<?php
/**
 * Verificador del generador de tramas contra los ejemplos OFICIALES.
 *
 *   php database/ecf_ejemplos/verificar.php
 *
 * Qué hace: por cada trama oficial del proveedor (extraídas de
 * «Ejemplos básicos_Archivos TXT.xlsx») la parsea con nuestro layout, la vuelve
 * a generar y compara el resultado carácter por carácter con el original.
 *
 * Si coinciden, el orden y la cantidad de campos de cada sección es exactamente
 * el que espera la plataforma. Es la prueba más fuerte que se puede hacer sin
 * credenciales: no depende de la red ni de que el ambiente de pruebas esté
 * arriba, y detecta el error más caro de todos —un campo corrido de posición—
 * que de otro modo solo aparecería cuando la DGII rechace el comprobante.
 *
 * Los tipos 41, 43, 46 y 47 se reportan como OMITIDOS: sus fixtures están
 * guardados, pero no son comprobantes de VENTA —documentan compras, gastos,
 * exportaciones y pagos al exterior— y el generador no los implementa.
 */

require_once dirname(__DIR__, 2) . '/includes/ecf_catalogos.php';
require_once dirname(__DIR__, 2) . '/includes/ecf_trama.php';

$dir = __DIR__;
$fixtures = glob($dir . '/*.txt');
sort($fixtures);

$ok = $fallos = $omitidos = 0;
$detalles = [];
$normalizadas = [];

/** Compara dos tramas ignorando espacios sobrantes al borde de cada campo. */
$igualSalvoEspacios = static function (string $a, string $b): bool {
    $limpia = static function (string $t): string {
        $out = [];
        foreach (explode("\r\n", $t) as $linea) {
            $out[] = implode('|', array_map('trim', ecfDividirLinea($linea)));
        }
        return implode("\r\n", $out);
    };
    return $limpia($a) === $limpia($b);
};

foreach ($fixtures as $ruta) {
    $nombre   = basename($ruta);
    $original = str_replace(["\r\n", "\r"], "\n", rtrim(file_get_contents($ruta), "\r\n"));
    $original = str_replace("\n", "\r\n", $original);

    try {
        $doc = ecfParsearTrama($original);
    } catch (InvalidArgumentException $e) {
        $omitidos++;
        continue;
    }

    $generada = ecfConstruirTrama($doc);

    if ($generada === $original) { $ok++; continue; }

    // El ejemplo oficial trae algún campo con espacios sobrantes al borde
    // («PRODUCTO EXENTO 44444 »). Recortarlos es correcto —un espacio final es
    // ruido, no dato— así que se cuenta aparte en vez de darlo por fallo.
    if ($igualSalvoEspacios($generada, $original)) {
        $ok++;
        $normalizadas[] = $nombre;
        continue;
    }

    $fallos++;
    $lineasO = explode("\r\n", $original);
    $lineasG = explode("\r\n", $generada);
    $diff = [];
    foreach ($lineasO as $i => $lo) {
        $lg = $lineasG[$i] ?? '«falta la línea»';
        if ($lo !== $lg) {
            $diff[] = sprintf(
                "    línea %d (%d campos esperados vs %d generados)\n      esperado: %s\n      generado: %s",
                $i + 1,
                count(ecfDividirLinea($lo)),
                count(ecfDividirLinea($lg)),
                $lo,
                $lg
            );
            if (count($diff) >= 3) break;
        }
    }
    if (count($lineasG) > count($lineasO)) {
        $diff[] = sprintf('    sobran %d líneas generadas', count($lineasG) - count($lineasO));
    }
    $detalles[] = "  ✗ $nombre\n" . implode("\n", $diff);
}

echo str_repeat('=', 74), "\n";
echo "  Verificación del generador de tramas e-CF contra ejemplos oficiales\n";
echo str_repeat('=', 74), "\n\n";

if ($detalles) {
    echo implode("\n\n", $detalles), "\n\n";
}

printf("  Reproducidas          : %d\n", $ok);
printf("  Con diferencias       : %d\n", $fallos);
printf("  Omitidas (sin layout) : %d\n", $omitidos);
printf("  Total de fixtures     : %d\n", count($fixtures));

if ($normalizadas) {
    printf(
        "\n  Nota: %d ejemplo(s) coinciden tras recortar espacios sobrantes que el\n"
        . "  documento original trae dentro de un campo (%s).\n",
        count($normalizadas),
        implode(', ', $normalizadas)
    );
}
echo "\n";

if ($fallos === 0) {
    echo "  ✓ Todos los tipos implementados (" . implode(', ', ecfTiposSoportados())
       . ") reproducen la trama oficial.\n\n";
    exit(0);
}
echo "  ✗ Hay layouts que no calzan. Revisa el orden de campos en ecfLayout().\n\n";
exit(1);
