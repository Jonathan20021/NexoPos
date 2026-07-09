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
            $devId = tx(function () use ($ventaId, $motivo, $ret) {
                $v = qOne("SELECT * FROM ventas WHERE id = ? FOR UPDATE", [$ventaId]);
                if (!$v || $v['estado'] === 'anulada') throw new RuntimeException('Venta no válida.');
                if (!can_access_sucursal($v['sucursal_id'])) throw new RuntimeException('No tienes acceso a la sucursal de esta venta.');
                if ($motivo === '') throw new RuntimeException('Indica el motivo de la devolución.');
                $totalDev = 0; $lineas = []; $totVendido = 0;
                $factorVenta = (float) $v['subtotal'] > 0
                    ? ((float) $v['subtotal'] - (float) $v['descuento']) / (float) $v['subtotal']
                    : 1.0;
                $detalles = qAll(
                    "SELECT vd.*, p.tipo AS producto_tipo,
                            COALESCE(NULLIF(vd.descripcion,''), p.nombre, '(producto no disponible)') AS descripcion
                     FROM venta_detalles vd LEFT JOIN productos p ON p.id=vd.producto_id
                     WHERE vd.venta_id = ?",
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
                    $importeLineaCobrado = ((float) $d['subtotal'] * $factorVenta) + (float) $d['itbis'];
                    $sub = round($importeLineaCobrado * ($cant / (float) $d['cantidad']), 2);
                    $precioReembolso = round($sub / $cant, 2);
                    $totalDev += $sub;
                    $lineas[] = ['vdid' => $d['id'], 'pid' => $d['producto_id'], 'es_stock' => $d['producto_tipo'] === 'producto', 'desc' => $d['descripcion'], 'cant' => $cant, 'precio' => $precioReembolso, 'costo' => (float) $d['costo_unitario'], 'sub' => $sub];
                }
                if (!$lineas) throw new RuntimeException('Indica al menos una cantidad a devolver.');
                $numero = nextNumero('devoluciones', 'numero', 'DEV');
                $devId = dbInsert('devoluciones', ['numero' => $numero, 'venta_id' => $ventaId, 'sucursal_id' => $v['sucursal_id'], 'usuario_id' => current_user()['id'], 'motivo' => $motivo, 'total' => $totalDev]);
                foreach ($lineas as $l) {
                    dbInsert('devolucion_detalles', ['devolucion_id' => $devId, 'venta_detalle_id' => $l['vdid'], 'producto_id' => $l['pid'], 'descripcion' => $l['desc'], 'cantidad' => $l['cant'], 'precio_unitario' => $l['precio'], 'subtotal' => $l['sub']]);
                    if ($l['pid'] && $l['es_stock']) ajustarStock((int) $l['pid'], (int) $v['sucursal_id'], $l['cant'], 'devolucion', 'devolucion', $devId, $l['costo'], 'Devolución ' . $numero);
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
                        $sesionCaja = cajaSesionAbierta((int) $v['sucursal_id'], (int) current_user()['id']);
                        if ($sesionCaja) {
                            dbInsert('caja_movimientos', [
                                'caja_sesion_id' => (int) $sesionCaja['id'], 'tipo' => 'egreso',
                                'concepto' => 'Reembolso ' . $numero, 'monto' => $totalDev,
                                'usuario_id' => current_user()['id'], 'created_at' => date('Y-m-d H:i:s'),
                            ]);
                        }
                    }
                }
                // ¿Devolución total?
                $totDevuelto = (float) qVal("SELECT COALESCE(SUM(dd.cantidad),0) FROM devolucion_detalles dd JOIN devoluciones de ON de.id=dd.devolucion_id WHERE de.venta_id=?", [$ventaId]);
                if ($totDevuelto >= $totVendido) dbUpdate('ventas', ['estado' => 'devuelta'], 'id = ?', [$ventaId]);
                return $devId;
            });
            audit('devoluciones', 'crear', 'Devolución registrada', ['tabla' => 'devoluciones', 'registro_id' => $devId]);
            flash('success', 'Devolución registrada y stock actualizado.');
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
    ?>
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
[$scope, $sp] = sucursalScope('d.sucursal_id');
$devs = qAll("SELECT d.*, v.numero AS venta_numero, su.nombre AS sucursal, u.nombre AS usuario FROM devoluciones d JOIN ventas v ON v.id=d.venta_id JOIN sucursales su ON su.id=d.sucursal_id LEFT JOIN usuarios u ON u.id=d.usuario_id WHERE $scope ORDER BY d.id DESC LIMIT 100", $sp);

$acciones = can('devoluciones.crear') ? '<button onclick="document.getElementById(\'buscarDev\').classList.toggle(\'hidden\')" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nueva devolución</button>' : '';
layout_start('Devoluciones', 'Registro de devoluciones de mercancía', $acciones);
?>

<?php if (can('devoluciones.crear')): ?>
<div id="buscarDev" class="card p-5 mb-5 hidden">
  <form method="post" class="flex items-end gap-3 flex-wrap">
    <?= csrf_field() ?><input type="hidden" name="accion" value="buscar">
    <div class="flex-1 min-w-[240px]"><label class="label">Número de factura a devolver</label><input name="numero" required class="input" placeholder="Ej. VTA-000012"></div>
    <button class="btn btn-primary"><?= icon('search', 'w-4 h-4') ?> Buscar venta</button>
  </form>
</div>
<?php endif; ?>

<div class="card overflow-hidden">
  <?php if (!$devs): ?>
    <?= empty_state('Sin devoluciones', 'Las devoluciones registradas aparecerán aquí.', 'undo') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Devolución</th><th>Venta</th><th>Sucursal</th><th>Motivo</th><th>Usuario</th><th>Fecha</th><th class="text-right">Total</th></tr></thead>
        <tbody>
          <?php foreach ($devs as $d): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($d['numero']) ?></td>
              <td class="text-slate-600"><?= e($d['venta_numero']) ?></td>
              <td class="text-slate-500"><?= e($d['sucursal']) ?></td>
              <td class="text-slate-500 max-w-xs truncate"><?= e($d['motivo'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($d['usuario'] ?: '—') ?></td>
              <td class="text-slate-500"><?= fechaHora($d['created_at']) ?></td>
              <td class="text-right font-bold text-rose-600"><?= money($d['total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
