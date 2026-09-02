<?php
/**
 * Ficha completa de un empleado.
 *
 * Todo lo que el sistema sabe de una persona en una sola pantalla: sus datos,
 * lo que ha cobrado, lo que le cuesta a la empresa de verdad, su asistencia y
 * sus permisos.
 *
 * LA CIFRA QUE NADIE TENÍA
 *
 * «Cuánto cuesta este empleado» no es su salario, y tampoco es su salario menos
 * lo que se le retiene. Lo que se le retiene (AFP 2.87%, SFS 3.04%) sale de SU
 * bolsillo, no del de la empresa. La empresa aporta APARTE —pensiones, salud,
 * riesgos laborales, INFOTEP— y encima provisiona la regalía. Un sueldo de
 * 29,998 le cuesta a Importers 37,384.51 al mes.
 *
 * Ese cálculo vive en `costoEmpleadorRD()` (includes/nomina.php), que es una
 * función pura y con pruebas, no una fórmula escondida en una plantilla.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('rrhh_empleados.ver');

$id = (int) get('id');
$e = qOne(
    "SELECT e.*, p.nombre AS puesto, d.nombre AS departamento, s.nombre AS sucursal,
            t.nombre AS marca
       FROM empleados e
       LEFT JOIN puestos       p ON p.id = e.puesto_id
       LEFT JOIN departamentos d ON d.id = e.departamento_id
       LEFT JOIN sucursales    s ON s.id = e.sucursal_id
       LEFT JOIN tiendas       t ON t.id = s.tienda_id
      WHERE e.id = ?",
    [$id]
);
if (!$e) { flash('error', 'Empleado no encontrado.'); redirect('modules/rrhh/empleados.php'); }
require_sucursal_access($e['sucursal_id']);

$nombre = $e['nombre'] . ' ' . $e['apellido'];

/* ---------------------------------------------------------------------------
 *  Historial de nóminas
 * ------------------------------------------------------------------------ */
$lineas = qAll(
    "SELECT nd.*, n.descripcion, n.fecha_desde, n.fecha_hasta, n.estado, n.tipo
       FROM nomina_detalles nd JOIN nominas n ON n.id = nd.nomina_id
      WHERE nd.empleado_id = ?
      ORDER BY n.fecha_hasta DESC, n.id DESC",
    [$id]
);
// Solo lo PAGADO cuenta como dinero que salió: una nómina en borrador todavía
// puede cambiar, y sumarla daría un histórico que no cuadra con la caja.
$pagadas = array_values(array_filter($lineas, fn($l) => $l['estado'] === 'pagada'));
$sumar = fn(array $filas, string $k) => array_sum(array_map(fn($x) => (float) $x[$k], $filas));

$cobrado   = $sumar($pagadas, 'salario_neto');
$retenido  = $sumar($pagadas, 'total_deducciones');
$isrPagado = $sumar($pagadas, 'isr');

/* ---------------------------------------------------------------------------
 *  Costo real
 * ------------------------------------------------------------------------ */
$costo = costoEmpleadorRD((float) $e['salario']);

/* ---------------------------------------------------------------------------
 *  Asistencia y permisos
 * ------------------------------------------------------------------------ */
$asis = qOne(
    "SELECT COUNT(*) AS dias,
            SUM(estado = 'presente') AS presentes,
            SUM(estado = 'ausente')  AS ausentes,
            SUM(estado = 'tardanza') AS tardanzas,
            COALESCE(SUM(horas_trabajadas), 0) AS horas,
            COALESCE(SUM(horas_extra), 0)      AS extras
       FROM asistencias WHERE empleado_id = ?",
    [$id]
) ?: [];

$permisos = qAll(
    "SELECT * FROM vacaciones WHERE empleado_id = ? ORDER BY fecha_desde DESC LIMIT 10",
    [$id]
);

/* ---------------------------------------------------------------------------
 *  Antigüedad
 * ------------------------------------------------------------------------ */
$antiguedad = '—';
if (!empty($e['fecha_ingreso'])) {
    $desde = new DateTime($e['fecha_ingreso']);
    $hasta = new DateTime($e['fecha_salida'] ?: 'today');
    $d = $desde->diff($hasta);
    $antiguedad = $d->y > 0
        ? $d->y . ' año(s) ' . ($d->m ? $d->m . ' mes(es)' : '')
        : ($d->m > 0 ? $d->m . ' mes(es)' : $d->days . ' día(s)');
}

$acc = '<a href="' . url('modules/rrhh/empleados.php') . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Volver</a>';
// El papel que la persona pide para un préstamo o una visa. Solo tiene sentido
// si ya cobró algo: sin nómina pagada el documento sale vacío.
if (can('rrhh_nomina.ver')
    && qVal("SELECT 1 FROM nomina_detalles nd JOIN nominas n ON n.id = nd.nomina_id
              WHERE nd.empleado_id = ? AND n.estado = 'pagada' AND YEAR(n.fecha_hasta) = ? LIMIT 1",
            [$id, (int) date('Y')])) {
    $acc .= '<a href="' . url('modules/rrhh/constancia_isr.php?empleado=' . $id . '&anio=' . date('Y'))
          . '" target="_blank" class="btn btn-ghost">' . icon('file', 'w-4 h-4')
          . ' Certificación ' . date('Y') . '</a>';
}
layout_start('Ficha de ' . e($nombre), e($e['puesto'] ?: 'Sin puesto asignado') . ' · ' . e($e['sucursal'] ?: 'sin sucursal'), $acc);
?>

<!-- Identidad -->
<div class="card p-6 mb-5">
  <div class="flex flex-wrap items-start gap-5">
    <?= avatar($nombre, 'w-16 h-16 text-xl') ?>
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-3 flex-wrap">
        <h2 class="text-xl font-extrabold text-slate-800"><?= e($nombre) ?></h2>
        <?= badgeFor($e['estado']) ?>
        <?php if ($e['marca']): ?><?= badge($e['marca'], 'slate') ?><?php endif; ?>
      </div>
      <p class="text-sm text-slate-500 mt-1">
        <?= e($e['codigo']) ?> · <?= e($e['cedula']) ?>
        <?= $e['fecha_nacimiento'] ? ' · nacida/o el ' . fechaCorta($e['fecha_nacimiento']) : '' ?>
      </p>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-2 mt-4 text-sm">
        <div><p class="text-slate-400">Puesto</p><p class="font-semibold text-slate-700"><?= e($e['puesto'] ?: '— sin asignar') ?></p></div>
        <div><p class="text-slate-400">Departamento</p><p class="font-semibold text-slate-700"><?= e($e['departamento'] ?: '—') ?></p></div>
        <div><p class="text-slate-400">Sucursal</p><p class="font-semibold text-slate-700"><?= e($e['sucursal'] ?: '—') ?></p></div>
        <div><p class="text-slate-400">Antigüedad</p><p class="font-semibold text-slate-700"><?= e($antiguedad) ?></p></div>
        <div><p class="text-slate-400">Ingreso</p><p class="font-semibold text-slate-700"><?= $e['fecha_ingreso'] ? fechaCorta($e['fecha_ingreso']) : '—' ?></p></div>
        <div><p class="text-slate-400">Contrato</p><p class="font-semibold text-slate-700"><?= e(ucfirst(str_replace('_', ' ', $e['tipo_contrato']))) ?></p></div>
        <div><p class="text-slate-400">Cobra por</p><p class="font-semibold text-slate-700"><?= e(ucfirst($e['metodo_pago'])) ?></p></div>
        <div><p class="text-slate-400">Banco</p><p class="font-semibold text-slate-700"><?= e($e['banco'] ?: '—') ?></p></div>
      </div>
      <?php if ($e['metodo_pago'] === 'transferencia' && !$e['cuenta_bancaria']): ?>
        <p class="mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 inline-block">
          <?= icon('alert', 'w-4 h-4 inline align-text-bottom') ?>
          Cobra por transferencia pero no tiene cuenta registrada: queda fuera del archivo del banco.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Las cuatro cifras -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-sm text-slate-400">Salario mensual</p>
    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= money($e['salario']) ?></p>
  </div>
  <div class="card p-5 border-blue-200 bg-blue-50/40">
    <p class="text-sm text-blue-700">Costo real para la empresa</p>
    <p class="text-xl font-extrabold text-blue-800 mt-1"><?= money($costo['total']) ?></p>
    <p class="text-xs text-blue-600/80 mt-0.5">+<?= number_format($costo['recargo'], 2) ?>% sobre el salario</p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-400">Nóminas cobradas</p>
    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= count($pagadas) ?></p>
    <?php if (count($lineas) > count($pagadas)): ?>
      <p class="text-xs text-amber-600 mt-0.5"><?= count($lineas) - count($pagadas) ?> sin pagar todavía</p>
    <?php endif; ?>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-400">Neto cobrado</p>
    <p class="text-xl font-extrabold text-emerald-600 mt-1"><?= money($cobrado) ?></p>
    <p class="text-xs text-slate-400 mt-0.5">retenido <?= money($retenido, false) ?></p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
  <!-- Desglose del costo -->
  <div class="card overflow-hidden lg:col-span-1">
    <div class="px-5 py-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Qué compone ese costo</h3>
      <p class="text-xs text-slate-500 mt-0.5">Al mes, sobre <?= money($e['salario'], false) ?></p>
    </div>
    <div class="p-5 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Salario</span><span class="font-semibold text-slate-700"><?= money($costo['salario'], false) ?></span></div>
      <?php
        $etiquetas = ['afp' => 'AFP patronal 7.10%', 'sfs' => 'SFS patronal 7.09%',
                      'riesgos' => 'Riesgos laborales 1.10%', 'infotep' => 'INFOTEP 1.00%'];
        foreach ($costo['partes'] as $k => $v): ?>
        <div class="flex justify-between"><span class="text-slate-500"><?= e($etiquetas[$k] ?? $k) ?></span><span class="text-slate-600"><?= money($v, false) ?></span></div>
      <?php endforeach; ?>
      <div class="flex justify-between"><span class="text-slate-500">Provisión de regalía (1/12)</span><span class="text-slate-600"><?= money($costo['regalia'], false) ?></span></div>
      <div class="flex justify-between pt-2 border-t border-slate-100">
        <span class="font-bold text-slate-800">Costo mensual</span>
        <span class="font-extrabold text-blue-700"><?= money($costo['total'], false) ?></span>
      </div>
      <div class="flex justify-between">
        <span class="font-semibold text-slate-600">Costo anual</span>
        <span class="font-bold text-slate-700"><?= money($costo['anual'], false) ?></span>
      </div>
      <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800 mt-3">
        <strong>Falta confirmar con el contador:</strong>
        <?= e(implode(' y ', COSTO_PENDIENTE_CONFIRMAR)) ?>.
        Sin los topes, un sueldo alto sale con un costo mayor del real.
      </div>
    </div>
  </div>

  <!-- Historial de nóminas -->
  <div class="card overflow-hidden lg:col-span-2">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
      <h3 class="font-bold text-slate-800">Historial de nóminas</h3>
      <span class="text-sm text-slate-400"><?= count($lineas) ?> período(s)</span>
    </div>
    <?php if (!$lineas): ?>
      <?= empty_state('Sin nóminas', 'Esta persona todavía no ha entrado en ninguna nómina.', 'wallet') ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr>
            <th>Período</th><th class="text-right">Base</th><th class="text-right">AFP</th>
            <th class="text-right">SFS</th><th class="text-right">ISR</th><th class="text-right">Neto</th><th>Estado</th>
          </tr></thead>
          <tbody>
            <?php foreach ($lineas as $l): ?>
              <tr>
                <td>
                  <a href="<?= e(url('modules/rrhh/nomina.php?ver=' . (int) $l['nomina_id'])) ?>" class="font-semibold text-slate-700 hover:text-blue-700"><?= e($l['descripcion']) ?></a>
                  <p class="text-xs text-slate-400"><?= fechaCorta($l['fecha_desde']) ?> – <?= fechaCorta($l['fecha_hasta']) ?></p>
                </td>
                <td class="text-right text-slate-600"><?= money($l['total_ingresos'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($l['afp'], false) ?></td>
                <td class="text-right text-slate-500"><?= money($l['sfs'], false) ?></td>
                <td class="text-right <?= (float) $l['isr'] > 0 ? 'text-rose-600 font-medium' : 'text-slate-400' ?>"><?= money($l['isr'], false) ?></td>
                <td class="text-right font-bold text-emerald-600"><?= money($l['salario_neto'], false) ?></td>
                <td><?= badgeFor($l['estado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <?php /* Solo lo PAGADO: un borrador todavía puede cambiar y sumarlo
                     daría un histórico que no cuadra con lo que salió de caja. */ ?>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td>TOTAL PAGADO (<?= count($pagadas) ?>)</td>
              <td class="text-right"><?= money($sumar($pagadas, 'total_ingresos'), false) ?></td>
              <td class="text-right"><?= money($sumar($pagadas, 'afp'), false) ?></td>
              <td class="text-right"><?= money($sumar($pagadas, 'sfs'), false) ?></td>
              <td class="text-right"><?= money($isrPagado, false) ?></td>
              <td class="text-right text-emerald-600"><?= money($cobrado, false) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Asistencia -->
  <div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Asistencia</h3></div>
    <?php if (!(int) ($asis['dias'] ?? 0)): ?>
      <?= empty_state('Sin registros', 'Todavía no se ha registrado asistencia de esta persona.', 'clock') ?>
    <?php else: ?>
      <div class="p-5 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
        <div><p class="text-slate-400">Días registrados</p><p class="text-lg font-bold text-slate-800"><?= (int) $asis['dias'] ?></p></div>
        <div><p class="text-slate-400">Presentes</p><p class="text-lg font-bold text-emerald-600"><?= (int) $asis['presentes'] ?></p></div>
        <div><p class="text-slate-400">Ausentes</p><p class="text-lg font-bold text-rose-600"><?= (int) $asis['ausentes'] ?></p></div>
        <div><p class="text-slate-400">Tardanzas</p><p class="text-lg font-bold text-amber-600"><?= (int) $asis['tardanzas'] ?></p></div>
        <div><p class="text-slate-400">Horas trabajadas</p><p class="text-lg font-bold text-slate-800"><?= qty($asis['horas']) ?></p></div>
        <div><p class="text-slate-400">Horas extra</p><p class="text-lg font-bold text-slate-800"><?= qty($asis['extras']) ?></p></div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Vacaciones y licencias -->
  <div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Vacaciones y licencias</h3></div>
    <?php if (!$permisos): ?>
      <?= empty_state('Sin solicitudes', 'Esta persona no tiene vacaciones ni licencias registradas.', 'sun') ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>Tipo</th><th>Desde</th><th>Hasta</th><th class="text-center">Días</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($permisos as $v): ?>
              <tr>
                <td class="font-semibold text-slate-700"><?= e(ucfirst($v['tipo'])) ?><?= $v['subtipo'] ? ' · ' . e($v['subtipo']) : '' ?></td>
                <td class="text-slate-500"><?= fechaCorta($v['fecha_desde']) ?></td>
                <td class="text-slate-500"><?= fechaCorta($v['fecha_hasta']) ?></td>
                <td class="text-center"><?= (int) $v['dias'] ?></td>
                <td><?= badgeFor($v['estado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php layout_end(); ?>
