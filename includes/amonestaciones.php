<?php
/**
 * Régimen disciplinario: amonestaciones, suspensiones y expediente.
 *
 * ============================================================================
 *  LOS 15 DÍAS QUE SE PIERDEN POR NO MIRARLOS
 * ============================================================================
 *
 * El derecho a despedir por una falta CADUCA a los 15 días de que el empleador
 * tuvo conocimiento de ella. Es el plazo que más se pierde: se documenta la
 * falta, pasan tres semanas, y para cuando alguien decide actuar ya no se puede
 * alegar esa causa.
 *
 * Por eso aquí se guardan dos fechas distintas —cuándo ocurrió y cuándo se
 * supo— y todo el módulo cuenta desde la segunda.
 *
 * El plazo NO está escrito a fuego por capricho: es de ley y no lo cambia una
 * configuración. Lo que sí es configurable —el catálogo de faltas y la
 * referencia legal de cada una— vive en base, para que lo ajuste el abogado
 * del cliente.
 */

/** Días de ley para ejercer el despido desde que se conoció la falta. */
const AMON_DIAS_CADUCIDAD = 15;

/** Horas de ley para comunicar un despido al Ministerio de Trabajo. */
const AMON_HORAS_COMUNICAR_DESPIDO = 48;

function amonDisponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { qVal("SELECT 1 FROM amonestaciones LIMIT 1"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

function amonTipos(): array
{
    return [
        'verbal'     => 'Amonestación verbal',
        'escrita'    => 'Amonestación escrita',
        'suspension' => 'Suspensión',
    ];
}

function amonGravedades(): array
{
    return [
        'leve'      => ['Leve', 'sky'],
        'grave'     => ['Grave', 'amber'],
        'muy_grave' => ['Muy grave', 'rose'],
    ];
}

function amonEstados(): array
{
    return [
        'borrador'      => ['Borrador', 'slate'],
        'notificada'    => ['Notificada', 'sky'],
        'firmada'       => ['Firmada', 'emerald'],
        'rehuso_firmar' => ['Rehusó firmar', 'amber'],
        'anulada'       => ['Anulada', 'slate'],
    ];
}

/**
 * Estado del plazo del artículo 90 para una falta.
 *
 * Devuelve siempre el detalle, haya caducado o no, para que la pantalla pueda
 * enseñar los días que quedan y no solo un sí o un no.
 */
function amonCaducidad(string $fechaConocimiento, ?string $hoy = null): array
{
    $hoy = $hoy ?: date('Y-m-d');
    $limite = date('Y-m-d', strtotime($fechaConocimiento . ' +' . AMON_DIAS_CADUCIDAD . ' days'));
    $dias = (int) floor((strtotime($limite) - strtotime($hoy)) / 86400);
    return [
        'limite'    => $limite,
        'dias'      => $dias,
        'caducado'  => $dias < 0,
        'urgente'   => $dias >= 0 && $dias <= 3,
        'etiqueta'  => $dias < 0
            ? 'Caducó hace ' . abs($dias) . ' día' . (abs($dias) === 1 ? '' : 's')
            : ($dias === 0 ? 'Vence HOY' : 'Quedan ' . $dias . ' día' . ($dias === 1 ? '' : 's')),
    ];
}

/**
 * Historial disciplinario de una persona, con la progresión.
 *
 * La disciplina progresiva —verbal, escrita, suspensión— es lo que sostiene un
 * despido: sin historial, la primera medida grave parece arbitraria. Esto
 * devuelve lo que hace falta para verlo de un vistazo.
 */
function amonHistorial(int $empleadoId, int $meses = 12): array
{
    if (!amonDisponible()) return ['total' => 0, 'vigentes' => 0, 'lista' => [], 'por_tipo' => [], 'dias_suspendido' => 0];
    $desde = date('Y-m-d', strtotime("-$meses months"));
    $lista = qAll(
        "SELECT a.*, f.nombre AS falta FROM amonestaciones a
           LEFT JOIN amonestacion_faltas f ON f.id = a.falta_id
          WHERE a.empleado_id = ? AND a.estado <> 'anulada' AND a.fecha_emision >= ?
          ORDER BY a.fecha_emision DESC, a.id DESC", [$empleadoId, $desde]);

    $porTipo = [];
    $dias = 0;
    foreach ($lista as $a) {
        $porTipo[$a['tipo']] = ($porTipo[$a['tipo']] ?? 0) + 1;
        $dias += (int) $a['dias_suspension'];
    }
    return [
        'total'           => count($lista),
        'vigentes'        => count(array_filter($lista, fn($a) => in_array($a['estado'], ['notificada', 'firmada', 'rehuso_firmar'], true))),
        'lista'           => $lista,
        'por_tipo'        => $porTipo,
        'dias_suspendido' => $dias,
        'meses'           => $meses,
    ];
}

/**
 * Qué medida sugiere el historial.
 *
 * NO decide nada: la decisión es del empleador. Solo dice en qué punto de la
 * progresión está la persona, que es lo que suele mirarse mal —o no mirarse—
 * cuando se levanta la tercera amonestación como si fuera la primera.
 */
function amonSugerencia(int $empleadoId, string $gravedad): array
{
    $h = amonHistorial($empleadoId);
    $previas = $h['vigentes'];
    if ($gravedad === 'muy_grave') {
        return ['tipo' => 'suspension', 'texto' => 'Falta muy grave: procede suspensión, y con historial previo puede sostenerse una medida mayor.'];
    }
    if ($previas === 0) {
        return ['tipo' => $gravedad === 'grave' ? 'escrita' : 'verbal',
                'texto' => 'Sin antecedentes en los últimos 12 meses: la medida ordinaria es la más leve de su tipo.'];
    }
    if ($previas === 1) {
        return ['tipo' => 'escrita', 'texto' => 'Ya hay 1 medida vigente: corresponde amonestación escrita.'];
    }
    return ['tipo' => 'suspension',
            'texto' => 'Ya hay ' . $previas . ' medidas vigentes en 12 meses: la progresión apunta a suspensión.'];
}

/** Resumen para las tarjetas del listado. */
function amonResumen(): array
{
    if (!amonDisponible()) return ['mes' => 0, 'sin_notificar' => 0, 'por_caducar' => 0, 'suspendidos' => 0];
    $r = qOne(
        "SELECT COALESCE(SUM(fecha_emision >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) mes,
                COALESCE(SUM(estado = 'borrador'), 0) sin_notificar,
                COALESCE(SUM(estado <> 'anulada'
                         AND fecha_conocimiento >= DATE_SUB(CURDATE(), INTERVAL " . AMON_DIAS_CADUCIDAD . " DAY)
                         AND fecha_conocimiento <= CURDATE()), 0) por_caducar,
                COALESCE(SUM(tipo = 'suspension' AND estado <> 'anulada'
                         AND suspension_hasta >= CURDATE()), 0) suspendidos
           FROM amonestaciones");
    return array_map('intval', $r ?: []);
}
