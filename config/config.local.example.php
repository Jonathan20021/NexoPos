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

/**
 * Verificación en dos pasos al iniciar sesión (ver docs/OTP-LOGIN.md).
 *
 * Normalmente NO hace falta tocar nada aquí: la política se administra desde
 * Administración → Seguridad de acceso. Estas dos constantes son llaves de
 * emergencia y mandan sobre lo que diga la base de datos.
 *
 *  OTP_DESACTIVADO     Apaga el segundo factor pase lo que pase. Es la salida
 *                      cuando nadie puede entrar (el correo dejó de funcionar,
 *                      el dueño perdió acceso a su buzón…). Quítala después.
 *
 *  OTP_EXIGIR_SIEMPRE  Lo contrario: exige el código aunque el correo no esté
 *                      configurado. Sin ella, si no hay RESEND_API_KEY el
 *                      sistema deja entrar solo con contraseña para no dejar al
 *                      negocio fuera de su propio ERP (y lo avisa en pantalla).
 *                      Con ella, si Resend falla, NADIE entra.
 */
// define('OTP_DESACTIVADO', true);
// define('OTP_EXIGIR_SIEMPRE', true);

/**
 * Facturación Electrónica · e-CF (ver docs/FACTURACION-ELECTRONICA.md).
 *
 * Todo esto se puede configurar desde Finanzas → Facturación Electrónica, que es
 * lo cómodo mientras se certifica. Pero en PRODUCCIÓN conviene declarar aquí el
 * usuario y la clave: estas constantes mandan sobre lo guardado en la base, y así
 * la credencial del proveedor no viaja en los respaldos SQL ni en un volcado.
 *
 *  ECF_USUARIO / ECF_CLAVE   Credenciales que entrega LUGANIS.
 *  ECF_URL_PRODUCCION        El manual solo publica la de pruebas; la de producción
 *                            la da el consultor de integración.
 *  ECF_DEVICE_ID             Identificador del servidor emisor. Debe ser ESTABLE:
 *                            ata la sesión y viaja en todas las peticiones. Si
 *                            cambia entre el login y el envío, el proveedor rechaza.
 *  ECF_IP_PUBLICA            IP pública del servidor (el login la exige).
 *  ECF_CA_BUNDLE             Solo si la red tiene un equipo que inspecciona TLS
 *                            (FortiGate, Zscaler…): ruta a un bundle de CA que
 *                            incluya el certificado raíz de ese equipo. Nunca se
 *                            desactiva la verificación; se amplía la confianza.
 *  ECF_CRON_KEY              Solo si vas a disparar la cola por URL en vez de
 *                            por CLI. Sin ella, ese endpoint no se expone.
 */
// define('ECF_USUARIO', '');
// define('ECF_CLAVE', '');
// define('ECF_URL_PRODUCCION', '');
// define('ECF_DEVICE_ID', '');
// define('ECF_IP_PUBLICA', '');
// define('ECF_CA_BUNDLE', __DIR__ . '/ca-ecf.local.crt');
// define('ECF_CRON_KEY', 'una-cadena-larga-y-aleatoria');

/* ---------------------------------------------------------------------------
 *  Reloj biométrico — BioTime Cloud (ZKTeco)
 *
 *  El ponche de Importers está en https://importers.biotime.mx. Estas cuatro
 *  constantes son lo único que Nexo necesita para leerlo.
 *
 *  BIOTIME_URL       El tenant, sin barra al final.
 *  BIOTIME_EMPRESA   El subdominio, que la nube llama «company». Para
 *                    importers.biotime.mx es «importers». Sin esto la nube
 *                    contesta 400 sin decir qué falta.
 *  BIOTIME_EMAIL     Correo de la cuenta. Conviene una cuenta propia para la
 *                    integración, no la de una persona: si alguien se va y se
 *                    le desactiva el usuario, la sincronización deja de andar
 *                    y nadie sabe por qué.
 *  BIOTIME_CLAVE     Su contraseña. No se guarda en la base de datos ni se
 *                    escribe en ningún log: `bioOfuscar()` la tapa incluso en
 *                    los mensajes de error.
 *
 *  Para comprobar que entra:  php pruebas/biotime.php
 */
// define('BIOTIME_URL', 'https://importers.biotime.mx');
// define('BIOTIME_EMPRESA', 'importers');
// define('BIOTIME_EMAIL', '');
// define('BIOTIME_CLAVE', '');
