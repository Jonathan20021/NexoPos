<?php
/**
 * Evaluación de una ruta.
 *
 * Las preguntas salen de las lecciones VISIBLES de la ruta: se examina de lo que
 * se pudo estudiar. Y solo se presenta con la ruta terminada — evaluar sin haber
 * leído convierte el certificado en una lotería de cuatro opciones.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$rk   = (string) (isPost() ? post('ruta') : get('ruta'));
$ruta = ent_ruta($rk);
if (!$ruta) {
    flash('warning', 'Esa ruta de entrenamiento no está disponible para tu rol.');
    redirect('modules/entrenamiento/index.php');
}

$preguntas = ent_quiz_ruta($rk);
if (!$preguntas) {
    flash('info', 'Esta ruta no tiene evaluación.');
    redirect('modules/entrenamiento/ruta.php?ruta=' . rawurlencode($rk));
}
if (!ent_puede_evaluar($rk)) {
    flash('warning', 'Termina todas las lecciones de la ruta antes de presentar la evaluación.');
    redirect('modules/entrenamiento/ruta.php?ruta=' . rawurlencode($rk));
}

$resultado = null;
if (isPost()) {
    verify_csrf();
    $resp = (array) ($_POST['r'] ?? []);
    // Se normaliza a [índice => opción] con enteros: lo que llega del navegador
    // no decide nada, solo señala una posición del temario que vive en servidor.
    $limpias = [];
    foreach ($resp as $i => $v) {
        if ($v === '' || !is_scalar($v)) continue;
        $limpias[(int) $i] = (int) $v;
    }
    $resultado = ent_evaluar($rk, $limpias, (int) post('segundos', 0));
}

$acciones = '<a href="' . e(url('modules/entrenamiento/ruta.php')) . '?ruta=' . e(rawurlencode($rk)) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Volver a la ruta</a>';

layout_start(
    'Evaluación · ' . $ruta['titulo'],
    count($preguntas) . ' preguntas · se aprueba con ' . ENT_APROBACION . '% o más',
    $acciones
);
?>

<?php if ($resultado === null): /* ============ EXAMEN ============ */ ?>

  <div class="card p-5 mb-5 flex flex-col sm:flex-row items-start gap-4 bg-slate-50/70">
    <span class="w-11 h-11 rounded-xl bg-white border border-slate-200 text-violet-600 flex items-center justify-center shrink-0"><?= icon('shield', 'w-5 h-5') ?></span>
    <div class="flex-1">
      <h3 class="font-bold text-slate-800">Antes de empezar</h3>
      <ul class="text-sm text-slate-500 mt-2 space-y-1.5">
        <li class="flex items-start gap-2"><span class="text-slate-300 mt-1.5">•</span><span>No hay tiempo límite y puedes repetirla las veces que quieras.</span></li>
        <li class="flex items-start gap-2"><span class="text-slate-300 mt-1.5">•</span><span>Se guarda cada intento, pero el certificado usa el mejor.</span></li>
        <li class="flex items-start gap-2"><span class="text-slate-300 mt-1.5">•</span><span>Al terminar verás la explicación de cada respuesta, aciertes o no.</span></li>
      </ul>
    </div>
  </div>

  <form method="post" x-data="{ inicio: Date.now(), respondidas: 0, total: <?= count($preguntas) ?> }"
        @change="respondidas = new Set([...$el.querySelectorAll('input[type=radio]:checked')].map(i => i.name)).size"
        @submit="$refs.seg.value = Math.round((Date.now() - inicio) / 1000)">
    <?= csrf_field() ?>
    <input type="hidden" name="ruta" value="<?= e($rk) ?>">
    <input type="hidden" name="segundos" x-ref="seg" value="0">

    <div class="flex flex-col gap-4">
      <?php foreach ($preguntas as $i => $q): ?>
        <div class="card p-5">
          <div class="flex items-start gap-3">
            <span class="shrink-0 w-8 h-8 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center font-bold text-sm"><?= $i + 1 ?></span>
            <div class="min-w-0 flex-1">
              <p class="font-bold text-slate-800 text-[15px] leading-snug"><?= e($q['p']) ?></p>
              <p class="text-[11px] text-slate-400 mt-1 uppercase tracking-wide font-semibold"><?= e($q['leccion_titulo']) ?></p>

              <div class="mt-3.5 space-y-2">
                <?php foreach ($q['ops'] as $oi => $op): ?>
                  <label class="flex items-start gap-3 px-4 py-3 rounded-xl border border-slate-200 cursor-pointer hover:border-blue-300 hover:bg-blue-50/40 transition has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                    <input type="radio" name="r[<?= $i ?>]" value="<?= $oi ?>" class="mt-0.5 shrink-0 w-4 h-4 accent-blue-600" required>
                    <span class="text-[14px] text-slate-700 leading-relaxed"><?= e($op) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card p-4 mt-5 sticky bottom-4 shadow-pop flex flex-col sm:flex-row items-center gap-3">
      <p class="text-sm text-slate-500 flex-1">
        Respondidas <strong class="text-slate-800" x-text="respondidas">0</strong> de <?= count($preguntas) ?>
      </p>
      <button class="btn btn-primary w-full sm:w-auto justify-center"><?= icon('check', 'w-4 h-4') ?> Enviar la evaluación</button>
    </div>
  </form>

<?php else: /* ============ RESULTADO ============ */
  $ok = $resultado['aprobado'];
  $pct = (int) round($resultado['pct']);
?>

  <div class="card p-6 mb-5 <?= $ok ? 'bg-gradient-to-br from-emerald-500 to-emerald-700' : 'bg-gradient-to-br from-amber-500 to-orange-600' ?> border-0 text-white">
    <div class="flex flex-col sm:flex-row items-center gap-6 text-center sm:text-left">
      <span class="w-16 h-16 rounded-2xl bg-white/20 flex items-center justify-center shrink-0">
        <?= icon($ok ? 'shield' : 'undo', 'w-8 h-8') ?>
      </span>
      <div class="flex-1">
        <h2 class="text-2xl font-extrabold"><?= $ok ? '¡Aprobada!' : 'Casi. Repásala y vuelve' ?></h2>
        <p class="text-white/85 mt-1">
          <?= $resultado['puntaje'] ?> de <?= $resultado['total'] ?> correctas · <?= $pct ?>%
          <?= $ok ? '' : ' · hacen falta ' . ENT_APROBACION . '%' ?>
        </p>
      </div>
      <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
        <?php if ($ok && ent_certificado_emitido($rk)): ?>
          <a href="<?= e(url('modules/entrenamiento/certificado.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>"
             class="inline-flex items-center justify-center gap-2 bg-white text-emerald-700 font-semibold text-sm px-4 h-10 rounded-xl hover:bg-emerald-50 transition">
            <?= icon('file', 'w-4 h-4') ?> Ver certificado
          </a>
        <?php endif; ?>
        <a href="<?= e(url('modules/entrenamiento/evaluacion.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>"
           class="inline-flex items-center justify-center gap-2 bg-white/20 border border-white/30 text-white font-semibold text-sm px-4 h-10 rounded-xl hover:bg-white/30 transition">
          <?= icon('undo', 'w-4 h-4') ?> Repetir
        </a>
      </div>
    </div>
  </div>

  <?php
    // Las lecciones donde se falló: lo útil no es el número, es qué repasar.
    $fallos = [];
    foreach ($resultado['detalle'] as $d) {
        if (!$d['acierto']) $fallos[$d['leccion']] = $d['leccion_titulo'];
    }
  ?>
  <?php if ($fallos): ?>
    <div class="card p-5 mb-5">
      <h3 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('target', 'w-4 h-4 text-amber-500') ?> Qué te conviene repasar</h3>
      <div class="flex flex-wrap gap-2 mt-3">
        <?php foreach ($fallos as $lk => $titulo): ?>
          <a href="<?= e(url('modules/entrenamiento/leccion.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>&leccion=<?= e(rawurlencode($lk)) ?>&vista=todo"
             class="inline-flex items-center gap-2 px-3.5 h-9 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[13px] font-semibold hover:bg-amber-100 transition">
            <?= icon('arrow-right', 'w-3.5 h-3.5') ?> <?= e($titulo) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="flex flex-col gap-4">
    <?php foreach ($resultado['detalle'] as $i => $d): ?>
      <div class="card p-5">
        <div class="flex items-start gap-3">
          <span class="shrink-0 w-8 h-8 rounded-xl flex items-center justify-center <?= $d['acierto'] ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?>">
            <?= icon($d['acierto'] ? 'check' : 'x', 'w-4 h-4') ?>
          </span>
          <div class="min-w-0 flex-1">
            <p class="font-bold text-slate-800 text-[15px] leading-snug"><?= e($d['pregunta']) ?></p>

            <div class="mt-3 space-y-2">
              <?php foreach ($d['opciones'] as $oi => $op):
                $esCorrecta = $oi === $d['correcta'];
                $esElegida  = $oi === $d['elegida'];
                $clase = $esCorrecta ? 'border-emerald-300 bg-emerald-50 text-emerald-900 font-semibold'
                       : ($esElegida ? 'border-rose-300 bg-rose-50 text-rose-900' : 'border-slate-200 text-slate-400');
              ?>
                <div class="px-4 py-2.5 rounded-xl border text-[14px] flex items-start gap-2.5 <?= $clase ?>">
                  <span class="shrink-0 w-5 h-5 rounded-md bg-white border border-slate-200 text-[11px] font-bold flex items-center justify-center mt-0.5"><?= chr(65 + $oi) ?></span>
                  <span class="flex-1"><?= e($op) ?></span>
                  <?php if ($esCorrecta): ?><span class="shrink-0 text-[11px] font-bold uppercase tracking-wide">Correcta</span><?php endif; ?>
                  <?php if ($esElegida && !$esCorrecta): ?><span class="shrink-0 text-[11px] font-bold uppercase tracking-wide">Tu respuesta</span><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($d['explicacion']): ?>
              <div class="mt-3 rounded-xl bg-slate-50 border border-slate-200 p-3.5">
                <p class="text-[13px] text-slate-600 leading-relaxed"><strong class="text-slate-800">Por qué:</strong> <?= e($d['explicacion']) ?></p>
              </div>
            <?php endif; ?>

            <a href="<?= e(url('modules/entrenamiento/leccion.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>&leccion=<?= e(rawurlencode($d['leccion'])) ?>&vista=todo"
               class="inline-flex items-center gap-1.5 mt-3 text-[13px] font-semibold text-blue-600 hover:text-blue-700">
              <?= icon('book', 'w-3.5 h-3.5') ?> <?= e($d['leccion_titulo']) ?>
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<?php layout_end(); ?>
