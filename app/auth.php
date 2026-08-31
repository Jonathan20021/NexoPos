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

/* ============================================================
 *  INICIO DE SESIÓN EN DOS PASOS
 *
 *  Paso 1  login_intentar()      valida usuario y contraseña
 *  Paso 2  login_confirmar_otp() valida el código que llegó por correo
 *
 *  La sesión NO existe hasta que termina el paso que corresponda. Entre uno y
 *  otro solo vive `$_SESSION['otp_login']`, que no concede ningún permiso.
 *  El motor del segundo factor está en `includes/otp.php`.
 * ============================================================ */

/** Busca la cuenta por usuario o correo, con su rol y su sucursal. */
function login_buscar_usuario(string $identificador): ?array
{
    return qOne(
        "SELECT u.*, r.nombre AS rol_nombre, r.es_super, s.nombre AS sucursal_nombre
         FROM usuarios u
         JOIN roles r ON r.id = u.rol_id
         LEFT JOIN sucursales s ON s.id = u.sucursal_id
         WHERE (u.usuario = ? OR u.email = ?) LIMIT 1",
        [$identificador, $identificador]
    );
}

/**
 * Paso 1: credenciales.
 *
 * @return array{estado:string, mensaje:string}  estado ∈ ok | otp | error
 */
function login_intentar(string $usuario, string $password): array
{
    $error = static fn(string $m) => ['estado' => 'error', 'mensaje' => $m];

    if ($usuario === '' || $password === '') {
        return $error('Escribe tu usuario y tu contraseña.');
    }

    // Fuerza bruta: se corta ANTES de tocar la base con la contraseña.
    $espera = otp_login_bloqueado($usuario);
    if ($espera > 0) {
        return $error('Demasiados intentos fallidos. Vuelve a intentarlo en ' . otp_espera_texto($espera) . '.');
    }

    $u = login_buscar_usuario($usuario);

    // Mensaje idéntico para «no existe» y «contraseña mala»: decir cuál de las
    // dos falló le regala al atacante la lista de usuarios válidos.
    if (!$u || !password_verify($password, $u['password_hash'])) {
        otp_registrar_intento('password', 'login:' . mb_strtolower($usuario), false,
            $u ? (int) $u['id'] : null, $u ? 'contraseña incorrecta' : 'usuario inexistente');
        otp_registrar_intento('password', 'ip:' . otp_ip(), false, $u ? (int) $u['id'] : null, 'fallo');
        if ($u) {
            audit('auth', 'login_fallido', 'Contraseña incorrecta desde ' . (otp_ip() ?: 'IP desconocida'),
                ['usuario_id' => (int) $u['id'],
                 'usuario_nombre' => trim($u['nombre'] . ' ' . $u['apellido'])]);
        }
        // Si el siguiente fallo bloquea, conviene decirlo antes de que ocurra.
        $restantes = LOGIN_MAX_FALLOS_CUENTA - otp_fallos('password', 'login:' . mb_strtolower($usuario));
        return $error('Usuario o contraseña incorrectos.'
            . ($restantes > 0 && $restantes <= 2 ? ' Te queda' . ($restantes === 1 ? ' 1 intento' : "n $restantes intentos")
                . ' antes de que la cuenta se bloquee temporalmente.' : ''));
    }

    if ((int) $u['activo'] !== 1) {
        otp_registrar_intento('password', 'login:' . mb_strtolower($usuario), false, (int) $u['id'], 'cuenta desactivada');
        return $error('Esta cuenta está desactivada. Contacta al administrador.');
    }

    otp_registrar_intento('password', 'login:' . mb_strtolower($usuario), true, (int) $u['id'], 'contraseña correcta');
    otp_registrar_intento('password', 'ip:' . otp_ip(), true, (int) $u['id'], 'contraseña correcta');

    // --- ¿Segundo factor? ---
    $req = otp_requerido($u);
    if (!$req['requerido']) {
        login_establecer_sesion($u, ['segundo_factor' => $req['motivo']]);
        return ['estado' => 'ok', 'mensaje' => ''];
    }

    $emision = otp_emitir($u, 'login', ['inicial' => true]);
    if (!$emision['ok'] && $emision['otp_id'] === null) {
        // No se llegó a emitir nada (freno de reenvío, correo inválido…).
        return $error($emision['error']);
    }

    // Identificador nuevo para el paso intermedio: nada de reutilizar el que
    // traía el visitante anónimo.
    session_regenerate_id(true);

    $_SESSION['otp_login'] = [
        'usuario_id' => (int) $u['id'],
        'otp_id'     => (int) $emision['otp_id'],
        'usuario'    => (string) $u['usuario'],
        'nombre'     => (string) $u['nombre'],
        'email'      => (string) $u['email'],
        'ua'         => otp_ua_hash(),
        'creado'     => time(),
        'expira'     => (int) ($emision['expira'] ?? (time() + 600)),
        'codigo_dev' => $emision['codigo_dev'],
        'aviso'      => $emision['ok'] ? '' : $emision['error'],
    ];

    return ['estado' => 'otp', 'mensaje' => $emision['ok'] ? '' : $emision['error']];
}

/**
 * Paso intermedio guardado en la sesión, ya validado.
 *
 * Devuelve null (y limpia) si caducó o si el navegador no es el mismo que pidió
 * el código: un identificador de sesión robado a medio camino no sirve de nada.
 */
function login_pendiente(): ?array
{
    $p = $_SESSION['otp_login'] ?? null;
    if (!is_array($p) || empty($p['usuario_id']) || empty($p['otp_id'])) return null;

    if ((time() - (int) $p['creado']) > OTP_PENDIENTE_VIDA_MIN * 60) {
        login_pendiente_limpiar();
        return null;
    }
    if (!hash_equals((string) $p['ua'], otp_ua_hash())) {
        login_pendiente_limpiar();
        return null;
    }
    return $p;
}

function login_pendiente_limpiar(): void
{
    unset($_SESSION['otp_login']);
}

/**
 * Paso 2: confirma el código y abre la sesión de verdad.
 *
 * @return array{ok:bool, mensaje:string, reiniciar:bool}  reiniciar = hay que volver al login
 */
function login_confirmar_otp(string $codigo, bool $recordarEquipo = false): array
{
    $p = login_pendiente();
    if (!$p) {
        return ['ok' => false, 'reiniciar' => true,
                'mensaje' => 'La verificación caducó. Inicia sesión otra vez.'];
    }

    $r = otp_verificar((int) $p['otp_id'], $codigo);
    if (!$r['ok']) {
        if ($r['agotado']) {
            audit('auth', 'otp_fallido', 'Verificación no superada: ' . $r['error'],
                ['usuario_id' => (int) $p['usuario_id'], 'usuario_nombre' => $p['nombre']]);
        }
        return ['ok' => false, 'reiniciar' => false, 'mensaje' => $r['error']];
    }

    // La cuenta se relee AHORA: entre el paso 1 y el 2 pudieron desactivarla.
    $u = qOne(
        "SELECT u.*, r.nombre AS rol_nombre, r.es_super, s.nombre AS sucursal_nombre
         FROM usuarios u
         JOIN roles r ON r.id = u.rol_id
         LEFT JOIN sucursales s ON s.id = u.sucursal_id
         WHERE u.id = ? LIMIT 1",
        [(int) $p['usuario_id']]
    );
    if (!$u || (int) $u['activo'] !== 1) {
        login_pendiente_limpiar();
        return ['ok' => false, 'reiniciar' => true,
                'mensaje' => 'Esta cuenta ya no está activa. Contacta al administrador.'];
    }

    login_pendiente_limpiar();

    if ($recordarEquipo && otp_config()['permite_recordar']) {
        $idDisp = otp_recordar_dispositivo((int) $u['id']);
        if ($idDisp) {
            audit('auth', 'dispositivo_confiable', 'Equipo marcado como de confianza: ' . otp_dispositivo_nombre(),
                ['usuario_id' => (int) $u['id'], 'usuario_nombre' => trim($u['nombre'] . ' ' . $u['apellido']),
                 'tabla' => 'login_dispositivos', 'registro_id' => $idDisp]);
        }
    }

    login_establecer_sesion($u, ['segundo_factor' => 'otp_verificado']);
    return ['ok' => true, 'reiniciar' => false, 'mensaje' => ''];
}

/**
 * Reenvía el código del paso pendiente.
 * @return array{ok:bool, mensaje:string, espera:int}
 */
function login_reenviar_otp(): array
{
    $p = login_pendiente();
    if (!$p) {
        return ['ok' => false, 'espera' => 0, 'mensaje' => 'La verificación caducó. Inicia sesión otra vez.'];
    }

    $u = login_buscar_usuario((string) $p['usuario']);
    if (!$u || (int) $u['activo'] !== 1) {
        login_pendiente_limpiar();
        return ['ok' => false, 'espera' => 0, 'mensaje' => 'Esta cuenta ya no está activa.'];
    }

    $r = otp_emitir($u);
    if ($r['otp_id'] !== null) {
        $_SESSION['otp_login']['otp_id']     = (int) $r['otp_id'];
        $_SESSION['otp_login']['expira']     = (int) $r['expira'];
        $_SESSION['otp_login']['codigo_dev'] = $r['codigo_dev'];
    }
    return ['ok' => $r['ok'], 'espera' => (int) $r['espera'],
            'mensaje' => $r['ok'] ? 'Te enviamos un código nuevo.' : $r['error']];
}

/**
 * Abre la sesión con los datos del usuario. Es el ÚNICO sitio donde se escribe
 * `$_SESSION['user']`: si alguna vez hay que endurecer el acceso, se endurece aquí.
 */
function login_establecer_sesion(array $u, array $opts = []): void
{
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
    $_SESSION['login_at'] = time();
    $_SESSION['segundo_factor'] = (string) ($opts['segundo_factor'] ?? 'no_aplica');

    // Cualquier código que quedara vivo deja de servir en cuanto se entra.
    otp_anular_vivos((int) $u['id'], 'login', 'sesion_iniciada');

    q("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?", [$u['id']]);

    $comoEntro = [
        'otp_verificado'        => 'con verificación en dos pasos',
        'equipo_de_confianza'   => 'desde un equipo de confianza',
        'usuario_exento'        => 'sin segundo factor (cuenta exenta)',
        'correo_no_configurado' => 'sin segundo factor (correo no configurado)',
        'sin_correo_valido'     => 'sin segundo factor (la cuenta no tiene correo válido)',
    ][$_SESSION['segundo_factor']] ?? 'sin segundo factor';

    audit('auth', 'login', 'Inicio de sesión ' . $comoEntro);
}

function logout_user(): void
{
    if (is_logged_in()) {
        audit('auth', 'logout', 'Cierre de sesión');
    }
    login_pendiente_limpiar();
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
    return mb_strtoupper(mb_substr($u['nombre'] ?? '', 0, 1) . mb_substr($u['apellido'] ?? '', 0, 1), 'UTF-8');
}
