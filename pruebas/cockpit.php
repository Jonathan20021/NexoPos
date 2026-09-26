<?php
/**
 * Banco de pruebas del Promotion Cockpit (includes/cockpit.php).
 *
 *   php pruebas/cockpit.php
 *
 * Cubre las funciones puras: indicadores de una fila y los efectos sobre el
 * margen. La promesa de la tabla es que volumen + tasa + mezcla + mezcla de
 * producto EXPLICAN ENTERA la variación del margen, tipo por tipo y en el
 * total. Si un día dejan de cuadrar, la dirección vería un «efecto total» que
 * no coincide con lo que pasó en el margen, y dejaría de creerse la pantalla.
 *
 * No toca la base ni necesita sesión. Devuelve 0 si todo pasa y 1 si algo falla.
 */

require_once dirname(__DIR__) . '/includes/cockpit.php';

$fallos = 0;
$total  = 0;

function comprueba(string $caso, $obtenido, $esperado, float $tol = 0.01): void
{
    global $fallos, $total;
    $total++;
    $ok = is_float($esperado) ? abs((float) $obtenido - $esperado) <= $tol : $obtenido === $esperado;
    if (!$ok) $fallos++;
    printf("  %s %-62s %s\n", $ok ? 'OK   ' : 'FALLA', $caso,
        $ok ? '' : 'obtenido ' . var_export($obtenido, true) . ', esperado ' . var_export($esperado, true));
}

function titulo(string $t): void { echo "\n== $t ==\n"; }

$fila = fn(float $gs, float $ns, float $costo, float $tickets = 1) =>
    ['gs' => $gs, 'promo' => 0.0, 'caja' => 0.0, 'ns' => $ns, 'costo' => $costo, 'qty' => 1.0, 'tickets' => $tickets, 'sin_lista' => 0.0];

/* ============================================================ */
titulo('Indicadores de una fila');
$m = cockpit_metricas($fila(1000, 850, 400, 10), 4000);
comprueba('descuento = bruta − neta', $m['desc'], 150.0);
comprueba('desc % sobre la venta bruta', $m['desc_pct'], 15.0);
comprueba('margen % sobre la bruta (antes de descuentos)', $m['margen_gs'], 60.0);
comprueba('margen % sobre la neta', $m['margen_ns'], 52.94);
comprueba('peso en la venta bruta total', $m['peso_gs'], 25.0);
comprueba('puntos perdidos = descuento / bruta total', $m['pts'], 3.75);
comprueba('ticket medio = neta / facturas', $m['atv'], 85.0);
$v = cockpit_metricas($fila(0, 0, 0, 0), 0);
comprueba('una fila vacía no divide entre cero', [$v['desc_pct'], $v['margen_ns'], $v['pts']], [0.0, 0.0, 0.0]);

/* ============================================================ */
titulo('Los efectos cuadran con la variación del margen');
// Tres tipos, dos años. Cambian volumen, peso, profundidad y costo a la vez.
$ty = ['sin' => $fila(5000, 5000, 2600), 'gwp' => $fila(3000, 2400, 1500), 'outlet' => $fila(2000, 1400, 1200)];
$ly = ['sin' => $fila(5200, 5200, 2600), 'gwp' => $fila(2000, 1700, 900),  'outlet' => $fila(800, 640, 420)];
$gsTy = 10000.0; $gsLy = 8000.0;
$sum = ['volumen' => 0.0, 'mezcla' => 0.0, 'tasa' => 0.0, 'producto' => 0.0, 'total' => 0.0];
foreach ($ty as $k => $r) {
    $e = cockpit_efectos($r, $ly[$k], $gsTy, $gsLy);
    $delta = ($r['ns'] - $r['costo']) - ($ly[$k]['ns'] - $ly[$k]['costo']);
    comprueba("«{$k}»: los cuatro efectos suman la variación del margen", $e['volumen'] + $e['mezcla'] + $e['tasa'] + $e['producto'], $delta);
    comprueba("«{$k}»: el efecto total es esa misma variación", $e['total'], $delta);
    foreach ($sum as $c => $_) $sum[$c] += $e[$c];
}
$margenTy = array_sum(array_map(fn($r) => $r['ns'] - $r['costo'], $ty));
$margenLy = array_sum(array_map(fn($r) => $r['ns'] - $r['costo'], $ly));
comprueba('en el total, los efectos suman la variación del margen', $sum['total'], $margenTy - $margenLy);
comprueba('la mezcla se compensa: sin cambio de márgenes, suma 0', (function () use ($fila) {
    // Mismos márgenes por tipo, solo cambia el peso: la mezcla total solo
    // refleja la diferencia de margen entre tipos, y con márgenes iguales es 0.
    $s = 0.0;
    $s += cockpit_efectos($fila(600, 540, 240), $fila(500, 450, 200), 1000, 1000)['mezcla'];
    $s += cockpit_efectos($fila(400, 360, 160), $fila(500, 450, 200), 1000, 1000)['mezcla'];
    return $s;
})(), 0.0);

titulo('Casos de borde');
$e = cockpit_efectos($fila(1000, 800, 500), $fila(0, 0, 0), 5000, 4000);
comprueba('un tipo nuevo: todo su margen es mezcla', $e['mezcla'], 300.0);
comprueba('un tipo nuevo: sin efecto tasa inventado', $e['tasa'], 0.0);
$e = cockpit_efectos($fila(1000, 900, 500), $fila(1000, 800, 500), 5000, 5000);
comprueba('descontar menos a igual venta es efecto tasa positivo', $e['tasa'], 100.0);
comprueba('... y no toca volumen ni mezcla', [$e['volumen'], $e['mezcla']], [0.0, 0.0]);

titulo('Fechas');
comprueba('un año antes', cockpit_un_anio_antes('2026-09-23'), '2025-09-23');
comprueba('29 de febrero cae en el 28', cockpit_un_anio_antes('2024-02-29'), '2023-02-28');

titulo('Hallazgos');
if (!function_exists('e')) { function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
$tot = fn(array $ty, array $ly) => ['ty' => cockpit_metricas($ty, $ty['gs']), 'ly' => cockpit_metricas($ly, $ly['gs']),
    'ef' => cockpit_efectos($ty, $ly, $ty['gs'], $ly['gs'])];
$T = $tot($fila(10000, 8500, 5000), $fila(10000, 9000, 5000));
$tiposH = ['gwp' => ['ty' => cockpit_metricas($fila(3000, 2000, 1500), 10000), 'ly' => cockpit_metricas($fila(3000, 2500, 1500), 10000)],
           'sin' => ['ty' => cockpit_metricas($fila(7000, 7000, 3500), 10000), 'ly' => cockpit_metricas($fila(7000, 7000, 3500), 10000)]];
$hz = cockpit_hallazgos($T, $tiposH, 30.0, 30.0);
$textos = implode(' | ', array_column($hz, 'texto'));
comprueba('avisa que la tasa de descuento subió', str_contains($textos, 'tasa de descuento subió'), true);
comprueba('señala el tipo que más cuesta', str_contains($textos, 'es el tipo que más cuesta'), true);
comprueba('el aviso de tasa sale en rojo', $hz[array_search(true, array_map(fn($x) => str_contains($x['texto'], 'tasa de descuento'), $hz))]['tono'], 'malo');
comprueba('nunca más de los pedidos', count(cockpit_hallazgos($T, $tiposH, 30.0, 10.0, 2)), 2);
comprueba('sin año anterior no inventa comparaciones', count(array_filter(cockpit_hallazgos($tot($fila(100, 90, 50), $fila(0, 0, 0)), [], 0.0, 0.0), fn($x) => str_contains($x['texto'], 'pts'))), 0);

titulo('Proyección de campaña');
require_once dirname(__DIR__) . '/includes/kpi_campanas.php';
$dias = fn(array $v, string $ini) => array_combine(array_map(fn($i) => date('Y-m-d', strtotime("$ini +$i day")), array_keys($v)), array_map(fn($x) => ['ns' => (float) $x], $v));
// El año pasado el pico fue al final: 10+10 los dos primeros días de 4 (20 de 100).
$ly = array_values($dias([10, 10, 30, 50], '2025-11-01'));
$ty = $dias([12, 12, 0, 0], '2026-11-01');
$pr = kpi_proyeccion($ty, $ly, '2026-11-03');
comprueba('proyecta con la forma del año anterior', $pr['proyeccion'], 120.0);
comprueba('cuenta solo los días cerrados', $pr['transcurridos'], 2);
$pr = kpi_proyeccion($dias([10, 10, 0, 0], '2026-11-01'), [], '2026-11-03');
comprueba('sin año anterior, promedio diario', $pr['proyeccion'], 40.0);
$pr = kpi_proyeccion($dias([10, 10, 0, 0], '2026-11-01'), [['ns' => 5], ['ns' => 5], ['ns' => 0], ['ns' => 0]], '2026-11-03');
comprueba('año anterior sin venta en lo que falta: promedio diario', [$pr['proyeccion'], $pr['metodo']], [40.0, 'promedio diario']);
comprueba('fuera de las fechas no proyecta', kpi_proyeccion($ty, $ly, '2026-12-01'), null);

titulo('Catálogos');
comprueba('todo motivo de caja tiene su tipo en el cockpit', array_diff(array_keys(cockpit_motivos_caja()), array_keys(cockpit_tipos())), []);
comprueba('toda familia de promoción tiene su tipo', array_diff(array_keys(cockpit_tipos_promocion()), array_keys(cockpit_tipos())), []);

printf("\n%d pruebas, %d fallos\n", $total, $fallos);
exit($fallos ? 1 : 0);
