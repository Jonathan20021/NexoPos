<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('usuarios.ver');

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id       = postInt('id');
        $nombre   = trim(post('nombre'));
        $apellido = trim(post('apellido'));
        $usuario  = trim(post('usuario'));
        $email    = trim(post('email'));
        $telefono = trim(post('telefono'));
        $rolId    = postInt('rol_id');
        $sucId    = postInt('sucursal_id');           // 0 = "Todas" -> NULL
        $password = (string) post('password');
        $activo   = postInt('activo', 1);
        $comisionPct = postNum('comision_pct');

        if ($nombre === '' || $apellido === '' || $usuario === '' || $email === '') {
            flash('error', 'Nombre, apellido, usuario y email son obligatorios.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'El correo electrónico no es válido.');
        } elseif ($rolId <= 0 || !qVal("SELECT 1 FROM roles WHERE id = ?", [$rolId])) {
            flash('error', 'Debes seleccionar un rol válido.');
        } elseif ($sucId > 0 && !qVal("SELECT 1 FROM sucursales WHERE id=? AND activo=1", [$sucId])) {
            flash('error', 'La sucursal seleccionada no es válida.');
        } elseif ($comisionPct < 0 || $comisionPct > 100) {
            flash('error', 'La comisión debe estar entre 0% y 100%.');
        } elseif (qVal("SELECT 1 FROM usuarios WHERE usuario = ? AND id <> ?", [$usuario, $id])) {
            flash('error', 'Ya existe un usuario con ese nombre de usuario.');
        } elseif (qVal("SELECT 1 FROM usuarios WHERE email = ? AND id <> ?", [$email, $id])) {
            flash('error', 'Ya existe un usuario con ese email.');
        } elseif (($id === 0 || $password !== '') && strlen($password) < 6) {
            flash('error', 'La contraseña debe tener al menos 6 caracteres.');
        } else {
            $datos = [
                'nombre'      => $nombre,
                'apellido'    => $apellido,
                'usuario'     => $usuario,
                'email'       => $email,
                'telefono'    => $telefono ?: null,
                'rol_id'      => $rolId,
                'sucursal_id' => $sucId > 0 ? $sucId : null,
                'comision_pct' => $comisionPct,
                'activo'      => $activo,
            ];
            if ($id > 0) {
                require_perm('usuarios.editar');
                if ($password !== '') {
                    $datos['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                dbUpdate('usuarios', $datos, 'id = ?', [$id]);
                audit('usuarios', 'editar', "Usuario actualizado: $usuario", ['tabla' => 'usuarios', 'registro_id' => $id]);
                flash('success', 'Usuario actualizado correctamente.');
            } else {
                require_perm('usuarios.crear');
                $datos['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $nid = dbInsert('usuarios', $datos);
                audit('usuarios', 'crear', "Usuario creado: $usuario", ['tabla' => 'usuarios', 'registro_id' => $nid]);
                flash('success', 'Usuario creado correctamente.');
            }
        }
        redirect('modules/admin/usuarios.php');
    }

    if ($accion === 'eliminar') {
        require_perm('usuarios.eliminar');
        $id = postInt('id');
        $yo = current_user()['id'] ?? 0;
        if ($id === (int) $yo) {
            flash('error', 'No puedes eliminar tu propio usuario.');
        } else {
            $usuario = qVal("SELECT usuario FROM usuarios WHERE id = ?", [$id]);
            if ($usuario === null) {
                flash('error', 'El usuario no existe.');
            } else {
                $tieneHistorial = qVal("SELECT 1 FROM ventas WHERE usuario_id=? LIMIT 1", [$id])
                    || qVal("SELECT 1 FROM caja_sesiones WHERE usuario_id=? LIMIT 1", [$id])
                    || qVal("SELECT 1 FROM empleados WHERE usuario_id=? LIMIT 1", [$id]);
                if ($tieneHistorial) {
                    dbUpdate('usuarios', ['activo' => 0], 'id=?', [$id]);
                    audit('usuarios', 'editar', "Usuario desactivado para conservar historial: $usuario", ['tabla' => 'usuarios', 'registro_id' => $id]);
                    flash('warning', 'El usuario tiene operaciones registradas; se desactivó en lugar de eliminarlo.');
                } else {
                    q("DELETE FROM usuarios WHERE id = ?", [$id]);
                    audit('usuarios', 'eliminar', "Usuario eliminado: $usuario", ['tabla' => 'usuarios', 'registro_id' => $id]);
                    flash('success', 'Usuario eliminado.');
                }
            }
        }
        redirect('modules/admin/usuarios.php');
    }
}

// ---------- Datos para selects ----------
$roles     = qAll("SELECT id, nombre FROM roles WHERE activo = 1 ORDER BY nombre");
$sucursalesOpts = qAll("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");
$miId      = (int) (current_user()['id'] ?? 0);

// ---------- Listado ----------
$q = trim(get('q'));
$where = $q !== '' ? "WHERE (u.nombre LIKE ? OR u.apellido LIKE ? OR u.usuario LIKE ? OR u.email LIKE ?)" : '';
$params = $q !== '' ? array_fill(0, 4, '%' . $q . '%') : [];
$usuarios = qAll(
    "SELECT u.*, r.nombre AS rol_nombre, s.nombre AS sucursal_nombre
     FROM usuarios u
     JOIN roles r ON r.id = u.rol_id
     LEFT JOIN sucursales s ON s.id = u.sucursal_id
     $where ORDER BY u.nombre, u.apellido",
    $params
);

$acciones = can('usuarios.crear') ? btn_nuevo('usr:new', 'Nuevo usuario') : '';
layout_start('Usuarios', 'Gestiona el acceso del personal al sistema', $acciones);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por nombre, usuario o email...') ?>
    <span class="text-sm text-slate-400"><?= count($usuarios) ?> usuarios</span>
  </div>

  <?php if (!$usuarios): ?>
    <?= empty_state('Sin usuarios', 'Crea el primer usuario del sistema.', 'users',
        can('usuarios.crear') ? btn_nuevo('usr:new', 'Nuevo usuario') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Usuario</th><th>Acceso</th><th>Email</th><th>Rol</th><th>Sucursal</th><th>Último acceso</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($usuarios as $u): ?>
            <?php $nombreCompleto = trim($u['nombre'] . ' ' . $u['apellido']); ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <?= avatar($nombreCompleto) ?>
                  <span class="font-semibold text-slate-700"><?= e($nombreCompleto) ?></span>
                </div>
              </td>
              <td><span class="font-mono text-sm text-slate-600"><?= e($u['usuario']) ?></span></td>
              <td class="text-slate-500"><?= e($u['email']) ?></td>
              <td><?= badge($u['rol_nombre'], 'indigo') ?></td>
              <td class="text-slate-500"><?= $u['sucursal_id'] ? e($u['sucursal_nombre']) : '<span class="text-slate-400 italic">Todas</span>' ?></td>
              <td class="text-slate-500 text-sm"><?= $u['ultimo_acceso'] ? e(fechaHora($u['ultimo_acceso'])) : '—' ?></td>
              <td><?= $u['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('usuarios.editar')): ?>
                    <button onclick="<?= jsEvent('usr:edit', ['id' => $u['id'], 'nombre' => $u['nombre'], 'apellido' => $u['apellido'], 'usuario' => $u['usuario'], 'email' => $u['email'], 'telefono' => $u['telefono'], 'rol_id' => $u['rol_id'], 'sucursal_id' => $u['sucursal_id'] ?? 0, 'comision_pct' => $u['comision_pct'], 'activo' => $u['activo']]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('usuarios.eliminar') && (int) $u['id'] !== $miId): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar el usuario «<?= e($u['usuario']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
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
<div x-data="{open:false, form:{id:0,nombre:'',apellido:'',usuario:'',email:'',telefono:'',rol_id:'',sucursal_id:0,activo:1}}"
     @usr:new.window="form={id:0,nombre:'',apellido:'',usuario:'',email:'',telefono:'',rol_id:'',sucursal_id:0,comision_pct:0,activo:1}; open=true"
     @usr:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 sticky top-0 bg-white">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar usuario' : 'Nuevo usuario'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input">
          </div>
          <div>
            <label class="label">Apellido *</label>
            <input type="text" name="apellido" x-model="form.apellido" required class="input">
          </div>
          <div>
            <label class="label">Usuario *</label>
            <input type="text" name="usuario" x-model="form.usuario" required class="input" autocomplete="off">
          </div>
          <div>
            <label class="label">Email *</label>
            <input type="email" name="email" x-model="form.email" required class="input" autocomplete="off">
          </div>
          <div>
            <label class="label">Teléfono</label>
            <input type="text" name="telefono" x-model="form.telefono" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Comisión por ventas (%)</label>
            <input type="number" step="0.01" min="0" name="comision_pct" x-model="form.comision_pct" class="input" placeholder="0.00">
          </div>
          <div>
            <label class="label">Rol *</label>
            <select name="rol_id" x-model="form.rol_id" required class="select">
              <option value="">Seleccione…</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>"><?= e($r['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Sucursal</label>
            <select name="sucursal_id" x-model="form.sucursal_id" class="select">
              <option value="0">Todas las sucursales</option>
              <?php foreach ($sucursalesOpts as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Contraseña <span x-show="form.id" class="text-slate-400 font-normal">(dejar vacío = no cambiar)</span><span x-show="!form.id"> *</span></label>
            <input type="password" name="password" class="input" autocomplete="new-password" :required="!form.id" placeholder="••••••••">
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Usuario activo
          </label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 sticky bottom-0 bg-white">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
