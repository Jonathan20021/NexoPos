<?php
/**
 * Abre una notificación: la marca como leída y lleva a la pantalla que resuelve
 * el problema. Va con token para que un enlace externo no pueda marcar lecturas
 * ajenas ni usarse como redirector.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$token = (string) get('_t');
if ($token === '' || !hash_equals(csrf_token(), $token)) {
    redirect('modules/notificaciones/index.php');
}

$id = (int) get('id');
$n  = null;
if ($id > 0 && notif_disponible()) {
    [$where, $par] = notif_where_visible();
    $n = qOne("SELECT n.* FROM notificaciones n WHERE $where AND n.id = ?", array_merge($par, [$id]));
}

if (!$n) {
    flash('warning', 'Esa notificación ya no está disponible.');
    redirect('modules/notificaciones/index.php');
}

notif_marcar_leida($id);
redirect($n['url'] ?: 'modules/notificaciones/index.php');
