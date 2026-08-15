<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('proveedores.ver');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = postInt('id');
        $nombre = trim(post('nombre'));
        if ($nombre === '') {
            flash('error', 'El nombre es obligatorio.');
        } else {
            $data = [
                'nombre' => $nombre, 'rnc' => trim(post('rnc')) ?: null, 'contacto' => trim(post('contacto')) ?: null,
                'telefono' => trim(post('telefono')) ?: null, 'email' => trim(post('email')) ?: null,
                'direccion' => trim(post('direccion')) ?: null, 'activo' => postInt('activo', 0) ? 1 : 0,
            ];
            // Ficha sanitaria del proveedor. El inspector pregunta a quien se le
            // compra y si esta habilitado; sin esto la respuesta es buscar el papel.
            if (san_disponible() && can('sanidad.editar')) {
                $data += [
                    'licencia_sanitaria'   => trim(post('licencia_sanitaria')) ?: null,
                    'licencia_vencimiento' => post('licencia_vencimiento') ?: null,
                    'pais_origen'          => trim(post('pais_origen')) ?: null,
                    'notas_sanitarias'     => trim(post('notas_sanitarias')) ?: null,
                ];
            }
            if ($id > 0) {
                require_perm('proveedores.editar');
                dbUpdate('proveedores', $data, 'id = ?', [$id]);
                audit('proveedores', 'editar', "Proveedor actualizado: $nombre", ['tabla' => 'proveedores', 'registro_id' => $id]);
                flash('success', 'Proveedor actualizado.');
            } else {
                require_perm('proveedores.crear');
                $data['codigo'] = nextNumero('proveedores', 'codigo', 'PRV', 3);
                $nid = dbInsert('proveedores', $data);
                audit('proveedores', 'crear', "Proveedor creado: $nombre", ['tabla' => 'proveedores', 'registro_id' => $nid]);
                flash('success', 'Proveedor creado.');
            }
        }
        redirect('modules/inventario/proveedores.php');
    }
    if ($accion === 'eliminar') {
        require_perm('proveedores.eliminar');
        $id = postInt('id');
        if (qVal("SELECT 1 FROM compras WHERE proveedor_id = ? LIMIT 1", [$id])) {
            flash('error', 'No se puede eliminar: el proveedor tiene compras registradas.');
        } else {
            $nombre = qVal("SELECT nombre FROM proveedores WHERE id = ?", [$id]);
            q("DELETE FROM proveedores WHERE id = ?", [$id]);
            audit('proveedores', 'eliminar', "Proveedor eliminado: $nombre", ['tabla' => 'proveedores', 'registro_id' => $id]);
            flash('success', 'Proveedor eliminado.');
        }
        redirect('modules/inventario/proveedores.php');
    }
}

$q = trim(get('q'));
// Una licencia sanitaria vencida no es un detalle administrativo: importar
// cosméticos con el suplidor sin licencia al día es lo que traba un embarque en
// aduana. Por eso es filtro y es indicador, no un dato escondido en la ficha.
$estadoF = in_array(get('estado'), ['activos', 'inactivos', 'licencia_vencida', 'con_deuda'], true) ? get('estado') : '';

$cond = [];
$params = [];
if ($q !== '') { $cond[] = "(nombre LIKE ? OR rnc LIKE ? OR contacto LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($estadoF === 'activos')          $cond[] = 'activo = 1';
if ($estadoF === 'inactivos')        $cond[] = 'activo = 0';
if ($estadoF === 'licencia_vencida') $cond[] = "licencia_vencimiento IS NOT NULL AND licencia_vencimiento < CURDATE()";
if ($estadoF === 'con_deuda')        $cond[] = "EXISTS (SELECT 1 FROM compras c WHERE c.proveedor_id = proveedores.id AND c.estado <> 'anulada' AND c.saldo > 0.01)";
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

if (export_solicitado()) {
    $todos = qAll("SELECT * FROM proveedores $where ORDER BY nombre", $params);
    export_tabla('proveedores',
        ['Código', 'Nombre', 'RNC', 'Contacto', 'Teléfono', 'Email', 'Dirección', 'País de origen', 'Licencia sanitaria', 'Vence', 'Estado'],
        array_map(fn($p) => [
            $p['codigo'], $p['nombre'], $p['rnc'], $p['contacto'], $p['telefono'], $p['email'], $p['direccion'],
            $p['pais_origen'] ?? '', $p['licencia_sanitaria'] ?? '', $p['licencia_vencimiento'] ?? '',
            $p['activo'] ? 'Activo' : 'Inactivo',
        ], $todos));
}

$pg = paginar((int) qVal("SELECT COUNT(*) FROM proveedores $where", $params), 25);
$proveedores = qAll("SELECT * FROM proveedores $where ORDER BY nombre LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $params);

$salud = qOne(
    "SELECT COUNT(*) total,
            COALESCE(SUM(activo = 1), 0) activos,
            COALESCE(SUM(licencia_vencimiento IS NOT NULL AND licencia_vencimiento < CURDATE()), 0) vencidas,
            (SELECT COALESCE(SUM(c.saldo), 0) FROM compras c WHERE c.estado <> 'anulada' AND c.saldo > 0.01) por_pagar
       FROM proveedores"
) ?: ['total' => 0, 'activos' => 0, 'vencidas' => 0, 'por_pagar' => 0];

$urlEstado = fn(string $es) => '?' . http_build_query(array_filter(['q' => $q ?: null, 'estado' => $es ?: null]));

$acciones = export_buttons() . (can('proveedores.crear') ? btn_nuevo('prov:new', 'Nuevo proveedor') : '');
layout_start('Proveedores', 'Gestiona tus suplidores de mercancía', $acciones);

echo kpis([
    ['label' => 'Proveedores activos', 'valor' => number_format((int) $salud['activos']), 'icono' => 'briefcase', 'color' => 'indigo',
     'nota' => (int) $salud['total'] > (int) $salud['activos']
        ? number_format((int) $salud['total'] - (int) $salud['activos']) . ' inactivos' : 'Todos activos'],
    ['label' => 'Por pagar', 'valor' => money($salud['por_pagar']), 'icono' => 'wallet', 'color' => 'amber',
     'nota' => 'Saldo de facturas abiertas', 'href' => url('modules/inventario/cuentas_pagar.php')],
    ['label' => 'Licencia vencida', 'valor' => number_format((int) $salud['vencidas']),
     'icono' => 'alert', 'color' => (int) $salud['vencidas'] > 0 ? 'rose' : 'slate',
     'nota' => (int) $salud['vencidas'] > 0 ? 'Revisar antes de importar' : 'Todas al día',
     'href' => (int) $salud['vencidas'] > 0 ? $urlEstado('licencia_vencida') : ''],
], 3);

ob_start(); ?>
      <form method="get" class="flex items-center gap-2">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <select name="estado" onchange="this.form.submit()" class="select w-52" aria-label="Filtrar proveedores">
          <option value="">Todos los proveedores</option>
          <option value="activos"           <?= $estadoF === 'activos'           ? 'selected' : '' ?>>Solo activos</option>
          <option value="inactivos"         <?= $estadoF === 'inactivos'         ? 'selected' : '' ?>>Solo inactivos</option>
          <option value="licencia_vencida"  <?= $estadoF === 'licencia_vencida'  ? 'selected' : '' ?>>Licencia vencida</option>
          <option value="con_deuda"         <?= $estadoF === 'con_deuda'         ? 'selected' : '' ?>>Con saldo por pagar</option>
        </select>
      </form>
<?php $filtroEstado = ob_get_clean(); ?>

<div class="card overflow-hidden">
  <?= toolbar(
        search_box('Buscar por nombre, RNC o contacto...', array_filter(['estado' => $estadoF ?: null])) . $filtroEstado,
        toolbar_conteo($pg['total'], 'proveedor', 'proveedores')
      ) ?>
  <?php if (!$proveedores): ?>
    <?= empty_state('Sin proveedores', 'Registra tus suplidores para gestionar compras.', 'briefcase', can('proveedores.crear') ? btn_nuevo('prov:new', 'Nuevo proveedor') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Proveedor</th><th>RNC</th><th>Contacto</th><th>Teléfono</th><th>Email</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($proveedores as $p): ?>
            <tr>
              <td><div class="flex items-center gap-3"><span class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center"><?= icon('briefcase', 'w-4 h-4') ?></span><div><p class="font-semibold text-slate-700"><?= e($p['nombre']) ?></p><p class="text-xs text-slate-400"><?= e($p['codigo']) ?></p></div></div></td>
              <td class="text-slate-500"><?= e($p['rnc'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($p['contacto'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($p['telefono'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($p['email'] ?: '—') ?></td>
              <td>
                <?php $vencida = !empty($p['licencia_vencimiento']) && $p['licencia_vencimiento'] < date('Y-m-d'); ?>
                <div class="flex flex-wrap items-center gap-1.5">
                  <?= $p['activo'] ? badge('Activo', 'emerald') : badge('Inactivo', 'slate') ?>
                  <?php if ($vencida): ?>
                    <span class="badge badge-rose" title="La licencia sanitaria venció el <?= e(fechaCorta($p['licencia_vencimiento'])) ?>">
                      <?= icon('alert', 'w-3 h-3') ?> Licencia vencida</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?= acciones([
                    can('proveedores.editar') ? btn_icono([
                        'icono' => 'edit', 'titulo' => 'Editar proveedor',
                        'onclick' => jsEvent('prov:edit', ['id'=>$p['id'],'nombre'=>$p['nombre'],'rnc'=>$p['rnc'],'contacto'=>$p['contacto'],'telefono'=>$p['telefono'],'email'=>$p['email'],'direccion'=>$p['direccion'],'activo'=>$p['activo'],'licencia_sanitaria'=>$p['licencia_sanitaria'] ?? '','licencia_vencimiento'=>$p['licencia_vencimiento'] ?? '','pais_origen'=>$p['pais_origen'] ?? '','notas_sanitarias'=>$p['notas_sanitarias'] ?? '']),
                    ]) : '',
                    can('proveedores.eliminar') ? btn_eliminar([
                        'id' => (int) $p['id'], 'titulo' => 'Eliminar proveedor',
                        'pregunta' => '¿Eliminar el proveedor «' . $p['nombre'] . '»?',
                    ]) : '',
                ]) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<div x-data="{open:false, form:{}}"
     @prov:new.window="form={id:0,nombre:'',rnc:'',contacto:'',telefono:'',email:'',direccion:'',activo:1,licencia_sanitaria:'',licencia_vencimiento:'',pais_origen:'',notas_sanitarias:''}; open=true"
     @prov:edit.window="form=$event.detail; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar proveedor' : 'Nuevo proveedor'"></h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2"><label class="label">Nombre / Razón social *</label><input name="nombre" x-model="form.nombre" required class="input"></div>
          <div><label class="label">RNC</label><input name="rnc" x-model="form.rnc" class="input"></div>
          <div><label class="label">Contacto</label><input name="contacto" x-model="form.contacto" class="input"></div>
          <div><label class="label">Teléfono</label><input name="telefono" x-model="form.telefono" class="input"></div>
          <div><label class="label">Email</label><input type="email" name="email" x-model="form.email" class="input"></div>
          <div class="sm:col-span-2"><label class="label">Dirección</label><input name="direccion" x-model="form.direccion" class="input"></div>
<?php if (san_disponible() && can('sanidad.editar')): ?>
          <div class="sm:col-span-2 pt-3 mt-1 border-t border-slate-100">
            <p class="font-semibold text-slate-700 text-sm flex items-center gap-1.5"><?= icon('shield', 'w-4 h-4 text-blue-600') ?> Ficha sanitaria</p>
            <p class="text-xs text-slate-500 mt-0.5">Salud Pública pregunta a quién se le compra la mercancía regulada y si ese proveedor está habilitado.</p>
          </div>
          <div><label class="label">Licencia sanitaria</label><input name="licencia_sanitaria" x-model="form.licencia_sanitaria" class="input font-mono"></div>
          <div><label class="label">Licencia vence el</label><input type="date" name="licencia_vencimiento" x-model="form.licencia_vencimiento" class="input"></div>
          <div><label class="label">País de origen</label><input name="pais_origen" x-model="form.pais_origen" class="input"></div>
          <div><label class="label">Notas sanitarias</label><input name="notas_sanitarias" x-model="form.notas_sanitarias" class="input" placeholder="Certificaciones, observaciones…"></div>
<?php endif; ?>
          <label class="flex items-center gap-2 text-sm text-slate-600"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600"> Activo</label>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button></div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
