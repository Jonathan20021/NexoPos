<?php
/**
 * Sella la versión antes de hacer commit.
 *
 *   php database/marcar_version.php
 *
 * ============================================================================
 *  PARA QUÉ EXISTE
 * ============================================================================
 *
 * El despliegue es «cPanel → Git Version Control → Update From Remote», y hasta
 * ahora no había forma de comprobar desde fuera si había entrado: casi todo el
 * código vive detrás del login y `database/` está bloqueado por el servidor.
 * Cada despliegue terminaba en «púlsalo tú y dime si se ve».
 *
 * Este script escribe `assets/version.txt`, que el servidor SÍ sirve en abierto
 * —`.txt` no está en las extensiones bloqueadas del .htaccess—. Después,
 * `verificar_despliegue.php` lo pide por HTTP y lo compara con el local.
 *
 * ---------------------------------------------------------------------------
 *  POR QUÉ NO LLEVA EL HASH DEL PROPIO COMMIT
 *
 * Porque no se puede: el hash no existe hasta que el commit está hecho, y
 * meterlo después cambiaría el hash otra vez. Lo que lleva es el hash SOBRE EL
 * QUE se construye —el HEAD de ahora mismo— más un número de compilación y la
 * fecha UTC. Eso identifica la entrega sin ambigüedad y sin morderse la cola.
 *
 * ---------------------------------------------------------------------------
 *  NO LLEVA EL MENSAJE DEL COMMIT, A PROPÓSITO
 *
 * El archivo es PÚBLICO. Un mensaje de commit puede nombrar a un cliente, un
 * fallo sin arreglar o una decisión interna. Build, fecha y hash base no dicen
 * nada que importe a quien lo lea desde fuera.
 */

$raiz = dirname(__DIR__);
$destino = $raiz . '/assets/version.txt';

/** Ejecuta git y devuelve la primera línea, o null. */
$git = static function (string $args) use ($raiz): ?string {
    $salida = @shell_exec('git -C ' . escapeshellarg($raiz) . ' ' . $args . ' 2>&1');
    $salida = trim((string) $salida);
    return $salida === '' ? null : strtok($salida, "\n");
};

$base = $git('rev-parse --short HEAD') ?? 'desconocido';
if (!preg_match('/^[0-9a-f]{7,40}$/', $base)) $base = 'desconocido';

// El número de compilación se lee del propio archivo y sube de uno en uno.
$build = 0;
if (is_file($destino)) {
    foreach (file($destino, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (str_starts_with($l, 'build=')) $build = (int) substr($l, 6);
    }
}
$build++;

$contenido = "build=$build\n"
           . 'fecha=' . gmdate('Y-m-d\TH:i:s\Z') . "\n"
           . "base=$base\n";

if (@file_put_contents($destino, $contenido) === false) {
    fwrite(STDERR, "No se pudo escribir $destino\n");
    exit(1);
}

echo "assets/version.txt sellado:\n";
echo preg_replace('/^/m', '  ', $contenido);
echo "\nAcuérdate de incluirlo en el commit (git add -A ya lo coge).\n";
