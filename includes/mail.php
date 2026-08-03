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

    // Adjuntos: [['filename' => 'cotizacion.pdf', 'content' => base64], ...]
    if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
        $cuerpo['attachments'] = array_values(array_filter($opts['attachments'],
            fn($a) => !empty($a['filename']) && !empty($a['content'])));
    }

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

/**
 * Envía varios correos en una sola petición (endpoint `/emails/batch` de Resend,
 * hasta 100 por llamada). Mil correos uno por uno tardan minutos; en lotes, segundos.
 *
 * Si el lote entero falla (red caída, key inválida), se reintenta uno por uno:
 * más lento, pero así un fallo de la API por lotes no deja la campaña parada.
 *
 * @param array $mensajes lista de ['para','asunto','html']
 * @return array Resultados EN EL MISMO ORDEN: [['ok','id','error'], ...]
 */
function mail_enviar_lote(array $mensajes): array
{
    $n = count($mensajes);
    if ($n === 0) return [];
    if ($n === 1) {
        return [mail_enviar($mensajes[0]['para'], $mensajes[0]['asunto'], $mensajes[0]['html'])];
    }
    if (!mail_configurado()) {
        return array_fill(0, $n, ['ok' => false, 'id' => null, 'error' => 'Correo no configurado (falta RESEND_API_KEY).']);
    }

    // Los destinatarios inválidos no viajan al proveedor: rompen el lote entero.
    $cuerpo = [];
    $indices = [];
    $out = array_fill(0, $n, null);
    foreach ($mensajes as $i => $m) {
        if (!filter_var($m['para'], FILTER_VALIDATE_EMAIL)) {
            $out[$i] = ['ok' => false, 'id' => null, 'error' => 'Destinatario inválido: ' . $m['para']];
            continue;
        }
        $item = [
            'from'    => MAIL_FROM,
            'to'      => [$m['para']],
            'subject' => $m['asunto'],
            'html'    => $m['html'],
        ];
        if (MAIL_REPLY_TO !== '') $item['reply_to'] = MAIL_REPLY_TO;
        $indices[] = $i;
        $cuerpo[]  = $item;
    }
    if (!$cuerpo) return $out;

    $ch = curl_init('https://api.resend.com/emails/batch');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = $resp === false ? [] : (json_decode($resp, true) ?: []);
    $ids  = $data['data'] ?? null;

    if ($code >= 200 && $code < 300 && is_array($ids)) {
        foreach ($indices as $k => $i) {
            $id = $ids[$k]['id'] ?? null;
            $out[$i] = $id
                ? ['ok' => true, 'id' => $id, 'error' => null]
                : ['ok' => false, 'id' => null, 'error' => 'Resend no devolvió id para este correo.'];
        }
        return $out;
    }

    // Respaldo: el lote no pasó, se intenta uno por uno.
    foreach ($indices as $i) {
        $m = $mensajes[$i];
        $out[$i] = mail_enviar($m['para'], $m['asunto'], $m['html']);
    }
    return $out;
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

/** Valores de fábrica del diseño del correo. */
function mail_diseno_defaults(): array
{
    return [
        'color'        => '#15803D',   // barra superior
        'color_boton'  => '#15803D',   // botones de acción
        'fondo'        => '#F1F5F9',   // fondo de la página (neutro: sirve con cualquier marca)
        'mostrar_logo' => 1,
        'pie'          => '',          // texto libre bajo los datos de la empresa
    ];
}

/**
 * Diseño visual del correo. Se personaliza en Marketing → Diseño del correo y
 * se guarda en la tabla `empresa` (las columnas pueden no existir todavía si la
 * migración P10 no está aplicada: por eso todo pasa por el valor de fábrica).
 *
 * Pasar $forzar sirve para la vista previa: permite dibujar el correo con unos
 * colores que aún no se han guardado.
 */
function mail_diseno(?array $forzar = null): array
{
    static $override = null;
    if ($forzar !== null) $override = $forzar;

    $def = mail_diseno_defaults();
    if ($override !== null) return array_merge($def, array_filter($override, fn($v) => $v !== null && $v !== ''));

    $e = $GLOBALS['empresa'] ?? [];
    return [
        'color'        => $e['mkt_color']        ?? $def['color'],
        'color_boton'  => $e['mkt_color_boton']  ?? $def['color_boton'],
        'fondo'        => $e['mkt_fondo']        ?? $def['fondo'],
        'mostrar_logo' => (int) ($e['mkt_mostrar_logo'] ?? $def['mostrar_logo']),
        'pie'          => (string) ($e['mkt_pie'] ?? $def['pie']),
    ];
}

/**
 * Mezcla un color con blanco (0 = igual, 1 = blanco). Sirve para sacar el fondo
 * suave del cupón a partir del color de marca, sea cual sea: así el bloque de
 * promoción combina con una marca azul, roja o morada sin tocar nada más.
 */
function mail_color_claro(string $hex, float $mezcla = 0.88): string
{
    $hex = mail_color($hex, '#15803D');
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $m = max(0.0, min(1.0, $mezcla));
    return sprintf('#%02X%02X%02X',
        (int) round($r + (255 - $r) * $m),
        (int) round($g + (255 - $g) * $m),
        (int) round($b + (255 - $b) * $m));
}

/** Un color de usuario nunca entra crudo en el HTML del correo. */
function mail_color(?string $c, string $porDefecto): string
{
    $c = trim((string) $c);
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : $porDefecto;
}

/**
 * Envoltorio del correo. Tablas y estilos en línea: es lo único que renderizan
 * bien Gmail, Outlook y los clientes móviles.
 *
 * La paleta que rodea al contenido es NEUTRA a propósito. Antes todo estaba
 * teñido de verde; en cuanto alguien pusiera su marca en azul, el correo se veía
 * roto. Ahora el color de marca manda solo en la barra y el botón, y el resto
 * funciona con cualquier color.
 */
function mail_plantilla(string $titulo, string $contenido, array $empresa, string $preheader = ''): string
{
    $d      = mail_diseno();
    $marca  = mail_color($d['color'], '#15803D');
    $fondo  = mail_color($d['fondo'], '#F1F5F9');
    $nombre = e($empresa['nombre'] ?? APP_NAME);
    $tel    = $empresa['telefono'] ?? '';
    $pie    = trim((string) $d['pie']);

    // Cabecera: el logo si lo hay y está activado; si no, el nombre.
    $logo = trim((string) ($empresa['logo'] ?? ''));
    $cabecera = ($d['mostrar_logo'] && $logo !== '' && function_exists('mkt_url_abs'))
        ? '<img src="' . e(mkt_url_abs($logo)) . '" alt="' . $nombre . '" height="34"
                style="height:34px;max-height:34px;width:auto;display:block;border:0;">'
        : '<p style="margin:0;font:600 18px/1.3 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#ffffff;">' . $nombre . '</p>';

    return '<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . e($titulo) . '</title></head>
<body style="margin:0;padding:0;background:' . $fondo . ';">
<span style="display:none;font-size:1px;color:' . $fondo . ';max-height:0;overflow:hidden;">' . e($preheader) . '</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $fondo . ';padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #E2E8F0;">
      <tr>
        <td style="background:' . $marca . ';padding:20px 24px;">' . $cabecera . '</td>
      </tr>
      <tr>
        <td style="padding:28px 24px;font:400 15px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#334155;">
          ' . $contenido . '
        </td>
      </tr>
      <tr>
        <td style="padding:18px 24px;border-top:1px solid #E2E8F0;font:400 12px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#64748B;">
          ' . $nombre . ($tel ? ' &middot; Tel. ' . e($tel) : '') . '<br>'
          . ($pie !== '' ? e($pie) : 'Este correo se envió automáticamente. No hace falta responderlo.') . '
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
function mail_boton(string $texto, string $url, string $color = ''): string
{
    // Sin color explícito manda el de la marca configurada.
    $color = mail_color($color !== '' ? $color : mail_diseno()['color_boton'], '#0369A1');

    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;">
      <tr><td style="background:' . $color . ';border-radius:10px;">
        <a href="' . e($url) . '" style="display:inline-block;padding:12px 22px;font:600 15px/1 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#ffffff;text-decoration:none;">' . e($texto) . '</a>
      </td></tr></table>';
}
