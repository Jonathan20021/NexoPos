<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('proveedores.ver');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = postInt('id');
        $nombre = trim(post('nombre'));
        if ($nombre === '') {
            flash('error', 'El nombre es obligatorio.');
        } else {
            $data = [
                'nombre' => $nombre, 'rnc' => trim(post('rnc')) ?: null, 'contacto' => trim(post('contacto')) ?: null,
                'telefono' => trim(post('telefono')) ?: null, 'email' => trim(post('email')) ?: null,
                'direccion' => trim(post('direccion')) ?: null, 'activo' => postInt('activo', 0) ? 1 : 0,
            ];
            if ($id > 0) {
                require_perm('proveedores.editar');
                dbUpdate('proveedores', $data, 'id = ?', [$id]);
                audit('proveedores', 'editar', "Proveedor actualizado: $nombre", ['tabla' => 'proveedores', 'registro_id' => $id]);
                flash('success', 'Proveedor actualizado.');
            } else {
                require_perm('proveedores.crear');
                $data['codigo'] = nextNumero('proveedores', 'codigo', 'PRV', 3);
                $nid = dbInsert('proveedores', $data);
                audit('proveedores', 'crear', "Proveedor creado: $nombre", ['tabla' => 'proveedores', 'registro_id' => $nid]);
                flash('success', 'Proveedor creado.');
            }
        }
        redirect('modules/inventario/proveedores.php');
    }
    if ($accion === 'eliminar') {
        require_perm('proveedores.eliminar');
        $id = postInt('id');
        if (qVal("SELECT 1 FROM compras WHERE proveedor_id = ? LIMIT 1", [$id])) {
            flash('error', 'No se puede eliminar: el proveedor tiene compras registradas.');
        } else {
            $nombre = qVal("SELECT nombre FROM proveedores WHERE id = ?", [$id]);
            q("DELETE FROM proveedores WHERE id = ?", [$id]);
            audit('proveedores', 'eliminar', "Proveedor eliminado: $nombre", ['tabla' => 'proveedores', 'registro_id' => $id]);
            flash('success', 'Proveedor eliminado.');
        }
        redirect('modules/inventario/proveedores.php');
    }
}

$q = trim(get('q'));
$where = $q !== '' ? "WHERE nombre LIKE ? OR rnc LIKE ? OR contacto LIKE ?" : '';
$params = $q !== '' ? ["%$q%", "%$q%", "%$q%"] : [];
$pg = paginar((int) qVal("SELECT COUNT(*) FROM proveedores $where", $params), 25);
$proveedores = qAll("SELECT * FROM proveedores $where ORDER BY nombre LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $params);

$acciones = can('proveedores.crear') ? btn_nuevo('prov:new', 'Nuevo proveedor') : '';
layout_start('Proveedores', 'Gestiona tus suplidores de mercancía', $acciones);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar proveedor...') ?>
    <span class="text-sm text-slate-400"><?= number_format($pg['total']) ?> proveedores</span>
  </div>
  <?php if (!$proveedores): ?>
    <?= empty_state('Sin proveedores', 'Registra tus suplidores para gestionar compras.', 'briefcase', can('proveedores.crear') ? btn_nuevo('prov:new', 'Nuevo proveedor') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Proveedor</th><th>RNC</th><th>Contacto</th><th>Teléfono</th><th>Email</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($proveedores as $p): ?>
            <tr>
              <td><div class="flex items-center gap-3"><span class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center"><?= icon('briefcase', 'w-4 h-4') ?></span><div><p class="font-semibold text-slate-700"><?= e($p['nombre']) ?></p><p class="text-xs text-slate-400"><?= e($p['codigo']) ?></p></div></div></td>
              <td class="text-slate-500"><?= e($p['rnc'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($p['contacto'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($p['telefono'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($p['email'] ?: '—') ?></td>
              <td><?= $p['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('proveedores.editar')): ?><button onclick="<?= jsEvent('prov:edit', ['id'=>$p['id'],'nombre'=>$p['nombre'],'rnc'=>$p['rnc'],'contacto'=>$p['contacto'],'telefono'=>$p['telefono'],'email'=>$p['email'],'direccion'=>$p['direccion'],'activo'=>$p['activo']]) ?>" aria-label="Editar proveedor" title="Editar" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50"><?= icon('edit', 'w-4 h-4') ?></button><?php endif; ?>
                  <?php if (can('proveedores.eliminar')): ?><form method="post" class="inline" onsubmit="return confirm('¿Eliminar «<?= e($p['nombre']) ?>»?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button aria-label="Eliminar proveedor" title="Eliminar" class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50"><?= icon('trash', 'w-4 h-4') ?></button></form><?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<div x-data="{open:false, form:{}}"
     @prov:new.window="form={id:0,nombre:'',rnc:'',contacto:'',telefono:'',email:'',direccion:'',activo:1}; open=true"
     @prov:edit.window="form=$event.detail; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar proveedor' : 'Nuevo proveedor'"></h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2"><label class="label">Nombre / Razón social *</label><input name="nombre" x-model="form.nombre" required class="input"></div>
          <div><label class="label">RNC</label><input name="rnc" x-model="form.rnc" class="input"></div>
          <div><label class="label">Contacto</label><input name="contacto" x-model="form.contacto" class="input"></div>
          <div><label class="label">Teléfono</label><input name="telefono" x-model="form.telefono" class="input"></div>
          <div><label class="label">Email</label><input type="email" name="email" x-model="form.email" class="input"></div>
          <div class="sm:col-span-2"><label class="label">Dirección</label><input name="direccion" x-model="form.direccion" class="input"></div>
          <label class="flex items-center gap-2 text-sm text-slate-600"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600"> Activo</label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button></div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
