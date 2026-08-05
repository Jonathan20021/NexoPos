<?php
/**
 * Control de vencimientos — lo que revisa PROCONSUMIDOR.
 *
 * La pregunta de una inspección es directa: ¿hay mercancía vencida a la venta?
 * Este reporte la responde por lote y por sucursal, y además pone precio a la
 * respuesta: cuánto dinero hay parado en producto vencido o a punto de vencer.
 *
 * Se ordena por fecha de vencimiento ascendente, no por producto: lo que vence
 * antes es lo que hay que mover o retirar hoy.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.sanidad');

if (!san_disponible()) {
    layout_start('Control de vencimientos', 'Módulo no instalado');
    echo empty_state('Falta la migración', 'Aplica database/migracion_sanidad_p13.sql para activar el módulo de cumplimiento sanitario.', 'shield');
    layout_end();
    return;
}

$estado  = in_array(get('estado'), ['vencido', 'por_vencer', 'bloqueado', 'sin_fecha', 'sin_lote'], true) ? get('estado') : '';
$sucFil  = (int) get('sucursal_id');
$dias    = (int) (get('dias') ?: SAN_DIAS_AVISO_LOTE);
$dias    = max(1, min(730, $dias));

$cond = ['l.cantidad > 0'];
$par  = [];
[$scope, $scopeP] = sucursalScope('l.sucursal_id');
$cond[] = $scope;
$par = array_merge($par, $scopeP);
if ($sucFil > 0 && can_access_sucursal($sucFil)) { $cond[] = 'l.sucursal_id = ?'; $par[] = $sucFil; }
if ($estado === 'vencido')    $cond[] = 'l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento < CURDATE()';
if ($estado === 'por_vencer') { $cond[] = 'l.fecha_vencimiento IS NOT NULL AND l.fecha_vencimiento >= CURDATE() AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL ? DAY)'; $par[] = $dias; }
if ($estado === 'bloqueado')  $cond[] = 'l.bloqueado = 1';
if ($estado === 'sin_fecha')  $cond[] = 'l.fecha_vencimiento IS NULL';
if ($estado === 'sin_lote')   { $cond[] = 'l.codigo = ?'; $par[] = SAN_LOTE_SIN_IDENTIFICAR; }
$where = implode(' AND ', $cond);

$filas = qAll(
    "SELECT l.*, p.nombre AS producto, p.codigo AS sku, p.registro_sanitario, p.registro_categoria,
            s.nombre AS sucursal, pr.nombre AS proveedor, u.abreviatura AS unidad
       FROM lotes l
       JOIN productos p  ON p.id = l.producto_id
       JOIN sucursales s ON s.id = l.sucursal_id
       LEFT JOIN proveedores pr ON pr.id = l.proveedor_id
       LEFT JOIN unidades u     ON u.id = p.unidad_id
      WHERE $where
      ORDER BY (l.fecha_vencimiento IS NULL), l.fecha_vencimiento ASC, p.nombre
      LIMIT 1000",
    $par
);

// Totales por tramo, sobre TODO el alcance visible (no solo el filtro de pantalla).
$tot = qOne(
    "SELECT
       COALESCE(SUM(CASE WHEN l.fecha_vencimiento < CURDATE() THEN l.cantidad * l.costo_unitario END),0) AS v_vencido,
       COALESCE(SUM(CASE WHEN l.fecha_vencimiento < CURDATE() THEN 1 END),0) AS n_vencido,
       COALESCE(SUM(CASE WHEN l.fecha_vencimiento >= CURDATE() AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN l.cantidad * l.costo_unitario END),0) AS v_30,
       COALESCE(SUM(CASE WHEN l.fecha_vencimiento >= CURDATE() AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END),0) AS n_30,
       COALESCE(SUM(CASE WHEN l.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN l.cantidad * l.costo_unitario END),0) AS v_90,
       COALESCE(SUM(CASE WHEN l.fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 END),0) AS n_90,
       COALESCE(SUM(CASE WHEN l.bloqueado = 1 THEN l.cantidad * l.costo_unitario END),0) AS v_bloq,
       COALESCE(SUM(CASE WHEN l.bloqueado = 1 THEN 1 END),0) AS n_bloq
     FROM lotes l WHERE l.cantidad > 0 AND $scope", $scopeP
) ?: [];

if (export_solicitado()) {
    $out = [];
    foreach ($filas as $l) {
        $st = san_estado_lote($l);
        $out[] = [$l['sku'], $l['producto'], $l['codigo'], $l['sucursal'],
            $l['fecha_vencimiento'] ? fechaCorta($l['fecha_vencimiento']) : 'Sin fecha',
            $st['etiqueta'], qty($l['cantidad']), money($l['costo_unitario'], false),
            money((float) $l['cantidad'] * (float) $l['costo_unitario'], false),
            $l['proveedor'], $l['registro_sanitario'], $l['bloqueado'] ? 'Sí' : 'No', $l['motivo_bloqueo']];
    }
    export_tabla('control_vencimientos',
        ['SKU', 'Producto', 'Lote', 'Sucursal', 'Vence', 'Estado', 'Existencia', 'Costo unit.', 'Valor',
         'Proveedor', 'Registro sanitario', 'Bloqueado', 'Motivo'],
        $out, 'Control de vencimientos');
}

layout_start('Control de vencimientos', 'Mercancía vencida y próxima a vencer · ' . fechaLarga(date('Y-m-d')), rep_barra_titulo());
?>

<?= rep_abrir('Control de vencimientos por lote', ['label' => 'Al ' . fechaLarga(date('Y-m-d'))], ['sucursal' => true]) ?>

<?= rep_kpis([
    ['label' => 'Vencido en almacén', 'valor' => money($tot['v_vencido'] ?? 0), 'icono' => 'alert',
     'color' => ($tot['n_vencido'] ?? 0) > 0 ? 'rose' : 'emerald',
     'nota' => number_format($tot['n_vencido'] ?? 0) . ' lote(s) · no se puede vender'],
    ['label' => 'Vence en 30 días', 'valor' => money($tot['v_30'] ?? 0), 'icono' => 'clock',
     'color' => ($tot['n_30'] ?? 0) > 0 ? 'amber' : 'emerald',
     'nota' => number_format($tot['n_30'] ?? 0) . ' lote(s) · sácalo ya'],
    ['label' => 'Vence en 31-90 días', 'valor' => money($tot['v_90'] ?? 0), 'icono' => 'calendar', 'color' => 'blue',
     'nota' => number_format($tot['n_90'] ?? 0) . ' lote(s)'],
    ['label' => 'Bloqueado', 'valor' => money($tot['v_bloq'] ?? 0), 'icono' => 'lock',
     'color' => ($tot['n_bloq'] ?? 0) > 0 ? 'violet' : 'slate',
     'nota' => number_format($tot['n_bloq'] ?? 0) . ' lote(s) retirados de circulación'],
]) ?>

<div class="card p-4 mb-5 no-print">
  <form method="get" class="flex flex-wrap items-end gap-3">
    <div>
      <label class="label">Mostrar</label>
      <select name="estado" class="select w-52">
        <option value="">Todos los lotes con existencia</option>
        <option value="vencido"    <?= $estado === 'vencido' ? 'selected' : '' ?>>Solo vencidos</option>
        <option value="por_vencer" <?= $estado === 'por_vencer' ? 'selected' : '' ?>>Por vencer</option>
        <option value="bloqueado"  <?= $estado === 'bloqueado' ? 'selected' : '' ?>>Bloqueados</option>
        <option value="sin_fecha"  <?= $estado === 'sin_fecha' ? 'selected' : '' ?>>Sin fecha de vencimiento</option>
        <option value="sin_lote"   <?= $estado === 'sin_lote' ? 'selected' : '' ?>>Sin lote identificado</option>
      </select>
    </div>
    <div x-data>
      <label class="label">Ventana (días)</label>
      <input type="number" name="dias" value="<?= $dias ?>" min="1" max="730" class="input w-28">
    </div>
    <button class="btn btn-ghost"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
    <?php if (can('sanidad.lotes')): ?>
      <a href="<?= e(url('modules/inventario/lotes.php')) ?>" class="btn btn-soft">
        <?= icon('layers', 'w-4 h-4') ?> Gestionar lotes
      </a>
    <?php endif; ?>
  </form>
</div>

<?php
$rows = [];
foreach ($filas as $l) {
    $st = san_estado_lote($l);
    $valor = (float) $l['cantidad'] * (float) $l['costo_unitario'];
    $esSinLote = $l['codigo'] === SAN_LOTE_SIN_IDENTIFICAR;
    $rows[] = [
        '<span class="font-semibold text-slate-700">' . e($l['producto']) . '</span>'
            . '<span class="block text-[11.5px] text-slate-400">' . e($l['sku'])
            . ($l['registro_sanitario'] ? ' · RS ' . e($l['registro_sanitario']) : '') . '</span>',
        $esSinLote
            ? '<span class="badge badge-amber" title="Existencia anterior al control de lote">Sin identificar</span>'
            : '<span class="font-mono text-[12.5px]">' . e($l['codigo']) . '</span>',
        e($l['sucursal']),
        $l['fecha_vencimiento'] ? fechaCorta($l['fecha_vencimiento']) : '<span class="text-slate-300">—</span>',
        '<span class="badge badge-' . $st['color'] . '">' . e($st['etiqueta']) . '</span>'
            . ($l['bloqueado'] && $l['motivo_bloqueo'] ? '<span class="block text-[11px] text-violet-600 mt-0.5">' . e($l['motivo_bloqueo']) . '</span>' : ''),
        '<span class="tabular-nums">' . qty($l['cantidad']) . ' ' . e($l['unidad'] ?: 'u') . '</span>',
        '<span class="tabular-nums">' . money($valor) . '</span>',
        e($l['proveedor'] ?: '—'),
    ];
}
echo rep_seccion('Lotes con existencia', count($filas) . ' lote(s)', 'layers', 'amber');
echo rep_tabla(
    ['Producto', 'Lote', 'Sucursal', 'Vence', ['Estado', 'center'], ['Existencia', 'right'], ['Valor', 'right'], 'Proveedor'],
    $rows,
    ['vacio_titulo' => 'Sin lotes que mostrar',
     'vacio' => 'No hay mercancía con control de lote en este filtro.',
     'vacio_icono' => 'check']
);
echo rep_fin();
?>

<?php layout_end(); ?>
