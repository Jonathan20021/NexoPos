<?php
/**
 * Panel ejecutivo (CEO): el estado del negocio en una sola pantalla.
 *
 * Criterio contable usado en TODOS los reportes:
 *   ingresos       = subtotal − descuento   (sin ITBIS: el ITBIS no es ingreso)
 *   utilidad bruta = ingresos − costo de la mercancía vendida
 *   utilidad neta  = utilidad bruta − gastos operativos del periodo
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.ejecutivo');

$p = rep_periodo('mes');
[$scope, $scopeP]   = rep_scope('v.sucursal_id');
[$scopeT, $scopeTP] = rep_scope('t.sucursal_id');
[$scopeD, $scopeDP] = rep_scope('d.sucursal_id');

/** Bloque de cifras de un rango de fechas (para comparar contra el periodo anterior). */
function ej_cifras(string $ini, string $fin, string $scope, array $scopeP, string $scopeD, array $scopeDP, string $scopeT, array $scopeTP): array
{
    $v = qOne(
        "SELECT COUNT(*) AS facturas,
                COALESCE(SUM(v.subtotal - v.descuento),0) AS ingresos,
                COALESCE(SUM(v.itbis),0)                  AS itbis,
                COALESCE(SUM(v.costo_total),0)            AS costo,
                COALESCE(SUM(v.total),0)                  AS cobrado,
                COALESCE(SUM(v.descuento),0)              AS descuentos,
                COUNT(DISTINCT v.cliente_id)              AS clientes
           FROM ventas v
          WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope",
        array_merge([$ini, $fin], $scopeP)
    ) ?: [];

    $dev = (float) qVal(
        "SELECT COALESCE(SUM(d.subtotal),0) FROM devoluciones d
          WHERE d.created_at BETWEEN ? AND ? AND $scopeD",
        array_merge([$ini, $fin], $scopeDP)
    );

    // Solo gasto OPERATIVO: la compra de mercancía es inventario y la devolución
    // ya se resta de los ingresos (ver rep_where_gastos()).
    $gasto = (float) qVal(
        "SELECT COALESCE(SUM(t.monto),0) FROM transacciones t
          WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $scopeT",
        array_merge([substr($ini, 0, 10), substr($fin, 0, 10)], $scopeTP)
    );

    $ingresos = (float) ($v['ingresos'] ?? 0) - $dev;
    $costo    = (float) ($v['costo'] ?? 0);
    $bruta    = $ingresos - $costo;

    return [
        'facturas'   => (int) ($v['facturas'] ?? 0),
        'ingresos'   => $ingresos,
        'itbis'      => (float) ($v['itbis'] ?? 0),
        'costo'      => $costo,
        'cobrado'    => (float) ($v['cobrado'] ?? 0),
        'descuentos' => (float) ($v['descuentos'] ?? 0),
        'clientes'   => (int) ($v['clientes'] ?? 0),
        'devoluciones' => $dev,
        'bruta'      => $bruta,
        'gastos'     => $gasto,
        'neta'       => $bruta - $gasto,
        'margen'     => $ingresos > 0 ? $bruta / $ingresos * 100 : 0,
        'ticket'     => ($v['facturas'] ?? 0) > 0 ? $ingresos / (int) $v['facturas'] : 0,
    ];
}

$act = ej_cifras($p['ini'], $p['fin'], $scope, $scopeP, $scopeD, $scopeDP, $scopeT, $scopeTP);
$ant = ej_cifras($p['prev_ini'], $p['prev_fin'], $scope, $scopeP, $scopeD, $scopeDP, $scopeT, $scopeTP);

/* ---------- Tendencia de 12 meses ---------- */
$meses = rep_meses_atras(12);
$serieIngresos = [];
$serieUtilidad = [];
$labels = [];
$filaMes = qAll(
    "SELECT DATE_FORMAT(v.fecha,'%Y-%m') AS ym,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS ingresos,
            COALESCE(SUM(v.costo_total),0) AS costo
       FROM ventas v
      WHERE v.estado = 'completada' AND v.fecha >= ? AND $scope
      GROUP BY ym",
    array_merge([$meses[0] . '-01 00:00:00'], $scopeP)
);
$mapaMes = [];
foreach ($filaMes as $r) $mapaMes[$r['ym']] = $r;
$gastoMes = qAll(
    "SELECT DATE_FORMAT(t.fecha,'%Y-%m') AS ym, COALESCE(SUM(t.monto),0) AS g
       FROM transacciones t WHERE " . rep_where_gastos() . " AND t.fecha >= ? AND $scopeT GROUP BY ym",
    array_merge([$meses[0] . '-01'], $scopeTP)
);
$mapaGasto = [];
foreach ($gastoMes as $r) $mapaGasto[$r['ym']] = (float) $r['g'];

foreach ($meses as $ym) {
    $labels[] = rep_mes_label($ym);
    $ing = (float) ($mapaMes[$ym]['ingresos'] ?? 0);
    $cos = (float) ($mapaMes[$ym]['costo'] ?? 0);
    $serieIngresos[] = $ing;
    $serieUtilidad[] = $ing - $cos - ($mapaGasto[$ym] ?? 0);
}

/* ---------- Ventas por sucursal ---------- */
$porSucursal = qAll(
    "SELECT su.nombre AS sucursal, COUNT(v.id) AS facturas,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS ingresos,
            COALESCE(SUM(v.subtotal - v.descuento - v.costo_total),0) AS utilidad
       FROM ventas v JOIN sucursales su ON su.id = v.sucursal_id
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY v.sucursal_id ORDER BY ingresos DESC",
    array_merge([$p['ini'], $p['fin']], $scopeP)
);

/* ---------- Canal de captación ---------- */
$porCanal = qAll(
    "SELECT COALESCE(NULLIF(v.canal_venta,''),'Sin especificar') AS canal,
            COUNT(*) AS n, COALESCE(SUM(v.subtotal - v.descuento),0) AS ingresos
       FROM ventas v
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY canal ORDER BY ingresos DESC",
    array_merge([$p['ini'], $p['fin']], $scopeP)
);

/* ---------- Top productos por utilidad ---------- */
$topProductos = qAll(
    "SELECT COALESCE(p.nombre, vd.descripcion) AS producto, c.nombre AS categoria,
            SUM(vd.cantidad) AS unidades,
            SUM(vd.subtotal - vd.descuento) AS ingresos,
            SUM(vd.subtotal - vd.descuento - (vd.cantidad * vd.costo_unitario)) AS utilidad
       FROM venta_detalles vd
       JOIN ventas v ON v.id = vd.venta_id
       LEFT JOIN productos p  ON p.id = vd.producto_id
       LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
      -- Se agrupa por TODAS las columnas no agregadas que se seleccionan.
      -- MySQL 8 trae ONLY_FULL_GROUP_BY activo y rechaza lo contrario (error 1055);
      -- MariaDB lo permite, así que en desarrollo no se nota y en producción rompe.
      GROUP BY COALESCE(p.id, vd.descripcion), COALESCE(p.nombre, vd.descripcion), c.nombre
      ORDER BY utilidad DESC LIMIT 10",
    array_merge([$p['ini'], $p['fin']], $scopeP)
);

/* ---------- Top clientes ---------- */
$topClientes = qAll(
    "SELECT COALESCE(cl.nombre,'Consumidor final') AS cliente, COUNT(v.id) AS compras,
            COALESCE(SUM(v.subtotal - v.descuento),0) AS ingresos
       FROM ventas v LEFT JOIN clientes cl ON cl.id = v.cliente_id
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scope
      GROUP BY v.cliente_id ORDER BY ingresos DESC LIMIT 8",
    array_merge([$p['ini'], $p['fin']], $scopeP)
);
$totalTopCli = array_sum(array_column($topClientes, 'ingresos')) ?: 1;

/* ---------- Metas activas ---------- */
$metas = qAll(
    "SELECT m.*, CONCAT(u.nombre,' ',u.apellido) AS vendedor, su.nombre AS sucursal
       FROM metas_ventas m
       LEFT JOIN usuarios u    ON u.id = m.usuario_id
       LEFT JOIN sucursales su ON su.id = m.sucursal_id
      WHERE m.estado = 'activa' AND m.periodo_inicio <= ? AND m.periodo_fin >= ?
      ORDER BY m.periodo_fin ASC LIMIT 6",
    [$p['hasta'], $p['desde']]
);

/* ---------- Embudo CRM ---------- */
$embudo = [];
if (can('crm.ver')) {
    $etapas = ['prospecto' => 'Prospecto', 'contactado' => 'Contactado', 'propuesta' => 'Propuesta',
               'negociacion' => 'Negociación', 'ganada' => 'Ganada', 'perdida' => 'Perdida'];
    $rows = qAll(
        "SELECT etapa, COUNT(*) n, COALESCE(SUM(valor_estimado),0) valor
           FROM crm_oportunidades o
          WHERE " . rep_scope('o.sucursal_id')[0] . " GROUP BY etapa",
        rep_scope('o.sucursal_id')[1]
    );
    $mapa = [];
    foreach ($rows as $r) $mapa[$r['etapa']] = $r;
    foreach ($etapas as $k => $lbl) {
        $embudo[] = ['etapa' => $lbl, 'n' => (int) ($mapa[$k]['n'] ?? 0), 'valor' => (float) ($mapa[$k]['valor'] ?? 0), 'clave' => $k];
    }
}

/* ---------- Salud financiera ---------- */
$cxc = qOne("SELECT COUNT(*) n, COALESCE(SUM(balance),0) t FROM clientes WHERE balance > 0") ?: ['n' => 0, 't' => 0];
$inventario = (float) qVal(
    "SELECT COALESCE(SUM(s.cantidad * p.precio_compra),0) FROM inventario_stock s
       JOIN productos p ON p.id = s.producto_id AND p.activo = 1 WHERE " . rep_scope('s.sucursal_id')[0],
    rep_scope('s.sucursal_id')[1]
);
$efectivo = (float) qVal("SELECT COALESCE(SUM(balance),0) FROM cuentas_financieras WHERE activo = 1");

/* ============================================================
 *  Exportaciones
 * ============================================================ */
if (quiere_excel()) {
    $filas = [
        ['Ingresos netos (sin ITBIS)', money($act['ingresos'], false), money($ant['ingresos'], false)],
        ['Costo de la mercancía vendida', money($act['costo'], false), money($ant['costo'], false)],
        ['Utilidad bruta', money($act['bruta'], false), money($ant['bruta'], false)],
        ['Gastos operativos', money($act['gastos'], false), money($ant['gastos'], false)],
        ['Utilidad neta', money($act['neta'], false), money($ant['neta'], false)],
        ['Margen bruto %', number_format($act['margen'], 2), number_format($ant['margen'], 2)],
        ['Facturas', $act['facturas'], $ant['facturas']],
        ['Ticket promedio', money($act['ticket'], false), money($ant['ticket'], false)],
        ['Devoluciones', money($act['devoluciones'], false), money($ant['devoluciones'], false)],
        ['ITBIS facturado', money($act['itbis'], false), money($ant['itbis'], false)],
    ];
    export_tabla('panel_ejecutivo_' . $p['desde'] . '_' . $p['hasta'],
        ['Indicador', 'Periodo actual', 'Periodo anterior'], $filas, 'Panel ejecutivo');
}

if (quiere_pdf() && function_exists('pdf_render')) {
    $H = pdf_brand_header('PANEL EJECUTIVO', 'Periodo ' . fechaCorta($p['desde']) . ' al ' . fechaCorta($p['hasta']) . ' · ' . rep_alcance_sucursal());
    $H .= '<h3>Resultado del periodo</h3><table class="tbl"><thead><tr><th>Indicador</th><th class="num">Actual</th><th class="num">Anterior</th><th class="num">Var.</th></tr></thead><tbody>';
    $lineas = [
        ['Ingresos netos (sin ITBIS)', $act['ingresos'], $ant['ingresos']],
        ['(−) Costo de mercancía vendida', $act['costo'], $ant['costo']],
        ['Utilidad bruta', $act['bruta'], $ant['bruta']],
        ['(−) Gastos operativos', $act['gastos'], $ant['gastos']],
        ['Utilidad neta', $act['neta'], $ant['neta']],
    ];
    foreach ($lineas as [$lbl, $a, $b]) {
        $d = rep_delta((float) $a, (float) $b);
        $H .= '<tr><td>' . htmlspecialchars($lbl) . '</td><td class="num">' . money($a) . '</td><td class="num">' . money($b)
            . '</td><td class="num">' . ($d === null ? '—' : number_format($d, 1) . '%') . '</td></tr>';
    }
    $H .= '</tbody></table>';

    $H .= '<h3>Ventas por sucursal</h3><table class="tbl"><thead><tr><th>Sucursal</th><th class="num">Facturas</th><th class="num">Ingresos</th><th class="num">Utilidad</th></tr></thead><tbody>';
    foreach ($porSucursal as $s) {
        $H .= '<tr><td>' . htmlspecialchars($s['sucursal']) . '</td><td class="num">' . (int) $s['facturas']
            . '</td><td class="num">' . money($s['ingresos']) . '</td><td class="num">' . money($s['utilidad']) . '</td></tr>';
    }
    $H .= ($porSucursal ? '' : '<tr><td colspan="4">Sin datos</td></tr>') . '</tbody></table>';

    $H .= '<h3>Productos más rentables</h3><table class="tbl"><thead><tr><th>Producto</th><th class="num">Unid.</th><th class="num">Ingresos</th><th class="num">Utilidad</th></tr></thead><tbody>';
    foreach ($topProductos as $t) {
        $H .= '<tr><td>' . htmlspecialchars($t['producto']) . '</td><td class="num">' . qty($t['unidades'])
            . '</td><td class="num">' . money($t['ingresos']) . '</td><td class="num">' . money($t['utilidad']) . '</td></tr>';
    }
    $H .= ($topProductos ? '' : '<tr><td colspan="4">Sin datos</td></tr>') . '</tbody></table>';

    $H .= '<h3>Mejores clientes</h3><table class="tbl"><thead><tr><th>Cliente</th><th class="num">Compras</th><th class="num">Ingresos</th></tr></thead><tbody>';
    foreach ($topClientes as $c) {
        $H .= '<tr><td>' . htmlspecialchars($c['cliente']) . '</td><td class="num">' . (int) $c['compras'] . '</td><td class="num">' . money($c['ingresos']) . '</td></tr>';
    }
    $H .= ($topClientes ? '' : '<tr><td colspan="3">Sin datos</td></tr>') . '</tbody></table>';

    pdf_render($H, 'panel_ejecutivo_' . $p['desde'] . '_a_' . $p['hasta'], 'portrait');
}

layout_start('Panel ejecutivo', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Panel ejecutivo', $p, ['sucursal' => true]);
?>

<!-- KPIs principales -->
<?= rep_kpis([
    ['label' => 'Ingresos netos', 'valor' => money($act['ingresos']), 'icono' => 'cash', 'color' => 'blue',
     'delta' => rep_delta($act['ingresos'], $ant['ingresos']),
     'nota' => 'Sin ITBIS · ' . number_format($act['facturas']) . ' factura(s)'],
    ['label' => 'Utilidad bruta', 'valor' => money($act['bruta']), 'icono' => 'trending', 'color' => 'emerald',
     'delta' => rep_delta($act['bruta'], $ant['bruta']),
     'nota' => 'Margen ' . number_format($act['margen'], 1) . '%'],
    ['label' => 'Gastos operativos', 'valor' => money($act['gastos']), 'icono' => 'dollar', 'color' => 'amber',
     'delta' => rep_delta($act['gastos'], $ant['gastos']), 'invertir' => true,
     'nota' => $act['ingresos'] > 0 ? number_format($act['gastos'] / $act['ingresos'] * 100, 1) . '% de los ingresos' : '—'],
    ['label' => 'Utilidad neta', 'valor' => money($act['neta']), 'icono' => 'chart',
     'color' => $act['neta'] >= 0 ? 'violet' : 'rose',
     'delta' => rep_delta($act['neta'], $ant['neta']),
     'nota' => $act['ingresos'] > 0 ? 'Rentabilidad ' . number_format($act['neta'] / $act['ingresos'] * 100, 1) . '%' : '—'],
]) ?>

<!-- KPIs secundarios -->
<?= rep_kpis([
    ['label' => 'Ticket promedio', 'valor' => money($act['ticket']), 'icono' => 'receipt', 'color' => 'indigo',
     'delta' => rep_delta($act['ticket'], $ant['ticket']), 'nota' => 'Ingreso medio por factura'],
    ['label' => 'Clientes atendidos', 'valor' => number_format($act['clientes']), 'icono' => 'users', 'color' => 'cyan',
     'delta' => rep_delta((float) $act['clientes'], (float) $ant['clientes']), 'nota' => 'Distintos en el periodo'],
    ['label' => 'Devoluciones', 'valor' => money($act['devoluciones']), 'icono' => 'undo', 'color' => 'rose',
     'delta' => rep_delta($act['devoluciones'], $ant['devoluciones']), 'invertir' => true,
     'nota' => $act['ingresos'] > 0 ? number_format($act['devoluciones'] / max($act['ingresos'], 1) * 100, 2) . '% de la venta' : '—'],
    ['label' => 'Descuentos otorgados', 'valor' => money($act['descuentos']), 'icono' => 'percent', 'color' => 'amber',
     'delta' => rep_delta($act['descuentos'], $ant['descuentos']), 'invertir' => true,
     'nota' => 'Utilidad cedida en el periodo'],
]) ?>

<!-- Tendencia -->
<?= rep_seccion('Tendencia de los últimos 12 meses', 'Ingresos netos contra utilidad neta, mes a mes', 'trending', 'blue') ?>
  <div class="px-5 pb-5">
    <?= lineChart([
        ['nombre' => 'Ingresos netos', 'color' => marca_app(), 'valores' => $serieIngresos, 'area' => true],
        ['nombre' => 'Utilidad neta', 'color' => '#10b981', 'valores' => $serieUtilidad],
    ], $labels, ['alto' => 280]) ?>
  </div>
<?= rep_fin() ?>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-stretch">
  <!-- Sucursales -->
  <div class="lg:col-span-3">
    <?= rep_seccion('Resultado por sucursal', 'Dónde se genera el dinero', 'store', 'indigo') ?>
      <?php
      $tot = array_sum(array_column($porSucursal, 'ingresos')) ?: 1;
      $filas = [];
      foreach ($porSucursal as $i => $s) {
          $marg = $s['ingresos'] > 0 ? $s['utilidad'] / $s['ingresos'] * 100 : 0;
          $filas[] = [
              '<span class="font-semibold text-slate-700">' . e($s['sucursal']) . '</span>',
              '<span class="text-slate-500">' . number_format((int) $s['facturas']) . '</span>',
              '<span class="font-semibold text-slate-800 tabular-nums">' . money($s['ingresos']) . '</span>',
              '<span class="' . ($s['utilidad'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' font-semibold tabular-nums">' . money($s['utilidad']) . '</span>',
              '<span class="badge badge-' . ($marg >= 25 ? 'emerald' : ($marg >= 10 ? 'amber' : 'rose')) . '">' . number_format($marg, 1) . '%</span>',
          ];
      }
      echo rep_tabla(
          ['Sucursal', ['Facturas', 'center'], ['Ingresos', 'right'], ['Utilidad', 'right'], ['Margen', 'center']],
          $filas,
          ['total' => $filas ? [
              'Total',
              number_format(array_sum(array_column($porSucursal, 'facturas'))),
              money(array_sum(array_column($porSucursal, 'ingresos'))),
              money(array_sum(array_column($porSucursal, 'utilidad'))),
              '',
          ] : null]
      );
      ?>
    <?= rep_fin() ?>
  </div>

  <!-- Canal -->
  <div class="lg:col-span-2">
    <?= rep_seccion('Origen de las ventas', 'Qué canal trae el dinero (marketing)', 'pie', 'violet') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php if (!$porCanal): ?>
          <?= empty_state('Sin ventas en el periodo', 'Cuando haya facturación verás de qué canal llegó cada peso.', 'megaphone') ?>
        <?php else: ?>
          <?= donutMulti(
              array_map(fn($c, $i) => ['label' => $c['canal'], 'value' => (float) $c['ingresos'], 'color' => rep_color($i)],
                        $porCanal, array_keys($porCanal)),
              'Total', money(array_sum(array_column($porCanal, 'ingresos')), false)
          ) ?>
          <?php $soloSinCanal = count($porCanal) === 1 && $porCanal[0]['canal'] === 'Sin especificar'; ?>
          <?php if ($soloSinCanal): ?>
            <div class="mt-5 flex items-start gap-3 rounded-xl bg-amber-50/60 border border-amber-200 p-3.5">
              <span class="w-8 h-8 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('megaphone', 'w-4 h-4') ?></span>
              <p class="text-[12.5px] text-slate-600 leading-relaxed">
                Ninguna venta tiene canal asignado, así que no se puede medir qué trae clientes.
                Pídele al equipo que marque el canal (Instagram, WhatsApp, Referido…) al cobrar en el POS
                y este gráfico te dirá dónde vale la pena invertir en publicidad.
              </p>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-stretch">
  <!-- Productos más rentables -->
  <div class="lg:col-span-3">
    <?= rep_seccion('Productos que más utilidad dejan', 'No siempre son los más vendidos', 'package', 'emerald') ?>
      <?php
      $filas = [];
      foreach ($topProductos as $i => $t) {
          $marg = $t['ingresos'] > 0 ? $t['utilidad'] / $t['ingresos'] * 100 : 0;
          $filas[] = [
              '<span class="text-slate-400 font-semibold">' . ($i + 1) . '</span>',
              '<span class="font-semibold text-slate-700">' . e($t['producto']) . '</span>'
                . ($t['categoria'] ? '<span class="block text-xs text-slate-400">' . e($t['categoria']) . '</span>' : ''),
              '<span class="text-slate-500 tabular-nums">' . qty($t['unidades']) . '</span>',
              '<span class="text-slate-600 tabular-nums">' . money($t['ingresos']) . '</span>',
              '<span class="font-bold ' . ($t['utilidad'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . ' tabular-nums">' . money($t['utilidad'])
                . '<span class="block text-[11px] font-medium text-slate-400">' . number_format($marg, 1) . '%</span></span>',
          ];
      }
      echo rep_tabla([['#', 'center'], 'Producto', ['Unid.', 'center'], ['Ingresos', 'right'], ['Utilidad', 'right']], $filas);
      ?>
    <?= rep_fin() ?>
  </div>

  <!-- Clientes -->
  <div class="lg:col-span-2">
    <?= rep_seccion('Mejores clientes del periodo', 'Concentración de la facturación', 'users', 'cyan') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
        <?php if (!$topClientes): ?>
          <?= empty_state('Sin ventas registradas', 'Los clientes que más compren aparecerán aquí ordenados por facturación.', 'users') ?>
        <?php else: foreach ($topClientes as $i => $c): ?>
          <?= rep_barra($c['cliente'], money($c['ingresos'], false), $c['ingresos'] / $totalTopCli * 100, rep_color($i),
                        (int) $c['compras'] . ' compra' . ((int) $c['compras'] === 1 ? '' : 's')) ?>
        <?php endforeach; endif; ?>
        <?php if (count($topClientes) === 1): ?>
          <div class="mt-4 flex items-start gap-3 rounded-xl bg-slate-50 p-3.5">
            <span class="w-8 h-8 rounded-lg bg-white border border-slate-200 text-slate-400 flex items-center justify-center shrink-0"><?= icon('alert', 'w-4 h-4') ?></span>
            <p class="text-[12.5px] text-slate-600 leading-relaxed">
              Toda la facturación del periodo está en un solo cliente. Si se factura mucho como
              «Consumidor final», registrar al cliente en el POS permite medir recompra y fidelizar.
            </p>
          </div>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<!-- Metas + embudo + salud financiera. Sin acceso al CRM son dos columnas, no
     tres con un hueco. -->
<div class="grid grid-cols-1 <?= $embudo ? 'lg:grid-cols-3' : 'lg:grid-cols-2' ?> gap-5 items-stretch">
  <div>
    <?= rep_seccion('Cumplimiento de metas', 'Metas activas que tocan este periodo', 'target', 'amber',
        can('metas.ver') ? '<a href="' . e(url('modules/finanzas/metas.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Gestionar</a>' : '') ?>
      <div class="px-5 pb-5 space-y-4 flex-1 flex flex-col <?= $metas ? '' : 'justify-center' ?>">
        <?php if (!$metas): ?>
          <?= empty_state(
              'Sin metas activas',
              'Define una meta de venta por sucursal o vendedor y aquí verás el avance contra el objetivo, día a día.',
              'target',
              can('metas.gestionar')
                  ? '<a href="' . e(url('modules/finanzas/metas.php')) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Crear meta</a>'
                  : ''
          ) ?>
        <?php else: foreach ($metas as $m):
          $pr = metaProgreso($m);
          $col = metaColor($pr['pct']);
          $quien = $m['vendedor'] ?: ($m['sucursal'] ?: 'Meta global');
        ?>
          <div>
            <div class="flex items-baseline justify-between gap-2 mb-1.5">
              <span class="text-sm font-semibold text-slate-700 truncate"><?= e($quien) ?></span>
              <span class="text-sm text-slate-500 tabular-nums"><?= money($pr['vendido'], false) ?> / <?= money($pr['objetivo'], false) ?></span>
            </div>
            <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full rounded-full transition-all duration-700" style="width:<?= max($pr['pct'], 1) ?>%;background:<?= rep_color_nombre($col) ?>"></div>
            </div>
            <div class="flex items-center justify-between mt-1 text-[11.5px] text-slate-400">
              <span><?= number_format($pr['pct'], 1) ?>% cumplido</span>
              <span><?= $pr['dias_restantes'] ?> día(s) restantes · faltan <?= money($pr['falta'], false) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>

  <?php if ($embudo):
    $totalOpo = array_sum(array_column($embudo, 'n'));
    $maxE = max(1, max(array_column($embudo, 'n')));
  ?>
  <div>
    <?= rep_seccion('Embudo comercial', $totalOpo . ' oportunidad(es) viva(s) en el CRM', 'briefcase', 'violet',
        '<a href="' . e(url('modules/crm/index.php')) . '" class="text-sm font-semibold text-blue-600 hover:text-blue-700 no-print">Ver embudo</a>') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col <?= $totalOpo > 0 ? '' : 'justify-center' ?>">
        <?php if ($totalOpo === 0): ?>
          <?= empty_state(
              'El embudo está vacío',
              'Registra oportunidades en el CRM y aquí verás cuánto dinero hay en juego en cada etapa de la negociación.',
              'briefcase',
              can('crm.crear')
                  ? '<a href="' . e(url('modules/crm/oportunidades.php')) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' Nueva oportunidad</a>'
                  : ''
          ) ?>
        <?php else: ?>
          <div class="space-y-2.5">
            <?php foreach ($embudo as $i => $et):
              $col = $et['clave'] === 'ganada' ? '#10b981' : ($et['clave'] === 'perdida' ? '#f43f5e' : rep_color($i));
              $ancho = $et['n'] / $maxE * 100;
            ?>
              <div class="flex items-center gap-2.5">
                <span class="w-24 text-xs font-semibold text-slate-500 shrink-0 truncate"><?= e($et['etapa']) ?></span>
                <span class="w-7 text-center text-xs font-bold text-slate-700 tabular-nums shrink-0"><?= $et['n'] ?></span>
                <div class="flex-1 h-6 rounded-lg bg-slate-100 overflow-hidden">
                  <div class="h-full rounded-lg transition-all duration-700" style="width:<?= max($ancho, 2) ?>%;background:<?= $col ?>"></div>
                </div>
                <span class="w-24 text-right text-xs font-semibold text-slate-500 tabular-nums shrink-0"><?= money($et['valor'], false) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between">
            <span class="text-sm font-bold text-slate-700">Valor total en el embudo</span>
            <span class="font-extrabold text-slate-800 tabular-nums"><?= money(array_sum(array_column($embudo, 'valor'))) ?></span>
          </div>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>
  <?php endif; ?>

  <div>
    <?php
    $capital = $efectivo + $inventario + (float) $cxc['t'];
    $composicion = [
        ['Efectivo y bancos', $efectivo, '#10b981', 'Disponible para pagar hoy'],
        ['Inventario a costo', $inventario, '#f59e0b', 'Se vuelve efectivo al venderse'],
        ['Por cobrar a clientes', (float) $cxc['t'], '#8b5cf6', (int) $cxc['n'] . ' cliente(s) con saldo'],
    ];
    ?>
    <?= rep_seccion('Salud financiera', 'Dónde está parado el dinero hoy', 'wallet', 'emerald') ?>
      <div class="px-5 pb-5 flex-1 flex flex-col">
        <?= barraApilada(array_map(fn($c) => ['label' => $c[0], 'value' => $c[1], 'color' => $c[2]], $composicion)) ?>
        <div class="mt-5 space-y-3.5 flex-1">
          <?php foreach ($composicion as [$lbl, $val, $col, $nota]): ?>
            <div class="flex items-start justify-between gap-3">
              <span class="flex items-start gap-2.5 min-w-0">
                <span class="w-2.5 h-2.5 rounded-full shrink-0 mt-1.5" style="background:<?= $col ?>"></span>
                <span class="min-w-0">
                  <span class="block text-sm text-slate-700 font-medium"><?= e($lbl) ?></span>
                  <span class="block text-[11px] text-slate-400"><?= e($nota) ?></span>
                </span>
              </span>
              <span class="text-right shrink-0">
                <span class="block font-bold text-slate-800 tabular-nums"><?= money($val) ?></span>
                <span class="block text-[11px] text-slate-400 tabular-nums"><?= number_format($capital > 0 ? $val / $capital * 100 : 0, 1) ?>%</span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between">
          <span class="text-sm font-bold text-slate-800">Capital de trabajo</span>
          <span class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($capital) ?></span>
        </div>
        <?php if ($capital > 0 && $inventario / $capital > 0.6): ?>
          <p class="mt-3 text-[12px] text-slate-500 leading-relaxed">
            El <?= number_format($inventario / $capital * 100, 0) ?>% del capital está en mercancía.
            Es dinero que no se puede usar hasta venderla: vigila la rotación en
            <a href="<?= e(url('modules/reportes/inventario_valorizado.php')) ?>" class="font-semibold text-blue-600 hover:text-blue-700">inventario valorizado</a>.
          </p>
        <?php endif; ?>
      </div>
    <?= rep_fin() ?>
  </div>
</div>

<?php layout_end(); ?>
