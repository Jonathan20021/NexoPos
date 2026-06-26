<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('caja.ver');

$sid = current_sucursal_id();
$uid = (int) current_user()['id'];

/** Calcula los totales de una sesión de caja en vivo. */
function totalesSesion(int $sesionId): array
{
    $row = qOne(
        "SELECT
            COALESCE(SUM(CASE WHEN v.estado='completada' THEN v.total ELSE 0 END),0) AS total_ventas,
            COUNT(CASE WHEN v.estado='completada' THEN 1 END) AS num_ventas
         FROM ventas v WHERE v.caja_sesion_id = ?", [$sesionId]
    );
    $efectivo = (float) qVal(
        "SELECT COALESCE(SUM(vp.monto),0) FROM venta_pagos vp
         JOIN ventas v ON v.id = vp.venta_id
         JOIN metodos_pago m ON m.id = vp.metodo_pago_id
         WHERE v.caja_sesion_id = ? AND v.estado='completada' AND m.afecta_caja = 1", [$sesionId]
    );
    $tarjeta = (float) qVal(
        "SELECT COALESCE(SUM(vp.monto),0) FROM venta_pagos vp
         JOIN ventas v ON v.id = vp.venta_id
         JOIN metodos_pago m ON m.id = vp.metodo_pago_id
         WHERE v.caja_sesion_id = ? AND v.estado='completada' AND m.afecta_caja = 0", [$sesionId]
    );
    $ingresos = (float) qVal("SELECT COALESCE(SUM(monto),0) FROM caja_movimientos WHERE caja_sesion_id = ? AND tipo='ingreso'", [$sesionId]);
    $egresos  = (float) qVal("SELECT COALESCE(SUM(monto),0) FROM caja_movimientos WHERE caja_sesion_id = ? AND tipo='egreso'", [$sesionId]);
    return [
        'total_ventas' => (float) $row['total_ventas'],
        'num_ventas'   => (int) $row['num_ventas'],
        'efectivo'     => $efectivo,
        'tarjeta'      => $tarjeta,
        'ingresos'     => $ingresos,
        'egresos'      => $egresos,
    ];
}

$sesion = ($sid !== null) ? cajaSesionAbierta($sid, $uid) : null;

// ---------- Acciones ----------
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    if ($accion === 'abrir') {
        require_perm('caja.abrir');
        if ($sid === null) { flash('error', 'Selecciona una sucursal específica para abrir caja.'); redirect('modules/pos/caja.php'); }
        if ($sesion) { flash('error', 'Ya tienes una caja abierta.'); redirect('modules/pos/caja.php'); }
        $cajaId = postInt('caja_id');
        $monto  = postNum('monto_apertura');
        $caja = qOne("SELECT * FROM cajas WHERE id = ? AND sucursal_id = ?", [$cajaId, $sid]);
        if (!$caja) { flash('error', 'Caja inválida.'); redirect('modules/pos/caja.php'); }
        $nid = dbInsert('caja_sesiones', [
            'caja_id' => $cajaId, 'sucursal_id' => $sid, 'usuario_id' => $uid,
            'monto_apertura' => $monto, 'estado' => 'abierta', 'abierta_at' => date('Y-m-d H:i:s'),
        ]);
        audit('caja', 'abrir', "Apertura de caja {$caja['nombre']} con " . money($monto), ['tabla' => 'caja_sesiones', 'registro_id' => $nid]);
        flash('success', 'Caja abierta. ¡Listo para vender!');
        redirect('modules/pos/caja.php');
    }

    if ($accion === 'movimiento') {
        require_perm('caja.movimiento');
        if (!$sesion) { flash('error', 'No hay caja abierta.'); redirect('modules/pos/caja.php'); }
        $tipo = post('tipo_mov') === 'egreso' ? 'egreso' : 'ingreso';
        $concepto = trim(post('concepto'));
        $monto = postNum('monto');
        if ($concepto === '' || $monto <= 0) { flash('error', 'Concepto y monto válidos son obligatorios.'); redirect('modules/pos/caja.php'); }
        dbInsert('caja_movimientos', ['caja_sesion_id' => (int) $sesion['id'], 'tipo' => $tipo, 'concepto' => $concepto, 'monto' => $monto, 'usuario_id' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
        audit('caja', 'movimiento', ucfirst($tipo) . " de caja: $concepto " . money($monto));
        flash('success', ucfirst($tipo) . ' registrado.');
        redirect('modules/pos/caja.php');
    }

    if ($accion === 'cerrar') {
        require_perm('caja.cerrar');
        if (!$sesion) { flash('error', 'No hay caja abierta.'); redirect('modules/pos/caja.php'); }
        $t = totalesSesion((int) $sesion['id']);
        $esperado = (float) $sesion['monto_apertura'] + $t['efectivo'] + $t['ingresos'] - $t['egresos'];
        $real = postNum('monto_cierre_real');
        $dif = round($real - $esperado, 2);
        dbUpdate('caja_sesiones', [
            'total_ventas' => $t['total_ventas'], 'total_efectivo' => $t['efectivo'],
            'total_tarjeta' => $t['tarjeta'], 'total_otros' => 0,
            'total_ingresos' => $t['ingresos'], 'total_egresos' => $t['egresos'],
            'efectivo_esperado' => $esperado, 'monto_cierre_real' => $real, 'diferencia' => $dif,
            'estado' => 'cerrada', 'cerrada_at' => date('Y-m-d H:i:s'), 'notas' => trim(post('notas')),
        ], 'id = ?', [(int) $sesion['id']]);
        audit('caja', 'cerrar', "Cierre de caja. Esperado " . money($esperado) . ", real " . money($real) . ", diferencia " . money($dif), ['tabla' => 'caja_sesiones', 'registro_id' => (int) $sesion['id']]);
        flash($dif == 0 ? 'success' : 'warning', 'Caja cerrada. Diferencia: ' . money($dif));
        redirect('modules/pos/caja.php?cierre=' . (int) $sesion['id']);
    }
}

layout_start('Caja', 'Apertura, movimientos y cierre de caja' . ($sid === null ? '' : ' · ' . e(current_user()['sucursal_nombre'] ?? '')));

if ($sid === null):
    echo empty_state('Selecciona una sucursal', 'La caja se opera por sucursal. Elige una sucursal en la barra superior para abrir o cerrar caja.', 'store');
    layout_end();
    return;
endif;

// Historial de sesiones cerradas
$historial = qAll("SELECT cs.*, c.nombre AS caja_nombre, u.nombre AS usuario FROM caja_sesiones cs JOIN cajas c ON c.id=cs.caja_id JOIN usuarios u ON u.id=cs.usuario_id WHERE cs.sucursal_id=? AND cs.estado='cerrada' ORDER BY cs.id DESC LIMIT 8", [$sid]);
?>

<?php if ($sesion):
    $t = totalesSesion((int) $sesion['id']);
    $esperado = (float) $sesion['monto_apertura'] + $t['efectivo'] + $t['ingresos'] - $t['egresos'];
    $movs = qAll("SELECT * FROM caja_movimientos WHERE caja_sesion_id=? ORDER BY id DESC", [(int) $sesion['id']]);
?>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <!-- Resumen de la sesión -->
    <div class="lg:col-span-2 space-y-5">
      <div class="card p-5">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-3">
            <span class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><?= icon('cash', 'w-5 h-5') ?></span>
            <div>
              <h3 class="font-bold text-slate-800"><?= e($sesion['caja_nombre']) ?> · <span class="text-emerald-600">Abierta</span></h3>
              <p class="text-sm text-slate-400">Desde <?= fechaHora($sesion['abierta_at']) ?></p>
            </div>
          </div>
          <?php if (can('pos.vender')): ?><a href="<?= e(url('modules/pos/index.php')) ?>" class="btn btn-primary"><?= icon('cart', 'w-4 h-4') ?> Ir a vender</a><?php endif; ?>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
          <?php
          $cards = [
            ['Monto apertura', money($sesion['monto_apertura']), 'wallet', 'text-slate-600'],
            ['Ventas (' . $t['num_ventas'] . ')', money($t['total_ventas']), 'receipt', 'text-blue-600'],
            ['Efectivo', money($t['efectivo']), 'cash', 'text-emerald-600'],
            ['Tarjeta/Otros', money($t['tarjeta']), 'wallet', 'text-indigo-600'],
            ['Ingresos extra', money($t['ingresos']), 'arrow-down', 'text-emerald-600'],
            ['Egresos', money($t['egresos']), 'arrow-up', 'text-rose-600'],
          ];
          foreach ($cards as $c): ?>
            <div class="rounded-xl bg-slate-50 p-3.5">
              <div class="flex items-center gap-1.5 text-slate-400 text-xs font-medium mb-1"><?= icon($c[2], 'w-3.5 h-3.5') ?> <?= e($c[0]) ?></div>
              <p class="text-lg font-extrabold <?= $c[3] ?>"><?= $c[1] ?></p>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-4 rounded-xl bg-blue-600 text-white p-4 flex items-center justify-between">
          <div><p class="text-blue-100 text-sm">Efectivo esperado en caja</p><p class="text-2xl font-extrabold"><?= money($esperado) ?></p></div>
          <?= icon('cash', 'w-10 h-10 text-blue-300') ?>
        </div>
      </div>

      <!-- Movimientos -->
      <div class="card overflow-hidden">
        <div class="p-5 pb-3 flex items-center justify-between">
          <h3 class="font-bold text-slate-800">Movimientos de efectivo</h3>
          <?php if (can('caja.movimiento')): ?><button onclick="<?= jsEvent('mov:new') ?>" class="btn btn-ghost btn-sm"><?= icon('plus', 'w-4 h-4') ?> Ingreso / Egreso</button><?php endif; ?>
        </div>
        <?php if (!$movs): ?>
          <p class="text-sm text-slate-400 px-5 pb-5">No hay movimientos de efectivo en esta sesión.</p>
        <?php else: ?>
          <table class="data-table">
            <thead><tr><th>Hora</th><th>Concepto</th><th>Tipo</th><th class="text-right">Monto</th></tr></thead>
            <tbody>
              <?php foreach ($movs as $m): ?>
                <tr>
                  <td class="text-slate-500"><?= date('h:i A', strtotime($m['created_at'])) ?></td>
                  <td class="font-medium text-slate-700"><?= e($m['concepto']) ?></td>
                  <td><?= $m['tipo'] === 'ingreso' ? badge('Ingreso', 'emerald') : badge('Egreso', 'rose') ?></td>
                  <td class="text-right font-bold <?= $m['tipo'] === 'ingreso' ? 'text-emerald-600' : 'text-rose-600' ?>"><?= ($m['tipo'] === 'ingreso' ? '+' : '−') . money($m['monto'], false) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Cierre -->
    <div class="card p-5 h-fit">
      <h3 class="font-bold text-slate-800 mb-1">Cerrar caja</h3>
      <p class="text-sm text-slate-400 mb-4">Cuenta el efectivo físico y regístralo para cuadrar.</p>
      <?php if (can('caja.cerrar')): ?>
      <form method="post" onsubmit="return confirm('¿Confirmar el cierre de caja? Esta acción no se puede deshacer.')">
        <?= csrf_field() ?><input type="hidden" name="accion" value="cerrar">
        <label class="label">Efectivo contado (real)</label>
        <div class="relative mb-3">
          <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold"><?= e(setting('moneda', 'RD$')) ?></span>
          <input type="number" step="0.01" name="monto_cierre_real" required class="input pl-12 text-lg font-bold" placeholder="0.00">
        </div>
        <label class="label">Notas (opcional)</label>
        <textarea name="notas" rows="2" class="input mb-3" placeholder="Observaciones del cierre"></textarea>
        <div class="rounded-xl bg-slate-50 p-3 text-sm space-y-1.5 mb-4">
          <div class="flex justify-between text-slate-500"><span>Apertura</span><span class="font-semibold text-slate-700"><?= money($sesion['monto_apertura']) ?></span></div>
          <div class="flex justify-between text-slate-500"><span>+ Ventas efectivo</span><span class="font-semibold text-emerald-600"><?= money($t['efectivo']) ?></span></div>
          <div class="flex justify-between text-slate-500"><span>+ Ingresos</span><span class="font-semibold text-emerald-600"><?= money($t['ingresos']) ?></span></div>
          <div class="flex justify-between text-slate-500"><span>− Egresos</span><span class="font-semibold text-rose-600"><?= money($t['egresos']) ?></span></div>
          <div class="flex justify-between pt-1.5 border-t border-slate-200"><span class="font-bold text-slate-700">Esperado</span><span class="font-extrabold text-blue-600"><?= money($esperado) ?></span></div>
        </div>
        <button class="btn btn-danger w-full"><?= icon('lock', 'w-4 h-4') ?> Cerrar caja</button>
      </form>
      <?php else: ?><p class="text-sm text-slate-400">No tienes permiso para cerrar caja.</p><?php endif; ?>
    </div>
  </div>

  <!-- Modal movimiento -->
  <div x-data="{open:false, tipo:'ingreso'}" @mov:new.window="open=true" @keydown.escape.window="open=false">
    <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
      <div class="bg-white rounded-2xl shadow-pop w-full max-w-sm" @click.stop>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="accion" value="movimiento">
          <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-bold text-slate-800">Movimiento de efectivo</h3>
            <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-2">
              <label class="cursor-pointer"><input type="radio" name="tipo_mov" value="ingreso" x-model="tipo" class="sr-only peer"><span class="block text-center py-2.5 rounded-xl border-2 border-slate-200 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 peer-checked:text-emerald-700 font-semibold text-sm transition">Ingreso</span></label>
              <label class="cursor-pointer"><input type="radio" name="tipo_mov" value="egreso" x-model="tipo" class="sr-only peer"><span class="block text-center py-2.5 rounded-xl border-2 border-slate-200 peer-checked:border-rose-500 peer-checked:bg-rose-50 peer-checked:text-rose-700 font-semibold text-sm transition">Egreso</span></label>
            </div>
            <div><label class="label">Concepto</label><input type="text" name="concepto" required class="input" placeholder="Ej. Pago a mensajero"></div>
            <div><label class="label">Monto</label><input type="number" step="0.01" name="monto" required class="input" placeholder="0.00"></div>
          </div>
          <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
            <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
            <button type="submit" class="btn btn-primary">Registrar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<?php else: // Sin caja abierta ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <div class="card p-6">
      <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mb-4"><?= icon('cash', 'w-6 h-6') ?></div>
      <h3 class="font-bold text-slate-800 text-lg">Abrir caja</h3>
      <p class="text-sm text-slate-400 mb-5">Registra el monto inicial en efectivo para comenzar a operar.</p>
      <?php if (can('caja.abrir')):
        $cajas = qAll("SELECT * FROM cajas WHERE sucursal_id=? AND activo=1 ORDER BY nombre", [$sid]); ?>
      <form method="post" class="space-y-4">
        <?= csrf_field() ?><input type="hidden" name="accion" value="abrir">
        <div>
          <label class="label">Caja</label>
          <select name="caja_id" required class="select">
            <?php foreach ($cajas as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Monto de apertura</label>
          <div class="relative">
            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold"><?= e(setting('moneda', 'RD$')) ?></span>
            <input type="number" step="0.01" name="monto_apertura" value="0.00" required class="input pl-12 text-lg font-bold">
          </div>
        </div>
        <button class="btn btn-primary w-full"><?= icon('check', 'w-4 h-4') ?> Abrir caja</button>
      </form>
      <?php else: ?><p class="text-sm text-slate-400">No tienes permiso para abrir caja.</p><?php endif; ?>
    </div>

    <div class="card p-6 bg-slate-50/50">
      <h3 class="font-bold text-slate-800 mb-1">¿Cómo funciona?</h3>
      <ul class="text-sm text-slate-500 space-y-2.5 mt-3">
        <li class="flex gap-2"><?= icon('check', 'w-4 h-4 text-emerald-500 mt-0.5 shrink-0') ?> Abres la caja con el efectivo inicial.</li>
        <li class="flex gap-2"><?= icon('check', 'w-4 h-4 text-emerald-500 mt-0.5 shrink-0') ?> Cada venta en el POS queda ligada a esta sesión.</li>
        <li class="flex gap-2"><?= icon('check', 'w-4 h-4 text-emerald-500 mt-0.5 shrink-0') ?> Registras ingresos/egresos de efectivo (fondo, pagos menores).</li>
        <li class="flex gap-2"><?= icon('check', 'w-4 h-4 text-emerald-500 mt-0.5 shrink-0') ?> Al cerrar, el sistema calcula el efectivo esperado y la diferencia.</li>
      </ul>
    </div>
  </div>
<?php endif; ?>

<!-- Historial de cierres -->
<div class="card overflow-hidden mt-5">
  <div class="p-5 pb-3"><h3 class="font-bold text-slate-800">Historial de cierres</h3></div>
  <?php if (!$historial): ?>
    <p class="text-sm text-slate-400 px-5 pb-5">Aún no hay cierres registrados.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Caja</th><th>Cajero</th><th>Apertura</th><th>Cierre</th><th class="text-right">Ventas</th><th class="text-right">Esperado</th><th class="text-right">Real</th><th class="text-right">Diferencia</th></tr></thead>
        <tbody>
          <?php foreach ($historial as $h): ?>
            <tr>
              <td class="font-semibold text-slate-700"><?= e($h['caja_nombre']) ?></td>
              <td class="text-slate-500"><?= e($h['usuario']) ?></td>
              <td class="text-slate-500"><?= fechaHora($h['abierta_at']) ?></td>
              <td class="text-slate-500"><?= fechaHora($h['cerrada_at']) ?></td>
              <td class="text-right font-semibold text-slate-700"><?= money($h['total_ventas']) ?></td>
              <td class="text-right text-slate-500"><?= money($h['efectivo_esperado']) ?></td>
              <td class="text-right text-slate-500"><?= money($h['monto_cierre_real']) ?></td>
              <td class="text-right font-bold <?= $h['diferencia'] == 0 ? 'text-emerald-600' : ($h['diferencia'] > 0 ? 'text-blue-600' : 'text-rose-600') ?>"><?= money($h['diferencia']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
