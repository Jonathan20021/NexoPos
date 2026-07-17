<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('dgii.ver');

$empresa = $GLOBALS['empresa'] ?: [];

// El período (AAAAMM) puede llegar como <input type="month"> (AAAA-MM) o ya formateado.
$periodo = date('Ym');
if (preg_match('/^(\d{4})-(\d{2})$/', (string) get('periodo_mes'), $m)) {
    $periodo = $m[1] . $m[2];
} elseif (preg_match('/^\d{6}$/', (string) get('periodo'))) {
    $periodo = get('periodo');
}
$mesInput = substr($periodo, 0, 4) . '-' . substr($periodo, 4, 2);

// Como en el 606/607: la declaración es por RNC (todas las sucursales).
// Este filtro solo acota lo que se revisa en pantalla.
$sucursalRevision = (int) get('sucursal_id') ?: null;
if ($sucursalRevision) require_sucursal_access($sucursalRevision);

$it1    = dgiiIt1($periodo, $sucursalRevision);
$avisos = dgiiIt1Avisos($periodo, $it1, $empresa);

$aPagar    = (float) $it1['a_pagar'];
$esAFavor  = $aPagar < 0;
$mesNombre = fechaLarga($mesInput . '-01');

// ---------- Export PDF para el contador ----------
if (quiere_pdf() && function_exists('pdf_render')) {
    $filas = function (array $rows): string {
        $h = '';
        foreach ($rows as $r) {
            $destacar = !empty($r[2]);
            $h .= '<tr' . ($destacar ? ' style="background:#f1f5f9;"' : '') . '>'
                . '<td>' . ($destacar ? '<strong>' . htmlspecialchars($r[0]) . '</strong>' : htmlspecialchars($r[0])) . '</td>'
                . '<td class="num">' . ($destacar ? '<strong>' . $r[1] . '</strong>' : $r[1]) . '</td></tr>';
        }
        return $h;
    };

    $H  = pdf_brand_header('IT-1 · DECLARACIÓN DE ITBIS', 'Período ' . $mesInput);
    $H .= '<h3>A. Operaciones del período</h3><table class="tbl"><tbody>'
        . $filas([
            ['Operaciones gravadas', money($it1['gravadas'])],
            ['Operaciones exentas', money($it1['exentas'])],
            ['Total de operaciones', money($it1['operaciones']), true],
        ]) . '</tbody></table>';
    $H .= '<h3>B. ITBIS</h3><table class="tbl"><tbody>'
        . $filas([
            ['ITBIS facturado en ventas (débito fiscal)', money($it1['debito'])],
            ['(−) ITBIS adelantado en compras (crédito fiscal)', money($it1['credito'])],
            ['Diferencia', money($it1['diferencia']), true],
        ]) . '</tbody></table>';
    $H .= '<h3>C. Retenciones y percepciones</h3><table class="tbl"><tbody>'
        . $filas([
            ['(−) ITBIS retenido por terceros', money($it1['retenido_terceros'])],
            ['(−) ITBIS percibido', money($it1['percibido_ventas'])],
            ['(+) ITBIS retenido a proveedores', money($it1['retenido_a_proveedores'])],
        ]) . '</tbody></table>';
    $H .= '<h3>' . ($esAFavor ? 'Saldo a favor' : 'ITBIS a pagar') . '</h3>'
        . '<table class="tbl"><tbody><tr style="background:#eff6ff;"><td><strong>'
        . ($esAFavor ? 'Saldo a favor del período' : 'Total ITBIS a pagar')
        . '</strong></td><td class="num"><strong>' . money(abs($aPagar)) . '</strong></td></tr></tbody></table>';
    $H .= '<p class="meta">Derivado de ' . $it1['ventas_registros'] . ' venta(s) del 607 y '
        . $it1['compras_registros'] . ' compra(s) del 606 con NCF. Resumen para transcribir a la '
        . 'Oficina Virtual de la DGII; no sustituye la revisión del contador.</p>';
    if ($avisos) {
        $H .= '<h3>Avisos</h3><ul>';
        foreach ($avisos as $a) $H .= '<li><strong>' . htmlspecialchars($a['ref']) . ':</strong> ' . htmlspecialchars($a['msg']) . '</li>';
        $H .= '</ul>';
    }
    audit('dgii', 'ver', "Resumen IT-1 exportado a PDF · período $periodo");
    pdf_render($H, 'it1_' . ($empresa['rnc'] ?? '') . '_' . $periodo, 'portrait');
}

$sucursales = sucursales_visibles();
$pdfUrl = '?' . http_build_query(array_merge($_GET, ['periodo' => $periodo, 'export' => 'pdf']));
$acciones = '<a href="' . e($pdfUrl) . '" target="_blank" class="btn btn-ghost no-print">' . icon('download', 'w-4 h-4') . ' PDF</a>';

layout_start('IT-1 · Declaración de ITBIS', 'Resumen del período para la Oficina Virtual · ' . $mesNombre, $acciones);
?>

<!-- Filtros -->
<form method="get" class="card p-5 mb-5 no-print">
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
    <div>
      <label class="label" for="periodo_mes">Período</label>
      <input type="month" id="periodo_mes" name="periodo_mes" value="<?= e($mesInput) ?>" required class="input">
      <p class="mt-1 text-xs text-slate-500">Se declara a más tardar el día 20 del mes siguiente.</p>
    </div>
    <div>
      <label class="label" for="sucursal_id">Sucursal (solo revisión)</label>
      <select id="sucursal_id" name="sucursal_id" class="select">
        <option value="">Todas las sucursales</option>
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $sucursalRevision === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-slate-500">La declaración es por RNC: incluye todas.</p>
    </div>
    <div><button class="btn btn-primary w-full cursor-pointer"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button></div>
  </div>
</form>

<!-- Resultado -->
<div class="card p-6 mb-5 border-l-4 <?= $esAFavor ? 'border-l-emerald-500' : 'border-l-blue-500' ?>">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div class="min-w-0">
      <p class="text-sm text-slate-500"><?= $esAFavor ? 'Saldo a favor del período' : 'ITBIS a pagar del período' ?></p>
      <p class="text-3xl font-extrabold <?= $esAFavor ? 'text-emerald-600' : 'text-slate-800' ?> mt-1"><?= money(abs($aPagar)) ?></p>
      <p class="text-xs text-slate-500 mt-1">
        Derivado de <?= number_format($it1['ventas_registros']) ?> venta(s) del 607 y
        <?= number_format($it1['compras_registros']) ?> compra(s) del 606<?php if (($it1['notas_credito'] ?? 0) > 0): ?>,
        menos <?= number_format($it1['notas_credito']) ?> nota(s) de crédito (B04)<?php endif; ?>.
      </p>
    </div>
    <span class="w-14 h-14 rounded-2xl <?= $esAFavor ? 'bg-emerald-50 text-emerald-600' : 'bg-blue-50 text-blue-600' ?> flex items-center justify-center shrink-0">
      <?= icon($esAFavor ? 'trending' : 'percent', 'w-7 h-7') ?>
    </span>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- A. Operaciones -->
  <div class="card p-5">
    <h3 class="font-bold text-slate-800">A. Operaciones del período</h3>
    <p class="text-sm text-slate-500 mb-4">Base imponible, tomada de cada línea de venta.</p>
    <div class="divide-y divide-slate-100 text-sm">
      <div class="flex items-center justify-between py-2.5">
        <span class="text-slate-600">Operaciones gravadas</span>
        <span class="font-semibold text-slate-800 tabular-nums"><?= money($it1['gravadas']) ?></span>
      </div>
      <div class="flex items-center justify-between py-2.5">
        <span class="text-slate-600">Operaciones exentas</span>
        <span class="font-semibold text-slate-800 tabular-nums"><?= money($it1['exentas']) ?></span>
      </div>
      <div class="flex items-center justify-between py-3 bg-slate-50/60 -mx-5 px-5">
        <span class="font-semibold text-slate-700">Total de operaciones</span>
        <span class="font-bold text-slate-800 tabular-nums"><?= money($it1['operaciones']) ?></span>
      </div>
      <div class="flex items-center justify-between py-2.5">
        <span class="text-slate-500 text-xs">Total facturado (operaciones + ITBIS)</span>
        <span class="text-slate-500 text-xs tabular-nums"><?= money($it1['total_facturado']) ?></span>
      </div>
    </div>
  </div>

  <!-- B. ITBIS -->
  <div class="card p-5">
    <h3 class="font-bold text-slate-800">B. ITBIS</h3>
    <p class="text-sm text-slate-500 mb-4">Débito contra crédito fiscal del período.</p>
    <div class="divide-y divide-slate-100 text-sm">
      <div class="flex items-center justify-between py-2.5">
        <span class="text-slate-600">ITBIS facturado en ventas <span class="text-slate-400">(débito fiscal)</span></span>
        <span class="font-semibold text-slate-800 tabular-nums"><?= money($it1['debito']) ?></span>
      </div>
      <div class="flex items-center justify-between py-2.5">
        <span class="text-slate-600">(−) ITBIS adelantado en compras <span class="text-slate-400">(crédito fiscal)</span></span>
        <span class="font-semibold text-rose-600 tabular-nums"><?= money($it1['credito']) ?></span>
      </div>
      <div class="flex items-center justify-between py-3 bg-slate-50/60 -mx-5 px-5">
        <span class="font-semibold text-slate-700">Diferencia</span>
        <span class="font-bold <?= $it1['diferencia'] < 0 ? 'text-emerald-600' : 'text-slate-800' ?> tabular-nums"><?= money($it1['diferencia']) ?></span>
      </div>
    </div>
    <p class="text-xs text-slate-500 mt-3">
      El crédito fiscal es el <strong>ITBIS por adelantar</strong> del 606: facturado menos el llevado al costo.
    </p>
  </div>
</div>

<!-- C. Retenciones -->
<div class="card p-5 mt-5">
  <h3 class="font-bold text-slate-800">C. Retenciones y percepciones</h3>
  <p class="text-sm text-slate-500 mb-4">Ajustan lo que finalmente se paga.</p>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
    <div class="rounded-xl bg-slate-50 p-4">
      <p class="text-slate-500 text-xs">(−) ITBIS retenido por terceros</p>
      <p class="font-bold text-slate-800 text-lg mt-1 tabular-nums"><?= money($it1['retenido_terceros']) ?></p>
      <p class="text-xs text-slate-400 mt-1">Tus clientes ya lo enteraron: se acredita.</p>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
      <p class="text-slate-500 text-xs">(−) ITBIS percibido</p>
      <p class="font-bold text-slate-800 text-lg mt-1 tabular-nums"><?= money($it1['percibido_ventas']) ?></p>
      <p class="text-xs text-slate-400 mt-1">Percepciones registradas en ventas.</p>
    </div>
    <div class="rounded-xl bg-slate-50 p-4">
      <p class="text-slate-500 text-xs">(+) ITBIS retenido a proveedores</p>
      <p class="font-bold text-slate-800 text-lg mt-1 tabular-nums"><?= money($it1['retenido_a_proveedores']) ?></p>
      <p class="text-xs text-slate-400 mt-1">Lo retuviste tú: lo debes enterar.</p>
    </div>
  </div>
</div>

<!-- Avisos -->
<?php if ($avisos): ?>
  <div class="card p-5 mt-5 border-l-4 border-l-amber-400">
    <div class="flex items-start gap-3">
      <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0 mt-0.5') ?>
      <div class="min-w-0 flex-1">
        <h3 class="font-bold text-slate-800"><?= count($avisos) ?> aviso<?= count($avisos) === 1 ? '' : 's' ?></h3>
        <p class="text-sm text-slate-600 mt-0.5">No bloquean la declaración, pero revísalos antes de presentarla.</p>
        <ul class="mt-3 space-y-1.5 text-sm">
          <?php foreach ($avisos as $a): ?>
            <li class="flex gap-2">
              <span class="font-semibold text-slate-700 shrink-0"><?= e($a['ref']) ?></span>
              <span class="text-slate-600"><?= e($a['msg']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Nota -->
<div class="card p-5 mt-5 border-l-4 border-l-blue-500">
  <div class="flex items-start gap-3">
    <?= icon('shield', 'w-5 h-5 text-blue-600 shrink-0 mt-0.5') ?>
    <div class="text-sm text-slate-600 min-w-0">
      <h3 class="font-bold text-slate-800">Cómo usar este resumen</h3>
      <p class="mt-1">
        El IT-1 no es un archivo que se sube: es la declaración que se llena en la
        <strong>Oficina Virtual</strong>. Estas cifras salen de las mismas ventas y compras que se
        declaran en el <a href="<?= e(url('modules/finanzas/dgii.php?formato=607&periodo=' . $periodo)) ?>" class="text-blue-600 font-semibold hover:underline">607</a>
        y el <a href="<?= e(url('modules/finanzas/dgii.php?formato=606&periodo=' . $periodo)) ?>" class="text-blue-600 font-semibold hover:underline">606</a>,
        así que siempre cuadran con lo enviado.
      </p>
      <p class="mt-2 text-slate-500">
        Es un apoyo para transcribir la declaración, no un sustituto de la revisión de tu contador.
      </p>
    </div>
  </div>
</div>

<?php layout_end(); ?>
