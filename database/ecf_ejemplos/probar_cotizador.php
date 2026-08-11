<?php
/**
 * Banco de pruebas del cotizador.
 *
 * REGISTRA VENTAS DE VERDAD al facturar: consume secuencias, descuenta
 * inventario y mueve caja. Por eso corre SOLO contra una base desechable cuyo
 * nombre termine en «_ecftest»; contra cualquier otra se niega a arrancar.
 *
 * Preparar el clon y ejecutar:
 *
 *   mysql -u root -e "DROP DATABASE IF EXISTS inventario_pos_ecftest; CREATE DATABASE inventario_pos_ecftest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
 *   mysqldump -u root --single-transaction inventario_pos | mysql -u root inventario_pos_ecftest
 *   php database/ecf_ejemplos/probar_cotizador.php
 *
 * Qué comprueba:
 *   · El descuento por línea y el global se componen en el orden correcto y el
 *     ITBIS se calcula sobre lo que de verdad se cobra.
 *   · Facturar solo una parte emite por esa parte, y ni un peso más.
 *   · El descuento global se prorratea: facturar la mitad no regala el doble.
 *   · Un concepto libre se factura como servicio y NO toca inventario.
 *   · El precio pactado manda aunque el de lista haya subido.
 *   · Lo que el cliente no se llevó queda registrado, no se pierde.
 */

define('DB_NAME', 'inventario_pos_ecftest');

if (!str_ends_with(DB_NAME, '_ecftest')) {
    fwrite(STDERR, "Esta prueba solo corre contra una base cuyo nombre termine en «_ecftest».\n");
    exit(2);
}

$raiz = dirname(__DIR__, 2);
$_SERVER['SCRIPT_NAME'] = '/cli.php';

set_error_handler(function ($no, $str) {
    if (str_contains($str, 'already defined')) return true;
    return false;
});
require_once $raiz . '/app/bootstrap.php';
restore_error_handler();

/* ---------------------------------------------------------------- utilidades */
$pruebas = 0; $fallos = 0;
function afirmar(string $nombre, bool $cond, string $detalle = ''): void
{
    global $pruebas, $fallos;
    $pruebas++;
    if ($cond) { echo "  ✓ $nombre\n"; return; }
    $fallos++;
    echo "  ✗ $nombre" . ($detalle ? "  ($detalle)" : '') . "\n";
}
function casi(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) < $eps; }

// Sesión de un usuario con permisos: cot_facturar() lee current_user().
$_SESSION['user'] = qOne("SELECT * FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
$_SESSION['user']['es_super'] = 1;
$_SESSION['sucursal_id'] = 1;

$tasaItbis = (float) setting('itbis_tasa', 18);

/* =========================================================================
 * 1. Totales: descuento de línea + descuento global
 * ====================================================================== */
echo "\nTotales con descuento por línea\n";

$t = cot_totales([
    ['producto_id' => 1, 'descripcion' => 'A', 'cantidad' => 10, 'precio_unitario' => 100, 'itbis_aplica' => 1, 'descuento_pct' => 10],
    ['producto_id' => 2, 'descripcion' => 'B', 'cantidad' => 2,  'precio_unitario' => 500, 'itbis_aplica' => 1],
], 0.0);

afirmar('El bruto es la suma a precio de lista', casi($t['bruto'], 2000.00), 'bruto=' . $t['bruto']);
afirmar('El 10% de la línea A se aplica sobre su base', casi($t['descuento_lineas'], 100.00), 'desc=' . $t['descuento_lineas']);
afirmar('El subtotal ya viene neto del descuento de línea', casi($t['subtotal'], 1900.00), 'sub=' . $t['subtotal']);
afirmar('El ITBIS se calcula sobre lo rebajado, no sobre lista',
    casi($t['itbis'], round(1900 * $tasaItbis / 100, 2)), 'itbis=' . $t['itbis']);
afirmar('El total cuadra', casi($t['total'], round(1900 + 1900 * $tasaItbis / 100, 2)), 'total=' . $t['total']);

// El monto explícito se respeta cuando no hay porcentaje.
$t2 = cot_totales([
    ['producto_id' => 1, 'descripcion' => 'A', 'cantidad' => 1, 'precio_unitario' => 1000, 'itbis_aplica' => 1, 'descuento_monto' => 250],
]);
afirmar('Sin porcentaje se usa el monto de descuento', casi($t2['subtotal'], 750.00), 'sub=' . $t2['subtotal']);

// Y nunca puede rebajar más que la propia línea.
$t3 = cot_totales([
    ['producto_id' => 1, 'descripcion' => 'A', 'cantidad' => 1, 'precio_unitario' => 100, 'itbis_aplica' => 0, 'descuento_monto' => 9999],
]);
afirmar('El descuento de línea no puede pasarse de la línea', casi($t3['subtotal'], 0.0), 'sub=' . $t3['subtotal']);

// Global encima del de línea.
$t4 = cot_totales([
    ['producto_id' => 1, 'descripcion' => 'A', 'cantidad' => 10, 'precio_unitario' => 100, 'itbis_aplica' => 0, 'descuento_pct' => 10],
], 400.0);
afirmar('El descuento global se aplica sobre el subtotal ya neto',
    casi($t4['total'], 500.00), 'total=' . $t4['total']);

/* =========================================================================
 * 2. Facturación parcial contra datos reales
 * ====================================================================== */
echo "\nFacturar solo lo que el cliente escogió\n";

// Producto con stock suficiente y un cliente cualquiera.
$prod = qOne("SELECT p.id, p.nombre, p.precio_venta FROM productos p WHERE p.activo=1 AND p.tipo='producto' ORDER BY p.id LIMIT 1");
$cli  = (int) qVal("SELECT id FROM clientes WHERE activo = 1 ORDER BY id LIMIT 1");
ajustarStock((int) $prod['id'], 1, 100, 'entrada', 'ajuste', null, 0, 'Carga para la prueba del cotizador');
$stock0 = stockActual((int) $prod['id'], 1);

$cotId = cot_guardar(
    ['cliente_id' => $cli, 'sucursal_id' => 1, 'descuento' => 0, 'validez_dias' => 15],
    [['producto_id' => (int) $prod['id'], 'descripcion' => $prod['nombre'],
      'cantidad' => 10, 'precio_unitario' => 200, 'itbis_aplica' => 1]]
);
$lineas = cot_lineas($cotId);
$det = (int) $lineas[0]['id'];

$r = cot_facturar($cotId, 1, [$det => 4]);
$venta = qOne("SELECT * FROM ventas WHERE id = ?", [(int) $r['id']]);

afirmar('Se factura por lo escogido, no por lo cotizado',
    casi((float) $venta['subtotal'], 800.00), 'subtotal=' . $venta['subtotal']);
afirmar('El inventario baja solo por lo facturado',
    casi(stockActual((int) $prod['id'], 1), $stock0 - 4), 'stock=' . stockActual((int) $prod['id'], 1));
afirmar('La cotización queda cerrada', qVal("SELECT estado FROM cotizaciones WHERE id=?", [$cotId]) === 'facturada');
afirmar('Queda registrado cuánto se facturó de la línea',
    casi((float) qVal("SELECT cantidad_facturada FROM cotizacion_detalles WHERE id=?", [$det]), 4.0));
afirmar('Y por tanto qué se descartó (6 de 10)',
    casi((float) qVal("SELECT cantidad - cantidad_facturada FROM cotizacion_detalles WHERE id=?", [$det]), 6.0));
afirmar('La factura apunta a la cotización y viceversa',
    (int) qVal("SELECT venta_id FROM cotizaciones WHERE id=?", [$cotId]) === (int) $r['id']);
afirmar('Se marca como parcial', !empty($r['parcial']));

// No se puede facturar más de lo cotizado.
$cot2 = cot_guardar(
    ['cliente_id' => $cli, 'sucursal_id' => 1],
    [['producto_id' => (int) $prod['id'], 'descripcion' => $prod['nombre'],
      'cantidad' => 3, 'precio_unitario' => 100, 'itbis_aplica' => 1]]
);
$det2 = (int) cot_lineas($cot2)[0]['id'];
$excedio = false;
try { cot_facturar($cot2, 1, [$det2 => 5]); } catch (Throwable $e) { $excedio = str_contains($e->getMessage(), 'solo tiene'); }
afirmar('Facturar más de lo cotizado se rechaza', $excedio);

/* =========================================================================
 * 3. El descuento global se prorratea
 * ====================================================================== */
echo "\nDescuento global a prorrata\n";

$cot3 = cot_guardar(
    ['cliente_id' => $cli, 'sucursal_id' => 1, 'descuento' => 200],
    [['producto_id' => (int) $prod['id'], 'descripcion' => $prod['nombre'],
      'cantidad' => 10, 'precio_unitario' => 100, 'itbis_aplica' => 1]]
);
$c3   = cot_obtener($cot3);
$det3 = (int) cot_lineas($cot3)[0]['id'];
$r3 = cot_facturar($cot3, 1, [$det3 => 5]);      // la mitad
$v3 = qOne("SELECT * FROM ventas WHERE id = ?", [(int) $r3['id']]);

afirmar('Facturar la mitad aplica la mitad del descuento global',
    casi((float) $v3['descuento'], 100.00), 'descuento=' . $v3['descuento']);
afirmar('Y la base facturada es la mitad de la cotizada',
    casi((float) $v3['subtotal'], 500.00), 'subtotal=' . $v3['subtotal']);
// La mitad de una cotización debe costar exactamente la mitad, impuesto incluido.
afirmar('El total es exactamente la mitad del cotizado',
    casi((float) $v3['total'], round((float) $c3['total'] / 2, 2), 0.02),
    'factura=' . $v3['total'] . ' cotización=' . $c3['total']);

/* -------------------------------------------------------------------------
 * 3.b El ITBIS de la cotización no puede separarse del de la factura
 * ---------------------------------------------------------------------- */
// El editor dejaba desmarcar el ITBIS de una línea, pero la venta lo calcula
// del producto: se cotizaba sin impuesto y se facturaba con él. Ahora el
// servidor lo resuelve del catálogo al guardar, y los dos totales coinciden.
$cot3b = cot_guardar(
    ['cliente_id' => $cli, 'sucursal_id' => 1],
    [['producto_id' => (int) $prod['id'], 'descripcion' => $prod['nombre'],
      'cantidad' => 4, 'precio_unitario' => 250, 'itbis_aplica' => 0]]   // ← mentira del navegador
);
$c3b = cot_obtener($cot3b);
$gravado = (int) qVal("SELECT itbis_aplica FROM productos WHERE id=?", [(int) $prod['id']]) === 1;
afirmar('El ITBIS se resuelve del catálogo, no de lo que mande el navegador',
    $gravado ? (float) $c3b['itbis'] > 0 : (float) $c3b['itbis'] == 0.0, 'itbis cotizado=' . $c3b['itbis']);

$r3b = cot_facturar($cot3b, 1);
$v3b = qOne("SELECT subtotal, itbis, total FROM ventas WHERE id = ?", [(int) $r3b['id']]);
afirmar('El total facturado es idéntico al cotizado',
    casi((float) $v3b['total'], (float) $c3b['total'], 0.02),
    'factura=' . $v3b['total'] . ' cotización=' . $c3b['total']);
afirmar('Y el ITBIS también',
    casi((float) $v3b['itbis'], (float) $c3b['itbis'], 0.02),
    'factura=' . $v3b['itbis'] . ' cotización=' . $c3b['itbis']);

/* =========================================================================
 * 4. Conceptos libres facturables como servicio
 * ====================================================================== */
echo "\nConceptos libres (servicios)\n";

$serv = qOne("SELECT id FROM productos WHERE tipo='servicio' AND activo=1 ORDER BY id LIMIT 1");
if (!$serv) {
    $sid = dbInsert('productos', [
        'codigo' => 'SRV-COT', 'nombre' => 'Servicios y conceptos', 'tipo' => 'servicio',
        'precio_venta' => 0, 'precio_compra' => 0, 'itbis_aplica' => 1, 'activo' => 1,
    ]);
    $serv = ['id' => $sid];
}
cot_guardarConfig(['producto_servicio_id' => (int) $serv['id'], 'validez_dias' => 15, 'prefijo' => 'COT',
                   'mostrar_itbis' => 1, 'mostrar_sku' => 1, 'mostrar_descuento' => 1]);
afirmar('El producto de servicio queda configurado', cot_productoServicio() !== null);

$stockAntes = stockActual((int) $prod['id'], 1);
$cot4 = cot_guardar(
    ['cliente_id' => $cli, 'sucursal_id' => 1],
    [
        ['producto_id' => (int) $prod['id'], 'descripcion' => $prod['nombre'], 'cantidad' => 1, 'precio_unitario' => 100, 'itbis_aplica' => 1],
        ['producto_id' => 0, 'descripcion' => 'Instalación y montaje en obra', 'cantidad' => 1, 'precio_unitario' => 8500, 'itbis_aplica' => 1, 'es_servicio' => 1],
    ]
);
$l4 = cot_lineas($cot4);
afirmar('La línea libre se guarda sin producto', $l4[1]['producto_id'] === null);

$r4 = cot_facturar($cot4, 1);
$dets = qAll("SELECT descripcion, producto_id FROM venta_detalles WHERE venta_id = ? ORDER BY id", [(int) $r4['id']]);
afirmar('La cotización con concepto libre YA se puede facturar', count($dets) === 2);
afirmar('En la factura se lee el concepto real, no «Servicios y conceptos»',
    $dets[1]['descripcion'] === 'Instalación y montaje en obra', $dets[1]['descripcion']);
afirmar('El concepto libre se enlaza al producto de servicio',
    (int) $dets[1]['producto_id'] === (int) $serv['id']);
afirmar('Un servicio no mueve inventario',
    casi(stockActual((int) $prod['id'], 1), $stockAntes - 1), 'stock=' . stockActual((int) $prod['id'], 1));

/* =========================================================================
 * 5. El precio pactado manda
 * ====================================================================== */
echo "\nEl precio cotizado se respeta\n";

$precioViejo = (float) qVal("SELECT precio_venta FROM productos WHERE id=?", [(int) $prod['id']]);
$cot5 = cot_guardar(
    ['cliente_id' => $cli, 'sucursal_id' => 1],
    [['producto_id' => (int) $prod['id'], 'descripcion' => $prod['nombre'],
      'cantidad' => 2, 'precio_unitario' => 150, 'itbis_aplica' => 0]]
);
q("UPDATE productos SET precio_venta = 9999 WHERE id = ?", [(int) $prod['id']]);   // sube la lista
$r5 = cot_facturar($cot5, 1);
$v5 = qOne("SELECT subtotal FROM ventas WHERE id = ?", [(int) $r5['id']]);
q("UPDATE productos SET precio_venta = ? WHERE id = ?", [$precioViejo, (int) $prod['id']]);

afirmar('Aunque suba el precio de lista, se cobra lo cotizado',
    casi((float) $v5['subtotal'], 300.00), 'subtotal=' . $v5['subtotal']);

/* =========================================================================
 * 6. Configuración y campos propios
 * ====================================================================== */
echo "\nPlantilla y campos propios\n";

cot_guardarConfig([
    'validez_dias' => 30, 'prefijo' => 'PRESU', 'condiciones' => 'Condiciones por defecto de la casa',
    'mostrar_itbis' => 1, 'mostrar_sku' => 0, 'mostrar_descuento' => 1,
    'producto_servicio_id' => (int) $serv['id'],
    'campos' => [['etiqueta' => 'Orden de compra'], ['etiqueta' => 'Proyecto']],
]);
$cfg = cot_config(true);
afirmar('La validez por defecto se guarda', (int) $cfg['validez_dias'] === 30);
afirmar('El prefijo se normaliza en mayúsculas', $cfg['prefijo'] === 'PRESU');
$campos = cot_campos();
afirmar('Los campos propios se definen', count($campos) === 2);
afirmar('La clave se deriva de la etiqueta sin acentos ni espacios',
    $campos[0]['clave'] === 'orden_de_compra', $campos[0]['clave']);

$cot6 = cot_guardar(
    ['cliente_id' => $cli, 'sucursal_id' => 1,
     'campos_extra' => ['orden_de_compra' => 'OC-4471', 'proyecto' => 'Torre Anacaona', 'inventado' => 'x']],
    [['producto_id' => (int) $prod['id'], 'descripcion' => $prod['nombre'], 'cantidad' => 1, 'precio_unitario' => 10, 'itbis_aplica' => 0]]
);
$c6 = cot_obtener($cot6);
$vals = cot_camposValores($c6);
afirmar('Los valores de los campos propios se guardan', ($vals['orden_de_compra'] ?? '') === 'OC-4471');
afirmar('Una clave que no está definida no se cuela', !isset($vals['inventado']));
afirmar('La numeración usa el prefijo configurado', str_starts_with($c6['numero'], 'PRESU-'), $c6['numero']);
afirmar('La validez por defecto se aplica al crear', (int) $c6['validez_dias'] === 30);
afirmar('Las condiciones por defecto se aplican al crear',
    $c6['condiciones'] === 'Condiciones por defecto de la casa');

/* ------------------------------------------------------------------ cierre */
echo "\n--------------------------------------------------------------------------\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, $fallos);
echo $fallos === 0
    ? "  ✓ El cotizador factura lo que el cliente se lleva, ni más ni menos.\n"
    : "  ✗ Hay fallos que revisar.\n";
exit($fallos === 0 ? 0 : 1);
