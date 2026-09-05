<?php
/**
 * El criterio con el que la pantalla propone a quién se parece cada persona.
 *
 *   php pruebas/ponche_criterio.php
 *
 * El criterio viejo era «la que más palabras comparta», y eligió mal:
 * «Martzabel Lora» fue a dar a «Soraya Lora Mercedes» cuando es «Maritzabel
 * Lora Piña». Compartían «Lora», que en ese padrón la tienen dos mujeres.
 *
 * El nuevo pesa la palabra por lo rara que sea: una que solo tiene una persona
 * decide, una que comparten cuatro no decide nada. Con él se emparejaron 32
 * personas de este cliente sin un solo error.
 *
 * Aquí se reproducen los casos REALES que se dieron —los aciertos y las
 * trampas— con un padrón inventado que tiene la misma forma.
 *
 * Devuelve 0 si todo pasa y 1 si algo falla.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$fallos = 0;
function ok(string $t, bool $c, string $x = ''): void {
    global $fallos; if (!$c) $fallos++;
    echo ($c ? '  OK    ' : '  FALLA ') . $t . (!$c && $x !== '' ? "  →  $x" : '') . "\n";
}

/* ---------------------------------------------------------------------------
 *  El criterio, aislado de la pantalla para poder probarlo.
 *
 *  Es el mismo código que corre en modules/rrhh/ponche.php. Vive aquí duplicado
 *  a propósito: si alguien cambia el de la pantalla sin cambiar este, la prueba
 *  falla y se entera. Un criterio que decide a quién se le carga un ponche no
 *  puede cambiarse sin que salte nada.
 * ------------------------------------------------------------------------ */
function pzz(string $s): array {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s = trim(preg_replace('~\s+~', ' ', preg_replace('~[^a-z0-9 ]~', ' ', $s)));
    return array_values(array_filter(explode(' ', $s), fn($p) => mb_strlen($p) > 1));
}
/** @return array{confianza:string, sugerido:?string, rivales:int} */
function juzgar(string $nombreReloj, array $padron): array {
    $frec = [];
    foreach ($padron as $n) foreach (array_unique(pzz($n)) as $p) $frec[$p] = ($frec[$p] ?? 0) + 1;

    $pr = pzz($nombreReloj);
    $cands = [];
    foreach ($padron as $n) {
        $com = array_values(array_intersect($pr, pzz($n)));
        if (!$com) continue;
        $excl = array_values(array_filter($com, fn($p) => ($frec[$p] ?? 9) === 1));
        $cands[] = ['nombre' => $n, 'com' => $com, 'excl' => $excl];
    }
    usort($cands, fn($x, $y) => [count($y['excl']), count($y['com'])] <=> [count($x['excl']), count($x['com'])]);

    $mejor = $cands[0] ?? null;
    $empate = $mejor ? count(array_filter($cands, fn($c) =>
        count($c['excl']) === count($mejor['excl']) && count($c['com']) === count($mejor['com']))) : 0;

    if ($mejor && count($mejor['com']) >= 2 && count($mejor['excl']) >= 1 && $empate === 1) {
        return ['confianza' => 'determinado', 'sugerido' => $mejor['nombre'], 'rivales' => count($cands)];
    }
    return ['confianza' => $mejor ? 'dudoso' : 'sin parecido',
            'sugerido' => $mejor['nombre'] ?? null, 'rivales' => count($cands)];
}

/* El padrón: los casos reales de este cliente, con las trampas incluidas. */
$padron = [
    'Maritzabel Lora Piña', 'Soraya Lora Mercedes',          // dos «Lora»: la trampa
    'Yilda Valdez', 'Yosmairi Mejía Valdez',                 // dos «Valdez»: la otra trampa
    'Yirda Mariel Jiménez', 'Ericka Cristina Marte Jiménez',
    'Estheribel Jiménez Fernández', 'Francia Jiménez Javalera',   // cuatro «Jiménez»
    'Nancy Mayuris Sosa', 'Ramón Alberto Ynfante Montero', 'José Ramón Vega Guzmán',
    'Nayeli Acosta', 'Denisse Scarlete Peguero', 'Catherin Geremil',
    'Manuel Alejandro Rodríguez', 'Jenny Maribel López Sánchez',
];

/* ============================================================
   1) Los que están determinados
   ============================================================ */
echo "\n=== DETERMINADOS: no hay a qué equivocarse ===\n";
foreach ([
    ['Yirda Jimenez',   'Yirda Mariel Jiménez',          'cuatro «Jiménez», una sola «Yirda»'],
    ['Nancy sosa',      'Nancy Mayuris Sosa',            'único candidato'],
    ['Ramon Ynfante',   'Ramón Alberto Ynfante Montero', 'dos «Ramón», un solo «Ynfante»'],
    ['Yilda Valdez',    'Yilda Valdez',                  'dos «Valdez», una sola «Yilda»'],
    ['Yosmairi Mejia',  'Yosmairi Mejía Valdez',         'y su vecina de apellido no la roba'],
] as [$reloj, $esperado, $porque]) {
    $j = juzgar($reloj, $padron);
    ok(sprintf('%-18s → %-32s (%s)', $reloj, mb_substr((string) $j['sugerido'], 0, 30), $porque),
        $j['confianza'] === 'determinado' && $j['sugerido'] === $esperado,
        $j['confianza'] . ' · propuso ' . ($j['sugerido'] ?? '—'));
}

/* ============================================================
   2) EL CASO QUE ROMPÍA EL CRITERIO VIEJO
   ============================================================ */
echo "\n=== LA TRAMPA QUE HIZO FALLAR AL ANTERIOR ===\n";
$j = juzgar('Martzabel Lora', $padron);
ok('«Martzabel Lora» NO se da por determinada', $j['confianza'] === 'dudoso',
    $j['confianza'] . ' · propuso ' . ($j['sugerido'] ?? '—'));
ok('y enseña que hay DOS candidatas con «Lora»', $j['rivales'] === 2, (string) $j['rivales']);

/* ============================================================
   3) Las demás dudosas: una sola palabra en común
   ============================================================ */
echo "\n=== DUDOSAS: se parecen, pero no lo bastante ===\n";
foreach ([
    ['Nayalis Acosta',    'solo comparten «acosta»'],
    ['Dennis Peguero',    'solo comparten «peguero»'],
    ['Catherin Geremi',   'solo comparten «catherin»'],
    ['Manuel Casado',     'solo comparten «manuel», y no es la misma persona'],
    ['Alveline Lopez',    'solo comparten «lopez»'],
    ['Eumy Esthefany Jimenez', 'cuatro «Jiménez» y ninguna «Eumy»'],
] as [$reloj, $porque]) {
    $j = juzgar($reloj, $padron);
    ok(sprintf('%-24s dudoso (%s)', $reloj, $porque), $j['confianza'] === 'dudoso',
        $j['confianza'] . ' · propuso ' . ($j['sugerido'] ?? '—'));
}

/* ============================================================
   4) Sin parecido
   ============================================================ */
echo "\n=== SIN PARECIDO ===\n";
foreach (['Admin', 'Niorkendy', 'Porfiria Cabrera'] as $reloj) {
    $j = juzgar($reloj, $padron);
    ok(sprintf('%-20s no propone a nadie', $reloj),
        $j['confianza'] === 'sin parecido' && $j['sugerido'] === null,
        $j['confianza'] . ' · ' . ($j['sugerido'] ?? '—'));
}

/* ============================================================
   5) El criterio de la pantalla es EL MISMO
   ============================================================ */
echo "\n=== LA PANTALLA USA ESTE CRITERIO ===\n";
$pantalla = file_get_contents(dirname(__DIR__) . '/modules/rrhh/ponche.php');
foreach ([
    '$frecuencia[$p] = ($frecuencia[$p] ?? 0) + 1;'                        => 'pesa las palabras por su frecuencia',
    "fn(\$p) => (\$frecuencia[\$p] ?? 9) === 1"                            => 'y considera exclusivas las de una sola persona',
    "count(\$mejor['com']) >= 2 && count(\$mejor['excl']) >= 1 && \$empate === 1" => 'y exige dos palabras, una exclusiva y ningún empate',
] as $trozo => $que) {
    ok('la pantalla ' . $que, str_contains($pantalla, $trozo),
        'si esto falla, alguien cambió el criterio de la pantalla y no el de aquí');
}

echo "\n" . ($fallos === 0 ? "  LA PALABRA RARA ES LA QUE DECIDE\n\n" : "  $fallos FALLO(S)\n\n");
exit($fallos === 0 ? 0 : 1);
