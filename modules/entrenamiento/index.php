<?php
/**
 * Centro de Entrenamiento — el hub.
 *
 * No lleva `require_perm()`: todo el que entra al sistema tiene derecho a que le
 * expliquen lo que puede tocar. El recorte lo hace `ent_catalogo_visible()`, que
 * solo muestra las lecciones de las pantallas que esta persona puede abrir.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$u        = current_user();
$catalogo = ent_catalogo_visible();
$progreso = ent_progreso();
$resumen  = ent_resumen();
$sigue    = ent_siguiente();

$acciones = '<a href="' . e(url('modules/entrenamiento/glosario.php')) . '" class="btn btn-ghost">'
          . icon('book', 'w-4 h-4') . ' Glosario</a>';
if (can('entrenamiento.equipo')) {
    $acciones .= '<a href="' . e(url('modules/entrenamiento/equipo.php')) . '" class="btn btn-ghost">'
               . icon('users', 'w-4 h-4') . ' Avance del equipo</a>';
}

layout_start(
    'Centro de Entrenamiento',
    $resumen['lecciones'] . ' lecciones para tu rol · ' . $resumen['rutas'] . ' rutas de aprendizaje',
    $acciones
);
?>

<?php if (!ent_disponible()): ?>
  <div class="card p-5 mb-6 border-amber-200 bg-amber-50/60 flex items-start gap-4">
    <span class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
    <div>
      <h3 class="font-bold text-slate-800">El avance no se está guardando</h3>
      <p class="text-sm text-slate-600 mt-1">Puedes leer todo el temario con normalidad, pero falta aplicar la migración
        <code class="text-xs bg-white px-1.5 py-0.5 rounded border border-amber-200">database/migracion_entrenamiento_p37.sql</code>
        para que el sistema recuerde por dónde vas.</p>
    </div>
  </div>
<?php endif; ?>

<!-- ============ Encabezado: tu avance ============ -->
<div class="card p-6 mb-6 bg-gradient-to-br from-blue-600 to-indigo-700 border-0 text-white">
  <div class="flex flex-col lg:flex-row lg:items-center gap-6">

    <!-- Anillo de avance -->
    <div class="flex items-center gap-5 shrink-0">
      <?php
        $pct = (int) $resumen['pct'];
        $r = 42; $circ = 2 * M_PI * $r;
        $off = $circ * (1 - $pct / 100);
      ?>
      <div class="relative w-[104px] h-[104px] shrink-0">
        <svg viewBox="0 0 100 100" class="w-full h-full -rotate-90">
          <circle cx="50" cy="50" r="<?= $r ?>" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="9"></circle>
          <circle cx="50" cy="50" r="<?= $r ?>" fill="none" stroke="#fff" stroke-width="9" stroke-linecap="round"
                  stroke-dasharray="<?= round($circ, 2) ?>" stroke-dashoffset="<?= round($off, 2) ?>"></circle>
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
          <span class="text-2xl font-extrabold leading-none"><?= $pct ?>%</span>
          <span class="text-[10px] uppercase tracking-wider text-white/70 mt-0.5">completado</span>
        </div>
      </div>
      <div class="min-w-0">
        <p class="text-sm text-white/70">Hola, <?= e($u['nombre']) ?></p>
        <h2 class="text-xl font-extrabold leading-tight mt-0.5">
          <?= $resumen['completadas'] ?> de <?= $resumen['lecciones'] ?> lecciones
        </h2>
        <p class="text-sm text-white/80 mt-1">
          <?php if ($resumen['minutos_restantes'] > 0): ?>
            Te quedan unos <?= $resumen['minutos_restantes'] ?> minutos de entrenamiento.
          <?php else: ?>
            Has terminado todo el temario de tu rol.
          <?php endif; ?>
        </p>
      </div>
    </div>

    <!-- Continuar -->
    <div class="lg:ml-auto w-full lg:w-auto">
      <?php if ($sigue): $info = ent_leccion($sigue[0], $sigue[1]); ?>
        <div class="bg-white/12 backdrop-blur rounded-2xl p-4 border border-white/15">
          <p class="text-[11px] uppercase tracking-wider text-white/60 font-semibold">
            <?= $resumen['completadas'] > 0 ? 'Continúa donde lo dejaste' : 'Empieza por aquí' ?>
          </p>
          <p class="font-bold mt-1.5 leading-snug"><?= e($info['leccion']['titulo']) ?></p>
          <p class="text-xs text-white/70 mt-0.5"><?= e($info['ruta']['titulo']) ?> · <?= (int) ($info['leccion']['minutos'] ?? 5) ?> min</p>
          <a href="<?= e(url('modules/entrenamiento/leccion.php')) ?>?ruta=<?= e(rawurlencode($sigue[0])) ?>&leccion=<?= e(rawurlencode($sigue[1])) ?>"
             class="mt-3 inline-flex items-center gap-2 bg-white text-blue-700 font-semibold text-sm px-4 h-10 rounded-xl hover:bg-blue-50 transition w-full sm:w-auto justify-center">
            <?= icon('arrow-right', 'w-4 h-4') ?> Continuar
          </a>
        </div>
      <?php else: ?>
        <div class="bg-white/12 backdrop-blur rounded-2xl p-4 border border-white/15 text-center">
          <span class="inline-flex w-10 h-10 rounded-xl bg-white/20 items-center justify-center mb-2"><?= icon('check', 'w-5 h-5') ?></span>
          <p class="font-bold">Temario completo</p>
          <p class="text-xs text-white/70 mt-0.5">Puedes repasar cualquier lección cuando quieras.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Cifras -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6 pt-5 border-t border-white/15">
    <div>
      <p class="text-[11px] uppercase tracking-wider text-white/60 font-semibold">Rutas</p>
      <p class="text-lg font-extrabold mt-0.5"><?= $resumen['rutas'] ?></p>
    </div>
    <div>
      <p class="text-[11px] uppercase tracking-wider text-white/60 font-semibold">Certificados</p>
      <p class="text-lg font-extrabold mt-0.5"><?= $resumen['certificados'] ?></p>
    </div>
    <div>
      <p class="text-[11px] uppercase tracking-wider text-white/60 font-semibold">Duración total</p>
      <p class="text-lg font-extrabold mt-0.5"><?= $resumen['minutos'] ?> min</p>
    </div>
    <div>
      <p class="text-[11px] uppercase tracking-wider text-white/60 font-semibold">Tiempo dedicado</p>
      <p class="text-lg font-extrabold mt-0.5"><?= $resumen['minutos_dedicados'] ?> min</p>
    </div>
  </div>
</div>

<!-- ============ Cómo funciona ============ -->
<div class="card p-5 mb-6 flex flex-col sm:flex-row items-start gap-4 bg-slate-50/70">
  <span class="w-11 h-11 rounded-xl bg-white border border-slate-200 text-blue-600 flex items-center justify-center shrink-0"><?= icon('target', 'w-5 h-5') ?></span>
  <div class="flex-1">
    <h3 class="font-bold text-slate-800">Este temario es tuyo, no es el manual de todos</h3>
    <p class="text-sm text-slate-500 mt-1 leading-relaxed">
      Solo aparecen las lecciones de las pantallas que tu rol (<strong class="text-slate-700"><?= e($u['rol_nombre']) ?></strong>)
      puede abrir. Si un compañero ve rutas que tú no ves, no es un error: tiene otros permisos.
      Cada ruta termina con una evaluación y, al aprobarla, emite un certificado a tu nombre.
    </p>
  </div>
</div>

<!-- ============ Rutas ============ -->
<?php if (!$catalogo): ?>
  <?= empty_state('Sin lecciones disponibles',
       'Tu rol no tiene acceso a ningún módulo con entrenamiento. Pídele a un administrador que revise tus permisos.',
       'lock') ?>
<?php else: ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($catalogo as $rk => $ruta):
      $a    = ent_avance_ruta($ruta, $progreso, $rk);
      $cert = ent_certificado_emitido($rk);
      $col  = $ruta['color'];
      $fondos = [
        'blue'    => 'bg-blue-50 text-blue-600',    'emerald' => 'bg-emerald-50 text-emerald-600',
        'amber'   => 'bg-amber-50 text-amber-600',  'indigo'  => 'bg-indigo-50 text-indigo-600',
        'cyan'    => 'bg-cyan-50 text-cyan-600',    'violet'  => 'bg-violet-50 text-violet-600',
        'rose'    => 'bg-rose-50 text-rose-600',    'pink'    => 'bg-pink-50 text-pink-600',
        'slate'   => 'bg-slate-100 text-slate-600',
      ];
      $barras = [
        'blue' => 'bg-blue-500', 'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500',
        'indigo' => 'bg-indigo-500', 'cyan' => 'bg-cyan-500', 'violet' => 'bg-violet-500',
        'rose' => 'bg-rose-500', 'pink' => 'bg-pink-500', 'slate' => 'bg-slate-500',
      ];
      $fondo = $fondos[$col] ?? $fondos['blue'];
      $barra = $barras[$col] ?? $barras['blue'];
    ?>
      <a href="<?= e(url('modules/entrenamiento/ruta.php')) ?>?ruta=<?= e(rawurlencode($rk)) ?>"
         class="card p-5 flex flex-col gap-3 hover:shadow-soft hover:border-blue-200 hover:-translate-y-0.5 transition-all duration-200 group focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20">
        <div class="flex items-start gap-3">
          <span class="w-11 h-11 rounded-xl <?= $fondo ?> flex items-center justify-center shrink-0"><?= icon($ruta['icono'], 'w-5 h-5') ?></span>
          <div class="min-w-0 flex-1">
            <h3 class="font-bold text-slate-800 leading-snug group-hover:text-blue-700 transition-colors"><?= e($ruta['titulo']) ?></h3>
            <p class="text-[11px] text-slate-400 mt-0.5 font-medium uppercase tracking-wide"><?= e($ruta['nivel']) ?> · <?= $a['total'] ?> lecciones · <?= $a['minutos'] ?> min</p>
          </div>
          <?php if ($cert): ?>
            <span class="shrink-0 w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center" title="Certificado obtenido"><?= icon('shield', 'w-4 h-4') ?></span>
          <?php endif; ?>
        </div>

        <p class="text-[13px] text-slate-500 leading-relaxed flex-1"><?= e($ruta['descripcion']) ?></p>

        <div>
          <div class="flex items-center justify-between text-xs mb-1.5">
            <span class="font-semibold text-slate-600"><?= $a['completadas'] ?>/<?= $a['total'] ?> completadas</span>
            <span class="font-bold <?= $a['pct'] === 100 ? 'text-emerald-600' : 'text-slate-400' ?>"><?= $a['pct'] ?>%</span>
          </div>
          <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full <?= $a['pct'] === 100 ? 'bg-emerald-500' : $barra ?> transition-all duration-500" style="width:<?= $a['pct'] ?>%"></div>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php layout_end(); ?>
