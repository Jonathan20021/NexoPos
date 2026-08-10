<?php
/**
 * Reportería de costos.
 *
 * Responde las cuatro preguntas de costo que se hace quien dirige una
 * importadora:
 *
 *   1. ¿Cuánto me costó lo que vendí y qué margen dejó de verdad?
 *   2. ¿Dónde está el dinero parado? (inventario a costo)
 *   3. ¿Cuánto me encarece traer la mercancía? (recargo de importación)
 *   4. ¿Qué estoy vendiendo por debajo de su costo?
 *
 * El costo que usa es el CONGELADO en cada línea de venta
 * (`venta_detalles.costo_unitario`), no el costo de hoy: si se recalculara con
 * el costo actual, aplicar una liquidación reescribiría el margen de meses ya
 * reportados y los números de ayer cambiarían solos.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('direccion.ver');

$p = rep_periodo('anio');
[$scope, $scopeP] = dir_scope('v');
$tiendaId = tiendaFiltroActual();

$tot  = dir_totales($p['ini'], $p['fin'], $scope, $scopeP);
$prev = dir_totales($p['prev_ini'], $p['prev_fin'], $scope, $scopeP);
$inv  = dir_inventario_costo($tiendaId);

// Meses de inventario: cuánto dura lo que hay en almacén al ritmo de venta del
// periodo. Es la cifra que dice si la próxima importación sobra.
$diasPeriodo = max(1, (int) $p['dias']);
$costoDiario = $tot['costo'] / $diasPeriodo;
$diasInventario = $costoDiario > 0 ? $inv['costo'] / $costoDiario : null;

/* ---------- Desgloses ---------- */
$porCategoria = dir_costos_por(
    "COALESCE(c.nombre,'Sin categoría')",
    'LEFT JOIN productos pr ON pr.id = vd.producto_id LEFT JOIN categorias c ON c.id = pr.categoria_id',
    'c.id, c.nombre', $p['ini'], $p['fin'], $scope, $scopeP
);
$porTienda = tiendas_hay() ? dir_costos_por(
    "COALESCE(t.nombre,'Sin marca')",
    'LEFT JOIN tiendas t ON t.id = v.tienda_id',
    'v.tienda_id, t.nombre', $p['ini'], $p['fin'], $scope, $scopeP
) : [];
$topProductos = dir_costos_por(
    "COALESCE(p2.nombre, vd.descripcion)",
    'LEFT JOIN productos p2 ON p2.id = vd.producto_id',
    'p2.id, COALESCE(p2.nombre, vd.descripcion)', $p['ini'], $p['fin'], $scope, $scopeP, 20
);
$bajoCosto = dir_bajo_costo($p['ini'], $p['fin'], $scope, $scopeP);

/* ---------- Evolución de 12 meses ---------- */
$meses = rep_meses_atras(12);
$phMes = [];
$rowsMes = qAll(
    "SELECT DATE_FORMAT(v.fecha,'%Y-%m') ym,
            COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
            COALESCE(SUM(v.costo_total),0) costo
       FROM ventas v
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY DATE_FORMAT(v.fecha,'%Y-%m')",
    array_merge([$meses[0] . '-01 00:00:00', date('Y-m-t 23:59:59')], $scopeP)
);
foreach ($rowsMes as $r) $phMes[$r['ym']] = ['ingresos' => (float) $r['ingresos'], 'costo' => (float) $r['costo']];

/* ---------- Costo de importación (liquidaciones aplicadas) ---------- */
$liqPeriodo = liq_disponible() ? (qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(fob),0) fob, COALESCE(SUM(gastos),0) gastos,
            COALESCE(SUM(gastos_no_costo),0) recuperable, COALESCE(SUM(costo_total),0) total
       FROM liquidaciones
      WHERE estado = 'aplicada' AND fecha BETWEEN ? AND ?"
      . ($tiendaId ? ' AND tienda_id = ' . (int) $tiendaId : ''),
    [$p['desde'], $p['hasta']]
) ?: []) : [];
$fobLiq = (float) ($liqPeriodo['fob'] ?? 0);
$recargoLiq = $fobLiq > 0 ? (float) ($liqPeriodo['gastos'] ?? 0) / $fobLiq * 100 : 0.0;

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    foreach ($porCategoria as $c) {
        $margen = $c['ingresos'] > 0 ? ($c['ingresos'] - $c['costo']) / $c['ingresos'] * 100 : 0;
        $filas[] = [$c['etiqueta'], qty($c['unidades']), money($c['ingresos'], false),
                    money($c['costo'], false), money($c['ingresos'] - $c['costo'], false),
                    number_format($margen, 1) . '%'];
    }
    export_tabla('costos_' . $p['desde'] . '_' . $p['hasta'],
        ['Categoría', 'Unidades', 'Ingresos', 'Costo', 'Utilidad', 'Margen'],
        $filas, 'Reportería de costos');
}

$extraFiltro = tiendas_hay() ? selectTiendaFiltro() : '';
layout_start('Reportería de costos', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Reportería de costos', $p, ['sucursal' => true, 'extra' => $extraFiltro]);

echo rep_kpis([
    [
        'label' => 'Costo de la mercancía vendida', 'valor' => money($tot['costo']),
        'icono' => 'package', 'color' => 'amber', 'invertir' => true,
        'delta' => rep_delta($tot['costo'], $prev['costo']),
        'nota'  => 'Sobre ingresos de <strong>' . money($tot['ingresos']) . '</strong>',
    ],
    [
        'label' => 'Utilidad bruta', 'valor' => money($tot['utilidad']),
        'icono' => 'trending', 'color' => 'emerald',
        'delta' => rep_delta($tot['utilidad'], $prev['utilidad']),
        'nota'  => 'Margen <strong>' . number_format($tot['margen'], 1) . '%</strong>'
                 . ' · antes ' . number_format($prev['margen'], 1) . '%',
    ],
    [
        'label' => 'Inventario a costo', 'valor' => money($inv['costo']),
        'icono' => 'box', 'color' => 'blue',
        'nota'  => $diasInventario !== null
            ? 'Alcanza para <strong>' . number_format($diasInventario, 0) . ' días</strong> al ritmo actual'
            : 'Sin ventas en el periodo para estimar la rotación',
    ],
    [
        'label' => 'Recargo de importación', 'valor' => number_format($recargoLiq, 1) . '%',
        'icono' => 'truck', 'color' => 'violet',
        'nota'  => (int) ($liqPeriodo['n'] ?? 0) > 0
            ? money($liqPeriodo['gastos']) . ' de gastos sobre ' . money($fobLiq) . ' de mercancía'
            : 'Sin liquidaciones aplicadas en el periodo',
    ],
], 4);
?>

<!-- Evolución -->
<?= rep_seccion('Ingresos, costo y margen', 'Últimos 12 meses', 'chart', 'indigo') ?>
  <div class="px-5 pb-5 flex-1 flex flex-col justify-center overflow-x-auto">
    <?php
    $labels = []; $sIng = []; $sCos = [];
    foreach ($meses as $ym) {
        $labels[] = rep_mes_label($ym);
        $sIng[] = $phMes[$ym]['ingresos'] ?? 0;
        $sCos[] = $phMes[$ym]['costo'] ?? 0;
    }
    echo lineChart([
        ['nombre' => 'Ingresos', 'color' => '#2563eb', 'valores' => $sIng],
        ['nombre' => 'Costo',    'color' => '#f59e0b', 'valores' => $sCos],
    ], $labels, ['alto' => 260]);
    ?>
  </div>
<?= rep_fin() ?>

<div class="grid grid-cols-1 <?= $porTienda ? 'lg:grid-cols-2' : '' ?> gap-5">
  <?php if ($porTienda): ?>
    <div>
      <?= rep_seccion('Costo y margen por tienda', 'Qué marca deja más después del costo', 'tag', 'violet') ?>
        <?php
        $filas = [];
        foreach ($porTienda as $t) {
            $u = $t['ingresos'] - $t['costo'];
            $m = $t['ingresos'] > 0 ? $u / $t['ingresos'] * 100 : 0;
            $filas[] = [
                '<span class="font-semibold text-slate-700">' . e($t['etiqueta']) . '</span>',
                '<span class="tabular-nums">' . money($t['ingresos']) . '</span>',
                '<span class="tabular-nums text-slate-500">' . money($t['costo']) . '</span>',
                '<span class="tabular-nums font-bold ' . ($u >= 0 ? 'text-slate-800' : 'text-rose-600') . '">' . money($u) . '</span>',
                '<span class="font-semibold ' . ($m < 0 ? 'text-rose-600' : ($m < 15 ? 'text-amber-600' : 'text-emerald-600')) . '">'
                  . number_format($m, 1) . '%</span>',
            ];
        }
        echo rep_tabla(['Tienda', ['Ingresos', 'right'], ['Costo', 'right'], ['Utilidad', 'right'], ['Margen', 'right']], $filas);
        ?>
      <?= rep_fin() ?>
    </div>
  <?php endif; ?>

  <div>
    <?= rep_seccion('Costo por categoría', 'Dónde se concentra el costo de lo vendido', 'layers', 'blue') ?>
      <?php
      $filas = []; $totCat = array_sum(array_column($porCategoria, 'costo'));
      foreach ($porCategoria as $i => $c) {
          $u = $c['ingresos'] - $c['costo'];
          $m = $c['ingresos'] > 0 ? $u / $c['ingresos'] * 100 : 0;
          $peso = $totCat > 0 ? $c['costo'] / $totCat * 100 : 0;
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($c['etiqueta']) . '</span>',
              '<span class="tabular-nums text-slate-500">' . qty($c['unidades']) . '</span>',
              '<span class="tabular-nums">' . money($c['costo']) . '</span>',
              '<span class="tabular-nums text-slate-400">' . number_format($peso, 1) . '%</span>',
              '<span class="font-semibold ' . ($m < 0 ? 'text-rose-600' : ($m < 15 ? 'text-amber-600' : 'text-emerald-600')) . '">'
                . number_format($m, 1) . '%</span>',
          ];
      }
      echo rep_tabla(
          ['Categoría', ['Unidades', 'right'], ['Costo', 'right'], ['Peso', 'right'], ['Margen', 'right']],
          $filas,
          ['total' => ['Total', '', '<span class="tabular-nums">' . money($totCat) . '</span>', '100%',
                       '<span>' . number_format($tot['margen'], 1) . '%</span>']]
      );
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Bajo costo: la alerta que justifica toda la pantalla -->
<?php if ($bajoCosto): ?>
  <?= rep_seccion('Se está vendiendo por debajo del costo', 'Precio de lista desactualizado frente al costo real', 'alert', 'rose') ?>
    <?php
    $filas = []; $perdida = 0.0;
    foreach ($bajoCosto as $b) {
        $dif = (float) $b['costo'] - (float) $b['ingresos'];
        $perdida += $dif;
        // Precio mínimo para dejar el mismo margen que el catálogo pretende.
        $costoUnit = (float) $b['unidades'] > 0 ? (float) $b['costo'] / (float) $b['unidades'] : 0;
        $filas[] = [
            '<span class="font-semibold text-slate-700">' . e($b['nombre']) . '</span>'
              . '<br><span class="text-xs text-slate-400 font-mono">' . e($b['codigo']) . '</span>',
            '<span class="tabular-nums text-slate-500">' . qty($b['unidades']) . '</span>',
            '<span class="tabular-nums">' . money($b['precio_venta']) . '</span>',
            '<span class="tabular-nums text-rose-600 font-semibold">' . money($costoUnit) . '</span>',
            '<span class="tabular-nums text-rose-600 font-bold">−' . money($dif, false) . '</span>',
            can('productos.editar')
                ? '<a href="' . e(url('modules/inventario/productos.php')) . '?q=' . urlencode((string) $b['codigo'])
                  . '" class="btn btn-ghost btn-sm">Repreciar</a>'
                : '',
        ];
    }
    echo rep_tabla(
        ['Producto', ['Unidades', 'right'], ['Precio de lista', 'right'], ['Costo real unit.', 'right'], ['Pérdida', 'right'], ''],
        $filas,
        ['total' => ['Pérdida acumulada del periodo', '', '', '', '<span class="text-rose-600">−' . money($perdida, false) . '</span>', '']]
    );
    ?>
  <?= rep_fin() ?>
<?php endif; ?>

<!-- Top de productos por costo -->
<?= rep_seccion('Los 20 artículos que más costo consumen', 'Donde se va el dinero de la mercancía', 'package', 'amber') ?>
  <?php
  $filas = [];
  foreach ($topProductos as $t) {
      $u = $t['ingresos'] - $t['costo'];
      $m = $t['ingresos'] > 0 ? $u / $t['ingresos'] * 100 : 0;
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($t['etiqueta']) . '</span>',
          '<span class="tabular-nums text-slate-500">' . qty($t['unidades']) . '</span>',
          '<span class="tabular-nums">' . money($t['ingresos']) . '</span>',
          '<span class="tabular-nums text-slate-500">' . money($t['costo']) . '</span>',
          '<span class="tabular-nums font-bold ' . ($u >= 0 ? 'text-slate-800' : 'text-rose-600') . '">' . money($u) . '</span>',
          '<span class="font-semibold ' . ($m < 0 ? 'text-rose-600' : ($m < 15 ? 'text-amber-600' : 'text-emerald-600')) . '">'
            . number_format($m, 1) . '%</span>',
      ];
  }
  echo rep_tabla(
      ['Producto', ['Unidades', 'right'], ['Ingresos', 'right'], ['Costo', 'right'], ['Utilidad', 'right'], ['Margen', 'right']],
      $filas
  );
  ?>
<?= rep_fin() ?>

<!-- Costo de importación del periodo -->
<?php if ((int) ($liqPeriodo['n'] ?? 0) > 0): ?>
  <?= rep_seccion('Costo de traer la mercancía', number_format((int) $liqPeriodo['n']) . ' liquidación(es) aplicada(s) en el periodo', 'truck', 'violet') ?>
    <div class="px-5 pb-5 grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="rounded-xl border border-slate-200 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Mercancía (FOB)</p>
        <p class="text-lg font-extrabold text-slate-800 mt-1 tabular-nums"><?= money($fobLiq) ?></p>
      </div>
      <div class="rounded-xl border border-slate-200 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Gastos al costo</p>
        <p class="text-lg font-extrabold text-slate-800 mt-1 tabular-nums"><?= money($liqPeriodo['gastos']) ?></p>
        <p class="text-xs text-slate-400 mt-0.5">flete, seguro, arancel, aduana</p>
      </div>
      <div class="rounded-xl border border-slate-200 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">ITBIS recuperable</p>
        <p class="text-lg font-extrabold text-slate-500 mt-1 tabular-nums"><?= money($liqPeriodo['recuperable']) ?></p>
        <p class="text-xs text-slate-400 mt-0.5">no es costo: se compensa</p>
      </div>
      <div class="rounded-xl border border-violet-200 bg-violet-50/50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-violet-700">Costo puesto en almacén</p>
        <p class="text-lg font-extrabold text-violet-700 mt-1 tabular-nums"><?= money($liqPeriodo['total']) ?></p>
        <p class="text-xs text-violet-600/80 mt-0.5">+<?= number_format($recargoLiq, 1) ?>% sobre el FOB</p>
      </div>
    </div>
    <div class="px-5 pb-5">
      <a href="<?= e(url('modules/inventario/liquidaciones.php')) ?>" class="btn btn-soft btn-sm no-print">
        <?= icon('chevron-right', 'w-3.5 h-3.5') ?> Ver las liquidaciones
      </a>
    </div>
  <?= rep_fin() ?>
<?php endif; ?>

<?php layout_end(); ?>
