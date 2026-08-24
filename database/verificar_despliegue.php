<?php
/**
 * ¿Llegó el último push a producción?
 *
 *   php database/verificar_despliegue.php [https://dominio]
 *
 * Pide `assets/version.txt` por HTTP y lo compara con el que hay en el repo.
 * Es la respuesta a algo que llevaba varios despliegues sin poder cerrarse:
 * el código vive detrás del login y `database/` está bloqueado, así que no
 * había nada público donde mirar.
 *
 * Además del contraste, avisa de dos cosas que se dan en la práctica:
 *
 *   · que el archivo local esté SIN SELLAR —alguien hizo commit sin pasar por
 *     marcar_version.php—, en cuyo caso una diferencia no prueba nada;
 *   · que el archivo remoto no exista todavía, que es lo normal la primera vez.
 */

$raiz  = dirname(__DIR__);
$local = $raiz . '/assets/version.txt';

$base = $argv[1] ?? null;
if (!$base) {
    // Sin argumento se intenta con la URL configurada; si no hay, se pide.
    if (is_file($raiz . '/config/config.php')) {
        $cfg = @file_get_contents($raiz . '/config/config.php');
        if ($cfg && preg_match("/APP_URL['\"]?\s*,\s*['\"](https?:\/\/[^'\"]+)/", $cfg, $m)) $base = $m[1];
    }
}
if (!$base) {
    fwrite(STDERR, "Indica el dominio:  php database/verificar_despliegue.php https://tu-dominio\n");
    exit(2);
}
$url = rtrim($base, '/') . '/assets/version.txt';

/** Lee un archivo de versión en su forma clave=valor. */
$parsear = static function (string $txt): array {
    $out = [];
    foreach (preg_split('/\R/', trim($txt)) as $l) {
        if (strpos($l, '=') === false) continue;
        [$k, $v] = explode('=', $l, 2);
        $out[trim($k)] = trim($v);
    }
    return $out;
};

echo "\n" . str_repeat('=', 70) . "\n  ¿LLEGÓ EL ÚLTIMO PUSH?\n" . str_repeat('=', 70) . "\n";
echo "  Consultando $url\n\n";

if (!is_file($local)) {
    echo "  ✗ No hay assets/version.txt en el repo. Ejecuta antes:\n";
    echo "      php database/marcar_version.php\n\n";
    exit(1);
}
$vLocal = $parsear((string) file_get_contents($local));

/* ---------- ¿Está sellado el commit actual? ----------
   Si el último commit tocó código pero nadie selló la versión, el archivo
   local es viejo y cualquier comparación engaña. Se detecta comparando la
   fecha del sello con la del último commit. */
$fechaCommit = trim((string) @shell_exec('git -C ' . escapeshellarg($raiz) . ' log -1 --format=%cI 2>&1'));
$sinSellar = false;
if ($fechaCommit && !empty($vLocal['fecha'])) {
    $tSello  = strtotime($vLocal['fecha']);
    $tCommit = strtotime($fechaCommit);
    // Un minuto de holgura: sellar y hacer commit son dos pasos seguidos.
    if ($tSello && $tCommit && $tSello < $tCommit - 60) $sinSellar = true;
}

/* ---------- Pedir el remoto ---------- */
$remoto = null; $codigo = 0; $error = '';
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        // Cache-Control para que no conteste un intermedio con la versión vieja:
        // sería el peor de los fallos posibles en esta herramienta.
        CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
    ]);
    $cuerpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);
    if ($cuerpo !== false && $codigo === 200) $remoto = $parsear((string) $cuerpo);
} else {
    fwrite(STDERR, "  Falta la extensión curl de PHP.\n");
    exit(2);
}

$f = static fn(array $v): string => 'build ' . ($v['build'] ?? '?')
    . ' · ' . ($v['fecha'] ?? '?') . ' · sobre ' . ($v['base'] ?? '?');

echo "  En el repo ....... " . $f($vLocal) . "\n";
if ($remoto === null) {
    echo "  En producción .... no se pudo leer (HTTP $codigo" . ($error ? ", $error" : '') . ")\n\n";
    if ($codigo === 404) {
        echo "  El archivo todavía no existe en el servidor. Es lo normal la PRIMERA vez:\n";
        echo "  hasta que no se despliegue el commit que lo crea, no hay nada que pedir.\n\n";
    }
    exit(1);
}
echo "  En producción .... " . $f($remoto) . "\n\n";

$igual = ($vLocal['build'] ?? '') === ($remoto['build'] ?? '')
      && ($vLocal['fecha'] ?? '') === ($remoto['fecha'] ?? '');

if ($sinSellar) {
    echo "  ⚠ El último commit NO pasó por marcar_version.php: el sello local es\n";
    echo "    anterior al commit, así que esta comparación no dice nada del código\n";
    echo "    que acabas de subir. Sella, vuelve a hacer commit y push.\n\n";
    exit(1);
}

if ($igual) {
    echo "  ✓ COINCIDEN. El servidor tiene lo mismo que el repo.\n\n";
    exit(0);
}

$bl = (int) ($vLocal['build'] ?? 0);
$br = (int) ($remoto['build'] ?? 0);
echo "  ✗ NO COINCIDEN.\n";
echo $br < $bl
    ? "    Producción va " . ($bl - $br) . " compilación(es) por detrás: falta el Update From Remote.\n\n"
    : "    Producción va por DELANTE del repo local. ¿Te falta un git pull?\n\n";
exit(1);
