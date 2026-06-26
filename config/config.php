<?php
/**
 * Configuración de la aplicación.
 *
 * IMPORTANTE (seguridad): las CREDENCIALES de base de datos y el entorno se definen
 * en  config/config.local.php , que NO se versiona (está en .gitignore) y por tanto
 * NUNCA se sube a GitHub. Copia  config.local.example.php  a  config.local.php  y
 * coloca tus datos reales en el servidor.
 */

// ===== Aplicación =====
define('APP_NAME', 'NexoPOS');
// Ruta base: '' = autodetección (funciona en subcarpeta de XAMPP o en la raíz de un dominio).
define('APP_URL', '');

// ===== Valores por defecto (la tabla `empresa` puede sobrescribirlos) =====
define('DEFAULT_MONEDA', 'RD$');
define('DEFAULT_ITBIS', 18.00);
define('TIMEZONE', 'America/Santo_Domingo');
date_default_timezone_set(TIMEZONE);

// ===== Credenciales locales (NO versionadas) =====
$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    require $__local;
}

// Respaldo para desarrollo local en XAMPP si no existe config.local.php.
// En producción SIEMPRE debe existir config.local.php con las credenciales reales.
if (!defined('DB_HOST'))    define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME'))    define('DB_NAME', 'inventario_pos');
if (!defined('DB_USER'))    define('DB_USER', 'root');
if (!defined('DB_PASS'))    define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('APP_ENV'))    define('APP_ENV', 'development');
