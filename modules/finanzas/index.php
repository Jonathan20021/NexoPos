<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('finanzas.ver');

require_once dirname(__DIR__, 2) . '/includes/charts.php';

/* ============================================================
 *  Acciones (POST · patrón PRG)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    /* ---------- Guardar (crear / editar) movimiento manual ---------- */
    if ($accion === 'guardar') {
        $id        = postInt('id');
        $tipo      = post('tipo') === 'gasto' ? 'gasto' : 'ingreso';
        $catId     = postInt('categoria_id');
        $cuentaId  = postInt('cuenta_id');
        $monto     = round(postNum('monto'), 2);
        $desc      = trim(post('descripcion'));
        $fecha     = trim(post('fecha')) ?: date('Y-m-d');
        $sucId     = current_sucursal_id();

        // Normaliza la fecha a Y-m-d
        $ts = strtotime($fecha);
        $fecha = $ts ? date('Y-m-d', $ts) : date('Y-m-d');

        // Validaciones
        $catOk = $catId > 0 && qVal("SELECT 1 FROM categorias_financieras WHERE id = ? AND tipo = ?", [$catId, $tipo]);
        $cuentaOk = $cuentaId === 0 || qVal("SELECT 1 FROM cuentas_financieras WHERE id = ?", [$cuentaId]);

        if ($monto <= 0) {
            flash('error', 'El monto debe ser mayor que cero.');
        } elseif ($catId > 0 && !$catOk) {
            flash('error', 'La categoría seleccionada no corresponde al tipo de movimiento.');
        } elseif (!$cuentaOk) {
            flash('error', 'La cuenta seleccionada no es válida.');
        } else {
            $cuentaIdDb = $cuentaId > 0 ? $cuentaId : null;
            $catIdDb    = $catId > 0 ? $catId : null;

            if ($id > 0) {
                require_perm('finanzas.editar');
                // Solo editable si es manual
                $orig = qOne("SELECT * FROM transacciones WHERE id = ?", [$id]);
                if (!$orig || $orig['referencia_tipo'] !== 'manual') {
                    flash('error', 'Solo se pueden editar los movimientos manuales.');
                    redirect('modules/finanzas/index.php');
                }
                tx(function () use ($orig, $id, $tipo, $catIdDb, $cuentaIdDb, $monto, $desc, $fecha) {
                    // 1) Revertir el efecto del movimiento anterior en su cuenta (si tenía)
                    if (!empty($orig['cuenta_id'])) {
                        $signoRev = $orig['tipo'] === 'ingreso' ? '-' : '+';   // revertir => inverso
                        q("UPDATE cuentas_financieras SET balance = balance $signoRev ? WHERE id = ?", [$orig['monto'], $orig['cuenta_id']]);
                    }
                    // 2) Actualizar la transacción
                    dbUpdate('transacciones', [
                        'tipo'         => $tipo,
                        'categoria_id' => $catIdDb,
                        'cuenta_id'    => $cuentaIdDb,
                        'monto'        => $monto,
                        'descripcion'  => $desc,
                        'fecha'        => $fecha,
                    ], 'id = ?', [$id]);
                    // 3) Aplicar el efecto del nuevo movimiento en su cuenta (si tiene)
                    if ($cuentaIdDb) {
                        $signoNew = $tipo === 'ingreso' ? '+' : '-';
                        q("UPDATE cuentas_financieras SET balance = balance $signoNew ? WHERE id = ?", [$monto, $cuentaIdDb]);
                    }
                });
                audit('finanzas', 'editar', "Movimiento manual actualizado ($tipo): " . money($monto), ['tabla' => 'transacciones', 'registro_id' => $id]);
                flash('success', 'Movimiento actualizado correctamente.');
            } else {
                require_perm('finanzas.crear');
                $nid = tx(function () use ($tipo, $monto, $sucId, $cuentaIdDb, $catIdDb, $desc, $fecha) {
                    return registrarTransaccion($tipo, $monto, [
                        'sucursal_id'     => $sucId,
                        'cuenta_id'       => $cuentaIdDb,
                        'categoria_id'    => $catIdDb,
                        'descripcion'     => $desc,
                        'referencia_tipo' => 'manual',
                        'fecha'           => $fecha,
                    ]);
                });
                audit('finanzas', 'crear', "Movimiento manual registrado ($tipo): " . money($monto), ['tabla' => 'transacciones', 'registro_id' => $nid]);
                flash('success', 'Movimiento registrado correctamente.');
            }
        }
        redirect('modules/finanzas/index.php');
    }

    /* ---------- Eliminar movimiento manual (revierte balance) ---------- */
    if ($accion === 'eliminar') {
        require_perm('finanzas.eliminar');
        $id = postInt('id');
        $t  = qOne("SELECT * FROM transacciones WHERE id = ?", [$id]);
        if (!$t) {
            flash('error', 'El movimiento no existe.');
        } elseif ($t['referencia_tipo'] !== 'manual') {
            flash('error', 'Solo se pueden eliminar los movimientos manuales.');
        } else {
            tx(function () use ($t, $id) {
                // Revertir el balance de la cuenta: ingreso resta, gasto suma
                if (!empty($t['cuenta_id'])) {
                    $signo = $t['tipo'] === 'ingreso' ? '-' : '+';
                    q("UPDATE cuentas_financieras SET balance = balance $signo ? WHERE id = ?", [$t['monto'], $t['cuenta_id']]);
                }
                q("DELETE FROM transacciones WHERE id = ?", [$id]);
            });
            audit('finanzas', 'eliminar', "Movimiento manual eliminado ({$t['tipo']}): " . money($t['monto']), ['tabla' => 'transacciones', 'registro_id' => $id]);
            flash('success', 'Movimiento eliminado y balance revertido.');
        }
        redirect('modules/finanzas/index.php');
    }
}

/* ============================================================
 *  Filtro de periodo + scope
 * ============================================================ */
$desde = trim(get('desde'));
$hasta = trim(get('hasta'));
$desde = ($desde && strtotime($desde)) ? date('Y-m-d', strtotime($desde)) : date('Y-m-01');
$hasta = ($hasta && strtotime($hasta)) ? date('Y-m-d', strtotime($hasta)) : date('Y-m-t');
if ($desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }

[$scopeW, $scopeP] = sucursalScope('t.sucursal_id');

$periodoP = array_merge([$desde, $hasta], $scopeP);

/* ---------- KPIs del periodo ---------- */
$totalIngresos = (float) qVal(
    "SELECT COALESCE(SUM(t.monto),0) FROM transacciones t
     WHERE t.tipo = 'ingreso' AND t.fecha BETWEEN ? AND ? AND $scopeW",
    $periodoP
);
$totalGastos = (float) qVal(
    "SELECT COALESCE(SUM(t.monto),0) FROM transacciones t
     WHERE t.tipo = 'gasto' AND t.fecha BETWEEN ? AND ? AND $scopeW",
    $periodoP
);
$balance = $totalIngresos - $totalGastos;

/* ---------- Lista de transacciones del periodo ---------- */
$movs = qAll(
    "SELECT t.*, cf.nombre AS categoria, cu.nombre AS cuenta, su.nombre AS sucursal,
            CONCAT(u.nombre,' ',u.apellido) AS usuario
     FROM transacciones t
     LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
     LEFT JOIN cuentas_financieras cu ON cu.id = t.cuenta_id
     LEFT JOIN sucursales su ON su.id = t.sucursal_id
     LEFT JOIN usuarios u ON u.id = t.usuario_id
     WHERE t.fecha BETWEEN ? AND ? AND $scopeW
     ORDER BY t.fecha DESC, t.id DESC",
    $periodoP
);

if (export_solicitado()) {
    export_tabla('finanzas', ['Fecha', 'Tipo', 'Categoría', 'Cuenta', 'Sucursal', 'Descripción', 'Origen', 'Monto'],
        array_map(fn($t) => [$t['fecha'], $t['tipo'], $t['categoria'], $t['cuenta'], $t['sucursal'], $t['descripcion'], $t['referencia_tipo'], $t['monto']], $movs));
}

/* ---------- Catálogos para el modal ---------- */
$catsFin = qAll("SELECT id, tipo, nombre FROM categorias_financieras WHERE activo = 1 ORDER BY tipo, nombre");
$cuentas = qAll("SELECT id, nombre, tipo FROM cuentas_financieras WHERE activo = 1 ORDER BY nombre");

// JSON para el filtrado por tipo en el modal (Alpine)
$catsJson = array_map(fn($c) => ['id' => (int) $c['id'], 'tipo' => $c['tipo'], 'nombre' => $c['nombre']], $catsFin);

$origenColors = [
    'venta'   => 'emerald',
    'compra'  => 'amber',
    'nomina'  => 'indigo',
    'manual'  => 'slate',
];
$origenLabels = [
    'venta'   => 'Venta',
    'compra'  => 'Compra',
    'nomina'  => 'Nómina',
    'manual'  => 'Manual',
];

$acciones = export_buttons() . (can('finanzas.crear') ? btn_nuevo('mov:new', 'Nuevo movimiento') : '');
layout_start('Ingresos y Gastos', 'Movimientos financieros del ' . fechaCorta($desde) . ' al ' . fechaCorta($hasta), $acciones);
?>

<!-- Filtro de periodo -->
<form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
  <div>
    <label class="label">Desde</label>
    <input type="date" name="desde" value="<?= e($desde) ?>" class="input w-auto">
  </div>
  <div>
    <label class="label">Hasta</label>
    <input type="date" name="hasta" value="<?= e($hasta) ?>" class="input w-auto">
  </div>
  <button type="submit" class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
  <a href="<?= e(url('modules/finanzas/index.php')) ?>" class="btn btn-ghost">Mes actual</a>
</form>

<!-- KPIs -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5">
    <div class="flex items-start justify-between">
      <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><?= icon('arrow-down', 'w-5 h-5') ?></div>
    </div>
    <p class="text-sm text-slate-500 mt-4">Total Ingresos</p>
    <p class="text-2xl font-extrabold text-emerald-600 mt-0.5"><?= money($totalIngresos) ?></p>
  </div>
  <div class="card p-5">
    <div class="flex items-start justify-between">
      <div class="w-11 h-11 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center"><?= icon('arrow-up', 'w-5 h-5') ?></div>
    </div>
    <p class="text-sm text-slate-500 mt-4">Total Gastos</p>
    <p class="text-2xl font-extrabold text-rose-600 mt-0.5"><?= money($totalGastos) ?></p>
  </div>
  <div class="card p-5">
    <div class="flex items-start justify-between">
      <div class="w-11 h-11 rounded-xl <?= $balance >= 0 ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600' ?> flex items-center justify-center"><?= icon('wallet', 'w-5 h-5') ?></div>
    </div>
    <p class="text-sm text-slate-500 mt-4">Balance del periodo</p>
    <p class="text-2xl font-extrabold mt-0.5 <?= $balance >= 0 ? 'text-blue-600' : 'text-rose-600' ?>"><?= money($balance) ?></p>
  </div>
</div>

<!-- Lista de transacciones -->
<div class="card overflow-hidden">
  <div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
    <h3 class="font-bold text-slate-800">Movimientos del periodo</h3>
    <span class="text-sm text-slate-400"><?= count($movs) ?> movimiento(s)</span>
  </div>

  <?php if (!$movs): ?>
    <?= empty_state('Sin movimientos', 'No hay ingresos ni gastos registrados en este periodo.', 'dollar',
        can('finanzas.crear') ? btn_nuevo('mov:new', 'Nuevo movimiento') : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Fecha</th><th>Tipo</th><th>Categoría</th><th>Cuenta</th>
            <th>Descripción</th><th>Origen</th><th class="text-right">Monto</th><th class="text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movs as $m):
            $esIngreso = $m['tipo'] === 'ingreso';
            $origen = $m['referencia_tipo'] ?: 'manual';
            $esManual = $origen === 'manual';
          ?>
            <tr>
              <td class="text-slate-500 whitespace-nowrap"><?= fechaCorta($m['fecha']) ?></td>
              <td><?= $esIngreso ? badge('Ingreso', 'emerald') : badge('Gasto', 'rose') ?></td>
              <td class="text-slate-600"><?= e($m['categoria'] ?: '—') ?></td>
              <td class="text-slate-500"><?= e($m['cuenta'] ?: '—') ?></td>
              <td class="text-slate-500 max-w-xs truncate"><?= e($m['descripcion'] ?: '—') ?></td>
              <td><?= badge($origenLabels[$origen] ?? ucfirst($origen), $origenColors[$origen] ?? 'slate') ?></td>
              <td class="text-right font-bold whitespace-nowrap <?= $esIngreso ? 'text-emerald-600' : 'text-rose-600' ?>">
                <?= ($esIngreso ? '+' : '−') . ' ' . money($m['monto'], false) ?>
              </td>
              <td>
                <div class="flex items-center justify-end gap-1">
                  <?php if ($esManual && can('finanzas.editar')): ?>
                    <button onclick="<?= jsEvent('mov:edit', [
                        'id'           => (int) $m['id'],
                        'tipo'         => $m['tipo'],
                        'categoria_id' => (int) ($m['categoria_id'] ?? 0),
                        'cuenta_id'    => (int) ($m['cuenta_id'] ?? 0),
                        'monto'        => (float) $m['monto'],
                        'descripcion'  => $m['descripcion'] ?? '',
                        'fecha'        => $m['fecha'],
                    ]) ?>" class="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50" title="Editar"><?= icon('edit', 'w-4 h-4') ?></button>
                  <?php endif; ?>
                  <?php if ($esManual && can('finanzas.eliminar')): ?>
                    <form method="post" class="inline" onsubmit="return confirm('¿Eliminar este movimiento? El balance de la cuenta se revertirá.')">
                      <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                      <button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" title="Eliminar"><?= icon('trash', 'w-4 h-4') ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if (!$esManual): ?>
                    <span class="inline-flex items-center gap-1 text-xs text-slate-300" title="Movimiento automático de solo lectura"><?= icon('lock', 'w-4 h-4') ?></span>
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

<!-- Modal crear/editar movimiento manual -->
<div x-data="movModal()"
     @mov:new.window="openNew()"
     @mov:edit.window="openEdit($event.detail)"
     @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="fixed inset-0 bg-slate-900/40 z-50 flex items-center justify-center p-4" @click.self="open=false">
    <div x-show="open" x-transition class="bg-white rounded-2xl shadow-pop w-full max-w-lg" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800" x-text="form.id ? 'Editar movimiento' : 'Nuevo movimiento'"></h3>
          <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-700"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-4">
          <!-- Tipo -->
          <div>
            <label class="label">Tipo de movimiento *</label>
            <div class="grid grid-cols-2 gap-2">
              <label class="cursor-pointer">
                <input type="radio" name="tipo" value="ingreso" x-model="form.tipo" @change="form.categoria_id=0" class="sr-only peer">
                <span class="block text-center px-3 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 peer-checked:text-emerald-700 transition">Ingreso</span>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="tipo" value="gasto" x-model="form.tipo" @change="form.categoria_id=0" class="sr-only peer">
                <span class="block text-center px-3 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 peer-checked:border-rose-500 peer-checked:bg-rose-50 peer-checked:text-rose-700 transition">Gasto</span>
              </label>
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Categoría (filtrada por tipo en JS) -->
            <div>
              <label class="label">Categoría</label>
              <select name="categoria_id" x-model.number="form.categoria_id" class="select">
                <option value="0">— Sin categoría —</option>
                <template x-for="c in catsFiltradas()" :key="c.id">
                  <option :value="c.id" x-text="c.nombre"></option>
                </template>
              </select>
            </div>
            <!-- Cuenta -->
            <div>
              <label class="label">Cuenta</label>
              <select name="cuenta_id" x-model.number="form.cuenta_id" class="select">
                <option value="0">— Sin cuenta —</option>
                <?php foreach ($cuentas as $cu): ?>
                  <option value="<?= (int) $cu['id'] ?>"><?= e($cu['nombre']) ?> (<?= e(ucfirst($cu['tipo'])) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Monto -->
            <div>
              <label class="label">Monto (RD$) *</label>
              <input type="number" name="monto" x-model="form.monto" step="0.01" min="0.01" required class="input" placeholder="0.00">
            </div>
            <!-- Fecha -->
            <div>
              <label class="label">Fecha *</label>
              <input type="date" name="fecha" x-model="form.fecha" required class="input">
            </div>
          </div>
          <!-- Descripción -->
          <div>
            <label class="label">Descripción</label>
            <textarea name="descripcion" x-model="form.descripcion" rows="2" class="input" placeholder="Concepto del movimiento (opcional)"></textarea>
          </div>
          <p class="text-xs text-slate-400 flex items-start gap-1.5">
            <?= icon('alert', 'w-4 h-4 shrink-0 mt-px') ?>
            <span>Si eliges una cuenta, su balance se actualizará automáticamente según el tipo de movimiento.</span>
          </p>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function movModal() {
  return {
    open: false,
    cats: <?= json_encode($catsJson, JSON_UNESCAPED_UNICODE) ?>,
    form: { id: 0, tipo: 'ingreso', categoria_id: 0, cuenta_id: 0, monto: '', descripcion: '', fecha: '<?= date('Y-m-d') ?>' },
    catsFiltradas() { return this.cats.filter(c => c.tipo === this.form.tipo); },
    openNew() {
      this.form = { id: 0, tipo: 'ingreso', categoria_id: 0, cuenta_id: 0, monto: '', descripcion: '', fecha: '<?= date('Y-m-d') ?>' };
      this.open = true;
    },
    openEdit(d) {
      this.form = {
        id: d.id, tipo: d.tipo,
        categoria_id: d.categoria_id || 0,
        cuenta_id: d.cuenta_id || 0,
        monto: d.monto, descripcion: d.descripcion || '',
        fecha: (d.fecha || '').substring(0, 10)
      };
      this.open = true;
    }
  };
}
</script>

<?php layout_end(); ?>
