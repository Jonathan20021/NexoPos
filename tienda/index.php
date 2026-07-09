<?php
/**
 * Tienda online pública: catálogo por sucursal, carrito y checkout.
 * No requiere sesión de usuario. No descuenta stock: crea un pedido.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/_shell.php';

$emp = tienda_empresa();
if (empty($emp['tienda_activa'])) {
    http_response_code(503);
    tienda_start('Tienda no disponible');
    echo '<main class="max-w-xl mx-auto px-4 py-24 text-center"><h1 class="font-display text-2xl font-bold">Tienda temporalmente cerrada</h1><p class="mt-2 text-emerald-900/70">Vuelve más tarde.</p></main>';
    tienda_end();
    exit;
}

$sucursales = tienda_sucursales();
if (!$sucursales) {
    http_response_code(503);
    tienda_start('Tienda no disponible');
    echo '<main class="max-w-xl mx-auto px-4 py-24 text-center"><h1 class="font-display text-2xl font-bold">No hay sucursales disponibles</h1></main>';
    tienda_end();
    exit;
}

$sucursalId = (int) get('sucursal');
$sucursal = null;
foreach ($sucursales as $s) if ((int) $s['id'] === $sucursalId) $sucursal = $s;
if (!$sucursal) $sucursal = $sucursales[0];
$sucursalId = (int) $sucursal['id'];

$tasaItbis = (float) setting('itbis_tasa', DEFAULT_ITBIS);

// ---------------------------------------------------------------------------
//  Crear pedido
// ---------------------------------------------------------------------------
if (isPost() && post('accion') === 'pedido') {
    verify_csrf();
    $nombre    = trim(post('cliente_nombre'));
    $telefono  = trim(post('cliente_telefono'));
    $email     = trim(post('cliente_email')) ?: null;
    $documento = trim(post('cliente_documento')) ?: null;
    $notas     = trim(post('notas')) ?: null;
    $metodo    = post('metodo_pago') === 'link_pago' ? 'link_pago' : 'pickup';
    $carrito   = json_decode(post('carrito', '[]'), true);

    try {
        if ($nombre === '' || mb_strlen($nombre) < 3) throw new RuntimeException('Escribe tu nombre completo.');
        if (wa_numero($telefono) === '' || strlen(wa_numero($telefono)) < 10) {
            throw new RuntimeException('Escribe un teléfono válido con código de país. Ej. 1 809 555 1234.');
        }
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Escribe un correo válido: ahí te enviamos la confirmación del pedido.');
        }
        if (!is_array($carrito) || !$carrito) throw new RuntimeException('Tu carrito está vacío.');
        if (count($carrito) > 50) throw new RuntimeException('Demasiados productos en el carrito.');

        $creado = tx(function () use ($carrito, $sucursalId, $nombre, $telefono, $email, $documento, $notas, $metodo, $tasaItbis) {
            $subtotal = 0; $itbisTotal = 0; $lineas = [];
            foreach ($carrito as $item) {
                $pid  = (int) ($item['id'] ?? 0);
                $cant = (float) ($item['cant'] ?? 0);
                if ($pid <= 0 || $cant <= 0) continue;
                if ($cant > 999) throw new RuntimeException('Cantidad no válida.');

                // El precio NUNCA se toma del navegador: se relee de la base.
                $p = qOne(
                    "SELECT p.id, p.nombre, p.precio_venta, p.itbis_aplica,
                            COALESCE(s.cantidad, 0) AS stock
                       FROM productos p
                       LEFT JOIN inventario_stock s ON s.producto_id = p.id AND s.sucursal_id = ?
                      WHERE p.id = ? AND p.activo = 1 AND p.tipo = 'producto'",
                    [$sucursalId, $pid]
                );
                if (!$p) throw new RuntimeException('Uno de los productos ya no está disponible.');
                if ((float) $p['stock'] < $cant) {
                    throw new RuntimeException('No hay suficiente inventario de «' . $p['nombre'] . '». Disponible: ' . qty($p['stock']) . '.');
                }

                $base  = round((float) $p['precio_venta'] * $cant, 2);
                $itbis = $p['itbis_aplica'] ? round($base * $tasaItbis / 100, 2) : 0.0;
                $subtotal += $base; $itbisTotal += $itbis;
                $lineas[] = ['pid' => $pid, 'desc' => $p['nombre'], 'cant' => $cant,
                             'precio' => (float) $p['precio_venta'], 'itbis' => $itbis, 'sub' => $base];
            }
            if (!$lineas) throw new RuntimeException('Tu carrito está vacío.');

            $numero = nextNumero('pedidos', 'numero', 'PED');
            $token  = bin2hex(random_bytes(16));
            $pedidoId = dbInsert('pedidos', [
                'numero' => $numero, 'token' => $token, 'sucursal_id' => $sucursalId,
                'cliente_nombre' => $nombre, 'cliente_telefono' => $telefono,
                'cliente_email' => $email, 'cliente_documento' => $documento,
                'notas' => $notas, 'metodo_pago' => $metodo,
                'subtotal' => $subtotal, 'itbis' => $itbisTotal,
                'total' => round($subtotal + $itbisTotal, 2), 'estado' => 'pendiente',
            ]);
            foreach ($lineas as $l) {
                dbInsert('pedido_detalles', [
                    'pedido_id' => $pedidoId, 'producto_id' => $l['pid'], 'descripcion' => $l['desc'],
                    'cantidad' => $l['cant'], 'precio_unitario' => $l['precio'],
                    'itbis' => $l['itbis'], 'subtotal' => $l['sub'],
                ]);
            }
            return ['id' => $pedidoId, 'token' => $token];
        });

        // Fuera de la transacción, y a prueba de fallos: un correo caído no puede
        // tumbar un pedido que ya está guardado. El resultado queda en correos_enviados.
        try {
            correoPedidoNuevo((int) $creado['id']);
        } catch (Throwable $e) {
            // Ni siquiera un error inesperado del proveedor interrumpe al cliente.
        }

        redirect('tienda/pedido.php?token=' . $creado['token']);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('tienda/index.php?sucursal=' . $sucursalId);
    }
}

// ---------------------------------------------------------------------------
//  Catálogo
// ---------------------------------------------------------------------------
$q          = trim(get('q'));
$categoriaId = (int) get('categoria');

$cond = ["p.activo = 1", "p.tipo = 'producto'", "s.cantidad > 0"];
$params = [$sucursalId];
if ($q !== '')      { $cond[] = "(p.nombre LIKE ? OR p.codigo LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($categoriaId)   { $cond[] = "p.categoria_id = ?"; $params[] = $categoriaId; }
$where = implode(' AND ', $cond);

$productos = qAll(
    "SELECT p.id, p.nombre, p.codigo, p.imagen, p.precio_venta, p.itbis_aplica,
            s.cantidad AS stock, c.nombre AS categoria
       FROM productos p
       JOIN inventario_stock s ON s.producto_id = p.id AND s.sucursal_id = ?
       LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE $where
      ORDER BY p.nombre",
    $params
);

$categorias = qAll(
    "SELECT DISTINCT c.id, c.nombre
       FROM categorias c
       JOIN productos p ON p.categoria_id = c.id AND p.activo = 1 AND p.tipo = 'producto'
       JOIN inventario_stock s ON s.producto_id = p.id AND s.sucursal_id = ? AND s.cantidad > 0
      ORDER BY c.nombre",
    [$sucursalId]
);

$productosJs = array_map(fn($p) => [
    'id' => (int) $p['id'], 'nombre' => $p['nombre'],
    'precio' => (float) $p['precio_venta'], 'itbis' => (int) $p['itbis_aplica'],
    'stock' => (float) $p['stock'],
], $productos);

tienda_start('Tienda en línea', 'Ordena en línea y retira en ' . $sucursal['nombre']);
$mensajes = get_flashes();
?>

<div x-data="tienda(<?= e(json_encode($productosJs)) ?>, <?= $sucursalId ?>, <?= $tasaItbis ?>)" x-cloak>

  <!-- Barra superior -->
  <header class="sticky top-0 z-30 bg-white/95 backdrop-blur border-b border-emerald-200">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
      <a href="<?= e(url('tienda/index.php')) ?>" class="flex items-center gap-2.5 shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-marca rounded-lg">
        <span class="w-9 h-9 rounded-xl bg-marca text-white grid place-items-center font-display font-bold">
          <?= e(mb_substr($emp['nombre'], 0, 1)) ?>
        </span>
        <span class="font-display font-bold text-marca-texto hidden sm:block"><?= e($emp['nombre']) ?></span>
      </a>

      <form method="get" class="flex-1 max-w-md relative">
        <input type="hidden" name="sucursal" value="<?= $sucursalId ?>">
        <label for="q" class="sr-only">Buscar productos</label>
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-emerald-700/50"><?= ticon('search', 'w-5 h-5') ?></span>
        <input id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar productos..." class="campo pl-10">
      </form>

      <button type="button" @click="abrirCarrito = true"
              class="relative shrink-0 rounded-xl p-2.5 text-marca-texto hover:bg-marca-muy transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-marca"
              :aria-label="`Abrir carrito, ${totalItems} artículos`">
        <?= ticon('cart', 'w-6 h-6') ?>
        <span x-show="totalItems > 0"
              class="absolute -top-0.5 -right-0.5 min-w-[20px] h-5 px-1 rounded-full bg-accion text-white text-xs font-bold grid place-items-center"
              x-text="totalItems"></span>
      </button>
    </div>
  </header>

  <!-- Selector de sucursal -->
  <section class="bg-marca text-white">
    <div class="max-w-6xl mx-auto px-4 py-5 flex flex-col sm:flex-row sm:items-center gap-4 justify-between">
      <div class="flex items-start gap-3 min-w-0">
        <span class="shrink-0 mt-0.5"><?= ticon('pin', 'w-5 h-5') ?></span>
        <div class="min-w-0">
          <p class="font-display font-semibold leading-tight">Retiras en <?= e($sucursal['nombre']) ?></p>
          <p class="text-sm text-emerald-100 truncate"><?= e($sucursal['direccion'] ?: '') ?></p>
          <?php if (!empty($sucursal['horario'])): ?>
            <p class="text-sm text-emerald-100 flex items-center gap-1.5 mt-0.5"><?= ticon('clock', 'w-4 h-4') ?> <?= e($sucursal['horario']) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php if (count($sucursales) > 1): ?>
        <form method="get" class="shrink-0">
          <label for="sucursal" class="sr-only">Cambiar de sucursal</label>
          <select id="sucursal" name="sucursal" onchange="this.form.submit()"
                  class="campo cursor-pointer text-marca-texto min-w-[220px]">
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $sucursalId ? 'selected' : '' ?>><?= e($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn-marca rounded-xl px-4 py-2 mt-2">Cambiar</button></noscript>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <!-- Cómo funciona: responde las tres dudas del cliente antes de que las tenga -->
  <section aria-label="Cómo funciona" class="bg-white border-b border-emerald-100">
    <div class="max-w-6xl mx-auto px-4 py-4 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
      <div class="flex items-center gap-2.5">
        <span class="shrink-0 w-9 h-9 rounded-xl bg-marca-muy text-marca grid place-items-center"><?= ticon('cart', 'w-4 h-4') ?></span>
        <p><span class="font-semibold block leading-tight">Ordena en línea</span>
           <span class="text-emerald-900/60">Sin registrarte</span></p>
      </div>
      <div class="flex items-center gap-2.5">
        <span class="shrink-0 w-9 h-9 rounded-xl bg-marca-muy text-marca grid place-items-center"><?= ticon('store', 'w-4 h-4') ?></span>
        <p><span class="font-semibold block leading-tight">Retira en la sucursal</span>
           <span class="text-emerald-900/60">Sin costo de envío</span></p>
      </div>
      <div class="flex items-center gap-2.5">
        <span class="shrink-0 w-9 h-9 rounded-xl bg-marca-muy text-marca grid place-items-center"><?= ticon('check', 'w-4 h-4') ?></span>
        <p><span class="font-semibold block leading-tight">Paga como prefieras</span>
           <span class="text-emerald-900/60">Al retirar o por link</span></p>
      </div>
    </div>
  </section>

  <main class="max-w-6xl mx-auto px-4 py-8">
    <?php foreach ($mensajes as $m): ?>
      <div role="alert" class="mb-6 rounded-xl border p-4 <?= $m['tipo'] === 'error' ? 'border-rose-300 bg-rose-50 text-rose-800' : 'border-emerald-300 bg-emerald-50 text-emerald-900' ?>">
        <?= e($m['mensaje']) ?>
      </div>
    <?php endforeach; ?>

    <!-- Categorías -->
    <?php if ($categorias): ?>
      <nav aria-label="Categorías" class="flex gap-2 overflow-x-auto pb-3 mb-6 -mx-4 px-4">
        <?php $qsBase = ['sucursal' => $sucursalId] + ($q !== '' ? ['q' => $q] : []); ?>
        <a href="?<?= e(http_build_query($qsBase)) ?>"
           class="shrink-0 px-4 py-2 rounded-full text-sm font-semibold border transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-marca
                  <?= !$categoriaId ? 'bg-marca text-white border-marca' : 'bg-white text-marca-texto border-emerald-200 hover:bg-marca-muy' ?>">
          Todo
        </a>
        <?php foreach ($categorias as $c): ?>
          <a href="?<?= e(http_build_query($qsBase + ['categoria' => (int) $c['id']])) ?>"
             class="shrink-0 px-4 py-2 rounded-full text-sm font-semibold border transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-marca
                    <?= $categoriaId === (int) $c['id'] ? 'bg-marca text-white border-marca' : 'bg-white text-marca-texto border-emerald-200 hover:bg-marca-muy' ?>">
            <?= e($c['nombre']) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if (!$productos): ?>
      <div class="text-center py-24">
        <span class="inline-grid place-items-center w-16 h-16 rounded-2xl bg-white text-emerald-700/40 mb-4"><?= ticon('box', 'w-8 h-8') ?></span>
        <h2 class="font-display text-xl font-bold">No encontramos productos</h2>
        <p class="mt-1 text-emerald-900/60">
          <?= $q !== '' ? 'Prueba con otra búsqueda.' : 'Esta sucursal no tiene productos disponibles ahora mismo.' ?>
        </p>
      </div>
    <?php else: ?>
      <h1 class="font-display text-2xl sm:text-3xl font-bold mb-1">Nuestros productos</h1>
      <p class="text-emerald-900/60 mb-6"><?= count($productos) ?> disponibles en <?= e($sucursal['nombre']) ?></p>

      <ul class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ($productos as $p): $pid = (int) $p['id']; $pocas = (float) $p['stock'] <= 5; ?>
          <li class="group bg-white rounded-2xl border border-emerald-100 overflow-hidden flex flex-col
                     transition-shadow duration-200 hover:shadow-lg hover:shadow-emerald-900/5">
            <div class="relative aspect-square bg-marca-muy grid place-items-center overflow-hidden">
              <?php if (!empty($p['imagen']) && is_file(dirname(__DIR__) . '/' . $p['imagen'])): ?>
                <img src="<?= e(url($p['imagen'])) ?>" alt="<?= e($p['nombre']) ?>" loading="lazy" decoding="async"
                     class="w-full h-full object-cover">
              <?php else: ?>
                <span class="text-emerald-700/25" aria-hidden="true"><?= ticon('box', 'w-12 h-12') ?></span>
              <?php endif; ?>

              <?php if ($pocas): ?>
                <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-xs font-semibold border border-amber-200">
                  Quedan <?= qty($p['stock']) ?>
                </span>
              <?php endif; ?>

              <span x-show="enCarrito(<?= $pid ?>) > 0" x-transition
                    class="absolute top-2 right-2 min-w-[26px] h-[26px] px-1.5 rounded-full bg-marca text-white text-xs font-bold grid place-items-center"
                    x-text="enCarrito(<?= $pid ?>)" aria-hidden="true"></span>
            </div>

            <div class="p-4 flex flex-col flex-1">
              <?php if ($p['categoria']): ?>
                <p class="text-xs font-semibold text-emerald-700/60 uppercase tracking-wide"><?= e($p['categoria']) ?></p>
              <?php endif; ?>
              <h2 class="font-semibold text-marca-texto mt-0.5 leading-snug line-clamp-2"><?= e($p['nombre']) ?></h2>

              <div class="mt-2">
                <p class="font-display text-xl font-bold text-marca leading-none"><?= money($p['precio_venta']) ?></p>
                <p class="text-xs text-emerald-900/50 mt-1">
                  <?= $p['itbis_aplica'] ? 'ITBIS incluido al facturar' : 'Exento de ITBIS' ?>
                </p>
              </div>

              <div class="mt-auto pt-3">
                <!-- Con el producto ya en el carrito, el botón se convierte en un contador. -->
                <template x-if="enCarrito(<?= $pid ?>) === 0">
                  <button type="button" @click="agregar(<?= $pid ?>)"
                          class="btn-marca w-full rounded-xl py-2.5 text-sm cursor-pointer flex items-center justify-center gap-2 min-h-[44px]">
                    <?= ticon('plus', 'w-4 h-4') ?> Agregar
                  </button>
                </template>
                <template x-if="enCarrito(<?= $pid ?>) > 0">
                  <div class="flex items-center justify-between gap-2 rounded-xl border border-marca/30 bg-marca-muy p-1">
                    <button type="button" @click="restar(<?= $pid ?>)" aria-label="Quitar uno de <?= e($p['nombre']) ?>"
                            class="w-10 h-10 grid place-items-center rounded-lg text-marca hover:bg-white transition-colors duration-200 cursor-pointer">
                      <?= ticon('minus', 'w-4 h-4') ?>
                    </button>
                    <span class="font-display font-bold text-marca-texto tabular-nums" x-text="enCarrito(<?= $pid ?>)"></span>
                    <button type="button" @click="agregar(<?= $pid ?>)" :disabled="enCarrito(<?= $pid ?>) >= <?= (float) $p['stock'] ?>"
                            aria-label="Agregar uno de <?= e($p['nombre']) ?>"
                            class="w-10 h-10 grid place-items-center rounded-lg text-marca hover:bg-white transition-colors duration-200 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">
                      <?= ticon('plus', 'w-4 h-4') ?>
                    </button>
                  </div>
                </template>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </main>

  <!-- Barra fija de carrito en móvil -->
  <div x-show="totalItems > 0" x-transition.opacity
       class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-emerald-200 p-3 shadow-[0_-4px_16px_rgba(0,0,0,.06)]">
    <button type="button" @click="abrirCarrito = true"
            class="btn-accion w-full rounded-xl py-3 min-h-[44px] cursor-pointer flex items-center justify-center gap-2">
      <?= ticon('cart', 'w-5 h-5') ?>
      <span>Ver carrito · <span x-text="fmt(total)"></span></span>
    </button>
  </div>

  <!-- Panel del carrito -->
  <div x-show="abrirCarrito" x-transition.opacity class="fixed inset-0 z-40 bg-black/40" @click="abrirCarrito = false" aria-hidden="true"></div>
  <aside x-show="abrirCarrito" x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
         class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white flex flex-col"
         role="dialog" aria-modal="true" aria-labelledby="tituloCarrito">

    <div class="flex items-center justify-between px-5 h-16 border-b border-emerald-100 shrink-0">
      <h2 id="tituloCarrito" class="font-display font-bold text-lg">Tu pedido</h2>
      <button type="button" @click="abrirCarrito = false" aria-label="Cerrar carrito"
              class="p-2 -mr-2 rounded-lg text-emerald-900/50 hover:bg-marca-muy transition-colors duration-200 cursor-pointer">
        <?= ticon('arrow-left', 'w-5 h-5') ?>
      </button>
    </div>

    <div class="flex-1 overflow-y-auto px-5 py-4">
      <template x-if="items.length === 0">
        <div class="text-center py-16">
          <span class="inline-grid place-items-center w-14 h-14 rounded-2xl bg-marca-muy text-emerald-700/40 mb-3"><?= ticon('cart', 'w-7 h-7') ?></span>
          <p class="font-semibold">Tu carrito está vacío</p>
          <p class="text-sm text-emerald-900/60 mt-1">Agrega productos para continuar.</p>
        </div>
      </template>

      <ul class="space-y-3" x-show="items.length > 0">
        <template x-for="it in items" :key="it.id">
          <li class="flex gap-3 items-start border border-emerald-100 rounded-xl p-3">
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-sm leading-snug" x-text="it.nombre"></p>
              <p class="text-sm text-emerald-900/60" x-text="fmt(it.precio) + ' c/u'"></p>
              <p class="text-xs text-amber-700 mt-1" x-show="it.cant >= it.stock">
                Máximo disponible: <span x-text="it.stock"></span>
              </p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button type="button" @click="restar(it.id)" :aria-label="`Quitar uno de ${it.nombre}`"
                      class="w-9 h-9 grid place-items-center rounded-lg border border-emerald-200 hover:bg-marca-muy transition-colors duration-200 cursor-pointer">
                <?= ticon('minus', 'w-4 h-4') ?>
              </button>
              <span class="w-8 text-center font-semibold tabular-nums" x-text="it.cant"></span>
              <button type="button" @click="agregar(it.id)" :disabled="it.cant >= it.stock" :aria-label="`Agregar uno de ${it.nombre}`"
                      class="w-9 h-9 grid place-items-center rounded-lg border border-emerald-200 hover:bg-marca-muy transition-colors duration-200 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">
                <?= ticon('plus', 'w-4 h-4') ?>
              </button>
              <button type="button" @click="eliminar(it.id)" :aria-label="`Eliminar ${it.nombre}`"
                      class="w-9 h-9 grid place-items-center rounded-lg text-rose-600 hover:bg-rose-50 transition-colors duration-200 cursor-pointer">
                <?= ticon('trash', 'w-4 h-4') ?>
              </button>
            </div>
          </li>
        </template>
      </ul>

      <!-- Checkout -->
      <form method="post" class="mt-6 space-y-4" x-show="items.length > 0" @submit="enviarPedido($event)">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="pedido">
        <input type="hidden" name="carrito" x-ref="carritoInput" id="carritoInput">

        <div class="border-t border-emerald-100 pt-4 space-y-1.5 text-sm">
          <div class="flex justify-between text-emerald-900/70"><span>Subtotal</span><span x-text="fmt(subtotal)"></span></div>
          <div class="flex justify-between text-emerald-900/70"><span>ITBIS</span><span x-text="fmt(itbis)"></span></div>
          <div class="flex justify-between font-display font-bold text-lg text-marca-texto pt-1.5 border-t border-emerald-100">
            <span>Total</span><span x-text="fmt(total)"></span>
          </div>
        </div>

        <div>
          <label class="block text-sm font-semibold mb-1" for="cliente_nombre">Nombre completo *</label>
          <input id="cliente_nombre" name="cliente_nombre" required minlength="3" autocomplete="name" class="campo">
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1" for="cliente_telefono">WhatsApp *</label>
          <input id="cliente_telefono" name="cliente_telefono" required inputmode="tel" autocomplete="tel"
                 placeholder="1 809 555 1234" class="campo">
          <p class="mt-1 text-xs text-emerald-900/60">Por aquí te avisamos y te mandamos el link de pago.</p>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1" for="cliente_email">Correo electrónico *</label>
          <input type="email" id="cliente_email" name="cliente_email" required inputmode="email" autocomplete="email"
                 placeholder="tunombre@correo.com" class="campo">
          <p class="mt-1 text-xs text-emerald-900/60">Te llega la confirmación del pedido al instante.</p>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1" for="cliente_documento">Cédula o RNC <span class="font-normal text-emerald-900/50">(opcional)</span></label>
          <input id="cliente_documento" name="cliente_documento" autocomplete="off" class="campo">
          <p class="mt-1 text-xs text-emerald-900/60">Necesaria solo si requieres factura con crédito fiscal.</p>
        </div>

        <fieldset>
          <legend class="block text-sm font-semibold mb-2">¿Cómo prefieres pagar?</legend>
          <div class="space-y-2">
            <label class="flex items-start gap-3 border border-emerald-200 rounded-xl p-3 cursor-pointer hover:bg-marca-muy transition-colors duration-200 has-[:checked]:border-marca has-[:checked]:bg-marca-muy">
              <input type="radio" name="metodo_pago" value="pickup" checked class="mt-1 accent-[#15803D]">
              <span>
                <span class="font-semibold block">Pagar al retirar</span>
                <span class="text-sm text-emerald-900/60">Pagas en efectivo o tarjeta cuando recojas el pedido.</span>
              </span>
            </label>
            <label class="flex items-start gap-3 border border-emerald-200 rounded-xl p-3 cursor-pointer hover:bg-marca-muy transition-colors duration-200 has-[:checked]:border-marca has-[:checked]:bg-marca-muy">
              <input type="radio" name="metodo_pago" value="link_pago" class="mt-1 accent-[#15803D]">
              <span>
                <span class="font-semibold block">Recibir link de pago</span>
                <span class="text-sm text-emerald-900/60">Te lo enviamos por WhatsApp para que pagues desde casa.</span>
              </span>
            </label>
          </div>
        </fieldset>

        <div>
          <label class="block text-sm font-semibold mb-1" for="notas">Nota para la tienda <span class="font-normal text-emerald-900/50">(opcional)</span></label>
          <textarea id="notas" name="notas" rows="2" maxlength="500" class="campo resize-none"></textarea>
        </div>

        <!--
          El botón NUNCA se deshabilita en el propio clic: un <button type="submit">
          deshabilitado pierde su acción por defecto y el formulario no se envía.
          El doble envío se evita en enviarPedido(), no con el atributo disabled.
        -->
        <button type="submit"
                class="btn-accion w-full rounded-xl py-3.5 min-h-[44px] cursor-pointer flex items-center justify-center gap-2"
                :aria-busy="enviando ? 'true' : 'false'"
                :class="enviando && 'opacity-70 pointer-events-none'">
          <span x-show="!enviando" class="flex items-center gap-2"><?= ticon('check', 'w-5 h-5') ?> Confirmar pedido</span>
          <span x-show="enviando">Enviando...</span>
        </button>
        <p class="text-xs text-center text-emerald-900/50">No se cobra nada ahora. Confirmamos disponibilidad antes de facturar.</p>
      </form>
    </div>
  </aside>
</div>

<script>
function tienda(catalogo, sucursalId, tasaItbis) {
  const clave = 'nexopos_carrito_' + sucursalId;
  return {
    catalogo, abrirCarrito: false, enviando: false, items: [],

    init() {
      // Si el usuario vuelve con el botón "atrás", el navegador restaura la página
      // desde caché con enviando=true. Hay que devolver el botón a su estado normal.
      window.addEventListener('pageshow', () => { this.enviando = false; });
      try {
        const guardado = JSON.parse(localStorage.getItem(clave) || '[]');
        // Se rehidrata contra el catálogo actual: precios y stock mandan del servidor.
        this.items = guardado
          .map(g => {
            const p = this.catalogo.find(c => c.id === g.id);
            if (!p) return null;
            return { ...p, cant: Math.min(g.cant, p.stock) };
          })
          .filter(Boolean);
      } catch { this.items = []; }
      this.$watch('items', () => this.guardar());
    },
    guardar() {
      localStorage.setItem(clave, JSON.stringify(this.items.map(i => ({ id: i.id, cant: i.cant }))));
    },
    enCarrito(id) { const i = this.items.find(x => x.id === id); return i ? i.cant : 0; },
    agregar(id) {
      const p = this.catalogo.find(c => c.id === id);
      if (!p) return;
      const i = this.items.find(x => x.id === id);
      if (i) { if (i.cant < p.stock) i.cant++; }
      else this.items.push({ ...p, cant: 1 });
    },
    restar(id) {
      const i = this.items.find(x => x.id === id);
      if (!i) return;
      if (i.cant > 1) i.cant--; else this.eliminar(id);
    },
    eliminar(id) { this.items = this.items.filter(x => x.id !== id); },

    enviarPedido(e) {
      if (this.enviando) { e.preventDefault(); return; }   // evita el doble envío
      if (this.items.length === 0) { e.preventDefault(); return; }
      this.$refs.carritoInput.value = JSON.stringify(this.items.map(i => ({ id: i.id, cant: i.cant })));
      this.enviando = true;   // solo cambia el texto; el submit ya está en marcha
    },

    get totalItems() { return this.items.reduce((s, i) => s + i.cant, 0); },
    get subtotal()   { return this.items.reduce((s, i) => s + i.precio * i.cant, 0); },
    get itbis()      { return this.items.reduce((s, i) => s + (i.itbis ? i.precio * i.cant * tasaItbis / 100 : 0), 0); },
    get total()      { return this.subtotal + this.itbis; },
    fmt(n) { return 'RD$ ' + n.toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
  };
}
</script>

<?php tienda_end(); ?>
