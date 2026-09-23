<?php
/**
 * Promotion Cockpit.
 *
 * De lo global al detalle, en cuatro pasos:
 *   1. Resumen    venta bruta → descuentos → venta neta y margen, por tipo de
 *                 descuento, con los efectos sobre el margen contra el año pasado.
 *   2. Detallado  stacking (cuántos descuentos lleva cada factura) y el detalle
 *                 promoción por promoción, con las menos activadas.
 *   3. Producto   productos héroe contra el resto y el detalle tipo → promoción → producto.
 *   4. Sell-out   participación y crecimiento por sucursal, canal, segmento y línea.
 *
 * Cálculo y criterios: includes/cockpit.php y docs/PROMOTION-COCKPIT.md.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('cockpit.ver');

if (!cockpit_disponible()) {
    layout_start('Promotion Cockpit', 'Análisis de descuentos y margen');
    echo '<div class="card p-6">' . empty_state(
        'Falta aplicar la actualización de la base de datos',
        'El cockpit necesita que se ejecute database/migracion_cockpit_promociones_p39.sql. Hasta entonces las ventas no guardan qué promoción se aplicó.',
        'alert'
    ) . '</div>';
    layout_end();
    exit;
}

/* ============================================================
 *  Clasificación masiva de productos (segmento / línea / héroe)
 * ============================================================ */
if (isPost()) {
    verify_csrf();
    require_perm('productos.editar');
    if (post('accion') === 'clasificar') {
        $ok = 0; $noEncontrados = [];
        foreach (preg_split('/\R/', (string) post('lineas')) as $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;
            // Acepta ; , o tabulador (lo que sale de pegar desde Excel).
            $c = array_map('trim', preg_split('/[;\t,]/', $linea));
            $sku = $c[0] ?? '';
            if ($sku === '' || mb_strtolower($sku) === 'sku') continue;
            $pid = (int) qVal("SELECT id FROM productos WHERE codigo = ? OR codigo_barras = ?", [$sku, $sku]);
            if (!$pid) { $noEncontrados[] = $sku; continue; }
            $heroe = mb_strtolower($c[3] ?? '');
            dbUpdate('productos', [
                'segmento' => mb_substr($c[1] ?? '', 0, 60) ?: null,
                'linea'    => mb_substr($c[2] ?? '', 0, 60) ?: null,
                'es_heroe' => in_array($heroe, ['1', 'si', 'sí', 'x', 'heroe', 'héroe', 'yes', 'hero'], true) ? 1 : 0,
            ], 'id = ?', [$pid]);
            $ok++;
        }
        audit('productos', 'editar', "Clasificación de marca (cockpit): $ok productos", ['tabla' => 'productos']);
        flash($ok ? 'success' : 'warning', "$ok producto(s) clasificados."
            . ($noEncontrados ? ' No se encontraron: ' . implode(', ', array_slice($noEncontrados, 0, 15)) . (count($noEncontrados) > 15 ? '…' : '') : ''));
    }
    redirect('modules/marketing/cockpit.php?' . http_build_query(array_diff_key($_GET, ['export' => 1])));
}

/* ============================================================
 *  Filtros y datos comunes
 * ============================================================ */
$tabs = ['resumen' => 'Resumen', 'detalle' => 'Detallado', 'producto' => 'Producto', 'sellout' => 'Sell-out'];
$tab = array_key_exists((string) get('tab'), $tabs) ? (string) get('tab') : 'resumen';
$f = cockpit_filtros();
$TY = $f['ty']; $LY = $f['ly'];
$tipos = cockpit_tipos();
$x = cockpit_expr();

$totTY = cockpit_metricas(cockpit_totales($f, $TY));
$totLY = cockpit_metricas(cockpit_totales($f, $LY));
$gsTY = (float) $totTY['gs']; $gsLY = (float) $totLY['gs'];

/** URL de esta pantalla con parámetros cambiados. */
function ck_url(array $cambios): string
{
    $q = array_merge($_GET, $cambios);
    unset($q['export']);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($q);
}

/** Variación de un valor en %, o de una tasa en puntos. */
function ck_var(float $ty, float $ly, bool $esTasa, bool $invertir = false): string
{
    if ($esTasa) {
        $d = $ty - $ly;
        $txt = cockpit_pts($d);
    } else {
        $d = rep_delta($ty, $ly);
        if ($d === null) return '<span class="text-slate-400">—</span>';
        $txt = ($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . '%';
    }
    if (abs($d) < 0.05) return '<span class="text-slate-400">' . $txt . '</span>';
    $bueno = $invertir ? $d < 0 : $d > 0;
    return '<span class="' . ($bueno ? 'text-emerald-600' : 'text-rose-600') . ' font-semibold">' . $txt . '</span>';
}

/** Importe entero con símbolo: en una tarjeta los centavos solo estorban. */
function ck_money(float $v): string
{
    return e(setting('moneda', 'RD$')) . ' ' . cockpit_n($v);
}

/** Tarjeta compacta del cockpit: valor TY, LY y variación. */
function ck_tile(string $titulo, string $valorTY, string $valorLY, string $var, string $extra = ''): string
{
    return '<div class="rounded-xl border border-slate-200 bg-white p-4 flex flex-col min-w-0">'
        . '<div class="flex items-start justify-between gap-2"><p class="text-sm font-semibold text-slate-600">' . e($titulo) . '</p>' . $extra . '</div>'
        . '<p class="text-xl 2xl:text-2xl font-extrabold text-slate-800 tabular-nums mt-1 break-words">' . $valorTY . '</p>'
        . '<p class="text-xs text-slate-400 mt-auto pt-2 bg-slate-50 -mx-4 -mb-4 px-4 pb-2.5 rounded-b-xl">Año anterior: <span class="tabular-nums">' . $valorLY . '</span> · ' . $var . '</p>'
        . '</div>';
}

$meses = cockpit_meses($TY);
$mesesLY = cockpit_meses($LY);
$etqMeses = array_map(fn($ym) => mesNombre((int) substr($ym, 5, 2), true), $meses);

/* ============================================================
 *  Datos por pestaña
 * ============================================================ */
$filasTipo = [];
if ($tab === 'resumen') {
    $porTipoTY = cockpit_por($f, $TY, $x['tipo']);
    $porTipoLY = cockpit_por($f, $LY, $x['tipo']);
    $claves = array_unique(array_merge(array_keys($porTipoTY), array_keys($porTipoLY)));
    foreach ($claves as $k) {
        $ty = $porTipoTY[$k] ?? cockpit_vacio();
        $ly = $porTipoLY[$k] ?? cockpit_vacio();
        $filasTipo[$k] = [
            'ty' => cockpit_metricas($ty, $gsTY), 'ly' => cockpit_metricas($ly, $gsLY),
            'ef' => cockpit_efectos($ty, $ly, $gsTY, $gsLY),
        ];
    }
    uasort($filasTipo, fn($a, $b) => $b['ty']['gs'] <=> $a['ty']['gs']);
    $agrupar = function (array $claves) use ($filasTipo, $gsTY, $gsLY) {
        $sel = array_intersect_key($filasTipo, array_flip($claves));
        $ef = ['volumen' => 0.0, 'mezcla' => 0.0, 'tasa' => 0.0, 'producto' => 0.0, 'total' => 0.0];
        foreach ($sel as $r) foreach ($ef as $k => $_) $ef[$k] += $r['ef'][$k];
        return [
            'ty' => cockpit_metricas(cockpit_sumar(array_column($sel, 'ty')), $gsTY),
            'ly' => cockpit_metricas(cockpit_sumar(array_column($sel, 'ly')), $gsLY),
            'ef' => $ef,
        ];
    };
    $enPromo = array_values(array_diff(array_keys($filasTipo), ['sin']));
    $filaTotal = $agrupar(array_keys($filasTipo));
    $filaSin   = $agrupar(['sin']);
    $filaPromo = $agrupar($enPromo);

    $menTY = cockpit_mensual($f, $TY);
    $menLY = cockpit_mensual($f, $LY);
    $serie = function (array $men, array $ms, string $que) {
        $out = [];
        foreach ($ms as $ym) {
            $r = $men[$ym] ?? null;
            if (!$r || $r['gs'] <= 0) { $out[] = 0; continue; }
            $out[] = round($que === 'desc' ? ($r['gs'] - $r['ns']) / $r['gs'] * 100 : $r['gs_promo'] / $r['gs'] * 100, 1);
        }
        return $out;
    };

    // KPIs de descuento.
    $directo = fn(array $t, array $porTipo) => $t['gs'] > 0
        ? (($t['gs'] - $t['ns']) - (($porTipo['muestra']['gs'] ?? 0) - ($porTipo['muestra']['ns'] ?? 0))) / $t['gs'] * 100 : 0.0;
    $regalos = fn(array $t, array $porTipo) => $t['gs'] > 0 ? (($porTipo['muestra']['gs'] ?? 0)) / $t['gs'] * 100 : 0.0;
}

if ($tab === 'detalle') {
    $stTY = cockpit_stacking($f, $TY);
    $stLY = cockpit_stacking($f, $LY);
    $vista = get('vista') === 'menos' ? 'menos' : 'todas';
    $mec = cockpit_mecanismos($f, $TY, $vista === 'menos');
    foreach ($mec as $k => $r) $mec[$k] = cockpit_metricas($r, $gsTY) + $r;
    $descTot = max(0.01, $gsTY - (float) $totTY['ns']);
    if ($vista === 'menos') {
        uasort($mec, fn($a, $b) => [$a['act'], $a['gs']] <=> [$b['act'], $b['gs']]);
        $mec = array_slice($mec, 0, 30, true);
    } else {
        uasort($mec, fn($a, $b) => [$a['tipo'], -$a['gs']] <=> [$b['tipo'], -$b['gs']]);
    }
    $promoTY = cockpit_por($f, $TY, "({$x['tipo']} <> 'sin')");
    $promoLY = cockpit_por($f, $LY, "({$x['tipo']} <> 'sin')");
    $pctPromo = fn(array $por, string $campo, float $tot) => $tot > 0 ? (float) ($por['1'][$campo] ?? 0) / $tot * 100 : 0.0;
}

if ($tab === 'producto') {
    $heroes = cockpit_por($f, $TY, 'COALESCE(pr.es_heroe,0)');
    $incluirSin = get('sin') === '1';
    [$w, $p] = cockpit_where($f, $TY);
    $rows = qAll(
        "SELECT {$x['tipo']} tipo, {$x['mecanismo']} mec, vd.producto_id pid,
                MAX(COALESCE(pr.codigo,'')) codigo, MAX(COALESCE(pr.nombre, vd.descripcion)) nombre,
                SUM(vd.cantidad) qty, SUM({$x['costo']}) costo, SUM({$x['gs']}) gs, SUM({$x['ns']}) ns
           " . cockpit_from() . " WHERE $w" . ($incluirSin ? '' : " AND {$x['tipo']} <> 'sin'") . "
          GROUP BY tipo, mec, pid ORDER BY gs DESC",
        $p
    );
    $mecInfo = cockpit_mecanismos($f, $TY);
    $arbol = [];
    foreach ($rows as $r) {
        $t = $r['tipo']; $m = $r['mec'];
        $arbol[$t]['tot'] = cockpit_sumar([$arbol[$t]['tot'] ?? cockpit_vacio(), $r]);
        $arbol[$t]['mec'][$m]['tot'] = cockpit_sumar([$arbol[$t]['mec'][$m]['tot'] ?? cockpit_vacio(), $r]);
        $arbol[$t]['mec'][$m]['prod'][] = $r;
    }
    uasort($arbol, fn($a, $b) => $b['tot']['gs'] <=> $a['tot']['gs']);
    $nombreMec = fn(string $m) => $m === 'sin' ? 'Sin promoción' : ($mecInfo[$m]['nombre'] ?? $m);
}

if ($tab === 'sellout') {
    $dims = [
        'sucursal' => ['Sucursal', 'store', "(SELECT su.nombre FROM sucursales su WHERE su.id = v.sucursal_id)"],
        'canal'    => ['Canal', 'megaphone', cockpit_canal_sql('v')],
        'segmento' => ['Segmento', 'layers', "COALESCE(NULLIF(pr.segmento,''), (SELECT c.nombre FROM categorias c WHERE c.id = pr.categoria_id), 'Sin segmento')"],
        'linea'    => ['Línea', 'tag', "COALESCE(NULLIF(pr.linea,''), 'Sin línea')"],
    ];
    if (tiendas_hay()) {
        $dims['tienda'] = ['Tienda (marca)', 'tag', "COALESCE((SELECT t.nombre FROM tiendas t WHERE t.id = v.tienda_id), 'Sin marca')"];
    }
    $sell = [];
    foreach ($dims as $k => [$nombre, $ico, $expr]) {
        $a = cockpit_por($f, $TY, $expr);
        $b = cockpit_por($f, $LY, $expr);
        $filas = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $g) {
            $filas[$g] = ['ty' => (float) ($a[$g]['ns'] ?? 0), 'ly' => (float) ($b[$g]['ns'] ?? 0)];
        }
        uasort($filas, fn($p, $q) => $q['ty'] <=> $p['ty']);
        $sell[$k] = $filas;
    }
    $canalesNombre = cockpit_canales();
}

/* ============================================================
 *  Exportación (la tabla principal de la pestaña)
 * ============================================================ */
if (export_solicitado()) {
    $n2 = fn($v) => number_format((float) $v, 2, '.', '');
    $p1 = fn($v) => number_format((float) $v, 1, '.', '');
    $sufijo = $TY[0] . '_' . $TY[1];
    if ($tab === 'resumen') {
        $filas = [];
        $emitir = function (string $nombre, array $r) use (&$filas, $n2, $p1) {
            $t = $r['ty'];
            $filas[] = [$nombre, $n2($t['gs']), $p1($t['peso_gs']), $p1($t['margen_gs']), $n2($t['desc']), $p1($t['desc_pct']),
                        $n2($t['ns']), $n2($t['costo']), $n2($t['margen']), $p1($t['margen_ns']), $p1($t['pts']),
                        $n2($r['ly']['gs']), $n2($r['ly']['ns']), $p1($r['ly']['desc_pct']),
                        $n2($r['ef']['volumen']), $n2($r['ef']['tasa']), $n2($r['ef']['mezcla']), $n2($r['ef']['producto']), $n2($r['ef']['total'])];
        };
        $emitir('TOTAL', $filaTotal);
        $emitir('SIN PROMOCIÓN', $filaSin);
        $emitir('EN PROMOCIÓN', $filaPromo);
        foreach ($filasTipo as $k => $r) if ($k !== 'sin') $emitir(cockpit_tipo_label($k), $r);
        export_tabla('cockpit_resumen_' . $sufijo,
            ['Tipo de descuento', 'Venta bruta', '% VB', 'Margen % VB', 'Descuentos', 'Desc %', 'Venta neta', 'Costo', 'Margen VN', 'Margen % VN', 'Pts perdidos',
             'VB año ant.', 'VN año ant.', 'Desc % año ant.', 'Efecto volumen', 'Efecto tasa desc.', 'Efecto mezcla', 'Efecto mezcla producto', 'Efecto total'],
            $filas, 'Promotion Cockpit — resumen por tipo de descuento');
    }
    if ($tab === 'detalle') {
        $filas = [];
        foreach ($mec as $r) {
            $filas[] = [cockpit_tipo_label($r['tipo']), $r['nombre'], (int) $r['act'], $n2($r['atv_ticket']), $n2($r['gs']), $p1($r['margen_gs']),
                        $p1($r['peso_gs']), $n2($r['desc']), $p1($r['desc_pct']), $p1($r['desc'] / $descTot * 100), $n2($r['ns']), $n2($r['costo']),
                        $p1($r['margen_ns']), $p1($r['pts'])];
        }
        export_tabla('cockpit_detalle_' . $sufijo,
            ['Tipo', 'Descuento o promoción', 'Activaciones', 'Ticket medio', 'Venta bruta', 'Margen % VB', 'Peso VB', 'Descuentos', 'Desc %', 'Peso desc.', 'Venta neta', 'Costo', 'Margen % VN', 'Pts perdidos'],
            $filas, 'Promotion Cockpit — detalle por descuento');
    }
    if ($tab === 'producto') {
        $filas = [];
        foreach ($rows as $r) {
            $filas[] = [cockpit_tipo_label($r['tipo']), $nombreMec($r['mec']), $r['codigo'], $r['nombre'], qty($r['qty']), $n2($r['costo']),
                        $n2($r['gs']), $n2($r['gs'] - $r['ns']), $p1($r['gs'] > 0 ? ($r['gs'] - $r['ns']) / $r['gs'] * 100 : 0), $n2($r['ns'])];
        }
        export_tabla('cockpit_producto_' . $sufijo,
            ['Tipo', 'Descuento', 'SKU', 'Producto', 'Cantidad', 'Costo', 'Venta bruta', 'Descuentos', 'Desc %', 'Venta neta'],
            $filas, 'Promotion Cockpit — detalle por producto');
    }
    // Sell-out: las cuatro dimensiones en una sola hoja.
    $filas = [];
    foreach ($sell ?? [] as $k => $dim) {
        $tot = array_sum(array_column($dim, 'ty')) ?: 1; $totL = array_sum(array_column($dim, 'ly')) ?: 1;
        foreach ($dim as $g => $v) {
            $filas[] = [$dims[$k][0], $k === 'canal' ? ($canalesNombre[$g] ?? $g) : $g, $n2($v['ty']), $p1($v['ty'] / $tot * 100),
                        $n2($v['ly']), $p1($v['ly'] / $totL * 100), ($d = rep_delta($v['ty'], $v['ly'])) === null ? '—' : $p1($d)];
        }
    }
    export_tabla('cockpit_sellout_' . $sufijo,
        ['Dimensión', 'Valor', 'Venta neta', 'Participación %', 'Venta neta año ant.', 'Participación año ant. %', 'Crecimiento %'],
        $filas, 'Promotion Cockpit — sell-out');
}

/* ============================================================
 *  Pantalla
 * ============================================================ */
$acciones = rep_barra_titulo();
layout_start('Promotion Cockpit', 'Del global al detalle · ' . fechaCorta($TY[0]) . ' al ' . fechaCorta($TY[1]) . ' contra ' . fechaCorta($LY[0]) . ' al ' . fechaCorta($LY[1]) . ' · ' . rep_alcance_sucursal(), $acciones);
echo rep_encabezado_impresion('Promotion Cockpit · ' . $tabs[$tab], ['desde' => $TY[0], 'hasta' => $TY[1]]);
?>

<!-- Pasos -->
<nav class="flex flex-wrap gap-1.5 mb-4 no-print" aria-label="Secciones del cockpit">
  <?php $i = 0; foreach ($tabs as $k => $lbl): $i++; $act = $k === $tab; ?>
    <a href="<?= e(ck_url(['tab' => $k, 'vista' => null, 'sin' => null])) ?>"
       class="inline-flex items-center gap-2 pl-3 pr-5 py-2 text-sm font-bold uppercase tracking-wide transition
              <?= $act ? 'bg-amber-400 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>"
       style="clip-path:polygon(0 0,calc(100% - 12px) 0,100% 50%,calc(100% - 12px) 100%,0 100%,<?= $i === 1 ? '0 50%' : '12px 50%' ?>)"
       <?= $act ? 'aria-current="page"' : '' ?>>
      <span class="text-xl leading-none font-light"><?= $i ?></span> <?= e($lbl) ?>
    </a>
  <?php endforeach; ?>
</nav>

<!-- Filtros -->
<?php
$marcas = qAll("SELECT id, nombre FROM marcas ORDER BY nombre");
$segmentos = cockpit_segmentos();
$presets = [
    'Año a la fecha'   => [date('Y-01-01'), date('Y-m-d')],
    'Este mes'         => [date('Y-m-01'), date('Y-m-d')],
    'Mes pasado'       => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    // El año fiscal de la marca va de abril a marzo.
    'Año fiscal (abr–mar)' => [(date('n') >= 4 ? date('Y') : date('Y') - 1) . '-04-01', date('Y-m-d')],
];
?>
<div class="card p-4 mb-5 no-print">
  <form method="get" class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 items-end">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php if ($s = selectSucursalFiltro()): ?><div class="col-span-2 md:col-span-1"><span class="label">Sucursal</span><?= $s ?></div><?php endif; ?>
    <?php if ($t = selectTiendaFiltro()): ?><div class="col-span-2 md:col-span-1"><span class="label">Tienda</span><?= $t ?></div><?php endif; ?>
    <div class="col-span-2 md:col-span-1">
      <label class="label" for="ck_canal">Canal</label>
      <select id="ck_canal" name="canal" class="select">
        <option value="">Todos</option>
        <?php foreach (cockpit_canales() as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $f['canal'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-span-2 md:col-span-1">
      <label class="label" for="ck_marca">Marca del producto</label>
      <select id="ck_marca" name="marca_id" class="select">
        <option value="">Todas</option>
        <?php foreach ($marcas as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $f['marca'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-span-2 md:col-span-1">
      <label class="label" for="ck_seg">Segmento</label>
      <select id="ck_seg" name="segmento" class="select">
        <option value="">Todos</option>
        <?php foreach ($segmentos as $sg): ?><option value="<?= e($sg) ?>" <?= $f['segmento'] === $sg ? 'selected' : '' ?>><?= e($sg) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="ck_tyd">Este año (TY)</label>
      <input id="ck_tyd" type="date" name="ty_desde" value="<?= e($TY[0]) ?>" class="input">
    </div>
    <div>
      <label class="label" for="ck_tyh">&nbsp;<span class="sr-only">Hasta</span></label>
      <input id="ck_tyh" type="date" name="ty_hasta" value="<?= e($TY[1]) ?>" class="input" aria-label="Este año hasta">
    </div>
    <div>
      <label class="label" for="ck_lyd">Año anterior (LY)</label>
      <input id="ck_lyd" type="date" name="ly_desde" value="<?= e($LY[0]) ?>" class="input">
    </div>
    <div>
      <label class="label" for="ck_lyh">&nbsp;<span class="sr-only">Hasta</span></label>
      <input id="ck_lyh" type="date" name="ly_hasta" value="<?= e($LY[1]) ?>" class="input" aria-label="Año anterior hasta">
    </div>
    <label class="col-span-2 md:col-span-1 flex items-center gap-2 text-sm text-slate-600 min-h-[44px]" title="Solo las sucursales que vendieron en los dos periodos">
      <input type="checkbox" name="samestore" value="1" <?= $f['samestore'] ? 'checked' : '' ?> class="rounded border-slate-300 text-blue-600">
      Mismas tiendas
    </label>
    <div class="col-span-2 md:col-span-1 flex gap-2">
      <button class="btn btn-primary flex-1"><?= icon('filter', 'w-4 h-4') ?> Aplicar</button>
    </div>
  </form>
  <div class="flex flex-wrap items-center gap-1.5 mt-3 pt-3 border-t border-slate-100">
    <span class="text-xs text-slate-400 mr-1">Rápido:</span>
    <?php foreach ($presets as $lbl => [$d, $h]): ?>
      <a href="<?= e(ck_url(['ty_desde' => $d, 'ty_hasta' => $h, 'ly_desde' => null, 'ly_hasta' => null])) ?>"
         class="px-2.5 py-1 rounded-lg text-xs font-semibold <?= $TY === [$d, $h] ? 'bg-blue-50 text-blue-700' : 'text-slate-500 hover:bg-slate-100' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
    <span class="text-xs text-slate-400 ml-auto">El año anterior se toma, por defecto, en las mismas fechas.</span>
  </div>
</div>

<?php
// Honestidad con el histórico: antes de esta versión la venta no guardaba el
// precio de lista, así que el descuento de promoción de esas líneas no existe.
$sinLista = $gsTY > 0 ? (float) $totTY['sin_lista'] / $gsTY * 100 : 0.0;
$sinListaLY = $gsLY > 0 ? (float) $totLY['sin_lista'] / $gsLY * 100 : 0.0;
if ($sinLista > 0.5 || $sinListaLY > 0.5): ?>
  <div class="card p-4 mb-5 border-amber-200 bg-amber-50/60 flex gap-3 items-start">
    <span class="text-amber-600 shrink-0"><?= icon('alert', 'w-5 h-5') ?></span>
    <p class="text-sm text-amber-800">
      <strong><?= number_format($sinLista, 0) ?>%</strong> de la venta de este año<?= $sinListaLY > 0.5 ? ' y <strong>' . number_format($sinListaLY, 0) . '%</strong> de la del año anterior' : '' ?>
      se facturó antes de que el sistema guardara el precio de lista y la promoción aplicada. En esas ventas solo se ve el
      descuento hecho en caja: el de las promociones no se conoce y se cuenta como venta sin promoción. A partir de ahora
      cada venta queda registrada completa.
    </p>
  </div>
<?php endif; ?>

<?php if ($tab === 'resumen'):
  $porTipoTyPlano = array_map(fn($r) => $r['ty'], $filasTipo);
  $porTipoLyPlano = array_map(fn($r) => $r['ly'], $filasTipo);
  $pPromoTY = $gsTY > 0 ? $filaPromo['ty']['gs'] / $gsTY * 100 : 0.0;
  $pPromoLY = $gsLY > 0 ? $filaPromo['ly']['gs'] / $gsLY * 100 : 0.0;
  $dirTY = $directo($totTY, $porTipoTyPlano); $dirLY = $directo($totLY, $porTipoLyPlano);
  $regTY = $regalos($totTY, $porTipoTyPlano); $regLY = $regalos($totLY, $porTipoLyPlano);
?>
  <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 mb-5">
    <!-- Ventas y margen -->
    <section class="card p-4 xl:col-span-4">
      <p class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">Ventas y margen</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?= ck_tile('Venta bruta', ck_money($totTY['gs']), ck_money($totLY['gs']), ck_var($totTY['gs'], $totLY['gs'], false)) ?>
        <?= ck_tile('% Margen s/ venta bruta', cockpit_pct($totTY['margen_gs']), cockpit_pct($totLY['margen_gs']), ck_var($totTY['margen_gs'], $totLY['margen_gs'], true)) ?>
        <?= ck_tile('Venta neta', ck_money($totTY['ns']), ck_money($totLY['ns']), ck_var($totTY['ns'], $totLY['ns'], false)) ?>
        <?= ck_tile('% Margen s/ venta neta', cockpit_pct($totTY['margen_ns']), cockpit_pct($totLY['margen_ns']), ck_var($totTY['margen_ns'], $totLY['margen_ns'], true)) ?>
      </div>
    </section>
    <!-- Tasa de descuento y venta en promo, con su curva -->
    <section class="card p-4 xl:col-span-5">
      <p class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">Descuento mes a mes</p>
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-center">
          <div class="sm:col-span-2"><?= ck_tile('Tasa de descuento', cockpit_pct($totTY['desc_pct']), cockpit_pct($totLY['desc_pct']), ck_var($totTY['desc_pct'], $totLY['desc_pct'], true, true)) ?></div>
          <div class="sm:col-span-3">
            <?= lineChart([
                ['nombre' => 'Este año', 'color' => '#334155', 'valores' => $serie($menTY, $meses, 'desc')],
                ['nombre' => 'Año anterior', 'color' => '#cbd5e1', 'valores' => array_slice(array_pad($serie($menLY, $mesesLY, 'desc'), count($meses), 0), 0, count($meses))],
            ], $etqMeses, ['alto' => 300, 'formato' => 'pct', 'leyenda' => false]) ?>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-center">
          <div class="sm:col-span-2"><?= ck_tile('% Venta en promoción', cockpit_pct($pPromoTY), cockpit_pct($pPromoLY), ck_var($pPromoTY, $pPromoLY, true, true)) ?></div>
          <div class="sm:col-span-3">
            <?= lineChart([
                ['nombre' => 'Este año', 'color' => '#334155', 'valores' => $serie($menTY, $meses, 'promo')],
                ['nombre' => 'Año anterior', 'color' => '#cbd5e1', 'valores' => array_slice(array_pad($serie($menLY, $mesesLY, 'promo'), count($meses), 0), 0, count($meses))],
            ], $etqMeses, ['alto' => 300, 'formato' => 'pct']) ?>
          </div>
        </div>
      </div>
    </section>
    <!-- KPIs de descuento -->
    <section class="card p-4 xl:col-span-3">
      <p class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">Composición del descuento</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-1 gap-3">
        <?= ck_tile('% Descuento directo', cockpit_pct($dirTY), cockpit_pct($dirLY), ck_var($dirTY, $dirLY, true, true),
            '<span class="text-slate-300" title="Rebaja de precio (promoción, negociado y caja) sobre la venta bruta">' . icon('percent', 'w-4 h-4') . '</span>') ?>
        <?= ck_tile('% Regalos y muestras', cockpit_pct($regTY), cockpit_pct($regLY), ck_var($regTY, $regLY, true, true),
            '<span class="text-slate-300" title="Valor a precio de lista de lo entregado a RD$0, sobre la venta bruta">' . icon('tag', 'w-4 h-4') . '</span>') ?>
      </div>
    </section>
  </div>

  <?php $conLY = get('ver') === 'ly'; ?>
  <section class="card overflow-hidden mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-100">
      <div>
        <h3 class="font-bold text-slate-800">Análisis por tipo de descuento</h3>
        <p class="text-sm text-slate-400">Todo lo necesario para entender el impacto de los descuentos · efectos sobre el margen contra el año anterior</p>
      </div>
      <div class="flex items-center gap-1 p-1 bg-slate-100 rounded-xl no-print">
        <a href="<?= e(ck_url(['ver' => null])) ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold <?= !$conLY ? 'bg-slate-700 text-white' : 'text-slate-500' ?>">Solo este año</a>
        <a href="<?= e(ck_url(['ver' => 'ly'])) ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold <?= $conLY ? 'bg-slate-700 text-white' : 'text-slate-500' ?>">Con año anterior</a>
      </div>
    </div>
    <?php if ($gsTY <= 0 && $gsLY <= 0): ?>
      <div class="p-6"><?= empty_state('Sin ventas en estos periodos', 'Cambia las fechas o los filtros.', 'chart') ?></div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table text-[13px] whitespace-nowrap">
        <thead>
          <tr>
            <th>Tipo de descuento</th>
            <th class="text-right">Venta bruta</th><th class="text-right">% VB</th><th class="text-right">Margen % VB</th>
            <th class="text-right">Descuentos</th><th class="text-right">Desc %</th><th class="text-right">Venta neta</th>
            <th class="text-right">Costo</th><th class="text-right">Margen VN</th><th class="text-right">Margen % VN</th>
            <th class="text-right" title="Puntos de la venta bruta total que se van en descuentos de este tipo">Pts perdidos</th>
            <th class="text-right border-l border-slate-200" title="Vender más o menos en total">Ef. volumen</th>
            <th class="text-right" title="Descontar más o menos hondo dentro del tipo">Ef. tasa desc.</th>
            <th class="text-right" title="Más o menos peso de este tipo en la venta">Ef. mezcla</th>
            <th class="text-right" title="Vender artículos de otro costo dentro del tipo">Ef. mezcla producto</th>
            <th class="text-right">Efecto total</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $pinta = function (string $nombre, array $r, string $cls = '', string $color = '') use ($conLY) {
            $t = $r['ty'];
            $h = '<tr class="' . $cls . '"><td>'
               . ($color ? '<span class="inline-block w-2.5 h-2.5 rounded-full mr-2 align-middle" style="background:' . e($color) . '"></span>' : '')
               . e($nombre) . '</td>'
               . '<td class="text-right tabular-nums">' . cockpit_n($t['gs']) . '</td>'
               . '<td class="text-right tabular-nums text-slate-500">' . cockpit_pct($t['peso_gs']) . '</td>'
               . '<td class="text-right tabular-nums">' . cockpit_pct($t['margen_gs']) . '</td>'
               . '<td class="text-right tabular-nums">' . cockpit_n($t['desc']) . '</td>'
               . '<td class="text-right tabular-nums">' . cockpit_pct($t['desc_pct']) . '</td>'
               . '<td class="text-right tabular-nums font-semibold">' . cockpit_n($t['ns']) . '</td>'
               . '<td class="text-right tabular-nums text-slate-500">' . cockpit_n($t['costo']) . '</td>'
               . '<td class="text-right tabular-nums">' . cockpit_n($t['margen']) . '</td>'
               . '<td class="text-right tabular-nums">' . cockpit_pct($t['margen_ns']) . '</td>'
               . '<td class="text-right tabular-nums">' . number_format($t['pts'], 1) . ' pts</td>'
               . '<td class="text-right border-l border-slate-100">' . cockpit_celda_efecto($r['ef']['volumen']) . '</td>'
               . '<td class="text-right">' . cockpit_celda_efecto($r['ef']['tasa']) . '</td>'
               . '<td class="text-right">' . cockpit_celda_efecto($r['ef']['mezcla']) . '</td>'
               . '<td class="text-right">' . cockpit_celda_efecto($r['ef']['producto']) . '</td>'
               . '<td class="text-right font-bold">' . cockpit_celda_efecto($r['ef']['total']) . '</td></tr>';
            if ($conLY) {
                $l = $r['ly'];
                $h .= '<tr class="text-slate-400 text-xs bg-slate-50/60"><td class="pl-8">año anterior</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_n($l['gs']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_pct($l['peso_gs']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_pct($l['margen_gs']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_n($l['desc']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_pct($l['desc_pct']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_n($l['ns']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_n($l['costo']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_n($l['margen']) . '</td>'
                    . '<td class="text-right tabular-nums">' . cockpit_pct($l['margen_ns']) . '</td>'
                    . '<td class="text-right tabular-nums">' . number_format($l['pts'], 1) . ' pts</td>'
                    . '<td colspan="5" class="border-l border-slate-100"></td></tr>';
            }
            return $h;
        };
        echo $pinta('TOTAL', $filaTotal, 'font-bold bg-slate-50 text-slate-800');
        echo $pinta('Ventas sin promoción', $filaSin, 'font-semibold text-slate-700');
        echo $pinta('Ventas en promoción', $filaPromo, 'font-semibold text-slate-700 border-b-2 border-slate-200');
        foreach ($filasTipo as $k => $r) {
            if ($k === 'sin') continue;
            echo $pinta(cockpit_tipo_label($k), $r, 'text-slate-600', cockpit_tipo_color($k));
        }
        ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-400 px-4 py-3 border-t border-slate-100">
      Venta bruta = precio de catálogo × cantidad (una muestra cuenta a su precio real). Venta neta = lo facturado sin ITBIS, después
      de promociones y del descuento en caja. Los cuatro efectos suman exactamente la variación del margen del tipo contra el año anterior.
    </p>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'detalle'):
  $pGsTY = $pctPromo($promoTY, 'gs', $gsTY);  $pGsLY = $pctPromo($promoLY, 'gs', $gsLY);
  $pNsTY = $pctPromo($promoTY, 'ns', (float) $totTY['ns']); $pNsLY = $pctPromo($promoLY, 'ns', (float) $totLY['ns']);
  $avgTY = $stTY['tickets'] > 0 ? $stTY['n'] / $stTY['tickets'] : 0.0;
  $avgLY = $stLY['tickets'] > 0 ? $stLY['n'] / $stLY['tickets'] : 0.0;
  $serieAvg = function (array $st, array $ms) {
      return array_map(fn($ym) => isset($st['por_mes'][$ym]) && $st['por_mes'][$ym]['tickets'] > 0
          ? round($st['por_mes'][$ym]['n'] / $st['por_mes'][$ym]['tickets'], 2) : 0, $ms);
  };
?>
  <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 mb-5">
    <section class="card p-4 xl:col-span-4">
      <p class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">% en promoción (stacking)</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?= ck_tile('Venta bruta en promo', cockpit_pct($pGsTY), cockpit_pct($pGsLY), ck_var($pGsTY, $pGsLY, true)) ?>
        <?= ck_tile('Venta neta en promo', cockpit_pct($pNsTY), cockpit_pct($pNsLY), ck_var($pNsTY, $pNsLY, true)) ?>
      </div>
    </section>
    <section class="card p-4 xl:col-span-5">
      <p class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">Número de descuentos</p>
      <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-center">
        <div class="sm:col-span-2"><?= ck_tile('Promedio por factura', number_format($avgTY, 2), number_format($avgLY, 2), ck_var($avgTY, $avgLY, false, true)) ?></div>
        <div class="sm:col-span-3">
          <?= lineChart([
              ['nombre' => 'Este año', 'color' => '#334155', 'valores' => $serieAvg($stTY, $meses)],
              ['nombre' => 'Año anterior', 'color' => '#cbd5e1', 'valores' => array_slice(array_pad($serieAvg($stLY, $mesesLY), count($meses), 0), 0, count($meses))],
          ], $etqMeses, ['alto' => 300, 'formato' => 'dec']) ?>
        </div>
      </div>
      <p class="text-xs text-slate-400 mt-2">Entre las facturas con al menos un descuento: cada promoción distinta, las muestras, un precio negociado y el descuento en caja cuentan uno.</p>
    </section>
    <section class="card p-4 xl:col-span-3">
      <p class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">Distribución (% venta bruta)</p>
      <?php
      $maxD = 1.0;
      $dTY = []; $dLY = [];
      foreach ([1, 2, 3, 4, 5] as $n) {
          $dTY[$n] = $stTY['gs'] > 0 ? $stTY['dist'][$n] / $stTY['gs'] * 100 : 0;
          $dLY[$n] = $stLY['gs'] > 0 ? $stLY['dist'][$n] / $stLY['gs'] * 100 : 0;
          $maxD = max($maxD, $dTY[$n], $dLY[$n]);
      }
      ?>
      <div class="flex items-end gap-2 h-44" role="img" aria-label="Distribución de la venta bruta por número de descuentos">
        <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
          <div class="flex-1 flex flex-col items-center gap-1 h-full">
            <span class="text-[10.5px] font-semibold <?= $dTY[$n] - $dLY[$n] >= 0 ? 'text-slate-600' : 'text-rose-600' ?>"><?= e(cockpit_pts($dTY[$n] - $dLY[$n])) ?></span>
            <div class="flex-1 w-full flex items-end justify-center gap-0.5">
              <div class="w-1/2 max-w-[18px] rounded-t bg-slate-700" style="height:<?= max(1, $dTY[$n] / $maxD * 100) ?>%" title="Este año: <?= number_format($dTY[$n], 1) ?>%"></div>
              <div class="w-1/2 max-w-[18px] rounded-t bg-slate-300" style="height:<?= max(1, $dLY[$n] / $maxD * 100) ?>%" title="Año anterior: <?= number_format($dLY[$n], 1) ?>%"></div>
            </div>
            <span class="text-xs text-slate-500 font-semibold"><?= $n === 5 ? '5+' : $n ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-slate-400 mt-2">Descuentos por factura · oscuro: este año; claro: año anterior.</p>
    </section>
  </div>

  <section class="card overflow-hidden mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-100">
      <div>
        <h3 class="font-bold text-slate-800">Detalle por descuento</h3>
        <p class="text-sm text-slate-400">Cada promoción por su código, cada motivo de descuento en caja, las muestras y los precios negociados</p>
      </div>
      <div class="flex items-center gap-1 p-1 bg-slate-100 rounded-xl no-print">
        <a href="<?= e(ck_url(['vista' => null])) ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold <?= $vista === 'todas' ? 'bg-slate-700 text-white' : 'text-slate-500' ?>">Tabla completa</a>
        <a href="<?= e(ck_url(['vista' => 'menos'])) ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold <?= $vista === 'menos' ? 'bg-slate-700 text-white' : 'text-slate-500' ?>">30 menos activadas</a>
      </div>
    </div>
    <?php if (!$mec): ?>
      <div class="p-6"><?= empty_state('Ningún descuento en este periodo', 'No hubo facturas con promociones, muestras ni descuentos en caja.', 'percent',
          can('promociones.ver') ? '<a href="' . e(url('modules/marketing/promociones.php')) . '" class="btn btn-primary">' . icon('percent', 'w-4 h-4') . ' Ver promociones</a>' : '') ?></div>
    <?php else: ?>
      <?php if ($vista === 'menos'): ?>
        <p class="text-sm text-slate-500 px-4 pt-3">Incluye las promociones que estuvieron vigentes en el periodo y <strong>nadie usó</strong>: son las primeras candidatas a revisar o a retirar.</p>
      <?php endif; ?>
      <div class="overflow-x-auto">
        <table class="data-table text-[13px] whitespace-nowrap">
          <thead><tr>
            <th>Tipo</th><th>Descuento o promoción</th><th class="text-right">Activaciones</th><th class="text-right" title="Venta neta media de las facturas que lo usaron">Ticket medio</th>
            <th class="text-right">Venta bruta</th><th class="text-right">Margen % VB</th><th class="text-right">Peso VB</th>
            <th class="text-right">Descuentos</th><th class="text-right">Desc %</th><th class="text-right">Peso desc.</th>
            <th class="text-right">Venta neta</th><th class="text-right">Costo</th><th class="text-right">Margen % VN</th><th class="text-right">Pts perdidos</th>
          </tr></thead>
          <tbody>
          <?php foreach ($mec as $r): ?>
            <tr class="<?= $r['act'] == 0 ? 'bg-rose-50/40' : '' ?>">
              <td><span class="inline-block w-2 h-2 rounded-full mr-1.5 align-middle" style="background:<?= e(cockpit_tipo_color($r['tipo'])) ?>"></span><span class="text-slate-500"><?= e(cockpit_tipo_label($r['tipo'])) ?></span></td>
              <td class="max-w-[320px]"><p class="font-semibold text-slate-700 truncate"><?= e($r['nombre']) ?></p><?php if ($r['detalle']): ?><p class="text-xs text-slate-400"><?= e($r['detalle']) ?></p><?php endif; ?></td>
              <td class="text-right tabular-nums font-semibold"><?= number_format($r['act']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['atv_ticket']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['gs']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_pct($r['margen_gs']) ?></td>
              <td class="text-right tabular-nums text-slate-500"><?= cockpit_pct($r['peso_gs']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['desc']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_pct($r['desc_pct']) ?></td>
              <td class="text-right tabular-nums text-slate-500"><?= cockpit_pct($r['desc'] / $descTot * 100) ?></td>
              <td class="text-right tabular-nums font-semibold"><?= cockpit_n($r['ns']) ?></td>
              <td class="text-right tabular-nums text-slate-500"><?= cockpit_n($r['costo']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_pct($r['margen_ns']) ?></td>
              <td class="text-right tabular-nums"><?= number_format($r['pts'], 1) ?> pts</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'producto'):
  $h = cockpit_metricas($heroes['1'] ?? cockpit_vacio());
  $o = cockpit_metricas($heroes['0'] ?? cockpit_vacio());
  $descTot = max(0.01, $h['desc'] + $o['desc']);
  $gsTot = max(0.01, $h['gs'] + $o['gs']);
  $bloque = function (string $titulo, array $r) use ($gsTot, $descTot) {
      return '<div class="rounded-xl border border-slate-200 p-4">'
          . '<p class="text-sm font-bold text-slate-600 mb-3">' . e($titulo) . '</p>'
          . '<dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">'
          . '<dt class="text-slate-400">Venta bruta</dt><dt class="text-slate-400">% del total</dt>'
          . '<dd class="font-bold text-slate-800 tabular-nums">' . cockpit_n($r['gs']) . '</dd><dd class="font-bold text-slate-800 tabular-nums">' . cockpit_pct($r['gs'] / $gsTot * 100) . '</dd>'
          . '<dt class="text-slate-400">Descuentos</dt><dt class="text-slate-400">% del total</dt>'
          . '<dd class="font-bold text-slate-800 tabular-nums">' . cockpit_n($r['desc']) . '</dd><dd class="font-bold text-slate-800 tabular-nums">' . cockpit_pct($r['desc'] / $descTot * 100) . '</dd>'
          . '<dt class="text-slate-400">Desc %</dt><dt class="text-slate-400">Venta neta</dt>'
          . '<dd class="font-bold text-slate-800 tabular-nums">' . cockpit_pct($r['desc_pct']) . '</dd><dd class="font-bold text-slate-800 tabular-nums">' . cockpit_n($r['ns']) . '</dd>'
          . '</dl></div>';
  };
?>
  <div class="space-y-5 mb-5">
    <section class="card p-4">
      <div class="flex items-center justify-between gap-2 mb-3">
        <p class="text-xs font-bold uppercase tracking-wider text-amber-600">Foco en héroes</p>
        <?php if (can('productos.editar')): ?>
          <button type="button" onclick="window.dispatchEvent(new CustomEvent('ck:clasificar'))" class="btn btn-ghost btn-sm no-print"><?= icon('edit', 'w-3.5 h-3.5') ?> Clasificar</button>
        <?php endif; ?>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <?= $bloque('Productos héroe', $h) ?>
        <?= $bloque('Otros productos', $o) ?>
      </div>
      <?php if ($h['gs'] <= 0): ?>
        <p class="text-xs text-slate-400 mt-3">Ningún producto está marcado como héroe. Márcalos en la ficha del producto o con «Clasificar».</p>
      <?php endif; ?>
    </section>

    <section class="card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-100">
        <div>
          <h3 class="font-bold text-slate-800">Detalle por producto</h3>
          <p class="text-sm text-slate-400">Tipo de descuento → promoción → producto. Toca una fila para abrirla.</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600 no-print">
          <input type="checkbox" <?= $incluirSin ? 'checked' : '' ?> onchange="location.href=this.checked ? '<?= e(ck_url(['sin' => '1'])) ?>' : '<?= e(ck_url(['sin' => null])) ?>'" class="rounded border-slate-300 text-blue-600">
          Incluir venta sin promoción
        </label>
      </div>
      <?php if (!$arbol): ?>
        <div class="p-6"><?= empty_state('Sin productos con descuento', 'En este periodo ninguna línea llevó promoción, muestra ni descuento.', 'package') ?></div>
      <?php else: ?>
        <?php
        $celdas = fn(array $r, string $cls = '') => '<span class="' . $cls . ' grid grid-cols-6 gap-3 text-right tabular-nums text-[13px] min-w-[520px]">'
            . '<span>' . qty($r['qty']) . '</span><span>' . cockpit_n($r['costo']) . '</span><span>' . cockpit_n($r['gs']) . '</span>'
            . '<span>' . cockpit_n($r['gs'] - $r['ns']) . '</span><span>' . cockpit_pct($r['gs'] > 0 ? ($r['gs'] - $r['ns']) / $r['gs'] * 100 : 0) . '</span>'
            . '<span class="font-semibold">' . cockpit_n($r['ns']) . '</span></span>';
        ?>
        <div class="overflow-x-auto">
          <div class="min-w-[900px]">
            <div class="grid grid-cols-[minmax(0,1fr)_520px] gap-4 px-4 py-2.5 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500 border-b border-slate-100">
              <span>Tipo · descuento · producto</span>
              <span class="grid grid-cols-6 gap-3 text-right"><span>Cant.</span><span>Costo</span><span>V. bruta</span><span>Desc.</span><span>Desc %</span><span>V. neta</span></span>
            </div>
            <?php foreach ($arbol as $t => $nodo): ?>
              <details class="border-b border-slate-100 group">
                <summary class="grid grid-cols-[minmax(0,1fr)_520px] gap-4 px-4 py-3 cursor-pointer hover:bg-slate-50 font-bold text-slate-800 list-none">
                  <span class="flex items-center gap-2 min-w-0"><span class="text-slate-400 group-open:rotate-90 transition"><?= icon('chevron-right', 'w-4 h-4') ?></span>
                    <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:<?= e(cockpit_tipo_color($t)) ?>"></span><span class="truncate"><?= e(cockpit_tipo_label($t)) ?></span></span>
                  <?= $celdas($nodo['tot']) ?>
                </summary>
                <?php uasort($nodo['mec'], fn($a, $b) => $b['tot']['gs'] <=> $a['tot']['gs']);
                foreach ($nodo['mec'] as $m => $mn): ?>
                  <details class="group/m">
                    <summary class="grid grid-cols-[minmax(0,1fr)_520px] gap-4 pl-10 pr-4 py-2.5 cursor-pointer hover:bg-slate-50 font-semibold text-slate-700 list-none border-t border-slate-50">
                      <span class="flex items-center gap-2 min-w-0"><span class="text-slate-300 group-open/m:rotate-90 transition"><?= icon('chevron-right', 'w-3.5 h-3.5') ?></span><span class="truncate"><?= e($nombreMec($m)) ?></span></span>
                      <?= $celdas($mn['tot']) ?>
                    </summary>
                    <?php foreach ($mn['prod'] as $pr): ?>
                      <div class="grid grid-cols-[minmax(0,1fr)_520px] gap-4 pl-16 pr-4 py-2 text-slate-600 border-t border-slate-50">
                        <span class="truncate text-[13px]"><span class="font-mono text-xs text-slate-400"><?= e($pr['codigo']) ?></span> <?= e($pr['nombre']) ?></span>
                        <?= $celdas($pr, 'text-slate-500') ?>
                      </div>
                    <?php endforeach; ?>
                  </details>
                <?php endforeach; ?>
              </details>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>

<?php else: /* sell-out */ ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <?php foreach ($sell as $k => $dim):
      $tot = array_sum(array_column($dim, 'ty')); $totL = array_sum(array_column($dim, 'ly'));
      $dim = array_filter($dim, fn($v) => $v['ty'] > 0 || $v['ly'] > 0);
    ?>
      <div>
      <?= rep_seccion('Sell-out por ' . mb_strtolower($dims[$k][0]), 'Participación en la venta neta de este año y crecimiento contra el anterior', $dims[$k][1], 'amber') ?>
        <div class="px-5 pb-5 flex-1 flex flex-col justify-center">
          <?php if (!$dim || $tot <= 0): ?>
            <?= empty_state('Sin ventas', 'No hay venta en este periodo con los filtros elegidos.', 'chart') ?>
          <?php elseif ($k === 'sucursal' || $k === 'canal' || $k === 'tienda'): ?>
            <?php $items = []; $i = 0;
            foreach ($dim as $g => $v) $items[] = ['label' => $k === 'canal' ? ($canalesNombre[$g] ?? $g) : $g, 'value' => $v['ty'], 'color' => rep_color($i++)];
            echo donutMulti($items, 'Venta neta', numAbrev($tot)); ?>
            <div class="flex flex-wrap gap-2 mt-4">
              <?php foreach ($dim as $g => $v): $d = rep_delta($v['ty'], $v['ly']); ?>
                <span class="inline-flex items-center gap-1.5 text-xs bg-slate-50 rounded-lg px-2 py-1">
                  <span class="text-slate-500"><?= e($k === 'canal' ? ($canalesNombre[$g] ?? $g) : $g) ?></span>
                  <span class="font-semibold <?= $d === null ? 'text-slate-400' : ($d >= 0 ? 'text-emerald-600' : 'text-rose-600') ?>"><?= $d === null ? 'nuevo' : (($d >= 0 ? '+' : '−') . number_format(abs($d), 0) . '%') ?></span>
                </span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <?php $i = 0; foreach ($dim as $g => $v): $d = rep_delta($v['ty'], $v['ly']); $pct = $v['ty'] / $tot * 100; ?>
              <div class="flex items-center gap-3 mb-2.5">
                <span class="w-28 sm:w-36 shrink-0 text-sm text-slate-600 truncate text-right" title="<?= e($g) ?>"><?= e($g) ?></span>
                <div class="flex-1 h-6 bg-slate-50 rounded overflow-hidden">
                  <div class="h-full rounded" style="width:<?= max(0.8, $pct) ?>%;background:<?= e(rep_color($i++)) ?>"></div>
                </div>
                <span class="w-12 text-sm font-semibold text-slate-700 tabular-nums text-right"><?= number_format($pct, 0) ?>%</span>
                <span class="w-14 text-xs font-semibold text-center rounded px-1.5 py-0.5 <?= $d === null ? 'bg-slate-100 text-slate-400' : ($d >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700') ?>">
                  <?= $d === null ? 'nuevo' : (($d >= 0 ? '+' : '−') . number_format(abs($d), 0) . '%') ?>
                </span>
              </div>
            <?php endforeach; ?>
            <?php if (isset($dim['Sin segmento']) || isset($dim['Sin línea'])): ?>
              <p class="text-xs text-slate-400 mt-2">Los productos sin segmento o sin línea se clasifican en su ficha o con «Clasificar» en la pestaña Producto.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?= rep_fin() ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (can('productos.editar')): ?>
<!-- Clasificación masiva -->
<div x-data="{open:false}" @ck:clasificar.window="open=true" @keydown.escape.window="open=false">
  <div x-show="open" x-transition.opacity style="display:none" class="modal-overlay" @click.self="open=false">
    <div x-show="open" x-transition class="modal-panel bg-white rounded-2xl shadow-pop max-w-2xl" @click.stop>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="clasificar">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 class="font-bold text-slate-800">Clasificar productos</h3>
          <button type="button" @click="open=false" aria-label="Cerrar" class="text-slate-400 hover:text-slate-700 p-1 -m-1"><?= icon('x', 'w-5 h-5') ?></button>
        </div>
        <div class="p-6 space-y-3">
          <p class="text-sm text-slate-600">Pega desde Excel una fila por producto: <strong>SKU, segmento, línea, héroe</strong> (separados por tabulador, punto y coma o coma). En héroe, «sí», «x» o «1» lo marcan.</p>
          <textarea name="lineas" rows="10" class="input font-mono text-sm" placeholder="27OR030I24;Face;Immortelle;sí&#10;29HD250A15;Body;Almond;&#10;01CM030AA;Hand;Shea;x"></textarea>
          <p class="text-xs text-slate-400">Se busca por código o por código de barras. Lo que dejes vacío se borra de ese producto.</p>
        </div>
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100">
          <button type="button" @click="open=false" class="btn btn-ghost">Cancelar</button>
          <button class="btn btn-primary"><?= icon('save', 'w-4 h-4') ?> Guardar clasificación</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_end(); ?>
