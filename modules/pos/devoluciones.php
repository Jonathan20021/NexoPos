<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('devoluciones.ver');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'buscar') {
        $numero = trim(post('numero'));
        [$scopeVenta, $scopeParams] = sucursalScope('sucursal_id');
        $v = qOne("SELECT id FROM ventas WHERE numero = ? AND estado='completada' AND $scopeVenta", array_merge([$numero], $scopeParams));
        if ($v) redirect('modules/pos/devoluciones.php?venta_id=' . (int) $v['id']);
        flash('error', 'No se encontró una venta completada con ese número.');
        redirect('modules/pos/devoluciones.php');
    }

    if ($accion === 'guardar') {
        require_perm('devoluciones.crear');
        $ventaId = postInt('venta_id');
        $motivo = trim(post('motivo'));
        $ret = $_POST['ret'] ?? [];
        try {
            $devId = txReintentable(function () use ($ventaId, $motivo, $ret) {
                $v = qOne("SELECT * FROM ventas WHERE id = ? FOR UPDATE", [$ventaId]);
                if (!$v || $v['estado'] === 'anulada') throw new RuntimeException('Venta no válida.');
                if (!can_access_sucursal($v['sucursal_id'])) throw new RuntimeException('No tienes acceso a la sucursal de esta venta.');
                if ($motivo === '') throw new RuntimeException('Indica el motivo de la devolución.');
                $totalDev = 0; $subtotalDev = 0; $itbisDev = 0; $lineas = []; $totVendido = 0;
                $sinCaja = false;   // reembolso en efectivo que no pudo anotarse en ningún arqueo
                $factorVenta = (float) $v['subtotal'] > 0
                    ? ((float) $v['subtotal'] - (float) $v['descuento']) / (float) $v['subtotal']
                    : 1.0;
                $detalles = qAll(
                    "SELECT vd.*, p.tipo AS producto_tipo,
                            COALESCE(NULLIF(vd.descripcion,''), p.nombre, '(producto no disponible)') AS descripcion
                     FROM venta_detalles vd LEFT JOIN productos p ON p.id=vd.producto_id
                     WHERE vd.venta_id = ?
                     ORDER BY vd.producto_id",
                    [$ventaId]
                );
                foreach ($detalles as $d) {
                    $totVendido += (float) $d['cantidad'];
                    $cant = (float) ($ret[$d['id']] ?? 0);
                    if ($cant <= 0) continue;
                    $yaDev = (float) qVal(
                        "SELECT COALESCE(SUM(dd.cantidad),0)
                         FROM devolucion_detalles dd JOIN devoluciones de ON de.id=dd.devolucion_id
                         WHERE de.venta_id=? AND (dd.venta_detalle_id=? OR (dd.venta_detalle_id IS NULL AND dd.producto_id <=> ? AND dd.descripcion=?))",
                        [$ventaId, $d['id'], $d['producto_id'], $d['descripcion']]
                    );
                    $maxDev = (float) $d['cantidad'] - $yaDev;
                    if ($cant > $maxDev) throw new RuntimeException('Cantidad a devolver excede lo vendido para «' . $d['descripcion'] . '».');
                    // Reembolsa el importe realmente cobrado: descuento proporcional + ITBIS.
                    // Se separan la base y el ITBIS para la nota de crédito (el 607 los pide aparte).
                    $prop = $cant / (float) $d['cantidad'];
                    $baseLinea  = round((float) $d['subtotal'] * $factorVenta * $prop, 2);
                    $itbisLinea = round((float) $d['itbis'] * $prop, 2);
                    $sub = round($baseLinea + $itbisLinea, 2);
                    $precioReembolso = round($sub / $cant, 2);
                    $totalDev += $sub; $subtotalDev += $baseLinea; $itbisDev += $itbisLinea;
                    $lineas[] = ['vdid' => $d['id'], 'pid' => $d['producto_id'], 'es_stock' => $d['producto_tipo'] === 'producto', 'desc' => $d['descripcion'], 'cant' => $cant, 'precio' => $precioReembolso, 'costo' => (float) $d['costo_unitario'], 'sub' => $sub];
                }
                if (!$lineas) throw new RuntimeException('Indica al menos una cantidad a devolver.');
                $numero = nextNumero('devoluciones', 'numero', 'DEV');
                // Nota de crédito (B04): solo si la venta llevaba NCF fiscal. Referencia
                // el NCF original y baja el ITBIS facturado en el 607 / IT-1.
                $b04 = null; $b04Faltante = false; $tipoNC = ncfTipoNotaCredito();
                if (!empty($v['ncf'])) {
                    $b04 = siguienteNCF($tipoNC);
                    if (!$b04) $b04Faltante = true; // sin secuencia activa: no bloquea, pero avisa
                }
                $devId = dbInsert('devoluciones', ['numero' => $numero, 'venta_id' => $ventaId, 'sucursal_id' => $v['sucursal_id'], 'usuario_id' => current_user()['id'], 'motivo' => $motivo, 'ncf' => $b04, 'ncf_modificado' => $b04 ? $v['ncf'] : null, 'subtotal' => $subtotalDev, 'itbis' => $itbisDev, 'total' => $totalDev]);
                if ($b04Faltante) flash('warning', 'La devolución se registró, pero no hay una secuencia ' . $tipoNC
                    . ' activa para emitir la nota de crédito. Configúrala en '
                    . ($tipoNC === 'E34' ? 'Finanzas → Facturación Electrónica → Secuencias.' : 'Configuración → Comprobantes.'));
                foreach ($lineas as $l) {
                    dbInsert('devolucion_detalles', ['devolucion_id' => $devId, 'venta_detalle_id' => $l['vdid'], 'producto_id' => $l['pid'], 'descripcion' => $l['desc'], 'cantidad' => $l['cant'], 'precio_unitario' => $l['precio'], 'subtotal' => $l['sub']]);
                    // La mercancia devuelta vuelve A SU LOTE, el mismo del que
                    // salio en la venta original. Si entrara sin identificar, un
                    // producto trazable dejaria de serlo por el simple hecho de
                    // que el cliente lo devolvio, y con fecha de caducidad eso
                    // importa: hay que saber cuando vence lo que vuelve al estante.
                    if ($l['pid'] && $l['es_stock']) {
                        san_mover_conservando_lotes(
                            (int) $l['pid'], (int) $v['sucursal_id'], (float) $l['cant'],
                            'devolucion', 'devolucion', $devId, (int) $v['sucursal_id'],
                            (float) $l['costo'], 'Devolución ' . $numero,
                            ['tipo' => 'venta', 'id' => $ventaId]
                        );
                    }
                }
                $metodo = qOne(
                    "SELECT m.afecta_caja, m.es_credito FROM venta_pagos vp JOIN metodos_pago m ON m.id=vp.metodo_pago_id WHERE vp.venta_id=? ORDER BY vp.id LIMIT 1",
                    [$ventaId]
                );
                if (!$metodo) throw new RuntimeException('La venta no tiene un método de pago válido.');
                if ((int) $metodo['es_credito'] === 1 && $totalDev > 0) {
                    $cli = qOne("SELECT id, balance FROM clientes WHERE id=? FOR UPDATE", [$v['cliente_id']]);
                    if (!$cli || round((float) $cli['balance'], 2) < round($totalDev, 2)) {
                        throw new RuntimeException('El crédito ya tiene abonos aplicados y no cubre esta devolución. Revisa la cuenta del cliente.');
                    }
                    q("UPDATE clientes SET balance = balance - ? WHERE id = ?", [$totalDev, $cli['id']]);
                } elseif ($totalDev > 0) {
                    $tipoCuenta = (int) $metodo['afecta_caja'] === 1 ? 'efectivo' : 'banco';
                    registrarTransaccion('gasto', $totalDev, [
                        'sucursal_id' => $v['sucursal_id'],
                        'cuenta_id' => cuentaFinancieraIdPorTipo($tipoCuenta, (int) $v['sucursal_id']),
                        'categoria_id' => categoriaFinancieraId('gasto', 'Devoluciones'),
                        'descripcion' => 'Devolución ' . $numero . ' (venta ' . $v['numero'] . ')',
                        'referencia_tipo' => 'devolucion', 'referencia_id' => $devId,
                    ]);
                    if ((int) $metodo['afecta_caja'] === 1) {
                        // El reembolso en efectivo sale de un cajón concreto. Sin caja
                        // abierta no hay dónde apuntar el egreso, y antes eso se
                        // resolvía callándose: el dinero salía igual y al cierre
                        // aparecía un faltante que nadie había causado.
                        //
                        // No se bloquea la devolución —hay quien reembolsa desde la
                        // oficina— pero tampoco se calla: se avisa nombrando la cifra,
                        // y queda listada en Integridad de datos hasta que se anote.
                        $sesionCaja = cajaSesionAbierta((int) $v['sucursal_id'], (int) current_user()['id']);
                        if ($sesionCaja) {
                            dbInsert('caja_movimientos', [
                                'caja_sesion_id' => (int) $sesionCaja['id'], 'tipo' => 'egreso',
                                'concepto' => 'Reembolso ' . $numero, 'monto' => $totalDev,
                                'usuario_id' => current_user()['id'], 'created_at' => date('Y-m-d H:i:s'),
                            ]);
                        } else {
                            $sinCaja = true;
                        }
                    }
                }
                // ¿Devolución total?
                $totDevuelto = (float) qVal("SELECT COALESCE(SUM(dd.cantidad),0) FROM devolucion_detalles dd JOIN devoluciones de ON de.id=dd.devolucion_id WHERE de.venta_id=?", [$ventaId]);
                if ($totDevuelto >= $totVendido) dbUpdate('ventas', ['estado' => 'devuelta'], 'id = ?', [$ventaId]);
                return ['id' => $devId, 'ncf' => $b04, 'sin_caja' => $sinCaja, 'monto' => $totalDev];
            });
            $devNcf    = $devId['ncf'] ?? null;
            $devSinCaja = !empty($devId['sin_caja']);
            $devMonto  = (float) ($devId['monto'] ?? 0);
            $devId     = $devId['id'];

            if ($devSinCaja) {
                flash('warning', 'Se reembolsaron ' . money($devMonto) . ' en efectivo y no tenías la caja '
                    . 'abierta, así que ese egreso no aparece en ningún arqueo. Anótalo como egreso cuando '
                    . 'abras tu caja. Mientras tanto sale listado en Integridad de datos, en «Reembolsos en '
                    . 'efectivo que no salieron del cajón».');
            }

            // Nota de Crédito Electrónica (tipo 34), FUERA de la transacción: la
            // mercancía ya volvió al estante y el dinero ya se reembolsó; que el
            // proveedor esté caído no puede deshacer nada de eso.
            $avisoEcf = '';
            if (ecfActivo() && ecfENCFValido((string) $devNcf)) {
                $emision = ecfEmitirSeguro('devolucion', (int) $devId);
                if (!$emision['ok']) $avisoEcf = ' ' . $emision['mensaje'];
            }

            audit('devoluciones', 'crear', 'Devolución registrada' . ($devNcf ? " (NC $devNcf)" : ''), ['tabla' => 'devoluciones', 'registro_id' => $devId]);
            flash($avisoEcf ? 'warning' : 'success',
                  'Devolución registrada y stock actualizado.'
                  . ($devNcf ? ' Nota de crédito ' . $devNcf . ' emitida.' : '') . $avisoEcf);

            // Se termina en el comprobante, igual que una venta termina en su
            // ticket: la nota de crédito es un documento fiscal que el cliente
            // se lleva, no un apunte interno.
            redirect('modules/pos/nota_credito.php?id=' . (int) $devId . '&print=1');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/devoluciones.php');
    }
}

// ----- Formulario de devolución para una venta -----
$ventaId = (int) get('venta_id');
if ($ventaId && can('devoluciones.crear')) {
    $v = qOne("SELECT v.*, cl.nombre AS cliente, su.nombre AS sucursal FROM ventas v LEFT JOIN clientes cl ON cl.id=v.cliente_id JOIN sucursales su ON su.id=v.sucursal_id WHERE v.id=?", [$ventaId]);
    if (!$v) { flash('error', 'Venta no encontrada.'); redirect('modules/pos/devoluciones.php'); }
    require_sucursal_access($v['sucursal_id']);
    $detalles = qAll(
        "SELECT vd.*, COALESCE(NULLIF(vd.descripcion,''), p.nombre, '(producto no disponible)') AS descripcion
         FROM venta_detalles vd LEFT JOIN productos p ON p.id = vd.producto_id
         WHERE vd.venta_id = ?",
        [$ventaId]
    );
    if (!$detalles) {
        flash('error', 'La venta ' . $v['numero'] . ' no tiene líneas de detalle registradas, por lo que no se puede devolver.');
        redirect('modules/pos/devoluciones.php');
    }
    layout_start('Nueva devolución', 'Venta ' . e($v['numero']) . ' · ' . e($v['cliente'] ?: 'Cliente Genérico'), '<a href="' . url('modules/pos/devoluciones.php') . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Cancelar</a>');
    // Se avisa ANTES de llenar el formulario. La devolución se puede registrar
    // igual, pero sin caja abierta el reembolso no entra en ningún arqueo, y eso
    // se entiende mejor antes de teclear las cantidades que en un aviso al final.
    $metodoVenta = qOne(
        "SELECT m.nombre, m.afecta_caja, m.es_credito FROM venta_pagos vp
           JOIN metodos_pago m ON m.id = vp.metodo_pago_id
          WHERE vp.venta_id = ? ORDER BY vp.id LIMIT 1",
        [$ventaId]
    );
    $reembolsoEnEfectivo = $metodoVenta
        && (int) $metodoVenta['afecta_caja'] === 1 && (int) $metodoVenta['es_credito'] === 0;
    $sinCajaAbierta = $reembolsoEnEfectivo
        && !cajaSesionAbierta((int) $v['sucursal_id'], (int) current_user()['id']);
    ?>
    <?php if ($sinCajaAbierta): ?>
      <div class="card p-4 mb-4 max-w-3xl bg-amber-50 border-amber-200 flex items-start gap-3">
        <?= icon('alert', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
        <div class="text-sm text-amber-900">
          <p class="font-semibold">Esta venta se cobró en efectivo y no tienes la caja abierta.</p>
          <p class="mt-0.5 leading-snug">
            Puedes registrar la devolución igual, pero el reembolso no quedará anotado en ningún
            arqueo y al cerrar turno aparecerá como faltante.
            <a href="<?= url('modules/pos/caja.php') ?>" class="underline font-medium">Abre tu caja</a>
            antes, o pide a quien la tenga que registre la devolución.
          </p>
        </div>
      </div>
    <?php endif; ?>
    <form method="post" class="card p-6 max-w-3xl">
      <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="venta_id" value="<?= $ventaId ?>">
      <div class="overflow-x-auto border border-slate-200 rounded-xl mb-4">
        <table class="w-full text-sm">
          <thead class="bg-slate-50"><tr><th class="text-left px-4 py-2.5 text-xs font-semibold text-slate-400 uppercase">Producto</th><th class="px-2 py-2.5 text-xs font-semibold text-slate-400 uppercase text-center">Vendido</th><th class="px-2 py-2.5 text-xs font-semibold text-slate-400 uppercase text-center">Ya devuelto</th><th class="px-2 py-2.5 text-xs font-semibold text-slate-400 uppercase text-center w-32">Devolver</th></tr></thead>
          <tbody>
            <?php $factorDev = (float) $v['subtotal'] > 0 ? (((float) $v['subtotal'] - (float) $v['descuento']) / (float) $v['subtotal']) : 1.0;
            foreach ($detalles as $d):
              $yaDev = (float) qVal(
                  "SELECT COALESCE(SUM(dd.cantidad),0)
                   FROM devolucion_detalles dd JOIN devoluciones de ON de.id=dd.devolucion_id
                   WHERE de.venta_id=? AND (dd.venta_detalle_id=? OR (dd.venta_detalle_id IS NULL AND dd.producto_id <=> ? AND dd.descripcion=?))",
                  [$ventaId, $d['id'], $d['producto_id'], $d['descripcion']]
              );
              $max = (float) $d['cantidad'] - $yaDev;
              $unitReembolso = (float) $d['cantidad'] > 0 ? ((((float) $d['subtotal'] * $factorDev) + (float) $d['itbis']) / (float) $d['cantidad']) : 0;
            ?>
              <tr class="border-t border-slate-100">
                <td class="px-4 py-2.5"><p class="font-semibold text-slate-700"><?= e($d['descripcion']) ?></p><p class="text-xs text-slate-400">Reembolso unitario: <?= money($unitReembolso) ?></p></td>
                <td class="px-2 py-2.5 text-center"><?= qty($d['cantidad']) ?></td>
                <td class="px-2 py-2.5 text-center text-slate-400"><?= qty($yaDev) ?></td>
                <td class="px-2 py-2.5 text-center"><input type="number" name="ret[<?= (int) $d['id'] ?>]" min="0" max="<?= $max ?>" step="0.001" value="0" <?= $max <= 0 ? 'disabled' : '' ?> class="input py-1.5 px-2 text-center w-24"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mb-4"><label class="label">Motivo de la devolución *</label><input name="motivo" required class="input" placeholder="Ej. Producto defectuoso, cliente insatisfecho..."></div>
      <div class="flex justify-end gap-2"><a href="<?= e(url('modules/pos/devoluciones.php')) ?>" class="btn btn-ghost">Cancelar</a><button class="btn btn-danger"><?= icon('undo', 'w-4 h-4') ?> Registrar devolución</button></div>
    </form>
    <?php layout_end(); return;
}

// ----- Listado -----
[$scope, $sp] = sucursalFiltro('d.sucursal_id');
$q = trim(get('q'));
$cond = [$scope];
$params = $sp;
if ($q !== '') { $cond[] = "(d.numero LIKE ? OR v.numero LIKE ? OR d.motivo LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
$where = implode(' AND ', $cond);

$joinBase = "FROM devoluciones d JOIN ventas v ON v.id=d.venta_id JOIN sucursales su ON su.id=d.sucursal_id LEFT JOIN usuarios u ON u.id=d.usuario_id WHERE $where";
$pg = paginar((int) qVal("SELECT COUNT(*) FROM devoluciones d JOIN ventas v ON v.id=d.venta_id WHERE $where", $params), 25);
$devs = qAll("SELECT d.*, v.numero AS venta_numero, su.nombre AS sucursal, u.nombre AS usuario $joinBase ORDER BY d.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $params);

// El indicador que importa no es cuántas devoluciones hay, sino qué proporción
// de lo vendido se está devolviendo: un 1% es normal, un 15% es un problema de
// producto, de precio o de quien vende.
[$scopeV, $spV] = sucursalFiltro('v.sucursal_id');
$mes = date('Y-m-01');
$resumen = qOne(
    "SELECT (SELECT COUNT(*) FROM devoluciones d2 JOIN ventas v2 ON v2.id = d2.venta_id
              WHERE d2.created_at >= ? AND " . str_replace('v.', 'v2.', $scopeV) . ") n_mes,
            (SELECT COALESCE(SUM(d2.total), 0) FROM devoluciones d2 JOIN ventas v2 ON v2.id = d2.venta_id
              WHERE d2.created_at >= ? AND " . str_replace('v.', 'v2.', $scopeV) . ") monto_mes,
            (SELECT COALESCE(SUM(v.total), 0) FROM ventas v
              WHERE v.fecha >= ? AND v.estado <> 'anulada' AND $scopeV) vendido_mes",
    array_merge([$mes], $spV, [$mes], $spV, [$mes], $spV)
) ?: ['n_mes' => 0, 'monto_mes' => 0, 'vendido_mes' => 0];

$vendido = (float) $resumen['vendido_mes'];
$devuelto = (float) $resumen['monto_mes'];
$pct = $vendido > 0 ? round($devuelto / $vendido * 100, 1) : 0.0;

$acciones = can('devoluciones.crear') ? btn_nuevo('dev:new', 'Nueva devolución') : '';
layout_start('Devoluciones', 'Registro de devoluciones de mercancía', $acciones);

echo kpis([
    ['label' => 'Devoluciones del mes', 'valor' => number_format((int) $resumen['n_mes']), 'icono' => 'undo',
     'color' => 'slate', 'nota' => 'Desde el día 1'],
    ['label' => 'Reembolsado este mes', 'valor' => money($devuelto), 'icono' => 'wallet',
     'color' => $devuelto > 0 ? 'amber' : 'slate', 'nota' => 'Dinero que salió de vuelta'],
    ['label' => 'Sobre lo vendido', 'valor' => $pct . '%', 'icono' => 'trending',
     // Por encima del 5% deja de ser ruido y pasa a ser una señal que hay que mirar.
     'color' => $pct >= 5 ? 'rose' : ($pct > 0 ? 'amber' : 'emerald'),
     'nota' => $vendido > 0 ? 'De ' . money($vendido) . ' facturados' : 'Sin ventas este mes'],
    ['label' => 'Total histórico', 'valor' => number_format($pg['total']), 'icono' => 'history', 'color' => 'blue',
     'nota' => 'En el filtro actual'],
], 4);
?>

<div class="card overflow-hidden">
  <?php $selSuc = selectSucursalFiltro(); ?>
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?php if ($selSuc): ?>
      <form method="get" class="flex items-center gap-2 flex-wrap">
        <input type="hidden" name="p" value="1">
        <input type="search" name="q" data-buscar value="<?= e($q) ?>" placeholder="Devolución, venta o motivo..." aria-label="Buscar devolución" autocomplete="off" class="input w-64">
        <?= $selSuc ?>
        <button class="btn btn-primary cursor-pointer" aria-label="Aplicar filtros" title="Filtrar"><?= icon('filter', 'w-4 h-4') ?></button>
      </form>
    <?php else: ?>
      <?= search_box('Devolución, venta o motivo...') ?>
    <?php endif; ?>
    <span class="text-sm text-slate-400"><?= number_format($pg['total']) ?> devoluciones</span>
  </div>

  <?php if (!$devs): ?>
    <?= empty_state('Sin devoluciones', $q !== '' ? 'Ninguna devolución coincide con la búsqueda.' : 'Las devoluciones registradas aparecerán aquí.', 'undo') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Devolución</th><th>Nota de crédito</th><th>Venta</th><th>Sucursal</th><th>Motivo</th><th>Usuario</th><th>Fecha</th><th class="text-right">Total</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($devs as $d): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($d['numero']) ?></td>
              <td class="font-mono text-xs"><?= $d['ncf'] ? e($d['ncf']) : '<span class="text-slate-300">—</span>' ?></td>
              <td class="text-slate-600"><?= e($d['venta_numero']) ?></td>
              <td class="text-slate-500"><?= e($d['sucursal']) ?></td>
              <td class="text-slate-500 max-w-xs truncate"><?= e($d['motivo'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($d['usuario'] ?: '—') ?></td>
              <td class="text-slate-500"><?= fechaHora($d['created_at']) ?></td>
              <td class="text-right font-bold text-rose-600"><?= money($d['total']) ?></td>
              <td class="text-right whitespace-nowrap">
                <a href="<?= e(url('modules/pos/nota_credito.php?id=' . (int) $d['id'])) ?>" target="_blank"
                   class="p-2 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100"
                   title="Nota de crédito"><?= icon('print', 'w-4 h-4') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<?php if (can('devoluciones.crear')): ?>
<!-- Modal: buscar la factura que se va a devolver -->
<div x-data="{open:false}" @dev:new.window="open=true; $nextTick(() => $refs.numero && $refs.numero.focus())"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="buscar">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nueva devolución</h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div class="flex items-start gap-3 rounded-xl bg-slate-50 p-3.5">
            <span class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 flex items-center justify-center shrink-0"><?= icon('receipt', 'w-4 h-4') ?></span>
            <p class="text-[13px] text-slate-600 leading-relaxed">Indica la factura que el cliente quiere devolver. En el siguiente paso eliges qué líneas y cuántas unidades regresan.</p>
          </div>
          <div>
            <label class="label" for="dev_numero">Número de factura *</label>
            <input id="dev_numero" x-ref="numero" name="numero" required autocomplete="off" class="input" placeholder="Ej. VTA-000012">
            <p class="text-xs text-slate-400 mt-1.5">Solo se pueden devolver ventas completadas de tu sucursal activa.</p>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('search', 'w-4 h-4') ?> Buscar venta</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
