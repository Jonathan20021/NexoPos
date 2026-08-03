<?php
/**
 * Cotizaciones: listado.
 *
 * Crear pide lo mínimo (cliente y moneda) y lleva al editor, igual que las
 * campañas: un formulario largo como puerta de entrada hace que nadie cotice.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('cotizaciones.ver');

if (!cot_disponible()) {
    layout_start('Cotizaciones', 'Falta aplicar la migración');
    echo '<div class="card p-8 text-center">' . icon('alert', 'w-10 h-10 text-amber-500 mx-auto mb-3')
       . '<h3 class="font-bold text-slate-700">Módulo no disponible todavía</h3>'
       . '<p class="text-sm text-slate-500 mt-1">Aplica <code class="bg-slate-100 px-1.5 py-0.5 rounded">database/migracion_cxp_monedas_cotizaciones_p11.sql</code>.</p></div>';
    layout_end();
    exit;
}

$estados = cot_estados();

/* ============================================================
 *  Acciones (POST · PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'crear') {
        require_perm('cotizaciones.crear');
        try {
            $clienteId = postInt('cliente_id');
            if (!$clienteId) throw new RuntimeException('Selecciona el cliente.');

            $monedaId = postInt('moneda_id') ?: (int) monedaBase()['id'];
            $id = cot_guardar([
                'cliente_id'   => $clienteId,
                'moneda_id'    => $monedaId,
                'tasa_cambio'  => mon_tasa($monedaId),
                'validez_dias' => postInt('validez_dias', 15),
                'fecha'        => date('Y-m-d'),
                'sucursal_id'  => current_sucursal_id(),
                'condiciones'  => setting('cot_condiciones', "Precios sujetos a disponibilidad.\nForma de pago: a convenir."),
            ], [[
                // Una línea vacía para arrancar: el editor la completa.
                'descripcion' => 'Producto o servicio', 'cantidad' => 1, 'precio_unitario' => 0, 'itbis_aplica' => 1,
            ]]);

            audit('cotizaciones', 'crear', 'Cotización creada', ['tabla' => 'cotizaciones', 'registro_id' => $id]);
            flash('success', 'Cotización creada. Agrega los productos.');
            redirect('modules/pos/cotizacion.php?id=' . $id);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/cotizaciones.php');
    }

    if ($accion === 'duplicar') {
        require_perm('cotizaciones.crear');
        $c = cot_obtener(postInt('id'));
        if ($c) {
            $lineas = array_map(fn($l) => [
                'producto_id' => $l['producto_id'], 'descripcion' => $l['descripcion'],
                'cantidad' => $l['cantidad'], 'precio_unitario' => $l['precio_unitario'],
                'itbis_aplica' => (float) $l['itbis'] > 0 ? 1 : 0,
            ], cot_lineas((int) $c['id']));

            $nid = cot_guardar([
                'cliente_id' => (int) $c['cliente_id'], 'moneda_id' => (int) $c['moneda_id'],
                'tasa_cambio' => mon_tasa((int) $c['moneda_id']), 'validez_dias' => (int) $c['validez_dias'],
                'fecha' => date('Y-m-d'), 'sucursal_id' => (int) $c['sucursal_id'],
                'descuento' => (float) $c['descuento'], 'condiciones' => $c['condiciones'], 'notas' => $c['notas'],
            ], $lineas);

            audit('cotizaciones', 'crear', "Cotización duplicada de {$c['numero']}", ['tabla' => 'cotizaciones', 'registro_id' => $nid]);
            flash('success', 'Cotización duplicada.');
            redirect('modules/pos/cotizacion.php?id=' . $nid);
        }
        redirect('modules/pos/cotizaciones.php');
    }

    if ($accion === 'eliminar') {
        require_perm('cotizaciones.eliminar');
        $id = postInt('id');
        $c = qOne("SELECT numero, estado FROM cotizaciones WHERE id = ?", [$id]);
        if ($c && $c['estado'] === 'facturada') {
            flash('error', 'Una cotización ya facturada no se elimina: queda como historial de la factura.');
        } elseif ($c) {
            q("DELETE FROM cotizaciones WHERE id = ?", [$id]);   // las líneas caen por FK
            audit('cotizaciones', 'eliminar', "Cotización eliminada: {$c['numero']}", ['tabla' => 'cotizaciones', 'registro_id' => $id]);
            flash('success', 'Cotización eliminada.');
        }
        redirect('modules/pos/cotizaciones.php');
    }
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q       = trim(get('q'));
$fEstado = array_key_exists(get('estado'), $estados) ? get('estado') : '';

[$scope, $scopeP] = sucursalScope('c.sucursal_id');
$cond = [$scope]; $params = $scopeP;
if ($q !== '')       { $cond[] = "(c.numero LIKE ? OR cl.nombre LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($fEstado !== '') { $cond[] = "c.estado = ?"; $params[] = $fEstado; }
$where = implode(' AND ', $cond);

$cotizaciones = qAll(
    "SELECT c.*, cl.nombre AS cliente, mo.codigo AS moneda_codigo, mo.simbolo AS moneda_simbolo, mo.es_base AS moneda_es_base,
            v.numero AS venta_numero
       FROM cotizaciones c
       JOIN clientes cl     ON cl.id = c.cliente_id
       LEFT JOIN monedas mo ON mo.id = c.moneda_id
       LEFT JOIN ventas v   ON v.id = c.venta_id
      WHERE $where
      ORDER BY c.id DESC
      LIMIT 300", $params
);

// Cifras del encabezado (mismo alcance de sucursal).
$kpi = qOne(
    "SELECT COUNT(*) total,
            COALESCE(SUM(CASE WHEN c.estado IN ('borrador','enviada') AND c.vence >= CURDATE() THEN c.total_base ELSE 0 END), 0) abiertas,
            COALESCE(SUM(CASE WHEN c.estado = 'facturada' THEN c.total_base ELSE 0 END), 0) ganado,
            SUM(c.estado = 'facturada') n_facturadas,
            SUM(c.estado IN ('borrador','enviada') AND c.vence >= CURDATE()) n_abiertas
       FROM cotizaciones c WHERE $scope", $scopeP
) ?: [];

$tasaCierre = (int) ($kpi['total'] ?? 0) > 0
    ? round((int) ($kpi['n_facturadas'] ?? 0) * 100 / (int) $kpi['total'], 1) : 0.0;

$clientes = qAll("SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre");
$monedasA = monedas();

if (export_solicitado()) {
    export_tabla('cotizaciones',
        ['Número', 'Cliente', 'Fecha', 'Vence', 'Moneda', 'Total', 'Total RD$', 'Estado'],
        array_map(fn($c) => [$c['numero'], $c['cliente'], $c['fecha'], $c['vence'],
                             $c['moneda_codigo'], $c['total'], $c['total_base'],
                             $estados[cot_estadoVisible($c)][0] ?? $c['estado']], $cotizaciones),
        'Cotizaciones');
}

$acciones = can('cotizaciones.crear') ? btn_nuevo('cot:new', 'Nueva cotización') : '';
layout_start('Cotizaciones', 'La oferta que va antes de la factura', $acciones);
?>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-5">
  <?php
  $tarjetas = [
      ['Cotizaciones', number_format((int) ($kpi['total'] ?? 0)), 'file', 'slate', 'en total'],
      ['Abiertas', money((float) ($kpi['abiertas'] ?? 0)), 'clock', 'sky', number_format((int) ($kpi['n_abiertas'] ?? 0)) . ' esperando respuesta'],
      ['Ganado', money((float) ($kpi['ganado'] ?? 0)), 'check', 'emerald', number_format((int) ($kpi['n_facturadas'] ?? 0)) . ' facturadas'],
      ['Tasa de cierre', $tasaCierre . '%', 'trending', 'violet', 'de cotizado a facturado'],
  ];
  foreach ($tarjetas as [$lbl, $val, $ic, $col, $sub]): ?>
    <div class="card p-5">
      <div class="flex items-start justify-between mb-3">
        <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold"><?= e($lbl) ?></p>
        <div class="w-9 h-9 rounded-xl bg-<?= $col ?>-50 text-<?= $col ?>-600 flex items-center justify-center"><?= icon($ic, 'w-4 h-4') ?></div>
      </div>
      <p class="text-2xl font-bold text-slate-800"><?= e($val) ?></p>
      <p class="text-xs text-slate-400 mt-1"><?= e($sub) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por número o cliente...', $fEstado !== '' ? ['estado' => $fEstado] : []) ?>
    <div class="flex items-center gap-1.5 flex-wrap">
      <a href="<?= e(url('modules/pos/cotizaciones.php')) ?>"
         class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $fEstado === '' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">Todas</a>
      <?php foreach ($estados as $v => [$l, $col]): ?>
        <a href="<?= e(url('modules/pos/cotizaciones.php?estado=' . $v)) ?>"
           class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $fEstado === $v ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$cotizaciones): ?>
    <?= empty_state('Sin cotizaciones', 'Prepara una oferta con precios y vigencia, y mándasela al cliente en PDF.', 'file',
        can('cotizaciones.crear') ? btn_nuevo('cot:new', 'Nueva cotización') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Número</th><th>Cliente</th><th>Fecha</th><th>Vence</th>
          <th class="text-right">Total</th><th class="text-center">Estado</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($cotizaciones as $c):
            $vis = cot_estadoVisible($c);
            [$et, $col] = $estados[$vis] ?? ['—', 'slate'];
            $esBase = (int) ($c['moneda_es_base'] ?? 1) === 1;
            $diasP  = (int) ((strtotime($c['vence']) - time()) / 86400);
          ?>
            <tr>
              <td>
                <a href="<?= e(url('modules/pos/cotizacion.php?id=' . (int) $c['id'])) ?>" class="font-semibold text-slate-700 hover:text-blue-600">
                  <?= e($c['numero']) ?>
                </a>
                <?php if ($c['venta_numero']): ?>
                  <p class="text-[11px] text-indigo-600 font-semibold">→ <?= e($c['venta_numero']) ?></p>
                <?php endif; ?>
              </td>
              <td class="text-slate-600"><?= e($c['cliente']) ?></td>
              <td class="text-slate-500 text-sm whitespace-nowrap"><?= e(fechaCorta($c['fecha'])) ?></td>
              <td class="text-sm whitespace-nowrap">
                <span class="<?= $vis === 'vencida' ? 'text-rose-500 font-semibold' : 'text-slate-500' ?>"><?= e(fechaCorta($c['vence'])) ?></span>
                <?php if (in_array($vis, ['borrador', 'enviada'], true) && $diasP >= 0 && $diasP <= 3): ?>
                  <span class="block text-[11px] text-amber-600 font-semibold">vence en <?= $diasP ?> día(s)</span>
                <?php endif; ?>
              </td>
              <td class="text-right font-bold text-slate-700 whitespace-nowrap">
                <?= $esBase ? e(money((float) $c['total'])) : e($c['moneda_simbolo'] . ' ' . number_format((float) $c['total'], 2)) ?>
                <?php if (!$esBase): ?>
                  <span class="block text-[11px] font-normal text-slate-400"><?= e(money((float) $c['total_base'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-center"><?= badge($et, $col) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="<?= e(url('modules/pos/cotizacion.php?id=' . (int) $c['id'] . '&pdf=1')) ?>" target="_blank"
                     class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Descargar PDF"><?= icon('file', 'w-4 h-4') ?></a>
                  <a href="<?= e(url('modules/pos/cotizacion.php?id=' . (int) $c['id'])) ?>"
                     class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Abrir"><?= icon('eye', 'w-4 h-4') ?></a>
                  <?php if (can('cotizaciones.crear')): ?>
                    <form method="post" class="inline">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="duplicar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50" title="Duplicar"><?= icon('layers', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if (can('cotizaciones.eliminar') && $c['estado'] !== 'facturada'): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la cotización <?= e($c['numero']) ?>?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (can('cotizaciones.crear')): ?>
<div x-data="{open:false}" @cot:new.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear">

        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nueva cotización</h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>

        <div class="p-6 space-y-4">
          <div>
            <label class="label">Cliente *</label>
            <select name="cliente_id" required class="select">
              <option value="">Selecciona…</option>
              <?php foreach ($clientes as $cl): ?><option value="<?= (int) $cl['id'] ?>"><?= e($cl['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Moneda</label>
              <select name="moneda_id" class="select">
                <?php foreach ($monedasA as $m): ?>
                  <option value="<?= (int) $m['id'] ?>"><?= e($m['codigo']) ?> — <?= e($m['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label">Válida por</label>
              <div class="flex items-center gap-2">
                <input type="number" min="1" max="365" name="validez_dias" value="15" class="input">
                <span class="text-sm text-slate-400">días</span>
              </div>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('arrow-right', 'w-4 h-4') ?> Crear y agregar productos</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
