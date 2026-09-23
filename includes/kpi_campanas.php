<?php
/**
 * KPIs de campañas (Holiday, Black Friday, lanzamientos…).
 *
 * El archivo que pide la marca —«Holiday KPIs to track»— tiene cuatro hojas:
 * SKUs foco este año contra el anterior, KPIs globales por canal, el detalle
 * día por día (Black Friday) y la inversión de marketing por rubro. Todo lo
 * que sale del POS se calcula aquí; lo que no (alcance, impresiones, NPS,
 * tráfico de la tienda, sesiones web…) se captura a mano en la campaña.
 *
 * Criterio de venta: el mismo del resto de reportes — venta neta sin ITBIS
 * (`subtotal − descuento`), ventas facturadas (`rep_estados_venta`), antes de
 * devoluciones. Los canales son los de la marca: cockpit_canal_sql().
 */

/** Rubros de inversión, en el orden de la hoja INVESTMENT. clave => [español, inglés] */
function kpi_rubros_inversion(): array
{
    return [
        'entrenamiento' => ['Entrenamiento retail', 'RETAIL TRAINING'],
        'merchandising' => ['Merchandising / animación en tienda (dummies)', 'MERCHANDISING/IN-STORE ANIMATION(DUMMIES)'],
        'lanzamiento'   => ['Evento de lanzamiento', 'LAUNCH EVENT'],
        'pr'            => ['PR (seeding, artículos…)', 'PR (seeding, articles, etc…)'],
        'alianzas'      => ['Alianzas (marcas, influencers…)', 'PARTNERSHIPS (Brands, Influencers etc…)'],
        'mall'          => ['Activaciones en mall, podios, pop-ups', 'MALL ANIMATIONS,PODIUMS, POP UPS'],
        'paid_media'    => ['Medios pagados (Google Ads, Meta Ads)', 'PAID MEDIA (Google Ads, Meta Ads)'],
        'ecommerce'     => ['E-commerce (incl. medios pagados)', 'ECOMMERCE (INCL. PAID MEDIA)'],
        'ooh'           => ['Publicidad tradicional (vallas, mall, OOH)', 'ADVERTISING TRADITIONAL MEDIA (BILLBOARDS/MALL TAKE OVER/OOH)'],
        'otros'         => ['Otras inversiones (regalos a top clientes…)', 'OTHER MARKETING INVESTMENTS'],
    ];
}

/**
 * KPIs que no salen del POS, agrupados como en la lámina «Examples of KPIs to share».
 * clave => [grupo, etiqueta, unidad: num|pct|money]
 */
function kpi_metricas_manuales(): array
{
    return [
        'trafico'            => ['Generales', 'Visitantes en tienda (tráfico)', 'num'],
        'nps'                => ['Generales', 'NPS', 'num'],
        'emv'                => ['Influencer / PR', 'EMV (valor mediático ganado)', 'money'],
        'impresiones_pr'     => ['Influencer / PR', 'Impresiones', 'num'],
        'alcance_pr'         => ['Influencer / PR', 'Alcance (reach)', 'num'],
        'engagement_pr'      => ['Influencer / PR', 'Tasa de interacción (engagement)', 'pct'],
        'impresiones_medios' => ['OOH / Campañas en medios', 'Impresiones', 'num'],
        'sesiones_web'       => ['E-commerce', 'Sesiones en la tienda online', 'num'],
        'asistentes'         => ['Entrenamiento', 'Beauty hosts que asistieron', 'num'],
        'encuesta'           => ['Entrenamiento', 'Satisfacción de la encuesta', 'pct'],
        'completitud'        => ['Entrenamiento', 'Tasa de completitud (MTS, talleres, tareas)', 'pct'],
        'productividad'      => ['Entrenamiento', 'Crecimiento de productividad (antes/después)', 'pct'],
        'rotacion'           => ['Incentivos retail', 'Rotación del equipo', 'pct'],
        'nps_experiencia'    => ['Experiencia a la medida', 'NPS de la experiencia', 'num'],
    ];
}

function kpi_campana(int $id): ?array
{
    return qOne("SELECT * FROM kpi_campanas WHERE id = ?", [$id]);
}

/** WHERE de las ventas de la campaña en un rango. @return array{0:string,1:array} */
function kpi_where(array $c, string $ini, string $fin, string $v = 'v'): array
{
    $w = [rep_estados_venta($v), "$v.fecha BETWEEN ? AND ?"];
    $p = [$ini . ' 00:00:00', $fin . ' 23:59:59'];
    // La sucursal activa del usuario sigue mandando: una campaña de todas las
    // sucursales vista por una encargada enseña solo la suya.
    [$ws, $ps] = sucursalScope("$v.sucursal_id");
    $w[] = $ws; $p = array_merge($p, $ps);
    if (!empty($c['sucursal_id'])) { $w[] = "$v.sucursal_id = ?"; $p[] = (int) $c['sucursal_id']; }
    if (!empty($c['tienda_id']))   { $w[] = "$v.tienda_id = ?";   $p[] = (int) $c['tienda_id']; }
    return [implode(' AND ', $w), $p];
}

/**
 * Totales por canal de la marca: venta neta, facturas, unidades y clientes nuevos.
 *
 * Cliente nuevo = su PRIMERA compra de la historia cae en el rango (el
 * consumidor final genérico no cuenta). Se le atribuye al canal de esa compra.
 *
 * @return array<string,array{ns:float,tickets:float,unidades:float,nuevos:float}> canal => …, más 'total'
 */
function kpi_por_canal(array $c, string $ini, string $fin): array
{
    $canal = cockpit_canal_sql('v');
    [$w, $p] = kpi_where($c, $ini, $fin);
    $out = [];
    foreach (array_keys(cockpit_canales()) as $k) $out[$k] = ['ns' => 0.0, 'tickets' => 0.0, 'unidades' => 0.0, 'nuevos' => 0.0];

    foreach (qAll("SELECT $canal g, SUM(v.subtotal - v.descuento) ns, COUNT(*) tickets FROM ventas v WHERE $w GROUP BY g", $p) as $r) {
        $out[$r['g']]['ns'] = (float) $r['ns'];
        $out[$r['g']]['tickets'] = (float) $r['tickets'];
    }
    foreach (qAll("SELECT $canal g, SUM(vd.cantidad) u FROM ventas v JOIN venta_detalles vd ON vd.venta_id = v.id
                   WHERE $w AND vd.es_muestra = 0 GROUP BY g", $p) as $r) {
        $out[$r['g']]['unidades'] = (float) $r['u'];
    }
    foreach (kpi_nuevos($c, $ini, $fin, $canal) as $g => $n) $out[$g]['nuevos'] = (float) $n;

    $out['total'] = ['ns' => 0.0, 'tickets' => 0.0, 'unidades' => 0.0, 'nuevos' => 0.0];
    foreach (array_keys(cockpit_canales()) as $k) foreach ($out['total'] as $m => $_) $out['total'][$m] += $out[$k][$m];
    return $out;
}

/** Clientes cuya primera compra cae en el rango, agrupados por una expresión de la venta. */
function kpi_nuevos(array $c, string $ini, string $fin, string $grupo): array
{
    [$w, $p] = kpi_where($c, $ini, $fin);
    $rows = qAll(
        "SELECT $grupo g, COUNT(DISTINCT v.cliente_id) n
           FROM ventas v
           JOIN (SELECT cliente_id, MIN(fecha) f FROM ventas
                  WHERE " . rep_estados_venta('ventas') . " AND cliente_id > 1 GROUP BY cliente_id) pc
             ON pc.cliente_id = v.cliente_id AND pc.f = v.fecha
          WHERE $w GROUP BY g",
        $p
    );
    $out = [];
    foreach ($rows as $r) $out[(string) $r['g']] = (int) $r['n'];
    return $out;
}

/** Día por día. @return array<string,array{ns:float,tickets:float,nuevos:float}> 'Y-m-d' => … */
function kpi_por_dia(array $c, string $ini, string $fin): array
{
    [$w, $p] = kpi_where($c, $ini, $fin);
    $out = [];
    for ($d = $ini; $d <= $fin && count($out) < 93; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $out[$d] = ['ns' => 0.0, 'tickets' => 0.0, 'nuevos' => 0.0];
    }
    foreach (qAll("SELECT DATE(v.fecha) d, SUM(v.subtotal - v.descuento) ns, COUNT(*) t FROM ventas v WHERE $w GROUP BY d", $p) as $r) {
        if (isset($out[$r['d']])) { $out[$r['d']]['ns'] = (float) $r['ns']; $out[$r['d']]['tickets'] = (float) $r['t']; }
    }
    foreach (kpi_nuevos($c, $ini, $fin, 'DATE(v.fecha)') as $d => $n) {
        if (isset($out[$d])) $out[$d]['nuevos'] = (float) $n;
    }
    return $out;
}

/**
 * Venta de productos en el rango: cantidad y venta neta de la línea (con su
 * parte del descuento en caja, para que la suma cuadre con el total).
 *
 * @param int[]|null $ids  null = todos (para el top cuando la campaña no tiene SKUs foco)
 * @return array<int,array{qty:float,ns:float}>
 */
function kpi_skus(array $c, string $ini, string $fin, ?array $ids = null, int $top = 0): array
{
    [$w, $p] = kpi_where($c, $ini, $fin);
    $filtro = '';
    if ($ids !== null) {
        if (!$ids) return [];
        $filtro = ' AND vd.producto_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    $rows = qAll(
        "SELECT vd.producto_id pid, SUM(vd.cantidad) qty,
                SUM(vd.subtotal - CASE WHEN v.subtotal > 0 THEN v.descuento * vd.subtotal / v.subtotal ELSE 0 END) ns
           FROM ventas v JOIN venta_detalles vd ON vd.venta_id = v.id
          WHERE $w AND vd.producto_id IS NOT NULL AND vd.es_muestra = 0 $filtro
          GROUP BY vd.producto_id ORDER BY ns DESC" . ($top > 0 ? ' LIMIT ' . (int) $top : ''),
        $p
    );
    $out = [];
    foreach ($rows as $r) $out[(int) $r['pid']] = ['qty' => (float) $r['qty'], 'ns' => (float) $r['ns']];
    return $out;
}

/** Clientes identificados que compraron y cuántos ya habían comprado antes del rango. */
function kpi_recurrencia(array $c, string $ini, string $fin, ?string $canal = null): array
{
    [$w, $p] = kpi_where($c, $ini, $fin);
    if ($canal) { $w .= ' AND ' . cockpit_canal_sql('v') . ' = ?'; $p[] = $canal; }
    $r = qOne(
        "SELECT COUNT(DISTINCT v.cliente_id) clientes,
                COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM ventas a WHERE a.cliente_id = v.cliente_id
                                                   AND " . rep_estados_venta('a') . " AND a.fecha < ?) THEN v.cliente_id END) recurrentes
           FROM ventas v WHERE $w AND v.cliente_id > 1",
        array_merge([$ini . ' 00:00:00'], $p)
    ) ?: [];
    return ['clientes' => (float) ($r['clientes'] ?? 0), 'recurrentes' => (float) ($r['recurrentes'] ?? 0)];
}

/**
 * Todo lo calculado de una campaña, TY y LY.
 * @return array{ty:array,ly:array,dias_ty:array,dias_ly:array,inversion:float,metricas:array,metas:array}
 */
function kpi_resumen(array $c): array
{
    $metricas = [];
    foreach (qAll("SELECT metrica, valor, valor_ly, nota FROM kpi_campana_metricas WHERE campana_id = ?", [$c['id']]) as $m) {
        $metricas[$m['metrica']] = $m;
    }
    $metas = [];
    foreach (qAll("SELECT canal, meta FROM kpi_campana_metas WHERE campana_id = ?", [$c['id']]) as $m) {
        $metas[$m['canal']] = (float) $m['meta'];
    }
    return [
        'ty'        => kpi_por_canal($c, $c['fecha_inicio'], $c['fecha_fin']),
        'ly'        => kpi_por_canal($c, $c['ly_inicio'], $c['ly_fin']),
        'rec_ty'    => kpi_recurrencia($c, $c['fecha_inicio'], $c['fecha_fin']),
        'rec_web'   => kpi_recurrencia($c, $c['fecha_inicio'], $c['fecha_fin'], 'web'),
        'inversion' => (float) qVal("SELECT COALESCE(SUM(monto),0) FROM kpi_campana_inversiones WHERE campana_id = ?", [$c['id']]),
        'metricas'  => $metricas,
        'metas'     => $metas,
        // Meta total: la de la campaña o, si no se puso, la suma de los canales.
        'meta'      => (float) $c['meta_ventas'] > 0 ? (float) $c['meta_ventas'] : array_sum($metas),
    ];
}

/** División segura. */
function kpi_div(float $a, float $b): ?float
{
    return abs($b) < 0.00001 ? null : $a / $b;
}

/** Crecimiento en % o null. */
function kpi_crec(float $ty, float $ly): ?float
{
    return abs($ly) < 0.005 ? null : ($ty - $ly) / abs($ly) * 100;
}
