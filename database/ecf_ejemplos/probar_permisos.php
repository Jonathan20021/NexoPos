<?php
/**
 * Guardián de los permisos.
 *
 *   php database/ecf_ejemplos/probar_permisos.php
 *
 * ============================================================================
 *  POR QUÉ EXISTE
 * ============================================================================
 *
 * Los permisos viven en DOS sitios y tienen que decir lo mismo:
 *
 *   · la tabla `permisos`, que es contra la que comprueba `can()`, y
 *   · `permission_catalog()`, que es lo que PINTA la pantalla de Roles.
 *
 * Cuando las migraciones P22–P24 metieron nueve permisos nuevos en la tabla
 * pero no en el catálogo, pasaron dos cosas y la segunda es grave:
 *
 *   1. No aparecían en la pantalla, así que no se podían conceder.
 *   2. Al guardar CUALQUIER rol, `roles.php` hace `DELETE` y reinserta solo lo
 *      que está en el catálogo — así que la primera edición de un rol **borraba
 *      esos nueve permisos sin avisar**.
 *
 * Esta prueba no deja que vuelva a pasar en silencio. No escribe nada.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pruebas = 0; $fallos = 0;
function afirmar(string $t, bool $ok, string $d = ''): void {
    global $pruebas, $fallos;
    $pruebas++;
    echo ($ok ? "  ✓ " : "  ✗ ") . $t . ($d ? "\n        " . $d : '') . "\n";
    if (!$ok) $fallos++;
}

/* ---------------------------------------------------------------------------
 *  1) El catálogo y la tabla dicen lo mismo
 * ------------------------------------------------------------------------ */
echo "\n=== Catálogo ↔ base de datos ===\n";
$cat = array_keys(permission_keys());
$bd  = qCol("SELECT clave FROM permisos");

$soloBD  = array_values(array_diff($bd, $cat));
$soloCat = array_values(array_diff($cat, $bd));

afirmar('No hay permisos en la BASE que falten en el catálogo',
    $soloBD === [],
    $soloBD ? 'INVISIBLES en Roles y SE BORRAN al guardar un rol: ' . implode(', ', $soloBD) : '');
afirmar('No hay permisos en el CATÁLOGO que falten en la base',
    $soloCat === [],
    $soloCat ? 'la pantalla los ofrece y no existen: ' . implode(', ', $soloCat) : '');
afirmar('Las dos listas tienen el mismo tamaño',
    count($cat) === count($bd), count($cat) . ' catálogo · ' . count($bd) . ' base');

/* ---------------------------------------------------------------------------
 *  2) Los módulos nuevos están donde tienen que estar
 * ------------------------------------------------------------------------ */
echo "\n=== Los módulos recientes aparecen en la pantalla de Roles ===\n";
foreach (['tss', 'prestamos', 'amonestaciones'] as $mod) {
    $enCat = array_filter($cat, fn($c) => str_starts_with($c, $mod . '.'));
    afirmar('El módulo «' . $mod . '» está en el catálogo',
        count($enCat) > 0, implode(', ', $enCat));
}

/* ---------------------------------------------------------------------------
 *  3) Ningún permiso se queda sin rol
 * ------------------------------------------------------------------------ */
echo "\n=== Todo permiso lo tiene al menos un rol ===\n";
$huerfanos = qCol(
    "SELECT p.clave FROM permisos p
      WHERE NOT EXISTS (SELECT 1 FROM rol_permisos rp
                         JOIN roles r ON r.id = rp.rol_id AND r.activo = 1 AND r.es_super = 0
                        WHERE rp.permiso_id = p.id) ORDER BY p.clave");
afirmar('Ningún permiso se queda huérfano',
    $huerfanos === [],
    $huerfanos ? 'solo los podría usar un super admin: ' . implode(', ', $huerfanos) : '');

/* ---------------------------------------------------------------------------
 *  4) Toda pantalla comprueba ALGO
 *
 *  La regla de la casa es que todo lleve permiso. Las excepciones existen
 *  —login, perfil propio, bajas de correo, cron— pero tienen que estar
 *  ESCRITAS aquí: si mañana alguien añade una pantalla sin guarda, esta prueba
 *  falla en vez de dejarla pasar.
 * ------------------------------------------------------------------------ */
echo "\n=== Toda pantalla comprueba permiso, sesión o clave de cron ===\n";
$exentas = [
    // Antes de iniciar sesión, por definición.
    'modules/auth/login.php'        => 'pantalla de acceso',
    'modules/auth/404.php'          => 'página de error',
    'modules/auth/verificar.php'    => 'segundo factor, todavía sin sesión',
    // Cosas del propio usuario: basta con estar dentro.
    'modules/auth/logout.php'       => 'cerrar la propia sesión',
    'modules/auth/perfil.php'       => 'los datos de uno mismo',
    'modules/dashboard/index.php'   => 'portada; cada tarjeta ya filtra',
    'modules/notificaciones/index.php'  => 'las notificaciones de uno mismo',
    'modules/notificaciones/accion.php' => 'idem',
    'modules/notificaciones/ir.php'     => 'idem',
    'modules/admin/cambiar_sucursal.php' => 'valida el acceso a la sucursal aparte',
    // El buscador filtra POR DENTRO, fuente por fuente. Ver probar_busqueda.php.
    'modules/busqueda/index.php'    => 'el motor filtra cada fuente con can()',
    'modules/busqueda/api.php'      => 'idem, y responde 401 sin sesión',
    // Enlaces que abre un cliente desde su correo: no tienen sesión.
    'modules/marketing/baja.php'    => 'baja de la lista desde el correo',
    'modules/marketing/t.php'       => 'rastreo de apertura y clic',
    // Cron: sin sesión, protegidos con clave compartida.
    'modules/marketing/cron.php'    => 'clave MKT_CRON_KEY',
    'modules/finanzas/ecf_cron.php' => 'clave ECF_CRON_KEY',
    // No son pantallas.
    'modules/crm/_crm.php'          => 'archivo incluido, no se sirve solo',
];

$sinGuarda = [];
$raiz = dirname(__DIR__, 2);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/modules'));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
    $src = (string) file_get_contents($f->getPathname());
    $tiene = strpos($src, 'require_perm(') !== false
          || strpos($src, 'is_logged_in()') !== false
          || strpos($src, 'require_login()') !== false;
    if (!$tiene && !isset($exentas[$rel])) $sinGuarda[] = $rel;
}
afirmar('Ninguna pantalla nueva se quedó sin guarda',
    $sinGuarda === [],
    $sinGuarda ? 'sin comprobar nada: ' . implode(', ', $sinGuarda)
               . "\n        Si alguna es pública a propósito, añádela a \$exentas con su motivo." : '');

// Las exentas tienen que seguir existiendo: una lista de excepciones que
// nombra archivos borrados deja de ser una lista, es un adorno.
$fantasmas = array_values(array_filter(array_keys($exentas), fn($r) => !is_file($raiz . '/' . $r)));
afirmar('La lista de exentas no nombra archivos que ya no existen',
    $fantasmas === [], $fantasmas ? implode(', ', $fantasmas) : '');

echo "\n--------------------------------------------------------------------------\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, $fallos);
echo $fallos === 0
    ? "  ✓ Catálogo y base dicen lo mismo, y toda pantalla comprueba algo.\n"
    : "  ✗ Hay fallos que revisar.\n";
exit($fallos === 0 ? 0 : 1);
