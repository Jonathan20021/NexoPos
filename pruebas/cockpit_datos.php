<?php
/**
 * Las cifras del Promotion Cockpit cuadran entre sí, contra la base real.
 *
 *   php pruebas/cockpit_datos.php
 *
 * pruebas/cockpit.php cubre las cuentas puras. Esto cubre lo que solo se ve con
 * datos: que el mismo número, llegado por dos caminos distintos (la suma de los
 * tipos y el total directo, el cubo del sell-out y el total, una moneda y otra,
 * la efectividad optimizada y una consulta ingenua), sea el mismo. Si una
 * pantalla dice una cosa y la de al lado otra, la marca deja de creer las dos.
 *
 * Solo LEE. Necesita la P39 aplicada y ventas; si no las hay, lo dice y sale bien.
 * Devuelve 0 si todo cuadra y 1 si algo no.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$fallos = 0;
$total  = 0;

function cuadra(string $caso, float $a, float $b, float $tol = 0.02): void
{
    global $fallos, $total;
    $total++;
    // Tolerancia relativa para importes grandes, absoluta para los pequeños.
    $ok = abs($a - $b) <= max($tol, abs($b) * 1e-9);
    if (!$ok) $fallos++;
    printf("  %s %-66s %s\n", $ok ? 'OK   ' : 'FALLA', $caso, $ok ? '' : sprintf('%.4f ≠ %.4f', $a, $b));
}

function titulo(string $t): void { echo "\n== $t ==\n"; }

if (!cockpit_disponible()) {
    echo "Sin la migración P39 no hay nada que comprobar.\n";
    exit(0);
}

// Un super administrador sin sucursal: ve toda la empresa, sin filtros de sesión.
$_SESSION['user'] = ['id' => 0, 'es_super' => 1, 'sucursal_id' => null, 'rol_id' => 0, 'nombre' => 'Pruebas'];
$_SESSION['permisos'] = [];
$_SESSION['sucursal_activa'] = '';

/** Filtros del cockpit para un rango, como si vinieran de la URL. */
function filtros(array $get): array
{
    $_GET = $get;
    return cockpit_filtros();
}

$hasta = date('Y-m-d');
$desde = date('Y-m-d', strtotime('-11 months', strtotime(date('Y-m-01'))));
$f = filtros(['ty_desde' => $desde, 'ty_hasta' => $hasta]);
$n = (int) qVal("SELECT COUNT(*) FROM ventas v WHERE " . rep_estados_venta('v') . " AND v.fecha BETWEEN ? AND ?", [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
if ($n === 0) {
    echo "No hay ventas entre $desde y $hasta: nada que comprobar.\n";
    exit(0);
}
echo "Periodo $desde a $hasta · " . number_format($n) . " ventas\n";

/* ============================================================ */
titulo('Resumen: los tipos suman el total');
$tot = cockpit_totales($f, $f['ty']);
$res = cockpit_resumen_datos($f);
foreach (['gs' => 'la venta bruta', 'ns' => 'la venta neta', 'costo' => 'el costo'] as $k => $lbl) {
    cuadra("la suma de los tipos da $lbl total", (float) $res['tot_ty'][$k], (float) $tot[$k]);
}
cuadra('en promoción + sin promoción = total (venta bruta)', $res['promo']['ty']['gs'] + $res['sin']['ty']['gs'], $res['total']['ty']['gs']);
$totLy = cockpit_totales($f, $f['ly']);
cuadra('lo mismo el año anterior (venta neta)', (float) $res['tot_ly']['ns'], (float) $totLy['ns']);

titulo('Los efectos explican el cambio del margen');
$ef = $res['total']['ef'];
cuadra('volumen + tasa + mezcla + mezcla de producto = efecto total', $ef['volumen'] + $ef['tasa'] + $ef['mezcla'] + $ef['producto'], $ef['total']);
cuadra('el efecto total es margen este año − margen año anterior', $ef['total'],
       ($res['total']['ty']['ns'] - $res['total']['ty']['costo']) - ($res['total']['ly']['ns'] - $res['total']['ly']['costo']));

/* ============================================================ */
titulo('Sell-out: cada dimensión reparte el mismo total');
$cubo = cockpit_cubo($f, $f['ty']);
$nsCubo = array_sum(array_column($cubo, 'ns'));
cuadra('el cubo suma la venta neta total', $nsCubo, (float) $tot['ns']);
foreach (['sucursal', 'canal', 'segmento', 'linea', 'ym'] as $dim) {
    cuadra("por $dim suma lo mismo", array_sum(cockpit_cubo_por($cubo, $dim)), $nsCubo);
}
cuadra('por segmento y línea a la vez, también', array_sum(cockpit_cubo_por($cubo, 'segmento', 'linea')), $nsCubo);
$porCanal = cockpit_por($f, $f['ty'], cockpit_canal_sql('v'));
cuadra('el canal por consulta directa da lo mismo que el cubo', array_sum(array_column($porCanal, 'ns')), $nsCubo);

/* ============================================================ */
titulo('Detallado: stacking y mecanismos');
$st = cockpit_stacking($f, $f['ty']);
cuadra('venta bruta con descuento del stacking = la de los tipos en promoción', $st['gs_promo'], $res['promo']['ty']['gs']);
cuadra('venta neta con descuento del stacking = la de los tipos en promoción', $st['ns_promo'], $res['promo']['ty']['ns']);
cuadra('la distribución por nº de descuentos suma la venta de esas facturas', array_sum($st['dist']), $st['gs']);
// El Detallado toma sus totales del stacking (todas las facturas, con y sin descuento).
foreach (['gs', 'ns', 'costo', 'tickets', 'sin_lista'] as $k) {
    cuadra("totales del stacking = totales directos ($k)", $st['total'][$k], (float) $tot[$k]);
}
$mec = cockpit_mecanismos($f, $f['ty']);
cuadra('los mecanismos suman la venta bruta en promoción', array_sum(array_column($mec, 'gs')), $res['promo']['ty']['gs']);
$mensual = cockpit_mensual($f, $f['ty']);
cuadra('los meses suman la venta bruta total', array_sum(array_column($mensual, 'gs')), (float) $tot['gs']);
// El resumen saca meses y tipos de UNA pasada: tiene que dar lo mismo que la serie aparte.
$igual = array_keys($mensual) === array_keys($res['mensual_ty']);
foreach ($mensual as $ym => $m) foreach (['gs', 'ns', 'gs_promo'] as $k) $igual = $igual && abs($m[$k] - ($res['mensual_ty'][$ym][$k] ?? -1)) < 0.01;
cuadra('la serie mensual del resumen (una pasada) = cockpit_mensual(), mes a mes', $igual ? 1.0 : 0.0, 1.0);

/* ============================================================ */
titulo('Moneda de reporte');
$monedas = array_filter(cockpit_monedas(), fn($m, $k) => $k !== '', ARRAY_FILTER_USE_BOTH);
if (!$monedas) {
    echo "  (sin tasas de reporte configuradas: se omite)\n";
} else {
    $cod = array_key_first($monedas);
    $tasa = $monedas[$cod][1];
    // cockpit_moneda() lee ?moneda=: se consulta con la URL cambiada.
    $fx = filtros(['ty_desde' => $desde, 'ty_hasta' => $hasta, 'moneda' => $cod]);
    $totX = cockpit_totales($fx, $fx['ty']);
    cuadra("venta neta en $cod × tasa = venta neta en pesos", (float) $totX['ns'] * $tasa, (float) $tot['ns'], 0.05);
    $resX = cockpit_resumen_datos($fx);
    cuadra("la tasa de descuento no cambia con la moneda", $resX['total']['ty']['desc_pct'], $res['total']['ty']['desc_pct'], 1e-6);
    $f = filtros(['ty_desde' => $desde, 'ty_hasta' => $hasta]);
}

/* ============================================================ */
titulo('Efectividad: la versión rápida contra una consulta ingenua');
$efec = array_values(array_filter(cockpit_efectividad($f, $f['ty']), fn($r) => $r['ud_dia_base'] !== null));
if (!$efec) {
    echo "  (ninguna promoción con base comparable en el periodo: se omite)\n";
} else {
    $x = cockpit_expr();
    foreach (array_slice($efec, 0, 3) as $r) {
        // Los productos que se vendieron con la promoción, sumados a mano en su ventana.
        $prods = qCol("SELECT DISTINCT vd.producto_id " . cockpit_from() . " WHERE " . rep_estados_venta('v')
            . " AND v.fecha BETWEEN ? AND ? AND vd.promocion_id = ? AND vd.producto_id IS NOT NULL",
            [$f['ty'][0] . ' 00:00:00', $f['ty'][1] . ' 23:59:59', $r['id']]);
        $in = implode(',', array_map('intval', $prods));
        $q = fn(array $ventana) => (float) qVal("SELECT COALESCE(SUM(vd.cantidad),0) " . cockpit_from() . " WHERE " . rep_estados_venta('v')
            . " AND v.fecha BETWEEN ? AND ? AND vd.es_muestra = 0 AND vd.producto_id IN ($in)", [$ventana[0] . ' 00:00:00', $ventana[1] . ' 23:59:59']);
        cuadra("«{$r['nombre']}»: unidades/día durante", $q($r['ventana']) / $r['dias'], $r['ud_dia'], 1e-6);
        cuadra("«{$r['nombre']}»: unidades/día antes", $q($r['base']) / $r['dias_base'], $r['ud_dia_base'], 1e-6);
    }
}

/* ============================================================ */
titulo('Notificación y resumen por correo');
$emp = cockpit_tasa_empresa($desde, $hasta);
cuadra('la tasa de toda la empresa (notificación) = la del resumen sin filtros', $emp['desc_pct'], $res['total']['ty']['desc_pct'], 1e-6);
cuadra('su % en promoción = el del resumen', $emp['promo_pct'], $res['pct_promo_ty'], 1e-6);

if (cockpit_vistas_disponible() && ($u = cockpit_resumen_dueno((int) qVal("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.es_super = 1 AND u.activo = 1 LIMIT 1")))) {
    $vista = ['id' => 0, 'nombre' => 'Prueba', 'query' => 'tab=resumen', 'frecuencia' => 'mensual'];
    $per = cockpit_resumen_periodo('mensual');
    [, $html] = cockpit_resumen_correo($vista, $u, $per);
    preg_match('/Venta neta<\/td><td[^>]*><strong>[^0-9]*([0-9,]+)/', $html, $m);
    $fp = filtros(['ty_desde' => $per[0], 'ty_hasta' => $per[1]]);
    cuadra('la venta neta del correo mensual = la del cockpit en ese mes', (float) str_replace(',', '', $m[1] ?? '0'),
           round((float) cockpit_totales($fp, $fp['ty'])['ns']), 1.0);
}

/* ============================================================ */
titulo('Campañas');
$camp = qOne("SELECT * FROM kpi_campanas ORDER BY fecha_inicio DESC LIMIT 1");
if (!$camp) {
    echo "  (no hay campañas: se omite)\n";
} else {
    $pc = kpi_por_canal($camp, $camp['fecha_inicio'], $camp['fecha_fin']);
    $suma = 0.0;
    foreach ($pc as $k => $v) if ($k !== 'total') $suma += $v['ns'];
    cuadra("«{$camp['nombre']}»: los canales suman el total", $suma, $pc['total']['ns']);
    $fc = ['ty' => [$camp['fecha_inicio'], $camp['fecha_fin']], 'ly' => [$camp['ly_inicio'], $camp['ly_fin']], 'canal' => null, 'marca' => null,
           'segmento' => null, 'linea' => null, 'samestore' => false, 'ly_modo' => 'fecha', 'ly_manual' => true,
           'sucursal_fija' => $camp['sucursal_id'], 'tienda_fija' => $camp['tienda_id']];
    cuadra("«{$camp['nombre']}»: su venta neta = la del cockpit en sus fechas", $pc['total']['ns'], (float) cockpit_totales($fc, $fc['ty'])['ns']);
}

printf("\n%d comprobaciones, %d fallos\n", $total, $fallos);
exit($fallos ? 1 : 0);
