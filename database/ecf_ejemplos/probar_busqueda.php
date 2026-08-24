<?php
/**
 * Banco de pruebas del buscador global.
 *
 * La pregunta que contesta —y es de seguridad— es esta: **¿el buscador enseña
 * cosas que el usuario no puede ver en su módulo?**
 *
 *   php database/ecf_ejemplos/probar_busqueda.php
 *
 * Se simulan roles distintos cambiando `$_SESSION['permisos']`, que es de donde
 * lee `can()`, y se comprueba qué grupos devuelve `buscar_global()` con cada
 * uno. No escribe nada en la base.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pruebas = 0; $fallos = 0;
function afirmar(string $t, bool $ok, string $d = ''): void {
    global $pruebas, $fallos;
    $pruebas++;
    echo ($ok ? "  ✓ " : "  ✗ ") . $t . ($d ? "  ($d)" : '') . "\n";
    if (!$ok) $fallos++;
}

/** Se mete en la piel de un rol: sin es_super y con esta lista de permisos. */
function comoRol(array $permisos): void {
    $_SESSION['user'] = ['id' => 1, 'nombre' => 'Prueba', 'sucursal_id' => null, 'es_super' => 0];
    $_SESSION['permisos'] = $permisos;
}
/** Nombres de los grupos que devuelve una búsqueda. */
function gruposDe(string $q): array {
    return array_map(fn($g) => $g['grupo'], buscar_global($q));
}

/* ---------------------------------------------------------------------------
 *  1) El texto del usuario no puede romper el LIKE
 * ------------------------------------------------------------------------ */
echo "\n=== Comodines de LIKE ===\n";
afirmar('El % se escapa', buscar_like('50%') === '50\\%');
afirmar('El _ se escapa', buscar_like('SKU_1') === 'SKU\\_1');
afirmar('La contrabarra se escapa primero', buscar_like('a\\b') === 'a\\\\b');

/* ---------------------------------------------------------------------------
 *  2) Reconocer un importe
 * ------------------------------------------------------------------------ */
echo "\n=== Búsqueda por importe ===\n";
afirmar('1180 es un importe', buscar_importe('1180') === 1180.0);
afirmar('Con separadores también', buscar_importe('1,180.00') === 1180.0);
afirmar('Con el signo de pesos también', buscar_importe('RD$ 1180') === 1180.0);
afirmar('Un número de dos cifras NO se trata como importe', buscar_importe('12') === null,
    'sería ruido: casi siempre es parte de un código');
afirmar('Un código no es un importe', buscar_importe('VTA-000012') === null);
afirmar('Una cédula no es un importe', buscar_importe('402-3767746-9') === null);

/* ---------------------------------------------------------------------------
 *  3) LO IMPORTANTE: cada fuente respeta su permiso
 * ------------------------------------------------------------------------ */
echo "\n=== Un super administrador lo ve todo ===\n";
$_SESSION['user'] = ['id' => 1, 'nombre' => 'Super', 'sucursal_id' => null, 'es_super' => 1];
$_SESSION['permisos'] = [];
$todo = gruposDe('ar');   // 2 caracteres: con 1 no se consulta la base
afirmar('Ve varios grupos a la vez', count($todo) >= 3, implode(' · ', $todo));

echo "\n=== Un rol SIN permisos no ve NADA de la base ===\n";
comoRol([]);
$nada = gruposDe('ar');
$soloNavegacion = array_values(array_diff($nada, ['Ir a']));
afirmar('No devuelve ningún grupo de datos', $soloNavegacion === [],
    $soloNavegacion ? 'SE FILTRÓ: ' . implode(', ', $soloNavegacion) : 'solo navegación, y esa también va filtrada');

echo "\n=== Cada permiso abre SOLO su grupo ===\n";
$casos = [
    'productos.ver'       => 'Productos',
    'clientes.ver'        => 'Clientes',
    'ventas.ver'          => 'Ventas',
    'proveedores.ver'     => 'Proveedores',
    'compras.ver'         => 'Compras',
    'rrhh_empleados.ver'  => 'Empleados',
    'crm.ver'             => 'Oportunidades',
    'prestamos.ver'       => 'Préstamos',
    'amonestaciones.ver'  => 'Amonestaciones',
    'pedidos.ver'         => 'Pedidos',
    'cotizaciones.ver'    => 'Cotizaciones',
];
foreach ($casos as $permiso => $grupo) {
    comoRol([$permiso]);
    // Se busca algo muy común para maximizar la posibilidad de que haya datos.
    $conPermiso = array_merge(gruposDe('ar'), gruposDe('in'), gruposDe('00'), gruposDe('la'));
    comoRol([]);
    $sinPermiso = array_merge(gruposDe('ar'), gruposDe('in'), gruposDe('00'), gruposDe('la'));
    afirmar("El grupo «" . $grupo . "» NO aparece sin " . $permiso, !in_array($grupo, $sinPermiso, true));
    // Si hay datos, con el permiso sí debe salir. Si no los hay, no se puede
    // afirmar nada y se dice, en vez de dar por buena una prueba vacía.
    if (in_array($grupo, $conPermiso, true)) {
        afirmar("El grupo «" . $grupo . "» SÍ aparece con " . $permiso, true);
    } else {
        echo "  · «" . $grupo . "» no se pudo comprobar en positivo: no hay datos que casen\n";
    }
}

echo "\n=== El cajero no ve la nómina ni el expediente ===\n";
comoRol(['pos.ver', 'ventas.ver', 'clientes.ver', 'cotizaciones.ver']);
$cajero = array_merge(gruposDe('ar'), gruposDe('in'), gruposDe('00'), gruposDe('la'), gruposDe('1'));
foreach (['Empleados', 'Préstamos', 'Amonestaciones', 'Compras', 'Proveedores'] as $prohibido) {
    afirmar("Un cajero NO ve «" . $prohibido . "»", !in_array($prohibido, $cajero, true));
}

echo "\n=== La navegación también se filtra ===\n";
comoRol([]);
$nav = buscar_navegacion('nomina');
afirmar('Sin permisos, «nómina» no aparece como atajo', $nav === [],
    $nav ? 'se coló: ' . $nav[0]['titulo'] : '');
comoRol(['rrhh_nomina.ver']);
$nav2 = buscar_navegacion('nomina');
afirmar('Con rrhh_nomina.ver sí aparece', count($nav2) > 0);

echo "\n=== Los accesos rápidos del panel vacío, igual ===\n";
comoRol([]);
$sinNada = array_column(buscar_atajos(), 'titulo');
// «Notificaciones» es el único legítimamente abierto: su pantalla solo pide
// estar dentro del sistema, no un permiso. El resto sí tiene que desaparecer.
afirmar('Sin permisos solo queda el que no exige ninguno',
    $sinNada === ['Notificaciones'], implode(', ', $sinNada) ?: 'ninguno');
foreach (['Nueva venta', 'Productos', 'Clientes', 'Reportes DGII'] as $cerrado) {
    afirmar('Sin permisos NO se ofrece «' . $cerrado . '»', !in_array($cerrado, $sinNada, true));
}
comoRol(['pos.vender']);
$at = array_column(buscar_atajos(), 'titulo');
afirmar('Con pos.vender aparece «Nueva venta»', in_array('Nueva venta', $at, true), implode(', ', $at));

echo "\n=== Buscar sin tildes encuentra lo que las lleva ===\n";
// strtr con dos cadenas trabaja byte a byte y destrozaba el UTF-8: «Nómina»
// se convertía en «nnimina» y no había forma de encontrarla.
afirmar('Nómina se normaliza bien', buscar_normalizar('Nómina') === 'nomina');
afirmar('Dirección se normaliza bien', buscar_normalizar('Dirección') === 'direccion');
afirmar('Facturación Electrónica se normaliza bien',
    buscar_normalizar('Facturación Electrónica') === 'facturacion electronica');
afirmar('La eñe también', buscar_normalizar('Año') === 'ano');
comoRol(['rrhh_nomina.ver']);
afirmar('Escribiendo «nomina» sin tilde se encuentra la pantalla',
    in_array('Nómina', array_column(buscar_navegacion('nomina'), 'titulo'), true));

/* ---------------------------------------------------------------------------
 *  4) El mínimo de caracteres
 * ------------------------------------------------------------------------ */
echo "\n=== Umbral de consulta ===\n";
$_SESSION['user'] = ['id' => 1, 'nombre' => 'Super', 'sucursal_id' => null, 'es_super' => 1];
$g1 = buscar_global('a');
$soloNav = array_filter($g1, fn($g) => $g['grupo'] !== 'Ir a');
afirmar('Con 1 carácter no se consulta la base', $soloNav === [],
    'la navegación sí responde, que es local');
afirmar('Con 2 ya se consulta', is_array(buscar_global('ab')));

echo "\n--------------------------------------------------------------------------\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, $fallos);
echo $fallos === 0
    ? "  ✓ El buscador no enseña nada que el usuario no pueda ver en su módulo.\n"
    : "  ✗ Hay fallos que revisar.\n";
exit($fallos === 0 ? 0 : 1);
