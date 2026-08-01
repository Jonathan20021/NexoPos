<?php
/**
 * Horarios y tráfico: a qué hora y qué día se vende.
 * Sirve para ajustar turnos, personal en piso y reposición de inventario.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.operacion');

$p = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('v.sucursal_id');
$pv = array_merge([$p['ini'], $p['fin']], $scopeP);

/* ---------- Por hora ---------- */
$porHora = array_fill(0, 24, ['n' => 0, 'ingresos' => 0.0]);
foreach (qAll(
    "SELECT HOUR(v.fecha) h, COUNT(*) n, COALESCE(SUM(v.subtotal - v.descuento),0) ingresos
       FROM ventas v WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope GROUP BY h",
    $pv
) as $r) $porHora[(int) $r['h']] = ['n' => (int) $r['n'], 'ingresos' => (float) $r['ingresos']];

/* ---------- Por día de la semana (1=domingo en MySQL DAYOFWEEK) ---------- */
$dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$porDia = array_fill(0, 7, ['n' => 0, 'ingresos' => 0.0, 'fechas' => 0]);
foreach (qAll(
    "SELECT DAYOFWEEK(v.fecha) d, COUNT(*) n, COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
            COUNT(DISTINCT DATE(v.fecha)) fechas
       FROM ventas v WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope GROUP BY d",
    $pv
) as $r) {
    $porDia[(int) $r['d'] - 1] = ['n' => (int) $r['n'], 'ingresos' => (float) $r['ingresos'], 'fechas' => (int) $r['fechas']];
}

/* ---------- Mapa de calor día × franja horaria ---------- */
$franjas = [
    ['06-09', 6, 9], ['09-12', 9, 12], ['12-15', 12, 15],
    ['15-18', 15, 18], ['18-21', 18, 21], ['21-24', 21, 24],
];
$mapa = [];
foreach (qAll(
    "SELECT DAYOFWEEK(v.fecha) d, HOUR(v.fecha) h, COALESCE(SUM(v.subtotal - v.descuento),0) ingresos, COUNT(*) n
       FROM ventas v WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope GROUP BY d, h",
    $pv
) as $r) {
    $d = (int) $r['d'] - 1;
    foreach ($franjas as $fi => [$lbl, $ini, $fin]) {
        if ((int) $r['h'] >= $ini && (int) $r['h'] < $fin) {
            $mapa[$d][$fi] = ($mapa[$d][$fi] ?? 0) + (float) $r['ingresos'];
        }
    }
}
$maxCelda = 0.0;
foreach ($mapa as $fila) foreach ($fila as $v) $maxCelda = max($maxCelda, $v);

/* ---------- Turnos de caja ---------- */
[$scopeCS, $scopeCSP] = rep_scope('cs.sucursal_id');
$turnos = qAll(
    "SELECT COALESCE(NULLIF(cs.turno,''),'Sin turno') turno, COUNT(*) sesiones,
            COALESCE(SUM(cs.total_ventas),0) ventas, COALESCE(SUM(cs.diferencia),0) diferencia,
            COALESCE(AVG(cs.total_ventas),0) promedio
       FROM caja_sesiones cs
      WHERE cs.estado='cerrada' AND DATE(cs.cerrada_at) BETWEEN ? AND ? AND $scopeCS
      GROUP BY turno ORDER BY ventas DESC",
    array_merge([$p['desde'], $p['hasta']], $scopeCSP)
);

$totalIngresos = array_sum(array_column($porHora, 'ingresos'));
$totalFacturas = array_sum(array_column($porHora, 'n'));

// Hora y día pico.
$horaPico = 0; $maxHora = -1;
foreach ($porHora as $h => $v) if ($v['ingresos'] > $maxHora) { $maxHora = $v['ingresos']; $horaPico = $h; }
$diaPico = 0; $maxDia = -1;
foreach ($porDia as $d => $v) if ($v['ingresos'] > $maxDia) { $maxDia = $v['ingresos']; $diaPico = $d; }

if (export_solicitado()) {
    $filas = [];
    foreach ($porHora as $h => $v) {
        $filas[] = [str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00', $v['n'], money($v['ingresos'], false),
            $v['n'] > 0 ? money($v['ingresos'] / $v['n'], false) : '0.00',
            number_format($totalIngresos > 0 ? $v['ingresos'] / $totalIngresos * 100 : 0, 2)];
    }
    export_tabla('trafico_horario_' . $p['desde'] . '_' . $p['hasta'],
        ['Hora', 'Facturas', 'Ingresos', 'Ticket promedio', '% del total'], $filas, 'Tráfico por hora');
}

layout_start('Horarios y tráfico', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Horarios y tráfico', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Hora pico', 'valor' => str_pad((string) $horaPico, 2, '0', STR_PAD_LEFT) . ':00 h', 'icono' => 'clock', 'color' => 'blue',
     'nota' => money($maxHora) . ' en esa franja'],
    ['label' => 'Día más fuerte', 'valor' => $dias[$diaPico], 'icono' => 'calendar', 'color' => 'violet',
     'nota' => money($maxDia) . ' acumulados'],
    ['label' => 'Facturas del periodo', 'valor' => number_format($totalFacturas), 'icono' => 'receipt', 'color' => 'emerald',
     'nota' => 'Ticket promedio ' . money($totalFacturas > 0 ? $totalIngresos / $totalFacturas : 0)],
    ['label' => 'Promedio por día operado', 'valor' => money(array_sum(array_column($porDia, 'fechas')) > 0
        ? $totalIngresos / array_sum(array_column($porDia, 'fechas')) : 0),
     'icono' => 'trending', 'color' => 'amber',
     'nota' => array_sum(array_column($porDia, 'fechas')) . ' día(s) con ventas'],
]) ?>

<!-- Curva horaria -->
<?= rep_seccion('Ventas por hora del día', 'Dónde se concentra el tráfico', 'clock', 'blue') ?>
  <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
    <?= lineChart([
        ['nombre' => 'Ingresos por hora', 'color' => '#2563eb',
         'valores' => array_column($porHora, 'ingresos'), 'area' => true],
    ], array_map(fn($h) => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . 'h', array_keys($porHora)), ['alto' => 250]) ?>
  </div>
  <?php
  $filas = [];
  foreach ($porHora as $h => $v) {
      if ($v['n'] === 0) continue;
      $filas[] = [
          '<span class="font-semibold text-slate-700 tabular-nums">' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00 – '
            . str_pad((string) (($h + 1) % 24), 2, '0', STR_PAD_LEFT) . ':00</span>',
          '<span class="text-slate-500 tabular-nums">' . number_format($v['n']) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($v['ingresos']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($v['ingresos'] / $v['n']) . '</span>',
          '<div class="h-1.5 w-24 rounded-full bg-slate-100 overflow-hidden ml-auto"><div class="h-full rounded-full bg-blue-500" style="width:'
            . max($totalIngresos > 0 ? $v['ingresos'] / $totalIngresos * 100 : 0, 1) . '%"></div></div>',
      ];
  }
  echo rep_tabla(['Franja', ['Facturas', 'center'], ['Ingresos', 'right'], ['Ticket', 'right'], ['Peso', 'right']], $filas,
      ['vacio_titulo' => 'Sin ventas', 'vacio' => 'No hubo facturación en el periodo seleccionado.', 'vacio_icono' => 'clock']);
  ?>
<?= rep_fin() ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Días de la semana -->
  <div>
    <?= rep_seccion('Por día de la semana', 'Acumulado del periodo', 'calendar', 'violet') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php $maxD = max(1, max(array_column($porDia, 'ingresos'))); foreach ($porDia as $d => $v): ?>
          <?= rep_barra($dias[$d], money($v['ingresos'], false),
                        $v['ingresos'] / $maxD * 100, rep_color($d),
                        $v['n'] . ' facturas en ' . $v['fechas'] . ' día(s)') ?>
        <?php endforeach; ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <!-- Turnos de caja -->
  <div>
    <?= rep_seccion('Rendimiento por turno de caja', 'Sesiones cerradas en el periodo', 'cash', 'emerald') ?>
      <?php
      $filas = [];
      foreach ($turnos as $t) {
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($t['turno']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . number_format((int) $t['sesiones']) . '</span>',
              '<span class="font-bold text-slate-800 tabular-nums">' . money($t['ventas']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . money($t['promedio']) . '</span>',
              '<span class="' . (abs((float) $t['diferencia']) < 0.01 ? 'text-emerald-600' : 'text-rose-600') . ' font-semibold tabular-nums">'
                . money($t['diferencia']) . '</span>',
          ];
      }
      echo rep_tabla(['Turno', ['Sesiones', 'center'], ['Ventas', 'right'], ['Promedio', 'right'], ['Dif. arqueo', 'right']], $filas,
          ['vacio_titulo' => 'Sin cierres de caja',
           'vacio' => 'No hay sesiones de caja cerradas dentro del periodo seleccionado.', 'vacio_icono' => 'cash']);
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Mapa de calor -->
<?= rep_seccion('Mapa de calor semanal', 'Intensidad de venta por día y franja horaria', 'grid', 'amber') ?>
  <div class="px-5 pb-5 overflow-x-auto">
    <table class="w-full min-w-[560px] border-separate" style="border-spacing:4px">
      <thead>
        <tr>
          <th class="text-left text-[11px] font-bold uppercase tracking-wide text-slate-400 w-24"></th>
          <?php foreach ($franjas as [$lbl]): ?>
            <th class="text-center text-[11px] font-bold uppercase tracking-wide text-slate-400"><?= e($lbl) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dias as $d => $nombre): ?>
          <tr>
            <td class="text-[12.5px] font-semibold text-slate-600 pr-2"><?= e($nombre) ?></td>
            <?php foreach ($franjas as $fi => [$lbl]):
              $val = $mapa[$d][$fi] ?? 0;
              $int = $maxCelda > 0 ? $val / $maxCelda : 0;
              $bg = $val <= 0 ? '#f8fafc' : 'rgba(37,99,235,' . max(0.08, round($int, 2)) . ')';
              $fg = $int > 0.55 ? '#fff' : '#475569';
            ?>
              <td class="rounded-lg text-center py-3 text-[11.5px] font-semibold tabular-nums"
                  style="background:<?= $bg ?>;color:<?= $fg ?>"
                  title="<?= e($nombre . ' ' . $lbl . ': ' . money($val)) ?>">
                <?= $val > 0 ? money($val, false) : '·' ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
    Los bloques más oscuros son las horas de mayor facturación. Úsalos para decidir cuánta gente poner en piso,
    cuándo hacer la reposición de góndola y a qué hora conviene lanzar una promoción.
  </div>
<?= rep_fin() ?>

<?php layout_end(); ?>
