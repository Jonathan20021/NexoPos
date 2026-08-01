<?php
/**
 * Página de resultados de la búsqueda global.
 *
 * Es el respaldo sin JavaScript del buscador de la barra (pulsar Enter cae
 * aquí) y también la vista «ver todos los resultados».
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$q = trim((string) get('q'));
$q = mb_substr($q, 0, 60);
$grupos = $q !== '' ? buscar_global($q, 20) : [];
$total  = buscar_total($grupos);

layout_start(
    'Búsqueda',
    $q === '' ? 'Encuentra productos, clientes, facturas y más' : $total . ' resultado(s) para «' . $q . '»',
    '<a href="' . e(url('modules/dashboard/index.php')) . '" class="btn btn-ghost">' . icon('dashboard', 'w-4 h-4') . ' Ir al panel</a>'
);
?>

<form method="get" class="card p-4 mb-5">
  <div class="relative">
    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><?= icon('search', 'w-5 h-5') ?></span>
    <input type="search" name="q" value="<?= e($q) ?>" autofocus autocomplete="off"
           placeholder="Producto, cliente, factura, NCF, proveedor, empleado…"
           aria-label="Buscar en todo el sistema"
           class="input pl-12 pr-28 py-3.5 text-[15px]">
    <button type="submit" class="btn btn-primary btn-sm absolute right-2 top-1/2 -translate-y-1/2">Buscar</button>
  </div>
</form>

<?php if ($q === ''): ?>
  <div class="card p-6">
    <h3 class="font-bold text-slate-800 mb-1">Accesos rápidos</h3>
    <p class="text-sm text-slate-400 mb-5">También puedes abrir el buscador desde cualquier pantalla con <kbd class="px-1.5 py-0.5 rounded border border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500">Ctrl</kbd> + <kbd class="px-1.5 py-0.5 rounded border border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500">K</kbd>.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
      <?php foreach (buscar_atajos() as $a): ?>
        <a href="<?= e($a['url']) ?>" class="flex items-center gap-3 rounded-xl border border-slate-200 p-3.5 hover:border-blue-300 hover:bg-blue-50/40 transition group">
          <span class="w-10 h-10 rounded-xl bg-slate-50 text-slate-400 group-hover:bg-blue-50 group-hover:text-blue-600 flex items-center justify-center shrink-0 transition"><?= icon($a['icono'], 'w-5 h-5') ?></span>
          <span class="min-w-0">
            <span class="block font-semibold text-slate-700 group-hover:text-blue-700 transition"><?= e($a['titulo']) ?></span>
            <span class="block text-xs text-slate-400 truncate"><?= e($a['subtitulo']) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

<?php elseif (!$grupos): ?>
  <div class="card">
    <?= empty_state(
        'Sin resultados para «' . $q . '»',
        'Revisa la ortografía o prueba con menos palabras. La búsqueda cubre código, nombre, RNC/cédula, número de factura y NCF.',
        'search'
    ) ?>
  </div>

<?php else: ?>
  <div class="space-y-5">
    <?php foreach ($grupos as $g):
      $fondos = ['blue' => 'bg-blue-50 text-blue-600', 'emerald' => 'bg-emerald-50 text-emerald-600',
                 'cyan' => 'bg-cyan-50 text-cyan-600', 'amber' => 'bg-amber-50 text-amber-600',
                 'indigo' => 'bg-indigo-50 text-indigo-600', 'violet' => 'bg-violet-50 text-violet-600',
                 'rose' => 'bg-rose-50 text-rose-600', 'slate' => 'bg-slate-100 text-slate-500'];
    ?>
      <section class="card overflow-hidden">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100">
          <span class="w-9 h-9 rounded-xl <?= $fondos[$g['color']] ?? $fondos['slate'] ?> flex items-center justify-center shrink-0"><?= icon($g['icono'], 'w-4 h-4') ?></span>
          <h3 class="font-bold text-slate-800"><?= e($g['grupo']) ?></h3>
          <span class="badge badge-slate ml-auto"><?= count($g['items']) ?></span>
        </div>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($g['items'] as $it): ?>
            <li>
              <a href="<?= e($it['url']) ?>" class="flex items-center gap-4 px-5 py-3.5 hover:bg-slate-50 transition group">
                <span class="min-w-0 flex-1">
                  <span class="block font-semibold text-slate-800 group-hover:text-blue-700 transition truncate">
                    <?= e($it['titulo']) ?>
                    <?php if (!empty($it['inactivo'])): ?><span class="badge badge-slate ml-1.5">Inactivo</span><?php endif; ?>
                  </span>
                  <?php if (!empty($it['subtitulo'])): ?>
                    <span class="block text-[12.5px] text-slate-400 truncate mt-0.5"><?= e($it['subtitulo']) ?></span>
                  <?php endif; ?>
                </span>
                <?php if (!empty($it['etiqueta'])): ?>
                  <?= badge($it['etiqueta'], $it['etiqueta_color'] ?? 'slate') ?>
                <?php endif; ?>
                <span class="text-slate-300 group-hover:text-blue-600 group-hover:translate-x-0.5 transition shrink-0"><?= icon('arrow-right', 'w-4 h-4') ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php layout_end(); ?>
