<?php
/**
 * Clientes y concentración (ABC / Pareto).
 *
 * Responde la pregunta que quita el sueño: ¿cuánto del negocio depende de unos
 * pocos clientes y quiénes se están enfriando?
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.ejecutivo');

$p = rep_periodo('anio');
[$scope, $scopeP] = rep_scope('v.sucursal_id');
$pv = array_merge([$p['ini'], $p['fin']], $scopeP);

/* ---------- Ranking del periodo ---------- */
$clientes = qAll(
    "SELECT v.cliente_id,
            COALESCE(cl.nombre,'Consumidor final') AS cliente,
            cl.codigo, cl.telefono, cl.tipo, cl.balance, cl.limite_credito,
            COUNT(v.id) AS compras,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS ingresos,
            COALESCE(SUM(v.subtotal - v.descuento - v.costo_total),0) AS utilidad,
            MAX(v.fecha) AS ultima
       FROM ventas v
       LEFT JOIN clientes cl ON cl.id = v.cliente_id
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY v.cliente_id
      ORDER BY ingresos DESC",
    $pv
);

$totalIngresos = array_sum(array_column($clientes, 'ingresos')) ?: 1;
$totalUtilidad = array_sum(array_column($clientes, 'utilidad'));

// Clasificación ABC: A hasta el 80% acumulado, B hasta el 95%, C el resto.
$acum = 0.0;
foreach ($clientes as $i => $c) {
    $acum += (float) $c['ingresos'];
    $pctAcum = $acum / $totalIngresos * 100;
    $clientes[$i]['pct']      = (float) $c['ingresos'] / $totalIngresos * 100;
    $clientes[$i]['pct_acum'] = $pctAcum;
    $clientes[$i]['abc']      = $pctAcum <= 80 ? 'A' : ($pctAcum <= 95 ? 'B' : 'C');
}
$conteoABC = ['A' => 0, 'B' => 0, 'C' => 0];
$montoABC  = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
foreach ($clientes as $c) {
    $conteoABC[$c['abc']]++;
    $montoABC[$c['abc']] += (float) $c['ingresos'];
}

$top1  = $clientes[0]['ingresos'] ?? 0;
$top5  = array_sum(array_column(array_slice($clientes, 0, 5), 'ingresos'));
$top10 = array_sum(array_column(array_slice($clientes, 0, 10), 'ingresos'));

/* ---------- Nuevos vs. recurrentes ---------- */
$nuevos = (int) qVal(
    "SELECT COUNT(*) FROM clientes c
      WHERE c.created_at BETWEEN ? AND ?", [$p['ini'], $p['fin']]
);
$recurrentes = 0;
foreach ($clientes as $c) if ((int) $c['compras'] > 1) $recurrentes++;

/* ---------- Clientes dormidos (compraron antes, no en el periodo) ---------- */
$dormidos = qAll(
    "SELECT cl.id, cl.nombre, cl.telefono, cl.balance,
            MAX(v.fecha) AS ultima,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS historico,
            COUNT(v.id) AS compras
       FROM clientes cl
       JOIN ventas v ON v.cliente_id = cl.id AND v.estado = 'completada' AND $scope
      WHERE cl.activo = 1
      GROUP BY cl.id
     HAVING MAX(v.fecha) < ?
      ORDER BY historico DESC LIMIT 15",
    array_merge($scopeP, [$p['ini']])
);

/* ---------- Exposición al crédito ---------- */
$credito = qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(balance),0) saldo, COALESCE(SUM(limite_credito),0) linea
       FROM clientes WHERE activo = 1 AND balance > 0"
) ?: ['n' => 0, 'saldo' => 0, 'linea' => 0];

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    foreach ($clientes as $i => $c) {
        $filas[] = [
            $i + 1, $c['cliente'], $c['codigo'] ?? '', $c['abc'],
            (int) $c['compras'], money($c['ingresos'], false), money($c['utilidad'], false),
            number_format($c['pct'], 2), number_format($c['pct_acum'], 2),
            fechaCorta($c['ultima']), money($c['balance'] ?? 0, false),
        ];
    }
    export_tabla('clientes_abc_' . $p['desde'] . '_' . $p['hasta'],
        ['#', 'Cliente', 'Código', 'Clase ABC', 'Compras', 'Ingresos', 'Utilidad', '% del total', '% acumulado', 'Última compra', 'Saldo pendiente'],
        $filas, 'Ranking de clientes (ABC)');
}

layout_start('Clientes y concentración', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Clientes y concentración', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Clientes que compraron', 'valor' => number_format(count($clientes)), 'icono' => 'users', 'color' => 'blue',
     'nota' => $recurrentes . ' con más de una compra'],
    ['label' => 'Clientes nuevos', 'valor' => number_format($nuevos), 'icono' => 'user', 'color' => 'emerald',
     'nota' => 'Registrados dentro del periodo'],
    ['label' => 'Concentración top 5', 'valor' => number_format($top5 / $totalIngresos * 100, 1) . '%', 'icono' => 'target',
     'color' => ($top5 / $totalIngresos) > 0.6 ? 'rose' : 'violet',
     'nota' => money($top5) . ' de ' . money($totalIngresos)],
    ['label' => 'Crédito vivo', 'valor' => money($credito['saldo']), 'icono' => 'wallet',
     'color' => (float) $credito['saldo'] > 0 ? 'amber' : 'slate',
     'nota' => (int) $credito['n'] . ' cliente(s) con saldo pendiente'],
], 4) ?>

<?php if ((float) $top5 / $totalIngresos > 0.6 && count($clientes) > 5): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-amber-200 bg-amber-50/50">
    <span class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-4 h-4') ?></span>
    <p class="text-sm text-slate-700"><strong>Riesgo de dependencia.</strong>
      Cinco clientes concentran el <?= number_format($top5 / $totalIngresos * 100, 1) ?>% de la facturación.
      Perder uno solo golpearía el resultado del año: vale la pena diversificar la cartera.</p>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <!-- Clasificación ABC -->
  <div>
    <?= rep_seccion('Clasificación ABC', 'Regla de Pareto sobre la facturación', 'layers', 'violet') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?= donutMulti([
            ['label' => 'A · núcleo del negocio', 'value' => $montoABC['A'], 'color' => '#2563eb'],
            ['label' => 'B · complementarios', 'value' => $montoABC['B'], 'color' => '#f59e0b'],
            ['label' => 'C · marginales', 'value' => $montoABC['C'], 'color' => '#cbd5e1'],
        ], 'Total', money($totalIngresos, false)) ?>
        <div class="grid grid-cols-3 gap-2 mt-5 text-center">
          <?php foreach (['A' => 'blue', 'B' => 'amber', 'C' => 'slate'] as $cls => $col): ?>
            <div class="rounded-xl bg-slate-50 p-3">
              <p class="text-xl font-extrabold text-slate-800"><?= $conteoABC[$cls] ?></p>
              <p class="text-[11px] font-semibold text-slate-500">Clase <?= $cls ?></p>
              <p class="text-[11px] text-slate-400"><?= number_format($totalIngresos > 0 ? $montoABC[$cls] / $totalIngresos * 100 : 0, 0) ?>% venta</p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?= rep_fin() ?>
  </div>

  <!-- Ranking -->
  <div class="lg:col-span-2">
    <?= rep_seccion('Ranking de clientes', 'Ordenados por ingreso neto del periodo', 'users', 'blue') ?>
      <?php
      $filas = [];
      foreach (array_slice($clientes, 0, 40) as $i => $c) {
          $badgeAbc = ['A' => 'blue', 'B' => 'amber', 'C' => 'slate'][$c['abc']];
          $filas[] = [
              '<span class="text-slate-400 font-semibold tabular-nums">' . ($i + 1) . '</span>',
              '<div class="flex items-center gap-2.5">' . avatar($c['cliente'], 'w-8 h-8')
                . '<div class="min-w-0"><span class="font-semibold text-slate-700 block truncate">' . e($c['cliente']) . '</span>'
                . '<span class="text-[11px] text-slate-400">' . e($c['codigo'] ?: '—')
                . ((float) ($c['balance'] ?? 0) > 0 ? ' · <span class="text-amber-600 font-semibold">debe ' . money($c['balance'], false) . '</span>' : '')
                . '</span></div></div>',
              badge($c['abc'], $badgeAbc),
              '<span class="text-slate-500 tabular-nums">' . number_format((int) $c['compras']) . '</span>',
              '<span class="font-bold text-slate-800 tabular-nums">' . money($c['ingresos']) . '</span>',
              '<span class="' . ($c['utilidad'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' font-semibold tabular-nums">' . money($c['utilidad']) . '</span>',
              '<span class="text-slate-400 tabular-nums text-xs">' . number_format($c['pct'], 1) . '%<span class="block text-[10px]">ac. ' . number_format($c['pct_acum'], 1) . '%</span></span>',
              '<span class="text-slate-500 text-xs">' . fechaCorta($c['ultima']) . '</span>',
          ];
      }
      echo rep_tabla(
          [['#', 'center'], 'Cliente', ['ABC', 'center'], ['Compras', 'center'], ['Ingresos', 'right'], ['Utilidad', 'right'], ['Peso', 'right'], ['Última', 'center']],
          $filas,
          ['total' => $filas ? ['', '<strong>Total del periodo</strong>', '', number_format(array_sum(array_column($clientes, 'compras'))),
                               money($totalIngresos), money($totalUtilidad), '100%', ''] : null,
           'vacio' => 'No hubo ventas con clientes en el rango seleccionado.']
      );
      if (count($clientes) > 40) {
          echo '<p class="px-5 pb-4 text-xs text-slate-400">Se muestran los primeros 40 de ' . count($clientes)
             . ' clientes. Descarga el Excel para ver la lista completa.</p>';
      }
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Clientes dormidos -->
<?= rep_seccion('Clientes dormidos', 'Compraron antes pero no en este periodo. Buena lista para llamar.', 'clock', 'amber',
    can('crm.ver') ? '<a href="' . e(url('modules/crm/index.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Abrir CRM</a>' : '') ?>
  <?php
  $filas = [];
  foreach ($dormidos as $d) {
      $dias = (int) floor((time() - strtotime($d['ultima'])) / 86400);
      $filas[] = [
          '<div class="flex items-center gap-2.5">' . avatar($d['nombre'], 'w-8 h-8')
            . '<span class="font-semibold text-slate-700">' . e($d['nombre']) . '</span></div>',
          '<span class="text-slate-500">' . e($d['telefono'] ?: '—') . '</span>',
          '<span class="text-slate-500 tabular-nums">' . number_format((int) $d['compras']) . '</span>',
          '<span class="font-semibold text-slate-700 tabular-nums">' . money($d['historico']) . '</span>',
          '<span class="text-slate-500">' . fechaCorta($d['ultima']) . '</span>',
          '<span class="badge badge-' . ($dias > 180 ? 'rose' : ($dias > 90 ? 'amber' : 'slate')) . '">' . $dias . ' días</span>',
          (float) $d['balance'] > 0
            ? '<span class="text-amber-600 font-semibold tabular-nums">' . money($d['balance']) . '</span>'
            : '<span class="text-slate-300">—</span>',
      ];
  }
  echo rep_tabla(
      ['Cliente', 'Teléfono', ['Compras', 'center'], ['Histórico', 'right'], ['Última compra', 'center'], ['Inactividad', 'center'], ['Saldo', 'right']],
      $filas,
      ['vacio_titulo' => 'Ningún cliente dormido', 'vacio' => 'Todos los clientes con historial compraron dentro del periodo. Excelente retención.', 'vacio_icono' => 'check']
  );
  ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
