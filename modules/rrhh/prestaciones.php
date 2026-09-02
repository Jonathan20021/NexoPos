<?php
/**
 * Liquidación de prestaciones laborales.
 *
 * Calcula preaviso, cesantía, vacaciones y regalía proporcional de quien sale,
 * aplicando las escalas del Código de Trabajo (ver includes/prestaciones.php),
 * deja corregir cada renglón, y guarda el papel que se firma.
 *
 * Todo queda CONGELADO en la fila al guardar —fecha de ingreso, sueldo, salario
 * diario, días de cada concepto— porque la ficha del empleado cambia y un
 * documento firmado no puede cambiar con ella.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('prestaciones.ver');

if (!plab_disponible()) {
    layout_start('Prestaciones laborales', 'Falta aplicar la migración');
    echo '<div class="card p-6">' . empty_state('Módulo no instalado',
        'Aplica database/migracion_prestaciones_p30.sql para habilitar la liquidación de prestaciones.', 'alert') . '</div>';
    layout_end();
    exit;
}

$verId = (int) get('ver');
$nuevo = get('nuevo') !== '' || (int) get('empleado') > 0;

/* ============================================================
 *  Acciones
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    try {
        if ($accion === 'guardar') {
            require_perm('prestaciones.crear');
            $eid   = postInt('empleado_id');
            $causa = (string) post('causa');
            $fsal  = (string) post('fecha_salida');
            $e = qOne("SELECT * FROM empleados WHERE id = ?", [$eid]);
            if (!$e) throw new RuntimeException('Empleado no encontrado.');
            if (!array_key_exists($causa, plab_causas())) throw new RuntimeException('Elige la causa de la salida.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fsal)) throw new RuntimeException('La fecha de salida no es válida.');
            // Sin fecha de ingreso no hay antigüedad, y sin antigüedad no hay
            // preaviso ni cesantía que valgan: es el dato del que cuelga todo.
            if (!$e['fecha_ingreso']) throw new RuntimeException(
                'Este empleado no tiene fecha de ingreso. Corrígela en su ficha antes de liquidarlo: '
                . 'de ella salen el preaviso, la cesantía y las vacaciones.');
            if ($fsal < $e['fecha_ingreso']) throw new RuntimeException('La salida no puede ser anterior al ingreso.');

            $id = txReintentable(function () use ($e, $eid, $causa, $fsal) {
                // Se recalcula del lado del servidor: los DÍAS de cada concepto
                // salen de la ley, no del formulario. Lo único que se acepta del
                // navegador son los MONTOS, que sí se negocian.
                $calc = plab_calcular($e, $causa, $fsal);
                $num = nextNumero('prestaciones', 'numero', 'LIQ');

                $preM = round((float) post('preaviso_monto'), 2);
                $cesM = round((float) post('cesantia_monto'), 2);
                $vacM = round((float) post('vacaciones_monto'), 2);
                $regM = round((float) post('regalia_monto'), 2);
                $penM = round((float) post('salario_pendiente'), 2);
                $othM = round((float) post('otros_monto'), 2);
                $dedM = round((float) post('deducciones'), 2);
                $total = round($preM + $cesM + $vacM + $regM + $penM + $othM - $dedM, 2);

                return dbInsert('prestaciones', [
                    'numero' => $num, 'empleado_id' => $eid, 'causa' => $causa,
                    'fecha_ingreso' => $e['fecha_ingreso'], 'fecha_salida' => $fsal,
                    'dias_servicio' => $calc['dias_servicio'],
                    'salario_mensual' => $calc['salario'], 'salario_diario' => $calc['diario'],
                    'preaviso_dias' => $calc['renglones']['preaviso']['dias'],   'preaviso_monto' => $preM,
                    'cesantia_dias' => $calc['renglones']['cesantia']['dias'],   'cesantia_monto' => $cesM,
                    'vacaciones_dias' => $calc['renglones']['vacaciones']['dias'], 'vacaciones_monto' => $vacM,
                    'regalia_monto' => $regM,
                    'salario_pendiente' => $penM,
                    'otros_monto' => $othM, 'otros_concepto' => trim(post('otros_concepto')) ?: null,
                    'deducciones' => $dedM, 'deducciones_concepto' => trim(post('deducciones_concepto')) ?: null,
                    'total' => $total, 'estado' => 'borrador',
                    'notas' => trim(post('notas')) ?: null,
                    'usuario_id' => current_user()['id'] ?? null,
                ]);
            });
            audit('prestaciones', 'crear', 'Liquidación de ' . $e['nombre'] . ' ' . $e['apellido'],
                ['tabla' => 'prestaciones', 'registro_id' => $id]);
            flash('success', 'Liquidación guardada como borrador. Revísala e imprímela para firmar.');
            redirect('modules/rrhh/prestaciones.php?ver=' . $id);
        }

        if (in_array($accion, ['firmar', 'pagar', 'anular'], true)) {
            $id = postInt('id');
            $l = qOne("SELECT * FROM prestaciones WHERE id = ?", [$id]);
            if (!$l) throw new RuntimeException('Liquidación no encontrada.');

            if ($accion === 'firmar') {
                require_perm('prestaciones.crear');
                if ($l['estado'] !== 'borrador') throw new RuntimeException('Solo se firma un borrador.');
                dbUpdate('prestaciones', ['estado' => 'firmada'], 'id = ?', [$id]);
                flash('success', 'Marcada como firmada. Guarda el documento en el expediente.');
            }
            if ($accion === 'pagar') {
                require_perm('prestaciones.pagar');
                if ($l['estado'] !== 'firmada') throw new RuntimeException('Se paga después de firmada.');
                $cuentaId = postInt('cuenta_id') ?: null;
                $monto    = (float) $l['total'];

                // Una liquidación es dinero que sale, y hasta ahora se marcaba
                // pagada sin dejar rastro en el libro de caja: un millón y medio
                // de pesos que no aparecían en el resultado ni descontaban de
                // ninguna cuenta. Es el mismo agujero que la nómina ya cerraba.
                $aviso = txReintentable(function () use ($id, $l, $cuentaId, $monto) {
                    dbUpdate('prestaciones', ['estado' => 'pagada', 'pagada_at' => date('Y-m-d H:i:s'),
                        'cuenta_id' => $cuentaId], 'id = ?', [$id]);
                    if ($monto <= 0) return '';

                    $cuenta = $cuentaId
                        ? qOne("SELECT * FROM cuentas_financieras WHERE id = ? AND activo = 1 FOR UPDATE", [$cuentaId])
                        : null;
                    if ($cuentaId && !$cuenta) throw new RuntimeException('La cuenta elegida no existe o está inactiva.');

                    registrarTransaccion('gasto', $monto, [
                        'sucursal_id'     => null,
                        'cuenta_id'       => $cuenta ? (int) $cuenta['id'] : null,
                        'categoria_id'    => categoriaFinancieraId('gasto', 'Prestaciones laborales'),
                        'descripcion'     => 'Liquidación de prestaciones ' . $l['numero'],
                        'referencia_tipo' => 'prestacion',
                        'referencia_id'   => $id,
                    ]);

                    // Se avisa si la cuenta queda en rojo, no se bloquea: puede
                    // faltar el saldo de apertura tanto como estar mal elegida.
                    if ($cuenta && (float) $cuenta['balance'] - $monto < -0.01) {
                        return 'La cuenta «' . $cuenta['nombre'] . '» queda en '
                             . money((float) $cuenta['balance'] - $monto) . '. Revisa si es la cuenta correcta '
                             . 'o si falta cargarle el saldo de apertura.';
                    }
                    return '';
                });
                flash('success', 'Liquidación marcada como pagada y registrada en finanzas.');
                if ($aviso) flash('warning', $aviso);
            }
            if ($accion === 'anular') {
                require_perm('prestaciones.anular');
                if ($l['estado'] === 'pagada') throw new RuntimeException('Una liquidación pagada no se anula: revierte el pago primero.');
                dbUpdate('prestaciones', ['estado' => 'anulada'], 'id = ?', [$id]);
                flash('success', 'Liquidación anulada.');
            }
            audit('prestaciones', $accion, 'Liquidación ' . $l['numero'], ['tabla' => 'prestaciones', 'registro_id' => $id]);
            redirect('modules/rrhh/prestaciones.php?ver=' . $id);
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('modules/rrhh/prestaciones.php' . ($verId ? '?ver=' . $verId : ''));
    }
}

/* ============================================================
 *  Ver una liquidación guardada
 * ============================================================ */
if ($verId > 0) {
    $l = qOne("SELECT l.*, e.nombre, e.apellido, e.cedula, e.codigo,
                      pu.nombre AS puesto, dep.nombre AS departamento, su.nombre AS sucursal
                 FROM prestaciones l
                 JOIN empleados e ON e.id = l.empleado_id
                 LEFT JOIN puestos pu        ON pu.id  = e.puesto_id
                 LEFT JOIN departamentos dep ON dep.id = e.departamento_id
                 LEFT JOIN sucursales su     ON su.id  = e.sucursal_id
                WHERE l.id = ?", [$verId]);
    if (!$l) { flash('error', 'Liquidación no encontrada.'); redirect('modules/rrhh/prestaciones.php'); }
    $causas = plab_causas();

    $acc = '<a href="' . e(url('modules/rrhh/prestaciones.php')) . '" class="btn btn-ghost">'
         . icon('arrow-left', 'w-4 h-4') . ' Volver</a>'
         . '<a href="' . e(url('modules/rrhh/prestacion_doc.php?id=' . $verId)) . '" target="_blank" class="btn btn-ghost">'
         . icon('print', 'w-4 h-4') . ' Documento para firmar</a>';
    if ($l['estado'] === 'borrador' && can('prestaciones.crear')) {
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\''
              . e(addslashes('Se marcará como firmada la liquidación ' . $l['numero'] . ' por ' . money($l['total'], false) . '.')) . '\')">'
              . csrf_field() . '<input type="hidden" name="accion" value="firmar"><input type="hidden" name="id" value="' . $verId . '">'
              . '<button class="btn btn-primary">' . icon('check', 'w-4 h-4') . ' Marcar firmada</button></form>';
    }
    if ($l['estado'] === 'firmada' && can('prestaciones.pagar')) {
        $cuentas = qAll("SELECT id, nombre, tipo, balance FROM cuentas_financieras WHERE activo = 1 ORDER BY tipo='banco' DESC, nombre");
        $opts = '';
        foreach ($cuentas as $c) $opts .= '<option value="' . (int) $c['id'] . '">' . e($c['nombre']) . ' · ' . money($c['balance']) . '</option>';
        $acc .= '<form method="post" class="inline-flex items-center gap-2">' . csrf_field()
              . '<input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="' . $verId . '">'
              . ($cuentas ? '<select name="cuenta_id" class="select py-1.5 text-sm max-w-[17rem]" aria-label="Cuenta">' . $opts . '</select>' : '')
              . '<button class="btn btn-success">' . icon('check', 'w-4 h-4') . ' Marcar pagada</button></form>';
    }
    if ($l['estado'] !== 'pagada' && $l['estado'] !== 'anulada' && can('prestaciones.anular')) {
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\''
              . e(addslashes('Se anulará la liquidación ' . $l['numero'] . '.')) . '\')">'
              . csrf_field() . '<input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="' . $verId . '">'
              . '<button class="btn btn-ghost">' . icon('x', 'w-4 h-4') . ' Anular</button></form>';
    }

    layout_start('Liquidación ' . e($l['numero']),
        e(trim($l['nombre'] . ' ' . $l['apellido'])) . ' · salida ' . fechaCorta($l['fecha_salida']), $acc);

    $reng = [
        ['Preaviso (art. 76)',                     (float) $l['preaviso_dias'],   (float) $l['preaviso_monto']],
        ['Auxilio de cesantía (art. 80)',          (float) $l['cesantia_dias'],   (float) $l['cesantia_monto']],
        ['Vacaciones no disfrutadas (art. 177)',   (float) $l['vacaciones_dias'], (float) $l['vacaciones_monto']],
        ['Regalía pascual proporcional (art. 219)', null,                         (float) $l['regalia_monto']],
        ['Salario pendiente',                       null,                         (float) $l['salario_pendiente']],
        [$l['otros_concepto'] ?: 'Otros conceptos', null,                         (float) $l['otros_monto']],
    ];
    ?>
    <?= kpis([
        ['label' => 'Total a pagar', 'valor' => money($l['total']), 'icono' => 'cash',
         'color' => 'emerald', 'nota' => $causas[$l['causa']]['label'] ?? $l['causa']],
        ['label' => 'Antigüedad', 'valor' => number_format((int) $l['dias_servicio']) . ' días',
         'icono' => 'calendar', 'color' => 'blue',
         'nota' => number_format((int) $l['dias_servicio'] / 365, 2) . ' año(s) · desde ' . fechaCorta($l['fecha_ingreso'])],
        ['label' => 'Salario diario', 'valor' => money($l['salario_diario']), 'icono' => 'coins',
         'color' => 'indigo', 'nota' => money($l['salario_mensual'], false) . ' ÷ ' . PLAB_DIVISOR_DIARIO],
        ['label' => 'Estado', 'valor' => ucfirst($l['estado']), 'icono' => 'file',
         'color' => $l['estado'] === 'pagada' ? 'emerald' : ($l['estado'] === 'anulada' ? 'rose' : 'amber'),
         'nota' => $l['pagada_at'] ? 'Pagada el ' . fechaCorta($l['pagada_at']) : 'Documento ' . e($l['numero'])],
    ], 4) ?>

    <div class="card overflow-hidden mb-5">
      <?= toolbar('<h3 class="font-bold text-slate-800">Lo que se le paga</h3>') ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>Concepto</th><th class="text-center">Días</th><th class="text-right">Monto</th></tr></thead>
          <tbody>
            <?php foreach ($reng as [$lbl, $d, $m]): if ($m == 0 && $d == 0) continue; ?>
              <tr>
                <td class="text-slate-700"><?= e($lbl) ?></td>
                <td class="text-center text-slate-500 tabular-nums"><?= $d === null ? '—' : qty($d) ?></td>
                <td class="text-right font-medium text-slate-800 tabular-nums"><?= money($m, false) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ((float) $l['deducciones'] > 0): ?>
              <tr>
                <td class="text-rose-700"><?= e($l['deducciones_concepto'] ?: 'Deducciones') ?></td>
                <td class="text-center text-slate-400">—</td>
                <td class="text-right font-medium text-rose-600 tabular-nums">−<?= money($l['deducciones'], false) ?></td>
              </tr>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td colspan="2">Total</td>
              <td class="text-right tabular-nums"><?= money($l['total'], false) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php if ($l['notas']): ?>
        <div class="px-5 py-3 border-t border-slate-100 text-sm text-slate-600"><?= nl2br(e($l['notas'])) ?></div>
      <?php endif; ?>
    </div>
    <?php
    layout_end();
    exit;
}

/* ============================================================
 *  Calculadora de una liquidación nueva
 * ============================================================ */
if ($nuevo) {
    $eid   = (int) get('empleado');
    $causa = array_key_exists((string) get('causa'), plab_causas()) ? (string) get('causa') : 'desahucio_empleador';
    $fsal  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) get('fecha_salida')) ? (string) get('fecha_salida') : date('Y-m-d');
    $e     = $eid > 0 ? qOne("SELECT * FROM empleados WHERE id = ?", [$eid]) : null;

    layout_start('Nueva liquidación', 'Preaviso, cesantía, vacaciones y regalía proporcional',
        '<a href="' . e(url('modules/rrhh/prestaciones.php')) . '" class="btn btn-ghost">'
        . icon('arrow-left', 'w-4 h-4') . ' Volver</a>');
    ?>

    <form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
      <input type="hidden" name="nuevo" value="1">
      <div class="flex-1 min-w-[18rem]">
        <label class="label" for="empleado">Empleado</label>
        <select id="empleado" name="empleado" class="select">
          <option value="0">Elige a la persona…</option>
          <?php foreach (qAll("SELECT id, nombre, apellido, cedula, estado FROM empleados ORDER BY estado='activo' DESC, nombre, apellido") as $x): ?>
            <option value="<?= (int) $x['id'] ?>" <?= $eid === (int) $x['id'] ? 'selected' : '' ?>>
              <?= e($x['nombre'] . ' ' . $x['apellido']) ?> · <?= e($x['cedula'] ?: 's/c') ?><?= $x['estado'] !== 'activo' ? ' (inactivo)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="min-w-[20rem]">
        <label class="label" for="causa">Causa de la salida</label>
        <select id="causa" name="causa" class="select">
          <?php foreach (plab_causas() as $k => $c): ?>
            <option value="<?= $k ?>" <?= $causa === $k ? 'selected' : '' ?>><?= e($c['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label" for="fecha_salida">Último día de trabajo</label>
        <input type="date" id="fecha_salida" name="fecha_salida" value="<?= e($fsal) ?>" class="input">
      </div>
      <button class="btn btn-primary"><?= icon('scale', 'w-4 h-4') ?> Calcular</button>
    </form>

    <?php if (!$e): ?>
      <div class="card p-6"><?= empty_state('Elige a la persona',
          'El cálculo sale de su fecha de ingreso, su sueldo y las nóminas del año.', 'users') ?></div>
      <?php layout_end(); exit; ?>
    <?php endif; ?>

    <?php
    $calc = plab_calcular($e, $causa, $fsal);
    $saldoPrestamo = presResumen((int) $e['id'])['saldo'] ?? 0.0;
    $vacTomadas = qAll(
        "SELECT fecha_desde, fecha_hasta, dias, estado, tipo FROM vacaciones
          WHERE empleado_id = ? AND estado IN ('aprobada','disfrutada') ORDER BY fecha_desde DESC LIMIT 8",
        [(int) $e['id']]);
    ?>

    <?php if ($calc['sin_ingreso']): ?>
      <div class="card p-4 mb-5 flex items-start gap-3 bg-rose-50 border-rose-200">
        <?= icon('alert', 'w-5 h-5 text-rose-600 mt-0.5 shrink-0') ?>
        <p class="text-sm text-rose-900">
          <strong>Esta persona no tiene fecha de ingreso en su ficha.</strong>
          De ahí salen la antigüedad, el preaviso, la cesantía y las vacaciones: sin ella no se puede liquidar.
          <a href="<?= e(url('modules/rrhh/empleado.php?id=' . (int) $e['id'])) ?>" class="underline font-semibold">Corregirla</a>.
        </p>
      </div>
    <?php endif; ?>

    <?= kpis([
        ['label' => 'Antigüedad', 'valor' => number_format($calc['dias_servicio']) . ' días',
         'icono' => 'calendar', 'color' => 'blue',
         'nota' => number_format($calc['anios_servicio'], 2) . ' año(s) desde ' . ($calc['fecha_ingreso'] ? fechaCorta($calc['fecha_ingreso']) : '—')],
        ['label' => 'Salario diario', 'valor' => money($calc['diario']), 'icono' => 'coins', 'color' => 'indigo',
         'nota' => money($calc['salario'], false) . ' ÷ ' . PLAB_DIVISOR_DIARIO . ' (divisor legal)'],
        ['label' => 'Total calculado', 'valor' => money($calc['total']), 'icono' => 'cash', 'color' => 'emerald',
         'nota' => 'Antes de pendientes y deducciones'],
        ['label' => 'Saldo de préstamo', 'valor' => money($saldoPrestamo), 'icono' => 'wallet',
         'color' => $saldoPrestamo > 0 ? 'amber' : 'slate',
         'nota' => $saldoPrestamo > 0 ? 'Se puede compensar con la liquidación' : 'No debe nada'],
    ], 4) ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="empleado_id" value="<?= (int) $e['id'] ?>">
      <input type="hidden" name="causa" value="<?= e($causa) ?>">
      <input type="hidden" name="fecha_salida" value="<?= e($fsal) ?>">

      <div class="card overflow-hidden mb-5">
        <?= toolbar(
            '<h3 class="font-bold text-slate-800">' . e($calc['causa_label']) . '</h3>'
            . '<p class="text-xs text-slate-400 mt-0.5">' . e($calc['causa_ayuda']) . '</p>') ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr>
              <th>Concepto</th><th>De dónde sale</th>
              <th class="text-center">Días</th><th class="text-right">Monto</th>
            </tr></thead>
            <tbody>
              <?php foreach ($calc['renglones'] as $k => $r): ?>
                <tr class="<?= $r['aplica'] ? '' : 'opacity-50' ?>">
                  <td class="font-semibold text-slate-700"><?= e($r['label']) ?></td>
                  <td class="text-sm text-slate-500 max-w-[22rem]"><?= e($r['regla']) ?></td>
                  <td class="text-center text-slate-500 tabular-nums"><?= $r['dias'] === null ? '—' : qty($r['dias']) ?></td>
                  <td class="text-right">
                    <input type="number" step="0.01" min="0" name="<?= $k ?>_monto"
                           value="<?= e(number_format((float) $r['monto'], 2, '.', '')) ?>"
                           class="w-32 px-2 py-1 rounded-lg border border-slate-200 text-sm text-right
                                  focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none">
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td class="font-semibold text-slate-700">Salario pendiente</td>
                <td class="text-sm text-slate-500">Días trabajados del período en curso que aún no se pagaron</td>
                <td class="text-center text-slate-300">—</td>
                <td class="text-right">
                  <input type="number" step="0.01" min="0" name="salario_pendiente" value="0.00"
                         class="w-32 px-2 py-1 rounded-lg border border-slate-200 text-sm text-right">
                </td>
              </tr>
              <tr>
                <td class="font-semibold text-slate-700">
                  <input type="text" name="otros_concepto" placeholder="Otro concepto…" maxlength="160"
                         class="w-full px-2 py-1 rounded-lg border border-slate-200 text-sm">
                </td>
                <td class="text-sm text-slate-500">Vacaciones de años anteriores, bonificación acordada…</td>
                <td class="text-center text-slate-300">—</td>
                <td class="text-right">
                  <input type="number" step="0.01" min="0" name="otros_monto" value="0.00"
                         class="w-32 px-2 py-1 rounded-lg border border-slate-200 text-sm text-right">
                </td>
              </tr>
              <tr class="bg-rose-50/40">
                <td class="font-semibold text-rose-800">
                  <input type="text" name="deducciones_concepto" maxlength="200"
                         value="<?= $saldoPrestamo > 0 ? 'Saldo de préstamo pendiente' : '' ?>"
                         placeholder="Concepto de la deducción…"
                         class="w-full px-2 py-1 rounded-lg border border-rose-200 text-sm">
                </td>
                <td class="text-sm text-rose-700">
                  <?php if ($saldoPrestamo > 0): ?>
                    Viene precargado el saldo de su préstamo (<?= money($saldoPrestamo, false) ?>): la
                    autorización de descuento que firmó permite compensarlo con la liquidación.
                  <?php else: ?>
                    Lo que se le descuenta: adelantos, faltantes aceptados, lo que se acuerde.
                  <?php endif; ?>
                </td>
                <td class="text-center text-slate-300">—</td>
                <td class="text-right">
                  <input type="number" step="0.01" min="0" name="deducciones"
                         value="<?= e(number_format($saldoPrestamo, 2, '.', '')) ?>"
                         class="w-32 px-2 py-1 rounded-lg border border-rose-200 text-sm text-right">
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="px-5 py-4 border-t border-slate-100">
          <label class="label" for="notas">Notas del acuerdo</label>
          <textarea id="notas" name="notas" rows="2" maxlength="500" class="input"
                    placeholder="Lo que se conversó y quedó acordado. Sale impreso en el documento."></textarea>
        </div>
      </div>

      <?php if ($vacTomadas): ?>
        <div class="card overflow-hidden mb-5">
          <?= toolbar('<h3 class="font-bold text-slate-800">Vacaciones registradas</h3>',
              '<span class="text-xs text-slate-400">Para saber qué ya disfrutó antes de pagar días de más</span>') ?>
          <div class="overflow-x-auto">
            <table class="data-table">
              <thead><tr><th>Desde</th><th>Hasta</th><th class="text-center">Días</th><th class="text-center">Estado</th></tr></thead>
              <tbody>
                <?php foreach ($vacTomadas as $v): ?>
                  <tr>
                    <td class="text-slate-600"><?= e(fechaCorta($v['fecha_desde'])) ?></td>
                    <td class="text-slate-600"><?= e(fechaCorta($v['fecha_hasta'])) ?></td>
                    <td class="text-center text-slate-700 font-medium"><?= (int) $v['dias'] ?></td>
                    <td class="text-center"><?= badgeFor($v['estado']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <?php if (can('prestaciones.crear') && !$calc['sin_ingreso']): ?>
        <div class="flex items-center gap-2 mb-5 no-print">
          <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar la liquidación</button>
          <span class="text-sm text-slate-500">Queda en borrador; después se imprime, se firma y se paga.</span>
        </div>
      <?php endif; ?>
    </form>

    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-2">Las escalas que se aplicaron</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5 text-sm text-slate-600">
        <div>
          <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Preaviso · art. 76</p>
          <ul class="space-y-0.5"><li>3 a 6 meses: 7 días</li><li>6 a 12 meses: 14 días</li><li>1 año o más: 28 días</li></ul>
        </div>
        <div>
          <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Cesantía · art. 80</p>
          <ul class="space-y-0.5"><li>3 a 6 meses: 6 días</li><li>6 a 12 meses: 13 días</li>
            <li>1 a 5 años: 21 días por año</li><li>más de 5 años: 23 días por año</li></ul>
        </div>
        <div>
          <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">Vacaciones · art. 177</p>
          <ul class="space-y-0.5"><li>1 a 5 años: 14 días laborables</li><li>5 años o más: 18 días laborables</li>
            <li class="text-slate-400">Proporcional al año de servicio en curso</li></ul>
        </div>
      </div>
      <p class="text-sm text-slate-500 mt-4 pt-3 border-t border-slate-100">
        La fracción de año en la cesantía se paga proporcional y el tramo se aplica a todos los años, que es como
        lo calcula el Ministerio de Trabajo. <strong>Cada renglón se puede corregir</strong>: si el abogado laboral
        usa otro criterio, manda el suyo. El sistema deja constancia de lo que se pagó, no decide la negociación.
      </p>
    </div>

    <?php layout_end(); exit;
}

/* ============================================================
 *  Listado
 * ============================================================ */
$pg = paginar((int) qVal("SELECT COUNT(*) FROM prestaciones"), 25);
$lista = qAll(
    "SELECT l.*, e.nombre, e.apellido, e.cedula
       FROM prestaciones l JOIN empleados e ON e.id = l.empleado_id
      ORDER BY l.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}");
$causas = plab_causas();

$acciones = can('prestaciones.crear')
    ? '<a href="' . e(url('modules/rrhh/prestaciones.php?nuevo=1')) . '" class="btn btn-primary">'
      . icon('plus', 'w-4 h-4') . ' Nueva liquidación</a>' : '';
layout_start('Prestaciones laborales',
    'Preaviso, cesantía, vacaciones y regalía proporcional de quien sale', $acciones);
?>

<div class="card overflow-hidden">
  <?= toolbar('<h3 class="font-bold text-slate-800">Liquidaciones</h3>', toolbar_conteo($pg['total'], 'liquidación', 'liquidaciones')) ?>
  <?php if (!$lista): ?>
    <div class="p-6"><?= empty_state('Todavía no hay liquidaciones',
        'Cuando alguien salga, aquí queda el cálculo y el documento que se firmó.', 'file', $acciones) ?></div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Documento</th><th>Empleado</th><th>Causa</th>
          <th class="text-center">Antigüedad</th><th class="text-right">Total</th>
          <th class="text-center">Estado</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($lista as $l): ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($l['numero']) ?></p>
                <p class="text-xs text-slate-400">Salida <?= e(fechaCorta($l['fecha_salida'])) ?></p>
              </td>
              <td class="whitespace-nowrap">
                <p class="text-slate-700"><?= e(trim($l['nombre'] . ' ' . $l['apellido'])) ?></p>
                <p class="text-xs text-slate-400"><?= e($l['cedula'] ?: '—') ?></p>
              </td>
              <td class="text-sm text-slate-600 max-w-[16rem]"><?= e($causas[$l['causa']]['label'] ?? $l['causa']) ?></td>
              <td class="text-center text-slate-600 tabular-nums">
                <?= number_format((int) $l['dias_servicio'] / 365, 2) ?> año(s)
              </td>
              <td class="text-right font-bold text-slate-800 tabular-nums"><?= money($l['total'], false) ?></td>
              <td class="text-center"><?= badgeFor($l['estado']) ?></td>
              <td class="text-right">
                <a href="<?= e(url('modules/rrhh/prestaciones.php?ver=' . (int) $l['id'])) ?>" class="btn btn-ghost btn-sm">Ver</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pg['totalPag'] > 1): ?><div class="px-5 pb-4"><?= paginacion($pg) ?></div><?php endif; ?>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
