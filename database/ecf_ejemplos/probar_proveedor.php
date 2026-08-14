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

/**
 * Fecha de vencimiento de la secuencia en el ambiente de pruebas.
 *
 * NO es una fecha cualquiera: es la que LUGANIS tiene autorizada en su entorno
 * de pruebas y la única que acepta. Antes se ponía `+1 año`, que cambia cada
 * día que se corre la prueba y que su ambiente rechazaba.
 *
 * Confirmada por Lucelyn González (LUGANIS) el 14/08/2026.
 */
const ECF_VENCIMIENTO_PRUEBAS = '2028-12-31';

// Rango alto para no chocar con documentos ya emitidos por otras pruebas en la
// cuenta compartida. Se preparan TODAS las secuencias, no solo la E32: la
// certificación pide los seis tipos.
$arranque = 90000 + (int) date('Hi');
foreach (['E31', 'E32', 'E33', 'E34', 'E44', 'E45'] as $t) {
    dbUpdate('ncf_secuencias', [
        'secuencia_actual' => $arranque,
        'secuencia_hasta'  => $arranque + 50,
        'vencimiento'      => ECF_VENCIMIENTO_PRUEBAS,
        'activo'           => 1,
    ], 'tipo = ?', [$t]);
}

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
// Lo que importa es que haya trackId y que NO esté en error. Exigir el estado
// intermedio «enviado» daba un falso fallo: el tick oportunista de la cola a
// veces ya lo ha consultado y aceptado antes de llegar aquí.
paso('El proveedor ACEPTÓ la trama y dio ticket',
     $doc && !empty($doc['track_id']) && in_array($doc['estado'], ['enviado', 'aceptado'], true),
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

/* ---------------- 7. Los demás tipos de comprobante ----------------
 *
 * La certificación no es de un tipo, es de todos. E32 ya pasó por el flujo
 * largo de arriba; aquí van los otros tres que nacen de una venta, cada uno
 * hasta que el proveedor lo ACEPTA, que es lo que cuenta.
 *
 * La nota de crédito (E34) no se prueba aquí porque no nace de una venta sino
 * de una devolución, y tiene su propio banco.
 */
echo "\n  ── Los demás tipos de comprobante ──\n";

// Los tres exigen el RNC o la cédula del comprador: un crédito fiscal sin RNC
// no existe, y el sistema lo rechaza antes de enviarlo. El «Cliente Genérico»
// (id 1) no lo tiene, así que se usa uno con RNC de verdad.
$cliRnc = (int) qVal("SELECT id FROM clientes WHERE rnc_cedula IS NOT NULL AND rnc_cedula <> '' ORDER BY id LIMIT 1");
paso('Hay un cliente con RNC para los comprobantes que lo exigen', $cliRnc > 0,
    'cliente #' . $cliRnc . ' · ' . (string) qVal("SELECT rnc_cedula FROM clientes WHERE id=?", [$cliRnc]));

$otros = [
    'credito_fiscal'   => ['E31', 'Factura de Crédito Fiscal'],
    'regimen_especial' => ['E44', 'Régimen Especial'],
    'gubernamental'    => ['E45', 'Gubernamental'],
];
foreach ($otros as $comprobante => [$serie, $nombre]) {
    $pid2 = (int) qVal("SELECT p.id FROM productos p JOIN inventario_stock s ON s.producto_id=p.id AND s.sucursal_id=?
                         WHERE p.activo=1 AND p.tipo='producto' AND s.cantidad>=3 ORDER BY p.id LIMIT 1", [$sid]);
    $v = registrarVentaPOS(
        ['cart' => [['id' => $pid2, 'cant' => 1]], 'comprobante' => $comprobante,
         'cliente_id' => $cliRnc, 'metodo_pago_id' => 1, 'descuento' => 0],
        ['sid' => $sid, 'uid' => (int) $u['id'], 'sesion' => null, 'puede_muestra' => false]
    );
    paso("$nombre ($serie): toma su e-NCF",
        !empty($v['ncf']) && str_starts_with((string) $v['ncf'], $serie), (string) ($v['ncf'] ?? '—'));

    $d2 = qOne("SELECT * FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [(int) ($v['id'] ?? 0)]);
    paso("$nombre ($serie): el proveedor da ticket",
        $d2 && !empty($d2['track_id']),
        'trackId: ' . ($d2['track_id'] ?? '—') . ' · ' . ($d2['estado'] ?? '—')
        . ($d2 && $d2['estado'] === 'error' ? ' · ' . mb_substr((string) $d2['estado_detalle'], 0, 120) : ''));

    if ($d2 && !empty($d2['track_id'])) {
        // Una espera fija da falsos negativos: el E44 tardó más de 5 s y la
        // prueba lo dio por fallido cuando el proveedor acabó aceptándolo. Se
        // reconsulta en escalera, igual que hace la cola de la aplicación.
        $intentos = 0;
        do {
            sleep(4);
            ecfActualizarEstado((int) $d2['id']);
            $d2 = qOne("SELECT * FROM ecf_documentos WHERE id=?", [(int) $d2['id']]);
        } while ($d2['estado'] !== 'aceptado' && $d2['estado'] !== 'rechazado' && ++$intentos < 5);

        paso("$nombre ($serie): la DGII lo ACEPTA", $d2['estado'] === 'aceptado',
            'estado: ' . $d2['estado'] . ' tras ' . ($intentos + 1) . ' consulta(s) · '
            . mb_substr((string) $d2['estado_detalle'], 0, 120));
    }
}

echo "\n", str_repeat('-', 72), "\n";
echo "  Vencimiento de secuencia usado: " . ECF_VENCIMIENTO_PRUEBAS . "\n";
echo $ok ? "  ✓ FLUJO COMPLETO CORRECTO\n\n" : "  ✗ Hay pasos fallidos\n\n";
exit($ok ? 0 : 1);
