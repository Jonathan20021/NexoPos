<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_perm('crm.ver');

$etapas = crm_etapas();
[$scope, $scopeParams] = sucursalScope('o.sucursal_id');

// ---------- KPIs ----------
$abiertas = qOne("SELECT COUNT(*) n, COALESCE(SUM(valor_estimado),0) v
                  FROM crm_oportunidades o WHERE $scope AND etapa NOT IN ('ganada','perdida')", $scopeParams);
$ganadasMes = qOne("SELECT COUNT(*) n, COALESCE(SUM(valor_estimado),0) v
                    FROM crm_oportunidades o WHERE $scope AND etapa='ganada' AND fecha_cierre_real >= ?",
                    array_merge($scopeParams, [date('Y-m-01')]));
$perdidasMes = (int) qVal("SELECT COUNT(*) FROM crm_oportunidades o WHERE $scope AND etapa='perdida' AND fecha_cierre_real >= ?",
                    array_merge($scopeParams, [date('Y-m-01')]));

[$scopeT, $scopeTParams] = sucursalScope('t.sucursal_id');
$tareasVencidas = (int) qVal("SELECT COUNT(*) FROM crm_tareas t WHERE $scopeT AND estado='pendiente' AND vence_at IS NOT NULL AND vence_at < NOW()", $scopeTParams);

// ---------- Oportunidades abiertas para el tablero ----------
$rows = qAll(
    "SELECT o.*, c.nombre AS cliente, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS responsable
     FROM crm_oportunidades o
     JOIN clientes c ON c.id = o.cliente_id
     LEFT JOIN usuarios u ON u.id = o.responsable_id
     WHERE $scope AND o.etapa NOT IN ('ganada','perdida')
     ORDER BY o.valor_estimado DESC, o.updated_at DESC",
    $scopeParams
);

$columnas = [];
foreach (crm_etapas_abiertas() as $et) $columnas[$et] = ['items' => [], 'total' => 0.0];
foreach ($rows as $r) {
    if (!isset($columnas[$r['etapa']])) continue;
    $columnas[$r['etapa']]['items'][] = $r;
    $columnas[$r['etapa']]['total'] += (float) $r['valor_estimado'];
}

$acciones = can('crm.crear') ? '<a href="' . e(url('modules/crm/oportunidades.php')) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nueva oportunidad</a>' : '';
layout_start('Embudo de Ventas', 'Tablero del pipeline por etapa', $acciones);
?>

<?php
// «Perdidas este mes» a secas no dice nada: 3 perdidas es bueno si se ganaron
// 30 y malo si se ganaron 2. Lo que importa es la proporción entre las dos.
$cerradas = (int) $ganadasMes['n'] + $perdidasMes;
$tasaGane = $cerradas > 0 ? round((int) $ganadasMes['n'] / $cerradas * 100) : 0;

echo kpis([
    ['label' => 'Valor del pipeline', 'valor' => money((float) $abiertas['v']), 'icono' => 'dollar', 'color' => 'blue',
     'nota' => (int) $abiertas['n'] . ' oportunidad' . ((int) $abiertas['n'] === 1 ? '' : 'es') . ' abierta'
        . ((int) $abiertas['n'] === 1 ? '' : 's')],
    ['label' => 'Ganadas este mes', 'valor' => number_format((int) $ganadasMes['n']), 'icono' => 'check',
     'color' => 'emerald', 'nota' => money((float) $ganadasMes['v']) . ' cerrados'],
    ['label' => 'Perdidas este mes', 'valor' => number_format($perdidasMes), 'icono' => 'x',
     'color' => $perdidasMes > 0 ? 'rose' : 'slate',
     'nota' => $cerradas > 0 ? 'Se gana el ' . $tasaGane . '% de lo que se cierra' : 'Nada cerrado todavía'],
    ['label' => 'Tareas vencidas', 'valor' => number_format($tareasVencidas), 'icono' => 'clock',
     'color' => $tareasVencidas > 0 ? 'amber' : 'emerald',
     'nota' => $tareasVencidas > 0 ? 'Compromisos que ya pasaron de fecha' : 'La agenda está al día',
     'href' => url('modules/crm/tareas.php?estado=vencidas')],
], 4);
?>

<!-- Tablero Kanban -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
  <?php foreach (crm_etapas_abiertas() as $et):
      $col = $columnas[$et];
      [$label, $color] = $etapas[$et];
  ?>
    <div class="bg-slate-100/70 rounded-2xl p-3 flex flex-col min-h-[120px]">
      <div class="flex items-center justify-between px-1 pb-3">
        <div class="flex items-center gap-2">
          <span class="badge badge-<?= e($color) ?>"><?= e($label) ?></span>
          <span class="text-xs font-semibold text-slate-400"><?= count($col['items']) ?></span>
        </div>
        <span class="text-xs font-bold text-slate-500"><?= money($col['total'], false) ?></span>
      </div>
      <div class="space-y-2.5 flex-1">
        <?php if (!$col['items']): ?>
          <p class="text-xs text-slate-400 text-center py-6">Sin oportunidades</p>
        <?php else: foreach ($col['items'] as $o): ?>
          <div class="bg-white rounded-xl border border-slate-200 p-3 shadow-sm hover:shadow-md transition">
            <div class="flex items-start justify-between gap-2">
              <p class="font-semibold text-slate-700 text-sm leading-tight"><?= e($o['titulo']) ?></p>
              <span class="text-[11px] font-bold text-blue-600 whitespace-nowrap"><?= money($o['valor_estimado'], false) ?></span>
            </div>
            <a href="<?= e(url('modules/crm/cliente.php?id=' . (int) $o['cliente_id'])) ?>" class="mt-1 flex items-center gap-1.5 text-xs text-slate-500 hover:text-blue-600">
              <?= icon('user', 'w-3.5 h-3.5') ?> <span class="truncate"><?= e($o['cliente']) ?></span>
            </a>
            <div class="mt-2 flex items-center justify-between gap-2">
              <span class="text-[11px] text-slate-400 truncate"><?= $o['responsable'] ? e($o['responsable']) : 'Sin asignar' ?></span>
              <?php if (can('crm.avanzar')): ?>
                <form method="post" action="<?= e(url('modules/crm/oportunidades.php')) ?>" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="avanzar">
                  <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                  <input type="hidden" name="volver" value="modules/crm/index.php">
                  <select name="etapa" onchange="this.form.submit()" class="select !w-auto !py-0.5 !text-[11px] !pr-6" title="Mover de etapa">
                    <?php foreach ($etapas as $k => $et2): ?>
                      <option value="<?= e($k) ?>" <?= $o['etapa'] === $k ? 'selected' : '' ?>><?= e($et2[0]) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?>
                <span class="text-[11px] font-semibold text-slate-400"><?= (int) $o['probabilidad'] ?>%</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<p class="text-xs text-slate-400 mt-4">Cambia la etapa desde el selector de cada tarjeta. Las oportunidades ganadas y perdidas salen del tablero y quedan en <a href="<?= e(url('modules/crm/oportunidades.php')) ?>" class="text-blue-600 hover:underline">Oportunidades</a>.</p>

<?php layout_end(); ?>
