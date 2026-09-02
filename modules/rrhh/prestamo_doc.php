<?php
/**
 * Autorización de descuento por nómina — el papel que se firma.
 *
 * No es un adorno: el Código de Trabajo exige el consentimiento del trabajador
 * para retenerle del salario algo que no sea obligatorio. Este documento es esa
 * constancia, y por eso lleva el cuadro de cuotas completo —para que quien
 * firma sepa exactamente cuánto, cuántas veces y hasta cuándo— y una línea de
 * firma con fecha.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('prestamos.ver');

$id = (int) get('id');
$p = qOne("SELECT p.*, e.nombre, e.apellido, e.cedula, e.salario, e.fecha_ingreso,
                  d.nombre AS departamento, s.nombre AS sucursal
             FROM prestamos p
             JOIN empleados e ON e.id = p.empleado_id
             LEFT JOIN departamentos d ON d.id = e.departamento_id
             LEFT JOIN sucursales s ON s.id = e.sucursal_id
            WHERE p.id = ?", [$id]);
if (!$p) { http_response_code(404); exit('Préstamo no encontrado.'); }

$cuotas = qAll("SELECT * FROM prestamo_cuotas WHERE prestamo_id = ? ORDER BY numero", [$id]);
$emp = $GLOBALS['empresa'] ?? [];
$totalInteres = array_sum(array_map(fn($c) => (float) $c['interes'], $cuotas));
$totalPagar   = round((float) $p['monto'] + $totalInteres, 2);
$esAvance     = $p['tipo'] === 'avance';
$nombre       = trim($p['nombre'] . ' ' . $p['apellido']);

$filas = '';
foreach ($cuotas as $c) {
    $filas .= '<tr>'
        . '<td class="c">' . (int) $c['numero'] . '</td>'
        . '<td class="c">' . fechaCorta($c['fecha_prevista']) . '</td>'
        . '<td class="r">' . pdf_numero($c['capital']) . '</td>'
        . '<td class="r">' . pdf_numero($c['interes']) . '</td>'
        . '<td class="r b">' . pdf_numero($c['total']) . '</td>'
        . '<td class="r">' . pdf_numero($c['saldo_despues']) . '</td>'
        . '</tr>';
}

$html = pdf_brand_header(
    $esAvance ? 'Autorización de descuento — Avance de sueldo' : 'Autorización de descuento por nómina',
    $p['numero'] . ' · ' . fechaCorta($p['fecha_desembolso'])
);

$html .= '<table class="datos"><tr>'
    . '<td><span class="lbl">Empleado</span>' . e($nombre) . '</td>'
    . '<td><span class="lbl">Cédula</span>' . e($p['cedula'] ?: '—') . '</td>'
    . '</tr><tr>'
    . '<td><span class="lbl">Departamento</span>' . e($p['departamento'] ?: '—') . '</td>'
    . '<td><span class="lbl">Lugar de trabajo</span>' . e($p['sucursal'] ?: '—') . '</td>'
    . '</tr></table>';

$html .= '<p class="cuerpo">Yo, <strong>' . e($nombre) . '</strong>, portador'
    . ' de la cédula de identidad y electoral núm. <strong>' . e($p['cedula'] ?: '________________') . '</strong>,'
    . ' empleado de <strong>' . e($emp['nombre'] ?? '') . '</strong>'
    . (!empty($emp['rnc']) ? ', RNC ' . e($emp['rnc']) : '') . ', declaro que he recibido'
    . ($esAvance ? ' un <strong>avance de sueldo</strong>' : ' un <strong>préstamo</strong>')
    . ' por la suma de <strong>' . money($p['monto']) . '</strong>'
    . ' (<em>' . e(pdf_en_letras((float) $p['monto'])) . '</em>)'
    . ($p['motivo'] ? ', destinado a <em>' . e($p['motivo']) . '</em>' : '')
    . '.</p>';

$html .= '<p class="cuerpo">En consecuencia, <strong>autorizo expresamente</strong> a mi empleador a'
    . ' descontar de mi salario, en ' . count($cuotas) . ' cuota' . (count($cuotas) === 1 ? '' : 's')
    . ' ' . mb_strtolower(presPeriodicidades()[$p['periodicidad']] ?? '') . (count($cuotas) === 1 ? '' : 'es')
    . ', la suma total de <strong>' . money($totalPagar) . '</strong>'
    . ((float) $p['tasa_anual'] > 0
        ? ', que incluye ' . money($totalInteres, false) . ' por concepto de intereses a una tasa de '
          . rtrim(rtrim(number_format((float) $p['tasa_anual'], 3, '.', ''), '0'), '.') . '% anual'
        : ', <strong>sin intereses</strong>')
    . ', conforme al calendario que se detalla a continuación.</p>';

$html .= '<p class="cuerpo">Esta autorización se otorga de forma libre y voluntaria, y se entiende sin perjuicio'
    . ' de las retenciones obligatorias de ley —seguridad social e impuesto sobre la renta—, que se aplican con'
    . ' preferencia a este descuento. En caso de terminación del contrato de trabajo por cualquier causa,'
    . ' autorizo que el saldo pendiente se compense con las prestaciones o valores que me correspondan, en la'
    . ' medida en que lo permita la legislación laboral vigente.</p>';

$html .= '<table class="items"><thead><tr>'
    . '<th class="c">Cuota</th><th class="c">Fecha</th><th class="r">Capital</th>'
    . '<th class="r">Interés</th><th class="r">A descontar</th><th class="r">Saldo</th>'
    . '</tr></thead><tbody>' . $filas . '</tbody>'
    . '<tfoot><tr>'
    . '<td colspan="2" class="b">TOTALES</td>'
    . '<td class="r b">' . pdf_numero($p['monto']) . '</td>'
    . '<td class="r b">' . pdf_numero($totalInteres) . '</td>'
    . '<td class="r b">' . pdf_numero($totalPagar) . '</td>'
    . '<td></td></tr></tfoot></table>';

$html .= '<table class="firmas"><tr>'
    . '<td><div class="linea"></div><span class="lbl">' . e($nombre) . '</span>'
    . '<span class="peq">Cédula ' . e($p['cedula'] ?: '________________') . '</span></td>'
    . '<td><div class="linea"></div><span class="lbl">Por ' . e($emp['nombre'] ?? 'la empresa') . '</span>'
    . '<span class="peq">Nombre, cargo y firma</span></td>'
    . '</tr></table>';

$html .= '<p class="peq" style="margin-top:14px">Firmado en '
    . e($emp['direccion'] ?? '') . ', a los ______ días del mes de ____________ del año ________.</p>';

$html .= pdf_pie('Documento generado por NexoPOS · ' . $p['numero']
    . ' · Conserve una copia en el expediente del empleado.');

// Estilos propios del documento, encima de los de la casa.
$html = '<style>'
    . '.cuerpo{font-size:10.5px;line-height:1.65;text-align:justify;margin:9px 0}'
    . '.datos{width:100%;margin:10px 0 4px;border-collapse:collapse}'
    . '.datos td{padding:4px 8px 4px 0;font-size:10.5px;width:50%;vertical-align:top}'
    . '.datos .lbl{display:block;font-size:8px;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8}'
    . '.firmas{width:100%;margin-top:40px;border-collapse:collapse}'
    . '.firmas td{width:50%;padding:0 18px;text-align:center;vertical-align:top}'
    . '.firmas .linea{border-top:1px solid #334155;margin-bottom:5px}'
    . '.firmas .lbl{display:block;font-size:10px;font-weight:700;color:#1E293B}'
    . '.peq{font-size:8.5px;color:#64748B}'
    . '</style>' . $html;

pdf_render($html, 'autorizacion_' . $p['numero'] . '.pdf', 'portrait', 'inline');
