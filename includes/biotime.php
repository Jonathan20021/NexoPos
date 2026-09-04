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

/* ===========================================================================
 *  De las marcas del reloj a `asistencias`
 *
 *  Las reglas de abajo no son estilo: cada una tapa un fallo que se vio en los
 *  datos reales de este cliente. Si alguna se quita, vuelve el fallo.
 * ======================================================================== */

/** Desfase esperado entre la hora de la marca y la de subida: RD es UTC−4. */
const BIO_DESFASE_ESPERADO_MIN = -240;

/** Cuánto puede desviarse de eso antes de que la marca no sea de fiar. */
const BIO_DESFASE_TOLERANCIA_MIN = 15;

/**
 * Convierte la fecha del reloj a la del sistema.
 *
 * `firstLastReport` devuelve «31-08-2026», día primero. `strtotime()` lee eso
 * como mes primero cuando el día es ≤ 12 —«05-08-2026» sale 8 de mayo— y el
 * error es invisible: la fila se guarda, en el día equivocado. Por eso se parte
 * a mano en vez de confiar en la conversión automática.
 */
function bioFechaISO(?string $fecha): ?string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') return null;
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $fecha, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $fecha : null;
    }
    if (preg_match('~^(\d{2})-(\d{2})-(\d{4})$~', $fecha, $m)) {
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
            ? $m[3] . '-' . $m[2] . '-' . $m[1] : null;
    }
    return null;
}

/** «08:45» o «08:45:26» → «08:45:00». Cualquier otra cosa, null. */
function bioHoraISO(?string $hora): ?string
{
    $hora = trim((string) $hora);
    if (!preg_match('~^(\d{1,2}):(\d{2})(?::(\d{2}))?$~', $hora, $m)) return null;
    if ((int) $m[1] > 23 || (int) $m[2] > 59) return null;
    return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
}

/**
 * ¿El aparato tiene la hora bien puesta?
 *
 * `punch_time` es hora local de RD y `upload_time` es UTC, así que la
 * diferencia tiene que ser de cuatro horas. En los datos de este cliente lo es
 * en 28 de 31 marcas; las tres que se salen —hasta 17 horas de desvío— son de
 * un aparato en montaje con el reloj mal puesto.
 *
 * Sin esta comprobación, un aparato desajustado mete horas falsas en la nómina
 * y nadie se entera hasta que alguien reclama.
 */
function bioDesfaseMinutos(array $marca): ?int
{
    $p = strtotime((string) ($marca['punch_time'] ?? ''));
    $u = strtotime((string) ($marca['upload_time'] ?? ''));
    if (!$p || !$u) return null;
    return (int) round(($p - $u) / 60);
}

function bioRelojDeFiar(array $marca): bool
{
    $d = bioDesfaseMinutos($marca);
    // Sin `upload_time` no se puede comprobar; se acepta, porque el reporte
    // por días no lo trae y bloquearlo dejaría la integración sin datos.
    if ($d === null) return true;
    return abs($d - BIO_DESFASE_ESPERADO_MIN) <= BIO_DESFASE_TOLERANCIA_MIN;
}

/**
 * La equivalencia entre el código del reloj y la persona de Nexo.
 *
 * Solo mira `biotime_emp_code`. NUNCA adivina por nombre ni por parecido: al
 * probarlo, emparejar por nombre eligió a la persona equivocada. Un código sin
 * equivalencia se informa y se salta; no se inventa.
 */
function bioEmpleadoDe(string $empCode, bool $olvidar = false): ?array
{
    // La caché vale DENTRO de una pasada, donde nadie cambia de estado a media
    // sincronización, y se tira al empezar la siguiente. Guardar la fila entera
    // —con su `estado`— entre dos pasadas daría de alta la asistencia de alguien
    // que ya se fue, porque se recordaría el «activo» de la vez anterior.
    static $cache = [];
    if ($olvidar) { $cache = []; return null; }

    $empCode = trim($empCode);
    if ($empCode === '') return null;
    if (array_key_exists($empCode, $cache)) return $cache[$empCode];

    return $cache[$empCode] = qOne(
        "SELECT id, nombre, apellido, sucursal_id, estado
           FROM empleados WHERE biotime_emp_code = ?", [$empCode]
    ) ?: null;
}

/* ===========================================================================
 *  Las marcas en bruto
 *
 *  `asistencias` es el resumen del día; esto es lo que de verdad registró el
 *  aparato. Se guarda entero porque el resumen pierde lo de en medio, y porque
 *  «¿a qué hora salió a almorzar?» solo se puede contestar con el dato crudo.
 * ======================================================================== */

/**
 * Cómo se identificó la persona, en cristiano.
 *
 * Las marcas en bruto traen un número; el informe trae la etiqueta. La
 * equivalencia de abajo NO está sacada de un manual: se dedujo cruzando las dos
 * fuentes sobre los datos reales de este cliente —verify_type 1 aparecía 6
 * veces como «Fingerprint», 3 ocho veces como «Password» y 15 veintisiete veces
 * como «Face»—. Un código que no esté aquí se enseña tal cual en vez de
 * inventarle un nombre.
 */
function bioVerificacion($v): string
{
    $v = trim((string) $v);
    return [
        '1'  => 'Huella',
        '3'  => 'Contraseña',
        '15' => 'Rostro',
    ][$v] ?? ($v === '' ? '—' : $v);
}

/**
 * Baja las marcas de un rango y las guarda.
 *
 * Idempotente por `biotime_id`: traer dos veces el mismo rango no duplica. Y no
 * borra nada, así que una marca que el reloj purgue con el tiempo se queda
 * aquí, que es justo lo que se le pide a un histórico.
 */
function bioGuardarMarcas(string $desde, string $hasta, bool $simular = false): array
{
    $parte = ['leidas' => 0, 'nuevas' => 0, 'ya_estaban' => 0, 'filas' => [],
              'reloj_desajustado' => [], 'ilegibles' => 0, 'error' => null];

    $r = bioPonches($desde, $hasta);
    if (!$r['ok']) { $parte['error'] = $r['error']; return $parte; }

    // El emparejamiento se resuelve una vez para todo el lote.
    $de = [];
    foreach (qAll("SELECT id, biotime_emp_code FROM empleados WHERE biotime_emp_code IS NOT NULL") as $e) {
        $de[(string) $e['biotime_emp_code']] = (int) $e['id'];
    }

    // Las marcas en bruto NO traen el nombre, solo el código. Se pide el padrón
    // del reloj una vez y se guarda el nombre junto a cada marca: sin él, quien
    // no esté emparejado aparecería en los avisos como un número suelto y no
    // habría forma de saber a quién reclamarle.
    $nombres = [];
    $pad = bioEmpleados();
    if ($pad['ok']) {
        foreach ($pad['filas'] as $p) {
            $n = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
            if ($n !== '') $nombres[(string) $p['emp_code']] = mb_substr($n, 0, 120);
        }
    }

    foreach ($r['filas'] as $m) {
        $parte['leidas']++;
        $bid  = (int) ($m['id'] ?? 0);
        $code = trim((string) ($m['emp_code'] ?? ''));
        $ts   = strtotime((string) ($m['punch_time'] ?? ''));
        if ($bid <= 0 || $code === '' || !$ts) { $parte['ilegibles']++; continue; }

        $yaEstaba = (bool) qVal("SELECT id FROM asistencia_marcas WHERE biotime_id = ?", [$bid]);

        $desfase = bioDesfaseMinutos($m);
        if ($desfase !== null && abs($desfase - BIO_DESFASE_ESPERADO_MIN) > BIO_DESFASE_TOLERANCIA_MIN) {
            // No se descarta: se guarda con su desfase anotado. Tirar el dato
            // dejaría un hueco sin explicación; guardarlo marcado deja ver que
            // ese aparato tenía la hora mal.
            $parte['reloj_desajustado'][(string) ($m['terminal_alias'] ?? '?')] = $desfase;
        }

        $fila = [
            'biotime_id'   => $bid,
            'emp_code'     => $code,
            'empleado_id'  => $de[$code] ?? null,
            'fecha'        => date('Y-m-d', $ts),
            'hora'         => date('H:i:s', $ts),
            'marcada_en'   => date('Y-m-d H:i:s', $ts),
            'subida_en'    => ($u = strtotime((string) ($m['upload_time'] ?? ''))) ? date('Y-m-d H:i:s', $u) : null,
            'desfase_min'  => $desfase,
            'terminal'     => mb_substr((string) ($m['terminal_alias'] ?? ''), 0, 80) ?: null,
            'verificacion' => mb_substr((string) ($m['verify_type'] ?? ''), 0, 30) ?: null,
            'nombre_reloj' => $nombres[$code] ?? null,
        ];
        // Se devuelven TODAS —las nuevas y las que ya estaban— porque de ellas
        // sale el día, y en simulación no hay nada guardado de donde sacarlo.
        $parte['filas'][] = $fila;

        if ($yaEstaba) { $parte['ya_estaban']++; continue; }
        if (!$simular) dbInsert('asistencia_marcas', $fila);
        $parte['nuevas']++;
    }
    return $parte;
}

/**
 * El día de cada persona a partir de unas marcas que ya se tienen en la mano.
 *
 * Es la misma cuenta que `bioDiasDesdeMarcas()` pero sin pasar por la base, que
 * es lo que permite que «ver qué haría» prediga el resultado sin escribir nada.
 */
function bioDiasDeMarcas(array $marcas): array
{
    $por = [];
    foreach ($marcas as $m) {
        $k = $m['emp_code'] . '|' . $m['fecha'];
        if (!isset($por[$k])) {
            $por[$k] = ['emp_code' => $m['emp_code'],
                        'att_date' => date('d-m-Y', strtotime($m['fecha'])),
                        'first_punch' => $m['hora'], 'last_punch' => $m['hora'], 'marcas' => 0,
                        'first_name' => (string) ($m['nombre_reloj'] ?? ''), 'last_name' => ''];
        }
        if ($m['hora'] < $por[$k]['first_punch']) $por[$k]['first_punch'] = $m['hora'];
        if ($m['hora'] > $por[$k]['last_punch'])  $por[$k]['last_punch']  = $m['hora'];
        $por[$k]['marcas']++;
    }
    ksort($por);
    return array_values($por);
}

/**
 * Pone al día el `empleado_id` de las marcas guardadas.
 *
 * Las marcas se guardan aunque su persona no esté emparejada todavía: son un
 * hecho ocurrido, no dependen de que alguien haya hecho su parte. Cuando el
 * emparejamiento llega, esta función las reclama hacia atrás — si no, el
 * histórico de esa persona empezaría el día en que se la empareja.
 */
function bioReclamarMarcas(): int
{
    $n = 0;
    foreach (qAll("SELECT id, biotime_emp_code FROM empleados WHERE biotime_emp_code IS NOT NULL") as $e) {
        $n += (int) q("UPDATE asistencia_marcas SET empleado_id = ?
                        WHERE emp_code = ? AND (empleado_id IS NULL OR empleado_id <> ?)",
                      [(int) $e['id'], (string) $e['biotime_emp_code'], (int) $e['id']])->rowCount();
    }
    // Y al revés: si a alguien se le quitó la equivalencia, sus marcas dejan de
    // apuntarle. Un histórico que sigue nombrando a quien ya no corresponde es
    // peor que uno incompleto.
    $n += (int) q("UPDATE asistencia_marcas m
                      LEFT JOIN empleados e ON e.biotime_emp_code = m.emp_code
                     SET m.empleado_id = NULL
                   WHERE m.empleado_id IS NOT NULL AND e.id IS NULL")->rowCount();
    return $n;
}

/**
 * El día de cada persona, sacado de las marcas guardadas.
 *
 * Devuelve la misma forma que `firstLastReport` para que la sincronización no
 * tenga que distinguir de dónde viene el dato. La diferencia es que aquí el
 * origen es local: se puede recalcular sin volver a pedirle nada al reloj.
 */
function bioDiasDesdeMarcas(string $desde, string $hasta): array
{
    $filas = qAll(
        "SELECT emp_code,
                DATE_FORMAT(fecha, '%d-%m-%Y') AS att_date,
                MIN(hora) AS first_punch,
                MAX(hora) AS last_punch,
                COUNT(*)  AS marcas
           FROM asistencia_marcas
          WHERE fecha BETWEEN ? AND ?
          GROUP BY emp_code, fecha
          ORDER BY fecha, emp_code",
        [$desde, $hasta]
    );
    // El nombre solo se usa para poder nombrar a quien no está emparejado.
    foreach ($filas as &$f) {
        $f['first_name'] = (string) qVal(
            "SELECT nombre_reloj FROM asistencia_marcas
              WHERE emp_code = ? AND nombre_reloj IS NOT NULL
              ORDER BY id DESC LIMIT 1", [$f['emp_code']]);
        $f['last_name'] = '';
    }
    unset($f);   // sin esto, la última fila se pisa en el próximo foreach por referencia
    return $filas;
}

/**
 * Trae los días del reloj y los deja en `asistencias`.
 *
 * Devuelve el parte de lo ocurrido: qué se escribió, qué se respetó y qué se
 * quedó fuera y por qué. No lanza excepción por una fila mala — una persona sin
 * emparejar no puede tumbar la sincronización de las otras cincuenta.
 *
 * LO QUE NO HACE, A PROPÓSITO:
 *
 *   · No escribe ausencias. «No hay marca» y «no vino» son cosas distintas: en
 *     este cliente solo ponchan 6 de 48, así que dar por ausente a quien no
 *     marcó llenaría la nómina de faltas falsas. La ausencia la decide una
 *     persona.
 *   · No pisa lo que corrigió un humano (`origen = 'manual'`). Lo informa.
 *   · No inventa la hora de salida cuando solo hay una marca.
 */
function bioSincronizar(string $desde, string $hasta, array $opciones = []): array
{
    $simular = !empty($opciones['simular']);
    bioEmpleadoDe('', true);   // cada pasada relee el padrón
    $parte = [
        'creadas' => 0, 'actualizadas' => 0, 'sin_cambio' => 0,
        'respetadas_manual' => [], 'sin_emparejar' => [], 'reloj_desajustado' => [],
        'incompletas' => [], 'fecha_mala' => 0, 'inactivos' => [],
        'desde' => $desde, 'hasta' => $hasta, 'simulado' => $simular, 'error' => null,
    ];

    // `filas` permite reprocesar lo ya traído sin volver a pedirlo —y es lo
    // que deja probar cada regla de abajo con casos que en los datos reales de
    // este cliente todavía no han ocurrido, pero ocurrirán.
    if (isset($opciones['filas'])) {
        $r = ['ok' => true, 'filas' => (array) $opciones['filas'], 'error' => null];
    } else {
        // Primero se guardan las marcas en bruto y después se calcula el día A
        // PARTIR de ellas, en vez de pedirle al reloj un segundo informe. Así
        // hay una sola fuente —lo que la pantalla enseña y lo que la nómina usa
        // salen de las mismas filas—, se conservan las marcas intermedias, y el
        // desfase del aparato queda comprobado: `firstLastReport` no trae
        // `upload_time`, así que por ahí esa comprobación nunca se disparaba.
        $g = bioGuardarMarcas($desde, $hasta, $simular);
        if ($g['error']) { $parte['error'] = $g['error']; return $parte; }
        if (!$simular) bioReclamarMarcas();
        $parte['marcas_nuevas']      = $g['nuevas'];
        $parte['marcas_ya_estaban']  = $g['ya_estaban'];
        $parte['reloj_desajustado']  = $g['reloj_desajustado'];
        // El día sale de las marcas que se acaban de leer, no de la base: en
        // simulación no se ha guardado nada y consultarla daría un resultado
        // viejo, que es peor que ninguno porque parece bueno.
        $r = ['ok' => true, 'filas' => bioDiasDeMarcas($g['filas']), 'error' => null];
    }
    if (!$r['ok']) { $parte['error'] = $r['error']; return $parte; }

    $ahora = date('Y-m-d H:i:s');

    foreach ($r['filas'] as $f) {
        $code  = trim((string) ($f['emp_code'] ?? ''));
        $fecha = bioFechaISO($f['att_date'] ?? null);
        if ($fecha === null) { $parte['fecha_mala']++; continue; }

        // Nunca fuera de lo pedido: si el servidor devuelve de más, no se
        // escribe. Sincronizar «ayer» no puede tocar el mes pasado.
        if ($fecha < $desde || $fecha > $hasta) { $parte['fecha_mala']++; continue; }

        $emp = bioEmpleadoDe($code);
        if ($emp === null) {
            $nom = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''));
            $parte['sin_emparejar'][$code] = $nom !== '' ? $nom : '(sin nombre)';
            continue;
        }
        if ($emp['estado'] === 'inactivo') {
            $parte['inactivos'][$code] = trim($emp['nombre'] . ' ' . $emp['apellido']);
            continue;
        }

        $entrada = bioHoraISO($f['first_punch'] ?? null);
        $salida  = bioHoraISO($f['last_punch'] ?? null);
        if ($entrada === null) { $parte['fecha_mala']++; continue; }

        // Una sola marca: se sabe que vino, no a qué hora se fue. Poner la
        // misma hora en las dos columnas diría «trabajó cero horas», que es
        // mentira y además se paga.
        $unaSola = ($salida === null || $salida === $entrada);
        if ($unaSola) {
            $salida = null;
            $parte['incompletas'][] = trim($emp['nombre'] . ' ' . $emp['apellido']) . ' · ' . $fecha;
        }

        $horas = 0.0;
        if ($salida !== null) {
            $h = (strtotime($fecha . ' ' . $salida) - strtotime($fecha . ' ' . $entrada)) / 3600;
            // Una jornada que cruza medianoche sale negativa. No se corrige
            // sola —«primera y última del día» ya la partió en dos— y se marca
            // como incompleta en vez de guardar horas absurdas.
            if ($h < 0) { $salida = null; $parte['incompletas'][] = trim($emp['nombre'] . ' ' . $emp['apellido']) . ' · ' . $fecha . ' (cruza medianoche)'; }
            else        { $horas = round($h, 2); }
        }

        $ya = qOne("SELECT id, origen, hora_entrada, hora_salida FROM asistencias
                     WHERE empleado_id = ? AND fecha = ?", [(int) $emp['id'], $fecha]);

        if ($ya && $ya['origen'] === 'manual') {
            // Lo tocó una persona: manda ella. Se dice la diferencia para que
            // alguien pueda mirarla, pero no se pisa.
            //
            // Se comparan LAS DOS horas. Mirar solo la entrada dejaba mudo el
            // caso más común —y el motivo de que esto exista—: alguien olvidó
            // ponchar la salida y se la pusieron a mano. Ahí la entrada coincide
            // y la salida no, así que no se avisaba de nada.
            $difEnt = (string) $ya['hora_entrada'] !== (string) $entrada;
            $difSal = (string) ($ya['hora_salida'] ?? '') !== (string) ($salida ?? '');
            if ($difEnt || $difSal) {
                $comoEsta = ($ya['hora_entrada'] ?: '—') . '–' . ($ya['hora_salida'] ?: '—');
                $comoElReloj = $entrada . '–' . ($salida ?: '—');
                $parte['respetadas_manual'][] = trim($emp['nombre'] . ' ' . $emp['apellido'])
                    . ' · ' . $fecha . ' · Nexo dice ' . $comoEsta . ' y el reloj ' . $comoElReloj;
            }
            continue;
        }

        $datos = [
            'hora_entrada'     => $entrada,
            'hora_salida'      => $salida,
            'horas_trabajadas' => $horas,
            'horas_extra'      => 0,
            'estado'           => 'presente',
            'origen'           => 'biotime',
            'biotime_sync_at'  => $ahora,
        ];

        if ($ya) {
            if ((string) $ya['hora_entrada'] === (string) $entrada
                && (string) $ya['hora_salida'] === (string) $salida) {
                $parte['sin_cambio']++;
                continue;
            }
            if (!$simular) dbUpdate('asistencias', $datos, 'id = ?', [(int) $ya['id']]);
            $parte['actualizadas']++;
        } else {
            if (!$simular) {
                dbInsert('asistencias', $datos + [
                    'empleado_id' => (int) $emp['id'],
                    'sucursal_id' => (int) $emp['sucursal_id'],
                    'fecha'       => $fecha,
                ]);
            }
            $parte['creadas']++;
        }
    }

    return $parte;
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
