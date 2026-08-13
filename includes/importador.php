<?php
/**
 * Carga masiva desde Excel o CSV (área de Dirección).
 *
 * La CEO llega con un año entero de operaciones en un Excel del sistema
 * anterior, con el catálogo de una importadora multimarca y con el packing list
 * de un contenedor. Este módulo lo mete en NexoPOS sin romper nada de lo que ya
 * está funcionando.
 *
 * CINCO TIPOS
 *
 *   clientes ...... el padrón de clientes del sistema viejo.
 *   ventas ........ un año de facturación histórica.
 *   productos ..... el catálogo. Miles de SKU que nadie va a teclear.
 *   existencias ... lo que hay en el almacén. NO escribe stock: ver la regla 5.
 *   embarque ...... el packing list de una importación, a una liquidación.
 *
 * CINCO REGLAS QUE DEFINEN EL COMPORTAMIENTO
 *
 * 1. **Una venta histórica no mueve inventario.** El stock de hoy ya refleja la
 *    realidad del almacén; descontar de nuevo un año de ventas lo dejaría en
 *    negativo y sin sentido.
 *
 * 2. **No consume NCF.** Esos comprobantes ya se emitieron en el sistema viejo.
 *    Si el archivo trae el NCF, se guarda tal cual; si no, la venta queda sin él.
 *
 * 3. **No genera movimientos de caja ni de cuentas por cobrar.** Duplicaría el
 *    flujo de efectivo de un año ya cerrado y descuadraría los bancos. Sí
 *    alimenta ventas, márgenes y comparativos, que es para lo que se carga.
 *
 * 4. **Todo lo cargado lleva la marca de su lote** (`importacion_id`). Un
 *    archivo mal mapeado se revierte con un botón en vez de restaurando un
 *    respaldo completo. Es la diferencia entre un error y un desastre.
 *
 * 5. **La carga de existencias NO escribe en `inventario_stock`.** Genera un
 *    conteo físico en borrador por sucursal y deja que se aplique por el camino
 *    de siempre. Un UPDATE directo dejaría el almacén con cantidades que no
 *    tienen origen: el kardex no cuadraría con la existencia, el costeo mentiría
 *    y una auditoría no podría explicar de dónde salió una unidad.
 *
 * LA TRAMPA DE LOS DOS ÍNDICES ÚNICOS
 *
 * `productos` es única por `codigo` Y por `codigo_barras`, igual que `empleados`
 * lo es por `codigo` y `cedula`. Con dos únicos, un `INSERT ... ON DUPLICATE KEY
 * UPDATE` no falla cuando choca: ACTUALIZA la fila que estorba. Así se perdió un
 * empleado entero en la carga del padrón, y solo lo vio una auditoría que releía
 * el Excel fila por fila. Aquí se busca primero y se decide después, y una fila
 * cuyo código pertenece a un producto y cuyo código de barras pertenece a OTRO
 * se rechaza en vez de fusionar dos artículos distintos.
 *
 * Ver docs/TIENDAS-Y-DIRECCION.md.
 */

/** ¿Está aplicada la migración P16? */
function imp_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'importaciones'"
        );
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** ¿Está aplicada la P19, la que abre catálogo, existencias y embarques? */
function imp_masiva_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = imp_disponible() && (bool) qVal(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND COLUMN_NAME = 'importacion_id'"
        );
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Carpeta privada donde reposa el archivo entre la vista previa y la carga. */
function imp_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/importaciones';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/**
 * Qué es cada tipo, en un solo sitio.
 *
 * La pantalla resolvía todo con ternarios de dos ramas —«clientes o ventas»— y
 * había una docena repartidos por la vista. Con cinco tipos eso no escala: cada
 * uno nuevo obliga a encontrarlos todos, y el que se olvide sale mal sin avisar.
 *
 *   crea ......... qué escribe, para el resumen y el aviso de reversión.
 *   sucursal ..... 'req' la exige, 'opt' la acepta como respaldo, null la ignora.
 *   destino ...... el tipo necesita un documento destino ya creado.
 *   escribe_stock  ninguno lo hace. Está escrito para que se note si algún día
 *                  alguien añade uno que sí, y tenga que justificarlo.
 */
function imp_tipos(): array
{
    return [
        'ventas' => [
            'etiqueta' => 'Ventas históricas',
            'ayuda'    => 'Un año de facturación del sistema anterior. No mueve inventario, no consume NCF y no toca la caja.',
            'icono'    => 'receipt',
            'crea'     => 'venta(s)',
            'sucursal' => 'req',
            'destino'  => null,
            'escribe_stock' => false,
        ],
        'clientes' => [
            'etiqueta' => 'Clientes',
            'ayuda'    => 'El padrón de clientes. Coteja por RNC o cédula antes de crear, para no duplicar a quien ya está.',
            'icono'    => 'users',
            'crea'     => 'cliente(s)',
            'sucursal' => null,
            'destino'  => null,
            'escribe_stock' => false,
        ],
        'productos' => [
            'etiqueta' => 'Catálogo de productos',
            'ayuda'    => 'Miles de SKU de una vez. Coteja por código y por código de barras, y crea las categorías, marcas y unidades que falten.',
            'icono'    => 'package',
            'crea'     => 'producto(s)',
            'sucursal' => null,
            'destino'  => null,
            'escribe_stock' => false,
        ],
        'existencias' => [
            'etiqueta' => 'Existencias del almacén',
            'ayuda'    => 'No escribe el stock: deja un conteo físico en borrador por sucursal para revisarlo y aplicarlo, y que el ajuste entre al kardex con su motivo.',
            'icono'    => 'clipboard',
            'crea'     => 'conteo(s) en borrador',
            'sucursal' => 'opt',
            'destino'  => null,
            'escribe_stock' => false,
        ],
        'embarque' => [
            'etiqueta' => 'Packing list de una importación',
            'ayuda'    => 'Vuelca las líneas del embarque en una liquidación abierta. Puede dar de alta los productos que llegan por primera vez.',
            'icono'    => 'truck',
            'crea'     => 'línea(s) de liquidación',
            'sucursal' => null,
            'destino'  => 'liquidacion',
            'escribe_stock' => false,
        ],
    ];
}

/** Un tipo válido, o 'ventas'. Nunca confiar en lo que llega por POST. */
function imp_tipo_valido($t): string
{
    $t = is_string($t) ? $t : '';
    return isset(imp_tipos()[$t]) ? $t : 'ventas';
}

/** Metadatos de un tipo. */
function imp_tipo(string $t): array
{
    return imp_tipos()[imp_tipo_valido($t)];
}

/* ============================================================
 *  Lectura del archivo
 * ============================================================ */

/**
 * Recibe el archivo subido y lo deja en la carpeta privada.
 * Devuelve ['ok'=>bool, 'path'=>string, 'nombre'=>string, 'error'=>string].
 */
function imp_guardar_archivo(string $campo): array
{
    $f = $_FILES[$campo] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Selecciona un archivo.'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'El archivo no se pudo subir (puede superar el límite del servidor).'];
    }
    if ($f['size'] > 25 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'El archivo supera los 25 MB. Divídelo por trimestres.'];
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'xlsx', 'xls'], true)) {
        return ['ok' => false, 'error' => 'Formato no admitido. Usa CSV o Excel (.xlsx).'];
    }
    if (in_array($ext, ['xlsx', 'xls'], true) && !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        return ['ok' => false, 'error' => 'Este servidor no puede leer Excel. Guarda el archivo como CSV.'];
    }

    $destino = imp_dir() . '/imp_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $destino)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.'];
    }
    return ['ok' => true, 'path' => $destino, 'nombre' => (string) $f['name'], 'error' => ''];
}

/**
 * Lee el archivo y devuelve ['headers' => [...], 'filas' => [[...]], 'total' => n].
 *
 * `$limite` acota las filas devueltas (vista previa) pero `total` siempre trae
 * el conteo real: la CEO tiene que ver «12.480 filas» antes de decidir.
 */
function imp_leer(string $path, int $limite = 0): array
{
    if (!is_file($path)) throw new RuntimeException('El archivo de la carga ya no está disponible. Vuelve a subirlo.');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['xlsx', 'xls'], true)
        ? imp_leer_excel($path, $limite)
        : imp_leer_csv($path, $limite);
}

/**
 * CSV con las tres trampas de siempre resueltas: el BOM de Excel, el separador
 * (Excel en español escribe con `;`) y el acentuado en Latin-1.
 */
function imp_leer_csv(string $path, int $limite = 0): array
{
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('No se pudo abrir el archivo.');

    $primera = fgets($fh);
    if ($primera === false) { fclose($fh); return ['headers' => [], 'filas' => [], 'total' => 0]; }
    // BOM UTF-8: sin quitarlo, la primera columna se llama «\xEF\xBB\xBFFecha»
    // y el mapeo automático nunca la reconoce.
    $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera);

    // El separador es el que más veces aparece fuera de comillas en la cabecera.
    $sep = ',';
    $mejor = 0;
    foreach ([',', ';', "\t", '|'] as $cand) {
        $n = count(str_getcsv($primera, $cand));
        if ($n > $mejor) { $mejor = $n; $sep = $cand; }
    }

    $normalizar = static function (array $fila): array {
        return array_map(static function ($c) {
            $c = (string) $c;
            // Si no es UTF-8 válido, viene de Excel en Latin-1.
            if (!mb_check_encoding($c, 'UTF-8')) $c = mb_convert_encoding($c, 'UTF-8', 'ISO-8859-1');
            return trim($c);
        }, $fila);
    };

    $headers = $normalizar(str_getcsv($primera, $sep));
    $filas = [];
    $total = 0;
    while (($fila = fgetcsv($fh, 0, $sep)) !== false) {
        // Línea totalmente vacía: Excel las genera al final por docenas.
        if ($fila === [null] || (count($fila) === 1 && trim((string) $fila[0]) === '')) continue;
        $total++;
        if ($limite > 0 && count($filas) >= $limite) continue;
        $filas[] = $normalizar($fila);
    }
    fclose($fh);
    return ['headers' => $headers, 'filas' => $filas, 'total' => $total];
}

/** Excel vía PhpSpreadsheet. Las fechas llegan como serial y se resuelven aquí. */
function imp_leer_excel(string $path, int $limite = 0): array
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new RuntimeException('Este servidor no puede leer Excel. Guarda el archivo como CSV.');
    }
    $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
    $lector->setReadDataOnly(true);
    $hoja = $lector->load($path)->getActiveSheet();

    $headers = []; $filas = []; $total = 0;
    foreach ($hoja->getRowIterator() as $i => $fila) {
        $celdas = [];
        foreach ($fila->getCellIterator() as $c) {
            $v = $c->getValue();
            // Una fecha de Excel es un número; sin esto llegaría «45231».
            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($c) && is_numeric($v)) {
                $v = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
            }
            $celdas[] = is_string($v) ? trim($v) : $v;
        }
        if ($i === 1) { $headers = array_map(fn($h) => (string) $h, $celdas); continue; }
        if (!array_filter($celdas, fn($c) => $c !== null && $c !== '')) continue;
        $total++;
        if ($limite > 0 && count($filas) >= $limite) continue;
        $filas[] = $celdas;
    }
    return ['headers' => $headers, 'filas' => $filas, 'total' => $total];
}

/* ============================================================
 *  Campos y mapeo
 * ============================================================ */

/**
 * Qué se puede cargar en cada tipo y con qué nombres suele venir.
 * Los alias alimentan el mapeo automático: casi ningún archivo trae las
 * columnas con el nombre exacto que usaría el sistema.
 */
function imp_campos(string $tipo): array
{
    if ($tipo === 'productos') {
        return [
            'nombre'        => ['label' => 'Nombre del producto', 'req' => true,  'alias' => ['nombre', 'producto', 'descripcion', 'descripción', 'articulo', 'artículo', 'item', 'description', 'detalle']],
            'codigo'        => ['label' => 'Código / SKU',        'req' => false, 'alias' => ['codigo', 'código', 'sku', 'ref', 'referencia', 'code', 'no', 'item code', 'codigo producto', 'clave']],
            'codigo_barras' => ['label' => 'Código de barras',    'req' => false, 'alias' => ['codigo de barras', 'código de barras', 'barras', 'barcode', 'ean', 'upc', 'ean13', 'gtin']],
            'categoria'     => ['label' => 'Categoría',           'req' => false, 'alias' => ['categoria', 'categoría', 'category', 'familia', 'rubro', 'linea', 'línea', 'grupo']],
            'marca'         => ['label' => 'Marca',               'req' => false, 'alias' => ['marca', 'brand', 'fabricante marca']],
            'unidad'        => ['label' => 'Unidad de medida',    'req' => false, 'alias' => ['unidad', 'um', 'unidad de medida', 'medida', 'uom', 'presentacion', 'presentación']],
            'tienda'        => ['label' => 'Tienda (marca comercial)', 'req' => false, 'alias' => ['tienda', 'marca comercial', 'store', 'concepto']],
            'precio_compra' => ['label' => 'Costo / precio de compra', 'req' => false, 'alias' => ['costo', 'precio compra', 'precio de compra', 'cost', 'costo unitario', 'fob', 'compra']],
            'precio_venta'  => ['label' => 'Precio de venta',     'req' => false, 'alias' => ['precio', 'precio venta', 'precio de venta', 'pvp', 'price', 'venta', 'precio publico', 'precio público']],
            'itbis_aplica'  => ['label' => 'Aplica ITBIS',        'req' => false, 'alias' => ['itbis', 'itbis aplica', 'gravado', 'impuesto', 'tax', 'iva']],
            'stock_minimo'  => ['label' => 'Stock mínimo',        'req' => false, 'alias' => ['stock minimo', 'stock mínimo', 'minimo', 'mínimo', 'min', 'reorden', 'punto de reorden']],
            'pais_origen'   => ['label' => 'País de origen',      'req' => false, 'alias' => ['pais', 'país', 'pais origen', 'país de origen', 'origen', 'country', 'made in']],
            'fabricante'    => ['label' => 'Fabricante',          'req' => false, 'alias' => ['fabricante', 'manufacturer', 'proveedor fabricante', 'laboratorio']],
            'descripcion'   => ['label' => 'Descripción larga',   'req' => false, 'alias' => ['descripcion larga', 'descripción larga', 'detalle largo', 'observacion', 'observación', 'notas']],
        ];
    }

    if ($tipo === 'existencias') {
        return [
            'producto' => ['label' => 'Producto (código, barras o nombre)', 'req' => true, 'alias' => ['producto', 'sku', 'codigo', 'código', 'articulo', 'artículo', 'item', 'referencia', 'ref', 'barras', 'codigo de barras', 'código de barras', 'descripcion', 'descripción']],
            'cantidad' => ['label' => 'Cantidad contada',  'req' => true,  'alias' => ['cantidad', 'existencia', 'existencias', 'stock', 'cant', 'qty', 'unidades', 'saldo', 'inventario', 'contado', 'fisico', 'físico']],
            'sucursal' => ['label' => 'Sucursal / almacén', 'req' => false, 'alias' => ['sucursal', 'almacen', 'almacén', 'tienda fisica', 'local', 'branch', 'warehouse', 'deposito', 'depósito', 'ubicacion', 'ubicación']],
            'costo'    => ['label' => 'Costo unitario',    'req' => false, 'alias' => ['costo', 'costo unitario', 'cost', 'valor unitario', 'precio compra']],
        ];
    }

    if ($tipo === 'embarque') {
        return [
            'producto'     => ['label' => 'Producto (código o barras)', 'req' => true, 'alias' => ['producto', 'sku', 'codigo', 'código', 'articulo', 'artículo', 'item', 'referencia', 'ref', 'item code', 'item no', 'part number', 'part no', 'model', 'modelo', 'codigo de barras', 'código de barras', 'barras']],
            'cantidad'     => ['label' => 'Cantidad',        'req' => true,  'alias' => ['cantidad', 'cant', 'qty', 'quantity', 'unidades', 'pcs', 'piezas', 'pzs']],
            'costo_moneda' => ['label' => 'Costo unitario (moneda del embarque)', 'req' => false, 'alias' => ['costo', 'costo unitario', 'precio', 'precio unitario', 'unit price', 'fob unitario', 'fob', 'valor unitario', 'cost']],
            'descripcion'  => ['label' => 'Descripción (para los que no existen)', 'req' => false, 'alias' => ['descripcion', 'descripción', 'nombre', 'producto nombre', 'detalle', 'description', 'item description']],
            'peso'         => ['label' => 'Peso',            'req' => false, 'alias' => ['peso', 'weight', 'kg', 'kgs', 'peso neto', 'net weight', 'peso bruto']],
            'volumen'      => ['label' => 'Volumen',         'req' => false, 'alias' => ['volumen', 'volume', 'cbm', 'm3', 'metros cubicos', 'metros cúbicos']],
            'lote'         => ['label' => 'Lote',            'req' => false, 'alias' => ['lote', 'batch', 'lot', 'no lote']],
            'vencimiento'  => ['label' => 'Vencimiento',     'req' => false, 'alias' => ['vencimiento', 'vence', 'expira', 'expiry', 'exp', 'fecha vencimiento', 'caducidad', 'best before']],
        ];
    }

    if ($tipo === 'clientes') {
        return [
            'nombre'           => ['label' => 'Nombre del cliente', 'req' => true,  'alias' => ['nombre', 'cliente', 'razon social', 'razón social', 'nombre cliente', 'name', 'customer']],
            'codigo'           => ['label' => 'Código',             'req' => false, 'alias' => ['codigo', 'código', 'id', 'code', 'no cliente', 'codigo cliente']],
            'rnc_cedula'       => ['label' => 'RNC o cédula',       'req' => false, 'alias' => ['rnc', 'cedula', 'cédula', 'rnc/cedula', 'rnc cedula', 'documento', 'identificacion', 'tax id']],
            'telefono'         => ['label' => 'Teléfono',           'req' => false, 'alias' => ['telefono', 'teléfono', 'tel', 'celular', 'movil', 'móvil', 'phone']],
            'email'            => ['label' => 'Correo',             'req' => false, 'alias' => ['email', 'correo', 'e-mail', 'mail']],
            'direccion'        => ['label' => 'Dirección',          'req' => false, 'alias' => ['direccion', 'dirección', 'address', 'domicilio']],
            'fecha_nacimiento' => ['label' => 'Fecha de nacimiento','req' => false, 'alias' => ['fecha nacimiento', 'nacimiento', 'cumpleanos', 'cumpleaños', 'birthday']],
            'tipo'             => ['label' => 'Tipo (contado/crédito)', 'req' => false, 'alias' => ['tipo', 'tipo cliente', 'condicion', 'condición']],
            'limite_credito'   => ['label' => 'Límite de crédito',  'req' => false, 'alias' => ['limite credito', 'límite crédito', 'limite', 'credito']],
        ];
    }
    return [
        'fecha'        => ['label' => 'Fecha de la venta', 'req' => true,  'alias' => ['fecha', 'date', 'fecha factura', 'fecha venta', 'f. emision', 'emision']],
        'numero'       => ['label' => 'Número de factura', 'req' => false, 'alias' => ['numero', 'número', 'factura', 'no factura', 'nro', 'documento', 'invoice', 'no.']],
        'cliente'      => ['label' => 'Cliente (nombre)',  'req' => false, 'alias' => ['cliente', 'nombre cliente', 'customer', 'razon social', 'razón social']],
        'cliente_rnc'  => ['label' => 'Cliente (RNC/cédula)', 'req' => false, 'alias' => ['rnc', 'cedula', 'cédula', 'rnc cliente', 'documento cliente']],
        'ncf'          => ['label' => 'NCF',               'req' => false, 'alias' => ['ncf', 'comprobante', 'ncf factura', 'e-ncf', 'encf']],
        'sucursal'     => ['label' => 'Sucursal',          'req' => false, 'alias' => ['sucursal', 'tienda fisica', 'local', 'branch', 'almacen', 'almacén']],
        'tienda'       => ['label' => 'Tienda (marca)',    'req' => false, 'alias' => ['marca', 'tienda', 'brand', 'linea', 'línea']],
        'vendedor'     => ['label' => 'Vendedor',          'req' => false, 'alias' => ['vendedor', 'cajero', 'seller', 'atendio', 'atendió']],
        'canal'        => ['label' => 'Canal de venta',    'req' => false, 'alias' => ['canal', 'origen', 'channel']],
        'metodo_pago'  => ['label' => 'Método de pago',    'req' => false, 'alias' => ['metodo', 'método', 'metodo pago', 'forma de pago', 'pago', 'payment']],
        'producto'     => ['label' => 'Producto (SKU o nombre)', 'req' => false, 'alias' => ['producto', 'sku', 'codigo producto', 'articulo', 'artículo', 'descripcion', 'descripción', 'item']],
        'cantidad'     => ['label' => 'Cantidad',          'req' => false, 'alias' => ['cantidad', 'cant', 'qty', 'unidades']],
        'precio'       => ['label' => 'Precio unitario',   'req' => false, 'alias' => ['precio', 'precio unitario', 'p. unitario', 'unit price']],
        'subtotal'     => ['label' => 'Subtotal / importe','req' => false, 'alias' => ['subtotal', 'importe', 'monto', 'valor', 'base', 'amount']],
        'descuento'    => ['label' => 'Descuento',         'req' => false, 'alias' => ['descuento', 'desc', 'discount']],
        'itbis'        => ['label' => 'ITBIS',             'req' => false, 'alias' => ['itbis', 'impuesto', 'tax', 'iva']],
        'total'        => ['label' => 'Total',             'req' => false, 'alias' => ['total', 'total factura', 'gran total', 'total general']],
        'costo'        => ['label' => 'Costo',             'req' => false, 'alias' => ['costo', 'costo total', 'cost', 'costo unitario']],
    ];
}

/** Normaliza un encabezado para compararlo: sin acentos, sin puntuación, minúsculas. */
function imp_slug(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string) $s));
}

/**
 * Adivina qué columna del archivo va a cada campo.
 * Devuelve [campo => índice de columna]. Solo asigna coincidencias claras: es
 * preferible que la CEO complete tres selectores a que el sistema cargue el
 * total en la columna del descuento sin decir nada.
 */
function imp_automapear(array $headers, string $tipo): array
{
    $mapa = [];
    $usadas = [];
    $slugs = array_map('imp_slug', $headers);

    foreach (imp_campos($tipo) as $campo => $cfg) {
        foreach ($cfg['alias'] as $alias) {
            $a = imp_slug($alias);
            foreach ($slugs as $i => $h) {
                if (isset($usadas[$i]) || $h === '') continue;
                if ($h === $a) { $mapa[$campo] = $i; $usadas[$i] = true; break 2; }
            }
        }
    }
    return $mapa;
}

/* ============================================================
 *  Normalización de valores
 * ============================================================ */

/**
 * Número desde cualquier formato de hoja de cálculo.
 * «RD$ 1,234.56», «1.234,56» y «(500)» (negativo contable) llegan todos aquí.
 */
function imp_num($v): float
{
    if (is_int($v) || is_float($v)) return (float) $v;
    $s = trim((string) $v);
    if ($s === '') return 0.0;
    $negativo = str_starts_with($s, '(') && str_ends_with($s, ')');
    $s = preg_replace('/[^0-9,.\-]/', '', $s) ?? '';
    if ($s === '' || $s === '-') return 0.0;

    $coma = strrpos($s, ',');
    $punto = strrpos($s, '.');
    if ($coma !== false && $punto !== false) {
        // El separador decimal es el que está más a la derecha.
        if ($coma > $punto) $s = str_replace('.', '', $s);
        else                $s = str_replace(',', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($coma !== false) {
        // Una sola coma: decimal si deja 1 o 2 cifras («1,50»); si deja 3, es
        // separador de miles («1,500»).
        $s = (strlen($s) - $coma - 1) <= 2 ? str_replace(',', '.', $s) : str_replace(',', '', $s);
    }
    $n = (float) $s;
    return $negativo ? -abs($n) : $n;
}

/**
 * Fecha desde cualquier formato razonable. Devuelve 'Y-m-d' o null.
 * En República Dominicana se escribe dd/mm/aaaa: ante «03/04/2025» se lee
 * 3 de abril, no 4 de marzo.
 */
function imp_fecha($v): ?string
{
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    $s = trim((string) $v);
    if ($s === '') return null;

    // Serial de Excel (días desde 1899-12-30).
    // `gmdate` y no `date`: el serial es una fecha sin hora ni huso, y con la
    // zona de Santo Domingo (UTC−4) el instante de medianoche UTC cae en el día
    // anterior. Con `date` toda fecha cargada por serial se corría un día atrás.
    if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {
        return gmdate('Y-m-d', (int) round(((float) $s - 25569) * 86400));
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})#', $s, $m)) {
        $d = (int) $m[1]; $mes = (int) $m[2]; $a = (int) $m[3];
        if ($a < 100) $a += $a < 70 ? 2000 : 1900;
        // Si el primer número no puede ser día pero el segundo sí, el archivo
        // viene en formato estadounidense: se acepta en vez de rechazar la fila.
        if ($d > 12 && $mes > 12) return null;
        if ($d > 31 || ($d > 12 && $mes > 12)) return null;
        if ($mes > 12) { [$d, $mes] = [$mes, $d]; }
        return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Valor de una columna mapeada en una fila. '' si no está mapeada. */
function imp_val(array $fila, array $mapa, string $campo): string
{
    if (!isset($mapa[$campo])) return '';
    $v = $fila[$mapa[$campo]] ?? '';
    return is_string($v) ? trim($v) : (string) $v;
}

/* ============================================================
 *  Análisis (vista previa)
 * ============================================================ */

/**
 * Revisa el archivo entero contra el mapeo y devuelve lo que se cargaría.
 *
 * No escribe nada. Es el paso que permite decir «12.480 filas, 12.475 ventas
 * por RD$ 34,2 millones, 5 filas rechazadas y estos son los motivos» ANTES de
 * tocar la base.
 *
 * @return array{docs:array,errores:array,avisos:array,resumen:array}
 */
function imp_analizar(string $tipo, array $filas, array $mapa, array $opts): array
{
    switch (imp_tipo_valido($tipo)) {
        case 'clientes':    return imp_analizar_clientes($filas, $mapa, $opts);
        case 'productos':   return imp_analizar_productos($filas, $mapa, $opts);
        case 'existencias': return imp_analizar_existencias($filas, $mapa, $opts);
        case 'embarque':    return imp_analizar_embarque($filas, $mapa, $opts);
        default:            return imp_analizar_ventas($filas, $mapa, $opts);
    }
}

/* ============================================================
 *  Utilidades compartidas por los tipos de inventario
 * ============================================================ */

/**
 * Índice de productos en memoria: código, código de barras y nombre → id.
 *
 * Se arma una vez por análisis. Con 5.000 SKU en el catálogo y 3.000 filas en
 * el archivo, consultar la base por fila son 3.000 viajes; el índice es uno.
 */
function imp_indice_productos(bool $recargar = false): array
{
    static $idx = null;
    if ($recargar) $idx = null;
    if ($idx !== null) return $idx;

    $idx = ['codigo' => [], 'barras' => [], 'nombre' => []];
    foreach (qAll("SELECT id, codigo, codigo_barras, nombre FROM productos") as $p) {
        $id = (int) $p['id'];
        $idx['codigo'][imp_slug((string) $p['codigo'])] = $id;
        if ((string) $p['codigo_barras'] !== '') {
            $idx['barras'][imp_slug((string) $p['codigo_barras'])] = $id;
        }
        // El nombre es el último recurso y solo si no se repite: dos productos
        // con el mismo nombre no se pueden distinguir, y adivinar el que no es
        // carga la existencia en el artículo equivocado.
        $n = imp_slug((string) $p['nombre']);
        $idx['nombre'][$n] = array_key_exists($n, $idx['nombre']) ? 0 : $id;
    }
    return $idx;
}

/** Relee el catálogo. Hace falta tras crear productos y volver a analizar. */
function imp_olvidar_indice(): void
{
    imp_indice_productos(true);
}

/**
 * Busca un producto por código, por código de barras y —si se permite— por
 * nombre exacto. Devuelve el id o null.
 *
 * El orden importa: el código es la identidad del artículo en el sistema y el
 * código de barras la del fabricante. Buscar primero por nombre encontraría
 * «Base líquida 30ml» de dos marcas distintas.
 */
function imp_buscar_producto(string $texto, array $idx, bool $porNombre = true): ?int
{
    $s = imp_slug($texto);
    if ($s === '') return null;
    if (isset($idx['codigo'][$s])) return $idx['codigo'][$s];
    if (isset($idx['barras'][$s])) return $idx['barras'][$s];
    if ($porNombre && !empty($idx['nombre'][$s])) return $idx['nombre'][$s];
    return null;
}

/** Índice de sucursales por nombre normalizado. */
function imp_indice_sucursales(): array
{
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    foreach (qAll("SELECT id, nombre FROM sucursales WHERE activo = 1") as $s) {
        $idx[imp_slug($s['nombre'])] = (int) $s['id'];
    }
    return $idx;
}

/**
 * Sí o no desde una hoja de cálculo. «Sí», «S», «1», «TRUE», «X» y la celda
 * vacía tratada como el valor por defecto que decida quien llama.
 */
function imp_bool($v, bool $porDefecto): bool
{
    $s = imp_slug((string) $v);
    if ($s === '') return $porDefecto;
    if (in_array($s, ['si', 'sí', 's', '1', 'true', 'v', 'x', 'y', 'yes', 'gravado', 'aplica'], true)) return true;
    if (in_array($s, ['no', 'n', '0', 'false', 'f', 'exento', 'exenta'], true)) return false;
    return $porDefecto;
}

function imp_analizar_clientes(array $filas, array $mapa, array $opts): array
{
    $docs = []; $errores = []; $avisos = [];
    $vistos = [];
    $nuevos = 0; $existentes = 0;

    foreach ($filas as $i => $fila) {
        $linea = $i + 2; // +1 por el encabezado, +1 porque las hojas empiezan en 1
        $nombre = imp_val($fila, $mapa, 'nombre');
        if ($nombre === '') { $errores[] = ['fila' => $linea, 'motivo' => 'Sin nombre de cliente.']; continue; }

        $rnc    = preg_replace('/[^0-9A-Za-z]/', '', imp_val($fila, $mapa, 'rnc_cedula'));
        $codigo = imp_val($fila, $mapa, 'codigo');
        $email  = imp_val($fila, $mapa, 'email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $avisos[] = ['fila' => $linea, 'motivo' => 'Correo no válido; se cargará el cliente sin correo.'];
            $email = '';
        }

        // Dentro del mismo archivo, el mismo cliente repetido es una sola ficha.
        $clave = $rnc !== '' ? 'r:' . $rnc : ($codigo !== '' ? 'c:' . mb_strtolower($codigo) : 'n:' . mb_strtolower($nombre));
        if (isset($vistos[$clave])) {
            $avisos[] = ['fila' => $linea, 'motivo' => 'Cliente repetido en el archivo; se toma la primera aparición.'];
            continue;
        }
        $vistos[$clave] = true;

        $existe = imp_buscar_cliente($nombre, $rnc, $codigo);
        if ($existe) $existentes++; else $nuevos++;

        $docs[] = [
            'fila'             => $linea,
            'existente_id'     => $existe,
            'nombre'           => mb_substr($nombre, 0, 150),
            'codigo'           => mb_substr($codigo, 0, 20),
            'rnc_cedula'       => $rnc !== '' ? mb_substr($rnc, 0, 30) : null,
            'telefono'         => mb_substr(imp_val($fila, $mapa, 'telefono'), 0, 40) ?: null,
            'email'            => $email ?: null,
            'direccion'        => mb_substr(imp_val($fila, $mapa, 'direccion'), 0, 255) ?: null,
            'fecha_nacimiento' => imp_fecha(imp_val($fila, $mapa, 'fecha_nacimiento')),
            'tipo'             => str_contains(mb_strtolower(imp_val($fila, $mapa, 'tipo')), 'cred') ? 'credito' : 'contado',
            'limite_credito'   => max(0, imp_num(imp_val($fila, $mapa, 'limite_credito'))),
        ];
    }

    return [
        'docs' => $docs, 'errores' => $errores, 'avisos' => $avisos,
        'resumen' => [
            'filas' => count($filas), 'validos' => count($docs),
            'nuevos' => $nuevos, 'existentes' => $existentes,
            'rechazados' => count($errores), 'monto' => 0.0,
        ],
    ];
}

/** Busca un cliente ya registrado por RNC, código o nombre exacto. */
function imp_buscar_cliente(string $nombre, string $rnc, string $codigo): ?int
{
    if ($rnc !== '') {
        $id = qVal("SELECT id FROM clientes WHERE REPLACE(REPLACE(rnc_cedula,'-',''),' ','') = ? LIMIT 1", [$rnc]);
        if ($id) return (int) $id;
    }
    if ($codigo !== '') {
        $id = qVal("SELECT id FROM clientes WHERE codigo = ? LIMIT 1", [$codigo]);
        if ($id) return (int) $id;
    }
    $id = qVal("SELECT id FROM clientes WHERE nombre = ? LIMIT 1", [$nombre]);
    return $id ? (int) $id : null;
}

function imp_analizar_ventas(array $filas, array $mapa, array $opts): array
{
    $docs = []; $errores = []; $avisos = [];
    $tasa = (float) setting('itbis_tasa', 18);
    $itbisModo   = $opts['itbis_modo'] ?? 'ninguno';
    $sucursalDef = (int) ($opts['sucursal_id'] ?? 0);
    $tiendaDef   = (int) ($opts['tienda_id'] ?? 0) ?: null;
    $costoCatalogo = !empty($opts['costo_catalogo']);
    // Detallado = el archivo trae una fila por línea de factura.
    $detallado = isset($mapa['producto']);

    // Catálogos en memoria: resolver cada fila con un SELECT convierte 12.000
    // filas en 48.000 consultas.
    $sucursales = [];
    foreach (qAll("SELECT id, nombre, codigo FROM sucursales") as $s) {
        $sucursales[imp_slug($s['nombre'])] = (int) $s['id'];
        $sucursales[imp_slug($s['codigo'])] = (int) $s['id'];
    }
    $tiendas = [];
    foreach (tiendas_activas() as $t) {
        $tiendas[imp_slug($t['nombre'])] = (int) $t['id'];
        $tiendas[imp_slug($t['codigo'])] = (int) $t['id'];
    }
    $productos = [];
    foreach (qAll("SELECT id, codigo, codigo_barras, nombre, precio_compra FROM productos") as $p) {
        $productos['c:' . imp_slug($p['codigo'])] = $p;
        if ($p['codigo_barras']) $productos['b:' . trim((string) $p['codigo_barras'])] = $p;
        $productos['n:' . imp_slug($p['nombre'])] = $p;
    }

    $sinProducto = 0; $grupos = [];

    foreach ($filas as $i => $fila) {
        $linea = $i + 2;
        $fecha = imp_fecha(imp_val($fila, $mapa, 'fecha'));
        if ($fecha === null) {
            $errores[] = ['fila' => $linea, 'motivo' => 'Fecha vacía o ilegible: «' . imp_val($fila, $mapa, 'fecha') . '».'];
            continue;
        }
        if ($fecha > date('Y-m-d')) {
            $errores[] = ['fila' => $linea, 'motivo' => 'La fecha está en el futuro (' . $fecha . ').'];
            continue;
        }

        $numero = mb_substr(imp_val($fila, $mapa, 'numero'), 0, 30);
        // Sin número de factura cada fila es su propio documento; con número, las
        // filas del mismo número son las líneas de una misma factura.
        $clave = ($detallado && $numero !== '') ? 'n:' . $numero : 'f:' . $linea;

        $sucSlug = imp_slug(imp_val($fila, $mapa, 'sucursal'));
        $sucId = $sucSlug !== '' ? ($sucursales[$sucSlug] ?? $sucursalDef) : $sucursalDef;
        if (!$sucId) {
            $errores[] = ['fila' => $linea, 'motivo' => 'No se pudo determinar la sucursal.'];
            continue;
        }
        $tieSlug = imp_slug(imp_val($fila, $mapa, 'tienda'));
        $tieId = $tieSlug !== '' ? ($tiendas[$tieSlug] ?? $tiendaDef) : $tiendaDef;

        // --- Importes de la fila ---
        $cant     = imp_num(imp_val($fila, $mapa, 'cantidad')) ?: 1.0;
        $precio   = imp_num(imp_val($fila, $mapa, 'precio'));
        $subtotal = imp_num(imp_val($fila, $mapa, 'subtotal'));
        $descuento= max(0, imp_num(imp_val($fila, $mapa, 'descuento')));
        $itbis    = imp_num(imp_val($fila, $mapa, 'itbis'));
        $total    = imp_num(imp_val($fila, $mapa, 'total'));
        $costo    = imp_num(imp_val($fila, $mapa, 'costo'));

        if ($subtotal == 0.0 && $precio != 0.0) $subtotal = round($precio * $cant, 2);
        if ($subtotal == 0.0 && $total != 0.0)  $subtotal = $total - $itbis;

        // ITBIS cuando el archivo no trae la columna.
        if (!isset($mapa['itbis'])) {
            if ($itbisModo === 'incluido') {
                $base  = $subtotal - $descuento;
                $itbis = round($base - $base / (1 + $tasa / 100), 2);
                $subtotal = round($subtotal - $itbis, 2);
            } elseif ($itbisModo === 'excluido') {
                $itbis = round(($subtotal - $descuento) * $tasa / 100, 2);
            } else {
                $itbis = 0.0;
            }
        }
        if ($subtotal <= 0 && $itbis <= 0) {
            $errores[] = ['fila' => $linea, 'motivo' => 'La fila no tiene importe (subtotal, precio ni total).'];
            continue;
        }
        if ($descuento > $subtotal) $descuento = $subtotal;

        // --- Producto ---
        $prodTxt = imp_val($fila, $mapa, 'producto');
        $prod = null;
        if ($prodTxt !== '') {
            $prod = $productos['c:' . imp_slug($prodTxt)]
                ?? $productos['b:' . $prodTxt]
                ?? $productos['n:' . imp_slug($prodTxt)]
                ?? null;
            if (!$prod) $sinProducto++;
        }
        if ($costo == 0.0 && $prod && $costoCatalogo) {
            $costo = round((float) $prod['precio_compra'] * $cant, 2);
        }

        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'numero'      => $numero,
                'fecha'       => $fecha,
                'sucursal_id' => $sucId,
                'tienda_id'   => $tieId,
                'ncf'         => mb_substr(strtoupper(preg_replace('/\s+/', '', imp_val($fila, $mapa, 'ncf'))), 0, 19) ?: null,
                'cliente'     => mb_substr(imp_val($fila, $mapa, 'cliente'), 0, 150),
                'cliente_rnc' => preg_replace('/[^0-9A-Za-z]/', '', imp_val($fila, $mapa, 'cliente_rnc')),
                'vendedor'    => imp_val($fila, $mapa, 'vendedor'),
                'canal'       => mb_substr(imp_val($fila, $mapa, 'canal'), 0, 40) ?: 'Histórico',
                'metodo'      => imp_val($fila, $mapa, 'metodo_pago'),
                'lineas'      => [],
                'subtotal'    => 0.0, 'descuento' => 0.0, 'itbis' => 0.0, 'costo' => 0.0,
                'filas'       => [],
            ];
        }
        $g = &$grupos[$clave];
        $g['filas'][] = $linea;
        $g['lineas'][] = [
            'producto_id' => $prod ? (int) $prod['id'] : null,
            'descripcion' => mb_substr($prodTxt !== '' ? ($prod['nombre'] ?? $prodTxt) : 'Venta histórica importada', 0, 180),
            'cantidad'    => $cant > 0 ? $cant : 1,
            'precio'      => $cant > 0 ? round($subtotal / $cant, 2) : $subtotal,
            'subtotal'    => round($subtotal, 2),
            'itbis'       => round($itbis, 2),
            'costo_unit'  => $cant > 0 ? round($costo / $cant, 2) : $costo,
        ];
        $g['subtotal']  = round($g['subtotal'] + $subtotal, 2);
        $g['descuento'] = round($g['descuento'] + $descuento, 2);
        $g['itbis']     = round($g['itbis'] + $itbis, 2);
        $g['costo']     = round($g['costo'] + $costo, 2);
        unset($g);
    }

    // --- Validación por documento ---
    $monto = 0.0; $duplicados = 0; $sinCliente = 0;
    $numerosVistos = [];

    foreach ($grupos as $g) {
        $g['total'] = round($g['subtotal'] - $g['descuento'] + $g['itbis'], 2);

        // Un número que ya existe en la base es una recarga del mismo archivo:
        // se salta en silencio para que reimportar sea seguro.
        if ($g['numero'] !== '') {
            if (isset($numerosVistos[$g['numero']])) {
                $errores[] = ['fila' => $g['filas'][0], 'motivo' => 'Número de factura repetido en el archivo: ' . $g['numero'] . '.'];
                continue;
            }
            $numerosVistos[$g['numero']] = true;
            if (qVal("SELECT 1 FROM ventas WHERE numero = ?", [$g['numero']])) {
                $duplicados++;
                continue;
            }
        }
        if ($g['ncf'] && qVal("SELECT 1 FROM ventas WHERE ncf = ?", [$g['ncf']])) {
            $duplicados++;
            continue;
        }
        if ($g['cliente'] === '' && $g['cliente_rnc'] === '') $sinCliente++;

        $monto += $g['subtotal'] - $g['descuento'];
        $docs[] = $g;
    }

    if ($sinProducto > 0) {
        $avisos[] = ['fila' => 0, 'motivo' => $sinProducto . ' línea(s) mencionan un producto que no está en el catálogo: se cargan con su descripción, sin enlazar.'];
    }
    if ($sinCliente > 0) {
        $avisos[] = ['fila' => 0, 'motivo' => $sinCliente . ' venta(s) sin cliente: quedarán como consumidor final.'];
    }
    if ($duplicados > 0) {
        $avisos[] = ['fila' => 0, 'motivo' => $duplicados . ' factura(s) ya estaban registradas y se omitirán (así reimportar el mismo archivo no duplica nada).'];
    }

    return [
        'docs' => $docs, 'errores' => $errores, 'avisos' => $avisos,
        'resumen' => [
            'filas' => count($filas), 'validos' => count($docs),
            'nuevos' => count($docs), 'existentes' => $duplicados,
            'rechazados' => count($errores), 'monto' => round($monto, 2),
            'detallado' => $detallado,
        ],
    ];
}

/* ============================================================
 *  Análisis · Catálogo de productos
 * ============================================================ */

/**
 * Revisa un catálogo. Nada de esto escribe: solo dice qué pasaría.
 *
 * Lo delicado son los dos índices únicos de `productos`. Una fila puede:
 *   · no coincidir con nada          → producto nuevo
 *   · coincidir por código           → actualización
 *   · coincidir por código de barras → actualización (el código cambió de sistema)
 *   · coincidir por código con UNO y por barras con OTRO → **conflicto**
 *
 * El cuarto caso se rechaza. Fusionar dos artículos distintos porque un archivo
 * los mezcló deja el inventario de los dos mal y no hay forma de deshacerlo
 * después: el histórico de ventas ya apunta a un solo id.
 */
function imp_analizar_productos(array $filas, array $mapa, array $opts): array
{
    $docs = []; $errores = []; $avisos = [];
    $idx = imp_indice_productos();
    $vistosCodigo = []; $vistosBarras = [];
    $nuevos = 0; $existentes = 0;
    $catNuevas = []; $marcasNuevas = []; $unidNuevas = [];

    $categorias = []; $marcas = []; $unidades = []; $tiendas = [];
    foreach (qAll("SELECT id, nombre FROM categorias") as $r) $categorias[imp_slug($r['nombre'])] = (int) $r['id'];
    foreach (qAll("SELECT id, nombre FROM marcas") as $r)     $marcas[imp_slug($r['nombre'])]     = (int) $r['id'];
    foreach (qAll("SELECT id, nombre, abreviatura FROM unidades") as $r) {
        $unidades[imp_slug($r['nombre'])] = (int) $r['id'];
        $unidades[imp_slug((string) $r['abreviatura'])] = (int) $r['id'];
    }
    try {
        foreach (qAll("SELECT id, nombre FROM tiendas") as $r) $tiendas[imp_slug($r['nombre'])] = (int) $r['id'];
    } catch (Throwable $e) { /* sin tiendas configuradas todavía */ }

    foreach ($filas as $i => $fila) {
        $linea = $i + 2;
        $nombre = imp_val($fila, $mapa, 'nombre');
        if ($nombre === '') { $errores[] = ['fila' => $linea, 'motivo' => 'Sin nombre de producto.']; continue; }

        $codigo = mb_substr(imp_val($fila, $mapa, 'codigo'), 0, 40);
        $barras = mb_substr(preg_replace('/\s+/', '', imp_val($fila, $mapa, 'codigo_barras')), 0, 60);

        // Repetido dentro del propio archivo: se queda la primera aparición.
        $sc = imp_slug($codigo); $sb = imp_slug($barras);
        if ($sc !== '' && isset($vistosCodigo[$sc])) {
            $errores[] = ['fila' => $linea, 'motivo' => 'El código «' . $codigo . '» ya viene en la fila ' . $vistosCodigo[$sc] . ' del archivo.'];
            continue;
        }
        if ($sb !== '' && isset($vistosBarras[$sb])) {
            $errores[] = ['fila' => $linea, 'motivo' => 'El código de barras «' . $barras . '» ya viene en la fila ' . $vistosBarras[$sb] . ' del archivo.'];
            continue;
        }

        $porCodigo = $sc !== '' ? ($idx['codigo'][$sc] ?? null) : null;
        $porBarras = $sb !== '' ? ($idx['barras'][$sb] ?? null) : null;
        if ($porCodigo && $porBarras && $porCodigo !== $porBarras) {
            $errores[] = ['fila' => $linea, 'motivo' => 'El código «' . $codigo . '» y el código de barras «' . $barras
                . '» pertenecen a dos productos distintos del catálogo. Corrige la fila: fusionarlos dañaría los dos.'];
            continue;
        }
        $existenteId = $porCodigo ?: $porBarras;

        $nombreCat = imp_val($fila, $mapa, 'categoria');
        $nombreMar = imp_val($fila, $mapa, 'marca');
        $nombreUni = imp_val($fila, $mapa, 'unidad');
        $nombreTie = imp_val($fila, $mapa, 'tienda');

        $catId = $nombreCat !== '' ? ($categorias[imp_slug($nombreCat)] ?? null) : null;
        $marId = $nombreMar !== '' ? ($marcas[imp_slug($nombreMar)] ?? null) : null;
        $uniId = $nombreUni !== '' ? ($unidades[imp_slug($nombreUni)] ?? null) : null;
        $tieId = $nombreTie !== '' ? ($tiendas[imp_slug($nombreTie)] ?? null) : null;

        if ($nombreCat !== '' && !$catId) $catNuevas[imp_slug($nombreCat)] = $nombreCat;
        if ($nombreMar !== '' && !$marId) $marcasNuevas[imp_slug($nombreMar)] = $nombreMar;
        if ($nombreUni !== '' && !$uniId) $unidNuevas[imp_slug($nombreUni)] = $nombreUni;
        if ($nombreTie !== '' && !$tieId) {
            $avisos[] = ['fila' => $linea, 'motivo' => 'La tienda «' . $nombreTie . '» no existe; el producto queda sin marca comercial.'];
        }

        $compra = imp_num(imp_val($fila, $mapa, 'precio_compra'));
        $venta  = imp_num(imp_val($fila, $mapa, 'precio_venta'));
        if ($venta < 0 || $compra < 0) {
            $errores[] = ['fila' => $linea, 'motivo' => 'Precio negativo.'];
            continue;
        }
        if ($venta > 0 && $compra > 0 && $venta < $compra) {
            $avisos[] = ['fila' => $linea, 'motivo' => '«' . $nombre . '» se vendería por debajo del costo ('
                . number_format($venta, 2) . ' < ' . number_format($compra, 2) . ').'];
        }

        if ($sc !== '') $vistosCodigo[$sc] = $linea;
        if ($sb !== '') $vistosBarras[$sb] = $linea;
        $existenteId ? $existentes++ : $nuevos++;

        $docs[] = [
            'fila'          => $linea,
            'existente_id'  => $existenteId,
            'codigo'        => $codigo,
            'codigo_barras' => $barras,
            'nombre'        => mb_substr($nombre, 0, 180),
            'descripcion'   => mb_substr(imp_val($fila, $mapa, 'descripcion'), 0, 255),
            'categoria'     => $nombreCat,
            'marca'         => $nombreMar,
            'unidad'        => $nombreUni,
            'tienda_id'     => $tieId,
            'precio_compra' => round($compra, 2),
            'precio_venta'  => round($venta, 2),
            'itbis_aplica'  => imp_bool(imp_val($fila, $mapa, 'itbis_aplica'), true) ? 1 : 0,
            'stock_minimo'  => max(0, imp_num(imp_val($fila, $mapa, 'stock_minimo'))),
            'pais_origen'   => mb_substr(imp_val($fila, $mapa, 'pais_origen'), 0, 60),
            'fabricante'    => mb_substr(imp_val($fila, $mapa, 'fabricante'), 0, 180),
        ];
    }

    if ($catNuevas || $marcasNuevas || $unidNuevas) {
        $partes = [];
        if ($catNuevas)    $partes[] = count($catNuevas) . ' categoría(s)';
        if ($marcasNuevas) $partes[] = count($marcasNuevas) . ' marca(s)';
        if ($unidNuevas)   $partes[] = count($unidNuevas) . ' unidad(es)';
        $avisos[] = ['fila' => 0, 'motivo' => empty($opts['crear_catalogos'])
            ? 'Se dejarán sin asignar ' . implode(', ', $partes) . ' que no existen. Marca «crear los que falten» si quieres darlos de alta.'
            : 'Se crearán ' . implode(', ', $partes) . ' que no existen todavía.'];
    }

    return [
        'docs' => $docs, 'errores' => $errores, 'avisos' => $avisos,
        'resumen' => [
            'filas'      => count($filas),
            'validos'    => count($docs),
            'nuevos'     => $nuevos,
            'existentes' => $existentes,
            'rechazados' => count($errores),
            'monto'      => 0.0,
            'catalogos'  => ['categorias' => $catNuevas, 'marcas' => $marcasNuevas, 'unidades' => $unidNuevas],
        ],
    ];
}

/* ============================================================
 *  Análisis · Existencias del almacén
 * ============================================================ */

/**
 * Revisa un archivo de existencias y lo agrupa por sucursal.
 *
 * NO escribe stock. Lo que sale de aquí es la materia prima de un conteo físico
 * en borrador por sucursal: el sistema pone el `stock_teorico` que tiene hoy y
 * el archivo pone el `stock_contado`. La diferencia la aplica una persona, y el
 * ajuste entra al kardex con su motivo, su usuario y su fecha.
 *
 * Un producto repetido para la misma sucursal se SUMA. En un archivo de almacén
 * el mismo SKU aparece una vez por ubicación o por pallet, y `conteo_detalles`
 * es único por (conteo, producto): insertarlo dos veces reventaría la tanda.
 */
function imp_analizar_existencias(array $filas, array $mapa, array $opts): array
{
    $errores = []; $avisos = [];
    $idx = imp_indice_productos();
    $sucursales = imp_indice_sucursales();
    $porNombre = !empty($opts['cotejar_por_nombre']);
    $sucDefecto = (int) ($opts['sucursal_id'] ?? 0);

    $grupos = [];      // sucursal_id => [producto_id => ['cantidad'=>, 'costo'=>, 'filas'=>[]]]
    $unidades = 0.0; $repetidos = 0;

    foreach ($filas as $i => $fila) {
        $linea = $i + 2;
        $texto = imp_val($fila, $mapa, 'producto');
        if ($texto === '') { $errores[] = ['fila' => $linea, 'motivo' => 'Sin producto.']; continue; }

        $prodId = imp_buscar_producto($texto, $idx, $porNombre);
        if (!$prodId) {
            $errores[] = ['fila' => $linea, 'motivo' => 'No existe el producto «' . $texto . '». Carga primero el catálogo.'];
            continue;
        }

        $sucId = $sucDefecto;
        $nombreSuc = imp_val($fila, $mapa, 'sucursal');
        if ($nombreSuc !== '') {
            $hallada = $sucursales[imp_slug($nombreSuc)] ?? 0;
            if (!$hallada) {
                $errores[] = ['fila' => $linea, 'motivo' => 'No existe la sucursal «' . $nombreSuc . '».'];
                continue;
            }
            $sucId = $hallada;
        }
        if ($sucId <= 0) {
            $errores[] = ['fila' => $linea, 'motivo' => 'Sin sucursal: mapea la columna o escoge una para todo el archivo.'];
            continue;
        }

        $cantidadTxt = imp_val($fila, $mapa, 'cantidad');
        $cantidad = imp_num($cantidadTxt);
        if ($cantidadTxt === '') { $errores[] = ['fila' => $linea, 'motivo' => 'Sin cantidad.']; continue; }
        if ($cantidad < 0) {
            $errores[] = ['fila' => $linea, 'motivo' => 'Cantidad negativa (' . $cantidadTxt . '). Una existencia no puede ser negativa.'];
            continue;
        }

        $costo = imp_num(imp_val($fila, $mapa, 'costo'));
        if (isset($grupos[$sucId][$prodId])) {
            $repetidos++;
            $grupos[$sucId][$prodId]['cantidad'] += $cantidad;
            $grupos[$sucId][$prodId]['filas'][] = $linea;
            if ($costo > 0) $grupos[$sucId][$prodId]['costo'] = $costo;
        } else {
            $grupos[$sucId][$prodId] = ['cantidad' => $cantidad, 'costo' => $costo, 'filas' => [$linea]];
        }
        $unidades += $cantidad;
    }

    if ($repetidos) {
        $avisos[] = ['fila' => 0, 'motivo' => $repetidos . ' fila(s) repiten un producto en la misma sucursal; sus cantidades se suman.'];
    }

    // Una sucursal solo admite un conteo abierto a la vez (regla de conteos.php).
    // Se avisa aquí, en la vista previa, y no a mitad de la escritura.
    $ocupadas = [];
    if ($grupos) {
        $ph = implode(',', array_fill(0, count($grupos), '?'));
        foreach (qAll("SELECT sucursal_id, numero FROM conteos
                        WHERE estado = 'abierto' AND sucursal_id IN ($ph)",
                      array_keys($grupos)) as $r) {
            $ocupadas[(int) $r['sucursal_id']] = $r['numero'];
        }
    }

    // Contra el stock de hoy, para que se vea la diferencia ANTES de aplicar.
    $docs = []; $conDiferencia = 0; $nombresSuc = array_flip($sucursales);
    foreach ($grupos as $sucId => $lineas) {
        if (isset($ocupadas[$sucId])) {
            $errores[] = ['fila' => 0, 'motivo' => 'La sucursal «' . ($nombresSuc[$sucId] ?? $sucId)
                . '» ya tiene el conteo ' . $ocupadas[$sucId] . ' abierto. Aplícalo o cancélalo antes de cargar otro.'];
            continue;
        }
        $ids = array_keys($lineas);
        $teorico = []; $costoCatalogo = [];
        foreach (array_chunk($ids, 500) as $tanda) {
            $ph = implode(',', array_fill(0, count($tanda), '?'));
            foreach (qAll("SELECT producto_id, cantidad FROM inventario_stock
                            WHERE sucursal_id = ? AND producto_id IN ($ph)",
                          array_merge([$sucId], $tanda)) as $r) {
                $teorico[(int) $r['producto_id']] = (float) $r['cantidad'];
            }
            // El conteo valora la diferencia con este costo. Si el archivo no lo
            // trae, se usa el del catálogo: dejarlo en cero valoraría en cero un
            // ajuste que sí mueve dinero.
            foreach (qAll("SELECT id, precio_compra FROM productos WHERE id IN ($ph)", $tanda) as $r) {
                $costoCatalogo[(int) $r['id']] = (float) $r['precio_compra'];
            }
        }
        $det = [];
        foreach ($lineas as $prodId => $d) {
            $t = $teorico[$prodId] ?? 0.0;
            if (abs($t - $d['cantidad']) > 0.0001) $conDiferencia++;
            $det[] = [
                'producto_id'    => $prodId,
                'stock_teorico'  => $t,
                'stock_contado'  => round($d['cantidad'], 3),
                'costo_unitario' => round($d['costo'] > 0 ? $d['costo'] : ($costoCatalogo[$prodId] ?? 0), 2),
            ];
        }
        $docs[] = [
            'sucursal_id' => $sucId,
            'sucursal'    => $nombresSuc[$sucId] ?? ('Sucursal ' . $sucId),
            'lineas'      => $det,
        ];
    }

    return [
        'docs' => $docs, 'errores' => $errores, 'avisos' => $avisos,
        'resumen' => [
            'filas'         => count($filas),
            'validos'       => array_sum(array_map(fn($g) => count($g['lineas']), $docs)),
            'nuevos'        => count($docs),          // conteos que se van a crear
            'existentes'    => 0,
            'rechazados'    => count($errores),
            'monto'         => 0.0,
            'unidades'      => round($unidades, 3),
            'con_diferencia' => $conDiferencia,
        ],
    ];
}

/* ============================================================
 *  Análisis · Packing list de un embarque
 * ============================================================ */

/**
 * Revisa el packing list contra una liquidación abierta.
 *
 * `liquidacion_detalles` es única por (liquidación, producto), así que el mismo
 * SKU repetido —lo normal cuando viene en varias cajas— se SUMA en una línea, y
 * el costo unitario se promedia ponderando por cantidad. Sumar cantidades y
 * quedarse con el último costo daría un FOB que no cuadra con la factura.
 */
function imp_analizar_embarque(array $filas, array $mapa, array $opts): array
{
    $errores = []; $avisos = [];
    $liqId = (int) ($opts['liquidacion_id'] ?? 0);
    if ($liqId <= 0) {
        return ['docs' => [], 'errores' => [['fila' => 0, 'motivo' => 'Escoge la liquidación destino.']], 'avisos' => [],
                'resumen' => ['filas' => count($filas), 'validos' => 0, 'nuevos' => 0, 'existentes' => 0,
                              'rechazados' => 1, 'monto' => 0.0]];
    }
    $liq = qOne("SELECT * FROM liquidaciones WHERE id = ?", [$liqId]);
    if (!$liq) {
        return ['docs' => [], 'errores' => [['fila' => 0, 'motivo' => 'Esa liquidación no existe.']], 'avisos' => [],
                'resumen' => ['filas' => count($filas), 'validos' => 0, 'nuevos' => 0, 'existentes' => 0,
                              'rechazados' => 1, 'monto' => 0.0]];
    }
    if (function_exists('liq_editable') && !liq_editable($liq)) {
        return ['docs' => [], 'errores' => [['fila' => 0, 'motivo' => 'La liquidación ' . $liq['numero']
                    . ' ya no se puede editar (estado: ' . $liq['estado'] . ').']], 'avisos' => [],
                'resumen' => ['filas' => count($filas), 'validos' => 0, 'nuevos' => 0, 'existentes' => 0,
                              'rechazados' => 1, 'monto' => 0.0]];
    }

    $idx = imp_indice_productos();
    $crear = !empty($opts['crear_productos']);
    // Los que exigen lote. La pantalla del embarque no deja agregarlos sin él y
    // el archivo tampoco puede: sin lote no hay trazabilidad ni vencimiento.
    $conLote = array_flip(array_map('intval', qCol("SELECT id FROM productos WHERE controla_lote = 1")));
    $yaEnLiq = [];
    foreach (qCol("SELECT producto_id FROM liquidacion_detalles WHERE liquidacion_id = ?", [$liqId]) as $p) {
        $yaEnLiq[(int) $p] = true;
    }

    $lineas = [];    // clave => línea agregada
    $nuevosProd = 0; $repetidos = 0; $fob = 0.0; $reemplazos = 0;

    foreach ($filas as $i => $fila) {
        $linea = $i + 2;
        $texto = imp_val($fila, $mapa, 'producto');
        $desc  = imp_val($fila, $mapa, 'descripcion');
        if ($texto === '' && $desc === '') { $errores[] = ['fila' => $linea, 'motivo' => 'Sin producto.']; continue; }

        $cantidadTxt = imp_val($fila, $mapa, 'cantidad');
        $cantidad = imp_num($cantidadTxt);
        if ($cantidad <= 0) {
            $errores[] = ['fila' => $linea, 'motivo' => 'Cantidad inválida (' . ($cantidadTxt ?: 'vacía') . ').'];
            continue;
        }

        // Por código o barras. Nunca por nombre: en un packing list la
        // descripción del proveedor casi nunca es la del catálogo.
        $prodId = imp_buscar_producto($texto, $idx, false);
        $clave = $prodId ? 'p' . $prodId : 'n' . imp_slug($texto !== '' ? $texto : $desc);

        if (!$prodId && !$crear) {
            $errores[] = ['fila' => $linea, 'motivo' => 'No existe el producto «' . ($texto ?: $desc)
                . '». Marca «dar de alta los que no existan» o cárgalo primero en el catálogo.'];
            continue;
        }
        if (!$prodId && $texto === '') {
            $errores[] = ['fila' => $linea, 'motivo' => 'Para dar de alta un producto hace falta su código, no solo la descripción.'];
            continue;
        }

        $costo = imp_num(imp_val($fila, $mapa, 'costo_moneda'));
        if ($costo < 0) { $errores[] = ['fila' => $linea, 'motivo' => 'Costo negativo.']; continue; }

        $lote = mb_substr(imp_val($fila, $mapa, 'lote'), 0, 60);
        if ($prodId && isset($conLote[$prodId]) && $lote === '') {
            $errores[] = ['fila' => $linea, 'motivo' => '«' . ($texto ?: $desc)
                . '» controla lote: el archivo tiene que traer el número de lote del embarque.'];
            continue;
        }

        if (isset($lineas[$clave])) {
            $repetidos++;
            $l = &$lineas[$clave];
            // Promedio ponderado: el FOB de la línea tiene que ser el de la factura.
            $totalPrevio = $l['cantidad'] * $l['costo_moneda'];
            $l['cantidad'] += $cantidad;
            $l['costo_moneda'] = $l['cantidad'] > 0 ? ($totalPrevio + $cantidad * $costo) / $l['cantidad'] : $costo;
            $l['peso']    += imp_num(imp_val($fila, $mapa, 'peso'));
            $l['volumen'] += imp_num(imp_val($fila, $mapa, 'volumen'));
            $l['filas'][] = $linea;
            unset($l);
        } else {
            if (!$prodId) $nuevosProd++;
            if ($prodId && isset($yaEnLiq[$prodId])) $reemplazos++;
            $lineas[$clave] = [
                'producto_id'  => $prodId,
                'codigo'       => $texto,
                'nombre'       => $desc !== '' ? $desc : $texto,
                'cantidad'     => $cantidad,
                'costo_moneda' => $costo,
                'peso'         => imp_num(imp_val($fila, $mapa, 'peso')),
                'volumen'      => imp_num(imp_val($fila, $mapa, 'volumen')),
                'lote'         => $lote,
                'vencimiento'  => imp_fecha(imp_val($fila, $mapa, 'vencimiento')),
                'ya_estaba'    => $prodId ? isset($yaEnLiq[$prodId]) : false,
                'filas'        => [$linea],
            ];
        }
    }

    foreach ($lineas as &$l) {
        $l['cantidad']     = round($l['cantidad'], 3);
        $l['costo_moneda'] = round($l['costo_moneda'], 4);
        $fob += $l['cantidad'] * $l['costo_moneda'];
    }
    unset($l);

    if ($repetidos) {
        $avisos[] = ['fila' => 0, 'motivo' => $repetidos . ' fila(s) repiten un SKU; se agrupan en una línea y el costo se promedia ponderando por cantidad.'];
    }
    if ($nuevosProd) {
        $avisos[] = ['fila' => 0, 'motivo' => $nuevosProd . ' producto(s) del embarque no están en el catálogo y se darán de alta con el costo de esta factura.'];
    }
    if ($reemplazos) {
        $avisos[] = ['fila' => 0, 'motivo' => $reemplazos . ' línea(s) ya estaban en la liquidación ' . $liq['numero'] . ' y se reemplazan con lo del archivo.'];
    }

    return [
        'docs' => array_values($lineas), 'errores' => $errores, 'avisos' => $avisos,
        'resumen' => [
            'filas'         => count($filas),
            'validos'       => count($lineas),
            'nuevos'        => $nuevosProd,
            'existentes'    => count($lineas) - $nuevosProd,
            'rechazados'    => count($errores),
            'monto'         => round($fob, 2),
            'liquidacion'   => $liq['numero'],
            'moneda'        => $liq['moneda_id'] ?? null,
        ],
    ];
}

/* ============================================================
 *  Carga definitiva
 * ============================================================ */

/**
 * Escribe en la base lo que devolvió imp_analizar(). Devuelve el id del lote.
 *
 * Se procesa en tandas: un año de ventas en una sola transacción bloquea la
 * tabla durante minutos y cualquier tropiezo tira las 12.000 filas. En tandas,
 * lo que entró queda, y el lote permite revertir todo igual.
 */
function imp_ejecutar(string $tipo, array $analisis, array $opts, string $archivo): int
{
    $tipo = imp_tipo_valido($tipo);
    $uid = (int) (current_user()['id'] ?? 0);
    $impId = dbInsert('importaciones', [
        'tipo'       => $tipo,
        'archivo'    => mb_substr($archivo, 0, 200),
        'filas'      => (int) $analisis['resumen']['filas'],
        'usuario_id' => $uid ?: null,
    ]);

    $creados = 0; $actualizados = 0; $monto = 0.0;
    $docs = $analisis['docs'];

    // Existencias y embarque no son filas sueltas: uno agrupa por sucursal y el
    // otro cuelga de un documento. Se escriben aparte y en su propia tanda.
    if ($tipo === 'existencias') {
        [$creados, $actualizados] = imp_grabar_existencias($docs, $impId, $uid, $opts);
    } elseif ($tipo === 'embarque') {
        [$creados, $actualizados, $monto] = imp_grabar_embarque($docs, $impId, $uid, $opts);
    } else {
        foreach (array_chunk($docs, 100) as $tanda) {
            $r = tx(function () use ($tanda, $tipo, $impId, $uid, $opts) {
                $c = 0; $a = 0; $m = 0.0;
                foreach ($tanda as $doc) {
                    if ($tipo === 'clientes') {
                        [$hecho, $esNuevo] = imp_grabar_cliente($doc, $impId, $uid, $opts);
                        if ($hecho) { $esNuevo ? $c++ : $a++; }
                    } elseif ($tipo === 'productos') {
                        [$hecho, $esNuevo] = imp_grabar_producto($doc, $impId, $uid, $opts);
                        if ($hecho) { $esNuevo ? $c++ : $a++; }
                    } else {
                        $m += imp_grabar_venta($doc, $impId, $uid, $opts);
                        $c++;
                    }
                }
                return [$c, $a, $m];
            });
            $creados += $r[0]; $actualizados += $r[1]; $monto += $r[2];
        }
    }

    dbUpdate('importaciones', [
        'creados'      => $creados,
        'actualizados' => $actualizados,
        'omitidos'     => (int) $analisis['resumen']['rechazados'] + (int) ($analisis['resumen']['existentes'] ?? 0),
        'monto'        => round($monto, 2),
        'detalle'      => json_encode([
            'mapa'    => $opts['mapa'] ?? [],
            'opts'    => array_diff_key($opts, ['mapa' => 1]),
            'avisos'  => array_slice($analisis['avisos'], 0, 100),
            'errores' => array_slice($analisis['errores'], 0, 300),
        ], JSON_UNESCAPED_UNICODE),
    ], 'id = ?', [$impId]);

    return $impId;
}

/** Crea o actualiza un cliente. Devuelve [bool grabado, bool esNuevo]. */
function imp_grabar_cliente(array $d, int $impId, int $uid, array $opts): array
{
    $datos = array_filter([
        'nombre'           => $d['nombre'],
        'rnc_cedula'       => $d['rnc_cedula'],
        'telefono'         => $d['telefono'],
        'email'            => $d['email'],
        'direccion'        => $d['direccion'],
        'fecha_nacimiento' => $d['fecha_nacimiento'],
    ], fn($v) => $v !== null && $v !== '');

    if ($d['existente_id']) {
        // Actualizar solo lo que venga con dato: un archivo sin la columna
        // teléfono no puede borrar los teléfonos que ya se tenían.
        if (empty($opts['actualizar_existentes'])) return [false, false];
        unset($datos['nombre']);
        if ($datos) dbUpdate('clientes', $datos, 'id = ?', [(int) $d['existente_id']]);
        return [true, false];
    }

    $datos['nombre']         = $d['nombre'];
    $datos['tipo']           = $d['tipo'];
    $datos['limite_credito'] = $d['limite_credito'];
    $datos['activo']         = 1;
    $datos['created_by']     = $uid ?: null;
    $datos['importacion_id'] = $impId;
    $datos['codigo']         = imp_codigo_cliente($d['codigo']);
    dbInsert('clientes', $datos);
    return [true, true];
}

/** Código libre para un cliente nuevo: respeta el del archivo si no está tomado. */
function imp_codigo_cliente(string $preferido): string
{
    $preferido = mb_substr(trim($preferido), 0, 20);
    if ($preferido !== '' && !qVal("SELECT 1 FROM clientes WHERE codigo = ?", [$preferido])) {
        return $preferido;
    }
    return nextNumero('clientes', 'codigo', 'CLI', 5);
}

/**
 * Graba una venta histórica. Devuelve el ingreso neto (subtotal − descuento).
 *
 * Lo que NO hace, y es deliberado: no toca stock, no consume NCF, no mueve
 * caja y no altera el balance del cliente. Ver la cabecera del archivo.
 */
function imp_grabar_venta(array $d, int $impId, int $uid, array $opts): float
{
    static $metodos = null, $metodoDef = null, $usuarios = null;
    if ($metodos === null) {
        $metodos = [];
        foreach (qAll("SELECT id, nombre FROM metodos_pago") as $m) $metodos[imp_slug($m['nombre'])] = (int) $m['id'];
        $metodoDef = (int) (qVal("SELECT id FROM metodos_pago WHERE activo=1 ORDER BY id LIMIT 1") ?: 0);
        $usuarios = [];
        foreach (qAll("SELECT id, nombre, apellido FROM usuarios") as $u) {
            $usuarios[imp_slug($u['nombre'] . ' ' . $u['apellido'])] = (int) $u['id'];
            $usuarios[imp_slug($u['nombre'])] = (int) $u['id'];
        }
    }

    // Cliente: se busca; solo se crea si la CEO lo pidió.
    $clienteId = null;
    if ($d['cliente'] !== '' || $d['cliente_rnc'] !== '') {
        $clienteId = imp_buscar_cliente($d['cliente'], $d['cliente_rnc'], '');
        if (!$clienteId && !empty($opts['crear_clientes']) && $d['cliente'] !== '') {
            $clienteId = dbInsert('clientes', [
                'codigo'         => nextNumero('clientes', 'codigo', 'CLI', 5),
                'nombre'         => $d['cliente'],
                'rnc_cedula'     => $d['cliente_rnc'] ?: null,
                'activo'         => 1,
                'created_by'     => $uid ?: null,
                'importacion_id' => $impId,
            ]);
        }
    }

    $numero = $d['numero'] !== '' ? $d['numero'] : nextNumero('ventas', 'numero', 'HIS');
    $vendedorId = $d['vendedor'] !== '' ? ($usuarios[imp_slug($d['vendedor'])] ?? $uid) : $uid;
    $metodoId = $d['metodo'] !== '' ? ($metodos[imp_slug($d['metodo'])] ?? $metodoDef) : $metodoDef;

    // Crédito fiscal o consumidor final según la serie del NCF del archivo.
    // B01 (preimpreso) y E31 (electrónico) son las series de crédito fiscal.
    $esFiscal = in_array(strtoupper(substr((string) $d['ncf'], 0, 3)), ['B01', 'E31'], true);

    $total = round($d['subtotal'] - $d['descuento'] + $d['itbis'], 2);

    $ventaId = dbInsert('ventas', [
        'numero'           => $numero,
        'sucursal_id'      => (int) $d['sucursal_id'],
        'tienda_id'        => $d['tienda_id'] ?: null,
        'importacion_id'   => $impId,
        'caja_sesion_id'   => null,     // no hubo caja: esta venta ocurrió en otro sistema
        'cliente_id'       => $clienteId,
        'usuario_id'       => $vendedorId ?: $uid,
        'fecha'            => $d['fecha'] . ' 12:00:00',
        'subtotal'         => $d['subtotal'],
        'descuento'        => $d['descuento'],
        'itbis'            => $d['itbis'],
        'total'            => $total,
        'costo_total'      => $d['costo'],
        'tipo_comprobante' => $esFiscal ? 'credito_fiscal' : 'consumidor',
        'ncf'              => $d['ncf'],
        'estado'           => 'completada',
        'canal_venta'      => $d['canal'],
        'notas'            => 'Venta histórica importada',
    ]);

    foreach ($d['lineas'] as $l) {
        dbInsert('venta_detalles', [
            'venta_id'        => $ventaId,
            'producto_id'     => $l['producto_id'],
            'descripcion'     => $l['descripcion'],
            'cantidad'        => $l['cantidad'],
            'precio_unitario' => max(0, $l['precio']),
            'costo_unitario'  => max(0, $l['costo_unit']),
            'descuento'       => 0,
            'itbis'           => max(0, $l['itbis']),
            'subtotal'        => max(0, $l['subtotal']),
        ]);
    }
    if ($metodoId) {
        dbInsert('venta_pagos', ['venta_id' => $ventaId, 'metodo_pago_id' => $metodoId, 'monto' => $total]);
    }

    return round($d['subtotal'] - $d['descuento'], 2);
}

/**
 * Id de una categoría, marca o unidad por nombre; la crea si se pidió.
 * Memoriza para no consultar dos veces el mismo nombre en un archivo de 3.000
 * filas donde «Maquillaje» se repite mil veces.
 */
function imp_catalogo_id(string $tabla, string $nombre, bool $crear): ?int
{
    static $cache = [];
    $nombre = trim($nombre);
    if ($nombre === '') return null;

    $clave = $tabla . '|' . imp_slug($nombre);
    if (array_key_exists($clave, $cache)) return $cache[$clave];

    $id = (int) (qVal("SELECT id FROM `$tabla` WHERE nombre = ? LIMIT 1", [$nombre]) ?: 0);
    if (!$id && $crear) {
        $datos = ['nombre' => mb_substr($nombre, 0, $tabla === 'unidades' ? 50 : 100)];
        if ($tabla !== 'unidades') $datos['activo'] = 1;
        // `unidades.abreviatura` es obligatoria: se saca del propio nombre.
        if ($tabla === 'unidades') $datos['abreviatura'] = mb_substr($nombre, 0, 10);
        $id = dbInsert($tabla, $datos);
    }
    return $cache[$clave] = ($id ?: null);
}

/** Código libre para un producto nuevo: respeta el del archivo si no está tomado. */
function imp_codigo_producto(string $preferido): string
{
    $preferido = mb_substr(trim($preferido), 0, 40);
    if ($preferido !== '' && !qVal("SELECT 1 FROM productos WHERE codigo = ?", [$preferido])) {
        return $preferido;
    }
    return nextNumero('productos', 'codigo', 'SKU', 5);
}

/**
 * Crea o actualiza un producto. Devuelve [bool grabado, bool esNuevo].
 *
 * Al actualizar se respeta lo que el archivo no trae: un catálogo sin la
 * columna «stock mínimo» no puede poner en cero los mínimos que ya estaban
 * configurados. Es la misma regla que en clientes.
 */
function imp_grabar_producto(array $d, int $impId, int $uid, array $opts): array
{
    $crearCat = !empty($opts['crear_catalogos']);
    $catId = imp_catalogo_id('categorias', $d['categoria'], $crearCat);
    $marId = imp_catalogo_id('marcas',     $d['marca'],     $crearCat);
    $uniId = imp_catalogo_id('unidades',   $d['unidad'],    $crearCat);

    // Solo lo que vino con dato. El código de barras vacío va como NULL y no
    // como cadena vacía: `codigo_barras` es único y dos cadenas vacías chocan,
    // mientras que dos NULL conviven.
    $datos = [];
    if ($d['nombre'] !== '')        $datos['nombre'] = $d['nombre'];
    if ($d['descripcion'] !== '')   $datos['descripcion'] = $d['descripcion'];
    if ($d['codigo_barras'] !== '') $datos['codigo_barras'] = $d['codigo_barras'];
    if ($catId)                     $datos['categoria_id'] = $catId;
    if ($marId)                     $datos['marca_id'] = $marId;
    if ($uniId)                     $datos['unidad_id'] = $uniId;
    if ($d['tienda_id'])            $datos['tienda_id'] = $d['tienda_id'];
    if ($d['precio_compra'] > 0)    $datos['precio_compra'] = $d['precio_compra'];
    if ($d['precio_venta'] > 0)     $datos['precio_venta'] = $d['precio_venta'];
    if ($d['stock_minimo'] > 0)     $datos['stock_minimo'] = $d['stock_minimo'];
    if ($d['pais_origen'] !== '')   $datos['pais_origen'] = $d['pais_origen'];
    if ($d['fabricante'] !== '')    $datos['fabricante'] = $d['fabricante'];

    if ($d['existente_id']) {
        if (empty($opts['actualizar_existentes'])) return [false, false];
        // El nombre no se pisa al actualizar: quien ya tenía el producto en el
        // sistema probablemente le puso un nombre mejor que el del proveedor.
        unset($datos['nombre']);
        if ($datos) dbUpdate('productos', $datos, 'id = ?', [(int) $d['existente_id']]);
        return [true, false];
    }

    $datos['nombre']         = $d['nombre'];
    $datos['codigo']         = imp_codigo_producto($d['codigo']);
    $datos['tipo']           = 'producto';
    $datos['itbis_aplica']   = $d['itbis_aplica'];
    $datos['activo']         = 1;
    $datos['importacion_id'] = $impId;
    dbInsert('productos', $datos);
    return [true, true];
}

/**
 * Deja un conteo físico en borrador por sucursal. NO escribe stock.
 *
 * Devuelve [conteos creados, líneas escritas]. Cada conteo va en su propia
 * transacción: si la tercera sucursal falla, las dos primeras quedan cargadas y
 * el lote las revierte igual.
 */
function imp_grabar_existencias(array $docs, int $impId, int $uid, array $opts): array
{
    $conteos = 0; $lineas = 0;
    $descripcion = mb_substr(trim((string) ($opts['descripcion'] ?? '')) ?: 'Carga de existencias', 0, 150);

    foreach ($docs as $doc) {
        $n = tx(function () use ($doc, $impId, $uid, $opts, $descripcion) {
            $conteoId = dbInsert('conteos', [
                'numero'         => nextNumero('conteos', 'numero', 'CNT'),
                'sucursal_id'    => (int) $doc['sucursal_id'],
                'descripcion'    => $descripcion . ' · ' . $doc['sucursal'],
                'estado'         => 'abierto',
                'notas'          => 'Cargado desde archivo. Revisa las diferencias antes de aplicar: '
                                    . 'al aplicar se mueve el stock por la diferencia y queda en el kardex.',
                'usuario_id'     => $uid ?: null,
                'importacion_id' => $impId,
            ]);
            $escritas = 0;
            foreach (array_chunk($doc['lineas'], 200) as $tanda) {
                foreach ($tanda as $l) {
                    dbInsert('conteo_detalles', [
                        'conteo_id'      => $conteoId,
                        'producto_id'    => (int) $l['producto_id'],
                        'stock_teorico'  => $l['stock_teorico'],
                        'stock_contado'  => $l['stock_contado'],
                        'costo_unitario' => (float) $l['costo_unitario'],
                        'contado_por'    => $uid ?: null,
                        'contado_at'     => date('Y-m-d H:i:s'),
                    ]);
                    $escritas++;
                }
            }
            return $escritas;
        });
        $conteos++; $lineas += $n;
    }
    return [$conteos, $lineas];
}

/**
 * Vuelca el packing list en la liquidación destino.
 *
 * Devuelve [líneas nuevas, líneas reemplazadas, FOB en la moneda del embarque].
 * Al final recalcula la liquidación una sola vez: hacerlo por línea repartiría
 * los gastos 300 veces para llegar al mismo sitio.
 */
function imp_grabar_embarque(array $docs, int $impId, int $uid, array $opts): array
{
    $liqId = (int) ($opts['liquidacion_id'] ?? 0);
    $liq = qOne("SELECT * FROM liquidaciones WHERE id = ?", [$liqId]);
    if (!$liq) throw new RuntimeException('La liquidación destino ya no existe.');
    $tasa = (float) ($liq['tasa_cambio'] ?: 1);

    $nuevas = 0; $reemplazadas = 0; $fob = 0.0;

    foreach (array_chunk($docs, 100) as $tanda) {
        $r = tx(function () use ($tanda, $liqId, $tasa, $impId, $uid, $opts) {
            $n = 0; $rep = 0; $f = 0.0;
            foreach ($tanda as $d) {
                $prodId = (int) ($d['producto_id'] ?? 0);

                // Producto que llega por primera vez en este contenedor.
                if (!$prodId) {
                    $prodId = dbInsert('productos', [
                        'codigo'         => imp_codigo_producto($d['codigo']),
                        'nombre'         => mb_substr($d['nombre'], 0, 180),
                        'tipo'           => 'producto',
                        'precio_compra'  => round($d['costo_moneda'] * $tasa, 2),
                        'itbis_aplica'   => 1,
                        'activo'         => 1,
                        'importacion_id' => $impId,
                    ]);
                }

                $datos = [
                    'cantidad'       => $d['cantidad'],
                    'costo_moneda'   => $d['costo_moneda'],
                    'costo_fob'      => round($d['costo_moneda'] * $tasa, 4),
                    'peso'           => max(0, (float) $d['peso']),
                    'volumen'        => max(0, (float) $d['volumen']),
                    'lote'           => $d['lote'] !== '' ? $d['lote'] : null,
                    'vencimiento'    => $d['vencimiento'],
                    'importacion_id' => $impId,
                ];

                // UNIQUE (liquidacion_id, producto_id): repetir es corregir.
                $ya = qVal("SELECT id FROM liquidacion_detalles WHERE liquidacion_id = ? AND producto_id = ?",
                           [$liqId, $prodId]);
                if ($ya) {
                    dbUpdate('liquidacion_detalles', $datos, 'id = ?', [(int) $ya]);
                    $rep++;
                } else {
                    $datos['liquidacion_id'] = $liqId;
                    $datos['producto_id']    = $prodId;
                    dbInsert('liquidacion_detalles', $datos);
                    $n++;
                }
                $f += $d['cantidad'] * $d['costo_moneda'];
            }
            return [$n, $rep, $f];
        });
        $nuevas += $r[0]; $reemplazadas += $r[1]; $fob += $r[2];
    }

    liq_recalcular($liqId);
    return [$nuevas, $reemplazadas, round($fob, 2)];
}

/* ============================================================
 *  Reversión y consulta de lotes
 * ============================================================ */

/**
 * Deshace un lote completo.
 *
 * Borra las ventas del lote (sus líneas y pagos caen por CASCADE) y los
 * clientes que nacieron con él y todavía no tienen movimiento propio. Un
 * cliente que ya compró de verdad NO se borra: se le quita la marca del lote y
 * se queda, porque borrarlo se llevaría por delante ventas reales.
 */
function imp_revertir(int $id): array
{
    return tx(function () use ($id) {
        $imp = qOne("SELECT * FROM importaciones WHERE id = ? FOR UPDATE", [$id]);
        if (!$imp) throw new RuntimeException('Ese lote de carga no existe.');
        if ($imp['estado'] === 'revertida') throw new RuntimeException('Ese lote ya se revirtió.');

        switch ($imp['tipo']) {
            case 'productos':   $r = imp_revertir_productos($id);   break;
            case 'existencias': $r = imp_revertir_existencias($id); break;
            case 'embarque':    $r = imp_revertir_embarque($id);    break;
            default:            $r = null;
        }
        if ($r !== null) {
            dbUpdate('importaciones', [
                'estado'       => 'revertida',
                'revertida_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);
            return $r;
        }

        $ventas = (int) qVal("SELECT COUNT(*) FROM ventas WHERE importacion_id = ?", [$id]);
        q("DELETE FROM ventas WHERE importacion_id = ?", [$id]);

        // Clientes del lote que no dejaron rastro en ninguna otra parte.
        $borrables = qCol(
            "SELECT c.id FROM clientes c
              WHERE c.importacion_id = ?
                AND c.balance = 0
                AND NOT EXISTS (SELECT 1 FROM ventas v WHERE v.cliente_id = c.id)
                AND NOT EXISTS (SELECT 1 FROM pagos_clientes p WHERE p.cliente_id = c.id)",
            [$id]
        );
        $clientes = 0;
        if ($borrables) {
            $ph = implode(',', array_fill(0, count($borrables), '?'));
            q("DELETE FROM clientes WHERE id IN ($ph)", $borrables);
            $clientes = count($borrables);
        }
        $conservados = (int) qVal("SELECT COUNT(*) FROM clientes WHERE importacion_id = ?", [$id]);
        q("UPDATE clientes SET importacion_id = NULL WHERE importacion_id = ?", [$id]);

        dbUpdate('importaciones', [
            'estado'       => 'revertida',
            'revertida_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        return ['ventas' => $ventas, 'clientes' => $clientes, 'clientes_conservados' => $conservados];
    });
}

/**
 * Deshace una carga de catálogo.
 *
 * Borra los productos que no dejaron rastro. El que ya se vendió, ya tiene
 * existencia o ya entró en un embarque **se conserva** y solo pierde la marca
 * del lote: borrarlo se llevaría por delante ventas reales y dejaría el
 * histórico apuntando a un id que no existe.
 */
function imp_revertir_productos(int $id): array
{
    $borrables = qCol(
        "SELECT p.id FROM productos p
          WHERE p.importacion_id = ?
            AND NOT EXISTS (SELECT 1 FROM venta_detalles vd WHERE vd.producto_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM liquidacion_detalles ld WHERE ld.producto_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM conteo_detalles cd WHERE cd.producto_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM movimientos_inventario mi WHERE mi.producto_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM inventario_stock st WHERE st.producto_id = p.id AND st.cantidad <> 0)",
        [$id]
    );
    $borrados = 0;
    if ($borrables) {
        foreach (array_chunk($borrables, 200) as $tanda) {
            $ph = implode(',', array_fill(0, count($tanda), '?'));
            // Las existencias en cero no son rastro, pero sí una fila que
            // estorba a la clave foránea.
            q("DELETE FROM inventario_stock WHERE producto_id IN ($ph)", $tanda);
            q("DELETE FROM productos WHERE id IN ($ph)", $tanda);
            $borrados += count($tanda);
        }
    }
    $conservados = (int) qVal("SELECT COUNT(*) FROM productos WHERE importacion_id = ?", [$id]);
    q("UPDATE productos SET importacion_id = NULL WHERE importacion_id = ?", [$id]);

    return ['productos' => $borrados, 'productos_conservados' => $conservados];
}

/**
 * Deshace una carga de existencias.
 *
 * Solo si NINGÚN conteo del lote se aplicó. Un conteo aplicado ya movió el
 * stock y lo dejó escrito en el kardex: borrar el conteo dejaría el movimiento
 * huérfano, apuntando a un documento que no existe. Eso se corrige con otro
 * conteo, no borrando el rastro.
 */
function imp_revertir_existencias(int $id): array
{
    $aplicados = qAll("SELECT numero FROM conteos WHERE importacion_id = ? AND estado = 'aplicado'", [$id]);
    if ($aplicados) {
        $nums = implode(', ', array_column($aplicados, 'numero'));
        throw new RuntimeException(
            'No se puede revertir: ' . count($aplicados) . ' conteo(s) de este lote ya se aplicaron (' . $nums . ') '
            . 'y su ajuste está en el kardex. Corrígelo con un conteo nuevo, no borrando el que dejó el movimiento.'
        );
    }

    $ids = array_map('intval', qCol("SELECT id FROM conteos WHERE importacion_id = ?", [$id]));
    $lineas = 0;
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $lineas = (int) qVal("SELECT COUNT(*) FROM conteo_detalles WHERE conteo_id IN ($ph)", $ids);
        q("DELETE FROM conteo_detalles WHERE conteo_id IN ($ph)", $ids);
        q("DELETE FROM conteos WHERE id IN ($ph)", $ids);
    }
    return ['conteos' => count($ids), 'lineas' => $lineas];
}

/**
 * Deshace la carga de un packing list.
 *
 * Solo si la liquidación sigue editable. Si ya se aplicó, el costo del embarque
 * está fijado en el catálogo y el stock entró: quitarle las líneas dejaría un
 * documento aplicado que no explica el costo que puso. Para eso está la
 * anulación de la liquidación, que sí sabe devolver stock y costo.
 */
function imp_revertir_embarque(int $id): array
{
    $liqs = qAll(
        "SELECT DISTINCT l.id, l.numero, l.estado
           FROM liquidacion_detalles d JOIN liquidaciones l ON l.id = d.liquidacion_id
          WHERE d.importacion_id = ?",
        [$id]
    );
    foreach ($liqs as $l) {
        if (!liq_editable($l)) {
            throw new RuntimeException(
                'No se puede revertir: la liquidación ' . $l['numero'] . ' está ' . $l['estado']
                . ' y su costo ya quedó en el catálogo. Anula la liquidación desde su pantalla, '
                . 'que sí sabe devolver el stock y el costo anterior.'
            );
        }
    }

    $lineas = (int) qVal("SELECT COUNT(*) FROM liquidacion_detalles WHERE importacion_id = ?", [$id]);
    q("DELETE FROM liquidacion_detalles WHERE importacion_id = ?", [$id]);
    foreach ($liqs as $l) liq_recalcular((int) $l['id']);

    // Los productos que nacieron con el embarque siguen la misma regla que en
    // una carga de catálogo: se van los que no dejaron rastro.
    $prod = imp_revertir_productos($id);

    return [
        'lineas'       => $lineas,
        'liquidaciones' => count($liqs),
        'productos'    => $prod['productos'],
        'productos_conservados' => $prod['productos_conservados'],
    ];
}

/** Últimos lotes cargados, para la pantalla del historial. */
function imp_lotes(int $limite = 25): array
{
    if (!imp_disponible()) return [];
    return qAll(
        "SELECT i.*, CONCAT(u.nombre,' ',u.apellido) AS usuario
           FROM importaciones i LEFT JOIN usuarios u ON u.id = i.usuario_id
          ORDER BY i.id DESC LIMIT " . max(1, $limite)
    );
}

/** Borra archivos de carga con más de una semana: son datos de clientes. */
function imp_limpiar_archivos(int $dias = 7): void
{
    $dir = imp_dir();
    foreach (glob($dir . '/imp_*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - $dias * 86400) @unlink($f);
    }
}
