<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.ver');

$desde = trim(get('desde'));
$hasta = trim(get('hasta'));
$desde = ($desde && strtotime($desde)) ? date('Y-m-d', strtotime($desde)) : date('Y-m-01');
$hasta = ($hasta && strtotime($hasta)) ? date('Y-m-d', strtotime($hasta)) : date('Y-m-t');
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$ini = $desde . ' 00:00:00';
$fin = $hasta . ' 23:59:59';
[$scopeW, $scopeP] = sucursalScope('v.sucursal_id');

if (isPost()) {
    verify_csrf();
    if (post('accion') === 'pagar') {
        require_perm('finanzas.crear');
        $vendId = postInt('vendedor_id');
        $monto = postNum('monto');
        $pdesde = post('desde'); $phasta = post('hasta');
        try {
            if ($monto <= 0) throw new RuntimeException('No hay comisión a pagar.');
            $v = qOne("SELECT CONCAT(nombre,' ',apellido) AS nombre FROM usuarios WHERE id = ?", [$vendId]);
            tx(function () use ($vendId, $monto, $pdesde, $phasta, $v) {
                $cuenta = qOne("SELECT id FROM cuentas_financieras WHERE tipo='efectivo' AND activo=1 LIMIT 1");
                registrarTransaccion('gasto', $monto, [
                    'cuenta_id' => $cuenta['id'] ?? null,
                    'categoria_id' => categoriaFinancieraId('gasto', 'Comisiones'),
                    'descripcion' => 'Comisión ' . ($v['nombre'] ?? '') . ' (' . fechaCorta($pdesde) . ' al ' . fechaCorta($phasta) . ')',
                    'referencia_tipo' => 'comision', 'referencia_id' => $vendId, 'fecha' => $phasta,
                ]);
            });
            audit('finanzas', 'crear', "Pago de comisión a {$v['nombre']} por " . money($monto));
            flash('success', 'Comisión pagada y registrada en finanzas.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/finanzas/comisiones.php?desde=' . $desde . '&hasta=' . $hasta);
    }
}

$params = array_merge([$ini, $fin], $scopeP);
$filas = qAll(
    "SELECT u.id, CONCAT(u.nombre,' ',u.apellido) AS vendedor, u.comision_pct,
            COUNT(v.id) AS ventas, COALESCE(SUM(v.total),0) AS facturado,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS base
     FROM usuarios u
     JOIN ventas v ON v.usuario_id = u.id AND v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY u.id HAVING ventas > 0 ORDER BY base DESC",
    $params
);
foreach ($filas as &$f) $f['comision'] = round($f['base'] * $f['comision_pct'] / 100, 2);
unset($f);

$totFact = array_sum(array_column($filas, 'facturado'));
$totBase = array_sum(array_column($filas, 'base'));
$totComision = array_sum(array_column($filas, 'comision'));

// ¿Ya pagada la comisión del periodo? (busca un gasto de comisión por vendedor en el rango)
$pagados = [];
foreach (qAll("SELECT referencia_id FROM transacciones WHERE referencia_tipo='comision' AND fecha BETWEEN ? AND ?", [$desde, $hasta]) as $p) {
    $pagados[(int) $p['referencia_id']] = true;
}

if (export_solicitado()) {
    export_tabla('comisiones', ['Vendedor', '% Comisión', 'Ventas', 'Facturado', 'Base (sin ITBIS)', 'Comisión'],
        array_map(fn($f) => [$f['vendedor'], $f['comision_pct'], $f['ventas'], $f['facturado'], $f['base'], $f['comision']], $filas),
        'Comisiones ' . fechaCorta($desde) . ' al ' . fechaCorta($hasta));
}

$acciones = export_buttons();
layout_start('Comisiones de Vendedores', 'Cálculo de comisiones · ' . fechaCorta($desde) . ' al ' . fechaCorta($hasta), $acciones);
?>
<form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
  <div><label class="label">Desde</label><input type="date" name="desde" value="<?= e($desde) ?>" class="input w-auto"></div>
  <div><label class="label">Hasta</label><input type="date" name="hasta" value="<?= e($hasta) ?>" class="input w-auto"></div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
  <a href="<?= e(url('modules/finanzas/comisiones.php')) ?>" class="btn btn-ghost">Mes actual</a>
</form>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5"><p class="text-sm text-slate-400">Total facturado</p><p class="text-2xl font-extrabold text-slate-800 mt-1"><?= money($totFact) ?></p></div>
  <div class="card p-5"><p class="text-sm text-slate-400">Base de comisión</p><p class="text-2xl font-extrabold text-slate-800 mt-1"><?= money($totBase) ?></p></div>
  <div class="card p-5"><p class="text-sm text-slate-400">Comisiones a pagar</p><p class="text-2xl font-extrabold text-emerald-600 mt-1"><?= money($totComision) ?></p></div>
</div>

<div class="card overflow-hidden">
  <?php if (!$filas): ?>
    <?= empty_state('Sin ventas en el periodo', 'No hay comisiones que calcular para estas fechas.', 'percent') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Vendedor</th><th class="text-center">% Comisión</th><th class="text-center">Ventas</th><th class="text-right">Facturado</th><th class="text-right">Base</th><th class="text-right">Comisión</th><th class="text-right">Acción</th></tr></thead>
        <tbody>
          <?php foreach ($filas as $f): $pagada = !empty($pagados[(int) $f['id']]); ?>
            <tr>
              <td><div class="flex items-center gap-2.5"><?= avatar($f['vendedor'], 'w-8 h-8') ?><span class="font-semibold text-slate-700"><?= e($f['vendedor']) ?></span></div></td>
              <td class="text-center"><span class="badge badge-blue"><?= number_format($f['comision_pct'], 2) ?>%</span></td>
              <td class="text-center text-slate-500"><?= (int) $f['ventas'] ?></td>
              <td class="text-right text-slate-600"><?= money($f['facturado']) ?></td>
              <td class="text-right text-slate-500"><?= money($f['base']) ?></td>
              <td class="text-right font-bold text-emerald-600"><?= money($f['comision']) ?></td>
              <td class="text-right">
                <?php if ($f['comision'] <= 0): ?>
                  <span class="text-slate-300 text-sm">—</span>
                <?php elseif ($pagada): ?>
                  <?= badge('Pagada', 'emerald') ?>
                <?php elseif (can('finanzas.crear')): ?>
                  <form method="post" class="inline" onsubmit="return confirm('¿Registrar el pago de comisión de <?= e($f['vendedor']) ?> por <?= e(money($f['comision'])) ?>?')">
                    <?= csrf_field() ?><input type="hidden" name="accion" value="pagar"><input type="hidden" name="vendedor_id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="monto" value="<?= $f['comision'] ?>"><input type="hidden" name="desde" value="<?= e($desde) ?>"><input type="hidden" name="hasta" value="<?= e($hasta) ?>">
                    <button class="btn btn-soft btn-sm"><?= icon('cash', 'w-3.5 h-3.5') ?> Pagar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="bg-slate-50 font-bold"><td colspan="3" class="px-4 py-3 border-t border-slate-200 text-slate-700">Totales</td><td class="px-4 py-3 border-t border-slate-200 text-right"><?= money($totFact) ?></td><td class="px-4 py-3 border-t border-slate-200 text-right"><?= money($totBase) ?></td><td class="px-4 py-3 border-t border-slate-200 text-right text-emerald-600"><?= money($totComision) ?></td><td class="border-t border-slate-200"></td></tr></tfoot>
      </table>
    </div>
    <p class="text-xs text-slate-400 p-4">La comisión se calcula sobre la base (subtotal sin ITBIS, menos descuentos) de las ventas completadas. El % se configura por usuario en Administración → Usuarios.</p>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
