<?php
/** Análisis de gastos: por categoría, por sucursal y tendencia mensual. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.finanzas');

$p = rep_periodo('mes');
[$scopeT, $scopeTP] = rep_scope('t.sucursal_id');
[$scopeV, $scopeVP] = rep_scope('v.sucursal_id');
$par = array_merge([$p['desde'], $p['hasta']], $scopeTP);

$ingresos = (float) qVal(
    "SELECT COALESCE(SUM(v.subtotal - v.descuento),0) FROM ventas v
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scopeV",
    array_merge([$p['ini'], $p['fin']], $scopeVP)
);

/* ---------- Por categoría ---------- */
$porCategoria = qAll(
    "SELECT COALESCE(cf.nombre,'Sin clasificar') AS categoria, COUNT(*) AS n,
            COALESCE(SUM(t.monto),0) AS monto
       FROM transacciones t LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
      WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT
      GROUP BY t.categoria_id ORDER BY monto DESC",
    $par
);
$total = array_sum(array_column($porCategoria, 'monto'));

$prevCategoria = [];
foreach (qAll(
    "SELECT COALESCE(cf.nombre,'Sin clasificar') AS categoria, COALESCE(SUM(t.monto),0) AS monto
       FROM transacciones t LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
      WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT GROUP BY t.categoria_id",
    array_merge([$p['prev_desde'], $p['prev_hasta']], $scopeTP)
) as $r) {
    $prevCategoria[$r['categoria']] = (float) $r['monto'];
}
$totalPrev = array_sum($prevCategoria);

/* ---------- Por sucursal ---------- */
$porSucursal = qAll(
    "SELECT COALESCE(su.nombre,'Sin sucursal') AS sucursal, COALESCE(SUM(t.monto),0) AS monto, COUNT(*) n
       FROM transacciones t LEFT JOIN sucursales su ON su.id = t.sucursal_id
      WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT
      GROUP BY t.sucursal_id ORDER BY monto DESC",
    $par
);

/* ---------- Tendencia 12 meses ---------- */
$meses = rep_meses_atras(12);
$mGasto = [];
foreach (qAll(
    "SELECT DATE_FORMAT(t.fecha,'%Y-%m') ym, COALESCE(SUM(t.monto),0) g
       FROM transacciones t WHERE " . rep_where_gastos() . " AND t.fecha >= ? AND $scopeT GROUP BY ym",
    array_merge([$meses[0] . '-01'], $scopeTP)
) as $r) $mGasto[$r['ym']] = (float) $r['g'];

$mIngreso = [];
foreach (qAll(
    "SELECT DATE_FORMAT(v.fecha,'%Y-%m') ym, COALESCE(SUM(v.subtotal - v.descuento),0) i
       FROM ventas v WHERE v.estado='completada' AND v.fecha >= ? AND $scopeV GROUP BY ym",
    array_merge([$meses[0] . '-01 00:00:00'], $scopeVP)
) as $r) $mIngreso[$r['ym']] = (float) $r['i'];

$labels = $sGasto = $sIngreso = [];
foreach ($meses as $ym) {
    $labels[]   = rep_mes_label($ym);
    $sGasto[]   = $mGasto[$ym] ?? 0;
    $sIngreso[] = $mIngreso[$ym] ?? 0;
}

/* ---------- Detalle de movimientos ---------- */
$pg = paginar((int) qVal(
    "SELECT COUNT(*) FROM transacciones t WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT",
    $par
), 30);
$detalle = qAll(
    "SELECT t.*, COALESCE(cf.nombre,'Sin clasificar') AS categoria, su.nombre AS sucursal,
            cu.nombre AS cuenta, CONCAT(u.nombre,' ',u.apellido) AS usuario
       FROM transacciones t
       LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
       LEFT JOIN sucursales su ON su.id = t.sucursal_id
       LEFT JOIN cuentas_financieras cu ON cu.id = t.cuenta_id
       LEFT JOIN usuarios u ON u.id = t.usuario_id
      WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT
      ORDER BY t.fecha DESC, t.id DESC LIMIT " . (int) $pg['porPagina'] . " OFFSET " . (int) $pg['offset'],
    $par
);

/* ---------- Gasto mayor del periodo ---------- */
$mayor = qOne(
    "SELECT t.monto, t.descripcion, t.fecha, COALESCE(cf.nombre,'Sin clasificar') categoria
       FROM transacciones t LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
      WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT
      ORDER BY t.monto DESC LIMIT 1",
    $par
);

if (export_solicitado()) {
    $todos = qAll(
        "SELECT t.fecha, COALESCE(cf.nombre,'Sin clasificar') categoria, su.nombre sucursal,
                cu.nombre cuenta, t.descripcion, t.referencia_tipo, t.monto
           FROM transacciones t
           LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
           LEFT JOIN sucursales su ON su.id = t.sucursal_id
           LEFT JOIN cuentas_financieras cu ON cu.id = t.cuenta_id
          WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT
          ORDER BY t.fecha DESC",
        $par
    );
    $filas = [];
    foreach ($todos as $t) {
        $filas[] = [fechaCorta($t['fecha']), $t['categoria'], $t['sucursal'] ?? '', $t['cuenta'] ?? '',
                    $t['descripcion'] ?? '', $t['referencia_tipo'] ?? 'manual', money($t['monto'], false)];
    }
    export_tabla('gastos_' . $p['desde'] . '_' . $p['hasta'],
        ['Fecha', 'Categoría', 'Sucursal', 'Cuenta', 'Descripción', 'Origen', 'Monto'], $filas, 'Análisis de gastos');
}

layout_start('Análisis de gastos', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Análisis de gastos', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Gasto operativo total', 'valor' => money($total), 'icono' => 'dollar', 'color' => 'amber',
     'delta' => rep_delta($total, $totalPrev), 'invertir' => true,
     'nota' => count($porCategoria) . ' categoría(s) con movimiento'],
    ['label' => 'Peso sobre los ingresos', 'valor' => ($ingresos > 0 ? number_format($total / $ingresos * 100, 1) : '0.0') . '%',
     'icono' => 'percent', 'color' => ($ingresos > 0 && $total / $ingresos > 0.4) ? 'rose' : 'blue',
     'nota' => 'Ingresos netos: ' . money($ingresos)],
    ['label' => 'Gasto promedio diario', 'valor' => money($p['dias'] > 0 ? $total / $p['dias'] : 0), 'icono' => 'calendar',
     'color' => 'violet', 'nota' => $p['dias'] . ' día(s) en el periodo'],
    ['label' => 'Mayor gasto individual', 'valor' => money($mayor['monto'] ?? 0), 'icono' => 'alert', 'color' => 'rose',
     'nota' => $mayor ? e(mb_substr((string) ($mayor['descripcion'] ?: $mayor['categoria']), 0, 40)) : 'Sin gastos'],
]) ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <div>
    <?= rep_seccion('Distribución del gasto', 'Participación de cada categoría', 'pie', 'amber') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php if (!$porCategoria): ?>
          <?= empty_state('Sin gastos en el periodo', 'No se registraron gastos operativos en este rango de fechas.', 'dollar') ?>
        <?php else: ?>
          <?= donutMulti(array_map(
              fn($g, $i) => ['label' => $g['categoria'], 'value' => (float) $g['monto'], 'color' => rep_color($i)],
              $porCategoria, array_keys($porCategoria)
          ), 'Total', money($total, false)) ?>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('Gasto por sucursal', 'Dónde se está gastando', 'store', 'indigo') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php if (!$porSucursal): ?>
          <?= empty_state('Sin gastos por sucursal', 'Cuando registres gastos verás aquí cuánto consume cada local.', 'store') ?>
        <?php else: foreach ($porSucursal as $i => $s): ?>
          <?= rep_barra($s['sucursal'], money($s['monto'], false), $total > 0 ? $s['monto'] / $total * 100 : 0,
                        rep_color($i), (int) $s['n'] . ' movimiento(s)') ?>
        <?php endforeach; endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Comparativa por categoría -->
<?= rep_seccion('Gasto por categoría comparado', 'Contra el periodo anterior: dónde se disparó el costo', 'list', 'rose') ?>
  <?php
  $filas = [];
  foreach ($porCategoria as $c) {
      $ant = $prevCategoria[$c['categoria']] ?? 0.0;
      $d = rep_delta((float) $c['monto'], $ant);
      $pctIng = $ingresos > 0 ? (float) $c['monto'] / $ingresos * 100 : 0;
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($c['categoria']) . '</span>',
          '<span class="text-slate-400 tabular-nums">' . number_format((int) $c['n']) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($c['monto']) . '</span>',
          '<span class="text-slate-400 tabular-nums text-xs">' . number_format($total > 0 ? $c['monto'] / $total * 100 : 0, 1) . '%</span>',
          '<span class="text-slate-500 tabular-nums">' . money($ant) . '</span>',
          $d === null
            ? '<span class="text-slate-300">—</span>'
            : '<span class="badge ' . ($d <= 0 ? 'stat-trend-up' : 'stat-trend-down') . '">'
              . icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') . ' ' . number_format(abs($d), 1) . '%</span>',
          '<span class="text-slate-400 tabular-nums text-xs">' . number_format($pctIng, 1) . '%</span>',
      ];
  }
  echo rep_tabla(
      ['Categoría', ['Mov.', 'center'], ['Periodo actual', 'right'], ['% gasto', 'right'], ['Periodo anterior', 'right'], ['Variación', 'center'], ['% ingresos', 'right']],
      $filas,
      ['total' => $filas ? ['Total', '', money($total), '100%', money($totalPrev), '', ($ingresos > 0 ? number_format($total / $ingresos * 100, 1) . '%' : '—')] : null]
  );
  ?>
<?= rep_fin() ?>

<!-- Tendencia -->
<?= rep_seccion('Ingresos contra gastos, 12 meses', 'La distancia entre las dos líneas es la utilidad operativa', 'trending', 'blue') ?>
  <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
    <?= lineChart([
        ['nombre' => 'Ingresos netos', 'color' => marca_app(), 'valores' => $sIngreso, 'area' => true],
        ['nombre' => 'Gastos operativos', 'color' => '#f59e0b', 'valores' => $sGasto],
    ], $labels, ['alto' => 280]) ?>
  </div>
<?= rep_fin() ?>

<!-- Detalle -->
<?= rep_seccion('Detalle de movimientos', 'Cada gasto del periodo', 'file', 'slate',
    can('finanzas.ver') ? '<a href="' . e(url('modules/finanzas/index.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Registrar gasto</a>' : '') ?>
  <?php
  $origenes = ['nomina' => ['Nómina', 'violet'], 'comision' => ['Comisión', 'cyan'], 'manual' => ['Manual', 'slate']];
  $filas = [];
  foreach ($detalle as $t) {
      [$oLbl, $oCol] = $origenes[$t['referencia_tipo'] ?? 'manual'] ?? [ucfirst((string) $t['referencia_tipo']), 'slate'];
      $filas[] = [
          '<span class="text-slate-500 whitespace-nowrap">' . fechaCorta($t['fecha']) . '</span>',
          '<span class="font-semibold text-slate-700">' . e($t['categoria']) . '</span>',
          '<span class="text-slate-600">' . e($t['descripcion'] ?: '—') . '</span>',
          '<span class="text-slate-500">' . e($t['sucursal'] ?: 'Global') . '</span>',
          '<span class="text-slate-500">' . e($t['cuenta'] ?: '—') . '</span>',
          badge($oLbl, $oCol),
          '<span class="font-bold text-rose-600 tabular-nums">' . money($t['monto']) . '</span>',
      ];
  }
  echo rep_tabla(['Fecha', 'Categoría', 'Descripción', 'Sucursal', 'Cuenta', ['Origen', 'center'], ['Monto', 'right']], $filas);
  echo paginacion($pg);
  ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
