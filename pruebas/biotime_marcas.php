<?php
/**
 * Las marcas en bruto: el histórico que permite contestar una reclamación.
 *
 *   php pruebas/biotime_marcas.php
 *
 * `asistencias` guarda el resumen del día. Esto guarda lo que de verdad pasó,
 * marca a marca — porque «entró a las 10:23 y salió a las 18:02» no dice a qué
 * hora se fue a almorzar, y esa es justo la pregunta que llega semanas después.
 *
 * No toca la red: las marcas se inyectan a mano.
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

/* ---------- Montaje ---------- */
$emps = qAll("SELECT id, nombre, apellido FROM empleados WHERE estado='activo' ORDER BY id LIMIT 2");
if (count($emps) < 2) { fwrite(STDERR, "  Hacen falta 2 empleados activos.\n"); exit(1); }
[$a, $b] = $emps;
$guardado = qAll("SELECT id, biotime_emp_code FROM empleados WHERE biotime_emp_code IS NOT NULL");
q("UPDATE empleados SET biotime_emp_code = NULL");
q("DELETE FROM asistencia_marcas WHERE biotime_id BETWEEN 900000 AND 900999");

/** Mete una marca como si viniera del reloj. */
function marca(int $bid, string $code, string $cuando, ?string $subida, string $term = 'TIENDA', string $ver = '15'): void
{
    $de = qVal("SELECT id FROM empleados WHERE biotime_emp_code = ?", [$code]);
    dbInsert('asistencia_marcas', [
        'biotime_id' => $bid, 'emp_code' => $code, 'empleado_id' => $de ? (int) $de : null,
        'fecha' => date('Y-m-d', strtotime($cuando)), 'hora' => date('H:i:s', strtotime($cuando)),
        'marcada_en' => date('Y-m-d H:i:s', strtotime($cuando)),
        'subida_en' => $subida ? date('Y-m-d H:i:s', strtotime($subida)) : null,
        'desfase_min' => $subida ? (int) round((strtotime($cuando) - strtotime($subida)) / 60) : null,
        'terminal' => $term, 'verificacion' => $ver, 'nombre_reloj' => 'Quien Sea',
    ]);
}

/* ============================================================
   1) Cómo se identificó, en cristiano
   ============================================================ */
echo "\n=== EL TIPO DE MARCA ===\n";
// La equivalencia se dedujo cruzando las dos fuentes sobre los datos reales
// del cliente, no de un manual.
ok('1 es huella',       bioVerificacion('1') === 'Huella');
ok('3 es contraseña',   bioVerificacion('3') === 'Contraseña');
ok('15 es rostro',      bioVerificacion('15') === 'Rostro');
ok('un código que no conozco se enseña tal cual, no se inventa',
    bioVerificacion('99') === '99', bioVerificacion('99'));
ok('vacío da raya',     bioVerificacion('') === '—');

/* ============================================================
   2) El día sale de las marcas, con las de en medio conservadas
   ============================================================ */
echo "\n=== EL DÍA, A PARTIR DE LAS MARCAS ===\n";
q("UPDATE empleados SET biotime_emp_code='901' WHERE id=?", [$a['id']]);
marca(900001, '901', '2026-06-10 10:23:52', '2026-06-10 14:23:53');
marca(900002, '901', '2026-06-10 10:24:56', '2026-06-10 14:24:57');
marca(900003, '901', '2026-06-10 13:25:05', '2026-06-10 17:25:06');
marca(900004, '901', '2026-06-10 18:02:22', '2026-06-10 22:02:23');

$d = bioDiasDesdeMarcas('2026-06-10', '2026-06-10');
$fila = $d[0] ?? null;
ok('toma la primera y la última del día',
    $fila && substr((string) $fila['first_punch'], 0, 5) === '10:23'
          && substr((string) $fila['last_punch'], 0, 5) === '18:02',
    $fila ? "{$fila['first_punch']} → {$fila['last_punch']}" : 'sin fila');
ok('y dice cuántas hubo en total', $fila && (int) $fila['marcas'] === 4, (string) ($fila['marcas'] ?? '—'));
ok('la fecha sale en el formato que espera la sincronización',
    $fila && $fila['att_date'] === '10-06-2026', (string) ($fila['att_date'] ?? '—'));
ok('las marcas de en medio NO se pierden',
    (int) qVal("SELECT COUNT(*) FROM asistencia_marcas WHERE emp_code='901' AND fecha='2026-06-10'") === 4);

// Y la sincronización, comiendo de ahí, deja el día bien.
$p = bioSincronizar('2026-06-10', '2026-06-10', ['filas' => $d]);
$as = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha='2026-06-10'", [$a['id']]);
ok('el día entra en asistencias con las horas bien',
    $as && $as['hora_entrada'] === '10:23:00' && $as['hora_salida'] === '18:02:00'
        && abs((float) $as['horas_trabajadas'] - 7.65) < 0.02,
    $as ? "{$as['hora_entrada']}–{$as['hora_salida']} = {$as['horas_trabajadas']} h" : 'no entró');

/* ============================================================
   3) El reloj desajustado
   ============================================================ */
echo "\n=== EL APARATO CON LA HORA MAL ===\n";
$m = ['punch_time' => '2026-06-11 08:00:00', 'upload_time' => '2026-06-11 12:00:00'];
ok('−240 min es lo normal en RD', bioRelojDeFiar($m));
marca(900010, '901', '2026-06-11 08:00:00', '2026-06-11 12:00:00');           // en hora
marca(900011, '901', '2026-06-11 03:30:00', '2026-06-11 12:00:00', 'MAL');    // desajustado
ok('la marca del aparato desajustado SE GUARDA igual, no se tira',
    (int) qVal("SELECT COUNT(*) FROM asistencia_marcas WHERE biotime_id=900011") === 1,
    'tirarla dejaría un hueco sin explicación');
ok('y queda anotado cuánto se desviaba',
    (int) qVal("SELECT desfase_min FROM asistencia_marcas WHERE biotime_id=900011") === -510,
    (string) qVal("SELECT desfase_min FROM asistencia_marcas WHERE biotime_id=900011"));

/* ============================================================
   4) Reclamar las marcas al emparejar
   ============================================================ */
echo "\n=== EMPAREJAR RECLAMA EL PASADO ===\n";
marca(900020, '902', '2026-06-12 09:00:00', '2026-06-12 13:00:00');   // nadie tiene el 902
ok('una marca sin emparejar se guarda sin dueño',
    qVal("SELECT empleado_id FROM asistencia_marcas WHERE biotime_id=900020") === null);

q("UPDATE empleados SET biotime_emp_code='902' WHERE id=?", [$b['id']]);
bioReclamarMarcas();
ok('al emparejar, sus marcas ANTIGUAS pasan a ser suyas',
    (int) qVal("SELECT empleado_id FROM asistencia_marcas WHERE biotime_id=900020") === (int) $b['id'],
    'si no, su histórico empezaría el día en que alguien la emparejó');

q("UPDATE empleados SET biotime_emp_code=NULL WHERE id=?", [$b['id']]);
bioReclamarMarcas();
ok('y si se le quita la equivalencia, dejan de apuntarle',
    qVal("SELECT empleado_id FROM asistencia_marcas WHERE biotime_id=900020") === null,
    'un histórico que nombra a quien ya no corresponde es peor que uno incompleto');

/* ============================================================
   5) No se duplica
   ============================================================ */
echo "\n=== NO SE DUPLICA ===\n";
$antes = (int) qVal("SELECT COUNT(*) FROM asistencia_marcas");
$choco = false;
try { marca(900001, '901', '2026-06-10 10:23:52', null); }
catch (Throwable $e) { $choco = true; }
ok('la misma marca del reloj no puede entrar dos veces', $choco
    && (int) qVal("SELECT COUNT(*) FROM asistencia_marcas") === $antes,
    'el índice único sobre biotime_id es lo que hace idempotente traer un rango');

/* ---------- Limpieza ---------- */
q("DELETE FROM asistencia_marcas WHERE biotime_id BETWEEN 900000 AND 900999");
q("DELETE FROM asistencias WHERE fecha BETWEEN '2026-06-01' AND '2026-06-30'");
q("UPDATE empleados SET biotime_emp_code = NULL");
foreach ($guardado as $g) q("UPDATE empleados SET biotime_emp_code=? WHERE id=?", [$g['biotime_emp_code'], $g['id']]);

echo "\n" . ($fallos === 0 ? "  EL HISTÓRICO GUARDA LO QUE PASÓ\n\n" : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
