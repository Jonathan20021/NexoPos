<?php
/**
 * Liquidación de importaciones — listado de embarques.
 *
 * Aquí se ve, de un golpe, qué mercancía viene en camino, qué ya llegó y está
 * pendiente de costear, y con cuánto recargo real entró la que ya se aplicó.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('liquidaciones.ver');

if (!liq_disponible()) {
    layout_start('Liquidación de importaciones', 'Costo real de la mercancía puesta en almacén');
    echo empty_state('Falta aplicar la migración',
        'Ejecuta database/migracion_tiendas_p16.sql para habilitar el costeo de importaciones.', 'alert');
    layout_end();
    return;
}

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'crear') {
        require_perm('liquidaciones.crear');
        $sucursalId = postInt('sucursal_id');
        if (!can_access_sucursal($sucursalId) || !qVal("SELECT 1 FROM sucursales WHERE id=? AND activo=1", [$sucursalId])) {
            flash('error', 'Selecciona un almacén válido.');
            redirect('modules/inventario/liquidaciones.php');
        }
        $modo      = post('modo') === 'recosteo' ? 'recosteo' : 'entrada';
        $compraId  = postInt('compra_id') ?: null;
        if ($modo === 'recosteo' && !$compraId) {
            flash('error', 'El modo «recostear una compra» necesita que elijas la compra ya registrada.');
            redirect('modules/inventario/liquidaciones.php');
        }
        if ($modo === 'entrada') $compraId = null;

        $tiendaId = tiendas_disponible() ? (postInt('tienda_id') ?: null) : null;
        if ($tiendaId && !array_key_exists($tiendaId, tiendas_opciones())) $tiendaId = null;

        $monedaId = mon_disponible() ? (postInt('moneda_id') ?: null) : null;
        $tasa     = $monedaId ? mon_tasa($monedaId) : 1.0;
        $tasaPost = postNum('tasa_cambio');
        if ($tasaPost > 0) $tasa = $tasaPost;

        $id = tx(function () use ($sucursalId, $modo, $compraId, $tiendaId, $monedaId, $tasa) {
            return dbInsert('liquidaciones', [
                'numero'        => nextNumero('liquidaciones', 'numero', 'LIQ'),
                'tienda_id'     => $tiendaId,
                'sucursal_id'   => $sucursalId,
                'proveedor_id'  => postInt('proveedor_id') ?: null,
                'compra_id'     => $compraId,
                'modo'          => $modo,
                'referencia'    => trim(post('referencia')) ?: null,
                'fecha'         => liq_fecha(post('fecha'), date('Y-m-d')),
                'fecha_llegada' => liq_fecha(post('fecha_llegada')),
                'moneda_id'     => $monedaId,
                'tasa_cambio'   => $tasa,
                'prorrateo'     => array_key_exists(post('prorrateo'), liq_prorrateos()) ? post('prorrateo') : 'valor',
                'estado'        => 'borrador',
                'usuario_id'    => (int) current_user()['id'],
            ]);
        });

        // Recostear una compra ya registrada: se copian sus líneas: es
        // exactamente la mercancía que entró, y volver a teclearla es la vía
        // rápida a un costo que no cuadra con el inventario.
        if ($modo === 'recosteo' && $compraId) {
            $lineas = qAll("SELECT producto_id, cantidad, costo_unitario FROM compra_detalles WHERE compra_id = ?", [$compraId]);
            $tasaC  = (float) (qVal("SELECT tasa_cambio FROM compras WHERE id = ?", [$compraId]) ?: 1);
            foreach ($lineas as $l) {
                dbInsert('liquidacion_detalles', [
                    'liquidacion_id' => $id,
                    'producto_id'    => (int) $l['producto_id'],
                    'cantidad'       => (float) $l['cantidad'],
                    // La compra guarda el costo ya en pesos; se refleja también en
                    // la moneda del embarque para que la ficha se lea igual.
                    'costo_moneda'   => $tasaC > 0 ? round((float) $l['costo_unitario'] / $tasaC, 4) : (float) $l['costo_unitario'],
                    'costo_fob'      => (float) $l['costo_unitario'],
                ]);
            }
            liq_recalcular($id);
        }

        audit('liquidaciones', 'crear', 'Liquidación creada', ['tabla' => 'liquidaciones', 'registro_id' => $id]);
        flash('success', 'Liquidación creada. Agrega las líneas del embarque y sus gastos.');
        redirect('modules/inventario/liquidacion.php?id=' . $id);
    }
}

// ---------- Filtros y listado ----------
$estado = get('estado');
if (!array_key_exists($estado, liq_estados())) $estado = '';
$busq = trim(get('q'));

[$scopeSuc, $paramsSuc] = sucursalScope('l.sucursal_id');
$cond = [$scopeSuc];
$params = $paramsSuc;
if ($estado !== '') { $cond[] = 'l.estado = ?'; $params[] = $estado; }
if ($busq !== '')   { $cond[] = '(l.numero LIKE ? OR l.referencia LIKE ?)'; $params[] = "%$busq%"; $params[] = "%$busq%"; }
$tieFiltro = tiendaFiltroActual();
if ($tieFiltro) { $cond[] = 'l.tienda_id = ?'; $params[] = $tieFiltro; }
$where = 'WHERE ' . implode(' AND ', $cond);

$filas = qAll(
    "SELECT l.*, s.nombre AS sucursal, pr.nombre AS proveedor, t.nombre AS tienda,
            (SELECT COUNT(*) FROM liquidacion_detalles d WHERE d.liquidacion_id = l.id) AS lineas,
            (SELECT COALESCE(SUM(d.cantidad),0) FROM liquidacion_detalles d WHERE d.liquidacion_id = l.id) AS unidades
       FROM liquidaciones l
       JOIN sucursales s ON s.id = l.sucursal_id
       LEFT JOIN proveedores pr ON pr.id = l.proveedor_id
       LEFT JOIN tiendas t ON t.id = l.tienda_id
       $where
      ORDER BY FIELD(l.estado,'borrador','transito','aplicada','anulada'), l.fecha DESC, l.id DESC
      LIMIT 200",
    $params
);

// Tarjetas de cabecera: lo que la dirección quiere saber sin abrir nada.
$res = liq_resumen($tieFiltro);

$sucursales = sucursales_visibles();
$proveedores = qAll("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");
$comprasRecientes = qAll(
    "SELECT c.id, c.numero, c.fecha, c.total, p.nombre AS proveedor
       FROM compras c LEFT JOIN proveedores p ON p.id = c.proveedor_id
      WHERE c.estado = 'recibida'
        AND NOT EXISTS (SELECT 1 FROM liquidaciones l WHERE l.compra_id = c.id AND l.estado <> 'anulada')
      ORDER BY c.fecha DESC, c.id DESC LIMIT 60"
);
$monedas = mon_disponible() ? monedas() : [];

$acciones = can('liquidaciones.crear') ? btn_nuevo('liq:new', 'Nuevo embarque') : '';
layout_start('Liquidación de importaciones', 'El costo real de la mercancía puesta en almacén', $acciones);
?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-4">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">En tránsito</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= number_format($res['transito']) ?></p>
    <p class="text-xs text-slate-400 mt-0.5"><?= money($res['transito_valor']) ?> en camino</p>
  </div>
  <div class="card p-4">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Borradores</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= number_format($res['borradores']) ?></p>
    <p class="text-xs text-slate-400 mt-0.5">Pendientes de aplicar</p>
  </div>
  <div class="card p-4">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Costeado este mes</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= money($res['costo_mes']) ?></p>
    <p class="text-xs text-slate-400 mt-0.5"><?= number_format($res['aplicadas_mes']) ?> embarque(s)</p>
  </div>
  <div class="card p-4">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Recargo sobre el FOB</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1 tabular-nums"><?= number_format($res['recargo_pct'], 1) ?>%</p>
    <p class="text-xs text-slate-400 mt-0.5">Cuánto encarece traerla</p>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <div class="flex items-center gap-2 flex-wrap">
      <?= search_box('Buscar por número o referencia (BL, contenedor)...') ?>
      <form method="get" class="flex items-center gap-2">
        <?php if ($busq !== ''): ?><input type="hidden" name="q" value="<?= e($busq) ?>"><?php endif; ?>
        <select name="estado" onchange="this.form.submit()" class="select w-44" aria-label="Filtrar por estado">
          <option value="">Todos los estados</option>
          <?php foreach (liq_estados() as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $estado === $k ? 'selected' : '' ?>><?= e($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= selectTiendaFiltro() ?>
      </form>
    </div>
    <span class="text-sm text-slate-400"><?= count($filas) ?> embarque(s)</span>
  </div>

  <?php if (!$filas): ?>
    <?= empty_state('Sin embarques registrados',
        'Registra el embarque que viene en camino con su factura FOB y sus gastos: el sistema calcula el costo real de cada unidad al llegar.',
        'truck', can('liquidaciones.crear') ? btn_nuevo('liq:new', 'Nuevo embarque') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Embarque</th><th>Proveedor</th><th>Almacén</th>
          <th class="text-center">Líneas</th>
          <th class="text-right">FOB</th><th class="text-right">Gastos</th>
          <th class="text-center">Recargo</th>
          <th class="text-right">Costo real</th>
          <th>Estado</th><th class="text-right"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($filas as $f):
            $recargo = (float) $f['fob'] > 0 ? (float) $f['gastos'] / (float) $f['fob'] * 100 : 0;
          ?>
            <tr>
              <td>
                <a href="<?= e(url('modules/inventario/liquidacion.php')) ?>?id=<?= (int) $f['id'] ?>" class="font-semibold text-blue-600 hover:text-blue-700"><?= e($f['numero']) ?></a>
                <p class="text-xs text-slate-400">
                  <?= fechaCorta($f['fecha']) ?>
                  <?= $f['referencia'] ? ' · ' . e($f['referencia']) : '' ?>
                  <?= $f['modo'] === 'recosteo' ? ' · recosteo' : '' ?>
                </p>
                <?php if (!empty($f['tienda_id'])): ?>
                  <p class="mt-0.5"><?= tienda_chip((int) $f['tienda_id'], 'text-[11px]') ?></p>
                <?php endif; ?>
              </td>
              <td class="text-slate-500 text-sm"><?= e($f['proveedor'] ?: '—') ?></td>
              <td class="text-slate-500 text-sm"><?= e($f['sucursal']) ?></td>
              <td class="text-center">
                <span class="badge badge-slate"><?= (int) $f['lineas'] ?></span>
                <p class="text-[11px] text-slate-400 mt-0.5"><?= qty($f['unidades']) ?> u.</p>
              </td>
              <td class="text-right tabular-nums"><?= money($f['fob'], false) ?></td>
              <td class="text-right tabular-nums text-slate-500"><?= money($f['gastos'], false) ?></td>
              <td class="text-center">
                <span class="badge <?= $recargo >= 40 ? 'badge-rose' : ($recargo >= 20 ? 'badge-amber' : 'badge-emerald') ?>">
                  <?= number_format($recargo, 1) ?>%
                </span>
              </td>
              <td class="text-right tabular-nums font-bold text-slate-800"><?= money($f['costo_total'], false) ?></td>
              <td><?= liq_badge($f['estado']) ?></td>
              <td class="text-right">
                <a href="<?= e(url('modules/inventario/liquidacion.php')) ?>?id=<?= (int) $f['id'] ?>"
                   class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 inline-flex" title="Abrir"><?= icon('chevron-right', 'w-4 h-4') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Modal: nuevo embarque -->
<?php if (can('liquidaciones.crear')): ?>
<div x-data="{open:false, modo:'entrada'}"
     @liq:new.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nuevo embarque</h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">

          <!-- Modo: es la decisión que cambia todo lo demás -->
          <div>
            <span class="label">¿Qué vas a hacer?</span>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-1">
              <label class="rounded-xl border p-3 cursor-pointer transition"
                     :class="modo==='entrada' ? 'border-blue-500 bg-blue-50/50 ring-1 ring-blue-200' : 'border-slate-200 hover:border-slate-300'">
                <input type="radio" name="modo" value="entrada" x-model="modo" class="sr-only">
                <p class="font-semibold text-sm text-slate-800">Entrar mercancía nueva</p>
                <p class="text-xs text-slate-500 mt-0.5">El embarque todavía no está en el inventario. Al aplicar, entra al costo real calculado aquí.</p>
              </label>
              <label class="rounded-xl border p-3 cursor-pointer transition"
                     :class="modo==='recosteo' ? 'border-blue-500 bg-blue-50/50 ring-1 ring-blue-200' : 'border-slate-200 hover:border-slate-300'">
                <input type="radio" name="modo" value="recosteo" x-model="modo" class="sr-only">
                <p class="font-semibold text-sm text-slate-800">Recostear una compra ya recibida</p>
                <p class="text-xs text-slate-500 mt-0.5">La mercancía ya entró y ahora llegaron los gastos. Solo se corrige el costo: no se mueve ni una unidad.</p>
              </label>
            </div>
          </div>

          <div x-show="modo==='recosteo'" x-cloak>
            <label class="label" for="liq_compra">Compra a recostear *</label>
            <select id="liq_compra" name="compra_id" class="select">
              <option value="">— Selecciona la compra —</option>
              <?php foreach ($comprasRecientes as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['numero']) ?> · <?= fechaCorta($c['fecha']) ?> · <?= e($c['proveedor'] ?: 'Sin proveedor') ?> · <?= money($c['total']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-slate-500">Se copiarán sus líneas y cantidades tal como entraron.</p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="label" for="liq_sucursal">Almacén de destino *</label>
              <select id="liq_sucursal" name="sucursal_id" required class="select">
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int) $s['id'] ?>" <?= current_sucursal_id() === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label" for="liq_proveedor">Proveedor</label>
              <select id="liq_proveedor" name="proveedor_id" class="select">
                <option value="">— Sin proveedor —</option>
                <?php foreach ($proveedores as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php if (tiendas_hay()): ?>
              <div>
                <label class="label" for="liq_tienda">Tienda (marca)</label>
                <select id="liq_tienda" name="tienda_id" class="select">
                  <option value="">— Sin marca —</option>
                  <?php foreach (tiendas_activas() as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <div>
              <label class="label" for="liq_ref">Referencia</label>
              <input type="text" id="liq_ref" name="referencia" maxlength="60" class="input" placeholder="BL / contenedor / DUA">
            </div>
            <div>
              <label class="label" for="liq_fecha">Fecha del embarque *</label>
              <input type="date" id="liq_fecha" name="fecha" value="<?= date('Y-m-d') ?>" required class="input">
            </div>
            <div>
              <label class="label" for="liq_llegada">Llegada (real o estimada)</label>
              <input type="date" id="liq_llegada" name="fecha_llegada" class="input">
            </div>
            <?php if ($monedas): ?>
              <div>
                <label class="label" for="liq_moneda">Moneda de la factura</label>
                <select id="liq_moneda" name="moneda_id" class="select">
                  <?php foreach ($monedas as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= (int) ($m['es_base'] ?? 0) === 1 ? 'selected' : '' ?>>
                      <?= e($m['codigo']) ?> — <?= e($m['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="label" for="liq_tasa">Tasa del día</label>
                <input type="number" step="0.0001" min="0" id="liq_tasa" name="tasa_cambio" class="input" placeholder="Vacío = tasa vigente">
                <p class="mt-1 text-xs text-slate-500">Se congela en el documento: el costo del pasado no cambia si mañana sube el dólar.</p>
              </div>
            <?php endif; ?>
            <div class="sm:col-span-2">
              <label class="label" for="liq_prorrateo">Repartir los gastos</label>
              <select id="liq_prorrateo" name="prorrateo" class="select">
                <?php foreach (liq_prorrateos() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
              </select>
              <p class="mt-1 text-xs text-slate-500">Se puede cambiar después: el reparto se recalcula solo.</p>
            </div>
          </div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('plus', 'w-4 h-4') ?> Crear embarque</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
