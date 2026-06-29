<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('compras.ver');

$tasaItbis = (float) setting('itbis_tasa', DEFAULT_ITBIS);

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        require_perm('compras.crear');
        $proveedorId = postInt('proveedor_id') ?: null;
        $sucursalId  = postInt('sucursal_id');
        require_sucursal_access($sucursalId);
        $fecha       = post('fecha') ?: date('Y-m-d');
        $lineas      = json_decode(post('lineas', '[]'), true);
        if (!$sucursalId || !is_array($lineas) || count($lineas) === 0) {
            flash('error', 'Selecciona la sucursal y agrega al menos un producto.');
            redirect('modules/inventario/compras.php');
        }
        if ($proveedorId && !qVal("SELECT 1 FROM proveedores WHERE id=? AND activo=1", [$proveedorId])) {
            flash('error', 'El proveedor seleccionado no es válido.');
            redirect('modules/inventario/compras.php');
        }
        try {
            $compraId = tx(function () use ($proveedorId, $sucursalId, $fecha, $lineas, $tasaItbis) {
                $subtotal = 0; $itbisTotal = 0; $det = [];
                foreach ($lineas as $l) {
                    $pid = (int) ($l['producto_id'] ?? 0);
                    $cant = (float) ($l['cantidad'] ?? 0);
                    $costo = (float) ($l['costo'] ?? 0);
                    if ($pid <= 0 || $cant <= 0) continue;
                    if ($costo <= 0) throw new RuntimeException('El costo de compra debe ser mayor que cero.');
                    $p = qOne("SELECT id, itbis_aplica FROM productos WHERE id = ?", [$pid]);
                    if (!$p) throw new RuntimeException('Producto inválido en la compra.');
                    $base = round($costo * $cant, 2);
                    $itbis = $p['itbis_aplica'] ? round($base * $tasaItbis / 100, 2) : 0;
                    $subtotal += $base; $itbisTotal += $itbis;
                    $det[] = ['pid' => $pid, 'cant' => $cant, 'costo' => $costo, 'itbis' => $itbis, 'base' => $base];
                }
                if (!$det) throw new RuntimeException('No hay líneas válidas.');
                $total = $subtotal + $itbisTotal;
                $numero = nextNumero('compras', 'numero', 'COM');
                $compraId = dbInsert('compras', [
                    'numero' => $numero, 'sucursal_id' => $sucursalId, 'proveedor_id' => $proveedorId, 'fecha' => $fecha,
                    'subtotal' => $subtotal, 'itbis' => $itbisTotal, 'descuento' => 0, 'total' => $total,
                    'estado' => 'recibida', 'usuario_id' => current_user()['id'],
                ]);
                foreach ($det as $d) {
                    dbInsert('compra_detalles', ['compra_id' => $compraId, 'producto_id' => $d['pid'], 'cantidad' => $d['cant'], 'costo_unitario' => $d['costo'], 'itbis' => $d['itbis'], 'subtotal' => $d['base']]);
                    ajustarStock($d['pid'], $sucursalId, $d['cant'], 'compra', 'compra', $compraId, $d['costo'], 'Compra ' . $numero);
                    q("UPDATE productos SET precio_compra = ? WHERE id = ?", [$d['costo'], $d['pid']]);
                }
                registrarTransaccion('gasto', $total, ['sucursal_id' => $sucursalId, 'cuenta_id' => cuentaFinancieraIdPorTipo('efectivo', $sucursalId), 'categoria_id' => categoriaFinancieraId('gasto', 'Compra de Mercancía'), 'descripcion' => 'Compra ' . $numero, 'referencia_tipo' => 'compra', 'referencia_id' => $compraId, 'fecha' => $fecha]);
                return $compraId;
            });
            audit('compras', 'crear', 'Compra registrada', ['tabla' => 'compras', 'registro_id' => $compraId]);
            flash('success', 'Compra registrada y stock actualizado.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/compras.php');
    }

    if ($accion === 'anular') {
        require_perm('compras.anular');
        $id = postInt('id');
        try {
            tx(function () use ($id) {
                $c = qOne("SELECT * FROM compras WHERE id = ? FOR UPDATE", [$id]);
                if (!$c || $c['estado'] !== 'recibida') throw new RuntimeException('La compra no se puede anular.');
                if (!can_access_sucursal($c['sucursal_id'])) throw new RuntimeException('No tienes acceso a la sucursal de esta compra.');
                foreach (qAll("SELECT * FROM compra_detalles WHERE compra_id = ?", [$id]) as $d) {
                    if (stockActual((int) $d['producto_id'], (int) $c['sucursal_id']) < $d['cantidad']) {
                        throw new RuntimeException('No se puede anular: ya se vendió parte de la mercancía.');
                    }
                }
                foreach (qAll("SELECT * FROM compra_detalles WHERE compra_id = ?", [$id]) as $d) {
                    ajustarStock((int) $d['producto_id'], (int) $c['sucursal_id'], -$d['cantidad'], 'salida', 'compra_anulada', $id, (float) $d['costo_unitario'], 'Anulación compra ' . $c['numero']);
                }
                // Revertir gasto en finanzas
                foreach (qAll("SELECT * FROM transacciones WHERE referencia_tipo='compra' AND referencia_id = ?", [$id]) as $tr) {
                    if ($tr['cuenta_id']) q("UPDATE cuentas_financieras SET balance = balance + ? WHERE id = ?", [$tr['monto'], $tr['cuenta_id']]);
                    q("DELETE FROM transacciones WHERE id = ?", [$tr['id']]);
                }
                dbUpdate('compras', ['estado' => 'anulada'], 'id = ?', [$id]);
            });
            audit('compras', 'anular', "Compra anulada #$id", ['tabla' => 'compras', 'registro_id' => $id]);
            flash('success', 'Compra anulada y stock revertido.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/compras.php');
    }
}

// ----- Detalle -----
$verId = (int) get('ver');
if ($verId) {
    $compra = qOne("SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal, u.nombre AS usuario FROM compras c LEFT JOIN proveedores p ON p.id=c.proveedor_id JOIN sucursales s ON s.id=c.sucursal_id LEFT JOIN usuarios u ON u.id=c.usuario_id WHERE c.id=?", [$verId]);
    if (!$compra) { flash('error', 'Compra no encontrada.'); redirect('modules/inventario/compras.php'); }
    require_sucursal_access($compra['sucursal_id']);
    $detalles = qAll("SELECT cd.*, pr.nombre AS producto, pr.codigo FROM compra_detalles cd JOIN productos pr ON pr.id=cd.producto_id WHERE cd.compra_id=?", [$verId]);
    layout_start('Compra ' . e($compra['numero']), 'Detalle de la compra', '<a href="' . url('modules/inventario/compras.php') . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Volver</a>');
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="card p-5 lg:col-span-2 overflow-hidden">
        <table class="data-table">
          <thead><tr><th>Producto</th><th class="text-center">Cantidad</th><th class="text-right">Costo</th><th class="text-right">ITBIS</th><th class="text-right">Subtotal</th></tr></thead>
          <tbody>
            <?php foreach ($detalles as $d): ?>
              <tr><td><p class="font-semibold text-slate-700"><?= e($d['producto']) ?></p><p class="text-xs text-slate-400"><?= e($d['codigo']) ?></p></td><td class="text-center"><?= qty($d['cantidad']) ?></td><td class="text-right"><?= money($d['costo_unitario']) ?></td><td class="text-right text-slate-500"><?= money($d['itbis']) ?></td><td class="text-right font-bold text-slate-800"><?= money($d['subtotal']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card p-5 h-fit space-y-3">
        <div><p class="text-xs text-slate-400">Proveedor</p><p class="font-semibold text-slate-700"><?= e($compra['proveedor'] ?: 'Sin proveedor') ?></p></div>
        <div><p class="text-xs text-slate-400">Sucursal</p><p class="font-semibold text-slate-700"><?= e($compra['sucursal']) ?></p></div>
        <div><p class="text-xs text-slate-400">Fecha</p><p class="font-semibold text-slate-700"><?= fechaCorta($compra['fecha']) ?></p></div>
        <div><p class="text-xs text-slate-400">Estado</p><?= badgeFor($compra['estado']) ?></div>
        <div class="border-t border-slate-100 pt-3 space-y-1.5 text-sm">
          <div class="flex justify-between text-slate-500"><span>Subtotal</span><span><?= money($compra['subtotal']) ?></span></div>
          <div class="flex justify-between text-slate-500"><span>ITBIS</span><span><?= money($compra['itbis']) ?></span></div>
          <div class="flex justify-between text-lg font-extrabold text-slate-800 pt-1 border-t border-slate-100"><span>Total</span><span><?= money($compra['total']) ?></span></div>
        </div>
      </div>
    </div>
    <?php layout_end(); return;
}

// ----- Listado -----
[$scope, $sp] = sucursalScope('c.sucursal_id');
$compras = qAll("SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal FROM compras c LEFT JOIN proveedores p ON p.id=c.proveedor_id JOIN sucursales s ON s.id=c.sucursal_id WHERE $scope ORDER BY c.id DESC LIMIT 100", $sp);

if (export_solicitado()) {
    $rows = qAll("SELECT c.numero, p.nombre AS proveedor, s.nombre AS sucursal, c.fecha, c.subtotal, c.itbis, c.total, c.estado FROM compras c LEFT JOIN proveedores p ON p.id=c.proveedor_id JOIN sucursales s ON s.id=c.sucursal_id WHERE $scope ORDER BY c.id DESC", $sp);
    export_tabla('compras', ['Número', 'Proveedor', 'Sucursal', 'Fecha', 'Subtotal', 'ITBIS', 'Total', 'Estado'],
        array_map(fn($c) => [$c['numero'], $c['proveedor'], $c['sucursal'], $c['fecha'], $c['subtotal'], $c['itbis'], $c['total'], $c['estado']], $rows));
}

$productosJs = array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'costo' => (float) $p['precio_compra'], 'itbis' => (int) $p['itbis_aplica']],
    qAll("SELECT id, nombre, precio_compra, itbis_aplica FROM productos WHERE activo=1 AND tipo='producto' ORDER BY nombre"));
$proveedores = qAll("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
$sucursales = sucursales_visibles();

$acciones = export_buttons() . (can('compras.crear') ? '<button onclick="' . jsEvent('compra:new') . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nueva compra</button>' : '');
layout_start('Compras', 'Registra entradas de mercancía de tus proveedores', $acciones);
?>

<div class="card overflow-hidden">
  <?php if (!$compras): ?>
    <?= empty_state('Sin compras', 'Registra una compra para aumentar el inventario.', 'truck', $acciones) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Compra</th><th>Proveedor</th><th>Sucursal</th><th>Fecha</th><th class="text-right">Total</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($compras as $c): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($c['numero']) ?></td>
              <td class="text-slate-600"><?= e($c['proveedor'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($c['sucursal']) ?></td>
              <td class="text-slate-500"><?= fechaCorta($c['fecha']) ?></td>
              <td class="text-right font-bold text-slate-800"><?= money($c['total']) ?></td>
              <td><?= badgeFor($c['estado']) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="?ver=<?= (int) $c['id'] ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ver"><?= icon('eye', 'w-4 h-4') ?></a>
                  <?php if (can('compras.anular') && $c['estado'] === 'recibida'): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Anular esta compra? Se revertirá el stock.')"><?= csrf_field() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Anular"><?= icon('x', 'w-4 h-4') ?></button></form>
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

<!-- Modal nueva compra -->
<div x-data="comprasForm()" @compra:new.window="reset(); open=true" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-3xl" @click.stop>
    <form method="post" @submit="document.getElementById('lineasInput').value=JSON.stringify(lineas)">
      <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="lineas" id="lineasInput">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Nueva compra</h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div><label class="label">Proveedor</label><select name="proveedor_id" class="select"><option value="">— Sin proveedor —</option><?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Sucursal *</label><select name="sucursal_id" required class="select"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" class="input"></div>
        </div>
        <div class="flex items-end gap-2">
          <div class="flex-1"><label class="label">Agregar producto</label><select x-model.number="nuevoProd" class="select"><option value="0">Selecciona...</option><?php foreach ($productosJs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
          <button type="button" @click="addLinea()" class="btn btn-soft"><?= icon('plus', 'w-4 h-4') ?> Agregar</button>
        </div>
        <div class="border border-slate-200 rounded-xl overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-slate-50"><tr><th class="text-left px-3 py-2 text-xs font-semibold text-slate-400 uppercase">Producto</th><th class="px-2 py-2 text-xs font-semibold text-slate-400 uppercase w-24">Cant.</th><th class="px-2 py-2 text-xs font-semibold text-slate-400 uppercase w-28">Costo</th><th class="px-2 py-2 text-xs font-semibold text-slate-400 uppercase text-right w-28">Subtotal</th><th class="w-10"></th></tr></thead>
            <tbody>
              <template x-for="(l,i) in lineas" :key="i">
                <tr class="border-t border-slate-100">
                  <td class="px-3 py-2 font-medium text-slate-700" x-text="l.nombre"></td>
                  <td class="px-2 py-2"><input type="number" step="0.001" min="0" x-model.number="l.cantidad" class="input py-1.5 px-2 text-sm"></td>
                  <td class="px-2 py-2"><input type="number" step="0.01" min="0.01" x-model.number="l.costo" class="input py-1.5 px-2 text-sm"></td>
                  <td class="px-2 py-2 text-right font-semibold text-slate-700" x-text="fmt(l.cantidad*l.costo)"></td>
                  <td class="px-2 py-2"><button type="button" @click="lineas.splice(i,1)" aria-label="Quitar producto" title="Quitar" class="text-rose-400 hover:text-rose-600 p-2"><?= icon('trash', 'w-4 h-4') ?></button></td>
                </tr>
              </template>
              <tr x-show="lineas.length===0"><td colspan="5" class="text-center text-slate-400 py-6 text-sm">Agrega productos a la compra.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="flex justify-end"><div class="w-64 space-y-1.5 text-sm">
          <div class="flex justify-between text-slate-500"><span>Subtotal</span><span x-text="fmt(subtotal)"></span></div>
          <div class="flex justify-between text-slate-500"><span>ITBIS</span><span x-text="fmt(itbis)"></span></div>
          <div class="flex justify-between text-lg font-extrabold text-slate-800 pt-1 border-t border-slate-100"><span>Total</span><span x-text="fmt(total)"></span></div>
        </div></div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" :disabled="lineas.length===0" class="btn btn-primary disabled:opacity-50"><?= icon('save', 'w-4 h-4') ?> Registrar compra</button></div>
    </form>
  </div>
</div>

<script>
function comprasForm() {
  return {
    open: false, nuevoProd: 0, lineas: [],
    productos: <?= json_encode($productosJs, JSON_UNESCAPED_UNICODE) ?>,
    tasa: <?= $tasaItbis ?>,
    reset() { this.lineas = []; this.nuevoProd = 0; },
    addLinea() {
      const p = this.productos.find(x => x.id === this.nuevoProd);
      if (!p) return;
      if (this.lineas.find(l => l.producto_id === p.id)) return;
      this.lineas.push({ producto_id: p.id, nombre: p.nombre, cantidad: 1, costo: p.costo, itbis_aplica: p.itbis });
      this.nuevoProd = 0;
    },
    get subtotal() { return this.lineas.reduce((s, l) => s + (l.cantidad || 0) * (l.costo || 0), 0); },
    get itbis() { return this.lineas.reduce((s, l) => s + (l.itbis_aplica ? (l.cantidad || 0) * (l.costo || 0) * this.tasa / 100 : 0), 0); },
    get total() { return this.subtotal + this.itbis; },
    fmt(n) { return '<?= e(setting('moneda', 'RD$')) ?> ' + (n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
  };
}
</script>

<?php layout_end(); ?>
