<?php
/**
 * Modal de alta/edición de una campaña. Lo incluyen el listado y el tablero;
 * siempre envía a kpi_campanas.php, que es quien guarda.
 * Eventos: kc:new (vacío) y kc:edit (con los datos de la fila).
 */
if (!function_exists('can_any')) { http_response_code(404); exit; }
if (!can_any(['kpi_campanas.crear', 'kpi_campanas.editar'])) return;
$kcVacia = ['id' => 0, 'nombre' => '', 'descripcion' => '', 'fecha_inicio' => date('Y-m-d'), 'fecha_fin' => date('Y-m-d', strtotime('+30 days')),
            'ly_inicio' => '', 'ly_fin' => '', 'sucursal_id' => '', 'tienda_id' => '', 'meta_ventas' => '', 'tasa_eur' => '', 'notas' => ''];
$kcSucursales = sucursales_visibles();
?>
<div x-data="{open:false, f:<?= e(json_encode($kcVacia)) ?>, vacio:<?= e(json_encode($kcVacia)) ?>}"
     @kc:new.window="f=JSON.parse(JSON.stringify(vacio)); open=true"
     @kc:edit.window="f=Object.assign(JSON.parse(JSON.stringify(vacio)), $event.detail); open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post" action="<?= e(url('modules/marketing/kpi_campanas.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar campaña' : 'Nueva campaña'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
          <div>
            <label class="label" for="kc_nombre">Nombre *</label>
            <input id="kc_nombre" name="nombre" x-model="f.nombre" required maxlength="120" class="input" placeholder="Ej. Holiday 2026">
          </div>
          <div>
            <label class="label" for="kc_desc">Descripción</label>
            <input id="kc_desc" name="descripcion" x-model="f.descripcion" maxlength="255" class="input" placeholder="Ej. Navidad: sets de regalo y calendario">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="label" for="kc_ini">Desde (este año) *</label><input id="kc_ini" type="date" name="fecha_inicio" x-model="f.fecha_inicio" required class="input"></div>
            <div><label class="label" for="kc_fin">Hasta (este año) *</label><input id="kc_fin" type="date" name="fecha_fin" x-model="f.fecha_fin" required class="input"></div>
            <div><label class="label" for="kc_lyi">Desde (año anterior)</label><input id="kc_lyi" type="date" name="ly_inicio" x-model="f.ly_inicio" class="input"></div>
            <div><label class="label" for="kc_lyf">Hasta (año anterior)</label><input id="kc_lyf" type="date" name="ly_fin" x-model="f.ly_fin" class="input"></div>
          </div>
          <div class="flex flex-wrap items-center gap-2 -mt-2">
            <p class="text-xs text-slate-400 flex-1 min-w-[220px]">Si dejas vacío el año anterior, se comparan las mismas fechas un año antes. Para un Black Friday conviene el mismo día de la semana.</p>
            <button type="button" class="btn btn-soft btn-sm"
                    @click="const a = d => { if (!d) return ''; const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() - 364); return x.toISOString().slice(0, 10); };
                            f.ly_inicio = a(f.fecha_inicio); f.ly_fin = a(f.fecha_fin);">
              <?= icon('calendar', 'w-3.5 h-3.5') ?> Alinear por día de la semana
            </button>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="label" for="kc_suc">Sucursal</label>
              <select id="kc_suc" name="sucursal_id" x-model="f.sucursal_id" class="select">
                <option value="">Todas</option>
                <?php foreach ($kcSucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php if (tiendas_hay()): ?>
            <div>
              <label class="label" for="kc_tie">Tienda (marca)</label>
              <select id="kc_tie" name="tienda_id" x-model="f.tienda_id" class="select">
                <option value="">Todas</option>
                <?php foreach (tiendas_opciones() as $tid => $tn): ?><option value="<?= (int) $tid ?>"><?= e($tn) ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div>
              <label class="label" for="kc_meta">Meta de venta neta (<?= e(setting('moneda', 'RD$')) ?>)</label>
              <input id="kc_meta" type="number" step="0.01" min="0" name="meta_ventas" x-model="f.meta_ventas" class="input" placeholder="Vacío = suma de las metas por canal">
            </div>
            <div>
              <label class="label" for="kc_eur">Tasa <?= e(setting('moneda', 'RD$')) ?> por euro</label>
              <input id="kc_eur" type="number" step="0.0001" min="0" name="tasa_eur" x-model="f.tasa_eur" class="input" placeholder="Para reportar la inversión en €">
            </div>
          </div>
          <div>
            <label class="label" for="kc_notas">Notas</label>
            <textarea id="kc_notas" name="notas" x-model="f.notas" rows="3" class="input" placeholder="Lo que la casa matriz debe saber: qué funcionó, qué no, contexto del mercado…"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
