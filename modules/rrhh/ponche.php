<?php
/**
 * El reloj biométrico: emparejar personas y traer los ponches.
 *
 * El emparejamiento vivía en un CSV que había que abrir en Excel. Aquí se hace
 * donde se trabaja, con permisos y auditoría, y con la garantía de que ninguna
 * propuesta se guarda sola: cada una la marca una persona.
 *
 * Al probar el emparejamiento automático por nombre eligió mal —«Martzabel
 * Lora», escrito con erratas en el reloj, fue a dar a «Soraya Lora Mercedes»
 * cuando es «Maritzabel Lora Piña»—. Una equivocación aquí no es un dato feo:
 * es el ponche de una persona cargado a la nómina de otra, y no se nota hasta
 * que alguien reclama. Por eso la máquina propone y una persona decide.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/biotime.php';
require_perm('rrhh_asistencia.ver');

/** Sin tildes ni mayúsculas, para comparar nombres escritos de dos maneras. */
function ponNorm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return trim(preg_replace('~\s+~', ' ', preg_replace('~[^a-z0-9 ]~', ' ', $s)));
}
function ponPartes(string $s): array {
    return array_values(array_filter(explode(' ', ponNorm($s)), fn($p) => mb_strlen($p) > 1));
}

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    require_perm('rrhh_asistencia.registrar');
    $accion = post('accion');

    if ($accion === 'emparejar') {
        $pares = (array) ($_POST['empleado'] ?? []);   // [emp_code => empleado_id]
        $puestos = $quitados = 0; $choques = [];

        foreach ($pares as $code => $empId) {
            $code  = trim((string) $code);
            $empId = (int) $empId;
            if ($code === '') continue;

            $actual = (int) qVal("SELECT id FROM empleados WHERE biotime_emp_code = ?", [$code]);

            if ($empId === 0) {                       // «— sin asignar —»
                if ($actual) { dbUpdate('empleados', ['biotime_emp_code' => null], 'id = ?', [$actual]); $quitados++; }
                continue;
            }
            if ($actual === $empId) continue;         // ya estaba

            // El índice único ya lo impide, pero su error no dice a quién le
            // pasó. Se comprueba antes para poder nombrarlo.
            if ($actual && $actual !== $empId) {
                dbUpdate('empleados', ['biotime_emp_code' => null], 'id = ?', [$actual]);
            }
            $otro = qOne("SELECT nombre, apellido FROM empleados WHERE id = ? AND biotime_emp_code IS NOT NULL AND biotime_emp_code <> ?", [$empId, $code]);
            if ($otro) {
                $choques[] = trim($otro['nombre'] . ' ' . $otro['apellido']) . ' ya tenía otro código de reloj; se le cambió al ' . $code . '.';
            }
            dbUpdate('empleados', ['biotime_emp_code' => $code], 'id = ?', [$empId]);
            $puestos++;
        }
        // Las marcas guardadas se reasignan en el acto, hacia atrás. Sin esto, el
        // histórico de esa persona empezaría el día en que alguien la empareja— y
        // sus ponches anteriores seguirían figurando como de nadie.
        $reclamadas = bioReclamarMarcas();

        audit('rrhh_asistencia', 'emparejar', "Reloj: $puestos emparejada(s), $quitados quitada(s), $reclamadas marca(s) reasignada(s)");
        flash('success', "$puestos persona(s) emparejada(s)" . ($quitados ? ", $quitados sin asignar" : '') . '.'
            . ($reclamadas > 0 ? " Se les asignaron $reclamadas marca(s) ya registradas." : ''));
        foreach ($choques as $c) flash('warning', $c);
        redirect('modules/rrhh/ponche.php');
    }

    if ($accion === 'traer') {
        $dias  = max(1, min(90, postInt('dias', 3)));
        $hasta = date('Y-m-d');
        $desde = date('Y-m-d', strtotime("-" . ($dias - 1) . " days"));
        $simular = post('modo') === 'simular';

        $p = bioSincronizar($desde, $hasta, ['simular' => $simular]);
        if ($p['error']) {
            flash('error', 'No se pudo traer el ponche: ' . $p['error']);
        } else {
            $msg = ($simular ? 'Simulación: ' : '') . "{$p['creadas']} día(s) nuevo(s), "
                 . "{$p['actualizadas']} actualizado(s), {$p['sin_cambio']} sin cambio.";
            flash($p['creadas'] || $p['actualizadas'] ? 'success' : 'info', $msg);

            if ($p['sin_emparejar']) {
                flash('warning', count($p['sin_emparejar']) . ' persona(s) del reloj sin emparejar: sus marcas '
                    . 'no entraron en ningún sitio (' . implode(', ', array_slice($p['sin_emparejar'], 0, 6)) . ').');
            }
            if ($p['respetadas_manual']) {
                flash('warning', 'Se respetó lo corregido a mano en ' . count($p['respetadas_manual'])
                    . ' día(s), pero no coincide con el reloj: ' . implode(' · ', array_slice($p['respetadas_manual'], 0, 3)));
            }
            if ($p['incompletas']) {
                flash('info', count($p['incompletas']) . ' día(s) con una sola marca: falta la salida. '
                    . 'Se pueden completar en Asistencia.');
            }
            if ($p['inactivos']) {
                flash('warning', 'Siguen ponchando personas que ya no trabajan aquí: '
                    . implode(', ', $p['inactivos']) . '. Hay que darlas de baja en el reloj.');
            }
            if (!$simular) audit('rrhh_asistencia', 'ponche', "Ponche traído a mano $desde→$hasta: {$p['creadas']} nuevo(s)");
        }
        redirect('modules/rrhh/ponche.php');
    }
    redirect('modules/rrhh/ponche.php');
}

// ---------- Datos ----------
$configurado = bioConfigurado();
$reloj = ['ok' => false, 'filas' => [], 'error' => null];
if ($configurado) $reloj = bioEmpleados();

$nexo = qAll("SELECT id, nombre, apellido, cedula, biotime_emp_code, sucursal_id
                FROM empleados WHERE estado <> 'inactivo' ORDER BY nombre, apellido");
$porCodigo = [];
foreach ($nexo as $e) if ($e['biotime_emp_code'] !== null) $porCodigo[(string) $e['biotime_emp_code']] = (int) $e['id'];

/* ---------------------------------------------------------------------------
 *  A quién se PARECE cada persona del reloj
 *
 *  El criterio de antes era «la que más palabras comparta», y eligió mal:
 *  «Martzabel Lora» fue a dar a «Soraya Lora Mercedes» cuando es «Maritzabel
 *  Lora Piña». Compartían «Lora», que en este padrón la tienen dos mujeres, así
 *  que no discrimina nada.
 *
 *  El que se usa ahora pesa la palabra por lo RARA que sea. Una que solo tiene
 *  una persona en toda la nómina —«Yirda», «Ynfante», «Yosmairi»— decide; una
 *  que comparten cuatro no decide nada. Con esto se emparejaron 32 personas sin
 *  un solo error, y separó bien «Yilda Valdez» de «Yosmairi Mejía Valdez», que
 *  el criterio viejo confundía.
 *
 *  Un caso está DETERMINADO cuando coinciden dos palabras o más, al menos una
 *  es exclusiva de ese candidato, no hay un segundo igual de bueno, y está
 *  activo. Todo lo demás es dudoso, y entonces se enseñan TODOS los candidatos:
 *  es justo ahí donde se elige mal, y esconder al rival sería tender la trampa.
 *
 *  Aun estando determinado, NADA se asigna solo. La máquina propone.
 * ------------------------------------------------------------------------ */

// Cuántas personas de Nexo comparten cada palabra. Es lo que convierte un
// parecido en una prueba: «lora» la tienen dos, «yirda» una sola.
$frecuencia = [];
foreach ($nexo as $e) {
    foreach (array_unique(ponPartes($e['nombre'] . ' ' . $e['apellido'])) as $p) {
        $frecuencia[$p] = ($frecuencia[$p] ?? 0) + 1;
    }
}

$gente = [];
foreach ($reloj['filas'] as $r) {
    $code = trim((string) ($r['emp_code'] ?? ''));
    if ($code === '') continue;
    $nom = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $pr  = ponPartes($nom);

    $cands = [];
    foreach ($nexo as $e) {
        $com = array_values(array_intersect($pr, ponPartes($e['nombre'] . ' ' . $e['apellido'])));
        if (!$com) continue;
        $excl = array_values(array_filter($com, fn($p) => ($frecuencia[$p] ?? 9) === 1));
        $cands[] = ['id' => (int) $e['id'], 'nombre' => trim($e['nombre'] . ' ' . $e['apellido']),
                    'com' => $com, 'excl' => $excl];
    }
    // Primero por palabras exclusivas, después por cuántas coinciden en total.
    usort($cands, fn($x, $y) => [count($y['excl']), count($y['com'])] <=> [count($x['excl']), count($x['com'])]);

    $mejor = $cands[0] ?? null;
    $empate = $mejor ? count(array_filter($cands, fn($c) =>
        count($c['excl']) === count($mejor['excl']) && count($c['com']) === count($mejor['com']))) : 0;

    if ($mejor && count($mejor['com']) >= 2 && count($mejor['excl']) >= 1 && $empate === 1) {
        $confianza = 'determinado';
        $porque = 'coinciden ' . implode(' y ', $mejor['com'])
                . ', y «' . implode('», «', $mejor['excl']) . '» no la tiene nadie más';
    } elseif (!$mejor) {
        $confianza = 'sin parecido';
        $porque = 'ningún nombre de Nexo se le parece';
    } elseif ($empate > 1) {
        $confianza = 'dudoso';
        $porque = $empate . ' personas encajan igual de bien: hay a qué equivocarse';
    } elseif (count($mejor['excl']) === 0) {
        $confianza = 'dudoso';
        $porque = 'solo coinciden palabras que tienen varias personas';
    } else {
        $confianza = 'dudoso';
        $porque = 'coincide una sola palabra («' . implode('», «', $mejor['com']) . '»)';
    }

    $gente[] = [
        'code' => $code, 'nombre' => $nom ?: '(sin nombre)',
        'depto' => (string) ($r['department']['dept_name'] ?? $r['dept_name'] ?? ''),
        'asignado' => $porCodigo[$code] ?? 0,
        'sugerido' => $mejor['id'] ?? null,
        'confianza' => $confianza,
        'porque' => $porque,
        // Los rivales solo se enseñan cuando hay duda: en un caso determinado
        // serían ruido, y en uno dudoso son la información que falta.
        'rivales' => $confianza === 'dudoso' ? array_slice($cands, 0, 4) : [],
    ];
}

usort($gente, fn($a, $b) => [$a['asignado'] ? 1 : 0, $a['nombre']] <=> [$b['asignado'] ? 1 : 0, $b['nombre']]);

$emparejados = count(array_filter($gente, fn($g) => $g['asignado'] > 0));
$sinEmparejar = count($gente) - $emparejados;
$sinReloj = count($nexo) - $emparejados;

// Última vez que entró algo del reloj, y cuánto se usa.
$ultima = qVal("SELECT MAX(biotime_sync_at) FROM asistencias WHERE origen = 'biotime'");
$delReloj = (int) qVal("SELECT COUNT(*) FROM asistencias WHERE origen = 'biotime'");
$aMano    = (int) qVal("SELECT COUNT(*) FROM asistencias WHERE origen = 'manual'");

layout_start('Reloj biométrico', 'Emparejar a la gente y traer los ponches a Asistencia');
?>

<?php if (!$configurado): ?>
  <div class="card p-6 mb-6 border-l-4 border-amber-400">
    <h3 class="font-bold text-slate-800 mb-1"><?= icon('alert', 'w-5 h-5 inline -mt-1 text-amber-500') ?> El reloj no está configurado</h3>
    <p class="text-sm text-slate-600">Falta <strong><?= e(implode(' y ', bioFaltantes())) ?></strong> en <code>config/config.local.php</code>.
       Mientras tanto esta pantalla no puede hacer nada.</p>
  </div>
<?php elseif (!$reloj['ok']): ?>
  <div class="card p-6 mb-6 border-l-4 border-rose-400">
    <h3 class="font-bold text-slate-800 mb-1"><?= icon('alert', 'w-5 h-5 inline -mt-1 text-rose-500') ?> No se pudo leer el reloj</h3>
    <p class="text-sm text-slate-600"><?= e((string) $reloj['error']) ?></p>
  </div>
<?php else: ?>

<div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
  <?php foreach ([
    ['Emparejadas', $emparejados, 'users', 'emerald'],
    ['Sin emparejar', $sinEmparejar, 'alert', $sinEmparejar ? 'amber' : 'slate'],
    ['En Nexo sin reloj', max(0, $sinReloj), 'id', 'slate'],
    ['Días traídos', $delReloj, 'clock', 'blue'],
  ] as [$et, $n, $ic, $col]): ?>
    <div class="card px-5 py-4 flex items-center gap-4">
      <span class="w-11 h-11 rounded-xl bg-<?= $col ?>-100 text-<?= $col ?>-600 flex items-center justify-center shrink-0"><?= icon($ic, 'w-5 h-5') ?></span>
      <div><p class="text-xs text-slate-400"><?= e($et) ?></p><p class="text-xl font-bold text-slate-800"><?= number_format($n) ?></p></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if (can('rrhh_asistencia.registrar')): ?>
<div class="card p-5 mb-6">
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div>
      <h3 class="font-bold text-slate-800">Traer los ponches</h3>
      <p class="text-sm text-slate-500 mt-0.5">
        Entra a Asistencia lo que el reloj registró. No escribe ausencias —quien no ponchó
        no aparece como que no vino— y no pisa lo que alguien corrigió a mano.
        <?php if ($ultima): ?><br><span class="text-xs">Última vez: <?= e(fechaHora($ultima)) ?>.</span><?php endif; ?>
      </p>
    </div>
    <form method="post" class="flex items-end gap-2">
      <?= csrf_field() ?><input type="hidden" name="accion" value="traer">
      <div>
        <label class="label">Últimos</label>
        <select name="dias" class="input w-28">
          <?php foreach ([3 => '3 días', 7 => '7 días', 15 => '15 días', 31 => '31 días'] as $d => $t): ?>
            <option value="<?= $d ?>"><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button name="modo" value="simular" class="btn btn-ghost"><?= icon('eye', 'w-4 h-4') ?> Ver qué haría</button>
      <button name="modo" value="traer" class="btn btn-primary"><?= icon('download', 'w-4 h-4') ?> Traer</button>
    </form>
  </div>
</div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="accion" value="emparejar">
  <div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h3 class="font-bold text-slate-800">Quién es quién</h3>
        <p class="text-xs text-slate-500">
          El reloj identifica por un número que no significa nada en Nexo. La columna de la
          derecha viene <strong>vacía a propósito</strong>: la propuesta es una pista, no una prueba.
        </p>
      </div>
      <?php if (can('rrhh_asistencia.registrar')): ?>
        <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar el emparejamiento</button>
      <?php endif; ?>
    </div>

    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <?php /* El «parecido» lleva el porqué escrito y los rivales cuando los hay:
                 en 28 píxeles eso se partía en cinco líneas y no se leía. El
                 departamento, en cambio, es un dato de apoyo. */ ?>
        <th class="w-56">En el reloj</th><th class="w-40">Departamento</th>
        <th class="w-64">Parecido</th><th class="w-72">Es esta persona de Nexo</th>
        </tr></thead>
        <tbody>
          <?php foreach ($gente as $g): ?>
            <tr class="<?= $g['asignado'] ? '' : 'bg-amber-50/40' ?>">
              <td>
                <p class="font-semibold text-slate-700"><?= e($g['nombre']) ?></p>
                <p class="text-xs text-slate-400">código <?= e($g['code']) ?></p>
              </td>
              <td class="text-slate-500 text-sm"><?= e($g['depto'] ?: '—') ?></td>
              <td>
                <?php if ($g['asignado']): ?>
                  <span class="badge badge-emerald">emparejada</span>
                <?php elseif ($g['confianza'] === 'determinado'): ?>
                  <span class="badge badge-slate" title="<?= e($g['porque']) ?>">determinado</span>
                <?php elseif ($g['confianza'] === 'dudoso'): ?>
                  <span class="badge badge-amber" title="<?= e($g['porque']) ?>">dudoso</span>
                <?php else: ?>
                  <span class="text-xs text-slate-400">sin parecido</span>
                <?php endif; ?>
                <?php if (!$g['asignado']): ?>
                  <span class="block text-[11px] text-slate-400 leading-tight mt-0.5"><?= e($g['porque']) ?></span>
                <?php endif; ?>
                <?php /* En los dudosos se enseñan los rivales: esconder al otro
                         candidato sería tender la trampa justo donde se falla. */ ?>
                <?php if ($g['rivales'] && count($g['rivales']) > 1): ?>
                  <span class="block text-[11px] text-amber-700 leading-tight mt-1">
                    <?php foreach ($g['rivales'] as $rv): ?>
                      <span class="block">· <?= e($rv['nombre']) ?></span>
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <select name="empleado[<?= e($g['code']) ?>]" class="input w-full"
                        <?= can('rrhh_asistencia.registrar') ? '' : 'disabled' ?>>
                  <option value="0">— sin asignar —</option>
                  <?php foreach ($nexo as $e):
                    $sel = $g['asignado'] ? ($g['asignado'] === (int) $e['id']) : false; ?>
                    <option value="<?= (int) $e['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                      <?= e(trim($e['nombre'] . ' ' . $e['apellido'])) ?><?= $e['cedula'] ? ' · ' . e($e['cedula']) : '' ?><?= (!$g['asignado'] && $g['sugerido'] === (int) $e['id'])
                            ? ($g['confianza'] === 'determinado' ? '   ← es esta' : '   ← ¿será esta?') : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="px-5 py-3 text-xs text-slate-400 border-t border-slate-100">
      El parecido se mide por lo RARA que sea cada palabra: una que solo tiene una persona
      en toda la nómina decide, una que comparten cuatro no decide nada. «Determinado» quiere
      decir que no hay a qué equivocarse; «dudoso» enseña debajo a todos los que encajan.
      Aun así, <strong>nada se asigna solo</strong>: elige tú. Lo que quede en «sin asignar»
      simplemente no entra, y sus marcas se quedan en el reloj esperando.
    </p>
  </div>
</form>

<?php endif; ?>

<div class="card p-5 mt-6">
  <h3 class="font-bold text-slate-800 mb-2">Cómo queda la asistencia</h3>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm text-slate-600">
    <p><strong class="text-slate-800"><?= number_format($delReloj) ?></strong> día(s) los trajo el reloj.
       <strong class="text-slate-800"><?= number_format($aMano) ?></strong> los escribió una persona.</p>
    <p>Si alguien corrige un día en <a class="link" href="<?= e(url('modules/rrhh/asistencia.php')) ?>">Asistencia</a>,
       esa fila pasa a ser suya y el reloj ya no la toca: se avisa de la diferencia, pero manda el humano.</p>
  </div>
</div>

<?php layout_end(); ?>
