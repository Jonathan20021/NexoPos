<?php
/**
 * Banco de pruebas de la nómina dominicana.
 *
 * No toca la base: `calcNominaRD()` es una función pura. Se puede correr contra
 * cualquier entorno.
 *
 *   php database/ecf_ejemplos/probar_nomina.php
 *
 * Qué comprueba:
 *   · El ISR de los seis casos de la tabla del contador, al centavo.
 *   · El prorrateo por días reproduce `=(Sueldo/2/11.91)*Días` del Excel.
 *   · La base cotizable incluye lo que debe e IGNORA la prima vacacional.
 *   · El préstamo se descuenta del neto y la prima se paga — las dos
 *     correcciones sobre la hoja del cliente.
 *   · El ISR se calcula sobre el mes, no sobre la quincena.
 */

$raiz = dirname(__DIR__, 2);
require_once $raiz . '/includes/nomina.php';

$pruebas = 0; $fallos = 0;
function afirmar(string $nombre, bool $cond, string $detalle = ''): void
{
    global $pruebas, $fallos;
    $pruebas++;
    if ($cond) { echo "  ✓ $nombre\n"; return; }
    $fallos++;
    echo "  ✗ $nombre" . ($detalle ? "  ($detalle)" : '') . "\n";
}
function casi(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) < $eps; }

/* =========================================================================
 * 1. El ISR contra la tabla del contador (filas 88-99 del Excel)
 * ====================================================================== */
echo "\nISR mensual · tabla del contador del cliente\n";

$tabla = [
    ['Ana Maria Guzman',      150000.00, 23866.69],
    ['Katherine Santos',       50000.00,  1854.00],
    ['Jenny Maribel Lopez',    67950.75,  4982.82],
    ['Damaris Hernandez',      38000.00,   160.38],
    ['Yirda Mariel Jimenez',   40000.00,   442.65],
    ['Nicole Anabel Fuenz.',   40000.00,   442.65],
];
foreach ($tabla as [$nombre, $sueldo, $esperado]) {
    $c = calcNominaRD($sueldo, [], 1.0);
    afirmar(sprintf('%-24s %10s → ISR %s', $nombre, number_format($sueldo, 2), number_format($esperado, 2)),
        casi($c['isr'], $esperado), 'dio ' . number_format($c['isr'], 2));
}

// Y en quincena tiene que ser exactamente la mitad.
$m = calcNominaRD(150000, [], 1.0);
$q = calcNominaRD(150000, [], 0.5);
afirmar('La quincena retiene la mitad del ISR mensual', casi($q['isr'], $m['isr'] / 2),
    'mensual=' . $m['isr'] . ' quincenal=' . $q['isr']);

// El bug que se corrigió: anualizar media quincena bajaba de tramo.
// Con 40,000 mensuales el ISR mensual es 442.65; si se anualizara la quincena
// (20,000 x 12 = 240,000) daría CERO por quedar bajo el mínimo exento.
$q40 = calcNominaRD(40000, [], 0.5);
afirmar('El ISR sale del mes completo, no de anualizar la quincena',
    $q40['isr'] > 0 && casi($q40['isr'], 442.65 / 2), 'dio ' . $q40['isr']);

/* =========================================================================
 * 2. TSS
 * ====================================================================== */
echo "\nAFP y SFS sobre la base del período\n";

$c = calcNominaRD(150000, [], 0.5);
afirmar('AFP 2.87% de la quincena', casi($c['afp'], 2152.50), 'dio ' . $c['afp']);
afirmar('SFS 3.04% de la quincena', casi($c['sfs'], 2280.00), 'dio ' . $c['sfs']);
// Los dos valores de la fila 4 del Excel (Ana María Guzmán), al centavo.

/* =========================================================================
 * 3. Prorrateo por días — la fórmula del Excel
 * ====================================================================== */
echo "\nProrrateo por días pagados\n";

$base = ['dias_base' => 11.91, 'dias_trabajados' => 11.91];
$c = calcNominaRD(150000, $base, 0.5);
afirmar('Quincena completa = medio sueldo exacto', casi($c['salarioPeriodo'], 75000.00),
    'dio ' . $c['salarioPeriodo']);

$c = calcNominaRD(150000, ['dias_base' => 11.91, 'dias_trabajados' => 6.00], 0.5);
$esperado = round(150000 / 2 / 11.91 * 6, 2);
afirmar('Media quincena reproduce =(Sueldo/2/11.91)*Días', casi($c['salarioPeriodo'], $esperado),
    'dio ' . $c['salarioPeriodo'] . ' esperaba ' . $esperado);

$c = calcNominaRD(150000, [], 0.5);
afirmar('Sin días declarados se paga el período completo', casi($c['salarioPeriodo'], 75000.00));

/* =========================================================================
 * 4. La base cotizable (columna N del contador)
 * ====================================================================== */
echo "\nBase cotizable\n";

$c = calcNominaRD(30000, [
    'dias_base' => 11.91, 'dias_trabajados' => 11.91,
    'monto_horas_extra' => 1000, 'otros_ingresos' => 500, 'reembolso' => 300,
    'vacaciones_diferencial' => 200, 'bonificaciones' => 400, 'descuento_dias' => 100,
], 0.5);
// 15000 + 1000 + 500 + 300 + 200 + 400 - 100 = 17300
afirmar('Suma feriados, otras remuneraciones, reembolso, diferencial e incentivos; resta días',
    casi($c['base'], 17300.00), 'dio ' . $c['base']);
afirmar('AFP y SFS van sobre esa base, no sobre el sueldo',
    casi($c['afp'], round(17300 * 0.0287, 2)) && casi($c['sfs'], round(17300 * 0.0304, 2)));

// La prima vacacional NO cotiza (así está en la hoja del contador).
$sin = calcNominaRD(30000, ['dias_base' => 11.91, 'dias_trabajados' => 11.91], 0.5);
$con = calcNominaRD(30000, ['dias_base' => 11.91, 'dias_trabajados' => 11.91, 'prima_vacacional' => 5000], 0.5);
afirmar('La prima vacacional NO entra en la base cotizable', casi($sin['base'], $con['base']),
    'sin=' . $sin['base'] . ' con=' . $con['base']);
afirmar('Y por tanto no mueve AFP, SFS ni ISR',
    casi($sin['afp'], $con['afp']) && casi($sin['sfs'], $con['sfs']) && casi($sin['isr'], $con['isr']));

/* =========================================================================
 * 5. Las dos correcciones sobre la hoja del cliente
 * ====================================================================== */
echo "\nLo que su Excel calcula mal\n";

// Su fórmula: U = N - S. Ignora T (préstamo) y G (prima).
afirmar('La prima vacacional SÍ se paga en el neto',
    casi($con['neto'], $sin['neto'] + 5000), 'sin=' . $sin['neto'] . ' con=' . $con['neto']);

$prest = calcNominaRD(30000, [
    'dias_base' => 11.91, 'dias_trabajados' => 11.91, 'otras_deducciones' => 2000,
], 0.5);
afirmar('El préstamo SÍ se descuenta del neto',
    casi($prest['neto'], $sin['neto'] - 2000), 'sin=' . $sin['neto'] . ' con préstamo=' . $prest['neto']);
afirmar('Pero el préstamo NO toca la base cotizable', casi($prest['base'], $sin['base']));

// El per-cápita sí es una retención (entra en S).
$pc = calcNominaRD(30000, ['dias_base' => 11.91, 'dias_trabajados' => 11.91, 'per_capita' => 350], 0.5);
afirmar('El per-cápita suma a las retenciones',
    casi($pc['totalDeducciones'], $sin['totalDeducciones'] + 350));

/* =========================================================================
 * 6. Coherencia
 * ====================================================================== */
echo "\nCoherencia\n";

$c = calcNominaRD(67950.75, [
    'dias_base' => 11.91, 'dias_trabajados' => 9.5, 'monto_horas_extra' => 1250.40,
    'comisiones' => 3200, 'prima_vacacional' => 1500, 'otras_deducciones' => 800, 'per_capita' => 275,
], 0.5);
afirmar('neto = base − retenciones − préstamo + prima',
    casi($c['neto'], $c['base'] - $c['totalDeducciones'] - 800 + 1500), 'neto=' . $c['neto']);
afirmar('retenciones = AFP + SFS + ISR + per-cápita',
    casi($c['totalDeducciones'], $c['afp'] + $c['sfs'] + $c['isr'] + 275));
afirmar('Ningún importe sale negativo',
    $c['base'] >= 0 && $c['afp'] >= 0 && $c['sfs'] >= 0 && $c['isr'] >= 0);

// Un descuento de días desmedido no puede volver la base negativa.
$x = calcNominaRD(30000, ['dias_base' => 11.91, 'dias_trabajados' => 11.91, 'descuento_dias' => 999999], 0.5);
afirmar('Un descuento mayor que el sueldo deja la base en cero, no en negativo', casi($x['base'], 0.0));

echo "\n--------------------------------------------------------------------------\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, $fallos);
echo $fallos === 0
    ? "  ✓ El cálculo cuadra con la tabla del contador y corrige lo que su hoja no hace.\n"
    : "  ✗ Hay fallos que revisar.\n";
exit($fallos === 0 ? 0 : 1);
