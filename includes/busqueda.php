<?php
/**
 * Búsqueda global.
 *
 * Un solo cuadro que encuentra productos, clientes, facturas, proveedores,
 * compras, empleados y oportunidades, además de llevarte a cualquier pantalla
 * del sistema. Respeta SIEMPRE los permisos del usuario y la sucursal activa:
 * lo que no puedes ver en su módulo tampoco aparece aquí.
 */

const BUSQUEDA_MIN = 2;      // caracteres mínimos para consultar la base
const BUSQUEDA_TOPE = 6;     // resultados por grupo

/**
 * Neutraliza los comodines de LIKE en el texto del usuario.
 *
 * Sin esto, buscar «50%» o «SKU_1» devuelve medio catálogo: MySQL interpreta
 * `%` como «cualquier cosa» y `_` como «un carácter». No es un problema de
 * seguridad (las consultas van preparadas), sino de resultados que no tienen
 * nada que ver con lo que se escribió.
 */
function buscar_like(string $q): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);
}

/**
 * Minúsculas y sin tildes, para comparar lo que se escribe con lo que hay.
 *
 * ============================================================================
 *  strtr CON DOS CADENAS TRABAJA BYTE A BYTE. Aquí eso destroza el UTF-8.
 * ============================================================================
 *
 * La versión anterior hacía `strtr($s, 'áéíóú…', 'aeiou…')`. Las tildes ocupan
 * DOS bytes cada una, así que la cadena de origen medía 24 bytes y la de
 * destino 12: strtr recortaba al mínimo y mapeaba medios caracteres.
 *
 *     «Nómina»    →  «nnimina»
 *     «Dirección» →  «direccinin»
 *
 * Resultado: **ninguna pantalla con tilde se podía encontrar escribiendo sin
 * tilde**, que es justo como escribe todo el mundo. La forma de array de strtr
 * sí entiende claves multibyte.
 */
function buscar_normalizar(string $s): string
{
    return strtr(mb_strtolower(trim($s)), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
}

/**
 * ¿El texto es un importe? Devuelve el número, o null si no lo es.
 *
 * Acepta lo que la gente escribe de verdad: «1180», «1,180.00», «RD$ 1180».
 * Se descartan los números cortos —hasta dos cifras— porque «12» es casi
 * siempre parte de un código y buscar todas las ventas de 12 pesos solo
 * añade ruido.
 */
function buscar_importe(string $q): ?float
{
    $limpio = str_replace([',', ' ', 'RD$', 'rd$', '$'], '', trim($q));
    if ($limpio === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $limpio)) return null;
    if (strlen(preg_replace('/\D/', '', $limpio)) < 3) return null;
    return (float) $limpio;
}

/**
 * Ejecuta la búsqueda.
 *
 * @return array<int,array{grupo:string,icono:string,color:string,items:array}>
 */
function buscar_global(string $q, int $tope = BUSQUEDA_TOPE): array
{
    $q = trim($q);
    $grupos = [];

    // Navegación y acciones responden desde el primer carácter: son locales.
    if ($q !== '') {
        $nav = buscar_navegacion($q);
        if ($nav) $grupos[] = ['grupo' => 'Ir a', 'icono' => 'grid', 'color' => 'slate', 'items' => $nav];
    }
    if (mb_strlen($q) < BUSQUEDA_MIN) return $grupos;

    $escapado = buscar_like($q);
    $like   = '%' . $escapado . '%';
    $exacto = $escapado . '%';

    /* ---------- Productos ---------- */
    if (can('productos.ver')) {
        [$wStock, $pStock] = sucursalScope('s.sucursal_id');
        $rows = qAll(
            "SELECT p.id, p.codigo, p.nombre, p.precio_venta, p.activo,
                    COALESCE(c.nombre,'Sin categoría') AS categoria,
                    COALESCE((SELECT SUM(s.cantidad) FROM inventario_stock s
                               WHERE s.producto_id = p.id AND $wStock),0) AS existencia
               FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id
              WHERE p.codigo LIKE ? OR p.codigo_barras LIKE ? OR p.nombre LIKE ?
              ORDER BY (p.codigo LIKE ?) DESC, p.activo DESC, p.nombre LIMIT $tope",
            array_merge($pStock, [$like, $like, $like, $exacto])
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'   => $r['nombre'],
                'subtitulo' => $r['codigo'] . ' · ' . $r['categoria'] . ' · ' . money($r['precio_venta']),
                'etiqueta' => qty($r['existencia']) . ' en stock',
                'etiqueta_color' => (float) $r['existencia'] > 0 ? 'emerald' : 'rose',
                'url'      => url('modules/inventario/productos.php?q=' . urlencode($r['codigo'])),
                'inactivo' => !$r['activo'],
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Productos', 'icono' => 'box', 'color' => 'blue', 'items' => $items];
    }

    /* ---------- Clientes ---------- */
    if (can('clientes.ver')) {
        $rows = qAll(
            "SELECT id, codigo, nombre, rnc_cedula, telefono, balance, tipo
               FROM clientes
              WHERE codigo LIKE ? OR nombre LIKE ? OR rnc_cedula LIKE ? OR telefono LIKE ?
              ORDER BY (nombre LIKE ?) DESC, nombre LIMIT $tope",
            [$like, $like, $like, $like, $exacto]
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'   => $r['nombre'],
                'subtitulo' => trim($r['codigo'] . ($r['rnc_cedula'] ? ' · ' . $r['rnc_cedula'] : '') . ($r['telefono'] ? ' · ' . $r['telefono'] : '')),
                'etiqueta' => (float) $r['balance'] > 0 ? 'Debe ' . money($r['balance']) : ucfirst($r['tipo']),
                'etiqueta_color' => (float) $r['balance'] > 0 ? 'amber' : 'slate',
                'url'      => can('crm.ver')
                    ? url('modules/crm/cliente.php?id=' . (int) $r['id'])
                    : url('modules/pos/clientes.php?q=' . urlencode($r['codigo'])),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Clientes', 'icono' => 'users', 'color' => 'cyan', 'items' => $items];
    }

    /* ---------- Ventas ----------
       También por IMPORTE. Quien llama a reclamar casi nunca trae el número de
       factura: trae «una compra de mil ciento ochenta pesos». Se acepta el
       número con o sin separadores —1180, 1,180.00, 1180.00— y se busca con un
       centavo de holgura, porque nadie dicta los decimales igual. */
    $importe = buscar_importe($q);
    if (can('ventas.ver')) {
        [$wv, $pv] = sucursalScope('v.sucursal_id');
        $porImporte = $importe !== null ? ' OR (v.total BETWEEN ? AND ?)' : '';
        $extra = $importe !== null ? [$importe - 0.01, $importe + 0.01] : [];
        $rows = qAll(
            "SELECT v.id, v.numero, v.ncf, v.fecha, v.total, v.estado,
                    COALESCE(cl.nombre,'Consumidor final') AS cliente
               FROM ventas v LEFT JOIN clientes cl ON cl.id = v.cliente_id
              WHERE (v.numero LIKE ? OR v.ncf LIKE ?$porImporte) AND $wv
              ORDER BY v.fecha DESC LIMIT $tope",
            array_merge([$like, $like], $extra, $pv)
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'   => $r['numero'] . ($r['ncf'] ? ' · ' . $r['ncf'] : ''),
                'subtitulo' => $r['cliente'] . ' · ' . fechaHora($r['fecha']),
                'etiqueta' => money($r['total']),
                'etiqueta_color' => $r['estado'] === 'completada' ? 'emerald' : 'rose',
                'url'      => url('modules/pos/ventas.php?q=' . urlencode($r['numero'])),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Ventas', 'icono' => 'receipt', 'color' => 'emerald', 'items' => $items];
    }

    /* ---------- Cotizaciones ---------- */
    if (can('cotizaciones.ver') && cot_disponible()) {
        [$wq, $pq] = sucursalScope('c.sucursal_id');
        $rows = qAll(
            "SELECT c.id, c.numero, c.fecha, c.total, c.estado,
                    COALESCE(cl.nombre,'Sin cliente') AS cliente
               FROM cotizaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
              WHERE (c.numero LIKE ? OR cl.nombre LIKE ?) AND $wq
              ORDER BY c.fecha DESC LIMIT $tope",
            array_merge([$like, $like], $pq)
        );
        $items = [];
        foreach ($rows as $r) {
            $est = cot_estados()[$r['estado']] ?? [$r['estado'], 'slate'];
            $items[] = [
                'titulo'    => $r['numero'],
                'subtitulo' => $r['cliente'] . ' · ' . fechaCorta($r['fecha']),
                'etiqueta'  => $est[0], 'etiqueta_color' => $est[1],
                'url'       => url('modules/pos/cotizacion.php?id=' . (int) $r['id']),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Cotizaciones', 'icono' => 'file', 'color' => 'sky', 'items' => $items];
    }

    /* ---------- Pedidos en línea ----------
       El teléfono es LO que se busca aquí: el cliente llama y dice su número,
       no el del pedido. */
    if (can('pedidos.ver')) {
        [$wp, $pp] = sucursalScope('p.sucursal_id');
        $rows = qAll(
            "SELECT p.id, p.numero, p.cliente_nombre, p.cliente_telefono, p.total, p.estado, p.created_at
               FROM pedidos p
              WHERE (p.numero LIKE ? OR p.cliente_nombre LIKE ? OR p.cliente_telefono LIKE ?) AND $wp
              ORDER BY p.id DESC LIMIT $tope",
            array_merge([$like, $like, $like], $pp)
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'    => $r['numero'] . ' · ' . $r['cliente_nombre'],
                'subtitulo' => ($r['cliente_telefono'] ?: 'sin teléfono') . ' · ' . fechaCorta($r['created_at']),
                'etiqueta'  => money($r['total']),
                'etiqueta_color' => $r['estado'] === 'pendiente' ? 'amber' : ($r['estado'] === 'cancelado' ? 'slate' : 'emerald'),
                'url'       => url('modules/pos/pedidos.php?q=' . urlencode($r['numero'])),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Pedidos', 'icono' => 'store', 'color' => 'emerald', 'items' => $items];
    }

    /* ---------- Comprobantes electrónicos ----------
       Se busca por e-NCF porque es lo que trae el cliente cuando reclama, y
       lo que pide la DGII cuando pregunta. */
    if (can('ecf.ver') && function_exists('ecfActivo')) {
        try {
            $rows = qAll(
                "SELECT id, encf, tipo_ecf, estado, total, fecha_emision, razon_social_comprador
                   FROM ecf_documentos WHERE encf LIKE ? ORDER BY id DESC LIMIT $tope", [$like]);
            $items = [];
            $col = ['aceptado' => 'emerald', 'rechazado' => 'rose', 'error' => 'rose'];
            foreach ($rows as $r) {
                $items[] = [
                    'titulo'    => $r['encf'],
                    'subtitulo' => ($r['razon_social_comprador'] ?: 'Consumidor final') . ' · ' . fechaCorta($r['fecha_emision']),
                    'etiqueta'  => $r['estado'], 'etiqueta_color' => $col[$r['estado']] ?? 'sky',
                    'url'       => url('modules/finanzas/ecf.php?tab=documentos'),
                ];
            }
            if ($items) $grupos[] = ['grupo' => 'Comprobantes e-CF', 'icono' => 'receipt', 'color' => 'violet', 'items' => $items];
        } catch (Throwable $e) { /* sin módulo e-CF, se omite */ }
    }

    /* ---------- Préstamos a empleados ---------- */
    if (can('prestamos.ver') && function_exists('presDisponible') && presDisponible()) {
        $rows = qAll(
            "SELECT p.id, p.numero, p.tipo, p.monto, p.saldo, p.estado,
                    CONCAT(e.nombre,' ',e.apellido) AS empleado
               FROM prestamos p JOIN empleados e ON e.id = p.empleado_id
              WHERE p.numero LIKE ? OR e.nombre LIKE ? OR e.apellido LIKE ? OR e.cedula LIKE ?
              ORDER BY p.estado = 'activo' DESC, p.id DESC LIMIT $tope",
            [$like, $like, $like, $like]
        );
        $items = [];
        foreach ($rows as $r) {
            $est = presEstados()[$r['estado']] ?? [$r['estado'], 'slate'];
            $items[] = [
                'titulo'    => $r['numero'] . ' · ' . $r['empleado'],
                'subtitulo' => (presTipos()[$r['tipo']] ?? $r['tipo']) . ' de ' . money($r['monto'], false)
                             . ' · saldo ' . money($r['saldo'], false),
                'etiqueta'  => $est[0], 'etiqueta_color' => $est[1],
                'url'       => url('modules/rrhh/prestamos.php?ver=' . (int) $r['id']),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Préstamos', 'icono' => 'wallet', 'color' => 'amber', 'items' => $items];
    }

    /* ---------- Amonestaciones ----------
       Se busca también dentro de los HECHOS: cuando alguien recuerda «lo de la
       caja descuadrada» pero no el número ni la fecha. */
    if (can('amonestaciones.ver') && function_exists('amonDisponible') && amonDisponible()) {
        $rows = qAll(
            "SELECT a.id, a.numero, a.tipo, a.gravedad, a.estado, a.fecha_emision, a.fecha_conocimiento,
                    CONCAT(e.nombre,' ',e.apellido) AS empleado
               FROM amonestaciones a JOIN empleados e ON e.id = a.empleado_id
              WHERE a.numero LIKE ? OR e.nombre LIKE ? OR e.apellido LIKE ? OR a.hechos LIKE ?
              ORDER BY a.fecha_emision DESC LIMIT $tope",
            [$like, $like, $like, $like]
        );
        $items = [];
        foreach ($rows as $r) {
            $cad = amonCaducidad($r['fecha_conocimiento']);
            $gra = amonGravedades()[$r['gravedad']] ?? [$r['gravedad'], 'slate'];
            $items[] = [
                'titulo'    => $r['numero'] . ' · ' . $r['empleado'],
                'subtitulo' => (amonTipos()[$r['tipo']] ?? $r['tipo']) . ' · ' . fechaCorta($r['fecha_emision'])
                             . ($r['estado'] !== 'anulada' && !$cad['caducado'] ? ' · ' . $cad['etiqueta'] : ''),
                'etiqueta'  => $gra[0], 'etiqueta_color' => $gra[1],
                'url'       => url('modules/rrhh/amonestaciones.php?ver=' . (int) $r['id']),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Amonestaciones', 'icono' => 'alert', 'color' => 'rose', 'items' => $items];
    }

    /* ---------- Proveedores ---------- */
    if (can('proveedores.ver')) {
        $rows = qAll(
            "SELECT id, codigo, nombre, rnc, telefono FROM proveedores
              WHERE codigo LIKE ? OR nombre LIKE ? OR rnc LIKE ?
              ORDER BY nombre LIMIT $tope",
            [$like, $like, $like]
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'   => $r['nombre'],
                'subtitulo' => trim($r['codigo'] . ($r['rnc'] ? ' · RNC ' . $r['rnc'] : '') . ($r['telefono'] ? ' · ' . $r['telefono'] : '')),
                'url'      => url('modules/inventario/proveedores.php?q=' . urlencode($r['codigo'])),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Proveedores', 'icono' => 'truck', 'color' => 'amber', 'items' => $items];
    }

    /* ---------- Lotes (cumplimiento sanitario) ---------- */
    // Poder teclear un numero de lote en el buscador global y llegar a su
    // trazabilidad es lo que hace util un retiro del mercado: se busca con el
    // fabricante al telefono, no navegando por menus.
    if (can('sanidad.ver') && san_disponible()) {
        [$sc, $sp] = sucursalScope('l.sucursal_id');
        $rows = qAll(
            "SELECT l.id, l.codigo, l.cantidad, l.fecha_vencimiento, l.bloqueado,
                    p.nombre AS producto, s.nombre AS sucursal
               FROM lotes l
               JOIN productos p  ON p.id = l.producto_id
               JOIN sucursales s ON s.id = l.sucursal_id
              WHERE l.codigo LIKE ? AND $sc
              ORDER BY (l.fecha_vencimiento IS NULL), l.fecha_vencimiento LIMIT $tope",
            array_merge([$like], $sp)
        );
        $items = [];
        foreach ($rows as $r) {
            $est = san_estado_lote($r);
            $items[] = [
                'titulo'    => 'Lote ' . $r['codigo'] . ' · ' . $r['producto'],
                'subtitulo' => $r['sucursal'] . ' · ' . qty($r['cantidad']) . ' en existencia'
                             . ($r['fecha_vencimiento'] ? ' · vence ' . fechaCorta($r['fecha_vencimiento']) : ''),
                'etiqueta'  => $est['etiqueta'],
                'etiqueta_color' => $est['color'],
                'url'       => url('modules/reportes/trazabilidad.php?lote_id=' . (int) $r['id']),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Lotes', 'icono' => 'shield', 'color' => 'rose', 'items' => $items];
    }

    /* ---------- Compras ---------- */
    if (can('compras.ver')) {
        [$wc, $pc] = sucursalScope('c.sucursal_id');
        $rows = qAll(
            "SELECT c.id, c.numero, c.ncf, c.fecha, c.total, c.estado,
                    COALESCE(pr.nombre,'Sin proveedor') AS proveedor
               FROM compras c LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
              WHERE (c.numero LIKE ? OR c.ncf LIKE ?) AND $wc
              ORDER BY c.fecha DESC LIMIT $tope",
            array_merge([$like, $like], $pc)
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'   => $r['numero'] . ($r['ncf'] ? ' · ' . $r['ncf'] : ''),
                'subtitulo' => $r['proveedor'] . ' · ' . fechaCorta($r['fecha']),
                'etiqueta' => money($r['total']),
                'etiqueta_color' => 'slate',
                'url'      => url('modules/inventario/compras.php?q=' . urlencode($r['numero'])),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Compras', 'icono' => 'cart', 'color' => 'indigo', 'items' => $items];
    }

    /* ---------- Empleados ---------- */
    if (can('rrhh_empleados.ver')) {
        $rows = qAll(
            "SELECT e.id, e.codigo, e.nombre, e.apellido, e.cedula, e.estado,
                    COALESCE(pu.nombre,'—') AS puesto
               FROM empleados e LEFT JOIN puestos pu ON pu.id = e.puesto_id
              WHERE e.codigo LIKE ? OR e.cedula LIKE ?
                 OR CONCAT(e.nombre,' ',e.apellido) LIKE ?
              ORDER BY e.nombre LIMIT $tope",
            [$like, $like, $like]
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'   => $r['nombre'] . ' ' . $r['apellido'],
                'subtitulo' => $r['codigo'] . ' · ' . $r['cedula'] . ' · ' . $r['puesto'],
                'etiqueta' => ucfirst($r['estado']),
                'etiqueta_color' => $r['estado'] === 'activo' ? 'emerald' : 'slate',
                'url'      => url('modules/rrhh/empleados.php?q=' . urlencode($r['codigo'])),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Empleados', 'icono' => 'id', 'color' => 'violet', 'items' => $items];
    }

    /* ---------- Oportunidades del CRM ---------- */
    if (can('crm.ver')) {
        [$wo, $po] = sucursalScope('o.sucursal_id');
        $rows = qAll(
            "SELECT o.id, o.codigo, o.titulo, o.etapa, o.valor_estimado, cl.nombre AS cliente
               FROM crm_oportunidades o JOIN clientes cl ON cl.id = o.cliente_id
              WHERE (o.codigo LIKE ? OR o.titulo LIKE ?) AND $wo
              ORDER BY o.updated_at DESC LIMIT $tope",
            array_merge([$like, $like], $po)
        );
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'titulo'   => $r['titulo'],
                'subtitulo' => $r['codigo'] . ' · ' . $r['cliente'],
                'etiqueta' => money($r['valor_estimado']),
                'etiqueta_color' => $r['etapa'] === 'ganada' ? 'emerald' : ($r['etapa'] === 'perdida' ? 'rose' : 'blue'),
                'url'      => url('modules/crm/oportunidades.php?q=' . urlencode($r['codigo'])),
            ];
        }
        if ($items) $grupos[] = ['grupo' => 'Oportunidades', 'icono' => 'briefcase', 'color' => 'rose', 'items' => $items];
    }

    return $grupos;
}

/** Pantallas del sistema que coinciden con el texto (respeta permisos). */
function buscar_navegacion(string $q, int $tope = 5): array
{
    $needle = buscar_normalizar($q);
    $items = [];

    foreach (nav_groups() as [$grupo, $entradas]) {
        foreach ($entradas as [$label, $ico, $href, $perm]) {
            if ($perm !== null && !can($perm)) continue;
            if (strpos(buscar_normalizar($label), $needle) === false
                && strpos(buscar_normalizar($grupo), $needle) === false) continue;
            $items[] = ['titulo' => $label, 'subtitulo' => $grupo, 'url' => $href, 'icono' => $ico];
            if (count($items) >= $tope) return $items;
        }
    }
    return $items;
}

/** Accesos directos que se ofrecen con el buscador vacío. */
function buscar_atajos(): array
{
    $todos = [
        ['Nueva venta', 'Abrir el punto de venta', 'cart', 'modules/pos/index.php', 'pos.vender'],
        ['Centro de reportes', 'Dirección, finanzas y contabilidad', 'chart', 'modules/reportes/index.php', 'reportes.ver'],
        ['Panel ejecutivo', 'Los números del negocio hoy', 'trending', 'modules/reportes/ejecutivo.php', 'reportes.ejecutivo'],
        ['Notificaciones', 'Alertas que requieren acción', 'bell', 'modules/notificaciones/index.php', null],
        ['Productos', 'Catálogo e inventario', 'box', 'modules/inventario/productos.php', 'productos.ver'],
        ['Clientes', 'Cartera y cuentas por cobrar', 'users', 'modules/pos/clientes.php', 'clientes.ver'],
        ['Caja', 'Apertura, cierre y arqueo', 'cash', 'modules/pos/caja.php', 'caja.ver'],
        ['Reportes DGII', '606, 607, 608 e IT-1', 'shield', 'modules/finanzas/dgii.php', 'dgii.ver'],
    ];
    $out = [];
    foreach ($todos as [$titulo, $sub, $ico, $ruta, $perm]) {
        if ($perm !== null && !can($perm)) continue;
        $out[] = ['titulo' => $titulo, 'subtitulo' => $sub, 'icono' => $ico, 'url' => url($ruta)];
    }
    return array_slice($out, 0, 6);
}

/** Cuenta cuántos resultados trae un conjunto de grupos. */
function buscar_total(array $grupos): int
{
    $n = 0;
    foreach ($grupos as $g) $n += count($g['items']);
    return $n;
}
