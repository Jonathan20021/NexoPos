<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('ventas.ver');

$id = (int) get('id');
$v = qOne("SELECT v.*, su.nombre AS sucursal, su.direccion AS suc_dir, su.telefono AS suc_tel, cl.nombre AS cliente, cl.rnc_cedula, u.nombre AS vendedor, u.apellido AS vend_ape
           FROM ventas v JOIN sucursales su ON su.id=v.sucursal_id LEFT JOIN clientes cl ON cl.id=v.cliente_id JOIN usuarios u ON u.id=v.usuario_id
           WHERE v.id=?", [$id]);
if (!$v) { http_response_code(404); die('Venta no encontrada'); }
require_sucursal_access($v['sucursal_id']);
$det = qAll("SELECT * FROM venta_detalles WHERE venta_id=?", [$id]);
$pagos = qAll("SELECT vp.*, m.nombre AS metodo FROM venta_pagos vp JOIN metodos_pago m ON m.id=vp.metodo_pago_id WHERE vp.venta_id=?", [$id]);
$emp = $GLOBALS['empresa'];
$autoPrint = get('print') === '1';

// ---- Factura PDF profesional (Dompdf) ----
if (get('pdf') === '1' && function_exists('pdf_render')) {
    $cliente = $v['cliente'] ?: 'Cliente Genérico';
    $h = pdf_brand_header('FACTURA', $v['numero']);
    $h .= '<table style="width:100%; margin-bottom:8px;"><tr>'
        . '<td style="vertical-align:top; width:55%;"><div class="box"><strong>Cliente:</strong> ' . htmlspecialchars($cliente)
        . (!empty($v['rnc_cedula']) ? '<br><strong>RNC/Cédula:</strong> ' . htmlspecialchars($v['rnc_cedula']) : '')
        . '<br><strong>Sucursal:</strong> ' . htmlspecialchars($v['sucursal']) . '</div></td>'
        . '<td style="vertical-align:top; padding-left:8px;"><div class="box"><strong>Factura:</strong> ' . htmlspecialchars($v['numero'])
        . ($v['ncf'] ? '<br><strong>NCF:</strong> ' . htmlspecialchars($v['ncf']) : '')
        . '<br><strong>Fecha:</strong> ' . fechaHora($v['fecha'])
        . '<br><strong>Atendió:</strong> ' . htmlspecialchars($v['vendedor'] . ' ' . $v['vend_ape']) . '</div></td></tr></table>';
    $h .= '<table class="tbl"><thead><tr><th>Producto</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">ITBIS</th><th class="num">Importe</th></tr></thead><tbody>';
    foreach ($det as $d) {
        $h .= '<tr><td>' . htmlspecialchars($d['descripcion']) . '</td><td class="num">' . qty($d['cantidad']) . '</td><td class="num">' . money($d['precio_unitario']) . '</td><td class="num">' . money($d['itbis']) . '</td><td class="num">' . money($d['subtotal']) . '</td></tr>';
    }
    $h .= '</tbody></table>';
    $h .= '<table style="width:48%; margin-left:52%; margin-top:12px;" class="totales">'
        . '<tr><td class="lbl">Subtotal</td><td class="val">' . money($v['subtotal']) . '</td></tr>'
        . ($v['descuento'] > 0 ? '<tr><td class="lbl">Descuento</td><td class="val">-' . money($v['descuento']) . '</td></tr>' : '')
        . '<tr><td class="lbl">ITBIS</td><td class="val">' . money($v['itbis']) . '</td></tr>'
        . '<tr><td class="lbl total-final">TOTAL</td><td class="val total-final">' . money($v['total']) . '</td></tr></table>';
    $h .= '<div style="margin-top:16px;">';
    foreach ($pagos as $p) $h .= '<span class="badge" style="background:#eff6ff;color:#2563eb;">' . htmlspecialchars($p['metodo']) . ': ' . money($p['monto']) . '</span> ';
    $h .= '</div>';
    $h .= '<p class="meta" style="text-align:center; margin-top:24px; font-size:11px; color:#374151;">' . htmlspecialchars($emp['mensaje_ticket'] ?? '¡Gracias por su compra!') . '</p>';
    pdf_render($h, 'factura_' . $v['numero'], 'portrait', 'inline');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ticket <?= e($v['numero']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body{font-family:'Inter',sans-serif}
  .ticket{width:320px}
  @media print{ .no-print{display:none!important} body{background:#fff} .ticket{width:100%;box-shadow:none;border:0} @page{margin:6mm} }
</style>
</head>
<body class="bg-slate-100 min-h-screen flex flex-col items-center py-8 px-4">

  <div class="no-print w-full max-w-[320px] flex gap-2 mb-4">
    <a href="<?= e(url('modules/pos/index.php')) ?>" class="btn btn-ghost flex-1 inline-flex items-center justify-center gap-2 bg-white border border-slate-200 rounded-xl py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">← Nueva venta</a>
    <a href="?id=<?= (int) $id ?>&pdf=1" target="_blank" class="flex-1 inline-flex items-center justify-center gap-2 bg-white border border-slate-200 rounded-xl py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">PDF</a>
    <button onclick="window.print()" class="flex-1 inline-flex items-center justify-center gap-2 bg-blue-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-blue-700">Imprimir</button>
  </div>

  <div class="ticket bg-white rounded-2xl shadow-card p-6 text-slate-800">
    <div class="text-center border-b border-dashed border-slate-300 pb-3 mb-3">
      <h1 class="text-lg font-extrabold"><?= e($emp['nombre'] ?? APP_NAME) ?></h1>
      <?php if (!empty($emp['rnc'])): ?><p class="text-xs text-slate-500">RNC: <?= e($emp['rnc']) ?></p><?php endif; ?>
      <p class="text-xs text-slate-500"><?= e($v['sucursal']) ?><?= $v['suc_dir'] ? ' · ' . e($v['suc_dir']) : '' ?></p>
      <?php if ($v['suc_tel']): ?><p class="text-xs text-slate-500">Tel: <?= e($v['suc_tel']) ?></p><?php endif; ?>
    </div>

    <div class="text-xs space-y-0.5 mb-3">
      <div class="flex justify-between"><span class="text-slate-500">Factura:</span><span class="font-semibold"><?= e($v['numero']) ?></span></div>
      <?php if ($v['ncf']): ?><div class="flex justify-between"><span class="text-slate-500">NCF:</span><span class="font-semibold"><?= e($v['ncf']) ?></span></div><?php endif; ?>
      <div class="flex justify-between"><span class="text-slate-500">Fecha:</span><span><?= fechaHora($v['fecha']) ?></span></div>
      <div class="flex justify-between"><span class="text-slate-500">Cliente:</span><span><?= e($v['cliente'] ?: 'Cliente Genérico') ?></span></div>
      <div class="flex justify-between"><span class="text-slate-500">Atendió:</span><span><?= e($v['vendedor'] . ' ' . $v['vend_ape']) ?></span></div>
    </div>

    <table class="w-full text-xs border-t border-dashed border-slate-300 pt-2">
      <thead><tr class="text-slate-400"><th class="text-left font-medium py-1">Producto</th><th class="text-center font-medium">Cant</th><th class="text-right font-medium">Importe</th></tr></thead>
      <tbody>
        <?php foreach ($det as $d): ?>
          <tr class="border-b border-slate-50">
            <td class="py-1"><?= e($d['descripcion']) ?><br><span class="text-slate-400"><?= money($d['precio_unitario']) ?></span></td>
            <td class="text-center align-top pt-1"><?= qty($d['cantidad']) ?></td>
            <td class="text-right align-top pt-1 font-medium"><?= money($d['subtotal'], false) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="text-xs space-y-1 mt-3 border-t border-dashed border-slate-300 pt-3">
      <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span><?= money($v['subtotal']) ?></span></div>
      <?php if ($v['descuento'] > 0): ?><div class="flex justify-between text-rose-600"><span>Descuento</span><span>−<?= money($v['descuento']) ?></span></div><?php endif; ?>
      <div class="flex justify-between"><span class="text-slate-500">ITBIS (<?= rtrim(rtrim(number_format((float)setting('itbis_tasa',18),2),'0'),'.') ?>%)</span><span><?= money($v['itbis']) ?></span></div>
      <div class="flex justify-between text-base font-extrabold pt-1 border-t border-slate-200 mt-1"><span>TOTAL</span><span><?= money($v['total']) ?></span></div>
    </div>

    <div class="text-xs mt-3 space-y-0.5">
      <?php foreach ($pagos as $p): ?>
        <div class="flex justify-between text-slate-500"><span><?= e($p['metodo']) ?></span><span><?= money($p['monto']) ?></span></div>
      <?php endforeach; ?>
    </div>

    <p class="text-center text-xs text-slate-500 mt-4 border-t border-dashed border-slate-300 pt-3"><?= e($emp['mensaje_ticket'] ?? '¡Gracias por su compra!') ?></p>
    <p class="text-center text-[10px] text-slate-300 mt-1"><?= e(APP_NAME) ?></p>
  </div>

  <?php if ($autoPrint): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script><?php endif; ?>
</body>
</html>
