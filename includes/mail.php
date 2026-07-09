<?php
/**
 * Correo saliente vía Resend.
 *
 * Regla de oro: un correo que falla NUNCA rompe la operación que lo disparó.
 * Un pedido se registra aunque Resend esté caído. Por eso ninguna función de
 * aquí lanza excepciones: devuelven el resultado y lo dejan registrado en la
 * tabla `correos_enviados`, que es donde se investiga qué pasó.
 */

/** ¿Hay configuración suficiente para enviar? */
function mail_configurado(): bool
{
    return RESEND_API_KEY !== '' && MAIL_FROM !== '';
}

/**
 * Envía un correo. Nunca lanza excepción.
 * @return array{ok:bool, id:?string, error:?string}
 */
function mail_enviar(string $para, string $asunto, string $html, array $opts = []): array
{
    if (!mail_configurado()) {
        return ['ok' => false, 'id' => null, 'error' => 'Correo no configurado (falta RESEND_API_KEY).'];
    }
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => null, 'error' => 'Destinatario inválido: ' . $para];
    }

    $cuerpo = [
        'from'    => $opts['from'] ?? MAIL_FROM,
        'to'      => [$para],
        'subject' => $asunto,
        'html'    => $html,
    ];
    $replyTo = $opts['reply_to'] ?? MAIL_REPLY_TO;
    if ($replyTo !== '') $cuerpo['reply_to'] = $replyTo;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'id' => null, 'error' => 'No se pudo contactar a Resend: ' . $curlErr];
    }
    $data = json_decode($resp, true) ?: [];
    if ($code >= 200 && $code < 300 && !empty($data['id'])) {
        return ['ok' => true, 'id' => $data['id'], 'error' => null];
    }
    $msg = $data['message'] ?? $data['error']['message'] ?? substr($resp, 0, 180);
    return ['ok' => false, 'id' => null, 'error' => "Resend respondió $code: $msg"];
}

/** Deja constancia del intento. Nunca interrumpe. */
function mail_registrar(?int $pedidoId, string $evento, string $destinatario, string $asunto, array $r): void
{
    try {
        dbInsert('correos_enviados', [
            'pedido_id'    => $pedidoId,
            'evento'       => $evento,
            'destinatario' => $destinatario,
            'asunto'       => mb_substr($asunto, 0, 180),
            'estado'       => $r['ok'] ? 'enviado' : 'fallido',
            'proveedor_id' => $r['id'],
            'error'        => $r['error'] ? mb_substr($r['error'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        // Ni siquiera el registro puede tumbar la operación.
    }
}

// ---------------------------------------------------------------------------
//  Plantilla HTML
// ---------------------------------------------------------------------------

/**
 * Envoltorio del correo. Tablas e estilos en línea: es lo único que renderizan
 * bien Gmail, Outlook y los clientes móviles.
 */
function mail_plantilla(string $titulo, string $contenido, array $empresa, string $preheader = ''): string
{
    $marca = '#15803D';
    $nombre = e($empresa['nombre'] ?? APP_NAME);
    $pie = $empresa['telefono'] ?? '';

    return '<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . e($titulo) . '</title></head>
<body style="margin:0;padding:0;background:#F0FDF4;">
<span style="display:none;font-size:1px;color:#F0FDF4;max-height:0;overflow:hidden;">' . e($preheader) . '</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F0FDF4;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #D1FAE5;">
      <tr>
        <td style="background:' . $marca . ';padding:20px 24px;">
          <p style="margin:0;font:600 18px/1.3 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#ffffff;">' . $nombre . '</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px 24px;font:400 15px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#14532D;">
          ' . $contenido . '
        </td>
      </tr>
      <tr>
        <td style="padding:18px 24px;border-top:1px solid #D1FAE5;font:400 12px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#4B7A5A;">
          ' . $nombre . ($pie ? ' &middot; Tel. ' . e($pie) : '') . '<br>
          Este correo se envió automáticamente. No hace falta responderlo.
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';
}

/** Tabla de líneas del pedido, para reutilizar en varios correos. */
function mail_tabla_pedido(array $pedido, array $detalles): string
{
    $filas = '';
    foreach ($detalles as $d) {
        $filas .= '<tr>
            <td style="padding:8px 0;border-bottom:1px solid #ECFDF5;">' . e($d['descripcion']) . '
              <span style="color:#4B7A5A;">&times; ' . qty($d['cantidad']) . '</span></td>
            <td style="padding:8px 0;border-bottom:1px solid #ECFDF5;text-align:right;white-space:nowrap;">' . money($d['subtotal']) . '</td>
        </tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="font:400 14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#14532D;margin:8px 0 4px;">
        ' . $filas . '
        <tr><td style="padding:8px 0;color:#4B7A5A;">Subtotal</td>
            <td style="padding:8px 0;text-align:right;">' . money($pedido['subtotal']) . '</td></tr>
        <tr><td style="padding:2px 0;color:#4B7A5A;">ITBIS</td>
            <td style="padding:2px 0;text-align:right;">' . money($pedido['itbis']) . '</td></tr>
        <tr><td style="padding:10px 0 0;font-weight:700;font-size:16px;">Total</td>
            <td style="padding:10px 0 0;text-align:right;font-weight:700;font-size:16px;color:#15803D;">' . money($pedido['total']) . '</td></tr>
    </table>';
}

/** Botón de acción. Se dibuja con tabla porque Outlook ignora padding en <a>. */
function mail_boton(string $texto, string $url, string $color = '#0369A1'): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;">
      <tr><td style="background:' . $color . ';border-radius:10px;">
        <a href="' . e($url) . '" style="display:inline-block;padding:12px 22px;font:600 15px/1 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#ffffff;text-decoration:none;">' . e($texto) . '</a>
      </td></tr></table>';
}
