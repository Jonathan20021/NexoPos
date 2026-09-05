<?php
/**
 * El reloj vigilado: que la campana suene cuando el ponche se calla.
 *
 *   php pruebas/ponche_avisos.php
 *
 * Toda la asistencia entra por la integración. Si la integración se calla, la
 * asistencia deja de existir y nadie se entera hasta que alguien mira la
 * nómina. Ya pasó en producción: el cron corría cada día a las 5:00 y llevaba
 * cuatro sin traer una marca, sin que nada lo dijera.
 *
 * Un integrador que falla se arregla. Uno que se calla, no: no hay quien sepa
 * que hay algo que arreglar.
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
/** Los avisos vivos de una familia. */
function avisos(string $prefijo): array {
    return qAll("SELECT clave, titulo, mensaje, prioridad FROM notificaciones
                  WHERE clave LIKE ? AND resuelta_at IS NULL ORDER BY clave", [$prefijo . '%']);
}
function hay(string $prefijo, string $trozo = ''): bool {
    foreach (avisos($prefijo) as $a) {
        if ($trozo === '' || mb_stripos($a['titulo'] . ' ' . $a['mensaje'], $trozo) !== false) return true;
    }
    return false;
}

/* ---------- Montaje ---------- */
$emp = qOne("SELECT id, sucursal_id FROM empleados WHERE estado='activo' ORDER BY id LIMIT 1");
if (!$emp) { fwrite(STDERR, "  Hace falta un empleado activo.\n"); exit(1); }
$guardado = qAll("SELECT id, biotime_emp_code FROM empleados WHERE biotime_emp_code IS NOT NULL");
/* Varias comprobaciones necesitan la tabla de marcas VACÍA —«cuándo fue la
   última marca» es una pregunta global—, así que se aparta lo que hubiera y se
   devuelve al final. Un banco no puede llevarse por delante datos que no creó
   él: la primera versión de esto borró las marcas reales de la base local. */
$marcasPrev = qAll("SELECT * FROM asistencia_marcas");
q("DELETE FROM asistencia_marcas");
printf("  (apartadas %d marca(s) existentes; se devuelven al terminar)
", count($marcasPrev));
q("DELETE FROM asistencia_marcas WHERE biotime_id BETWEEN 950000 AND 950999");
q("DELETE FROM asistencias WHERE fecha BETWEEN '2026-05-01' AND '2026-05-31'");

/** Mete una marca como si viniera del reloj. */
function marca(int $bid, string $code, ?int $empId, string $cuando, ?int $desfase, string $term): void {
    dbInsert('asistencia_marcas', [
        'biotime_id' => $bid, 'emp_code' => $code, 'empleado_id' => $empId,
        'fecha' => date('Y-m-d', strtotime($cuando)), 'hora' => date('H:i:s', strtotime($cuando)),
        'marcada_en' => date('Y-m-d H:i:s', strtotime($cuando)),
        'subida_en' => $desfase === null ? null : date('Y-m-d H:i:s', strtotime($cuando) - $desfase * 60),
        'desfase_min' => $desfase, 'terminal' => $term, 'verificacion' => '15',
        'nombre_reloj' => 'Alguien Del Reloj',
    ]);
}

/* ============================================================
   1) El reloj se calló
   ============================================================ */
echo "\n=== EL RELOJ SE CALLÓ ===\n";
q("DELETE FROM asistencia_marcas");
marca(950001, '950', (int) $emp['id'], date('Y-m-d H:i:s', strtotime('-10 days')) , -240, 'TIENDA');
notif_gen_ponche();
ok('avisa cuando lleva días sin registrar nada', hay('ponche_mudo', 'sin registrar'),
    json_encode(array_column(avisos('ponche_mudo'), 'titulo')));
ok('y a los 7 días o más sube a prioridad alta',
    (avisos('ponche_mudo')[0]['prioridad'] ?? '') === 'alta',
    (string) (avisos('ponche_mudo')[0]['prioridad'] ?? '—'));

/* --- Con una marca de hoy, el aviso se retira solo --- */
q("DELETE FROM asistencia_marcas");
marca(950002, '950', (int) $emp['id'], date('Y-m-d H:i:s'), -240, 'TIENDA');
notif_gen_ponche();
ok('y se retira solo en cuanto vuelve a entrar una marca', !hay('ponche_mudo'),
    json_encode(array_column(avisos('ponche_mudo'), 'titulo')));

/* --- Un fin de semana largo no dispara nada --- */
q("DELETE FROM asistencia_marcas");
marca(950003, '950', (int) $emp['id'], date('Y-m-d H:i:s', strtotime('-2 days')), -240, 'TIENDA');
notif_gen_ponche();
ok('dos días no molestan: eso es un fin de semana', !hay('ponche_mudo'));

/* ============================================================
   2) Poncha gente que no le cuenta a nadie
   ============================================================ */
echo "\n=== PONCHA Y NO LE CUENTA A NADIE ===\n";
// Se limpia lo de arriba: aquellas marcas apuntaban al empleado SIN que él
// tuviera ese código, y `bioReclamarMarcas()` las desapunta —con razón—, lo
// que dejaría un huérfano de más enturbiando esta comprobación.
q("DELETE FROM asistencia_marcas");
marca(950010, '951', null, date('Y-m-d H:i:s'), -240, 'TIENDA');
notif_gen_ponche();
ok('avisa de quien poncha sin emparejar', hay('ponche_sin_emparejar', 'no le cuenta a nadie'),
    json_encode(array_column(avisos('ponche_sin_emparejar'), 'titulo')));
ok('y lo dice en singular cuando es una sola',
    hay('ponche_sin_emparejar', 'Una persona poncha'),
    (string) (avisos('ponche_sin_emparejar')[0]['titulo'] ?? '—'));

// Al emparejarla, el aviso desaparece: es una situación viva, no un mensaje.
q("UPDATE empleados SET biotime_emp_code = '951' WHERE id = ?", [$emp['id']]);
bioReclamarMarcas();
notif_gen_ponche();
ok('al emparejarla, el aviso se retira solo', !hay('ponche_sin_emparejar'));
q("UPDATE empleados SET biotime_emp_code = NULL WHERE id = ?", [$emp['id']]);

/* ============================================================
   3) Días sin hora de salida
   ============================================================ */
echo "\n=== DÍAS SIN SALIDA ===\n";
$ayer = date('Y-m-d', strtotime('-1 day'));
q("DELETE FROM asistencias WHERE empleado_id = ? AND fecha = ?", [$emp['id'], $ayer]);
dbInsert('asistencias', ['empleado_id' => (int) $emp['id'], 'sucursal_id' => (int) $emp['sucursal_id'],
    'fecha' => $ayer, 'hora_entrada' => '08:00:00', 'hora_salida' => null,
    'horas_trabajadas' => 0, 'horas_extra' => 0, 'estado' => 'presente', 'origen' => 'biotime']);
notif_gen_ponche();
ok('avisa de los días que quedaron sin salida', hay('ponche_sin_salida', 'sin hora de salida'),
    json_encode(array_column(avisos('ponche_sin_salida'), 'titulo')));
ok('y explica que eso deja el día en cero horas', hay('ponche_sin_salida', 'cero'));

// Un día de hace medio año ya no se va a completar: recordarlo para siempre
// convierte el aviso en parte del paisaje y deja de leerse.
q("UPDATE asistencias SET fecha = '2026-01-15' WHERE empleado_id = ? AND fecha = ?", [$emp['id'], $ayer]);
notif_gen_ponche();
ok('un día de hace meses ya no molesta', !hay('ponche_sin_salida'));
q("DELETE FROM asistencias WHERE empleado_id = ? AND fecha = '2026-01-15'", [$emp['id']]);

/* ============================================================
   4) Aparatos con la hora mal puesta
   ============================================================ */
echo "\n=== APARATO CON LA HORA MAL ===\n";
marca(950020, '952', null, date('Y-m-d H:i:s'), -240, 'BUENO');
marca(950021, '953', null, date('Y-m-d H:i:s'), 330, 'DESAJUSTADO');
notif_gen_ponche();
ok('avisa del aparato desajustado', hay('ponche_reloj_malo', 'DESAJUSTADO'),
    json_encode(array_column(avisos('ponche_reloj_malo'), 'titulo')));
ok('y NO del que está en hora', !hay('ponche_reloj_malo', 'BUENO'));

/* ============================================================
   5) Correr dos veces no duplica
   ============================================================ */
echo "\n=== NO SE DUPLICA ===\n";
$n1 = (int) qVal("SELECT COUNT(*) FROM notificaciones WHERE clave LIKE 'ponche%' AND resuelta_at IS NULL");
notif_gen_ponche(); notif_gen_ponche();
$n2 = (int) qVal("SELECT COUNT(*) FROM notificaciones WHERE clave LIKE 'ponche%' AND resuelta_at IS NULL");
ok('generar dos veces deja los mismos avisos', $n1 === $n2, "$n1 → $n2");

/* ---------- Limpieza ---------- */
q("DELETE FROM asistencia_marcas");
foreach ($marcasPrev as $m) dbInsert('asistencia_marcas', $m);   // devueltas tal cual estaban
q("DELETE FROM asistencias WHERE empleado_id = ? AND fecha = ?", [$emp['id'], $ayer]);
q("UPDATE empleados SET biotime_emp_code = NULL");
foreach ($guardado as $g) q("UPDATE empleados SET biotime_emp_code=? WHERE id=?", [$g['biotime_emp_code'], $g['id']]);
notif_gen_ponche();   // deja los avisos acordes con lo que quede de verdad

printf("\n  (devueltas %d marca(s) de las que había antes)\n",
    (int) qVal("SELECT COUNT(*) FROM asistencia_marcas"));
echo "\n" . ($fallos === 0 ? "  EL RELOJ NO SE CALLA SIN QUE SE SEPA\n\n" : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
