<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('conciliacion.ver');

$cuentas = conciliacionCuentas();

// Cuenta seleccionada (por defecto la primera conciliable).
$cuentaId = (int) get('cuenta_id') ?: (int) ($cuentas[0]['id'] ?? 0);
$cuenta = null;
foreach ($cuentas as $c) if ((int) $c['id'] === $cuentaId) $cuenta = $c;
// Solo se opera sobre cuentas conciliables y de una sucursal permitida.
if ($cuenta && $cuenta['sucursal_id']) require_sucursal_access((int) $cuenta['sucursal_id']);

$fechaCorte = trim((string) get('fecha_corte'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCorte)) $fechaCorte = date('Y-m-t'); // fin del mes actual
$saldoBanco = (float) str_replace(',', '', (string) get('saldo_banco', '0'));

$volver = 'modules/finanzas/conciliacion.php?cuenta_id=' . $cuentaId
        . '&fecha_corte=' . $fechaCorte . '&saldo_banco=' . $saldoBanco;

/* ============================================================
 *  Acciones (POST · patrón PRG)
 * ============================================================ */
if (isPost() && $cuenta) {
    verify_csrf();
    $accion = post('accion');
    try {
        /* ---------- Marcar / desmarcar un movimiento ---------- */
        if ($accion === 'marcar') {
            require_perm('conciliacion.conciliar');
            $id = postInt('transaccion_id');
            $t = qOne("SELECT id, cuenta_id, conciliacion_id FROM transacciones WHERE id = ?", [$id]);
            if (!$t) throw new RuntimeException('Movimiento no encontrado.');
            if ((int) $t['cuenta_id'] !== $cuentaId) throw new RuntimeException('El movimiento no pertenece a esta cuenta.');
            // Lo que ya quedó dentro de un corte cerrado no se toca.
            if ($t['conciliacion_id'] !== null) throw new RuntimeException('Ese movimiento ya pertenece a un corte cerrado.');
            q("UPDATE transacciones SET conciliada = IF(conciliada = 1, 0, 1) WHERE id = ?", [$id]);
        }

        /* ---------- Marcar todo lo pendiente hasta el corte ---------- */
        elseif ($accion === 'marcar_todo') {
            require_perm('conciliacion.conciliar');
            $n = q("UPDATE transacciones SET conciliada = 1
                     WHERE cuenta_id = ? AND fecha <= ? AND conciliada = 0 AND conciliacion_id IS NULL",
                   [$cuentaId, $fechaCorte])->rowCount();
            flash('success', "$n movimiento(s) marcados como conciliados.");
        }

        /* ---------- Cerrar el corte ---------- */
        elseif ($accion === 'cerrar') {
            require_perm('conciliacion.cerrar');
            $id = conciliacionCerrar($cuentaId, $fechaCorte, $saldoBanco, trim(post('notas')) ?: null);
            audit('conciliacion', 'crear', "Conciliación cerrada #$id · cuenta {$cuenta['nombre']} al $fechaCorte");
            flash('success', 'Conciliación cerrada. Los movimientos quedaron bloqueados.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect($volver);
}

if (!$cuentas) {
    layout_start('Conciliación bancaria', 'Cruce con el estado de cuenta del banco');
    echo empty_state(
        'No hay cuentas conciliables',
        'La conciliación aplica a cuentas de banco, tarjeta o transferencia. El efectivo se cuadra en el cierre de caja. Crea una cuenta en Finanzas → Cuentas.',
        'wallet',
        can('finanzas.crear') ? '<a href="' . url('modules/finanzas/cuentas.php') . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Ir a Cuentas</a>' : ''
    );
    layout_end();
    return;
}

$r      = conciliacionResumen($cuentaId, $fechaCorte, $saldoBanco);
$movs   = conciliacionMovimientos($cuentaId, $fechaCorte);
$hist   = conciliacionHistorial($cuentaId);

// El balance vivo debería ser el saldo en libros de hoy. Si no coincide, algo
// movió `balance` fuera de registrarTransaccion() y conviene avisarlo.
$librosHoy = conciliacionSaldoLibros($cuentaId, date('Y-m-d'));
$balanceDesfase = round((float) $cuenta['balance'] - $librosHoy, 2);

layout_start('Conciliación bancaria', 'Cruce con el estado de cuenta · ' . e($cuenta['nombre']));
?>

<!-- Filtros -->
<form method="get" class="card p-5 mb-5">
  <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
    <div>
      <label class="label" for="cuenta_id">Cuenta</label>
      <select id="cuenta_id" name="cuenta_id" class="select">
        <?php foreach ($cuentas as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $cuentaId ? 'selected' : '' ?>>
            <?= e($c['nombre']) ?> · <?= e(ucfirst($c['tipo'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="fecha_corte">Fecha de corte</label>
      <input type="date" id="fecha_corte" name="fecha_corte" value="<?= e($fechaCorte) ?>" required class="input">
    </div>
    <div>
      <label class="label" for="saldo_banco">Saldo según el banco</label>
      <input type="number" step="0.01" id="saldo_banco" name="saldo_banco" value="<?= e((string) $saldoBanco) ?>" class="input">
      <p class="mt-1 text-xs text-slate-500">El del estado de cuenta a esa fecha.</p>
    </div>
    <div><button class="btn btn-primary w-full cursor-pointer"><?= icon('filter', 'w-4 h-4') ?> Calcular</button></div>
  </div>
</form>

<!-- Cuadre -->
<div class="card p-6 mb-5 border-l-4 <?= $r['cuadra'] ? 'border-l-emerald-500' : 'border-l-amber-400' ?>">
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div>
      <h3 class="font-bold text-slate-800 mb-3">Cuadre al <?= fechaCorta($fechaCorte) ?></h3>
      <div class="divide-y divide-slate-100 text-sm">
        <div class="flex items-center justify-between py-2.5">
          <span class="text-slate-600">Saldo según el banco</span>
          <span class="font-semibold text-slate-800 tabular-nums"><?= money($r['saldo_banco']) ?></span>
        </div>
        <div class="flex items-center justify-between py-2.5">
          <span class="text-slate-600">(+) Depósitos en tránsito <span class="text-slate-400">(el banco aún no los acredita)</span></span>
          <span class="font-semibold text-emerald-600 tabular-nums"><?= money($r['transito_ingresos']) ?></span>
        </div>
        <div class="flex items-center justify-between py-2.5">
          <span class="text-slate-600">(−) Pagos en tránsito <span class="text-slate-400">(el banco aún no los debita)</span></span>
          <span class="font-semibold text-rose-600 tabular-nums"><?= money($r['transito_gastos']) ?></span>
        </div>
        <div class="flex items-center justify-between py-3 bg-slate-50/60 -mx-6 px-6">
          <span class="font-semibold text-slate-700">Saldo bancario ajustado</span>
          <span class="font-bold text-slate-800 tabular-nums"><?= money($r['saldo_ajustado']) ?></span>
        </div>
        <div class="flex items-center justify-between py-2.5">
          <span class="text-slate-600">Saldo según libros</span>
          <span class="font-semibold text-slate-800 tabular-nums"><?= money($r['saldo_libros']) ?></span>
        </div>
      </div>
    </div>

    <div class="flex flex-col justify-center">
      <div class="rounded-2xl <?= $r['cuadra'] ? 'bg-emerald-50' : 'bg-amber-50' ?> p-5 text-center">
        <p class="text-sm <?= $r['cuadra'] ? 'text-emerald-700' : 'text-amber-700' ?>">Diferencia</p>
        <p class="text-3xl font-extrabold <?= $r['cuadra'] ? 'text-emerald-600' : 'text-amber-600' ?> mt-1 tabular-nums"><?= money($r['diferencia']) ?></p>
        <p class="text-xs <?= $r['cuadra'] ? 'text-emerald-700' : 'text-amber-700' ?> mt-2">
          <?= $r['cuadra']
              ? 'Cuadra. Puedes cerrar el corte.'
              : 'No cuadra: falta registrar algo o hay un movimiento mal marcado.' ?>
        </p>
      </div>
      <div class="flex gap-3 mt-4 text-sm">
        <div class="flex-1 rounded-xl bg-slate-50 p-3 text-center">
          <p class="text-lg font-bold text-slate-800"><?= number_format($r['conciliadas']) ?></p>
          <p class="text-xs text-slate-500">conciliados</p>
        </div>
        <div class="flex-1 rounded-xl bg-slate-50 p-3 text-center">
          <p class="text-lg font-bold text-slate-800"><?= number_format($r['pendientes']) ?></p>
          <p class="text-xs text-slate-500">en tránsito</p>
        </div>
      </div>
    </div>
  </div>

  <?php if ($r['cuadra'] && can('conciliacion.cerrar')): ?>
    <form method="post" class="mt-5 pt-5 border-t border-slate-100 flex flex-col sm:flex-row gap-3 sm:items-end"
          onsubmit="return confirm('¿Cerrar la conciliación al <?= e($fechaCorte) ?>? Los movimientos marcados quedarán bloqueados.')">
      <?= csrf_field() ?><input type="hidden" name="accion" value="cerrar">
      <div class="flex-1">
        <label class="label" for="notas">Notas del corte (opcional)</label>
        <input type="text" id="notas" name="notas" class="input" placeholder="Ej. Estado de cuenta de julio recibido el 03/08">
      </div>
      <button class="btn btn-success shrink-0"><?= icon('lock', 'w-4 h-4') ?> Cerrar conciliación</button>
    </form>
  <?php endif; ?>
</div>

<?php if (abs($balanceDesfase) >= 0.01): ?>
  <div class="card p-5 mb-5 border-l-4 border-l-amber-400">
    <div class="flex items-start gap-3">
      <?= icon('alert', 'w-5 h-5 text-amber-600 shrink-0 mt-0.5') ?>
      <div class="text-sm text-slate-600">
        <h3 class="font-bold text-slate-800">El balance de la cuenta no coincide con sus movimientos</h3>
        <p class="mt-1">
          La cuenta marca <strong><?= money($cuenta['balance']) ?></strong>, pero
          saldo inicial + movimientos da <strong><?= money($librosHoy) ?></strong>
          (desfase de <?= money($balanceDesfase) ?>). Suele indicar un saldo tocado a mano.
          La conciliación usa los movimientos, que son la fuente de verdad.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Movimientos -->
<div class="card overflow-hidden">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4 border-b border-slate-100">
    <div>
      <h3 class="font-bold text-slate-800">Movimientos hasta el corte</h3>
      <p class="text-sm text-slate-500">Marca los que ya aparecen en el estado de cuenta.</p>
    </div>
    <?php if ($r['pendientes'] > 0 && can('conciliacion.conciliar')): ?>
      <form method="post" onsubmit="return confirm('¿Marcar como conciliados los <?= (int) $r['pendientes'] ?> movimientos en tránsito?')">
        <?= csrf_field() ?><input type="hidden" name="accion" value="marcar_todo">
        <button class="btn btn-ghost btn-sm"><?= icon('check', 'w-4 h-4') ?> Marcar todo lo pendiente</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$movs): ?>
    <?= empty_state('Sin movimientos', 'Esta cuenta no tiene movimientos hasta el ' . fechaCorta($fechaCorte) . '.', 'wallet') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th class="w-28">Estado</th><th>Fecha</th><th>Descripción</th><th>Categoría</th><th>Origen</th><th class="text-right">Monto</th></tr></thead>
        <tbody>
          <?php foreach ($movs as $m):
            $conciliada = (int) $m['conciliada'] === 1;
            $bloqueada  = $m['conciliacion_id'] !== null;
            $esIngreso  = $m['tipo'] === 'ingreso';
          ?>
            <tr class="<?= $conciliada ? 'bg-emerald-50/30' : '' ?>">
              <td>
                <?php if ($bloqueada): ?>
                  <span class="badge badge-slate" title="Pertenece a un corte cerrado"><?= icon('lock', 'w-3 h-3') ?> Cerrada</span>
                <?php elseif (can('conciliacion.conciliar')): ?>
                  <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="marcar">
                    <input type="hidden" name="transaccion_id" value="<?= (int) $m['id'] ?>">
                    <button class="badge <?= $conciliada ? 'badge-emerald' : 'badge-amber' ?> cursor-pointer hover:opacity-80 transition"
                            title="<?= $conciliada ? 'Quitar la marca' : 'Marcar como conciliada' ?>">
                      <?= icon($conciliada ? 'check' : 'clock', 'w-3 h-3') ?>
                      <?= $conciliada ? 'Conciliada' : 'En tránsito' ?>
                    </button>
                  </form>
                <?php else: ?>
                  <?= $conciliada ? badge('Conciliada', 'emerald') : badge('En tránsito', 'amber') ?>
                <?php endif; ?>
              </td>
              <td class="text-slate-500 tabular-nums whitespace-nowrap"><?= fechaCorta($m['fecha']) ?></td>
              <td class="text-slate-700"><?= e($m['descripcion'] ?: '—') ?></td>
              <td class="text-slate-500 text-xs"><?= e($m['categoria'] ?: '—') ?></td>
              <td class="text-xs"><?= badge(ucfirst((string) ($m['referencia_tipo'] ?: 'manual')), 'slate') ?></td>
              <td class="text-right font-bold tabular-nums <?= $esIngreso ? 'text-emerald-600' : 'text-rose-600' ?>">
                <?= ($esIngreso ? '+' : '−') . money($m['monto'], false) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Historial -->
<?php if ($hist): ?>
  <div class="card overflow-hidden mt-5">
    <div class="px-5 py-4 border-b border-slate-100"><h3 class="font-bold text-slate-800">Cortes cerrados</h3></div>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Corte</th><th class="text-right">Saldo banco</th><th class="text-right">Saldo libros</th><th class="text-right">Diferencia</th><th class="text-center">Movs.</th><th>Cerrada por</th><th>Notas</th></tr></thead>
        <tbody>
          <?php foreach ($hist as $h): ?>
            <tr>
              <td class="font-semibold text-slate-700 tabular-nums"><?= fechaCorta($h['fecha_corte']) ?></td>
              <td class="text-right tabular-nums"><?= money($h['saldo_banco']) ?></td>
              <td class="text-right tabular-nums"><?= money($h['saldo_libros']) ?></td>
              <td class="text-right tabular-nums font-semibold text-emerald-600"><?= money($h['diferencia']) ?></td>
              <td class="text-center"><span class="badge badge-slate"><?= (int) $h['movimientos'] ?></span></td>
              <td class="text-slate-500 text-xs"><?= e($h['usuario'] ?: '—') ?></td>
              <td class="text-slate-500 text-xs max-w-xs truncate"><?= e($h['notas'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Nota -->
<div class="card p-5 mt-5 border-l-4 border-l-blue-500">
  <div class="flex items-start gap-3">
    <?= icon('shield', 'w-5 h-5 text-blue-600 shrink-0 mt-0.5') ?>
    <div class="text-sm text-slate-600">
      <h3 class="font-bold text-slate-800">Cómo funciona</h3>
      <p class="mt-1">
        Al saldo del banco se le <strong>suma</strong> lo que el banco todavía no acreditó y se le
        <strong>resta</strong> lo que todavía no debitó. El resultado debe ser igual al saldo en libros.
        Si queda diferencia, falta registrar un movimiento o alguno está mal marcado.
      </p>
      <p class="mt-2 text-slate-500">
        El <strong>efectivo</strong> no se concilia aquí: su arqueo es el
        <a href="<?= e(url('modules/pos/caja.php')) ?>" class="text-blue-600 font-semibold hover:underline">cierre de caja</a>,
        que cuenta el dinero físico.
      </p>
    </div>
  </div>
</div>

<?php layout_end(); ?>
