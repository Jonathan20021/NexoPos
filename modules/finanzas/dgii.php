<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('dgii.ver');

$empresa = $GLOBALS['empresa'] ?: [];
$formatosValidos = ['606', '607', '608'];

$formato = in_array(get('formato'), $formatosValidos, true) ? get('formato') : '606';

// El período (AAAAMM) puede llegar como <input type="month"> (AAAA-MM) o ya formateado.
$periodo = date('Ym');
if (preg_match('/^(\d{4})-(\d{2})$/', (string) get('periodo_mes'), $m)) {
    $periodo = $m[1] . $m[2];
} elseif (preg_match('/^\d{6}$/', (string) get('periodo'))) {
    $periodo = get('periodo');
}
$mesInput = substr($periodo, 0, 4) . '-' . substr($periodo, 4, 2);

// El archivo oficial siempre abarca todas las sucursales (se declara por RNC).
// Este filtro solo acota lo que se ve en pantalla.
$sucursalRevision = (int) get('sucursal_id') ?: null;
if ($sucursalRevision) require_sucursal_access($sucursalRevision);

$filasArchivo = dgiiFilas($formato, $periodo);                    // lo que se exporta
$filasVista   = dgiiFilas($formato, $periodo, $sucursalRevision); // lo que se muestra
[$errores, $avisos] = dgiiValidar($formato, $filasArchivo, $empresa);

// ---------- Descarga del TXT ----------
if (isPost() && post('accion') === 'descargar') {
    verify_csrf();
    require_perm('dgii.generar');
    if ($errores) {
        flash('error', 'Corrige los ' . count($errores) . ' errores antes de generar el archivo.');
        redirect('modules/finanzas/dgii.php?formato=' . $formato . '&periodo=' . $periodo);
    }
    $contenido = dgiiTxt($formato, $filasArchivo, $empresa, $periodo);
    $nombre = dgiiNombreArchivo($formato, $empresa['rnc'], $periodo);
    audit('dgii', 'generar', "Formato $formato generado para el período $periodo (" . count($filasArchivo) . ' registros)');

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($contenido));
    echo $contenido;
    exit;
}

$sucursales = sucursales_visibles();

// ---------- Totales ----------
$totales = ['registros' => count($filasVista), 'facturado' => 0.0, 'itbis' => 0.0];
foreach ($filasVista as $f) {
    if ($formato === '606') {
        $totales['facturado'] += (float) $f['monto_bienes'] + (float) $f['monto_servicios'];
        $totales['itbis']     += (float) $f['itbis'];
    } elseif ($formato === '607') {
        $totales['facturado'] += (float) $f['subtotal'] - (float) $f['descuento'];
        $totales['itbis']     += (float) $f['itbis'];
    }
}

$titulos = [
    '606' => 'Compras de Bienes y Servicios',
    '607' => 'Ventas de Bienes y Servicios',
    '608' => 'Comprobantes Anulados',
];

$acciones = '';
if (can('dgii.generar')) {
    $disabled = $errores ? 'disabled' : '';
    $clases = $errores
        ? 'btn bg-slate-200 text-slate-400 cursor-not-allowed'
        : 'btn btn-primary cursor-pointer';
    $acciones = '<form method="post" class="inline">' . csrf_field()
        . '<input type="hidden" name="accion" value="descargar">'
        . '<button ' . $disabled . ' class="' . $clases . '" '
        . ($errores ? 'title="Corrige los errores para poder generar el archivo"' : 'title="Descargar el archivo TXT"') . '>'
        . icon('download', 'w-4 h-4') . ' Generar TXT</button></form>';
}

layout_start('Reportes DGII', 'Formatos de envío de datos · Norma General 07-2018', $acciones);
?>

<!-- Selector de formato -->
<div class="flex flex-wrap items-center gap-2 mb-5" role="tablist" aria-label="Formato de envío">
  <?php foreach ($formatosValidos as $f):
      $activo = $f === $formato;
      $qs = http_build_query(['formato' => $f, 'periodo' => $periodo] + ($sucursalRevision ? ['sucursal_id' => $sucursalRevision] : []));
  ?>
    <a href="?<?= e($qs) ?>" role="tab" aria-selected="<?= $activo ? 'true' : 'false' ?>"
       class="px-4 py-2.5 rounded-xl text-sm font-semibold border transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500
              <?= $activo ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-800' ?>">
      Formato <?= $f ?>
      <span class="hidden sm:inline font-normal opacity-80">· <?= e($titulos[$f]) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<!-- Filtros -->
<form method="get" class="card p-5 mb-5">
  <input type="hidden" name="formato" value="<?= e($formato) ?>">
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
    <div>
      <label class="label" for="periodo_mes">Período a reportar</label>
      <input type="month" id="periodo_mes" name="periodo_mes" value="<?= e($mesInput) ?>" required class="input">
      <p class="mt-1 text-xs text-slate-500">Se envía a más tardar el día 15 del mes siguiente.</p>
    </div>
    <div>
      <label class="label" for="sucursal_id">Sucursal (solo revisión)</label>
      <select id="sucursal_id" name="sucursal_id" class="select">
        <option value="">Todas las sucursales</option>
        <?php foreach ($sucursales as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $sucursalRevision === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-slate-500">El archivo siempre incluye todas.</p>
    </div>
    <div>
      <button class="btn btn-primary w-full cursor-pointer"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
    </div>
  </div>
</form>

<!-- Resumen -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-sm text-slate-500">Registros en el archivo</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format(count($filasArchivo)) ?></p>
    <?php if ($sucursalRevision): ?>
      <p class="text-xs text-slate-500 mt-1"><?= number_format(count($filasVista)) ?> en la sucursal filtrada</p>
    <?php endif; ?>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-500">Monto facturado</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= $formato === '608' ? '—' : money($totales['facturado']) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-500">ITBIS</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= $formato === '608' ? '—' : money($totales['itbis']) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-500">Validación</p>
    <?php if ($errores): ?>
      <p class="text-2xl font-extrabold text-rose-600 mt-1"><?= count($errores) ?></p>
      <p class="text-xs text-rose-600 mt-1">errores por corregir</p>
    <?php else: ?>
      <p class="text-2xl font-extrabold text-emerald-600 mt-1">OK</p>
      <p class="text-xs text-slate-500 mt-1">sin errores</p>
    <?php endif; ?>
  </div>
</div>

<!-- Errores y advertencias -->
<?php if ($errores): ?>
  <div class="card p-5 mb-5 border-l-4 border-l-rose-500">
    <div class="flex items-start gap-3">
      <?= icon('alert', 'w-5 h-5 text-rose-600 shrink-0 mt-0.5') ?>
      <div class="min-w-0 flex-1">
        <h3 class="font-bold text-slate-800">No se puede generar el archivo</h3>
        <p class="text-sm text-slate-600 mt-0.5">La DGII rechazaría estos registros. Corrígelos y vuelve a intentar.</p>
        <ul class="mt-3 space-y-1.5 text-sm">
          <?php foreach (array_slice($errores, 0, 25) as $er): ?>
            <li class="flex gap-2">
              <span class="font-semibold text-slate-700 shrink-0"><?= e($er['ref']) ?></span>
              <span class="text-slate-600"><?= e($er['msg']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($errores) > 25): ?>
          <p class="mt-2 text-xs text-slate-500">y <?= count($errores) - 25 ?> más.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($avisos): ?>
  <div class="card p-5 mb-5 border-l-4 border-l-amber-400">
    <div class="flex items-start gap-3">
      <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0 mt-0.5') ?>
      <div class="min-w-0 flex-1">
        <h3 class="font-bold text-slate-800"><?= count($avisos) ?> advertencia<?= count($avisos) === 1 ? '' : 's' ?></h3>
        <p class="text-sm text-slate-600 mt-0.5">No impiden generar el archivo, pero conviene revisarlas.</p>
        <ul class="mt-3 space-y-1.5 text-sm">
          <?php foreach (array_slice($avisos, 0, 8) as $av): ?>
            <li class="flex gap-2">
              <span class="font-semibold text-slate-700 shrink-0"><?= e($av['ref']) ?></span>
              <span class="text-slate-600"><?= e($av['msg']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($avisos) > 8): ?>
          <p class="mt-2 text-xs text-slate-500">y <?= count($avisos) - 8 ?> más.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Vista previa -->
<div class="card overflow-hidden">
  <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
    <h3 class="font-bold text-slate-800">Vista previa · Formato <?= $formato ?></h3>
    <span class="text-sm text-slate-500"><?= e($titulos[$formato]) ?></span>
  </div>

  <?php if (!$filasVista): ?>
    <?= empty_state(
        'Sin registros en el período',
        $formato === '608'
          ? 'No se anuló ningún comprobante fiscal en este período.'
          : 'No hay comprobantes con NCF en ' . $mesInput . '. La DGII exige enviar el formato de manera informativa aunque no haya operaciones.',
        'receipt'
    ) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <?php if ($formato === '606'): ?>
            <tr><th>Compra</th><th>Proveedor</th><th>RNC</th><th>NCF</th><th>Tipo B/S</th><th>Comprobante</th>
                <th class="text-right">Bienes</th><th class="text-right">Servicios</th><th class="text-right">ITBIS</th><th>Pago</th></tr>
          <?php elseif ($formato === '607'): ?>
            <tr><th>Venta</th><th>Cliente</th><th>RNC/Cédula</th><th>NCF</th><th>Ingreso</th><th>Fecha</th>
                <th class="text-right">Facturado</th><th class="text-right">ITBIS</th><th class="text-right">Total</th></tr>
          <?php else: ?>
            <tr><th>NCF</th><th>Fecha comprobante</th><th>Motivo de anulación</th><th>Registrado</th></tr>
          <?php endif; ?>
        </thead>
        <tbody>
          <?php foreach (array_slice($filasVista, 0, 100) as $f): ?>
            <?php if ($formato === '606'): ?>
              <tr>
                <td class="font-semibold text-slate-700"><?= e($f['numero']) ?></td>
                <td><?= e($f['proveedor_nombre'] ?: '—') ?></td>
                <td class="tabular-nums"><?= e(dgiiSoloDigitos($f['proveedor_rnc']) ?: '—') ?></td>
                <td class="font-mono text-xs"><?= e($f['ncf']) ?></td>
                <td class="text-xs text-slate-500"><?= (int) $f['tipo_bien_servicio'] ?></td>
                <td class="tabular-nums"><?= e(dgiiFecha($f['fecha_comprobante'])) ?></td>
                <td class="text-right tabular-nums"><?= money($f['monto_bienes']) ?></td>
                <td class="text-right tabular-nums"><?= money($f['monto_servicios']) ?></td>
                <td class="text-right tabular-nums"><?= money($f['itbis']) ?></td>
                <td class="text-xs text-slate-500"><?= e(dgiiFormasPago606()[(int) $f['forma_pago']] ?? '—') ?></td>
              </tr>
            <?php elseif ($formato === '607'): ?>
              <tr>
                <td class="font-semibold text-slate-700"><?= e($f['numero']) ?></td>
                <td><?= e($f['cliente_nombre'] ?: '—') ?></td>
                <td class="tabular-nums"><?= e(dgiiSoloDigitos($f['cliente_doc'] ?? '') ?: '—') ?></td>
                <td class="font-mono text-xs"><?= e($f['ncf']) ?></td>
                <td class="text-xs text-slate-500"><?= (int) $f['tipo_ingreso'] ?></td>
                <td class="tabular-nums"><?= e(dgiiFecha($f['fecha'])) ?></td>
                <td class="text-right tabular-nums"><?= money((float) $f['subtotal'] - (float) $f['descuento']) ?></td>
                <td class="text-right tabular-nums"><?= money($f['itbis']) ?></td>
                <td class="text-right tabular-nums font-bold text-slate-800"><?= money($f['total']) ?></td>
              </tr>
            <?php else: ?>
              <tr>
                <td class="font-mono text-xs"><?= e($f['ncf']) ?></td>
                <td class="tabular-nums"><?= e(dgiiFecha($f['fecha_comprobante'])) ?></td>
                <td><?= (int) $f['tipo_anulacion'] ?>. <?= e(dgiiTiposAnulacion()[(int) $f['tipo_anulacion']] ?? 'Desconocido') ?></td>
                <td class="text-slate-500 text-xs"><?= e(substr((string) $f['created_at'], 0, 16)) ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($filasVista) > 100): ?>
      <p class="px-5 py-3 text-xs text-slate-500 border-t border-slate-100">
        Se muestran los primeros 100 de <?= number_format(count($filasVista)) ?> registros. El archivo TXT los incluye todos.
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Nota de cumplimiento -->
<div class="card p-5 mt-5 border-l-4 border-l-blue-500">
  <div class="flex items-start gap-3">
    <?= icon('shield', 'w-5 h-5 text-blue-600 shrink-0 mt-0.5') ?>
    <div class="text-sm text-slate-600 min-w-0">
      <h3 class="font-bold text-slate-800">Antes de tu primer envío</h3>
      <p class="mt-1">
        Los instructivos de la DGII definen las columnas, pero no documentan el separador del archivo TXT
        (su plantilla de Excel lo genera). Este módulo usa el pipe <code class="px-1 rounded bg-slate-100 font-mono">|</code>,
        que es la estructura que produce esa herramienta.
      </p>
      <p class="mt-2">
        Pasa el archivo generado por la <strong>herramienta de pre-validación</strong> de la Oficina Virtual
        antes del primer envío real. Una vez que la DGII te acepte un período, los siguientes salen igual.
      </p>
      <p class="mt-2 text-slate-500">
        Archivo: <code class="px-1 rounded bg-slate-100 font-mono text-xs"><?= e(dgiiNombreArchivo($formato, $empresa['rnc'] ?? '', $periodo)) ?></code>
      </p>
    </div>
  </div>
</div>

<?php layout_end(); ?>
