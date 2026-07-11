<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('pos.ver');

$sid = current_sucursal_id();
$uid = (int) current_user()['id'];
$tasa = (float) setting('itbis_tasa', DEFAULT_ITBIS);
$moneda = setting('moneda', 'RD$');
$puedeMuestra = can('ventas.muestra'); // habilita el toggle de muestra en el carrito

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
    "SELECT p.id, p.codigo, p.nombre, p.precio_venta, p.itbis_aplica, p.categoria_id, p.marca_id, COALESCE(s.cantidad,0) AS stock, COALESCE(c.color,'slate') AS color
     FROM productos p LEFT JOIN inventario_stock s ON s.producto_id=p.id AND s.sucursal_id=?
     LEFT JOIN categorias c ON c.id=p.categoria_id
     WHERE p.activo=1 AND p.tipo='producto' ORDER BY p.nombre", [$sid]
);
$prodJs = array_map(function ($p) {
    // El precio mostrado ya trae la promoción vigente (el servidor la recalcula al vender).
    $pr = aplicarPromocion((float) $p['precio_venta'], $p, 'pos');
    return [
        'id' => (int) $p['id'], 'codigo' => $p['codigo'], 'nombre' => $p['nombre'],
        'precio' => $pr['precio'], 'precio_lista' => $pr['original'],
        'promo' => $pr['promo'] ? $pr['etiqueta'] : '',
        'itbis_aplica' => (int) $p['itbis_aplica'],
        'categoria_id' => (int) $p['categoria_id'], 'stock' => (float) $p['stock'], 'color' => $p['color'],
    ];
}, $productos);

// Meta personal de la vendedora: se muestra un progreso compacto arriba del POS.
$miMeta = metaPersonalActiva($uid);
if ($miMeta) {
    $mp = metaProgreso($miMeta);
    $mpCol = metaColor($mp['pct']);
    ?>
    <div class="card p-4 mb-4 flex flex-col sm:flex-row sm:items-center gap-3">
      <span class="w-10 h-10 rounded-xl badge-<?= $mpCol ?> flex items-center justify-center shrink-0"><?= icon('trending', 'w-5 h-5') ?></span>
      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between gap-3 mb-1">
          <p class="text-sm font-semibold text-slate-700">Mi meta<?= $mp['dias_restantes'] > 0 ? ' · ' . $mp['dias_restantes'] . ' día' . ($mp['dias_restantes'] === 1 ? '' : 's') : '' ?></p>
          <p class="text-sm text-slate-500"><span class="font-bold text-<?= $mpCol ?>-600"><?= e($moneda) ?> <?= number_format($mp['vendido'], 2) ?></span> / <?= e($moneda) ?> <?= number_format($mp['objetivo'], 2) ?></p>
        </div>
        <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full bg-<?= $mpCol ?>-500 transition-all" style="width: <?= $mp['pct'] ?>%"></div>
        </div>
        <p class="text-xs text-slate-400 mt-1"><?= $mp['pct'] ?>% · <?= $mp['falta'] > 0 ? 'Faltan ' . e($moneda) . ' ' . number_format($mp['falta'], 2) : '¡Meta alcanzada!' ?></p>
      </div>
    </div>
    <?php
}

$categorias = qAll("SELECT DISTINCT c.id, c.nombre, c.color FROM categorias c JOIN productos p ON p.categoria_id=c.id WHERE p.activo=1 ORDER BY c.nombre");
$metodos = qAll("SELECT id, nombre, afecta_caja FROM metodos_pago WHERE activo=1 ORDER BY id");
$clientes = qAll("SELECT id, nombre FROM clientes WHERE activo=1 ORDER BY nombre");
$efectivoId = (int) (qVal("SELECT id FROM metodos_pago WHERE afecta_caja=1 AND activo=1 ORDER BY id LIMIT 1") ?: 1);
$badgeMap = ['blue'=>'badge-blue','emerald'=>'badge-emerald','amber'=>'badge-amber','rose'=>'badge-rose','indigo'=>'badge-indigo','cyan'=>'badge-cyan','sky'=>'badge-sky','pink'=>'badge-pink','violet'=>'badge-violet','slate'=>'badge-slate'];
?>

<div x-data="pos()" class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start pb-24 lg:pb-0">

  <!-- Estado del modo offline (conexión / cola de sincronización) -->
  <div class="lg:col-span-3 flex flex-wrap items-center gap-2" x-show="!online || pendientes>0 || errores>0" x-cloak>
    <span x-show="!online" class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-amber-100 text-amber-700">
      <span class="w-2 h-2 rounded-full bg-amber-500"></span> Sin conexión · las ventas se guardan localmente
    </span>
    <span x-show="online && pendientes>0" class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-blue-100 text-blue-700">
      <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/></svg>
      Sincronizando <span x-text="pendientes"></span> venta(s)…
    </span>
    <span x-show="!online && pendientes>0" class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-600">
      <span x-text="pendientes"></span> venta(s) en espera de enviar
    </span>
    <button type="button" x-show="online && pendientes>0" @click="sincronizar()" class="text-xs font-semibold text-blue-600 hover:text-blue-700 underline">Reintentar ahora</button>
    <button type="button" x-show="errores>0" @click="abrirErrores()" class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-rose-100 text-rose-700 hover:bg-rose-200">
      <?= icon('alert', 'w-3.5 h-3.5') ?> <span x-text="errores"></span> venta(s) con error · revisar
    </button>
  </div>

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
          <div class="mt-1 flex items-baseline gap-1.5 flex-wrap">
            <p class="text-base font-extrabold text-blue-600" x-text="fmt(p.precio)"></p>
            <template x-if="p.promo">
              <span class="text-xs text-slate-400 line-through" x-text="fmt(p.precio_lista)"></span>
            </template>
            <template x-if="p.promo">
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-500 text-white" x-text="p.promo"></span>
            </template>
          </div>
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
        <div class="bg-slate-50 rounded-xl p-2.5" :class="it.muestra ? 'ring-1 ring-amber-300 bg-amber-50/60' : ''">
          <div class="flex items-center gap-2">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-slate-700 truncate" x-text="it.nombre"></p>
              <p class="text-xs" :class="it.muestra ? 'text-amber-600 font-semibold' : 'text-slate-400'"
                 x-text="it.muestra ? 'MUESTRA · ' + fmt(0) : fmt(it.precio)"></p>
            </div>
            <div class="flex items-center gap-1.5">
              <button @click="dec(it)" class="w-7 h-7 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 flex items-center justify-center"><?= icon('minus', 'w-3.5 h-3.5') ?></button>
              <input type="text" x-model.number="it.cant" @change="qtyChange(it)" class="w-10 text-center text-sm font-bold bg-white border border-slate-200 rounded-lg py-1">
              <button @click="inc(it)" class="w-7 h-7 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 flex items-center justify-center"><?= icon('plus', 'w-3.5 h-3.5') ?></button>
            </div>
            <div class="w-20 text-right">
              <p class="text-sm font-bold" :class="it.muestra ? 'text-amber-600' : 'text-slate-800'" x-text="fmt(lineTotal(it))"></p>
            </div>
          </div>
          <?php if ($puedeMuestra): ?>
            <div class="flex items-center justify-end mt-1.5">
              <button type="button" @click="toggleMuestra(it)"
                      class="inline-flex items-center gap-1 text-xs font-semibold rounded-md px-2 py-0.5 transition-colors duration-200 cursor-pointer"
                      :class="it.muestra ? 'bg-amber-500 text-white hover:bg-amber-600' : 'text-slate-400 hover:text-amber-600 hover:bg-amber-50'"
                      :aria-pressed="it.muestra.toString()"
                      :title="it.muestra ? 'Cobrar esta línea normalmente' : 'Entregar esta línea como muestra (RD$0.00)'">
                <?= icon('tag', 'w-3.5 h-3.5') ?>
                <span x-text="it.muestra ? 'Es muestra' : 'Marcar muestra'"></span>
              </button>
            </div>
          <?php endif; ?>
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

  <!-- Barra de cobro fija (solo móvil) -->
  <div x-show="cart.length>0" x-transition style="display:none"
       class="lg:hidden fixed bottom-0 left-0 right-0 z-30 bg-white border-t border-slate-200 px-4 py-3 flex items-center gap-3 shadow-[0_-6px_24px_-10px_rgba(15,23,42,0.25)]">
    <div class="flex-1 min-w-0">
      <p class="text-xs text-slate-400" x-text="cart.reduce((s,i)=>s+i.cant,0) + ' artículo(s)'"></p>
      <p class="text-lg font-extrabold text-slate-800 truncate" x-text="fmt(total)"></p>
    </div>
    <button @click="openPay()" class="btn btn-primary py-3 px-6 shrink-0"><?= icon('cash', 'w-5 h-5') ?> Cobrar</button>
  </div>

  <!-- Modal de cobro -->
  <div x-show="pay" x-transition.opacity style="display:none" class="modal-overlay !bg-slate-900/50" @click.self="pay=false" @keydown.escape.window="pay=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form @submit.prevent="confirmar()">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Procesar cobro</h3>
          <button type="button" @click="pay=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
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
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Método de pago</label>
              <select name="metodo_pago_id" x-model.number="metodo_pago_id" class="select">
                <?php foreach ($metodos as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label">¿Cómo nos encontró?</label>
              <select name="canal_venta" x-model="canal_venta" class="select">
                <?php foreach (canalesVenta() as $ch): ?><option value="<?= e($ch) ?>"><?= e($ch) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div x-show="metodo_pago_id===<?= $efectivoId ?>">
            <label class="label">Efectivo recibido</label>
            <input type="number" step="0.01" x-model.number="recibido" class="input text-lg font-bold" placeholder="0.00">
            <div class="flex justify-between mt-2 text-sm font-semibold" :class="cambio>0?'text-emerald-600':'text-slate-400'">
              <span>Cambio</span><span x-text="fmt(cambio)"></span>
            </div>
          </div>
          <div x-show="!online" class="flex items-start gap-2 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 text-sm px-3 py-2.5">
            <?= icon('alert', 'w-4 h-4 mt-0.5 shrink-0') ?>
            <span>Sin conexión. La venta se guardará y se enviará automáticamente al volver el internet. El comprobante fiscal (NCF) se asignará al sincronizar.</span>
          </div>
          <div x-show="payError" x-transition class="flex items-start gap-2 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm px-3 py-2.5">
            <?= icon('alert', 'w-4 h-4 mt-0.5 shrink-0') ?><span x-text="payError"></span>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="pay=false" :disabled="procesando" class="btn btn-ghost">Cancelar</button>
          <button type="submit" :disabled="procesando" class="btn btn-success disabled:opacity-60">
            <span x-show="!procesando"><?= icon('check', 'w-4 h-4') ?> <span x-text="online ? 'Confirmar venta' : 'Guardar venta (offline)'"></span></span>
            <span x-show="procesando" class="inline-flex items-center gap-2"><svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/></svg> Procesando…</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Ticket provisional (venta guardada offline) -->
  <div x-show="provisional" x-transition.opacity style="display:none" class="modal-overlay !bg-slate-900/50" @click.self="provisional=false" @keydown.escape.window="provisional=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-sm" @click.stop id="ticketProvisional">
      <div class="p-6 text-center">
        <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center mx-auto mb-3"><?= icon('save', 'w-7 h-7') ?></div>
        <h3 class="text-lg font-bold text-slate-800">Venta guardada</h3>
        <p class="text-sm text-slate-500 mt-1">Se registró sin conexión. Se enviará automáticamente y su comprobante fiscal (NCF) se asignará al sincronizar.</p>
        <div class="text-left mt-4 border border-dashed border-slate-300 rounded-xl p-4 text-sm">
          <p class="text-center font-bold text-slate-700"><?= e($GLOBALS['empresa']['nombre'] ?? APP_NAME) ?></p>
          <p class="text-center text-[11px] uppercase tracking-wide text-amber-600 font-bold mt-0.5">Ticket provisional · sin valor fiscal</p>
          <p class="text-center text-xs text-slate-400 mb-3" x-text="prov ? prov.fecha.toLocaleString('es-DO') : ''"></p>
          <template x-for="(it,i) in (prov ? prov.items : [])" :key="i">
            <div class="flex justify-between gap-2 py-0.5">
              <span class="truncate text-slate-600"><span x-text="it.cant"></span>× <span x-text="it.nombre"></span><span x-show="it.muestra" class="text-amber-600 font-semibold"> (muestra)</span></span>
              <span class="font-semibold text-slate-700" x-text="fmt(it.precio * it.cant)"></span>
            </div>
          </template>
          <div class="flex justify-between border-t border-slate-200 mt-2 pt-2 font-extrabold text-slate-800">
            <span>Total</span><span x-text="prov ? fmt(prov.total) : ''"></span>
          </div>
        </div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="provisional=false" class="btn btn-ghost">Cerrar</button>
        <button type="button" @click="imprimirProvisional()" class="btn btn-primary"><?= icon('print', 'w-4 h-4') ?> Imprimir</button>
      </div>
    </div>
  </div>

  <!-- Ventas con error de sincronización -->
  <div x-show="verErrores" x-transition.opacity style="display:none" class="modal-overlay !bg-slate-900/50" @click.self="verErrores=false" @keydown.escape.window="verErrores=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('alert', 'w-5 h-5 text-rose-500') ?> Ventas con error</h3>
        <button type="button" @click="verErrores=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
      </div>
      <div class="p-4 space-y-2 max-h-[60vh] overflow-y-auto">
        <p class="text-xs text-slate-500 px-1">Estas ventas no pudieron registrarse al sincronizar (por ejemplo, stock insuficiente). Revísalas y vuelve a capturarlas si corresponde.</p>
        <template x-for="er in listaErrores" :key="er.uuid">
          <div class="rounded-xl border border-rose-200 bg-rose-50/60 p-3">
            <div class="flex items-center justify-between gap-2">
              <p class="text-sm font-bold text-slate-700" x-text="fmt(er.payload.total || 0)"></p>
              <button type="button" @click="descartarError(er.uuid)" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Descartar</button>
            </div>
            <p class="text-xs text-rose-600 mt-0.5" x-text="er.error"></p>
            <p class="text-[11px] text-slate-400 mt-0.5" x-text="new Date(er.createdAt).toLocaleString('es-DO')"></p>
          </div>
        </template>
        <p x-show="listaErrores.length===0" class="text-center text-sm text-slate-400 py-6">No hay ventas con error.</p>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(asset('js/pos-offline.js')) ?>"></script>
<script>
function pos() {
  return {
    productos: <?= json_encode($prodJs, JSON_UNESCAPED_UNICODE) ?>,
    search: '', cat: 0, cart: [], descuento: 0,
    pay: false, comprobante: 'consumidor', cliente_id: 1,
    metodo_pago_id: <?= $efectivoId ?>, recibido: 0,
    canal_venta: 'Mostrador',
    tasa: <?= $tasa ?>,
    puedeMuestra: <?= $puedeMuestra ? 'true' : 'false' ?>,
    // Estado del modo offline
    online: navigator.onLine, pendientes: 0, errores: 0,
    procesando: false, payError: '',
    provisional: false, prov: null,
    verErrores: false, listaErrores: [],
    init() {
      var self = this;
      window.addEventListener('online',  function () { self.online = true; });
      window.addEventListener('offline', function () { self.online = false; });
      PosOffline.init({
        syncUrl: '<?= e(url('modules/pos/sync_venta.php')) ?>',
        csrf: '<?= e(csrf_token()) ?>',
        onChange: function (s) { self.pendientes = s.pending; self.errores = s.errors; },
      });
    },
    // Fecha/hora local en formato del servidor (para conservar el momento real offline).
    _ahora() {
      var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' +
             p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    },
    async confirmar() {
      if (this.cart.length === 0 || this.procesando) return;
      this.procesando = true; this.payError = '';
      var payload = {
        cart: this.cart.map(function (i) { return { id: i.id, cant: i.cant, muestra: i.muestra ? 1 : 0 }; }),
        descuento: this.descuento || 0,
        cliente_id: this.cliente_id,
        comprobante: this.comprobante,
        metodo_pago_id: this.metodo_pago_id,
        canal: this.canal_venta,
        uuid: PosOffline.uuid(),
        fecha: this._ahora(),
        total: this.total,   // solo informativo (para la lista de errores); el servidor recalcula
      };
      // Copia para el ticket provisional y para descontar stock local.
      var snap = {
        items: this.cart.map(function (i) { return { nombre: i.nombre, cant: i.cant, precio: i.muestra ? 0 : i.precio, muestra: i.muestra }; }),
        total: this.total, fecha: new Date(),
      };
      var vendidos = this.cart.map(function (i) { return { id: i.id, cant: i.cant }; });
      var r = await PosOffline.submitSale(payload);
      this.procesando = false;
      if (r.outcome === 'online') {
        window.location = '<?= e(url('modules/pos/ticket.php')) ?>?id=' + r.data.id + '&print=1';
        return;
      }
      if (r.outcome === 'queued') {
        this._descontarStock(vendidos);
        this.pay = false;
        this.prov = snap;
        this.provisional = true;
        this.cart = []; this.descuento = 0; this.recibido = 0;
        return;
      }
      // Error de negocio: se queda en el modal para corregir.
      this.payError = r.error || 'No se pudo registrar la venta.';
    },
    _descontarStock(vendidos) {
      var map = {};
      this.productos.forEach(function (p) { map[p.id] = p; });
      vendidos.forEach(function (v) { if (map[v.id]) map[v.id].stock = Math.max(0, map[v.id].stock - v.cant); });
    },
    sincronizar() { PosOffline.flush(); },
    async abrirErrores() {
      this.listaErrores = await PosOffline.listErrors();
      this.verErrores = true;
    },
    async descartarError(id) {
      await PosOffline.dismissError(id);
      this.listaErrores = await PosOffline.listErrors();
      if (this.listaErrores.length === 0) this.verErrores = false;
    },
    imprimirProvisional() { window.print(); },
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
      else this.cart.push({ id: p.id, nombre: p.nombre, precio: p.precio, itbis: p.itbis_aplica, stock: p.stock, cant: 1, muestra: false });
    },
    inc(it) { if (it.cant < it.stock) it.cant++; },
    dec(it) { it.cant--; if (it.cant <= 0) this.remove(it); },
    remove(it) { this.cart = this.cart.filter(i => i !== it); },
    qtyChange(it) { let v = parseFloat(it.cant) || 1; it.cant = Math.max(1, Math.min(it.stock, v)); },
    toggleMuestra(it) { if (!this.puedeMuestra) return; it.muestra = !it.muestra; },
    // Una muestra no aporta al cobro: su total de línea es 0.
    lineTotal(it) { return it.muestra ? 0 : it.precio * it.cant; },
    get subtotal() { return this.cart.reduce((s, i) => s + this.lineTotal(i), 0); },
    get itbis() {
      const desc = Math.min(this.descuento || 0, this.subtotal);
      const f = this.subtotal > 0 ? (this.subtotal - desc) / this.subtotal : 1;
      return this.cart.reduce((s, i) => s + (!i.muestra && i.itbis ? i.precio * i.cant * this.tasa / 100 : 0), 0) * f;
    },
    get total() { return (this.subtotal - Math.min(this.descuento || 0, this.subtotal)) + this.itbis; },
    get cambio() { return Math.max(0, (this.recibido || 0) - this.total); },
    fmt(n) { return '<?= $moneda ?> ' + (n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    openPay() { if (this.cart.length === 0) return; this.recibido = 0; this.payError = ''; this.pay = true; },
  };
}
</script>

<?php layout_end(); ?>
