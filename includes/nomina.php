<?php
/**
 * Cálculo de nómina dominicana (TSS + ISR).
 *
 * Vive aparte de la pantalla para que se pueda probar: `modules/rrhh/nomina.php`
 * ejecuta la página entera al incluirlo, así que un banco de pruebas no podía
 * llamar a estas funciones sin dispararla.
 */

/**
 * Cálculo de una línea de nómina dominicana.
 *
 *  - AFP 2.87% y SFS 3.04% sobre la BASE COTIZABLE del período.
 *  - ISR: escala anual sobre el equivalente mensual, prorrateado al período.
 *
 * ── Por qué el ISR no se calcula sobre lo que se paga en el período ──
 *
 * La escala es anual y progresiva, así que anualizar una quincena (x12 sobre
 * medio mes) da la mitad de la renta real y baja a casi todo el mundo de tramo
 * — con el padrón de Importers dejaba exentos a cinco de los seis que sí
 * tributan. Se saca el ISR del mes completo y recién ahí se prorratea, que es
 * como lo hace la DGII y como lo calcula el contador del cliente. AFP y SFS son
 * porcentaje plano: dan igual en cualquier orden.
 *
 * ── La base cotizable ──
 *
 * Es la fórmula que definió el contador (columna N de su hoja):
 *
 *   base = sueldo del período + feriado/horas extra + otras remuneraciones
 *          + reembolso + vacaciones diferencial + incentivos − descuento de días
 *
 * La **prima vacacional queda FUERA** a propósito: así está en su hoja.
 * PENDIENTE de confirmar con él si debería cotizar; mientras tanto se paga en el
 * neto sin tocar AFP, SFS ni ISR. Cambiar una base de cotización sin respaldo se
 * declara mal a la TSS, y eso no se arregla después.
 *
 * ── Dos correcciones sobre la hoja del cliente ──
 *
 * Su Excel tiene dos fórmulas que no hacen lo que sus propias columnas dicen.
 * Hoy no se nota porque esas columnas están en cero; el día que se usen, sí:
 *
 *   · `U (neto) = N − S` **ignora la columna T**, el préstamo al empleado. Con
 *     su fórmula, un préstamo se registra y no se descuenta: se le paga de más.
 *     Aquí SÍ se resta.
 *   · `N` ignora la columna G, la prima vacacional, y `U` tampoco la suma: con
 *     su fórmula la prima **no se pagaría nunca**. Aquí se paga.
 *
 * Consecuencia buscada: en cuanto alguien tenga préstamo o prima, el sistema
 * dará distinto que su hoja. Es lo correcto y hay que avisárselo al contador.
 *
 * ── Prorrateo por días ──
 *
 * El Excel usa `=(Sueldo/2/11.91)*DíasPagados`: 11.91 es su convenio de días
 * laborables de una quincena. Con días = base el resultado es exactamente medio
 * sueldo, así que una quincena normal cuadra al centavo.
 *
 * @param float $salarioMensual  Como lo guarda `empleados`: mensual, entero.
 * @param array $c               Conceptos DEL PERÍODO, todos opcionales.
 * @param float $factor          1 mensual, 0.5 quincenal, 1/4.33 semanal.
 */
/**
 * @param array|bool|null $tss Parámetros de la TSS a usar.
 *                             null  = los vigentes en base (o ninguno si no hay);
 *                             array = estos, para simular o recalcular un mes viejo;
 *                             false = sin topes, modo función pura para las pruebas.
 */
function calcNominaRD(float $salarioMensual, array $c = [], float $factor = 1.0, array|bool|null $tss = null): array
{
    $n = static fn(string $k): float => max(0.0, (float) ($c[$k] ?? 0));

    // 1) Sueldo del período, prorrateado por los días realmente pagados.
    $diasBase = (float) ($c['dias_base'] ?? 0);
    $dias     = (float) ($c['dias_trabajados'] ?? 0);
    $completo = round($salarioMensual * $factor, 2);
    $salarioPeriodo = ($diasBase > 0 && $dias > 0)
        ? round($salarioMensual * $factor / $diasBase * $dias, 2)
        : $completo;

    // 2) Base cotizable (columna N). La prima vacacional NO entra.
    $base = round(
        $salarioPeriodo
        + $n('monto_horas_extra') + $n('otros_ingresos') + $n('comisiones')
        + $n('reembolso') + $n('vacaciones_diferencial') + $n('bonificaciones')
        - $n('descuento_dias'),
        2
    );
    $base = max(0.0, $base);

    // Retenciones de la TSS, con los TOPES de la Ley 87-01 si están encendidos.
    //
    // Mientras `aplicar_topes` esté en 0 —que es como nace— tssAportes()
    // devuelve exactamente base × 2.87% y base × 3.04%, o sea lo mismo que
    // hacía esta función antes. Encenderlos es una decisión del contador, no un
    // efecto secundario de desplegar. Ver includes/tss.php.
    if ($tss === false || !function_exists('tssAportes')) {
        $afp = round($base * 0.0287, 2);
        $sfs = round($base * 0.0304, 2);
    } else {
        $ap  = tssAportes($base, $factor, is_array($tss) ? $tss : null);
        $afp = $ap['empleado']['afp'];
        $sfs = $ap['empleado']['sfs'];
    }

    // 3) ISR sobre el equivalente MENSUAL de lo que se está ganando. Sin
    //    redondear la base: el redondeo va en la retención, no en la escala.
    //
    //    Se resta la TSS REALMENTE retenida, no un 5.91% teórico: con los topes
    //    encendidos un sueldo alto cotiza menos, y si aquí se siguiera restando
    //    el porcentaje entero se le calcularía de menos el ISR.
    $mensualEquivalente = $factor > 0 ? $base / $factor : $base;
    $tssMensual = $factor > 0 ? ($afp + $sfs) / $factor : ($afp + $sfs);
    $anual = ($mensualEquivalente - $tssMensual) * 12;

    if     ($anual <= 416220.00) $isrAnual = 0;
    elseif ($anual <= 624329.00) $isrAnual = ($anual - 416220.00) * 0.15;
    elseif ($anual <= 867123.00) $isrAnual = 31216.00 + ($anual - 624329.00) * 0.20;
    else                         $isrAnual = 79776.00 + ($anual - 867123.00) * 0.25;
    $isr = round($isrAnual / 12 * $factor, 2);

    // 4) Retenciones (columna S) y neto (columna U, ya corregido).
    $perCapita = $n('per_capita');
    $prestamo  = $n('otras_deducciones');
    $prima     = $n('prima_vacacional');

    $totalRetenciones = round($afp + $sfs + $isr + $perCapita, 2);
    $neto = round($base - $totalRetenciones - $prestamo + $prima, 2);

    return [
        'salarioPeriodo'    => $salarioPeriodo,
        'base'              => $base,          // columna N
        'afp'               => $afp,
        'sfs'               => $sfs,
        'isr'               => $isr,
        'perCapita'         => $perCapita,
        'prestamo'          => $prestamo,
        'prima'             => $prima,
        'totalIngresos'     => $base,          // se guarda en total_ingresos
        'totalDeducciones'  => $totalRetenciones,
        'neto'              => $neto,
    ];
}

/** Días que son una jornada completa según el período. Convenio del cliente. */
function nominaDiasBase(string $tipo): float
{
    return $tipo === 'mensual' ? 23.82 : ($tipo === 'quincenal' ? 11.91 : 5.5);
}

/* ============================================================
 *  LO QUE CUESTA UN EMPLEADO
 * ============================================================ */

/**
 * Aportes que paga LA EMPRESA sobre el salario cotizable, en tanto por uno.
 *
 * Ojo con la confusión más común de la nómina dominicana: lo que se le retiene
 * al empleado (AFP 2.87% y SFS 3.04%) NO es lo que le cuesta a la empresa. La
 * empresa aporta aparte, y esa parte es más del doble.
 *
 * Son los porcentajes generales de la Ley 87-01 y del INFOTEP. El de riesgos
 * laborales lleva un componente variable según la siniestralidad de la empresa;
 * aquí va el mínimo.
 *
 * @see COSTO_PENDIENTE_CONFIRMAR
 */
const COSTO_EMPLEADOR = [
    'afp'      => 0.0710,   // pensiones
    'sfs'      => 0.0709,   // salud familiar
    'riesgos'  => 0.0110,   // riesgos laborales, mínimo
    'infotep'  => 0.0100,   // formación técnico profesional
];

/**
 * Provisión mensual de la regalía pascual: un doceavo del salario ordinario.
 * No es un aporte a la TSS, pero se paga cada diciembre y quien mira el costo
 * de un empleado necesita verlo, o el número sale corto un 8%.
 */
const COSTO_REGALIA = 1 / 12;

/**
 * Lo que la empresa NO ha confirmado todavía y por tanto no se aplica solo.
 *
 * 1. Los TOPES de cotización de la TSS —SFS y AFP dejan de cotizar por encima
 *    de cierto múltiplo del salario mínimo—. Sin ellos, un sueldo alto sale con
 *    un costo mayor del real. Es la misma duda que arrastra la hoja del cliente.
 * 2. La provisión de cesantía, que depende de la antigüedad y solo se paga si
 *    hay desahucio.
 */
const COSTO_PENDIENTE_CONFIRMAR = ['topes de cotización TSS', 'provisión de cesantía'];

/**
 * Desglosa lo que le cuesta a la empresa un salario mensual.
 *
 * Función pura y sin base de datos: se puede probar sola y se usa igual en la
 * ficha del empleado que en un informe.
 *
 * @param float $salarioMensual Salario ordinario del mes.
 * @param bool  $conRegalia     Incluir la provisión de la regalía pascual.
 */
function costoEmpleadorRD(float $salarioMensual, bool $conRegalia = true, array|bool|null $tss = null): array
{
    $base = max(0.0, $salarioMensual);

    // Con los topes encendidos, el aporte patronal se calcula sobre la base
    // TOPADA de cada régimen. Aquí es donde más se nota: el tope de riesgos
    // laborales son 4 salarios mínimos, mucho más bajo que el de salud o
    // pensiones, así que un sueldo alto lo pasa de largo.
    if ($tss !== false && function_exists('tssAportes')) {
        $ap = tssAportes($base, 1.0, is_array($tss) ? $tss : null);
        $partes = [
            'afp'     => $ap['empleador']['afp'],
            'sfs'     => $ap['empleador']['sfs'],
            'riesgos' => $ap['empleador']['srl'],
            'infotep' => $ap['empleador']['infotep'],
        ];
    } else {
        $partes = [];
        foreach (COSTO_EMPLEADOR as $k => $tasa) {
            $partes[$k] = round($base * $tasa, 2);
        }
    }
    $aportes = round(array_sum($partes), 2);
    $regalia = $conRegalia ? round($base * COSTO_REGALIA, 2) : 0.0;
    $total   = round($base + $aportes + $regalia, 2);

    return [
        'salario'   => round($base, 2),
        'partes'    => $partes,
        'aportes'   => $aportes,
        'regalia'   => $regalia,
        'total'     => $total,
        // Cuánto se paga de más por encima del salario, en porcentaje.
        'recargo'   => $base > 0 ? round(($total - $base) / $base * 100, 2) : 0.0,
        'anual'     => round($total * 12, 2),
    ];
}

/**
 * Vuelve a sumar los totales de la nómina desde sus líneas.
 *
 * Hace falta cada vez que se añade o se quita una línea. Se suma desde la base
 * y no se ajusta el total sumando o restando la línea tocada: si alguna vez los
 * totales quedaron descuadrados por otra vía, esto los deja bien en lugar de
 * arrastrar el error.
 */
function nominaRecalcularTotales(int $nominaId): void
{
    $t = qOne(
        "SELECT COALESCE(SUM(total_ingresos), 0)    AS bruto,
                COALESCE(SUM(total_deducciones), 0) AS deducciones,
                COALESCE(SUM(salario_neto), 0)      AS neto
           FROM nomina_detalles WHERE nomina_id = ?",
        [$nominaId]
    ) ?: ['bruto' => 0, 'deducciones' => 0, 'neto' => 0];

    dbUpdate('nominas', [
        'total_bruto'       => round((float) $t['bruto'], 2),
        'total_deducciones' => round((float) $t['deducciones'], 2),
        'total_neto'        => round((float) $t['neto'], 2),
    ], 'id = ?', [$nominaId]);
}


/* ============================================================
 *  EXPORTABLES
 * ============================================================ */

/**
 * Las 23 columnas de la hoja del cliente, en su orden y con sus dos niveles de
 * encabezado. La fila 1 del Excel lleva agrupadores sobre varias columnas y la
 * fila 2 los títulos; se reproduce igual para que el contador reconozca su
 * documento y no tenga que reordenar nada.
 *
 * @return array [letra => ['grupo', 'titulo', 'campo']]
 */
function nominaColumnasExcel(): array
{
    return [
        'A' => ['', 'No.',                                  '_no'],
        'B' => ['', 'Nombres y Apellido',                   '_nombre'],
        'C' => ['', 'No. de Documento',                     'cedula'],
        'D' => ['', 'Sueldo Mensual',                       '_sueldo_mensual'],
        'E' => ['', 'Sueldo Quincenal',                     'salario_base'],
        'F' => ['Feriado/ Horas extras', 'Dias pagados',    'dias_trabajados'],
        'G' => ['', 'Prima Vacacional',                     'prima_vacacional'],
        'H' => ['', 'Total feriado/horas ext.',             'monto_horas_extra'],
        'I' => ['Otros Ingresos', 'Otras Remuneraciones',   '_otras_remuneraciones'],
        'J' => ['', 'Reembolso al empleado',                'reembolso'],
        'K' => ['', 'Vacaciones diferencial',               'vacaciones_diferencial'],
        'L' => ['', 'Incentivos',                           'bonificaciones'],
        'M' => ['Retenciones y Descuentos', 'Descuentos de días', 'descuento_dias'],
        'N' => ['', 'Ingresos cotizable seguridad social',  'total_ingresos'],
        'O' => ['', 'AFP 2.87%',                            'afp'],
        'P' => ['', 'SFS 3.04%',                            'sfs'],
        'Q' => ['', 'Per- Capital Quincenal',               'per_capita'],
        'R' => ['', 'ISR Quincenal',                        'isr'],
        'S' => ['', 'Total Retenciones Quincenal',          'total_deducciones'],
        'T' => ['', 'Cuentas por cobrar empleados',         'otras_deducciones'],
        'U' => ['', 'Total neto a depositar',               'salario_neto'],
        'V' => ['', 'NO. DE CUENTAS',                       'cuenta_bancaria'],
        'W' => ['', 'BANCOS',                               'banco'],
    ];
}

/**
 * Las líneas de una nómina agrupadas por sucursal, como en la hoja del cliente:
 * un encabezado en la columna A y debajo su gente.
 *
 * El Excel agrupa con una sola columna donde mezcla locales y áreas; aquí se usa
 * la sucursal, que es «dónde trabaja». Ver la nota Nomina del vault.
 */
function nominaLineasAgrupadas(int $nominaId): array
{
    return qAll(
        "SELECT nd.*, e.nombre, e.apellido, e.cedula, e.salario AS _sueldo_mensual,
                e.cuenta_bancaria, e.banco,
                /* La CEO lo dijo en una frase: «no hay oficina, la distribución
                   administrativa es por departamento». Una tienda SÍ es un grupo
                   real —L'Occitane Punta Cana vende y factura— pero «Oficina Santo
                   Domingo» no es un centro de costo, es un edificio. Para la gente
                   de oficina el grupo es su DEPARTAMENTO.

                   Se distingue por dato, no por nombre ni por id: una tienda tiene
                   marca (tienda_id) y una oficina no. */
                COALESCE(
                    CASE WHEN s.tienda_id IS NULL THEN dep.nombre ELSE s.nombre END,
                    s.nombre, dep.nombre, 'SIN UBICACIÓN'
                ) AS grupo
           FROM nomina_detalles nd
           JOIN empleados e   ON e.id = nd.empleado_id
           LEFT JOIN sucursales s   ON s.id = e.sucursal_id
           LEFT JOIN departamentos dep ON dep.id = e.departamento_id
          WHERE nd.nomina_id = ?
          ORDER BY grupo, e.nombre, e.apellido",
        [$nominaId]
    );
}

/**
 * Exporta la nómina con el formato EXACTO de la hoja del cliente.
 *
 * Termina la ejecución: descarga el archivo.
 */
function nominaExportarExcel(array $nomina, array $lineas): void
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('Falta PhpSpreadsheet para exportar la nómina.');
    }
    while (ob_get_level() > 0) ob_end_clean();

    $cols = nominaColumnasExcel();
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $nomina['descripcion']), 0, 31) ?: 'Nomina');

    // --- Encabezados en dos niveles, como el original ---
    foreach ($cols as $L => [$grupo, $titulo, $_]) {
        if ($grupo !== '') $sh->setCellValue($L . '1', $grupo);
        $sh->setCellValue($L . '2', $titulo);
    }
    $sh->getStyle('A1:W2')->getFont()->setBold(true);
    $sh->getStyle('A2:W2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
       ->getStartColor()->setRGB('D9E1F2');
    $sh->getStyle('A1:W2')->getAlignment()->setWrapText(true)->setVertical('center');

    // --- Filas, agrupadas por sucursal ---
    $r = 3;
    $grupoActual = null;
    $no = 0;
    $sumas = array_fill_keys(array_keys($cols), 0.0);
    $numericas = ['D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U'];

    foreach ($lineas as $l) {
        if ($l['grupo'] !== $grupoActual) {
            $grupoActual = $l['grupo'];
            $sh->setCellValue('A' . $r, mb_strtoupper($grupoActual));
            $sh->getStyle('A' . $r . ':W' . $r)->getFont()->setBold(true);
            $sh->getStyle('A' . $r . ':W' . $r)->getFill()
               ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
               ->getStartColor()->setRGB('EDF0F4');
            $r++;
        }
        $no++;
        // Otras remuneraciones (col. I) = otros ingresos + comisiones. El Excel
        // no tiene columna de comisión; se suman aquí para no inventar una
        // columna que rompería el formato que el contador conoce.
        $l['_no'] = $no;
        $l['_nombre'] = trim($l['nombre'] . ' ' . $l['apellido']);
        $l['_otras_remuneraciones'] = (float) $l['otros_ingresos'] + (float) $l['comisiones'];

        foreach ($cols as $L => [$_, $__, $campo]) {
            $v = $l[$campo] ?? '';
            if (in_array($L, $numericas, true)) {
                $v = (float) $v;
                $sumas[$L] += $v;
                if ($L !== 'F') $sh->getStyle($L . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                $sh->setCellValue($L . $r, $v);
            } else {
                // Cédula y cuenta como TEXTO: si van como número pierden el cero
                // inicial, que es justo lo que le pasó al Excel del cliente en
                // dos cuentas. Ver la nota Nomina del vault.
                $sh->setCellValueExplicit($L . $r, (string) $v,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $r++;
    }

    // --- Totales ---
    $sh->setCellValue('A' . $r, 'TOTALES');
    foreach ($numericas as $L) {
        if ($L === 'F') continue;           // los días no se suman
        // Redondeado al escribir: sumar decimales arrastra ruido binario
        // (26847.040000000008) y aunque el formato lo oculte, el valor guardado
        // es el que se copia a otra celda o se exporta.
        $sh->setCellValue($L . $r, round($sumas[$L], 2));
        $sh->getStyle($L . $r)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    $sh->getStyle('A' . $r . ':W' . $r)->getFont()->setBold(true);
    $sh->getStyle('A' . $r . ':W' . $r)->getBorders()->getTop()->setBorderStyle('thin');

    foreach (array_keys($cols) as $L) $sh->getColumnDimension($L)->setAutoSize(true);
    $sh->getRowDimension(2)->setRowHeight(34);
    $sh->freezePane('C3');

    $archivo = 'nomina_' . preg_replace('/[^A-Za-z0-9]+/', '_', $nomina['descripcion']) . '_' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

/**
 * Archivo para subir al banco.
 *
 * Es lo que de verdad ejecuta la nómina: sin esto, alguien tiene que teclear 53
 * transferencias a mano. Va en CSV porque es lo que aceptan los portales de
 * banca empresarial dominicanos, y con las cuentas y cédulas como texto.
 *
 * Solo entra quien cobra por transferencia. A quien no tiene cuenta se le paga
 * en efectivo y **se avisa en pantalla**: dejarlo fuera en silencio es la forma
 * más fácil de que alguien no cobre.
 *
 * Termina la ejecución: descarga el archivo.
 */
function nominaExportarBanco(array $nomina, array $lineas): void
{
    while (ob_get_level() > 0) ob_end_clean();

    $filas = [];
    $sinCuenta = [];
    $sospechosas = [];
    $total = 0.0;

    foreach ($lineas as $l) {
        $cuenta = preg_replace('/\D+/', '', (string) ($l['cuenta_bancaria'] ?? ''));
        $nombre = trim($l['nombre'] . ' ' . $l['apellido']);
        $monto  = round((float) $l['salario_neto'], 2);

        if ($cuenta === '' || $monto <= 0) { $sinCuenta[] = $nombre; continue; }

        // Dos cuentas del padrón perdieron el cero inicial DENTRO del propio
        // Excel del cliente, por venir guardadas como número. Se marcan para que
        // alguien las revise antes de mandar la transferencia a la nada.
        if (strlen($cuenta) !== 11) $sospechosas[] = $nombre . ' (' . strlen($cuenta) . ' dígitos)';

        $filas[] = [$cuenta, $nombre, number_format($monto, 2, '.', ''), $l['cedula'], $l['banco'] ?: 'BHD'];
        $total += $monto;
    }

    $archivo = 'pago_banco_' . preg_replace('/[^A-Za-z0-9]+/', '_', $nomina['descripcion']) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Cuenta', 'Beneficiario', 'Monto', 'Documento', 'Banco']);
    foreach ($filas as $f) fputcsv($out, $f);

    // El pie no es adorno: es lo que se coteja contra el portal del banco antes
    // de autorizar, y donde se ve a quién hay que pagarle aparte.
    fputcsv($out, []);
    fputcsv($out, ['TOTAL', count($filas) . ' transferencias', number_format($total, 2, '.', '')]);
    if ($sospechosas) {
        fputcsv($out, []);
        fputcsv($out, ['REVISAR: la cuenta no tiene 11 dígitos', implode(' · ', $sospechosas)]);
    }
    if ($sinCuenta) {
        fputcsv($out, []);
        fputcsv($out, ['FUERA DEL ARCHIVO: sin cuenta, se pagan aparte', implode(' · ', $sinCuenta)]);
    }
    fclose($out);
    exit;
}
