<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('pos.ver');

$sid = current_sucursal_id();
$uid = (int) current_user()['id'];
$tasa = (float) setting('itbis_tasa', DEFAULT_ITBIS);
$moneda = setting('moneda', 'RD$');

layout_start('Punto de Venta', 'Registra ventas de forma rápida' . ($sid === null ? '' : ' · ' . e(current_user()['sucursal_nombre'] ?? '')));

if ($sid === null) { echo empty_state('Selecciona una sucursal', 'El punto de venta opera por sucursal. Elige una en la barra superior.', 'store'); layout_end(); return; }

$sesion = cajaSesionAbierta($sid, $uid);
if (!$sesion) {
    echo '<div class="card p-10 text-center max-w-lg mx-auto">'
        . '<div class="w-16 h-16 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center mx-auto mb-4">' . icon('cash', 'w-8 h-8') . '</div>'
        . '<h3 class="text-lg font-bold text-slate-800">La caja está cerrada</h3>'
        . '<p class="text-slate-500 text-sm mt-1 mb-5">Debes abrir la caja antes de comenzar a vender en esta sucursal.</p>'
        . (can('caja.abrir') ? '<a href="' . url('modules/pos/caja.php') . '" class="btn btn-primary inline-flex">' . icon('cash', 'w-4 h-4') . ' Abrir caja</a>' : '')
        . '</div>';
    layout_end();
    return;
}

$productos = qAll(
    "SELECT p.id, p.codigo, p.nombre, p.precio_venta, p.itbis_aplica, p.categoria_id, COALESCE(s.cantidad,0) AS stock, COALESCE(c.color,'slate') AS color
     FROM productos p LEFT JOIN inventario_stock s ON s.producto_id=p.id AND s.sucursal_id=?
     LEFT JOIN categorias c ON c.id=p.categoria_id
     WHERE p.activo=1 AND p.tipo='producto' ORDER BY p.nombre", [$sid]
);
$prodJs = array_map(fn($p) => [
    'id' => (int) $p['id'], 'codigo' => $p['codigo'], 'nombre' => $p['nombre'],
    'precio' => (float) $p['precio_venta'], 'itbis_aplica' => (int) $p['itbis_aplica'],
    'categoria_id' => (int) $p['categoria_id'], 'stock' => (float) $p['stock'], 'color' => $p['color'],
], $productos);

$categorias = qAll("SELECT DISTINCT c.id, c.nombre, c.color FROM categorias c JOIN productos p ON p.categoria_id=c.id WHERE p.activo=1 ORDER BY c.nombre");
$metodos = qAll("SELECT id, nombre, afecta_caja FROM metodos_pago WHERE activo=1 ORDER BY id");
$clientes = qAll("SELECT id, nombre FROM clientes WHERE activo=1 ORDER BY nombre");
$efectivoId = (int) (qVal("SELECT id FROM metodos_pago WHERE afecta_caja=1 AND activo=1 ORDER BY id LIMIT 1") ?: 1);
$badgeMap = ['blue'=>'badge-blue','emerald'=>'badge-emerald','amber'=>'badge-amber','rose'=>'badge-rose','indigo'=>'badge-indigo','cyan'=>'badge-cyan','sky'=>'badge-sky','pink'=>'badge-pink','violet'=>'badge-violet','slate'=>'badge-slate'];
?>

<div x-data="pos()" class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

  <!-- Productos -->
  <div class="lg:col-span-2 card p-4">
    <div class="flex items-center gap-2 mb-3">
      <div class="relative flex-1">
        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
        <input type="text" x-model="search" placeholder="Buscar producto o escanear código..." class="input pl-10" autofocus>
      </div>
      <span class="text-sm text-slate-400 hidden sm:block" x-text="filtered.length + ' productos'"></span>
    </div>

    <!-- Chips de categoría -->
    <div class="flex gap-2 overflow-x-auto pb-2 mb-1">
      <button @click="cat=0" :class="cat===0 ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="px-3.5 py-1.5 rounded-lg text-sm font-semibold whitespace-nowrap transition">Todas</button>
      <?php foreach ($categorias as $c): ?>
        <button @click="cat=<?= (int) $c['id'] ?>" :class="cat===<?= (int) $c['id'] ?> ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="px-3.5 py-1.5 rounded-lg text-sm font-semibold whitespace-nowrap transition"><?= e($c['nombre']) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 max-h-[calc(100vh-280px)] overflow-y-auto pr-1 pt-2">
      <template x-for="p in filtered" :key="p.id">
        <button @click="add(p)" :disabled="p.stock<=0"
                class="text-left rounded-xl border border-slate-200 p-3 hover:border-blue-400 hover:shadow-soft transition disabled:opacity-50 disabled:cursor-not-allowed group">
          <div class="flex items-center justify-between mb-2">
            <span class="w-9 h-9 rounded-lg bg-slate-100 text-slate-500 flex items-center justify-center group-hover:bg-blue-50 group-hover:text-blue-600 transition"><?= icon('box', 'w-4 h-4') ?></span>
            <span class="text-[11px] font-semibold px-1.5 py-0.5 rounded"
                  :class="p.stock<=0 ? 'bg-rose-50 text-rose-600' : (p.stock<=5 ? 'bg-amber-50 text-amber-600':'bg-emerald-50 text-emerald-600')"
                  x-text="(p.stock<=0?'Agotado':p.stock+' u.')"></span>
          </div>
          <p class="text-sm font-semibold text-slate-700 leading-tight line-clamp-2" x-text="p.nombre"></p>
          <p class="text-base font-extrabold text-blue-600 mt-1" x-text="fmt(p.precio)"></p>
        </button>
      </template>
      <div x-show="filtered.length===0" class="col-span-full text-center text-slate-400 py-12 text-sm">No se encontraron productos.</div>
    </div>
  </div>

  <!-- Carrito -->
  <div class="card p-0 lg:sticky lg:top-20 overflow-hidden flex flex-col max-h-[calc(100vh-110px)]">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
      <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('cart', 'w-5 h-5 text-blue-600') ?> Carrito <span class="badge badge-blue" x-text="cart.length"></span></h3>
      <button @click="cart=[]" x-show="cart.length>0" class="text-xs font-semibold text-rose-500 hover:text-rose-600">Vaciar</button>
    </div>

    <div class="flex-1 overflow-y-auto p-3 space-y-2 min-h-[140px]">
      <div x-show="cart.length===0" class="flex flex-col items-center justify-center text-center py-10 text-slate-400">
        <?= icon('cart', 'w-10 h-10 mb-2 opacity-40') ?>
        <p class="text-sm">Agrega productos para iniciar la venta.</p>
      </div>
      <template x-for="(it,idx) in cart" :key="it.id">
        <div class="flex items-center gap-2 bg-slate-50 rounded-xl p-2.5">
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-slate-700 truncate" x-text="it.nombre"></p>
            <p class="text-xs text-slate-400" x-text="fmt(it.precio)"></p>
          </div>
          <div class="flex items-center gap-1.5">
            <button @click="dec(it)" class="w-7 h-7 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 flex items-center justify-center"><?= icon('minus', 'w-3.5 h-3.5') ?></button>
            <input type="text" x-model.number="it.cant" @change="qtyChange(it)" class="w-10 text-center text-sm font-bold bg-white border border-slate-200 rounded-lg py-1">
            <button @click="inc(it)" class="w-7 h-7 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 flex items-center justify-center"><?= icon('plus', 'w-3.5 h-3.5') ?></button>
          </div>
          <div class="w-20 text-right">
            <p class="text-sm font-bold text-slate-800" x-text="fmt(it.precio*it.cant)"></p>
          </div>
        </div>
      </template>
    </div>

    <div class="border-t border-slate-100 p-4 space-y-2">
      <div class="flex justify-between text-sm text-slate-500"><span>Subtotal</span><span x-text="fmt(subtotal)"></span></div>
      <div class="flex justify-between text-sm text-slate-500 items-center">
        <span>Descuento</span>
        <input type="number" step="0.01" min="0" x-model.number="descuento" class="w-24 text-right input py-1 px-2 text-sm">
      </div>
      <div class="flex justify-between text-sm text-slate-500"><span>ITBIS (<?= rtrim(rtrim(number_format($tasa, 2), '0'), '.') ?>%)</span><span x-text="fmt(itbis)"></span></div>
      <div class="flex justify-between text-lg font-extrabold text-slate-800 pt-2 border-t border-slate-100"><span>Total</span><span x-text="fmt(total)"></span></div>
      <button @click="openPay()" :disabled="cart.length===0" class="btn btn-primary w-full py-3 text-base mt-1 disabled:opacity-50"><?= icon('cash', 'w-5 h-5') ?> Cobrar</button>
    </div>
  </div>

  <!-- Modal de cobro -->
  <div x-show="pay" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/50 z-50 flex items-center justify-center p-4" @click.self="pay=false" @keydown.escape.window="pay=false">
    <div class="bg-white rounded-2xl shadow-pop w-full max-w-md" @click.stop>
      <form method="post" action="<?= e(url('modules/pos/guardar_venta.php')) ?>" @submit="document.getElementById('cartInput').value=JSON.stringify(cart.map(i=>({id:i.id,cant:i.cant})))">
        <?= csrf_field() ?>
        <input type="hidden" name="cart" id="cartInput">
        <input type="hidden" name="descuento" :value="descuento||0">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Procesar cobro</h3>
          <button type="button" @click="pay=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div class="rounded-xl bg-blue-600 text-white p-4 text-center">
            <p class="text-blue-100 text-sm">Total a cobrar</p>
            <p class="text-3xl font-extrabold" x-text="fmt(total)"></p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Cliente</label>
              <select name="cliente_id" x-model="cliente_id" class="select">
                <?php foreach ($clientes as $cl): ?><option value="<?= (int) $cl['id'] ?>"><?= e($cl['nombre']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label">Comprobante</label>
              <select name="comprobante" x-model="comprobante" class="select">
                <option value="consumidor">Consumidor Final</option>
                <option value="credito_fiscal">Crédito Fiscal</option>
              </select>
            </div>
          </div>
          <div>
            <label class="label">Método de pago</label>
            <select name="metodo_pago_id" x-model.number="metodo_pago_id" class="select">
              <?php foreach ($metodos as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div x-show="metodo_pago_id===<?= $efectivoId ?>">
            <label class="label">Efectivo recibido</label>
            <input type="number" step="0.01" x-model.number="recibido" class="input text-lg font-bold" placeholder="0.00">
            <div class="flex justify-between mt-2 text-sm font-semibold" :class="cambio>0?'text-emerald-600':'text-slate-400'">
              <span>Cambio</span><span x-text="fmt(cambio)"></span>
            </div>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="pay=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-success"><?= icon('check', 'w-4 h-4') ?> Confirmar venta</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function pos() {
  return {
    productos: <?= json_encode($prodJs, JSON_UNESCAPED_UNICODE) ?>,
    search: '', cat: 0, cart: [], descuento: 0,
    pay: false, comprobante: 'consumidor', cliente_id: 1,
    metodo_pago_id: <?= $efectivoId ?>, recibido: 0,
    tasa: <?= $tasa ?>,
    get filtered() {
      const s = this.search.toLowerCase();
      return this.productos.filter(p =>
        (this.cat === 0 || p.categoria_id === this.cat) &&
        (s === '' || p.nombre.toLowerCase().includes(s) || (p.codigo || '').toLowerCase().includes(s)));
    },
    add(p) {
      if (p.stock <= 0) return;
      let it = this.cart.find(i => i.id === p.id);
      if (it) { if (it.cant < p.stock) it.cant++; }
      else this.cart.push({ id: p.id, nombre: p.nombre, precio: p.precio, itbis: p.itbis_aplica, stock: p.stock, cant: 1 });
    },
    inc(it) { if (it.cant < it.stock) it.cant++; },
    dec(it) { it.cant--; if (it.cant <= 0) this.remove(it); },
    remove(it) { this.cart = this.cart.filter(i => i !== it); },
    qtyChange(it) { let v = parseFloat(it.cant) || 1; it.cant = Math.max(1, Math.min(it.stock, v)); },
    get subtotal() { return this.cart.reduce((s, i) => s + i.precio * i.cant, 0); },
    get itbis() {
      const desc = Math.min(this.descuento || 0, this.subtotal);
      const f = this.subtotal > 0 ? (this.subtotal - desc) / this.subtotal : 1;
      return this.cart.reduce((s, i) => s + (i.itbis ? i.precio * i.cant * this.tasa / 100 : 0), 0) * f;
    },
    get total() { return (this.subtotal - Math.min(this.descuento || 0, this.subtotal)) + this.itbis; },
    get cambio() { return Math.max(0, (this.recibido || 0) - this.total); },
    fmt(n) { return '<?= $moneda ?> ' + (n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    openPay() { if (this.cart.length === 0) return; this.recibido = 0; this.pay = true; },
  };
}
</script>

<?php layout_end(); ?>
