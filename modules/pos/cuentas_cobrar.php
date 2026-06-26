<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('clientes.ver');

if (isPost()) {
    verify_csrf();
    if (post('accion') === 'abono') {
        require_perm('clientes.editar');
        $clienteId = postInt('cliente_id');
        $monto = postNum('monto');
        $metodoId = postInt('metodo_pago_id') ?: null;
        $notas = trim(post('notas'));
        try {
            tx(function () use ($clienteId, $monto, $metodoId, $notas) {
                $cli = qOne("SELECT nombre, balance FROM clientes WHERE id = ? FOR UPDATE", [$clienteId]);
                if (!$cli) throw new RuntimeException('Cliente no válido.');
                if ($monto <= 0) throw new RuntimeException('El monto del abono debe ser mayor a cero.');
                if ($monto > (float) $cli['balance'] + 0.01) throw new RuntimeException('El abono no puede superar el balance pendiente (' . money($cli['balance']) . ').');
                $sid = current_sucursal_id();
                dbInsert('pagos_clientes', [
                    'cliente_id' => $clienteId, 'sucursal_id' => $sid, 'monto' => $monto,
                    'metodo_pago_id' => $metodoId, 'notas' => $notas ?: null,
                    'usuario_id' => current_user()['id'], 'fecha' => date('Y-m-d H:i:s'),
                ]);
                q("UPDATE clientes SET balance = balance - ? WHERE id = ?", [$monto, $clienteId]);
                $cuenta = qOne("SELECT id FROM cuentas_financieras WHERE " . ($sid ? 'sucursal_id=' . (int) $sid . ' AND ' : '') . "tipo='efectivo' AND activo=1 LIMIT 1");
                registrarTransaccion('ingreso', $monto, [
                    'sucursal_id' => $sid, 'cuenta_id' => $cuenta['id'] ?? null,
                    'categoria_id' => categoriaFinancieraId('ingreso', 'Cobros a clientes'),
                    'descripcion' => 'Abono de ' . $cli['nombre'], 'referencia_tipo' => 'abono', 'referencia_id' => $clienteId,
                ]);
            });
            audit('clientes', 'editar', "Abono registrado al cliente #$clienteId por " . money($monto));
            flash('success', 'Abono registrado correctamente.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/pos/cuentas_cobrar.php');
    }
}

$q = trim(get('q'));
$where = "c.balance > 0.01";
$params = [];
if ($q !== '') { $where .= " AND (c.nombre LIKE ? OR c.rnc_cedula LIKE ?)"; $params = ["%$q%", "%$q%"]; }
$deudores = qAll("SELECT c.* FROM clientes c WHERE $where ORDER BY c.balance DESC", $params);

$totalCobrar = (float) qVal("SELECT COALESCE(SUM(balance),0) FROM clientes WHERE balance > 0");
$nDeudores = (int) qVal("SELECT COUNT(*) FROM clientes WHERE balance > 0.01");
$nExcedidos = (int) qVal("SELECT COUNT(*) FROM clientes WHERE limite_credito > 0 AND balance > limite_credito");
$metodos = qAll("SELECT id, nombre FROM metodos_pago WHERE activo=1 AND es_credito=0 ORDER BY id");
$abonos = qAll("SELECT pc.*, c.nombre AS cliente, u.nombre AS usuario, m.nombre AS metodo FROM pagos_clientes pc JOIN clientes c ON c.id=pc.cliente_id LEFT JOIN usuarios u ON u.id=pc.usuario_id LEFT JOIN metodos_pago m ON m.id=pc.metodo_pago_id ORDER BY pc.id DESC LIMIT 10");

if (export_solicitado()) {
    export_tabla('cuentas_por_cobrar', ['Código', 'Cliente', 'RNC/Cédula', 'Teléfono', 'Límite', 'Balance'],
        array_map(fn($c) => [$c['codigo'], $c['nombre'], $c['rnc_cedula'], $c['telefono'], $c['limite_credito'], $c['balance']], $deudores), 'Cuentas por Cobrar');
}

layout_start('Cuentas por Cobrar', 'Clientes con crédito pendiente y registro de abonos', export_buttons());
?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5 flex items-center gap-4"><div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center"><?= icon('wallet', 'w-5 h-5') ?></div><div><p class="text-sm text-slate-500">Total por cobrar</p><p class="text-2xl font-extrabold text-slate-800"><?= money($totalCobrar) ?></p></div></div>
  <div class="card p-5 flex items-center gap-4"><div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><?= icon('users', 'w-5 h-5') ?></div><div><p class="text-sm text-slate-500">Clientes con deuda</p><p class="text-2xl font-extrabold text-slate-800"><?= number_format($nDeudores) ?></p></div></div>
  <div class="card p-5 flex items-center gap-4"><div class="w-11 h-11 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center"><?= icon('alert', 'w-5 h-5') ?></div><div><p class="text-sm text-slate-500">Exceden su límite</p><p class="text-2xl font-extrabold text-slate-800"><?= number_format($nExcedidos) ?></p></div></div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="card overflow-hidden lg:col-span-2">
    <div class="p-4 border-b border-slate-100"><?= search_box('Buscar cliente...') ?></div>
    <?php if (!$deudores): ?>
      <?= empty_state('Sin cuentas por cobrar', 'Ningún cliente tiene crédito pendiente.', 'check') ?>
    <?php else: ?>
      <div class="overflow-x-auto"><table class="data-table">
        <thead><tr><th>Cliente</th><th>Teléfono</th><th class="text-right">Límite</th><th class="text-right">Balance</th><th>Uso</th><th class="text-right">Acción</th></tr></thead>
        <tbody>
          <?php foreach ($deudores as $c):
            $pct = $c['limite_credito'] > 0 ? min(100, round($c['balance'] / $c['limite_credito'] * 100)) : 0;
            $exc = $c['limite_credito'] > 0 && $c['balance'] > $c['limite_credito'];
          ?>
            <tr>
              <td><div class="flex items-center gap-3"><?= avatar($c['nombre'], 'w-8 h-8') ?><div><p class="font-semibold text-slate-700"><?= e($c['nombre']) ?></p><p class="text-xs text-slate-400"><?= e($c['codigo']) ?></p></div></div></td>
              <td class="text-slate-500"><?= e($c['telefono'] ?: '—') ?></td>
              <td class="text-right text-slate-500"><?= $c['limite_credito'] > 0 ? money($c['limite_credito']) : '—' ?></td>
              <td class="text-right font-bold text-amber-600"><?= money($c['balance']) ?></td>
              <td><?php if ($c['limite_credito'] > 0): ?><div class="w-20"><div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-full rounded-full <?= $exc ? 'bg-rose-500' : 'bg-amber-500' ?>" style="width:<?= $pct ?>%"></div></div></div><?php else: ?><span class="text-slate-300">—</span><?php endif; ?></td>
              <td class="text-right">
                <?php if (can('clientes.editar')): ?><button onclick="<?= jsEvent('abono:new', ['cliente_id' => $c['id'], 'nombre' => $c['nombre'], 'balance' => $c['balance']]) ?>" class="btn btn-soft btn-sm"><?= icon('cash', 'w-3.5 h-3.5') ?> Abono</button><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="card overflow-hidden">
    <div class="p-5 pb-3"><h3 class="font-bold text-slate-800">Abonos recientes</h3></div>
    <div class="px-5 pb-5 space-y-3">
      <?php if (!$abonos): ?><p class="text-sm text-slate-400">Aún no hay abonos.</p><?php else: foreach ($abonos as $a): ?>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0"><?= icon('arrow-down', 'w-4 h-4') ?></div>
          <div class="min-w-0 flex-1"><p class="text-sm font-semibold text-slate-700 truncate"><?= e($a['cliente']) ?></p><p class="text-xs text-slate-400"><?= fechaHora($a['fecha']) ?> · <?= e($a['metodo'] ?: 'Efectivo') ?></p></div>
          <p class="text-sm font-bold text-emerald-600"><?= money($a['monto']) ?></p>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- Modal abono -->
<div x-data="{open:false, form:{}}" @abono:new.window="form=$event.detail; form.monto=$event.detail.balance; open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div class="bg-white rounded-2xl shadow-pop w-full max-w-sm" @click.stop>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="abono"><input type="hidden" name="cliente_id" :value="form.cliente_id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Registrar abono</h3><button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button></div>
        <div class="p-6 space-y-4">
          <div class="rounded-xl bg-slate-50 p-3"><p class="font-semibold text-slate-700" x-text="form.nombre"></p><p class="text-sm text-amber-600 font-semibold">Balance: <span x-text="'<?= e(setting('moneda', 'RD$')) ?> ' + Number(form.balance).toLocaleString('en-US',{minimumFractionDigits:2})"></span></p></div>
          <div><label class="label">Monto del abono *</label><input type="number" step="0.01" min="0.01" :max="form.balance" name="monto" x-model="form.monto" required class="input text-lg font-bold"></div>
          <div><label class="label">Método</label><select name="metodo_pago_id" class="select"><?php foreach ($metodos as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Notas</label><input name="notas" class="input" placeholder="Opcional"></div>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100"><button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button><button class="btn btn-success"><?= icon('check', 'w-4 h-4') ?> Registrar abono</button></div>
      </form>
    </div>
  </div>
</div>

<?php layout_end(); ?>
