<?php
/**
 * Banco de pruebas de la sincronización del ponche.
 *
 *   php pruebas/biotime_sync.php
 *
 * No toca la red: le da a `bioSincronizar()` las filas a mano, para poder
 * probar los casos que en los datos de este cliente todavía no han ocurrido
 * pero van a ocurrir en cuanto la gente empiece a ponchar de verdad.
 *
 * Cada comprobación de aquí corresponde a un fallo concreto. Si alguna se cae,
 * el fallo volvió.
 *
 * Devuelve 0 si todo pasa y 1 si algo falla.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/includes/biotime.php';

$fallos = 0;
function ok(string $t, bool $c, string $x = ''): void {
    global $fallos; if (!$c) $fallos++;
    echo ($c ? '  OK    ' : '  FALLA ') . $t . (!$c && $x !== '' ? "  →  $x" : '') . "\n";
}

/* ============================================================
   1) La fecha del reloj viene con el día primero
   ============================================================ */
echo "\n=== LA FECHA (día primero, no mes) ===\n";
ok('31-08-2026 es el 31 de agosto', bioFechaISO('31-08-2026') === '2026-08-31', (string) bioFechaISO('31-08-2026'));

// El caso que muerde: con día ≤ 12, strtotime() lo lee como mes y guarda la
// fila en otro mes sin quejarse. Aquí serían el 5 de agosto, no el 8 de mayo.
ok('05-08-2026 es el 5 de AGOSTO, no el 8 de mayo',
    bioFechaISO('05-08-2026') === '2026-08-05',
    (string) bioFechaISO('05-08-2026') . '  (strtotime diría ' . date('Y-m-d', strtotime('05-08-2026')) . ')');

ok('una fecha ya en ISO pasa igual',  bioFechaISO('2026-08-31') === '2026-08-31');
ok('32-01-2026 no existe: null',      bioFechaISO('32-01-2026') === null);
ok('29-02-2027 no existe: null',      bioFechaISO('29-02-2027') === null);
ok('29-02-2028 sí existe',            bioFechaISO('29-02-2028') === '2028-02-29');
ok('vacío da null',                   bioFechaISO('') === null);
ok('basura da null',                  bioFechaISO('ayer por la tarde') === null);

/* ============================================================
   2) La hora
   ============================================================ */
echo "\n=== LA HORA ===\n";
ok('08:45 → 08:45:00',        bioHoraISO('08:45') === '08:45:00');
ok('08:45:26 → 08:45:00',     bioHoraISO('08:45:26') === '08:45:00');
ok('8:45 → 08:45:00',         bioHoraISO('8:45') === '08:45:00');
ok('25:00 no existe: null',   bioHoraISO('25:00') === null);
ok('08:99 no existe: null',   bioHoraISO('08:99') === null);
ok('vacío da null',           bioHoraISO('') === null);

/* ============================================================
   3) El reloj del aparato
   ============================================================ */
echo "\n=== ¿EL APARATO TIENE LA HORA BIEN? ===\n";
$marca = fn(string $p, string $u) => ['punch_time' => $p, 'upload_time' => $u];
ok('−240 min (UTC−4) es lo normal',
    bioRelojDeFiar($marca('2026-08-31 08:45:26', '2026-08-31 12:45:29')));
ok('un retraso de subida de 8 min sigue valiendo',
    bioRelojDeFiar($marca('2026-08-31 08:45:26', '2026-08-31 12:53:29')));
ok('17 horas de desvío NO vale',
    !bioRelojDeFiar($marca('2026-07-29 03:32:38', '2026-07-29 21:00:00')),
    'ese aparato tenía el reloj mal en el montaje');
ok('sin upload_time se acepta (el reporte por días no lo trae)',
    bioRelojDeFiar(['punch_time' => '2026-08-31 08:45:26']));

/* ============================================================
   4) La sincronización, regla por regla
   ============================================================ */
echo "\n=== LA SINCRONIZACIÓN ===\n";

$emps = qAll("SELECT id, nombre, apellido FROM empleados WHERE estado='activo' ORDER BY id LIMIT 2");
[$a, $b] = $emps;
$guardado = qAll("SELECT id, biotime_emp_code FROM empleados WHERE biotime_emp_code IS NOT NULL");
q("UPDATE empleados SET biotime_emp_code = NULL");
q("UPDATE empleados SET biotime_emp_code = '901' WHERE id = ?", [$a['id']]);
q("UPDATE empleados SET biotime_emp_code = '902' WHERE id = ?", [$b['id']]);
q("DELETE FROM asistencias WHERE fecha BETWEEN '2026-08-01' AND '2026-08-31'");

/** Una fila como la que devuelve firstLastReport. */
function fila(string $code, string $fecha, ?string $ini, ?string $fin): array {
    return ['emp_code' => $code, 'att_date' => $fecha, 'first_punch' => $ini,
            'last_punch' => $fin, 'first_name' => 'Quien', 'last_name' => 'Sea'];
}
$D = ['2026-08-01', '2026-08-31'];

/* --- Un día normal --- */
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('901', '10-08-2026', '08:45', '17:30')]]);
$fila = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha='2026-08-10'", [$a['id']]);
ok('crea el día con entrada, salida y horas',
    $p['creadas'] === 1 && $fila && $fila['hora_entrada'] === '08:45:00'
    && $fila['hora_salida'] === '17:30:00' && abs((float) $fila['horas_trabajadas'] - 8.75) < 0.01,
    $fila ? "{$fila['hora_entrada']}–{$fila['hora_salida']} = {$fila['horas_trabajadas']} h" : 'no se creó');
ok('y queda marcado que lo trajo el reloj', $fila && $fila['origen'] === 'biotime');

/* --- Repetirla no cambia nada --- */
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('901', '10-08-2026', '08:45', '17:30')]]);
ok('repetir la sincronización no duplica ni reescribe',
    $p['creadas'] === 0 && $p['actualizadas'] === 0 && $p['sin_cambio'] === 1,
    json_encode(array_intersect_key($p, array_flip(['creadas','actualizadas','sin_cambio']))));

/* --- Si en el reloj cambia, se actualiza --- */
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('901', '10-08-2026', '08:45', '18:00')]]);
ok('si la marca cambia, la fila se actualiza', $p['actualizadas'] === 1);

/* --- LO CORREGIDO A MANO NO SE PISA --- */
q("UPDATE asistencias SET origen='manual', hora_salida='19:00:00', horas_trabajadas=10.25
    WHERE empleado_id=? AND fecha='2026-08-10'", [$a['id']]);
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('901', '10-08-2026', '07:00', '17:30')]]);
$fila = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha='2026-08-10'", [$a['id']]);
ok('lo que corrigió una persona NO se pisa',
    $fila['hora_salida'] === '19:00:00' && $fila['origen'] === 'manual',
    "{$fila['hora_entrada']}–{$fila['hora_salida']} ({$fila['origen']})");
ok('pero la diferencia se avisa', count($p['respetadas_manual']) === 1,
    json_encode($p['respetadas_manual']));

/* --- UNA SOLA MARCA: no se inventa la salida --- */
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('902', '11-08-2026', '08:50', '08:50')]]);
$fila = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha='2026-08-11'", [$b['id']]);
ok('con una sola marca la salida queda vacía, no igual a la entrada',
    $fila && $fila['hora_entrada'] === '08:50:00' && $fila['hora_salida'] === null,
    $fila ? "salida = " . var_export($fila['hora_salida'], true) : 'no se creó');
ok('y no se le apuntan cero horas trabajadas como si hubiera venido y no hecho nada',
    $fila && (float) $fila['horas_trabajadas'] === 0.0 && count($p['incompletas']) === 1,
    json_encode($p['incompletas']));

/* --- UNA JORNADA QUE CRUZA MEDIANOCHE --- */
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('902', '12-08-2026', '22:00', '02:00')]]);
$fila = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha='2026-08-12'", [$b['id']]);
ok('si cruza medianoche no guarda horas negativas',
    $fila && (float) $fila['horas_trabajadas'] >= 0 && $fila['hora_salida'] === null,
    $fila ? "{$fila['horas_trabajadas']} h" : '—');
ok('y lo señala como incompleta',
    (bool) array_filter($p['incompletas'], fn($x) => str_contains($x, 'medianoche')),
    json_encode($p['incompletas']));

/* --- NO SE ADIVINA A NADIE --- */
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('777', '13-08-2026', '08:00', '17:00')]]);
ok('un código sin equivalencia NO se adivina: se informa',
    $p['creadas'] === 0 && isset($p['sin_emparejar']['777']),
    json_encode($p['sin_emparejar']));

/* --- NO SE ESCRIBEN AUSENCIAS --- */
$antes = (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha='2026-08-14'");
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('901', '14-08-2026', '08:00', '17:00')]]);
$despues = (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha='2026-08-14'");
ok('quien no ponchó NO se marca como ausente',
    $despues - $antes === 1 && (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha='2026-08-14' AND estado='ausente'") === 0,
    ($despues - $antes) . ' fila(s) nuevas de 56 personas');

/* --- FUERA DEL RANGO PEDIDO NO SE TOCA NADA --- */
$p = bioSincronizar('2026-08-20', '2026-08-21', ['filas' => [fila('901', '05-07-2026', '08:00', '17:00')]]);
ok('una fila fuera del rango pedido se descarta',
    $p['creadas'] === 0 && $p['fecha_mala'] === 1
    && !qVal("SELECT id FROM asistencias WHERE empleado_id=? AND fecha='2026-07-05'", [$a['id']]),
    "creadas={$p['creadas']} fecha_mala={$p['fecha_mala']}");

/* --- UNA FILA MALA NO TUMBA LAS DEMÁS --- */
$p = bioSincronizar($D[0], $D[1], ['filas' => [
    fila('901', 'no-es-fecha', '08:00', '17:00'),
    fila('777', '15-08-2026', '08:00', '17:00'),
    fila('902', '15-08-2026', '09:00', '18:00'),
]]);
ok('una fila mala no impide procesar las buenas',
    $p['creadas'] === 1 && $p['fecha_mala'] === 1 && count($p['sin_emparejar']) === 1,
    json_encode(['creadas'=>$p['creadas'],'fecha_mala'=>$p['fecha_mala'],'sin_emparejar'=>count($p['sin_emparejar'])]));

/* --- SIMULAR NO ESCRIBE --- */
$n0 = (int) qVal("SELECT COUNT(*) FROM asistencias");
$p = bioSincronizar($D[0], $D[1], ['simular' => true,
     'filas' => [fila('901', '20-08-2026', '08:00', '17:00')]]);
ok('en modo simulación cuenta pero no escribe',
    $p['creadas'] === 1 && (int) qVal("SELECT COUNT(*) FROM asistencias") === $n0,
    "dice {$p['creadas']} creada(s) y la tabla sigue con $n0");

/* --- QUIEN YA NO TRABAJA AQUÍ --- */
q("UPDATE empleados SET estado='inactivo' WHERE id=?", [$b['id']]);
$p = bioSincronizar($D[0], $D[1], ['filas' => [fila('902', '25-08-2026', '08:00', '17:00')]]);
ok('a quien ya está inactivo no se le apunta asistencia',
    $p['creadas'] === 0 && count($p['inactivos']) === 1,
    json_encode($p['inactivos']));
q("UPDATE empleados SET estado='activo' WHERE id=?", [$b['id']]);

/* --- DOS PERSONAS NO PUEDEN COMPARTIR CÓDIGO --- */
$choco = false;
try { q("UPDATE empleados SET biotime_emp_code='901' WHERE id=?", [$b['id']]); }
catch (Throwable $e) { $choco = true; }
ok('la base impide dar el mismo código de reloj a dos personas', $choco,
   'sin esto, los ponches de una acabarían en la nómina de la otra');

/* ---------- Limpieza ---------- */
q("DELETE FROM asistencias WHERE fecha BETWEEN '2026-08-01' AND '2026-08-31'");
q("UPDATE empleados SET biotime_emp_code = NULL");
foreach ($guardado as $g) q("UPDATE empleados SET biotime_emp_code=? WHERE id=?", [$g['biotime_emp_code'], $g['id']]);

echo "\n" . ($fallos === 0 ? "  EL PONCHE ENTRA LIMPIO\n\n" : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
