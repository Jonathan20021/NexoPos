<?php
/**
 * Banco de pruebas de la TSS (Ley 87-01).
 *
 * Todas las pruebas pasan los parámetros a mano, así que NO dependen de lo que
 * haya en la base ni de si los topes están encendidos en producción.
 *
 *   php database/ecf_ejemplos/probar_tss.php
 *
 * Qué comprueba:
 *   · Con los topes apagados, el cálculo es idéntico al de siempre.
 *   · Cada régimen se topa en su múltiplo: SFS 10, AFP 20, SRL 4.
 *   · El tope es MENSUAL y se parte en una quincena.
 *   · El INFOTEP no lleva tope.
 *   · Con topes, el ISR SUBE: se retiene menos TSS, luego queda más gravado.
 *   · tssParametros() respeta la vigencia y no reescribe el pasado.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$pruebas = 0; $fallos = 0;
function afirmar(string $t, bool $ok, string $d = ''): void {
    global $pruebas, $fallos;
    $pruebas++;
    if (!$ok) { $fallos++; echo "  ✗ $t" . ($d ? "\n        $d" : '') . "\n"; }
    else echo "  ✓ $t" . ($d ? "  ($d)" : '') . "\n";
}
function casi(float $a, float $b, float $e = 0.01): bool { return abs($a - $b) < $e; }

/* Salario mínimo cotizable de laboratorio: 10,000 deja los topes en números
   redondos —SFS 100,000 · AFP 200,000 · SRL 40,000— y se ve a simple vista
   cuál muerde en cada caso. */
$BASE = [
    'salario_minimo_cotizable' => 10000.00,
    'sfs_empleado' => 0.0304, 'sfs_empleador' => 0.0709,
    'afp_empleado' => 0.0287, 'afp_empleador' => 0.0710,
    'srl_empleador' => 0.0110, 'infotep_empleador' => 0.0100,
    'tope_sfs_sm' => 10, 'tope_afp_sm' => 20, 'tope_srl_sm' => 4,
];
$ON  = $BASE + ['aplicar_topes' => 1];
$OFF = $BASE + ['aplicar_topes' => 0];

echo "\n=== Topes apagados: nada cambia ===\n";
$a = tssAportes(150000.00, 1.0, $OFF);
afirmar('AFP del empleado es el 2.87% de todo', casi($a['empleado']['afp'], 150000 * 0.0287));
afirmar('SFS del empleado es el 3.04% de todo', casi($a['empleado']['sfs'], 150000 * 0.0304));
afirmar('Riesgos laborales sobre el sueldo entero', casi($a['empleador']['srl'], 150000 * 0.0110));
afirmar('No marca ningún régimen como topado', !array_filter($a['topado']));

echo "\n=== Topes encendidos: cada régimen corta en el suyo ===\n";
$b = tssAportes(150000.00, 1.0, $ON);
afirmar('SFS se topa en 10 mínimos = 100,000', casi($b['bases']['sfs'], 100000.00), 'base ' . $b['bases']['sfs']);
afirmar('AFP NO se topa: 150,000 < 20 mínimos', casi($b['bases']['afp'], 150000.00), 'base ' . $b['bases']['afp']);
afirmar('SRL se topa en 4 mínimos = 40,000', casi($b['bases']['srl'], 40000.00), 'base ' . $b['bases']['srl']);
afirmar('El INFOTEP no lleva tope: va sobre los 150,000',
    casi($b['empleador']['infotep'], 150000 * 0.0100));

afirmar('Al empleado se le retiene MENOS con topes',
    $b['total_empleado'] < $a['total_empleado'],
    'sin topes ' . $a['total_empleado'] . ' → con topes ' . $b['total_empleado']);
afirmar('A la empresa le cuesta MENOS con topes',
    $b['total_empleador'] < $a['total_empleador'],
    'sin topes ' . $a['total_empleador'] . ' → con topes ' . $b['total_empleador']);

echo "\n=== Un sueldo por debajo de todos los topes no se entera ===\n";
$c1 = tssAportes(25000.00, 1.0, $OFF);
$c2 = tssAportes(25000.00, 1.0, $ON);
afirmar('25,000 cotiza igual con y sin topes',
    casi($c1['total_empleado'], $c2['total_empleado']) && casi($c1['total_empleador'], $c2['total_empleador']));

echo "\n=== El tope es MENSUAL: en quincena se parte ===\n";
// Media base (75,000) contra el tope entero (100,000) no cortaría nunca; contra
// medio tope (50,000) sí. Este es el error clásico.
$q = tssAportes(75000.00, 0.5, $ON);
afirmar('SFS quincenal se topa en 50,000, no en 100,000', casi($q['bases']['sfs'], 50000.00), 'base ' . $q['bases']['sfs']);
afirmar('SRL quincenal se topa en 20,000', casi($q['bases']['srl'], 20000.00), 'base ' . $q['bases']['srl']);
afirmar('Dos quincenas topadas = un mes topado',
    casi($q['empleado']['sfs'] * 2, tssAportes(150000.00, 1.0, $ON)['empleado']['sfs']));

echo "\n=== Efecto en la nómina y en el ISR ===\n";
$sinT = calcNominaRD(150000.00, ['dias_base' => 11.91, 'dias_trabajados' => 11.91], 0.5, $OFF);
$conT = calcNominaRD(150000.00, ['dias_base' => 11.91, 'dias_trabajados' => 11.91], 0.5, $ON);
afirmar('Con topes se retiene menos AFP+SFS',
    ($conT['afp'] + $conT['sfs']) < ($sinT['afp'] + $sinT['sfs']),
    'sin ' . round($sinT['afp'] + $sinT['sfs'], 2) . ' → con ' . round($conT['afp'] + $conT['sfs'], 2));
afirmar('Y por eso el ISR SUBE: queda más renta gravada',
    $conT['isr'] > $sinT['isr'],
    'sin ' . $sinT['isr'] . ' → con ' . $conT['isr']);
afirmar('El neto se mueve, pero no se dispara',
    abs($conT['neto'] - $sinT['neto']) < $sinT['neto'] * 0.10);

echo "\n=== Costo para la empresa ===\n";
$kSin = costoEmpleadorRD(150000.00, true, $OFF);
$kCon = costoEmpleadorRD(150000.00, true, $ON);
afirmar('El costo patronal baja al topar', $kCon['total'] < $kSin['total'],
    money($kSin['total'], false) . ' → ' . money($kCon['total'], false));
afirmar('La regalía NO se topa: es del Código de Trabajo, no de la TSS',
    casi($kSin['regalia'], $kCon['regalia']));
afirmar('El modo «sin topes» sigue dando el 24.62% de siempre',
    casi($kSin['recargo'], 24.62), 'recargo ' . $kSin['recargo'] . '%');

echo "\n=== Guardas ===\n";
afirmar('Sin salario mínimo cotizable NO se topa nada, aunque esté encendido',
    !tssTopesActivos(['aplicar_topes' => 1, 'salario_minimo_cotizable' => 0]));
afirmar('Un salario de cero no rompe ni divide por cero',
    tssAportes(0.0, 1.0, $ON)['total'] === 0.0);
afirmar('Un salario negativo se trata como cero',
    tssAportes(-9000.0, 1.0, $ON)['total'] === 0.0);
afirmar('Un régimen inventado no tiene tope', tssTope('inventado', $ON) === 0.0);

echo "\n=== Vigencia: el pasado no se reescribe ===\n";
$p2026 = tssParametros('2026-06-30');
afirmar('Hay parámetros vigentes en la base', $p2026 !== null,
    $p2026 ? 'vigencia ' . $p2026['vigencia_desde'] : 'NO se aplicó la migración P22');
if ($p2026) {
    afirmar('Nacen APAGADOS: la nómina no cambia al desplegar',
        (int) $p2026['aplicar_topes'] === 0);
    afirmar('Una fecha anterior a toda vigencia no devuelve parámetros',
        tssParametros('2000-01-01') === null);
}

echo "\n--------------------------------------------------------------------------\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, $fallos);
echo $fallos === 0
    ? "  ✓ Los topes de la Ley 87-01 cortan donde deben y no cambian nada hasta que se enciendan.\n"
    : "  ✗ Hay fallos que revisar.\n";
exit($fallos === 0 ? 0 : 1);
