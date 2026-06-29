<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_asistencia.ver');

$estadosAsis = ['presente', 'ausente', 'tardanza', 'permiso', 'vacaciones', 'licencia'];

/** Calcula horas trabajadas y extra a partir de hora_entrada/hora_salida. */
function calcularHoras(?string $entrada, ?string $salida): array
{
    if (!$entrada || !$salida) return [0.0, 0.0];
    $t1 = strtotime($entrada);
    $t2 = strtotime($salida);
    if ($t1 === false || $t2 === false) return [0.0, 0.0];
    $diff = ($t2 - $t1) / 3600;          // diferencia en horas
    if ($diff < 0) $diff = 0;            // salida antes de entrada → 0
    $horas = round($diff, 2);
    $extra = $horas > 8 ? round($horas - 8, 2) : 0.0;
    return [$horas, $extra];
}

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    require_perm('rrhh_asistencia.registrar');
    $accion = post('accion');

    if ($accion === 'marcar' || $accion === 'registrar') {
        $empleadoId = postInt('empleado_id');
        $fechaPost  = trim(post('fecha'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPost)) $fechaPost = date('Y-m-d');

        // El empleado debe existir, estar activo y dentro del alcance de sucursal.
        [$wScope, $pScope] = sucursalScope('sucursal_id');
        $emp = qOne(
            "SELECT id, sucursal_id FROM empleados WHERE id = ? AND estado = 'activo' AND $wScope",
            array_merge([$empleadoId], $pScope)
        );

        if (!$emp) {
            flash('error', 'Empleado no válido o sin permiso para esta sucursal.');
            redirect('modules/rrhh/asistencia.php?fecha=' . $fechaPost);
        }

        $estado = in_array(post('estado'), $estadosAsis, true) ? post('estado') : 'presente';

        if ($accion === 'marcar') {
            // Marcado rápido: solo fija el estado, sin horas.
            $entrada = null;
            $salida  = null;
            $horas   = 0.0;
            $extra   = 0.0;
        } else {
            $entrada = trim(post('hora_entrada')) ?: null;
            $salida  = trim(post('hora_salida')) ?: null;
            [$horas, $extra] = calcularHoras($entrada, $salida);
        }
        $notas = trim(post('notas')) ?: null;

        $datos = [
            'empleado_id'      => $empleadoId,
            'sucursal_id'      => $emp['sucursal_id'],
            'fecha'            => $fechaPost,
            'hora_entrada'     => $entrada,
            'hora_salida'      => $salida,
            'horas_trabajadas' => $horas,
            'horas_extra'      => $extra,
            'estado'           => $estado,
            'notas'            => $notas,
        ];

        // UPSERT por la clave única (empleado_id, fecha).
        $existe = qVal("SELECT id FROM asistencias WHERE empleado_id = ? AND fecha = ?", [$empleadoId, $fechaPost]);
        if ($existe) {
            unset($datos['empleado_id'], $datos['fecha']);   // no se reescribe la clave
            dbUpdate('asistencias', $datos, 'id = ?', [(int) $existe]);
            audit('rrhh_asistencia', 'registrar', "Asistencia actualizada (empleado #$empleadoId, $fechaPost): $estado", ['tabla' => 'asistencias', 'registro_id' => (int) $existe]);
        } else {
            $nid = dbInsert('asistencias', $datos);
            audit('rrhh_asistencia', 'registrar', "Asistencia registrada (empleado #$empleadoId, $fechaPost): $estado", ['tabla' => 'asistencias', 'registro_id' => $nid]);
        }
        flash('success', 'Asistencia guardada correctamente.');
        redirect('modules/rrhh/asistencia.php?fecha=' . $fechaPost);
    }

    redirect('modules/rrhh/asistencia.php');
}

// ---------- Datos de la página ----------
$fecha = trim(get('fecha'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');
$puedeRegistrar = can('rrhh_asistencia.registrar');

[$wScope, $pScope] = sucursalScope('e.sucursal_id');
$empleados = qAll(
    "SELECT e.id, e.nombre, e.apellido, e.foto,
            p.nombre  AS puesto,
            d.nombre  AS departamento,
            a.id      AS asistencia_id,
            a.hora_entrada, a.hora_salida, a.horas_trabajadas, a.horas_extra,
            a.estado  AS estado_dia, a.notas
       FROM empleados e
       LEFT JOIN puestos       p ON p.id = e.puesto_id
       LEFT JOIN departamentos d ON d.id = e.departamento_id
       LEFT JOIN asistencias   a ON a.empleado_id = e.id AND a.fecha = ?
      WHERE e.estado = 'activo' AND $wScope
      ORDER BY e.nombre, e.apellido",
    array_merge([$fecha], $pScope)
);

// KPIs de la fecha seleccionada
$totalEmpleados = count($empleados);
$presentes = $ausentes = $tardanzas = 0;
foreach ($empleados as $emp) {
    switch ($emp['estado_dia']) {
        case 'presente': $presentes++; break;
        case 'ausente':  $ausentes++;  break;
        case 'tardanza': $tardanzas++; break;
    }
}

/** Color del badge según el estado del día. */
function colorEstadoDia(?string $estado): string
{
    return [
        'presente'   => 'emerald',
        'ausente'    => 'rose',
        'tardanza'   => 'amber',
        'permiso'    => 'sky',
        'vacaciones' => 'indigo',
        'licencia'   => 'violet',
    ][$estado] ?? 'slate';
}

$esHoy = $fecha === date('Y-m-d');
layout_start('Control de Asistencia', 'Registra la asistencia diaria de los empleados activos');
?>

<!-- Selector de fecha + KPIs -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
  <form method="get" class="flex items-end gap-3">
    <div>
      <label class="label">Fecha</label>
      <input type="date" name="fecha" value="<?= e($fecha) ?>" max="<?= date('Y-m-d') ?>"
             class="input" onchange="this.form.submit()">
    </div>
    <button type="submit" class="btn btn-soft"><?= icon('calendar', 'w-4 h-4') ?> Ver</button>
    <?php if (!$esHoy): ?>
      <a href="<?= url('modules/rrhh/asistencia.php') ?>" class="btn btn-ghost">Hoy</a>
    <?php endif; ?>
  </form>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <div class="card px-4 py-3">
      <div class="text-xs text-slate-400 font-medium">Presentes</div>
      <div class="text-2xl font-bold text-emerald-600"><?= $presentes ?></div>
    </div>
    <div class="card px-4 py-3">
      <div class="text-xs text-slate-400 font-medium">Ausentes</div>
      <div class="text-2xl font-bold text-rose-600"><?= $ausentes ?></div>
    </div>
    <div class="card px-4 py-3">
      <div class="text-xs text-slate-400 font-medium">Tardanzas</div>
      <div class="text-2xl font-bold text-amber-600"><?= $tardanzas ?></div>
    </div>
    <div class="card px-4 py-3">
      <div class="text-xs text-slate-400 font-medium">Total empleados</div>
      <div class="text-2xl font-bold text-slate-700"><?= $totalEmpleados ?></div>
    </div>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <h3 class="font-semibold text-slate-700">Asistencia del <?= e(fechaCorta($fecha)) ?></h3>
    <span class="text-sm text-slate-400"><?= $totalEmpleados ?> empleado(s) activo(s)</span>
  </div>

  <?php if (!$empleados): ?>
    <?= empty_state('Sin empleados activos', 'No hay empleados activos en esta sucursal para registrar asistencia.', 'id') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Empleado</th>
            <th>Puesto</th>
            <th>Departamento</th>
            <th>Estado</th>
            <th class="text-center">Entrada</th>
            <th class="text-center">Salida</th>
            <th class="text-center">Horas</th>
            <?php if ($puedeRegistrar): ?><th class="text-right">Acciones</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($empleados as $emp): ?>
            <?php $nombreCompleto = $emp['nombre'] . ' ' . $emp['apellido']; ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <?= avatar($nombreCompleto) ?>
                  <span class="font-semibold text-slate-700"><?= e($nombreCompleto) ?></span>
                </div>
              </td>
              <td class="text-slate-500"><?= e($emp['puesto'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($emp['departamento'] ?: '—') ?></td>
              <td>
                <?php if ($emp['estado_dia']): ?>
                  <?= badge(ucfirst($emp['estado_dia']), colorEstadoDia($emp['estado_dia'])) ?>
                <?php else: ?>
                  <span class="badge badge-slate">Sin registro</span>
                <?php endif; ?>
              </td>
              <td class="text-center text-slate-600"><?= $emp['hora_entrada'] ? e(date('h:i A', strtotime($emp['hora_entrada']))) : '—' ?></td>
              <td class="text-center text-slate-600"><?= $emp['hora_salida'] ? e(date('h:i A', strtotime($emp['hora_salida']))) : '—' ?></td>
              <td class="text-center">
                <?php if ((float) $emp['horas_trabajadas'] > 0): ?>
                  <span class="font-medium text-slate-700"><?= e(qty($emp['horas_trabajadas'])) ?>h</span>
                  <?php if ((float) $emp['horas_extra'] > 0): ?>
                    <span class="badge badge-amber ml-1" title="Horas extra">+<?= e(qty($emp['horas_extra'])) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-slate-300">—</span>
                <?php endif; ?>
              </td>
              <?php if ($puedeRegistrar): ?>
                <td>
                  <div class="flex items-center justify-end gap-1">
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="marcar">
                      <input type="hidden" name="empleado_id" value="<?= (int) $emp['id'] ?>">
                      <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
                      <input type="hidden" name="estado" value="presente">
                      <button class="btn btn-sm btn-success" title="Marcar presente"><?= icon('check', 'w-4 h-4') ?> Presente</button>
                    </form>
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="marcar">
                      <input type="hidden" name="empleado_id" value="<?= (int) $emp['id'] ?>">
                      <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
                      <input type="hidden" name="estado" value="ausente">
                      <button class="btn btn-sm btn-danger" title="Marcar ausente"><?= icon('x', 'w-4 h-4') ?> Ausente</button>
                    </form>
                    <button onclick="<?= jsEvent('asis:detalle', [
                        'empleado_id'  => $emp['id'],
                        'nombre'       => $nombreCompleto,
                        'hora_entrada' => $emp['hora_entrada'] ?: '',
                        'hora_salida'  => $emp['hora_salida'] ?: '',
                        'estado'       => $emp['estado_dia'] ?: 'presente',
                        'notas'        => $emp['notas'] ?: '',
                    ]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Detalle"><?= icon('edit', 'w-4 h-4') ?></button>
                  </div>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($puedeRegistrar): ?>
<!-- Modal detalle de asistencia -->
<div x-data="{open:false, form:{empleado_id:0, nombre:'', hora_entrada:'', hora_salida:'', estado:'presente', notas:''}}"
     @asis:detalle.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="registrar">
        <input type="hidden" name="empleado_id" :value="form.empleado_id">
        <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <div>
            <h3 class="font-bold text-slate-800">Registro de asistencia</h3>
            <p class="text-xs text-slate-400" x-text="form.nombre + ' · <?= e(fechaCorta($fecha)) ?>'"></p>
          </div>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Estado del día</label>
            <select name="estado" x-model="form.estado" class="select">
              <option value="presente">Presente</option>
              <option value="ausente">Ausente</option>
              <option value="tardanza">Tardanza</option>
              <option value="permiso">Permiso</option>
              <option value="vacaciones">Vacaciones</option>
              <option value="licencia">Licencia</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Hora de entrada</label>
              <input type="time" name="hora_entrada" x-model="form.hora_entrada" class="input">
            </div>
            <div>
              <label class="label">Hora de salida</label>
              <input type="time" name="hora_salida" x-model="form.hora_salida" class="input">
            </div>
          </div>
          <p class="text-xs text-slate-400">Las horas trabajadas y las horas extra (sobre 8h) se calculan automáticamente.</p>
          <div>
            <label class="label">Notas</label>
            <textarea name="notas" x-model="form.notas" rows="2" class="input" placeholder="Opcional"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
