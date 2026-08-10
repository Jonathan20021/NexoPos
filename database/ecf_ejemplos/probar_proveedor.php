<?php
/**
 * Flujo COMPLETO contra el ambiente de pruebas REAL de LUGANIS, usando las
 * funciones de la aplicación (no peticiones sueltas).
 *
 * Es la única suite que necesita red y credenciales. Comprueba de punta a punta:
 * login → venta → emisión → trackId → estado aceptado → timbre de la DGII → QR →
 * PDF y XML firmados → ticket.
 *
 * REGISTRA VENTAS Y EMITE COMPROBANTES DE VERDAD en el ambiente de pruebas del
 * proveedor, así que corre solo sobre una base cuyo nombre termine en «_ecftest».
 *
 *   mysql -u root -e "DROP DATABASE IF EXISTS inventario_pos_ecftest; CREATE DATABASE inventario_pos_ecftest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
 *   mysqldump -u root --single-transaction inventario_pos | mysql -u root inventario_pos_ecftest
 *   php database/ecf_ejemplos/probar_proveedor.php
 *
 * Necesita ECF_USUARIO / ECF_CLAVE en config/config.local.php. Sin ellas, sale
 * sin ejecutar nada en vez de fallar.
 */
define('DB_NAME', 'inventario_pos_ecftest');

if (!str_ends_with(DB_NAME, '_ecftest')) {
    fwrite(STDERR, "Esta prueba solo corre contra una base cuyo nombre termine en «_ecftest».
");
    exit(2);
}

set_error_handler(fn($n, $s) => str_contains($s, 'already defined'));
require dirname(__DIR__, 2) . '/app/bootstrap.php';
restore_error_handler();

if (!ecfConfigurado()) {
    fwrite(STDERR, "Faltan las credenciales del proveedor (ECF_USUARIO / ECF_CLAVE en config/config.local.php).
");
    exit(2);
}

$u = qOne("SELECT * FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
$_SESSION['user'] = $u; $_SESSION['user']['es_super'] = 1;
$_SESSION['sucursal_id'] = (int) qVal("SELECT id FROM sucursales ORDER BY id LIMIT 1");

$paso = 0;
$ok = true;
function paso(string $t, bool $c, string $d = ''): void {
    global $paso, $ok;
    $paso++;
    echo ($c ? "  ✓ " : "  ✗ ") . "$paso. $t" . ($d ? "\n       $d" : '') . "\n";
    if (!$c) $ok = false;
}

/* La empresa del clon debe emitir con el RNC de la cuenta de prueba: la API
   rechaza cualquier otro (código 1006). */
$RNC = defined('ECF_RNC_PRUEBA') ? ECF_RNC_PRUEBA : '102616541';
dbUpdate('empresa', [
    'rnc' => $RNC,
    'nombre' => 'L OCCITANE EN PROVENCE (IMPORTERS)',
    'direccion' => 'Av. Gustavo Mejia Ricart, Santo Domingo, D. N.',
], 'id = ?', [(int) qVal("SELECT id FROM empresa LIMIT 1")]);
$GLOBALS['empresa'] = qOne("SELECT * FROM empresa LIMIT 1");

// Secuencia E32 en un rango alto, para no chocar con documentos ya emitidos por
// otras pruebas en la cuenta compartida.
$arranque = 90000 + (int) date('Hi');
dbUpdate('ncf_secuencias', ['secuencia_actual' => $arranque, 'secuencia_hasta' => $arranque + 50,
    'vencimiento' => date('Y-m-d', strtotime('+1 year')), 'activo' => 1], "tipo = 'E32'");

ecfGuardarConfig(['activo' => 1, 'ambiente' => 'stage', 'envio_automatico' => 1,
    'access_token' => null, 'refresh_token' => null, 'token_expira' => null]);

echo "\n", str_repeat('=', 72), "\n";
echo "  FLUJO COMPLETO POR LA APLICACIÓN · ambiente de pruebas de LUGANIS\n";
echo str_repeat('=', 72), "\n";
echo "  RNC emisor: $RNC   ·   secuencia E32 desde $arranque\n\n";

/* ---------------- 1. Login ---------------- */
$r = ecfLogin();
paso('Login con las credenciales reales', $r['ok'], $r['mensaje']);
if (!$r['ok']) exit(1);

$cfg = ecfConfig(true);
$vig = strtotime($cfg['token_expira']) - time();
paso('Guarda la vigencia REAL del token (no 3600 fijo)', $vig > 0 && $vig <= 2500, "quedan {$vig}s");

/* ---------------- 2. Venta y emisión ---------------- */
$sid = (int) $_SESSION['sucursal_id'];
$pid = (int) qVal("SELECT p.id FROM productos p JOIN inventario_stock s ON s.producto_id=p.id AND s.sucursal_id=?
                    WHERE p.activo=1 AND p.tipo='producto' AND s.cantidad>=3 ORDER BY p.id LIMIT 1", [$sid]);
$venta = registrarVentaPOS(
    ['cart' => [['id' => $pid, 'cant' => 2]], 'comprobante' => 'consumidor',
     'cliente_id' => 1, 'metodo_pago_id' => 1, 'descuento' => 0],
    ['sid' => $sid, 'uid' => (int) $u['id'], 'sesion' => null, 'puede_muestra' => false]
);
paso('La venta se registra', !empty($venta['id']), 'venta #' . $venta['id'] . ' · ' . money($venta['total']));
paso('Toma un e-NCF E32', ecfENCFValido((string) $venta['ncf']), $venta['ncf']);
paso('La emisión devolvió resultado', isset($venta['ecf']), $venta['ecf']['mensaje'] ?? '');

$doc = qOne("SELECT * FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [(int) $venta['id']]);
paso('El proveedor ACEPTÓ la trama y dio ticket',
     $doc && !empty($doc['track_id']) && $doc['estado'] === 'enviado',
     'trackId: ' . ($doc['track_id'] ?? '—') . ' · estado: ' . ($doc['estado'] ?? '—'));

/* ---------------- 3. Consulta de estado ---------------- */
sleep(4);
$e = ecfActualizarEstado((int) $doc['id']);
$doc = qOne("SELECT * FROM ecf_documentos WHERE id=?", [(int) $doc['id']]);
paso('La consulta del estado resuelve', $e['ok'], 'estado: ' . $doc['estado'] . ' · ' . $e['mensaje']);
paso('Y lo interpreta como ACEPTADO', $doc['estado'] === 'aceptado', 'estado: ' . $doc['estado']);

/* ---------------- 4. QR ---------------- */
$qr = ecfQrDataUri((int) $doc['id']);
$doc = qOne("SELECT qr_url, LENGTH(qr) AS qr_len FROM ecf_documentos WHERE id=?", [(int) $doc['id']]);
paso('Descarga la URL del timbre de la DGII', !empty($doc['qr_url']), (string) $doc['qr_url']);
paso('Dibuja el QR y lo guarda', $qr !== null && (int) $doc['qr_len'] > 1000,
     'imagen de ' . (int) $doc['qr_len'] . ' bytes');


/* ---------------- 5. PDF y XML del proveedor ---------------- */
$encf = (string) qVal("SELECT encf FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [(int) $venta['id']]);
foreach (['PDF', 'XML'] as $rec) {
    $d = ecfDescargarRecurso($rec, $RNC, $encf);
    $bien = $d['ok'] && !empty($d['contenido']);
    paso("Descarga el $rec del proveedor", $bien,
         $bien ? ($d['nombre'] . ' · ' . strlen($d['contenido']) . ' bytes') : $d['mensaje']);

}

/* ---------------- 6. Ticket ---------------- */
$_GET['id'] = (string) $venta['id'];
ob_start(); require dirname(__DIR__, 2) . '/modules/pos/ticket.php'; $html = ob_get_clean();
paso('El ticket sale con el e-NCF', str_contains($html, $venta['ncf']));
paso('Y con el QR incrustado', $qr !== null && str_contains($html, 'data:image/png;base64,'));

echo "\n", str_repeat('-', 72), "\n";
echo $ok ? "  ✓ FLUJO COMPLETO CORRECTO\n\n" : "  ✗ Hay pasos fallidos\n\n";
exit($ok ? 0 : 1);
