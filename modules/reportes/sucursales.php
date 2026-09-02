<?php
/** Comparativo de sucursales: cada local, lado a lado, con los mismos criterios. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
// Lo abre la CEO con su paquete de dirección, y también quien tenga solo este
// informe: comparar locales no obliga a ver la utilidad de toda la empresa.
require_any_perm(['reportes.ejecutivo', 'reportes.sucursales']);

$p = rep_periodo('mes');
$visibles = sucursales_visibles();
$ids = array_map(fn($s) => (int) $s['id'], $visibles);
if (!$ids) $ids = [0];
$ph = implode(',', array_fill(0, count($ids), '?'));

/* ---------- Ventas y utilidad ---------- */
// Criterio compartido: entra «completada» y también «devuelta», y lo devuelto se
// resta abajo. Antes se dejaba fuera la devuelta Y ADEMÁS se restaba la
// devolución, así que la factura con una unidad devuelta de cinco se castigaba
// dos veces y el local salía peor de lo que fue.
$ventas = qAll(
    "SELECT v.sucursal_id, COUNT(*) facturas,
            COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
            COALESCE(SUM(v.costo_total),0) costo,
            COALESCE(SUM(v.descuento),0) descuentos,
            COALESCE(SUM(v.itbis),0) itbis,
            COUNT(DISTINCT v.cliente_id) clientes
       FROM ventas v
      WHERE " . rep_estados_venta() . " AND v.fecha BETWEEN ? AND ? AND v.sucursal_id IN ($ph)
      GROUP BY v.sucursal_id",
    array_merge([$p['ini'], $p['fin']], $ids)
);
$prev = qAll(
    "SELECT v.sucursal_id, COALESCE(SUM(v.subtotal - v.descuento),0) ingresos
       FROM ventas v
      WHERE " . rep_estados_venta() . " AND v.fecha BETWEEN ? AND ? AND v.sucursal_id IN ($ph)
      GROUP BY v.sucursal_id",
    array_merge([$p['prev_ini'], $p['prev_fin']], $ids)
);
// El periodo anterior se compara con la misma vara: también neto de devoluciones.
$prevDev = qAll(
    "SELECT d.sucursal_id, COALESCE(SUM(d.subtotal),0) t FROM devoluciones d
      WHERE d.created_at BETWEEN ? AND ? AND d.sucursal_id IN ($ph) GROUP BY d.sucursal_id",
    array_merge([$p['prev_ini'], $p['prev_fin']], $ids)
);
$gastos = qAll(
    "SELECT t.sucursal_id, COALESCE(SUM(t.monto),0) g FROM transacciones t
      WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND t.sucursal_id IN ($ph)
      GROUP BY t.sucursal_id",
    array_merge([$p['desde'], $p['hasta']], $ids)
);
$devol = qAll(
    // El costo de lo devuelto también sale: la mercancía volvió al almacén y deja
    // de ser costo de venta. Sin esto, el margen del local salía hundido.
    "SELECT d.sucursal_id, COALESCE(SUM(d.subtotal),0) t, COUNT(*) n,
            COALESCE(SUM(dd.costo),0) c
       FROM devoluciones d
       LEFT JOIN (SELECT x.devolucion_id, SUM(x.cantidad * vd.costo_unitario) costo
                    FROM devolucion_detalles x
                    LEFT JOIN venta_detalles vd ON vd.id = x.venta_detalle_id
                   GROUP BY x.devolucion_id) dd ON dd.devolucion_id = d.id
      WHERE d.created_at BETWEEN ? AND ? AND d.sucursal_id IN ($ph) GROUP BY d.sucursal_id",
    array_merge([$p['ini'], $p['fin']], $ids)
);
$inventario = qAll(
    "SELECT s.sucursal_id, COALESCE(SUM(s.cantidad * pr.precio_compra),0) v
       FROM inventario_stock s JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1
      WHERE s.sucursal_id IN ($ph) GROUP BY s.sucursal_id",
    $ids
);
$empleados = qAll(
    "SELECT sucursal_id, COUNT(*) n FROM empleados WHERE estado = 'activo' AND sucursal_id IN ($ph) GROUP BY sucursal_id",
    $ids
);

$idx = function (array $rows, string $key, string $campo) {
    $m = [];
    foreach ($rows as $r) $m[(int) $r[$key]] = $r[$campo];
    return $m;
};
$mPrev  = $idx($prev, 'sucursal_id', 'ingresos');
$mGasto = $idx($gastos, 'sucursal_id', 'g');
$mDevT  = $idx($devol, 'sucursal_id', 't');
$mDevN  = $idx($devol, 'sucursal_id', 'n');
$mDevC  = $idx($devol, 'sucursal_id', 'c');
$mPrevD = $idx($prevDev, 'sucursal_id', 't');
$mInv   = $idx($inventario, 'sucursal_id', 'v');
$mEmp   = $idx($empleados, 'sucursal_id', 'n');
$mVenta = [];
foreach ($ventas as $v) $mVenta[(int) $v['sucursal_id']] = $v;

$datos = [];
foreach ($visibles as $s) {
    $sid = (int) $s['id'];
    $v   = $mVenta[$sid] ?? ['facturas' => 0, 'ingresos' => 0, 'costo' => 0, 'descuentos' => 0, 'itbis' => 0, 'clientes' => 0];
    $ing = (float) $v['ingresos'] - (float) ($mDevT[$sid] ?? 0);
    $cos = (float) $v['costo'] - (float) ($mDevC[$sid] ?? 0);
    $gas = (float) ($mGasto[$sid] ?? 0);
    $emp = (int) ($mEmp[$sid] ?? 0);
    $datos[] = [
        'id' => $sid, 'nombre' => $s['nombre'],
        'facturas' => (int) $v['facturas'], 'ingresos' => $ing, 'costo' => $cos,
        'bruta' => $ing - $cos, 'gastos' => $gas, 'neta' => $ing - $cos - $gas,
        'margen' => $ing > 0 ? ($ing - $cos) / $ing * 100 : 0,
        'ticket' => (int) $v['facturas'] > 0 ? $ing / (int) $v['facturas'] : 0,
        'clientes' => (int) $v['clientes'], 'descuentos' => (float) $v['descuentos'],
        'devoluciones' => (float) ($mDevT[$sid] ?? 0), 'devol_n' => (int) ($mDevN[$sid] ?? 0),
        'inventario' => (float) ($mInv[$sid] ?? 0), 'empleados' => $emp,
        'por_empleado' => $emp > 0 ? $ing / $emp : 0,
        'delta' => rep_delta($ing, (float) ($mPrev[$sid] ?? 0) - (float) ($mPrevD[$sid] ?? 0)),
        'rotacion' => (float) ($mInv[$sid] ?? 0) > 0 ? $cos / (float) $mInv[$sid] : 0,
    ];
}
usort($datos, fn($a, $b) => $b['ingresos'] <=> $a['ingresos']);
$totalIng = array_sum(array_column($datos, 'ingresos')) ?: 1;

if (export_solicitado()) {
    $filas = [];
    foreach ($datos as $d) {
        $filas[] = [$d['nombre'], $d['facturas'], money($d['ingresos'], false), money($d['costo'], false),
            money($d['bruta'], false), number_format($d['margen'], 2), money($d['gastos'], false),
            money($d['neta'], false), money($d['ticket'], false), $d['clientes'],
            money($d['devoluciones'], false), money($d['inventario'], false), $d['empleados'],
            money($d['por_empleado'], false)];
    }
    export_tabla('comparativo_sucursales_' . $p['desde'] . '_' . $p['hasta'],
        ['Sucursal', 'Facturas', 'Ingresos', 'Costo', 'Utilidad bruta', 'Margen %', 'Gastos', 'Utilidad neta',
         'Ticket promedio', 'Clientes', 'Devoluciones', 'Inventario a costo', 'Empleados', 'Venta por empleado'],
        $filas, 'Comparativo de sucursales');
}

layout_start('Comparativo de sucursales', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Comparativo de sucursales', $p, ['sucursal' => false]);
?>

<?php if (count($datos) < 2): ?>
  <div class="card p-4 mb-5 flex items-start gap-3">
    <span class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><?= icon('store', 'w-4 h-4') ?></span>
    <p class="text-sm text-slate-600">Este reporte compara locales entre sí. Con una sola sucursal visible muestra únicamente sus cifras.</p>
  </div>
<?php endif; ?>

<!-- Tarjetas por sucursal -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-5">
  <?php foreach ($datos as $i => $d): ?>
    <div class="card p-5 print-break">
      <div class="flex items-start justify-between gap-2">
        <div class="flex items-center gap-2.5 min-w-0">
          <span class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background:<?= rep_color($i) ?>1a;color:<?= rep_color($i) ?>"><?= icon('store', 'w-5 h-5') ?></span>
          <div class="min-w-0">
            <h3 class="font-bold text-slate-800 truncate"><?= e($d['nombre']) ?></h3>
            <p class="text-[11px] text-slate-400"><?= number_format($d['ingresos'] / $totalIng * 100, 1) ?>% del total</p>
          </div>
        </div>
        <?php if ($d['delta'] !== null): ?>
          <span class="badge <?= $d['delta'] >= 0 ? 'stat-trend-up' : 'stat-trend-down' ?> shrink-0">
            <?= icon($d['delta'] >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') ?> <?= number_format(abs($d['delta']), 1) ?>%
          </span>
        <?php endif; ?>
      </div>

      <p class="text-2xl font-extrabold text-slate-800 mt-4 tabular-nums"><?= money($d['ingresos']) ?></p>
      <p class="text-xs text-slate-400">Ingresos netos · <?= number_format($d['facturas']) ?> factura(s)</p>

      <div class="mt-4 space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-slate-500">Utilidad bruta</span>
          <span class="font-semibold text-slate-700 tabular-nums"><?= money($d['bruta']) ?> <span class="text-slate-400 text-xs">(<?= number_format($d['margen'], 1) ?>%)</span></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Gastos</span>
          <span class="font-semibold text-rose-600 tabular-nums"><?= money($d['gastos']) ?></span></div>
        <div class="flex justify-between pt-2 border-t border-slate-100"><span class="font-semibold text-slate-700">Utilidad neta</span>
          <span class="font-extrabold <?= $d['neta'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> tabular-nums"><?= money($d['neta']) ?></span></div>
      </div>

      <div class="grid grid-cols-3 gap-2 mt-4 pt-4 border-t border-slate-100 text-center">
        <div><p class="text-sm font-bold text-slate-700 tabular-nums"><?= money($d['ticket'], false) ?></p><p class="text-[10.5px] text-slate-400">Ticket</p></div>
        <div><p class="text-sm font-bold text-slate-700 tabular-nums"><?= number_format($d['clientes']) ?></p><p class="text-[10.5px] text-slate-400">Clientes</p></div>
        <div><p class="text-sm font-bold text-slate-700 tabular-nums"><?= number_format($d['empleados']) ?></p><p class="text-[10.5px] text-slate-400">Empleados</p></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Tabla detallada -->
<?= rep_seccion('Todos los indicadores, lado a lado', 'Mismo criterio contable para cada local', 'scale', 'indigo') ?>
  <?php
  $filas = [];
  foreach ($datos as $d) {
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($d['nombre']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . number_format($d['facturas']) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($d['ingresos']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($d['costo']) . '</span>',
          '<span class="text-slate-700 font-semibold tabular-nums">' . money($d['bruta']) . '</span>',
          '<span class="badge badge-' . ($d['margen'] >= 25 ? 'emerald' : ($d['margen'] >= 10 ? 'amber' : 'rose')) . '">' . number_format($d['margen'], 1) . '%</span>',
          '<span class="text-rose-600 tabular-nums">' . money($d['gastos']) . '</span>',
          '<span class="font-bold ' . ($d['neta'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' tabular-nums">' . money($d['neta']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($d['ticket']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($d['inventario']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($d['por_empleado']) . '</span>',
      ];
  }
  $sum = fn($c) => array_sum(array_column($datos, $c));
  echo rep_tabla(
      ['Sucursal', ['Facturas', 'center'], ['Ingresos', 'right'], ['Costo', 'right'], ['Utilidad bruta', 'right'],
       ['Margen', 'center'], ['Gastos', 'right'], ['Utilidad neta', 'right'], ['Ticket', 'right'],
       ['Inventario', 'right'], ['Venta/empleado', 'right']],
      $filas,
      ['total' => $filas ? ['Total', number_format($sum('facturas')), money($sum('ingresos')), money($sum('costo')),
          money($sum('bruta')),
          ($sum('ingresos') > 0 ? number_format($sum('bruta') / $sum('ingresos') * 100, 1) . '%' : '—'),
          money($sum('gastos')), money($sum('neta')), '', money($sum('inventario')), ''] : null]
  );
  ?>
<?= rep_fin() ?>

<!-- Participación -->
<?= rep_seccion('Participación en la facturación', 'Cuánto aporta cada local al total', 'pie', 'emerald') ?>
  <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
    <?php foreach ($datos as $i => $d): ?>
      <?= rep_barra($d['nombre'], money($d['ingresos'], false), $d['ingresos'] / $totalIng * 100, rep_color($i),
                    number_format($d['facturas']) . ' facturas') ?>
    <?php endforeach; ?>
  </div>
<?= rep_fin() ?>

<?php layout_end(); ?>
