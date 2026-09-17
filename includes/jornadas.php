<?php
/**
 * Horarios, feriados y el día completo de una persona.
 *
 * El reloj dice a qué hora llegó alguien. Solo. Para saber si esa hora es
 * TARDE hace falta contra qué compararla, y eso es lo que faltaba: por eso
 * `asistencias.estado` tenía «tardanza» desde el primer día y nunca se pudo
 * rellenar.
 *
 * ---------------------------------------------------------------------------
 *  LA REGLA QUE MANDA SOBRE TODAS
 *
 *  Un día sin marcas NO se guarda como ausencia. Se deduce al mirarlo, con
 *  todo lo que el sistema sabe —horario, feriado, vacaciones aprobadas— y solo
 *  se llama «ausente» cuando se puede demostrar. Ver `jorEvaluarDia()`.
 *
 *  Quien no tiene jornada asignada NO recibe tardanza. Inventarle un horario
 *  de oficina a quien trabaja por turnos acaba en un descuento de nómina que
 *  nadie puede defender, y eso es peor que no decir nada.
 * ---------------------------------------------------------------------------
 */

/** Cuántas horas de trabajo hacen falta para dar por tomado el almuerzo. */
const JOR_ALMUERZO_DESDE_HORAS = 6.0;

/* ===========================================================================
 *  FERIADOS
 * ======================================================================== */

/**
 * Los feriados de República Dominicana de un año, ya trasladados.
 *
 * La Ley 139-97 mueve algunos al lunes: si caen martes o miércoles, al lunes
 * ANTERIOR; si caen jueves o viernes, al lunes SIGUIENTE. Sábado, domingo y
 * lunes se quedan donde están. Los demás son de fecha fija.
 *
 * Se calcula en vez de escribirse a mano porque una lista escrita caduca el 31
 * de diciembre, y el año siguiente el sistema daría por laborable el 27 de
 * febrero sin que nadie lo note hasta ver la nómina.
 *
 * OJO — pendiente de confirmar con el contador: aquí el 16 de agosto va como
 * TRASLADABLE, que es la lectura más extendida de la 139-97. Si en la empresa
 * se celebra en su fecha fija, se corrige en la pantalla de Feriados y esa
 * corrección sobrevive a regenerar el año (ver `jorSembrarFeriados()`).
 */
function jorFeriadosRD(int $anio): array
{
    // [mes, día, nombre, ¿se traslada al lunes?]
    $fijos = [
        [1,  1,  'Año Nuevo',                      false],
        [1,  6,  'Día de los Santos Reyes',        true ],
        [1,  21, 'Nuestra Señora de la Altagracia', false],
        [1,  26, 'Día de Duarte',                  true ],
        [2,  27, 'Día de la Independencia',        false],
        [5,  1,  'Día del Trabajo',                true ],
        [8,  16, 'Día de la Restauración',         true ],
        [9,  24, 'Nuestra Señora de las Mercedes', false],
        [11, 6,  'Día de la Constitución',         true ],
        [12, 25, 'Día de Navidad',                 false],
    ];

    $out = [];
    foreach ($fijos as [$m, $d, $nombre, $mueve]) {
        $ts  = mktime(12, 0, 0, $m, $d, $anio);
        $dow = (int) date('N', $ts);            // 1 = lunes … 7 = domingo
        if ($mueve) {
            if ($dow === 2 || $dow === 3)      $ts -= ($dow - 1) * 86400;      // al lunes anterior
            elseif ($dow === 4 || $dow === 5)  $ts += (8 - $dow) * 86400;      // al lunes siguiente
        }
        $out[date('Y-m-d', $ts)] = $nombre;
    }

    // Semana Santa: Viernes Santo es el domingo de Pascua menos dos días, y
    // Corpus Christi el jueves sesenta días después. Los dos son de fecha
    // móvil de verdad —dependen de la Pascua—, no del traslado al lunes.
    $pascua = easter_date($anio);
    $out[date('Y-m-d', $pascua - 2 * 86400)]  = 'Viernes Santo';
    $out[date('Y-m-d', $pascua + 60 * 86400)] = 'Corpus Christi';

    ksort($out);
    return $out;
}

/**
 * Deja en la tabla los feriados de un año.
 *
 * NO toca los que alguien añadió a mano (`automatico = 0`): un duelo nacional
 * o una jornada electoral los declara el Gobierno sobre la marcha y no salen
 * de ninguna ley. Borrarlos al regenerar el año sería perder lo único que no
 * se puede recalcular.
 */
function jorSembrarFeriados(int $anio): array
{
    $r = ['creados' => 0, 'ya_estaban' => 0, 'respetados_manual' => 0];

    foreach (jorFeriadosRD($anio) as $fecha => $nombre) {
        $ya = qOne("SELECT id, automatico FROM feriados WHERE fecha = ?", [$fecha]);
        if ($ya) {
            if (!(int) $ya['automatico']) { $r['respetados_manual']++; continue; }
            $r['ya_estaban']++;
            continue;
        }
        dbInsert('feriados', ['fecha' => $fecha, 'nombre' => $nombre, 'automatico' => 1, 'pagado' => 1]);
        $r['creados']++;
    }

    // Lo que acaba de entrar tiene que verse YA: sembrar y recalcular se hacen
    // seguido, en la misma petición.
    jorFeriado('', true);
    return $r;
}

/**
 * El feriado de una fecha, o null. Cachea el año entero de una vez.
 *
 * `$olvidar` tira la caché, y hace falta de verdad: sembrar el año y recalcular
 * la asistencia ocurren en la MISMA petición. Sin tirarla, el recálculo seguía
 * viendo el año vacío de antes de sembrarlo y daba por ausente a toda la
 * plantilla el 27 de febrero. Sin error, sin aviso: el número simplemente
 * salía mal.
 */
function jorFeriado(string $fecha, bool $olvidar = false): ?array
{
    static $cache = [];
    if ($olvidar) { $cache = []; if ($fecha === '') return null; }
    $anio = substr($fecha, 0, 4);
    if (!isset($cache[$anio])) {
        $cache[$anio] = [];
        foreach (qAll("SELECT fecha, nombre, pagado FROM feriados
                        WHERE fecha BETWEEN ? AND ?", ["$anio-01-01", "$anio-12-31"]) as $f) {
            $cache[$anio][$f['fecha']] = $f;
        }
    }
    return $cache[$anio][$fecha] ?? null;
}

/* ===========================================================================
 *  LA JORNADA
 * ======================================================================== */

/**
 * La jornada de una persona con sus siete días, o null si no tiene asignada.
 *
 * Se cachea DENTRO de la pasada. No entre pasadas: guardar el horario de
 * alguien entre dos ejecuciones haría que un cambio de jornada no se notara
 * hasta reiniciar, y el síntoma —«a este le sigue contando tarde»— no apunta
 * a la causa por ningún lado.
 */
function jorDeEmpleado(int $empleadoId, bool $olvidar = false): ?array
{
    static $cache = [];
    if ($olvidar) { $cache = []; return null; }
    if (array_key_exists($empleadoId, $cache)) return $cache[$empleadoId];

    $j = qOne("SELECT j.* FROM jornadas j
                 JOIN empleados e ON e.jornada_id = j.id
                WHERE e.id = ? AND j.activo = 1", [$empleadoId]);
    if (!$j) return $cache[$empleadoId] = null;

    $j['dias'] = [];
    foreach (qAll("SELECT dia_semana, labora, hora_entrada, hora_salida
                     FROM jornada_dias WHERE jornada_id = ?", [(int) $j['id']]) as $d) {
        $j['dias'][(int) $d['dia_semana']] = $d;
    }
    return $cache[$empleadoId] = $j;
}

/**
 * Qué toca ese día concreto según la jornada.
 *
 * Devuelve null cuando no hay jornada. Un día SIN fila en `jornada_dias` se
 * trata como NO laborable: es más seguro callar que afirmar que alguien debía
 * venir un día del que nadie dijo nada.
 */
function jorDelDia(?array $jornada, string $fecha): ?array
{
    if (!$jornada) return null;
    $dow = (int) date('N', strtotime($fecha));
    $d   = $jornada['dias'][$dow] ?? null;
    if (!$d || !(int) $d['labora'] || !$d['hora_entrada'] || !$d['hora_salida']) {
        return ['labora' => false, 'entrada' => null, 'salida' => null, 'horas' => 0.0];
    }

    $ini = strtotime("2000-01-01 {$d['hora_entrada']}");
    $fin = strtotime("2000-01-01 {$d['hora_salida']}");
    // Salida menor o igual que entrada = turno de noche: termina al día
    // siguiente. Se anota, porque «primera y última marca del día» parte esa
    // jornada en dos y las horas trabajadas de un turno de noche NO salen de
    // restar una marca de la otra.
    $nocturna = $fin <= $ini;
    if ($nocturna) $fin += 86400;

    $horas = ($fin - $ini) / 3600;
    if ($horas >= JOR_ALMUERZO_DESDE_HORAS) $horas -= (int) $jornada['almuerzo_min'] / 60;

    return [
        'labora'   => true,
        'entrada'  => $d['hora_entrada'],
        'salida'   => $d['hora_salida'],
        'horas'    => round(max(0, $horas), 2),
        'nocturna' => $nocturna,
    ];
}

/** El permiso aprobado que cubre esa fecha, o null. */
function jorPermiso(int $empleadoId, string $fecha): ?array
{
    $v = qOne("SELECT tipo, subtipo, con_goce FROM vacaciones
                WHERE empleado_id = ? AND estado IN ('aprobada','disfrutada')
                  AND ? BETWEEN fecha_desde AND fecha_hasta
                ORDER BY id LIMIT 1", [$empleadoId, $fecha]);
    return $v ?: null;
}

/* ===========================================================================
 *  EL DÍA COMPLETO
 * ======================================================================== */

/**
 * Qué pasó ese día, dicho con todo lo que el sistema sabe.
 *
 * Esta es la única función que decide un estado. La usan LAS DOS puntas —la
 * sincronización del reloj al guardar y la pantalla al pintar—, porque tener
 * dos criterios para lo mismo termina en una pantalla que dice «presente»
 * sobre una fila guardada como «tardanza».
 *
 * El orden de las preguntas no es casual:
 *
 *   1. ¿Ponchó?  Si hay marcas, la persona VINO, y eso manda sobre cualquier
 *      otra cosa. Si además estaba de vacaciones o era feriado, se dice —son
 *      situaciones con dinero dentro, art. 177 y art. 205— pero no se borra
 *      el hecho de que trabajó.
 *   2. Sin marcas: ¿tenía permiso aprobado? → vacaciones / licencia.
 *   3. ¿Era feriado? → feriado.
 *   4. ¿Su jornada dice que no se trabaja? → descanso.
 *   5. Solo entonces cabe hablar de ausencia, y ÚNICAMENTE si se puede
 *      demostrar (ver `$puedeAfirmarAusencia`).
 *
 * @param array       $emp      empleado: id, jornada_id
 * @param string      $fecha    Y-m-d
 * @param string|null $entrada  primera marca del día, HH:MM:SS
 * @param string|null $salida   última marca, o null si solo ponchó una vez
 * @param bool        $relojVivo si ese día el reloj registró marcas de alguien
 */
function jorEvaluarDia(array $emp, string $fecha, ?string $entrada, ?string $salida,
                       bool $relojVivo = false): array
{
    $empId   = (int) $emp['id'];
    $jornada = jorDeEmpleado($empId);
    $dia     = jorDelDia($jornada, $fecha);
    $feriado = jorFeriado($fecha);
    $permiso = jorPermiso($empId, $fecha);

    $r = [
        'estado'              => 'presente',
        'tardanza_min'        => 0,
        'salida_temprana_min' => 0,
        'horas_trabajadas'    => 0.0,
        'horas_esperadas'     => $dia && $dia['labora'] ? (float) $dia['horas'] : 0.0,
        'horas_extra'         => 0.0,
        'jornada_id'          => $jornada ? (int) $jornada['id'] : null,
        'feriado'             => $feriado['nombre'] ?? null,
        'permiso'             => null,
        'avisos'              => [],
        'sin_jornada'         => $jornada === null,
    ];
    if ($permiso) {
        $r['permiso'] = $permiso['tipo'] === 'vacaciones'
            ? 'Vacaciones'
            : ('Licencia' . ($permiso['subtipo'] ? ' · ' . $permiso['subtipo'] : ''));
    }

    /* ---------- 1) Hay marcas: vino ---------- */
    if ($entrada !== null) {
        $r['estado'] = 'presente';

        if ($salida !== null) {
            $ini = strtotime("$fecha $entrada");
            $fin = strtotime("$fecha $salida");
            // La jornada que cruza medianoche ya viene partida en dos por
            // «primera y última marca del día»: restar daría negativo. No se
            // corrige sola, se dice.
            if ($fin > $ini) {
                $h = ($fin - $ini) / 3600;
                if ($h >= JOR_ALMUERZO_DESDE_HORAS && $jornada) {
                    $h -= (int) $jornada['almuerzo_min'] / 60;
                }
                $r['horas_trabajadas'] = round(max(0, $h), 2);
            } else {
                $r['avisos'][] = 'La salida es anterior a la entrada: la jornada cruza la medianoche.';
            }
        }

        // Tardanza y salida temprana solo con jornada. Sin ella no hay contra
        // qué comparar y cualquier número sería inventado.
        if ($dia && $dia['labora']) {
            $tol   = (int) $jornada['tolerancia_min'];
            $debia = strtotime("$fecha {$dia['entrada']}");
            $llego = strtotime("$fecha $entrada");
            $mins  = (int) round(($llego - $debia) / 60);
            if ($mins > $tol) {
                // Se cuenta el retraso ENTERO desde la hora pactada, no desde
                // donde acaba la tolerancia: la tolerancia decide SI cuenta,
                // no cuánto. Diez minutos tarde son diez, no cinco.
                $r['tardanza_min'] = $mins;
                $r['estado']       = 'tardanza';
            }

            if ($salida !== null) {
                $debiaSalir = strtotime("$fecha {$dia['salida']}");
                $seFue      = strtotime("$fecha $salida");
                if ($seFue < $debiaSalir) {
                    $r['salida_temprana_min'] = (int) round(($debiaSalir - $seFue) / 60);
                }
            }

            $sobra = $r['horas_trabajadas'] - $r['horas_esperadas'];
            if ($sobra * 60 >= (int) $jornada['extra_desde_min']) {
                $r['horas_extra'] = round($sobra, 2);
            }
        }

        // Trabajó un día que no le tocaba. No se corrige el estado —vino—,
        // pero se dice, porque los dos casos se pagan distinto.
        if ($feriado) {
            $r['avisos'][] = 'Trabajó un feriado (' . $feriado['nombre'] . '): art. 205, se paga con recargo.';
            if ($r['horas_extra'] <= 0) $r['horas_extra'] = $r['horas_trabajadas'];
        } elseif ($dia && !$dia['labora']) {
            $r['avisos'][] = 'Trabajó en su día de descanso: art. 205, se paga con recargo.';
            if ($r['horas_extra'] <= 0) $r['horas_extra'] = $r['horas_trabajadas'];
        }
        if ($permiso) {
            $r['avisos'][] = 'Ponchó estando de ' . strtolower($r['permiso'])
                           . ': si se le paga el permiso y el día, se paga dos veces.';
        }
        if ($salida === null) {
            $r['avisos'][] = 'No ponchó la salida: ese día queda en cero horas, que es lo que se paga.';
        }
        return $r;
    }

    /* ---------- 2) Sin marcas ---------- */
    if ($permiso) {
        $r['estado'] = $permiso['tipo'] === 'vacaciones' ? 'vacaciones' : 'licencia';
        return $r;
    }
    if ($feriado) {
        $r['estado'] = 'feriado';
        return $r;
    }
    if ($dia && !$dia['labora']) {
        $r['estado'] = 'descanso';
        return $r;
    }

    /* ---------- 3) ¿Se puede afirmar que no vino? ----------
     *
     * Solo cuando las cuatro cosas se cumplen. Si falta una, el sistema NO
     * sabe qué pasó y decirlo «ausente» sería inventarse una falta —que acaba
     * en un descuento que nadie puede defender—.
     */
    $emparejado = !empty($emp['biotime_emp_code']);
    $pasado     = $fecha < date('Y-m-d');
    $conJornada = $dia !== null && $dia['labora'];

    if ($emparejado && $pasado && $conJornada && $relojVivo) {
        $r['estado'] = 'ausente';
    } else {
        $r['estado'] = 'sin_marcas';
        if (!$emparejado)  $r['avisos'][] = 'Su ficha no tiene código del reloj: pudo ponchar sin que le contara.';
        elseif (!$relojVivo) $r['avisos'][] = 'Ese día el reloj no registró nada de nadie: no se puede afirmar que no vino.';
        elseif (!$conJornada) $r['avisos'][] = 'No tiene jornada asignada: no se sabe si ese día le tocaba trabajar.';
    }
    return $r;
}

/**
 * ¿Registró el reloj algo ese día, de quien sea?
 *
 * Es lo que separa «no vino» de «el reloj estaba caído». Se resuelve el rango
 * de una vez porque preguntarlo por persona y día son cientos de consultas
 * para responder siempre lo mismo.
 */
function jorDiasConReloj(string $desde, string $hasta): array
{
    $out = [];
    foreach (qAll("SELECT DISTINCT fecha FROM asistencia_marcas
                    WHERE fecha BETWEEN ? AND ?", [$desde, $hasta]) as $f) {
        $out[$f['fecha']] = true;
    }
    return $out;
}

/* ===========================================================================
 *  RECALCULAR
 * ======================================================================== */

/**
 * Vuelve a juzgar los días ya guardados.
 *
 * Hace falta porque el horario se asigna DESPUÉS de que el reloj ya trajo
 * semanas de marcas: sin esto, la tardanza solo existiría de hoy en adelante y
 * todo lo anterior quedaría en «presente» para siempre.
 *
 * Respeta `origen = 'manual'` igual que la sincronización: lo que corrigió una
 * persona no lo pisa nadie.
 */
function jorRecalcular(string $desde, string $hasta, ?int $empleadoId = null, bool $simular = false): array
{
    jorDeEmpleado(0, true);   // el horario se relee en cada recálculo

    $r = ['revisadas' => 0, 'cambiadas' => 0, 'respetadas_manual' => 0, 'sin_jornada' => 0, 'detalle' => []];
    $relojVivo = jorDiasConReloj($desde, $hasta);

    $w = 'a.fecha BETWEEN ? AND ?';
    $p = [$desde, $hasta];
    if ($empleadoId) { $w .= ' AND a.empleado_id = ?'; $p[] = $empleadoId; }

    foreach (qAll("SELECT a.*, e.biotime_emp_code, e.nombre, e.apellido, e.jornada_id AS emp_jornada
                     FROM asistencias a JOIN empleados e ON e.id = a.empleado_id
                    WHERE $w ORDER BY a.fecha, e.nombre", $p) as $a) {
        $r['revisadas']++;
        if ($a['origen'] === 'manual') { $r['respetadas_manual']++; continue; }

        $ev = jorEvaluarDia(
            ['id' => (int) $a['empleado_id'], 'biotime_emp_code' => $a['biotime_emp_code']],
            $a['fecha'], $a['hora_entrada'], $a['hora_salida'],
            isset($relojVivo[$a['fecha']])
        );
        if ($ev['sin_jornada']) $r['sin_jornada']++;

        // `sin_marcas` no es un estado guardable: estas filas TIENEN marcas.
        $estado = $ev['estado'] === 'sin_marcas' ? 'presente' : $ev['estado'];

        $cambia = $estado !== $a['estado']
               || (int) $ev['tardanza_min']        !== (int) $a['tardanza_min']
               || (int) $ev['salida_temprana_min'] !== (int) $a['salida_temprana_min']
               || abs((float) $ev['horas_trabajadas'] - (float) $a['horas_trabajadas']) > 0.005
               || abs((float) $ev['horas_extra']     - (float) $a['horas_extra'])     > 0.005;

        if (!$cambia) continue;
        $r['cambiadas']++;
        $r['detalle'][] = trim($a['nombre'] . ' ' . $a['apellido']) . ' · ' . $a['fecha']
                        . ' · ' . $a['estado'] . ' → ' . $estado
                        . ($ev['tardanza_min'] ? ' (' . $ev['tardanza_min'] . ' min tarde)' : '');

        if (!$simular) {
            dbUpdate('asistencias', [
                'estado'              => $estado,
                'tardanza_min'        => (int) $ev['tardanza_min'],
                'salida_temprana_min' => (int) $ev['salida_temprana_min'],
                'horas_trabajadas'    => $ev['horas_trabajadas'],
                'horas_extra'         => $ev['horas_extra'],
                'horas_esperadas'     => $ev['horas_esperadas'],
                'jornada_id'          => $ev['jornada_id'],
            ], 'id = ?', [(int) $a['id']]);
        }
    }
    return $r;
}

/** Cuántas personas activas no tienen horario. Sin él no hay tardanza posible. */
function jorSinJornada(): array
{
    return qAll("SELECT id, nombre, apellido FROM empleados
                  WHERE estado <> 'inactivo' AND jornada_id IS NULL
                  ORDER BY nombre, apellido");
}

/** Los nombres de los días, para las pantallas. */
function jorNombreDia(int $n): string
{
    return ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][$n] ?? '';
}

/** Cómo se llama y de qué color va cada estado del día. */
function jorEstadoBadge(string $estado, array $ev = []): string
{
    switch ($estado) {
        case 'presente':   return badge('Ponchó', 'emerald');
        case 'tardanza':   return badge('Tarde ' . ($ev['tardanza_min'] ?? 0) . ' min', 'amber');
        case 'ausente':    return badge('Ausente', 'rose');
        case 'vacaciones': return badge($ev['permiso'] ?? 'Vacaciones', 'indigo');
        case 'licencia':   return badge($ev['permiso'] ?? 'Licencia', 'violet');
        case 'permiso':    return badge('Permiso', 'sky');
        case 'feriado':    return badge($ev['feriado'] ?? 'Feriado', 'cyan');
        case 'descanso':   return badge('Descanso', 'slate');
        default:           return '<span class="badge badge-slate">Sin marcas</span>';
    }
}
