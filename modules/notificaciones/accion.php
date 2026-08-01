<?php
/** Acciones del centro de notificaciones (solo POST, patrón PRG). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

if (!isPost()) {
    redirect('modules/notificaciones/index.php');
}
verify_csrf();

$destino = local_redirect_target(post('redir'), url('modules/notificaciones/index.php'));

switch (post('accion')) {
    case 'marcar_todas':
        $n = notif_marcar_todas();
        flash('success', $n > 0
            ? $n . ' notificación' . ($n === 1 ? '' : 'es') . ' marcada' . ($n === 1 ? '' : 's') . ' como leída' . ($n === 1 ? '' : 's') . '.'
            : 'No había notificaciones sin leer.');
        break;

    case 'marcar_una':
        notif_marcar_leida(postInt('id'));
        break;

    case 'revisar':
        // Fuerza un barrido inmediato (botón «Buscar alertas ahora»).
        try {
            notif_generar();
            flash('success', 'Alertas actualizadas con la información más reciente.');
        } catch (Throwable $ex) {
            flash('error', 'No se pudieron actualizar las alertas.');
        }
        break;
}

header('Location: ' . $destino);
exit;
