<?php
/**
 * Carga histórica de clientes y ventas (área de Dirección).
 *
 * La CEO llega con un año entero de operaciones en un Excel del sistema
 * anterior. Este módulo lo mete en NexoPOS sin romper nada de lo que ya está
 * funcionando.
 *
 * CUATRO REGLAS QUE DEFINEN EL COMPORTAMIENTO
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

/** Carpeta privada donde reposa el archivo entre la vista previa y la carga. */
function imp_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/importaciones';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
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
    if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {
        return date('Y-m-d', (int) round(((float) $s - 25569) * 86400));
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
    return $tipo === 'clientes'
        ? imp_analizar_clientes($filas, $mapa, $opts)
        : imp_analizar_ventas($filas, $mapa, $opts);
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
    $uid = (int) (current_user()['id'] ?? 0);
    $impId = dbInsert('importaciones', [
        'tipo'       => $tipo === 'clientes' ? 'clientes' : 'ventas',
        'archivo'    => mb_substr($archivo, 0, 200),
        'filas'      => (int) $analisis['resumen']['filas'],
        'usuario_id' => $uid ?: null,
    ]);

    $creados = 0; $actualizados = 0; $monto = 0.0;
    $docs = $analisis['docs'];
    $tandas = array_chunk($docs, 100);

    foreach ($tandas as $tanda) {
        $r = tx(function () use ($tanda, $tipo, $impId, $uid, $opts) {
            $c = 0; $a = 0; $m = 0.0;
            foreach ($tanda as $doc) {
                if ($tipo === 'clientes') {
                    [$hecho, $esNuevo] = imp_grabar_cliente($doc, $impId, $uid, $opts);
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
