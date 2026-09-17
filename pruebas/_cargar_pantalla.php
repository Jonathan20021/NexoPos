<?php
/**
 * Carga una pantalla del sistema como si la hubiera pedido un administrador.
 *
 * Existe para que un `php -l` no sea toda la comprobación: una pantalla compila
 * perfectamente y luego revienta con «Unknown column» en la primera consulta.
 * No escribe nada: solo hace un GET.
 */
// Este script se da a sí mismo sesión de superusuario para poder cargar
// cualquier pantalla. Por HTTP eso sería una escalada de privilegios, así
// que no se expone: solo línea de comandos.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }

$RAIZ = dirname(__DIR__);
$ruta = $argv[1] ?? '';
if ($ruta === '' || !is_file($RAIZ . '/' . $ruta)) { fwrite(STDERR, "No existe: $ruta\n"); exit(2); }

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/' . $ruta;
$_SERVER['SCRIPT_NAME']    = '/' . $ruta;
$_SERVER['HTTP_HOST']      = 'localhost';

require_once $RAIZ . '/app/bootstrap.php';
// La misma forma que arma el login: la barra superior espera `rol_nombre`,
// y sin él cada pantalla suelta un aviso que no es suyo.
$u = qOne("SELECT u.*, r.nombre AS rol_nombre, r.es_super, s.nombre AS sucursal_nombre
             FROM usuarios u JOIN roles r ON r.id = u.rol_id
             LEFT JOIN sucursales s ON s.id = u.sucursal_id
            WHERE u.activo = 1 ORDER BY r.es_super DESC, u.id LIMIT 1");
if (!$u) { fwrite(STDERR, "Sin usuarios activos\n"); exit(2); }
$_SESSION['user'] = $u;
$_SESSION['user']['es_super'] = 1;

ob_start();
require $RAIZ . '/' . $ruta;
echo ob_get_clean();
