<?php
/**
 * Reescribe el motivo y el código de los e-CF ya consultados.
 *
 *   php database/reparar_dictamen_ecf.php          (solo enseña lo que haría)
 *   php database/reparar_dictamen_ecf.php --aplicar
 *
 * ============================================================================
 *  PARA QUÉ EXISTE
 * ============================================================================
 *
 * La respuesta del proveedor trae dos dictámenes. El del sobre —`status.code`
 * 0, «Transacción exitosa»— solo dice que la consulta llegó y volvió. El del
 * comprobante —`data.responseCode`, `data.responseMessage`— es el de la DGII.
 *
 * Se estaba guardando el del sobre. Resultado: una factura rechazada por la
 * DGII se leía en pantalla con la etiqueta «rechazado» y, justo debajo, el
 * motivo «Transacción exitosa». El motivo verdadero («Fecha de vencimiento de
 * secuencia inválida») no aparecía en ningún sitio, así que no había manera de
 * saber qué corregir.
 *
 * Arreglado el código, las filas viejas siguen mintiendo, y no se corrigen
 * solas: la cola solo reconsulta los documentos en estado «enviado», nunca los
 * que ya quedaron aceptados o rechazados. De ahí este repaso.
 *
 * ---------------------------------------------------------------------------
 *  NO LLAMA AL PROVEEDOR
 *
 * No hace falta: la respuesta completa está guardada en `respuesta_estado`, en
 * la misma fila. Esto solo la vuelve a leer con el criterio correcto. Por eso
 * es repetible, no consume secuencias y no puede alterar el estado fiscal de
 * nada: reescribe dos columnas informativas y ninguna más.
 */

$raiz = dirname(__DIR__);
$aplicar = in_array('--aplicar', $argv, true);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/cli.php';
$_SERVER['HTTP_HOST'] = 'localhost';
ob_start();
require $raiz . '/app/bootstrap.php';
ob_end_clean();

$docs = qAll("SELECT id, encf, estado, codigo_respuesta, estado_detalle, respuesta_estado
                FROM ecf_documentos
               WHERE respuesta_estado IS NOT NULL AND respuesta_estado <> ''
               ORDER BY id");

echo 'Documentos con respuesta guardada: ' . count($docs) . PHP_EOL;
echo $aplicar ? "Modo: APLICAR" : "Modo: solo mostrar (añade --aplicar para escribir)";
echo PHP_EOL . PHP_EOL;

$cambiados = 0;
$intactos  = 0;

foreach ($docs as $d) {
    $json = json_decode((string) $d['respuesta_estado'], true);
    if (!is_array($json)) {
        printf("  #%-4d %-16s respuesta ilegible, se deja como está" . PHP_EOL, $d['id'], (string) $d['encf']);
        continue;
    }

    $v = ecfVeredictoDocumento($json);

    // Sin dictamen del comprobante no hay nada mejor que lo que ya había.
    if ($v['code'] === null && $v['message'] === null) { $intactos++; continue; }

    $codigo  = $v['code'] ?? $d['codigo_respuesta'];
    $motivo  = $v['message'] !== null ? mb_substr($v['message'], 0, 500) : $d['estado_detalle'];

    if ((string) $codigo === (string) $d['codigo_respuesta'] && $motivo === $d['estado_detalle']) {
        $intactos++;
        continue;
    }

    printf("  #%-4d %-16s %-10s  %s · «%s»" . PHP_EOL . "       %-16s %-10s  %s · «%s»" . PHP_EOL,
        $d['id'], (string) $d['encf'], $d['estado'],
        (string) $d['codigo_respuesta'], (string) $d['estado_detalle'],
        'queda como', '', (string) $codigo, (string) $motivo);

    if ($aplicar) {
        dbUpdate('ecf_documentos',
            ['codigo_respuesta' => $codigo, 'estado_detalle' => $motivo],
            'id = ?', [$d['id']]);
    }
    $cambiados++;
}

echo PHP_EOL . "  ya correctos: $intactos" . PHP_EOL;
echo '  ' . ($aplicar ? "corregidos:   $cambiados" : "por corregir: $cambiados") . PHP_EOL;
if (!$aplicar && $cambiados > 0) {
    echo PHP_EOL . "  Nada se ha escrito. Vuelve a lanzarlo con --aplicar." . PHP_EOL;
}
