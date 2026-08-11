<?php
/**
 * Panel de Dirección — la pantalla de la CEO.
 *
 * Una sola vista con lo que hace falta para decidir: cómo va el año contra el
 * anterior, cómo va este mes contra el mismo mes del año pasado, qué marca
 * aporta qué, cuánto cuesta de verdad la mercancía y qué viene en camino.
 *
 * Todo lo que se muestra aquí enlaza a la pantalla que lo explica en detalle:
 * el panel resume, no reemplaza.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('direccion.ver');

[$scope, $scopeP] = dir_scope('v');
$tiendaId = tiendaFiltroActual();

$hoy    = date('Y-m-d');
$anio   = (int) date('Y');
$anioAnt = $anio - 1;
$mes    = (int) date('n');

// --- Año en curso contra el mismo tramo del año pasado ---
$diaMes   = min((int) date('j'), (int) date('t', mktime(0, 0, 0, $mes, 1, $anioAnt)));
$hastaAnt = sprintf('%04d-%02d-%02d 23:59:59', $anioAnt, $mes, $diaMes);

$anioAct = dir_totales($anio . '-01-01 00:00:00', $hoy . ' 23:59:59', $scope, $scopeP);
$anioPas = dir_totales($anioAnt . '-01-01 00:00:00', $hastaAnt, $scope, $scopeP);

// --- Mes en curso contra el mismo mes del año pasado ---
$mesIni = date('Y-m-01 00:00:00');
$mesAct = dir_totales($mesIni, $hoy . ' 23:59:59', $scope, $scopeP);
$mesPasIni = sprintf('%04d-%02d-01 00:00:00', $anioAnt, $mes);
$mesPasFin = sprintf('%04d-%02d-%02d 23:59:59', $anioAnt, $mes, $diaMes);
$mesPas = dir_totales($mesPasIni, $mesPasFin, $scope, $scopeP);

// --- Mes anterior completo, para el «mes contra mes» ---
$mAntIni = date('Y-m-01 00:00:00', strtotime('first day of last month'));
$mAntFin = date('Y-m-t 23:59:59', strtotime('last day of last month'));
$mesAnterior = dir_totales($mAntIni, $mAntFin, $scope, $scopeP);

// --- Serie de 24 meses ---
$meses24 = rep_meses_atras(24);
$mapa = [];
foreach (qAll(
    "SELECT DATE_FORMAT(v.fecha,'%Y-%m') ym,
            COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
            COALESCE(SUM(v.costo_total),0) costo
       FROM ventas v
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY DATE_FORMAT(v.fecha,'%Y-%m')",
    array_merge([$meses24[0] . '-01 00:00:00', date('Y-m-t 23:59:59')], $scopeP)
) as $r) {
    $mapa[$r['ym']] = ['ingresos' => (float) $r['ingresos'], 'costo' => (float) $r['costo']];
}

// --- Por tienda, en el año ---
$porTienda = tiendas_hay() ? dir_dimension(
    "COALESCE(t.nombre,'Sin marca')", 'LEFT JOIN tiendas t ON t.id = v.tienda_id',
    'v.tienda_id, t.nombre',
    [$anio . '-01-01 00:00:00', $hoy . ' 23:59:59'],
    [$anioAnt . '-01-01 00:00:00', $hastaAnt],
    $scope, $scopeP
) : [];

// --- Mercancía en camino y en liquidación ---
$liq = liq_resumen($tiendaId);
$inv = dir_inventario_costo($tiendaId);

// --- Últimas cargas históricas ---
$lotes = can('direccion.importar') ? array_slice(imp_lotes(4), 0, 4) : [];

layout_start('Panel de Dirección', 'Cómo va el negocio · ' . rep_alcance_sucursal());
?>

<!-- Filtro -->
<?php if (tiendas_hay() || count(sucursales_visibles()) > 1): ?>
  <form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3 no-print">
    <?php $selSuc = selectSucursalFiltro(); ?>
    <?php if ($selSuc): ?><div><span class="label">Sucursal</span><?= $selSuc ?></div><?php endif; ?>
    <?php if (tiendas_hay()): ?><div><span class="label">Tienda</span><?= selectTiendaFiltro() ?></div><?php endif; ?>
    <button class="btn btn-ghost btn-sm"><?= icon('filter', 'w-3.5 h-3.5') ?> Aplicar</button>
  </form>
<?php endif; ?>

<!-- Año contra año -->
<?= rep_kpis([
    [
        'label' => 'Ingresos del año ' . $anio, 'valor' => money($anioAct['ingresos']),
        'icono' => 'dollar', 'color' => 'emerald',
        'delta' => rep_delta($anioAct['ingresos'], $anioPas['ingresos']),
        'nota'  => 'Mismo tramo de ' . $anioAnt . ': <strong>' . money($anioPas['ingresos']) . '</strong>',
    ],
    [
        'label' => 'Utilidad bruta del año', 'valor' => money($anioAct['utilidad']),
        'icono' => 'trending', 'color' => 'blue',
        'delta' => rep_delta($anioAct['utilidad'], $anioPas['utilidad']),
        'nota'  => 'Margen <strong>' . number_format($anioAct['margen'], 1) . '%</strong> · antes ' . number_format($anioPas['margen'], 1) . '%',
    ],
    [
        'label' => 'Facturas del año', 'valor' => number_format($anioAct['facturas']),
        'icono' => 'receipt', 'color' => 'violet',
        'delta' => rep_delta($anioAct['facturas'], $anioPas['facturas']),
        'nota'  => 'Ticket promedio <strong>' . money($anioAct['ticket']) . '</strong>',
    ],
    [
        'label' => 'Inventario a costo', 'valor' => money($inv['costo']),
        'icono' => 'box', 'color' => 'amber',
        'nota'  => qty($inv['unidades']) . ' unidades · valor de venta ' . money($inv['venta']),
    ],
]) ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

  <!-- Curva de 24 meses -->
  <div class="lg:col-span-2">
    <?= rep_seccion('Dos años de ventas', 'Ingresos netos y costo, mes a mes', 'chart', 'indigo',
        '<a href="' . e(url('modules/direccion/comparativo.php')) . '" class="btn btn-ghost btn-sm no-print">' . icon('chevron-right', 'w-3.5 h-3.5') . ' Año contra año</a>') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center overflow-x-auto">
        <?php
        $labels = []; $sIng = []; $sCos = [];
        foreach ($meses24 as $ym) {
            $labels[] = rep_mes_label($ym);
            $sIng[] = $mapa[$ym]['ingresos'] ?? 0;
            $sCos[] = $mapa[$ym]['costo'] ?? 0;
        }
        echo lineChart([
            ['nombre' => 'Ingresos', 'color' => marca_app(), 'valores' => $sIng],
            ['nombre' => 'Costo',    'color' => '#f59e0b', 'valores' => $sCos],
        ], $labels, ['alto' => 300]);
        ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <!-- Comparativos rápidos -->
  <div>
    <?= rep_seccion('Comparativos', 'Los dos que se miran todos los días', 'calendar', 'blue') ?>
      <div class="px-5 pb-5 space-y-4">
        <?php
        $bloques = [
            [
                'titulo' => mesNombre($mes) . ' ' . $anio . ' vs ' . mesNombre($mes) . ' ' . $anioAnt,
                'sub'    => 'Mismo tramo del mes',
                'a' => $mesAct, 'b' => $mesPas,
            ],
            [
                'titulo' => mesNombre($mes) . ' vs ' . mesNombre($mes === 1 ? 12 : $mes - 1),
                'sub'    => 'Contra el mes anterior completo',
                'a' => $mesAct, 'b' => $mesAnterior,
            ],
        ];
        foreach ($bloques as $bl):
            $d = rep_delta($bl['a']['ingresos'], $bl['b']['ingresos']);
        ?>
          <div class="rounded-xl border border-slate-200 p-4">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-700 leading-tight"><?= e($bl['titulo']) ?></p>
                <p class="text-xs text-slate-400"><?= e($bl['sub']) ?></p>
              </div>
              <?php if ($d !== null): ?>
                <span class="badge <?= $d >= 0 ? 'stat-trend-up' : 'stat-trend-down' ?> shrink-0">
                  <?= icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') ?> <?= number_format(abs($d), 1) ?>%
                </span>
              <?php endif; ?>
            </div>
            <div class="flex items-baseline gap-2 mt-2">
              <p class="text-xl font-extrabold text-slate-800 tabular-nums"><?= money($bl['a']['ingresos']) ?></p>
              <p class="text-sm text-slate-400 tabular-nums">vs <?= money($bl['b']['ingresos']) ?></p>
            </div>
            <div class="flex items-center justify-between text-xs text-slate-500 mt-1.5">
              <span>Margen <?= number_format($bl['a']['margen'], 1) ?>%</span>
              <span><?= number_format($bl['a']['facturas']) ?> factura(s)</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

  <!-- Por tienda -->
  <?php if ($porTienda): ?>
    <div>
      <?= rep_seccion('Ventas por tienda', 'Año ' . $anio . ' contra el mismo tramo de ' . $anioAnt, 'tag', 'violet',
          can('tiendas.ver') ? '<a href="' . e(url('modules/admin/tiendas.php')) . '" class="btn btn-ghost btn-sm no-print">Administrar</a>' : '') ?>
        <?php
        $filas = [];
        $maxT = max(array_map(fn($v) => $v['a'], $porTienda) ?: [1]);
        foreach ($porTienda as $nombre => $v) {
            $d = rep_delta($v['a'], $v['b']);
            $filas[] = [
                '<span class="font-semibold text-slate-700">' . e($nombre) . '</span>'
                  . '<div class="h-1.5 rounded-full bg-slate-100 overflow-hidden mt-1.5 max-w-[220px]">'
                  . '<div class="h-full rounded-full bg-violet-500" style="width:' . max(1, $maxT > 0 ? $v['a'] / $maxT * 100 : 0) . '%"></div></div>',
                '<span class="font-bold text-slate-800 tabular-nums">' . money($v['a']) . '</span>',
                '<span class="text-slate-500 tabular-nums">' . money($v['b']) . '</span>',
                $d === null ? '<span class="text-slate-300">—</span>'
                    : '<span class="badge ' . ($d >= 0 ? 'stat-trend-up' : 'stat-trend-down') . '">'
                      . icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') . ' ' . number_format(abs($d), 1) . '%</span>',
            ];
        }
        echo rep_tabla(['Tienda', [(string) $anio, 'right'], [(string) $anioAnt, 'right'], ['Var.', 'center']], $filas,
            ['vacio' => 'Todavía no hay ventas asociadas a una tienda.']);
        ?>
      <?= rep_fin() ?>
    </div>
  <?php endif; ?>

  <!-- Mercancía y costos -->
  <div>
    <?= rep_seccion('Mercancía y costos', 'Lo que viene, lo que está y lo que cuesta', 'truck', 'amber',
        can('liquidaciones.ver') ? '<a href="' . e(url('modules/inventario/liquidaciones.php')) . '" class="btn btn-ghost btn-sm no-print">' . icon('chevron-right', 'w-3.5 h-3.5') . ' Liquidaciones</a>' : '') ?>
      <div class="px-5 pb-5 space-y-3 flex-1">
        <div class="grid grid-cols-2 gap-3">
          <div class="rounded-xl border border-slate-200 p-3.5">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">En tránsito</p>
            <p class="text-xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= number_format($liq['transito']) ?></p>
            <p class="text-xs text-slate-400 mt-0.5"><?= money($liq['transito_valor']) ?> en camino</p>
          </div>
          <div class="rounded-xl border <?= $liq['borradores'] > 0 ? 'border-amber-200 bg-amber-50/50' : 'border-slate-200' ?> p-3.5">
            <p class="text-xs font-bold uppercase tracking-wide <?= $liq['borradores'] > 0 ? 'text-amber-700' : 'text-slate-400' ?>">Sin liquidar</p>
            <p class="text-xl font-extrabold mt-1 tabular-nums <?= $liq['borradores'] > 0 ? 'text-amber-700' : 'text-slate-800' ?>"><?= number_format($liq['borradores']) ?></p>
            <p class="text-xs text-slate-400 mt-0.5">borrador(es) pendientes</p>
          </div>
          <div class="rounded-xl border border-slate-200 p-3.5">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Costeado este mes</p>
            <p class="text-xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= money($liq['costo_mes']) ?></p>
            <p class="text-xs text-slate-400 mt-0.5"><?= number_format($liq['aplicadas_mes']) ?> embarque(s)</p>
          </div>
          <div class="rounded-xl border border-slate-200 p-3.5">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Recargo de traerla</p>
            <p class="text-xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= number_format($liq['recargo_pct'], 1) ?>%</p>
            <p class="text-xs text-slate-400 mt-0.5">sobre el valor de la mercancía</p>
          </div>
        </div>
        <div class="rounded-xl bg-slate-50 border border-slate-200 p-3.5">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-slate-700">Costo de lo vendido este año</p>
              <p class="text-xs text-slate-400">Margen bruto <?= number_format($anioAct['margen'], 1) ?>%</p>
            </div>
            <p class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($anioAct['costo']) ?></p>
          </div>
          <a href="<?= e(url('modules/direccion/costos.php')) ?>" class="btn btn-soft btn-sm w-full mt-3 no-print">
            <?= icon('coins', 'w-3.5 h-3.5') ?> Ver la reportería de costos
          </a>
        </div>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Cargas históricas -->
<?php if (can('direccion.importar')): ?>
  <?= rep_seccion('Datos históricos cargados', 'Ventas y clientes traídos del sistema anterior', 'download', 'slate',
      '<a href="' . e(url('modules/direccion/importar.php')) . '" class="btn btn-soft btn-sm no-print">' . icon('plus', 'w-3.5 h-3.5') . ' Cargar archivo</a>') ?>
    <?php
    $filas = [];
    foreach ($lotes as $l) {
        $filas[] = [
            '<span class="font-mono text-sm">#' . (int) $l['id'] . '</span>',
            '<span class="text-slate-700">' . e($l['tipo'] === 'clientes' ? 'Clientes' : 'Ventas') . '</span>',
            '<span class="text-slate-500 text-sm">' . e($l['archivo'] ?: '—') . '</span>',
            '<span class="tabular-nums">' . number_format((int) $l['creados']) . '</span>',
            '<span class="tabular-nums">' . ((float) $l['monto'] > 0 ? money($l['monto'], false) : '—') . '</span>',
            '<span class="text-slate-400 text-sm">' . fechaCorta($l['created_at']) . '</span>',
            $l['estado'] === 'revertida' ? badge('Revertida', 'rose') : badge('Procesada', 'emerald'),
        ];
    }
    echo rep_tabla(
        ['Lote', 'Tipo', 'Archivo', ['Registros', 'right'], ['Monto', 'right'], 'Fecha', 'Estado'],
        $filas,
        ['vacio' => 'Cuando cargues un año de ventas del sistema anterior, aparecerá aquí.',
         'vacio_titulo' => 'Sin cargas todavía', 'vacio_icono' => 'download']
    );
    ?>
  <?= rep_fin() ?>
<?php endif; ?>

<?php layout_end(); ?>
