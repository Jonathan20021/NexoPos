<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('transferencias.ver');

/** Valida origen/destino/líneas de un formulario de transferencia. @return array{origen,destino,fecha,det} */
function transferenciaValidarEntrada(): array
{
    $origen = postInt('sucursal_origen_id');
    $destino = postInt('sucursal_destino_id');
    $fecha = post('fecha') ?: date('Y-m-d');
    $lineas = json_decode(post('lineas', '[]'), true);
    if ($origen <= 0 || $destino <= 0 || $origen === $destino || !is_array($lineas) || !$lineas) {
        throw new RuntimeException('Selecciona origen y destino distintos y agrega productos.');
    }
    require_sucursal_access($origen);
    if (!qVal("SELECT 1 FROM sucursales WHERE id=? AND activo=1", [$destino])) {
        throw new RuntimeException('La sucursal de destino no es válida.');
    }
    $det = [];
    foreach ($lineas as $l) {
        $pid = (int) ($l['producto_id'] ?? 0); $cant = (float) ($l['cantidad'] ?? 0);
        if ($pid <= 0 || $cant <= 0) continue;
        $det[$pid] = ['pid' => $pid, 'cant' => ($det[$pid]['cant'] ?? 0) + $cant];
    }
    if (!$det) throw new RuntimeException('No hay líneas válidas.');
    return ['origen' => $origen, 'destino' => $destino, 'fecha' => $fecha, 'det' => array_values($det)];
}

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    // Crear: guarda como borrador y, si se pidió, lo envía en el mismo paso.
    if ($accion === 'guardar') {
        require_perm('transferencias.crear');
        $enviarYa = post('modo') === 'enviar';
        if ($enviarYa) require_perm('transferencias.enviar');
        try {
            $in = transferenciaValidarEntrada();
            $tid = tx(function () use ($in, $enviarYa) {
                $numero = nextNumero('transferencias', 'numero', 'TRF');
                $tid = dbInsert('transferencias', ['numero' => $numero, 'sucursal_origen_id' => $in['origen'], 'sucursal_destino_id' => $in['destino'], 'fecha' => $in['fecha'], 'estado' => 'borrador', 'usuario_id' => current_user()['id']]);
                foreach ($in['det'] as $d) {
                    dbInsert('transferencia_detalles', ['transferencia_id' => $tid, 'producto_id' => $d['pid'], 'cantidad' => $d['cant']]);
                }
                if ($enviarYa) transferenciaEnviar($tid);
                return $tid;
            });
            audit('transferencias', $enviarYa ? 'enviar' : 'crear', ($enviarYa ? 'Transferencia enviada' : 'Borrador de transferencia creado') . " #$tid", ['tabla' => 'transferencias', 'registro_id' => $tid]);
            flash('success', $enviarYa ? 'Transferencia enviada. Stock descontado del origen.' : 'Borrador guardado. Puedes editarlo y enviarlo cuando esté listo.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/transferencias.php');
    }

    // Editar un borrador: reemplaza sus líneas (no toca stock, sigue en borrador).
    if ($accion === 'editar') {
        require_perm('transferencias.crear');
        $id = postInt('id');
        try {
            $in = transferenciaValidarEntrada();
            tx(function () use ($id, $in) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'borrador') throw new RuntimeException('Solo se puede editar un borrador.');
                if (!can_access_sucursal($t['sucursal_origen_id'])) deny_access();
                dbUpdate('transferencias', ['sucursal_origen_id' => $in['origen'], 'sucursal_destino_id' => $in['destino'], 'fecha' => $in['fecha']], 'id=?', [$id]);
                q("DELETE FROM transferencia_detalles WHERE transferencia_id=?", [$id]);
                foreach ($in['det'] as $d) {
                    dbInsert('transferencia_detalles', ['transferencia_id' => $id, 'producto_id' => $d['pid'], 'cantidad' => $d['cant']]);
                }
            });
            audit('transferencias', 'editar', "Borrador de transferencia editado #$id", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Borrador actualizado.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Eliminar un borrador (nunca movió stock).
    if ($accion === 'eliminar') {
        require_perm('transferencias.crear');
        $id = postInt('id');
        try {
            tx(function () use ($id) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'borrador') throw new RuntimeException('Solo se puede eliminar un borrador.');
                if (!can_access_sucursal($t['sucursal_origen_id'])) deny_access();
                q("DELETE FROM transferencia_detalles WHERE transferencia_id=?", [$id]);
                q("DELETE FROM transferencias WHERE id=?", [$id]);
            });
            audit('transferencias', 'eliminar', "Borrador de transferencia eliminado #$id");
            flash('success', 'Borrador eliminado.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Enviar un borrador ya guardado.
    if ($accion === 'enviar') {
        require_perm('transferencias.enviar');
        $id = postInt('id');
        try {
            tx(fn() => transferenciaEnviar($id));
            audit('transferencias', 'enviar', "Transferencia enviada #$id", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Transferencia enviada. Stock descontado del origen.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    if ($accion === 'recibir') {
        require_perm('transferencias.recibir');
        $id = postInt('id');
        try {
            tx(function () use ($id) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'enviada') throw new RuntimeException('La transferencia no se puede recibir.');
                if (!can_access_sucursal($t['sucursal_destino_id'])) throw new RuntimeException('Solo la sucursal de destino puede recibir esta transferencia.');
                foreach (qAll("SELECT * FROM transferencia_detalles WHERE transferencia_id=?", [$id]) as $d) {
                    ajustarStock((int) $d['producto_id'], (int) $t['sucursal_destino_id'], (float) $d['cantidad'], 'transferencia_entrada', 'transferencia', $id, 0, 'Transferencia ' . $t['numero'] . ' (entrada)');
                }
                dbUpdate('transferencias', ['estado' => 'recibida', 'recibida_por' => current_user()['id'], 'recibida_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            });
            audit('transferencias', 'recibir', "Transferencia recibida #$id", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Transferencia recibida. Stock agregado al destino.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    // Rechazar: el destino no acepta una enviada; el stock vuelve al origen.
    if ($accion === 'rechazar') {
        require_perm('transferencias.rechazar');
        $id = postInt('id');
        $motivo = trim(post('motivo_rechazo'));
        try {
            if ($motivo === '') throw new RuntimeException('Indica el motivo del rechazo.');
            tx(function () use ($id, $motivo) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'enviada') throw new RuntimeException('Solo se puede rechazar una transferencia enviada.');
                if (!can_access_sucursal($t['sucursal_destino_id'])) throw new RuntimeException('Solo la sucursal de destino puede rechazar esta transferencia.');
                transferenciaDevolverStock($t);
                dbUpdate('transferencias', ['estado' => 'rechazada', 'motivo_rechazo' => $motivo, 'recibida_por' => current_user()['id'], 'recibida_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            });
            audit('transferencias', 'rechazar', "Transferencia rechazada #$id: $motivo", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Transferencia rechazada. Stock devuelto al origen.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }

    if ($accion === 'anular') {
        require_perm('transferencias.anular');
        $id = postInt('id');
        try {
            tx(function () use ($id) {
                $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
                if (!$t || $t['estado'] !== 'enviada') throw new RuntimeException('Solo se pueden anular transferencias enviadas (no recibidas).');
                if (!can_access_sucursal($t['sucursal_origen_id'])) throw new RuntimeException('Solo la sucursal de origen puede anular esta transferencia.');
                transferenciaDevolverStock($t);
                dbUpdate('transferencias', ['estado' => 'anulada'], 'id=?', [$id]);
            });
            audit('transferencias', 'anular', "Transferencia anulada #$id", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Transferencia anulada. Stock devuelto al origen.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect('modules/inventario/transferencias.php');
    }
}

// ----- Detalle -----
$verId = (int) get('ver');
if ($verId) {
    $t = qOne("SELECT t.*, so.nombre AS origen, sd.nombre AS destino, u.nombre AS usuario FROM transferencias t JOIN sucursales so ON so.id=t.sucursal_origen_id JOIN sucursales sd ON sd.id=t.sucursal_destino_id LEFT JOIN usuarios u ON u.id=t.usuario_id WHERE t.id=?", [$verId]);
    if (!$t) { flash('error', 'Transferencia no encontrada.'); redirect('modules/inventario/transferencias.php'); }
    if (!can_access_sucursal($t['sucursal_origen_id']) && !can_access_sucursal($t['sucursal_destino_id'])) {
        deny_access();
    }
    $det = qAll("SELECT td.*, p.nombre AS producto, p.codigo FROM transferencia_detalles td JOIN productos p ON p.id=td.producto_id WHERE td.transferencia_id=?", [$verId]);
    layout_start('Transferencia ' . e($t['numero']), 'Detalle', '<a href="' . url('modules/inventario/transferencias.php') . '" class="btn btn-ghost">' . icon('arrow-left', 'w-4 h-4') . ' Volver</a>');
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="card lg:col-span-2 overflow-hidden"><table class="data-table"><thead><tr><th>Producto</th><th class="text-right">Cantidad</th></tr></thead><tbody><?php foreach ($det as $d): ?><tr><td><p class="font-semibold text-slate-700"><?= e($d['producto']) ?></p><p class="text-xs text-slate-400"><?= e($d['codigo']) ?></p></td><td class="text-right font-bold text-slate-800"><?= qty($d['cantidad']) ?></td></tr><?php endforeach; ?></tbody></table></div>
      <div class="card p-5 h-fit space-y-3">
        <div class="flex items-center justify-between"><span class="text-xs text-slate-400">Estado</span><?= badgeFor($t['estado']) ?></div>
        <div class="flex items-center gap-2 text-sm"><span class="badge badge-slate"><?= e($t['origen']) ?></span><?= icon('arrow-right', 'w-4 h-4 text-slate-300') ?><span class="badge badge-blue"><?= e($t['destino']) ?></span></div>
        <div><p class="text-xs text-slate-400">Fecha</p><p class="font-semibold text-slate-700"><?= fechaCorta($t['fecha']) ?></p></div>
        <div><p class="text-xs text-slate-400">Creada por</p><p class="font-semibold text-slate-700"><?= e($t['usuario'] ?: '—') ?></p></div>
        <?php if ($t['estado'] === 'rechazada' && !empty($t['motivo_rechazo'])): ?>
          <div class="rounded-xl bg-rose-50 border border-rose-100 p-3">
            <p class="text-xs text-rose-500 font-semibold">Motivo del rechazo</p>
            <p class="text-sm text-slate-700 mt-0.5"><?= e($t['motivo_rechazo']) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php layout_end(); return;
}

// ----- Listado -----
// Una transferencia toca dos sucursales, así que el alcance mira origen y destino.
$sid = current_sucursal_id();
$scope = $sid === null ? '1=1' : "(t.sucursal_origen_id = $sid OR t.sucursal_destino_id = $sid)";

$q = trim(get('q'));
$estadoT = in_array(get('estado'), ['borrador', 'enviada', 'recibida', 'rechazada', 'anulada'], true) ? get('estado') : '';
$cond = [$scope];
$params = [];
if ($q !== '')       { $cond[] = "(t.numero LIKE ? OR so.nombre LIKE ? OR sd.nombre LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($estadoT !== '') { $cond[] = "t.estado = ?"; $params[] = $estadoT; }
$where = implode(' AND ', $cond);

$joinT = "FROM transferencias t JOIN sucursales so ON so.id=t.sucursal_origen_id JOIN sucursales sd ON sd.id=t.sucursal_destino_id WHERE $where";
$pg = paginar((int) qVal("SELECT COUNT(*) $joinT", $params), 25);
$transferencias = qAll("SELECT t.*, so.nombre AS origen, sd.nombre AS destino $joinT ORDER BY t.id DESC LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}", $params);

$productosJs = array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre']], qAll("SELECT id, nombre FROM productos WHERE activo=1 AND tipo='producto' ORDER BY nombre"));
$sucursales = qAll("SELECT id, nombre FROM sucursales WHERE activo=1 ORDER BY nombre");

// Líneas de los borradores visibles, para poder editarlos desde el modal sin otra consulta por fila.
$lineasPorTrf = [];
$idsBorrador = array_values(array_map(fn($t) => (int) $t['id'], array_filter($transferencias, fn($t) => $t['estado'] === 'borrador')));
if ($idsBorrador) {
    $ph = implode(',', array_fill(0, count($idsBorrador), '?'));
    foreach (qAll("SELECT td.transferencia_id, td.producto_id, td.cantidad, p.nombre FROM transferencia_detalles td JOIN productos p ON p.id=td.producto_id WHERE td.transferencia_id IN ($ph)", $idsBorrador) as $r) {
        $lineasPorTrf[(int) $r['transferencia_id']][] = ['producto_id' => (int) $r['producto_id'], 'nombre' => $r['nombre'], 'cantidad' => (float) $r['cantidad']];
    }
}

$acciones = (can('transferencias.crear') && count($sucursales) > 1) ? '<button onclick="' . jsEvent('trf:new') . '" class="btn btn-primary">' . icon('transfer', 'w-4 h-4') . ' Nueva transferencia</button>' : '';
layout_start('Transferencias', 'Movimiento de inventario entre sucursales', $acciones);

if (count($sucursales) < 2):
    echo empty_state('Se necesitan al menos 2 sucursales', 'Crea otra sucursal para transferir inventario entre ellas.', 'transfer');
    layout_end(); return;
endif;
?>

<div class="card overflow-hidden">
  <form method="get" class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <div class="flex items-center gap-2 flex-wrap">
      <input type="hidden" name="p" value="1">
      <input type="search" name="q" data-buscar value="<?= e($q) ?>" placeholder="Número o sucursal..." aria-label="Buscar transferencia" autocomplete="off" class="input w-64">
      <select name="estado" aria-label="Estado" class="select cursor-pointer">
        <option value="">Todos los estados</option>
        <?php foreach (['borrador' => 'Borrador', 'enviada' => 'Enviada', 'recibida' => 'Recibida', 'rechazada' => 'Rechazada', 'anulada' => 'Anulada'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $estadoT === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary cursor-pointer" aria-label="Aplicar filtros" title="Filtrar"><?= icon('filter', 'w-4 h-4') ?></button>
    </div>
    <span class="text-sm text-slate-400"><?= number_format($pg['total']) ?> transferencias</span>
  </form>

  <?php if (!$transferencias): ?>
    <?= empty_state('Sin transferencias', $q !== '' || $estadoT !== '' ? 'Ninguna transferencia coincide con los filtros.' : 'Crea una transferencia para mover stock entre sucursales.', 'transfer', $acciones) ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Número</th><th>Origen</th><th>Destino</th><th>Fecha</th><th>Estado</th><th class="text-right">Acciones</th></tr></thead>
        <tbody>
          <?php foreach ($transferencias as $t): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($t['numero']) ?></td>
              <td class="text-slate-600"><?= e($t['origen']) ?></td>
              <td class="text-slate-600"><?= e($t['destino']) ?></td>
              <td class="text-slate-500"><?= fechaCorta($t['fecha']) ?></td>
              <td><?= badgeFor($t['estado']) ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <a href="?ver=<?= (int) $t['id'] ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Ver"><?= icon('eye', 'w-4 h-4') ?></a>

                  <?php // ---- Borrador: editar, enviar, eliminar (solo el origen) ----
                  $esOrigen = can_access_sucursal($t['sucursal_origen_id']);
                  $esDestino = can_access_sucursal($t['sucursal_destino_id']);
                  if ($t['estado'] === 'borrador' && $esOrigen): ?>
                    <?php if (can('transferencias.crear')): ?>
                      <button type="button" onclick="<?= jsEvent('trf:edit', ['id' => (int) $t['id'], 'origen' => (int) $t['sucursal_origen_id'], 'destino' => (int) $t['sucursal_destino_id'], 'fecha' => $t['fecha'], 'lineas' => $lineasPorTrf[$t['id']] ?? []]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar borrador"><?= icon('edit', 'w-4 h-4') ?></button>
                    <?php endif; ?>
                    <?php if (can('transferencias.enviar')): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Enviar la transferencia? Se descontará el stock del origen.')"><?= csrf_field() ?><input type="hidden" name="accion" value="enviar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-blue-500 hover:text-blue-600 hover:bg-blue-50" title="Enviar"><?= icon('transfer', 'w-4 h-4') ?></button></form>
                    <?php endif; ?>
                    <?php if (can('transferencias.crear')): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Eliminar este borrador?')"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar borrador"><?= icon('trash', 'w-4 h-4') ?></button></form>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php // ---- Enviada: recibir/rechazar (destino), anular (origen) ----
                  if ($t['estado'] === 'enviada'): ?>
                    <?php if (can('transferencias.recibir') && $esDestino): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Confirmar recepción? Se agregará el stock al destino.')"><?= csrf_field() ?><input type="hidden" name="accion" value="recibir"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-emerald-500 hover:text-emerald-600 hover:bg-emerald-50" title="Recibir"><?= icon('check', 'w-4 h-4') ?></button></form>
                    <?php endif; ?>
                    <?php if (can('transferencias.rechazar') && $esDestino): ?>
                      <button type="button" onclick="<?= jsEvent('trf:rechazar', ['id' => (int) $t['id'], 'numero' => $t['numero']]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50" title="Rechazar"><?= icon('undo', 'w-4 h-4') ?></button>
                    <?php endif; ?>
                    <?php if (can('transferencias.anular') && $esOrigen): ?>
                      <form method="post" class="inline" onsubmit="return confirm('¿Anular la transferencia? El stock volverá al origen.')"><?= csrf_field() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Anular"><?= icon('x', 'w-4 h-4') ?></button></form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= paginacion($pg) ?>
  <?php endif; ?>
</div>

<!-- Modal crear / editar transferencia -->
<div x-data="trfForm()" @trf:new.window="reset(); open=true" @trf:edit.window="openEdit($event.detail)" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
    <form method="post" @submit="document.getElementById('trfLineas').value=JSON.stringify(lineas)">
      <?= csrf_field() ?><input type="hidden" name="accion" :value="id ? 'editar' : 'guardar'"><input type="hidden" name="id" :value="id"><input type="hidden" name="lineas" id="trfLineas">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800" x-text="id ? 'Editar borrador' : 'Nueva transferencia'"></h3><button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div><label class="label">Origen *</label><select name="sucursal_origen_id" x-model.number="origen" required class="select"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Destino *</label><select name="sucursal_destino_id" x-model.number="destino" required class="select"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Fecha</label><input type="date" name="fecha" x-model="fecha" class="input"></div>
        </div>
        <p x-show="origen===destino" class="text-sm text-rose-600">El origen y el destino deben ser distintos.</p>
        <div class="flex items-end gap-2">
          <div class="flex-1"><label class="label">Agregar producto</label><select x-model.number="nuevoProd" class="select"><option value="0">Selecciona...</option><?php foreach ($productosJs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
          <button type="button" @click="addLinea()" class="btn btn-soft"><?= icon('plus', 'w-4 h-4') ?> Agregar</button>
        </div>
        <div class="border border-slate-200 rounded-xl overflow-hidden">
          <table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="text-left px-3 py-2 text-xs font-semibold text-slate-400 uppercase">Producto</th><th class="px-2 py-2 text-xs font-semibold text-slate-400 uppercase w-28">Cantidad</th><th class="w-10"></th></tr></thead>
            <tbody>
              <template x-for="(l,i) in lineas" :key="i"><tr class="border-t border-slate-100"><td class="px-3 py-2 font-medium text-slate-700" x-text="l.nombre"></td><td class="px-2 py-2"><input type="number" step="0.001" min="0.001" x-model.number="l.cantidad" aria-label="Cantidad a transferir" class="input py-1.5 px-2 text-sm"></td><td class="px-2 py-2"><button type="button" @click="lineas.splice(i,1)" aria-label="Quitar producto" title="Quitar" class="text-rose-400 hover:text-rose-600 p-2"><?= icon('trash', 'w-4 h-4') ?></button></td></tr></template>
              <tr x-show="lineas.length===0"><td colspan="3" class="text-center text-slate-400 py-6 text-sm">Agrega productos a transferir.</td></tr>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-slate-500">El <strong>borrador</strong> no descuenta stock: puedes editarlo y enviarlo cuando esté listo.</p>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
        <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
        <!-- El modo viaja como name/value del botón submit (evita el desfase de :value en Alpine). -->
        <template x-if="id">
          <button type="submit" name="modo" value="borrador" :disabled="lineas.length===0 || origen===destino" class="btn btn-primary disabled:opacity-50"><?= icon('save', 'w-4 h-4') ?> Guardar cambios</button>
        </template>
        <template x-if="!id">
          <span class="flex gap-2">
            <button type="submit" name="modo" value="borrador" :disabled="lineas.length===0 || origen===destino" class="btn btn-ghost disabled:opacity-50"><?= icon('save', 'w-4 h-4') ?> Guardar borrador</button>
            <?php if (can('transferencias.enviar')): ?>
              <button type="submit" name="modo" value="enviar" :disabled="lineas.length===0 || origen===destino" class="btn btn-primary disabled:opacity-50"><?= icon('transfer', 'w-4 h-4') ?> Enviar ahora</button>
            <?php endif; ?>
          </span>
        </template>
      </div>
    </form>
  </div>
</div>

<!-- Modal rechazar -->
<div x-data="{ open:false, id:0, numero:'' }" @trf:rechazar.window="id=$event.detail.id; numero=$event.detail.numero; open=true" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="id" :value="id">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Rechazar transferencia <span x-text="numero"></span></h3><button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button></div>
      <div class="p-6 space-y-3">
        <p class="text-sm text-slate-600">El stock volverá al origen. Indica por qué rechazas la transferencia.</p>
        <div><label class="label">Motivo *</label><input type="text" name="motivo_rechazo" required maxlength="255" class="input" placeholder="Ej. Llegó incompleta / producto dañado" x-ref="motivo" x-effect="open && $nextTick(() => $refs.motivo.focus())"></div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" class="btn btn-danger"><?= icon('undo', 'w-4 h-4') ?> Rechazar</button></div>
    </form>
  </div>
</div>

<script>
function trfForm() {
  const DEF_ORIGEN = <?= (int) ($sucursales[0]['id'] ?? 0) ?>, DEF_DESTINO = <?= (int) ($sucursales[1]['id'] ?? 0) ?>;
  return {
    open: false, id: 0, nuevoProd: 0, lineas: [], origen: DEF_ORIGEN, destino: DEF_DESTINO, fecha: '<?= date('Y-m-d') ?>',
    productos: <?= json_encode($productosJs, JSON_UNESCAPED_UNICODE) ?>,
    reset() { this.id = 0; this.lineas = []; this.nuevoProd = 0; this.origen = DEF_ORIGEN; this.destino = DEF_DESTINO; this.fecha = '<?= date('Y-m-d') ?>'; },
    openEdit(d) {
      this.id = d.id; this.origen = d.origen; this.destino = d.destino; this.fecha = d.fecha;
      this.lineas = (d.lineas || []).map(l => ({ producto_id: l.producto_id, nombre: l.nombre, cantidad: l.cantidad }));
      this.nuevoProd = 0; this.open = true;
    },
    addLinea() { const p = this.productos.find(x => x.id === this.nuevoProd); if (!p || this.lineas.find(l => l.producto_id === p.id)) return; this.lineas.push({ producto_id: p.id, nombre: p.nombre, cantidad: 1 }); this.nuevoProd = 0; },
  };
}
</script>

<?php layout_end(); ?>
