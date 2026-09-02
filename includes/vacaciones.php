<?php
/**
 * Vacaciones: el derecho del art. 177 y lo que queda de él.
 *
 * Hasta ahora la pantalla de vacaciones apuntaba solicitudes y nada más: nadie
 * sabía cuántos días le tocaban a cada quien ni cuántos llevaba tomados. Dos
 * consecuencias, las dos con dinero dentro:
 *
 *   · Los días se contaban de CALENDARIO. El art. 177 concede días laborables,
 *     así que unas vacaciones de dos semanas se apuntaban como 14 días cuando
 *     legalmente eran 12. Dos días de más consumidos, cada vez.
 *
 *   · La liquidación pagaba las vacaciones proporcionales del año de servicio
 *     en curso sin restar las ya disfrutadas EN ESE MISMO AÑO. Se pagaban dos
 *     veces: una al tomarlas (son con disfrute de salario) y otra al liquidar.
 *
 * ---------------------------------------------------------------------------
 * CRITERIO QUE HAY QUE CONFIRMAR CON EL ABOGADO LABORAL
 *
 * «Días laborables» aquí excluye los DOMINGOS, que es el descanso semanal del
 * art. 163. NO se descuentan los feriados nacionales porque el sistema no tiene
 * calendario de feriados; cuando unas vacaciones caigan sobre uno, hay que
 * bajar el número a mano. La pantalla lo dice donde se teclea, para que quien
 * lo aprueba lo sepa en el momento y no lo descubra en la liquidación.
 * ---------------------------------------------------------------------------
 */

/**
 * Días laborables entre dos fechas, ambas incluidas, sin contar domingos.
 */
function vac_dias_laborables(string $desde, string $hasta): int
{
    $ini = strtotime($desde);
    $fin = strtotime($hasta);
    if ($ini === false || $fin === false || $fin < $ini) return 0;

    $n = 0;
    for ($t = $ini; $t <= $fin; $t += 86400) {
        if ((int) date('N', $t) !== 7) $n++;   // 7 = domingo
    }
    return $n;
}

/**
 * Meses completos de servicio entre dos fechas.
 *
 * De calendario, no dividiendo por una media de 30,44 días: entre enero y
 * septiembre hay cinco meses de 31 días, así que la media se queda corta y
 * decía «7 meses» a quien llevaba 8. Un mes de menos en la escala del primer
 * año es un día de vacaciones de menos.
 */
function vac_meses_servicio(string $ingreso, ?string $ref = null): int
{
    $ref = $ref ?: date('Y-m-d');
    $ini = strtotime($ingreso);
    $fin = strtotime($ref);
    if ($ini === false || $fin === false || $fin < $ini) return 0;

    $m = ((int) date('Y', $fin) - (int) date('Y', $ini)) * 12
       + ((int) date('n', $fin) - (int) date('n', $ini));
    // Si aún no ha llegado el día del mes, el mes no está cumplido.
    if ((int) date('j', $fin) < (int) date('j', $ini)) $m--;
    return max(0, $m);
}

/**
 * Derecho anual de vacaciones del art. 177, en días laborables.
 *
 * El primer año tiene su propia escala en el párrafo del artículo, mes a mes;
 * no es una regla de tres. A partir del año son 14 días, y desde los cinco, 18.
 */
function vac_derecho_anual(string $ingreso, ?string $ref = null): array
{
    $ref = $ref ?: date('Y-m-d');
    $ini = strtotime($ingreso);
    $fin = strtotime($ref);
    if ($ini === false || $fin === false || $fin < $ini) {
        return ['dias' => 0, 'regla' => 'Fecha de ingreso no válida'];
    }

    $meses = vac_meses_servicio($ingreso, $ref);

    if ($meses >= 60) return ['dias' => 18, 'regla' => 'Cinco años o más de servicio: 18 días (art. 177)'];
    if ($meses >= 12) return ['dias' => 14, 'regla' => 'De uno a cinco años de servicio: 14 días (art. 177)'];

    // Párrafo del art. 177: escala del primer año, de cinco meses en adelante.
    $escala = [5 => 6, 6 => 7, 7 => 8, 8 => 9, 9 => 10, 10 => 11, 11 => 12];
    if ($meses < 5) {
        return ['dias' => 0, 'regla' => 'Menos de cinco meses de servicio: todavía no genera vacaciones'];
    }
    $d = $escala[min(11, $meses)] ?? 12;
    return ['dias' => $d, 'regla' => sprintf('%d meses de servicio: %d días (escala del primer año, art. 177)', $meses, $d)];
}

/**
 * El año de servicio en curso: desde el último aniversario de ingreso.
 *
 * El derecho se cuenta por año de SERVICIO, no por año natural. Mezclarlos hace
 * que quien entró en septiembre parezca no tener vacaciones cada enero.
 */
function vac_anio_servicio(string $ingreso, ?string $ref = null): array
{
    $ref = $ref ?: date('Y-m-d');
    $ini = strtotime($ingreso);
    $fin = strtotime($ref);
    if ($ini === false || $fin === false || $fin < $ini) {
        return ['desde' => $ingreso, 'hasta' => $ref, 'anios' => 0];
    }
    $anios = intdiv(vac_meses_servicio($ingreso, $ref), 12);
    $desde = date('Y-m-d', strtotime($ingreso . ' +' . $anios . ' years'));
    $hasta = date('Y-m-d', strtotime($ingreso . ' +' . ($anios + 1) . ' years -1 day'));
    return ['desde' => $desde, 'hasta' => $hasta, 'anios' => $anios];
}

/**
 * Días de vacaciones ya disfrutados dentro de una ventana.
 *
 * Cuenta lo aprobado y lo disfrutado; lo solicitado todavía no consume nada
 * porque puede rechazarse. Solo `tipo = 'vacaciones'`: una licencia médica no
 * sale del derecho del art. 177.
 */
function vac_disfrutadas(int $empleadoId, string $desde, string $hasta): float
{
    return (float) qVal(
        "SELECT COALESCE(SUM(COALESCE(dias_laborables, dias)), 0)
           FROM vacaciones
          WHERE empleado_id = ? AND tipo = 'vacaciones'
            AND estado IN ('aprobada','disfrutada')
            AND fecha_desde BETWEEN ? AND ?",
        [$empleadoId, $desde, $hasta]
    );
}

/**
 * El saldo completo de una persona: a cuánto tiene derecho, cuánto lleva
 * tomado y cuánto le queda, todo en días laborables.
 */
function vac_balance(array $emp, ?string $ref = null): array
{
    $ref     = $ref ?: date('Y-m-d');
    $ingreso = (string) ($emp['fecha_ingreso'] ?? '');
    if ($ingreso === '' || $ingreso === '0000-00-00') {
        return ['derecho' => 0, 'disfrutadas' => 0.0, 'saldo' => 0.0,
                'desde' => null, 'hasta' => null, 'anios' => 0,
                'regla' => 'Sin fecha de ingreso no se puede calcular el derecho'];
    }

    $anio    = vac_anio_servicio($ingreso, $ref);
    $derecho = vac_derecho_anual($ingreso, $ref);
    $usadas  = vac_disfrutadas((int) $emp['id'], $anio['desde'], $anio['hasta']);

    return [
        'derecho'     => (int) $derecho['dias'],
        'disfrutadas' => round($usadas, 2),
        'saldo'       => round($derecho['dias'] - $usadas, 2),
        'desde'       => $anio['desde'],
        'hasta'       => $anio['hasta'],
        'anios'       => $anio['anios'],
        'regla'       => $derecho['regla'],
    ];
}
