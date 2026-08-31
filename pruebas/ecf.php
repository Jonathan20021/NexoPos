<?php
/**
 * Banco de pruebas de la lectura de respuestas del proveedor de e-CF.
 *
 *   php pruebas/ecf.php
 *
 * Son funciones puras sobre el JSON que devuelve el proveedor: no tocan la base
 * ni la red. Las tramas de abajo son respuestas REALES recogidas de la cuenta
 * del cliente, recortadas a lo que importa.
 *
 * El caso que obliga a que esto exista:
 *
 *   La respuesta trae DOS dictámenes. El del sobre —`status.code` 0,
 *   «Transacción exitosa»— dice que la consulta llegó y volvió. El del
 *   comprobante —`data.responseCode` 145, «Fecha de vencimiento de secuencia
 *   inválida»— dice que la DGII rechazó la factura. Se estaba guardando el
 *   primero, así que en pantalla una factura rechazada aparecía con la etiqueta
 *   «rechazado» y, justo debajo, el motivo «Transacción exitosa». Nadie podía
 *   saber por qué esa factura no existe (se corrigió el 2026-08-31).
 *
 * Devuelve 0 si todo pasa y 1 si algo falla.
 */

require_once dirname(__DIR__) . '/includes/ecf_api.php';

$fallos = 0;
$total  = 0;

function comprueba(string $caso, $obtenido, $esperado): void
{
    global $fallos, $total;
    $total++;
    $ok = $obtenido === $esperado;
    if (!$ok) $fallos++;
    printf("  %s %-58s %s\n", $ok ? 'OK   ' : 'FALLA', $caso,
        $ok ? '' : 'obtenido ' . var_export($obtenido, true) . ', esperado ' . var_export($esperado, true));
}

function titulo(string $t): void { echo "\n== $t ==\n"; }

/** Respuesta de consulta con el sobre siempre en éxito, como la manda el proveedor. */
function trama(array $data): array
{
    return ['status' => ['code' => '0', 'message' => 'Transacción exitosa'], 'data' => $data];
}

$aceptado = trama(['trackId' => 'a1', 'filename' => 'x.xml',
    'status' => 'Aceptado', 'responseCode' => '1', 'responseMessage' => 'Aceptado']);

$rechazado = trama(['trackId' => 'a2', 'filename' => 'x.xml',
    'status' => 'Rechazado', 'responseCode' => '145',
    'responseMessage' => 'Fecha de vencimiento de secuencia inválida.']);

$condicional = trama(['trackId' => 'a3', 'filename' => 'x.xml',
    'status' => 'Aceptado condicional', 'responseCode' => '4',
    'responseMessage' => 'Aceptado Condicional',
    'responseObservations' => '1385 - El campo RNCComprador del área Comprador de la sección Encabezado no es válido.']);

/* ============================================================
 *  El estado que se le pone al comprobante
 * ============================================================ */
titulo('Estado del comprobante');
comprueba('«Aceptado» es aceptado', ecfInterpretarEstado($aceptado), 'aceptado');
comprueba('«Rechazado» es rechazado pese al sobre en éxito', ecfInterpretarEstado($rechazado), 'rechazado');
comprueba('«Aceptado condicional» sigue siendo aceptado', ecfInterpretarEstado($condicional), 'aceptado');
comprueba('sin respuesta se queda enviado y se reconsulta', ecfInterpretarEstado(null), 'enviado');
comprueba('en proceso se queda enviado',
    ecfInterpretarEstado(trama(['status' => 'En proceso'])), 'enviado');

/* ============================================================
 *  El motivo y el código que se guardan
 * ============================================================ */
titulo('Dictamen del comprobante, no el del sobre');
comprueba('el código del rechazo es el de la DGII, no el 0 del sobre',
    ecfVeredictoDocumento($rechazado)['code'], '145');
comprueba('el motivo del rechazo es el real',
    ecfVeredictoDocumento($rechazado)['message'], 'Fecha de vencimiento de secuencia inválida.');
comprueba('un aceptado trae el código 1', ecfVeredictoDocumento($aceptado)['code'], '1');
comprueba('el sobre sigue diciendo lo suyo, aparte',
    ecfEstadoRespuesta($rechazado)['message'], 'Transacción exitosa');

titulo('Reparos con los que la DGII acepta igual');
comprueba('el condicional trae el código 4', ecfVeredictoDocumento($condicional)['code'], '4');
comprueba('los reparos se pegan al motivo y no se pierden',
    ecfVeredictoDocumento($condicional)['message'],
    'Aceptado Condicional · 1385 - El campo RNCComprador del área Comprador de la sección Encabezado no es válido.');
comprueba('varios reparos se listan separados',
    ecfVeredictoDocumento(trama(['responseMessage' => 'Aceptado Condicional',
        'responseObservations' => ['1385 - RNC del comprador', '1240 - Fecha fuera de rango']]))['message'],
    'Aceptado Condicional · 1385 - RNC del comprador · 1240 - Fecha fuera de rango');
comprueba('un reparo vacío no ensucia el motivo',
    ecfVeredictoDocumento(trama(['responseMessage' => 'Aceptado', 'responseObservations' => '']))['message'],
    'Aceptado');

/* ============================================================
 *  Respuestas incompletas: nunca inventar un dictamen
 * ============================================================ */
titulo('Respuestas sin dictamen del comprobante');
comprueba('sin «data» no hay código', ecfVeredictoDocumento(['status' => ['code' => '0']])['code'], null);
comprueba('sin «data» no hay motivo', ecfVeredictoDocumento(['status' => ['code' => '0']])['message'], null);
comprueba('null no revienta', ecfVeredictoDocumento(null)['code'], null);
comprueba('«data» vacío no inventa motivo', ecfVeredictoDocumento(trama([]))['message'], null);

echo "\n" . ($fallos === 0
        ? "TODO EN VERDE ($total comprobaciones)"
        : "$fallos de $total FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
