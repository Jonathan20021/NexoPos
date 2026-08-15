<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('inventario.ver');

[$scope, $sp] = sucursalFiltro('m.sucursal_id');
$q = trim(get('q'));
$tipo = get('tipo');
$desde = get('desde');
$hasta = get('hasta');
$tipos = ['entrada','salida','ajuste','compra','venta','devolucion','transferencia_salida','transferencia_entrada'];

$cond = [$scope];
$params = $sp;
if ($q !== '') { $cond[] = "p.nombre LIKE ?"; $params[] = "%$q%"; }
if (in_array($tipo, $tipos, true)) { $cond[] = "m.tipo = ?"; $params[] = $tipo; }
// Rango sobre la columna, no DATE(columna): así se usa el índice de fecha.
if ($desde) { $cond[] = "m.created_at >= ?"; $params[] = $desde . ' 00:00:00'; }
if ($hasta) { $cond[] = "m.created_at <= ?"; $params[] = $hasta . ' 23:59:59'; }
$where = implode(' AND ', $cond);

if (export_solicitado()) {
    $rows = qAll("SELECT m.created_at, p.codigo, p.nombre AS producto, su.nombre AS sucursal, m.tipo, m.cantidad, m.stock_anterior, m.stock_nuevo, m.motivo, u.nombre AS usuario FROM movimientos_inventario m JOIN productos p ON p.id=m.producto_id JOIN sucursales su ON su.id=m.sucursal_id LEFT JOIN usuarios u ON u.id=m.usuario_id WHERE $where ORDER BY m.id DESC", $params);
    export_tabla('movimientos_inventario', ['Fecha', 'Código', 'Producto', 'Sucursal', 'Tipo', 'Cantidad', 'Stock anterior', 'Stock nuevo', 'Motivo', 'Usuario'],
        array_map(fn($r) => [$r['created_at'], $r['codigo'], $r['producto'], $r['sucursal'], $r['tipo'], $r['cantidad'], $r['stock_anterior'], $r['stock_nuevo'], $r['motivo'], $r['usuario'] ?: 'Sistema'], $rows));
}

$pg = paginar((int) qVal("SELECT COUNT(*) FROM movimientos_inventario m JOIN productos p ON p.id=m.producto_id WHERE $where", $params), 40);

$movs = qAll(
    "SELECT m.*, p.nombre AS producto, p.codigo, su.nombre AS sucursal, u.nombre AS usuario
     FROM movimientos_inventario m
     JOIN productos p ON p.id=m.producto_id
     JOIN sucursales su ON su.id=m.sucursal_id
     LEFT JOIN usuarios u ON u.id=m.usuario_id
     WHERE $where ORDER BY m.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $params
);

$tipoBadge = ['entrada'=>['Entrada','emerald'],'compra'=>['Compra','emerald'],'transferencia_entrada'=>['Transf. entrada','sky'],'devolucion'=>['Devolución','sky'],'salida'=>['Salida','rose'],'venta'=>['Venta','rose'],'transferencia_salida'=>['Transf. salida','amber'],'ajuste'=>['Ajuste','violet']];

// Aquí los totales SÍ siguen al filtro, al revés que en el catálogo: un kardex
// se lee por tramos —este mes, esta sucursal, este producto— y el resumen tiene
// que hablar del tramo que se está mirando, no del histórico completo.
$resumen = qOne(
    "SELECT COUNT(*) n,
            COALESCE(SUM(CASE WHEN m.cantidad > 0 THEN m.cantidad ELSE 0 END), 0) entradas,
            COALESCE(SUM(CASE WHEN m.cantidad < 0 THEN -m.cantidad ELSE 0 END), 0) salidas,
            COALESCE(SUM(m.tipo = 'ajuste'), 0) ajustes
       FROM movimientos_inventario m
       JOIN productos p ON p.id = m.producto_id
      WHERE $where", $params
) ?: ['n' => 0, 'entradas' => 0, 'salidas' => 0, 'ajustes' => 0];
$neto = (float) $resumen['entradas'] - (float) $resumen['salidas'];

layout_start('Movimientos de inventario', 'Kardex: historial completo de entradas y salidas', export_buttons());

echo kpis([
    ['label' => 'Movimientos', 'valor' => number_format((int) $resumen['n']), 'icono' => 'history', 'color' => 'slate',
     'nota' => 'En el tramo filtrado'],
    ['label' => 'Unidades que entraron', 'valor' => qty($resumen['entradas']), 'icono' => 'arrow-down', 'color' => 'emerald'],
    ['label' => 'Unidades que salieron', 'valor' => qty($resumen['salidas']), 'icono' => 'arrow-up', 'color' => 'rose'],
    ['label' => 'Movimiento neto', 'valor' => ($neto >= 0 ? '+' : '−') . qty(abs($neto)),
     'icono' => 'layers', 'color' => $neto >= 0 ? 'blue' : 'amber',
     'nota' => (int) $resumen['ajustes'] > 0
        ? number_format((int) $resumen['ajustes']) . ' ajuste' . ((int) $resumen['ajustes'] === 1 ? '' : 's') . ' incluidos'
        : 'Sin ajustes manuales'],
], 4);
?>

<div class="card overflow-hidden">
  <?php $selSuc = selectSucursalFiltro(); ?>
  <form method="get" class="p-4 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-<?= $selSuc ? '5' : '4' ?> gap-3">
    <input type="hidden" name="p" value="1">
    <input type="search" name="q" data-buscar value="<?= e($q) ?>" placeholder="Buscar producto..." aria-label="Buscar producto" autocomplete="off" class="input">
    <?= $selSuc ?>
    <select name="tipo" aria-label="Tipo de movimiento" class="select cursor-pointer"><option value="">Todos los tipos</option><?php foreach ($tipos as $t): ?><option value="<?= $t ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= e($tipoBadge[$t][0] ?? $t) ?></option><?php endforeach; ?></select>
    <input type="date" name="desde" value="<?= e($desde) ?>" class="input" title="Desde">
    <div class="flex gap-2"><input type="date" name="hasta" value="<?= e($hasta) ?>" class="input" title="Hasta"><button aria-label="Aplicar filtros" title="Filtrar" class="btn btn-primary shrink-0"><?= icon('filter', 'w-4 h-4') ?></button></div>
  </form>

  <?php if (!$movs): ?>
    <?= empty_state('Sin movimientos', 'No hay movimientos que coincidan con los filtros.', 'history') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Producto</th><th>Sucursal</th><th>Tipo</th><th class="text-center">Cantidad</th><th class="text-center">Anterior → Nuevo</th><th>Motivo</th><th>Usuario</th></tr></thead>
        <tbody>
          <?php foreach ($movs as $m):
            $tb = $tipoBadge[$m['tipo']] ?? [$m['tipo'], 'slate'];
            $pos = $m['cantidad'] >= 0;
          ?>
            <tr>
              <td class="text-slate-500 whitespace-nowrap"><?= fechaHora($m['created_at']) ?></td>
              <td><p class="font-semibold text-slate-700"><?= e($m['producto']) ?></p><p class="text-xs text-slate-400"><?= e($m['codigo']) ?></p></td>
              <td class="text-slate-500"><?= e($m['sucursal']) ?></td>
              <td><?= badge($tb[0], $tb[1]) ?></td>
              <td class="text-center font-bold <?= $pos ? 'text-emerald-600' : 'text-rose-600' ?>"><?= ($pos ? '+' : '') . qty($m['cantidad']) ?></td>
              <td class="text-center text-slate-400 text-xs"><?= qty($m['stock_anterior']) ?> → <span class="text-slate-600 font-semibold"><?= qty($m['stock_nuevo']) ?></span></td>
              <td class="text-slate-500 max-w-xs truncate"><?= e($m['motivo'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($m['usuario'] ?: 'Sistema') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
