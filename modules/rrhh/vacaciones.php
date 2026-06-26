<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_vacaciones.ver');

$tipos = ['vacaciones', 'licencia'];
$subtiposLicencia = ['enfermedad', 'personal', 'maternidad', 'duelo'];

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        require_perm('rrhh_vacaciones.crear');
        $empleadoId = postInt('empleado_id');
        $tipo       = in_array(post('tipo'), $tipos, true) ? post('tipo') : 'vacaciones';
        $subtipo    = trim(post('subtipo'));
        $desde      = trim(post('fecha_desde'));
        $hasta      = trim(post('fecha_hasta'));
        $conGoce    = postInt('con_goce', 1) ? 1 : 0;
        $motivo     = trim(post('motivo')) ?: null;

        // Para vacaciones el subtipo va vacío; para licencia se valida contra la lista.
        if ($tipo === 'vacaciones') {
            $subtipo = null;
        } else {
            $subtipo = in_array($subtipo, $subtiposLicencia, true) ? $subtipo : null;
        }

        $okFechas = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta);
        $tsDesde = $okFechas ? strtotime($desde) : false;
        $tsHasta = $okFechas ? strtotime($hasta) : false;

        // El empleado debe existir y estar dentro del alcance de sucursal.
        [$wScope, $pScope] = sucursalScope('sucursal_id');
        $emp = qOne(
            "SELECT id FROM empleados WHERE id = ? AND $wScope",
            array_merge([$empleadoId], $pScope)
        );

        if (!$emp) {
            flash('error', 'Selecciona un empleado válido.');
        } elseif (!$okFechas || $tsDesde === false || $tsHasta === false) {
            flash('error', 'Las fechas de la solicitud no son válidas.');
        } elseif ($tsHasta < $tsDesde) {
            flash('error', 'La fecha hasta no puede ser anterior a la fecha desde.');
        } else {
            // Días inclusivos: datediff + 1.
            $dias = (int) floor(($tsHasta - $tsDesde) / 86400) + 1;
            $nid = dbInsert('vacaciones', [
                'empleado_id'     => $empleadoId,
                'tipo'            => $tipo,
                'subtipo'         => $subtipo,
                'fecha_solicitud' => date('Y-m-d'),
                'fecha_desde'     => $desde,
                'fecha_hasta'     => $hasta,
                'dias'            => $dias,
                'con_goce'        => $conGoce,
                'estado'          => 'solicitada',
                'motivo'          => $motivo,
            ]);
            audit('rrhh_vacaciones', 'crear', "Solicitud de $tipo creada (empleado #$empleadoId, $dias día(s))", ['tabla' => 'vacaciones', 'registro_id' => $nid]);
            flash('success', 'Solicitud registrada correctamente.');
        }
        redirect('modules/rrhh/vacaciones.php');
    }

    if ($accion === 'aprobar' || $accion === 'rechazar') {
        require_perm('rrhh_vacaciones.aprobar');
        $id = postInt('id');
        // Solo se procesan solicitudes en estado 'solicitada' dentro del alcance de sucursal.
        [$wScope, $pScope] = sucursalScope('e.sucursal_id');
        $sol = qOne(
            "SELECT v.id, v.tipo, v.estado, v.empleado_id
               FROM vacaciones v
               JOIN empleados e ON e.id = v.empleado_id
              WHERE v.id = ? AND $wScope",
            array_merge([$id], $pScope)
        );

        if (!$sol) {
            flash('error', 'Solicitud no encontrada.');
        } elseif ($sol['estado'] !== 'solicitada') {
            flash('error', 'Solo se pueden aprobar o rechazar solicitudes pendientes.');
        } else {
            $u = current_user();
            $nuevoEstado = $accion === 'aprobar' ? 'aprobada' : 'rechazada';
            tx(function () use ($sol, $nuevoEstado, $u, $accion) {
                dbUpdate('vacaciones', [
                    'estado'       => $nuevoEstado,
                    'aprobado_por' => $u['id'] ?? null,
                ], 'id = ?', [(int) $sol['id']]);
                // Al aprobar vacaciones, el empleado pasa a estado 'vacaciones'.
                if ($accion === 'aprobar' && $sol['tipo'] === 'vacaciones') {
                    dbUpdate('empleados', ['estado' => 'vacaciones'], 'id = ?', [(int) $sol['empleado_id']]);
                }
            });
            audit('rrhh_vacaciones', $accion, "Solicitud #{$sol['id']} {$nuevoEstado}", ['tabla' => 'vacaciones', 'registro_id' => (int) $sol['id']]);
            flash('success', $accion === 'aprobar' ? 'Solicitud aprobada.' : 'Solicitud rechazada.');
        }
        redirect('modules/rrhh/vacaciones.php');
    }

    redirect('modules/rrhh/vacaciones.php');
}

// ---------- Filtros y listado ----------
$fEstado   = trim(get('estado'));
$fEmpleado = (int) get('empleado');
$puedeAprobar = can('rrhh_vacaciones.aprobar');

[$wScope, $pScope] = sucursalScope('e.sucursal_id');
$where  = [$wScope];
$params = $pScope;

if (in_array($fEstado, ['solicitada', 'aprobada', 'rechazada', 'disfrutada'], true)) {
    $where[] = 'v.estado = ?';
    $params[] = $fEstado;
}
if ($fEmpleado > 0) {
    $where[] = 'v.empleado_id = ?';
    $params[] = $fEmpleado;
}
$whereSql = implode(' AND ', $where);

$solicitudes = qAll(
    "SELECT v.*,
            e.nombre AS emp_nombre, e.apellido AS emp_apellido,
            ap.nombre AS aprob_nombre, ap.apellido AS aprob_apellido
       FROM vacaciones v
       JOIN empleados e ON e.id = v.empleado_id
       LEFT JOIN usuarios ap ON ap.id = v.aprobado_por
      WHERE $whereSql
      ORDER BY v.created_at DESC, v.id DESC",
    $params
);

// Empleados (alcance de sucursal) para el select de nueva solicitud y el filtro.
[$wEmp, $pEmp] = sucursalScope('e.sucursal_id');
$empleadosLista = qAll(
    "SELECT e.id, e.nombre, e.apellido
       FROM empleados e
      WHERE e.estado = 'activo' AND $wEmp
      ORDER BY e.nombre, e.apellido",
    $pEmp
);

// KPIs
$mesIni = date('Y-m-01');
$mesFin = date('Y-m-t');
[$wK, $pK] = sucursalScope('e.sucursal_id');
$kpiPendientes = (int) qVal(
    "SELECT COUNT(*) FROM vacaciones v JOIN empleados e ON e.id = v.empleado_id WHERE v.estado = 'solicitada' AND $wK",
    $pK
);
$kpiAprobadasMes = (int) qVal(
    "SELECT COUNT(*) FROM vacaciones v JOIN empleados e ON e.id = v.empleado_id
      WHERE v.estado = 'aprobada' AND v.fecha_solicitud BETWEEN ? AND ? AND $wK",
    array_merge([$mesIni, $mesFin], $pK)
);
$kpiEnVacaciones = (int) qVal(
    "SELECT COUNT(*) FROM empleados e WHERE e.estado = 'vacaciones' AND $wK",
    $pK
);

$colorEstado = ['solicitada' => 'amber', 'aprobada' => 'emerald', 'rechazada' => 'rose', 'disfrutada' => 'sky'];

$acciones = can('rrhh_vacaciones.crear') ? btn_nuevo('vac:new', 'Nueva solicitud') : '';
layout_start('Vacaciones y Licencias', 'Gestiona las solicitudes de vacaciones y licencias del personal', $acciones);
?>

<!-- KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
  <div class="card px-5 py-4 flex items-center gap-4">
    <span class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center"><?= icon('clock', 'w-5 h-5') ?></span>
    <div>
      <div class="text-xs text-slate-400 font-medium">Solicitudes pendientes</div>
      <div class="text-2xl font-bold text-slate-700"><?= $kpiPendientes ?></div>
    </div>
  </div>
  <div class="card px-5 py-4 flex items-center gap-4">
    <span class="w-11 h-11 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center"><?= icon('check', 'w-5 h-5') ?></span>
    <div>
      <div class="text-xs text-slate-400 font-medium">Aprobadas este mes</div>
      <div class="text-2xl font-bold text-slate-700"><?= $kpiAprobadasMes ?></div>
    </div>
  </div>
  <div class="card px-5 py-4 flex items-center gap-4">
    <span class="w-11 h-11 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center"><?= icon('sun', 'w-5 h-5') ?></span>
    <div>
      <div class="text-xs text-slate-400 font-medium">Empleados de vacaciones</div>
      <div class="text-2xl font-bold text-slate-700"><?= $kpiEnVacaciones ?></div>
    </div>
  </div>
</div>

<div class="card overflow-hidden">
  <!-- Filtros -->
  <div class="p-4 border-b border-slate-100">
    <form method="get" class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label">Estado</label>
        <select name="estado" class="select" onchange="this.form.submit()">
          <option value="">Todos</option>
          <option value="solicitada" <?= $fEstado === 'solicitada' ? 'selected' : '' ?>>Solicitadas</option>
          <option value="aprobada"   <?= $fEstado === 'aprobada' ? 'selected' : '' ?>>Aprobadas</option>
          <option value="rechazada"  <?= $fEstado === 'rechazada' ? 'selected' : '' ?>>Rechazadas</option>
          <option value="disfrutada" <?= $fEstado === 'disfrutada' ? 'selected' : '' ?>>Disfrutadas</option>
        </select>
      </div>
      <div>
        <label class="label">Empleado</label>
        <select name="empleado" class="select" onchange="this.form.submit()">
          <option value="0">Todos</option>
          <?php foreach ($empleadosLista as $el): ?>
            <option value="<?= (int) $el['id'] ?>" <?= $fEmpleado === (int) $el['id'] ? 'selected' : '' ?>>
              <?= e($el['nombre'] . ' ' . $el['apellido']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-soft"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
      <?php if ($fEstado !== '' || $fEmpleado > 0): ?>
        <a href="<?= url('modules/rrhh/vacaciones.php') ?>" class="btn btn-ghost">Limpiar</a>
      <?php endif; ?>
      <span class="ml-auto text-sm text-slate-400 self-center"><?= count($solicitudes) ?> solicitud(es)</span>
    </form>
  </div>

  <?php if (!$solicitudes): ?>
    <?= empty_state('Sin solicitudes', 'No hay solicitudes de vacaciones o licencias con los filtros actuales.', 'sun',
        can('rrhh_vacaciones.crear') ? btn_nuevo('vac:new', 'Nueva solicitud') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Empleado</th>
            <th>Tipo</th>
            <th>Subtipo</th>
            <th>Periodo</th>
            <th class="text-center">Días</th>
            <th class="text-center">Con goce</th>
            <th>Estado</th>
            <?php if ($puedeAprobar): ?><th class="text-right">Acciones</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($solicitudes as $s): ?>
            <?php $nombreEmp = $s['emp_nombre'] . ' ' . $s['emp_apellido']; ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <?= avatar($nombreEmp) ?>
                  <span class="font-semibold text-slate-700"><?= e($nombreEmp) ?></span>
                </div>
              </td>
              <td><?= $s['tipo'] === 'vacaciones' ? badge('Vacaciones', 'indigo') : badge('Licencia', 'violet') ?></td>
              <td class="text-slate-500"><?= e($s['subtipo'] ? ucfirst($s['subtipo']) : '—') ?></td>
              <td class="text-slate-600 whitespace-nowrap"><?= e(fechaCorta($s['fecha_desde'])) ?> <span class="text-slate-300">→</span> <?= e(fechaCorta($s['fecha_hasta'])) ?></td>
              <td class="text-center"><span class="badge badge-slate"><?= (int) $s['dias'] ?></span></td>
              <td class="text-center"><?= $s['con_goce'] ? '<span class="text-emerald-600 font-medium">Sí</span>' : '<span class="text-slate-400">No</span>' ?></td>
              <td>
                <?= badge(ucfirst($s['estado']), $colorEstado[$s['estado']] ?? 'slate') ?>
                <?php if ($s['estado'] !== 'solicitada' && $s['aprob_nombre']): ?>
                  <div class="text-xs text-slate-400 mt-1">por <?= e($s['aprob_nombre'] . ' ' . $s['aprob_apellido']) ?></div>
                <?php endif; ?>
              </td>
              <?php if ($puedeAprobar): ?>
                <td>
                  <div class="flex items-center justify-end gap-1">
                    <?php if ($s['estado'] === 'solicitada'): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Aprobar esta solicitud de <?= e($nombreEmp) ?>?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="aprobar">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button class="btn btn-sm btn-success" title="Aprobar"><?= icon('check', 'w-4 h-4') ?> Aprobar</button>
                      </form>
                      <form method="post" class="inline" onsubmit="return confirm('¿Rechazar esta solicitud de <?= e($nombreEmp) ?>?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="rechazar">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button class="btn btn-sm btn-danger" title="Rechazar"><?= icon('x', 'w-4 h-4') ?> Rechazar</button>
                      </form>
                    <?php else: ?>
                      <span class="text-xs text-slate-300">—</span>
                    <?php endif; ?>
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

<?php if (can('rrhh_vacaciones.crear')): ?>
<!-- Modal nueva solicitud -->
<div x-data="{open:false, form:{empleado_id:'', tipo:'vacaciones', subtipo:'', fecha_desde:'', fecha_hasta:'', con_goce:1, motivo:''}}"
     @vac:new.window="form={empleado_id:'', tipo:'vacaciones', subtipo:'', fecha_desde:'', fecha_hasta:'', con_goce:1, motivo:''}; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nueva solicitud</h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Empleado *</label>
            <select name="empleado_id" x-model="form.empleado_id" required class="select">
              <option value="" disabled>Selecciona un empleado</option>
              <?php foreach ($empleadosLista as $el): ?>
                <option value="<?= (int) $el['id'] ?>"><?= e($el['nombre'] . ' ' . $el['apellido']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Tipo *</label>
              <select name="tipo" x-model="form.tipo" class="select">
                <option value="vacaciones">Vacaciones</option>
                <option value="licencia">Licencia</option>
              </select>
            </div>
            <div x-show="form.tipo === 'licencia'">
              <label class="label">Subtipo</label>
              <select name="subtipo" x-model="form.subtipo" class="select">
                <option value="">—</option>
                <option value="enfermedad">Enfermedad</option>
                <option value="personal">Personal</option>
                <option value="maternidad">Maternidad</option>
                <option value="duelo">Duelo</option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Desde *</label>
              <input type="date" name="fecha_desde" x-model="form.fecha_desde" required class="input">
            </div>
            <div>
              <label class="label">Hasta *</label>
              <input type="date" name="fecha_hasta" x-model="form.fecha_hasta" required class="input">
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="con_goce" value="0">
            <input type="checkbox" name="con_goce" value="1" :checked="form.con_goce==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Con goce de sueldo
          </label>
          <div>
            <label class="label">Motivo</label>
            <textarea name="motivo" x-model="form.motivo" rows="2" class="input" placeholder="Opcional"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar solicitud</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
