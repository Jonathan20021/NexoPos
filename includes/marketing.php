<?php
/**
 * Motor de marketing: segmentos, campañas, envío y automatizaciones.
 *
 * Dos canales, dos naturalezas distintas:
 *
 *   · CORREO (Resend) — se envía solo, del servidor al cliente, sin que nadie
 *     toque nada. Es el canal automático de verdad.
 *   · WHATSAPP (wa.me) — wa.me es un enlace, no una API: abre la conversación
 *     con el mensaje ya escrito, pero el botón «enviar» lo pulsa una persona.
 *     Por eso el sistema prepara TODO (número normalizado, mensaje personalizado,
 *     enlace con rastreo, cola ordenada) y la consola de envío lo despacha de un
 *     clic por cliente. Prometer envío automático por wa.me sería mentira.
 *
 * Regla de oro heredada de includes/mail.php: un envío que falla nunca rompe la
 * operación que lo disparó. Aquí, además, nunca se pierde el trabajo: cada
 * destinatario es una fila en `campana_envios`, así que un envío interrumpido se
 * reanuda donde quedó y nadie recibe el mismo correo dos veces.
 */

const MKT_LOTE          = 80;   // destinatarios por corrida (una llamada a Resend)
const MKT_TICK_MINUTOS  = 2;    // cada cuánto se despierta el motor de fondo
const MKT_TOPE_AUDIENCIA = 5000; // techo duro de destinatarios por campaña
const MKT_ATRIBUCION_DIAS = 14;  // ventana para atribuir una venta a una campaña

/* ============================================================
 *  Disponibilidad
 * ============================================================ */

/** ¿Está aplicada la migración P9? Evita romper una base sin actualizar. */
function mkt_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'campana_envios'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/* ============================================================
 *  Teléfonos y WhatsApp
 * ============================================================ */

/**
 * Normaliza un teléfono al formato que wa.me entiende: solo dígitos, con
 * código de país. República Dominicana usa 809/829/849 con prefijo 1.
 * Devuelve '' si no hay un número usable.
 */
function mkt_telefono(?string $tel): string
{
    $d = preg_replace('/\D+/', '', (string) $tel);
    if ($d === '') return '';

    // 8095551234 → 18095551234
    if (strlen($d) === 10 && preg_match('/^(809|829|849)/', $d)) return '1' . $d;
    // 18095551234 (ya completo)
    if (strlen($d) === 11 && $d[0] === '1') return $d;
    // 5551234 sin código de área: no se puede adivinar, se descarta.
    if (strlen($d) < 10) return '';
    return $d;   // número internacional de otro país
}

/** Teléfono legible: +1 (809) 555-1234 */
function mkt_telefono_bonito(string $normalizado): string
{
    if (strlen($normalizado) === 11 && $normalizado[0] === '1') {
        return '+1 (' . substr($normalizado, 1, 3) . ') ' . substr($normalizado, 4, 3) . '-' . substr($normalizado, 7);
    }
    return $normalizado === '' ? '—' : '+' . $normalizado;
}

/** Enlace wa.me con el mensaje ya escrito. */
function mkt_wa_link(string $telefonoNormalizado, string $mensaje): string
{
    if ($telefonoNormalizado === '') return '';
    return 'https://wa.me/' . $telefonoNormalizado . '?text=' . rawurlencode($mensaje);
}

/* ============================================================
 *  Variables de personalización
 * ============================================================ */

/** Catálogo de variables, para mostrarlo en el editor. */
function mkt_variables_catalogo(): array
{
    return [
        '{{cliente}}'   => 'Nombre del cliente',
        '{{nombre}}'    => 'Solo el primer nombre',
        '{{empresa}}'   => 'Nombre de tu empresa',
        '{{telefono}}'  => 'Teléfono de tu empresa',
        '{{promo}}'     => 'Nombre de la promoción destacada',
        '{{descuento}}' => 'Descuento de la promoción (20% o RD$ 500.00)',
        '{{vigencia}}'  => 'Vigencia de la promoción («hasta el 31/12/2026»)',
        '{{saldo}}'     => 'Saldo pendiente del cliente',
        '{{tienda}}'    => 'Enlace a tu tienda en línea',
    ];
}

/** Valores concretos para un cliente y una promoción. */
function mkt_variables(array $cliente, ?array $promo = null): array
{
    $emp = $GLOBALS['empresa'] ?? [];
    $nombre = trim((string) ($cliente['nombre'] ?? ''));
    $partes = preg_split('/\s+/', $nombre);

    $descuento = '';
    $vigencia  = '';
    if ($promo) {
        $descuento = $promo['tipo'] === 'porcentaje'
            ? rtrim(rtrim(number_format((float) $promo['valor'], 2), '0'), '.') . '%'
            : money((float) $promo['valor']);
        $vigencia = 'hasta el ' . fechaCorta($promo['fecha_fin']);
    }

    return [
        '{{cliente}}'   => $nombre !== '' ? $nombre : 'estimado cliente',
        '{{nombre}}'    => $partes[0] ?? 'Hola',
        '{{empresa}}'   => (string) ($emp['nombre'] ?? APP_NAME),
        '{{telefono}}'  => (string) ($emp['telefono'] ?? ''),
        '{{promo}}'     => $promo['nombre'] ?? 'nuestra promoción',
        '{{descuento}}' => $descuento !== '' ? $descuento : 'un descuento especial',
        '{{vigencia}}'  => $vigencia !== '' ? $vigencia : 'por tiempo limitado',
        '{{saldo}}'     => money((float) ($cliente['balance'] ?? 0)),
        '{{tienda}}'    => mkt_url_abs('tienda/index.php'),
    ];
}

/** Sustituye las variables de un texto. */
function mkt_render(string $texto, array $vars): string
{
    return strtr($texto, $vars);
}

/**
 * HTML permitido en el cuerpo de una campaña. El contenido lo escribe personal
 * con permiso, pero se ve en pantalla y en la vista previa: no se deja pasar
 * script ni manejadores de eventos.
 */
function mkt_html_seguro(string $html): string
{
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|form)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
    $html = preg_replace('#<\s*/?\s*(script|style|iframe|object|embed|form)\b[^>]*>#i', '', $html);
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1="#"', $html);
    return $html;
}

/* ============================================================
 *  Segmentos
 * ============================================================ */

/** Valores por defecto de un segmento nuevo. */
function mkt_segmento_defaults(): array
{
    return [
        'id' => 0, 'nombre' => '', 'descripcion' => '',
        'requiere_email' => 1, 'requiere_telefono' => 0,
        'tipo_cliente' => 'cualquiera', 'deuda' => 'cualquiera',
        'sucursal_id' => null, 'categoria_id' => null,
        'dias_sin_comprar_min' => null, 'dias_sin_comprar_max' => null,
        'incluir_sin_compras' => 1,
        'compras_min' => null, 'gasto_min' => null, 'gasto_max' => null,
        'cumple_mes' => 0, 'activo' => 1,
    ];
}

/**
 * Traduce las reglas de un segmento a SQL.
 *
 * El histórico de cada cliente (número de compras, gasto y última compra) se
 * calcula una sola vez en una subconsulta agrupada; nunca una consulta por
 * cliente. `gastado` es el total facturado (con ITBIS): es lo que el cliente
 * realmente pagó, que es la medida útil para segmentar.
 *
 * @return array [$sql, $params]
 */
function mkt_segmento_sql(array $seg, string $canal = 'email'): array
{
    $w = ['c.activo = 1'];
    $p = [];

    // Consentimiento: solo se excluye a quien pidió no recibir promociones.
    $w[] = 'c.acepta_marketing = 1';

    if ($canal === 'whatsapp' || !empty($seg['requiere_telefono'])) {
        $w[] = "c.telefono IS NOT NULL AND c.telefono <> ''";
    }
    if ($canal === 'email' || !empty($seg['requiere_email'])) {
        $w[] = "c.email IS NOT NULL AND c.email <> '' AND c.email LIKE '%_@_%.__%'";
    }

    if (($seg['tipo_cliente'] ?? 'cualquiera') !== 'cualquiera') {
        $w[] = 'c.tipo = ?';
        $p[] = $seg['tipo_cliente'];
    }
    if (($seg['deuda'] ?? 'cualquiera') === 'con')  $w[] = 'c.balance > 0';
    if (($seg['deuda'] ?? 'cualquiera') === 'sin')  $w[] = 'c.balance <= 0';

    // Cumpleaños: 1..12 mes fijo, 13 = el mes en curso.
    $mes = (int) ($seg['cumple_mes'] ?? 0);
    if ($mes === 13) {
        $w[] = 'c.fecha_nacimiento IS NOT NULL AND MONTH(c.fecha_nacimiento) = MONTH(CURDATE())';
    } elseif ($mes >= 1 && $mes <= 12) {
        $w[] = 'c.fecha_nacimiento IS NOT NULL AND MONTH(c.fecha_nacimiento) = ?';
        $p[] = $mes;
    }

    // Compró alguna vez en una sucursal / de una categoría.
    if (!empty($seg['sucursal_id'])) {
        $w[] = "EXISTS (SELECT 1 FROM ventas v2 WHERE v2.cliente_id = c.id AND v2.estado = 'completada' AND v2.sucursal_id = ?)";
        $p[] = (int) $seg['sucursal_id'];
    }
    if (!empty($seg['categoria_id'])) {
        $w[] = "EXISTS (SELECT 1 FROM ventas v3
                         JOIN venta_detalles vd ON vd.venta_id = v3.id
                         JOIN productos pr ON pr.id = vd.producto_id
                        WHERE v3.cliente_id = c.id AND v3.estado = 'completada' AND pr.categoria_id = ?)";
        $p[] = (int) $seg['categoria_id'];
    }

    // Recencia, frecuencia y monto sobre el histórico agregado.
    $tieneHistorico = false;
    $hw = [];
    if (($n = $seg['dias_sin_comprar_min'] ?? null) !== null && $n !== '') {
        $hw[] = 'h.ultima <= (CURDATE() - INTERVAL ? DAY)'; $p[] = (int) $n; $tieneHistorico = true;
    }
    if (($n = $seg['dias_sin_comprar_max'] ?? null) !== null && $n !== '') {
        $hw[] = 'h.ultima >= (CURDATE() - INTERVAL ? DAY)'; $p[] = (int) $n; $tieneHistorico = true;
    }
    if (($n = $seg['compras_min'] ?? null) !== null && $n !== '') {
        $hw[] = 'h.compras >= ?'; $p[] = (int) $n; $tieneHistorico = true;
    }
    if (($n = $seg['gasto_min'] ?? null) !== null && $n !== '') {
        $hw[] = 'h.gastado >= ?'; $p[] = (float) $n; $tieneHistorico = true;
    }
    if (($n = $seg['gasto_max'] ?? null) !== null && $n !== '') {
        $hw[] = 'h.gastado <= ?'; $p[] = (float) $n; $tieneHistorico = true;
    }

    if ($tieneHistorico) {
        $cond = implode(' AND ', $hw);
        // «Incluir sin compras» decide si los clientes sin historial entran igual.
        $w[] = empty($seg['incluir_sin_compras'])
            ? "($cond)"
            : "(h.cliente_id IS NULL OR ($cond))";
    } elseif (empty($seg['incluir_sin_compras'])) {
        $w[] = 'h.cliente_id IS NOT NULL';
    }

    // El histórico agregado solo se cruza cuando alguna regla lo necesita: con
    // 60.000 ventas, esa subconsulta es lo más caro de la pantalla, y segmentos
    // como «cumpleañeros del mes» o «con saldo pendiente» no la usan para nada.
    $necesitaHistorico = $tieneHistorico || empty($seg['incluir_sin_compras']);

    $select = $necesitaHistorico
        ? "c.id, c.nombre, c.email, c.telefono, c.balance, c.fecha_nacimiento,
           COALESCE(h.compras, 0) AS compras, COALESCE(h.gastado, 0) AS gastado, h.ultima"
        : "c.id, c.nombre, c.email, c.telefono, c.balance, c.fecha_nacimiento,
           0 AS compras, 0 AS gastado, NULL AS ultima";

    $join = $necesitaHistorico
        ? "LEFT JOIN (SELECT v.cliente_id, COUNT(*) AS compras, SUM(v.total) AS gastado, MAX(v.fecha) AS ultima
                        FROM ventas v
                       WHERE v.estado = 'completada' AND v.cliente_id IS NOT NULL
                       GROUP BY v.cliente_id) h ON h.cliente_id = c.id"
        : '';

    $sql = "SELECT $select
              FROM clientes c
              $join
             WHERE " . implode("\n               AND ", $w) . "
             ORDER BY c.nombre";

    return [$sql, $p];
}

/** Destinos dados de baja, como conjunto para filtrar en memoria. */
function mkt_bajas(string $canal): array
{
    static $cache = [];
    if (isset($cache[$canal])) return $cache[$canal];
    $set = [];
    foreach (qCol("SELECT destino FROM marketing_bajas WHERE canal = ?", [$canal]) as $d) {
        $set[strtolower($d)] = true;
    }
    return $cache[$canal] = $set;
}

/**
 * Destinatarios reales de un segmento por un canal: ya sin bajas, sin duplicados
 * y con el destino normalizado y validado.
 *
 * @return array filas [cliente_id, nombre, destino, balance, compras, gastado, ultima]
 */
function mkt_destinatarios(array $seg, string $canal, int $tope = MKT_TOPE_AUDIENCIA): array
{
    [$sql, $params] = mkt_segmento_sql($seg, $canal);
    $rows  = qAll($sql, $params);
    $bajas = mkt_bajas($canal);

    $vistos = [];
    $out = [];
    foreach ($rows as $r) {
        if ($canal === 'email') {
            $destino = strtolower(trim((string) $r['email']));
            if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) continue;
        } else {
            $destino = mkt_telefono($r['telefono']);
            if ($destino === '') continue;
        }
        if (isset($vistos[$destino]) || isset($bajas[strtolower($destino)])) continue;
        $vistos[$destino] = true;

        $out[] = [
            'cliente_id' => (int) $r['id'],
            'nombre'     => $r['nombre'],
            'destino'    => $destino,
            'balance'    => (float) $r['balance'],
            'compras'    => (int) $r['compras'],
            'gastado'    => (float) $r['gastado'],
            'ultima'     => $r['ultima'],
        ];
        if (count($out) >= $tope) break;
    }
    return $out;
}

/** Cuántos recibirían: se muestra antes de enviar, nunca después. */
function mkt_conteo(array $seg, string $canal): int
{
    return count(mkt_destinatarios($seg, $canal));
}

/** Segmento por id, o el «todos» implícito si no hay ninguno. */
function mkt_segmento(?int $id): array
{
    if ($id) {
        $s = qOne("SELECT * FROM marketing_segmentos WHERE id = ?", [$id]);
        if ($s) return $s;
    }
    return mkt_segmento_defaults();
}

/** Resumen legible de las reglas de un segmento (para las tarjetas). */
function mkt_segmento_reglas(array $s): array
{
    $r = [];
    if (!empty($s['requiere_email']))    $r[] = 'Con correo';
    if (!empty($s['requiere_telefono'])) $r[] = 'Con teléfono';
    if (($s['tipo_cliente'] ?? '') === 'contado') $r[] = 'De contado';
    if (($s['tipo_cliente'] ?? '') === 'credito') $r[] = 'A crédito';
    if (($s['deuda'] ?? '') === 'con') $r[] = 'Con saldo pendiente';
    if (($s['deuda'] ?? '') === 'sin') $r[] = 'Sin deuda';
    if (!empty($s['dias_sin_comprar_min'])) $r[] = 'Sin comprar hace ' . (int) $s['dias_sin_comprar_min'] . '+ días';
    if (!empty($s['dias_sin_comprar_max'])) $r[] = 'Compró en los últimos ' . (int) $s['dias_sin_comprar_max'] . ' días';
    if (!empty($s['compras_min'])) $r[] = (int) $s['compras_min'] . '+ compras';
    if (!empty($s['gasto_min']))   $r[] = 'Gastó ' . money((float) $s['gasto_min']) . '+';
    if (!empty($s['gasto_max']))   $r[] = 'Gastó hasta ' . money((float) $s['gasto_max']);
    if ((int) ($s['cumple_mes'] ?? 0) === 13) $r[] = 'Cumple este mes';
    elseif ((int) ($s['cumple_mes'] ?? 0) > 0) $r[] = 'Cumple en ' . mesNombre((int) $s['cumple_mes']);
    if (!empty($s['sucursal_id']))  $r[] = 'Compró en una sucursal';
    if (!empty($s['categoria_id'])) $r[] = 'Compró de una categoría';
    if (empty($s['incluir_sin_compras'])) $r[] = 'Solo con compras';
    return $r ?: ['Todos los clientes activos'];
}

/* ============================================================
 *  Composición del correo
 * ============================================================ */

/**
 * URL absoluta (con dominio) de una ruta del sistema.
 *
 * En un correo no valen las rutas relativas: el enlace se abre desde Gmail, no
 * desde tu dominio. Orden de preferencia: APP_URL si es absoluta, la variable de
 * entorno APP_PUBLIC_URL, y por último la petición en curso. Bajo cron (CLI) no
 * hay petición: ahí es obligatorio definir una de las dos primeras.
 */
function mkt_url_abs(string $path = ''): string
{
    static $raiz = null;
    if ($raiz === null) {
        if (APP_URL !== '' && preg_match('#^https?://#i', APP_URL)) {
            $raiz = '';                       // url() ya devuelve absoluta
        } elseif (($env = trim((string) getenv('APP_PUBLIC_URL'))) !== '') {
            $raiz = rtrim($env, '/');
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $esquema = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
            $raiz = $esquema . '://' . $_SERVER['HTTP_HOST'];
        } else {
            $raiz = '';
        }
    }
    return $raiz . url($path);
}

/** URL absoluta del rastreador. */
function mkt_url_rastreo(string $token, string $accion, string $destino = ''): string
{
    $u = mkt_url_abs('modules/marketing/t.php') . '?t=' . $token . '&a=' . $accion;
    if ($destino !== '') $u .= '&u=' . rawurlencode($destino);
    return $u;
}

/** Bloque visual de la promoción destacada (tabla, para que Outlook lo respete). */
function mkt_bloque_promo(array $promo): string
{
    $valor = $promo['tipo'] === 'porcentaje'
        ? rtrim(rtrim(number_format((float) $promo['valor'], 2), '0'), '.') . '%'
        : money((float) $promo['valor']);

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="margin:20px 0;border:2px dashed #15803D;border-radius:14px;background:#F0FDF4;">
      <tr><td style="padding:18px 20px;text-align:center;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;">
        <p style="margin:0 0 4px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#15803D;font-weight:700;">Promoción</p>
        <p style="margin:0;font-size:30px;line-height:1.1;font-weight:800;color:#14532D;">' . e($valor) . ' de descuento</p>
        <p style="margin:6px 0 0;font-size:16px;font-weight:600;color:#166534;">' . e($promo['nombre']) . '</p>'
        . (!empty($promo['descripcion']) ? '<p style="margin:6px 0 0;font-size:14px;color:#4B7A5A;">' . e($promo['descripcion']) . '</p>' : '')
        . '<p style="margin:10px 0 0;font-size:13px;color:#4B7A5A;">Vigente hasta el ' . e(fechaCorta($promo['fecha_fin'])) . '</p>
      </td></tr>
    </table>';
}

/**
 * Cuerpo completo del correo de una campaña, ya personalizado.
 *
 * $envio puede venir vacío (vista previa): sin token no se inyecta rastreo, y
 * el enlace de baja apunta a la página informativa.
 */
function mkt_html_correo(array $c, array $cliente, array $envio = []): string
{
    $promo = mkt_promo(isset($c['promocion_id']) ? (int) $c['promocion_id'] : null);
    $vars  = mkt_variables($cliente, $promo);
    $token = $envio['token'] ?? '';

    $cuerpo = mkt_html_seguro(mkt_render((string) $c['contenido'], $vars));

    if (!empty($c['imagen'])) {
        $cuerpo = '<img src="' . e(mkt_url_abs($c['imagen'])) . '" alt="" style="width:100%;max-width:512px;border-radius:12px;display:block;margin:0 0 18px;">' . $cuerpo;
    }
    if ($promo) {
        $cuerpo .= mkt_bloque_promo($promo);
    }
    if (!empty($c['cta_texto']) && !empty($c['cta_url'])) {
        $destino = mkt_render((string) $c['cta_url'], $vars);
        $href = $token !== '' ? mkt_url_rastreo($token, 'c', $destino) : $destino;
        $cuerpo .= mail_boton($c['cta_texto'], $href, '#15803D');
    }

    // Baja: obligatoria en correo comercial. Sin ella, el dominio termina en spam.
    $urlBaja = mkt_url_abs('modules/marketing/baja.php') . ($token !== '' ? '?t=' . $token : '');
    $cuerpo .= '<p style="margin:26px 0 0;padding-top:14px;border-top:1px solid #ECFDF5;font:400 12px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#8AA79A;">'
        . 'Recibes este correo porque eres cliente de ' . e($vars['{{empresa}}']) . '. '
        . '<a href="' . e($urlBaja) . '" style="color:#4B7A5A;">Dejar de recibir promociones</a>.</p>';

    if ($token !== '') {
        $cuerpo .= '<img src="' . e(mkt_url_rastreo($token, 'o')) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
    }

    $asunto    = mkt_render(mkt_asunto_variante($c, $envio['variante'] ?? 'A'), $vars);
    $preheader = mkt_render((string) ($c['preheader'] ?? ''), $vars);
    if ($preheader === '') $preheader = mb_substr(trim(strip_tags($cuerpo)), 0, 120);

    return mail_plantilla($asunto, $cuerpo, $GLOBALS['empresa'] ?? [], $preheader);
}

/** Asunto según la variante de la prueba A/B. */
function mkt_asunto_variante(array $c, string $variante): string
{
    if ($variante === 'B' && !empty($c['asunto_b'])) return (string) $c['asunto_b'];
    return (string) $c['asunto'];
}

/** Mensaje de WhatsApp personalizado (texto plano; wa.me no admite HTML). */
function mkt_texto_whatsapp(array $c, array $cliente, array $envio = []): string
{
    $promo = mkt_promo(isset($c['promocion_id']) ? (int) $c['promocion_id'] : null);
    $vars  = mkt_variables($cliente, $promo);

    $texto = trim((string) ($c['whatsapp_texto'] ?? ''));
    if ($texto === '') {
        // Sin texto propio se degrada el cuerpo del correo a texto plano.
        $texto = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", (string) $c['contenido'])), ENT_QUOTES, 'UTF-8'));
    }
    $texto = mkt_render($texto, $vars);

    if (!empty($c['cta_url'])) {
        $destino = mkt_render((string) $c['cta_url'], $vars);
        $token   = $envio['token'] ?? '';
        $texto .= "\n\n" . ($token !== '' ? mkt_url_rastreo($token, 'c', $destino) : $destino);
    }
    return preg_replace("/\n{3,}/", "\n\n", $texto);
}

/* ============================================================
 *  Audiencia de una campaña
 * ============================================================ */

/** Promoción destacada de una campaña. Cacheada: se pide una vez por lote. */
function mkt_promo(?int $id): ?array
{
    static $cache = [];
    if (!$id) return null;
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = qOne("SELECT * FROM promociones WHERE id = ?", [$id]) ?: null;
    }
    return $cache[$id];
}

/** Token irrepetible de un envío (identifica aperturas, clics y bajas). */
function mkt_token(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Construye (o completa) la lista de destinatarios de una campaña.
 *
 * Es idempotente: la clave única (campana_id, canal, destino) impide duplicar a
 * nadie, así que se puede recalcular tras editar el segmento y solo se añaden
 * los que faltan. Los ya enviados no se tocan.
 *
 * @return array ['nuevos'=>int, 'total'=>int]
 */
function mkt_construir_audiencia(int $campanaId): array
{
    $c = qOne("SELECT * FROM campanas WHERE id = ?", [$campanaId]);
    if (!$c) return ['nuevos' => 0, 'total' => 0];

    $seg     = mkt_segmento($c['segmento_id'] ? (int) $c['segmento_id'] : null);
    $canales = $c['canal'] === 'ambos' ? ['email', 'whatsapp'] : [$c['canal']];
    $usaAB   = !empty($c['asunto_b']);
    $nuevos  = 0;
    $i = 0;

    foreach ($canales as $canal) {
        foreach (mkt_destinatarios($seg, $canal) as $d) {
            $st = q(
                "INSERT IGNORE INTO campana_envios
                    (campana_id, cliente_id, canal, destino, nombre, token, variante, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')",
                [$campanaId, $d['cliente_id'], $canal, $d['destino'], $d['nombre'], mkt_token(),
                 ($usaAB && $canal === 'email' && $i % 2 === 1) ? 'B' : 'A']
            );
            $nuevos += $st->rowCount();
            $i++;
        }
    }

    $total = (int) qVal("SELECT COUNT(*) FROM campana_envios WHERE campana_id = ?", [$campanaId]);
    dbUpdate('campanas', ['total' => $total, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$campanaId]);

    return ['nuevos' => $nuevos, 'total' => $total];
}

/** Recalcula los contadores de la campaña a partir de sus envíos. */
function mkt_recalcular(int $campanaId): array
{
    $r = qOne(
        "SELECT COUNT(*) total,
                SUM(estado = 'enviado')  enviados,
                SUM(estado = 'fallido')  fallidos,
                SUM(estado = 'pendiente') pendientes,
                SUM(abierto_at IS NOT NULL) aperturas,
                SUM(clic_at IS NOT NULL)    clics
           FROM campana_envios WHERE campana_id = ?",
        [$campanaId]
    ) ?: [];

    $datos = [
        'total'     => (int) ($r['total'] ?? 0),
        'enviados'  => (int) ($r['enviados'] ?? 0),
        'fallidos'  => (int) ($r['fallidos'] ?? 0),
        'aperturas' => (int) ($r['aperturas'] ?? 0),
        'clics'     => (int) ($r['clics'] ?? 0),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    dbUpdate('campanas', $datos, 'id = ?', [$campanaId]);
    $datos['pendientes'] = (int) ($r['pendientes'] ?? 0);
    return $datos;
}

/* ============================================================
 *  Envío
 * ============================================================ */

/**
 * Procesa un lote de correos pendientes de una campaña.
 *
 * Se envía en una sola llamada por lote (Resend acepta hasta 100 por petición),
 * y cada resultado se anota en su fila. WhatsApp NO se procesa aquí: sale por la
 * consola, de un clic por persona.
 *
 * @return array ['procesados','enviados','fallidos','pendientes','estado','error']
 */
function mkt_procesar_campana(int $campanaId, int $max = MKT_LOTE): array
{
    $c = qOne("SELECT * FROM campanas WHERE id = ?", [$campanaId]);
    if (!$c) return ['procesados' => 0, 'enviados' => 0, 'fallidos' => 0, 'pendientes' => 0, 'estado' => '', 'error' => 'Campaña no encontrada.'];
    if (in_array($c['estado'], ['pausada', 'cancelada', 'borrador'], true)) {
        return ['procesados' => 0, 'enviados' => 0, 'fallidos' => 0,
                'pendientes' => (int) qVal("SELECT COUNT(*) FROM campana_envios WHERE campana_id=? AND estado='pendiente' AND canal='email'", [$campanaId]),
                'estado' => $c['estado'], 'error' => 'La campaña no está en envío.'];
    }
    if (!mail_configurado()) {
        return ['procesados' => 0, 'enviados' => 0, 'fallidos' => 0, 'pendientes' => 0, 'estado' => $c['estado'],
                'error' => 'El correo no está configurado (falta la clave de Resend).'];
    }

    $pendientes = qAll(
        "SELECT * FROM campana_envios
          WHERE campana_id = ? AND canal = 'email' AND estado = 'pendiente'
          ORDER BY id LIMIT " . max(1, min(100, $max)),
        [$campanaId]
    );

    if (!$pendientes) {
        mkt_cerrar_si_termino($campanaId);
        $c2 = qOne("SELECT estado FROM campanas WHERE id = ?", [$campanaId]);
        return ['procesados' => 0, 'enviados' => 0, 'fallidos' => 0, 'pendientes' => 0,
                'estado' => $c2['estado'] ?? '', 'error' => null];
    }

    // Marcar la campaña como «enviando» en cuanto sale el primer lote.
    if ($c['estado'] !== 'enviando') {
        dbUpdate('campanas', ['estado' => 'enviando'], 'id = ?', [$campanaId]);
    }

    $mensajes = [];
    $clientes = [];
    foreach ($pendientes as $env) {
        $cliente = $env['cliente_id']
            ? (qOne("SELECT id, nombre, balance FROM clientes WHERE id = ?", [(int) $env['cliente_id']]) ?: [])
            : [];
        if (!$cliente) $cliente = ['nombre' => $env['nombre'], 'balance' => 0];
        $clientes[$env['id']] = $cliente;

        $mensajes[] = [
            'para'   => $env['destino'],
            'asunto' => mkt_render(mkt_asunto_variante($c, $env['variante']), mkt_variables($cliente, mkt_promo((int) $c['promocion_id']))),
            'html'   => mkt_html_correo($c, $cliente, $env),
        ];
    }

    $resultados = mail_enviar_lote($mensajes);

    $enviados = 0; $fallidos = 0;
    foreach ($pendientes as $i => $env) {
        $r = $resultados[$i] ?? ['ok' => false, 'id' => null, 'error' => 'Sin respuesta del proveedor.'];
        dbUpdate('campana_envios', [
            'estado'       => $r['ok'] ? 'enviado' : 'fallido',
            'proveedor_id' => $r['id'],
            'error'        => $r['error'] ? mb_substr($r['error'], 0, 255) : null,
            'enviado_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $env['id']]);

        if ($r['ok']) $enviados++; else $fallidos++;

        // Bitácora general de correo, la misma que usan los pedidos.
        try {
            dbInsert('correos_enviados', [
                'pedido_id'    => null,
                'campana_id'   => $campanaId,
                'evento'       => 'campana',
                'destinatario' => $env['destino'],
                'asunto'       => mb_substr($mensajes[$i]['asunto'], 0, 180),
                'estado'       => $r['ok'] ? 'enviado' : 'fallido',
                'proveedor_id' => $r['id'],
                'error'        => $r['error'] ? mb_substr($r['error'], 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            // El registro no puede tumbar el envío.
        }
    }

    $tot = mkt_recalcular($campanaId);
    mkt_cerrar_si_termino($campanaId);
    $estado = (string) qVal("SELECT estado FROM campanas WHERE id = ?", [$campanaId]);

    return [
        'procesados' => count($pendientes),
        'enviados'   => $enviados,
        'fallidos'   => $fallidos,
        'pendientes' => (int) qVal("SELECT COUNT(*) FROM campana_envios WHERE campana_id=? AND canal='email' AND estado='pendiente'", [$campanaId]),
        'estado'     => $estado,
        'error'      => null,
    ];
}

/** Cierra la campaña cuando ya no queda nadie pendiente por correo. */
function mkt_cerrar_si_termino(int $campanaId): void
{
    $pend = (int) qVal("SELECT COUNT(*) FROM campana_envios WHERE campana_id = ? AND canal = 'email' AND estado = 'pendiente'", [$campanaId]);
    if ($pend > 0) return;

    $c = qOne("SELECT estado, enviados, fallidos, enviada_at FROM campanas WHERE id = ?", [$campanaId]);
    if (!$c || !in_array($c['estado'], ['enviando', 'programada'], true)) return;

    $enviados = (int) $c['enviados'];
    $fallidos = (int) $c['fallidos'];

    // Sin un solo intento no hay nada que cerrar: vuelve a borrador. Marcarla
    // «enviada» con cero correos sería mentirle al historial (pasa cuando la
    // audiencia se queda vacía, por ejemplo si todos se dieron de baja).
    if ($enviados === 0 && $fallidos === 0) {
        dbUpdate('campanas', ['estado' => 'borrador', 'enviada_at' => null], 'id = ?', [$campanaId]);
        return;
    }

    $estado = $fallidos > 0 ? 'parcial' : 'enviada';
    dbUpdate('campanas', [
        'estado'     => $estado,
        'enviada_at' => $c['enviada_at'] ?: date('Y-m-d H:i:s'),
    ], 'id = ?', [$campanaId]);
}

/** Devuelve a «pendiente» los envíos fallidos, para reintentar. */
function mkt_reintentar_fallidos(int $campanaId): int
{
    $n = q("UPDATE campana_envios SET estado = 'pendiente', error = NULL, proveedor_id = NULL
             WHERE campana_id = ? AND estado = 'fallido'", [$campanaId])->rowCount();
    if ($n > 0) dbUpdate('campanas', ['estado' => 'enviando'], 'id = ?', [$campanaId]);
    mkt_recalcular($campanaId);
    return $n;
}

/* ============================================================
 *  Rastreo (aperturas, clics) y bajas
 * ============================================================ */

/** Marca la apertura de un correo. Silencioso: nunca debe fallar visiblemente. */
function mkt_registrar_apertura(string $token): void
{
    try {
        q("UPDATE campana_envios
              SET aperturas = aperturas + 1,
                  abierto_at = COALESCE(abierto_at, NOW())
            WHERE token = ?", [$token]);
        $cid = qVal("SELECT campana_id FROM campana_envios WHERE token = ?", [$token]);
        if ($cid) q("UPDATE campanas SET aperturas = (SELECT COUNT(*) FROM campana_envios WHERE campana_id = ? AND abierto_at IS NOT NULL) WHERE id = ?", [(int) $cid, (int) $cid]);
    } catch (Throwable $e) {
    }
}

/** Marca el clic y devuelve la campaña del envío. */
function mkt_registrar_clic(string $token): ?array
{
    try {
        $env = qOne("SELECT * FROM campana_envios WHERE token = ?", [$token]);
        if (!$env) return null;
        q("UPDATE campana_envios
              SET clics = clics + 1,
                  clic_at = COALESCE(clic_at, NOW()),
                  abierto_at = COALESCE(abierto_at, NOW())
            WHERE id = ?", [(int) $env['id']]);
        q("UPDATE campanas
              SET clics     = (SELECT COUNT(*) FROM campana_envios WHERE campana_id = ? AND clic_at IS NOT NULL),
                  aperturas = (SELECT COUNT(*) FROM campana_envios WHERE campana_id = ? AND abierto_at IS NOT NULL)
            WHERE id = ?", [(int) $env['campana_id'], (int) $env['campana_id'], (int) $env['campana_id']]);
        return $env;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Destinos a los que se puede redirigir tras un clic.
 *
 * Un redirector que acepta cualquier URL es un regalo para el phishing: el
 * enlace lleva TU dominio y termina donde quiera el atacante. Solo se permite
 * lo que la propia campaña publicó.
 */
function mkt_destino_permitido(array $campana, string $url): bool
{
    if ($url === '') return false;
    $candidatas = [];
    if (!empty($campana['cta_url'])) $candidatas[] = trim((string) $campana['cta_url']);
    if (preg_match_all('/https?:\/\/[^\s"\'<>]+/i', (string) $campana['contenido'] . ' ' . (string) $campana['whatsapp_texto'], $m)) {
        foreach ($m[0] as $u) $candidatas[] = rtrim($u, '.,);');
    }
    foreach ($candidatas as $c) {
        if (strcasecmp(rtrim($c, '/'), rtrim($url, '/')) === 0) return true;
    }
    // Además, cualquier URL del propio sistema.
    $base = rtrim(mkt_url_abs(''), '/');
    return $base !== '' && stripos($url, $base) === 0;
}

/** ¿Este destino pidió no recibir más? */
function mkt_dado_de_baja(string $canal, string $destino): bool
{
    return (bool) qVal("SELECT 1 FROM marketing_bajas WHERE canal = ? AND destino = ?", [$canal, strtolower($destino)]);
}

/** Registra una baja. Idempotente. */
function mkt_dar_de_baja(string $canal, string $destino, ?int $clienteId = null, ?int $campanaId = null, string $motivo = ''): void
{
    $destino = strtolower(trim($destino));
    if ($destino === '') return;
    q("INSERT IGNORE INTO marketing_bajas (canal, destino, cliente_id, campana_id, motivo)
       VALUES (?, ?, ?, ?, ?)", [$canal, $destino, $clienteId, $campanaId, mb_substr($motivo, 0, 180) ?: null]);

    if ($clienteId) {
        // El cliente deja de entrar en cualquier segmento futuro.
        q("UPDATE clientes SET acepta_marketing = 0 WHERE id = ?", [$clienteId]);
    }
    if ($campanaId) {
        q("UPDATE campanas SET bajas = (SELECT COUNT(*) FROM marketing_bajas WHERE campana_id = ?) WHERE id = ?", [$campanaId, $campanaId]);
    }
    // Un pendiente de alguien que se dio de baja no debe salir.
    q("UPDATE campana_envios SET estado = 'omitido', error = 'Dado de baja'
        WHERE canal = ? AND destino = ? AND estado = 'pendiente'", [$canal, $destino]);
}

/* ============================================================
 *  Métricas
 * ============================================================ */

/**
 * Ventas atribuidas a una campaña: compras de los destinatarios dentro de los
 * N días siguientes al envío. Es atribución por ventana, no causalidad
 * demostrada; sirve para comparar campañas entre sí, no para jurar que la venta
 * ocurrió «por» el correo.
 */
function mkt_ventas_atribuidas(int $campanaId, int $dias = MKT_ATRIBUCION_DIAS): array
{
    $r = qOne(
        "SELECT COUNT(DISTINCT v.id) AS ventas, COALESCE(SUM(v.total), 0) AS monto
           FROM campana_envios ce
           JOIN ventas v ON v.cliente_id = ce.cliente_id
                        AND v.estado = 'completada'
                        AND v.fecha >= ce.enviado_at
                        AND v.fecha <  (ce.enviado_at + INTERVAL ? DAY)
          WHERE ce.campana_id = ? AND ce.estado = 'enviado' AND ce.cliente_id IS NOT NULL",
        [$dias, $campanaId]
    ) ?: ['ventas' => 0, 'monto' => 0];

    return ['ventas' => (int) $r['ventas'], 'monto' => (float) $r['monto']];
}

/** Métricas de una campaña, con porcentajes ya calculados. */
function mkt_metricas(array $c): array
{
    $enviados = (int) $c['enviados'];
    $aperturas = (int) $c['aperturas'];
    $clics = (int) $c['clics'];
    $atrib = mkt_ventas_atribuidas((int) $c['id']);

    return [
        'total'      => (int) $c['total'],
        'enviados'   => $enviados,
        'fallidos'   => (int) $c['fallidos'],
        'aperturas'  => $aperturas,
        'clics'      => $clics,
        'bajas'      => (int) $c['bajas'],
        'tasa_apertura' => $enviados > 0 ? round($aperturas * 100 / $enviados, 1) : 0.0,
        'tasa_clic'     => $enviados > 0 ? round($clics * 100 / $enviados, 1) : 0.0,
        'ctr'           => $aperturas > 0 ? round($clics * 100 / $aperturas, 1) : 0.0,
        'ventas'        => $atrib['ventas'],
        'monto'         => $atrib['monto'],
    ];
}

/** Cifras del panel de marketing (últimos $dias días). */
function mkt_resumen(int $dias = 30): array
{
    $desde = date('Y-m-d 00:00:00', strtotime("-$dias days"));

    $env = qOne(
        "SELECT COUNT(*) enviados,
                SUM(abierto_at IS NOT NULL) aperturas,
                SUM(clic_at IS NOT NULL) clics
           FROM campana_envios
          WHERE estado = 'enviado' AND enviado_at >= ?", [$desde]
    ) ?: [];

    $enviados  = (int) ($env['enviados'] ?? 0);
    $aperturas = (int) ($env['aperturas'] ?? 0);
    $clics     = (int) ($env['clics'] ?? 0);

    $atrib = qOne(
        "SELECT COUNT(DISTINCT v.id) ventas, COALESCE(SUM(v.total), 0) monto
           FROM campana_envios ce
           JOIN ventas v ON v.cliente_id = ce.cliente_id
                        AND v.estado = 'completada'
                        AND v.fecha >= ce.enviado_at
                        AND v.fecha < (ce.enviado_at + INTERVAL ? DAY)
          WHERE ce.estado = 'enviado' AND ce.enviado_at >= ? AND ce.cliente_id IS NOT NULL",
        [MKT_ATRIBUCION_DIAS, $desde]
    ) ?: [];

    return [
        'dias'          => $dias,
        'enviados'      => $enviados,
        'aperturas'     => $aperturas,
        'clics'         => $clics,
        'tasa_apertura' => $enviados > 0 ? round($aperturas * 100 / $enviados, 1) : 0.0,
        'tasa_clic'     => $enviados > 0 ? round($clics * 100 / $enviados, 1) : 0.0,
        'ventas'        => (int) ($atrib['ventas'] ?? 0),
        'monto'         => (float) ($atrib['monto'] ?? 0),
        'campanas'      => (int) qVal("SELECT COUNT(*) FROM campanas"),
        'en_curso'      => (int) qVal("SELECT COUNT(*) FROM campanas WHERE estado IN ('enviando','programada')"),
        'pendientes_wa' => (int) qVal("SELECT COUNT(*) FROM campana_envios WHERE canal = 'whatsapp' AND estado = 'pendiente'"),
        'automatizaciones' => (int) qVal("SELECT COUNT(*) FROM marketing_automatizaciones WHERE activo = 1"),
        'bajas'         => (int) qVal("SELECT COUNT(*) FROM marketing_bajas"),
        'contactables'  => (int) qVal("SELECT COUNT(*) FROM clientes WHERE activo = 1 AND acepta_marketing = 1 AND email IS NOT NULL AND email <> ''"),
    ];
}

/* ============================================================
 *  Automatizaciones
 * ============================================================ */

/** Etiquetas y explicación de cada disparador. */
function mkt_disparadores(): array
{
    return [
        'bienvenida'      => ['label' => 'Cliente nuevo (bienvenida)',   'dias' => 'Días de espera tras registrarse', 'icono' => 'user',
                              'ayuda' => 'Sale una vez, cuando el cliente lleva los días indicados registrado. A cada cliente le llega una sola vez en la vida.'],
        'post_venta'      => ['label' => 'Después de una compra',        'dias' => 'Días después de la compra', 'icono' => 'receipt',
                              'ayuda' => 'Agradecimiento tras la venta. Se envía una vez por venta.'],
        'cumpleanos'      => ['label' => 'Cumpleaños',                   'dias' => 'Días de antelación (0 = el mismo día)', 'icono' => 'calendar',
                              'ayuda' => 'Requiere la fecha de nacimiento en la ficha del cliente. Una vez al año por cliente.'],
        'recompra'        => ['label' => 'Recordatorio de recompra',     'dias' => 'Días desde la última compra', 'icono' => 'undo',
                              'ayuda' => 'Para consumibles y reposición. Se envía una vez por cada última compra.'],
        'inactivo'        => ['label' => 'Cliente dormido (rescate)',    'dias' => 'Días sin comprar', 'icono' => 'clock',
                              'ayuda' => 'Rescate de clientes que dejaron de venir. Como máximo una vez al mes por cliente.'],
        'saldo_pendiente' => ['label' => 'Aviso de saldo pendiente',     'dias' => 'Días desde la venta a crédito', 'icono' => 'wallet',
                              'ayuda' => 'Recordatorio de cobranza a clientes con balance. Como máximo una vez al mes por cliente.'],
    ];
}

/**
 * Candidatos de una automatización: a quién le toca hoy y con qué clave de
 * periodo se evita repetirle.
 *
 * @return array filas [cliente_id, nombre, email, telefono, balance, periodo]
 */
function mkt_auto_candidatos(array $a, int $tope): array
{
    $dias = max(0, (int) $a['dias']);
    $tope = max(1, min(500, $tope));
    $anio = date('Y');
    $mes  = date('Y-m');

    switch ($a['disparador']) {
        case 'bienvenida':
            return qAll(
                "SELECT c.id cliente_id, c.nombre, c.email, c.telefono, c.balance, 'unico' periodo
                   FROM clientes c
                  WHERE c.activo = 1 AND c.acepta_marketing = 1
                    AND c.created_at <= (NOW() - INTERVAL ? DAY)
                    AND c.created_at >= (NOW() - INTERVAL ? DAY)
                    AND NOT EXISTS (SELECT 1 FROM marketing_automatizacion_log l
                                     WHERE l.automatizacion_id = ? AND l.cliente_id = c.id)
                  ORDER BY c.created_at DESC LIMIT $tope",
                [$dias, $dias + 30, (int) $a['id']]
            );

        case 'post_venta':
            return qAll(
                "SELECT c.id cliente_id, c.nombre, c.email, c.telefono, c.balance,
                        CONCAT('venta:', v.id) periodo
                   FROM ventas v
                   JOIN clientes c ON c.id = v.cliente_id AND c.activo = 1 AND c.acepta_marketing = 1
                  WHERE v.estado = 'completada'
                    AND v.fecha <= (NOW() - INTERVAL ? DAY)
                    AND v.fecha >= (NOW() - INTERVAL ? DAY)
                    AND NOT EXISTS (SELECT 1 FROM marketing_automatizacion_log l
                                     WHERE l.automatizacion_id = ? AND l.cliente_id = c.id
                                       AND l.periodo = CONCAT('venta:', v.id))
                  ORDER BY v.fecha DESC LIMIT $tope",
                [$dias, $dias + 7, (int) $a['id']]
            );

        case 'cumpleanos':
            // El cumpleaños de este año, con los días de antelación configurados.
            return qAll(
                "SELECT c.id cliente_id, c.nombre, c.email, c.telefono, c.balance, ? periodo
                   FROM clientes c
                  WHERE c.activo = 1 AND c.acepta_marketing = 1
                    AND c.fecha_nacimiento IS NOT NULL
                    AND MONTH(c.fecha_nacimiento) = MONTH(CURDATE() + INTERVAL ? DAY)
                    AND DAY(c.fecha_nacimiento)   = DAY(CURDATE() + INTERVAL ? DAY)
                    AND NOT EXISTS (SELECT 1 FROM marketing_automatizacion_log l
                                     WHERE l.automatizacion_id = ? AND l.cliente_id = c.id AND l.periodo = ?)
                  ORDER BY c.nombre LIMIT $tope",
                [$anio, $dias, $dias, (int) $a['id'], $anio]
            );

        case 'recompra':
            return qAll(
                "SELECT c.id cliente_id, c.nombre, c.email, c.telefono, c.balance,
                        CONCAT('venta:', u.venta_id) periodo
                   FROM (SELECT v.cliente_id, MAX(v.fecha) ultima,
                                SUBSTRING_INDEX(GROUP_CONCAT(v.id ORDER BY v.fecha DESC), ',', 1) venta_id
                           FROM ventas v
                          WHERE v.estado = 'completada' AND v.cliente_id IS NOT NULL
                          GROUP BY v.cliente_id) u
                   JOIN clientes c ON c.id = u.cliente_id AND c.activo = 1 AND c.acepta_marketing = 1
                  WHERE u.ultima <= (NOW() - INTERVAL ? DAY)
                    AND u.ultima >= (NOW() - INTERVAL ? DAY)
                    AND NOT EXISTS (SELECT 1 FROM marketing_automatizacion_log l
                                     WHERE l.automatizacion_id = ? AND l.cliente_id = c.id
                                       AND l.periodo = CONCAT('venta:', u.venta_id))
                  ORDER BY u.ultima DESC LIMIT $tope",
                [$dias, $dias + 15, (int) $a['id']]
            );

        case 'inactivo':
            return qAll(
                "SELECT c.id cliente_id, c.nombre, c.email, c.telefono, c.balance, ? periodo
                   FROM (SELECT v.cliente_id, MAX(v.fecha) ultima
                           FROM ventas v
                          WHERE v.estado = 'completada' AND v.cliente_id IS NOT NULL
                          GROUP BY v.cliente_id) u
                   JOIN clientes c ON c.id = u.cliente_id AND c.activo = 1 AND c.acepta_marketing = 1
                  WHERE u.ultima <= (NOW() - INTERVAL ? DAY)
                    AND NOT EXISTS (SELECT 1 FROM marketing_automatizacion_log l
                                     WHERE l.automatizacion_id = ? AND l.cliente_id = c.id AND l.periodo = ?)
                  ORDER BY u.ultima DESC LIMIT $tope",
                [$mes, $dias, (int) $a['id'], $mes]
            );

        case 'saldo_pendiente':
            return qAll(
                "SELECT c.id cliente_id, c.nombre, c.email, c.telefono, c.balance, ? periodo
                   FROM clientes c
                  WHERE c.activo = 1 AND c.acepta_marketing = 1 AND c.balance > 0
                    AND EXISTS (SELECT 1 FROM ventas v
                                 WHERE v.cliente_id = c.id AND v.estado = 'completada'
                                   AND v.fecha <= (NOW() - INTERVAL ? DAY))
                    AND NOT EXISTS (SELECT 1 FROM marketing_automatizacion_log l
                                     WHERE l.automatizacion_id = ? AND l.cliente_id = c.id AND l.periodo = ?)
                  ORDER BY c.balance DESC LIMIT $tope",
                [$mes, $dias, (int) $a['id'], $mes]
            );
    }
    return [];
}

/** Etiqueta del periodo de la campaña que agrupa una corrida. */
function mkt_auto_periodo_campana(string $disparador): array
{
    // Mensual para lo que se repite poco; diario para lo que fluye a diario.
    if (in_array($disparador, ['cumpleanos', 'inactivo', 'saldo_pendiente'], true)) {
        return [date('Y-m'), mesNombre((int) date('n')) . ' ' . date('Y')];
    }
    return [date('Y-m-d'), fechaCorta(date('Y-m-d'))];
}

/**
 * Corre una automatización: busca a quién le toca, crea (o reutiliza) la campaña
 * del periodo y encola los envíos. No envía: de eso se encarga el mismo motor de
 * campañas, así que las automatizaciones heredan reintentos, rastreo y métricas.
 *
 * @return array ['encolados'=>int, 'campana_id'=>?int]
 */
function mkt_auto_correr(array $a): array
{
    if (empty($a['activo'])) return ['encolados' => 0, 'campana_id' => null];

    $tope = max(1, (int) $a['tope_dia']);
    $candidatos = mkt_auto_candidatos($a, $tope);
    if (!$candidatos) {
        dbUpdate('marketing_automatizaciones', ['ultimo_run' => date('Y-m-d H:i:s')], 'id = ?', [(int) $a['id']]);
        return ['encolados' => 0, 'campana_id' => null];
    }

    [$periodo, $etiqueta] = mkt_auto_periodo_campana($a['disparador']);
    $nombre = $a['nombre'] . ' · ' . $etiqueta;

    $campanaId = (int) (qVal("SELECT id FROM campanas WHERE automatizacion_id = ? AND nombre = ?",
                             [(int) $a['id'], $nombre]) ?: 0);
    if (!$campanaId) {
        $campanaId = dbInsert('campanas', [
            'nombre'         => $nombre,
            'asunto'         => $a['asunto'],
            'preheader'      => $a['preheader'],
            'contenido'      => $a['contenido'],
            'canal'          => $a['canal'],
            'segmento'       => 'con_email',
            'segmento_id'    => null,
            'promocion_id'   => $a['promocion_id'],
            'cta_texto'      => $a['cta_texto'],
            'cta_url'        => $a['cta_url'],
            'whatsapp_texto' => $a['whatsapp_texto'],
            'estado'         => 'enviando',
            'automatizacion_id' => (int) $a['id'],
            'created_by'     => null,
        ]);
    }

    $canales = $a['canal'] === 'ambos' ? ['email', 'whatsapp'] : [$a['canal']];
    $encolados = 0;

    foreach ($candidatos as $cand) {
        foreach ($canales as $canal) {
            $destino = $canal === 'email'
                ? strtolower(trim((string) $cand['email']))
                : mkt_telefono($cand['telefono']);

            if ($destino === '') continue;
            if ($canal === 'email' && !filter_var($destino, FILTER_VALIDATE_EMAIL)) continue;
            if (mkt_dado_de_baja($canal, $destino)) continue;

            $st = q(
                "INSERT IGNORE INTO campana_envios
                    (campana_id, cliente_id, canal, destino, nombre, token, estado)
                 VALUES (?, ?, ?, ?, ?, ?, 'pendiente')",
                [$campanaId, (int) $cand['cliente_id'], $canal, $destino, $cand['nombre'], mkt_token()]
            );
            if ($st->rowCount() > 0) $encolados++;
        }

        // La bitácora se escribe aunque no haya destino: así no se reintenta
        // eternamente a un cliente sin correo ni teléfono.
        q("INSERT IGNORE INTO marketing_automatizacion_log (automatizacion_id, cliente_id, periodo, campana_id)
           VALUES (?, ?, ?, ?)",
          [(int) $a['id'], (int) $cand['cliente_id'], (string) $cand['periodo'], $campanaId]);
    }

    dbUpdate('marketing_automatizaciones', [
        'ultimo_run' => date('Y-m-d H:i:s'),
        'enviados'   => (int) $a['enviados'] + $encolados,
    ], 'id = ?', [(int) $a['id']]);

    mkt_recalcular($campanaId);
    return ['encolados' => $encolados, 'campana_id' => $campanaId];
}

/* ============================================================
 *  Motor de fondo
 * ============================================================ */

/**
 * Una pasada del motor: activa lo programado, corre las automatizaciones que
 * tocan y despacha un lote de correos.
 *
 * @return array resumen de lo hecho
 */
function mkt_tick(int $lote = MKT_LOTE): array
{
    $hecho = ['activadas' => 0, 'automatizaciones' => 0, 'encolados' => 0, 'enviados' => 0, 'fallidos' => 0];
    if (!mkt_disponible()) return $hecho;

    // 1. Campañas programadas cuya hora ya llegó.
    $listas = qCol("SELECT id FROM campanas WHERE estado = 'programada' AND programada_at IS NOT NULL AND programada_at <= NOW() LIMIT 5");
    foreach ($listas as $id) {
        mkt_construir_audiencia((int) $id);
        dbUpdate('campanas', ['estado' => 'enviando'], 'id = ?', [(int) $id]);
        $hecho['activadas']++;
    }

    // 2. Automatizaciones: como mucho una vez por hora cada una.
    $autos = qAll("SELECT * FROM marketing_automatizaciones
                    WHERE activo = 1 AND (ultimo_run IS NULL OR ultimo_run < (NOW() - INTERVAL 1 HOUR))");
    foreach ($autos as $a) {
        $r = mkt_auto_correr($a);
        $hecho['automatizaciones']++;
        $hecho['encolados'] += $r['encolados'];
    }

    // 3. Despachar un lote de la campaña en curso más antigua.
    if (mail_configurado()) {
        $cid = qVal("SELECT c.id FROM campanas c
                      WHERE c.estado = 'enviando'
                        AND EXISTS (SELECT 1 FROM campana_envios e
                                     WHERE e.campana_id = c.id AND e.canal = 'email' AND e.estado = 'pendiente')
                      ORDER BY c.id LIMIT 1");
        if ($cid) {
            $r = mkt_procesar_campana((int) $cid, $lote);
            $hecho['enviados'] += $r['enviados'];
            $hecho['fallidos'] += $r['fallidos'];
        }
    }

    return $hecho;
}

/**
 * Reclama el turno del motor de forma atómica: aunque entren diez peticiones en
 * el mismo segundo, solo una corre el tick. Mismo mecanismo que el barrido de
 * notificaciones (tabla `sistema_estado`), así que no hace falta cron.
 */
function mkt_reclamar_turno(int $minutos = MKT_TICK_MINUTOS): bool
{
    $st = q(
        "INSERT INTO sistema_estado (clave, valor, updated_at)
         VALUES ('marketing_tick', UNIX_TIMESTAMP(), NOW())
         ON DUPLICATE KEY UPDATE
            valor      = IF(updated_at < (NOW() - INTERVAL ? MINUTE), UNIX_TIMESTAMP(), valor),
            updated_at = IF(updated_at < (NOW() - INTERVAL ? MINUTE), NOW(), updated_at)",
        [$minutos, $minutos]
    );
    return $st->rowCount() > 0;
}

/**
 * Corre el motor si toca. Se llama al pintar la barra superior: nunca debe
 * tumbar una página ni hacerla esperar, así que solo entra cuando hay trabajo
 * real y silencia cualquier error.
 */
function mkt_tick_si_toca(): void
{
    if (!mkt_disponible()) return;
    try {
        $hayTrabajo = qVal(
            "SELECT 1 FROM campanas
              WHERE (estado = 'enviando')
                 OR (estado = 'programada' AND programada_at IS NOT NULL AND programada_at <= NOW())
              LIMIT 1"
        ) ?: qVal("SELECT 1 FROM marketing_automatizaciones
                    WHERE activo = 1 AND (ultimo_run IS NULL OR ultimo_run < (NOW() - INTERVAL 1 HOUR)) LIMIT 1");
        if (!$hayTrabajo) return;

        if (mkt_reclamar_turno()) {
            @set_time_limit(60);
            ignore_user_abort(true);
            mkt_tick();
        }
    } catch (Throwable $e) {
        if (APP_ENV !== 'production') error_log('[marketing] ' . $e->getMessage());
    }
}

/* ============================================================
 *  Compatibilidad con la versión anterior del módulo
 * ============================================================ */

/** Segmentos fijos del módulo viejo (siguen funcionando en campañas antiguas). */
function campanaSegmentos(): array
{
    return [
        'con_email' => 'Todos los clientes con correo',
        'con_deuda' => 'Clientes con saldo pendiente',
    ];
}
