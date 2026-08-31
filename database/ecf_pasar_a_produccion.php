<?php
/**
 * Cambio del e-CF de PRUEBAS a PRODUCCIÓN.
 *
 *   php database/ecf_pasar_a_produccion.php            ← simula, no toca nada
 *   php database/ecf_pasar_a_produccion.php --aplicar  ← lo hace de verdad
 *
 * ============================================================================
 *  POR QUÉ ESTO ES UN SCRIPT Y NO DOS CLICS
 * ============================================================================
 *
 * Hay que hacer DOS cosas y el orden importa:
 *
 *   1. cargar los rangos autorizados por la DGII, y
 *   2. apuntar el sistema al ambiente de producción de LUGANIS.
 *
 * Si se hace solo lo primero, cada venta CONSUME un e-NCF autorizado de verdad
 * contra el ambiente de PRUEBAS: el número queda gastado en nuestra base y la
 * DGII nunca lo ve. Después aparecen huecos en la secuencia que no se explican.
 *
 * Si se hace solo lo segundo, se emite contra producción con números de prueba
 * —los 900xxx— que la DGII rechaza porque no están autorizados.
 *
 * Van juntos, en una transacción, o no van.
 *
 * ---------------------------------------------------------------------------
 *  LOS RANGOS SALEN DE LOS PDF DE LA DGII, NO DE UNA SUPOSICIÓN
 *
 * IMPORTERS E31.pdf e IMPORTERS E32.pdf, solicitud del 27/08/2026, RNC
 * 102616541 · IMPORTERS T & E S A. Ambas APROBADAS.
 */

$RAIZ = dirname(__DIR__);
require_once $RAIZ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }
$aplicar = in_array('--aplicar', $argv, true);

/* ============================================================
 *  Lo que autorizó la DGII
 * ============================================================ */
const RNC_AUTORIZADO = '102616541';

$RANGOS = [
    'E31' => [
        'autorizacion' => '6005458872',
        'desde'        => 1085,
        'hasta'        => 3084,
        'vencimiento'  => '2027-12-31',
        'solicitada'   => 2000,
        'aprobada'     => 2000,
        'etiqueta'     => 'Factura de Crédito Fiscal Electrónico',
    ],
    'E32' => [
        'autorizacion' => '6005458879',
        'desde'        => 12001,
        'hasta'        => 16213,
        // La DGII pone «N/A»: el tipo 32 NO lleva FechaVencimientoSecuencia en
        // la trama, así que aquí va NULL y es lo correcto, no un olvido.
        'vencimiento'  => null,
        'solicitada'   => 15000,
        'aprobada'     => 4213,   // ojo: aprobó MENOS de lo pedido
        'etiqueta'     => 'Factura de Consumo Electrónica',
    ],
];

// Nombres propios a propósito: un `titulo()` genérico choca con el de cualquier
// script de utilidad que incluya este archivo, y como el bootstrap apaga los
// errores en producción, el choque NO se ve: la salida sale vacía y parece que
// no pasó nada. Ya costó un rato de diagnóstico.
function ln(string $t = ''): void { echo $t, "\n"; }
function ecfPpTitulo(string $t): void { ln(); ln(str_repeat('=', 74)); ln('  ' . mb_strtoupper($t, 'UTF-8')); ln(str_repeat('=', 74)); }

ecfPpTitulo($aplicar ? 'cambio a producción · SE VA A APLICAR' : 'cambio a producción · SIMULACIÓN');
if (!$aplicar) ln('  Nada se escribe. Para hacerlo de verdad:  --aplicar');

/* ============================================================
 *  Guardas. Cualquiera que falle detiene el cambio.
 * ============================================================ */
ecfPpTitulo('comprobaciones previas');
$problemas = [];
$avisos    = [];

$cfg = ecfConfig();
$emp = $GLOBALS['empresa'] ?? [];

// 1) El RNC de la empresa tiene que ser el de la autorización.
$rncEmpresa = preg_replace('/\D/', '', (string) ($emp['rnc'] ?? ''));
$okRnc = $rncEmpresa === RNC_AUTORIZADO;
ln(($okRnc ? '  ✓ ' : '  ✗ ') . 'El RNC de la empresa coincide con el de la autorización  ('
   . $rncEmpresa . ' vs ' . RNC_AUTORIZADO . ')');
if (!$okRnc) $problemas[] = 'El RNC no coincide: se estarían cargando rangos de otro contribuyente.';

// 2) Sin URL de producción no hay a dónde emitir.
$url = trim((string) ($cfg['url_produccion'] ?? ''));
ln(($url !== '' ? '  ✓ ' : '  ✗ ') . 'URL del ambiente de producción  (' . ($url ?: 'VACÍA') . ')');
if ($url === '') $problemas[] = 'Falta `url_produccion`. La da LUGANIS; sin ella toda emisión falla.';

// 3) Credenciales.
$okCred = trim((string) ($cfg['usuario'] ?? '')) !== '' && trim((string) ($cfg['clave'] ?? '')) !== '';
ln(($okCred ? '  ✓ ' : '  ✗ ') . 'Usuario y clave configurados  (' . ($cfg['usuario'] ?: '—') . ')');
if (!$okCred) $problemas[] = 'Faltan usuario o clave.';
else $avisos[] = 'Las credenciales actuales son las que funcionan en PRUEBAS. '
               . 'Confirma con LUGANIS si valen también en producción o si hay otras.';

// 4) No retroceder: si ya se emitió algo en producción, el rango no puede
//    empezar por debajo. Hoy no hay nada, pero mañana este script puede
//    volver a usarse para cargar el siguiente bloque.
foreach ($RANGOS as $tipo => $r) {
    $num = substr($tipo, 1);
    $ultimo = (int) qVal(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(encf, 4) AS UNSIGNED)), 0)
           FROM ecf_documentos WHERE tipo_ecf = ? AND estado IN ('aceptado','aceptado_condicional')",
        [$num]
    );
    // Los 900xxx son de prueba: no cuentan como emisión real.
    $real = $ultimo > 0 && $ultimo < 900000 ? $ultimo : 0;
    $ok = $real === 0 || $r['desde'] > $real;
    ln(($ok ? '  ✓ ' : '  ✗ ') . $tipo . ': el rango arranca por encima de lo ya emitido de verdad'
       . '  (emitido ' . ($real ?: 'nada') . ' · arranca en ' . $r['desde'] . ')');
    if (!$ok) $problemas[] = "$tipo: el rango empezaría por debajo de un e-NCF ya aceptado.";
}

// 5) Tipos que se quedan SIN rango autorizado.
$sinRango = qCol("SELECT tipo FROM ncf_secuencias WHERE tipo LIKE 'E%' AND tipo NOT IN ('"
               . implode("','", array_keys($RANGOS)) . "')");
if ($sinRango) {
    ln('  ! Sin autorización de la DGII: ' . implode(', ', $sinRango));
    $avisos[] = 'Esos tipos se DESACTIVAN al pasar a producción, para que nadie emita un número '
              . 'inventado. Ojo con E34: sin nota de crédito NO se puede revertir una factura.';
}

/* ============================================================
 *  Qué quedaría
 * ============================================================ */
ecfPpTitulo('lo que se cargaría');
foreach ($RANGOS as $tipo => $r) {
    $actual = qOne("SELECT secuencia_actual, secuencia_hasta, vencimiento FROM ncf_secuencias WHERE tipo = ?", [$tipo]);
    ln(sprintf('  %s · %s', $tipo, $r['etiqueta']));
    ln(sprintf('      autorización %s   %s comprobantes (se pidieron %s)',
        $r['autorizacion'], number_format($r['aprobada']), number_format($r['solicitada'])));
    ln(sprintf('      ahora  %8s → %-8s  vence %s',
        $actual['secuencia_actual'] ?? '—', $actual['secuencia_hasta'] ?? '—', $actual['vencimiento'] ?: '—'));
    ln(sprintf('      queda  %8s → %-8s  vence %s',
        $r['desde'], $r['hasta'], $r['vencimiento'] ?? 'N/A (el tipo 32 no la lleva)'));
    ln(sprintf('      primer e-NCF que saldría:  %s%s', $tipo, str_pad((string) $r['desde'], 10, '0', STR_PAD_LEFT)));
    ln();
}
ln('  ecf_config.ambiente:  ' . ($cfg['ambiente'] ?? '?') . '  →  produccion');
ln('  Los tokens de sesión se borran: hay que autenticarse de nuevo contra producción.');

if ($avisos) {
    ecfPpTitulo('avisos');
    foreach ($avisos as $a) ln('  ! ' . $a);
}

if ($problemas) {
    ecfPpTitulo('NO se puede cambiar todavía');
    foreach ($problemas as $p) ln('  ✗ ' . $p);
    ln();
    ln('  Resuelve lo de arriba y vuelve a correrlo.');
    ln();
    exit(1);
}

if (!$aplicar) {
    ecfPpTitulo('todo listo');
    ln('  Las comprobaciones pasan. Para aplicarlo:');
    ln();
    ln('      php database/ecf_pasar_a_produccion.php --aplicar');
    ln();
    ln('  Haz un respaldo antes. Después de aplicarlo, la primera venta emite');
    ln('  un comprobante FISCAL DE VERDAD: no es una prueba más.');
    ln();
    exit(0);
}

/* ============================================================
 *  Aplicar
 * ============================================================ */
$u = qOne("SELECT * FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
$_SESSION['user'] = $u; $_SESSION['user']['es_super'] = 1;

try {
    tx(function () use ($RANGOS, $sinRango) {
        foreach ($RANGOS as $tipo => $r) {
            dbUpdate('ncf_secuencias', [
                'secuencia_actual' => $r['desde'],
                'secuencia_hasta'  => $r['hasta'],
                'vencimiento'      => $r['vencimiento'],
                'autorizacion'     => $r['autorizacion'],
                'autorizada_at'    => '2026-08-27',
                'ambiente'         => 'produccion',
                'activo'           => 1,
            ], 'tipo = ?', [$tipo]);
        }
        // Lo que no tiene rango autorizado NO puede emitir en producción.
        foreach ($sinRango as $tipo) {
            dbUpdate('ncf_secuencias', ['activo' => 0, 'ambiente' => 'produccion'], 'tipo = ?', [$tipo]);
        }
    });

    // Fuera de la transacción: tocar la config no debe deshacer los rangos.
    ecfGuardarConfig([
        'ambiente'      => 'produccion',
        'access_token'  => null,
        'refresh_token' => null,
        'token_expira'  => null,
    ]);

    audit('ecf', 'configurar',
        'e-CF cambiado a PRODUCCIÓN · E31 aut. 6005458872 (1085-3084) · E32 aut. 6005458879 (12001-16213)'
        . ($sinRango ? ' · desactivados sin autorización: ' . implode(', ', $sinRango) : ''),
        ['tabla' => 'ecf_config', 'registro_id' => null]);

    ecfPpTitulo('hecho');
    foreach (qAll("SELECT tipo, secuencia_actual, secuencia_hasta, vencimiento, autorizacion, ambiente, activo
                     FROM ncf_secuencias WHERE tipo LIKE 'E%' ORDER BY tipo") as $s) {
        ln(sprintf('  %-4s %8s → %-8s vence %-12s aut %-12s %s  activo=%s',
            $s['tipo'], $s['secuencia_actual'], $s['secuencia_hasta'], $s['vencimiento'] ?: '—',
            $s['autorizacion'] ?: '—', $s['ambiente'], $s['activo']));
    }
    ln();
    ln('  ambiente: ' . (ecfConfig()['ambiente'] ?? '?'));
    ln('  A partir de ahora cada venta emite un comprobante fiscal de verdad.');
    ln();
} catch (Throwable $e) {
    ln();
    ln('  ✗ ' . $e->getMessage());
    ln('  No se cambió nada. Revisa y vuelve a intentarlo.');
    exit(1);
}
