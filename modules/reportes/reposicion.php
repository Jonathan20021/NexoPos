<?php
/**
 * Qué mover y a dónde — sugerencias de reposición entre tiendas.
 *
 * ============================================================================
 *  EL TRABAJO QUE SE HACÍA A MANO
 * ============================================================================
 *
 * «Existencias por tienda» pone el mismo artículo de las trece tiendas en una
 * fila para poder compararlo. Pero decidir de cuál sacar y a cuál mandar seguía
 * siendo cuenta de cabeza, artículo por artículo, con trescientos artículos.
 *
 * Este informe hace esa cuenta y propone movimientos concretos: cuántas
 * unidades, de qué tienda, a qué tienda. Después se crean como traslados, que
 * es donde entra la aprobación de la dirección.
 *
 * ============================================================================
 *  CÓMO DECIDE, Y QUÉ ES UNA SUPOSICIÓN
 * ============================================================================
 *
 * Para cada artículo en cada tienda:
 *
 *      venta diaria = unidades vendidas en la ventana / días de la ventana
 *      necesita     = MAX(stock mínimo, venta diaria × días de cobertura)
 *      déficit      = necesita − existencia        (si es positivo)
 *      excedente    = existencia − necesita        (si es positivo)
 *
 * Y luego, artículo por artículo, se reparte lo que sobra en unas tiendas hacia
 * lo que falta en otras, empezando por el excedente más grande y el déficit más
 * grande. Lo que no alcanza a cubrirse **no se inventa**: sale como «hay que
 * comprar».
 *
 * Los DÍAS DE COBERTURA son la única suposición y está arriba, editable: cuántos
 * días se quiere que aguante cada tienda. La venta diaria no es una suposición,
 * es lo que de verdad se vendió, neto de devoluciones y con el mismo criterio
 * que el resto de los informes.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_any_perm(['reportes.inventario', 'reportes.operacion']);

$dias      = max(7, min(365, (int) (get('cobertura') ?: 30)));   // días que debe aguantar cada tienda
$ventana   = max(14, min(365, (int) (get('ventana') ?: 60)));    // sobre cuántos días se mide la venta
$categoria = (int) get('categoria_id');
$q         = trim((string) get('q'));
$soloConMovimiento = get('todos') !== '1';

$hasta = date('Y-m-d');
$desde = date('Y-m-d', strtotime("-$ventana days"));

/* ---------- Tiendas que entran ---------- */
$sucursales = array_values(array_filter(sucursales_visibles(),
    fn($s) => can_access_sucursal((int) $s['id'])));
$ids = array_map(fn($s) => (int) $s['id'], $sucursales) ?: [0];
$ph  = implode(',', array_fill(0, count($ids), '?'));
$nombreSuc = [];
foreach ($sucursales as $s) $nombreSuc[(int) $s['id']] = $s['nombre'];

/* ---------- Catálogo ---------- */
$cond = ["p.activo = 1", "p.tipo = 'producto'"];
$par  = [];
if ($q !== '') { $cond[] = '(p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
if ($categoria > 0) { $cond[] = 'p.categoria_id = ?'; $par[] = $categoria; }

$productos = qAll(
    "SELECT p.id, p.codigo, p.nombre, p.stock_minimo, p.precio_compra,
            COALESCE(c.nombre,'Sin categoría') AS categoria
       FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE " . implode(' AND ', $cond) . "
      ORDER BY p.nombre", $par);

/* ---------- Existencias ---------- */
$stock = [];
foreach (qAll("SELECT producto_id, sucursal_id, cantidad FROM inventario_stock
                WHERE sucursal_id IN ($ph)", $ids) as $s) {
    $stock[(int) $s['producto_id']][(int) $s['sucursal_id']] = (float) $s['cantidad'];
}

/* ---------- Venta de la ventana, por producto y tienda ---------- */
// Criterio compartido: entra la venta «devuelta» y luego se resta lo devuelto.
// Sin netear, un artículo que se vendió y volvió entero pedirá reposición que
// nadie necesita.
$vendido = [];
foreach (qAll(
    "SELECT vd.producto_id, v.sucursal_id, COALESCE(SUM(vd.cantidad),0) u
       FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
      WHERE " . rep_estados_venta() . " AND vd.producto_id IS NOT NULL
        AND v.fecha BETWEEN ? AND ? AND v.sucursal_id IN ($ph)
      GROUP BY vd.producto_id, v.sucursal_id",
    array_merge([$desde . ' 00:00:00', $hasta . ' 23:59:59'], $ids)
) as $r) {
    $vendido[(int) $r['producto_id']][(int) $r['sucursal_id']] = (float) $r['u'];
}
foreach (qAll(
    "SELECT dd.producto_id, d.sucursal_id, COALESCE(SUM(dd.cantidad),0) u
       FROM devolucion_detalles dd
       JOIN devoluciones d ON d.id = dd.devolucion_id
      WHERE dd.producto_id IS NOT NULL
        AND d.created_at BETWEEN ? AND ? AND d.sucursal_id IN ($ph)
      GROUP BY dd.producto_id, d.sucursal_id",
    array_merge([$desde . ' 00:00:00', $hasta . ' 23:59:59'], $ids)
) as $r) {
    $pid = (int) $r['producto_id']; $sid = (int) $r['sucursal_id'];
    $vendido[$pid][$sid] = max(0.0, ($vendido[$pid][$sid] ?? 0.0) - (float) $r['u']);
}

/* ---------- El reparto ---------- */
$movimientos = [];      // los traslados propuestos
$comprar     = [];      // lo que no se resuelve moviendo
$conDeficit  = 0;
$totalMover  = 0.0; $valorMover = 0.0;
$totalComprar = 0.0; $valorComprar = 0.0;

foreach ($productos as $p) {
    $pid = (int) $p['id'];
    $min = (float) $p['stock_minimo'];
    $costo = (float) $p['precio_compra'];

    $deficit = []; $excedente = []; $huboMovimiento = false;
    foreach ($ids as $sid) {
        $ex  = $stock[$pid][$sid] ?? 0.0;
        $vta = $vendido[$pid][$sid] ?? 0.0;
        if ($vta > 0 || $ex > 0) $huboMovimiento = true;
        $diaria  = $vta / $ventana;
        $necesita = max($min, $diaria * $dias);
        // Se redondea hacia arriba: media unidad no se puede vender.
        $necesita = ceil($necesita * 1000) / 1000;

        $d = round($necesita - $ex, 3);
        if ($d > 0.0005)      $deficit[$sid]   = ['falta' => $d, 'ex' => $ex, 'diaria' => $diaria, 'necesita' => $necesita];
        elseif ($d < -0.0005) $excedente[$sid] = ['sobra' => -$d, 'ex' => $ex, 'diaria' => $diaria, 'necesita' => $necesita];
    }
    if ($soloConMovimiento && !$huboMovimiento) continue;
    if (!$deficit) continue;
    $conDeficit++;

    // El excedente más grande cubre primero el déficit más grande: así se hacen
    // menos traslados y más gordos, que es lo que se quiere mover.
    uasort($deficit,   fn($a, $b) => $b['falta'] <=> $a['falta']);
    uasort($excedente, fn($a, $b) => $b['sobra'] <=> $a['sobra']);

    foreach ($deficit as $destino => $d) {
        $falta = $d['falta'];
        foreach ($excedente as $origen => &$o) {
            if ($falta <= 0.0005 || $o['sobra'] <= 0.0005) continue;
            $mover = round(min($falta, $o['sobra']), 3);
            if ($mover <= 0.0005) continue;
            $o['sobra'] = round($o['sobra'] - $mover, 3);
            $falta      = round($falta - $mover, 3);
            $movimientos[] = [
                'producto' => $p['nombre'], 'codigo' => $p['codigo'], 'categoria' => $p['categoria'],
                'origen' => $origen, 'destino' => $destino, 'unidades' => $mover,
                'costo' => $costo, 'valor' => round($mover * $costo, 2),
                'ex_origen' => $o['ex'], 'ex_destino' => $d['ex'],
                'cobertura_destino' => $d['diaria'] > 0 ? round($d['ex'] / $d['diaria'], 1) : null,
            ];
            $totalMover += $mover; $valorMover += $mover * $costo;
        }
        unset($o);
        if ($falta > 0.0005) {
            $comprar[] = [
                'producto' => $p['nombre'], 'codigo' => $p['codigo'], 'categoria' => $p['categoria'],
                'destino' => $destino, 'unidades' => $falta,
                'costo' => $costo, 'valor' => round($falta * $costo, 2),
                'ex_destino' => $d['ex'], 'diaria' => $d['diaria'],
            ];
            $totalComprar += $falta; $valorComprar += $falta * $costo;
        }
    }
}

/* ---------- Agrupado por ruta, que es como se crean los traslados ---------- */
$rutas = [];
foreach ($movimientos as $m) {
    $k = $m['origen'] . '>' . $m['destino'];
    $rutas[$k] ??= ['origen' => $m['origen'], 'destino' => $m['destino'],
                    'articulos' => 0, 'unidades' => 0.0, 'valor' => 0.0, 'lineas' => []];
    $rutas[$k]['articulos']++;
    $rutas[$k]['unidades'] += $m['unidades'];
    $rutas[$k]['valor']    += $m['valor'];
    $rutas[$k]['lineas'][]  = $m;
}
uasort($rutas, fn($a, $b) => $b['valor'] <=> $a['valor']);

usort($comprar, fn($a, $b) => $b['valor'] <=> $a['valor']);

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $filas = [];
    foreach ($movimientos as $m) {
        $filas[] = ['MOVER', $m['codigo'], $m['producto'], $m['categoria'],
            $nombreSuc[$m['origen']] ?? '', $nombreSuc[$m['destino']] ?? '',
            qty($m['unidades']), money($m['valor'], false)];
    }
    foreach ($comprar as $c) {
        $filas[] = ['COMPRAR', $c['codigo'], $c['producto'], $c['categoria'],
            '', $nombreSuc[$c['destino']] ?? '', qty($c['unidades']), money($c['valor'], false)];
    }
    export_tabla('reposicion_' . $hasta,
        ['Acción', 'Código', 'Producto', 'Categoría', 'Sale de', 'Va a', 'Unidades', 'Valor a costo'],
        $filas, 'Sugerencias de reposición');
}

/* ---------- Pantalla ---------- */
layout_start('Qué mover y a dónde',
    'Cobertura objetivo ' . $dias . ' día(s) · venta medida sobre los últimos ' . $ventana . ' días',
    rep_barra_titulo());
echo rep_encabezado_impresion('Sugerencias de reposición', rep_periodo('mes'));
?>

<form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
  <div class="flex-1 min-w-[13rem]">
    <label class="label" for="q">Buscar</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" class="input" placeholder="Nombre o código…">
  </div>
  <div>
    <label class="label" for="categoria_id">Categoría</label>
    <select id="categoria_id" name="categoria_id" class="select">
      <option value="0">Todas</option>
      <?php foreach (qAll("SELECT id, nombre FROM categorias ORDER BY nombre") as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $categoria === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="label" for="cobertura">Días de cobertura</label>
    <input type="number" id="cobertura" name="cobertura" value="<?= $dias ?>" min="7" max="365" class="input w-28">
  </div>
  <div>
    <label class="label" for="ventana">Medir la venta sobre</label>
    <select id="ventana" name="ventana" class="select">
      <?php foreach ([30 => '30 días', 60 => '60 días', 90 => '90 días', 180 => '180 días'] as $v => $lbl): ?>
        <option value="<?= $v ?>" <?= $ventana === $v ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="label" for="todos">Qué artículos</label>
    <select id="todos" name="todos" class="select">
      <option value="0" <?= $soloConMovimiento ? 'selected' : '' ?>>Con existencia o venta</option>
      <option value="1" <?= $soloConMovimiento ? '' : 'selected' ?>>Todo el catálogo</option>
    </select>
  </div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Calcular</button>
</form>

<?= rep_kpis([
    ['label' => 'Artículos que faltan en alguna tienda', 'valor' => number_format($conDeficit),
     'icono' => 'alert', 'color' => $conDeficit > 0 ? 'amber' : 'emerald',
     'nota' => 'De ' . number_format(count($productos)) . ' del catálogo'],
    ['label' => 'Se resuelve moviendo', 'valor' => qty($totalMover) . ' u.',
     'icono' => 'transfer', 'color' => 'blue',
     'nota' => money($valorMover) . ' en ' . count($rutas) . ' ruta(s)'],
    ['label' => 'Hay que comprar', 'valor' => qty($totalComprar) . ' u.',
     'icono' => 'truck', 'color' => $totalComprar > 0 ? 'rose' : 'emerald',
     'nota' => $totalComprar > 0 ? money($valorComprar) . ' que no hay en ninguna tienda' : 'Todo se cubre moviendo'],
    ['label' => 'Tiendas comparadas', 'valor' => number_format(count($sucursales)),
     'icono' => 'store', 'color' => 'indigo', 'nota' => 'Las que puedes ver'],
], 4) ?>

<div class="card p-4 mb-5 flex items-start gap-3 bg-slate-50 border-slate-200 no-print">
  <?= icon('scale', 'w-5 h-5 text-slate-400 mt-0.5 shrink-0') ?>
  <p class="text-sm text-slate-600">
    Cada tienda «necesita» lo que sea mayor entre su <strong>stock mínimo</strong> y lo que va a vender
    en <strong><?= $dias ?> días</strong> al ritmo de los últimos <?= $ventana ?>. Lo que sobra en unas
    cubre lo que falta en otras, empezando por el excedente más grande. Lo que no alcanza sale abajo
    como compra: <strong>no se inventa existencia que no hay</strong>. La venta está neta de
    devoluciones, con el mismo criterio que el resto de los informes.
  </p>
</div>

<?php if (!$rutas && !$comprar): ?>
  <?php
  // «Nada que reponer» puede significar dos cosas muy distintas: que todo está
  // bien surtido, o que no hay con qué calcularlo. Decir la primera cuando pasa
  // la segunda es mentir con una cara tranquilizadora.
  $conMinimo = (int) qVal("SELECT COUNT(*) FROM productos WHERE activo = 1 AND tipo = 'producto' AND stock_minimo > 0");
  $conVenta  = 0;
  foreach ($vendido as $porSuc) foreach ($porSuc as $u) if ($u > 0) { $conVenta++; break 2; }
  ?>
  <?php if ($conMinimo === 0 && $conVenta === 0): ?>
    <div class="card p-4 mb-5 flex items-start gap-3 bg-amber-50 border-amber-200">
      <?= icon('alert', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0') ?>
      <div class="text-sm text-amber-900">
        <strong>No es que esté todo surtido: es que todavía no hay con qué calcularlo.</strong>
        <p class="mt-1 text-amber-800">
          Este informe necesita una de estas dos cosas para tener algo que decir:
          <strong>stock mínimo</strong> en la ficha de los productos, o
          <strong>ventas de los últimos <?= $ventana ?> días</strong> para medir a qué ritmo se vende cada
          tienda. Ahora mismo ningún artículo tiene mínimo y no hay ventas en la ventana, así que
          ninguna tienda «necesita» nada y no hay déficit que repartir.
        </p>
        <p class="mt-1 text-amber-800">
          En cuanto empiece a venderse, funciona solo. Poner el mínimo de los artículos que nunca
          pueden faltar lo hace útil desde el primer día.
        </p>
      </div>
    </div>
  <?php else: ?>
    <div class="card p-6"><?= empty_state('Nada que reponer',
        'Con la cobertura de ' . $dias . ' días, ninguna tienda se queda corta.', 'check') ?></div>
  <?php endif; ?>
<?php endif; ?>

<?php foreach ($rutas as $r): ?>
  <?= rep_seccion(
      e($nombreSuc[$r['origen']] ?? '?') . '  →  ' . e($nombreSuc[$r['destino']] ?? '?'),
      number_format($r['articulos']) . ' artículo(s) · ' . qty($r['unidades']) . ' unidades · '
        . money($r['valor']) . ' a costo',
      'transfer', 'blue',
      can('transferencias.crear')
        ? '<a href="' . e(url('modules/inventario/transferencias.php')) . '" class="btn btn-soft btn-sm no-print">'
          . icon('plus', 'w-3.5 h-3.5') . ' Crear el traslado</a>' : '') ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Producto</th>
          <th class="text-center">Tiene el origen</th>
          <th class="text-center">Tiene el destino</th>
          <th class="text-center">Le aguanta</th>
          <th class="text-center">Mover</th>
          <th class="text-right">Valor</th>
        </tr></thead>
        <tbody>
          <?php foreach ($r['lineas'] as $l): ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($l['producto']) ?></p>
                <p class="text-xs text-slate-400"><?= e($l['codigo']) ?> · <?= e($l['categoria']) ?></p>
              </td>
              <td class="text-center text-slate-600 tabular-nums"><?= qty($l['ex_origen']) ?></td>
              <td class="text-center tabular-nums <?= $l['ex_destino'] <= 0 ? 'text-rose-600 font-bold' : 'text-slate-600' ?>">
                <?= qty($l['ex_destino']) ?>
              </td>
              <td class="text-center text-sm">
                <?php if ($l['cobertura_destino'] === null): ?>
                  <span class="text-slate-300">no vende</span>
                <?php else: ?>
                  <span class="badge badge-<?= $l['cobertura_destino'] < 7 ? 'rose' : ($l['cobertura_destino'] < 21 ? 'amber' : 'slate') ?>">
                    <?= number_format($l['cobertura_destino'], 0) ?> d
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-center font-bold text-blue-700 tabular-nums"><?= qty($l['unidades']) ?></td>
              <td class="text-right text-slate-600 tabular-nums"><?= money($l['valor'], false) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?= rep_fin() ?>
<?php endforeach; ?>

<?php if ($comprar): ?>
  <?= rep_seccion('Lo que no se resuelve moviendo',
      'No hay excedente en ninguna tienda: hay que comprarlo', 'truck', 'rose',
      can('compras.crear')
        ? '<a href="' . e(url('modules/inventario/compras.php')) . '" class="btn btn-soft btn-sm no-print">'
          . icon('plus', 'w-3.5 h-3.5') . ' Registrar compra</a>' : '') ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Producto</th><th>Falta en</th>
          <th class="text-center">Tiene</th><th class="text-center">Vende al día</th>
          <th class="text-center">Comprar</th><th class="text-right">Valor a costo</th>
        </tr></thead>
        <tbody>
          <?php foreach ($comprar as $c): ?>
            <tr>
              <td>
                <p class="font-semibold text-slate-700"><?= e($c['producto']) ?></p>
                <p class="text-xs text-slate-400"><?= e($c['codigo']) ?> · <?= e($c['categoria']) ?></p>
              </td>
              <td class="text-slate-600 text-sm"><?= e($nombreSuc[$c['destino']] ?? '—') ?></td>
              <td class="text-center tabular-nums <?= $c['ex_destino'] <= 0 ? 'text-rose-600 font-bold' : 'text-slate-600' ?>">
                <?= qty($c['ex_destino']) ?>
              </td>
              <td class="text-center text-slate-500 tabular-nums">
                <?= $c['diaria'] > 0 ? number_format($c['diaria'], 2) : '—' ?>
              </td>
              <td class="text-center font-bold text-rose-700 tabular-nums"><?= qty($c['unidades']) ?></td>
              <td class="text-right text-slate-600 tabular-nums"><?= money($c['valor'], false) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-slate-50 font-bold text-slate-800">
            <td colspan="4">Total a comprar</td>
            <td class="text-center tabular-nums"><?= qty($totalComprar) ?></td>
            <td class="text-right tabular-nums"><?= money($valorComprar, false) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?= rep_fin() ?>
<?php endif; ?>

<?php layout_end(); ?>
