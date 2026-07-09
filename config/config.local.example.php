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

/**
 * Correo saliente vía Resend (https://resend.com).
 *
 *  - RESEND_API_KEY: créala en el panel de Resend. Conviene restringirla a tu dominio.
 *  - MAIL_FROM: el dominio DEBE estar verificado en Resend, o Resend rechaza el envío.
 *  - MAIL_REPLY_TO: a dónde responde el cliente al pulsar «Responder».
 *
 * Si dejas RESEND_API_KEY vacía, el sistema no envía correos y sigue funcionando
 * con normalidad: un pedido nunca se pierde porque falle el correo.
 */
define('RESEND_API_KEY', '');
define('MAIL_FROM', 'Pedidos <pedidos@tudominio.com>');
define('MAIL_REPLY_TO', 'contacto@tudominio.com');
