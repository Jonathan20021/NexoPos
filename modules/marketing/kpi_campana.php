<?php
/**
 * Tablero de una campaña: el archivo «Holiday KPIs to track», calculado solo.
 *
 *   · KPIs globales por canal (Retail, Mayoreo, Web, Social selling) con meta.
 *   · Día por día contra el año anterior (la hoja «Black Friday»).
 *   · SKUs foco este año contra el anterior.
 *   · Inversión de marketing por rubro, con su peso, MER y CPA.
 *   · Los KPIs para compartir con la casa matriz: los del POS se calculan; los
 *     demás (tráfico, alcance, NPS…) se capturan aquí.
 *
 * Excel: el mismo libro de la marca, con sus hojas y sus encabezados en inglés.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('kpi_campanas.ver');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (!cockpit_disponible()) redirect('modules/marketing/kpi_campanas.php');

$id = (int) get('id');
$c = kpi_campana($id);
if (!$c) { flash('error', 'Campaña no encontrada.'); redirect('modules/marketing/kpi_campanas.php'); }
// Una campaña de otra sucursal no se abre con cambiar el id de la URL.
if (!empty($c['sucursal_id']) && !can_access_sucursal((int) $c['sucursal_id'])) {
    flash('error', 'No tienes acceso a la sucursal de esta campaña.');
    redirect('modules/marketing/kpi_campanas.php');
}
$aqui = 'modules/marketing/kpi_campana.php?id=' . $id;

/* ============================================================
 *  Edición de las piezas de la campaña
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    require_perm('kpi_campanas.editar');
    $accion = post('accion');
    $ancla = '';

    if ($accion === 'metas') {
        foreach (array_keys(cockpit_canales()) as $canal) {
            $meta = max(0, round((float) ($_POST['meta'][$canal] ?? 0), 2));
            q("INSERT INTO kpi_campana_metas (campana_id, canal, meta) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta = VALUES(meta)", [$id, $canal, $meta]);
        }
        flash('success', 'Metas por canal guardadas.');
        $ancla = '#canales';
    }

    if ($accion === 'skus') {
        $orden = 0; $noEncontrados = []; $ids = [];
        foreach (preg_split('/[\s,;]+/', (string) post('codigos')) as $cod) {
            if ($cod === '') continue;
            $pid = (int) qVal("SELECT id FROM productos WHERE codigo = ? OR codigo_barras = ?", [$cod, $cod]);
            if ($pid) { $ids[$pid] = $orden++; } else { $noEncontrados[] = $cod; }
        }
        tx(function () use ($id, $ids) {
            q("DELETE FROM kpi_campana_productos WHERE campana_id = ?", [$id]);
            foreach ($ids as $pid => $o) dbInsert('kpi_campana_productos', ['campana_id' => $id, 'producto_id' => $pid, 'orden' => $o]);
        });
        flash($noEncontrados ? 'warning' : 'success', count($ids) . ' SKU(s) foco guardados.'
            . ($noEncontrados ? ' No existen en el catálogo: ' . implode(', ', array_slice($noEncontrados, 0, 15)) . (count($noEncontrados) > 15 ? '…' : '') : ''));
        $ancla = '#skus';
    }

    if ($accion === 'inversion_agregar') {
        $rubro = (string) post('rubro');
        $monto = round(postNum('monto'), 2);
        if (!array_key_exists($rubro, kpi_rubros_inversion(true)) || $monto <= 0) {
            flash('error', 'Elige el rubro y un monto mayor que cero.');
        } else {
            dbInsert('kpi_campana_inversiones', [
                'campana_id' => $id, 'rubro' => $rubro, 'monto' => $monto,
                'detalle'    => mb_substr(trim((string) post('detalle')), 0, 160) ?: null,
                'resultado'  => mb_substr(trim((string) post('resultado')), 0, 255) ?: null,
            ]);
            flash('success', 'Inversión registrada.');
        }
        $ancla = '#inversion';
    }

    if ($accion === 'inversion_eliminar') {
        q("DELETE FROM kpi_campana_inversiones WHERE id = ? AND campana_id = ?", [postInt('inv_id'), $id]);
        flash('success', 'Inversión eliminada.');
        $ancla = '#inversion';
    }

    if ($accion === 'metricas') {
        $num = function ($v) {
            $v = trim(str_replace(',', '', (string) $v));
            return $v === '' || !is_numeric($v) ? null : round((float) $v, 2);
        };
        foreach (array_keys(kpi_metricas_manuales()) as $k) {
            $val = $num($_POST['valor'][$k] ?? '');
            $ly  = $num($_POST['valor_ly'][$k] ?? '');
            $nota = mb_substr(trim((string) ($_POST['nota'][$k] ?? '')), 0, 255) ?: null;
            if ($val === null && $ly === null && $nota === null) {
                q("DELETE FROM kpi_campana_metricas WHERE campana_id = ? AND metrica = ?", [$id, $k]);
                continue;
            }
            q("INSERT INTO kpi_campana_metricas (campana_id, metrica, valor, valor_ly, nota) VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE valor = VALUES(valor), valor_ly = VALUES(valor_ly), nota = VALUES(nota)",
              [$id, $k, $val, $ly, $nota]);
        }
        flash('success', 'KPIs capturados guardados.');
        $ancla = '#kpis';
    }

    audit('kpi_campanas', 'editar', 'Campaña «' . $c['nombre'] . '»: ' . $accion, ['tabla' => 'kpi_campanas', 'registro_id' => $id]);
    redirect($aqui . $ancla);
}

/* ============================================================
 *  Cálculo
 * ============================================================ */
$R = kpi_resumen($c);
$ty = $R['ty']; $ly = $R['ly']; $T = $ty['total']; $L = $ly['total'];
$canales = cockpit_canales();
// Un canal desactivado que igual recibió ventas se enseña (marcado): si no, las
// filas no sumarían el total.
foreach (cockpit_canales_todos() + array_fill_keys(array_keys($ty), null) as $k => $nombre) {
    if ($k === 'total' || isset($canales[$k])) continue;
    if (($ty[$k]['ns'] ?? 0) != 0 || ($ly[$k]['ns'] ?? 0) != 0) $canales[$k] = ($nombre ?? $k) . ' (inactivo)';
}
$inv = $R['inversion'];
$met = $R['metricas'];
$mval = fn(string $k, string $campo = 'valor') => isset($met[$k][$campo]) && $met[$k][$campo] !== null ? (float) $met[$k][$campo] : null;

$diasTY = kpi_por_dia($c, $c['fecha_inicio'], $c['fecha_fin']);
$diasLY = array_values(kpi_por_dia($c, $c['ly_inicio'], $c['ly_fin']));

// SKUs foco (o, si no hay, las 20 más vendidas para que la hoja no salga vacía).
$foco = qAll("SELECT p.id, p.codigo, p.nombre FROM kpi_campana_productos kp JOIN productos p ON p.id = kp.producto_id
              WHERE kp.campana_id = ? ORDER BY kp.orden, p.nombre", [$id]);
$esTop = !$foco;
if ($esTop) {
    $top = kpi_skus($c, $c['fecha_inicio'], $c['fecha_fin'], null, cockpit_param_int('top_skus'));
    $foco = $top ? qAll("SELECT id, codigo, nombre FROM productos WHERE id IN (" . implode(',', array_keys($top)) . ")") : [];
    usort($foco, fn($a, $b) => ($top[$b['id']]['ns'] ?? 0) <=> ($top[$a['id']]['ns'] ?? 0));
}
$idsFoco = array_map(fn($p) => (int) $p['id'], $foco);
$skuTY = kpi_skus($c, $c['fecha_inicio'], $c['fecha_fin'], $idsFoco);
$skuLY = kpi_skus($c, $c['ly_inicio'], $c['ly_fin'], $idsFoco);

$inversiones = qAll("SELECT * FROM kpi_campana_inversiones WHERE campana_id = ? ORDER BY id", [$id]);
$rubros = kpi_rubros_inversion();
$tasaEur = (float) ($c['tasa_eur'] ?? 0);

// KPIs derivados.
$atv  = fn(array $r) => kpi_div($r['ns'], $r['tickets']);
$upt  = fn(array $r) => kpi_div($r['unidades'], $r['tickets']);
// Qué KPI capturado hace de tráfico y cuál de sesiones web lo decide la configuración.
$kTrafico = kpi_metrica_rol('trafico'); $kSesiones = kpi_metrica_rol('sesiones_web');
$trafico = $kTrafico ? $mval($kTrafico) : null; $traficoLY = $kTrafico ? $mval($kTrafico, 'valor_ly') : null;
$sesiones = $kSesiones ? $mval($kSesiones) : null; $sesionesLY = $kSesiones ? $mval($kSesiones, 'valor_ly') : null;
$kpi = [
    'ns'         => [$T['ns'], $L['ns']],
    'tickets'    => [$T['tickets'], $L['tickets']],
    'atv'        => [$atv($T), $atv($L)],
    'upt'        => [$upt($T), $upt($L)],
    'nuevos'     => [$T['nuevos'], $L['nuevos']],
    'conversion' => [$trafico ? $T['tickets'] / $trafico * 100 : null, $traficoLY ? $L['tickets'] / $traficoLY * 100 : null],
    'cpa'        => [$inv > 0 ? kpi_div($inv, $T['nuevos']) : null, null],
    'mer'        => [$inv > 0 ? $T['ns'] / $inv : null, null],
    // Retorno sobre lo que se vendió DE MÁS contra el año anterior, no sobre
    // la venta entera: la tienda habría vendido algo sin campaña.
    'iroi'       => [$inv > 0 ? ($T['ns'] - $L['ns'] - $inv) / $inv * 100 : null, null],
    'aov_web'    => [$atv($ty['web']), $atv($ly['web'])],
    'conv_web'   => [$sesiones ? $ty['web']['tickets'] / $sesiones * 100 : null, $sesionesLY ? $ly['web']['tickets'] / $sesionesLY * 100 : null],
    'recurr_web' => [$R['rec_web']['clientes'] > 0 ? $R['rec_web']['recurrentes'] / $R['rec_web']['clientes'] * 100 : null, null],
    'recurr'     => [$R['rec_ty']['clientes'] > 0 ? $R['rec_ty']['recurrentes'] / $R['rec_ty']['clientes'] * 100 : null, null],
];

// Qué promociones movieron la campaña: las mismas cuentas que la pestaña
// Efectividad del cockpit, dentro de las fechas y el alcance de la campaña.
$fCamp = ['ty' => [$c['fecha_inicio'], $c['fecha_fin']], 'ly' => [$c['ly_inicio'], $c['ly_fin']], 'canal' => null, 'marca' => null,
          'segmento' => null, 'linea' => null, 'samestore' => false, 'ly_modo' => 'fecha', 'ly_manual' => true,
          'sucursal_fija' => $c['sucursal_id'] ?? null, 'tienda_fija' => $c['tienda_id'] ?? null];
$promosCamp = $c['fecha_inicio'] <= date('Y-m-d') ? cockpit_efectividad($fCamp, [$c['fecha_inicio'], $c['fecha_fin']]) : [];
usort($promosCamp, fn($a, $b) => $b['gs_promo'] <=> $a['gs_promo']);

/* ============================================================
 *  Excel: el libro de la marca
 * ============================================================ */
if (quiere_excel()) {
    while (ob_get_level() > 0) ob_end_clean();
    $ss = new Spreadsheet();
    $amarillo = 'FFF2CC'; $azul = 'DDEBF7'; $gris = 'F2F2F2';
    $cab = function ($sh, string $rango, string $color = 'D9D9D9') {
        $st = $sh->getStyle($rango);
        $st->getFont()->setBold(true);
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        $st->getAlignment()->setWrapText(true)->setVertical('center');
    };
    $pctFmt = '0.0%'; $numFmt = '#,##0.00'; $intFmt = '#,##0';
    $g = fn($ty, $ly) => ($ly ?? 0) != 0 ? ($ty - $ly) / abs($ly) : null;

    // --- SKUS REPORT ---
    $sh = $ss->getActiveSheet()->setTitle('SKUS REPORT');
    $sh->setCellValue('B1', mb_strtoupper($c['nombre']));
    $sh->fromArray(['THIS YEAR ' . substr($c['fecha_inicio'], 0, 4), null, null, null, null, null, 'LAST YEAR ' . substr($c['ly_inicio'], 0, 4), null, null, null, null, null, 'YEAR ON YEAR COMPARISON'], null, 'A2');
    $sh->fromArray(['DATES THIS YEAR', fechaCorta($c['fecha_inicio']) . ' - ' . fechaCorta($c['fecha_fin']), 'TOTAL NS', $T['ns'], null, null,
                    'DATES LAST YEAR', fechaCorta($c['ly_inicio']) . ' - ' . fechaCorta($c['ly_fin']), 'TOTAL NS', $L['ns']], null, 'A3');
    $sh->fromArray(['SKU THIS YEAR', 'DESCRIPTION', 'QTY', 'NET SALES', '% NS', null, 'SKU LAST YEAR', 'DESCRIPTION', 'QTY', 'NET SALES', '% NS', null,
                    'NS VS LY', '% Growth Net Sales VS. LY', 'SHARE OF NS PTS DIFFERENCE'], null, 'A4');
    $cab($sh, 'A2:O4', $amarillo);
    $f = 5;
    foreach ($foco as $p) {
        $a = $skuTY[$p['id']] ?? ['qty' => 0, 'ns' => 0]; $b = $skuLY[$p['id']] ?? ['qty' => 0, 'ns' => 0];
        $pa = $T['ns'] > 0 ? $a['ns'] / $T['ns'] : 0; $pb = $L['ns'] > 0 ? $b['ns'] / $L['ns'] : 0;
        $sh->fromArray([$p['codigo'], $p['nombre'], $a['qty'], $a['ns'], $pa, null, $p['codigo'], $p['nombre'], $b['qty'], $b['ns'], $pb, null,
                        $a['ns'] - $b['ns'], $g($a['ns'], $b['ns']) ?? '-', ($pa - $pb) * 100], null, 'A' . $f);
        $f++;
    }
    $sh->getStyle("D5:D$f")->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle("J5:J$f")->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle("M5:M$f")->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle("E5:E$f")->getNumberFormat()->setFormatCode($pctFmt);
    $sh->getStyle("K5:K$f")->getNumberFormat()->setFormatCode($pctFmt);
    $sh->getStyle("N5:N$f")->getNumberFormat()->setFormatCode($pctFmt);
    $sh->getStyle("O5:O$f")->getNumberFormat()->setFormatCode('0.0');
    $sh->getStyle('D3')->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle('J3')->getNumberFormat()->setFormatCode($numFmt);
    foreach (range('A', 'O') as $col) $sh->getColumnDimension($col)->setWidth(in_array($col, ['B', 'H'], true) ? 36 : 15);

    // --- GLOBAL KPIs ---
    $sh = $ss->createSheet()->setTitle('GLOBAL KPIs');
    $sh->fromArray(['Channel', 'Net Sales TY', 'Net Sales LY', 'Growth vs.LY (%)', 'Net Sales TY vs. Target', 'Target', 'Ticket count TY', 'Ticket count LY',
                    'Growth vs.LY (%)', 'ATV TY', 'ATV LY', 'Growth vs.LY (%)', 'New customers TY', 'New customers LY', 'Growth vs.LY (%)'], null, 'A1');
    $cab($sh, 'A1:O1', $azul);
    $nombresEn = cockpit_canales_en();
    $f = 2;
    foreach (array_merge(array_keys($canales), ['total']) as $k) {
        $a = $ty[$k]; $b = $ly[$k];
        $meta = $k === 'total' ? $R['meta'] : ($R['metas'][$k] ?? 0);
        $sh->fromArray([$k === 'total' ? 'TOTAL' : $nombresEn[$k], $a['ns'], $b['ns'], $g($a['ns'], $b['ns']), $meta > 0 ? ($a['ns'] - $meta) / $meta : null, $meta ?: null,
                        $a['tickets'], $b['tickets'], $g($a['tickets'], $b['tickets']), $atv($a), $atv($b), $g($atv($a) ?? 0, $atv($b)),
                        $a['nuevos'], $b['nuevos'], $g($a['nuevos'], $b['nuevos'])], null, 'A' . $f);
        $f++;
    }
    $cab($sh, 'A6:O6', $gris);
    foreach (['B', 'C', 'F', 'J', 'K'] as $col) $sh->getStyle("{$col}2:{$col}6")->getNumberFormat()->setFormatCode($numFmt);
    foreach (['D', 'E', 'I', 'L', 'O'] as $col) $sh->getStyle("{$col}2:{$col}6")->getNumberFormat()->setFormatCode($pctFmt);
    foreach (range('A', 'O') as $col) $sh->getColumnDimension($col)->setWidth(15);
    $sh->getStyle('A1:O1')->getAlignment()->setWrapText(true);

    // --- Día por día (hoja «Black Friday» del libro original) ---
    $sh = $ss->createSheet()->setTitle('DAILY');
    $sh->fromArray(['Date', 'Net Sales TY', 'Net Sales LY', 'Growth vs.LY (%)', 'Date LY', 'Ticket count TY', 'Ticket count LY', 'Growth vs.LY (%)',
                    'ATV TY', 'ATV LY', 'Growth vs.LY (%)', 'New customers TY', 'New customers LY', 'Growth vs.LY (%)'], null, 'A1');
    $cab($sh, 'A1:N1', $azul);
    $f = 2; $i = 0;
    foreach ($diasTY as $d => $a) {
        $b = $diasLY[$i] ?? ['ns' => 0, 'tickets' => 0, 'nuevos' => 0];
        $dLY = date('Y-m-d', strtotime($c['ly_inicio'] . " +$i day"));
        $sh->fromArray([fechaCorta($d), $a['ns'], $b['ns'], $g($a['ns'], $b['ns']), $dLY <= $c['ly_fin'] ? fechaCorta($dLY) : '', $a['tickets'], $b['tickets'], $g($a['tickets'], $b['tickets']),
                        kpi_div($a['ns'], $a['tickets']), kpi_div($b['ns'], $b['tickets']), $g(kpi_div($a['ns'], $a['tickets']) ?? 0, kpi_div($b['ns'], $b['tickets'])),
                        $a['nuevos'], $b['nuevos'], $g($a['nuevos'], $b['nuevos'])], null, 'A' . $f);
        $f++; $i++;
    }
    $sh->fromArray(['TOTAL', $T['ns'], $L['ns'], $g($T['ns'], $L['ns']), null, $T['tickets'], $L['tickets'], $g($T['tickets'], $L['tickets']),
                    $atv($T), $atv($L), $g($atv($T) ?? 0, $atv($L)), $T['nuevos'], $L['nuevos'], $g($T['nuevos'], $L['nuevos'])], null, 'A' . $f);
    $cab($sh, "A$f:N$f", $gris);
    foreach (['B', 'C', 'I', 'J'] as $col) $sh->getStyle("{$col}2:{$col}$f")->getNumberFormat()->setFormatCode($numFmt);
    foreach (['D', 'H', 'K', 'N'] as $col) $sh->getStyle("{$col}2:{$col}$f")->getNumberFormat()->setFormatCode($pctFmt);
    foreach (range('A', 'N') as $col) $sh->getColumnDimension($col)->setWidth(15);

    // --- INVESTMENT ---
    $sh = $ss->createSheet()->setTitle('INVESTMENT');
    $moneda = $tasaEur > 0 ? '€ EUROS' : setting('moneda', 'RD$');
    $sh->fromArray([null, 'FINAL MARKETING INVESTMENT IN ' . $moneda, 'INVESTMENT % SHARE', "RESULTS\n(KPI'S OR GENERAL COMMENTS)"], null, 'A1');
    $cab($sh, 'A1:D1', $amarillo);
    $porRubro = [];
    foreach ($inversiones as $iv) {
        $porRubro[$iv['rubro']]['monto'] = ($porRubro[$iv['rubro']]['monto'] ?? 0) + (float) $iv['monto'];
        $txt = trim(($iv['detalle'] ? $iv['detalle'] . ': ' : '') . ($iv['resultado'] ?? ''));
        if ($txt !== '') $porRubro[$iv['rubro']]['res'][] = $txt;
    }
    $f = 2;
    foreach ($rubros as $k => [$es, $en]) {
        $m = $porRubro[$k]['monto'] ?? 0;
        $sh->fromArray([$en, $tasaEur > 0 ? $m / $tasaEur : $m, $inv > 0 ? $m / $inv : 0, implode("\n", $porRubro[$k]['res'] ?? [])], null, 'A' . $f);
        $f++;
    }
    $sh->fromArray(['TOTAL', $tasaEur > 0 ? $inv / $tasaEur : $inv, $inv > 0 ? 1 : 0, 'MER: ' . ($kpi['mer'][0] !== null ? number_format($kpi['mer'][0], 2) : '—')
                    . ' · CPA: ' . ($kpi['cpa'][0] !== null ? money($kpi['cpa'][0]) : '—')], null, 'A' . $f);
    $cab($sh, "A$f:D$f", $gris);
    $sh->getStyle("B2:B$f")->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle("C2:C$f")->getNumberFormat()->setFormatCode($pctFmt);
    $sh->getStyle("D2:D$f")->getAlignment()->setWrapText(true);
    $sh->getColumnDimension('A')->setWidth(48); $sh->getColumnDimension('B')->setWidth(22);
    $sh->getColumnDimension('C')->setWidth(14); $sh->getColumnDimension('D')->setWidth(60);

    // --- KPIs para compartir ---
    $sh = $ss->createSheet()->setTitle('KPIs');
    $sh->fromArray(['Group', 'KPI', 'This year', 'Last year', 'Growth vs. LY', 'Comment'], null, 'A1');
    $cab($sh, 'A1:F1', $azul);
    $filasK = [
        ['GENERAL KPIS', 'Net Sales', $kpi['ns'][0], $kpi['ns'][1]],
        ['GENERAL KPIS', 'ATV', $kpi['atv'][0], $kpi['atv'][1]],
        ['GENERAL KPIS', 'Tickets', $kpi['tickets'][0], $kpi['tickets'][1]],
        ['GENERAL KPIS', 'UPT', $kpi['upt'][0], $kpi['upt'][1]],
        ['GENERAL KPIS', 'Conversion rate (%)', $kpi['conversion'][0], $kpi['conversion'][1]],
        ['GENERAL KPIS', 'New Customers recruited', $kpi['nuevos'][0], $kpi['nuevos'][1]],
        ['GENERAL KPIS', 'CPA', $kpi['cpa'][0], null],
        ['GENERAL KPIS', 'MER', $kpi['mer'][0], null],
        ['GENERAL KPIS', 'Incremental ROI (%)', $kpi['iroi'][0], null],
        ['E-COMMERCE ON-SITE', 'AOV', $kpi['aov_web'][0], $kpi['aov_web'][1]],
        ['E-COMMERCE ON-SITE', 'Conversion rate (%)', $kpi['conv_web'][0], $kpi['conv_web'][1]],
        ['E-COMMERCE ON-SITE', 'Returning customer rate (%)', $kpi['recurr_web'][0], null],
        ['E-COMMERCE ON-SITE', 'Net Sales', $ty['web']['ns'], $ly['web']['ns']],
    ];
    foreach (kpi_metricas_def() as $k => $d) {
        if (!isset($met[$k])) continue;
        $filasK[] = [mb_strtoupper($d['grupo']), $d['nombre_en'], $mval($k), $mval($k, 'valor_ly'), null, $met[$k]['nota'] ?? ''];
    }
    $f = 2;
    foreach ($filasK as $r) {
        $sh->fromArray([$r[0], $r[1], $r[2], $r[3], ($r[2] !== null && $r[3]) ? ($r[2] - $r[3]) / abs($r[3]) : null, $r[5] ?? ''], null, 'A' . $f);
        $f++;
    }
    $sh->getStyle("C2:D$f")->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle("E2:E$f")->getNumberFormat()->setFormatCode($pctFmt);
    $sh->getColumnDimension('A')->setWidth(26); $sh->getColumnDimension('B')->setWidth(40);
    foreach (['C', 'D', 'E'] as $col) $sh->getColumnDimension($col)->setWidth(16);
    $sh->getColumnDimension('F')->setWidth(50);

    // --- Promociones de la campaña ---
    $sh = $ss->createSheet()->setTitle('PROMOTIONS');
    $sh->fromArray(['Promotion', 'Discount type', 'Gross sales with promo', 'Discount given', 'Units/day before', 'Units/day during', 'Uplift', 'Incremental margin', 'Verdict'], null, 'A1');
    $cab($sh, 'A1:I1', $azul);
    $f = 2;
    foreach ($promosCamp as $r) {
        $sh->fromArray([$r['nombre'], cockpit_tipo_label($r['tipo']), $r['gs_promo'], $r['costo_desc'], $r['ud_dia_base'], $r['ud_dia'],
            $r['aumento'] === null ? null : $r['aumento'] / 100, $r['margen_incremental'], $r['veredicto'][1] . ($r['motivo'] ? ' (' . $r['motivo'] . ')' : '')], null, 'A' . $f);
        $f++;
    }
    $sh->getStyle("C2:F$f")->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle("H2:H$f")->getNumberFormat()->setFormatCode($numFmt);
    $sh->getStyle("G2:G$f")->getNumberFormat()->setFormatCode($pctFmt);
    $sh->getColumnDimension('A')->setWidth(36); $sh->getColumnDimension('B')->setWidth(30); $sh->getColumnDimension('I')->setWidth(50);
    foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $col) $sh->getColumnDimension($col)->setWidth(16);

    $ss->setActiveSheetIndex(1);
    $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', $c['nombre']) . '_KPIs.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

/* ============================================================
 *  Pantalla
 * ============================================================ */
$fmtCrec = function (?float $d, bool $invertir = false): string {
    if ($d === null) return '<span class="text-slate-300">—</span>';
    $bueno = $invertir ? $d <= 0 : $d >= 0;
    return '<span class="font-semibold ' . ($bueno ? 'text-emerald-600' : 'text-rose-600') . '">' . ($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . '%</span>';
};
$n0 = fn($v) => $v === null ? '—' : number_format((float) $v, 0);
$n2 = fn($v) => $v === null ? '—' : number_format((float) $v, 2);
// En las tarjetas, sin centavos: con ellos la cifra no cabe.
function ck_money_kpi(float $v): string { return e(setting('moneda', 'RD$')) . ' ' . number_format($v, 0); }
$puedeEditar = can('kpi_campanas.editar');
$enCurso = $c['fecha_inicio'] <= date('Y-m-d') && $c['fecha_fin'] >= date('Y-m-d');

$acciones = '<a href="?id=' . $id . '&export=excel" class="btn btn-ghost">' . icon('download', 'w-4 h-4') . ' Excel de la marca</a>'
    . ($puedeEditar ? '<button type="button" onclick="' . (jsEvent('kc:edit', array_intersect_key($c, array_flip(['id', 'nombre', 'descripcion', 'fecha_inicio', 'fecha_fin', 'ly_inicio', 'ly_fin', 'sucursal_id', 'tienda_id', 'meta_ventas', 'tasa_eur', 'notas'])))) . '" class="btn btn-ghost">' . icon('edit', 'w-4 h-4') . ' Editar</button>' : '')
    . '<a href="' . e(url('modules/marketing/kpi_campanas.php')) . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Campañas</a>';
$alcance = ($c['sucursal_id'] ? (string) qVal("SELECT nombre FROM sucursales WHERE id = ?", [$c['sucursal_id']]) : 'Todas las sucursales')
    . ($c['tienda_id'] ? ' · ' . (tiendas_opciones()[(int) $c['tienda_id']] ?? '') : '');
layout_start($c['nombre'], fechaCorta($c['fecha_inicio']) . ' al ' . fechaCorta($c['fecha_fin']) . ' contra ' . fechaCorta($c['ly_inicio']) . ' al ' . fechaCorta($c['ly_fin']) . ' · ' . $alcance, $acciones);

$metaPct = $R['meta'] > 0 ? $T['ns'] / $R['meta'] * 100 : null;
echo rep_kpis([
    ['label' => 'Venta neta', 'valor' => ck_money_kpi($T['ns']), 'icono' => 'dollar', 'color' => 'emerald', 'delta' => kpi_crec($T['ns'], $L['ns']),
     'nota' => 'Año anterior: <strong>' . ck_money_kpi($L['ns']) . '</strong>' . ($metaPct !== null ? ' · ' . number_format($metaPct, 0) . '% de la meta' : '')],
    ['label' => 'Facturas', 'valor' => number_format($T['tickets']), 'icono' => 'receipt', 'color' => 'blue', 'delta' => kpi_crec($T['tickets'], $L['tickets']),
     'nota' => 'Ticket medio: <strong>' . ck_money_kpi($kpi['atv'][0] ?? 0) . '</strong> (año ant. ' . ck_money_kpi($kpi['atv'][1] ?? 0) . ')'],
    ['label' => 'Clientes nuevos', 'valor' => number_format($T['nuevos']), 'icono' => 'users', 'color' => 'violet', 'delta' => kpi_crec($T['nuevos'], $L['nuevos']),
     'nota' => 'UPT: <strong>' . $n2($kpi['upt'][0]) . '</strong> unidades por factura'],
    ['label' => 'Inversión', 'valor' => ck_money_kpi($inv), 'icono' => 'megaphone', 'color' => 'amber',
     'nota' => $inv > 0 ? 'MER <strong>' . number_format($kpi['mer'][0], 2) . 'x</strong> · CPA <strong>' . ($kpi['cpa'][0] !== null ? ck_money_kpi($kpi['cpa'][0]) : '—') . '</strong>' : 'Registra la inversión para ver el retorno'],
]);
?>

<?php
// Campaña en curso: a qué ritmo va y dónde cerraría.
$proy = kpi_proyeccion($diasTY, $diasLY, date('Y-m-d'));
if ($proy):
    $metaP = $R['meta'] > 0 ? $proy['proyeccion'] / $R['meta'] * 100 : null;
    $avance = $proy['transcurridos'] / max(1, $proy['total']) * 100;
?>
  <section class="card p-4 mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <h3 class="font-bold text-slate-800">Proyección al cierre</h3>
      <span class="badge badge-amber">En curso · día <?= (int) $proy['transcurridos'] ?> de <?= (int) $proy['total'] ?></span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
      <div>
        <p class="text-sm text-slate-500">Vendido hasta ayer</p>
        <p class="text-2xl font-extrabold text-slate-800 tabular-nums"><?= ck_money_kpi($proy['ns_hoy']) ?></p>
      </div>
      <div>
        <p class="text-sm text-slate-500">Cerraría en</p>
        <p class="text-2xl font-extrabold text-slate-800 tabular-nums"><?= ck_money_kpi($proy['proyeccion']) ?></p>
        <p class="text-xs text-slate-400">Según la <?= e($proy['metodo']) ?><?= $L['ns'] > 0 ? ' · año anterior cerró en ' . ck_money_kpi($L['ns']) : '' ?></p>
      </div>
      <div>
        <?php if ($metaP !== null): ?>
          <p class="text-sm text-slate-500">Contra la meta de <?= ck_money_kpi($R['meta']) ?></p>
          <div class="h-3 rounded-full bg-slate-100 overflow-hidden mt-2" role="img" aria-label="Proyección: <?= number_format($metaP, 0) ?>% de la meta">
            <div class="h-full rounded-full <?= $metaP >= 100 ? 'bg-emerald-500' : ($metaP >= 85 ? 'bg-amber-400' : 'bg-rose-500') ?>" style="width:<?= min(100, $metaP) ?>%"></div>
          </div>
          <p class="text-sm font-semibold mt-1 <?= $metaP >= 100 ? 'text-emerald-600' : ($metaP >= 85 ? 'text-amber-600' : 'text-rose-600') ?>">
            <?= number_format($metaP, 0) ?>% de la meta<?= $metaP < 100 ? ' · faltarían ' . ck_money_kpi($R['meta'] - $proy['proyeccion']) : '' ?>
          </p>
        <?php else: ?>
          <p class="text-sm text-slate-400">Pon una meta a la campaña para ver si llega.</p>
        <?php endif; ?>
        <p class="text-xs text-slate-400 mt-1">Tiempo transcurrido: <?= number_format($avance, 0) ?>%</p>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($c['notas']): ?>
  <div class="card p-4 mb-5 text-sm text-slate-600 whitespace-pre-line"><?= e($c['notas']) ?></div>
<?php endif; ?>

<!-- KPIs globales por canal -->
<section id="canales" class="card overflow-hidden mb-5" x-data="{editar:false}">
  <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-100">
    <div>
      <h3 class="font-bold text-slate-800">KPIs globales por canal</h3>
      <p class="text-sm text-slate-400">Venta neta, facturas, ticket medio y clientes nuevos contra el año anterior y contra la meta</p>
    </div>
    <?php if ($puedeEditar): ?><button type="button" @click="editar=!editar" class="btn btn-ghost btn-sm no-print"><?= icon('target', 'w-3.5 h-3.5') ?> Metas por canal</button><?php endif; ?>
  </div>
  <?php if ($puedeEditar): ?>
    <form method="post" x-show="editar" x-cloak class="p-4 bg-slate-50 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
      <?= csrf_field() ?><input type="hidden" name="accion" value="metas">
      <?php foreach ($canales as $k => $lbl): ?>
        <div><label class="label" for="meta_<?= e($k) ?>"><?= e($lbl) ?></label>
          <input id="meta_<?= e($k) ?>" type="number" step="0.01" min="0" name="meta[<?= e($k) ?>]" value="<?= e((string) ($R['metas'][$k] ?? '')) ?>" class="input"></div>
      <?php endforeach; ?>
      <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar metas</button>
    </form>
  <?php endif; ?>
  <?php
  $catC = []; $cTy = []; $cLy = []; $cMeta = [];
  foreach ($canales as $k => $lbl) {
      $catC[] = $lbl;
      $cTy[] = round($ty[$k]['ns']); $cLy[] = round($ly[$k]['ns']);
      $cMeta[] = ($R['metas'][$k] ?? 0) > 0 ? round($R['metas'][$k]) : null;
  }
  ?>
  <div class="px-4 pt-4">
    <?= grafico([
        'legend' => ['data' => ['Este año', 'Año anterior', 'Meta']],
        'xAxis' => ['type' => 'category', 'data' => $catC],
        'yAxis' => ['type' => 'value'],
        'series' => [
            ['name' => 'Este año', 'type' => 'bar', 'data' => $cTy, 'barMaxWidth' => 34, 'itemStyle' => ['color' => GRAF_TY, 'borderRadius' => [4, 4, 0, 0]]],
            ['name' => 'Año anterior', 'type' => 'bar', 'data' => $cLy, 'barMaxWidth' => 34, 'itemStyle' => ['color' => GRAF_LY, 'borderRadius' => [4, 4, 0, 0]]],
            // La meta es una marca, no una barra: un trazo horizontal sobre cada canal.
            ['name' => 'Meta', 'type' => 'scatter', 'data' => $cMeta, 'symbol' => 'rect', 'symbolSize' => [56, 3], 'itemStyle' => ['color' => '#0f172a'], 'z' => 5],
        ],
    ], ['formato' => 'money0', 'titulo' => 'Venta por canal contra meta'], '260px') ?>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table text-[13px] whitespace-nowrap">
      <thead><tr>
        <th>Canal</th><th class="text-right">Venta neta</th><th class="text-right">Año ant.</th><th class="text-right">Crec.</th>
        <th class="text-right">vs. meta</th><th class="text-right">Meta</th>
        <th class="text-right border-l border-slate-100">Facturas</th><th class="text-right">Año ant.</th><th class="text-right">Crec.</th>
        <th class="text-right border-l border-slate-100">Ticket medio</th><th class="text-right">Año ant.</th><th class="text-right">Crec.</th>
        <th class="text-right border-l border-slate-100">Clientes nuevos</th><th class="text-right">Año ant.</th><th class="text-right">Crec.</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_merge(array_keys($canales), ['total']) as $k):
        $a = $ty[$k]; $b = $ly[$k];
        $meta = $k === 'total' ? $R['meta'] : ($R['metas'][$k] ?? 0); ?>
        <tr class="<?= $k === 'total' ? 'font-bold bg-slate-50 text-slate-800' : '' ?>">
          <td><?= e($k === 'total' ? 'TOTAL' : $canales[$k]) ?></td>
          <td class="text-right tabular-nums font-semibold"><?= $n0($a['ns']) ?></td>
          <td class="text-right tabular-nums text-slate-500"><?= $n0($b['ns']) ?></td>
          <td class="text-right"><?= $fmtCrec(kpi_crec($a['ns'], $b['ns'])) ?></td>
          <td class="text-right"><?= $meta > 0 ? $fmtCrec(($a['ns'] - $meta) / $meta * 100) : '<span class="text-slate-300">—</span>' ?></td>
          <td class="text-right tabular-nums text-slate-500"><?= $meta > 0 ? $n0($meta) : '—' ?></td>
          <td class="text-right tabular-nums border-l border-slate-100"><?= $n0($a['tickets']) ?></td>
          <td class="text-right tabular-nums text-slate-500"><?= $n0($b['tickets']) ?></td>
          <td class="text-right"><?= $fmtCrec(kpi_crec($a['tickets'], $b['tickets'])) ?></td>
          <td class="text-right tabular-nums border-l border-slate-100"><?= $n0($atv($a)) ?></td>
          <td class="text-right tabular-nums text-slate-500"><?= $n0($atv($b)) ?></td>
          <td class="text-right"><?= $fmtCrec($atv($a) !== null && $atv($b) ? kpi_crec($atv($a), $atv($b)) : null) ?></td>
          <td class="text-right tabular-nums border-l border-slate-100"><?= $n0($a['nuevos']) ?></td>
          <td class="text-right tabular-nums text-slate-500"><?= $n0($b['nuevos']) ?></td>
          <td class="text-right"><?= $fmtCrec(kpi_crec($a['nuevos'], $b['nuevos'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-xs text-slate-400 px-4 py-3 border-t border-slate-100">
    Web = tienda online · Social selling = Instagram, WhatsApp, Facebook y TikTok · Mayoreo = facturas con crédito fiscal o gubernamentales · Retail = el resto.
    Cliente nuevo = su primera compra de la historia cae en la campaña.
  </p>
</section>

<!-- Día por día -->
<?php
$labels = []; $sa = []; $sb = []; $ta = []; $tb = []; $i = 0;
foreach ($diasTY as $d => $a) {
    $labels[] = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'][(int) date('N', strtotime($d))] . ' ' . date('d/m', strtotime($d));
    $sa[] = round($a['ns']); $sb[] = round($diasLY[$i]['ns'] ?? 0);
    $ta[] = (int) $a['tickets']; $tb[] = (int) ($diasLY[$i]['tickets'] ?? 0);
    $i++;
}
?>
<section class="card overflow-hidden mb-5">
  <div class="p-4 border-b border-slate-100">
    <h3 class="font-bold text-slate-800">Día por día</h3>
    <p class="text-sm text-slate-400">Cada día de la campaña contra el mismo día del periodo comparable (la hoja «Black Friday»)<?= count($diasTY) >= cockpit_param_int('dias_max') ? ' · se muestran los primeros ' . cockpit_param_int('dias_max') . ' días' : '' ?></p>
  </div>
  <div class="p-4 grid grid-cols-1 xl:grid-cols-3 gap-5">
    <div class="xl:col-span-2">
      <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Venta neta por día</p>
      <?= grafico_lineas_ty_ly($labels, $sa, $sb, ['formato' => 'money0', 'titulo' => 'Venta neta por día'], '300px', count($labels) > 14) ?>
    </div>
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Facturas por día</p>
      <?= grafico_ty_ly($labels, $ta, $tb, ['formato' => 'num', 'titulo' => 'Facturas por día', 'tipos' => ['line', 'bar']], '300px',
          count($labels) > 14 ? ['dataZoom' => [['type' => 'inside'], ['type' => 'slider', 'height' => 22, 'bottom' => 4]]] : []) ?>
    </div>
  </div>
  <details class="border-t border-slate-100" <?= count($diasTY) <= 10 ? 'open' : '' ?>>
    <summary class="px-4 py-3 cursor-pointer text-sm font-semibold text-slate-600 hover:bg-slate-50">Ver la tabla diaria</summary>
    <div class="overflow-x-auto">
      <table class="data-table text-[13px] whitespace-nowrap">
        <thead><tr><th>Fecha</th><th class="text-right">Venta neta</th><th>Fecha comparable</th><th class="text-right">Año ant.</th><th class="text-right">Crec.</th>
          <th class="text-right">Facturas</th><th class="text-right">Año ant.</th><th class="text-right">Ticket medio</th><th class="text-right">Año ant.</th><th class="text-right">Nuevos</th><th class="text-right">Año ant.</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($diasTY as $d => $a): $b = $diasLY[$i] ?? ['ns' => 0, 'tickets' => 0, 'nuevos' => 0];
          $dLY = date('Y-m-d', strtotime($c['ly_inicio'] . " +$i day")); $i++; ?>
          <tr>
            <td class="font-semibold text-slate-700"><?= e(fechaCorta($d)) ?> <span class="text-xs text-slate-400"><?= e(['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'][(int) date('N', strtotime($d))]) ?></span></td>
            <td class="text-right tabular-nums font-semibold"><?= $n0($a['ns']) ?></td>
            <td class="text-slate-400 text-xs"><?= $dLY <= $c['ly_fin'] ? e(fechaCorta($dLY)) . ' ' . e(['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'][(int) date('N', strtotime($dLY))]) : '—' ?></td>
            <td class="text-right tabular-nums text-slate-500"><?= $n0($b['ns']) ?></td>
            <td class="text-right"><?= $fmtCrec(kpi_crec($a['ns'], $b['ns'])) ?></td>
            <td class="text-right tabular-nums"><?= $n0($a['tickets']) ?></td>
            <td class="text-right tabular-nums text-slate-500"><?= $n0($b['tickets']) ?></td>
            <td class="text-right tabular-nums"><?= $n0(kpi_div($a['ns'], $a['tickets'])) ?></td>
            <td class="text-right tabular-nums text-slate-500"><?= $n0(kpi_div($b['ns'], $b['tickets'])) ?></td>
            <td class="text-right tabular-nums"><?= $n0($a['nuevos']) ?></td>
            <td class="text-right tabular-nums text-slate-500"><?= $n0($b['nuevos']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
</section>

<!-- SKUs -->
<section id="skus" class="card overflow-hidden mb-5" x-data="{editar:false}">
  <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-100">
    <div>
      <h3 class="font-bold text-slate-800">Reporte de SKUs</h3>
      <p class="text-sm text-slate-400"><?= $esTop ? 'La campaña no tiene SKUs foco: se muestran los 20 más vendidos.' : count($foco) . ' SKU(s) foco de la campaña' ?> · participación sobre la venta neta total de cada periodo</p>
    </div>
    <?php if ($puedeEditar): ?><button type="button" @click="editar=!editar" class="btn btn-ghost btn-sm no-print"><?= icon('edit', 'w-3.5 h-3.5') ?> <?= $esTop ? 'Definir SKUs foco' : 'Cambiar SKUs foco' ?></button><?php endif; ?>
  </div>
  <?php if ($puedeEditar): ?>
    <form method="post" x-show="editar" x-cloak class="p-4 bg-slate-50 border-b border-slate-100 space-y-3">
      <?= csrf_field() ?><input type="hidden" name="accion" value="skus">
      <label class="label" for="kc_codigos">Códigos (SKU o código de barras), uno por línea o separados por coma</label>
      <textarea id="kc_codigos" name="codigos" rows="5" class="input font-mono text-sm" placeholder="27OR030I24&#10;27DC050I23&#10;29HD250A15"><?= $esTop ? '' : e(implode("\n", array_column($foco, 'codigo'))) ?></textarea>
      <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar SKUs</button>
    </form>
  <?php endif; ?>
  <?php if (!$foco): ?>
    <div class="p-6"><?= empty_state('Sin ventas de productos', 'No hubo ventas en las fechas de la campaña.', 'package') ?></div>
  <?php else: ?>
  <?php
  $skuCat = []; $skuA = []; $skuB = [];
  foreach (array_slice($foco, 0, 25) as $p) {
      $skuCat[] = $p['codigo'] . ' · ' . $p['nombre'];
      $a = $skuTY[$p['id']]['ns'] ?? 0; $b = $skuLY[$p['id']]['ns'] ?? 0; $d = kpi_crec($a, $b);
      $tip = '<b>' . e($p['nombre']) . '</b><br>' . e($p['codigo']) . '<br>Este año: <b>' . ck_money_kpi($a) . '</b> · ' . qty($skuTY[$p['id']]['qty'] ?? 0) . ' u.'
          . '<br>Año anterior: ' . ck_money_kpi($b) . ' · ' . qty($skuLY[$p['id']]['qty'] ?? 0) . ' u.<br>Crecimiento: <b>' . ($d === null ? 'nuevo' : (($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . '%')) . '</b>';
      $skuA[] = ['value' => round($a), 'tip' => $tip]; $skuB[] = ['value' => round($b), 'tip' => $tip];
  }
  ?>
  <?php if ($skuCat): ?>
    <div class="px-4 pt-4">
      <?= grafico_ty_ly($skuCat, $skuA, $skuB, ['formato' => 'money0', 'titulo' => 'SKUs foco', 'horizontal' => true],
          max(220, 34 * count($skuCat) + 60) . 'px', ['tooltip' => ['trigger' => 'item']]) ?>
    </div>
  <?php endif; ?>
  <div class="overflow-x-auto">
    <table class="data-table text-[13px] whitespace-nowrap">
      <thead><tr>
        <th>SKU</th><th>Descripción</th><th class="text-right">Cant.</th><th class="text-right">Venta neta</th><th class="text-right">% VN</th>
        <th class="text-right border-l border-slate-100">Cant. año ant.</th><th class="text-right">Venta neta año ant.</th><th class="text-right">% VN</th>
        <th class="text-right border-l border-slate-100">VN vs. año ant.</th><th class="text-right">Crec.</th><th class="text-right">Dif. participación</th>
      </tr></thead>
      <tbody>
      <?php $sumA = ['qty' => 0, 'ns' => 0]; $sumB = ['qty' => 0, 'ns' => 0];
      foreach ($foco as $p):
        $a = $skuTY[$p['id']] ?? ['qty' => 0, 'ns' => 0]; $b = $skuLY[$p['id']] ?? ['qty' => 0, 'ns' => 0];
        foreach (['qty', 'ns'] as $m) { $sumA[$m] += $a[$m]; $sumB[$m] += $b[$m]; }
        $pa = $T['ns'] > 0 ? $a['ns'] / $T['ns'] * 100 : 0; $pb = $L['ns'] > 0 ? $b['ns'] / $L['ns'] * 100 : 0; ?>
        <tr>
          <td class="font-mono text-xs text-slate-500"><?= e($p['codigo']) ?></td>
          <td class="max-w-[280px] truncate text-slate-700"><?= e($p['nombre']) ?></td>
          <td class="text-right tabular-nums"><?= qty($a['qty']) ?></td>
          <td class="text-right tabular-nums font-semibold"><?= $n0($a['ns']) ?></td>
          <td class="text-right tabular-nums text-slate-500"><?= number_format($pa, 1) ?>%</td>
          <td class="text-right tabular-nums border-l border-slate-100 text-slate-500"><?= qty($b['qty']) ?></td>
          <td class="text-right tabular-nums text-slate-500"><?= $n0($b['ns']) ?></td>
          <td class="text-right tabular-nums text-slate-400"><?= number_format($pb, 1) ?>%</td>
          <td class="text-right tabular-nums border-l border-slate-100 <?= $a['ns'] - $b['ns'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= ($a['ns'] - $b['ns'] >= 0 ? '+' : '−') . $n0(abs($a['ns'] - $b['ns'])) ?></td>
          <td class="text-right"><?= $fmtCrec(kpi_crec($a['ns'], $b['ns'])) ?></td>
          <td class="text-right tabular-nums"><?= e(cockpit_pts($pa - $pb)) ?></td>
        </tr>
      <?php endforeach;
      $pa = $T['ns'] > 0 ? $sumA['ns'] / $T['ns'] * 100 : 0; $pb = $L['ns'] > 0 ? $sumB['ns'] / $L['ns'] * 100 : 0; ?>
      </tbody>
      <tfoot><tr class="bg-slate-50 font-bold text-slate-800">
        <td class="px-4 py-3" colspan="2">Total de estos SKUs</td>
        <td class="text-right px-4 tabular-nums"><?= qty($sumA['qty']) ?></td><td class="text-right px-4 tabular-nums"><?= $n0($sumA['ns']) ?></td><td class="text-right px-4 tabular-nums"><?= number_format($pa, 1) ?>%</td>
        <td class="text-right px-4 tabular-nums"><?= qty($sumB['qty']) ?></td><td class="text-right px-4 tabular-nums"><?= $n0($sumB['ns']) ?></td><td class="text-right px-4 tabular-nums"><?= number_format($pb, 1) ?>%</td>
        <td class="text-right px-4 tabular-nums"><?= ($sumA['ns'] - $sumB['ns'] >= 0 ? '+' : '−') . $n0(abs($sumA['ns'] - $sumB['ns'])) ?></td>
        <td class="text-right px-4"><?= $fmtCrec(kpi_crec($sumA['ns'], $sumB['ns'])) ?></td><td class="text-right px-4 tabular-nums"><?= e(cockpit_pts($pa - $pb)) ?></td>
      </tr></tfoot>
    </table>
  </div>
  <?php endif; ?>
</section>

<section id="promociones" class="card overflow-hidden mb-5">
  <div class="p-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
    <div>
      <h3 class="font-bold text-slate-800">Promociones de la campaña</h3>
      <p class="text-sm text-slate-400">Las que se usaron entre el <?= e(fechaCorta($c['fecha_inicio'])) ?> y el <?= e(fechaCorta($c['fecha_fin'])) ?>, con su resultado contra los días previos a cada una.</p>
    </div>
    <?php if (can('cockpit.ver')): ?>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('modules/marketing/cockpit.php') . '?' . http_build_query(['tab' => 'efectividad', 'ty_desde' => $c['fecha_inicio'], 'ty_hasta' => min($c['fecha_fin'], date('Y-m-d')),
          'ly_desde' => $c['ly_inicio'], 'ly_hasta' => $c['ly_fin'], 'ly_manual' => 1, 'sucursal_id' => $c['sucursal_id'] ?: null, 'tienda_id' => $c['tienda_id'] ?: null])) ?>">
        <?= icon('percent', 'w-3.5 h-3.5') ?> Ver en el cockpit</a>
    <?php endif; ?>
  </div>
  <?php if (!$promosCamp): ?>
    <div class="p-6"><?= empty_state('Ninguna promoción usada todavía', $c['fecha_inicio'] > date('Y-m-d') ? 'La campaña aún no empieza.' : 'Ninguna venta de la campaña registró una promoción.', 'percent') ?></div>
  <?php else: $totDesc = array_sum(array_column($promosCamp, 'costo_desc')); ?>
  <div class="overflow-x-auto">
    <table class="data-table text-[13px] whitespace-nowrap">
      <thead><tr><th>Promoción</th><th class="text-right">Venta bruta con la promo</th><th class="text-right">Descuento regalado</th><th class="text-right">Peso en el descuento</th>
        <th class="text-right">Aumento de unidades</th><th class="text-right">Margen incremental</th><th>Veredicto</th></tr></thead>
      <tbody>
      <?php foreach ($promosCamp as $r): ?>
        <tr>
          <td class="max-w-[280px]"><p class="font-semibold text-slate-700 truncate"><?= e($r['nombre']) ?></p>
            <p class="text-xs text-slate-400"><span class="inline-block w-2 h-2 rounded-full mr-1 align-middle" style="background:<?= e(cockpit_tipo_color($r['tipo'])) ?>"></span><?= e(cockpit_tipo_label($r['tipo'])) ?></p></td>
          <td class="text-right tabular-nums"><?= cockpit_n($r['gs_promo']) ?></td>
          <td class="text-right tabular-nums"><?= cockpit_n($r['costo_desc']) ?></td>
          <td class="text-right tabular-nums"><?= $totDesc > 0 ? cockpit_pct($r['costo_desc'] / $totDesc * 100, 0) : '—' ?></td>
          <td class="text-right"><?= $r['aumento'] === null ? '<span class="text-slate-300">—</span>' : '<span class="font-semibold ' . ($r['aumento'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . '">' . ($r['aumento'] >= 0 ? '+' : '−') . number_format(abs($r['aumento']), 0) . '%</span>' ?></td>
          <td class="text-right"><?= $r['margen_incremental'] === null ? '<span class="text-slate-300">—</span>' : cockpit_celda_efecto($r['margen_incremental']) ?></td>
          <td><?= badge($r['veredicto'][1], $r['veredicto'][2]) ?><?php if ($r['motivo']): ?><p class="text-[11px] text-slate-400 mt-0.5"><?= e($r['motivo']) ?></p><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="grid grid-cols-1 xl:grid-cols-5 gap-5 mb-5">
  <!-- Inversión -->
  <section id="inversion" class="card overflow-hidden xl:col-span-3">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Inversión de marketing</h3>
      <p class="text-sm text-slate-400">Por rubro, con su peso y el resultado logrado<?= $tasaEur > 0 ? ' · en € a ' . number_format($tasaEur, 2) . ' por euro' : '' ?></p>
    </div>
    <?php if (!$inversiones): ?>
      <div class="p-6"><?= empty_state('Sin inversión registrada', 'Anota lo invertido por rubro (medios pagados, PR, activaciones…) para calcular MER, CPA y retorno.', 'megaphone') ?></div>
    <?php else: ?>
      <?php
      $porRubro = [];
      foreach ($inversiones as $iv) $porRubro[$iv['rubro']] = ($porRubro[$iv['rubro']] ?? 0) + (float) $iv['monto'];
      arsort($porRubro);
      ?>
      <div class="px-4 pt-3">
        <?= grafico([
            'xAxis' => ['type' => 'value'],
            'yAxis' => ['type' => 'category', 'inverse' => true, 'data' => array_map(fn($k) => $rubros[$k][0] ?? $k, array_keys($porRubro)),
                        'axisLabel' => ['width' => 170, 'overflow' => 'truncate', 'interval' => 0]],
            'series' => [['name' => 'Inversión', 'type' => 'bar', 'barMaxWidth' => 20, 'itemStyle' => ['color' => GRAF_TY, 'borderRadius' => [0, 4, 4, 0]],
                'data' => array_map(fn($v) => round($v), array_values($porRubro)),
                'label' => ['show' => true, 'position' => 'right', 'fontSize' => 11, 'color' => '#52514e', 'formato' => 'money0']]],
        ], ['formato' => 'money0', 'titulo' => 'Inversión por rubro', 'herramientas' => false], max(140, 34 * count($porRubro) + 30) . 'px') ?>
      </div>
      <div class="overflow-x-auto">
        <table class="data-table text-[13px]">
          <thead><tr><th>Rubro</th><th class="text-right"><?= e(setting('moneda', 'RD$')) ?></th><?= $tasaEur > 0 ? '<th class="text-right">€</th>' : '' ?><th class="text-right">Peso</th><th>Resultado</th><?= $puedeEditar ? '<th></th>' : '' ?></tr></thead>
          <tbody>
          <?php foreach ($inversiones as $iv): ?>
            <tr>
              <td><p class="font-semibold text-slate-700"><?= e($rubros[$iv['rubro']][0] ?? $iv['rubro']) ?></p><?php if ($iv['detalle']): ?><p class="text-xs text-slate-400"><?= e($iv['detalle']) ?></p><?php endif; ?></td>
              <td class="text-right tabular-nums whitespace-nowrap"><?= $n0($iv['monto']) ?></td>
              <?php if ($tasaEur > 0): ?><td class="text-right tabular-nums whitespace-nowrap text-slate-500"><?= $n0($iv['monto'] / $tasaEur) ?></td><?php endif; ?>
              <td class="text-right tabular-nums text-slate-500"><?= number_format($iv['monto'] / $inv * 100, 1) ?>%</td>
              <td class="text-slate-600 text-sm"><?= e($iv['resultado'] ?? '') ?></td>
              <?php if ($puedeEditar): ?>
                <td class="text-right">
                  <form method="post" onsubmit="return confirm('¿Eliminar esta inversión?')">
                    <?= csrf_field() ?><input type="hidden" name="accion" value="inversion_eliminar"><input type="hidden" name="inv_id" value="<?= (int) $iv['id'] ?>">
                    <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="bg-slate-50 font-bold text-slate-800">
            <td class="px-4 py-3">Total</td><td class="text-right px-4 tabular-nums"><?= $n0($inv) ?></td>
            <?= $tasaEur > 0 ? '<td class="text-right px-4 tabular-nums">' . $n0($inv / $tasaEur) . '</td>' : '' ?>
            <td class="text-right px-4">100%</td><td colspan="<?= $puedeEditar ? 2 : 1 ?>" class="px-4 text-sm font-semibold text-slate-500">MER <?= $kpi['mer'][0] !== null ? number_format($kpi['mer'][0], 2) . 'x' : '—' ?> · CPA <?= $kpi['cpa'][0] !== null ? money($kpi['cpa'][0]) : '—' ?></td>
          </tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
    <?php if ($puedeEditar): ?>
      <form method="post" class="p-4 border-t border-slate-100 bg-slate-50 grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
        <?= csrf_field() ?><input type="hidden" name="accion" value="inversion_agregar">
        <div><label class="label" for="inv_rubro">Rubro</label>
          <select id="inv_rubro" name="rubro" class="select" required><option value="">— Elige —</option>
            <?php foreach (kpi_rubros_inversion(true) as $k => [$es]): ?><option value="<?= e($k) ?>"><?= e($es) ?></option><?php endforeach; ?></select></div>
        <div><label class="label" for="inv_monto">Monto (<?= e(setting('moneda', 'RD$')) ?>)</label><input id="inv_monto" type="number" step="0.01" min="0.01" name="monto" required class="input"></div>
        <div><label class="label" for="inv_det">Detalle</label><input id="inv_det" name="detalle" maxlength="160" class="input" placeholder="Ej. Meta Ads 15-30 nov"></div>
        <div><label class="label" for="inv_res">Resultado (KPI o comentario)</label><input id="inv_res" name="resultado" maxlength="255" class="input" placeholder="Ej. 1.2M impresiones, 3.4% CTR"></div>
        <div class="sm:col-span-2 flex justify-end"><button class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Añadir inversión</button></div>
      </form>
    <?php endif; ?>
  </section>

  <!-- KPIs calculados -->
  <section class="card overflow-hidden xl:col-span-2">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">KPIs para compartir</h3>
      <p class="text-sm text-slate-400">Calculados del POS con lo capturado abajo</p>
    </div>
    <?php
    $linea = function (string $lbl, $a, $b, string $fmt, string $ayuda = '') use ($fmtCrec) {
        $f = fn($v) => $v === null ? '<span class="text-slate-300">—</span>'
            : ($fmt === 'money' ? money($v) : ($fmt === 'pct' ? number_format($v, 1) . '%' : ($fmt === 'x' ? number_format($v, 2) . 'x' : number_format($v, $fmt === 'dec' ? 2 : 0))));
        return '<div class="flex items-start justify-between gap-3 px-4 py-2.5 border-b border-slate-50">'
            . '<div class="min-w-0"><p class="text-sm text-slate-600">' . e($lbl) . '</p>' . ($ayuda ? '<p class="text-xs text-slate-400">' . e($ayuda) . '</p>' : '') . '</div>'
            . '<div class="text-right shrink-0"><p class="text-sm font-bold text-slate-800 tabular-nums">' . $f($a) . '</p>'
            . ($b !== null ? '<p class="text-xs text-slate-400 tabular-nums">' . $f($b) . ' · ' . ($a !== null ? $fmtCrec(kpi_crec((float) $a, (float) $b)) : '') . '</p>' : '')
            . '</div></div>';
    };
    ?>
    <p class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-wider text-amber-600">Generales</p>
    <?= $linea('Venta neta', $kpi['ns'][0], $kpi['ns'][1], 'money') ?>
    <?= $linea('Ticket medio (ATV)', $kpi['atv'][0], $kpi['atv'][1], 'money') ?>
    <?= $linea('Facturas (tickets)', $kpi['tickets'][0], $kpi['tickets'][1], 'num') ?>
    <?= $linea('Unidades por factura (UPT)', $kpi['upt'][0], $kpi['upt'][1], 'dec') ?>
    <?= $linea('Tasa de conversión', $kpi['conversion'][0], $kpi['conversion'][1], 'pct', $trafico ? 'Facturas ÷ visitantes' : 'Captura el tráfico de la tienda') ?>
    <?= $linea('Clientes nuevos', $kpi['nuevos'][0], $kpi['nuevos'][1], 'num') ?>
    <?= $linea('Clientes que repiten', $kpi['recurr'][0], null, 'pct', 'De los clientes identificados, los que ya habían comprado') ?>
    <?= $linea('CPA (costo por cliente nuevo)', $kpi['cpa'][0], null, 'money', 'Inversión ÷ clientes nuevos') ?>
    <?= $linea('MER', $kpi['mer'][0], null, 'x', 'Venta neta ÷ inversión') ?>
    <?= $linea('ROI incremental', $kpi['iroi'][0], null, 'pct', '(Venta de más contra el año anterior − inversión) ÷ inversión') ?>
    <p class="px-4 pt-4 pb-1 text-xs font-bold uppercase tracking-wider text-amber-600">E-commerce</p>
    <?= $linea('Venta neta web', $ty['web']['ns'], $ly['web']['ns'], 'money') ?>
    <?= $linea('Pedido medio (AOV)', $kpi['aov_web'][0], $kpi['aov_web'][1], 'money') ?>
    <?= $linea('Conversión web', $kpi['conv_web'][0], $kpi['conv_web'][1], 'pct', $sesiones ? 'Pedidos ÷ sesiones' : 'Captura las sesiones de la tienda online') ?>
    <?= $linea('Clientes web que repiten', $kpi['recurr_web'][0], null, 'pct') ?>
  </section>
</div>

<!-- KPIs capturados a mano -->
<section id="kpis" class="card overflow-hidden mb-5">
  <div class="p-4 border-b border-slate-100">
    <h3 class="font-bold text-slate-800">KPIs de la activación</h3>
    <p class="text-sm text-slate-400">Lo que no pasa por la caja: tráfico, PR, medios, entrenamiento, incentivos y NPS. Deja vacío lo que no aplique.</p>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="accion" value="metricas">
    <div class="overflow-x-auto">
      <table class="data-table text-[13px]">
        <thead><tr><th>Grupo</th><th>KPI</th><th class="text-right">Este año</th><th class="text-right">Año anterior</th><th class="text-right">Crec.</th><th>Nota</th></tr></thead>
        <tbody>
        <?php foreach (kpi_metricas_manuales() as $k => [$grupo, $lbl, $unidad]):
          $v = $mval($k); $vl = $mval($k, 'valor_ly'); ?>
          <tr>
            <td class="text-slate-400 whitespace-nowrap"><?= e($grupo) ?></td>
            <td class="text-slate-700 font-medium"><?= e($lbl) ?> <span class="text-slate-300 text-xs"><?= $unidad === 'pct' ? '(%)' : ($unidad === 'money' ? '(' . e(setting('moneda', 'RD$')) . ')' : '') ?></span></td>
            <?php if ($puedeEditar): ?>
              <td class="text-right"><input type="text" inputmode="decimal" name="valor[<?= e($k) ?>]" value="<?= $v === null ? '' : e(rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')) ?>" class="input py-1.5 text-right w-32 ml-auto" aria-label="<?= e($lbl) ?> este año"></td>
              <td class="text-right"><input type="text" inputmode="decimal" name="valor_ly[<?= e($k) ?>]" value="<?= $vl === null ? '' : e(rtrim(rtrim(number_format($vl, 2, '.', ''), '0'), '.')) ?>" class="input py-1.5 text-right w-32 ml-auto" aria-label="<?= e($lbl) ?> año anterior"></td>
              <td class="text-right"><?= $v !== null && $vl ? $fmtCrec(kpi_crec($v, $vl), !empty(kpi_metricas_def()[$k]['menor_es_mejor'])) : '<span class="text-slate-300">—</span>' ?></td>
              <td><input type="text" name="nota[<?= e($k) ?>]" value="<?= e($met[$k]['nota'] ?? '') ?>" maxlength="255" class="input py-1.5 min-w-[200px]" aria-label="Nota de <?= e($lbl) ?>"></td>
            <?php else: ?>
              <td class="text-right tabular-nums"><?= $v === null ? '—' : e(number_format($v, 2)) ?></td>
              <td class="text-right tabular-nums text-slate-500"><?= $vl === null ? '—' : e(number_format($vl, 2)) ?></td>
              <td class="text-right"><?= $v !== null && $vl ? $fmtCrec(kpi_crec($v, $vl), !empty(kpi_metricas_def()[$k]['menor_es_mejor'])) : '—' ?></td>
              <td class="text-slate-500"><?= e($met[$k]['nota'] ?? '') ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($puedeEditar): ?>
      <div class="flex justify-end p-4 border-t border-slate-100"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar KPIs</button></div>
    <?php endif; ?>
  </form>
</section>

<?php require __DIR__ . '/_kpi_campana_form.php'; ?>
<?php layout_end(); ?>
