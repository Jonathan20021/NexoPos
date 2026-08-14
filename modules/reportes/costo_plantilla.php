<?php
/**
 * Lo que cuesta la plantilla que hay HOY, por sucursal y por marca.
 *
 * No confundir con «Resumen de nómina», que mira hacia atrás: cuánto costaron
 * las nóminas ya procesadas de un período. Este mira el presente y responde
 * otra pregunta: con la gente que tengo contratada ahora mismo, ¿cuánto me
 * cuesta cada local y cada marca, al mes y al año?
 *
 * Es la cifra que se necesita para decidir si una tienda se sostiene, y no sale
 * de ninguna nómina: sale del padrón.
 *
 * EL COSTO NO ES EL SALARIO
 *
 * Sobre el salario, la empresa aporta AFP 7.10%, SFS 7.09%, riesgos laborales
 * 1.10% e INFOTEP 1.00%, y provisiona la regalía (un doceavo). Un 24.62% por
 * encima. Las tasas son las de `COSTO_EMPLEADOR` en includes/nomina.php, la
 * misma fuente que usa la ficha del empleado y el resumen de nómina.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.contabilidad');

[$scope, $scopeP] = sucursalFiltro('e.sucursal_id');

/**
 * Agrupa el padrón activo por lo que se le pida y le calcula el costo.
 *
 * El costo se suma EMPLEADO A EMPLEADO y no aplicando el porcentaje al total
 * del grupo: da lo mismo mientras las tasas sean planas, pero el día que entren
 * los topes de la TSS —que dependen del sueldo de cada persona— la suma por
 * grupo daría un número falso y nadie se enteraría.
 */
function costoAgrupado(string $selectEtiqueta, string $join, string $groupBy, string $scope, array $scopeP): array
{
    $filas = qAll(
        "SELECT $selectEtiqueta AS etiqueta, e.salario
           FROM empleados e $join
          WHERE e.estado = 'activo' AND $scope",
        $scopeP
    );

    $grupos = [];
    foreach ($filas as $f) {
        $k = $f['etiqueta'] ?: '— sin asignar';
        $c = costoEmpleadorRD((float) $f['salario']);
        if (!isset($grupos[$k])) {
            $grupos[$k] = ['etiqueta' => $k, 'empleados' => 0, 'salarios' => 0.0,
                           'aportes' => 0.0, 'regalia' => 0.0, 'total' => 0.0];
        }
        $grupos[$k]['empleados']++;
        $grupos[$k]['salarios'] += $c['salario'];
        $grupos[$k]['aportes']  += $c['aportes'];
        $grupos[$k]['regalia']  += $c['regalia'];
        $grupos[$k]['total']    += $c['total'];
    }
    uasort($grupos, fn($a, $b) => $b['total'] <=> $a['total']);
    return array_values($grupos);
}

$porSucursal = costoAgrupado('s.nombre', 'LEFT JOIN sucursales s ON s.id = e.sucursal_id', '', $scope, $scopeP);
$porMarca    = costoAgrupado('t.nombre', 'LEFT JOIN sucursales s ON s.id = e.sucursal_id
                                          LEFT JOIN tiendas t ON t.id = s.tienda_id', '', $scope, $scopeP);
$porDepto    = costoAgrupado('d.nombre', 'LEFT JOIN departamentos d ON d.id = e.departamento_id', '', $scope, $scopeP);

// Totales de la empresa.
$tot = ['empleados' => 0, 'salarios' => 0.0, 'aportes' => 0.0, 'regalia' => 0.0, 'total' => 0.0];
foreach ($porSucursal as $g) foreach (array_keys($tot) as $k) $tot[$k] += $g[$k];

$masCaros = qAll(
    "SELECT CONCAT(e.nombre,' ',e.apellido) AS nom, e.salario, s.nombre AS sucursal, p.nombre AS puesto
       FROM empleados e
       LEFT JOIN sucursales s ON s.id = e.sucursal_id
       LEFT JOIN puestos    p ON p.id = e.puesto_id
      WHERE e.estado = 'activo' AND $scope
      ORDER BY e.salario DESC LIMIT 10",
    $scopeP
);

/** Una fila de tabla con su barra de proporción. */
function filaCosto(array $g, float $totalGeneral): string
{
    $pct = $totalGeneral > 0 ? $g['total'] / $totalGeneral * 100 : 0;
    return '<tr>'
        . '<td class="font-semibold text-slate-700">' . e($g['etiqueta']) . '</td>'
        . '<td class="text-center"><span class="badge badge-blue">' . (int) $g['empleados'] . '</span></td>'
        . '<td class="text-right text-slate-600">' . money($g['salarios'], false) . '</td>'
        . '<td class="text-right text-slate-500">' . money($g['aportes'], false) . '</td>'
        . '<td class="text-right text-slate-500">' . money($g['regalia'], false) . '</td>'
        . '<td class="text-right font-bold text-blue-700">' . money($g['total'], false) . '</td>'
        . '<td class="text-right text-slate-500">' . money($g['total'] * 12, false) . '</td>'
        . '<td class="w-32"><div class="flex items-center gap-2">'
        . '<div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">'
        . '<div class="h-full rounded-full bg-blue-500" style="width:' . number_format($pct, 1) . '%"></div></div>'
        . '<span class="text-xs text-slate-400 tabular-nums w-10 text-right">' . number_format($pct, 1) . '%</span>'
        . '</div></td></tr>';
}

if (export_solicitado()) {
    $filas = [];
    foreach ([['SUCURSAL', $porSucursal], ['MARCA', $porMarca], ['DEPARTAMENTO', $porDepto]] as [$titulo, $grupo]) {
        $filas[] = [$titulo, '', '', '', '', '', ''];
        foreach ($grupo as $g) {
            $filas[] = [$g['etiqueta'], $g['empleados'], round($g['salarios'], 2), round($g['aportes'], 2),
                        round($g['regalia'], 2), round($g['total'], 2), round($g['total'] * 12, 2)];
        }
    }
    $filas[] = ['TOTAL EMPRESA', $tot['empleados'], round($tot['salarios'], 2), round($tot['aportes'], 2),
                round($tot['regalia'], 2), round($tot['total'], 2), round($tot['total'] * 12, 2)];
    export_tabla('costo_plantilla',
        ['Grupo', 'Empleados', 'Salarios', 'Aportes patronales', 'Provisión regalía', 'Costo mensual', 'Costo anual'],
        $filas);
}

layout_start('Costo de la plantilla', 'Lo que cuesta hoy la gente contratada, por sucursal y por marca', rep_barra_titulo());
?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-sm text-slate-400">Empleados activos</p>
    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= number_format($tot['empleados']) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-400">Salarios</p>
    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= money($tot['salarios']) ?></p>
    <p class="text-xs text-slate-400 mt-0.5">lo que ve el empleado</p>
  </div>
  <div class="card p-5 border-blue-200 bg-blue-50/40">
    <p class="text-sm text-blue-700">Costo real mensual</p>
    <p class="text-xl font-extrabold text-blue-800 mt-1"><?= money($tot['total']) ?></p>
    <p class="text-xs text-blue-600/80 mt-0.5">
      +<?= $tot['salarios'] > 0 ? number_format(($tot['total'] - $tot['salarios']) / $tot['salarios'] * 100, 2) : '0' ?>% sobre los salarios
    </p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-400">Proyección anual</p>
    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= money($tot['total'] * 12) ?></p>
  </div>
</div>

<div class="card p-4 mb-5 flex items-start gap-3 bg-amber-50 border-amber-200">
  <?= icon('alert', 'w-5 h-5 text-amber-500 mt-0.5 shrink-0') ?>
  <p class="text-sm text-amber-800">
    <strong>Es una proyección del padrón de hoy, no lo que se pagó.</strong>
    Toma el salario de cada empleado activo y le suma los aportes patronales
    (AFP <?= number_format(COSTO_EMPLEADOR['afp'] * 100, 2) ?>%,
    SFS <?= number_format(COSTO_EMPLEADOR['sfs'] * 100, 2) ?>%,
    riesgos <?= number_format(COSTO_EMPLEADOR['riesgos'] * 100, 2) ?>%,
    INFOTEP <?= number_format(COSTO_EMPLEADOR['infotep'] * 100, 2) ?>%) y la provisión de regalía.
    No incluye horas extra, comisiones ni <?= e(implode(' ni ', COSTO_PENDIENTE_CONFIRMAR)) ?>.
    Para lo realmente pagado en un período, mira <a class="underline font-semibold" href="<?= e(url('modules/reportes/nomina.php')) ?>">Resumen de nómina</a>.
  </p>
</div>

<?php
$bloques = [
    ['Por sucursal',     'Dónde trabaja la gente. Es la cifra para decidir si un local se sostiene.', $porSucursal],
    ['Por marca',        'Cuánto cuesta sostener cada marca. Las oficinas no tienen marca y salen aparte.', $porMarca],
    ['Por departamento', 'Qué área se lleva la masa salarial.', $porDepto],
];
foreach ($bloques as [$titulo, $ayuda, $grupo]): ?>
  <div class="card overflow-hidden mb-5">
    <div class="px-5 py-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800"><?= e($titulo) ?></h3>
      <p class="text-xs text-slate-500 mt-0.5"><?= e($ayuda) ?></p>
    </div>
    <?php if (!$grupo): ?>
      <?= empty_state('Sin datos', 'No hay empleados activos en el alcance actual.', 'users') ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr>
            <th><?= e(explode(' ', $titulo)[1] ?? 'Grupo') ?></th>
            <th class="text-center">Empleados</th>
            <th class="text-right">Salarios</th>
            <th class="text-right">Aportes</th>
            <th class="text-right">Regalía</th>
            <th class="text-right">Costo mensual</th>
            <th class="text-right">Costo anual</th>
            <th class="text-right">Peso</th>
          </tr></thead>
          <tbody>
            <?php foreach ($grupo as $g) echo filaCosto($g, $tot['total']); ?>
          </tbody>
          <tfoot>
            <tr class="bg-slate-50 font-bold text-slate-800">
              <td>TOTAL</td>
              <td class="text-center"><?= number_format($tot['empleados']) ?></td>
              <td class="text-right"><?= money($tot['salarios'], false) ?></td>
              <td class="text-right"><?= money($tot['aportes'], false) ?></td>
              <td class="text-right"><?= money($tot['regalia'], false) ?></td>
              <td class="text-right text-blue-700"><?= money($tot['total'], false) ?></td>
              <td class="text-right"><?= money($tot['total'] * 12, false) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="card overflow-hidden">
  <div class="px-5 py-4 border-b border-slate-100">
    <h3 class="font-bold text-slate-800">Los diez salarios más altos</h3>
    <p class="text-xs text-slate-500 mt-0.5">Con su costo real, que es lo que sale de caja.</p>
  </div>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead><tr><th>Empleado</th><th>Puesto</th><th>Sucursal</th><th class="text-right">Salario</th><th class="text-right">Costo real</th><th class="text-right">Anual</th></tr></thead>
      <tbody>
        <?php foreach ($masCaros as $m): $c = costoEmpleadorRD((float) $m['salario']); ?>
          <tr>
            <td class="font-semibold text-slate-700"><?= e($m['nom']) ?></td>
            <td class="text-slate-500"><?= e($m['puesto'] ?: '—') ?></td>
            <td class="text-slate-500"><?= e($m['sucursal'] ?: '—') ?></td>
            <td class="text-right text-slate-600"><?= money($m['salario'], false) ?></td>
            <td class="text-right font-bold text-blue-700"><?= money($c['total'], false) ?></td>
            <td class="text-right text-slate-500"><?= money($c['anual'], false) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_end(); ?>
