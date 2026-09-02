<?php
/**
 * Por qué esta nómina costó distinto que la anterior.
 *
 * ============================================================================
 *  LA PREGUNTA QUE SE CONTESTABA A OJO
 * ============================================================================
 *
 * «La quincena subió ochenta mil pesos, ¿por qué?» se respondía abriendo las dos
 * corridas en dos pestañas y comparando cincuenta y siete filas a mano. Aquí la
 * diferencia se descompone en sus causas y **las causas suman exactamente la
 * diferencia**: si no cuadrara, la explicación no valdría nada.
 *
 *   altas + bajas + aumentos + días + variables = diferencia del bruto
 *
 * ── Cómo se separa un aumento de un cambio de días ──
 *
 * El sueldo del período es `mensual × factor × días / díasBase`. Entre dos
 * corridas pueden haber cambiado las dos cosas a la vez, así que se parte:
 *
 *   por el aumento .... (mensual_B − mensual_A) × factor × díasB/díasBaseB
 *   por los días ...... mensual_A × factor × (díasB/díasBaseB − díasA/díasBaseA)
 *
 * Sumadas dan exactamente la diferencia del sueldo del período. No es una
 * aproximación: es la misma resta escrita en dos trozos.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_any_perm(['reportes.contabilidad', 'reportes.nomina']);

/* ---------- Qué dos nóminas se comparan ---------- */
$disponibles = qAll(
    "SELECT id, descripcion, tipo, fecha_desde, fecha_hasta, estado, total_bruto, total_neto
       FROM nominas
      WHERE tipo <> 'regalia' AND estado IN ('procesada','pagada')
      ORDER BY fecha_hasta DESC, id DESC LIMIT 40"
);
$idB = (int) get('hasta') ?: (int) ($disponibles[0]['id'] ?? 0);
$idA = (int) get('desde') ?: (int) ($disponibles[1]['id'] ?? 0);

$A = $idA ? qOne("SELECT * FROM nominas WHERE id = ?", [$idA]) : null;
$B = $idB ? qOne("SELECT * FROM nominas WHERE id = ?", [$idB]) : null;

/** Las líneas de una nómina, indexadas por empleado. */
function vnLineas(?array $n): array
{
    if (!$n) return [];
    $out = [];
    foreach (qAll(
        "SELECT nd.*, CONCAT(e.nombre,' ',e.apellido) AS nombre, e.cedula,
                COALESCE(NULLIF(nd.salario_mensual,0), e.salario) AS mensual,
                COALESCE(su.nombre, dep.nombre, 'Sin ubicación') AS grupo
           FROM nomina_detalles nd
           JOIN empleados e ON e.id = nd.empleado_id
           LEFT JOIN sucursales su     ON su.id  = e.sucursal_id
           LEFT JOIN departamentos dep ON dep.id = e.departamento_id
          WHERE nd.nomina_id = ?", [(int) $n['id']]) as $l) {
        $out[(int) $l['empleado_id']] = $l;
    }
    return $out;
}
function vnFactor(?array $n): float
{
    return ($n['tipo'] ?? '') === 'mensual' ? 1.0
         : (($n['tipo'] ?? '') === 'quincenal' ? 0.5 : 1 / 4.33);
}
/** La fracción del período que se pagó: días trabajados sobre días base. */
function vnFraccion(array $l): float
{
    $db = (float) $l['dias_base'];
    return $db > 0 ? (float) $l['dias_trabajados'] / $db : 1.0;
}

$lA = vnLineas($A);
$lB = vnLineas($B);
$fA = vnFactor($A);
$fB = vnFactor($B);

/* ---------- La descomposición ---------- */
// Los conceptos variables se comparan uno a uno; el descuento de días RESTA del
// bruto, así que su delta entra con el signo cambiado.
$variables = [
    'monto_horas_extra'      => 'Horas extra',
    'comisiones'             => 'Comisiones',
    'bonificaciones'         => 'Bonificaciones / incentivos',
    'otros_ingresos'         => 'Otros ingresos',
    'reembolso'              => 'Reembolsos',
    'vacaciones_diferencial' => 'Vacaciones (diferencial)',
];

$causas = ['altas' => 0.0, 'bajas' => 0.0, 'aumentos' => 0.0, 'dias' => 0.0, 'descuento_dias' => 0.0];
foreach ($variables as $k => $_) $causas[$k] = 0.0;

$altas = []; $bajas = []; $subidas = []; $bajadas = [];

foreach ($lB as $eid => $b) {
    if (!isset($lA[$eid])) {
        $causas['altas'] += (float) $b['total_ingresos'];
        $altas[] = ['nombre' => $b['nombre'], 'monto' => (float) $b['total_ingresos'], 'grupo' => $b['grupo']];
        continue;
    }
    $a = $lA[$eid];

    $mA = (float) $a['mensual']; $mB = (float) $b['mensual'];
    $frA = vnFraccion($a);       $frB = vnFraccion($b);

    $porAumento = round(($mB - $mA) * $fB * $frB, 2);
    $porDias    = round($mA * ($fB * $frB - $fA * $frA), 2);
    $causas['aumentos'] += $porAumento;
    $causas['dias']     += $porDias;

    if (abs($mB - $mA) > 0.005) {
        $reg = ['nombre' => $b['nombre'], 'grupo' => $b['grupo'], 'antes' => $mA, 'ahora' => $mB,
                'impacto' => $porAumento];
        if ($mB > $mA) $subidas[] = $reg; else $bajadas[] = $reg;
    }

    foreach ($variables as $k => $_) {
        $causas[$k] += (float) $b[$k] - (float) $a[$k];
    }
    // Un descuento mayor baja el bruto: entra restando.
    $causas['descuento_dias'] -= (float) $b['descuento_dias'] - (float) $a['descuento_dias'];
}
foreach ($lA as $eid => $a) {
    if (isset($lB[$eid])) continue;
    $causas['bajas'] -= (float) $a['total_ingresos'];
    $bajas[] = ['nombre' => $a['nombre'], 'monto' => (float) $a['total_ingresos'], 'grupo' => $a['grupo']];
}

$causas = array_map(fn($v) => round($v, 2), $causas);

$brutoA = (float) ($A['total_bruto'] ?? 0);
$brutoB = (float) ($B['total_bruto'] ?? 0);
$delta  = round($brutoB - $brutoA, 2);
$sumaCausas = round(array_sum($causas), 2);
$descuadre  = round($delta - $sumaCausas, 2);

$netoA = (float) ($A['total_neto'] ?? 0);
$netoB = (float) ($B['total_neto'] ?? 0);
$dedA  = (float) ($A['total_deducciones'] ?? 0);
$dedB  = (float) ($B['total_deducciones'] ?? 0);

/* ---------- Exportación ---------- */
if (export_solicitado() && $A && $B) {
    $filas = [];
    foreach (vnEtiquetas($variables) as $k => $lbl) {
        if (abs($causas[$k] ?? 0) < 0.005) continue;
        $filas[] = [$lbl, money($causas[$k], false)];
    }
    $filas[] = ['DIFERENCIA TOTAL', money($delta, false)];
    export_tabla('variacion_nomina_' . $idA . '_' . $idB,
        ['Causa', 'Impacto en el bruto'], $filas, 'Variación entre nóminas');
}

/** Etiquetas de todas las causas, en el orden en que se leen. */
function vnEtiquetas(array $variables): array
{
    return array_merge([
        'altas'    => 'Gente que entró',
        'bajas'    => 'Gente que salió',
        'aumentos' => 'Cambios de sueldo',
        'dias'     => 'Días trabajados',
    ], $variables, ['descuento_dias' => 'Descuentos por días']);
}

layout_start('Variación entre nóminas',
    $A && $B ? e($A['descripcion']) . '  →  ' . e($B['descripcion']) : 'Elige dos nóminas',
    rep_barra_titulo());
?>

<form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
  <div class="min-w-[20rem]">
    <label class="label" for="desde">Nómina de referencia</label>
    <select id="desde" name="desde" class="select">
      <?php foreach ($disponibles as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= $idA === (int) $d['id'] ? 'selected' : '' ?>>
          <?= e($d['descripcion']) ?> · <?= e(fechaCorta($d['fecha_hasta'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="min-w-[20rem]">
    <label class="label" for="hasta">Nómina a explicar</label>
    <select id="hasta" name="hasta" class="select">
      <?php foreach ($disponibles as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= $idB === (int) $d['id'] ? 'selected' : '' ?>>
          <?= e($d['descripcion']) ?> · <?= e(fechaCorta($d['fecha_hasta'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary"><?= icon('chart', 'w-4 h-4') ?> Comparar</button>
</form>

<?php if (!$A || !$B || $idA === $idB): ?>
  <div class="card p-6"><?= empty_state(
      count($disponibles) < 2 ? 'Hacen falta dos nóminas confirmadas' : 'Elige dos nóminas distintas',
      'La comparación necesita una corrida de referencia y otra que explicar.', 'chart') ?></div>
  <?php layout_end(); exit; ?>
<?php endif; ?>

<?= rep_kpis([
    ['label' => 'Bruto de referencia', 'valor' => money($brutoA), 'icono' => 'wallet', 'color' => 'slate',
     'nota' => e($A['descripcion']) . ' · ' . count($lA) . ' persona(s)'],
    ['label' => 'Bruto a explicar', 'valor' => money($brutoB), 'icono' => 'wallet', 'color' => 'blue',
     'nota' => e($B['descripcion']) . ' · ' . count($lB) . ' persona(s)'],
    ['label' => 'Diferencia', 'valor' => ($delta >= 0 ? '+' : '') . money($delta),
     'icono' => $delta >= 0 ? 'trending' : 'trending-down',
     'color' => abs($delta) < 0.01 ? 'slate' : ($delta > 0 ? 'rose' : 'emerald'),
     'nota' => $brutoA > 0 ? number_format($delta / $brutoA * 100, 2) . '% sobre la referencia' : '—'],
    ['label' => 'Neto pagado', 'valor' => ($netoB - $netoA >= 0 ? '+' : '') . money($netoB - $netoA),
     'icono' => 'cash', 'color' => 'violet',
     'nota' => money($netoA, false) . ' → ' . money($netoB, false)],
], 4) ?>

<?php if (abs($descuadre) >= 0.02): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 bg-rose-50 border-rose-200">
    <?= icon('alert', 'w-5 h-5 text-rose-600 mt-0.5 shrink-0') ?>
    <p class="text-sm text-rose-900">
      <strong>Las causas no suman la diferencia: faltan <?= money($descuadre) ?>.</strong>
      Una explicación que no cuadra no sirve. Suele pasar si una de las dos nóminas se tocó por
      fuera de la pantalla; avisa para que no se tome esta descomposición por buena.
    </p>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
  <div>
    <?= rep_seccion('De dónde sale la diferencia', 'Las causas suman exactamente el total', 'scale', 'blue') ?>
      <div class="divide-y divide-slate-100">
        <?php foreach (vnEtiquetas($variables) as $k => $lbl):
            $v = (float) ($causas[$k] ?? 0);
            if (abs($v) < 0.005) continue; ?>
          <div class="flex items-center justify-between gap-4 px-5 py-3">
            <span class="text-sm text-slate-600"><?= e($lbl) ?></span>
            <span class="tabular-nums font-medium <?= $v > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">
              <?= ($v >= 0 ? '+' : '') . money($v) ?>
            </span>
          </div>
        <?php endforeach; ?>
        <?php if (abs($delta) < 0.005 && !array_filter($causas, fn($v) => abs($v) >= 0.005)): ?>
          <div class="px-5 py-6 text-center text-sm text-slate-400">
            Las dos nóminas son idénticas peso a peso.
          </div>
        <?php endif; ?>
        <div class="flex items-center justify-between gap-4 px-5 py-4 bg-slate-50 font-extrabold text-slate-800">
          <span class="text-sm">DIFERENCIA TOTAL</span>
          <span class="tabular-nums"><?= ($delta >= 0 ? '+' : '') . money($delta) ?></span>
        </div>
      </div>
      <div class="px-5 py-3 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
        Un aumento y un cambio de días se separan aunque ocurran a la vez: el sueldo del período es
        <em>mensual × factor × días/díasBase</em>, y la resta se escribe en dos trozos que suman lo mismo.
      </div>
    <?= rep_fin() ?>
  </div>

  <div>
    <?= rep_seccion('Retenciones y neto', 'Cómo se movió lo que se le quita a la gente', 'minus', 'amber') ?>
      <div class="divide-y divide-slate-100">
        <?php
        $comp = [
            ['Bruto',        $brutoA, $brutoB],
            ['Retenciones',  $dedA,   $dedB],
            ['Neto pagado',  $netoA,  $netoB],
        ];
        foreach ($comp as [$lbl, $va, $vb]):
            $d = round($vb - $va, 2); ?>
          <div class="flex items-center justify-between gap-4 px-5 py-3">
            <span class="text-sm text-slate-600"><?= e($lbl) ?></span>
            <span class="text-sm text-slate-500 tabular-nums"><?= money($va, false) ?> → <?= money($vb, false) ?></span>
            <span class="tabular-nums font-medium <?= abs($d) < 0.005 ? 'text-slate-400' : ($d > 0 ? 'text-rose-600' : 'text-emerald-600') ?>">
              <?= ($d >= 0 ? '+' : '') . money($d) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div>
    <?= rep_seccion('Entraron', count($altas) . ' persona(s)', 'user', 'emerald') ?>
      <?php
      echo rep_tabla(['Persona', ['Aporta', 'right']],
          array_map(fn($x) => [
              '<span class="text-sm text-slate-700">' . e($x['nombre']) . '</span>'
                . '<span class="block text-xs text-slate-400">' . e($x['grupo']) . '</span>',
              money($x['monto'], false),
          ], $altas),
          ['vacio' => 'Nadie entró entre las dos corridas.', 'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>
  <div>
    <?= rep_seccion('Salieron', count($bajas) . ' persona(s)', 'user', 'rose') ?>
      <?php
      echo rep_tabla(['Persona', ['Deja de costar', 'right']],
          array_map(fn($x) => [
              '<span class="text-sm text-slate-700">' . e($x['nombre']) . '</span>'
                . '<span class="block text-xs text-slate-400">' . e($x['grupo']) . '</span>',
              money($x['monto'], false),
          ], $bajas),
          ['vacio' => 'Nadie salió entre las dos corridas.', 'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>
  <div>
    <?= rep_seccion('Cambios de sueldo', count($subidas) + count($bajadas) . ' persona(s)', 'trending', 'violet') ?>
      <?php
      $fc = [];
      foreach (array_merge($subidas, $bajadas) as $x) {
          $fc[] = [
              '<span class="text-sm text-slate-700">' . e($x['nombre']) . '</span>'
                . '<span class="block text-xs text-slate-400">' . money($x['antes'], false)
                . ' → ' . money($x['ahora'], false) . '</span>',
              '<span class="' . ($x['impacto'] > 0 ? 'text-rose-600' : 'text-emerald-600') . '">'
                . ($x['impacto'] >= 0 ? '+' : '') . money($x['impacto'], false) . '</span>',
          ];
      }
      echo rep_tabla(['Persona', ['Impacto', 'right']], $fc,
          ['vacio' => 'Ningún sueldo cambió entre las dos corridas.', 'vacio_icono' => 'check']);
      ?>
    <?= rep_fin() ?>
  </div>
</div>

<?php layout_end(); ?>
