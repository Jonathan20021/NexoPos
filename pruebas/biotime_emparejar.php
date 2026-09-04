<?php
/**
 * Empareja a cada persona del reloj con la de Nexo. En dos pasos, a propósito.
 *
 *   php pruebas/biotime_emparejar.php --proponer
 *       Escribe storage/biotime_emparejamiento.csv con una propuesta por
 *       persona. NO toca la base.
 *
 *   php pruebas/biotime_emparejar.php --aplicar
 *       Lee ese CSV y guarda SOLO las filas que alguien marcó con «si».
 *
 * Por qué dos pasos y no uno: al probar el emparejamiento automático por
 * nombre, eligió mal. «Martzabel Lora» —así, escrito con erratas en el reloj—
 * fue a dar a «Soraya Lora Mercedes», cuando la persona es «Maritzabel Lora
 * Piña». Una equivocación aquí no es un dato feo: es el ponche de una persona
 * cargado a la nómina de otra, y nadie lo nota hasta que alguien reclama.
 *
 * Así que la máquina propone y una persona que las conoce decide. El CSV se
 * abre en Excel, se revisa la columna CONFIRMAR y se vuelve a guardar.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/includes/biotime.php';

$csv = dirname(__DIR__) . '/storage/biotime_emparejamiento.csv';
$modo = in_array('--aplicar', $_SERVER['argv'] ?? [], true) ? 'aplicar' : 'proponer';

/** Sin tildes, sin mayúsculas, sin dobles espacios. */
function bioNorm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                    'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u']);
    return trim(preg_replace('~\s+~', ' ', preg_replace('~[^a-z0-9 ]~', ' ', $s)));
}
/** Las palabras del nombre, para comparar sin depender del orden. */
function bioPartes(string $s): array {
    return array_values(array_filter(explode(' ', bioNorm($s)), fn($p) => mb_strlen($p) > 1));
}

/* ============================================================
   APLICAR
   ============================================================ */
if ($modo === 'aplicar') {
    if (!is_file($csv)) {
        fwrite(STDERR, "  No existe $csv.\n  Primero: php pruebas/biotime_emparejar.php --proponer\n");
        exit(1);
    }
    $fh = fopen($csv, 'r');
    fgetcsv($fh, 0, ';');   // cabecera
    $puestos = $saltados = 0; $problemas = [];

    while (($f = fgetcsv($fh, 0, ';')) !== false) {
        if (count($f) < 5) continue;
        [$code, $nombreReloj, $empId, $nombreNexo, $confirmar] = $f;
        $code = trim((string) $code);
        $empId = (int) $empId;

        if (mb_strtolower(trim((string) $confirmar)) !== 'si' || $code === '' || $empId <= 0) {
            $saltados++;
            continue;
        }
        $e = qOne("SELECT id, nombre, apellido FROM empleados WHERE id = ?", [$empId]);
        if (!$e) { $problemas[] = "$code → el empleado #$empId no existe"; continue; }

        // El índice único impide que dos personas compartan código, pero el
        // mensaje de la base no dice a quién le pasó. Se comprueba antes para
        // poder nombrarlo.
        $otro = qOne("SELECT id, nombre, apellido FROM empleados WHERE biotime_emp_code = ? AND id <> ?", [$code, $empId]);
        if ($otro) {
            $problemas[] = "$code ya está asignado a " . trim($otro['nombre'] . ' ' . $otro['apellido']);
            continue;
        }
        dbUpdate('empleados', ['biotime_emp_code' => $code], 'id = ?', [$empId]);
        $puestos++;
    }
    fclose($fh);

    printf("\n  emparejadas ... %d\n  sin confirmar . %d\n", $puestos, $saltados);
    if ($problemas) {
        echo "\n  PROBLEMAS:\n";
        foreach ($problemas as $p) echo "    $p\n";
    }
    $total = (int) qVal("SELECT COUNT(*) FROM empleados WHERE biotime_emp_code IS NOT NULL");
    printf("\n  En total hay %d persona(s) con código de reloj.\n\n", $total);
    exit($problemas ? 1 : 0);
}

/* ============================================================
   PROPONER
   ============================================================ */
if (!bioConfigurado()) {
    fwrite(STDERR, "  El reloj no está configurado: falta " . implode(' y ', bioFaltantes()) . ".\n");
    exit(1);
}
$reloj = bioEmpleados();
if (!$reloj['ok']) { fwrite(STDERR, "  No se pudo leer el padrón: {$reloj['error']}\n"); exit(1); }

$nexo = qAll("SELECT id, nombre, apellido, cedula, codigo FROM empleados WHERE estado <> 'inactivo' ORDER BY nombre");
$yaPuestos = [];
foreach ($nexo as $e) {
    $c = qVal("SELECT biotime_emp_code FROM empleados WHERE id = ?", [$e['id']]);
    if ($c) $yaPuestos[(string) $c] = (int) $e['id'];
}

@mkdir(dirname($csv), 0775, true);
$fh = fopen($csv, 'w');
fwrite($fh, "\xEF\xBB\xBF");   // BOM: si no, Excel se come las tildes
fputcsv($fh, ['CODIGO_RELOJ', 'NOMBRE_EN_EL_RELOJ', 'EMPLEADO_ID', 'NOMBRE_EN_NEXO', 'CONFIRMAR', 'QUE_TAN_SEGURO'], ';');

$seguras = $dudosas = $sinNada = 0;
foreach ($reloj['filas'] as $r) {
    $code = trim((string) ($r['emp_code'] ?? ''));
    if ($code === '') continue;
    $nom = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $pr  = bioPartes($nom);

    $mejor = null; $comunes = 0;
    foreach ($nexo as $e) {
        $n = count(array_intersect($pr, bioPartes($e['nombre'] . ' ' . $e['apellido'])));
        if ($n > $comunes) { $comunes = $n; $mejor = $e; }
    }

    // «si» solo se pone solo cuando ya estaba emparejado antes: en todo lo
    // demás lo escribe una persona. Dos apellidos que coinciden es una pista,
    // no una prueba: «Lora» y «Lora» son dos mujeres distintas en este padrón.
    $confirmar = (isset($yaPuestos[$code]) && $mejor && $yaPuestos[$code] === (int) $mejor['id']) ? 'si' : '';
    $nivel = $comunes >= 2 ? 'probable' : ($comunes === 1 ? 'DUDOSA, mirar' : 'sin parecido');
    if ($comunes >= 2) $seguras++; elseif ($comunes === 1) $dudosas++; else { $sinNada++; $mejor = null; }

    fputcsv($fh, [$code, $nom, $mejor['id'] ?? '', $mejor ? trim($mejor['nombre'] . ' ' . $mejor['apellido']) : '',
                  $confirmar, $nivel], ';');
}
fclose($fh);

printf("\n  %d persona(s) del reloj → %s\n\n", count($reloj['filas']), $csv);
printf("    con dos apellidos que coinciden .. %d  (probable, pero hay que mirarlo)\n", $seguras);
printf("    con una sola palabra en común .... %d  (DUDOSAS)\n", $dudosas);
printf("    sin ningún parecido .............. %d\n", $sinNada);

$faltan = (int) qVal("SELECT COUNT(*) FROM empleados WHERE estado <> 'inactivo'") - count($reloj['filas']);
if ($faltan > 0) printf("\n    Además, %d persona(s) de Nexo no están dadas de alta en el reloj.\n", $faltan);

echo "\n  Ábrelo en Excel, revisa fila por fila y escribe «si» en CONFIRMAR solo\n";
echo "  donde estés seguro. Lo que no lleve «si» no se guarda. Después:\n\n";
echo "      php pruebas/biotime_emparejar.php --aplicar\n\n";
echo "  Ninguna propuesta se aplica sola: el emparejamiento automático por\n";
echo "  nombre ya eligió mal al probarlo, y el ponche de una persona en la\n";
echo "  nómina de otra no se nota hasta que alguien reclama.\n\n";
