<?php
/**
 * Autenticación y control de acceso basado en roles (RBAC).
 */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function is_super(): bool
{
    return !empty($_SESSION['user']['es_super']);
}

/** Sucursal activa del contexto (super admin puede cambiarla). */
function current_sucursal_id(): ?int
{
    if (isset($_SESSION['sucursal_activa']) && $_SESSION['sucursal_activa'] !== '') {
        return (int) $_SESSION['sucursal_activa'];
    }
    $u = current_user();
    return isset($u['sucursal_id']) ? (int) $u['sucursal_id'] : null;
}

function set_sucursal_activa($id): void
{
    $_SESSION['sucursal_activa'] = $id === '' ? '' : (int) $id;
}

/** Indica si el usuario actual puede operar datos de una sucursal concreta. */
function can_access_sucursal($sucursalId): bool
{
    $u = current_user();
    if (!$u) return false;
    if (is_super() || $u['sucursal_id'] === null) {
        return $sucursalId === null || (int) $sucursalId > 0;
    }
    if ($sucursalId === null || (int) $sucursalId <= 0) return false;
    return (int) $u['sucursal_id'] === (int) $sucursalId;
}

function deny_access(): void
{
    http_response_code(403);
    require __DIR__ . '/../modules/auth/403.php';
    exit;
}

/** Detiene una lectura directa que intente salir del alcance de sucursal. */
function require_sucursal_access($sucursalId): void
{
    require_login();
    if (!can_access_sucursal($sucursalId)) {
        deny_access();
    }
}

function can(string $perm): bool
{
    if (is_super()) return true;
    return in_array($perm, $_SESSION['permisos'] ?? [], true);
}

function can_any(array $perms): bool
{
    foreach ($perms as $p) {
        if (can($p)) return true;
    }
    return false;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('modules/auth/login.php');
    }
}

function require_perm(string $perm): void
{
    require_login();
    if (!can($perm)) {
        http_response_code(403);
        require __DIR__ . '/../modules/auth/403.php';
        exit;
    }
}

function load_permisos(int $rolId): array
{
    return qCol(
        "SELECT p.clave FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ?",
        [$rolId]
    );
}

/** Intenta autenticar. Devuelve [true,''] o [false, 'mensaje']. */
function login_user(string $usuario, string $password): array
{
    $u = qOne(
        "SELECT u.*, r.nombre AS rol_nombre, r.es_super, s.nombre AS sucursal_nombre
         FROM usuarios u
         JOIN roles r ON r.id = u.rol_id
         LEFT JOIN sucursales s ON s.id = u.sucursal_id
         WHERE (u.usuario = ? OR u.email = ?) LIMIT 1",
        [$usuario, $usuario]
    );

    if (!$u) {
        return [false, 'Usuario o contraseña incorrectos.'];
    }
    if ((int) $u['activo'] !== 1) {
        return [false, 'Esta cuenta está desactivada. Contacta al administrador.'];
    }
    if (!password_verify($password, $u['password_hash'])) {
        return [false, 'Usuario o contraseña incorrectos.'];
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'              => (int) $u['id'],
        'nombre'          => $u['nombre'],
        'apellido'        => $u['apellido'],
        'usuario'         => $u['usuario'],
        'email'           => $u['email'],
        'avatar'          => $u['avatar'],
        'rol_id'          => (int) $u['rol_id'],
        'rol_nombre'      => $u['rol_nombre'],
        'es_super'        => (int) $u['es_super'],
        'sucursal_id'     => $u['sucursal_id'] !== null ? (int) $u['sucursal_id'] : null,
        'sucursal_nombre' => $u['sucursal_nombre'] ?? 'Todas las sucursales',
    ];
    $_SESSION['permisos'] = load_permisos((int) $u['rol_id']);
    $_SESSION['sucursal_activa'] = $u['sucursal_id'] !== null ? (int) $u['sucursal_id'] : '';

    q("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?", [$u['id']]);
    audit('auth', 'login', 'Inicio de sesión');

    return [true, ''];
}

function logout_user(): void
{
    if (is_logged_in()) {
        audit('auth', 'logout', 'Cierre de sesión');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Iniciales para el avatar. */
function user_iniciales(?array $u = null): string
{
    $u = $u ?: current_user();
    if (!$u) return '?';
    return strtoupper(mb_substr($u['nombre'] ?? '', 0, 1) . mb_substr($u['apellido'] ?? '', 0, 1));
}
