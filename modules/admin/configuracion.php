<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('configuracion.ver');

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    require_perm('configuracion.editar');
    $accion = post('accion');

    if ($accion === 'guardar_empresa') {
        $datos = [
            'nombre'         => trim(post('nombre')) ?: 'Mi Empresa',
            'rnc'            => trim(post('rnc')) ?: null,
            'direccion'      => trim(post('direccion')) ?: null,
            'telefono'       => trim(post('telefono')) ?: null,
            'email'          => trim(post('email')) ?: null,
            'moneda'         => trim(post('moneda')) ?: 'RD$',
            'itbis_tasa'     => postNum('itbis_tasa'),
            'mensaje_ticket' => trim(post('mensaje_ticket')) ?: null,
            'logo'           => guardar_imagen('logo', 'logo', setting('logo')),
        ];
        dbUpdate('empresa', $datos, 'id = ?', [1]);
        audit('configuracion', 'editar', 'Datos de la empresa actualizados', ['tabla' => 'empresa', 'registro_id' => 1]);
        flash('success', 'Datos de la empresa guardados.');
        redirect('modules/admin/configuracion.php?tab=empresa');
    }

    if ($accion === 'guardar_ncf') {
        $id = postInt('id');
        $seq = qOne("SELECT id, tipo FROM ncf_secuencias WHERE id = ?", [$id]);
        if ($seq) {
            $datos = [
                'descripcion'      => trim(post('descripcion')) ?: null,
                'secuencia_actual' => max(1, postInt('secuencia_actual', 1)),
                'secuencia_hasta'  => max(1, postInt('secuencia_hasta', 1)),
                'vencimiento'      => trim(post('vencimiento')) ?: null,
                'activo'           => postInt('activo', 0),
            ];
            dbUpdate('ncf_secuencias', $datos, 'id = ?', [$id]);
            audit('configuracion', 'editar', "Secuencia NCF actualizada: {$seq['tipo']}", ['tabla' => 'ncf_secuencias', 'registro_id' => $id]);
            flash('success', 'Secuencia de comprobante actualizada.');
        }
        redirect('modules/admin/configuracion.php?tab=ncf');
    }

    if ($accion === 'toggle_metodo') {
        $id = postInt('id');
        $m = qOne("SELECT id, nombre, activo FROM metodos_pago WHERE id = ?", [$id]);
        if ($m) {
            $nuevo = (int) $m['activo'] === 1 ? 0 : 1;
            dbUpdate('metodos_pago', ['activo' => $nuevo], 'id = ?', [$id]);
            audit('configuracion', 'editar', "Método de pago {$m['nombre']} " . ($nuevo ? 'activado' : 'desactivado'), ['tabla' => 'metodos_pago', 'registro_id' => $id]);
            flash('success', 'Método de pago actualizado.');
        }
        redirect('modules/admin/configuracion.php?tab=metodos');
    }

    if ($accion === 'crear_metodo') {
        $nombre = trim(post('nombre'));
        $afecta = postInt('afecta_caja', 0);
        if ($nombre === '') {
            flash('error', 'El nombre del método de pago es obligatorio.');
        } else {
            $nid = dbInsert('metodos_pago', ['nombre' => $nombre, 'afecta_caja' => $afecta, 'activo' => 1]);
            audit('configuracion', 'crear', "Método de pago creado: $nombre", ['tabla' => 'metodos_pago', 'registro_id' => $nid]);
            flash('success', 'Método de pago creado.');
        }
        redirect('modules/admin/configuracion.php?tab=metodos');
    }
}

// ---------- Datos ----------
$empresa = qOne("SELECT * FROM empresa WHERE id = 1") ?: [];
$ncf     = qAll("SELECT * FROM ncf_secuencias ORDER BY tipo");
$metodos = qAll("SELECT * FROM metodos_pago ORDER BY nombre");
$puedeEditar = can('configuracion.editar');
$tabInicial  = in_array(get('tab'), ['empresa', 'ncf', 'metodos'], true) ? get('tab') : 'empresa';

layout_start('Configuración', 'Ajustes generales del sistema');
?>

<div x-data="{ tab: <?= e(json_encode($tabInicial)) ?> }">
  <!-- Pestañas -->
  <div class="flex items-center gap-1 border-b border-slate-200 mb-6 overflow-x-auto">
    <button @click="tab='empresa'" :class="tab==='empresa' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'" class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px whitespace-nowrap">
      <?= icon('building', 'w-4 h-4') ?> Empresa
    </button>
    <button @click="tab='ncf'" :class="tab==='ncf' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'" class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px whitespace-nowrap">
      <?= icon('receipt', 'w-4 h-4') ?> Comprobantes (NCF)
    </button>
    <button @click="tab='metodos'" :class="tab==='metodos' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'" class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px whitespace-nowrap">
      <?= icon('wallet', 'w-4 h-4') ?> Métodos de pago
    </button>
  </div>

  <!-- ============ Pestaña Empresa ============ -->
  <div x-show="tab==='empresa'" style="display:none">
    <div class="card p-6 max-w-3xl">
      <form method="post" class="space-y-4" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar_empresa">
        <?php $logoActual = setting('logo'); ?>
        <div class="flex items-center gap-4 p-4 rounded-xl bg-slate-50 border border-slate-200 mb-2">
          <div class="w-20 h-20 rounded-xl bg-white border border-slate-200 flex items-center justify-center overflow-hidden shrink-0">
            <?php if ($logoActual && is_file(dirname(__DIR__, 2) . '/' . $logoActual)): ?>
              <img src="<?= e(url($logoActual)) ?>" alt="Logo" class="max-w-full max-h-full object-contain">
            <?php else: ?>
              <span class="text-slate-300"><?= icon('store', 'w-8 h-8') ?></span>
            <?php endif; ?>
          </div>
          <div class="flex-1">
            <label class="label">Logo / Marca registrada</label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" class="block w-full text-sm text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-semibold hover:file:bg-blue-100 cursor-pointer" <?= $puedeEditar ? '' : 'disabled' ?>>
            <p class="text-xs text-slate-400 mt-1">PNG, JPG o WEBP (máx 3 MB). Se usará en los PDF, facturas y reportes.</p>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="label">Nombre de la empresa *</label>
            <input type="text" name="nombre" value="<?= e($empresa['nombre'] ?? '') ?>" required class="input" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">RNC</label>
            <input type="text" name="rnc" value="<?= e($empresa['rnc'] ?? '') ?>" class="input" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Teléfono</label>
            <input type="text" name="telefono" value="<?= e($empresa['telefono'] ?? '') ?>" class="input" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
          <div class="sm:col-span-2">
            <label class="label">Dirección</label>
            <input type="text" name="direccion" value="<?= e($empresa['direccion'] ?? '') ?>" class="input" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Email</label>
            <input type="email" name="email" value="<?= e($empresa['email'] ?? '') ?>" class="input" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Moneda</label>
            <input type="text" name="moneda" value="<?= e($empresa['moneda'] ?? 'RD$') ?>" class="input" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
          <div>
            <label class="label">Tasa ITBIS (%)</label>
            <input type="number" step="0.01" min="0" name="itbis_tasa" value="<?= e($empresa['itbis_tasa'] ?? '18.00') ?>" class="input" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
          <div class="sm:col-span-2">
            <label class="label">Mensaje del ticket</label>
            <input type="text" name="mensaje_ticket" value="<?= e($empresa['mensaje_ticket'] ?? '') ?>" class="input" placeholder="¡Gracias por su compra!" <?= $puedeEditar ? '' : 'disabled' ?>>
          </div>
        </div>
        <?php if ($puedeEditar): ?>
          <div class="flex justify-end pt-2">
            <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar cambios</button>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ============ Pestaña NCF ============ -->
  <div x-show="tab==='ncf'" style="display:none">
    <div class="card overflow-hidden">
      <?php if (!$ncf): ?>
        <?= empty_state('Sin secuencias NCF', 'No hay secuencias de comprobantes configuradas.', 'receipt') ?>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Tipo</th><th>Descripción</th><th class="text-center">Secuencia actual</th><th class="text-center">Hasta</th><th>Vencimiento</th><th>Estado</th><?php if ($puedeEditar): ?><th class="text-right">Acciones</th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach ($ncf as $n): ?>
                <tr>
                  <td><span class="font-mono font-semibold text-slate-700"><?= e($n['tipo']) ?></span></td>
                  <td class="text-slate-500"><?= e($n['descripcion'] ?: '—') ?></td>
                  <td class="text-center font-mono text-slate-700"><?= (int) $n['secuencia_actual'] ?></td>
                  <td class="text-center font-mono text-slate-500"><?= (int) $n['secuencia_hasta'] ?></td>
                  <td class="text-slate-500"><?= $n['vencimiento'] ? e(fechaCorta($n['vencimiento'])) : '—' ?></td>
                  <td><?= $n['activo'] ? badge('Activa', 'emerald') : badge('Inactiva', 'slate') ?></td>
                  <?php if ($puedeEditar): ?>
                    <td>
                      <div class="flex items-center justify-end">
                        <button onclick="<?= jsEvent('ncf:edit', ['id' => $n['id'], 'tipo' => $n['tipo'], 'descripcion' => $n['descripcion'], 'secuencia_actual' => $n['secuencia_actual'], 'secuencia_hasta' => $n['secuencia_hasta'], 'vencimiento' => $n['vencimiento'], 'activo' => $n['activo']]) ?>"
                                class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                      </div>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ Pestaña Métodos de pago ============ -->
  <div x-show="tab==='metodos'" style="display:none">
    <div class="flex items-center justify-end mb-4">
      <?php if ($puedeEditar): ?>
        <?= btn_nuevo('mp:new', 'Nuevo método') ?>
      <?php endif; ?>
    </div>
    <div class="card overflow-hidden">
      <?php if (!$metodos): ?>
        <?= empty_state('Sin métodos de pago', 'Crea el primer método de pago.', 'wallet',
            $puedeEditar ? btn_nuevo('mp:new', 'Nuevo método') : '') ?>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="data-table">
            <thead><tr><th>Método</th><th class="text-center">¿Afecta caja?</th><th>Estado</th><?php if ($puedeEditar): ?><th class="text-right">Acciones</th><?php endif; ?></tr></thead>
            <tbody>
              <?php foreach ($metodos as $m): ?>
                <tr>
                  <td>
                    <div class="flex items-center gap-3">
                      <span class="w-9 h-9 rounded-lg badge-cyan flex items-center justify-center"><?= icon('wallet', 'w-4 h-4') ?></span>
                      <span class="font-semibold text-slate-700"><?= e($m['nombre']) ?></span>
                    </div>
                  </td>
                  <td class="text-center"><?= (int) $m['afecta_caja'] === 1 ? badge('Sí', 'blue') : badge('No', 'slate') ?></td>
                  <td><?= $m['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?></td>
                  <?php if ($puedeEditar): ?>
                    <td>
                      <div class="flex items-center justify-end">
                        <form method="post" class="inline">
                          <?= csrf_field() ?><input type="hidden" name="accion" value="toggle_metodo"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                          <?php if ($m['activo']): ?>
                            <button class="btn btn-soft btn-sm"><?= icon('x', 'w-4 h-4') ?> Desactivar</button>
                          <?php else: ?>
                            <button class="btn btn-success btn-sm"><?= icon('check', 'w-4 h-4') ?> Activar</button>
                          <?php endif; ?>
                        </form>
                      </div>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($puedeEditar): ?>
<!-- Modal editar secuencia NCF -->
<div x-data="{open:false, form:{id:0,tipo:'',descripcion:'',secuencia_actual:1,secuencia_hasta:1,vencimiento:'',activo:1}}"
     @ncf:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar_ncf">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Editar secuencia <span class="font-mono" x-text="form.tipo"></span></h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Descripción</label>
            <input type="text" name="descripcion" x-model="form.descripcion" class="input" placeholder="Ej. Consumidor final">
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Secuencia actual</label>
              <input type="number" min="1" name="secuencia_actual" x-model="form.secuencia_actual" class="input">
            </div>
            <div>
              <label class="label">Secuencia hasta</label>
              <input type="number" min="1" name="secuencia_hasta" x-model="form.secuencia_hasta" class="input">
            </div>
          </div>
          <div>
            <label class="label">Vencimiento</label>
            <input type="date" name="vencimiento" x-model="form.vencimiento" class="input">
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Secuencia activa
          </label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal nuevo método de pago -->
<div x-data="{open:false, form:{nombre:'',afecta_caja:0}}"
     @mp:new.window="form={nombre:'',afecta_caja:0}; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear_metodo">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Nuevo método de pago</h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Transferencia">
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="afecta_caja" value="0">
            <input type="checkbox" name="afecta_caja" value="1" :checked="form.afecta_caja==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Afecta el conteo de caja (efectivo)
          </label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Crear</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
