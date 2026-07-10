<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('pos.vender');
verify_csrf();

$sid = current_sucursal_id();
$uid = (int) current_user()['id'];

if ($sid === null) { flash('error', 'Selecciona una sucursal para vender.'); redirect('modules/pos/index.php'); }

$sesion = cajaSesionAbierta($sid, $uid);
if (!$sesion) { flash('error', 'Debes abrir la caja antes de vender.'); redirect('modules/pos/caja.php'); }

$cart = json_decode(post('cart', '[]'), true);
if (!is_array($cart) || count($cart) === 0) { flash('error', 'El carrito está vacío.'); redirect('modules/pos/index.php'); }

try {
    // Toda la lógica vive en registrarVentaPOS() (compartida con la sincronización offline).
    $r = registrarVentaPOS([
        'cart'           => $cart,
        'descuento'      => postNum('descuento'),
        'cliente_id'     => postInt('cliente_id'),
        'comprobante'    => post('comprobante'),
        'metodo_pago_id' => postInt('metodo_pago_id'),
        'canal'          => post('canal_venta'),
        'uuid'           => post('uuid'),
    ], [
        'sid' => $sid, 'uid' => $uid, 'sesion' => $sesion, 'puede_muestra' => can('ventas.muestra'),
    ]);

    audit('pos', 'vender', 'Venta registrada', ['tabla' => 'ventas', 'registro_id' => $r['id']]);
    flash('success', 'Venta registrada correctamente.');
    redirect('modules/pos/ticket.php?id=' . $r['id'] . '&print=1');

} catch (Throwable $e) {
    flash('error', $e->getMessage());
    redirect('modules/pos/index.php');
}
