<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.ver');
require_once dirname(__DIR__, 2) . '/includes/charts.php';

/* ============================================================
 *  Filtro de periodo + scope de sucursal
 * ============================================================ */
$desde = trim(get('desde'));
$hasta = trim(get('hasta'));
$desde = ($desde && strtotime($desde)) ? date('Y-m-d', strtotime($desde)) : date('Y-m-01');
$hasta = ($hasta && strtotime($hasta)) ? date('Y-m-d', strtotime($hasta)) : date('Y-m-t');
if ($desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }

// Límites datetime para comparar contra ventas.fecha (DATETIME)
$ini = $desde . ' 00:00:00';
$fin = $hasta . ' 23:59:59';

$sid       = current_sucursal_id();
$esTodas   = $sid === null;
[$scopeW, $scopeP] = sucursalScope('v.sucursal_id');   // ventas
[$scopeT, $scopeTP] = sucursalScope('t.sucursal_id');  // transacciones

// Parámetros base reutilizables (periodo sobre ventas + scope)
$pVentas = array_merge([$ini, $fin], $scopeP);

/* ============================================================
 *  a) Estado de resultados resumido
 * ============================================================ */
$ingresosVentas = (float) qVal(
    "SELECT COALESCE(SUM(v.total),0) FROM ventas v
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW",
    $pVentas
);
$costoVentas = (float) qVal(
    "SELECT COALESCE(SUM(v.costo_total),0) FROM ventas v
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW",
    $pVentas
);
$utilidadBruta = $ingresosVentas - $costoVentas;

$otrosGastos = (float) qVal(
    "SELECT COALESCE(SUM(t.monto),0) FROM transacciones t
     WHERE t.tipo = 'gasto' AND t.fecha BETWEEN ? AND ? AND $scopeT",
    array_merge([$desde, $hasta], $scopeTP)
);
$utilidadNeta = $utilidadBruta - $otrosGastos;

$nVentas = (int) qVal(
    "SELECT COUNT(*) FROM ventas v
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW",
    $pVentas
);

/* ============================================================
 *  b) Ventas por día (barChart)
 * ============================================================ */
$ventasDiaRows = qAll(
    "SELECT DATE(v.fecha) AS dia, SUM(v.total) AS total
     FROM ventas v
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY DATE(v.fecha) ORDER BY dia",
    $pVentas
);
// Mapa dia => total para rellenar días sin ventas (si el rango es <= 62 días)
$ventasPorDia = [];
foreach ($ventasDiaRows as $r) $ventasPorDia[$r['dia']] = (float) $r['total'];

$serieDia = [];
$diasRango = (strtotime($hasta) - strtotime($desde)) / 86400;
if ($diasRango >= 0 && $diasRango <= 62) {
    for ($t = strtotime($desde); $t <= strtotime($hasta); $t += 86400) {
        $d = date('Y-m-d', $t);
        $serieDia[] = ['label' => date('d/m', $t), 'value' => $ventasPorDia[$d] ?? 0];
    }
} else {
    // Rango muy amplio: muestra solo los días con ventas
    foreach ($ventasDiaRows as $r) {
        $serieDia[] = ['label' => date('d/m', strtotime($r['dia'])), 'value' => (float) $r['total']];
    }
}
$totalSerieDia = array_sum(array_column($serieDia, 'value'));

/* ============================================================
 *  c) Top 10 productos por unidades vendidas
 * ============================================================ */
$topProductos = qAll(
    "SELECT p.nombre, c.nombre AS categoria,
            SUM(vd.cantidad) AS unidades, SUM(vd.subtotal) AS total
     FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     JOIN productos p ON p.id = vd.producto_id
     LEFT JOIN categorias c ON c.id = p.categoria_id
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY p.id ORDER BY unidades DESC, total DESC LIMIT 10",
    $pVentas
);

/* ============================================================
 *  d) Ventas por categoría (barras horizontales con %)
 * ============================================================ */
$porCategoria = qAll(
    "SELECT COALESCE(c.nombre,'Sin categoría') AS categoria,
            COALESCE(c.color,'slate') AS color, SUM(vd.subtotal) AS total
     FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     JOIN productos p ON p.id = vd.producto_id
     LEFT JOIN categorias c ON c.id = p.categoria_id
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY c.id ORDER BY total DESC",
    $pVentas
);
$totalCat = array_sum(array_column($porCategoria, 'total')) ?: 1;

/* ============================================================
 *  e) Ventas por sucursal
 * ============================================================ */
$porSucursal = qAll(
    "SELECT su.nombre AS sucursal, COUNT(v.id) AS ventas, COALESCE(SUM(v.total),0) AS total
     FROM ventas v
     JOIN sucursales su ON su.id = v.sucursal_id
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY v.sucursal_id ORDER BY total DESC",
    $pVentas
);

/* ============================================================
 *  f) Ventas por método de pago
 * ============================================================ */
$porMetodo = qAll(
    "SELECT mp.nombre AS metodo, COUNT(DISTINCT vp.venta_id) AS ventas, COALESCE(SUM(vp.monto),0) AS total
     FROM venta_pagos vp
     JOIN ventas v ON v.id = vp.venta_id
     JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY vp.metodo_pago_id ORDER BY total DESC",
    $pVentas
);
$totalMetodo = array_sum(array_column($porMetodo, 'total')) ?: 1;

/* ============================================================
 *  g) Ventas por vendedor
 * ============================================================ */
$porVendedor = qAll(
    "SELECT CONCAT(u.nombre,' ',u.apellido) AS vendedor, COUNT(v.id) AS ventas, COALESCE(SUM(v.total),0) AS total
     FROM ventas v
     JOIN usuarios u ON u.id = v.usuario_id
     WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY v.usuario_id ORDER BY total DESC LIMIT 15",
    $pVentas
);

$catColors = ['blue'=>'#2563eb','emerald'=>'#10b981','amber'=>'#f59e0b','rose'=>'#f43f5e','indigo'=>'#6366f1','cyan'=>'#06b6d4','sky'=>'#0ea5e9','pink'=>'#ec4899','slate'=>'#64748b','violet'=>'#8b5cf6'];

/* ============================================================
 *  Exportación a PDF gerencial profesional (Dompdf con marca)
 * ============================================================ */
if (quiere_pdf() && function_exists('pdf_render')) {
    $subt = 'Periodo ' . fechaCorta($desde) . ' al ' . fechaCorta($hasta) . ' · ' . ($esTodas ? 'Todas las sucursales' : (current_user()['sucursal_nombre'] ?? ''));
    $H = pdf_brand_header('REPORTE GERENCIAL', $subt);

    $H .= '<h3>Estado de resultados</h3><table class="tbl"><tbody>'
        . '<tr><td>Ingresos por ventas (' . $nVentas . ' ventas)</td><td class="num">' . money($ingresosVentas) . '</td></tr>'
        . '<tr><td>(−) Costo de ventas</td><td class="num">' . money($costoVentas) . '</td></tr>'
        . '<tr style="background:#f1f5f9;"><td><strong>Utilidad bruta</strong></td><td class="num"><strong>' . money($utilidadBruta) . '</strong></td></tr>'
        . '<tr><td>(−) Otros gastos</td><td class="num">' . money($otrosGastos) . '</td></tr>'
        . '<tr style="background:#eff6ff;"><td><strong>Utilidad neta</strong></td><td class="num"><strong>' . money($utilidadNeta) . '</strong></td></tr>'
        . '</tbody></table>';

    $H .= '<h3>Top 10 productos por unidades</h3><table class="tbl"><thead><tr><th>#</th><th>Producto</th><th>Categoría</th><th class="num">Unidades</th><th class="num">Total</th></tr></thead><tbody>';
    foreach ($topProductos as $i => $p) $H .= '<tr><td>' . ($i + 1) . '</td><td>' . htmlspecialchars($p['nombre']) . '</td><td>' . htmlspecialchars($p['categoria'] ?: '—') . '</td><td class="num">' . qty($p['unidades']) . '</td><td class="num">' . money($p['total']) . '</td></tr>';
    $H .= ($topProductos ? '' : '<tr><td colspan="5">Sin datos</td></tr>') . '</tbody></table>';

    $H .= '<h3>Ventas por categoría</h3><table class="tbl"><thead><tr><th>Categoría</th><th class="num">Total</th><th class="num">%</th></tr></thead><tbody>';
    foreach ($porCategoria as $pc) $H .= '<tr><td>' . htmlspecialchars($pc['categoria']) . '</td><td class="num">' . money($pc['total']) . '</td><td class="num">' . round($pc['total'] / $totalCat * 100, 1) . '%</td></tr>';
    $H .= '</tbody></table>';

    $H .= '<h3>Ventas por método de pago</h3><table class="tbl"><thead><tr><th>Método</th><th class="num">Ventas</th><th class="num">Monto</th><th class="num">%</th></tr></thead><tbody>';
    foreach ($porMetodo as $m) $H .= '<tr><td>' . htmlspecialchars($m['metodo']) . '</td><td class="num">' . (int) $m['ventas'] . '</td><td class="num">' . money($m['total']) . '</td><td class="num">' . round($m['total'] / $totalMetodo * 100, 1) . '%</td></tr>';
    $H .= '</tbody></table>';

    $H .= '<h3>Ventas por vendedor</h3><table class="tbl"><thead><tr><th>Vendedor</th><th class="num">Ventas</th><th class="num">Total</th></tr></thead><tbody>';
    foreach ($porVendedor as $vn) $H .= '<tr><td>' . htmlspecialchars($vn['vendedor']) . '</td><td class="num">' . (int) $vn['ventas'] . '</td><td class="num">' . money($vn['total']) . '</td></tr>';
    $H .= '</tbody></table>';

    $H .= '<h3>Ventas por sucursal</h3><table class="tbl"><thead><tr><th>Sucursal</th><th class="num">Ventas</th><th class="num">Total</th></tr></thead><tbody>';
    foreach ($porSucursal as $s) $H .= '<tr><td>' . htmlspecialchars($s['sucursal']) . '</td><td class="num">' . (int) $s['ventas'] . '</td><td class="num">' . money($s['total']) . '</td></tr>';
    $H .= '</tbody></table>';

    pdf_render($H, 'reporte_gerencial_' . $desde . '_a_' . $hasta, 'portrait');
}

$pdfUrl = '?' . http_build_query(array_merge($_GET, ['export' => 'pdf']));
$acciones = '<a href="' . e($pdfUrl) . '" target="_blank" class="btn btn-ghost no-print">' . icon('download', 'w-4 h-4') . ' PDF</a>'
    . '<button type="button" onclick="window.print()" class="btn btn-ghost no-print">' . icon('print', 'w-4 h-4') . ' Imprimir</button>';
layout_start('Reportes Gerenciales', 'Análisis de ventas y resultados · ' . fechaCorta($desde) . ' al ' . fechaCorta($hasta), $acciones);
?>

<style>
@media print {
  .no-print { display: none !important; }
  body { background: #fff !important; }
  aside, nav, header, footer, .lg\:pl-\[260px\] > header { display: none !important; }
  .lg\:pl-\[260px\] { padding-left: 0 !important; }
  .card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; break-inside: avoid; }
  main { padding: 0 !important; }
  .print-grid-break { break-inside: avoid; }
  a[href]:after { content: ''; }
}
.print-only { display: none; }
@media print { .print-only { display: block; } }
</style>

<!-- Encabezado de impresión -->
<div class="print-only mb-4">
  <h2 style="font-size:18px;font-weight:800;color:#0f172a;"><?= e(setting('nombre', APP_NAME)) ?> — Reportes Gerenciales</h2>
  <p style="font-size:12px;color:#475569;">Periodo: <?= fechaCorta($desde) ?> al <?= fechaCorta($hasta) ?> ·
     <?= $esTodas ? 'Todas las sucursales' : e(current_user()['sucursal_nombre'] ?? '') ?></p>
</div>

<!-- Filtro de periodo -->
<form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3 no-print">
  <div>
    <label class="label">Desde</label>
    <input type="date" name="desde" value="<?= e($desde) ?>" class="input w-auto">
  </div>
  <div>
    <label class="label">Hasta</label>
    <input type="date" name="hasta" value="<?= e($hasta) ?>" class="input w-auto">
  </div>
  <button type="submit" class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
  <a href="<?= e(url('modules/finanzas/reportes.php')) ?>" class="btn btn-ghost">Mes actual</a>
</form>

<!-- a) Estado de resultados -->
<div class="card p-5 mb-5 print-grid-break">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h3 class="font-bold text-slate-800">Estado de resultados resumido</h3>
      <p class="text-sm text-slate-400"><?= $nVentas ?> venta(s) completada(s) en el periodo</p>
    </div>
    <span class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><?= icon('chart', 'w-5 h-5') ?></span>
  </div>
  <div class="divide-y divide-slate-100">
    <div class="flex items-center justify-between py-2.5">
      <span class="text-slate-600">Ingresos por ventas</span>
      <span class="font-semibold text-slate-800"><?= money($ingresosVentas) ?></span>
    </div>
    <div class="flex items-center justify-between py-2.5">
      <span class="text-slate-600">(−) Costo de ventas</span>
      <span class="font-semibold text-rose-600"><?= money($costoVentas) ?></span>
    </div>
    <div class="flex items-center justify-between py-2.5 bg-slate-50/60 -mx-5 px-5">
      <span class="font-semibold text-slate-700">Utilidad bruta</span>
      <span class="font-bold <?= $utilidadBruta >= 0 ? 'text-slate-800' : 'text-rose-600' ?>"><?= money($utilidadBruta) ?></span>
    </div>
    <div class="flex items-center justify-between py-2.5">
      <span class="text-slate-600">(−) Otros gastos</span>
      <span class="font-semibold text-rose-600"><?= money($otrosGastos) ?></span>
    </div>
    <div class="flex items-center justify-between py-3 bg-blue-50/60 -mx-5 px-5 mt-1">
      <span class="font-bold text-slate-800">Utilidad neta</span>
      <span class="text-lg font-extrabold <?= $utilidadNeta >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= money($utilidadNeta) ?></span>
    </div>
  </div>
</div>

<!-- b) Ventas por día -->
<div class="card p-5 mb-5 print-grid-break">
  <div class="flex items-center justify-between mb-5">
    <div>
      <h3 class="font-bold text-slate-800">Ventas por día</h3>
      <p class="text-sm text-slate-400">Ingresos diarios del periodo</p>
    </div>
    <span class="badge badge-blue"><?= icon('trending', 'w-3.5 h-3.5') ?> <?= money($totalSerieDia) ?></span>
  </div>
  <?php if (!$serieDia): ?>
    <p class="text-sm text-slate-400 py-10 text-center">Sin ventas en este periodo.</p>
  <?php else: ?>
    <div class="overflow-x-auto"><?= barChart($serieDia, 'bg-gradient-to-t from-blue-500 to-blue-400') ?></div>
  <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
  <!-- c) Top 10 productos -->
  <div class="card overflow-hidden print-grid-break">
    <div class="p-5 pb-3"><h3 class="font-bold text-slate-800">Top 10 productos por unidades</h3>
      <p class="text-sm text-slate-400">Más vendidos en el periodo</p></div>
    <?php if (!$topProductos): ?>
      <p class="text-sm text-slate-400 py-10 text-center">Sin ventas en este periodo.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th class="text-center w-10">#</th><th>Producto</th><th>Categoría</th><th class="text-right">Unidades</th><th class="text-right">Total</th></tr></thead>
          <tbody>
            <?php foreach ($topProductos as $i => $p): ?>
              <tr>
                <td class="text-center text-slate-400 font-semibold"><?= $i + 1 ?></td>
                <td class="font-semibold text-slate-700"><?= e($p['nombre']) ?></td>
                <td class="text-slate-500"><?= e($p['categoria'] ?: '—') ?></td>
                <td class="text-right font-semibold text-slate-700"><?= qty($p['unidades']) ?></td>
                <td class="text-right text-slate-600"><?= money($p['total']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- d) Ventas por categoría -->
  <div class="card p-5 print-grid-break">
    <h3 class="font-bold text-slate-800 mb-1">Ventas por categoría</h3>
    <p class="text-sm text-slate-400 mb-4">Participación sobre el total</p>
    <?php if (!$porCategoria): ?>
      <p class="text-sm text-slate-400 py-10 text-center">Sin ventas en este periodo.</p>
    <?php else: foreach ($porCategoria as $pc):
        $pctc = round(($pc['total'] / $totalCat) * 100, 1);
        $col = $catColors[$pc['color']] ?? '#64748b'; ?>
      <div class="mb-3.5">
        <div class="flex items-center justify-between text-sm mb-1.5">
          <span class="font-medium text-slate-600"><?= e($pc['categoria']) ?></span>
          <span class="text-slate-400 font-medium"><?= money($pc['total']) ?> · <?= $pctc ?>%</span>
        </div>
        <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full" style="width:<?= max($pctc, 1) ?>%;background:<?= $col ?>"></div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
  <!-- f) Ventas por método de pago -->
  <div class="card overflow-hidden print-grid-break">
    <div class="p-5 pb-3"><h3 class="font-bold text-slate-800">Ventas por método de pago</h3>
      <p class="text-sm text-slate-400">Distribución de cobros</p></div>
    <?php if (!$porMetodo): ?>
      <p class="text-sm text-slate-400 py-10 text-center">Sin pagos registrados en este periodo.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>Método</th><th class="text-center">Ventas</th><th class="text-right">Monto</th><th class="text-right">%</th></tr></thead>
          <tbody>
            <?php foreach ($porMetodo as $m): $pctm = round(($m['total'] / $totalMetodo) * 100, 1); ?>
              <tr>
                <td class="font-semibold text-slate-700"><?= e($m['metodo']) ?></td>
                <td class="text-center text-slate-500"><?= (int) $m['ventas'] ?></td>
                <td class="text-right font-semibold text-slate-700"><?= money($m['total']) ?></td>
                <td class="text-right text-slate-400"><?= $pctm ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- g) Ventas por vendedor -->
  <div class="card overflow-hidden print-grid-break">
    <div class="p-5 pb-3"><h3 class="font-bold text-slate-800">Ventas por vendedor</h3>
      <p class="text-sm text-slate-400">Desempeño del equipo</p></div>
    <?php if (!$porVendedor): ?>
      <p class="text-sm text-slate-400 py-10 text-center">Sin ventas en este periodo.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>Vendedor</th><th class="text-center">Ventas</th><th class="text-right">Total</th></tr></thead>
          <tbody>
            <?php foreach ($porVendedor as $vn): ?>
              <tr>
                <td class="flex items-center gap-2.5">
                  <?= avatar($vn['vendedor'], 'w-8 h-8') ?>
                  <span class="font-semibold text-slate-700"><?= e($vn['vendedor']) ?></span>
                </td>
                <td class="text-center text-slate-500"><?= (int) $vn['ventas'] ?></td>
                <td class="text-right font-semibold text-slate-700"><?= money($vn['total']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- e) Ventas por sucursal -->
<div class="card overflow-hidden mb-2 print-grid-break">
  <div class="p-5 pb-3 flex items-center justify-between">
    <div>
      <h3 class="font-bold text-slate-800">Ventas por sucursal</h3>
      <p class="text-sm text-slate-400"><?= $esTodas ? 'Comparativo entre todas las sucursales' : 'Sucursal activa' ?></p>
    </div>
    <span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center"><?= icon('store', 'w-5 h-5') ?></span>
  </div>
  <?php if (!$porSucursal): ?>
    <p class="text-sm text-slate-400 py-10 text-center">Sin ventas en este periodo.</p>
  <?php else:
    $totalSuc = array_sum(array_column($porSucursal, 'total')) ?: 1; ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Sucursal</th><th class="text-center"># Ventas</th><th class="text-right">Total</th><th class="text-right">%</th></tr></thead>
        <tbody>
          <?php foreach ($porSucursal as $s): $pcts = round(($s['total'] / $totalSuc) * 100, 1); ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($s['sucursal']) ?></td>
              <td class="text-center text-slate-500"><?= (int) $s['ventas'] ?></td>
              <td class="text-right font-bold text-slate-800"><?= money($s['total']) ?></td>
              <td class="text-right text-slate-400"><?= $pcts ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-slate-50/60 font-bold">
            <td class="px-4 py-3 border-t border-slate-200 text-slate-700">Total</td>
            <td class="px-4 py-3 border-t border-slate-200 text-center text-slate-700"><?= array_sum(array_column($porSucursal, 'ventas')) ?></td>
            <td class="px-4 py-3 border-t border-slate-200 text-right text-slate-800"><?= money(array_sum(array_column($porSucursal, 'total'))) ?></td>
            <td class="px-4 py-3 border-t border-slate-200 text-right text-slate-400">100%</td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
