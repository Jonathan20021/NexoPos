<?php
/**
 * Administración → Actualizar base de datos.
 *
 * El segundo paso de cada despliegue, sin phpMyAdmin: tras «Update From
 * Remote», el super administrador entra aquí y pulsa un botón. Se guarda un
 * respaldo completo en storage/respaldos y se aplica el archivo de la entrega
 * (includes/actualizador.php). Solo el super administrador: el permiso
 * `sistema.actualizar_bd` no está en el catálogo, así que ningún rol lo tiene.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('sistema.actualizar_bd');

// Descarga de un respaldo automático. El nombre se valida entero: nada de rutas.
if (($nombre = (string) get('respaldo')) !== '') {
    if (!preg_match('/^antes_de_actualizar_\d{8}_\d{6}\.sql\.gz$/', $nombre) || !is_file(ACT_DIR_RESPALDOS . '/' . $nombre)) {
        flash('error', 'Ese respaldo no existe.');
        redirect('modules/admin/actualizar_bd.php');
    }
    audit('configuracion', 'editar', 'Descargó el respaldo automático ' . $nombre);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . filesize(ACT_DIR_RESPALDOS . '/' . $nombre));
    readfile(ACT_DIR_RESPALDOS . '/' . $nombre);
    exit;
}

$resultado = null;   // lo que pasó al pulsar el botón, para enseñarlo en esta misma respuesta
if (isPost() && post('accion') === 'aplicar') {
    verify_csrf();
    $paquetes = act_paquetes();
    $clave = (string) post('paquete');
    if (!isset($paquetes[$clave])) {
        flash('error', 'Actualización desconocida.');
        redirect('modules/admin/actualizar_bd.php');
    }
    @set_time_limit(900);
    ignore_user_abort(true);   // si se cierra la pestaña a medias, que termine igual

    $resultado = ['respaldo' => null, 'aplicacion' => null, 'error' => null];
    try {
        // Sin respaldo no se toca nada.
        $resultado['respaldo'] = act_respaldar();
        $resultado['aplicacion'] = act_aplicar($paquetes[$clave]['archivo']);
    } catch (Throwable $e) {
        $resultado['error'] = $e->getMessage();
    }
    $ap = $resultado['aplicacion'];
    audit('configuracion', 'editar', mb_substr(
        'Actualizó la base de datos (' . $paquetes[$clave]['archivo'] . '): '
        . ($resultado['error'] ? 'error antes de aplicar — ' . $resultado['error']
           : ($ap['ok'] ? 'aplicada, ' . $ap['ejecutadas'] . ' sentencias' : 'se detuvo en la sentencia ' . ($ap['ejecutadas'] + 1) . ' — ' . $ap['error']))
        . ($resultado['respaldo'] ? '. Respaldo: ' . $resultado['respaldo']['nombre'] : ''), 0, 255));
    act_pendiente(true);
}

$estado = act_estado();
$pendiente = false;
foreach ($estado as $e) if ($e['pendiente']) $pendiente = true;
$respaldos = act_respaldos();

layout_start('Actualizar base de datos', 'El paso que va después de «Update From Remote»');

/** Tabla genérica para los resultados del archivo; OK en verde y FALTA en rojo. */
$pintarTabla = function (array $filas): string {
    $cols = array_keys($filas[0]);
    $h = '<div class="overflow-x-auto"><table class="data-table text-sm"><thead><tr>';
    foreach ($cols as $c) $h .= '<th>' . e(str_replace('_', ' ', (string) $c)) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($filas as $f) {
        $h .= '<tr>';
        foreach ($f as $v) {
            $v = (string) $v;
            $cls = $v === 'OK' ? 'text-emerald-600 font-bold' : (str_starts_with($v, 'FALTA') ? 'text-rose-600 font-bold' : 'text-slate-600');
            $h .= '<td class="' . $cls . '">' . e($v) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
};
?>

<?php if ($resultado): $ap = $resultado['aplicacion']; ?>
  <?php if ($resultado['error']): ?>
    <div class="card p-5 mb-5 border-rose-200 bg-rose-50">
      <p class="font-bold text-rose-800 flex items-center gap-2"><?= icon('alert', 'w-5 h-5') ?> No se aplicó nada</p>
      <p class="text-sm text-rose-700 mt-1"><?= e($resultado['error']) ?></p>
    </div>
  <?php elseif (!$ap['ok']): ?>
    <div class="card p-5 mb-5 border-rose-200 bg-rose-50">
      <p class="font-bold text-rose-800 flex items-center gap-2"><?= icon('alert', 'w-5 h-5') ?>
        Se detuvo en la sentencia <?= (int) $ap['ejecutadas'] + 1 ?> de <?= (int) $ap['sentencias'] ?></p>
      <p class="text-sm text-rose-700 mt-1"><?= e($ap['error']) ?></p>
      <pre class="mt-3 text-xs bg-white border border-rose-200 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap"><?= e($ap['sql']) ?></pre>
      <p class="text-sm text-rose-700 mt-3">Lo anterior a esa sentencia ya quedó aplicado y el archivo es seguro de repetir:
        corrige la causa y vuelve a pulsar el botón. El respaldo de antes de empezar está abajo.</p>
    </div>
  <?php elseif (act_hay_falta($ap['tablas'])): ?>
    <div class="card p-5 mb-5 border-amber-200 bg-amber-50">
      <p class="font-bold text-amber-900 flex items-center gap-2"><?= icon('alert', 'w-5 h-5') ?> Aplicada, pero algo dice FALTA</p>
      <p class="text-sm text-amber-800 mt-1">Revisa las tablas de abajo: suele ser una migración anterior que esta entrega da por hecha.</p>
    </div>
  <?php else: ?>
    <div class="card p-5 mb-5 border-emerald-200 bg-emerald-50">
      <p class="font-bold text-emerald-800 flex items-center gap-2"><?= icon('check', 'w-5 h-5') ?> La base quedó al día</p>
      <p class="text-sm text-emerald-700 mt-1"><?= (int) $ap['ejecutadas'] ?> sentencias en <?= e(number_format($ap['segundos'], 1)) ?> s.
        Respaldo previo guardado: <strong><?= e($resultado['respaldo']['nombre']) ?></strong>.</p>
    </div>
  <?php endif; ?>

  <?php if ($ap && $ap['tablas']): ?>
    <div class="card p-5 mb-5">
      <h3 class="font-bold text-slate-800 mb-3">Lo que respondió la base</h3>
      <div class="space-y-4">
        <?php foreach ($ap['tablas'] as $t): ?><?= $pintarTabla($t) ?><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="lg:col-span-2 space-y-5">
    <?php foreach ($estado as $clave => $p): ?>
      <div class="card p-6">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="font-bold text-slate-800"><?= e($p['titulo']) ?></h3>
            <p class="text-xs text-slate-400 mt-0.5 font-mono">database/<?= e($p['archivo']) ?></p>
          </div>
          <?= $p['pendiente'] ? badge('Pendiente', 'amber') : badge('Aplicada', 'emerald') ?>
        </div>

        <ul class="mt-4 space-y-2">
          <?php foreach ($p['checks'] as $c): ?>
            <li class="flex items-center gap-2 text-sm">
              <span class="<?= $c['ok'] ? 'text-emerald-500' : 'text-amber-500' ?>"><?= icon($c['ok'] ? 'check' : 'clock', 'w-4 h-4') ?></span>
              <span class="<?= $c['ok'] ? 'text-slate-600' : 'text-slate-800 font-semibold' ?>"><?= e($c['nombre']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if (!$p['existe']): ?>
          <p class="mt-4 text-sm text-rose-600">El archivo no está en el servidor. Haz primero «Update From Remote» en cPanel.</p>
        <?php else: ?>
          <form method="post" class="mt-5"
                data-confirmar="<?= e($p['pendiente']
                    ? '¿Actualizar la base ahora? Primero se guarda un respaldo completo; después se aplican los cambios. Tarda unos segundos.'
                    : 'La base ya está al día. ¿Volver a aplicarla de todos modos? No cambia nada de lo que ya está bien.') ?>"
                onsubmit="return confirm(this.dataset.confirmar)" data-act-form>
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="aplicar">
            <input type="hidden" name="paquete" value="<?= e($clave) ?>">
            <button class="btn <?= $p['pendiente'] ? 'btn-primary' : 'btn-ghost' ?> disabled:opacity-60">
              <?= icon($p['pendiente'] ? 'save' : 'history', 'w-4 h-4') ?>
              <span data-txt><?= $p['pendiente'] ? 'Respaldar y actualizar ahora' : 'Volver a aplicar' ?></span>
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="card p-6 bg-slate-50/60">
      <h3 class="font-bold text-slate-800">Qué hace el botón</h3>
      <ol class="text-sm text-slate-600 space-y-1.5 mt-3 list-decimal list-inside">
        <li>Guarda un <strong>respaldo completo</strong> de la base en el servidor (abajo a la derecha se puede descargar).</li>
        <li>Aplica el archivo de la entrega, que añade tablas, columnas, catálogos y permisos. <strong>No toca precios, ventas ni datos.</strong></li>
        <li>Enseña la misma verificación que daría phpMyAdmin: todo debe decir <strong>OK</strong>.</li>
      </ol>
      <p class="text-xs text-slate-400 mt-3">Es seguro repetirlo. Si algo fallara a medias, lo aplicado se queda y basta con volver a pulsar.</p>
    </div>
  </div>

  <div class="card overflow-hidden h-fit">
    <div class="p-5 pb-3">
      <h3 class="font-bold text-slate-800">Respaldos automáticos</h3>
      <p class="text-xs text-slate-400 mt-0.5">Los que se guardaron antes de cada actualización</p>
    </div>
    <?php if (!$respaldos): ?>
      <p class="px-5 pb-5 text-sm text-slate-400">Todavía ninguno.</p>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($respaldos as $r): ?>
          <li class="px-5 py-3 flex items-center justify-between gap-3 text-sm">
            <div class="min-w-0">
              <p class="text-slate-700 font-medium"><?= e(date('d/m/Y h:i A', $r['fecha'])) ?></p>
              <p class="text-xs text-slate-400"><?= e($r['bytes'] >= 1048576 ? number_format($r['bytes'] / 1048576, 1) . ' MB' : number_format($r['bytes'] / 1024) . ' KB') ?> · .sql.gz</p>
            </div>
            <a href="?respaldo=<?= e(urlencode($r['nombre'])) ?>" class="btn btn-ghost btn-sm shrink-0"><?= icon('download', 'w-3.5 h-3.5') ?> Descargar</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<script>
// El pie convierte el confirm() en su modal y vuelve a enviar el formulario con
// data-confirmado=1. Ese segundo envío es el bueno: ahí se avisa de que está
// trabajando, porque respaldar y aplicar tarda unos segundos sin dar señales.
document.querySelectorAll('form[data-act-form]').forEach(function (f) {
  f.addEventListener('submit', function () {
    if (f.dataset.confirmado !== '1') return;
    var b = f.querySelector('button');
    b.disabled = true;
    b.querySelector('[data-txt]').textContent = 'Respaldando y actualizando…';
  });
});
</script>

<?php layout_end(); ?>
