<?php
/**
 * Rentabilidad: dónde se gana y dónde se pierde dinero de verdad.
 *
 * Margen por producto, categoría, sucursal y vendedor. Detecta líneas vendidas
 * por debajo del costo, que es una fuga que no se ve en el total de ventas.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.finanzas');

$p = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('v.sucursal_id');
$pv = array_merge([$p['ini'], $p['fin']], $scopeP);
$vista = in_array(get('vista'), ['producto', 'categoria', 'marca', 'vendedor', 'sucursal'], true) ? get('vista') : 'producto';

/* ---------- Totales ---------- */
$tot = qOne(
    "SELECT COALESCE(SUM(vd.subtotal - vd.descuento),0) ingresos,
            COALESCE(SUM(vd.cantidad * vd.costo_unitario),0) costo,
            COALESCE(SUM(vd.cantidad),0) unidades
       FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope",
    $pv
) ?: ['ingresos' => 0, 'costo' => 0, 'unidades' => 0];
$ingTot = (float) $tot['ingresos'];
$cosTot = (float) $tot['costo'];
$utTot  = $ingTot - $cosTot;

/* ---------- Agrupación según la vista ---------- */
$config = [
    'producto'  => ["COALESCE(p.nombre, vd.descripcion)", 'LEFT JOIN productos p ON p.id = vd.producto_id', 'COALESCE(p.id, vd.descripcion)', 'Producto', 'package'],
    'categoria' => ["COALESCE(c.nombre,'Sin categoría')", 'LEFT JOIN productos p ON p.id = vd.producto_id LEFT JOIN categorias c ON c.id = p.categoria_id', 'c.id', 'Categoría', 'tag'],
    'marca'     => ["COALESCE(mc.nombre,'Sin marca')", 'LEFT JOIN productos p ON p.id = vd.producto_id LEFT JOIN marcas mc ON mc.id = p.marca_id', 'mc.id', 'Marca', 'layers'],
    'vendedor'  => ["CONCAT(u.nombre,' ',u.apellido)", 'JOIN usuarios u ON u.id = v.usuario_id', 'v.usuario_id', 'Vendedor', 'users'],
    'sucursal'  => ['su.nombre', 'JOIN sucursales su ON su.id = v.sucursal_id', 'v.sucursal_id', 'Sucursal', 'store'],
];
[$sel, $join, $group, $etiqueta, $icono] = $config[$vista];

$filasDatos = qAll(
    "SELECT $sel AS etiqueta,
            COALESCE(SUM(vd.cantidad),0) unidades,
            COALESCE(SUM(vd.subtotal - vd.descuento),0) ingresos,
            COALESCE(SUM(vd.cantidad * vd.costo_unitario),0) costo,
            COALESCE(SUM(vd.descuento),0) descuentos,
            COUNT(DISTINCT v.id) facturas
       FROM venta_detalles vd
       JOIN ventas v ON v.id = vd.venta_id
       $join
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY $group
      ORDER BY (COALESCE(SUM(vd.subtotal - vd.descuento),0) - COALESCE(SUM(vd.cantidad * vd.costo_unitario),0)) DESC",
    $pv
);

/* ---------- Líneas vendidas bajo costo ---------- */
$bajoCosto = qAll(
    "SELECT COALESCE(p.nombre, vd.descripcion) AS producto, v.numero, v.fecha,
            vd.cantidad, vd.precio_unitario, vd.costo_unitario,
            (vd.subtotal - vd.descuento - vd.cantidad * vd.costo_unitario) AS perdida
       FROM venta_detalles vd
       JOIN ventas v ON v.id = vd.venta_id
       LEFT JOIN productos p ON p.id = vd.producto_id
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
        AND vd.es_muestra = 0
        AND (vd.subtotal - vd.descuento) < (vd.cantidad * vd.costo_unitario)
      ORDER BY perdida ASC LIMIT 25",
    $pv
);
$perdidaTotal = (float) qVal(
    "SELECT COALESCE(SUM((vd.subtotal - vd.descuento) - (vd.cantidad * vd.costo_unitario)),0)
       FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope AND vd.es_muestra = 0
        AND (vd.subtotal - vd.descuento) < (vd.cantidad * vd.costo_unitario)",
    $pv
);

/* ---------- Precios de lista sin margen ---------- */
$sinMargen = qAll(
    "SELECT nombre, codigo, precio_compra, precio_venta
       FROM productos WHERE activo = 1 AND precio_compra > 0 AND precio_venta > 0 AND precio_venta <= precio_compra
      ORDER BY (precio_compra - precio_venta) DESC LIMIT 20"
);

/* ---------- Muestras entregadas ---------- */
$muestras = qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(vd.cantidad),0) unidades,
            COALESCE(SUM(vd.cantidad * vd.precio_original),0) valor,
            COALESCE(SUM(vd.cantidad * vd.costo_unitario),0) costo
       FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
      WHERE v.estado='completada' AND vd.es_muestra = 1 AND v.fecha BETWEEN ? AND ? AND $scope",
    $pv
) ?: ['n' => 0, 'unidades' => 0, 'valor' => 0, 'costo' => 0];

if (export_solicitado()) {
    $filas = [];
    foreach ($filasDatos as $d) {
        $ut = (float) $d['ingresos'] - (float) $d['costo'];
        $filas[] = [$d['etiqueta'], qty($d['unidades']), (int) $d['facturas'],
            money($d['ingresos'], false), money($d['costo'], false), money($ut, false),
            number_format((float) $d['ingresos'] > 0 ? $ut / (float) $d['ingresos'] * 100 : 0, 2),
            money($d['descuentos'], false)];
    }
    export_tabla('rentabilidad_' . $vista . '_' . $p['desde'] . '_' . $p['hasta'],
        [$etiqueta, 'Unidades', 'Facturas', 'Ingresos', 'Costo', 'Utilidad', 'Margen %', 'Descuentos'],
        $filas, 'Rentabilidad por ' . mb_strtolower($etiqueta));
}

$tabs = '';
foreach ($config as $k => $c) {
    $qs = array_merge($_GET, ['vista' => $k]);
    $act = $vista === $k;
    $tabs .= '<a href="?' . e(http_build_query($qs)) . '" class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition '
        . ($act ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800') . '">' . e($c[3]) . '</a>';
}

layout_start('Rentabilidad', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Rentabilidad', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Ingresos netos', 'valor' => money($ingTot), 'icono' => 'cash', 'color' => 'blue',
     'nota' => qty($tot['unidades']) . ' unidad(es) vendidas'],
    ['label' => 'Costo de la mercancía', 'valor' => money($cosTot), 'icono' => 'box', 'color' => 'slate',
     'nota' => $ingTot > 0 ? number_format($cosTot / $ingTot * 100, 1) . '% de los ingresos' : '—'],
    ['label' => 'Utilidad bruta', 'valor' => money($utTot), 'icono' => 'trending',
     'color' => $utTot >= 0 ? 'emerald' : 'rose',
     'nota' => 'Margen ' . number_format($ingTot > 0 ? $utTot / $ingTot * 100 : 0, 1) . '%'],
    ['label' => 'Ventas bajo costo', 'valor' => money(abs($perdidaTotal)), 'icono' => 'alert',
     'color' => abs($perdidaTotal) > 0 ? 'rose' : 'emerald',
     'nota' => count($bajoCosto) > 0 ? count($bajoCosto) . '+ línea(s) con pérdida' : 'Ninguna venta bajo costo'],
]) ?>

<!-- Selector de vista -->
<div class="card p-2 mb-5 no-print inline-flex flex-wrap gap-1 bg-slate-100">
  <?= $tabs ?>
</div>

<?= rep_seccion('Rentabilidad por ' . mb_strtolower($etiqueta), 'Ordenado por utilidad generada en el periodo', $icono, 'emerald') ?>
  <?php
  $filas = [];
  foreach (array_slice($filasDatos, 0, 60) as $d) {
      $ing = (float) $d['ingresos'];
      $ut  = $ing - (float) $d['costo'];
      $mg  = $ing > 0 ? $ut / $ing * 100 : 0;
      $peso = $utTot != 0 ? $ut / $utTot * 100 : 0;
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($d['etiqueta']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . qty($d['unidades']) . '</span>',
          '<span class="text-slate-400 tabular-nums">' . number_format((int) $d['facturas']) . '</span>',
          '<span class="font-semibold text-slate-800 tabular-nums">' . money($ing) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($d['costo']) . '</span>',
          '<span class="font-bold ' . ($ut >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' tabular-nums">' . money($ut) . '</span>',
          '<span class="badge badge-' . ($mg >= 30 ? 'emerald' : ($mg >= 15 ? 'blue' : ($mg >= 0 ? 'amber' : 'rose'))) . '">' . number_format($mg, 1) . '%</span>',
          '<div class="h-1.5 w-20 rounded-full bg-slate-100 overflow-hidden ml-auto"><div class="h-full rounded-full" style="width:'
            . max(min(abs($peso), 100), 1) . '%;background:' . ($ut >= 0 ? '#10b981' : '#f43f5e') . '"></div></div>',
      ];
  }
  echo rep_tabla(
      [$etiqueta, ['Unid.', 'center'], ['Fact.', 'center'], ['Ingresos', 'right'], ['Costo', 'right'], ['Utilidad', 'right'], ['Margen', 'center'], ['Peso', 'right']],
      $filas,
      ['total' => $filas ? ['Total', qty($tot['unidades']), '', money($ingTot), money($cosTot), money($utTot),
          number_format($ingTot > 0 ? $utTot / $ingTot * 100 : 0, 1) . '%', ''] : null]
  );
  if (count($filasDatos) > 60) {
      echo '<p class="px-5 pb-4 text-xs text-slate-400">Se muestran los primeros 60 de ' . count($filasDatos)
         . ' registros. Descarga el Excel para la lista completa.</p>';
  }
  ?>
<?= rep_fin() ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Ventas bajo costo -->
  <div>
    <?= rep_seccion('Ventas por debajo del costo', 'Líneas facturadas con pérdida en el periodo', 'trending-down', 'rose') ?>
      <?php
      $filas = [];
      foreach ($bajoCosto as $b) {
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($b['producto']) . '</span>'
                . '<span class="block text-[11px] text-slate-400">' . e($b['numero']) . ' · ' . fechaCorta($b['fecha']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . qty($b['cantidad']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . money($b['precio_unitario']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . money($b['costo_unitario']) . '</span>',
              '<span class="font-bold text-rose-600 tabular-nums">' . money($b['perdida']) . '</span>',
          ];
      }
      echo rep_tabla(['Producto / factura', ['Cant.', 'center'], ['Precio', 'right'], ['Costo', 'right'], ['Pérdida', 'right']], $filas,
          ['vacio_titulo' => 'Ninguna venta bajo costo',
           'vacio' => 'Todas las líneas facturadas cubrieron su costo. Excelente control de precios y descuentos.',
           'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>

  <!-- Lista de precios sin margen -->
  <div>
    <?= rep_seccion('Precios de lista sin margen', 'Productos cuyo precio de venta no supera al de compra', 'alert', 'amber',
        can('productos.editar') ? '<a href="' . e(url('modules/inventario/productos.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Corregir precios</a>' : '') ?>
      <?php
      $filas = [];
      foreach ($sinMargen as $s) {
          $dif = (float) $s['precio_venta'] - (float) $s['precio_compra'];
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($s['nombre']) . '</span>'
                . '<span class="block text-[11px] text-slate-400">' . e($s['codigo']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . money($s['precio_compra']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . money($s['precio_venta']) . '</span>',
              '<span class="font-bold text-rose-600 tabular-nums">' . money($dif) . '</span>',
          ];
      }
      echo rep_tabla(['Producto', ['Costo', 'right'], ['Precio', 'right'], ['Margen', 'right']], $filas,
          ['vacio_titulo' => 'Lista de precios sana',
           'vacio' => 'Todos los productos activos tienen precio de venta por encima del costo.', 'vacio_icono' => 'check']);
      ?>
      <?php if ((int) $muestras['n'] > 0): ?>
        <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
          <strong class="text-slate-600">Muestras del periodo:</strong>
          <?= qty($muestras['unidades']) ?> unidad(es) entregadas a RD$0.00 en <?= (int) $muestras['n'] ?> línea(s).
          Valor de lista <?= money($muestras['valor']) ?> · costo real asumido <strong><?= money($muestras['costo']) ?></strong>.
          Las muestras no cuentan como venta bajo costo, pero sí consumen inventario.
        </div>
      <?php endif; ?>
    <?= rep_fin() ?>
  </div>
</div>

<?php layout_end(); ?>
