<?php
/**
 * Horarios de trabajo: crearlos, asignarlos y recalcular lo ya traído.
 *
 * Sin horario el reloj solo sabe a qué hora llegó alguien; con él sabe si esa
 * hora era tarde. Por eso esta pantalla enseña arriba, y en rojo, cuánta gente
 * sigue sin horario asignado: mientras quede alguien ahí, su tardanza no existe
 * y nadie lo sabría.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/jornadas.php';
require_perm('rrhh_jornadas.ver');

$DIAS = [1, 2, 3, 4, 5, 6, 7];

if (isPost()) {
    verify_csrf();
    require_perm('rrhh_jornadas.gestionar');
    $accion = post('accion');

    /* ---------- Guardar un horario con sus siete días ---------- */
    if ($accion === 'guardar') {
        $id     = postInt('id');
        $nombre = trim(post('nombre'));

        if ($nombre === '') {
            flash('error', 'El horario necesita un nombre.');
            redirect('modules/rrhh/jornadas.php');
        }

        $datos = [
            'nombre'          => mb_substr($nombre, 0, 80),
            'tolerancia_min'  => max(0, min(120, postInt('tolerancia_min', 5))),
            'almuerzo_min'    => max(0, min(240, postInt('almuerzo_min', 60))),
            'extra_desde_min' => max(0, min(240, postInt('extra_desde_min', 30))),
            'activo'          => postInt('activo', 1),
            'notas'           => trim(post('notas')) ?: null,
        ];

        try {
            tx(function () use (&$id, $datos, $DIAS, $nombre) {
                if ($id > 0) {
                    dbUpdate('jornadas', $datos, 'id = ?', [$id]);
                } else {
                    $id = dbInsert('jornadas', $datos);
                }
                // Los días se reescriben enteros: es más simple y no deja
                // filas huérfanas de un día que se desmarcó.
                q("DELETE FROM jornada_dias WHERE jornada_id = ?", [$id]);
                foreach ($DIAS as $d) {
                    $labora  = postInt("labora_$d") === 1;
                    $entrada = trim(post("entrada_$d"));
                    $salida  = trim(post("salida_$d"));
                    // Un día marcado como laborable sin horas no se puede
                    // aplicar: se guarda como no laborable en vez de dejar una
                    // fila que luego daría tardanzas contra NULL.
                    if ($labora && ($entrada === '' || $salida === '')) $labora = false;
                    dbInsert('jornada_dias', [
                        'jornada_id'   => $id,
                        'dia_semana'   => $d,
                        'labora'       => $labora ? 1 : 0,
                        'hora_entrada' => $labora ? $entrada : null,
                        'hora_salida'  => $labora ? $salida  : null,
                    ]);
                }
            });
            audit('rrhh_jornadas', 'gestionar', "Horario guardado: $nombre",
                  ['tabla' => 'jornadas', 'registro_id' => $id]);
            flash('success', 'Horario guardado. Recalcula la asistencia para aplicarlo a lo ya traído.');
        } catch (Throwable $ex) {
            flash('error', 'No se pudo guardar: ' . $ex->getMessage());
        }
        redirect('modules/rrhh/jornadas.php');
    }

    /* ---------- Eliminar ---------- */
    if ($accion === 'eliminar') {
        $id = postInt('id');
        $j  = qOne("SELECT nombre FROM jornadas WHERE id = ?", [$id]);
        $n  = (int) qVal("SELECT COUNT(*) FROM empleados WHERE jornada_id = ?", [$id]);
        if (!$j) {
            flash('error', 'Ese horario ya no existe.');
        } elseif ($n > 0) {
            // Borrarlo pondría a NULL la jornada de esa gente por la clave
            // foránea, y su tardanza desaparecería sin que nadie lo pidiera.
            flash('error', "No se puede eliminar: $n persona(s) lo tienen asignado. Cámbiales el horario primero.");
        } else {
            q("DELETE FROM jornadas WHERE id = ?", [$id]);
            audit('rrhh_jornadas', 'gestionar', "Horario eliminado: {$j['nombre']}",
                  ['tabla' => 'jornadas', 'registro_id' => $id]);
            flash('success', 'Horario eliminado.');
        }
        redirect('modules/rrhh/jornadas.php');
    }

    /* ---------- Asignar a varias personas de una vez ---------- */
    if ($accion === 'asignar') {
        $jid  = postInt('jornada_id') ?: null;
        $ids  = array_filter(array_map('intval', (array) post('empleados', [])));
        if (!$ids) {
            flash('error', 'No seleccionaste a nadie.');
            redirect('modules/rrhh/jornadas.php?tab=asignar');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        q("UPDATE empleados SET jornada_id = ? WHERE id IN ($in)", array_merge([$jid], $ids));

        $comoSeLlama = $jid ? (string) qVal("SELECT nombre FROM jornadas WHERE id = ?", [$jid]) : 'sin horario';
        audit('rrhh_jornadas', 'gestionar',
              count($ids) . " empleado(s) asignados a: $comoSeLlama");
        flash('success', count($ids) . ' persona(s) asignadas a ' . $comoSeLlama
            . '. Recalcula la asistencia para aplicarlo a lo ya traído.');
        redirect('modules/rrhh/jornadas.php?tab=asignar');
    }

    /* ---------- Recalcular la asistencia ya guardada ----------
     *
     * El horario casi siempre se asigna DESPUÉS de que el reloj ya trajo
     * semanas de marcas. Sin esto, la tardanza solo existiría de hoy en
     * adelante y todo lo anterior se quedaría en «presente» para siempre.
     */
    if ($accion === 'recalcular') {
        $desde = post('desde') ?: date('Y-m-01', strtotime('-1 month'));
        $hasta = post('hasta') ?: date('Y-m-d');
        $r = jorRecalcular($desde, $hasta, null, post('simular') === '1');

        $msg = "Revisados {$r['revisadas']} día(s): {$r['cambiadas']} cambiado(s)"
             . ($r['respetadas_manual'] ? ", {$r['respetadas_manual']} respetado(s) por ser corrección a mano" : '')
             . ($r['sin_jornada'] ? ", {$r['sin_jornada']} sin horario asignado" : '') . '.';
        if (post('simular') === '1') $msg = 'SIMULACIÓN — ' . $msg . ' No se escribió nada.';
        else audit('rrhh_jornadas', 'gestionar', "Asistencia recalculada $desde→$hasta: {$r['cambiadas']} cambio(s)");

        flash($r['cambiadas'] > 0 || post('simular') === '1' ? 'success' : 'info', $msg);
        $_SESSION['jor_detalle'] = array_slice($r['detalle'], 0, 40);
        redirect('modules/rrhh/jornadas.php');
    }
}

/* ---------- Datos ---------- */
$jornadas = qAll("SELECT j.*, (SELECT COUNT(*) FROM empleados e WHERE e.jornada_id = j.id
                                 AND e.estado <> 'inactivo') AS gente
                    FROM jornadas j ORDER BY j.activo DESC, j.nombre");
foreach ($jornadas as &$j) {
    $j['dias'] = [];
    foreach (qAll("SELECT * FROM jornada_dias WHERE jornada_id = ? ORDER BY dia_semana", [(int) $j['id']]) as $d) {
        $j['dias'][(int) $d['dia_semana']] = $d;
    }
}
unset($j);

$sinJornada = jorSinJornada();
$activos    = (int) qVal("SELECT COUNT(*) FROM empleados WHERE estado <> 'inactivo'");
$feriadosAnio = (int) qVal("SELECT COUNT(*) FROM feriados WHERE YEAR(fecha) = ?", [date('Y')]);

$plantilla = qAll("SELECT e.id, e.nombre, e.apellido, e.jornada_id, e.biotime_emp_code,
                          j.nombre AS jornada, d.nombre AS departamento
                     FROM empleados e
                     LEFT JOIN jornadas j ON j.id = e.jornada_id
                     LEFT JOIN departamentos d ON d.id = e.departamento_id
                    WHERE e.estado <> 'inactivo'
                    ORDER BY (e.jornada_id IS NOT NULL), d.nombre, e.nombre");

$detalle = $_SESSION['jor_detalle'] ?? [];
unset($_SESSION['jor_detalle']);
$tab = get('tab') === 'asignar' ? 'asignar' : 'horarios';
$puedeGestionar = can('rrhh_jornadas.gestionar');

layout_start('Horarios de trabajo', 'Sin horario no hay tardanza: el reloj solo sabe a qué hora llegaron');
?>

<?= kpis([
    ['label' => 'Horarios activos', 'valor' => count(array_filter($jornadas, fn($x) => (int) $x['activo'])),
     'icono' => 'clock', 'color' => 'blue'],
    ['label' => 'Con horario asignado', 'valor' => $activos - count($sinJornada),
     'nota' => 'de ' . $activos . ' activos', 'icono' => 'users', 'color' => 'emerald'],
    ['label' => 'Sin horario', 'valor' => count($sinJornada),
     'nota' => count($sinJornada) > 0 ? 'A esta gente no se le puede calcular tardanza' : 'Toda la plantilla cubierta',
     'icono' => count($sinJornada) > 0 ? 'alert' : 'check',
     'color' => count($sinJornada) > 0 ? 'rose' : 'emerald',
     'href'  => count($sinJornada) > 0 ? url('modules/rrhh/jornadas.php?tab=asignar') : ''],
    ['label' => 'Feriados de ' . date('Y'), 'valor' => $feriadosAnio,
     'nota' => $feriadosAnio === 0 ? 'Sin calendario, un feriado sale como falta' : '',
     'icono' => 'calendar', 'color' => $feriadosAnio === 0 ? 'amber' : 'cyan',
     'href' => url('modules/rrhh/feriados.php')],
]) ?>

<?php if ($detalle): ?>
  <div class="card p-5 mb-5 border-blue-200 bg-blue-50/40">
    <p class="font-semibold text-slate-700 mb-2">Lo que cambió al recalcular</p>
    <ul class="text-sm text-slate-600 space-y-0.5 max-h-56 overflow-auto">
      <?php foreach ($detalle as $d): ?><li>· <?= e($d) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div x-data="{ tab: '<?= $tab ?>', editando: null }">
  <div class="flex items-center gap-1 mb-5 bg-slate-100 p-1 rounded-xl w-full sm:w-auto sm:inline-flex">
    <button @click="tab='horarios'" :class="tab==='horarios' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            class="px-4 py-2 rounded-lg text-sm font-semibold transition">Horarios</button>
    <button @click="tab='asignar'" :class="tab==='asignar' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            class="px-4 py-2 rounded-lg text-sm font-semibold transition">
      Quién tiene cuál
      <?php if ($sinJornada): ?><span class="badge badge-rose ml-1"><?= count($sinJornada) ?></span><?php endif; ?>
    </button>
  </div>

  <!-- ============ HORARIOS ============ -->
  <div x-show="tab==='horarios'" x-cloak>
    <div class="card overflow-hidden mb-5">
      <?= toolbar(
          '<p class="text-sm text-slate-500">Un horario se aplica a quien lo tenga asignado. La tolerancia decide <b>si</b> cuenta una tardanza; los minutos se cuentan enteros desde la hora pactada.</p>',
          $puedeGestionar ? '<button type="button" @click="editando = {id:0}" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nuevo horario</button>' : ''
      ) ?>

      <?php if (!$jornadas): ?>
        <?= empty_state('Todavía no hay ningún horario',
              'Mientras no lo haya, el reloj sigue trayendo las marcas pero nadie puede decir si alguien llegó tarde.',
              'clock') ?>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Horario</th>
              <?php foreach ($DIAS as $d): ?><th class="text-center"><?= mb_substr(jorNombreDia($d), 0, 3) ?></th><?php endforeach; ?>
              <th class="text-center">Tolerancia</th>
              <th class="text-center">Almuerzo</th>
              <th class="text-center">Gente</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($jornadas as $j): ?>
              <tr>
                <td>
                  <span class="font-semibold text-slate-700"><?= e($j['nombre']) ?></span>
                  <?php if (!(int) $j['activo']): ?> <?= badge('Inactivo', 'slate') ?><?php endif; ?>
                  <?php if ($j['notas']): ?><div class="text-xs text-slate-400"><?= e($j['notas']) ?></div><?php endif; ?>
                </td>
                <?php foreach ($DIAS as $d):
                    $dd = $j['dias'][$d] ?? null; ?>
                  <td class="text-center text-xs whitespace-nowrap">
                    <?php if ($dd && (int) $dd['labora']): ?>
                      <span class="text-slate-600"><?= substr($dd['hora_entrada'], 0, 5) ?></span>
                      <div class="text-slate-400"><?= substr($dd['hora_salida'], 0, 5) ?></div>
                    <?php else: ?>
                      <span class="text-slate-300">—</span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
                <td class="text-center tabular-nums"><?= (int) $j['tolerancia_min'] ?> min</td>
                <td class="text-center tabular-nums"><?= (int) $j['almuerzo_min'] ?> min</td>
                <td class="text-center">
                  <?= (int) $j['gente'] > 0 ? badge((string) $j['gente'], 'blue') : '<span class="text-slate-300">0</span>' ?>
                </td>
                <td>
                  <?= acciones([
                      $puedeGestionar ? btn_icono([
                          'icono' => 'edit', 'titulo' => 'Editar',
                          'aria'  => 'Editar el horario ' . $j['nombre'],
                          'onclick' => 'this.dispatchEvent(new CustomEvent(\'editar-jornada\',{bubbles:true,detail:' . e(json_encode($j)) . '}))',
                      ]) : '',
                      $puedeGestionar && (int) $j['gente'] === 0 ? btn_eliminar([
                          'id' => $j['id'], 'titulo' => 'Eliminar',
                          'aria' => 'Eliminar el horario ' . $j['nombre'],
                          'pregunta' => '¿Eliminar el horario «' . $j['nombre'] . '»?',
                      ]) : '',
                  ]) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($puedeGestionar): ?>
    <!-- Recalcular -->
    <div class="card p-5">
      <p class="font-semibold text-slate-700">Aplicar a lo que ya se trajo</p>
      <p class="text-sm text-slate-500 mt-1 mb-4">
        El horario casi siempre se asigna después de que el reloj lleva semanas trayendo marcas.
        Recalcular vuelve a juzgar esos días con el horario de ahora. <b>Lo corregido a mano no se toca.</b>
      </p>
      <form method="post" class="flex flex-wrap items-end gap-3">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="recalcular">
        <div>
          <label class="form-label">Desde</label>
          <input type="date" name="desde" class="form-input" value="<?= date('Y-m-01', strtotime('-1 month')) ?>">
        </div>
        <div>
          <label class="form-label">Hasta</label>
          <input type="date" name="hasta" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
        <button name="simular" value="1" class="btn btn-secondary">Ver qué cambiaría</button>
        <button class="btn btn-primary"><?= icon('refresh', 'w-4 h-4') ?> Recalcular</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- ============ ASIGNAR ============ -->
  <div x-show="tab==='asignar'" x-cloak>
    <?php if ($sinJornada): ?>
      <div class="card p-4 mb-4 border-rose-200 bg-rose-50/40 text-sm text-rose-700">
        <b><?= count($sinJornada) ?> persona(s) sin horario.</b>
        A ellas el reloj les apunta la hora de llegada, pero el sistema no dice si es tarde
        —y tampoco las da nunca por ausentes, porque no sabe si ese día les tocaba trabajar—.
      </div>
    <?php endif; ?>

    <form method="post" x-data="{ marcados: [] }">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="asignar">
      <div class="card overflow-hidden">
        <?= toolbar(
            '<p class="text-sm text-slate-500"><span x-text="marcados.length"></span> seleccionada(s)</p>',
            $puedeGestionar
              ? '<div class="flex items-center gap-2">'
                . '<select name="jornada_id" class="form-input form-input-sm">'
                . '<option value="">— Quitarles el horario —</option>'
                . implode('', array_map(fn($x) => '<option value="' . (int) $x['id'] . '">' . e($x['nombre']) . '</option>',
                                        array_filter($jornadas, fn($x) => (int) $x['activo'])))
                . '</select>'
                . '<button class="btn btn-primary" :disabled="!marcados.length">Asignar</button></div>'
              : ''
        ) ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead>
              <tr>
                <?php if ($puedeGestionar): ?><th class="w-10"></th><?php endif; ?>
                <th>Empleado</th><th>Departamento</th><th>Horario</th><th class="text-center">Reloj</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($plantilla as $p): ?>
                <tr class="<?= $p['jornada_id'] ? '' : 'bg-rose-50/40' ?>">
                  <?php if ($puedeGestionar): ?>
                    <td><input type="checkbox" name="empleados[]" value="<?= (int) $p['id'] ?>"
                               x-model="marcados" class="rounded border-slate-300"></td>
                  <?php endif; ?>
                  <td class="font-medium text-slate-700"><?= e(trim($p['nombre'] . ' ' . $p['apellido'])) ?></td>
                  <td class="text-slate-500"><?= e($p['departamento'] ?: '—') ?></td>
                  <td>
                    <?= $p['jornada'] ? badge($p['jornada'], 'blue') : '<span class="badge badge-rose">Sin horario</span>' ?>
                  </td>
                  <td class="text-center">
                    <?= $p['biotime_emp_code']
                        ? badge($p['biotime_emp_code'], 'emerald')
                        : '<span class="text-xs text-slate-400" title="Sin código del reloj: nunca se le apunta una falta">—</span>' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </form>
  </div>

  <!-- ============ MODAL: crear / editar ============ -->
  <?php if ($puedeGestionar): ?>
  <div x-show="editando" x-cloak @editar-jornada.window="editando = $event.detail"
       class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto bg-slate-900/40">
    <div @click.outside="editando = null" class="card w-full max-w-3xl my-8 p-6">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="editando?.id || 0">

        <h3 class="text-lg font-bold text-slate-800 mb-4"
            x-text="editando?.id ? 'Editar horario' : 'Nuevo horario'"></h3>

        <div class="grid sm:grid-cols-2 gap-4 mb-5">
          <div class="sm:col-span-2">
            <label class="form-label">Nombre</label>
            <input name="nombre" class="form-input" required maxlength="80"
                   :value="editando?.nombre || ''" placeholder="Oficina 8 a 5">
          </div>
          <div>
            <label class="form-label">Tolerancia (minutos)</label>
            <input type="number" name="tolerancia_min" class="form-input" min="0" max="120"
                   :value="editando?.tolerancia_min ?? 5">
            <p class="form-hint">Decide <b>si</b> cuenta la tardanza, no cuánta: pasados estos minutos se cuenta el retraso entero.</p>
          </div>
          <div>
            <label class="form-label">Almuerzo (minutos)</label>
            <input type="number" name="almuerzo_min" class="form-input" min="0" max="240"
                   :value="editando?.almuerzo_min ?? 60">
            <p class="form-hint">Se resta solo de las jornadas de 6 horas o más.</p>
          </div>
          <div>
            <label class="form-label">Hora extra a partir de (minutos)</label>
            <input type="number" name="extra_desde_min" class="form-input" min="0" max="240"
                   :value="editando?.extra_desde_min ?? 30">
            <p class="form-hint">Quedarse cinco minutos recogiendo no es una hora extra.</p>
          </div>
          <div>
            <label class="form-label">Estado</label>
            <select name="activo" class="form-input">
              <option value="1">Activo</option>
              <option value="0" :selected="editando && editando.activo == 0">Inactivo</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="form-label">Notas</label>
            <input name="notas" class="form-input" maxlength="255" :value="editando?.notas || ''">
          </div>
        </div>

        <p class="form-label mb-2">Los días</p>
        <div class="space-y-2 mb-5">
          <?php foreach ($DIAS as $d):
              $porDefecto = $d <= 5 ? ['1', '08:00', '17:00'] : ($d === 6 ? ['1', '08:00', '12:00'] : ['0', '', '']); ?>
            <div class="flex flex-wrap items-center gap-3 p-2 rounded-lg bg-slate-50"
                 x-data="{ dia: <?= $d ?>, on: true }"
                 x-effect="on = editando ? (editando.id ? (editando.dias?.[dia]?.labora == 1) : <?= $porDefecto[0] ?> == 1) : false">
              <label class="flex items-center gap-2 w-32 shrink-0">
                <input type="checkbox" name="labora_<?= $d ?>" value="1" x-model="on" class="rounded border-slate-300">
                <span class="text-sm font-medium text-slate-600"><?= jorNombreDia($d) ?></span>
              </label>
              <input type="time" name="entrada_<?= $d ?>" class="form-input form-input-sm w-32" :disabled="!on"
                     x-bind:value="editando?.dias?.[dia]?.hora_entrada?.substring(0,5) || '<?= $porDefecto[1] ?>'">
              <span class="text-slate-400 text-sm">a</span>
              <input type="time" name="salida_<?= $d ?>" class="form-input form-input-sm w-32" :disabled="!on"
                     x-bind:value="editando?.dias?.[dia]?.hora_salida?.substring(0,5) || '<?= $porDefecto[2] ?>'">
              <span class="text-xs text-slate-400" x-show="!on">No se trabaja</span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="flex justify-end gap-2">
          <button type="button" @click="editando = null" class="btn btn-secondary">Cancelar</button>
          <button class="btn btn-primary">Guardar horario</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
