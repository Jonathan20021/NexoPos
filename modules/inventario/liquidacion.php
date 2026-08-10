<?php
/**
 * Ficha de un embarque: líneas, gastos y el costo real que resulta.
 *
 * El corazón de la pantalla es la tabla de costeo: al lado del FOB de cada
 * artículo se ve cuánto gasto le tocó, cuánto queda costando puesto en almacén
 * y qué margen deja al precio de venta actual. Ahí es donde se descubre que un
 * artículo que «deja 40%» en realidad deja 12% después del flete.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('liquidaciones.ver');

if (!liq_disponible()) { redirect('modules/inventario/liquidaciones.php'); }

$id  = (int) get('id');
$liq = liq_cargar($id);
if (!$liq) { http_response_code(404); layout_start('Embarque no encontrado', ''); echo empty_state('No existe', 'Esa liquidación no está registrada.', 'alert'); layout_end(); return; }
require_sucursal_access($liq['sucursal_id']);

$editable = liq_editable($liq);
$tasa     = (float) $liq['tasa_cambio'] ?: 1.0;
$monedaId = $liq['moneda_id'] ? (int) $liq['moneda_id'] : null;
$simbolo  = $monedaId && mon_disponible() ? (moneda($monedaId)['codigo'] ?? 'RD$') : setting('moneda', 'RD$');
$esExtranjera = $monedaId && mon_disponible() && (int) (moneda($monedaId)['es_base'] ?? 0) !== 1;

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    $volver = 'modules/inventario/liquidacion.php?id=' . $id;

    // Editar el encabezado, agregar y quitar cosas: todo exige que siga abierta.
    if (in_array($accion, ['encabezado', 'linea', 'quitar_linea', 'gasto', 'quitar_gasto', 'transito', 'borrador'], true)) {
        require_perm('liquidaciones.crear');
        if (!$editable) {
            flash('error', 'Esta liquidación ya no se puede editar: está ' . mb_strtolower(liq_estados()[$liq['estado']]['label']) . '.');
            redirect($volver);
        }
    }

    if ($accion === 'encabezado') {
        $nuevaTasa = postNum('tasa_cambio');
        dbUpdate('liquidaciones', [
            'referencia'    => trim(post('referencia')) ?: null,
            'fecha'         => liq_fecha(post('fecha'), $liq['fecha']),
            'fecha_llegada' => liq_fecha(post('fecha_llegada')),
            'proveedor_id'  => postInt('proveedor_id') ?: null,
            'tienda_id'     => tiendas_disponible() ? (postInt('tienda_id') ?: null) : $liq['tienda_id'],
            'prorrateo'     => array_key_exists(post('prorrateo'), liq_prorrateos()) ? post('prorrateo') : $liq['prorrateo'],
            'tasa_cambio'   => $nuevaTasa > 0 ? $nuevaTasa : $tasa,
            'notas'         => mb_substr(trim(post('notas')), 0, 500) ?: null,
        ], 'id = ?', [$id]);

        // Si cambió la tasa hay que volver a pasar a pesos todo lo pactado en
        // moneda extranjera: si no, el FOB en pesos se queda con la tasa vieja y
        // el costo final sale mal sin que nadie lo note.
        if ($nuevaTasa > 0 && abs($nuevaTasa - $tasa) > 0.000001) {
            q("UPDATE liquidacion_detalles SET costo_fob = ROUND(costo_moneda * ?, 4) WHERE liquidacion_id = ?", [$nuevaTasa, $id]);
            q("UPDATE liquidacion_gastos SET monto = ROUND(monto_moneda * tasa_cambio, 2) WHERE liquidacion_id = ?", [$id]);
        }
        liq_recalcular($id);
        flash('success', 'Embarque actualizado.');
        redirect($volver);
    }

    if ($accion === 'linea') {
        $clave = trim(post('producto'));
        $prod = qOne(
            "SELECT id, nombre, controla_lote FROM productos
              WHERE activo = 1 AND (codigo = ? OR codigo_barras = ? OR nombre = ?) LIMIT 1",
            [$clave, $clave, $clave]
        );
        $cant  = postNum('cantidad');
        $costo = postNum('costo_moneda');

        if (!$prod) {
            flash('error', 'No se encontró un producto activo con «' . $clave . '». Escribe su SKU exacto o elígelo de la lista.');
        } elseif ($cant <= 0) {
            flash('error', 'La cantidad debe ser mayor que cero.');
        } elseif ($costo < 0) {
            flash('error', 'El costo no puede ser negativo.');
        } elseif (!empty($prod['controla_lote']) && trim(post('lote')) === '') {
            flash('error', '«' . $prod['nombre'] . '» controla lote: escribe el número de lote del embarque.');
        } else {
            $datos = [
                'liquidacion_id' => $id,
                'producto_id'    => (int) $prod['id'],
                'cantidad'       => $cant,
                'costo_moneda'   => $costo,
                'costo_fob'      => round($costo * $tasa, 4),
                'peso'           => max(0, postNum('peso')),
                'volumen'        => max(0, postNum('volumen')),
                'lote'           => trim(post('lote')) ?: null,
                'vencimiento'    => liq_fecha(post('vencimiento')),
            ];
            // Un artículo repetido no crea otra línea: se actualiza. El UNIQUE
            // (liquidacion_id, producto_id) lo impide de todos modos, y esto lo
            // convierte en «corregir» en vez de en un error.
            $ya = qVal("SELECT id FROM liquidacion_detalles WHERE liquidacion_id = ? AND producto_id = ?", [$id, (int) $prod['id']]);
            if ($ya) {
                unset($datos['liquidacion_id'], $datos['producto_id']);
                dbUpdate('liquidacion_detalles', $datos, 'id = ?', [(int) $ya]);
                flash('success', 'Se actualizó la línea de «' . $prod['nombre'] . '».');
            } else {
                dbInsert('liquidacion_detalles', $datos);
                flash('success', '«' . $prod['nombre'] . '» agregado al embarque.');
            }
            liq_recalcular($id);
        }
        redirect($volver);
    }

    if ($accion === 'quitar_linea') {
        q("DELETE FROM liquidacion_detalles WHERE id = ? AND liquidacion_id = ?", [postInt('linea_id'), $id]);
        liq_recalcular($id);
        flash('success', 'Línea eliminada.');
        redirect($volver);
    }

    if ($accion === 'gasto') {
        $tipo    = array_key_exists(post('tipo'), liq_tipos_gasto()) ? post('tipo') : 'otros';
        $montoM  = postNum('monto_moneda');
        $gMoneda = mon_disponible() ? (postInt('gasto_moneda_id') ?: null) : null;
        $gTasa   = $gMoneda ? mon_tasa($gMoneda) : 1.0;
        $gTasaP  = postNum('gasto_tasa');
        if ($gTasaP > 0) $gTasa = $gTasaP;

        if ($montoM <= 0) {
            flash('error', 'El monto del gasto debe ser mayor que cero.');
        } else {
            dbInsert('liquidacion_gastos', [
                'liquidacion_id' => $id,
                'tipo'           => $tipo,
                'concepto'       => trim(post('concepto')) ?: liq_gasto_label($tipo),
                'moneda_id'      => $gMoneda,
                'tasa_cambio'    => $gTasa,
                'monto_moneda'   => $montoM,
                'monto'          => round($montoM * $gTasa, 2),
                'al_costo'       => post('al_costo') === '1' ? 1 : 0,
                'orden'          => (int) qVal("SELECT COALESCE(MAX(orden),0)+1 FROM liquidacion_gastos WHERE liquidacion_id = ?", [$id]),
            ]);
            liq_recalcular($id);
            flash('success', 'Gasto agregado. El reparto se recalculó.');
        }
        redirect($volver);
    }

    if ($accion === 'quitar_gasto') {
        q("DELETE FROM liquidacion_gastos WHERE id = ? AND liquidacion_id = ?", [postInt('gasto_id'), $id]);
        liq_recalcular($id);
        flash('success', 'Gasto eliminado.');
        redirect($volver);
    }

    if ($accion === 'transito' || $accion === 'borrador') {
        dbUpdate('liquidaciones', ['estado' => $accion === 'transito' ? 'transito' : 'borrador'], 'id = ?', [$id]);
        audit('liquidaciones', 'editar', 'Embarque marcado como ' . $accion . ': ' . $liq['numero'], ['tabla' => 'liquidaciones', 'registro_id' => $id]);
        flash('success', $accion === 'transito' ? 'Embarque marcado como en tránsito.' : 'Embarque devuelto a borrador.');
        redirect($volver);
    }

    if ($accion === 'aplicar') {
        require_perm('liquidaciones.aplicar');
        try {
            $r = liq_aplicar($id, (int) current_user()['id']);
            audit('liquidaciones', 'aplicar',
                'Liquidación aplicada ' . $liq['numero'] . ' · ' . money($r['costo_total']),
                ['tabla' => 'liquidaciones', 'registro_id' => $id]);
            flash('success', $r['modo'] === 'entrada'
                ? 'Aplicada: entraron ' . qty($r['unidades']) . ' unidades al almacén y el catálogo quedó con el costo real.'
                : 'Aplicada: se corrigió el costo de ' . $r['lineas'] . ' producto(s). No se movió inventario.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }

    if ($accion === 'anular') {
        require_perm('liquidaciones.anular');
        try {
            liq_anular($id, (int) current_user()['id'], trim(post('motivo')));
            audit('liquidaciones', 'anular', 'Liquidación anulada ' . $liq['numero'], ['tabla' => 'liquidaciones', 'registro_id' => $id]);
            flash('success', 'Liquidación anulada. El stock y los costos volvieron a como estaban.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }
}

// ---------- Cálculo para mostrar ----------
$calc = liq_calcular($liq['prorrateo'], $liq['detalles'], $liq['gastos']);
$porProducto = [];
foreach ($calc['lineas'] as $l) $porProducto[$l['producto_id']] = $l;

$proveedores = qAll("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");
$catalogo    = qAll("SELECT id, codigo, nombre, precio_venta, controla_lote FROM productos WHERE activo = 1 AND tipo='producto' ORDER BY nombre");
$monedas     = mon_disponible() ? monedas() : [];

$titulo = 'Embarque ' . $liq['numero'];
$acciones = '<a href="' . e(url('modules/inventario/liquidaciones.php')) . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Volver</a>';
if ($editable && can('liquidaciones.aplicar')) {
    $acciones .= '<button type="button" onclick="window.dispatchEvent(new CustomEvent(\'liq:aplicar\',{bubbles:true}))" class="btn btn-success">'
        . icon('check', 'w-4 h-4') . ' Aplicar</button>';
}
layout_start($titulo, liq_estados()[$liq['estado']]['ayuda'], $acciones);
?>

<!-- Estado y totales -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-4 lg:col-span-1">
    <div class="flex items-center justify-between">
      <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Estado</p>
      <?= liq_badge($liq['estado']) ?>
    </div>
    <p class="text-sm text-slate-600 mt-2 leading-snug"><?= e(liq_estados()[$liq['estado']]['ayuda']) ?></p>
    <?php if ($editable): ?>
      <div class="flex gap-2 mt-3 flex-wrap">
        <?php if (can('liquidaciones.crear') && $liq['estado'] === 'borrador'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="transito">
            <button class="btn btn-soft btn-sm"><?= icon('truck', 'w-3.5 h-3.5') ?> Marcar en tránsito</button></form>
        <?php elseif (can('liquidaciones.crear')): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="borrador">
            <button class="btn btn-ghost btn-sm">Volver a borrador</button></form>
        <?php endif; ?>
      </div>
    <?php elseif ($liq['estado'] === 'aplicada'): ?>
      <p class="text-xs text-slate-400 mt-2">Aplicada el <?= fechaHora($liq['aplicada_at']) ?>.</p>
    <?php endif; ?>
    <?php if ($liq['estado'] !== 'anulada' && can('liquidaciones.anular')): ?>
      <form method="post" class="mt-3" onsubmit="return confirm('¿Anular la liquidación <?= e($liq['numero']) ?>?<?= $liq['estado'] === 'aplicada' ? ' Se sacará del inventario lo que entró y los costos volverán a como estaban.' : '' ?>')">
        <?= csrf_field() ?><input type="hidden" name="accion" value="anular">
        <input type="text" name="motivo" class="input text-sm mb-2" placeholder="Motivo (opcional)">
        <button class="btn btn-danger btn-sm w-full"><?= icon('x', 'w-3.5 h-3.5') ?> Anular</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card p-4">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Mercancía (FOB)</p>
    <p class="text-xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= money($calc['fob']) ?></p>
    <p class="text-xs text-slate-400 mt-0.5"><?= qty($calc['unidades']) ?> unidades · <?= count($liq['detalles']) ?> línea(s)</p>
  </div>
  <div class="card p-4">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Gastos al costo</p>
    <p class="text-xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= money($calc['gastos']) ?></p>
    <p class="text-xs mt-0.5 <?= $calc['recargo_pct'] >= 40 ? 'text-rose-600' : ($calc['recargo_pct'] >= 20 ? 'text-amber-600' : 'text-slate-400') ?>">
      Encarece la mercancía un <?= number_format($calc['recargo_pct'], 1) ?>%
    </p>
  </div>
  <div class="card p-4" style="border-left: 4px solid #059669">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Costo real puesto en almacén</p>
    <p class="text-xl font-extrabold text-emerald-700 mt-1 tabular-nums"><?= money($calc['costo_total']) ?></p>
    <?php if ($calc['gastos_no_costo'] > 0): ?>
      <p class="text-xs text-slate-400 mt-0.5">+ <?= money($calc['gastos_no_costo']) ?> recuperable (no es costo)</p>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

  <!-- ============ Columna izquierda: líneas y costeo ============ -->
  <div class="lg:col-span-2 space-y-5">

    <div class="card overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h3 class="font-bold text-slate-800">Costeo por artículo</h3>
          <p class="text-xs text-slate-500 mt-0.5">Gastos repartidos <?= e(mb_strtolower(liq_prorrateos()[$liq['prorrateo']])) ?></p>
        </div>
      </div>

      <?php if (!$liq['detalles']): ?>
        <?= empty_state('El embarque no tiene artículos', 'Agrega las líneas de la factura del proveedor con su cantidad y su costo FOB.', 'box') ?>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr>
              <th>Artículo</th>
              <th class="text-center">Cant.</th>
              <th class="text-right">FOB unit.</th>
              <th class="text-right">Gasto asignado</th>
              <th class="text-right">Costo real unit.</th>
              <th class="text-center">Recargo</th>
              <th class="text-center">Margen actual</th>
              <?php if ($editable): ?><th></th><?php endif; ?>
            </tr></thead>
            <tbody>
              <?php foreach ($liq['detalles'] as $d):
                $l = $porProducto[(int) $d['producto_id']] ?? null;
                if (!$l) continue;
                $precio = (float) $d['precio_venta'];
                // Margen contra el costo REAL, que es de lo que va todo esto.
                $margen = $precio > 0 ? ($precio - $l['costo_final']) / $precio * 100 : 0;
                $margenPrevio = $precio > 0 ? ($precio - (float) $d['costo_fob']) / $precio * 100 : 0;
              ?>
                <tr>
                  <td>
                    <p class="font-semibold text-slate-700"><?= e($d['producto']) ?></p>
                    <p class="text-xs text-slate-400 font-mono"><?= e($d['sku']) ?></p>
                    <?php if (!empty($d['lote'])): ?>
                      <p class="text-[11px] text-slate-500 mt-0.5">Lote <?= e($d['lote']) ?><?= $d['vencimiento'] ? ' · vence ' . fechaCorta($d['vencimiento']) : '' ?></p>
                    <?php elseif (!empty($d['controla_lote'])): ?>
                      <p class="text-[11px] text-amber-600 mt-0.5">Controla lote y no tiene número</p>
                    <?php endif; ?>
                  </td>
                  <td class="text-center tabular-nums"><?= qty($d['cantidad']) ?></td>
                  <td class="text-right tabular-nums">
                    <?= money($d['costo_fob'], false) ?>
                    <?php if ($esExtranjera): ?>
                      <p class="text-[11px] text-slate-400"><?= e($simbolo) ?> <?= number_format((float) $d['costo_moneda'], 2) ?></p>
                    <?php endif; ?>
                  </td>
                  <td class="text-right tabular-nums text-slate-500"><?= money($l['prorrateo'], false) ?></td>
                  <td class="text-right tabular-nums font-bold text-slate-800"><?= money($l['costo_final'], false) ?></td>
                  <td class="text-center">
                    <span class="badge <?= $l['recargo_pct'] >= 40 ? 'badge-rose' : ($l['recargo_pct'] >= 20 ? 'badge-amber' : 'badge-emerald') ?>">
                      +<?= number_format($l['recargo_pct'], 1) ?>%
                    </span>
                  </td>
                  <td class="text-center">
                    <?php if ($precio > 0): ?>
                      <span class="font-semibold <?= $margen < 0 ? 'text-rose-600' : ($margen < 15 ? 'text-amber-600' : 'text-emerald-600') ?>">
                        <?= number_format($margen, 1) ?>%
                      </span>
                      <p class="text-[11px] text-slate-400">antes <?= number_format($margenPrevio, 1) ?>%</p>
                    <?php else: ?>
                      <span class="text-slate-300">sin precio</span>
                    <?php endif; ?>
                  </td>
                  <?php if ($editable): ?>
                    <td class="text-right">
                      <form method="post" class="inline" onsubmit="return confirm('¿Quitar «<?= e($d['producto']) ?>» del embarque?')">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="quitar_linea"><input type="hidden" name="linea_id" value="<?= (int) $d['id'] ?>">
                        <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50"><?= icon('trash', 'w-4 h-4') ?></button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($editable && can('liquidaciones.crear')): ?>
        <form method="post" class="p-4 bg-slate-50 border-t border-slate-100 grid grid-cols-2 sm:grid-cols-12 gap-3 items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="linea">
          <div class="col-span-2 sm:col-span-4">
            <label class="label" for="ln_prod">Artículo (SKU o nombre) *</label>
            <input type="text" id="ln_prod" name="producto" list="dl_productos" required class="input" placeholder="SKU-00012" data-escaner autocomplete="off">
            <datalist id="dl_productos">
              <?php foreach ($catalogo as $c): ?>
                <option value="<?= e($c['codigo']) ?>"><?= e($c['nombre']) ?></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="ln_cant">Cantidad *</label>
            <input type="number" step="0.001" min="0.001" id="ln_cant" name="cantidad" required class="input">
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="ln_costo">Costo unit. (<?= e($simbolo) ?>) *</label>
            <input type="number" step="0.0001" min="0" id="ln_costo" name="costo_moneda" required class="input">
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="ln_peso">Peso unit. (kg)</label>
            <input type="number" step="0.001" min="0" id="ln_peso" name="peso" class="input" placeholder="0">
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="ln_vol">Volumen (m³)</label>
            <input type="number" step="0.0001" min="0" id="ln_vol" name="volumen" class="input" placeholder="0">
          </div>
          <div class="sm:col-span-3">
            <label class="label" for="ln_lote">Lote</label>
            <input type="text" id="ln_lote" name="lote" maxlength="60" class="input" placeholder="Solo mercancía regulada">
          </div>
          <div class="sm:col-span-3">
            <label class="label" for="ln_venc">Vencimiento</label>
            <input type="date" id="ln_venc" name="vencimiento" class="input">
          </div>
          <div class="col-span-2 sm:col-span-6 flex justify-end">
            <button class="btn btn-primary w-full sm:w-auto"><?= icon('plus', 'w-4 h-4') ?> Agregar artículo</button>
          </div>
          <p class="col-span-2 sm:col-span-12 text-xs text-slate-500 -mt-1">
            Peso y volumen solo hacen falta si repartes los gastos por peso o por volumen. Si repites un artículo, se corrige la línea existente.
          </p>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ Columna derecha: datos y gastos ============ -->
  <div class="space-y-5">

    <!-- Datos del embarque -->
    <div class="card">
      <div class="px-5 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Datos del embarque</h3></div>
      <?php if ($editable && can('liquidaciones.crear')): ?>
        <form method="post" class="p-5 space-y-3">
          <?= csrf_field() ?><input type="hidden" name="accion" value="encabezado">
          <div><label class="label" for="hd_ref">Referencia</label>
            <input type="text" id="hd_ref" name="referencia" value="<?= e($liq['referencia']) ?>" maxlength="60" class="input" placeholder="BL / contenedor / DUA"></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="hd_fecha">Fecha</label><input type="date" id="hd_fecha" name="fecha" value="<?= e($liq['fecha']) ?>" class="input"></div>
            <div><label class="label" for="hd_lleg">Llegada</label><input type="date" id="hd_lleg" name="fecha_llegada" value="<?= e($liq['fecha_llegada']) ?>" class="input"></div>
          </div>
          <div><label class="label" for="hd_prov">Proveedor</label>
            <select id="hd_prov" name="proveedor_id" class="select">
              <option value="">— Sin proveedor —</option>
              <?php foreach ($proveedores as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $liq['proveedor_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <?php if (tiendas_hay()): ?>
            <div><label class="label" for="hd_tienda">Tienda (marca)</label>
              <select id="hd_tienda" name="tienda_id" class="select">
                <option value="">— Sin marca —</option>
                <?php foreach (tiendas_activas() as $t): ?>
                  <option value="<?= (int) $t['id'] ?>" <?= (int) $liq['tienda_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
                <?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <div><label class="label" for="hd_pro">Repartir los gastos</label>
            <select id="hd_pro" name="prorrateo" class="select">
              <?php foreach (liq_prorrateos() as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $liq['prorrateo'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select></div>
          <?php if ($esExtranjera): ?>
            <div><label class="label" for="hd_tasa">Tasa (<?= e($simbolo) ?> → RD$)</label>
              <input type="number" step="0.0001" min="0" id="hd_tasa" name="tasa_cambio" value="<?= e(rtrim(rtrim(number_format($tasa, 4, '.', ''), '0'), '.')) ?>" class="input">
              <p class="mt-1 text-xs text-slate-500">Al cambiarla se recalcula el FOB en pesos de todas las líneas.</p></div>
          <?php endif; ?>
          <div><label class="label" for="hd_notas">Notas</label>
            <textarea id="hd_notas" name="notas" rows="2" maxlength="500" class="input"><?= e($liq['notas']) ?></textarea></div>
          <button class="btn btn-primary w-full"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </form>
      <?php else: ?>
        <div class="p-5 text-sm space-y-1.5">
          <div class="flex justify-between gap-2"><span class="text-slate-500">Referencia</span><span class="font-medium text-slate-700"><?= e($liq['referencia'] ?: '—') ?></span></div>
          <div class="flex justify-between gap-2"><span class="text-slate-500">Fecha</span><span class="text-slate-700"><?= fechaCorta($liq['fecha']) ?></span></div>
          <div class="flex justify-between gap-2"><span class="text-slate-500">Llegada</span><span class="text-slate-700"><?= $liq['fecha_llegada'] ? fechaCorta($liq['fecha_llegada']) : '—' ?></span></div>
          <div class="flex justify-between gap-2"><span class="text-slate-500">Proveedor</span><span class="text-slate-700"><?= e($liq['proveedor'] ?: '—') ?></span></div>
          <div class="flex justify-between gap-2"><span class="text-slate-500">Almacén</span><span class="text-slate-700"><?= e($liq['sucursal']) ?></span></div>
          <?php if ($liq['tienda']): ?><div class="flex justify-between gap-2"><span class="text-slate-500">Tienda</span><span class="text-slate-700"><?= e($liq['tienda']) ?></span></div><?php endif; ?>
          <div class="flex justify-between gap-2"><span class="text-slate-500">Reparto</span><span class="text-slate-700"><?= e(liq_prorrateos()[$liq['prorrateo']]) ?></span></div>
          <?php if ($esExtranjera): ?><div class="flex justify-between gap-2"><span class="text-slate-500">Tasa</span><span class="text-slate-700"><?= number_format($tasa, 4) ?></span></div><?php endif; ?>
          <?php if ($liq['compra_numero']): ?><div class="flex justify-between gap-2"><span class="text-slate-500">Recostea</span><span class="text-slate-700"><?= e($liq['compra_numero']) ?></span></div><?php endif; ?>
          <?php if ($liq['notas']): ?><p class="text-xs text-slate-500 pt-2 border-t border-slate-100 mt-2 whitespace-pre-line"><?= e($liq['notas']) ?></p><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Gastos -->
    <div class="card">
      <div class="px-5 py-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">Gastos del embarque</h3>
        <p class="text-xs text-slate-500 mt-0.5">El ITBIS de aduana no entra al costo: se compensa con el ITBIS de la venta.</p>
      </div>
      <?php if (!$liq['gastos']): ?>
        <p class="px-5 py-6 text-sm text-slate-400 text-center">Sin gastos registrados. El costo sería solo el FOB.</p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($liq['gastos'] as $g): ?>
            <li class="px-5 py-3 flex items-center gap-3">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-700 truncate"><?= e($g['concepto']) ?></p>
                <p class="text-xs text-slate-400">
                  <?= e(liq_gasto_label($g['tipo'])) ?>
                  <?php if ((int) $g['al_costo'] !== 1): ?>
                    <span class="text-sky-600 font-semibold">· recuperable</span>
                  <?php endif; ?>
                </p>
              </div>
              <span class="text-sm font-semibold tabular-nums <?= (int) $g['al_costo'] === 1 ? 'text-slate-800' : 'text-slate-400' ?>"><?= money($g['monto'], false) ?></span>
              <?php if ($editable && can('liquidaciones.crear')): ?>
                <form method="post" class="shrink-0" onsubmit="return confirm('¿Quitar este gasto?')">
                  <?= csrf_field() ?><input type="hidden" name="accion" value="quitar_gasto"><input type="hidden" name="gasto_id" value="<?= (int) $g['id'] ?>">
                  <button class="p-1.5 rounded-lg text-slate-300 hover:text-rose-600 hover:bg-rose-50"><?= icon('trash', 'w-3.5 h-3.5') ?></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($editable && can('liquidaciones.crear')): ?>
        <form method="post" class="p-5 border-t border-slate-100 bg-slate-50 space-y-3"
              x-data="{tipo:'flete', alCosto:'1', tipos: <?= e(json_encode(array_map(fn($t) => $t['al_costo'], liq_tipos_gasto()))) ?>}">
          <?= csrf_field() ?><input type="hidden" name="accion" value="gasto">
          <div>
            <label class="label" for="g_tipo">Tipo de gasto</label>
            <select id="g_tipo" name="tipo" x-model="tipo" @change="alCosto = String(tipos[tipo])" class="select">
              <?php foreach (liq_tipos_gasto() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div><label class="label" for="g_concepto">Concepto</label>
            <input type="text" id="g_concepto" name="concepto" maxlength="140" class="input" placeholder="Opcional: factura de la agencia"></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="g_monto">Monto *</label>
              <input type="number" step="0.01" min="0.01" id="g_monto" name="monto_moneda" required class="input"></div>
            <?php if ($monedas): ?>
              <div><label class="label" for="g_moneda">Moneda</label>
                <select id="g_moneda" name="gasto_moneda_id" class="select">
                  <?php foreach ($monedas as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= (int) ($m['es_base'] ?? 0) === 1 ? 'selected' : '' ?>><?= e($m['codigo']) ?></option>
                  <?php endforeach; ?>
                </select></div>
            <?php endif; ?>
          </div>
          <label class="flex items-start gap-2 text-sm text-slate-600 cursor-pointer">
            <input type="hidden" name="al_costo" value="0">
            <input type="checkbox" name="al_costo" value="1" :checked="alCosto==='1'" class="rounded border-slate-300 text-blue-600 mt-0.5">
            <span>Este gasto <strong>entra al costo</strong> de la mercancía</span>
          </label>
          <button class="btn btn-soft w-full"><?= icon('plus', 'w-4 h-4') ?> Agregar gasto</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal: confirmar aplicación -->
<?php if ($editable && can('liquidaciones.aplicar')): ?>
<div x-data="{open:false}" @liq:aplicar.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="aplicar">
        <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Aplicar la liquidación</h3></div>
        <div class="p-6 space-y-3 text-sm text-slate-600">
          <?php if ($liq['modo'] === 'entrada'): ?>
            <p>Van a <strong>entrar <?= qty($calc['unidades']) ?> unidades</strong> al almacén <strong><?= e($liq['sucursal']) ?></strong>
               con un costo total de <strong><?= money($calc['costo_total']) ?></strong>.</p>
          <?php else: ?>
            <p>Se va a <strong>corregir el costo</strong> de <strong><?= count($liq['detalles']) ?> producto(s)</strong>.
               La mercancía ya está en el inventario: <strong>no se moverá ni una unidad</strong>.</p>
          <?php endif; ?>
          <p>Cada producto quedará con su costo real en el catálogo. Las ventas ya emitidas conservan el costo con el que se facturaron.</p>
          <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-xs leading-relaxed">
            Este documento <strong>no registra la deuda al proveedor</strong> ni el pago de los gastos: eso se hace en
            Compras y en Cuentas por Pagar. Solo determina el costo.
          </div>
          <?php
          $sinLote = array_filter($liq['detalles'], fn($d) => !empty($d['controla_lote']) && empty($d['lote']));
          if ($liq['modo'] === 'entrada' && $sinLote): ?>
            <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-700 p-3 text-xs">
              <?= count($sinLote) ?> artículo(s) controlan lote y no tienen número: entrarán como «<?= e(defined('SAN_LOTE_SIN_IDENTIFICAR') ? SAN_LOTE_SIN_IDENTIFICAR : 'SIN-LOTE') ?>»
              y quedarán señalados en los reportes sanitarios.
            </div>
          <?php endif; ?>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-success"><?= icon('check', 'w-4 h-4') ?> Aplicar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
