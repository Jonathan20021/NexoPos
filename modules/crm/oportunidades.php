<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_perm('crm.ver');

$etapas    = crm_etapas();
$fijaSuc   = crm_sucursal_fija();
$sucursales = crm_sucursales_visibles();
$clientes  = crm_clientes_lista();
$usuarios  = crm_usuarios_lista();

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id      = postInt('id');
        $titulo  = trim(post('titulo'));
        $clienteId = postInt('cliente_id');
        $etapa   = array_key_exists(post('etapa'), $etapas) ? post('etapa') : 'prospecto';
        $valor   = postNum('valor_estimado');
        $prob    = max(0, min(100, postInt('probabilidad')));
        $fuente  = trim(post('fuente'));
        $respId  = postInt('responsable_id') ?: null;
        $cierre  = post('fecha_cierre_estimada') ?: null;
        $desc    = trim(post('descripcion'));
        $motivo  = trim(post('motivo_perdida'));

        try {
            if ($titulo === '')                 throw new RuntimeException('El título de la oportunidad es obligatorio.');
            if ($clienteId <= 0 || !qVal("SELECT 1 FROM clientes WHERE id = ?", [$clienteId]))
                throw new RuntimeException('Selecciona un cliente válido.');
            if ($valor < 0)                     throw new RuntimeException('El valor estimado no puede ser negativo.');
            if ($respId && !qVal("SELECT 1 FROM usuarios WHERE id = ?", [$respId]))
                throw new RuntimeException('El responsable seleccionado no existe.');

            $datos = [
                'cliente_id'            => $clienteId,
                'titulo'                => $titulo,
                'descripcion'           => $desc ?: null,
                'etapa'                 => $etapa,
                'valor_estimado'        => $valor,
                'probabilidad'          => $prob,
                'fuente'                => $fuente ?: null,
                'responsable_id'        => $respId,
                'fecha_cierre_estimada' => $cierre,
                'motivo_perdida'        => $etapa === 'perdida' ? ($motivo ?: null) : null,
                'fecha_cierre_real'     => in_array($etapa, ['ganada', 'perdida'], true) ? date('Y-m-d') : null,
            ];

            if ($id > 0) {
                require_perm('crm.editar');
                $actual = qOne("SELECT * FROM crm_oportunidades WHERE id = ?", [$id]);
                if (!$actual) throw new RuntimeException('Oportunidad no encontrada.');
                require_sucursal_access($actual['sucursal_id']);
                dbUpdate('crm_oportunidades', $datos, 'id = ?', [$id]);
                audit('crm', 'editar', "Oportunidad actualizada: $titulo", ['tabla' => 'crm_oportunidades', 'registro_id' => $id]);
                flash('success', 'Oportunidad actualizada.');
            } else {
                require_perm('crm.crear');
                $datos['sucursal_id'] = crm_resolver_sucursal();
                $datos['codigo']      = nextNumero('crm_oportunidades', 'codigo', 'OPT', 5);
                $datos['created_by']  = current_user()['id'] ?? null;
                $nid = dbInsert('crm_oportunidades', $datos);
                audit('crm', 'crear', "Oportunidad creada: $titulo", ['tabla' => 'crm_oportunidades', 'registro_id' => $nid]);
                flash('success', 'Oportunidad creada.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/crm/oportunidades.php');
    }

    if ($accion === 'avanzar') {
        require_perm('crm.avanzar');
        $id    = postInt('id');
        $nueva = post('etapa');
        $o = qOne("SELECT * FROM crm_oportunidades WHERE id = ?", [$id]);
        if (!$o) { flash('error', 'Oportunidad no encontrada.'); redirect('modules/crm/oportunidades.php'); }
        require_sucursal_access($o['sucursal_id']);
        if (!array_key_exists($nueva, $etapas)) { flash('error', 'Etapa no válida.'); redirect('modules/crm/oportunidades.php'); }

        $datos = ['etapa' => $nueva, 'probabilidad' => $etapas[$nueva][2]];
        $datos['fecha_cierre_real'] = in_array($nueva, ['ganada', 'perdida'], true) ? date('Y-m-d') : null;
        if ($nueva === 'perdida') $datos['motivo_perdida'] = trim(post('motivo_perdida')) ?: $o['motivo_perdida'];
        dbUpdate('crm_oportunidades', $datos, 'id = ?', [$id]);
        audit('crm', 'editar', "Oportunidad #{$o['codigo']} movida a «{$etapas[$nueva][0]}»", ['tabla' => 'crm_oportunidades', 'registro_id' => $id]);
        flash('success', "Oportunidad movida a «{$etapas[$nueva][0]}».");
        redirect(post('volver') ?: 'modules/crm/oportunidades.php');
    }

    if ($accion === 'eliminar') {
        require_perm('crm.eliminar');
        $id = postInt('id');
        $o = qOne("SELECT codigo, titulo, sucursal_id FROM crm_oportunidades WHERE id = ?", [$id]);
        if ($o) {
            require_sucursal_access($o['sucursal_id']);
            q("DELETE FROM crm_oportunidades WHERE id = ?", [$id]);
            audit('crm', 'eliminar', "Oportunidad eliminada: {$o['titulo']}", ['tabla' => 'crm_oportunidades', 'registro_id' => $id]);
            flash('success', 'Oportunidad eliminada.');
        }
        redirect('modules/crm/oportunidades.php');
    }
}

// ---------- Filtros + alcance por sucursal ----------
[$scope, $scopeParams] = sucursalScope('o.sucursal_id');
$q        = trim(get('q'));
$fEtapa   = get('etapa');
$fResp    = (int) get('responsable');

$where  = "WHERE $scope";
$params = $scopeParams;
if ($q !== '')   { $where .= " AND (o.titulo LIKE ? OR o.codigo LIKE ? OR c.nombre LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if (array_key_exists($fEtapa, $etapas)) { $where .= " AND o.etapa = ?"; $params[] = $fEtapa; }
if ($fResp > 0)  { $where .= " AND o.responsable_id = ?"; $params[] = $fResp; }

$baseSelect = "FROM crm_oportunidades o
               JOIN clientes c ON c.id = o.cliente_id
               LEFT JOIN sucursales s ON s.id = o.sucursal_id
               LEFT JOIN usuarios u ON u.id = o.responsable_id
               $where";

// ---------- KPIs (respetan el alcance de sucursal, no los filtros) ----------
$kpiWhere = "WHERE $scope"; $kpiParams = $scopeParams;
$abiertas = qOne("SELECT COUNT(*) n, COALESCE(SUM(valor_estimado),0) v FROM crm_oportunidades o $kpiWhere AND etapa NOT IN ('ganada','perdida')", $kpiParams);
$ganadasMes = (int) qVal("SELECT COUNT(*) FROM crm_oportunidades o $kpiWhere AND etapa='ganada' AND fecha_cierre_real >= ?", array_merge($kpiParams, [date('Y-m-01')]));
$cerradas = (int) qVal("SELECT COUNT(*) FROM crm_oportunidades o $kpiWhere AND etapa IN ('ganada','perdida')", $kpiParams);
$ganadasTot = (int) qVal("SELECT COUNT(*) FROM crm_oportunidades o $kpiWhere AND etapa='ganada'", $kpiParams);
$conversion = $cerradas > 0 ? round($ganadasTot / $cerradas * 100) : 0;

// ---------- Exportación ----------
if (export_solicitado()) {
    $rows = qAll("SELECT o.codigo, c.nombre cliente, o.titulo, o.etapa, o.valor_estimado, o.probabilidad,
                         o.fuente, u.nombre resp_n, u.apellido resp_a, s.nombre sucursal, o.fecha_cierre_estimada
                  $baseSelect ORDER BY o.updated_at DESC", $params);
    export_tabla('oportunidades',
        ['Código', 'Cliente', 'Título', 'Etapa', 'Valor estimado', 'Prob. %', 'Fuente', 'Responsable', 'Sucursal', 'Cierre estimado'],
        array_map(fn($r) => [
            $r['codigo'], $r['cliente'], $r['titulo'], $etapas[$r['etapa']][0] ?? $r['etapa'],
            $r['valor_estimado'], $r['probabilidad'], $r['fuente'],
            trim(($r['resp_n'] ?? '') . ' ' . ($r['resp_a'] ?? '')) ?: '—', $r['sucursal'], $r['fecha_cierre_estimada'],
        ], $rows));
}

// ---------- Listado paginado ----------
$pg = paginar((int) qVal("SELECT COUNT(*) $baseSelect", $params), 25);
$ops = qAll(
    "SELECT o.*, c.nombre AS cliente, c.codigo AS cliente_codigo, s.nombre AS sucursal,
            TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS responsable
     $baseSelect ORDER BY FIELD(o.etapa,'negociacion','propuesta','contactado','prospecto','ganada','perdida'), o.updated_at DESC
     LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $params
);

$acciones = export_buttons() . (can('crm.crear') ? btn_nuevo('opo:new', 'Nueva oportunidad') : '');
layout_start('Oportunidades', 'Embudo de ventas de tu equipo comercial', $acciones);

$probJs = json_encode(array_map(fn($e) => $e[2], $etapas));
?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <div class="flex items-center gap-2 text-slate-500 text-sm mb-1"><?= icon('briefcase', 'w-4 h-4') ?> Oportunidades abiertas</div>
    <p class="text-2xl font-extrabold text-slate-800"><?= number_format((int) $abiertas['n']) ?></p>
  </div>
  <div class="card p-5">
    <div class="flex items-center gap-2 text-slate-500 text-sm mb-1"><?= icon('dollar', 'w-4 h-4') ?> Valor del pipeline</div>
    <p class="text-2xl font-extrabold text-blue-600"><?= money((float) $abiertas['v']) ?></p>
  </div>
  <div class="card p-5">
    <div class="flex items-center gap-2 text-slate-500 text-sm mb-1"><?= icon('check', 'w-4 h-4') ?> Ganadas este mes</div>
    <p class="text-2xl font-extrabold text-emerald-600"><?= number_format($ganadasMes) ?></p>
  </div>
  <div class="card p-5">
    <div class="flex items-center gap-2 text-slate-500 text-sm mb-1"><?= icon('pie', 'w-4 h-4') ?> Tasa de conversión</div>
    <p class="text-2xl font-extrabold text-slate-800"><?= $conversion ?>%</p>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por título, código o cliente...', array_filter(['etapa' => $fEtapa, 'responsable' => $fResp ?: ''])) ?>
    <form method="get" class="flex items-center gap-2">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <select name="etapa" class="select !w-auto" onchange="this.form.submit()">
        <option value="">Todas las etapas</option>
        <?php foreach ($etapas as $k => $et): ?>
          <option value="<?= e($k) ?>" <?= $fEtapa === $k ? 'selected' : '' ?>><?= e($et[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($usuarios): ?>
      <select name="responsable" class="select !w-auto" onchange="this.form.submit()">
        <option value="">Todos los responsables</option>
        <?php foreach ($usuarios as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $fResp === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$ops): ?>
    <?= empty_state('Sin oportunidades', 'Registra tu primera oportunidad de venta para empezar a llenar el embudo.', 'briefcase',
        can('crm.crear') ? btn_nuevo('opo:new', 'Nueva oportunidad') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Oportunidad</th><th>Cliente</th><th>Etapa</th><th class="text-right">Valor</th>
          <th>Responsable</th><th>Cierre est.</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($ops as $o): ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($o['titulo']) ?></p>
                <p class="text-xs text-slate-400 font-mono"><?= e($o['codigo']) ?> · <?= e($o['sucursal'] ?? '—') ?></p>
              </td>
              <td>
                <a href="<?= e(url('modules/crm/cliente.php?id=' . (int) $o['cliente_id'])) ?>" class="text-blue-600 hover:underline"><?= e($o['cliente']) ?></a>
              </td>
              <td>
                <?php if (can('crm.avanzar')): ?>
                  <form method="post" class="inline">
                    <?= csrf_field() ?><input type="hidden" name="accion" value="avanzar"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <select name="etapa" onchange="this.form.submit()" class="select !w-auto !py-1 !text-xs">
                      <?php foreach ($etapas as $k => $et): ?>
                        <option value="<?= e($k) ?>" <?= $o['etapa'] === $k ? 'selected' : '' ?>><?= e($et[0]) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php else: ?>
                  <?= crm_etapa_badge($o['etapa']) ?>
                <?php endif; ?>
              </td>
              <td class="text-right font-bold text-slate-700"><?= money($o['valor_estimado']) ?><span class="block text-xs font-normal text-slate-400"><?= (int) $o['probabilidad'] ?>%</span></td>
              <td class="text-slate-500 text-sm"><?= e($o['responsable'] ?: '—') ?></td>
              <td class="text-slate-500 text-sm whitespace-nowrap"><?= $o['fecha_cierre_estimada'] ? e(fechaCorta($o['fecha_cierre_estimada'])) : '—' ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="<?= e(url('modules/crm/cliente.php?id=' . (int) $o['cliente_id'])) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ficha 360°"><?= icon('eye', 'w-4 h-4') ?></a>
                  <?php if (can('crm.editar')): ?>
                    <button onclick="<?= jsEvent('opo:edit', [
                        'id' => (int) $o['id'], 'cliente_id' => (int) $o['cliente_id'], 'titulo' => $o['titulo'],
                        'descripcion' => $o['descripcion'], 'etapa' => $o['etapa'], 'valor_estimado' => $o['valor_estimado'],
                        'probabilidad' => (int) $o['probabilidad'], 'fuente' => $o['fuente'], 'responsable_id' => (int) $o['responsable_id'],
                        'fecha_cierre_estimada' => $o['fecha_cierre_estimada'], 'motivo_perdida' => $o['motivo_perdida'],
                    ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('crm.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la oportunidad «<?= e($o['titulo']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
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

<!-- Modal crear/editar -->
<div x-data="{open:false, probs:<?= e($probJs) ?>, f:{id:0,cliente_id:'',titulo:'',descripcion:'',etapa:'prospecto',valor_estimado:0,probabilidad:10,fuente:'',responsable_id:'',fecha_cierre_estimada:'',motivo_perdida:''}}"
     @opo:new.window="f={id:0,cliente_id:'',titulo:'',descripcion:'',etapa:'prospecto',valor_estimado:0,probabilidad:10,fuente:'',responsable_id:'',fecha_cierre_estimada:'',motivo_perdida:''}; open=true"
     @opo:edit.window="f=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="f.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="f.id ? 'Editar oportunidad' : 'Nueva oportunidad'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4 max-h-[72vh] overflow-y-auto">
          <div class="sm:col-span-2">
            <label class="label">Título *</label>
            <input type="text" name="titulo" x-model="f.titulo" required class="input" placeholder="Ej. Venta mayorista de 50 cajas">
          </div>
          <div>
            <label class="label">Cliente *</label>
            <select name="cliente_id" x-model="f.cliente_id" required class="select">
              <option value="">— Selecciona —</option>
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
            <label class="label">Etapa</label>
            <select name="etapa" x-model="f.etapa" @change="f.probabilidad = probs[f.etapa] ?? f.probabilidad" class="select">
              <?php foreach ($etapas as $k => $et): ?><option value="<?= e($k) ?>"><?= e($et[0]) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Valor estimado (RD$)</label>
            <input type="number" step="0.01" min="0" name="valor_estimado" x-model="f.valor_estimado" class="input" placeholder="0.00">
          </div>
          <div>
            <label class="label">Probabilidad (%)</label>
            <input type="number" min="0" max="100" name="probabilidad" x-model="f.probabilidad" class="input">
          </div>
          <div>
            <label class="label">Fuente / canal</label>
            <input type="text" name="fuente" x-model="f.fuente" class="input" placeholder="Ej. Instagram, Referido, Mostrador" list="crm-fuentes">
            <datalist id="crm-fuentes"><option>Instagram</option><option>Facebook</option><option>WhatsApp</option><option>Referido</option><option>Mostrador</option><option>Página web</option><option>Llamada</option></datalist>
          </div>
          <div>
            <label class="label">Responsable</label>
            <select name="responsable_id" x-model="f.responsable_id" class="select">
              <option value="">— Sin asignar —</option>
              <?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label">Cierre estimado</label>
            <input type="date" name="fecha_cierre_estimada" x-model="f.fecha_cierre_estimada" class="input">
          </div>
          <div class="sm:col-span-2" x-show="f.etapa === 'perdida'" x-transition>
            <label class="label">Motivo de pérdida</label>
            <input type="text" name="motivo_perdida" x-model="f.motivo_perdida" class="input" placeholder="Ej. Precio, se fue con la competencia...">
          </div>
          <div class="sm:col-span-2">
            <label class="label">Descripción / notas</label>
            <textarea name="descripcion" x-model="f.descripcion" rows="3" class="input" placeholder="Detalle de la oportunidad"></textarea>
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
