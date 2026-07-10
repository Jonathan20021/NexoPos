<?php
/**
 * Punto de arranque común. Cada página hace:
 *   require_once dirname(__DIR__, N) . '/app/bootstrap.php';
 */

require_once dirname(__DIR__) . '/config/config.php';

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

require_once dirname(__DIR__) . '/config/database.php';

// Autoloader de Composer (Dompdf, PhpSpreadsheet)
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once dirname(__DIR__) . '/includes/components.php';
require_once dirname(__DIR__) . '/includes/operaciones.php';
require_once dirname(__DIR__) . '/includes/dgii.php';
require_once dirname(__DIR__) . '/includes/dgii_reportes.php';
require_once dirname(__DIR__) . '/includes/mail.php';
require_once dirname(__DIR__) . '/includes/correos_pedido.php';
require_once dirname(__DIR__) . '/includes/metas.php';
require_once dirname(__DIR__) . '/includes/export.php';
require_once dirname(__DIR__) . '/includes/uploads.php';
require_once dirname(__DIR__) . '/includes/backup.php';
if (is_file($autoload)) {
    require_once dirname(__DIR__) . '/includes/excel.php';
    require_once dirname(__DIR__) . '/includes/pdf.php';
}

if (session_status() === PHP_SESSION_NONE) {
    $__secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime'  => 0,
        'path'      => '/',
        'httponly'  => true,
        'samesite'  => 'Lax',
        'secure'    => $__secure,
    ]);
    session_name('NEXOPOS_SESSID');
    session_start();
}

// Cabeceras de seguridad (respaldo por si el servidor no aplica .htaccess)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

// Cargar configuración de empresa; si la base no existe, sugerir el instalador.
$GLOBALS['empresa'] = [];
$enInstalador = defined('NEXOPOS_INSTALLER')
    || strpos($_SERVER['SCRIPT_NAME'] ?? '', 'install') !== false;
try {
    $GLOBALS['empresa'] = qOne("SELECT * FROM empresa LIMIT 1") ?: [];
} catch (Throwable $e) {
    if (!$enInstalador) {
        http_response_code(503);
        $instalarUrl = url('install/index.php');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Instalación requerida</title>'
            . '<script src="https://cdn.tailwindcss.com"></script></head>'
            . '<body class="min-h-screen bg-slate-100 flex items-center justify-center p-6">'
            . '<div class="max-w-md w-full bg-white rounded-2xl shadow-xl border border-slate-200 p-8 text-center">'
            . '<div class="w-14 h-14 rounded-2xl bg-blue-600 text-white flex items-center justify-center mx-auto mb-4 text-2xl font-bold">N</div>'
            . '<h1 class="text-xl font-bold text-slate-800">Base de datos no encontrada</h1>'
            . '<p class="text-slate-500 mt-2 text-sm">El sistema aún no está instalado. Ejecuta el instalador para crear la base de datos y los datos iniciales.</p>'
            . '<a href="' . e($instalarUrl) . '" class="inline-flex mt-6 items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-xl transition">Ejecutar instalador</a>'
            . (APP_ENV === 'production' ? '' : '<p class="text-xs text-slate-400 mt-4">' . e($e->getMessage()) . '</p>')
            . '</div></body></html>';
        exit;
    }
}
