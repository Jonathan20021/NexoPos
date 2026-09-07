<?php
/**
 * Certificado de una ruta terminada.
 *
 * Se emite con dos condiciones a la vez: todas las lecciones visibles de la ruta
 * completadas Y la evaluación aprobada. La página no «genera» nada: si las dos
 * condiciones se cumplen, el certificado existe; si dejan de cumplirse (a
 * alguien le reinician el avance), deja de existir. No hay documento guardado
 * que pueda contradecir al sistema.
 *
 * Se imprime con la hoja de impresión global del layout (`.no-print`).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$rk   = (string) get('ruta');
$ruta = ent_ruta($rk);
if (!$ruta) {
    flash('warning', 'Esa ruta no está disponible para tu rol.');
    redirect('modules/entrenamiento/index.php');
}
if (!ent_certificado_emitido($rk)) {
    flash('warning', 'Necesitas terminar todas las lecciones y aprobar la evaluación de esta ruta.');
    redirect('modules/entrenamiento/ruta.php?ruta=' . rawurlencode($rk));
}

$u      = current_user();
$mejor  = ent_mejor_evaluacion($rk);
$avance = ent_avance_ruta($ruta, ent_progreso(), $rk);
$fecha  = substr((string) ($mejor['created_at'] ?? date('Y-m-d')), 0, 10);
$folio  = ent_folio((int) $u['id'], $rk, $fecha);
$empresa = $GLOBALS['empresa'] ?? [];
$logo    = setting('logo') ?: marca_app_logo();
$hayLogo = $logo && is_file(dirname(__DIR__, 2) . '/' . $logo);

$acciones = '<a href="' . e(url('modules/entrenamiento/ruta.php')) . '?ruta=' . e(rawurlencode($rk)) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Volver</a>'
          . '<button onclick="window.print()" class="btn btn-primary">' . icon('print', 'w-4 h-4') . ' Imprimir</button>';

layout_start('Certificado', $ruta['titulo'], $acciones);
?>

<div class="max-w-4xl mx-auto">
  <div class="card overflow-hidden print-break">

    <!-- Filo superior -->
    <div class="h-2 bg-gradient-to-r from-blue-600 via-indigo-600 to-violet-600"></div>

    <div class="p-8 sm:p-12 text-center">

      <?php if ($hayLogo): ?>
        <img src="<?= e(url($logo)) ?>" alt="<?= e(setting('nombre', APP_NAME)) ?>" class="h-12 mx-auto object-contain mb-6">
      <?php else: ?>
        <div class="w-14 h-14 rounded-2xl bg-blue-600 text-white flex items-center justify-center mx-auto mb-6 text-2xl font-extrabold">
          <?= e(mb_strtoupper(mb_substr((string) setting('nombre', APP_NAME), 0, 1))) ?>
        </div>
      <?php endif; ?>

      <p class="text-[11px] uppercase tracking-[0.3em] text-slate-400 font-bold">Certificado de entrenamiento</p>

      <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-800 mt-5 leading-tight">
        <?= e(trim($u['nombre'] . ' ' . $u['apellido'])) ?>
      </h1>

      <p class="text-slate-500 mt-4 max-w-xl mx-auto leading-relaxed">
        completó el entrenamiento de
      </p>

      <h2 class="text-xl sm:text-2xl font-extrabold text-blue-700 mt-2"><?= e($ruta['titulo']) ?></h2>

      <p class="text-slate-500 mt-4 max-w-2xl mx-auto leading-relaxed text-[15px]">
        <?= $avance['total'] ?> lecciones · <?= $avance['minutos'] ?> minutos de contenido ·
        evaluación aprobada con <strong class="text-slate-700"><?= number_format((float) $mejor['porcentaje'], 0) ?>%</strong>
        (<?= (int) $mejor['puntaje'] ?> de <?= (int) $mejor['total'] ?> respuestas correctas)
      </p>

      <div class="w-24 h-px bg-slate-200 mx-auto my-8"></div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 text-left max-w-2xl mx-auto">
        <div>
          <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Emitido el</p>
          <p class="text-sm font-semibold text-slate-700 mt-1"><?= e(fechaLarga($fecha)) ?></p>
        </div>
        <div>
          <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Puesto / rol</p>
          <p class="text-sm font-semibold text-slate-700 mt-1"><?= e($u['rol_nombre']) ?></p>
        </div>
        <div>
          <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Folio</p>
          <p class="text-sm font-semibold text-slate-700 mt-1 font-mono"><?= e($folio) ?></p>
        </div>
      </div>

      <div class="mt-10 pt-6 border-t border-slate-100">
        <p class="text-sm font-bold text-slate-700"><?= e($empresa['nombre'] ?? setting('nombre', APP_NAME)) ?></p>
        <?php if (!empty($empresa['rnc'])): ?>
          <p class="text-xs text-slate-400 mt-0.5">RNC <?= e($empresa['rnc']) ?></p>
        <?php endif; ?>
        <p class="text-[11px] text-slate-400 mt-3 max-w-lg mx-auto leading-relaxed">
          Este certificado acredita haber recorrido el entrenamiento del sistema para el rol indicado.
          Su validez depende del avance registrado: se puede verificar en el Centro de Entrenamiento
          con el folio y el nombre de la persona.
        </p>
      </div>
    </div>
  </div>

  <div class="card p-5 mt-5 no-print flex flex-col sm:flex-row items-start gap-4 bg-slate-50/70">
    <span class="w-11 h-11 rounded-xl bg-white border border-slate-200 text-slate-400 flex items-center justify-center shrink-0"><?= icon('shield', 'w-5 h-5') ?></span>
    <div class="flex-1">
      <h3 class="font-bold text-slate-800">Qué acredita y qué no</h3>
      <p class="text-sm text-slate-500 mt-1 leading-relaxed">
        Acredita que esta persona recorrió el temario de su rol y respondió correctamente la evaluación.
        No sustituye la supervisión de los primeros días en el puesto, ni autoriza permisos:
        los permisos los asigna un administrador en Roles.
      </p>
    </div>
  </div>
</div>

<?php layout_end(); ?>
