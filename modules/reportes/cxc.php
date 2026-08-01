<?php
/**
 * Cuentas por cobrar con antigüedad de saldos.
 *
 * Como las facturas a crédito no llevan fecha de vencimiento propia, la
 * antigüedad se calcula aplicando los abonos del cliente a sus facturas más
 * viejas primero (FIFO) y clasificando lo que queda por la fecha de la factura.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.finanzas');

$p = rep_periodo('anio');
[$scope, $scopeP] = rep_scope('v.sucursal_id');
$corte = date('Y-m-d');

/* ---------- Facturas a crédito ---------- */
$facturas = qAll(
    "SELECT v.id, v.numero, v.fecha, v.cliente_id, v.ncf,
            COALESCE(SUM(vp.monto),0) AS credito
       FROM ventas v
       JOIN venta_pagos vp  ON vp.venta_id = v.id
       JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id AND mp.es_credito = 1
      WHERE v.estado = 'completada' AND $scope
      GROUP BY v.id
      ORDER BY v.cliente_id, v.fecha ASC",
    $scopeP
);

/* ---------- Abonos por cliente ---------- */
$abonos = [];
foreach (qAll("SELECT cliente_id, COALESCE(SUM(monto),0) t FROM pagos_clientes GROUP BY cliente_id") as $r) {
    $abonos[(int) $r['cliente_id']] = (float) $r['t'];
}

/* ---------- Clientes con saldo ---------- */
$clientes = [];
foreach (qAll(
    "SELECT id, codigo, nombre, telefono, email, balance, limite_credito, tipo
       FROM clientes WHERE balance > 0 OR id IN (SELECT DISTINCT cliente_id FROM ventas WHERE cliente_id IS NOT NULL)"
) as $c) {
    $clientes[(int) $c['id']] = $c;
}

/* ---------- Reparto FIFO ---------- */
$buckets = ['corriente' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90mas' => 0.0];
$porCliente = [];
$pendientes = [];
$restante   = $abonos;

foreach ($facturas as $f) {
    $cid = (int) $f['cliente_id'];
    if (!isset($clientes[$cid])) continue;

    $monto = (float) $f['credito'];
    $pagoDisponible = $restante[$cid] ?? 0.0;
    $aplicado = min($monto, $pagoDisponible);
    $restante[$cid] = $pagoDisponible - $aplicado;
    $saldo = round($monto - $aplicado, 2);
    if ($saldo <= 0.009) continue;

    $dias = (int) floor((strtotime($corte) - strtotime(date('Y-m-d', strtotime($f['fecha'])))) / 86400);
    $b = $dias <= 30 ? 'corriente' : ($dias <= 60 ? 'b30' : ($dias <= 90 ? 'b60' : ($dias <= 120 ? 'b90' : 'b90mas')));
    $buckets[$b] += $saldo;

    if (!isset($porCliente[$cid])) {
        $porCliente[$cid] = ['cliente' => $clientes[$cid], 'total' => 0.0, 'facturas' => 0,
                             'buckets' => ['corriente' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'b90mas' => 0.0],
                             'mas_vieja' => $f['fecha']];
    }
    $porCliente[$cid]['total'] += $saldo;
    $porCliente[$cid]['facturas']++;
    $porCliente[$cid]['buckets'][$b] += $saldo;
    if ($f['fecha'] < $porCliente[$cid]['mas_vieja']) $porCliente[$cid]['mas_vieja'] = $f['fecha'];

    $pendientes[] = ['numero' => $f['numero'], 'ncf' => $f['ncf'], 'fecha' => $f['fecha'],
                     'cliente' => $clientes[$cid]['nombre'], 'monto' => $monto, 'saldo' => $saldo,
                     'dias' => $dias, 'bucket' => $b];
}
uasort($porCliente, fn($a, $b) => $b['total'] <=> $a['total']);
usort($pendientes, fn($a, $b) => $b['dias'] <=> $a['dias']);

$totalCartera = array_sum($buckets);
$vencido = $buckets['b30'] + $buckets['b60'] + $buckets['b90'] + $buckets['b90mas'];

// Saldo oficial (clientes.balance) para contrastar.
$balanceOficial = (float) qVal("SELECT COALESCE(SUM(balance),0) FROM clientes WHERE balance > 0");
$nConSaldo      = (int) qVal("SELECT COUNT(*) FROM clientes WHERE balance > 0");
$excedidos      = qAll(
    "SELECT nombre, balance, limite_credito FROM clientes
      WHERE activo = 1 AND limite_credito > 0 AND balance > limite_credito ORDER BY (balance - limite_credito) DESC LIMIT 10"
);

// Cobranza del periodo (para medir el ritmo de recuperación).
$cobrado = (float) qVal(
    "SELECT COALESCE(SUM(monto),0) FROM pagos_clientes WHERE DATE(fecha) BETWEEN ? AND ?",
    [$p['desde'], $p['hasta']]
);
$facturadoCredito = (float) qVal(
    "SELECT COALESCE(SUM(vp.monto),0)
       FROM ventas v JOIN venta_pagos vp ON vp.venta_id = v.id
       JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id AND mp.es_credito = 1
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scope",
    array_merge([$p['ini'], $p['fin']], $scopeP)
);

$etiquetas = [
    'corriente' => ['Por vencer (0-30 días)', 'emerald'],
    'b30'       => ['31 a 60 días', 'amber'],
    'b60'       => ['61 a 90 días', 'amber'],
    'b90'       => ['91 a 120 días', 'rose'],
    'b90mas'    => ['Más de 120 días', 'rose'],
];

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    foreach ($porCliente as $c) {
        $filas[] = [$c['cliente']['codigo'], $c['cliente']['nombre'], $c['cliente']['telefono'] ?? '',
            $c['facturas'], money($c['buckets']['corriente'], false), money($c['buckets']['b30'], false),
            money($c['buckets']['b60'], false), money($c['buckets']['b90'], false),
            money($c['buckets']['b90mas'], false), money($c['total'], false),
            fechaCorta($c['mas_vieja']), money($c['cliente']['limite_credito'], false)];
    }
    export_tabla('cuentas_por_cobrar_' . $corte,
        ['Código', 'Cliente', 'Teléfono', 'Facturas', '0-30 días', '31-60', '61-90', '91-120', '+120', 'Saldo total', 'Factura más antigua', 'Límite de crédito'],
        $filas, 'Antigüedad de cuentas por cobrar');
}

layout_start('Cuentas por cobrar', 'Antigüedad de saldos al ' . fechaCorta($corte) . ' · ' . rep_alcance_sucursal(), rep_barra_titulo());
echo rep_encabezado_impresion('Cuentas por cobrar', $p);
echo rep_filtros($p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Cartera total', 'valor' => money($totalCartera), 'icono' => 'wallet', 'color' => 'blue',
     'nota' => count($porCliente) . ' cliente(s) con saldo pendiente'],
    ['label' => 'Saldo vencido', 'valor' => money($vencido), 'icono' => 'alert',
     'color' => $vencido > 0 ? 'rose' : 'emerald',
     'nota' => $totalCartera > 0 ? number_format($vencido / $totalCartera * 100, 1) . '% de la cartera' : 'Sin vencidos'],
    ['label' => 'Cobrado en el periodo', 'valor' => money($cobrado), 'icono' => 'cash', 'color' => 'emerald',
     'nota' => 'Abonos recibidos de clientes'],
    ['label' => 'Facturado a crédito', 'valor' => money($facturadoCredito), 'icono' => 'receipt', 'color' => 'violet',
     'nota' => $facturadoCredito > 0 ? 'Recuperación ' . number_format($cobrado / $facturadoCredito * 100, 0) . '%' : 'Sin crédito nuevo'],
]) ?>

<?php if ($vencido > 0): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 border-rose-200 bg-rose-50/40">
    <span class="w-9 h-9 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-4 h-4') ?></span>
    <p class="text-sm text-slate-700">
      <strong><?= money($vencido) ?> están vencidos.</strong>
      <?= money($buckets['b90'] + $buckets['b90mas']) ?> llevan más de 90 días — a esa altura la probabilidad de cobro cae fuerte.
      Prioriza la gestión de los clientes que aparecen arriba en la tabla.
    </p>
  </div>
<?php endif; ?>

<!-- Distribución por antigüedad -->
<div class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-5">
  <?php foreach ($etiquetas as $k => [$lbl, $col]):
    $v = $buckets[$k];
    $pct = $totalCartera > 0 ? $v / $totalCartera * 100 : 0;
    $bg = ['emerald' => 'bg-emerald-50 text-emerald-600', 'amber' => 'bg-amber-50 text-amber-600', 'rose' => 'bg-rose-50 text-rose-600'][$col];
  ?>
    <div class="card p-4">
      <div class="w-9 h-9 rounded-xl <?= $bg ?> flex items-center justify-center mb-3"><?= icon('clock', 'w-4 h-4') ?></div>
      <p class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($v) ?></p>
      <p class="text-[11.5px] font-semibold text-slate-500 mt-0.5"><?= e($lbl) ?></p>
      <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden mt-2">
        <div class="h-full rounded-full" style="width:<?= max($pct, 0.5) ?>%;background:<?= rep_color_nombre($col) ?>"></div>
      </div>
      <p class="text-[11px] text-slate-400 mt-1"><?= number_format($pct, 1) ?>% de la cartera</p>
    </div>
  <?php endforeach; ?>
</div>

<!-- Por cliente -->
<?= rep_seccion('Antigüedad por cliente', 'Ordenado por saldo pendiente', 'users', 'blue',
    can('clientes.ver') ? '<a href="' . e(url('modules/pos/cuentas_cobrar.php')) . '" class="btn btn-soft btn-sm no-print">' . icon('cash', 'w-3.5 h-3.5') . ' Registrar abono</a>' : '') ?>
  <?php
  $filas = [];
  foreach ($porCliente as $c) {
      $cl = $c['cliente'];
      $diasViejo = (int) floor((strtotime($corte) - strtotime(date('Y-m-d', strtotime($c['mas_vieja'])))) / 86400);
      $sobre = (float) $cl['limite_credito'] > 0 && (float) $cl['balance'] > (float) $cl['limite_credito'];
      $filas[] = [
          '<div class="flex items-center gap-2.5">' . avatar($cl['nombre'], 'w-8 h-8')
            . '<div class="min-w-0"><span class="font-semibold text-slate-700 block truncate">' . e($cl['nombre']) . '</span>'
            . '<span class="text-[11px] text-slate-400">' . e($cl['codigo']) . ($cl['telefono'] ? ' · ' . e($cl['telefono']) : '') . '</span></div></div>',
          '<span class="text-slate-500 tabular-nums">' . $c['facturas'] . '</span>',
          '<span class="text-emerald-600 tabular-nums">' . ($c['buckets']['corriente'] > 0 ? money($c['buckets']['corriente'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-amber-600 tabular-nums">' . ($c['buckets']['b30'] > 0 ? money($c['buckets']['b30'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-amber-700 tabular-nums">' . ($c['buckets']['b60'] > 0 ? money($c['buckets']['b60'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-rose-600 tabular-nums">' . ($c['buckets']['b90'] > 0 ? money($c['buckets']['b90'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-rose-700 font-semibold tabular-nums">' . ($c['buckets']['b90mas'] > 0 ? money($c['buckets']['b90mas'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($c['total']) . '</span>'
            . ($sobre ? '<span class="block text-[10px] font-semibold text-rose-600">sobre su límite</span>' : ''),
          '<span class="badge badge-' . ($diasViejo > 90 ? 'rose' : ($diasViejo > 30 ? 'amber' : 'slate')) . '">' . $diasViejo . ' d</span>',
      ];
  }
  $sumB = fn($k) => $buckets[$k];
  echo rep_tabla(
      ['Cliente', ['Fact.', 'center'], ['0-30', 'right'], ['31-60', 'right'], ['61-90', 'right'], ['91-120', 'right'], ['+120', 'right'], ['Saldo total', 'right'], ['Antigüedad', 'center']],
      $filas,
      ['total' => $filas ? ['Total cartera', '', money($sumB('corriente')), money($sumB('b30')), money($sumB('b60')),
          money($sumB('b90')), money($sumB('b90mas')), money($totalCartera), ''] : null,
       'vacio_titulo' => 'Sin cuentas por cobrar',
       'vacio' => 'Ningún cliente tiene facturas a crédito pendientes. Toda la venta está cobrada.',
       'vacio_icono' => 'check']
  );
  ?>
<?= rep_fin() ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Facturas más viejas -->
  <div>
    <?= rep_seccion('Facturas más atrasadas', 'Las 15 que más tiempo llevan sin cobrarse', 'receipt', 'rose') ?>
      <?php
      $filas = [];
      foreach (array_slice($pendientes, 0, 15) as $f) {
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($f['numero']) . '</span>'
                . ($f['ncf'] ? '<span class="block text-[10.5px] text-slate-400">' . e($f['ncf']) . '</span>' : ''),
              '<span class="text-slate-600">' . e($f['cliente']) . '</span>',
              '<span class="text-slate-500">' . fechaCorta($f['fecha']) . '</span>',
              '<span class="font-bold text-slate-800 tabular-nums">' . money($f['saldo']) . '</span>',
              '<span class="badge badge-' . ($f['dias'] > 90 ? 'rose' : ($f['dias'] > 30 ? 'amber' : 'slate')) . '">' . $f['dias'] . ' días</span>',
          ];
      }
      echo rep_tabla(['Factura', 'Cliente', ['Fecha', 'center'], ['Saldo', 'right'], ['Atraso', 'center']], $filas,
          ['vacio_titulo' => 'Nada atrasado', 'vacio' => 'No hay facturas a crédito pendientes.', 'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>

  <!-- Control de crédito -->
  <div>
    <?= rep_seccion('Control de límites de crédito', 'Clientes que superan la línea autorizada', 'shield', 'amber') ?>
      <?php
      $filas = [];
      foreach ($excedidos as $x) {
          $exceso = (float) $x['balance'] - (float) $x['limite_credito'];
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($x['nombre']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . money($x['limite_credito']) . '</span>',
              '<span class="font-semibold text-slate-800 tabular-nums">' . money($x['balance']) . '</span>',
              '<span class="font-bold text-rose-600 tabular-nums">' . money($exceso) . '</span>',
          ];
      }
      echo rep_tabla(['Cliente', ['Límite', 'right'], ['Saldo', 'right'], ['Exceso', 'right']], $filas,
          ['vacio_titulo' => 'Todo dentro del límite',
           'vacio' => 'Ningún cliente supera su línea de crédito autorizada.', 'vacio_icono' => 'check']);
      ?>
      <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
        Saldo oficial registrado en las fichas de cliente: <strong><?= money($balanceOficial) ?></strong> en <?= $nConSaldo ?> cliente(s).
        <?php if (abs($balanceOficial - $totalCartera) > 1): ?>
          La antigüedad reconstruida suma <?= money($totalCartera) ?>; la diferencia suele venir de abonos aplicados sin factura de respaldo.
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<?php layout_end(); ?>
