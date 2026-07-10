<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('finanzas.ver');

$tiposCuenta = ['efectivo' => 'Efectivo', 'banco' => 'Banco', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'otro' => 'Otro'];

/* ============================================================
 *  Acciones (POST · patrón PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    /* ---------- Guardar (crear / editar) ---------- */
    if ($accion === 'guardar') {
        $id     = postInt('id');
        $nombre = trim(post('nombre'));
        $tipo   = array_key_exists(post('tipo'), $tiposCuenta) ? post('tipo') : 'efectivo';
        $sucRaw = post('sucursal_id');
        $sucId  = ($sucRaw === '' || $sucRaw === null) ? null : (int) $sucRaw;
        $activo = postInt('activo', 1);

        // Validar que la sucursal exista (si se indicó una)
        $sucOk = $sucId === null || qVal("SELECT 1 FROM sucursales WHERE id = ?", [$sucId]);
        $orig = $id > 0 ? qOne("SELECT id, sucursal_id FROM cuentas_financieras WHERE id = ?", [$id]) : null;

        if ($id > 0 && (!$orig || !can_access_sucursal($orig['sucursal_id']))) {
            deny_access();
        }
        if (!can_access_sucursal($sucId)) {
            deny_access();
        }

        if ($nombre === '') {
            flash('error', 'El nombre de la cuenta es obligatorio.');
        } elseif (!$sucOk) {
            flash('error', 'La sucursal seleccionada no es válida.');
        } elseif (qVal("SELECT 1 FROM cuentas_financieras WHERE nombre = ? AND id <> ?", [$nombre, $id])) {
            flash('error', 'Ya existe una cuenta con ese nombre.');
        } else {
            if ($id > 0) {
                require_perm('finanzas.editar');
                // El balance NO se modifica directamente al editar (se gestiona por transacciones)
                dbUpdate('cuentas_financieras', [
                    'nombre'      => $nombre,
                    'tipo'        => $tipo,
                    'sucursal_id' => $sucId,
                    'activo'      => $activo,
                ], 'id = ?', [$id]);
                audit('finanzas', 'editar', "Cuenta financiera actualizada: $nombre", ['tabla' => 'cuentas_financieras', 'registro_id' => $id]);
                flash('success', 'Cuenta actualizada correctamente.');
            } else {
                require_perm('finanzas.crear');
                // El saldo inicial se fija al crear: se conserva aparte y también
                // arranca el balance vivo (que luego evoluciona por movimientos).
                $saldoInicial = round(postNum('balance'), 2);
                $nid = dbInsert('cuentas_financieras', [
                    'nombre'        => $nombre,
                    'tipo'          => $tipo,
                    'sucursal_id'   => $sucId,
                    'saldo_inicial' => $saldoInicial,
                    'balance'       => $saldoInicial,
                    'activo'        => $activo,
                ]);
                audit('finanzas', 'crear', "Cuenta financiera creada: $nombre (saldo inicial " . money($saldoInicial) . ")", ['tabla' => 'cuentas_financieras', 'registro_id' => $nid]);
                flash('success', 'Cuenta creada correctamente.');
            }
        }
        redirect('modules/finanzas/cuentas.php');
    }

    /* ---------- Eliminar (bloqueada si tiene transacciones) ---------- */
    if ($accion === 'eliminar') {
        require_perm('finanzas.eliminar');
        $id = postInt('id');
        $cuenta = qOne("SELECT nombre, sucursal_id FROM cuentas_financieras WHERE id = ?", [$id]);
        if (!$cuenta || !can_access_sucursal($cuenta['sucursal_id'])) {
            deny_access();
        }
        $enUso = (int) qVal("SELECT COUNT(*) FROM transacciones WHERE cuenta_id = ?", [$id]);
        if ($enUso > 0) {
            flash('error', "No se puede eliminar: la cuenta tiene $enUso transacción(es) asociada(s). Desactívala en su lugar.");
        } else {
            $nombre = $cuenta['nombre'];
            q("DELETE FROM cuentas_financieras WHERE id = ?", [$id]);
            audit('finanzas', 'eliminar', "Cuenta financiera eliminada: $nombre", ['tabla' => 'cuentas_financieras', 'registro_id' => $id]);
            flash('success', 'Cuenta eliminada.');
        }
        redirect('modules/finanzas/cuentas.php');
    }
}

/* ============================================================
 *  Listado
 * ============================================================ */
$q = trim(get('q'));
[$scopeCuenta, $scopeCuentaParams] = sucursalScope('cf.sucursal_id');
$params = $scopeCuentaParams;
$where = "WHERE $scopeCuenta";
if ($q !== '') { $where .= " AND cf.nombre LIKE ?"; $params[] = '%' . $q . '%'; }

$cuentas = qAll(
    "SELECT cf.*, su.nombre AS sucursal,
            (SELECT COUNT(*) FROM transacciones t WHERE t.cuenta_id = cf.id) AS movimientos
     FROM cuentas_financieras cf
     LEFT JOIN sucursales su ON su.id = cf.sucursal_id
     $where
     ORDER BY cf.nombre",
    $params
);

$balanceTotal = (float) qVal("SELECT COALESCE(SUM(cf.balance),0) FROM cuentas_financieras cf WHERE cf.activo = 1 AND $scopeCuenta", $scopeCuentaParams);
$sucursales = sucursales_visibles();

$acciones = can('finanzas.crear') ? btn_nuevo('cta:new', 'Nueva cuenta') : '';
layout_start('Cuentas Financieras', 'Efectivo, bancos y otras cuentas de tu negocio', $acciones);
?>

<!-- KPI: balance total -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5 sm:col-span-1">
    <div class="flex items-start justify-between">
      <div class="w-11 h-11 rounded-xl <?= $balanceTotal >= 0 ? 'bg-blue-50 text-blue-600' : 'bg-rose-50 text-rose-600' ?> flex items-center justify-center"><?= icon('wallet', 'w-5 h-5') ?></div>
    </div>
    <p class="text-sm text-slate-500 mt-4">Balance total (cuentas activas)</p>
    <p class="text-2xl font-extrabold mt-0.5 <?= $balanceTotal >= 0 ? 'text-slate-800' : 'text-rose-600' ?>"><?= money($balanceTotal) ?></p>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <?= search_box('Buscar cuenta...') ?>
    <span class="text-sm text-slate-400"><?= count($cuentas) ?> cuenta(s)</span>
  </div>

  <?php if (!$cuentas): ?>
    <?= empty_state('Sin cuentas', 'Crea tu primera cuenta financiera (caja, banco, etc.).', 'wallet',
        can('finanzas.crear') ? btn_nuevo('cta:new', 'Nueva cuenta') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr><th>Cuenta</th><th>Tipo</th><th>Sucursal</th><th class="text-right">Balance</th><th>Estado</th><th class="text-right">Acciones</th></tr>
        </thead>
        <tbody>
          <?php
          $tipoColor = ['efectivo' => 'emerald', 'banco' => 'sky', 'tarjeta' => 'violet', 'transferencia' => 'indigo', 'otro' => 'slate'];
          foreach ($cuentas as $c):
            $bal = (float) $c['balance'];
          ?>
            <tr>
              <td>
                <div class="flex items-center gap-3">
                  <span class="w-9 h-9 rounded-lg <?= 'badge-' . ($tipoColor[$c['tipo']] ?? 'slate') ?> flex items-center justify-center"><?= icon('wallet', 'w-4 h-4') ?></span>
                  <span class="font-semibold text-slate-700"><?= e($c['nombre']) ?></span>
                </div>
              </td>
              <td><?= badge($tiposCuenta[$c['tipo']] ?? ucfirst($c['tipo']), $tipoColor[$c['tipo']] ?? 'slate') ?></td>
              <td class="text-slate-500"><?= e($c['sucursal'] ?: 'Todas') ?></td>
              <td class="text-right whitespace-nowrap">
                <span class="font-bold <?= $bal >= 0 ? 'text-slate-800' : 'text-rose-600' ?>"><?= money($bal) ?></span>
                <span class="block text-[11px] text-slate-400">Inicial: <?= money((float) ($c['saldo_inicial'] ?? 0)) ?></span>
              </td>
              <td><?= $c['activo'] ? badge('Activa', 'emerald') : badge('Inactiva', 'slate') ?></td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if (can('finanzas.editar')): ?>
                    <button onclick="<?= jsEvent('cta:edit', [
                        'id'          => (int) $c['id'],
                        'nombre'      => $c['nombre'],
                        'tipo'        => $c['tipo'],
                        'sucursal_id' => $c['sucursal_id'] !== null ? (int) $c['sucursal_id'] : '',
                        'balance'     => (float) $c['balance'],
                        'activo'      => (int) $c['activo'],
                    ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if (can('finanzas.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la cuenta «<?= e($c['nombre']) ?>»?')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"<?= $c['movimientos'] > 0 ? ' disabled' : '' ?>><?= icon('trash', 'w-4 h-4') ?></button>
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
<div x-data="{open:false, isNew:true, form:{id:0,nombre:'',tipo:'efectivo',sucursal_id:'',balance:0,activo:1}}"
     @cta:new.window="form={id:0,nombre:'',tipo:'efectivo',sucursal_id:'',balance:0,activo:1}; isNew=true; open=true"
     @cta:edit.window="form=$event.detail; isNew=false; open=true"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar cuenta' : 'Nueva cuenta'"></h3>
          <button type="button" @click="open=false" aria-label="Cerrar modal" title="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <div>
            <label class="label">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required class="input" placeholder="Ej. Caja principal">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="label">Tipo *</label>
              <select name="tipo" x-model="form.tipo" class="select">
                <?php foreach ($tiposCuenta as $val => $lbl): ?>
                  <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label">Sucursal</label>
              <select name="sucursal_id" x-model="form.sucursal_id" class="select">
                <?php if (is_super() || current_user()['sucursal_id'] === null): ?><option value="">Todas</option><?php endif; ?>
                <?php foreach ($sucursales as $s): ?>
                  <option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div>
            <label class="label">
              <span x-text="isNew ? 'Saldo inicial (RD$)' : 'Balance actual (RD$)'"></span>
            </label>
            <input type="number" name="balance" x-model="form.balance" step="0.01" class="input"
                   :disabled="!isNew" :class="!isNew ? 'bg-slate-50 text-slate-400 cursor-not-allowed' : ''">
            <p class="text-xs text-slate-400 mt-1" x-show="!isNew">El balance solo cambia mediante movimientos de ingresos y gastos.</p>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" :checked="form.activo==1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"> Cuenta activa
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
