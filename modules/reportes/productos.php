<?php
/** Desempeño de productos: qué se mueve, qué no, y qué se está agotando. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.operacion');

$p = rep_periodo('mes');
[$scope, $scopeP]   = rep_scope('v.sucursal_id');
[$scopeS, $scopeSP] = rep_scope('s.sucursal_id');
$pv = array_merge([$p['ini'], $p['fin']], $scopeP);

/* ---------- Vendidos en el periodo ---------- */
$vendidos = qAll(
    "SELECT COALESCE(p.id,0) AS id, COALESCE(p.nombre, vd.descripcion) AS producto, p.codigo,
            COALESCE(c.nombre,'Sin categoría') AS categoria, COALESCE(c.color,'slate') AS color,
            SUM(vd.cantidad) AS unidades,
            SUM(vd.subtotal - vd.descuento) AS ingresos,
            SUM(vd.cantidad * vd.costo_unitario) AS costo,
            COUNT(DISTINCT v.id) AS facturas,
            SUM(vd.descuento) AS descuentos
       FROM venta_detalles vd
       JOIN ventas v ON v.id = vd.venta_id
       LEFT JOIN productos p  ON p.id = vd.producto_id
       LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY COALESCE(p.id, vd.descripcion)
      ORDER BY unidades DESC",
    $pv
);
$totUnidades = array_sum(array_column($vendidos, 'unidades'));
$totIngresos = array_sum(array_column($vendidos, 'ingresos'));
$totCosto    = array_sum(array_column($vendidos, 'costo'));

/* ---------- Stock actual para cruzar cobertura ---------- */
$stock = [];
foreach (qAll(
    "SELECT s.producto_id, SUM(s.cantidad) c FROM inventario_stock s WHERE $scopeS GROUP BY s.producto_id",
    $scopeSP
) as $r) $stock[(int) $r['producto_id']] = (float) $r['c'];

/* ---------- Sin ventas en el periodo ---------- */
$sinVenta = qAll(
    "SELECT p.id, p.nombre, p.codigo, COALESCE(c.nombre,'Sin categoría') categoria,
            COALESCE((SELECT SUM(s.cantidad) FROM inventario_stock s WHERE s.producto_id = p.id AND $scopeS),0) AS existencia,
            p.precio_compra,
            (SELECT MAX(v2.fecha) FROM venta_detalles vd2 JOIN ventas v2 ON v2.id = vd2.venta_id
              WHERE vd2.producto_id = p.id AND v2.estado='completada') AS ultima_venta
       FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE p.activo = 1 AND p.tipo = 'producto'
        AND p.id NOT IN (
            SELECT DISTINCT vd.producto_id FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
             WHERE vd.producto_id IS NOT NULL AND v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope)
      ORDER BY existencia * p.precio_compra DESC LIMIT 25",
    array_merge($scopeSP, [$p['ini'], $p['fin']], $scopeP)
);

/* ---------- Por categoría ---------- */
$porCategoria = qAll(
    "SELECT COALESCE(c.nombre,'Sin categoría') categoria, COALESCE(c.color,'slate') color,
            SUM(vd.cantidad) unidades, SUM(vd.subtotal - vd.descuento) ingresos,
            SUM(vd.cantidad * vd.costo_unitario) costo
       FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
       LEFT JOIN productos p  ON p.id = vd.producto_id
       LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY c.id ORDER BY ingresos DESC",
    $pv
);

/* ---------- Quiebres de stock ---------- */
$quiebres = qAll(
    "SELECT p.nombre, p.codigo, p.stock_minimo, su.nombre AS sucursal, s.cantidad,
            COALESCE((SELECT SUM(vd.cantidad) FROM venta_detalles vd JOIN ventas v2 ON v2.id = vd.venta_id
                       WHERE vd.producto_id = p.id AND v2.estado='completada' AND v2.fecha BETWEEN ? AND ?),0) AS vendidas
       FROM inventario_stock s
       JOIN productos p   ON p.id = s.producto_id AND p.activo = 1 AND p.tipo = 'producto'
       JOIN sucursales su ON su.id = s.sucursal_id
      WHERE $scopeS AND (s.cantidad <= 0 OR (p.stock_minimo > 0 AND s.cantidad <= p.stock_minimo))
      ORDER BY s.cantidad ASC, vendidas DESC LIMIT 30",
    array_merge([$p['ini'], $p['fin']], $scopeSP)
);

if (export_solicitado()) {
    $filas = [];
    foreach ($vendidos as $i => $v) {
        $ut = (float) $v['ingresos'] - (float) $v['costo'];
        $ex = $stock[(int) $v['id']] ?? 0;
        $filas[] = [$i + 1, $v['producto'], $v['codigo'] ?? '', $v['categoria'], qty($v['unidades']),
            (int) $v['facturas'], money($v['ingresos'], false), money($v['costo'], false), money($ut, false),
            number_format((float) $v['ingresos'] > 0 ? $ut / (float) $v['ingresos'] * 100 : 0, 2),
            money($v['descuentos'], false), qty($ex)];
    }
    export_tabla('desempeno_productos_' . $p['desde'] . '_' . $p['hasta'],
        ['#', 'Producto', 'Código', 'Categoría', 'Unidades', 'Facturas', 'Ingresos', 'Costo', 'Utilidad', 'Margen %', 'Descuentos', 'Existencia actual'],
        $filas, 'Desempeño de productos');
}

layout_start('Desempeño de productos', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Desempeño de productos', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Productos vendidos', 'valor' => number_format(count($vendidos)), 'icono' => 'package', 'color' => 'blue',
     'nota' => qty($totUnidades) . ' unidades en total'],
    ['label' => 'Ingresos por producto', 'valor' => money($totIngresos), 'icono' => 'cash', 'color' => 'emerald',
     'nota' => 'Sin ITBIS, ya con descuentos'],
    ['label' => 'Utilidad generada', 'valor' => money($totIngresos - $totCosto), 'icono' => 'trending', 'color' => 'violet',
     'nota' => 'Margen ' . number_format($totIngresos > 0 ? ($totIngresos - $totCosto) / $totIngresos * 100 : 0, 1) . '%'],
    ['label' => 'Sin ventas en el periodo', 'valor' => number_format(count($sinVenta)), 'icono' => 'clock',
     'color' => count($sinVenta) > 0 ? 'amber' : 'emerald', 'nota' => 'Productos activos que no rotaron'],
]) ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div>
    <?= rep_seccion('Peso por categoría', 'Participación en los ingresos', 'pie', 'violet') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php if (!$porCategoria): ?>
          <?= empty_state('Sin ventas en el periodo', 'Cuando haya facturación verás el peso de cada categoría.', 'tag') ?>
        <?php else: ?>
          <?= donutMulti(array_map(
              fn($c) => ['label' => $c['categoria'], 'value' => (float) $c['ingresos'], 'color' => rep_color_nombre($c['color'])],
              $porCategoria
          ), 'Ingresos', money($totIngresos, false)) ?>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <div class="lg:col-span-2">
    <?= rep_seccion('Más vendidos', 'Ordenados por unidades despachadas', 'package', 'blue') ?>
      <?php
      $filas = [];
      foreach (array_slice($vendidos, 0, 30) as $i => $v) {
          $ut = (float) $v['ingresos'] - (float) $v['costo'];
          $mg = (float) $v['ingresos'] > 0 ? $ut / (float) $v['ingresos'] * 100 : 0;
          $ex = $stock[(int) $v['id']] ?? 0;
          $cobertura = (float) $v['unidades'] > 0 ? $ex / ((float) $v['unidades'] / max(1, $p['dias'])) : null;
          $filas[] = [
              '<span class="text-slate-400 font-semibold tabular-nums">' . ($i + 1) . '</span>',
              '<div><span class="font-semibold text-slate-700">' . e($v['producto']) . '</span>'
                . '<span class="block text-[11px] text-slate-400">' . e($v['codigo'] ?: '—') . ' · ' . e($v['categoria']) . '</span></div>',
              '<span class="font-semibold text-slate-700 tabular-nums">' . qty($v['unidades']) . '</span>',
              '<span class="text-slate-400 tabular-nums">' . number_format((int) $v['facturas']) . '</span>',
              '<span class="font-bold text-slate-800 tabular-nums">' . money($v['ingresos']) . '</span>',
              '<span class="' . ($ut >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' font-semibold tabular-nums">' . money($ut) . '</span>',
              '<span class="badge badge-' . ($mg >= 30 ? 'emerald' : ($mg >= 15 ? 'blue' : ($mg >= 0 ? 'amber' : 'rose'))) . '">' . number_format($mg, 1) . '%</span>',
              $cobertura === null
                ? '<span class="text-slate-300">—</span>'
                : '<span class="badge badge-' . ($cobertura < 7 ? 'rose' : ($cobertura < 21 ? 'amber' : 'slate')) . '">' . number_format($cobertura, 0) . ' d</span>',
          ];
      }
      echo rep_tabla(
          [['#', 'center'], 'Producto', ['Unid.', 'center'], ['Fact.', 'center'], ['Ingresos', 'right'], ['Utilidad', 'right'], ['Margen', 'center'], ['Cobertura', 'center']],
          $filas,
          ['total' => $filas ? ['', 'Total del periodo', qty($totUnidades), '', money($totIngresos), money($totIngresos - $totCosto), '', ''] : null]
      );
      if (count($vendidos) > 30) {
          echo '<p class="px-5 pb-4 text-xs text-slate-400">Se muestran los 30 primeros de ' . count($vendidos)
             . ' productos vendidos. El Excel trae la lista completa.</p>';
      }
      ?>
      <div class="px-5 py-3 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500">
        <strong class="text-slate-600">Cobertura:</strong> días que aguanta la existencia actual al ritmo de venta del periodo.
        Menos de 7 días es riesgo de quiebre.
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Quiebres -->
  <div>
    <?= rep_seccion('Riesgo de quiebre', 'Agotados o por debajo del mínimo', 'alert', 'rose',
        can('compras.crear') ? '<a href="' . e(url('modules/inventario/compras.php')) . '" class="btn btn-soft btn-sm no-print">' . icon('truck', 'w-3.5 h-3.5') . ' Comprar</a>' : '') ?>
      <?php
      $filas = [];
      foreach ($quiebres as $qb) {
          $filas[] = [
              '<div><span class="font-semibold text-slate-700">' . e($qb['nombre']) . '</span>'
                . '<span class="block text-[11px] text-slate-400">' . e($qb['codigo']) . ' · ' . e($qb['sucursal']) . '</span></div>',
              '<span class="font-bold ' . ((float) $qb['cantidad'] <= 0 ? 'text-rose-600' : 'text-amber-600') . ' tabular-nums">' . qty($qb['cantidad']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . qty($qb['stock_minimo']) . '</span>',
              '<span class="text-slate-600 tabular-nums">' . qty($qb['vendidas']) . '</span>',
              (float) $qb['cantidad'] <= 0 ? badge('Agotado', 'rose') : badge('Bajo mínimo', 'amber'),
          ];
      }
      echo rep_tabla(['Producto', ['Existencia', 'center'], ['Mínimo', 'center'], ['Vendidas', 'center'], ['Estado', 'center']], $filas,
          ['vacio_titulo' => 'Inventario saludable',
           'vacio' => 'Ningún producto está agotado ni por debajo de su stock mínimo.', 'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>

  <!-- Sin rotación -->
  <div>
    <?= rep_seccion('No se vendieron en el periodo', 'Ordenados por capital inmovilizado', 'clock', 'amber') ?>
      <?php
      $filas = [];
      foreach ($sinVenta as $s) {
          $valor = (float) $s['existencia'] * (float) $s['precio_compra'];
          $dias  = $s['ultima_venta'] ? (int) floor((time() - strtotime($s['ultima_venta'])) / 86400) : null;
          $filas[] = [
              '<div><span class="font-semibold text-slate-700">' . e($s['nombre']) . '</span>'
                . '<span class="block text-[11px] text-slate-400">' . e($s['codigo']) . ' · ' . e($s['categoria']) . '</span></div>',
              '<span class="text-slate-600 tabular-nums">' . qty($s['existencia']) . '</span>',
              '<span class="font-bold text-slate-800 tabular-nums">' . money($valor) . '</span>',
              $dias === null ? badge('Nunca vendido', 'rose') : '<span class="text-slate-500 text-xs">hace ' . $dias . ' días</span>',
          ];
      }
      echo rep_tabla(['Producto', ['Existencia', 'center'], ['Capital parado', 'right'], ['Última venta', 'center']], $filas,
          ['vacio_titulo' => 'Todo rotó',
           'vacio' => 'Todos los productos activos tuvieron al menos una venta en el periodo.', 'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<?php layout_end(); ?>
