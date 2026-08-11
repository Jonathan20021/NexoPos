<?php
/**
 * Comprobante de una devolución: nota de crédito en formato térmico (80 mm) y
 * en PDF (A4). Es el gemelo de `ticket.php`, y por las mismas razones.
 *
 * Existe porque una nota de crédito NO es un recibo de caja: es un comprobante
 * fiscal por derecho propio. El cliente se lleva un documento con su e-NCF, con
 * el e-NCF de la factura que corrige y con el QR de la DGII, igual que se llevó
 * la factura. Sin este papel, la nota quedaba solo en la base de datos.
 *
 * La MARCA es la de la venta original, no la de la empresa ni la del día de
 * hoy: el cliente compró en L'Occitane y la nota que corrige esa compra tiene
 * que verse como aquella factura. Se lee de `ventas.tienda_id`, congelado al
 * cobrar. El emisor fiscal sigue siendo la empresa, con su RNC.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('devoluciones.ver');

$id = (int) get('id');
$d = qOne(
    "SELECT dv.*, su.nombre AS sucursal, su.direccion AS suc_dir, su.telefono AS suc_tel,
            u.nombre AS usuario, u.apellido AS usuario_ape
       FROM devoluciones dv
       JOIN sucursales su ON su.id = dv.sucursal_id
       LEFT JOIN usuarios u ON u.id = dv.usuario_id
      WHERE dv.id = ?",
    [$id]
);
if (!$d) { http_response_code(404); die('Devolución no encontrada'); }
require_sucursal_access($d['sucursal_id']);

// La venta original: de ahí salen la marca, el cliente y el comprobante que se
// corrige. Se busca por id para no depender de que el NCF siga escrito igual.
$v = qOne(
    "SELECT v.*, cl.nombre AS cliente, cl.rnc_cedula, cl.direccion AS cli_dir, cl.telefono AS cli_tel
       FROM ventas v LEFT JOIN clientes cl ON cl.id = v.cliente_id
      WHERE v.id = ?",
    [(int) $d['venta_id']]
);

$det = qAll(
    "SELECT dd.*, p.codigo AS sku
       FROM devolucion_detalles dd LEFT JOIN productos p ON p.id = dd.producto_id
      WHERE dd.devolucion_id = ?
      ORDER BY dd.id",
    [$id]
);
$emp = $GLOBALS['empresa'];
$autoPrint = get('print') === '1';

// ---- Identidad impresa ----
$marca   = tienda_marca_de_venta($v ?: []);
$color   = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $marca['color']) ? $marca['color'] : marca_app();
$logoUrl = tienda_logo_url($marca);

// ---- Comprobante fiscal ----
$esElectronico = ecfENCFValido((string) $d['ncf']);
$ecfDoc = $esElectronico
    ? qOne("SELECT id, estado, track_id FROM ecf_documentos WHERE origen='devolucion' AND origen_id=?", [$id])
    : null;
$rotuloNcf = $esElectronico ? 'e-NCF' : 'NCF';

// Lo declarado a la DGII manda sobre lo que se pueda recalcular hoy. Ver
// ecfDeclaradoDeDevolucion() para el porqué de cada campo.
$declarado  = $esElectronico ? ecfDeclaradoDeDevolucion($id) : null;
$codModif   = isset($declarado['INFR']['CodigoModificacion']) && $declarado['INFR']['CodigoModificacion'] !== ''
    ? (int) $declarado['INFR']['CodigoModificacion'] : null;
$textoModif = $codModif !== null ? ecfTextoModificacion($codModif) : null;

$ecfQr = $ecfDoc ? ecfQrDeDevolucion($id) : null;

/* ---------------------------------------------------------------------------
 *  Líneas a imprimir
 *
 *  Con e-CF se imprimen las de la trama: sus importes son la BASE sin ITBIS,
 *  que es lo que se declaró y lo que cuadra con el desglose de abajo. Sin e-CF
 *  (nota en papel) se usan las de la base de datos, cuyo subtotal trae el ITBIS
 *  dentro porque es el importe que salió de la caja — y entonces la columna se
 *  rotula como reembolso, no como base.
 * ------------------------------------------------------------------------- */
$lineasDeclaradas = !empty($declarado['ITEM']) ? $declarado['ITEM'] : null;
if ($lineasDeclaradas) {
    $skuPorNombre = [];
    foreach ($det as $l) $skuPorNombre[$l['descripcion']] = $l['sku'];

    $lineas = array_map(fn($it) => [
        'descripcion' => $it['NombreItem'],
        'sku'         => $skuPorNombre[$it['NombreItem']] ?? null,
        'cantidad'    => (float) $it['CantidadItem'],
        'precio'      => (float) $it['PrecioUnitarioItem'],
        'importe'     => (float) $it['MontoItem'],
    ], $lineasDeclaradas);
    $rotuloImporte = 'Base';
} else {
    $lineas = array_map(fn($l) => [
        'descripcion' => $l['descripcion'],
        'sku'         => $l['sku'],
        'cantidad'    => (float) $l['cantidad'],
        'precio'      => (float) $l['precio_unitario'],
        'importe'     => (float) $l['subtotal'],
    ], $det);
    // «Reembolso» y no «Importe»: en esta rama la cifra lleva el ITBIS dentro y
    // suma hacia el total, no hacia la base. Nombrarla evita que alguien la lea
    // como base y crea que el papel no cuadra.
    $rotuloImporte = 'Reembolso';
}

// ---- Desglose de impuestos ----
// La devolución ya guarda base e ITBIS separados (se calcularon al reembolsar,
// con el descuento de la venta ya repartido), así que aquí no se recalcula
// nada: se imprime lo que se acreditó.
$tasaItbis = rtrim(rtrim(number_format((float) setting('itbis_tasa', 18), 2), '0'), '.');

$barrasNota = barcode_svg($d['numero'], ['alto' => 34, 'modulo' => 1.15, 'texto' => false]);

/* ---------------------------------------------------------------------------
 *  Nota de crédito en PDF (A4)
 * ------------------------------------------------------------------------- */
if (get('pdf') === '1' && function_exists('pdf_render')) {
    $cliente = ($v['cliente'] ?? '') ?: 'Cliente Genérico';
    $esc = fn($s) => htmlspecialchars((string) $s);

    // El timbre lleva el código de seguridad en la URL; se pinta junto al QR.
    $codSeguridad = '';
    if (!empty($doc0 = qOne("SELECT qr_url FROM ecf_documentos WHERE origen='devolucion' AND origen_id=?", [$id]))
        && preg_match('/CodigoSeguridad=([^&]+)/', (string) $doc0['qr_url'], $m)) {
        $codSeguridad = urldecode($m[1]);
    }

    $h = pdf_brand_header('NOTA DE CRÉDITO', $d['numero'], $marca);

    // Receptor y comprobante, en celdas de una misma fila para que midan igual.
    $h .= '<table style="width:100%; margin-bottom:12px; border-spacing:0;"><tr>'
        . '<td class="box box-acento" style="border-left-color:' . $color . '; width:50%;">'
        . '<div class="box-tit">Acreditado a</div>'
        . '<div class="nombre-fuerte">' . $esc($cliente) . '</div>'
        . '<div class="dato">'
        . (!empty($v['rnc_cedula']) ? 'RNC/Cédula ' . $esc($v['rnc_cedula']) . '<br>' : '')
        . (!empty($v['cli_dir']) ? $esc($v['cli_dir']) . '<br>' : '')
        . (!empty($v['cli_tel']) ? $esc($v['cli_tel']) : '')
        . '</div></td>'
        . '<td style="width:3%; border:0;"></td>'
        . '<td class="box" style="width:47%;">'
        . '<div class="box-tit">' . ($d['ncf'] ? $rotuloNcf : 'Comprobante') . '</div>'
        . ($d['ncf'] ? '<div class="qr-encf">' . $esc($d['ncf']) . '</div>' : '<div class="nombre-fuerte">' . $esc($d['numero']) . '</div>')
        . '<table style="width:100%; margin-top:6px;">'
        . '<tr><td class="dato" style="color:#8A93A5;">Fecha</td><td class="dato num"><strong>' . fechaHora($d['created_at']) . '</strong></td></tr>'
        . '<tr><td class="dato" style="color:#8A93A5;">Sucursal</td><td class="dato num"><strong>' . $esc($d['sucursal']) . '</strong></td></tr>'
        . '<tr><td class="dato" style="color:#8A93A5;">Registró</td><td class="dato num"><strong>' . $esc(trim($d['usuario'] . ' ' . $d['usuario_ape']) ?: '—') . '</strong></td></tr>'
        . '</table></td></tr></table>';

    // El bloque que da sentido al documento: qué comprobante corrige y por qué.
    // Sin esto, una nota de crédito es un papel con un monto y nada más.
    $h .= '<div class="box box-acento" style="border-left-color:' . $color . '; margin-bottom:4px;">'
        . '<div class="box-tit">Modifica el comprobante</div>'
        . '<table style="width:100%;"><tr>'
        . '<td><span class="qr-encf" style="font-size:11px;">' . $esc($d['ncf_modificado'] ?: ($v['ncf'] ?? '—')) . '</span>'
        . '<span class="dato" style="color:#8A93A5;">  ·  factura ' . $esc($v['numero'] ?? '—') . '</span></td>'
        . '<td class="dato num">' . ($textoModif ? '<strong>' . $esc($textoModif) . '</strong> <span style="color:#8A93A5;">(código ' . (int) $codModif . ')</span>' : '') . '</td>'
        . '</tr></table>'
        . ($d['motivo'] ? '<div class="qr-nota" style="margin-top:4px;">' . $esc($d['motivo']) . '</div>' : '')
        . '</div>';

    $h .= '<table class="tbl"><thead><tr>'
        . '<th style="background:' . $color . '; width:52%;">Descripción</th>'
        . '<th style="background:' . $color . '; width:10%;" class="num">Cant.</th>'
        . '<th style="background:' . $color . '; width:18%;" class="num">Precio</th>'
        . '<th style="background:' . $color . '; width:20%;" class="num">' . $rotuloImporte . '</th>'
        . '</tr></thead><tbody>';
    foreach ($lineas as $l) {
        $h .= '<tr><td><strong>' . $esc($l['descripcion']) . '</strong>'
            . ($l['sku'] ? '<br><span class="sku">' . $esc($l['sku']) . '</span>' : '') . '</td>'
            . '<td class="num">' . qty($l['cantidad']) . '</td>'
            . '<td class="num">' . money($l['precio'], false) . '</td>'
            . '<td class="num"><strong>' . money($l['importe'], false) . '</strong></td></tr>';
    }
    $h .= '</tbody></table>';

    $izq = '';
    if ($ecfQr) {
        $izq .= '<div class="qr-caja"><table style="width:100%"><tr>'
            . '<td width="90"><img src="' . $ecfQr . '" alt="Código QR"></td>'
            . '<td style="vertical-align:middle; padding-left:4px;">'
            . '<div class="box-tit" style="margin-bottom:3px;">Comprobante fiscal electrónico</div>'
            . ($codSeguridad ? '<div class="qr-encf">Código de seguridad ' . $esc($codSeguridad) . '</div>' : '')
            . '<div class="qr-nota" style="margin-top:3px;">Escanee el código para verificar<br>este documento ante la DGII.</div>'
            . '</td></tr></table></div>';
    }

    $der = '<table class="tot">'
        . '<tr><td class="lbl">Base acreditada</td><td class="val">' . money($d['subtotal']) . '</td></tr>'
        . '<tr><td class="lbl">ITBIS (' . $tasaItbis . '%)</td><td class="val">' . money($d['itbis']) . '</td></tr>'
        . '</table>'
        . '<div class="total-bloque" style="background:' . $color . ';"><table style="width:100%"><tr>'
        . '<td class="lbl">TOTAL ACREDITADO</td><td class="val">' . money($d['total']) . '</td>'
        . '</tr></table></div>';

    $h .= '<table style="width:100%; margin-top:14px;"><tr>'
        . '<td style="width:56%; vertical-align:top;">' . $izq . '</td>'
        . '<td style="width:44%; vertical-align:top; padding-left:10px;">' . $der . '</td>'
        . '</tr></table>';

    $h .= pdf_pie('Emitido por ' . $esc($emp['nombre'] ?? APP_NAME)
        . (!empty($emp['rnc']) ? '  ·  RNC ' . $esc($emp['rnc']) : '')
        . (!empty($marca['pie']) ? '<br>' . $esc($marca['pie']) : ''));

    pdf_render($h, 'nota_credito_' . $d['numero'], 'portrait', 'inline');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nota de crédito <?= e($d['numero']) ?> · <?= e($marca['nombre']) ?></title>
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
    <a href="<?= e(url('modules/pos/devoluciones.php')) ?>" class="flex-1 inline-flex items-center justify-center gap-2 bg-white border border-slate-200 rounded-xl py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">← Devoluciones</a>
    <a href="?id=<?= (int) $id ?>&pdf=1" target="_blank" class="flex-1 inline-flex items-center justify-center gap-2 bg-white border border-slate-200 rounded-xl py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Nota PDF</a>
    <button onclick="window.print()" class="flex-1 inline-flex items-center justify-center gap-2 text-white rounded-xl py-2.5 text-sm font-semibold hover:opacity-90" style="background: <?= e($color) ?>">Imprimir</button>
  </div>

  <div class="ticket bg-white rounded-2xl shadow-card p-6 text-slate-800">

    <!-- Encabezado de la marca -->
    <div class="text-center pb-3 mb-3 border-b border-dashed border-slate-300">
      <?php if ($logoUrl): ?>
        <img src="<?= e($logoUrl) ?>" alt="<?= e($marca['nombre']) ?>" class="mx-auto max-h-16 max-w-[180px] object-contain mb-2">
      <?php else: ?>
        <div class="w-14 h-14 mx-auto rounded-xl text-white text-xl font-extrabold flex items-center justify-center mb-2"
             style="background: <?= e($color) ?>"><?= e(tienda_iniciales($marca['nombre'])) ?></div>
      <?php endif; ?>
      <h1 class="text-lg font-extrabold leading-tight"><?= e($marca['nombre']) ?></h1>
      <?php if ($marca['direccion']): ?>
        <p class="text-xs text-slate-500"><?= e($marca['direccion']) ?><?= $marca['ciudad'] ? ', ' . e($marca['ciudad']) : '' ?></p>
      <?php endif; ?>
      <?php if ($marca['telefono']): ?><p class="text-xs text-slate-500">Tel: <?= e($marca['telefono']) ?></p><?php endif; ?>
      <?php if (!empty($marca['rnc'])): ?><p class="text-xs text-slate-500">RNC: <?= e($marca['rnc']) ?></p><?php endif; ?>
    </div>

    <!-- Qué documento es este. Va destacado a propósito: quien lo recibe tiene
         que ver de un golpe que NO es una factura. -->
    <div class="text-center mb-3 py-2 rounded-xl" style="background: <?= e($color) ?>14; color: <?= e($color) ?>">
      <p class="text-sm font-extrabold uppercase tracking-wide">Nota de crédito</p>
      <p class="text-[11px] opacity-80">Documento de acreditación · no es una factura</p>
    </div>

    <!-- Datos del comprobante -->
    <div class="text-xs space-y-0.5 mb-3">
      <div class="flex justify-between gap-2"><span class="text-slate-500">Nota</span><span class="font-semibold"><?= e($d['numero']) ?></span></div>
      <?php if ($d['ncf']): ?><div class="flex justify-between gap-2"><span class="text-slate-500"><?= $rotuloNcf ?></span><span class="font-semibold"><?= e($d['ncf']) ?></span></div><?php endif; ?>
      <?php if ($esElectronico && $ecfDoc && $ecfDoc['estado'] !== 'aceptado'): ?>
        <div class="flex justify-between gap-2"><span class="text-slate-500">Transmisión</span><span class="text-amber-600">pendiente</span></div>
      <?php endif; ?>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Fecha</span><span><?= fechaHora($d['created_at']) ?></span></div>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Cliente</span><span class="text-right"><?= e(($v['cliente'] ?? '') ?: 'Cliente Genérico') ?></span></div>
      <?php if (!empty($v['rnc_cedula'])): ?>
        <div class="flex justify-between gap-2"><span class="text-slate-500">RNC/Cédula</span><span><?= e($v['rnc_cedula']) ?></span></div>
      <?php endif; ?>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Registró</span><span class="text-right"><?= e(trim($d['usuario'] . ' ' . $d['usuario_ape']) ?: '—') ?></span></div>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Sucursal</span><span class="text-right"><?= e($d['sucursal']) ?></span></div>
    </div>

    <!-- Comprobante que corrige -->
    <div class="text-xs mb-3 tk-linea pt-2">
      <p class="text-[10px] uppercase tracking-wide font-bold text-slate-400 mb-1">Modifica el comprobante</p>
      <div class="flex justify-between gap-2">
        <span class="text-slate-500"><?= $esElectronico ? 'e-NCF' : 'NCF' ?> modificado</span>
        <span class="font-semibold font-mono"><?= e($d['ncf_modificado'] ?: ($v['ncf'] ?? '—')) ?></span>
      </div>
      <div class="flex justify-between gap-2"><span class="text-slate-500">Factura</span><span><?= e($v['numero'] ?? '—') ?></span></div>
      <?php if ($textoModif !== null): ?>
        <div class="flex justify-between gap-2">
          <span class="text-slate-500">Motivo fiscal</span>
          <span class="text-right"><?= e($textoModif) ?> <span class="text-slate-400">(<?= (int) $codModif ?>)</span></span>
        </div>
      <?php endif; ?>
      <?php if ($d['motivo']): ?>
        <p class="text-slate-500 mt-1 leading-snug"><?= e($d['motivo']) ?></p>
      <?php endif; ?>
    </div>

    <!-- Líneas devueltas -->
    <table class="w-full text-xs tk-linea pt-2">
      <thead><tr class="text-slate-400">
        <th class="text-left font-medium py-1">Descripción</th>
        <th class="text-center font-medium">Cant</th>
        <th class="text-right font-medium"><?= e($rotuloImporte) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($lineas as $l): ?>
          <tr class="border-b border-slate-50 align-top">
            <td class="py-1">
              <span class="font-medium"><?= e($l['descripcion']) ?></span><br>
              <span class="text-slate-400 text-[11px]">
                <?php if (!empty($l['sku'])): ?><span class="font-mono"><?= e($l['sku']) ?></span> · <?php endif; ?><?= money($l['precio']) ?>
              </span>
            </td>
            <td class="text-center pt-1"><?= qty($l['cantidad']) ?></td>
            <td class="text-right pt-1 font-medium"><?= money($l['importe'], false) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Totales -->
    <div class="text-xs space-y-1 mt-3 tk-linea pt-3">
      <div class="flex justify-between"><span class="text-slate-500">Base acreditada</span><span><?= money($d['subtotal']) ?></span></div>
      <div class="flex justify-between"><span class="text-slate-500">ITBIS (<?= $tasaItbis ?>%)</span><span><?= money($d['itbis']) ?></span></div>
      <div class="flex justify-between text-base font-extrabold pt-1 border-t border-slate-200 mt-1">
        <span>TOTAL ACREDITADO</span><span style="color: <?= e($color) ?>"><?= money($d['total']) ?></span>
      </div>
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

    <!-- Código de barras del número de nota -->
    <?php if ($barrasNota !== ''): ?>
      <div class="text-center mt-4 tk-linea pt-3">
        <div class="flex justify-center"><?= $barrasNota ?></div>
        <p class="text-[11px] font-mono tracking-widest text-slate-600 mt-0.5"><?= e($d['numero']) ?></p>
      </div>
    <?php endif; ?>

    <p class="text-center text-[10px] text-slate-400 mt-4 tk-linea pt-3 leading-tight">
      Emitido por <?= e($emp['nombre'] ?? APP_NAME) ?><?= !empty($emp['rnc']) ? ' · RNC ' . e($emp['rnc']) : '' ?>
      <?php if (!empty($marca['pie'])): ?><br><?= e($marca['pie']) ?><?php endif; ?>
    </p>
  </div>

  <?php if ($autoPrint): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script><?php endif; ?>
</body>
</html>
