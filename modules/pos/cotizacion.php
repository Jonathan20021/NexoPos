<?php
/**
 * Editor de una cotización: líneas, totales, PDF, envío y facturación.
 *
 * Las líneas se manejan en el navegador (Alpine) y se envían de una sola vez.
 * Los totales que se muestran son informativos: al guardar, el servidor los
 * vuelve a calcular con `cot_totales()`, que es la única fuente válida.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('cotizaciones.ver');

if (!cot_disponible()) { redirect('modules/pos/cotizaciones.php'); }

$id = (int) get('id');
$c  = cot_obtener($id);
if (!$c) { flash('error', 'Cotización no encontrada.'); redirect('modules/pos/cotizaciones.php'); }
if (!can_access_sucursal((int) $c['sucursal_id'])) {
    flash('error', 'Esta cotización es de otra sucursal.');
    redirect('modules/pos/cotizaciones.php');
}

$lineas   = cot_lineas($id);
$estados  = cot_estados();
$visible  = cot_estadoVisible($c);
$editable = cot_editable($c) && can('cotizaciones.crear');
$esBase   = (int) ($c['moneda_es_base'] ?? 1) === 1;

/* ---------- PDF (Dompdf) ---------- */
if (get('pdf') === '1' && function_exists('pdf_render')) {
    pdf_render(cot_pdf_html($c, $lineas), 'cotizacion_' . $c['numero'], 'landscape',
               get('descargar') === '1' ? 'download' : 'inline');
}

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    $volver = 'modules/pos/cotizacion.php?id=' . $id;

    if ($accion === 'guardar') {
        require_perm('cotizaciones.crear');
        try {
            $lineasIn = json_decode((string) post('lineas_json'), true);
            if (!is_array($lineasIn)) throw new RuntimeException('No se recibieron las líneas.');

            cot_guardar([
                'id'           => $id,
                'cliente_id'   => postInt('cliente_id'),
                'sucursal_id'  => (int) $c['sucursal_id'],
                'fecha'        => post('fecha'),
                'validez_dias' => postInt('validez_dias', 15),
                'moneda_id'    => postInt('moneda_id'),
                'tasa_cambio'  => postNum('tasa_cambio'),
                'descuento'    => postNum('descuento'),
                'condiciones'  => post('condiciones'),
                'notas'        => post('notas'),
            ], $lineasIn);

            audit('cotizaciones', 'editar', "Cotización {$c['numero']} actualizada", ['tabla' => 'cotizaciones', 'registro_id' => $id]);
            flash('success', 'Cotización guardada.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }

    if ($accion === 'estado') {
        require_perm('cotizaciones.crear');
        $nuevo = post('estado');
        if (in_array($nuevo, ['borrador', 'enviada', 'aceptada', 'rechazada'], true) && $c['estado'] !== 'facturada') {
            dbUpdate('cotizaciones', ['estado' => $nuevo, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit('cotizaciones', 'editar', "Cotización {$c['numero']}: $nuevo", ['tabla' => 'cotizaciones', 'registro_id' => $id]);
            flash('success', 'Estado actualizado a «' . ($estados[$nuevo][0] ?? $nuevo) . '».');
        }
        redirect($volver);
    }

    if ($accion === 'enviar') {
        require_perm('cotizaciones.crear');
        $r = cot_enviarCorreo($id);
        $r['ok']
            ? flash('success', 'Cotización enviada a ' . $c['cliente_email'] . ' con el PDF adjunto.')
            : flash('error', $r['error'] ?? 'No se pudo enviar.');
        redirect($volver);
    }

    if ($accion === 'facturar') {
        require_perm('cotizaciones.facturar');
        try {
            // Cantidades marcadas por línea. Sin nada marcado se factura todo,
            // que es el caso normal.
            $sel = [];
            foreach ((array) ($_POST['ret'] ?? []) as $detId => $cant) {
                $sel[(int) $detId] = (float) $cant;
            }
            $r = cot_facturar($id, postInt('metodo_pago_id') ?: 1, $sel ?: null);
            flash('success', 'Factura ' . $r['numero'] . ' generada' . ($r['ncf'] ? ' con NCF ' . $r['ncf'] : '') . '.'
                . (!empty($r['parcial']) ? ' Se facturó solo parte de lo cotizado; el resto queda registrado como no vendido.' : ''));
            redirect('modules/pos/ventas.php?q=' . urlencode($r['numero']));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }
}

/* ============================================================
 *  Datos de la pantalla
 * ============================================================ */
$clientes  = qAll("SELECT id, nombre, rnc_cedula, email, telefono FROM clientes WHERE activo = 1 ORDER BY nombre");
$monedasA  = monedas();
$metodos   = qAll("SELECT id, nombre FROM metodos_pago WHERE activo = 1 ORDER BY id");
$tasaItbis = (float) setting('itbis_tasa', DEFAULT_ITBIS);

// Catálogo para el buscador de productos del editor.
$catalogo = qAll(
    "SELECT p.id, p.codigo, p.nombre, p.precio_venta, p.itbis_aplica, p.tipo,
            COALESCE(st.cantidad, 0) AS stock
       FROM productos p
       LEFT JOIN inventario_stock st ON st.producto_id = p.id AND st.sucursal_id = ?
      WHERE p.activo = 1
      ORDER BY p.nombre
      LIMIT 2000", [(int) $c['sucursal_id']]
);

$lineasJs = array_map(fn($l) => [
    'producto_id'     => $l['producto_id'] ? (int) $l['producto_id'] : null,
    'descripcion'     => $l['descripcion'],
    'cantidad'        => (float) $l['cantidad'],
    'precio_unitario' => (float) $l['precio_unitario'],
    'descuento_pct'   => (float) ($l['descuento_pct'] ?? 0),
    'itbis_aplica'    => (float) $l['itbis'] > 0 ? 1 : 0,
    'es_servicio'     => (int) ($l['es_servicio'] ?? 0),
], $lineas);

$cotCfg      = cot_config();
$camposProp  = cot_campos();
$camposVal   = cot_camposValores($c);
$hayServicio = cot_productoServicio() !== null;

[$etEstado, $colEstado] = $estados[$visible] ?? ['—', 'slate'];
$telWa = function_exists('mkt_telefono') ? mkt_telefono($c['cliente_telefono']) : '';

$acciones = '<a href="' . e(url('modules/pos/cotizaciones.php')) . '" class="btn btn-ghost">'
          . icon('arrow-left', 'w-4 h-4') . ' Cotizaciones</a>'
          . '<a href="' . e(url('modules/pos/cotizacion.php?id=' . $id . '&pdf=1')) . '" target="_blank" class="btn btn-soft">'
          . icon('file', 'w-4 h-4') . ' Ver PDF</a>';

layout_start('Cotización ' . $c['numero'], $c['cliente'] . ' · ' . strtolower($etEstado), $acciones);
?>

<div class="grid lg:grid-cols-3 gap-5 items-start" x-data="editorCotizacion()">

  <!-- ============ Documento ============ -->
  <div class="lg:col-span-2 space-y-5">

    <?php if (!$editable): ?>
      <div class="card p-4 flex items-start gap-3 bg-slate-50">
        <?= icon('lock', 'w-5 h-5 text-slate-400 mt-0.5 shrink-0') ?>
        <p class="text-sm text-slate-600">
          <?php if ($c['estado'] === 'facturada'): ?>
            Esta cotización ya se convirtió en la factura <strong><?= e($c['venta_id'] ? (qVal("SELECT numero FROM ventas WHERE id=?", [(int) $c['venta_id']]) ?: '') : '') ?></strong>
            y no se puede editar: cambiarla dejaría un documento que no coincide con lo facturado.
            <?php
              // Lo que el cliente NO se llevó. Es información de negocio: dice qué
              // se ofreció y no se vendió.
              $sinVender = array_filter($lineas, fn($l) => (float) $l['cantidad'] - (float) ($l['cantidad_facturada'] ?? 0) > 0.0001);
            ?>
            <?php if ($sinVender): ?>
              <span class="block mt-2 font-semibold text-slate-700">No entró en la factura:</span>
              <ul class="mt-1 space-y-0.5">
                <?php foreach ($sinVender as $l): ?>
                  <li class="text-[13px] text-slate-500">
                    <?= e($l['descripcion']) ?> ·
                    <?= qty((float) $l['cantidad'] - (float) ($l['cantidad_facturada'] ?? 0)) ?> de <?= qty($l['cantidad']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php else: ?>
            No tienes permiso para editar cotizaciones.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <form method="post" class="card" @submit="prepararEnvio()">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="lineas_json" :value="JSON.stringify(lineas)">

      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
        <h2 class="font-bold text-slate-800 flex items-center gap-2"><?= icon('file', 'w-4 h-4 text-slate-400') ?> Documento</h2>
        <?= badge($etEstado, $colEstado) ?>
      </div>

      <div class="p-5 space-y-4" <?= $editable ? '' : 'style="opacity:.6;pointer-events:none"' ?>>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Cliente *</label>
            <select name="cliente_id" required class="select">
              <?php foreach ($clientes as $cl): ?>
                <option value="<?= (int) $cl['id'] ?>" <?= (int) $c['cliente_id'] === (int) $cl['id'] ? 'selected' : '' ?>>
                  <?= e($cl['nombre']) ?><?= $cl['rnc_cedula'] ? ' · ' . e($cl['rnc_cedula']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Fecha</label>
              <input type="date" name="fecha" value="<?= e($c['fecha']) ?>" class="input">
            </div>
            <div>
              <label class="label">Válida (días)</label>
              <input type="number" min="1" max="365" name="validez_dias" value="<?= (int) $c['validez_dias'] ?>" class="input">
            </div>
          </div>
        </div>

        <?php if (mon_hayExtranjeras()): ?>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Moneda</label>
              <select name="moneda_id" x-model.number="monedaId" @change="alCambiarMoneda()" class="select">
                <?php foreach ($monedasA as $m): ?>
                  <option value="<?= (int) $m['id'] ?>" data-tasa="<?= e((string) $m['tasa']) ?>"
                          data-simbolo="<?= e($m['simbolo']) ?>" data-base="<?= (int) $m['es_base'] ?>"
                          <?= (int) $c['moneda_id'] === (int) $m['id'] ? 'selected' : '' ?>>
                    <?= e($m['codigo']) ?> — <?= e($m['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div x-show="!esBase()">
              <label class="label">Tasa de cambio</label>
              <input type="number" step="0.0001" min="0.0001" name="tasa_cambio" x-model.number="tasa" class="input">
              <p class="text-xs text-slate-400 mt-1">Pesos por 1 unidad. Queda fija en el documento.</p>
            </div>
          </div>
        <?php else: ?>
          <input type="hidden" name="moneda_id" value="<?= (int) ($c['moneda_id'] ?: monedaBase()['id']) ?>">
          <input type="hidden" name="tasa_cambio" value="1">
        <?php endif; ?>

        <!-- Líneas -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="label mb-0">Productos y servicios</label>
            <button type="button" @click="agregar()" class="btn btn-soft btn-sm"><?= icon('plus', 'w-3.5 h-3.5') ?> Agregar línea</button>
          </div>

          <div class="overflow-x-auto border border-slate-200 rounded-xl">
            <table class="w-full text-sm">
              <thead class="bg-slate-50">
                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                  <th class="p-2.5 font-semibold">Descripción</th>
                  <th class="p-2.5 font-semibold text-right w-24">Cant.</th>
                  <th class="p-2.5 font-semibold text-right w-32">Precio</th>
                  <th class="p-2.5 font-semibold text-right w-20">Desc. %</th>
                  <th class="p-2.5 font-semibold text-center w-16">ITBIS</th>
                  <th class="p-2.5 font-semibold text-right w-32">Importe</th>
                  <th class="w-10"></th>
                </tr>
              </thead>
              <tbody>
                <template x-for="(l, i) in lineas" :key="i">
                  <tr class="border-t border-slate-100">
                    <td class="p-2">
                      <input type="text" x-model="l.descripcion" list="catalogoProd" @change="alElegirProducto(i, $event)"
                             class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-blue-500"
                             placeholder="Escribe o elige del catálogo">
                    </td>
                    <td class="p-2"><input type="number" step="0.001" min="0" x-model.number="l.cantidad"
                             class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-sm text-right"></td>
                    <td class="p-2"><input type="number" step="0.01" min="0" x-model.number="l.precio_unitario"
                             class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-sm text-right"></td>
                    <td class="p-2"><input type="number" step="0.01" min="0" max="100" x-model.number="l.descuento_pct"
                             class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-sm text-right"
                             placeholder="0"></td>
                    <?php /* El ITBIS lo decide el producto y la ley, no quien cotiza: se
                             muestra, no se edita. Ver cot_resolverItbis(). */ ?>
                    <td class="p-2 text-center">
                      <span class="text-xs font-semibold" :class="l.itbis_aplica ? 'text-slate-600' : 'text-slate-300'"
                            x-text="l.itbis_aplica ? 'Sí' : 'No'"
                            title="Lo determina el producto"></span>
                    </td>
                    <td class="p-2 text-right whitespace-nowrap">
                      <span class="font-semibold text-slate-700" x-text="fmt(netoLinea(l))"></span>
                      <span x-show="(Number(l.descuento_pct)||0) > 0" class="block text-[11px] text-slate-400 line-through"
                            x-text="fmt((Number(l.cantidad)||0) * (Number(l.precio_unitario)||0))"></span>
                    </td>
                    <td class="p-2 text-center">
                      <button type="button" @click="quitar(i)" class="text-slate-300 hover:text-rose-600" title="Quitar">✕</button>
                    </td>
                  </tr>
                </template>
                <tr x-show="!lineas.length"><td colspan="7" class="p-6 text-center text-slate-400 text-sm">Sin líneas. Pulsa «Agregar línea».</td></tr>
              </tbody>
            </table>
          </div>

          <datalist id="catalogoProd">
            <?php foreach ($catalogo as $p): ?>
              <option value="<?= e($p['nombre']) ?>" data-id="<?= (int) $p['id'] ?>"
                      data-precio="<?= e((string) $p['precio_venta']) ?>" data-itbis="<?= (int) $p['itbis_aplica'] ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <p class="text-xs text-slate-400 mt-2">
            Al elegir un producto del catálogo se trae su precio, pero puedes cambiarlo: una cotización es una oferta.
            El ITBIS lo determina el producto y no se edita aquí, para que el total cotizado y el facturado no puedan separarse.
            <?php if ($hayServicio): ?>
              Una línea escrita a mano se factura como servicio y no toca inventario.
            <?php else: ?>
              <span class="text-amber-600">Para poder facturar líneas escritas a mano, elige el producto de servicio en
              <a href="<?= e(url('modules/pos/cotizaciones.php?tab=ajustes')) ?>" class="underline">Ajustes</a>.</span>
            <?php endif; ?>
          </p>
        </div>

        <!-- Totales -->
        <div class="grid sm:grid-cols-2 gap-4 pt-2">
          <div class="space-y-4">
            <div>
              <label class="label">Descuento</label>
              <input type="number" step="0.01" min="0" name="descuento" x-model.number="descuento" class="input">
            </div>
            <div>
              <label class="label">Condiciones</label>
              <textarea name="condiciones" rows="3" class="input text-sm"
                        placeholder="Forma de pago, tiempo de entrega, garantía…"><?= e((string) $c['condiciones']) ?></textarea>
            </div>
            <div>
              <label class="label">Notas</label>
              <input type="text" name="notas" maxlength="500" value="<?= e((string) $c['notas']) ?>" class="input">
            </div>
            <?php /* Campos propios del negocio: se definen una vez en Ajustes y
                     salen en el documento. */ ?>
            <?php foreach ($camposProp as $cp): ?>
              <div>
                <label class="label"><?= e($cp['etiqueta']) ?></label>
                <input type="text" maxlength="180" class="input"
                       name="campos_extra[<?= e($cp['clave']) ?>]"
                       value="<?= e((string) ($camposVal[$cp['clave']] ?? '')) ?>">
              </div>
            <?php endforeach; ?>
          </div>

          <div class="rounded-xl bg-slate-50 p-4 h-fit">
            <div class="space-y-2 text-sm">
              <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span class="font-semibold" x-text="fmt(subtotal())"></span></div>
              <div class="flex justify-between" x-show="descuento > 0"><span class="text-slate-500">Descuento</span><span class="font-semibold text-rose-600" x-text="'-' + fmt(descuento)"></span></div>
              <div class="flex justify-between"><span class="text-slate-500">ITBIS (<?= rtrim(rtrim(number_format($tasaItbis, 2), '0'), '.') ?>%)</span><span class="font-semibold" x-text="fmt(itbis())"></span></div>
              <div class="flex justify-between pt-2 border-t border-slate-200 text-base">
                <span class="font-bold text-slate-700">Total</span>
                <span class="font-bold text-slate-900" x-text="fmt(total())"></span>
              </div>
              <p class="text-xs text-slate-400 text-right" x-show="!esBase()" x-text="'≈ <?= e(setting('moneda', 'RD$')) ?> ' + (total() * tasa).toLocaleString('en-US',{minimumFractionDigits:2, maximumFractionDigits:2})"></p>
            </div>
          </div>
        </div>
      </div>

      <?php if ($editable): ?>
        <div class="px-5 py-4 border-t border-slate-100 flex justify-end">
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar cotización</button>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ============ Acciones ============ -->
  <div class="space-y-5">

    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-4">Enviar al cliente</h3>

      <div class="space-y-2">
        <a href="<?= e(url('modules/pos/cotizacion.php?id=' . $id . '&pdf=1&descargar=1')) ?>" class="btn btn-soft w-full">
          <?= icon('download', 'w-4 h-4') ?> Descargar PDF
        </a>

        <?php if (can('cotizaciones.crear')): ?>
          <form method="post" onsubmit="return confirm('¿Enviar la cotización por correo a <?= e($c['cliente_email'] ?: 'el cliente') ?>?')">
            <?= csrf_field() ?><input type="hidden" name="accion" value="enviar">
            <button class="btn btn-primary w-full"
                    <?= (mail_configurado() && filter_var((string) $c['cliente_email'], FILTER_VALIDATE_EMAIL)) ? '' : 'disabled' ?>>
              <?= icon('mail', 'w-4 h-4') ?> Enviar por correo con PDF
            </button>
          </form>
          <?php if (!filter_var((string) $c['cliente_email'], FILTER_VALIDATE_EMAIL)): ?>
            <p class="text-xs text-amber-600">Este cliente no tiene correo en su ficha.</p>
          <?php elseif (!mail_configurado()): ?>
            <p class="text-xs text-amber-600">Falta configurar el correo del sistema.</p>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($telWa !== ''): ?>
          <a href="<?= e(mkt_wa_link($telWa, cot_textoWhatsapp($c))) ?>" target="_blank" rel="noopener" class="btn btn-success w-full">
            <?= icon('phone', 'w-4 h-4') ?> Avisar por WhatsApp
          </a>
          <p class="text-xs text-slate-400">Abre el chat con el mensaje escrito. El PDF se adjunta a mano o se manda por correo.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($c['estado'] !== 'facturada' && can('cotizaciones.crear')): ?>
      <div class="card p-5">
        <h3 class="font-bold text-slate-800 mb-1">¿Qué dijo el cliente?</h3>
        <p class="text-sm text-slate-500 mb-4">Marcarlo aquí es lo que alimenta la tasa de cierre.</p>
        <div class="grid grid-cols-2 gap-2">
          <?php foreach (['aceptada' => ['Aceptó', 'btn-success'], 'rechazada' => ['Rechazó', 'btn-ghost'],
                          'enviada' => ['Enviada', 'btn-soft'], 'borrador' => ['Borrador', 'btn-ghost']] as $v => [$lbl, $cls]): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="estado" value="<?= e($v) ?>">
              <button class="btn <?= $cls ?> w-full btn-sm" <?= $c['estado'] === $v ? 'disabled' : '' ?>><?= e($lbl) ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($c['estado'] !== 'facturada' && can('cotizaciones.facturar')): ?>
      <div class="card p-5">
        <h3 class="font-bold text-slate-800 mb-1">Convertir en factura</h3>
        <p class="text-sm text-slate-500 mb-4">
          Genera la venta con NCF, descuenta el stock y respeta los precios cotizados,
          aunque la lista haya cambiado.
        </p>
        <?php /* Rara vez el cliente se lleva todo. Aquí se marca lo que sí, con
                 su cantidad; lo que quede en cero no se factura y queda escrito
                 como no vendido. Ver cot_facturar(). */ ?>
        <form method="post" class="space-y-3" x-data="facturarParcial()"
              @submit="return confirmar($event)">
          <?= csrf_field() ?><input type="hidden" name="accion" value="facturar">

          <div class="rounded-xl border border-slate-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-slate-50 border-b border-slate-200">
              <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Qué se lleva</span>
              <button type="button" @click="todo()" class="text-xs font-semibold text-blue-600 hover:underline">Todo</button>
            </div>
            <table class="w-full text-sm">
              <tbody>
                <?php foreach ($lineas as $l): ?>
                  <tr class="border-b border-slate-100 last:border-0">
                    <td class="px-3 py-2">
                      <div class="text-slate-700 leading-tight"><?= e($l['descripcion']) ?></div>
                      <div class="text-[11px] text-slate-400">cotizadas <?= qty($l['cantidad']) ?></div>
                    </td>
                    <td class="px-3 py-2 w-28">
                      <input type="number" step="0.001" min="0" max="<?= e((string) (float) $l['cantidad']) ?>"
                             name="ret[<?= (int) $l['id'] ?>]" value="<?= e((string) (float) $l['cantidad']) ?>"
                             data-max="<?= e((string) (float) $l['cantidad']) ?>"
                             class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-sm text-right">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div>
            <label class="label">Forma de pago</label>
            <select name="metodo_pago_id" class="select">
              <?php foreach ($metodos as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-primary w-full"><?= icon('receipt', 'w-4 h-4') ?> Facturar</button>
          <p class="text-xs text-slate-400">
            Al facturar, la cotización se cierra. Lo que no se lleve queda registrado como no vendido.
          </p>
        </form>
        <script>
          function facturarParcial() {
            return {
              todo() {
                this.$root.querySelectorAll('input[name^="ret["]')
                  .forEach(i => { i.value = i.dataset.max; });
              },
              confirmar(ev) {
                const campos = [...this.$root.querySelectorAll('input[name^="ret["]')];
                const suma = campos.reduce((s, i) => s + (Number(i.value) || 0), 0);
                if (suma <= 0) {
                  ev.preventDefault();
                  alert('Marca al menos una línea con cantidad para facturar.');
                  return false;
                }
                const parcial = campos.some(i => (Number(i.value) || 0) < Number(i.dataset.max));
                const aviso = parcial
                  ? 'El cliente NO se lleva todo lo cotizado. Se facturará solo lo marcado y la cotización se cerrará. ¿Continuar?'
                  : 'Se generará una factura real con NCF y se descontará el stock. ¿Continuar?';
                if (!confirm(aviso)) { ev.preventDefault(); return false; }
                return true;
              },
            };
          }
        </script>
        <?php if (!$esBase): ?>
          <p class="text-xs text-slate-400 mt-3">
            La factura sale en pesos a la tasa de esta cotización
            (<?= e(rtrim(rtrim(number_format((float) $c['tasa_cambio'], 4), '0'), '.')) ?>), que es la que se le prometió al cliente.
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card p-5">
      <h3 class="font-bold text-slate-800 mb-3">Resumen</h3>
      <div class="space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-slate-500">Número</span><span class="font-semibold"><?= e($c['numero']) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Vence</span><span class="font-semibold <?= $visible === 'vencida' ? 'text-rose-600' : '' ?>"><?= e(fechaCorta($c['vence'])) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Sucursal</span><span class="font-semibold"><?= e($c['sucursal']) ?></span></div>
        <?php if ($c['enviada_at']): ?>
          <div class="flex justify-between"><span class="text-slate-500">Enviada</span><span class="font-semibold"><?= e(fechaCorta($c['enviada_at'])) ?></span></div>
        <?php endif; ?>
        <div class="flex justify-between pt-2 border-t border-slate-100">
          <span class="text-slate-500">Total</span>
          <span class="font-bold text-slate-800">
            <?= $esBase ? e(money((float) $c['total'])) : e($c['moneda_simbolo'] . ' ' . number_format((float) $c['total'], 2)) ?>
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  function editorCotizacion() {
    return {
      lineas: <?= json_encode($lineasJs, JSON_UNESCAPED_UNICODE) ?>,
      descuento: <?= (float) $c['descuento'] ?>,
      monedaId: <?= (int) ($c['moneda_id'] ?: monedaBase()['id']) ?>,
      tasa: <?= (float) $c['tasa_cambio'] ?>,
      simbolo: '<?= e($esBase ? setting('moneda', 'RD$') : $c['moneda_simbolo']) ?>',
      base: <?= (int) monedaBase()['id'] ?>,
      itbisTasa: <?= $tasaItbis ?>,

      esBase() { return Number(this.monedaId) === this.base; },
      hayServicio: <?= $hayServicio ? 'true' : 'false' ?>,

      agregar() { this.lineas.push({ producto_id: null, descripcion: '', cantidad: 1, precio_unitario: 0, descuento_pct: 0, itbis_aplica: 1, es_servicio: 0 }); },
      quitar(i) { this.lineas.splice(i, 1); },

      // Al escribir/elegir un nombre del catálogo se enlaza el producto y se
      // trae su precio. Enlazarlo importa: sin producto no se puede facturar.
      alElegirProducto(i, ev) {
        const op = [...document.querySelectorAll('#catalogoProd option')]
          .find(o => o.value === ev.target.value);
        if (!op) {
          // Sin producto del catálogo es un concepto libre: se factura como
          // servicio y sigue el ITBIS del producto de servicio configurado.
          this.lineas[i].producto_id = null;
          this.lineas[i].es_servicio = 1;
          return;
        }
        this.lineas[i].producto_id = Number(op.dataset.id);
        this.lineas[i].es_servicio = 0;
        this.lineas[i].itbis_aplica = Number(op.dataset.itbis) ? 1 : 0;
        if (!this.lineas[i].precio_unitario) {
          const precio = Number(op.dataset.precio) || 0;
          // El catálogo está en pesos: si la cotización va en otra moneda, se convierte.
          this.lineas[i].precio_unitario = this.esBase()
            ? precio
            : Math.round((precio / (this.tasa || 1)) * 100) / 100;
        }
      },

      alCambiarMoneda() {
        const op = document.querySelector(`select[name=moneda_id] option[value="${this.monedaId}"]`);
        if (!op) return;
        this.tasa = Number(op.dataset.tasa) || 1;
        this.simbolo = op.dataset.simbolo || 'RD$';
      },

      // Importe de la línea ya rebajado. Es lo que suma al subtotal y sobre lo
      // que se calcula el ITBIS: se tributa sobre lo que se cobra.
      netoLinea(l) {
        const base = (Number(l.cantidad) || 0) * (Number(l.precio_unitario) || 0);
        const pct  = Math.min(100, Math.max(0, Number(l.descuento_pct) || 0));
        return Math.round((base - base * pct / 100) * 100) / 100;
      },
      descuentoLineas() {
        return this.lineas.reduce((s, l) =>
          s + ((Number(l.cantidad) || 0) * (Number(l.precio_unitario) || 0) - this.netoLinea(l)), 0);
      },
      subtotal() { return this.lineas.reduce((s, l) => s + this.netoLinea(l), 0); },
      itbis() {
        const bruto = this.lineas.reduce((s, l) =>
          s + (l.itbis_aplica ? this.netoLinea(l) * this.itbisTasa / 100 : 0), 0);
        const st = this.subtotal();
        const factor = st > 0 ? (st - Math.min(this.descuento || 0, st)) / st : 1;
        return bruto * factor;
      },
      total() { return this.subtotal() - Math.min(this.descuento || 0, this.subtotal()) + this.itbis(); },
      fmt(v) { return this.simbolo + ' ' + (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

      prepararEnvio() { this.lineas = this.lineas.filter(l => Number(l.cantidad) > 0 && String(l.descripcion).trim() !== ''); }
    };
  }
</script>

<?php layout_end(); ?>
