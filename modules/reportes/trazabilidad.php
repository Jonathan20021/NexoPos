<?php
/**
 * Trazabilidad de lote (retiro del mercado / recall).
 *
 * Es el reporte que se usa el peor día: el fabricante avisa de un problema con
 * un lote y hay que responder, con nombres y facturas, a quién se le vendió.
 * Se busca por número de lote y se obtiene la cadena completa:
 *
 *    proveedor → compra → lote → facturas → clientes  +  lo que queda en almacén
 *
 * Los datos de contacto del cliente salen aquí a propósito: sin teléfono ni
 * correo, «saber a quién se le vendió» no sirve para avisarle.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.sanidad');

if (!san_disponible()) {
    layout_start('Trazabilidad de lote', 'Módulo no instalado');
    echo empty_state('Falta la migración', 'Aplica database/migracion_sanidad_p13.sql para activar el módulo de cumplimiento sanitario.', 'shield');
    layout_end();
    return;
}

$q      = trim((string) get('q'));
$loteId = (int) get('lote_id');

// Búsqueda por número de lote: puede haber el mismo lote en varias sucursales.
$coincidencias = [];
if ($q !== '' && !$loteId) {
    [$scope, $scopeP] = sucursalScope('l.sucursal_id');
    $coincidencias = qAll(
        "SELECT l.id, l.codigo, l.cantidad, l.fecha_vencimiento, l.bloqueado,
                p.nombre AS producto, p.codigo AS sku, s.nombre AS sucursal
           FROM lotes l
           JOIN productos p  ON p.id = l.producto_id
           JOIN sucursales s ON s.id = l.sucursal_id
          WHERE $scope AND (l.codigo LIKE ? OR p.nombre LIKE ? OR p.codigo LIKE ?)
          ORDER BY l.codigo, p.nombre LIMIT 100",
        array_merge($scopeP, ["%$q%", "%$q%", "%$q%"])
    );
    if (count($coincidencias) === 1) $loteId = (int) $coincidencias[0]['id'];
}

$tz = $loteId ? san_trazabilidad($loteId) : [];
if ($tz && !can_access_sucursal((int) $tz['lote']['sucursal_id'])) { $tz = []; $loteId = 0; }

if ($tz && export_solicitado()) {
    $out = [];
    foreach ($tz['ventas'] as $v) {
        $out[] = [$v['numero'], $v['ncf'], fechaHora($v['fecha']), $v['cliente'], $v['rnc_cedula'],
                  $v['telefono'], $v['email'], qty(abs((float) $v['cantidad'])), $v['sucursal']];
    }
    export_tabla('trazabilidad_lote_' . preg_replace('/[^A-Za-z0-9_-]/', '', $tz['lote']['codigo']),
        ['Factura', 'NCF', 'Fecha', 'Cliente', 'RNC/Cédula', 'Teléfono', 'Correo', 'Cantidad', 'Sucursal'],
        $out, 'Trazabilidad del lote ' . $tz['lote']['codigo']);
}

layout_start('Trazabilidad de lote', 'A quién se le vendió un lote · retiro del mercado', $tz ? rep_barra_titulo() : '');
?>

<!-- Buscador -->
<div class="card p-5 mb-5 no-print">
  <form method="get" class="flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[260px]">
      <label class="label">Número de lote, producto o SKU</label>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><?= icon('search', 'w-4 h-4') ?></span>
        <input type="search" name="q" value="<?= e($q) ?>" autofocus
               placeholder="Ej. L-2026-001" class="input pl-10">
      </div>
    </div>
    <button class="btn btn-primary"><?= icon('search', 'w-4 h-4') ?> Rastrear</button>
  </form>
  <p class="text-xs text-slate-400 mt-3">
    Devuelve de qué proveedor y compra entró el lote, a qué clientes salió con sus facturas y datos de contacto,
    y cuánto queda en almacén.
  </p>
</div>

<?php if ($coincidencias && !$loteId): ?>
  <div class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100"><h3 class="font-bold text-slate-800 text-sm"><?= count($coincidencias) ?> coincidencia(s)</h3></div>
    <table class="data-table">
      <thead><tr><th>Lote</th><th>Producto</th><th>Sucursal</th><th class="text-right">Existencia</th><th>Vence</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($coincidencias as $c): ?>
          <tr>
            <td class="font-mono text-[12.5px]"><?= e($c['codigo']) ?></td>
            <td><span class="font-semibold text-slate-700"><?= e($c['producto']) ?></span><span class="block text-[11.5px] text-slate-400"><?= e($c['sku']) ?></span></td>
            <td><?= e($c['sucursal']) ?></td>
            <td class="text-right tabular-nums"><?= qty($c['cantidad']) ?></td>
            <td><?= $c['fecha_vencimiento'] ? fechaCorta($c['fecha_vencimiento']) : '—' ?></td>
            <td class="text-right"><a href="?lote_id=<?= (int) $c['id'] ?>" class="btn btn-soft btn-sm">Rastrear</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php elseif ($q !== '' && !$loteId): ?>
  <?= empty_state('Sin coincidencias', 'Ningún lote, producto o SKU contiene «' . e($q) . '».', 'search') ?>
<?php endif; ?>

<?php if ($tz): $l = $tz['lote']; $st = san_estado_lote($l); ?>

  <?= rep_abrir('Trazabilidad del lote ' . $l['codigo'], ['label' => 'Al ' . fechaLarga(date('Y-m-d'))], []) ?>

  <?= rep_kpis([
      ['label' => 'Unidades vendidas', 'valor' => qty($tz['vendido']), 'icono' => 'cart', 'color' => 'blue',
       'nota' => count($tz['ventas']) . ' factura(s)'],
      ['label' => 'Clientes alcanzados', 'valor' => number_format($tz['clientes']), 'icono' => 'users',
       'color' => $tz['clientes'] > 0 ? 'amber' : 'slate', 'nota' => 'Hay que avisarles en un retiro'],
      ['label' => 'Queda en almacén', 'valor' => qty($tz['en_stock']), 'icono' => 'box',
       'color' => $tz['en_stock'] > 0 ? 'emerald' : 'slate', 'nota' => 'Se puede bloquear ahora mismo'],
      ['label' => 'Estado del lote', 'valor' => $st['etiqueta'], 'icono' => 'shield', 'color' => $st['color'],
       'nota' => $l['fecha_vencimiento'] ? 'Vence ' . fechaCorta($l['fecha_vencimiento']) : 'Sin fecha de vencimiento'],
  ]) ?>

  <!-- Origen -->
  <?= rep_seccion('De dónde vino', 'La mitad de la cadena que el inspector pregunta primero', 'truck', 'indigo') ?>
    <div class="px-5 pb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div><p class="text-xs text-slate-400">Producto</p><p class="font-semibold text-slate-700"><?= e($l['producto']) ?></p><p class="text-[11.5px] text-slate-400"><?= e($l['sku']) ?></p></div>
      <div><p class="text-xs text-slate-400">Registro sanitario</p><p class="font-semibold text-slate-700 font-mono text-sm"><?= e($l['registro_sanitario'] ?: '—') ?></p></div>
      <div><p class="text-xs text-slate-400">Proveedor</p><p class="font-semibold text-slate-700"><?= e($l['proveedor'] ?: '—') ?></p><p class="text-[11.5px] text-slate-400"><?= e($l['proveedor_rnc'] ?: '') ?></p></div>
      <div><p class="text-xs text-slate-400">Compra de entrada</p><p class="font-semibold text-slate-700"><?= e($l['compra_numero'] ?: '—') ?></p><p class="text-[11.5px] text-slate-400"><?= $l['compra_fecha'] ? fechaCorta($l['compra_fecha']) : '' ?></p></div>
    </div>
    <?php if (can('sanidad.bloquear') && !$l['bloqueado'] && (float) $l['cantidad'] > 0): ?>
      <div class="px-5 pb-5 no-print">
        <a href="<?= e(url('modules/inventario/lotes.php?q=' . urlencode($l['codigo']))) ?>" class="btn btn-danger btn-sm">
          <?= icon('lock', 'w-3.5 h-3.5') ?> Bloquear este lote para que no se venda
        </a>
      </div>
    <?php endif; ?>
  <?= rep_fin() ?>

  <!-- A quién salió -->
  <?php
  $rows = [];
  foreach ($tz['ventas'] as $v) {
      $contacto = [];
      if ($v['telefono']) $contacto[] = e($v['telefono']);
      if ($v['email'])    $contacto[] = e($v['email']);
      $rows[] = [
          '<a class="font-semibold text-blue-600 hover:underline" href="' . e(url('modules/pos/ticket.php?id=' . (int) $v['venta_id'])) . '">' . e($v['numero']) . '</a>'
            . ($v['ncf'] ? '<span class="block text-[11px] text-slate-400 font-mono">' . e($v['ncf']) . '</span>' : ''),
          fechaHora($v['fecha']),
          '<span class="font-semibold text-slate-700">' . e($v['cliente'] ?: 'Consumidor final') . '</span>'
            . ($v['rnc_cedula'] ? '<span class="block text-[11px] text-slate-400">' . e($v['rnc_cedula']) . '</span>' : ''),
          $contacto ? implode('<span class="text-slate-300"> · </span>', $contacto) : '<span class="text-slate-300">Sin contacto</span>',
          '<span class="tabular-nums font-semibold">' . qty(abs((float) $v['cantidad'])) . '</span>',
          e($v['sucursal']),
      ];
  }
  echo rep_seccion('A quién se le vendió', count($tz['ventas']) . ' factura(s) · ' . $tz['clientes'] . ' cliente(s)', 'users', 'rose');
  echo rep_tabla(
      ['Factura', 'Fecha', 'Cliente', 'Contacto', ['Cantidad', 'right'], 'Sucursal'],
      $rows,
      ['vacio_titulo' => 'Este lote no se ha vendido',
       'vacio' => 'Toda su existencia sigue en el almacén: un retiro no afectaría a ningún cliente.',
       'vacio_icono' => 'check']
  );
  echo rep_fin();
  ?>

  <!-- Historial completo -->
  <?php
  $mrows = [];
  foreach ($tz['movimientos'] as $m) {
      $signo = (float) $m['cantidad'] >= 0 ? '+' : '';
      $mrows[] = [
          fechaHora($m['created_at']),
          '<span class="badge badge-slate">' . e($m['tipo']) . '</span>',
          '<span class="tabular-nums font-semibold ' . ((float) $m['cantidad'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . '">' . $signo . qty($m['cantidad']) . '</span>',
          '<span class="tabular-nums text-slate-500">' . qty($m['saldo_nuevo']) . '</span>',
          e($m['motivo'] ?: '—'),
          e($m['usuario'] ?: '—'),
      ];
  }
  echo rep_seccion('Historial del lote', 'Cada entrada y salida, con quién la hizo', 'history', 'slate');
  echo rep_tabla(
      ['Fecha', 'Tipo', ['Movimiento', 'right'], ['Saldo', 'right'], 'Motivo', 'Usuario'],
      $mrows, ['vacio' => 'Sin movimientos.']
  );
  echo rep_fin();
  ?>

<?php endif; ?>

<?php layout_end(); ?>
