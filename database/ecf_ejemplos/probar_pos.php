<?php
/**
 * Prueba de extremo a extremo del enganche del e-CF al punto de venta.
 *
 * REGISTRA VENTAS DE VERDAD: consume secuencias, descuenta inventario y mueve
 * caja. Por eso corre SOLO contra una base desechable cuyo nombre termine en
 * «_ecftest»; contra cualquier otra se niega a arrancar. Esa guarda es
 * deliberada: un descuido aquí ensuciaría la contabilidad del cliente.
 *
 * Preparar el clon y ejecutar:
 *
 *   mysql -u root -e "DROP DATABASE IF EXISTS inventario_pos_ecftest; CREATE DATABASE inventario_pos_ecftest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
 *   mysqldump -u root --single-transaction inventario_pos | mysql -u root inventario_pos_ecftest
 *   php database/ecf_ejemplos/probar_pos.php
 *
 * Qué comprueba:
 *   · Con el interruptor apagado, el POS factura exactamente como siempre.
 *   · Con él encendido, la venta toma E32/E31 y crea su documento electrónico.
 *   · Con el proveedor caído, la venta se completa igual y el comprobante queda
 *     en cola — que es la regla que no se negocia.
 *   · La secuencia avanza una vez por comprobante y ningún e-NCF se repite.
 *   · Un e-NCF consumido nunca se pierde, aunque el documento no valide.
 */

// Se fija la base ANTES de arrancar la app: config.local.php la definiría después
// y el primer define() gana.
define('DB_NAME', 'inventario_pos_ecftest');

if (!str_ends_with(DB_NAME, '_ecftest')) {
    fwrite(STDERR, "Esta prueba solo corre contra una base cuyo nombre termine en «_ecftest».
");
    exit(2);
}

$raiz = dirname(__DIR__, 2);
$_SERVER['SCRIPT_NAME'] = '/cli.php';

// config.local.php reintenta define('DB_NAME', …) y PHP avisa; el valor de arriba
// es el que queda. Se silencia solo ese aviso concreto.
set_error_handler(function ($no, $str) {
    if (str_contains($str, 'already defined')) return true;
    return false;
});
require_once $raiz . '/app/bootstrap.php';
restore_error_handler();

// La prueba NO es idempotente: cuenta secuencias y documentos desde cero. Sobre
// un clon ya usado los números no cuadran y los fallos serían falsos.
if ((int) qVal("SELECT COUNT(*) FROM ecf_documentos") > 0) {
    fwrite(STDERR,
        "El clon ya tiene comprobantes emitidos de una corrida anterior.\n"
        . "Vuelve a clonarlo antes de repetir la prueba (ver la cabecera de este archivo).\n");
    exit(2);
}

$u = qOne("SELECT * FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
$_SESSION['user'] = $u;
$_SESSION['user']['es_super'] = 1;   // para poder renderizar el ticket al final
$_SESSION['sucursal_id'] = (int) qVal("SELECT id FROM sucursales ORDER BY id LIMIT 1");

$pruebas = 0; $fallos = [];
function afirmar(string $t, bool $c, string $d = ''): void {
    global $pruebas, $fallos;
    $pruebas++;
    echo $c ? "  ✓ $t\n" : "  ✗ $t" . ($d ? "\n      $d" : '') . "\n";
    if (!$c) $fallos[] = $t;
}

/** Arma el contexto y el carrito de una venta de prueba. */
function venderUna(string $comprobante = 'consumidor', ?int $clienteId = null): array
{
    $sid = (int) $_SESSION['sucursal_id'];
    $uid = (int) $_SESSION['user']['id'];
    $pid = (int) qVal(
        "SELECT p.id FROM productos p
           JOIN inventario_stock s ON s.producto_id = p.id AND s.sucursal_id = ?
          WHERE p.activo = 1 AND p.tipo = 'producto' AND s.cantidad >= 5
          ORDER BY p.id LIMIT 1", [$sid]
    );
    if (!$pid) throw new RuntimeException('No hay producto con stock para probar.');

    $sesion = qOne("SELECT * FROM caja_sesiones WHERE sucursal_id = ? AND estado = 'abierta' LIMIT 1", [$sid]);

    return registrarVentaPOS(
        ['cart' => [['id' => $pid, 'cant' => 2]], 'comprobante' => $comprobante,
         'cliente_id' => $clienteId ?? 1, 'metodo_pago_id' => 1, 'descuento' => 0],
        ['sid' => $sid, 'uid' => $uid, 'sesion' => $sesion, 'puede_muestra' => false]
    );
}

echo "\n", str_repeat('=', 74), "\n";
echo "  e-CF enganchado al POS · prueba de extremo a extremo\n";
echo str_repeat('=', 74), "\n";
echo "  Base de pruebas: ", DB_NAME, "\n\n";

/* ---------------------------------------------------------------------------
 * 1. Interruptor APAGADO: nada debe cambiar
 * ------------------------------------------------------------------------- */
echo "Con la facturación electrónica APAGADA\n";

ecfGuardarConfig(['activo' => 0, 'envio_automatico' => 0]);
$docsAntes = (int) qVal("SELECT COUNT(*) FROM ecf_documentos");

$v1 = venderUna('consumidor');
$row1 = qOne("SELECT ncf, ecf_tipo, estado FROM ventas WHERE id = ?", [$v1['id']]);

afirmar('La venta se registra', !empty($v1['id']));
afirmar('Toma un NCF preimpreso B02', str_starts_with((string) $row1['ncf'], 'B02'), 'ncf: ' . $row1['ncf']);
afirmar('No marca tipo de e-CF', $row1['ecf_tipo'] === null);
afirmar('No genera ningún documento electrónico',
    (int) qVal("SELECT COUNT(*) FROM ecf_documentos") === $docsAntes);
afirmar('La venta no trae bloque e-CF en la respuesta', !isset($v1['ecf']));

/* ---------------------------------------------------------------------------
 * 2. Interruptor ENCENDIDO
 * ------------------------------------------------------------------------- */
echo "\nCon la facturación electrónica ENCENDIDA\n";

// Rango autorizado de mentira, suficiente para la prueba.
dbUpdate('ncf_secuencias', ['secuencia_actual' => 1, 'secuencia_hasta' => 500,
                            'vencimiento' => date('Y-m-d', strtotime('+1 year')), 'activo' => 1], "tipo = 'E32'");
dbUpdate('ncf_secuencias', ['secuencia_actual' => 1, 'secuencia_hasta' => 500,
                            'vencimiento' => date('Y-m-d', strtotime('+1 year')), 'activo' => 1], "tipo = 'E31'");
dbUpdate('ncf_secuencias', ['secuencia_actual' => 1, 'secuencia_hasta' => 500,
                            'vencimiento' => date('Y-m-d', strtotime('+1 year')), 'activo' => 1], "tipo = 'E34'");
ecfGuardarConfig(['activo' => 1, 'envio_automatico' => 0]);   // registrar, no transmitir

afirmar('ecfActivo() responde que sí', ecfActivo());
afirmar('La serie de consumidor pasa a E32', ncfTipoDeComprobante('consumidor') === 'E32');
afirmar('La serie de crédito fiscal pasa a E31', ncfTipoDeComprobante('credito_fiscal') === 'E31');
afirmar('La nota de crédito pasa a E34', ncfTipoNotaCredito() === 'E34');

$v2 = venderUna('consumidor');
$row2 = qOne("SELECT ncf, ecf_tipo, total, estado FROM ventas WHERE id = ?", [$v2['id']]);
$doc2 = qOne("SELECT * FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [$v2['id']]);

afirmar('La venta se registra igual', !empty($v2['id']) && $row2['estado'] === 'completada');
afirmar('Toma un e-NCF E32 de 13 caracteres',
    ecfENCFValido((string) $row2['ncf']) && str_starts_with((string) $row2['ncf'], 'E32'),
    'ncf: ' . $row2['ncf']);
afirmar('Guarda el tipo de e-CF en la venta', $row2['ecf_tipo'] === '32');
afirmar('Crea el documento electrónico', $doc2 !== null);
afirmar('El documento usa EL MISMO e-NCF que la venta',
    $doc2 && $doc2['encf'] === $row2['ncf'], 'doc: ' . ($doc2['encf'] ?? '—') . ' venta: ' . $row2['ncf']);
afirmar('Queda pendiente de transmitir', $doc2 && $doc2['estado'] === 'pendiente');
afirmar('Guarda la trama enviada', $doc2 && str_starts_with((string) $doc2['trama'], 'IDOC|32|'));
afirmar('El nombre del archivo sigue la nomenclatura del manual',
    $doc2 && preg_match('/^\d{9,11}E32\d{10}\.txt$/', (string) $doc2['archivo']) === 1,
    'archivo: ' . ($doc2['archivo'] ?? '—'));

// Congelado fiscal por línea
$lin = qOne("SELECT ecf_indicador_facturacion, ecf_unidad_medida, ecf_bien_servicio
               FROM venta_detalles WHERE venta_id = ? LIMIT 1", [$v2['id']]);
afirmar('Congela el indicador de ITBIS en la línea', $lin && $lin['ecf_indicador_facturacion'] !== null);
afirmar('Congela la unidad de medida en la línea', $lin && $lin['ecf_unidad_medida'] !== null);
afirmar('Congela bien/servicio en la línea', $lin && $lin['ecf_bien_servicio'] !== null);

// El total del documento cuadra con la venta
$sumaItems = 0.0;
foreach (explode("\r\n", (string) $doc2['trama']) as $l) {
    if (str_starts_with($l, 'ITEM|')) {
        $campos = ecfDividirLinea($l);
        $sumaItems += (float) end($campos);
    }
}
afirmar('La suma de los ítems cuadra con subtotal − descuento',
    abs($sumaItems - ((float) qVal("SELECT subtotal - descuento FROM ventas WHERE id=?", [$v2['id']]))) < 0.01,
    'suma: ' . $sumaItems);

/* ---------------------------------------------------------------------------
 * 3. El proveedor caído NO puede tumbar la venta
 * ------------------------------------------------------------------------- */
echo "\nCon el proveedor inalcanzable (envío automático encendido)\n";

ecfGuardarConfig(['envio_automatico' => 1, 'usuario' => 'prueba', 'clave' => 'prueba123',
                  'url_stage' => 'https://127.0.0.1:9', 'access_token' => null, 'token_expira' => null]);

$t0 = microtime(true);
$v3 = venderUna('consumidor');
$ms = (int) round((microtime(true) - $t0) * 1000);
$row3 = qOne("SELECT ncf, estado FROM ventas WHERE id = ?", [$v3['id']]);
$doc3 = qOne("SELECT * FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [$v3['id']]);

afirmar('La venta se completa igual', $row3 && $row3['estado'] === 'completada');
afirmar('El stock se descontó', (int) qVal(
    "SELECT COUNT(*) FROM movimientos_inventario WHERE referencia_tipo='venta' AND referencia_id=?", [$v3['id']]) > 0);
afirmar('El documento queda registrado para reintentar', $doc3 !== null);
afirmar('Su estado refleja el fallo', $doc3 && in_array($doc3['estado'], ['pendiente', 'error'], true),
    'estado: ' . ($doc3['estado'] ?? '—'));
afirmar('Registra el intento fallido', $doc3 && (int) $doc3['intentos'] >= 1);
afirmar('Programa un reintento o agota', $doc3 &&
    ($doc3['proximo_intento'] !== null || $doc3['estado'] === 'error'));
afirmar('La respuesta trae el aviso para el cajero', isset($v3['ecf']) && $v3['ecf']['ok'] === false);
afirmar('No hace esperar al cajero más de 25 s', $ms < 25000, "tardó {$ms} ms");

/* ---------------------------------------------------------------------------
 * 4. Idempotencia y secuencia
 * ------------------------------------------------------------------------- */
echo "\nSecuencia e idempotencia\n";

// La secuencia arranca en 1 y avanza UNA vez por comprobante emitido: el
// contador debe quedar exactamente en «emitidos + 1», sin saltos ni repeticiones.
$sec = (int) qVal("SELECT secuencia_actual FROM ncf_secuencias WHERE tipo='E32'");
$emitidosE32 = (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE tipo_ecf='32'");
afirmar('La secuencia avanzó exactamente una vez por comprobante',
    $sec === $emitidosE32 + 1, "secuencia_actual: $sec · emitidos E32: $emitidosE32");

$reEmision = ecfEmitirVenta((int) $v2['id']);
afirmar('Reemitir la misma venta no crea un segundo documento',
    (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [$v2['id']]) === 1);
afirmar('Reemitir no consume otro e-NCF',
    (int) qVal("SELECT secuencia_actual FROM ncf_secuencias WHERE tipo='E32'") === $sec);

$distintos = (int) qVal("SELECT COUNT(DISTINCT encf) FROM ecf_documentos");
$total = (int) qVal("SELECT COUNT(*) FROM ecf_documentos");
afirmar('Ningún e-NCF se repite', $distintos === $total, "$distintos distintos de $total");

/* ---------------------------------------------------------------------------
 * 5. Crédito fiscal sin RNC: debe quedar registrado, no perderse
 * ------------------------------------------------------------------------- */
echo "\nCrédito fiscal (E31) con un cliente sin RNC\n";

$v4 = venderUna('credito_fiscal', 1);   // Cliente Genérico, sin RNC
$row4 = qOne("SELECT ncf FROM ventas WHERE id = ?", [$v4['id']]);
$doc4 = qOne("SELECT * FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [$v4['id']]);

afirmar('La venta entra igual', !empty($v4['id']));
afirmar('Toma un e-NCF E31', str_starts_with((string) $row4['ncf'], 'E31'), 'ncf: ' . $row4['ncf']);
afirmar('El e-NCF consumido NO se pierde: queda un documento en la bandeja', $doc4 !== null);
afirmar('Marcado como error, no como enviado', $doc4 && $doc4['estado'] === 'error');
afirmar('Explica que falta el RNC del comprador',
    $doc4 && str_contains((string) $doc4['estado_detalle'], 'RNC'),
    'detalle: ' . ($doc4['estado_detalle'] ?? '—'));

/* ---------------------------------------------------------------------------
 * 6. Código QR: nunca bloquea la impresión
 * ------------------------------------------------------------------------- */
echo "\nCódigo QR de la Representación Impresa\n";

// El proveedor sigue apuntando a un puerto muerto, así que cualquier intento
// de descarga fallará. Es justo lo que se quiere comprobar.
$docQr = (int) qVal("SELECT id FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [$v2['id']]);

dbUpdate('ecf_documentos', ['estado' => 'pendiente', 'qr' => null, 'qr_intentos' => 0], 'id = ?', [$docQr]);
afirmar('Sin aceptar todavía, no hay QR', ecfQrDataUri($docQr) === null);
afirmar('Y ni siquiera lo intenta (no gasta llamadas)',
    (int) qVal("SELECT qr_intentos FROM ecf_documentos WHERE id=?", [$docQr]) === 0);

dbUpdate('ecf_documentos', ['estado' => 'aceptado'], 'id = ?', [$docQr]);
$t0 = microtime(true);
$qr = ecfQrDataUri($docQr);
$msQr = (int) round((microtime(true) - $t0) * 1000);
afirmar('Aceptado pero con el proveedor caído: devuelve null sin lanzar', $qr === null);
afirmar('Cuenta el intento para no reintentar en cada reimpresión',
    (int) qVal("SELECT qr_intentos FROM ecf_documentos WHERE id=?", [$docQr]) === 1);
afirmar('No se queda colgado esperando', $msQr < 20000, "tardó {$msQr} ms");

// Tras agotar los intentos deja de insistir.
dbUpdate('ecf_documentos', ['qr_intentos' => ECF_QR_INTENTOS_MAX], 'id = ?', [$docQr]);
$antes = (int) qVal("SELECT COUNT(*) FROM ecf_log");
ecfQrDataUri($docQr);
afirmar('Agotados los intentos, ya no llama al proveedor',
    (int) qVal("SELECT COUNT(*) FROM ecf_log") === $antes);

// Con el QR en caché se sirve sin tocar la red, aunque el proveedor siga caído.
$falso = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('Z', 120));
dbUpdate('ecf_documentos', ['qr' => $falso], 'id = ?', [$docQr]);
$antes = (int) qVal("SELECT COUNT(*) FROM ecf_log");
afirmar('En caché se devuelve tal cual', ecfQrDataUri($docQr) === $falso);
afirmar('Y no genera ninguna llamada', (int) qVal("SELECT COUNT(*) FROM ecf_log") === $antes);
afirmar('ecfQrDeVenta() lo encuentra por la venta', ecfQrDeVenta((int) $v2['id']) === $falso);

// El ticket tiene que salir con el QR dentro.
$_GET['id'] = (string) $v2['id'];
ob_start();
require dirname(__DIR__, 2) . '/modules/pos/ticket.php';
$html = ob_get_clean();
afirmar('El ticket incrusta el QR', str_contains($html, $falso));
afirmar('El ticket rotula «e-NCF», no «NCF»', str_contains($html, 'e-NCF'));
afirmar('Y lleva la leyenda de verificación ante la DGII',
    str_contains($html, 'Verifique este documento ante la DGII'));

/* ---------------------------------------------------------------------------
 * 7. Tarea programada: la cola avanza sola sin hacer esperar a nadie
 * ------------------------------------------------------------------------- */
echo "\nCola: tick oportunista y cron\n";

$reset = static fn() => q("DELETE FROM sistema_estado WHERE clave = 'ecf_cola_tick'");

// Sin trabajo pendiente no debe gastar el turno: si lo quemara, la cola se
// quedaría parada otros cinco minutos justo cuando entre trabajo de verdad.
$reset();
q("UPDATE ecf_documentos SET estado = 'aceptado', proximo_intento = NULL, consultado_at = NOW()");
afirmar('Sin trabajo, ecfHayTrabajoEnCola() dice que no', !ecfHayTrabajoEnCola());
ecfTickSiToca();
afirmar('Y el tick no consume el turno',
    (int) qVal("SELECT COUNT(*) FROM sistema_estado WHERE clave='ecf_cola_tick'") === 0);

// Con trabajo sí entra.
$reset();
q("UPDATE ecf_documentos SET estado = 'pendiente', proximo_intento = NULL, intentos = 0 WHERE id = ?", [$docQr]);
afirmar('Con un pendiente vencido, hay trabajo', ecfHayTrabajoEnCola());

$t0 = microtime(true);
ecfTickSiToca();          // el proveedor sigue caído: fallará, pero sin lanzar
$msTick = (int) round((microtime(true) - $t0) * 1000);
afirmar('El tick corre y reclama el turno',
    (int) qVal("SELECT COUNT(*) FROM sistema_estado WHERE clave='ecf_cola_tick'") === 1);
afirmar('Registra el intento sobre el documento',
    (int) qVal("SELECT intentos FROM ecf_documentos WHERE id=?", [$docQr]) >= 1);
afirmar('No hace esperar a la página más de 30 s', $msTick < 30000, "tardó {$msTick} ms");

// Segunda llamada dentro de la ventana: no debe volver a correr.
$intentosAntes = (int) qVal("SELECT intentos FROM ecf_documentos WHERE id=?", [$docQr]);
q("UPDATE ecf_documentos SET proximo_intento = NULL WHERE id = ?", [$docQr]);
ecfTickSiToca();
afirmar('Dos ticks seguidos no procesan dos veces (freno de turno)',
    (int) qVal("SELECT intentos FROM ecf_documentos WHERE id=?", [$docQr]) === $intentosAntes);

afirmar('Apagado el interruptor, el tick no hace nada', (function () use ($reset, $docQr) {
    $reset();
    ecfGuardarConfig(['activo' => 0]);
    ecfTickSiToca();
    $corrio = (int) qVal("SELECT COUNT(*) FROM sistema_estado WHERE clave='ecf_cola_tick'") > 0;
    ecfGuardarConfig(['activo' => 1]);
    return !$corrio;
})());

/* ------------------------------------------------------------- Alertas */
echo "\nAviso cuando un comprobante se atasca\n";

q("UPDATE ecf_documentos SET estado = 'error', estado_detalle = 'prueba' WHERE id = ?", [$docQr]);
notif_gen_ecf();
$aviso = qOne("SELECT * FROM notificaciones WHERE clave = 'ecf_error' AND estado = 'activa'");
afirmar('Un e-CF en error genera alerta', $aviso !== null);
afirmar('Con prioridad crítica', $aviso && $aviso['prioridad'] === 'critica');
afirmar('Y visible solo para quien puede ver el e-CF', $aviso && $aviso['permiso'] === 'ecf.ver');

// Al resolverse, la alerta se cierra sola (es una situación viva, no un evento).
q("UPDATE ecf_documentos SET estado = 'aceptado' WHERE estado = 'error'");
notif_gen_ecf();
afirmar('Resuelto el problema, la alerta se cierra sola',
    (int) qVal("SELECT COUNT(*) FROM notificaciones WHERE clave='ecf_error' AND estado='activa'") === 0);

/* ---------------------------------------------------------------------------
 * 8. Notas de crédito: los dos errores que no se notan hasta la fiscalización
 * ------------------------------------------------------------------------- */
echo "\nNota de crédito por devolución\n";

ecfGuardarConfig(['activo' => 1, 'envio_automatico' => 0]);
dbUpdate('ncf_secuencias', ['secuencia_actual' => 1, 'secuencia_hasta' => 500,
    'vencimiento' => date('Y-m-d', strtotime('+1 year')), 'activo' => 1], "tipo = 'E34'");

/** Registra una devolución sobre una venta, como lo hace la pantalla. */
function devolver(int $ventaId, float $cantidad): int
{
    $v  = qOne("SELECT * FROM ventas WHERE id = ?", [$ventaId]);
    $vd = qOne("SELECT * FROM venta_detalles WHERE venta_id = ? ORDER BY id LIMIT 1", [$ventaId]);
    $factor = (float) $v['subtotal'] > 0
        ? ((float) $v['subtotal'] - (float) $v['descuento']) / (float) $v['subtotal'] : 1.0;

    $prop  = $cantidad / (float) $vd['cantidad'];
    $base  = round((float) $vd['subtotal'] * $factor * $prop, 2);
    $itbis = round((float) $vd['itbis'] * $prop, 2);
    $sub   = round($base + $itbis, 2);

    $devId = dbInsert('devoluciones', [
        'numero' => nextNumero('devoluciones', 'numero', 'DEV'), 'venta_id' => $ventaId,
        'sucursal_id' => (int) $v['sucursal_id'], 'usuario_id' => current_user()['id'],
        'motivo' => 'Prueba automatizada', 'ncf' => siguienteNCF(ncfTipoNotaCredito()),
        'ncf_modificado' => $v['ncf'], 'subtotal' => $base, 'itbis' => $itbis, 'total' => $sub,
    ]);
    dbInsert('devolucion_detalles', [
        'devolucion_id' => $devId, 'venta_detalle_id' => (int) $vd['id'],
        'producto_id' => (int) $vd['producto_id'], 'descripcion' => $vd['descripcion'],
        'cantidad' => $cantidad, 'precio_unitario' => round($sub / $cantidad, 2), 'subtotal' => $sub,
    ]);
    return $devId;
}

// --- Devolución TOTAL: se devuelve TODA la cantidad vendida ---
$vTot = venderUna('consumidor');
$cantVendida = (float) qVal("SELECT cantidad FROM venta_detalles WHERE venta_id = ? LIMIT 1", [(int) $vTot['id']]);
$dTot = devolver((int) $vTot['id'], $cantVendida);
$docTot = ecfDocumentoDeDevolucion($dTot, ecfFormatearENCF('34', 1));
$devTot = qOne("SELECT * FROM devoluciones WHERE id = ?", [$dTot]);
$ventaTot = qOne("SELECT * FROM ventas WHERE id = ?", [(int) $vTot['id']]);

afirmar('La nota de crédito toma un e-NCF E34',
    ecfENCFValido((string) $devTot['ncf']) && str_starts_with((string) $devTot['ncf'], 'E34'),
    'ncf: ' . $devTot['ncf']);
afirmar('Referencia el e-NCF de la factura',
    $docTot['INFR']['NCFModificado'] === $ventaTot['ncf'],
    $docTot['INFR']['NCFModificado'] . ' vs ' . $ventaTot['ncf']);
afirmar('Devolución total → código 1 (anula el comprobante)',
    $docTot['INFR']['CodigoModificacion'] === '1', 'código: ' . $docTot['INFR']['CodigoModificacion']);

// EL ERROR CARO: declarar el importe reembolsado (que lleva el ITBIS dentro)
// como si fuera base. Se comprueba que el monto sea la BASE, no el total.
$montoNota = 0.0;
foreach ($docTot['ITEM'] as $it) $montoNota += (float) $it['MontoItem'];
afirmar('El monto declarado es la BASE, sin el ITBIS dentro',
    abs($montoNota - (float) $devTot['subtotal']) < 0.01,
    'declarado ' . ecfMonto($montoNota) . ' · base ' . ecfMonto($devTot['subtotal'])
        . ' · reembolsado ' . ecfMonto($devTot['total']));
afirmar('Y NO es el importe reembolsado (que inflaría el ITBIS)',
    abs($montoNota - (float) $devTot['total']) > 0.01 || (float) $devTot['itbis'] == 0.0);
afirmar('La nota de crédito válida pasa la validación',
    ecfValidarDocumento($docTot) === [], implode(' · ', ecfValidarDocumento($docTot)));

// --- Devolución PARCIAL: 1 de 2 unidades ---
$sidP = (int) $_SESSION['sucursal_id'];
$pidP = (int) qVal("SELECT p.id FROM productos p JOIN inventario_stock s ON s.producto_id=p.id
                     AND s.sucursal_id=? WHERE p.activo=1 AND p.tipo='producto' AND s.cantidad>=5
                    ORDER BY p.id LIMIT 1", [$sidP]);
$vPar = registrarVentaPOS(
    ['cart' => [['id' => $pidP, 'cant' => 2]], 'comprobante' => 'consumidor',
     'cliente_id' => 1, 'metodo_pago_id' => 1, 'descuento' => 0],
    ['sid' => $sidP, 'uid' => (int) $u['id'], 'sesion' => null, 'puede_muestra' => false]
);
$dPar   = devolver((int) $vPar['id'], 1.0);
$docPar = ecfDocumentoDeDevolucion($dPar, ecfFormatearENCF('34', 2));
$devPar = qOne("SELECT * FROM devoluciones WHERE id = ?", [$dPar]);

afirmar('Devolución parcial → código 3 (corrige montos, NO anula)',
    $docPar['INFR']['CodigoModificacion'] === '3', 'código: ' . $docPar['INFR']['CodigoModificacion']);
$montoPar = 0.0;
foreach ($docPar['ITEM'] as $it) $montoPar += (float) $it['MontoItem'];
afirmar('La parcial acredita solo lo devuelto, en base',
    abs($montoPar - (float) $devPar['subtotal']) < 0.01,
    'declarado ' . ecfMonto($montoPar) . ' · base devuelta ' . ecfMonto($devPar['subtotal']));
afirmar('Y es la mitad de la venta, no el total',
    abs($montoPar - ((float) qVal("SELECT subtotal FROM ventas WHERE id=?", [(int) $vPar['id']]) / 2)) < 0.01);

// Una segunda devolución de la misma venta nunca puede ser «anulación total».
$dPar2   = devolver((int) $vPar['id'], 1.0);
$docPar2 = ecfDocumentoDeDevolucion($dPar2, ecfFormatearENCF('34', 3));
afirmar('Con una devolución previa, la siguiente sigue siendo código 3',
    $docPar2['INFR']['CodigoModificacion'] === '3', 'código: ' . $docPar2['INFR']['CodigoModificacion']);

echo "\n", str_repeat('-', 74), "\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, count($fallos));
if ($fallos) { foreach ($fallos as $f) echo "   - $f\n"; echo "\n"; exit(1); }
echo "  ✓ El e-CF está enganchado y una caída del proveedor no tumba la venta.\n\n";
exit(0);
