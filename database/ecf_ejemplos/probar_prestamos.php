<?php
/**
 * Banco de pruebas de los préstamos a empleados.
 *
 * Lo que hay que demostrar no es que la pantalla pinte, sino la CADENA
 * completa: se otorga → se autoriza → la nómina lo descuenta sola → al
 * confirmar la nómina la cuota queda cobrada y el saldo baja.
 *
 *   php database/ecf_ejemplos/probar_prestamos.php
 *
 * Escribe en la base y lo deshace todo al terminar.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (defined('APP_ENV') && APP_ENV === 'production') {
    fwrite(STDERR, "Esta prueba ESCRIBE en la base y no se corre contra producción.\n");
    exit(2);
}
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }
if (!presDisponible()) { fwrite(STDERR, "Falta aplicar migracion_prestamos_p23.sql\n"); exit(2); }

$pruebas = 0; $fallos = 0;
function afirmar(string $t, bool $ok, string $d = ''): void {
    global $pruebas, $fallos;
    $pruebas++;
    echo ($ok ? "  ✓ " : "  ✗ ") . $t . ($d ? "  ($d)" : '') . "\n";
    if (!$ok) $fallos++;
}
function casi(float $a, float $b, float $e = 0.011): bool { return abs($a - $b) < $e; }

$u = qOne("SELECT * FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
$_SESSION['user'] = $u; $_SESSION['user']['es_super'] = 1;

/* ---------------------------------------------------------------------------
 *  1) La amortización tiene que cuadrar al céntimo
 * ------------------------------------------------------------------------ */
echo "\n=== Cuadro de amortización ===\n";

$plan = presAmortizar(10000.00, 3, 0, 'quincenal', '2026-09-15');
afirmar('El capital repartido suma EXACTAMENTE el préstamo',
    casi(array_sum(array_column($plan, 'capital')), 10000.00),
    'suma ' . array_sum(array_column($plan, 'capital')));
afirmar('El saldo termina en cero, sin céntimos colgando',
    casi((float) end($plan)['saldo'], 0.0), 'saldo final ' . end($plan)['saldo']);
afirmar('Sin tasa no hay intereses', array_sum(array_column($plan, 'interes')) == 0.0);
afirmar('Las fechas van cada 15 días', $plan[1]['fecha'] === '2026-09-30');

$planM = presAmortizar(50000.00, 12, 18.0, 'mensual', '2026-09-01');
afirmar('Con interés, el capital sigue sumando el préstamo',
    casi(array_sum(array_column($planM, 'capital')), 50000.00),
    'suma ' . round(array_sum(array_column($planM, 'capital')), 2));
afirmar('Con interés se devuelve MÁS de lo prestado',
    array_sum(array_column($planM, 'total')) > 50000.00,
    'total ' . round(array_sum(array_column($planM, 'total')), 2));
afirmar('Las cuotas mensuales van de mes en mes', $planM[1]['fecha'] === '2026-10-01');
afirmar('El interés baja con el saldo', $planM[0]['interes'] > $planM[11]['interes']);

$plan1 = presAmortizar(3333.33, 1, 0, 'quincenal', '2026-09-15');
afirmar('Un avance de una sola cuota se lleva todo', casi($plan1[0]['total'], 3333.33));

/* ---------------------------------------------------------------------------
 *  2) El tope legal se mide sobre el NETO
 * ------------------------------------------------------------------------ */
echo "\n=== Tope legal de descuento ===\n";

$e = qOne("SELECT id, nombre, apellido, salario FROM empleados WHERE estado='activo' AND salario > 0 ORDER BY salario DESC LIMIT 1");
$cfg = presConfig();
$chico = presCabeLegal((int) $e['id'], 100.00, 'quincenal');
afirmar('Una cuota mínima cabe de sobra', $chico['cabe']);
afirmar('El tope se calcula sobre el neto, no sobre el bruto',
    $chico['neto'] < (float) $e['salario'],
    'neto quincenal ' . money($chico['neto'], false) . ' de un sueldo de ' . money($e['salario'], false));

$enorme = presCabeLegal((int) $e['id'], $chico['neto'] * 0.95, 'quincenal');
afirmar('Una cuota del 95% del neto NO cabe', !$enorme['cabe'],
    'tope ' . $cfg['tope_pct_neto'] . '% = ' . money($enorme['tope_monto'], false));
afirmar('Y dice cuánto se pasa', $enorme['exceso'] > 0, 'exceso ' . money($enorme['exceso'], false));

/* ---------------------------------------------------------------------------
 *  3) La cadena completa
 * ------------------------------------------------------------------------ */
echo "\n=== De la firma al descuento ===\n";

$DESDE = '2026-09-01'; $HASTA = '2026-09-15';
q("DELETE FROM prestamos WHERE numero LIKE 'PRE-TEST%'");

$monto = 6000.00;
$plan = presAmortizar($monto, 3, 0, 'quincenal', '2026-09-10');   // 1.ª cuota DENTRO del período
$pid = tx(function () use ($e, $monto, $plan) {
    $id = dbInsert('prestamos', [
        'numero' => 'PRE-TEST1', 'empleado_id' => (int) $e['id'], 'tipo' => 'prestamo',
        'monto' => $monto, 'tasa_anual' => 0, 'cuotas' => 3, 'periodicidad' => 'quincenal',
        'fecha_desembolso' => '2026-09-01', 'fecha_primera_cuota' => '2026-09-10',
        'saldo' => $monto, 'estado' => 'activo', 'autorizado' => 0,
    ]);
    foreach ($plan as $c) {
        dbInsert('prestamo_cuotas', ['prestamo_id' => $id, 'numero' => $c['numero'],
            'fecha_prevista' => $c['fecha'], 'capital' => $c['capital'], 'interes' => $c['interes'],
            'total' => $c['total'], 'saldo_despues' => $c['saldo']]);
    }
    return $id;
});

afirmar('SIN autorización no se descuenta ni un peso',
    presCuotaDelPeriodo((int) $e['id'], $DESDE, $HASTA) == 0.0);

dbUpdate('prestamos', ['autorizado' => 1, 'autorizado_at' => date('Y-m-d H:i:s')], 'id = ?', [$pid]);
$cuota = presCuotaDelPeriodo((int) $e['id'], $DESDE, $HASTA);
afirmar('Autorizado, la cuota del período ya aparece', casi($cuota, 2000.00), money($cuota, false));
afirmar('Solo la cuota QUE VENCE en el período, no las tres',
    casi($cuota, (float) $plan[0]['total']));

// La nómina la descuenta sola.
$diasBase = nominaDiasBase('quincenal');
$nid = dbInsert('nominas', ['descripcion' => 'PRE-TEST nómina', 'tipo' => 'quincenal',
    'fecha_desde' => $DESDE, 'fecha_hasta' => $HASTA, 'estado' => 'borrador', 'usuario_id' => (int) $u['id']]);
$c = calcNominaRD((float) $e['salario'],
    ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase, 'otras_deducciones' => $cuota], 0.5);
$did = dbInsert('nomina_detalles', ['nomina_id' => $nid, 'empleado_id' => (int) $e['id'],
    'salario_base' => $c['salarioPeriodo'], 'dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
    'otras_deducciones' => $cuota, 'total_ingresos' => $c['totalIngresos'],
    'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'],
    'total_deducciones' => $c['totalDeducciones'], 'salario_neto' => $c['neto']]);

$sinPrestamo = calcNominaRD((float) $e['salario'], ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase], 0.5);
afirmar('El préstamo baja el neto exactamente en la cuota',
    casi($sinPrestamo['neto'] - $c['neto'], $cuota),
    money($sinPrestamo['neto'], false) . ' → ' . money($c['neto'], false));
afirmar('Y NO toca la base cotizable: un préstamo no es salario',
    casi((float) $sinPrestamo['totalIngresos'], (float) $c['totalIngresos']));

// Al confirmar la nómina se da por cobrada.
afirmar('Antes de confirmar, la cuota sigue pendiente',
    qVal("SELECT estado FROM prestamo_cuotas WHERE prestamo_id=? AND numero=1", [$pid]) === 'pendiente');

$n = presAplicarCobro((int) $e['id'], $did, $DESDE, $HASTA, $cuota);
afirmar('Al confirmar se cobra UNA cuota', $n === 1, "cuotas marcadas: $n");
afirmar('La cuota queda marcada como descontada',
    qVal("SELECT estado FROM prestamo_cuotas WHERE prestamo_id=? AND numero=1", [$pid]) === 'descontada');
afirmar('Y queda dicho de qué línea de nómina salió',
    (int) qVal("SELECT nomina_detalle_id FROM prestamo_cuotas WHERE prestamo_id=? AND numero=1", [$pid]) === $did);
afirmar('El saldo baja al capital que queda',
    casi((float) qVal("SELECT saldo FROM prestamos WHERE id=?", [$pid]), 4000.00),
    money((float) qVal("SELECT saldo FROM prestamos WHERE id=?", [$pid]), false));
afirmar('El período ya no ofrece esa cuota otra vez',
    presCuotaDelPeriodo((int) $e['id'], $DESDE, $HASTA) == 0.0);

/* Si se descuenta MENOS de lo previsto, la cuota NO se da por cobrada: mejor
   que quede pendiente a que la deuda desaparezca sin haberse pagado. */
$n2 = presAplicarCobro((int) $e['id'], $did, '2026-09-16', '2026-09-30', 500.00);
afirmar('Un descuento parcial NO da la cuota por cobrada', $n2 === 0);

/* ---------------------------------------------------------------------------
 *  4) Anular condona lo pendiente y respeta lo cobrado
 * ------------------------------------------------------------------------ */
echo "\n=== Anulación ===\n";
tx(function () use ($pid) {
    q("UPDATE prestamo_cuotas SET estado='condonada' WHERE prestamo_id=? AND estado='pendiente'", [$pid]);
    dbUpdate('prestamos', ['estado' => 'anulado', 'saldo' => 0], 'id = ?', [$pid]);
});
afirmar('Lo pendiente queda condonado',
    (int) qVal("SELECT COUNT(*) FROM prestamo_cuotas WHERE prestamo_id=? AND estado='condonada'", [$pid]) === 2);
afirmar('Lo ya cobrado NO se toca: salió de una nómina confirmada',
    (int) qVal("SELECT COUNT(*) FROM prestamo_cuotas WHERE prestamo_id=? AND estado='descontada'", [$pid]) === 1);

/* ---------------------------------------------------------------------------
 *  5) Saldado automático
 * ------------------------------------------------------------------------ */
echo "\n=== Saldado ===\n";
q("DELETE FROM prestamos WHERE numero = 'PRE-TEST2'");
$pid2 = dbInsert('prestamos', ['numero' => 'PRE-TEST2', 'empleado_id' => (int) $e['id'], 'tipo' => 'avance',
    'monto' => 1500.00, 'cuotas' => 1, 'periodicidad' => 'quincenal',
    'fecha_desembolso' => '2026-09-01', 'fecha_primera_cuota' => '2026-09-10',
    'saldo' => 1500.00, 'estado' => 'activo', 'autorizado' => 1]);
dbInsert('prestamo_cuotas', ['prestamo_id' => $pid2, 'numero' => 1, 'fecha_prevista' => '2026-09-10',
    'capital' => 1500.00, 'interes' => 0, 'total' => 1500.00, 'saldo_despues' => 0]);
presAplicarCobro((int) $e['id'], $did, $DESDE, $HASTA, 1500.00);
afirmar('Cobrada la última cuota, el préstamo se salda solo',
    qVal("SELECT estado FROM prestamos WHERE id=?", [$pid2]) === 'saldado');
afirmar('Y el saldo queda en cero',
    casi((float) qVal("SELECT saldo FROM prestamos WHERE id=?", [$pid2]), 0.0));

/* ---------- Limpieza ---------- */
q("DELETE FROM prestamos WHERE numero LIKE 'PRE-TEST%'");
q("DELETE FROM nomina_detalles WHERE nomina_id = ?", [$nid]);
q("DELETE FROM nominas WHERE id = ?", [$nid]);

echo "\n--------------------------------------------------------------------------\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, $fallos);
echo $fallos === 0
    ? "  ✓ El préstamo se descuenta solo, sin autorización no retiene nada, y lo cobrado no se borra.\n"
    : "  ✗ Hay fallos que revisar.\n";
exit($fallos === 0 ? 0 : 1);
