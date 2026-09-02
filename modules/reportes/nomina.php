<?php
/**
 * Resumen de nómina y cargas sociales (República Dominicana).
 *
 * Bruto, deducciones del empleado (AFP 2.87%, SFS 3.04%, ISR) y neto, más la
 * estimación del aporte patronal a la TSS, que es un costo real que no aparece
 * en el recibo del empleado.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_any_perm(['reportes.contabilidad', 'reportes.nomina']);

$p = rep_periodo('mes');
[$scopeN, $scopeNP] = rep_scope('n.sucursal_id');

// Las tasas patronales viven en `includes/nomina.php` (COSTO_EMPLEADOR) y se
// leen de ahí. Estaban duplicadas en este archivo y ya habían divergido: aquí
// riesgos laborales figuraba al 1.15% y en la ficha del empleado al 1.10%, así
// que el mismo empleado costaba distinto según la pantalla que se abriera.
const TSS_AFP_PATRONAL = COSTO_EMPLEADOR['afp']     * 100;
const TSS_SFS_PATRONAL = COSTO_EMPLEADOR['sfs']     * 100;
const TSS_RIESGOS      = COSTO_EMPLEADOR['riesgos'] * 100;
const TSS_INFOTEP      = COSTO_EMPLEADOR['infotep'] * 100;

/* ---------- Nóminas del periodo ---------- */
$nominas = qAll(
    "SELECT n.*, su.nombre AS sucursal, CONCAT(u.nombre,' ',u.apellido) AS usuario,
            (SELECT COUNT(*) FROM nomina_detalles nd WHERE nd.nomina_id = n.id) AS empleados
       FROM nominas n
       LEFT JOIN sucursales su ON su.id = n.sucursal_id
       LEFT JOIN usuarios u ON u.id = n.usuario_id
      WHERE n.fecha_desde <= ? AND n.fecha_hasta >= ? AND $scopeN
      ORDER BY n.fecha_desde DESC",
    array_merge([$p['hasta'], $p['desde']], $scopeNP)
);
$ids = array_map(fn($n) => (int) $n['id'], $nominas);
$phN = $ids ? implode(',', array_fill(0, count($ids), '?')) : '0';

/* ---------- Totales ---------- */
$tot = $ids ? (qOne(
    "SELECT COUNT(*) lineas, COUNT(DISTINCT nd.empleado_id) empleados,
            COALESCE(SUM(nd.salario_base),0) base,
            COALESCE(SUM(nd.monto_horas_extra),0) extras,
            COALESCE(SUM(nd.bonificaciones),0) bonos,
            COALESCE(SUM(nd.comisiones),0) comisiones,
            COALESCE(SUM(nd.otros_ingresos),0) otros,
            COALESCE(SUM(nd.total_ingresos),0) bruto,
            COALESCE(SUM(nd.afp),0) afp,
            COALESCE(SUM(nd.sfs),0) sfs,
            COALESCE(SUM(nd.isr),0) isr,
            COALESCE(SUM(nd.otras_deducciones),0) otras_ded,
            COALESCE(SUM(nd.total_deducciones),0) deducciones,
            COALESCE(SUM(nd.salario_neto),0) neto,
            COALESCE(SUM(nd.horas_extra),0) horas_extra
       FROM nomina_detalles nd WHERE nd.nomina_id IN ($phN)", $ids
) ?: []) : [];
$bruto = (float) ($tot['bruto'] ?? 0);
$neto  = (float) ($tot['neto'] ?? 0);

// Aporte patronal estimado sobre el salario base cotizable.
$cotizable   = (float) ($tot['base'] ?? 0);
$afpPatronal = $cotizable * TSS_AFP_PATRONAL / 100;
$sfsPatronal = $cotizable * TSS_SFS_PATRONAL / 100;
$riesgos     = $cotizable * TSS_RIESGOS / 100;
$infotep     = $cotizable * TSS_INFOTEP / 100;
$patronal    = $afpPatronal + $sfsPatronal + $riesgos + $infotep;
$costoTotal  = $bruto + $patronal;

/* ---------- Por empleado ---------- */
$porEmpleado = $ids ? qAll(
    "SELECT CONCAT(em.nombre,' ',em.apellido) AS empleado, em.cedula, em.codigo,
            COALESCE(dp.nombre,'Sin departamento') AS departamento,
            COALESCE(pu.nombre,'—') AS puesto,
            COUNT(*) periodos,
            COALESCE(SUM(nd.total_ingresos),0) bruto,
            COALESCE(SUM(nd.afp),0) afp, COALESCE(SUM(nd.sfs),0) sfs, COALESCE(SUM(nd.isr),0) isr,
            COALESCE(SUM(nd.otras_deducciones),0) otras,
            COALESCE(SUM(nd.salario_neto),0) neto
       FROM nomina_detalles nd
       JOIN empleados em ON em.id = nd.empleado_id
       LEFT JOIN departamentos dp ON dp.id = em.departamento_id
       LEFT JOIN puestos pu ON pu.id = em.puesto_id
      WHERE nd.nomina_id IN ($phN)
      GROUP BY nd.empleado_id ORDER BY bruto DESC", $ids
) : [];

/* ---------- Por departamento ---------- */
$porDepto = $ids ? qAll(
    "SELECT COALESCE(dp.nombre,'Sin departamento') AS departamento,
            COUNT(DISTINCT nd.empleado_id) empleados,
            COALESCE(SUM(nd.total_ingresos),0) bruto,
            COALESCE(SUM(nd.total_deducciones),0) deducciones,
            COALESCE(SUM(nd.salario_neto),0) neto
       FROM nomina_detalles nd
       JOIN empleados em ON em.id = nd.empleado_id
       LEFT JOIN departamentos dp ON dp.id = em.departamento_id
      WHERE nd.nomina_id IN ($phN)
      GROUP BY em.departamento_id ORDER BY bruto DESC", $ids
) : [];

/* ---------- Histórico 12 meses ---------- */
$meses = rep_meses_atras(12);
$hist = [];
foreach (qAll(
    "SELECT DATE_FORMAT(n.fecha_hasta,'%Y-%m') ym, COALESCE(SUM(n.total_bruto),0) bruto,
            COALESCE(SUM(n.total_neto),0) neto
       FROM nominas n WHERE n.fecha_hasta >= ? AND $scopeN GROUP BY ym",
    array_merge([$meses[0] . '-01'], $scopeNP)
) as $r) $hist[$r['ym']] = $r;

$labels = $sBruto = $sNeto = [];
foreach ($meses as $ym) {
    $labels[] = rep_mes_label($ym);
    $sBruto[] = (float) ($hist[$ym]['bruto'] ?? 0);
    $sNeto[]  = (float) ($hist[$ym]['neto'] ?? 0);
}

/* ---------- Contexto: peso sobre la venta ---------- */
[$scopeV, $scopeVP] = rep_scope('v.sucursal_id');
$ingresos = (float) qVal(
    "SELECT COALESCE(SUM(v.subtotal - v.descuento),0) FROM ventas v
      WHERE v.estado='completada' AND v.fecha BETWEEN ? AND ? AND $scopeV",
    array_merge([$p['ini'], $p['fin']], $scopeVP)
);

if (export_solicitado()) {
    $filas = [];
    foreach ($porEmpleado as $e) {
        $filas[] = [$e['codigo'], $e['empleado'], $e['cedula'], $e['departamento'], $e['puesto'],
            money($e['bruto'], false), money($e['afp'], false), money($e['sfs'], false),
            money($e['isr'], false), money($e['otras'], false), money($e['neto'], false)];
    }
    export_tabla('nomina_' . $p['desde'] . '_' . $p['hasta'],
        ['Código', 'Empleado', 'Cédula', 'Departamento', 'Puesto', 'Bruto', 'AFP', 'SFS', 'ISR', 'Otras deducciones', 'Neto'],
        $filas, 'Resumen de nómina');
}

layout_start('Resumen de nómina', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Resumen de nómina', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Nómina bruta', 'valor' => money($bruto), 'icono' => 'wallet', 'color' => 'blue',
     'nota' => number_format((int) ($tot['empleados'] ?? 0)) . ' empleado(s) en ' . count($nominas) . ' nómina(s)'],
    ['label' => 'Deducciones del empleado', 'valor' => money($tot['deducciones'] ?? 0), 'icono' => 'minus', 'color' => 'amber',
     'nota' => 'AFP + SFS + ISR + otras'],
    ['label' => 'Neto pagado', 'valor' => money($neto), 'icono' => 'cash', 'color' => 'emerald',
     'nota' => $bruto > 0 ? number_format($neto / $bruto * 100, 1) . '% del bruto' : '—'],
    ['label' => 'Costo total con TSS', 'valor' => money($costoTotal), 'icono' => 'id', 'color' => 'violet',
     'nota' => $ingresos > 0 ? number_format($costoTotal / $ingresos * 100, 1) . '% de los ingresos' : 'Incluye aporte patronal'],
]) ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Estructura -->
  <div>
    <?= rep_seccion('Estructura de la nómina', 'De lo devengado a lo que recibe el empleado', 'scale', 'blue') ?>
      <div class="divide-y divide-slate-100">
        <?php
        $lineas = [
            ['Salario base', (float) ($tot['base'] ?? 0), 'normal'],
            ['Horas extra (' . qty($tot['horas_extra'] ?? 0) . ' h)', (float) ($tot['extras'] ?? 0), 'normal'],
            ['Bonificaciones', (float) ($tot['bonos'] ?? 0), 'normal'],
            ['Comisiones', (float) ($tot['comisiones'] ?? 0), 'normal'],
            ['Otros ingresos', (float) ($tot['otros'] ?? 0), 'normal'],
            ['TOTAL DEVENGADO', $bruto, 'sub'],
            ['(−) AFP empleado (2.87%)', -(float) ($tot['afp'] ?? 0), 'normal'],
            ['(−) SFS empleado (3.04%)', -(float) ($tot['sfs'] ?? 0), 'normal'],
            ['(−) ISR retenido', -(float) ($tot['isr'] ?? 0), 'normal'],
            ['(−) Otras deducciones', -(float) ($tot['otras_ded'] ?? 0), 'normal'],
            ['NETO A PAGAR', $neto, 'final'],
        ];
        foreach ($lineas as [$lbl, $val, $est]):
          $cls = match ($est) {
            'final' => 'bg-emerald-50/70 font-extrabold text-slate-800',
            'sub'   => 'bg-slate-50 font-bold text-slate-800',
            default => 'text-slate-600',
          };
        ?>
          <div class="flex items-center justify-between gap-4 px-5 py-3 <?= $cls ?>">
            <span class="text-sm"><?= e($lbl) ?></span>
            <span class="tabular-nums whitespace-nowrap <?= $val < 0 ? 'text-rose-600' : '' ?>"><?= money($val) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <!-- Aporte patronal -->
  <div>
    <?= rep_seccion('Aporte patronal estimado (TSS)', 'Lo que la empresa paga además del salario', 'building', 'violet') ?>
      <div class="divide-y divide-slate-100">
        <?php
        $ap = [
            ['AFP patronal (' . number_format(TSS_AFP_PATRONAL, 2) . '%)', $afpPatronal],
            ['SFS patronal (' . number_format(TSS_SFS_PATRONAL, 2) . '%)', $sfsPatronal],
            ['Riesgos laborales (' . number_format(TSS_RIESGOS, 2) . '%)', $riesgos],
            ['INFOTEP (' . number_format(TSS_INFOTEP, 2) . '%)', $infotep],
        ];
        foreach ($ap as [$lbl, $val]): ?>
          <div class="flex items-center justify-between gap-4 px-5 py-3">
            <span class="text-sm text-slate-600"><?= e($lbl) ?></span>
            <span class="font-semibold text-slate-700 tabular-nums"><?= money($val) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="flex items-center justify-between gap-4 px-5 py-3 bg-slate-50 font-bold text-slate-800">
          <span class="text-sm">TOTAL APORTE PATRONAL</span>
          <span class="tabular-nums"><?= money($patronal) ?></span>
        </div>
        <div class="flex items-center justify-between gap-4 px-5 py-4 bg-violet-50/60">
          <span class="font-extrabold text-slate-800">COSTO REAL DEL PERSONAL</span>
          <span class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($costoTotal) ?></span>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
        Estimación sobre el salario base cotizable (<?= money($cotizable) ?>) con las tasas patronales vigentes.
        El tope salarial de cotización y las tasas de riesgos laborales varían por empresa: confirma con tu asesor de TSS.
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Nóminas del periodo -->
<?= rep_seccion('Nóminas del periodo', 'Cada corrida con su estado', 'file', 'indigo',
    can('rrhh_nomina.ver') ? '<a href="' . e(url('modules/rrhh/nomina.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Gestionar nóminas</a>' : '') ?>
  <?php
  $filas = [];
  foreach ($nominas as $n) {
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($n['descripcion']) . '</span>'
            . '<span class="block text-[11px] text-slate-400">' . e(ucfirst($n['tipo'])) . ' · ' . e($n['sucursal'] ?: 'Global') . '</span>',
          '<span class="text-slate-500 whitespace-nowrap">' . fechaCorta($n['fecha_desde']) . ' – ' . fechaCorta($n['fecha_hasta']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . number_format((int) $n['empleados']) . '</span>',
          '<span class="font-semibold text-slate-800 tabular-nums">' . money($n['total_bruto']) . '</span>',
          '<span class="text-rose-600 tabular-nums">' . money($n['total_deducciones']) . '</span>',
          '<span class="font-bold text-emerald-600 tabular-nums">' . money($n['total_neto']) . '</span>',
          badgeFor($n['estado']),
      ];
  }
  echo rep_tabla(
      ['Nómina', 'Periodo', ['Empleados', 'center'], ['Bruto', 'right'], ['Deducciones', 'right'], ['Neto', 'right'], ['Estado', 'center']],
      $filas,
      ['vacio_titulo' => 'Sin nóminas en el periodo',
       'vacio' => 'No hay corridas de nómina que se solapen con el rango seleccionado.']
  );
  ?>
<?= rep_fin() ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Por departamento -->
  <div>
    <?= rep_seccion('Costo por departamento', 'Dónde está el gasto de personal', 'building', 'amber') ?>
      <?php
      $filas = [];
      foreach ($porDepto as $d) {
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($d['departamento']) . '</span>',
              '<span class="text-slate-500 tabular-nums">' . number_format((int) $d['empleados']) . '</span>',
              '<span class="font-semibold text-slate-800 tabular-nums">' . money($d['bruto']) . '</span>',
              '<span class="text-rose-600 tabular-nums">' . money($d['deducciones']) . '</span>',
              '<span class="font-bold text-emerald-600 tabular-nums">' . money($d['neto']) . '</span>',
              '<span class="text-slate-400 tabular-nums text-xs">' . number_format($bruto > 0 ? (float) $d['bruto'] / $bruto * 100 : 0, 1) . '%</span>',
          ];
      }
      echo rep_tabla(['Departamento', ['Emp.', 'center'], ['Bruto', 'right'], ['Deducciones', 'right'], ['Neto', 'right'], ['%', 'right']], $filas);
      ?>
    <?= rep_fin() ?>
  </div>

  <!-- Histórico -->
  <div>
    <?= rep_seccion('Evolución de la nómina', 'Bruto contra neto en 12 meses', 'trending', 'blue') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?= lineChart([
            ['nombre' => 'Nómina bruta', 'color' => marca_app(), 'valores' => $sBruto, 'area' => true],
            ['nombre' => 'Neto pagado', 'color' => '#10b981', 'valores' => $sNeto],
        ], $labels, ['alto' => 260]) ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Detalle por empleado -->
<?= rep_seccion('Detalle por empleado', 'Acumulado del periodo, útil para la TSS y el IR-3', 'users', 'emerald') ?>
  <?php
  $filas = [];
  foreach ($porEmpleado as $e) {
      $filas[] = [
          '<div class="flex items-center gap-2.5">' . avatar($e['empleado'], 'w-8 h-8')
            . '<div class="min-w-0"><span class="font-semibold text-slate-700 block truncate">' . e($e['empleado']) . '</span>'
            . '<span class="text-[11px] text-slate-400">' . e($e['cedula']) . ' · ' . e($e['puesto']) . '</span></div></div>',
          '<span class="text-slate-500">' . e($e['departamento']) . '</span>',
          '<span class="text-slate-400 tabular-nums">' . number_format((int) $e['periodos']) . '</span>',
          '<span class="font-semibold text-slate-800 tabular-nums">' . money($e['bruto']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($e['afp']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($e['sfs']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . ((float) $e['isr'] > 0 ? money($e['isr']) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="font-bold text-emerald-600 tabular-nums">' . money($e['neto']) . '</span>',
      ];
  }
  echo rep_tabla(
      ['Empleado', 'Departamento', ['Períodos', 'center'], ['Bruto', 'right'], ['AFP', 'right'], ['SFS', 'right'], ['ISR', 'right'], ['Neto', 'right']],
      $filas,
      ['total' => $filas ? ['Total', '', '', money($bruto), money($tot['afp'] ?? 0), money($tot['sfs'] ?? 0),
          money($tot['isr'] ?? 0), money($neto)] : null]
  );
  ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
