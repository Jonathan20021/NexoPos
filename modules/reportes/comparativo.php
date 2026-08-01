<?php
/** Comparativo de periodos: contra el periodo anterior y contra el año pasado. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.ejecutivo');

$p = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('v.sucursal_id');

// Mismo rango del año pasado.
$anioIni = date('Y-m-d', strtotime($p['desde'] . ' -1 year')) . ' 00:00:00';
$anioFin = date('Y-m-d', strtotime($p['hasta'] . ' -1 year')) . ' 23:59:59';

$rangos = [
    'actual'   => ['Periodo actual', $p['ini'], $p['fin'], fechaCorta($p['desde']) . ' al ' . fechaCorta($p['hasta'])],
    'anterior' => ['Periodo anterior', $p['prev_ini'], $p['prev_fin'], fechaCorta($p['prev_desde']) . ' al ' . fechaCorta($p['prev_hasta'])],
    'anio'     => ['Mismo periodo año pasado', $anioIni, $anioFin, fechaCorta(substr($anioIni, 0, 10)) . ' al ' . fechaCorta(substr($anioFin, 0, 10))],
];

/** Totales de un rango. */
function cmp_total(string $ini, string $fin, string $scope, array $scopeP): array
{
    $r = qOne(
        "SELECT COUNT(*) facturas,
                COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
                COALESCE(SUM(v.costo_total),0) costo,
                COALESCE(SUM(v.descuento),0) descuentos,
                COUNT(DISTINCT v.cliente_id) clientes
           FROM ventas v
          WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope",
        array_merge([$ini, $fin], $scopeP)
    ) ?: [];
    $ing = (float) ($r['ingresos'] ?? 0);
    $cos = (float) ($r['costo'] ?? 0);
    return [
        'facturas' => (int) ($r['facturas'] ?? 0), 'ingresos' => $ing, 'costo' => $cos,
        'utilidad' => $ing - $cos, 'descuentos' => (float) ($r['descuentos'] ?? 0),
        'clientes' => (int) ($r['clientes'] ?? 0),
        'ticket' => ($r['facturas'] ?? 0) > 0 ? $ing / (int) $r['facturas'] : 0,
        'margen' => $ing > 0 ? ($ing - $cos) / $ing * 100 : 0,
    ];
}

$tot = [];
foreach ($rangos as $k => $r) $tot[$k] = cmp_total($r[1], $r[2], $scope, $scopeP);

/**
 * Desglose comparado por una dimensión (mismo SQL, tres rangos).
 *
 * `$ingresos` es la expresión a sumar y NO siempre es la misma. Las dimensiones
 * que agrupan por algo de la venta (sucursal, canal, vendedor) suman el total de
 * la venta. La de categoría entra por `venta_detalles`, así que sumar el total de
 * la venta lo repetiría una vez por cada línea: medido con 3 líneas por factura,
 * daba 137,3 millones donde el ingreso real era 45,8 — el triple. Ahí hay que
 * sumar la línea, que además es lo único que se puede atribuir a una categoría.
 */
function cmp_dimension(string $selectLabel, string $from, string $groupBy, array $rangos, string $scope, array $scopeP, string $ingresos = 'v.subtotal - v.descuento'): array
{
    $out = [];
    foreach ($rangos as $k => $r) {
        $rows = qAll(
            "SELECT $selectLabel AS etiqueta, COALESCE(SUM($ingresos),0) AS ingresos, COUNT(DISTINCT v.id) AS facturas
               FROM ventas v $from
              WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
              GROUP BY $groupBy",
            array_merge([$r[1], $r[2]], $scopeP)
        );
        foreach ($rows as $row) {
            $et = $row['etiqueta'] ?? 'Sin especificar';
            $out[$et][$k] = ['ingresos' => (float) $row['ingresos'], 'facturas' => (int) $row['facturas']];
        }
    }
    foreach ($out as $et => $v) {
        foreach (array_keys($rangos) as $k) {
            $out[$et][$k] = $v[$k] ?? ['ingresos' => 0.0, 'facturas' => 0];
        }
    }
    uasort($out, fn($a, $b) => $b['actual']['ingresos'] <=> $a['actual']['ingresos']);
    return $out;
}

$dimensiones = [
    'Sucursal'        => cmp_dimension('su.nombre', 'JOIN sucursales su ON su.id = v.sucursal_id', 'v.sucursal_id', $rangos, $scope, $scopeP),
    'Canal de venta'  => cmp_dimension("COALESCE(NULLIF(v.canal_venta,''),'Sin especificar')", '', 'v.canal_venta', $rangos, $scope, $scopeP),
    'Vendedor'        => cmp_dimension("CONCAT(u.nombre,' ',u.apellido)", 'JOIN usuarios u ON u.id = v.usuario_id', 'v.usuario_id', $rangos, $scope, $scopeP),
    // Suma la LÍNEA, no el total de la venta: ver el comentario de cmp_dimension().
    'Categoría'       => cmp_dimension("COALESCE(c.nombre,'Sin categoría')",
        'JOIN venta_detalles vd ON vd.venta_id = v.id LEFT JOIN productos pr ON pr.id = vd.producto_id LEFT JOIN categorias c ON c.id = pr.categoria_id',
        'c.id', $rangos, $scope, $scopeP, 'vd.subtotal - vd.descuento'),
];

/* ---------- Serie diaria comparada ---------- */
$serie = [];
$dias  = min($p['dias'], 31);
for ($i = 0; $i < $dias; $i++) {
    $dAct = date('Y-m-d', strtotime($p['desde'] . " +$i day"));
    $dAnt = date('Y-m-d', strtotime($p['prev_desde'] . " +$i day"));
    if ($dAct > $p['hasta']) break;
    $serie[] = ['label' => date('d/m', strtotime($dAct)), 'act' => $dAct, 'ant' => $dAnt];
}
$ventasDia = function (array $fechas) use ($scope, $scopeP) {
    if (!$fechas) return [];
    $ph = implode(',', array_fill(0, count($fechas), '?'));
    $rows = qAll(
        "SELECT DATE(v.fecha) d, COALESCE(SUM(v.subtotal - v.descuento),0) t
           FROM ventas v WHERE v.estado='completada' AND DATE(v.fecha) IN ($ph) AND $scope GROUP BY d",
        array_merge($fechas, $scopeP)
    );
    $m = [];
    foreach ($rows as $r) $m[$r['d']] = (float) $r['t'];
    return $m;
};
$mapAct = $ventasDia(array_column($serie, 'act'));
$mapAnt = $ventasDia(array_column($serie, 'ant'));
$datosBarra = [];
foreach ($serie as $s) {
    $datosBarra[] = ['label' => $s['label'], 'a' => $mapAct[$s['act']] ?? 0, 'b' => $mapAnt[$s['ant']] ?? 0];
}

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    foreach ([
        ['Ingresos netos', 'ingresos', true], ['Costo de ventas', 'costo', true],
        ['Utilidad bruta', 'utilidad', true], ['Margen %', 'margen', false],
        ['Facturas', 'facturas', false], ['Ticket promedio', 'ticket', true],
        ['Clientes distintos', 'clientes', false], ['Descuentos', 'descuentos', true],
    ] as [$lbl, $campo, $esMoneda]) {
        $f = fn($v) => $esMoneda ? money($v, false) : ($campo === 'margen' ? number_format($v, 2) : number_format($v));
        $d1 = rep_delta((float) $tot['actual'][$campo], (float) $tot['anterior'][$campo]);
        $d2 = rep_delta((float) $tot['actual'][$campo], (float) $tot['anio'][$campo]);
        $filas[] = [$lbl, $f($tot['actual'][$campo]), $f($tot['anterior'][$campo]),
                    $d1 === null ? '—' : number_format($d1, 1) . '%',
                    $f($tot['anio'][$campo]), $d2 === null ? '—' : number_format($d2, 1) . '%'];
    }
    export_tabla('comparativo_' . $p['desde'] . '_' . $p['hasta'],
        ['Indicador', 'Actual', 'Anterior', 'Var. vs anterior', 'Año pasado', 'Var. vs año pasado'],
        $filas, 'Comparativo de periodos');
}

layout_start('Comparativo de periodos', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Comparativo de periodos', $p, ['sucursal' => true]);

/** Celda con variación. */
function cmp_var(?float $d, bool $invertir = false): string
{
    if ($d === null) return '<span class="text-slate-300">—</span>';
    $bueno = $invertir ? $d <= 0 : $d >= 0;
    return '<span class="badge ' . ($bueno ? 'stat-trend-up' : 'stat-trend-down') . '">'
        . icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') . ' ' . number_format(abs($d), 1) . '%</span>';
}
?>

<!-- Encabezado de rangos -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <?php $estilos = ['actual' => 'border-blue-200 bg-blue-50/40', 'anterior' => '', 'anio' => ''];
  foreach ($rangos as $k => $r): ?>
    <div class="card p-4 <?= $estilos[$k] ?>">
      <p class="text-xs font-bold uppercase tracking-wide text-slate-400"><?= e($r[0]) ?></p>
      <p class="text-lg font-extrabold text-slate-800 mt-1 tabular-nums"><?= money($tot[$k]['ingresos']) ?></p>
      <p class="text-xs text-slate-400 mt-0.5"><?= e($r[3]) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<!-- Tabla comparativa de indicadores -->
<?= rep_seccion('Indicadores comparados', 'Contra el periodo anterior y contra el mismo periodo del año pasado', 'chart', 'blue') ?>
  <?php
  $filas = [];
  $indicadores = [
      ['Ingresos netos', 'ingresos', 'money', false],
      ['Costo de mercancía vendida', 'costo', 'money', true],
      ['Utilidad bruta', 'utilidad', 'money', false],
      ['Margen bruto', 'margen', 'pct', false],
      ['Facturas emitidas', 'facturas', 'num', false],
      ['Ticket promedio', 'ticket', 'money', false],
      ['Clientes distintos', 'clientes', 'num', false],
      ['Descuentos otorgados', 'descuentos', 'money', true],
  ];
  foreach ($indicadores as [$lbl, $campo, $tipo, $inv]) {
      $fmt = fn($v) => $tipo === 'money' ? money($v) : ($tipo === 'pct' ? number_format($v, 1) . '%' : number_format($v));
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($lbl) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . $fmt($tot['actual'][$campo]) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . $fmt($tot['anterior'][$campo]) . '</span>',
          cmp_var(rep_delta((float) $tot['actual'][$campo], (float) $tot['anterior'][$campo]), $inv),
          '<span class="text-slate-500 tabular-nums">' . $fmt($tot['anio'][$campo]) . '</span>',
          cmp_var(rep_delta((float) $tot['actual'][$campo], (float) $tot['anio'][$campo]), $inv),
      ];
  }
  echo rep_tabla(
      ['Indicador', ['Actual', 'right'], ['Anterior', 'right'], ['Var.', 'center'], ['Año pasado', 'right'], ['Var. anual', 'center']],
      $filas
  );
  ?>
<?= rep_fin() ?>

<!-- Serie diaria -->
<?php if ($datosBarra): ?>
<?= rep_seccion('Día a día contra el periodo anterior', 'Cada barra oscura es este periodo; la clara, el anterior', 'trending', 'indigo') ?>
  <div class="px-5 pb-5 overflow-x-auto">
    <?= barChartComparado($datosBarra, 'Periodo actual', 'Periodo anterior') ?>
  </div>
<?= rep_fin() ?>
<?php endif; ?>

<!-- Desglose por dimensión -->
<?php foreach ($dimensiones as $nombre => $datos):
  $iconos = ['Sucursal' => 'store', 'Canal de venta' => 'megaphone', 'Vendedor' => 'users', 'Categoría' => 'tag'];
  $filas = [];
  foreach ($datos as $etiqueta => $v) {
      $d1 = rep_delta($v['actual']['ingresos'], $v['anterior']['ingresos']);
      $d2 = rep_delta($v['actual']['ingresos'], $v['anio']['ingresos']);
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($etiqueta) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . number_format($v['actual']['facturas']) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($v['actual']['ingresos']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($v['anterior']['ingresos']) . '</span>',
          cmp_var($d1),
          '<span class="text-slate-500 tabular-nums">' . money($v['anio']['ingresos']) . '</span>',
          cmp_var($d2),
      ];
  }
?>
  <?= rep_seccion('Por ' . mb_strtolower($nombre), 'Ingresos netos comparados', $iconos[$nombre] ?? 'list', 'emerald') ?>
    <?= rep_tabla(
        [$nombre, ['Facturas', 'center'], ['Actual', 'right'], ['Anterior', 'right'], ['Var.', 'center'], ['Año pasado', 'right'], ['Var. anual', 'center']],
        $filas
    ) ?>
  <?= rep_fin() ?>
<?php endforeach; ?>

<?php layout_end(); ?>
