<?php
/**
 * Promotion Cockpit.
 *
 * De lo global al detalle, en seis pasos:
 *   1. Resumen    venta bruta → descuentos → venta neta y margen, por tipo de
 *                 descuento, con los efectos sobre el margen contra el año pasado.
 *   2. Detallado  stacking (cuántos descuentos lleva cada factura) y el detalle
 *                 promoción por promoción, con las menos activadas.
 *   3. Producto   productos héroe contra el resto y el detalle tipo → promoción → producto.
 *   4. Sell-out   participación y crecimiento por sucursal, canal, segmento y línea.
 *   5. Efectividad  cada promoción contra sus días previos: aumento, margen incremental.
 *   6. Simulador  una promoción que aún no existe: margen y punto de equilibrio.
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
    $uid = (int) (current_user()['id'] ?? 0);
    // Vistas guardadas: cada quien las suyas; compartirlas no da permiso a borrarlas.
    if (post('accion') === 'guardar_vista' && cockpit_vistas_disponible()) {
        $nombre = trim(mb_substr((string) post('nombre'), 0, 80));
        $q = cockpit_vista_query($_GET);
        if ($nombre === '') {
            flash('warning', 'Ponle un nombre a la vista.');
        } elseif (strlen($q) > 1000) {   // la columna es VARCHAR(1000)
            flash('warning', 'Esta vista tiene demasiados filtros para guardarse. Quita alguno e inténtalo de nuevo.');
        } else {
            $existe = (int) qVal("SELECT id FROM cockpit_vistas WHERE usuario_id = ? AND nombre = ?", [$uid, $nombre]);
            $datos = ['query' => $q, 'compartida' => post('compartida') ? 1 : 0];
            $existe ? dbUpdate('cockpit_vistas', $datos, 'id = ?', [$existe])
                    : dbInsert('cockpit_vistas', $datos + ['usuario_id' => $uid, 'nombre' => $nombre]);
            flash('success', $existe ? "Vista «{$nombre}» actualizada." : "Vista «{$nombre}» guardada.");
        }
        redirect('modules/marketing/cockpit.php?' . http_build_query(array_diff_key($_GET, ['export' => 1])));
    }
    if (post('accion') === 'frecuencia_vista' && cockpit_resumen_disponible()) {
        $frec = array_key_exists((string) post('frecuencia'), cockpit_resumen_frecuencias()) ? (string) post('frecuencia') : '';
        // Al activarlo no se manda el periodo que ya cerró: el primero llega en el próximo cierre.
        $per = cockpit_resumen_periodo($frec);
        q("UPDATE cockpit_vistas SET frecuencia = ?, ultimo_periodo = ? WHERE id = ? AND usuario_id = ?",
          [$frec, $per[1] ?? null, (int) post('id'), $uid]);
        flash('success', $frec === '' ? 'Ya no recibirás el resumen de esa vista.'
            : 'Recibirás el resumen ' . ($frec === 'semanal' ? 'cada lunes' : 'cada día 1') . ' en ' . (current_user()['email'] ?? 'tu correo') . '.');
        redirect('modules/marketing/cockpit.php?' . http_build_query(array_diff_key($_GET, ['export' => 1])));
    }
    if (post('accion') === 'borrar_vista' && cockpit_vistas_disponible()) {
        q("DELETE FROM cockpit_vistas WHERE id = ? AND usuario_id = ?", [(int) post('id'), $uid]);
        flash('success', 'Vista borrada.');
        redirect('modules/marketing/cockpit.php?' . http_build_query(array_diff_key($_GET, ['export' => 1])));
    }
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

// Vista previa del correo de una vista propia (el último periodo cerrado).
if (get('vista_correo') !== '' && cockpit_resumen_disponible()) {
    $v = qOne("SELECT * FROM cockpit_vistas WHERE id = ? AND usuario_id = ?", [(int) get('vista_correo'), (int) (current_user()['id'] ?? 0)]);
    $u = $v ? cockpit_resumen_dueno((int) $v['usuario_id']) : null;
    if (!$v || !$u) { http_response_code(404); exit('Vista no encontrada.'); }
    if ($v['frecuencia'] === '') $v['frecuencia'] = 'semanal';
    [, $html] = cockpit_resumen_correo($v, $u, cockpit_resumen_periodo($v['frecuencia']));
    header('Content-Type: text/html; charset=utf-8');
    exit($html);
}

/* ============================================================
 *  Filtros y datos comunes
 * ============================================================ */
$tabs = ['resumen' => 'Resumen', 'detalle' => 'Detallado', 'producto' => 'Producto', 'sellout' => 'Sell-out',
         'efectividad' => 'Efectividad', 'simulador' => 'Simulador'];
$tab = array_key_exists((string) get('tab'), $tabs) ? (string) get('tab') : 'resumen';
$f = cockpit_filtros();
$TY = $f['ty']; $LY = $f['ly'];
$tipos = cockpit_tipos();
$x = cockpit_expr();

// En el resumen los totales son la suma de los tipos, que ya se consultan:
// dos barridos del periodo menos (≈0,4 s con 60.000 ventas).
if ($tab === 'resumen') {
    $res = cockpit_resumen_datos($f);
    ['tot_ty' => $totTY, 'tot_ly' => $totLY] = $res;
} else {
    $totTY = cockpit_metricas(cockpit_totales($f, $TY));
    $totLY = cockpit_metricas(cockpit_totales($f, $LY));
}
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
    return e(cockpit_moneda()[1]) . ' ' . cockpit_n($v);
}

// Los gráficos escriben sus importes en la moneda elegida.
$GLOBALS['graficos_moneda'] = cockpit_moneda()[1];

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
    ['filas' => $filasTipo, 'total' => $filaTotal, 'sin' => $filaSin, 'promo' => $filaPromo] = $res;

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
    // Llegar desde un gráfico del resumen deja la tabla filtrada por ese tipo.
    $tipoFiltro = array_key_exists((string) get('tipo'), cockpit_tipos()) ? (string) get('tipo') : null;
    $mecTodos = $mec;
    if ($tipoFiltro) $mec = array_filter($mec, fn($r) => $r['tipo'] === $tipoFiltro);
    $descTot = max(0.01, $gsTY - (float) $totTY['ns']);
    if ($vista === 'menos') {
        uasort($mec, fn($a, $b) => [$a['act'], $a['gs']] <=> [$b['act'], $b['gs']]);
        $mec = array_slice($mec, 0, cockpit_param_int('menos_activadas'), true);
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
        // Alias k_*: «tipo» es también columna de productos y de promociones, y
        // en un GROUP BY la columna le gana al alias.
        "SELECT {$x['tipo']} k_tipo, {$x['mecanismo']} k_mec, vd.producto_id k_pid,
                MAX(COALESCE(pr.codigo,'')) codigo, MAX(COALESCE(pr.nombre, vd.descripcion)) nombre,
                SUM(vd.cantidad) qty, SUM({$x['costo']}) costo, SUM({$x['gs']}) gs, SUM({$x['ns']}) ns
           " . cockpit_from() . " WHERE $w" . ($incluirSin ? '' : " AND {$x['tipo']} <> 'sin'") . "
          GROUP BY k_tipo, k_mec, k_pid ORDER BY gs DESC",
        $p
    );
    $rows = array_map(fn($r) => ['tipo' => $r['k_tipo'], 'mec' => $r['k_mec'], 'pid' => $r['k_pid']] + $r, $rows);
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
        'sucursal' => ['Sucursal', 'store'],
        'canal'    => ['Canal', 'megaphone'],
        'segmento' => ['Segmento', 'layers'],
        'linea'    => ['Línea', 'tag'],
    ];
    if (tiendas_hay()) $dims['tienda'] = ['Tienda (marca)', 'tag'];
    // Una sola pasada por periodo; cada dimensión se reparte en PHP.
    $cuboTY = cockpit_cubo($f, $TY);
    $cuboLY = cockpit_cubo($f, $LY);
    $sell = [];
    foreach (array_keys($dims) as $k) {
        $a = cockpit_cubo_por($cuboTY, $k);
        $b = cockpit_cubo_por($cuboLY, $k);
        $filas = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $g) {
            $filas[$g] = ['ty' => $a[$g] ?? 0.0, 'ly' => $b[$g] ?? 0.0];
        }
        uasort($filas, fn($p, $q) => $q['ty'] <=> $p['ty']);
        $sell[$k] = $filas;
    }
    $canalesNombre = cockpit_canales();
    $segLin = cockpit_cubo_por($cuboTY, 'segmento', 'linea');
    $menTY = array_map(fn($ns) => ['ns' => $ns], cockpit_cubo_por($cuboTY, 'ym'));
    $menLY = array_map(fn($ns) => ['ns' => $ns], cockpit_cubo_por($cuboLY, 'ym'));
}

/* ============================================================
 *  Exportación (la tabla principal de la pestaña)
 * ============================================================ */
if ($tab === 'efectividad') {
    $efec = cockpit_efectividad($f, $TY);
    // Primero las que se pueden juzgar; dentro de cada grupo, las que más descuento regalaron.
    usort($efec, fn($a, $b) => [$a['margen_incremental'] === null, -$a['costo_desc']] <=> [$b['margen_incremental'] === null, -$b['costo_desc']]);
    $cliPromo = cockpit_clientes_promo($f, $TY);
}

if ($tab === 'simulador') {
    // La promoción que se quiere probar vive en la URL, como el resto de filtros.
    $simAlcances = ['todos' => 'Todo el catálogo', 'categoria' => 'Una categoría', 'marca' => 'Una marca', 'segmento' => 'Un segmento', 'linea' => 'Una línea', 'producto' => 'Un producto'];
    $sim = [
        'alcance'   => array_key_exists((string) get('s_alcance'), $simAlcances) ? (string) get('s_alcance') : 'todos',
        'objetivo'  => trim((string) get('s_obj')),
        'tipo'      => get('s_tipo') === 'monto' ? 'monto' : 'porcentaje',
        'valor'     => max(0.0, (float) get('s_valor', 20)),
        'dias'      => min(120, max(1, (int) (get('s_dias') ?: 14))),
        'dias_base' => min(90, max(7, (int) (get('s_base') ?: 28))),
    ];
    if ($sim['tipo'] === 'porcentaje') $sim['valor'] = min(100.0, $sim['valor']);
    // El producto se busca por código: nadie se sabe el id.
    $simProducto = null;
    if ($sim['alcance'] === 'producto') {
        $simProducto = $sim['objetivo'] !== '' ? qOne("SELECT id, codigo, nombre FROM productos WHERE codigo = ? OR codigo_barras = ? LIMIT 1", [$sim['objetivo'], $sim['objetivo']]) : null;
        $sim['objetivo_id'] = $simProducto['id'] ?? 0;
    }
    $simListo = $sim['alcance'] === 'todos' || ($sim['alcance'] === 'producto' ? $simProducto !== null : $sim['objetivo'] !== '');
    $simRes = $simHist = null; $simEsc = [];
    if ($simListo) {
        $cfgSim = $sim + ['aumento' => 0];
        $cfgSim['objetivo'] = $sim['alcance'] === 'producto' ? $sim['objetivo_id'] : $sim['objetivo'];
        // Primera pasada sin aumento: da la profundidad real para buscar promociones parecidas.
        $simRes = cockpit_simular($f, $cfgSim);
        $simHist = cockpit_aumento_historico($f, $simRes['profundidad']);
        $aumentoGet = get('s_aum');
        $sim['aumento'] = $aumentoGet !== null && $aumentoGet !== '' ? max(-90.0, min(500.0, (float) $aumentoGet))
            : ($simHist ? round($simHist['aumento']) : 0.0);
        $sim['aumento_propuesto'] = $aumentoGet === null || $aumentoGet === '';
        // Solo cambia el aumento: se recalcula sobre las mismas filas, sin volver a la base.
        if ($sim['aumento'] != 0) {
            $simRes = array_merge($simRes, cockpit_sim_calcular($simRes['filas'], ['aumento' => $sim['aumento']] + $cfgSim, $simRes['base'][2], $simRes['divisor']));
        }
        // La misma promoción, más suave y más fuerte.
        if ($simRes['productos']) {
            $valores = $sim['tipo'] === 'porcentaje' ? [10, 15, 20, 25, 30, 40] : array_map(fn($k) => round($sim['valor'] * $k, 2), [0.5, 0.75, 1.25, 1.5, 2]);
            $valores = array_values(array_unique(array_merge($valores, [$sim['valor']])));
            sort($valores);
            $simEsc = cockpit_sim_escenarios($simRes, $cfgSim, $valores, function (?float $prof) use ($f, $sim) {
                $h = cockpit_aumento_historico($f, $prof);
                return $h && $h['parecidas'] ? [round($h['aumento']), true] : [(float) $sim['aumento'], false];
            });
        }
    }
}

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
    if ($tab === 'efectividad') {
        $filas = [];
        foreach ($efec as $r) {
            $filas[] = [$r['nombre'], cockpit_tipo_label($r['tipo']), $r['ventana'][0] . ' a ' . $r['ventana'][1], $r['dias'], $r['productos'],
                $r['ud_dia_base'] === null ? '—' : $n2($r['ud_dia_base']), $n2($r['ud_dia']), $r['aumento'] === null ? '—' : $p1($r['aumento']),
                $r['margen_incremental'] === null ? '—' : $n2($r['margen_incremental']), $n2($r['costo_desc']),
                $r['retorno'] === null ? '—' : $n2($r['retorno']), $r['veredicto'][1] . ($r['motivo'] ? ' (' . $r['motivo'] . ')' : '')];
        }
        export_tabla('cockpit_efectividad_' . $sufijo,
            ['Promoción', 'Tipo', 'Vigencia analizada', 'Días', 'Productos', 'Unid./día antes', 'Unid./día durante', 'Aumento %',
             'Margen incremental', 'Costo del descuento', 'Retorno', 'Veredicto'],
            $filas, 'Promotion Cockpit — efectividad de cada promoción');
    }
    if ($tab === 'simulador') {
        $filas = [];
        foreach ($simRes['productos'] ?? [] as $r) {
            $filas[] = [$r['codigo'], $r['nombre'], $n2($r['u_dia']), $n2($r['lista']), $n2($r['p0']), $n2($r['p1']), $n2($r['c']), $n2($r['mu0']), $n2($r['mu1'])];
        }
        export_tabla('cockpit_simulador_' . date('Y-m-d'),
            ['SKU', 'Producto', 'Unid./día (base)', 'Precio de lista', 'Precio cobrado hoy', 'Precio con la promoción', 'Costo', 'Margen por unidad hoy', 'Margen por unidad con promo'],
            $filas, 'Promotion Cockpit — simulación de promoción');
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
// Copiar la vista: los filtros viven en la URL, así que compartir el enlace
// es compartir exactamente lo que uno está mirando.
$copiar = '<button type="button" x-data="{ok:false}" @click="navigator.clipboard.writeText(location.href).then(() => { ok = true; setTimeout(() => ok = false, 1800); })"'
    . ' class="btn btn-ghost no-print" title="Copia el enlace con todos los filtros">' . icon('file', 'w-4 h-4')
    . ' <span x-text="ok ? \'¡Copiado!\' : \'Copiar enlace\'">Copiar enlace</span></button>';
// Vistas guardadas: el mismo enlace, con nombre y sin tener que guardarlo en otro lado.
$vistasBtn = '';
if (cockpit_vistas_disponible()) {
    $uidV = (int) (current_user()['id'] ?? 0);
    $vistas = cockpit_vistas($uidV);
    $qActual = cockpit_vista_query($_GET);
    $conCorreo = cockpit_resumen_disponible();
    ob_start(); ?>
    <div class="relative no-print" x-data="{open:false, guardar:false}" @keydown.escape.window="open=false" @click.outside="open=false">
      <button type="button" class="btn btn-ghost" @click="open=!open" :aria-expanded="open.toString()"><?= icon('list', 'w-4 h-4') ?> Vistas<?= $vistas ? ' <span class="badge badge-blue">' . count($vistas) . '</span>' : '' ?></button>
      <div x-show="open" x-transition x-cloak class="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-pop border border-slate-200 z-40 p-2">
        <?php if (!$vistas): ?><p class="text-sm text-slate-400 px-2 py-3">Aún no hay vistas. Guarda esta combinación de pestaña y filtros para volver con un clic.</p><?php endif; ?>
        <ul class="max-h-72 overflow-y-auto">
          <?php foreach ($vistas as $v): $esMia = (int) $v['usuario_id'] === $uidV; ?>
            <li class="flex items-center gap-1 rounded-lg <?= $v['query'] === $qActual ? 'bg-blue-50' : 'hover:bg-slate-50' ?>">
              <div class="flex-1 min-w-0">
                <a href="<?= e(url('modules/marketing/cockpit.php') . '?' . $v['query']) ?>" class="block px-2 pt-2 <?= $esMia && $conCorreo ? 'pb-0.5' : 'pb-2' ?>">
                  <span class="block text-sm font-semibold text-slate-700 truncate"><?= e($v['nombre']) ?></span>
                  <span class="block text-xs text-slate-400 truncate"><?= $esMia ? ($v['compartida'] ? 'Tuya · compartida' : 'Tuya') : 'De ' . e($v['autor'] ?? '—') ?></span>
                </a>
                <?php if ($esMia && $conCorreo): ?>
                <form method="post" class="flex items-center gap-1.5 px-2 pb-2">
                  <?= csrf_field() ?><input type="hidden" name="accion" value="frecuencia_vista"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                  <?= icon('mail', 'w-3.5 h-3.5 text-slate-400 shrink-0') ?>
                  <select name="frecuencia" onchange="this.form.submit()" class="text-xs border-0 bg-transparent p-0 pr-5 text-slate-500 focus:ring-0 cursor-pointer" aria-label="Resumen por correo de <?= e($v['nombre']) ?>">
                    <?php foreach (cockpit_resumen_frecuencias() as $fk => $fl): ?><option value="<?= e($fk) ?>" <?= ($v['frecuencia'] ?? '') === $fk ? 'selected' : '' ?>><?= e($fk === '' ? 'Sin resumen por correo' : 'Correo: ' . mb_strtolower($fl)) ?></option><?php endforeach; ?>
                  </select>
                  <a href="?vista_correo=<?= (int) $v['id'] ?>" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline ml-auto shrink-0">Ver correo</a>
                </form>
                <?php endif; ?>
              </div>
              <?php if ($esMia): ?>
              <form method="post" onsubmit="return confirm('¿Borrar la vista «' + <?= e(json_encode($v['nombre'])) ?> + '»?')">
                <?= csrf_field() ?><input type="hidden" name="accion" value="borrar_vista"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                <button class="p-2 text-slate-300 hover:text-rose-600" aria-label="Borrar vista <?= e($v['nombre']) ?>"><?= icon('trash', 'w-4 h-4') ?></button>
              </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="border-t border-slate-100 mt-2 pt-2">
          <button type="button" x-show="!guardar" @click="guardar=true; $nextTick(() => $refs.nv.focus())" class="w-full text-left px-2 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 rounded-lg"><?= icon('plus', 'w-4 h-4 inline') ?> Guardar la vista actual</button>
          <form method="post" x-show="guardar" x-cloak class="space-y-2 p-1">
            <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_vista">
            <input x-ref="nv" name="nombre" maxlength="80" required class="input" placeholder="Ej. Black Friday · retail · USD" aria-label="Nombre de la vista">
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="compartida" value="1" class="rounded border-slate-300 text-blue-600"> Compartir con el equipo</label>
            <p class="text-xs text-slate-400">Si el periodo es uno de los rápidos (este mes, mes pasado…), se guarda el periodo y no las fechas: siempre abre actualizado. Con el mismo nombre, se reemplaza.</p>
            <button class="btn btn-primary w-full"><?= icon('save', 'w-4 h-4') ?> Guardar</button>
          </form>
        </div>
      </div>
    </div>
    <?php $vistasBtn = ob_get_clean();
}
$acciones = $vistasBtn . $copiar . rep_barra_titulo(can('cockpit.configurar')
    ? '<a href="' . e(url('modules/marketing/cockpit_config.php')) . '" class="btn btn-ghost no-print">' . icon('settings', 'w-4 h-4') . ' Configurar</a>' : '');
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
$presets = array_map(fn($p) => $p[1], array_column(cockpit_presets(), null, 0));
?>
<?php
$nFiltros = count(array_filter([get('sucursal_id'), get('tienda_id'), $f['canal'], $f['marca'], $f['segmento'], $f['linea'] ?? null, $f['samestore'] ? 1 : null]));
?>
<div class="card p-4 mb-5 no-print" x-data="{abierto: window.matchMedia('(min-width: 768px)').matches}">
  <!-- En el teléfono los filtros se pliegan: abiertos se comían media pantalla antes del primer número. -->
  <button type="button" @click="abierto=!abierto" class="md:hidden w-full flex items-center justify-between gap-2 min-h-[44px] text-sm font-semibold text-slate-700" :aria-expanded="abierto.toString()">
    <span class="inline-flex items-center gap-2"><?= icon('filter', 'w-4 h-4') ?> Filtros<?= $nFiltros ? ' <span class="badge badge-blue">' . $nFiltros . '</span>' : '' ?>
      <span class="text-xs font-normal text-slate-400"><?= e(fechaCorta($TY[0]) . ' – ' . fechaCorta($TY[1])) ?></span></span>
    <span class="transition" :class="abierto && 'rotate-180'"><?= icon('chevron-down', 'w-4 h-4') ?></span>
  </button>
  <div x-show="abierto" x-cloak class="mt-3 md:mt-0">
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
    <div class="col-span-2 md:col-span-1">
      <label class="label" for="ck_lin">Línea</label>
      <select id="ck_lin" name="linea" class="select">
        <option value="">Todas</option>
        <?php foreach (qCol("SELECT DISTINCT COALESCE(NULLIF(linea,''), 'Sin línea') l FROM productos WHERE activo = 1 ORDER BY l") as $ln): ?><option value="<?= e($ln) ?>" <?= ($f['linea'] ?? null) === $ln ? 'selected' : '' ?>><?= e($ln) ?></option><?php endforeach; ?>
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
      <input type="hidden" name="ly_manual" id="ck_lyman" value="<?= $f['ly_manual'] ? '1' : '0' ?>">
      <input id="ck_lyd" type="date" name="ly_desde" value="<?= e($LY[0]) ?>" class="input" oninput="document.getElementById('ck_lyman').value='1'">
    </div>
    <div>
      <label class="label" for="ck_lyh">&nbsp;<span class="sr-only">Hasta</span></label>
      <input id="ck_lyh" type="date" name="ly_hasta" value="<?= e($LY[1]) ?>" class="input" aria-label="Año anterior hasta" oninput="document.getElementById('ck_lyman').value='1'">
    </div>
    <div class="col-span-2 md:col-span-1">
      <label class="label" for="ck_lym">Comparar con</label>
      <select id="ck_lym" name="ly_modo" class="select" onchange="document.getElementById('ck_lyman').value='0'">
        <option value="fecha" <?= $f['ly_modo'] === 'fecha' ? 'selected' : '' ?>>Mismas fechas</option>
        <option value="semana" <?= $f['ly_modo'] === 'semana' ? 'selected' : '' ?>>Mismo día de la semana</option>
      </select>
    </div>
    <?php if (count(cockpit_monedas()) > 1): ?>
    <div class="col-span-2 md:col-span-1">
      <label class="label" for="ck_mon">Moneda</label>
      <select id="ck_mon" name="moneda" class="select">
        <?php foreach (cockpit_monedas() as $cod => [$monS, $tasa, $nota]): ?>
          <option value="<?= e($cod) ?>" <?= cockpit_moneda()[0] === $cod ? 'selected' : '' ?>><?= e($cod === '' ? $monS . ' (base)' : $cod . ' · ' . $monS) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
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
    <?php foreach (cockpit_presets() as $pk => [$lbl, [$d, $h]]): ?>
      <a href="<?= e(ck_url(['ty_desde' => $d, 'ty_hasta' => $h, 'ly_desde' => null, 'ly_hasta' => null, 'ly_manual' => null, 'periodo' => $pk])) ?>"
         class="px-2.5 py-1 rounded-lg text-xs font-semibold <?= $TY === [$d, $h] ? 'bg-blue-50 text-blue-700' : 'text-slate-500 hover:bg-slate-100' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
    <span class="text-xs text-slate-400 ml-auto"><?= $f['ly_modo'] === 'semana'
        ? 'El año anterior se toma 52 semanas antes: cada día contra el mismo día de la semana.'
        : 'El año anterior se toma, por defecto, en las mismas fechas.' ?></span>
  </div>
  </div>
</div>

<?php [$monCod, $monSim, $monTasa, $monNota] = cockpit_moneda(); if ($monCod !== ''): ?>
  <div class="card p-3 mb-5 flex flex-wrap items-center gap-2 text-sm text-slate-600">
    <?= icon('coins', 'w-4 h-4 text-slate-400') ?>
    Cifras en <strong><?= e($monCod) ?></strong> a <strong><?= e(setting('moneda', 'RD$')) ?> <?= e(number_format($monTasa, 2)) ?></strong> por <?= e($monSim) ?> (<?= e($monNota) ?>).
    Los porcentajes no cambian con la moneda.
    <a href="<?= e(ck_url(['moneda' => null])) ?>" class="ml-auto text-blue-600 hover:underline">Ver en <?= e(setting('moneda', 'RD$')) ?></a>
  </div>
<?php endif; ?>

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
  <?php
  $hallazgos = cockpit_hallazgos($filaTotal, $filasTipo, $pPromoTY, $pPromoLY);
  $sinUso = cockpit_promos_sin_uso($TY[0], $TY[1]);
  if ($sinUso) {
      $nombresSU = array_map(fn($p) => ($p['codigo'] ? $p['codigo'] . ' ' : '') . $p['nombre'], array_slice($sinUso, 0, 3));
      array_unshift($hallazgos, ['tono' => 'malo', 'tipo' => null, 'peso' => 99, 'url' => ck_url(['tab' => 'detalle', 'vista' => 'menos']),
          'texto' => '<b>' . count($sinUso) . ' promoción(es) vigente(s) que nadie usó</b> en el periodo: ' . e(implode(', ', $nombresSU)) . (count($sinUso) > 3 ? '…' : '') . '.']);
  }
  $tonos = ['bueno' => ['text-emerald-600 bg-emerald-50', 'arrow-up'], 'malo' => ['text-rose-600 bg-rose-50', 'alert'], 'neutro' => ['text-blue-600 bg-blue-50', 'target']];
  ?>
  <?php if ($hallazgos): ?>
  <section class="card p-4 mb-5" aria-labelledby="hallazgos_t">
    <h3 id="hallazgos_t" class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">Lo más importante</h3>
    <ul class="grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-2.5">
      <?php foreach ($hallazgos as $hz):
        [$cls, $ico] = $tonos[$hz['tono']];
        $url = $hz['url'] ?? ($hz['tipo'] ? ck_url(['tab' => 'detalle', 'tipo' => $hz['tipo']]) : null); ?>
        <li class="flex items-start gap-3 text-sm text-slate-600">
          <span class="w-7 h-7 rounded-lg <?= $cls ?> flex items-center justify-center shrink-0" aria-hidden="true"><?= icon($ico, 'w-3.5 h-3.5') ?></span>
          <span class="pt-1"><?= $hz['texto'] ?><?php if ($url): ?> <a href="<?= e($url) ?>" class="text-blue-600 hover:underline whitespace-nowrap">Ver detalle →</a><?php endif; ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
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
            <?= grafico_lineas_ty_ly($etqMeses, $serie($menTY, $meses, 'desc'),
                array_slice(array_pad($serie($menLY, $mesesLY, 'desc'), count($meses), 0), 0, count($meses)),
                ['formato' => 'pct', 'titulo' => 'Tasa de descuento mes a mes', 'escala' => true, 'herramientas' => false], '190px') ?>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-center">
          <div class="sm:col-span-2"><?= ck_tile('% Venta en promoción', cockpit_pct($pPromoTY), cockpit_pct($pPromoLY), ck_var($pPromoTY, $pPromoLY, true, true)) ?></div>
          <div class="sm:col-span-3">
            <?= grafico_lineas_ty_ly($etqMeses, $serie($menTY, $meses, 'promo'),
                array_slice(array_pad($serie($menLY, $mesesLY, 'promo'), count($meses), 0), 0, count($meses)),
                ['formato' => 'pct', 'titulo' => 'Venta en promoción mes a mes', 'escala' => true, 'herramientas' => false], '190px') ?>
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

  <?php
  // ---------- Gráficos interactivos ----------
  $mTY = $filaTotal['ty']['margen']; $mLY = $filaTotal['ly']['margen']; $ef = $filaTotal['ef'];
  $tiposGraf = array_filter($filasTipo, fn($r, $k) => $k !== 'sin' && ($r['ty']['desc'] > 0 || $r['ly']['desc'] > 0), ARRAY_FILTER_USE_BOTH);
  uasort($tiposGraf, fn($a, $b) => $b['ty']['desc'] <=> $a['ty']['desc']);
  $drill = fn(string $k) => ck_url(['tab' => 'detalle', 'tipo' => $k, 'ver' => null]);
  $gsMes = array_map(fn($ym) => round($menTY[$ym]['gs'] ?? 0), $meses);
  $gsMesLY = array_slice(array_pad(array_map(fn($ym) => round($menLY[$ym]['gs'] ?? 0), $mesesLY), count($meses), 0), 0, count($meses));
  ?>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-5">
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Puente del margen</h3>
      <p class="text-sm text-slate-400 mb-2">De margen del año anterior a margen de este año: qué sumó y qué restó</p>
      <?= grafico_cascada('Margen año ant.', $mLY, [
          ['Volumen', $ef['volumen']], ['Mezcla', $ef['mezcla']], ['Tasa de descuento', $ef['tasa']], ['Mezcla de producto', $ef['producto']],
      ], 'Margen este año', $mTY, ['formato' => 'money0', 'titulo' => 'Puente del margen'], '320px') ?>
    </section>
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Venta bruta mes a mes</h3>
      <p class="text-sm text-slate-400 mb-2">Arrastra para acercar un tramo · cambia a líneas con la caja de herramientas</p>
      <?= grafico_ty_ly($etqMeses, $gsMes, $gsMesLY, ['formato' => 'money0', 'titulo' => 'Venta bruta mes a mes', 'tipos' => ['line', 'bar']], '320px',
          count($meses) > 12 ? ['dataZoom' => [['type' => 'inside'], ['type' => 'slider', 'height' => 22, 'bottom' => 4]]] : []) ?>
    </section>
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Cuánto cuesta cada tipo de descuento</h3>
      <p class="text-sm text-slate-400 mb-2">Descuento en <?= e(cockpit_moneda()[1]) ?> · toca una barra para ver sus promociones</p>
      <?php
      $cats = array_map(fn($k) => cockpit_tipo_label($k), array_keys($tiposGraf));
      $dTy = []; $dLy = [];
      foreach ($tiposGraf as $k => $r) {
          $dTy[] = ['value' => round($r['ty']['desc']), 'url' => $drill($k), 'itemStyle' => ['color' => cockpit_tipo_color($k)]];
          $dLy[] = ['value' => round($r['ly']['desc']), 'url' => $drill($k)];
      }
      echo $tiposGraf
          ? grafico_ty_ly($cats, $dTy, $dLy, ['formato' => 'money0', 'titulo' => 'Descuento por tipo', 'horizontal' => true], max(260, 38 * count($cats) + 60) . 'px')
          : empty_state('Sin descuentos', 'No hubo descuentos en estos periodos.', 'percent');
      ?>
    </section>
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Profundidad contra rentabilidad</h3>
      <p class="text-sm text-slate-400 mb-2">Cada burbuja es un tipo (sin las muestras): a la derecha descuenta más hondo, arriba deja más margen; el tamaño es su venta bruta</p>
      <?php
      $maxGs = max(1, max(array_map(fn($r) => $r['ty']['gs'], $tiposGraf ?: [['ty' => ['gs' => 1]]])));
      $puntos = [];
      foreach ($tiposGraf as $k => $r) {
          $t = $r['ty'];
          // Una muestra es siempre 100% de descuento: en la burbuja solo aplasta al resto.
          if ($t['gs'] <= 0 || $k === 'muestra') continue;
          $puntos[] = [
              'name' => cockpit_tipo_label($k), 'value' => [round($t['desc_pct'], 1), round($t['margen_ns'], 1), round($t['gs'])],
              'symbolSize' => round(14 + 46 * sqrt($t['gs'] / $maxGs)), 'url' => $drill($k),
              'itemStyle' => ['color' => cockpit_tipo_color($k), 'opacity' => 0.85, 'borderColor' => '#ffffff', 'borderWidth' => 2],
              'tip' => '<b>' . e(cockpit_tipo_label($k)) . '</b><br>Descuento: <b>' . cockpit_pct($t['desc_pct']) . '</b><br>Margen s/ venta neta: <b>'
                  . cockpit_pct($t['margen_ns']) . '</b><br>Venta bruta: <b>' . ck_money($t['gs']) . '</b><br><span style="color:#94a3b8">Toca para ver el detalle</span>',
          ];
      }
      echo $puntos ? grafico([
          'xAxis' => ['type' => 'value', 'name' => 'Desc %', 'nameLocation' => 'middle', 'nameGap' => 26, 'formato' => 'pct', 'scale' => true],
          'yAxis' => ['type' => 'value', 'name' => 'Margen % VN', 'formato' => 'pct', 'scale' => true],
          'series' => [['type' => 'scatter', 'data' => $puntos,
              'label' => ['show' => true, 'position' => 'right', 'formatter' => '{b}', 'fontSize' => 11, 'color' => '#52514e'],
              'labelLayout' => ['hideOverlap' => true], 'emphasis' => ['focus' => 'self', 'scale' => 1.1]]],
      ], ['formato' => 'pct', 'titulo' => 'Profundidad contra rentabilidad'], '320px') : empty_state('Sin datos', '', 'chart');
      ?>
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
          <?= grafico_lineas_ty_ly($etqMeses, $serieAvg($stTY, $meses),
              array_slice(array_pad($serieAvg($stLY, $mesesLY), count($meses), 0), 0, count($meses)),
              ['formato' => 'dec', 'titulo' => 'Descuentos por factura', 'escala' => true, 'herramientas' => false], '200px') ?>
        </div>
      </div>
      <p class="text-xs text-slate-400 mt-2">Entre las facturas con al menos un descuento: cada promoción distinta, las muestras, un precio negociado y el descuento en caja cuentan uno.</p>
    </section>
    <section class="card p-4 xl:col-span-3">
      <p class="text-xs font-bold uppercase tracking-wider text-amber-600 mb-3">Distribución (% venta bruta)</p>
      <?php
      $dTY = []; $dLY = [];
      foreach ([1, 2, 3, 4, 5] as $n) {
          $dTY[] = round($stTY['gs'] > 0 ? $stTY['dist'][$n] / $stTY['gs'] * 100 : 0, 1);
          $dLY[] = round($stLY['gs'] > 0 ? $stLY['dist'][$n] / $stLY['gs'] * 100 : 0, 1);
      }
      echo grafico_ty_ly(['1', '2', '3', '4', '5+'], $dTY, $dLY, ['formato' => 'pct', 'titulo' => 'Distribución por número de descuentos', 'herramientas' => false], '210px');
      ?>
      <p class="text-xs text-slate-400 mt-1">Descuentos por factura, en % de la venta bruta con descuento.</p>
    </section>
  </div>

  <?php
  // Pareto: las promociones que más descuento regalaron, con su % acumulado.
  $pareto = array_filter($mecTodos, fn($r) => $r['desc'] > 0);
  uasort($pareto, fn($a, $b) => $b['desc'] <=> $a['desc']);
  $totDesc = array_sum(array_column($pareto, 'desc')) ?: 1; $acum = 0;
  $pCats = []; $pData = [];
  foreach (array_slice($pareto, 0, 15, true) as $r) {
      $acum += $r['desc'];
      $pCats[] = $r['nombre'];
      $pData[] = ['value' => round($r['desc']), 'itemStyle' => ['color' => cockpit_tipo_color($r['tipo']), 'borderRadius' => [0, 4, 4, 0]],
          'url' => ck_url(['tipo' => $r['tipo']]),
          'tip' => '<b>' . e($r['nombre']) . '</b><br>' . e(cockpit_tipo_label($r['tipo'])) . '<br>Descuento: <b>' . ck_money($r['desc']) . '</b> (' . cockpit_pct($r['desc'] / $totDesc * 100) . ')'
              . '<br>Acumulado: <b>' . cockpit_pct($acum / $totDesc * 100) . '</b><br>Activaciones: <b>' . number_format($r['act']) . '</b>',
          'acum' => round($acum / $totDesc * 100, 1)];
  }
  $burbujas = []; $maxGsM = max(1, max(array_merge([1], array_column($mecTodos, 'gs'))));
  foreach ($mecTodos as $r) {
      if ($r['act'] <= 0 || $r['tipo'] === 'muestra') continue;
      $burbujas[] = ['name' => $r['nombre'], 'value' => [(int) $r['act'], round($r['desc_pct'], 1), round($r['gs'])],
          'symbolSize' => round(10 + 40 * sqrt($r['gs'] / $maxGsM)), 'url' => ck_url(['tipo' => $r['tipo']]),
          'itemStyle' => ['color' => cockpit_tipo_color($r['tipo']), 'opacity' => 0.8, 'borderColor' => '#fff', 'borderWidth' => 1.5],
          'tip' => '<b>' . e($r['nombre']) . '</b><br>' . e(cockpit_tipo_label($r['tipo'])) . '<br>Activaciones: <b>' . number_format($r['act'])
              . '</b><br>Desc %: <b>' . cockpit_pct($r['desc_pct']) . '</b><br>Venta bruta: <b>' . ck_money($r['gs']) . '</b><br>Ticket medio: <b>' . ck_money($r['atv_ticket']) . '</b>'];
  }
  ?>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-5">
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Dónde se va el descuento</h3>
      <p class="text-sm text-slate-400 mb-2">Las 15 promociones o motivos que más descuento dieron · el tooltip trae el % acumulado</p>
      <?= $pData ? grafico([
          'xAxis' => ['type' => 'value'],
          'yAxis' => ['type' => 'category', 'inverse' => true, 'data' => $pCats, 'axisLabel' => ['width' => 190, 'overflow' => 'truncate', 'interval' => 0]],
          'series' => [['name' => 'Descuento', 'type' => 'bar', 'data' => $pData, 'barMaxWidth' => 18]],
      ], ['formato' => 'money0', 'titulo' => 'Pareto de descuentos'], max(280, 26 * count($pCats) + 40) . 'px') : empty_state('Sin descuentos', '', 'percent') ?>
    </section>
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Uso contra profundidad</h3>
      <p class="text-sm text-slate-400 mb-2">A la derecha las más usadas, arriba las que más descuentan; el tamaño es su venta bruta. Arrastra para acercar.</p>
      <?= $burbujas ? grafico([
          'xAxis' => ['type' => 'value', 'name' => 'Activaciones', 'nameLocation' => 'middle', 'nameGap' => 26, 'formato' => 'num', 'scale' => true],
          'yAxis' => ['type' => 'value', 'name' => 'Desc %', 'formato' => 'pct', 'scale' => true],
          'dataZoom' => [['type' => 'inside', 'xAxisIndex' => 0], ['type' => 'inside', 'yAxisIndex' => 0]],
          'series' => [['type' => 'scatter', 'data' => $burbujas, 'emphasis' => ['focus' => 'self', 'label' => ['show' => true, 'formatter' => '{b}', 'position' => 'top']]]],
      ], ['formato' => 'num', 'titulo' => 'Uso contra profundidad'], '340px') : empty_state('Sin activaciones', '', 'chart') ?>
    </section>
  </div>

  <section class="card overflow-hidden mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-100">
      <div>
        <h3 class="font-bold text-slate-800">Detalle por descuento
          <?php if ($tipoFiltro): ?>
            <a href="<?= e(ck_url(['tipo' => null])) ?>" class="ml-2 inline-flex items-center gap-1 align-middle text-xs font-semibold px-2 py-1 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100" title="Quitar el filtro">
              <?= e(cockpit_tipo_label($tipoFiltro)) ?> <?= icon('x', 'w-3 h-3') ?></a>
          <?php endif; ?>
        </h3>
        <p class="text-sm text-slate-400">Cada promoción por su código, cada motivo de descuento en caja, las muestras y los precios negociados</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 no-print">
      <select aria-label="Filtrar por tipo" class="select w-auto py-1.5 text-sm" onchange="location.href=this.value">
        <option value="<?= e(ck_url(['tipo' => null])) ?>">Todos los tipos</option>
        <?php foreach (array_unique(array_column($mecTodos, 'tipo')) as $tk): ?>
          <option value="<?= e(ck_url(['tipo' => $tk])) ?>" <?= $tipoFiltro === $tk ? 'selected' : '' ?>><?= e(cockpit_tipo_label($tk)) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="flex items-center gap-1 p-1 bg-slate-100 rounded-xl">
        <a href="<?= e(ck_url(['vista' => null])) ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold <?= $vista === 'todas' ? 'bg-slate-700 text-white' : 'text-slate-500' ?>">Tabla completa</a>
        <a href="<?= e(ck_url(['vista' => 'menos'])) ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold <?= $vista === 'menos' ? 'bg-slate-700 text-white' : 'text-slate-500' ?>"><?= cockpit_param_int('menos_activadas') ?> menos activadas</a>
      </div>
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

    <?php
    // Mapa de árbol: tipo → promoción → producto, por venta bruta. Clic = acercar.
    $hojas = [];
    foreach ($arbol as $t => $nodo) {
        $hijosM = [];
        foreach ($nodo['mec'] as $m => $mn) {
            $prods = [];
            foreach (array_slice($mn['prod'], 0, 40) as $pr) {
                $d = $pr['gs'] > 0 ? ($pr['gs'] - $pr['ns']) / $pr['gs'] * 100 : 0;
                $prods[] = ['name' => ($pr['codigo'] ? $pr['codigo'] . ' ' : '') . $pr['nombre'], 'value' => round($pr['gs']),
                    'tip' => '<b>' . e($pr['nombre']) . '</b><br>' . e($nombreMec($m)) . '<br>Venta bruta: <b>' . ck_money($pr['gs']) . '</b><br>Descuento: <b>'
                        . cockpit_pct($d) . '</b><br>Venta neta: <b>' . ck_money($pr['ns']) . '</b><br>Cantidad: <b>' . qty($pr['qty']) . '</b>'];
            }
            $hijosM[] = ['name' => $nombreMec($m), 'value' => round($mn['tot']['gs']), 'children' => $prods,
                'tip' => '<b>' . e($nombreMec($m)) . '</b><br>Venta bruta: <b>' . ck_money($mn['tot']['gs']) . '</b><br>Descuento: <b>'
                    . cockpit_pct($mn['tot']['gs'] > 0 ? ($mn['tot']['gs'] - $mn['tot']['ns']) / $mn['tot']['gs'] * 100 : 0) . '</b>'];
        }
        $hojas[] = ['name' => cockpit_tipo_label($t), 'value' => round($nodo['tot']['gs']), 'children' => $hijosM,
            'itemStyle' => ['color' => cockpit_tipo_color($t)],
            'tip' => '<b>' . e(cockpit_tipo_label($t)) . '</b><br>Venta bruta: <b>' . ck_money($nodo['tot']['gs']) . '</b><br>Descuento: <b>'
                . cockpit_pct($nodo['tot']['gs'] > 0 ? ($nodo['tot']['gs'] - $nodo['tot']['ns']) / $nodo['tot']['gs'] * 100 : 0) . '</b>'];
    }
    ?>
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
      <section class="card p-4 xl:col-span-2">
        <h3 class="font-bold text-slate-800">Mapa de la venta con descuento</h3>
        <p class="text-sm text-slate-400 mb-2">Tipo → promoción → producto, por venta bruta. Toca un bloque para entrar; la barra de abajo te devuelve.</p>
        <?= $hojas ? grafico([
            'tooltip' => ['trigger' => 'item'],
            'series' => [[
                'type' => 'treemap', 'name' => 'Venta bruta', 'data' => $hojas, 'leafDepth' => 1, 'roam' => false,
                'breadcrumb' => ['show' => true, 'bottom' => 4, 'itemStyle' => ['color' => '#f1f5f9', 'borderColor' => '#e2e8f0', 'textStyle' => ['color' => '#334155']]],
                'top' => 8, 'left' => 0, 'right' => 0, 'bottom' => 36,
                'upperLabel' => ['show' => true, 'height' => 22, 'color' => '#fff', 'fontWeight' => 'bold'],
                'levels' => [
                    ['itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2, 'gapWidth' => 2]],
                    ['colorSaturation' => [0.35, 0.6], 'itemStyle' => ['borderColorSaturation' => 0.6, 'gapWidth' => 1, 'borderWidth' => 2]],
                    ['colorSaturation' => [0.3, 0.55], 'itemStyle' => ['borderColorSaturation' => 0.5, 'gapWidth' => 1]],
                ],
            ]],
        ], ['formato' => 'money0', 'titulo' => 'Mapa de la venta con descuento'], '420px') : empty_state('Sin datos', '', 'package') ?>
      </section>
      <section class="card p-4">
        <h3 class="font-bold text-slate-800">Héroes contra el resto</h3>
        <p class="text-sm text-slate-400 mb-2">Qué parte de cada total ponen los héroes</p>
        <?php
        $gsT = max(0.01, $h['gs'] + $o['gs']); $nsT = max(0.01, $h['ns'] + $o['ns']);
        $filasH = ['Venta bruta' => [$h['gs'] / $gsT * 100, $o['gs'] / $gsT * 100], 'Descuentos' => [$h['desc'] / $descTot * 100, $o['desc'] / $descTot * 100],
                   'Venta neta' => [$h['ns'] / $nsT * 100, $o['ns'] / $nsT * 100]];
        echo grafico([
            'legend' => ['data' => ['Héroes', 'Otros']],
            'xAxis' => ['type' => 'value', 'max' => 100, 'formato' => 'pct'],
            'yAxis' => ['type' => 'category', 'inverse' => true, 'data' => array_keys($filasH)],
            'series' => [
                ['name' => 'Héroes', 'type' => 'bar', 'stack' => 'h', 'barMaxWidth' => 28, 'data' => array_map(fn($v) => round($v[0], 1), array_values($filasH)),
                 'itemStyle' => ['color' => GRAF_TY, 'borderColor' => '#fff', 'borderWidth' => 2],
                 // Fuera de la barra: un 10% no deja sitio para «10.1%» dentro y se recortaba.
                 'label' => ['show' => true, 'position' => 'right', 'color' => '#0f172a', 'fontWeight' => 'bold', 'fontSize' => 11, 'formato' => 'pct']],
                ['name' => 'Otros', 'type' => 'bar', 'stack' => 'h', 'barMaxWidth' => 28, 'data' => array_map(fn($v) => round($v[1], 1), array_values($filasH)),
                 'itemStyle' => ['color' => GRAF_LY, 'borderColor' => '#fff', 'borderWidth' => 2], 'label' => ['show' => true, 'position' => 'insideRight', 'color' => '#334155', 'fontSize' => 11, 'formato' => 'pct']],
            ],
        ], ['formato' => 'pct', 'titulo' => 'Héroes contra el resto'], '220px');
        ?>
      </section>
    </div>

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

<?php elseif ($tab === 'sellout'): ?>
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
          <?php elseif ($k === 'sucursal' || $k === 'canal' || $k === 'tienda'):
            // Anillo: parte de un todo, como mucho 6 porciones; el resto se agrupa en «Otras».
            $items = []; $i = 0; $resto = 0.0; $restoLy = 0.0;
            foreach ($dim as $g => $v) {
                $nombreG = $k === 'canal' ? ($canalesNombre[$g] ?? $g) : $g;
                if ($i >= 6) { $resto += $v['ty']; $restoLy += $v['ly']; continue; }
                $d = rep_delta($v['ty'], $v['ly']);
                $items[] = ['name' => $nombreG, 'value' => round($v['ty']), 'itemStyle' => ['color' => rep_color($i++)],
                    'url' => $k === 'canal' ? ck_url(['canal' => $g, 'tab' => 'resumen']) : null,
                    'tip' => '<b>' . e($nombreG) . '</b><br>Venta neta: <b>' . ck_money($v['ty']) . '</b> (' . cockpit_pct($tot > 0 ? $v['ty'] / $tot * 100 : 0) . ')'
                        . '<br>Año anterior: ' . ck_money($v['ly']) . '<br>Crecimiento: <b>' . ($d === null ? 'nuevo' : (($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . '%')) . '</b>'
                        . ($k === 'canal' ? '<br><span style="color:#94a3b8">Toca para filtrar el cockpit</span>' : '')];
            }
            if ($resto > 0) $items[] = ['name' => 'Otras', 'value' => round($resto), 'itemStyle' => ['color' => '#94a3b8']];
            echo grafico([
                'tooltip' => ['trigger' => 'item'],
                'legend' => ['type' => 'scroll', 'orient' => 'vertical', 'right' => 4, 'top' => 'middle', 'left' => null,
                    'textStyle' => ['width' => 150, 'overflow' => 'truncate']],
                'series' => [['type' => 'pie', 'name' => 'Venta neta', 'radius' => ['52%', '78%'], 'center' => ['35%', '50%'], 'data' => $items,
                    'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2], 'avoidLabelOverlap' => true,
                    'label' => ['show' => true, 'position' => 'center', 'formatter' => ck_money($tot), 'fontSize' => 15, 'fontWeight' => 'bold', 'color' => '#0f172a'],
                    'emphasis' => ['scale' => true, 'scaleSize' => 6, 'label' => ['show' => true, 'formatter' => "{b}\n{d}%", 'fontSize' => 14]],
                    'labelLine' => ['show' => false]]],
            ], ['formato' => 'money0', 'titulo' => 'Sell-out por ' . mb_strtolower($dims[$k][0])], '280px'); ?>
          <?php else:
            $filtroDim = $k === 'segmento' ? 'segmento' : 'linea';
            $cats = []; $dTy = []; $dLy = [];
            foreach ($dim as $g => $v) {
                $d = rep_delta($v['ty'], $v['ly']);
                $tip = '<b>' . e($g) . '</b><br>Venta neta: <b>' . ck_money($v['ty']) . '</b> (' . cockpit_pct($tot > 0 ? $v['ty'] / $tot * 100 : 0) . ' del total)'
                    . '<br>Año anterior: ' . ck_money($v['ly']) . ' (' . cockpit_pct($totL > 0 ? $v['ly'] / $totL * 100 : 0) . ')'
                    . '<br>Crecimiento: <b>' . ($d === null ? 'nuevo' : (($d >= 0 ? '+' : '−') . number_format(abs($d), 1) . '%')) . '</b>'
                    . '<br><span style="color:#94a3b8">Toca para filtrar el cockpit</span>';
                $url = ck_url([$filtroDim => $g, 'tab' => 'resumen']);
                $cats[] = $g;
                $dTy[] = ['value' => round($v['ty']), 'url' => $url, 'tip' => $tip];
                $dLy[] = ['value' => round($v['ly']), 'url' => $url, 'tip' => $tip];
            }
            echo grafico_ty_ly($cats, $dTy, $dLy, ['formato' => 'money0', 'titulo' => 'Sell-out por ' . mb_strtolower($dims[$k][0]), 'horizontal' => true],
                max(240, 34 * count($cats) + 60) . 'px', ['tooltip' => ['trigger' => 'item']]); ?>
            <?php if (isset($dim['Sin segmento']) || isset($dim['Sin línea'])): ?>
              <p class="text-xs text-slate-400 mt-2">Los productos sin segmento o sin línea se clasifican en Configuración del cockpit → Productos.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?= rep_fin() ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 mt-5">
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Segmento → línea</h3>
      <p class="text-sm text-slate-400 mb-2">Venta neta de este año. Toca un segmento para abrirlo.</p>
      <?php
      $sol = [];
      foreach ($segLin as $clave => $v) {
          [$sg, $ln] = explode('|', $clave, 2) + [1 => ''];
          $sol[$sg]['value'] = ($sol[$sg]['value'] ?? 0) + $v;
          $sol[$sg]['children'][] = ['name' => $ln, 'value' => round($v)];
      }
      uasort($sol, fn($a, $b) => $b['value'] <=> $a['value']);
      $solData = []; $i = 0;
      foreach ($sol as $sg => $n) {
          $solData[] = ['name' => $sg, 'value' => round($n['value']), 'children' => $n['children'], 'itemStyle' => ['color' => rep_color($i++)]];
      }
      echo $solData ? grafico([
          'tooltip' => ['trigger' => 'item'],
          'series' => [['type' => 'sunburst', 'data' => $solData, 'radius' => ['12%', '95%'], 'sort' => 'desc',
              'itemStyle' => ['borderColor' => '#fff', 'borderWidth' => 2],
              'label' => ['rotate' => 'radial', 'minAngle' => 8, 'fontSize' => 11],
              'levels' => [[], ['r0' => '12%', 'r' => '55%'], ['r0' => '55%', 'r' => '95%', 'itemStyle' => ['opacity' => 0.75]]]]],
      ], ['formato' => 'money0', 'titulo' => 'Segmento y línea'], '380px') : empty_state('Sin ventas', '', 'layers');
      ?>
    </section>
    <section class="card p-4">
      <h3 class="font-bold text-slate-800">Sell-out mes a mes</h3>
      <p class="text-sm text-slate-400 mb-2">Venta neta este año contra el anterior</p>
      <?= grafico_lineas_ty_ly($etqMeses, array_map(fn($ym) => round($menTY[$ym]['ns'] ?? 0), $meses),
          array_slice(array_pad(array_map(fn($ym) => round($menLY[$ym]['ns'] ?? 0), $mesesLY), count($meses), 0), 0, count($meses)),
          ['formato' => 'money0', 'titulo' => 'Sell-out mes a mes'], '380px', count($meses) > 12) ?>
    </section>
  </div>

<?php elseif ($tab === 'efectividad'):
  $conBase = array_filter($efec, fn($r) => $r['margen_incremental'] !== null);
  $rentables = array_filter($conBase, fn($r) => in_array($r['veredicto'][0], ['rentable', 'margen'], true));
  $incrTot = array_sum(array_column($conBase, 'margen_incremental'));
  $costoBase = array_sum(array_column($conBase, 'costo_desc'));
?>
  <?= kpis([
      ['label' => 'Promociones con comparación', 'valor' => count($conBase) . ' <span class="text-base font-semibold text-slate-400">de ' . count($efec) . '</span>', 'icono' => 'percent', 'color' => 'blue',
       'nota' => 'Las demás no tienen un «antes» comparable'],
      ['label' => 'Rentables', 'valor' => count($rentables), 'icono' => 'trending', 'color' => 'emerald',
       'nota' => $conBase ? number_format(count($rentables) / count($conBase) * 100, 0) . '% de las comparables' : '—'],
      ['label' => 'Margen incremental', 'valor' => ck_money($incrTot), 'icono' => 'coins', 'color' => $incrTot >= 0 ? 'emerald' : 'rose',
       'nota' => 'Lo que ganaron de más (o perdieron) contra sus días previos'],
      ['label' => 'Retorno del descuento', 'valor' => $costoBase > 0 ? number_format($incrTot / $costoBase, 2) . 'x' : '—', 'icono' => 'target', 'color' => 'violet',
       'nota' => 'Margen incremental ÷ descuento regalado (' . ck_money($costoBase) . ')'],
  ]) ?>

  <div class="grid grid-cols-1 xl:grid-cols-5 gap-5 mb-5">
    <section class="card p-4 xl:col-span-3">
      <h3 class="font-bold text-slate-800">¿Vendió más? ¿Ganó margen?</h3>
      <p class="text-sm text-slate-400 mb-2">Cada punto es una promoción. Arriba a la derecha: vendió más y ganó margen. Abajo a la derecha: vendió más, pero el descuento costó más de lo que trajo. El tamaño es el descuento regalado.</p>
      <?php
      $maxC = max(1, max(array_merge([1], array_column($conBase, 'costo_desc'))));
      $pts = [];
      foreach ($conBase as $r) {
          $pts[] = ['name' => $r['nombre'], 'value' => [round($r['aumento'], 1), round($r['margen_incremental'])],
              'symbolSize' => round(12 + 38 * sqrt($r['costo_desc'] / $maxC)), 'url' => ck_url(['tab' => 'detalle', 'tipo' => $r['tipo']]),
              'itemStyle' => ['color' => cockpit_tipo_color($r['tipo']), 'opacity' => 0.85, 'borderColor' => '#fff', 'borderWidth' => 1.5],
              'tip' => '<b>' . e($r['nombre']) . '</b><br>' . e($r['veredicto'][1]) . '<br>Unidades/día: ' . number_format((float) $r['ud_dia_base'], 1) . ' → <b>' . number_format($r['ud_dia'], 1)
                  . '</b> (' . ($r['aumento'] >= 0 ? '+' : '−') . number_format(abs($r['aumento']), 0) . '%)<br>Margen incremental: <b>' . ck_money($r['margen_incremental'])
                  . '</b><br>Descuento regalado: ' . ck_money($r['costo_desc']) . '<br>' . e(fechaCorta($r['ventana'][0]) . ' – ' . fechaCorta($r['ventana'][1])) . ' contra los ' . $r['dias_base'] . ' días previos'];
      }
      echo $pts ? grafico([
          // Aire a los lados: los puntos grandes de las esquinas se cortaban contra el borde.
          'grid' => ['left' => 12, 'right' => 90, 'top' => 36, 'bottom' => 34, 'containLabel' => true],
          'xAxis' => ['type' => 'value', 'name' => 'Aumento de unidades', 'nameLocation' => 'middle', 'nameGap' => 26, 'formato' => 'pct', 'scale' => true, 'boundaryGap' => ['12%', '12%']],
          'yAxis' => ['type' => 'value', 'name' => 'Margen incremental', 'scale' => true, 'boundaryGap' => ['15%', '15%']],
          'series' => [['type' => 'scatter', 'data' => $pts,
              'label' => ['show' => true, 'position' => 'right', 'formatter' => '{b}', 'fontSize' => 10, 'color' => '#52514e'], 'labelLayout' => ['hideOverlap' => true],
              'markLine' => ['silent' => true, 'symbol' => 'none', 'label' => ['show' => false], 'lineStyle' => ['color' => '#94a3b8', 'type' => 'dashed'],
                             'data' => [['xAxis' => 0], ['yAxis' => 0]]]]],
      ], ['formato' => 'money0', 'titulo' => 'Efectividad de promociones'], '380px')
      : empty_state('Nada que comparar todavía', 'Ninguna promoción del periodo tiene días previos comparables (las que duran meses no se pueden medir así).', 'chart'); ?>
    </section>
    <section class="card p-4 xl:col-span-2">
      <h3 class="font-bold text-slate-800">¿Quién compra en promoción?</h3>
      <p class="text-sm text-slate-400 mb-2">Venta bruta con y sin descuento de cada tipo de cliente</p>
      <?php
      $g = $cliPromo['grupos'];
      $etq = ['nuevos' => 'Clientes nuevos', 'recurrentes' => 'Recurrentes', 'anonimos' => 'Sin identificar'];
      echo grafico([
          'legend' => ['data' => ['Con descuento', 'Sin descuento']],
          'xAxis' => ['type' => 'value'],
          'yAxis' => ['type' => 'category', 'inverse' => true, 'data' => array_values($etq)],
          'series' => [
              ['name' => 'Con descuento', 'type' => 'bar', 'stack' => 'c', 'barMaxWidth' => 26, 'itemStyle' => ['color' => GRAF_TY, 'borderColor' => '#fff', 'borderWidth' => 2],
               'data' => array_map(fn($k) => round($g[$k]['gs_promo']), array_keys($etq))],
              ['name' => 'Sin descuento', 'type' => 'bar', 'stack' => 'c', 'barMaxWidth' => 26, 'itemStyle' => ['color' => GRAF_LY, 'borderColor' => '#fff', 'borderWidth' => 2],
               'data' => array_map(fn($k) => round($g[$k]['gs'] - $g[$k]['gs_promo']), array_keys($etq))],
          ],
      ], ['formato' => 'money0', 'titulo' => 'Clientes y promociones', 'herramientas' => false], '200px');
      ?>
      <ul class="mt-3 space-y-1.5 text-sm text-slate-600">
        <?php foreach ($etq as $k => $lbl): $gg = $g[$k]; ?>
          <li class="flex justify-between gap-3"><span><?= e($lbl) ?><?= $gg['clientes'] ? ' <span class="text-slate-400">(' . number_format($gg['clientes']) . ')</span>' : '' ?></span>
            <span class="tabular-nums font-semibold"><?= $gg['gs'] > 0 ? cockpit_pct($gg['gs_promo'] / $gg['gs'] * 100) : '—' ?> en promo</span></li>
        <?php endforeach; ?>
      </ul>
      <div class="mt-4 p-3 rounded-xl bg-amber-50 text-sm text-amber-900">
        <b><?= number_format($cliPromo['n_dependientes']) ?> clientes dependientes de la promoción</b>: 2 o más compras en el periodo y el 80% o más con descuento.
        Suman <?= ck_money($cliPromo['gs_dependientes']) ?> de venta bruta.
      </div>
    </section>
  </div>

  <section class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Resultado de cada promoción</h3>
      <p class="text-sm text-slate-400">Los productos que se vendieron con la promoción, en sus días de vigencia contra el mismo número de días justo antes (hasta 28). Es una lectura, no un experimento: la base puede traer otra temporada u otra promoción.</p>
    </div>
    <?php if (!$efec): ?>
      <div class="p-6"><?= empty_state('Sin promociones usadas en el periodo', 'Cuando las ventas empiecen a registrar su promoción, aquí se verá cuál funcionó.', 'percent') ?></div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table text-[13px] whitespace-nowrap">
        <thead><tr><th>Promoción</th><th>Analizado</th><th class="text-right">Productos</th><th class="text-right">Unid./día antes → durante</th><th class="text-right">Aumento</th>
          <th class="text-right">Margen incremental</th><th class="text-right">Descuento regalado</th><th class="text-right" title="Margen incremental ÷ descuento">Retorno</th><th>Veredicto</th></tr></thead>
        <tbody>
        <?php foreach ($efec as $r): ?>
          <tr>
            <td class="max-w-[280px]"><p class="font-semibold text-slate-700 truncate"><?= e($r['nombre']) ?></p>
              <p class="text-xs text-slate-400"><span class="inline-block w-2 h-2 rounded-full mr-1 align-middle" style="background:<?= e(cockpit_tipo_color($r['tipo'])) ?>"></span><?= e(cockpit_tipo_label($r['tipo'])) ?><?= $r['profundidad'] !== null ? ' · ' . cockpit_pct($r['profundidad'], 0) : '' ?></p></td>
            <td class="text-slate-500 text-xs"><?= e(fechaCorta($r['ventana'][0]) . ' – ' . fechaCorta($r['ventana'][1])) ?><br><?= (int) $r['dias'] ?> días<?= $r['ud_dia_base'] !== null ? ' vs. ' . (int) $r['dias_base'] . ' previos' : '' ?></td>
            <td class="text-right tabular-nums"><?= number_format($r['productos']) ?></td>
            <td class="text-right tabular-nums"><?= $r['ud_dia_base'] === null ? '<span class="text-slate-300">—</span>' : number_format($r['ud_dia_base'], 1) . ' → <b>' . number_format($r['ud_dia'], 1) . '</b>' ?></td>
            <td class="text-right"><?= $r['aumento'] === null ? '<span class="text-slate-300">—</span>' : '<span class="font-semibold ' . ($r['aumento'] >= 0 ? 'text-emerald-600' : 'text-rose-600') . '">' . ($r['aumento'] >= 0 ? '+' : '−') . number_format(abs($r['aumento']), 0) . '%</span>' ?></td>
            <td class="text-right"><?= $r['margen_incremental'] === null ? '<span class="text-slate-300">—</span>' : cockpit_celda_efecto($r['margen_incremental']) ?></td>
            <td class="text-right tabular-nums"><?= cockpit_n($r['costo_desc']) ?></td>
            <td class="text-right tabular-nums"><?= $r['retorno'] === null ? '<span class="text-slate-300">—</span>' : number_format($r['retorno'], 2) . 'x' ?></td>
            <td><?= badge($r['veredicto'][1], $r['veredicto'][2]) ?><?php if ($r['motivo']): ?><p class="text-[11px] text-slate-400 mt-0.5"><?= e($r['motivo']) ?></p><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <?php if ($cliPromo['dependientes']): ?>
  <section class="card overflow-hidden mb-5">
    <div class="p-4 border-b border-slate-100">
      <h3 class="font-bold text-slate-800">Clientes que casi solo compran con descuento</h3>
      <p class="text-sm text-slate-400">Los 10 con más venta. Útil para decidir a quién NO enviarle la próxima promoción masiva.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="data-table text-[13px]">
        <thead><tr><th>Cliente</th><th class="text-right">Facturas</th><th class="text-right">Venta bruta</th><th class="text-right">Con descuento</th></tr></thead>
        <tbody>
        <?php foreach ($cliPromo['dependientes'] as $id => $d): ?>
          <tr><td><a class="text-slate-700 hover:text-blue-600" href="<?= e(url('modules/crm/cliente.php?id=' . (int) $id)) ?>"><?= e($d['nombre']) ?></a></td>
            <td class="text-right tabular-nums"><?= (int) $d['facturas'] ?></td><td class="text-right tabular-nums"><?= cockpit_n($d['gs']) ?></td>
            <td class="text-right tabular-nums font-semibold"><?= cockpit_pct($d['pct'], 0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>
<?php elseif ($tab === 'simulador'):
  $mon = cockpit_moneda()[1];
  $categorias = qAll("SELECT id, nombre FROM categorias ORDER BY nombre");
  $lineas = qCol("SELECT DISTINCT COALESCE(NULLIF(linea,''), 'Sin línea') l FROM productos WHERE activo = 1 ORDER BY l");
  // Los filtros de arriba (sucursal, canal, marca…) siguen valiendo: la base sale de ellos.
  $ocultos = array_filter($_GET, fn($v, $k) => is_scalar($v) && !str_starts_with((string) $k, 's_') && !in_array($k, ['tab', 'export'], true), ARRAY_FILTER_USE_BOTH);
?>
  <section class="card p-4 mb-5 no-print" x-data="{alcance: <?= e(json_encode($sim['alcance'])) ?>, tipo: <?= e(json_encode($sim['tipo'])) ?>}">
    <h3 class="font-bold text-slate-800">¿Qué pasaría si…?</h3>
    <p class="text-sm text-slate-400 mb-3">Describe la promoción. La base es lo que se vendió en los últimos días con los filtros de arriba; el precio de la promoción se aplica sobre el precio de lista y nunca sube lo que ya se cobraba más barato.</p>
    <form method="get" class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 items-end">
      <input type="hidden" name="tab" value="simulador">
      <?php foreach ($ocultos as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <div class="col-span-2 md:col-span-1">
        <label class="label" for="s_alcance">Aplica a</label>
        <select id="s_alcance" name="s_alcance" x-model="alcance" class="select">
          <?php foreach ($simAlcances as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $sim['alcance'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2 md:col-span-1" x-show="alcance !== 'todos'" x-cloak>
        <label class="label" for="s_obj">¿Cuál?</label>
        <select id="s_obj" name="s_obj" class="select" x-show="alcance === 'categoria'" :disabled="alcance !== 'categoria'">
          <?php foreach ($categorias as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sim['alcance'] === 'categoria' && $sim['objetivo'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select>
        <select name="s_obj" class="select" x-show="alcance === 'marca'" :disabled="alcance !== 'marca'" aria-label="Marca">
          <?php foreach ($marcas as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $sim['alcance'] === 'marca' && $sim['objetivo'] === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
        </select>
        <select name="s_obj" class="select" x-show="alcance === 'segmento'" :disabled="alcance !== 'segmento'" aria-label="Segmento">
          <?php foreach ($segmentos as $sg): ?><option value="<?= e($sg) ?>" <?= $sim['alcance'] === 'segmento' && $sim['objetivo'] === $sg ? 'selected' : '' ?>><?= e($sg) ?></option><?php endforeach; ?>
        </select>
        <select name="s_obj" class="select" x-show="alcance === 'linea'" :disabled="alcance !== 'linea'" aria-label="Línea">
          <?php foreach ($lineas as $ln): ?><option value="<?= e($ln) ?>" <?= $sim['alcance'] === 'linea' && $sim['objetivo'] === $ln ? 'selected' : '' ?>><?= e($ln) ?></option><?php endforeach; ?>
        </select>
        <input name="s_obj" class="input" x-show="alcance === 'producto'" :disabled="alcance !== 'producto'" placeholder="SKU o código de barras" aria-label="SKU del producto"
               value="<?= $sim['alcance'] === 'producto' ? e($sim['objetivo']) : '' ?>">
      </div>
      <div>
        <label class="label" for="s_tipo">Descuento</label>
        <select id="s_tipo" name="s_tipo" x-model="tipo" class="select">
          <option value="porcentaje" <?= $sim['tipo'] === 'porcentaje' ? 'selected' : '' ?>>Porcentaje</option>
          <option value="monto" <?= $sim['tipo'] === 'monto' ? 'selected' : '' ?>>Monto fijo</option>
        </select>
      </div>
      <div>
        <label class="label" for="s_valor"><span x-text="tipo === 'monto' ? <?= e(json_encode('Monto (' . $mon . ')')) ?> : '% de descuento'">% de descuento</span></label>
        <input id="s_valor" type="number" name="s_valor" min="0" step="0.5" :max="tipo === 'monto' ? null : 100" value="<?= e((string) $sim['valor']) ?>" class="input" required>
      </div>
      <div>
        <label class="label" for="s_dias">Días de promoción</label>
        <input id="s_dias" type="number" name="s_dias" min="1" max="120" value="<?= (int) $sim['dias'] ?>" class="input">
      </div>
      <div>
        <label class="label" for="s_base">Días de base</label>
        <input id="s_base" type="number" name="s_base" min="7" max="90" value="<?= (int) $sim['dias_base'] ?>" class="input" title="Cuántos días recientes se toman como venta normal">
      </div>
      <div>
        <label class="label" for="s_aum">Aumento esperado %</label>
        <input id="s_aum" type="number" name="s_aum" min="-90" max="500" step="1" value="<?= $simListo ? e((string) $sim['aumento']) : '' ?>" class="input" placeholder="Del historial">
      </div>
      <div class="col-span-2 md:col-span-1">
        <button class="btn btn-primary w-full"><?= icon('pulse', 'w-4 h-4') ?> Simular</button>
      </div>
    </form>
  </section>

  <?php if (!$simListo): ?>
    <div class="card p-6"><?= empty_state($sim['alcance'] === 'producto' && $sim['objetivo'] !== '' ? 'No se encontró ese producto' : 'Elige a qué aplica la promoción',
        $sim['alcance'] === 'producto' ? 'Escribe el SKU o el código de barras tal como está en la ficha del producto.' : 'Selecciona el grupo de productos y pulsa «Simular».', 'percent') ?></div>
  <?php elseif (!$simRes['productos']): ?>
    <div class="card p-6"><?= empty_state('Sin ventas en la base', 'Esos productos no se vendieron entre el ' . fechaCorta($simRes['base'][0]) . ' y el ' . fechaCorta($simRes['base'][1]) . ' con los filtros elegidos. Amplía los días de base o quita filtros.', 'chart') ?></div>
  <?php else:
    $t = $simRes['tot'];
    $eq = $simRes['equilibrio'];
    $vivo = ['a' => (float) $sim['aumento'], 'u0' => $t['u0'], 'ns0' => $t['ns0'], 'm0' => $t['m0'], 'nsb' => $t['nsb'], 'mu1' => $t['mu1'],
             'regalo0' => $t['ns0'] - $t['nsb'], 'eq' => $eq, 'mon' => $mon];
  ?>
  <div x-data="ckSim(<?= e(json_encode($vivo)) ?>)" x-init="$watch('a', () => pintar())">
    <?php if ($simHist && $sim['aumento_propuesto']): ?>
      <div class="card p-3 mb-4 text-sm text-slate-600 flex gap-2 items-start">
        <span class="text-blue-500 shrink-0"><?= icon('history', 'w-4 h-4') ?></span>
        <span>El aumento de <b><?= number_format($sim['aumento'], 0) ?>%</b> es la mediana de <?= (int) $simHist['n'] ?> promoción(es)
          <?= $simHist['parecidas'] ? 'de profundidad parecida' : '' ?> del último año (pestaña Efectividad). Muévelo abajo para ver qué pasa con otros valores.</span>
      </div>
    <?php elseif ($sim['aumento_propuesto']): ?>
      <div class="card p-3 mb-4 text-sm text-slate-600">No hay promociones medidas en el último año para proponer un aumento: se parte de 0%. Muévelo abajo.</div>
    <?php endif; ?>

    <div class="card p-4 mb-4">
      <div class="flex flex-wrap items-center gap-3">
        <label for="s_slider" class="text-sm font-semibold text-slate-700">Si las unidades suben</label>
        <input id="s_slider" type="range" min="-50" max="200" step="1" x-model.number="a" class="flex-1 min-w-[180px] accent-blue-600">
        <span class="text-lg font-extrabold tabular-nums w-20 text-right" x-text="(a >= 0 ? '+' : '−') + Math.abs(a) + '%'"></span>
      </div>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <p class="flex-1 min-w-[240px] text-sm font-semibold rounded-lg px-3 py-2" :class="dif() >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'" x-text="veredicto()"></p>
        <?php
        // Las promociones del sistema se definen por categoría, marca o producto; segmento y línea no.
        $simCrear = can('promociones.crear') && in_array($sim['alcance'], ['todos', 'categoria', 'marca', 'producto'], true) && cockpit_moneda()[0] === '';
        if ($simCrear):
          $objetivoNombre = match ($sim['alcance']) {
              'categoria' => (string) qVal("SELECT nombre FROM categorias WHERE id = ?", [(int) $sim['objetivo']]),
              'marca'     => (string) qVal("SELECT nombre FROM marcas WHERE id = ?", [(int) $sim['objetivo']]),
              'producto'  => (string) ($simProducto['nombre'] ?? ''),
              default     => 'todo',
          };
          $crear = url('modules/marketing/promociones.php') . '?' . http_build_query([
              'nueva' => 1, 'tipo' => $sim['tipo'], 'valor' => $sim['valor'], 'alcance' => $sim['alcance'],
              'objetivo' => $sim['alcance'] === 'producto' ? ($simProducto['id'] ?? '') : ($sim['alcance'] === 'todos' ? '' : $sim['objetivo']),
              'dias' => $sim['dias'],
              'nombre' => ($sim['tipo'] === 'porcentaje' ? number_format($sim['valor'], 0) . '%' : setting('moneda', 'RD$') . ' ' . number_format($sim['valor'], 0)) . ' en ' . $objetivoNombre,
          ]);
        ?>
          <a href="<?= e($crear) ?>" class="btn btn-soft" title="Abre el alta de promoción con estos datos; revisa y guarda allí"><?= icon('plus', 'w-4 h-4') ?> Crear esta promoción</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-sm font-semibold text-slate-600">Venta neta</p>
        <p class="text-lg sm:text-xl font-extrabold text-slate-800 tabular-nums mt-1" x-text="m(ns1())"></p>
        <p class="text-xs text-slate-400 mt-1">Sin promoción: <span class="tabular-nums" x-text="m(ns0)"></span></p>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-sm font-semibold text-slate-600">Margen</p>
        <p class="text-lg sm:text-xl font-extrabold tabular-nums mt-1" :class="dif() >= 0 ? 'text-emerald-600' : 'text-rose-600'" x-text="m(m1())"></p>
        <p class="text-xs text-slate-400 mt-1">Sin promoción: <span class="tabular-nums" x-text="m(m0)"></span> · <span class="font-semibold" x-text="(dif() >= 0 ? '+' : '−') + m(Math.abs(dif()))"></span></p>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-sm font-semibold text-slate-600">Descuento regalado</p>
        <p class="text-lg sm:text-xl font-extrabold text-slate-800 tabular-nums mt-1" x-text="m(regalo0 * (1 + a / 100))"></p>
        <p class="text-xs text-slate-400 mt-1"><?= $simRes['profundidad'] !== null ? 'Rebaja media de ' . cockpit_pct($simRes['profundidad']) . ' sobre lo que se cobra hoy' : '' ?></p>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-sm font-semibold text-slate-600">Punto de equilibrio</p>
        <p class="text-xl font-extrabold text-slate-800 tabular-nums mt-1"><?= $simRes['pierde_por_unidad'] ? '<span class="text-rose-600">No existe</span>' : ($eq === null ? '—' : '+' . number_format($eq, 0) . '%') ?></p>
        <p class="text-xs text-slate-400 mt-1"><?= $simRes['pierde_por_unidad'] ? 'Con ese precio se pierde dinero en cada unidad: vender más empeora el margen.' : 'Lo que deben subir las unidades para ganar el mismo margen' ?></p>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-5 mb-5">
      <section class="card p-4 xl:col-span-3">
        <h3 class="font-bold text-slate-800">Margen según cuánto suban las ventas</h3>
        <p class="text-sm text-slate-400 mb-2"><?= (int) $simRes['dias'] ?> días de promoción. Donde la línea azul cruza la gris, la promoción empieza a pagar su descuento.</p>
        <div x-ref="graf">
        <?php
        $lineasMarca = [['xAxis' => (float) $sim['aumento'], 'name' => 'Esperado', 'lineStyle' => ['color' => '#2a78d6'], 'label' => ['formatter' => 'Esperado']]];
        if ($eq !== null && $eq <= 200) $lineasMarca[] = ['xAxis' => round($eq, 1), 'name' => 'Equilibrio', 'lineStyle' => ['color' => '#d97706'], 'label' => ['formatter' => 'Equilibrio']];
        echo grafico([
            'legend' => ['data' => ['Con la promoción', 'Sin promoción']],
            'grid' => ['left' => 8, 'right' => 16, 'top' => 40, 'bottom' => 34, 'containLabel' => true],
            'xAxis' => ['type' => 'value', 'min' => -50, 'max' => 200, 'name' => 'Aumento de unidades', 'nameLocation' => 'middle', 'nameGap' => 26, 'formato' => 'pct'],
            'yAxis' => ['type' => 'value', 'scale' => true],
            'series' => [
                ['id' => 'con', 'name' => 'Con la promoción', 'type' => 'line', 'showSymbol' => false, 'lineStyle' => ['width' => 3, 'color' => GRAF_TY], 'itemStyle' => ['color' => GRAF_TY],
                 'data' => array_map(fn($k) => [$k, round($t['mu1'] * (1 + $k / 100))], range(-50, 200, 10)),
                 'markLine' => ['silent' => true, 'symbol' => 'none', 'lineStyle' => ['type' => 'dashed'], 'label' => ['position' => 'insideEndTop', 'fontSize' => 11], 'data' => $lineasMarca]],
                ['id' => 'sin', 'name' => 'Sin promoción', 'type' => 'line', 'showSymbol' => false, 'lineStyle' => ['width' => 2, 'color' => GRAF_LY, 'type' => 'dashed'], 'itemStyle' => ['color' => GRAF_LY],
                 'data' => [[-50, round($t['m0'])], [200, round($t['m0'])]]],
            ],
        ], ['formato' => 'money0', 'titulo' => 'Simulación de promoción'], '340px');
        ?>
        </div>
      </section>
      <section class="card p-4 xl:col-span-2 text-sm text-slate-600 space-y-3">
        <h3 class="font-bold text-slate-800">Cómo se calcula</h3>
        <p><b>Base:</b> <?= number_format($t['u0'] / max(1, $simRes['dias']), 1) ?> unidades por día de <?= number_format(count($simRes['productos'])) ?> producto(s),
          lo vendido del <?= e(fechaCorta($simRes['base'][0])) ?> al <?= e(fechaCorta($simRes['base'][1])) ?> (<?= (int) $simRes['base'][2] ?> días), al precio y costo que tuvieron.</p>
        <p><b>Con la promoción:</b> cada producto pasa a su precio de lista con el descuento; si ya se vendía más barato, se queda en su precio.</p>
        <p><b>Equilibrio:</b> margen de hoy ÷ margen con promoción a igual volumen − 1. Si las unidades suben menos que eso, se pierde margen.</p>
        <p class="text-xs text-slate-400">No incluye tráfico extra a otros productos, ni la venta que se adelanta y luego falta (compras que igual se iban a hacer). Úsalo como orden de magnitud, no como presupuesto.</p>
      </section>
    </div>

    <?php if ($simEsc):
      $mejor = array_reduce($simEsc, fn($c, $r) => $c === null || $r['dif'] > $c['dif'] ? $r : $c);
      $fmtValor = fn($v) => $sim['tipo'] === 'porcentaje' ? number_format($v, 0) . '%' : ck_money($v);
    ?>
    <section class="card overflow-hidden mb-5">
      <div class="p-4 border-b border-slate-100">
        <h3 class="font-bold text-slate-800">La misma promoción, más suave o más fuerte</h3>
        <p class="text-sm text-slate-400">El aumento de cada fila es el que dieron promociones de esa profundidad (±5 pts) en el último año; sin historia a esa profundidad, se usa el que elegiste arriba.
          Clic en una fila para simularla.</p>
      </div>
      <div class="overflow-x-auto">
        <table class="data-table text-[13px] whitespace-nowrap">
          <thead><tr><th>Descuento</th><th class="text-right">Rebaja real</th><th class="text-right">Equilibrio</th><th class="text-right">Aumento supuesto</th>
            <th class="text-right">Venta neta</th><th class="text-right">Descuento regalado</th><th class="text-right">Margen vs. no hacerla</th></tr></thead>
          <tbody>
          <?php foreach ($simEsc as $r): $actual = abs($r['valor'] - $sim['valor']) < 0.001; ?>
            <tr class="<?= $actual ? 'bg-blue-50/70' : 'hover:bg-slate-50' ?> cursor-pointer" onclick="location.href=<?= e(json_encode(ck_url(['s_valor' => $r['valor'], 's_aum' => null]))) ?>">
              <td class="font-semibold text-slate-700"><a href="<?= e(ck_url(['s_valor' => $r['valor'], 's_aum' => null])) ?>" class="hover:text-blue-600"><?= e($fmtValor($r['valor'])) ?></a>
                <?= $actual ? ' <span class="badge badge-blue">la tuya</span>' : '' ?><?= $r === $mejor && $r['dif'] > 0 ? ' <span class="badge badge-emerald">mejor margen</span>' : '' ?></td>
              <td class="text-right tabular-nums"><?= $r['profundidad'] !== null ? cockpit_pct($r['profundidad']) : '—' ?></td>
              <td class="text-right tabular-nums"><?= $r['pierde'] ? '<span class="text-rose-600 font-semibold">bajo costo</span>' : ($r['equilibrio'] === null ? '—' : '+' . number_format($r['equilibrio'], 0) . '%') ?></td>
              <td class="text-right tabular-nums"><?= ($r['aumento'] >= 0 ? '+' : '−') . number_format(abs($r['aumento']), 0) ?>%<?= $r['de_historia'] ? ' <span class="text-[11px] text-slate-400">histórico</span>' : '' ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['ns']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['regalo']) ?></td>
              <td class="text-right"><?= cockpit_celda_efecto($r['dif']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <section class="card overflow-hidden mb-5">
      <div class="p-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="font-bold text-slate-800">Productos afectados</h3>
          <p class="text-sm text-slate-400">Los de más venta<?= count($simRes['productos']) > 25 ? ' (25 de ' . number_format(count($simRes['productos'])) . '; la exportación trae todos)' : '' ?>. En rojo, los que quedarían por debajo del costo.</p>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="data-table text-[13px] whitespace-nowrap">
          <thead><tr><th>Producto</th><th class="text-right">Unid./día</th><th class="text-right">Lista</th><th class="text-right">Cobrado hoy → con promo</th><th class="text-right">Costo</th><th class="text-right">Margen por unidad</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($simRes['productos'], 0, 25) as $r): ?>
            <tr class="<?= $r['mu1'] < 0 ? 'bg-rose-50/60' : '' ?>">
              <td class="max-w-[300px]"><p class="font-semibold text-slate-700 truncate"><?= e($r['nombre']) ?></p><p class="text-xs text-slate-400"><?= e($r['codigo']) ?></p></td>
              <td class="text-right tabular-nums"><?= number_format($r['u_dia'], 1) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['lista']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['p0']) ?> → <b><?= cockpit_n($r['p1']) ?></b></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['c']) ?></td>
              <td class="text-right tabular-nums"><?= cockpit_n($r['mu0']) ?> → <b class="<?= $r['mu1'] < 0 ? 'text-rose-600' : '' ?>"><?= cockpit_n($r['mu1']) ?></b></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
  <script>
  function ckSim(d) {
    return Object.assign(d, {
      m(v) { return this.mon + ' ' + Math.round(v).toLocaleString('es-DO'); },
      ns1() { return this.nsb * (1 + this.a / 100); },
      m1() { return this.mu1 * (1 + this.a / 100); },
      dif() { return this.m1() - this.m0; },
      veredicto() {
        const d = this.dif(), s = this.m(Math.abs(d));
        if (this.mu1 <= 0) return 'Con este precio cada unidad se vende por debajo del costo: la promoción pierde ' + s + ' de margen y cuanto más venda, más pierde.';
        return d >= 0 ? 'La promoción gana ' + s + ' de margen frente a no hacerla.'
          : 'La promoción pierde ' + s + ' de margen' + (this.eq !== null ? ': necesita que las unidades suban al menos ' + Math.ceil(this.eq) + '%.' : '.');
      },
      pintar() {
        const el = this.$refs.graf && this.$refs.graf.querySelector('[id^="graf"]');
        const g = el && window.echarts && echarts.getInstanceByDom(el);
        if (!g) return;
        const data = [{ xAxis: this.a, name: 'Esperado', lineStyle: { color: '#2a78d6' }, label: { formatter: 'Esperado' } }];
        if (this.eq !== null && this.eq <= 200) data.push({ xAxis: Math.round(this.eq * 10) / 10, name: 'Equilibrio', lineStyle: { color: '#d97706' }, label: { formatter: 'Equilibrio' } });
        g.setOption({ series: [{ id: 'con', markLine: { silent: true, symbol: 'none', lineStyle: { type: 'dashed' }, label: { position: 'insideEndTop', fontSize: 11 }, data: data } }] });
        const campo = document.getElementById('s_aum'); if (campo) campo.value = this.a;
      }
    });
  }
  </script>
  <?php endif; ?>
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
