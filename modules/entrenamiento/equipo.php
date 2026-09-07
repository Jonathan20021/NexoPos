<?php
/**
 * Avance del equipo.
 *
 * Mirar el avance ajeno es supervisión de personal, no formación propia: por eso
 * esta pantalla —y solo esta— lleva permiso.
 *
 * El porcentaje de cada persona se mide contra SU temario, que depende de sus
 * permisos. Un cajero con 12 lecciones y un administrador con 70 llegan los dos
 * al 100%: comparar «lecciones hechas» entre roles no significaría nada.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('entrenamiento.equipo');

if (isPost()) {
    verify_csrf();
    $uid = 0;
    if (post('accion') === 'reiniciar') {
        require_perm('entrenamiento.asignar');
        $uid = postInt('usuario_id');
        // Se relee del alcance visible: un id escrito a mano en el formulario no
        // debe alcanzar a alguien de otra sucursal.
        $enAlcance = false;
        foreach (ent_equipo() as $e) if ((int) $e['id'] === $uid) { $enAlcance = true; $obj = $e; break; }
        if ($enAlcance) {
            ent_reiniciar($uid);
            flash('info', 'Se reinició el entrenamiento de ' . trim($obj['nombre'] . ' ' . $obj['apellido']) . '.');
        } else {
            flash('error', 'Esa persona no está en tu alcance.');
            $uid = 0;
        }
    }
    redirect('modules/entrenamiento/equipo.php' . ($uid > 0 ? '?usuario=' . $uid : ''));
}

$verUsuario = (int) get('usuario');
$equipo     = ent_equipo();

/* ---------- Detalle de una persona ---------- */
if ($verUsuario > 0) {
    $persona = null;
    foreach ($equipo as $e) if ((int) $e['id'] === $verUsuario) { $persona = $e; break; }
    if (!$persona) {
        flash('warning', 'Esa persona no está en tu alcance.');
        redirect('modules/entrenamiento/equipo.php');
    }

    $rutas = ent_detalle_usuario($persona);
    $intentos = ent_disponible()
        ? qAll("SELECT * FROM entrenamiento_evaluaciones WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 15", [$verUsuario])
        : [];

    layout_start(
        trim($persona['nombre'] . ' ' . $persona['apellido']),
        $persona['rol_nombre'] . ' · ' . ($persona['sucursal_nombre'] ?: 'Todas las sucursales'),
        '<a href="' . e(url('modules/entrenamiento/equipo.php')) . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Todo el equipo</a>'
    );
    ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
      <div class="lg:col-span-2 flex flex-col gap-5">

        <div class="card p-5">
          <div class="flex items-center justify-between text-sm mb-2">
            <span class="font-semibold text-slate-700"><?= (int) $persona['completadas'] ?> de <?= (int) $persona['lecciones_total'] ?> lecciones de su temario</span>
            <span class="font-extrabold <?= $persona['pct'] === 100 ? 'text-emerald-600' : 'text-slate-500' ?>"><?= (int) $persona['pct'] ?>%</span>
          </div>
          <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full rounded-full <?= $persona['pct'] === 100 ? 'bg-emerald-500' : 'bg-blue-500' ?>" style="width:<?= (int) $persona['pct'] ?>%"></div>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-5 pt-4 border-t border-slate-100">
            <div><p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">En curso</p><p class="text-lg font-extrabold text-slate-800 mt-0.5"><?= (int) $persona['en_curso'] ?></p></div>
            <div><p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">Certificados</p><p class="text-lg font-extrabold text-slate-800 mt-0.5"><?= (int) $persona['certificados'] ?></p></div>
            <div><p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">Tiempo</p><p class="text-lg font-extrabold text-slate-800 mt-0.5"><?= (int) $persona['minutos'] ?> min</p></div>
            <div><p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">Última actividad</p><p class="text-sm font-semibold text-slate-700 mt-1.5"><?= $persona['ultima'] ? e(tiempoRelativo($persona['ultima'])) : '—' ?></p></div>
          </div>
        </div>

        <div class="card overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-100">
            <h2 class="font-bold text-slate-800">Avance por ruta</h2>
            <p class="text-xs text-slate-400 mt-0.5">Solo las rutas que sus permisos le abren.</p>
          </div>
          <?php if (!$rutas): ?>
            <?= empty_state('Sin rutas', 'Su rol no tiene acceso a ningún módulo con entrenamiento.', 'lock') ?>
          <?php else: ?>
            <div class="divide-y divide-slate-100">
              <?php foreach ($rutas as $r): ?>
                <div class="px-5 py-4 flex items-center gap-4">
                  <span class="w-9 h-9 rounded-xl bg-slate-50 text-slate-400 flex items-center justify-center shrink-0"><?= icon($r['icono'], 'w-[18px] h-[18px]') ?></span>
                  <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                      <p class="font-semibold text-slate-800 text-[15px]"><?= e($r['titulo']) ?></p>
                      <?php if ($r['aprobada']): ?><?= badge('Certificada · ' . number_format((float) $r['porcentaje'], 0) . '%', 'emerald') ?><?php endif; ?>
                    </div>
                    <div class="flex items-center gap-3 mt-2">
                      <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden flex-1">
                        <div class="h-full rounded-full <?= $r['pct'] === 100 ? 'bg-emerald-500' : 'bg-blue-500' ?>" style="width:<?= $r['pct'] ?>%"></div>
                      </div>
                      <span class="text-xs font-bold text-slate-400 shrink-0 w-20 text-right"><?= $r['completadas'] ?>/<?= $r['total'] ?> · <?= $r['pct'] ?>%</span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($intentos): ?>
          <div class="card overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
              <h2 class="font-bold text-slate-800">Historial de evaluaciones</h2>
              <p class="text-xs text-slate-400 mt-0.5">Todos los intentos, aprobados o no.</p>
            </div>
            <div class="overflow-x-auto">
              <table class="data-table">
                <thead><tr><th>Fecha</th><th>Ruta</th><th class="text-right">Resultado</th><th>Estado</th></tr></thead>
                <tbody>
                  <?php foreach ($intentos as $i):
                    $r = ent_catalogo()[$i['ruta_clave']] ?? null; ?>
                    <tr>
                      <td class="whitespace-nowrap"><?= e(fechaHora($i['created_at'])) ?></td>
                      <td><?= e($r['titulo'] ?? $i['ruta_clave']) ?></td>
                      <td class="text-right font-semibold"><?= (int) $i['puntaje'] ?>/<?= (int) $i['total'] ?> · <?= number_format((float) $i['porcentaje'], 0) ?>%</td>
                      <td><?= (int) $i['aprobado'] === 1 ? badge('Aprobada', 'emerald') : badge('No aprobada', 'amber') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="flex flex-col gap-5">
        <div class="card p-5">
          <div class="flex items-center gap-3">
            <?= avatar($persona['nombre'] . ' ' . $persona['apellido'], 'w-12 h-12') ?>
            <div class="min-w-0">
              <p class="font-bold text-slate-800 truncate"><?= e(trim($persona['nombre'] . ' ' . $persona['apellido'])) ?></p>
              <p class="text-xs text-slate-400 truncate"><?= e($persona['email']) ?></p>
            </div>
          </div>
          <div class="mt-4 pt-4 border-t border-slate-100 space-y-2 text-sm">
            <div class="flex justify-between gap-3"><span class="text-slate-400">Rol</span><span class="font-semibold text-slate-700 text-right"><?= e($persona['rol_nombre']) ?></span></div>
            <div class="flex justify-between gap-3"><span class="text-slate-400">Sucursal</span><span class="font-semibold text-slate-700 text-right"><?= e($persona['sucursal_nombre'] ?: 'Todas') ?></span></div>
            <div class="flex justify-between gap-3"><span class="text-slate-400">Último acceso</span><span class="font-semibold text-slate-700 text-right"><?= $persona['ultimo_acceso'] ? e(tiempoRelativo($persona['ultimo_acceso'])) : 'Nunca' ?></span></div>
          </div>
        </div>

        <?php if (can('entrenamiento.asignar') && (int) $persona['completadas'] > 0): ?>
          <form method="post" onsubmit="return confirm('¿Reiniciar el entrenamiento de <?= e(trim($persona['nombre'] . ' ' . $persona['apellido'])) ?>? Se borra su avance y sus evaluaciones.')">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="reiniciar">
            <input type="hidden" name="usuario_id" value="<?= (int) $persona['id'] ?>">
            <button class="btn btn-ghost w-full justify-center text-slate-500"><?= icon('undo', 'w-4 h-4') ?> Reiniciar su entrenamiento</button>
          </form>
          <p class="text-[11px] text-slate-400 text-center -mt-2 leading-relaxed">Se usa cuando alguien cambia de puesto y tiene que recorrer el temario nuevo.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php
    layout_end();
    return;
}

/* ---------- Listado del equipo ---------- */
$q = trim((string) get('q'));
if ($q !== '') {
    $equipo = array_values(array_filter($equipo, function ($e) use ($q) {
        $texto = mb_strtolower($e['nombre'] . ' ' . $e['apellido'] . ' ' . $e['usuario'] . ' ' . $e['rol_nombre'] . ' ' . ($e['sucursal_nombre'] ?? ''));
        return str_contains($texto, mb_strtolower($q));
    }));
}

$n          = count($equipo);
$terminados = count(array_filter($equipo, fn($e) => $e['pct'] === 100));
$sinEmpezar = count(array_filter($equipo, fn($e) => $e['completadas'] === 0));
$promedio   = $n > 0 ? (int) round(array_sum(array_column($equipo, 'pct')) / $n) : 0;
$certs      = array_sum(array_column($equipo, 'certificados'));

layout_start('Avance del equipo', $n . ' persona' . ($n === 1 ? '' : 's') . ' en tu alcance',
    '<a href="' . e(url('modules/entrenamiento/index.php')) . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Mi entrenamiento</a>');
?>

<?php if (!ent_disponible()): ?>
  <div class="card p-5 mb-5 border-amber-200 bg-amber-50/60">
    <p class="text-sm text-slate-600">Falta aplicar <code class="text-xs bg-white px-1.5 py-0.5 rounded border border-amber-200">database/migracion_entrenamiento_p37.sql</code>: sin ella no hay avance que mostrar.</p>
  </div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">Avance promedio</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= $promedio ?>%</p>
    <p class="text-xs text-slate-400 mt-0.5">Cada quien contra su propio temario</p>
  </div>
  <div class="card p-5">
    <p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">Al día</p>
    <p class="text-2xl font-extrabold text-emerald-600 mt-1"><?= $terminados ?></p>
    <p class="text-xs text-slate-400 mt-0.5">Terminaron su temario completo</p>
  </div>
  <div class="card p-5">
    <p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">Sin empezar</p>
    <p class="text-2xl font-extrabold <?= $sinEmpezar > 0 ? 'text-amber-600' : 'text-slate-800' ?> mt-1"><?= $sinEmpezar ?></p>
    <p class="text-xs text-slate-400 mt-0.5">No han abierto ninguna lección</p>
  </div>
  <div class="card p-5">
    <p class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">Certificados</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= $certs ?></p>
    <p class="text-xs text-slate-400 mt-0.5">Rutas aprobadas por el equipo</p>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center gap-3">
    <div class="flex-1">
      <h2 class="font-bold text-slate-800">Personas</h2>
      <p class="text-xs text-slate-400 mt-0.5">El porcentaje se mide contra el temario que abre el rol de cada quien.</p>
    </div>
    <?= search_box('Buscar por nombre, rol o sucursal…') ?>
  </div>

  <?php if (!$equipo): ?>
    <?= empty_state('Sin resultados', $q !== '' ? 'Nadie coincide con «' . $q . '».' : 'No hay personas activas en tu alcance.', 'users') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Persona</th>
            <th>Rol</th>
            <th>Sucursal</th>
            <th class="min-w-[180px]">Avance</th>
            <th class="text-center">Certif.</th>
            <th class="text-right">Última actividad</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($equipo as $e): ?>
            <tr>
              <td>
                <div class="flex items-center gap-2.5">
                  <?= avatar($e['nombre'] . ' ' . $e['apellido'], 'w-8 h-8') ?>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-700 truncate"><?= e(trim($e['nombre'] . ' ' . $e['apellido'])) ?></p>
                    <p class="text-xs text-slate-400 truncate"><?= e($e['usuario']) ?></p>
                  </div>
                </div>
              </td>
              <td class="text-sm text-slate-500"><?= e($e['rol_nombre']) ?></td>
              <td class="text-sm text-slate-500"><?= e($e['sucursal_nombre'] ?: 'Todas') ?></td>
              <td>
                <div class="flex items-center gap-2.5">
                  <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden flex-1 min-w-[70px]">
                    <div class="h-full rounded-full <?= $e['pct'] === 100 ? 'bg-emerald-500' : ($e['pct'] === 0 ? 'bg-slate-300' : 'bg-blue-500') ?>" style="width:<?= max(2, (int) $e['pct']) ?>%"></div>
                  </div>
                  <span class="text-xs font-bold text-slate-500 shrink-0 w-24 text-right"><?= (int) $e['completadas'] ?>/<?= (int) $e['lecciones_total'] ?> · <?= (int) $e['pct'] ?>%</span>
                </div>
              </td>
              <td class="text-center"><?= (int) $e['certificados'] > 0 ? badge((string) (int) $e['certificados'], 'emerald') : '<span class="text-slate-300">—</span>' ?></td>
              <td class="text-right text-sm text-slate-400 whitespace-nowrap"><?= $e['ultima'] ? e(tiempoRelativo($e['ultima'])) : 'Sin empezar' ?></td>
              <td class="text-right">
                <a href="<?= e(url('modules/entrenamiento/equipo.php')) ?>?usuario=<?= (int) $e['id'] ?>"
                   class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 inline-flex" title="Ver detalle"><?= icon('eye', 'w-4 h-4') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
