<?php
/**
 * La antigüedad es dinero, y un padrón cargado de golpe la falsea en silencio.
 *
 *   php pruebas/antiguedad_avisos.php
 *
 * `fecha_ingreso` decide el preaviso, la cesantía, los días de vacaciones del
 * art. 177 y la regalía proporcional. Quince archivos la leen. Cuando se carga
 * un padrón de una vez, esa columna se queda con la fecha de la carga: en
 * producción, 56 de 57 personas comparten el 2026-07-16.
 *
 * El resultado no es un error visible, es una cifra convincente y equivocada.
 * A quien se liquide hoy se le pagarían semanas en vez de años.
 *
 * Devuelve 0 si todo pasa y 1 si algo falla.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$fallos = 0;
function ok(string $t, bool $c, string $x = ''): void {
    global $fallos; if (!$c) $fallos++;
    echo ($c ? '  OK    ' : '  FALLA ') . $t . (!$c && $x !== '' ? "  →  $x" : '') . "\n";
}
function avisos(): array {
    return qAll("SELECT clave, titulo, mensaje, prioridad FROM notificaciones
                  WHERE clave LIKE 'antiguedad%' AND resuelta_at IS NULL");
}
function hay(string $trozo): bool {
    foreach (avisos() as $a) if (mb_stripos($a['titulo'] . ' ' . $a['mensaje'], $trozo) !== false) return true;
    return false;
}

/* ---------- Se apartan las fechas reales y se devuelven al final ---------- */
$previas = qAll("SELECT id, fecha_ingreso, estado FROM empleados");
$ids = array_column($previas, 'id');
if (count($ids) < 12) { fwrite(STDERR, "  Hacen falta 12 empleados.\n"); exit(1); }

/* ============================================================
   1) Todos con la misma fecha: el marcador de la carga
   ============================================================ */
echo "\n=== TODOS CON LA MISMA FECHA ===\n";
q("UPDATE empleados SET fecha_ingreso = '2026-07-16', estado = 'activo'");
notif_gen_antiguedad();
ok('avisa cuando casi todos comparten fecha de ingreso', hay('misma fecha de ingreso'),
    json_encode(array_column(avisos(), 'titulo')));
ok('y lo trata como cosa seria', (avisos()[0]['prioridad'] ?? '') === 'alta',
    (string) (avisos()[0]['prioridad'] ?? '—'));
ok('y dice QUÉ cálculos quedan mal, no solo que hay un problema',
    hay('cesantía') && hay('regalía'),
    (string) (avisos()[0]['mensaje'] ?? '—'));

/* ============================================================
   2) Con fechas repartidas, no molesta
   ============================================================ */
echo "\n=== CON FECHAS DE VERDAD ===\n";
foreach ($ids as $i => $id) {
    q("UPDATE empleados SET fecha_ingreso = ? WHERE id = ?",
      [date('Y-m-d', strtotime('-' . (100 + $i * 37) . ' days')), $id]);
}
notif_gen_antiguedad();
ok('con fechas repartidas no dice nada', !hay('misma fecha'),
    json_encode(array_column(avisos(), 'titulo')));

/* --- Una tanda de contrataciones legítima tampoco --- */
foreach (array_slice($ids, 0, 5) as $id) q("UPDATE empleados SET fecha_ingreso='2025-03-01' WHERE id=?", [$id]);
notif_gen_antiguedad();
ok('cinco personas contratadas el mismo día es normal, y no molesta', !hay('misma fecha'),
    'contratar en tanda pasa; que sean casi todas, no');

/* ============================================================
   3) Sin fecha ninguna
   ============================================================ */
echo "\n=== SIN FECHA DE INGRESO ===\n";
q("UPDATE empleados SET fecha_ingreso = NULL WHERE id = ?", [$ids[0]]);
notif_gen_antiguedad();
ok('avisa de quien no tiene fecha de ingreso', hay('sin fecha de ingreso'),
    json_encode(array_column(avisos(), 'titulo')));
ok('y explica que no se le podría liquidar', hay('liquidar'));

/* --- La consulta no se rompe con MySQL 8 --- */
ok('la comprobación no compara contra la fecha cero',
    !str_contains(file_get_contents(dirname(__DIR__) . '/includes/notificaciones.php'),
                  "fecha_ingreso = '0000-00-00'"),
    'MySQL 8 lleva NO_ZERO_DATE y rechaza la consulta entera con el error 1525');

/* --- Al ponerle fecha, el aviso se retira --- */
q("UPDATE empleados SET fecha_ingreso = '2024-05-10' WHERE id = ?", [$ids[0]]);
notif_gen_antiguedad();
ok('al ponerle su fecha, el aviso se retira solo', !hay('sin fecha de ingreso'));

/* ============================================================
   4) No se duplica
   ============================================================ */
echo "\n=== NO SE DUPLICA ===\n";
q("UPDATE empleados SET fecha_ingreso = '2026-07-16'");
notif_gen_antiguedad();
$n1 = count(avisos());
notif_gen_antiguedad(); notif_gen_antiguedad();
ok('generar tres veces deja los mismos avisos', count(avisos()) === $n1, $n1 . ' → ' . count(avisos()));

/* ---------- Se devuelven las fechas ---------- */
foreach ($previas as $p) {
    q("UPDATE empleados SET fecha_ingreso = ?, estado = ? WHERE id = ?",
      [$p['fecha_ingreso'], $p['estado'], $p['id']]);
}
notif_gen_antiguedad();
printf("\n  (devueltas las fechas de %d empleado(s))\n", count($previas));

echo "\n" . ($fallos === 0 ? "  UNA ANTIGÜEDAD FALSA YA NO PASA CALLADA\n\n" : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
