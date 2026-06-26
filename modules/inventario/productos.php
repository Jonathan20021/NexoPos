<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('productos.ver');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id = postInt('id');
        $codigo = trim(post('codigo'));
        $nombre = trim(post('nombre'));
        $precioVenta = postNum('precio_venta');
        if ($codigo === '' || $nombre === '') {
            flash('error', 'Código y nombre son obligatorios.');
        } elseif (qVal("SELECT 1 FROM productos WHERE codigo = ? AND id <> ?", [$codigo, $id])) {
            flash('error', 'Ya existe un producto con ese código (SKU).');
        } else {
            $data = [
                'codigo' => $codigo,
                'codigo_barras' => trim(post('codigo_barras')) ?: null,
                'nombre' => $nombre,
                'descripcion' => trim(post('descripcion')) ?: null,
                'categoria_id' => postInt('categoria_id') ?: null,
                'marca_id' => postInt('marca_id') ?: null,
                'unidad_id' => postInt('unidad_id') ?: null,
                'tipo' => post('tipo') === 'servicio' ? 'servicio' : 'producto',
                'precio_compra' => postNum('precio_compra'),
                'precio_venta' => $precioVenta,
                'itbis_aplica' => postInt('itbis_aplica', 0) ? 1 : 0,
                'stock_minimo' => postNum('stock_minimo'),
                'activo' => postInt('activo', 0) ? 1 : 0,
                'imagen' => guardar_imagen('imagen', 'productos', post('imagen_actual') ?: null),
            ];
            if ($id > 0) {
                require_perm('productos.editar');
                dbUpdate('productos', $data, 'id = ?', [$id]);
                audit('productos', 'editar', "Producto actualizado: $nombre", ['tabla' => 'productos', 'registro_id' => $id]);
                flash('success', 'Producto actualizado.');
            } else {
                require_perm('productos.crear');
                $nid = tx(function () use ($data) {
                    $pid = dbInsert('productos', $data);
                    foreach (qCol("SELECT id FROM sucursales") as $sucId) {
                        dbInsert('inventario_stock', ['producto_id' => $pid, 'sucursal_id' => (int) $sucId, 'cantidad' => 0]);
                    }
                    return $pid;
                });
                audit('productos', 'crear', "Producto creado: $nombre", ['tabla' => 'productos', 'registro_id' => $nid]);
                flash('success', 'Producto creado y agregado al inventario de todas las sucursales.');
            }
        }
        redirect('modules/inventario/productos.php');
    }

    if ($accion === 'eliminar') {
        require_perm('productos.eliminar');
        $id = postInt('id');
        $nombre = qVal("SELECT nombre FROM productos WHERE id = ?", [$id]);
        if (qVal("SELECT 1 FROM venta_detalles WHERE producto_id = ? LIMIT 1", [$id])) {
            dbUpdate('productos', ['activo' => 0], 'id = ?', [$id]);
            audit('productos', 'editar', "Producto desactivado (tiene ventas): $nombre", ['tabla' => 'productos', 'registro_id' => $id]);
            flash('warning', 'El producto tiene ventas registradas; se desactivó en lugar de eliminarlo.');
        } else {
            q("DELETE FROM productos WHERE id = ?", [$id]);
            audit('productos', 'eliminar', "Producto eliminado: $nombre", ['tabla' => 'productos', 'registro_id' => $id]);
            flash('success', 'Producto eliminado.');
        }
        redirect('modules/inventario/productos.php');
    }
}

$sid = current_sucursal_id();
$stockExpr = $sid === null
    ? "(SELECT COALESCE(SUM(cantidad),0) FROM inventario_stock WHERE producto_id=p.id)"
    : "(SELECT COALESCE(SUM(cantidad),0) FROM inventario_stock WHERE producto_id=p.id AND sucursal_id=" . (int) $sid . ")";

$q = trim(get('q'));
$catFiltro = (int) get('categoria_id');
$cond = ['p.activo IN (0,1)'];
$params = [];
if ($q !== '') { $cond[] = "(p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($catFiltro > 0) { $cond[] = "p.categoria_id = ?"; $params[] = $catFiltro; }
$where = 'WHERE ' . implode(' AND ', $cond);

if (export_solicitado()) {
    $rows = qAll("SELECT p.codigo, p.codigo_barras, p.nombre, c.nombre AS categoria, m.nombre AS marca, p.precio_compra, p.precio_venta, p.stock_minimo, $stockExpr AS stock FROM productos p LEFT JOIN categorias c ON c.id=p.categoria_id LEFT JOIN marcas m ON m.id=p.marca_id $where ORDER BY p.nombre", $params);
    export_tabla('productos', ['Código', 'Cód. barras', 'Nombre', 'Categoría', 'Marca', 'Precio compra', 'Precio venta', 'Stock mínimo', 'Stock actual'],
        array_map(fn($r) => [$r['codigo'], $r['codigo_barras'], $r['nombre'], $r['categoria'], $r['marca'], $r['precio_compra'], $r['precio_venta'], $r['stock_minimo'], $r['stock']], $rows));
}

$productos = qAll(
    "SELECT p.*, c.nombre AS categoria, c.color AS cat_color, m.nombre AS marca, u.abreviatura AS unidad,
            $stockExpr AS stock
     FROM productos p
     LEFT JOIN categorias c ON c.id=p.categoria_id
     LEFT JOIN marcas m ON m.id=p.marca_id
     LEFT JOIN unidades u ON u.id=p.unidad_id
     $where ORDER BY p.nombre LIMIT 300", $params
);

$categorias = qAll("SELECT id, nombre, color FROM categorias WHERE activo=1 ORDER BY nombre");
$marcas = qAll("SELECT id, nombre FROM marcas WHERE activo=1 ORDER BY nombre");
$unidades = qAll("SELECT id, nombre, abreviatura FROM unidades ORDER BY nombre");
$sigCodigo = nextNumero('productos', 'codigo', 'SKU', 5);

$acciones = export_buttons() . (can('productos.crear') ? btn_nuevo('prod:new', 'Nuevo producto') : '');
layout_start('Productos', 'Catálogo de productos por categoría', $acciones);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <div class="flex items-center gap-2 flex-wrap">
      <?= search_box('Buscar por nombre, SKU o código de barras...', $catFiltro ? ['categoria_id' => $catFiltro] : []) ?>
      <form method="get" class="flex items-center gap-2">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <select name="categoria_id" onchange="this.form.submit()" class="select w-48">
          <option value="0">Todas las categorías</option>
          <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $catFiltro === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <span class="text-sm text-slate-400"><?= count($productos) ?> productos</span>
  </div>

  <?php if (!$productos): ?>
    <?= empty_state('Sin productos', 'Crea tu primer producto para comenzar a vender.', 'box', can('productos.crear') ? btn_nuevo('prod:new', 'Nuevo producto') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Producto</th><th>Categoría</th><th class="text-right">Compra</th><th class="text-right">Venta</th><th class="text-center">Margen</th><th class="text-center">Stock</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($productos as $p):
            $margen = $p['precio_venta'] > 0 ? round((($p['precio_venta'] - $p['precio_compra']) / $p['precio_venta']) * 100) : 0;
            $stock = (float) $p['stock'];
            $stockBadge = $stock <= 0 ? 'rose' : ($stock <= $p['stock_minimo'] ? 'amber' : 'emerald');
          ?>
            <tr class="<?= $p['activo'] ? '' : 'opacity-50' ?>">
              <td>
                <div class="flex items-center gap-3">
                  <?php if (!empty($p['imagen']) && is_file(dirname(__DIR__, 2) . '/' . $p['imagen'])): ?>
                    <img src="<?= e(url($p['imagen'])) ?>" alt="" class="w-9 h-9 rounded-lg object-cover border border-slate-200 shrink-0">
                  <?php else: ?>
                    <span class="w-9 h-9 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center shrink-0"><?= icon('box', 'w-4 h-4') ?></span>
                  <?php endif; ?>
                  <div class="min-w-0"><p class="font-semibold text-slate-700 truncate"><?= e($p['nombre']) ?></p><p class="text-xs text-slate-400"><?= e($p['codigo']) ?><?= $p['marca'] ? ' · ' . e($p['marca']) : '' ?></p></div>
                </div>
              </td>
              <td><?= $p['categoria'] ? badge($p['categoria'], $p['cat_color']) : '<span class="text-slate-300">—</span>' ?></td>
              <td class="text-right text-slate-500"><?= money($p['precio_compra']) ?></td>
              <td class="text-right font-bold text-slate-800"><?= money($p['precio_venta']) ?></td>
              <td class="text-center"><span class="badge <?= $margen >= 30 ? 'badge-emerald' : ($margen >= 10 ? 'badge-amber' : 'badge-slate') ?>"><?= $margen ?>%</span></td>
              <td class="text-center"><span class="badge badge-<?= $stockBadge ?>"><?= qty($stock) ?> <?= e($p['unidad'] ?: 'u') ?></span></td>
              <td><?= $p['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('productos.editar')): ?>
                    <button onclick="<?= jsEvent('prod:edit', ['id'=>$p['id'],'codigo'=>$p['codigo'],'codigo_barras'=>$p['codigo_barras'],'nombre'=>$p['nombre'],'descripcion'=>$p['descripcion'],'categoria_id'=>$p['categoria_id'],'marca_id'=>$p['marca_id'],'unidad_id'=>$p['unidad_id'],'tipo'=>$p['tipo'],'precio_compra'=>$p['precio_compra'],'precio_venta'=>$p['precio_venta'],'itbis_aplica'=>$p['itbis_aplica'],'stock_minimo'=>$p['stock_minimo'],'imagen'=>$p['imagen'],'activo'=>$p['activo']]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('productos.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar «<?= e($p['nombre']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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

<!-- Modal producto -->
<div x-data="{open:false, form:{}}"
     @prod:new.window="form={id:0,codigo:'<?= e($sigCodigo) ?>',codigo_barras:'',nombre:'',descripcion:'',categoria_id:'',marca_id:'',unidad_id:'',tipo:'producto',precio_compra:0,precio_venta:0,itbis_aplica:1,stock_minimo:0,imagen:'',activo:1}; open=true"
     @prod:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-2xl max-h-[92vh] overflow-y-auto" @click.stop>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id"><input type="hidden" name="imagen_actual" :value="form.imagen||''">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 sticky top-0 bg-white">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar producto' : 'Nuevo producto'"></h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div><label class="label">Código / SKU *</label><input name="codigo" x-model="form.codigo" required class="input"></div>
          <div><label class="label">Código de barras</label><input name="codigo_barras" x-model="form.codigo_barras" class="input"></div>
          <div class="sm:col-span-2"><label class="label">Nombre *</label><input name="nombre" x-model="form.nombre" required class="input"></div>
          <div class="sm:col-span-2"><label class="label">Descripción</label><input name="descripcion" x-model="form.descripcion" class="input"></div>
          <div class="sm:col-span-2">
            <label class="label">Imagen del producto</label>
            <div class="flex items-center gap-3">
              <div class="w-14 h-14 rounded-lg bg-slate-100 border border-slate-200 flex items-center justify-center overflow-hidden shrink-0">
                <template x-if="form.imagen"><img :src="'<?= e(base_url()) ?>/'+form.imagen" class="max-w-full max-h-full object-contain"></template>
                <template x-if="!form.imagen"><span class="text-slate-300"><?= icon('box', 'w-6 h-6') ?></span></template>
              </div>
              <input type="file" name="imagen" accept="image/png,image/jpeg,image/webp,image/gif" class="block w-full text-sm text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-semibold hover:file:bg-blue-100 cursor-pointer">
            </div>
          </div>
          <div><label class="label">Categoría</label><select name="categoria_id" x-model="form.categoria_id" class="select"><option value="">— Sin categoría —</option><?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Marca</label><select name="marca_id" x-model="form.marca_id" class="select"><option value="">— Sin marca —</option><?php foreach ($marcas as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Unidad</label><select name="unidad_id" x-model="form.unidad_id" class="select"><option value="">— Unidad —</option><?php foreach ($unidades as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?> (<?= e($u['abreviatura']) ?>)</option><?php endforeach; ?></select></div>
          <div><label class="label">Tipo</label><select name="tipo" x-model="form.tipo" class="select"><option value="producto">Producto (controla stock)</option><option value="servicio">Servicio</option></select></div>
          <div><label class="label">Precio de compra</label><input type="number" step="0.01" name="precio_compra" x-model="form.precio_compra" class="input"></div>
          <div><label class="label">Precio de venta *</label><input type="number" step="0.01" name="precio_venta" x-model="form.precio_venta" required class="input"></div>
          <div><label class="label">Stock mínimo</label><input type="number" step="0.001" name="stock_minimo" x-model="form.stock_minimo" class="input"></div>
          <div class="flex flex-col justify-end gap-2 pb-1">
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="hidden" name="itbis_aplica" value="0"><input type="checkbox" name="itbis_aplica" value="1" :checked="form.itbis_aplica==1" class="rounded border-slate-300 text-blue-600"> Aplica ITBIS (18%)</label>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600"> Producto activo</label>
          </div>
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
