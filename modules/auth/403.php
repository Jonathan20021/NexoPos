<?php
/** Página 403 — incluida por require_perm() cuando falta permiso. */
if (!function_exists('layout_start')) {
    require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
}
layout_start('Acceso denegado');
?>
<div class="card p-10 text-center max-w-lg mx-auto mt-6">
  <div class="w-16 h-16 rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center mx-auto mb-5"><?= icon('lock', 'w-8 h-8') ?></div>
  <h2 class="text-xl font-extrabold text-slate-800">No tienes permiso</h2>
  <p class="text-slate-500 mt-2 text-sm">Tu rol <strong><?= e(current_user()['rol_nombre'] ?? '') ?></strong> no tiene acceso a esta sección. Si crees que es un error, contacta al administrador.</p>
  <a href="<?= e(url('modules/dashboard/index.php')) ?>" class="btn btn-primary mt-6 inline-flex"><?= icon('dashboard', 'w-4 h-4') ?> Volver al panel</a>
</div>
<?php layout_end(); ?>
