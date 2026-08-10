<?php
/**
 * Área de Dirección — consultas compartidas del panel de la CEO.
 *
 * Todas respetan el mismo criterio contable que el resto de reportes
 * (ver docs/REPORTES-Y-NOTIFICACIONES.md), porque nada erosiona más la
 * confianza en un sistema que dos pantallas dando cifras distintas:
 *
 *   Ingresos       = subtotal − descuento   (SIN ITBIS: se recauda para la DGII)
 *   Costo          = ventas.costo_total
 *   Utilidad bruta = ingresos − costo
 *
 * El alcance por sucursal y por tienda se pasa siempre como fragmento SQL para
 * que la misma función sirva al panel, al comparativo y a los costos.
 */

/** Años que tienen ventas registradas, del más reciente al más antiguo. */
function dir_anios(): array
{
    $anios = qCol("SELECT DISTINCT YEAR(fecha) FROM ventas WHERE estado = 'completada' ORDER BY 1 DESC");
    $anios = array_map('intval', $anios);
    $hoy = (int) date('Y');
    if (!in_array($hoy, $anios, true)) array_unshift($anios, $hoy);
    return $anios;
}

/**
 * Alcance combinado de sucursal + tienda para las consultas de Dirección.
 * Devuelve [fragmentoWhere, params].
 */
function dir_scope(string $alias = 'v'): array
{
    [$w, $p] = sucursalFiltro($alias . '.sucursal_id');
    $tid = tiendaFiltroActual();
    if ($tid !== null) {
        $w .= " AND $alias.tienda_id = ?";
        $p[] = $tid;
    }
    return [$w, $p];
}

/**
 * Totales de un rango de fechas.
 *
 * @return array{ingresos:float,costo:float,utilidad:float,margen:float,facturas:int,
 *               ticket:float,clientes:int,descuentos:float,itbis:float,unidades:float}
 */
function dir_totales(string $ini, string $fin, string $scope, array $params): array
{
    $r = qOne(
        "SELECT COUNT(*) facturas,
                COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
                COALESCE(SUM(v.costo_total),0)            costo,
                COALESCE(SUM(v.descuento),0)              descuentos,
                COALESCE(SUM(v.itbis),0)                  itbis,
                COUNT(DISTINCT v.cliente_id)              clientes
           FROM ventas v
          WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope",
        array_merge([$ini, $fin], $params)
    ) ?: [];

    $ing = (float) ($r['ingresos'] ?? 0);
    $cos = (float) ($r['costo'] ?? 0);
    $fac = (int) ($r['facturas'] ?? 0);

    return [
        'facturas'   => $fac,
        'ingresos'   => $ing,
        'costo'      => $cos,
        'utilidad'   => $ing - $cos,
        'margen'     => $ing > 0 ? ($ing - $cos) / $ing * 100 : 0.0,
        'ticket'     => $fac > 0 ? $ing / $fac : 0.0,
        'clientes'   => (int) ($r['clientes'] ?? 0),
        'descuentos' => (float) ($r['descuentos'] ?? 0),
        'itbis'      => (float) ($r['itbis'] ?? 0),
    ];
}

/**
 * Serie de los 12 meses de un año: [1 => ['ingresos'=>,'costo'=>,'facturas'=>], ...].
 *
 * Una sola consulta agrupada, no doce. Con dos años de histórico la diferencia
 * entre agrupar y hacer un bucle de doce consultas es medio segundo por pantalla
 * (ver el apartado de rendimiento en docs/CONVENCIONES-DEV.md).
 */
function dir_serie_anual(int $anio, string $scope, array $params): array
{
    $rows = qAll(
        "SELECT MONTH(v.fecha) m,
                COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
                COALESCE(SUM(v.costo_total),0)            costo,
                COUNT(*)                                  facturas
           FROM ventas v
          WHERE v.estado = 'completada'
            AND v.fecha BETWEEN ? AND ?
            AND $scope
          GROUP BY MONTH(v.fecha)",
        array_merge([$anio . '-01-01 00:00:00', $anio . '-12-31 23:59:59'], $params)
    );

    $serie = [];
    for ($m = 1; $m <= 12; $m++) $serie[$m] = ['ingresos' => 0.0, 'costo' => 0.0, 'facturas' => 0];
    foreach ($rows as $r) {
        $m = (int) $r['m'];
        $serie[$m] = [
            'ingresos' => (float) $r['ingresos'],
            'costo'    => (float) $r['costo'],
            'facturas' => (int) $r['facturas'],
        ];
    }
    return $serie;
}

/**
 * Comparativo por una dimensión entre dos rangos.
 *
 * `$ingresoExpr` no siempre es el mismo: las dimensiones que agrupan por algo de
 * la venta (tienda, sucursal, canal) suman el total de la venta; las que entran
 * por `venta_detalles` (categoría, producto) tienen que sumar la LÍNEA, porque
 * sumar el total de la venta lo repetiría una vez por cada línea. Ese error dio
 * una vez el triple del ingreso real.
 *
 * @return array<string,array{a:float,b:float,facturas:int}>
 */
function dir_dimension(string $etiqueta, string $joins, string $groupBy, array $rangoA, array $rangoB,
                       string $scope, array $params, string $ingresoExpr = 'v.subtotal - v.descuento'): array
{
    $consulta = function (array $rango) use ($etiqueta, $joins, $groupBy, $scope, $params, $ingresoExpr) {
        return qAll(
            "SELECT $etiqueta AS etiqueta,
                    COALESCE(SUM($ingresoExpr),0) AS ingresos,
                    COUNT(DISTINCT v.id)          AS facturas
               FROM ventas v $joins
              WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
              GROUP BY $groupBy",
            array_merge([$rango[0], $rango[1]], $params)
        );
    };

    $out = [];
    foreach ($consulta($rangoA) as $r) {
        $k = (string) ($r['etiqueta'] ?? 'Sin especificar');
        $out[$k] = ['a' => (float) $r['ingresos'], 'b' => 0.0, 'facturas' => (int) $r['facturas']];
    }
    foreach ($consulta($rangoB) as $r) {
        $k = (string) ($r['etiqueta'] ?? 'Sin especificar');
        if (!isset($out[$k])) $out[$k] = ['a' => 0.0, 'b' => 0.0, 'facturas' => 0];
        $out[$k]['b'] = (float) $r['ingresos'];
    }
    uasort($out, fn($x, $y) => $y['a'] <=> $x['a']);
    return $out;
}

/**
 * Costo de la mercancía vendida desglosado por una dimensión.
 *
 * Entra por `venta_detalles` a propósito: el costo por categoría o por producto
 * solo existe a nivel de línea. Por eso se suman `vd.subtotal` y
 * `vd.cantidad * vd.costo_unitario`, nunca los totales de la venta.
 *
 * @return array<int,array{etiqueta:string,ingresos:float,costo:float,unidades:float}>
 */
function dir_costos_por(string $etiqueta, string $joins, string $groupBy,
                        string $ini, string $fin, string $scope, array $params, int $limite = 0): array
{
    $lim = $limite > 0 ? ' LIMIT ' . (int) $limite : '';
    $rows = qAll(
        "SELECT $etiqueta AS etiqueta,
                COALESCE(SUM(vd.subtotal - vd.descuento),0)      AS ingresos,
                COALESCE(SUM(vd.cantidad * vd.costo_unitario),0) AS costo,
                COALESCE(SUM(vd.cantidad),0)                     AS unidades
           FROM ventas v
           JOIN venta_detalles vd ON vd.venta_id = v.id
           $joins
          WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
          GROUP BY $groupBy
          ORDER BY costo DESC$lim",
        array_merge([$ini, $fin], $params)
    );
    return array_map(fn($r) => [
        'etiqueta' => (string) ($r['etiqueta'] ?? 'Sin especificar'),
        'ingresos' => (float) $r['ingresos'],
        'costo'    => (float) $r['costo'],
        'unidades' => (float) $r['unidades'],
    ], $rows);
}

/**
 * Inventario valorizado a costo, ahora mismo.
 * Es el dinero parado en el almacén: la cifra que la dirección compara contra
 * el costo de lo que vende para saber cuántos meses de inventario tiene.
 */
function dir_inventario_costo(?int $tiendaId = null): array
{
    [$w, $p] = sucursalFiltro('s.sucursal_id');
    $wT = '';
    if ($tiendaId !== null) { $wT = ' AND pr.tienda_id = ?'; $p[] = $tiendaId; }

    $r = qOne(
        "SELECT COALESCE(SUM(s.cantidad * pr.precio_compra),0) costo,
                COALESCE(SUM(s.cantidad * pr.precio_venta),0)  venta,
                COALESCE(SUM(s.cantidad),0)                    unidades
           FROM inventario_stock s
           JOIN productos pr ON pr.id = s.producto_id
          WHERE pr.activo = 1 AND s.cantidad > 0 AND $w$wT",
        $p
    ) ?: [];

    return [
        'costo'    => (float) ($r['costo'] ?? 0),
        'venta'    => (float) ($r['venta'] ?? 0),
        'unidades' => (float) ($r['unidades'] ?? 0),
    ];
}

/**
 * Artículos que se están vendiendo por debajo de su costo.
 *
 * Con el costeo de importaciones esto deja de ser teórico: al aplicar una
 * liquidación el costo real sube y hay artículos cuyo precio de lista se quedó
 * en el costo de la factura del proveedor. Esta lista es la que hay que
 * repreciar.
 */
function dir_bajo_costo(string $ini, string $fin, string $scope, array $params, int $limite = 15): array
{
    return qAll(
        "SELECT p.id, p.nombre, p.codigo, p.precio_venta, p.precio_compra,
                COALESCE(SUM(vd.cantidad),0) unidades,
                COALESCE(SUM(vd.subtotal - vd.descuento),0) ingresos,
                COALESCE(SUM(vd.cantidad * vd.costo_unitario),0) costo
           FROM ventas v
           JOIN venta_detalles vd ON vd.venta_id = v.id
           JOIN productos p ON p.id = vd.producto_id
          WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
          GROUP BY p.id, p.nombre, p.codigo, p.precio_venta, p.precio_compra
         -- Las expresiones se repiten en vez de usar los alias: MariaDB rechaza
         -- referenciar el alias de una función de grupo dentro de HAVING.
         HAVING SUM(vd.cantidad * vd.costo_unitario) > SUM(vd.subtotal - vd.descuento)
            AND SUM(vd.cantidad) > 0
          ORDER BY (SUM(vd.cantidad * vd.costo_unitario) - SUM(vd.subtotal - vd.descuento)) DESC
          LIMIT " . max(1, $limite),
        array_merge([$ini, $fin], $params)
    );
}
