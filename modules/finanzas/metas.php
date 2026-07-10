<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('metas.ver');

$moneda = setting('moneda', 'RD$');

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        require_perm('metas.gestionar');
        $id       = postInt('id');
        $alcance  = in_array(post('alcance'), ['global', 'sucursal', 'vendedor'], true) ? post('alcance') : 'sucursal';
        $sucId    = $alcance === 'global' ? null : (postInt('sucursal_id') ?: null);
        $usrId    = $alcance === 'vendedor' ? (postInt('usuario_id') ?: null) : null;
        $ini      = post('periodo_inicio');
        $fin      = post('periodo_fin');
        $monto    = postNum('monto_objetivo');
        $notas    = trim(post('notas')) ?: null;

        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
                throw new RuntimeException('Indica el período (fecha inicial y final).');
            }
            if ($fin < $ini) throw new RuntimeException('La fecha final no puede ser anterior a la inicial.');
            if ($monto <= 0) throw new RuntimeException('El monto objetivo debe ser mayor que cero.');
            if ($alcance === 'sucursal' && !$sucId) throw new RuntimeException('Selecciona la sucursal de la meta.');
            if ($alcance === 'vendedor' && !$usrId) throw new RuntimeException('Selecciona el vendedor de la meta.');
            if ($sucId) require_sucursal_access($sucId);

            $datos = [
                'sucursal_id' => $sucId, 'usuario_id' => $usrId,
                'periodo_inicio' => $ini, 'periodo_fin' => $fin,
                'moneda' => $moneda, 'monto_objetivo' => $monto, 'notas' => $notas,
            ];
            if ($id > 0) {
                dbUpdate('metas_ventas', $datos, 'id = ?', [$id]);
                audit('metas', 'editar', "Meta actualizada #$id (" . money($monto) . ')', ['tabla' => 'metas_ventas', 'registro_id' => $id]);
                flash('success', 'Meta actualizada.');
            } else {
                $datos['created_by'] = current_user()['id'];
                $nid = dbInsert('metas_ventas', $datos);
                audit('metas', 'crear', "Meta creada (" . money($monto) . ')', ['tabla' => 'metas_ventas', 'registro_id' => $nid]);
                flash('success', 'Meta creada.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/finanzas/metas.php');
    }

    if ($accion === 'estado') {
        require_perm('metas.gestionar');
        $id = postInt('id');
        $nuevo = in_array(post('estado'), ['activa', 'cerrada', 'cancelada'], true) ? post('estado') : null;
        if ($nuevo) {
            dbUpdate('metas_ventas', ['estado' => $nuevo], 'id = ?', [$id]);
            audit('metas', 'estado', "Meta #$id → $nuevo", ['tabla' => 'metas_ventas', 'registro_id' => $id]);
            flash('success', "Meta marcada como $nuevo.");
        }
        redirect('modules/finanzas/metas.php');
    }
}

// ---------- Listado ----------
$fEstado = in_array(get('estado'), ['activa', 'cerrada', 'cancelada'], true) ? get('estado') : 'activa';
[$scope, $sp] = sucursalFiltro('m.sucursal_id');
// Las metas globales (sin sucursal) se ven siempre; el filtro solo acota las de sucursal.
$where = "m.estado = ? AND (m.sucursal_id IS NULL OR $scope)";
$params = array_merge([$fEstado], $sp);

$metas = qAll(
    "SELECT m.*, s.nombre AS sucursal, CONCAT(u.nombre,' ',u.apellido) AS vendedor
       FROM metas_ventas m
       LEFT JOIN sucursales s ON s.id = m.sucursal_id
       LEFT JOIN usuarios u ON u.id = m.usuario_id
      WHERE $where
      ORDER BY m.periodo_fin DESC, m.id DESC",
    $params
);

$sucursales = sucursales_visibles();
$vendedores = qAll("SELECT id, CONCAT(nombre,' ',apellido) AS nombre FROM usuarios WHERE activo = 1 ORDER BY nombre");

$acciones = can('metas.gestionar') ? btn_nuevo('meta:new', 'Nueva meta') : '';
layout_start('Metas de Venta', 'Objetivos por sucursal y vendedor · progreso en tiempo real', $acciones);
?>

<!-- Filtro de estado -->
<div class="flex flex-wrap items-center gap-2 mb-5">
  <?php foreach (['activa' => 'Activas', 'cerrada' => 'Cerradas', 'cancelada' => 'Canceladas'] as $k => $lbl):
      $qs = http_build_query(['estado' => $k] + (sucursalFiltroActual() ? ['sucursal_id' => sucursalFiltroActual()] : [])); ?>
    <a href="?<?= e($qs) ?>"
       class="px-4 py-2 rounded-xl text-sm font-semibold border transition-colors duration-200 cursor-pointer
              <?= $fEstado === $k ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$metas): ?>
  <?= empty_state('Sin metas ' . $fEstado . 's', 'Crea una meta mensual por sucursal y divídela entre tus vendedoras.', 'trending',
      can('metas.gestionar') ? btn_nuevo('meta:new', 'Nueva meta') : '') ?>
<?php else: ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <?php foreach ($metas as $m):
        $pr = metaProgreso($m);
        $col = metaColor($pr['pct']);
        $alcance = $m['usuario_id'] ? $m['vendedor'] : ($m['sucursal_id'] ? $m['sucursal'] : 'Global (toda la empresa)');
        $icono = $m['usuario_id'] ? 'user' : ($m['sucursal_id'] ? 'store' : 'building');
    ?>
      <div class="card p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <span class="w-10 h-10 rounded-xl badge-<?= $col ?> flex items-center justify-center shrink-0"><?= icon($icono, 'w-5 h-5') ?></span>
            <div class="min-w-0">
              <p class="font-bold text-slate-800 truncate"><?= e($alcance) ?></p>
              <p class="text-xs text-slate-400"><?= fechaCorta($m['periodo_inicio']) ?> – <?= fechaCorta($m['periodo_fin']) ?></p>
            </div>
          </div>
          <?php if (can('metas.gestionar')): ?>
            <div class="flex items-center gap-1 shrink-0">
              <button onclick="<?= jsEvent('meta:edit', ['id' => (int) $m['id'], 'alcance' => $m['usuario_id'] ? 'vendedor' : ($m['sucursal_id'] ? 'sucursal' : 'global'), 'sucursal_id' => (int) $m['sucursal_id'], 'usuario_id' => (int) $m['usuario_id'], 'periodo_inicio' => $m['periodo_inicio'], 'periodo_fin' => $m['periodo_fin'], 'monto_objetivo' => $m['monto_objetivo'], 'notas' => $m['notas']]) ?>"
                      class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 cursor-pointer" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
              <?php if ($m['estado'] === 'activa'): ?>
                <form method="post" class="inline" onsubmit="return confirm('¿Cerrar esta meta?')">
                  <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="estado" value="cerrada">
                  <button class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 cursor-pointer" title="Cerrar meta"><?= icon('check', 'w-4 h-4') ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Barra de progreso -->
        <div class="mt-4">
          <div class="flex items-end justify-between mb-1.5">
            <span class="text-2xl font-extrabold text-slate-800"><?= e($moneda) ?> <?= number_format($pr['vendido'], 2) ?></span>
            <span class="text-sm text-slate-400">de <?= e($moneda) ?> <?= number_format($pr['objetivo'], 2) ?></span>
          </div>
          <div class="h-3 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full bg-<?= $col ?>-500 transition-all" style="width: <?= $pr['pct'] ?>%"></div>
          </div>
          <div class="flex items-center justify-between mt-2 text-sm">
            <span class="font-bold text-<?= $col ?>-600"><?= $pr['pct'] ?>%</span>
            <span class="text-slate-500">
              <?php if ($pr['falta'] > 0): ?>Faltan <?= e($moneda) ?> <?= number_format($pr['falta'], 2) ?><?php else: ?>¡Meta alcanzada!<?php endif; ?>
              <?php if ($m['estado'] === 'activa' && $pr['dias_restantes'] > 0): ?>
                · <?= $pr['dias_restantes'] ?> día<?= $pr['dias_restantes'] === 1 ? '' : 's' ?>
              <?php endif; ?>
            </span>
          </div>
          <?php if ($pr['devuelto'] > 0): ?>
            <p class="text-xs text-slate-400 mt-1.5">Venta neta: bruto <?= money($pr['bruto']) ?> − devoluciones <?= money($pr['devuelto']) ?></p>
          <?php endif; ?>
          <?php if ($m['notas']): ?><p class="text-xs text-slate-500 mt-2"><?= e($m['notas']) ?></p><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Modal crear/editar meta -->
<?php if (can('metas.gestionar')): ?>
<div x-data="{ open: false, form: {} }"
     @meta:new.window="form = {id:0, alcance:'sucursal', sucursal_id:'', usuario_id:'', periodo_inicio:'', periodo_fin:'', monto_objetivo:'', notas:''}; open = true"
     @meta:edit.window="form = $event.detail; open = true"
     @keydown.escape.window="open = false"
     x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open = false" role="dialog" aria-modal="true">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar meta' : 'Nueva meta'"></h3>
        <button type="button" @click="open = false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1 cursor-pointer"><?= icon('x', 'w-5 h-5') ?></button>
      </div>
      <div class="p-6 space-y-4">
        <div>
          <label class="label" for="alcance">Alcance de la meta *</label>
          <select id="alcance" name="alcance" x-model="form.alcance" class="select cursor-pointer">
            <option value="sucursal">Por sucursal</option>
            <option value="vendedor">Por vendedor</option>
            <option value="global">Global (toda la empresa)</option>
          </select>
        </div>
        <div x-show="form.alcance !== 'global'">
          <label class="label" for="sucursal_id">Sucursal <span x-show="form.alcance==='sucursal'">*</span></label>
          <select id="sucursal_id" name="sucursal_id" x-model="form.sucursal_id" class="select cursor-pointer">
            <option value="">— Selecciona —</option>
            <?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div x-show="form.alcance === 'vendedor'">
          <label class="label" for="usuario_id">Vendedor *</label>
          <select id="usuario_id" name="usuario_id" x-model="form.usuario_id" class="select cursor-pointer">
            <option value="">— Selecciona —</option>
            <?php foreach ($vendedores as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e($v['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="pi">Desde *</label><input type="date" id="pi" name="periodo_inicio" x-model="form.periodo_inicio" required class="input"></div>
          <div><label class="label" for="pf">Hasta *</label><input type="date" id="pf" name="periodo_fin" x-model="form.periodo_fin" required class="input"></div>
        </div>
        <div>
          <label class="label" for="monto">Monto objetivo (<?= e($moneda) ?>) *</label>
          <input type="number" step="0.01" min="0" id="monto" name="monto_objetivo" x-model="form.monto_objetivo" required class="input text-lg font-bold">
        </div>
        <div>
          <label class="label" for="notas">Notas</label>
          <input type="text" id="notas" name="notas" x-model="form.notas" maxlength="255" class="input" placeholder="Opcional">
        </div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open = false" class="btn btn-ghost cursor-pointer">Cancelar</button>
        <button class="btn btn-primary cursor-pointer"><?= icon('save', 'w-4 h-4') ?> Guardar meta</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
