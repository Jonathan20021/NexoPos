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
                    // Cuotas de préstamo que vencen dentro del período: entran
                    // solas en la columna «préstamo». Antes había que acordarse
                    // de teclearlas cada quincena, y la que se olvidaba no se
                    // cobraba nunca.
                    $cuota = presCuotaDelPeriodo((int) $e['id'], $desde, $hasta);
                    $c = calcNominaRD((float) $e['salario'],
                        ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
                         'otras_deducciones' => $cuota], $factor);
                    dbInsert('nomina_detalles', [
                        'nomina_id' => $nid, 'empleado_id' => $e['id'], 'salario_base' => $c['salarioPeriodo'],
                        'dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
                        'horas_extra' => 0, 'monto_horas_extra' => 0, 'bonificaciones' => 0, 'comisiones' => 0,
                        'otros_ingresos' => 0, 'prima_vacacional' => 0, 'reembolso' => 0,
                        'vacaciones_diferencial' => 0, 'descuento_dias' => 0, 'per_capita' => 0,
                        'total_ingresos' => $c['totalIngresos'],
                        'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'], 'otras_deducciones' => $c['prestamo'],
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

    /* ---------------------------------------------------------------------
     *  Editar la cabecera del período
     * ------------------------------------------------------------------ */
    if ($accion === 'editar_cabecera') {
        require_perm('rrhh_nomina.procesar');
        $nid = postInt('id');
        $n = qOne("SELECT * FROM nominas WHERE id = ?", [$nid]);
        if (!$n)                         { flash('error', 'Nómina no encontrada.'); redirect('modules/rrhh/nomina.php'); }
        if ($n['estado'] !== 'borrador') { flash('error', 'Solo se puede editar una nómina en borrador.'); redirect('modules/rrhh/nomina.php?ver=' . $nid); }
        require_sucursal_access($n['sucursal_id']);

        $descripcion = trim(post('descripcion'));
        $desde = post('fecha_desde'); $hasta = post('fecha_hasta');
        if ($descripcion === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            flash('error', 'Completa descripción y fechas del periodo.');
        } elseif ($desde > $hasta) {
            flash('error', 'La fecha inicial no puede ser posterior a la fecha final.');
        } else {
            // Las fechas se pueden corregir, pero NO se recalcula solo: cambiar
            // el período con conceptos ya capturados obligaría a decidir qué
            // hacer con ellos. Se avisa y que la persona lo revise.
            dbUpdate('nominas', ['descripcion' => $descripcion, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta], 'id = ?', [$nid]);
            audit('rrhh_nomina', 'editar', "Cabecera de nómina actualizada: $descripcion", ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash('success', 'Período actualizado.');
            if ($desde !== $n['fecha_desde'] || $hasta !== $n['fecha_hasta']) {
                flash('info', 'Cambiaste las fechas. Revisa si alguien entró o salió dentro del período nuevo: '
                    . 'las líneas ya creadas no se tocan solas.');
            }
        }
        redirect('modules/rrhh/nomina.php?ver=' . $nid);
    }

    /* ---------------------------------------------------------------------
     *  Quitar una línea
     * ------------------------------------------------------------------ */
    if ($accion === 'quitar_linea') {
        require_perm('rrhh_nomina.procesar');
        $nid = postInt('id');
        $n = qOne("SELECT * FROM nominas WHERE id = ?", [$nid]);
        if (!$n)                         { flash('error', 'Nómina no encontrada.'); redirect('modules/rrhh/nomina.php'); }
        if ($n['estado'] !== 'borrador') { flash('error', 'Solo se puede editar una nómina en borrador.'); redirect('modules/rrhh/nomina.php?ver=' . $nid); }
        require_sucursal_access($n['sucursal_id']);

        $d = qOne("SELECT nd.id, CONCAT(e.nombre,' ',e.apellido) AS nom
                     FROM nomina_detalles nd JOIN empleados e ON e.id = nd.empleado_id
                    WHERE nd.id = ? AND nd.nomina_id = ?", [postInt('linea_id'), $nid]);
        if (!$d) {
            flash('error', 'Esa línea no pertenece a esta nómina.');
        } else {
            q("DELETE FROM nomina_detalles WHERE id = ?", [(int) $d['id']]);
            nominaRecalcularTotales($nid);
            audit('rrhh_nomina', 'editar', 'Línea quitada de la nómina: ' . $d['nom'], ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash('success', $d['nom'] . ' ya no está en esta nómina.');
        }
        redirect('modules/rrhh/nomina.php?ver=' . $nid);
    }

    /* ---------------------------------------------------------------------
     *  Añadir un empleado a una nómina ya creada
     * ------------------------------------------------------------------ */
    if ($accion === 'agregar_linea') {
        require_perm('rrhh_nomina.procesar');
        $nid = postInt('id');
        $n = qOne("SELECT * FROM nominas WHERE id = ?", [$nid]);
        if (!$n)                         { flash('error', 'Nómina no encontrada.'); redirect('modules/rrhh/nomina.php'); }
        if ($n['estado'] !== 'borrador') { flash('error', 'Solo se puede editar una nómina en borrador.'); redirect('modules/rrhh/nomina.php?ver=' . $nid); }
        require_sucursal_access($n['sucursal_id']);

        // A propósito NO se filtra por estado ni por fechas: esta acción existe
        // justamente para los casos que el filtro automático no puede resolver
        // —quien se fue sin que se registrara su fecha de salida y aun así
        // cobró su última quincena—. Quien la usa está tomando una decisión y
        // queda en la auditoría.
        $e = qOne("SELECT * FROM empleados WHERE id = ?", [postInt('empleado_id')]);
        if (!$e) {
            flash('error', 'Ese empleado no existe.');
        } elseif (qVal("SELECT 1 FROM nomina_detalles WHERE nomina_id = ? AND empleado_id = ?", [$nid, (int) $e['id']])) {
            flash('error', $e['nombre'] . ' ' . $e['apellido'] . ' ya está en esta nómina.');
        } else {
            $factor = $n['tipo'] === 'quincenal' ? 0.5 : ($n['tipo'] === 'semanal' ? (1 / 4.33) : 1);
            $diasBase = nominaDiasBase($n['tipo']);
            $cuota = presCuotaDelPeriodo((int) $e['id'], $n['fecha_desde'], $n['fecha_hasta']);
            $c = calcNominaRD((float) $e['salario'], ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
                                                      'otras_deducciones' => $cuota], $factor);
            dbInsert('nomina_detalles', [
                'nomina_id' => $nid, 'empleado_id' => (int) $e['id'], 'salario_base' => $c['salarioPeriodo'],
                'dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
                'horas_extra' => 0, 'monto_horas_extra' => 0, 'bonificaciones' => 0, 'comisiones' => 0,
                'otros_ingresos' => 0, 'prima_vacacional' => 0, 'reembolso' => 0,
                'vacaciones_diferencial' => 0, 'descuento_dias' => 0, 'per_capita' => 0,
                'total_ingresos' => $c['totalIngresos'],
                'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'], 'otras_deducciones' => $c['prestamo'],
                'total_deducciones' => $c['totalDeducciones'], 'salario_neto' => $c['neto'],
            ]);
            nominaRecalcularTotales($nid);
            audit('rrhh_nomina', 'editar', 'Empleado añadido a la nómina: ' . $e['nombre'] . ' ' . $e['apellido'],
                ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash('success', $e['nombre'] . ' ' . $e['apellido'] . ' añadido con la jornada completa. '
                . 'Ajusta sus días y conceptos antes de confirmar.');
            if ($e['estado'] !== 'activo') {
                flash('info', 'Ojo: figura como ' . $e['estado'] . '. Se añadió igual porque lo pediste.');
            }
        }
        redirect('modules/rrhh/nomina.php?ver=' . $nid);
    }

    // Captura de conceptos y recálculo. Solo en borrador: una nómina cerrada
    // no se toca, porque ya se declaró y se pagó con esos números.
    /* ---------------------------------------------------------------------
     *  Volver a leer el padrón SIN perder lo capturado
     *
     *  Una nómina en borrador es una foto del padrón del momento en que se
     *  generó. Si después entra alguien, se va otro, cambia un sueldo o —como
     *  pasó con la reorganización por departamentos— se mueve gente de sitio,
     *  el borrador se queda viejo. La única salida era borrarlo y volver a
     *  generarlo, tirando a la basura la captura de horas extra, comisiones,
     *  préstamos y días de cincuenta y ocho personas.
     *
     *  Esto lo pone al día CONSERVANDO esa captura. Solo en borrador: una
     *  nómina confirmada es un documento cerrado y no se toca.
     * ------------------------------------------------------------------ */
    if ($accion === 'regenerar') {
        require_perm('rrhh_nomina.procesar');
        $nid = postInt('id');
        $n = qOne("SELECT * FROM nominas WHERE id = ?", [$nid]);
        if (!$n)                         { flash('error', 'Nómina no encontrada.'); redirect('modules/rrhh/nomina.php'); }
        if ($n['estado'] !== 'borrador') { flash('error', 'Solo se puede actualizar una nómina en borrador.'); redirect('modules/rrhh/nomina.php?ver=' . $nid); }
        require_sucursal_access($n['sucursal_id']);

        // MISMO criterio de pertenencia al período que al generarla. Si aquí se
        // usara otro, actualizar cambiaría la plantilla sin que nadie lo pida.
        $cond = [
            'fecha_ingreso <= ?',
            '(fecha_salida IS NULL OR fecha_salida >= ?)',
            "(estado = 'activo' OR fecha_salida IS NOT NULL)",
        ];
        $params = [$n['fecha_hasta'], $n['fecha_desde']];
        if ($n['sucursal_id']) { $cond[] = 'sucursal_id = ?'; $params[] = (int) $n['sucursal_id']; }
        $deben = [];
        foreach (qAll("SELECT * FROM empleados WHERE " . implode(' AND ', $cond), $params) as $emp) {
            $deben[(int) $emp['id']] = $emp;
        }
        if (!$deben) {
            flash('error', 'Ningún empleado corresponde a ese período. No se actualizó nada.');
            redirect('modules/rrhh/nomina.php?ver=' . $nid);
        }

        $factor   = $n['tipo'] === 'quincenal' ? 0.5 : ($n['tipo'] === 'semanal' ? (1 / 4.33) : 1);
        $diasBase = nominaDiasBase($n['tipo']);
        // Los conceptos que se capturan a mano. Se conservan tal cual.
        $capturados = ['dias_trabajados', 'horas_extra', 'monto_horas_extra', 'prima_vacacional',
                       'otros_ingresos', 'comisiones', 'reembolso', 'vacaciones_diferencial',
                       'bonificaciones', 'descuento_dias', 'per_capita', 'otras_deducciones'];

        try {
            $r = txReintentable(function () use ($nid, $n, $deben, $factor, $diasBase, $capturados) {
                $pendientes = $deben;
                $altas = []; $bajas = []; $recalculados = [];

                foreach (qAll("SELECT nd.*, e.nombre, e.apellido FROM nomina_detalles nd
                                 LEFT JOIN empleados e ON e.id = nd.empleado_id
                                WHERE nd.nomina_id = ?", [$nid]) as $d) {
                    $eid = (int) $d['empleado_id'];
                    if (!isset($pendientes[$eid])) {
                        q("DELETE FROM nomina_detalles WHERE id = ?", [(int) $d['id']]);
                        $bajas[] = trim(($d['nombre'] ?? '') . ' ' . ($d['apellido'] ?? '')) ?: ('#' . $eid);
                        continue;
                    }
                    $emp = $pendientes[$eid];
                    unset($pendientes[$eid]);   // lo que sobre al final son altas

                    // Se recalcula con el sueldo VIGENTE, pero con los conceptos
                    // que ya estaban capturados en la pantalla.
                    $vals = [];
                    foreach ($capturados as $k) $vals[$k] = (float) $d[$k];
                    $vals['dias_base'] = (float) $d['dias_base'] ?: $diasBase;
                    $c = calcNominaRD((float) $emp['salario'], $vals, $factor);

                    // «Recalculado», no «le subieron el sueldo»: el importe del
                    // período también se mueve si cambiaron los días capturados.
                    // Decir lo segundo sería afirmar algo que esta comparación
                    // no sabe.
                    if (round((float) $d['salario_base'], 2) !== round($c['salarioPeriodo'], 2)) {
                        $recalculados[] = trim($emp['nombre'] . ' ' . $emp['apellido']);
                    }
                    dbUpdate('nomina_detalles', $vals + [
                        'salario_base'      => $c['salarioPeriodo'],
                        'total_ingresos'    => $c['totalIngresos'],
                        'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'],
                        'total_deducciones' => $c['totalDeducciones'],
                        'salario_neto'      => $c['neto'],
                    ], 'id = ?', [(int) $d['id']]);
                }

                foreach ($pendientes as $emp) {
                    // Solo las ALTAS traen su cuota de préstamo. A quien ya
                    // estaba no se le toca la columna: puede haberla ajustado
                    // alguien a mano y eso manda.
                    $cuota = presCuotaDelPeriodo((int) $emp['id'], $n['fecha_desde'], $n['fecha_hasta']);
                    $c = calcNominaRD((float) $emp['salario'],
                        ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
                         'otras_deducciones' => $cuota], $factor);
                    dbInsert('nomina_detalles', [
                        'nomina_id' => $nid, 'empleado_id' => (int) $emp['id'], 'salario_base' => $c['salarioPeriodo'],
                        'dias_base' => $diasBase, 'dias_trabajados' => $diasBase,
                        'horas_extra' => 0, 'monto_horas_extra' => 0, 'bonificaciones' => 0, 'comisiones' => 0,
                        'otros_ingresos' => 0, 'prima_vacacional' => 0, 'reembolso' => 0,
                        'vacaciones_diferencial' => 0, 'descuento_dias' => 0, 'per_capita' => 0,
                        'total_ingresos' => $c['totalIngresos'],
                        'afp' => $c['afp'], 'sfs' => $c['sfs'], 'isr' => $c['isr'], 'otras_deducciones' => $c['prestamo'],
                        'total_deducciones' => $c['totalDeducciones'], 'salario_neto' => $c['neto'],
                    ]);
                    $altas[] = trim($emp['nombre'] . ' ' . $emp['apellido']);
                }

                nominaRecalcularTotales($nid);
                return ['altas' => $altas, 'bajas' => $bajas, 'recalculados' => $recalculados];
            });

            // Se dice QUIÉN entró y quién salió, no cuántos: si el resultado
            // sorprende, quien lea sabrá dónde mirar sin abrir el padrón.
            $lista = fn(array $x) => implode(', ', array_slice($x, 0, 6))
                   . (count($x) > 6 ? ' y ' . (count($x) - 6) . ' más' : '');
            $partes = [];
            if ($r['altas'])   $partes[] = count($r['altas']) . ' añadido(s): ' . $lista($r['altas']);
            if ($r['bajas'])   $partes[] = count($r['bajas']) . ' retirado(s): ' . $lista($r['bajas']);
            if ($r['recalculados']) $partes[] = count($r['recalculados']) . ' con el importe recalculado: ' . $lista($r['recalculados']);

            audit('rrhh_nomina', 'editar',
                  'Nómina actualizada contra el padrón: ' . ($partes ? implode(' · ', $partes) : 'sin cambios'),
                  ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash($partes ? 'success' : 'info', $partes
                ? 'Nómina actualizada. ' . implode('. ', $partes) . '. Lo capturado a mano se conservó.'
                : 'La nómina ya estaba al día con el padrón: no hubo nada que cambiar.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/rrhh/nomina.php?ver=' . $nid);
    }

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
            $sinCobrar = txReintentable(function () use ($nid, $n, $campos, $factor) {
                $tb = 0; $td = 0; $tn = 0;
                $sinCobrar = [];
                foreach (qAll("SELECT nd.*, e.nombre, e.apellido, e.salario FROM nomina_detalles nd JOIN empleados e ON e.id=nd.empleado_id WHERE nd.nomina_id = ?", [$nid]) as $d) {
                    $vals = [];
                    foreach ($campos as $k) {
                        $v = $_POST[$k][$d['id']] ?? $d[$k];
                        $vals[$k] = max(0.0, round((float) str_replace(',', '', (string) $v), 2));
                    }
                    $vals['dias_base'] = (float) $d['dias_base'] ?: nominaDiasBase($n['tipo']);

                    $c = calcNominaRD((float) $d['salario'], $vals, $factor);

                    // Se guarda lo que de VERDAD se descontó. Si la cuota no cabía
                    // en el sueldo del período, dejarla escrita entera haría que la
                    // fila no cuadre (ingresos − deducciones ≠ neto) y daría por
                    // cobrado un dinero que nadie cobró.
                    $vals['otras_deducciones'] = $c['prestamo'];
                    if ($c['prestamoPendiente'] > 0) {
                        $sinCobrar[] = trim($d['nombre'] . ' ' . $d['apellido']) . ' (' . money($c['prestamoPendiente']) . ')';
                    }

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
                return $sinCobrar;
            });
            flash('success', 'Conceptos guardados y nómina recalculada.');
            if ($sinCobrar) {
                flash('warning', 'La cuota de préstamo no cabía en el sueldo del período de: '
                    . implode(', ', array_slice($sinCobrar, 0, 5))
                    . (count($sinCobrar) > 5 ? ' y ' . (count($sinCobrar) - 5) . ' más' : '')
                    . '. Se descontó solo hasta donde alcanzó; el resto sigue pendiente.');
            }
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

            // Las cuotas de préstamo se dan por cobradas AQUÍ, al confirmar, y
            // no al generar el borrador. Un borrador se puede borrar, y una
            // cuota marcada como cobrada por una nómina que nunca se pagó sería
            // una deuda que se esfuma sin que nadie pagara nada.
            $cuotas = 0;
            if (function_exists('presAplicarCobro') && presDisponible()) {
                foreach (qAll("SELECT id, empleado_id, otras_deducciones FROM nomina_detalles WHERE nomina_id = ?", [$nid]) as $d) {
                    if ((float) $d['otras_deducciones'] <= 0) continue;
                    $cuotas += presAplicarCobro((int) $d['empleado_id'], (int) $d['id'],
                        $n['fecha_desde'], $n['fecha_hasta'], (float) $d['otras_deducciones']);
                }
            }

            audit('rrhh_nomina', 'procesar', "Nómina confirmada: {$n['descripcion']}"
                . ($cuotas ? " · $cuotas cuota(s) de préstamo cobradas" : ''),
                ['tabla' => 'nominas', 'registro_id' => $nid]);
            flash('success', 'Nómina confirmada. Ya no se puede editar.'
                . ($cuotas ? ' Se dieron por cobradas ' . $cuotas . ' cuota(s) de préstamo.' : ''));
        } else {
            flash('error', 'Esta nómina no está en borrador.');
        }
        redirect('modules/rrhh/nomina.php?ver=' . $nid);
    }

    if ($accion === 'pagar') {
        require_perm('rrhh_nomina.pagar');
        $id = postInt('id');
        // De qué cuenta sale el dinero. Antes estaba FIJO en efectivo y no había
        // forma de decir otra cosa: a 53 de los 58 se les paga por transferencia,
        // así que una quincena entera salía de una caja que nunca tuvo ese
        // dinero y la dejaba en −877.721,39. La cuenta ahora se elige al pagar.
        $cuentaId = postInt('cuenta_id') ?: null;
        try {
            $aviso = txReintentable(function () use ($id, $cuentaId) {
                $n = qOne("SELECT * FROM nominas WHERE id=? FOR UPDATE", [$id]);
                if (!$n || $n['estado'] !== 'procesada') throw new RuntimeException('La nómina no se puede pagar.');
                if (!can_access_sucursal($n['sucursal_id'])) throw new RuntimeException('No tienes acceso a la sucursal de esta nómina.');

                $sucId = $n['sucursal_id'] !== null ? (int) $n['sucursal_id'] : null;
                $cuenta = null;
                if ($cuentaId) {
                    $cuenta = qOne("SELECT * FROM cuentas_financieras WHERE id = ? AND activo = 1 FOR UPDATE", [$cuentaId]);
                    if (!$cuenta) throw new RuntimeException('La cuenta elegida para el pago no existe o está inactiva.');
                    if ($cuenta['sucursal_id'] !== null && !can_access_sucursal($cuenta['sucursal_id'])) {
                        throw new RuntimeException('No tienes acceso a la cuenta elegida.');
                    }
                } else {
                    // Sin elección explícita se conserva el comportamiento de
                    // siempre, para no cambiar nada a quien sí paga en efectivo.
                    $auto = cuentaFinancieraIdPorTipo('efectivo', $sucId);
                    $cuenta = $auto ? qOne("SELECT * FROM cuentas_financieras WHERE id = ? FOR UPDATE", [$auto]) : null;
                }

                dbUpdate('nominas', ['estado' => 'pagada'], 'id=?', [$id]);

                $neto = (float) $n['total_neto'];
                $aviso = '';
                if ($neto > 0) {
                    // Si el pago deja la cuenta en rojo se avisa, pero no se
                    // bloquea: las dos causas posibles son legítimas y el
                    // sistema no puede saber cuál es. Se nombran las dos en vez
                    // de suponer una — el saldo de apertura sin cargar es tan
                    // común como haber elegido la cuenta equivocada.
                    if ($cuenta && (float) $cuenta['balance'] - $neto < -0.01) {
                        $queda = money((float) $cuenta['balance'] - $neto);
                        $aviso = 'La cuenta «' . $cuenta['nombre'] . '» queda en ' . $queda . '. '
                               . ($cuenta['tipo'] === 'efectivo'
                                   ? 'Una caja no debería quedar debiendo: si el pago salió del banco, anúlalo y vuelve a registrarlo contra la cuenta bancaria.'
                                   : 'Revisa si falta cargar el saldo de apertura de esa cuenta.')
                               . ' El saldo inicial se registra en Finanzas → Cuentas.';
                    }
                    registrarTransaccion('gasto', $neto, [
                        'sucursal_id'     => $n['sucursal_id'],
                        'cuenta_id'       => $cuenta ? (int) $cuenta['id'] : null,
                        'categoria_id'    => categoriaFinancieraId('gasto', 'Nómina'),
                        'descripcion'     => 'Pago de nómina: ' . $n['descripcion']
                                           . ($cuenta ? ' (' . $cuenta['nombre'] . ')' : ''),
                        'referencia_tipo' => 'nomina',
                        'referencia_id'   => $id,
                    ]);
                }
                return $aviso;
            });
            audit('rrhh_nomina', 'pagar', "Nómina pagada #$id", ['tabla' => 'nominas', 'registro_id' => $id]);
            flash('success', 'Nómina marcada como pagada y registrada en finanzas.');
            if ($aviso) flash('warning', $aviso);
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
        $acc .= '<button type="button" onclick="' . jsEvent('nom:edit') . '" class="btn btn-ghost">'
              . icon('edit', 'w-4 h-4') . ' Editar período</button>';
    }
    if ($n['estado'] === 'borrador' && can('rrhh_nomina.procesar')) {
        // Dice lo que se conserva, no solo lo que cambia: el miedo al pulsarlo
        // es perder la captura de las 58 filas, y justo eso es lo que NO pasa.
        $avisoR = 'Se volverá a leer el padrón para el período '
                . fechaCorta($n['fecha_desde']) . ' al ' . fechaCorta($n['fecha_hasta'])
                . ': entra quien ahora corresponda, sale quien ya no, y se refresca el sueldo. '
                . 'Las horas extra, comisiones, préstamos y días que ya capturaste SE CONSERVAN.';
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\'' . e(addslashes($avisoR)) . '\')">'
              . csrf_field() . '<input type="hidden" name="accion" value="regenerar"><input type="hidden" name="id" value="' . $verId . '">'
              . '<button class="btn btn-soft">' . icon('history', 'w-4 h-4') . ' Actualizar contra el padrón</button></form>';
    }
    if ($n['estado'] === 'borrador' && can('rrhh_nomina.procesar')) {
        // El mensaje dice QUÉ se cierra y por cuánto, no solo que es irreversible.
        $avisoC = 'Se confirmará «' . $n['descripcion'] . '»: ' . count($det) . ' empleado(s) por '
                . money($n['total_neto'], false) . ' netos. Deja de ser editable.';
        $acc .= '<form method="post" class="inline" onsubmit="return confirm(\'' . e(addslashes($avisoC)) . '\')">'
              . csrf_field() . '<input type="hidden" name="accion" value="confirmar"><input type="hidden" name="id" value="' . $verId . '">'
              . '<button class="btn btn-primary">' . icon('check', 'w-4 h-4') . ' Confirmar nómina</button></form>';
    }
    if ($n['estado'] === 'procesada' && can('rrhh_nomina.pagar')) {
        // De qué cuenta sale el dinero. Se pregunta porque la mayoría de la
        // gente cobra por transferencia: dar por hecho «efectivo» dejaba una
        // caja en rojo por el importe de la quincena entera.
        $cuentas = qAll(
            "SELECT id, nombre, tipo, balance FROM cuentas_financieras
              WHERE activo = 1 AND (sucursal_id IS NULL OR sucursal_id = ?)
              ORDER BY tipo = 'banco' DESC, sucursal_id IS NULL, nombre",
            [$n['sucursal_id']]
        );
        $opts = '';
        foreach ($cuentas as $cta) {
            $opts .= '<option value="' . (int) $cta['id'] . '">'
                   . e($cta['nombre']) . ' · ' . e(ucfirst($cta['tipo']))
                   . ' · ' . money($cta['balance']) . '</option>';
        }
        $avisoP = '¿Marcar como pagada «' . $n['descripcion'] . '» por '
                . money($n['total_neto'], false) . ' netos? Se registrará el gasto en la cuenta elegida.';
        $acc .= '<form method="post" class="inline-flex items-center gap-2" onsubmit="return confirm(\'' . e(addslashes($avisoP)) . '\')">'
              . csrf_field()
              . '<input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="' . $verId . '">'
              . ($cuentas
                  ? '<select name="cuenta_id" class="select py-1.5 text-sm max-w-[19rem]" aria-label="Cuenta de la que sale el pago">' . $opts . '</select>'
                  : '')
              . '<button class="btn btn-success">' . icon('check', 'w-4 h-4') . ' Marcar pagada</button></form>';
    }
    $editable = $n['estado'] === 'borrador' && can('rrhh_nomina.procesar');
    layout_start('Nómina · ' . e($n['descripcion']), 'Periodo ' . fechaCorta($n['fecha_desde']) . ' al ' . fechaCorta($n['fecha_hasta']) . ' · ' . ucfirst($n['tipo']), $acc);
    ?>
    <?php
      // Dónde está la nómina y qué falta. Un badge suelto dice el estado; esto
      // dice además lo que viene después, que es lo que la persona necesita.
      $pasos = ['borrador' => 'Borrador', 'procesada' => 'Confirmada', 'pagada' => 'Pagada'];
      $actual = array_search($n['estado'], array_keys($pasos), true);
      $actual = $actual === false ? 0 : $actual;
      // Suma de columnas para el pie de la tabla: antes solo salían dos.
      $sum = fn(string $k) => array_sum(array_map(fn($d) => (float) $d[$k], $det));
    ?>
    <div class="card p-5 mb-5">
      <div class="flex flex-wrap items-center gap-x-8 gap-y-4 justify-between">
        <ol class="flex items-center gap-1" aria-label="Estado de la nómina">
          <?php foreach (array_values($pasos) as $i => $lbl): $hecho = $i <= $actual; ?>
            <li class="flex items-center gap-2">
              <span class="w-7 h-7 rounded-full grid place-items-center text-xs font-bold border-2
                           <?= $hecho ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-200 text-slate-300' ?>"
                    <?= $i === $actual ? 'aria-current="step"' : '' ?>>
                <?= $hecho ? icon('check', 'w-3.5 h-3.5') : $i + 1 ?>
              </span>
              <span class="text-sm <?= $i === $actual ? 'font-bold text-slate-800' : ($hecho ? 'text-slate-500' : 'text-slate-400') ?>"><?= e($lbl) ?></span>
              <?php if ($i < count($pasos) - 1): ?>
                <span class="w-8 h-0.5 mx-1 rounded <?= $i < $actual ? 'bg-blue-600' : 'bg-slate-200' ?>" aria-hidden="true"></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
        <p class="text-sm text-slate-500">
          <?= count($det) ?> empleado(s) ·
          <?= e($n['sucursal'] ?: 'todas las sucursales') ?> ·
          <?= fechaCorta($n['fecha_desde']) ?> al <?= fechaCorta($n['fecha_hasta']) ?>
        </p>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
      <div class="card p-5">
        <p class="text-sm text-slate-400">Total bruto</p>
        <p class="text-xl font-extrabold text-slate-800 mt-1"><?= money($n['total_bruto']) ?></p>
        <p class="text-xs text-slate-400 mt-0.5">base cotizable</p>
      </div>
      <div class="card p-5">
        <p class="text-sm text-slate-400">Deducciones</p>
        <p class="text-xl font-extrabold text-rose-600 mt-1"><?= money($n['total_deducciones']) ?></p>
        <p class="text-xs text-slate-400 mt-0.5">AFP <?= money($sum('afp'), false) ?> · SFS <?= money($sum('sfs'), false) ?> · ISR <?= money($sum('isr'), false) ?></p>
      </div>
      <div class="card p-5">
        <p class="text-sm text-slate-400">Total neto a pagar</p>
        <p class="text-xl font-extrabold text-emerald-600 mt-1"><?= money($n['total_neto']) ?></p>
      </div>
      <div class="card p-5">
        <p class="text-sm text-slate-400">Promedio por empleado</p>
        <p class="text-xl font-extrabold text-slate-800 mt-1"><?= money(count($det) ? $n['total_neto'] / count($det) : 0) ?></p>
      </div>
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
    <form method="post"
          x-data="{
            buscar: '',
            tocadas: 0,
            /* Marca la fila y cuenta. A propósito NO se recalcula el neto aquí:
               el ISR sale de la escala anual y duplicar ese cálculo en JavaScript
               es pedir que un día diga una cosa la pantalla y otra la base. Se
               avisa de que hay cambios sin guardar y manda el servidor. */
            tocar(e) {
              const fila = e.target.closest('tr');
              if (fila && !fila.dataset.tocada) { fila.dataset.tocada = '1'; this.tocadas++; }
            },
            visible(fila) {
              const t = this.buscar.trim().toLowerCase();
              return t === '' || fila.dataset.busca.includes(t);
            }
          }"><?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar_conceptos">
    <input type="hidden" name="id" value="<?= $verId ?>">
    <div class="card overflow-hidden">
      <?php if ($editable): ?>
        <div class="p-4 border-b border-slate-100 flex flex-wrap items-center gap-3 justify-between">
          <div class="relative flex-1 min-w-[240px] max-w-sm">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
            <input type="text" x-model="buscar" placeholder="Filtrar por nombre o cédula..."
                   class="input pl-9" autocomplete="off">
          </div>
          <div class="flex items-center gap-3">
            <p class="text-sm" :class="tocadas > 0 ? 'text-amber-700 font-semibold' : 'text-slate-400'">
              <span x-show="tocadas === 0">Sin cambios pendientes</span>
              <span x-show="tocadas > 0" x-cloak><span x-text="tocadas"></span> fila(s) modificada(s)</span>
            </p>
            <?php if (can('rrhh_nomina.procesar')): ?>
              <button type="button" class="btn btn-soft btn-sm" onclick="<?= jsEvent('nom:add') ?>">
                <?= icon('plus', 'w-4 h-4') ?> Añadir empleado
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <div class="<?= $editable ? 'tabla-ancha' : 'overflow-x-auto' ?>">
        <table class="data-table">
          <?php /* En BORRADOR la tabla es un formulario: aquí se capturan los
                   conceptos que el módulo antes no tenía dónde recoger. Cerrada,
                   se muestra en solo lectura.

                   `tabla-ancha` solo en borrador: es la única versión con quince
                   columnas de captura. Cerrada tiene seis y cabe sin más. */ ?>
          <thead>
            <tr>
              <th class="min-w-[15rem]">Empleado</th>
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
              <?php if ($editable): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php
              $campoNum = function (string $campo, array $d, string $ancho = 'w-20') {
                  return '<input type="number" step="0.01" min="0" name="' . $campo . '[' . (int) $d['id'] . ']"'
                       . ' value="' . e(rtrim(rtrim(number_format((float) $d[$campo], 2, '.', ''), '0'), '.') ?: '0') . '"'
                       // `tocar` solo marca la fila; el recálculo lo hace el servidor.
                       . ' @input="tocar($event)"'
                       . ' class="' . $ancho . ' px-2 py-1 rounded-lg border border-slate-200 text-sm text-right'
                       . ' focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none">';
              };
            ?>
            <?php foreach ($det as $d): ?>
              <tr <?= $editable ? 'x-show="visible($el)" data-busca="' . e(mb_strtolower($d['nombre'] . ' ' . $d['apellido'] . ' ' . $d['cedula'])) . '" :class="$el.dataset.tocada ? \'bg-amber-50/60\' : \'\'"' : '' ?>>
                <?php /* `whitespace-nowrap`: «Annie Charline Rodríguez Navarro»
                         se partía en cuatro líneas y estiraba TODA la fila a
                         cuatro veces su alto, con las quince casillas de captura
                         flotando en el centro de un hueco enorme. */ ?>
                <td class="whitespace-nowrap"><div class="flex items-center gap-2"><?= avatar($d['nombre'] . ' ' . $d['apellido'], 'w-8 h-8 shrink-0') ?><div><p class="font-semibold text-slate-700"><?= e($d['nombre'] . ' ' . $d['apellido']) ?></p><p class="text-xs text-slate-400"><?= e($d['cedula']) ?> · <?= money($d['salario_base']) ?></p></div></div></td>
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
                <?php if ($editable): ?>
                  <td class="text-right">
                    <?php $avisoQ = 'Se quitará a ' . $d['nombre'] . ' ' . $d['apellido']
                                  . ' de esta nómina. Su neto de ' . money($d['salario_neto'], false)
                                  . ' dejará de contar en los totales.'; ?>
                    <form method="post" class="inline" onsubmit="return confirm('<?= e(addslashes($avisoQ)) ?>')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="quitar_linea">
                      <input type="hidden" name="id" value="<?= $verId ?>">
                      <input type="hidden" name="linea_id" value="<?= (int) $d['id'] ?>">
                      <button class="p-1.5 rounded-lg text-slate-300 hover:text-rose-600 hover:bg-rose-50" title="Quitar de la nómina"><?= icon('x', 'w-4 h-4') ?></button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <?php /* Antes el pie dejaba AFP, SFS e ISR en blanco: justo las tres
                     cifras que hay que cuadrar contra la TSS y la DGII. */ ?>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td colspan="<?= $editable ? 10 : 2 ?>">TOTALES</td>
              <td class="text-right"><?= money($sum('afp'), false) ?></td>
              <td class="text-right"><?= money($sum('sfs'), false) ?></td>
              <td class="text-right"><?= money($sum('isr'), false) ?></td>
              <td class="text-right text-rose-600"><?= money($n['total_deducciones']) ?></td>
              <td class="text-right text-emerald-600"><?= money($n['total_neto']) ?></td>
              <?php if ($editable): ?><td></td><?php endif; ?>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php if ($editable): ?>
        <div class="p-4 border-t border-slate-100 flex items-center justify-between gap-3 flex-wrap">
          <p class="text-sm text-slate-400" x-show="tocadas === 0">Los totales se recalculan al guardar.</p>
          <p class="text-sm text-amber-700 font-semibold" x-show="tocadas > 0" x-cloak>
            Tienes <span x-text="tocadas"></span> fila(s) sin guardar.
          </p>
          <button class="btn btn-primary"><?= icon('check', 'w-4 h-4') ?> Guardar y recalcular</button>
        </div>
      <?php endif; ?>
    </div>
    </form>

    <?php if ($editable && can('rrhh_nomina.procesar')): ?>
      <?php
        // Quien todavía no está en esta nómina. Se ofrecen TODOS, también los
        // inactivos, porque el caso que obliga a usar esto es precisamente el
        // que el filtro automático no puede resolver: alguien que se fue sin
        // que constara su fecha de salida y aun así cobró su última quincena.
        $candidatos = qAll(
            "SELECT e.id, CONCAT(e.nombre,' ',e.apellido) AS nom, e.cedula, e.salario, e.estado,
                    s.nombre AS sucursal
               FROM empleados e LEFT JOIN sucursales s ON s.id = e.sucursal_id
              WHERE e.id NOT IN (SELECT empleado_id FROM nomina_detalles WHERE nomina_id = ?)
              ORDER BY e.estado = 'activo' DESC, e.nombre", [$verId]
        );
      ?>
      <!-- Añadir un empleado a una nómina ya creada -->
      <div x-data="{open:false}" @nom:add.window="open=true" @keydown.escape.window="open=false">
        <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
          <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="agregar_linea">
              <input type="hidden" name="id" value="<?= $verId ?>">
              <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="font-bold text-slate-800">Añadir empleado a la nómina</h3>
                <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
              </div>
              <div class="p-6 space-y-4">
                <?php if (!$candidatos): ?>
                  <p class="text-sm text-slate-500">Todos los empleados del padrón ya están en esta nómina.</p>
                <?php else: ?>
                  <div>
                    <label class="label" for="nom_emp">Empleado *</label>
                    <select id="nom_emp" name="empleado_id" required class="select">
                      <option value="">— Elige —</option>
                      <?php foreach ($candidatos as $c): ?>
                        <option value="<?= (int) $c['id'] ?>">
                          <?= e($c['nom']) ?> · <?= e($c['cedula']) ?> · <?= money($c['salario'], false) ?>
                          <?= $c['estado'] !== 'activo' ? ' — ' . strtoupper($c['estado']) : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="rounded-xl bg-sky-50 border border-sky-200 p-3 text-xs text-sky-700">
                    Entra con la jornada completa y todos los conceptos en cero. Ajusta sus días
                    y lo que corresponda antes de confirmar la nómina.
                  </div>
                  <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
                    La lista incluye a quien está de baja: es para el caso de quien se fue dentro
                    del período y aun así cobra su última quincena. Queda en la auditoría.
                  </div>
                <?php endif; ?>
              </div>
              <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
                <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
                <?php if ($candidatos): ?>
                  <button type="submit" class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Añadir</button>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Corregir el período sin tener que borrar y rehacer la nómina -->
      <div x-data="{open:false}" @nom:edit.window="open=true" @keydown.escape.window="open=false">
        <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
          <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="editar_cabecera">
              <input type="hidden" name="id" value="<?= $verId ?>">
              <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <h3 class="font-bold text-slate-800">Editar el período</h3>
                <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
              </div>
              <div class="p-6 space-y-4">
                <div>
                  <label class="label" for="nom_desc">Descripción *</label>
                  <input id="nom_desc" name="descripcion" required class="input" value="<?= e($n['descripcion']) ?>">
                </div>
                <div class="grid grid-cols-2 gap-4">
                  <div><label class="label" for="nom_d">Desde *</label><input id="nom_d" type="date" name="fecha_desde" value="<?= e($n['fecha_desde']) ?>" required class="input"></div>
                  <div><label class="label" for="nom_h">Hasta *</label><input id="nom_h" type="date" name="fecha_hasta" value="<?= e($n['fecha_hasta']) ?>" required class="input"></div>
                </div>
                <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
                  Cambiar las fechas <strong>no vuelve a elegir empleados</strong>: las líneas ya
                  creadas se quedan como están. Si el período nuevo cambia quién entra, añade o
                  quita las líneas a mano.
                </div>
              </div>
              <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
                <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
                <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <?php layout_end(); return;
}

// ----- Listado -----
[$scopeNomina, $paramsNomina] = sucursalScope('n.sucursal_id');

// Antes era un `LIMIT 60` mudo, sin búsqueda ni filtros: con dos nóminas al mes
// eso se queda corto en dos años y medio, y sin avisar. Ahora usa los mismos
// componentes que el resto de la aplicación.
$q        = trim(get('q'));
$fEstado  = in_array(get('estado'), ['borrador', 'procesada', 'pagada'], true) ? get('estado') : '';
$fAnio    = (int) get('anio');

$cond = [$scopeNomina]; $params = $paramsNomina;
if ($q !== '')      { $cond[] = 'n.descripcion LIKE ?'; $params[] = "%$q%"; }
if ($fEstado !== '') { $cond[] = 'n.estado = ?';        $params[] = $fEstado; }
if ($fAnio > 0)     { $cond[] = 'YEAR(n.fecha_hasta) = ?'; $params[] = $fAnio; }
$where = implode(' AND ', $cond);

$pg = paginar((int) qVal("SELECT COUNT(*) FROM nominas n WHERE $where", $params), 25);
$nominas = qAll(
    "SELECT n.*, s.nombre AS sucursal,
            (SELECT COUNT(*) FROM nomina_detalles WHERE nomina_id = n.id) AS empleados
       FROM nominas n LEFT JOIN sucursales s ON s.id = n.sucursal_id
      WHERE $where ORDER BY n.fecha_hasta DESC, n.id DESC
      LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $params
);

$anios = qCol("SELECT DISTINCT YEAR(fecha_hasta) FROM nominas ORDER BY 1 DESC");

// Cifras del año en curso: lo pagado, lo que falta por cerrar y cuánta gente.
$anioActual = $fAnio ?: (int) date('Y');
$res = qOne(
    "SELECT COUNT(*) AS cuantas,
            COALESCE(SUM(CASE WHEN estado = 'pagada' THEN total_neto END), 0) AS pagado,
            SUM(estado = 'borrador')  AS borradores,
            SUM(estado = 'procesada') AS por_pagar
       FROM nominas n WHERE $scopeNomina AND YEAR(fecha_hasta) = ?",
    array_merge($paramsNomina, [$anioActual])
) ?: ['cuantas' => 0, 'pagado' => 0, 'borradores' => 0, 'por_pagar' => 0];

$sucursales = sucursales_visibles();

$acciones = can('rrhh_nomina.procesar') ? '<button onclick="' . jsEvent('nom:new') . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Procesar nómina</button>' : '';
layout_start('Nómina', 'Procesa la nómina con cálculo automático de TSS (AFP/SFS) e ISR', $acciones);
?>

<!-- Cifras del año: lo que ya salió de caja y lo que queda por cerrar -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-sm text-slate-400">Nóminas de <?= (int) $anioActual ?></p>
    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= number_format((int) $res['cuantas']) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-400">Pagado en el año</p>
    <p class="text-xl font-extrabold text-emerald-600 mt-1"><?= money($res['pagado']) ?></p>
  </div>
  <div class="card p-5 <?= (int) $res['borradores'] > 0 ? 'border-amber-200 bg-amber-50/50' : '' ?>">
    <p class="text-sm <?= (int) $res['borradores'] > 0 ? 'text-amber-700' : 'text-slate-400' ?>">En borrador</p>
    <p class="text-xl font-extrabold mt-1 <?= (int) $res['borradores'] > 0 ? 'text-amber-700' : 'text-slate-800' ?>">
      <?= number_format((int) $res['borradores']) ?></p>
    <?php if ((int) $res['borradores'] > 0): ?>
      <p class="text-xs text-amber-600/80 mt-0.5">sin confirmar</p>
    <?php endif; ?>
  </div>
  <div class="card p-5 <?= (int) $res['por_pagar'] > 0 ? 'border-sky-200 bg-sky-50/50' : '' ?>">
    <p class="text-sm <?= (int) $res['por_pagar'] > 0 ? 'text-sky-700' : 'text-slate-400' ?>">Confirmadas sin pagar</p>
    <p class="text-xl font-extrabold mt-1 <?= (int) $res['por_pagar'] > 0 ? 'text-sky-700' : 'text-slate-800' ?>">
      <?= number_format((int) $res['por_pagar']) ?></p>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex flex-wrap items-center gap-3 justify-between">
    <?= search_box('Buscar por descripción del período...',
        array_filter(['estado' => $fEstado, 'anio' => $fAnio ?: ''])) ?>
    <form method="get" class="flex items-center gap-2 flex-wrap">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <select name="estado" class="select" onchange="this.form.submit()">
        <option value="">Todos los estados</option>
        <?php foreach (['borrador' => 'En borrador', 'procesada' => 'Confirmadas', 'pagada' => 'Pagadas'] as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $fEstado === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($anios): ?>
        <select name="anio" class="select" onchange="this.form.submit()">
          <option value="">Todos los años</option>
          <?php foreach ($anios as $a): ?>
            <option value="<?= (int) $a ?>" <?= $fAnio === (int) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <span class="text-sm text-slate-400 whitespace-nowrap"><?= number_format($pg['total']) ?> nómina(s)</span>
    </form>
  </div>

  <?php if (!$nominas): ?>
    <?= $q !== '' || $fEstado !== '' || $fAnio
        ? empty_state('Sin resultados', 'Ninguna nómina coincide con el filtro.', 'search')
        : empty_state('Sin nóminas', 'Procesa tu primera nómina. El sistema calcula AFP, SFS e ISR automáticamente.', 'wallet', $acciones) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Descripción</th><th>Periodo</th><th>Tipo</th><th>Sucursal</th><th class="text-center">Empleados</th><th class="text-right">Neto</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($nominas as $n): ?>
            <tr>
              <td>
                <a href="?ver=<?= (int) $n['id'] ?>" class="font-semibold text-slate-700 hover:text-blue-700"><?= e($n['descripcion']) ?></a>
                <?php if ($n['estado'] === 'borrador'): ?>
                  <p class="text-xs text-amber-600 mt-0.5">Falta confirmarla</p>
                <?php endif; ?>
              </td>
              <td class="text-slate-500 whitespace-nowrap"><?= fechaCorta($n['fecha_desde']) ?> – <?= fechaCorta($n['fecha_hasta']) ?></td>
              <td><?= badge(ucfirst($n['tipo']), 'slate') ?></td>
              <td class="text-slate-500"><?= e($n['sucursal'] ?: 'Todas') ?></td>
              <td class="text-center"><span class="badge badge-blue"><?= (int) $n['empleados'] ?></span></td>
              <td class="text-right font-bold text-slate-800"><?= money($n['total_neto']) ?></td>
              <td><?= badgeFor($n['estado']) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="?ver=<?= (int) $n['id'] ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ver detalle"><?= icon('eye', 'w-4 h-4') ?></a>
                  <!-- Los tres exportables, sin tener que entrar al detalle -->
                  <a href="?ver=<?= (int) $n['id'] ?>&export=excel" class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Excel con el formato del cliente"><?= icon('download', 'w-4 h-4') ?></a>
                  <a href="?ver=<?= (int) $n['id'] ?>&export=banco" class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50" title="Archivo de pago del banco"><?= icon('bank', 'w-4 h-4') ?></a>
                  <?php if (can('rrhh_nomina.procesar') && $n['estado'] !== 'pagada'): ?>
                    <?php
                      // El modal global del layout (includes/layout/footer.php)
                      // recoge este confirm() y lo convierte en diálogo. Se le da
                      // un mensaje que NOMBRA la nómina y dice cuánto se lleva:
                      // «¿Eliminar esta nómina?» no informa de nada.
                      $aviso = 'Se eliminará «' . $n['descripcion'] . '» con sus '
                             . (int) $n['empleados'] . ' línea(s) de empleado. No se puede deshacer.';
                    ?>
                    <form method="post" class="inline" onsubmit="return confirm('<?= e(addslashes($aviso)) ?>')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="eliminar">
                      <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
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
