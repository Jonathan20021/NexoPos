<?php
/**
 * Funciones de ayuda globales: base de datos, formato, seguridad, UI.
 */

/* ============================================================
 *  BASE DE DATOS (envoltura sobre PDO con sentencias preparadas)
 * ============================================================ */

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
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
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
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
    foreach (['/modules/', '/install/', '/app/', '/api/', '/includes/'] as $m) {
        $p = strpos($script, $m);
        if ($p !== false) return $b = rtrim(substr($script, 0, $p), '/');
    }
    $dir = str_replace('\\', '/', dirname($script));
    return $b = ($dir === '/' ? '' : rtrim($dir, '/'));
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
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

function back(): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? url('modules/dashboard/index.php');
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

/** Genera un número correlativo con prefijo (ej. VTA-000123). */
function nextNumero(string $tabla, string $columna, string $prefijo, int $padding = 6): string
{
    $max = (int) qVal("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`$columna`,'-',-1) AS UNSIGNED)),0) FROM `$tabla`");
    return $prefijo . '-' . str_pad((string) ($max + 1), $padding, '0', STR_PAD_LEFT);
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
