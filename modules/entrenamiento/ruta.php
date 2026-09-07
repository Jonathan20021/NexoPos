<?php
/** Detalle de una ruta de aprendizaje: sus lecciones y su evaluación. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$rk   = (string) get('ruta');
$ruta = ent_ruta($rk);
if (!$ruta) {
    flash('warning', 'Esa ruta de entrenamiento no existe o no está disponible para tu rol.');
    redirect('modules/entrenamiento/index.php');
}

$progreso = ent_progreso();
$a        = ent_avance_ruta($ruta, $progreso, $rk);
$quiz     = ent_quiz_ruta($rk);
$mejor    = ent_mejor_evaluacion($rk);
$cert     = ent_certificado_emitido($rk);
$puedeEv  = ent_puede_evaluar($rk);

$fondos = [
  'blue' => 'bg-blue-50 text-blue-600', 'emerald' => 'bg-emerald-50 text-emerald-600',
  'amber' => 'bg-amber-50 text-amber-600', 'indigo' => 'bg-indigo-50 text-indigo-600',
  'cyan' => 'bg-cyan-50 text-cyan-600', 'violet' => 'bg-violet-50 text-violet-600',
  'rose' => 'bg-rose-50 text-rose-600', 'pink' => 'bg-pink-50 text-pink-600',
  'slate' => 'bg-slate-100 text-slate-600',
];
$barras = [
  'blue' => 'bg-blue-500', 'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500',
  'indigo' => 'bg-indigo-500', 'cyan' => 'bg-cyan-500', 'violet' => 'bg-violet-500',
  'rose' => 'bg-rose-500', 'pink' => 'bg-pink-500', 'slate' => 'bg-slate-500',
];
$fondo = $fondos[$ruta['color']] ?? $fondos['blue'];
$barra = $barras[$ruta['color']] ?? $barras['blue'];

$acciones = '<a href="' . e(url('modules/entrenamiento/index.php')) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Todas las rutas</a>';

layout_start($ruta['titulo'], $ruta['descripcion'], $acciones);
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

  <!-- ============ Columna principal: las lecciones ============ -->
  <div class="lg:col-span-2 flex flex-col gap-5">

    <div class="card p-5">
      <div class="flex items-center justify-between text-sm mb-2">
        <span class="font-semibold text-slate-700"><?= $a['completadas'] ?> de <?= $a['total'] ?> lecciones completadas</span>
        <span class="font-extrabold <?= $a['pct'] === 100 ? 'text-emerald-600' : 'text-slate-500' ?>"><?= $a['pct'] ?>%</span>
      </div>
      <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
        <div class="h-full rounded-full <?= $a['pct'] === 100 ? 'bg-emerald-500' : $barra ?> transition-all duration-500" style="width:<?= $a['pct'] ?>%"></div>
      </div>
      <?php if ($a['minutos_restantes'] > 0): ?>
        <p class="text-xs text-slate-400 mt-2.5">Quedan unos <?= $a['minutos_restantes'] ?> minutos para terminar esta ruta.</p>
      <?php endif; ?>
    </div>

    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <span class="w-9 h-9 rounded-xl <?= $fondo ?> flex items-center justify-center shrink-0"><?= icon($ruta['icono'], 'w-[18px] h-[18px]') ?></span>
        <div>
          <h2 class="font-bold text-slate-800 leading-tight">Lecciones</h2>
          <p class="text-xs text-slate-400"><?= e($ruta['nivel']) ?> · <?= $a['minutos'] ?> minutos en total</p>
        </div>
      </div>

      <ol class="divide-y divide-slate-100">
        <?php $n = 0; foreach ($ruta['lecciones'] as $lk => $lec):
          $n++;
          $estado = ent_estado_leccion(ent_clave($rk, $lk), $progreso);
          $p      = $progreso[ent_clave($rk, $lk)] ?? null;
          $href   = url('modules/entrenamiento/leccion.php') . '?ruta=' . rawurlencode($rk) . '&leccion=' . rawurlencode($lk);
        ?>
          <li>
            <a href="<?= e($href) ?>" class="flex items-start gap-4 px-5 py-4 hover:bg-slate-50 transition group">
              <span class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold mt-0.5
                <?= $estado === 'completada' ? 'bg-emerald-500 text-white'
                    : ($estado === 'en_curso' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400') ?>">
                <?= $estado === 'completada' ? icon('check', 'w-4 h-4') : $n ?>
              </span>
              <div class="min-w-0 flex-1">
                <h3 class="font-semibold text-slate-800 leading-snug group-hover:text-blue-700 transition-colors"><?= e($lec['titulo']) ?></h3>
                <p class="text-[13px] text-slate-500 mt-0.5 leading-relaxed"><?= e($lec['resumen']) ?></p>
                <div class="flex flex-wrap items-center gap-2 mt-2">
                  <span class="text-[11px] text-slate-400 font-medium inline-flex items-center gap-1"><?= icon('clock', 'w-3 h-3') ?> <?= (int) ($lec['minutos'] ?? 5) ?> min</span>
                  <span class="text-[11px] text-slate-300">·</span>
                  <span class="text-[11px] text-slate-400 font-medium"><?= count($lec['pasos'] ?? []) ?> pasos</span>
                  <?php if (!empty($lec['quiz'])): ?>
                    <span class="text-[11px] text-slate-300">·</span>
                    <span class="text-[11px] text-slate-400 font-medium"><?= count($lec['quiz']) ?> preguntas</span>
                  <?php endif; ?>
                  <?php if ($estado === 'en_curso' && $p): ?>
                    <?= badge('En curso · paso ' . (int) $p['paso_actual'] . ' de ' . (int) $p['pasos_total'], 'blue') ?>
                  <?php elseif ($estado === 'completada'): ?>
                    <?= badge('Completada', 'emerald') ?>
                  <?php endif; ?>
                </div>
              </div>
              <span class="text-slate-300 group-hover:text-blue-600 group-hover:translate-x-0.5 transition-all shrink-0 mt-1"><?= icon('chevron-right', 'w-4 h-4') ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>

  <!-- ============ Columna lateral ============ -->
  <div class="flex flex-col gap-5">

    <div class="card p-5">
      <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('users', 'w-4 h-4 text-slate-400') ?> ¿Para quién es?</h3>
      <p class="text-sm text-slate-500 mt-2 leading-relaxed"><?= e($ruta['para_quien']) ?></p>
    </div>

    <!-- Evaluación y certificado -->
    <div class="card p-5">
      <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('shield', 'w-4 h-4 text-slate-400') ?> Evaluación</h3>

      <?php if (!$quiz): ?>
        <p class="text-sm text-slate-500 mt-2">Esta ruta no tiene evaluación.</p>

      <?php else: ?>
        <p class="text-sm text-slate-500 mt-2 leading-relaxed">
          <?= count($quiz) ?> preguntas sobre las lecciones de esta ruta.
          Se aprueba con <?= ENT_APROBACION ?>% o más, y puedes repetirla las veces que quieras.
        </p>

        <?php if ($mejor): ?>
          <div class="mt-4 rounded-xl border <?= (int) $mejor['aprobado'] === 1 ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50/60' ?> p-3.5">
            <p class="text-[11px] uppercase tracking-wider font-semibold <?= (int) $mejor['aprobado'] === 1 ? 'text-emerald-700' : 'text-amber-700' ?>">Tu mejor intento</p>
            <p class="text-2xl font-extrabold text-slate-800 mt-0.5"><?= number_format((float) $mejor['porcentaje'], 0) ?>%</p>
            <p class="text-xs text-slate-500"><?= (int) $mejor['puntaje'] ?> de <?= (int) $mejor['total'] ?> correctas · <?= e(fechaCorta($mejor['created_at'])) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($puedeEv): ?>
          <a href="<?= e(url('modules/entrenamiento/evaluacion.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>"
             class="btn btn-primary w-full mt-4 justify-center">
            <?= icon('check', 'w-4 h-4') ?> <?= $mejor ? 'Repetir la evaluación' : 'Presentar la evaluación' ?>
          </a>
        <?php else: ?>
          <div class="mt-4 rounded-xl bg-slate-50 border border-slate-200 p-3.5 flex items-start gap-2.5">
            <span class="text-slate-400 shrink-0 mt-0.5"><?= icon('lock', 'w-4 h-4') ?></span>
            <p class="text-xs text-slate-500 leading-relaxed">
              Termina las <?= $a['total'] - $a['completadas'] ?> lección<?= ($a['total'] - $a['completadas']) === 1 ? '' : 'es' ?>
              que te faltan para presentar la evaluación.
            </p>
          </div>
        <?php endif; ?>

        <?php if ($cert): ?>
          <a href="<?= e(url('modules/entrenamiento/certificado.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>"
             class="btn btn-soft w-full mt-2.5 justify-center">
            <?= icon('file', 'w-4 h-4') ?> Ver mi certificado
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if ($a['completadas'] > 0 && ent_disponible()): ?>
      <form method="post" action="<?= e(url('modules/entrenamiento/progreso.php')) ?>"
            onsubmit="return confirm('¿Reiniciar tu avance en «<?= e($ruta['titulo']) ?>»? Se borran las lecciones completadas y las evaluaciones de esta ruta.')">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="reiniciar_ruta">
        <input type="hidden" name="ruta" value="<?= e($rk) ?>">
        <button class="btn btn-ghost w-full justify-center text-slate-500">
          <?= icon('undo', 'w-4 h-4') ?> Reiniciar mi avance en esta ruta
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php layout_end(); ?>
