<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('ventas.ver');

if (isPost()) {
    verify_csrf();
    if (post('accion') === 'anular') {
        require_perm('ventas.anular');
        $id = postInt('id');
        // 608, columna 3: la DGII exige el motivo de la anulación.
        $tipoAnulacion = postInt('tipo_anulacion');
        try {
            if (!isset(dgiiTiposAnulacion()[$tipoAnulacion])) {
                throw new RuntimeException('Selecciona un motivo de anulación válido para el reporte 608.');
            }
            tx(function () use ($id, $tipoAnulacion) {
                $v = qOne("SELECT * FROM ventas WHERE id = ? FOR UPDATE", [$id]);
                if (!$v || $v['estado'] !== 'completada') throw new RuntimeException('La venta no se puede anular.');
                if (!can_access_sucursal($v['sucursal_id'])) throw new RuntimeException('No tienes acceso a la sucursal de esta venta.');
                if (qVal("SELECT 1 FROM devoluciones WHERE venta_id = ? LIMIT 1", [$id])) throw new RuntimeException('La venta tiene devoluciones; no se puede anular.');
                if ($v['caja_sesion_id'] && qVal("SELECT 1 FROM caja_sesiones WHERE id = ? AND estado = 'cerrada'", [$v['caja_sesion_id']])) {
                    throw new RuntimeException('La caja de esta venta ya fue cerrada. Registra una devolución para mantener el cuadre.');
                }
                foreach (qAll("SELECT vd.*, p.tipo AS producto_tipo FROM venta_detalles vd LEFT JOIN productos p ON p.id=vd.producto_id WHERE vd.venta_id = ?", [$id]) as $d) {
                    if ($d['producto_id'] && $d['producto_tipo'] === 'producto') {
                        ajustarStock((int) $d['producto_id'], (int) $v['sucursal_id'], (float) $d['cantidad'], 'entrada', 'venta_anulada', $id, (float) $d['costo_unitario'], 'Anulación venta ' . $v['numero']);
                    }
                }
                $esCredito = (int) qVal(
                    "SELECT COALESCE(MAX(m.es_credito),0) FROM venta_pagos vp JOIN metodos_pago m ON m.id=vp.metodo_pago_id WHERE vp.venta_id=?",
                    [$id]
                ) === 1;
                if ($esCredito) {
                    $cli = qOne("SELECT id, balance FROM clientes WHERE id = ? FOR UPDATE", [$v['cliente_id']]);
                    if (!$cli || round((float) $cli['balance'], 2) < round((float) $v['total'], 2)) {
                        throw new RuntimeException('La venta a crédito ya tiene abonos aplicados. Usa una devolución para corregirla.');
                    }
                    q("UPDATE clientes SET balance = balance - ? WHERE id = ?", [$v['total'], $cli['id']]);
                } else {
                    foreach (qAll("SELECT * FROM transacciones WHERE referencia_tipo='venta' AND referencia_id = ?", [$id]) as $tr) {
                        if ($tr['cuenta_id']) q("UPDATE cuentas_financieras SET balance = balance - ? WHERE id = ?", [$tr['monto'], $tr['cuenta_id']]);
                        q("DELETE FROM transacciones WHERE id = ?", [$tr['id']]);
                    }
                }
                dbUpdate('ventas', ['estado' => 'anulada'], 'id = ?', [$id]);

                // Formato 608: solo se reportan comprobantes fiscales realmente emitidos.
                if (!empty($v['ncf'])) {
                    dbInsert('comprobantes_anulados', [
                        'ncf'               => $v['ncf'],
                        'fecha_comprobante' => substr($v['fecha'], 0, 10),
                        'tipo_anulacion'    => $tipoAnulacion,
                        'venta_id'          => $id,
                        'sucursal_id'       => $v['sucursal_id'],
                        'usuario_id'        => current_user()['id'],
                        'notas'             => 'Anulación de la venta ' . $v['numero'],
                    ]);
                }
            });
            audit('ventas', 'anular', "Venta anulada #$id", ['tabla' => 'ventas', 'registro_id' => $id]);
            flash('success', 'Venta anulada y stock revertido.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/ventas.php');
    }
}

// ----- Detalle -----
$verId = (int) get('ver');
if ($verId) {
    $v = qOne("SELECT v.*, su.nombre AS sucursal, cl.nombre AS cliente, u.nombre AS vendedor, u.apellido AS vend_ape FROM ventas v JOIN sucursales su ON su.id=v.sucursal_id LEFT JOIN clientes cl ON cl.id=v.cliente_id JOIN usuarios u ON u.id=v.usuario_id WHERE v.id=?", [$verId]);
    if (!$v) { flash('error', 'Venta no encontrada.'); redirect('modules/pos/ventas.php'); }
    require_sucursal_access($v['sucursal_id']);
    $det = qAll(
        "SELECT vd.*, COALESCE(NULLIF(vd.descripcion,''), p.nombre, '(producto no disponible)') AS descripcion
         FROM venta_detalles vd LEFT JOIN productos p ON p.id = vd.producto_id
         WHERE vd.venta_id = ?",
        [$verId]
    );
    $pagos = qAll("SELECT vp.*, m.nombre AS metodo FROM venta_pagos vp JOIN metodos_pago m ON m.id=vp.metodo_pago_id WHERE vp.venta_id=?", [$verId]);
    layout_start('Venta ' . e($v['numero']), 'Detalle de la venta', '<a href="' . url('modules/pos/ventas.php') . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Volver</a><a href="' . url('modules/pos/ticket.php?id=' . $verId . '&pdf=1') . '" target="_blank" class="btn btn-ghost">' . icon('download', 'w-4 h-4') . ' Factura PDF</a><a href="' . url('modules/pos/ticket.php?id=' . $verId) . '" target="_blank" class="btn btn-primary">' . icon('print', 'w-4 h-4') . ' Ticket</a>');
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="card lg:col-span-2 overflow-hidden">
        <table class="data-table">
          <thead><tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-right">Precio</th><th class="text-right">ITBIS</th><th class="text-right">Subtotal</th></tr></thead>
          <tbody>
            <?php foreach ($det as $d): ?><tr><td class="font-semibold text-slate-700"><?= e($d['descripcion']) ?></td><td class="text-center"><?= qty($d['cantidad']) ?></td><td class="text-right"><?= money($d['precio_unitario']) ?></td><td class="text-right text-slate-500"><?= money($d['itbis']) ?></td><td class="text-right font-bold text-slate-800"><?= money($d['subtotal']) ?></td></tr><?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card p-5 h-fit space-y-3">
        <div class="flex items-center justify-between"><span class="text-xs text-slate-400">Estado</span><?= badgeFor($v['estado']) ?></div>
        <div><p class="text-xs text-slate-400">Cliente</p><p class="font-semibold text-slate-700"><?= e($v['cliente'] ?: 'Cliente Genérico') ?></p></div>
        <div><p class="text-xs text-slate-400">Sucursal</p><p class="font-semibold text-slate-700"><?= e($v['sucursal']) ?></p></div>
        <div><p class="text-xs text-slate-400">Vendedor</p><p class="font-semibold text-slate-700"><?= e($v['vendedor'] . ' ' . $v['vend_ape']) ?></p></div>
        <div><p class="text-xs text-slate-400">Fecha</p><p class="font-semibold text-slate-700"><?= fechaHora($v['fecha']) ?></p></div>
        <?php if ($v['ncf']): ?><div><p class="text-xs text-slate-400">NCF (<?= $v['tipo_comprobante'] === 'credito_fiscal' ? 'Crédito Fiscal' : 'Consumidor' ?>)</p><p class="font-semibold text-slate-700"><?= e($v['ncf']) ?></p></div><?php endif; ?>
        <div class="border-t border-slate-100 pt-3 space-y-1.5 text-sm">
          <div class="flex justify-between text-slate-500"><span>Subtotal</span><span><?= money($v['subtotal']) ?></span></div>
          <?php if ($v['descuento'] > 0): ?><div class="flex justify-between text-rose-600"><span>Descuento</span><span>−<?= money($v['descuento']) ?></span></div><?php endif; ?>
          <div class="flex justify-between text-slate-500"><span>ITBIS</span><span><?= money($v['itbis']) ?></span></div>
          <div class="flex justify-between text-lg font-extrabold text-slate-800 pt-1 border-t border-slate-100"><span>Total</span><span><?= money($v['total']) ?></span></div>
        </div>
        <div class="border-t border-slate-100 pt-3"><?php foreach ($pagos as $p): ?><div class="flex justify-between text-sm text-slate-500"><span><?= e($p['metodo']) ?></span><span><?= money($p['monto']) ?></span></div><?php endforeach; ?></div>
      </div>
    </div>
    <?php layout_end(); return;
}

// ----- Listado -----
[$scope, $sp] = sucursalFiltro('v.sucursal_id');
$q = trim(get('q'));
$estado = get('estado');
$desde = get('desde');
$hasta = get('hasta');
$cond = [$scope];
$params = $sp;
if ($q !== '') { $cond[] = "(v.numero LIKE ? OR cl.nombre LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if (in_array($estado, ['completada', 'anulada', 'devuelta'], true)) { $cond[] = "v.estado = ?"; $params[] = $estado; }
if ($desde) { $cond[] = "DATE(v.fecha) >= ?"; $params[] = $desde; }
if ($hasta) { $cond[] = "DATE(v.fecha) <= ?"; $params[] = $hasta; }
$where = implode(' AND ', $cond);

if (export_solicitado()) {
    $rows = qAll("SELECT v.numero, v.ncf, v.fecha, v.subtotal, v.itbis, v.total, v.estado, su.nombre AS sucursal, cl.nombre AS cliente, u.nombre AS vendedor FROM ventas v JOIN sucursales su ON su.id=v.sucursal_id LEFT JOIN clientes cl ON cl.id=v.cliente_id JOIN usuarios u ON u.id=v.usuario_id WHERE $where ORDER BY v.id DESC", $params);
    export_tabla('ventas', ['Factura', 'NCF', 'Fecha', 'Cliente', 'Sucursal', 'Vendedor', 'Subtotal', 'ITBIS', 'Total', 'Estado'],
        array_map(fn($r) => [$r['numero'], $r['ncf'], $r['fecha'], $r['cliente'] ?: 'Cliente Genérico', $r['sucursal'], $r['vendedor'], $r['subtotal'], $r['itbis'], $r['total'], $r['estado']], $rows));
}

$pagina = max(1, (int) get('p'));
$pp = 25;
$total = (int) qVal("SELECT COUNT(*) FROM ventas v LEFT JOIN clientes cl ON cl.id=v.cliente_id WHERE $where", $params);
$totalPag = max(1, (int) ceil($total / $pp));
$offset = ($pagina - 1) * $pp;
$ventas = qAll("SELECT v.*, su.nombre AS sucursal, cl.nombre AS cliente, u.nombre AS vendedor FROM ventas v JOIN sucursales su ON su.id=v.sucursal_id LEFT JOIN clientes cl ON cl.id=v.cliente_id JOIN usuarios u ON u.id=v.usuario_id WHERE $where ORDER BY v.id DESC LIMIT $pp OFFSET $offset", $params);

$totVendido = (float) qVal("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE $where AND v.estado='completada'", $params);

layout_start('Ventas', 'Historial de ventas' . ($total ? ' · ' . number_format($total) . ' registros' : ''), export_buttons());
?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5"><p class="text-sm text-slate-400">Total vendido (filtro)</p><p class="text-2xl font-extrabold text-slate-800 mt-1"><?= money($totVendido) ?></p></div>
  <div class="card p-5"><p class="text-sm text-slate-400">Transacciones</p><p class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format($total) ?></p></div>
  <div class="card p-5"><p class="text-sm text-slate-400">Ticket promedio</p><p class="text-2xl font-extrabold text-slate-800 mt-1"><?= money($total > 0 ? $totVendido / max(1, $total) : 0) ?></p></div>
</div>

<div class="card overflow-hidden">
  <?php $selSuc = selectSucursalFiltro(); ?>
  <form method="get" class="p-4 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-<?= $selSuc ? '6' : '5' ?> gap-3">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Factura o cliente..." aria-label="Buscar factura o cliente" class="input sm:col-span-2">
    <?= $selSuc ?>
    <select name="estado" aria-label="Estado" class="select cursor-pointer"><option value="">Todos</option><option value="completada" <?= $estado === 'completada' ? 'selected' : '' ?>>Completada</option><option value="anulada" <?= $estado === 'anulada' ? 'selected' : '' ?>>Anulada</option><option value="devuelta" <?= $estado === 'devuelta' ? 'selected' : '' ?>>Devuelta</option></select>
    <input type="date" name="desde" value="<?= e($desde) ?>" aria-label="Fecha inicial" class="input">
    <div class="flex gap-2"><input type="date" name="hasta" value="<?= e($hasta) ?>" aria-label="Fecha final" class="input"><button aria-label="Aplicar filtros" title="Filtrar" class="btn btn-primary shrink-0 cursor-pointer"><?= icon('filter', 'w-4 h-4') ?></button></div>
  </form>
  <?php if (!$ventas): ?>
    <?= empty_state('Sin ventas', 'No hay ventas que coincidan con los filtros.', 'receipt') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Factura</th><th>Cliente</th><th>Sucursal</th><th>Vendedor</th><th>Fecha</th><th class="text-right">Total</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($ventas as $v): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($v['numero']) ?><?php if ($v['ncf']): ?><br><span class="text-[11px] text-slate-400"><?= e($v['ncf']) ?></span><?php endif; ?></td>
              <td class="text-slate-600"><?= e($v['cliente'] ?: 'Cliente Genérico') ?></td>
              <td class="text-slate-500"><?= e($v['sucursal']) ?></td>
              <td class="text-slate-500"><?= e($v['vendedor']) ?></td>
              <td class="text-slate-500 whitespace-nowrap"><?= fechaHora($v['fecha']) ?></td>
              <td class="text-right font-bold text-slate-800"><?= money($v['total']) ?></td>
              <td><?= badgeFor($v['estado']) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="?ver=<?= (int) $v['id'] ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ver"><?= icon('eye', 'w-4 h-4') ?></a>
                  <a href="<?= e(url('modules/pos/ticket.php?id=' . (int) $v['id'])) ?>" target="_blank" class="p-2 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100" title="Ticket"><?= icon('print', 'w-4 h-4') ?></a>
                  <?php if (can('ventas.anular') && $v['estado'] === 'completada'): ?>
                    <button type="button"
                            onclick="<?= jsEvent('venta:anular', ['id' => (int) $v['id'], 'numero' => $v['numero'], 'ncf' => $v['ncf'] ?? '']) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 cursor-pointer transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                            title="Anular" aria-label="Anular la venta <?= e($v['numero']) ?>"><?= icon('x', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPag > 1): $qs = $_GET; ?>
      <div class="flex items-center justify-between p-4 border-t border-slate-100 text-sm">
        <span class="text-slate-400">Página <?= $pagina ?> de <?= $totalPag ?></span>
        <div class="flex items-center gap-1">
          <?php for ($i = max(1, $pagina - 2); $i <= min($totalPag, $pagina + 2); $i++): $qs['p'] = $i; ?><a href="?<?= e(http_build_query($qs)) ?>" class="px-3 py-1.5 rounded-lg font-semibold <?= $i === $pagina ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100' ?>"><?= $i ?></a><?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Modal anular venta: la DGII exige el motivo (Formato 608) -->
<div x-data="{ open: false, venta: { id: 0, numero: '', ncf: '' } }"
     @venta:anular.window="venta = $event.detail; open = true"
     @keydown.escape.window="open = false"
     x-show="open" x-transition.opacity style="display:none"
     class="modal-overlay" @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="tituloAnular">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="anular">
      <input type="hidden" name="id" :value="venta.id">

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="tituloAnular" class="font-bold text-slate-800">Anular venta <span x-text="venta.numero"></span></h3>
        <button type="button" @click="open = false" aria-label="Cerrar modal" title="Cerrar"
                class="text-slate-400 hover:text-slate-700 p-1 -m-1 cursor-pointer transition-colors duration-200"><?= icon('x', 'w-5 h-5') ?></button>
      </div>

      <div class="p-6 space-y-4">
        <div class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
          <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0') ?>
          <p class="text-sm text-amber-800">Se revertirá el stock y se eliminará el ingreso registrado en Finanzas. Esta acción no se puede deshacer.</p>
        </div>

        <div>
          <label class="label" for="tipo_anulacion">Motivo de la anulación *</label>
          <select id="tipo_anulacion" name="tipo_anulacion" required class="select">
            <option value="">Selecciona el motivo...</option>
            <?php foreach (dgiiTiposAnulacion() as $k => $v): ?>
              <option value="<?= $k ?>"><?= $k ?>. <?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="mt-1 text-xs text-slate-500">
            Códigos oficiales de la DGII. Se reporta en el Formato 608 solo si la venta tiene NCF.
          </p>
        </div>

        <p class="text-xs text-slate-500" x-show="!venta.ncf">
          Esta venta no tiene NCF, por lo que no se incluirá en el 608.
        </p>
      </div>

      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open = false" class="btn btn-ghost cursor-pointer">Cancelar</button>
        <button class="btn btn-danger cursor-pointer"><?= icon('x', 'w-4 h-4') ?> Anular venta</button>
      </div>
    </form>
  </div>
</div>

<?php layout_end(); ?>
