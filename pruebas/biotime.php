<?php
/**
 * ¿Se puede leer el ponche de Importers desde Nexo?
 *
 *   php pruebas/biotime.php
 *
 * No escribe nada: solo pregunta y enseña lo que contesta el reloj. Sirve para
 * tres cosas que hay que saber antes de programar la sincronización:
 *
 *   1. Si las credenciales entran.
 *   2. CON QUÉ CÓDIGO identifica el reloj a cada persona, que es lo que hay que
 *      casar con `empleados` de Nexo. Sin eso no hay integración posible.
 *   3. Si los turnos están configurados, porque de ahí sale la tardanza: Nexo
 *      no tiene horarios y no la puede deducir.
 *
 * Las credenciales NO van aquí. Se ponen en config/config.local.php:
 *
 *   define('BIOTIME_URL',     'https://importers.biotime.mx');
 *   define('BIOTIME_EMPRESA', 'importers');
 *   define('BIOTIME_EMAIL',   'la-cuenta@ejemplo.com');
 *   define('BIOTIME_CLAVE',   'la-contraseña');
 *
 * Devuelve 0 si el reloj contesta a todo y 1 si algo falla.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/includes/biotime.php';

$fallos = 0;
echo "\n  RELOJ BIOMÉTRICO — ¿contesta?\n";
echo "  " . str_repeat('─', 74) . "\n";

foreach (bioDiagnostico() as $p) {
    if (!$p['ok']) $fallos++;
    printf("  %s %-22s %s\n", $p['ok'] ? 'OK   ' : 'FALLA', $p['paso'], $p['detalle']);
}

if ($fallos > 0) {
    $auth = bioUltimaAuth();
    $credenciales = $auth && str_contains(mb_strtolower($auth['raw'] ?? ''), 'unable to log in');

    echo "\n  Con esto no se puede seguir.\n";
    if ($credenciales) {
        // Cuando el servidor dice esto, la forma de la petición era CORRECTA:
        // si sobrara o faltara «company», el error nombraría ese campo. Mandar
        // a revisar el formato aquí es mandar a arreglar lo que ya funciona.
        echo "  El reloj entendió la petición y rechazó las credenciales: la URL, la\n";
        echo "  empresa y el formato están bien, y lo que no cuadra es el correo o la\n";
        echo "  contraseña. Guárdala con:  php pruebas/biotime_clave.php\n\n";
    } else {
        echo "  Lo más común:\n";
        echo "  · Las constantes BIOTIME_* no están en config/config.local.php.\n";
        echo "  · La nube pide {email, password, company}, no {username, password}.\n";
        echo "    «company» es el subdominio: en importers.biotime.mx es «importers».\n";
        echo "  · La cuenta no tiene permiso de API. En BioTime se habilita por usuario.\n\n";
    }
    exit(1);
}

/* -------------------------------------------------------------------------
 *  Lo que de verdad hay que mirar: cómo se llama la gente en cada sitio.
 *
 *  Nexo identifica por `codigo` y por `cedula`. El reloj por `emp_code`. Si no
 *  coinciden, hay que decidir cómo casarlos ANTES de escribir nada.
 * ---------------------------------------------------------------------- */
echo "\n  ¿CASAN LAS PERSONAS?\n";
echo "  " . str_repeat('─', 74) . "\n";

$reloj = bioEmpleados();
if (!$reloj['ok']) { echo "  No se pudo leer el padrón: {$reloj['error']}\n\n"; exit(1); }

$porCodigo = $porCedula = 0;
$sinCasar  = [];
foreach ($reloj['filas'] as $r) {
    $code = trim((string) ($r['emp_code'] ?? ''));
    if ($code === '') continue;
    $nombre = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

    if (qVal("SELECT id FROM empleados WHERE codigo = ?", [$code]))      { $porCodigo++; continue; }
    if (qVal("SELECT id FROM empleados WHERE cedula = ?", [$code]))      { $porCedula++; continue; }
    $sinCasar[] = str_pad($code, 12) . ($nombre ?: '(sin nombre)');
}

$total = count($reloj['filas']);
printf("  en el reloj ................ %d persona(s)\n", $total);
printf("  en Nexo (activas) .......... %d\n", (int) qVal("SELECT COUNT(*) FROM empleados WHERE estado <> 'inactivo'"));
printf("  casan por `codigo` ......... %d\n", $porCodigo);
printf("  casan por `cedula` ......... %d\n", $porCedula);
printf("  SIN CASAR .................. %d\n", count($sinCasar));

if ($sinCasar) {
    echo "\n  Las que no casan (hasta 15):\n";
    foreach (array_slice($sinCasar, 0, 15) as $s) echo "    $s\n";
    echo "\n  Mientras haya gente sin casar, la sincronización no puede ser automática:\n";
    echo "  haría falta una columna de equivalencia en `empleados` y llenarla una vez.\n";
}

/* -------------------------------------------------------------------------
 *  Y una muestra de un día real, para ver qué campos trae
 * ---------------------------------------------------------------------- */
echo "\n  UN DÍA DE VERDAD\n";
echo "  " . str_repeat('─', 74) . "\n";
$hasta = date('Y-m-d');
$desde = date('Y-m-d', strtotime('-7 days'));

$rep = bioReporte($desde, $hasta, ['page_size' => 3]);
if ($rep['ok'] && $rep['filas']) {
    foreach (array_slice($rep['filas'], 0, 2) as $i => $f) {
        echo "\n  — fila " . ($i + 1) . " —\n";
        foreach ($f as $k => $v) {
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            printf("    %-22s %s\n", $k, mb_substr((string) $v, 0, 46));
        }
    }
} else {
    echo "  Sin días calculados entre $desde y $hasta.\n";
    echo "  Si hay ponches pero no días, es que falta asignar turnos en BioTime.\n";
}

echo "\n  El reloj contesta. Falta decidir cómo se casan las personas.\n\n";
exit(0);
