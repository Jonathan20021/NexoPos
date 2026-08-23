<?php
/**
 * El documento de la amonestación — el que se entrega y se firma.
 *
 * Lleva lo que hace falta para que sirva de algo en un expediente:
 *
 *   · los HECHOS en concreto, no una etiqueta;
 *   · la fecha en que ocurrieron y la fecha en que la empresa lo supo;
 *   · el descargo del trabajador, si lo dio;
 *   · una línea de firma con acuse de recibo, y
 *   · el bloque de DOS TESTIGOS para cuando se niega a firmar, que es el único
 *     modo de acreditar que se le notificó.
 *
 * El acuse dice «recibí», no «estoy de acuerdo»: firmar no es aceptar los
 * hechos, y un documento que confunda las dos cosas invita a que nadie firme.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('amonestaciones.ver');

$id = (int) get('id');
$a = qOne("SELECT a.*, e.nombre, e.apellido, e.cedula, e.fecha_ingreso,
                  f.nombre AS falta, d.nombre AS departamento, s.nombre AS sucursal, p.nombre AS puesto
             FROM amonestaciones a
             JOIN empleados e ON e.id = a.empleado_id
             LEFT JOIN amonestacion_faltas f ON f.id = a.falta_id
             LEFT JOIN departamentos d ON d.id = e.departamento_id
             LEFT JOIN sucursales s ON s.id = e.sucursal_id
             LEFT JOIN puestos p ON p.id = e.puesto_id
            WHERE a.id = ?", [$id]);
if (!$a) { http_response_code(404); exit('Amonestación no encontrada.'); }

$emp    = $GLOBALS['empresa'] ?? [];
$nombre = trim($a['nombre'] . ' ' . $a['apellido']);
$tipo   = amonTipos()[$a['tipo']] ?? $a['tipo'];
$grav   = amonGravedades()[$a['gravedad']][0] ?? $a['gravedad'];

$html = pdf_brand_header(mb_strtoupper($tipo), $a['numero'] . ' · ' . fechaCorta($a['fecha_emision']));

$html .= '<table class="datos"><tr>'
    . '<td><span class="lbl">Empleado</span>' . e($nombre) . '</td>'
    . '<td><span class="lbl">Cédula</span>' . e($a['cedula'] ?: '—') . '</td></tr><tr>'
    . '<td><span class="lbl">Puesto</span>' . e($a['puesto'] ?: '—') . '</td>'
    . '<td><span class="lbl">Departamento</span>' . e($a['departamento'] ?: '—') . '</td></tr><tr>'
    . '<td><span class="lbl">Lugar de trabajo</span>' . e($a['sucursal'] ?: '—') . '</td>'
    . '<td><span class="lbl">Fecha de ingreso</span>' . ($a['fecha_ingreso'] ? fechaCorta($a['fecha_ingreso']) : '—') . '</td>'
    . '</tr></table>';

$html .= '<table class="datos b2"><tr>'
    . '<td><span class="lbl">Fecha del hecho</span>' . fechaCorta($a['fecha_hecho']) . '</td>'
    . '<td><span class="lbl">Gravedad</span>' . e($grav)
    . ($a['falta'] ? ' — ' . e($a['falta']) : '') . '</td></tr></table>';

$html .= '<h2 class="sec">Hechos</h2>'
    . '<p class="cuerpo">' . nl2br(e($a['hechos'])) . '</p>';

if ($a['medida']) {
    $html .= '<h2 class="sec">Medida</h2><p class="cuerpo">' . nl2br(e($a['medida'])) . '</p>';
}

if ($a['tipo'] === 'suspension' && $a['suspension_desde']) {
    $html .= '<p class="cuerpo destacado">Se aplica una <strong>suspensión de '
        . (int) $a['dias_suspension'] . ' día(s)</strong>, del '
        . fechaCorta($a['suspension_desde']) . ' al ' . fechaCorta($a['suspension_hasta']) . ', ambos inclusive.</p>';
}

if ($a['referencia_legal']) {
    $html .= '<p class="peq">Fundamento: ' . e($a['referencia_legal']) . '</p>';
}

$html .= '<h2 class="sec">Advertencia</h2>'
    . '<p class="cuerpo">Se le comunica formalmente que la conducta descrita no se corresponde con las'
    . ' obligaciones a su cargo. Se le exhorta a corregirla de inmediato. <strong>La reincidencia o la comisión'
    . ' de nuevas faltas podrá dar lugar a medidas disciplinarias mayores</strong>, conforme al Código de Trabajo'
    . ' de la República Dominicana y al reglamento interno de la empresa.</p>';

// El descargo se imprime si ya está recogido; si no, se deja el espacio para
// escribirlo a mano en el acto de la entrega.
$html .= '<h2 class="sec">Descargo del trabajador</h2>';
if ($a['descargo']) {
    $html .= '<p class="cuerpo">' . nl2br(e($a['descargo'])) . '</p>';
} else {
    $html .= '<p class="peq">Espacio para que el trabajador exponga su versión de los hechos, si desea hacerlo.</p>'
        . '<div class="renglon"></div><div class="renglon"></div><div class="renglon"></div>';
}

$html .= '<h2 class="sec">Acuse de recibo</h2>'
    . '<p class="peq">La firma acredita <strong>haber recibido</strong> este documento y haber sido informado de su'
    . ' contenido. No implica aceptación de los hechos ni renuncia a ningún derecho.</p>';

$html .= '<table class="firmas"><tr>'
    . '<td><div class="linea"></div><span class="lbl">' . e($nombre) . '</span>'
    . '<span class="peq">Cédula ' . e($a['cedula'] ?: '________________') . '</span>'
    . '<span class="peq">Fecha: ____ / ____ / ________</span></td>'
    . '<td><div class="linea"></div><span class="lbl">' . e($a['supervisor'] ?: 'Por la empresa') . '</span>'
    . '<span class="peq">' . e($emp['nombre'] ?? '') . '</span></td>'
    . '</tr></table>';

// Bloque de testigos: se imprime SIEMPRE. Si el día de la entrega el trabajador
// se niega a firmar, el papel ya tiene dónde dejarlo constar sin improvisar.
$rehuso = $a['estado'] === 'rehuso_firmar';
$html .= '<div class="testigos">'
    . '<p class="peq"><strong>' . ($rehuso ? 'CONSTANCIA DE NEGATIVA A FIRMAR' : 'Si el trabajador se niega a firmar') . '</strong> — '
    . 'Los abajo firmantes hacen constar que el presente documento le fue leído y entregado a '
    . e($nombre) . ' en la fecha indicada, y que este se negó a firmarlo.</p>'
    . '<table class="firmas"><tr>'
    . '<td><div class="linea"></div><span class="lbl">' . e($a['testigo1'] ?: 'Testigo 1') . '</span>'
    . '<span class="peq">Nombre, cédula y firma</span></td>'
    . '<td><div class="linea"></div><span class="lbl">' . e($a['testigo2'] ?: 'Testigo 2') . '</span>'
    . '<span class="peq">Nombre, cédula y firma</span></td>'
    . '</tr></table></div>';

$html .= pdf_pie('Original para el expediente del empleado · copia para el interesado · ' . $a['numero']);

$html = '<style>'
    . '.sec{font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:#475569;'
    . 'border-bottom:1px solid #E2E8F0;padding-bottom:3px;margin:14px 0 6px;font-weight:700}'
    . '.cuerpo{font-size:10.5px;line-height:1.65;text-align:justify;margin:6px 0}'
    . '.destacado{background:#FEF3C7;padding:7px 9px;border-radius:4px}'
    . '.datos{width:100%;margin:10px 0 2px;border-collapse:collapse}'
    . '.datos.b2{border-top:1px solid #E2E8F0;padding-top:4px}'
    . '.datos td{padding:4px 8px 4px 0;font-size:10.5px;width:50%;vertical-align:top}'
    . '.datos .lbl{display:block;font-size:8px;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8}'
    . '.renglon{border-bottom:1px solid #CBD5E1;height:17px}'
    . '.firmas{width:100%;margin-top:26px;border-collapse:collapse}'
    . '.firmas td{width:50%;padding:0 16px;text-align:center;vertical-align:top}'
    . '.firmas .linea{border-top:1px solid #334155;margin-bottom:4px}'
    . '.firmas .lbl{display:block;font-size:10px;font-weight:700;color:#1E293B}'
    . '.peq{font-size:8.5px;color:#64748B;line-height:1.5}'
    . '.testigos{margin-top:22px;border-top:1px dashed #CBD5E1;padding-top:10px}'
    . '</style>' . $html;

pdf_render($html, 'amonestacion_' . $a['numero'] . '.pdf', 'portrait', 'inline');
