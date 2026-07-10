<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('comisiones.ver');

$desde = trim(get('desde'));
$hasta = trim(get('hasta'));
$desde = ($desde && strtotime($desde)) ? date('Y-m-d', strtotime($desde)) : date('Y-m-01');
$hasta = ($hasta && strtotime($hasta)) ? date('Y-m-d', strtotime($hasta)) : date('Y-m-t');
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$ini = $desde . ' 00:00:00';
$fin = $hasta . ' 23:59:59';
[$scopeW, $scopeP] = sucursalScope('v.sucursal_id');

$volver = 'modules/finanzas/comisiones.php?desde=' . $desde . '&hasta=' . $hasta;

/* ============================================================
 *  Acciones (POST · patrón PRG). Flujo: pendiente → aprobada → pagada.
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');
    try {
        /* ---------- Generar/registrar (deja la comisión en 'pendiente') ---------- */
        if ($accion === 'generar') {
            require_perm('comisiones.generar');
            $vendId = postInt('vendedor_id');
            $pd = trim(post('desde')); $ph = trim(post('hasta'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ph)) {
                throw new RuntimeException('El periodo de comisión no es válido.');
            }
            if ($pd > $ph) [$pd, $ph] = [$ph, $pd];
            // Se recalcula en el servidor (no se confía en el navegador).
            $v = qOne(
                "SELECT u.id, CONCAT(u.nombre,' ',u.apellido) AS nombre, u.comision_pct,
                        COUNT(v.id) AS ventas, COALESCE(SUM(v.subtotal-v.descuento),0) AS base
                 FROM usuarios u
                 JOIN ventas v ON v.usuario_id=u.id AND v.estado='completada'
                    AND v.fecha BETWEEN ? AND ? AND $scopeW
                 WHERE u.id=? GROUP BY u.id",
                array_merge([$pd . ' 00:00:00', $ph . ' 23:59:59'], $scopeP, [$vendId])
            );
            $monto = $v ? round((float) $v['base'] * (float) $v['comision_pct'] / 100, 2) : 0.0;
            if (!$v || $monto <= 0) throw new RuntimeException('No hay comisión que registrar para ese vendedor y periodo.');

            $sid = current_sucursal_id();
            $uid = (int) current_user()['id'];
            // UPSERT: una sola comisión por vendedor+periodo. Si existe pero está
            // 'anulada', se reabre como 'pendiente'; si ya está viva, se avisa.
            $ya = qOne("SELECT id, estado FROM comisiones WHERE usuario_id=? AND periodo_desde=? AND periodo_hasta=?", [$vendId, $pd, $ph]);
            if ($ya && $ya['estado'] !== 'anulada') {
                throw new RuntimeException('Esa comisión ya está registrada (' . $ya['estado'] . ').');
            }
            $datos = [
                'usuario_id' => $vendId, 'sucursal_id' => $sid,
                'periodo_desde' => $pd, 'periodo_hasta' => $ph,
                'base' => (float) $v['base'], 'pct' => (float) $v['comision_pct'], 'monto' => $monto,
                'ventas_cant' => (int) $v['ventas'], 'estado' => 'pendiente',
                'transaccion_id' => null, 'aprobada_por' => null, 'aprobada_at' => null,
                'pagada_por' => null, 'pagada_at' => null, 'generada_por' => $uid,
            ];
            if ($ya) dbUpdate('comisiones', $datos, 'id = ?', [$ya['id']]);
            else     dbInsert('comisiones', $datos);
            audit('finanzas', 'crear', "Comisión registrada: {$v['nombre']} " . money($monto) . " [$pd:$ph]");
            flash('success', 'Comisión registrada como pendiente de aprobación.');
        }

        /* ---------- Aprobar (pendiente → aprobada) ---------- */
        elseif ($accion === 'aprobar') {
            require_perm('comisiones.aprobar');
            $id = postInt('id');
            $c = qOne("SELECT * FROM comisiones WHERE id=?", [$id]);
            if (!$c) throw new RuntimeException('Comisión no encontrada.');
            if (!can_access_sucursal($c['sucursal_id'])) deny_access();
            if ($c['estado'] !== 'pendiente') throw new RuntimeException('Solo se aprueban comisiones pendientes.');
            dbUpdate('comisiones', ['estado' => 'aprobada', 'aprobada_por' => (int) current_user()['id'], 'aprobada_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            audit('finanzas', 'editar', "Comisión aprobada #$id por " . money($c['monto']));
            flash('success', 'Comisión aprobada. Ya puede pagarse.');
        }

        /* ---------- Pagar (aprobada → pagada, registra el gasto) ---------- */
        elseif ($accion === 'pagar') {
            require_perm('comisiones.pagar');
            $id = postInt('id');
            tx(function () use ($id) {
                $c = qOne("SELECT * FROM comisiones WHERE id=? FOR UPDATE", [$id]);
                if (!$c) throw new RuntimeException('Comisión no encontrada.');
                if (!can_access_sucursal($c['sucursal_id'])) deny_access();
                if ($c['estado'] !== 'aprobada') throw new RuntimeException('Solo se pagan comisiones aprobadas.');
                $monto = (float) $c['monto'];
                if ($monto <= 0) throw new RuntimeException('El monto de la comisión no es válido.');
                $nombre = qVal("SELECT CONCAT(nombre,' ',apellido) FROM usuarios WHERE id=?", [$c['usuario_id']]);
                $sidPago = $c['sucursal_id'] !== null ? (int) $c['sucursal_id'] : current_sucursal_id();
                $cuentaId = cuentaFinancieraIdPorTipo($sidPago === null ? 'banco' : 'efectivo', $sidPago);
                $trId = registrarTransaccion('gasto', $monto, [
                    'sucursal_id' => $sidPago, 'cuenta_id' => $cuentaId,
                    'categoria_id' => categoriaFinancieraId('gasto', 'Comisiones'),
                    'descripcion' => 'Comisión ' . $nombre . " [{$c['periodo_desde']}:{$c['periodo_hasta']}]",
                    'referencia_tipo' => 'comision', 'referencia_id' => (int) $c['usuario_id'],
                    'fecha' => $c['periodo_hasta'],
                ]);
                dbUpdate('comisiones', [
                    'estado' => 'pagada', 'transaccion_id' => $trId,
                    'pagada_por' => (int) current_user()['id'], 'pagada_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
                audit('finanzas', 'crear', "Comisión pagada #$id a $nombre por " . money($monto), ['tabla' => 'comisiones', 'registro_id' => $id]);
            });
            flash('success', 'Comisión pagada y registrada en finanzas.');
        }

        /* ---------- Anular (pendiente/aprobada → anulada) ---------- */
        elseif ($accion === 'anular') {
            require_perm('comisiones.anular');
            $id = postInt('id');
            $c = qOne("SELECT * FROM comisiones WHERE id=?", [$id]);
            if (!$c) throw new RuntimeException('Comisión no encontrada.');
            if (!can_access_sucursal($c['sucursal_id'])) deny_access();
            if ($c['estado'] === 'pagada') throw new RuntimeException('No se puede anular una comisión ya pagada.');
            if ($c['estado'] === 'anulada') throw new RuntimeException('La comisión ya está anulada.');
            dbUpdate('comisiones', ['estado' => 'anulada'], 'id = ?', [$id]);
            audit('finanzas', 'editar', "Comisión anulada #$id");
            flash('success', 'Comisión anulada.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect($volver);
}

/* ============================================================
 *  Cálculo del periodo + cruce con los registros de comisión
 * ============================================================ */
$params = array_merge([$ini, $fin], $scopeP);
$filas = qAll(
    "SELECT u.id, CONCAT(u.nombre,' ',u.apellido) AS vendedor, u.comision_pct,
            COUNT(v.id) AS ventas, COALESCE(SUM(v.total),0) AS facturado,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS base
     FROM usuarios u
     JOIN ventas v ON v.usuario_id = u.id AND v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeW
     GROUP BY u.id HAVING ventas > 0 ORDER BY base DESC",
    $params
);
foreach ($filas as &$f) $f['comision'] = round($f['base'] * $f['comision_pct'] / 100, 2);
unset($f);

// Registros de comisión ya existentes para este periodo (por vendedor).
$reg = [];
foreach (qAll("SELECT * FROM comisiones WHERE periodo_desde=? AND periodo_hasta=?", [$desde, $hasta]) as $r) {
    $reg[(int) $r['usuario_id']] = $r;
}

$totFact = array_sum(array_column($filas, 'facturado'));
$totBase = array_sum(array_column($filas, 'base'));
$totComision = array_sum(array_column($filas, 'comision'));

if (export_solicitado()) {
    export_tabla('comisiones',
        ['Vendedor', '% Comisión', 'Ventas', 'Facturado', 'Base (sin ITBIS)', 'Comisión', 'Estado'],
        array_map(function ($f) use ($reg) {
            $e = $reg[(int) $f['id']]['estado'] ?? 'sin registrar';
            return [$f['vendedor'], $f['comision_pct'], $f['ventas'], $f['facturado'], $f['base'], $f['comision'], $e];
        }, $filas),
        'Comisiones ' . fechaCorta($desde) . ' al ' . fechaCorta($hasta));
}

$estadoBadge = [
    'pendiente' => ['Pendiente', 'amber'],
    'aprobada'  => ['Aprobada', 'blue'],
    'pagada'    => ['Pagada', 'emerald'],
    'anulada'   => ['Anulada', 'slate'],
];

$acciones = export_buttons();
layout_start('Comisiones de Vendedores', 'Cálculo y pago de comisiones · ' . fechaCorta($desde) . ' al ' . fechaCorta($hasta), $acciones);
?>
<form method="get" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
  <div><label class="label">Desde</label><input type="date" name="desde" value="<?= e($desde) ?>" class="input w-auto"></div>
  <div><label class="label">Hasta</label><input type="date" name="hasta" value="<?= e($hasta) ?>" class="input w-auto"></div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Filtrar</button>
  <a href="<?= e(url('modules/finanzas/comisiones.php')) ?>" class="btn btn-ghost">Mes actual</a>
</form>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
  <div class="card p-5"><p class="text-sm text-slate-400">Total facturado</p><p class="text-2xl font-extrabold text-slate-800 mt-1"><?= money($totFact) ?></p></div>
  <div class="card p-5"><p class="text-sm text-slate-400">Base de comisión</p><p class="text-2xl font-extrabold text-slate-800 mt-1"><?= money($totBase) ?></p></div>
  <div class="card p-5"><p class="text-sm text-slate-400">Comisiones del periodo</p><p class="text-2xl font-extrabold text-emerald-600 mt-1"><?= money($totComision) ?></p></div>
</div>

<div class="card overflow-hidden">
  <?php if (!$filas): ?>
    <?= empty_state('Sin ventas en el periodo', 'No hay comisiones que calcular para estas fechas.', 'percent') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr><th>Vendedor</th><th class="text-center">% Com.</th><th class="text-center">Ventas</th><th class="text-right">Base</th><th class="text-right">Comisión</th><th class="text-center">Estado</th><th class="text-right">Acción</th></tr></thead>
        <tbody>
          <?php foreach ($filas as $f):
            $r = $reg[(int) $f['id']] ?? null;
            $estado = $r['estado'] ?? null;
            // El monto a mostrar: si hay registro, el monto congelado; si no, el calculado.
            $montoMostrar = $r ? (float) $r['monto'] : (float) $f['comision'];
          ?>
            <tr>
              <td><div class="flex items-center gap-2.5"><?= avatar($f['vendedor'], 'w-8 h-8') ?><span class="font-semibold text-slate-700"><?= e($f['vendedor']) ?></span></div></td>
              <td class="text-center"><span class="badge badge-blue"><?= number_format($f['comision_pct'], 2) ?>%</span></td>
              <td class="text-center text-slate-500"><?= (int) $f['ventas'] ?></td>
              <td class="text-right text-slate-500"><?= money($f['base']) ?></td>
              <td class="text-right font-bold text-emerald-600"><?= money($montoMostrar) ?></td>
              <td class="text-center">
                <?php if ($estado && isset($estadoBadge[$estado])): ?>
                  <?= badge($estadoBadge[$estado][0], $estadoBadge[$estado][1]) ?>
                <?php else: ?>
                  <span class="text-slate-300 text-sm">—</span>
                <?php endif; ?>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1.5">
                  <?php
                    // Botón reutilizable de acción por comisión.
                    $btn = function (string $accion, string $label, string $clase, string $icono, array $extra = [], string $confirm = '') use ($f, $r, $desde, $hasta) {
                        $hidden = '<input type="hidden" name="accion" value="' . e($accion) . '">';
                        foreach ($extra as $k => $v) $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
                        $onsub = $confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '';
                        return '<form method="post" class="inline"' . $onsub . '>' . csrf_field() . $hidden
                            . '<button class="' . $clase . '">' . icon($icono, 'w-3.5 h-3.5') . ' ' . e($label) . '</button></form>';
                    };
                  ?>
                  <?php if ($f['comision'] <= 0 && !$estado): ?>
                    <span class="text-slate-300 text-sm">—</span>
                  <?php elseif (!$estado || $estado === 'anulada'): ?>
                    <?php if (can('comisiones.generar')): ?>
                      <?= $btn('generar', $estado === 'anulada' ? 'Registrar de nuevo' : 'Registrar', 'btn btn-soft btn-sm', 'save',
                            ['vendedor_id' => (int) $f['id'], 'desde' => $desde, 'hasta' => $hasta]) ?>
                    <?php else: ?><span class="text-slate-300 text-sm">—</span><?php endif; ?>
                  <?php elseif ($estado === 'pendiente'): ?>
                    <?php if (can('comisiones.aprobar')) echo $btn('aprobar', 'Aprobar', 'btn btn-primary btn-sm', 'check', ['id' => (int) $r['id']], '¿Aprobar la comisión de ' . $f['vendedor'] . ' por ' . money($montoMostrar) . '?'); ?>
                    <?php if (can('comisiones.anular')) echo $btn('anular', 'Anular', 'btn btn-ghost btn-sm', 'x', ['id' => (int) $r['id']], '¿Anular esta comisión?'); ?>
                  <?php elseif ($estado === 'aprobada'): ?>
                    <?php if (can('comisiones.pagar')) echo $btn('pagar', 'Pagar', 'btn btn-success btn-sm', 'cash', ['id' => (int) $r['id']], '¿Registrar el pago de comisión de ' . $f['vendedor'] . ' por ' . money($montoMostrar) . '?'); ?>
                    <?php if (can('comisiones.anular')) echo $btn('anular', 'Anular', 'btn btn-ghost btn-sm', 'x', ['id' => (int) $r['id']], '¿Anular esta comisión aprobada?'); ?>
                  <?php elseif ($estado === 'pagada'): ?>
                    <span class="text-xs text-slate-400"><?= $r['pagada_at'] ? 'Pagada ' . fechaCorta($r['pagada_at']) : 'Pagada' ?></span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="bg-slate-50 font-bold"><td colspan="3" class="px-4 py-3 border-t border-slate-200 text-slate-700">Totales</td><td class="px-4 py-3 border-t border-slate-200 text-right"><?= money($totBase) ?></td><td class="px-4 py-3 border-t border-slate-200 text-right text-emerald-600"><?= money($totComision) ?></td><td colspan="2" class="border-t border-slate-200"></td></tr></tfoot>
      </table>
    </div>
    <p class="text-xs text-slate-400 p-4">Flujo: se <b>registra</b> la comisión (queda pendiente), un responsable la <b>aprueba</b> y luego se <b>paga</b> (se registra el gasto en finanzas). La base es el subtotal sin ITBIS menos descuentos; el % se configura por usuario en Administración → Usuarios. El monto se congela al registrar.</p>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
