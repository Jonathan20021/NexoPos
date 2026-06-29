<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('inventario.ver');

if (isPost()) {
    verify_csrf();
    if (post('accion') === 'ajustar') {
        require_perm('inventario.ajustar');
        $pid = postInt('producto_id');
        $suc = postInt('sucursal_id');
        $modo = post('modo');
        $cantidad = postNum('cantidad');
        $motivo = trim(post('motivo'));
        require_sucursal_access($suc);
        if ($pid <= 0 || $suc <= 0 || $motivo === '') {
            flash('error', 'Completa todos los campos del ajuste.');
        } else {
            try {
                tx(function () use ($pid, $suc, $modo, $cantidad, $motivo) {
                    $actual = stockActual($pid, $suc);
                    if ($modo === 'exacta') $delta = $cantidad - $actual;
                    elseif ($modo === 'salida') $delta = -abs($cantidad);
                    else $delta = abs($cantidad);
                    if ($actual + $delta < 0) throw new RuntimeException('El ajuste dejaría el stock en negativo.');
                    ajustarStock($pid, $suc, $delta, 'ajuste', 'ajuste', null, 0, $motivo);
                });
                audit('inventario', 'ajustar', "Ajuste de stock producto #$pid: $motivo");
                flash('success', 'Stock ajustado correctamente.');
            } catch (Throwable $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('modules/inventario/stock.php' . (get('bajo') ? '?bajo=1' : ''));
    }
}

[$scope, $sp] = sucursalScope('s.sucursal_id');
$q = trim(get('q'));
$soloBajo = get('bajo') === '1';
$cond = [$scope, 'p.activo = 1'];
$params = $sp;
if ($q !== '') { $cond[] = "(p.nombre LIKE ? OR p.codigo LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($soloBajo) { $cond[] = "s.cantidad <= p.stock_minimo"; }
$where = implode(' AND ', $cond);

if (export_solicitado()) {
    $all = qAll("SELECT p.codigo, p.nombre, c.nombre AS categoria, su.nombre AS sucursal, s.cantidad, p.stock_minimo, p.precio_compra FROM inventario_stock s JOIN productos p ON p.id=s.producto_id JOIN sucursales su ON su.id=s.sucursal_id LEFT JOIN categorias c ON c.id=p.categoria_id WHERE $where ORDER BY p.nombre", $params);
    export_tabla('stock', ['Código', 'Producto', 'Categoría', 'Sucursal', 'Cantidad', 'Mínimo', 'Valor (costo)'],
        array_map(fn($r) => [$r['codigo'], $r['nombre'], $r['categoria'], $r['sucursal'], $r['cantidad'], $r['stock_minimo'], round($r['cantidad'] * $r['precio_compra'], 2)], $all));
}

$rows = qAll(
    "SELECT s.id, s.cantidad, p.id AS pid, p.nombre, p.codigo, p.stock_minimo, p.precio_compra,
            c.nombre AS categoria, c.color AS cat_color, su.id AS suc_id, su.nombre AS sucursal
     FROM inventario_stock s
     JOIN productos p ON p.id=s.producto_id
     JOIN sucursales su ON su.id=s.sucursal_id
     LEFT JOIN categorias c ON c.id=p.categoria_id
     WHERE $where ORDER BY (s.cantidad<=p.stock_minimo) DESC, p.nombre LIMIT 400", $params
);

$totProd = (int) qVal("SELECT COUNT(DISTINCT s.producto_id) FROM inventario_stock s JOIN productos p ON p.id=s.producto_id WHERE $scope AND p.activo=1", $sp);
$totBajo = (int) qVal("SELECT COUNT(*) FROM inventario_stock s JOIN productos p ON p.id=s.producto_id WHERE $scope AND p.activo=1 AND s.cantidad<=p.stock_minimo", $sp);
$valorTotal = (float) qVal("SELECT COALESCE(SUM(s.cantidad*p.precio_compra),0) FROM inventario_stock s JOIN productos p ON p.id=s.producto_id WHERE $scope AND p.activo=1", $sp);

layout_start('Stock', 'Existencias por producto y sucursal', export_buttons());
?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5 flex items-center gap-4"><div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><?= icon('box', 'w-6 h-6') ?></div><div><p class="text-2xl font-extrabold text-slate-800"><?= number_format($totProd) ?></p><p class="text-sm text-slate-400">Productos en inventario</p></div></div>
  <a href="?bajo=1" class="card p-5 flex items-center gap-4 hover:border-amber-300 transition"><div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center"><?= icon('alert', 'w-6 h-6') ?></div><div><p class="text-2xl font-extrabold text-slate-800"><?= number_format($totBajo) ?></p><p class="text-sm text-slate-400">En stock bajo</p></div></a>
  <div class="card p-5 flex items-center gap-4"><div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><?= icon('dollar', 'w-6 h-6') ?></div><div><p class="text-2xl font-extrabold text-slate-800"><?= money($valorTotal) ?></p><p class="text-sm text-slate-400">Valor del inventario (costo)</p></div></div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar producto...', $soloBajo ? ['bajo' => '1'] : []) ?>
    <div class="flex items-center gap-2">
      <?php if ($soloBajo): ?><a href="<?= e(url('modules/inventario/stock.php')) ?>" class="btn btn-ghost btn-sm">Ver todos</a><?php else: ?><a href="?bajo=1" class="btn btn-ghost btn-sm"><?= icon('filter', 'w-4 h-4') ?> Solo stock bajo</a><?php endif; ?>
      <span class="text-sm text-slate-400"><?= count($rows) ?> registros</span>
    </div>
  </div>
  <?php if (!$rows): ?>
    <?= empty_state('Sin existencias', 'No hay productos que coincidan con el filtro.', 'layers') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Producto</th><th>Categoría</th><th>Sucursal</th><th class="text-center">Stock</th><th class="text-center">Mínimo</th><th class="text-right">Valor</th><th>Estado</th><?php if (can('inventario.ajustar')): ?><th class="text-right">Ajustar</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($rows as $r):
            $st = (float) $r['cantidad'];
            $estado = $st <= 0 ? ['Agotado', 'rose'] : ($st <= $r['stock_minimo'] ? ['Bajo', 'amber'] : ['OK', 'emerald']);
          ?>
            <tr>
              <td><p class="font-semibold text-slate-700"><?= e($r['nombre']) ?></p><p class="text-xs text-slate-400"><?= e($r['codigo']) ?></p></td>
              <td><?= $r['categoria'] ? badge($r['categoria'], $r['cat_color']) : '<span class="text-slate-300">—</span>' ?></td>
              <td class="text-slate-500"><?= e($r['sucursal']) ?></td>
              <td class="text-center font-bold text-slate-800"><?= qty($st) ?></td>
              <td class="text-center text-slate-400"><?= qty($r['stock_minimo']) ?></td>
              <td class="text-right text-slate-500"><?= money($st * $r['precio_compra']) ?></td>
              <td><?= badge($estado[0], $estado[1]) ?></td>
              <?php if (can('inventario.ajustar')): ?>
                <td class="text-right"><button onclick="<?= jsEvent('stock:ajustar', ['producto_id'=>$r['pid'],'sucursal_id'=>$r['suc_id'],'nombre'=>$r['nombre'],'sucursal'=>$r['sucursal'],'cantidad'=>$st]) ?>" class="btn btn-ghost btn-sm"><?= icon('edit', 'w-3.5 h-3.5') ?> Ajustar</button></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (can('inventario.ajustar')): ?>
<div x-data="{open:false, form:{}}" @stock:ajustar.window="form=$event.detail; form.modo='exacta'; form.cantidad=$event.detail.cantidad; form.motivo=''; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="ajustar">
        <input type="hidden" name="producto_id" :value="form.producto_id"><input type="hidden" name="sucursal_id" :value="form.sucursal_id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Ajustar stock</h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
        <div class="p-6 space-y-4">
          <div class="rounded-xl bg-slate-50 p-3"><p class="font-semibold text-slate-700" x-text="form.nombre"></p><p class="text-sm text-slate-400" x-text="form.sucursal + ' · Stock actual: ' + form.cantidad"></p></div>
          <div>
            <label class="label">Tipo de ajuste</label>
            <div class="grid grid-cols-3 gap-2">
              <label class="cursor-pointer"><input type="radio" name="modo" value="exacta" x-model="form.modo" class="sr-only peer"><span class="block text-center py-2 rounded-lg border-2 border-slate-200 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700 text-xs font-semibold transition">Establecer</span></label>
              <label class="cursor-pointer"><input type="radio" name="modo" value="entrada" x-model="form.modo" class="sr-only peer"><span class="block text-center py-2 rounded-lg border-2 border-slate-200 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 peer-checked:text-emerald-700 text-xs font-semibold transition">Entrada (+)</span></label>
              <label class="cursor-pointer"><input type="radio" name="modo" value="salida" x-model="form.modo" class="sr-only peer"><span class="block text-center py-2 rounded-lg border-2 border-slate-200 peer-checked:border-rose-500 peer-checked:bg-rose-50 peer-checked:text-rose-700 text-xs font-semibold transition">Salida (−)</span></label>
            </div>
          </div>
          <div><label class="label" x-text="form.modo==='exacta' ? 'Cantidad final' : 'Cantidad a '+(form.modo==='entrada'?'agregar':'descontar')"></label><input type="number" step="0.001" name="cantidad" x-model.number="form.cantidad" required class="input"></div>
          <div><label class="label">Motivo *</label><input name="motivo" x-model="form.motivo" required class="input" placeholder="Ej. Conteo físico, merma, daño..."></div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Aplicar ajuste</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
