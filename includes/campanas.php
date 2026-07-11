<?php
/**
 * Campañas por correo: envío masivo a clientes usando la infraestructura de
 * Resend (includes/mail.php) y la plantilla HTML de los correos del sistema.
 *
 * El envío es sÍncrono y con un tope de seguridad: para una base de clientes de
 * PyME es suficiente. Cada correo individual queda registrado en correos_enviados
 * con su campana_id, y la campaña acumula enviados/fallidos.
 */

const CAMPANA_TOPE = 1000;   // máximo de destinatarios por envío (evita cuelgues)

/** Etiquetas de los segmentos disponibles. */
function campanaSegmentos(): array
{
    return [
        'con_email' => 'Todos los clientes con correo',
        'con_deuda' => 'Clientes con saldo pendiente',
    ];
}

/**
 * Destinatarios de un segmento: clientes activos con un correo válido.
 * Devuelve filas [id, nombre, email], sin duplicar correos.
 */
function campanaDestinatarios(string $segmento): array
{
    $cond = "activo = 1 AND email IS NOT NULL AND email <> ''";
    if ($segmento === 'con_deuda') $cond .= " AND balance > 0";

    $rows = qAll("SELECT id, nombre, email FROM clientes WHERE $cond ORDER BY nombre");

    // Filtrar correos inválidos y de-duplicar por correo (en minúsculas).
    $vistos = [];
    $out = [];
    foreach ($rows as $r) {
        $email = strtolower(trim($r['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($vistos[$email])) continue;
        $vistos[$email] = true;
        $out[] = $r;
    }
    return $out;
}

/** Cuántos destinatarios recibiría un segmento (para mostrar antes de enviar). */
function campanaConteo(string $segmento): int
{
    return count(campanaDestinatarios($segmento));
}

/**
 * Envía una campaña que esté en 'borrador'. Idempotente por estado: una campaña
 * ya enviada no se reenvía. Devuelve ['ok','enviados','fallidos','total','error'].
 */
function campanaEnviar(int $campanaId): array
{
    $c = qOne("SELECT * FROM campanas WHERE id = ?", [$campanaId]);
    if (!$c) return ['ok' => false, 'error' => 'Campaña no encontrada.'];
    if ($c['estado'] !== 'borrador') return ['ok' => false, 'error' => 'Esta campaña ya fue enviada.'];
    if (!mail_configurado()) return ['ok' => false, 'error' => 'El correo no está configurado (falta la clave de Resend).'];

    $destinatarios = campanaDestinatarios($c['segmento']);
    $total = count($destinatarios);
    if ($total === 0) return ['ok' => false, 'error' => 'No hay clientes con correo en este segmento.'];

    $empresa = $GLOBALS['empresa'] ?? [];
    $html = mail_plantilla($c['asunto'], $c['contenido'], $empresa, mb_substr(strip_tags($c['contenido']), 0, 120));

    $enviados = 0; $fallidos = 0;
    foreach (array_slice($destinatarios, 0, CAMPANA_TOPE) as $d) {
        $r = mail_enviar($d['email'], $c['asunto'], $html);
        if ($r['ok']) $enviados++; else $fallidos++;
        // Registro por destinatario (con campana_id).
        try {
            dbInsert('correos_enviados', [
                'pedido_id'    => null,
                'campana_id'   => $campanaId,
                'evento'       => 'campana',
                'destinatario' => $d['email'],
                'asunto'       => mb_substr($c['asunto'], 0, 180),
                'estado'       => $r['ok'] ? 'enviado' : 'fallido',
                'proveedor_id' => $r['id'],
                'error'        => $r['error'] ? mb_substr($r['error'], 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            // El registro no puede tumbar el envío.
        }
    }

    $estado = $fallidos === 0 ? 'enviada' : ($enviados === 0 ? 'borrador' : 'parcial');
    dbUpdate('campanas', [
        'estado' => $estado, 'total' => $total, 'enviados' => $enviados, 'fallidos' => $fallidos,
        'enviada_at' => $enviados > 0 ? date('Y-m-d H:i:s') : null,
    ], 'id = ?', [$campanaId]);

    return ['ok' => $enviados > 0, 'enviados' => $enviados, 'fallidos' => $fallidos, 'total' => $total,
            'error' => $enviados === 0 ? 'No se pudo enviar ningún correo.' : null];
}
