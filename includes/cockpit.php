<?php
/**
 * Promotion Cockpit — análisis de descuentos, stacking y margen.
 *
 * Réplica, con los datos del POS, del cockpit que usa la casa matriz de la
 * marca: de la venta BRUTA (a precio de catálogo) a la venta NETA, pasando por
 * cada tipo de descuento, y lo que eso le cuesta al margen este año contra el
 * anterior. Ver docs/PROMOTION-COCKPIT.md.
 *
 * Cómo se mide cada línea de venta:
 *
 *   Venta bruta (GS)   precio de catálogo × cantidad (también para una muestra,
 *                      que se regala a su precio de lista); para una venta anterior
 *                      a la migración P39 no se sabe el precio de lista y se
 *                      toma lo cobrado (su descuento de promo queda en 0 y la
 *                      pantalla lo avisa, en vez de inventarlo).
 *   Descuento en caja  el descuento manual de la factura repartido entre sus
 *                      líneas en proporción a su subtotal.
 *   Venta neta (NS)    subtotal de la línea − su parte del descuento en caja.
 *                      Es el mismo criterio de ingresos de los reportes (sin ITBIS).
 *   Costo (SMC)        costo_unitario × cantidad, congelado al vender.
 *
 * Cada línea cae en UN tipo de descuento (la fila de la tabla): muestra,
 * la familia de la promoción que ganó, precio negociado, el motivo del
 * descuento en caja o «sin promoción». Las ventas son las de siempre
 * (`rep_estados_venta`): facturadas, antes de devoluciones.
 */

/* ============================================================
 *  Disponibilidad (el código puede llegar antes que la migración)
 * ============================================================ */

/** ¿Ya existen las columnas de rastro de la P39? Se consulta una vez por petición. */
function cockpit_capturando(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            // Las cinco columnas que se escriben al vender. Si la P39 se quedó a
            // medias, mejor no escribir ninguna que tumbar cada venta del POS.
            $ok = (int) qVal("SELECT COUNT(*) FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND (
                                     (TABLE_NAME = 'venta_detalles' AND COLUMN_NAME IN ('precio_lista','promocion_id'))
                                  OR (TABLE_NAME = 'ventas' AND COLUMN_NAME = 'descuento_motivo')
                                  OR (TABLE_NAME = 'pedido_detalles' AND COLUMN_NAME IN ('precio_lista','promocion_id')))") === 5;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** ¿Está entera la migración P39 (columnas + tablas de campañas)? */
function cockpit_disponible(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = cockpit_capturando()
                && (bool) qVal("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_campanas'");
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/* ============================================================
 *  Configuración (P40): todo sale de tablas editables desde
 *  Marketing → Configuración del cockpit. Los valores de abajo son
 *  el respaldo mientras la P40 no esté aplicada, y los de la siembra.
 * ============================================================ */

/** ¿Existe la configuración editable (P40)? */
function cockpit_config_disponible(): bool
{
    static $ok = null;
    if ($ok === null) {
        if (!function_exists('qVal')) return $ok = false;   // pruebas sin base
        try {
            $ok = (bool) qVal("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cockpit_parametros'");
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Lee una tabla de configuración, o null si no existe todavía. Cacheado por petición. */
function cockpit_config_filas(string $tabla, string $orden = 'orden, clave'): ?array
{
    static $cache = [];
    if (!cockpit_config_disponible()) return null;
    return $cache[$tabla] ??= qAll("SELECT * FROM $tabla ORDER BY $orden");
}

/** Parámetros generales. */
function cockpit_param(string $clave, $defecto = null)
{
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (cockpit_config_filas('cockpit_parametros', 'clave') ?? [] as $r) $p[$r['clave']] = $r['valor'];
    }
    return ($p[$clave] ?? '') !== '' ? $p[$clave] : $defecto;
}

/** Definición de los parámetros que se editan en pantalla: clave => [etiqueta, tipo, ayuda, defecto]. */
function cockpit_parametros_def(): array
{
    return [
        'periodo_defecto'   => ['Periodo al abrir el cockpit', 'select', 'Lo que se ve sin tocar filtros.', 'ytd'],
        'mes_inicio_fiscal' => ['Mes en que empieza el año fiscal', 'mes', 'La marca cierra de abril a marzo.', '4'],
        'canal_defecto'     => ['Canal de la marca cuando ninguna regla aplica', 'canal', '', 'retail'],
        'menos_activadas'   => ['Cuántas promociones mostrar en «menos activadas»', 'int', '', '30'],
        'top_skus'          => ['SKUs a mostrar cuando la campaña no tiene SKUs foco', 'int', '', '20'],
        'dias_max'          => ['Máximo de días en el detalle diario de una campaña', 'int', '', '93'],
        'canales_captacion' => ['Canales de captación del POS (uno por línea)', 'lineas', 'Las opciones que el cajero elige al vender. Lo que quites no se borra de las ventas viejas.', "Mostrador\nInstagram\nWhatsApp\nFacebook\nReferido\nOtro"],
    ];
}

function cockpit_param_int(string $clave): int
{
    return max(1, (int) cockpit_param($clave, cockpit_parametros_def()[$clave][3] ?? 1));
}

/** Respaldo de los tipos (igual que la siembra de la P40). */
function cockpit_tipos_defecto(): array
{
    // clave => [nombre, color, es_promocion, etiqueta_caja, sistema]
    return [
        'set_regalo'  => ['Sets de regalo (Gift sets)', '#2a78d6', 1, null, 0],
        'calendario'  => ['Calendario de adviento', '#eb6834', 1, null, 0],
        'gwp'         => ['Regalo con compra (GWP / PWP)', '#1baf7a', 1, null, 0],
        'crm'         => ['Clientes y fidelidad (CRM)', '#eda100', 1, 'Cliente frecuente / fidelidad', 0],
        'operacion'   => ['Operación especial (Black Friday, aniversario…)', '#e87ba4', 1, null, 0],
        'temporada'   => ['Temporada / rebajas (Sales)', '#008300', 1, null, 0],
        'lanzamiento' => ['Lanzamiento', '#4a3aa7', 1, null, 0],
        'empleados'   => ['Descuento de empleados', '#e34948', 1, 'Empleado', 0],
        'outlet'      => ['Outlet / liquidación', '#78716c', 1, 'Producto con defecto / liquidación', 0],
        'otro'        => ['Otro descuento', '#a8a29e', 1, 'Otro', 0],
        'cortesia'    => ['Cortesía / servicio al cliente', '#57534e', 0, 'Cortesía / servicio', 0],
        'manual'      => ['Descuento manual en caja', '#475569', 0, 'Descuento manual', 1],
        'promocion'   => ['Promoción sin clasificar', '#94a3b8', 0, null, 1],
        'muestra'     => ['Muestras y regalos (RD$0)', '#64748b', 0, null, 1],
        'negociado'   => ['Precio negociado (cotización)', '#334155', 0, null, 1],
        'sin'         => ['Sin promoción', '#cbd5e1', 0, null, 1],
    ];
}

/**
 * Todos los tipos, activos e inactivos (para poner nombre a lo histórico).
 * @return array<string,array{nombre:string,color:string,es_promocion:int,etiqueta_caja:?string,sistema:int,activo:int}>
 */
function cockpit_tipos_def(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $filas = cockpit_config_filas('cockpit_tipos');
    $t = [];
    if ($filas === null) {
        foreach (cockpit_tipos_defecto() as $k => [$n, $c, $p, $caja, $sis]) {
            $t[$k] = ['nombre' => $n, 'color' => $c, 'es_promocion' => $p, 'etiqueta_caja' => $caja, 'sistema' => $sis, 'activo' => 1];
        }
        return $t;
    }
    foreach ($filas as $r) {
        $t[$r['clave']] = ['nombre' => $r['nombre'], 'color' => $r['color'], 'es_promocion' => (int) $r['es_promocion'],
                           'etiqueta_caja' => $r['etiqueta_caja'], 'sistema' => (int) $r['sistema'], 'activo' => (int) $r['activo']];
    }
    // El cálculo usa estas claves aunque alguien borre la fila a mano en la base.
    foreach (cockpit_tipos_defecto() as $k => [$n, $c, $p, $caja, $sis]) {
        if ($sis && !isset($t[$k])) $t[$k] = ['nombre' => $n, 'color' => $c, 'es_promocion' => 0, 'etiqueta_caja' => $caja, 'sistema' => 1, 'activo' => 1];
    }
    return $t;
}

/**
 * Tipos de descuento: la fila del cockpit. clave => [nombre, color].
 *
 * Las familias de promoción y los motivos de descuento en caja comparten
 * catálogo a propósito: un descuento de empleado es «Descuento de empleados»
 * lo haya hecho una promoción o el cajero a mano.
 */
function cockpit_tipos(): array
{
    return array_map(fn($r) => [$r['nombre'], $r['color']], cockpit_tipos_def());
}

/** Familias que se pueden asignar a una promoción (activas). */
function cockpit_tipos_promocion(): array
{
    $out = [];
    foreach (cockpit_tipos_def() as $k => $r) if ($r['es_promocion'] && $r['activo']) $out[$k] = $r['nombre'];
    return $out;
}

/** Motivos del descuento manual en caja (selector del POS). «manual» siempre existe. */
function cockpit_motivos_caja(): array
{
    $out = [];
    foreach (cockpit_tipos_def() as $k => $r) {
        if ($r['etiqueta_caja'] !== null && $r['etiqueta_caja'] !== '' && ($r['activo'] || $k === 'manual')) $out[$k] = $r['etiqueta_caja'];
    }
    if (!isset($out['manual'])) $out = ['manual' => 'Descuento manual'] + $out;
    return $out;
}

function cockpit_tipo_label(string $k): string
{
    return cockpit_tipos_def()[$k]['nombre'] ?? ucfirst(str_replace('_', ' ', $k));
}

function cockpit_tipo_color(string $k): string
{
    return cockpit_tipos_def()[$k]['color'] ?? '#94a3b8';
}

/** Canales en los que reporta la marca (activos). clave => nombre */
function cockpit_canales(): array
{
    $filas = cockpit_config_filas('cockpit_canales');
    if ($filas === null) {
        return ['retail' => 'Retail (tiendas)', 'mayoreo' => 'Mayoreo / corporativo', 'web' => 'Web (tienda online)', 'social' => 'Social selling'];
    }
    $out = [];
    foreach ($filas as $r) if ($r['activo']) $out[$r['clave']] = $r['nombre'];
    return $out ?: ['retail' => 'Retail'];
}

/** Todos los canales, activos o no (para nombrar lo que se vendió en uno ya desactivado). */
function cockpit_canales_todos(): array
{
    $filas = cockpit_config_filas('cockpit_canales');
    return $filas === null ? cockpit_canales() : array_column($filas, 'nombre', 'clave');
}

/** Nombre en inglés de cada canal (encabezados del Excel de la marca). */
function cockpit_canales_en(): array
{
    $out = ['retail' => 'Retail', 'mayoreo' => 'Wholesale', 'web' => 'Web', 'social' => 'Social Selling'];
    foreach (cockpit_config_filas('cockpit_canales') ?? [] as $r) $out[$r['clave']] = $r['nombre_en'] ?: $r['nombre'];
    return $out;
}

/** Reglas de canal, en orden. @return array<int,array{tipo:string,valor:string,canal:string}> */
function cockpit_canal_reglas(): array
{
    $filas = cockpit_config_filas('cockpit_canal_reglas', 'orden, id');
    if ($filas !== null) return $filas;
    return [
        ['tipo' => 'canal_venta', 'valor' => 'Tienda online', 'canal' => 'web'],
        ['tipo' => 'canal_venta', 'valor' => 'Instagram', 'canal' => 'social'],
        ['tipo' => 'canal_venta', 'valor' => 'WhatsApp', 'canal' => 'social'],
        ['tipo' => 'canal_venta', 'valor' => 'Facebook', 'canal' => 'social'],
        ['tipo' => 'canal_venta', 'valor' => 'TikTok', 'canal' => 'social'],
        ['tipo' => 'comprobante', 'valor' => 'no_consumidor', 'canal' => 'mayoreo'],
    ];
}

/**
 * Canal de la marca para una venta, como expresión SQL.
 *
 * Se arma con las reglas configuradas: la primera que se cumple gana y el
 * resto cae en el canal por defecto. Es la ÚNICA regla del mapeo.
 */
function cockpit_canal_sql(string $v = 'v'): string
{
    static $cache = [];
    if (isset($cache[$v])) return $cache[$v];
    // Los valores los escribe la marca en pantalla: se citan con el propio
    // driver, que sabe si el servidor usa NO_BACKSLASH_ESCAPES.
    $txt = fn(string $s) => db()->quote($s);
    $casos = [];
    foreach (cockpit_canal_reglas() as $r) {
        if ($r['tipo'] === 'comprobante') {
            $cond = $r['valor'] === 'no_consumidor' ? "$v.tipo_comprobante <> 'consumidor'" : "$v.tipo_comprobante = " . $txt($r['valor']);
        } else {
            $cond = "$v.canal_venta = " . $txt($r['valor']);
        }
        $casos[] = "WHEN $cond THEN " . $txt($r['canal']);
    }
    $defecto = (string) cockpit_param('canal_defecto', 'retail');
    return $cache[$v] = $casos ? '(CASE ' . implode(' ', $casos) . ' ELSE ' . $txt($defecto) . ' END)' : $txt($defecto);
}

/* ============================================================
 *  Expresiones por línea
 * ============================================================ */

/** FROM común: la línea de venta con su factura, su producto y su promoción. */
function cockpit_from(): string
{
    return "FROM ventas v
            JOIN venta_detalles vd ON vd.venta_id = v.id
            LEFT JOIN productos pr ON pr.id = vd.producto_id
            LEFT JOIN promociones pm ON pm.id = vd.promocion_id";
}

/** @return array<string,string> gs, caja, ns, costo, negociado, tipo, mecanismo */
function cockpit_expr(): array
{
    // Una muestra vale lo que cuesta en catálogo (su precio de lista); las de
    // antes de P39 solo tienen `precio_original`, que ya traía la promoción.
    $gs = "GREATEST(vd.subtotal, CASE WHEN vd.es_muestra = 1 THEN COALESCE(vd.precio_lista, vd.precio_original) * vd.cantidad
                                      WHEN vd.precio_lista IS NOT NULL THEN vd.precio_lista * vd.cantidad
                                      ELSE vd.subtotal END)";
    $caja = "(CASE WHEN v.subtotal > 0 THEN v.descuento * vd.subtotal / v.subtotal ELSE 0 END)";
    $negociado = "(vd.promocion_id IS NULL AND vd.es_muestra = 0 AND vd.precio_lista IS NOT NULL
                   AND vd.precio_lista * vd.cantidad > vd.subtotal + 0.005)";
    $motivo = "COALESCE(NULLIF(v.descuento_motivo,''),'manual')";
    return [
        'gs'        => $gs,
        'caja'      => $caja,
        'ns'        => "(vd.subtotal - $caja)",
        'costo'     => "(vd.cantidad * vd.costo_unitario)",
        'negociado' => $negociado,
        'tipo'      => "(CASE WHEN vd.es_muestra = 1 THEN 'muestra'
                              WHEN vd.promocion_id IS NOT NULL THEN COALESCE(NULLIF(pm.tipo_descuento,''),'promocion')
                              WHEN $negociado THEN 'negociado'
                              WHEN v.descuento > 0 THEN $motivo
                              ELSE 'sin' END)",
        // Un paso más fino que el tipo: la promoción concreta o el motivo de caja.
        'mecanismo' => "(CASE WHEN vd.es_muestra = 1 THEN 'muestra'
                              WHEN vd.promocion_id IS NOT NULL THEN CONCAT('p', vd.promocion_id)
                              WHEN $negociado THEN 'negociado'
                              WHEN v.descuento > 0 THEN CONCAT('c:', $motivo)
                              ELSE 'sin' END)",
    ];
}

/** Columnas agregadas estándar (alias: gs, promo, caja, ns, costo, qty, tickets, sin_lista). */
function cockpit_sumas_sql(): string
{
    $x = cockpit_expr();
    return "COALESCE(SUM({$x['gs']}),0) gs,
            COALESCE(SUM({$x['gs']} - vd.subtotal),0) promo,
            COALESCE(SUM({$x['caja']}),0) caja,
            COALESCE(SUM({$x['ns']}),0) ns,
            COALESCE(SUM({$x['costo']}),0) costo,
            COALESCE(SUM(vd.cantidad),0) qty,
            COUNT(DISTINCT v.id) tickets,
            COALESCE(SUM(CASE WHEN vd.precio_lista IS NULL AND vd.es_muestra = 0 THEN vd.subtotal ELSE 0 END),0) sin_lista";
}

/* ============================================================
 *  Filtros
 * ============================================================ */

/**
 * Lee los filtros de la URL.
 *
 * TY por defecto: el periodo configurado (año a la fecha, salvo que la marca
 * elija otro). LY por defecto: el mismo rango un
 * año antes (la comparación justa: no se comparan nueve meses contra doce).
 *
 * @return array{ty:array{0:string,1:string},ly:array{0:string,1:string},canal:?string,marca:?int,samestore:bool,segmento:?string}
 */
function cockpit_filtros(): array
{
    $fecha = function (string $k, string $def): string {
        $v = trim((string) get($k));
        return ($v !== '' && ($t = strtotime($v))) ? date('Y-m-d', $t) : $def;
    };
    [$defD, $defH] = cockpit_presets()[(string) cockpit_param('periodo_defecto', 'ytd')][1] ?? cockpit_presets()['ytd'][1];
    $tyD = $fecha('ty_desde', $defD);
    $tyH = $fecha('ty_hasta', $defH);
    if ($tyD > $tyH) [$tyD, $tyH] = [$tyH, $tyD];
    $lyD = $fecha('ly_desde', cockpit_un_anio_antes($tyD));
    $lyH = $fecha('ly_hasta', cockpit_un_anio_antes($tyH));
    if ($lyD > $lyH) [$lyD, $lyH] = [$lyH, $lyD];

    $canal = (string) get('canal');
    return [
        'ty'        => [$tyD, $tyH],
        'ly'        => [$lyD, $lyH],
        'canal'     => array_key_exists($canal, cockpit_canales()) ? $canal : null,
        'marca'     => (int) get('marca_id') ?: null,
        'segmento'  => trim((string) get('segmento')) ?: null,
        'linea'     => trim((string) get('linea')) ?: null,
        'samestore' => get('samestore') === '1',
    ];
}

/** Periodos rápidos: clave => [etiqueta, [desde, hasta]]. El año fiscal usa el mes configurado. */
function cockpit_presets(): array
{
    $mesF = min(12, max(1, (int) cockpit_param('mes_inicio_fiscal', 4)));
    $anioF = (int) date('n') >= $mesF ? (int) date('Y') : (int) date('Y') - 1;
    $iniF = sprintf('%04d-%02d-01', $anioF, $mesF);
    $nombreMes = function_exists('mesNombre') ? mb_strtolower(mesNombre($mesF, true)) : (string) $mesF;
    return [
        'ytd'        => ['Año a la fecha', [date('Y-01-01'), date('Y-m-d')]],
        'fiscal'     => ['Año fiscal (desde ' . $nombreMes . ')', [$iniF, date('Y-m-d')]],
        'trimestre'  => ['Trimestre', [date('Y-m-01', mktime(0, 0, 0, (int) (ceil(date('n') / 3) - 1) * 3 + 1, 1)), date('Y-m-d')]],
        'mes'        => ['Este mes', [date('Y-m-01'), date('Y-m-d')]],
        'mes_pasado' => ['Mes pasado', [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))]],
        'u12'        => ['Últimos 12 meses', [date('Y-m-d', strtotime('-1 year +1 day')), date('Y-m-d')]],
    ];
}

/** La misma fecha un año antes; un 29 de febrero cae en el 28. */
function cockpit_un_anio_antes(string $d): string
{
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    $dd = min($dd, (int) date('t', mktime(0, 0, 0, $m, 1, $y - 1)));
    return sprintf('%04d-%02d-%02d', $y - 1, $m, $dd);
}

/**
 * Sucursales comparables («same store»): vendieron en los dos periodos.
 * Una tienda abierta este año inflaría el crecimiento; una cerrada, lo hundiría.
 */
function cockpit_samestore(array $f): array
{
    static $cache = [];
    $k = implode('|', array_merge($f['ty'], $f['ly']));
    if (isset($cache[$k])) return $cache[$k];
    $en = fn(array $r) => array_map('intval', qCol(
        "SELECT DISTINCT v.sucursal_id FROM ventas v WHERE " . rep_estados_venta('v') . " AND v.fecha BETWEEN ? AND ?",
        [$r[0] . ' 00:00:00', $r[1] . ' 23:59:59']
    ));
    return $cache[$k] = array_values(array_intersect($en($f['ty']), $en($f['ly'])));
}

/**
 * WHERE de un periodo con todos los filtros aplicados.
 *
 * @param bool $porLinea  Si la consulta entra por la línea (hay alias pr/vd),
 *                        se pueden aplicar los filtros de producto.
 * @return array{0:string,1:array}
 */
function cockpit_where(array $f, array $rango, bool $porLinea = true): array
{
    $w = [rep_estados_venta('v'), 'v.fecha BETWEEN ? AND ?'];
    $p = [$rango[0] . ' 00:00:00', $rango[1] . ' 23:59:59'];

    [$ws, $ps] = rep_scope('v.sucursal_id');
    $w[] = $ws; $p = array_merge($p, $ps);
    if (function_exists('tiendas_hay') && tiendas_hay()) {
        [$wt, $pt] = tiendaScope('v.tienda_id');
        $w[] = $wt; $p = array_merge($p, $pt);
    }
    if ($f['canal']) {
        $w[] = cockpit_canal_sql('v') . ' = ?';
        $p[] = $f['canal'];
    }
    if ($f['samestore']) {
        $ids = cockpit_samestore($f);
        $w[] = $ids ? 'v.sucursal_id IN (' . implode(',', $ids) . ')' : '1=0';
    }
    if ($porLinea && $f['marca']) {
        $w[] = 'pr.marca_id = ?';
        $p[] = $f['marca'];
    }
    if ($porLinea && !empty($f['linea'])) {
        $w[] = "COALESCE(NULLIF(pr.linea,''), 'Sin línea') = ?";
        $p[] = $f['linea'];
    }
    if ($porLinea && $f['segmento']) {
        // Mismo respaldo que el gráfico: tocar «Sin segmento» tiene que encontrar algo.
        $w[] = "COALESCE(NULLIF(pr.segmento,''), (SELECT c.nombre FROM categorias c WHERE c.id = pr.categoria_id), 'Sin segmento') = ?";
        $p[] = $f['segmento'];
    }
    return [implode(' AND ', $w), $p];
}

/* ============================================================
 *  Consultas
 * ============================================================ */

/**
 * Sumas agrupadas por una expresión (o totales si $grupo es null).
 *
 * @return array<string,array> clave del grupo => sumas
 */
function cockpit_por(array $f, array $rango, ?string $grupo = null, string $joinsExtra = ''): array
{
    [$w, $p] = cockpit_where($f, $rango);
    $sel = $grupo ? "$grupo AS g," : "'total' AS g,";
    $sql = "SELECT $sel " . cockpit_sumas_sql() . ' ' . cockpit_from() . " $joinsExtra WHERE $w"
         . ($grupo ? ' GROUP BY g' : '');
    $out = [];
    foreach (qAll($sql, $p) as $r) {
        $out[(string) $r['g']] = array_map('floatval', array_diff_key($r, ['g' => 1]));
    }
    return $out;
}

function cockpit_totales(array $f, array $rango): array
{
    return cockpit_por($f, $rango)['total'] ?? cockpit_vacio();
}

function cockpit_vacio(): array
{
    return ['gs' => 0.0, 'promo' => 0.0, 'caja' => 0.0, 'ns' => 0.0, 'costo' => 0.0, 'qty' => 0.0, 'tickets' => 0.0, 'sin_lista' => 0.0];
}

/** Suma varias filas de sumas. */
function cockpit_sumar(array $filas): array
{
    $t = cockpit_vacio();
    foreach ($filas as $r) foreach ($t as $k => $_) $t[$k] += (float) ($r[$k] ?? 0);
    return $t;
}

/**
 * Indicadores derivados de una fila de sumas.
 *
 *   desc        venta bruta − venta neta (promoción + caja + regalos)
 *   margen_gs   margen antes de descuentos sobre la venta bruta
 *   margen_ns   margen real sobre la venta neta
 *   pts         puntos de margen que este tipo le quita al TOTAL: su descuento
 *               sobre la venta bruta total. La suma de las filas da la tasa de
 *               descuento total, así se ve qué familia se come el margen.
 */
function cockpit_metricas(array $r, float $gsTotal = 0.0): array
{
    $gs = (float) $r['gs']; $ns = (float) $r['ns']; $c = (float) $r['costo'];
    $desc = $gs - $ns;
    return $r + [
        'desc'        => $desc,
        'desc_pct'    => $gs > 0 ? $desc / $gs * 100 : 0.0,
        'margen'      => $ns - $c,
        'margen_gs'   => $gs > 0 ? ($gs - $c) / $gs * 100 : 0.0,
        'margen_ns'   => $ns > 0 ? ($ns - $c) / $ns * 100 : 0.0,
        'peso_gs'     => $gsTotal > 0 ? $gs / $gsTotal * 100 : 0.0,
        'pts'         => $gsTotal > 0 ? $desc / $gsTotal * 100 : 0.0,
        'atv'         => ($r['tickets'] ?? 0) > 0 ? $ns / $r['tickets'] : 0.0,
    ];
}

/**
 * Efectos sobre el margen, TY contra LY, de un tipo de descuento.
 *
 * Con m = margen / venta bruta, s = peso del tipo en la venta bruta total,
 * d = descuento / venta bruta y k = costo / venta bruta (m = 1 − d − k):
 *
 *   Volumen          (GS_ty − GS_ly) · s_ly · m_ly     vender más o menos en total
 *   Mezcla           GS_ty · (s_ty − s_ly) · m_ly      más peso en este tipo
 *   Tasa de desc.    −GS_t,ty · (d_ty − d_ly)          descontar más hondo
 *   Mezcla producto  −GS_t,ty · (k_ty − k_ly)          vender artículos de otro costo
 *
 * Los cuatro suman exactamente la variación del margen del tipo, y las filas
 * suman la del total: no queda un «residuo» sin explicar.
 */
function cockpit_efectos(array $ty, array $ly, float $gsTotTy, float $gsTotLy): array
{
    $gT = (float) $ty['gs']; $gL = (float) $ly['gs'];
    $sT = $gsTotTy > 0 ? $gT / $gsTotTy : 0.0;
    $sL = $gsTotLy > 0 ? $gL / $gsTotLy : 0.0;
    $mL = $gL > 0 ? ((float) $ly['ns'] - (float) $ly['costo']) / $gL : 0.0;
    $dT = $gT > 0 ? ($gT - (float) $ty['ns']) / $gT : 0.0;
    $dL = $gL > 0 ? ($gL - (float) $ly['ns']) / $gL : 0.0;
    $kT = $gT > 0 ? (float) $ty['costo'] / $gT : 0.0;
    $kL = $gL > 0 ? (float) $ly['costo'] / $gL : 0.0;

    // Un tipo que no existía el año pasado no tiene tasas LY: todo su margen
    // es mezcla (apareció) y no se le atribuye un cambio de tasa ficticio.
    if ($gL <= 0) {
        $total = ((float) $ty['ns'] - (float) $ty['costo']);
        return ['volumen' => 0.0, 'mezcla' => $total, 'tasa' => 0.0, 'producto' => 0.0, 'total' => $total];
    }
    $e = [
        'volumen'  => ($gsTotTy - $gsTotLy) * $sL * $mL,
        'mezcla'   => $gsTotTy * ($sT - $sL) * $mL,
        'tasa'     => -$gT * ($dT - $dL),
        'producto' => -$gT * ($kT - $kL),
    ];
    $e['total'] = array_sum($e);
    return $e;
}

/** Serie mensual: venta bruta, neta y bruta en promoción. @return array<string,array> 'Y-m' => sumas */
function cockpit_mensual(array $f, array $rango): array
{
    $x = cockpit_expr();
    [$w, $p] = cockpit_where($f, $rango);
    $rows = qAll(
        "SELECT DATE_FORMAT(v.fecha, '%Y-%m') ym,
                COALESCE(SUM({$x['gs']}),0) gs, COALESCE(SUM({$x['ns']}),0) ns,
                COALESCE(SUM(CASE WHEN {$x['tipo']} <> 'sin' THEN {$x['gs']} ELSE 0 END),0) gs_promo
           " . cockpit_from() . " WHERE $w GROUP BY ym ORDER BY ym",
        $p
    );
    $out = [];
    foreach ($rows as $r) $out[$r['ym']] = array_map('floatval', ['gs' => $r['gs'], 'ns' => $r['ns'], 'gs_promo' => $r['gs_promo']]);
    return $out;
}

/**
 * Sell-out en UNA pasada: venta neta agrupada a la vez por mes, sucursal, canal,
 * tienda, segmento y línea. Salen unos cientos de filas y el reparto por cada
 * dimensión se hace en PHP. Con una consulta por dimensión y periodo la
 * pestaña hacía catorce barridos del periodo (3 s con 60.000 ventas).
 *
 * @return array<int,array{ym:string,sucursal:string,canal:string,tienda:string,segmento:string,linea:string,ns:float,gs:float}>
 */
function cockpit_cubo(array $f, array $rango): array
{
    $x = cockpit_expr();
    [$w, $p] = cockpit_where($f, $rango);
    $filas = qAll(
        // Alias con prefijo a propósito: «canal» a secas es también una columna
        // de `promociones`, y el GROUP BY agrupaba por ella en vez del alias.
        "SELECT DATE_FORMAT(v.fecha, '%Y-%m') k_ym, v.sucursal_id k_suc, " . cockpit_canal_sql('v') . " k_can, COALESCE(v.tienda_id, 0) k_tie,
                COALESCE(NULLIF(pr.segmento,''), c.nombre, 'Sin segmento') k_seg, COALESCE(NULLIF(pr.linea,''), 'Sin línea') k_lin,
                SUM({$x['ns']}) ns, SUM({$x['gs']}) gs
           " . cockpit_from() . " LEFT JOIN categorias c ON c.id = pr.categoria_id
          WHERE $w GROUP BY k_ym, k_suc, k_can, k_tie, k_seg, k_lin",
        $p
    );
    $suc = array_column(qAll("SELECT id, nombre FROM sucursales"), 'nombre', 'id');
    $tie = function_exists('tiendas_hay') && tiendas_hay() ? array_column(qAll("SELECT id, nombre FROM tiendas"), 'nombre', 'id') : [];
    return array_map(fn($r) => [
        'ym' => $r['k_ym'], 'sucursal' => $suc[$r['k_suc']] ?? ('#' . $r['k_suc']), 'canal' => (string) $r['k_can'],
        'tienda' => $tie[$r['k_tie']] ?? 'Sin marca', 'segmento' => (string) $r['k_seg'], 'linea' => (string) $r['k_lin'],
        'ns' => (float) $r['ns'], 'gs' => (float) $r['gs'],
    ], $filas);
}

/** Suma el cubo por una o varias dimensiones (unidas con «|»). @return array<string,float> */
function cockpit_cubo_por(array $cubo, string ...$dims): array
{
    $out = [];
    foreach ($cubo as $r) {
        $k = implode('|', array_map(fn($d) => $r[$d], $dims));
        $out[$k] = ($out[$k] ?? 0.0) + $r['ns'];
    }
    return $out;
}

/** Meses ('Y-m') que cubre un rango. */
function cockpit_meses(array $rango): array
{
    $out = [];
    $d = strtotime(substr($rango[0], 0, 7) . '-01');
    $fin = strtotime(substr($rango[1], 0, 7) . '-01');
    while ($d <= $fin && count($out) < 60) {
        $out[] = date('Y-m', $d);
        $d = strtotime('+1 month', $d);
    }
    return $out;
}

/**
 * Stacking: cuántos descuentos distintos lleva cada factura.
 *
 * Una factura suma un descuento por cada promoción distinta que ganó en sus
 * líneas, uno si trae muestras, uno si trae un precio negociado y uno si lleva
 * descuento en caja.
 *
 * @return array{por_mes:array<string,array{tickets:float,n:float}>,dist:array<int,float>,gs:float,tickets:float,n:float}
 *         dist = venta bruta por número de descuentos (1..5, 5 = «5 o más»)
 */
function cockpit_stacking(array $f, array $rango): array
{
    $x = cockpit_expr();
    [$w, $p] = cockpit_where($f, $rango);
    $rows = qAll(
        "SELECT t.ym, LEAST(t.n, 5) n, COUNT(*) tickets, SUM(t.gs) gs FROM (
            SELECT v.id,
                   MAX(DATE_FORMAT(v.fecha, '%Y-%m')) ym,
                   COUNT(DISTINCT vd.promocion_id)
                     + MAX(vd.es_muestra)
                     + MAX(CASE WHEN {$x['negociado']} THEN 1 ELSE 0 END)
                     + MAX(CASE WHEN v.descuento > 0 THEN 1 ELSE 0 END) n,
                   SUM({$x['gs']}) gs
              " . cockpit_from() . " WHERE $w GROUP BY v.id
         ) t WHERE t.n > 0 GROUP BY t.ym, LEAST(t.n, 5)",
        $p
    );
    $porMes = []; $dist = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0];
    $gs = 0.0; $tk = 0.0; $n = 0.0;
    foreach ($rows as $r) {
        $porMes[$r['ym']] ??= ['tickets' => 0.0, 'n' => 0.0];
        $porMes[$r['ym']]['tickets'] += (float) $r['tickets'];
        $porMes[$r['ym']]['n'] += (float) $r['tickets'] * (int) $r['n'];
        $dist[(int) $r['n']] += (float) $r['gs'];
        $gs += (float) $r['gs']; $tk += (float) $r['tickets']; $n += (float) $r['tickets'] * (int) $r['n'];
    }
    return ['por_mes' => $porMes, 'dist' => $dist, 'gs' => $gs, 'tickets' => $tk, 'n' => $n];
}

/**
 * Detalle por mecanismo de descuento (cada promoción, cada motivo de caja…).
 *
 * `activaciones` son las facturas en que se usó; `atv` el ticket neto medio de
 * esas facturas completas (lo que el cliente se llevó, no solo la línea en promo).
 *
 * Con $incluirInactivas, las promociones que estuvieron vigentes en el periodo
 * y nadie usó salen con cero: son justo las que hay que revisar.
 *
 * @return array<string,array>
 */
function cockpit_mecanismos(array $f, array $rango, bool $incluirInactivas = false): array
{
    $x = cockpit_expr();
    $filas = cockpit_por($f, $rango, $x['mecanismo']);
    unset($filas['sin']);

    [$w, $p] = cockpit_where($f, $rango);
    $act = [];
    foreach (qAll(
        "SELECT t.m, COUNT(*) act, SUM(v2.subtotal - v2.descuento) neto FROM (
            SELECT DISTINCT {$x['mecanismo']} m, v.id vid " . cockpit_from() . " WHERE $w
         ) t JOIN ventas v2 ON v2.id = t.vid WHERE t.m <> 'sin' GROUP BY t.m",
        $p
    ) as $r) {
        $act[$r['m']] = ['act' => (float) $r['act'], 'neto' => (float) $r['neto']];
    }

    $promos = [];
    foreach (qAll("SELECT id, nombre, codigo, tipo_descuento, tipo, valor, fecha_inicio, fecha_fin FROM promociones") as $pr) {
        $promos['p' . $pr['id']] = $pr;
    }
    if ($incluirInactivas) {
        foreach ($promos as $k => $pr) {
            if (!isset($filas[$k]) && $pr['fecha_inicio'] <= $rango[1] && $pr['fecha_fin'] >= $rango[0]) {
                $filas[$k] = cockpit_vacio();
            }
        }
    }

    $motivos = cockpit_motivos_caja();
    $out = [];
    foreach ($filas as $k => $r) {
        if (isset($promos[$k])) {
            $pr = $promos[$k];
            $tipo = $pr['tipo_descuento'] ?: 'promocion';
            $nombre = ($pr['codigo'] ? $pr['codigo'] . ' - ' : '') . $pr['nombre'];
            $detalle = ($pr['tipo'] === 'porcentaje' ? rtrim(rtrim(number_format((float) $pr['valor'], 2), '0'), '.') . '%' : money((float) $pr['valor']))
                     . ' · ' . fechaCorta($pr['fecha_inicio']) . ' al ' . fechaCorta($pr['fecha_fin']);
        } elseif (str_starts_with($k, 'p')) {
            $tipo = 'promocion'; $nombre = 'Promoción eliminada #' . substr($k, 1); $detalle = '';
        } elseif (str_starts_with($k, 'c:')) {
            $tipo = substr($k, 2);
            $nombre = 'Descuento en caja · ' . ($motivos[$tipo] ?? $tipo);
            $detalle = 'Manual, en la factura';
        } else {
            $tipo = $k; $nombre = cockpit_tipo_label($k); $detalle = '';
        }
        $out[$k] = $r + [
            'tipo' => $tipo, 'nombre' => $nombre, 'detalle' => $detalle,
            'act' => $act[$k]['act'] ?? 0.0,
            'atv_ticket' => ($act[$k]['act'] ?? 0) > 0 ? $act[$k]['neto'] / $act[$k]['act'] : 0.0,
        ];
    }
    return $out;
}

/** Opciones de segmento que existen (producto o, si falta, categoría). */
function cockpit_segmentos(): array
{
    return qCol("SELECT DISTINCT COALESCE(NULLIF(p.segmento,''), c.nombre) s
                   FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id
                  WHERE COALESCE(NULLIF(p.segmento,''), c.nombre) IS NOT NULL ORDER BY s");
}

/* ============================================================
 *  Formato
 * ============================================================ */

/** Número sin decimales con separador de miles (las tablas del cockpit son de miles). */
function cockpit_n(float $v): string
{
    return number_format($v, 0);
}

function cockpit_pct(float $v, int $dec = 1): string
{
    return number_format($v, $dec) . '%';
}

/** Diferencia en puntos, con signo. */
function cockpit_pts(float $v): string
{
    return ($v >= 0 ? '+' : '−') . number_format(abs($v), 1) . ' pts';
}

/** Celda de efecto: verde si suma margen, rosa si lo resta. */
function cockpit_celda_efecto(float $v): string
{
    if (abs($v) < 0.5) return '<span class="text-slate-300 tabular-nums">0</span>';
    $cls = $v >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700';
    return '<span class="inline-block px-1.5 py-0.5 rounded tabular-nums ' . $cls . '">'
        . ($v < 0 ? '−' : '') . number_format(abs($v), 0) . '</span>';
}
