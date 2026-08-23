<?php
/**
 * Buscador global de la barra superior.
 *
 * El campo visible es un botón: al pulsarlo (o con Ctrl/⌘+K) abre un panel que
 * consulta modules/busqueda/api.php mientras escribes, con navegación por
 * teclado. Si el JavaScript no carga, el enlace lleva a la página de búsqueda
 * completa, que hace exactamente lo mismo desde el servidor.
 */
$atajos = buscar_atajos();
?>
<div x-data="buscadorGlobal(<?= e(json_encode(url('modules/busqueda/api.php'))) ?>, <?= e(json_encode(url('modules/busqueda/index.php'))) ?>)"
     @keydown.window.prevent.ctrl.k="abrir()" @keydown.window.prevent.meta.k="abrir()"
     @keydown.escape.window="cerrar()">

  <!-- Disparador -->
  <button type="button" @click="abrir()"
          class="hidden sm:flex items-center gap-2.5 bg-slate-100 hover:bg-slate-200/70 rounded-xl px-3.5 h-10 w-64 lg:w-80 max-w-full text-left transition focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/15"
          aria-label="Buscar en todo el sistema">
    <span class="text-slate-400 shrink-0"><?= icon('search', 'w-4 h-4') ?></span>
    <span class="text-sm text-slate-400 flex-1 truncate">Buscar en todo…</span>
    <kbd class="hidden lg:inline-flex items-center gap-0.5 rounded-md border border-slate-300/70 bg-white px-1.5 py-0.5 text-[10.5px] font-semibold text-slate-400 shrink-0">
      <span x-text="tecla">Ctrl</span> K
    </kbd>
  </button>
  <a href="<?= e(url('modules/busqueda/index.php')) ?>" @click.prevent="abrir()"
     class="sm:hidden w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-500 hover:bg-slate-100 transition"
     aria-label="Buscar"><?= icon('search', 'w-5 h-5') ?></a>

  <!-- Panel
       ------------------------------------------------------------------
       TELEPORTADO AL BODY, y no es por gusto.

       Este buscador vive dentro del <header>, que lleva `backdrop-blur`.
       `backdrop-filter` crea un BLOQUE CONTENEDOR para los descendientes
       `position: fixed` —igual que `transform`—, así que `fixed inset-0` no
       se anclaba a la ventana sino a la cabecera: 64 px de alto y empezando
       después de la barra lateral.

       El resultado era el que se veía: el panel descentrado hacia la derecha
       y el fondo oscuro sin cubrir ni la barra lateral ni la página.

       `x-teleport` mueve el nodo al final del body, donde `fixed` vuelve a
       significar lo que significa. Alpine mantiene el scope, así que
       `visible`, `x-ref` y los eventos siguen funcionando desde aquí.
       ------------------------------------------------------------------ -->
  <template x-teleport="body">
  <!-- SIN animación de entrada, y es deliberado. Sobre un nodo teleportado ni
       las transiciones de Alpine ni una animación CSS con `fill: both` llegan
       a correr —arrancan dentro de un subárbol oculto— y el panel se quedaba
       clavado en su primer fotograma, invisible, con el fondo ya pintado.
       Una paleta de comandos se abre al instante de todos modos. -->
  <div x-show="visible" x-cloak style="display:none"
       class="fixed inset-0 z-[70] flex items-start justify-center p-4 sm:pt-24 bg-slate-900/40 backdrop-blur-[2px]"
       @click.self="cerrar()">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-pop border border-slate-100 overflow-hidden flex flex-col max-h-[min(80vh,620px)]">

      <!-- Entrada -->
      <div class="flex items-center gap-3 px-4 border-b border-slate-100 shrink-0">
        <span class="text-slate-400 shrink-0"><?= icon('search', 'w-5 h-5') ?></span>
        <input x-ref="campo" x-model="q" @input.debounce.220ms="buscar()"
               @keydown.arrow-down.prevent="mover(1)" @keydown.arrow-up.prevent="mover(-1)"
               @keydown.enter.prevent="abrirSeleccion()"
               type="text" autocomplete="off" spellcheck="false"
               placeholder="Producto, cliente, factura, NCF, proveedor, empleado…"
               aria-label="Buscar en todo el sistema"
               class="flex-1 py-4 text-[15px] text-slate-700 placeholder:text-slate-400 outline-none bg-transparent">
        <span x-show="cargando" x-cloak class="shrink-0">
          <svg class="w-4 h-4 animate-spin text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M21 12a9 9 0 1 1-6.2-8.6" stroke-linecap="round"/>
          </svg>
        </span>
        <button type="button" @click="cerrar()" aria-label="Cerrar buscador"
                class="shrink-0 text-slate-300 hover:text-slate-600 transition p-1"><?= icon('x', 'w-5 h-5') ?></button>
      </div>

      <!-- Resultados -->
      <div class="overflow-y-auto overscroll-contain flex-1" x-ref="lista">

        <!-- Atajos (buscador vacío) -->
        <template x-if="!q.trim()">
          <div class="p-2">
            <p class="px-3 pt-2 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400">Accesos rápidos</p>
            <?php foreach ($atajos as $i => $a): ?>
              <a href="<?= e($a['url']) ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-slate-50 transition group">
                <span class="w-9 h-9 rounded-lg bg-slate-50 text-slate-400 group-hover:bg-blue-50 group-hover:text-blue-600 flex items-center justify-center shrink-0 transition"><?= icon($a['icono'], 'w-4 h-4') ?></span>
                <span class="min-w-0">
                  <span class="block text-[13.5px] font-semibold text-slate-700"><?= e($a['titulo']) ?></span>
                  <span class="block text-[11.5px] text-slate-400 truncate"><?= e($a['subtitulo']) ?></span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </template>

        <!-- Sin resultados -->
        <template x-if="q.trim().length >= 2 && !cargando && plano.length === 0">
          <div class="flex flex-col items-center text-center px-6 py-14">
            <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mb-3"><?= icon('search', 'w-7 h-7') ?></div>
            <p class="text-sm font-semibold text-slate-700">Sin resultados para «<span x-text="q"></span>»</p>
            <p class="text-xs text-slate-400 mt-1 max-w-xs">Prueba con el código, el RNC/cédula o el número de factura. También puedes escribir el nombre de una pantalla para ir a ella.</p>
          </div>
        </template>

        <!-- Grupos -->
        <template x-if="plano.length > 0">
          <div class="p-2">
            <template x-for="grupo in grupos" :key="grupo.grupo">
              <div class="mb-1">
                <p class="px-3 pt-2 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400" x-text="grupo.grupo"></p>
                <template x-for="item in grupo.items" :key="item.url + item.titulo">
                  <a :href="item.url"
                     @mouseenter="indice = plano.findIndex(p => p.url === item.url && p.titulo === item.titulo)"
                     :class="esActivo(item) ? 'bg-blue-50 ring-1 ring-blue-100' : 'hover:bg-slate-50'"
                     class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition group">
                    <span class="min-w-0 flex-1">
                      <span class="block text-[13.5px] font-semibold text-slate-800 truncate" x-text="item.titulo"></span>
                      <span x-show="item.subtitulo" class="block text-[11.5px] text-slate-400 truncate" x-text="item.subtitulo"></span>
                    </span>
                    <span x-show="item.etiqueta" class="badge shrink-0"
                          :class="'badge-' + (item.etiqueta_color || 'slate')" x-text="item.etiqueta"></span>
                    <span class="text-slate-300 shrink-0" :class="esActivo(item) && 'text-blue-600'"><?= icon('arrow-right', 'w-4 h-4') ?></span>
                  </a>
                </template>
              </div>
            </template>
          </div>
        </template>
      </div>

      <!-- Pie -->
      <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-t border-slate-100 bg-slate-50/70 shrink-0">
        <div class="flex items-center gap-3 text-[11px] text-slate-400">
          <span class="inline-flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded border border-slate-200 bg-white font-semibold">↑↓</kbd> navegar</span>
          <span class="inline-flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded border border-slate-200 bg-white font-semibold">Enter</kbd> abrir</span>
          <span class="hidden sm:inline-flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded border border-slate-200 bg-white font-semibold">Esc</kbd> cerrar</span>
        </div>
        <a x-show="q.trim().length >= 2" :href="urlTodo + encodeURIComponent(q)"
           class="text-[12px] font-semibold text-blue-600 hover:text-blue-700 shrink-0">Ver todos los resultados →</a>
      </div>
    </div>
  </div>
  </template>
</div>

<script>
function buscadorGlobal(apiUrl, paginaUrl) {
  return {
    visible: false,
    q: '',
    grupos: [],
    plano: [],          // lista aplanada: es sobre la que se mueve el teclado
    indice: -1,
    cargando: false,
    peticion: 0,        // descarta respuestas que llegan tarde y desordenadas
    urlTodo: paginaUrl + '?q=',
    tecla: (navigator.platform || '').indexOf('Mac') === 0 ? '⌘' : 'Ctrl',

    abrir() {
      this.visible = true;
      this.$nextTick(() => this.$refs.campo && this.$refs.campo.focus());
    },
    cerrar() {
      this.visible = false;
      this.indice = -1;
    },

    async buscar() {
      var texto = this.q.trim();
      if (texto.length < 2) { this.grupos = []; this.plano = []; this.indice = -1; return; }

      var turno = ++this.peticion;
      this.cargando = true;
      try {
        var r = await fetch(apiUrl + '?q=' + encodeURIComponent(texto), {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin',
        });
        if (turno !== this.peticion) return;      // llegó una respuesta vieja
        if (r.status === 401) { window.location.href = paginaUrl; return; }
        var data = await r.json();
        if (turno !== this.peticion) return;
        this.grupos = data.grupos || [];
        this.plano = this.grupos.reduce((acc, g) => acc.concat(g.items), []);
        this.indice = this.plano.length ? 0 : -1;
      } catch (e) {
        this.grupos = [];
        this.plano = [];
        this.indice = -1;
      } finally {
        if (turno === this.peticion) this.cargando = false;
      }
    },

    mover(paso) {
      if (!this.plano.length) return;
      this.indice = (this.indice + paso + this.plano.length) % this.plano.length;
      this.$nextTick(() => {
        var activo = this.$refs.lista && this.$refs.lista.querySelector('.ring-blue-100');
        if (activo && activo.scrollIntoView) activo.scrollIntoView({ block: 'nearest' });
      });
    },

    esActivo(item) {
      var sel = this.plano[this.indice];
      return !!sel && sel.url === item.url && sel.titulo === item.titulo;
    },

    abrirSeleccion() {
      var sel = this.plano[this.indice];
      // Sin selección, Enter lleva a la página de resultados completa.
      window.location.href = sel ? sel.url : (this.urlTodo + encodeURIComponent(this.q.trim()));
    },
  };
}
</script>
