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

/**
 * ¿Ya existen las columnas de rastro de la P39 en la VENTA? Las tres que se
 * escriben al facturar. Si la P39 se quedó a medias, mejor no escribir ninguna
 * que tumbar cada venta del POS.
 *
 * Va en el camino de cada venta, así que un «sí» se recuerda en la sesión: las
 * columnas no desaparecen, y así el POS no consulta information_schema en cada
 * cobro. Un «no» se vuelve a mirar (la migración puede correr en cualquier momento).
 */
function cockpit_capturando(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!empty($_SESSION['cockpit_capturando'])) return $ok = true;
    $ok = cockpit_columnas("(TABLE_NAME = 'venta_detalles' AND COLUMN_NAME IN ('precio_lista','promocion_id'))
                            OR (TABLE_NAME = 'ventas' AND COLUMN_NAME = 'descuento_motivo')", 3);
    if ($ok && session_status() === PHP_SESSION_ACTIVE) $_SESSION['cockpit_capturando'] = 1;
    return $ok;
}

/** Lo mismo para los pedidos de la tienda en línea (instalaciones sin tienda no las tienen). */
function cockpit_capturando_pedidos(): bool
{
    static $ok = null;
    return $ok ??= cockpit_columnas("TABLE_NAME = 'pedido_detalles' AND COLUMN_NAME IN ('precio_lista','promocion_id')", 2);
}

/** ¿Existen exactamente $n columnas que cumplen $cond en esta base? */
function cockpit_columnas(string $cond, int $n): bool
{
    try {
        return (int) qVal("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ($cond)") === $n;
    } catch (Throwable $e) {
        return false;
    }
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
            // Las seis: una P40 a medias (o una tabla borrada a mano) no puede
            // tumbar el cockpit; mientras falte alguna, se usan los valores de fábrica.
            $ok = (int) qVal("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
                              ('cockpit_tipos','cockpit_canales','cockpit_canal_reglas','kpi_rubros','kpi_metricas_def','cockpit_parametros')") === 6;
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
    if (array_key_exists($tabla, $cache)) return $cache[$tabla];
    try {
        return $cache[$tabla] = qAll("SELECT * FROM $tabla ORDER BY $orden");
    } catch (PDOException $e) {
        // Solo una tabla que no existe (42S02) cae a los valores de fábrica. Otro
        // error (un bloqueo, un corte) sube: clasificar en silencio con otros
        // catálogos daría cifras distintas de lo configurado sin avisar a nadie.
        if ($e->getCode() !== '42S02') throw $e;
        error_log("[cockpit] falta la tabla de configuración $tabla: se usan los valores de fábrica");
        return $cache[$tabla] = null;
    }
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
        'tasas_reporte'     => ['Tasas fijas de reporte (una por línea: USD=59.50)', 'lineas', 'Pesos por unidad. El cockpit puede verse en esas monedas; siempre a esta tasa fija, nunca a la del día.', ''],
        'tasa_desc_objetivo'   => ['Tasa de descuento objetivo (máximo, % de la venta bruta)', 'pct', 'Lo que la marca acepta descontar. Vacío = sin objetivo. El cockpit, el resumen por correo y las notificaciones avisan al pasarse.', ''],
        'venta_promo_objetivo' => ['Venta en promoción objetivo (máximo, % de la venta bruta)', 'pct', 'Cuánta venta puede ir con descuento. Vacío = sin objetivo.', ''],
        'canales_captacion' => ['Canales de captación del POS (uno por línea)', 'lineas', 'Las opciones que el cajero elige al vender. Lo que quites no se borra de las ventas viejas.', "Mostrador\nInstagram\nWhatsApp\nFacebook\nReferido\nOtro"],
    ];
}

/**
 * Lee un porcentaje escrito por una persona («12», «12,5»). null si está vacío;
 * false si no es un número entre 0 y 100. Lo comparten los objetivos y la
 * pantalla de configuración que los guarda.
 */
function cockpit_leer_pct($v)
{
    $v = trim(str_replace(',', '.', (string) $v));
    if ($v === '') return null;
    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 100) return false;
    return round((float) $v, 2);
}

/** Un objetivo en % configurado, o null si no hay (vacío o inválido). */
function cockpit_objetivo(string $clave): ?float
{
    $v = cockpit_leer_pct(cockpit_param($clave, ''));
    return $v === false ? null : $v;
}

/** Tope de descuento de un tipo (% de su venta bruta), o null. */
function cockpit_tope_tipo(string $k): ?float
{
    $t = cockpit_tipos_def()[$k]['tope'] ?? null;
    return $t === null ? null : (float) $t;
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
                           'etiqueta_caja' => $r['etiqueta_caja'], 'sistema' => (int) $r['sistema'], 'activo' => (int) $r['activo'],
                           // Columna posterior: sin ella, ningún tipo tiene tope.
                           'tope' => isset($r['tope_desc_pct']) && $r['tope_desc_pct'] !== '' ? (float) $r['tope_desc_pct'] : null];
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

/**
 * Todo motivo de caja que exista, activo o no. Para VALIDAR lo que llega: una
 * venta hecha sin conexión, o en un POS cargado antes de que alguien apagara el
 * motivo, conserva el motivo que el cajero eligió.
 */
function cockpit_motivos_caja_todos(): array
{
    $out = ['manual' => 'Descuento manual'];
    foreach (cockpit_tipos_def() as $k => $r) if ($r['etiqueta_caja'] !== null && $r['etiqueta_caja'] !== '') $out[$k] = $r['etiqueta_caja'];
    return $out;
}

/** Familias de promoción que existen, activas o no (para validar al guardar). */
function cockpit_tipos_promocion_todos(): array
{
    $out = [];
    foreach (cockpit_tipos_def() as $k => $r) if ($r['es_promocion']) $out[$k] = $r['nombre'] . ($r['activo'] ? '' : ' (inactiva)');
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
    // Moneda de reporte: todo importe pasa por aquí, así tablas, gráficos y
    // exportaciones salen en la misma moneda sin convertir nada más.
    $d = cockpit_divisor();
    $m = fn(string $sql) => $d == 1.0 ? $sql : "($sql / $d)";
    return [
        'gs'        => $m($gs),
        'caja'      => $m($caja),
        'ns'        => $m("(vd.subtotal - $caja)"),
        'costo'     => $m("(vd.cantidad * vd.costo_unitario)"),
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
    // ?periodo=mes_pasado se mueve con el calendario (lo usan las vistas guardadas).
    $periodo = (string) get('periodo');
    $periodo = isset(cockpit_presets()[$periodo]) ? $periodo : (string) cockpit_param('periodo_defecto', 'ytd');
    [$defD, $defH] = cockpit_presets()[$periodo][1] ?? cockpit_presets()['ytd'][1];
    $tyD = $fecha('ty_desde', $defD);
    $tyH = $fecha('ty_hasta', $defH);
    if ($tyD > $tyH) [$tyD, $tyH] = [$tyH, $tyD];
    // Retail compara sábado con sábado: «semana» mueve el año anterior 364 días
    // (52 semanas exactas) en vez de la misma fecha del calendario.
    $modo = get('ly_modo') === 'semana' ? 'semana' : 'fecha';
    $atras = fn(string $d) => $modo === 'semana' ? date('Y-m-d', strtotime($d . ' -364 days')) : cockpit_un_anio_antes($d);
    // El año anterior solo se respeta si alguien lo fijó a mano: si no, sigue a
    // las fechas de este año (cambiar TY o el modo lo recalcula).
    $manual = get('ly_manual') === '1' || (get('ly_manual') === '' && trim((string) get('ly_desde')) !== '');
    $lyD = $manual ? $fecha('ly_desde', $atras($tyD)) : $atras($tyD);
    $lyH = $manual ? $fecha('ly_hasta', $atras($tyH)) : $atras($tyH);
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
        'ly_modo'   => $modo,
        'ly_manual' => $manual,
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
    // Alcance fijo de quien llama (una campaña de una sucursal o una tienda).
    if (!empty($f['sucursal_fija'])) { $w[] = 'v.sucursal_id = ?'; $p[] = (int) $f['sucursal_fija']; }
    if (!empty($f['tienda_fija']))   { $w[] = 'v.tienda_id = ?';   $p[] = (int) $f['tienda_fija']; }
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
        "SELECT t.ym, LEAST(t.n, 5) n, COUNT(*) tickets, SUM(t.gs) gs, SUM(t.gs_p) gs_p, SUM(t.ns_p) ns_p FROM (
            SELECT v.id,
                   MAX(DATE_FORMAT(v.fecha, '%Y-%m')) ym,
                   COUNT(DISTINCT vd.promocion_id)
                     + MAX(vd.es_muestra)
                     + MAX(CASE WHEN {$x['negociado']} THEN 1 ELSE 0 END)
                     + MAX(CASE WHEN v.descuento > 0 THEN 1 ELSE 0 END) n,
                   SUM({$x['gs']}) gs,
                   -- Las líneas con algún descuento: el «% en promoción» sale de aquí
                   -- sin otro barrido del periodo.
                   SUM(CASE WHEN {$x['tipo']} <> 'sin' THEN {$x['gs']} ELSE 0 END) gs_p,
                   SUM(CASE WHEN {$x['tipo']} <> 'sin' THEN {$x['ns']} ELSE 0 END) ns_p
              " . cockpit_from() . " WHERE $w GROUP BY v.id
         ) t WHERE t.n > 0 GROUP BY t.ym, LEAST(t.n, 5)",
        $p
    );
    $porMes = []; $dist = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0];
    $gs = 0.0; $tk = 0.0; $n = 0.0; $gsP = 0.0; $nsP = 0.0;
    foreach ($rows as $r) {
        $gsP += (float) $r['gs_p']; $nsP += (float) $r['ns_p'];
        $porMes[$r['ym']] ??= ['tickets' => 0.0, 'n' => 0.0];
        $porMes[$r['ym']]['tickets'] += (float) $r['tickets'];
        $porMes[$r['ym']]['n'] += (float) $r['tickets'] * (int) $r['n'];
        $dist[(int) $r['n']] += (float) $r['gs'];
        $gs += (float) $r['gs']; $tk += (float) $r['tickets']; $n += (float) $r['tickets'] * (int) $r['n'];
    }
    return ['por_mes' => $porMes, 'dist' => $dist, 'gs' => $gs, 'tickets' => $tk, 'n' => $n, 'gs_promo' => $gsP, 'ns_promo' => $nsP];
}

/**
 * Tipo, nombre y detalle de un mecanismo ('p12', 'c:empleados', 'negociado'…)
 * sin tocar las ventas. $promos: las promociones por 'p'.id (null = las carga).
 * @return array{tipo:string, nombre:string, detalle:string}
 */
function cockpit_mecanismo_info(string $k, ?array $promos = null): array
{
    static $cache = null;
    if ($promos === null) {
        $cache ??= array_column(array_map(fn($pr) => ['k' => 'p' . $pr['id']] + $pr,
            qAll("SELECT id, nombre, codigo, tipo_descuento, tipo, valor, fecha_inicio, fecha_fin FROM promociones")), null, 'k');
        $promos = $cache;
    }
    if (isset($promos[$k])) {
        $pr = $promos[$k];
        return ['tipo' => $pr['tipo_descuento'] ?: 'promocion', 'nombre' => ($pr['codigo'] ? $pr['codigo'] . ' - ' : '') . $pr['nombre'],
                'detalle' => ($pr['tipo'] === 'porcentaje' ? rtrim(rtrim(number_format((float) $pr['valor'], 2), '0'), '.') . '%' : money((float) $pr['valor']))
                           . ' · ' . fechaCorta($pr['fecha_inicio']) . ' al ' . fechaCorta($pr['fecha_fin'])];
    }
    if (str_starts_with($k, 'p')) return ['tipo' => 'promocion', 'nombre' => 'Promoción eliminada #' . substr($k, 1), 'detalle' => ''];
    if (str_starts_with($k, 'c:')) {
        // Todos los motivos, activos o no: uno ya apagado sigue teniendo nombre en su historia.
        $motivo = substr($k, 2);
        return ['tipo' => $motivo, 'nombre' => 'Descuento en caja · ' . (cockpit_motivos_caja_todos()[$motivo] ?? $motivo), 'detalle' => 'Manual, en la factura'];
    }
    return ['tipo' => $k, 'nombre' => cockpit_tipo_label($k), 'detalle' => ''];
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
        $act[$r['m']] = ['act' => (float) $r['act'], 'neto' => (float) $r['neto'] / cockpit_divisor()];
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

    $out = [];
    foreach ($filas as $k => $r) {
        ['tipo' => $tipo, 'nombre' => $nombre, 'detalle' => $detalle] = cockpit_mecanismo_info($k, $promos);
        $out[$k] = $r + [
            'tipo' => $tipo, 'nombre' => $nombre, 'detalle' => $detalle,
            'act' => $act[$k]['act'] ?? 0.0,
            'atv_ticket' => ($act[$k]['act'] ?? 0) > 0 ? $act[$k]['neto'] / $act[$k]['act'] : 0.0,
        ];
    }
    return $out;
}

/* ============================================================
 *  Hallazgos automáticos
 * ============================================================ */

/**
 * Lo que una persona tendría que ver primero, en frases.
 *
 * Solo lee lo ya calculado para el resumen (no consulta nada): la tasa de
 * descuento, el margen, el tipo que más margen se lleva, el que más subió su
 * descuento y lo que más movió el margen. Cada hallazgo trae su tono (bueno,
 * malo, neutro) y, si aplica, el tipo al que lleva al tocarlo. Se ordenan por
 * cuánto pesan y se devuelven los $max más fuertes.
 *
 * @param array $total  ['ty'=>metricas, 'ly'=>metricas, 'ef'=>efectos] del total
 * @param array $tipos  clave => la misma estructura, por tipo de descuento
 * @param ?array $objetivos ['desc' => ?float, 'promo' => ?float, 'topes' => [tipo => float]];
 *                          null = los configurados (Configuración → Parámetros y Tipos)
 * @return array<int,array{tono:string,texto:string,tipo:?string,peso:float}>
 */
function cockpit_hallazgos(array $total, array $tipos, float $promoTY, float $promoLY, int $max = 5, ?array $objetivos = null): array
{
    $objetivos ??= ['desc' => cockpit_objetivo('tasa_desc_objetivo'), 'promo' => cockpit_objetivo('venta_promo_objetivo'),
                    'topes' => array_filter(array_map(fn($r) => $r['tope'] ?? null, cockpit_tipos_def()), fn($v) => $v !== null)];
    $h = [];
    $t = $total['ty']; $l = $total['ly'];
    $hayLy = ($l['gs'] ?? 0) > 0;
    $pts = fn(float $v) => ($v >= 0 ? '+' : '−') . number_format(abs($v), 1) . ' pts';
    $pc = fn(float $v) => number_format($v, 1) . '%';

    if ($hayLy) {
        $d = $t['desc_pct'] - $l['desc_pct'];
        if (abs($d) >= 0.3) {
            $h[] = ['tono' => $d > 0 ? 'malo' : 'bueno', 'tipo' => null, 'peso' => abs($d) * 3,
                'texto' => 'La tasa de descuento ' . ($d > 0 ? 'subió' : 'bajó') . ' a <b>' . $pc($t['desc_pct']) . '</b> (' . $pts($d) . ' contra el año anterior).'];
        }
        $dm = $t['margen_ns'] - $l['margen_ns'];
        if (abs($dm) >= 0.3) {
            $h[] = ['tono' => $dm > 0 ? 'bueno' : 'malo', 'tipo' => null, 'peso' => abs($dm) * 3,
                'texto' => 'El margen sobre la venta neta ' . ($dm > 0 ? 'mejoró' : 'empeoró') . ' a <b>' . $pc($t['margen_ns']) . '</b> (' . $pts($dm) . ').'];
        }
        $dp = $promoTY - $promoLY;
        if (abs($dp) >= 2) {
            $h[] = ['tono' => $dp > 0 ? 'malo' : 'bueno', 'tipo' => null, 'peso' => abs($dp),
                'texto' => 'Se vende más ' . ($dp > 0 ? '<b>en</b>' : '<b>sin</b>') . ' promoción: ' . $pc($promoTY) . ' de la venta bruta va con descuento (' . $pts($dp) . ').'];
        }
        // Lo que más movió el margen, en dinero.
        $ef = $total['ef'] ?? [];
        $nombres = ['volumen' => 'el volumen de venta', 'mezcla' => 'la mezcla entre tipos de descuento',
                    'tasa' => 'la profundidad de los descuentos', 'producto' => 'la mezcla de productos (su costo)'];
        $mayor = null;
        foreach ($nombres as $k => $_) if ($mayor === null || abs($ef[$k] ?? 0) > abs($ef[$mayor] ?? 0)) $mayor = $k;
        if ($mayor !== null && abs($ef['total'] ?? 0) >= 1 && abs($ef[$mayor]) >= 1) {
            $h[] = ['tono' => ($ef['total'] ?? 0) >= 0 ? 'bueno' : 'malo', 'tipo' => null, 'peso' => 5,
                'texto' => 'El margen ' . (($ef['total'] ?? 0) >= 0 ? 'ganó' : 'perdió') . ' <b>' . number_format(abs($ef['total']), 0) . '</b> contra el año anterior; lo que más pesó fue '
                    . $nombres[$mayor] . ' (' . (($ef[$mayor] >= 0) ? '+' : '−') . number_format(abs($ef[$mayor]), 0) . ').'];
        }
    }

    // El tipo que más margen se lleva y el que más subió su descuento.
    $conDesc = array_filter($tipos, fn($r, $k) => $k !== 'sin' && ($r['ty']['desc'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);
    if ($conDesc) {
        uasort($conDesc, fn($a, $b) => $b['ty']['pts'] <=> $a['ty']['pts']);
        $k = array_key_first($conDesc); $r = $conDesc[$k]['ty'];
        $share = $t['desc'] > 0 ? $r['desc'] / $t['desc'] * 100 : 0;
        $h[] = ['tono' => 'neutro', 'tipo' => $k, 'peso' => $r['pts'] * 2,
            'texto' => '<b>' . e(cockpit_tipo_label($k)) . '</b> es el tipo que más cuesta: ' . $pc($share) . ' de todo el descuento y '
                . number_format($r['pts'], 1) . ' pts de la venta bruta, con un descuento medio de ' . $pc($r['desc_pct']) . '.'];
        if ($hayLy) {
            $subidas = [];
            foreach ($conDesc as $k => $r) {
                if (($r['ty']['peso_gs'] ?? 0) < 1 || ($r['ly']['gs'] ?? 0) <= 0) continue;
                $subidas[$k] = $r['ty']['desc_pct'] - $r['ly']['desc_pct'];
            }
            if ($subidas) {
                arsort($subidas);
                $k = array_key_first($subidas);
                if ($subidas[$k] >= 1) {
                    $h[] = ['tono' => 'malo', 'tipo' => $k, 'peso' => $subidas[$k] * 1.5,
                        'texto' => '<b>' . e(cockpit_tipo_label($k)) . '</b> descuenta más hondo que el año pasado: ' . $pc($conDesc[$k]['ty']['desc_pct'])
                            . ' (' . $pts($subidas[$k]) . ').'];
                }
            }
        }
    }

    // Contra los objetivos de la marca: pasarse pesa más que cualquier variación,
    // porque es lo que la casa matriz pregunta primero.
    $gs = (float) ($t['gs'] ?? 0);
    if ($gs > 0 && ($obj = $objetivos['desc'] ?? null) !== null) {
        $d = $t['desc_pct'] - $obj;
        $h[] = $d > 0
            ? ['tono' => 'malo', 'tipo' => null, 'peso' => 20 + $d * 4,
               'texto' => 'La tasa de descuento (<b>' . $pc($t['desc_pct']) . '</b>) está por encima del objetivo de ' . $pc($obj) . ' (' . $pts($d) . ').']
            : ['tono' => 'bueno', 'tipo' => null, 'peso' => 1,
               'texto' => 'La tasa de descuento (<b>' . $pc($t['desc_pct']) . '</b>) está dentro del objetivo de ' . $pc($obj) . '.'];
    }
    if ($gs > 0 && ($obj = $objetivos['promo'] ?? null) !== null && $promoTY > $obj) {
        $h[] = ['tono' => 'malo', 'tipo' => null, 'peso' => 18 + ($promoTY - $obj) * 2,
            'texto' => 'La venta en promoción (<b>' . $pc($promoTY) . '</b>) supera el objetivo de ' . $pc($obj) . ' (' . $pts($promoTY - $obj) . ').'];
    }
    foreach ($objetivos['topes'] ?? [] as $k => $tope) {
        $r = $tipos[$k]['ty'] ?? null;
        // Un tipo con muy poca venta no merece la alarma (0,5% de la venta bruta).
        if (!$r || ($r['gs'] ?? 0) <= 0 || ($r['peso_gs'] ?? 0) < 0.5 || $r['desc_pct'] <= $tope) continue;
        $h[] = ['tono' => 'malo', 'tipo' => $k, 'peso' => 15 + ($r['desc_pct'] - $tope) * 2,
            'texto' => '<b>' . e(cockpit_tipo_label($k)) . '</b> descuenta ' . $pc($r['desc_pct']) . ', por encima de su tope de ' . $pc($tope)
                . ' (' . $pts($r['desc_pct'] - $tope) . ').'];
    }

    usort($h, fn($a, $b) => $b['peso'] <=> $a['peso']);
    return array_slice($h, 0, $max);
}

/**
 * Tasa de descuento de TODA la empresa en un rango, sin filtros de sesión (para
 * la notificación: la ve cualquiera con el permiso, no depende de quién barre).
 * @return array{gs:float, ns:float, desc_pct:float, promo_pct:float}
 */
function cockpit_tasa_empresa(string $desde, string $hasta): array
{
    $x = cockpit_expr();
    $r = qOne("SELECT COALESCE(SUM({$x['gs']}),0) gs, COALESCE(SUM({$x['ns']}),0) ns,
                      COALESCE(SUM(CASE WHEN {$x['tipo']} <> 'sin' THEN {$x['gs']} ELSE 0 END),0) gs_promo "
              . cockpit_from() . " WHERE " . rep_estados_venta('v') . " AND v.fecha BETWEEN ? AND ?",
              [$desde . ' 00:00:00', $hasta . ' 23:59:59']) ?: [];
    $gs = (float) ($r['gs'] ?? 0);
    return ['gs' => $gs, 'ns' => (float) ($r['ns'] ?? 0),
            'desc_pct' => $gs > 0 ? ($gs - (float) $r['ns']) / $gs * 100 : 0.0,
            'promo_pct' => $gs > 0 ? (float) $r['gs_promo'] / $gs * 100 : 0.0];
}

/**
 * Promociones que estuvieron vigentes en el rango y nadie usó (sin filtros de
 * sucursal: una promoción es de toda la empresa). @return array<int,array{id:int,nombre:string,codigo:?string}>
 */
function cockpit_promos_sin_uso(string $desde, string $hasta): array
{
    return qAll(
        "SELECT p.id, p.nombre, p.codigo FROM promociones p
          WHERE p.activo = 1 AND p.fecha_inicio <= ? AND p.fecha_fin >= ?
            AND NOT EXISTS (SELECT 1 FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
                             WHERE vd.promocion_id = p.id AND v.fecha BETWEEN ? AND ?)
          ORDER BY p.fecha_inicio",
        [$hasta, $desde, $desde . ' 00:00:00', $hasta . ' 23:59:59']
    );
}

/* ============================================================
 *  Efectividad de cada promoción
 * ============================================================ */

/**
 * ¿La promoción hizo vender más, y eso pagó el descuento?
 *
 * Para cada promoción vigente en el rango se toman los productos que se
 * vendieron CON ella y se comparan, por día, dos ventanas:
 *
 *   durante   sus días de vigencia dentro del rango (toda la venta de esos
 *             productos, con o sin la promo: si otra promo ganó en una línea,
 *             sigue siendo venta del periodo);
 *   base      el mismo número de días justo antes de que empezara (máximo
 *             $maxBase), sin tocar el rango.
 *
 *   aumento             unidades/día durante ÷ unidades/día base − 1
 *   margen incremental  (margen/día durante − margen/día base) × días
 *   costo del descuento venta bruta − venta neta de las líneas con la promo
 *   retorno             margen incremental ÷ costo del descuento
 *
 * Es una lectura, no un experimento: la base puede tener otra promoción o
 * otra temporada. Sin venta en la base no hay comparación y se dice.
 *
 * @return array<int,array> una fila por promoción, de mayor a menor costo
 */
function cockpit_efectividad(array $f, array $rango, int $maxBase = 28, int $tope = 40): array
{
    $x = cockpit_expr();
    [$w, $p] = cockpit_where($f, $rango);
    // Lo vendido CON cada promoción: productos y costo del descuento.
    $con = qAll(
        "SELECT vd.promocion_id k_pro, vd.producto_id k_prod, SUM({$x['gs']}) gs, SUM({$x['ns']}) ns, SUM(vd.cantidad) qty
           " . cockpit_from() . " WHERE $w AND vd.promocion_id IS NOT NULL AND vd.producto_id IS NOT NULL
          GROUP BY k_pro, k_prod",
        $p
    );
    $porPromo = [];
    foreach ($con as $r) {
        $k = (int) $r['k_pro'];
        $porPromo[$k]['prods'][] = (int) $r['k_prod'];
        $porPromo[$k]['costo_desc'] = ($porPromo[$k]['costo_desc'] ?? 0) + (float) $r['gs'] - (float) $r['ns'];
        $porPromo[$k]['gs_promo'] = ($porPromo[$k]['gs_promo'] ?? 0) + (float) $r['gs'];
    }
    if (!$porPromo) return [];
    uasort($porPromo, fn($a, $b) => $b['costo_desc'] <=> $a['costo_desc']);
    $porPromo = array_slice($porPromo, 0, $tope, true);
    $promos = [];
    foreach (qAll("SELECT id, nombre, codigo, tipo_descuento, tipo, valor, fecha_inicio, fecha_fin FROM promociones WHERE id IN ("
                  . implode(',', array_map('intval', array_keys($porPromo))) . ")") as $pr) {
        $promos[(int) $pr['id']] = $pr;
    }

    // Antes del primer día con ventas no hay «antes» que valga.
    $primeraVenta = (string) (qVal("SELECT MIN(fecha) FROM ventas WHERE " . rep_estados_venta('ventas')) ?? '');
    // Primero las ventanas de cada promoción; después UNA consulta producto × día
    // que cubre todas (antes era una por ventana: 1,4 s con 60.000 ventas).
    $plan = [];
    foreach ($porPromo as $id => $d) {
        $pr = $promos[$id] ?? null;
        if (!$pr) continue;
        $ini = max($pr['fecha_inicio'], $rango[0]);
        $fin = min($pr['fecha_fin'], $rango[1], date('Y-m-d'));
        if ($ini > $fin) continue;
        $dias = (int) floor((strtotime($fin) - strtotime($ini)) / 86400) + 1;
        $diasBase = min($dias, $maxBase);
        $bFin = date('Y-m-d', strtotime($pr['fecha_inicio'] . ' -1 day'));
        $bIni = date('Y-m-d', strtotime($bFin . ' -' . ($diasBase - 1) . ' days'));
        // Una promoción de meses no tiene un «antes» comparable: es el precio
        // normal de ese periodo. Tampoco si la base cae antes de que hubiera ventas.
        $larga = (strtotime($pr['fecha_fin']) - strtotime($pr['fecha_inicio'])) / 86400 > 90;
        $sinHistoria = $primeraVenta === '' || $bIni < substr($primeraVenta, 0, 10);
        $plan[$id] = compact('pr', 'ini', 'fin', 'dias', 'diasBase', 'bIni', 'bFin', 'larga', 'sinHistoria') + ['prods' => array_values(array_unique($d['prods']))];
    }
    if (!$plan) return [];
    $desde = min(array_map(fn($v) => $v['larga'] || $v['sinHistoria'] ? $v['ini'] : min($v['ini'], $v['bIni']), $plan));
    $hasta = max(array_column($plan, 'fin'));
    $todos = array_values(array_unique(array_merge(...array_column($plan, 'prods'))));
    [$w, $p] = cockpit_where($f, [$desde, $hasta]);
    $cubo = [];
    foreach (qAll("SELECT vd.producto_id k_prod, DATE(v.fecha) k_dia, SUM(vd.cantidad) qty, SUM({$x['ns']}) ns, SUM({$x['costo']}) costo "
                  . cockpit_from() . " WHERE $w AND vd.es_muestra = 0 AND vd.producto_id IN (" . implode(',', array_map('intval', $todos)) . ")
                   GROUP BY k_prod, k_dia", $p) as $r) {
        $cubo[(int) $r['k_prod']][$r['k_dia']] = [(float) $r['qty'], (float) $r['ns'], (float) $r['ns'] - (float) $r['costo']];
    }
    // Sumas acumuladas por producto: cada ventana cuesta dos búsquedas binarias
    // por producto, no un recorrido de todos sus días.
    $acum = [];
    foreach ($cubo as $pid => $porDia) {
        ksort($porDia);
        $dias = array_keys($porDia); $c = [[0.0, 0.0, 0.0]]; $t = [0.0, 0.0, 0.0];
        foreach ($porDia as [$q, $n, $m]) { $t[0] += $q; $t[1] += $n; $t[2] += $m; $c[] = $t; }
        $acum[$pid] = [$dias, $c];
    }
    // Cuántos días de la lista son <= $d (o < $d si $estricto).
    $hasta = function (array $dias, string $d, bool $estricto): int {
        $lo = 0; $hi = count($dias);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($estricto ? $dias[$mid] < $d : $dias[$mid] <= $d) $lo = $mid + 1; else $hi = $mid;
        }
        return $lo;
    };
    $suma = function (array $prods, string $ini, string $fin) use ($acum, $hasta) {
        $t = ['qty' => 0.0, 'ns' => 0.0, 'margen' => 0.0];
        foreach ($prods as $pid) {
            if (!isset($acum[$pid])) continue;
            [$dias, $c] = $acum[$pid];
            $a = $c[$hasta($dias, $ini, true)]; $b = $c[$hasta($dias, $fin, false)];
            $t['qty'] += $b[0] - $a[0]; $t['ns'] += $b[1] - $a[1]; $t['margen'] += $b[2] - $a[2];
        }
        return $t;
    };

    $out = [];
    foreach ($plan as $id => $v) {
        ['pr' => $pr, 'ini' => $ini, 'fin' => $fin, 'dias' => $dias, 'diasBase' => $diasBase, 'bIni' => $bIni, 'bFin' => $bFin,
         'larga' => $larga, 'sinHistoria' => $sinHistoria, 'prods' => $prods] = $v;
        $d = $porPromo[$id];
        $dur = $suma($prods, $ini, $fin);
        $base = ($larga || $sinHistoria) ? ['qty' => 0.0, 'ns' => 0.0, 'margen' => 0.0] : $suma($prods, $bIni, $bFin);
        $hayBase = $base['qty'] > 0;
        $motivo = $larga ? 'Promoción de más de 90 días: no hay un «antes» comparable'
                : ($sinHistoria ? 'Empezó antes de que el sistema tuviera ventas' : ($hayBase ? '' : 'Esos productos no vendieron en los días previos'));
        $udD = $dur['qty'] / $dias; $udB = $hayBase ? $base['qty'] / $diasBase : null;
        $mgD = $dur['margen'] / $dias; $mgB = $hayBase ? $base['margen'] / $diasBase : null;
        $aumento = $hayBase && $udB > 0 ? ($udD / $udB - 1) * 100 : null;
        $incr = $hayBase ? ($mgD - $mgB) * $dias : null;
        $out[] = [
            'id' => $id, 'nombre' => ($pr['codigo'] ? $pr['codigo'] . ' - ' : '') . $pr['nombre'],
            'tipo' => $pr['tipo_descuento'] ?: 'promocion',
            'profundidad' => $pr['tipo'] === 'porcentaje' ? (float) $pr['valor'] : null,
            'ventana' => [$ini, $fin], 'base' => [$bIni, $bFin], 'dias' => $dias, 'dias_base' => $diasBase,
            'productos' => count($prods), 'costo_desc' => $d['costo_desc'], 'gs_promo' => $d['gs_promo'],
            'ud_dia' => $udD, 'ud_dia_base' => $udB, 'ns_dia' => $dur['ns'] / $dias,
            'margen_dia' => $mgD, 'margen_dia_base' => $mgB,
            'aumento' => $aumento, 'margen_incremental' => $incr,
            'retorno' => $incr !== null && $d['costo_desc'] > 0 ? $incr / $d['costo_desc'] : null,
            'veredicto' => cockpit_veredicto($aumento, $incr), 'motivo' => $motivo, 'larga' => $larga,
        ];
    }
    return $out;
}

/** Lectura corta del resultado de una promoción. [clave, etiqueta, tono] */
function cockpit_veredicto(?float $aumento, ?float $incremental): array
{
    if ($aumento === null) return ['sin_base', 'Sin base para comparar', 'slate'];
    if ($incremental > 0) return $aumento > 0 ? ['rentable', 'Vendió más y ganó margen', 'emerald']
                                             : ['margen', 'Ganó margen sin vender más', 'sky'];
    if ($aumento > 5) return ['cara', 'Vendió más, pero el descuento costó más de lo que trajo', 'amber'];
    return ['sin_efecto', 'No movió la venta: el descuento se regaló', 'rose'];
}

/**
 * Clientes y promociones: de quién viene la venta con descuento.
 *
 *   nuevos        su primera compra de la historia cae en el rango
 *   recurrentes   ya habían comprado antes
 *   anónimos      consumidor final sin identificar
 *   dependientes  2+ facturas en el rango y 80%+ de su venta bruta con descuento
 *
 * @return array{grupos:array<string,array{gs:float,gs_promo:float,clientes:int}>,dependientes:array,n_dependientes:int,gs_dependientes:float}
 */
function cockpit_clientes_promo(array $f, array $rango): array
{
    $x = cockpit_expr();
    [$w, $p] = cockpit_where($f, $rango);
    $rows = qAll(
        "SELECT v.cliente_id k_cli, SUM({$x['gs']}) gs,
                SUM(CASE WHEN {$x['tipo']} <> 'sin' THEN {$x['gs']} ELSE 0 END) gsp, COUNT(DISTINCT v.id) t
           " . cockpit_from() . " WHERE $w GROUP BY k_cli",
        $p
    );
    $ids = array_values(array_filter(array_map(fn($r) => (int) $r['k_cli'], $rows), fn($id) => $id > 1));
    $primera = [];
    if ($ids) {
        foreach (array_chunk($ids, 1000) as $lote) {
            foreach (qAll("SELECT cliente_id, MIN(fecha) f FROM ventas WHERE " . rep_estados_venta('ventas') . "
                            AND cliente_id IN (" . implode(',', $lote) . ") GROUP BY cliente_id") as $r) {
                $primera[(int) $r['cliente_id']] = $r['f'];
            }
        }
    }
    $g = ['nuevos' => ['gs' => 0.0, 'gs_promo' => 0.0, 'clientes' => 0], 'recurrentes' => ['gs' => 0.0, 'gs_promo' => 0.0, 'clientes' => 0],
          'anonimos' => ['gs' => 0.0, 'gs_promo' => 0.0, 'clientes' => 0]];
    $dep = [];
    foreach ($rows as $r) {
        $id = (int) $r['k_cli'];
        $grupo = $id <= 1 ? 'anonimos' : (($primera[$id] ?? '') >= $rango[0] . ' 00:00:00' ? 'nuevos' : 'recurrentes');
        $g[$grupo]['gs'] += (float) $r['gs'];
        $g[$grupo]['gs_promo'] += (float) $r['gsp'];
        $g[$grupo]['clientes'] += $id > 1 ? 1 : 0;
        if ($id > 1 && (int) $r['t'] >= 2 && (float) $r['gs'] > 0 && (float) $r['gsp'] / (float) $r['gs'] >= 0.8) {
            $dep[$id] = ['gs' => (float) $r['gs'], 'pct' => (float) $r['gsp'] / (float) $r['gs'] * 100, 'facturas' => (int) $r['t']];
        }
    }
    uasort($dep, fn($a, $b) => $b['gs'] <=> $a['gs']);
    $top = array_slice($dep, 0, 10, true);
    if ($top) {
        $nombres = array_column(qAll("SELECT id, nombre FROM clientes WHERE id IN (" . implode(',', array_keys($top)) . ")"), 'nombre', 'id');
        foreach ($top as $id => &$t) $t['nombre'] = $nombres[$id] ?? ('#' . $id);
        unset($t);
    }
    return ['grupos' => $g, 'dependientes' => $top, 'n_dependientes' => count($dep), 'gs_dependientes' => array_sum(array_column($dep, 'gs'))];
}

/* ============================================================
 *  Simulador «¿qué pasa si…?»
 * ============================================================ */

/**
 * Qué costaría una promoción antes de lanzarla.
 *
 * Toma la venta real de los productos del alcance en los últimos $cfg['dias_base']
 * días (con los filtros del cockpit: sucursal, canal…) y la proyecta a
 * $cfg['dias'] días con y sin la promoción:
 *
 *   precio con promo   el de catálogo menos el descuento; si el precio al que ya
 *                      se vende es más bajo (otra promo), se queda ése: gana el
 *                      menor precio, igual que en el POS.
 *   sin promo          unidades/día × días al precio y costo actuales
 *   con promo          lo mismo × (1 + aumento esperado) al precio nuevo
 *   punto de equilibrio cuánto más hay que vender para que el margen con promo
 *                      iguale al de sin promo.
 *
 * @param array $cfg alcance, objetivo, tipo (porcentaje|monto), valor, dias, aumento (%), dias_base
 */
function cockpit_simular(array $f, array $cfg): array
{
    $x = cockpit_expr();
    $diasBase = max(7, (int) ($cfg['dias_base'] ?? 28));
    $hasta = date('Y-m-d', strtotime('-1 day'));
    $desde = date('Y-m-d', strtotime($hasta . ' -' . ($diasBase - 1) . ' days'));
    [$w, $p] = cockpit_where($f, [$desde, $hasta]);
    [$wa, $pa] = cockpit_sim_alcance((string) ($cfg['alcance'] ?? 'todos'), $cfg['objetivo'] ?? null);
    $rows = qAll(
        "SELECT vd.producto_id k_prod, MAX(pr.codigo) codigo, MAX(pr.nombre) nombre, MAX(pr.precio_venta) lista,
                SUM(vd.cantidad) qty, SUM({$x['ns']}) ns, SUM({$x['costo']}) costo
           " . cockpit_from() . " WHERE $w AND $wa AND vd.es_muestra = 0 AND vd.producto_id IS NOT NULL
          GROUP BY k_prod HAVING SUM(vd.cantidad) > 0 ORDER BY SUM({$x['ns']}) DESC",
        array_merge($p, $pa)
    );
    return ['base' => [$desde, $hasta, $diasBase], 'filas' => $rows, 'divisor' => cockpit_divisor()] + cockpit_sim_calcular($rows, $cfg, $diasBase, cockpit_divisor());
}

/**
 * La cuenta del simulador, sin base de datos (la cubren las pruebas).
 * $rows: por producto, qty, ns y costo del periodo base, y su precio de lista.
 */
function cockpit_sim_calcular(array $rows, array $cfg, int $diasBase, float $d = 1.0): array
{
    $dias = max(1, (int) ($cfg['dias'] ?? 14));
    $a = (float) ($cfg['aumento'] ?? 0) / 100;
    $valor = max(0.0, (float) ($cfg['valor'] ?? 0));
    $tot = ['u0' => 0.0, 'ns0' => 0.0, 'm0' => 0.0, 'u1' => 0.0, 'ns1' => 0.0, 'm1' => 0.0, 'regalo' => 0.0, 'mu0' => 0.0, 'mu1' => 0.0, 'nsb' => 0.0];
    $prods = [];
    foreach ($rows as $r) {
        $q = (float) $r['qty'];
        $p0 = (float) $r['ns'] / $q;
        $c = (float) $r['costo'] / $q;
        // Sin precio de lista en la ficha, se parte de lo que se cobró.
        $lista = (float) $r['lista'] > 0 ? (float) $r['lista'] / $d : $p0;
        // El monto se escribe en la moneda en que se está mirando.
        $p1 = ($cfg['tipo'] ?? 'porcentaje') === 'monto' ? max(0.0, $lista - $valor) : $lista * (1 - min(100.0, $valor) / 100);
        $p1 = min($p0, $p1);                      // gana el menor precio
        $u0 = $q / $diasBase * $dias;
        $u1 = $u0 * (1 + $a);
        $tot['u0'] += $u0; $tot['ns0'] += $u0 * $p0; $tot['m0'] += $u0 * ($p0 - $c);
        $tot['u1'] += $u1; $tot['ns1'] += $u1 * $p1; $tot['m1'] += $u1 * ($p1 - $c);
        $tot['regalo'] += $u1 * ($p0 - $p1);
        $tot['mu0'] += $u0 * ($p0 - $c); $tot['mu1'] += $u0 * ($p1 - $c);   // margen a volumen igual
        $tot['nsb'] += $u0 * $p1;
        $prods[] = ['codigo' => $r['codigo'], 'nombre' => $r['nombre'], 'u_dia' => $q / $diasBase, 'lista' => $lista, 'p0' => $p0, 'p1' => $p1, 'c' => $c,
                    'mu0' => $p0 - $c, 'mu1' => $p1 - $c];
    }
    // Aumento que deja el margen igual: margen sin promo ÷ margen con promo a volumen igual − 1.
    $equilibrio = $tot['mu1'] > 0 ? ($tot['mu0'] / $tot['mu1'] - 1) * 100 : null;
    $curva = [];
    for ($k = 0; $k <= 200; $k += 10) $curva[$k] = $tot['mu1'] * (1 + $k / 100);
    return ['dias' => $dias, 'productos' => $prods, 'tot' => $tot,
            'equilibrio' => $equilibrio, 'pierde_por_unidad' => $rows && $tot['mu1'] <= 0,
            // Rebaja real sobre lo que se cobraba (con los productos que ya tenían otro precio).
            'profundidad' => $tot['ns0'] > 0 ? (1 - $tot['nsb'] / $tot['ns0']) * 100 : null, 'curva' => $curva, 'margen_sin' => $tot['m0']];
}

/**
 * La misma promoción a varias profundidades. Cada una con su equilibrio y el
 * margen si las unidades subieran lo que subieron promociones parecidas (o lo
 * esperado, si no hay historia a esa profundidad).
 */
function cockpit_sim_escenarios(array $sim, array $cfg, array $valores, callable $aumentoPara): array
{
    $out = [];
    foreach ($valores as $v) {
        $c = ['valor' => $v, 'aumento' => 0] + $cfg;
        $r0 = cockpit_sim_calcular($sim['filas'], $c, $sim['base'][2], $sim['divisor']);
        [$aum, $deHistoria] = $aumentoPara($r0['profundidad']);
        $r = cockpit_sim_calcular($sim['filas'], ['aumento' => $aum] + $c, $sim['base'][2], $sim['divisor']);
        $out[] = ['valor' => $v, 'profundidad' => $r0['profundidad'], 'equilibrio' => $r0['equilibrio'], 'pierde' => $r0['pierde_por_unidad'],
                  'aumento' => $aum, 'de_historia' => $deHistoria, 'ns' => $r['tot']['ns1'], 'margen' => $r['tot']['m1'],
                  'dif' => $r['tot']['m1'] - $r['tot']['m0'], 'regalo' => $r['tot']['regalo']];
    }
    return $out;
}

/** WHERE del alcance de una promoción simulada. */
function cockpit_sim_alcance(string $alcance, $objetivo): array
{
    switch ($alcance) {
        case 'categoria': return ['pr.categoria_id = ?', [(int) $objetivo]];
        case 'marca':     return ['pr.marca_id = ?', [(int) $objetivo]];
        case 'producto':  return ['pr.id = ?', [(int) $objetivo]];
        case 'segmento':  return ["COALESCE(NULLIF(pr.segmento,''), (SELECT c.nombre FROM categorias c WHERE c.id = pr.categoria_id), 'Sin segmento') = ?", [(string) $objetivo]];
        case 'linea':     return ["COALESCE(NULLIF(pr.linea,''), 'Sin línea') = ?", [(string) $objetivo]];
    }
    return ['1=1', []];
}

/**
 * Aumento que dieron las promociones parecidas (±5 puntos de profundidad) en
 * el último año, para proponerlo en el simulador. null si no hay con qué.
 */
function cockpit_aumento_historico(array $f, ?float $profundidad): ?array
{
    // El año de efectividad se calcula una vez por página (el simulador lo pide por escenario).
    static $cache = [];
    $clave = md5(serialize($f));
    $cache[$clave] ??= array_values(array_filter(cockpit_efectividad($f, [date('Y-m-d', strtotime('-365 days')), date('Y-m-d')]), fn($r) => $r['aumento'] !== null));
    return cockpit_aumento_de($cache[$clave], $profundidad);
}

/** Mediana del aumento de las promociones medidas, las de profundidad parecida si las hay. */
function cockpit_aumento_de(array $e, ?float $profundidad): ?array
{
    if ($profundidad !== null) {
        $cerca = array_filter($e, fn($r) => $r['profundidad'] !== null && abs($r['profundidad'] - $profundidad) <= 5);
        if ($cerca) $e = $cerca;
    }
    if (!$e) return null;
    $v = array_column($e, 'aumento');
    sort($v);
    $m = intdiv(count($v), 2);
    $mediana = count($v) % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    return ['aumento' => $mediana, 'n' => count($v), 'parecidas' => isset($cerca) && $cerca];
}

/**
 * Lo que necesita el resumen (la pestaña y el correo): cada tipo de descuento
 * TY/LY con sus efectos, y los agregados total / sin promoción / en promoción.
 * Las facturas NO se suman por tipo (una factura con líneas de dos tipos
 * contaría dos veces): el total no las trae, para que nadie lea un dato falso.
 */
function cockpit_resumen_datos(array $f): array
{
    $x = cockpit_expr();
    $porTY = cockpit_por($f, $f['ty'], $x['tipo']);
    $porLY = cockpit_por($f, $f['ly'], $x['tipo']);
    $totTY = cockpit_metricas(cockpit_sumar($porTY));
    $totLY = cockpit_metricas(cockpit_sumar($porLY));
    unset($totTY['tickets'], $totTY['atv'], $totLY['tickets'], $totLY['atv']);
    $gsTY = (float) $totTY['gs']; $gsLY = (float) $totLY['gs'];
    $filas = [];
    foreach (array_unique(array_merge(array_keys($porTY), array_keys($porLY))) as $k) {
        $ty = $porTY[$k] ?? cockpit_vacio();
        $ly = $porLY[$k] ?? cockpit_vacio();
        $filas[$k] = ['ty' => cockpit_metricas($ty, $gsTY), 'ly' => cockpit_metricas($ly, $gsLY), 'ef' => cockpit_efectos($ty, $ly, $gsTY, $gsLY)];
    }
    uasort($filas, fn($a, $b) => $b['ty']['gs'] <=> $a['ty']['gs']);
    $agrupar = function (array $claves) use ($filas, $gsTY, $gsLY) {
        $sel = array_intersect_key($filas, array_flip($claves));
        $ef = ['volumen' => 0.0, 'mezcla' => 0.0, 'tasa' => 0.0, 'producto' => 0.0, 'total' => 0.0];
        foreach ($sel as $r) foreach ($ef as $k => $_) $ef[$k] += $r['ef'][$k];
        return ['ty' => cockpit_metricas(cockpit_sumar(array_column($sel, 'ty')), $gsTY),
                'ly' => cockpit_metricas(cockpit_sumar(array_column($sel, 'ly')), $gsLY), 'ef' => $ef];
    };
    $promo = $agrupar(array_values(array_diff(array_keys($filas), ['sin'])));
    return [
        'tot_ty' => $totTY, 'tot_ly' => $totLY, 'filas' => $filas,
        'total' => $agrupar(array_keys($filas)), 'sin' => $agrupar(['sin']), 'promo' => $promo,
        'pct_promo_ty' => $gsTY > 0 ? $promo['ty']['gs'] / $gsTY * 100 : 0.0,
        'pct_promo_ly' => $gsLY > 0 ? $promo['ly']['gs'] / $gsLY * 100 : 0.0,
    ];
}

/* ============================================================
 *  Vistas guardadas
 * ============================================================ */

function cockpit_vistas_disponible(): bool
{
    static $ok = null;
    return $ok ??= (bool) qVal("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cockpit_vistas'");
}

/**
 * La parte de la URL que se guarda: solo pestaña y filtros conocidos. Si las
 * fechas son un periodo rápido, se guarda el periodo y no las fechas, para que
 * «Mes pasado» siga siendo el mes pasado el mes que viene.
 */
function cockpit_vista_query(array $get): string
{
    $claves = ['tab', 'sucursal_id', 'tienda_id', 'canal', 'marca_id', 'segmento', 'linea', 'ty_desde', 'ty_hasta', 'ly_desde', 'ly_hasta',
               'ly_manual', 'ly_modo', 'moneda', 'samestore', 'tipo', 'vista', 'periodo',
               's_alcance', 's_obj', 's_tipo', 's_valor', 's_dias', 's_base', 's_aum'];
    $q = [];
    foreach ($claves as $k) {
        if (isset($get[$k]) && is_scalar($get[$k]) && (string) $get[$k] !== '') $q[$k] = mb_substr((string) $get[$k], 0, 80);
    }
    $manual = ($q['ly_manual'] ?? '') === '1';
    if (isset($q['ty_desde'], $q['ty_hasta']) && !$manual) {
        // Varios periodos coinciden ciertos días (el 10 de abril, «este mes» y
        // «año fiscal» son lo mismo). Manda el que se pulsó (?periodo= en los
        // atajos); si no, el más corto, que es el que alguien suele querer.
        $presets = cockpit_presets();
        $orden = array_values(array_unique(array_merge(isset($q['periodo'], $presets[$q['periodo']]) ? [$q['periodo']] : [],
                                                       ['mes', 'mes_pasado', 'trimestre', 'fiscal', 'ytd', 'u12'], array_keys($presets))));
        unset($q['periodo']);
        foreach ($orden as $clave) {
            if (isset($presets[$clave]) && $presets[$clave][1] === [$q['ty_desde'], $q['ty_hasta']]) {
                unset($q['ty_desde'], $q['ty_hasta'], $q['ly_desde'], $q['ly_hasta'], $q['ly_manual']);
                $q['periodo'] = $clave;
                break;
            }
        }
    }
    if (!$manual) unset($q['ly_desde'], $q['ly_hasta'], $q['ly_manual']);
    return http_build_query($q);
}

/** Las vistas del usuario y las compartidas por los demás. */
function cockpit_vistas(int $usuarioId): array
{
    if (!cockpit_vistas_disponible()) return [];
    $frec = function_exists('cockpit_resumen_disponible') && cockpit_resumen_disponible() ? ', v.frecuencia' : '';
    return qAll("SELECT v.id, v.nombre, v.query, v.compartida, v.usuario_id, u.nombre autor$frec
                   FROM cockpit_vistas v LEFT JOIN usuarios u ON u.id = v.usuario_id
                  WHERE v.usuario_id = ? OR v.compartida = 1
                  ORDER BY v.usuario_id <> ?, v.nombre", [$usuarioId, $usuarioId]);
}

/* ============================================================
 *  Moneda de reporte
 * ============================================================ */

/**
 * Monedas en que se puede ver el cockpit: código => [símbolo, pesos por unidad, nota].
 *
 * La contabilidad vive en pesos y nunca se convierte al vuelo con la tasa del
 * día (el pasado cambiaría con el dólar). Por eso la tasa es FIJA: la que la
 * marca configura como tasa de reporte (la del presupuesto, típicamente). Si no
 * la configuró, se ofrece la del catálogo de monedas y se avisa que es la del día.
 */
function cockpit_monedas(): array
{
    $base = (string) (function_exists('setting') ? setting('moneda', 'RD$') : 'RD$');
    $out = ['' => [$base, 1.0, '']];
    $fijas = [];
    foreach (preg_split('/\R/', (string) cockpit_param('tasas_reporte', '')) as $l) {
        if (preg_match('/^\s*([A-Za-z]{3})\s*[=:]\s*([0-9]+(?:[.,][0-9]+)?)\s*$/', $l, $m) && (float) str_replace(',', '.', $m[2]) > 0) {
            $fijas[strtoupper($m[1])] = (float) str_replace(',', '.', $m[2]);
        }
    }
    $simbolos = ['USD' => 'US$', 'EUR' => '€'];
    foreach ($fijas as $cod => $tasa) $out[$cod] = [$simbolos[$cod] ?? $cod, $tasa, 'tasa de reporte fija'];
    if (function_exists('mon_disponible') && mon_disponible()) {
        foreach (monedas() as $mo) {
            if (!empty($mo['es_base']) || isset($out[$mo['codigo']]) || (float) $mo['tasa'] <= 0) continue;
            $out[$mo['codigo']] = [$mo['simbolo'] ?: $mo['codigo'], (float) $mo['tasa'], 'tasa del día (configura una fija)'];
        }
    }
    return $out;
}

/** La moneda elegida en la URL (?moneda=USD) o la base. [código, símbolo, tasa, nota] */
function cockpit_moneda(): array
{
    // Cacheada por moneda pedida: el resumen por correo cambia de «petición» a media página.
    static $m = [];
    $cod = strtoupper((string) (function_exists('get') ? get('moneda') : ''));
    if (isset($m[$cod])) return $m[$cod];
    $pedida = $cod;
    $monedas = cockpit_monedas();
    if (!isset($monedas[$cod])) $cod = '';
    [$sim, $tasa, $nota] = $monedas[$cod];
    return $m[$pedida] = [$cod, $sim, $tasa, $nota];
}

/** Pesos por unidad de la moneda de reporte (1 = moneda base). */
function cockpit_divisor(): float
{
    return function_exists('get') && get('moneda') !== '' ? cockpit_moneda()[2] : 1.0;
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
