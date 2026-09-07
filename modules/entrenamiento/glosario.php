<?php
/**
 * Glosario del sistema y del negocio dominicano.
 *
 * Está separado del temario porque se consulta, no se estudia: alguien que lleva
 * seis meses usando el sistema sigue necesitando recordar qué reporta el 607.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$q         = trim((string) get('q'));
$glosario  = ent_glosario();
$total     = 0;
$filtrado  = [];

foreach ($glosario as $cat => $terminos) {
    $sub = [];
    foreach ($terminos as $t => $d) {
        if ($q === '' || str_contains(mb_strtolower($t . ' ' . $d), mb_strtolower($q))) {
            $sub[$t] = $d;
        }
    }
    if ($sub) { $filtrado[$cat] = $sub; $total += count($sub); }
}
$totalGeneral = array_sum(array_map('count', $glosario));

$iconos = [
    'Fiscal y DGII'     => ['shield', 'blue'],
    'Inventario'        => ['box', 'amber'],
    'Ventas y clientes' => ['cart', 'emerald'],
    'Finanzas'          => ['dollar', 'violet'],
    'Recursos Humanos'  => ['id', 'rose'],
    'Sistema'           => ['settings', 'slate'],
];

layout_start('Glosario', $totalGeneral . ' términos del sistema y del negocio',
    '<a href="' . e(url('modules/entrenamiento/index.php')) . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Centro de Entrenamiento</a>');
?>

<div class="card p-4 mb-5 no-print">
  <?= search_box('Buscar un término: NCF, kardex, cesantía, arqueo…') ?>
  <?php if ($q !== ''): ?>
    <p class="text-sm text-slate-500 mt-3">
      <?= $total ?> resultado<?= $total === 1 ? '' : 's' ?> para «<strong class="text-slate-700"><?= e($q) ?></strong>».
      <a href="<?= e(url('modules/entrenamiento/glosario.php')) ?>" class="text-blue-600 font-semibold hover:underline">Ver todos</a>
    </p>
  <?php endif; ?>
</div>

<?php if (!$filtrado): ?>
  <?= empty_state('Sin resultados', 'Ningún término coincide con «' . $q . '». Prueba con una palabra más corta.', 'search',
      '<a href="' . e(url('modules/entrenamiento/glosario.php')) . '" class="btn btn-primary">Ver todo el glosario</a>') ?>
<?php else: ?>

  <?php if ($q === ''): ?>
    <div class="flex flex-wrap gap-2 mb-5 no-print">
      <?php foreach ($filtrado as $cat => $_): [$ico, $col] = $iconos[$cat] ?? ['book', 'slate']; ?>
        <a href="#<?= e(md5($cat)) ?>" class="inline-flex items-center gap-2 px-3.5 h-9 rounded-xl bg-white border border-slate-200 text-[13px] font-semibold text-slate-600 hover:border-blue-300 hover:text-blue-700 transition">
          <?= icon($ico, 'w-4 h-4 text-slate-400') ?> <?= e($cat) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="flex flex-col gap-5">
    <?php foreach ($filtrado as $cat => $terminos):
      [$ico, $col] = $iconos[$cat] ?? ['book', 'slate'];
      $fondos = ['blue' => 'bg-blue-50 text-blue-600', 'amber' => 'bg-amber-50 text-amber-600',
                 'emerald' => 'bg-emerald-50 text-emerald-600', 'violet' => 'bg-violet-50 text-violet-600',
                 'rose' => 'bg-rose-50 text-rose-600', 'slate' => 'bg-slate-100 text-slate-600'];
    ?>
      <section id="<?= e(md5($cat)) ?>" class="card overflow-hidden print-break">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
          <span class="w-9 h-9 rounded-xl <?= $fondos[$col] ?? $fondos['slate'] ?> flex items-center justify-center shrink-0"><?= icon($ico, 'w-[18px] h-[18px]') ?></span>
          <div>
            <h2 class="font-bold text-slate-800 leading-tight"><?= e($cat) ?></h2>
            <p class="text-xs text-slate-400"><?= count($terminos) ?> términos</p>
          </div>
        </div>
        <dl class="divide-y divide-slate-100">
          <?php foreach ($terminos as $t => $d): ?>
            <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-4 gap-1.5 sm:gap-4">
              <dt class="font-bold text-slate-800 text-[15px] sm:col-span-1"><?= e($t) ?></dt>
              <dd class="text-[14px] text-slate-600 leading-relaxed sm:col-span-3"><?= e($d) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php layout_end(); ?>
