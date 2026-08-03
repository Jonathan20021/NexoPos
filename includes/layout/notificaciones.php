<?php
/**
 * Widget de notificaciones de la barra superior.
 *
 * Se alimenta del motor de includes/notificaciones.php: aquí no hay lógica de
 * negocio, solo presentación. El barrido se dispara antes de pintar (acotado a
 * una vez cada NOTIF_SCAN_MINUTOS entre todos los usuarios).
 */
notif_scan_si_toca();
// Mismo enganche para el motor de marketing: despacha campañas programadas y
// automatizaciones sin necesidad de cron. Solo entra si hay trabajo pendiente.
mkt_tick_si_toca();

$notifs     = notif_listar(['limit' => 12]);
$noLeidas   = notif_no_leidas();
$resumen    = notif_resumen();
$criticas   = $resumen['critica'] + $resumen['alta'];
$tokenNotif = csrf_token();
?>
<div class="relative" x-data="{open:false}" @keydown.escape.window="open=false">
  <button @click="open=!open" type="button"
          :aria-expanded="open.toString()" aria-haspopup="true"
          aria-label="Notificaciones<?= $noLeidas ? ' (' . $noLeidas . ' sin leer)' : '' ?>"
          title="Notificaciones"
          class="relative w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-500 hover:text-slate-800 hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-blue-500/10 transition">
    <?= icon('bell', 'w-5 h-5') ?>
    <?php if ($noLeidas > 0): ?>
      <?php if ($criticas > 0): ?>
        <span class="absolute -top-1 -right-1 flex h-[18px] min-w-[18px]">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-60"></span>
          <span class="relative inline-flex items-center justify-center rounded-full bg-rose-500 text-white text-[10px] font-bold px-1 min-w-[18px] h-[18px]"><?= $noLeidas > 99 ? '99+' : $noLeidas ?></span>
        </span>
      <?php else: ?>
        <span class="absolute -top-1 -right-1 bg-blue-600 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center"><?= $noLeidas > 99 ? '99+' : $noLeidas ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </button>

  <!-- Panel -->
  <div x-show="open" x-cloak @click.outside="open=false"
       x-transition:enter="transition ease-out duration-150"
       x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
       x-transition:enter-end="opacity-100 translate-y-0 scale-100"
       x-transition:leave="transition ease-in duration-100"
       x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
       class="fixed inset-x-3 top-[68px] sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-[400px] bg-white rounded-2xl shadow-pop border border-slate-100 z-50 overflow-hidden origin-top-right">

    <div class="flex items-center justify-between gap-2 px-4 py-3.5 border-b border-slate-100">
      <div class="min-w-0">
        <h3 class="font-bold text-slate-800 leading-tight">Notificaciones</h3>
        <p class="text-xs text-slate-400 mt-0.5">
          <?php if ($noLeidas > 0): ?>
            <?= $noLeidas ?> sin leer<?= $criticas > 0 ? ' · ' . $criticas . ' requieren atención' : '' ?>
          <?php else: ?>
            Todo al día
          <?php endif; ?>
        </p>
      </div>
      <?php if ($noLeidas > 0): ?>
        <form method="post" action="<?= e(url('modules/notificaciones/accion.php')) ?>" class="shrink-0">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="marcar_todas">
          <input type="hidden" name="redir" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <button type="submit" class="text-xs font-semibold text-blue-600 hover:text-blue-700 px-2 py-1 rounded-lg hover:bg-blue-50 transition whitespace-nowrap">
            Marcar leídas
          </button>
        </form>
      <?php endif; ?>
    </div>

    <div class="max-h-[min(65vh,460px)] overflow-y-auto overscroll-contain divide-y divide-slate-50">
      <?php if (!$notifs): ?>
        <div class="flex flex-col items-center text-center px-6 py-12">
          <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-500 flex items-center justify-center mb-3"><?= icon('check', 'w-7 h-7') ?></div>
          <p class="text-sm font-semibold text-slate-700">Sin alertas pendientes</p>
          <p class="text-xs text-slate-400 mt-1 max-w-[240px]">El inventario, la cobranza y los comprobantes fiscales están en orden.</p>
        </div>
      <?php else: foreach ($notifs as $n):
        [$icoCls, $barra] = notif_estilo($n['color']);
        $href = $n['url']
            ? url('modules/notificaciones/ir.php?id=' . (int) $n['id'] . '&_t=' . urlencode($tokenNotif))
            : url('modules/notificaciones/index.php');
      ?>
        <a href="<?= e($href) ?>" class="flex gap-3 px-4 py-3.5 hover:bg-slate-50 transition group relative <?= $n['leida'] ? '' : 'bg-blue-50/30' ?>">
          <span class="absolute left-0 top-0 bottom-0 w-1 <?= in_array($n['prioridad'], ['critica', 'alta'], true) ? $barra : 'bg-transparent' ?>"></span>
          <span class="w-9 h-9 rounded-xl <?= $icoCls ?> flex items-center justify-center shrink-0 mt-0.5"><?= icon($n['icono'], 'w-4 h-4') ?></span>
          <span class="min-w-0 flex-1">
            <span class="flex items-start gap-2">
              <span class="text-[13.5px] font-semibold text-slate-800 leading-snug flex-1"><?= e($n['titulo']) ?></span>
              <?php if (!$n['leida']): ?><span class="w-2 h-2 rounded-full bg-blue-600 shrink-0 mt-1.5"></span><?php endif; ?>
            </span>
            <?php if ($n['mensaje']): ?>
              <span class="block text-xs text-slate-500 mt-0.5 leading-relaxed"><?= e($n['mensaje']) ?></span>
            <?php endif; ?>
            <span class="flex items-center gap-2 mt-1.5 text-[11px] text-slate-400">
              <span><?= e(tiempoRelativo($n['updated_at'])) ?></span>
              <?php if ($n['sucursal_nombre']): ?>
                <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                <span class="truncate"><?= e($n['sucursal_nombre']) ?></span>
              <?php endif; ?>
              <span class="w-1 h-1 rounded-full bg-slate-300"></span>
              <span><?= e(notif_categoria_label($n['categoria'])) ?></span>
            </span>
          </span>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <div class="px-4 py-3 border-t border-slate-100 bg-slate-50/60">
      <a href="<?= e(url('modules/notificaciones/index.php')) ?>" class="flex items-center justify-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700">
        Ver centro de notificaciones <?= icon('arrow-right', 'w-4 h-4') ?>
      </a>
    </div>
  </div>
</div>
