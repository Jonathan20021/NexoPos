<?php
/**
 * Existencias por tienda: el mismo artículo en todos los locales, lado a lado.
 *
 * El inventario valorizado dice cuánto dinero hay dormido; este dice DÓNDE está.
 * Quien reparte mercancía entre trece tiendas necesita ver la fila completa para
 * decidir de cuál sacar y a cuál mandar, y eso obligaba a abrir la pantalla de
 * existencias una vez por local y comparar de memoria.
 *
 * Por defecto solo salen los artículos que tienen algo o que están por debajo
 * del mínimo en alguna tienda: con 303 productos y la mayoría en cero, una
 * matriz completa es una pared de ceros donde no se ve nada.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_any_perm(['reportes.contabilidad', 'reportes.inventario']);

$p = rep_periodo('mes');   // el informe es una foto de hoy; el periodo solo viaja a los filtros

/* ---------- Qué tiendas entran ---------- */
$sucursales = array_values(array_filter(sucursales_visibles(),
    fn($s) => can_access_sucursal((int) $s['id'])));
$ids = array_map(fn($s) => (int) $s['id'], $sucursales) ?: [0];
$ph  = implode(',', array_fill(0, count($ids), '?'));

/* ---------- Filtros ---------- */
$q         = trim((string) get('q'));
$categoria = (int) get('categoria_id');
$vista     = in_array(get('vista'), ['movidos', 'bajo', 'agotados', 'todos'], true) ? get('vista') : 'movidos';

$cond = ["p.activo = 1", "p.tipo = 'producto'"];
$par  = [];
if ($q !== '') { $cond[] = '(p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
if ($categoria > 0) { $cond[] = 'p.categoria_id = ?'; $par[] = $categoria; }
$where = implode(' AND ', $cond);

/* ---------- La matriz ---------- */
$productos = qAll(
    "SELECT p.id, p.codigo, p.nombre, p.stock_minimo, p.precio_compra,
            COALESCE(c.nombre,'Sin categoría') AS categoria
       FROM productos p
       LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE $where
      ORDER BY c.nombre, p.nombre",
    $par
);

// Una sola consulta para todas las existencias: una por producto convertiría
// trescientas filas en trescientas consultas.
$matriz = [];
foreach (qAll(
    "SELECT s.producto_id, s.sucursal_id, s.cantidad
       FROM inventario_stock s
      WHERE s.sucursal_id IN ($ph) AND s.cantidad <> 0", $ids
) as $s) {
    $matriz[(int) $s['producto_id']][(int) $s['sucursal_id']] = (float) $s['cantidad'];
}

$filas = [];
$totalPorSuc = array_fill_keys($ids, 0.0);
$totalUnidades = 0.0; $totalCosto = 0.0; $conQuiebre = 0;

foreach ($productos as $pr) {
    $pid   = (int) $pr['id'];
    $porSuc = [];
    $suma   = 0.0;
    foreach ($ids as $sid) {
        $c = $matriz[$pid][$sid] ?? 0.0;
        $porSuc[$sid] = $c;
        $suma += $c;
    }
    $min      = (float) $pr['stock_minimo'];
    $enCero   = count(array_filter($porSuc, fn($c) => $c <= 0));
    $bajoMin  = $min > 0 && count(array_filter($porSuc, fn($c) => $c > 0 && $c < $min)) > 0;
    $quiebre  = $min > 0 && $enCero > 0;

    // El filtro de vista decide qué merece salir.
    $entra = match ($vista) {
        'bajo'     => $bajoMin || $quiebre,
        'agotados' => $suma <= 0,
        'todos'    => true,
        default    => $suma > 0 || $bajoMin || $quiebre,
    };
    if (!$entra) continue;

    foreach ($ids as $sid) $totalPorSuc[$sid] += $porSuc[$sid];
    $totalUnidades += $suma;
    $totalCosto    += $suma * (float) $pr['precio_compra'];
    if ($quiebre) $conQuiebre++;

    $filas[] = [
        'id' => $pid, 'codigo' => $pr['codigo'], 'nombre' => $pr['nombre'],
        'categoria' => $pr['categoria'], 'minimo' => $min, 'costo' => (float) $pr['precio_compra'],
        'por_suc' => $porSuc, 'total' => $suma, 'quiebre' => $quiebre, 'bajo' => $bajoMin,
    ];
}

/* ---------- Exportación ---------- */
if (export_solicitado()) {
    $cab = ['Código', 'Producto', 'Categoría', 'Mínimo'];
    foreach ($sucursales as $s) $cab[] = $s['nombre'];
    $cab[] = 'Total'; $cab[] = 'Valor a costo';

    $out = [];
    foreach ($filas as $f) {
        $fila = [$f['codigo'], $f['nombre'], $f['categoria'], qty($f['minimo'])];
        foreach ($ids as $sid) $fila[] = qty($f['por_suc'][$sid]);
        $fila[] = qty($f['total']);
        $fila[] = money($f['total'] * $f['costo'], false);
        $out[] = $fila;
    }
    export_tabla('existencias_por_tienda_' . date('Y-m-d'), $cab, $out, 'Existencias por tienda');
}

/* ---------- Pantalla ---------- */
layout_start('Existencias por tienda',
    'Foto de hoy · ' . count($filas) . ' artículo(s) en ' . count($sucursales) . ' tienda(s)',
    rep_barra_titulo());
echo rep_encabezado_impresion('Existencias por tienda', $p);
?>

<form method="get" class="card p-4 mb-5 flex items-end gap-3 flex-wrap no-print">
  <div class="flex-1 min-w-[14rem]">
    <label class="label" for="q">Buscar</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" class="input"
           placeholder="Nombre, código o código de barras...">
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
    <label class="label" for="vista">Qué enseñar</label>
    <select id="vista" name="vista" class="select">
      <?php foreach ([
          'movidos'  => 'Con existencia o en alerta',
          'bajo'     => 'Solo lo que está bajo el mínimo',
          'agotados' => 'Solo lo agotado en todas',
          'todos'    => 'Todo el catálogo',
      ] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $vista === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
</form>

<?= rep_kpis([
    ['label' => 'Unidades en total', 'valor' => qty($totalUnidades), 'icono' => 'layers', 'color' => 'blue',
     'nota' => count($filas) . ' artículo(s) listado(s)'],
    ['label' => 'Valor a costo', 'valor' => money($totalCosto), 'icono' => 'coins', 'color' => 'emerald',
     'nota' => 'De lo que se está listando'],
    ['label' => 'Con quiebre en alguna tienda', 'valor' => number_format($conQuiebre),
     'icono' => 'alert', 'color' => $conQuiebre > 0 ? 'rose' : 'emerald',
     'nota' => $conQuiebre > 0 ? 'Están en cero donde deberían tener mínimo' : 'Ninguno bajo mínimo'],
    ['label' => 'Tiendas comparadas', 'valor' => number_format(count($sucursales)), 'icono' => 'store',
     'color' => 'indigo', 'nota' => 'Las que puedes ver'],
], 4) ?>

<?= rep_seccion('El mismo artículo en cada tienda',
    'En rojo lo que está en cero teniendo mínimo; en ámbar lo que va por debajo', 'layers', 'blue') ?>
  <div class="overflow-x-auto">
    <table class="data-table">
      <thead>
        <tr>
          <!-- La columna del producto va FIJA: con doce tiendas la tabla se
               desplaza, y al llegar a la derecha se perdía de vista de qué
               artículo era cada fila. Una matriz sin su primera columna no se
               puede leer. -->
          <!-- El fondo va con «!» porque .data-table thead th lo pinta al 60% de
 opacidad: en una columna fija eso deja ver el texto que pasa por debajo. -->
          <th class="min-w-[15rem] sticky left-0 z-20 !bg-slate-100">Producto</th>
          <th class="text-center">Mínimo</th>
          <?php foreach ($sucursales as $s): ?>
            <th class="text-center whitespace-nowrap"><?= e($s['nombre']) ?></th>
          <?php endforeach; ?>
          <th class="text-center">Total</th>
          <th class="text-right">Valor</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$filas): ?>
          <tr><td colspan="<?= count($sucursales) + 4 ?>" class="text-center text-slate-400 py-8">
            Ningún artículo coincide con el filtro.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($filas as $f): ?>
          <tr>
            <td class="sticky left-0 z-10 !bg-white">
              <p class="font-semibold text-slate-700"><?= e($f['nombre']) ?></p>
              <p class="text-xs text-slate-400"><?= e($f['codigo']) ?> · <?= e($f['categoria']) ?></p>
            </td>
            <td class="text-center text-slate-500"><?= $f['minimo'] > 0 ? qty($f['minimo']) : '—' ?></td>
            <?php foreach ($ids as $sid): $c = $f['por_suc'][$sid];
                $clase = 'text-slate-300';
                if ($c > 0) $clase = ($f['minimo'] > 0 && $c < $f['minimo']) ? 'text-amber-600 font-semibold' : 'text-slate-700 font-medium';
                elseif ($f['minimo'] > 0) $clase = 'text-rose-600 font-bold'; ?>
              <td class="text-center tabular-nums <?= $clase ?>"><?= $c != 0 ? qty($c) : '0' ?></td>
            <?php endforeach; ?>
            <td class="text-center font-bold text-slate-800 tabular-nums"><?= qty($f['total']) ?></td>
            <td class="text-right text-slate-600 tabular-nums"><?= money($f['total'] * $f['costo'], false) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($filas): ?>
        <tfoot>
          <tr class="bg-slate-50 font-bold text-slate-800">
            <td colspan="2" class="sticky left-0 z-10 !bg-slate-100">Total por tienda</td>
            <?php foreach ($ids as $sid): ?>
              <td class="text-center tabular-nums"><?= qty($totalPorSuc[$sid]) ?></td>
            <?php endforeach; ?>
            <td class="text-center tabular-nums"><?= qty($totalUnidades) ?></td>
            <td class="text-right tabular-nums"><?= money($totalCosto, false) ?></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
<?= rep_fin() ?>

<?php layout_end(); ?>
