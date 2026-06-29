<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!isPost()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
verify_csrf();

$u = current_user();
$s = post('s');

// Solo super admin o usuarios "todas las sucursales" pueden cambiar libremente.
if (is_super() || $u['sucursal_id'] === null) {
    set_sucursal_activa($s === '' ? '' : (int) $s);
} elseif ($s !== '' && (int) $s === (int) $u['sucursal_id']) {
    set_sucursal_activa((int) $s);
}

// Redirección segura (solo rutas locales)
$redir = local_redirect_target(post('redir'));
header('Location: ' . $redir);
exit;
