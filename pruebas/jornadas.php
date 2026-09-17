<?php
/**
 * Horarios, feriados y el día completo.
 *
 *   php pruebas/jornadas.php
 *
 * Lo que se prueba aquí decide si a alguien se le apunta una tardanza o una
 * falta, así que no basta con que «funcione»: cada caso de abajo es una
 * situación con dinero o con un expediente disciplinario detrás.
 *
 * Lo más importante que se comprueba es lo que el sistema se NIEGA a afirmar:
 * que alguien estuvo ausente cuando no se puede demostrar.
 *
 * Todo corre dentro de una transacción que se deshace al final: no deja ni una
 * fila. Devuelve 0 si todo pasa y 1 si algo falla.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/includes/jornadas.php';

$fallos = 0;
function ok(string $t, bool $c, string $x = ''): void {
    global $fallos; if (!$c) $fallos++;
    echo ($c ? '  OK    ' : '  FALLA ') . $t . (!$c && $x !== '' ? "  →  $x" : '') . "\n";
}
function seccion(string $t): void { echo "\n=== " . mb_strtoupper($t, 'UTF-8') . " ===\n"; }

db()->beginTransaction();

try {

/* ===========================================================================
 *  FERIADOS · el traslado al lunes de la Ley 139-97
 * ======================================================================== */
seccion('feriados de RD');

// 2026: el 6 de enero cae MARTES → se va al lunes 5.
$f26 = jorFeriadosRD(2026);
ok('el 6 de enero, que cae martes, se mueve al lunes anterior',
   isset($f26['2026-01-05']) && !isset($f26['2026-01-06']),
   'salió ' . implode(', ', array_slice(array_keys($f26), 0, 3)));

// El 27 de febrero NO se mueve nunca, caiga donde caiga.
ok('la Independencia se queda el 27 de febrero aunque caiga entre semana',
   isset($f26['2026-02-27']));
ok('el Año Nuevo se queda el 1 de enero', isset($f26['2026-01-01']));
ok('la Navidad se queda el 25 de diciembre', isset($f26['2026-12-25']));

// Duarte, 26 de enero de 2026, cae LUNES: se queda.
ok('un feriado trasladable que ya cae lunes no se mueve', isset($f26['2026-01-26']));

// 2027: el 6 de enero cae MIÉRCOLES → lunes 4. El 26 de enero, MARTES → lunes 25.
$f27 = jorFeriadosRD(2027);
ok('miércoles también se va al lunes ANTERIOR', isset($f27['2027-01-04']));
ok('y el martes siguiente igual',               isset($f27['2027-01-25']));

// 2026: el 1 de mayo cae VIERNES → lunes 4 (siguiente).
ok('jueves y viernes se van al lunes SIGUIENTE, no al anterior',
   isset($f26['2026-05-04']) && !isset($f26['2026-05-01']));

// Semana Santa se calcula desde la Pascua, no de una lista.
$viernesSanto = array_search('Viernes Santo', $f26, true);
ok('el Viernes Santo sale de la Pascua y cae en viernes',
   $viernesSanto && (int) date('N', strtotime($viernesSanto)) === 5, (string) $viernesSanto);
$corpus = array_search('Corpus Christi', $f26, true);
ok('Corpus Christi cae en jueves, sesenta días después de la Pascua',
   $corpus && (int) date('N', strtotime($corpus)) === 4, (string) $corpus);

ok('un año entero trae los doce feriados de ley', count($f26) === 12, (string) count($f26));

/* ---- Sembrar respeta lo que puso una persona ---- */
seccion('sembrar el año');
q("DELETE FROM feriados WHERE fecha BETWEEN '2029-01-01' AND '2029-12-31'");
$s1 = jorSembrarFeriados(2029);
ok('la primera siembra crea el año completo', $s1['creados'] === 12, json_encode($s1));
$s2 = jorSembrarFeriados(2029);
ok('sembrar dos veces no duplica nada', $s2['creados'] === 0 && $s2['ya_estaban'] === 12, json_encode($s2));

// Un duelo nacional lo declara el Gobierno: no sale de ninguna ley.
dbInsert('feriados', ['fecha' => '2029-03-14', 'nombre' => 'Duelo nacional', 'automatico' => 0, 'pagado' => 1]);
$s3 = jorSembrarFeriados(2029);
ok('regenerar el año NO borra el feriado que añadió una persona',
   (bool) qVal("SELECT id FROM feriados WHERE fecha = '2029-03-14'"));

/* ===========================================================================
 *  LA JORNADA
 * ======================================================================== */
seccion('el horario');

$jid = dbInsert('jornadas', [
    'nombre' => '__prueba oficina', 'tolerancia_min' => 10, 'almuerzo_min' => 60,
    'extra_desde_min' => 30, 'activo' => 1,
]);
foreach ([1, 2, 3, 4, 5] as $d) {
    dbInsert('jornada_dias', ['jornada_id' => $jid, 'dia_semana' => $d, 'labora' => 1,
                              'hora_entrada' => '08:00:00', 'hora_salida' => '17:00:00']);
}
dbInsert('jornada_dias', ['jornada_id' => $jid, 'dia_semana' => 6, 'labora' => 1,
                          'hora_entrada' => '08:00:00', 'hora_salida' => '12:00:00']);
dbInsert('jornada_dias', ['jornada_id' => $jid, 'dia_semana' => 7, 'labora' => 0,
                          'hora_entrada' => null, 'hora_salida' => null]);

$emp = qOne("SELECT id, biotime_emp_code FROM empleados WHERE estado <> 'inactivo' ORDER BY id LIMIT 1");
if (!$emp) { echo "  (sin empleados en la base: no se puede seguir)\n"; db()->rollBack(); exit(1); }
$empId = (int) $emp['id'];
dbUpdate('empleados', ['jornada_id' => $jid, 'biotime_emp_code' => '__t1'], 'id = ?', [$empId]);
jorDeEmpleado(0, true);

$jor = jorDeEmpleado($empId);
ok('la jornada se lee con sus siete días', $jor && count($jor['dias']) === 7);

// 2026-09-14 es LUNES; 2026-09-19 SÁBADO; 2026-09-20 DOMINGO.
$lun = jorDelDia($jor, '2026-09-14');
$sab = jorDelDia($jor, '2026-09-19');
$dom = jorDelDia($jor, '2026-09-20');
ok('de lunes a viernes son 8 horas: 9 menos la de almuerzo',
   $lun['labora'] && abs($lun['horas'] - 8.0) < 0.01, (string) $lun['horas']);
ok('el sábado de 8 a 12 son 4 horas SIN restar almuerzo',
   $sab['labora'] && abs($sab['horas'] - 4.0) < 0.01, (string) $sab['horas']);
ok('el domingo no se trabaja', !$dom['labora']);

/* ===========================================================================
 *  EL DÍA COMPLETO
 * ======================================================================== */
seccion('puntualidad');
$ev = fn(string $f, ?string $e, ?string $s, bool $vivo = true) =>
    jorEvaluarDia(['id' => $empId, 'biotime_emp_code' => '__t1'], $f, $e, $s, $vivo);

$r = $ev('2026-09-14', '07:55:00', '17:05:00');
ok('llegar antes de la hora no es tardanza', $r['estado'] === 'presente' && $r['tardanza_min'] === 0);

$r = $ev('2026-09-14', '08:08:00', '17:00:00');
ok('dentro de la tolerancia tampoco', $r['estado'] === 'presente' && $r['tardanza_min'] === 0);

$r = $ev('2026-09-14', '08:25:00', '17:00:00');
ok('pasada la tolerancia sí es tardanza', $r['estado'] === 'tardanza');
ok('y se cuentan los 25 minutos enteros, no los 15 que pasan de la tolerancia',
   $r['tardanza_min'] === 25, (string) $r['tardanza_min']);

$r = $ev('2026-09-14', '08:00:00', '15:30:00');
ok('irse antes se anota en minutos', $r['salida_temprana_min'] === 90, (string) $r['salida_temprana_min']);

seccion('horas');
$r = $ev('2026-09-14', '08:00:00', '17:00:00');
ok('nueve horas de reloj menos el almuerzo son ocho trabajadas',
   abs($r['horas_trabajadas'] - 8.0) < 0.01, (string) $r['horas_trabajadas']);
ok('y no sobra ninguna', $r['horas_extra'] == 0.0);

$r = $ev('2026-09-14', '08:00:00', '17:20:00');
ok('quedarse veinte minutos no es una hora extra', $r['horas_extra'] == 0.0, (string) $r['horas_extra']);

$r = $ev('2026-09-14', '08:00:00', '19:00:00');
ok('quedarse dos horas sí lo es', abs($r['horas_extra'] - 2.0) < 0.01, (string) $r['horas_extra']);

$r = $ev('2026-09-19', '08:00:00', '12:00:00');
ok('el sábado corto no resta almuerzo: cuatro horas son cuatro',
   abs($r['horas_trabajadas'] - 4.0) < 0.01, (string) $r['horas_trabajadas']);

$r = $ev('2026-09-14', '08:00:00', null);
ok('sin marcar la salida el día queda en cero horas', $r['horas_trabajadas'] == 0.0);
ok('y se avisa, porque es lo que se paga',
   (bool) array_filter($r['avisos'], fn($a) => str_contains($a, 'salida')));

$r = $ev('2026-09-14', '22:00:00', '06:00:00');
ok('una salida anterior a la entrada no produce horas negativas', $r['horas_trabajadas'] >= 0);
ok('y se dice que cruza la medianoche',
   (bool) array_filter($r['avisos'], fn($a) => str_contains($a, 'medianoche')));

/* ===========================================================================
 *  SIN MARCAS — lo que el sistema se niega a afirmar
 * ======================================================================== */
seccion('sin marcas');

// Descanso: domingo.
ok('el domingo sin marcas es descanso, no una falta', $ev('2026-09-20', null, null)['estado'] === 'descanso');

// Feriado.
jorSembrarFeriados(2026);
$r = $ev('2026-02-27', null, null);
ok('un feriado sin marcas es feriado, no una falta', $r['estado'] === 'feriado', $r['estado']);
ok('y se dice cuál es', $r['feriado'] === 'Día de la Independencia', (string) $r['feriado']);

// Vacaciones aprobadas.
$vid = dbInsert('vacaciones', [
    'empleado_id' => $empId, 'tipo' => 'vacaciones', 'fecha_solicitud' => '2026-09-01',
    'fecha_desde' => '2026-09-14', 'fecha_hasta' => '2026-09-18', 'dias' => 5,
    'dias_laborables' => 5, 'con_goce' => 1, 'estado' => 'aprobada',
]);
$r = $ev('2026-09-15', null, null);
ok('unas vacaciones aprobadas explican la ausencia', $r['estado'] === 'vacaciones', $r['estado']);

dbUpdate('vacaciones', ['tipo' => 'licencia', 'subtipo' => 'enfermedad'], 'id = ?', [$vid]);
$r = $ev('2026-09-15', null, null);
ok('una licencia también, y con su motivo',
   $r['estado'] === 'licencia' && str_contains((string) $r['permiso'], 'enfermedad'), (string) $r['permiso']);

// Una solicitud NO aprobada no justifica nada.
dbUpdate('vacaciones', ['estado' => 'solicitada'], 'id = ?', [$vid]);
$r = $ev('2026-09-15', null, null);
ok('una solicitud SIN aprobar no justifica la ausencia', $r['estado'] !== 'licencia', $r['estado']);
q("DELETE FROM vacaciones WHERE id = ?", [$vid]);

seccion('cuándo se puede decir «ausente»');

// Día laborable, pasado, emparejado y con el reloj vivo: se puede afirmar.
$r = $ev('2026-09-16', null, null, true);
ok('con todo a favor sí se afirma la falta', $r['estado'] === 'ausente', $r['estado']);

// Si el reloj no registró nada de nadie ese día, no se puede.
$r = $ev('2026-09-16', null, null, false);
ok('si el reloj estuvo mudo ese día NO se afirma la falta', $r['estado'] === 'sin_marcas', $r['estado']);
ok('y se explica por qué',
   (bool) array_filter($r['avisos'], fn($a) => str_contains($a, 'no registró nada')));

// Sin código de reloj: pudo ponchar sin que le contara.
$r = jorEvaluarDia(['id' => $empId, 'biotime_emp_code' => null], '2026-09-16', null, null, true);
ok('a quien no tiene código de reloj NUNCA se le apunta una falta', $r['estado'] === 'sin_marcas', $r['estado']);

// Hoy todavía no ha terminado.
$r = $ev(date('Y-m-d'), null, null, true);
ok('el día de hoy no se da por ausente: aún puede ponchar', $r['estado'] === 'sin_marcas', $r['estado']);

seccion('sin horario asignado');
dbUpdate('empleados', ['jornada_id' => null], 'id = ?', [$empId]);
jorDeEmpleado(0, true);
$r = $ev('2026-09-14', '10:30:00', '17:00:00');
ok('a quien no tiene horario no se le inventa una tardanza',
   $r['tardanza_min'] === 0 && $r['estado'] === 'presente', $r['estado']);
ok('y queda dicho que le falta el horario', $r['sin_jornada'] === true);
$r = $ev('2026-09-16', null, null, true);
ok('tampoco se le apunta una falta: no se sabe si le tocaba trabajar',
   $r['estado'] === 'sin_marcas', $r['estado']);

dbUpdate('empleados', ['jornada_id' => $jid], 'id = ?', [$empId]);
jorDeEmpleado(0, true);

/* ===========================================================================
 *  CUANDO PONCHA IGUAL
 * ======================================================================== */
seccion('ponchó cuando no le tocaba');

$r = $ev('2026-09-20', '08:00:00', '14:00:00');   // domingo
ok('trabajar en su descanso NO borra que vino', $r['estado'] === 'presente');
ok('y todo lo trabajado ese día cuenta como extra', $r['horas_extra'] > 0, (string) $r['horas_extra']);
ok('y se cita el art. 205',
   (bool) array_filter($r['avisos'], fn($a) => str_contains($a, '205')));

$r = $ev('2026-02-27', '08:00:00', '17:00:00');   // feriado, un viernes
ok('trabajar un feriado tampoco lo borra', $r['estado'] === 'presente');
ok('y también se paga con recargo',
   (bool) array_filter($r['avisos'], fn($a) => str_contains($a, 'feriado')));

$vid = dbInsert('vacaciones', [
    'empleado_id' => $empId, 'tipo' => 'vacaciones', 'fecha_solicitud' => '2026-09-01',
    'fecha_desde' => '2026-09-14', 'fecha_hasta' => '2026-09-18', 'dias' => 5,
    'dias_laborables' => 5, 'con_goce' => 1, 'estado' => 'aprobada',
]);
$r = $ev('2026-09-15', '08:00:00', '17:00:00');
ok('ponchar estando de vacaciones se avisa: se pagaría dos veces',
   (bool) array_filter($r['avisos'], fn($a) => str_contains($a, 'dos veces')));
q("DELETE FROM vacaciones WHERE id = ?", [$vid]);

/* ===========================================================================
 *  RECALCULAR
 * ======================================================================== */
seccion('recalcular lo ya guardado');

q("DELETE FROM asistencias WHERE empleado_id = ? AND fecha IN ('2026-09-14','2026-09-15')", [$empId]);
dbInsert('asistencias', [
    'empleado_id' => $empId, 'fecha' => '2026-09-14', 'hora_entrada' => '08:40:00',
    'hora_salida' => '17:00:00', 'estado' => 'presente', 'origen' => 'biotime',
]);
dbInsert('asistencias', [
    'empleado_id' => $empId, 'fecha' => '2026-09-15', 'hora_entrada' => '09:00:00',
    'hora_salida' => '17:00:00', 'estado' => 'presente', 'origen' => 'manual',
]);
// El reloj tiene que constar vivo esos días para que el recálculo los juzgue igual.
$rec = jorRecalcular('2026-09-14', '2026-09-15', $empId);
ok('la fila del reloj se corrige a tardanza', $rec['cambiadas'] === 1, json_encode($rec));
ok('y la corregida a mano se respeta', $rec['respetadas_manual'] === 1, json_encode($rec));
$g = qOne("SELECT estado, tardanza_min FROM asistencias WHERE empleado_id = ? AND fecha = '2026-09-14'", [$empId]);
ok('queda guardada con sus minutos',
   $g['estado'] === 'tardanza' && (int) $g['tardanza_min'] === 40, json_encode($g));
$m = qOne("SELECT estado FROM asistencias WHERE empleado_id = ? AND fecha = '2026-09-15'", [$empId]);
ok('la de la persona sigue como la dejó', $m['estado'] === 'presente', json_encode($m));

$rec2 = jorRecalcular('2026-09-14', '2026-09-15', $empId);
ok('recalcular dos veces no cambia nada la segunda', $rec2['cambiadas'] === 0, json_encode($rec2));

/* ===========================================================================
 *  QUE LAS PANTALLAS USEN ESTE MISMO CRITERIO
 * ======================================================================== */
seccion('una sola fuente');
$sync = file_get_contents(dirname(__DIR__) . '/includes/biotime.php');
ok('la sincronización del reloj evalúa el día con esta función',
   str_contains($sync, 'jorEvaluarDia('),
   'si falla, el reloj está guardando estados con otro criterio que la pantalla');

} finally {
    db()->rollBack();
}

echo "\n" . ($fallos === 0
    ? "  EL HORARIO DECIDE, Y SOLO AFIRMA LO QUE PUEDE DEMOSTRAR\n\n"
    : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
