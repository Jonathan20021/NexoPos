<?php
/**
 * Motor de marketing para cron real.
 *
 * NO hace falta para que el sistema funcione: el motor también se despierta solo
 * cuando alguien usa el sistema (includes/layout/notificaciones.php). Este
 * archivo es para el caso en que se quiera envío puntual aunque nadie abra el
 * panel — por ejemplo, una campaña programada para las 6:00 a.m.
 *
 * En cPanel → Cron Jobs, cada 5 minutos:
 *   /usr/local/bin/php /home2/usuario/dominio/modules/marketing/cron.php
 *
 * O por URL (requiere la clave):
 *   curl -s "https://tudominio.com/modules/marketing/cron.php?key=LA_CLAVE"
 *
 * La clave se define en config/config.local.php:
 *   define('MKT_CRON_KEY', 'una-cadena-larga-y-aleatoria');
 */
define('NEXOPOS_CRON', true);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$esCli = PHP_SAPI === 'cli';

if (!$esCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $clave = defined('MKT_CRON_KEY') ? (string) MKT_CRON_KEY : '';
    if ($clave === '') {
        http_response_code(403);
        exit("Falta MKT_CRON_KEY en config/config.local.php.\n"
           . "Sin clave, este endpoint no se expone por HTTP.\n");
    }
    if (!hash_equals($clave, (string) get('key'))) {
        http_response_code(403);
        exit("Clave incorrecta.\n");
    }
}

if (!mkt_disponible()) {
    exit("Marketing no disponible: falta aplicar database/migracion_marketing_p9.sql\n");
}

@set_time_limit(300);
ignore_user_abort(true);

$inicio = microtime(true);
$totales = ['activadas' => 0, 'automatizaciones' => 0, 'encolados' => 0, 'enviados' => 0, 'fallidos' => 0];

// Varias pasadas por corrida: cada una despacha un lote. Se para al quedarse sin
// trabajo o al acercarse a los dos minutos, lo que ocurra primero.
for ($i = 0; $i < 20; $i++) {
    $r = mkt_tick();
    foreach ($totales as $k => $_) $totales[$k] += $r[$k] ?? 0;

    if (($r['enviados'] + $r['fallidos'] + $r['encolados'] + $r['activadas']) === 0) break;
    if (microtime(true) - $inicio > 120) break;
}

$seg = round(microtime(true) - $inicio, 2);
echo "Marketing · " . date('Y-m-d H:i:s') . " ({$seg}s)\n";
echo "  Campañas activadas:   {$totales['activadas']}\n";
echo "  Automatizaciones:     {$totales['automatizaciones']}\n";
echo "  Envíos encolados:     {$totales['encolados']}\n";
echo "  Correos enviados:     {$totales['enviados']}\n";
echo "  Correos fallidos:     {$totales['fallidos']}\n";
