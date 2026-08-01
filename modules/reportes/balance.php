<?php
/**
 * Balance general (situación) a una fecha.
 *
 * Se deriva de los saldos vivos del sistema. No hay catálogo contable formal, así
 * que el patrimonio se calcula por diferencia (activo − pasivo): es el capital
 * más los resultados acumulados que la operación ha generado.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.contabilidad');

$p = rep_periodo('mes');
$corte = $p['hasta'] > date('Y-m-d') ? date('Y-m-d') : $p['hasta'];
[$scopeS, $scopeSP] = rep_scope('s.sucursal_id');
[$scopeV, $scopeVP] = rep_scope('v.sucursal_id');
[$scopeC, $scopeCP] = rep_scope('c.sucursal_id');

/* ============================================================
 *  ACTIVO
 * ============================================================ */
$efectivo = (float) qVal("SELECT COALESCE(SUM(balance),0) FROM cuentas_financieras WHERE activo = 1 AND tipo = 'efectivo'");
$bancos   = (float) qVal("SELECT COALESCE(SUM(balance),0) FROM cuentas_financieras WHERE activo = 1 AND tipo <> 'efectivo'");
$cxc      = (float) qVal("SELECT COALESCE(SUM(balance),0) FROM clientes WHERE balance > 0");
$inventario = (float) qVal(
    "SELECT COALESCE(SUM(s.cantidad * pr.precio_compra),0)
       FROM inventario_stock s JOIN productos pr ON pr.id = s.producto_id AND pr.activo = 1
      WHERE $scopeS",
    $scopeSP
);
// ITBIS adelantado del periodo fiscal en curso (crédito fiscal aún no aplicado).
$mesFiscal = date('Y-m', strtotime($corte));
$itbisCompras = (float) qVal(
    "SELECT COALESCE(SUM(c.itbis),0) FROM compras c
      WHERE c.estado <> 'anulada' AND DATE_FORMAT(c.fecha,'%Y-%m') = ? AND $scopeC",
    array_merge([$mesFiscal], $scopeCP)
);
$itbisVentas = (float) qVal(
    "SELECT COALESCE(SUM(v.itbis),0) FROM ventas v
      WHERE v.estado = 'completada' AND DATE_FORMAT(v.fecha,'%Y-%m') = ? AND $scopeV",
    array_merge([$mesFiscal], $scopeVP)
);
$itbisSaldo = $itbisVentas - $itbisCompras;   // > 0 → por pagar; < 0 → a favor

$activoCorriente = $efectivo + $bancos + $cxc + $inventario + max(0, -$itbisSaldo);

// Activo fijo: lo que la empresa posee y no se vende (mobiliario, equipos,
// vehículos), a su valor en libros = costo − depreciación acumulada.
$af = activosResumen(current_sucursal_id());
$activoFijo  = $af['neto'];
$activoTotal = $activoCorriente + $activoFijo;

/* ============================================================
 *  PASIVO
 * ============================================================ */
$cxp = (float) qVal(
    "SELECT COALESCE(SUM(c.total),0) FROM compras c
      WHERE c.estado <> 'anulada' AND c.forma_pago = 4 AND c.fecha_pago IS NULL AND $scopeC",
    $scopeCP
);
$nominaPorPagar = (float) qVal(
    "SELECT COALESCE(SUM(total_neto),0) FROM nominas WHERE estado = 'procesada'"
);
$comisionesPorPagar = (float) qVal(
    "SELECT COALESCE(SUM(monto),0) FROM comisiones WHERE estado IN ('pendiente','aprobada')"
);
$itbisPorPagar = max(0, $itbisSaldo);
$pasivoTotal = $cxp + $nominaPorPagar + $comisionesPorPagar + $itbisPorPagar;

$patrimonio = $activoTotal - $pasivoTotal;

/* ============================================================
 *  Resultado acumulado del año (para explicar el patrimonio)
 * ============================================================ */
$inicioAnio = date('Y-01-01');
$ingAnio = (float) qVal(
    "SELECT COALESCE(SUM(v.subtotal - v.descuento),0) FROM ventas v
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scopeV",
    array_merge([$inicioAnio . ' 00:00:00', $corte . ' 23:59:59'], $scopeVP)
);
$cosAnio = (float) qVal(
    "SELECT COALESCE(SUM(v.costo_total),0) FROM ventas v
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scopeV",
    array_merge([$inicioAnio . ' 00:00:00', $corte . ' 23:59:59'], $scopeVP)
);
$gasAnio = rep_gastos_operativos($inicioAnio, $corte);
$resultadoAnio = $ingAnio - $cosAnio - $gasAnio;

/* ---------- Indicadores ---------- */
$razonCorriente = $pasivoTotal > 0 ? $activoCorriente / $pasivoTotal : null;
$capitalTrabajo = $activoCorriente - $pasivoTotal;
$pruebaAcida    = $pasivoTotal > 0 ? ($efectivo + $bancos + $cxc) / $pasivoTotal : null;
$endeudamiento  = $activoTotal > 0 ? $pasivoTotal / $activoTotal * 100 : 0;

$activo = [
    ['Efectivo en caja', $efectivo, 'Dinero disponible en las cajas'],
    ['Bancos y otras cuentas', $bancos, 'Cuentas bancarias, tarjetas y transferencias'],
    ['Cuentas por cobrar', $cxc, 'Saldo pendiente de los clientes a crédito'],
    ['Inventario de mercancías', $inventario, 'Existencias valoradas a costo de compra'],
];
if ($itbisSaldo < 0) $activo[] = ['ITBIS a favor', -$itbisSaldo, 'Crédito fiscal del periodo ' . $mesFiscal];
if ($af['cantidad'] > 0) {
    $activo[] = ['Activos fijos (valor en libros)', $activoFijo,
        $af['cantidad'] . ' activo(s) · costo ' . money($af['costo']) . ' − depreciación ' . money($af['depreciacion'])];
}

$pasivo = [
    ['Cuentas por pagar a proveedores', $cxp, 'Compras a crédito sin fecha de pago'],
    ['ITBIS por pagar a la DGII', $itbisPorPagar, 'Débito menos crédito fiscal de ' . $mesFiscal],
    ['Nómina por pagar', $nominaPorPagar, 'Nóminas procesadas aún no pagadas'],
    ['Comisiones por pagar', $comisionesPorPagar, 'Comisiones pendientes o aprobadas sin pagar'],
];

if (export_solicitado()) {
    $filas = [['ACTIVO', '', '']];
    foreach ($activo as [$l, $v]) $filas[] = ['  ' . $l, money($v, false), ''];
    $filas[] = ['TOTAL ACTIVO', money($activoTotal, false), ''];
    $filas[] = ['PASIVO', '', ''];
    foreach ($pasivo as [$l, $v]) $filas[] = ['  ' . $l, money($v, false), ''];
    $filas[] = ['TOTAL PASIVO', money($pasivoTotal, false), ''];
    $filas[] = ['PATRIMONIO (capital + resultados acumulados)', money($patrimonio, false), ''];
    $filas[] = ['TOTAL PASIVO + PATRIMONIO', money($pasivoTotal + $patrimonio, false), ''];
    export_tabla('balance_general_' . $corte, ['Concepto', 'Monto', ''], $filas, 'Balance general al ' . fechaCorta($corte));
}

if (quiere_pdf() && function_exists('pdf_render')) {
    $H = pdf_brand_header('BALANCE GENERAL', 'Al ' . fechaCorta($corte) . ' · ' . rep_alcance_sucursal());
    $H .= '<h3>Activo</h3><table class="tbl"><tbody>';
    foreach ($activo as [$l, $v]) $H .= '<tr><td>' . htmlspecialchars($l) . '</td><td class="num">' . money($v) . '</td></tr>';
    $H .= '<tr style="background:#f1f5f9"><td><strong>Total activo</strong></td><td class="num"><strong>' . money($activoTotal) . '</strong></td></tr></tbody></table>';
    $H .= '<h3>Pasivo</h3><table class="tbl"><tbody>';
    foreach ($pasivo as [$l, $v]) $H .= '<tr><td>' . htmlspecialchars($l) . '</td><td class="num">' . money($v) . '</td></tr>';
    $H .= '<tr style="background:#f1f5f9"><td><strong>Total pasivo</strong></td><td class="num"><strong>' . money($pasivoTotal) . '</strong></td></tr></tbody></table>';
    $H .= '<h3>Patrimonio</h3><table class="tbl"><tbody>'
        . '<tr><td>Capital y resultados acumulados</td><td class="num">' . money($patrimonio) . '</td></tr>'
        . '<tr style="background:#eff6ff"><td><strong>Total pasivo + patrimonio</strong></td><td class="num"><strong>' . money($pasivoTotal + $patrimonio) . '</strong></td></tr>'
        . '</tbody></table>';
    pdf_render($H, 'balance_general_' . $corte, 'portrait');
}

layout_start('Balance general', 'Situación al ' . fechaCorta($corte) . ' · ' . rep_alcance_sucursal(), rep_barra_titulo());
echo rep_encabezado_impresion('Balance general', $p);
echo rep_filtros($p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Total activo', 'valor' => money($activoTotal), 'icono' => 'bank', 'color' => 'blue',
     'nota' => 'Lo que el negocio tiene'],
    ['label' => 'Total pasivo', 'valor' => money($pasivoTotal), 'icono' => 'file', 'color' => 'amber',
     'nota' => 'Lo que el negocio debe'],
    ['label' => 'Patrimonio', 'valor' => money($patrimonio), 'icono' => 'shield',
     'color' => $patrimonio >= 0 ? 'emerald' : 'rose', 'nota' => 'Capital + resultados acumulados'],
    ['label' => 'Capital de trabajo', 'valor' => money($capitalTrabajo), 'icono' => 'pulse',
     'color' => $capitalTrabajo >= 0 ? 'violet' : 'rose',
     'nota' => $capitalTrabajo >= 0 ? 'Holgura para operar' : 'El pasivo supera al activo corriente'],
]) ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- ACTIVO -->
  <div>
    <?= rep_seccion('Activo', 'Los recursos del negocio al ' . fechaCorta($corte), 'bank', 'blue') ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($activo as [$lbl, $val, $desc]): ?>
          <div class="flex items-start justify-between gap-4 px-5 py-3.5">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-700"><?= e($lbl) ?></p>
              <p class="text-[11.5px] text-slate-400 mt-0.5"><?= e($desc) ?></p>
            </div>
            <span class="font-bold text-slate-800 tabular-nums whitespace-nowrap"><?= money($val) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="flex items-center justify-between px-5 py-4 bg-blue-50/60">
          <span class="font-extrabold text-slate-800">TOTAL ACTIVO</span>
          <span class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($activoTotal) ?></span>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-slate-100">
        <?= barraApilada(array_map(fn($a, $i) => ['label' => $a[0], 'value' => (float) $a[1], 'color' => rep_color($i)], $activo, array_keys($activo))) ?>
        <div class="flex flex-wrap gap-3 mt-3">
          <?php foreach ($activo as $i => $a): if ((float) $a[1] <= 0) continue; ?>
            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-500">
              <span class="w-2.5 h-2.5 rounded-full" style="background:<?= rep_color($i) ?>"></span>
              <?= e($a[0]) ?> · <?= number_format($activoTotal > 0 ? (float) $a[1] / $activoTotal * 100 : 0, 1) ?>%
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?= rep_fin() ?>
  </div>

  <!-- PASIVO + PATRIMONIO -->
  <div>
    <?= rep_seccion('Pasivo y patrimonio', 'Cómo está financiado el activo', 'scale', 'amber') ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($pasivo as [$lbl, $val, $desc]): ?>
          <div class="flex items-start justify-between gap-4 px-5 py-3.5">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-700"><?= e($lbl) ?></p>
              <p class="text-[11.5px] text-slate-400 mt-0.5"><?= e($desc) ?></p>
            </div>
            <span class="font-bold text-slate-800 tabular-nums whitespace-nowrap"><?= money($val) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="flex items-center justify-between px-5 py-3.5 bg-amber-50/50">
          <span class="font-bold text-slate-800">TOTAL PASIVO</span>
          <span class="font-extrabold text-slate-800 tabular-nums"><?= money($pasivoTotal) ?></span>
        </div>
        <div class="flex items-start justify-between gap-4 px-5 py-3.5">
          <div>
            <p class="text-sm font-semibold text-slate-700">Capital y resultados acumulados</p>
            <p class="text-[11.5px] text-slate-400 mt-0.5">Activo menos pasivo. Incluye <?= money($resultadoAnio) ?> generados en <?= date('Y') ?>.</p>
          </div>
          <span class="font-bold <?= $patrimonio >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> tabular-nums whitespace-nowrap"><?= money($patrimonio) ?></span>
        </div>
        <div class="flex items-center justify-between px-5 py-4 bg-slate-50">
          <span class="font-extrabold text-slate-800">TOTAL PASIVO + PATRIMONIO</span>
          <span class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($pasivoTotal + $patrimonio) ?></span>
        </div>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Indicadores -->
<?= rep_seccion('Indicadores financieros', 'Lectura rápida de la solidez del negocio', 'pulse', 'violet') ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 p-5 pt-0">
    <?php
    $indicadores = [
      ['Razón corriente', $razonCorriente === null ? '—' : number_format($razonCorriente, 2),
       'Activo corriente / pasivo. Sano por encima de 1.5.',
       $razonCorriente === null ? 'slate' : ($razonCorriente >= 1.5 ? 'emerald' : ($razonCorriente >= 1 ? 'amber' : 'rose'))],
      ['Prueba ácida', $pruebaAcida === null ? '—' : number_format($pruebaAcida, 2),
       'Sin contar el inventario. Sano por encima de 1.',
       $pruebaAcida === null ? 'slate' : ($pruebaAcida >= 1 ? 'emerald' : ($pruebaAcida >= 0.7 ? 'amber' : 'rose'))],
      ['Endeudamiento', number_format($endeudamiento, 1) . '%',
       'Qué parte del activo está financiada con deuda.',
       $endeudamiento <= 40 ? 'emerald' : ($endeudamiento <= 65 ? 'amber' : 'rose')],
      ['Resultado del año', money($resultadoAnio),
       'Utilidad acumulada del 1 de enero al ' . fechaCorta($corte) . '.',
       $resultadoAnio >= 0 ? 'emerald' : 'rose'],
    ];
    $bgs = ['emerald' => 'bg-emerald-50 text-emerald-700', 'amber' => 'bg-amber-50 text-amber-700',
            'rose' => 'bg-rose-50 text-rose-700', 'slate' => 'bg-slate-100 text-slate-500'];
    foreach ($indicadores as [$lbl, $val, $desc, $col]): ?>
      <div class="rounded-2xl border border-slate-200 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= e($lbl) ?></p>
        <p class="text-2xl font-extrabold text-slate-800 mt-1.5 tabular-nums"><?= $val ?></p>
        <span class="inline-block mt-2 px-2 py-0.5 rounded-full text-[11px] font-bold <?= $bgs[$col] ?>">
          <?= $col === 'emerald' ? 'Saludable' : ($col === 'amber' ? 'Vigilar' : ($col === 'rose' ? 'Atención' : 'Sin dato')) ?>
        </span>
        <p class="text-[11.5px] text-slate-400 mt-2 leading-relaxed"><?= e($desc) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
    <strong class="text-slate-600">Alcance.</strong>
    Este balance se construye con los saldos operativos del sistema: caja, bancos, clientes, inventario, proveedores,
    nómina, ITBIS y
    <?php if ($af['cantidad'] > 0): ?>
      los activos fijos registrados a su valor en libros
      (<a href="<?= e(url('modules/finanzas/activos.php')) ?>" class="font-semibold text-blue-600 hover:text-blue-700 no-print">ver el registro</a>).
    <?php else: ?>
      los activos fijos, que todavía no tienen ninguno registrado
      <?php if (can('activos.crear')): ?>
        (<a href="<?= e(url('modules/finanzas/activos.php')) ?>" class="font-semibold text-blue-600 hover:text-blue-700 no-print">registrarlos</a>
        hace que el activo deje de estar subestimado).
      <?php endif; ?>
    <?php endif; ?>
    No incluye préstamos ni aportes de capital que se lleven fuera del sistema: esos los añade la contabilidad formal.
  </div>
<?= rep_fin() ?>

<?php layout_end(); ?>
