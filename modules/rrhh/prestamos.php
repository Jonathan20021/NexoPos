<?php
/**
 * Préstamos y avances a empleados.
 *
 * El módulo existe para que un préstamo deje de ser un número suelto en la
 * columna «otras deducciones» de cada quincena y pase a tener lo que tiene un
 * préstamo: monto, cuotas, calendario, saldo vivo, autorización firmada y
 * descuento automático en la nómina del período que toca.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('prestamos.ver');

if (!presDisponible()) {
    layout_start('Préstamos', 'Falta aplicar la migración');
    echo empty_state('Módulo no instalado',
        'Aplica database/migracion_prestamos_p23.sql para activar los préstamos a empleados.', 'wallet');
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
        require_perm('prestamos.crear');
        $empId  = postInt('empleado_id');
        $monto  = round(max(0.0, postNum('monto')), 2);
        $cuotas = max(1, postInt('cuotas'));
        $tasa   = max(0.0, postNum('tasa_anual'));
        $tipo   = array_key_exists(post('tipo'), presTipos()) ? post('tipo') : 'prestamo';
        $per    = array_key_exists(post('periodicidad'), presPeriodicidades()) ? post('periodicidad') : 'quincenal';
        $desem  = post('fecha_desembolso') ?: date('Y-m-d');
        $primera= post('fecha_primera_cuota') ?: date('Y-m-d', strtotime($desem . ' +15 days'));

        $e = qOne("SELECT id, nombre, apellido, salario FROM empleados WHERE id = ? AND estado = 'activo'", [$empId]);
        try {
            if (!$e)          throw new RuntimeException('Elige un empleado activo.');
            if ($monto <= 0)  throw new RuntimeException('El monto tiene que ser mayor que cero.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desem) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $primera)) {
                throw new RuntimeException('Revisa las fechas.');
            }
            // Un avance de sueldo es, por definición, una sola cuota sin interés.
            if ($tipo === 'avance') { $cuotas = 1; $tasa = 0.0; }

            $plan = presAmortizar($monto, $cuotas, $tasa, $per, $primera);
            $cuotaTipica = $plan[0]['total'];

            // El tope legal se mide sobre el NETO, después de TSS e ISR.
            $legal = presCabeLegal((int) $e['id'], $cuotaTipica, $per);
            $forzar = post('forzar_tope') === '1';
            $motivoForzar = trim(post('excede_motivo'));
            if (!$legal['cabe'] && !$forzar) {
                throw new RuntimeException(
                    'La cuota de ' . money($cuotaTipica, false) . ' pasa del tope legal ('
                    . $legal['tope_pct'] . '% del neto = ' . money($legal['tope_monto'], false) . ').'
                    . ' Alarga el plazo, baja el monto, o marca la casilla de autorización excepcional escribiendo el motivo.');
            }
            if (!$legal['cabe'] && $motivoForzar === '') {
                throw new RuntimeException('Para pasar del tope legal hay que escribir por qué. Queda en el expediente.');
            }

            $id = tx(function () use ($e, $tipo, $monto, $tasa, $cuotas, $per, $desem, $primera, $plan, $legal, $forzar, $motivoForzar) {
                $numero = nextNumero('prestamos', 'numero', $tipo === 'avance' ? 'AVA' : 'PRE');
                $id = dbInsert('prestamos', [
                    'numero' => $numero, 'empleado_id' => (int) $e['id'], 'tipo' => $tipo,
                    'monto' => $monto, 'tasa_anual' => $tasa, 'cuotas' => $cuotas, 'periodicidad' => $per,
                    'fecha_desembolso' => $desem, 'fecha_primera_cuota' => $primera,
                    'motivo' => trim(post('motivo')) ?: null,
                    'saldo' => $monto, 'estado' => 'activo',
                    'excede_tope' => $legal['cabe'] ? 0 : 1,
                    'excede_motivo' => $legal['cabe'] ? null : $motivoForzar,
                    'notas' => trim(post('notas')) ?: null,
                    'usuario_id' => current_user()['id'],
                ]);
                foreach ($plan as $c) {
                    dbInsert('prestamo_cuotas', [
                        'prestamo_id' => $id, 'numero' => $c['numero'], 'fecha_prevista' => $c['fecha'],
                        'capital' => $c['capital'], 'interes' => $c['interes'], 'total' => $c['total'],
                        'saldo_despues' => $c['saldo'],
                    ]);
                }
                return $id;
            });
            audit('prestamos', 'crear', 'Préstamo otorgado a ' . $e['nombre'] . ' ' . $e['apellido']
                . ' por ' . money($monto, false) . ' en ' . $cuotas . ' cuota(s)'
                . ($legal['cabe'] ? '' : ' · POR ENCIMA DEL TOPE LEGAL: ' . $motivoForzar),
                ['tabla' => 'prestamos', 'registro_id' => $id]);
            flash('success', 'Préstamo registrado. Falta que el empleado AUTORICE el descuento: '
                . 'hasta entonces la nómina no le retiene nada.');
            redirect('modules/rrhh/prestamos.php?ver=' . $id);
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
            redirect('modules/rrhh/prestamos.php');
        }
    }

    /* ---------- Autorizar el descuento ----------
       El Código de Trabajo exige el consentimiento del trabajador para retenerle
       algo que no sea obligatorio. Sin esta marca, presCuotaDelPeriodo() no
       devuelve nada y la nómina no descuenta. */
    if ($accion === 'autorizar') {
        require_perm('prestamos.crear');
        $id = postInt('id');
        $p = qOne("SELECT p.*, e.nombre, e.apellido FROM prestamos p JOIN empleados e ON e.id=p.empleado_id WHERE p.id=?", [$id]);
        if ($p && $p['estado'] === 'activo') {
            dbUpdate('prestamos', ['autorizado' => 1, 'autorizado_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit('prestamos', 'editar', 'Descuento autorizado · ' . $p['numero'] . ' de ' . $p['nombre'] . ' ' . $p['apellido'],
                ['tabla' => 'prestamos', 'registro_id' => $id]);
            flash('success', 'Autorización registrada. La próxima nómina ya descuenta la cuota que toque.');
        } else {
            flash('error', 'Solo se puede autorizar un préstamo activo.');
        }
        redirect('modules/rrhh/prestamos.php?ver=' . $id);
    }

    if ($accion === 'anular') {
        require_perm('prestamos.anular');
        $id = postInt('id');
        $p = qOne("SELECT p.*, e.nombre, e.apellido FROM prestamos p JOIN empleados e ON e.id=p.empleado_id WHERE p.id=?", [$id]);
        try {
            if (!$p) throw new RuntimeException('Préstamo no encontrado.');
            $cobradas = (int) qVal("SELECT COUNT(*) FROM prestamo_cuotas WHERE prestamo_id=? AND estado='descontada'", [$id]);
            // Anular NO borra lo ya cobrado: eso salió de una nómina confirmada
            // y esa nómina no se reescribe. Solo se cancela lo que queda.
            tx(function () use ($id) {
                q("UPDATE prestamo_cuotas SET estado='condonada' WHERE prestamo_id=? AND estado='pendiente'", [$id]);
                dbUpdate('prestamos', ['estado' => 'anulado', 'saldo' => 0,
                    'anulado_por' => current_user()['id'], 'anulado_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            });
            audit('prestamos', 'anular', 'Préstamo anulado ' . $p['numero'] . ' de ' . $p['nombre'] . ' ' . $p['apellido']
                . ' · saldo condonado ' . money((float) $p['saldo'], false)
                . ($cobradas ? " · $cobradas cuota(s) ya cobradas se mantienen" : ''),
                ['tabla' => 'prestamos', 'registro_id' => $id]);
            flash('success', 'Préstamo anulado. Se condonó el saldo pendiente'
                . ($cobradas ? '; las ' . $cobradas . ' cuota(s) ya cobradas se mantienen, porque salieron de nóminas confirmadas.' : '.'));
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
        }
        redirect('modules/rrhh/prestamos.php?ver=' . $id);
    }

    if ($accion === 'configurar') {
        require_perm('prestamos.configurar');
        $cfg = qOne("SELECT id FROM prestamo_config ORDER BY id LIMIT 1");
        $datos = [
            'tope_pct_neto' => max(0.0, min(100.0, postNum('tope_pct_neto'))),
            'neto_minimo_protegido' => max(0.0, postNum('neto_minimo_protegido')),
            'exige_autorizacion' => post('exige_autorizacion') ? 1 : 0,
            'notas' => trim(post('notas')) ?: null,
            'updated_at' => date('Y-m-d H:i:s'), 'usuario_id' => current_user()['id'],
        ];
        if ($cfg) dbUpdate('prestamo_config', $datos, 'id = ?', [(int) $cfg['id']]);
        else      dbInsert('prestamo_config', $datos);
        audit('prestamos', 'editar', 'Tope legal de descuento: ' . $datos['tope_pct_neto'] . '% del neto',
            ['tabla' => 'prestamo_config', 'registro_id' => (int) ($cfg['id'] ?? 0)]);
        flash('success', 'Límite de descuento guardado.');
        redirect('modules/rrhh/prestamos.php');
    }
}

/* ============================================================
 *  Detalle de un préstamo
 * ============================================================ */
if ($verId) {
    $p = qOne("SELECT p.*, e.nombre, e.apellido, e.cedula, e.salario, e.id AS eid,
                      d.nombre AS departamento
                 FROM prestamos p JOIN empleados e ON e.id = p.empleado_id
                 LEFT JOIN departamentos d ON d.id = e.departamento_id
                WHERE p.id = ?", [$verId]);
    if (!$p) { flash('error', 'Préstamo no encontrado.'); redirect('modules/rrhh/prestamos.php'); }

    $cuotas = qAll("SELECT * FROM prestamo_cuotas WHERE prestamo_id = ? ORDER BY numero", [$verId]);
    $pagadas = array_filter($cuotas, fn($c) => $c['estado'] === 'descontada');
    $totalInteres = array_sum(array_map(fn($c) => (float) $c['interes'], $cuotas));
    $estado = presEstados()[$p['estado']] ?? [$p['estado'], 'slate'];

    $acc = '<a href="' . e(url('modules/rrhh/prestamos.php')) . '" class="btn btn-ghost">'
         . icon('arrow-left', 'w-4 h-4') . ' Volver</a>';
    if ($p['estado'] === 'activo' && !$p['autorizado'] && can('prestamos.crear')) {
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\''
              . e(addslashes('Confirmas que ' . $p['nombre'] . ' ' . $p['apellido']
                . ' autorizó por escrito el descuento de este préstamo en su nómina.')) . '\')">'
              . csrf_field() . '<input type="hidden" name="accion" value="autorizar"><input type="hidden" name="id" value="' . $verId . '">'
              . '<button class="btn btn-primary">' . icon('check', 'w-4 h-4') . ' Registrar autorización</button></form>';
    }
    if ($p['estado'] === 'activo' && can('prestamos.anular')) {
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\''
              . e(addslashes('Se anulará ' . $p['numero'] . ' y se CONDONARÁ el saldo de '
                . money((float) $p['saldo'], false) . '. Lo ya cobrado se mantiene.')) . '\')">'
              . csrf_field() . '<input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="' . $verId . '">'
              . '<button class="btn btn-soft">' . icon('x', 'w-4 h-4') . ' Anular y condonar</button></form>';
    }

    layout_start(presTipos()[$p['tipo']] . ' · ' . e($p['numero']),
        trim($p['nombre'] . ' ' . $p['apellido']) . ' · ' . ($p['departamento'] ?: 'sin departamento'), $acc);

    echo kpis([
        ['label' => 'Monto otorgado', 'valor' => money($p['monto']), 'icono' => 'wallet', 'color' => 'blue',
         'nota' => fechaCorta($p['fecha_desembolso'])],
        ['label' => 'Saldo pendiente', 'valor' => money($p['saldo']), 'icono' => 'dollar',
         'color' => (float) $p['saldo'] > 0 ? 'amber' : 'emerald',
         'nota' => count($pagadas) . ' de ' . count($cuotas) . ' cuotas cobradas'],
        ['label' => 'Cuota', 'valor' => money($cuotas ? $cuotas[0]['total'] : 0), 'icono' => 'history',
         'color' => 'violet', 'nota' => presPeriodicidades()[$p['periodicidad']]
            . ((float) $p['tasa_anual'] > 0 ? ' · ' . rtrim(rtrim(number_format((float) $p['tasa_anual'], 3, '.', ''), '0'), '.') . '% anual' : ' · sin interés')],
        ['label' => 'Estado', 'valor' => $estado[0], 'icono' => 'shield', 'color' => $estado[1],
         'nota' => $p['autorizado'] ? 'Descuento autorizado' : 'SIN autorizar: no se descuenta'],
    ], 4);
    ?>

    <?php if (!$p['autorizado'] && $p['estado'] === 'activo'): ?>
      <div class="card p-5 mb-5 border-l-4 border-l-amber-400">
        <div class="flex items-start gap-3">
          <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0 mt-0.5') ?>
          <div>
            <h3 class="font-bold text-slate-800">Falta la autorización del empleado</h3>
            <p class="text-sm text-slate-600 mt-0.5">
              El Código de Trabajo exige el consentimiento del trabajador para retenerle del salario algo que
              no sea obligatorio. <strong>Mientras no se registre, la nómina no descuenta ninguna cuota.</strong>
              Imprime el documento, recoge la firma y registra la autorización.
            </p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ((int) $p['excede_tope'] === 1): ?>
      <div class="card p-5 mb-5 border-l-4 border-l-rose-500">
        <div class="flex items-start gap-3">
          <?= icon('alert', 'w-5 h-5 text-rose-600 shrink-0 mt-0.5') ?>
          <div>
            <h3 class="font-bold text-slate-800">Se otorgó por encima del tope de descuento</h3>
            <p class="text-sm text-slate-600 mt-0.5">Motivo registrado: <em><?= e($p['excede_motivo'] ?: '—') ?></em></p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="card overflow-hidden lg:col-span-2">
        <?= toolbar('<h3 class="font-bold text-slate-800">Cuadro de amortización</h3>',
                    toolbar_conteo(count($cuotas), 'cuota')) ?>
        <div class="overflow-x-auto"><table class="data-table">
          <thead><tr><th>#</th><th>Vence</th><th class="text-right">Capital</th><th class="text-right">Interés</th>
            <th class="text-right">Cuota</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($cuotas as $c):
              $atrasada = $c['estado'] === 'pendiente' && $c['fecha_prevista'] < date('Y-m-d'); ?>
              <tr class="<?= $atrasada ? 'bg-amber-50/60' : '' ?>">
                <td class="font-semibold text-slate-700"><?= (int) $c['numero'] ?></td>
                <td class="text-slate-500 whitespace-nowrap"><?= fechaCorta($c['fecha_prevista']) ?></td>
                <td class="text-right"><?= money($c['capital'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($c['interes'], false) ?></td>
                <td class="text-right font-bold text-slate-800"><?= money($c['total'], false) ?></td>
                <td class="text-right text-slate-400"><?= money($c['saldo_despues'], false) ?></td>
                <td><?php
                  if ($c['estado'] === 'descontada') echo badge('Cobrada', 'emerald');
                  elseif ($c['estado'] === 'condonada') echo badge('Condonada', 'slate');
                  elseif ($atrasada) echo badge('Atrasada', 'amber');
                  else echo badge('Pendiente', 'sky');
                ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="bg-slate-50 font-bold text-slate-800">
            <td colspan="2">TOTALES</td>
            <td class="text-right"><?= money($p['monto'], false) ?></td>
            <td class="text-right"><?= money($totalInteres, false) ?></td>
            <td class="text-right"><?= money((float) $p['monto'] + $totalInteres, false) ?></td>
            <td colspan="2"></td>
          </tr></tfoot>
        </table></div>
      </div>

      <div class="card p-5 h-fit space-y-3">
        <div><p class="text-xs text-slate-400">Empleado</p>
          <a href="<?= e(url('modules/rrhh/empleado.php?id=' . (int) $p['eid'])) ?>"
             class="font-semibold text-blue-600 hover:text-blue-700"><?= e(trim($p['nombre'] . ' ' . $p['apellido'])) ?></a>
          <p class="text-xs text-slate-400"><?= e($p['cedula'] ?: 'sin cédula') ?></p></div>
        <div><p class="text-xs text-slate-400">Salario mensual</p>
          <p class="font-semibold text-slate-700"><?= money($p['salario']) ?></p></div>
        <div><p class="text-xs text-slate-400">Motivo</p>
          <p class="text-sm text-slate-700"><?= e($p['motivo'] ?: '—') ?></p></div>
        <?php if ($p['autorizado_at']): ?>
          <div><p class="text-xs text-slate-400">Autorizado</p>
            <p class="text-sm text-slate-700"><?= fechaHora($p['autorizado_at']) ?></p></div>
        <?php endif; ?>
        <?php if ($p['notas']): ?>
          <div class="border-t border-slate-100 pt-3"><p class="text-xs text-slate-400">Notas</p>
            <p class="text-sm text-slate-600"><?= nl2br(e($p['notas'])) ?></p></div>
        <?php endif; ?>
        <div class="border-t border-slate-100 pt-3">
          <a href="<?= e(url('modules/rrhh/prestamo_doc.php?id=' . $verId)) ?>" target="_blank" rel="noopener"
             class="btn btn-soft w-full"><?= icon('print', 'w-4 h-4') ?> Autorización de descuento</a>
        </div>
      </div>
    </div>

    <?php layout_end(); return;
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q      = trim(get('q'));
$fEst   = array_key_exists((string) get('estado'), presEstados()) ? get('estado') : '';
$cond = ['1=1']; $par = [];
if ($q !== '')    { $cond[] = "(p.numero LIKE ? OR e.nombre LIKE ? OR e.apellido LIKE ? OR e.cedula LIKE ?)";
                    array_push($par, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($fEst !== '') { $cond[] = "p.estado = ?"; $par[] = $fEst; }
$where = implode(' AND ', $cond);

$join = "FROM prestamos p JOIN empleados e ON e.id = p.empleado_id WHERE $where";
if (export_solicitado()) {
    $rows = qAll("SELECT p.*, e.nombre, e.apellido, e.cedula $join ORDER BY p.id DESC", $par);
    export_tabla('prestamos', ['Número', 'Tipo', 'Empleado', 'Cédula', 'Monto', 'Cuotas', 'Cuota', 'Saldo', 'Desembolso', 'Estado', 'Autorizado'],
        array_map(fn($r) => [$r['numero'], presTipos()[$r['tipo']] ?? $r['tipo'],
            trim($r['nombre'] . ' ' . $r['apellido']), $r['cedula'], $r['monto'], $r['cuotas'],
            round((float) $r['monto'] / max(1, (int) $r['cuotas']), 2), $r['saldo'],
            $r['fecha_desembolso'], $r['estado'], $r['autorizado'] ? 'Sí' : 'No'], $rows));
}

$pg = paginar((int) qVal("SELECT COUNT(*) $join", $par), 25);
$lista = qAll("SELECT p.*, e.nombre, e.apellido, e.cedula,
                      (SELECT COUNT(*) FROM prestamo_cuotas c WHERE c.prestamo_id=p.id AND c.estado='descontada') cobradas,
                      (SELECT MIN(c.fecha_prevista) FROM prestamo_cuotas c WHERE c.prestamo_id=p.id AND c.estado='pendiente') proxima
               $join ORDER BY p.estado='activo' DESC, p.id DESC
               LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $par);

$res = presResumen();
$cfg = presConfig();
$empleados = qAll("SELECT id, nombre, apellido, cedula, salario FROM empleados WHERE estado='activo' ORDER BY nombre, apellido");

$acciones = export_buttons() . (can('prestamos.crear') ? btn_nuevo('pre:new', 'Nuevo préstamo') : '');
layout_start('Préstamos a empleados', 'Avances y préstamos con descuento por nómina', $acciones);

echo kpis([
    ['label' => 'Préstamos activos', 'valor' => number_format($res['activos']), 'icono' => 'wallet', 'color' => 'blue',
     'href' => '?estado=activo'],
    ['label' => 'Saldo por cobrar', 'valor' => money($res['saldo']), 'icono' => 'dollar',
     'color' => $res['saldo'] > 0 ? 'amber' : 'emerald', 'nota' => 'Lo que le deben a la empresa'],
    ['label' => 'A descontar este mes', 'valor' => money($res['cuota_mes']), 'icono' => 'history', 'color' => 'violet',
     'nota' => $res['atrasadas'] > 0
        ? number_format($res['atrasadas']) . ' cuota(s) atrasadas' : 'Al día'],
    // Un préstamo sin autorización no retiene NADA. Si hay varios así, alguien
    // está esperando un descuento que no está ocurriendo.
    ['label' => 'Sin autorizar', 'valor' => number_format($res['sin_autorizar']), 'icono' => 'alert',
     'color' => $res['sin_autorizar'] > 0 ? 'rose' : 'emerald',
     'nota' => $res['sin_autorizar'] > 0 ? 'No se les descuenta nada' : 'Todos autorizados'],
], 4);
?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por número, nombre o cédula...', array_filter(['estado' => $fEst ?: null])) ?>
    <div class="flex items-center gap-1.5 flex-wrap">
      <a href="?" class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $fEst === '' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">Todos</a>
      <?php foreach (presEstados() as $k => [$lbl, $_]): ?>
        <a href="?estado=<?= $k ?>" class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $fEst === $k ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$lista): ?>
    <?= empty_state('Sin préstamos', 'Un préstamo aquí se descuenta solo en la nómina del período que toca, sin que nadie tenga que acordarse.', 'wallet',
        can('prestamos.crear') ? btn_nuevo('pre:new', 'Otorgar el primero') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto"><table class="data-table">
      <thead><tr><th>Número</th><th>Empleado</th><th class="text-right">Monto</th><th class="text-center">Cuotas</th>
        <th class="text-right">Saldo</th><th>Próxima</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($lista as $l):
          $est = presEstados()[$l['estado']] ?? [$l['estado'], 'slate'];
          $atras = $l['proxima'] && $l['proxima'] < date('Y-m-d') && $l['estado'] === 'activo'; ?>
          <tr>
            <td><p class="font-semibold text-slate-700"><?= e($l['numero']) ?></p>
                <p class="text-xs text-slate-400"><?= e(presTipos()[$l['tipo']] ?? $l['tipo']) ?></p></td>
            <td><p class="font-semibold text-slate-700"><?= e(trim($l['nombre'] . ' ' . $l['apellido'])) ?></p>
                <p class="text-xs text-slate-400"><?= e($l['cedula'] ?: '—') ?></p></td>
            <td class="text-right"><?= money($l['monto']) ?></td>
            <td class="text-center"><span class="badge badge-slate"><?= (int) $l['cobradas'] ?>/<?= (int) $l['cuotas'] ?></span></td>
            <td class="text-right font-bold <?= (float) $l['saldo'] > 0 ? 'text-amber-700' : 'text-slate-400' ?>"><?= money($l['saldo']) ?></td>
            <td class="<?= $atras ? 'text-amber-700 font-semibold' : 'text-slate-500' ?> whitespace-nowrap">
              <?= $l['proxima'] ? fechaCorta($l['proxima']) . ($atras ? ' ⚠' : '') : '—' ?></td>
            <td><?= badge($est[0], $est[1]) ?>
                <?php if ($l['estado'] === 'activo' && !$l['autorizado']): ?>
                  <span class="block mt-1"><?= badge('Sin autorizar', 'rose') ?></span>
                <?php endif; ?></td>
            <td><?= acciones([
                  btn_icono(['icono' => 'eye', 'titulo' => 'Ver el cuadro de amortización',
                             'aria' => 'Ver el préstamo ' . $l['numero'],
                             'href' => '?ver=' . (int) $l['id']]),
                  btn_icono(['icono' => 'print', 'color' => 'slate', 'titulo' => 'Autorización de descuento',
                             'href' => url('modules/rrhh/prestamo_doc.php?id=' . (int) $l['id']), 'target' => '_blank']),
                ]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<?php if (can('prestamos.configurar')): ?>
<form method="post" class="card p-5 mt-5">
  <?= csrf_field() ?><input type="hidden" name="accion" value="configurar">
  <h3 class="font-bold text-slate-800 mb-1">Límite legal de descuento</h3>
  <p class="text-sm text-slate-500 mb-4">
    El Código de Trabajo protege el salario: lo obligatorio —TSS e ISR— va primero, y lo voluntario solo puede
    salir de lo que queda. Por eso el tope se mide sobre el <strong>neto</strong>, no sobre el bruto.
    El porcentaje exacto debe confirmarlo el abogado del cliente.
  </p>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div><label class="label">Tope, % del neto</label>
      <input type="number" step="0.01" min="0" max="100" name="tope_pct_neto"
             value="<?= e(rtrim(rtrim(number_format((float) $cfg['tope_pct_neto'], 2, '.', ''), '0'), '.')) ?>" class="input"></div>
    <div><label class="label">Neto mínimo protegido</label>
      <input type="number" step="0.01" min="0" name="neto_minimo_protegido"
             value="<?= e(rtrim(rtrim(number_format((float) $cfg['neto_minimo_protegido'], 2, '.', ''), '0'), '.')) ?>" class="input">
      <p class="text-[11px] text-slate-400 mt-1">Por debajo de esto no se descuenta, aunque el % lo permita. 0 = sin suelo.</p></div>
    <div class="flex items-end">
      <label class="flex items-center gap-2 cursor-pointer pb-2">
        <input type="checkbox" name="exige_autorizacion" value="1" <?= (int) $cfg['exige_autorizacion'] ? 'checked' : '' ?>
               class="w-4 h-4 rounded border-slate-300">
        <span class="text-sm font-semibold text-slate-700">Exigir autorización firmada</span></label>
    </div>
  </div>
  <div class="mt-4"><label class="label">Notas</label>
    <textarea name="notas" rows="2" class="input"><?= e($cfg['notas'] ?? '') ?></textarea></div>
  <div class="mt-4 flex justify-end"><button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar límite</button></div>
</form>
<?php endif; ?>

<!-- Modal: nuevo préstamo -->
<div x-data="prestamoNuevo()" @pre:new.window="abrir()" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-3xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
        <div class="p-5 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nuevo préstamo o avance</h3>
          <p class="text-sm text-slate-500">El cuadro de cuotas y el control del tope legal se calculan mientras escribes.</p>
        </div>
        <div class="p-5 space-y-4 max-h-[60vh] overflow-y-auto">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="label">Empleado</label>
              <select name="empleado_id" x-model="empleadoId" class="select" required>
                <option value="">Elige…</option>
                <?php foreach ($empleados as $e): ?>
                  <option value="<?= (int) $e['id'] ?>" data-salario="<?= e($e['salario']) ?>">
                    <?= e(trim($e['nombre'] . ' ' . $e['apellido'])) ?> — <?= money($e['salario'], false) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div><label class="label">Tipo</label>
              <select name="tipo" x-model="tipo" class="select">
                <?php foreach (presTipos() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div><label class="label">Monto</label>
              <input type="number" step="0.01" min="1" name="monto" x-model.number="monto" class="input" required></div>
            <div><label class="label">Cuotas</label>
              <input type="number" min="1" max="120" name="cuotas" x-model.number="cuotas"
                     :disabled="tipo === 'avance'" class="input"></div>
            <div><label class="label">Tasa anual %</label>
              <input type="number" step="0.001" min="0" name="tasa_anual" x-model.number="tasa"
                     :disabled="tipo === 'avance'" class="input"></div>
            <div><label class="label">Periodicidad</label>
              <select name="periodicidad" x-model="periodicidad" class="select">
                <?php foreach (presPeriodicidades() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="label">Fecha de desembolso</label>
              <input type="date" name="fecha_desembolso" x-model="desembolso" class="input" required></div>
            <div><label class="label">Primera cuota</label>
              <input type="date" name="fecha_primera_cuota" x-model="primera" class="input" required></div>
          </div>
          <div><label class="label">Motivo</label>
            <input type="text" name="motivo" maxlength="255" class="input" placeholder="Salud, estudios, emergencia familiar…"></div>

          <div class="rounded-xl bg-slate-50 p-4" x-show="monto > 0">
            <div class="flex items-baseline justify-between">
              <span class="text-sm text-slate-500">Cuota estimada</span>
              <span class="text-2xl font-extrabold text-slate-800" x-text="fmt(cuotaEstimada())"></span>
            </div>
            <p class="text-xs text-slate-400 mt-1"
               x-text="cuotas + ' cuota(s) ' + (periodicidad === 'mensual' ? 'mensuales' : 'quincenales') + ' · total a devolver ' + fmt(totalDevolver())"></p>
            <template x-if="salario() > 0">
              <p class="text-xs mt-2" :class="pasaTope() ? 'text-rose-600 font-semibold' : 'text-emerald-700'"
                 x-text="pasaTope()
                    ? 'La cuota pasa del ' + tope + '% del neto estimado. Hace falta autorización excepcional.'
                    : 'Dentro del ' + tope + '% del neto estimado.'"></p>
            </template>
          </div>

          <template x-if="pasaTope()">
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 space-y-3">
              <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="forzar_tope" value="1" class="mt-0.5 w-4 h-4 rounded border-rose-300">
                <span class="text-sm text-rose-900"><strong>Autorizar por encima del tope</strong>
                  <span class="block text-rose-700">Queda marcado en el expediente del préstamo y en la auditoría.</span></span>
              </label>
              <input type="text" name="excede_motivo" maxlength="255" class="input"
                     placeholder="Por qué se autoriza pasar del tope">
            </div>
          </template>

          <div><label class="label">Notas</label><textarea name="notas" rows="2" class="input"></textarea></div>
        </div>
        <div class="p-5 border-t border-slate-100 flex justify-end gap-2">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Otorgar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function prestamoNuevo() {
  return {
    open: false, empleadoId: '', tipo: 'prestamo', monto: 0, cuotas: 4, tasa: 0,
    periodicidad: 'quincenal', desembolso: '<?= date('Y-m-d') ?>',
    primera: '<?= date('Y-m-d', strtotime('+15 days')) ?>',
    tope: <?= (float) $cfg['tope_pct_neto'] ?>,
    abrir() { this.open = true; },
    salario() {
      const o = document.querySelector('select[name=empleado_id] option[value="' + this.empleadoId + '"]');
      return o ? parseFloat(o.dataset.salario || 0) : 0;
    },
    // Solo para orientar mientras se escribe. El cálculo que MANDA es el del
    // servidor, con la nómina real: aquí no se conocen ni el ISR ni los otros
    // préstamos vivos de la persona.
    netoEstimado() {
      const s = this.salario();
      const periodo = this.periodicidad === 'mensual' ? s : s / 2;
      return periodo * 0.9409;
    },
    n() { return this.tipo === 'avance' ? 1 : Math.max(1, parseInt(this.cuotas) || 1); },
    cuotaEstimada() {
      const m = parseFloat(this.monto) || 0, n = this.n();
      const t = this.tipo === 'avance' ? 0 : (parseFloat(this.tasa) || 0);
      if (m <= 0) return 0;
      if (t <= 0) return m / n;
      const i = (t / 100) / (this.periodicidad === 'mensual' ? 12 : 24);
      return m * i / (1 - Math.pow(1 + i, -n));
    },
    totalDevolver() { return this.cuotaEstimada() * this.n(); },
    pasaTope() {
      const neto = this.netoEstimado();
      return neto > 0 && this.cuotaEstimada() > neto * (this.tope / 100);
    },
    fmt(v) { return 'RD$ ' + (v || 0).toLocaleString('es-DO', {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
  };
}
</script>

<?php layout_end(); ?>
