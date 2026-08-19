<?php
/**
 * SOLO LOCAL. Prueba de «Actualizar contra el padrón».
 *
 * Lo que hay que demostrar no es que corra, sino que NO se lleve por delante lo
 * que alguien capturó a mano. Se captura, se cambia el padrón por debajo, se
 * actualiza, y se comprueba que los conceptos siguen ahí.
 */
$RAIZ = dirname(__DIR__, 2);
set_error_handler(fn($n, $s) => str_contains($s, 'already defined'));
require $RAIZ . '/app/bootstrap.php';
restore_error_handler();

/* ---------------------------------------------------------------------------
 *  SEGURO. A diferencia de probar_nomina.php —que solo llama a una función
 *  pura y se puede correr en cualquier sitio—, esta prueba ESCRIBE: crea una
 *  nómina, da de baja a alguien y le sube el sueldo a otro. Lo deshace todo al
 *  terminar, pero un fallo a media faena dejaría el padrón tocado.
 *
 *  Por eso no corre contra producción ni por accidente.
 * ------------------------------------------------------------------------ */
if (defined('APP_ENV') && APP_ENV === 'production') {
    fwrite(STDERR, "Esta prueba ESCRIBE en la base y no se corre contra producción.\n");
    exit(2);
}
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos.');
}

$ok = true;
function paso(string $t, bool $c, string $d = ''): void {
    global $ok;
    echo ($c ? '  ✓ ' : '  ✗ ') . $t . ($d ? "\n        $d" : '') . "\n";
    if (!$c) $ok = false;
}

$u = qOne("SELECT * FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
$_SESSION['user'] = $u; $_SESSION['user']['es_super'] = 1;
$_SESSION['csrf'] = bin2hex(random_bytes(16));

/* ---------- Escenario ---------- */
q("DELETE nd FROM nomina_detalles nd JOIN nominas n ON n.id = nd.nomina_id WHERE n.descripcion LIKE 'PRUEBA REGEN%'");
q("DELETE FROM nominas WHERE descripcion LIKE 'PRUEBA REGEN%'");

$DESDE = '2026-08-01'; $HASTA = '2026-08-15';
$nid = dbInsert('nominas', ['descripcion' => 'PRUEBA REGEN', 'tipo' => 'quincenal',
    'fecha_desde' => $DESDE, 'fecha_hasta' => $HASTA, 'estado' => 'borrador', 'usuario_id' => (int) $u['id']]);
$diasBase = nominaDiasBase('quincenal');

$emps = qAll("SELECT * FROM empleados WHERE fecha_ingreso <= ? AND (fecha_salida IS NULL OR fecha_salida >= ?)
                AND (estado = 'activo' OR fecha_salida IS NOT NULL) ORDER BY id", [$HASTA, $DESDE]);
foreach ($emps as $e) {
    $c = calcNominaRD((float) $e['salario'], ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase], 0.5);
    dbInsert('nomina_detalles', ['nomina_id' => $nid, 'empleado_id' => (int) $e['id'],
        'salario_base' => $c['salarioPeriodo'], 'dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
        'total_ingresos' => $c['totalIngresos'], 'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'],
        'total_deducciones' => $c['totalDeducciones'], 'salario_neto' => $c['neto']]);
}
nominaRecalcularTotales($nid);
$antesN = count($emps);
echo "Nómina #$nid con $antesN filas.\n\n";

/* ---------- 1) Se captura a mano ---------- */
$capturado = qOne("SELECT nd.*, e.nombre, e.apellido FROM nomina_detalles nd
                     JOIN empleados e ON e.id = nd.empleado_id
                    WHERE nd.nomina_id = ? ORDER BY nd.id LIMIT 1", [$nid]);
dbUpdate('nomina_detalles', [
    'monto_horas_extra' => 1500.00, 'comisiones' => 2750.50, 'otras_deducciones' => 900.00,
    'per_capita' => 350.00, 'dias_trabajados' => 10.00,
], 'id = ?', [(int) $capturado['id']]);
echo "Capturado a mano en «" . trim($capturado['nombre'] . ' ' . $capturado['apellido']) . "»:\n";
echo "  horas extra 1,500.00 · comisiones 2,750.50 · préstamo 900.00 · per-cápita 350.00 · días 10\n\n";

/* ---------- 2) El padrón cambia por debajo ---------- */
$sale  = qAll("SELECT e.* FROM empleados e JOIN nomina_detalles nd ON nd.empleado_id = e.id
                WHERE nd.nomina_id = ? AND e.id <> ? ORDER BY e.id DESC LIMIT 1", [$nid, (int) $capturado['empleado_id']])[0];
$subeS = qAll("SELECT e.* FROM empleados e JOIN nomina_detalles nd ON nd.empleado_id = e.id
                WHERE nd.nomina_id = ? AND e.id NOT IN (?, ?) ORDER BY e.id DESC LIMIT 1",
                [$nid, (int) $capturado['empleado_id'], (int) $sale['id']])[0];
$salarioViejo = (float) $subeS['salario'];

// Uno se va antes del período: deja de corresponderle.
dbUpdate('empleados', ['fecha_salida' => '2026-07-20', 'estado' => 'inactivo'], 'id = ?', [(int) $sale['id']]);
// A otro le suben el sueldo.
dbUpdate('empleados', ['salario' => $salarioViejo + 10000], 'id = ?', [(int) $subeS['id']]);
// Y se quita una fila a mano, como si alguien la hubiera borrado: al actualizar debe volver.
$vuelve = qOne("SELECT nd.id, nd.empleado_id, e.nombre, e.apellido FROM nomina_detalles nd
                  JOIN empleados e ON e.id = nd.empleado_id
                 WHERE nd.nomina_id = ? AND nd.empleado_id NOT IN (?, ?, ?) ORDER BY nd.id DESC LIMIT 1",
                 [$nid, (int) $capturado['empleado_id'], (int) $sale['id'], (int) $subeS['id']]);
q("DELETE FROM nomina_detalles WHERE id = ?", [(int) $vuelve['id']]);

echo "Padrón cambiado por debajo:\n";
echo "  · sale       " . trim($sale['nombre'] . ' ' . $sale['apellido']) . " (fecha_salida 2026-07-20)\n";
echo "  · sube sueldo " . trim($subeS['nombre'] . ' ' . $subeS['apellido']) . " (" . number_format($salarioViejo, 2) . " → " . number_format($salarioViejo + 10000, 2) . ")\n";
echo "  · falta      " . trim($vuelve['nombre'] . ' ' . $vuelve['apellido']) . " (fila borrada a mano)\n\n";

/* ---------- 3) Se actualiza contra el padrón ---------- */
register_shutdown_function(function () use ($nid, $capturado, $sale, $subeS, $vuelve, $salarioViejo, $antesN) {
    echo "\n" . str_repeat('=', 74) . "\n  RESULTADO\n" . str_repeat('=', 74) . "\n";

    $d = qOne("SELECT * FROM nomina_detalles WHERE nomina_id = ? AND empleado_id = ?", [$nid, (int) $capturado['empleado_id']]);
    paso('la captura a mano sobrevive', $d
        && abs((float) $d['monto_horas_extra'] - 1500.00) < 0.01
        && abs((float) $d['comisiones'] - 2750.50) < 0.01
        && abs((float) $d['otras_deducciones'] - 900.00) < 0.01
        && abs((float) $d['per_capita'] - 350.00) < 0.01
        && abs((float) $d['dias_trabajados'] - 10.00) < 0.01,
        $d ? sprintf('he %s · com %s · prést %s · per-cáp %s · días %s',
            $d['monto_horas_extra'], $d['comisiones'], $d['otras_deducciones'], $d['per_capita'], $d['dias_trabajados']) : 'sin fila');

    paso('el que se fue antes del período queda fuera',
        !qVal("SELECT 1 FROM nomina_detalles WHERE nomina_id = ? AND empleado_id = ?", [$nid, (int) $sale['id']]));

    $v = qOne("SELECT * FROM nomina_detalles WHERE nomina_id = ? AND empleado_id = ?", [$nid, (int) $vuelve['empleado_id']]);
    paso('la fila borrada a mano vuelve', (bool) $v);

    $s = qOne("SELECT * FROM nomina_detalles WHERE nomina_id = ? AND empleado_id = ?", [$nid, (int) $subeS['id']]);
    paso('el sueldo se refresca del padrón', $s && abs((float) $s['salario_base'] - ($salarioViejo + 10000) / 2) < 0.02,
        $s ? 'salario_base = ' . $s['salario_base'] . ' (esperado ' . number_format(($salarioViejo + 10000) / 2, 2, '.', '') . ')' : 'sin fila');

    $n = qOne("SELECT * FROM nominas WHERE id = ?", [$nid]);
    $suma = qOne("SELECT COALESCE(SUM(total_ingresos),0) b, COALESCE(SUM(total_deducciones),0) d,
                         COALESCE(SUM(salario_neto),0) n, COUNT(*) c FROM nomina_detalles WHERE nomina_id = ?", [$nid]);
    paso('los totales de la cabecera cuadran con las líneas',
        abs((float) $n['total_bruto'] - (float) $suma['b']) < 0.01
        && abs((float) $n['total_neto'] - (float) $suma['n']) < 0.01,
        'cabecera neto ' . $n['total_neto'] . ' · suma ' . round((float) $suma['n'], 2));

    paso('la plantilla queda en ' . $suma['c'] . ' (antes ' . $antesN . ', uno se fue)',
        (int) $suma['c'] === $antesN - 1);

    foreach (($_SESSION['flash'] ?? []) as $f) {
        echo "\n  aviso: " . (is_array($f) ? ($f['mensaje'] ?? json_encode($f)) : $f) . "\n";
    }

    // Limpieza: esto es una prueba, no deja rastro.
    q("DELETE FROM nomina_detalles WHERE nomina_id = ?", [$nid]);
    q("DELETE FROM nominas WHERE id = ?", [$nid]);
    dbUpdate('empleados', ['fecha_salida' => null, 'estado' => 'activo'], 'id = ?', [(int) $sale['id']]);
    dbUpdate('empleados', ['salario' => $salarioViejo], 'id = ?', [(int) $subeS['id']]);

    global $ok;
    echo "\n  " . ($ok ? 'TODO BIEN' : 'HAY FALLOS') . "\n\n";
});

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_csrf' => $_SESSION['csrf'], 'accion' => 'regenerar', 'id' => $nid];
require $RAIZ . '/modules/rrhh/nomina.php';
