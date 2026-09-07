<?php
/**
 * Centro de Entrenamiento — motor.
 *
 * El temario vive en `entrenamiento_temario_operacion.php` y
 * `entrenamiento_temario_gestion.php`. Aquí está todo lo que lo hace funcionar:
 * el recorte por permisos, el avance de cada persona, las evaluaciones, los
 * certificados y la ayuda contextual.
 *
 * ---------------------------------------------------------------------------
 * LA IDEA CENTRAL
 * ---------------------------------------------------------------------------
 * El sistema tiene 13 módulos y más de 70 permisos. Nadie usa los 13. Un manual
 * único obliga a cada persona a buscar su parte entre las de los demás, y lo que
 * pasa en la práctica es que no lo lee nadie.
 *
 * Por eso el temario se recorta con `can()`: una lección declara el MISMO
 * permiso que abre la pantalla que enseña. Quien no puede ver la nómina tampoco
 * ve su lección — no por secreto, sino porque estudiar algo que no se puede
 * abrir es tiempo perdido y ensucia el avance con lecciones que nunca va a
 * completar.
 *
 * Consecuencia de diseño: TODOS los porcentajes de avance se calculan sobre lo
 * VISIBLE para esa persona. Un cajero con 12 lecciones y un administrador con 70
 * llegan los dos al 100%. Comparar avances entre roles no tendría sentido de
 * otra forma.
 */

require_once __DIR__ . '/entrenamiento_temario_operacion.php';
require_once __DIR__ . '/entrenamiento_temario_gestion.php';

/** Porcentaje mínimo para aprobar una evaluación de ruta. */
const ENT_APROBACION = 80;

/* ============================================================
 *  DISPONIBILIDAD
 * ============================================================ */

/**
 * ¿Están las tablas de la migración P37?
 *
 * El código puede desplegarse antes que la migración. Sin las tablas el temario
 * se puede LEER igual (es contenido, no datos): lo único que se pierde es
 * guardar el avance. Cualquier función que escriba comprueba esto primero.
 */
function ent_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $n = (int) qVal(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('entrenamiento_progreso','entrenamiento_evaluaciones')"
        );
        $ok = $n === 2;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/* ============================================================
 *  CATÁLOGO
 * ============================================================ */

/** Temario completo, sin filtrar. @return array<string,array> */
function ent_catalogo(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    return $cache = array_merge(ent_temario_operacion(), ent_temario_gestion());
}

/**
 * ¿Tiene la persona el permiso que pide esta ruta o esta lección?
 *
 * `null` = todo el mundo. Un arreglo = basta con uno (igual que `can_any()`),
 * porque hay rutas a las que se llega por varias puertas: el bloque de POS lo
 * abre tanto el cajero (`pos.vender`) como quien solo consulta ventas.
 */
function ent_permiso_ok($permiso): bool
{
    if ($permiso === null || $permiso === '') return true;
    return is_array($permiso) ? can_any($permiso) : can($permiso);
}

/**
 * El temario recortado a lo que esta persona puede usar.
 *
 * Una ruta aparece si tiene su permiso O si al menos una de sus lecciones es
 * visible: así, quien solo tiene `conteos.ver` ve la ruta de inventario con esa
 * única lección dentro, en vez de quedarse sin ruta.
 */
function ent_catalogo_visible(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = [];
    foreach (ent_catalogo() as $rk => $ruta) {
        $lecciones = [];
        foreach ($ruta['lecciones'] as $lk => $lec) {
            if (ent_permiso_ok($lec['permiso'] ?? null)) {
                $lecciones[$lk] = $lec;
            }
        }
        // Ninguna lección utilizable: la ruta entera desaparece. No se comprueba
        // el permiso de la RUTA: es informativo (dice a qué área pertenece) y
        // exigirlo dejaría fuera a quien tiene una sola de sus lecciones — el
        // caso de quien solo puede ver conteos dentro de todo el inventario.
        if (!$lecciones) continue;
        $out[$rk] = array_merge($ruta, ['lecciones' => $lecciones]);
    }
    return $cache = $out;
}

/** Clave completa de una lección, la que se guarda en la base. */
function ent_clave(string $ruta, string $leccion): string
{
    return $ruta . '.' . $leccion;
}

/** Una ruta visible, o null. */
function ent_ruta(string $ruta): ?array
{
    return ent_catalogo_visible()[$ruta] ?? null;
}

/**
 * Una lección visible, con su ruta y su posición.
 * @return array{ruta:array, ruta_clave:string, leccion:array, leccion_clave:string,
 *               clave:string, indice:int, total:int, anterior:?string, siguiente:?string}|null
 */
function ent_leccion(string $ruta, string $leccion): ?array
{
    $r = ent_ruta($ruta);
    if (!$r || !isset($r['lecciones'][$leccion])) return null;

    $claves = array_keys($r['lecciones']);
    $i      = array_search($leccion, $claves, true);

    return [
        'ruta'          => $r,
        'ruta_clave'    => $ruta,
        'leccion'       => $r['lecciones'][$leccion],
        'leccion_clave' => $leccion,
        'clave'         => ent_clave($ruta, $leccion),
        'indice'        => $i + 1,
        'total'         => count($claves),
        'anterior'      => $claves[$i - 1] ?? null,
        'siguiente'     => $claves[$i + 1] ?? null,
    ];
}

/** Minutos estimados de una ruta (solo lo visible). */
function ent_minutos_ruta(array $ruta): int
{
    $m = 0;
    foreach ($ruta['lecciones'] as $l) $m += (int) ($l['minutos'] ?? 5);
    return $m;
}

/* ============================================================
 *  AVANCE
 * ============================================================ */

/**
 * Avance de una persona: [leccion_clave => fila].
 *
 * Se lee UNA vez por petición y se reparte. El hub pinta ~12 rutas con ~70
 * lecciones: preguntar por cada una sería una consulta por iteración, que es
 * justo el error de rendimiento que documenta CONVENCIONES-DEV.
 */
function ent_progreso(?int $usuarioId = null, bool $recargar = false): array
{
    static $cache = [];
    $uid = $usuarioId ?: (int) (current_user()['id'] ?? 0);
    if ($uid <= 0 || !ent_disponible()) return [];
    // Toda escritura invalida: sin esto, completar una lección y preguntar en la
    // misma petición cuánto lleva la ruta devuelve la foto de ANTES, y el aviso
    // de «ya puedes presentar la evaluación» no aparece nunca.
    if ($recargar) unset($cache[$uid]);
    if (isset($cache[$uid])) return $cache[$uid];

    $filas = qAll(
        "SELECT leccion_clave, ruta_clave, estado, paso_actual, pasos_total,
                segundos, veces, completado_at
           FROM entrenamiento_progreso WHERE usuario_id = ?",
        [$uid]
    );
    $out = [];
    foreach ($filas as $f) $out[$f['leccion_clave']] = $f;
    return $cache[$uid] = $out;
}

/** Estado de una lección para esta persona: 'pendiente' | 'en_curso' | 'completada'. */
function ent_estado_leccion(string $clave, ?array $progreso = null): string
{
    $progreso = $progreso ?? ent_progreso();
    return $progreso[$clave]['estado'] ?? 'pendiente';
}

/**
 * Guarda por dónde va. Se llama al abrir un paso.
 *
 * INSERT ... ON DUPLICATE KEY UPDATE contra la clave única (usuario, lección):
 * sin leer antes, y sin que dos pestañas abiertas en la misma lección creen dos
 * filas. `paso_actual` solo avanza (GREATEST): volver atrás a repasar el paso 2
 * no debe borrar que ya se había llegado al 6.
 */
function ent_registrar_paso(string $ruta, string $leccion, int $paso, int $total): void
{
    if (!ent_disponible()) return;
    $uid = (int) (current_user()['id'] ?? 0);
    if ($uid <= 0) return;

    // Guardar el avance es secundario: si falla, la lección se sigue leyendo.
    // Una sesión viva de un usuario ya borrado rompe la clave foránea, y sería
    // absurdo que eso dejara a alguien sin poder consultar el manual.
    try {
        q("INSERT INTO entrenamiento_progreso
              (usuario_id, ruta_clave, leccion_clave, estado, paso_actual, pasos_total, iniciado_at)
           VALUES (?, ?, ?, 'en_curso', ?, ?, NOW())
           ON DUPLICATE KEY UPDATE
              paso_actual = GREATEST(paso_actual, VALUES(paso_actual)),
              pasos_total = VALUES(pasos_total)",
            [$uid, $ruta, ent_clave($ruta, $leccion), max(1, $paso), max(1, $total)]
        );
        ent_progreso($uid, true);
    } catch (Throwable $e) {
        // Silencio deliberado: no hay nada que el lector pueda hacer al respecto.
    }
}

/**
 * Marca una lección como completada.
 *
 * Repetir una lección ya completada NO la reabre: sube `veces` y suma el tiempo.
 * El repaso es bueno; que el avance baje por repasar, no.
 */
function ent_completar(string $ruta, string $leccion, int $segundos = 0): void
{
    if (!ent_disponible()) return;
    $uid = (int) (current_user()['id'] ?? 0);
    if ($uid <= 0) return;

    $clave = ent_clave($ruta, $leccion);
    $yaEstaba = ent_estado_leccion($clave) === 'completada';

    try {
        q("INSERT INTO entrenamiento_progreso
              (usuario_id, ruta_clave, leccion_clave, estado, paso_actual, pasos_total,
               segundos, veces, iniciado_at, completado_at)
           VALUES (?, ?, ?, 'completada', 1, 1, ?, 1, NOW(), NOW())
           ON DUPLICATE KEY UPDATE
              estado        = 'completada',
              segundos      = segundos + VALUES(segundos),
              veces         = veces + 1,
              completado_at = COALESCE(completado_at, NOW())",
            [$uid, $ruta, $clave, max(0, min($segundos, 7200))]
        );
    } catch (Throwable $e) {
        return;   // ver el comentario de ent_registrar_paso()
    }
    ent_progreso($uid, true);

    if (!$yaEstaba) {
        audit('entrenamiento', 'leccion_completada', 'Completó la lección ' . $clave);
    }
}

/** Borra el avance: toda la academia, o solo una ruta. */
function ent_reiniciar(int $usuarioId, ?string $ruta = null): int
{
    if (!ent_disponible() || $usuarioId <= 0) return 0;

    if ($ruta === null) {
        $n = q("DELETE FROM entrenamiento_progreso WHERE usuario_id = ?", [$usuarioId])->rowCount();
        q("DELETE FROM entrenamiento_evaluaciones WHERE usuario_id = ?", [$usuarioId]);
    } else {
        $n = q("DELETE FROM entrenamiento_progreso WHERE usuario_id = ? AND ruta_clave = ?",
            [$usuarioId, $ruta])->rowCount();
        q("DELETE FROM entrenamiento_evaluaciones WHERE usuario_id = ? AND ruta_clave = ?",
            [$usuarioId, $ruta]);
    }
    ent_progreso($usuarioId, true);
    audit('entrenamiento', 'reinicio',
        'Reinició el entrenamiento' . ($ruta ? " de la ruta $ruta" : ' completo'),
        ['tabla' => 'entrenamiento_progreso', 'registro_id' => $usuarioId]);
    return $n;
}

/* ============================================================
 *  RESUMEN Y AVANCE POR RUTA
 * ============================================================ */

/**
 * Avance de una ruta para esta persona.
 * @return array{total:int, completadas:int, en_curso:int, pct:int, minutos:int, minutos_restantes:int}
 */
function ent_avance_ruta(array $ruta, ?array $progreso = null, string $rutaClave = ''): array
{
    $progreso = $progreso ?? ent_progreso();
    $total = count($ruta['lecciones']);
    $ok = $curso = $minRest = 0;

    foreach ($ruta['lecciones'] as $lk => $lec) {
        $estado = ent_estado_leccion(ent_clave($rutaClave, $lk), $progreso);
        if ($estado === 'completada')      $ok++;
        elseif ($estado === 'en_curso')    $curso++;
        if ($estado !== 'completada')      $minRest += (int) ($lec['minutos'] ?? 5);
    }

    return [
        'total'             => $total,
        'completadas'       => $ok,
        'en_curso'          => $curso,
        'pct'               => $total > 0 ? (int) round($ok / $total * 100) : 0,
        'minutos'           => ent_minutos_ruta($ruta),
        'minutos_restantes' => $minRest,
    ];
}

/**
 * Resumen del entrenamiento de QUIEN MIRA, sobre lo visible para esa persona.
 *
 * No acepta otro usuario a propósito: se apoya en `ent_catalogo_visible()`, que
 * usa los permisos de la sesión. Para el avance de otro está `ent_equipo()` y
 * `ent_detalle_usuario()`, que sí miden contra el temario del otro rol.
 *
 * @return array{rutas:int, lecciones:int, completadas:int, pct:int,
 *               minutos:int, minutos_restantes:int, certificados:int, minutos_dedicados:int}
 */
function ent_resumen(): array
{
    $progreso = ent_progreso();
    $cat = ent_catalogo_visible();

    $lecciones = $ok = $min = $minRest = $certs = 0;
    foreach ($cat as $rk => $ruta) {
        $a = ent_avance_ruta($ruta, $progreso, $rk);
        $lecciones += $a['total'];
        $ok        += $a['completadas'];
        $min       += $a['minutos'];
        $minRest   += $a['minutos_restantes'];
        if (ent_certificado_emitido($rk)) $certs++;
    }

    $segundos = 0;
    foreach ($progreso as $p) $segundos += (int) $p['segundos'];

    return [
        'rutas'             => count($cat),
        'lecciones'         => $lecciones,
        'completadas'       => $ok,
        'pct'               => $lecciones > 0 ? (int) round($ok / $lecciones * 100) : 0,
        'minutos'           => $min,
        'minutos_restantes' => $minRest,
        'certificados'      => $certs,
        'minutos_dedicados' => (int) round($segundos / 60),
    ];
}

/**
 * Por dónde seguir. Devuelve [ruta_clave, leccion_clave] o null si terminó todo.
 *
 * Prioridad: (1) la lección que dejó a medias, (2) la primera pendiente de la
 * ruta que ya empezó — para no dejar rutas a medio terminar —, (3) la primera
 * pendiente del temario en su orden.
 */
function ent_siguiente(): ?array
{
    $progreso = ent_progreso();
    $cat = ent_catalogo_visible();

    foreach ($cat as $rk => $ruta) {
        foreach ($ruta['lecciones'] as $lk => $_) {
            if (ent_estado_leccion(ent_clave($rk, $lk), $progreso) === 'en_curso') {
                return [$rk, $lk];
            }
        }
    }
    foreach ($cat as $rk => $ruta) {
        $a = ent_avance_ruta($ruta, $progreso, $rk);
        if ($a['completadas'] > 0 && $a['completadas'] < $a['total']) {
            foreach ($ruta['lecciones'] as $lk => $_) {
                if (ent_estado_leccion(ent_clave($rk, $lk), $progreso) !== 'completada') {
                    return [$rk, $lk];
                }
            }
        }
    }
    foreach ($cat as $rk => $ruta) {
        foreach ($ruta['lecciones'] as $lk => $_) {
            if (ent_estado_leccion(ent_clave($rk, $lk), $progreso) !== 'completada') {
                return [$rk, $lk];
            }
        }
    }
    return null;
}

/* ============================================================
 *  EVALUACIONES
 * ============================================================ */

/**
 * Las preguntas de una ruta: las de sus lecciones VISIBLES, en orden.
 *
 * Se examina de lo que se pudo estudiar. Incluir la pregunta de una lección que
 * el permiso ocultó sería preguntar por algo que nunca se mostró.
 *
 * @return array<int,array{p:string,ops:array,ok:int,exp:string,leccion:string,leccion_titulo:string}>
 */
function ent_quiz_ruta(string $rutaClave): array
{
    $r = ent_ruta($rutaClave);
    if (!$r) return [];

    $preguntas = [];
    foreach ($r['lecciones'] as $lk => $lec) {
        foreach (($lec['quiz'] ?? []) as $q) {
            $preguntas[] = $q + [
                'leccion'        => $lk,
                'leccion_titulo' => $lec['titulo'],
            ];
        }
    }
    return $preguntas;
}

/**
 * Corrige y guarda un intento.
 *
 * @param array $respuestas  [índice de pregunta => índice de opción elegida]
 * @return array{puntaje:int,total:int,pct:float,aprobado:bool,detalle:array}
 */
function ent_evaluar(string $rutaClave, array $respuestas, int $segundos = 0): array
{
    $preguntas = ent_quiz_ruta($rutaClave);
    $total = count($preguntas);
    $ok = 0;
    $detalle = [];

    foreach ($preguntas as $i => $q) {
        $elegida  = isset($respuestas[$i]) && $respuestas[$i] !== '' ? (int) $respuestas[$i] : null;
        $correcta = (int) $q['ok'];
        $acierto  = $elegida !== null && $elegida === $correcta;
        if ($acierto) $ok++;
        $detalle[] = [
            'pregunta'       => $q['p'],
            'opciones'       => $q['ops'],
            'elegida'        => $elegida,
            'correcta'       => $correcta,
            'acierto'        => $acierto,
            'explicacion'    => $q['exp'] ?? '',
            'leccion'        => $q['leccion'],
            'leccion_titulo' => $q['leccion_titulo'],
        ];
    }

    $pct = $total > 0 ? round($ok / $total * 100, 2) : 0.0;
    $aprobado = $total > 0 && $pct >= ENT_APROBACION;

    if (ent_disponible()) {
        $uid = (int) (current_user()['id'] ?? 0);
        if ($uid > 0) {
            dbInsert('entrenamiento_evaluaciones', [
                'usuario_id' => $uid,
                'ruta_clave' => $rutaClave,
                'puntaje'    => $ok,
                'total'      => $total,
                'porcentaje' => $pct,
                'aprobado'   => $aprobado ? 1 : 0,
                'segundos'   => max(0, min($segundos, 7200)),
                'respuestas' => json_encode($respuestas, JSON_UNESCAPED_UNICODE),
            ]);
            audit('entrenamiento', 'evaluacion',
                'Evaluación de «' . $rutaClave . '»: ' . $ok . '/' . $total
                . ' (' . number_format($pct, 0) . '%) — ' . ($aprobado ? 'aprobada' : 'no aprobada'));
        }
    }

    return ['puntaje' => $ok, 'total' => $total, 'pct' => $pct,
            'aprobado' => $aprobado, 'detalle' => $detalle];
}

/** El mejor intento de una ruta, o null. */
function ent_mejor_evaluacion(string $rutaClave, ?int $usuarioId = null): ?array
{
    if (!ent_disponible()) return null;
    $uid = $usuarioId ?: (int) (current_user()['id'] ?? 0);
    if ($uid <= 0) return null;

    return qOne(
        "SELECT * FROM entrenamiento_evaluaciones
          WHERE usuario_id = ? AND ruta_clave = ?
          ORDER BY porcentaje DESC, created_at ASC LIMIT 1",
        [$uid, $rutaClave]
    );
}

/** Intentos de una ruta, del más reciente al más antiguo. */
function ent_intentos(string $rutaClave, ?int $usuarioId = null): array
{
    if (!ent_disponible()) return [];
    $uid = $usuarioId ?: (int) (current_user()['id'] ?? 0);
    if ($uid <= 0) return [];

    return qAll(
        "SELECT * FROM entrenamiento_evaluaciones
          WHERE usuario_id = ? AND ruta_clave = ? ORDER BY created_at DESC LIMIT 20",
        [$uid, $rutaClave]
    );
}

/* ============================================================
 *  CERTIFICADOS
 * ============================================================ */

/**
 * ¿Se puede presentar la evaluación de esta ruta?
 *
 * Solo con todas sus lecciones visibles completadas. Evaluar sin haber estudiado
 * convierte el certificado en una lotería de cuatro opciones.
 */
function ent_puede_evaluar(string $rutaClave): bool
{
    $r = ent_ruta($rutaClave);
    if (!$r || !ent_quiz_ruta($rutaClave)) return false;
    $a = ent_avance_ruta($r, ent_progreso(), $rutaClave);
    return $a['total'] > 0 && $a['completadas'] === $a['total'];
}

/**
 * ¿Tiene certificado de esta ruta? (todo completado + evaluación aprobada)
 *
 * Solo de quien mira, por la misma razón que `ent_resumen()`.
 */
function ent_certificado_emitido(string $rutaClave): bool
{
    $r = ent_ruta($rutaClave);
    if (!$r) return false;

    $a = ent_avance_ruta($r, ent_progreso(), $rutaClave);
    if ($a['total'] === 0 || $a['completadas'] < $a['total']) return false;

    $ev = ent_mejor_evaluacion($rutaClave);
    return $ev !== null && (int) $ev['aprobado'] === 1;
}

/**
 * Folio del certificado: verificable y sin números correlativos que revelen
 * cuántos se han emitido.
 */
function ent_folio(int $usuarioId, string $rutaClave, string $fecha): string
{
    $h = strtoupper(substr(hash('sha256', $usuarioId . '|' . $rutaClave . '|' . $fecha . '|' . APP_NAME), 0, 8));
    return 'NX-' . strtoupper(substr(preg_replace('/[^a-z]/i', '', $rutaClave) . 'XXX', 0, 3)) . '-' . $h;
}

/* ============================================================
 *  EQUIPO (supervisión)
 * ============================================================ */

/**
 * Avance de todo el equipo.
 *
 * IMPORTANTE: el porcentaje de cada persona se calcula contra SU temario, que
 * depende de sus permisos. Por eso no basta con contar filas: hay que saber
 * cuántas lecciones le tocan a cada rol. Se resuelve con una pasada por rol,
 * simulando sus permisos — no una consulta por usuario.
 */
function ent_equipo(): array
{
    if (!ent_disponible()) return [];

    [$scope, $params] = sucursalScope('u.sucursal_id');
    // El alcance por sucursal no debe esconder al personal con alcance global
    // (dirección, administración): si no, un encargado no ve a su propio jefe.
    $usuarios = qAll(
        "SELECT u.id, u.nombre, u.apellido, u.usuario, u.email, u.avatar, u.activo,
                u.rol_id, u.ultimo_acceso, r.nombre AS rol_nombre, r.es_super,
                s.nombre AS sucursal_nombre
           FROM usuarios u
           JOIN roles r ON r.id = u.rol_id
      LEFT JOIN sucursales s ON s.id = u.sucursal_id
          WHERE u.activo = 1 AND ($scope OR u.sucursal_id IS NULL)
       ORDER BY r.nombre, u.nombre, u.apellido",
        $params
    );
    if (!$usuarios) return [];

    $ids = array_column($usuarios, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $prog = qAll(
        "SELECT usuario_id,
                SUM(estado = 'completada') AS completadas,
                SUM(estado = 'en_curso')   AS en_curso,
                SUM(segundos)              AS segundos,
                MAX(updated_at)            AS ultima
           FROM entrenamiento_progreso
          WHERE usuario_id IN ($in)
       GROUP BY usuario_id",
        $ids
    );
    $porUsuario = [];
    foreach ($prog as $p) $porUsuario[(int) $p['usuario_id']] = $p;

    $evals = qAll(
        "SELECT usuario_id, COUNT(DISTINCT ruta_clave) AS rutas_aprobadas
           FROM entrenamiento_evaluaciones
          WHERE usuario_id IN ($in) AND aprobado = 1
       GROUP BY usuario_id",
        $ids
    );
    $porEval = [];
    foreach ($evals as $e) $porEval[(int) $e['usuario_id']] = (int) $e['rutas_aprobadas'];

    // Cuántas lecciones le tocan a cada ROL (no a cada usuario): los usuarios de
    // un mismo rol comparten temario, así que basta con calcularlo una vez.
    $porRol = [];
    foreach ($usuarios as $u) {
        $rol = (int) $u['rol_id'];
        if (isset($porRol[$rol])) continue;
        $porRol[$rol] = ent_lecciones_de_rol($rol, (int) $u['es_super'] === 1);
    }

    $out = [];
    foreach ($usuarios as $u) {
        $uid   = (int) $u['id'];
        $tot   = $porRol[(int) $u['rol_id']];
        $hecho = (int) ($porUsuario[$uid]['completadas'] ?? 0);
        // Si a alguien le quitaron un permiso después de estudiar, lo hecho
        // puede superar a lo que hoy le toca. El tope evita un 130%.
        $hecho = min($hecho, $tot);
        $out[] = $u + [
            'lecciones_total' => $tot,
            'completadas'     => $hecho,
            'en_curso'        => (int) ($porUsuario[$uid]['en_curso'] ?? 0),
            'pct'             => $tot > 0 ? (int) round($hecho / $tot * 100) : 0,
            'minutos'         => (int) round((int) ($porUsuario[$uid]['segundos'] ?? 0) / 60),
            'ultima'          => $porUsuario[$uid]['ultima'] ?? null,
            'certificados'    => $porEval[$uid] ?? 0,
        ];
    }
    return $out;
}

/**
 * Ejecuta algo «viendo el sistema» con los permisos de otro rol.
 *
 * `can()` es la única fuente de verdad del recorte del temario. Para saber qué
 * le toca a OTRA persona hay dos caminos: reimplementar aquí la lógica de
 * permisos —y garantizar que las dos se separen con el tiempo— o prestarle a
 * `can()` los permisos del otro rol durante un instante. Se eligió lo segundo.
 *
 * El intercambio se deshace siempre, incluso si el callable lanza.
 */
function ent_con_permisos(int $rolId, bool $esSuper, callable $fn)
{
    $permisosPrevios = $_SESSION['permisos'] ?? [];
    $superPrevio     = $_SESSION['user']['es_super'] ?? 0;

    $_SESSION['permisos'] = $esSuper ? [] : load_permisos($rolId);
    if (isset($_SESSION['user'])) $_SESSION['user']['es_super'] = $esSuper ? 1 : 0;

    try {
        return $fn();
    } finally {
        $_SESSION['permisos'] = $permisosPrevios;
        if (isset($_SESSION['user'])) $_SESSION['user']['es_super'] = $superPrevio;
    }
}

/** Cuántas lecciones del temario le corresponden a un rol. */
function ent_lecciones_de_rol(int $rolId, bool $esSuper = false): int
{
    static $cache = [];
    $k = $rolId . ':' . (int) $esSuper;
    if (isset($cache[$k])) return $cache[$k];

    return $cache[$k] = ent_con_permisos($rolId, $esSuper, function () {
        $n = 0;
        foreach (ent_catalogo() as $ruta) {
            foreach ($ruta['lecciones'] as $lec) {
                if (ent_permiso_ok($lec['permiso'] ?? null)) $n++;
            }
        }
        return $n;
    });
}

/**
 * Avance de otra persona, ruta por ruta, medido contra SU temario.
 *
 * @return array<int,array{clave:string,titulo:string,icono:string,color:string,
 *                         total:int,completadas:int,pct:int,aprobada:bool,porcentaje:?float}>
 */
function ent_detalle_usuario(array $usuario): array
{
    if (!ent_disponible()) return [];
    $uid = (int) $usuario['id'];

    $filas = qCol(
        "SELECT leccion_clave FROM entrenamiento_progreso
          WHERE usuario_id = ? AND estado = 'completada'",
        [$uid]
    );
    $hechas = array_flip($filas);

    $evals = qAll(
        "SELECT ruta_clave, MAX(porcentaje) AS mejor, MAX(aprobado) AS aprobado
           FROM entrenamiento_evaluaciones WHERE usuario_id = ? GROUP BY ruta_clave",
        [$uid]
    );
    $porRuta = [];
    foreach ($evals as $e) $porRuta[$e['ruta_clave']] = $e;

    return ent_con_permisos((int) $usuario['rol_id'], (int) ($usuario['es_super'] ?? 0) === 1,
        function () use ($hechas, $porRuta) {
            $out = [];
            foreach (ent_catalogo() as $rk => $ruta) {
                $total = $ok = 0;
                foreach ($ruta['lecciones'] as $lk => $lec) {
                    if (!ent_permiso_ok($lec['permiso'] ?? null)) continue;
                    $total++;
                    if (isset($hechas[ent_clave($rk, $lk)])) $ok++;
                }
                if ($total === 0) continue;
                $ev = $porRuta[$rk] ?? null;
                $out[] = [
                    'clave'       => $rk,
                    'titulo'      => $ruta['titulo'],
                    'icono'       => $ruta['icono'],
                    'color'       => $ruta['color'],
                    'total'       => $total,
                    'completadas' => $ok,
                    'pct'         => (int) round($ok / $total * 100),
                    'aprobada'    => $ev !== null && (int) $ev['aprobado'] === 1,
                    'porcentaje'  => $ev !== null ? (float) $ev['mejor'] : null,
                ];
            }
            return $out;
        });
}

/* ============================================================
 *  AYUDA CONTEXTUAL
 * ============================================================ */

/**
 * Índice pantalla → lección, construido invirtiendo el campo `pantalla` del
 * temario. Se arma solo: añadir una lección con su `pantalla` la conecta al
 * botón de ayuda sin tocar nada más.
 *
 * @return array<string,array{0:string,1:string}>  ruta relativa => [ruta, lección]
 */
function ent_indice_pantallas(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $idx = [];
    foreach (ent_catalogo() as $rk => $ruta) {
        foreach ($ruta['lecciones'] as $lk => $lec) {
            foreach (ent_pantallas_de_leccion($lec) as $p) {
                // La primera lección que reclama una pantalla se queda con ella:
                // el temario está en orden didáctico, así que la primera es la
                // introductoria, que es la que conviene ofrecer.
                if (!isset($idx[$p])) $idx[$p] = [$rk, $lk];
            }
        }
    }
    return $cache = $idx;
}

/** Pantallas que enseña una lección: la principal y las de sus pasos. */
function ent_pantallas_de_leccion(array $lec): array
{
    $out = [];
    if (!empty($lec['pantalla'])) $out[] = ent_normalizar_pantalla($lec['pantalla']);
    foreach (($lec['pasos'] ?? []) as $p) {
        if (!empty($p['ruta'])) $out[] = ent_normalizar_pantalla($p['ruta']);
    }
    return array_values(array_unique(array_filter($out)));
}

/** «/base/modules/pos/index.php», «modules/pos/», «…/pos» → «modules/pos/index.php». */
function ent_normalizar_pantalla(string $p): string
{
    $p = parse_url($p, PHP_URL_PATH) ?: '';
    $p = str_replace('\\', '/', $p);
    if (($pos = strpos($p, 'modules/')) !== false) $p = substr($p, $pos);
    $p = ltrim($p, '/');
    if ($p === '' ) return '';
    if (str_ends_with($p, '/'))        $p .= 'index.php';
    elseif (!str_ends_with($p, '.php')) $p .= '.php';
    return $p;
}

/**
 * La lección que explica la pantalla actual, si existe y si la persona puede
 * verla. Devuelve ['ruta'=>…, 'leccion'=>…, 'titulo'=>…, 'url'=>…] o null.
 */
function ent_ayuda_actual(?string $script = null): ?array
{
    $script = ent_normalizar_pantalla($script ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') return null;

    $par = ent_indice_pantallas()[$script] ?? null;
    if (!$par) return null;

    [$rk, $lk] = $par;
    $info = ent_leccion($rk, $lk);          // ya viene filtrado por permisos
    if (!$info) return null;

    return [
        'ruta'    => $rk,
        'leccion' => $lk,
        'titulo'  => $info['leccion']['titulo'],
        'minutos' => (int) ($info['leccion']['minutos'] ?? 5),
        'estado'  => ent_estado_leccion(ent_clave($rk, $lk)),
        'url'     => url('modules/entrenamiento/leccion.php') . '?ruta=' . rawurlencode($rk)
                     . '&leccion=' . rawurlencode($lk),
    ];
}

/* ============================================================
 *  GLOSARIO
 * ============================================================ */

/**
 * Vocabulario del sistema y del negocio dominicano.
 *
 * Está aquí y no en una lección porque se consulta, no se estudia: alguien que
 * lleva seis meses usando el sistema sigue necesitando recordar qué es el 607.
 *
 * @return array<string,array<string,string>>  categoría => término => definición
 */
function ent_glosario(): array
{
    return [
        'Fiscal y DGII' => [
            'NCF' => 'Número de Comprobante Fiscal. La numeración autorizada por la DGII que identifica cada factura. Se consume de forma atómica: dos cajas cobrando a la vez nunca reciben el mismo número, y uno consumido no se recicla.',
            'e-CF' => 'Comprobante Fiscal Electrónico. Va a la DGII a través de un proveedor certificado que lo firma digitalmente y devuelve un acuse. Convive con el NCF preimpreso: no lo reemplaza hasta que se activa.',
            'RNC' => 'Registro Nacional del Contribuyente. Identifica fiscalmente a una empresa. El de la empresa es el emisor de todos los comprobantes, aunque se facture con varias marcas.',
            'ITBIS' => 'Impuesto sobre Transferencias de Bienes Industrializados y Servicios. Se cobra al cliente y se le entrega a la DGII: nunca es ingreso del negocio, y por eso los reportes lo excluyen.',
            '606' => 'Formato mensual de compras de bienes y servicios. Sale de las compras registradas con NCF.',
            '607' => 'Formato mensual de ventas. Sale de las ventas con NCF más las notas de crédito de las devoluciones.',
            '608' => 'Formato mensual de comprobantes anulados.',
            'IT-1' => 'Declaración Jurada del ITBIS. ITBIS cobrado menos ITBIS adelantado, más retenciones.',
            'IR-3' => 'Declaración de retenciones de asalariados. Sale de las nóminas confirmadas del período.',
            'Nota de crédito' => 'El comprobante que corrige o anula parcialmente una factura ya emitida. Es la forma correcta de corregir un NCF: no se edita ni se borra.',
        ],
        'Inventario' => [
            'Kardex' => 'La historia completa de un producto: cada entrada y salida en orden, con su documento y su responsable. No se edita; se corrige con un movimiento nuevo.',
            'Stock mínimo' => 'El umbral que dispara la alerta de reposición. Por debajo de él, el producto sale en rojo y entra en el informe de reposición.',
            'Ajuste' => 'Cambio de existencia sin un documento comercial detrás. Siempre exige motivo escrito y sale en el informe de ajustes y mermas.',
            'Merma' => 'Pérdida de inventario por rotura, vencimiento o deterioro. Se registra como una baja con su nota, no como un ajuste sin explicación.',
            'Conteo físico' => 'Procedimiento de cuatro fases (abrir, capturar, revisar, aplicar) para cuadrar el sistema con el estante. Capturar y aplicar son permisos distintos.',
            'Transferencia en tránsito' => 'Mercancía que ya salió del local de origen y todavía no entró al de destino. Es un estado correcto, y es lo que permite detectar lo que se pierde por el camino.',
            'Liquidación de importación' => 'El cálculo del costo real puesto en almacén: factura del proveedor más flete, seguro, aranceles y gastos, repartidos sobre cada artículo.',
            'FEFO' => 'First Expired, First Out: sale primero lo que vence primero. Es el criterio de consumo cuando hay control de lote.',
        ],
        'Ventas y clientes' => [
            'Sesión de caja' => 'El turno: se abre con un fondo, acumula ventas y movimientos, y se cierra con un arqueo. Una caja no puede tener dos sesiones abiertas.',
            'Arqueo' => 'El conteo del efectivo al cerrar, comparado contra el efectivo esperado. La diferencia queda registrada con su cajero.',
            'Efectivo esperado' => 'Fondo inicial + ventas en efectivo + ingresos − egresos − devoluciones en efectivo. Las ventas con tarjeta y a crédito NO entran.',
            'Venta a crédito' => 'No entra dinero hoy: el total se suma al balance del cliente y aparece en Cuentas por Cobrar.',
            'Cuentas por cobrar (CxC)' => 'Lo que los clientes deben. Se lee por antigüedad: 0-30, 31-60, 61-90 y más de 90 días.',
            'Abono' => 'Pago parcial o total de una deuda. Es entrada de efectivo, pero NO un ingreso nuevo: la venta ya se contabilizó el día que se hizo.',
            'Cotización' => 'Propuesta de precio con vigencia. No mueve stock ni consume NCF. Al facturarse respeta el precio pactado.',
        ],
        'Finanzas' => [
            'Gasto operativo' => 'Lo que consume el negocio para funcionar. NO incluye la compra de mercancía (que es inventario) ni las devoluciones (que ya restaron del ingreso).',
            'Costo de lo vendido' => 'El costo que tenía la mercancía el día que se vendió. Queda congelado: si se recalculara con el costo de hoy, el margen del año pasado cambiaría cada semana.',
            'Utilidad bruta' => 'Ingresos menos costo de lo vendido. Antes de gastos operativos.',
            'Flujo de efectivo' => 'Entradas y salidas de dinero real. Distinto del resultado: se puede ganar dinero y quedarse sin efectivo el mismo mes.',
            'Diferencia cambiaria' => 'El efecto de pagar una deuda en dólares a una tasa distinta de la de la compra. Es gasto o ingreso financiero, no un saldo pendiente.',
            'Conciliación bancaria' => 'Cruzar los libros contra el estado de cuenta. Un corte con diferencia no se cierra: la diferencia es justamente lo que hay que investigar.',
            'En tránsito (banca)' => 'Movimientos que ya están en libros pero el banco todavía no refleja.',
            'Depreciación' => 'El reconocimiento mensual del valor que va perdiendo un activo. Es gasto que NO mueve efectivo.',
            'Provisión laboral' => 'Lo que se debe al personal aunque todavía no se haya pagado: regalía devengada, vacaciones acumuladas y cesantía.',
        ],
        'Recursos Humanos' => [
            'AFP' => 'Administradora de Fondos de Pensiones. Aporte del trabajador sobre la base cotizable, con los topes de la Ley 87-01.',
            'SFS' => 'Seguro Familiar de Salud. El otro aporte del trabajador a la seguridad social.',
            'TSS' => 'Tesorería de la Seguridad Social. Recibe los aportes y las novedades del mes: altas, bajas y cambios de salario.',
            'ISR' => 'Impuesto Sobre la Renta. Se calcula sobre el equivalente mensual y se prorratea al período; la escala es anual y progresiva.',
            'Base cotizable' => 'Lo que cotiza a AFP y SFS: sueldo del período más horas extra, otras remuneraciones e incentivos, menos descuento de días.',
            'Regalía pascual' => 'El salario de Navidad, a pagar a más tardar el 20 de diciembre. Se prorratea por meses trabajados.',
            'Preaviso' => 'La compensación por terminar el contrato sin el aviso previo que exige la ley.',
            'Cesantía' => 'El auxilio que corresponde según la antigüedad cuando el empleador desahucia.',
            'Desahucio' => 'Terminación del contrato por voluntad de una parte sin causa. Del empleador, genera preaviso y cesantía.',
        ],
        'Sistema' => [
            'Sucursal' => 'El local físico. Es un límite de seguridad: gobierna stock, caja, usuarios y permisos.',
            'Tienda' => 'La marca comercial con la que se factura: logo, colores y datos impresos. NO es un límite de seguridad.',
            'Sucursal activa' => 'El selector de la barra superior. Decide qué datos ves en todo el sistema. Es la causa número uno de «me falta información».',
            'Rol' => 'Un paquete de permisos. Define qué puede hacer una persona.',
            'Permiso' => 'Una acción concreta (ver, crear, aprobar, anular), no un módulo entero.',
            'Alcance global' => 'Usuario sin sucursal asignada: ve todos los locales y puede cambiar de sucursal activa.',
            'Auditoría' => 'El registro de quién hizo qué, cuándo y desde dónde. No se edita ni se borra desde la aplicación.',
            'Segundo factor' => 'El código que llega al correo después de la contraseña. Un equipo de confianza lo omite durante un tiempo, en ese navegador.',
        ],
    ];
}
