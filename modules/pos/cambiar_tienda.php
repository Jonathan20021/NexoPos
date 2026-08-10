<?php
/**
 * Cambia la tienda (marca comercial) activa del punto de venta.
 *
 * Solo cambia con qué logo se factura. No es un límite de seguridad: no
 * concede ni quita acceso a nada, así que basta con validar que la tienda
 * exista y esté activa — de eso se encarga tienda_set().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('pos.ver');

if (!isPost()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
verify_csrf();

if (!tienda_set(postInt('tienda_id'))) {
    flash('error', 'Esa tienda no existe o está inactiva.');
}

header('Location: ' . local_redirect_target(post('redir'), url('modules/pos/index.php')));
exit;
