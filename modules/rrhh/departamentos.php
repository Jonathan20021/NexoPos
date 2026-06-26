<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_departamentos.ver');

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    // ----- Departamentos -----
    if ($accion === 'guardar_dep') {
        $id         = postInt('id');
        $nombre     = trim(post('nombre'));
        $descripcion = trim(post('descripcion'));
        $sucursalId = postInt('sucursal_id') ?: null;
        $activo     = postInt('activo', 1);

        if ($nombre === '') {
            flash('error', 'El nombre del departamento es obligatorio.');
        } else {
            $datos = [
                'nombre'      => $nombre,
                'descripcion' => $descripcion ?: null,
                'sucursal_id' => $sucursalId,
                'activo'      => $activo,
            ];
            if ($id > 0) {
                require_perm('rrhh_departamentos.editar');
                dbUpdate('departamentos', $datos, 'id = ?', [$id]);
                audit('rrhh_departamentos', 'editar', "Departamento actualizado: $nombre", ['tabla' => 'departamentos', 'registro_id' => $id]);
                flash('success', 'Departamento actualizado correctamente.');
            } else {
                require_perm('rrhh_departamentos.crear');
                $nid = dbInsert('departamentos', $datos);
                audit('rrhh_departamentos', 'crear', "Departamento creado: $nombre", ['tabla' => 'departamentos', 'registro_id' => $nid]);
                flash('success', 'Departamento creado correctamente.');
            }
        }
        redirect('modules/rrhh/departamentos.php');
    }

    if ($accion === 'eliminar_dep') {
        require_perm('rrhh_departamentos.eliminar');
        $id = postInt('id');
        $nEmpleados = (int) qVal("SELECT COUNT(*) FROM empleados WHERE departamento_id = ?", [$id]);
        if ($nEmpleados > 0) {
            flash('error', "No se puede eliminar: $nEmpleados empleado(s) pertenecen a este departamento.");
        } else {
            $nombre = qVal("SELECT nombre FROM departamentos WHERE id = ?", [$id]);
            q("DELETE FROM departamentos WHERE id = ?", [$id]);
            audit('rrhh_departamentos', 'eliminar', "Departamento eliminado: $nombre", ['tabla' => 'departamentos', 'registro_id' => $id]);
            flash('success', 'Departamento eliminado.');
        }
        redirect('modules/rrhh/departamentos.php');
    }

    // ----- Puestos -----
    if ($accion === 'guardar_puesto') {
        $id            = postInt('id');
        $departamentoId = postInt('departamento_id') ?: null;
        $nombre        = trim(post('nombre'));
        $salarioBase   = postNum('salario_base');
        $activo        = postInt('activo', 1);

        if ($nombre === '') {
            flash('error', 'El nombre del puesto es obligatorio.');
        } else {
            $datos = [
                'departamento_id' => $departamentoId,
                'nombre'          => $nombre,
                'salario_base'    => $salarioBase,
                'activo'          => $activo,
            ];
            if ($id > 0) {
                require_perm('rrhh_departamentos.editar');
                dbUpdate('puestos', $datos, 'id = ?', [$id]);
                audit('rrhh_departamentos', 'editar', "Puesto actualizado: $nombre", ['tabla' => 'puestos', 'registro_id' => $id]);
                flash('success', 'Puesto actualizado correctamente.');
            } else {
                require_perm('rrhh_departamentos.crear');
                $nid = dbInsert('puestos', $datos);
                audit('rrhh_departamentos', 'crear', "Puesto creado: $nombre", ['tabla' => 'puestos', 'registro_id' => $nid]);
                flash('success', 'Puesto creado correctamente.');
            }
        }
        redirect('modules/rrhh/departamentos.php');
    }

    if ($accion === 'eliminar_puesto') {
        require_perm('rrhh_departamentos.eliminar');
        $id = postInt('id');
        $nEmpleados = (int) qVal("SELECT COUNT(*) FROM empleados WHERE puesto_id = ?", [$id]);
        if ($nEmpleados > 0) {
            flash('error', "No se puede eliminar: $nEmpleados empleado(s) ocupan este puesto.");
        } else {
            $nombre = qVal("SELECT nombre FROM puestos WHERE id = ?", [$id]);
            q("DELETE FROM puestos WHERE id = ?", [$id]);
            audit('rrhh_departamentos', 'eliminar', "Puesto eliminado: $nombre", ['tabla' => 'puestos', 'registro_id' => $id]);
            flash('success', 'Puesto eliminado.');
        }
        redirect('modules/rrhh/departamentos.php');
    }
}

// ---------- Datos ----------
$sucursales = qAll("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");

$departamentos = qAll(
    "SELECT d.*, su.nombre AS sucursal,
            (SELECT COUNT(*) FROM puestos p WHERE p.departamento_id = d.id) AS puestos,
            (SELECT COUNT(*) FROM empleados e WHERE e.departamento_id = d.id) AS empleados
     FROM departamentos d
     LEFT JOIN sucursales su ON su.id = d.sucursal_id
     ORDER BY d.nombre"
);

$puestos = qAll(
    "SELECT p.*, d.nombre AS departamento,
            (SELECT COUNT(*) FROM empleados e WHERE e.puesto_id = p.id) AS empleados
     FROM puestos p
     LEFT JOIN departamentos d ON d.id = p.departamento_id
     ORDER BY p.nombre"
);

$depsParaSelect = qAll("SELECT id, nombre FROM departamentos ORDER BY nombre");

layout_start('Departamentos y puestos', 'Organiza la estructura de tu personal');
?>

<div x-data="{ tab: 'departamentos' }">
  <!-- Pestañas -->
  <div class="flex items-center gap-1 mb-5 bg-slate-100 p-1 rounded-xl w-full sm:w-auto sm:inline-flex">
    <button @click="tab='departamentos'" :class="tab==='departamentos' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            class="flex-1 sm:flex-none px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center justify-center gap-2">
      <?= icon('briefcase', 'w-4 h-4') ?> Departamentos
      <span class="badge badge-slate"><?= count($departamentos) ?></span>
    </button>
    <button @click="tab='puestos'" :class="tab==='puestos' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
            class="flex-1 sm:flex-none px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center justify-center gap-2">
      <?= icon('id', 'w-4 h-4') ?> Puestos
      <span class="badge badge-slate"><?= count($puestos) ?></span>
    </button>
  </div>

  <!-- ===================== Departamentos ===================== -->
  <div x-show="tab==='departamentos'" x-transition>
    <div class="card overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h3 class="font-bold text-slate-800">Departamentos</h3>
          <p class="text-sm text-slate-400">Áreas funcionales de la empresa</p>
        </div>
        <?php if (can('rrhh_departamentos.crear')): ?>
          <button onclick="<?= jsEvent('dep:new') ?>" class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Nuevo departamento</button>
        <?php endif; ?>
      </div>

      <?php if (!$departamentos): ?>
        <?= empty_state('Sin departamentos', 'Crea el primer departamento para organizar tu personal.', 'briefcase',
            can('rrhh_departamentos.crear') ? '<button onclick="' . jsEvent('dep:new') . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nuevo departamento</button>' : '') ?>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Departamento</th><th>Descripción</th><th>Sucursal</th><th class="text-center">Puestos</th><th class="text-center">Empleados</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
            <tbody>
              <?php foreach ($departamentos as $d): ?>
                <tr>
                  <td>
                    <div class="flex items-center gap-3">
                      <span class="w-9 h-9 rounded-lg badge-blue flex items-center justify-center"><?= icon('briefcase', 'w-4 h-4') ?></span>
                      <span class="font-semibold text-slate-700"><?= e($d['nombre']) ?></span>
                    </div>
                  </td>
                  <td class="text-slate-500 max-w-xs truncate"><?= e($d['descripcion'] ?: '—') ?></td>
                  <td class="text-slate-500"><?= e($d['sucursal'] ?: 'Todas') ?></td>
                  <td class="text-center"><span class="badge badge-slate"><?= (int) $d['puestos'] ?></span></td>
                  <td class="text-center"><span class="badge badge-slate"><?= (int) $d['empleados'] ?></span></td>
                  <td><?= $d['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?></td>
                  <td>
                    <div class="flex items-center justify-end gap-1">
                      <?php if (can('rrhh_departamentos.editar')): ?>
                        <button onclick="<?= jsEvent('dep:edit', ['id' => $d['id'], 'nombre' => $d['nombre'], 'descripcion' => $d['descripcion'], 'sucursal_id' => $d['sucursal_id'] ?? '', 'activo' => $d['activo']]) ?>"
                                class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                      <?php endif; ?>
                      <?php if (can('rrhh_departamentos.eliminar')): ?>
                        <form method="post" class="inline" onsubmit="return confirm('¿Eliminar el departamento «<?= e($d['nombre']) ?>»?')">
                          <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_dep"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                          <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===================== Puestos ===================== -->
  <div x-show="tab==='puestos'" x-transition style="display:none">
    <div class="card overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h3 class="font-bold text-slate-800">Puestos</h3>
          <p class="text-sm text-slate-400">Cargos y salarios base por departamento</p>
        </div>
        <?php if (can('rrhh_departamentos.crear')): ?>
          <button onclick="<?= jsEvent('puesto:new') ?>" class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Nuevo puesto</button>
        <?php endif; ?>
      </div>

      <?php if (!$puestos): ?>
        <?= empty_state('Sin puestos', 'Crea el primer puesto para asignarlo a tus empleados.', 'id',
            can('rrhh_departamentos.crear') ? '<button onclick="' . jsEvent('puesto:new') . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nuevo puesto</button>' : '') ?>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Puesto</th><th>Departamento</th><th class="text-right">Salario base</th><th class="text-center">Empleados</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
            <tbody>
              <?php foreach ($puestos as $p): ?>
                <tr>
                  <td>
                    <div class="flex items-center gap-3">
                      <span class="w-9 h-9 rounded-lg badge-indigo flex items-center justify-center"><?= icon('id', 'w-4 h-4') ?></span>
                      <span class="font-semibold text-slate-700"><?= e($p['nombre']) ?></span>
                    </div>
                  </td>
                  <td class="text-slate-500"><?= e($p['departamento'] ?: '—') ?></td>
                  <td class="text-right font-bold text-slate-800"><?= money($p['salario_base']) ?></td>
                  <td class="text-center"><span class="badge badge-slate"><?= (int) $p['empleados'] ?></span></td>
                  <td><?= $p['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?></td>
                  <td>
                    <div class="flex items-center justify-end gap-1">
                      <?php if (can('rrhh_departamentos.editar')): ?>
                        <button onclick="<?= jsEvent('puesto:edit', ['id' => $p['id'], 'departamento_id' => $p['departamento_id'] ?? '', 'nombre' => $p['nombre'], 'salario_base' => $p['salario_base'], 'activo' => $p['activo']]) ?>"
                                class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                      <?php endif; ?>
                      <?php if (can('rrhh_departamentos.eliminar')): ?>
                        <form method="post" class="inline" onsubmit="return confirm('¿Eliminar el puesto «<?= e($p['nombre']) ?>»?')">
                          <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_puesto"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                          <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Departamento -->
<div x-data="{open:false, form:{id:0,nombre:'',descripcion:'',sucursal_id:'',activo:1}}"
     @dep:new.window="form={id:0,nombre:'',descripcion:'',sucursal_id:'',activo:1}; open=true"
     @dep:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar_dep">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar departamento' : 'Nuevo departamento'"></h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Ventas">
          </div>
          <div>
            <label class="label">Descripción</label>
            <textarea name="descripcion" x-model="form.descripcion" rows="2" class="input" placeholder="Opcional"></textarea>
          </div>
          <div>
            <label class="label">Sucursal</label>
            <select name="sucursal_id" x-model="form.sucursal_id" class="select">
              <option value="">Todas</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Departamento activo
          </label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Puesto -->
<div x-data="{open:false, form:{id:0,departamento_id:'',nombre:'',salario_base:0,activo:1}}"
     @puesto:new.window="form={id:0,departamento_id:'',nombre:'',salario_base:0,activo:1}; open=true"
     @puesto:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar_puesto">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar puesto' : 'Nuevo puesto'"></h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Cajero">
          </div>
          <div>
            <label class="label">Departamento</label>
            <select name="departamento_id" x-model="form.departamento_id" class="select">
              <option value="">Sin asignar</option>
              <?php foreach ($depsParaSelect as $d): ?>
                <option value="<?= (int) $d['id'] ?>"><?= e($d['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Salario base (RD$)</label>
            <input type="number" step="0.01" min="0" name="salario_base" x-model="form.salario_base" class="input" placeholder="0.00">
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Puesto activo
          </label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
