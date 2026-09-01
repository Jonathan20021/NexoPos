<?php
/**
 * Inventario valorizado: cuánto dinero hay dormido en los estantes.
 * A costo (lo que vale en el balance) y a precio de venta (lo que puede generar).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
// Quien revisa existencias no necesita el libro diario ni la nómina, que es lo
// que arrastraba el paquete de contabilidad.
require_any_perm(['reportes.contabilidad', 'reportes.inventario']);

$p = rep_periodo('mes');
[$scopeS, $scopeSP] = rep_scope('s.sucursal_id');
[$scopeV, $scopeVP] = rep_scope('v.sucursal_id');
$vista = in_array(get('vista'), ['producto', 'categoria', 'sucursal', 'marca'], true) ? get('vista') : 'categoria';

/* ---------- Totales ---------- */
$tot = qOne(
    "SELECT COALESCE(SUM(s.cantidad),0) unidades,
            COALESCE(SUM(s.cantidad * pr.precio_compra),0) costo,
            COALESCE(SUM(s.cantidad * pr.precio_venta),0) venta,
            COUNT(DISTINCT pr.id) productos
       FROM inventario_stock s JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1
      WHERE $scopeS",
    $scopeSP
) ?: ['unidades' => 0, 'costo' => 0, 'venta' => 0, 'productos' => 0];
$valorCosto = (float) $tot['costo'];
$valorVenta = (float) $tot['venta'];
$utilPot    = $valorVenta - $valorCosto;

// Costo de la mercancía vendida del periodo → rotación y días de inventario.
$cmv = (float) qVal(
    "SELECT COALESCE(SUM(v.costo_total),0) FROM ventas v
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scopeV",
    array_merge([$p['ini'], $p['fin']], $scopeVP)
);
$rotacion = $valorCosto > 0 ? $cmv / $valorCosto : 0;
$diasInv  = $cmv > 0 ? $valorCosto / ($cmv / max(1, $p['dias'])) : null;

/* ---------- Agrupación ---------- */
$config = [
    'categoria' => ["COALESCE(c.nombre,'Sin categoría')", 'LEFT JOIN categorias c ON c.id = pr.categoria_id', 'c.id', 'Categoría', 'tag'],
    'sucursal'  => ['su.nombre', 'JOIN sucursales su ON su.id = s.sucursal_id', 's.sucursal_id', 'Sucursal', 'store'],
    'marca'     => ["COALESCE(mc.nombre,'Sin marca')", 'LEFT JOIN marcas mc ON mc.id = pr.marca_id', 'mc.id', 'Marca', 'layers'],
    'producto'  => ['pr.nombre', '', 'pr.id', 'Producto', 'package'],
];
[$sel, $join, $group, $etiqueta, $icono] = $config[$vista];

$grupos = qAll(
    "SELECT $sel AS etiqueta,
            COALESCE(SUM(s.cantidad),0) unidades,
            COALESCE(SUM(s.cantidad * pr.precio_compra),0) costo,
            COALESCE(SUM(s.cantidad * pr.precio_venta),0) venta,
            COUNT(DISTINCT pr.id) productos
       FROM inventario_stock s
       JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1
       $join
      WHERE $scopeS
      GROUP BY $group HAVING costo > 0 OR unidades > 0
      ORDER BY costo DESC",
    $scopeSP
);

/* ---------- Detalle por producto ---------- */
$pg = paginar((int) qVal(
    "SELECT COUNT(DISTINCT pr.id) FROM inventario_stock s JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1 WHERE $scopeS",
    $scopeSP
), 40);
$detalle = qAll(
    "SELECT pr.id, pr.codigo, pr.nombre, pr.precio_compra, pr.precio_venta, pr.stock_minimo,
            COALESCE(c.nombre,'Sin categoría') AS categoria,
            COALESCE(SUM(s.cantidad),0) AS cantidad,
            COALESCE(SUM(s.cantidad * pr.precio_compra),0) AS costo,
            COALESCE(SUM(s.cantidad * pr.precio_venta),0) AS venta,
            (SELECT MAX(mi.created_at) FROM movimientos_inventario mi WHERE mi.producto_id = pr.id AND mi.tipo = 'venta') AS ultima_venta
       FROM inventario_stock s
       JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1
       LEFT JOIN categorias c ON c.id = pr.categoria_id
      WHERE $scopeS
      GROUP BY pr.id
      ORDER BY costo DESC
      LIMIT " . (int) $pg['porPagina'] . " OFFSET " . (int) $pg['offset'],
    $scopeSP
);

/* ---------- Inventario sin rotación ---------- */
$sinRotacion = qAll(
    "SELECT pr.nombre, pr.codigo, COALESCE(SUM(s.cantidad),0) cantidad,
            COALESCE(SUM(s.cantidad * pr.precio_compra),0) costo,
            (SELECT MAX(mi.created_at) FROM movimientos_inventario mi WHERE mi.producto_id = pr.id AND mi.tipo = 'venta') AS ultima_venta
       FROM inventario_stock s JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1
      WHERE $scopeS
      GROUP BY pr.id
     HAVING cantidad > 0 AND (ultima_venta IS NULL OR ultima_venta < (NOW() - INTERVAL 90 DAY))
      ORDER BY costo DESC LIMIT 20",
    $scopeSP
);
$capitalDormido = array_sum(array_column($sinRotacion, 'costo'));

/* ---------- Quiebres ---------- */
$quiebres = qOne(
    "SELECT SUM(CASE WHEN s.cantidad <= 0 THEN 1 ELSE 0 END) agotados,
            SUM(CASE WHEN s.cantidad > 0 AND pr.stock_minimo > 0 AND s.cantidad <= pr.stock_minimo THEN 1 ELSE 0 END) bajos
       FROM inventario_stock s JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1 AND pr.tipo = 'producto'
      WHERE $scopeS",
    $scopeSP
) ?: ['agotados' => 0, 'bajos' => 0];

if (export_solicitado()) {
    $todos = qAll(
        "SELECT pr.codigo, pr.nombre, COALESCE(c.nombre,'Sin categoría') categoria, su.nombre sucursal,
                s.cantidad, pr.precio_compra, pr.precio_venta,
                (s.cantidad * pr.precio_compra) costo, (s.cantidad * pr.precio_venta) venta
           FROM inventario_stock s
           JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1
           JOIN sucursales su ON su.id = s.sucursal_id
           LEFT JOIN categorias c ON c.id = pr.categoria_id
          WHERE $scopeS ORDER BY pr.nombre",
        $scopeSP
    );
    $filas = [];
    foreach ($todos as $t) {
        $filas[] = [$t['codigo'], $t['nombre'], $t['categoria'], $t['sucursal'], qty($t['cantidad']),
            money($t['precio_compra'], false), money($t['precio_venta'], false),
            money($t['costo'], false), money($t['venta'], false),
            money((float) $t['venta'] - (float) $t['costo'], false)];
    }
    export_tabla('inventario_valorizado_' . date('Y-m-d'),
        ['Código', 'Producto', 'Categoría', 'Sucursal', 'Existencia', 'Costo unit.', 'Precio unit.', 'Valor a costo', 'Valor a venta', 'Utilidad potencial'],
        $filas, 'Inventario valorizado');
}

$tabs = '';
foreach ($config as $k => $c) {
    $qs = array_merge($_GET, ['vista' => $k]);
    $tabs .= '<a href="?' . e(http_build_query($qs)) . '" class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition '
        . ($vista === $k ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800') . '">' . e($c[3]) . '</a>';
}

layout_start('Inventario valorizado', 'Existencias al ' . fechaCorta(date('Y-m-d')) . ' · ' . rep_alcance_sucursal(), rep_barra_titulo());
echo rep_encabezado_impresion('Inventario valorizado', $p);
echo rep_filtros($p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Valor a costo', 'valor' => money($valorCosto), 'icono' => 'box', 'color' => 'blue',
     'nota' => qty($tot['unidades']) . ' unidades · ' . number_format((int) $tot['productos']) . ' productos'],
    ['label' => 'Valor a precio de venta', 'valor' => money($valorVenta), 'icono' => 'cash', 'color' => 'emerald',
     'nota' => 'Si se vendiera todo el inventario'],
    ['label' => 'Utilidad potencial', 'valor' => money($utilPot), 'icono' => 'trending', 'color' => 'violet',
     'nota' => 'Margen ' . number_format($valorVenta > 0 ? $utilPot / $valorVenta * 100 : 0, 1) . '% sobre venta'],
    ['label' => 'Días de inventario', 'valor' => $diasInv === null ? '—' : number_format($diasInv, 0),
     'icono' => 'clock', 'color' => ($diasInv !== null && $diasInv > 120) ? 'rose' : 'amber',
     'nota' => 'Rotación ' . number_format($rotacion, 2) . 'x en el periodo'],
]) ?>

<?php if ($capitalDormido > 0): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-amber-200 bg-amber-50/40">
    <span class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-4 h-4') ?></span>
    <p class="text-sm text-slate-700">
      <strong><?= money($capitalDormido) ?> en mercancía sin vender hace más de 90 días.</strong>
      Es <?= number_format($valorCosto > 0 ? $capitalDormido / $valorCosto * 100 : 0, 1) ?>% del inventario parado.
      Una promoción o una liquidación libera ese efectivo.
    </p>
  </div>
<?php endif; ?>

<!-- Selector -->
<div class="card p-2 mb-5 no-print inline-flex flex-wrap gap-1 bg-slate-100"><?= $tabs ?></div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div>
    <?= rep_seccion('Composición', 'Peso de cada ' . mb_strtolower($etiqueta) . ' en el inventario', 'pie', 'blue') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php if (!$grupos): ?>
          <?= empty_state('Sin existencias', 'No hay inventario registrado en el alcance seleccionado.', 'box') ?>
        <?php else: ?>
          <?= donutMulti(array_map(
              fn($g, $i) => ['label' => $g['etiqueta'], 'value' => (float) $g['costo'], 'color' => rep_color($i)],
              array_slice($grupos, 0, 8), array_keys(array_slice($grupos, 0, 8))
          ), 'A costo', money($valorCosto, false)) ?>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <div class="lg:col-span-2">
    <?= rep_seccion('Valorización por ' . mb_strtolower($etiqueta), 'Costo, precio de venta y utilidad potencial', $icono, 'emerald') ?>
      <?php
      $filas = [];
      foreach ($grupos as $g) {
          $ut = (float) $g['venta'] - (float) $g['costo'];
          $mg = (float) $g['venta'] > 0 ? $ut / (float) $g['venta'] * 100 : 0;
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($g['etiqueta']) . '</span>',
              '<span class="text-slate-400 tabular-nums">' . number_format((int) $g['productos']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . qty($g['unidades']) . '</span>',
              '<span class="font-bold text-slate-800 tabular-nums">' . money($g['costo']) . '</span>',
              '<span class="text-slate-600 tabular-nums">' . money($g['venta']) . '</span>',
              '<span class="text-emerald-600 font-semibold tabular-nums">' . money($ut) . '</span>',
              '<span class="badge badge-' . ($mg >= 30 ? 'emerald' : ($mg >= 15 ? 'blue' : 'amber')) . '">' . number_format($mg, 1) . '%</span>',
              '<span class="text-slate-400 tabular-nums text-xs">' . number_format($valorCosto > 0 ? (float) $g['costo'] / $valorCosto * 100 : 0, 1) . '%</span>',
          ];
      }
      echo rep_tabla(
          [$etiqueta, ['Prod.', 'center'], ['Unid.', 'center'], ['A costo', 'right'], ['A venta', 'right'], ['Utilidad', 'right'], ['Margen', 'center'], ['Peso', 'right']],
          $filas,
          ['total' => $filas ? ['Total', number_format((int) $tot['productos']), qty($tot['unidades']), money($valorCosto),
              money($valorVenta), money($utilPot),
              number_format($valorVenta > 0 ? $utilPot / $valorVenta * 100 : 0, 1) . '%', '100%'] : null]
      );
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Detalle -->
<?= rep_seccion('Detalle por producto', 'Ordenado por valor inmovilizado', 'package', 'indigo',
    '<span class="badge badge-' . ((int) $quiebres['agotados'] > 0 ? 'rose' : 'slate') . '">' . (int) $quiebres['agotados'] . ' agotados</span>'
    . '<span class="badge badge-' . ((int) $quiebres['bajos'] > 0 ? 'amber' : 'slate') . '">' . (int) $quiebres['bajos'] . ' bajo mínimo</span>') ?>
  <?php
  $filas = [];
  foreach ($detalle as $d) {
      $ut = (float) $d['venta'] - (float) $d['costo'];
      $dias = $d['ultima_venta'] ? (int) floor((time() - strtotime($d['ultima_venta'])) / 86400) : null;
      $filas[] = [
          '<div><span class="font-semibold text-slate-700">' . e($d['nombre']) . '</span>'
            . '<span class="block text-[11px] text-slate-400">' . e($d['codigo']) . ' · ' . e($d['categoria']) . '</span></div>',
          '<span class="' . ((float) $d['cantidad'] <= (float) $d['stock_minimo'] ? 'text-rose-600 font-bold' : 'text-slate-600') . ' tabular-nums">' . qty($d['cantidad']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($d['precio_compra']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($d['precio_venta']) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($d['costo']) . '</span>',
          '<span class="text-slate-600 tabular-nums">' . money($d['venta']) . '</span>',
          '<span class="text-emerald-600 font-semibold tabular-nums">' . money($ut) . '</span>',
          $dias === null
            ? '<span class="badge badge-rose">Nunca</span>'
            : '<span class="badge badge-' . ($dias > 90 ? 'amber' : 'slate') . '">' . $dias . ' d</span>',
      ];
  }
  echo rep_tabla(
      ['Producto', ['Existencia', 'center'], ['Costo unit.', 'right'], ['Precio', 'right'], ['Valor costo', 'right'], ['Valor venta', 'right'], ['Utilidad pot.', 'right'], ['Última venta', 'center']],
      $filas
  );
  echo paginacion($pg);
  ?>
<?= rep_fin() ?>

<!-- Sin rotación -->
<?= rep_seccion('Mercancía sin rotación', 'Sin ventas en los últimos 90 días — capital dormido', 'clock', 'rose',
    can('promociones.crear') ? '<a href="' . e(url('modules/marketing/promociones.php')) . '" class="btn btn-soft btn-sm no-print">' . icon('percent', 'w-3.5 h-3.5') . ' Crear promoción</a>' : '') ?>
  <?php
  $filas = [];
  foreach ($sinRotacion as $s) {
      $dias = $s['ultima_venta'] ? (int) floor((time() - strtotime($s['ultima_venta'])) / 86400) : null;
      $filas[] = [
          '<div><span class="font-semibold text-slate-700">' . e($s['nombre']) . '</span>'
            . '<span class="block text-[11px] text-slate-400">' . e($s['codigo']) . '</span></div>',
          '<span class="text-slate-600 tabular-nums">' . qty($s['cantidad']) . '</span>',
          '<span class="font-bold text-rose-600 tabular-nums">' . money($s['costo']) . '</span>',
          $dias === null
            ? '<span class="badge badge-rose">Nunca se ha vendido</span>'
            : '<span class="badge badge-amber">Hace ' . $dias . ' días</span>',
      ];
  }
  echo rep_tabla(['Producto', ['Existencia', 'center'], ['Capital inmovilizado', 'right'], ['Última venta', 'center']], $filas,
      ['total' => $filas ? ['Total dormido', '', money($capitalDormido), ''] : null,
       'vacio_titulo' => 'Todo el inventario rota',
       'vacio' => 'Ningún producto lleva más de 90 días sin venderse. Muy buena gestión de compras.',
       'vacio_icono' => 'check']);
  ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
