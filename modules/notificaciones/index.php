<?php
/** Centro de notificaciones: todas las alertas vivas del negocio. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

notif_scan_si_toca();

$catFiltro = (string) get('categoria');
$soloNo    = get('no_leidas') === '1';
$validas   = ['inventario', 'ventas', 'finanzas', 'fiscal', 'crm', 'rrhh', 'sistema'];
if (!in_array($catFiltro, $validas, true)) $catFiltro = '';

$todas    = notif_listar(['limit' => 200]);
$noLeidas = notif_no_leidas();

// Conteo por categoría sobre el total visible (los filtros no cambian las pestañas).
$porCategoria = [];
foreach ($todas as $n) {
    $porCategoria[$n['categoria']] = ($porCategoria[$n['categoria']] ?? 0) + 1;
}

$lista = array_values(array_filter($todas, function ($n) use ($catFiltro, $soloNo) {
    if ($catFiltro !== '' && $n['categoria'] !== $catFiltro) return false;
    if ($soloNo && $n['leida']) return false;
    return true;
}));

$prioridades = [
    'critica' => ['Crítica', 'rose'], 'alta' => ['Alta', 'amber'],
    'media'   => ['Media', 'blue'],  'baja' => ['Informativa', 'slate'],
];
$resumen = notif_resumen();

$redir = $_SERVER['REQUEST_URI'] ?? url('modules/notificaciones/index.php');
$acciones = '<form method="post" action="' . e(url('modules/notificaciones/accion.php')) . '" class="inline-flex gap-2">'
    . csrf_field()
    . '<input type="hidden" name="redir" value="' . e($redir) . '">'
    . '<button type="submit" name="accion" value="revisar" class="btn btn-ghost">' . icon('history', 'w-4 h-4') . ' Revisar ahora</button>'
    . ($noLeidas > 0
        ? '<button type="submit" name="accion" value="marcar_todas" class="btn btn-primary">' . icon('check', 'w-4 h-4') . ' Marcar todas como leídas</button>'
        : '')
    . '</form>';

layout_start(
    'Notificaciones',
    $noLeidas > 0 ? $noLeidas . ' sin leer · ' . count($todas) . ' alerta(s) activa(s)' : 'Todo al día en ' . rep_alcance_sucursal(),
    $acciones
);
?>

<?php if (!notif_disponible()): ?>
  <div class="card p-6 flex items-start gap-4 border-amber-200 bg-amber-50/50">
    <span class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
    <div>
      <h3 class="font-bold text-slate-800">Falta aplicar la migración</h3>
      <p class="text-sm text-slate-600 mt-1">Ejecuta <code class="px-1.5 py-0.5 rounded bg-white border border-amber-200 text-xs">database/migracion_notificaciones_p3.sql</code> para activar el centro de notificaciones.</p>
    </div>
  </div>
<?php else: ?>

<?php
// Cuatro prioridades, cuatro tarjetas. Cuando una está en cero se apaga a gris:
// un «0 críticas» en rojo se lee como alarma cuando es justo lo contrario.
//
// No llevan enlace a propósito: esta pantalla filtra por CATEGORÍA y por «no
// leídas», no por prioridad. Una tarjeta que no lleva a ninguna parte es peor
// que una tarjeta quieta.
echo kpis(array_map(fn($t) => [
    'label' => $t[0], 'valor' => number_format((int) $t[1]), 'icono' => $t[2],
    'color' => (int) $t[1] > 0 ? $t[3] : 'slate', 'nota' => $t[4],
], [
    ['Críticas', $resumen['critica'], 'alert', 'rose', 'Detienen la operación'],
    ['Altas', $resumen['alta'], 'trending', 'amber', 'Requieren acción hoy'],
    ['Medias', $resumen['media'], 'bell', 'blue', 'Para esta semana'],
    ['Informativas', $resumen['baja'], 'check', 'emerald', 'Solo para enterarte'],
]), 4);
?>

<div class="card overflow-hidden">
  <!-- Filtros -->
  <div class="flex flex-wrap items-center gap-2 p-4 border-b border-slate-100">
    <div class="flex flex-wrap items-center gap-1 p-1 bg-slate-100 rounded-xl">
      <a href="<?= e('?' . http_build_query(array_filter(['no_leidas' => $soloNo ? '1' : null]))) ?>"
         class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition <?= $catFiltro === '' ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
        Todas <span class="text-slate-400"><?= count($todas) ?></span>
      </a>
      <?php foreach ($validas as $c): if (empty($porCategoria[$c])) continue; ?>
        <a href="<?= e('?' . http_build_query(array_filter(['categoria' => $c, 'no_leidas' => $soloNo ? '1' : null]))) ?>"
           class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition <?= $catFiltro === $c ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
          <?= e(notif_categoria_label($c)) ?> <span class="text-slate-400"><?= (int) $porCategoria[$c] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <label class="inline-flex items-center gap-2 text-sm text-slate-600 ml-auto cursor-pointer select-none">
      <input type="checkbox" onchange="location.href=this.checked ? '<?= e('?' . http_build_query(array_filter(['categoria' => $catFiltro ?: null, 'no_leidas' => '1']))) ?>' : '<?= e('?' . http_build_query(array_filter(['categoria' => $catFiltro ?: null]))) ?>'"
             <?= $soloNo ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
      Solo sin leer
    </label>
  </div>

  <?php if (!$lista): ?>
    <?= empty_state(
        $soloNo ? 'No tienes notificaciones sin leer' : 'Sin alertas en esta categoría',
        'El sistema revisa el inventario, la cobranza, la caja, los comprobantes fiscales y las tareas cada pocos minutos. Cuando algo requiera tu atención aparecerá aquí.',
        'check'
    ) ?>
  <?php else: ?>
    <ul class="divide-y divide-slate-100">
      <?php foreach ($lista as $n):
        [$icoCls, $barra] = notif_estilo($n['color']);
        [$pLabel, $pColor] = $prioridades[$n['prioridad']] ?? ['Media', 'blue'];
        $href = $n['url']
            ? url('modules/notificaciones/ir.php?id=' . (int) $n['id'] . '&_t=' . urlencode(csrf_token()))
            : '';
      ?>
        <li class="relative flex items-start gap-4 px-5 py-4 hover:bg-slate-50/70 transition <?= $n['leida'] ? '' : 'bg-blue-50/25' ?>">
          <span class="absolute left-0 top-0 bottom-0 w-1 <?= in_array($n['prioridad'], ['critica', 'alta'], true) ? $barra : 'bg-transparent' ?>"></span>
          <span class="w-11 h-11 rounded-xl <?= $icoCls ?> flex items-center justify-center shrink-0"><?= icon($n['icono'], 'w-5 h-5') ?></span>

          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h4 class="font-semibold text-slate-800"><?= e($n['titulo']) ?></h4>
              <?= badge($pLabel, $pColor) ?>
              <?php if (!$n['leida']): ?><span class="badge badge-blue">Nueva</span><?php endif; ?>
            </div>
            <?php if ($n['mensaje']): ?>
              <p class="text-sm text-slate-500 mt-1 leading-relaxed"><?= e($n['mensaje']) ?></p>
            <?php endif; ?>
            <div class="flex flex-wrap items-center gap-2 mt-2 text-[11.5px] text-slate-400">
              <span><?= e(tiempoRelativo($n['updated_at'])) ?></span>
              <span class="w-1 h-1 rounded-full bg-slate-300"></span>
              <span><?= e(notif_categoria_label($n['categoria'])) ?></span>
              <?php if ($n['sucursal_nombre']): ?>
                <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                <span class="inline-flex items-center gap-1"><?= icon('store', 'w-3 h-3') ?> <?= e($n['sucursal_nombre']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="flex items-center gap-1.5 shrink-0">
            <?php if (!$n['leida']): ?>
              <form method="post" action="<?= e(url('modules/notificaciones/accion.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="marcar_una">
                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                <input type="hidden" name="redir" value="<?= e($redir) ?>">
                <button type="submit" title="Marcar como leída" aria-label="Marcar como leída"
                        class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 transition"><?= icon('check', 'w-4 h-4') ?></button>
              </form>
            <?php endif; ?>
            <?php if ($href): ?>
              <a href="<?= e($href) ?>" class="btn btn-soft btn-sm whitespace-nowrap">Resolver <?= icon('arrow-right', 'w-3.5 h-3.5') ?></a>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<p class="text-xs text-slate-400 mt-4 text-center">
  Las alertas se recalculan solas cada <?= NOTIF_SCAN_MINUTOS ?> minutos y desaparecen cuando el problema queda resuelto.
</p>
<?php endif; ?>

<?php layout_end(); ?>
