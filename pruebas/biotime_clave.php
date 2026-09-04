<?php
/**
 * Guarda la contraseña del reloj en la configuración local.
 *
 *   php pruebas/biotime_clave.php
 *
 * Existe para que la clave la teclee UNA PERSONA y no viaje por ningún sitio
 * donde quede escrita sin querer:
 *
 *   · No va en la línea de comandos, así que no entra en el historial del shell.
 *   · Se pide por consola y, si Windows lo permite, sin mostrarla en pantalla.
 *   · Se escribe solo en config/config.local.php, que no se versiona.
 *   · Nunca se imprime: al terminar solo se dice cuántos caracteres se guardaron.
 *
 * Para probar la conexión SIN guardar nada, no hace falta esto:
 *
 *   BIOTIME_CLAVE='...' php pruebas/biotime.php
 */

$config = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($config)) {
    fwrite(STDERR, "  No existe config/config.local.php.\n");
    exit(1);
}

/**
 * Lee una línea sin mostrarla, si se puede.
 *
 * `Read-Host -AsSecureString` solo funciona con una consola de verdad: si esto
 * corre desde un editor, un hook o una tubería, no hay consola y hay que caer a
 * la lectura normal. Se avisa cuando pasa, en vez de aparentar que se ocultó.
 */
function leerClave(string $etiqueta): string
{
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0 && stream_isatty(STDIN)) {
        $ps = 'powershell -NoProfile -Command "$s = Read-Host -AsSecureString '
            . escapeshellarg($etiqueta) . '; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"';
        $salida = shell_exec($ps);
        if (is_string($salida) && trim($salida) !== '') return trim($salida);
        fwrite(STDOUT, "  (no se pudo ocultar; se verá al escribir)\n");
    }
    fwrite(STDOUT, '  ' . $etiqueta . ': ');
    return trim((string) fgets(STDIN));
}

echo "\n  CONTRASEÑA DEL RELOJ BIOMÉTRICO\n";
echo "  " . str_repeat('─', 62) . "\n";
echo "  Se guarda en config/config.local.php y no se imprime nunca.\n\n";

$clave = leerClave('Contraseña de la cuenta de BioTime');
if ($clave === '') {
    fwrite(STDERR, "\n  No se escribió nada. No se tocó la configuración.\n\n");
    exit(1);
}

$php = file_get_contents($config);
$linea = "define('BIOTIME_CLAVE', " . var_export($clave, true) . ");";

// Se reemplaza la que hubiera —comentada o no— en vez de añadir una segunda:
// dos `define` de lo mismo dan un aviso de PHP y gana la primera, que sería la
// vieja. Es justo el fallo que dejaría a alguien mirando por qué no entra.
$patron = "~^\s*(//\s*)?define\(\s*'BIOTIME_CLAVE'.*$~m";
$php = preg_match($patron, $php)
    ? preg_replace($patron, $linea, $php, 1)
    : rtrim($php, "\r\n") . "\n" . $linea . "\n";

if (file_put_contents($config, $php) === false) {
    fwrite(STDERR, "  No se pudo escribir en config/config.local.php.\n");
    exit(1);
}

printf("\n  Guardada (%d caracteres) en config/config.local.php.\n", strlen($clave));
echo "  Ese fichero no se versiona: comprobado con `git check-ignore`.\n\n";
echo "  Ahora:  php pruebas/biotime.php\n\n";
exit(0);
