<?php
/**
 * Gestión de lotes — la pantalla operativa del control sanitario.
 *
 * Tres acciones que no se pueden hacer desde ninguna otra parte:
 *   · Identificar la existencia que quedó en SIN-LOTE al activar el control.
 *   · Bloquear o liberar un lote (retiro del mercado por alerta del fabricante).
 *   · Dar de baja mercancía vencida, que la retira del inventario dejando rastro.
 *
 * Bloquear NO borra ni descuenta: deja la mercancía en el almacén pero fuera de
 * circulación. Es lo que se hace cuando llega una alerta y todavía no se sabe si
 * habrá que destruirla o devolverla.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('sanidad.ver');

if (!san_disponible()) {
    layout_start('Lotes', 'Módulo no instalado');
    echo empty_state('Falta la migración', 'Aplica database/migracion_sanidad_p13.sql para activar el control por lote.', 'shield');
    layout_end();
    return;
}

$volver = 'modules/inventario/lotes.php?' . http_build_query(array_intersect_key($_GET, ['q' => 1, 'estado' => 1]));

if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    $id = postInt('id');

    try {
        $lote = qOne("SELECT l.*, p.nombre AS producto FROM lotes l JOIN productos p ON p.id = l.producto_id WHERE l.id = ?", [$id]);
        if (!$lote) throw new RuntimeException('El lote no existe.');
        require_sucursal_access((int) $lote['sucursal_id']);

        /* ---------- Bloquear / liberar ---------- */
        if ($accion === 'bloquear' || $accion === 'liberar') {
            require_perm('sanidad.bloquear');
            $bloq = $accion === 'bloquear';
            $motivo = trim(post('motivo'));
            if ($bloq && $motivo === '') throw new RuntimeException('Escribe el motivo del bloqueo: es lo que se le enseña al inspector.');

            dbUpdate('lotes', [
                'bloqueado' => $bloq ? 1 : 0,
                'motivo_bloqueo' => $bloq ? $motivo : null,
            ], 'id = ?', [$id]);

            // El bloqueo no mueve existencia, pero sí deja constancia en el libro
            // del lote: en una auditoría importa CUÁNDO se retiró y quién lo hizo.
            dbInsert('lote_movimientos', [
                'lote_id' => $id, 'producto_id' => (int) $lote['producto_id'], 'sucursal_id' => (int) $lote['sucursal_id'],
                'tipo' => $bloq ? 'bloqueo' : 'liberacion', 'cantidad' => 0,
                'saldo_anterior' => (float) $lote['cantidad'], 'saldo_nuevo' => (float) $lote['cantidad'],
                'referencia_tipo' => 'sanidad', 'referencia_id' => null,
                'motivo' => $bloq ? $motivo : 'Lote liberado',
                'usuario_id' => current_user()['id'] ?? null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            audit('sanidad', $bloq ? 'bloquear' : 'liberar',
                ($bloq ? 'Lote bloqueado' : 'Lote liberado') . ': ' . $lote['codigo'] . ' de ' . $lote['producto'],
                ['tabla' => 'lotes', 'registro_id' => $id]);
            flash('success', $bloq ? 'Lote bloqueado: ya no se puede vender.' : 'Lote liberado: vuelve a estar disponible.');
        }

        /* ---------- Dar de baja ---------- */
        if ($accion === 'baja') {
            require_perm('sanidad.baja');
            $cant = postNum('cantidad');
            // «Baja sanitaria» a secas no explica nada: dar de baja mercancía es
            // sacarla del inventario, y eso lleva motivo escrito.
            $motivo = trim(post('motivo'));
            if ($motivo === '') throw new RuntimeException('Escribe por qué se da de baja este lote.');
            if ($cant <= 0) throw new RuntimeException('La cantidad debe ser mayor que cero.');
            if ($cant > (float) $lote['cantidad'] + 0.0001) throw new RuntimeException('El lote solo tiene ' . qty($lote['cantidad']) . '.');

            txReintentable(function () use ($lote, $cant, $motivo) {
                // Se pasa el lote EXPLÍCITO: una baja tiene que salir del lote que
                // se está dando de baja, no del que FEFO elegiría.
                ajustarStock((int) $lote['producto_id'], (int) $lote['sucursal_id'], -$cant, 'baja',
                    'sanidad', (int) $lote['id'], (float) $lote['costo_unitario'],
                    'Baja lote ' . $lote['codigo'] . ' · ' . $motivo,
                    ['codigo' => $lote['codigo']]);
            });
            audit('sanidad', 'baja', 'Baja de ' . qty($cant) . ' del lote ' . $lote['codigo'] . ' (' . $lote['producto'] . '): ' . $motivo,
                ['tabla' => 'lotes', 'registro_id' => $id]);
            flash('success', 'Se dieron de baja ' . qty($cant) . ' unidad(es) del lote ' . $lote['codigo'] . '. Queda registrado en el kardex.');
        }

        /* ---------- Identificar / corregir datos del lote ---------- */
        if ($accion === 'editar') {
            require_perm('sanidad.lotes');
            $nuevoCodigo = trim(post('codigo'));
            $venc = post('fecha_vencimiento') ?: null;
            if ($nuevoCodigo === '') throw new RuntimeException('El número de lote no puede quedar vacío.');
            if ($nuevoCodigo !== $lote['codigo']
                && qVal("SELECT 1 FROM lotes WHERE producto_id = ? AND sucursal_id = ? AND codigo = ? AND id <> ?",
                        [(int) $lote['producto_id'], (int) $lote['sucursal_id'], $nuevoCodigo, $id])) {
                throw new RuntimeException('Ya existe otro lote con el código ' . $nuevoCodigo . ' para este producto en esta sucursal.');
            }
            dbUpdate('lotes', ['codigo' => $nuevoCodigo, 'fecha_vencimiento' => $venc], 'id = ?', [$id]);
            audit('sanidad', 'editar', 'Lote ' . $lote['codigo'] . ' → ' . $nuevoCodigo . ' (' . $lote['producto'] . ')',
                ['tabla' => 'lotes', 'registro_id' => $id]);
            flash('success', 'Lote actualizado.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect($volver);
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q      = trim((string) get('q'));
$estado = in_array(get('estado'), ['vencido', 'por_vencer', 'bloqueado', 'sin_fecha', 'sin_lote'], true) ? get('estado') : '';

$lotes = san_lotes(['codigo' => $q, 'estado' => $estado, 'con_stock' => !$q, 'limite' => 400]);
if ($q !== '') {
    // Al buscar se permite ver también lo agotado: rastrear un lote consumido es
    // parte del trabajo en un retiro del mercado.
    $lotes = array_filter($lotes, fn($l) => stripos($l['codigo'], $q) !== false
        || stripos($l['producto'], $q) !== false || stripos($l['sku'], $q) !== false);
}
$lotes = array_filter($lotes, fn($l) => can_access_sucursal((int) $l['sucursal_id']));
$res = san_resumen();

$acciones = '<a href="' . e(url('modules/reportes/vencimientos.php')) . '" class="btn btn-ghost">'
    . icon('chart', 'w-4 h-4') . ' Reporte de vencimientos</a>';
layout_start('Lotes y vencimientos', 'Control sanitario de la mercancía regulada', $acciones);
?>

<?php
// Las cuatro tarjetas ya eran filtros —pulsarlas acota el listado—, pero se
// pintaban a mano componiendo la clase del color en tiempo de ejecución. Ahora
// pasan por kpi(), que es la misma tarjeta del resto del sistema.
echo kpis([
    ['label' => 'Vencidos con existencia', 'valor' => number_format($res['lotes_vencidos']), 'icono' => 'alert',
     'color' => $res['lotes_vencidos'] > 0 ? 'rose' : 'emerald',
     'nota' => money($res['valor_vencido']) . ' inmovilizados', 'href' => '?estado=vencido'],
    ['label' => 'Por vencer', 'valor' => number_format($res['lotes_por_vencer']), 'icono' => 'clock',
     'color' => $res['lotes_por_vencer'] > 0 ? 'amber' : 'emerald',
     'nota' => 'En ' . SAN_DIAS_AVISO_LOTE . ' días', 'href' => '?estado=por_vencer'],
    ['label' => 'Bloqueados', 'valor' => number_format($res['lotes_bloqueados']), 'icono' => 'lock',
     'color' => $res['lotes_bloqueados'] > 0 ? 'violet' : 'slate',
     'nota' => 'Fuera de circulación', 'href' => '?estado=bloqueado'],
    ['label' => 'Sin identificar', 'valor' => number_format($res['sin_identificar']), 'icono' => 'search',
     'color' => $res['sin_identificar'] > 0 ? 'amber' : 'emerald',
     'nota' => 'Existencia sin lote', 'href' => '?estado=sin_lote'],
], 4);

$limpiar = ($q || $estado) ? '<a href="?" class="btn btn-ghost btn-sm">Limpiar</a>' : '';
?>

<div class="card overflow-hidden">
  <?= toolbar(
        search_box('Lote, producto o SKU…', array_filter(['estado' => $estado ?: null])) . $limpiar,
        toolbar_conteo(count($lotes), 'lote')
      ) ?>

  <?php if (!$lotes): ?>
    <?= empty_state('Sin lotes', 'Aparecerán al recibir compras de productos con control sanitario.', 'layers') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Producto</th><th>Lote</th><th>Sucursal</th><th>Vence</th>
          <th class="text-center">Estado</th><th class="text-right">Existencia</th><th>Proveedor</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($lotes as $l): $st = san_estado_lote($l); $sinId = $l['codigo'] === SAN_LOTE_SIN_IDENTIFICAR; ?>
            <tr class="<?= $st['estado'] === 'vencido' ? 'bg-rose-50/40' : ($l['bloqueado'] ? 'bg-violet-50/30' : '') ?>">
              <td>
                <span class="font-semibold text-slate-700"><?= e($l['producto']) ?></span>
                <span class="block text-[11.5px] text-slate-400"><?= e($l['sku']) ?><?= $l['registro_producto'] ? ' · RS ' . e($l['registro_producto']) : '' ?></span>
              </td>
              <td>
                <?php if ($sinId): ?>
                  <span class="badge badge-amber" title="Existencia anterior al control de lote">Sin identificar</span>
                <?php else: ?>
                  <span class="font-mono text-[12.5px]"><?= e($l['codigo']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-slate-500"><?= e($l['sucursal']) ?></td>
              <td><?= $l['fecha_vencimiento'] ? fechaCorta($l['fecha_vencimiento']) : '<span class="text-slate-300">—</span>' ?></td>
              <td class="text-center">
                <span class="badge badge-<?= $st['color'] ?>"><?= e($st['etiqueta']) ?></span>
                <?php if ($l['bloqueado'] && $l['motivo_bloqueo']): ?>
                  <span class="block text-[11px] text-violet-600 mt-0.5"><?= e($l['motivo_bloqueo']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-right tabular-nums font-semibold"><?= qty($l['cantidad']) ?> <?= e($l['unidad'] ?: 'u') ?></td>
              <td class="text-slate-500 text-[13px]"><?= e($l['proveedor'] ?: '—') ?><?= $l['compra_numero'] ? '<span class="block text-[11px] text-slate-400">' . e($l['compra_numero']) . '</span>' : '' ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="<?= e(url('modules/reportes/trazabilidad.php?lote_id=' . (int) $l['id'])) ?>"
                     class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Rastrear a qué clientes salió"><?= icon('search', 'w-4 h-4') ?></a>
                  <?php if (can('sanidad.lotes')): ?>
                    <button onclick="<?= jsEvent('lote:edit', ['id'=>$l['id'],'codigo'=>$sinId ? '' : $l['codigo'],'fecha_vencimiento'=>$l['fecha_vencimiento'],'producto'=>$l['producto']]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Identificar o corregir el lote"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('sanidad.bloquear')): ?>
                    <?php if ($l['bloqueado']): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Liberar el lote <?= e($l['codigo']) ?>? Volverá a poder venderse.')">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="liberar"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                        <button class="p-2 rounded-lg text-violet-500 hover:text-violet-700 hover:bg-violet-50" title="Liberar"><?= icon('check', 'w-4 h-4') ?></button>
                      </form>
                    <?php else: ?>
                      <button onclick="<?= jsEvent('lote:bloq', ['id'=>$l['id'],'codigo'=>$l['codigo'],'producto'=>$l['producto']]) ?>"
                              class="p-2 rounded-lg text-slate-400 hover:text-violet-600 hover:bg-violet-50" title="Bloquear (retiro del mercado)"><?= icon('lock', 'w-4 h-4') ?></button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (can('sanidad.baja') && (float) $l['cantidad'] > 0): ?>
                    <button onclick="<?= jsEvent('lote:baja', ['id'=>$l['id'],'codigo'=>$l['codigo'],'producto'=>$l['producto'],'cantidad'=>$l['cantidad']]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Dar de baja"><?= icon('trash', 'w-4 h-4') ?></button>
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

<!-- Modal: identificar / corregir -->
<div x-data="{open:false, form:{}}" @lote:edit.window="form=$event.detail; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" :value="form.id">
        <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Identificar lote</h3><p class="text-sm text-slate-500 mt-0.5" x-text="form.producto"></p></div>
        <div class="p-6 space-y-4">
          <div><label class="label">N.º de lote *</label><input name="codigo" x-model="form.codigo" required class="input font-mono" placeholder="L-2026-001"></div>
          <div><label class="label">Fecha de vencimiento</label><input type="date" name="fecha_vencimiento" x-model="form.fecha_vencimiento" class="input"></div>
          <p class="text-xs text-slate-400">Corregir estos datos queda registrado en la auditoría con tu nombre.</p>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: bloquear -->
<div x-data="{open:false, form:{}}" @lote:bloq.window="form=$event.detail; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="bloquear"><input type="hidden" name="id" :value="form.id">
        <div class="p-6 text-center">
          <div class="w-14 h-14 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center mx-auto mb-4"><?= icon('lock', 'w-7 h-7') ?></div>
          <h3 class="text-lg font-bold text-slate-800">Bloquear el lote <span x-text="form.codigo"></span></h3>
          <p class="text-sm text-slate-500 mt-2">La mercancía se queda en el almacén pero <strong>deja de poder venderse</strong>. Se usa cuando llega una alerta del fabricante y aún no se sabe si habrá que devolverla o destruirla.</p>
        </div>
        <div class="px-6 pb-2">
          <label class="label">Motivo del bloqueo *</label>
          <input name="motivo" required class="input" placeholder="Ej. Alerta del fabricante · Resolución DIGEMAPS 00-000">
          <p class="text-xs text-slate-400 mt-1">Es lo que se le enseña al inspector para justificar el retiro.</p>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('lock', 'w-4 h-4') ?> Bloquear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: baja -->
<div x-data="{open:false, form:{}}" @lote:baja.window="form=$event.detail; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="baja"><input type="hidden" name="id" :value="form.id">
        <div class="p-6 text-center">
          <div class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-4"><?= icon('trash', 'w-7 h-7') ?></div>
          <h3 class="text-lg font-bold text-slate-800">Dar de baja</h3>
          <p class="text-sm text-slate-500 mt-2">Retira la mercancía del inventario y la registra en el kardex como pérdida. <span x-text="form.producto"></span> · lote <span class="font-mono" x-text="form.codigo"></span></p>
        </div>
        <div class="px-6 pb-2 space-y-4">
          <div>
            <label class="label">Cantidad a dar de baja *</label>
            <input type="number" step="0.001" min="0.001" name="cantidad" :max="form.cantidad" :value="form.cantidad" required class="input">
            <p class="text-xs text-slate-400 mt-1">Disponible en el lote: <span x-text="form.cantidad"></span></p>
          </div>
          <div><label class="label">Motivo</label><input name="motivo" class="input" placeholder="Vencimiento, daño, retiro del mercado…"></div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-danger"><?= icon('trash', 'w-4 h-4') ?> Dar de baja</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
