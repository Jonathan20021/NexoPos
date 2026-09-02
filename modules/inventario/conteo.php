<?php
/**
 * Detalle de un conteo físico: capturar cantidades, ver diferencias y aplicar.
 *
 * Al aplicar se mueve el stock por la DIFERENCIA encontrada (contado − teórico),
 * no por el número absoluto contado. Es lo correcto cuando la tienda siguió
 * vendiendo durante el conteo: si se forzara el valor absoluto, las ventas
 * hechas mientras se contaba se borrarían del inventario.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('conteos.ver');

$id = (int) get('id') ?: postInt('id');
$c  = qOne(
    "SELECT c.*, su.nombre AS sucursal, cat.nombre AS categoria,
            CONCAT(u.nombre,' ',u.apellido) AS abierto_por,
            CONCAT(ua.nombre,' ',ua.apellido) AS aplicado_por_nombre
       FROM conteos c
       JOIN sucursales su ON su.id = c.sucursal_id
       LEFT JOIN categorias cat ON cat.id = c.categoria_id
       LEFT JOIN usuarios u  ON u.id  = c.usuario_id
       LEFT JOIN usuarios ua ON ua.id = c.aplicado_por
      WHERE c.id = ?",
    [$id]
);
if (!$c) { flash('error', 'Conteo no encontrado.'); redirect('modules/inventario/conteos.php'); }
require_sucursal_access((int) $c['sucursal_id']);
$volver = 'modules/inventario/conteo.php?id=' . $id;

/* ============================================================
 *  Acciones
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    $accion = post('accion');

    /* ---------- Guardar cantidades contadas ---------- */
    if ($accion === 'contar') {
        require_perm('conteos.contar');
        try {
            if ($c['estado'] !== 'abierto') throw new RuntimeException('Este conteo ya no admite cambios.');
            $cont = $_POST['cont'] ?? [];
            if (!is_array($cont)) $cont = [];

            $guardados = txReintentable(function () use ($cont, $id) {
                $uid = current_user()['id'] ?? null;
                $n = 0;
                // Ordenado por id para que dos capturas simultáneas no se crucen.
                ksort($cont, SORT_NUMERIC);
                foreach ($cont as $detId => $valor) {
                    $detId = (int) $detId;
                    $txt = trim((string) $valor);
                    if ($detId <= 0) continue;
                    if ($txt === '') {
                        // Vaciar el campo devuelve la línea a «sin contar».
                        $n += dbUpdate('conteo_detalles',
                            ['stock_contado' => null, 'contado_por' => null, 'contado_at' => null],
                            'id = ? AND conteo_id = ?', [$detId, $id]);
                        continue;
                    }
                    $cant = (float) str_replace(',', '', $txt);
                    if ($cant < 0) throw new RuntimeException('Una cantidad contada no puede ser negativa.');
                    $n += dbUpdate('conteo_detalles',
                        ['stock_contado' => round($cant, 3), 'contado_por' => $uid, 'contado_at' => date('Y-m-d H:i:s')],
                        'id = ? AND conteo_id = ?', [$detId, $id]);
                }
                return $n;
            });

            flash('success', $guardados > 0 ? $guardados . ' línea(s) guardadas.' : 'No hubo cambios que guardar.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver . '&' . http_build_query(array_intersect_key($_GET, ['q' => 1, 'filtro' => 1, 'p' => 1])));
    }

    /* ---------- Aplicar los ajustes al stock ---------- */
    if ($accion === 'aplicar') {
        require_perm('conteos.aplicar');
        try {
            if ($c['estado'] !== 'abierto') throw new RuntimeException('Este conteo ya fue cerrado.');

            // La dirección exige una nota que explique cualquier reducción del
            // inventario. El ajuste a mano ya la pedía; el conteo no, y era el
            // camino por el que veinte unidades pasaban a quince sin que nadie
            // dijera qué había pasado. Se valida DENTRO de la transacción,
            // contra las líneas reales, porque entre abrir el modal y pulsar
            // aplicar alguien pudo cambiar lo contado.
            $justificacion = mb_substr(trim((string) post('justificacion')), 0, 300);

            $resumen = txReintentable(function () use ($id, $justificacion) {
                $conteo = qOne("SELECT * FROM conteos WHERE id = ? FOR UPDATE", [$id]);
                if (!$conteo || $conteo['estado'] !== 'abierto') {
                    throw new RuntimeException('Otro usuario acaba de cerrar este conteo.');
                }
                $sucursalId = (int) $conteo['sucursal_id'];

                // Solo las líneas contadas CON diferencia. El orden por
                // producto_id evita interbloqueos (ver docs/CONCURRENCIA.md).
                $lineas = qAll(
                    "SELECT d.*, p.nombre
                       FROM conteo_detalles d JOIN productos p ON p.id = d.producto_id
                      WHERE d.conteo_id = ? AND d.stock_contado IS NOT NULL
                        AND ABS(d.stock_contado - d.stock_teorico) > 0.0001
                      ORDER BY d.producto_id",
                    [$id]
                );

                // ¿Este conteo va a bajar existencia? Si sí, sin explicación no se aplica.
                $baja = false;
                foreach ($lineas as $l) {
                    if ((float) $l['stock_contado'] - (float) $l['stock_teorico'] < -0.0001) { $baja = true; break; }
                }
                if ($baja && $justificacion === '') {
                    throw new RuntimeException('Este conteo reduce el inventario. Escribe una nota que explique '
                        . 'por qué falta esa mercancía antes de aplicarlo.');
                }

                $ajustados = 0; $sobrante = 0.0; $faltante = 0.0; $omitidos = []; $sinLote = [];
                foreach ($lineas as $l) {
                    $delta = round((float) $l['stock_contado'] - (float) $l['stock_teorico'], 3);
                    if (abs($delta) < 0.0001) continue;

                    // El stock pudo moverse durante el conteo; se aplica la
                    // diferencia sobre la existencia actual, no el absoluto.
                    $actual = stockActual((int) $l['producto_id'], $sucursalId);
                    if ($actual + $delta < 0) {
                        // Bajar tanto dejaría el inventario en negativo: se salta
                        // esa línea y se avisa, en vez de tumbar todo el conteo.
                        $omitidos[] = $l['nombre'];
                        continue;
                    }
                    // Un conteo no pregunta el número de lote, así que las unidades
                    // que APARECEN entran al lote «sin identificar». No se pierden ni
                    // se venden antes de tiempo —FEFO deja lo que no tiene fecha para
                    // el final— pero un producto regulado sin lote es un problema de
                    // trazabilidad, y quien contó tiene la caja delante ahora mismo.
                    // Por eso se le dice al terminar, con nombre y sitio donde
                    // corregirlo, en vez de dejarlo callado en la base.
                    if ($delta > 0 && function_exists('san_controla_lote')
                        && san_controla_lote((int) $l['producto_id'])) {
                        $sinLote[] = $l['nombre'];
                    }
                    ajustarStock((int) $l['producto_id'], $sucursalId, $delta, 'ajuste', 'conteo', $id,
                        (float) $l['costo_unitario'],
                        // El número solo dice de dónde salió; la nota dice qué pasó.
                        // Los dos juntos son lo que lee el informe de ajustes y mermas.
                        'Conteo ' . $conteo['numero'] . ($justificacion !== '' ? ' · ' . $justificacion : ''));
                    $ajustados++;
                    $valor = $delta * (float) $l['costo_unitario'];
                    if ($delta > 0) $sobrante += $valor; else $faltante += $valor;
                }

                dbUpdate('conteos', [
                    'estado' => 'aplicado',
                    'justificacion' => $justificacion ?: null,
                    'aplicado_por' => current_user()['id'] ?? null,
                    'aplicado_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);

                return ['ajustados' => $ajustados, 'sobrante' => $sobrante, 'faltante' => $faltante,
                        'omitidos' => $omitidos, 'sin_lote' => $sinLote];
            });

            audit('conteos', 'aplicar', 'Conteo ' . $c['numero'] . ' aplicado: ' . $resumen['ajustados'] . ' ajuste(s)',
                ['tabla' => 'conteos', 'registro_id' => $id]);

            flash('success', 'Conteo aplicado: ' . $resumen['ajustados'] . ' producto(s) ajustados. '
                . 'Sobrantes ' . money($resumen['sobrante']) . ' · Faltantes ' . money(abs($resumen['faltante'])) . '.');
            if (!empty($resumen['sin_lote'])) {
                $n = count($resumen['sin_lote']);
                flash('warning', $n . ' producto(s) con control de lote sumaron unidades y el conteo no pregunta el '
                    . 'número, así que entraron como «' . (defined('SAN_LOTE_SIN_IDENTIFICAR') ? SAN_LOTE_SIN_IDENTIFICAR : 'SIN-LOTE')
                    . '»: ' . implode(', ', array_slice($resumen['sin_lote'], 0, 5))
                    . ($n > 5 ? ' y ' . ($n - 5) . ' más' : '') . '. Ponles su lote y su vencimiento en '
                    . 'Inventario → Lotes, filtrando por «sin lote».');
            }
            if ($resumen['omitidos']) {
                flash('warning', 'No se pudieron ajustar ' . count($resumen['omitidos']) . ' producto(s) porque el ajuste'
                    . ' dejaría la existencia en negativo (se vendió más de lo contado mientras contabas): '
                    . implode(', ', array_slice($resumen['omitidos'], 0, 5)) . '.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($volver);
    }

    /* ---------- Cancelar ---------- */
    if ($accion === 'cancelar') {
        require_perm('conteos.cancelar');
        try {
            if ($c['estado'] !== 'abierto') throw new RuntimeException('Solo se puede cancelar un conteo abierto.');
            dbUpdate('conteos', [
                'estado' => 'cancelado',
                'cancelado_por' => current_user()['id'] ?? null,
                'cancelado_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$id]);
            audit('conteos', 'cancelar', 'Conteo ' . $c['numero'] . ' cancelado', ['tabla' => 'conteos', 'registro_id' => $id]);
            flash('success', 'Conteo cancelado. No se tocó el inventario.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('modules/inventario/conteos.php');
    }
}

/* ============================================================
 *  Datos de la pantalla
 * ============================================================ */
$totales = qOne(
    "SELECT COUNT(*) productos,
            SUM(d.stock_contado IS NOT NULL) contados,
            SUM(d.stock_contado IS NOT NULL AND ABS(d.stock_contado - d.stock_teorico) > 0.0001) diferencias,
            SUM(d.stock_contado IS NOT NULL AND d.stock_contado < d.stock_teorico - 0.0001) lineas_faltantes,
            COALESCE(SUM(CASE WHEN d.stock_contado IS NOT NULL AND d.stock_contado > d.stock_teorico
                         THEN (d.stock_contado - d.stock_teorico) * d.costo_unitario ELSE 0 END),0) sobrante,
            COALESCE(SUM(CASE WHEN d.stock_contado IS NOT NULL AND d.stock_contado < d.stock_teorico
                         THEN (d.stock_teorico - d.stock_contado) * d.costo_unitario ELSE 0 END),0) faltante,
            COALESCE(SUM(d.stock_teorico * d.costo_unitario),0) valor_teorico
       FROM conteo_detalles d WHERE d.conteo_id = ?",
    [$id]
) ?: [];

$q      = trim((string) get('q'));
$filtro = in_array(get('filtro'), ['sin_contar', 'diferencias', 'contados'], true) ? get('filtro') : '';

$cond = ['d.conteo_id = ?'];
$par  = [$id];
if ($q !== '') { $cond[] = '(p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
if ($filtro === 'sin_contar')  $cond[] = 'd.stock_contado IS NULL';
if ($filtro === 'contados')    $cond[] = 'd.stock_contado IS NOT NULL';
if ($filtro === 'diferencias') $cond[] = 'd.stock_contado IS NOT NULL AND ABS(d.stock_contado - d.stock_teorico) > 0.0001';
$where = implode(' AND ', $cond);

$base = "FROM conteo_detalles d JOIN productos p ON p.id = d.producto_id WHERE $where";
$pg = paginar((int) qVal("SELECT COUNT(*) $base", $par), 50);
$lineas = qAll(
    "SELECT d.*, p.nombre, p.codigo, p.codigo_barras, cat.nombre AS categoria,
            COALESCE((SELECT s.cantidad FROM inventario_stock s
                       WHERE s.producto_id = d.producto_id AND s.sucursal_id = ?),0) AS stock_ahora
       FROM conteo_detalles d
       JOIN productos p ON p.id = d.producto_id
       LEFT JOIN categorias cat ON cat.id = p.categoria_id
      WHERE $where
      ORDER BY p.nombre LIMIT {$pg['porPagina']} OFFSET {$pg['offset']}",
    array_merge([(int) $c['sucursal_id']], $par)
);

if (export_solicitado()) {
    $todas = qAll(
        "SELECT p.codigo, p.nombre, d.stock_teorico, d.stock_contado, d.costo_unitario
           FROM conteo_detalles d JOIN productos p ON p.id = d.producto_id
          WHERE d.conteo_id = ? ORDER BY p.nombre",
        [$id]
    );
    $filas = [];
    foreach ($todas as $t) {
        $dif = $t['stock_contado'] === null ? null : (float) $t['stock_contado'] - (float) $t['stock_teorico'];
        $filas[] = [$t['codigo'], $t['nombre'], qty($t['stock_teorico']),
            $t['stock_contado'] === null ? 'sin contar' : qty($t['stock_contado']),
            $dif === null ? '' : qty($dif),
            money($t['costo_unitario'], false),
            $dif === null ? '' : money($dif * (float) $t['costo_unitario'], false)];
    }
    export_tabla('conteo_' . $c['numero'],
        ['Código', 'Producto', 'Existencia sistema', 'Contado', 'Diferencia', 'Costo unitario', 'Impacto'],
        $filas, 'Conteo ' . $c['numero']);
}

$pctAvance = (int) $totales['productos'] > 0 ? (int) $totales['contados'] / (int) $totales['productos'] * 100 : 0;
$impacto = (float) $totales['sobrante'] - (float) $totales['faltante'];
$abierto = $c['estado'] === 'abierto';

$acciones = export_buttons()
    . '<a href="' . e(url('modules/inventario/conteos.php')) . '" class="btn btn-ghost no-print">' . icon('arrow-left', 'w-4 h-4') . ' Conteos</a>';

layout_start('Conteo ' . $c['numero'], e($c['descripcion']) . ' · ' . e($c['sucursal']) . ' · ' . e($c['categoria'] ?: 'Todo el catálogo'), $acciones);
?>

<!-- Estado -->
<?php if (!$abierto): ?>
  <div class="card p-4 mb-5 flex items-start gap-3 <?= $c['estado'] === 'aplicado' ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200 bg-slate-50' ?>">
    <span class="w-10 h-10 rounded-xl <?= $c['estado'] === 'aplicado' ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-200 text-slate-500' ?> flex items-center justify-center shrink-0">
      <?= icon($c['estado'] === 'aplicado' ? 'check' : 'x', 'w-5 h-5') ?>
    </span>
    <div>
      <h3 class="font-bold text-slate-800"><?= $c['estado'] === 'aplicado' ? 'Conteo aplicado al inventario' : 'Conteo cancelado' ?></h3>
      <p class="text-sm text-slate-600 mt-0.5">
        <?php if ($c['estado'] === 'aplicado'): ?>
          Los ajustes ya se registraron en el kardex por <?= e($c['aplicado_por_nombre'] ?: '—') ?> el <?= fechaHora($c['aplicado_at']) ?>.
          Este conteo queda como constancia y no se puede modificar.
          <?php if (trim((string) ($c['justificacion'] ?? '')) !== ''): ?>
            <span class="block mt-1.5 text-slate-700">
              <span class="text-slate-500">Explicación de las diferencias:</span>
              «<?= e($c['justificacion']) ?>»
            </span>
          <?php endif; ?>
        <?php else: ?>
          Se canceló el <?= fechaHora($c['cancelado_at']) ?>. No se tocó el inventario.
        <?php endif; ?>
      </p>
    </div>
  </div>
<?php endif; ?>

<!-- Indicadores -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
  <div class="card p-5">
    <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center mb-3"><?= icon('clipboard', 'w-5 h-5') ?></div>
    <p class="text-sm text-slate-500">Avance del conteo</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-0.5 tabular-nums"><?= number_format($pctAvance, 0) ?>%</p>
    <div class="h-2 rounded-full bg-slate-100 overflow-hidden mt-2">
      <div class="h-full rounded-full <?= $pctAvance >= 100 ? 'bg-emerald-500' : 'bg-blue-500' ?>" style="width:<?= max($pctAvance, 1) ?>%"></div>
    </div>
    <p class="text-xs text-slate-400 mt-1.5"><?= number_format((int) $totales['contados']) ?> de <?= number_format((int) $totales['productos']) ?> productos</p>
  </div>

  <div class="card p-5">
    <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center mb-3"><?= icon('alert', 'w-5 h-5') ?></div>
    <p class="text-sm text-slate-500">Productos con diferencia</p>
    <p class="text-2xl font-extrabold text-slate-800 mt-0.5 tabular-nums"><?= number_format((int) $totales['diferencias']) ?></p>
    <p class="text-xs text-slate-400 mt-1.5">
      <?= (int) $totales['contados'] > 0 ? number_format((int) $totales['diferencias'] / (int) $totales['contados'] * 100, 1) . '% de lo contado' : 'Aún sin contar' ?>
    </p>
  </div>

  <div class="card p-5">
    <div class="w-11 h-11 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center mb-3"><?= icon('trending-down', 'w-5 h-5') ?></div>
    <p class="text-sm text-slate-500">Faltante (merma)</p>
    <p class="text-2xl font-extrabold text-rose-600 mt-0.5 tabular-nums"><?= money($totales['faltante']) ?></p>
    <p class="text-xs text-slate-400 mt-1.5">Mercancía que el sistema tenía y no apareció</p>
  </div>

  <div class="card p-5">
    <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3"><?= icon('trending', 'w-5 h-5') ?></div>
    <p class="text-sm text-slate-500">Sobrante</p>
    <p class="text-2xl font-extrabold text-emerald-600 mt-0.5 tabular-nums"><?= money($totales['sobrante']) ?></p>
    <p class="text-xs text-slate-400 mt-1.5">Impacto neto <?= ($impacto >= 0 ? '+' : '') . money($impacto) ?></p>
  </div>
</div>

<!-- Barra de trabajo -->
<div class="card p-4 mb-5 no-print flex flex-wrap items-center gap-2">
  <form method="get" class="flex items-center gap-2">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($filtro): ?><input type="hidden" name="filtro" value="<?= e($filtro) ?>"><?php endif; ?>
    <div class="relative">
      <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><?= icon('search', 'w-4 h-4') ?></span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Producto, SKU o código de barras…"
             autocomplete="off" class="input pl-10 w-auto min-w-[260px]">
    </div>
    <button class="btn btn-ghost btn-sm"><?= icon('filter', 'w-3.5 h-3.5') ?> Buscar</button>
  </form>

  <div class="flex flex-wrap items-center gap-1 p-1 bg-slate-100 rounded-xl">
    <?php foreach (['' => 'Todos', 'sin_contar' => 'Sin contar', 'contados' => 'Contados', 'diferencias' => 'Con diferencia'] as $k => $lbl):
      $qs = array_filter(['id' => $id, 'filtro' => $k ?: null, 'q' => $q ?: null]); ?>
      <a href="?<?= e(http_build_query($qs)) ?>"
         class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition <?= $filtro === $k ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="ml-auto flex items-center gap-2">
    <?php if ($abierto && can('conteos.contar')): ?>
      <a href="<?= e(url('modules/inventario/escaner.php?modo=conteo&conteo_id=' . $id)) ?>" class="btn btn-soft btn-sm"
         title="Abre el terminal de almacén: cada lectura suma a lo contado y se guarda al momento">
        <?= icon('barcode', 'w-3.5 h-3.5') ?> Contar escaneando
      </a>
    <?php endif; ?>
    <?php if ($abierto && can('conteos.cancelar')): ?>
      <form method="post" onsubmit="return confirm('¿Cancelar el conteo <?= e($c['numero']) ?>? No se tocará el inventario y no se podrá retomar.')">
        <?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-ghost btn-sm"><?= icon('x', 'w-3.5 h-3.5') ?> Cancelar conteo</button>
      </form>
    <?php endif; ?>
    <?php if ($abierto && can('conteos.aplicar')): ?>
      <button type="button" onclick="<?= jsEvent('cnt:aplicar') ?>" class="btn btn-primary btn-sm"
              <?= (int) $totales['contados'] === 0 ? 'disabled' : '' ?>>
        <?= icon('check', 'w-3.5 h-3.5') ?> Aplicar al inventario
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- Captura -->
<form method="post" id="formConteo">
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="contar">
  <input type="hidden" name="id" value="<?= $id ?>">

  <div class="card overflow-hidden">
    <?php if (!$lineas): ?>
      <?= empty_state('Sin productos en este filtro', 'Prueba con otro filtro o limpia la búsqueda.', 'search') ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr>
            <th>Producto</th>
            <th class="text-right">Sistema</th>
            <th class="text-center w-36">Contado</th>
            <th class="text-right">Diferencia</th>
            <th class="text-right">Impacto</th>
            <?php if ($abierto): ?><th class="text-center">Ahora</th><?php endif; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($lineas as $l):
              $contado = $l['stock_contado'];
              $dif = $contado === null ? null : (float) $contado - (float) $l['stock_teorico'];
              $valor = $dif === null ? 0 : $dif * (float) $l['costo_unitario'];
              $movido = abs((float) $l['stock_ahora'] - (float) $l['stock_teorico']) > 0.0001;
            ?>
              <tr class="<?= $dif !== null && abs($dif) > 0.0001 ? ($dif < 0 ? 'bg-rose-50/40' : 'bg-emerald-50/40') : '' ?>">
                <td>
                  <span class="font-semibold text-slate-700"><?= e($l['nombre']) ?></span>
                  <span class="block text-[11.5px] text-slate-400">
                    <?= e($l['codigo']) ?><?= $l['categoria'] ? ' · ' . e($l['categoria']) : '' ?>
                  </span>
                </td>
                <td class="text-right tabular-nums text-slate-600"><?= qty($l['stock_teorico']) ?></td>
                <td class="text-center">
                  <?php if ($abierto && can('conteos.contar')): ?>
                    <input type="text" inputmode="decimal" name="cont[<?= (int) $l['id'] ?>]"
                           value="<?= $contado === null ? '' : qty($contado) ?>"
                           data-teorico="<?= (float) $l['stock_teorico'] ?>"
                           data-barras="<?= e((string) $l['codigo_barras']) ?>"
                           data-codigo="<?= e((string) $l['codigo']) ?>"
                           aria-label="Cantidad contada de <?= e($l['nombre']) ?>"
                           class="input py-1.5 px-2 text-center w-28 tabular-nums" placeholder="—">
                  <?php else: ?>
                    <span class="tabular-nums font-semibold text-slate-700"><?= $contado === null ? '<span class="text-slate-300">sin contar</span>' : qty($contado) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-right tabular-nums font-semibold <?= $dif === null ? 'text-slate-300' : ($dif < 0 ? 'text-rose-600' : ($dif > 0 ? 'text-emerald-600' : 'text-slate-400')) ?>">
                  <?= $dif === null ? '—' : (($dif > 0 ? '+' : '') . qty($dif)) ?>
                </td>
                <td class="text-right tabular-nums <?= abs($valor) < 0.005 ? 'text-slate-300' : ($valor < 0 ? 'text-rose-600' : 'text-emerald-600') ?>">
                  <?= $dif === null || abs($valor) < 0.005 ? '—' : (($valor > 0 ? '+' : '') . money($valor)) ?>
                </td>
                <?php if ($abierto): ?>
                  <td class="text-center">
                    <?php if ($movido): ?>
                      <span class="badge badge-amber" title="El stock se movió después de abrir el conteo (ventas o entradas). Al aplicar se ajusta por la diferencia, no por el absoluto."><?= qty($l['stock_ahora']) ?></span>
                    <?php else: ?>
                      <span class="text-slate-300 text-xs">sin cambios</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= paginacion($pg) ?>
    <?php endif; ?>
  </div>

  <?php if ($abierto && can('conteos.contar') && $lineas): ?>
    <div class="sticky bottom-0 mt-4 no-print">
      <div class="card p-4 flex flex-wrap items-center gap-3 shadow-pop">
        <p class="text-sm text-slate-500 flex-1 min-w-[220px]">
          Escribe lo que contaste. Deja el campo vacío para marcar el producto como <strong>sin contar</strong>.
          Guarda antes de cambiar de página o de filtro.
          <span class="block mt-0.5">Con una pistola lectora puedes disparar sobre esta pantalla: cada lectura suma 1 a su producto.</span>
        </p>
        <span id="avisoEscaneo" class="text-sm font-semibold px-3 py-1.5 rounded-lg hidden"></span>
        <button type="submit" class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar cantidades</button>
      </div>
    </div>
  <?php endif; ?>
</form>

<?php if ($abierto && can('conteos.aplicar')): ?>
<!-- Modal: aplicar -->
<div x-data="{open:false}" @cnt:aplicar.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-md" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="aplicar">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="p-6 text-center">
          <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto mb-4"><?= icon('clipboard', 'w-7 h-7') ?></div>
          <h3 class="text-lg font-bold text-slate-800">Aplicar el conteo al inventario</h3>
          <p class="text-sm text-slate-500 mt-2 leading-relaxed">
            Se ajustarán <strong><?= number_format((int) $totales['diferencias']) ?> producto(s)</strong> con diferencia.
            Cada ajuste queda registrado en el kardex a tu nombre.
          </p>
        </div>
        <div class="px-6 pb-2 space-y-2 text-sm">
          <div class="flex justify-between py-2 border-t border-slate-100"><span class="text-slate-600">Sobrantes</span><span class="font-semibold text-emerald-600 tabular-nums"><?= money($totales['sobrante']) ?></span></div>
          <div class="flex justify-between py-2 border-t border-slate-100"><span class="text-slate-600">Faltantes</span><span class="font-semibold text-rose-600 tabular-nums"><?= money($totales['faltante']) ?></span></div>
          <div class="flex justify-between py-2 border-t border-slate-100"><span class="font-bold text-slate-800">Impacto neto</span><span class="font-extrabold tabular-nums <?= $impacto >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= ($impacto >= 0 ? '+' : '') . money($impacto) ?></span></div>
        </div>
        <?php if ((int) $totales['contados'] < (int) $totales['productos']): ?>
          <div class="mx-6 mt-3 flex items-start gap-2.5 rounded-xl bg-amber-50 border border-amber-200 p-3">
            <?= icon('alert', 'w-4 h-4 text-amber-600 shrink-0 mt-0.5') ?>
            <p class="text-[12.5px] text-slate-700 leading-relaxed">
              Quedan <strong><?= number_format((int) $totales['productos'] - (int) $totales['contados']) ?></strong> productos sin contar.
              Esos no se tocan: se quedan como están en el sistema.
            </p>
          </div>
        <?php endif; ?>
        <?php $hayFaltantes = (int) ($totales['lineas_faltantes'] ?? 0) > 0; ?>
        <div class="px-6 pt-4">
          <label class="label" for="cnt_justificacion">
            Explicación de las diferencias
            <?php if ($hayFaltantes): ?>
              <span class="text-rose-600">*</span>
            <?php else: ?>
              <span class="text-slate-400 font-normal">(opcional)</span>
            <?php endif; ?>
          </label>
          <textarea id="cnt_justificacion" name="justificacion" rows="2" maxlength="300" class="input"
                    <?= $hayFaltantes ? 'required' : '' ?>
                    placeholder="<?= $hayFaltantes
                        ? 'Por qué falta esa mercancía: rotura, muestra, robo, error de captura…'
                        : 'Opcional: qué explica lo que apareció de más' ?>"></textarea>
          <?php if ($hayFaltantes): ?>
            <p class="text-[12px] text-slate-500 mt-1.5 leading-relaxed">
              Este conteo baja la existencia de
              <strong><?= number_format((int) $totales['lineas_faltantes']) ?> producto(s)</strong>.
              La nota queda pegada a cada movimiento del kardex y sale en el informe de ajustes y mermas.
            </p>
          <?php endif; ?>
        </div>
        <div class="flex gap-2 p-6 pt-4">
          <button type="button" @click="open=false" class="btn btn-ghost flex-1">Volver</button>
          <button type="submit" class="btn btn-primary flex-1"><?= icon('check', 'w-4 h-4') ?> Sí, aplicar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?= escaner_script() ?>
<script>
/**
 * Marca la fila en vivo mientras se teclea, para que el faltante o el sobrante
 * se vea sin tener que guardar y recargar.
 */
(function () {
  'use strict';
  var form = document.getElementById('formConteo');
  if (!form) return;
  form.querySelectorAll('input[name^="cont["]').forEach(function (campo) {
    campo.addEventListener('input', function () {
      var fila = campo.closest('tr');
      var teorico = parseFloat(campo.dataset.teorico || '0');
      var txt = campo.value.trim().replace(/,/g, '');
      fila.classList.remove('bg-rose-50/40', 'bg-emerald-50/40');
      if (txt === '') return;
      var v = parseFloat(txt);
      if (isNaN(v)) return;
      var dif = v - teorico;
      if (Math.abs(dif) < 0.0001) return;
      fila.classList.add(dif < 0 ? 'bg-rose-50/40' : 'bg-emerald-50/40');
    });
    // Enter salta al siguiente campo en vez de enviar el formulario: contar es
    // teclear muchos números seguidos.
    campo.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      var campos = Array.prototype.slice.call(form.querySelectorAll('input[name^="cont["]'));
      var i = campos.indexOf(campo);
      if (i > -1 && campos[i + 1]) { campos[i + 1].focus(); campos[i + 1].select(); }
    });
  });

  /* ---------------------------------------------------------------------
   * Pistola lectora sobre esta pantalla: cada disparo suma 1 al producto.
   * Solo se toca lo que está VISIBLE en la página; el conteo se pagina de 50
   * en 50, así que si el artículo no está aquí se dice en vez de sumárselo al
   * que no era. Para contar el almacén entero de corrido está el terminal del
   * teléfono, que guarda al momento.
   * ------------------------------------------------------------------- */
  var aviso = document.getElementById('avisoEscaneo');
  function decir(msg, ok) {
    if (!aviso) return;
    aviso.textContent = msg;
    aviso.className = 'text-sm font-semibold px-3 py-1.5 rounded-lg ' + (ok ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700');
    clearTimeout(aviso._t);
    aviso._t = setTimeout(function () { aviso.className = 'text-sm font-semibold px-3 py-1.5 rounded-lg hidden'; }, ok ? 2500 : 5000);
  }

  function buscarCampo(codigo) {
    var campos = Array.prototype.slice.call(form.querySelectorAll('input[name^="cont["]'));
    var alt = /^\d{12}$/.test(codigo) ? '0' + codigo : (/^0\d{12}$/.test(codigo) ? codigo.slice(1) : null);
    for (var i = 0; i < campos.length; i++) {
      var b = campos[i].dataset.barras || '';
      if (b && (b === codigo || (alt && b === alt))) return campos[i];
    }
    for (var j = 0; j < campos.length; j++) {
      if ((campos[j].dataset.codigo || '').toLowerCase() === codigo.toLowerCase()) return campos[j];
    }
    return null;
  }

  // Solo se escucha la pistola si esta pantalla admite captura. En un conteo ya
  // aplicado o cancelado no hay campos que rellenar, y un disparo solo produciría
  // un error desconcertante.
  if (window.NexoEscaner && form.querySelector('input[name^="cont["]')) {
    NexoEscaner.teclado({
      onCodigo: function (codigo) {
        var campo = buscarCampo(codigo);
        if (!campo) {
          NexoEscaner.pitar(false);
          decir('El código ' + codigo + ' no está en esta página del conteo. Búscalo arriba o usa «Contar escaneando».', false);
          return;
        }
        var actual = parseFloat(String(campo.value).replace(/,/g, '')) || 0;
        campo.value = String(actual + 1);
        campo.dispatchEvent(new Event('input', { bubbles: true }));   // repinta la fila
        campo.focus();
        campo.select();
        campo.scrollIntoView({ block: 'center', behavior: 'smooth' });
        decir((campo.getAttribute('aria-label') || '').replace('Cantidad contada de ', '') + ' → ' + campo.value, true);
      },
    });
  }
})();
</script>

<?php layout_end(); ?>
