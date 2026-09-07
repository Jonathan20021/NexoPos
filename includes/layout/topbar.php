<?php
/** Barra superior. */
$u = current_user();
$sucs = sucursales_visibles();
$puedeCambiarSuc = is_super() || ($u['sucursal_id'] === null) || count($sucs) > 1;
$sucActiva = current_sucursal_id();
$redir = $_SERVER['REQUEST_URI'] ?? url('modules/dashboard/index.php');
?>
<header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200 flex items-center gap-3 px-4 sm:px-6">
  <button @click="sidebar=true" aria-label="Abrir menú" title="Abrir menú" class="lg:hidden text-slate-500 hover:text-slate-800 -ml-1 p-2 -my-2"><?= icon('menu', 'w-6 h-6') ?></button>
  <button @click="toggleSidebar()" :aria-label="sidebarCollapsed ? 'Expandir menú' : 'Contraer menú'"
          :title="sidebarCollapsed ? 'Expandir menú' : 'Contraer menú'" :aria-expanded="(!sidebarCollapsed).toString()"
          class="hidden lg:flex w-10 h-10 -ml-1 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-4 focus:ring-blue-500/10 transition">
    <span x-show="!sidebarCollapsed"><?= icon('arrow-left', 'w-5 h-5') ?></span>
    <span x-show="sidebarCollapsed" style="display:none"><?= icon('arrow-right', 'w-5 h-5') ?></span>
  </button>

  <!-- Buscador global (Ctrl/⌘ + K) -->
  <?php include __DIR__ . '/buscador.php'; ?>

  <!-- Este lado no se encoge: quien cede espacio es el buscador. -->
  <div class="flex items-center gap-2 sm:gap-3 ml-auto shrink-0">
    <!-- Selector de sucursal -->
    <?php if ($puedeCambiarSuc): ?>
      <form action="<?= e(url('modules/admin/cambiar_sucursal.php')) ?>" method="post" class="hidden md:block">
        <?= csrf_field() ?>
        <input type="hidden" name="redir" value="<?= e($redir) ?>">
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><?= icon('store', 'w-4 h-4') ?></span>
          <select name="s" aria-label="Cambiar sucursal" onchange="this.form.submit()" class="appearance-none bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-8 h-10 text-sm font-medium text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 cursor-pointer">
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

    <!-- Fecha.
         Aparece a partir de `xl`, no de `lg`. En 1024 el menú lateral ya ocupa
         260 px y a la barra le quedan 755: con buscador, sucursal, fecha, ayuda,
         campana y usuario el bloque medía 813 y la página scrolleaba en
         horizontal. La fecha es lo prescindible de esa fila —está en cada
         documento y en el sistema operativo—, así que es lo que cede el sitio. -->
    <div class="hidden xl:flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-xl h-10 px-3.5 text-sm font-medium text-slate-600">
      <?= icon('calendar', 'w-4 h-4 text-slate-400') ?>
      <?= e(fechaLarga(date('Y-m-d'))) ?>
    </div>

    <!-- Ayuda de esta pantalla -->
    <?php $ayuda = ent_ayuda_actual(); ?>
    <div class="relative" x-data="{open:false}">
      <button @click="open=!open" aria-label="Ayuda" title="Ayuda de esta pantalla"
              class="relative w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition">
        <?= icon('book', 'w-5 h-5') ?>
        <?php if ($ayuda && $ayuda['estado'] !== 'completada'): ?>
          <?php /* Un punto, no un número: señala que hay algo que leer sin
                    convertir la ayuda en una tarea pendiente más. */ ?>
          <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-blue-500 ring-2 ring-white"></span>
        <?php endif; ?>
      </button>
      <div x-show="open" @click.outside="open=false" x-transition style="display:none"
           class="absolute right-0 mt-2 w-72 bg-white rounded-2xl shadow-pop border border-slate-100 p-2 z-50">
        <?php if ($ayuda): ?>
          <a href="<?= e($ayuda['url']) ?>" class="block px-3 py-2.5 rounded-xl hover:bg-blue-50 group">
            <p class="text-[11px] uppercase tracking-wider font-bold text-blue-600">Cómo funciona esta pantalla</p>
            <p class="text-sm font-semibold text-slate-700 mt-0.5 leading-snug group-hover:text-blue-700"><?= e($ayuda['titulo']) ?></p>
            <p class="text-xs text-slate-400 mt-0.5"><?= (int) $ayuda['minutos'] ?> min<?= $ayuda['estado'] === 'completada' ? ' · ya la completaste' : '' ?></p>
          </a>
          <div class="h-px bg-slate-100 my-1"></div>
        <?php else: ?>
          <p class="px-3 py-2.5 text-xs text-slate-400 leading-relaxed">Esta pantalla todavía no tiene su lección. Empieza por el temario de tu rol.</p>
        <?php endif; ?>
        <a href="<?= e(url('modules/entrenamiento/index.php')) ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-50"><?= icon('target', 'w-4 h-4') ?> Centro de Entrenamiento</a>
        <a href="<?= e(url('modules/entrenamiento/glosario.php')) ?>" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-50"><?= icon('book', 'w-4 h-4') ?> Glosario</a>
      </div>
    </div>

    <!-- Notificaciones -->
    <?php include __DIR__ . '/notificaciones.php'; ?>

    <!-- Usuario -->
    <div class="relative" x-data="{open:false}">
      <button @click="open=!open" class="flex items-center gap-2.5 pl-1 pr-2 h-10 rounded-xl hover:bg-slate-100 transition">
        <?= avatar(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''), 'w-9 h-9') ?>
        <!-- Acotado y recortado: «Administrador de TSS/NOMINA» ensanchaba la
             barra hasta sacarla de la pantalla. El nombre completo sigue
             estando en el menú que se abre debajo. -->
        <div class="hidden sm:block text-left leading-tight max-w-[10rem] xl:max-w-[13rem]">
          <p class="text-sm font-semibold text-slate-700 truncate" title="<?= e($u['nombre'] . ' ' . $u['apellido']) ?>"><?= e($u['nombre'] . ' ' . $u['apellido']) ?></p>
          <p class="text-[11px] text-slate-400 truncate" title="<?= e($u['rol_nombre']) ?>"><?= e($u['rol_nombre']) ?></p>
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
        <form method="post" action="<?= e(url('modules/auth/logout.php')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-rose-600 hover:bg-rose-50"><?= icon('logout', 'w-4 h-4') ?> Cerrar sesión</button>
        </form>
      </div>
    </div>
  </div>
</header>
