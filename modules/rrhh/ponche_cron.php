<?php
/**
 * Trae el ponche del reloj biométrico a `asistencias`.
 *
 * En cPanel → Cron Jobs, una vez al día de madrugada:
 *   /usr/local/bin/php /home2/usuario/dominio/modules/rrhh/ponche_cron.php
 *
 * O por URL —que es como la llaman los servicios externos tipo cron-job.org—,
 * y entonces exige la clave:
 *   https://tudominio.com/modules/rrhh/ponche_cron?key=LA_CLAVE
 *
 * OJO CON LA «.php» EN LA URL. El .htaccess redirige con 301 a la forma sin
 * extensión, y ni curl (sin -L) ni los servicios de cron externos siguen
 * redirecciones: reciben el 301, lo dan por bueno y NO EJECUTAN NADA. Se ve
 * un cron «en verde» que en realidad no ha corrido nunca.
 *
 * La clave se define en config/config.local.php:
 *   define('PONCHE_CRON_KEY', 'una-cadena-larga-y-aleatoria');
 *
 * Por defecto trae los ÚLTIMOS TRES DÍAS, no solo ayer. Un aparato que estuvo
 * sin red sube sus marcas tarde, y una ventana de un día las perdería para
 * siempre: nadie vuelve a mirar el ponche de anteayer. Repetir días ya traídos
 * no cuesta nada porque la sincronización es idempotente.
 *
 *   --dias=7        cuántos días hacia atrás
 *   --desde=... --hasta=...   un rango exacto
 *   --simular       dice lo que haría sin escribir nada
 *
 * ---------------------------------------------------------------------------
 *  CÓDIGOS DE SALIDA Y ESTADO HTTP
 *
 *  Un vigilante externo —cron-job.org, UptimeRobot— solo mira el estado HTTP.
 *  Y «no se pudo hablar con el reloj» y «hay 43 personas sin emparejar» son
 *  cosas MUY distintas:
 *
 *    · fallo de verdad  → HTTP 500 y salida 1. El vigilante avisa.
 *    · algo que mirar   → HTTP 200 y salida 0. El trabajo se hizo; lo que falta
 *                         es de operaciones, y avisar cada hora de lo mismo
 *                         durante meses es la forma más rápida de que nadie
 *                         vuelva a leer un aviso.
 *
 *  Por CLI se mantienen los dos códigos separados (0, 1 y 2) por si alguien
 *  encadena comandos.
 */
define('NEXOPOS_CRON', true);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/biotime.php';

$esCli = PHP_SAPI === 'cli';

if (!$esCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $clave = defined('PONCHE_CRON_KEY') ? (string) PONCHE_CRON_KEY : '';
    if ($clave === '') {
        http_response_code(403);
        exit("Falta PONCHE_CRON_KEY en config/config.local.php.\n"
           . "Sin clave, este endpoint no se expone por HTTP.\n");
    }
    if (!hash_equals($clave, (string) get('key'))) {
        http_response_code(403);
        exit("Clave incorrecta.\n");
    }
}

/** Un argumento de la línea de comandos, o de la URL si vino por HTTP. */
function arg(string $nombre, ?string $porDefecto = null): ?string
{
    foreach ($_SERVER['argv'] ?? [] as $a) {
        if (str_starts_with($a, "--$nombre=")) return substr($a, strlen($nombre) + 3);
        if ($a === "--$nombre") return '1';
    }
    $v = $_GET[$nombre] ?? null;
    return is_string($v) && $v !== '' ? $v : $porDefecto;
}

/** Termina diciendo la verdad por los dos canales: el código y el estado HTTP. */
function terminar(int $codigo, ?int $http = null): never
{
    if (PHP_SAPI !== 'cli' && $http !== null) http_response_code($http);
    exit($codigo);
}

if (!bioConfigurado()) {
    echo "El reloj no está configurado: falta " . implode(' y ', bioFaltantes()) . ".\n";
    terminar(2, 500);
}

$dias  = max(1, min(90, (int) arg('dias', '3')));
$hasta = arg('hasta') ?: date('Y-m-d');
$desde = arg('desde') ?: date('Y-m-d', strtotime($hasta . ' -' . ($dias - 1) . ' days'));
$simular = arg('simular') !== null;

$t0 = microtime(true);
$p  = bioSincronizar($desde, $hasta, ['simular' => $simular]);
$ms = (int) round((microtime(true) - $t0) * 1000);

if ($p['error']) {
    echo "No se pudo traer el ponche: {$p['error']}\n";
    terminar(2, 500);
}

printf("Ponche %s → %s%s  (%d ms)\n", $desde, $hasta, $simular ? '  [SIMULACIÓN]' : '', $ms);
printf("  creadas %d · actualizadas %d · sin cambio %d\n",
    $p['creadas'], $p['actualizadas'], $p['sin_cambio']);

// Lo que hay que mirar. Se nombra, porque un contador no dice a quién le pasó.
$hayQueMirar = false;

if ($p['sin_emparejar']) {
    $hayQueMirar = true;
    echo "\n  SIN EMPAREJAR — sus marcas no entraron en ningún sitio:\n";
    foreach ($p['sin_emparejar'] as $code => $nombre) printf("    %-6s %s\n", $code, $nombre);
    echo "    Emparejalas en Recursos Humanos → Reloj biométrico.
";
}
if ($p['respetadas_manual']) {
    $hayQueMirar = true;
    echo "\n  CORREGIDAS A MANO — se dejó lo del humano, pero no coincide:\n";
    foreach ($p['respetadas_manual'] as $x) echo "    $x\n";
}
if ($p['incompletas']) {
    echo "\n  INCOMPLETAS — una sola marca, falta la salida:\n";
    foreach (array_slice($p['incompletas'], 0, 20) as $x) echo "    $x\n";
    if (count($p['incompletas']) > 20) printf("    ... y %d más\n", count($p['incompletas']) - 20);
}
if ($p['inactivos']) {
    $hayQueMirar = true;
    echo "\n  YA NO TRABAJAN AQUÍ pero siguen ponchando — hay que darles de baja en el reloj:\n";
    foreach ($p['inactivos'] as $code => $nombre) printf("    %-6s %s\n", $code, $nombre);
}
if ($p['fecha_mala'] > 0) {
    $hayQueMirar = true;
    printf("\n  %d fila(s) descartadas por fecha u hora ilegible.\n", $p['fecha_mala']);
}

if (!$simular) {
    audit('rrhh_asistencia', 'ponche', sprintf(
        'Ponche %s→%s: %d creada(s), %d actualizada(s), %d sin emparejar',
        $desde, $hasta, $p['creadas'], $p['actualizadas'], count($p['sin_emparejar'])
    ));
}

// El trabajo se hizo. Que haya gente sin emparejar es una tarea pendiente de
// alguien, no un error de esta ejecución: por HTTP se contesta 200 para que el
// vigilante no dé la alarma todos los días por lo mismo.
if ($hayQueMirar) echo "\n  (hay tareas pendientes, pero la sincronización se completó)\n";
terminar($hayQueMirar ? 1 : 0, 200);
