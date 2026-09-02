<?php
/**
 * Certificación de ingresos y retenciones del año.
 *
 * El papel que un empleado pide para un préstamo, una visa o para declarar por
 * su cuenta cuando tiene otros ingresos, y que la empresa está en posición de
 * dar porque es quien retuvo. Hasta ahora había que armarlo a mano leyendo doce
 * quincenas.
 *
 *   ?empleado=<id>&anio=<yyyy>   → la de una persona
 *   ?anio=<yyyy>                 → la de todo el que cobró ese año, una por hoja
 *
 * ── Qué se certifica y qué no ──
 *
 * Solo lo PAGADO. Una nómina confirmada pero sin pagar es una retención que
 * todavía no ocurrió, y certificar dinero que no se movió es firmar algo falso.
 * Si en el año quedan nóminas sin pagar, el documento lo dice en el pie en vez
 * de dar un número corto sin explicación.
 *
 * La regalía pascual va en su propia línea y FUERA de la base: está exenta de
 * ISR por el art. 222, así que sumarla a los ingresos gravados haría que las
 * cuentas del empleado no cuadren con lo que se le retuvo.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_nomina.ver');

$anio = (int) (get('anio') ?: date('Y'));
if ($anio < 2000 || $anio > (int) date('Y') + 1) $anio = (int) date('Y');
$empleadoId = (int) get('empleado');

$desde = sprintf('%04d-01-01', $anio);
$hasta = sprintf('%04d-12-31', $anio);

/* ---------- A quién se le certifica ---------- */
$cond = ["n.estado = 'pagada'", "n.fecha_hasta BETWEEN ? AND ?"];
$par  = [$desde, $hasta];
if ($empleadoId > 0) { $cond[] = 'nd.empleado_id = ?'; $par[] = $empleadoId; }

$filas = qAll(
    "SELECT nd.empleado_id,
            DATE_FORMAT(n.fecha_hasta, '%Y-%m') AS ym,
            n.tipo,
            COALESCE(SUM(nd.total_ingresos + nd.prima_vacacional),0) AS ingresos,
            COALESCE(SUM(nd.afp),0) afp, COALESCE(SUM(nd.sfs),0) sfs,
            COALESCE(SUM(nd.isr),0) isr, COALESCE(SUM(nd.per_capita),0) per_capita,
            COALESCE(SUM(nd.salario_neto),0) neto
       FROM nomina_detalles nd
       JOIN nominas n ON n.id = nd.nomina_id
      WHERE " . implode(' AND ', $cond) . "
      GROUP BY nd.empleado_id, DATE_FORMAT(n.fecha_hasta, '%Y-%m'), n.tipo
      ORDER BY nd.empleado_id, ym",
    $par
);
if (!$filas) {
    http_response_code(404);
    exit('No hay ninguna nómina PAGADA de ' . $anio . ($empleadoId ? ' para esa persona' : '') . '.');
}

/* ---------- Se agrupa por persona ---------- */
$porEmpleado = [];
foreach ($filas as $f) {
    $eid = (int) $f['empleado_id'];
    $porEmpleado[$eid] ??= ['meses' => [], 'regalia' => 0.0,
        'tot' => ['ingresos' => 0.0, 'afp' => 0.0, 'sfs' => 0.0, 'isr' => 0.0, 'per_capita' => 0.0, 'neto' => 0.0]];

    if ($f['tipo'] === 'regalia') {
        // Exenta: no entra en la base ni en los totales gravados.
        $porEmpleado[$eid]['regalia'] += (float) $f['ingresos'];
        continue;
    }
    $m = $f['ym'];
    $porEmpleado[$eid]['meses'][$m] ??= ['ingresos' => 0.0, 'afp' => 0.0, 'sfs' => 0.0,
                                         'isr' => 0.0, 'per_capita' => 0.0, 'neto' => 0.0];
    foreach (['ingresos', 'afp', 'sfs', 'isr', 'per_capita', 'neto'] as $k) {
        $porEmpleado[$eid]['meses'][$m][$k] += (float) $f[$k];
        $porEmpleado[$eid]['tot'][$k]       += (float) $f[$k];
    }
}

$ids = array_keys($porEmpleado);
$ph  = implode(',', array_fill(0, count($ids), '?'));
$gente = [];
foreach (qAll(
    "SELECT e.id, e.nombre, e.apellido, e.cedula, e.codigo, e.fecha_ingreso,
            pu.nombre AS puesto, dep.nombre AS departamento, su.nombre AS sucursal
       FROM empleados e
       LEFT JOIN puestos pu        ON pu.id  = e.puesto_id
       LEFT JOIN departamentos dep ON dep.id = e.departamento_id
       LEFT JOIN sucursales su     ON su.id  = e.sucursal_id
      WHERE e.id IN ($ph) ORDER BY e.nombre, e.apellido", $ids) as $g) {
    $gente[(int) $g['id']] = $g;
}

/* ---------- ¿Queda algo sin pagar en el año? ---------- */
$sinPagar = qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(total_neto),0) monto FROM nominas
      WHERE estado = 'procesada' AND fecha_hasta BETWEEN ? AND ?", [$desde, $hasta]
) ?: ['n' => 0, 'monto' => 0];

$emp = $GLOBALS['empresa'] ?? [];

/* ---------- El documento ---------- */
function ciFila(string $etiqueta, float $monto, bool $fuerte = false, bool $neg = false): string
{
    return '<tr>'
        . '<td class="' . ($fuerte ? 'b' : '') . '">' . htmlspecialchars($etiqueta) . '</td>'
        . '<td class="r ' . ($fuerte ? 'b' : '') . ($neg ? ' neg' : '') . '">'
        . ($neg ? '−' : '') . number_format($monto, 2) . '</td>'
        . '</tr>';
}

function certificacion(array $g, array $d, int $anio, array $emp, array $sinPagar): string
{
    $nombre = trim($g['nombre'] . ' ' . $g['apellido']);
    $t = $d['tot'];
    $tssTotal = $t['afp'] + $t['sfs'] + $t['per_capita'];

    $h = pdf_brand_header('Certificación de ingresos y retenciones', 'Año ' . $anio);

    $h .= '<table class="datos"><tr>'
        . '<td><span class="lbl">Empleado</span>' . e($nombre)
        . ($g['codigo'] ? ' <span class="peq">' . e($g['codigo']) . '</span>' : '') . '</td>'
        . '<td><span class="lbl">Cédula</span>' . e($g['cedula'] ?: '—') . '</td>'
        . '</tr><tr>'
        . '<td><span class="lbl">Puesto</span>' . e($g['puesto'] ?: ($g['departamento'] ?: '—')) . '</td>'
        . '<td><span class="lbl">Lugar de trabajo</span>' . e($g['sucursal'] ?: '—') . '</td>'
        . '</tr><tr>'
        . '<td><span class="lbl">Fecha de ingreso</span>'
        . ($g['fecha_ingreso'] ? fechaCorta($g['fecha_ingreso']) : '—') . '</td>'
        . '<td><span class="lbl">Período certificado</span>1 de enero al 31 de diciembre de ' . $anio . '</td>'
        . '</tr></table>';

    $h .= '<p class="cuerpo"><strong>' . e($emp['nombre'] ?? '') . '</strong>'
        . (!empty($emp['rnc']) ? ', RNC ' . e($emp['rnc']) : '')
        . ', hace constar que durante el año ' . $anio . ' pagó a <strong>' . e($nombre) . '</strong> '
        . 'las sumas que se detallan y le retuvo los aportes a la seguridad social y el impuesto sobre '
        . 'la renta que igualmente se indican.</p>';

    /* Mes a mes */
    $meses = '';
    ksort($d['meses']);
    foreach ($d['meses'] as $ym => $m) {
        $meses .= '<tr>'
            . '<td>' . e(mesNombre((int) substr($ym, 5, 2))) . '</td>'
            . '<td class="r">' . number_format($m['ingresos'], 2) . '</td>'
            . '<td class="r">' . number_format($m['afp'], 2) . '</td>'
            . '<td class="r">' . number_format($m['sfs'], 2) . '</td>'
            . '<td class="r">' . number_format($m['isr'], 2) . '</td>'
            . '<td class="r">' . number_format($m['neto'], 2) . '</td>'
            . '</tr>';
    }
    $h .= '<table class="items"><thead><tr>'
        . '<th>Mes</th><th class="r">Ingresos gravados</th><th class="r">AFP</th>'
        . '<th class="r">SFS</th><th class="r">ISR retenido</th><th class="r">Neto pagado</th>'
        . '</tr></thead><tbody>' . $meses . '</tbody>'
        . '<tfoot><tr>'
        . '<td class="b">TOTAL ' . $anio . '</td>'
        . '<td class="r b">' . number_format($t['ingresos'], 2) . '</td>'
        . '<td class="r b">' . number_format($t['afp'], 2) . '</td>'
        . '<td class="r b">' . number_format($t['sfs'], 2) . '</td>'
        . '<td class="r b">' . number_format($t['isr'], 2) . '</td>'
        . '<td class="r b">' . number_format($t['neto'], 2) . '</td>'
        . '</tr></tfoot></table>';

    /* Resumen */
    $res = ciFila('Ingresos gravados del año', $t['ingresos'], true);
    $res .= ciFila('(−) Aportes a la seguridad social (AFP + SFS'
        . ($t['per_capita'] > 0 ? ' + per cápita' : '') . ')', $tssTotal, false, true);
    $res .= ciFila('(−) Impuesto sobre la renta retenido', $t['isr'], false, true);
    $res .= ciFila('Neto pagado', $t['neto'], true);
    if ($d['regalia'] > 0) {
        $res .= '<tr><td colspan="2" class="sep"></td></tr>';
        $res .= ciFila('Salario de Navidad (exento de ISR, art. 222)', $d['regalia']);
    }
    $h .= '<table class="resumen">' . $res . '</table>';

    if ($d['regalia'] > 0) {
        $h .= '<p class="peq">El salario de Navidad se detalla aparte porque no está sujeto al impuesto '
            . 'sobre la renta (art. 222 del Código de Trabajo) y por tanto no forma parte de los ingresos '
            . 'gravados sobre los que se calculó la retención.</p>';
    }
    if ((int) $sinPagar['n'] > 0) {
        $h .= '<p class="peq">Esta certificación recoge únicamente lo efectivamente pagado. Al cierre de '
            . 'su emisión quedaban ' . (int) $sinPagar['n'] . ' nómina(s) del año procesadas y pendientes '
            . 'de pago, cuyos importes no se incluyen.</p>';
    }

    $h .= '<table class="firmas"><tr>'
        . '<td><div class="linea"></div><span class="lbl2">Por ' . e($emp['nombre'] ?? 'la empresa') . '</span>'
        . '<span class="peq">Nombre, cargo y firma</span></td>'
        . '</tr></table>';

    $h .= '<p class="peq" style="margin-top:12px">Expedida en '
        . e($emp['direccion'] ?? '') . ', a los ______ días del mes de ____________ del año ________, '
        . 'a solicitud de la parte interesada.</p>';

    return $h;
}

$css = '<style>'
    . '.cuerpo{font-size:10.5px;line-height:1.6;text-align:justify;margin:9px 0}'
    . '.datos{width:100%;margin:10px 0 4px;border-collapse:collapse}'
    . '.datos td{padding:4px 8px 4px 0;font-size:10.5px;width:50%;vertical-align:top}'
    . '.lbl{display:block;font-size:8px;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8}'
    . '.resumen{width:60%;margin:12px 0 4px;border-collapse:collapse}'
    . '.resumen td{padding:4px 8px 4px 0;font-size:10.5px;border-bottom:1px solid #EEF2F7}'
    . '.resumen .r{text-align:right}'
    . '.resumen .b{font-weight:bold;color:#0F172A}'
    . '.resumen .neg{color:#BE123C}'
    . '.resumen .sep{border:0;height:6px}'
    . '.firmas{width:100%;margin-top:34px;border-collapse:collapse}'
    . '.firmas td{width:60%;padding:0 18px;text-align:center;vertical-align:top}'
    . '.firmas .linea{border-top:1px solid #334155;margin-bottom:5px}'
    . '.lbl2{display:block;font-size:10px;font-weight:700;color:#1E293B}'
    . '.peq{font-size:8.5px;color:#64748B;line-height:1.5;margin:6px 0}'
    . '</style>';

$html = $css;
$i = 0;
foreach ($gente as $eid => $g) {
    if (!isset($porEmpleado[$eid])) continue;
    if ($i > 0) $html .= '<div style="page-break-before:always"></div>';
    $html .= certificacion($g, $porEmpleado[$eid], $anio, $emp, $sinPagar);
    $i++;
}
$html .= pdf_pie('Documento generado por NexoPOS · Certificación de retenciones ' . $anio);

pdf_render($html, 'constancia_retenciones_' . $anio . ($empleadoId > 0 ? '_' . $empleadoId : ''),
    'portrait', 'inline');
