<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_perm('crm.ver');

$prioridades = crm_prioridades();
$fijaSuc     = crm_sucursal_fija();
$sucursales  = crm_sucursales_visibles();
$clientes    = crm_clientes_lista();
$usuarios    = crm_usuarios_lista();

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id        = postInt('id');
        $titulo    = trim(post('titulo'));
        $detalle   = trim(post('detalle'));
        $clienteId = postInt('cliente_id') ?: null;
        $asignado  = postInt('asignado_a') ?: null;
        $prioridad = array_key_exists(post('prioridad'), $prioridades) ? post('prioridad') : 'media';
        $vence     = post('vence_at') ? date('Y-m-d H:i:s', strtotime(post('vence_at'))) : null;

        try {
            if ($titulo === '') throw new RuntimeException('El título de la tarea es obligatorio.');
            if ($clienteId && !qVal("SELECT 1 FROM clientes WHERE id = ?", [$clienteId])) $clienteId = null;
            if ($asignado && !qVal("SELECT 1 FROM usuarios WHERE id = ?", [$asignado])) $asignado = null;

            $datos = [
                'cliente_id' => $clienteId,
                'asignado_a' => $asignado,
                'titulo'     => $titulo,
                'detalle'    => $detalle ?: null,
                'prioridad'  => $prioridad,
                'vence_at'   => $vence,
            ];
            if ($id > 0) {
                require_perm('crm.editar');
                $act = qOne("SELECT sucursal_id FROM crm_tareas WHERE id = ?", [$id]);
                if (!$act) throw new RuntimeException('Tarea no encontrada.');
                require_sucursal_access($act['sucursal_id']);
                dbUpdate('crm_tareas', $datos, 'id = ?', [$id]);
                audit('crm', 'editar', "Tarea actualizada: $titulo", ['tabla' => 'crm_tareas', 'registro_id' => $id]);
                flash('success', 'Tarea actualizada.');
            } else {
                require_perm('crm.crear');
                $datos['sucursal_id'] = crm_resolver_sucursal();
                $datos['created_by']  = current_user()['id'] ?? null;
                $nid = dbInsert('crm_tareas', $datos);
                audit('crm', 'crear', "Tarea creada: $titulo", ['tabla' => 'crm_tareas', 'registro_id' => $nid]);
                flash('success', 'Tarea creada.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/crm/tareas.php');
    }

    if ($accion === 'estado') {
        require_perm('crm.editar');
        $id     = postInt('id');
        $nuevo  = in_array(post('estado'), ['pendiente', 'completada', 'cancelada'], true) ? post('estado') : 'pendiente';
        $t = qOne("SELECT titulo, sucursal_id FROM crm_tareas WHERE id = ?", [$id]);
        if ($t) {
            require_sucursal_access($t['sucursal_id']);
            dbUpdate('crm_tareas', [
                'estado'        => $nuevo,
                'completada_at' => $nuevo === 'completada' ? date('Y-m-d H:i:s') : null,
            ], 'id = ?', [$id]);
            audit('crm', 'editar', "Tarea «{$t['titulo']}» → $nuevo", ['tabla' => 'crm_tareas', 'registro_id' => $id]);
            flash('success', 'Tarea actualizada.');
        }
        redirect(post('volver') ?: 'modules/crm/tareas.php');
    }

    if ($accion === 'eliminar') {
        require_perm('crm.eliminar');
        $id = postInt('id');
        $t = qOne("SELECT titulo, sucursal_id FROM crm_tareas WHERE id = ?", [$id]);
        if ($t) {
            require_sucursal_access($t['sucursal_id']);
            q("DELETE FROM crm_tareas WHERE id = ?", [$id]);
            audit('crm', 'eliminar', "Tarea eliminada: {$t['titulo']}", ['tabla' => 'crm_tareas', 'registro_id' => $id]);
            flash('success', 'Tarea eliminada.');
        }
        redirect('modules/crm/tareas.php');
    }
}

// ---------- Filtros + alcance ----------
[$scope, $scopeParams] = sucursalScope('t.sucursal_id');
$q       = trim(get('q'));
$fEstado = get('estado', 'pendientes');

$where = "WHERE $scope"; $params = $scopeParams;
if ($q !== '') { $where .= " AND (t.titulo LIKE ? OR t.detalle LIKE ? OR c.nombre LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($fEstado === 'pendientes')      $where .= " AND t.estado = 'pendiente'";
elseif ($fEstado === 'vencidas')    $where .= " AND t.estado = 'pendiente' AND t.vence_at IS NOT NULL AND t.vence_at < NOW()";
elseif ($fEstado === 'completadas') $where .= " AND t.estado = 'completada'";
elseif ($fEstado === 'canceladas')  $where .= " AND t.estado = 'cancelada'";

$base = "FROM crm_tareas t
         LEFT JOIN clientes c ON c.id = t.cliente_id
         LEFT JOIN sucursales s ON s.id = t.sucursal_id
         LEFT JOIN usuarios u ON u.id = t.asignado_a
         $where";

$pg = paginar((int) qVal("SELECT COUNT(*) $base", $params), 30);
$tareas = qAll(
    "SELECT t.*, c.nombre AS cliente, s.nombre AS sucursal,
            TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado
     $base ORDER BY t.estado='pendiente' DESC, (t.vence_at IS NULL), t.vence_at ASC, t.id DESC
     LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $params
);

$filtros = ['pendientes' => 'Pendientes', 'vencidas' => 'Vencidas', 'completadas' => 'Completadas', 'canceladas' => 'Canceladas', 'todas' => 'Todas'];

$acciones = can('crm.crear') ? btn_nuevo('tar:new', 'Nueva tarea') : '';
layout_start('Tareas y Seguimientos', 'Agenda comercial de tu equipo', $acciones);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar tarea o cliente...', array_filter(['estado' => $fEstado])) ?>
    <div class="flex items-center gap-1 flex-wrap">
      <?php foreach ($filtros as $k => $lbl):
        $qs = array_filter(['q' => $q, 'estado' => $k]);
        $activo = $fEstado === $k; ?>
        <a href="?<?= e(http_build_query($qs)) ?>" class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $activo ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100' ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$tareas): ?>
    <?= empty_state('Sin tareas', 'No hay tareas en este filtro. Crea un seguimiento para no perder ninguna oportunidad.', 'check',
        can('crm.crear') ? btn_nuevo('tar:new', 'Nueva tarea') : '') ?>
  <?php else: ?>
    <div class="divide-y divide-slate-100">
      <?php foreach ($tareas as $t):
        [$pl, $pc] = $prioridades[$t['prioridad']] ?? ['Media', 'sky'];
        $vencida = crm_tarea_vencida($t);
        $hecha = $t['estado'] === 'completada';
        $cancelada = $t['estado'] === 'cancelada';
      ?>
        <div class="flex items-start gap-3 p-4 <?= $hecha || $cancelada ? 'opacity-60' : '' ?>">
          <?php if (can('crm.editar') && !$hecha && !$cancelada): ?>
            <form method="post" class="pt-0.5">
              <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="estado" value="completada"><input type="hidden" name="volver" value="modules/crm/tareas.php?estado=<?= e($fEstado) ?>">
              <button class="w-5 h-5 rounded-full border-2 border-slate-300 hover:border-emerald-500 hover:bg-emerald-50 transition" title="Marcar completada"></button>
            </form>
          <?php else: ?>
            <span class="w-5 h-5 rounded-full mt-0.5 flex items-center justify-center <?= $hecha ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-400' ?>"><?= icon($hecha ? 'check' : 'x', 'w-3 h-3') ?></span>
          <?php endif; ?>

          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="font-semibold text-slate-700 <?= $hecha || $cancelada ? 'line-through' : '' ?>"><?= e($t['titulo']) ?></p>
              <?= badge($pl, $pc) ?>
              <?php if ($vencida): ?><?= badge('Vencida', 'rose') ?><?php endif; ?>
              <?php if ($cancelada): ?><?= badge('Cancelada', 'slate') ?><?php endif; ?>
            </div>
            <?php if ($t['detalle']): ?><p class="text-sm text-slate-500 mt-0.5"><?= e($t['detalle']) ?></p><?php endif; ?>
            <div class="flex items-center gap-3 mt-1 text-xs text-slate-400 flex-wrap">
              <?php if ($t['cliente']): ?>
                <a href="<?= e(url('modules/crm/cliente.php?id=' . (int) $t['cliente_id'])) ?>" class="inline-flex items-center gap-1 hover:text-blue-600"><?= icon('user', 'w-3.5 h-3.5') ?> <?= e($t['cliente']) ?></a>
              <?php endif; ?>
              <?php if ($t['vence_at']): ?><span class="inline-flex items-center gap-1 <?= $vencida ? 'text-rose-500 font-semibold' : '' ?>"><?= icon('calendar', 'w-3.5 h-3.5') ?> <?= e(fechaHora($t['vence_at'])) ?></span><?php endif; ?>
              <?php if ($t['asignado']): ?><span class="inline-flex items-center gap-1"><?= icon('user', 'w-3.5 h-3.5') ?> <?= e($t['asignado']) ?></span><?php endif; ?>
              <span class="inline-flex items-center gap-1"><?= icon('building', 'w-3.5 h-3.5') ?> <?= e($t['sucursal'] ?? '—') ?></span>
            </div>
          </div>

          <div class="flex items-center gap-1 shrink-0">
            <?php if (can('crm.editar') && !$hecha && !$cancelada): ?>
              <button onclick="<?= jsEvent('tar:edit', [
                  'id' => (int) $t['id'], 'cliente_id' => (int) $t['cliente_id'], 'asignado_a' => (int) $t['asignado_a'],
                  'titulo' => $t['titulo'], 'detalle' => $t['detalle'], 'prioridad' => $t['prioridad'],
                  'vence_at' => $t['vence_at'] ? date('Y-m-d\TH:i', strtotime($t['vence_at'])) : '',
              ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
              <form method="post" class="inline">
                <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="estado" value="cancelada"><input type="hidden" name="volver" value="modules/crm/tareas.php?estado=<?= e($fEstado) ?>">
                <button class="p-2 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50" title="Cancelar tarea"><?= icon('x', 'w-4 h-4') ?></button>
              </form>
            <?php endif; ?>
            <?php if (can('crm.eliminar')): ?>
              <form method="post" class="inline" onsubmit="return confirm('¿Eliminar esta tarea?')">
                <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<!-- Modal crear/editar -->
<div x-data="{open:false, f:{id:0,cliente_id:0,asignado_a:0,titulo:'',detalle:'',prioridad:'media',vence_at:''}}"
     @tar:new.window="f={id:0,cliente_id:0,asignado_a:0,titulo:'',detalle:'',prioridad:'media',vence_at:''}; open=true"
     @tar:edit.window="f=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar tarea' : 'Nueva tarea'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="label">Título *</label>
            <input type="text" name="titulo" x-model="f.titulo" required class="input" placeholder="Ej. Llamar para dar seguimiento a la cotización">
          </div>
          <div>
            <label class="label">Cliente</label>
            <select name="cliente_id" x-model="f.cliente_id" class="select">
              <option value="0">— Ninguno —</option>
              <?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php if ($fijaSuc === null): ?>
          <div>
            <label class="label">Sucursal *</label>
            <select name="sucursal_id" class="select" required>
              <option value="">— Selecciona —</option>
              <?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div>
            <label class="label">Asignar a</label>
            <select name="asignado_a" x-model="f.asignado_a" class="select">
              <option value="0">— Sin asignar —</option>
              <?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Prioridad</label>
            <select name="prioridad" x-model="f.prioridad" class="select">
              <?php foreach ($prioridades as $k => $p): ?><option value="<?= e($k) ?>"><?= e($p[0]) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Vence</label>
            <input type="datetime-local" name="vence_at" x-model="f.vence_at" class="input">
          </div>
          <div class="sm:col-span-2">
            <label class="label">Detalle</label>
            <textarea name="detalle" x-model="f.detalle" rows="2" class="input" placeholder="Notas opcionales"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
