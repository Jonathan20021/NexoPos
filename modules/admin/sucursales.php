<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('sucursales.ver');

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id        = postInt('id');
        $codigo    = trim(post('codigo'));
        $nombre    = trim(post('nombre'));
        $direccion = trim(post('direccion'));
        $telefono  = trim(post('telefono'));
        $email     = trim(post('email'));
        $encargado = trim(post('encargado'));
        $activo    = postInt('activo', 1);
        // La tienda pública usa wa.me, que exige el número solo en dígitos y con país.
        $whatsapp  = preg_replace('/\D+/', '', post('whatsapp'));
        $horario   = trim(post('horario'));
        $tiendaAct = post('tienda_activa') ? 1 : 0;

        if ($codigo === '' || $nombre === '') {
            flash('error', 'El código y el nombre son obligatorios.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'El correo electrónico no es válido.');
        } elseif (qVal("SELECT 1 FROM sucursales WHERE codigo = ? AND id <> ?", [$codigo, $id])) {
            flash('error', 'Ya existe una sucursal con ese código.');
        } elseif ($whatsapp !== '' && strlen($whatsapp) < 10) {
            flash('error', 'El WhatsApp debe incluir el código de país. Ej. 1 809 555 0101.');
        } else {
            $datos = [
                'codigo'        => $codigo,
                'nombre'        => $nombre,
                'direccion'     => $direccion ?: null,
                'telefono'      => $telefono ?: null,
                'whatsapp'      => $whatsapp ?: null,
                'horario'       => $horario ?: null,
                'email'         => $email ?: null,
                'encargado'     => $encargado ?: null,
                'activo'        => $activo,
                'tienda_activa' => $tiendaAct,
            ];
            if ($id > 0) {
                require_perm('sucursales.editar');
                dbUpdate('sucursales', $datos, 'id = ?', [$id]);
                audit('sucursales', 'editar', "Sucursal actualizada: $nombre", ['tabla' => 'sucursales', 'registro_id' => $id]);
                flash('success', 'Sucursal actualizada correctamente.');
            } else {
                require_perm('sucursales.crear');
                $nid = dbInsert('sucursales', $datos);
                audit('sucursales', 'crear', "Sucursal creada: $nombre", ['tabla' => 'sucursales', 'registro_id' => $nid]);
                flash('success', 'Sucursal creada correctamente.');
            }
        }
        redirect('modules/admin/sucursales.php');
    }

    if ($accion === 'eliminar') {
        require_perm('sucursales.eliminar');
        $id = postInt('id');
        $nUsuarios = (int) qVal("SELECT COUNT(*) FROM usuarios WHERE sucursal_id = ?", [$id]);
        $nVentas   = (int) qVal("SELECT COUNT(*) FROM ventas WHERE sucursal_id = ?", [$id]);
        $tieneHistorial = $nUsuarios > 0 || $nVentas > 0
            || qVal("SELECT 1 FROM compras WHERE sucursal_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM caja_sesiones WHERE sucursal_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM devoluciones WHERE sucursal_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM transferencias WHERE sucursal_origen_id=? OR sucursal_destino_id=? LIMIT 1", [$id, $id])
            || qVal("SELECT 1 FROM movimientos_inventario WHERE sucursal_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM pagos_clientes WHERE sucursal_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM asistencias WHERE sucursal_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM nominas WHERE sucursal_id=? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM transacciones WHERE sucursal_id=? LIMIT 1", [$id]);
        if ($tieneHistorial) {
            dbUpdate('sucursales', ['activo' => 0], 'id=?', [$id]);
            audit('sucursales', 'editar', "Sucursal desactivada para conservar historial #$id", ['tabla' => 'sucursales', 'registro_id' => $id]);
            flash('warning', 'La sucursal tiene operaciones registradas; se desactivó en lugar de eliminarla.');
        } else {
            $nombre = qVal("SELECT nombre FROM sucursales WHERE id = ?", [$id]);
            q("DELETE FROM sucursales WHERE id = ?", [$id]);
            audit('sucursales', 'eliminar', "Sucursal eliminada: $nombre", ['tabla' => 'sucursales', 'registro_id' => $id]);
            flash('success', 'Sucursal eliminada.');
        }
        redirect('modules/admin/sucursales.php');
    }
}

// ---------- Listado ----------
$q = trim(get('q'));
$where = $q !== '' ? "WHERE (s.nombre LIKE ? OR s.codigo LIKE ?)" : '';
$params = $q !== '' ? ['%' . $q . '%', '%' . $q . '%'] : [];
$sucursales = qAll(
    "SELECT s.*, (SELECT COUNT(*) FROM usuarios u WHERE u.sucursal_id = s.id) AS usuarios
     FROM sucursales s $where ORDER BY s.nombre",
    $params
);

$acciones = can('sucursales.crear') ? btn_nuevo('suc:new', 'Nueva sucursal') : '';
layout_start('Sucursales', 'Administra las sucursales de tu negocio', $acciones);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por nombre o código...') ?>
    <span class="text-sm text-slate-400"><?= count($sucursales) ?> sucursales</span>
  </div>

  <?php if (!$sucursales): ?>
    <?= empty_state('Sin sucursales', 'Crea tu primera sucursal para comenzar.', 'store',
        can('sucursales.crear') ? btn_nuevo('suc:new', 'Nueva sucursal') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Código</th><th>Nombre</th><th>Encargado</th><th>Teléfono</th><th class="text-center">Usuarios</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($sucursales as $s): ?>
            <tr>
              <td><span class="font-mono text-sm font-semibold text-slate-700"><?= e($s['codigo']) ?></span></td>
              <td>
                <div class="flex items-center gap-3">
                  <span class="w-9 h-9 rounded-lg badge-blue flex items-center justify-center"><?= icon('store', 'w-4 h-4') ?></span>
                  <span class="font-semibold text-slate-700"><?= e($s['nombre']) ?></span>
                </div>
              </td>
              <td class="text-slate-500"><?= e($s['encargado'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($s['telefono'] ?: '—') ?></td>
              <td class="text-center"><span class="badge badge-slate"><?= (int) $s['usuarios'] ?></span></td>
              <td><?= $s['activo'] ? badge('Activa', 'emerald') : badge('Inactiva', 'slate') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('sucursales.editar')): ?>
                    <button onclick="<?= jsEvent('suc:edit', ['id' => $s['id'], 'codigo' => $s['codigo'], 'nombre' => $s['nombre'], 'direccion' => $s['direccion'], 'telefono' => $s['telefono'], 'whatsapp' => $s['whatsapp'], 'horario' => $s['horario'], 'email' => $s['email'], 'encargado' => $s['encargado'], 'activo' => $s['activo'], 'tienda_activa' => $s['tienda_activa']]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('sucursales.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la sucursal «<?= e($s['nombre']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
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
<div x-data="{open:false, form:{id:0,codigo:'',nombre:'',direccion:'',telefono:'',whatsapp:'',horario:'',email:'',encargado:'',activo:1,tienda_activa:1}}"
     @suc:new.window="form={id:0,codigo:'',nombre:'',direccion:'',telefono:'',whatsapp:'',horario:'',email:'',encargado:'',activo:1,tienda_activa:1}; open=true"
     @suc:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar sucursal' : 'Nueva sucursal'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Código *</label>
            <input type="text" name="codigo" x-model="form.codigo" required class="input" placeholder="Ej. SUC-01">
          </div>
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Sucursal Principal">
          </div>
          <div class="sm:col-span-2">
            <label class="label">Dirección</label>
            <input type="text" name="direccion" x-model="form.direccion" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Teléfono</label>
            <input type="text" name="telefono" x-model="form.telefono" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Email</label>
            <input type="email" name="email" x-model="form.email" class="input" placeholder="Opcional">
          </div>
          <div class="sm:col-span-2">
            <label class="label">Encargado</label>
            <input type="text" name="encargado" x-model="form.encargado" class="input" placeholder="Opcional">
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 cursor-pointer">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Sucursal activa
          </label>

          <div class="sm:col-span-2 border-t border-slate-100 pt-4">
            <h4 class="font-bold text-slate-800 text-sm">Tienda en línea</h4>
          </div>
          <div>
            <label class="label" for="suc_whatsapp">WhatsApp</label>
            <input type="text" id="suc_whatsapp" name="whatsapp" x-model="form.whatsapp" class="input" placeholder="1 809 555 0101">
            <p class="mt-1 text-xs text-slate-500">Con código de país. Los clientes escriben aquí.</p>
          </div>
          <div>
            <label class="label" for="suc_horario">Horario</label>
            <input type="text" id="suc_horario" name="horario" x-model="form.horario" class="input" placeholder="Lun a Sáb, 8:00 AM - 8:00 PM">
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 cursor-pointer">
            <input type="checkbox" name="tienda_activa" value="1" :checked="form.tienda_activa==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Visible en la tienda pública
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
