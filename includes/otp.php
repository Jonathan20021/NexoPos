<?php
/**
 * Verificación en dos pasos (OTP por correo) para el inicio de sesión.
 *
 * El flujo completo vive aquí; `app/auth.php` solo lo orquesta y las páginas de
 * `modules/auth/` lo dibujan. Ver `docs/OTP-LOGIN.md`.
 *
 * REGLAS QUE SOSTIENEN ESTE ARCHIVO
 * ---------------------------------
 *  1. El código no se guarda en claro. Se guarda su bcrypt. Ni un volcado de la
 *     base entrega códigos utilizables.
 *  2. Cada código es de un solo uso, caduca, y muere tras N intentos fallidos.
 *     Emitir uno nuevo anula el anterior: nunca hay dos códigos válidos a la vez.
 *  3. La sesión NO se inicia hasta que el código se verifica. Mientras tanto solo
 *     existe `$_SESSION['otp_login']`, que no otorga ningún permiso.
 *  4. Todo se cuenta: fallos de contraseña, fallos de código y reenvíos. De esos
 *     contadores salen los bloqueos por fuerza bruta.
 *  5. Un fallo de correo NO deja entrar a nadie… salvo que el correo no esté
 *     configurado en absoluto (ver `otp_operativo()`), donde bloquear equivaldría
 *     a dejar al cliente fuera de su propio sistema.
 */

// --- Parámetros de seguridad (no configurables desde la interfaz a propósito) ---
const OTP_LONGITUD            = 6;     // dígitos del código
const OTP_MAX_INTENTOS        = 5;     // fallos permitidos por código
const OTP_REENVIO_ESPERA      = 60;    // segundos mínimos entre dos reenvíos manuales
const OTP_MAX_ENVIOS_HORA     = 10;    // envíos por usuario y hora (techo duro)
const OTP_PENDIENTE_VIDA_MIN  = 20;    // vida del paso intermedio en la sesión
const OTP_PURGA_DIAS          = 45;    // histórico que se conserva

// --- Límites del primer factor (contraseña) ---
const LOGIN_VENTANA_MIN       = 15;    // ventana deslizante del contador
const LOGIN_MAX_FALLOS_CUENTA = 5;     // fallos por cuenta dentro de la ventana
const LOGIN_MAX_FALLOS_IP     = 20;    // fallos por IP dentro de la ventana

const OTP_COOKIE_DISPOSITIVO  = 'NEXOPOS_DISP';

/* ============================================================
 *  DISPONIBILIDAD Y CONFIGURACIÓN
 * ============================================================ */

/**
 * ¿Está aplicada la migración P14?
 *
 * Se comprueba ANTES de tocar cualquier tabla del módulo: el código puede
 * desplegarse antes que la migración, y en ese hueco el login tiene que seguir
 * funcionando como siempre en vez de reventar con «table doesn't exist».
 */
function otp_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $n = (int) qVal(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('login_otp','login_intentos','login_dispositivos')"
        );
        $ok = $n === 3;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Política vigente. Los valores de fábrica valen aunque falte la migración. */
function otp_config(): array
{
    $e = $GLOBALS['empresa'] ?? [];
    $modo = (string) ($e['otp_modo'] ?? 'siempre');
    if (!in_array($modo, ['siempre', 'dispositivo_nuevo', 'nunca'], true)) {
        $modo = 'siempre';
    }
    $vigencia = (int) ($e['otp_vigencia_min'] ?? 10);
    $dias     = (int) ($e['otp_recordar_dias'] ?? 30);

    return [
        'modo'           => $modo,
        'vigencia_min'   => max(2, min(60, $vigencia ?: 10)),
        'recordar_dias'  => max(0, min(365, $dias)),
        'permite_recordar' => $modo === 'dispositivo_nuevo' && $dias > 0,
    ];
}

/**
 * ¿Se puede exigir el segundo factor ahora mismo?
 *
 * Sin RESEND_API_KEY no hay forma de entregar el código. Exigirlo dejaría al
 * cliente fuera de su propio sistema por un dato de configuración del servidor,
 * así que en ese caso se deja pasar y se avisa a gritos en Seguridad de acceso y
 * en el centro de notificaciones. Quien quiera cerrar también esa puerta define
 * OTP_EXIGIR_SIEMPRE en config.local.php y entonces nadie entra sin código.
 */
function otp_operativo(): bool
{
    return mail_configurado() || (defined('OTP_EXIGIR_SIEMPRE') && OTP_EXIGIR_SIEMPRE);
}

/** ¿La política está activa (independientemente de si hoy se puede enviar)? */
function otp_politica_activa(): bool
{
    if (defined('OTP_DESACTIVADO') && OTP_DESACTIVADO) return false;   // llave de emergencia
    if (!otp_disponible()) return false;
    return otp_config()['modo'] !== 'nunca';
}

/* ============================================================
 *  CONTEXTO DE LA PETICIÓN
 * ============================================================ */

function otp_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function otp_ua(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/** Huella del navegador. Ata el código pendiente al equipo que lo pidió. */
function otp_ua_hash(): string
{
    return hash('sha256', otp_ua());
}

/** Nombre legible del equipo: «Chrome en Windows». Solo para que el usuario se reconozca. */
function otp_dispositivo_nombre(string $ua = ''): string
{
    $ua = $ua !== '' ? $ua : otp_ua();
    if ($ua === '') return 'Equipo desconocido';

    $navegador = 'Navegador';
    foreach ([
        'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome',
        'Firefox/' => 'Firefox', 'Safari/' => 'Safari',
    ] as $aguja => $nombre) {
        if (stripos($ua, $aguja) !== false) { $navegador = $nombre; break; }
    }

    $sistema = 'un equipo';
    foreach ([
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
        'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'Macintosh' => 'Mac',
        'Linux' => 'Linux',
    ] as $aguja => $nombre) {
        if (stripos($ua, $aguja) !== false) { $sistema = $nombre; break; }
    }

    return mb_substr($navegador . ' en ' . $sistema, 0, 80);
}

/**
 * Enmascara el correo para mostrarlo sin revelarlo entero.
 * juan.perez@gmail.com → ju••••••••@gm•••.com
 */
function otp_email_mascara(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) return '•••••';

    $usuario = substr($email, 0, $at);
    $dominio = substr($email, $at + 1);

    $tapar = static function (string $s, int $visibles): string {
        $len = mb_strlen($s);
        if ($len <= $visibles) return mb_substr($s, 0, 1) . str_repeat('•', max(1, $len - 1));
        return mb_substr($s, 0, $visibles) . str_repeat('•', min(8, $len - $visibles));
    };

    $punto = strrpos($dominio, '.');
    $tld   = $punto !== false ? substr($dominio, $punto) : '';
    $base  = $punto !== false ? substr($dominio, 0, $punto) : $dominio;

    return $tapar($usuario, 2) . '@' . $tapar($base, 2) . $tld;
}

/* ============================================================
 *  CONTADOR DE INTENTOS (base de los bloqueos)
 * ============================================================ */

/** Deja constancia de un intento. Nunca interrumpe la operación. */
function otp_registrar_intento(string $tipo, string $clave, bool $exito, ?int $usuarioId = null, string $detalle = ''): void
{
    if (!otp_disponible()) return;
    try {
        dbInsert('login_intentos', [
            'tipo'       => $tipo,
            'clave'      => mb_substr($clave, 0, 190),
            'exito'      => $exito ? 1 : 0,
            'usuario_id' => $usuarioId,
            'ip'         => otp_ip(),
            'detalle'    => $detalle !== '' ? mb_substr($detalle, 0, 120) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Un fallo del contador no puede tumbar el inicio de sesión.
    }
}

/**
 * Fallos dentro de la ventana, contando solo los POSTERIORES al último acierto.
 *
 * Ese matiz importa: si alguien falla cuatro veces y entra bien a la quinta, no
 * debe arrastrar los cuatro fallos y quedar bloqueado en su siguiente descuido.
 */
function otp_fallos(string $tipo, string $clave, int $minutos = LOGIN_VENTANA_MIN): int
{
    if (!otp_disponible()) return 0;
    try {
        $desde = date('Y-m-d H:i:s', time() - $minutos * 60);
        return (int) qVal(
            "SELECT COUNT(*) FROM login_intentos
              WHERE tipo = ? AND clave = ? AND exito = 0 AND created_at >= ?
                AND id > COALESCE((SELECT MAX(i2.id) FROM login_intentos i2
                                    WHERE i2.tipo = login_intentos.tipo AND i2.clave = login_intentos.clave
                                      AND i2.exito = 1 AND i2.created_at >= ?), 0)",
            [$tipo, $clave, $desde, $desde]
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/** Momento (timestamp) del último evento de ese tipo, o null. */
function otp_ultimo_evento(string $tipo, string $clave): ?int
{
    if (!otp_disponible()) return null;
    try {
        $f = qVal("SELECT MAX(created_at) FROM login_intentos WHERE tipo = ? AND clave = ?", [$tipo, $clave]);
        return $f ? strtotime($f) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Eventos de ese tipo dentro de la ventana (aciertos y fallos). */
function otp_eventos(string $tipo, string $clave, int $minutos): int
{
    if (!otp_disponible()) return 0;
    try {
        return (int) qVal(
            "SELECT COUNT(*) FROM login_intentos WHERE tipo = ? AND clave = ? AND created_at >= ?",
            [$tipo, $clave, date('Y-m-d H:i:s', time() - $minutos * 60)]
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * ¿Está bloqueado el primer factor? Devuelve los segundos que faltan, o 0.
 *
 * Dos límites a la vez: por cuenta (protege a una persona concreta) y por IP
 * (protege al sistema de un barrido por diccionario contra muchas cuentas).
 */
function otp_login_bloqueado(string $identificador): int
{
    if (!otp_disponible()) return 0;

    $espera = 0;
    foreach ([
        ['login:' . mb_strtolower($identificador), LOGIN_MAX_FALLOS_CUENTA],
        ['ip:' . otp_ip(),                          LOGIN_MAX_FALLOS_IP],
    ] as [$clave, $tope]) {
        if ($clave === 'ip:') continue;                 // sin IP no se bloquea a ciegas
        if (otp_fallos('password', $clave) < $tope) continue;

        $ultimo = otp_ultimo_evento('password', $clave) ?? time();
        $espera = max($espera, LOGIN_VENTANA_MIN * 60 - (time() - $ultimo));
    }
    return max(0, $espera);
}

/** «3 minutos», «45 segundos» — para decirle a la persona cuánto falta. */
function otp_espera_texto(int $segundos): string
{
    if ($segundos <= 60) return max(1, $segundos) . ' segundos';
    $min = (int) ceil($segundos / 60);
    return $min . ' minuto' . ($min === 1 ? '' : 's');
}

/* ============================================================
 *  ¿HACE FALTA EL SEGUNDO FACTOR?
 * ============================================================ */

/**
 * Decide si este usuario, en este equipo, necesita código.
 * @return array{requerido:bool, motivo:string}
 */
function otp_requerido(array $u): array
{
    if (!otp_politica_activa())            return ['requerido' => false, 'motivo' => 'politica_desactivada'];
    if ((int) ($u['otp_activo'] ?? 1) !== 1) return ['requerido' => false, 'motivo' => 'usuario_exento'];

    if (!otp_operativo()) {
        // El correo no está configurado: no hay forma de entregar el código.
        return ['requerido' => false, 'motivo' => 'correo_no_configurado'];
    }
    if (!filter_var((string) ($u['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return ['requerido' => false, 'motivo' => 'sin_correo_valido'];
    }

    $cfg = otp_config();
    if ($cfg['modo'] === 'dispositivo_nuevo' && otp_dispositivo_confiable((int) $u['id'])) {
        return ['requerido' => false, 'motivo' => 'equipo_de_confianza'];
    }
    return ['requerido' => true, 'motivo' => $cfg['modo']];
}

/* ============================================================
 *  EMISIÓN Y VERIFICACIÓN DEL CÓDIGO
 * ============================================================ */

/** Código de 6 dígitos con generador criptográfico. Conserva los ceros a la izquierda. */
function otp_generar_codigo(): string
{
    $max = (10 ** OTP_LONGITUD) - 1;
    return str_pad((string) random_int(0, $max), OTP_LONGITUD, '0', STR_PAD_LEFT);
}

/**
 * Emite un código y lo envía por correo.
 *
 * `$opts['inicial']` marca la emisión que nace de validar la contraseña, y
 * cambia dos cosas frente a un reenvío pedido a mano:
 *
 *   · No se aplica la espera de OTP_REENVIO_ESPERA. Aplicarla rompía un caso
 *     real: cerrar sesión y volver a entrar en menos de un minuto dejaba a la
 *     persona sin poder acceder, con la contraseña correcta y sin culpa alguna.
 *   · Si ya hay un código vivo, recién emitido y sin usar, se REUTILIZA en vez de
 *     mandar otro correo: el usuario ya lo tiene en la bandeja, y así un doble
 *     clic en «Iniciar sesión» no le llena el buzón ni le gasta la cuota.
 *
 * El techo de OTP_MAX_ENVIOS_HORA se respeta siempre: es el límite que impide
 * usar el formulario de acceso como ametralladora de correos.
 *
 * @return array{ok:bool, otp_id:?int, error:string, espera:int, expira:?int, codigo_dev:?string}
 */
function otp_emitir(array $u, string $proposito = 'login', array $opts = []): array
{
    $inicial = !empty($opts['inicial']);
    $fallo = static fn(string $msg, int $espera = 0) =>
        ['ok' => false, 'otp_id' => null, 'error' => $msg, 'espera' => $espera, 'expira' => null, 'codigo_dev' => null];

    if (!otp_disponible()) {
        return $fallo('La verificación en dos pasos no está instalada en este servidor.');
    }
    $uid   = (int) $u['id'];
    $email = trim((string) ($u['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $fallo('Tu cuenta no tiene un correo válido para recibir el código. Contacta al administrador.');
    }

    // --- Freno de reenvíos: por tiempo y por volumen ---
    $claveEnvio = 'user:' . $uid;
    $ultimo = otp_ultimo_evento('envio', $claveEnvio);

    if ($inicial) {
        // ¿Hay un código recién enviado, intacto y todavía válido? Se reaprovecha.
        $vivo = qOne(
            "SELECT id, expira_en FROM login_otp
              WHERE usuario_id = ? AND proposito = ? AND usado_en IS NULL AND anulado_en IS NULL
                AND enviado = 1 AND intentos = 0 AND expira_en > NOW()
                AND created_at >= NOW() - INTERVAL ? SECOND
              ORDER BY id DESC LIMIT 1",
            [$uid, $proposito, OTP_REENVIO_ESPERA]
        );
        if ($vivo) {
            return ['ok' => true, 'otp_id' => (int) $vivo['id'], 'error' => '',
                    'espera' => max(0, OTP_REENVIO_ESPERA - (time() - ($ultimo ?? time()))),
                    'expira' => strtotime($vivo['expira_en']), 'codigo_dev' => null];
        }
    } elseif ($ultimo !== null) {
        $faltan = OTP_REENVIO_ESPERA - (time() - $ultimo);
        if ($faltan > 0) {
            return $fallo('Espera ' . otp_espera_texto($faltan) . ' antes de pedir otro código.', $faltan);
        }
    }

    if (otp_eventos('envio', $claveEnvio, 60) >= OTP_MAX_ENVIOS_HORA) {
        return $fallo('Se alcanzó el máximo de códigos por hora para esta cuenta. Intenta de nuevo más tarde.', 900);
    }

    // Nunca dos códigos vivos: el nuevo mata al anterior.
    otp_anular_vivos($uid, $proposito, 'reemplazado');

    $cfg     = otp_config();
    $codigo  = otp_generar_codigo();
    $expira  = time() + $cfg['vigencia_min'] * 60;

    $otpId = dbInsert('login_otp', [
        'usuario_id'   => $uid,
        'proposito'    => $proposito,
        'codigo_hash'  => password_hash($codigo, PASSWORD_DEFAULT),
        'destino'      => mb_substr($email, 0, 120),
        'max_intentos' => OTP_MAX_INTENTOS,
        'ip'           => otp_ip(),
        'user_agent'   => otp_ua(),
        'ua_hash'      => otp_ua_hash(),
        'expira_en'    => date('Y-m-d H:i:s', $expira),
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    // --- Envío ---
    $asunto = $codigo . ' es tu código de acceso a ' . (setting('nombre') ?: APP_NAME);
    $r = mail_enviar($email, $asunto, otp_email_html($u, $codigo, $cfg['vigencia_min']));
    mail_registrar(null, 'otp_login', $email, $asunto, $r);

    dbUpdate('login_otp', [
        'enviado'      => $r['ok'] ? 1 : 0,
        'error_envio'  => $r['error'] ? mb_substr($r['error'], 0, 255) : null,
        'proveedor_id' => $r['id'],
    ], 'id = ?', [$otpId]);

    otp_registrar_intento('envio', $claveEnvio, $r['ok'], $uid, $r['ok'] ? 'enviado' : (string) $r['error']);
    audit('auth', 'otp_enviado',
        ($r['ok'] ? 'Código de verificación enviado a ' : 'Fallo al enviar el código a ') . otp_email_mascara($email),
        ['usuario_id' => $uid, 'usuario_nombre' => trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')),
         'tabla' => 'login_otp', 'registro_id' => $otpId]);

    otp_limpiar();

    // En desarrollo SIN correo configurado el código se muestra en pantalla: de
    // otro modo no habría manera de probar el flujo en XAMPP. Jamás en producción.
    $dev = (APP_ENV !== 'production' && !mail_configurado()) ? $codigo : null;

    if (!$r['ok'] && $dev === null) {
        // Se deja el código emitido: si el correo llega con retraso, sigue siendo válido.
        return [
            'ok' => false, 'otp_id' => $otpId, 'espera' => 0, 'expira' => $expira, 'codigo_dev' => null,
            'error' => 'No se pudo enviar el código a tu correo. ' . ($r['error'] ?: '') ,
        ];
    }

    return ['ok' => true, 'otp_id' => $otpId, 'error' => '', 'espera' => OTP_REENVIO_ESPERA,
            'expira' => $expira, 'codigo_dev' => $dev];
}

/** Anula los códigos vivos del usuario (al emitir otro, al entrar, al cambiar contraseña). */
function otp_anular_vivos(int $usuarioId, string $proposito = 'login', string $motivo = 'anulado'): void
{
    if (!otp_disponible()) return;
    try {
        q("UPDATE login_otp SET anulado_en = NOW(), motivo_anulacion = ?
            WHERE usuario_id = ? AND proposito = ? AND usado_en IS NULL AND anulado_en IS NULL",
          [mb_substr($motivo, 0, 60), $usuarioId, $proposito]);
    } catch (Throwable $e) {
        // No es crítico: el código caduca solo.
    }
}

/**
 * Comprueba un código.
 *
 * El intento se suma con un UPDATE condicional ANTES de comparar: así dos
 * peticiones en paralelo no pueden gastar el mismo intento dos veces ni superar
 * el tope probando en ráfaga. Solo después se hace el `password_verify`, que es
 * la parte lenta.
 *
 * @return array{ok:bool, error:string, agotado:bool}
 */
function otp_verificar(int $otpId, string $codigo): array
{
    $codigo = preg_replace('/\D+/', '', (string) $codigo);

    if (!otp_disponible()) {
        return ['ok' => false, 'error' => 'La verificación no está disponible.', 'agotado' => true];
    }
    if (strlen($codigo) !== OTP_LONGITUD) {
        return ['ok' => false, 'error' => 'El código debe tener ' . OTP_LONGITUD . ' dígitos.', 'agotado' => false];
    }

    $consumido = q(
        "UPDATE login_otp SET intentos = intentos + 1
          WHERE id = ? AND usado_en IS NULL AND anulado_en IS NULL
            AND expira_en > NOW() AND intentos < max_intentos",
        [$otpId]
    )->rowCount();

    $fila = qOne("SELECT * FROM login_otp WHERE id = ?", [$otpId]);
    if (!$fila) {
        return ['ok' => false, 'error' => 'La verificación caducó. Vuelve a iniciar sesión.', 'agotado' => true];
    }

    if ($consumido === 0) {
        // Se explica el porqué exacto: son estados del propio usuario, no filtran nada.
        if ($fila['usado_en'])                         $err = 'Ese código ya se usó. Pide uno nuevo.';
        elseif ($fila['anulado_en'])                   $err = 'Ese código dejó de ser válido. Pide uno nuevo.';
        elseif (strtotime($fila['expira_en']) <= time()) $err = 'El código caducó. Pide uno nuevo.';
        else                                           $err = 'Demasiados intentos fallidos. Pide un código nuevo.';
        return ['ok' => false, 'error' => $err, 'agotado' => true];
    }

    $uid = (int) $fila['usuario_id'];

    if (!password_verify($codigo, $fila['codigo_hash'])) {
        // `$fila` se leyó DESPUÉS del UPDATE, así que `intentos` ya incluye este.
        $restantes = max(0, (int) $fila['max_intentos'] - (int) $fila['intentos']);
        otp_registrar_intento('otp', 'user:' . $uid, false, $uid, 'codigo incorrecto');

        if ($restantes === 0) {
            otp_anular_vivos($uid, (string) $fila['proposito'], 'intentos_agotados');
            audit('auth', 'otp_bloqueado', 'Código de verificación agotado por intentos fallidos',
                ['usuario_id' => $uid, 'tabla' => 'login_otp', 'registro_id' => $otpId]);
            return ['ok' => false, 'error' => 'Código incorrecto. Se agotaron los intentos: pide un código nuevo.', 'agotado' => true];
        }
        return [
            'ok' => false, 'agotado' => false,
            'error' => 'Código incorrecto. Te queda' . ($restantes === 1 ? ' 1 intento.' : "n $restantes intentos."),
        ];
    }

    // Marcar como usado también es condicional: garantiza un solo uso real.
    $usado = q("UPDATE login_otp SET usado_en = NOW() WHERE id = ? AND usado_en IS NULL", [$otpId])->rowCount();
    if ($usado === 0) {
        return ['ok' => false, 'error' => 'Ese código ya se usó. Pide uno nuevo.', 'agotado' => true];
    }

    otp_registrar_intento('otp', 'user:' . $uid, true, $uid, 'verificado');
    return ['ok' => true, 'error' => '', 'agotado' => false];
}

/** Datos del código pendiente (para la cuenta atrás y los avisos de la pantalla). */
function otp_info(int $otpId): ?array
{
    if (!otp_disponible()) return null;
    return qOne("SELECT id, usuario_id, destino, intentos, max_intentos, enviado, expira_en, usado_en, anulado_en
                   FROM login_otp WHERE id = ?", [$otpId]);
}

/* ============================================================
 *  EQUIPOS DE CONFIANZA
 * ============================================================ */

/** Opciones de la cookie, alineadas con las de la sesión (HTTPS incluido). */
function otp_cookie_opts(int $expira): array
{
    $seguro = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return [
        'expires'  => $expira,
        'path'     => base_url() . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $seguro,
    ];
}

/** Token del equipo tal como viaja en la cookie (o cadena vacía). */
function otp_cookie_token(): string
{
    $t = (string) ($_COOKIE[OTP_COOKIE_DISPOSITIVO] ?? '');
    return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
}

/**
 * ¿El equipo actual está marcado como de confianza para este usuario?
 * De paso refresca `ultimo_uso`, que es lo que el usuario ve en su perfil.
 */
function otp_dispositivo_confiable(int $usuarioId): bool
{
    if (!otp_disponible()) return false;
    $token = otp_cookie_token();
    if ($token === '') return false;

    try {
        $d = qOne(
            "SELECT id FROM login_dispositivos
              WHERE token_hash = ? AND usuario_id = ? AND revocado_en IS NULL AND expira_en > NOW()",
            [hash('sha256', $token), $usuarioId]
        );
        if (!$d) return false;
        q("UPDATE login_dispositivos SET ultimo_uso = NOW(), ip = ? WHERE id = ?", [otp_ip(), (int) $d['id']]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Marca este equipo como de confianza. Devuelve el id del registro.
 *
 * El token se rota en cada marcado: si el anterior se filtró, deja de servir.
 */
function otp_recordar_dispositivo(int $usuarioId): ?int
{
    $cfg = otp_config();
    if (!otp_disponible() || $cfg['recordar_dias'] <= 0) return null;

    try {
        // El equipo que ya estuviera registrado con esta cookie se retira.
        $anterior = otp_cookie_token();
        if ($anterior !== '') {
            q("UPDATE login_dispositivos SET revocado_en = NOW()
                WHERE token_hash = ? AND usuario_id = ? AND revocado_en IS NULL",
              [hash('sha256', $anterior), $usuarioId]);
        }

        $token  = bin2hex(random_bytes(32));
        $expira = time() + $cfg['recordar_dias'] * 86400;

        $id = dbInsert('login_dispositivos', [
            'usuario_id' => $usuarioId,
            'token_hash' => hash('sha256', $token),
            'nombre'     => otp_dispositivo_nombre(),
            'ip'         => otp_ip(),
            'user_agent' => otp_ua(),
            'ultimo_uso' => date('Y-m-d H:i:s'),
            'expira_en'  => date('Y-m-d H:i:s', $expira),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!headers_sent()) {
            setcookie(OTP_COOKIE_DISPOSITIVO, $token, otp_cookie_opts($expira));
        }
        return $id;
    } catch (Throwable $e) {
        return null;   // no poder recordar el equipo no debe impedir entrar
    }
}

/** Equipos de confianza vivos de un usuario, el más reciente primero. */
function otp_dispositivos(int $usuarioId): array
{
    if (!otp_disponible()) return [];
    try {
        return qAll(
            "SELECT * FROM login_dispositivos
              WHERE usuario_id = ? AND revocado_en IS NULL AND expira_en > NOW()
              ORDER BY COALESCE(ultimo_uso, created_at) DESC",
            [$usuarioId]
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** ¿Cuál de la lista es el equipo desde el que se está mirando? */
function otp_dispositivo_actual_id(int $usuarioId): ?int
{
    $token = otp_cookie_token();
    if ($token === '' || !otp_disponible()) return null;
    $id = qVal("SELECT id FROM login_dispositivos WHERE token_hash = ? AND usuario_id = ?",
        [hash('sha256', $token), $usuarioId]);
    return $id !== null ? (int) $id : null;
}

/** Revoca un equipo concreto. Solo del propio usuario indicado. */
function otp_revocar_dispositivo(int $id, int $usuarioId): bool
{
    if (!otp_disponible()) return false;
    $n = q("UPDATE login_dispositivos SET revocado_en = NOW()
             WHERE id = ? AND usuario_id = ? AND revocado_en IS NULL", [$id, $usuarioId])->rowCount();
    if ($n > 0 && otp_dispositivo_actual_id($usuarioId) === $id && !headers_sent()) {
        setcookie(OTP_COOKIE_DISPOSITIVO, '', otp_cookie_opts(time() - 42000));
    }
    return $n > 0;
}

/** Revoca todos los equipos de un usuario. Devuelve cuántos cayeron. */
function otp_revocar_todos(int $usuarioId): int
{
    if (!otp_disponible()) return 0;
    $n = q("UPDATE login_dispositivos SET revocado_en = NOW()
             WHERE usuario_id = ? AND revocado_en IS NULL", [$usuarioId])->rowCount();
    if ($n > 0 && !headers_sent() && otp_cookie_token() !== '') {
        setcookie(OTP_COOKIE_DISPOSITIVO, '', otp_cookie_opts(time() - 42000));
    }
    return $n;
}

/* ============================================================
 *  MANTENIMIENTO
 * ============================================================ */

/**
 * Purga el histórico. Corre como mucho una vez por hora y sin cron, igual que el
 * barrido de notificaciones: sin esto `login_intentos` crece sin techo.
 */
function otp_limpiar(): void
{
    if (!otp_disponible()) return;
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;

    try {
        // Mismo turno atómico que el barrido de notificaciones: aunque entren
        // diez peticiones en el mismo segundo, la purga corre una sola vez.
        $turno = q(
            "INSERT INTO sistema_estado (clave, valor, updated_at)
             VALUES ('otp_purga', UNIX_TIMESTAMP(), NOW())
             ON DUPLICATE KEY UPDATE
                valor      = IF(updated_at < (NOW() - INTERVAL 60 MINUTE), UNIX_TIMESTAMP(), valor),
                updated_at = IF(updated_at < (NOW() - INTERVAL 60 MINUTE), NOW(), updated_at)"
        )->rowCount();
        if ($turno === 0) return;

        $corte = date('Y-m-d H:i:s', time() - OTP_PURGA_DIAS * 86400);
        q("DELETE FROM login_otp WHERE created_at < ?", [$corte]);
        q("DELETE FROM login_intentos WHERE created_at < ?", [$corte]);
        q("DELETE FROM login_dispositivos WHERE expira_en < ? OR (revocado_en IS NOT NULL AND revocado_en < ?)",
          [$corte, $corte]);
    } catch (Throwable $e) {
        // El mantenimiento nunca rompe el inicio de sesión.
    }
}

/* ============================================================
 *  CORREO DEL CÓDIGO
 * ============================================================ */

/**
 * Cuerpo del correo. Reutiliza la plantilla de marca del sistema (mail.php) para
 * que el código llegue con el mismo logo, colores y pie que el resto de envíos.
 *
 * CRITERIOS DE MAQUETACIÓN (un correo no es una página web):
 *
 *  · Todo en tablas y estilos en línea. Es lo único que Outlook, Gmail y los
 *    clientes móviles renderizan igual.
 *  · El código va en UN solo bloque de texto, no en seis casillas. En el móvil,
 *    seis celdas no se pueden seleccionar de una pasada y la persona termina
 *    tecleando a mano; un bloque se mantiene pulsado y se copia entero.
 *  · El panel del código se tiñe con el color de marca aclarado
 *    (`mail_color_claro`), así combina con cualquier marca —verde, azul o
 *    morada— sin tocar nada.
 *  · Outlook de escritorio ignora `border-radius` y estrecha el `letter-spacing`:
 *    el bloque sigue siendo legible porque el tamaño y el contraste no dependen
 *    de esas dos propiedades.
 *
 * La IP, el equipo y la hora van a propósito: son la información con la que la
 * persona se da cuenta de que ese intento no es suyo.
 */
function otp_email_html(array $u, string $codigo, int $vigenciaMin): string
{
    $empresa  = $GLOBALS['empresa'] ?? [];
    $marca    = mail_color(mail_diseno()['color'], '#15803D');
    $suave    = mail_color_claro($marca, 0.93);   // fondo del panel del código
    $borde    = mail_color_claro($marca, 0.78);   // borde del mismo panel
    $nombre   = trim((string) ($u['nombre'] ?? ''));
    $negocio  = trim((string) ($empresa['nombre'] ?? '')) ?: APP_NAME;

    // El código se parte en dos mitades: 158 402 se lee y se teclea mucho mejor
    // que 158402, y al copiarlo el espacio se limpia en el formulario.
    $mitad   = (int) ceil(strlen($codigo) / 2);
    $legible = substr($codigo, 0, $mitad) . ' ' . substr($codigo, $mitad);

    $detalle = static fn(string $k, string $v) =>
        '<tr>
           <td style="padding:5px 0;color:#64748B;white-space:nowrap;">' . e($k) . '</td>
           <td style="padding:5px 0;text-align:right;color:#1E293B;font-weight:600;">' . e($v) . '</td>
         </tr>';

    $contenido = '
      <p style="margin:0 0 4px;font:700 20px/1.3 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#0F172A;">
        Verificación de acceso</p>
      <p style="margin:0 0 20px;color:#475569;">
        Hola' . ($nombre !== '' ? ' <strong style="color:#0F172A;">' . e($nombre) . '</strong>' : '') . ',
        recibimos un intento de iniciar sesión en <strong style="color:#0F172A;">' . e($negocio) . '</strong>.
        Escribe este código para continuar.</p>

      <!-- Panel del código -->
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="background:' . $suave . ';border:1px solid ' . $borde . ';border-radius:14px;">
        <tr>
          <td align="center" style="padding:26px 20px 22px;">
            <p style="margin:0 0 12px;font:700 11px/1 -apple-system,Segoe UI,Roboto,Arial,sans-serif;
                      letter-spacing:1.4px;text-transform:uppercase;color:#64748B;">
              Código de verificación</p>
            <p style="margin:0;font:700 38px/1.1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
                      letter-spacing:6px;color:' . $marca . ';white-space:nowrap;">' . e($legible) . '</p>
            <p style="margin:14px 0 0;font:400 13px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#64748B;">
              Vence en <strong style="color:#1E293B;">' . (int) $vigenciaMin . ' minutos</strong>
              &nbsp;·&nbsp; Se puede usar una sola vez</p>
          </td>
        </tr>
      </table>

      <p style="margin:22px 0 6px;font-weight:700;color:#0F172A;font-size:14px;">Detalles de la solicitud</p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="font:400 13px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;
                    border-top:1px solid #E2E8F0;">'
        . $detalle('Cuenta',        (string) ($u['usuario'] ?? ''))
        . $detalle('Fecha y hora',  date('d/m/Y h:i A'))
        . $detalle('Equipo',        otp_dispositivo_nombre())
        . $detalle('Dirección IP',  otp_ip() ?: 'No disponible')
        . '</table>

      <!-- Aviso de seguridad -->
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="margin-top:22px;background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;">
        <tr>
          <td style="padding:14px 16px;font:400 13px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#991B1B;">
            <strong>¿No fuiste tú?</strong> Significa que alguien más conoce tu contraseña.
            No uses este código, cambia tu contraseña en cuanto puedas y avisa al administrador
            del sistema.
          </td>
        </tr>
      </table>

      <p style="margin:20px 0 0;font:400 12px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#94A3B8;">
        Este código es personal. Nadie de ' . e($negocio) . ' te lo pedirá nunca por teléfono,
        WhatsApp ni correo electrónico.</p>';

    return mail_plantilla('Código de verificación', $contenido, $empresa,
        'Tu código vence en ' . (int) $vigenciaMin . ' minutos y sirve una sola vez.');
}
