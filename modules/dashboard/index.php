<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
require_once dirname(__DIR__, 2) . '/includes/charts.php';

$sid = current_sucursal_id();
$scopeV = $sid === null ? '1=1' : 'v.sucursal_id = ' . (int) $sid;   // ventas
$scopeS = $sid === null ? '1=1' : 's.sucursal_id = ' . (int) $sid;   // stock

$hoy        = date('Y-m-d');
$inicioMes  = date('Y-m-01');
$mesPrevIni = date('Y-m-01', strtotime('first day of last month'));
$mesPrevFin = date('Y-m-t', strtotime('last day of last month'));

/*
 * Los rangos van sobre la columna `fecha` tal cual, NO con DATE(v.fecha).
 * `ventas.fecha` es DATETIME y tiene índice: envolverla en DATE() lo anula y
 * obliga a recorrer la tabla entera. Con 60.000 ventas medimos 59.558 filas
 * escaneadas por consulta frente a 85 usando el rango. El dashboard hacía eso
 * 19 veces y tardaba más de 5 segundos en abrir.
 */
$mesIni  = $inicioMes . ' 00:00:00';
$mesFin  = date('Y-m-t') . ' 23:59:59';
$prevIni = $mesPrevIni . ' 00:00:00';
$prevFin = $mesPrevFin . ' 23:59:59';
$hoyIni  = $hoy . ' 00:00:00';
$hoyFin  = $hoy . ' 23:59:59';

// Los KPIs del mes salen de UNA sola pasada en vez de tres consultas iguales.
$kpiMes = qOne(
    "SELECT COALESCE(SUM(total),0) ventas,
            COALESCE(SUM(subtotal - costo_total - descuento),0) ganancia,
            COALESCE(AVG(total),0) ticket
       FROM ventas v
      WHERE v.estado='completada' AND $scopeV AND v.fecha BETWEEN '$mesIni' AND '$mesFin'"
) ?: [];
$ventasMes   = (float) ($kpiMes['ventas'] ?? 0);
$gananciaMes = (float) ($kpiMes['ganancia'] ?? 0);
$ticketProm  = (float) ($kpiMes['ticket'] ?? 0);

$kpiHoy = qOne(
    "SELECT COALESCE(SUM(total),0) total, COUNT(*) n
       FROM ventas v
      WHERE v.estado='completada' AND $scopeV AND v.fecha BETWEEN '$hoyIni' AND '$hoyFin'"
) ?: [];
$ventasHoyTot = (float) ($kpiHoy['total'] ?? 0);
$ventasHoyN   = (int) ($kpiHoy['n'] ?? 0);

$ventasPrev  = (float) qVal("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE v.estado='completada' AND $scopeV AND v.fecha BETWEEN '$prevIni' AND '$prevFin'");
$valorInv    = (float) qVal("SELECT COALESCE(SUM(s.cantidad * p.precio_compra),0) FROM inventario_stock s JOIN productos p ON p.id=s.producto_id WHERE p.activo=1 AND $scopeS");

$nProductos  = (int) qVal("SELECT COUNT(*) FROM productos WHERE activo=1");
$nStockBajo  = (int) qVal("SELECT COUNT(*) FROM inventario_stock s JOIN productos p ON p.id=s.producto_id WHERE p.activo=1 AND s.cantidad<=p.stock_minimo AND $scopeS");
$nEmpleados  = (int) qVal("SELECT COUNT(*) FROM empleados WHERE estado='activo'" . ($sid ? " AND (sucursal_id=$sid OR sucursal_id IS NULL)" : ''));
$nClientes   = (int) qVal("SELECT COUNT(*) FROM clientes WHERE activo=1");

$trendVentas = $ventasPrev > 0 ? round((($ventasMes - $ventasPrev) / $ventasPrev) * 100, 1) : ($ventasMes > 0 ? 100 : 0);

/*
 * Serie de 14 días: una consulta agrupada, no una por día. El bucle anterior
 * lanzaba 14 recorridos completos de la tabla de ventas.
 */
$desde14 = date('Y-m-d', strtotime('-13 days'));
$porDia = [];
foreach (qAll(
    "SELECT DATE(v.fecha) d, COALESCE(SUM(v.total),0) t
       FROM ventas v
      WHERE v.estado='completada' AND $scopeV AND v.fecha BETWEEN '$desde14 00:00:00' AND '$hoyFin'
      GROUP BY DATE(v.fecha)"
) as $r) $porDia[$r['d']] = (float) $r['t'];

$serie = []; $serieVals = [];
for ($i = 13; $i >= 0; $i--) {
    $f = date('Y-m-d', strtotime("-$i days"));
    $t = $porDia[$f] ?? 0.0;
    $serie[] = ['label' => date('d/m', strtotime($f)), 'value' => $t];
    $serieVals[] = $t;
}

// Top productos del mes
$topProductos = qAll(
    "SELECT p.nombre, c.nombre AS categoria, c.color, SUM(vd.cantidad) AS unidades, SUM(vd.subtotal) AS total
     FROM venta_detalles vd
     JOIN ventas v ON v.id=vd.venta_id
     JOIN productos p ON p.id=vd.producto_id
     LEFT JOIN categorias c ON c.id=p.categoria_id
     WHERE v.estado='completada' AND $scopeV AND v.fecha BETWEEN '$mesIni' AND '$mesFin'
     GROUP BY p.id ORDER BY unidades DESC LIMIT 5"
);

// Ventas por categoría (mes)
$porCategoria = qAll(
    "SELECT COALESCE(c.nombre,'Sin categoría') AS categoria, COALESCE(c.color,'slate') AS color, SUM(vd.subtotal) AS total
     FROM venta_detalles vd
     JOIN ventas v ON v.id=vd.venta_id
     JOIN productos p ON p.id=vd.producto_id
     LEFT JOIN categorias c ON c.id=p.categoria_id
     WHERE v.estado='completada' AND $scopeV AND v.fecha BETWEEN '$mesIni' AND '$mesFin'
     GROUP BY c.id ORDER BY total DESC LIMIT 6"
);
$totalCat = array_sum(array_column($porCategoria, 'total')) ?: 1;

// Ventas recientes
$recientes = qAll(
    "SELECT v.numero, v.total, v.fecha, v.estado, su.nombre AS sucursal, cl.nombre AS cliente, u.nombre AS vendedor
     FROM ventas v
     JOIN sucursales su ON su.id=v.sucursal_id
     LEFT JOIN clientes cl ON cl.id=v.cliente_id
     JOIN usuarios u ON u.id=v.usuario_id
     WHERE $scopeV ORDER BY v.fecha DESC LIMIT 7"
);

// Stock bajo
$stockBajo = qAll(
    "SELECT p.nombre, p.stock_minimo, s.cantidad, su.nombre AS sucursal, c.color
     FROM inventario_stock s
     JOIN productos p ON p.id=s.producto_id
     JOIN sucursales su ON su.id=s.sucursal_id
     LEFT JOIN categorias c ON c.id=p.categoria_id
     WHERE p.activo=1 AND s.cantidad<=p.stock_minimo AND $scopeS
     ORDER BY s.cantidad ASC LIMIT 6"
);

$catColors = ['blue'=>'#2563eb','emerald'=>'#10b981','amber'=>'#f59e0b','rose'=>'#f43f5e','indigo'=>'#6366f1','cyan'=>'#06b6d4','sky'=>'#0ea5e9','pink'=>'#ec4899','slate'=>'#64748b','violet'=>'#8b5cf6'];

// Alertas críticas del centro de notificaciones (lo que no puede esperar).
$alertas = array_slice(array_filter(
    notif_listar(['limit' => 20]),
    fn($n) => in_array($n['prioridad'], ['critica', 'alta'], true)
), 0, 4);

$acciones = '';
if (can('pos.vender')) $acciones .= '<a href="' . url('modules/pos/index.php') . '" class="btn btn-primary">' . icon('cart', 'w-4 h-4') . ' Nueva venta</a>';
if (can('reportes.ver')) $acciones .= '<a href="' . url('modules/reportes/index.php') . '" class="btn btn-ghost">' . icon('chart', 'w-4 h-4') . ' Centro de reportes</a>';

layout_start('Dashboard', 'Resumen general de ' . e(current_user()['sucursal_nombre'] ?? 'tu negocio') . ' · ' . fechaLarga($hoy), $acciones);
?>

<?php if ($alertas): ?>
<!-- Alertas que requieren acción -->
<div class="card p-4 mb-5 border-l-4 border-l-rose-500">
  <div class="flex items-center justify-between gap-3 mb-3">
    <h3 class="font-bold text-slate-800 flex items-center gap-2">
      <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center"><?= icon('alert', 'w-4 h-4') ?></span>
      Requiere tu atención
    </h3>
    <a href="<?= e(url('modules/notificaciones/index.php')) ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Ver todas →</a>
  </div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
    <?php foreach ($alertas as $a):
      [$icoCls, $barra] = notif_estilo($a['color']);
      $href = $a['url']
          ? url('modules/notificaciones/ir.php?id=' . (int) $a['id'] . '&_t=' . urlencode(csrf_token()))
          : url('modules/notificaciones/index.php');
    ?>
      <a href="<?= e($href) ?>" class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 hover:border-blue-200 hover:bg-slate-50/70 transition group">
        <span class="w-9 h-9 rounded-xl <?= $icoCls ?> flex items-center justify-center shrink-0"><?= icon($a['icono'], 'w-4 h-4') ?></span>
        <span class="min-w-0 flex-1">
          <span class="block text-[13.5px] font-semibold text-slate-800 leading-snug group-hover:text-blue-700 transition-colors"><?= e($a['titulo']) ?></span>
          <?php if ($a['mensaje']): ?>
            <span class="block text-xs text-slate-500 mt-0.5 line-clamp-2"><?= e($a['mensaje']) ?></span>
          <?php endif; ?>
        </span>
        <span class="text-slate-300 group-hover:text-blue-600 shrink-0 mt-1"><?= icon('arrow-right', 'w-4 h-4') ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- KPIs principales -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
  <?php
  $kpis = [
    ['Ventas del mes', money($ventasMes), 'cash', 'blue', $trendVentas, sparkline($serieVals, '#2563eb')],
    ['Ventas de hoy', money($ventasHoyTot), 'cart', 'emerald', null, '<p class="text-sm text-slate-400 mt-1">' . $ventasHoyN . ' transacciones</p>'],
    ['Ganancia del mes', money($gananciaMes), 'trending', 'violet', null, '<p class="text-sm text-slate-400 mt-1">Ticket prom. ' . money($ticketProm) . '</p>'],
    ['Valor inventario', money($valorInv), 'box', 'amber', null, '<p class="text-sm text-slate-400 mt-1">' . $nProductos . ' productos activos</p>'],
  ];
  $ic = ['blue'=>'bg-blue-50 text-blue-600','emerald'=>'bg-emerald-50 text-emerald-600','violet'=>'bg-violet-50 text-violet-600','amber'=>'bg-amber-50 text-amber-600'];
  foreach ($kpis as $k): ?>
    <div class="card p-5">
      <div class="flex items-start justify-between">
        <div class="w-11 h-11 rounded-xl <?= $ic[$k[3]] ?> flex items-center justify-center"><?= icon($k[2], 'w-5 h-5') ?></div>
        <?php if ($k[4] !== null): ?>
          <span class="badge <?= $k[4] >= 0 ? 'stat-trend-up' : 'stat-trend-down' ?>"><?= icon($k[4] >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') ?> <?= abs($k[4]) ?>%</span>
        <?php endif; ?>
      </div>
      <p class="text-sm text-slate-500 mt-4"><?= e($k[0]) ?></p>
      <p class="text-2xl font-extrabold text-slate-800 mt-0.5"><?= $k[1] ?></p>
      <div class="mt-2"><?= $k[5] ?></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Stats secundarios -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <?php
  $mini = [
    ['Productos', $nProductos, 'box', 'text-blue-600'],
    ['Stock bajo', $nStockBajo, 'alert', $nStockBajo > 0 ? 'text-rose-600' : 'text-slate-600'],
    ['Empleados', $nEmpleados, 'users', 'text-emerald-600'],
    ['Clientes', $nClientes, 'user', 'text-indigo-600'],
  ];
  foreach ($mini as $m): ?>
    <div class="card p-4 flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-slate-50 <?= $m[3] ?> flex items-center justify-center shrink-0"><?= icon($m[2], 'w-5 h-5') ?></div>
      <div><p class="text-xl font-extrabold text-slate-800"><?= number_format($m[1]) ?></p><p class="text-xs text-slate-400 font-medium"><?= e($m[0]) ?></p></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Gráfico + categorías -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
  <div class="card p-5 lg:col-span-2 h-full flex flex-col">
    <div class="flex items-center justify-between mb-5">
      <div><h3 class="font-bold text-slate-800">Ventas de los últimos 14 días</h3><p class="text-sm text-slate-400">Ingresos diarios</p></div>
      <span class="badge badge-blue"><?= icon('trending', 'w-3.5 h-3.5') ?> <?= money(array_sum($serieVals)) ?></span>
    </div>
    <?= barChart($serie, 'bg-gradient-to-t from-blue-500 to-blue-400') ?>
  </div>

  <div class="card p-5 h-full flex flex-col">
    <h3 class="font-bold text-slate-800 mb-1">Ventas por categoría</h3>
    <p class="text-sm text-slate-400 mb-4">Este mes</p>
    <div class="flex-1 flex flex-col justify-center">
    <?php if (!$porCategoria): ?>
      <?= empty_state('Sin ventas este mes', 'Aquí verás qué categorías mueven el negocio.', 'tag') ?>
    <?php else: foreach ($porCategoria as $pc):
        $pct = round(($pc['total'] / $totalCat) * 100); $col = $catColors[$pc['color']] ?? '#64748b'; ?>
      <div class="mb-3.5">
        <div class="flex items-center justify-between text-sm mb-1.5">
          <span class="font-medium text-slate-600"><?= e($pc['categoria']) ?></span>
          <span class="text-slate-400 font-medium"><?= $pct ?>%</span>
        </div>
        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
        </div>
      </div>
    <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- Recientes + stock bajo -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="card lg:col-span-2 overflow-hidden h-full flex flex-col">
    <div class="flex items-center justify-between p-5 pb-3">
      <h3 class="font-bold text-slate-800">Ventas recientes</h3>
      <?php if (can('ventas.ver')): ?><a href="<?= e(url('modules/pos/ventas.php')) ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-700">Ver todas →</a><?php endif; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Factura</th><th>Cliente</th><th>Sucursal</th><th>Fecha</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
        <tbody>
          <?php if (!$recientes): ?>
            <tr><td colspan="6"><?= empty_state('Aún no hay ventas', 'Las ventas aparecerán aquí.', 'receipt') ?></td></tr>
          <?php else: foreach ($recientes as $r): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($r['numero']) ?></td>
              <td class="text-slate-600"><?= e($r['cliente'] ?: 'Cliente Genérico') ?></td>
              <td class="text-slate-500"><?= e($r['sucursal']) ?></td>
              <td class="text-slate-500"><?= fechaHora($r['fecha']) ?></td>
              <td class="text-right font-bold text-slate-800"><?= money($r['total']) ?></td>
              <td><?= badgeFor($r['estado']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card overflow-hidden h-full flex flex-col">
    <div class="flex items-center justify-between p-5 pb-3">
      <h3 class="font-bold text-slate-800">Stock bajo</h3>
      <?php if (can('inventario.ver')): ?><a href="<?= e(url('modules/inventario/stock.php')) ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-700">Ver →</a><?php endif; ?>
    </div>
    <div class="px-5 pb-5 space-y-3 flex-1 flex flex-col <?= $stockBajo ? '' : 'justify-center' ?>">
      <?php if (!$stockBajo): ?>
        <div class="flex flex-col items-center text-center py-8">
          <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-500 flex items-center justify-center mb-3"><?= icon('check', 'w-7 h-7') ?></div>
          <p class="text-sm font-semibold text-slate-700">Inventario en orden</p>
          <p class="text-xs text-slate-400 mt-1 max-w-[200px]">Ningún producto está por debajo de su stock mínimo.</p>
        </div>
      <?php else: foreach ($stockBajo as $sb): ?>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center shrink-0"><?= icon('alert', 'w-4 h-4') ?></div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-700 truncate"><?= e($sb['nombre']) ?></p>
            <p class="text-xs text-slate-400"><?= e($sb['sucursal']) ?></p>
          </div>
          <div class="text-right">
            <p class="text-sm font-bold text-rose-600"><?= qty($sb['cantidad']) ?></p>
            <p class="text-[11px] text-slate-400">mín <?= qty($sb['stock_minimo']) ?></p>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php layout_end(); ?>
