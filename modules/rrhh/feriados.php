<?php
/**
 * El calendario de feriados.
 *
 * Sin él, el 27 de febrero sale como que no vino toda la empresa, y unas
 * vacaciones que caen sobre un feriado le comen al empleado un día de su
 * derecho del art. 177 —porque `vac_dias_laborables()` solo descuenta domingos—.
 *
 * Los de la Ley 139-97 se calculan, con su traslado al lunes incluido. Los que
 * declara el Gobierno sobre la marcha —un duelo nacional, una jornada
 * electoral— se añaden a mano y sobreviven a regenerar el año.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/jornadas.php';
require_perm('rrhh_feriados.ver');

$anio = max(2020, min(2100, (int) (get('anio') ?: date('Y'))));

if (isPost()) {
    verify_csrf();
    require_perm('rrhh_feriados.gestionar');
    $accion = post('accion');

    if ($accion === 'sembrar') {
        $a = max(2020, min(2100, postInt('anio', (int) date('Y'))));
        $r = jorSembrarFeriados($a);
        audit('rrhh_feriados', 'gestionar', "Feriados de $a generados: {$r['creados']} nuevo(s)");
        flash('success', "Año $a: {$r['creados']} feriado(s) añadidos, {$r['ya_estaban']} ya estaban"
            . ($r['respetados_manual'] ? ", {$r['respetados_manual']} puesto(s) a mano respetado(s)" : '') . '.');
        redirect('modules/rrhh/feriados.php?anio=' . $a);
    }

    if ($accion === 'guardar') {
        $fecha  = trim(post('fecha'));
        $nombre = trim(post('nombre'));
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $fecha) || $nombre === '') {
            flash('error', 'Hace falta una fecha válida y un nombre.');
        } elseif (qVal("SELECT id FROM feriados WHERE fecha = ?", [$fecha])) {
            flash('error', 'Ya hay un feriado ese día.');
        } else {
            // `automatico = 0`: lo puso una persona, así que regenerar el año
            // no puede borrarlo. Es lo único de esta tabla que no se recalcula.
            dbInsert('feriados', ['fecha' => $fecha, 'nombre' => mb_substr($nombre, 0, 80),
                                  'automatico' => 0, 'pagado' => postInt('pagado', 1)]);
            audit('rrhh_feriados', 'gestionar', "Feriado añadido: $fecha · $nombre");
            flash('success', 'Feriado añadido.');
        }
        redirect('modules/rrhh/feriados.php?anio=' . substr($fecha, 0, 4));
    }

    if ($accion === 'eliminar') {
        $id = postInt('id');
        $f  = qOne("SELECT fecha, nombre FROM feriados WHERE id = ?", [$id]);
        if ($f) {
            q("DELETE FROM feriados WHERE id = ?", [$id]);
            audit('rrhh_feriados', 'gestionar', "Feriado eliminado: {$f['fecha']} · {$f['nombre']}");
            flash('success', 'Feriado eliminado.');
        }
        redirect('modules/rrhh/feriados.php?anio=' . $anio);
    }
}

$feriados = qAll("SELECT * FROM feriados WHERE YEAR(fecha) = ? ORDER BY fecha", [$anio]);
$deLey    = jorFeriadosRD($anio);

// Lo que la ley dice y todavía no está en la tabla. Se enseña para que la
// diferencia se vea, en vez de tener que compararlo de cabeza.
$faltan = [];
foreach ($deLey as $f => $n) {
    if (!array_filter($feriados, fn($x) => $x['fecha'] === $f)) $faltan[$f] = $n;
}

$puedeGestionar = can('rrhh_feriados.gestionar');
layout_start('Feriados', 'Un feriado no es una falta ni una tardanza: sin calendario, el sistema no puede distinguirlo');
?>

<?= kpis([
    ['label' => 'Feriados en ' . $anio, 'valor' => count($feriados), 'icono' => 'calendar', 'color' => 'cyan'],
    ['label' => 'Que manda la ley', 'valor' => count($deLey),
     'nota' => 'Ley 139-97, con el traslado al lunes', 'icono' => 'file', 'color' => 'blue'],
    ['label' => 'Faltan por cargar', 'valor' => count($faltan),
     'nota' => $faltan ? 'Esos días saldrían como falta' : 'El año está completo',
     'icono' => $faltan ? 'alert' : 'check', 'color' => $faltan ? 'amber' : 'emerald'],
    ['label' => 'Puestos a mano', 'valor' => count(array_filter($feriados, fn($x) => !(int) $x['automatico'])),
     'nota' => 'Duelos, elecciones: no salen de la ley', 'icono' => 'edit', 'color' => 'violet'],
], 4) ?>

<div class="card overflow-hidden mb-5">
  <?= toolbar(
      '<form method="get" class="flex items-center gap-2">'
      . '<label class="text-sm text-slate-500">Año</label>'
      . '<input type="number" name="anio" value="' . $anio . '" min="2020" max="2100" class="form-input form-input-sm w-28" onchange="this.form.submit()">'
      . '</form>',
      $puedeGestionar
        ? '<form method="post" class="inline">' . csrf_field()
          . '<input type="hidden" name="accion" value="sembrar"><input type="hidden" name="anio" value="' . $anio . '">'
          . '<button class="btn btn-primary">' . icon('refresh', 'w-4 h-4') . ' Generar los de ' . $anio . '</button></form>'
        : ''
  ) ?>

  <?php if (!$feriados): ?>
    <?= empty_state('El año ' . $anio . ' no tiene feriados cargados',
          'Mientras esté vacío, cada feriado de este año saldrá en la asistencia como una falta de toda la plantilla.',
          'calendar') ?>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead><tr><th>Fecha</th><th>Día</th><th>Feriado</th><th class="text-center">Origen</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($feriados as $f):
            $dow = (int) date('N', strtotime($f['fecha']));
            $enLey = $deLey[$f['fecha']] ?? null; ?>
          <tr>
            <td class="tabular-nums font-medium text-slate-700"><?= e(fechaCorta($f['fecha'])) ?></td>
            <td class="text-slate-500"><?= jorNombreDia($dow) ?></td>
            <td>
              <?= e($f['nombre']) ?>
              <?php if (!(int) $f['pagado']): ?> <?= badge('No pagado', 'slate') ?><?php endif; ?>
              <?php if ((int) $f['automatico'] && !$enLey): ?>
                <?= badge('Ya no lo manda la ley', 'amber') ?>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?= (int) $f['automatico'] ? badge('Ley 139-97', 'blue') : badge('Puesto a mano', 'violet') ?>
            </td>
            <td>
              <?= $puedeGestionar ? acciones([btn_eliminar([
                    'id' => $f['id'], 'titulo' => 'Eliminar',
                    'aria' => 'Eliminar el feriado ' . $f['nombre'],
                    'pregunta' => '¿Quitar «' . $f['nombre'] . '» del calendario?',
                  ])]) : '' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($faltan): ?>
  <div class="card p-5 mb-5 border-amber-200 bg-amber-50/40">
    <p class="font-semibold text-slate-700 mb-1">La ley manda estos y no están cargados</p>
    <p class="text-sm text-slate-500 mb-3">Esos días la asistencia los tratará como laborables.</p>
    <ul class="text-sm text-slate-600 grid sm:grid-cols-2 gap-x-6 gap-y-0.5">
      <?php foreach ($faltan as $f => $n): ?>
        <li>· <span class="tabular-nums"><?= e(fechaCorta($f)) ?></span> — <?= e($n) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($puedeGestionar): ?>
<div class="card p-5">
  <p class="font-semibold text-slate-700">Añadir uno que no sale de la ley</p>
  <p class="text-sm text-slate-500 mt-1 mb-4">
    Un duelo nacional o una jornada electoral los declara el Gobierno sobre la marcha.
    Los que se añadan aquí <b>no se borran</b> al volver a generar el año.
  </p>
  <form method="post" class="flex flex-wrap items-end gap-3">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <div><label class="form-label">Fecha</label><input type="date" name="fecha" class="form-input" required></div>
    <div class="flex-1 min-w-[16rem]"><label class="form-label">Cómo se llama</label>
      <input name="nombre" class="form-input" maxlength="80" required placeholder="Duelo nacional"></div>
    <div><label class="form-label">¿Se paga?</label>
      <select name="pagado" class="form-input"><option value="1">Sí</option><option value="0">No</option></select></div>
    <button class="btn btn-primary">Añadir</button>
  </form>
</div>
<?php endif; ?>

<?php layout_end(); ?>
