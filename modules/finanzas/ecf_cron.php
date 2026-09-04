<?php
/**
 * Cola de Comprobantes Fiscales Electrónicos para cron real.
 *
 * NO hace falta para que el sistema funcione: la cola también se despierta sola
 * cuando alguien usa el sistema (includes/layout/notificaciones.php). Este
 * archivo es para que los comprobantes se transmitan aunque nadie abra el
 * panel — el caso típico es la tienda cerrada con ventas del día sin acusar.
 *
 * Con la facturación electrónica encendida conviene ponerlo: un comprobante que
 * no se transmite es un problema fiscal, no una tarea que pueda esperar a que
 * alguien entre al sistema.
 *
 * En cPanel → Cron Jobs, cada 5 minutos:
 *   /usr/local/bin/php /home2/usuario/dominio/modules/finanzas/ecf_cron.php
 *
 * O por URL (requiere la clave):
 *   curl -s "https://tudominio.com/modules/finanzas/ecf_cron?key=LA_CLAVE"
 *
 * OJO CON LA «.php» EN LA URL. El .htaccess redirige con 301 a la forma sin
 * extensión, y ni curl (sin -L) ni los servicios de cron externos siguen
 * redirecciones: reciben el 301, lo dan por bueno y NO EJECUTAN NADA. Se ve
 * un cron «en verde» que en realidad no ha corrido nunca.
 *
 * La clave se define en config/config.local.php:
 *   define('ECF_CRON_KEY', 'una-cadena-larga-y-aleatoria');
 *
 * Sale con código 0 si todo fue bien y 1 si algún documento quedó fallido, para
 * que el cron pueda avisar.
 */
define('NEXOPOS_CRON', true);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$esCli = PHP_SAPI === 'cli';

if (!$esCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $clave = defined('ECF_CRON_KEY') ? (string) ECF_CRON_KEY : '';
    if ($clave === '') {
        http_response_code(403);
        exit("Falta ECF_CRON_KEY en config/config.local.php.\n"
           . "Sin clave, este endpoint no se expone por HTTP.\n");
    }
    if (!hash_equals($clave, (string) get('key'))) {
        http_response_code(403);
        exit("Clave incorrecta.\n");
    }
}

if (!ecfDisponible()) {
    exit("Facturación electrónica no disponible: falta aplicar database/migracion_ecf_p15.sql\n");
}
if (!ecfActivo()) {
    exit("La facturación electrónica está apagada. Nada que hacer.\n");
}
if (!ecfConfigurado()) {
    exit("Faltan las credenciales del proveedor (Finanzas → Facturación Electrónica).\n");
}

@set_time_limit(600);
ignore_user_abort(true);

$inicio  = microtime(true);
$totales = ['enviados' => 0, 'fallidos' => 0, 'consultados' => 0];

// Varias pasadas por corrida: cada una despacha un lote. Se para al quedarse sin
// trabajo o al acercarse a los cinco minutos, lo que ocurra primero — así dos
// ejecuciones del cron no se solapan.
for ($i = 0; $i < 20; $i++) {
    $r = ecfProcesarCola(25);
    foreach ($totales as $k => $_) $totales[$k] += $r[$k] ?? 0;

    if (($r['enviados'] + $r['fallidos'] + $r['consultados']) === 0) break;
    if (microtime(true) - $inicio > 300) break;
}

// Lo que quedó sin resolver, para que la salida del cron sirva de vigilancia.
$atascados = (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE estado = 'error'");
$enCola    = (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE estado = 'pendiente'");
$enProceso = (int) qVal("SELECT COUNT(*) FROM ecf_documentos WHERE estado = 'enviado'");

$seg = round(microtime(true) - $inicio, 2);
echo "e-CF · " . date('Y-m-d H:i:s') . " ({$seg}s)\n";
echo "  Transmitidos:        {$totales['enviados']}\n";
echo "  Fallidos:            {$totales['fallidos']}\n";
echo "  Estados consultados: {$totales['consultados']}\n";
echo "  ---\n";
echo "  Pendientes en cola:  {$enCola}\n";
echo "  En proceso (DGII):   {$enProceso}\n";
echo "  Con error:           {$atascados}\n";

if ($atascados > 0) {
    echo "\n  Hay {$atascados} comprobante(s) en error. Revísalos en\n"
       . "  Finanzas → Facturación Electrónica → Comprobantes.\n";
}

exit($totales['fallidos'] > 0 || $atascados > 0 ? 1 : 0);
