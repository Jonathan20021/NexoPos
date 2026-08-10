<?php
/**
 * Cliente de la API REST de facturación electrónica (LUGANIS).
 *
 * Fuente: LUG-OPE-MA-001 «Manual de Integración Usuario RD» v03.
 *
 * ---------------------------------------------------------------------------
 *  POR QUÉ ESTE CLIENTE LEE LAS RESPUESTAS «A TIENTAS»
 * ---------------------------------------------------------------------------
 *  El manual muestra los cuerpos de respuesta como CAPTURAS DE PANTALLA
 *  (Ilustraciones 2, 4, 8 y 12), no como texto, así que los nombres exactos de
 *  los campos JSON —dónde viene el access token, cómo se llama el trackId— no
 *  están publicados en ninguna parte del documento. Tampoco existe catálogo de
 *  códigos de error ni enumeración de los estados de un trackId.
 *
 *  En vez de adivinar UN nombre y romper en el primer contacto, se busca el
 *  valor por varios nombres plausibles de forma recursiva y SIEMPRE se guarda
 *  la respuesta cruda en `ecf_log`. Con la primera llamada real contra el
 *  ambiente de pruebas quedan a la vista los nombres verdaderos y, si hiciera
 *  falta, se fijan en ecfClavesToken() / ecfClavesTrackId().
 *
 *  Los códigos HTTP sí están documentados (§6.2) e indican el estado de la
 *  PETICIÓN, no su resultado: 200 procesada · 401 token vencido · 403 sin
 *  permiso · 500 error interno (reintentar) · 503 servicio no disponible.
 */

require_once __DIR__ . '/ecf_catalogos.php';
require_once __DIR__ . '/ecf_trama.php';   // ecfTramaBase64()

/** Segundos antes del vencimiento en que se refresca el token (MA-001 §10.e). */
const ECF_MARGEN_REFRESCO = 300;

/**
 * Bundle de CA a usar para verificar el certificado del proveedor.
 *
 * Se define porque en redes con un appliance de inspección TLS (FortiGate,
 * Palo Alto, Zscaler…) el certificado que llega NO es el del proveedor sino uno
 * re-firmado por el equipo de red. Windows suele confiar en ese CA porque TI lo
 * instaló, pero PHP no consulta el almacén de Windows: usa su propio archivo
 * `curl.cainfo`, donde ese CA no está.
 *
 * La salida correcta es AÑADIR ese CA a un bundle propio, nunca apagar la
 * verificación: los datos que viajan por aquí son fiscales y firmados.
 *
 * Orden: constante ECF_CA_BUNDLE (config.local.php) → config/ca-ecf.local.crt →
 * el bundle por defecto de PHP.
 */
function ecfCaBundle(): ?string
{
    if (defined('ECF_CA_BUNDLE') && is_file(ECF_CA_BUNDLE)) return ECF_CA_BUNDLE;
    $propio = dirname(__DIR__) . '/config/ca-ecf.local.crt';
    return is_file($propio) ? $propio : null;
}

/* ============================================================
 *  CONFIGURACIÓN
 * ============================================================ */

/**
 * Configuración efectiva del proveedor.
 *
 * Las constantes de config.local.php tienen precedencia sobre la fila de la
 * base: así, en producción, las credenciales pueden quedar fuera de la base de
 * datos y de sus respaldos, igual que se hace con RESEND_API_KEY.
 */
function ecfConfig(bool $refrescar = false): array
{
    static $cache = null;
    if ($cache !== null && !$refrescar) return $cache;

    $cfg = qOne("SELECT * FROM ecf_config WHERE id = 1") ?: [];

    foreach ([
        'usuario'        => 'ECF_USUARIO',
        'clave'          => 'ECF_CLAVE',
        'device_id'      => 'ECF_DEVICE_ID',
        'url_produccion' => 'ECF_URL_PRODUCCION',
        'ip_publica'     => 'ECF_IP_PUBLICA',
    ] as $col => $const) {
        if (defined($const) && constant($const) !== '') $cfg[$col] = constant($const);
    }

    return $cache = $cfg;
}

/** Guarda cambios en la configuración e invalida la caché. */
function ecfGuardarConfig(array $datos): void
{
    if (!$datos) return;
    dbUpdate('ecf_config', $datos, 'id = 1');
    ecfConfig(true);
}

/** URL base según el ambiente activo. */
function ecfUrlBase(): string
{
    $c = ecfConfig();
    $url = ($c['ambiente'] ?? 'stage') === 'produccion'
        ? (string) ($c['url_produccion'] ?? '')
        : (string) ($c['url_stage'] ?? '');
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException(
            ($c['ambiente'] ?? '') === 'produccion'
                ? 'Falta la URL de producción. La entrega el consultor de LUGANIS; el manual solo publica la de pruebas.'
                : 'Falta la URL del ambiente de pruebas.'
        );
    }
    return rtrim($url, '/');
}

/**
 * Identificador del dispositivo. Debe ser ESTABLE: ata la sesión y viaja en el
 * header `device-id` de todas las peticiones posteriores al login. Si cambia
 * entre el login y el envío, el proveedor rechaza la petición.
 */
function ecfDeviceId(): string
{
    $c = ecfConfig();
    $id = trim((string) ($c['device_id'] ?? ''));
    if ($id !== '') return $id;

    // Se genera una vez y se conserva.
    $id = 'nexopos-' . substr(bin2hex(random_bytes(8)), 0, 12);
    ecfGuardarConfig(['device_id' => $id]);
    return $id;
}

/** ¿Está configurado lo mínimo para intentar una llamada? */
function ecfConfigurado(): bool
{
    $c = ecfConfig();
    return trim((string) ($c['usuario'] ?? '')) !== ''
        && trim((string) ($c['clave'] ?? '')) !== '';
}

/**
 * IP pública que se declara en el login. Nunca vacía.
 *
 * El proveedor la exige: sin ella responde 1003 «Falta la información de
 * geolocalización» y el login falla entero. No comprueba que sea la IP real de
 * origen —acepta cualquier IPv4 bien formada—, pero el campo tiene que venir.
 *
 * Orden: lo configurado → la IP del servidor si es pública → un marcador.
 */
function ecfIpPublica(): string
{
    $c = ecfConfig();
    $ip = trim((string) ($c['ip_publica'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;

    $delServidor = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    if (filter_var($delServidor, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $delServidor;
    }

    // En desarrollo (XAMPP) y bajo cron no hay IP pública a mano. Se manda una
    // válida para no bloquear la integración; en producción conviene fijar la
    // real en Configuración o en ECF_IP_PUBLICA.
    return '0.0.0.0';
}

/**
 * Vigencia del token, en segundos, leída de la respuesta.
 *
 * El manual dice 3600 pero el ambiente real devuelve 2400 con la cuenta de
 * envío y 600 con la de consulta. Suponerla es garantía de 401 a media jornada.
 */
function ecfVigenciaToken(?array $json): int
{
    $v = ecfBuscarValor($json, ['expiresIn', 'expires_in', 'expiration']);
    return is_numeric($v) && (int) $v > 0 ? (int) $v : 3600;
}

/**
 * Margen para refrescar antes de que venza.
 *
 * El manual sugiere 5 minutos, pero con un token de 600 s eso deja apenas la
 * mitad de la vida útil; y con uno más corto no se usaría nunca. Se toma el
 * menor entre los 5 minutos y un tercio de la vigencia.
 */
function ecfMargenRefresco(int $vigencia): int
{
    return (int) max(30, min(ECF_MARGEN_REFRESCO, intdiv(max($vigencia, 90), 3)));
}

/* ============================================================
 *  BITÁCORA
 * ============================================================ */

/** Oculta credenciales y tokens antes de escribir en la bitácora. */
function ecfOfuscar(?string $texto): ?string
{
    if ($texto === null || $texto === '') return $texto;
    $t = preg_replace('/("authenticationValue"\s*:\s*")[^"]*"/i', '$1********"', $texto);
    $t = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1********', $t);
    $t = preg_replace('/("(?:access|refresh)[_A-Za-z]*[Tt]oken"\s*:\s*")[^"]{12,}"/i', '$1********"', $t);
    // La trama en Base64 puede pesar cientos de KB: se recorta.
    $t = preg_replace('/("filecontent"\s*:\s*")[^"]{200,}"/i', '$1…(trama en Base64 recortada)"', $t);
    return mb_substr((string) $t, 0, 60000, 'UTF-8');
}

/** Registra una llamada. Nunca lanza: la bitácora no puede tumbar una emisión. */
function ecfRegistrarLlamada(array $datos): void
{
    try {
        dbInsert('ecf_log', [
            'documento_id' => $datos['documento_id'] ?? null,
            'operacion'    => substr((string) ($datos['operacion'] ?? ''), 0, 20),
            'metodo'       => substr((string) ($datos['metodo'] ?? 'GET'), 0, 8),
            'url'          => substr((string) ($datos['url'] ?? ''), 0, 255),
            'http_code'    => (int) ($datos['http_code'] ?? 0),
            'ms'           => (int) ($datos['ms'] ?? 0),
            'peticion'     => ecfOfuscar($datos['peticion'] ?? null),
            'respuesta'    => ecfOfuscar($datos['respuesta'] ?? null),
            'error'        => $datos['error'] !== null ? substr((string) $datos['error'], 0, 255) : null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Silencio deliberado.
    }
}

/* ============================================================
 *  TRANSPORTE
 * ============================================================ */

/**
 * Ejecuta una petición HTTP contra la API y devuelve:
 *   ['http' => int, 'json' => array|null, 'raw' => string, 'error' => string|null]
 *
 * No lanza excepción por códigos de error: el llamador decide. Sí lanza si la
 * configuración es inservible (sin URL), porque eso es un fallo de instalación.
 */
function ecfHttp(string $metodo, string $ruta, array $opciones = []): array
{
    $url = preg_match('#^https?://#i', $ruta) ? $ruta : ecfUrlBase() . $ruta;

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($opciones['headers'] ?? [] as $h) $headers[] = $h;

    $cuerpo = null;
    if (array_key_exists('json', $opciones)) {
        $cuerpo = json_encode($opciones['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($metodo),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => (int) ($opciones['timeout'] ?: 45),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
    ]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
    if (!empty($opciones['binario'])) curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    if ($ca = ecfCaBundle()) curl_setopt($ch, CURLOPT_CAINFO, $ca);

    $t0   = microtime(true);
    $raw  = curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch) ?: null;
    curl_close($ch);

    if ($raw === false) $raw = '';

    $json = null;
    if ($raw !== '' && empty($opciones['binario'])) {
        $decodificado = json_decode($raw, true);
        if (is_array($decodificado)) $json = $decodificado;
    }

    ecfRegistrarLlamada([
        'documento_id' => $opciones['documento_id'] ?? null,
        'operacion'    => $opciones['operacion'] ?? 'http',
        'metodo'       => strtoupper($metodo),
        'url'          => $url,
        'http_code'    => $http,
        'ms'           => $ms,
        'peticion'     => $cuerpo,
        'respuesta'    => empty($opciones['binario'])
            ? $raw
            : '(' . strlen($raw) . ' bytes binarios)',
        'error'        => $err,
    ]);

    return ['http' => $http, 'json' => $json, 'raw' => $raw, 'error' => $err, 'ms' => $ms];
}

/**
 * Busca recursivamente el primer valor cuya clave coincida (sin distinguir
 * mayúsculas ni guiones bajos) con alguno de los nombres dados.
 *
 * Es la pieza que permite trabajar sin conocer la forma exacta de la respuesta.
 */
function ecfBuscarValor($json, array $claves)
{
    if (!is_array($json)) return null;

    $normalizar = static fn(string $s): string => strtolower(str_replace(['_', '-'], '', $s));
    $buscadas = array_map($normalizar, $claves);

    foreach ($json as $k => $v) {
        if (is_string($k) && in_array($normalizar($k), $buscadas, true)) {
            if (is_scalar($v) && (string) $v !== '') return $v;
        }
    }
    foreach ($json as $v) {
        if (is_array($v)) {
            $hallado = ecfBuscarValor($v, $claves);
            if ($hallado !== null) return $hallado;
        }
    }
    return null;
}

/**
 * Códigos de respuesta observados en el ambiente de pruebas.
 *
 * El manual NO publica este catálogo; estos códigos se fueron recogiendo de las
 * respuestas reales. La lista no pretende ser completa —irá creciendo con lo que
 * aparezca en `ecf_log`— y por eso nada del flujo depende de que un código esté
 * aquí: sirve para explicar mejor el fallo, no para decidir.
 *
 * `reintentable` marca los que tiene sentido volver a intentar tal cual. Un
 * error de contenido (RNC que no cuadra, formato del nombre) se reintentaría
 * eternamente con el mismo resultado.
 */
function ecfCodigosRespuesta(): array
{
    return [
        '0'    => ['ok' => true,  'texto' => 'Transacción exitosa'],
        '0002' => ['ok' => false, 'texto' => "Formato del campo 'filename' inválido. Se espera RncE99Secuencial.ext"],
        '0006' => ['ok' => false, 'texto' => 'El ticket no se encontró en los registros'],
        '0010' => ['ok' => false, 'texto' => "El campo 'filecontent' es obligatorio"],
        '1001' => ['ok' => false, 'texto' => 'Faltan datos obligatorios en la petición'],
        '1003' => ['ok' => false, 'texto' => 'Falta la información de geolocalización (latitud, longitud e IP pública)'],
        '1006' => ['ok' => false, 'texto' => 'El RNC del nombre del archivo no corresponde al de la compañía en sesión'],
        '2007' => ['ok' => false, 'texto' => 'El documento de identidad indicado es de otra empresa'],
    ];
}

/** Explicación conocida de un código, o null si todavía no está catalogado. */
function ecfExplicarCodigo(?string $codigo): ?string
{
    if ($codigo === null || $codigo === '') return null;
    return ecfCodigosRespuesta()[$codigo]['texto'] ?? null;
}

/** Nombres bajo los que puede venir el access token. */
function ecfClavesToken(): array
{
    // El ambiente real lo entrega en data.token.accessToken; el resto son
    // alternativas por si cambia entre ambientes.
    return ['accessToken', 'access_token', 'token', 'jwt', 'idToken'];
}

/** Nombres bajo los que puede venir el refresh token. */
function ecfClavesRefresh(): array
{
    return ['refreshToken', 'refresh_token'];
}

/** Nombres bajo los que puede venir el ticket de seguimiento. */
function ecfClavesTrackId(): array
{
    return ['trackId', 'track_id', 'internalTrackId', 'internal_track_id', 'ticket', 'trackid'];
}

/** Extrae ['code' => …, 'message' => …] del envoltorio «status» de la respuesta. */
function ecfEstadoRespuesta(?array $json): array
{
    $code = ecfBuscarValor($json, ['code', 'codigo', 'statusCode']);
    $msg  = ecfBuscarValor($json, ['message', 'mensaje', 'description', 'descripcion']);
    return ['code' => $code !== null ? (string) $code : null,
            'message' => $msg !== null ? (string) $msg : null];
}

/* ============================================================
 *  AUTENTICACIÓN
 * ============================================================ */

/**
 * Login. Devuelve ['ok' => bool, 'mensaje' => string, 'json' => array|null].
 *
 * El cuerpo sigue el EJEMPLO curl del manual (§6.4), que anida los datos del
 * dispositivo dentro de «deviceInfo». La tabla de parámetros de esa misma
 * sección los lista como campos planos del body; se eligió el ejemplo porque es
 * el que muestra una petición real. Anotado para confirmar con el consultor.
 */
function ecfLogin(?int $timeout = null): array
{
    $c = ecfConfig(true);
    if (!ecfConfigurado()) {
        return ['ok' => false, 'mensaje' => 'Faltan el usuario y la clave del proveedor.', 'json' => null];
    }

    $r = ecfHttp('POST', '/authentication-service/auth/login/COMPANY', [
        'operacion' => 'login',
        'timeout'   => $timeout,
        'json' => [
            'identifierValue'     => (string) $c['usuario'],
            'authenticationValue' => (string) $c['clave'],
            'deviceInfo' => [
                'appVersion'        => (string) ($c['app_version'] ?: '1.0.0'),
                'os'                => 'NexoPOS ' . PHP_OS_FAMILY,
                'deviceId'          => ecfDeviceId(),
                'latitude'          => (string) ($c['latitud']  ?: '18.486058'),
                'longitude'         => (string) ($c['longitud'] ?: '-69.931212'),
                // NUNCA vacío: el proveedor responde 1003 «Falta la información
                // de geolocalización» y el login falla entero. No valida que la
                // IP sea la real, pero exige que venga algo con forma de IPv4.
                'providerIpAddress' => ecfIpPublica(),
            ],
        ],
    ]);

    if ($r['error']) {
        return ['ok' => false, 'mensaje' => 'No se pudo conectar: ' . $r['error'], 'json' => null];
    }

    $token = ecfBuscarValor($r['json'], ecfClavesToken());
    if ($r['http'] === 200 && $token) {
        $refresh = ecfBuscarValor($r['json'], ecfClavesRefresh());
        ecfGuardarConfig([
            'access_token'  => (string) $token,
            'refresh_token' => $refresh ? (string) $refresh : null,
            'token_expira'  => date('Y-m-d H:i:s', time() + ecfVigenciaToken($r['json'])),
        ]);
        return ['ok' => true, 'mensaje' => 'Sesión iniciada con el proveedor.', 'json' => $r['json']];
    }

    $st = ecfEstadoRespuesta($r['json']);
    return [
        'ok' => false,
        'json' => $r['json'],
        'mensaje' => ecfMensajeError($r['http'], $st, 'No se pudo iniciar sesión', $r['raw']),
    ];
}

/** Renueva el access token sin volver a autenticar (MA-001 §6.5). */
function ecfRefrescarToken(?int $timeout = null): array
{
    $c = ecfConfig(true);
    if (empty($c['refresh_token']) || empty($c['access_token'])) {
        return ['ok' => false, 'mensaje' => 'No hay sesión previa que refrescar.', 'json' => null];
    }

    $r = ecfHttp('PUT', '/authentication-service/refreshToken', [
        'operacion' => 'refresh',
        'timeout'   => $timeout,
        'headers' => [
            'device-id: ' . ecfDeviceId(),
            'Authorization: Bearer ' . $c['access_token'],
        ],
        'json' => ['refreshToken' => (string) $c['refresh_token']],
    ]);

    $token = ecfBuscarValor($r['json'], ecfClavesToken());
    if ($r['http'] === 200 && $token) {
        $refresh = ecfBuscarValor($r['json'], ecfClavesRefresh());
        ecfGuardarConfig([
            'access_token'  => (string) $token,
            'refresh_token' => $refresh ? (string) $refresh : $c['refresh_token'],
            // La vigencia se lee de la respuesta, no se supone. En el ambiente
            // real vale 2400 s con la cuenta de envío y 600 s con la de
            // consulta, no los 3600 que dice el manual: darla por hecha
            // significaría seguir usando un token muerto y comer 401.
            'token_expira'  => date('Y-m-d H:i:s', time() + ecfVigenciaToken($r['json'])),
        ]);
        return ['ok' => true, 'mensaje' => 'Token renovado.', 'json' => $r['json']];
    }

    $st = ecfEstadoRespuesta($r['json']);
    return ['ok' => false, 'json' => $r['json'],
            'mensaje' => ecfMensajeError($r['http'], $st, 'No se pudo renovar el token', $r['raw'])];
}

/** Cierra la sesión (MA-001 §6.6). Se recomienda tras la última petición. */
function ecfLogout(): array
{
    $c = ecfConfig(true);
    if (empty($c['access_token'])) return ['ok' => true, 'mensaje' => 'No había sesión abierta.'];

    $r = ecfHttp('GET', '/authentication-service/logout', [
        'operacion' => 'logout',
        'headers' => [
            'device-id: ' . ecfDeviceId(),
            'Authorization: Bearer ' . $c['access_token'],
        ],
    ]);

    ecfGuardarConfig(['access_token' => null, 'refresh_token' => null, 'token_expira' => null]);
    return ['ok' => $r['http'] === 200, 'mensaje' => 'Sesión cerrada.'];
}

/**
 * Devuelve un access token utilizable, renovándolo o reautenticando si hace
 * falta. Null si no se pudo obtener.
 *
 * El manual pide expresamente NO hacer login por cada envío (§10.d): se
 * reutiliza el token guardado mientras le queden más de 5 minutos de vida.
 */
function ecfToken(?int $timeout = null): ?string
{
    $c = ecfConfig(true);
    $token  = (string) ($c['access_token'] ?? '');
    $expira = $c['token_expira'] ?? null;

    if ($token !== '' && $expira) {
        $restante = strtotime((string) $expira) - time();
        // El margen se calcula sobre la vigencia real de ESTE token, no sobre
        // una constante: las dos cuentas del proveedor dan tokens de duración
        // muy distinta (2400 s y 600 s).
        $vigencia = max(1, strtotime((string) $expira) - strtotime((string) ($c['updated_at'] ?? 'now')));
        if ($restante > ecfMargenRefresco($vigencia)) return $token;
        if ($restante > 0 && ecfRefrescarToken($timeout)['ok']) {
            return (string) ecfConfig(true)['access_token'];
        }
    }

    return ecfLogin($timeout)['ok'] ? (string) ecfConfig(true)['access_token'] : null;
}

/** Headers de sesión para las operaciones autenticadas. */
function ecfHeadersSesion(string $token): array
{
    return ['device-id: ' . ecfDeviceId(), 'Authorization: Bearer ' . $token];
}

/* ============================================================
 *  ENVÍO Y CONSULTA
 * ============================================================ */

/**
 * Envía una trama y devuelve:
 *   ['ok' => bool, 'track_id' => ?string, 'mensaje' => string,
 *    'http' => int, 'reintentable' => bool, 'json' => ?array, 'raw' => string]
 *
 * `reintentable` distingue un fallo pasajero (red caída, 500, 503) de un
 * rechazo de contenido. Solo el primero debe reintentarse: repetir una trama
 * rechazada gasta la misma secuencia una y otra vez.
 */
function ecfEnviarTrama(string $nombreArchivo, string $trama, ?int $documentoId = null, array $opciones = []): array
{
    $token = ecfToken(isset($opciones['timeout']) ? max(3, (int) $opciones['timeout']) : null);
    if (!$token) {
        return ['ok' => false, 'track_id' => null, 'http' => 0, 'reintentable' => true,
                'json' => null, 'raw' => '',
                'mensaje' => 'No se pudo obtener un token válido del proveedor.'];
    }

    // El login previo hereda el MISMO tope que el envío. Sin esto, una venta con
    // el proveedor lento esperaba los 45 s por defecto del login ANTES de llegar
    // al envío, y el tope corto del POS no servía de nada.
    $timeout = max(3, (int) ($opciones['timeout'] ?? 90));
    $peticion = static fn(string $tk): array => ecfHttp('POST', '/parser-service/send', [
        'operacion'    => 'send',
        'documento_id' => $documentoId,
        'timeout'      => $timeout,
        'headers'      => ecfHeadersSesion($tk),
        'json'         => ['filename' => $nombreArchivo, 'filecontent' => ecfTramaBase64($trama)],
    ]);

    $r = $peticion($token);

    // 401 = token vencido o inválido. Se reautentica UNA vez y se reintenta.
    if ($r['http'] === 401) {
        $login = ecfLogin($timeout);
        if ($login['ok']) $r = $peticion((string) ecfConfig(true)['access_token']);
    }

    $track = ecfBuscarValor($r['json'], ecfClavesTrackId());
    $st    = ecfEstadoRespuesta($r['json']);

    if ($r['http'] === 200 && $track) {
        return ['ok' => true, 'track_id' => (string) $track, 'http' => 200, 'reintentable' => false,
                'json' => $r['json'], 'raw' => $r['raw'],
                'mensaje' => $st['message'] ?: 'Trama recibida por el proveedor.'];
    }

    $reintentable = $r['error'] !== null || in_array($r['http'], [0, 500, 502, 503, 504], true);

    return [
        'ok' => false, 'track_id' => null, 'http' => $r['http'], 'reintentable' => $reintentable,
        'json' => $r['json'], 'raw' => $r['raw'],
        'mensaje' => $r['error']
            ? 'Error de conexión: ' . $r['error']
            : ecfMensajeError($r['http'], $st, 'El proveedor no devolvió un trackId', $r['raw']),
    ];
}

/**
 * Consulta el estado de un e-CF por su trackId (MA-001 §6.8).
 *
 * El manual NO publica la lista de estados posibles, así que el estado se
 * infiere del texto de la respuesta y, ante la duda, se deja «enviado» —nunca
 * se da por aceptado algo que no se pudo confirmar.
 */
function ecfConsultarTrackId(string $trackId, ?int $documentoId = null, array $opciones = []): array
{
    $timeout = isset($opciones['timeout']) ? max(3, (int) $opciones['timeout']) : null;

    $token = ecfToken($timeout);
    if (!$token) {
        return ['ok' => false, 'estado' => null, 'mensaje' => 'Sin token válido.', 'json' => null, 'raw' => ''];
    }

    $consulta = static fn(string $tk): array => ecfHttp('GET', '/parser-service/read/trackId/' . rawurlencode($trackId), [
        'operacion'    => 'consulta',
        'documento_id' => $documentoId,
        'timeout'      => $timeout,
        'headers'      => ecfHeadersSesion($tk),
    ]);

    $r = $consulta($token);

    if ($r['http'] === 401 && ecfLogin($timeout)['ok']) {
        $r = $consulta((string) ecfConfig(true)['access_token']);
    }

    $st = ecfEstadoRespuesta($r['json']);

    if ($r['http'] !== 200) {
        return ['ok' => false, 'estado' => null, 'json' => $r['json'], 'raw' => $r['raw'],
                'codigo' => $st['code'],
                'mensaje' => ecfMensajeError($r['http'], $st, 'No se pudo consultar el estado', $r['raw'])];
    }

    return [
        'ok' => true,
        'estado' => ecfInterpretarEstado($r['json']),
        'codigo' => $st['code'],
        'mensaje' => $st['message'] ?: 'Consulta realizada.',
        'json' => $r['json'],
        'raw' => $r['raw'],
    ];
}

/**
 * Traduce la respuesta de consulta a uno de nuestros estados.
 *
 * La respuesta real trae el veredicto en `data.status` («Aceptado» por el
 * trackId, «ACCEPTED» por el servicio STATUS) junto a `data.responseCode`. Se
 * lee ese campo, que es determinista, y solo si no viniera se cae a la
 * heurística de texto.
 *
 * OJO: el sobre `status.code` de arriba vale «0» aunque el documento haya sido
 * rechazado — ese cero dice que la CONSULTA salió bien, no el comprobante. Leer
 * el código equivocado sería dar por bueno lo que la DGII rechazó.
 *
 * Ante la duda se devuelve «enviado» y se vuelve a consultar más tarde.
 */
function ecfInterpretarEstado(?array $json): string
{
    if (!is_array($json)) return 'enviado';

    $veredicto = ecfBuscarValor($json['data'] ?? [], ['status', 'estado', 'responseMessage', 'documentStatus']);
    $normal = static function ($v): string {
        $s = strtolower(trim((string) $v));
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    };

    if ($veredicto !== null) {
        $v = $normal($veredicto);
        foreach (['aceptad', 'accepted', 'aprobad', 'approved'] as $a) if (str_contains($v, $a)) return 'aceptado';
        foreach (['rechaz', 'rejected', 'anulad', 'cancel'] as $a) if (str_contains($v, $a)) return 'rechazado';
        foreach (['proceso', 'pending', 'enviado', 'recib', 'cola'] as $a) if (str_contains($v, $a)) return 'enviado';
    }

    // Sin veredicto explícito: heurística sobre el texto completo, conservadora.
    $texto = $normal(json_encode($json, JSON_UNESCAPED_UNICODE) ?: '');
    foreach (['rechaz', 'rejected', 'invalid', 'no valido'] as $a) if (str_contains($texto, $a)) return 'rechazado';
    foreach (['aceptad', 'accepted', 'aprobad', 'approved'] as $a) if (str_contains($texto, $a)) return 'aceptado';
    return 'enviado';
}

/* ============================================================
 *  DESCARGAS (MA-001 §7 — Integración Avanzada)
 * ============================================================ */

/**
 * Descarga el PDF, XML, QR o STATUS de un documento ya emitido.
 *
 * @param string $recurso PDF | XML | QR | STATUS
 * @param string $rnc     RNC del emisor (se envía como «RNC-123456789»)
 * @param string $encf    e-NCF, p. ej. E310000000001
 *
 * OJO: los ejemplos curl del manual para PDF, XML y QR apuntan a
 * `pe.stage-api.tech-luganis.net` (el dominio de PERÚ) y con doble barra. Es un
 * error de copiado del documento: aquí se usa la URL base de República
 * Dominicana, que es la que el propio manual declara en §6.1.
 */
function ecfDescargarRecurso(string $recurso, string $rnc, string $encf, ?int $documentoId = null): array
{
    $recurso = strtoupper($recurso);
    if (!in_array($recurso, ['PDF', 'XML', 'QR', 'STATUS'], true)) {
        throw new InvalidArgumentException('Recurso no válido: ' . $recurso);
    }

    $token = ecfToken();
    if (!$token) return ['ok' => false, 'contenido' => null, 'mensaje' => 'Sin token válido.'];

    $ruta = sprintf(
        '/client-service/download/%s/RNC-%s/%s',
        $recurso,
        preg_replace('/\D+/', '', $rnc),
        rawurlencode($encf)
    );

    // Los cuatro servicios responden application/json, NO el archivo binario.
    // El contenido viene dentro, en base64 (PDF y XML) o como URL (QR).
    $r = ecfHttp('GET', $ruta, [
        'operacion'    => 'descarga',
        'documento_id' => $documentoId,
        'timeout'      => 60,
        'headers'      => ecfHeadersSesion($token),
    ]);

    $st = ecfEstadoRespuesta($r['json']);

    // El sobre puede decir 200 y traer un error dentro (p. ej. 2007, RNC de otra
    // empresa). Se comprueba el código, no solo el HTTP.
    $codigoOk = $st['code'] === null || $st['code'] === '0';
    if ($r['http'] !== 200 || !$codigoOk || $r['raw'] === '') {
        return ['ok' => false, 'contenido' => null, 'nombre' => null, 'url' => null, 'json' => $r['json'],
                'mensaje' => ecfMensajeError($r['http'], $st, 'No se pudo descargar el ' . $recurso, $r['raw'])];
    }

    $detalle = $r['json']['data']['detail'] ?? [];

    // QR: no llega una imagen, llega la URL del timbre de la DGII.
    $url = is_array($detalle) ? ($detalle['qrCode'] ?? $detalle['url'] ?? null) : null;

    // PDF y XML: base64 con su nombre de archivo.
    $b64    = is_array($detalle) ? ($detalle['base64FileContent'] ?? $detalle['content'] ?? null) : null;
    $nombre = is_array($detalle) ? ($detalle['filename'] ?? null) : null;
    $binario = null;
    if (is_string($b64) && $b64 !== '') {
        $d = base64_decode($b64, true);
        if ($d !== false && $d !== '') $binario = $d;
    }

    return [
        'ok' => true,
        'contenido' => $binario,          // bytes del PDF/XML, o null
        'nombre'    => $nombre,           // nombre que le da el proveedor
        'url'       => is_string($url) ? $url : null,   // QR: URL del timbre
        'detalle'   => is_array($detalle) ? $detalle : [],
        'json'      => $r['json'],
        'raw'       => $r['raw'],
        'mensaje'   => 'Descarga completada.',
    ];
}

/* ============================================================
 *  MENSAJES
 * ============================================================ */

/**
 * Detecta que quien respondió NO fue el proveedor sino un equipo de red.
 *
 * Un firewall con filtrado web devuelve una página HTML de bloqueo con código
 * 403 o 200. Sin esta comprobación, el mensaje resultante sería «no tienes
 * permiso sobre ese recurso» y se perdería medio día revisando credenciales que
 * están perfectamente bien.
 *
 * @return string|null Explicación si se detecta bloqueo; null si no.
 */
function ecfDetectarBloqueoRed(string $raw): ?string
{
    if ($raw === '' || stripos($raw, '<html') === false) return null;

    $marcas = [
        'FortiGuard'          => 'FortiGate (FortiGuard Web Filter)',
        'Web Page Blocked'    => 'un filtro de contenido web',
        'Internet usage policy' => 'la política de navegación de la red',
        'Zscaler'             => 'Zscaler',
        'Palo Alto'           => 'Palo Alto Networks',
        'blocked by'          => 'un filtro de contenido web',
    ];
    foreach ($marcas as $marca => $quien) {
        if (stripos($raw, $marca) !== false) {
            return 'La petición no llegó al proveedor: la bloqueó ' . $quien
                 . ' de esta red. Hay que pedirle a TI que permita el dominio '
                 . '*.tech-luganis.net (suele aparecer como categoría «Unrated») '
                 . 'y que lo exima de la inspección SSL.';
        }
    }
    return null;
}

/**
 * Redacta un mensaje de error entendible combinando el código HTTP
 * (documentado en §6.2) con el «status» que devuelva el proveedor.
 */
function ecfMensajeError(int $http, array $status, string $contexto, string $raw = ''): string
{
    // Un equipo de red interpuesto explica el fallo mejor que cualquier código.
    if ($bloqueo = ecfDetectarBloqueoRed($raw)) {
        return $contexto . '. ' . $bloqueo;
    }

    $porHttp = [
        0   => 'no hubo respuesta del servidor (revisa la conexión o el firewall)',
        401 => 'el token está vencido o no es válido',
        403 => 'las credenciales no tienen permiso sobre ese recurso',
        404 => 'la ruta no existe en este ambiente',
        500 => 'error interno del proveedor: la transacción no se procesó, hay que reintentar',
        502 => 'el proveedor está fuera de servicio momentáneamente',
        503 => 'el servicio no está disponible en este momento',
        504 => 'el proveedor no respondió a tiempo',
    ];

    $partes = [$contexto];
    if (isset($porHttp[$http]))      $partes[] = $porHttp[$http];
    elseif ($http && $http !== 200)  $partes[] = "el servidor respondió HTTP $http";

    $detalle = trim((string) ($status['message'] ?? ''));
    if ($detalle !== '') {
        $partes[] = 'respuesta del proveedor: «' . $detalle . '»'
                  . (!empty($status['code']) ? ' (código ' . $status['code'] . ')' : '');
    } elseif (!empty($status['code'])) {
        $partes[] = 'código ' . $status['code'];
    }

    return implode('. ', $partes) . '.';
}
