<?php
/**
 * Banco de pruebas de la carga masiva (catálogo, existencias y embarques).
 *
 * ESCRIBE DE VERDAD: crea productos, conteos y líneas de liquidación. Por eso
 * corre SOLO contra una base desechable cuyo nombre termine en «_ecftest»;
 * contra cualquier otra se niega a arrancar. Un descuido aquí ensuciaría el
 * catálogo del cliente, que es justo lo que este módulo existe para cuidar.
 *
 * Preparar el clon y ejecutar:
 *
 *   mysql -u root -e "DROP DATABASE IF EXISTS inventario_pos_ecftest; CREATE DATABASE inventario_pos_ecftest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
 *   mysqldump -u root --single-transaction inventario_pos | mysql -u root inventario_pos_ecftest
 *   php database/ecf_ejemplos/probar_importador.php
 *
 * Qué comprueba:
 *   · Los números y las fechas de una hoja de cálculo real.
 *   · El mapeo automático acierta con encabezados en español y en inglés.
 *   · La trampa de los dos índices únicos de `productos`: código de uno y
 *     código de barras de otro se RECHAZA en vez de fusionar dos artículos.
 *   · La carga de existencias NO escribe stock: deja un conteo en borrador.
 *   · Un SKU repetido en el packing list se agrupa y el costo se promedia
 *     ponderando por cantidad, que es lo que hace cuadrar el FOB.
 *   · La reversión se NIEGA cuando el conteo ya se aplicó o la liquidación ya
 *     no es editable, en vez de deshacer a medias.
 */

define('DB_NAME', 'inventario_pos_ecftest');

if (!str_ends_with(DB_NAME, '_ecftest')) {
    fwrite(STDERR, "Esta prueba solo corre contra una base cuyo nombre termine en «_ecftest».\n");
    exit(2);
}

$raiz = dirname(__DIR__, 2);
$_SERVER['SCRIPT_NAME'] = '/cli.php';
set_error_handler(fn($no, $str) => (bool) str_contains($str, 'already defined'));
require_once $raiz . '/app/bootstrap.php';
restore_error_handler();

$u = qOne("SELECT * FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
$_SESSION['user'] = $u;
$_SESSION['user']['es_super'] = 1;

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
/** Motivos de rechazo concatenados, para afirmar sobre ellos. */
function motivos(array $r): string { return implode(' | ', array_column($r['errores'], 'motivo')); }
/** Lo mismo con los avisos: la vista los pinta como ['fila'=>n,'motivo'=>…]. */
function avisos(array $r): string { return implode(' | ', array_column($r['avisos'], 'motivo')); }

if (!imp_masiva_disponible()) {
    fwrite(STDERR, "Falta la migración P19 en esta base. Aplica database/migracion_carga_masiva_p19.sql.\n");
    exit(2);
}

/* =========================================================================
 * 1. Lo que llega de una hoja de cálculo
 * ====================================================================== */
echo "\nNúmeros y fechas como los escribe Excel\n";

afirmar('«RD$ 1,234.56» es mil doscientos treinta y cuatro', casi(imp_num('RD$ 1,234.56'), 1234.56));
afirmar('«1.234,56» (Excel en español) también',            casi(imp_num('1.234,56'), 1234.56));
afirmar('«1,500» son mil quinientos, no uno con cinco',      casi(imp_num('1,500'), 1500.0));
afirmar('«1,50» es uno con cincuenta',                       casi(imp_num('1,50'), 1.5));
afirmar('«(500)» es negativo contable',                      casi(imp_num('(500)'), -500.0));
afirmar('La celda vacía es cero',                            casi(imp_num(''), 0.0));

afirmar('«03/04/2025» se lee 3 de abril, no 4 de marzo', imp_fecha('03/04/2025') === '2025-04-03');
afirmar('El serial 45000 de Excel es una fecha',         imp_fecha('45000') === '2023-03-15',
    'dio ' . var_export(imp_fecha('45000'), true));
afirmar('«2025-12-31» pasa tal cual',                    imp_fecha('2025-12-31') === '2025-12-31');
afirmar('Una fecha imposible devuelve null',             imp_fecha('31/02/2025') === null);

afirmar('«Sí», «X» y «1» son verdadero', imp_bool('Sí', false) && imp_bool('X', false) && imp_bool('1', false));
afirmar('«Exento» y «No» son falso',     !imp_bool('Exento', true) && !imp_bool('No', true));
afirmar('La celda vacía respeta el valor por defecto', imp_bool('', true) && !imp_bool('', false));

/* =========================================================================
 * 2. El mapeo automático
 * ====================================================================== */
echo "\nMapeo automático de columnas\n";

$cab = ['SKU', 'Descripción', 'Categoría', 'Marca', 'Precio de Venta', 'Costo', 'Código de Barras'];
$m = imp_automapear($cab, 'productos');
afirmar('«SKU» va al código',              ($m['codigo'] ?? -1) === 0);
afirmar('«Descripción» va al nombre',      ($m['nombre'] ?? -1) === 1);
afirmar('«Precio de Venta» al precio',     ($m['precio_venta'] ?? -1) === 4);
afirmar('«Costo» al precio de compra',     ($m['precio_compra'] ?? -1) === 5);
afirmar('«Código de Barras» a las barras', ($m['codigo_barras'] ?? -1) === 6);

$m2 = imp_automapear(['Item Code', 'Item Description', 'Qty', 'Unit Price', 'Net Weight'], 'embarque');
afirmar('Un packing list en inglés también se mapea',
    ($m2['producto'] ?? -1) === 0 && ($m2['cantidad'] ?? -1) === 2
    && ($m2['costo_moneda'] ?? -1) === 3 && ($m2['peso'] ?? -1) === 4,
    json_encode($m2));

afirmar('Ninguna columna se asigna a dos campos',
    count(array_unique($m)) === count($m));

/* =========================================================================
 * 3. Catálogo de productos
 * ====================================================================== */
echo "\nCatálogo de productos\n";

// Dos productos de referencia con los que chocar a propósito.
$refA = dbInsert('productos', ['codigo' => 'ZZ-REF-A', 'codigo_barras' => '7700000000011',
    'nombre' => 'Producto de referencia A', 'tipo' => 'producto', 'activo' => 1, 'precio_venta' => 100]);
$refB = dbInsert('productos', ['codigo' => 'ZZ-REF-B', 'codigo_barras' => '7700000000022',
    'nombre' => 'Producto de referencia B', 'tipo' => 'producto', 'activo' => 1, 'precio_venta' => 200]);
imp_olvidar_indice();

$mapaProd = ['codigo' => 0, 'nombre' => 1, 'codigo_barras' => 2, 'categoria' => 3, 'precio_venta' => 4, 'precio_compra' => 5];
$filas = [
    ['ZZ-NUEVO-1', 'Labial mate rojo',    '7700000000099', 'Maquillaje ZZ', '450.00', '210.00'],
    // Sin barras a propósito: así la fila del choque es la única que trae
    // 7700000000011 y llega a la comprobación de identidades cruzadas.
    ['ZZ-REF-A',   'Referencia A (nuevo nombre)', '', 'Maquillaje ZZ', '150.00', '80.00'],
    ['ZZ-REF-A',   'Repetido en el archivo', '', '', '10', '5'],
    ['ZZ-REF-B',   'Choque de identidades', '7700000000011', '', '10', '5'],
    ['',           '',                     '',              '', '', ''],
    ['ZZ-NUEVO-2', 'Base líquida 30ml',    '',              'Maquillaje ZZ', '900.00', '400.00'],
];
$an = imp_analizar('productos', $filas, $mapaProd, ['crear_catalogos' => true]);

afirmar('Reconoce el producto que ya existe por su código',
    $an['resumen']['existentes'] === 1, 'existentes=' . $an['resumen']['existentes']);
afirmar('Cuenta dos productos nuevos', $an['resumen']['nuevos'] === 2, 'nuevos=' . $an['resumen']['nuevos']);
afirmar('Rechaza el código repetido dentro del archivo',
    str_contains(motivos($an), 'ya viene en la fila 3') || str_contains(motivos($an), 'ya viene en la fila'));
afirmar('RECHAZA la fila cuyo código es de un producto y cuyas barras son de otro',
    str_contains(motivos($an), 'pertenecen a dos productos distintos'), motivos($an));
afirmar('Rechaza la fila sin nombre', str_contains(motivos($an), 'Sin nombre de producto'));
afirmar('Avisa de la categoría que va a crear',
    str_contains(avisos($an), 'categoría'), avisos($an));
afirmar('Todo aviso lleva la forma que la vista sabe pintar',
    array_reduce($an['avisos'], fn($c, $a) => $c && is_array($a) && isset($a['fila'], $a['motivo']), true));

$antes = (int) qVal("SELECT COUNT(*) FROM productos");
$lote1 = imp_ejecutar('productos', $an, ['crear_catalogos' => true, 'actualizar_existentes' => true], 'catalogo.xlsx');
$despues = (int) qVal("SELECT COUNT(*) FROM productos");

afirmar('Escribe exactamente los dos productos nuevos', $despues - $antes === 2, 'diferencia=' . ($despues - $antes));
afirmar('Creó la categoría que no existía',
    (int) qVal("SELECT COUNT(*) FROM categorias WHERE nombre = 'Maquillaje ZZ'") === 1);

$nuevo = qOne("SELECT * FROM productos WHERE codigo = 'ZZ-NUEVO-1'");
afirmar('El producto nuevo lleva la marca de su lote', (int) $nuevo['importacion_id'] === $lote1);
afirmar('Y su precio de venta',  casi((float) $nuevo['precio_venta'], 450.00));
afirmar('Y queda asociado a la categoría creada', (int) $nuevo['categoria_id'] > 0);

$sinBarras = qOne("SELECT codigo_barras FROM productos WHERE codigo = 'ZZ-NUEVO-2'");
afirmar('El código de barras vacío se guarda NULL, no cadena vacía',
    $sinBarras['codigo_barras'] === null, var_export($sinBarras['codigo_barras'], true));

$refAdespues = qOne("SELECT nombre, precio_venta FROM productos WHERE id = ?", [$refA]);
afirmar('Al actualizar NO se pisa el nombre que ya tenía',
    $refAdespues['nombre'] === 'Producto de referencia A', $refAdespues['nombre']);
afirmar('Pero sí se actualiza el precio que trae el archivo',
    casi((float) $refAdespues['precio_venta'], 150.00));

/* =========================================================================
 * 3b. La foto del producto
 * ====================================================================== */
echo "\nFoto del producto\n";

// Una imagen de verdad dentro de la carpeta permitida.
$dirFotos = $raiz . '/assets/uploads/productos';
@mkdir($dirFotos, 0775, true);
$fotoRel = 'assets/uploads/productos/zz_prueba_importador.png';
file_put_contents($raiz . '/' . $fotoRel, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

afirmar('Acepta una ruta válida dentro de assets/uploads',
    imp_ruta_imagen($fotoRel) === $fotoRel);
afirmar('Tolera la barra invertida de Windows y la barra inicial',
    imp_ruta_imagen('/' . str_replace('/', '\\', $fotoRel)) === $fotoRel);
afirmar('RECHAZA salir de la carpeta con «..»',
    imp_ruta_imagen('assets/uploads/../../config/config.local.php') === '');
afirmar('RECHAZA una ruta absoluta de Windows',
    imp_ruta_imagen('C:\\xampp\\htdocs\\proyecto-inventario-pos\\config\\config.php') === '');
afirmar('RECHAZA una ruta absoluta de Unix',      imp_ruta_imagen('/etc/passwd') === '');
afirmar('RECHAZA una URL',                        imp_ruta_imagen('https://ajeno.example/x.png') === '');
afirmar('RECHAZA fuera de assets/uploads',        imp_ruta_imagen('assets/img/logo.png') === '');
afirmar('RECHAZA una extensión que no es imagen', imp_ruta_imagen('assets/uploads/productos/x.php') === '');
afirmar('RECHAZA una foto que no existe',
    imp_ruta_imagen('assets/uploads/productos/no_existe_jamas.png') === '');

$anFoto = imp_analizar('productos', [
    ['ZZ-FOTO-1', 'Producto con foto', $fotoRel],
    ['ZZ-FOTO-2', 'Producto con foto inventada', 'assets/uploads/productos/fantasma.png'],
], ['codigo' => 0, 'nombre' => 1, 'imagen' => 2], []);
afirmar('La foto válida entra en el producto', ($anFoto['docs'][0]['imagen'] ?? '') === $fotoRel);
afirmar('La foto que no existe avisa y deja el producto sin imagen',
    ($anFoto['docs'][1]['imagen'] ?? 'x') === '' && str_contains(avisos($anFoto), 'No se encontró la foto'));

$loteFoto = imp_ejecutar('productos', $anFoto, [], 'confotos.csv');
afirmar('Se graba la ruta de la foto en el producto',
    qVal("SELECT imagen FROM productos WHERE codigo = 'ZZ-FOTO-1'") === $fotoRel);
afirmar('Y el que no tenía foto queda sin imagen, no con una ruta rota',
    (string) qVal("SELECT COALESCE(imagen,'') FROM productos WHERE codigo = 'ZZ-FOTO-2'") === '');
imp_revertir($loteFoto);
@unlink($raiz . '/' . $fotoRel);

/* =========================================================================
 * 4. Existencias: la regla que no se puede romper
 * ====================================================================== */
echo "\nExistencias del almacén\n";

$sucId = (int) qVal("SELECT id FROM sucursales WHERE activo = 1 ORDER BY id LIMIT 1");
$sucNom = (string) qVal("SELECT nombre FROM sucursales WHERE id = ?", [$sucId]);
q("DELETE FROM conteos WHERE sucursal_id = ? AND estado = 'abierto'", [$sucId]);
imp_olvidar_indice();

$stockAntes = (float) (qVal("SELECT cantidad FROM inventario_stock WHERE producto_id = ? AND sucursal_id = ?",
    [$refA, $sucId]) ?? 0);

$mapaEx = ['producto' => 0, 'cantidad' => 1, 'sucursal' => 2];
$filasEx = [
    ['ZZ-REF-A',      '40', $sucNom],
    ['ZZ-REF-A',      '10', $sucNom],          // repetido: se suma
    ['7700000000022', '25', $sucNom],          // por código de barras
    ['ZZ-NO-EXISTE',  '5',  $sucNom],
    ['ZZ-REF-B',      '-3', $sucNom],
];
$anEx = imp_analizar('existencias', $filasEx, $mapaEx, []);

afirmar('Un producto repetido en la misma sucursal suma sus cantidades',
    casi((float) $anEx['docs'][0]['lineas'][0]['stock_contado'], 50.0),
    'dio ' . ($anEx['docs'][0]['lineas'][0]['stock_contado'] ?? '?'));
afirmar('Encuentra el producto por su código de barras', count($anEx['docs'][0]['lineas']) === 2);
afirmar('Rechaza el producto que no está en el catálogo',
    str_contains(motivos($anEx), 'No existe el producto'));
afirmar('Rechaza una existencia negativa', str_contains(motivos($anEx), 'no puede ser negativa'));
afirmar('Trae el stock teórico de hoy para comparar',
    casi((float) $anEx['docs'][0]['lineas'][0]['stock_teorico'], $stockAntes));

$lote2 = imp_ejecutar('existencias', $anEx, [], 'almacen.xlsx');

$stockDespues = (float) (qVal("SELECT cantidad FROM inventario_stock WHERE producto_id = ? AND sucursal_id = ?",
    [$refA, $sucId]) ?? 0);
afirmar('LA REGLA: la carga NO escribió stock', casi($stockDespues, $stockAntes),
    "antes=$stockAntes despues=$stockDespues");
afirmar('Tampoco tocó el kardex',
    (int) qVal("SELECT COUNT(*) FROM movimientos_inventario WHERE referencia_tipo = 'importacion'") === 0);

$conteo = qOne("SELECT * FROM conteos WHERE importacion_id = ?", [$lote2]);
afirmar('Dejó un conteo en la sucursal del archivo', $conteo && (int) $conteo['sucursal_id'] === $sucId);
afirmar('Y lo dejó ABIERTO, para revisarlo antes de aplicar', $conteo['estado'] === 'abierto');
afirmar('Con sus dos líneas',
    (int) qVal("SELECT COUNT(*) FROM conteo_detalles WHERE conteo_id = ?", [(int) $conteo['id']]) === 2);
afirmar('El contado sale del archivo y el teórico del sistema',
    casi((float) qVal("SELECT stock_contado FROM conteo_detalles WHERE conteo_id = ? AND producto_id = ?",
        [(int) $conteo['id'], $refA]), 50.0));

$anEx2 = imp_analizar('existencias', [['ZZ-REF-A', '1', $sucNom]], $mapaEx, []);
afirmar('Con un conteo ya abierto en esa sucursal, se niega a cargar otro',
    str_contains(motivos($anEx2), 'ya tiene el conteo'), motivos($anEx2));

/* =========================================================================
 * 5. Packing list de un embarque
 * ====================================================================== */
echo "\nPacking list de una importación\n";

$provId = (int) (qVal("SELECT id FROM proveedores ORDER BY id LIMIT 1") ?: 0);
$liqId = dbInsert('liquidaciones', [
    'numero' => 'ZZ-LIQ-1', 'sucursal_id' => $sucId, 'proveedor_id' => $provId ?: null,
    'fecha' => date('Y-m-d'), 'tasa_cambio' => 60.0, 'prorrateo' => 'valor',
    'estado' => 'borrador', 'usuario_id' => (int) $u['id'],
]);

$mapaEmb = ['producto' => 0, 'descripcion' => 1, 'cantidad' => 2, 'costo_moneda' => 3];
$filasEmb = [
    ['ZZ-REF-A',   'Referencia A',  '100', '10.00'],
    ['ZZ-REF-A',   'Referencia A',  '100', '12.00'],   // mismo SKU, otro costo
    ['ZZ-IMPORT-1', 'Sombra nueva', '50',  '8.00'],    // no existe todavía
];
$anEmb = imp_analizar('embarque', $filasEmb, $mapaEmb, ['liquidacion_id' => $liqId, 'crear_productos' => true]);

afirmar('El SKU repetido se agrupa en una sola línea', count($anEmb['docs']) === 2,
    'líneas=' . count($anEmb['docs']));
$lineaA = null;
foreach ($anEmb['docs'] as $d) if ((int) ($d['producto_id'] ?? 0) === $refA) $lineaA = $d;
afirmar('Con la cantidad sumada', $lineaA && casi((float) $lineaA['cantidad'], 200.0));
afirmar('Y el costo promediado ponderando por cantidad (11.00, no 12.00)',
    $lineaA && casi((float) $lineaA['costo_moneda'], 11.00), 'dio ' . ($lineaA['costo_moneda'] ?? '?'));
afirmar('El FOB cuadra con la factura: 200×11 + 50×8 = 2,600',
    casi((float) $anEmb['resumen']['monto'], 2600.00), 'dio ' . $anEmb['resumen']['monto']);
afirmar('Avisa del producto que va a dar de alta', $anEmb['resumen']['nuevos'] === 1);

$lote3 = imp_ejecutar('embarque', $anEmb, ['liquidacion_id' => $liqId, 'crear_productos' => true], 'packing.xlsx');

afirmar('Escribió las dos líneas en la liquidación',
    (int) qVal("SELECT COUNT(*) FROM liquidacion_detalles WHERE liquidacion_id = ?", [$liqId]) === 2);
afirmar('Dio de alta el producto que llegaba por primera vez',
    (int) qVal("SELECT COUNT(*) FROM productos WHERE codigo = 'ZZ-IMPORT-1'") === 1);
afirmar('El costo en pesos se calculó con la tasa del embarque (11 × 60 = 660)',
    casi((float) qVal("SELECT costo_fob FROM liquidacion_detalles WHERE liquidacion_id = ? AND producto_id = ?",
        [$liqId, $refA]), 660.0));
afirmar('Y recalculó el FOB de la liquidación',
    casi((float) qVal("SELECT fob FROM liquidaciones WHERE id = ?", [$liqId]), 2600.0 * 60),
    'dio ' . qVal("SELECT fob FROM liquidaciones WHERE id = ?", [$liqId]));

// Un producto que exige lote no puede entrar sin él.
q("UPDATE productos SET controla_lote = 1 WHERE id = ?", [$refB]);
imp_olvidar_indice();
$anLote = imp_analizar('embarque', [['ZZ-REF-B', 'Referencia B', '10', '5']], $mapaEmb,
    ['liquidacion_id' => $liqId, 'crear_productos' => false]);
afirmar('Un producto que controla lote se rechaza si el archivo no lo trae',
    str_contains(motivos($anLote), 'controla lote'), motivos($anLote));
q("UPDATE productos SET controla_lote = 0 WHERE id = ?", [$refB]);

/* =========================================================================
 * 6. Reversión
 * ====================================================================== */
echo "\nReversión de un lote\n";

$r3 = imp_revertir($lote3);
afirmar('El embarque se revierte con la liquidación en borrador', $r3['lineas'] === 2);
afirmar('Quedó la liquidación sin líneas',
    (int) qVal("SELECT COUNT(*) FROM liquidacion_detalles WHERE liquidacion_id = ?", [$liqId]) === 0);
afirmar('Y se borró el producto que nació con el embarque y no dejó rastro',
    (int) qVal("SELECT COUNT(*) FROM productos WHERE codigo = 'ZZ-IMPORT-1'") === 0);
afirmar('El FOB de la liquidación volvió a cero',
    casi((float) qVal("SELECT fob FROM liquidaciones WHERE id = ?", [$liqId]), 0.0));

$r2 = imp_revertir($lote2);
afirmar('Las existencias se revierten borrando el conteo sin aplicar', $r2['conteos'] === 1);
afirmar('No quedan líneas de ese conteo',
    (int) qVal("SELECT COUNT(*) FROM conteo_detalles WHERE conteo_id = ?", [(int) $conteo['id']]) === 0);

// Un conteo ya aplicado NO se puede deshacer borrándolo.
$anEx3 = imp_analizar('existencias', [['ZZ-REF-A', '77', $sucNom]], $mapaEx, []);
$lote4 = imp_ejecutar('existencias', $anEx3, [], 'almacen2.xlsx');
q("UPDATE conteos SET estado = 'aplicado', aplicado_at = NOW() WHERE importacion_id = ?", [$lote4]);
$negado = '';
try { imp_revertir($lote4); } catch (Throwable $e) { $negado = $e->getMessage(); }
afirmar('SE NIEGA a revertir si el conteo ya se aplicó',
    str_contains($negado, 'ya se aplicaron'), $negado ?: 'no se negó');
afirmar('Y explica que se corrige con otro conteo, no borrando el rastro',
    str_contains($negado, 'conteo nuevo'), $negado);

// Una liquidación aplicada tampoco.
$liq2 = dbInsert('liquidaciones', [
    'numero' => 'ZZ-LIQ-2', 'sucursal_id' => $sucId, 'proveedor_id' => $provId ?: null,
    'fecha' => date('Y-m-d'), 'tasa_cambio' => 60.0, 'prorrateo' => 'valor',
    'estado' => 'borrador', 'usuario_id' => (int) $u['id'],
]);
$anEmb2 = imp_analizar('embarque', [['ZZ-REF-A', 'Referencia A', '5', '3']], $mapaEmb,
    ['liquidacion_id' => $liq2, 'crear_productos' => false]);
$lote5 = imp_ejecutar('embarque', $anEmb2, ['liquidacion_id' => $liq2], 'packing2.xlsx');
q("UPDATE liquidaciones SET estado = 'aplicada' WHERE id = ?", [$liq2]);
$negado2 = '';
try { imp_revertir($lote5); } catch (Throwable $e) { $negado2 = $e->getMessage(); }
afirmar('SE NIEGA a revertir si la liquidación ya se aplicó',
    str_contains($negado2, 'aplicada'), $negado2 ?: 'no se negó');
afirmar('Y remite a anular la liquidación, que sí sabe devolver stock y costo',
    str_contains($negado2, 'Anula la liquidación'), $negado2);

// El catálogo: se borra lo que no dejó rastro y se CONSERVA lo que sí.
// ZZ-NUEVO-1 se mete en el conteo ya aplicado para darle rastro; ZZ-NUEVO-2 se
// queda limpio. Al revertir, uno tiene que sobrevivir y el otro irse.
$idNuevo1 = (int) qVal("SELECT id FROM productos WHERE codigo = 'ZZ-NUEVO-1'");
$conteoAplicado = (int) qVal("SELECT id FROM conteos WHERE importacion_id = ?", [$lote4]);
dbInsert('conteo_detalles', ['conteo_id' => $conteoAplicado, 'producto_id' => $idNuevo1,
    'stock_teorico' => 0, 'stock_contado' => 3, 'costo_unitario' => 0]);

$r1 = imp_revertir($lote1);
afirmar('La carga de catálogo borra el producto que no dejó rastro',
    (int) qVal("SELECT COUNT(*) FROM productos WHERE codigo = 'ZZ-NUEVO-2'") === 0,
    'borrados=' . $r1['productos']);
afirmar('Y CONSERVA el que ya entró en un conteo: borrarlo dejaría el conteo huérfano',
    (int) qVal("SELECT COUNT(*) FROM productos WHERE codigo = 'ZZ-NUEVO-1'") === 1);
afirmar('El conservado se cuenta como tal', $r1['productos_conservados'] >= 1,
    'conservados=' . $r1['productos_conservados']);
afirmar('Ningún producto queda con la marca de un lote revertido',
    (int) qVal("SELECT COUNT(*) FROM productos WHERE importacion_id = ?", [$lote1]) === 0);
afirmar('Un lote ya revertido no se revierte dos veces',
    (function () use ($lote1) {
        try { imp_revertir($lote1); return false; } catch (Throwable $e) { return str_contains($e->getMessage(), 'ya se revirtió'); }
    })());

/* =========================================================================
 * 7. Los cinco tipos
 * ====================================================================== */
echo "\nDescriptor de tipos\n";

afirmar('Están los cinco tipos', count(imp_tipos()) === 5);
afirmar('Ninguno declara que escribe stock',
    count(array_filter(imp_tipos(), fn($t) => $t['escribe_stock'])) === 0);
afirmar('Un tipo inventado cae en «ventas», no revienta', imp_tipo_valido('borrar_todo') === 'ventas');
afirmar('Cada tipo tiene sus campos', array_reduce(array_keys(imp_tipos()),
    fn($c, $t) => $c && count(imp_campos($t)) > 0, true));
afirmar('Todos los tipos tienen al menos un campo obligatorio', array_reduce(array_keys(imp_tipos()),
    fn($c, $t) => $c && count(array_filter(imp_campos($t), fn($x) => $x['req'])) > 0, true));

echo "\n--------------------------------------------------------------------------\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, $fallos);
echo $fallos === 0
    ? "  ✓ La carga masiva escribe lo que dice y no escribe lo que no debe.\n"
    : "  ✗ Hay fallos que revisar.\n";
exit($fallos === 0 ? 0 : 1);
