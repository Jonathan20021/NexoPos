<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_perm('crm.ver');

$id = (int) get('id');
$cliente = $id > 0 ? qOne("SELECT * FROM clientes WHERE id = ?", [$id]) : null;
if (!$cliente) { flash('error', 'Cliente no encontrado.'); redirect('modules/pos/clientes.php'); }

$etapas      = crm_etapas();
$tipos       = crm_tipos();
$prioridades = crm_prioridades();
$fijaSuc     = crm_sucursal_fija();
$sucursales  = crm_sucursales_visibles();
$usuarios    = crm_usuarios_lista();
$volver      = 'modules/crm/cliente.php?id=' . $id;

// ---------- Acciones (quick-add desde la ficha) ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    try {
        if ($accion === 'guardar_interaccion') {
            require_perm('crm.crear');
            $asunto = trim(post('asunto'));
            if ($asunto === '') throw new RuntimeException('El asunto de la interacción es obligatorio.');
            $tipo = array_key_exists(post('tipo'), $tipos) ? post('tipo') : 'nota';
            dbInsert('crm_interacciones', [
                'cliente_id'  => $id,
                'sucursal_id' => crm_resolver_sucursal(),
                'usuario_id'  => current_user()['id'] ?? null,
                'tipo'        => $tipo,
                'asunto'      => $asunto,
                'detalle'     => trim(post('detalle')) ?: null,
                'fecha'       => date('Y-m-d H:i:s'),
            ]);
            audit('crm', 'crear', "Interacción registrada (ficha): $asunto", ['tabla' => 'crm_interacciones', 'registro_id' => $id]);
            flash('success', 'Interacción registrada.');
        }

        if ($accion === 'guardar_oportunidad') {
            require_perm('crm.crear');
            $titulo = trim(post('titulo'));
            if ($titulo === '') throw new RuntimeException('El título de la oportunidad es obligatorio.');
            $etapa = array_key_exists(post('etapa'), $etapas) ? post('etapa') : 'prospecto';
            dbInsert('crm_oportunidades', [
                'codigo'                => nextNumero('crm_oportunidades', 'codigo', 'OPT', 5),
                'cliente_id'            => $id,
                'sucursal_id'           => crm_resolver_sucursal(),
                'titulo'                => $titulo,
                'etapa'                 => $etapa,
                'valor_estimado'        => max(0, postNum('valor_estimado')),
                'probabilidad'          => $etapas[$etapa][2],
                'fuente'                => trim(post('fuente')) ?: null,
                'responsable_id'        => postInt('responsable_id') ?: null,
                'fecha_cierre_estimada' => post('fecha_cierre_estimada') ?: null,
                'created_by'            => current_user()['id'] ?? null,
            ]);
            audit('crm', 'crear', "Oportunidad creada (ficha): $titulo", ['tabla' => 'crm_oportunidades', 'registro_id' => $id]);
            flash('success', 'Oportunidad creada.');
        }

        if ($accion === 'guardar_tarea') {
            require_perm('crm.crear');
            $titulo = trim(post('titulo'));
            if ($titulo === '') throw new RuntimeException('El título de la tarea es obligatorio.');
            $prioridad = array_key_exists(post('prioridad'), $prioridades) ? post('prioridad') : 'media';
            dbInsert('crm_tareas', [
                'cliente_id'  => $id,
                'sucursal_id' => crm_resolver_sucursal(),
                'asignado_a'  => postInt('asignado_a') ?: null,
                'titulo'      => $titulo,
                'detalle'     => trim(post('detalle')) ?: null,
                'prioridad'   => $prioridad,
                'vence_at'    => post('vence_at') ? date('Y-m-d H:i:s', strtotime(post('vence_at'))) : null,
                'created_by'  => current_user()['id'] ?? null,
            ]);
            audit('crm', 'crear', "Tarea creada (ficha): $titulo", ['tabla' => 'crm_tareas', 'registro_id' => $id]);
            flash('success', 'Tarea creada.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect($volver);
}

// ---------- Datos conectados (alcance por sucursal) ----------
// El cliente es global, pero los sub-listados se filtran por la sucursal activa.
// El fragmento se califica por alias (usuarios también tiene sucursal_id → sería
// ambiguo sin calificar); los parámetros del scope son los mismos en todos.
$scParams = sucursalScope('x.sucursal_id')[1];
$scV = sucursalScope('ventas.sucursal_id')[0];
$scO = sucursalScope('o.sucursal_id')[0];
$scI = sucursalScope('i.sucursal_id')[0];
$scT = sucursalScope('t.sucursal_id')[0];
$paramsCli = array_merge([$id], $scParams);

// Ventas del cliente
$ventasKpi = qOne("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM ventas
                   WHERE cliente_id = ? AND estado = 'completada' AND $scV", $paramsCli);
$ventas = qAll("SELECT id, numero, fecha, total, estado FROM ventas
                WHERE cliente_id = ? AND $scV ORDER BY fecha DESC LIMIT 8", $paramsCli);

// Oportunidades
$opoKpi = qOne("SELECT COUNT(*) n, COALESCE(SUM(valor_estimado),0) v FROM crm_oportunidades o
                WHERE o.cliente_id = ? AND o.etapa NOT IN ('ganada','perdida') AND $scO", $paramsCli);
$oportunidades = qAll("SELECT o.*, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS responsable
                       FROM crm_oportunidades o LEFT JOIN usuarios u ON u.id = o.responsable_id
                       WHERE o.cliente_id = ? AND {$scO}
                       ORDER BY FIELD(o.etapa,'negociacion','propuesta','contactado','prospecto','ganada','perdida'), o.updated_at DESC",
                       $paramsCli);

// Interacciones (timeline)
$interacciones = qAll("SELECT i.*, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS usuario
                       FROM crm_interacciones i LEFT JOIN usuarios u ON u.id = i.usuario_id
                       WHERE i.cliente_id = ? AND {$scI} ORDER BY i.fecha DESC LIMIT 30", $paramsCli);

// Tareas pendientes
$tareas = qAll("SELECT t.*, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado
                FROM crm_tareas t LEFT JOIN usuarios u ON u.id = t.asignado_a
                WHERE t.cliente_id = ? AND t.estado = 'pendiente' AND {$scT}
                ORDER BY (t.vence_at IS NULL), t.vence_at ASC LIMIT 12", $paramsCli);

// Abonos / pagos
$pagos = qAll("SELECT monto, fecha, notas FROM pagos_clientes WHERE cliente_id = ? ORDER BY fecha DESC LIMIT 6", [$id]);

$acciones = '<a href="' . e(url('modules/pos/clientes.php')) . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Clientes</a>';
if (can('crm.crear')) {
    $acciones .= '<button onclick="' . jsEvent('ficha:opo') . '" class="btn btn-soft">' . icon('briefcase', 'w-4 h-4') . ' Oportunidad</button>';
    $acciones .= '<button onclick="' . jsEvent('ficha:tarea') . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Tarea</button>';
}
layout_start('Ficha 360° · ' . $cliente['nombre'], 'Vista integral del cliente', $acciones);
?>

<!-- Cabecera del cliente -->
<div class="card p-6 mb-5">
  <div class="flex flex-col sm:flex-row sm:items-center gap-4">
    <?= avatar($cliente['nombre'], 'w-16 h-16 text-xl') ?>
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2 flex-wrap">
        <h2 class="text-xl font-extrabold text-slate-800"><?= e($cliente['nombre']) ?></h2>
        <?= $cliente['tipo'] === 'credito' ? badge('Crédito', 'indigo') : badge('Contado', 'slate') ?>
        <?= $cliente['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?>
      </div>
      <p class="text-xs text-slate-400 font-mono mt-0.5"><?= e($cliente['codigo']) ?></p>
      <div class="flex items-center gap-4 mt-2 text-sm text-slate-500 flex-wrap">
        <?php if ($cliente['telefono']): ?><span class="inline-flex items-center gap-1.5"><?= icon('phone', 'w-4 h-4') ?> <?= e($cliente['telefono']) ?></span><?php endif; ?>
        <?php if ($cliente['email']): ?><span class="inline-flex items-center gap-1.5"><?= icon('mail', 'w-4 h-4') ?> <?= e($cliente['email']) ?></span><?php endif; ?>
        <?php if ($cliente['rnc_cedula']): ?><span class="inline-flex items-center gap-1.5"><?= icon('id', 'w-4 h-4') ?> <?= e($cliente['rnc_cedula']) ?></span><?php endif; ?>
        <?php if ($cliente['direccion']): ?><span class="inline-flex items-center gap-1.5"><?= icon('map', 'w-4 h-4') ?> <?= e($cliente['direccion']) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-5">
  <div class="card p-4"><p class="text-xs text-slate-500">Total comprado</p><p class="text-xl font-extrabold text-slate-800"><?= money((float) $ventasKpi['t']) ?></p></div>
  <div class="card p-4"><p class="text-xs text-slate-500">Compras</p><p class="text-xl font-extrabold text-slate-800"><?= number_format((int) $ventasKpi['n']) ?></p></div>
  <div class="card p-4"><p class="text-xs text-slate-500">Balance por cobrar</p><p class="text-xl font-extrabold <?= $cliente['balance'] > 0 ? 'text-amber-600' : 'text-slate-800' ?>"><?= money($cliente['balance']) ?></p></div>
  <div class="card p-4"><p class="text-xs text-slate-500">Oport. abiertas</p><p class="text-xl font-extrabold text-slate-800"><?= (int) $opoKpi['n'] ?></p></div>
  <div class="card p-4"><p class="text-xs text-slate-500">Valor pipeline</p><p class="text-xl font-extrabold text-blue-600"><?= money((float) $opoKpi['v']) ?></p></div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <!-- Columna principal -->
  <div class="lg:col-span-2 space-y-5">

    <!-- Oportunidades -->
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
        <?= icon('briefcase', 'w-5 h-5 text-slate-400') ?><h3 class="font-bold text-slate-800">Oportunidades</h3>
        <span class="text-sm text-slate-400 ml-auto"><?= count($oportunidades) ?></span>
      </div>
      <?php if (!$oportunidades): ?>
        <p class="p-5 text-sm text-slate-400">Sin oportunidades registradas para este cliente.</p>
      <?php else: ?>
        <div class="divide-y divide-slate-100">
          <?php foreach ($oportunidades as $o): ?>
            <div class="px-5 py-3 flex items-center gap-3">
              <div class="min-w-0 flex-1">
                <p class="font-semibold text-slate-700 text-sm"><?= e($o['titulo']) ?></p>
                <p class="text-xs text-slate-400"><?= e($o['codigo']) ?> · <?= money($o['valor_estimado']) ?> · <?= $o['responsable'] ? e($o['responsable']) : 'Sin asignar' ?></p>
              </div>
              <?php if (can('crm.avanzar')): ?>
                <form method="post" action="<?= e(url('modules/crm/oportunidades.php')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="accion" value="avanzar"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                  <select name="etapa" onchange="this.form.submit()" class="select !w-auto !py-1 !text-xs">
                    <?php foreach ($etapas as $k => $et): ?><option value="<?= e($k) ?>" <?= $o['etapa'] === $k ? 'selected' : '' ?>><?= e($et[0]) ?></option><?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?>
                <?= crm_etapa_badge($o['etapa']) ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Interacciones / timeline -->
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
        <?= icon('phone', 'w-5 h-5 text-slate-400') ?><h3 class="font-bold text-slate-800">Bitácora de interacciones</h3>
      </div>

      <?php if (can('crm.crear')): ?>
      <form method="post" class="p-5 border-b border-slate-100 bg-slate-50/60">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_interaccion">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
          <select name="tipo" class="select sm:col-span-1">
            <?php foreach ($tipos as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t[0]) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="asunto" required class="input sm:col-span-3" placeholder="Asunto (ej. Llamada de seguimiento)">
          <?php if ($fijaSuc === null): ?>
          <select name="sucursal_id" required class="select sm:col-span-2">
            <option value="">— Sucursal —</option>
            <?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
          </select>
          <?php endif; ?>
          <input type="text" name="detalle" class="input <?= $fijaSuc === null ? 'sm:col-span-2' : 'sm:col-span-3' ?>" placeholder="Detalle (opcional)">
          <button type="submit" class="btn btn-primary sm:col-span-1"><?= icon('plus', 'w-4 h-4') ?> Registrar</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (!$interacciones): ?>
        <p class="p-5 text-sm text-slate-400">Aún no hay interacciones registradas.</p>
      <?php else: ?>
        <ul class="p-5 space-y-4">
          <?php foreach ($interacciones as $it): [$tl, $tico] = $tipos[$it['tipo']] ?? ['Nota', 'edit']; ?>
            <li class="flex gap-3">
              <span class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center shrink-0"><?= icon($tico, 'w-4 h-4') ?></span>
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-semibold text-slate-700 text-sm"><?= e($it['asunto']) ?></span>
                  <span class="badge badge-slate"><?= e($tl) ?></span>
                </div>
                <?php if ($it['detalle']): ?><p class="text-sm text-slate-500 mt-0.5"><?= e($it['detalle']) ?></p><?php endif; ?>
                <p class="text-xs text-slate-400 mt-0.5"><?= e(fechaHora($it['fecha'])) ?><?= $it['usuario'] ? ' · ' . e($it['usuario']) : '' ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <!-- Columna lateral -->
  <div class="space-y-5">

    <!-- Tareas pendientes -->
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
        <?= icon('check', 'w-5 h-5 text-slate-400') ?><h3 class="font-bold text-slate-800">Tareas pendientes</h3>
        <span class="text-sm text-slate-400 ml-auto"><?= count($tareas) ?></span>
      </div>
      <?php if (!$tareas): ?>
        <p class="p-5 text-sm text-slate-400">Sin tareas pendientes.</p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($tareas as $t): [$pl, $pc] = $prioridades[$t['prioridad']] ?? ['Media', 'sky']; $venc = crm_tarea_vencida($t); ?>
            <li class="px-5 py-3 flex items-start gap-2">
              <?php if (can('crm.editar')): ?>
                <form method="post" action="<?= e(url('modules/crm/tareas.php')) ?>" class="pt-0.5">
                  <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="estado" value="completada"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                  <button class="w-4 h-4 rounded-full border-2 border-slate-300 hover:border-emerald-500 hover:bg-emerald-50 transition" title="Completar"></button>
                </form>
              <?php endif; ?>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-700"><?= e($t['titulo']) ?></p>
                <div class="flex items-center gap-2 mt-0.5 flex-wrap"><?= badge($pl, $pc) ?><?php if ($venc): ?><?= badge('Vencida', 'rose') ?><?php endif; ?><?php if ($t['vence_at']): ?><span class="text-xs text-slate-400"><?= e(fechaCorta($t['vence_at'])) ?></span><?php endif; ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Últimas ventas -->
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
        <?= icon('receipt', 'w-5 h-5 text-slate-400') ?><h3 class="font-bold text-slate-800">Últimas ventas</h3>
      </div>
      <?php if (!$ventas): ?>
        <p class="p-5 text-sm text-slate-400">Sin ventas registradas.</p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($ventas as $v): ?>
            <li class="px-5 py-2.5 flex items-center justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-mono text-slate-600"><?= e($v['numero']) ?></p>
                <p class="text-xs text-slate-400"><?= e(fechaCorta($v['fecha'])) ?></p>
              </div>
              <div class="text-right">
                <p class="text-sm font-bold text-slate-700"><?= money($v['total']) ?></p>
                <?php if ($v['estado'] !== 'completada'): ?><span class="text-xs"><?= badge(ucfirst($v['estado']), 'rose') ?></span><?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Últimos abonos -->
    <?php if ($pagos): ?>
    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
        <?= icon('wallet', 'w-5 h-5 text-slate-400') ?><h3 class="font-bold text-slate-800">Últimos abonos</h3>
      </div>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($pagos as $p): ?>
          <li class="px-5 py-2.5 flex items-center justify-between gap-2">
            <span class="text-xs text-slate-400"><?= e(fechaCorta($p['fecha'])) ?><?= $p['notas'] ? ' · ' . e($p['notas']) : '' ?></span>
            <span class="text-sm font-bold text-emerald-600"><?= money($p['monto']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('crm.crear')): ?>
<!-- Modal: nueva oportunidad -->
<div x-data="{open:false}" @ficha:opo.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_oportunidad">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nueva oportunidad</h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2"><label class="label">Título *</label><input type="text" name="titulo" required class="input" placeholder="Ej. Pedido mayorista"></div>
          <?php if ($fijaSuc === null): ?>
          <div class="sm:col-span-2"><label class="label">Sucursal *</label><select name="sucursal_id" required class="select"><option value="">— Selecciona —</option><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
          <div><label class="label">Etapa</label><select name="etapa" class="select"><?php foreach ($etapas as $k => $et): ?><option value="<?= e($k) ?>"><?= e($et[0]) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Valor estimado (RD$)</label><input type="number" step="0.01" min="0" name="valor_estimado" class="input" placeholder="0.00"></div>
          <div><label class="label">Responsable</label><select name="responsable_id" class="select"><option value="">— Sin asignar —</option><?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Cierre estimado</label><input type="date" name="fecha_cierre_estimada" class="input"></div>
          <div class="sm:col-span-2"><label class="label">Fuente / canal</label><input type="text" name="fuente" class="input" placeholder="Ej. Referido, Instagram"></div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: nueva tarea -->
<div x-data="{open:false}" @ficha:tarea.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_tarea">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nueva tarea</h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2"><label class="label">Título *</label><input type="text" name="titulo" required class="input" placeholder="Ej. Llamar mañana a las 10am"></div>
          <?php if ($fijaSuc === null): ?>
          <div class="sm:col-span-2"><label class="label">Sucursal *</label><select name="sucursal_id" required class="select"><option value="">— Selecciona —</option><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
          <div><label class="label">Prioridad</label><select name="prioridad" class="select"><?php foreach ($prioridades as $k => $p): ?><option value="<?= e($k) ?>"><?= e($p[0]) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Vence</label><input type="datetime-local" name="vence_at" class="input"></div>
          <div class="sm:col-span-2"><label class="label">Asignar a</label><select name="asignado_a" class="select"><option value="">— Sin asignar —</option><?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['nombre']) ?></option><?php endforeach; ?></select></div>
          <div class="sm:col-span-2"><label class="label">Detalle</label><textarea name="detalle" rows="2" class="input" placeholder="Opcional"></textarea></div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
