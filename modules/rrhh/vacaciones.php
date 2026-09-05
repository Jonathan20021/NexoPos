<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_vacaciones.ver');

$tipos = ['vacaciones', 'licencia'];
$subtiposLicencia = ['enfermedad', 'personal', 'maternidad', 'duelo'];

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        require_perm('rrhh_vacaciones.crear');
        $empleadoId = postInt('empleado_id');
        $tipo       = in_array(post('tipo'), $tipos, true) ? post('tipo') : 'vacaciones';
        $subtipo    = trim(post('subtipo'));
        $desde      = trim(post('fecha_desde'));
        $hasta      = trim(post('fecha_hasta'));
        $conGoce    = postInt('con_goce', 1) ? 1 : 0;
        $motivo     = trim(post('motivo')) ?: null;

        // Para vacaciones el subtipo va vacío; para licencia se valida contra la lista.
        if ($tipo === 'vacaciones') {
            $subtipo = null;
        } else {
            $subtipo = in_array($subtipo, $subtiposLicencia, true) ? $subtipo : null;
        }

        $okFechas = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta);
        $tsDesde = $okFechas ? strtotime($desde) : false;
        $tsHasta = $okFechas ? strtotime($hasta) : false;

        // El empleado debe existir y estar dentro del alcance de sucursal.
        [$wScope, $pScope] = sucursalScope('sucursal_id');
        $emp = qOne(
            "SELECT id, nombre, apellido, fecha_ingreso FROM empleados WHERE id = ? AND $wScope",
            array_merge([$empleadoId], $pScope)
        );

        if (!$emp) {
            flash('error', 'Selecciona un empleado válido.');
        } elseif (!$okFechas || $tsDesde === false || $tsHasta === false) {
            flash('error', 'Las fechas de la solicitud no son válidas.');
        } elseif ($tsHasta < $tsDesde) {
            flash('error', 'La fecha hasta no puede ser anterior a la fecha desde.');
        } else {
            // Días inclusivos: datediff + 1. Este es el calendario, el que
            // dura la ausencia; sirve para saber cuándo vuelve la persona.
            $dias = (int) floor(($tsHasta - $tsDesde) / 86400) + 1;

            // Y este es el que consume derecho: el art. 177 concede días
            // LABORABLES. Contar de calendario le comía a la persona los
            // domingos de su propio descanso, dos por cada quincena de asueto.
            $laborables = $tipo === 'vacaciones' ? vac_dias_laborables($desde, $hasta) : $dias;

            $nid = dbInsert('vacaciones', [
                'empleado_id'     => $empleadoId,
                'tipo'            => $tipo,
                'subtipo'         => $subtipo,
                'fecha_solicitud' => date('Y-m-d'),
                'fecha_desde'     => $desde,
                'fecha_hasta'     => $hasta,
                'dias'            => $dias,
                'dias_laborables' => $laborables,
                'con_goce'        => $conGoce,
                'estado'          => 'solicitada',
                'motivo'          => $motivo,
            ]);
            audit('rrhh_vacaciones', 'crear', "Solicitud de $tipo creada (empleado #$empleadoId, $dias día(s))", ['tabla' => 'vacaciones', 'registro_id' => $nid]);

            // Se avisa, no se bloquea: adelantar vacaciones del año siguiente
            // es una decisión de la empresa, y hay ausencias sin goce que son
            // legítimas. Lo que no puede pasar es que nadie se entere.
            if ($tipo === 'vacaciones') {
                $bal = vac_balance($emp);
                if ($bal['derecho'] > 0 && $laborables > $bal['saldo'] + 0.01) {
                    flash('warning', trim($emp['nombre'] . ' ' . $emp['apellido']) . ' tenía '
                        . number_format($bal['saldo'], 2) . ' día(s) de saldo y se le apuntaron '
                        . number_format($laborables, 2) . ' laborables. ' . $bal['regla']
                        . '; ya llevaba ' . number_format($bal['disfrutadas'], 2)
                        . ' disfrutado(s) en este año de servicio.');
                } elseif ($bal['derecho'] === 0) {
                    flash('warning', trim($emp['nombre'] . ' ' . $emp['apellido']) . ': ' . $bal['regla'] . '.');
                }
            }
            flash('success', 'Solicitud registrada correctamente.');
        }
        redirect('modules/rrhh/vacaciones.php');
    }

    if ($accion === 'aprobar' || $accion === 'rechazar') {
        require_perm('rrhh_vacaciones.aprobar');
        $id = postInt('id');
        // Solo se procesan solicitudes en estado 'solicitada' dentro del alcance de sucursal.
        [$wScope, $pScope] = sucursalScope('e.sucursal_id');
        $sol = qOne(
            "SELECT v.id, v.tipo, v.estado, v.empleado_id
               FROM vacaciones v
               JOIN empleados e ON e.id = v.empleado_id
              WHERE v.id = ? AND $wScope",
            array_merge([$id], $pScope)
        );

        if (!$sol) {
            flash('error', 'Solicitud no encontrada.');
        } elseif ($sol['estado'] !== 'solicitada') {
            flash('error', 'Solo se pueden aprobar o rechazar solicitudes pendientes.');
        } else {
            $u = current_user();
            $nuevoEstado = $accion === 'aprobar' ? 'aprobada' : 'rechazada';
            tx(function () use ($sol, $nuevoEstado, $u, $accion) {
                dbUpdate('vacaciones', [
                    'estado'       => $nuevoEstado,
                    'aprobado_por' => $u['id'] ?? null,
                ], 'id = ?', [(int) $sol['id']]);
                // Al aprobar vacaciones, el empleado pasa a estado 'vacaciones'.
                if ($accion === 'aprobar' && $sol['tipo'] === 'vacaciones') {
                    dbUpdate('empleados', ['estado' => 'vacaciones'], 'id = ?', [(int) $sol['empleado_id']]);
                }
            });
            audit('rrhh_vacaciones', $accion, "Solicitud #{$sol['id']} {$nuevoEstado}", ['tabla' => 'vacaciones', 'registro_id' => (int) $sol['id']]);
            flash('success', $accion === 'aprobar' ? 'Solicitud aprobada.' : 'Solicitud rechazada.');
        }
        redirect('modules/rrhh/vacaciones.php');
    }

    redirect('modules/rrhh/vacaciones.php');
}

// ---------- Filtros y listado ----------
$fEstado   = trim(get('estado'));
$fEmpleado = (int) get('empleado');
$puedeAprobar = can('rrhh_vacaciones.aprobar');

[$wScope, $pScope] = sucursalScope('e.sucursal_id');
$where  = [$wScope];
$params = $pScope;

if (in_array($fEstado, ['solicitada', 'aprobada', 'rechazada', 'disfrutada'], true)) {
    $where[] = 'v.estado = ?';
    $params[] = $fEstado;
}
if ($fEmpleado > 0) {
    $where[] = 'v.empleado_id = ?';
    $params[] = $fEmpleado;
}
// Búsqueda por nombre: con 56 empleados, encontrar a alguien en el desplegable
// es más lento que escribir tres letras.
$q = trim(get('q'));
if ($q !== '') {
    $where[] = "(e.nombre LIKE ? OR e.apellido LIKE ? OR CONCAT(e.nombre,' ',e.apellido) LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
$whereSql = implode(' AND ', $where);

// Antes esta consulta NO tenía límite: traía todas las solicitudes de la
// historia de la empresa en cada carga. Con 58 personas pidiendo vacaciones
// cada año, eso crece para siempre.
$pg = paginar((int) qVal(
    "SELECT COUNT(*) FROM vacaciones v JOIN empleados e ON e.id = v.empleado_id WHERE $whereSql",
    $params
), 25);

$solicitudes = qAll(
    "SELECT v.*,
            e.nombre AS emp_nombre, e.apellido AS emp_apellido,
            ap.nombre AS aprob_nombre, ap.apellido AS aprob_apellido
       FROM vacaciones v
       JOIN empleados e ON e.id = v.empleado_id
       LEFT JOIN usuarios ap ON ap.id = v.aprobado_por
      WHERE $whereSql
      ORDER BY v.created_at DESC, v.id DESC
      LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    $params
);

// Empleados (alcance de sucursal) para el select de nueva solicitud y el filtro.
[$wEmp, $pEmp] = sucursalScope('e.sucursal_id');
$empleadosLista = qAll(
    "SELECT e.id, e.nombre, e.apellido
       FROM empleados e
      WHERE e.estado = 'activo' AND $wEmp
      ORDER BY e.nombre, e.apellido",
    $pEmp
);

// KPIs
$mesIni = date('Y-m-01');
$mesFin = date('Y-m-t');
[$wK, $pK] = sucursalScope('e.sucursal_id');
$kpiPendientes = (int) qVal(
    "SELECT COUNT(*) FROM vacaciones v JOIN empleados e ON e.id = v.empleado_id WHERE v.estado = 'solicitada' AND $wK",
    $pK
);
$kpiAprobadasMes = (int) qVal(
    "SELECT COUNT(*) FROM vacaciones v JOIN empleados e ON e.id = v.empleado_id
      WHERE v.estado = 'aprobada' AND v.fecha_solicitud BETWEEN ? AND ? AND $wK",
    array_merge([$mesIni, $mesFin], $pK)
);
$kpiEnVacaciones = (int) qVal(
    "SELECT COUNT(*) FROM empleados e WHERE e.estado = 'vacaciones' AND $wK",
    $pK
);

/* ---------------------------------------------------------------------------
 *  Saldo de vacaciones de cada quien (art. 177)
 *
 *  Esta pantalla apuntaba solicitudes y nada más: nadie sabía cuántos días le
 *  tocaban a cada persona ni cuántos llevaba tomados. Así no hay forma de
 *  aprobar unas vacaciones con criterio, ni de saber lo que la empresa debe en
 *  días acumulados, que es una deuda real aunque no esté en el banco.
 *
 *  Se calcula sobre el AÑO DE SERVICIO de cada quien —desde su aniversario de
 *  ingreso—, no sobre el año natural: si no, quien entró en septiembre parece
 *  no tener vacaciones cada enero.
 * ------------------------------------------------------------------------ */
$saldos = [];
$nexoTodos = qAll("SELECT e.id, e.nombre, e.apellido, e.fecha_ingreso, e.salario
                     FROM empleados e
                    WHERE e.estado IN ('activo','vacaciones') AND $wEmp
                    ORDER BY e.nombre, e.apellido", $pEmp);
foreach ($nexoTodos as $emp) {
    $b = vac_balance($emp);
    if ($b['derecho'] <= 0) continue;   // todavía no genera derecho: no es saldo, es nada
    $saldos[] = $b + [
        'id'      => (int) $emp['id'],
        'nombre'  => trim($emp['nombre'] . ' ' . $emp['apellido']),
        // Lo que costaría pagarlos, que es la medida de lo que se debe.
        'importe' => round($b['saldo'] * ((float) $emp['salario'] / 23.83), 2),
    ];
}
// Primero quien más días acumula: es quien más riesgo tiene de perderlos y
// más deuda representa.
usort($saldos, fn($a, $b) => $b['saldo'] <=> $a['saldo']);

/* Por qué no hay a quién enseñar, cuando no lo hay.
 *
 *  El panel se escondía entero si nadie generaba derecho, y quien lo buscaba no
 *  podía saber si faltaba la función, estaba rota, o es que de verdad no hay
 *  saldo. En producción pasa lo tercero, y por un motivo que importa: 56 de 57
 *  personas comparten la fecha de ingreso de la carga inicial, así que nadie
 *  llega a los cinco meses del art. 177. */
$sinDerecho = [];
foreach ($nexoTodos as $e) {
    $d = vac_derecho_anual((string) $e['fecha_ingreso']);
    if ($d['dias'] <= 0) $sinDerecho[$d['regla']] = ($sinDerecho[$d['regla']] ?? 0) + 1;
}
arsort($sinDerecho);

// La señal de que las fechas son un marcador y no la antigüedad real.
$mismaFecha = qOne("SELECT fecha_ingreso, COUNT(*) n FROM empleados
                     WHERE estado IN ('activo','vacaciones') AND fecha_ingreso IS NOT NULL
                     GROUP BY fecha_ingreso ORDER BY n DESC LIMIT 1");
$saldoDias    = array_sum(array_column($saldos, 'saldo'));
$saldoImporte = array_sum(array_column($saldos, 'importe'));

$colorEstado = ['solicitada' => 'amber', 'aprobada' => 'emerald', 'rechazada' => 'rose', 'disfrutada' => 'sky'];

$acciones = can('rrhh_vacaciones.crear') ? btn_nuevo('vac:new', 'Nueva solicitud') : '';
layout_start('Vacaciones y Licencias', 'Gestiona las solicitudes de vacaciones y licencias del personal', $acciones);
?>

<!-- KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
  <div class="card px-5 py-4 flex items-center gap-4">
    <span class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center"><?= icon('clock', 'w-5 h-5') ?></span>
    <div>
      <div class="text-xs text-slate-400 font-medium">Solicitudes pendientes</div>
      <div class="text-2xl font-bold text-slate-700"><?= $kpiPendientes ?></div>
    </div>
  </div>
  <div class="card px-5 py-4 flex items-center gap-4">
    <span class="w-11 h-11 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center"><?= icon('check', 'w-5 h-5') ?></span>
    <div>
      <div class="text-xs text-slate-400 font-medium">Aprobadas este mes</div>
      <div class="text-2xl font-bold text-slate-700"><?= $kpiAprobadasMes ?></div>
    </div>
  </div>
  <div class="card px-5 py-4 flex items-center gap-4">
    <span class="w-11 h-11 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center"><?= icon('sun', 'w-5 h-5') ?></span>
    <div>
      <div class="text-xs text-slate-400 font-medium">Empleados de vacaciones</div>
      <div class="text-2xl font-bold text-slate-700"><?= $kpiEnVacaciones ?></div>
    </div>
  </div>
</div>

<div class="card overflow-hidden">
  <!-- Filtros -->
  <div class="p-4 border-b border-slate-100">
    <form method="get" class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label">Estado</label>
        <select name="estado" class="select" onchange="this.form.submit()">
          <option value="">Todos</option>
          <option value="solicitada" <?= $fEstado === 'solicitada' ? 'selected' : '' ?>>Solicitadas</option>
          <option value="aprobada"   <?= $fEstado === 'aprobada' ? 'selected' : '' ?>>Aprobadas</option>
          <option value="rechazada"  <?= $fEstado === 'rechazada' ? 'selected' : '' ?>>Rechazadas</option>
          <option value="disfrutada" <?= $fEstado === 'disfrutada' ? 'selected' : '' ?>>Disfrutadas</option>
        </select>
      </div>
      <div>
        <label class="label">Empleado</label>
        <select name="empleado" class="select" onchange="this.form.submit()">
          <option value="0">Todos</option>
          <?php foreach ($empleadosLista as $el): ?>
            <option value="<?= (int) $el['id'] ?>" <?= $fEmpleado === (int) $el['id'] ? 'selected' : '' ?>>
              <?= e($el['nombre'] . ' ' . $el['apellido']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="min-w-[220px]">
        <label class="label" for="vac_q">Buscar</label>
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
          <input id="vac_q" type="search" name="q" value="<?= e($q) ?>" data-buscar
                 placeholder="Nombre del empleado..." class="input pl-9" autocomplete="off">
        </div>
      </div>
      <button type="submit" class="btn btn-soft"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
      <?php if ($fEstado !== '' || $fEmpleado > 0 || $q !== ''): ?>
        <a href="<?= url('modules/rrhh/vacaciones.php') ?>" class="btn btn-ghost">Limpiar</a>
      <?php endif; ?>
      <span class="ml-auto text-sm text-slate-400 self-center">
        <?= number_format($pg['total']) ?> solicitud(es)
        <?php if ($pg['totalPag'] > 1): ?>
          · <?= number_format($pg['desde']) ?>–<?= number_format($pg['hasta']) ?>
        <?php endif; ?>
      </span>
    </form>
  </div>

  <?php if (!$solicitudes): ?>
    <?= empty_state('Sin solicitudes', 'No hay solicitudes de vacaciones o licencias con los filtros actuales.', 'sun',
        can('rrhh_vacaciones.crear') ? btn_nuevo('vac:new', 'Nueva solicitud') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Empleado</th>
            <th>Tipo</th>
            <th>Subtipo</th>
            <th>Periodo</th>
            <th class="text-center">Días</th>
            <th class="text-center">Con goce</th>
            <th>Estado</th>
            <?php if ($puedeAprobar): ?><th class="text-right">Acciones</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($solicitudes as $s): ?>
            <?php $nombreEmp = $s['emp_nombre'] . ' ' . $s['emp_apellido']; ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <?= avatar($nombreEmp) ?>
                  <span class="font-semibold text-slate-700"><?= e($nombreEmp) ?></span>
                </div>
              </td>
              <td><?= $s['tipo'] === 'vacaciones' ? badge('Vacaciones', 'indigo') : badge('Licencia', 'violet') ?></td>
              <td class="text-slate-500"><?= e($s['subtipo'] ? ucfirst($s['subtipo']) : '—') ?></td>
              <td class="text-slate-600 whitespace-nowrap"><?= e(fechaCorta($s['fecha_desde'])) ?> <span class="text-slate-300">→</span> <?= e(fechaCorta($s['fecha_hasta'])) ?></td>
              <?php // El calendario dice cuánto dura la ausencia; los laborables
                       // son los que consumen el derecho del art. 177. No son el
                       // mismo número y confundirlos le come días a la persona. ?>
              <td class="text-center">
                <span class="badge badge-slate"><?= (int) $s['dias'] ?></span>
                <?php if ($s['tipo'] === 'vacaciones' && $s['dias_laborables'] !== null
                          && (float) $s['dias_laborables'] !== (float) $s['dias']): ?>
                  <span class="block text-xs text-slate-400 mt-0.5" title="Días laborables, sin domingos: son los que consumen el derecho">
                    <?= rtrim(rtrim(number_format((float) $s['dias_laborables'], 2), '0'), '.') ?> laborables
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-center"><?= $s['con_goce'] ? '<span class="text-emerald-600 font-medium">Sí</span>' : '<span class="text-slate-400">No</span>' ?></td>
              <td>
                <?= badge(ucfirst($s['estado']), $colorEstado[$s['estado']] ?? 'slate') ?>
                <?php if ($s['estado'] !== 'solicitada' && $s['aprob_nombre']): ?>
                  <div class="text-xs text-slate-400 mt-1">por <?= e($s['aprob_nombre'] . ' ' . $s['aprob_apellido']) ?></div>
                <?php endif; ?>
              </td>
              <?php if ($puedeAprobar): ?>
                <td>
                  <div class="flex items-center justify-end gap-1">
                    <?php if ($s['estado'] === 'solicitada'): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Aprobar esta solicitud de <?= e($nombreEmp) ?>?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="aprobar">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button class="btn btn-sm btn-success" title="Aprobar"><?= icon('check', 'w-4 h-4') ?> Aprobar</button>
                      </form>
                      <form method="post" class="inline" onsubmit="return confirm('¿Rechazar esta solicitud de <?= e($nombreEmp) ?>?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="rechazar">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button class="btn btn-sm btn-danger" title="Rechazar"><?= icon('x', 'w-4 h-4') ?> Rechazar</button>
                      </form>
                    <?php else: ?>
                      <span class="text-xs text-slate-300">—</span>
                    <?php endif; ?>
                  </div>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<?php if (!$saldos && $nexoTodos): ?>
  <?php /* Un panel que desaparece no dice nada. Si no hay saldo, hay un motivo
           y conviene decirlo: casi siempre es que las fechas de ingreso no son
           las de verdad, y de esas fechas salen también el preaviso, la
           cesantía y la regalía. */ ?>
  <div class="card p-5 mt-6 border-l-4 border-slate-300">
    <h3 class="font-bold text-slate-800 mb-1">Todavía no hay días que deber</h3>
    <p class="text-sm text-slate-600">
      Ninguna de las <?= count($nexoTodos) ?> personas genera vacaciones ahora mismo:
      <?php foreach ($sinDerecho as $regla => $n): ?>
        <span class="block text-xs mt-0.5">· <?= (int) $n ?> — <?= e(mb_strtolower($regla)) ?></span>
      <?php endforeach; ?>
    </p>
    <?php if ($mismaFecha && (int) $mismaFecha['n'] >= 10
              && (int) $mismaFecha['n'] >= count($nexoTodos) * 0.4): ?>
      <div class="mt-3 rounded-xl bg-amber-50 border border-amber-200 p-3">
        <p class="text-sm text-amber-800">
          <?= icon('alert', 'w-4 h-4 inline -mt-0.5') ?>
          <strong><?= (int) $mismaFecha['n'] ?> de <?= count($nexoTodos) ?></strong> figuran con la misma fecha
          de ingreso, el <?= e(fechaCorta($mismaFecha['fecha_ingreso'])) ?>. Eso suele ser la fecha en que se
          cargó el padrón, no la de cada quien.
        </p>
        <p class="text-xs text-amber-700 mt-1">
          De esa fecha salen también el preaviso, la cesantía y la regalía proporcional. Mientras no
          se corrijan, esos cálculos dan una cifra equivocada sin avisar.
          <?php if (can('rrhh_empleados.ver')): ?>
            <a class="link" href="<?= e(url('modules/rrhh/empleados.php')) ?>">Revisar el padrón</a>.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($saldos): ?>
<?php /* Los días acumulados son una deuda de la empresa aunque no estén en el
         banco: si mañana se va la persona, hay que pagárselos. Enseñarlos
         cuesta poco y evita la sorpresa en la liquidación. */ ?>
<div class="card mt-6" x-data="{ abierto: false }">
  <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0"><?= icon('calendar', 'w-5 h-5') ?></span>
      <div>
        <h3 class="font-bold text-slate-800">Días que se deben</h3>
        <p class="text-xs text-slate-500">Derecho del art. 177 menos lo ya disfrutado, por año de servicio de cada quien</p>
      </div>
    </div>
    <div class="flex items-center gap-4">
      <div class="text-right">
        <p class="text-xs text-slate-400">Acumulado</p>
        <p class="font-bold text-slate-800"><?= rtrim(rtrim(number_format($saldoDias, 2), '0'), '.') ?> día(s) · <?= money($saldoImporte) ?></p>
      </div>
      <button type="button" @click="abierto = !abierto" class="btn btn-soft btn-sm no-print">
        <span x-text="abierto ? 'Ocultar' : 'Ver quiénes'"></span>
      </button>
    </div>
  </div>

  <div x-show="abierto" x-transition x-cloak class="overflow-x-auto">
    <table class="data-table">
      <thead><tr>
        <th>Empleado</th>
        <th>Año de servicio en curso</th>
        <th class="text-center">Le tocan</th>
        <th class="text-center">Tomados</th>
        <th class="text-center">Le quedan</th>
        <th class="text-right">Si hubiera que pagarlos</th>
      </tr></thead>
      <tbody>
        <?php foreach ($saldos as $s): ?>
          <tr>
            <td>
              <div class="flex items-center gap-3">
                <?= avatar($s['nombre']) ?>
                <div>
                  <p class="font-semibold text-slate-700"><?= e($s['nombre']) ?></p>
                  <p class="text-xs text-slate-400"><?= e($s['regla']) ?></p>
                </div>
              </div>
            </td>
            <td class="text-slate-600 whitespace-nowrap text-sm"><?= e(fechaCorta($s['desde'])) ?> <span class="text-slate-300">→</span> <?= e(fechaCorta($s['hasta'])) ?></td>
            <td class="text-center text-slate-600"><?= (int) $s['derecho'] ?></td>
            <td class="text-center text-slate-500"><?= $s['disfrutadas'] > 0 ? rtrim(rtrim(number_format($s['disfrutadas'], 2), '0'), '.') : '—' ?></td>
            <td class="text-center">
              <span class="badge <?= $s['saldo'] <= 0 ? 'badge-slate' : ($s['saldo'] >= 14 ? 'badge-amber' : 'badge-emerald') ?>">
                <?= rtrim(rtrim(number_format($s['saldo'], 2), '0'), '.') ?>
              </span>
            </td>
            <td class="text-right text-slate-600"><?= money($s['importe']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="px-5 py-3 text-xs text-slate-400 border-t border-slate-100">
      Los días son laborables, sin domingos. Los feriados nacionales no se descuentan
      porque el sistema no lleva calendario de feriados: si unas vacaciones caen sobre uno,
      hay que bajar el número a mano.
    </p>
  </div>
</div>
<?php endif; ?>

<?php if (can('rrhh_vacaciones.crear')): ?>
<!-- Modal nueva solicitud -->
<div x-data="{open:false, form:{empleado_id:'', tipo:'vacaciones', subtipo:'', fecha_desde:'', fecha_hasta:'', con_goce:1, motivo:''}}"
     @vac:new.window="form={empleado_id:'', tipo:'vacaciones', subtipo:'', fecha_desde:'', fecha_hasta:'', con_goce:1, motivo:''}; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nueva solicitud</h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Empleado *</label>
            <select name="empleado_id" x-model="form.empleado_id" required class="select">
              <option value="" disabled>Selecciona un empleado</option>
              <?php foreach ($empleadosLista as $el): ?>
                <option value="<?= (int) $el['id'] ?>"><?= e($el['nombre'] . ' ' . $el['apellido']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Tipo *</label>
              <select name="tipo" x-model="form.tipo" class="select">
                <option value="vacaciones">Vacaciones</option>
                <option value="licencia">Licencia</option>
              </select>
            </div>
            <div x-show="form.tipo === 'licencia'">
              <label class="label">Subtipo</label>
              <select name="subtipo" x-model="form.subtipo" class="select">
                <option value="">—</option>
                <option value="enfermedad">Enfermedad</option>
                <option value="personal">Personal</option>
                <option value="maternidad">Maternidad</option>
                <option value="duelo">Duelo</option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Desde *</label>
              <input type="date" name="fecha_desde" x-model="form.fecha_desde" required class="input">
            </div>
            <div>
              <label class="label">Hasta *</label>
              <input type="date" name="fecha_hasta" x-model="form.fecha_hasta" required class="input">
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="con_goce" value="0">
            <input type="checkbox" name="con_goce" value="1" :checked="form.con_goce==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Con goce de sueldo
          </label>
          <div>
            <label class="label">Motivo</label>
            <textarea name="motivo" x-model="form.motivo" rows="2" class="input" placeholder="Opcional"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar solicitud</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
