<?php
/**
 * Confirmación pública de un pedido. Se accede con el token, nunca con el id.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/_shell.php';

$token = (string) get('token');
$pedido = preg_match('/^[a-f0-9]{32}$/', $token)
    ? qOne("SELECT p.*, s.nombre AS sucursal, s.direccion, s.whatsapp, s.horario
              FROM pedidos p JOIN sucursales s ON s.id = p.sucursal_id
             WHERE p.token = ?", [$token])
    : null;

if (!$pedido) {
    http_response_code(404);
    tienda_start('Pedido no encontrado');
    echo '<main class="max-w-xl mx-auto px-4 py-24 text-center">'
       . '<h1 class="font-display text-2xl font-bold">No encontramos ese pedido</h1>'
       . '<p class="mt-2 text-emerald-900/70">Verifica el enlace que te compartimos.</p>'
       . '<a href="' . e(url('tienda/index.php')) . '" class="btn-marca inline-block mt-6 rounded-xl px-5 py-3 cursor-pointer">Volver a la tienda</a>'
       . '</main>';
    tienda_end();
    exit;
}

$detalles = qAll("SELECT * FROM pedido_detalles WHERE pedido_id = ? ORDER BY id", [$pedido['id']]);
$emp = tienda_empresa();

$estados = [
    'pendiente'  => ['Pendiente de confirmación', 'bg-amber-100 text-amber-800 border-amber-300'],
    'confirmado' => ['Confirmado', 'bg-sky-100 text-sky-800 border-sky-300'],
    'listo'      => ['Listo para retirar', 'bg-emerald-100 text-emerald-800 border-emerald-300'],
    'entregado'  => ['Entregado', 'bg-slate-100 text-slate-700 border-slate-300'],
    'cancelado'  => ['Cancelado', 'bg-rose-100 text-rose-800 border-rose-300'],
];
[$estadoTexto, $estadoClases] = $estados[$pedido['estado']] ?? ['Pendiente', 'bg-amber-100 text-amber-800 border-amber-300'];

// Mensaje que el cliente envía a la tienda para confirmar su pedido.
$mensajeTienda = "Hola {$emp['nombre']}, acabo de hacer el pedido {$pedido['numero']} "
    . "por " . money($pedido['total']) . " para retirar en {$pedido['sucursal']}. "
    . ($pedido['metodo_pago'] === 'link_pago' ? 'Quisiera recibir el link de pago.' : 'Pagaré al retirar.');
$linkWhatsapp = wa_link($pedido['whatsapp'], $mensajeTienda);

tienda_start('Pedido ' . $pedido['numero']);
?>

<header class="bg-white border-b border-emerald-200">
  <div class="max-w-3xl mx-auto px-4 h-16 flex items-center gap-3">
    <a href="<?= e(url('tienda/index.php?sucursal=' . (int) $pedido['sucursal_id'])) ?>"
       class="p-2 -ml-2 rounded-lg text-emerald-900/60 hover:bg-marca-muy transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-marca"
       aria-label="Volver a la tienda"><?= ticon('arrow-left', 'w-5 h-5') ?></a>
    <span class="font-display font-bold text-marca-texto"><?= e($emp['nombre']) ?></span>
  </div>
</header>

<main class="max-w-3xl mx-auto px-4 py-10">

  <div class="text-center">
    <span class="inline-grid place-items-center w-16 h-16 rounded-2xl bg-marca text-white mb-4"><?= ticon('check', 'w-8 h-8') ?></span>
    <h1 class="font-display text-3xl font-bold">¡Recibimos tu pedido!</h1>
    <p class="mt-2 text-emerald-900/70">
      Pedido <span class="font-semibold text-marca-texto"><?= e($pedido['numero']) ?></span>.
      Guarda este enlace para consultar su estado.
    </p>
    <span class="inline-block mt-4 px-3 py-1 rounded-full text-sm font-semibold border <?= $estadoClases ?>">
      <?= e($estadoTexto) ?>
    </span>
  </div>

  <?php if ($linkWhatsapp): ?>
    <a href="<?= e($linkWhatsapp) ?>" target="_blank" rel="noopener"
       class="btn-marca mt-8 w-full rounded-xl py-4 min-h-[44px] cursor-pointer flex items-center justify-center gap-2.5 text-base">
      <?= ticon('whatsapp', 'w-5 h-5') ?> Avisar por WhatsApp a la tienda
    </a>
    <p class="mt-2 text-center text-xs text-emerald-900/60">
      Se abrirá WhatsApp con un mensaje ya escrito.
    </p>
  <?php else: ?>
    <div role="status" class="mt-8 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 text-sm">
      Esta sucursal aún no tiene WhatsApp configurado. Te contactaremos al número que dejaste.
    </div>
  <?php endif; ?>

  <!-- Resumen -->
  <section class="mt-8 bg-white rounded-2xl border border-emerald-100 overflow-hidden">
    <h2 class="font-display font-bold px-5 py-4 border-b border-emerald-100">Resumen del pedido</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <caption class="sr-only">Productos incluidos en el pedido <?= e($pedido['numero']) ?></caption>
        <thead class="bg-marca-muy text-left">
          <tr>
            <th scope="col" class="px-5 py-2.5 font-semibold">Producto</th>
            <th scope="col" class="px-3 py-2.5 font-semibold text-center">Cant.</th>
            <th scope="col" class="px-3 py-2.5 font-semibold text-right">Precio</th>
            <th scope="col" class="px-5 py-2.5 font-semibold text-right">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detalles as $d): ?>
            <tr class="border-t border-emerald-50">
              <td class="px-5 py-3 font-medium"><?= e($d['descripcion']) ?></td>
              <td class="px-3 py-3 text-center tabular-nums"><?= qty($d['cantidad']) ?></td>
              <td class="px-3 py-3 text-right tabular-nums"><?= money($d['precio_unitario']) ?></td>
              <td class="px-5 py-3 text-right tabular-nums font-semibold"><?= money($d['subtotal']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="border-t border-emerald-100">
          <tr><td colspan="3" class="px-5 py-1.5 text-right text-emerald-900/70">Subtotal</td><td class="px-5 py-1.5 text-right tabular-nums"><?= money($pedido['subtotal']) ?></td></tr>
          <tr><td colspan="3" class="px-5 py-1.5 text-right text-emerald-900/70">ITBIS</td><td class="px-5 py-1.5 text-right tabular-nums"><?= money($pedido['itbis']) ?></td></tr>
          <tr class="font-display font-bold text-base"><td colspan="3" class="px-5 py-3 text-right">Total</td><td class="px-5 py-3 text-right tabular-nums text-marca"><?= money($pedido['total']) ?></td></tr>
        </tfoot>
      </table>
    </div>
  </section>

  <!-- Retiro y pago -->
  <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
    <section class="bg-white rounded-2xl border border-emerald-100 p-5">
      <h2 class="font-display font-bold flex items-center gap-2"><?= ticon('store', 'w-5 h-5 text-marca') ?> Retiras en</h2>
      <p class="mt-2 font-semibold"><?= e($pedido['sucursal']) ?></p>
      <?php if ($pedido['direccion']): ?><p class="text-sm text-emerald-900/70 mt-0.5"><?= e($pedido['direccion']) ?></p><?php endif; ?>
      <?php if ($pedido['horario']): ?><p class="text-sm text-emerald-900/70 mt-1.5 flex items-center gap-1.5"><?= ticon('clock', 'w-4 h-4') ?> <?= e($pedido['horario']) ?></p><?php endif; ?>
    </section>

    <section class="bg-white rounded-2xl border border-emerald-100 p-5">
      <h2 class="font-display font-bold">Forma de pago</h2>
      <?php if ($pedido['metodo_pago'] === 'link_pago'): ?>
        <p class="mt-2 font-semibold">Link de pago</p>
        <p class="text-sm text-emerald-900/70 mt-0.5">
          Te enviaremos el enlace por WhatsApp al <?= e($pedido['cliente_telefono']) ?>.
        </p>
      <?php else: ?>
        <p class="mt-2 font-semibold">Pagas al retirar</p>
        <p class="text-sm text-emerald-900/70 mt-0.5">En efectivo o tarjeta, cuando recojas tu pedido.</p>
      <?php endif; ?>
      <?php if ($pedido['notas']): ?>
        <p class="text-sm text-emerald-900/70 mt-3 pt-3 border-t border-emerald-50"><span class="font-semibold">Tu nota:</span> <?= e($pedido['notas']) ?></p>
      <?php endif; ?>
    </section>
  </div>

  <p class="mt-8 text-center text-sm text-emerald-900/60">
    Confirmamos disponibilidad antes de facturar. No se ha cobrado nada todavía.
  </p>
</main>

<?php tienda_end(); ?>
