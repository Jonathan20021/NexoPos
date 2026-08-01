<?php
/**
 * Funciones de ayuda globales: base de datos, formato, seguridad, UI.
 */

/* ============================================================
 *  BASE DE DATOS (envoltura sobre PDO con sentencias preparadas)
 * ============================================================ */

function q(string $sql, array $params = []): PDOStatement
{
    // Perfilado opcional. SQL_PROFILE no se define nunca en producción; sirve
    // para saber qué consulta de una página cuesta el tiempo, que es la única
    // forma fiable de perseguir una página lenta (ver docs/CONVENCIONES-DEV.md).
    $perfilar = defined('SQL_PROFILE');
    if ($perfilar) $t0 = microtime(true);
    $st = db()->prepare($sql);
    $st->execute($params);
    if ($perfilar) sqlPerfil(round((microtime(true) - $t0) * 1000, 2), $sql);
    return $st;
}

/**
 * Acumulador del perfilado. Sin argumentos devuelve lo registrado, de la
 * consulta más lenta a la más rápida: [['ms'=>..., 'sql'=>...], ...].
 */
function sqlPerfil(?float $ms = null, string $sql = ''): array
{
    static $registro = [];
    if ($ms !== null) {
        $registro[] = ['ms' => $ms, 'sql' => preg_replace('/\s+/', ' ', trim($sql))];
        return [];
    }
    usort($registro, fn($a, $b) => $b['ms'] <=> $a['ms']);
    return $registro;
}

function qAll(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function qOne(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Devuelve la primera columna de la primera fila (o null). */
function qVal(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Devuelve un arreglo plano de la primera columna de todas las filas. */
function qCol(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function lastId(): int
{
    return (int) db()->lastInsertId();
}

/** INSERT genérico desde arreglo asociativo. Devuelve el id insertado. */
function dbInsert(string $tabla, array $data): int
{
    $cols = array_keys($data);
    $ph   = array_map(fn($c) => ':' . $c, $cols);
    $sql  = "INSERT INTO `$tabla` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
    $st   = db()->prepare($sql);
    foreach ($data as $k => $v) {
        $st->bindValue(':' . $k, $v);
    }
    $st->execute();
    return (int) db()->lastInsertId();
}

/**
 * UPDATE genérico por condición. Usa parámetros posicionales (?) de forma
 * consistente tanto en SET como en WHERE para evitar mezclar estilos en PDO.
 * El $where debe usar marcadores posicionales: dbUpdate('t', $data, 'id = ?', [$id]).
 */
function dbUpdate(string $tabla, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    $vals = [];
    foreach ($data as $k => $v) {
        $sets[] = "`$k` = ?";
        $vals[] = $v;
    }
    $sql = "UPDATE `$tabla` SET " . implode(', ', $sets) . " WHERE $where";
    $st  = db()->prepare($sql);
    $i = 1;
    foreach ($vals as $v) {
        $st->bindValue($i++, $v);
    }
    foreach (array_values($whereParams) as $v) {
        $st->bindValue($i++, $v);
    }
    $st->execute();
    return $st->rowCount();
}

function tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Transacción que reintenta sola ante un choque transitorio de concurrencia.
 *
 * Con varias sucursales vendiendo a la vez, InnoDB puede abortar una transacción
 * por interbloqueo (1213) o por agotar la espera de un bloqueo (1205). No es un
 * error del negocio: es la base pidiendo «vuelve a intentarlo». Si eso llega a la
 * pantalla, el cajero pierde la venta sin motivo.
 *
 * Solo se reintentan esos dos códigos. Cualquier otro error (stock insuficiente,
 * cliente sin crédito, dato inválido) sube tal cual: reintentarlo no arreglaría
 * nada y ocultaría el problema real.
 */
function txReintentable(callable $fn, int $intentos = 3)
{
    for ($i = 1; ; $i++) {
        try {
            return tx($fn);
        } catch (PDOException $e) {
            $codigo = (int) ($e->errorInfo[1] ?? 0);
            if ($i >= $intentos || !in_array($codigo, [1213, 1205], true)) {
                throw $e;
            }
            // Espera creciente con algo de azar para que los reintentos no
            // vuelvan a chocar entre ellos en el mismo instante.
            usleep(random_int(30000, 120000) * $i);
        }
    }
}

/* ============================================================
 *  CONFIGURACIÓN DE EMPRESA
 * ============================================================ */

function setting(string $key, $default = null)
{
    $e = $GLOBALS['empresa'] ?? [];
    return $e[$key] ?? $default;
}

/* ============================================================
 *  FORMATO
 * ============================================================ */

function money($n, bool $simbolo = true): string
{
    $s = number_format((float) $n, 2, '.', ',');
    return $simbolo ? (setting('moneda', DEFAULT_MONEDA) . ' ' . $s) : $s;
}

function qty($n): string
{
    $n = (float) $n;
    return $n == floor($n) ? number_format($n, 0) : rtrim(rtrim(number_format($n, 3), '0'), '.');
}

function pct($n): string
{
    return number_format((float) $n, 2) . '%';
}

function fechaCorta($d): string
{
    if (!$d) return '—';
    $t = is_numeric($d) ? (int) $d : strtotime($d);
    return $t ? date('d/m/Y', $t) : '—';
}

function fechaHora($d): string
{
    if (!$d) return '—';
    $t = is_numeric($d) ? (int) $d : strtotime($d);
    return $t ? date('d/m/Y h:i A', $t) : '—';
}

function fechaLarga($d): string
{
    if (!$d) return '—';
    $meses = [1=>'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $t = is_numeric($d) ? (int) $d : strtotime($d);
    if (!$t) return '—';
    return date('d', $t) . ' ' . $meses[(int) date('n', $t)] . ', ' . date('Y', $t);
}

/** Tiempo transcurrido en lenguaje natural: «hace 5 min», «hace 3 días». */
function tiempoRelativo($d): string
{
    if (!$d) return '—';
    $t = is_numeric($d) ? (int) $d : strtotime($d);
    if (!$t) return '—';
    $seg = time() - $t;
    if ($seg < 0)     return fechaCorta($d);
    if ($seg < 60)    return 'hace un momento';
    if ($seg < 3600)  { $m = (int) ($seg / 60);    return 'hace ' . $m . ' min'; }
    if ($seg < 86400) { $h = (int) ($seg / 3600);  return 'hace ' . $h . ' hora' . ($h === 1 ? '' : 's'); }
    if ($seg < 604800){ $d2 = (int) ($seg / 86400); return 'hace ' . $d2 . ' día' . ($d2 === 1 ? '' : 's'); }
    return fechaCorta($d);
}

/** Nombre del mes en español (1-12). */
function mesNombre(int $m, bool $corto = false): string
{
    $largos = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio',
               'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $n = $largos[$m] ?? '';
    return $corto ? mb_substr($n, 0, 3) : $n;
}

/* ============================================================
 *  SEGURIDAD / ESCAPE
 * ============================================================ */

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void
{
    $t = $_POST['_csrf'] ?? '';
    $sessionToken = $_SESSION['csrf'] ?? '';
    if (!is_string($t) || $t === '' || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $t)) {
        http_response_code(403);
        die('Token de seguridad inválido. Recarga la página e intenta de nuevo.');
    }
}

/* ============================================================
 *  NAVEGACIÓN / URLS
 * ============================================================ */

/** Ruta base de la app. Auto-detecta si APP_URL está vacío. */
function base_url(): string
{
    if (APP_URL !== '') return rtrim(APP_URL, '/');
    static $b = null;
    if ($b !== null) return $b;
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    foreach (['/modules/', '/install/', '/app/', '/api/', '/includes/', '/tienda/'] as $m) {
        $p = strpos($script, $m);
        if ($p !== false) return $b = rtrim(substr($script, 0, $p), '/');
    }
    $dir = str_replace('\\', '/', dirname($script));
    return $b = ($dir === '/' ? '' : rtrim($dir, '/'));
}

/**
 * Construye una URL de la aplicación SIN la extensión .php.
 *
 * El .htaccess de la raíz sirve /modules/pos/ventas con el archivo ventas.php,
 * y redirige (301) las direcciones viejas con .php a la versión limpia. Aquí solo
 * se genera la forma limpia:
 *
 *   url('modules/pos/ventas.php')            -> /base/modules/pos/ventas
 *   url('modules/pos/ticket.php?id=5&pdf=1') -> /base/modules/pos/ticket?id=5&pdf=1
 *   url('tienda/index.php')                  -> /base/tienda/
 *   url('index.php')                         -> /base/
 *   url('assets/favicon.svg')                -> /base/assets/favicon.svg   (sin tocar)
 */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');

    // Se separa la cadena de consulta y el fragmento para no tocarlos.
    $corte = strcspn($path, '?#');
    $ruta  = substr($path, 0, $corte);
    $resto = substr($path, $corte);

    if ($ruta === 'index.php') {
        $ruta = '';
    } elseif (str_ends_with($ruta, '/index.php')) {
        $ruta = substr($ruta, 0, -strlen('index.php'));   // conserva la barra final
    } elseif (str_ends_with($ruta, '.php')) {
        $ruta = substr($ruta, 0, -4);
    }

    return base_url() . '/' . $ruta . $resto;
}

function asset(string $path = ''): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    $loc = (strpos($path, 'http') === 0) ? $path : url($path);
    header('Location: ' . $loc);
    exit;
}

/**
 * Acepta solo destinos locales absolutos. Evita redirecciones externas por
 * barras invertidas, URLs relativas al protocolo o caracteres de control.
 */
function local_redirect_target($target, ?string $fallback = null): string
{
    $fallback ??= url('modules/dashboard/index.php');
    if (!is_string($target) || $target === '' || $target[0] !== '/') {
        return $fallback;
    }
    $decoded = rawurldecode($target);
    if (str_starts_with($decoded, '//') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
        return $fallback;
    }
    return $target;
}

function back(): void
{
    $ref = local_redirect_target($_SERVER['HTTP_REFERER'] ?? '');
    header('Location: ' . $ref);
    exit;
}

function current_path(): string
{
    return $_SERVER['REQUEST_URI'] ?? '';
}

function active(string $needle, string $class = 'nav-active'): string
{
    return strpos(current_path(), $needle) !== false ? $class : '';
}

/* ============================================================
 *  MENSAJES FLASH
 * ============================================================ */

function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ============================================================
 *  ENTRADA / VALIDACIÓN
 * ============================================================ */

function input(string $key, $default = '')
{
    return $_REQUEST[$key] ?? $default;
}

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function postNum(string $key, $default = 0): float
{
    $v = $_POST[$key] ?? $default;
    return (float) str_replace(',', '', (string) $v);
}

function postInt(string $key, $default = 0): int
{
    return (int) ($_POST[$key] ?? $default);
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Reserva y devuelve el siguiente correlativo con prefijo (ej. VTA-000123).
 *
 * El número se toma de la tabla `contadores` con un UPDATE atómico. Esto NO es
 * un detalle de estilo: la versión anterior hacía «SELECT MAX(...) + 1» y luego
 * insertaba, así que dos cajas vendiendo en el mismo segundo generaban el mismo
 * número y una de las dos ventas moría contra el índice UNIQUE. Un UPDATE es
 * indivisible: cada llamada se lleva un número distinto, vengan de donde vengan.
 *
 * Consume el número, así que solo debe llamarse cuando el registro se va a
 * insertar de verdad. Para mostrarlo en un formulario usa previewNumero().
 */
function nextNumero(string $tabla, string $columna, string $prefijo, int $padding = 6): string
{
    $clave = $tabla . '.' . $columna . '.' . $prefijo;

    // Primera vez: el contador arranca donde terminaron los datos que ya existen.
    if (!qVal("SELECT 1 FROM contadores WHERE nombre = ?", [$clave])) {
        q(
            "INSERT IGNORE INTO contadores (nombre, valor)
             SELECT ?, COALESCE(MAX(CAST(SUBSTRING_INDEX(`$columna`,'-',-1) AS UNSIGNED)),0)
               FROM `$tabla` WHERE `$columna` LIKE ?",
            [$clave, $prefijo . '-%']
        );
    }

    // Hasta 5 vueltas por si alguien insertó un número a mano por fuera del
    // contador: se salta el ocupado en vez de reventar contra el UNIQUE.
    for ($i = 0; $i < 5; $i++) {
        q("UPDATE contadores SET valor = LAST_INSERT_ID(valor + 1) WHERE nombre = ?", [$clave]);
        $n = (int) db()->lastInsertId();
        if ($n <= 0) {
            throw new RuntimeException('No se pudo reservar el correlativo de ' . $tabla . '.');
        }
        $numero = $prefijo . '-' . str_pad((string) $n, $padding, '0', STR_PAD_LEFT);
        if (!qVal("SELECT 1 FROM `$tabla` WHERE `$columna` = ?", [$numero])) {
            return $numero;
        }
    }
    throw new RuntimeException('No se pudo generar un correlativo libre para ' . $tabla . '.');
}

/**
 * Muestra cuál sería el próximo correlativo SIN consumirlo.
 * Para rellenar un campo de formulario: si se usara nextNumero(), cada vez que
 * alguien abre la pantalla se quemaría un número.
 */
function previewNumero(string $tabla, string $columna, string $prefijo, int $padding = 6): string
{
    $clave = $tabla . '.' . $columna . '.' . $prefijo;
    $actual = qVal("SELECT valor FROM contadores WHERE nombre = ?", [$clave]);
    if ($actual === null) {
        $actual = qVal(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`$columna`,'-',-1) AS UNSIGNED)),0)
               FROM `$tabla` WHERE `$columna` LIKE ?",
            [$prefijo . '-%']
        );
    }
    return $prefijo . '-' . str_pad((string) ((int) $actual + 1), $padding, '0', STR_PAD_LEFT);
}

function badgeEstado(string $estado): array
{
    $map = [
        'activo'      => ['Activo', 'emerald'],
        'inactivo'    => ['Inactivo', 'slate'],
        'completada'  => ['Completada', 'emerald'],
        'pendiente'   => ['Pendiente', 'amber'],
        'anulada'     => ['Anulada', 'rose'],
        'devuelta'    => ['Devuelta', 'rose'],
        'abierta'     => ['Abierta', 'emerald'],
        'cerrada'     => ['Cerrada', 'slate'],
        'borrador'    => ['Borrador', 'slate'],
        'procesada'   => ['Procesada', 'sky'],
        'pagada'      => ['Pagada', 'emerald'],
        'recibida'    => ['Recibida', 'emerald'],
        'enviada'     => ['Enviada', 'sky'],
        'aprobada'    => ['Aprobada', 'emerald'],
        'rechazada'   => ['Rechazada', 'rose'],
        'solicitada'  => ['Solicitada', 'amber'],
        'disfrutada'  => ['Disfrutada', 'sky'],
    ];
    return $map[strtolower($estado)] ?? [ucfirst($estado), 'slate'];
}
