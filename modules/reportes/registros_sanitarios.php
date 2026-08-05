<?php
/**
 * Registros sanitarios — el documento base de cualquier inspección.
 *
 * Es lo primero que pide un inspector de DIGEMAPS: la lista de productos
 * regulados con su número de registro y su vigencia. Este reporte la entrega
 * ordenada por urgencia (lo vencido arriba), no alfabéticamente: en una
 * inspección lo que importa es ver primero lo que está mal.
 *
 * No lleva filtro de periodo. Un registro sanitario está vigente HOY o no lo
 * está; preguntar «cuáles estaban vigentes en marzo» no le sirve a nadie.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.sanidad');

if (!san_disponible()) {
    layout_start('Registros sanitarios', 'Módulo no instalado');
    echo empty_state('Falta la migración', 'Aplica database/migracion_sanidad_p13.sql para activar el módulo de cumplimiento sanitario.', 'shield');
    layout_end();
    return;
}

$estado    = in_array(get('estado'), ['vencido', 'por_vencer', 'sin_registro', 'vigente'], true) ? get('estado') : '';
$categoria = array_key_exists((string) get('categoria'), san_categorias()) ? get('categoria') : '';
$entidad   = array_key_exists((string) get('entidad'), san_entidades()) ? get('entidad') : '';

$cond = ['p.regulado = 1', 'p.activo = 1'];
$par  = [];
if ($categoria) { $cond[] = 'p.registro_categoria = ?'; $par[] = $categoria; }
if ($entidad)   { $cond[] = 'p.registro_entidad = ?';   $par[] = $entidad; }
if ($estado === 'vencido')      $cond[] = 'p.registro_vencimiento IS NOT NULL AND p.registro_vencimiento < CURDATE()';
if ($estado === 'por_vencer')   $cond[] = 'p.registro_vencimiento IS NOT NULL AND p.registro_vencimiento >= CURDATE() AND p.registro_vencimiento <= DATE_ADD(CURDATE(), INTERVAL ' . SAN_DIAS_AVISO_REGISTRO . ' DAY)';
if ($estado === 'sin_registro') $cond[] = "(p.registro_sanitario IS NULL OR p.registro_sanitario = '')";
if ($estado === 'vigente')      $cond[] = "p.registro_sanitario <> '' AND (p.registro_vencimiento IS NULL OR p.registro_vencimiento > DATE_ADD(CURDATE(), INTERVAL " . SAN_DIAS_AVISO_REGISTRO . ' DAY))';
$where = implode(' AND ', $cond);

$filas = qAll(
    "SELECT p.*, c.nombre AS categoria_prod, m.nombre AS marca,
            (SELECT COALESCE(SUM(cantidad),0) FROM inventario_stock WHERE producto_id = p.id) AS stock
       FROM productos p
       LEFT JOIN categorias c ON c.id = p.categoria_id
       LEFT JOIN marcas m     ON m.id = p.marca_id
      WHERE $where
      ORDER BY (p.registro_sanitario IS NULL OR p.registro_sanitario = '') DESC,
               (p.registro_vencimiento IS NULL), p.registro_vencimiento ASC, p.nombre",
    $par
);

$res = san_resumen();

if (export_solicitado()) {
    $out = [];
    foreach ($filas as $p) {
        $st = san_estado_registro($p);
        $out[] = [
            $p['codigo'], $p['nombre'], $p['marca'],
            san_categorias()[$p['registro_categoria']] ?? '',
            $p['registro_sanitario'], $p['registro_entidad'], $p['registro_titular'],
            $p['registro_emision'] ? fechaCorta($p['registro_emision']) : '',
            $p['registro_vencimiento'] ? fechaCorta($p['registro_vencimiento']) : 'Sin fecha',
            $st['etiqueta'], $p['fabricante'], $p['pais_origen'],
            $p['controla_lote'] ? 'Sí' : 'No', qty($p['stock']),
        ];
    }
    export_tabla('registros_sanitarios',
        ['SKU', 'Producto', 'Marca', 'Categoría sanitaria', 'N.º registro', 'Entidad', 'Titular',
         'Emitido', 'Vence', 'Estado', 'Fabricante', 'Origen', 'Controla lote', 'Existencia'],
        $out, 'Registros sanitarios');
}

$acciones = rep_barra_titulo();
layout_start('Registros sanitarios', 'Vigencia de los registros ante Salud Pública · ' . fechaLarga(date('Y-m-d')), $acciones);
?>

<?= rep_abrir('Registros sanitarios de productos', ['label' => 'Al ' . fechaLarga(date('Y-m-d'))], []) ?>

<?= rep_kpis([
    ['label' => 'Productos regulados', 'valor' => number_format($res['regulados']), 'icono' => 'shield', 'color' => 'blue',
     'nota' => 'Sujetos a control sanitario'],
    ['label' => 'Sin registro', 'valor' => number_format($res['sin_registro']), 'icono' => 'alert',
     'color' => $res['sin_registro'] > 0 ? 'rose' : 'emerald',
     'nota' => $res['sin_registro'] > 0 ? 'No se pueden justificar ante una inspección' : 'Todos documentados'],
    ['label' => 'Registro vencido', 'valor' => number_format($res['registro_vencido']), 'icono' => 'x',
     'color' => $res['registro_vencido'] > 0 ? 'rose' : 'emerald',
     'nota' => $res['registro_vencido'] > 0 ? 'Renovar de inmediato' : 'Ninguno vencido'],
    ['label' => 'Por vencer', 'valor' => number_format($res['registro_por_vencer']), 'icono' => 'clock',
     'color' => $res['registro_por_vencer'] > 0 ? 'amber' : 'emerald',
     'nota' => 'En los próximos ' . SAN_DIAS_AVISO_REGISTRO . ' días'],
]) ?>

<!-- Filtros -->
<div class="card p-4 mb-5 no-print">
  <form method="get" class="flex flex-wrap items-end gap-3">
    <div>
      <label class="label">Estado</label>
      <select name="estado" class="select w-48">
        <option value="">Todos</option>
        <option value="sin_registro" <?= $estado === 'sin_registro' ? 'selected' : '' ?>>Sin registro</option>
        <option value="vencido"      <?= $estado === 'vencido' ? 'selected' : '' ?>>Vencidos</option>
        <option value="por_vencer"   <?= $estado === 'por_vencer' ? 'selected' : '' ?>>Por vencer</option>
        <option value="vigente"      <?= $estado === 'vigente' ? 'selected' : '' ?>>Vigentes</option>
      </select>
    </div>
    <div>
      <label class="label">Categoría sanitaria</label>
      <select name="categoria" class="select w-52">
        <option value="">Todas</option>
        <?php foreach (san_categorias() as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= $categoria === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">Entidad</label>
      <select name="entidad" class="select w-56">
        <option value="">Todas</option>
        <?php foreach (san_entidades() as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= $entidad === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-ghost"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
  </form>
</div>

<?php
$rows = [];
foreach ($filas as $p) {
    $st = san_estado_registro($p);
    $rows[] = [
        '<span class="font-semibold text-slate-700">' . e($p['nombre']) . '</span>'
            . '<span class="block text-[11.5px] text-slate-400">' . e($p['codigo'])
            . ($p['marca'] ? ' · ' . e($p['marca']) : '') . '</span>',
        e(san_categorias()[$p['registro_categoria']] ?? '—'),
        $p['registro_sanitario']
            ? '<span class="font-mono text-[12.5px]">' . e($p['registro_sanitario']) . '</span>'
              . ($p['registro_entidad'] ? '<span class="block text-[11px] text-slate-400">' . e($p['registro_entidad']) . '</span>' : '')
            : '<span class="badge badge-rose">Sin registro</span>',
        e($p['registro_titular'] ?: '—'),
        $p['registro_vencimiento'] ? fechaCorta($p['registro_vencimiento']) : '<span class="text-slate-300">Sin fecha</span>',
        '<span class="badge badge-' . $st['color'] . '">' . e($st['etiqueta']) . '</span>',
        e($p['fabricante'] ?: '—') . ($p['pais_origen'] ? '<span class="block text-[11px] text-slate-400">' . e($p['pais_origen']) . '</span>' : ''),
        '<span class="tabular-nums">' . qty($p['stock']) . '</span>'
            . ($p['controla_lote'] ? '<span class="block text-[10px] text-slate-400">por lote</span>' : ''),
    ];
}
echo rep_seccion('Detalle', count($filas) . ' producto(s)', 'shield', 'blue');
echo rep_tabla(
    ['Producto', 'Categoría sanitaria', 'N.º de registro', 'Titular', 'Vence', ['Estado', 'center'], 'Fabricante / origen', ['Existencia', 'right']],
    $rows,
    ['vacio_titulo' => 'Sin productos regulados',
     'vacio' => 'Marca los productos con control sanitario desde Inventario → Productos.',
     'vacio_icono' => 'shield']
);
echo rep_fin();
?>

<?php layout_end(); ?>
