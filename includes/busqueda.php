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

    /* ---------- Ventas ---------- */
    if (can('ventas.ver')) {
        [$wv, $pv] = sucursalScope('v.sucursal_id');
        $rows = qAll(
            "SELECT v.id, v.numero, v.ncf, v.fecha, v.total, v.estado,
                    COALESCE(cl.nombre,'Consumidor final') AS cliente
               FROM ventas v LEFT JOIN clientes cl ON cl.id = v.cliente_id
              WHERE (v.numero LIKE ? OR v.ncf LIKE ?) AND $wv
              ORDER BY v.fecha DESC LIMIT $tope",
            array_merge([$like, $like], $pv)
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
    $norm = static fn(string $s): string => mb_strtolower(strtr($s, 'áéíóúÁÉÍÓÚñÑ', 'aeiouAEIOUnN'));
    $needle = $norm($q);
    $items = [];

    foreach (nav_groups() as [$grupo, $entradas]) {
        foreach ($entradas as [$label, $ico, $href, $perm]) {
            if ($perm !== null && !can($perm)) continue;
            if (strpos($norm($label), $needle) === false && strpos($norm($grupo), $needle) === false) continue;
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
