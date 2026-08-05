<?php
/**
 * Expediente de auditoría — el documento que se entrega en una inspección.
 *
 * Reúne en UNA sola pieza imprimible lo que preguntan Salud Pública (MSP /
 * DIGEMAPS), PROCONSUMIDOR, el Ministerio de Agricultura e INDOCAL. La CEO lo
 * abre, pulsa imprimir y lo entrega; no tiene que reunir cinco reportes.
 *
 * IMPORTANTE Y HONESTO: ninguna de esas entidades publica un formato de archivo
 * oficial, como sí hace la DGII con los 606/607/608. Una inspección sanitaria es
 * DOCUMENTAL. Esto no es «el formulario oficial de Salud Pública» —no existe—,
 * es la evidencia de la empresa ordenada como la piden en la práctica.
 *
 * Arriba va un semáforo de cumplimiento. Si algo está en rojo, es mejor que lo
 * vea la CEO antes que el inspector: para eso se imprime desde aquí.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.sanidad');

if (!san_disponible()) {
    layout_start('Expediente de auditoría', 'Módulo no instalado');
    echo empty_state('Falta la migración', 'Aplica database/migracion_sanidad_p13.sql para activar el módulo de cumplimiento sanitario.', 'shield');
    layout_end();
    return;
}

$res = san_resumen();
$emp = $GLOBALS['empresa'] ?? [];
[$scope, $scopeP] = sucursalScope('l.sucursal_id');

/* --------- Los hallazgos que hay que resolver, en orden de gravedad --------- */
$hallazgos = [];
if ($res['sin_registro'] > 0)
    $hallazgos[] = ['rose', 'shield', $res['sin_registro'] . ' producto(s) regulados SIN registro sanitario',
        'No se pueden justificar ante una inspección. Cárgales el número de registro en Inventario → Productos.',
        url('modules/reportes/registros_sanitarios.php?estado=sin_registro')];
if ($res['registro_vencido'] > 0)
    $hallazgos[] = ['rose', 'x', $res['registro_vencido'] . ' registro(s) sanitarios VENCIDOS',
        'Comercializar con el registro vencido es una infracción. Inicia la renovación ante DIGEMAPS.',
        url('modules/reportes/registros_sanitarios.php?estado=vencido')];
if ($res['lotes_vencidos'] > 0)
    $hallazgos[] = ['rose', 'alert', $res['lotes_vencidos'] . ' lote(s) VENCIDOS con existencia (' . money($res['valor_vencido']) . ')',
        'El sistema ya impide venderlos, pero PROCONSUMIDOR sanciona tenerlos en el área de venta. Retíralos y da la baja.',
        url('modules/reportes/vencimientos.php?estado=vencido')];
if (($res['proveedor_licencia_vencida'] ?? 0) > 0)
    $hallazgos[] = ['amber', 'truck', $res['proveedor_licencia_vencida'] . ' proveedor(es) con licencia sanitaria vencida',
        'Pide la licencia renovada antes de la próxima compra.',
        url('modules/reportes/proveedores_sanitario.php')];
if ($res['registro_por_vencer'] > 0)
    $hallazgos[] = ['amber', 'clock', $res['registro_por_vencer'] . ' registro(s) por vencer en ' . SAN_DIAS_AVISO_REGISTRO . ' días',
        'Una renovación tarda meses: empieza el trámite ahora.',
        url('modules/reportes/registros_sanitarios.php?estado=por_vencer')];
if ($res['lotes_por_vencer'] > 0)
    $hallazgos[] = ['amber', 'calendar', $res['lotes_por_vencer'] . ' lote(s) vencen en ' . SAN_DIAS_AVISO_LOTE . ' días',
        'Muévelos con promoción o devuélvelos al proveedor mientras aún tienen vida útil.',
        url('modules/reportes/vencimientos.php?estado=por_vencer')];
if ($res['sin_identificar'] > 0)
    $hallazgos[] = ['amber', 'search', $res['sin_identificar'] . ' lote(s) sin identificar',
        'Existencia anterior al control de lote. Mientras no se identifique, esa mercancía no es trazable.',
        url('modules/reportes/vencimientos.php?estado=sin_lote')];
if ($res['lotes_bloqueados'] > 0)
    $hallazgos[] = ['violet', 'lock', $res['lotes_bloqueados'] . ' lote(s) bloqueados',
        'Retirados de circulación a propósito. Documenta el motivo y su destino final.',
        url('modules/reportes/vencimientos.php?estado=bloqueado')];

$descuadres = san_descuadres();
if ($descuadres)
    $hallazgos[] = ['rose', 'alert', count($descuadres) . ' descuadre(s) entre existencia y lotes',
        'La suma de los lotes no coincide con el inventario. Revísalo antes de presentar el expediente.',
        url('modules/admin/integridad.php')];

$conforme = count(array_filter($hallazgos, fn($h) => $h[0] === 'rose')) === 0;

/* --------- Datos del expediente --------- */
$porCategoria = qAll(
    "SELECT registro_categoria, COUNT(*) n,
            SUM(registro_sanitario IS NOT NULL AND registro_sanitario <> '') con_registro
       FROM productos WHERE regulado = 1 AND activo = 1
      GROUP BY registro_categoria ORDER BY n DESC"
);
$vencidos = qAll(
    "SELECT l.codigo, l.fecha_vencimiento, l.cantidad, l.costo_unitario,
            p.nombre AS producto, p.codigo AS sku, s.nombre AS sucursal
       FROM lotes l JOIN productos p ON p.id = l.producto_id JOIN sucursales s ON s.id = l.sucursal_id
      WHERE l.cantidad > 0 AND l.fecha_vencimiento < CURDATE() AND $scope
      ORDER BY l.fecha_vencimiento LIMIT 100", $scopeP
);
$proveedores = qAll(
    "SELECT nombre, rnc, licencia_sanitaria, licencia_vencimiento, pais_origen
       FROM proveedores WHERE activo = 1
        AND (licencia_sanitaria IS NOT NULL AND licencia_sanitaria <> '')
      ORDER BY (licencia_vencimiento IS NULL), licencia_vencimiento LIMIT 100"
);

layout_start('Expediente de auditoría', 'Documento consolidado para las entidades de control', rep_barra_titulo());
?>

<!-- ============ Portada ============ -->
<div class="card p-6 mb-5 print-break">
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div class="min-w-0">
      <p class="text-xs uppercase tracking-wide text-slate-400 font-bold">Expediente de cumplimiento sanitario</p>
      <h2 class="text-2xl font-extrabold text-slate-800 mt-1"><?= e($emp['nombre'] ?? APP_NAME) ?></h2>
      <p class="text-sm text-slate-500 mt-1">
        <?php if (!empty($emp['rnc'])): ?>RNC <?= e($emp['rnc']) ?> · <?php endif; ?>
        <?= e($emp['direccion'] ?? '') ?>
      </p>
      <p class="text-sm text-slate-500"><?= e($emp['telefono'] ?? '') ?><?= !empty($emp['email']) ? ' · ' . e($emp['email']) : '' ?></p>
    </div>
    <div class="text-right shrink-0">
      <p class="text-xs text-slate-400">Emitido</p>
      <p class="font-bold text-slate-700"><?= fechaLarga(date('Y-m-d')) ?></p>
      <p class="text-xs text-slate-400 mt-1">Por <?= e(trim((current_user()['nombre'] ?? '') . ' ' . (current_user()['apellido'] ?? ''))) ?></p>
    </div>
  </div>

  <div class="mt-5 rounded-xl p-4 flex items-start gap-3 <?= $conforme ? 'bg-emerald-50 border border-emerald-200' : 'bg-rose-50 border border-rose-200' ?>">
    <span class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 <?= $conforme ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600' ?>">
      <?= icon($conforme ? 'check' : 'alert', 'w-5 h-5') ?>
    </span>
    <div>
      <h3 class="font-bold text-slate-800"><?= $conforme ? 'Sin hallazgos críticos' : 'Hay hallazgos que resolver antes de una inspección' ?></h3>
      <p class="text-sm text-slate-600 mt-0.5">
        <?= $conforme
            ? 'Todos los productos regulados tienen registro vigente y no hay mercancía vencida en existencia.'
            : 'Los puntos en rojo del cuadro siguiente son los que una inspección señalaría como incumplimiento.' ?>
      </p>
    </div>
  </div>

  <p class="text-xs text-slate-400 mt-4 leading-relaxed">
    Este expediente reúne la evidencia que solicitan en la práctica el Ministerio de Salud Pública (DIGEMAPS),
    PROCONSUMIDOR, el Ministerio de Agricultura e INDOCAL. Se genera con los datos vivos del sistema en la fecha
    indicada. No sustituye a los documentos originales (resoluciones de registro, licencias y certificados),
    que deben conservarse en físico.
  </p>
</div>

<!-- ============ Semáforo ============ -->
<?= rep_seccion('Estado de cumplimiento', count($hallazgos) . ' punto(s) de atención', 'shield', $conforme ? 'emerald' : 'rose') ?>
  <div class="px-5 pb-5">
    <?php if (!$hallazgos): ?>
      <div class="flex items-center gap-3 text-emerald-700 bg-emerald-50 rounded-xl p-4">
        <?= icon('check', 'w-5 h-5') ?>
        <span class="text-sm font-semibold">Nada que reportar: registros vigentes, sin vencidos y sin descuadres.</span>
      </div>
    <?php else: ?>
      <ul class="space-y-2.5">
        <?php foreach ($hallazgos as [$color, $ic, $titulo, $accion, $enlace]): ?>
          <li class="flex items-start gap-3 rounded-xl border p-3.5
                     <?= ['rose'=>'border-rose-200 bg-rose-50/50','amber'=>'border-amber-200 bg-amber-50/50','violet'=>'border-violet-200 bg-violet-50/50'][$color] ?>">
            <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0
                         <?= ['rose'=>'bg-rose-100 text-rose-600','amber'=>'bg-amber-100 text-amber-600','violet'=>'bg-violet-100 text-violet-600'][$color] ?>">
              <?= icon($ic, 'w-4 h-4') ?>
            </span>
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-slate-800 text-sm"><?= e($titulo) ?></p>
              <p class="text-[13px] text-slate-600 mt-0.5"><?= e($accion) ?></p>
            </div>
            <a href="<?= e($enlace) ?>" class="btn btn-ghost btn-sm shrink-0 no-print">Ver</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?= rep_fin() ?>

<!-- ============ Resumen por categoría ============ -->
<?php
$rows = [];
foreach ($porCategoria as $c) {
    $sin = (int) $c['n'] - (int) $c['con_registro'];
    $rows[] = [
        e(san_categorias()[$c['registro_categoria']] ?? 'Sin clasificar'),
        '<span class="tabular-nums">' . number_format($c['n']) . '</span>',
        '<span class="tabular-nums text-emerald-600 font-semibold">' . number_format($c['con_registro']) . '</span>',
        $sin > 0 ? '<span class="tabular-nums text-rose-600 font-semibold">' . number_format($sin) . '</span>'
                 : '<span class="text-slate-300">0</span>',
    ];
}
echo rep_seccion('Productos regulados por categoría sanitaria', 'Cobertura documental del catálogo', 'layers', 'blue');
echo rep_tabla(['Categoría', ['Productos', 'right'], ['Con registro', 'right'], ['Sin registro', 'right']], $rows,
    ['vacio_titulo' => 'Ningún producto marcado como regulado',
     'vacio' => 'Marca los productos sujetos a control sanitario en Inventario → Productos.', 'vacio_icono' => 'shield']);
echo rep_fin();
?>

<!-- ============ Mercancía vencida ============ -->
<?php
$rows = [];
foreach ($vencidos as $v) {
    $rows[] = [
        '<span class="font-semibold text-slate-700">' . e($v['producto']) . '</span><span class="block text-[11.5px] text-slate-400">' . e($v['sku']) . '</span>',
        '<span class="font-mono text-[12.5px]">' . e($v['codigo']) . '</span>',
        e($v['sucursal']),
        '<span class="text-rose-600 font-semibold">' . fechaCorta($v['fecha_vencimiento']) . '</span>',
        '<span class="tabular-nums">' . qty($v['cantidad']) . '</span>',
        '<span class="tabular-nums">' . money((float) $v['cantidad'] * (float) $v['costo_unitario']) . '</span>',
    ];
}
echo rep_seccion('Mercancía vencida en existencia', $vencidos ? 'Retirar del área de venta y dar de baja' : 'Ninguna', 'alert', $vencidos ? 'rose' : 'emerald');
echo rep_tabla(['Producto', 'Lote', 'Sucursal', 'Venció', ['Existencia', 'right'], ['Valor', 'right']], $rows,
    ['vacio_titulo' => 'Sin mercancía vencida',
     'vacio' => 'No hay lotes vencidos con existencia. Es el punto que más pesa en una inspección de PROCONSUMIDOR.',
     'vacio_icono' => 'check']);
echo rep_fin();
?>

<!-- ============ Proveedores ============ -->
<?php
$rows = [];
foreach ($proveedores as $p) {
    $dias = $p['licencia_vencimiento'] ? san_dias_hasta($p['licencia_vencimiento']) : null;
    $badge = $dias === null ? '<span class="badge badge-slate">Sin fecha</span>'
        : ($dias < 0 ? '<span class="badge badge-rose">Vencida</span>'
        : ($dias <= 90 ? '<span class="badge badge-amber">Vence en ' . $dias . ' d.</span>'
        : '<span class="badge badge-emerald">Vigente</span>'));
    $rows[] = [
        '<span class="font-semibold text-slate-700">' . e($p['nombre']) . '</span>'
          . ($p['rnc'] ? '<span class="block text-[11.5px] text-slate-400">RNC ' . e($p['rnc']) . '</span>' : ''),
        '<span class="font-mono text-[12.5px]">' . e($p['licencia_sanitaria']) . '</span>',
        $p['licencia_vencimiento'] ? fechaCorta($p['licencia_vencimiento']) : '—',
        $badge,
        e($p['pais_origen'] ?: '—'),
    ];
}
echo rep_seccion('Proveedores con licencia sanitaria', count($proveedores) . ' proveedor(es)', 'truck', 'indigo');
echo rep_tabla(['Proveedor', 'Licencia', 'Vence', ['Estado', 'center'], 'Origen'], $rows,
    ['vacio_titulo' => 'Sin licencias registradas',
     'vacio' => 'Carga la licencia sanitaria de cada proveedor de mercancía regulada en Inventario → Proveedores.',
     'vacio_icono' => 'truck']);
echo rep_fin();
?>

<div class="card p-5 no-print">
  <h3 class="font-bold text-slate-800 text-sm mb-3">Reportes de respaldo</h3>
  <div class="flex flex-wrap gap-2">
    <a href="<?= e(url('modules/reportes/registros_sanitarios.php')) ?>" class="btn btn-soft btn-sm"><?= icon('shield', 'w-3.5 h-3.5') ?> Registros sanitarios</a>
    <a href="<?= e(url('modules/reportes/vencimientos.php')) ?>" class="btn btn-soft btn-sm"><?= icon('clock', 'w-3.5 h-3.5') ?> Control de vencimientos</a>
    <a href="<?= e(url('modules/reportes/trazabilidad.php')) ?>" class="btn btn-soft btn-sm"><?= icon('search', 'w-3.5 h-3.5') ?> Trazabilidad de lote</a>
    <a href="<?= e(url('modules/reportes/proveedores_sanitario.php')) ?>" class="btn btn-soft btn-sm"><?= icon('truck', 'w-3.5 h-3.5') ?> Ficha de proveedores</a>
  </div>
</div>

<?php layout_end(); ?>
