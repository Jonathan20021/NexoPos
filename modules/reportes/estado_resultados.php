<?php
/**
 * Estado de resultados (P&L).
 *
 * Cuentas del periodo con % sobre ingresos y comparación contra el periodo
 * anterior, más la evolución mensual de los últimos 12 meses.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.finanzas');

$p = rep_periodo('mes');
[$scope, $scopeP]   = rep_scope('v.sucursal_id');
[$scopeD, $scopeDP] = rep_scope('d.sucursal_id');
[$scopeT, $scopeTP] = rep_scope('t.sucursal_id');

/** Cuentas del estado de resultados para un rango. */
function er_cuentas(string $ini, string $fin, string $scope, array $scopeP, string $scopeD, array $scopeDP, string $scopeT, array $scopeTP): array
{
    $v = qOne(
        "SELECT COALESCE(SUM(v.subtotal),0) bruto, COALESCE(SUM(v.descuento),0) descuento,
                COALESCE(SUM(v.costo_total),0) costo, COALESCE(SUM(v.itbis),0) itbis, COUNT(*) n
           FROM ventas v
          WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope",
        array_merge([$ini, $fin], $scopeP)
    ) ?: [];
    $dev = (float) qVal(
        "SELECT COALESCE(SUM(d.subtotal),0) FROM devoluciones d WHERE d.created_at BETWEEN ? AND ? AND $scopeD",
        array_merge([$ini, $fin], $scopeDP)
    );

    $fd = substr($ini, 0, 10);
    $fh = substr($fin, 0, 10);
    $gastosCat = qAll(
        "SELECT COALESCE(cf.nombre,'Sin clasificar') AS categoria, COALESCE(SUM(t.monto),0) AS monto
           FROM transacciones t
           LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
          WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT
          GROUP BY t.categoria_id ORDER BY monto DESC",
        array_merge([$fd, $fh], $scopeTP)
    );
    $otros = qAll(
        "SELECT COALESCE(cf.nombre,'Otros ingresos') AS categoria, COALESCE(SUM(t.monto),0) AS monto
           FROM transacciones t
           LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
          WHERE " . rep_where_otros_ingresos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT
          GROUP BY t.categoria_id ORDER BY monto DESC",
        array_merge([$fd, $fh], $scopeTP)
    );

    $bruto     = (float) ($v['bruto'] ?? 0);
    $descuento = (float) ($v['descuento'] ?? 0);
    $neto      = $bruto - $descuento - $dev;
    $costo     = (float) ($v['costo'] ?? 0);
    $utilBruta = $neto - $costo;
    $totalGast = array_sum(array_column($gastosCat, 'monto'));
    $totalOtro = array_sum(array_column($otros, 'monto'));

    return [
        'bruto' => $bruto, 'descuento' => $descuento, 'devoluciones' => $dev, 'neto' => $neto,
        'costo' => $costo, 'utilidad_bruta' => $utilBruta,
        'gastos_cat' => $gastosCat, 'gastos' => $totalGast,
        'otros_cat' => $otros, 'otros' => $totalOtro,
        'operativa' => $utilBruta - $totalGast,
        'neta' => $utilBruta - $totalGast + $totalOtro,
        'itbis' => (float) ($v['itbis'] ?? 0), 'facturas' => (int) ($v['n'] ?? 0),
    ];
}

$act = er_cuentas($p['ini'], $p['fin'], $scope, $scopeP, $scopeD, $scopeDP, $scopeT, $scopeTP);
$ant = er_cuentas($p['prev_ini'], $p['prev_fin'], $scope, $scopeP, $scopeD, $scopeDP, $scopeT, $scopeTP);

/* ---------- Evolución mensual ---------- */
$meses = rep_meses_atras(12);
$mIng = qAll(
    "SELECT DATE_FORMAT(v.fecha,'%Y-%m') ym, COALESCE(SUM(v.subtotal - v.descuento),0) ing, COALESCE(SUM(v.costo_total),0) cos
       FROM ventas v WHERE v.estado='completada' AND v.fecha >= ? AND $scope GROUP BY ym",
    array_merge([$meses[0] . '-01 00:00:00'], $scopeP)
);
$mGas = qAll(
    "SELECT DATE_FORMAT(t.fecha,'%Y-%m') ym, COALESCE(SUM(t.monto),0) g
       FROM transacciones t WHERE " . rep_where_gastos() . " AND t.fecha >= ? AND $scopeT GROUP BY ym",
    array_merge([$meses[0] . '-01'], $scopeTP)
);
$iIng = []; foreach ($mIng as $r) $iIng[$r['ym']] = $r;
$iGas = []; foreach ($mGas as $r) $iGas[$r['ym']] = (float) $r['g'];

$labels = $sIng = $sBruta = $sNeta = [];
foreach ($meses as $ym) {
    $ing = (float) ($iIng[$ym]['ing'] ?? 0);
    $cos = (float) ($iIng[$ym]['cos'] ?? 0);
    $gas = $iGas[$ym] ?? 0;
    $labels[]  = rep_mes_label($ym);
    $sIng[]    = $ing;
    $sBruta[]  = $ing - $cos;
    $sNeta[]   = $ing - $cos - $gas;
}

/** Fila del estado de resultados. */
function er_fila(string $label, float $a, float $b, float $baseA, float $baseB, string $estilo = ''): array
{
    $pctA = $baseA > 0 ? $a / $baseA * 100 : 0;
    $pctB = $baseB > 0 ? $b / $baseB * 100 : 0;
    return [$label, $a, $b, $pctA, $pctB, $estilo, rep_delta($a, $b)];
}

$filasER = [
    er_fila('Ventas brutas', $act['bruto'], $ant['bruto'], $act['bruto'], $ant['bruto']),
    er_fila('(−) Descuentos otorgados', -$act['descuento'], -$ant['descuento'], $act['bruto'], $ant['bruto']),
    er_fila('(−) Devoluciones', -$act['devoluciones'], -$ant['devoluciones'], $act['bruto'], $ant['bruto']),
    er_fila('Ingresos netos', $act['neto'], $ant['neto'], $act['neto'], $ant['neto'], 'sub'),
    er_fila('(−) Costo de la mercancía vendida', -$act['costo'], -$ant['costo'], $act['neto'], $ant['neto']),
    er_fila('UTILIDAD BRUTA', $act['utilidad_bruta'], $ant['utilidad_bruta'], $act['neto'], $ant['neto'], 'total'),
];
foreach ($act['gastos_cat'] as $g) {
    $b = 0.0;
    foreach ($ant['gastos_cat'] as $g2) if ($g2['categoria'] === $g['categoria']) $b = (float) $g2['monto'];
    $filasER[] = er_fila('    ' . $g['categoria'], -(float) $g['monto'], -$b, $act['neto'], $ant['neto'], 'detalle');
}
$filasER[] = er_fila('(−) Total gastos operativos', -$act['gastos'], -$ant['gastos'], $act['neto'], $ant['neto'], 'sub');
$filasER[] = er_fila('UTILIDAD OPERATIVA', $act['operativa'], $ant['operativa'], $act['neto'], $ant['neto'], 'total');
if ($act['otros'] > 0 || $ant['otros'] > 0) {
    $filasER[] = er_fila('(+) Otros ingresos', $act['otros'], $ant['otros'], $act['neto'], $ant['neto']);
}
$filasER[] = er_fila('UTILIDAD NETA DEL PERIODO', $act['neta'], $ant['neta'], $act['neto'], $ant['neto'], 'final');

/* ---------- Exportaciones ---------- */
if (quiere_excel()) {
    $filas = [];
    foreach ($filasER as [$lbl, $a, $b, $pa, $pb, $est, $d]) {
        $filas[] = [trim($lbl), money($a, false), number_format($pa, 2), money($b, false), number_format($pb, 2),
                    $d === null ? '—' : number_format($d, 1)];
    }
    export_tabla('estado_resultados_' . $p['desde'] . '_' . $p['hasta'],
        ['Concepto', 'Periodo actual', '% s/ingresos', 'Periodo anterior', '% s/ingresos', 'Variación %'],
        $filas, 'Estado de resultados');
}

if (quiere_pdf() && function_exists('pdf_render')) {
    $H  = pdf_brand_header('ESTADO DE RESULTADOS',
        'Del ' . fechaCorta($p['desde']) . ' al ' . fechaCorta($p['hasta']) . ' · ' . rep_alcance_sucursal());
    $H .= '<table class="tbl"><thead><tr><th>Concepto</th><th class="num">Periodo actual</th><th class="num">%</th><th class="num">Periodo anterior</th><th class="num">%</th></tr></thead><tbody>';
    foreach ($filasER as [$lbl, $a, $b, $pa, $pb, $est]) {
        $bg = $est === 'final' ? ' style="background:#F4F5FB;font-weight:bold"'
            : ($est === 'total' ? ' style="background:#f1f5f9;font-weight:bold"' : '');
        $H .= '<tr' . $bg . '><td>' . htmlspecialchars(trim($lbl)) . '</td>'
            . '<td class="num">' . money($a) . '</td><td class="num">' . number_format($pa, 1) . '%</td>'
            . '<td class="num">' . money($b) . '</td><td class="num">' . number_format($pb, 1) . '%</td></tr>';
    }
    $H .= '</tbody></table>';
    $H .= '<p style="font-size:10px;color:#64748b;margin-top:14px">Nota: el ITBIS facturado ('
        . money($act['itbis']) . ') no forma parte de los ingresos; se recauda por cuenta de la DGII. '
        . 'Las compras de mercancía no figuran como gasto: el costo entra al resultado cuando el producto se vende.</p>';
    pdf_render($H, 'estado_resultados_' . $p['desde'] . '_a_' . $p['hasta'], 'portrait');
}

layout_start('Estado de resultados', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Estado de resultados', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Ingresos netos', 'valor' => money($act['neto']), 'icono' => 'cash', 'color' => 'blue',
     'delta' => rep_delta($act['neto'], $ant['neto']), 'nota' => number_format($act['facturas']) . ' factura(s)'],
    ['label' => 'Utilidad bruta', 'valor' => money($act['utilidad_bruta']), 'icono' => 'trending', 'color' => 'emerald',
     'delta' => rep_delta($act['utilidad_bruta'], $ant['utilidad_bruta']),
     'nota' => ($act['neto'] > 0 ? number_format($act['utilidad_bruta'] / $act['neto'] * 100, 1) : '0') . '% de margen bruto'],
    ['label' => 'Gastos operativos', 'valor' => money($act['gastos']), 'icono' => 'dollar', 'color' => 'amber',
     'delta' => rep_delta($act['gastos'], $ant['gastos']), 'invertir' => true,
     'nota' => ($act['neto'] > 0 ? number_format($act['gastos'] / $act['neto'] * 100, 1) : '0') . '% de los ingresos'],
    ['label' => 'Utilidad neta', 'valor' => money($act['neta']), 'icono' => 'chart',
     'color' => $act['neta'] >= 0 ? 'violet' : 'rose', 'delta' => rep_delta($act['neta'], $ant['neta']),
     'nota' => ($act['neto'] > 0 ? number_format($act['neta'] / $act['neto'] * 100, 1) : '0') . '% de rentabilidad'],
]) ?>

<!-- Estado de resultados -->
<?= rep_seccion('Estado de resultados comparado', 'Del ' . fechaCorta($p['desde']) . ' al ' . fechaCorta($p['hasta'])
    . ' contra ' . fechaCorta($p['prev_desde']) . ' al ' . fechaCorta($p['prev_hasta']), 'receipt', 'blue') ?>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead>
        <tr>
          <th>Concepto</th>
          <th class="text-right">Periodo actual</th>
          <th class="text-right">%</th>
          <th class="text-right">Periodo anterior</th>
          <th class="text-right">%</th>
          <th class="text-center">Variación</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filasER as [$lbl, $a, $b, $pa, $pb, $est, $d]):
          $cls = match ($est) {
            'final'   => 'bg-blue-50/70 font-extrabold text-slate-800',
            'total'   => 'bg-slate-50 font-bold text-slate-800',
            'sub'     => 'font-semibold text-slate-700',
            'detalle' => 'text-slate-500',
            default   => 'text-slate-600',
          };
          $indent = $est === 'detalle' ? 'pl-8' : '';
          $bueno  = str_starts_with(trim($lbl), '(−)') ? ($d !== null && $d <= 0) : ($d !== null && $d >= 0);
        ?>
          <tr class="<?= $cls ?>">
            <td class="<?= $indent ?>"><?= e(trim($lbl)) ?></td>
            <td class="text-right tabular-nums <?= $a < 0 ? 'text-rose-600' : '' ?>"><?= money($a) ?></td>
            <td class="text-right tabular-nums text-slate-400 text-xs"><?= number_format(abs($pa), 1) ?>%</td>
            <td class="text-right tabular-nums text-slate-400"><?= money($b) ?></td>
            <td class="text-right tabular-nums text-slate-400 text-xs"><?= number_format(abs($pb), 1) ?>%</td>
            <td class="text-center">
              <?php if ($d === null): ?><span class="text-slate-300">—</span>
              <?php else: ?>
                <span class="badge <?= $bueno ? 'stat-trend-up' : 'stat-trend-down' ?>">
                  <?= icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') ?> <?= number_format(abs($d), 1) ?>%
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
    <strong class="text-slate-600">Criterio contable.</strong>
    El ITBIS facturado (<?= money($act['itbis']) ?>) no es ingreso: se recauda por cuenta de la DGII y se declara en el IT-1.
    Las compras de mercancía tampoco aparecen como gasto — son inventario; su costo entra al resultado cuando el producto se vende.
  </div>
<?= rep_fin() ?>

<!-- Evolución -->
<?= rep_seccion('Evolución de 12 meses', 'Ingresos, utilidad bruta y utilidad neta mes a mes', 'trending', 'violet') ?>
  <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
    <?= lineChart([
        ['nombre' => 'Ingresos netos', 'color' => marca_app(), 'valores' => $sIng, 'area' => true],
        ['nombre' => 'Utilidad bruta', 'color' => '#10b981', 'valores' => $sBruta],
        ['nombre' => 'Utilidad neta', 'color' => '#8b5cf6', 'valores' => $sNeta],
    ], $labels, ['alto' => 290]) ?>
  </div>
<?= rep_fin() ?>

<!-- Composición del gasto -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <div>
    <?= rep_seccion('En qué se va el dinero', 'Gastos operativos por categoría', 'pie', 'amber') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php if (!$act['gastos_cat']): ?>
          <?= empty_state('Sin gastos en el periodo', 'No se registraron gastos operativos en este rango de fechas.', 'dollar') ?>
        <?php else: ?>
          <?= donutMulti(array_map(
              fn($g, $i) => ['label' => $g['categoria'], 'value' => (float) $g['monto'], 'color' => rep_color($i)],
              $act['gastos_cat'], array_keys($act['gastos_cat'])
          ), 'Gastos', money($act['gastos'], false)) ?>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('Estructura del resultado', 'De cada RD$100 de ingreso, a dónde va', 'scale', 'indigo') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php
        $base = $act['neto'] ?: 1;
        $comp = [
            ['label' => 'Costo de la mercancía', 'value' => $act['costo'], 'color' => '#f43f5e'],
            ['label' => 'Gastos operativos', 'value' => $act['gastos'], 'color' => '#f59e0b'],
            ['label' => 'Utilidad neta', 'value' => max(0, $act['neta']), 'color' => '#10b981'],
        ];
        echo barraApilada($comp);
        ?>
        <div class="mt-5 space-y-3">
          <?php foreach ($comp as $c):
            $pct = $c['value'] / $base * 100; ?>
            <div class="flex items-center justify-between text-sm">
              <span class="inline-flex items-center gap-2 text-slate-600">
                <span class="w-2.5 h-2.5 rounded-full" style="background:<?= $c['color'] ?>"></span><?= e($c['label']) ?>
              </span>
              <span class="font-semibold text-slate-700 tabular-nums"><?= money($c['value'], false) ?>
                <span class="text-slate-400 text-xs">· <?= number_format($pct, 1) ?>%</span></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-5 pt-4 border-t border-slate-100 flex items-center justify-between">
          <span class="text-sm font-bold text-slate-700">Por cada RD$100 facturados quedan</span>
          <span class="text-xl font-extrabold <?= $act['neta'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
            <?= money($act['neto'] > 0 ? $act['neta'] / $act['neto'] * 100 : 0) ?>
          </span>
        </div>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<?php layout_end(); ?>
