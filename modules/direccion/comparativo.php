<?php
/**
 * Año contra año, mes contra mes.
 *
 * La pregunta que hace la dirección no es «¿cuánto vendimos?», sino «¿vamos
 * mejor o peor que el año pasado, y en qué mes se torció?». Esta pantalla
 * responde exactamente eso: una matriz de doce meses con los dos años lado a
 * lado y la variación de cada uno.
 *
 * La comparación es HONESTA con el año en curso: contra el año actual solo se
 * comparan los meses ya cerrados o en curso, porque comparar doce meses contra
 * ocho siempre dice que vamos peor.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('direccion.ver');

$anios = dir_anios();
$anioA = (int) get('a', (string) $anios[0]);
if (!in_array($anioA, $anios, true)) $anioA = (int) $anios[0];
$anioB = (int) get('b', (string) ($anioA - 1));
if ($anioB === $anioA) $anioB = $anioA - 1;

[$scope, $scopeP] = dir_scope('v');

// Meses a comparar. Si A es el año en curso, se corta en el mes actual: contra
// doce meses completos del año pasado, la foto saldría siempre en rojo.
$esAnioEnCurso = $anioA === (int) date('Y');
$mesTope = $esAnioEnCurso ? (int) date('n') : 12;
$parcial = $esAnioEnCurso && $mesTope < 12;
$hastaA = $esAnioEnCurso ? date('Y-m-d 23:59:59') : $anioA . '-12-31 23:59:59';
if ($esAnioEnCurso) {
    // El mismo día del año de comparación. Un 29 de febrero no existe en un año
    // que no es bisiesto: ese día se corta en el 28 en vez de generar una fecha
    // inválida que MySQL descarta en silencio (y deja el comparativo en cero).
    $mes = (int) date('n');
    $dia = min((int) date('j'), (int) date('t', mktime(0, 0, 0, $mes, 1, $anioB)));
    $hastaB = sprintf('%04d-%02d-%02d 23:59:59', $anioB, $mes, $dia);
} else {
    $hastaB = $anioB . '-12-31 23:59:59';
}

$totA = dir_totales($anioA . '-01-01 00:00:00', $hastaA, $scope, $scopeP);
$totB = dir_totales($anioB . '-01-01 00:00:00', $hastaB, $scope, $scopeP);

$serieA = dir_serie_anual($anioA, $scope, $scopeP);
$serieB = dir_serie_anual($anioB, $scope, $scopeP);

// Dimensiones comparadas. Los rangos son los mismos que los de los totales para
// que la suma de cada tabla cuadre con la tarjeta de arriba.
$rangoA = [$anioA . '-01-01 00:00:00', $hastaA];
$rangoB = [$anioB . '-01-01 00:00:00', $hastaB];

$dimensiones = [];
if (tiendas_hay()) {
    $dimensiones['Tienda'] = ['icono' => 'tag', 'datos' => dir_dimension(
        "COALESCE(t.nombre,'Sin marca')", 'LEFT JOIN tiendas t ON t.id = v.tienda_id',
        'v.tienda_id, t.nombre', $rangoA, $rangoB, $scope, $scopeP
    )];
}
$dimensiones['Sucursal'] = ['icono' => 'store', 'datos' => dir_dimension(
    'su.nombre', 'JOIN sucursales su ON su.id = v.sucursal_id',
    'v.sucursal_id, su.nombre', $rangoA, $rangoB, $scope, $scopeP
)];
$dimensiones['Categoría'] = ['icono' => 'layers', 'datos' => dir_dimension(
    "COALESCE(c.nombre,'Sin categoría')",
    'JOIN venta_detalles vd ON vd.venta_id = v.id LEFT JOIN productos pr ON pr.id = vd.producto_id LEFT JOIN categorias c ON c.id = pr.categoria_id',
    'c.id, c.nombre', $rangoA, $rangoB, $scope, $scopeP, 'vd.subtotal - vd.descuento'
)];
$dimensiones['Canal de venta'] = ['icono' => 'megaphone', 'datos' => dir_dimension(
    "COALESCE(NULLIF(v.canal_venta,''),'Sin especificar')", '',
    'v.canal_venta', $rangoA, $rangoB, $scope, $scopeP
)];

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    for ($m = 1; $m <= 12; $m++) {
        $a = $serieA[$m]['ingresos']; $b = $serieB[$m]['ingresos'];
        $d = rep_delta($a, $b);
        $filas[] = [mesNombre($m), money($a, false), money($b, false),
                    $d === null ? '—' : number_format($d, 1) . '%',
                    money($a - $b, false)];
    }
    $filas[] = ['TOTAL', money($totA['ingresos'], false), money($totB['ingresos'], false),
                ($d = rep_delta($totA['ingresos'], $totB['ingresos'])) === null ? '—' : number_format($d, 1) . '%',
                money($totA['ingresos'] - $totB['ingresos'], false)];
    export_tabla('comparativo_' . $anioA . '_vs_' . $anioB,
        ['Mes', (string) $anioA, (string) $anioB, 'Variación', 'Diferencia'],
        $filas, 'Comparativo ' . $anioA . ' vs ' . $anioB);
}

/** Celda de variación con color. */
function dir_var(?float $d, bool $invertir = false): string
{
    if ($d === null) return '<span class="text-slate-300">—</span>';
    $bueno = $invertir ? $d <= 0 : $d >= 0;
    return '<span class="badge ' . ($bueno ? 'stat-trend-up' : 'stat-trend-down') . '">'
        . icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') . ' ' . number_format(abs($d), 1) . '%</span>';
}

$acciones = rep_acciones()
    . '<button type="button" onclick="window.print()" class="btn btn-ghost no-print">' . icon('print', 'w-4 h-4') . ' Imprimir</button>';
layout_start('Año contra año', $anioA . ' comparado con ' . $anioB . ' · ' . rep_alcance_sucursal(), $acciones);
?>

<!-- Filtros -->
<div class="card p-4 mb-5 no-print">
  <form method="get" class="flex flex-wrap items-end gap-3">
    <div>
      <label class="label" for="cmp_a">Año</label>
      <select id="cmp_a" name="a" class="select cursor-pointer">
        <?php foreach ($anios as $y): ?><option value="<?= $y ?>" <?= $anioA === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
      </select>
    </div>
    <span class="text-slate-400 pb-2.5 font-semibold">contra</span>
    <div>
      <label class="label" for="cmp_b">Año de comparación</label>
      <select id="cmp_b" name="b" class="select cursor-pointer">
        <?php for ($y = (int) date('Y'); $y >= min($anios) - 1; $y--): ?>
          <option value="<?= $y ?>" <?= $anioB === $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div><span class="label">Sucursal</span><?= selectSucursalFiltro() ?: '<p class="text-sm text-slate-400 pb-2">Única</p>' ?></div>
    <?php if (tiendas_hay()): ?>
      <div><span class="label">Tienda</span><?= selectTiendaFiltro() ?></div>
    <?php endif; ?>
    <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Comparar</button>
  </form>
  <?php if ($parcial): ?>
    <p class="text-xs text-slate-500 mt-3 pt-3 border-t border-slate-100">
      <?= $anioA ?> va por <?= mb_strtolower(mesNombre($mesTope)) ?>. Para que la comparación sea justa, los totales de
      <?= $anioB ?> se cortan en la misma fecha; la tabla mensual sí muestra los doce meses de <?= $anioB ?>.
    </p>
  <?php endif; ?>
</div>

<?= rep_encabezado_impresion('Comparativo ' . $anioA . ' vs ' . $anioB, ['desde' => $anioA . '-01-01', 'hasta' => substr($hastaA, 0, 10)]) ?>

<!-- Indicadores -->
<?php
$indicadores = [
    ['Ingresos netos', 'ingresos', 'money', 'dollar', 'emerald', false],
    ['Utilidad bruta', 'utilidad', 'money', 'trending', 'blue', false],
    ['Margen bruto', 'margen', 'pct', 'percent', 'violet', false],
    ['Facturas', 'facturas', 'num', 'receipt', 'amber', false],
];
$kpis = [];
foreach ($indicadores as [$lbl, $campo, $tipo, $ico, $col, $inv]) {
    $fmt = fn($v) => $tipo === 'money' ? money($v) : ($tipo === 'pct' ? number_format($v, 1) . '%' : number_format($v));
    $kpis[] = [
        'label' => $lbl, 'valor' => $fmt($totA[$campo]), 'icono' => $ico, 'color' => $col,
        'delta' => rep_delta((float) $totA[$campo], (float) $totB[$campo]), 'invertir' => $inv,
        'nota'  => $anioB . ': <strong>' . $fmt($totB[$campo]) . '</strong>',
    ];
}
echo rep_kpis($kpis);
?>

<!-- Curva de los dos años -->
<?= rep_seccion('Mes a mes', $anioA . ' contra ' . $anioB . ' · ingresos netos', 'chart', 'indigo') ?>
  <div class="px-5 pb-5 flex-1 flex flex-col justify-center overflow-x-auto">
    <?php
    $labels = [];
    $valA = []; $valB = [];
    for ($m = 1; $m <= 12; $m++) {
        $labels[] = mesNombre($m, true);
        $valA[] = $serieA[$m]['ingresos'];
        $valB[] = $serieB[$m]['ingresos'];
    }
    echo lineChart([
        ['nombre' => (string) $anioA, 'color' => marca_app(), 'valores' => $valA],
        ['nombre' => (string) $anioB, 'color' => '#cbd5e1', 'valores' => $valB],
    ], $labels, ['alto' => 280]);
    ?>
  </div>
<?= rep_fin() ?>

<!-- Matriz mensual -->
<?= rep_seccion('Detalle por mes', 'Ingresos, utilidad y variación de cada mes', 'calendar', 'blue') ?>
  <?php
  $filas = [];
  $acumA = 0.0; $acumB = 0.0;
  for ($m = 1; $m <= 12; $m++) {
      $a = $serieA[$m]; $b = $serieB[$m];
      $uA = $a['ingresos'] - $a['costo'];
      $enCurso = $esAnioEnCurso && $m === $mesTope;
      $futuro  = $esAnioEnCurso && $m > $mesTope;
      $acumA += $a['ingresos']; $acumB += $b['ingresos'];
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e(mesNombre($m)) . '</span>'
            . ($enCurso ? ' <span class="badge badge-amber">en curso</span>' : ''),
          $futuro ? '<span class="text-slate-300">—</span>' : '<span class="font-bold text-slate-800 tabular-nums">' . money($a['ingresos']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($b['ingresos']) . '</span>',
          $futuro ? '<span class="text-slate-300">—</span>' : dir_var(rep_delta($a['ingresos'], $b['ingresos'])),
          $futuro ? '<span class="text-slate-300">—</span>'
                  : '<span class="tabular-nums ' . ($a['ingresos'] - $b['ingresos'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . '">'
                    . ($a['ingresos'] - $b['ingresos'] >= 0 ? '+' : '−') . money(abs($a['ingresos'] - $b['ingresos']), false) . '</span>',
          $futuro ? '<span class="text-slate-300">—</span>' : '<span class="text-slate-500 tabular-nums">' . money($uA) . '</span>',
          $futuro || $a['ingresos'] <= 0 ? '<span class="text-slate-300">—</span>'
                  : '<span class="text-slate-500 tabular-nums">' . number_format($uA / $a['ingresos'] * 100, 1) . '%</span>',
          $futuro ? '<span class="text-slate-300">—</span>' : '<span class="text-slate-400 tabular-nums">' . number_format($a['facturas']) . '</span>',
      ];
  }
  echo rep_tabla(
      ['Mes', [(string) $anioA, 'right'], [(string) $anioB, 'right'], ['Var.', 'center'], ['Diferencia', 'right'],
       ['Utilidad', 'right'], ['Margen', 'right'], ['Facturas', 'right']],
      $filas,
      ['total' => [
          'Acumulado del año',
          '<span class="tabular-nums">' . money($acumA) . '</span>',
          '<span class="tabular-nums">' . money($acumB) . '</span>',
          dir_var(rep_delta($acumA, $acumB)),
          '<span class="tabular-nums ' . ($acumA - $acumB >= 0 ? 'text-emerald-600' : 'text-rose-600') . '">'
            . ($acumA - $acumB >= 0 ? '+' : '−') . money(abs($acumA - $acumB), false) . '</span>',
          '<span class="tabular-nums">' . money($totA['utilidad']) . '</span>',
          '<span class="tabular-nums">' . number_format($totA['margen'], 1) . '%</span>',
          '<span class="tabular-nums">' . number_format($totA['facturas']) . '</span>',
      ]]
  );
  ?>
<?= rep_fin() ?>

<!-- Desgloses -->
<?php foreach ($dimensiones as $nombre => $dim):
  $filas = [];
  foreach ($dim['datos'] as $etiqueta => $v) {
      if ($v['a'] == 0.0 && $v['b'] == 0.0) continue;
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($etiqueta) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($v['a']) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($v['b']) . '</span>',
          dir_var(rep_delta($v['a'], $v['b'])),
          '<span class="tabular-nums ' . ($v['a'] - $v['b'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . '">'
            . ($v['a'] - $v['b'] >= 0 ? '+' : '−') . money(abs($v['a'] - $v['b']), false) . '</span>',
      ];
  }
?>
  <?= rep_seccion('Por ' . mb_strtolower($nombre), 'Ingresos netos ' . $anioA . ' contra ' . $anioB, $dim['icono'], 'emerald') ?>
    <?= rep_tabla(
        [$nombre, [(string) $anioA, 'right'], [(string) $anioB, 'right'], ['Var.', 'center'], ['Diferencia', 'right']],
        $filas,
        ['vacio' => 'No hubo ventas con esta dimensión en ninguno de los dos años.']
    ) ?>
  <?= rep_fin() ?>
<?php endforeach; ?>

<?php layout_end(); ?>
