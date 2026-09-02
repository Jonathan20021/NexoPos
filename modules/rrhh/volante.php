<?php
/**
 * Volante de pago — el papel que se le entrega a cada persona.
 *
 * El Código de Trabajo obliga al empleador a llevar constancia de lo que paga y
 * de lo que retiene, y ninguna retención se sostiene si el trabajador no puede
 * ver de dónde salió. Hasta ahora el sistema calculaba la nómina, la exportaba a
 * Excel y generaba el archivo del banco, pero no había forma de darle a nadie el
 * detalle de su propia quincena.
 *
 *   ?nomina=<id>                → un volante por cada persona de la nómina
 *   ?nomina=<id>&empleado=<id>  → solo el de esa persona
 *
 * Van DOS por hoja. Con 57 personas y 24 quincenas al año, uno por página son
 * mil trescientas hojas; el volante cabe de sobra en media carta y se corta por
 * la línea de puntos.
 *
 * ── Por qué los totales de aquí no son las columnas de la tabla ──
 *
 * `nomina_detalles.total_ingresos` guarda la BASE COTIZABLE, que deja fuera la
 * prima vacacional a propósito (ver includes/nomina.php), y `total_deducciones`
 * no incluye la cuota de préstamo. Ninguna de las dos sirve tal cual en un papel
 * que la persona va a cuadrar de cabeza: si «total ingresos» menos «total
 * deducciones» no da el neto que cobró, el volante genera una consulta en vez de
 * evitarla. Aquí se suman los conceptos de verdad, y por construcción cuadran:
 *
 *      (base + prima) − (retenciones + préstamo) = neto
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_nomina.ver');

$nominaId   = (int) get('nomina');
$empleadoId = (int) get('empleado');

$n = qOne("SELECT * FROM nominas WHERE id = ?", [$nominaId]);
if (!$n) { http_response_code(404); exit('Nómina no encontrada.'); }
if ($n['sucursal_id'] !== null) require_sucursal_access((int) $n['sucursal_id']);

$cond = ['nd.nomina_id = ?'];
$par  = [$nominaId];
if ($empleadoId > 0) { $cond[] = 'nd.empleado_id = ?'; $par[] = $empleadoId; }
$lineas = qAll(
    "SELECT nd.*, e.nombre, e.apellido, e.cedula, e.salario AS sueldo_mensual, e.codigo,
            e.banco, e.cuenta_bancaria,
            pu.nombre AS puesto, dep.nombre AS departamento, su.nombre AS sucursal
       FROM nomina_detalles nd
       JOIN empleados e ON e.id = nd.empleado_id
       LEFT JOIN puestos pu       ON pu.id  = e.puesto_id
       LEFT JOIN departamentos dep ON dep.id = e.departamento_id
       LEFT JOIN sucursales su     ON su.id  = e.sucursal_id
      WHERE " . implode(' AND ', $cond) . "
      ORDER BY e.nombre, e.apellido",
    $par
);
if (!$lineas) { http_response_code(404); exit('Esta nómina no tiene líneas que mostrar.'); }

$emp    = $GLOBALS['empresa'] ?? [];
$factor = $n['tipo'] === 'mensual' ? 1.0 : ($n['tipo'] === 'quincenal' ? 0.5 : 1 / 4.33);
$tssP   = function_exists('tssParametros') ? tssParametros($n['fecha_hasta']) : null;
$periodo = fechaCorta($n['fecha_desde']) . ' al ' . fechaCorta($n['fecha_hasta']);

/** Una fila de concepto: etiqueta a la izquierda, importe a la derecha. */
function volFila(string $etiqueta, float $monto, string $nota = '', bool $fuerte = false): string
{
    if (abs($monto) < 0.005 && !$fuerte) return '';
    return '<tr>'
        . '<td class="cpt' . ($fuerte ? ' b' : '') . '">' . htmlspecialchars($etiqueta)
        . ($nota !== '' ? '<span class="nt">' . htmlspecialchars($nota) . '</span>' : '')
        . '</td>'
        . '<td class="imp' . ($fuerte ? ' b' : '') . '">' . number_format($monto, 2) . '</td>'
        . '</tr>';
}

/** El volante de una persona. */
function volante(array $d, array $n, array $emp, float $factor, ?array $tssP, string $periodo): string
{
    $nombre = trim($d['nombre'] . ' ' . $d['apellido']);
    // La regalía no es una quincena: no lleva días, no cotiza y no paga ISR.
    // Llamarle «sueldo del período» y enseñar debajo el aporte patronal a la TSS
    // sería decirle a la persona algo que no ocurrió.
    $esRegalia = ($n['tipo'] ?? '') === 'regalia';
    $base   = (float) $d['total_ingresos'];      // base cotizable
    $prima  = (float) $d['prima_vacacional'];
    $ret    = (float) $d['total_deducciones'];   // AFP + SFS + ISR + per cápita
    $prest  = (float) $d['otras_deducciones'];   // cuota de préstamo aplicada
    $ingresos   = round($base + $prima, 2);
    $deducciones= round($ret + $prest, 2);
    $neto       = (float) $d['salario_neto'];

    /* -------- Ingresos -------- */
    $ing = volFila($esRegalia ? 'Regalía pascual' : 'Sueldo del período', (float) $d['salario_base'],
        $esRegalia
            ? 'Una duodécima parte del salario ordinario del año · exenta de ISR y sin TSS'
            : ((float) $d['dias_base'] > 0
                ? qty($d['dias_trabajados']) . ' de ' . qty($d['dias_base']) . ' día(s)' : ''), true);
    $ing .= volFila('Horas extra', (float) $d['monto_horas_extra'],
        (float) $d['horas_extra'] > 0 ? qty($d['horas_extra']) . ' hora(s)' : '');
    $ing .= volFila('Bonificaciones', (float) $d['bonificaciones']);
    $ing .= volFila('Comisiones', (float) $d['comisiones']);
    $ing .= volFila('Vacaciones (diferencial)', (float) $d['vacaciones_diferencial']);
    $ing .= volFila('Reembolso', (float) $d['reembolso']);
    $ing .= volFila('Otros ingresos', (float) $d['otros_ingresos']);
    $ing .= volFila('Prima vacacional', $prima);
    // El descuento por días ya viene restado dentro de la base; se enseña para
    // que la resta se pueda seguir con el lápiz.
    if ((float) $d['descuento_dias'] > 0.005) {
        $ing .= '<tr><td class="cpt">Descuento por días no laborados</td>'
              . '<td class="imp neg">−' . number_format((float) $d['descuento_dias'], 2) . '</td></tr>';
    }

    /* -------- Deducciones -------- */
    $ded  = volFila('AFP · pensiones (2.87%)', (float) $d['afp'], '', true);
    $ded .= volFila('SFS · salud (3.04%)', (float) $d['sfs'], '', true);
    $ded .= volFila('ISR · impuesto sobre la renta', (float) $d['isr'], '', true);
    $ded .= volFila('Per cápita adicional', (float) $d['per_capita']);
    $ded .= volFila('Cuota de préstamo', $prest);

    /* -------- Lo que además pone la empresa (informativo) -------- */
    $patronal = '';
    if (!$esRegalia && function_exists('tssAportes')) {
        $a = tssAportes($base, $factor, $tssP);
        $patronal = '<div class="patronal">'
            . '<span class="ttl">Además, la empresa aporta por usted a la TSS</span>'
            . ' SFS ' . number_format($a['empleador']['sfs'], 2)
            . ' · AFP ' . number_format($a['empleador']['afp'], 2)
            . ' · Riesgos laborales ' . number_format($a['empleador']['srl'], 2)
            . ' · INFOTEP ' . number_format($a['empleador']['infotep'], 2)
            . ' &nbsp;=&nbsp; <strong>' . number_format($a['total_empleador'], 2) . '</strong>'
            . '</div>';
    }

    $pago = trim((string) $d['banco']) !== ''
        ? htmlspecialchars($d['banco']) . ((string) $d['cuenta_bancaria'] !== ''
            ? ' · cuenta ' . htmlspecialchars(substr((string) $d['cuenta_bancaria'], 0, -4) === ''
                ? (string) $d['cuenta_bancaria']
                : str_repeat('•', max(0, strlen((string) $d['cuenta_bancaria']) - 4))
                  . substr((string) $d['cuenta_bancaria'], -4))
            : '')
        : 'Efectivo';

    $h = '<div class="vol">';
    $h .= '<table class="cab"><tr>'
        . '<td class="e">' . htmlspecialchars($emp['nombre'] ?? APP_NAME)
        . (!empty($emp['rnc']) ? '<span class="nt">RNC ' . htmlspecialchars($emp['rnc']) . '</span>' : '')
        . '</td>'
        . '<td class="t">VOLANTE DE PAGO<span class="nt">' . htmlspecialchars($n['descripcion']) . '</span></td>'
        . '</tr></table>';

    $h .= '<table class="ident"><tr>'
        . '<td><span class="lbl">Empleado</span>' . htmlspecialchars($nombre)
        . ($d['codigo'] ? ' <span class="nt2">' . htmlspecialchars($d['codigo']) . '</span>' : '') . '</td>'
        . '<td><span class="lbl">Cédula</span>' . htmlspecialchars($d['cedula'] ?: '—') . '</td>'
        . '<td><span class="lbl">Período</span>' . htmlspecialchars($periodo) . '</td>'
        . '</tr><tr>'
        . '<td><span class="lbl">Puesto</span>' . htmlspecialchars($d['puesto'] ?: ($d['departamento'] ?: '—')) . '</td>'
        . '<td><span class="lbl">Lugar de trabajo</span>' . htmlspecialchars($d['sucursal'] ?: '—') . '</td>'
        . '<td><span class="lbl">Forma de pago</span>' . $pago . '</td>'
        . '</tr></table>';

    $h .= '<table class="cols"><tr>'
        . '<td class="col"><table class="conc"><thead><tr><th colspan="2">INGRESOS</th></tr></thead>'
        . '<tbody>' . $ing . '</tbody>'
        . '<tfoot><tr><td class="cpt b">Total devengado</td><td class="imp b">'
        . number_format($ingresos, 2) . '</td></tr></tfoot></table></td>'
        . '<td class="col"><table class="conc"><thead><tr><th colspan="2">DEDUCCIONES</th></tr></thead>'
        . '<tbody>' . $ded . '</tbody>'
        . '<tfoot><tr><td class="cpt b">Total retenido</td><td class="imp b">'
        . number_format($deducciones, 2) . '</td></tr></tfoot></table></td>'
        . '</tr></table>';

    $h .= '<table class="neto"><tr>'
        . '<td class="lbl2">NETO A RECIBIR</td>'
        . '<td class="val">RD$ ' . number_format($neto, 2) . '</td>'
        . '</tr></table>';

    $h .= $patronal;

    $h .= '<table class="firma"><tr>'
        . '<td><div class="linea"></div><span class="nt">Recibí conforme · ' . htmlspecialchars($nombre) . '</span></td>'
        . '<td><div class="linea"></div><span class="nt">Fecha</span></td>'
        . '</tr></table>';

    $h .= '</div>';
    return $h;
}

/* ---------- Armado del documento ---------- */
$css = '<style>'
    . '.vol{border:1px solid #CBD5E1;border-radius:5px;padding:9px 11px 7px;margin-bottom:9px}'
    . '.cab{width:100%;border-collapse:collapse;margin-bottom:6px}'
    . '.cab .e{font-size:11px;font-weight:bold;color:#1E293B}'
    . '.cab .t{text-align:right;font-size:10px;font-weight:bold;letter-spacing:.08em;color:' . marca_app() . '}'
    . '.nt{display:block;font-size:7.5px;font-weight:normal;color:#94A3B8;letter-spacing:0}'
    . '.nt2{font-size:7.5px;color:#94A3B8}'
    . '.ident{width:100%;border-collapse:collapse;border-top:1.4px solid ' . marca_app() . ';padding-top:3px}'
    . '.ident td{padding:3px 8px 3px 0;font-size:8.6px;color:#1E293B;width:33.3%;vertical-align:top}'
    . '.ident .lbl{display:block;font-size:6.6px;text-transform:uppercase;letter-spacing:.07em;color:#94A3B8}'
    . '.cols{width:100%;border-collapse:collapse;margin-top:4px}'
    . '.cols .col{width:50%;vertical-align:top;padding:0 5px 0 0}'
    . '.conc{width:100%;border-collapse:collapse}'
    . '.conc th{font-size:6.8px;letter-spacing:.09em;color:#64748B;text-align:left;'
    .          'border-bottom:1px solid #E2E8F0;padding:2px 0 3px}'
    . '.conc .cpt{font-size:8.4px;color:#334155;padding:2.6px 0;line-height:1.2}'
    . '.conc .imp{font-size:8.6px;color:#0F172A;text-align:right;padding:2.6px 0}'
    . '.conc .neg{color:#BE123C}'
    . '.conc .b{font-weight:bold}'
    . '.conc tfoot td{border-top:1px solid #CBD5E1;padding-top:3.4px}'
    . '.neto{width:100%;border-collapse:collapse;margin-top:6px;background:#F1F5F9}'
    . '.neto .lbl2{font-size:8.4px;font-weight:bold;letter-spacing:.08em;color:#334155;padding:5px 8px}'
    . '.neto .val{font-size:13px;font-weight:bold;color:#0F172A;text-align:right;padding:5px 8px}'
    . '.patronal{font-size:7.4px;color:#64748B;margin-top:5px;line-height:1.4}'
    . '.patronal .ttl{display:block;font-weight:bold;color:#475569}'
    . '.firma{width:100%;border-collapse:collapse;margin-top:16px}'
    . '.firma td{width:50%;padding:0 14px;text-align:center;vertical-align:top}'
    . '.firma .linea{border-top:1px solid #64748B;margin-bottom:3px}'
    . '.corte{border-top:1px dashed #94A3B8;margin:2px 0 9px;font-size:6px;color:#CBD5E1}'
    . '</style>';

$html = $css;
$i = 0;
foreach ($lineas as $d) {
    $html .= volante($d, $n, $emp, $factor, $tssP, $periodo);
    $i++;
    if ($i % 2 === 0 && $i < count($lineas)) {
        $html .= '<div style="page-break-after:always"></div>';
    } elseif ($i < count($lineas)) {
        $html .= '<div class="corte">&nbsp;</div>';   // por aquí se corta la hoja
    }
}

$nombreArchivo = 'volantes_' . preg_replace('/[^A-Za-z0-9]+/', '_', $n['descripcion'])
    . ($empleadoId > 0 ? '_' . $empleadoId : '');
pdf_render($html, $nombreArchivo, 'portrait', 'inline');
