<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_empleados.ver');

$generos        = ['M', 'F', 'O'];
$tiposContrato  = ['indefinido', 'temporal', 'por_obra'];
$metodosPago    = ['efectivo', 'transferencia', 'cheque'];
$estados        = ['activo', 'inactivo', 'vacaciones', 'licencia'];

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id            = postInt('id');
        $nombre        = trim(post('nombre'));
        $apellido      = trim(post('apellido'));
        $cedula        = trim(post('cedula'));
        $fechaNac      = trim(post('fecha_nacimiento'));
        $genero        = in_array(post('genero'), $generos, true) ? post('genero') : null;
        $telefono      = trim(post('telefono'));
        $email         = trim(post('email'));
        $direccion     = trim(post('direccion'));
        $sucursalId    = postInt('sucursal_id') ?: null;
        $departamentoId = postInt('departamento_id') ?: null;
        $puestoId      = postInt('puesto_id') ?: null;
        $fechaIngreso  = trim(post('fecha_ingreso'));
        $tipoContrato  = in_array(post('tipo_contrato'), $tiposContrato, true) ? post('tipo_contrato') : 'indefinido';
        $salario       = postNum('salario');
        $metodoPago    = in_array(post('metodo_pago'), $metodosPago, true) ? post('metodo_pago') : 'efectivo';
        $banco         = trim(post('banco'));
        $cuenta        = trim(post('cuenta_bancaria'));
        $estado        = in_array(post('estado'), $estados, true) ? post('estado') : 'activo';
        $dep = $departamentoId ? qOne("SELECT id, sucursal_id FROM departamentos WHERE id=? AND activo=1", [$departamentoId]) : null;
        $puesto = $puestoId ? qOne("SELECT id, departamento_id FROM puestos WHERE id=? AND activo=1", [$puestoId]) : null;

        if ($id > 0) {
            $sucActual = qVal("SELECT sucursal_id FROM empleados WHERE id = ?", [$id]);
            if (!can_access_sucursal($sucActual)) deny_access();
        }
        if (!can_access_sucursal($sucursalId)) deny_access();

        if ($nombre === '' || $apellido === '' || $cedula === '') {
            flash('error', 'Nombre, apellido y cédula son obligatorios.');
        } elseif ($fechaIngreso === '') {
            flash('error', 'La fecha de ingreso es obligatoria.');
        } elseif ($salario < 0) {
            flash('error', 'El salario no puede ser negativo.');
        } elseif ($fechaNac !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaNac)) {
            flash('error', 'La fecha de nacimiento no es válida.');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaIngreso)) {
            flash('error', 'La fecha de ingreso no es válida.');
        } elseif (($departamentoId && !$dep) || ($dep && $dep['sucursal_id'] !== null && !can_access_sucursal($dep['sucursal_id']))) {
            flash('error', 'El departamento seleccionado no es válido para esta sucursal.');
        } elseif ($puestoId && (!$puesto || !$departamentoId || (int) $puesto['departamento_id'] !== $departamentoId)) {
            flash('error', 'El puesto seleccionado no corresponde al departamento.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'El correo electrónico no es válido.');
        } elseif (qVal("SELECT 1 FROM empleados WHERE cedula = ? AND id <> ?", [$cedula, $id])) {
            flash('error', 'Ya existe un empleado con esa cédula.');
        } else {
            $datos = [
                'nombre'           => $nombre,
                'apellido'         => $apellido,
                'cedula'           => $cedula,
                'fecha_nacimiento' => $fechaNac ?: null,
                'genero'           => $genero,
                'telefono'         => $telefono ?: null,
                'email'            => $email ?: null,
                'direccion'        => $direccion ?: null,
                'sucursal_id'      => $sucursalId,
                'departamento_id'  => $departamentoId,
                'puesto_id'        => $puestoId,
                'fecha_ingreso'    => $fechaIngreso,
                'tipo_contrato'    => $tipoContrato,
                'salario'          => $salario,
                'metodo_pago'      => $metodoPago,
                'banco'            => $banco ?: null,
                'cuenta_bancaria'  => $cuenta ?: null,
                'estado'           => $estado,
            ];
            if ($id > 0) {
                require_perm('rrhh_empleados.editar');
                dbUpdate('empleados', $datos, 'id = ?', [$id]);
                audit('rrhh_empleados', 'editar', "Empleado actualizado: $nombre $apellido", ['tabla' => 'empleados', 'registro_id' => $id]);
                flash('success', 'Empleado actualizado correctamente.');
            } else {
                require_perm('rrhh_empleados.crear');
                $datos['codigo'] = nextNumero('empleados', 'codigo', 'EMP', 4);
                $nid = dbInsert('empleados', $datos);
                audit('rrhh_empleados', 'crear', "Empleado creado: $nombre $apellido", ['tabla' => 'empleados', 'registro_id' => $nid]);
                flash('success', 'Empleado creado correctamente.');
            }
        }
        redirect('modules/rrhh/empleados.php');
    }

    if ($accion === 'eliminar') {
        require_perm('rrhh_empleados.eliminar');
        $id = postInt('id');
        $empEliminar = qOne("SELECT CONCAT(nombre,' ',apellido) AS nombre, sucursal_id FROM empleados WHERE id = ?", [$id]);
        if (!$empEliminar || !can_access_sucursal($empEliminar['sucursal_id'])) deny_access();
        $nombre = $empEliminar['nombre'];
        $tieneHistorial = qVal("SELECT 1 FROM nomina_detalles WHERE empleado_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM asistencias WHERE empleado_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM vacaciones WHERE empleado_id=? LIMIT 1", [$id]);
        if ($tieneHistorial) {
            dbUpdate('empleados', ['estado' => 'inactivo'], 'id=?', [$id]);
            audit('rrhh_empleados', 'editar', "Empleado desactivado para conservar historial: $nombre", ['tabla' => 'empleados', 'registro_id' => $id]);
            flash('warning', 'El empleado tiene historial; se marcó como inactivo en lugar de eliminarlo.');
        } else {
            q("DELETE FROM empleados WHERE id = ?", [$id]);
            audit('rrhh_empleados', 'eliminar', "Empleado eliminado: $nombre", ['tabla' => 'empleados', 'registro_id' => $id]);
            flash('success', 'Empleado eliminado.');
        }
        redirect('modules/rrhh/empleados.php');
    }
}

// ---------- Catálogos para selects ----------
$sucursales    = sucursales_visibles();
$departamentos = qAll("SELECT id, nombre FROM departamentos WHERE activo = 1 ORDER BY nombre");
$puestos       = qAll("SELECT id, nombre, departamento_id, salario_base FROM puestos WHERE activo = 1 ORDER BY nombre");
$puestosSalario = [];
foreach ($puestos as $p) $puestosSalario[(int) $p['id']] = (float) $p['salario_base'];

// ---------- Filtro por sucursal activa ----------
[$scopeW, $scopeP] = sucursalScope('e.sucursal_id');

// ---------- KPIs ----------
$totalActivos = (int) qVal("SELECT COUNT(*) FROM empleados e WHERE e.estado = 'activo' AND $scopeW", $scopeP);
$nominaMensual = (float) qVal("SELECT COALESCE(SUM(e.salario),0) FROM empleados e WHERE e.estado = 'activo' AND $scopeW", $scopeP);
$nDepartamentos = (int) qVal("SELECT COUNT(*) FROM departamentos WHERE activo = 1");

// ---------- Listado ----------
$q      = trim(get('q'));
$depId  = (int) get('departamento_id');
$conds  = [$scopeW];
$params = $scopeP;
if ($q !== '') {
    $conds[] = "(e.nombre LIKE ? OR e.apellido LIKE ? OR e.cedula LIKE ?)";
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
if ($depId > 0) {
    $conds[] = "e.departamento_id = ?";
    $params[] = $depId;
}
$where = 'WHERE ' . implode(' AND ', $conds);
$empleados = qAll(
    "SELECT e.*, p.nombre AS puesto, d.nombre AS departamento, su.nombre AS sucursal
     FROM empleados e
     LEFT JOIN puestos p ON p.id = e.puesto_id
     LEFT JOIN departamentos d ON d.id = e.departamento_id
     LEFT JOIN sucursales su ON su.id = e.sucursal_id
     $where ORDER BY e.nombre, e.apellido",
    $params
);

if (export_solicitado()) {
    export_tabla('empleados', ['Código', 'Nombre', 'Apellido', 'Cédula', 'Puesto', 'Departamento', 'Sucursal', 'Fecha ingreso', 'Salario', 'Estado'],
        array_map(fn($e) => [$e['codigo'], $e['nombre'], $e['apellido'], $e['cedula'], $e['puesto'], $e['departamento'], $e['sucursal'], $e['fecha_ingreso'], $e['salario'], $e['estado']], $empleados));
}

$acciones = export_buttons() . (can('rrhh_empleados.crear') ? btn_nuevo('emp:new', 'Nuevo empleado') : '');
layout_start('Empleados', 'Gestiona la plantilla de personal y la nómina', $acciones);
?>

<!-- KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><?= icon('users', 'w-5 h-5') ?></div>
    <div><p class="text-sm text-slate-500">Empleados activos</p><p class="text-2xl font-extrabold text-slate-800"><?= number_format($totalActivos) ?></p></div>
  </div>
  <div class="card p-5 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><?= icon('wallet', 'w-5 h-5') ?></div>
    <div><p class="text-sm text-slate-500">Nómina mensual</p><p class="text-2xl font-extrabold text-slate-800"><?= money($nominaMensual) ?></p></div>
  </div>
  <div class="card p-5 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0"><?= icon('briefcase', 'w-5 h-5') ?></div>
    <div><p class="text-sm text-slate-500">Departamentos</p><p class="text-2xl font-extrabold text-slate-800"><?= number_format($nDepartamentos) ?></p></div>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por nombre, apellido o cédula...', $depId > 0 ? ['departamento_id' => $depId] : []) ?>
    <form method="get" class="flex items-center gap-2">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <select name="departamento_id" class="select" onchange="this.form.submit()">
        <option value="">Todos los departamentos</option>
        <?php foreach ($departamentos as $d): ?>
          <option value="<?= (int) $d['id'] ?>" <?= $depId === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="text-sm text-slate-400 whitespace-nowrap"><?= count($empleados) ?> empleados</span>
    </form>
  </div>

  <?php if (!$empleados): ?>
    <?= empty_state('Sin empleados', 'Registra tu primer empleado para administrar la nómina.', 'users',
        can('rrhh_empleados.crear') ? btn_nuevo('emp:new', 'Nuevo empleado') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Empleado</th><th>Cédula</th><th>Puesto</th><th>Departamento</th><th>Sucursal</th><th class="text-right">Salario</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($empleados as $e): ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <?= avatar($e['nombre'] . ' ' . $e['apellido']) ?>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-700 truncate"><?= e($e['nombre'] . ' ' . $e['apellido']) ?></p>
                    <p class="text-xs text-slate-400 font-mono"><?= e($e['codigo']) ?></p>
                  </div>
                </div>
              </td>
              <td class="text-slate-500"><?= e($e['cedula']) ?></td>
              <td class="text-slate-500"><?= e($e['puesto'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($e['departamento'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($e['sucursal'] ?: '—') ?></td>
              <td class="text-right font-bold text-slate-800"><?= money($e['salario']) ?></td>
              <td><?= badgeFor($e['estado']) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('rrhh_empleados.editar')): ?>
                    <button onclick="<?= jsEvent('emp:edit', [
                        'id' => $e['id'], 'nombre' => $e['nombre'], 'apellido' => $e['apellido'], 'cedula' => $e['cedula'],
                        'fecha_nacimiento' => $e['fecha_nacimiento'], 'genero' => $e['genero'] ?? '', 'telefono' => $e['telefono'], 'email' => $e['email'],
                        'direccion' => $e['direccion'], 'sucursal_id' => $e['sucursal_id'] ?? '', 'departamento_id' => $e['departamento_id'] ?? '',
                        'puesto_id' => $e['puesto_id'] ?? '', 'fecha_ingreso' => $e['fecha_ingreso'], 'tipo_contrato' => $e['tipo_contrato'],
                        'salario' => $e['salario'], 'metodo_pago' => $e['metodo_pago'], 'banco' => $e['banco'], 'cuenta_bancaria' => $e['cuenta_bancaria'],
                        'estado' => $e['estado'],
                    ]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('rrhh_empleados.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar al empleado «<?= e($e['nombre'] . ' ' . $e['apellido']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
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

<!-- Modal crear/editar -->
<div x-data="{
        open:false,
        salarios: <?= e(json_encode($puestosSalario, JSON_UNESCAPED_UNICODE)) ?>,
        form:{id:0,nombre:'',apellido:'',cedula:'',fecha_nacimiento:'',genero:'',telefono:'',email:'',direccion:'',sucursal_id:'',departamento_id:'',puesto_id:'',fecha_ingreso:'<?= date('Y-m-d') ?>',tipo_contrato:'indefinido',salario:0,metodo_pago:'efectivo',banco:'',cuenta_bancaria:'',estado:'activo'},
        sugerirSalario(){ const s = this.salarios[this.form.puesto_id]; if (s && (!this.form.salario || parseFloat(this.form.salario) === 0)) this.form.salario = s; }
     }"
     @emp:new.window="form={id:0,nombre:'',apellido:'',cedula:'',fecha_nacimiento:'',genero:'',telefono:'',email:'',direccion:'',sucursal_id:'',departamento_id:'',puesto_id:'',fecha_ingreso:'<?= date('Y-m-d') ?>',tipo_contrato:'indefinido',salario:0,metodo_pago:'efectivo',banco:'',cuenta_bancaria:'',estado:'activo'}; open=true"
     @emp:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar empleado' : 'Nuevo empleado'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. María">
          </div>
          <div>
            <label class="label">Apellido *</label>
            <input type="text" name="apellido" x-model="form.apellido" required class="input" placeholder="Ej. Rodríguez">
          </div>
          <div>
            <label class="label">Cédula *</label>
            <input type="text" name="cedula" x-model="form.cedula" required class="input" placeholder="000-0000000-0">
          </div>
          <div>
            <label class="label">Fecha de nacimiento</label>
            <input type="date" name="fecha_nacimiento" x-model="form.fecha_nacimiento" class="input">
          </div>
          <div>
            <label class="label">Género</label>
            <select name="genero" x-model="form.genero" class="select">
              <option value="">—</option>
              <option value="M">Masculino</option>
              <option value="F">Femenino</option>
              <option value="O">Otro</option>
            </select>
          </div>
          <div>
            <label class="label">Teléfono</label>
            <input type="text" name="telefono" x-model="form.telefono" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Email</label>
            <input type="email" name="email" x-model="form.email" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Sucursal</label>
            <select name="sucursal_id" x-model="form.sucursal_id" class="select">
              <option value="">Sin asignar</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="label">Dirección</label>
            <input type="text" name="direccion" x-model="form.direccion" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Departamento</label>
            <select name="departamento_id" x-model="form.departamento_id" class="select">
              <option value="">Sin asignar</option>
              <?php foreach ($departamentos as $d): ?>
                <option value="<?= (int) $d['id'] ?>"><?= e($d['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Puesto</label>
            <select name="puesto_id" x-model="form.puesto_id" @change="sugerirSalario()" class="select">
              <option value="">Sin asignar</option>
              <?php foreach ($puestos as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Fecha de ingreso *</label>
            <input type="date" name="fecha_ingreso" x-model="form.fecha_ingreso" required class="input">
          </div>
          <div>
            <label class="label">Tipo de contrato</label>
            <select name="tipo_contrato" x-model="form.tipo_contrato" class="select">
              <option value="indefinido">Indefinido</option>
              <option value="temporal">Temporal</option>
              <option value="por_obra">Por obra</option>
            </select>
          </div>
          <div>
            <label class="label">Salario (RD$) *</label>
            <input type="number" step="0.01" min="0" name="salario" x-model="form.salario" required class="input" placeholder="0.00">
          </div>
          <div>
            <label class="label">Método de pago</label>
            <select name="metodo_pago" x-model="form.metodo_pago" class="select">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="cheque">Cheque</option>
            </select>
          </div>
          <div>
            <label class="label">Banco</label>
            <input type="text" name="banco" x-model="form.banco" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Cuenta bancaria</label>
            <input type="text" name="cuenta_bancaria" x-model="form.cuenta_bancaria" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Estado</label>
            <select name="estado" x-model="form.estado" class="select">
              <option value="activo">Activo</option>
              <option value="inactivo">Inactivo</option>
              <option value="vacaciones">Vacaciones</option>
              <option value="licencia">Licencia</option>
            </select>
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

<?php layout_end(); ?>
