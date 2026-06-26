<?php
/** Barra superior. */
$u = current_user();
$sucs = sucursales_visibles();
$puedeCambiarSuc = is_super() || ($u['sucursal_id'] === null) || count($sucs) > 1;
$sucActiva = current_sucursal_id();
$redir = $_SERVER['REQUEST_URI'] ?? url('modules/dashboard/index.php');
?>
<header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200 flex items-center gap-3 px-4 sm:px-6">
  <button @click="sidebar=true" class="lg:hidden text-slate-500 hover:text-slate-800 -ml-1"><?= icon('menu', 'w-6 h-6') ?></button>

  <!-- Buscador -->
  <form action="<?= e(url('modules/inventario/productos.php')) ?>" method="get" class="hidden sm:flex items-center gap-2 bg-slate-100 rounded-xl px-3.5 h-10 w-72 max-w-full focus-within:ring-2 focus-within:ring-blue-500/20 transition">
    <span class="text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
    <input type="text" name="q" placeholder="Buscar productos..." class="bg-transparent outline-none text-sm flex-1 placeholder:text-slate-400">
  </form>

  <div class="flex items-center gap-2 sm:gap-3 ml-auto">
    <!-- Selector de sucursal -->
    <?php if ($puedeCambiarSuc): ?>
      <form action="<?= e(url('modules/admin/cambiar_sucursal.php')) ?>" method="get" class="hidden md:block">
        <input type="hidden" name="redir" value="<?= e($redir) ?>">
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><?= icon('store', 'w-4 h-4') ?></span>
          <select name="s" onchange="this.form.submit()" class="appearance-none bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-8 h-10 text-sm font-medium text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 cursor-pointer">
            <?php if (is_super() || $u['sucursal_id'] === null): ?>
              <option value="" <?= $sucActiva === null ? 'selected' : '' ?>>Todas las sucursales</option>
            <?php endif; ?>
            <?php foreach ($sucs as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= ((int) $s['id'] === $sucActiva) ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><?= icon('chevron-down', 'w-4 h-4') ?></span>
        </div>
      </form>
    <?php endif; ?>

    <!-- Fecha -->
    <div class="hidden lg:flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-xl h-10 px-3.5 text-sm font-medium text-slate-600">
      <?= icon('calendar', 'w-4 h-4 text-slate-400') ?>
      <?= e(fechaLarga(date('Y-m-d'))) ?>
    </div>

    <!-- Notificaciones -->
    <a href="<?= e(url('modules/inventario/stock.php')) ?>" class="relative w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition" title="Productos con stock bajo">
      <?= icon('bell', 'w-5 h-5') ?>
      <?php if (!empty($lowStock)): ?><span class="absolute -top-1 -right-1 bg-rose-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] px-1 inline-flex items-center justify-center"><?= (int) $lowStock ?></span><?php endif; ?>
    </a>

    <!-- Usuario -->
    <div class="relative" x-data="{open:false}">
      <button @click="open=!open" class="flex items-center gap-2.5 pl-1 pr-2 h-10 rounded-xl hover:bg-slate-100 transition">
        <?= avatar(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''), 'w-9 h-9') ?>
        <div class="hidden sm:block text-left leading-tight">
          <p class="text-sm font-semibold text-slate-700"><?= e($u['nombre'] . ' ' . $u['apellido']) ?></p>
          <p class="text-[11px] text-slate-400"><?= e($u['rol_nombre']) ?></p>
        </div>
        <span class="text-slate-400 hidden sm:block"><?= icon('chevron-down', 'w-4 h-4') ?></span>
      </button>
      <div x-show="open" @click.outside="open=false" x-transition style="display:none" class="absolute right-0 mt-2 w-60 bg-white rounded-2xl shadow-pop border border-slate-100 p-2 z-50">
        <div class="px-3 py-2.5 border-b border-slate-100 mb-1">
          <p class="text-sm font-semibold text-slate-700"><?= e($u['nombre'] . ' ' . $u['apellido']) ?></p>
          <p class="text-xs text-slate-400"><?= e($u['email']) ?></p>
          <p class="text-xs text-blue-600 font-medium mt-1"><?= icon('store', 'w-3 h-3 inline -mt-0.5') ?> <?= e($u['sucursal_nombre']) ?></p>
        </div>
        <a href="<?= e(url('modules/auth/perfil.php')) ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-50"><?= icon('user', 'w-4 h-4') ?> Mi perfil</a>
        <?php if (can('configuracion.ver')): ?>
        <a href="<?= e(url('modules/admin/configuracion.php')) ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-50"><?= icon('settings', 'w-4 h-4') ?> Configuración</a>
        <?php endif; ?>
        <a href="<?= e(url('modules/auth/logout.php')) ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-rose-600 hover:bg-rose-50"><?= icon('logout', 'w-4 h-4') ?> Cerrar sesión</a>
      </div>
    </div>
  </div>
</header>
