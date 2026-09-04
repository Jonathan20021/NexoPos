<?php
/**
 * La pantalla del reloj: emparejar sin equivocarse de persona.
 *
 *   php pruebas/biotime_pantalla.php
 *
 * Lo que se comprueba aquí es lo que pasa al PULSAR GUARDAR, que es donde una
 * equivocación se convierte en el ponche de alguien cargado a la nómina de otro.
 *
 * Las pantallas llaman a `redirect()`, que corta la ejecución, así que cada
 * acción tiene que correr en OTRO proceso. Este archivo se invoca a sí mismo
 * con `--como` para hacer de arnés: así no depende de nada externo y funciona
 * en cualquier máquina que tenga el proyecto.
 *
 * Devuelve 0 si todo pasa y 1 si algo falla.
 */

/* =====================================================================
 *  Modo arnés: ejecuta una acción POST como un usuario concreto.
 * ================================================================== */
if (($argv[1] ?? '') === '--como') {
    define('RESEND_API_KEY', '');   // nunca correo de verdad desde una prueba
    $usuario = $argv[2] ?? '';
    $pagina  = $argv[3] ?? '';
    // Los datos llegan en base64. En Windows, escapeshellarg() BORRA los «%» de
    // los argumentos —para que el shell no expanda variables— y eso destroza un
    // `empleado%5B901%5D=5`: llegaba sin corchetes y no se guardaba nada.
    parse_str(base64_decode($argv[4] ?? '', true) ?: '', $_POST);
    $_GET = []; $_REQUEST = $_POST;

    $raiz = dirname(__DIR__);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SCRIPT_NAME']    = '/' . $pagina;
    $_SERVER['REQUEST_URI']    = $_SERVER['SCRIPT_NAME'];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['DOCUMENT_ROOT']  = $raiz;

    ob_start();
    require_once $raiz . '/app/bootstrap.php';
    $u = qOne("SELECT u.*, r.nombre AS rol_nombre, r.es_super
                 FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.usuario = ?", [$usuario]);
    if (!$u) { ob_end_clean(); fwrite(STDERR, "  [ERROR] el usuario $usuario no existe\n"); exit(2); }

    $_SESSION['user'] = [
        'id' => (int) $u['id'], 'nombre' => $u['nombre'], 'apellido' => $u['apellido'],
        'email' => $u['email'], 'rol_nombre' => $u['rol_nombre'], 'es_super' => (int) $u['es_super'],
        'sucursal_id' => $u['sucursal_id'] === null ? null : (int) $u['sucursal_id'],
        'sucursal_nombre' => 'X',
    ];
    $_SESSION['permisos'] = load_permisos((int) $u['rol_id']);
    $_SESSION['csrf'] = 'p'; $_POST['_csrf'] = 'p';
    if ($u['sucursal_id'] !== null) $_SESSION['sucursal_id'] = (int) $u['sucursal_id'];
    ob_end_clean();

    // Los avisos se imprimen al morir el proceso: `redirect()` sale antes.
    register_shutdown_function(function () {
        foreach ($_SESSION['flash'] ?? [] as $f) {
            echo '  [' . strtoupper($f['tipo'] ?? '?') . '] ' . ($f['mensaje'] ?? '') . "\n";
        }
    });
    ob_start(); require $raiz . '/' . $pagina; ob_end_clean();
    exit(0);
}

/* =====================================================================
 *  Las pruebas
 * ================================================================== */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/includes/biotime.php';

$fallos = 0;
function ok(string $t, bool $c, string $x = ''): void {
    global $fallos; if (!$c) $fallos++;
    echo ($c ? '  OK    ' : '  FALLA ') . $t . (!$c && $x !== '' ? "  →  $x" : '') . "\n";
}
function comoUsuario(string $usuario, string $pagina, array $post): array
{
    $php = PHP_BINARY;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' --como '
         . escapeshellarg($usuario) . ' ' . escapeshellarg($pagina) . ' '
         . escapeshellarg(base64_encode(http_build_query($post))) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    preg_match_all('~\[(SUCCESS|ERROR|WARNING|INFO)\]\s*(.*)~', $out, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [strtoupper($x[1]), trim($x[2])], $m);
}
function tuvo(array $f, string $tipo, string $trozo = ''): bool {
    foreach ($f as [$t, $m]) if ($t === strtoupper($tipo) && ($trozo === '' || mb_stripos($m, $trozo) !== false)) return true;
    return false;
}
function pinta(array $f): string {
    return implode(' | ', array_map(fn($x) => $x[0] . ': ' . mb_substr($x[1], 0, 80), $f)) ?: '(sin mensaje)';
}

/* ---------- Quién hace de quién ----------
 *
 *  Los usuarios se BUSCAN por lo que pueden hacer, no por su nombre: cada base
 *  —la de demostración, la réplica del cliente— tiene los suyos, y un banco que
 *  fije «jsandoval» solo corre en una máquina.
 */
$puede = qOne("SELECT u.usuario FROM usuarios u JOIN roles r ON r.id = u.rol_id
                WHERE u.activo = 1 AND r.es_super = 1 LIMIT 1");
$noPuede = qOne("SELECT u.usuario FROM usuarios u JOIN roles r ON r.id = u.rol_id
                  WHERE u.activo = 1 AND r.es_super = 0
                    AND NOT EXISTS (SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id
                                     WHERE rp.rol_id = u.rol_id AND p.clave = 'rrhh_asistencia.registrar')
                  LIMIT 1");
if (!$puede) { fwrite(STDERR, "  Hace falta un usuario super administrador activo.
"); exit(1); }
$UPUEDE   = $puede['usuario'];
$UNOPUEDE = $noPuede['usuario'] ?? null;
printf("  con permiso: %s   ·   sin permiso: %s
", $UPUEDE, $UNOPUEDE ?? '(ninguno en esta base)');

/* ---------- Montaje ---------- */
$emps = qAll("SELECT id, nombre, apellido FROM empleados WHERE estado='activo' ORDER BY id LIMIT 3");
if (count($emps) < 3) { fwrite(STDERR, "  Hacen falta 3 empleados activos.\n"); exit(1); }
[$a, $b, $c] = $emps;
$guardado = qAll("SELECT id, biotime_emp_code FROM empleados WHERE biotime_emp_code IS NOT NULL");
q("UPDATE empleados SET biotime_emp_code = NULL");
$P = 'modules/rrhh/ponche.php';

/* ============================================================
   1) Emparejar
   ============================================================ */
echo "\n=== EMPAREJAR ===\n";
$f = comoUsuario($UPUEDE, $P, ['accion' => 'emparejar',
    'empleado' => ['901' => $a['id'], '902' => $b['id']]]);
ok('guarda las equivalencias marcadas',
    (string) qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$a['id']]) === '901'
    && (string) qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$b['id']]) === '902',
    pinta($f));

comoUsuario($UPUEDE, $P, ['accion' => 'emparejar', 'empleado' => ['901' => $a['id'], '903' => 0]]);
ok('lo que queda en «sin asignar» no inventa una equivalencia',
    qVal("SELECT id FROM empleados WHERE biotime_emp_code = '903'") === null);

comoUsuario($UPUEDE, $P, ['accion' => 'emparejar', 'empleado' => ['902' => 0]]);
ok('poner «sin asignar» quita la equivalencia que había',
    qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$b['id']]) === null);

/* ============================================================
   2) EL CASO QUE ROMPE: mover un código de una persona a otra
   ============================================================ */
echo "\n=== CAMBIAR DE PERSONA UN CÓDIGO ===\n";
// El índice único impide que dos tengan el mismo. Si al reasignar no se libera
// antes el anterior, la base rechaza y el emparejamiento se queda a medias.
$f = comoUsuario($UPUEDE, $P, ['accion' => 'emparejar', 'empleado' => ['901' => $c['id']]]);
ok('el código pasa a la persona nueva',
    (string) qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$c['id']]) === '901', pinta($f));
ok('y se le quita a la anterior, sin chocar con el índice único',
    qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$a['id']]) === null);
ok('nadie queda con el código duplicado',
    (int) qVal("SELECT COUNT(*) FROM empleados WHERE biotime_emp_code='901'") === 1);

/* ============================================================
   3) Los permisos
   ============================================================ */
echo "\n=== LOS PERMISOS ===\n";
if ($UNOPUEDE === null) {
    echo "  (esta base no tiene ningún usuario SIN el permiso; se omite)
";
} else {
    $antes = (string) qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$c['id']]);
    comoUsuario($UNOPUEDE, $P, ['accion' => 'emparejar', 'empleado' => ['901' => $b['id']]]);
    ok('quien no puede registrar asistencia NO puede emparejar',
        (string) qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$c['id']]) === $antes
        && qVal("SELECT biotime_emp_code FROM empleados WHERE id=?", [$b['id']]) === null);
}

/* ============================================================
   4) Traer desde la pantalla
   ============================================================ */
echo "\n=== TRAER DESDE LA PANTALLA ===\n";
$n0 = (int) qVal("SELECT COUNT(*) FROM asistencias");
$m0 = (int) qVal("SELECT COUNT(*) FROM asistencia_marcas");
$f = comoUsuario($UPUEDE, $P, ['accion' => 'traer', 'dias' => 7, 'modo' => 'simular']);
ok('«ver qué haría» no escribe NADA, ni asistencias ni marcas',
    (int) qVal("SELECT COUNT(*) FROM asistencias") === $n0
    && (int) qVal("SELECT COUNT(*) FROM asistencia_marcas") === $m0,
    'asistencias ' . $n0 . '→' . qVal("SELECT COUNT(*) FROM asistencias")
    . ' · marcas ' . $m0 . '→' . qVal("SELECT COUNT(*) FROM asistencia_marcas"));
ok('y contesta algo, sea el resultado o el motivo de no poder', $f !== [], pinta($f));

/* ============================================================
   5) Corregir a mano marca la fila como del humano
   ============================================================ */
echo "\n=== CORREGIR A MANO ===\n";
$hoy = date('Y-m-d');
q("DELETE FROM asistencias WHERE empleado_id=? AND fecha=?", [$c['id'], $hoy]);
dbInsert('asistencias', ['empleado_id' => (int) $c['id'], 'sucursal_id' => 1, 'fecha' => $hoy,
    'hora_entrada' => '08:00:00', 'hora_salida' => null, 'horas_trabajadas' => 0,
    'horas_extra' => 0, 'estado' => 'presente', 'origen' => 'biotime']);

// Corregir exige motivo: ya no se marca asistencia a mano, se enmienda un día.
comoUsuario($UPUEDE, 'modules/rrhh/asistencia.php', ['accion' => 'registrar',
    'empleado_id' => $c['id'], 'fecha' => $hoy, 'estado' => 'presente',
    'hora_entrada' => '08:00', 'hora_salida' => '17:00',
    'notas' => 'olvidó ponchar la salida']);
$fila = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha=?", [$c['id'], $hoy]);
ok('al corregir un día del reloj, la fila pasa a ser del humano',
    $fila && $fila['origen'] === 'manual' && $fila['hora_salida'] === '17:00:00',
    $fila ? "{$fila['origen']} · salida {$fila['hora_salida']}" : 'no existe');

$p = bioSincronizar($hoy, $hoy, ['filas' => [[
    'emp_code' => '901', 'att_date' => date('d-m-Y'), 'first_punch' => '08:00',
    'last_punch' => '15:00', 'first_name' => 'X', 'last_name' => 'Y']]]);
$fila = qOne("SELECT * FROM asistencias WHERE empleado_id=? AND fecha=?", [$c['id'], $hoy]);
ok('y la siguiente pasada del reloj ya no se la pisa',
    $fila['hora_salida'] === '17:00:00' && count($p['respetadas_manual']) === 1,
    "salida {$fila['hora_salida']}, avisos " . count($p['respetadas_manual']));

/* ---------- Limpieza ---------- */
q("DELETE FROM asistencias WHERE empleado_id=? AND fecha=?", [$c['id'], $hoy]);
q("UPDATE empleados SET biotime_emp_code = NULL");
foreach ($guardado as $g) q("UPDATE empleados SET biotime_emp_code=? WHERE id=?", [$g['biotime_emp_code'], $g['id']]);

echo "\n" . ($fallos === 0 ? "  LA PANTALLA NO SE EQUIVOCA DE PERSONA\n\n" : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
