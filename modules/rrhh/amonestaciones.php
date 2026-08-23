<?php
/**
 * Amonestaciones y régimen disciplinario.
 *
 * La pantalla gira alrededor de una cuenta atrás: el derecho a despedir por una
 * falta caduca a los 15 días de conocerla. Todo lo demás —el catálogo, la
 * progresión, el papel que se firma— sirve para que ese expediente sostenga
 * una decisión el día que haga falta.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('amonestaciones.ver');

if (!amonDisponible()) {
    layout_start('Amonestaciones', 'Falta aplicar la migración');
    echo empty_state('Módulo no instalado',
        'Aplica database/migracion_amonestaciones_p24.sql para activar el régimen disciplinario.', 'shield');
    layout_end();
    return;
}

$verId = (int) get('ver');

/* ============================================================
 *  Acciones
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'crear') {
        require_perm('amonestaciones.crear');
        $empId   = postInt('empleado_id');
        $tipo    = array_key_exists(post('tipo'), amonTipos()) ? post('tipo') : 'escrita';
        $grav    = array_key_exists(post('gravedad'), amonGravedades()) ? post('gravedad') : 'leve';
        $hecho   = post('fecha_hecho');
        $conoc   = post('fecha_conocimiento') ?: $hecho;
        $hechos  = trim(post('hechos'));
        $dias    = max(0, postInt('dias_suspension'));

        try {
            $e = qOne("SELECT id, nombre, apellido FROM empleados WHERE id = ?", [$empId]);
            if (!$e) throw new RuntimeException('Elige un empleado.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $hecho)) throw new RuntimeException('Indica cuándo ocurrió el hecho.');
            if ($conoc < $hecho) throw new RuntimeException('No se puede haber sabido del hecho antes de que ocurriera.');
            if ($hecho > date('Y-m-d')) throw new RuntimeException('El hecho no puede ser de una fecha futura.');
            // Una amonestación que dice «mala actitud» no sostiene nada.
            if (mb_strlen($hechos) < 30) {
                throw new RuntimeException('Describe los hechos en concreto —qué pasó, cuándo y dónde—. '
                    . 'Una amonestación vaga no sostiene nada en un expediente.');
            }
            if ($tipo === 'suspension' && $dias < 1) throw new RuntimeException('Una suspensión necesita al menos un día.');

            $susDesde = $tipo === 'suspension' ? (post('suspension_desde') ?: date('Y-m-d')) : null;
            $susHasta = $susDesde ? date('Y-m-d', strtotime($susDesde . ' +' . ($dias - 1) . ' days')) : null;

            $id = dbInsert('amonestaciones', [
                'numero' => nextNumero('amonestaciones', 'numero', 'AMO'),
                'empleado_id' => (int) $e['id'],
                'falta_id' => postInt('falta_id') ?: null,
                'tipo' => $tipo, 'gravedad' => $grav,
                'fecha_hecho' => $hecho, 'fecha_conocimiento' => $conoc,
                'fecha_emision' => date('Y-m-d'),
                'hechos' => $hechos,
                'referencia_legal' => trim(post('referencia_legal')) ?: null,
                'medida' => trim(post('medida')) ?: null,
                'dias_suspension' => $tipo === 'suspension' ? $dias : 0,
                'suspension_desde' => $susDesde, 'suspension_hasta' => $susHasta,
                'supervisor' => trim(post('supervisor')) ?: (current_user()['nombre'] ?? null),
                'notas' => trim(post('notas')) ?: null,
                'estado' => 'borrador',
                'usuario_id' => current_user()['id'],
            ]);
            $cad = amonCaducidad($conoc);
            audit('amonestaciones', 'crear', 'Amonestación a ' . $e['nombre'] . ' ' . $e['apellido']
                . ' (' . amonTipos()[$tipo] . ', ' . $grav . ')', ['tabla' => 'amonestaciones', 'registro_id' => $id]);
            flash($cad['caducado'] ? 'warning' : 'success',
                'Amonestación registrada en borrador. Imprímela, notifícala y recoge la firma.'
                . ($cad['caducado']
                    ? ' OJO: el plazo de 15 días para ejercer el despido por esta falta ya caducó.'
                    : ' Plazo para ejercer el despido por esta falta: ' . $cad['etiqueta'] . '.'));
            redirect('modules/rrhh/amonestaciones.php?ver=' . $id);
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
            redirect('modules/rrhh/amonestaciones.php');
        }
    }

    if ($accion === 'estado') {
        require_perm('amonestaciones.crear');
        $id = postInt('id');
        $nuevo = post('estado');
        $a = qOne("SELECT a.*, e.nombre, e.apellido FROM amonestaciones a JOIN empleados e ON e.id=a.empleado_id WHERE a.id=?", [$id]);
        try {
            if (!$a) throw new RuntimeException('Amonestación no encontrada.');
            if ($a['estado'] === 'anulada') throw new RuntimeException('Está anulada.');
            if (!array_key_exists($nuevo, amonEstados()) || $nuevo === 'anulada') throw new RuntimeException('Estado no válido.');

            $datos = ['estado' => $nuevo];
            if ($nuevo === 'notificada') $datos['notificada_at'] = date('Y-m-d H:i:s');
            if ($nuevo === 'firmada')    $datos['firmada_at'] = date('Y-m-d H:i:s');
            if ($nuevo === 'rehuso_firmar') {
                // Negarse a firmar no anula la amonestación, pero SIN testigos
                // el documento no acredita que se le notificó.
                $t1 = trim(post('testigo1')); $t2 = trim(post('testigo2'));
                if ($t1 === '' || $t2 === '') {
                    throw new RuntimeException('Para dejar constancia de que se negó a firmar hacen falta DOS testigos con nombre. '
                        . 'Sin ellos el documento no acredita que se le notificó.');
                }
                $datos['testigo1'] = $t1; $datos['testigo2'] = $t2;
                $datos['notificada_at'] = $a['notificada_at'] ?: date('Y-m-d H:i:s');
            }
            dbUpdate('amonestaciones', $datos, 'id = ?', [$id]);
            audit('amonestaciones', 'editar', $a['numero'] . ' → ' . amonEstados()[$nuevo][0]
                . ' · ' . $a['nombre'] . ' ' . $a['apellido'], ['tabla' => 'amonestaciones', 'registro_id' => $id]);
            flash('success', 'Amonestación marcada como ' . mb_strtolower(amonEstados()[$nuevo][0]) . '.');
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
        }
        redirect('modules/rrhh/amonestaciones.php?ver=' . $id);
    }

    /* ---------- Descargo del trabajador ----------
       Su versión de los hechos. Que exista es lo que convierte el papel en un
       expediente y no en una acusación unilateral. */
    if ($accion === 'descargo') {
        require_perm('amonestaciones.crear');
        $id = postInt('id');
        $txt = trim(post('descargo'));
        if ($txt === '') { flash('error', 'Escribe el descargo.'); redirect('modules/rrhh/amonestaciones.php?ver=' . $id); }
        dbUpdate('amonestaciones', ['descargo' => $txt, 'descargo_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        audit('amonestaciones', 'editar', 'Descargo registrado', ['tabla' => 'amonestaciones', 'registro_id' => $id]);
        flash('success', 'Descargo registrado en el expediente.');
        redirect('modules/rrhh/amonestaciones.php?ver=' . $id);
    }

    if ($accion === 'anular') {
        require_perm('amonestaciones.anular');
        $id = postInt('id');
        $a = qOne("SELECT a.*, e.nombre, e.apellido FROM amonestaciones a JOIN empleados e ON e.id=a.empleado_id WHERE a.id=?", [$id]);
        if ($a) {
            dbUpdate('amonestaciones', ['estado' => 'anulada', 'anulada_por' => current_user()['id'],
                'anulada_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit('amonestaciones', 'anular', 'Amonestación anulada ' . $a['numero'] . ' de ' . $a['nombre'] . ' ' . $a['apellido']
                . ' · motivo: ' . (trim(post('motivo')) ?: 'sin indicar'), ['tabla' => 'amonestaciones', 'registro_id' => $id]);
            flash('success', 'Amonestación anulada. Deja de contar en el historial, pero queda el rastro en la auditoría.');
        }
        redirect('modules/rrhh/amonestaciones.php?ver=' . $id);
    }
}

/* ============================================================
 *  Detalle
 * ============================================================ */
if ($verId) {
    $a = qOne("SELECT a.*, e.nombre, e.apellido, e.cedula, e.fecha_ingreso, e.id AS eid,
                      f.nombre AS falta, d.nombre AS departamento, s.nombre AS sucursal
                 FROM amonestaciones a
                 JOIN empleados e ON e.id = a.empleado_id
                 LEFT JOIN amonestacion_faltas f ON f.id = a.falta_id
                 LEFT JOIN departamentos d ON d.id = e.departamento_id
                 LEFT JOIN sucursales s ON s.id = e.sucursal_id
                WHERE a.id = ?", [$verId]);
    if (!$a) { flash('error', 'Amonestación no encontrada.'); redirect('modules/rrhh/amonestaciones.php'); }

    $cad = amonCaducidad($a['fecha_conocimiento']);
    $est = amonEstados()[$a['estado']] ?? [$a['estado'], 'slate'];
    $gra = amonGravedades()[$a['gravedad']] ?? [$a['gravedad'], 'slate'];
    $hist = amonHistorial((int) $a['eid']);

    $acc = '<a href="' . e(url('modules/rrhh/amonestaciones.php')) . '" class="btn btn-ghost">'
         . icon('arrow-left', 'w-4 h-4') . ' Volver</a>'
         . '<a href="' . e(url('modules/rrhh/amonestacion_doc.php?id=' . $verId)) . '" target="_blank" rel="noopener" class="btn btn-soft">'
         . icon('print', 'w-4 h-4') . ' Imprimir documento</a>';

    layout_start('Amonestación · ' . e($a['numero']),
        trim($a['nombre'] . ' ' . $a['apellido']) . ' · ' . ($a['departamento'] ?: 'sin departamento'), $acc);

    echo kpis([
        ['label' => 'Medida', 'valor' => amonTipos()[$a['tipo']] ?? $a['tipo'], 'icono' => 'shield', 'color' => $gra[1],
         'nota' => 'Gravedad: ' . $gra[0] . ((int) $a['dias_suspension'] > 0 ? ' · ' . (int) $a['dias_suspension'] . ' día(s)' : '')],
        ['label' => 'Estado', 'valor' => $est[0], 'icono' => 'check', 'color' => $est[1],
         'nota' => $a['firmada_at'] ? 'Firmada el ' . fechaCorta($a['firmada_at'])
                 : ($a['notificada_at'] ? 'Notificada el ' . fechaCorta($a['notificada_at']) : 'Sin notificar')],
        // La cuenta atrás del artículo 90.
        ['label' => 'Plazo para ejercer el despido', 'valor' => $cad['etiqueta'], 'icono' => 'clock',
         'color' => $cad['caducado'] ? 'rose' : ($cad['urgente'] ? 'amber' : 'emerald'),
         'nota' => 'Vence el ' . fechaCorta($cad['limite']) . ' · 15 días desde que se supo'],
        ['label' => 'Antecedentes en 12 meses', 'valor' => number_format($hist['vigentes']), 'icono' => 'history',
         'color' => $hist['vigentes'] > 1 ? 'amber' : 'slate',
         'nota' => $hist['dias_suspendido'] > 0 ? $hist['dias_suspendido'] . ' día(s) de suspensión acumulados' : 'Sin suspensiones'],
    ], 4);
    ?>

    <?php if ($cad['caducado'] && $a['estado'] !== 'anulada'): ?>
      <div class="card p-5 mb-5 border-l-4 border-l-rose-500">
        <div class="flex items-start gap-3">
          <?= icon('alert', 'w-5 h-5 text-rose-600 shrink-0 mt-0.5') ?>
          <div>
            <h3 class="font-bold text-slate-800">El plazo para despedir por esta falta ya venció</h3>
            <p class="text-sm text-slate-600 mt-0.5">
              El derecho a ejercer el despido por una causa caduca a los <strong>15 días</strong> de que la empresa
              tuvo conocimiento de ella. Se supo el <strong><?= fechaCorta($a['fecha_conocimiento']) ?></strong> y el
              plazo venció el <strong><?= fechaCorta($cad['limite']) ?></strong>.
              La amonestación sigue valiendo para el expediente y para la progresión; lo que ya no se puede es
              alegar <em>esta</em> falta como causa de despido.
            </p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="lg:col-span-2 space-y-5">
        <div class="card p-5">
          <h3 class="font-bold text-slate-800 mb-3">Hechos</h3>
          <?php if ($a['falta']): ?>
            <p class="mb-2"><?= badge($a['falta'], $gra[1]) ?></p>
          <?php endif; ?>
          <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line"><?= e($a['hechos']) ?></p>
          <?php if ($a['medida']): ?>
            <h4 class="font-bold text-slate-700 mt-4 mb-1 text-sm">Medida adoptada</h4>
            <p class="text-sm text-slate-600 whitespace-pre-line"><?= e($a['medida']) ?></p>
          <?php endif; ?>
          <?php if ($a['referencia_legal']): ?>
            <p class="text-xs text-slate-400 mt-3">Referencia legal: <?= e($a['referencia_legal']) ?></p>
          <?php endif; ?>
        </div>

        <div class="card p-5">
          <h3 class="font-bold text-slate-800 mb-1">Descargo del trabajador</h3>
          <p class="text-sm text-slate-500 mb-3">
            Su versión de los hechos. Que exista es lo que convierte el papel en un expediente y no en una
            acusación de una sola parte.
          </p>
          <?php if ($a['descargo']): ?>
            <div class="rounded-xl bg-slate-50 p-4">
              <p class="text-sm text-slate-700 whitespace-pre-line"><?= e($a['descargo']) ?></p>
              <p class="text-xs text-slate-400 mt-2">Registrado el <?= fechaHora($a['descargo_at']) ?></p>
            </div>
          <?php elseif (can('amonestaciones.crear') && $a['estado'] !== 'anulada'): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="accion" value="descargo">
              <input type="hidden" name="id" value="<?= $verId ?>">
              <textarea name="descargo" rows="3" class="input" required
                        placeholder="Lo que dijo el trabajador sobre los hechos…"></textarea>
              <div class="mt-3 flex justify-end"><button class="btn btn-soft btn-sm"><?= icon('save', 'w-4 h-4') ?> Registrar descargo</button></div>
            </form>
          <?php else: ?>
            <p class="text-sm text-slate-400">Sin descargo registrado.</p>
          <?php endif; ?>
        </div>

        <?php if ($hist['total'] > 1): ?>
          <div class="card overflow-hidden">
            <?= toolbar('<h3 class="font-bold text-slate-800">Historial de los últimos 12 meses</h3>',
                        toolbar_conteo($hist['total'], 'medida')) ?>
            <div class="overflow-x-auto"><table class="data-table">
              <thead><tr><th>Fecha</th><th>Número</th><th>Medida</th><th>Falta</th><th>Estado</th></tr></thead>
              <tbody>
                <?php foreach ($hist['lista'] as $h):
                  $he = amonEstados()[$h['estado']] ?? [$h['estado'], 'slate'];
                  $hg = amonGravedades()[$h['gravedad']] ?? [$h['gravedad'], 'slate']; ?>
                  <tr class="<?= (int) $h['id'] === $verId ? 'bg-blue-50/50' : '' ?>">
                    <td class="text-slate-500 whitespace-nowrap"><?= fechaCorta($h['fecha_emision']) ?></td>
                    <td><a href="?ver=<?= (int) $h['id'] ?>" class="font-semibold text-blue-600 hover:text-blue-700"><?= e($h['numero']) ?></a></td>
                    <td><?= badge(amonTipos()[$h['tipo']] ?? $h['tipo'], $hg[1]) ?></td>
                    <td class="text-slate-500 max-w-xs truncate"><?= e($h['falta'] ?: '—') ?></td>
                    <td><?= badge($he[0], $he[1]) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table></div>
          </div>
        <?php endif; ?>
      </div>

      <div class="space-y-5">
        <div class="card p-5 space-y-3">
          <div><p class="text-xs text-slate-400">Empleado</p>
            <a href="<?= e(url('modules/rrhh/empleado.php?id=' . (int) $a['eid'])) ?>"
               class="font-semibold text-blue-600 hover:text-blue-700"><?= e(trim($a['nombre'] . ' ' . $a['apellido'])) ?></a>
            <p class="text-xs text-slate-400"><?= e($a['cedula'] ?: 'sin cédula') ?></p></div>
          <div><p class="text-xs text-slate-400">Lugar de trabajo</p>
            <p class="text-sm text-slate-700"><?= e($a['sucursal'] ?: '—') ?></p></div>
          <div class="border-t border-slate-100 pt-3">
            <p class="text-xs text-slate-400">El hecho ocurrió</p>
            <p class="text-sm font-semibold text-slate-700"><?= fechaCorta($a['fecha_hecho']) ?></p></div>
          <div><p class="text-xs text-slate-400">La empresa lo supo</p>
            <p class="text-sm font-semibold text-slate-700"><?= fechaCorta($a['fecha_conocimiento']) ?></p>
            <p class="text-[11px] text-slate-400">Desde aquí corren los 15 días</p></div>
          <?php if ($a['suspension_desde']): ?>
            <div><p class="text-xs text-slate-400">Suspensión</p>
              <p class="text-sm font-semibold text-slate-700"><?= fechaCorta($a['suspension_desde']) ?> al <?= fechaCorta($a['suspension_hasta']) ?></p></div>
          <?php endif; ?>
          <div><p class="text-xs text-slate-400">Supervisor</p>
            <p class="text-sm text-slate-700"><?= e($a['supervisor'] ?: '—') ?></p></div>
          <?php if ($a['testigo1']): ?>
            <div class="border-t border-slate-100 pt-3"><p class="text-xs text-slate-400">Testigos de la negativa a firmar</p>
              <p class="text-sm text-slate-700"><?= e($a['testigo1']) ?></p>
              <p class="text-sm text-slate-700"><?= e($a['testigo2']) ?></p></div>
          <?php endif; ?>
        </div>

        <?php if (can('amonestaciones.crear') && $a['estado'] !== 'anulada'): ?>
          <div class="card p-5">
            <h3 class="font-bold text-slate-800 mb-3">Seguimiento</h3>
            <div class="space-y-2">
              <?php if ($a['estado'] === 'borrador'): ?>
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $verId ?>">
                  <input type="hidden" name="estado" value="notificada">
                  <button class="btn btn-primary w-full"><?= icon('check', 'w-4 h-4') ?> Marcar como notificada</button></form>
              <?php endif; ?>
              <?php if (in_array($a['estado'], ['borrador', 'notificada'], true)): ?>
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $verId ?>">
                  <input type="hidden" name="estado" value="firmada">
                  <button class="btn btn-soft w-full"><?= icon('edit', 'w-4 h-4') ?> El empleado firmó</button></form>

                <div x-data="{abrir:false}">
                  <button type="button" @click="abrir=!abrir" class="btn btn-ghost w-full">
                    <?= icon('x', 'w-4 h-4') ?> Se negó a firmar</button>
                  <form method="post" x-show="abrir" x-cloak class="mt-2 space-y-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $verId ?>">
                    <input type="hidden" name="estado" value="rehuso_firmar">
                    <p class="text-xs text-slate-500">Hacen falta <strong>dos testigos</strong>: sin ellos el documento
                      no acredita que se le notificó.</p>
                    <input type="text" name="testigo1" class="input" placeholder="Nombre del primer testigo" required>
                    <input type="text" name="testigo2" class="input" placeholder="Nombre del segundo testigo" required>
                    <button class="btn btn-soft btn-sm w-full">Dejar constancia</button>
                  </form>
                </div>
              <?php endif; ?>
              <?php if (can('amonestaciones.anular')): ?>
                <form method="post" onsubmit="return confirm('<?= e(addslashes('Se anulará ' . $a['numero'] . '. Dejará de contar en el historial, pero el rastro queda en la auditoría.')) ?>')">
                  <?= csrf_field() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= $verId ?>">
                  <input type="text" name="motivo" class="input mb-2" placeholder="Motivo de la anulación">
                  <button class="btn btn-ghost w-full text-rose-600"><?= icon('trash', 'w-4 h-4') ?> Anular</button></form>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php layout_end(); return;
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q     = trim(get('q'));
$fEst  = array_key_exists((string) get('estado'), amonEstados()) ? get('estado') : '';
$fTipo = array_key_exists((string) get('tipo'), amonTipos()) ? get('tipo') : '';

$cond = ['1=1']; $par = [];
if ($q !== '')     { $cond[] = "(a.numero LIKE ? OR e.nombre LIKE ? OR e.apellido LIKE ? OR a.hechos LIKE ?)";
                     array_push($par, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($fEst !== '')  { $cond[] = "a.estado = ?"; $par[] = $fEst; }
if ($fTipo !== '') { $cond[] = "a.tipo = ?"; $par[] = $fTipo; }
$where = implode(' AND ', $cond);
$join = "FROM amonestaciones a JOIN empleados e ON e.id = a.empleado_id
         LEFT JOIN amonestacion_faltas f ON f.id = a.falta_id WHERE $where";

if (export_solicitado()) {
    $rows = qAll("SELECT a.*, e.nombre, e.apellido, e.cedula, f.nombre AS falta $join ORDER BY a.id DESC", $par);
    export_tabla('amonestaciones',
        ['Número', 'Fecha', 'Empleado', 'Cédula', 'Medida', 'Gravedad', 'Falta', 'Hecho', 'Se supo', 'Estado'],
        array_map(fn($r) => [$r['numero'], $r['fecha_emision'], trim($r['nombre'] . ' ' . $r['apellido']),
            $r['cedula'], amonTipos()[$r['tipo']] ?? $r['tipo'], $r['gravedad'], $r['falta'],
            $r['fecha_hecho'], $r['fecha_conocimiento'], $r['estado']], $rows));
}

$pg = paginar((int) qVal("SELECT COUNT(*) $join", $par), 25);
$lista = qAll("SELECT a.*, e.nombre, e.apellido, e.cedula, f.nombre AS falta $join
               ORDER BY a.fecha_emision DESC, a.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $par);

$res = amonResumen();
$faltas = qAll("SELECT * FROM amonestacion_faltas WHERE activo = 1 ORDER BY FIELD(gravedad,'leve','grave','muy_grave'), nombre");
$empleados = qAll("SELECT id, nombre, apellido, cedula FROM empleados WHERE estado='activo' ORDER BY nombre, apellido");

$acciones = export_buttons() . (can('amonestaciones.crear') ? btn_nuevo('amo:new', 'Levantar amonestación') : '');
layout_start('Amonestaciones', 'Régimen disciplinario y expediente del personal', $acciones);

echo kpis([
    ['label' => 'Levantadas este mes', 'valor' => number_format($res['mes']), 'icono' => 'shield', 'color' => 'blue'],
    // El plazo del art. 90 es lo que se pierde por no mirarlo a tiempo.
    ['label' => 'Dentro del plazo de 15 días', 'valor' => number_format($res['por_caducar']), 'icono' => 'clock',
     'color' => $res['por_caducar'] > 0 ? 'amber' : 'slate',
     'nota' => $res['por_caducar'] > 0 ? 'Todavía se puede ejercer el despido' : 'Ninguna en plazo vivo'],
    ['label' => 'Sin notificar', 'valor' => number_format($res['sin_notificar']), 'icono' => 'alert',
     'color' => $res['sin_notificar'] > 0 ? 'rose' : 'emerald',
     'nota' => $res['sin_notificar'] > 0 ? 'Un borrador no notificado no sirve de nada' : 'Todas notificadas',
     'href' => $res['sin_notificar'] > 0 ? '?estado=borrador' : ''],
    ['label' => 'Suspensiones vigentes', 'valor' => number_format($res['suspendidos']), 'icono' => 'lock',
     'color' => $res['suspendidos'] > 0 ? 'violet' : 'slate', 'href' => '?tipo=suspension'],
], 4);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por número, empleado o hechos...', array_filter(['estado' => $fEst ?: null, 'tipo' => $fTipo ?: null])) ?>
    <form method="get" class="flex items-center gap-2">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <select name="tipo" onchange="this.form.submit()" class="select w-44" aria-label="Tipo de medida">
        <option value="">Todas las medidas</option>
        <?php foreach (amonTipos() as $k => $v): ?>
          <option value="<?= $k ?>" <?= $fTipo === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="estado" onchange="this.form.submit()" class="select w-40" aria-label="Estado">
        <option value="">Todos los estados</option>
        <?php foreach (amonEstados() as $k => [$lbl, $_]): ?>
          <option value="<?= $k ?>" <?= $fEst === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!$lista): ?>
    <?= empty_state('Sin amonestaciones', 'Un expediente disciplinario es lo que sostiene una decisión el día que haga falta.', 'shield',
        can('amonestaciones.crear') ? btn_nuevo('amo:new', 'Levantar la primera') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto"><table class="data-table">
      <thead><tr><th>Número</th><th>Empleado</th><th>Medida</th><th>Falta</th>
        <th>Plazo de ley</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($lista as $l):
          $cad = amonCaducidad($l['fecha_conocimiento']);
          $est = amonEstados()[$l['estado']] ?? [$l['estado'], 'slate'];
          $gra = amonGravedades()[$l['gravedad']] ?? [$l['gravedad'], 'slate']; ?>
          <tr>
            <td><p class="font-semibold text-slate-700"><?= e($l['numero']) ?></p>
                <p class="text-xs text-slate-400"><?= fechaCorta($l['fecha_emision']) ?></p></td>
            <td><p class="font-semibold text-slate-700"><?= e(trim($l['nombre'] . ' ' . $l['apellido'])) ?></p>
                <p class="text-xs text-slate-400"><?= e($l['cedula'] ?: '—') ?></p></td>
            <td><?= badge(amonTipos()[$l['tipo']] ?? $l['tipo'], $gra[1]) ?>
                <?php if ((int) $l['dias_suspension'] > 0): ?>
                  <span class="block text-xs text-slate-400 mt-1"><?= (int) $l['dias_suspension'] ?> día(s)</span>
                <?php endif; ?></td>
            <td class="text-slate-500 max-w-xs truncate"><?= e($l['falta'] ?: mb_substr($l['hechos'], 0, 40) . '…') ?></td>
            <td class="whitespace-nowrap <?= $cad['caducado'] ? 'text-slate-400' : ($cad['urgente'] ? 'text-amber-700 font-semibold' : 'text-emerald-700') ?>">
              <?= e($cad['etiqueta']) ?></td>
            <td><?= badge($est[0], $est[1]) ?></td>
            <td><?= acciones([
                  btn_icono(['icono' => 'eye', 'titulo' => 'Ver el expediente',
                             'aria' => 'Ver la amonestación ' . $l['numero'], 'href' => '?ver=' . (int) $l['id']]),
                  btn_icono(['icono' => 'print', 'color' => 'slate', 'titulo' => 'Imprimir el documento',
                             'href' => url('modules/rrhh/amonestacion_doc.php?id=' . (int) $l['id']), 'target' => '_blank']),
                ]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<!-- Modal: nueva amonestación -->
<div x-data="amonNueva()" @amo:new.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-3xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
        <div class="p-5 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Levantar amonestación</h3>
          <p class="text-sm text-slate-500">El plazo de ley empieza a correr desde que la empresa supo del hecho.</p>
        </div>
        <div class="p-5 space-y-4 max-h-[62vh] overflow-y-auto">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="label">Empleado</label>
              <select name="empleado_id" class="select" required>
                <option value="">Elige…</option>
                <?php foreach ($empleados as $e): ?>
                  <option value="<?= (int) $e['id'] ?>"><?= e(trim($e['nombre'] . ' ' . $e['apellido'])) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div><label class="label">Falta</label>
              <select name="falta_id" x-model="faltaId" @change="ajustarGravedad()" class="select">
                <option value="">Otra / sin catalogar</option>
                <?php foreach ($faltas as $f): ?>
                  <option value="<?= (int) $f['id'] ?>" data-gravedad="<?= e($f['gravedad']) ?>"><?= e($f['nombre']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div><label class="label">Cuándo ocurrió</label>
              <input type="date" name="fecha_hecho" x-model="hecho" max="<?= date('Y-m-d') ?>" class="input" required></div>
            <div><label class="label">Cuándo lo supo la empresa</label>
              <input type="date" name="fecha_conocimiento" x-model="conocimiento" max="<?= date('Y-m-d') ?>" class="input" required>
              <p class="text-[11px] mt-1" :class="caducado() ? 'text-rose-600 font-semibold' : 'text-slate-400'"
                 x-text="textoPlazo()"></p></div>
            <div><label class="label">Gravedad</label>
              <select name="gravedad" x-model="gravedad" class="select">
                <?php foreach (amonGravedades() as $k => [$lbl, $_]): ?>
                  <option value="<?= $k ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div><label class="label">Medida</label>
              <select name="tipo" x-model="tipo" class="select">
                <?php foreach (amonTipos() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
              </select></div>
            <div x-show="tipo === 'suspension'"><label class="label">Días de suspensión</label>
              <input type="number" min="1" max="90" name="dias_suspension" value="1" class="input"></div>
            <div x-show="tipo === 'suspension'"><label class="label">Desde</label>
              <input type="date" name="suspension_desde" value="<?= date('Y-m-d') ?>" class="input"></div>
          </div>

          <div><label class="label">Hechos, en concreto</label>
            <textarea name="hechos" rows="4" class="input" required minlength="30"
                      placeholder="Qué pasó, cuándo y dónde. Con nombres, horas y lugar si aplica. «Mala actitud» no sostiene nada."></textarea>
            <p class="text-[11px] text-slate-400 mt-1">Mínimo 30 caracteres. Este texto es el que se imprime y el que se firma.</p></div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="label">Medida adoptada</label>
              <textarea name="medida" rows="2" class="input" placeholder="Qué se le pide o qué consecuencia tiene"></textarea></div>
            <div><label class="label">Referencia legal</label>
              <input type="text" name="referencia_legal" maxlength="180" class="input"
                     placeholder="Artículo / numeral que aplique, o el reglamento interno">
              <p class="text-[11px] text-slate-400 mt-1">Lo llena quien conozca el encuadre exacto; el sistema no lo inventa.</p></div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="label">Supervisor que la levanta</label>
              <input type="text" name="supervisor" maxlength="140" class="input"
                     value="<?= e(current_user()['nombre'] ?? '') ?>"></div>
            <div><label class="label">Notas internas</label>
              <input type="text" name="notas" maxlength="255" class="input" placeholder="No se imprime"></div>
          </div>
        </div>
        <div class="p-5 border-t border-slate-100 flex justify-end gap-2">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Registrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function amonNueva() {
  return {
    open: false, faltaId: '', gravedad: 'leve', tipo: 'escrita',
    hecho: '<?= date('Y-m-d') ?>', conocimiento: '<?= date('Y-m-d') ?>',
    // La gravedad viene del catálogo, pero se puede cambiar: el catálogo
    // orienta, no decide.
    ajustarGravedad() {
      const o = document.querySelector('select[name=falta_id] option[value="' + this.faltaId + '"]');
      if (o && o.dataset.gravedad) this.gravedad = o.dataset.gravedad;
    },
    diasRestantes() {
      if (!this.conocimiento) return null;
      const lim = new Date(this.conocimiento + 'T00:00:00');
      lim.setDate(lim.getDate() + <?= AMON_DIAS_CADUCIDAD ?>);
      const hoy = new Date(new Date().toDateString());
      return Math.floor((lim - hoy) / 86400000);
    },
    caducado() { const d = this.diasRestantes(); return d !== null && d < 0; },
    textoPlazo() {
      const d = this.diasRestantes();
      if (d === null) return '';
      if (d < 0)  return 'El plazo de 15 días para despedir por esta falta ya venció.';
      if (d === 0) return 'El plazo para ejercer el despido vence HOY.';
      return 'Quedan ' + d + ' día(s) para poder ejercer el despido por esta falta.';
    },
  };
}
</script>

<?php layout_end(); ?>
