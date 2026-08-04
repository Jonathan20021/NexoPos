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
        $precioCompra = postNum('precio_compra');
        $precioVenta = postNum('precio_venta');
        $stockMinimo = postNum('stock_minimo');
        $categoriaId = postInt('categoria_id') ?: null;
        $marcaId = postInt('marca_id') ?: null;
        $unidadId = postInt('unidad_id') ?: null;

        // El código de barras se revisa de verdad: un dígito verificador que no
        // cuadra casi siempre es un número mal tecleado, y si se guarda, la
        // etiqueta impresa nunca leerá lo mismo que el producto físico.
        $barras = barcode_validar(post('codigo_barras'));

        if ($codigo === '' || $nombre === '') {
            flash('error', 'Código y nombre son obligatorios.');
        } elseif ($precioCompra < 0 || $precioVenta < 0 || $stockMinimo < 0) {
            flash('error', 'Precios y stock mínimo no pueden ser negativos.');
        } elseif (!$barras['ok']) {
            flash('error', $barras['error']);
        } elseif (($categoriaId && !qVal("SELECT 1 FROM categorias WHERE id=?", [$categoriaId]))
            || ($marcaId && !qVal("SELECT 1 FROM marcas WHERE id=?", [$marcaId]))
            || ($unidadId && !qVal("SELECT 1 FROM unidades WHERE id=?", [$unidadId]))) {
            flash('error', 'Categoría, marca o unidad no válidas.');
        } elseif (qVal("SELECT 1 FROM productos WHERE codigo = ? AND id <> ?", [$codigo, $id])) {
            flash('error', 'Ya existe un producto con ese código (SKU).');
        } elseif ($barras['valor'] !== ''
            && ($otro = qOne("SELECT id, nombre FROM productos WHERE codigo_barras = ? AND id <> ?", [$barras['valor'], $id]))) {
            // Dos productos con el mismo código de barras significan que la caja
            // cobraría el equivocado. Se corta aquí y se dice cuál lo tiene.
            flash('error', 'El código de barras ' . $barras['valor'] . ' ya lo usa «' . $otro['nombre'] . '». Un código identifica a un solo producto.');
        } else {
            $data = [
                'codigo' => $codigo,
                'codigo_barras' => $barras['valor'] !== '' ? $barras['valor'] : null,
                'nombre' => $nombre,
                'descripcion' => trim(post('descripcion')) ?: null,
                'categoria_id' => $categoriaId,
                'marca_id' => $marcaId,
                'unidad_id' => $unidadId,
                'tipo' => post('tipo') === 'servicio' ? 'servicio' : 'producto',
                'precio_compra' => $precioCompra,
                'precio_venta' => $precioVenta,
                'itbis_aplica' => postInt('itbis_aplica', 0) ? 1 : 0,
                'stock_minimo' => $stockMinimo,
                'activo' => postInt('activo', 0) ? 1 : 0,
                'imagen' => guardar_imagen('imagen', 'productos', post('imagen_actual') ?: null),
            ];
            // Las comprobaciones de arriba son «mirar y actuar»: entre el SELECT y
            // el INSERT otra persona puede quedarse con el mismo SKU o el mismo
            // código de barras. La red que de verdad lo impide son los índices
            // UNIQUE; aquí se traduce ese choque a un mensaje entendible en vez de
            // dejar salir un error de base de datos.
            try {
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
                if ($barras['aviso'] !== '') flash('info', $barras['aviso']);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash('error', str_contains($e->getMessage(), 'uq_producto_barras')
                        ? 'Otro usuario acaba de asignarle ese código de barras a otro producto. Revisa y vuelve a intentarlo.'
                        : 'Otro usuario acaba de crear un producto con ese código (SKU). Elige otro.');
                } else {
                    throw $e;
                }
            }
        }
        redirect('modules/inventario/productos.php');
    }

    /* ---------- Asignar un código de barras interno a un producto ---------- */
    if ($accion === 'generar_barras') {
        require_perm('productos.editar');
        $id = postInt('id');
        try {
            $codigo = tx(function () use ($id) {
                // FOR UPDATE y relectura: si alguien más se lo asignó mientras esta
                // pantalla estaba abierta, no se le pisa su código.
                $p = qOne("SELECT id, nombre, codigo_barras FROM productos WHERE id = ? FOR UPDATE", [$id]);
                if (!$p) throw new RuntimeException('El producto ya no existe.');
                if (trim((string) $p['codigo_barras']) !== '') return null;
                $c = barcode_generar_interno();
                dbUpdate('productos', ['codigo_barras' => $c], 'id = ?', [$id]);
                return $c;
            });
            if ($codigo === null) {
                flash('info', 'Ese producto ya tenía código de barras.');
            } else {
                audit('productos', 'editar', "Código de barras interno asignado: $codigo", ['tabla' => 'productos', 'registro_id' => $id]);
                flash('success', 'Código de barras ' . $codigo . ' asignado. Ya puedes imprimir su etiqueta.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/productos.php?' . http_build_query(array_intersect_key($_GET, ['q' => 1, 'categoria_id' => 1])));
    }

    if ($accion === 'eliminar') {
        require_perm('productos.eliminar');
        $id = postInt('id');
        $nombre = qVal("SELECT nombre FROM productos WHERE id = ?", [$id]);
        $tieneHistorial = qVal("SELECT 1 FROM venta_detalles WHERE producto_id = ? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM compra_detalles WHERE producto_id = ? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM transferencia_detalles WHERE producto_id = ? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM movimientos_inventario WHERE producto_id = ? LIMIT 1", [$id]);
        if ($tieneHistorial) {
            dbUpdate('productos', ['activo' => 0], 'id = ?', [$id]);
            audit('productos', 'editar', "Producto desactivado (tiene historial): $nombre", ['tabla' => 'productos', 'registro_id' => $id]);
            flash('warning', 'El producto tiene movimientos registrados; se desactivó para conservar el historial.');
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

$pg = paginar((int) qVal("SELECT COUNT(*) FROM productos p $where", $params), 25);
$productos = qAll(
    "SELECT p.*, c.nombre AS categoria, c.color AS cat_color, m.nombre AS marca, u.abreviatura AS unidad,
            $stockExpr AS stock
     FROM productos p
     LEFT JOIN categorias c ON c.id=p.categoria_id
     LEFT JOIN marcas m ON m.id=p.marca_id
     LEFT JOIN unidades u ON u.id=p.unidad_id
     $where ORDER BY p.nombre LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $params
);

$categorias = qAll("SELECT id, nombre, color FROM categorias WHERE activo=1 ORDER BY nombre");
$marcas = qAll("SELECT id, nombre FROM marcas WHERE activo=1 ORDER BY nombre");
$unidades = qAll("SELECT id, nombre, abreviatura FROM unidades ORDER BY nombre");
// preview y no next: aquí solo se sugiere el código en el formulario. Consumirlo
// en cada carga de página quemaría un correlativo por visita.
$sigCodigo = previewNumero('productos', 'codigo', 'SKU', 5);

$acciones = export_buttons()
    . (can('productos.etiquetas')
        ? '<a href="' . e(url('modules/inventario/etiquetas.php')) . '" class="btn btn-ghost">' . icon('barcode', 'w-4 h-4') . ' Etiquetas</a>'
        : '')
    . (can('inventario.ver')
        ? '<a href="' . e(url('modules/inventario/escaner.php')) . '" class="btn btn-ghost">' . icon('search', 'w-4 h-4') . ' Escáner</a>'
        : '')
    . (can('productos.crear') ? btn_nuevo('prod:new', 'Nuevo producto') : '');
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
    <span class="text-sm text-slate-400"><?= number_format($pg['total']) ?> productos</span>
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
            // Se calcula aquí, al principio de la fila: lo usan dos columnas distintas.
            $cod = trim((string) $p['codigo_barras']);
          ?>
            <tr class="<?= $p['activo'] ? '' : 'opacity-50' ?>">
              <td>
                <div class="flex items-center gap-3">
                  <?php if (!empty($p['imagen']) && is_file(dirname(__DIR__, 2) . '/' . $p['imagen'])): ?>
                    <img src="<?= e(url($p['imagen'])) ?>" alt="" class="w-9 h-9 rounded-lg object-cover border border-slate-200 shrink-0">
                  <?php else: ?>
                    <span class="w-9 h-9 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center shrink-0"><?= icon('box', 'w-4 h-4') ?></span>
                  <?php endif; ?>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-700 truncate"><?= e($p['nombre']) ?></p>
                    <p class="text-xs text-slate-400"><?= e($p['codigo']) ?><?= $p['marca'] ? ' · ' . e($p['marca']) : '' ?></p>
                    <?php if ($cod !== ''): ?>
                      <p class="text-[11px] text-slate-500 font-mono flex items-center gap-1 mt-0.5">
                        <span class="text-slate-300"><?= icon('barcode', 'w-3 h-3') ?></span><?= e($cod) ?>
                        <?php if (barcode_es_interno($cod)): ?>
                          <span class="text-[10px] text-slate-400 font-sans" title="Código interno generado por el sistema (prefijo 200)">interno</span>
                        <?php endif; ?>
                      </p>
                    <?php elseif ($p['tipo'] === 'producto'): ?>
                      <p class="text-[11px] text-amber-600 mt-0.5">Sin código de barras</p>
                    <?php endif; ?>
                  </div>
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
                  <?php if ($cod === '' && $p['tipo'] === 'producto' && can('productos.editar')): ?>
                    <form method="post" class="inline"
                          onsubmit="return confirm('¿Asignar un código de barras interno a «<?= e($p['nombre']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="generar_barras"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <button class="p-2 rounded-lg text-amber-500 hover:text-amber-600 hover:bg-amber-50" title="Generar código de barras interno"><?= icon('barcode', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
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
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<!-- Modal producto -->
<div x-data="prodModal()"
     @prod:new.window="nuevo()"
     @prod:edit.window="form=$event.detail; revisar(); open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id"><input type="hidden" name="imagen_actual" :value="form.imagen||''">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 sticky top-0 bg-white">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar producto' : 'Nuevo producto'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div><label class="label">Código / SKU *</label><input name="codigo" x-model="form.codigo" required class="input"></div>
          <div>
            <label class="label">Código de barras</label>
            <div class="flex gap-1.5">
              <input name="codigo_barras" x-model="form.codigo_barras" @input="revisar()" data-escaner
                     placeholder="Escanea, escribe o genera" autocomplete="off" class="input flex-1 font-mono">
              <button type="button" @click="escanear()" x-show="camaraOk" x-cloak
                      class="btn btn-soft px-2.5 shrink-0" title="Escanear con la cámara"><?= icon('barcode', 'w-4 h-4') ?></button>
              <button type="button" @click="generar()" class="btn btn-soft px-2.5 shrink-0"
                      title="Generar un código interno EAN-13"><?= icon('plus', 'w-4 h-4') ?></button>
            </div>
            <p class="text-xs mt-1" :class="barras.ok ? 'text-slate-400' : 'text-rose-600'" x-text="barras.msg"></p>
          </div>
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
          <div><label class="label">Precio de compra</label><input type="number" step="0.01" min="0" name="precio_compra" x-model="form.precio_compra" class="input"></div>
          <div><label class="label">Precio de venta *</label><input type="number" step="0.01" min="0" name="precio_venta" x-model="form.precio_venta" required class="input"></div>
          <div><label class="label">Stock mínimo</label><input type="number" step="0.001" min="0" name="stock_minimo" x-model="form.stock_minimo" class="input"></div>
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

<?= escaner_script() ?>
<script>
function prodModal() {
  return {
    open: false,
    form: {},
    camaraOk: false,
    barras: { ok: true, msg: '' },

    init() {
      this.camaraOk = NexoEscaner.soportado();
      // Si se llega desde el escáner de almacén con un código que no existía,
      // se abre el formulario ya con ese código puesto.
      const pre = new URLSearchParams(location.search).get('nuevo_barras');
      if (pre) { this.nuevo(); this.form.codigo_barras = pre; this.revisar(); }
    },

    nuevo() {
      this.form = {
        id: 0, codigo: <?= json_encode($sigCodigo) ?>, codigo_barras: '', nombre: '', descripcion: '',
        categoria_id: '', marca_id: '', unidad_id: '', tipo: 'producto',
        precio_compra: 0, precio_venta: 0, itbis_aplica: 1, stock_minimo: 0, imagen: '', activo: 1,
      };
      this.barras = { ok: true, msg: '' };
      this.open = true;
    },

    /* Dígito verificador EAN/UPC: se pesa 3-1-3-1 desde la DERECHA del cuerpo.
       Hacerlo desde la izquierda da otro resultado según el largo del código. */
    dv(cuerpo) {
      let suma = 0, peso = 3;
      for (let i = cuerpo.length - 1; i >= 0; i--) {
        suma += parseInt(cuerpo[i], 10) * peso;
        peso = peso === 3 ? 1 : 3;
      }
      return String((10 - (suma % 10)) % 10);
    },

    /* Mismo criterio que el servidor, para avisar antes de guardar y no después.
       Solo se marca como error un EAN-13 con el verificador cambiado; los códigos
       internos numéricos de otro largo se aceptan y se imprimen en Code 128. */
    revisar() {
      const v = String(this.form.codigo_barras || '').trim();
      if (!v) { this.barras = { ok: true, msg: 'Opcional. Sin código no se puede escanear en caja ni en el almacén.' }; return; }
      if (v.length > 60) { this.barras = { ok: false, msg: 'Máximo 60 caracteres.' }; return; }

      const soloDigitos = /^\d+$/.test(v);
      if (soloDigitos && [8, 12, 13, 14].includes(v.length)) {
        const correcto = v.slice(0, -1) + this.dv(v.slice(0, -1));
        if (correcto === v) {
          const nombre = { 8: 'EAN-8', 12: 'UPC-A', 13: 'EAN-13', 14: 'ITF-14' }[v.length];
          const interno = v.length === 13 && v[0] === '2';
          this.barras = { ok: true, msg: nombre + ' válido' + (interno ? ' · código interno' : ' · código de fabricante') + '.' };
          return;
        }
        if (v.length === 13) {
          this.barras = { ok: false, msg: 'EAN-13 inválido: el verificador debería ser ' + correcto.slice(-1) + ' (' + correcto + ').' };
          return;
        }
        this.barras = { ok: true, msg: 'Código interno: se imprimirá en Code 128.' };
        return;
      }
      this.barras = { ok: true, msg: 'Se imprimirá como Code 128 (alfanumérico).' };
    },

    escanear() {
      NexoEscaner.abrir({
        titulo: 'Código de barras del producto',
        ayuda: 'Apunta al código impreso en el empaque.',
        continuo: false,
        onLeer: (codigo) => { this.form.codigo_barras = codigo; this.revisar(); },
      });
    },

    async generar() {
      if (String(this.form.codigo_barras || '').trim() &&
          !confirm('Este producto ya tiene un código escrito. ¿Reemplazarlo por uno interno nuevo?')) return;
      try {
        const res = await fetch(<?= json_encode(url('modules/inventario/api_escaner.php')) ?>, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF': <?= json_encode(csrf_token()) ?> },
          credentials: 'same-origin',
          body: JSON.stringify({ accion: 'nuevo_codigo' }),
        });
        const r = await res.json();
        if (!r.ok) { this.barras = { ok: false, msg: r.error || 'No se pudo generar el código.' }; return; }
        this.form.codigo_barras = r.codigo;
        this.revisar();
      } catch (e) {
        this.barras = { ok: false, msg: 'Sin conexión con el servidor.' };
      }
    },
  };
}
</script>

<?php layout_end(); ?>
