<?php
/**
 * Libro de ventas: factura por factura, con NCF, cliente, vendedor, forma de
 * pago y margen. Es el detalle que respalda todos los demás reportes.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.operacion');

$p = rep_periodo('mes');
[$scope, $scopeP] = rep_scope('v.sucursal_id');

$q          = trim((string) get('q'));
$estado     = in_array(get('estado'), ['completada', 'anulada', 'devuelta'], true) ? get('estado') : '';
$comprobante = in_array(get('comprobante'), ['consumidor', 'credito_fiscal'], true) ? get('comprobante') : '';
$vendedorId = (int) get('vendedor_id');

$cond = ['v.fecha BETWEEN ? AND ?', $scope];
$par  = array_merge([$p['ini'], $p['fin']], $scopeP);
if ($estado !== '')      { $cond[] = 'v.estado = ?';           $par[] = $estado; }
else                     { $cond[] = "v.estado <> 'anulada'"; }
if ($comprobante !== '') { $cond[] = 'v.tipo_comprobante = ?'; $par[] = $comprobante; }
if ($vendedorId > 0)     { $cond[] = 'v.usuario_id = ?';       $par[] = $vendedorId; }
if ($q !== '') {
    $cond[] = '(v.numero LIKE ? OR v.ncf LIKE ? OR cl.nombre LIKE ?)';
    array_push($par, "%$q%", "%$q%", "%$q%");
}
$where = implode(' AND ', $cond);

$base = "FROM ventas v
         LEFT JOIN clientes cl   ON cl.id = v.cliente_id
         JOIN usuarios u         ON u.id = v.usuario_id
         JOIN sucursales su      ON su.id = v.sucursal_id
        WHERE $where";

$tot = qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(v.subtotal - v.descuento),0) ingresos,
            COALESCE(SUM(v.itbis),0) itbis, COALESCE(SUM(v.total),0) total,
            COALESCE(SUM(v.costo_total),0) costo, COALESCE(SUM(v.descuento),0) descuento
     $base", $par
) ?: [];
$ingresos = (float) ($tot['ingresos'] ?? 0);
$utilidad = $ingresos - (float) ($tot['costo'] ?? 0);

$pg = paginar((int) ($tot['n'] ?? 0), 50);
$ventas = qAll(
    "SELECT v.*, COALESCE(cl.nombre,'Consumidor final') AS cliente, cl.rnc_cedula,
            CONCAT(u.nombre,' ',u.apellido) AS vendedor, su.nombre AS sucursal,
            (SELECT GROUP_CONCAT(DISTINCT mp.nombre SEPARATOR ', ')
               FROM venta_pagos vp JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
              WHERE vp.venta_id = v.id) AS metodos
     $base ORDER BY v.fecha DESC, v.id DESC
     LIMIT " . (int) $pg['porPagina'] . " OFFSET " . (int) $pg['offset'],
    $par
);

$vendedores = qAll(
    "SELECT DISTINCT u.id, CONCAT(u.nombre,' ',u.apellido) AS nombre
       FROM ventas v JOIN usuarios u ON u.id = v.usuario_id
      WHERE v.fecha BETWEEN ? AND ? AND $scope ORDER BY nombre",
    array_merge([$p['ini'], $p['fin']], $scopeP)
);

if (export_solicitado()) {
    $todas = qAll(
        "SELECT v.numero, v.ncf, v.fecha, v.tipo_comprobante, v.estado,
                COALESCE(cl.nombre,'Consumidor final') AS cliente, cl.rnc_cedula,
                CONCAT(u.nombre,' ',u.apellido) AS vendedor, su.nombre AS sucursal,
                v.subtotal, v.descuento, v.itbis, v.total, v.costo_total, v.canal_venta
         $base ORDER BY v.fecha", $par
    );
    $filas = [];
    foreach ($todas as $v) {
        $ing = (float) $v['subtotal'] - (float) $v['descuento'];
        $filas[] = [$v['numero'], $v['ncf'] ?? '', fechaHora($v['fecha']),
            $v['tipo_comprobante'] === 'credito_fiscal' ? 'B01 Crédito fiscal' : 'B02 Consumidor',
            $v['cliente'], $v['rnc_cedula'] ?? '', $v['vendedor'], $v['sucursal'], $v['canal_venta'] ?? '',
            money($v['subtotal'], false), money($v['descuento'], false), money($v['itbis'], false),
            money($v['total'], false), money($v['costo_total'], false),
            money($ing - (float) $v['costo_total'], false), $v['estado']];
    }
    export_tabla('libro_ventas_' . $p['desde'] . '_' . $p['hasta'],
        ['Factura', 'NCF', 'Fecha', 'Comprobante', 'Cliente', 'RNC/Cédula', 'Vendedor', 'Sucursal', 'Canal',
         'Subtotal', 'Descuento', 'ITBIS', 'Total', 'Costo', 'Utilidad', 'Estado'],
        $filas, 'Libro de ventas');
}

$filtroExtra = '<select name="estado" aria-label="Estado" class="select cursor-pointer">'
    . '<option value="">Estado: todas</option>'
    . '<option value="completada"' . ($estado === 'completada' ? ' selected' : '') . '>Completadas</option>'
    . '<option value="devuelta"' . ($estado === 'devuelta' ? ' selected' : '') . '>Con devolución</option>'
    . '<option value="anulada"' . ($estado === 'anulada' ? ' selected' : '') . '>Anuladas</option>'
    . '</select>'
    . '<select name="comprobante" aria-label="Comprobante" class="select cursor-pointer">'
    . '<option value="">Comprobante: todos</option>'
    . '<option value="credito_fiscal"' . ($comprobante === 'credito_fiscal' ? ' selected' : '') . '>B01 Crédito fiscal</option>'
    . '<option value="consumidor"' . ($comprobante === 'consumidor' ? ' selected' : '') . '>B02 Consumidor final</option>'
    . '</select>'
    . '<select name="vendedor_id" aria-label="Vendedor" class="select cursor-pointer"><option value="">Vendedor: todos</option>';
foreach ($vendedores as $v) {
    $filtroExtra .= '<option value="' . (int) $v['id'] . '"' . ($vendedorId === (int) $v['id'] ? ' selected' : '') . '>' . e($v['nombre']) . '</option>';
}
$filtroExtra .= '</select>'
    . '<input type="search" name="q" value="' . e($q) . '" placeholder="Factura, NCF o cliente..." class="input w-auto min-w-[220px]">';

layout_start('Libro de ventas', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Libro de ventas', $p, ['sucursal' => true, 'extra' => $filtroExtra]);
?>

<?= rep_kpis([
    ['label' => 'Facturas', 'valor' => number_format((int) ($tot['n'] ?? 0)), 'icono' => 'receipt', 'color' => 'blue',
     'nota' => 'En el rango y filtros actuales'],
    ['label' => 'Ingresos netos', 'valor' => money($ingresos), 'icono' => 'cash', 'color' => 'emerald',
     'nota' => 'ITBIS aparte: ' . money($tot['itbis'] ?? 0)],
    ['label' => 'Total facturado', 'valor' => money($tot['total'] ?? 0), 'icono' => 'dollar', 'color' => 'violet',
     'nota' => 'Con ITBIS incluido'],
    ['label' => 'Utilidad bruta', 'valor' => money($utilidad), 'icono' => 'trending',
     'color' => $utilidad >= 0 ? 'emerald' : 'rose',
     'nota' => 'Margen ' . number_format($ingresos > 0 ? $utilidad / $ingresos * 100 : 0, 1) . '% · descuentos ' . money($tot['descuento'] ?? 0)],
]) ?>

<?= rep_seccion('Detalle de facturas', number_format((int) ($tot['n'] ?? 0)) . ' documento(s) en el periodo', 'list', 'blue') ?>
  <?php
  $filas = [];
  foreach ($ventas as $v) {
      $ing = (float) $v['subtotal'] - (float) $v['descuento'];
      $ut  = $ing - (float) $v['costo_total'];
      $mg  = $ing > 0 ? $ut / $ing * 100 : 0;
      $verUrl = url('modules/pos/ticket.php?id=' . (int) $v['id']);
      $filas[] = [
          '<a href="' . e($verUrl) . '" target="_blank" rel="noopener" class="font-semibold text-slate-700 hover:text-blue-700">' . e($v['numero']) . '</a>'
            . ($v['ncf'] ? '<span class="block text-[11px] text-slate-400">' . e($v['ncf']) . '</span>' : ''),
          '<span class="text-slate-500 whitespace-nowrap">' . fechaHora($v['fecha']) . '</span>',
          '<div class="min-w-0"><span class="text-slate-700 block truncate">' . e($v['cliente']) . '</span>'
            . ($v['rnc_cedula'] ? '<span class="text-[11px] text-slate-400">' . e($v['rnc_cedula']) . '</span>' : '') . '</div>',
          '<span class="text-slate-500">' . e($v['vendedor']) . '</span>'
            . '<span class="block text-[11px] text-slate-400">' . e($v['sucursal']) . '</span>',
          '<span class="text-[11.5px] text-slate-500">' . e($v['metodos'] ?: '—') . '</span>'
            . ($v['canal_venta'] ? '<span class="block text-[10.5px] text-slate-400">' . e($v['canal_venta']) . '</span>' : ''),
          badge($v['tipo_comprobante'] === 'credito_fiscal' ? 'B01' : 'B02', $v['tipo_comprobante'] === 'credito_fiscal' ? 'blue' : 'slate'),
          '<span class="text-slate-600 tabular-nums">' . money($v['subtotal'], false) . '</span>',
          '<span class="text-slate-500 tabular-nums">' . ((float) $v['descuento'] > 0 ? money($v['descuento'], false) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-slate-500 tabular-nums">' . money($v['itbis'], false) . '</span>',
          '<span class="font-bold text-slate-800 tabular-nums">' . money($v['total'], false) . '</span>',
          '<span class="' . ($ut >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' font-semibold tabular-nums">' . money($ut, false)
            . '<span class="block text-[10px] font-medium text-slate-400">' . number_format($mg, 1) . '%</span></span>',
          badgeFor($v['estado']),
      ];
  }
  echo rep_tabla(
      ['Factura', 'Fecha', 'Cliente', 'Vendedor', 'Pago', ['Tipo', 'center'], ['Subtotal', 'right'],
       ['Desc.', 'right'], ['ITBIS', 'right'], ['Total', 'right'], ['Utilidad', 'right'], ['Estado', 'center']],
      $filas,
      ['total' => $filas ? ['Total del periodo', '', '', '', '', '',
          money((float) ($tot['ingresos'] ?? 0) + (float) ($tot['descuento'] ?? 0), false),
          money($tot['descuento'] ?? 0, false), money($tot['itbis'] ?? 0, false),
          money($tot['total'] ?? 0, false), money($utilidad, false), ''] : null,
       'vacio_titulo' => 'Sin facturas',
       'vacio' => 'No hay ventas que coincidan con el periodo y los filtros seleccionados.', 'vacio_icono' => 'receipt']
  );
  echo paginacion($pg);
  ?>
<?= rep_fin() ?>

<?php layout_end(); ?>
