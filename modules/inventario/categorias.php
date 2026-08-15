<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('categorias.ver');

$colores = ['blue', 'emerald', 'amber', 'rose', 'indigo', 'cyan', 'sky', 'pink', 'violet', 'slate'];

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id     = postInt('id');
        $nombre = trim(post('nombre'));
        $desc   = trim(post('descripcion'));
        $color  = in_array(post('color'), $colores, true) ? post('color') : 'blue';
        $activo = postInt('activo', 1);

        if ($nombre === '') {
            flash('error', 'El nombre de la categoría es obligatorio.');
        } elseif (qVal("SELECT 1 FROM categorias WHERE nombre = ? AND id <> ?", [$nombre, $id])) {
            flash('error', 'Ya existe una categoría con ese nombre.');
        } else {
            if ($id > 0) {
                require_perm('categorias.editar');
                dbUpdate('categorias', ['nombre' => $nombre, 'descripcion' => $desc, 'color' => $color, 'activo' => $activo], 'id = ?', [$id]);
                audit('categorias', 'editar', "Categoría actualizada: $nombre", ['tabla' => 'categorias', 'registro_id' => $id]);
                flash('success', 'Categoría actualizada correctamente.');
            } else {
                require_perm('categorias.crear');
                $nid = dbInsert('categorias', ['nombre' => $nombre, 'descripcion' => $desc, 'color' => $color, 'activo' => $activo]);
                audit('categorias', 'crear', "Categoría creada: $nombre", ['tabla' => 'categorias', 'registro_id' => $nid]);
                flash('success', 'Categoría creada correctamente.');
            }
        }
        redirect('modules/inventario/categorias.php');
    }

    if ($accion === 'eliminar') {
        require_perm('categorias.eliminar');
        $id = postInt('id');
        $enUso = (int) qVal("SELECT COUNT(*) FROM productos WHERE categoria_id = ?", [$id]);
        if ($enUso > 0) {
            flash('error', "No se puede eliminar: $enUso producto(s) usan esta categoría.");
        } else {
            $nombre = qVal("SELECT nombre FROM categorias WHERE id = ?", [$id]);
            q("DELETE FROM categorias WHERE id = ?", [$id]);
            audit('categorias', 'eliminar', "Categoría eliminada: $nombre", ['tabla' => 'categorias', 'registro_id' => $id]);
            flash('success', 'Categoría eliminada.');
        }
        redirect('modules/inventario/categorias.php');
    }
}

// ---------- Listado ----------
$q = trim(get('q'));
$where = $q !== '' ? "WHERE c.nombre LIKE ?" : '';
$params = $q !== '' ? ['%' . $q . '%'] : [];
$cats = qAll(
    "SELECT c.*, (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id) AS productos
     FROM categorias c $where ORDER BY c.nombre",
    $params
);

// La exportación se lleva TODO lo que coincide con el filtro, no la página.
if (export_solicitado()) {
    export_tabla('categorias', ['Nombre', 'Descripción', 'Color', 'Productos', 'Estado'],
        array_map(fn($c) => [
            $c['nombre'], $c['descripcion'], $c['color'], (int) $c['productos'],
            $c['activo'] ? 'Activa' : 'Inactiva',
        ], $cats));
}

$resumen = qOne(
    "SELECT (SELECT COUNT(*) FROM categorias) total,
            (SELECT COALESCE(SUM(activo = 1), 0) FROM categorias) activas,
            (SELECT COUNT(*) FROM productos WHERE categoria_id IS NOT NULL) clasificados,
            (SELECT COUNT(*) FROM productos WHERE categoria_id IS NULL) sin_clasificar,
            (SELECT COUNT(*) FROM categorias c
              WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.categoria_id = c.id)) vacias"
) ?: ['total' => 0, 'activas' => 0, 'clasificados' => 0, 'sin_clasificar' => 0, 'vacias' => 0];

$acciones = export_buttons() . (can('categorias.crear') ? btn_nuevo('cat:new', 'Nueva categoría') : '');
layout_start('Categorías', 'Organiza tus productos diversos por categoría', $acciones);

echo kpis([
    ['label' => 'Categorías activas', 'valor' => number_format((int) $resumen['activas']), 'icono' => 'tag', 'color' => 'blue',
     'nota' => (int) $resumen['vacias'] > 0
        ? number_format((int) $resumen['vacias']) . ' sin ningún producto' : 'Todas tienen productos'],
    ['label' => 'Productos clasificados', 'valor' => number_format((int) $resumen['clasificados']), 'icono' => 'box', 'color' => 'emerald'],
    // Un producto sin categoría se pierde: no sale en los filtros del catálogo
    // ni en los informes que agrupan por categoría. Por eso se cuenta aparte.
    ['label' => 'Sin categoría', 'valor' => number_format((int) $resumen['sin_clasificar']),
     'icono' => 'alert', 'color' => (int) $resumen['sin_clasificar'] > 0 ? 'amber' : 'slate',
     'nota' => (int) $resumen['sin_clasificar'] > 0
        ? 'No aparecen al filtrar por categoría'
        : 'Todo el catálogo está clasificado'],
], 3);
?>

<div class="card overflow-hidden">
  <?= toolbar(search_box('Buscar categoría...'), toolbar_conteo(count($cats), 'categoría', 'categorías')) ?>

  <?php if (!$cats): ?>
    <?= empty_state('Sin categorías', 'Crea tu primera categoría para clasificar los productos.', 'tag',
        can('categorias.crear') ? btn_nuevo('cat:new', 'Nueva categoría') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Categoría</th><th>Descripción</th><th class="text-center">Productos</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($cats as $c): ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <span class="w-9 h-9 rounded-lg badge-<?= e($c['color']) ?> flex items-center justify-center"><?= icon('tag', 'w-4 h-4') ?></span>
                  <span class="font-semibold text-slate-700"><?= e($c['nombre']) ?></span>
                </div>
              </td>
              <td class="text-slate-500 max-w-xs truncate"><?= e($c['descripcion'] ?: '—') ?></td>
              <td class="text-center"><span class="badge badge-slate"><?= (int) $c['productos'] ?></span></td>
              <td><?= $c['activo'] ? badge('Activa', 'emerald') : badge('Inactiva', 'slate') ?></td>
              <td>
                <?= acciones([
                    (int) $c['productos'] > 0 ? btn_icono([
                        'icono' => 'box', 'titulo' => 'Ver los productos de esta categoría', 'color' => 'slate',
                        'href' => url('modules/inventario/productos.php?categoria_id=' . (int) $c['id']),
                    ]) : '',
                    can('categorias.editar') ? btn_icono([
                        'icono' => 'edit', 'titulo' => 'Editar categoría',
                        'onclick' => jsEvent('cat:edit', ['id' => $c['id'], 'nombre' => $c['nombre'], 'descripcion' => $c['descripcion'], 'color' => $c['color'], 'activo' => $c['activo']]),
                    ]) : '',
                    can('categorias.eliminar') ? btn_eliminar([
                        'id' => (int) $c['id'], 'titulo' => 'Eliminar categoría',
                        'pregunta' => '¿Eliminar la categoría «' . $c['nombre'] . '»?',
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

<!-- Modal crear/editar -->
<div x-data="{open:false, form:{id:0,nombre:'',descripcion:'',color:'blue',activo:1}}"
     @cat:new.window="form={id:0,nombre:'',descripcion:'',color:'blue',activo:1}; open=true"
     @cat:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar categoría' : 'Nueva categoría'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Bebidas">
          </div>
          <div>
            <label class="label">Descripción</label>
            <textarea name="descripcion" x-model="form.descripcion" rows="2" class="input" placeholder="Opcional"></textarea>
          </div>
          <div>
            <label class="label">Color de identificación</label>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($colores as $col): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="color" value="<?= $col ?>" x-model="form.color" class="sr-only peer">
                  <span class="w-8 h-8 rounded-lg badge-<?= $col ?> flex items-center justify-center ring-2 ring-transparent peer-checked:ring-slate-800 transition">
                    <span class="w-3 h-3 rounded-full bg-current opacity-70"></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Categoría activa
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
