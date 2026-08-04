<?php
/**
 * Etiquetas de producto con código de barras.
 *
 * Dos trabajos en una pantalla:
 *   1. Asignar código de barras interno a la mercancía que no trae uno de fábrica
 *      (a granel, importación sin etiquetar, producción propia).
 *   2. Imprimir las etiquetas, en rollo térmico o en hoja adhesiva A4.
 *
 * Las barras se dibujan en SVG (`includes/barcode.php`), no como imagen: una
 * etiqueta de 38 mm impresa desde un PNG sale con los bordes difuminados y el
 * lector falla una de cada tres veces. El vector se imprime nítido a cualquier
 * tamaño y en cualquier impresora.
 *
 * Los tamaños están en milímetros de verdad (`@page` + unidades `mm`), así que lo
 * que sale de la impresora mide lo que dice el nombre y calza en el adhesivo.
 *
 * La hoja se imprime como DOCUMENTO APARTE (`?vista=hoja`), no ocultando el resto
 * de la aplicación con CSS: el contenido va anidado dentro del armazón (barra
 * lateral, cabecera, main…) y "esconder todo menos esto" es frágil y además
 * arrastra los márgenes de `@page` del layout, que arruinan una etiqueta de 38 mm.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('productos.etiquetas');

/* ============================================================
 *  Formatos de etiqueta
 *  `mod` es el ancho de la barra fina en mm: por debajo de 0,25 mm hay lectores
 *  de gama baja que ya no leen, así que se sube en las etiquetas grandes.
 * ============================================================ */
$formatos = [
    'r38' => ['nombre' => 'Rollo térmico 38 × 25 mm', 'w' => 38, 'h' => 25, 'mod' => 0.26, 'alto' => 10, 'nom' => 6.2, 'precio' => 8.5],
    'r50' => ['nombre' => 'Rollo térmico 50 × 25 mm', 'w' => 50, 'h' => 25, 'mod' => 0.30, 'alto' => 10, 'nom' => 6.6, 'precio' => 9],
    'r57' => ['nombre' => 'Rollo térmico 57 × 32 mm', 'w' => 57, 'h' => 32, 'mod' => 0.33, 'alto' => 13, 'nom' => 7.2, 'precio' => 11],
    'a4'  => ['nombre' => 'Hoja A4 adhesiva · 3 × 8 (70 × 37 mm)', 'w' => 70, 'h' => 37, 'mod' => 0.36, 'alto' => 15, 'nom' => 8.2, 'precio' => 12, 'hoja' => true],
];
// is_string antes de indexar: un `?formato[]=x` haría que la clave fuera un array.
$fmtPedido = $_REQUEST['formato'] ?? '';
$fmtKey = (is_string($fmtPedido) && isset($formatos[$fmtPedido])) ? $fmtPedido : 'r38';
$f = $formatos[$fmtKey];

/* ============================================================
 *  Vista de impresión — documento independiente, sin el armazón de la app
 * ============================================================ */
if (($_REQUEST['vista'] ?? '') === 'hoja') {
    $cant = $_POST['cant'] ?? $_GET['cant'] ?? [];
    if (!is_array($cant)) $cant = [];

    // Se normaliza y se tope: nadie necesita 10.000 etiquetas de un producto y un
    // número disparatado (o negativo) solo sirve para tumbar el navegador.
    $pedido = [];
    foreach ($cant as $id => $n) {
        $id = (int) $id;
        $n  = (int) $n;
        if ($id > 0 && $n > 0) $pedido[$id] = min($n, 500);
    }
    if (!$pedido) { flash('warning', 'No seleccionaste ninguna etiqueta.'); redirect('modules/inventario/etiquetas.php'); }

    $ids = array_keys($pedido);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $rows = qAll(
        "SELECT p.id, p.codigo, p.codigo_barras, p.nombre, p.precio_venta, m.nombre AS marca
           FROM productos p LEFT JOIN marcas m ON m.id = p.marca_id
          WHERE p.id IN ($marcas) AND p.codigo_barras IS NOT NULL AND p.codigo_barras <> ''
          ORDER BY p.nombre",
        $ids
    );

    $camposIn = $_REQUEST['campos'] ?? 'empresa,nombre,sku,precio';
    $campos = array_flip(explode(',', is_string($camposIn) ? $camposIn : ''));
    $empresa = $GLOBALS['empresa']['nombre'] ?? APP_NAME;

    // 1 mm ≈ 3.7795 px: el SVG se dibuja en px y se coloca en una caja en mm.
    $px = 3.7795;
    ?><!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Etiquetas · <?= e($empresa) ?></title>
      <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; font-family: Arial, Helvetica, sans-serif; color: #000; }
        .barra { position: sticky; top: 0; z-index: 10; background: #1e293b; color: #fff; padding: .75rem 1rem;
                 display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .barra p { margin: 0; font-size: .875rem; flex: 1; min-width: 200px; }
        .barra button, .barra a { background: #2563eb; color: #fff; border: 0; border-radius: .6rem; padding: .55rem 1rem;
                 font: inherit; font-size: .85rem; font-weight: 700; cursor: pointer; text-decoration: none; }
        .barra a { background: rgba(255,255,255,.15); }
        .hoja { display: flex; flex-wrap: wrap; gap: 0; }

        .etq { width: <?= $f['w'] ?>mm; height: <?= $f['h'] ?>mm; padding: 1.2mm 1.5mm;
               display: flex; flex-direction: column; align-items: center; justify-content: space-between;
               overflow: hidden; background: #fff; text-align: center;
               page-break-inside: avoid; break-inside: avoid; }
        .etq-empresa { font-size: <?= round($f['nom'] * 0.72, 2) ?>pt; text-transform: uppercase; color: #333;
                       width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .etq-nombre  { font-size: <?= $f['nom'] ?>pt; font-weight: 700; line-height: 1.1; width: 100%;
                       display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .etq-barras  { flex: 1; min-height: 0; width: 100%; display: flex; align-items: center; justify-content: center; }
        .etq-barras svg { max-width: 100%; height: auto; }
        .etq-pie     { width: 100%; display: flex; align-items: baseline; justify-content: space-between; gap: 1mm; }
        .etq-sku     { font-size: <?= round($f['nom'] * 0.75, 2) ?>pt; color: #333; font-family: monospace; }
        .etq-precio  { font-size: <?= $f['precio'] ?>pt; font-weight: 800; white-space: nowrap; }

        /* En pantalla se ven separadas y con guía, para revisarlas antes de gastar rollo. */
        @media screen {
          .hoja { gap: 4mm; padding: 1.5rem; }
          .etq { outline: 1px dashed #94a3b8; }
        }
        @media print {
          body { background: #fff; }
          .barra { display: none !important; }
          .hoja { gap: 0; padding: 0; }
          .etq { outline: 0; }
          @page {
            size: <?= !empty($f['hoja']) ? 'A4' : $f['w'] . 'mm ' . $f['h'] . 'mm' ?>;
            margin: <?= !empty($f['hoja']) ? '8mm 5mm' : '0' ?>;
          }
        }
      </style>
    </head>
    <body>
      <div class="barra">
        <p>
          <strong><?= number_format(array_sum(array_intersect_key($pedido, array_flip(array_column($rows, 'id'))))) ?></strong>
          etiqueta(s) · <?= e($f['nombre']) ?>.
          <?= !empty($f['hoja'])
              ? 'Elige tamaño A4 y márgenes «predeterminados».'
              : 'Elige tu impresora de etiquetas y márgenes «ninguno».' ?>
        </p>
        <button type="button" onclick="window.print()">Imprimir</button>
        <a href="<?= e(url('modules/inventario/etiquetas.php')) ?>">Volver</a>
      </div>

      <div class="hoja">
        <?php foreach ($rows as $p):
          $svg = barcode_svg($p['codigo_barras'], [
              'alto'   => $f['alto'] * $px,
              'modulo' => $f['mod'] * $px,
              'texto'  => true,
          ]);
          if ($svg === '') continue;
          $veces = $pedido[(int) $p['id']] ?? 0;
          for ($i = 0; $i < $veces; $i++): ?>
            <div class="etq">
              <?php if (isset($campos['empresa'])): ?><div class="etq-empresa"><?= e($empresa) ?></div><?php endif; ?>
              <?php if (isset($campos['nombre'])): ?><div class="etq-nombre"><?= e($p['nombre']) ?></div><?php endif; ?>
              <div class="etq-barras"><?= $svg ?></div>
              <div class="etq-pie">
                <span class="etq-sku"><?= isset($campos['sku']) ? e($p['codigo']) : '' ?></span>
                <span class="etq-precio"><?= isset($campos['precio']) ? money($p['precio_venta']) : '' ?></span>
              </div>
            </div>
          <?php endfor;
        endforeach; ?>
      </div>

      <script>
        // Se espera a que el navegador maquete los SVG antes de abrir el diálogo:
        // si se llama antes, la primera etiqueta sale medida a ojo.
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
      </script>
    </body>
    </html>
    <?php
    exit;
}

/* ============================================================
 *  Acciones
 * ============================================================ */
if (isPost()) {
    verify_csrf();

    /* ---------- Asignar códigos internos a los que no tienen ---------- */
    if (post('accion') === 'generar_faltantes') {
        require_perm('productos.editar');
        try {
            $ids = $_POST['ids'] ?? [];
            $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];

            $generados = 0;
            if ($ids) {
                // En orden de id: si dos personas hacen esto a la vez, ambas recorren
                // la lista igual y no se produce interbloqueo (docs/CONCURRENCIA.md).
                sort($ids, SORT_NUMERIC);
                $generados = tx(function () use ($ids) {
                    $n = 0;
                    foreach ($ids as $id) {
                        // Se relee dentro de la transacción: si otro usuario ya le puso
                        // código mientras esta pantalla estaba abierta, no se le pisa.
                        $p = qOne("SELECT id, tipo, codigo_barras FROM productos WHERE id = ? FOR UPDATE", [$id]);
                        if (!$p || $p['tipo'] !== 'producto') continue;
                        if (trim((string) $p['codigo_barras']) !== '') continue;
                        dbUpdate('productos', ['codigo_barras' => barcode_generar_interno()], 'id = ?', [$id]);
                        $n++;
                    }
                    return $n;
                });
            }

            if ($generados > 0) {
                audit('productos', 'editar', "Códigos de barras internos generados: $generados producto(s)");
                flash('success', $generados . ' producto(s) recibieron su código de barras interno (EAN-13, prefijo 200). Ya se pueden escanear e imprimir.');
            } else {
                flash('info', 'No hubo nada que asignar: todos los productos de la lista ya tenían código.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/etiquetas.php?' . http_build_query(array_intersect_key($_GET, ['q' => 1, 'categoria_id' => 1, 'filtro' => 1, 'formato' => 1])));
    }
}

/* ============================================================
 *  Datos de la pantalla
 * ============================================================ */
$q         = trim((string) get('q'));
$catFiltro = (int) get('categoria_id');
$filtro    = in_array(get('filtro'), ['sin_codigo', 'con_codigo'], true) ? get('filtro') : '';

$cond   = ["p.activo = 1", "p.tipo = 'producto'"];
$params = [];
if ($q !== '')      { $cond[] = "(p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($catFiltro > 0) { $cond[] = "p.categoria_id = ?"; $params[] = $catFiltro; }
if ($filtro === 'sin_codigo') $cond[] = "(p.codigo_barras IS NULL OR p.codigo_barras = '')";
if ($filtro === 'con_codigo') $cond[] = "(p.codigo_barras IS NOT NULL AND p.codigo_barras <> '')";
$where = 'WHERE ' . implode(' AND ', $cond);

$sid = current_sucursal_id();
$stockExpr = $sid === null
    ? "(SELECT COALESCE(SUM(cantidad),0) FROM inventario_stock WHERE producto_id = p.id)"
    : "(SELECT COALESCE(SUM(cantidad),0) FROM inventario_stock WHERE producto_id = p.id AND sucursal_id = " . (int) $sid . ")";

$TOPE = 300;
$total = (int) qVal("SELECT COUNT(*) FROM productos p $where", $params);
$productos = qAll(
    "SELECT p.id, p.codigo, p.codigo_barras, p.nombre, p.precio_venta, m.nombre AS marca,
            u.abreviatura AS unidad, $stockExpr AS stock
       FROM productos p
       LEFT JOIN marcas m ON m.id = p.marca_id
       LEFT JOIN unidades u ON u.id = p.unidad_id
     $where ORDER BY p.nombre LIMIT $TOPE",
    $params
);

$categorias   = qAll("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre");
$sinCodigo    = (int) qVal("SELECT COUNT(*) FROM productos WHERE activo = 1 AND tipo = 'producto' AND (codigo_barras IS NULL OR codigo_barras = '')");
$idsSinCodigo = array_values(array_filter(array_map(
    fn($p) => trim((string) $p['codigo_barras']) === '' ? (int) $p['id'] : null, $productos)));

$acciones = '<a href="' . e(url('modules/inventario/productos.php')) . '" class="btn btn-ghost">'
    . icon('box', 'w-4 h-4') . ' Productos</a>';

layout_start('Etiquetas con código de barras',
    'Asigna códigos internos e imprime las etiquetas de la mercancía', $acciones);
?>

<!-- ============ Productos sin código ============ -->
<?php if ($sinCodigo > 0): ?>
  <div class="card p-4 mb-5 flex flex-col sm:flex-row sm:items-center gap-3 border-amber-200 bg-amber-50/50">
    <span class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0"><?= icon('barcode', 'w-5 h-5') ?></span>
    <div class="flex-1 min-w-0">
      <h3 class="font-bold text-slate-800 text-sm"><?= number_format($sinCodigo) ?> producto(s) activos sin código de barras</h3>
      <p class="text-sm text-slate-600 mt-0.5">
        Sin código no se pueden escanear en la caja ni en el almacén. El sistema les asigna un
        <strong>EAN-13 válido con prefijo 200</strong>, el rango que el estándar reserva para uso interno de
        una empresa: nunca chocará con el código de un fabricante.
      </p>
    </div>
    <?php if ($idsSinCodigo && can('productos.editar')): ?>
      <form method="post" class="shrink-0"
            onsubmit="return confirm('¿Asignar código de barras interno a <?= count($idsSinCodigo) ?> producto(s) de esta lista?')">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="generar_faltantes">
        <?php foreach ($idsSinCodigo as $id): ?><input type="hidden" name="ids[]" value="<?= (int) $id ?>"><?php endforeach; ?>
        <button class="btn btn-primary btn-sm"><?= icon('barcode', 'w-3.5 h-3.5') ?> Asignar a los <?= count($idsSinCodigo) ?> de esta lista</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ============ Filtros ============ -->
<div class="card p-4 mb-5">
  <form method="get" class="flex flex-wrap items-end gap-3">
    <div>
      <label class="label">Buscar</label>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><?= icon('search', 'w-4 h-4') ?></span>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre, SKU o código…" class="input pl-10 min-w-[220px]">
      </div>
    </div>
    <div>
      <label class="label">Categoría</label>
      <select name="categoria_id" class="select w-48">
        <option value="0">Todas</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $catFiltro === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">Mostrar</label>
      <select name="filtro" class="select w-44">
        <option value="">Todos</option>
        <option value="sin_codigo" <?= $filtro === 'sin_codigo' ? 'selected' : '' ?>>Solo sin código</option>
        <option value="con_codigo" <?= $filtro === 'con_codigo' ? 'selected' : '' ?>>Solo con código</option>
      </select>
    </div>
    <input type="hidden" name="formato" value="<?= e($fmtKey) ?>">
    <button class="btn btn-ghost"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
  </form>
</div>

<!-- ============ Selección e impresión ============ -->
<form method="post" action="<?= e(url('modules/inventario/etiquetas.php')) ?>" target="_blank"
      x-data="etiquetas()">
  <input type="hidden" name="vista" value="hoja">
  <input type="hidden" name="campos" :value="campos">

  <div class="card p-4 mb-5">
    <div class="flex flex-wrap items-end gap-4">
      <div>
        <label class="label">Formato de etiqueta</label>
        <select name="formato" class="select w-72">
          <?php foreach ($formatos as $k => $ff): ?>
            <option value="<?= e($k) ?>" <?= $fmtKey === $k ? 'selected' : '' ?>><?= e($ff['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex flex-wrap items-center gap-4 pb-1">
        <span class="text-sm font-semibold text-slate-600">Qué lleva:</span>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" x-model="ver.nombre" class="rounded border-slate-300 text-blue-600"> Nombre</label>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" x-model="ver.precio" class="rounded border-slate-300 text-blue-600"> Precio</label>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" x-model="ver.sku" class="rounded border-slate-300 text-blue-600"> SKU</label>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" x-model="ver.empresa" class="rounded border-slate-300 text-blue-600"> Empresa</label>
      </div>
    </div>
  </div>

  <div class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" @click="fijar(1)" class="btn btn-ghost btn-sm">1 por producto</button>
        <button type="button" @click="fijar(0)" class="btn btn-ghost btn-sm">Ninguna</button>
        <button type="button" @click="porStock()" class="btn btn-ghost btn-sm"
                title="Pone en cada producto tantas etiquetas como unidades hay en existencia">
          <?= icon('layers', 'w-3.5 h-3.5') ?> Cantidad = existencia
        </button>
      </div>
      <p class="text-sm text-slate-500">
        <span class="font-bold text-slate-700" x-text="total"></span> etiqueta(s) de
        <span class="font-bold text-slate-700" x-text="productos"></span> producto(s)
      </p>
    </div>

    <?php if (!$productos): ?>
      <?= empty_state('Sin productos', 'Ajusta la búsqueda o los filtros.', 'search') ?>
    <?php else: ?>
      <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
        <table class="data-table">
          <thead><tr>
            <th>Producto</th>
            <th>Código de barras</th>
            <th class="text-center">Vista previa</th>
            <th class="text-right">Precio</th>
            <th class="text-center">Existencia</th>
            <th class="text-center w-28">Etiquetas</th>
          </tr></thead>
          <tbody>
            <?php foreach ($productos as $p):
              $cod = trim((string) $p['codigo_barras']);
              $tieneCodigo = $cod !== '';
              $tipoCod = $tieneCodigo ? barcode_tipo($cod) : '';
            ?>
              <tr>
                <td>
                  <span class="font-semibold text-slate-700"><?= e($p['nombre']) ?></span>
                  <span class="block text-[11.5px] text-slate-400"><?= e($p['codigo']) ?><?= $p['marca'] ? ' · ' . e($p['marca']) : '' ?></span>
                </td>
                <td>
                  <?php if ($tieneCodigo): ?>
                    <span class="font-mono text-[12.5px] text-slate-600"><?= e($cod) ?></span>
                    <span class="badge <?= barcode_es_interno($cod) ? 'badge-slate' : 'badge-sky' ?> ml-1"
                          title="<?= barcode_es_interno($cod) ? 'Código interno generado por el sistema' : 'Código del fabricante' ?>">
                      <?= e(barcode_tipo_label($tipoCod)) ?>
                    </span>
                  <?php else: ?>
                    <span class="badge badge-amber">Sin código</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($tieneCodigo): ?>
                    <div class="inline-block bg-white rounded border border-slate-200 p-1 leading-none">
                      <?= barcode_svg($cod, ['alto' => 22, 'modulo' => 1, 'texto' => false]) ?>
                    </div>
                  <?php else: ?>
                    <span class="text-slate-300 text-xs">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-right font-semibold text-slate-700"><?= money($p['precio_venta']) ?></td>
                <td class="text-center text-slate-500 tabular-nums"><?= qty($p['stock']) ?> <?= e($p['unidad'] ?: 'u') ?></td>
                <td class="text-center">
                  <input type="number" min="0" max="500" step="1" name="cant[<?= (int) $p['id'] ?>]" value="0"
                         data-etq data-stock="<?= (float) $p['stock'] ?>"
                         @input="recontar()"
                         aria-label="Etiquetas de <?= e($p['nombre']) ?>"
                         class="input py-1.5 px-2 text-center w-24 tabular-nums"
                         <?= $tieneCodigo ? '' : 'disabled title="Este producto no tiene código de barras"' ?>>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total > $TOPE): ?>
        <p class="px-4 py-2 text-xs text-amber-700 bg-amber-50 border-t border-amber-200">
          Hay <?= number_format($total) ?> productos y se muestran los primeros <?= $TOPE ?>.
          Filtra por categoría o busca para llegar al resto.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="flex flex-wrap items-center gap-3 mb-6">
    <button type="submit" class="btn btn-primary" :disabled="total === 0">
      <?= icon('print', 'w-4 h-4') ?> Generar e imprimir
    </button>
    <p class="text-sm text-slate-500">
      Se abre en una pestaña nueva con la vista previa; desde ahí sale el diálogo de impresión.
    </p>
  </div>
</form>

<script>
function etiquetas() {
  return {
    total: 0, productos: 0,
    ver: { nombre: true, precio: true, sku: true, empresa: true },

    init() { this.recontar(); },

    get campos() {
      return Object.keys(this.ver).filter(k => this.ver[k]).join(',');
    },

    recontar() {
      let t = 0, p = 0;
      this.cajas().forEach(inp => {
        const n = Math.max(0, Math.min(500, parseInt(inp.value, 10) || 0));
        if (n > 0) { t += n; p++; }
      });
      this.total = t;
      this.productos = p;
    },

    cajas() {
      return Array.from(document.querySelectorAll('input[data-etq]:not([disabled])'));
    },

    fijar(n) {
      this.cajas().forEach(inp => { inp.value = n; });
      this.recontar();
    },

    porStock() {
      this.cajas().forEach(inp => {
        // Una etiqueta por unidad en existencia; sin existencia, ninguna.
        inp.value = Math.max(0, Math.min(500, Math.round(parseFloat(inp.dataset.stock) || 0)));
      });
      this.recontar();
    },
  };
}
</script>

<?php layout_end(); ?>
