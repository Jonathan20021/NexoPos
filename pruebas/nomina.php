<?php
/**
 * Banco de pruebas del cálculo de nómina dominicana.
 *
 *   php pruebas/nomina.php
 *
 * `includes/nomina.php` vive aparte de la pantalla precisamente «para que se
 * pueda probar»; esto es la prueba. Son funciones puras: no toca la base de
 * datos ni necesita sesión, así que se puede correr en cualquier momento.
 *
 * Devuelve 0 si todo pasa y 1 si algo falla, para poder encadenarlo.
 *
 * Cada caso está aquí porque protege algo que YA se rompió o que costaría
 * dinero si se rompe:
 *
 *   · El ISR se calcula sobre el mes completo y se prorratea. Anualizar una
 *     quincena baja de tramo a casi todo el mundo (se corrigió el 2026-08-12).
 *   · Cero días trabajados es cero sueldo. Antes pagaba la quincena entera:
 *     «no me pasaron los días» y «trabajó cero días» se trataban igual.
 *   · El neto nunca es negativo. Una cuota de préstamo mayor que lo devengado
 *     daba un neto bajo cero y descuadraba el total del período.
 */

require_once dirname(__DIR__) . '/includes/nomina.php';

$fallos = 0;
$total  = 0;

function comprueba(string $caso, $obtenido, $esperado, float $tol = 0.01): void
{
    global $fallos, $total;
    $total++;
    $ok = is_bool($esperado)
        ? $obtenido === $esperado
        : abs((float) $obtenido - (float) $esperado) <= $tol;
    if (!$ok) $fallos++;
    printf("  %s %-56s %s\n", $ok ? 'OK   ' : 'FALLA', $caso,
        $ok ? '' : 'obtenido ' . var_export($obtenido, true) . ', esperado ' . var_export($esperado, true));
}

function titulo(string $t): void { echo "\n== $t ==\n"; }

/* ============================================================
 *  Tasas planas
 * ============================================================ */
titulo('Mensual de 30.000, sin conceptos');
$r = calcNominaRD(30000, [], 1.0, false);
comprueba('base cotizable = sueldo',       $r['base'], 30000.00);
comprueba('AFP 2,87%',                     $r['afp'],  861.00);
comprueba('SFS 3,04%',                     $r['sfs'],  912.00);
comprueba('exento: bajo 416.220 anual',    $r['isr'],  0.00);
comprueba('neto = base − retenciones',     $r['neto'], 28227.00);

/* ============================================================
 *  El ISR es anual y progresivo: se saca del mes y se prorratea
 * ============================================================ */
titulo('Sueldo que tributa: 80.000 mensual');
$m = calcNominaRD(80000, [], 1.0, false);
$q = calcNominaRD(80000, [], 0.5, false);
comprueba('el mensual retiene ISR',              $m['isr'] > 0, true);
comprueba('la quincena retiene la mitad',        $q['isr'], round($m['isr'] / 2, 2));

// A mano, con la escala de la DGII: base 80.000 − TSS 5,91% = 75.272 × 12.
$anual  = (80000 - 2296.00 - 2432.00) * 12;
$isrEsp = 79776.00 + ($anual - 867123.00) * 0.25;
comprueba('cuadra con la escala anual',          $m['isr'], round($isrEsp / 12, 2));

titulo('La quincena es exactamente la mitad');
$rq = calcNominaRD(30000, [], 0.5, false);
comprueba('sueldo del período', $rq['salarioPeriodo'], 15000.00);
comprueba('AFP',                $rq['afp'], 430.50);
comprueba('SFS',                $rq['sfs'], 456.00);

/* ============================================================
 *  Prorrateo por días
 * ============================================================ */
titulo('Días trabajados (quincena, base 11,91 días)');
$completo = calcNominaRD(30000, ['dias_base' => 11.91, 'dias_trabajados' => 11.91], 0.5, false);
$mitad    = calcNominaRD(30000, ['dias_base' => 11.91, 'dias_trabajados' => 5.955], 0.5, false);
$cero     = calcNominaRD(30000, ['dias_base' => 11.91, 'dias_trabajados' => 0], 0.5, false);
$sinDato  = calcNominaRD(30000, ['dias_base' => 11.91], 0.5, false);

comprueba('todos los días = medio sueldo',       $completo['salarioPeriodo'], 15000.00);
comprueba('la mitad de los días = la mitad',     $mitad['salarioPeriodo'],     7500.00);
comprueba('CERO días = no se paga nada',         $cero['salarioPeriodo'],         0.00);
comprueba('cero días: tampoco cotiza',           $cero['afp'],                    0.00);
comprueba('sin dato de días = período completo', $sinDato['salarioPeriodo'],  15000.00);

/* ============================================================
 *  Conceptos del período
 * ============================================================ */
titulo('Conceptos que suman y restan');
$c = calcNominaRD(30000, [
    'monto_horas_extra' => 1000, 'otros_ingresos' => 500, 'comisiones' => 2000,
    'descuento_dias' => 300, 'prima_vacacional' => 750, 'per_capita' => 400,
], 0.5, false);
comprueba('base = sueldo + extras − descuento',  $c['base'], 18200.00);
comprueba('la prima NO cotiza',                  $c['base'], 18200.00);
comprueba('la prima SÍ se paga en el neto',      $c['prima'], 750.00);
comprueba('el per cápita descuenta',             $c['perCapita'], 400.00);

/* ============================================================
 *  El neto nunca baja de cero
 * ============================================================ */
titulo('Cuota de préstamo mayor que lo devengado');
$p = calcNominaRD(30000, ['otras_deducciones' => 999999], 0.5, false);
comprueba('el neto no es negativo',              $p['neto'] >= 0, true);
comprueba('el neto queda en cero',               $p['neto'], 0.00);
comprueba('se descuenta solo lo que alcanza',    $p['prestamo'] < 999999, true);
comprueba('lo que no cupo se reporta',           $p['prestamoPendiente'] > 0, true);
comprueba('aplicado + pendiente = la cuota',     $p['prestamo'] + $p['prestamoPendiente'], 999999.00);

titulo('Robustez');
$neg = calcNominaRD(30000, ['descuento_dias' => 999999], 0.5, false);
comprueba('la base nunca es negativa',   $neg['base'] >= 0, true);
comprueba('la AFP nunca es negativa',    $neg['afp'] >= 0, true);
$z = calcNominaRD(0, [], 1.0, false);
comprueba('sueldo cero no revienta',     $z['neto'], 0.00);

/* ============================================================
 *  Bordes de la escala
 * ============================================================ */
titulo('Bordes de la escala anual del ISR');
$techoExento = 416220 / 12 / (1 - 0.0591);   // renta mensual que deja el anual justo en el tope
comprueba('en el techo del tramo exento no retiene',
    calcNominaRD(round($techoExento, 2), [], 1.0, false)['isr'], 0.00, 1.0);
comprueba('un poco por encima ya retiene',
    calcNominaRD(round($techoExento, 2) + 1000, [], 1.0, false)['isr'] > 0, true);

echo "\n" . ($fallos === 0
        ? "TODO EN VERDE ($total comprobaciones)"
        : "$fallos de $total FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
