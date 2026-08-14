<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_nomina.ver');


if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'procesar') {
        require_perm('rrhh_nomina.procesar');
        $descripcion = trim(post('descripcion'));
        $tipo = in_array(post('tipo'), ['mensual', 'quincenal', 'semanal'], true) ? post('tipo') : 'mensual';
        $desde = post('fecha_desde'); $hasta = post('fecha_hasta');
        $sucFiltro = postInt('sucursal_id');
        $sucActiva = current_sucursal_id();
        if ($sucActiva !== null) $sucFiltro = $sucActiva;
        elseif ($sucFiltro > 0) require_sucursal_access($sucFiltro);
        if ($descripcion === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            flash('error', 'Completa descripción y fechas del periodo.');
            redirect('modules/rrhh/nomina.php');
        }
        if ($desde > $hasta) {
            flash('error', 'La fecha inicial no puede ser posterior a la fecha final.');
            redirect('modules/rrhh/nomina.php');
        }
        // Una nómina es de un PERÍODO, no de hoy. Quien entró después de que el
        // período terminara no puede cobrarlo, y quien se fue antes de que
        // empezara tampoco. Antes solo se miraba `estado`, así que una
        // contratación nueva se colaba en la nómina del mes pasado en cuanto
        // alguien la regenerara.
        //
        // Al revés también: quien se fue DENTRO del período cobra su última
        // quincena aunque hoy figure inactivo. Solo si consta su fecha de
        // salida — sin ella no hay forma de saber si le tocaba, y meterlo a
        // ciegas sería pagarle a quien quizá no trabajó.
        $cond = [
            'fecha_ingreso <= ?',
            '(fecha_salida IS NULL OR fecha_salida >= ?)',
            "(estado = 'activo' OR fecha_salida IS NOT NULL)",
        ];
        $params = [$hasta, $desde];
        if ($sucFiltro > 0) { $cond[] = 'sucursal_id = ?'; $params[] = $sucFiltro; }
        $emps = qAll("SELECT * FROM empleados WHERE " . implode(' AND ', $cond), $params);

        // Quién queda fuera y por qué. Un filtro mudo que descarta a media
        // plantilla es peor que no tenerlo: las fechas de ingreso del padrón
        // vienen de una carga y pueden estar sin depurar.
        $fueraIngreso = qAll(
            "SELECT nombre, apellido, fecha_ingreso FROM empleados
              WHERE estado = 'activo' AND fecha_ingreso > ?"
            . ($sucFiltro > 0 ? ' AND sucursal_id = ?' : '') . ' ORDER BY fecha_ingreso, nombre',
            $sucFiltro > 0 ? [$hasta, $sucFiltro] : [$hasta]
        );

        if (!$emps) {
            flash('error', 'Ningún empleado corresponde a ese período.'
                . ($fueraIngreso ? ' Hay ' . count($fueraIngreso) . ' activo(s), pero su fecha de ingreso es'
                    . ' posterior al ' . fechaCorta($hasta) . '. Revisa las fechas de ingreso del padrón.' : ''));
            redirect('modules/rrhh/nomina.php');
        }

        $factor = $tipo === 'quincenal' ? 0.5 : ($tipo === 'semanal' ? (1 / 4.33) : 1);
        try {
            $nid = tx(function () use ($descripcion, $tipo, $desde, $hasta, $sucFiltro, $emps, $factor) {
                $nid = dbInsert('nominas', ['sucursal_id' => $sucFiltro ?: null, 'descripcion' => $descripcion, 'tipo' => $tipo, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta, 'estado' => 'borrador', 'usuario_id' => current_user()['id']]);
                $tb = 0; $td = 0; $tn = 0;
                $diasBase = nominaDiasBase($tipo);
                foreach ($emps as $e) {
                    // Arranca con la jornada completa y todos los conceptos en
                    // cero: la nómina nace en BORRADOR y es en la pantalla donde
                    // se capturan horas extra, comisiones, préstamos y días.
                    //
                    // El salario mensual entra entero: es calcNominaRD() quien lo
                    // parte, porque el ISR necesita ver el mes completo.
                    $c = calcNominaRD((float) $e['salario'],
                        ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase], $factor);
                    dbInsert('nomina_detalles', [
                        'nomina_id' => $nid, 'empleado_id' => $e['id'], 'salario_base' => $c['salarioPeriodo'],
                        'dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
                        'horas_extra' => 0, 'monto_horas_extra' => 0, 'bonificaciones' => 0, 'comisiones' => 0,
                        'otros_ingresos' => 0, 'prima_vacacional' => 0, 'reembolso' => 0,
                        'vacaciones_diferencial' => 0, 'descuento_dias' => 0, 'per_capita' => 0,
                        'total_ingresos' => $c['totalIngresos'],
                        'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'], 'otras_deducciones' => 0,
                        'total_deducciones' => $c['totalDeducciones'], 'salario_neto' => $c['neto'],
                    ]);
                    $tb += $c['totalIngresos']; $td += $c['totalDeducciones']; $tn += $c['neto'];
                }
                dbUpdate('nominas', ['total_bruto' => $tb, 'total_deducciones' => $td, 'total_neto' => $tn], 'id = ?', [$nid]);
                return $nid;
            });
            audit('rrhh_nomina', 'procesar', "Nómina procesada: $descripcion (" . count($emps) . " empleados)", ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash('success', 'Nómina generada para ' . count($emps) . ' empleados. '
                . 'Queda en BORRADOR: captura horas extra, comisiones, préstamos y días antes de confirmarla.');
            if ($fueraIngreso) {
                // Se avisa por nombre: si sobran, quien lea sabrá dónde mirar.
                $quienes = array_slice(array_map(fn($e) => $e['nombre'] . ' ' . $e['apellido'], $fueraIngreso), 0, 6);
                flash('info', count($fueraIngreso) . ' empleado(s) quedaron fuera por haber ingresado después del '
                    . fechaCorta($hasta) . ': ' . implode(', ', $quienes)
                    . (count($fueraIngreso) > 6 ? ' y ' . (count($fueraIngreso) - 6) . ' más' : '') . '.');
            }
            redirect('modules/rrhh/nomina.php?ver=' . $nid);
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
            redirect('modules/rrhh/nomina.php');
        }
    }

    // Captura de conceptos y recálculo. Solo en borrador: una nómina cerrada
    // no se toca, porque ya se declaró y se pagó con esos números.
    if ($accion === 'guardar_conceptos') {
        require_perm('rrhh_nomina.procesar');
        $nid = postInt('id');
        $n = qOne("SELECT * FROM nominas WHERE id = ?", [$nid]);
        if (!$n)                        { flash('error', 'Nómina no encontrada.'); redirect('modules/rrhh/nomina.php'); }
        if ($n['estado'] !== 'borrador') { flash('error', 'Solo se puede editar una nómina en borrador.'); redirect('modules/rrhh/nomina.php?ver=' . $nid); }
        require_sucursal_access($n['sucursal_id']);

        $campos = ['dias_trabajados', 'horas_extra', 'monto_horas_extra', 'prima_vacacional',
                   'otros_ingresos', 'comisiones', 'reembolso', 'vacaciones_diferencial',
                   'bonificaciones', 'descuento_dias', 'per_capita', 'otras_deducciones'];
        $factor = $n['tipo'] === 'quincenal' ? 0.5 : ($n['tipo'] === 'semanal' ? (1 / 4.33) : 1);

        try {
            txReintentable(function () use ($nid, $n, $campos, $factor) {
                $tb = 0; $td = 0; $tn = 0;
                foreach (qAll("SELECT nd.*, e.salario FROM nomina_detalles nd JOIN empleados e ON e.id=nd.empleado_id WHERE nd.nomina_id = ?", [$nid]) as $d) {
                    $vals = [];
                    foreach ($campos as $k) {
                        $v = $_POST[$k][$d['id']] ?? $d[$k];
                        $vals[$k] = max(0.0, round((float) str_replace(',', '', (string) $v), 2));
                    }
                    $vals['dias_base'] = (float) $d['dias_base'] ?: nominaDiasBase($n['tipo']);

                    $c = calcNominaRD((float) $d['salario'], $vals, $factor);
                    dbUpdate('nomina_detalles', $vals + [
                        'salario_base'      => $c['salarioPeriodo'],
                        'total_ingresos'    => $c['totalIngresos'],
                        'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'],
                        'total_deducciones' => $c['totalDeducciones'],
                        'salario_neto'      => $c['neto'],
                    ], 'id = ?', [(int) $d['id']]);
                    $tb += $c['totalIngresos']; $td += $c['totalDeducciones']; $tn += $c['neto'];
                }
                dbUpdate('nominas', ['total_bruto' => $tb, 'total_deducciones' => $td, 'total_neto' => $tn], 'id = ?', [$nid]);
            });
            flash('success', 'Conceptos guardados y nómina recalculada.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/rrhh/nomina.php?ver=' . $nid);
    }

    // Cierra el borrador. A partir de aquí los números no cambian.
    if ($accion === 'confirmar') {
        require_perm('rrhh_nomina.procesar');
        $nid = postInt('id');
        $n = qOne("SELECT * FROM nominas WHERE id = ?", [$nid]);
        if ($n && $n['estado'] === 'borrador') {
            require_sucursal_access($n['sucursal_id']);
            dbUpdate('nominas', ['estado' => 'procesada'], 'id = ?', [$nid]);
            audit('rrhh_nomina', 'procesar', "Nómina confirmada: {$n['descripcion']}", ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash('success', 'Nómina confirmada. Ya no se puede editar.');
        } else {
            flash('error', 'Esta nómina no está en borrador.');
        }
        redirect('modules/rrhh/nomina.php?ver=' . $nid);
    }

    if ($accion === 'pagar') {
        require_perm('rrhh_nomina.pagar');
        $id = postInt('id');
        try {
            tx(function () use ($id) {
                $n = qOne("SELECT * FROM nominas WHERE id=? FOR UPDATE", [$id]);
                if (!$n || $n['estado'] !== 'procesada') throw new RuntimeException('La nómina no se puede pagar.');
                if (!can_access_sucursal($n['sucursal_id'])) throw new RuntimeException('No tienes acceso a la sucursal de esta nómina.');
                dbUpdate('nominas', ['estado' => 'pagada'], 'id=?', [$id]);
                if ((float) $n['total_neto'] > 0) {
                    registrarTransaccion('gasto', (float) $n['total_neto'], ['sucursal_id' => $n['sucursal_id'], 'cuenta_id' => cuentaFinancieraIdPorTipo('efectivo', $n['sucursal_id'] !== null ? (int) $n['sucursal_id'] : null), 'categoria_id' => categoriaFinancieraId('gasto', 'Nómina'), 'descripcion' => 'Pago de nómina: ' . $n['descripcion'], 'referencia_tipo' => 'nomina', 'referencia_id' => $id]);
                }
            });
            audit('rrhh_nomina', 'pagar', "Nómina pagada #$id", ['tabla' => 'nominas', 'registro_id' => $id]);
            flash('success', 'Nómina marcada como pagada y registrada en finanzas.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/rrhh/nomina.php?ver=' . $id);
    }

    if ($accion === 'eliminar') {
        require_perm('rrhh_nomina.procesar');
        $id = postInt('id');
        $n = qOne("SELECT estado, descripcion, sucursal_id FROM nominas WHERE id=?", [$id]);
        if ($n && !can_access_sucursal($n['sucursal_id'])) deny_access();
        if ($n && $n['estado'] !== 'pagada') {
            q("DELETE FROM nominas WHERE id=?", [$id]);
            audit('rrhh_nomina', 'eliminar', "Nómina eliminada: " . ($n['descripcion'] ?? ''), ['tabla' => 'nominas', 'registro_id' => $id]);
            flash('success', 'Nómina eliminada.');
        } else {
            flash('error', 'No se puede eliminar una nómina ya pagada.');
        }
        redirect('modules/rrhh/nomina.php');
    }
}

// ----- Detalle -----
$verId = (int) get('ver');
if ($verId) {
    $n = qOne("SELECT n.*, s.nombre AS sucursal, u.nombre AS usuario FROM nominas n LEFT JOIN sucursales s ON s.id=n.sucursal_id LEFT JOIN usuarios u ON u.id=n.usuario_id WHERE n.id=?", [$verId]);
    if (!$n) { flash('error', 'Nómina no encontrada.'); redirect('modules/rrhh/nomina.php'); }
    require_sucursal_access($n['sucursal_id']);
    $det = qAll("SELECT nd.*, e.nombre, e.apellido, e.cedula, p.nombre AS puesto FROM nomina_detalles nd JOIN empleados e ON e.id=nd.empleado_id LEFT JOIN puestos p ON p.id=e.puesto_id WHERE nd.nomina_id=? ORDER BY e.nombre", [$verId]);
    // Excel con el formato EXACTO de la hoja del cliente (23 columnas, agrupadas
    // por sucursal, con su fila de totales). El PDF sigue siendo el resumen.
    if (quiere_excel()) {
        nominaExportarExcel($n, nominaLineasAgrupadas($verId));
    }
    if (quiere_pdf()) {
        export_tabla('nomina_' . $n['id'], ['Empleado', 'Cédula', 'Puesto', 'Base cotizable', 'AFP', 'SFS', 'ISR', 'Retenciones', 'Neto'],
            array_map(fn($d) => [$d['nombre'] . ' ' . $d['apellido'], $d['cedula'], $d['puesto'], $d['total_ingresos'], $d['afp'], $d['sfs'], $d['isr'], $d['total_deducciones'], $d['salario_neto']], $det));
    }
    // Archivo para subir al banco: solo quien cobra por transferencia.
    if (($_GET['export'] ?? '') === 'banco') {
        nominaExportarBanco($n, nominaLineasAgrupadas($verId));
    }
    $acc = '<a href="' . url('modules/rrhh/nomina.php') . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Volver</a>'
        . '<a href="?ver=' . $verId . '&export=excel" class="btn btn-ghost">' . icon('download', 'w-4 h-4') . ' Excel</a>'
        . '<a href="?ver=' . $verId . '&export=banco" class="btn btn-ghost">' . icon('bank', 'w-4 h-4') . ' Archivo banco</a>'
        . '<a href="?ver=' . $verId . '&export=pdf" target="_blank" class="btn btn-ghost">' . icon('print', 'w-4 h-4') . ' PDF</a>';
    if ($n['estado'] === 'borrador' && can('rrhh_nomina.procesar')) {
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\'Al confirmar, la nómina deja de ser editable. ¿Continuar?\')">'
              . csrf_field() . '<input type="hidden" name="accion" value="confirmar"><input type="hidden" name="id" value="' . $verId . '">'
              . '<button class="btn btn-primary">' . icon('check', 'w-4 h-4') . ' Confirmar nómina</button></form>';
    }
    if ($n['estado'] === 'procesada' && can('rrhh_nomina.pagar')) {
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\'¿Marcar esta nómina como pagada?\')">' . csrf_field() . '<input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="' . $verId . '"><button class="btn btn-success">' . icon('check', 'w-4 h-4') . ' Marcar pagada</button></form>';
    }
    $editable = $n['estado'] === 'borrador' && can('rrhh_nomina.procesar');
    layout_start('Nómina · ' . e($n['descripcion']), 'Periodo ' . fechaCorta($n['fecha_desde']) . ' al ' . fechaCorta($n['fecha_hasta']) . ' · ' . ucfirst($n['tipo']), $acc);
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-5">
      <div class="card p-5"><p class="text-sm text-slate-400">Estado</p><div class="mt-1"><?= badgeFor($n['estado']) ?></div></div>
      <div class="card p-5"><p class="text-sm text-slate-400">Total bruto</p><p class="text-xl font-extrabold text-slate-800 mt-1"><?= money($n['total_bruto']) ?></p></div>
      <div class="card p-5"><p class="text-sm text-slate-400">Deducciones</p><p class="text-xl font-extrabold text-rose-600 mt-1"><?= money($n['total_deducciones']) ?></p></div>
      <div class="card p-5"><p class="text-sm text-slate-400">Total neto a pagar</p><p class="text-xl font-extrabold text-emerald-600 mt-1"><?= money($n['total_neto']) ?></p></div>
    </div>
    <?php if ($editable): ?>
      <div class="card p-4 mb-4 flex items-start gap-3 bg-amber-50 border-amber-200">
        <?= icon('alert', 'w-5 h-5 text-amber-500 mt-0.5 shrink-0') ?>
        <p class="text-sm text-amber-800">
          <strong>Esta nómina está en borrador.</strong> Captura aquí los conceptos del período
          —días pagados, feriados y horas extra, comisiones, incentivos, prima vacacional,
          per-cápita y préstamos— y pulsa <em>Guardar y recalcular</em>. Al confirmarla deja de
          ser editable.
        </p>
      </div>
    <?php endif; ?>
    <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar_conceptos">
    <input type="hidden" name="id" value="<?= $verId ?>">
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="data-table">
          <?php /* En BORRADOR la tabla es un formulario: aquí se capturan los
                   conceptos que el módulo antes no tenía dónde recoger. Cerrada,
                   se muestra en solo lectura. */ ?>
          <thead>
            <tr>
              <th>Empleado</th>
              <?php if ($editable): ?>
                <th class="text-center" title="Días pagados del período">Días</th>
                <th class="text-center" title="Feriado y horas extra (col. H)">Feriado/H.E.</th>
                <th class="text-center" title="Otras remuneraciones (col. I)">Otras rem.</th>
                <th class="text-center" title="Comisiones">Comisión</th>
                <th class="text-center" title="Incentivos (col. L)">Incentivo</th>
                <th class="text-center" title="Prima vacacional (col. G) — se paga, no cotiza">Prima</th>
                <th class="text-center" title="Descuento por días no trabajados (col. M)">Desc. días</th>
                <th class="text-center" title="Per-cápita del plan de salud (col. Q)">Per-cáp.</th>
                <th class="text-center" title="Préstamo / cuentas por cobrar (col. T)">Préstamo</th>
              <?php else: ?>
                <th class="text-right">Base cotizable</th>
              <?php endif; ?>
              <th class="text-right">AFP</th>
              <th class="text-right">SFS</th>
              <th class="text-right">ISR</th>
              <th class="text-right">Retenciones</th>
              <th class="text-right">Neto</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $campoNum = function (string $campo, array $d, string $ancho = 'w-20') {
                  return '<input type="number" step="0.01" min="0" name="' . $campo . '[' . (int) $d['id'] . ']"'
                       . ' value="' . e(rtrim(rtrim(number_format((float) $d[$campo], 2, '.', ''), '0'), '.') ?: '0') . '"'
                       . ' class="' . $ancho . ' px-2 py-1 rounded-lg border border-slate-200 text-sm text-right">';
              };
            ?>
            <?php foreach ($det as $d): ?>
              <tr>
                <td><div class="flex items-center gap-2"><?= avatar($d['nombre'] . ' ' . $d['apellido'], 'w-8 h-8') ?><div><p class="font-semibold text-slate-700"><?= e($d['nombre'] . ' ' . $d['apellido']) ?></p><p class="text-xs text-slate-400"><?= e($d['cedula']) ?> · <?= money($d['salario_base']) ?></p></div></div></td>
                <?php if ($editable): ?>
                  <td class="text-center"><?= $campoNum('dias_trabajados', $d, 'w-16') ?></td>
                  <td class="text-center"><?= $campoNum('monto_horas_extra', $d) ?></td>
                  <td class="text-center"><?= $campoNum('otros_ingresos', $d) ?></td>
                  <td class="text-center"><?= $campoNum('comisiones', $d) ?></td>
                  <td class="text-center"><?= $campoNum('bonificaciones', $d) ?></td>
                  <td class="text-center"><?= $campoNum('prima_vacacional', $d) ?></td>
                  <td class="text-center"><?= $campoNum('descuento_dias', $d) ?></td>
                  <td class="text-center"><?= $campoNum('per_capita', $d) ?></td>
                  <td class="text-center"><?= $campoNum('otras_deducciones', $d) ?></td>
                <?php else: ?>
                  <td class="text-right text-slate-700"><?= money($d['total_ingresos']) ?></td>
                <?php endif; ?>
                <td class="text-right text-slate-500"><?= money($d['afp']) ?></td>
                <td class="text-right text-slate-500"><?= money($d['sfs']) ?></td>
                <td class="text-right text-slate-500"><?= money($d['isr']) ?></td>
                <td class="text-right text-rose-600 font-medium"><?= money($d['total_deducciones']) ?></td>
                <td class="text-right font-bold text-emerald-600"><?= money($d['salario_neto']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td colspan="<?= $editable ? 10 : 2 ?>">TOTALES</td>
              <td colspan="3"></td>
              <td class="text-right text-rose-600"><?= money($n['total_deducciones']) ?></td>
              <td class="text-right text-emerald-600"><?= money($n['total_neto']) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php if ($editable): ?>
        <div class="p-4 border-t border-slate-100 flex justify-end">
          <button class="btn btn-primary"><?= icon('check', 'w-4 h-4') ?> Guardar y recalcular</button>
        </div>
      <?php endif; ?>
    </div>
    </form>
    <?php layout_end(); return;
}

// ----- Listado -----
[$scopeNomina, $paramsNomina] = sucursalScope('n.sucursal_id');
$nominas = qAll("SELECT n.*, s.nombre AS sucursal, (SELECT COUNT(*) FROM nomina_detalles WHERE nomina_id=n.id) AS empleados FROM nominas n LEFT JOIN sucursales s ON s.id=n.sucursal_id WHERE $scopeNomina ORDER BY n.id DESC LIMIT 60", $paramsNomina);
$sucursales = sucursales_visibles();

$acciones = can('rrhh_nomina.procesar') ? '<button onclick="' . jsEvent('nom:new') . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Procesar nómina</button>' : '';
layout_start('Nómina', 'Procesa la nómina con cálculo automático de TSS (AFP/SFS) e ISR', $acciones);
?>

<div class="card overflow-hidden">
  <?php if (!$nominas): ?>
    <?= empty_state('Sin nóminas', 'Procesa tu primera nómina. El sistema calcula AFP, SFS e ISR automáticamente.', 'wallet', $acciones) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Descripción</th><th>Periodo</th><th>Tipo</th><th>Sucursal</th><th class="text-center">Empleados</th><th class="text-right">Neto</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($nominas as $n): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($n['descripcion']) ?></td>
              <td class="text-slate-500 whitespace-nowrap"><?= fechaCorta($n['fecha_desde']) ?> – <?= fechaCorta($n['fecha_hasta']) ?></td>
              <td><?= badge(ucfirst($n['tipo']), 'slate') ?></td>
              <td class="text-slate-500"><?= e($n['sucursal'] ?: 'Todas') ?></td>
              <td class="text-center"><span class="badge badge-blue"><?= (int) $n['empleados'] ?></span></td>
              <td class="text-right font-bold text-slate-800"><?= money($n['total_neto']) ?></td>
              <td><?= badgeFor($n['estado']) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="?ver=<?= (int) $n['id'] ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ver"><?= icon('eye', 'w-4 h-4') ?></a>
                  <?php if (can('rrhh_nomina.procesar') && $n['estado'] !== 'pagada'): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar esta nómina?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button></form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Modal procesar nómina -->
<div x-data="{open:false}" @nom:new.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="procesar">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Procesar nómina</h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
        <div class="p-6 space-y-4">
          <div><label class="label">Descripción *</label><input name="descripcion" required class="input" placeholder="Ej. Nómina <?= e(fechaLarga(date('Y-m-d'))) ?>"></div>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="label">Tipo</label><select name="tipo" class="select"><option value="mensual">Mensual</option><option value="quincenal">Quincenal</option><option value="semanal">Semanal</option></select></div>
            <div><label class="label">Sucursal</label><select name="sucursal_id" class="select"><option value="0">Todas</option><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="label">Desde *</label><input type="date" name="fecha_desde" value="<?= date('Y-m-01') ?>" required class="input"></div>
            <div><label class="label">Hasta *</label><input type="date" name="fecha_hasta" value="<?= date('Y-m-t') ?>" required class="input"></div>
          </div>
          <div class="rounded-xl bg-sky-50 border border-sky-200 p-3 text-xs text-sky-700">El sistema calculará automáticamente AFP (2.87%), SFS (3.04%) e ISR según la escala vigente para cada empleado activo.</div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" class="btn btn-primary"><?= icon('wallet', 'w-4 h-4') ?> Procesar</button></div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
