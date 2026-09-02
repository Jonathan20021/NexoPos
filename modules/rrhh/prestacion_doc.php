<?php
/**
 * Recibo de descargo de prestaciones laborales — el papel que se firma.
 *
 * No es un adorno ni un resumen bonito: es el documento que acredita qué se
 * pagó, por qué concepto y bajo qué escala. Por eso lleva el desglose completo
 * con los días de cada renglón, el monto en letras, y la constancia de que el
 * trabajador recibió conforme.
 *
 * Lo que NO lleva, a propósito: ninguna frase de renuncia a derechos. El recibo
 * hace constar un pago; lo que el trabajador renuncie o deje de renunciar es
 * materia de un acuerdo que redacta un abogado, no una plantilla del sistema.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('prestaciones.ver');

$id = (int) get('id');
$l = qOne(
    "SELECT l.*, e.nombre, e.apellido, e.cedula, e.codigo,
            pu.nombre AS puesto, dep.nombre AS departamento, su.nombre AS sucursal
       FROM prestaciones l
       JOIN empleados e ON e.id = l.empleado_id
       LEFT JOIN puestos pu        ON pu.id  = e.puesto_id
       LEFT JOIN departamentos dep ON dep.id = e.departamento_id
       LEFT JOIN sucursales su     ON su.id  = e.sucursal_id
      WHERE l.id = ?", [$id]);
if (!$l) { http_response_code(404); exit('Liquidación no encontrada.'); }

$emp    = $GLOBALS['empresa'] ?? [];
$causas = plab_causas();
$causa  = $causas[$l['causa']]['label'] ?? $l['causa'];
$nombre = trim($l['nombre'] . ' ' . $l['apellido']);
$anios  = (int) $l['dias_servicio'] / 365;

$html = pdf_brand_header('Recibo de descargo — Prestaciones laborales',
    $l['numero'] . ' · ' . fechaCorta($l['fecha_salida']));

$html .= '<table class="datos"><tr>'
    . '<td><span class="lbl">Trabajador</span>' . e($nombre) . '</td>'
    . '<td><span class="lbl">Cédula</span>' . e($l['cedula'] ?: '—') . '</td>'
    . '</tr><tr>'
    . '<td><span class="lbl">Puesto</span>' . e($l['puesto'] ?: ($l['departamento'] ?: '—')) . '</td>'
    . '<td><span class="lbl">Lugar de trabajo</span>' . e($l['sucursal'] ?: '—') . '</td>'
    . '</tr><tr>'
    . '<td><span class="lbl">Fecha de ingreso</span>' . fechaCorta($l['fecha_ingreso']) . '</td>'
    . '<td><span class="lbl">Último día de trabajo</span>' . fechaCorta($l['fecha_salida']) . '</td>'
    . '</tr><tr>'
    . '<td><span class="lbl">Tiempo de servicio</span>' . number_format((int) $l['dias_servicio']) . ' días ('
        . number_format($anios, 2) . ' año' . ($anios == 1 ? '' : 's') . ')</td>'
    . '<td><span class="lbl">Causa de la terminación</span>' . e($causa) . '</td>'
    . '</tr></table>';

$html .= '<p class="cuerpo">El presente recibo hace constar la liquidación de las prestaciones y derechos '
    . 'adquiridos por <strong>' . e($nombre) . '</strong> con motivo de la terminación de su contrato de trabajo '
    . 'con <strong>' . e($emp['nombre'] ?? '') . '</strong>'
    . (!empty($emp['rnc']) ? ', RNC ' . e($emp['rnc']) : '')
    . ', calculadas conforme al Código de Trabajo de la República Dominicana sobre un salario mensual de '
    . '<strong>' . money($l['salario_mensual']) . '</strong>, equivalente a un salario diario de '
    . '<strong>' . money($l['salario_diario']) . '</strong> (salario mensual entre ' . PLAB_DIVISOR_DIARIO . ').</p>';

/* ---------- El desglose ---------- */
$reng = [
    ['Preaviso — art. 76',                      (float) $l['preaviso_dias'],   (float) $l['preaviso_monto']],
    ['Auxilio de cesantía — art. 80',           (float) $l['cesantia_dias'],   (float) $l['cesantia_monto']],
    ['Vacaciones no disfrutadas — art. 177',    (float) $l['vacaciones_dias'], (float) $l['vacaciones_monto']],
    ['Salario de Navidad proporcional — art. 219', null,                       (float) $l['regalia_monto']],
    ['Salario pendiente de pago',                null,                          (float) $l['salario_pendiente']],
];
if ((float) $l['otros_monto'] != 0.0) {
    $reng[] = [$l['otros_concepto'] ?: 'Otros conceptos acordados', null, (float) $l['otros_monto']];
}

// Aquí los importes van SIEMPRE con dos decimales, no con pdf_numero(): esa
// función decide por la forma del dato y un monto redondo saldría «15,000» en
// una columna de dinero de un papel que se firma.
$mnt = fn(float $v) => number_format($v, 2);

$filas = ''; $bruto = 0.0;
foreach ($reng as [$lbl, $d, $m]) {
    if ($m == 0.0 && ($d === null || $d == 0.0)) continue;
    $bruto += $m;
    $filas .= '<tr><td>' . e($lbl) . '</td>'
        . '<td class="c">' . ($d === null ? '—' : rtrim(rtrim(number_format($d, 2), '0'), '.')) . '</td>'
        . '<td class="r b">' . $mnt($m) . '</td></tr>';
}
$filas .= '<tr class="sub"><td colspan="2" class="b">Total devengado</td>'
        . '<td class="r b">' . $mnt(round($bruto, 2)) . '</td></tr>';
if ((float) $l['deducciones'] != 0.0) {
    $filas .= '<tr><td>' . e($l['deducciones_concepto'] ?: 'Deducciones') . '</td>'
        . '<td class="c">—</td><td class="r">−' . $mnt((float) $l['deducciones']) . '</td></tr>';
}

$html .= '<table class="items"><thead><tr>'
    . '<th>Concepto</th><th class="c">Días</th><th class="r">Monto RD$</th>'
    . '</tr></thead><tbody>' . $filas . '</tbody>'
    . '<tfoot><tr><td colspan="2" class="b">NETO A RECIBIR</td>'
    . '<td class="r b">' . $mnt((float) $l['total']) . '</td></tr></tfoot></table>';

$html .= '<p class="cuerpo">Suma que asciende a <strong>' . money($l['total']) . '</strong> '
    . '(<em>' . e(pdf_en_letras((float) $l['total'])) . '</em>).</p>';

if ($l['notas']) {
    $html .= '<p class="cuerpo"><span class="lbl">Notas del acuerdo</span>' . nl2br(e($l['notas'])) . '</p>';
}

$html .= '<p class="cuerpo">El preaviso y el auxilio de cesantía se calcularon aplicando las escalas de los '
    . 'artículos 76 y 80 del Código de Trabajo según el tiempo de servicio arriba indicado; las vacaciones, '
    . 'conforme al artículo 177, y el salario de Navidad conforme al artículo 219, en proporción al tiempo '
    . 'trabajado en el año en curso.</p>';

$html .= '<table class="firmas"><tr>'
    . '<td><div class="linea"></div><span class="lbl">' . e($nombre) . '</span>'
    . '<span class="peq">Cédula ' . e($l['cedula'] ?: '________________') . ' · Recibí conforme</span></td>'
    . '<td><div class="linea"></div><span class="lbl">Por ' . e($emp['nombre'] ?? 'la empresa') . '</span>'
    . '<span class="peq">Nombre, cargo y firma</span></td>'
    . '</tr></table>';

$html .= '<p class="peq" style="margin-top:14px">Firmado en '
    . e($emp['direccion'] ?? '') . ', a los ______ días del mes de ____________ del año ________.</p>';

$html .= pdf_pie('Documento generado por NexoPOS · ' . $l['numero']
    . ' · Conserve una copia en el expediente del trabajador.');

$html = '<style>'
    . '.cuerpo{font-size:10.5px;line-height:1.65;text-align:justify;margin:9px 0}'
    . '.datos{width:100%;margin:10px 0 4px;border-collapse:collapse}'
    . '.datos td{padding:4px 8px 4px 0;font-size:10.5px;width:50%;vertical-align:top}'
    . '.lbl{display:block;font-size:8px;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8}'
    . '.items .sub td{border-top:1px solid #CBD5E1;background:#F8FAFC}'
    . '.firmas{width:100%;margin-top:44px;border-collapse:collapse}'
    . '.firmas td{width:50%;padding:0 18px;text-align:center;vertical-align:top}'
    . '.firmas .linea{border-top:1px solid #334155;margin-bottom:5px}'
    . '.firmas .lbl{display:block;font-size:10px;font-weight:700;color:#1E293B;text-transform:none;letter-spacing:0}'
    . '.peq{font-size:8.5px;color:#64748B}'
    . '</style>' . $html;

pdf_render($html, 'liquidacion_' . $l['numero'], 'portrait', 'inline');
