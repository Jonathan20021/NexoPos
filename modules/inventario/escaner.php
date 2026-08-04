<?php
/**
 * Terminal de almacén — el teléfono como lector de códigos de barras.
 *
 * Pensada para usarse de pie, con una mano y con guantes: botones grandes, una
 * sola columna y la cámara siempre a un toque. Cuatro modos:
 *
 *   Consultar — qué es este producto, a cuánto se vende y cuánto queda (aquí y
 *               en las demás sucursales). No escribe nada.
 *   Entrada   — suma existencias (mercancía que llega).
 *   Salida    — resta existencias (merma, consumo interno, muestra).
 *   Conteo    — captura cantidades dentro de un conteo físico abierto.
 *
 * Por qué cada lectura se envía al servidor de inmediato en vez de acumular una
 * lista para «guardar al final»: en un almacén el teléfono se queda sin batería,
 * se bloquea o alguien cierra la pestaña. Lo que ya se contó no se puede perder.
 * El historial de la pantalla es solo para que el operario vea lo que lleva hecho.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('inventario.ver');

$sid       = current_sucursal_id();
// El nombre se resuelve desde la sucursal ACTIVA, no desde `current_user()['sucursal_nombre']`:
// ese campo es la sucursal ASIGNADA al usuario y viene NULL en quien ve todas
// (un super admin que cambió de sucursal en la barra superior). Aquí el nombre
// no es decorativo: dice a qué almacén va a entrar la mercancía.
$sucNombre = $sid === null ? '' : (string) qVal("SELECT nombre FROM sucursales WHERE id = ?", [$sid]);
$modo      = in_array(get('modo'), ['consultar', 'entrada', 'salida', 'conteo'], true) ? get('modo') : 'consultar';
$puedeMover = can('inventario.ajustar');
$puedeContar = can('conteos.contar');

// Conteos abiertos a los que este usuario tiene acceso: el modo Conteo necesita uno.
$conteosAbiertos = [];
if ($puedeContar) {
    [$scope, $scopeP] = sucursalScope('c.sucursal_id');
    $conteosAbiertos = qAll(
        "SELECT c.id, c.numero, c.descripcion, s.nombre AS sucursal
           FROM conteos c JOIN sucursales s ON s.id = c.sucursal_id
          WHERE c.estado = 'abierto' AND $scope
          ORDER BY c.id DESC",
        $scopeP
    );
}
$conteoId = (int) get('conteo_id');
if ($conteoId && !array_filter($conteosAbiertos, fn($c) => (int) $c['id'] === $conteoId)) $conteoId = 0;
if (!$conteoId && count($conteosAbiertos) === 1) $conteoId = (int) $conteosAbiertos[0]['id'];

// Un modo que el usuario no puede usar se degrada a Consultar en vez de dar un 403:
// llegar aquí desde un enlace guardado no debería ser una pared.
if (($modo === 'entrada' || $modo === 'salida') && !$puedeMover) $modo = 'consultar';
if ($modo === 'conteo' && (!$puedeContar || !$conteosAbiertos)) $modo = 'consultar';

$acciones = '<a href="' . e(url('modules/inventario/productos.php')) . '" class="btn btn-ghost">'
    . icon('box', 'w-4 h-4') . ' Productos</a>';

layout_start('Escáner de almacén',
    'Usa el teléfono como lector de códigos' . ($sid === null ? '' : ' · ' . e($sucNombre)),
    $acciones);
?>

<!-- Sin x-init: Alpine llama solo al init() del componente. Ponerlo además en
     x-init lo ejecutaría DOS veces y registraría dos oyentes de pistola lectora. -->
<div x-data="terminalEscaner()" class="max-w-2xl mx-auto pb-28">

  <!-- Aviso: sin sucursal activa no se puede mover inventario -->
  <?php if ($sid === null): ?>
    <div class="card p-4 mb-4 flex items-start gap-3 border-amber-200 bg-amber-50/60">
      <span class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
      <div>
        <h3 class="font-bold text-slate-800 text-sm">Estás viendo todas las sucursales</h3>
        <p class="text-sm text-slate-600 mt-0.5">Puedes consultar productos, pero para registrar entradas o salidas
          tienes que elegir una sucursal concreta arriba: la mercancía entra a un almacén, no a «todos».</p>
      </div>
    </div>
  <?php endif; ?>

  <!-- Selector de modo -->
  <div class="card p-2 mb-4">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
      <?php
      $modos = [
          'consultar' => ['Consultar', 'search', true],
          'entrada'   => ['Entrada', 'arrow-down', $puedeMover && $sid !== null],
          'salida'    => ['Salida', 'arrow-up', $puedeMover && $sid !== null],
          'conteo'    => ['Conteo', 'clipboard', $puedeContar && (bool) $conteosAbiertos],
      ];
      foreach ($modos as $k => [$lbl, $ic, $habilitado]):
        $activo = $modo === $k;
        $qs = http_build_query(array_filter(['modo' => $k, 'conteo_id' => $conteoId ?: null]));
      ?>
        <?php if ($habilitado): ?>
          <a href="?<?= e($qs) ?>"
             class="flex flex-col items-center justify-center gap-1 rounded-xl py-3 px-2 font-semibold text-sm transition <?= $activo ? 'bg-blue-600 text-white shadow-soft' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
            <?= icon($ic, 'w-5 h-5') ?> <?= $lbl ?>
          </a>
        <?php else: ?>
          <span class="flex flex-col items-center justify-center gap-1 rounded-xl py-3 px-2 font-semibold text-sm bg-slate-50 text-slate-300 cursor-not-allowed"
                title="<?= $k === 'conteo' ? 'No hay ningún conteo abierto' : 'No tienes permiso o falta elegir sucursal' ?>">
            <?= icon($ic, 'w-5 h-5') ?> <?= $lbl ?>
          </span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($modo === 'conteo' && count($conteosAbiertos) > 1): ?>
    <form method="get" class="card p-3 mb-4 flex items-center gap-2">
      <input type="hidden" name="modo" value="conteo">
      <label class="label mb-0 shrink-0">Conteo</label>
      <select name="conteo_id" class="select" onchange="this.form.submit()">
        <?php foreach ($conteosAbiertos as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $conteoId === (int) $c['id'] ? 'selected' : '' ?>>
            <?= e($c['numero']) ?> · <?= e($c['sucursal']) ?><?= $c['descripcion'] ? ' · ' . e($c['descripcion']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>

  <!-- Explicación del modo activo -->
  <div class="card p-4 mb-4 flex items-start gap-3">
    <span class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0
      <?= ['consultar'=>'bg-blue-50 text-blue-600','entrada'=>'bg-emerald-50 text-emerald-600','salida'=>'bg-rose-50 text-rose-600','conteo'=>'bg-indigo-50 text-indigo-600'][$modo] ?>">
      <?= icon(['consultar'=>'search','entrada'=>'arrow-down','salida'=>'arrow-up','conteo'=>'clipboard'][$modo], 'w-5 h-5') ?>
    </span>
    <div class="min-w-0">
      <h3 class="font-bold text-slate-800 text-sm">
        <?= ['consultar'=>'Consultar producto','entrada'=>'Entrada de mercancía','salida'=>'Salida de mercancía','conteo'=>'Captura de conteo físico'][$modo] ?>
      </h3>
      <p class="text-sm text-slate-500 mt-0.5">
        <?php if ($modo === 'consultar'): ?>
          Escanea y verás qué producto es, su precio y las existencias en cada sucursal. No se modifica nada.
        <?php elseif ($modo === 'entrada'): ?>
          Cada lectura <strong>suma</strong> existencias en <?= e($sucNombre) ?> y queda registrada en el kardex a tu nombre.
        <?php elseif ($modo === 'salida'): ?>
          Cada lectura <strong>resta</strong> existencias en <?= e($sucNombre) ?>. Úsalo para merma, consumo interno o mercancía dañada.
        <?php else: ?>
          Cada lectura suma a lo contado en el conteo abierto. Los ajustes al inventario no se aplican aquí:
          se revisan y se confirman desde la pantalla del conteo.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <!-- Cantidad por lectura -->
  <?php if ($modo !== 'consultar'): ?>
    <div class="card p-4 mb-4">
      <label class="label">Cantidad que suma cada lectura</label>
      <div class="flex items-center gap-2">
        <button type="button" @click="cant = Math.max(1, cant - 1)" class="btn btn-soft w-12 h-12 justify-center shrink-0"><?= icon('minus', 'w-5 h-5') ?></button>
        <input type="number" step="0.001" min="0.001" x-model.number="cant" class="input text-center text-2xl font-extrabold h-12 tabular-nums">
        <button type="button" @click="cant = cant + 1" class="btn btn-soft w-12 h-12 justify-center shrink-0"><?= icon('plus', 'w-5 h-5') ?></button>
      </div>
      <p class="text-xs text-slate-400 mt-2">
        Déjalo en 1 para ir contando pieza por pieza. Súbelo cuando entra una caja completa
        y prefieras escanear una sola vez.
      </p>
    </div>
  <?php endif; ?>

  <!-- Botón grande de escanear + entrada manual -->
  <div class="card p-4 mb-4">
    <button type="button" @click="escanear()"
            class="w-full rounded-2xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-lg py-6 flex items-center justify-center gap-3 transition shadow-soft">
      <?= icon('barcode', 'w-7 h-7') ?> Escanear con la cámara
    </button>

    <div class="flex items-center gap-3 my-3">
      <span class="h-px flex-1 bg-slate-200"></span>
      <span class="text-xs text-slate-400 font-semibold">o escribe / dispara con la pistola</span>
      <span class="h-px flex-1 bg-slate-200"></span>
    </div>

    <form @submit.prevent="encolar(manual)" class="flex gap-2">
      <input type="text" x-model="manual" data-escaner placeholder="Código de barras o SKU"
             autocomplete="off" enterkeyhint="send" class="input flex-1">
      <button type="submit" class="btn btn-primary px-5" :disabled="!manual.trim() || ocupado">Buscar</button>
    </form>
    <p class="text-xs text-slate-400 mt-2" x-show="!camaraOk" x-cloak x-text="motivoCamara"></p>

    <!-- Cola pendiente: escaneando en cadena, el operario tiene que ver que las
         lecturas se están registrando y no se perdió ninguna. -->
    <p x-show="cola.length > 0" x-cloak
       class="mt-2 flex items-center gap-2 text-xs font-semibold text-blue-700 bg-blue-50 rounded-lg px-3 py-2">
      <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"/></svg>
      Registrando <span x-text="cola.length"></span> lectura(s) en cola…
    </p>
  </div>

  <!-- Última lectura -->
  <template x-if="ultimo">
    <div class="card p-4 mb-4" :class="ultimo.error ? 'border-rose-200 bg-rose-50/50' : 'border-emerald-200 bg-emerald-50/40'">
      <template x-if="ultimo.error">
        <div class="flex items-start gap-3">
          <span class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
          <div class="min-w-0">
            <h3 class="font-bold text-slate-800">No se pudo registrar</h3>
            <p class="text-sm text-rose-700 mt-0.5" x-text="ultimo.error"></p>
            <template x-if="ultimo.crear">
              <a :href="<?= json_encode(url('modules/inventario/productos.php')) ?> + '?nuevo_barras=' + encodeURIComponent(ultimo.codigo)"
                 class="btn btn-soft btn-sm mt-3"><?= icon('plus', 'w-3.5 h-3.5') ?> Crear producto con este código</a>
            </template>
          </div>
        </div>
      </template>

      <template x-if="!ultimo.error && ultimo.producto">
        <div>
          <div class="flex items-start gap-3">
            <template x-if="ultimo.producto.imagen">
              <img :src="ultimo.producto.imagen" alt="" class="w-14 h-14 rounded-xl object-cover border border-slate-200 shrink-0">
            </template>
            <template x-if="!ultimo.producto.imagen">
              <span class="w-14 h-14 rounded-xl bg-white border border-slate-200 text-slate-400 flex items-center justify-center shrink-0"><?= icon('box', 'w-6 h-6') ?></span>
            </template>
            <div class="min-w-0 flex-1">
              <h3 class="font-bold text-slate-800 leading-tight" x-text="ultimo.producto.nombre"></h3>
              <!-- Un producto desactivado no aparece en el POS: si sigue llegando
                   mercancía suya, hay que saberlo aquí y no descubrirlo en la caja. -->
              <template x-if="ultimo.producto.activo === 0">
                <span class="badge badge-amber mt-1">Producto desactivado · no se vende en el POS</span>
              </template>
              <p class="text-xs text-slate-500 mt-0.5">
                <span x-text="ultimo.producto.codigo"></span>
                <template x-if="ultimo.producto.marca"><span> · <span x-text="ultimo.producto.marca"></span></span></template>
                <template x-if="ultimo.producto.codigo_barras"><span class="block font-mono" x-text="ultimo.producto.codigo_barras"></span></template>
              </p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-lg font-extrabold text-blue-600" x-text="money(ultimo.producto.precio_venta)"></p>
              <p class="text-[11px] text-slate-400">precio de venta</p>
            </div>
          </div>

          <template x-if="ultimo.mensaje">
            <p class="mt-3 text-sm font-semibold rounded-xl px-3 py-2"
               :class="ultimo.tipo==='salida' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'"
               x-text="ultimo.mensaje"></p>
          </template>

          <div class="mt-3 grid grid-cols-2 gap-2">
            <template x-for="s in ultimo.producto.sucursales" :key="s.id">
              <div class="rounded-xl bg-white border border-slate-200 px-3 py-2">
                <p class="text-[11px] text-slate-400 truncate" x-text="s.nombre"></p>
                <p class="font-bold tabular-nums"
                   :class="s.cantidad <= 0 ? 'text-rose-600' : (s.cantidad <= ultimo.producto.stock_minimo ? 'text-amber-600' : 'text-slate-800')"
                   x-text="qty(s.cantidad) + ' ' + ultimo.producto.unidad"></p>
              </div>
            </template>
          </div>
        </div>
      </template>
    </div>
  </template>

  <!-- Historial de la sesión -->
  <div class="card overflow-hidden" x-show="historial.length > 0" x-cloak>
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
      <h3 class="font-bold text-slate-800 text-sm flex items-center gap-2">
        <?= icon('history', 'w-4 h-4 text-slate-400') ?> Lecturas de esta sesión
        <span class="badge badge-slate" x-text="historial.length"></span>
      </h3>
      <button type="button" @click="historial = []" class="text-xs font-semibold text-slate-400 hover:text-slate-600">Limpiar</button>
    </div>
    <ul class="divide-y divide-slate-100 max-h-80 overflow-y-auto">
      <template x-for="(h, i) in historial" :key="i">
        <li class="px-4 py-2.5 flex items-center gap-3">
          <span class="w-2 h-2 rounded-full shrink-0" :class="h.ok ? 'bg-emerald-500' : 'bg-rose-500'"></span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-700 truncate" x-text="h.titulo"></p>
            <p class="text-[11px] text-slate-400 truncate" x-text="h.detalle"></p>
          </div>
          <span class="text-[11px] text-slate-400 tabular-nums shrink-0" x-text="h.hora"></span>
        </li>
      </template>
    </ul>
  </div>

  <p class="text-center text-xs text-slate-400 mt-6 px-4">
    Cada lectura se guarda en el servidor al momento. Si el teléfono se apaga o se cierra la
    pantalla, no se pierde nada de lo ya registrado.
  </p>
</div>

<?= escaner_script() ?>
<script>
function terminalEscaner() {
  return {
    modo: <?= json_encode($modo) ?>,
    conteoId: <?= (int) $conteoId ?>,
    sucursalId: <?= $sid === null ? 'null' : (int) $sid ?>,
    cant: 1,
    manual: '',
    ocupado: false,
    cola: [],
    ultimo: null,
    historial: [],
    camaraOk: true,
    motivoCamara: '',
    _quitarTeclado: null,

    init() {
      if (!window.NexoEscaner) {
        // Si el módulo del escáner no cargó, la pantalla sigue sirviendo: se
        // escribe el código a mano o se dispara la pistola sobre el campo.
        this.camaraOk = false;
        this.motivoCamara = 'No se pudo cargar el escáner. Escribe el código a mano o usa una pistola lectora.';
        return;
      }
      this.camaraOk = NexoEscaner.soportado();
      this.motivoCamara = this.camaraOk ? '' : NexoEscaner.motivoNoSoportado();

      // Pistola USB/Bluetooth: escribe muy rápido y cierra con Enter. Se escucha
      // en toda la pantalla para que funcione sin tener que enfocar el campo.
      this._quitarTeclado = NexoEscaner.teclado({
        onCodigo: (codigo) => this.encolar(codigo),
      });
    },
    destroy() { if (this._quitarTeclado) this._quitarTeclado(); },

    escanear() {
      if (!window.NexoEscaner) return;
      NexoEscaner.abrir({
        titulo: {consultar:'Consultar', entrada:'Entrada de mercancía', salida:'Salida de mercancía', conteo:'Conteo físico'}[this.modo],
        ayuda: this.modo === 'consultar'
          ? 'Apunta al código. Puedes escanear uno tras otro.'
          : 'Cada lectura registra ' + this.qty(this.cant) + '. Puedes escanear en cadena.',
        continuo: true,
        onLeer: (codigo) => this.encolar(codigo),
      });
    },

    /**
     * Punto de entrada de TODA lectura (cámara, pistola o escrita a mano).
     *
     * Se encola en vez de procesarse al vuelo. Escaneando en cadena, la lectura
     * siguiente llega mientras la anterior todavía viaja al servidor; si se
     * descartara por «estar ocupado», el operario contaría diez piezas y se
     * guardarían siete, sin que nada se lo dijera. Una lectura perdida en
     * silencio es el peor fallo posible en un inventario.
     */
    encolar(codigo) {
      codigo = String(codigo || '').trim();
      if (!codigo) return;
      this.cola.push(codigo);
      this.bombear();
    },

    async bombear() {
      if (this.ocupado) return;          // ya hay un ciclo vaciando la cola
      this.ocupado = true;
      try {
        while (this.cola.length) await this.enviar(this.cola.shift());
      } finally {
        this.ocupado = false;
      }
    },

    async enviar(codigo) {
      codigo = String(codigo || '').trim();
      if (!codigo) return;
      this.manual = '';
      try {
        // Siempre se identifica primero el producto: así, si el código no existe,
        // se avisa antes de intentar mover un inventario que no sabemos de quién es.
        const p = await this.api({ accion: 'buscar', codigo: codigo });
        if (!p.encontrado) {
          this.fallar(codigo, p.error || 'Producto no encontrado.', p.valido);
          return;
        }
        const prod = p.producto;

        if (this.modo === 'consultar') {
          this.acertar(prod, '', '', codigo);
          return;
        }
        if (this.modo === 'conteo') {
          const r = await this.api({ accion: 'contar', conteo_id: this.conteoId, producto_id: prod.id, cantidad: this.cant, modo: 'sumar' });
          if (!r.ok) { this.fallar(codigo, r.error, false, prod); return; }
          this.acertar(prod, r.mensaje, 'entrada', codigo);
          return;
        }
        const r = await this.api({ accion: 'mover', producto_id: prod.id, cantidad: this.cant, tipo: this.modo });
        if (!r.ok) { this.fallar(codigo, r.error, false, prod); return; }
        // El stock que devuelve el movimiento es el bueno: la ficha se pidió antes.
        // Se localiza la sucursal por ID, no por nombre: dos sucursales pueden
        // llamarse igual y el nombre además puede venir vacío.
        prod.stock = r.stock;
        const suc = prod.sucursales.find(s => s.id === this.sucursalId);
        if (suc) suc.cantidad = r.stock;
        this.acertar(prod, r.mensaje, r.tipo, codigo);
      } catch (e) {
        // Cubre tanto un fallo de red como una respuesta que no es JSON (por
        // ejemplo, la pantalla de sesión expirada devuelta como HTML).
        this.fallar(codigo, 'No se pudo registrar la lectura: sin respuesta del servidor. Revisa la conexión y repítela.', false);
      }
    },

    // Siempre POST, también para consultar. El .htaccess redirige con 301 las
    // peticiones GET a `.php` hacia la URL sin extensión; en el almacén eso sería
    // un salto de más en cada lectura. Los POST no se redirigen.
    async api(datos) {
      const res = await fetch(<?= json_encode(url('modules/inventario/api_escaner.php')) ?>, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': <?= json_encode(csrf_token()) ?> },
        credentials: 'same-origin',
        body: JSON.stringify(datos),
      });
      return await res.json();
    },

    acertar(prod, mensaje, tipo, codigo) {
      this.ultimo = { producto: prod, mensaje: mensaje, tipo: tipo, error: '', codigo: codigo };
      this.anotar(true, prod.nombre, mensaje || (this.qty(prod.stock) + ' ' + prod.unidad + ' en existencia'));
    },

    fallar(codigo, error, puedeCrear, prod) {
      if (window.NexoEscaner) NexoEscaner.pitar(false);
      this.ultimo = { producto: prod || null, mensaje: '', tipo: '', error: error, codigo: codigo, crear: !!puedeCrear };
      this.anotar(false, codigo, error);
    },

    anotar(ok, titulo, detalle) {
      const d = new Date();
      this.historial.unshift({
        ok: ok, titulo: titulo, detalle: detalle,
        hora: String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'),
      });
      if (this.historial.length > 100) this.historial.pop();
    },

    money(n) { return '<?= e(setting('moneda', 'RD$')) ?> ' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    qty(n) { const v = Number(n) || 0; return v % 1 === 0 ? String(v) : v.toFixed(3).replace(/0+$/, '').replace(/\.$/, ''); },
  };
}
</script>

<?php layout_end(); ?>
