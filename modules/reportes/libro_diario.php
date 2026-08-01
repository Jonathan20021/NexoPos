<?php
/**
 * Libro diario: los asientos del periodo en formato debe/haber.
 *
 * El sistema no lleva partida doble nativa; aquí se DERIVAN los asientos a
 * partir de las operaciones reales (ventas, compras, devoluciones, gastos,
 * nómina y cobros). Es el puente para entregarle algo usable a la contabilidad
 * externa sin pedirle a nadie que teclee dos veces.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('reportes.contabilidad');

$p = rep_periodo('mes');
[$scopeV, $scopeVP] = rep_scope('v.sucursal_id');
[$scopeC, $scopeCP] = rep_scope('c.sucursal_id');
[$scopeD, $scopeDP] = rep_scope('d.sucursal_id');
[$scopeT, $scopeTP] = rep_scope('t.sucursal_id');

$LIMITE = 400;   // tope de asientos en pantalla (el Excel trae todo)
$asientos = [];

/** Agrega un asiento con sus líneas. */
function asiento(array &$out, string $fecha, string $tipo, string $ref, string $desc, array $lineas): void
{
    $debe = $haber = 0.0;
    foreach ($lineas as $l) { $debe += $l[1]; $haber += $l[2]; }
    if ($debe < 0.005 && $haber < 0.005) return;
    $out[] = ['fecha' => $fecha, 'tipo' => $tipo, 'ref' => $ref, 'desc' => $desc,
              'lineas' => $lineas, 'debe' => $debe, 'haber' => $haber];
}

/* ---------- 1) Ventas ---------- */
$ventas = qAll(
    "SELECT v.id, v.numero, v.ncf, v.fecha, v.subtotal, v.descuento, v.itbis, v.total, v.costo_total,
            COALESCE(cl.nombre,'Consumidor final') AS cliente,
            COALESCE((SELECT SUM(vp.monto) FROM venta_pagos vp
                        JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id AND mp.es_credito = 1
                       WHERE vp.venta_id = v.id),0) AS credito
       FROM ventas v LEFT JOIN clientes cl ON cl.id = v.cliente_id
      WHERE v.estado = 'completada' AND v.fecha BETWEEN ? AND ? AND $scopeV
      ORDER BY v.fecha",
    array_merge([$p['ini'], $p['fin']], $scopeVP)
);
foreach ($ventas as $v) {
    $ingreso = (float) $v['subtotal'] - (float) $v['descuento'];
    $credito = min((float) $v['credito'], (float) $v['total']);
    $contado = (float) $v['total'] - $credito;
    $lineas = [];
    if ($contado > 0.005) $lineas[] = ['Efectivo y bancos', $contado, 0.0];
    if ($credito > 0.005) $lineas[] = ['Cuentas por cobrar', $credito, 0.0];
    $lineas[] = ['Ingresos por ventas', 0.0, $ingreso];
    if ((float) $v['itbis'] > 0.005) $lineas[] = ['ITBIS por pagar (DGII)', 0.0, (float) $v['itbis']];
    asiento($asientos, $v['fecha'], 'Venta', $v['numero'] . ($v['ncf'] ? ' · ' . $v['ncf'] : ''), 'Facturación a ' . $v['cliente'], $lineas);

    if ((float) $v['costo_total'] > 0.005) {
        asiento($asientos, $v['fecha'], 'Costo', $v['numero'], 'Costo de la mercancía vendida', [
            ['Costo de ventas', (float) $v['costo_total'], 0.0],
            ['Inventario de mercancías', 0.0, (float) $v['costo_total']],
        ]);
    }
}

/* ---------- 2) Compras ---------- */
$compras = qAll(
    "SELECT c.numero, c.ncf, c.fecha, c.subtotal, c.itbis, c.total, c.forma_pago, c.fecha_pago,
            COALESCE(pr.nombre,'Sin proveedor') AS proveedor
       FROM compras c LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
      WHERE c.estado <> 'anulada' AND c.fecha BETWEEN ? AND ? AND $scopeC
      ORDER BY c.fecha",
    array_merge([$p['desde'], $p['hasta']], $scopeCP)
);
foreach ($compras as $c) {
    $itbis = (float) $c['itbis'];
    $total = (float) $c['total'];
    $mercancia = max(0.0, $total - $itbis);
    $contra = ((int) $c['forma_pago'] === 4 && !$c['fecha_pago']) ? 'Cuentas por pagar' : 'Efectivo y bancos';
    $lineas = [['Inventario de mercancías', $mercancia, 0.0]];
    if ($itbis > 0.005) $lineas[] = ['ITBIS adelantado (crédito fiscal)', $itbis, 0.0];
    $lineas[] = [$contra, 0.0, $total];
    asiento($asientos, $c['fecha'] . ' 00:00:00', 'Compra', $c['numero'] . ($c['ncf'] ? ' · ' . $c['ncf'] : ''),
        'Compra a ' . $c['proveedor'], $lineas);
}

/* ---------- 3) Devoluciones ---------- */
$devoluciones = qAll(
    "SELECT d.numero, d.ncf, d.created_at AS fecha, d.subtotal, d.itbis, d.total, v.numero AS venta
       FROM devoluciones d LEFT JOIN ventas v ON v.id = d.venta_id
      WHERE d.created_at BETWEEN ? AND ? AND $scopeD ORDER BY d.created_at",
    array_merge([$p['ini'], $p['fin']], $scopeDP)
);
foreach ($devoluciones as $d) {
    $lineas = [['Devoluciones sobre ventas', (float) $d['subtotal'], 0.0]];
    if ((float) $d['itbis'] > 0.005) $lineas[] = ['ITBIS por pagar (DGII)', (float) $d['itbis'], 0.0];
    $lineas[] = ['Efectivo y bancos', 0.0, (float) $d['total']];
    asiento($asientos, $d['fecha'], 'Devolución', $d['numero'] . ($d['ncf'] ? ' · ' . $d['ncf'] : ''),
        'Nota de crédito sobre ' . ($d['venta'] ?: 'venta'), $lineas);
}

/* ---------- 4) Gastos y otros ingresos de caja ---------- */
$movs = qAll(
    "SELECT t.id, t.fecha, t.tipo, t.monto, t.descripcion, t.referencia_tipo,
            COALESCE(cf.nombre, IF(t.tipo='gasto','Gastos varios','Otros ingresos')) AS categoria,
            cu.nombre AS cuenta
       FROM transacciones t
       LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
       LEFT JOIN cuentas_financieras cu ON cu.id = t.cuenta_id
      WHERE t.fecha BETWEEN ? AND ? AND $scopeT
        AND (t.referencia_tipo IS NULL OR t.referencia_tipo NOT IN ('venta','compra','devolucion'))
      ORDER BY t.fecha",
    array_merge([$p['desde'], $p['hasta']], $scopeTP)
);
foreach ($movs as $m) {
    $monto = (float) $m['monto'];
    $cuenta = $m['cuenta'] ?: 'Efectivo y bancos';
    if ($m['tipo'] === 'gasto') {
        asiento($asientos, $m['fecha'] . ' 00:00:00', 'Gasto', $m['referencia_tipo'] ?: 'manual',
            $m['descripcion'] ?: $m['categoria'],
            [[$m['categoria'], $monto, 0.0], [$cuenta, 0.0, $monto]]);
    } elseif ($m['referencia_tipo'] === 'abono') {
        asiento($asientos, $m['fecha'] . ' 00:00:00', 'Cobro', 'abono',
            $m['descripcion'] ?: 'Abono de cliente',
            [[$cuenta, $monto, 0.0], ['Cuentas por cobrar', 0.0, $monto]]);
    } else {
        asiento($asientos, $m['fecha'] . ' 00:00:00', 'Ingreso', $m['referencia_tipo'] ?: 'manual',
            $m['descripcion'] ?: $m['categoria'],
            [[$cuenta, $monto, 0.0], [$m['categoria'], 0.0, $monto]]);
    }
}

usort($asientos, fn($a, $b) => [$a['fecha'], $a['tipo']] <=> [$b['fecha'], $b['tipo']]);

/* ---------- Balanza de comprobación ---------- */
$balanza = [];
foreach ($asientos as $a) {
    foreach ($a['lineas'] as [$cuenta, $debe, $haber]) {
        if (!isset($balanza[$cuenta])) $balanza[$cuenta] = ['debe' => 0.0, 'haber' => 0.0];
        $balanza[$cuenta]['debe']  += $debe;
        $balanza[$cuenta]['haber'] += $haber;
    }
}
ksort($balanza);
$totDebe  = array_sum(array_column($balanza, 'debe'));
$totHaber = array_sum(array_column($balanza, 'haber'));

/* ---------- Resumen por tipo ---------- */
$porTipo = [];
foreach ($asientos as $a) {
    $porTipo[$a['tipo']] = ($porTipo[$a['tipo']] ?? 0) + 1;
}

if (export_solicitado()) {
    $filas = [];
    $n = 0;
    foreach ($asientos as $a) {
        $n++;
        foreach ($a['lineas'] as [$cuenta, $debe, $haber]) {
            $filas[] = [str_pad((string) $n, 5, '0', STR_PAD_LEFT), fechaCorta($a['fecha']), $a['tipo'], $a['ref'],
                $a['desc'], $cuenta, $debe > 0 ? money($debe, false) : '', $haber > 0 ? money($haber, false) : ''];
        }
    }
    export_tabla('libro_diario_' . $p['desde'] . '_' . $p['hasta'],
        ['Asiento', 'Fecha', 'Tipo', 'Referencia', 'Concepto', 'Cuenta', 'Debe', 'Haber'],
        $filas, 'Libro diario');
}

layout_start('Libro diario', rep_subtitulo($p), rep_barra_titulo());
echo rep_abrir('Libro diario', $p, ['sucursal' => true]);
?>

<?= rep_kpis([
    ['label' => 'Asientos generados', 'valor' => number_format(count($asientos)), 'icono' => 'book', 'color' => 'blue',
     'nota' => implode(' · ', array_map(fn($k, $v) => $v . ' ' . mb_strtolower($k), array_keys($porTipo), $porTipo))],
    ['label' => 'Total al debe', 'valor' => money($totDebe), 'icono' => 'arrow-down', 'color' => 'emerald',
     'nota' => count($balanza) . ' cuenta(s) afectadas'],
    ['label' => 'Total al haber', 'valor' => money($totHaber), 'icono' => 'arrow-up', 'color' => 'rose',
     'nota' => 'Debe ser igual al debe'],
    ['label' => 'Descuadre', 'valor' => money(abs($totDebe - $totHaber)), 'icono' => 'scale',
     'color' => abs($totDebe - $totHaber) < 0.5 ? 'emerald' : 'rose',
     'nota' => abs($totDebe - $totHaber) < 0.5 ? 'La partida cuadra' : 'Revisar operaciones con importes inconsistentes'],
], 4) ?>

<!-- Balanza de comprobación -->
<?= rep_seccion('Balanza de comprobación', 'Movimiento acumulado de cada cuenta en el periodo', 'scale', 'indigo') ?>
  <?php
  $filas = [];
  foreach ($balanza as $cuenta => $v) {
      $saldo = $v['debe'] - $v['haber'];
      $filas[] = [
          '<span class="font-semibold text-slate-700">' . e($cuenta) . '</span>',
          '<span class="text-slate-600 tabular-nums">' . ($v['debe'] > 0 ? money($v['debe']) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="text-slate-600 tabular-nums">' . ($v['haber'] > 0 ? money($v['haber']) : '<span class="text-slate-300">—</span>') . '</span>',
          '<span class="font-bold ' . ($saldo >= 0 ? 'text-slate-800' : 'text-rose-600') . ' tabular-nums">' . money(abs($saldo)) . '</span>',
          '<span class="text-xs font-semibold text-slate-400">' . ($saldo >= 0 ? 'Deudor' : 'Acreedor') . '</span>',
      ];
  }
  echo rep_tabla(
      ['Cuenta', ['Debe', 'right'], ['Haber', 'right'], ['Saldo', 'right'], ['Naturaleza', 'center']],
      $filas,
      ['total' => $filas ? ['Totales', money($totDebe), money($totHaber), money(abs($totDebe - $totHaber)), ''] : null,
       'vacio_titulo' => 'Sin operaciones en el periodo',
       'vacio' => 'No hubo ventas, compras ni movimientos de caja en el rango seleccionado.']
  );
  ?>
<?= rep_fin() ?>

<!-- Asientos -->
<?= rep_seccion('Asientos del periodo', 'En orden cronológico, con su contrapartida', 'book', 'blue',
    '<span class="badge badge-slate">' . number_format(count($asientos)) . ' asientos</span>') ?>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead>
        <tr>
          <th class="text-center w-16">#</th><th>Fecha</th><th>Tipo</th><th>Referencia</th>
          <th>Cuenta</th><th class="text-right">Debe</th><th class="text-right">Haber</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$asientos): ?>
          <tr><td colspan="7"><?= empty_state('Sin asientos', 'No hubo operaciones económicas en el periodo seleccionado.', 'book') ?></td></tr>
        <?php else:
          $colores = ['Venta' => 'emerald', 'Costo' => 'slate', 'Compra' => 'blue', 'Devolución' => 'rose',
                      'Gasto' => 'amber', 'Ingreso' => 'cyan', 'Cobro' => 'violet'];
          foreach (array_slice($asientos, 0, $LIMITE) as $n => $a):
            $lineas = $a['lineas'];
            $span = count($lineas);
        ?>
          <?php foreach ($lineas as $li => [$cuenta, $debe, $haber]): ?>
            <tr class="<?= $li === 0 ? 'border-t-2 border-slate-100' : '' ?>">
              <?php if ($li === 0): ?>
                <td rowspan="<?= $span ?>" class="text-center align-top text-slate-400 font-semibold tabular-nums text-xs pt-4"><?= str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT) ?></td>
                <td rowspan="<?= $span ?>" class="align-top text-slate-500 whitespace-nowrap pt-4"><?= fechaCorta($a['fecha']) ?></td>
                <td rowspan="<?= $span ?>" class="align-top pt-4"><?= badge($a['tipo'], $colores[$a['tipo']] ?? 'slate') ?></td>
                <td rowspan="<?= $span ?>" class="align-top pt-4">
                  <span class="font-semibold text-slate-700 block"><?= e($a['ref']) ?></span>
                  <span class="text-[11px] text-slate-400"><?= e($a['desc']) ?></span>
                </td>
              <?php endif; ?>
              <td class="<?= $haber > 0 ? 'pl-8 text-slate-500' : 'text-slate-700 font-medium' ?>"><?= e($cuenta) ?></td>
              <td class="text-right tabular-nums <?= $debe > 0 ? 'font-semibold text-slate-800' : 'text-slate-300' ?>"><?= $debe > 0 ? money($debe, false) : '—' ?></td>
              <td class="text-right tabular-nums <?= $haber > 0 ? 'font-semibold text-slate-800' : 'text-slate-300' ?>"><?= $haber > 0 ? money($haber, false) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($asientos) > $LIMITE): ?>
    <p class="px-5 py-4 border-t border-slate-100 text-xs text-slate-500">
      Se muestran los primeros <?= $LIMITE ?> asientos de <?= number_format(count($asientos)) ?>.
      Descarga el Excel para entregarle el periodo completo a la contabilidad.
    </p>
  <?php endif; ?>
  <div class="px-5 py-4 border-t border-slate-100 bg-slate-50/60 text-xs text-slate-500 leading-relaxed">
    <strong class="text-slate-600">Cómo leer esto.</strong>
    Los asientos se derivan de las operaciones registradas en el sistema con un plan de cuentas simplificado.
    Sirven para conciliar y para entregar el movimiento a la contabilidad externa; no sustituyen el catálogo formal
    de cuentas de la empresa si este difiere.
  </div>
<?= rep_fin() ?>

<?php layout_end(); ?>
