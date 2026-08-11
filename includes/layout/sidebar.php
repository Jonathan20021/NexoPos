<?php
/** Menú lateral (filtrado por permisos del usuario). */
$u = current_user();

// Indicadores dinámicos
[$scopeSt, $paramsSt] = sucursalScope('s.sucursal_id');
$lowStock = (int) qVal(
    "SELECT COUNT(*) FROM inventario_stock s JOIN productos p ON p.id = s.producto_id
     WHERE p.activo = 1 AND s.cantidad <= p.stock_minimo AND $scopeSt",
    $paramsSt
);
?>
<aside class="app-sidebar fixed inset-y-0 left-0 z-40 w-[260px] bg-white border-r border-slate-200 flex flex-col transition-transform duration-300 lg:translate-x-0"
       :class="sidebar ? 'translate-x-0' : '-translate-x-full'">

  <!-- Marca -->
  <div class="sidebar-brand h-16 flex items-center gap-2.5 px-5 border-b border-slate-100 shrink-0">
    <?php
      // Misma cadena de respaldo que en los comprobantes: logo de la empresa y,
      // si no hay ninguno cargado, el de la aplicación.
      $brandLogo = setting('logo') ?: marca_app_logo();
      $hayLogo   = $brandLogo && is_file(dirname(__DIR__, 2) . '/' . $brandLogo);
      $inicial   = mb_strtoupper(mb_substr((string) setting('nombre', APP_NAME), 0, 1));
    ?>
    <?php if ($hayLogo): ?>
      <?php /* Desplegada: manda el logotipo. El nombre del sistema va debajo en
                pequeño porque el logotipo ya trae el del negocio, y repetirlo en
                grande competiría con él. */ ?>
      <div class="sidebar-brand-name min-w-0">
        <img src="<?= e(url($brandLogo)) ?>" alt="<?= e(setting('nombre', APP_NAME)) ?>"
             class="h-7 max-w-[168px] object-contain object-left">
        <span class="block mt-1 text-[11px] font-semibold uppercase tracking-[.12em] text-slate-400 leading-none"><?= e(APP_NAME) ?></span>
      </div>
      <?php /* Plegada (80 px): ahí no cabe un logotipo apaisado, así que se
                reduce a la inicial del negocio. */ ?>
      <div class="sidebar-brand-mini w-9 h-9 rounded-xl bg-blue-600 text-white items-center justify-center font-extrabold text-lg shadow-lg shadow-blue-600/30"><?= e($inicial) ?></div>
    <?php else: ?>
      <div class="w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center font-extrabold text-lg shadow-lg shadow-blue-600/30">N</div>
      <span class="sidebar-brand-name text-xl font-extrabold text-slate-800 tracking-tight"><?= e(APP_NAME) ?></span>
    <?php endif; ?>
    <button @click="sidebar=false" aria-label="Cerrar menú" title="Cerrar menú" class="ml-auto lg:hidden text-slate-400 hover:text-slate-700 p-2 -mr-2"><?= icon('x', 'w-5 h-5') ?></button>
  </div>

  <!-- Navegación -->
  <nav class="sidebar-nav flex-1 overflow-y-auto px-3 py-2 pb-4">
    <?php foreach (nav_groups() as [$grupo, $items]):
        // Filtrar items por permiso
        $visibles = array_filter($items, fn($it) => $it[3] === null || can($it[3]));
        if (!$visibles) continue;
    ?>
      <div class="sidebar-nav-group">
      <div class="sidebar-section-label nav-section"><?= e($grupo) ?></div>
      <?php foreach ($visibles as $it):
          [$label, $ico, $href, $perm] = $it;
          $isActive = navActive($href);
      ?>
        <a href="<?= e($href) ?>" class="nav-link <?= $isActive ? 'nav-active' : '' ?>" title="<?= e($label) ?>"
           @mouseenter="showSidebarTooltip(<?= e(json_encode($label)) ?>, $el)"
           @focus="showSidebarTooltip(<?= e(json_encode($label)) ?>, $el)"
           @mouseleave="sidebarTooltip.visible=false" @blur="sidebarTooltip.visible=false">
          <?= icon($ico, 'w-[18px] h-[18px] shrink-0') ?>
          <span class="sidebar-link-label flex-1"><?= e($label) ?></span>
          <?php if ($label === 'Stock' && $lowStock > 0): ?>
            <span class="sidebar-stock-badge bg-rose-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center"><?= $lowStock ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <!-- Usuario -->
  <div class="border-t border-slate-100 p-3 shrink-0">
    <div class="sidebar-user flex items-center gap-3 px-2 py-2 rounded-xl">
      <?= avatar(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''), 'w-9 h-9') ?>
      <div class="sidebar-user-copy min-w-0 flex-1">
        <p class="text-sm font-semibold text-slate-700 truncate"><?= e(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')) ?></p>
        <p class="text-xs text-slate-400 truncate"><?= e($u['rol_nombre'] ?? '') ?></p>
      </div>
      <form class="sidebar-logout" method="post" action="<?= e(url('modules/auth/logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" title="Cerrar sesión" class="text-slate-400 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 transition"><?= icon('logout', 'w-[18px] h-[18px]') ?></button>
      </form>
    </div>
  </div>

  <div x-show="sidebarTooltip.visible && sidebarCollapsed" x-transition.opacity role="tooltip"
       class="hidden lg:block fixed left-[88px] z-50 -translate-y-1/2 pointer-events-none whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white shadow-md"
       :style="`top:${sidebarTooltip.top}px`" x-text="sidebarTooltip.label" style="display:none"></div>
</aside>
