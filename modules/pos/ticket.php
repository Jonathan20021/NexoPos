<?php
/**
 * Comprobante de una venta: ticket térmico (pantalla/impresora de 80 mm) y
 * factura profesional en PDF (A4).
 *
 * Ambos salen con la MARCA de la tienda con la que se facturó, no con la de la
 * empresa: el cliente compró en L'Occitane y ese es el logo que espera ver.
 * La marca se lee de `ventas.tienda_id`, que quedó congelado al cobrar — si se
 * dedujera del producto, reimprimir una factura vieja podría cambiarle el logo.
 *
 * El emisor fiscal sigue siendo la empresa: el RNC y el NCF son los suyos.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('ventas.ver');

$id = (int) get('id');
$v = qOne("SELECT v.*, su.nombre AS sucursal, su.direccion AS suc_dir, su.telefono AS suc_tel,
                  cl.nombre AS cliente, cl.rnc_cedula, cl.direccion AS cli_dir, cl.telefono AS cli_tel,
                  u.nombre AS vendedor, u.apellido AS vend_ape
           FROM ventas v JOIN sucursales su ON su.id=v.sucursal_id LEFT JOIN clientes cl ON cl.id=v.cliente_id JOIN usuarios u ON u.id=v.usuario_id
           WHERE v.id=?", [$id]);
if (!$v) { http_response_code(404); die('Venta no encontrada'); }
require_sucursal_access($v['sucursal_id']);

$det = qAll(
    "SELECT vd.*, p.codigo AS sku,
            COALESCE(NULLIF(vd.descripcion,''), p.nombre, '(producto no disponible)') AS descripcion
     FROM venta_detalles vd LEFT JOIN productos p ON p.id = vd.producto_id
     WHERE vd.venta_id = ?
     ORDER BY vd.id",
    [$id]
);
$pagos = qAll("SELECT vp.*, m.nombre AS metodo FROM venta_pagos vp JOIN metodos_pago m ON m.id=vp.metodo_pago_id WHERE vp.venta_id=?", [$id]);
$emp = $GLOBALS['empresa'];
$autoPrint = get('print') === '1';

// ---- Identidad impresa ----
$marca   = tienda_marca_de_venta($v);
$color   = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $marca['color']) ? $marca['color'] : marca_app();
$logoUrl = tienda_logo_url($marca);

// Comprobante Fiscal Electrónico. El rótulo cambia («e-NCF» en vez de «NCF»)
// porque son documentos distintos y el cliente tiene derecho a saber cuál
// recibió: el e-CF lo puede verificar él mismo ante la DGII.
$esElectronico = ecfENCFValido((string) $v['ncf']);
$ecfDoc = $esElectronico
    ? qOne("SELECT id, estado, track_id, qr_url FROM ecf_documentos WHERE origen='venta' AND origen_id=?", [$id])
    : null;
$rotuloNcf = $esElectronico ? 'e-NCF' : 'NCF';

// Código QR de la Representación Impresa.
//
// Lo genera el proveedor —lleva el código de seguridad derivado de la firma
// digital— y aquí solo se pinta. La primera vez se descarga y se guarda; a
// partir de ahí sale de la base. Si todavía no está (el comprobante aún no se
// acepta, o el proveedor no contesta), devuelve null y el ticket se imprime
// igual: un fallo de red nunca puede dejar a un cliente sin su comprobante.
$ecfQr = $ecfDoc ? ecfQrDeVenta($id) : null;

/* ---------------------------------------------------------------------------
 *  Desglose de impuestos
 *
 *  La ley pide poder ver sobre qué base se cobró el ITBIS. Se separa en gravado
 *  y exento leyendo las líneas: una línea con `itbis = 0` es exenta (o una
 *  muestra). El descuento del encabezado se reparte proporcionalmente, que es
 *  como se calculó el ITBIS al vender — si no, la base impresa no cuadraría con
 *  el impuesto cobrado.
 * ------------------------------------------------------------------------- */
$factorDesc = (float) $v['subtotal'] > 0 ? ((float) $v['subtotal'] - (float) $v['descuento']) / (float) $v['subtotal'] : 1.0;
$baseGravada = 0.0; $baseExenta = 0.0;
foreach ($det as $d) {
    if ((float) $d['itbis'] > 0) $baseGravada += (float) $d['subtotal'];
    else                         $baseExenta  += (float) $d['subtotal'];
}
$baseGravada = round($baseGravada * $factorDesc, 2);
$baseExenta  = round($baseExenta * $factorDesc, 2);
$tasaItbis   = rtrim(rtrim(number_format((float) setting('itbis_tasa', 18), 2), '0'), '.');

// Código de barras del número de factura: es lo que lee la cajera cuando el
// cliente vuelve a hacer un cambio, en vez de teclearlo.
$barrasFactura = barcode_svg($v['numero'], ['alto' => 34, 'modulo' => 1.15, 'texto' => false]);

// ---- Factura PDF profesional (Dompdf) ----
if (get('pdf') === '1' && function_exists('pdf_render')) {
    $cliente = $v['cliente'] ?: 'Cliente Genérico';
    $esc = fn($s) => htmlspecialchars((string) $s);

    // El timbre de la DGII lleva el código de seguridad en la URL; es lo que
    // permite cotejar el comprobante sin escanear nada.
    $codSeguridad = '';
    if (!empty($ecfDoc['qr_url']) && preg_match('/CodigoSeguridad=([^&]+)/', (string) $ecfDoc['qr_url'], $m)) {
        $codSeguridad = urldecode($m[1]);
    }

    $h = pdf_brand_header('FACTURA', $v['numero'], $marca);

    // Receptor y datos del comprobante, uno al lado del otro. El e-NCF va
    // destacado y no perdido en una lista: es el dato por el que se busca esta
    // factura, tanto en la DGII como en la contabilidad del cliente.
    $h .= '<table style="width:100%; margin-bottom:14px; border-spacing:0;"><tr>'
        . '<td class="box box-acento" style="border-left-color:' . $color . '; width:50%;">'
        . '<div class="box-tit">Facturado a</div>'
        . '<div class="nombre-fuerte">' . $esc($cliente) . '</div>'
        . '<div class="dato">'
        . (!empty($v['rnc_cedula']) ? 'RNC/Cédula ' . $esc($v['rnc_cedula']) . '<br>' : '')
        . (!empty($v['cli_dir']) ? $esc($v['cli_dir']) . '<br>' : '')
        . (!empty($v['cli_tel']) ? $esc($v['cli_tel']) : '')
        . '</div></td>'
        . '<td style="width:3%; border:0;"></td>'
        . '<td class="box" style="width:47%;">'
        . '<div class="box-tit">' . ($v['ncf'] ? $rotuloNcf : 'Comprobante') . '</div>'
        . ($v['ncf'] ? '<div class="qr-encf">' . $esc($v['ncf']) . '</div>' : '<div class="nombre-fuerte">' . $esc($v['numero']) . '</div>')
        . '<table style="width:100%; margin-top:6px;">'
        . '<tr><td class="dato" style="color:#8A93A5;">Fecha</td><td class="dato num"><strong>' . fechaHora($v['fecha']) . '</strong></td></tr>'
        . '<tr><td class="dato" style="color:#8A93A5;">Sucursal</td><td class="dato num"><strong>' . $esc($v['sucursal']) . '</strong></td></tr>'
        . '<tr><td class="dato" style="color:#8A93A5;">Atendió</td><td class="dato num"><strong>' . $esc($v['vendedor'] . ' ' . $v['vend_ape']) . '</strong></td></tr>'
        . '</table></td></tr></table>';

    // El código va bajo la descripción y no en su propia columna: gana ancho
    // para el nombre del producto, que es lo que el cliente lee.
    $h .= '<table class="tbl"><thead><tr>'
        . '<th style="background:' . $color . '; width:43%;">Descripción</th>'
        . '<th style="background:' . $color . '; width:9%;" class="num">Cant.</th>'
        . '<th style="background:' . $color . '; width:16%;" class="num">Precio</th>'
        . '<th style="background:' . $color . '; width:14%;" class="num">ITBIS</th>'
        . '<th style="background:' . $color . '; width:18%;" class="num">Importe</th>'
        . '</tr></thead><tbody>';
    foreach ($det as $d) {
        $etq = !empty($d['es_muestra'])
            ? ' <span style="color:#B45309; font-weight:bold; font-size:8.5px;">[MUESTRA]</span>' : '';
        $h .= '<tr><td><strong>' . $esc($d['descripcion']) . '</strong>' . $etq
            . ($d['sku'] ? '<br><span class="sku">' . $esc($d['sku']) . '</span>' : '') . '</td>'
            . '<td class="num">' . qty($d['cantidad']) . '</td>'
            . '<td class="num">' . money($d['precio_unitario'], false) . '</td>'
            . '<td class="num">' . money($d['itbis'], false) . '</td>'
            . '<td class="num"><strong>' . money($d['subtotal'], false) . '</strong></td></tr>';
    }
    $h .= '</tbody></table>';

    /* -----------------------------------------------------------------------
     *  Bloque de cierre: a la izquierda lo fiscal (QR, desglose y pago), a la
     *  derecha los totales. El QR pasa de flotar suelto en medio de la hoja a
     *  ir junto al timbre que representa, a un tamaño que se escanea igual.
     * -------------------------------------------------------------------- */
    $izq = '';
    if ($ecfQr) {
        $izq .= '<div class="qr-caja" style="margin-bottom:8px;"><table style="width:100%"><tr>'
            . '<td width="90"><img src="' . $ecfQr . '" alt="Código QR"></td>'
            . '<td style="vertical-align:middle; padding-left:4px;">'
            . '<div class="box-tit" style="margin-bottom:3px;">Comprobante fiscal electrónico</div>'
            . ($codSeguridad ? '<div class="qr-encf">Código de seguridad ' . $esc($codSeguridad) . '</div>' : '')
            . '<div class="qr-nota" style="margin-top:3px;">Escanee el código para verificar<br>este documento ante la DGII.</div>'
            . '</td></tr></table></div>';
    }
    // Forma de pago: una fila legible, no una etiqueta suelta al margen.
    if ($pagos) {
        $izq .= '<div class="box"><div class="box-tit">Forma de pago</div><table style="width:100%;">';
        foreach ($pagos as $p) {
            $izq .= '<tr><td class="dato"><strong>' . $esc($p['metodo']) . '</strong></td>'
                 . '<td class="dato num">' . money($p['monto']) . '</td></tr>';
        }
        $izq .= '</table></div>';
    }

    $der = '<table class="tot">'
        . '<tr><td class="lbl">Subtotal</td><td class="val">' . money($v['subtotal']) . '</td></tr>'
        . ($v['descuento'] > 0 ? '<tr><td class="lbl">Descuento</td><td class="val" style="color:#BE123C;">−' . money($v['descuento']) . '</td></tr>' : '')
        . '<tr><td class="lbl">ITBIS (' . $tasaItbis . '%)</td><td class="val">' . money($v['itbis']) . '</td></tr>'
        . '</table>'
        . '<div class="total-bloque" style="background:' . $color . ';"><table style="width:100%"><tr>'
        . '<td class="lbl">TOTAL A PAGAR</td><td class="val">' . money($v['total']) . '</td>'
        . '</tr></table></div>'
        . '<div class="box" style="margin-top:8px;"><div class="box-tit">Desglose de impuestos</div>'
        . '<table style="width:100%;">'
        . '<tr><td class="dato" style="color:#8A93A5;">Base gravada (' . $tasaItbis . '%)</td><td class="dato num">' . money($baseGravada, false) . '</td></tr>'
        . ($baseExenta > 0 ? '<tr><td class="dato" style="color:#8A93A5;">Base exenta</td><td class="dato num">' . money($baseExenta, false) . '</td></tr>' : '')
        . '<tr><td class="dato" style="color:#8A93A5;">ITBIS</td><td class="dato num">' . money($v['itbis'], false) . '</td></tr>'
        . '</table></div>';

    $h .= '<table style="width:100%; margin-top:14px;"><tr>'
        . '<td style="width:56%; vertical-align:top;">' . $izq . '</td>'
        . '<td style="width:44%; vertical-align:top; padding-left:10px;">' . $der . '</td>'
        . '</tr></table>';

    if (!empty($marca['politica'])) {
        $h .= '<div class="box" style="margin-top:12px;"><div class="box-tit">Política de devoluciones</div>'
            . '<div class="qr-nota">' . nl2br($esc($marca['politica'])) . '</div></div>';
    }

    $h .= '<p style="text-align:center; margin-top:16px; font-size:11px; color:#4B5563;">' . $esc($marca['mensaje']) . '</p>';

    // El emisor legal cierra la página. La marca es comercial; la factura la
    // emite la empresa y eso tiene que estar escrito en el papel.
    $h .= pdf_pie('Emitido por ' . $esc($emp['nombre'] ?? APP_NAME)
        . (!empty($emp['rnc']) ? '  ·  RNC ' . $esc($emp['rnc']) : '')
        . (!empty($marca['pie']) ? '<br>' . $esc($marca['pie']) : ''));

    pdf_render($h, 'factura_' . $v['numero'], 'portrait', 'inline');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Factura <?= e($v['numero']) ?> · <?= e($marca['nombre']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body{font-family:'Inter',sans-serif}
  .ticket{width:320px}
  .tk-linea{border-top:1px dashed #cbd5e1}
  @media print{
    .no-print{display:none!important}
    body{background:#fff;padding:0;margin:0}
    .ticket{width:100%;box-shadow:none;border:0;border-radius:0;padding:0}
    @page{margin:4mm}
  }
</style>
</head>
<body class="bg-slate-100 min-h-screen flex flex-col items-center py-8 px-4">

  <div class="no-print w-full max-w-[320px] flex gap-2 mb-4">
    <a href="<?= e(url('modules/pos/index.php')) ?>" class="flex-1 inline-flex items-center justify-center gap-2 bg-white border border-slate-200 rounded-xl py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">← Nueva venta</a>
    <a href="?id=<?= (int) $id ?>&pdf=1" target="_blank" class="flex-1 inline-flex items-center justify-center gap-2 bg-white border border-slate-200 rounded-xl py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Factura PDF</a>
    <button onclick="window.print()" class="flex-1 inline-flex items-center justify-center gap-2 text-white rounded-xl py-2.5 text-sm font-semibold hover:opacity-90" style="background: <?= e($color) ?>">Imprimir</button>
  </div>

  <div class="ticket bg-white rounded-2xl shadow-card p-6 text-slate-800">

    <!-- Encabezado de la marca -->
    <div class="text-center pb-3 mb-3 tk-linea border-t-0 border-b border-dashed border-slate-300">
      <?php if ($logoUrl): ?>
        <img src="<?= e($logoUrl) ?>" alt="<?= e($marca['nombre']) ?>" class="mx-auto max-h-16 max-w-[180px] object-contain mb-2">
      <?php else: ?>
        <div class="w-14 h-14 mx-auto rounded-xl text-white text-xl font-extrabold flex items-center justify-center mb-2"
             style="background: <?= e($color) ?>"><?= e(tienda_iniciales($marca['nombre'])) ?></div>
      <?php endif; ?>
      <h1 class="text-lg font-extrabold leading-tight"><?= e($marca['nombre']) ?></h1>
      <?php if ($marca['encabezado']): ?><p class="text-xs text-slate-500 mt-0.5"><?= e($marca['encabezado']) ?></p><?php endif; ?>
      <?php if ($marca['direccion']): ?>
        <p class="text-xs text-slate-500"><?= e($marca['direccion']) ?><?= $marca['ciudad'] ? ', ' . e($marca['ciudad']) : '' ?></p>
      <?php endif; ?>
      <?php if ($marca['telefono']): ?><p class="text-xs text-slate-500">Tel: <?= e($marca['telefono']) ?></p><?php endif; ?>
      <?php if (!empty($marca['rnc'])): ?><p class="text-xs text-slate-500">RNC: <?= e($marca['rnc']) ?></p><?php endif; ?>
    </div>

    <!-- Datos del comprobante -->
    <div class="text-xs space-y-0.5 mb-3">
      <div class="flex justify-between gap-2"><span class="text-slate-500">Factura</span><span class="font-semibold"><?= e($v['numero']) ?></span></div>
      <?php if ($v['ncf']): ?><div class="flex justify-between gap-2"><span class="text-slate-500"><?= $rotuloNcf ?></span><span class="font-semibold"><?= e($v['ncf']) ?></span></div><?php endif; ?>
      <?php if ($esElectronico && $ecfDoc && $ecfDoc['estado'] !== 'aceptado'): ?>
        <div class="flex justify-between gap-2"><span class="text-slate-500">Transmisión</span><span class="text-amber-600">pendiente</span></div>
      <?php endif; ?>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Fecha</span><span><?= fechaHora($v['fecha']) ?></span></div>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Cliente</span><span class="text-right"><?= e($v['cliente'] ?: 'Cliente Genérico') ?></span></div>
      <?php if (!empty($v['rnc_cedula'])): ?>
        <div class="flex justify-between gap-2"><span class="text-slate-500">RNC/Cédula</span><span><?= e($v['rnc_cedula']) ?></span></div>
      <?php endif; ?>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Atendió</span><span class="text-right"><?= e($v['vendedor'] . ' ' . $v['vend_ape']) ?></span></div>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Sucursal</span><span class="text-right"><?= e($v['sucursal']) ?></span></div>
    </div>

    <!-- Líneas -->
    <table class="w-full text-xs tk-linea pt-2">
      <thead><tr class="text-slate-400">
        <th class="text-left font-medium py-1">Descripción</th>
        <th class="text-center font-medium">Cant</th>
        <th class="text-right font-medium">Importe</th>
      </tr></thead>
      <tbody>
        <?php foreach ($det as $d): ?>
          <tr class="border-b border-slate-50 align-top">
            <td class="py-1">
              <span class="font-medium"><?= e($d['descripcion']) ?></span>
              <?php if (!empty($d['es_muestra'])): ?>
                <span class="text-amber-600 font-bold"> [MUESTRA]</span>
              <?php endif; ?>
              <br>
              <span class="text-slate-400 text-[11px]">
                <?php if (!empty($d['sku'])): ?><span class="font-mono"><?= e($d['sku']) ?></span> · <?php endif; ?><?= money($d['precio_unitario']) ?>
              </span>
            </td>
            <td class="text-center pt-1"><?= qty($d['cantidad']) ?></td>
            <td class="text-right pt-1 font-medium"><?= money($d['subtotal'], false) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Totales -->
    <div class="text-xs space-y-1 mt-3 tk-linea pt-3">
      <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span><?= money($v['subtotal']) ?></span></div>
      <?php if ($v['descuento'] > 0): ?><div class="flex justify-between text-rose-600"><span>Descuento</span><span>−<?= money($v['descuento']) ?></span></div><?php endif; ?>
      <div class="flex justify-between"><span class="text-slate-500">ITBIS (<?= $tasaItbis ?>%)</span><span><?= money($v['itbis']) ?></span></div>
      <div class="flex justify-between text-base font-extrabold pt-1 border-t border-slate-200 mt-1">
        <span>TOTAL</span><span style="color: <?= e($color) ?>"><?= money($v['total']) ?></span>
      </div>
    </div>

    <!-- Pagos -->
    <div class="text-xs mt-3 space-y-0.5">
      <?php foreach ($pagos as $p): ?>
        <div class="flex justify-between text-slate-500"><span><?= e($p['metodo']) ?></span><span><?= money($p['monto']) ?></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Desglose de impuestos -->
    <div class="mt-3 tk-linea pt-2">
      <p class="text-[10px] uppercase tracking-wide font-bold text-slate-400 mb-1">Desglose de impuestos</p>
      <table class="w-full text-[11px]">
        <thead><tr class="text-slate-400">
          <th class="text-left font-medium">Concepto</th>
          <th class="text-center font-medium">Tasa</th>
          <th class="text-right font-medium">Base</th>
          <th class="text-right font-medium">ITBIS</th>
        </tr></thead>
        <tbody>
          <tr><td>Gravado</td><td class="text-center"><?= $tasaItbis ?>%</td>
              <td class="text-right"><?= money($baseGravada, false) ?></td>
              <td class="text-right"><?= money($v['itbis'], false) ?></td></tr>
          <?php if ($baseExenta > 0): ?>
            <tr><td>Exento</td><td class="text-center">0%</td>
                <td class="text-right"><?= money($baseExenta, false) ?></td>
                <td class="text-right">0.00</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Comprobante fiscal electrónico -->
    <?php if ($ecfQr): ?>
      <div class="text-center mt-4 tk-linea pt-3">
        <img src="<?= e($ecfQr) ?>" alt="Código QR del comprobante fiscal electrónico"
             class="mx-auto w-28 h-28" style="image-rendering: pixelated;">
        <p class="text-[10px] text-slate-500 mt-1.5 leading-tight">
          Comprobante Fiscal Electrónico<br>Verifique este documento ante la DGII
        </p>
      </div>
    <?php endif; ?>

    <!-- Código de barras del número de factura -->
    <?php if ($barrasFactura !== ''): ?>
      <div class="text-center mt-4 tk-linea pt-3">
        <div class="flex justify-center"><?= $barrasFactura ?></div>
        <p class="text-[11px] font-mono tracking-widest text-slate-600 mt-0.5"><?= e($v['numero']) ?></p>
      </div>
    <?php endif; ?>

    <!-- Política de devoluciones -->
    <?php if (!empty($marca['politica'])): ?>
      <div class="mt-4 tk-linea pt-3">
        <p class="text-[10px] uppercase tracking-wide font-bold text-slate-400 mb-1">Devoluciones y cambios</p>
        <p class="text-[11px] text-slate-500 leading-snug whitespace-pre-line"><?= e($marca['politica']) ?></p>
      </div>
    <?php endif; ?>

    <p class="text-center text-xs text-slate-600 font-medium mt-4 tk-linea pt-3"><?= e($marca['mensaje']) ?></p>
    <?php if ($marca['sitio_web']): ?>
      <p class="text-center text-[11px] text-slate-400 mt-0.5"><?= e($marca['sitio_web']) ?></p>
    <?php endif; ?>
    <p class="text-center text-[10px] text-slate-400 mt-2 leading-tight">
      Emitido por <?= e($emp['nombre'] ?? APP_NAME) ?><?= !empty($emp['rnc']) ? ' · RNC ' . e($emp['rnc']) : '' ?>
      <?php if (!empty($marca['pie'])): ?><br><?= e($marca['pie']) ?><?php endif; ?>
    </p>
  </div>

  <?php if ($autoPrint): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script><?php endif; ?>
</body>
</html>
