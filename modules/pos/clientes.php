<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('clientes.ver');

$tipos = ['contado', 'credito'];

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        $id         = postInt('id');
        $nombre     = trim(post('nombre'));
        $rncCedula  = trim(post('rnc_cedula'));
        $telefono   = trim(post('telefono'));
        $email      = trim(post('email'));
        $direccion  = trim(post('direccion'));
        $tipo       = in_array(post('tipo'), $tipos, true) ? post('tipo') : 'contado';
        $limite     = $tipo === 'credito' ? postNum('limite_credito') : 0;
        $activo     = postInt('activo', 1);

        if ($nombre === '') {
            flash('error', 'El nombre del cliente es obligatorio.');
        } else {
            $datos = [
                'nombre'         => $nombre,
                'rnc_cedula'     => $rncCedula ?: null,
                'telefono'       => $telefono ?: null,
                'email'          => $email ?: null,
                'direccion'      => $direccion ?: null,
                'tipo'           => $tipo,
                'limite_credito' => $limite,
                'activo'         => $activo,
            ];
            if ($id > 0) {
                require_perm('clientes.editar');
                dbUpdate('clientes', $datos, 'id = ?', [$id]);
                audit('clientes', 'editar', "Cliente actualizado: $nombre", ['tabla' => 'clientes', 'registro_id' => $id]);
                flash('success', 'Cliente actualizado correctamente.');
            } else {
                require_perm('clientes.crear');
                $datos['codigo'] = nextNumero('clientes', 'codigo', 'CLI', 5);
                $nid = dbInsert('clientes', $datos);
                audit('clientes', 'crear', "Cliente creado: $nombre", ['tabla' => 'clientes', 'registro_id' => $nid]);
                flash('success', 'Cliente creado correctamente.');
            }
        }
        redirect('modules/pos/clientes.php');
    }

    if ($accion === 'eliminar') {
        require_perm('clientes.eliminar');
        $id = postInt('id');
        if ($id === 1) {
            flash('error', 'El Cliente Genérico no se puede eliminar.');
        } else {
            $nVentas = (int) qVal("SELECT COUNT(*) FROM ventas WHERE cliente_id = ?", [$id]);
            if ($nVentas > 0) {
                flash('error', "No se puede eliminar: el cliente tiene $nVentas venta(s) registradas.");
            } else {
                $nombre = qVal("SELECT nombre FROM clientes WHERE id = ?", [$id]);
                q("DELETE FROM clientes WHERE id = ?", [$id]);
                audit('clientes', 'eliminar', "Cliente eliminado: $nombre", ['tabla' => 'clientes', 'registro_id' => $id]);
                flash('success', 'Cliente eliminado.');
            }
        }
        redirect('modules/pos/clientes.php');
    }
}

// ---------- KPIs ----------
$totalClientes = (int) qVal("SELECT COUNT(*) FROM clientes");
$clientesCredito = (int) qVal("SELECT COUNT(*) FROM clientes WHERE tipo = 'credito'");
$balanceTotal = (float) qVal("SELECT COALESCE(SUM(balance),0) FROM clientes");

// ---------- Listado ----------
$q = trim(get('q'));
$where = $q !== '' ? "WHERE (c.nombre LIKE ? OR c.rnc_cedula LIKE ? OR c.telefono LIKE ?)" : '';
$params = $q !== '' ? ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'] : [];
$clientes = qAll(
    "SELECT c.* FROM clientes c $where ORDER BY (c.id = 1) DESC, c.nombre",
    $params
);

if (export_solicitado()) {
    export_tabla('clientes', ['Código', 'Nombre', 'RNC/Cédula', 'Teléfono', 'Email', 'Tipo', 'Límite crédito', 'Balance', 'Estado'],
        array_map(fn($c) => [$c['codigo'], $c['nombre'], $c['rnc_cedula'], $c['telefono'], $c['email'], $c['tipo'], $c['limite_credito'], $c['balance'], $c['activo'] ? 'Activo' : 'Inactivo'], $clientes));
}

$acciones = export_buttons() . (can('clientes.crear') ? btn_nuevo('cli:new', 'Nuevo cliente') : '');
layout_start('Clientes', 'Administra los clientes y sus cuentas por cobrar', $acciones);
?>

<!-- KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><?= icon('users', 'w-5 h-5') ?></div>
    <div><p class="text-sm text-slate-500">Total de clientes</p><p class="text-2xl font-extrabold text-slate-800"><?= number_format($totalClientes) ?></p></div>
  </div>
  <div class="card p-5 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0"><?= icon('id', 'w-5 h-5') ?></div>
    <div><p class="text-sm text-slate-500">Clientes a crédito</p><p class="text-2xl font-extrabold text-slate-800"><?= number_format($clientesCredito) ?></p></div>
  </div>
  <div class="card p-5 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0"><?= icon('wallet', 'w-5 h-5') ?></div>
    <div><p class="text-sm text-slate-500">Balance por cobrar</p><p class="text-2xl font-extrabold text-slate-800"><?= money($balanceTotal) ?></p></div>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar por nombre, RNC/cédula o teléfono...') ?>
    <span class="text-sm text-slate-400"><?= count($clientes) ?> clientes</span>
  </div>

  <?php if (!$clientes): ?>
    <?= empty_state('Sin clientes', 'Crea tu primer cliente para registrar sus ventas.', 'users',
        can('clientes.crear') ? btn_nuevo('cli:new', 'Nuevo cliente') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Cliente</th><th>RNC/Cédula</th><th>Teléfono</th><th>Tipo</th><th class="text-right">Balance</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($clientes as $c): ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <?= avatar($c['nombre']) ?>
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-700 truncate"><?= e($c['nombre']) ?></p>
                    <p class="text-xs text-slate-400 font-mono"><?= e($c['codigo']) ?></p>
                  </div>
                </div>
              </td>
              <td class="text-slate-500"><?= e($c['rnc_cedula'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($c['telefono'] ?: '—') ?></td>
              <td><?= $c['tipo'] === 'credito' ? badge('Crédito', 'indigo') : badge('Contado', 'slate') ?></td>
              <td class="text-right font-bold <?= $c['balance'] > 0 ? 'text-amber-600' : 'text-slate-700' ?>"><?= money($c['balance']) ?></td>
              <td><?= $c['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('clientes.editar')): ?>
                    <button onclick="<?= jsEvent('cli:edit', ['id' => $c['id'], 'nombre' => $c['nombre'], 'rnc_cedula' => $c['rnc_cedula'], 'telefono' => $c['telefono'], 'email' => $c['email'], 'direccion' => $c['direccion'], 'tipo' => $c['tipo'], 'limite_credito' => $c['limite_credito'], 'activo' => $c['activo']]) ?>"
                            class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('clientes.eliminar') && (int) $c['id'] !== 1): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar el cliente «<?= e($c['nombre']) ?>»?')">
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

<!-- Modal crear/editar -->
<div x-data="{open:false, form:{id:0,nombre:'',rnc_cedula:'',telefono:'',email:'',direccion:'',tipo:'contado',limite_credito:0,activo:1}}"
     @cli:new.window="form={id:0,nombre:'',rnc_cedula:'',telefono:'',email:'',direccion:'',tipo:'contado',limite_credito:0,activo:1}; open=true"
     @cli:edit.window="form=$event.detail; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar cliente' : 'Nuevo cliente'"></h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Juan Pérez">
          </div>
          <div>
            <label class="label">RNC / Cédula</label>
            <input type="text" name="rnc_cedula" x-model="form.rnc_cedula" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Teléfono</label>
            <input type="text" name="telefono" x-model="form.telefono" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Email</label>
            <input type="email" name="email" x-model="form.email" class="input" placeholder="Opcional">
          </div>
          <div>
            <label class="label">Tipo</label>
            <select name="tipo" x-model="form.tipo" class="select">
              <option value="contado">Contado</option>
              <option value="credito">Crédito</option>
            </select>
          </div>
          <div class="sm:col-span-2">
            <label class="label">Dirección</label>
            <input type="text" name="direccion" x-model="form.direccion" class="input" placeholder="Opcional">
          </div>
          <div class="sm:col-span-2" x-show="form.tipo === 'credito'" x-transition>
            <label class="label">Límite de crédito (RD$)</label>
            <input type="number" step="0.01" min="0" name="limite_credito" x-model="form.limite_credito" class="input" placeholder="0.00">
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Cliente activo
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

<?php layout_end(); ?>
