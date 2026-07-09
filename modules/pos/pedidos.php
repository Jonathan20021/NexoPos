<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tienda/_shell.php'; // wa_link() y wa_numero()
require_perm('pedidos.ver');

$emp = $GLOBALS['empresa'] ?: [];

/** Orden de avance de un pedido. `cancelado` queda fuera: no es un avance. */
function pedidoRango(string $estado): int
{
    return ['pendiente' => 1, 'confirmado' => 2, 'listo' => 3, 'entregado' => 4][$estado] ?? 0;
}

/**
 * Un pedido con link de pago no puede marcarse «listo» ni «entregado» mientras
 * no se confirme el cobro. Sin esto, se entrega mercancía sin haber cobrado.
 * Cancelar siempre se permite.
 */
function pedidoPuedeAvanzar(array $p, string $nuevo): bool
{
    if ($nuevo === 'cancelado') return true;
    if ($p['metodo_pago'] !== 'link_pago') return true;
    if ($p['pago_confirmado_at']) return true;
    return pedidoRango($nuevo) < 3;
}

if (isPost()) {
    verify_csrf();

    if (post('accion') === 'link') {
        require_perm('pedidos.gestionar');
        $id   = postInt('id');
        $link = trim(post('link_pago'));
        try {
            $p = qOne("SELECT * FROM pedidos WHERE id = ?", [$id]);
            if (!$p) throw new RuntimeException('Pedido no encontrado.');
            require_sucursal_access($p['sucursal_id']);
            if ($link !== '') {
                if (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link)) {
                    throw new RuntimeException('El link de pago debe ser una URL válida que empiece por https://');
                }
                if (mb_strlen($link) > 500) throw new RuntimeException('El link de pago es demasiado largo.');
            }
            // Guardar un link NO es enviarlo. La marca de envío se pone al abrir
            // WhatsApp, y se limpia aquí porque un link nuevo aún no se ha enviado.
            dbUpdate('pedidos', [
                'link_pago' => $link ?: null,
                'link_pago_enviado_at' => null,
            ], 'id = ?', [$id]);
            audit('pedidos', 'link', "Link de pago actualizado en {$p['numero']}", ['tabla' => 'pedidos', 'registro_id' => $id]);

            $aviso = '';
            if ($link) {
                try {
                    correoPedidoLinkPago($id, $link);
                    $aviso = filter_var((string) $p['cliente_email'], FILTER_VALIDATE_EMAIL)
                        ? ' Le enviamos el enlace por correo.' : '';
                } catch (Throwable $e) { /* el correo nunca bloquea la operación */ }
            }
            flash('success', $link
                ? "Link de pago guardado en {$p['numero']}.$aviso Falta enviárselo por WhatsApp."
                : "Link de pago eliminado de {$p['numero']}.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/pedidos.php');
    }

    /**
     * Convierte un pedido en venta real: emite NCF, descuenta stock, registra el
     * cobro y lo asienta en Finanzas. Es la misma operación que hace el POS.
     *
     * Se factura al PRECIO QUE SE LE COTIZÓ AL CLIENTE (el guardado en el pedido),
     * no al precio actual del catálogo. Si el precio subió, no se le cobra de más
     * por haber ordenado antes.
     */
    if (post('accion') === 'facturar') {
        require_perm('pedidos.gestionar');
        require_perm('pos.vender');
        $id          = postInt('id');
        $metodoId    = postInt('metodo_pago_id') ?: 1;
        $comprobante = post('comprobante') === 'credito_fiscal' ? 'credito_fiscal' : 'consumidor';
        $uid         = (int) current_user()['id'];

        try {
            $ventaId = tx(function () use ($id, $metodoId, $comprobante, $uid) {
                $ped = qOne("SELECT * FROM pedidos WHERE id = ? FOR UPDATE", [$id]);
                if (!$ped) throw new RuntimeException('Pedido no encontrado.');
                if (!can_access_sucursal($ped['sucursal_id'])) throw new RuntimeException('No tienes acceso a la sucursal de este pedido.');
                if ($ped['venta_id']) throw new RuntimeException("El pedido {$ped['numero']} ya fue facturado.");
                if (in_array($ped['estado'], ['cancelado'], true)) throw new RuntimeException('Un pedido cancelado no se puede facturar.');
                if ($ped['metodo_pago'] === 'link_pago' && !$ped['pago_confirmado_at']) {
                    throw new RuntimeException('El cliente eligió pagar con link y el pago no está confirmado. Confírmalo antes de facturar.');
                }

                $sid = (int) $ped['sucursal_id'];

                // La venta entra a la caja: exige una sesión abierta, igual que el POS.
                $sesion = cajaSesionAbierta($sid, $uid);
                if (!$sesion) throw new RuntimeException('Abre la caja de esa sucursal antes de facturar el pedido.');

                $metodo = qOne("SELECT id, nombre, afecta_caja, es_credito FROM metodos_pago WHERE id = ? AND activo = 1", [$metodoId]);
                if (!$metodo) throw new RuntimeException('Método de pago no válido o inactivo.');

                // ---- Cliente: se reutiliza o se crea a partir de los datos del pedido ----
                $clienteId = 1; // Cliente Genérico
                $doc = trim((string) $ped['cliente_documento']);
                if ($doc !== '') {
                    $existente = qVal("SELECT id FROM clientes WHERE rnc_cedula = ? AND activo = 1", [$doc]);
                    $clienteId = $existente
                        ? (int) $existente
                        : dbInsert('clientes', [
                            'codigo'     => nextNumero('clientes', 'codigo', 'CLI', 5),
                            'nombre'     => $ped['cliente_nombre'],
                            'rnc_cedula' => $doc,
                            'tipo_id'    => dgiiTipoIdPorDocumento($doc) ?? 1,
                            'telefono'   => $ped['cliente_telefono'],
                            'email'      => $ped['cliente_email'],
                            'tipo'       => 'contado',
                            'activo'     => 1,
                        ]);
                }
                if ($comprobante === 'credito_fiscal' && $doc === '') {
                    throw new RuntimeException('Un comprobante de crédito fiscal exige el RNC o cédula del cliente.');
                }

                // ---- Líneas: precio cotizado, costo actual, stock revalidado ----
                $detalles = qAll("SELECT * FROM pedido_detalles WHERE pedido_id = ?", [$id]);
                if (!$detalles) throw new RuntimeException('El pedido no tiene líneas.');

                $subtotal = 0.0; $itbisTotal = 0.0; $costoTotal = 0.0; $lineas = [];
                foreach ($detalles as $d) {
                    if (!$d['producto_id']) throw new RuntimeException('El producto «' . $d['descripcion'] . '» ya no existe en el catálogo.');
                    $p = qOne("SELECT id, nombre, precio_compra, tipo FROM productos WHERE id = ? AND activo = 1", [$d['producto_id']]);
                    if (!$p) throw new RuntimeException('El producto «' . $d['descripcion'] . '» ya no está activo.');

                    $cant = (float) $d['cantidad'];
                    if ($p['tipo'] === 'producto') {
                        $stock = stockActual((int) $p['id'], $sid);
                        if ($cant > $stock) {
                            throw new RuntimeException('Ya no hay inventario de «' . $p['nombre'] . '»: quedan ' . qty($stock) . ' y el pedido lleva ' . qty($cant) . '.');
                        }
                    }
                    $subtotal   += (float) $d['subtotal'];
                    $itbisTotal += (float) $d['itbis'];
                    $costoTotal += (float) $p['precio_compra'] * $cant;
                    $lineas[] = [
                        'pid' => (int) $p['id'], 'nombre' => $p['nombre'], 'tipo' => $p['tipo'], 'cant' => $cant,
                        'precio' => (float) $d['precio_unitario'], 'costo' => (float) $p['precio_compra'],
                        'base' => (float) $d['subtotal'], 'itbis' => (float) $d['itbis'],
                    ];
                }
                $total = round($subtotal + $itbisTotal, 2);

                $ncf = siguienteNCF($comprobante === 'credito_fiscal' ? 'B01' : 'B02');
                if ($ncf === null) throw new RuntimeException('No hay una secuencia NCF activa y vigente para este comprobante.');

                $numero = nextNumero('ventas', 'numero', 'VTA');
                $ventaId = dbInsert('ventas', [
                    'numero' => $numero, 'sucursal_id' => $sid, 'caja_sesion_id' => (int) $sesion['id'],
                    'cliente_id' => $clienteId, 'usuario_id' => $uid, 'fecha' => date('Y-m-d H:i:s'),
                    'subtotal' => $subtotal, 'descuento' => 0, 'itbis' => $itbisTotal, 'total' => $total,
                    'costo_total' => $costoTotal, 'tipo_comprobante' => $comprobante, 'ncf' => $ncf,
                    'tipo_ingreso' => 1, 'estado' => 'completada',
                    'notas' => 'Pedido en línea ' . $ped['numero'],
                ]);

                foreach ($lineas as $l) {
                    dbInsert('venta_detalles', [
                        'venta_id' => $ventaId, 'producto_id' => $l['pid'], 'descripcion' => $l['nombre'],
                        'cantidad' => $l['cant'], 'precio_unitario' => $l['precio'], 'costo_unitario' => $l['costo'],
                        'descuento' => 0, 'itbis' => $l['itbis'], 'subtotal' => $l['base'],
                    ]);
                    if ($l['tipo'] === 'producto') {
                        ajustarStock($l['pid'], $sid, -$l['cant'], 'venta', 'venta', $ventaId, $l['costo'], 'Venta ' . $numero);
                    }
                }

                dbInsert('venta_pagos', ['venta_id' => $ventaId, 'metodo_pago_id' => $metodoId, 'monto' => $total]);

                if ((int) $metodo['es_credito'] === 1) {
                    if ($clienteId <= 1) throw new RuntimeException('Una venta a crédito exige un cliente registrado: el pedido no trae cédula ni RNC.');
                    $cli = qOne("SELECT nombre, balance, limite_credito FROM clientes WHERE id = ? FOR UPDATE", [$clienteId]);
                    if ((float) $cli['limite_credito'] > 0 && ((float) $cli['balance'] + $total) > (float) $cli['limite_credito']) {
                        throw new RuntimeException('La venta supera el límite de crédito de ' . $cli['nombre'] . '.');
                    }
                    q("UPDATE clientes SET balance = balance + ? WHERE id = ?", [$total, $clienteId]);
                } elseif ($total > 0) {
                    registrarTransaccion('ingreso', $total, [
                        'sucursal_id' => $sid,
                        'cuenta_id' => cuentaFinancieraIdPorTipo((int) $metodo['afecta_caja'] === 1 ? 'efectivo' : 'banco', $sid),
                        'categoria_id' => categoriaFinancieraId('ingreso', 'Ventas'),
                        'descripcion' => 'Venta ' . $numero . ' (pedido ' . $ped['numero'] . ')',
                        'referencia_tipo' => 'venta', 'referencia_id' => $ventaId,
                    ]);
                }

                dbUpdate('pedidos', ['venta_id' => $ventaId, 'estado' => 'entregado'], 'id = ?', [$id]);
                return $ventaId;
            });

            audit('pedidos', 'facturar', "Pedido facturado como venta #$ventaId", ['tabla' => 'pedidos', 'registro_id' => $id]);
            flash('success', 'Pedido facturado. Se emitió el NCF y se descontó el inventario.');
            redirect('modules/pos/ticket.php?id=' . $ventaId . '&print=1');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('modules/pos/pedidos.php');
        }
    }

    /**
     * Deja constancia de que se le abrió WhatsApp al cliente.
     *
     * Lo llama un fetch() desde el enlace de WhatsApp, no un <form>: la cabecera
     * CSP de la app declara `form-action 'self'`, que en Chrome bloquea cualquier
     * formulario con target="_blank". El enlace <a> no está sujeto a esa directiva.
     */
    if (post('accion') === 'whatsapp') {
        require_perm('pedidos.gestionar');
        $id = postInt('id');
        try {
            $p = qOne("SELECT p.*, s.nombre AS sucursal FROM pedidos p JOIN sucursales s ON s.id = p.sucursal_id WHERE p.id = ?", [$id]);
            if (!$p) throw new RuntimeException('Pedido no encontrado.');
            require_sucursal_access($p['sucursal_id']);

            // Solo se marca como enviado cuando el mensaje realmente lleva el link de pago.
            if ($p['metodo_pago'] === 'link_pago' && linkPagoPedido($p, $emp)) {
                dbUpdate('pedidos', ['link_pago_enviado_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                audit('pedidos', 'whatsapp', "Link de pago enviado por WhatsApp: {$p['numero']}", ['tabla' => 'pedidos', 'registro_id' => $id]);
            } else {
                audit('pedidos', 'whatsapp', "Mensaje de WhatsApp abierto: {$p['numero']}", ['tabla' => 'pedidos', 'registro_id' => $id]);
            }
            http_response_code(204);
            exit;
        } catch (Throwable $e) {
            http_response_code(422);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
            exit;
        }
    }

    /**
     * Confirma que el cliente ya pagó con el link. Es la condición para poder
     * avanzar el pedido o facturarlo: nadie entrega mercancía sin cobrar.
     */
    if (post('accion') === 'confirmar_pago') {
        require_perm('pedidos.gestionar');
        $id = postInt('id');
        try {
            $p = qOne("SELECT * FROM pedidos WHERE id = ?", [$id]);
            if (!$p) throw new RuntimeException('Pedido no encontrado.');
            require_sucursal_access($p['sucursal_id']);
            if ($p['metodo_pago'] !== 'link_pago') throw new RuntimeException('Este pedido se paga al retirar: el cobro se registra al facturar.');
            if ($p['pago_confirmado_at']) throw new RuntimeException("El pago de {$p['numero']} ya estaba confirmado.");
            if (!$p['link_pago_enviado_at']) throw new RuntimeException('Envíale primero el link de pago por WhatsApp.');

            dbUpdate('pedidos', [
                'pago_confirmado_at'  => date('Y-m-d H:i:s'),
                'pago_confirmado_por' => current_user()['id'],
            ], 'id = ?', [$id]);
            audit('pedidos', 'confirmar_pago', "Pago confirmado del pedido {$p['numero']} (" . money($p['total']) . ')', ['tabla' => 'pedidos', 'registro_id' => $id]);
            flash('success', "Pago de {$p['numero']} confirmado. Ya puedes marcarlo listo y facturarlo.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/pedidos.php');
    }

    if (post('accion') === 'estado') {
        require_perm('pedidos.gestionar');
        $id = postInt('id');
        $nuevo = post('estado');
        $validos = ['pendiente', 'confirmado', 'listo', 'entregado', 'cancelado'];
        try {
            if (!in_array($nuevo, $validos, true)) throw new RuntimeException('Estado no válido.');
            $p = qOne("SELECT * FROM pedidos WHERE id = ?", [$id]);
            if (!$p) throw new RuntimeException('Pedido no encontrado.');
            require_sucursal_access($p['sucursal_id']);
            if ($p['estado'] === 'entregado') throw new RuntimeException('Un pedido entregado ya no cambia de estado.');
            if (!pedidoPuedeAvanzar($p, $nuevo)) {
                throw new RuntimeException("El cliente eligió pagar con link. Confirma que ya pagó antes de marcar el pedido como «{$nuevo}».");
            }
            dbUpdate('pedidos', ['estado' => $nuevo], 'id = ?', [$id]);
            audit('pedidos', 'estado', "Pedido {$p['numero']}: {$p['estado']} → $nuevo", ['tabla' => 'pedidos', 'registro_id' => $id]);
            try {
                correoPedidoEstado($id, $nuevo);   // solo notifica listo, entregado y cancelado
            } catch (Throwable $e) { /* el correo nunca bloquea el cambio de estado */ }
            flash('success', "Pedido {$p['numero']} marcado como $nuevo.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/pedidos.php');
    }
}

[$scope, $sp] = sucursalFiltro('p.sucursal_id');
$estado = in_array(get('estado'), ['pendiente', 'confirmado', 'listo', 'entregado', 'cancelado'], true) ? get('estado') : '';
$q = trim(get('q'));

$cond = [$scope];
$params = $sp;
if ($estado !== '') { $cond[] = "p.estado = ?"; $params[] = $estado; }
if ($q !== '')      { $cond[] = "(p.numero LIKE ? OR p.cliente_nombre LIKE ? OR p.cliente_telefono LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
$where = implode(' AND ', $cond);

$pg = paginar((int) qVal("SELECT COUNT(*) FROM pedidos p WHERE $where", $params), 25);
$pedidos = qAll(
    "SELECT p.*, s.nombre AS sucursal, v.numero AS venta_numero,
            (SELECT COUNT(*) FROM pedido_detalles WHERE pedido_id = p.id) AS items
       FROM pedidos p
       JOIN sucursales s ON s.id = p.sucursal_id
       LEFT JOIN ventas v ON v.id = p.venta_id
      WHERE $where ORDER BY p.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $params
);

$metodos = qAll("SELECT id, nombre, es_credito FROM metodos_pago WHERE activo = 1 ORDER BY id");

$pendientes = (int) qVal("SELECT COUNT(*) FROM pedidos p WHERE $scope AND p.estado = 'pendiente'", $sp);

$estadoBadge = [
    'pendiente'  => ['Pendiente', 'bg-amber-50 text-amber-700 border-amber-200'],
    'confirmado' => ['Confirmado', 'bg-sky-50 text-sky-700 border-sky-200'],
    'listo'      => ['Listo', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'entregado'  => ['Entregado', 'bg-slate-50 text-slate-600 border-slate-200'],
    'cancelado'  => ['Cancelado', 'bg-rose-50 text-rose-700 border-rose-200'],
];

/**
 * Link de pago efectivo del pedido: el suyo propio, y si no tiene, el genérico
 * de la empresa. El del pedido siempre manda, porque lleva el monto de esa venta.
 */
function linkPagoPedido(array $p, array $emp): ?string
{
    return $p['link_pago'] ?: ($emp['link_pago'] ?? null) ?: null;
}

/** Mensaje de WhatsApp que la tienda envía al cliente. */
function mensajePedido(array $p, array $emp): string
{
    $saludo = "Hola {$p['cliente_nombre']}, te escribimos de " . ($emp['nombre'] ?? APP_NAME) . ".";
    $base = " Tu pedido {$p['numero']} por " . money($p['total']) . " está ";

    // Cada rama cierra su propia puntuación: así no se duplica el punto final.
    $estado = match ($p['estado']) {
        'confirmado' => 'confirmado.',
        'listo'      => 'listo para retirar en ' . $p['sucursal'] . '.',
        'entregado'  => 'entregado. ¡Gracias por tu compra!',
        'cancelado'  => 'cancelado.',
        default      => 'en proceso.',
    };

    $msg = $saludo . $base . $estado;
    $cerrado = in_array($p['estado'], ['entregado', 'cancelado'], true);
    $link = linkPagoPedido($p, $emp);

    if ($cerrado) {
        return $msg;
    }
    if ($p['metodo_pago'] === 'link_pago') {
        $msg .= $link
            ? " Puedes pagar " . money($p['total']) . " aquí: $link"
            : " En breve te enviamos el link de pago.";
    } else {
        $msg .= ' Pagas ' . money($p['total']) . ' al retirar.';
    }
    return $msg;
}

$acciones = '';
layout_start('Pedidos en línea', 'Órdenes recibidas desde la tienda pública', $acciones);
?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-sm text-slate-500">Pendientes de confirmar</p>
    <p class="text-2xl font-extrabold <?= $pendientes ? 'text-amber-600' : 'text-slate-800' ?> mt-1"><?= number_format($pendientes) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-500">Pedidos que coinciden</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format($pg['total']) ?></p>
  </div>
  <div class="card p-5 col-span-2">
    <p class="text-sm text-slate-500">Enlace público de la tienda</p>
    <a href="<?= e(url('tienda/index.php')) ?>" target="_blank" rel="noopener"
       class="mt-1 inline-flex items-center gap-1.5 font-semibold text-blue-600 hover:text-blue-700 transition-colors duration-200 cursor-pointer break-all">
      <?= icon('store', 'w-4 h-4') ?> <?= e(url('tienda/index.php')) ?>
    </a>
  </div>
</div>

<div class="card overflow-hidden">
  <?php $selSuc = selectSucursalFiltro(); ?>
  <form method="get" class="p-4 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-<?= $selSuc ? '4' : '3' ?> gap-3">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Número, cliente o teléfono..." aria-label="Buscar pedido" class="input">
    <?= $selSuc ?>
    <select name="estado" aria-label="Estado del pedido" class="select cursor-pointer">
      <option value="">Todos los estados</option>
      <?php foreach ($estadoBadge as $k => [$label, $_]): ?>
        <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary cursor-pointer" aria-label="Aplicar filtros"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
  </form>

  <?php if (!$pedidos): ?>
    <?= empty_state('Sin pedidos', 'Cuando un cliente ordene desde la tienda, aparecerá aquí.', 'cart') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Pedido</th><th>Cliente</th><th>Sucursal</th><th class="text-center">Items</th>
            <th class="text-right">Total</th><th>Pago</th><th>Estado</th><th class="text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pedidos as $p): ?>
            <?php
              [$label, $clases] = $estadoBadge[$p['estado']];
              $wa = wa_link($p['cliente_telefono'], mensajePedido($p, $emp));
            ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($p['numero']) ?></p>
                <p class="text-xs text-slate-400"><?= e(substr((string) $p['created_at'], 0, 16)) ?></p>
              </td>
              <td>
                <p class="font-semibold text-slate-700"><?= e($p['cliente_nombre']) ?></p>
                <p class="text-xs text-slate-400"><?= e($p['cliente_telefono']) ?></p>
              </td>
              <td class="text-slate-600"><?= e($p['sucursal']) ?></td>
              <td class="text-center tabular-nums"><?= (int) $p['items'] ?></td>
              <td class="text-right font-bold text-slate-800 tabular-nums"><?= money($p['total']) ?></td>
              <td>
                <?php $linkEfectivo = linkPagoPedido($p, $emp); ?>
                <?php if ($p['metodo_pago'] === 'link_pago'): ?>
                  <span class="text-xs font-semibold text-blue-600 block">Link de pago</span>
                  <?php if ($p['pago_confirmado_at']): ?>
                    <span class="text-xs font-semibold text-emerald-600">Pagado <?= e(substr((string) $p['pago_confirmado_at'], 0, 10)) ?></span>
                  <?php elseif ($p['link_pago_enviado_at']): ?>
                    <span class="text-xs text-amber-600">Enviado, sin cobrar</span>
                  <?php elseif ($p['link_pago']): ?>
                    <span class="text-xs text-amber-600">Cargado, sin enviar</span>
                  <?php elseif ($linkEfectivo): ?>
                    <span class="text-xs text-slate-400">Usa el genérico</span>
                  <?php else: ?>
                    <span class="text-xs font-semibold text-amber-600">Falta el link</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-xs font-semibold text-slate-500">Al retirar</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold border <?= $clases ?>"><?= $label ?></span>
                <?php if ($p['venta_id']): ?>
                  <a href="<?= e(url('modules/pos/ventas.php?ver=' . (int) $p['venta_id'])) ?>"
                     class="block mt-1 text-xs font-semibold text-blue-600 hover:text-blue-700 transition-colors duration-200 cursor-pointer">
                    <?= e($p['venta_numero']) ?>
                  </a>
                <?php endif; ?>
              </td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('pedidos.gestionar') && can('pos.vender') && !$p['venta_id'] && $p['estado'] !== 'cancelado'): ?>
                    <?php $sinCobrar = $p['metodo_pago'] === 'link_pago' && !$p['pago_confirmado_at']; ?>
                    <button type="button" <?= $sinCobrar ? 'disabled' : '' ?>
                            <?= $sinCobrar ? '' : 'onclick="' . jsEvent('pedido:facturar', ['id' => (int) $p['id'], 'numero' => $p['numero'], 'total' => money($p['total']), 'cliente' => $p['cliente_nombre'], 'sucursal' => $p['sucursal'], 'documento' => (string) $p['cliente_documento'], 'metodo' => $p['metodo_pago']]) . '"' ?>
                            class="p-2 rounded-lg transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500
                                   <?= $sinCobrar ? 'text-slate-300 cursor-not-allowed' : 'text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 cursor-pointer' ?>"
                            title="<?= $sinCobrar ? 'Confirma el pago antes de facturar' : 'Convertir el pedido en venta' ?>"
                            aria-label="Facturar el pedido <?= e($p['numero']) ?>"><?= icon('receipt', 'w-4 h-4') ?></button>
                  <?php endif; ?>

                  <?php if (can('pedidos.gestionar') && $p['metodo_pago'] === 'link_pago' && $p['estado'] !== 'entregado'): ?>
                    <button type="button"
                            onclick="<?= jsEvent('pedido:link', ['id' => (int) $p['id'], 'numero' => $p['numero'], 'total' => money($p['total']), 'cliente' => $p['cliente_nombre'], 'link' => (string) $p['link_pago']]) ?>"
                            class="p-2 rounded-lg transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500
                                   <?= $p['link_pago'] ? 'text-slate-400 hover:text-blue-600 hover:bg-blue-50' : 'text-amber-600 hover:bg-amber-50' ?>"
                            title="<?= $p['link_pago'] ? 'Cambiar el link de pago' : 'Agregar el link de pago de este pedido' ?>"
                            aria-label="Link de pago del pedido <?= e($p['numero']) ?>"><?= icon('wallet', 'w-4 h-4') ?></button>
                  <?php endif; ?>

                  <?php if ($wa): ?>
                    <?php $esperaLink = $p['metodo_pago'] === 'link_pago' && !$p['link_pago_enviado_at'] && $linkEfectivo; ?>
                    <a href="<?= e($wa) ?>" target="_blank" rel="noopener"
                       <?php if (can('pedidos.gestionar')): ?>data-wa-pedido="<?= (int) $p['id'] ?>"<?php endif; ?>
                       class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-sm font-semibold transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500
                              <?= $esperaLink ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'text-emerald-700 hover:bg-emerald-50' ?>"
                       title="Abrir WhatsApp con el mensaje ya escrito para <?= e($p['cliente_nombre']) ?>"
                       aria-label="Enviar WhatsApp a <?= e($p['cliente_nombre']) ?>">
                      <?= icon('phone', 'w-4 h-4') ?>
                      <span class="hidden xl:inline"><?= $esperaLink ? 'Enviar link' : 'WhatsApp' ?></span>
                    </a>
                  <?php endif; ?>

                  <?php if (can('pedidos.gestionar') && $p['metodo_pago'] === 'link_pago' && !$p['pago_confirmado_at'] && $p['estado'] !== 'cancelado'): ?>
                    <form method="post" class="inline"
                          onsubmit="return confirm('¿Confirmas que <?= e($p['cliente_nombre']) ?> ya pagó <?= e(money($p['total'])) ?>?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="confirmar_pago">
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <button class="inline-flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-sm font-semibold text-blue-700 hover:bg-blue-50 transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:opacity-40 disabled:cursor-not-allowed"
                              <?= $p['link_pago_enviado_at'] ? '' : 'disabled' ?>
                              title="<?= $p['link_pago_enviado_at'] ? 'Marcar el pago como recibido' : 'Envíale primero el link de pago por WhatsApp' ?>"
                              aria-label="Confirmar el pago del pedido <?= e($p['numero']) ?>">
                        <?= icon('check', 'w-4 h-4') ?>
                        <span class="hidden xl:inline">Confirmar pago</span>
                      </button>
                    </form>
                  <?php endif; ?>

                  <a href="<?= e(url('tienda/pedido.php?token=' . $p['token'])) ?>" target="_blank" rel="noopener"
                     class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                     title="Ver el pedido como lo ve el cliente"
                     aria-label="Ver pedido <?= e($p['numero']) ?>"><?= icon('eye', 'w-4 h-4') ?></a>

                  <?php if (can('pedidos.gestionar') && $p['estado'] !== 'entregado'): ?>
                    <form method="post" class="inline-flex items-center gap-1">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="estado">
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <label class="sr-only" for="estado_<?= (int) $p['id'] ?>">Cambiar estado del pedido <?= e($p['numero']) ?></label>
                      <select id="estado_<?= (int) $p['id'] ?>" name="estado" onchange="this.form.submit()"
                              class="select py-1.5 text-xs cursor-pointer">
                        <?php foreach ($estadoBadge as $k => [$lbl, $_]): ?>
                          <?php $bloqueado = !pedidoPuedeAvanzar($p, $k); ?>
                          <option value="<?= $k ?>" <?= $p['estado'] === $k ? 'selected' : '' ?> <?= $bloqueado ? 'disabled' : '' ?>>
                            <?= $lbl ?><?= $bloqueado ? ' — falta confirmar el pago' : '' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <noscript><button class="btn btn-ghost btn-sm">Guardar</button></noscript>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<?php
// Pedidos que esperan link de pago. Se cuenta sobre TODO el filtro, no sobre la
// página visible: si no, la advertencia desaparecería al pasar de página.
$sinLink = empty($emp['link_pago'])
    ? (int) qVal(
        "SELECT COUNT(*) FROM pedidos p
          WHERE $where AND p.metodo_pago = 'link_pago'
            AND p.estado NOT IN ('entregado','cancelado')
            AND (p.link_pago IS NULL OR p.link_pago = '')",
        $params)
    : 0;
?>
<?php if ($sinLink): ?>
  <div class="card p-5 mt-5 border-l-4 border-l-amber-400">
    <div class="flex items-start gap-3">
      <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0 mt-0.5') ?>
      <div class="text-sm text-slate-600">
        <h3 class="font-bold text-slate-800"><?= $sinLink ?> pedido<?= $sinLink === 1 ? '' : 's' ?> sin link de pago</h3>
        <p class="mt-1">
          Genera el enlace de cobro por el monto exacto en tu pasarela y pégalo en cada pedido con el botón de la billetera.
          Hasta entonces, el mensaje de WhatsApp solo le avisa al cliente que se lo enviarás en breve.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Modal: convertir el pedido en venta -->
<div x-data="{ open: false, ped: { id: 0, numero: '', total: '', cliente: '', sucursal: '', documento: '', metodo: 'pickup' },
               get tieneDoc() { return this.ped.documento !== ''; } }"
     @pedido:facturar.window="ped = $event.detail; open = true"
     @keydown.escape.window="open = false"
     x-show="open" x-transition.opacity style="display:none"
     class="modal-overlay" @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="tituloFacturar">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="facturar">
      <input type="hidden" name="id" :value="ped.id">

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="tituloFacturar" class="font-bold text-slate-800">Facturar <span x-text="ped.numero"></span></h3>
        <button type="button" @click="open = false" aria-label="Cerrar modal" title="Cerrar"
                class="text-slate-400 hover:text-slate-700 p-1 -m-1 cursor-pointer transition-colors duration-200"><?= icon('x', 'w-5 h-5') ?></button>
      </div>

      <div class="p-6 space-y-4">
        <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-sm">
          <p class="text-slate-500">Total a cobrar</p>
          <p class="text-xl font-extrabold text-slate-800" x-text="ped.total"></p>
          <p class="text-slate-500 mt-1">
            <span class="font-semibold text-slate-700" x-text="ped.cliente"></span> ·
            <span x-text="ped.sucursal"></span>
          </p>
        </div>

        <div>
          <label class="label" for="metodo_pago_id">Método de pago *</label>
          <select id="metodo_pago_id" name="metodo_pago_id" required class="select cursor-pointer">
            <?php foreach ($metodos as $m): ?>
              <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === 1 ? 'selected' : '' ?>><?= e($m['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="mt-1 text-xs text-slate-500">
            El cliente eligió <span class="font-semibold" x-text="ped.metodo === 'link_pago' ? 'pagar con link' : 'pagar al retirar'"></span>.
            Registra aquí cómo pagó realmente.
          </p>
        </div>

        <div>
          <label class="label" for="comprobante">Tipo de comprobante *</label>
          <select id="comprobante" name="comprobante" class="select cursor-pointer">
            <option value="consumidor">Consumidor Final (B02)</option>
            <option value="credito_fiscal" x-bind:disabled="!tieneDoc">Crédito Fiscal (B01)</option>
          </select>
          <p class="mt-1 text-xs" :class="tieneDoc ? 'text-slate-500' : 'text-amber-600'"
             x-text="tieneDoc ? 'El cliente dejó su RNC o cédula: puedes emitir crédito fiscal.' : 'El pedido no trae RNC ni cédula, así que solo puede emitirse Consumidor Final.'"></p>
        </div>

        <div class="flex gap-3 rounded-xl border border-sky-200 bg-sky-50 p-3">
          <?= icon('alert', 'w-5 h-5 text-sky-600 shrink-0') ?>
          <p class="text-sm text-sky-900">
            Se emitirá el NCF, se descontará el inventario de la sucursal y el cobro entrará a la caja abierta.
            Se factura al precio que se le cotizó al cliente, no al precio actual del catálogo.
          </p>
        </div>
      </div>

      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open = false" class="btn btn-ghost cursor-pointer">Cancelar</button>
        <button class="btn btn-primary cursor-pointer"><?= icon('receipt', 'w-4 h-4') ?> Facturar y emitir ticket</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: link de pago del pedido -->
<div x-data="{ open: false, pedido: { id: 0, numero: '', total: '', cliente: '', link: '' } }"
     @pedido:link.window="pedido = $event.detail; open = true; $nextTick(() => $refs.campoLink.focus())"
     @keydown.escape.window="open = false"
     x-show="open" x-transition.opacity style="display:none"
     class="modal-overlay" @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="tituloLink">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="link">
      <input type="hidden" name="id" :value="pedido.id">

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="tituloLink" class="font-bold text-slate-800">Link de pago · <span x-text="pedido.numero"></span></h3>
        <button type="button" @click="open = false" aria-label="Cerrar modal" title="Cerrar"
                class="text-slate-400 hover:text-slate-700 p-1 -m-1 cursor-pointer transition-colors duration-200"><?= icon('x', 'w-5 h-5') ?></button>
      </div>

      <div class="p-6 space-y-4">
        <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-sm">
          <p class="text-slate-500">Monto a cobrar</p>
          <p class="text-xl font-extrabold text-slate-800" x-text="pedido.total"></p>
          <p class="text-slate-500 mt-1">Cliente: <span class="font-semibold text-slate-700" x-text="pedido.cliente"></span></p>
        </div>

        <div>
          <label class="label" for="link_pago">Enlace de cobro *</label>
          <input type="url" id="link_pago" name="link_pago" x-ref="campoLink" x-model="pedido.link"
                 placeholder="https://pagos.tubanco.com/abc123" class="input" autocomplete="off">
          <p class="mt-1 text-xs text-slate-500">
            Genera el enlace por el monto exacto en tu pasarela y pégalo aquí. Cada pedido lleva el suyo.
          </p>
        </div>

        <p class="text-xs text-slate-500">
          Deja el campo vacío para quitar el enlace. Después de guardar, usa el botón de WhatsApp para enviárselo al cliente.
        </p>
      </div>

      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open = false" class="btn btn-ghost cursor-pointer">Cancelar</button>
        <button class="btn btn-primary cursor-pointer"><?= icon('save', 'w-4 h-4') ?> Guardar link</button>
      </div>
    </form>
  </div>
</div>

<script>
/**
 * Al abrir WhatsApp, deja constancia del envío.
 *
 * Se usa fetch() y no un <form>: la cabecera CSP declara `form-action 'self'`,
 * que en Chrome bloquea cualquier formulario con target="_blank". Un <a> no cae
 * bajo esa directiva, y `connect-src 'self'` sí permite este fetch.
 *
 * Si el fetch falla, WhatsApp se abre igual: no bloqueamos el envío por no poder
 * anotarlo.
 */
document.querySelectorAll('a[data-wa-pedido]').forEach(function (a) {
  a.addEventListener('click', function () {
    var datos = new URLSearchParams({
      _csrf: <?= json_encode(csrf_token()) ?>,
      accion: 'whatsapp',
      id: a.dataset.waPedido,
    });
    fetch(window.location.pathname, {
      method: 'POST',
      body: datos,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    })
      .then(function (r) { if (r.ok) window.location.reload(); })
      .catch(function () { /* el enlace ya se abrió; no interrumpimos al usuario */ });
  });
});
</script>

<?php layout_end(); ?>
