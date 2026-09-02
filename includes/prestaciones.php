<?php
/**
 * Prestaciones laborales — lo que se le paga a quien sale (Código de Trabajo).
 *
 * ============================================================================
 *  ESTO NO FIRMA NADA POR NADIE
 * ============================================================================
 *
 * Una liquidación se negocia y se firma; el sistema no la decide. Lo que hace
 * este módulo es calcular la cifra que sale de aplicar la ley a los datos que
 * ya están en base —fecha de ingreso, sueldo, nóminas del año— para que nadie
 * la saque a mano en una hoja suelta, y dejar constancia de qué escala se
 * aplicó. **Cada renglón se puede corregir antes de firmar.**
 *
 * ============================================================================
 *  LAS ESCALAS
 * ============================================================================
 *
 * PREAVISO (art. 76) — días de salario según la antigüedad:
 *      menos de 3 meses ......  0
 *      de 3 a 6 meses ........  7
 *      de 6 a 12 meses .......  14
 *      1 año o más ...........  28
 *
 * AUXILIO DE CESANTÍA (art. 80) — días POR CADA AÑO de servicio:
 *      menos de 3 meses ......  0
 *      de 3 a 6 meses ........  6   (fijos, no por año)
 *      de 6 a 12 meses .......  13  (fijos)
 *      de 1 a 5 años .........  21  por año
 *      más de 5 años .........  23  por año
 *
 *   La fracción de año se paga proporcional, que es como lo calcula el
 *   Ministerio de Trabajo. Y el tramo se aplica a TODOS los años, no en
 *   escalones: siete años son 23 × 7, no 21 × 5 + 23 × 2. Es la lectura literal
 *   del art. 80 numeral 5 y la del calculador oficial; **si el abogado laboral
 *   del cliente usa otra, el número se corrige en pantalla.**
 *
 * VACACIONES (art. 177) — días laborables al año:
 *      de 1 a 5 años .........  14
 *      5 años o más ..........  18
 *   Proporcional a la parte del año de servicio que se lleva corrida.
 *
 * REGALÍA PROPORCIONAL (art. 219) — una duodécima del salario ordinario del año
 *   hasta la fecha de salida. Sale de includes/regalia.php, el mismo cálculo que
 *   la regalía de diciembre.
 *
 * ============================================================================
 *  QUÉ SE PAGA SEGÚN LA CAUSA
 * ============================================================================
 *
 * Preaviso y cesantía solo se pagan cuando la salida la provoca la empresa o
 * cuando la justicia la equipara a eso. Vacaciones, regalía y salario pendiente
 * se pagan SIEMPRE, se vaya como se vaya. Ver plab_causas().
 *
 * ============================================================================
 *  IMPUESTOS
 * ============================================================================
 *
 * Preaviso y cesantía son indemnizaciones: no cotizan a la TSS. La regalía está
 * exenta de ISR por el art. 222. Sobre el resto decide el contador, y por eso
 * hay un renglón de deducciones abierto en vez de una retención automática:
 * calcular un ISR que quizá no aplica sería peor que no calcularlo.
 */

/**
 * Divisor legal para pasar de sueldo mensual a salario diario.
 *
 * 23.83 es el número que usa el Ministerio de Trabajo (365 días ÷ 12 meses,
 * descontando los domingos: 23.83 días laborables promedio al mes).
 */
const PLAB_DIVISOR_DIARIO = 23.83;

/** Causas de terminación y qué arrastra cada una. */
function plab_causas(): array
{
    return [
        'desahucio_empleador' => [
            'label' => 'Desahucio ejercido por el empleador',
            'ayuda' => 'La empresa termina el contrato sin alegar falta. Paga preaviso y cesantía.',
            'preaviso' => true, 'cesantia' => true],
        'despido_injustificado' => [
            'label' => 'Despido declarado injustificado',
            'ayuda' => 'Se alegó una falta y no se sostuvo. Se paga como un desahucio.',
            'preaviso' => true, 'cesantia' => true],
        'dimision_justificada' => [
            'label' => 'Dimisión justificada del trabajador',
            'ayuda' => 'Se va por una falta de la empresa. Le corresponde lo mismo que a un desahucio.',
            'preaviso' => true, 'cesantia' => true],
        'desahucio_trabajador' => [
            'label' => 'Renuncia (desahucio del trabajador)',
            'ayuda' => 'Se va por su voluntad. No hay preaviso a su favor ni cesantía.',
            'preaviso' => false, 'cesantia' => false],
        'despido_justificado' => [
            'label' => 'Despido justificado',
            'ayuda' => 'Falta comprobada del trabajador. No hay preaviso ni cesantía.',
            'preaviso' => false, 'cesantia' => false],
        'dimision_injustificada' => [
            'label' => 'Dimisión injustificada',
            'ayuda' => 'Se fue alegando una falta que no se sostuvo. No hay preaviso ni cesantía.',
            'preaviso' => false, 'cesantia' => false],
        'mutuo_acuerdo' => [
            'label' => 'Mutuo acuerdo',
            'ayuda' => 'Lo que se pague por encima de vacaciones y regalía es lo que se acuerde.',
            'preaviso' => false, 'cesantia' => false],
        'vencimiento_contrato' => [
            'label' => 'Vencimiento del contrato o de la obra',
            'ayuda' => 'Termina lo pactado. No hay preaviso ni cesantía.',
            'preaviso' => false, 'cesantia' => false],
    ];
}

function plab_estados(): array
{
    return ['borrador' => 'Borrador', 'firmada' => 'Firmada', 'pagada' => 'Pagada', 'anulada' => 'Anulada'];
}

/** ¿Está aplicada la migración? */
function plab_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { qVal("SELECT 1 FROM prestaciones LIMIT 1"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

/** Días de servicio entre dos fechas, ambas inclusive. */
function plab_dias_servicio(string $ingreso, string $salida): int
{
    if ($ingreso === '' || $salida === '' || $salida < $ingreso) return 0;
    return (int) floor((strtotime($salida) - strtotime($ingreso)) / 86400) + 1;
}

/** Días de preaviso que corresponden (art. 76). */
function plab_dias_preaviso(int $diasServicio): float
{
    if ($diasServicio < 90)  return 0;      // menos de 3 meses
    if ($diasServicio < 182) return 7;      // 3 a 6 meses
    if ($diasServicio < 365) return 14;     // 6 a 12 meses
    return 28;                              // 1 año o más
}

/**
 * Días de auxilio de cesantía (art. 80).
 *
 * Devuelve además la tasa aplicada, para poder decir en pantalla de dónde
 * salieron los días en vez de soltar un número.
 */
function plab_dias_cesantia(int $diasServicio): array
{
    if ($diasServicio < 90)  return ['dias' => 0.0,  'tasa' => 0,  'regla' => 'Menos de 3 meses: no corresponde'];
    if ($diasServicio < 182) return ['dias' => 6.0,  'tasa' => 0,  'regla' => 'De 3 a 6 meses: 6 días'];
    if ($diasServicio < 365) return ['dias' => 13.0, 'tasa' => 0,  'regla' => 'De 6 a 12 meses: 13 días'];

    $anios = $diasServicio / 365;
    $tasa  = $anios > 5 ? 23 : 21;
    return [
        'dias'  => round($anios * $tasa, 2),
        'tasa'  => $tasa,
        'anios' => round($anios, 2),
        'regla' => sprintf('%s: %d días por año × %s año(s)',
            $anios > 5 ? 'Más de 5 años' : 'De 1 a 5 años', $tasa, number_format($anios, 2)),
    ];
}

/**
 * Días de vacaciones que quedan por pagar (art. 177).
 *
 * Se paga lo proporcional al año de servicio EN CURSO. Lo de años anteriores no
 * disfrutado no sale de aquí —el sistema no sabe qué se disfrutó de verdad si
 * no se registró— y se añade a mano en la pantalla.
 */
function plab_dias_vacaciones(string $ingreso, string $salida): array
{
    $dias = plab_dias_servicio($ingreso, $salida);
    if ($dias < 152) {   // menos de 5 meses: el art. 177 no genera derecho todavía
        return ['dias' => 0.0, 'derecho' => 0, 'meses' => 0,
                'regla' => 'Menos de 5 meses de servicio: todavía no genera vacaciones'];
    }
    $anios   = $dias / 365;
    $derecho = $anios >= 5 ? 18 : 14;

    // Fracción del año de servicio en curso: desde el último aniversario.
    $aniosEnteros = (int) floor($anios);
    $aniversario  = date('Y-m-d', strtotime($ingreso . ' +' . $aniosEnteros . ' years'));
    $diasCorridos = plab_dias_servicio($aniversario, $salida);
    $meses = min(12, round($diasCorridos / (365 / 12), 2));

    return [
        'dias'    => round($derecho * $meses / 12, 2),
        'derecho' => $derecho,
        'meses'   => $meses,
        'regla'   => sprintf('%d días al año (%s de servicio) × %s mes(es) corridos ÷ 12',
            $derecho, $anios >= 5 ? '5 años o más' : 'de 1 a 5 años', number_format($meses, 2)),
    ];
}

/**
 * El cálculo completo de una liquidación.
 *
 * No escribe nada: devuelve los renglones para que la pantalla los enseñe y los
 * deje corregir. Guardar es otra cosa.
 */
function plab_calcular(array $empleado, string $causa, string $fechaSalida): array
{
    $causas = plab_causas();
    $c = $causas[$causa] ?? $causas['desahucio_empleador'];

    $ingreso = (string) ($empleado['fecha_ingreso'] ?: '');
    $salario = (float) $empleado['salario'];
    $diario  = round($salario / PLAB_DIVISOR_DIARIO, 2);
    $dias    = plab_dias_servicio($ingreso, $fechaSalida);

    $pre = plab_dias_preaviso($dias);
    $ces = plab_dias_cesantia($dias);
    $vac = plab_dias_vacaciones($ingreso, $fechaSalida);

    // Lo que la persona YA se tomó en este mismo año de servicio no se le paga
    // otra vez: las vacaciones se disfrutan con salario, así que ya se le
    // pagaron cuando las tomó. Sin este descuento la liquidación las abonaba
    // por segunda vez, y nadie lo notaba porque el renglón parece correcto.
    $vacUsadas = 0.0;
    $vacVentana = null;
    if ($ingreso !== '' && function_exists('vac_anio_servicio')) {
        $vacVentana = vac_anio_servicio($ingreso, $fechaSalida);
        $vacUsadas  = vac_disfrutadas((int) $empleado['id'], $vacVentana['desde'], $vacVentana['hasta']);
    }
    $vacPagar = max(0.0, round($vac['dias'] - $vacUsadas, 2));

    // Regalía proporcional: el mismo cálculo que la de diciembre, cortado en la
    // fecha de salida. Se pide con esa fecha como corte para no arrastrar días
    // que la persona ya no trabajó.
    $anio = (int) substr($fechaSalida, 0, 4);
    $pagos = [];
    foreach (regaliaPagosDelAnio($anio) as $p) {
        if ((int) $p['empleado_id'] === (int) $empleado['id']) $pagos[] = $p;
    }
    $reg = regaliaDeEmpleado(
        array_merge($empleado, ['fecha_salida' => $fechaSalida]),
        $anio, $fechaSalida, $pagos, true
    );

    $renglones = [
        'preaviso' => [
            'label'   => 'Preaviso (art. 76)',
            'aplica'  => $c['preaviso'],
            'dias'    => $c['preaviso'] ? $pre : 0.0,
            'monto'   => $c['preaviso'] ? round($pre * $diario, 2) : 0.0,
            'regla'   => $c['preaviso']
                ? ($pre > 0 ? number_format($pre, 0) . ' días de salario por la antigüedad'
                            : 'Menos de 3 meses: no corresponde')
                : 'No corresponde por la causa de la salida',
        ],
        'cesantia' => [
            'label'   => 'Auxilio de cesantía (art. 80)',
            'aplica'  => $c['cesantia'],
            'dias'    => $c['cesantia'] ? $ces['dias'] : 0.0,
            'monto'   => $c['cesantia'] ? round($ces['dias'] * $diario, 2) : 0.0,
            'regla'   => $c['cesantia'] ? $ces['regla'] : 'No corresponde por la causa de la salida',
        ],
        'vacaciones' => [
            'label'  => 'Vacaciones no disfrutadas (art. 177)',
            'aplica' => true,
            'dias'   => $vacPagar,
            'monto'  => round($vacPagar * $diario, 2),
            // Se enseña la resta entera, no solo el resultado: quien firma la
            // liquidación tiene que poder ver de dónde sale cada día.
            'regla'  => $vacUsadas > 0.0001
                ? $vac['regla'] . '. Menos ' . number_format($vacUsadas, 2)
                  . ' día(s) ya disfrutados desde el ' . fechaCorta($vacVentana['desde'])
                : $vac['regla'],
            'disfrutadas' => round($vacUsadas, 2),
            'generadas'   => $vac['dias'],
        ],
        'regalia' => [
            'label'  => 'Regalía pascual proporcional (art. 219)',
            'aplica' => true,
            'dias'   => null,
            'monto'  => $reg ? $reg['regalia'] : 0.0,
            'regla'  => $reg
                ? 'Una duodécima parte de ' . money($reg['devengado'], false)
                  . ' devengados del 1 de enero al ' . fechaCorta($fechaSalida)
                : 'Sin salario devengado en el año',
        ],
    ];

    $total = 0.0;
    foreach ($renglones as $r) $total += (float) $r['monto'];

    return [
        'causa'          => $causa,
        'causa_label'    => $c['label'],
        'causa_ayuda'    => $c['ayuda'],
        'fecha_ingreso'  => $ingreso,
        'fecha_salida'   => $fechaSalida,
        'dias_servicio'  => $dias,
        'anios_servicio' => round($dias / 365, 2),
        'salario'        => $salario,
        'diario'         => $diario,
        'renglones'      => $renglones,
        'total'          => round($total, 2),
        'regalia_detalle'=> $reg,
        'sin_ingreso'    => $ingreso === '',
    ];
}
