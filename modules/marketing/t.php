<?php
/**
 * Rastreo de campañas. Público a propósito: lo abre el cliente desde su correo,
 * sin sesión.
 *
 *   ?t=TOKEN&a=o            → píxel de 1×1: marca la apertura
 *   ?t=TOKEN&a=c&u=DESTINO  → registra el clic y redirige
 *
 * El parámetro `u` NO es un redirector abierto: solo se acepta un destino que la
 * propia campaña publicó (su botón, un enlace de su contenido) o una URL del
 * mismo sistema. Un redirector que acepte cualquier cosa convierte tu dominio en
 * herramienta de phishing.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$token  = preg_replace('/[^a-f0-9]/i', '', (string) get('t'));
$accion = get('a') === 'c' ? 'c' : 'o';

/* ---------- Apertura: siempre devuelve el píxel, pase lo que pase ---------- */
if ($accion === 'o') {
    if ($token !== '' && mkt_disponible()) {
        mkt_registrar_apertura($token);
    }
    // GIF transparente de 1×1.
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Length: ' . strlen($gif));
    echo $gif;
    exit;
}

/* ---------- Clic: registra y redirige a un destino verificado ---------- */
$destino = trim((string) get('u'));
$inicio  = url('index.php');

if ($token === '' || !mkt_disponible()) {
    redirect('index.php');
}

$envio = mkt_registrar_clic($token);
if (!$envio) {
    redirect('index.php');
}

$campana = qOne("SELECT * FROM campanas WHERE id = ?", [(int) $envio['campana_id']]);
if ($campana && $destino !== '' && mkt_destino_permitido($campana, $destino)) {
    header('Location: ' . $destino, true, 302);
    exit;
}

// Destino no reconocido: se lleva al cliente a la tienda, nunca a donde diga la URL.
header('Location: ' . url('tienda/index.php'), true, 302);
exit;
