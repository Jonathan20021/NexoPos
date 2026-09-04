<?php
/**
 * Cliente del reloj biométrico (BioTime Cloud de ZKTeco).
 *
 * El ponche de Importers vive en https://importers.biotime.mx y Nexo lo apunta
 * a mano en `asistencias`. Esto es el puente: lee lo que el reloj ya sabe en
 * vez de que alguien lo teclee mirando otra pantalla.
 *
 * ---------------------------------------------------------------------------
 * LO QUE HAY QUE SABER ANTES DE TOCAR ESTO
 *
 * · La nube NO se autentica como el BioTime de servidor. El manual del 8.0 dice
 *   `{username, password}`; la nube pide `{email, password, company}`, donde
 *   `company` es el subdominio —«importers»—. Con el cuerpo del manual, la nube
 *   contesta 400 sin explicar por qué.
 *
 * · El token vale un rato y se pide otra vez, no se guarda en la base. Una
 *   contraseña en claro en una tabla es una contraseña que se filtra en el
 *   próximo volcado; aquí sale de la configuración del servidor y no se
 *   escribe en ningún sitio. `bioOfuscar()` la tapa incluso en los errores.
 *
 * · Nexo NO tiene turnos. Sin turno no se puede saber qué es una tardanza, así
 *   que esa parte la contesta BioTime, que sí los tiene configurados: por eso
 *   existe `bioReporte()` además de `bioPonches()`. Ver docs/PONCHE-BIOTIME.md.
 * ---------------------------------------------------------------------------
 */

/**
 * Lo que hace falta para hablar con el reloj.
 *
 * Cada valor puede venir de una constante de configuración o del entorno, y el
 * ENTORNO MANDA. Eso permite probar sin dejar la contraseña escrita en ningún
 * fichero:
 *
 *     BIOTIME_CLAVE='...' php pruebas/biotime.php
 *
 * Una contraseña en un fichero es una contraseña que acaba en una copia de
 * seguridad, en un adjunto de correo o en el portapapeles de alguien. Para el
 * servicio que corre solo hace falta ponerla en algún sitio; para probar, no.
 */
function bioConfig(): array
{
    $v = static function (string $nombre): string {
        $env = getenv($nombre);
        if (is_string($env) && $env !== '') return $env;
        return defined($nombre) ? (string) constant($nombre) : '';
    };
    return [
        'url'     => rtrim($v('BIOTIME_URL'), '/'),
        'email'   => $v('BIOTIME_EMAIL'),
        'clave'   => $v('BIOTIME_CLAVE'),
        'empresa' => $v('BIOTIME_EMPRESA'),
    ];
}

function bioConfigurado(): bool
{
    return bioFaltantes() === [];
}

/**
 * Qué constantes faltan, por su nombre.
 *
 * «Faltan BIOTIME_URL, BIOTIME_EMAIL, BIOTIME_CLAVE o BIOTIME_EMPRESA» cuando
 * solo falta una manda a revisar cuatro cosas de las que tres están bien. Decir
 * cuál es la que falta cuesta lo mismo.
 */
function bioFaltantes(): array
{
    $c = bioConfig();
    $faltan = [];
    foreach (['url' => 'BIOTIME_URL', 'email' => 'BIOTIME_EMAIL',
              'clave' => 'BIOTIME_CLAVE', 'empresa' => 'BIOTIME_EMPRESA'] as $k => $n) {
        if ($c[$k] === '') $faltan[] = $n;
    }
    return $faltan;
}

/** Tapa la contraseña para que no acabe en un log ni en un mensaje de error. */
function bioOfuscar(?string $texto): string
{
    $c = bioConfig();
    if ($texto === null || $texto === '') return '';
    if ($c['clave'] !== '') $texto = str_replace($c['clave'], '********', $texto);
    // Y el token, que también abre la puerta.
    return preg_replace('~("token"\s*:\s*")[^"]{12,}(")~', '$1********$2', $texto) ?? $texto;
}

/**
 * Una llamada al reloj. Devuelve siempre la misma forma, incluso al fallar:
 * quien llama decide qué hacer, no se lanza excepción por un 401.
 */
function bioHttp(string $metodo, string $ruta, array $opciones = []): array
{
    $c   = bioConfig();
    $url = preg_match('#^https?://#i', $ruta) ? $ruta : $c['url'] . $ruta;

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($opciones['headers'] ?? [] as $h) $headers[] = $h;

    $cuerpo = array_key_exists('json', $opciones)
        ? json_encode($opciones['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($metodo),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => (int) ($opciones['timeout'] ?? 45),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
    ]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);

    $t0   = microtime(true);
    $raw  = (string) curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch) ?: null;
    curl_close($ch);

    $json = null;
    if ($raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) $json = $d;
    }

    return ['http' => $http, 'json' => $json, 'raw' => $raw, 'error' => $err, 'ms' => $ms,
            'url' => $url];
}

/**
 * El token de sesión del reloj.
 *
 * Se guarda en memoria mientras dure la petición: una sincronización pide
 * varias páginas seguidas y no tiene sentido volver a autenticarse en cada una.
 */
function bioToken(bool $refrescar = false): ?string
{
    static $token = null;
    if ($token !== null && !$refrescar) return $token;
    if (!bioConfigurado()) return null;

    $c = bioConfig();
    $r = bioHttp('POST', '/jwt-api-token-auth/', ['json' => [
        'email'    => $c['email'],
        'password' => $c['clave'],
        'company'  => $c['empresa'],
    ]]);
    bioUltimaAuth($r);

    $t = $r['json']['token'] ?? $r['json']['data']['token'] ?? null;
    return $token = (is_string($t) && $t !== '') ? $t : null;
}

/**
 * Guarda y devuelve la última respuesta del login.
 *
 * Sin esto, cuando no entra solo se puede adivinar por qué, y adivinar aquí
 * cuesta caro: «credenciales incorrectas» y «falta el campo company» piden
 * arreglos completamente distintos. El servidor ya lo dice; hay que enseñarlo.
 */
function bioUltimaAuth(?array $r = null): ?array
{
    static $ultima = null;
    if ($r !== null) $ultima = $r;
    return $ultima;
}

/** Lo que contestó el reloj al intentar entrar, en una línea legible. */
function bioPorQueNoEntra(): string
{
    $r = bioUltimaAuth();
    if ($r === null) return 'No se llegó a intentar.';
    if ($r['error']) return 'No se pudo conectar: ' . $r['error'];

    // Django REST devuelve los errores por campo; se aplanan tal cual vienen.
    $partes = [];
    foreach ((array) ($r['json'] ?? []) as $campo => $v) {
        $txt = is_array($v) ? implode(' ', array_map('strval', $v)) : (string) $v;
        $partes[] = ($campo === 'non_field_errors' ? '' : $campo . ': ') . $txt;
    }
    $dice = $partes ? implode(' · ', $partes) : bioOfuscar(mb_substr($r['raw'], 0, 160));

    return 'HTTP ' . $r['http'] . ' — ' . ($dice !== '' ? $dice : 'sin explicación');
}

/** GET autenticado. Devuelve la respuesta cruda de `bioHttp()`. */
function bioGet(string $ruta, array $params = []): array
{
    $tok = bioToken();
    if ($tok === null) {
        return ['http' => 0, 'json' => null, 'raw' => '', 'ms' => 0, 'url' => $ruta,
                'error' => 'No se pudo autenticar con el reloj. Revisa BIOTIME_EMAIL, '
                         . 'BIOTIME_CLAVE y BIOTIME_EMPRESA en la configuración.'];
    }
    $qs = $params ? '?' . http_build_query($params) : '';
    return bioHttp('GET', $ruta . $qs, ['headers' => ['Authorization: JWT ' . $tok]]);
}

/**
 * Recorre una lista paginada entera.
 *
 * BioTime devuelve `{count, next, previous, data:[...]}`. Se sigue `next` en
 * vez de ir sumando páginas a mano, y hay un tope de vueltas porque un `next`
 * que no avanza —lo he visto en otros DRF— dejaría esto girando para siempre.
 */
function bioLista(string $ruta, array $params = [], int $maxPaginas = 200): array
{
    $filas = [];
    $r = bioGet($ruta, $params + ['page_size' => 500]);
    $vueltas = 0;

    while (true) {
        if ($r['http'] !== 200 || !is_array($r['json'] ?? null)) {
            return ['ok' => false, 'filas' => $filas, 'http' => $r['http'],
                    'error' => $r['error'] ?? ('El reloj respondió ' . $r['http'] . ': '
                             . bioOfuscar(mb_substr($r['raw'], 0, 300)))];
        }
        foreach (($r['json']['data'] ?? []) as $f) $filas[] = $f;

        $sig = $r['json']['next'] ?? null;
        if (!is_string($sig) || $sig === '' || ++$vueltas >= $maxPaginas) break;
        $r = bioGet($sig);
    }
    return ['ok' => true, 'filas' => $filas, 'http' => 200, 'error' => null,
            'total' => $r['json']['count'] ?? count($filas)];
}

/** El padrón del reloj: quién está dado de alta y con qué código. */
function bioEmpleados(array $params = []): array
{
    return bioLista('/personnel/api/employees/', $params);
}

/** Marcas en crudo. Cada fila es un ponche, no un día. */
function bioPonches(string $desde, string $hasta, array $params = []): array
{
    return bioLista('/iclock/api/transactions/', $params + [
        'start_time' => $desde . ' 00:00:00',
        'end_time'   => $hasta . ' 23:59:59',
    ]);
}

/**
 * Una fila por ponche, con el nombre y el departamento pegados.
 *
 * OJO: esto NO trae el día resuelto contra el turno, aunque se llame «report».
 * Comprobado contra el servidor: sus columnas son `att_date` y `punch_time`,
 * una por marca. Ni turno, ni horas, ni tardanza. Para el día entero es
 * bioDiaPorDia().
 */
function bioReporte(string $desde, string $hasta, array $params = []): array
{
    return bioLista('/att/api/transactionReport/', $params + [
        'start_date' => $desde,
        'end_date'   => $hasta,
    ]);
}

/**
 * Una fila por persona y día: primera marca, última y total.
 *
 * Este es el que encaja con `asistencias` de Nexo, casi columna por columna:
 * first_punch → hora_entrada, last_punch → hora_salida, total_time →
 * horas_trabajadas.
 *
 * Lo que NO trae es la tardanza, porque este BioTime no tiene turnos
 * configurados —/att/api/shift/ y compañía contestan 404—. Da la hora de
 * llegada, no si esa hora es tarde. Ver docs/PONCHE-BIOTIME.md §3.
 */
function bioDiaPorDia(string $desde, string $hasta, array $params = []): array
{
    return bioLista('/att/api/firstLastReport/', $params + [
        'start_date' => $desde,
        'end_date'   => $hasta,
    ]);
}

/**
 * Diagnóstico: qué contesta el reloj a cada cosa.
 *
 * Existe para poder responder, con hechos, las tres preguntas que deciden cómo
 * se hace la integración: si entra, cómo identifica a la gente, y si el turno
 * está configurado. No escribe nada en ningún sitio.
 */
function bioDiagnostico(): array
{
    $pasos = [];
    $c = bioConfig();

    $faltan = bioFaltantes();
    $pasos[] = ['paso' => 'Configuración', 'ok' => $faltan === [],
        'detalle' => $faltan === []
            ? $c['url'] . ' · empresa «' . $c['empresa'] . '» · ' . $c['email']
            : 'Falta ' . implode(' y ', $faltan)
              . (in_array('BIOTIME_CLAVE', $faltan, true) && count($faltan) === 1
                  ? '. Pásala por el entorno y no queda escrita: '
                    . "BIOTIME_CLAVE='...' php pruebas/biotime.php"
                  : '')];
    if (!bioConfigurado()) return $pasos;

    $tok = bioToken(true);
    $pasos[] = ['paso' => 'Entrar al reloj', 'ok' => $tok !== null,
        'detalle' => $tok !== null
            ? 'Token recibido (' . strlen($tok) . ' caracteres)'
            : bioPorQueNoEntra()];
    if ($tok === null) return $pasos;

    $emp = bioEmpleados(['page_size' => 5]);
    $muestra = array_slice($emp['filas'], 0, 3);
    $pasos[] = ['paso' => 'Padrón del reloj', 'ok' => $emp['ok'],
        'detalle' => $emp['ok']
            ? ($emp['total'] ?? count($emp['filas'])) . ' persona(s). Códigos de muestra: '
              . implode(', ', array_map(fn($e) => (string) ($e['emp_code'] ?? '?'), $muestra))
            : $emp['error']];

    $hasta = date('Y-m-d');
    $desde = date('Y-m-d', strtotime('-7 days'));

    $pon = bioPonches($desde, $hasta, ['page_size' => 5]);
    $pasos[] = ['paso' => 'Ponches en crudo', 'ok' => $pon['ok'],
        'detalle' => $pon['ok']
            ? ($pon['total'] ?? count($pon['filas'])) . ' marca(s) entre ' . $desde . ' y ' . $hasta
            : $pon['error']];

    // El día por día, que es el que encaja con `asistencias`. NO trae tardanza:
    // este BioTime no tiene turnos configurados. Ver docs/PONCHE-BIOTIME.md §3.
    $rep = bioDiaPorDia($desde, $hasta, ['page_size' => 5]);
    $pasos[] = ['paso' => 'Día por día', 'ok' => $rep['ok'],
        'detalle' => $rep['ok']
            ? ($rep['total'] ?? count($rep['filas'])) . ' día(s) con primera y última marca'
            : $rep['error']];

    return $pasos;
}
