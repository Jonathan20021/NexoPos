<?php
/**
 * Reserva NCF para el terminal (modo offline Fase 2). El POS lo llama por fetch()
 * mientras HAY conexión, para mantener un colchón de comprobantes listos por si se
 * cae el internet. Devuelve los NCF tallados del maestro para B02 y/o B01.
 *
 * El navegador guarda esos NCF y, estando offline, los imprime como comprobante
 * fiscal definitivo. No requiere caja abierta: reservar no es vender.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function term_salir(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in())    term_salir(401, ['ok' => false, 'error' => 'Sesión expirada.']);
if (!can('pos.vender')) term_salir(403, ['ok' => false, 'error' => 'Sin permiso para vender.']);
if (!isPost())          term_salir(405, ['ok' => false, 'error' => 'Método no permitido.']);

$tokenHeader = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $tokenHeader)) {
    term_salir(419, ['ok' => false, 'error' => 'Token de seguridad inválido. Recarga el POS.']);
}

$sid = current_sucursal_id();
if ($sid === null) term_salir(400, ['ok' => false, 'error' => 'Selecciona una sucursal.']);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || empty($in['device_token'])) {
    term_salir(400, ['ok' => false, 'error' => 'Falta el identificador del terminal.']);
}

// Cuántos NCF pedir por tipo (el navegador manda cuántos le faltan para el objetivo).
$necesita = is_array($in['need'] ?? null) ? $in['need'] : [];
$pedidoB02 = max(0, min(300, (int) ($necesita['B02'] ?? 0)));
$pedidoB01 = max(0, min(300, (int) ($necesita['B01'] ?? 0)));

try {
    $term = terminalUpsert((string) $in['device_token'], $sid, $in['nombre'] ?? null);
    $tid  = (int) $term['id'];

    $ncfs = ['B02' => [], 'B01' => []];
    $venc = ['B02' => null, 'B01' => null];
    if ($pedidoB02 > 0) { $r = reservarNCF($tid, 'B02', $pedidoB02); $ncfs['B02'] = $r['ncfs']; $venc['B02'] = $r['vencimiento']; }
    if ($pedidoB01 > 0) { $r = reservarNCF($tid, 'B01', $pedidoB01); $ncfs['B01'] = $r['ncfs']; $venc['B01'] = $r['vencimiento']; }

    term_salir(200, [
        'ok' => true,
        'terminal_id' => $tid,
        'nombre' => $term['nombre'],
        'ncfs' => $ncfs,
        'vencimiento' => $venc,
    ]);
} catch (Throwable $e) {
    term_salir(422, ['ok' => false, 'error' => $e->getMessage()]);
}
