<?php
/**
 * API del escáner de almacén. Responde JSON al terminal del teléfono
 * (`modules/inventario/escaner.php`) y al campo de código de barras de Productos.
 *
 * Acciones:
 *   buscar  — qué producto es este código (y cuánto hay en la sucursal activa)
 *   mover   — registrar una entrada o una salida de mercancía
 *   contar  — capturar una cantidad dentro de un conteo físico abierto
 *
 * Cada acción comprueba SU permiso por separado: ver el catálogo no da derecho a
 * mover inventario. La sucursal nunca la elige el teléfono, la impone el servidor
 * a partir de la sucursal activa de la sesión.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_salir(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) api_salir(401, ['ok' => false, 'error' => 'Sesión expirada. Vuelve a iniciar sesión.']);

$in = [];
if (isPost()) {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    if (!is_array($in)) $in = $_POST;

    // CSRF por cabecera: el fetch la manda, el token vive en la página.
    if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
        api_salir(419, ['ok' => false, 'error' => 'Token de seguridad inválido. Recarga la pantalla.']);
    }
} else {
    $in = $_GET;
}

$accion = (string) ($in['accion'] ?? 'buscar');
$sid    = current_sucursal_id();

/** Ficha del producto tal como la muestra el teléfono. */
function api_ficha(array $p, ?int $sid): array
{
    $pid = (int) $p['id'];

    // Marca, categoría y unidad en una sola consulta: el almacén escanea sin
    // parar y cuatro viajes a la base por lectura se notan.
    $ref = qOne(
        "SELECT m.nombre AS marca, c.nombre AS categoria, u.abreviatura AS unidad
           FROM productos p
           LEFT JOIN marcas m ON m.id = p.marca_id
           LEFT JOIN categorias c ON c.id = p.categoria_id
           LEFT JOIN unidades u ON u.id = p.unidad_id
          WHERE p.id = ?",
        [$pid]
    ) ?: [];

    // Existencias por sucursal: en el almacén la pregunta constante es «no queda
    // aquí, ¿hay en la otra tienda?». Pero SOLO las sucursales que este usuario
    // puede ver: a un cajero atado a una sucursal no se le enseñan las demás,
    // igual que no las ve en Stock ni en el buscador global.
    $porSucursal = [];
    foreach (qAll(
        "SELECT s.id, s.nombre, COALESCE(i.cantidad,0) AS cantidad
           FROM sucursales s
           LEFT JOIN inventario_stock i ON i.sucursal_id = s.id AND i.producto_id = ?
          WHERE s.activo = 1 ORDER BY s.nombre",
        [$pid]
    ) as $s) {
        if (!can_access_sucursal((int) $s['id'])) continue;
        $porSucursal[] = ['id' => (int) $s['id'], 'nombre' => $s['nombre'], 'cantidad' => (float) $s['cantidad']];
    }

    // El total solo puede sumar lo que el usuario tiene permitido ver.
    $stock = $sid === null
        ? array_sum(array_column($porSucursal, 'cantidad'))
        : stockActual($pid, $sid);

    return [
        'id'            => $pid,
        'codigo'        => $p['codigo'],
        'codigo_barras' => $p['codigo_barras'],
        'nombre'        => $p['nombre'],
        'marca'         => $ref['marca'] ?? null,
        'categoria'     => $ref['categoria'] ?? null,
        'unidad'        => $ref['unidad'] ?: 'u',
        'tipo'          => $p['tipo'],
        'activo'        => (int) $p['activo'],
        'precio_venta'  => (float) $p['precio_venta'],
        'precio_compra' => (float) $p['precio_compra'],
        'stock_minimo'  => (float) $p['stock_minimo'],
        'stock'         => $stock,
        'imagen'        => $p['imagen'] ? url($p['imagen']) : null,
        'sucursales'    => $porSucursal,
    ];
}

/* ============================================================
 *  buscar
 * ============================================================ */
if ($accion === 'buscar') {
    if (!can('productos.ver') && !can('inventario.ver')) {
        api_salir(403, ['ok' => false, 'error' => 'Sin permiso para consultar el catálogo.']);
    }
    $codigo = barcode_normalizar((string) ($in['codigo'] ?? ''));
    if ($codigo === '') api_salir(400, ['ok' => false, 'error' => 'No se recibió ningún código.']);

    $p = barcode_buscar_producto($codigo);
    if (!$p) {
        api_salir(200, [
            'ok' => false, 'encontrado' => false, 'codigo' => $codigo,
            'error' => 'Ningún producto tiene el código ' . $codigo . '.',
            // Permite ofrecer «crear producto con este código» sin otra consulta.
            'valido' => barcode_validar($codigo)['ok'],
        ]);
    }
    api_salir(200, ['ok' => true, 'encontrado' => true, 'codigo' => $codigo, 'producto' => api_ficha($p, $sid)]);
}

/* ============================================================
 *  mover  (entrada / salida de almacén)
 * ============================================================ */
if ($accion === 'mover') {
    if (!isPost()) api_salir(405, ['ok' => false, 'error' => 'Método no permitido.']);
    if (!can('inventario.ajustar')) api_salir(403, ['ok' => false, 'error' => 'Sin permiso para ajustar inventario.']);
    if ($sid === null) api_salir(400, ['ok' => false, 'error' => 'Elige una sucursal en la barra superior: una entrada de almacén tiene que ir a una sucursal concreta.']);
    // La sucursal activa se puede fijar en la sesión; se comprueba que sea una a
    // la que este usuario tenga derecho antes de moverle el inventario.
    if (!can_access_sucursal($sid)) api_salir(403, ['ok' => false, 'error' => 'No tienes acceso a esa sucursal.']);

    $productoId = (int) ($in['producto_id'] ?? 0);
    $cantidad   = round((float) ($in['cantidad'] ?? 0), 3);
    $tipo       = ($in['tipo'] ?? '') === 'salida' ? 'salida' : 'entrada';
    $motivo     = trim((string) ($in['motivo'] ?? ''));

    if ($productoId <= 0) api_salir(400, ['ok' => false, 'error' => 'Producto no válido.']);
    if ($cantidad <= 0)   api_salir(400, ['ok' => false, 'error' => 'La cantidad debe ser mayor que cero.']);

    $p = qOne("SELECT * FROM productos WHERE id = ?", [$productoId]);
    if (!$p) api_salir(404, ['ok' => false, 'error' => 'El producto ya no existe.']);
    if ($p['tipo'] !== 'producto') api_salir(422, ['ok' => false, 'error' => 'Un servicio no lleva existencias.']);

    $delta = $tipo === 'salida' ? -$cantidad : $cantidad;

    try {
        // txReintentable, no tx: mover stock choca con las ventas de la caja y con
        // otras sucursales. Ver docs/CONCURRENCIA.md.
        $nuevo = txReintentable(function () use ($productoId, $sid, $delta, $tipo, $p, $motivo) {
            return ajustarStock(
                $productoId, $sid, $delta, $tipo, 'escaner', null,
                (float) $p['precio_compra'],
                $motivo !== '' ? $motivo : ('Escáner de almacén · ' . ($tipo === 'salida' ? 'salida' : 'entrada'))
            );
        });
    } catch (Throwable $e) {
        api_salir(422, ['ok' => false, 'error' => $e->getMessage()]);
    }

    audit('inventario', 'ajustar',
        ($tipo === 'salida' ? 'Salida' : 'Entrada') . ' por escáner: ' . qty($cantidad) . ' de ' . $p['nombre'],
        ['tabla' => 'productos', 'registro_id' => $productoId]);

    api_salir(200, [
        'ok' => true, 'stock' => $nuevo, 'tipo' => $tipo,
        'mensaje' => ($tipo === 'salida' ? 'Salida' : 'Entrada') . ' de ' . qty($cantidad) . ' · '
                   . $p['nombre'] . ' · quedan ' . qty($nuevo),
    ]);
}

/* ============================================================
 *  contar  (captura dentro de un conteo físico abierto)
 * ============================================================ */
if ($accion === 'contar') {
    if (!isPost()) api_salir(405, ['ok' => false, 'error' => 'Método no permitido.']);
    if (!can('conteos.contar')) api_salir(403, ['ok' => false, 'error' => 'Sin permiso para capturar conteos.']);

    $conteoId   = (int) ($in['conteo_id'] ?? 0);
    $productoId = (int) ($in['producto_id'] ?? 0);
    $cantidad   = round((float) ($in['cantidad'] ?? 0), 3);
    // 'sumar' es lo natural escaneando: cada lectura suma una unidad a lo ya contado.
    $modo       = ($in['modo'] ?? 'sumar') === 'fijar' ? 'fijar' : 'sumar';

    if ($cantidad < 0) api_salir(400, ['ok' => false, 'error' => 'La cantidad no puede ser negativa.']);

    $c = qOne("SELECT * FROM conteos WHERE id = ?", [$conteoId]);
    if (!$c) api_salir(404, ['ok' => false, 'error' => 'El conteo no existe.']);
    if ($c['estado'] !== 'abierto') api_salir(409, ['ok' => false, 'error' => 'Este conteo ya fue cerrado.']);
    if (!can_access_sucursal((int) $c['sucursal_id'])) api_salir(403, ['ok' => false, 'error' => 'Ese conteo es de otra sucursal.']);

    try {
        $r = txReintentable(function () use ($conteoId, $productoId, $cantidad, $modo) {
            $d = qOne(
                "SELECT id, stock_contado, stock_teorico FROM conteo_detalles
                  WHERE conteo_id = ? AND producto_id = ? FOR UPDATE",
                [$conteoId, $productoId]
            );
            // El alcance del conteo se congela al abrirlo. Si el producto no está
            // dentro, se avisa en vez de inventar una línea: contar algo fuera del
            // alcance descuadraría el avance y el impacto calculado.
            if (!$d) throw new RuntimeException('Este producto no forma parte del conteo (quedó fuera del alcance elegido al abrirlo).');

            // Leer-sumar-escribir dentro del FOR UPDATE: dos personas escaneando el
            // mismo pasillo no se pisan la cuenta.
            $nuevo = $modo === 'fijar'
                ? $cantidad
                : round((float) ($d['stock_contado'] ?? 0) + $cantidad, 3);

            dbUpdate('conteo_detalles', [
                'stock_contado' => $nuevo,
                'contado_por'   => current_user()['id'] ?? null,
                'contado_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $d['id']]);

            return ['contado' => $nuevo, 'teorico' => (float) $d['stock_teorico']];
        });
    } catch (Throwable $e) {
        api_salir(422, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $dif = round($r['contado'] - $r['teorico'], 3);
    api_salir(200, [
        'ok' => true, 'contado' => $r['contado'], 'teorico' => $r['teorico'], 'diferencia' => $dif,
        'mensaje' => 'Contado: ' . qty($r['contado']) . ' · sistema ' . qty($r['teorico'])
                   . ' · ' . ($dif == 0 ? 'cuadra' : (($dif > 0 ? 'sobran ' : 'faltan ') . qty(abs($dif)))),
    ]);
}

/* ============================================================
 *  nuevo_codigo  (reserva un código interno para el formulario de producto)
 * ============================================================ */
if ($accion === 'nuevo_codigo') {
    if (!isPost()) api_salir(405, ['ok' => false, 'error' => 'Método no permitido.']);
    if (!can('productos.crear') && !can('productos.editar')) {
        api_salir(403, ['ok' => false, 'error' => 'Sin permiso para editar productos.']);
    }
    try {
        // Reserva de verdad: el número se consume aquí aunque el usuario luego
        // cancele el formulario. Un hueco en la numeración interna no le importa
        // a nadie; dos productos con el mismo código, sí.
        api_salir(200, ['ok' => true, 'codigo' => barcode_generar_interno()]);
    } catch (Throwable $e) {
        api_salir(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

api_salir(400, ['ok' => false, 'error' => 'Acción no reconocida.']);
