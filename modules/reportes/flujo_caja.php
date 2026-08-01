<?php
/**
 * Flujo de efectivo: entradas, salidas y saldo acumulado.
 *
 * A diferencia del estado de resultados, aquí SÍ entra todo lo que mueve dinero
 * (compras de mercancía, cobros de cuentas por cobrar...). Es el reporte de
 * liquidez: no dice si el negocio gana, dice si tiene con qué pagar.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.finanzas');

$p = rep_periodo('mes');
[$scopeT, $scopeTP] = rep_scope('t.sucursal_id');
$par = array_merge([$p['desde'], $p['hasta']], $scopeTP);

/* ---------- Totales ---------- */
$tot = qOne(
    "SELECT COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE 0 END),0) entradas,
            COALESCE(SUM(CASE WHEN t.tipo='gasto'   THEN t.monto ELSE 0 END),0) salidas,
            COUNT(*) n
       FROM transacciones t WHERE t.fecha BETWEEN ? AND ? AND $scopeT AND " . rep_where_flujo() . "",
    $par
) ?: ['entradas' => 0, 'salidas' => 0, 'n' => 0];
$entradas = (float) $tot['entradas'];
$salidas  = (float) $tot['salidas'];
$flujo    = $entradas - $salidas;

$prev = qOne(
    "SELECT COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE 0 END),0) e,
            COALESCE(SUM(CASE WHEN t.tipo='gasto'   THEN t.monto ELSE 0 END),0) s
       FROM transacciones t WHERE t.fecha BETWEEN ? AND ? AND $scopeT AND " . rep_where_flujo() . "",
    array_merge([$p['prev_desde'], $p['prev_hasta']], $scopeTP)
) ?: ['e' => 0, 's' => 0];

// Saldo antes del periodo (arranque de la curva acumulada).
$saldoInicial = (float) qVal(
    "SELECT COALESCE(SUM(cf.saldo_inicial),0) FROM cuentas_financieras cf WHERE cf.activo = 1"
) + (float) qVal(
    "SELECT COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE -t.monto END),0)
       FROM transacciones t WHERE t.fecha < ? AND $scopeT AND " . rep_where_flujo() . "",
    array_merge([$p['desde']], $scopeTP)
);

/* ---------- Movimiento diario ---------- */
$porDia = qAll(
    "SELECT t.fecha,
            COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE 0 END),0) entradas,
            COALESCE(SUM(CASE WHEN t.tipo='gasto'   THEN t.monto ELSE 0 END),0) salidas
       FROM transacciones t WHERE t.fecha BETWEEN ? AND ? AND $scopeT AND " . rep_where_flujo() . "
      GROUP BY t.fecha ORDER BY t.fecha",
    $par
);
$mapDia = [];
foreach ($porDia as $d) $mapDia[$d['fecha']] = $d;

$labels = $serieEnt = $serieSal = $serieSaldo = [];
$saldo = $saldoInicial;
$maxPuntos = 62;
$paso = max(1, (int) ceil($p['dias'] / $maxPuntos));
$i = 0;
for ($t = strtotime($p['desde']); $t <= strtotime($p['hasta']); $t += 86400) {
    $f = date('Y-m-d', $t);
    $e = (float) ($mapDia[$f]['entradas'] ?? 0);
    $s = (float) ($mapDia[$f]['salidas'] ?? 0);
    $saldo += $e - $s;
    if ($i % $paso === 0 || $f === $p['hasta']) {
        $labels[]     = date('d/m', $t);
        $serieEnt[]   = $e;
        $serieSal[]   = $s;
        $serieSaldo[] = $saldo;
    }
    $i++;
}
$saldoFinal = $saldo;

/* ---------- Por cuenta ---------- */
$porCuenta = qAll(
    "SELECT cf.id, cf.nombre, cf.tipo, cf.balance,
            COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE 0 END),0) entradas,
            COALESCE(SUM(CASE WHEN t.tipo='gasto'   THEN t.monto ELSE 0 END),0) salidas
       FROM cuentas_financieras cf
       LEFT JOIN transacciones t ON t.cuenta_id = cf.id AND t.fecha BETWEEN ? AND ? AND $scopeT AND " . rep_where_flujo() . "
      WHERE cf.activo = 1
      GROUP BY cf.id ORDER BY cf.nombre",
    $par
);

/* ---------- Por categoría ---------- */
$entradasCat = qAll(
    "SELECT COALESCE(cf.nombre, CASE t.referencia_tipo WHEN 'venta' THEN 'Ventas' WHEN 'abono' THEN 'Cobros a clientes' ELSE 'Otros' END) AS categoria,
            COALESCE(SUM(t.monto),0) monto, COUNT(*) n
       FROM transacciones t LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
      WHERE t.tipo = 'ingreso' AND t.fecha BETWEEN ? AND ? AND $scopeT AND " . rep_where_flujo() . "
      GROUP BY categoria ORDER BY monto DESC",
    $par
);
$salidasCat = qAll(
    "SELECT COALESCE(cf.nombre, CASE t.referencia_tipo WHEN 'compra' THEN 'Compra de mercancía' WHEN 'nomina' THEN 'Nómina' ELSE 'Otros' END) AS categoria,
            COALESCE(SUM(t.monto),0) monto, COUNT(*) n
       FROM transacciones t LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
      WHERE t.tipo = 'gasto' AND t.fecha BETWEEN ? AND ? AND $scopeT AND " . rep_where_flujo() . "
      GROUP BY categoria ORDER BY monto DESC",
    $par
);

/* ---------- Efectivo de caja (arqueos del periodo) ---------- */
[$scopeCS, $scopeCSP] = rep_scope('cs.sucursal_id');
$cajas = qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(cs.total_efectivo),0) efectivo,
            COALESCE(SUM(cs.diferencia),0) diferencia
       FROM caja_sesiones cs
      WHERE cs.estado = 'cerrada' AND cs.cerrada_at BETWEEN ? AND ? AND $scopeCS",
    array_merge([$p['ini'], $p['fin']], $scopeCSP)
) ?: ['n' => 0, 'efectivo' => 0, 'diferencia' => 0];

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    $sal = $saldoInicial;
    foreach ($porDia as $d) {
        $sal += (float) $d['entradas'] - (float) $d['salidas'];
        $filas[] = [fechaCorta($d['fecha']), money($d['entradas'], false), money($d['salidas'], false),
                    money((float) $d['entradas'] - (float) $d['salidas'], false), money($sal, false)];
    }
    export_tabla('flujo_efectivo_' . $p['desde'] . '_' . $p['hasta'],
        ['Fecha', 'Entradas', 'Salidas', 'Flujo del día', 'Saldo acumulado'], $filas, 'Flujo de efectivo');
}

layout_start('Flujo de efectivo', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Flujo de efectivo', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Entradas de efectivo', 'valor' => money($entradas), 'icono' => 'arrow-down', 'color' => 'emerald',
     'delta' => rep_delta($entradas, (float) $prev['e']), 'nota' => 'Cobros y ventas del periodo'],
    ['label' => 'Salidas de efectivo', 'valor' => money($salidas), 'icono' => 'arrow-up', 'color' => 'rose',
     'delta' => rep_delta($salidas, (float) $prev['s']), 'invertir' => true, 'nota' => 'Pagos, compras y nómina'],
    ['label' => 'Flujo neto', 'valor' => money($flujo), 'icono' => 'pulse', 'color' => $flujo >= 0 ? 'blue' : 'rose',
     'delta' => rep_delta($flujo, (float) $prev['e'] - (float) $prev['s']),
     'nota' => $flujo >= 0 ? 'El periodo generó caja' : 'El periodo consumió caja'],
    ['label' => 'Saldo al cierre', 'valor' => money($saldoFinal), 'icono' => 'bank', 'color' => 'violet',
     'nota' => 'Arrancó en ' . money($saldoInicial)],
]) ?>

<!-- Curva de saldo -->
<?= rep_seccion('Curva de liquidez', 'Saldo acumulado día por día. La línea no debería acercarse a cero.', 'trending', 'blue') ?>
  <div class="px-5 pb-5">
    <?= lineChart([
        ['nombre' => 'Saldo acumulado', 'color' => '#8b5cf6', 'valores' => $serieSaldo, 'area' => true],
        ['nombre' => 'Entradas', 'color' => '#10b981', 'valores' => $serieEnt],
        ['nombre' => 'Salidas', 'color' => '#f43f5e', 'valores' => $serieSal],
    ], $labels, ['alto' => 290]) ?>
  </div>
<?= rep_fin() ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <div>
    <?= rep_seccion('De dónde entra el dinero', 'Entradas del periodo por concepto', 'arrow-down', 'emerald') ?>
      <?php
      $totE = array_sum(array_column($entradasCat, 'monto')) ?: 1;
      $filas = [];
      foreach ($entradasCat as $c) {
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($c['categoria']) . '</span>',
              '<span class="text-slate-400 tabular-nums">' . number_format((int) $c['n']) . '</span>',
              '<span class="font-bold text-emerald-600 tabular-nums">' . money($c['monto']) . '</span>',
              '<span class="text-slate-400 tabular-nums text-xs">' . number_format($c['monto'] / $totE * 100, 1) . '%</span>',
          ];
      }
      echo rep_tabla(['Concepto', ['Mov.', 'center'], ['Monto', 'right'], ['%', 'right']], $filas,
          ['total' => $filas ? ['Total entradas', number_format((int) array_sum(array_column($entradasCat, 'n'))), money($entradas), '100%'] : null]);
      ?>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('A dónde sale el dinero', 'Salidas del periodo por concepto', 'arrow-up', 'rose') ?>
      <?php
      $totS = array_sum(array_column($salidasCat, 'monto')) ?: 1;
      $filas = [];
      foreach ($salidasCat as $c) {
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($c['categoria']) . '</span>',
              '<span class="text-slate-400 tabular-nums">' . number_format((int) $c['n']) . '</span>',
              '<span class="font-bold text-rose-600 tabular-nums">' . money($c['monto']) . '</span>',
              '<span class="text-slate-400 tabular-nums text-xs">' . number_format($c['monto'] / $totS * 100, 1) . '%</span>',
          ];
      }
      echo rep_tabla(['Concepto', ['Mov.', 'center'], ['Monto', 'right'], ['%', 'right']], $filas,
          ['total' => $filas ? ['Total salidas', number_format((int) array_sum(array_column($salidasCat, 'n'))), money($salidas), '100%'] : null]);
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Cuentas -->
<?= rep_seccion('Saldo por cuenta', 'Efectivo, bancos y demás cuentas registradas', 'bank', 'indigo',
    can('finanzas.ver') ? '<a href="' . e(url('modules/finanzas/cuentas.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Administrar</a>' : '') ?>
  <?php
  $tiposCuenta = ['efectivo' => ['Efectivo', 'emerald'], 'banco' => ['Banco', 'blue'], 'tarjeta' => ['Tarjeta', 'violet'],
                  'transferencia' => ['Transferencia', 'cyan'], 'otro' => ['Otro', 'slate']];
  $filas = [];
  foreach ($porCuenta as $c) {
      [$lbl, $col] = $tiposCuenta[$c['tipo']] ?? ['Otro', 'slate'];
      $neto = (float) $c['entradas'] - (float) $c['salidas'];
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($c['nombre']) . '</span>',
          badge($lbl, $col),
          '<span class="text-emerald-600 tabular-nums">' . money($c['entradas']) . '</span>',
          '<span class="text-rose-600 tabular-nums">' . money($c['salidas']) . '</span>',
          '<span class="font-semibold ' . ($neto >= 0 ? 'text-slate-700' : 'text-rose-600') . ' tabular-nums">' . money($neto) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($c['balance']) . '</span>',
      ];
  }
  echo rep_tabla(
      ['Cuenta', ['Tipo', 'center'], ['Entradas', 'right'], ['Salidas', 'right'], ['Flujo neto', 'right'], ['Balance actual', 'right']],
      $filas,
      ['total' => $filas ? ['Total', '', money(array_sum(array_column($porCuenta, 'entradas'))),
          money(array_sum(array_column($porCuenta, 'salidas'))),
          money(array_sum(array_column($porCuenta, 'entradas')) - array_sum(array_column($porCuenta, 'salidas'))),
          money(array_sum(array_column($porCuenta, 'balance')))] : null]
  );
  ?>
<?= rep_fin() ?>

<!-- Caja -->
<?php if ((int) $cajas['n'] > 0): ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
  <div class="card p-5">
    <p class="text-sm text-slate-500">Cierres de caja del periodo</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format((int) $cajas['n']) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-500">Efectivo cobrado en caja</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= money($cajas['efectivo']) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-500">Diferencias de arqueo</p>
    <p class="text-2xl font-extrabold mt-1 <?= abs((float) $cajas['diferencia']) < 0.01 ? 'text-emerald-600' : 'text-rose-600' ?>">
      <?= money($cajas['diferencia']) ?>
    </p>
    <p class="text-xs text-slate-400 mt-1"><?= abs((float) $cajas['diferencia']) < 0.01 ? 'Cuadre perfecto' : 'Revisar cierres con faltante o sobrante' ?></p>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
