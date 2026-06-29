<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('transferencias.ver');

if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'guardar') {
        require_perm('transferencias.crear');
        $origen = postInt('sucursal_origen_id');
        $destino = postInt('sucursal_destino_id');
        $fecha = post('fecha') ?: date('Y-m-d');
        $lineas = json_decode(post('lineas', '[]'), true);
        if ($origen <= 0 || $destino <= 0 || $origen === $destino || !is_array($lineas) || !$lineas) {
            flash('error', 'Selecciona origen y destino distintos y agrega productos.');
            redirect('modules/inventario/transferencias.php');
        }
        require_sucursal_access($origen);
        if (!qVal("SELECT 1 FROM sucursales WHERE id=? AND activo=1", [$destino])) {
            flash('error', 'La sucursal de destino no es válida.');
            redirect('modules/inventario/transferencias.php');
        }
        try {
            $tid = tx(function () use ($origen, $destino, $fecha, $lineas) {
                $det = [];
                foreach ($lineas as $l) {
                    $pid = (int) ($l['producto_id'] ?? 0); $cant = (float) ($l['cantidad'] ?? 0);
                    if ($pid <= 0 || $cant <= 0) continue;
                    if (stockActual($pid, $origen) < $cant) {
                        $nom = qVal("SELECT nombre FROM productos WHERE id=?", [$pid]);
                        throw new RuntimeException('Stock insuficiente en origen para «' . $nom . '».');
                    }
                    $det[] = ['pid' => $pid, 'cant' => $cant];
                }
                if (!$det) throw new RuntimeException('No hay líneas válidas.');
                $numero = nextNumero('transferencias', 'numero', 'TRF');
                $tid = dbInsert('transferencias', ['numero' => $numero, 'sucursal_origen_id' => $origen, 'sucursal_destino_id' => $destino, 'fecha' => $fecha, 'estado' => 'enviada', 'usuario_id' => current_user()['id']]);
                foreach ($det as $d) {
                    dbInsert('transferencia_detalles', ['transferencia_id' => $tid, 'producto_id' => $d['pid'], 'cantidad' => $d['cant']]);
                    ajustarStock($d['pid'], $origen, -$d['cant'], 'transferencia_salida', 'transferencia', $tid, 0, 'Transferencia ' . $numero . ' (salida)');
                }
                return $tid;
            });
            audit('transferencias', 'crear', 'Transferencia enviada', ['tabla' => 'transferencias', 'registro_id' => $tid]);
            flash('success', 'Transferencia enviada. Stock descontado del origen.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
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
                dbUpdate('transferencias', ['estado' => 'recibida'], 'id=?', [$id]);
            });
            audit('transferencias', 'recibir', "Transferencia recibida #$id", ['tabla' => 'transferencias', 'registro_id' => $id]);
            flash('success', 'Transferencia recibida. Stock agregado al destino.');
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
                foreach (qAll("SELECT * FROM transferencia_detalles WHERE transferencia_id=?", [$id]) as $d) {
                    ajustarStock((int) $d['producto_id'], (int) $t['sucursal_origen_id'], (float) $d['cantidad'], 'transferencia_entrada', 'transferencia_anulada', $id, 0, 'Anulación transferencia ' . $t['numero']);
                }
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
      </div>
    </div>
    <?php layout_end(); return;
}

// ----- Listado -----
$sid = current_sucursal_id();
$scope = $sid === null ? '1=1' : "(t.sucursal_origen_id = $sid OR t.sucursal_destino_id = $sid)";
$transferencias = qAll("SELECT t.*, so.nombre AS origen, sd.nombre AS destino FROM transferencias t JOIN sucursales so ON so.id=t.sucursal_origen_id JOIN sucursales sd ON sd.id=t.sucursal_destino_id WHERE $scope ORDER BY t.id DESC LIMIT 100");

$productosJs = array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre']], qAll("SELECT id, nombre FROM productos WHERE activo=1 AND tipo='producto' ORDER BY nombre"));
$sucursales = qAll("SELECT id, nombre FROM sucursales WHERE activo=1 ORDER BY nombre");

$acciones = (can('transferencias.crear') && count($sucursales) > 1) ? '<button onclick="' . jsEvent('trf:new') . '" class="btn btn-primary">' . icon('transfer', 'w-4 h-4') . ' Nueva transferencia</button>' : '';
layout_start('Transferencias', 'Movimiento de inventario entre sucursales', $acciones);

if (count($sucursales) < 2):
    echo empty_state('Se necesitan al menos 2 sucursales', 'Crea otra sucursal para transferir inventario entre ellas.', 'transfer');
    layout_end(); return;
endif;
?>

<div class="card overflow-hidden">
  <?php if (!$transferencias): ?>
    <?= empty_state('Sin transferencias', 'Crea una transferencia para mover stock entre sucursales.', 'transfer', $acciones) ?>
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
                  <?php if (can('transferencias.recibir') && can_access_sucursal($t['sucursal_destino_id']) && $t['estado'] === 'enviada'): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Confirmar recepción? Se agregará el stock al destino.')"><?= csrf_field() ?><input type="hidden" name="accion" value="recibir"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-emerald-500 hover:text-emerald-600 hover:bg-emerald-50" title="Recibir"><?= icon('check', 'w-4 h-4') ?></button></form>
                  <?php endif; ?>
                  <?php if (can('transferencias.anular') && can_access_sucursal($t['sucursal_origen_id']) && $t['estado'] === 'enviada'): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Anular la transferencia? El stock volverá al origen.')"><?= csrf_field() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Anular"><?= icon('x', 'w-4 h-4') ?></button></form>
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

<!-- Modal nueva transferencia -->
<div x-data="trfForm()" @trf:new.window="reset(); open=true" @keydown.escape.window="open=false" x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
  <div class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
    <form method="post" @submit="document.getElementById('trfLineas').value=JSON.stringify(lineas)">
      <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="lineas" id="trfLineas">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Nueva transferencia</h3><button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button></div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div><label class="label">Origen *</label><select name="sucursal_origen_id" x-model.number="origen" required class="select"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Destino *</label><select name="sucursal_destino_id" x-model.number="destino" required class="select"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" class="input"></div>
        </div>
        <p x-show="origen===destino" class="text-sm text-rose-600">El origen y el destino deben ser distintos.</p>
        <div class="flex items-end gap-2">
          <div class="flex-1"><label class="label">Agregar producto</label><select x-model.number="nuevoProd" class="select"><option value="0">Selecciona...</option><?php foreach ($productosJs as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
          <button type="button" @click="addLinea()" class="btn btn-soft"><?= icon('plus', 'w-4 h-4') ?> Agregar</button>
        </div>
        <div class="border border-slate-200 rounded-xl overflow-hidden">
          <table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="text-left px-3 py-2 text-xs font-semibold text-slate-400 uppercase">Producto</th><th class="px-2 py-2 text-xs font-semibold text-slate-400 uppercase w-28">Cantidad</th><th class="w-10"></th></tr></thead>
            <tbody>
              <template x-for="(l,i) in lineas" :key="i"><tr class="border-t border-slate-100"><td class="px-3 py-2 font-medium text-slate-700" x-text="l.nombre"></td><td class="px-2 py-2"><input type="number" step="0.001" min="0" x-model.number="l.cantidad" class="input py-1.5 px-2 text-sm"></td><td class="px-2 py-2"><button type="button" @click="lineas.splice(i,1)" class="text-rose-400 hover:text-rose-600"><?= icon('trash', 'w-4 h-4') ?></button></td></tr></template>
              <tr x-show="lineas.length===0"><td colspan="3" class="text-center text-slate-400 py-6 text-sm">Agrega productos a transferir.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button type="submit" :disabled="lineas.length===0 || origen===destino" class="btn btn-primary disabled:opacity-50"><?= icon('transfer', 'w-4 h-4') ?> Enviar transferencia</button></div>
    </form>
  </div>
</div>

<script>
function trfForm() {
  return {
    open: false, nuevoProd: 0, lineas: [], origen: <?= (int) ($sucursales[0]['id'] ?? 0) ?>, destino: <?= (int) ($sucursales[1]['id'] ?? 0) ?>,
    productos: <?= json_encode($productosJs, JSON_UNESCAPED_UNICODE) ?>,
    reset() { this.lineas = []; this.nuevoProd = 0; },
    addLinea() { const p = this.productos.find(x => x.id === this.nuevoProd); if (!p || this.lineas.find(l => l.producto_id === p.id)) return; this.lineas.push({ producto_id: p.id, nombre: p.nombre, cantidad: 1 }); this.nuevoProd = 0; },
  };
}
</script>

<?php layout_end(); ?>
