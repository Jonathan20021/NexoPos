<?php
/**
 * Ficha sanitaria de proveedores.
 *
 * En una inspección la cadena no empieza en la tienda: preguntan a quién se le
 * compra la mercancía regulada y si ese proveedor está habilitado. Este reporte
 * cruza cada proveedor con su licencia, su vigencia y QUÉ productos regulados
 * suministra realmente, según las compras registradas.
 *
 * Ese cruce es la parte que no se puede improvisar: un listado de proveedores lo
 * tiene cualquiera, pero «este proveedor nos surtió estos tres productos con
 * registro sanitario» sale del histórico de compras.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.sanidad');

if (!san_disponible()) {
    layout_start('Ficha sanitaria de proveedores', 'Módulo no instalado');
    echo empty_state('Falta la migración', 'Aplica database/migracion_sanidad_p13.sql para activar el módulo de cumplimiento sanitario.', 'shield');
    layout_end();
    return;
}

$soloRegulados = get('todos') !== '1';

// Proveedores que han surtido mercancía regulada, con su licencia.
$prov = qAll(
    "SELECT pv.id, pv.nombre, pv.rnc, pv.contacto, pv.telefono, pv.email,
            pv.licencia_sanitaria, pv.licencia_vencimiento, pv.pais_origen, pv.notas_sanitarias,
            COUNT(DISTINCT p.id) AS productos_regulados,
            MAX(c.fecha) AS ultima_compra
       FROM proveedores pv
       LEFT JOIN compras c        ON c.proveedor_id = pv.id AND c.estado = 'recibida'
       LEFT JOIN compra_detalles d ON d.compra_id = c.id
       LEFT JOIN productos p       ON p.id = d.producto_id AND p.regulado = 1
      WHERE pv.activo = 1
      GROUP BY pv.id, pv.nombre, pv.rnc, pv.contacto, pv.telefono, pv.email,
               pv.licencia_sanitaria, pv.licencia_vencimiento, pv.pais_origen, pv.notas_sanitarias
      " . ($soloRegulados ? "HAVING productos_regulados > 0 OR (pv.licencia_sanitaria IS NOT NULL AND pv.licencia_sanitaria <> '')" : "") . "
      ORDER BY (pv.licencia_vencimiento IS NULL), pv.licencia_vencimiento ASC, pv.nombre"
);

// Qué productos regulados surte cada uno (para el detalle desplegable).
$porProveedor = [];
foreach (qAll(
    "SELECT DISTINCT c.proveedor_id, p.nombre, p.codigo, p.registro_sanitario, p.registro_categoria
       FROM compras c
       JOIN compra_detalles d ON d.compra_id = c.id
       JOIN productos p       ON p.id = d.producto_id
      WHERE p.regulado = 1 AND c.estado = 'recibida'
      ORDER BY p.nombre") as $r) {
    $porProveedor[(int) $r['proveedor_id']][] = $r;
}

$conLicencia = count(array_filter($prov, fn($p) => trim((string) $p['licencia_sanitaria']) !== ''));
$vencidas = count(array_filter($prov, fn($p) => $p['licencia_vencimiento'] && san_dias_hasta($p['licencia_vencimiento']) < 0));
$sinLicencia = count(array_filter($prov, fn($p) => trim((string) $p['licencia_sanitaria']) === '' && (int) $p['productos_regulados'] > 0));

if (export_solicitado()) {
    $out = [];
    foreach ($prov as $p) {
        $out[] = [$p['nombre'], $p['rnc'], $p['licencia_sanitaria'],
            $p['licencia_vencimiento'] ? fechaCorta($p['licencia_vencimiento']) : '',
            $p['pais_origen'], $p['productos_regulados'],
            $p['ultima_compra'] ? fechaCorta($p['ultima_compra']) : '',
            $p['telefono'], $p['email'], $p['notas_sanitarias']];
    }
    export_tabla('proveedores_sanitario',
        ['Proveedor', 'RNC', 'Licencia sanitaria', 'Vence', 'Origen', 'Productos regulados que surte',
         'Última compra', 'Teléfono', 'Correo', 'Notas'],
        $out, 'Ficha sanitaria de proveedores');
}

layout_start('Ficha sanitaria de proveedores', 'A quién se le compra la mercancía regulada · ' . fechaLarga(date('Y-m-d')), rep_barra_titulo());
?>

<?= rep_abrir('Proveedores de mercancía regulada', ['label' => 'Al ' . fechaLarga(date('Y-m-d'))], []) ?>

<?= rep_kpis([
    ['label' => 'Proveedores en el expediente', 'valor' => number_format(count($prov)), 'icono' => 'truck', 'color' => 'blue',
     'nota' => 'Surten productos regulados o tienen licencia'],
    ['label' => 'Con licencia registrada', 'valor' => number_format($conLicencia), 'icono' => 'shield',
     'color' => $conLicencia === count($prov) && $prov ? 'emerald' : 'amber',
     'nota' => count($prov) ? number_format($conLicencia / max(1, count($prov)) * 100, 0) . '% del total' : '—'],
    ['label' => 'Sin licencia', 'valor' => number_format($sinLicencia), 'icono' => 'alert',
     'color' => $sinLicencia > 0 ? 'rose' : 'emerald',
     'nota' => $sinLicencia > 0 ? 'Surten regulados sin documentar' : 'Todos documentados'],
    ['label' => 'Licencia vencida', 'valor' => number_format($vencidas), 'icono' => 'x',
     'color' => $vencidas > 0 ? 'rose' : 'emerald',
     'nota' => $vencidas > 0 ? 'Pide la renovación antes de comprar' : 'Ninguna vencida'],
], 4) ?>

<div class="card p-4 mb-5 no-print flex items-center gap-3">
  <a href="?" class="px-3 py-1.5 rounded-lg text-[13px] font-semibold <?= $soloRegulados ? 'bg-white text-blue-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800' ?>">Solo relevantes</a>
  <a href="?todos=1" class="px-3 py-1.5 rounded-lg text-[13px] font-semibold <?= !$soloRegulados ? 'bg-white text-blue-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800' ?>">Todos los proveedores</a>
</div>

<?php
$rows = [];
foreach ($prov as $p) {
    $dias = $p['licencia_vencimiento'] ? san_dias_hasta($p['licencia_vencimiento']) : null;
    if (trim((string) $p['licencia_sanitaria']) === '') {
        $badge = (int) $p['productos_regulados'] > 0
            ? '<span class="badge badge-rose">Sin licencia</span>'
            : '<span class="badge badge-slate">No aplica</span>';
    } elseif ($dias === null) {
        $badge = '<span class="badge badge-slate">Sin fecha</span>';
    } elseif ($dias < 0) {
        $badge = '<span class="badge badge-rose">Vencida hace ' . abs($dias) . ' d.</span>';
    } elseif ($dias <= 90) {
        $badge = '<span class="badge badge-amber">Vence en ' . $dias . ' d.</span>';
    } else {
        $badge = '<span class="badge badge-emerald">Vigente</span>';
    }

    $lista = $porProveedor[(int) $p['id']] ?? [];
    $detalle = '';
    if ($lista) {
        $detalle = '<span class="block text-[11px] text-slate-400 mt-1">'
            . e(implode(', ', array_slice(array_column($lista, 'nombre'), 0, 3)))
            . (count($lista) > 3 ? ' y ' . (count($lista) - 3) . ' más' : '') . '</span>';
    }

    $rows[] = [
        '<span class="font-semibold text-slate-700">' . e($p['nombre']) . '</span>'
          . ($p['rnc'] ? '<span class="block text-[11.5px] text-slate-400">RNC ' . e($p['rnc']) . '</span>' : ''),
        $p['licencia_sanitaria'] ? '<span class="font-mono text-[12.5px]">' . e($p['licencia_sanitaria']) . '</span>' : '<span class="text-slate-300">—</span>',
        $p['licencia_vencimiento'] ? fechaCorta($p['licencia_vencimiento']) : '<span class="text-slate-300">—</span>',
        $badge,
        e($p['pais_origen'] ?: '—'),
        '<span class="tabular-nums font-semibold">' . number_format($p['productos_regulados']) . '</span>' . $detalle,
        $p['ultima_compra'] ? fechaCorta($p['ultima_compra']) : '<span class="text-slate-300">Sin compras</span>',
    ];
}
echo rep_seccion('Detalle', count($prov) . ' proveedor(es)', 'truck', 'indigo');
echo rep_tabla(
    ['Proveedor', 'Licencia sanitaria', 'Vence', ['Estado', 'center'], 'Origen', 'Productos regulados que surte', 'Última compra'],
    $rows,
    ['vacio_titulo' => 'Sin proveedores de mercancía regulada',
     'vacio' => 'Aparecerán aquí cuando registres compras de productos marcados como regulados.',
     'vacio_icono' => 'truck']
);
echo rep_fin();
?>

<?php layout_end(); ?>
