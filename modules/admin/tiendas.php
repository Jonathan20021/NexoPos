<?php
/**
 * Tiendas y marcas comerciales.
 *
 * Cada tienda es la cara con la que se factura: logo, colores, dirección del
 * punto de venta, mensaje del ticket y política de devolución. El emisor fiscal
 * sigue siendo la empresa (un solo RNC, una sola secuencia de NCF).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('tiendas.ver');

if (!tiendas_disponible()) {
    layout_start('Tiendas y marcas', 'Identidad comercial de los comprobantes');
    echo empty_state(
        'Falta aplicar la migración',
        'Ejecuta database/migracion_tiendas_p16.sql para habilitar las tiendas.',
        'alert'
    );
    layout_end();
    return;
}

$TIPOS_COLOR = ['#2563eb', '#0f172a', '#7c3aed', '#059669', '#b45309', '#be123c', '#0891b2', '#a16207'];

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id     = postInt('id');
        $codigo = strtoupper(trim(post('codigo')));
        $nombre = trim(post('nombre'));
        $email  = trim(post('email'));
        $color  = trim(post('color')) ?: '#2563eb';
        // wa.me exige el número solo en dígitos y con código de país.
        $whatsapp = preg_replace('/\D+/', '', post('whatsapp'));

        if ($codigo === '' || $nombre === '') {
            flash('error', 'El código y el nombre comercial son obligatorios.');
        } elseif (!preg_match('/^[A-Z0-9\-_]{2,20}$/', $codigo)) {
            flash('error', 'El código admite letras, números y guiones (2 a 20 caracteres).');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'El correo electrónico no es válido.');
        } elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            flash('error', 'El color de marca debe ser un valor hexadecimal como #2563eb.');
        } elseif (qVal("SELECT 1 FROM tiendas WHERE codigo = ? AND id <> ?", [$codigo, $id])) {
            flash('error', 'Ya existe una tienda con ese código.');
        } elseif ($whatsapp !== '' && strlen($whatsapp) < 10) {
            flash('error', 'El WhatsApp debe incluir el código de país. Ej. 1 809 555 0101.');
        } else {
            $logoActual = $id > 0 ? qVal("SELECT logo FROM tiendas WHERE id = ?", [$id]) : null;
            $logo = guardar_imagen('logo', 'tiendas', $logoActual);
            if (post('quitar_logo') === '1') {
                if ($logo && is_file(dirname(__DIR__, 2) . '/' . $logo)) {
                    @unlink(dirname(__DIR__, 2) . '/' . $logo);
                }
                $logo = null;
            }

            $datos = [
                'codigo'              => $codigo,
                'nombre'              => $nombre,
                'razon_social'        => trim(post('razon_social')) ?: null,
                'rnc'                 => trim(post('rnc')) ?: null,
                'direccion'           => trim(post('direccion')) ?: null,
                'ciudad'              => trim(post('ciudad')) ?: null,
                'telefono'            => trim(post('telefono')) ?: null,
                'whatsapp'            => $whatsapp ?: null,
                'email'               => $email ?: null,
                'sitio_web'           => trim(post('sitio_web')) ?: null,
                'logo'                => $logo,
                'color'               => $color,
                'encabezado'          => trim(post('encabezado')) ?: null,
                'mensaje_ticket'      => trim(post('mensaje_ticket')) ?: null,
                'politica_devolucion' => trim(post('politica_devolucion')) ?: null,
                'pie_factura'         => trim(post('pie_factura')) ?: null,
                'orden'               => postInt('orden'),
                'activo'              => postInt('activo', 1),
            ];

            if ($id > 0) {
                require_perm('tiendas.editar');
                dbUpdate('tiendas', $datos, 'id = ?', [$id]);
                audit('tiendas', 'editar', "Tienda actualizada: $nombre", ['tabla' => 'tiendas', 'registro_id' => $id]);
                flash('success', 'Tienda actualizada correctamente.');
            } else {
                require_perm('tiendas.crear');
                $nid = dbInsert('tiendas', $datos);
                audit('tiendas', 'crear', "Tienda creada: $nombre", ['tabla' => 'tiendas', 'registro_id' => $nid]);
                flash('success', 'Tienda «' . $nombre . '» creada. Asígnale productos para que el POS pueda facturar con su marca.');
            }
        }
        redirect('modules/admin/tiendas.php');
    }

    if ($accion === 'eliminar') {
        require_perm('tiendas.eliminar');
        $id = postInt('id');
        $nombre = (string) qVal("SELECT nombre FROM tiendas WHERE id = ?", [$id]);

        // Una marca con facturas emitidas no se borra: esas facturas se
        // reimprimen y tienen que seguir saliendo con su logo.
        $tieneHistorial = qVal("SELECT 1 FROM ventas WHERE tienda_id = ? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM compras WHERE tienda_id = ? LIMIT 1", [$id])
            || qVal("SELECT 1 FROM liquidaciones WHERE tienda_id = ? LIMIT 1", [$id]);

        if ($tieneHistorial) {
            dbUpdate('tiendas', ['activo' => 0], 'id = ?', [$id]);
            audit('tiendas', 'editar', "Tienda desactivada para conservar historial: $nombre", ['tabla' => 'tiendas', 'registro_id' => $id]);
            flash('warning', 'La tienda tiene comprobantes emitidos; se desactivó en lugar de eliminarla.');
        } else {
            $logo = qVal("SELECT logo FROM tiendas WHERE id = ?", [$id]);
            q("DELETE FROM tiendas WHERE id = ?", [$id]);
            if ($logo && is_file(dirname(__DIR__, 2) . '/' . $logo)) @unlink(dirname(__DIR__, 2) . '/' . $logo);
            audit('tiendas', 'eliminar', "Tienda eliminada: $nombre", ['tabla' => 'tiendas', 'registro_id' => $id]);
            flash('success', 'Tienda eliminada.');
        }
        redirect('modules/admin/tiendas.php');
    }
}

// ---------- Listado ----------
$busq   = trim(get('q'));
$where  = $busq !== '' ? "WHERE (t.nombre LIKE ? OR t.codigo LIKE ? OR t.razon_social LIKE ?)" : '';
$params = $busq !== '' ? array_fill(0, 3, '%' . $busq . '%') : [];

$tiendas = qAll(
    "SELECT t.*,
            (SELECT COUNT(*) FROM productos p WHERE p.tienda_id = t.id AND p.activo = 1) AS productos,
            (SELECT COUNT(*) FROM ventas v WHERE v.tienda_id = t.id AND v.estado = 'completada') AS ventas,
            (SELECT COALESCE(SUM(v.subtotal - v.descuento),0) FROM ventas v
              WHERE v.tienda_id = t.id AND v.estado = 'completada') AS ingresos
       FROM tiendas t $where
      ORDER BY t.orden, t.nombre",
    $params
);

$sinMarca = (int) qVal("SELECT COUNT(*) FROM productos WHERE tienda_id IS NULL AND activo = 1");

$acciones = can('tiendas.crear') ? btn_nuevo('tie:new', 'Nueva tienda') : '';
layout_start('Tiendas y marcas', 'La identidad con la que se imprimen facturas y tickets', $acciones);
?>

<!-- Cómo funciona: se explica una vez, arriba, y no en cada campo del formulario -->
<div class="card p-4 mb-5 flex items-start gap-3 bg-blue-50/50 border-blue-100">
  <span class="w-9 h-9 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0"><?= icon('tag', 'w-5 h-5') ?></span>
  <div class="text-sm text-slate-600 leading-relaxed">
    <p class="font-semibold text-slate-800">Una tienda es una marca, no un local.</p>
    <p class="mt-0.5">
      La <strong>sucursal</strong> dice dónde se vende (stock, caja, usuarios). La <strong>tienda</strong> dice
      con qué marca se vende: es el logo y los datos que salen impresos en la factura y en el ticket térmico.
      Un mismo local puede atender varias marcas.
    </p>
    <p class="mt-1 text-slate-500">
      El emisor fiscal sigue siendo <strong><?= e($GLOBALS['empresa']['nombre'] ?? APP_NAME) ?></strong>
      con un solo RNC y una sola secuencia de NCF: la marca cambia el papel, no la declaración a la DGII.
    </p>
  </div>
</div>

<?php if ($tiendas && $sinMarca > 0): ?>
  <div class="card p-4 mb-5 flex items-center gap-3 border-amber-200 bg-amber-50/60">
    <span class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
    <p class="text-sm text-slate-600 flex-1">
      Hay <strong><?= number_format($sinMarca) ?></strong> producto<?= $sinMarca === 1 ? '' : 's' ?> activo<?= $sinMarca === 1 ? '' : 's' ?>
      sin marca asignada. Se pueden vender desde cualquier tienda, pero su factura sale con los datos de la empresa.
    </p>
    <?php if (can('productos.editar')): ?>
      <a href="<?= e(url('modules/inventario/productos.php')) ?>?sin_tienda=1" class="btn btn-soft btn-sm shrink-0">Asignar marca</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por nombre, código o razón social...') ?>
    <span class="text-sm text-slate-400"><?= count($tiendas) ?> tienda<?= count($tiendas) === 1 ? '' : 's' ?></span>
  </div>

  <?php if (!$tiendas): ?>
    <?= empty_state(
        'Todavía no hay tiendas',
        'Crea la primera marca (por ejemplo, L\'Occitane) con su logo y sus datos. Mientras no exista ninguna, las facturas salen con los datos de la empresa.',
        'tag',
        can('tiendas.crear') ? btn_nuevo('tie:new', 'Nueva tienda') : ''
    ) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Marca</th><th>Código</th><th>Punto de venta</th>
          <th class="text-center">Productos</th><th class="text-center">Facturas</th>
          <th class="text-right">Facturado</th><th>Estado</th><th class="text-right">Acciones</th>
        </tr></thead>
        <tbody>
          <?php foreach ($tiendas as $t):
            $marca = tienda_marca((int) $t['id']);
            $logo  = tienda_logo_url($marca);
          ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <?php if ($logo): ?>
                    <img src="<?= e($logo) ?>" alt="Logo de <?= e($t['nombre']) ?>"
                         class="w-10 h-10 rounded-lg object-contain bg-white border border-slate-200 p-0.5">
                  <?php else: ?>
                    <span class="w-10 h-10 rounded-lg text-white text-sm font-bold flex items-center justify-center shrink-0"
                          style="background: <?= e($t['color']) ?>"><?= e(tienda_iniciales($t['nombre'])) ?></span>
                  <?php endif; ?>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-700 truncate"><?= e($t['nombre']) ?></p>
                    <?php if ($t['razon_social']): ?>
                      <p class="text-xs text-slate-400 truncate"><?= e($t['razon_social']) ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td><span class="font-mono text-sm font-semibold text-slate-700"><?= e($t['codigo']) ?></span></td>
              <td class="text-slate-500 text-sm">
                <?= e($t['encabezado'] ?: ($t['direccion'] ?: '—')) ?>
                <?php if ($t['telefono']): ?><br><span class="text-xs text-slate-400">Tel: <?= e($t['telefono']) ?></span><?php endif; ?>
              </td>
              <td class="text-center"><span class="badge badge-slate"><?= number_format((int) $t['productos']) ?></span></td>
              <td class="text-center"><span class="badge badge-blue"><?= number_format((int) $t['ventas']) ?></span></td>
              <td class="text-right tabular-nums font-semibold text-slate-700"><?= money($t['ingresos']) ?></td>
              <td><?= $t['activo'] ? badge('Activa', 'emerald') : badge('Inactiva', 'slate') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('tiendas.editar')): ?>
                    <button onclick="<?= jsEvent('tie:edit', [
                        'id' => $t['id'], 'codigo' => $t['codigo'], 'nombre' => $t['nombre'],
                        'razon_social' => $t['razon_social'], 'rnc' => $t['rnc'], 'direccion' => $t['direccion'],
                        'ciudad' => $t['ciudad'], 'telefono' => $t['telefono'], 'whatsapp' => $t['whatsapp'],
                        'email' => $t['email'], 'sitio_web' => $t['sitio_web'], 'color' => $t['color'],
                        'encabezado' => $t['encabezado'], 'mensaje_ticket' => $t['mensaje_ticket'],
                        'politica_devolucion' => $t['politica_devolucion'], 'pie_factura' => $t['pie_factura'],
                        'orden' => $t['orden'], 'activo' => $t['activo'], 'logo' => $logo ?: '',
                    ]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('tiendas.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la tienda «<?= e($t['nombre']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
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

<!-- Modal crear/editar -->
<?php $vacio = "{id:0,codigo:'',nombre:'',razon_social:'',rnc:'',direccion:'',ciudad:'',telefono:'',whatsapp:'',email:'',sitio_web:'',color:'#2563eb',encabezado:'',mensaje_ticket:'¡Gracias por su compra!',politica_devolucion:'',pie_factura:'',orden:0,activo:1,logo:''}"; ?>
<div x-data="{open:false, quitar:false, previa:'', form:<?= $vacio ?>,
              iniciales() { const p = (this.form.nombre||'').trim().split(/[\s\-·]+/).filter(Boolean);
                            if (!p.length) return '?';
                            return (p.length === 1 ? p[0][0] : p[0][0] + p[1][0]).toUpperCase(); },
              verPrevia(e) { const f = e.target.files && e.target.files[0];
                             if (!f) { this.previa = ''; return; }
                             this.previa = URL.createObjectURL(f); this.quitar = false; } }"
     @tie:new.window="form=<?= $vacio ?>; quitar=false; previa=''; open=true"
     @tie:edit.window="form=$event.detail; quitar=false; previa=''; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-3xl" @click.stop>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <input type="hidden" name="quitar_logo" :value="quitar ? 1 : 0">

        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar tienda' : 'Nueva tienda'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>

        <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto">

          <!-- Identidad -->
          <div class="grid grid-cols-1 sm:grid-cols-6 gap-4">
            <div class="sm:col-span-2">
              <label class="label" for="tie_codigo">Código *</label>
              <input type="text" id="tie_codigo" name="codigo" x-model="form.codigo" required maxlength="20"
                     class="input font-mono uppercase" placeholder="LOCC">
            </div>
            <div class="sm:col-span-4">
              <label class="label" for="tie_nombre">Nombre comercial *</label>
              <input type="text" id="tie_nombre" name="nombre" x-model="form.nombre" required maxlength="120"
                     class="input" placeholder="L'Occitane">
              <p class="mt-1 text-xs text-slate-500">Es el nombre que el cliente lee arriba del ticket.</p>
            </div>
            <div class="sm:col-span-4">
              <label class="label" for="tie_razon">Razón social</label>
              <input type="text" id="tie_razon" name="razon_social" x-model="form.razon_social" maxlength="180"
                     class="input" placeholder="Solo si difiere del nombre comercial">
            </div>
            <div class="sm:col-span-2">
              <label class="label" for="tie_rnc">RNC de la marca</label>
              <input type="text" id="tie_rnc" name="rnc" x-model="form.rnc" maxlength="30" class="input" placeholder="Opcional">
              <p class="mt-1 text-xs text-slate-500">Referencia interna. El NCF se emite con el RNC de la empresa.</p>
            </div>
          </div>

          <!-- Marca visual -->
          <div class="border-t border-slate-100 pt-5">
            <h4 class="font-bold text-slate-800 text-sm mb-3">Marca visual</h4>
            <div class="grid grid-cols-1 sm:grid-cols-6 gap-4 items-start">
              <div class="sm:col-span-2">
                <span class="label">Vista previa</span>
                <div class="rounded-xl border border-slate-200 p-4 flex flex-col items-center justify-center gap-2 bg-slate-50 min-h-[132px]">
                  <template x-if="previa || (form.logo && !quitar)">
                    <img :src="previa || form.logo" alt="Logo de la tienda" class="max-h-16 max-w-full object-contain">
                  </template>
                  <template x-if="!previa && (!form.logo || quitar)">
                    <span class="w-14 h-14 rounded-xl text-white text-lg font-bold flex items-center justify-center"
                          :style="'background:' + form.color" x-text="iniciales()"></span>
                  </template>
                  <p class="text-xs font-semibold text-slate-600 text-center leading-tight" x-text="form.nombre || 'Nombre comercial'"></p>
                  <p class="text-[11px] text-slate-400 text-center leading-tight" x-text="form.encabezado || ''"></p>
                </div>
              </div>
              <div class="sm:col-span-4 space-y-4">
                <div>
                  <label class="label" for="tie_logo">Logo</label>
                  <input type="file" id="tie_logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif"
                         @change="verPrevia($event)"
                         class="block w-full text-sm text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                  <div class="flex items-center justify-between gap-3 mt-1">
                    <p class="text-xs text-slate-500">PNG con fondo transparente, máximo 3 MB. Se imprime en blanco y negro en el ticket térmico.</p>
                    <button type="button" x-show="form.logo && !quitar" @click="quitar=true; previa=''"
                            class="text-xs font-semibold text-rose-600 hover:text-rose-700 shrink-0">Quitar logo</button>
                  </div>
                  <p x-show="quitar" x-cloak class="text-xs font-semibold text-rose-600 mt-1">El logo se eliminará al guardar.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label class="label" for="tie_color">Color de marca</label>
                    <div class="flex items-center gap-2">
                      <input type="color" id="tie_color" name="color" x-model="form.color"
                             class="h-10 w-14 rounded-lg border border-slate-200 cursor-pointer bg-white p-1">
                      <input type="text" x-model="form.color" maxlength="7" class="input font-mono flex-1" aria-label="Color en hexadecimal">
                    </div>
                    <div class="flex gap-1.5 mt-2">
                      <?php foreach ($TIPOS_COLOR as $c): ?>
                        <button type="button" @click="form.color='<?= $c ?>'" title="<?= $c ?>"
                                class="w-6 h-6 rounded-md border border-slate-200 hover:scale-110 transition"
                                style="background: <?= $c ?>"></button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div>
                    <label class="label" for="tie_encabezado">Línea del punto de venta</label>
                    <input type="text" id="tie_encabezado" name="encabezado" x-model="form.encabezado" maxlength="140"
                           class="input" placeholder="L297 · Ágora Mall, Nivel 2">
                    <p class="mt-1 text-xs text-slate-500">Va bajo el nombre, en el ticket.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Datos de contacto -->
          <div class="border-t border-slate-100 pt-5">
            <h4 class="font-bold text-slate-800 text-sm mb-3">Datos que se imprimen</h4>
            <div class="grid grid-cols-1 sm:grid-cols-6 gap-4">
              <div class="sm:col-span-4">
                <label class="label" for="tie_direccion">Dirección</label>
                <input type="text" id="tie_direccion" name="direccion" x-model="form.direccion" maxlength="255" class="input" placeholder="Av. John F. Kennedy #45">
              </div>
              <div class="sm:col-span-2">
                <label class="label" for="tie_ciudad">Ciudad</label>
                <input type="text" id="tie_ciudad" name="ciudad" x-model="form.ciudad" maxlength="80" class="input" placeholder="Santo Domingo">
              </div>
              <div class="sm:col-span-2">
                <label class="label" for="tie_telefono">Teléfono</label>
                <input type="text" id="tie_telefono" name="telefono" x-model="form.telefono" maxlength="40" class="input" placeholder="809-555-0101">
              </div>
              <div class="sm:col-span-2">
                <label class="label" for="tie_whatsapp">WhatsApp</label>
                <input type="text" id="tie_whatsapp" name="whatsapp" x-model="form.whatsapp" maxlength="20" class="input" placeholder="1 809 555 0101">
              </div>
              <div class="sm:col-span-2">
                <label class="label" for="tie_email">Correo</label>
                <input type="email" id="tie_email" name="email" x-model="form.email" maxlength="120" class="input" placeholder="tienda@empresa.do">
              </div>
              <div class="sm:col-span-6">
                <label class="label" for="tie_web">Sitio web</label>
                <input type="text" id="tie_web" name="sitio_web" x-model="form.sitio_web" maxlength="140" class="input" placeholder="www.loccitane.com">
              </div>
            </div>
          </div>

          <!-- Textos del comprobante -->
          <div class="border-t border-slate-100 pt-5">
            <h4 class="font-bold text-slate-800 text-sm mb-3">Textos del comprobante</h4>
            <div class="grid grid-cols-1 gap-4">
              <div>
                <label class="label" for="tie_mensaje">Mensaje de cierre</label>
                <input type="text" id="tie_mensaje" name="mensaje_ticket" x-model="form.mensaje_ticket" maxlength="255"
                       class="input" placeholder="¡Gracias por su compra!">
              </div>
              <div>
                <label class="label" for="tie_politica">Política de devoluciones</label>
                <textarea id="tie_politica" name="politica_devolucion" x-model="form.politica_devolucion" rows="3" class="input"
                          placeholder="Aceptamos cambios dentro de los 30 días posteriores a la compra, presentando este comprobante y con el producto sin usar."></textarea>
                <p class="mt-1 text-xs text-slate-500">Se imprime al pie del ticket y de la factura. PROCONSUMIDOR exige que el cliente pueda leerla.</p>
              </div>
              <div>
                <label class="label" for="tie_pie">Pie de la factura</label>
                <input type="text" id="tie_pie" name="pie_factura" x-model="form.pie_factura" maxlength="255"
                       class="input" placeholder="Distribuido por Importers TyE, S. A.">
              </div>
            </div>
          </div>

          <!-- Estado -->
          <div class="border-t border-slate-100 pt-5 grid grid-cols-1 sm:grid-cols-6 gap-4 items-center">
            <div class="sm:col-span-2">
              <label class="label" for="tie_orden">Orden en los listados</label>
              <input type="number" id="tie_orden" name="orden" x-model.number="form.orden" min="0" max="999" class="input">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-4 cursor-pointer sm:mt-6">
              <input type="hidden" name="activo" value="0">
              <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
              Tienda activa (aparece en el punto de venta)
            </label>
          </div>
        </div>

        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
