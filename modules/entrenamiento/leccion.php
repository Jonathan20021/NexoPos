<?php
/**
 * Reproductor de una lección, paso a paso.
 *
 * El avance se recorre con `?paso=N` (navegación de servidor) en vez de un
 * carrusel de JavaScript: cada paso queda en el historial del navegador, se
 * puede enlazar y compartir, funciona sin JavaScript y —lo que importa— el
 * avance se registra sin depender de que el navegador consiga avisar.
 *
 * `?vista=todo` muestra la lección entera, para leerla de corrido o imprimirla.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$rk = (string) get('ruta');
$lk = (string) get('leccion');
$info = ent_leccion($rk, $lk);
if (!$info) {
    flash('warning', 'Esa lección no existe o no está disponible para tu rol.');
    redirect('modules/entrenamiento/index.php');
}

$ruta    = $info['ruta'];
$lec     = $info['leccion'];
$pasos   = $lec['pasos'] ?? [];
$total   = max(1, count($pasos));
$todo    = get('vista') === 'todo';
$paso    = max(1, min($total, (int) get('paso', 1)));
$estado  = ent_estado_leccion($info['clave']);
$urlBase = url('modules/entrenamiento/leccion.php') . '?ruta=' . rawurlencode($rk) . '&leccion=' . rawurlencode($lk);

// Registrar por dónde va. En la vista completa se registra el último paso: quien
// lee de corrido ha visto la lección entera.
ent_registrar_paso($rk, $lk, $todo ? $total : $paso, $total);

/**
 * Dibuja un paso. El contenido admite: texto, lista de puntos, tabla de
 * conceptos, aviso (lo que puede salir mal), consejo y enlace a la pantalla real.
 */
function ent_paso_html(array $p, int $n, bool $compacto = false): string
{
    $h = '<div class="flex items-start gap-4">'
       . '<span class="shrink-0 w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold text-sm">' . $n . '</span>'
       . '<div class="min-w-0 flex-1">'
       . '<h3 class="text-lg font-extrabold text-slate-800 leading-snug">' . e($p['t'] ?? '') . '</h3>';

    if (!empty($p['d'])) {
        $h .= '<p class="text-[15px] text-slate-600 mt-2 leading-relaxed">' . e($p['d']) . '</p>';
    }

    if (!empty($p['lista'])) {
        $h .= '<ul class="mt-3 space-y-2">';
        foreach ($p['lista'] as $li) {
            $h .= '<li class="flex items-start gap-2.5 text-[15px] text-slate-600 leading-relaxed">'
                . '<span class="shrink-0 w-1.5 h-1.5 rounded-full bg-blue-500 mt-2"></span>'
                . '<span>' . e($li) . '</span></li>';
        }
        $h .= '</ul>';
    }

    if (!empty($p['campos'])) {
        $h .= '<div class="mt-3.5 rounded-xl border border-slate-200 overflow-hidden divide-y divide-slate-100">';
        foreach ($p['campos'] as $k => $v) {
            $h .= '<div class="grid grid-cols-1 sm:grid-cols-3 gap-1 sm:gap-3 px-4 py-3 bg-white">'
                . '<div class="text-[13px] font-bold text-slate-700 sm:col-span-1">' . e($k) . '</div>'
                . '<div class="text-[14px] text-slate-600 sm:col-span-2 leading-relaxed">' . e($v) . '</div>'
                . '</div>';
        }
        $h .= '</div>';
    }

    if (!empty($p['aviso'])) {
        $h .= '<div class="mt-3.5 rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 flex items-start gap-3">'
            . '<span class="text-amber-600 shrink-0 mt-0.5">' . icon('alert', 'w-[18px] h-[18px]') . '</span>'
            . '<p class="text-[14px] text-amber-900 leading-relaxed"><strong class="font-bold">Cuidado.</strong> ' . e($p['aviso']) . '</p>'
            . '</div>';
    }

    if (!empty($p['tip'])) {
        $h .= '<div class="mt-3 rounded-xl border border-sky-200 bg-sky-50/70 p-3.5 flex items-start gap-3">'
            . '<span class="text-sky-600 shrink-0 mt-0.5">' . icon('target', 'w-[18px] h-[18px]') . '</span>'
            . '<p class="text-[14px] text-sky-900 leading-relaxed"><strong class="font-bold">Consejo.</strong> ' . e($p['tip']) . '</p>'
            . '</div>';
    }

    if (!empty($p['ruta']) && !$compacto) {
        $h .= '<a href="' . e(url($p['ruta'])) . '" target="_blank" rel="noopener"'
            . ' class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">'
            . icon('arrow-right', 'w-4 h-4') . ' Abrir esta pantalla en otra pestaña</a>';
    }

    return $h . '</div></div>';
}

$acciones = '<a href="' . e(url('modules/entrenamiento/ruta.php')) . '?ruta=' . e(rawurlencode($rk)) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' ' . e($ruta['titulo']) . '</a>'
          . '<a href="' . e($urlBase) . ($todo ? '' : '&vista=todo') . '" class="btn btn-ghost">'
          . icon($todo ? 'list' : 'file', 'w-4 h-4') . ' ' . ($todo ? 'Ver paso a paso' : 'Ver lección completa') . '</a>';

layout_start($lec['titulo'], $ruta['titulo'] . ' · Lección ' . $info['indice'] . ' de ' . $info['total'] . ' · ' . (int) ($lec['minutos'] ?? 5) . ' min', $acciones);
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

  <div class="lg:col-span-2 flex flex-col gap-5">

    <!-- Barra de pasos -->
    <?php if (!$todo): ?>
      <div class="card p-4 no-print">
        <div class="flex items-center justify-between text-xs mb-2">
          <span class="font-semibold text-slate-600">Paso <?= $paso ?> de <?= $total ?></span>
          <span class="font-bold text-slate-400"><?= (int) round($paso / $total * 100) ?>%</span>
        </div>
        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full bg-blue-600 transition-all duration-300" style="width:<?= round($paso / $total * 100, 2) ?>%"></div>
        </div>
        <div class="flex flex-wrap gap-1.5 mt-3">
          <?php for ($i = 1; $i <= $total; $i++): ?>
            <a href="<?= e($urlBase) ?>&paso=<?= $i ?>"
               class="w-7 h-7 rounded-lg text-[11px] font-bold flex items-center justify-center transition
                 <?= $i === $paso ? 'bg-blue-600 text-white' : ($i < $paso ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-400 hover:bg-slate-200') ?>"
               title="Paso <?= $i ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Objetivos: solo al empezar o en la vista completa -->
    <?php if (!empty($lec['objetivos']) && ($todo || $paso === 1)): ?>
      <div class="card p-5 bg-slate-50/70">
        <h2 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('target', 'w-4 h-4 text-blue-600') ?> Al terminar esta lección sabrás</h2>
        <ul class="mt-3 space-y-2">
          <?php foreach ($lec['objetivos'] as $o): ?>
            <li class="flex items-start gap-2.5 text-[15px] text-slate-600 leading-relaxed">
              <span class="text-emerald-500 shrink-0 mt-0.5"><?= icon('check', 'w-4 h-4') ?></span>
              <span><?= e($o) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- Contenido -->
    <?php if ($todo): ?>
      <div class="card divide-y divide-slate-100">
        <?php foreach ($pasos as $i => $p): ?>
          <div class="p-5 sm:p-6"><?= ent_paso_html($p, $i + 1) ?></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="card p-5 sm:p-6">
        <?= ent_paso_html($pasos[$paso - 1] ?? [], $paso) ?>
      </div>

      <!-- Navegación -->
      <div class="flex items-center gap-3 no-print">
        <?php if ($paso > 1): ?>
          <a href="<?= e($urlBase) ?>&paso=<?= $paso - 1 ?>" class="btn btn-ghost"><?= icon('arrow-left', 'w-4 h-4') ?> Anterior</a>
        <?php endif; ?>

        <?php if ($paso < $total): ?>
          <a href="<?= e($urlBase) ?>&paso=<?= $paso + 1 ?>" class="btn btn-primary ml-auto">Siguiente paso <?= icon('arrow-right', 'w-4 h-4') ?></a>
        <?php else: ?>
          <form method="post" action="<?= e(url('modules/entrenamiento/progreso.php')) ?>" class="ml-auto">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="completar">
            <input type="hidden" name="ruta" value="<?= e($rk) ?>">
            <input type="hidden" name="leccion" value="<?= e($lk) ?>">
            <button class="btn <?= $estado === 'completada' ? 'btn-soft' : 'btn-success' ?>">
              <?= icon('check', 'w-4 h-4') ?>
              <?= $estado === 'completada' ? 'Marcar de nuevo y continuar' : 'Marcar como completada' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Errores frecuentes -->
    <?php if (!empty($lec['errores']) && ($todo || $paso === $total)): ?>
      <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
          <span class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-[18px] h-[18px]') ?></span>
          <div>
            <h2 class="font-bold text-slate-800 leading-tight">Cuando algo no sale</h2>
            <p class="text-xs text-slate-400">Los tropiezos más frecuentes de esta pantalla y cómo se resuelven.</p>
          </div>
        </div>
        <div class="divide-y divide-slate-100">
          <?php foreach ($lec['errores'] as $err): ?>
            <div class="px-5 py-4">
              <p class="font-bold text-slate-800 text-[15px]"><?= e($err['sintoma']) ?></p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2.5">
                <div class="rounded-xl bg-slate-50 p-3">
                  <p class="text-[11px] uppercase tracking-wider font-bold text-slate-400">Por qué pasa</p>
                  <p class="text-[14px] text-slate-600 mt-1 leading-relaxed"><?= e($err['causa']) ?></p>
                </div>
                <div class="rounded-xl bg-emerald-50/70 p-3">
                  <p class="text-[11px] uppercase tracking-wider font-bold text-emerald-700">Qué hacer</p>
                  <p class="text-[14px] text-slate-700 mt-1 leading-relaxed"><?= e($err['solucion']) ?></p>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Autoevaluación (no se guarda: es práctica) -->
    <?php if (!empty($lec['quiz']) && ($todo || $paso === $total)): ?>
      <div class="card overflow-hidden no-print" x-data="{ elegidas: {} }">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
          <span class="w-9 h-9 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center shrink-0"><?= icon('check', 'w-[18px] h-[18px]') ?></span>
          <div>
            <h2 class="font-bold text-slate-800 leading-tight">Compruébalo tú mismo</h2>
            <p class="text-xs text-slate-400">Práctica, no examen: esto no se guarda ni cuenta para el certificado.</p>
          </div>
        </div>
        <div class="divide-y divide-slate-100">
          <?php foreach ($lec['quiz'] as $qi => $q): ?>
            <div class="px-5 py-4">
              <p class="font-semibold text-slate-800 text-[15px] leading-snug"><?= e($q['p']) ?></p>
              <div class="mt-3 space-y-2">
                <?php foreach ($q['ops'] as $oi => $op): ?>
                  <button type="button" @click="elegidas[<?= $qi ?>] = <?= $oi ?>"
                          class="w-full text-left px-4 py-2.5 rounded-xl border text-[14px] transition flex items-start gap-2.5"
                          :class="elegidas[<?= $qi ?>] === undefined ? 'border-slate-200 hover:border-blue-300 hover:bg-blue-50/40 text-slate-600'
                                  : (<?= $oi ?> === <?= (int) $q['ok'] ?> ? 'border-emerald-300 bg-emerald-50 text-emerald-900 font-semibold'
                                  : (elegidas[<?= $qi ?>] === <?= $oi ?> ? 'border-rose-300 bg-rose-50 text-rose-900' : 'border-slate-200 text-slate-400'))">
                    <span class="shrink-0 w-5 h-5 rounded-md bg-white border border-slate-200 text-[11px] font-bold flex items-center justify-center mt-0.5"><?= chr(65 + $oi) ?></span>
                    <span><?= e($op) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <?php if (!empty($q['exp'])): ?>
                <div x-show="elegidas[<?= $qi ?>] !== undefined" x-transition style="display:none"
                     class="mt-3 rounded-xl bg-slate-50 border border-slate-200 p-3.5">
                  <p class="text-[13px] text-slate-600 leading-relaxed"><strong class="text-slate-800">Por qué:</strong> <?= e($q['exp']) ?></p>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Vista completa: marcar y seguir -->
    <?php if ($todo): ?>
      <form method="post" action="<?= e(url('modules/entrenamiento/progreso.php')) ?>" class="no-print">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="completar">
        <input type="hidden" name="ruta" value="<?= e($rk) ?>">
        <input type="hidden" name="leccion" value="<?= e($lk) ?>">
        <button class="btn <?= $estado === 'completada' ? 'btn-soft' : 'btn-success' ?> w-full justify-center">
          <?= icon('check', 'w-4 h-4') ?>
          <?= $estado === 'completada' ? 'Ya la completaste — marcar de nuevo' : 'Marcar como completada' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- ============ Columna lateral ============ -->
  <div class="flex flex-col gap-5 no-print">

    <div class="card p-5">
      <div class="flex items-center gap-2.5">
        <?= $estado === 'completada'
            ? '<span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">' . icon('check', 'w-4 h-4') . '</span>'
            : '<span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">' . icon('clock', 'w-4 h-4') . '</span>' ?>
        <div>
          <p class="text-sm font-bold text-slate-800"><?= $estado === 'completada' ? 'Lección completada' : ($estado === 'en_curso' ? 'En curso' : 'Sin empezar') ?></p>
          <p class="text-xs text-slate-400"><?= (int) ($lec['minutos'] ?? 5) ?> minutos · <?= $total ?> pasos</p>
        </div>
      </div>

      <?php if (!empty($lec['pantalla'])): ?>
        <a href="<?= e(url($lec['pantalla'])) ?>" target="_blank" rel="noopener" class="btn btn-soft w-full mt-4 justify-center">
          <?= icon('arrow-right', 'w-4 h-4') ?> Abrir la pantalla real
        </a>
        <p class="text-[11px] text-slate-400 mt-2 text-center leading-relaxed">Se abre en otra pestaña para que puedas practicar sin perder la lección.</p>
      <?php endif; ?>
    </div>

    <!-- Índice de la ruta -->
    <div class="card overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-100">
        <p class="text-[11px] uppercase tracking-wider font-bold text-slate-400">En esta ruta</p>
        <p class="text-sm font-bold text-slate-800 mt-0.5"><?= e($ruta['titulo']) ?></p>
      </div>
      <div class="max-h-[420px] overflow-y-auto">
        <?php $progreso = ent_progreso(); $n = 0; foreach ($ruta['lecciones'] as $k => $l): $n++;
          $st = ent_estado_leccion(ent_clave($rk, $k), $progreso);
          $act = $k === $lk;
        ?>
          <a href="<?= e(url('modules/entrenamiento/leccion.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>&leccion=<?= e(rawurlencode($k)) ?>"
             class="flex items-start gap-2.5 px-4 py-2.5 text-[13px] transition <?= $act ? 'bg-blue-50 border-l-2 border-blue-600' : 'hover:bg-slate-50 border-l-2 border-transparent' ?>">
            <span class="shrink-0 w-5 h-5 rounded-md flex items-center justify-center text-[10px] font-bold mt-0.5
              <?= $st === 'completada' ? 'bg-emerald-500 text-white' : ($act ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400') ?>">
              <?= $st === 'completada' ? '✓' : $n ?>
            </span>
            <span class="<?= $act ? 'font-bold text-blue-800' : 'text-slate-600' ?> leading-snug"><?= e($l['titulo']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Siguiente lección -->
    <?php if ($info['siguiente']): $sig = $ruta['lecciones'][$info['siguiente']]; ?>
      <a href="<?= e(url('modules/entrenamiento/leccion.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>&leccion=<?= e(rawurlencode($info['siguiente'])) ?>"
         class="card p-4 hover:shadow-soft hover:border-blue-200 transition group">
        <p class="text-[11px] uppercase tracking-wider font-bold text-slate-400">Siguiente lección</p>
        <p class="font-bold text-slate-800 mt-1 leading-snug group-hover:text-blue-700 transition-colors"><?= e($sig['titulo']) ?></p>
        <p class="text-xs text-slate-400 mt-1"><?= (int) ($sig['minutos'] ?? 5) ?> min</p>
      </a>
    <?php endif; ?>
  </div>
</div>

<?php layout_end(); ?>
