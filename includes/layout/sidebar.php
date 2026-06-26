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
<aside class="fixed inset-y-0 left-0 z-40 w-[260px] bg-white border-r border-slate-200 flex flex-col transition-transform duration-300 lg:translate-x-0"
       :class="sidebar ? 'translate-x-0' : '-translate-x-full'">

  <!-- Marca -->
  <div class="h-16 flex items-center gap-2.5 px-5 border-b border-slate-100 shrink-0">
    <?php $brandLogo = setting('logo'); ?>
    <?php if ($brandLogo && is_file(dirname(__DIR__, 2) . '/' . $brandLogo)): ?>
      <img src="<?= e(url($brandLogo)) ?>" alt="Logo" class="w-9 h-9 rounded-xl object-contain bg-white border border-slate-100">
    <?php else: ?>
      <div class="w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center font-extrabold text-lg shadow-lg shadow-blue-600/30">N</div>
    <?php endif; ?>
    <span class="text-xl font-extrabold text-slate-800 tracking-tight"><?= e(APP_NAME) ?></span>
    <button @click="sidebar=false" class="ml-auto lg:hidden text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
  </div>

  <!-- Navegación -->
  <nav class="flex-1 overflow-y-auto px-3 py-2 pb-4">
    <?php foreach (nav_groups() as [$grupo, $items]):
        // Filtrar items por permiso
        $visibles = array_filter($items, fn($it) => $it[3] === null || can($it[3]));
        if (!$visibles) continue;
    ?>
      <div class="nav-section"><?= e($grupo) ?></div>
      <?php foreach ($visibles as $it):
          [$label, $ico, $href, $perm] = $it;
          $isActive = navActive($href);
      ?>
        <a href="<?= e($href) ?>" class="nav-link <?= $isActive ? 'nav-active' : '' ?>">
          <?= icon($ico, 'w-[18px] h-[18px] shrink-0') ?>
          <span class="flex-1"><?= e($label) ?></span>
          <?php if ($label === 'Stock' && $lowStock > 0): ?>
            <span class="bg-rose-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center"><?= $lowStock ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <!-- Usuario -->
  <div class="border-t border-slate-100 p-3 shrink-0">
    <div class="flex items-center gap-3 px-2 py-2 rounded-xl">
      <?= avatar(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''), 'w-9 h-9') ?>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-slate-700 truncate"><?= e(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')) ?></p>
        <p class="text-xs text-slate-400 truncate"><?= e($u['rol_nombre'] ?? '') ?></p>
      </div>
      <a href="<?= e(url('modules/auth/logout.php')) ?>" title="Cerrar sesión" class="text-slate-400 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 transition"><?= icon('logout', 'w-[18px] h-[18px]') ?></a>
    </div>
  </div>
</aside>
