<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/tienda/_shell.php'; // wa_link() y wa_numero()
require_perm('pedidos.ver');

$emp = $GLOBALS['empresa'] ?: [];

if (isPost()) {
    verify_csrf();

    if (post('accion') === 'link') {
        require_perm('pedidos.gestionar');
        $id   = postInt('id');
        $link = trim(post('link_pago'));
        try {
            $p = qOne("SELECT * FROM pedidos WHERE id = ?", [$id]);
            if (!$p) throw new RuntimeException('Pedido no encontrado.');
            require_sucursal_access($p['sucursal_id']);
            if ($link !== '') {
                if (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link)) {
                    throw new RuntimeException('El link de pago debe ser una URL válida que empiece por https://');
                }
                if (mb_strlen($link) > 500) throw new RuntimeException('El link de pago es demasiado largo.');
            }
            dbUpdate('pedidos', [
                'link_pago' => $link ?: null,
                'link_pago_enviado_at' => $link ? date('Y-m-d H:i:s') : null,
            ], 'id = ?', [$id]);
            audit('pedidos', 'link', "Link de pago actualizado en {$p['numero']}", ['tabla' => 'pedidos', 'registro_id' => $id]);
            flash('success', $link ? "Link de pago guardado en {$p['numero']}. Ya puedes enviarlo por WhatsApp." : "Link de pago eliminado de {$p['numero']}.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/pedidos.php');
    }

    if (post('accion') === 'estado') {
        require_perm('pedidos.gestionar');
        $id = postInt('id');
        $nuevo = post('estado');
        $validos = ['pendiente', 'confirmado', 'listo', 'entregado', 'cancelado'];
        try {
            if (!in_array($nuevo, $validos, true)) throw new RuntimeException('Estado no válido.');
            $p = qOne("SELECT * FROM pedidos WHERE id = ?", [$id]);
            if (!$p) throw new RuntimeException('Pedido no encontrado.');
            require_sucursal_access($p['sucursal_id']);
            if ($p['estado'] === 'entregado') throw new RuntimeException('Un pedido entregado ya no cambia de estado.');
            dbUpdate('pedidos', ['estado' => $nuevo], 'id = ?', [$id]);
            audit('pedidos', 'estado', "Pedido {$p['numero']}: {$p['estado']} → $nuevo", ['tabla' => 'pedidos', 'registro_id' => $id]);
            flash('success', "Pedido {$p['numero']} marcado como $nuevo.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/pedidos.php');
    }
}

[$scope, $sp] = sucursalFiltro('p.sucursal_id');
$estado = in_array(get('estado'), ['pendiente', 'confirmado', 'listo', 'entregado', 'cancelado'], true) ? get('estado') : '';
$q = trim(get('q'));

$cond = [$scope];
$params = $sp;
if ($estado !== '') { $cond[] = "p.estado = ?"; $params[] = $estado; }
if ($q !== '')      { $cond[] = "(p.numero LIKE ? OR p.cliente_nombre LIKE ? OR p.cliente_telefono LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
$where = implode(' AND ', $cond);

$pedidos = qAll(
    "SELECT p.*, s.nombre AS sucursal,
            (SELECT COUNT(*) FROM pedido_detalles WHERE pedido_id = p.id) AS items
       FROM pedidos p JOIN sucursales s ON s.id = p.sucursal_id
      WHERE $where ORDER BY p.id DESC LIMIT 100",
    $params
);

$pendientes = (int) qVal("SELECT COUNT(*) FROM pedidos p WHERE $scope AND p.estado = 'pendiente'", $sp);

$estadoBadge = [
    'pendiente'  => ['Pendiente', 'bg-amber-50 text-amber-700 border-amber-200'],
    'confirmado' => ['Confirmado', 'bg-sky-50 text-sky-700 border-sky-200'],
    'listo'      => ['Listo', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'entregado'  => ['Entregado', 'bg-slate-50 text-slate-600 border-slate-200'],
    'cancelado'  => ['Cancelado', 'bg-rose-50 text-rose-700 border-rose-200'],
];

/**
 * Link de pago efectivo del pedido: el suyo propio, y si no tiene, el genérico
 * de la empresa. El del pedido siempre manda, porque lleva el monto de esa venta.
 */
function linkPagoPedido(array $p, array $emp): ?string
{
    return $p['link_pago'] ?: ($emp['link_pago'] ?? null) ?: null;
}

/** Mensaje de WhatsApp que la tienda envía al cliente. */
function mensajePedido(array $p, array $emp): string
{
    $saludo = "Hola {$p['cliente_nombre']}, te escribimos de " . ($emp['nombre'] ?? APP_NAME) . ".";
    $base = " Tu pedido {$p['numero']} por " . money($p['total']) . " está ";

    // Cada rama cierra su propia puntuación: así no se duplica el punto final.
    $estado = match ($p['estado']) {
        'confirmado' => 'confirmado.',
        'listo'      => 'listo para retirar en ' . $p['sucursal'] . '.',
        'entregado'  => 'entregado. ¡Gracias por tu compra!',
        'cancelado'  => 'cancelado.',
        default      => 'en proceso.',
    };

    $msg = $saludo . $base . $estado;
    $cerrado = in_array($p['estado'], ['entregado', 'cancelado'], true);
    $link = linkPagoPedido($p, $emp);

    if ($cerrado) {
        return $msg;
    }
    if ($p['metodo_pago'] === 'link_pago') {
        $msg .= $link
            ? " Puedes pagar " . money($p['total']) . " aquí: $link"
            : " En breve te enviamos el link de pago.";
    } else {
        $msg .= ' Pagas ' . money($p['total']) . ' al retirar.';
    }
    return $msg;
}

$acciones = '';
layout_start('Pedidos en línea', 'Órdenes recibidas desde la tienda pública', $acciones);
?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <p class="text-sm text-slate-500">Pendientes de confirmar</p>
    <p class="text-2xl font-extrabold <?= $pendientes ? 'text-amber-600' : 'text-slate-800' ?> mt-1"><?= number_format($pendientes) ?></p>
  </div>
  <div class="card p-5">
    <p class="text-sm text-slate-500">Pedidos listados</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-1"><?= number_format(count($pedidos)) ?></p>
  </div>
  <div class="card p-5 col-span-2">
    <p class="text-sm text-slate-500">Enlace público de la tienda</p>
    <a href="<?= e(url('tienda/index.php')) ?>" target="_blank" rel="noopener"
       class="mt-1 inline-flex items-center gap-1.5 font-semibold text-blue-600 hover:text-blue-700 transition-colors duration-200 cursor-pointer break-all">
      <?= icon('store', 'w-4 h-4') ?> <?= e(url('tienda/index.php')) ?>
    </a>
  </div>
</div>

<div class="card overflow-hidden">
  <?php $selSuc = selectSucursalFiltro(); ?>
  <form method="get" class="p-4 border-b border-slate-100 grid grid-cols-1 sm:grid-cols-<?= $selSuc ? '4' : '3' ?> gap-3">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Número, cliente o teléfono..." aria-label="Buscar pedido" class="input">
    <?= $selSuc ?>
    <select name="estado" aria-label="Estado del pedido" class="select cursor-pointer">
      <option value="">Todos los estados</option>
      <?php foreach ($estadoBadge as $k => [$label, $_]): ?>
        <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary cursor-pointer" aria-label="Aplicar filtros"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
  </form>

  <?php if (!$pedidos): ?>
    <?= empty_state('Sin pedidos', 'Cuando un cliente ordene desde la tienda, aparecerá aquí.', 'cart') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Pedido</th><th>Cliente</th><th>Sucursal</th><th class="text-center">Items</th>
            <th class="text-right">Total</th><th>Pago</th><th>Estado</th><th class="text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pedidos as $p): ?>
            <?php
              [$label, $clases] = $estadoBadge[$p['estado']];
              $wa = wa_link($p['cliente_telefono'], mensajePedido($p, $emp));
            ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($p['numero']) ?></p>
                <p class="text-xs text-slate-400"><?= e(substr((string) $p['created_at'], 0, 16)) ?></p>
              </td>
              <td>
                <p class="font-semibold text-slate-700"><?= e($p['cliente_nombre']) ?></p>
                <p class="text-xs text-slate-400"><?= e($p['cliente_telefono']) ?></p>
              </td>
              <td class="text-slate-600"><?= e($p['sucursal']) ?></td>
              <td class="text-center tabular-nums"><?= (int) $p['items'] ?></td>
              <td class="text-right font-bold text-slate-800 tabular-nums"><?= money($p['total']) ?></td>
              <td>
                <?php $linkEfectivo = linkPagoPedido($p, $emp); ?>
                <?php if ($p['metodo_pago'] === 'link_pago'): ?>
                  <span class="text-xs font-semibold text-blue-600 block">Link de pago</span>
                  <?php if ($p['link_pago']): ?>
                    <span class="text-xs text-emerald-600">Enviado <?= e(substr((string) $p['link_pago_enviado_at'], 0, 10)) ?></span>
                  <?php elseif ($linkEfectivo): ?>
                    <span class="text-xs text-slate-400">Usa el genérico</span>
                  <?php else: ?>
                    <span class="text-xs font-semibold text-amber-600">Falta el link</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-xs font-semibold text-slate-500">Al retirar</span>
                <?php endif; ?>
              </td>
              <td><span class="px-2.5 py-1 rounded-lg text-xs font-semibold border <?= $clases ?>"><?= $label ?></span></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('pedidos.gestionar') && $p['metodo_pago'] === 'link_pago' && $p['estado'] !== 'entregado'): ?>
                    <button type="button"
                            onclick="<?= jsEvent('pedido:link', ['id' => (int) $p['id'], 'numero' => $p['numero'], 'total' => money($p['total']), 'cliente' => $p['cliente_nombre'], 'link' => (string) $p['link_pago']]) ?>"
                            class="p-2 rounded-lg transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500
                                   <?= $p['link_pago'] ? 'text-slate-400 hover:text-blue-600 hover:bg-blue-50' : 'text-amber-600 hover:bg-amber-50' ?>"
                            title="<?= $p['link_pago'] ? 'Cambiar el link de pago' : 'Agregar el link de pago de este pedido' ?>"
                            aria-label="Link de pago del pedido <?= e($p['numero']) ?>"><?= icon('wallet', 'w-4 h-4') ?></button>
                  <?php endif; ?>

                  <?php if ($wa): ?>
                    <a href="<?= e($wa) ?>" target="_blank" rel="noopener"
                       class="p-2 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                       title="Escribir al cliente por WhatsApp"
                       aria-label="Escribir a <?= e($p['cliente_nombre']) ?> por WhatsApp"><?= icon('phone', 'w-4 h-4') ?></a>
                  <?php endif; ?>

                  <a href="<?= e(url('tienda/pedido.php?token=' . $p['token'])) ?>" target="_blank" rel="noopener"
                     class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                     title="Ver el pedido como lo ve el cliente"
                     aria-label="Ver pedido <?= e($p['numero']) ?>"><?= icon('eye', 'w-4 h-4') ?></a>

                  <?php if (can('pedidos.gestionar') && $p['estado'] !== 'entregado'): ?>
                    <form method="post" class="inline-flex items-center gap-1">
                      <?= csrf_field() ?>
                      <input type="hidden" name="accion" value="estado">
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <label class="sr-only" for="estado_<?= (int) $p['id'] ?>">Cambiar estado del pedido <?= e($p['numero']) ?></label>
                      <select id="estado_<?= (int) $p['id'] ?>" name="estado" onchange="this.form.submit()"
                              class="select py-1.5 text-xs cursor-pointer">
                        <?php foreach ($estadoBadge as $k => [$lbl, $_]): ?>
                          <option value="<?= $k ?>" <?= $p['estado'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                      </select>
                      <noscript><button class="btn btn-ghost btn-sm">Guardar</button></noscript>
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

<?php
// Pedidos que piden link de pago y no tienen ninguno utilizable.
$sinLink = 0;
foreach ($pedidos as $p) {
    if ($p['metodo_pago'] === 'link_pago'
        && !in_array($p['estado'], ['entregado', 'cancelado'], true)
        && !linkPagoPedido($p, $emp)) $sinLink++;
}
?>
<?php if ($sinLink): ?>
  <div class="card p-5 mt-5 border-l-4 border-l-amber-400">
    <div class="flex items-start gap-3">
      <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0 mt-0.5') ?>
      <div class="text-sm text-slate-600">
        <h3 class="font-bold text-slate-800"><?= $sinLink ?> pedido<?= $sinLink === 1 ? '' : 's' ?> sin link de pago</h3>
        <p class="mt-1">
          Genera el enlace de cobro por el monto exacto en tu pasarela y pégalo en cada pedido con el botón de la billetera.
          Hasta entonces, el mensaje de WhatsApp solo le avisa al cliente que se lo enviarás en breve.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Modal: link de pago del pedido -->
<div x-data="{ open: false, pedido: { id: 0, numero: '', total: '', cliente: '', link: '' } }"
     @pedido:link.window="pedido = $event.detail; open = true; $nextTick(() => $refs.campoLink.focus())"
     @keydown.escape.window="open = false"
     x-show="open" x-transition.opacity style="display:none"
     class="modal-overlay" @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="tituloLink">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="link">
      <input type="hidden" name="id" :value="pedido.id">

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="tituloLink" class="font-bold text-slate-800">Link de pago · <span x-text="pedido.numero"></span></h3>
        <button type="button" @click="open = false" aria-label="Cerrar modal" title="Cerrar"
                class="text-slate-400 hover:text-slate-700 p-1 -m-1 cursor-pointer transition-colors duration-200"><?= icon('x', 'w-5 h-5') ?></button>
      </div>

      <div class="p-6 space-y-4">
        <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-sm">
          <p class="text-slate-500">Monto a cobrar</p>
          <p class="text-xl font-extrabold text-slate-800" x-text="pedido.total"></p>
          <p class="text-slate-500 mt-1">Cliente: <span class="font-semibold text-slate-700" x-text="pedido.cliente"></span></p>
        </div>

        <div>
          <label class="label" for="link_pago">Enlace de cobro *</label>
          <input type="url" id="link_pago" name="link_pago" x-ref="campoLink" x-model="pedido.link"
                 placeholder="https://pagos.tubanco.com/abc123" class="input" autocomplete="off">
          <p class="mt-1 text-xs text-slate-500">
            Genera el enlace por el monto exacto en tu pasarela y pégalo aquí. Cada pedido lleva el suyo.
          </p>
        </div>

        <p class="text-xs text-slate-500">
          Deja el campo vacío para quitar el enlace. Después de guardar, usa el botón de WhatsApp para enviárselo al cliente.
        </p>
      </div>

      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open = false" class="btn btn-ghost cursor-pointer">Cancelar</button>
        <button class="btn btn-primary cursor-pointer"><?= icon('save', 'w-4 h-4') ?> Guardar link</button>
      </div>
    </form>
  </div>
</div>

<?php layout_end(); ?>
