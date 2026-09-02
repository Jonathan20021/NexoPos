<?php
/**
 * Provisiones laborales — lo que se debe hoy aunque nadie lo haya cobrado.
 *
 * ============================================================================
 *  LA CIFRA QUE NO EXISTÍA EN NINGÚN LADO
 * ============================================================================
 *
 * La nómina dice lo que se pagó. Este informe dice lo que se DEBE: si mañana la
 * empresa cerrara, cuánto habría que entregar por derechos ya devengados y
 * todavía no pagados. Son tres cosas y ninguna sale del estado de resultados:
 *
 *   · REGALÍA devengada del año, que se paga el 20 de diciembre pero se gana
 *     mes a mes desde enero.
 *   · VACACIONES generadas y no disfrutadas del año de servicio en curso.
 *   · CESANTÍA acumulada, que es la mayor de las tres con diferencia y la que
 *     crece cada año sin que nadie la vea.
 *
 * No confundir con «Costo de la plantilla», que mira el gasto MENSUAL de tener
 * a la gente contratada. Este mira el pasivo ACUMULADO.
 *
 * ============================================================================
 *  LA CESANTÍA NO SIEMPRE SE PAGA, Y AUN ASÍ SE PROVISIONA
 * ============================================================================
 *
 * El auxilio de cesantía solo es exigible cuando la salida la provoca la empresa
 * —desahucio o despido declarado injustificado— o en una dimisión justificada.
 * Quien renuncia no lo cobra. Provisionarla al 100% es el criterio conservador:
 * se registra el pasivo entero porque la empresa no controla quién se va ni por
 * qué. Es una decisión del contador, así que la pantalla la enseña por separado
 * y deja mirar el total con y sin ella.
 *
 * El preaviso NO se provisiona: es un evento, no un derecho que se acumule.
 * Sale aparte y solo como referencia de a cuánto ascendería.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_any_perm(['reportes.contabilidad', 'reportes.nomina']);

$hoy   = date('Y-m-d');
$anio  = (int) date('Y');
$corte = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) get('corte')) ? (string) get('corte') : $hoy;
$conCesantia = get('cesantia') !== '0';

[$scope, $scopeP] = sucursalFiltro('e.sucursal_id');

/* ---------- El padrón vivo ---------- */
$empleados = qAll(
    "SELECT e.id, e.codigo, e.nombre, e.apellido, e.cedula, e.salario,
            e.fecha_ingreso, e.fecha_salida, e.estado,
            su.nombre AS sucursal, t.nombre AS marca, dep.nombre AS departamento
       FROM empleados e
       LEFT JOIN sucursales su     ON su.id  = e.sucursal_id
       LEFT JOIN tiendas t         ON t.id   = su.tienda_id
       LEFT JOIN departamentos dep ON dep.id = e.departamento_id
      WHERE e.estado = 'activo' AND $scope
      ORDER BY e.nombre, e.apellido",
    $scopeP
);

/* ---------- Lo devengado del año, para la regalía ---------- */
$pagos = [];
foreach (regaliaPagosDelAnio($anio) as $p) $pagos[(int) $p['empleado_id']][] = $p;

$filas = [];
$tot = ['regalia' => 0.0, 'vacaciones' => 0.0, 'cesantia' => 0.0, 'preaviso' => 0.0, 'empleados' => 0];
$sinIngreso = [];
$umbrales = ['anio' => [], 'cinco' => []];

foreach ($empleados as $e) {
    $eid     = (int) $e['id'];
    $salario = (float) $e['salario'];
    $diario  = round($salario / PLAB_DIVISOR_DIARIO, 2);
    $ingreso = (string) ($e['fecha_ingreso'] ?: '');

    // Regalía: la duodécima de lo devengado en el año hasta la fecha de corte.
    $r = regaliaDeEmpleado($e, $anio, $corte, $pagos[$eid] ?? [], true);
    $regalia = $r ? (float) $r['regalia'] : 0.0;

    $dias = $ingreso !== '' ? plab_dias_servicio($ingreso, $corte) : 0;
    if ($ingreso === '') $sinIngreso[] = trim($e['nombre'] . ' ' . $e['apellido']);

    $vac = $ingreso !== '' ? plab_dias_vacaciones($ingreso, $corte)
                           : ['dias' => 0.0, 'derecho' => 0, 'regla' => 'Sin fecha de ingreso'];
    $ces = $ingreso !== '' ? plab_dias_cesantia($dias)
                           : ['dias' => 0.0, 'tasa' => 0, 'regla' => 'Sin fecha de ingreso'];
    $pre = $ingreso !== '' ? plab_dias_preaviso($dias) : 0.0;

    // Quien está a punto de cruzar un tramo: la provisión pega un salto y
    // conviene saberlo antes, no cuando ya pasó.
    if ($ingreso !== '' && $dias < 365 && $dias >= 335) {
        $umbrales['anio'][] = trim($e['nombre'] . ' ' . $e['apellido'])
            . ' (' . (365 - $dias) . ' día(s) para el año)';
    }
    if ($ingreso !== '' && $dias < 1826 && $dias >= 1796) {
        $umbrales['cinco'][] = trim($e['nombre'] . ' ' . $e['apellido'])
            . ' (' . (1826 - $dias) . ' día(s) para los 5 años)';
    }

    $f = [
        'id' => $eid, 'nombre' => trim($e['nombre'] . ' ' . $e['apellido']),
        'cedula' => $e['cedula'], 'grupo' => $e['sucursal'] ?: ($e['departamento'] ?: 'Sin ubicación'),
        'marca' => $e['marca'] ?: 'Sin marca',
        'salario' => $salario, 'diario' => $diario,
        'ingreso' => $ingreso, 'dias' => $dias, 'anios' => $dias > 0 ? round($dias / 365, 2) : 0,
        'regalia' => $regalia,
        'vac_dias' => (float) $vac['dias'], 'vacaciones' => round((float) $vac['dias'] * $diario, 2),
        'ces_dias' => (float) $ces['dias'], 'cesantia' => round((float) $ces['dias'] * $diario, 2),
        'ces_regla' => $ces['regla'],
        'pre_dias' => $pre, 'preaviso' => round($pre * $diario, 2),
    ];
    $f['total'] = round($f['regalia'] + $f['vacaciones'] + ($conCesantia ? $f['cesantia'] : 0), 2);

    $filas[] = $f;
    $tot['empleados']++;
    foreach (['regalia', 'vacaciones', 'cesantia', 'preaviso'] as $k) $tot[$k] += $f[$k];
}

foreach (['regalia', 'vacaciones', 'cesantia', 'preaviso'] as $k) $tot[$k] = round($tot[$k], 2);
$tot['provision'] = round($tot['regalia'] + $tot['vacaciones'] + ($conCesantia ? $tot['cesantia'] : 0), 2);

usort($filas, fn($a, $b) => $b['total'] <=> $a['total']);

/* ---------- Agrupados ---------- */
$agrupar = function (string $campo) use ($filas, $conCesantia) {
    $g = [];
    foreach ($filas as $f) {
        $k = $f[$campo];
        $g[$k] ??= ['etiqueta' => $k, 'n' => 0, 'regalia' => 0.0, 'vacaciones' => 0.0,
                    'cesantia' => 0.0, 'total' => 0.0];
        $g[$k]['n']++;
        foreach (['regalia', 'vacaciones', 'cesantia'] as $c) $g[$k][$c] += $f[$c];
        $g[$k]['total'] += $f['total'];
    }
    uasort($g, fn($a, $b) => $b['total'] <=> $a['total']);
    return array_values($g);
};
$porGrupo = $agrupar('grupo');
$porMarca = $agrupar('marca');

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    export_tabla('provisiones_laborales_' . $corte,
        ['Código', 'Empleado', 'Cédula', 'Ubicación', 'Sueldo', 'Salario diario', 'Ingreso',
         'Años', 'Regalía devengada', 'Días vac.', 'Vacaciones', 'Días cesantía', 'Cesantía',
         'Total provisionado', 'Preaviso (referencia)'],
        array_map(fn($f) => [
            '', $f['nombre'], $f['cedula'], $f['grupo'],
            money($f['salario'], false), money($f['diario'], false),
            $f['ingreso'] ?: '', number_format($f['anios'], 2),
            money($f['regalia'], false), qty($f['vac_dias']), money($f['vacaciones'], false),
            qty($f['ces_dias']), money($f['cesantia'], false),
            money($f['total'], false), money($f['preaviso'], false),
        ], $filas),
        'Provisiones laborales al ' . fechaCorta($corte));
}

/* ---------- Pantalla ---------- */
layout_start('Provisiones laborales',
    'Lo que se debe al ' . fechaCorta($corte) . ' · ' . count($filas) . ' empleado(s)',
    rep_barra_titulo());
echo rep_encabezado_impresion('Provisiones laborales al ' . fechaCorta($corte), rep_periodo('mes'));
?>

<form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
  <div>
    <label class="label" for="corte">Fecha de corte</label>
    <input type="date" id="corte" name="corte" value="<?= e($corte) ?>" class="input">
  </div>
  <div>
    <label class="label" for="cesantia">La cesantía</label>
    <select id="cesantia" name="cesantia" class="select min-w-[24rem]">
      <option value="1" <?= $conCesantia ? 'selected' : '' ?>>Provisionarla al 100% (criterio conservador)</option>
      <option value="0" <?= $conCesantia ? '' : 'selected' ?>>Dejarla fuera del total (solo como referencia)</option>
    </select>
  </div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
</form>

<?= rep_kpis([
    ['label' => 'Provisión total', 'valor' => money($tot['provision']), 'icono' => 'scale', 'color' => 'violet',
     'nota' => $conCesantia ? 'Regalía + vacaciones + cesantía' : 'Regalía + vacaciones (sin cesantía)'],
    ['label' => 'Regalía devengada', 'valor' => money($tot['regalia']), 'icono' => 'sun', 'color' => 'amber',
     'nota' => 'Se paga antes del 20 de diciembre'],
    ['label' => 'Vacaciones acumuladas', 'valor' => money($tot['vacaciones']), 'icono' => 'calendar', 'color' => 'sky',
     'nota' => 'Del año de servicio en curso'],
    ['label' => 'Cesantía acumulada', 'valor' => money($tot['cesantia']), 'icono' => 'briefcase',
     'color' => $conCesantia ? 'rose' : 'slate',
     'nota' => $conCesantia ? 'Dentro del total' : 'Fuera del total, solo referencia'],
], 4) ?>

<?php if ($sinIngreso): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-rose-50 border-rose-200">
    <?= icon('alert', 'w-5 h-5 text-rose-600 mt-0.5 shrink-0') ?>
    <div class="text-sm text-rose-900">
      <strong><?= count($sinIngreso) ?> persona(s) sin fecha de ingreso.</strong>
      <p class="mt-1 text-rose-800">
        De esa fecha salen la antigüedad, las vacaciones y la cesantía: para ellas la provisión sale en
        cero y el total de arriba está corto.
        <?= e(implode(', ', array_slice($sinIngreso, 0, 5))) ?><?= count($sinIngreso) > 5 ? ' y ' . (count($sinIngreso) - 5) . ' más' : '' ?>.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php
// La antigüedad es el motor de la cesantía: si el padrón se cargó con una fecha
// marcador, este informe da un pasivo ridículamente bajo y parece una buena
// noticia. Es el aviso más importante de la pantalla.
$marcador = function_exists('regaliaIngresosSospechosos') ? regaliaIngresosSospechosos($anio) : [];
?>
<?php foreach ($marcador as $mk): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-rose-50 border-rose-200">
    <?= icon('alert', 'w-5 h-5 text-rose-600 mt-0.5 shrink-0') ?>
    <div class="text-sm text-rose-900">
      <strong><?= number_format($mk['empleados']) ?> empleados figuran ingresando el mismo día
      (<?= e(fechaCorta($mk['fecha'])) ?>).</strong>
      <p class="mt-1 text-rose-800">
        Casi seguro es la fecha con la que se cargó el padrón. <strong>La antigüedad es el motor de la
        cesantía</strong>: con una fecha de este año, a quien lleva cinco años se le provisionan unos
        pocos días en vez de 23 por año, y el pasivo de arriba sale muchísimo más bajo de lo que es.
        Corrige las fechas de ingreso antes de dar esta cifra por buena.
      </p>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($umbrales['anio'] || $umbrales['cinco']): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-amber-50 border-amber-200">
    <?= icon('clock', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
    <div class="text-sm text-amber-900">
      <strong>La provisión va a pegar un salto.</strong>
      <?php if ($umbrales['anio']): ?>
        <p class="mt-1 text-amber-800">
          <strong>Cumplen el año</strong> en menos de un mes: el preaviso pasa de 14 a 28 días y la
          cesantía de 13 días fijos a 21 por año. <?= e(implode(' · ', $umbrales['anio'])) ?>.
        </p>
      <?php endif; ?>
      <?php if ($umbrales['cinco']): ?>
        <p class="mt-1 text-amber-800">
          <strong>Cumplen cinco años</strong> en menos de un mes: la cesantía pasa de 21 a 23 días por
          año, y se aplica a TODOS los años. <?= e(implode(' · ', $umbrales['cinco'])) ?>.
        </p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
  <div>
    <?= rep_seccion('Por dónde está el pasivo', 'Sucursal o área', 'store', 'violet') ?>
      <?php
      $fg = [];
      foreach ($porGrupo as $g) {
          $fg[] = [
              '<span class="text-sm text-slate-700">' . e($g['etiqueta']) . '</span>'
                . '<span class="block text-xs text-slate-400">' . number_format($g['n']) . ' persona(s)</span>',
              money($g['regalia'], false),
              money($g['vacaciones'], false),
              money($g['cesantia'], false),
              '<span class="font-bold text-slate-800">' . money($g['total'], false) . '</span>',
          ];
      }
      echo rep_tabla([
          'Ubicación', ['Regalía', 'right'], ['Vacaciones', 'right'], ['Cesantía', 'right'], ['Total', 'right'],
      ], $fg, ['vacio' => 'Sin plantilla activa.', 'vacio_icono' => 'users']);
      ?>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('Por marca', 'Cuánto pasivo carga cada negocio', 'layers', 'indigo') ?>
      <?php
      $fm = [];
      foreach ($porMarca as $g) {
          $fm[] = [
              '<span class="text-sm text-slate-700">' . e($g['etiqueta']) . '</span>'
                . '<span class="block text-xs text-slate-400">' . number_format($g['n']) . ' persona(s)</span>',
              money($g['regalia'], false),
              money($g['vacaciones'], false),
              money($g['cesantia'], false),
              '<span class="font-bold text-slate-800">' . money($g['total'], false) . '</span>',
          ];
      }
      echo rep_tabla([
          'Marca', ['Regalía', 'right'], ['Vacaciones', 'right'], ['Cesantía', 'right'], ['Total', 'right'],
      ], $fm, ['vacio' => 'Sin plantilla activa.', 'vacio_icono' => 'layers']);
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<?= rep_seccion('Persona por persona', 'Ordenado por lo que más pesa', 'users', 'blue') ?>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead><tr>
        <th>Empleado</th><th>Antigüedad</th>
        <th class="text-right">Regalía</th>
        <th class="text-center">Días vac.</th><th class="text-right">Vacaciones</th>
        <th class="text-center">Días ces.</th><th class="text-right">Cesantía</th>
        <th class="text-right">Total</th>
        <th class="text-right">Preaviso</th>
      </tr></thead>
      <tbody>
        <?php if (!$filas): ?>
          <tr><td colspan="9" class="text-center text-slate-400 py-8">Sin plantilla activa.</td></tr>
        <?php endif; ?>
        <?php foreach ($filas as $f): ?>
          <tr>
            <td class="whitespace-nowrap">
              <p class="font-semibold text-slate-700"><?= e($f['nombre']) ?></p>
              <p class="text-xs text-slate-400"><?= e($f['grupo']) ?> · <?= money($f['salario'], false) ?></p>
            </td>
            <td class="text-sm text-slate-600 whitespace-nowrap">
              <?= $f['ingreso'] ? number_format($f['anios'], 2) . ' año(s)' : '<span class="text-rose-600">sin fecha</span>' ?>
              <?php if ($f['ingreso']): ?>
                <span class="block text-xs text-slate-400">desde <?= e(fechaCorta($f['ingreso'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-right text-slate-700 tabular-nums"><?= money($f['regalia'], false) ?></td>
            <td class="text-center text-slate-500 tabular-nums"><?= $f['vac_dias'] > 0 ? qty($f['vac_dias']) : '—' ?></td>
            <td class="text-right text-slate-700 tabular-nums"><?= money($f['vacaciones'], false) ?></td>
            <td class="text-center text-slate-500 tabular-nums"><?= $f['ces_dias'] > 0 ? qty($f['ces_dias']) : '—' ?></td>
            <td class="text-right tabular-nums <?= $conCesantia ? 'text-slate-700' : 'text-slate-400' ?>">
              <?= money($f['cesantia'], false) ?>
            </td>
            <td class="text-right font-bold text-slate-800 tabular-nums"><?= money($f['total'], false) ?></td>
            <td class="text-right text-slate-400 tabular-nums"><?= money($f['preaviso'], false) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($filas): ?>
        <tfoot>
          <tr class="bg-slate-50 font-bold text-slate-800">
            <td colspan="2">Total · <?= number_format($tot['empleados']) ?> persona(s)</td>
            <td class="text-right tabular-nums"><?= money($tot['regalia'], false) ?></td>
            <td></td>
            <td class="text-right tabular-nums"><?= money($tot['vacaciones'], false) ?></td>
            <td></td>
            <td class="text-right tabular-nums"><?= money($tot['cesantia'], false) ?></td>
            <td class="text-right tabular-nums"><?= money($tot['provision'], false) ?></td>
            <td class="text-right tabular-nums text-slate-500"><?= money($tot['preaviso'], false) ?></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
<?= rep_fin() ?>

<div class="card p-5 mt-5">
  <h3 class="font-bold text-slate-800 mb-2">Qué se está provisionando y qué no</h3>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-sm text-slate-600">
    <div>
      <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wider mb-1.5">Entra en el total</p>
      <ul class="space-y-1.5">
        <li>· <strong>Regalía devengada</strong> — una duodécima de lo ganado en el año hasta la fecha de
          corte. Se paga antes del 20 de diciembre pero se gana desde enero.</li>
        <li>· <strong>Vacaciones del año de servicio en curso</strong> — 14 días laborables al año hasta
          los cinco años, 18 después, proporcionales a lo corrido.</li>
        <li>· <strong>Cesantía</strong>, si está encendida arriba.</li>
      </ul>
    </div>
    <div>
      <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">No entra</p>
      <ul class="space-y-1.5">
        <li>· <strong>Preaviso</strong> — no es un derecho que se acumule, es un evento. Sale a la
          derecha solo como referencia de a cuánto ascendería.</li>
        <li>· <strong>Vacaciones de años anteriores no disfrutadas</strong> — el sistema no sabe qué se
          disfrutó de verdad si no se registró en el módulo de vacaciones.</li>
      </ul>
    </div>
  </div>
  <p class="text-sm text-slate-500 mt-4 pt-3 border-t border-slate-100">
    <strong>Sobre la cesantía:</strong> solo es exigible cuando la salida la provoca la empresa
    —desahucio o despido declarado injustificado— o en una dimisión justificada; quien renuncia no la
    cobra. Provisionarla al 100% es el criterio conservador, porque la empresa no controla quién se va
    ni por qué. La decisión es del contador y por eso se puede sacar del total con el selector de
    arriba. No confundir este informe con <em>Costo de la plantilla</em>: aquel mira el gasto mensual
    de tener contratada a la gente, este mira el pasivo acumulado.
  </p>
</div>

<?php layout_end(); ?>
