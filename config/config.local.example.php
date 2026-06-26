<?php
/**
 * PLANTILLA de credenciales.
 *
 *  1. Copia este archivo como  config.local.php  (en la misma carpeta /config).
 *  2. Coloca las credenciales reales de tu base de datos.
 *
 * config.local.php está en .gitignore: NUNCA se sube a GitHub. Así las contraseñas
 * de producción no quedan expuestas en el repositorio público.
 *
 * NOTA cPanel/producción: el host suele ser 'localhost' porque la aplicación y MySQL
 * están en el mismo servidor. La IP pública solo se usa para conexiones remotas.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'tu_base_de_datos');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña_segura');
define('DB_CHARSET', 'utf8mb4');

// 'production' oculta los errores al público. 'development' los muestra (solo local).
define('APP_ENV', 'production');
