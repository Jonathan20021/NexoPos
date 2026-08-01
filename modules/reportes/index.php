<?php
/** Centro de Reportes: el índice de todo lo que el negocio puede mirar. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.ver');

$grupos = rep_catalogo_visible();
$p      = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('v.sucursal_id');

// Cifras de encabezado para que el hub no sea solo una lista de enlaces.
$pv = array_merge([$p['ini'], $p['fin']], $scopeP);
$resumen = qOne(
    "SELECT COALESCE(SUM(v.total),0) AS venta, COALESCE(SUM(v.total - v.costo_total),0) AS margen,
            COUNT(*) AS n
       FROM ventas v
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope",
    $pv
) ?: ['venta' => 0, 'margen' => 0, 'n' => 0];

$prev = qOne(
    "SELECT COALESCE(SUM(v.total),0) AS venta FROM ventas v
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope",
    array_merge([$p['prev_ini'], $p['prev_fin']], $scopeP)
) ?: ['venta' => 0];

$gastos = rep_gastos_operativos($p['desde'], $p['hasta']);

$venta   = (float) $resumen['venta'];
$margen  = (float) $resumen['margen'];
$neto    = $margen - $gastos;
$pctMarg = $venta > 0 ? $margen / $venta * 100 : 0;

$totalReportes = 0;
foreach ($grupos as $g) $totalReportes += count($g['reportes']);

layout_start('Centro de Reportes', $totalReportes . ' reportes disponibles · ' . rep_subtitulo($p), rep_barra_titulo());
echo rep_filtros($p, ['sucursal' => true, 'acciones' => '']);
?>

<!-- Pulso del negocio -->
<?= rep_kpis([
    ['label' => 'Ventas del periodo', 'valor' => money($venta), 'icono' => 'cash', 'color' => 'blue',
     'delta' => rep_delta($venta, (float) $prev['venta']), 'nota' => (int) $resumen['n'] . ' factura(s)'],
    ['label' => 'Utilidad bruta', 'valor' => money($margen), 'icono' => 'trending', 'color' => 'emerald',
     'nota' => 'Margen ' . number_format($pctMarg, 1) . '% sobre la venta'],
    ['label' => 'Gastos operativos', 'valor' => money($gastos), 'icono' => 'dollar', 'color' => 'amber',
     'nota' => $venta > 0 ? number_format($gastos / $venta * 100, 1) . '% de la venta' : '—'],
    ['label' => 'Resultado neto', 'valor' => money($neto), 'icono' => 'chart', 'color' => $neto >= 0 ? 'violet' : 'rose',
     'nota' => $neto >= 0 ? 'Utilidad del periodo' : 'Pérdida del periodo'],
]) ?>

<?php if (!$grupos): ?>
  <?= empty_state('Sin reportes disponibles', 'Tu rol no tiene acceso a ningún bloque de reportes. Pídele a un administrador que te asigne los permisos.', 'lock') ?>
<?php endif; ?>

<?php foreach ($grupos as $clave => $g):
  $fondo = ['violet' => 'bg-violet-50 text-violet-600', 'emerald' => 'bg-emerald-50 text-emerald-600',
            'blue' => 'bg-blue-50 text-blue-600', 'amber' => 'bg-amber-50 text-amber-600'][$g['color']] ?? 'bg-blue-50 text-blue-600';
?>
  <section class="mb-7">
    <div class="flex items-center gap-3 mb-3.5">
      <span class="w-10 h-10 rounded-xl <?= $fondo ?> flex items-center justify-center shrink-0"><?= icon($g['icono'], 'w-5 h-5') ?></span>
      <div>
        <h2 class="text-lg font-extrabold text-slate-800 leading-tight"><?= e($g['titulo']) ?></h2>
        <p class="text-sm text-slate-400"><?= e($g['descripcion']) ?></p>
      </div>
      <span class="ml-auto badge badge-slate"><?= count($g['reportes']) ?> reportes</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <?php foreach ($g['reportes'] as [$archivo, $titulo, $desc, $ico]):
        $href = url('modules/reportes/' . $archivo) . '?periodo=' . e($p['preset'])
              . ($p['preset'] === 'personalizado' ? '&desde=' . e($p['desde']) . '&hasta=' . e($p['hasta']) : '')
              . (sucursalFiltroActual() ? '&sucursal_id=' . (int) sucursalFiltroActual() : '');
      ?>
        <a href="<?= e($href) ?>"
           class="card p-5 flex flex-col gap-2 hover:shadow-soft hover:border-blue-200 hover:-translate-y-0.5 transition-all duration-200 group focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20">
          <div class="flex items-start gap-3">
            <span class="w-10 h-10 rounded-xl bg-slate-50 text-slate-400 group-hover:<?= str_replace(' ', ' group-hover:', $fondo) ?> flex items-center justify-center shrink-0 transition-colors"><?= icon($ico, 'w-5 h-5') ?></span>
            <div class="min-w-0 flex-1">
              <h3 class="font-bold text-slate-800 leading-snug group-hover:text-blue-700 transition-colors"><?= e($titulo) ?></h3>
            </div>
            <span class="text-slate-300 group-hover:text-blue-600 group-hover:translate-x-0.5 transition-all shrink-0"><?= icon('arrow-right', 'w-4 h-4') ?></span>
          </div>
          <p class="text-[13px] text-slate-500 leading-relaxed"><?= e($desc) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<div class="card p-5 flex flex-col sm:flex-row items-start sm:items-center gap-4 bg-slate-50/60">
  <span class="w-11 h-11 rounded-xl bg-white border border-slate-200 text-slate-400 flex items-center justify-center shrink-0"><?= icon('download', 'w-5 h-5') ?></span>
  <div class="flex-1">
    <h3 class="font-bold text-slate-800">Todo se exporta</h3>
    <p class="text-sm text-slate-500 mt-0.5">Cada reporte se descarga en Excel para seguir trabajándolo o en PDF con el logo de la empresa, listo para enviar a la contabilidad externa o al banco.</p>
  </div>
  <?php if (can('dgii.ver')): ?>
    <a href="<?= e(url('modules/finanzas/dgii.php')) ?>" class="btn btn-ghost shrink-0"><?= icon('shield', 'w-4 h-4') ?> Formatos DGII</a>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
