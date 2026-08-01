<?php
/**
 * Auxiliar de cuentas (mayor): saldo inicial, movimientos y saldo final de cada
 * cuenta financiera. Es el reporte que se cruza contra el estado de cuenta del banco.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.contabilidad');

$p = rep_periodo('mes');
[$scopeT, $scopeTP] = rep_scope('t.sucursal_id');

$cuentas = qAll(
    "SELECT cf.*, su.nombre AS sucursal FROM cuentas_financieras cf
       LEFT JOIN sucursales su ON su.id = cf.sucursal_id
      WHERE cf.activo = 1 ORDER BY cf.nombre"
);
$cuentaId = (int) get('cuenta_id');
$ids = array_map(fn($c) => (int) $c['id'], $cuentas);
if ($cuentaId && !in_array($cuentaId, $ids, true)) $cuentaId = 0;

/** Resumen de una cuenta en el periodo. */
function aux_resumen(int $id, array $p, string $scopeT, array $scopeTP): array
{
    $inicial = (float) qVal("SELECT COALESCE(saldo_inicial,0) FROM cuentas_financieras WHERE id = ?", [$id])
        + (float) qVal(
            "SELECT COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE -t.monto END),0)
               FROM transacciones t WHERE t.cuenta_id = ? AND t.fecha < ? AND $scopeT",
            array_merge([$id, $p['desde']], $scopeTP)
        );
    $m = qOne(
        "SELECT COALESCE(SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE 0 END),0) debe,
                COALESCE(SUM(CASE WHEN t.tipo='gasto'   THEN t.monto ELSE 0 END),0) haber,
                COUNT(*) n,
                COALESCE(SUM(CASE WHEN t.conciliada = 1 THEN 1 ELSE 0 END),0) conciliados
           FROM transacciones t WHERE t.cuenta_id = ? AND t.fecha BETWEEN ? AND ? AND $scopeT",
        array_merge([$id, $p['desde'], $p['hasta']], $scopeTP)
    ) ?: ['debe' => 0, 'haber' => 0, 'n' => 0, 'conciliados' => 0];
    return [
        'inicial' => $inicial, 'debe' => (float) $m['debe'], 'haber' => (float) $m['haber'],
        'final' => $inicial + (float) $m['debe'] - (float) $m['haber'],
        'n' => (int) $m['n'], 'conciliados' => (int) $m['conciliados'],
    ];
}

$resumenes = [];
foreach ($cuentas as $c) $resumenes[(int) $c['id']] = aux_resumen((int) $c['id'], $p, $scopeT, $scopeTP);

/* ---------- Movimientos de la cuenta seleccionada ---------- */
$movimientos = [];
$cuentaSel = null;
if ($cuentaId) {
    $cuentaSel = qOne("SELECT * FROM cuentas_financieras WHERE id = ?", [$cuentaId]);
    $movimientos = qAll(
        "SELECT t.*, COALESCE(cf.nombre,'Sin clasificar') AS categoria, su.nombre AS sucursal,
                CONCAT(u.nombre,' ',u.apellido) AS usuario
           FROM transacciones t
           LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
           LEFT JOIN sucursales su ON su.id = t.sucursal_id
           LEFT JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.cuenta_id = ? AND t.fecha BETWEEN ? AND ? AND $scopeT
          ORDER BY t.fecha ASC, t.id ASC LIMIT 500",
        array_merge([$cuentaId, $p['desde'], $p['hasta']], $scopeTP)
    );
}

$totInicial = array_sum(array_column($resumenes, 'inicial'));
$totDebe    = array_sum(array_column($resumenes, 'debe'));
$totHaber   = array_sum(array_column($resumenes, 'haber'));
$totFinal   = array_sum(array_column($resumenes, 'final'));
$balanceReal = array_sum(array_map(fn($c) => (float) $c['balance'], $cuentas));

if (export_solicitado()) {
    if ($cuentaId && $cuentaSel) {
        $filas = [];
        $saldo = $resumenes[$cuentaId]['inicial'];
        foreach ($movimientos as $m) {
            $saldo += $m['tipo'] === 'ingreso' ? (float) $m['monto'] : -(float) $m['monto'];
            $filas[] = [fechaCorta($m['fecha']), $m['categoria'], $m['descripcion'] ?? '',
                $m['referencia_tipo'] ?? '', $m['sucursal'] ?? '',
                $m['tipo'] === 'ingreso' ? money($m['monto'], false) : '',
                $m['tipo'] === 'gasto' ? money($m['monto'], false) : '',
                money($saldo, false), $m['conciliada'] ? 'Sí' : 'No'];
        }
        export_tabla('auxiliar_' . preg_replace('/[^a-z0-9]+/i', '_', $cuentaSel['nombre']) . '_' . $p['desde'],
            ['Fecha', 'Categoría', 'Descripción', 'Origen', 'Sucursal', 'Debe', 'Haber', 'Saldo', 'Conciliado'],
            $filas, 'Auxiliar de ' . $cuentaSel['nombre']);
    } else {
        $filas = [];
        foreach ($cuentas as $c) {
            $r = $resumenes[(int) $c['id']];
            $filas[] = [$c['nombre'], $c['tipo'], $c['sucursal'] ?? 'Global', money($r['inicial'], false),
                money($r['debe'], false), money($r['haber'], false), money($r['final'], false),
                money($c['balance'], false)];
        }
        export_tabla('auxiliar_cuentas_' . $p['desde'] . '_' . $p['hasta'],
            ['Cuenta', 'Tipo', 'Sucursal', 'Saldo inicial', 'Debe', 'Haber', 'Saldo final', 'Balance del sistema'],
            $filas, 'Auxiliar de cuentas');
    }
}

$selectCuenta = '<select name="cuenta_id" aria-label="Cuenta" class="select cursor-pointer" onchange="this.form.submit()">'
    . '<option value="">Todas las cuentas</option>';
foreach ($cuentas as $c) {
    $selectCuenta .= '<option value="' . (int) $c['id'] . '"' . ($cuentaId === (int) $c['id'] ? ' selected' : '') . '>'
        . e($c['nombre']) . '</option>';
}
$selectCuenta .= '</select>';

layout_start('Auxiliar de cuentas', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Auxiliar de cuentas', $p, ['sucursal' => true, 'extra' => $selectCuenta]);
?>

<?= rep_kpis([
    ['label' => 'Saldo inicial', 'valor' => money($totInicial), 'icono' => 'bank', 'color' => 'slate',
     'nota' => 'Al ' . fechaCorta($p['desde'])],
    ['label' => 'Entradas del periodo', 'valor' => money($totDebe), 'icono' => 'arrow-down', 'color' => 'emerald',
     'nota' => count($cuentas) . ' cuenta(s) activas'],
    ['label' => 'Salidas del periodo', 'valor' => money($totHaber), 'icono' => 'arrow-up', 'color' => 'rose',
     'nota' => 'Pagos y egresos'],
    ['label' => 'Saldo final', 'valor' => money($totFinal), 'icono' => 'wallet', 'color' => 'blue',
     'nota' => abs($totFinal - $balanceReal) < 1
        ? 'Coincide con el balance del sistema'
        : 'Balance del sistema: ' . money($balanceReal)],
]) ?>

<!-- Mayor resumido -->
<?= rep_seccion('Mayor por cuenta', 'Saldo inicial, movimientos del periodo y saldo final', 'book', 'blue',
    can('conciliacion.ver') ? '<a href="' . e(url('modules/finanzas/conciliacion.php')) . '" class="btn btn-soft btn-sm no-print">' . icon('check', 'w-3.5 h-3.5') . ' Conciliar</a>' : '') ?>
  <?php
  $tipos = ['efectivo' => ['Efectivo', 'emerald'], 'banco' => ['Banco', 'blue'], 'tarjeta' => ['Tarjeta', 'violet'],
            'transferencia' => ['Transferencia', 'cyan'], 'otro' => ['Otro', 'slate']];
  $filas = [];
  foreach ($cuentas as $c) {
      $id = (int) $c['id'];
      $r  = $resumenes[$id];
      [$lbl, $col] = $tipos[$c['tipo']] ?? ['Otro', 'slate'];
      $qs = array_merge($_GET, ['cuenta_id' => $id]);
      $pctConc = $r['n'] > 0 ? $r['conciliados'] / $r['n'] * 100 : 0;
      $filas[] = [
          '<a href="?' . e(http_build_query($qs)) . '" class="font-semibold text-slate-700 hover:text-blue-700">' . e($c['nombre']) . '</a>'
            . '<span class="block text-[11px] text-slate-400">' . e($c['sucursal'] ?: 'Global') . '</span>',
          badge($lbl, $col),
          '<span class="text-slate-500 tabular-nums">' . money($r['inicial']) . '</span>',
          '<span class="text-emerald-600 tabular-nums">' . money($r['debe']) . '</span>',
          '<span class="text-rose-600 tabular-nums">' . money($r['haber']) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($r['final']) . '</span>',
          '<span class="text-slate-400 tabular-nums text-xs">' . $r['n'] . ' mov.'
            . ($r['n'] > 0 ? '<span class="block text-[10px]">' . number_format($pctConc, 0) . '% conciliado</span>' : '') . '</span>',
      ];
  }
  echo rep_tabla(
      ['Cuenta', ['Tipo', 'center'], ['Saldo inicial', 'right'], ['Debe', 'right'], ['Haber', 'right'], ['Saldo final', 'right'], ['Movimientos', 'right']],
      $filas,
      ['total' => $filas ? ['Totales', '', money($totInicial), money($totDebe), money($totHaber), money($totFinal), ''] : null,
       'vacio_titulo' => 'Sin cuentas activas',
       'vacio' => 'Crea al menos una cuenta financiera para llevar el control del efectivo y los bancos.']
  );
  ?>
<?= rep_fin() ?>

<!-- Detalle de la cuenta -->
<?php if ($cuentaSel): ?>
  <?= rep_seccion('Movimientos de « ' . $cuentaSel['nombre'] . ' »',
      'Saldo corrido del ' . fechaCorta($p['desde']) . ' al ' . fechaCorta($p['hasta']), 'list', 'indigo',
      '<a href="?' . e(http_build_query(array_diff_key($_GET, ['cuenta_id' => 1]))) . '" class="btn btn-ghost btn-sm no-print">'
      . icon('x', 'w-3.5 h-3.5') . ' Quitar filtro</a>') ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th class="text-center">Origen</th>
              <th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Saldo</th><th class="text-center">Conc.</th></tr>
        </thead>
        <tbody>
          <tr class="bg-slate-50">
            <td colspan="6" class="font-semibold text-slate-600">Saldo inicial al <?= fechaCorta($p['desde']) ?></td>
            <td class="text-right font-bold text-slate-800 tabular-nums"><?= money($resumenes[$cuentaId]['inicial']) ?></td>
            <td></td>
          </tr>
          <?php if (!$movimientos): ?>
            <tr><td colspan="8" class="text-center text-slate-400 py-8">Esta cuenta no tuvo movimientos en el periodo.</td></tr>
          <?php else:
            $saldo = $resumenes[$cuentaId]['inicial'];
            foreach ($movimientos as $m):
              $esIngreso = $m['tipo'] === 'ingreso';
              $saldo += $esIngreso ? (float) $m['monto'] : -(float) $m['monto'];
          ?>
            <tr>
              <td class="text-slate-500 whitespace-nowrap"><?= fechaCorta($m['fecha']) ?></td>
              <td class="text-slate-700"><?= e($m['descripcion'] ?: $m['categoria']) ?></td>
              <td class="text-slate-500"><?= e($m['categoria']) ?></td>
              <td class="text-center"><?= badge($m['referencia_tipo'] ?: 'manual', 'slate') ?></td>
              <td class="text-right tabular-nums <?= $esIngreso ? 'font-semibold text-emerald-600' : 'text-slate-300' ?>"><?= $esIngreso ? money($m['monto'], false) : '—' ?></td>
              <td class="text-right tabular-nums <?= !$esIngreso ? 'font-semibold text-rose-600' : 'text-slate-300' ?>"><?= !$esIngreso ? money($m['monto'], false) : '—' ?></td>
              <td class="text-right font-bold text-slate-800 tabular-nums"><?= money($saldo, false) ?></td>
              <td class="text-center"><?= $m['conciliada'] ? icon('check', 'w-4 h-4 text-emerald-500 inline') : '<span class="text-slate-300">—</span>' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
          <tr class="bg-blue-50/60 font-bold text-slate-800">
            <td colspan="4" class="px-4 py-3.5 border-t-2 border-slate-200">Saldo final al <?= fechaCorta($p['hasta']) ?></td>
            <td class="px-4 py-3.5 border-t-2 border-slate-200 text-right tabular-nums"><?= money($resumenes[$cuentaId]['debe'], false) ?></td>
            <td class="px-4 py-3.5 border-t-2 border-slate-200 text-right tabular-nums"><?= money($resumenes[$cuentaId]['haber'], false) ?></td>
            <td class="px-4 py-3.5 border-t-2 border-slate-200 text-right tabular-nums"><?= money($resumenes[$cuentaId]['final'], false) ?></td>
            <td class="px-4 py-3.5 border-t-2 border-slate-200"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php if (count($movimientos) >= 500): ?>
      <p class="px-5 py-3 border-t border-slate-100 text-xs text-slate-500">Se muestran los primeros 500 movimientos. Reduce el rango de fechas o descarga el Excel.</p>
    <?php endif; ?>
  <?= rep_fin() ?>
<?php else: ?>
  <div class="card p-5 flex items-start gap-3 bg-slate-50/60">
    <span class="w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 flex items-center justify-center shrink-0"><?= icon('eye', 'w-4 h-4') ?></span>
    <p class="text-sm text-slate-600">Haz clic en el nombre de una cuenta para ver su mayor con el saldo corrido movimiento a movimiento.</p>
  </div>
<?php endif; ?>

<?php layout_end(); ?>
