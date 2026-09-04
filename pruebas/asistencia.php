<?php
/**
 * La pantalla de asistencia: marcar sin borrar lo que ya estaba.
 *
 *   php pruebas/asistencia.php
 *
 * El botón «Presente» ponía las horas en nulo. Un clic sobre alguien cuyo
 * ponche ya había entrado —10:23 a 18:02, 7,65 h— le borraba la jornada, y como
 * la fila pasa a ser manual la sincronización siguiente ya no la reponía. En
 * lote eso mismo, multiplicado por 57.
 *
 * Se invoca a sí mismo con `--como` para hacer de arnés, porque las pantallas
 * llaman a `redirect()` y eso corta la ejecución.
 *
 * Devuelve 0 si todo pasa y 1 si algo falla.
 */

if (($argv[1] ?? '') === '--como') {
    define('RESEND_API_KEY', '');
    $usuario = $argv[2] ?? '';
    $pagina  = $argv[3] ?? '';
    // base64: en Windows escapeshellarg() borra los «%» y destroza un empleado_id[].
    parse_str(base64_decode($argv[4] ?? '', true) ?: '', $_POST);
    $_GET = []; $_REQUEST = $_POST;

    $raiz = dirname(__DIR__);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SCRIPT_NAME'] = '/' . $pagina; $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['DOCUMENT_ROOT'] = $raiz;

    ob_start();
    require_once $raiz . '/app/bootstrap.php';
    $u = qOne("SELECT u.*, r.nombre AS rol_nombre, r.es_super
                 FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.usuario = ?", [$usuario]);
    if (!$u) { ob_end_clean(); fwrite(STDERR, "  [ERROR] el usuario $usuario no existe\n"); exit(2); }
    $_SESSION['user'] = [
        'id' => (int) $u['id'], 'nombre' => $u['nombre'], 'apellido' => $u['apellido'],
        'email' => $u['email'], 'rol_nombre' => $u['rol_nombre'], 'es_super' => (int) $u['es_super'],
        'sucursal_id' => $u['sucursal_id'] === null ? null : (int) $u['sucursal_id'], 'sucursal_nombre' => 'X',
    ];
    $_SESSION['permisos'] = load_permisos((int) $u['rol_id']);
    $_SESSION['csrf'] = 'p'; $_POST['_csrf'] = 'p';
    if ($u['sucursal_id'] !== null) $_SESSION['sucursal_id'] = (int) $u['sucursal_id'];
    ob_end_clean();
    register_shutdown_function(function () {
        foreach ($_SESSION['flash'] ?? [] as $f) {
            echo '  [' . strtoupper($f['tipo'] ?? '?') . '] ' . ($f['mensaje'] ?? '') . "\n";
        }
    });
    ob_start(); require $raiz . '/' . $pagina; ob_end_clean();
    exit(0);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$fallos = 0;
function ok(string $t, bool $c, string $x = ''): void {
    global $fallos; if (!$c) $fallos++;
    echo ($c ? '  OK    ' : '  FALLA ') . $t . (!$c && $x !== '' ? "  →  $x" : '') . "\n";
}
function comoUsuario(string $usuario, array $post): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --como '
         . escapeshellarg($usuario) . ' ' . escapeshellarg('modules/rrhh/asistencia.php') . ' '
         . escapeshellarg(base64_encode(http_build_query($post))) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    preg_match_all('~\[(SUCCESS|ERROR|WARNING|INFO)\]\s*(.*)~', $out, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [strtoupper($x[1]), trim($x[2])], $m);
}
function pinta(array $f): string {
    return implode(' | ', array_map(fn($x) => $x[0] . ': ' . mb_substr($x[1], 0, 90), $f)) ?: '(sin mensaje)';
}

/* ---------- Quién hace de quién ---------- */
$puede = qOne("SELECT u.usuario FROM usuarios u JOIN roles r ON r.id = u.rol_id
                WHERE u.activo = 1 AND r.es_super = 1 LIMIT 1");
if (!$puede) { fwrite(STDERR, "  Hace falta un super administrador activo.\n"); exit(1); }
$U = $puede['usuario'];
$noPuede = qOne("SELECT u.usuario FROM usuarios u JOIN roles r ON r.id = u.rol_id
                  WHERE u.activo = 1 AND r.es_super = 0
                    AND NOT EXISTS (SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id
                                     WHERE rp.rol_id = u.rol_id AND p.clave = 'rrhh_asistencia.registrar')
                  LIMIT 1");

$emps = qAll("SELECT id, nombre, apellido FROM empleados WHERE estado='activo' ORDER BY id LIMIT 4");
if (count($emps) < 4) { fwrite(STDERR, "  Hacen falta 4 empleados activos.\n"); exit(1); }
$F = '2026-06-15';
$ids = array_map(fn($e) => (int) $e['id'], $emps);
q("DELETE FROM asistencias WHERE fecha = ?", [$F]);

/* ============================================================
   1) EL FALLO QUE COSTABA HORAS
   ============================================================ */
echo "\n=== MARCAR NO BORRA LO QUE YA HABÍA ===\n";
dbInsert('asistencias', ['empleado_id' => $ids[0], 'sucursal_id' => 1, 'fecha' => $F,
    'hora_entrada' => '10:23:00', 'hora_salida' => '18:02:00', 'horas_trabajadas' => 7.65,
    'horas_extra' => 0, 'estado' => 'presente', 'origen' => 'biotime']);

comoUsuario($U, ['accion' => 'marcar', 'empleado_id' => $ids[0], 'fecha' => $F, 'estado' => 'tardanza']);
$a = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha=?", [$ids[0], $F]);
ok('el marcado rápido conserva las horas del reloj',
    $a && $a['hora_entrada'] === '10:23:00' && $a['hora_salida'] === '18:02:00'
       && abs((float) $a['horas_trabajadas'] - 7.65) < 0.01,
    $a ? "{$a['hora_entrada']}–{$a['hora_salida']} = {$a['horas_trabajadas']} h" : 'no existe');
ok('y sí cambia el estado', $a && $a['estado'] === 'tardanza', (string) ($a['estado'] ?? '—'));
ok('y la fila pasa a ser del humano', $a && $a['origen'] === 'manual', (string) ($a['origen'] ?? '—'));

/* ============================================================
   2) En lote
   ============================================================ */
echo "\n=== MARCAR A VARIOS DE UNA VEZ ===\n";
$f = comoUsuario($U, ['accion' => 'marcar_lote', 'fecha' => $F, 'estado' => 'presente',
                      'empleado_id' => [$ids[1], $ids[2], $ids[3]]]);
$n = (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=? AND estado='presente'", [$F]);
ok('marca a los tres en una sola petición', $n === 3, "$n de 3 · " . pinta($f));

/* --- y tampoco borra horas --- */
$f = comoUsuario($U, ['accion' => 'marcar_lote', 'fecha' => $F, 'estado' => 'presente',
                      'empleado_id' => [$ids[0]]]);
$a = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha=?", [$ids[0], $F]);
ok('EN LOTE tampoco borra las horas',
    $a && $a['hora_entrada'] === '10:23:00' && $a['hora_salida'] === '18:02:00',
    $a ? "{$a['hora_entrada']}–{$a['hora_salida']}" : 'no existe');

/* --- repetirlo no duplica --- */
$antes = (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$F]);
comoUsuario($U, ['accion' => 'marcar_lote', 'fecha' => $F, 'estado' => 'ausente', 'empleado_id' => $ids]);
ok('repetirlo actualiza, no duplica',
    (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$F]) === $antes,
    $antes . ' → ' . qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$F]));
ok('y el estado nuevo se aplicó a todos',
    (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=? AND estado='ausente'", [$F]) === 4);

/* --- selección vacía --- */
$f = comoUsuario($U, ['accion' => 'marcar_lote', 'fecha' => $F, 'estado' => 'presente']);
ok('sin nadie seleccionado no hace nada y lo dice',
    (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=? AND estado='presente'", [$F]) === 0,
    pinta($f));

/* --- ids inventados --- */
comoUsuario($U, ['accion' => 'marcar_lote', 'fecha' => $F, 'estado' => 'presente',
                 'empleado_id' => [999999, 888888]]);
ok('un id que no existe no crea una fila fantasma',
    (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=? AND empleado_id IN (999999,888888)", [$F]) === 0);

/* --- fecha futura --- */
$manana = date('Y-m-d', strtotime('+1 day'));
comoUsuario($U, ['accion' => 'marcar_lote', 'fecha' => $manana, 'estado' => 'presente', 'empleado_id' => [$ids[0]]]);
ok('una fecha que no ha pasado se rechaza en el SERVIDOR, no solo en el selector',
    (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$manana]) === 0,
    qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$manana]) . ' fila(s) creadas');
comoUsuario($U, ['accion' => 'marcar', 'empleado_id' => $ids[0], 'fecha' => $manana, 'estado' => 'presente']);
ok('tampoco de una en una',
    (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$manana]) === 0);
q("DELETE FROM asistencias WHERE fecha = ?", [$manana]);

/* ============================================================
   3) Los permisos
   ============================================================ */
echo "\n=== LOS PERMISOS ===\n";
if ($noPuede === null) {
    echo "  (esta base no tiene ningún usuario SIN el permiso; se omite)\n";
} else {
    q("DELETE FROM asistencias WHERE fecha = ?", [$F]);
    comoUsuario($noPuede['usuario'], ['accion' => 'marcar_lote', 'fecha' => $F,
        'estado' => 'presente', 'empleado_id' => $ids]);
    ok('quien no puede registrar asistencia tampoco puede en lote',
        (int) qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$F]) === 0,
        qVal("SELECT COUNT(*) FROM asistencias WHERE fecha=?", [$F]) . ' fila(s) creadas');
}

/* ---------- Limpieza ---------- */
q("DELETE FROM asistencias WHERE fecha = ?", [$F]);

echo "\n" . ($fallos === 0 ? "  MARCAR NO PIERDE HORAS\n\n" : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
