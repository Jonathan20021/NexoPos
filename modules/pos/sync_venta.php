<?php
/**
 * Sincroniza UNA venta que se hizo offline. Lo llama el POS por fetch() cuando
 * vuelve la conexión, una vez por cada venta pendiente en IndexedDB.
 *
 * Responde JSON. Es idempotente: reenviar la misma venta (mismo UUID) devuelve la
 * venta ya registrada sin crear otra ni consumir otro NCF.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function sync_salir(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in())              sync_salir(401, ['ok' => false, 'error' => 'Sesión expirada. Vuelve a iniciar sesión.']);
if (!can('pos.vender'))           sync_salir(403, ['ok' => false, 'error' => 'Sin permiso para vender.']);
if (!isPost())                    sync_salir(405, ['ok' => false, 'error' => 'Método no permitido.']);

// CSRF por cabecera (el fetch lo manda; el token vive en la página del POS).
$tokenHeader = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $tokenHeader)) {
    sync_salir(419, ['ok' => false, 'error' => 'Token de seguridad inválido. Recarga el POS.']);
}

$sid = current_sucursal_id();
$uid = (int) current_user()['id'];
if ($sid === null) sync_salir(400, ['ok' => false, 'error' => 'Selecciona una sucursal.']);

// La venta sincronizada entra a la caja abierta actual. Si no hay caja abierta, la
// venta queda pendiente hasta que se abra una (la cash de una venta debe cuadrar
// contra una sesión de caja).
$sesion = cajaSesionAbierta($sid, $uid);
if (!$sesion) sync_salir(409, ['ok' => false, 'retry' => true, 'error' => 'Abre la caja para sincronizar las ventas pendientes.']);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || empty($in['uuid'])) {
    sync_salir(400, ['ok' => false, 'error' => 'Datos de venta incompletos.']);
}

// Si la venta trae un NCF pre-asignado (offline Fase 2), hay que resolver el
// terminal que lo reservó para validar la pertenencia del número.
$terminalId = 0;
if (!empty($in['ncf']) && !empty($in['device_token'])) {
    try {
        $term = terminalUpsert((string) $in['device_token'], $sid);
        $terminalId = (int) $term['id'];
    } catch (Throwable $e) {
        sync_salir(422, ['ok' => false, 'retry' => false, 'error' => 'Terminal no válido para el NCF offline.']);
    }
}

try {
    $r = registrarVentaPOS([
        'cart'           => $in['cart'] ?? [],
        'descuento'      => $in['descuento'] ?? 0,
        'cliente_id'     => $in['cliente_id'] ?? 1,
        'comprobante'    => $in['comprobante'] ?? 'consumidor',
        'metodo_pago_id' => $in['metodo_pago_id'] ?? 1,
        'canal'          => $in['canal'] ?? 'Mostrador',
        'uuid'           => $in['uuid'],
        'fecha'          => $in['fecha'] ?? null,
        'ncf'            => $in['ncf'] ?? null,
    ], [
        'sid' => $sid, 'uid' => $uid, 'sesion' => $sesion, 'puede_muestra' => can('ventas.muestra'),
        'terminal_id' => $terminalId,
    ]);

    if (!$r['duplicada']) {
        // El POS marca 'offline' las ventas que estuvieron en cola; las directas no.
        $glosa = !empty($in['offline'])
            ? 'Venta offline sincronizada ' . $r['numero']
            : 'Venta registrada ' . $r['numero'];
        audit('pos', 'vender', $glosa, ['tabla' => 'ventas', 'registro_id' => $r['id']]);
    }
    sync_salir(200, [
        'ok' => true, 'duplicada' => $r['duplicada'],
        'id' => $r['id'], 'numero' => $r['numero'], 'ncf' => $r['ncf'], 'total' => $r['total'],
    ]);
} catch (Throwable $e) {
    // Error de negocio (stock, NCF agotado, etc.): NO se reintenta en bucle; se
    // reporta para que el operador lo resuelva. La venta queda marcada con error.
    sync_salir(422, ['ok' => false, 'retry' => false, 'error' => $e->getMessage()]);
}
