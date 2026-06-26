<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$u = current_user();
$s = $_GET['s'] ?? '';

// Solo super admin o usuarios "todas las sucursales" pueden cambiar libremente.
if (is_super() || $u['sucursal_id'] === null) {
    set_sucursal_activa($s === '' ? '' : (int) $s);
} elseif ($s !== '' && (int) $s === (int) $u['sucursal_id']) {
    set_sucursal_activa((int) $s);
}

// Redirección segura (solo rutas locales)
$redir = $_GET['redir'] ?? '';
if (!is_string($redir) || $redir === '' || $redir[0] !== '/' || strpos($redir, '//') === 0) {
    $redir = url('modules/dashboard/index.php');
}
header('Location: ' . $redir);
exit;
