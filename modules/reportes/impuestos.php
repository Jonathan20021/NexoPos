<?php
/**
 * ITBIS y retenciones.
 *
 * El número que hay que declarar en el IT-1: ITBIS cobrado en ventas menos el
 * adelantado en compras, más las retenciones. Con el desglose mensual para ver
 * el histórico de la obligación.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.contabilidad');

$p = rep_periodo('mes');
[$scopeV, $scopeVP] = rep_scope('v.sucursal_id');
[$scopeC, $scopeCP] = rep_scope('c.sucursal_id');
[$scopeD, $scopeDP] = rep_scope('d.sucursal_id');

/* ---------- ITBIS del periodo ---------- */
$ventas = qOne(
    "SELECT COUNT(*) n,
            COALESCE(SUM(v.subtotal - v.descuento),0) base,
            COALESCE(SUM(v.itbis),0) itbis,
            COALESCE(SUM(v.itbis_retenido_terceros),0) ret_terceros,
            COALESCE(SUM(v.itbis_percibido),0) percibido,
            COALESCE(SUM(v.retencion_renta_terceros),0) isr_terceros,
            COALESCE(SUM(v.impuesto_selectivo),0) selectivo,
            COALESCE(SUM(v.propina_legal),0) propina,
            COALESCE(SUM(CASE WHEN v.tipo_comprobante = 'credito_fiscal' THEN v.itbis ELSE 0 END),0) itbis_b01
       FROM ventas v
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeV",
    array_merge([$p['ini'], $p['fin']], $scopeVP)
) ?: [];

$compras = qOne(
    "SELECT COUNT(*) n,
            COALESCE(SUM(c.subtotal),0) base,
            COALESCE(SUM(c.itbis),0) itbis,
            COALESCE(SUM(c.itbis_retenido),0) itbis_ret,
            COALESCE(SUM(c.itbis_proporcionalidad),0) proporcion,
            COALESCE(SUM(c.itbis_costo),0) al_costo,
            COALESCE(SUM(c.monto_retencion_renta),0) isr_ret,
            COALESCE(SUM(c.impuesto_selectivo),0) selectivo,
            COALESCE(SUM(c.total),0) total
       FROM compras c
      WHERE c.estado <> 'anulada' AND c.fecha BETWEEN ? AND ? AND $scopeC",
    array_merge([$p['desde'], $p['hasta']], $scopeCP)
) ?: [];

$notasCredito = qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(d.subtotal),0) base, COALESCE(SUM(d.itbis),0) itbis
       FROM devoluciones d WHERE d.created_at BETWEEN ? AND ? AND $scopeD",
    array_merge([$p['ini'], $p['fin']], $scopeDP)
) ?: [];

$debitoFiscal  = (float) ($ventas['itbis'] ?? 0) - (float) ($notasCredito['itbis'] ?? 0);
$creditoFiscal = (float) ($compras['itbis'] ?? 0) - (float) ($compras['al_costo'] ?? 0);
$retenido      = (float) ($ventas['ret_terceros'] ?? 0);
$aPagar        = $debitoFiscal - $creditoFiscal - $retenido;

/* ---------- Histórico mensual ---------- */
$meses = rep_meses_atras(12);
$mv = [];
foreach (qAll(
    "SELECT DATE_FORMAT(v.fecha,'%Y-%m') ym, COALESCE(SUM(v.itbis),0) itbis, COALESCE(SUM(v.subtotal - v.descuento),0) base
       FROM ventas v WHERE v.estado='completada' AND v.fecha >= ? AND $scopeV GROUP BY ym",
    array_merge([$meses[0] . '-01 00:00:00'], $scopeVP)
) as $r) $mv[$r['ym']] = $r;

$mc = [];
foreach (qAll(
    "SELECT DATE_FORMAT(c.fecha,'%Y-%m') ym, COALESCE(SUM(c.itbis),0) itbis
       FROM compras c WHERE c.estado <> 'anulada' AND c.fecha >= ? AND $scopeC GROUP BY ym",
    array_merge([$meses[0] . '-01'], $scopeCP)
) as $r) $mc[$r['ym']] = (float) $r['itbis'];

$md = [];
foreach (qAll(
    "SELECT DATE_FORMAT(d.created_at,'%Y-%m') ym, COALESCE(SUM(d.itbis),0) itbis
       FROM devoluciones d WHERE d.created_at >= ? AND $scopeD GROUP BY ym",
    array_merge([$meses[0] . '-01 00:00:00'], $scopeDP)
) as $r) $md[$r['ym']] = (float) $r['itbis'];

$historico = [];
$labels = $sDeb = $sCre = [];
foreach ($meses as $ym) {
    $deb = (float) ($mv[$ym]['itbis'] ?? 0) - ($md[$ym] ?? 0);
    $cre = $mc[$ym] ?? 0;
    $historico[] = ['ym' => $ym, 'base' => (float) ($mv[$ym]['base'] ?? 0), 'debito' => $deb,
                    'credito' => $cre, 'pagar' => $deb - $cre];
    $labels[] = rep_mes_label($ym);
    $sDeb[]   = $deb;
    $sCre[]   = $cre;
}

/* ---------- Comprobantes por tipo ---------- */
$porComprobante = qAll(
    "SELECT v.tipo_comprobante, COUNT(*) n, COALESCE(SUM(v.subtotal - v.descuento),0) base, COALESCE(SUM(v.itbis),0) itbis
       FROM ventas v WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scopeV
      GROUP BY v.tipo_comprobante",
    array_merge([$p['ini'], $p['fin']], $scopeVP)
);

/* ---------- Ventas exentas ---------- */
$exentas = (float) qVal(
    "SELECT COALESCE(SUM(vd.subtotal - vd.descuento),0)
       FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
       LEFT JOIN productos pr ON pr.id = vd.producto_id
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scopeV
        AND (pr.itbis_aplica = 0 OR vd.itbis = 0)",
    array_merge([$p['ini'], $p['fin']], $scopeVP)
);

$tasa = (float) setting('itbis_tasa', 18);

if (export_solicitado()) {
    $filas = [];
    foreach ($historico as $h) {
        $filas[] = [rep_mes_label($h['ym']), money($h['base'], false), money($h['debito'], false),
                    money($h['credito'], false), money($h['pagar'], false)];
    }
    export_tabla('itbis_' . $p['desde'] . '_' . $p['hasta'],
        ['Periodo', 'Base imponible', 'ITBIS cobrado (débito)', 'ITBIS pagado (crédito)', 'Saldo a pagar'],
        $filas, 'ITBIS por periodo');
}

if (quiere_pdf() && function_exists('pdf_render')) {
    $H = pdf_brand_header('ITBIS Y RETENCIONES',
        'Periodo ' . fechaCorta($p['desde']) . ' al ' . fechaCorta($p['hasta']) . ' · ' . rep_alcance_sucursal());
    $H .= '<h3>Liquidación del periodo</h3><table class="tbl"><tbody>'
        . '<tr><td>Ventas gravadas (base imponible)</td><td class="num">' . money($ventas['base'] ?? 0) . '</td></tr>'
        . '<tr><td>ITBIS facturado en ventas</td><td class="num">' . money($ventas['itbis'] ?? 0) . '</td></tr>'
        . '<tr><td>(−) ITBIS de notas de crédito</td><td class="num">' . money($notasCredito['itbis'] ?? 0) . '</td></tr>'
        . '<tr style="background:#f1f5f9"><td><strong>Débito fiscal</strong></td><td class="num"><strong>' . money($debitoFiscal) . '</strong></td></tr>'
        . '<tr><td>(−) ITBIS adelantado en compras</td><td class="num">' . money($creditoFiscal) . '</td></tr>'
        . '<tr><td>(−) ITBIS retenido por terceros</td><td class="num">' . money($retenido) . '</td></tr>'
        . '<tr style="background:#eff6ff"><td><strong>' . ($aPagar >= 0 ? 'ITBIS A PAGAR' : 'SALDO A FAVOR') . '</strong></td>'
        . '<td class="num"><strong>' . money(abs($aPagar)) . '</strong></td></tr>'
        . '</tbody></table>';
    $H .= '<h3>Histórico mensual</h3><table class="tbl"><thead><tr><th>Periodo</th><th class="num">Base</th><th class="num">Débito</th><th class="num">Crédito</th><th class="num">A pagar</th></tr></thead><tbody>';
    foreach ($historico as $h) {
        $H .= '<tr><td>' . rep_mes_label($h['ym']) . '</td><td class="num">' . money($h['base']) . '</td><td class="num">'
            . money($h['debito']) . '</td><td class="num">' . money($h['credito']) . '</td><td class="num">' . money($h['pagar']) . '</td></tr>';
    }
    $H .= '</tbody></table>';
    pdf_render($H, 'itbis_' . $p['desde'] . '_a_' . $p['hasta'], 'portrait');
}

$accionesExtra = can('dgii.ver')
    ? '<a href="' . e(url('modules/finanzas/it1.php')) . '" class="btn btn-soft no-print">' . icon('percent', 'w-4 h-4') . ' Formulario IT-1</a>'
    : '';

layout_start('ITBIS y retenciones', rep_subtitulo($p), rep_barra_titulo($accionesExtra));
echo rep_abrir('ITBIS y retenciones', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Débito fiscal', 'valor' => money($debitoFiscal), 'icono' => 'arrow-up', 'color' => 'blue',
     'nota' => 'ITBIS cobrado en ' . number_format((int) ($ventas['n'] ?? 0)) . ' factura(s)'],
    ['label' => 'Crédito fiscal', 'valor' => money($creditoFiscal), 'icono' => 'arrow-down', 'color' => 'emerald',
     'nota' => 'ITBIS adelantado en ' . number_format((int) ($compras['n'] ?? 0)) . ' compra(s)'],
    ['label' => 'Retenido por terceros', 'valor' => money($retenido), 'icono' => 'shield', 'color' => 'violet',
     'nota' => 'Se descuenta de lo que hay que pagar'],
    ['label' => $aPagar >= 0 ? 'ITBIS a pagar' : 'Saldo a favor', 'valor' => money(abs($aPagar)), 'icono' => 'bank',
     'color' => $aPagar >= 0 ? 'amber' : 'emerald', 'nota' => 'Vence el día 20 del mes siguiente'],
]) ?>

<!-- Liquidación -->
<?= rep_seccion('Liquidación del periodo', 'Así se arma el número que va al IT-1', 'percent', 'blue') ?>
  <div class="divide-y divide-slate-100">
    <?php
    $lineas = [
        ['Ventas gravadas (base imponible)', (float) ($ventas['base'] ?? 0) - $exentas, 'normal'],
        ['Ventas exentas de ITBIS', $exentas, 'normal'],
        ['ITBIS facturado a clientes (' . number_format($tasa, 0) . '%)', (float) ($ventas['itbis'] ?? 0), 'normal'],
        ['(−) ITBIS de notas de crédito B04', -(float) ($notasCredito['itbis'] ?? 0), 'normal'],
        ['Débito fiscal del periodo', $debitoFiscal, 'sub'],
        ['(−) ITBIS pagado en compras', -(float) ($compras['itbis'] ?? 0), 'normal'],
        ['(+) ITBIS llevado al costo (no deducible)', (float) ($compras['al_costo'] ?? 0), 'normal'],
        ['Crédito fiscal del periodo', -$creditoFiscal, 'sub'],
        ['(−) ITBIS retenido por terceros', -$retenido, 'normal'],
        [$aPagar >= 0 ? 'ITBIS A PAGAR A LA DGII' : 'SALDO A FAVOR PARA EL PRÓXIMO PERIODO', abs($aPagar), 'final'],
    ];
    foreach ($lineas as [$lbl, $val, $est]):
      $cls = match ($est) {
        'final' => 'bg-blue-50/70 font-extrabold text-slate-800',
        'sub'   => 'bg-slate-50 font-bold text-slate-800',
        default => 'text-slate-600',
      };
    ?>
      <div class="flex items-center justify-between gap-4 px-5 py-3 <?= $cls ?>">
        <span class="text-sm"><?= e($lbl) ?></span>
        <span class="tabular-nums whitespace-nowrap <?= $val < 0 && $est !== 'final' ? 'text-rose-600' : '' ?>"><?= money($val) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?= rep_fin() ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Comprobantes -->
  <div>
    <?= rep_seccion('Por tipo de comprobante', 'Crédito fiscal (B01) contra consumidor final (B02)', 'receipt', 'indigo') ?>
      <?php
      $nombres = ['credito_fiscal' => ['Crédito fiscal · B01', 'blue'], 'consumidor' => ['Consumidor final · B02', 'slate']];
      $filas = [];
      foreach ($porComprobante as $c) {
          [$lbl, $col] = $nombres[$c['tipo_comprobante']] ?? [$c['tipo_comprobante'], 'slate'];
          $filas[] = [
              badge($lbl, $col),
              '<span class="text-slate-500 tabular-nums">' . number_format((int) $c['n']) . '</span>',
              '<span class="text-slate-700 font-semibold tabular-nums">' . money($c['base']) . '</span>',
              '<span class="font-bold text-slate-800 tabular-nums">' . money($c['itbis']) . '</span>',
          ];
      }
      echo rep_tabla(['Tipo', ['Facturas', 'center'], ['Base', 'right'], ['ITBIS', 'right']], $filas,
          ['total' => $filas ? ['Total', number_format((int) array_sum(array_column($porComprobante, 'n'))),
              money(array_sum(array_column($porComprobante, 'base'))),
              money(array_sum(array_column($porComprobante, 'itbis')))] : null]);
      ?>
    <?= rep_fin() ?>
  </div>

  <!-- Otras obligaciones -->
  <div>
    <?= rep_seccion('Otras obligaciones del periodo', 'Retenciones e impuestos adicionales', 'shield', 'violet') ?>
      <?php
      $otros = [
          ['Retención de ISR a terceros (ventas)', (float) ($ventas['isr_terceros'] ?? 0)],
          ['Retención de ISR practicada (compras)', (float) ($compras['isr_ret'] ?? 0)],
          ['ITBIS retenido en compras', (float) ($compras['itbis_ret'] ?? 0)],
          ['ITBIS percibido', (float) ($ventas['percibido'] ?? 0)],
          ['Impuesto selectivo al consumo', (float) ($ventas['selectivo'] ?? 0) + (float) ($compras['selectivo'] ?? 0)],
          ['Propina legal (10%)', (float) ($ventas['propina'] ?? 0)],
      ];
      $filas = [];
      foreach ($otros as [$lbl, $val]) {
          $filas[] = [
              '<span class="' . ($val > 0 ? 'font-semibold text-slate-700' : 'text-slate-400') . '">' . e($lbl) . '</span>',
              '<span class="' . ($val > 0 ? 'font-bold text-slate-800' : 'text-slate-300') . ' tabular-nums">' . money($val) . '</span>',
          ];
      }
      echo rep_tabla(['Concepto', ['Monto', 'right']], $filas);
      ?>
      <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
        Las retenciones practicadas a proveedores se reportan en el <strong>606</strong>; las ventas, en el <strong>607</strong>;
        los comprobantes anulados, en el <strong>608</strong>.
        <?php if (can('dgii.ver')): ?>
          <a href="<?= e(url('modules/finanzas/dgii.php')) ?>" class="font-semibold text-blue-600 hover:text-blue-700 no-print">Generar los archivos →</a>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Histórico -->
<?= rep_seccion('Histórico de ITBIS, 12 meses', 'Débito contra crédito fiscal mes a mes', 'trending', 'amber') ?>
  <div class="px-5 pb-5">
    <?= lineChart([
        ['nombre' => 'Débito fiscal (ventas)', 'color' => '#2563eb', 'valores' => $sDeb, 'area' => true],
        ['nombre' => 'Crédito fiscal (compras)', 'color' => '#10b981', 'valores' => $sCre],
    ], $labels, ['alto' => 260]) ?>
  </div>
  <?php
  $filas = [];
  foreach (array_reverse($historico) as $h) {
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e(rep_mes_label($h['ym'])) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($h['base']) . '</span>',
          '<span class="text-slate-600 tabular-nums">' . money($h['debito']) . '</span>',
          '<span class="text-slate-600 tabular-nums">' . money($h['credito']) . '</span>',
          '<span class="font-bold ' . ($h['pagar'] >= 0 ? 'text-slate-800' : 'text-emerald-600') . ' tabular-nums">' . money($h['pagar']) . '</span>',
      ];
  }
  echo rep_tabla(['Periodo', ['Base imponible', 'right'], ['Débito fiscal', 'right'], ['Crédito fiscal', 'right'], ['A pagar', 'right']], $filas);
  ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
