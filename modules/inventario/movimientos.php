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
if ($desde) { $cond[] = "DATE(m.created_at) >= ?"; $params[] = $desde; }
if ($hasta) { $cond[] = "DATE(m.created_at) <= ?"; $params[] = $hasta; }
$where = implode(' AND ', $cond);

if (export_solicitado()) {
    $rows = qAll("SELECT m.created_at, p.codigo, p.nombre AS producto, su.nombre AS sucursal, m.tipo, m.cantidad, m.stock_anterior, m.stock_nuevo, m.motivo, u.nombre AS usuario FROM movimientos_inventario m JOIN productos p ON p.id=m.producto_id JOIN sucursales su ON su.id=m.sucursal_id LEFT JOIN usuarios u ON u.id=m.usuario_id WHERE $where ORDER BY m.id DESC", $params);
    export_tabla('movimientos_inventario', ['Fecha', 'Código', 'Producto', 'Sucursal', 'Tipo', 'Cantidad', 'Stock anterior', 'Stock nuevo', 'Motivo', 'Usuario'],
        array_map(fn($r) => [$r['created_at'], $r['codigo'], $r['producto'], $r['sucursal'], $r['tipo'], $r['cantidad'], $r['stock_anterior'], $r['stock_nuevo'], $r['motivo'], $r['usuario'] ?: 'Sistema'], $rows));
}

$pagina = max(1, (int) get('p'));
$porPagina = 40;
$total = (int) qVal("SELECT COUNT(*) FROM movimientos_inventario m JOIN productos p ON p.id=m.producto_id WHERE $where", $params);
$totalPag = max(1, (int) ceil($total / $porPagina));
$offset = ($pagina - 1) * $porPagina;

$movs = qAll(
    "SELECT m.*, p.nombre AS producto, p.codigo, su.nombre AS sucursal, u.nombre AS usuario
     FROM movimientos_inventario m
     JOIN productos p ON p.id=m.producto_id
     JOIN sucursales su ON su.id=m.sucursal_id
     LEFT JOIN usuarios u ON u.id=m.usuario_id
     WHERE $where ORDER BY m.id DESC LIMIT $porPagina OFFSET $offset", $params
);

$tipoBadge = ['entrada'=>['Entrada','emerald'],'compra'=>['Compra','emerald'],'transferencia_entrada'=>['Transf. entrada','sky'],'devolucion'=>['Devolución','sky'],'salida'=>['Salida','rose'],'venta'=>['Venta','rose'],'transferencia_salida'=>['Transf. salida','amber'],'ajuste'=>['Ajuste','violet']];

layout_start('Movimientos de inventario', 'Kardex: historial completo de entradas y salidas', export_buttons());
?>

<div class="card overflow-hidden">
  <?php $selSuc = selectSucursalFiltro(); ?>
  <form method="get" class="p-4 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-<?= $selSuc ? '5' : '4' ?> gap-3">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar producto..." aria-label="Buscar producto" class="input">
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
    <?php if ($totalPag > 1):
      $qs = $_GET; ?>
      <div class="flex items-center justify-between p-4 border-t border-slate-100 text-sm">
        <span class="text-slate-400"><?= number_format($total) ?> movimientos</span>
        <div class="flex items-center gap-1">
          <?php for ($i = max(1, $pagina - 2); $i <= min($totalPag, $pagina + 2); $i++): $qs['p'] = $i; ?>
            <a href="?<?= e(http_build_query($qs)) ?>" class="px-3 py-1.5 rounded-lg font-semibold <?= $i === $pagina ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
