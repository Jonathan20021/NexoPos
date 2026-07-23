<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_perm('crm.ver');

$tipos     = crm_tipos();
$fijaSuc   = crm_sucursal_fija();
$sucursales = crm_sucursales_visibles();
$clientes  = crm_clientes_lista();

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id        = postInt('id');
        $clienteId = postInt('cliente_id');
        $opoId     = postInt('oportunidad_id') ?: null;
        $tipo      = array_key_exists(post('tipo'), $tipos) ? post('tipo') : 'nota';
        $asunto    = trim(post('asunto'));
        $detalle   = trim(post('detalle'));
        $fecha     = post('fecha') ? date('Y-m-d H:i:s', strtotime(post('fecha'))) : date('Y-m-d H:i:s');

        try {
            if ($clienteId <= 0 || !qVal("SELECT 1 FROM clientes WHERE id = ?", [$clienteId]))
                throw new RuntimeException('Selecciona un cliente válido.');
            if ($asunto === '') throw new RuntimeException('El asunto es obligatorio.');
            if ($opoId && !qVal("SELECT 1 FROM crm_oportunidades WHERE id = ? AND cliente_id = ?", [$opoId, $clienteId]))
                $opoId = null; // la oportunidad debe ser del mismo cliente

            $datos = [
                'cliente_id'     => $clienteId,
                'oportunidad_id' => $opoId,
                'tipo'           => $tipo,
                'asunto'         => $asunto,
                'detalle'        => $detalle ?: null,
                'fecha'          => $fecha,
            ];
            if ($id > 0) {
                require_perm('crm.editar');
                $act = qOne("SELECT sucursal_id FROM crm_interacciones WHERE id = ?", [$id]);
                if (!$act) throw new RuntimeException('Interacción no encontrada.');
                require_sucursal_access($act['sucursal_id']);
                dbUpdate('crm_interacciones', $datos, 'id = ?', [$id]);
                audit('crm', 'editar', "Interacción actualizada: $asunto", ['tabla' => 'crm_interacciones', 'registro_id' => $id]);
                flash('success', 'Interacción actualizada.');
            } else {
                require_perm('crm.crear');
                $datos['sucursal_id'] = crm_resolver_sucursal();
                $datos['usuario_id']  = current_user()['id'] ?? null;
                $nid = dbInsert('crm_interacciones', $datos);
                audit('crm', 'crear', "Interacción registrada: $asunto", ['tabla' => 'crm_interacciones', 'registro_id' => $nid]);
                flash('success', 'Interacción registrada.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/crm/interacciones.php');
    }

    if ($accion === 'eliminar') {
        require_perm('crm.eliminar');
        $id = postInt('id');
        $it = qOne("SELECT asunto, sucursal_id FROM crm_interacciones WHERE id = ?", [$id]);
        if ($it) {
            require_sucursal_access($it['sucursal_id']);
            q("DELETE FROM crm_interacciones WHERE id = ?", [$id]);
            audit('crm', 'eliminar', "Interacción eliminada: {$it['asunto']}", ['tabla' => 'crm_interacciones', 'registro_id' => $id]);
            flash('success', 'Interacción eliminada.');
        }
        redirect('modules/crm/interacciones.php');
    }
}

// ---------- Filtros + alcance ----------
[$scope, $scopeParams] = sucursalScope('i.sucursal_id');
$q     = trim(get('q'));
$fTipo = get('tipo');

$where = "WHERE $scope"; $params = $scopeParams;
if ($q !== '')  { $where .= " AND (i.asunto LIKE ? OR i.detalle LIKE ? OR c.nombre LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if (array_key_exists($fTipo, $tipos)) { $where .= " AND i.tipo = ?"; $params[] = $fTipo; }

$base = "FROM crm_interacciones i
         JOIN clientes c ON c.id = i.cliente_id
         LEFT JOIN sucursales s ON s.id = i.sucursal_id
         LEFT JOIN usuarios u ON u.id = i.usuario_id
         $where";

if (export_solicitado()) {
    $rows = qAll("SELECT i.fecha, c.nombre cliente, i.tipo, i.asunto, i.detalle, s.nombre sucursal,
                         TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) usuario
                  $base ORDER BY i.fecha DESC", $params);
    export_tabla('interacciones',
        ['Fecha', 'Cliente', 'Tipo', 'Asunto', 'Detalle', 'Sucursal', 'Registrado por'],
        array_map(fn($r) => [fechaHora($r['fecha']), $r['cliente'], $tipos[$r['tipo']][0] ?? $r['tipo'], $r['asunto'], $r['detalle'], $r['sucursal'], $r['usuario']], $rows));
}

$pg = paginar((int) qVal("SELECT COUNT(*) $base", $params), 25);
$items = qAll(
    "SELECT i.*, c.nombre AS cliente, s.nombre AS sucursal,
            TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS usuario
     $base ORDER BY i.fecha DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $params
);

$acciones = export_buttons() . (can('crm.crear') ? btn_nuevo('int:new', 'Registrar interacción') : '');
layout_start('Interacciones', 'Bitácora de contactos con los clientes', $acciones);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por asunto, detalle o cliente...', array_filter(['tipo' => $fTipo])) ?>
    <form method="get" class="flex items-center gap-2">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <select name="tipo" class="select !w-auto" onchange="this.form.submit()">
        <option value="">Todos los tipos</option>
        <?php foreach ($tipos as $k => $t): ?><option value="<?= e($k) ?>" <?= $fTipo === $k ? 'selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!$items): ?>
    <?= empty_state('Sin interacciones', 'Registra la primera llamada, visita o nota con un cliente.', 'phone',
        can('crm.crear') ? btn_nuevo('int:new', 'Registrar interacción') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Cliente</th><th>Tipo</th><th>Asunto</th><th>Registró</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): [$tl, $tico] = $tipos[$it['tipo']] ?? ['Nota', 'edit']; ?>
            <tr>
              <td class="text-slate-500 text-sm whitespace-nowrap"><?= e(fechaHora($it['fecha'])) ?></td>
              <td>
                <a href="<?= e(url('modules/crm/cliente.php?id=' . (int) $it['cliente_id'])) ?>" class="text-blue-600 hover:underline font-medium"><?= e($it['cliente']) ?></a>
                <span class="block text-xs text-slate-400"><?= e($it['sucursal'] ?? '—') ?></span>
              </td>
              <td><span class="badge badge-slate inline-flex items-center gap-1"><?= icon($tico, 'w-3.5 h-3.5') ?> <?= e($tl) ?></span></td>
              <td>
                <p class="font-medium text-slate-700"><?= e($it['asunto']) ?></p>
                <?php if ($it['detalle']): ?><p class="text-xs text-slate-400 max-w-md truncate"><?= e($it['detalle']) ?></p><?php endif; ?>
              </td>
              <td class="text-slate-500 text-sm"><?= e($it['usuario'] ?: '—') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('crm.editar')): ?>
                    <button onclick="<?= jsEvent('int:edit', [
                        'id' => (int) $it['id'], 'cliente_id' => (int) $it['cliente_id'], 'oportunidad_id' => (int) $it['oportunidad_id'],
                        'tipo' => $it['tipo'], 'asunto' => $it['asunto'], 'detalle' => $it['detalle'],
                        'fecha' => date('Y-m-d\TH:i', strtotime($it['fecha'])),
                    ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('crm.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar esta interacción?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<!-- Modal registrar/editar -->
<div x-data="{open:false, f:{id:0,cliente_id:'',oportunidad_id:0,tipo:'llamada',asunto:'',detalle:'',fecha:''}}"
     @int:new.window="f={id:0,cliente_id:'',oportunidad_id:0,tipo:'llamada',asunto:'',detalle:'',fecha:''}; open=true"
     @int:edit.window="f=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">
        <input type="hidden" name="oportunidad_id" :value="f.oportunidad_id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar interacción' : 'Registrar interacción'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="label">Cliente *</label>
            <select name="cliente_id" x-model="f.cliente_id" required class="select">
              <option value="">— Selecciona —</option>
              <?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php if ($fijaSuc === null): ?>
          <div class="sm:col-span-2">
            <label class="label">Sucursal *</label>
            <select name="sucursal_id" class="select" required>
              <option value="">— Selecciona —</option>
              <?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div>
            <label class="label">Tipo</label>
            <select name="tipo" x-model="f.tipo" class="select">
              <?php foreach ($tipos as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t[0]) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Fecha y hora</label>
            <input type="datetime-local" name="fecha" x-model="f.fecha" class="input">
          </div>
          <div class="sm:col-span-2">
            <label class="label">Asunto *</label>
            <input type="text" name="asunto" x-model="f.asunto" required class="input" placeholder="Ej. Llamada de seguimiento de cotización">
          </div>
          <div class="sm:col-span-2">
            <label class="label">Detalle</label>
            <textarea name="detalle" x-model="f.detalle" rows="3" class="input" placeholder="¿Qué se conversó? Próximos pasos..."></textarea>
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
