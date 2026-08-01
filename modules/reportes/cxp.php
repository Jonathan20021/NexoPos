<?php
/**
 * Cuentas por pagar a proveedores.
 *
 * Una compra queda como pendiente cuando su forma de pago DGII es «crédito» (4)
 * y todavía no tiene fecha de pago registrada. La antigüedad se cuenta desde la
 * fecha del comprobante.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.finanzas');

$p = rep_periodo('anio');
[$scope, $scopeP] = rep_scope('c.sucursal_id');
$corte = date('Y-m-d');

/* ---------- Pendientes de pago ---------- */
$pendientes = qAll(
    "SELECT c.id, c.numero, c.ncf, c.fecha, c.fecha_comprobante, c.total, c.subtotal, c.itbis,
            c.proveedor_id, COALESCE(pr.nombre,'Sin proveedor') AS proveedor, pr.rnc, pr.telefono, pr.email
       FROM compras c
       LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
      WHERE c.estado <> 'anulada' AND c.forma_pago = 4 AND c.fecha_pago IS NULL AND $scope
      ORDER BY COALESCE(c.fecha_comprobante, c.fecha) ASC",
    $scopeP
);

$buckets = ['corriente' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90mas' => 0.0];
$porProveedor = [];
foreach ($pendientes as $i => $c) {
    $fecha = $c['fecha_comprobante'] ?: $c['fecha'];
    $dias  = (int) floor((strtotime($corte) - strtotime($fecha)) / 86400);
    $b = $dias <= 30 ? 'corriente' : ($dias <= 60 ? 'b30' : ($dias <= 90 ? 'b60' : ($dias <= 120 ? 'b90' : 'b90mas')));
    $monto = (float) $c['total'];
    $buckets[$b] += $monto;
    $pendientes[$i]['dias'] = $dias;
    $pendientes[$i]['bucket'] = $b;

    $pid = (int) ($c['proveedor_id'] ?? 0);
    if (!isset($porProveedor[$pid])) {
        $porProveedor[$pid] = ['nombre' => $c['proveedor'], 'rnc' => $c['rnc'], 'telefono' => $c['telefono'],
                               'total' => 0.0, 'facturas' => 0, 'mas_vieja' => $fecha,
                               'buckets' => ['corriente' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90mas' => 0.0]];
    }
    $porProveedor[$pid]['total'] += $monto;
    $porProveedor[$pid]['facturas']++;
    $porProveedor[$pid]['buckets'][$b] += $monto;
    if ($fecha < $porProveedor[$pid]['mas_vieja']) $porProveedor[$pid]['mas_vieja'] = $fecha;
}
uasort($porProveedor, fn($a, $b) => $b['total'] <=> $a['total']);
$totalDeuda = array_sum($buckets);
$vencido = $totalDeuda - $buckets['corriente'];

/* ---------- Compras del periodo por proveedor ---------- */
$comprasPeriodo = qAll(
    "SELECT COALESCE(pr.nombre,'Sin proveedor') AS proveedor, pr.rnc,
            COUNT(*) AS compras, COALESCE(SUM(c.subtotal),0) AS subtotal,
            COALESCE(SUM(c.itbis),0) AS itbis, COALESCE(SUM(c.total),0) AS total,
            COALESCE(SUM(c.itbis_retenido),0) AS itbis_ret,
            COALESCE(SUM(c.monto_retencion_renta),0) AS isr_ret
       FROM compras c LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
      WHERE c.estado <> 'anulada' AND c.fecha BETWEEN ? AND ? AND $scope
      GROUP BY c.proveedor_id ORDER BY total DESC",
    array_merge([$p['desde'], $p['hasta']], $scopeP)
);
$totalCompras = array_sum(array_column($comprasPeriodo, 'total'));

$pagadoPeriodo = (float) qVal(
    "SELECT COALESCE(SUM(c.total),0) FROM compras c
      WHERE c.estado <> 'anulada' AND c.fecha_pago BETWEEN ? AND ? AND $scope",
    array_merge([$p['desde'], $p['hasta']], $scopeP)
);

$etiquetas = [
    'corriente' => ['Al día (0-30)', 'emerald'], 'b30' => ['31 a 60 días', 'amber'],
    'b60' => ['61 a 90 días', 'amber'], 'b90' => ['91 a 120 días', 'rose'], 'b90mas' => ['Más de 120 días', 'rose'],
];

if (export_solicitado()) {
    $filas = [];
    foreach ($pendientes as $c) {
        $filas[] = [$c['numero'], $c['ncf'] ?? '', $c['proveedor'], $c['rnc'] ?? '',
            fechaCorta($c['fecha_comprobante'] ?: $c['fecha']), $c['dias'],
            money($c['subtotal'], false), money($c['itbis'], false), money($c['total'], false),
            $etiquetas[$c['bucket']][0]];
    }
    export_tabla('cuentas_por_pagar_' . $corte,
        ['Compra', 'NCF', 'Proveedor', 'RNC', 'Fecha', 'Días', 'Subtotal', 'ITBIS', 'Total', 'Antigüedad'],
        $filas, 'Cuentas por pagar');
}

layout_start('Cuentas por pagar', 'Deuda con proveedores al ' . fechaCorta($corte) . ' · ' . rep_alcance_sucursal(), rep_barra_titulo());
echo rep_encabezado_impresion('Cuentas por pagar', $p);
echo rep_filtros($p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Deuda con proveedores', 'valor' => money($totalDeuda), 'icono' => 'truck',
     'color' => $totalDeuda > 0 ? 'amber' : 'emerald',
     'nota' => count($pendientes) . ' factura(s) de ' . count($porProveedor) . ' proveedor(es)'],
    ['label' => 'Vencido', 'valor' => money($vencido), 'icono' => 'alert', 'color' => $vencido > 0 ? 'rose' : 'emerald',
     'nota' => $totalDeuda > 0 ? number_format($vencido / $totalDeuda * 100, 1) . '% de la deuda' : 'Nada vencido'],
    ['label' => 'Compras del periodo', 'valor' => money($totalCompras), 'icono' => 'cart', 'color' => 'blue',
     'nota' => count($comprasPeriodo) . ' proveedor(es) activo(s)'],
    ['label' => 'Pagado en el periodo', 'valor' => money($pagadoPeriodo), 'icono' => 'cash', 'color' => 'violet',
     'nota' => 'Compras con fecha de pago en el rango'],
]) ?>

<!-- Antigüedad -->
<div class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-5">
  <?php foreach ($etiquetas as $k => [$lbl, $col]):
    $v = $buckets[$k];
    $pct = $totalDeuda > 0 ? $v / $totalDeuda * 100 : 0;
    $bg = ['emerald' => 'bg-emerald-50 text-emerald-600', 'amber' => 'bg-amber-50 text-amber-600', 'rose' => 'bg-rose-50 text-rose-600'][$col];
  ?>
    <div class="card p-4">
      <div class="w-9 h-9 rounded-xl <?= $bg ?> flex items-center justify-center mb-3"><?= icon('calendar', 'w-4 h-4') ?></div>
      <p class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($v) ?></p>
      <p class="text-[11.5px] font-semibold text-slate-500 mt-0.5"><?= e($lbl) ?></p>
      <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden mt-2">
        <div class="h-full rounded-full" style="width:<?= max($pct, 0.5) ?>%;background:<?= rep_color_nombre($col) ?>"></div>
      </div>
      <p class="text-[11px] text-slate-400 mt-1"><?= number_format($pct, 1) ?>%</p>
    </div>
  <?php endforeach; ?>
</div>

<!-- Por proveedor -->
<?= rep_seccion('Deuda por proveedor', 'Ordenada por monto pendiente', 'briefcase', 'amber') ?>
  <?php
  $filas = [];
  foreach ($porProveedor as $pr) {
      $dias = (int) floor((strtotime($corte) - strtotime($pr['mas_vieja'])) / 86400);
      $filas[] = [
          '<div><span class="font-semibold text-slate-700">' . e($pr['nombre']) . '</span>'
            . '<span class="block text-[11px] text-slate-400">' . e($pr['rnc'] ?: 'Sin RNC')
            . ($pr['telefono'] ? ' · ' . e($pr['telefono']) : '') . '</span></div>',
          '<span class="text-slate-500 tabular-nums">' . $pr['facturas'] . '</span>',
          '<span class="text-emerald-600 tabular-nums">' . ($pr['buckets']['corriente'] > 0 ? money($pr['buckets']['corriente'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-amber-600 tabular-nums">' . ($pr['buckets']['b30'] > 0 ? money($pr['buckets']['b30'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-amber-700 tabular-nums">' . ($pr['buckets']['b60'] > 0 ? money($pr['buckets']['b60'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-rose-600 tabular-nums">' . (($pr['buckets']['b90'] + $pr['buckets']['b90mas']) > 0 ? money($pr['buckets']['b90'] + $pr['buckets']['b90mas'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($pr['total']) . '</span>',
          '<span class="badge badge-' . ($dias > 90 ? 'rose' : ($dias > 30 ? 'amber' : 'slate')) . '">' . $dias . ' d</span>',
      ];
  }
  echo rep_tabla(
      ['Proveedor', ['Fact.', 'center'], ['0-30', 'right'], ['31-60', 'right'], ['61-90', 'right'], ['+90', 'right'], ['Total', 'right'], ['Antigüedad', 'center']],
      $filas,
      ['total' => $filas ? ['Total deuda', '', money($buckets['corriente']), money($buckets['b30']), money($buckets['b60']),
          money($buckets['b90'] + $buckets['b90mas']), money($totalDeuda), ''] : null,
       'vacio_titulo' => 'Sin deuda con proveedores',
       'vacio' => 'No hay compras a crédito pendientes de pago. Registra la forma de pago «Crédito» y deja la fecha de pago vacía para que aparezcan aquí.',
       'vacio_icono' => 'check']
  );
  ?>
<?= rep_fin() ?>

<!-- Compras del periodo -->
<?= rep_seccion('Compras del periodo por proveedor', 'Incluye ITBIS y retenciones para el 606', 'truck', 'blue',
    can('compras.ver') ? '<a href="' . e(url('modules/inventario/compras.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Ver compras</a>' : '') ?>
  <?php
  $filas = [];
  foreach ($comprasPeriodo as $c) {
      $filas[] = [
          '<div><span class="font-semibold text-slate-700">' . e($c['proveedor']) . '</span>'
            . '<span class="block text-[11px] text-slate-400">' . e($c['rnc'] ?: 'Sin RNC') . '</span></div>',
          '<span class="text-slate-500 tabular-nums">' . number_format((int) $c['compras']) . '</span>',
          '<span class="text-slate-600 tabular-nums">' . money($c['subtotal']) . '</span>',
          '<span class="text-slate-600 tabular-nums">' . money($c['itbis']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . ((float) $c['itbis_ret'] > 0 ? money($c['itbis_ret']) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-slate-500 tabular-nums">' . ((float) $c['isr_ret'] > 0 ? money($c['isr_ret']) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($c['total']) . '</span>',
          '<span class="text-slate-400 tabular-nums text-xs">' . ($totalCompras > 0 ? number_format($c['total'] / $totalCompras * 100, 1) : '0') . '%</span>',
      ];
  }
  echo rep_tabla(
      ['Proveedor', ['Compras', 'center'], ['Subtotal', 'right'], ['ITBIS', 'right'], ['ITBIS ret.', 'right'], ['ISR ret.', 'right'], ['Total', 'right'], ['%', 'right']],
      $filas,
      ['total' => $filas ? ['Total', number_format((int) array_sum(array_column($comprasPeriodo, 'compras'))),
          money(array_sum(array_column($comprasPeriodo, 'subtotal'))),
          money(array_sum(array_column($comprasPeriodo, 'itbis'))),
          money(array_sum(array_column($comprasPeriodo, 'itbis_ret'))),
          money(array_sum(array_column($comprasPeriodo, 'isr_ret'))),
          money($totalCompras), '100%'] : null]
  );
  ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
