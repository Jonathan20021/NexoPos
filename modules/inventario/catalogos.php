<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('productos.ver');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    // ---- Marcas ----
    if ($accion === 'guardar_marca') {
        $id = postInt('id'); $nombre = trim(post('nombre'));
        if ($nombre === '') { flash('error', 'El nombre de la marca es obligatorio.'); }
        elseif (qVal("SELECT 1 FROM marcas WHERE nombre = ? AND id <> ?", [$nombre, $id])) { flash('error', 'Ya existe esa marca.'); }
        else {
            $data = ['nombre' => $nombre, 'activo' => postInt('activo', 0) ? 1 : 0];
            if ($id > 0) { require_perm('productos.editar'); dbUpdate('marcas', $data, 'id = ?', [$id]); audit('productos', 'editar', "Marca actualizada: $nombre"); flash('success', 'Marca actualizada.'); }
            else { require_perm('productos.crear'); dbInsert('marcas', $data); audit('productos', 'crear', "Marca creada: $nombre"); flash('success', 'Marca creada.'); }
        }
        redirect('modules/inventario/catalogos.php');
    }
    if ($accion === 'eliminar_marca') {
        require_perm('productos.eliminar');
        $id = postInt('id');
        if (qVal("SELECT 1 FROM productos WHERE marca_id = ? LIMIT 1", [$id])) flash('error', 'No se puede eliminar: hay productos con esta marca.');
        else { q("DELETE FROM marcas WHERE id = ?", [$id]); audit('productos', 'eliminar', "Marca eliminada #$id"); flash('success', 'Marca eliminada.'); }
        redirect('modules/inventario/catalogos.php');
    }

    // ---- Unidades ----
    if ($accion === 'guardar_unidad') {
        $id = postInt('id'); $nombre = trim(post('nombre')); $abrev = trim(post('abreviatura'));
        if ($nombre === '' || $abrev === '') { flash('error', 'Nombre y abreviatura son obligatorios.'); }
        else {
            $data = ['nombre' => $nombre, 'abreviatura' => $abrev];
            if ($id > 0) { require_perm('productos.editar'); dbUpdate('unidades', $data, 'id = ?', [$id]); audit('productos', 'editar', "Unidad actualizada: $nombre"); flash('success', 'Unidad actualizada.'); }
            else { require_perm('productos.crear'); dbInsert('unidades', $data); audit('productos', 'crear', "Unidad creada: $nombre"); flash('success', 'Unidad creada.'); }
        }
        redirect('modules/inventario/catalogos.php');
    }
    if ($accion === 'eliminar_unidad') {
        require_perm('productos.eliminar');
        $id = postInt('id');
        if (qVal("SELECT 1 FROM productos WHERE unidad_id = ? LIMIT 1", [$id])) flash('error', 'No se puede eliminar: hay productos con esta unidad.');
        else { q("DELETE FROM unidades WHERE id = ?", [$id]); audit('productos', 'eliminar', "Unidad eliminada #$id"); flash('success', 'Unidad eliminada.'); }
        redirect('modules/inventario/catalogos.php');
    }
}

$marcas = qAll("SELECT m.*, (SELECT COUNT(*) FROM productos p WHERE p.marca_id=m.id) AS productos FROM marcas m ORDER BY m.nombre");
$unidades = qAll("SELECT u.*, (SELECT COUNT(*) FROM productos p WHERE p.unidad_id=u.id) AS productos FROM unidades u ORDER BY u.nombre");
$puedeCrear = can('productos.crear');

layout_start('Marcas y Unidades', 'Catálogos base para tus productos');
?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Marcas -->
  <div class="card overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
      <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('tag', 'w-5 h-5 text-blue-600') ?> Marcas</h3>
      <?php if ($puedeCrear): ?><button onclick="<?= jsEvent('marca:new') ?>" class="btn btn-soft btn-sm"><?= icon('plus', 'w-4 h-4') ?> Nueva</button><?php endif; ?>
    </div>
    <?php if (!$marcas): ?><?= empty_state('Sin marcas', 'Agrega marcas para tus productos.', 'tag') ?><?php else: ?>
    <div class="overflow-x-auto"><table class="data-table min-w-[410px]">
      <thead><tr><th>Marca</th><th class="text-center">Productos</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($marcas as $m): ?>
        <tr>
          <td class="font-semibold text-slate-700"><?= e($m['nombre']) ?></td>
          <td class="text-center"><span class="badge badge-slate"><?= (int) $m['productos'] ?></span></td>
          <td><?= $m['activo'] ? badge('Activa', 'emerald') : badge('Inactiva', 'slate') ?></td>
          <td><div class="flex items-center justify-end gap-1">
            <?php if (can('productos.editar')): ?><button onclick="<?= jsEvent('marca:edit', ['id'=>$m['id'],'nombre'=>$m['nombre'],'activo'=>$m['activo']]) ?>" aria-label="Editar marca" title="Editar" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50"><?= icon('edit', 'w-4 h-4') ?></button><?php endif; ?>
            <?php if (can('productos.eliminar')): ?><form method="post" class="inline" onsubmit="return confirm('¿Eliminar marca?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_marca"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button aria-label="Eliminar marca" title="Eliminar" class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50"><?= icon('trash', 'w-4 h-4') ?></button></form><?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- Unidades -->
  <div class="card overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
      <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('layers', 'w-5 h-5 text-indigo-600') ?> Unidades de medida</h3>
      <?php if ($puedeCrear): ?><button onclick="<?= jsEvent('unidad:new') ?>" class="btn btn-soft btn-sm"><?= icon('plus', 'w-4 h-4') ?> Nueva</button><?php endif; ?>
    </div>
    <?php if (!$unidades): ?><?= empty_state('Sin unidades', 'Agrega unidades (Unidad, Libra, Galón...).', 'layers') ?><?php else: ?>
    <div class="overflow-x-auto"><table class="data-table min-w-[410px]">
      <thead><tr><th>Unidad</th><th>Abreviatura</th><th class="text-center">Productos</th><th class="text-right">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($unidades as $u): ?>
        <tr>
          <td class="font-semibold text-slate-700"><?= e($u['nombre']) ?></td>
          <td><span class="badge badge-indigo"><?= e($u['abreviatura']) ?></span></td>
          <td class="text-center"><span class="badge badge-slate"><?= (int) $u['productos'] ?></span></td>
          <td><div class="flex items-center justify-end gap-1">
            <?php if (can('productos.editar')): ?><button onclick="<?= jsEvent('unidad:edit', ['id'=>$u['id'],'nombre'=>$u['nombre'],'abreviatura'=>$u['abreviatura']]) ?>" aria-label="Editar unidad" title="Editar" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50"><?= icon('edit', 'w-4 h-4') ?></button><?php endif; ?>
            <?php if (can('productos.eliminar')): ?><form method="post" class="inline" onsubmit="return confirm('¿Eliminar unidad?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar_unidad"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button aria-label="Eliminar unidad" title="Eliminar" class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50"><?= icon('trash', 'w-4 h-4') ?></button></form><?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal marca -->
<div x-data="{open:false, form:{}}" @marca:new.window="form={id:0,nombre:'',activo:1}; open=true" @marca:edit.window="form=$event.detail; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-sm" @click.stop>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="guardar_marca"><input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800" x-text="form.id?'Editar marca':'Nueva marca'"></h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
        <div class="p-6 space-y-4">
          <div><label class="label">Nombre *</label><input name="nombre" x-model="form.nombre" required class="input"></div>
          <label class="flex items-center gap-2 text-sm text-slate-600"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600"> Activa</label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Modal unidad -->
<div x-data="{open:false, form:{}}" @unidad:new.window="form={id:0,nombre:'',abreviatura:''}; open=true" @unidad:edit.window="form=$event.detail; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-sm" @click.stop>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="guardar_unidad"><input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800" x-text="form.id?'Editar unidad':'Nueva unidad'"></h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
        <div class="p-6 space-y-4">
          <div><label class="label">Nombre *</label><input name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Libra"></div>
          <div><label class="label">Abreviatura *</label><input name="abreviatura" x-model="form.abreviatura" required maxlength="10" class="input" placeholder="Ej. LB"></div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button></div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
