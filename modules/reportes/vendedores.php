<?php
/** Desempeño del equipo de ventas: venta, margen, ticket, descuentos y meta. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.operacion');

$p = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('v.sucursal_id');
$pv = array_merge([$p['ini'], $p['fin']], $scopeP);

$vendedores = qAll(
    "SELECT u.id, CONCAT(u.nombre,' ',u.apellido) AS vendedor, u.comision_pct, r.nombre AS rol,
            su.nombre AS sucursal,
            COUNT(v.id) AS facturas,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS ingresos,
            COALESCE(SUM(v.costo_total),0) AS costo,
            COALESCE(SUM(v.descuento),0) AS descuentos,
            COALESCE(SUM(v.itbis),0) AS itbis,
            COUNT(DISTINCT v.cliente_id) AS clientes,
            COUNT(DISTINCT DATE(v.fecha)) AS dias_activos
       FROM ventas v
       JOIN usuarios u   ON u.id = v.usuario_id
       LEFT JOIN roles r ON r.id = u.rol_id
       LEFT JOIN sucursales su ON su.id = u.sucursal_id
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY v.usuario_id ORDER BY ingresos DESC",
    $pv
);

$prev = [];
foreach (qAll(
    "SELECT v.usuario_id, COALESCE(SUM(v.subtotal - v.descuento),0) ingresos
       FROM ventas v WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope GROUP BY v.usuario_id",
    array_merge([$p['prev_ini'], $p['prev_fin']], $scopeP)
) as $r) $prev[(int) $r['usuario_id']] = (float) $r['ingresos'];

// Devoluciones atribuidas al vendedor de la venta original.
$devol = [];
foreach (qAll(
    "SELECT v.usuario_id, COALESCE(SUM(d.subtotal),0) t, COUNT(*) n
       FROM devoluciones d JOIN ventas v ON v.id = d.venta_id
      WHERE d.created_at BETWEEN ? AND ? AND $scope GROUP BY v.usuario_id",
    $pv
) as $r) $devol[(int) $r['usuario_id']] = $r;

// Metas activas por vendedor.
$metas = [];
foreach (qAll(
    "SELECT * FROM metas_ventas WHERE usuario_id IS NOT NULL AND estado = 'activa'
       AND periodo_inicio <= ? AND periodo_fin >= ?",
    [$p['hasta'], $p['desde']]
) as $m) $metas[(int) $m['usuario_id']] = $m;

// Comisiones registradas del periodo.
$comisiones = [];
foreach (qAll(
    "SELECT usuario_id, COALESCE(SUM(monto),0) t, estado FROM comisiones
      WHERE periodo_desde >= ? AND periodo_hasta <= ? GROUP BY usuario_id",
    [$p['desde'], $p['hasta']]
) as $r) $comisiones[(int) $r['usuario_id']] = (float) $r['t'];

$totIngresos = array_sum(array_column($vendedores, 'ingresos'));
$totFacturas = array_sum(array_column($vendedores, 'facturas'));
$totCosto    = array_sum(array_column($vendedores, 'costo'));

/* ---------- Canal por vendedor ---------- */
$porCanal = qAll(
    "SELECT v.usuario_id, COALESCE(NULLIF(v.canal_venta,''),'Sin especificar') canal,
            COALESCE(SUM(v.subtotal - v.descuento),0) ingresos
       FROM ventas v WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY v.usuario_id, canal",
    $pv
);
$canalPorVendedor = [];
foreach ($porCanal as $c) $canalPorVendedor[(int) $c['usuario_id']][$c['canal']] = (float) $c['ingresos'];

if (export_solicitado()) {
    $filas = [];
    foreach ($vendedores as $v) {
        $ut = (float) $v['ingresos'] - (float) $v['costo'];
        $meta = $metas[(int) $v['id']] ?? null;
        $pr = $meta ? metaProgreso($meta) : null;
        $filas[] = [$v['vendedor'], $v['rol'] ?? '', $v['sucursal'] ?? '', (int) $v['facturas'],
            money($v['ingresos'], false), money($ut, false),
            number_format((float) $v['ingresos'] > 0 ? $ut / (float) $v['ingresos'] * 100 : 0, 2),
            money((float) $v['facturas'] > 0 ? (float) $v['ingresos'] / (int) $v['facturas'] : 0, false),
            money($v['descuentos'], false), (int) $v['clientes'], (int) $v['dias_activos'],
            $meta ? money($meta['monto_objetivo'], false) : '', $pr ? number_format($pr['pct'], 1) : '',
            money($comisiones[(int) $v['id']] ?? 0, false)];
    }
    export_tabla('desempeno_vendedores_' . $p['desde'] . '_' . $p['hasta'],
        ['Vendedor', 'Rol', 'Sucursal', 'Facturas', 'Ingresos', 'Utilidad', 'Margen %', 'Ticket promedio',
         'Descuentos', 'Clientes', 'Días activos', 'Meta', '% meta', 'Comisiones'],
        $filas, 'Desempeño del equipo');
}

layout_start('Desempeño del equipo', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Desempeño del equipo', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Vendedores activos', 'valor' => number_format(count($vendedores)), 'icono' => 'users', 'color' => 'blue',
     'nota' => 'Con al menos una venta en el periodo'],
    ['label' => 'Venta promedio por vendedor', 'valor' => money(count($vendedores) > 0 ? $totIngresos / count($vendedores) : 0),
     'icono' => 'cash', 'color' => 'emerald', 'nota' => 'Ingresos netos del equipo: ' . money($totIngresos)],
    ['label' => 'Ticket promedio del equipo', 'valor' => money($totFacturas > 0 ? $totIngresos / $totFacturas : 0),
     'icono' => 'receipt', 'color' => 'violet', 'nota' => number_format($totFacturas) . ' factura(s)'],
    ['label' => 'Descuentos otorgados', 'valor' => money(array_sum(array_column($vendedores, 'descuentos'))),
     'icono' => 'percent', 'color' => 'amber', 'nota' => 'Utilidad cedida por el equipo'],
]) ?>

<!-- Ranking visual -->
<?= rep_seccion('Ranking de ventas', 'Ingresos netos generados en el periodo', 'trending', 'blue') ?>
  <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
    <?php if (!$vendedores): ?>
      <?= empty_state('Sin actividad del equipo', 'Nadie registró ventas en el periodo seleccionado.', 'users') ?>
    <?php else: foreach ($vendedores as $i => $v): ?>
      <?= rep_barra($v['vendedor'], money($v['ingresos'], false),
                    $totIngresos > 0 ? (float) $v['ingresos'] / $totIngresos * 100 : 0, rep_color($i),
                    (int) $v['facturas'] . ' facturas · ' . (int) $v['clientes'] . ' clientes') ?>
    <?php endforeach; endif; ?>
  </div>
<?= rep_fin() ?>

<!-- Tabla completa -->
<?= rep_seccion('Indicadores por vendedor', 'Todo lo que hay que mirar para evaluar al equipo', 'users', 'emerald') ?>
  <?php
  $filas = [];
  foreach ($vendedores as $i => $v) {
      $uid = (int) $v['id'];
      $ing = (float) $v['ingresos'];
      $ut  = $ing - (float) $v['costo'];
      $mg  = $ing > 0 ? $ut / $ing * 100 : 0;
      $ticket = (int) $v['facturas'] > 0 ? $ing / (int) $v['facturas'] : 0;
      $d = rep_delta($ing, $prev[$uid] ?? 0);
      $meta = $metas[$uid] ?? null;
      $pr = $meta ? metaProgreso($meta) : null;
      $dev = $devol[$uid] ?? null;
      $pctDesc = $ing > 0 ? (float) $v['descuentos'] / ($ing + (float) $v['descuentos']) * 100 : 0;

      $filas[] = [
          '<div class="flex items-center gap-2.5">' . avatar($v['vendedor'], 'w-9 h-9')
            . '<div class="min-w-0"><span class="font-semibold text-slate-700 block truncate">' . e($v['vendedor']) . '</span>'
            . '<span class="text-[11px] text-slate-400">' . e($v['rol'] ?: '—') . ($v['sucursal'] ? ' · ' . e($v['sucursal']) : '') . '</span></div></div>',
          '<span class="text-slate-500 tabular-nums">' . number_format((int) $v['facturas']) . '</span>'
            . '<span class="block text-[10.5px] text-slate-400">' . (int) $v['dias_activos'] . ' día(s)</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($ing) . '</span>',
          $d === null ? '<span class="text-slate-300">—</span>'
            : '<span class="badge ' . ($d >= 0 ? 'stat-trend-up' : 'stat-trend-down') . '">'
              . icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') . ' ' . number_format(abs($d), 1) . '%</span>',
          '<span class="' . ($ut >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' font-semibold tabular-nums">' . money($ut)
            . '<span class="block text-[10.5px] font-medium text-slate-400">' . number_format($mg, 1) . '%</span></span>',
          '<span class="text-slate-600 tabular-nums">' . money($ticket) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . number_format((int) $v['clientes']) . '</span>',
          '<span class="' . ($pctDesc > 10 ? 'text-rose-600 font-semibold' : 'text-slate-500') . ' tabular-nums">' . money($v['descuentos'], false)
            . '<span class="block text-[10.5px] text-slate-400">' . number_format($pctDesc, 1) . '%</span></span>',
          $dev
            ? '<span class="text-rose-600 tabular-nums">' . money($dev['t'], false) . '<span class="block text-[10.5px] text-slate-400">' . (int) $dev['n'] . ' dev.</span></span>'
            : '<span class="text-slate-300">—</span>',
          $pr
            ? '<div class="min-w-[110px]"><div class="flex items-center justify-between text-[11px] mb-1">'
              . '<span class="font-semibold text-slate-600">' . number_format($pr['pct'], 0) . '%</span>'
              . '<span class="text-slate-400">' . money($meta['monto_objetivo'], false) . '</span></div>'
              . '<div class="h-1.5 rounded-full bg-slate-100 overflow-hidden"><div class="h-full rounded-full" style="width:'
              . max($pr['pct'], 1) . '%;background:' . rep_color_nombre(metaColor($pr['pct'])) . '"></div></div></div>'
            : '<span class="text-slate-300 text-xs">Sin meta</span>',
          '<span class="text-slate-600 tabular-nums">' . (isset($comisiones[$uid]) ? money($comisiones[$uid]) : '<span class="text-slate-300">—</span>') . '</span>',
      ];
  }
  echo rep_tabla(
      ['Vendedor', ['Facturas', 'center'], ['Ingresos', 'right'], ['Vs. anterior', 'center'], ['Utilidad', 'right'],
       ['Ticket', 'right'], ['Clientes', 'center'], ['Descuentos', 'right'], ['Devoluciones', 'right'], ['Meta', 'center'], ['Comisión', 'right']],
      $filas,
      ['total' => $filas ? ['Total del equipo', number_format($totFacturas), money($totIngresos), '',
          money($totIngresos - $totCosto), money($totFacturas > 0 ? $totIngresos / $totFacturas : 0), '',
          money(array_sum(array_column($vendedores, 'descuentos'))), '', '',
          money(array_sum($comisiones))] : null,
       'vacio_titulo' => 'Sin actividad del equipo',
       'vacio' => 'Nadie registró ventas en el periodo seleccionado.']
  );
  ?>
<?= rep_fin() ?>

<!-- Canal por vendedor -->
<?php if ($canalPorVendedor): ?>
<?= rep_seccion('Canal de captación por vendedor', 'De dónde saca cada quien sus ventas', 'megaphone', 'violet') ?>
  <?php
  $canales = [];
  foreach ($canalPorVendedor as $mapa) foreach (array_keys($mapa) as $c) $canales[$c] = true;
  $canales = array_keys($canales);
  sort($canales);
  $headers = ['Vendedor'];
  foreach ($canales as $c) $headers[] = [$c, 'right'];
  $headers[] = ['Total', 'right'];

  $filas = [];
  foreach ($vendedores as $v) {
      $uid = (int) $v['id'];
      $fila = ['<span class="font-semibold text-slate-700">' . e($v['vendedor']) . '</span>'];
      $suma = 0.0;
      foreach ($canales as $c) {
          $val = $canalPorVendedor[$uid][$c] ?? 0;
          $suma += $val;
          $fila[] = $val > 0
              ? '<span class="text-slate-600 tabular-nums">' . money($val, false) . '</span>'
              : '<span class="text-slate-300">—</span>';
      }
      $fila[] = '<span class="font-bold text-slate-800 tabular-nums">' . money($suma, false) . '</span>';
      $filas[] = $fila;
  }
  echo rep_tabla($headers, $filas);
  ?>
<?= rep_fin() ?>
<?php endif; ?>

<?php layout_end(); ?>
