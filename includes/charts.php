<?php
/** Mini-gráficos en SVG puro (sin dependencias). */

function sparkline(array $vals, string $color = '#2563eb', int $w = 120, int $h = 38): string
{
    $vals = array_values(array_map('floatval', $vals));
    if (count($vals) < 2) $vals = array_pad($vals, 2, 0);
    $max = max($vals); $min = min($vals);
    $range = ($max - $min) ?: 1;
    $n = count($vals); $step = $w / ($n - 1);
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i * $step, 2);
        $y = round($h - (($v - $min) / $range) * ($h - 6) - 3, 2);
        $pts[] = "$x,$y";
    }
    $line = implode(' ', $pts);
    $area = "0,$h " . $line . " $w,$h";
    $id = 'sg' . substr(md5($color . $line), 0, 6);
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="w-full h-10" preserveAspectRatio="none">'
        . '<defs><linearGradient id="' . $id . '" x1="0" x2="0" y1="0" y2="1">'
        . '<stop offset="0%" stop-color="' . $color . '" stop-opacity="0.28"/>'
        . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
        . '<polygon points="' . $area . '" fill="url(#' . $id . ')"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

/** Gráfico de barras vertical con CSS. $data = [ ['label'=>, 'value'=>], ... ] */
function barChart(array $data, string $color = 'bg-blue-500'): string
{
    $max = 0;
    foreach ($data as $d) $max = max($max, (float) $d['value']);
    $max = $max ?: 1;
    $html = '<div class="flex items-end gap-2 h-48">';
    foreach ($data as $d) {
        $pct = round(((float) $d['value'] / $max) * 100, 1);
        $html .= '<div class="flex-1 flex flex-col items-center gap-2 group min-w-0">'
            . '<div class="w-full flex items-end justify-center h-40">'
            . '<div class="w-full max-w-[34px] ' . $color . ' rounded-t-lg transition-all duration-500 relative group-hover:opacity-80" style="height:' . max($pct, 1.5) . '%">'
            . '<span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[11px] font-semibold text-slate-700 bg-white border border-slate-200 rounded-md px-1.5 py-0.5 opacity-0 group-hover:opacity-100 transition whitespace-nowrap shadow-sm">' . money($d['value'], false) . '</span>'
            . '</div></div>'
            . '<span class="text-[10.5px] text-slate-400 font-medium truncate w-full text-center">' . e($d['label']) . '</span>'
            . '</div>';
    }
    return $html . '</div>';
}

/**
 * Número abreviado para ejes y etiquetas: 1 250 000 → 1.25M, 200 000 → 200K.
 * Con cifras grandes, el eje completo no cabe y se recorta contra el borde.
 */
function numAbrev($v): string
{
    $v = (float) $v;
    $a = abs($v);
    if ($a >= 1000000) return rtrim(rtrim(number_format($v / 1000000, 2, '.', ''), '0'), '.') . 'M';
    if ($a >= 1000)    return rtrim(rtrim(number_format($v / 1000, 1, '.', ''), '0'), '.') . 'K';
    if ($a >= 1)       return number_format($v, 0);
    return $a < 0.005 ? '0' : number_format($v, 2);
}

/**
 * Gráfico de líneas / área en SVG puro, con rejilla y eje de valores.
 *
 * @param array $series [['nombre'=>'Ventas','color'=>'#2563eb','valores'=>[1,2,3],'area'=>true], ...]
 * @param array $labels etiquetas del eje X (misma longitud que los valores)
 * @param array $opts   alto (px del viewBox), formato ('money'|'num'), leyenda (bool)
 */
function lineChart(array $series, array $labels, array $opts = []): string
{
    $W = 760;
    $H = (int) ($opts['alto'] ?? 260);
    $padT = 16; $padB = 32;
    $esMoneda = ($opts['formato'] ?? 'money') === 'money';
    // El eje se abrevia (200K) y el tooltip lleva la cifra exacta.
    $fmtEje  = fn($v) => numAbrev($v);
    $fmtReal = $esMoneda ? fn($v) => money($v) : fn($v) => number_format((float) $v, 0);

    $n = count($labels);
    if ($n === 0 || !$series) return '<p class="text-sm text-slate-400 py-10 text-center">Sin datos para graficar.</p>';

    $max = 0.0;
    foreach ($series as $s) foreach ($s['valores'] as $v) $max = max($max, (float) $v);
    // Escala «bonita»: redondea el techo hacia arriba a 1/2/5 × 10^k.
    if ($max <= 0) { $max = 1.0; }
    $exp  = (int) floor(log10($max));
    $base = pow(10, $exp);
    $mult = $max / $base;
    $mult = $mult <= 1 ? 1 : ($mult <= 2 ? 2 : ($mult <= 5 ? 5 : 10));
    $techo = $mult * $base;

    // El margen izquierdo se calcula con la etiqueta más ancha del eje: fijarlo
    // a ojo es lo que hacía que «200,000.00» se cortara contra el borde.
    $anchoEtiqueta = 0;
    for ($g = 0; $g <= 4; $g++) {
        $anchoEtiqueta = max($anchoEtiqueta, mb_strlen($fmtEje($techo * $g / 4)));
    }
    $padL = (int) (14 + $anchoEtiqueta * 6.4);
    // Margen derecho: la última etiqueta del eje X se ancla al final y necesita aire.
    $padR = 22;
    $iw = $W - $padL - $padR;
    $ih = $H - $padT - $padB;

    $x = fn($i) => $padL + ($n === 1 ? $iw / 2 : $i * ($iw / ($n - 1)));
    $y = fn($v) => $padT + $ih - (min((float) $v, $techo) / $techo) * $ih;

    $uid = 'lc' . substr(md5(json_encode($labels) . count($series) . mt_rand()), 0, 6);
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="w-full h-auto" role="img" preserveAspectRatio="xMidYMid meet">';

    // Rejilla + eje Y (5 marcas)
    for ($g = 0; $g <= 4; $g++) {
        $val = $techo * $g / 4;
        $yy  = round($y($val), 1);
        $svg .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="' . ($g === 0 ? '0' : '3 4') . '"/>';
        $svg .= '<text x="' . ($padL - 9) . '" y="' . ($yy + 3.5) . '" text-anchor="end" font-size="10.5" fill="#94a3b8" font-family="Inter,sans-serif">' . e($fmtEje($val)) . '</text>';
    }

    // Etiquetas X. La primera y la última se anclan hacia dentro para que no se
    // salgan del lienzo; el resto va centrado bajo su punto.
    $paso = max(1, (int) ceil($n / 12));
    foreach ($labels as $i => $lbl) {
        if ($i % $paso !== 0 && $i !== $n - 1) continue;
        $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
        $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 11) . '" text-anchor="' . $anchor . '" font-size="10.5" fill="#94a3b8" font-family="Inter,sans-serif">' . e($lbl) . '</text>';
    }

    foreach ($series as $k => $s) {
        $color = $s['color'] ?? rep_color($k);
        $pts = [];
        foreach ($s['valores'] as $i => $v) $pts[] = round($x($i), 1) . ',' . round($y($v), 1);
        if (count($pts) === 1) $pts[] = $pts[0];
        $linea = implode(' ', $pts);

        if (!empty($s['area'])) {
            $gid = $uid . 'g' . $k;
            $svg .= '<defs><linearGradient id="' . $gid . '" x1="0" x2="0" y1="0" y2="1">'
                . '<stop offset="0%" stop-color="' . $color . '" stop-opacity="0.25"/>'
                . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>';
            $svg .= '<polygon points="' . round($x(0), 1) . ',' . ($padT + $ih) . ' ' . $linea . ' ' . round($x($n - 1), 1) . ',' . ($padT + $ih) . '" fill="url(#' . $gid . ')"/>';
        }
        $dash = !empty($s['punteada']) ? ' stroke-dasharray="5 4"' : '';
        $svg .= '<polyline points="' . $linea . '" fill="none" stroke="' . $color . '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"' . $dash . '/>';

        // Puntos con tooltip nativo (aquí sí va la cifra exacta, no la abreviada)
        foreach ($s['valores'] as $i => $v) {
            if ($n > 40 && $i % $paso !== 0) continue;
            $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="3.5" fill="#fff" stroke="' . $color . '" stroke-width="2">'
                . '<title>' . e(($s['nombre'] ?? '') . ' · ' . ($labels[$i] ?? '') . ': ' . $fmtReal($v)) . '</title></circle>';
        }
    }
    $svg .= '</svg>';

    $leyenda = '';
    if (($opts['leyenda'] ?? true) && count($series) > 0) {
        $leyenda = '<div class="flex flex-wrap items-center gap-4 mt-3">';
        foreach ($series as $k => $s) {
            $leyenda .= '<span class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500">'
                . '<span class="w-3 h-3 rounded-full" style="background:' . e($s['color'] ?? rep_color($k)) . '"></span>'
                . e($s['nombre'] ?? 'Serie ' . ($k + 1)) . '</span>';
        }
        $leyenda .= '</div>';
    }
    return $svg . $leyenda;
}

/**
 * Barras verticales comparando dos series (actual vs. anterior).
 * @param array $data [['label'=>, 'a'=>float, 'b'=>float], ...]
 */
function barChartComparado(array $data, string $nombreA = 'Actual', string $nombreB = 'Anterior', string $colorA = '#2563eb', string $colorB = '#cbd5e1'): string
{
    $max = 0.0;
    foreach ($data as $d) $max = max($max, (float) $d['a'], (float) ($d['b'] ?? 0));
    $max = $max ?: 1;

    $h = '<div class="flex items-end gap-2 h-52 overflow-x-auto pb-1">';
    foreach ($data as $d) {
        $pa = round((float) $d['a'] / $max * 100, 1);
        $pb = round((float) ($d['b'] ?? 0) / $max * 100, 1);
        $h .= '<div class="flex-1 min-w-[42px] flex flex-col items-center gap-2 group">'
            . '<div class="w-full flex items-end justify-center gap-1 h-40">'
            . '<div class="w-1/2 max-w-[20px] rounded-t-md transition-all duration-500 relative" style="height:' . max($pb, 1) . '%;background:' . $colorB . '">'
            . '<title></title></div>'
            . '<div class="w-1/2 max-w-[20px] rounded-t-md transition-all duration-500 relative" style="height:' . max($pa, 1) . '%;background:' . $colorA . '">'
            . '<span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[11px] font-semibold text-slate-700 bg-white border border-slate-200 rounded-md px-1.5 py-0.5 opacity-0 group-hover:opacity-100 transition whitespace-nowrap shadow-sm z-10">'
            . money($d['a'], false) . '</span></div>'
            . '</div>'
            . '<span class="text-[10.5px] text-slate-400 font-medium truncate w-full text-center">' . e($d['label']) . '</span>'
            . '</div>';
    }
    $h .= '</div>';
    $h .= '<div class="flex items-center gap-4 mt-3">'
        . '<span class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500"><span class="w-3 h-3 rounded-sm" style="background:' . $colorA . '"></span>' . e($nombreA) . '</span>'
        . '<span class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500"><span class="w-3 h-3 rounded-sm" style="background:' . $colorB . '"></span>' . e($nombreB) . '</span>'
        . '</div>';
    return $h;
}

/**
 * Dona de varios segmentos con leyenda.
 * @param array $items [['label'=>, 'value'=>float, 'color'=>'#hex'], ...]
 */
function donutMulti(array $items, string $centroTitulo = '', string $centroValor = ''): string
{
    $total = 0.0;
    foreach ($items as $i) $total += (float) $i['value'];
    if ($total <= 0) return '<p class="text-sm text-slate-400 py-10 text-center">Sin datos para graficar.</p>';

    $r = 54; $c = 2 * M_PI * $r; $off = 0.0;
    $svg = '<svg viewBox="0 0 140 140" class="w-40 h-40 shrink-0 -rotate-90">';
    $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="#f1f5f9" stroke-width="20"/>';
    foreach ($items as $k => $it) {
        $frac = (float) $it['value'] / $total;
        if ($frac <= 0) continue;
        $len  = $frac * $c;
        $col  = $it['color'] ?? rep_color($k);
        $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . e($col) . '" stroke-width="20"'
            . ' stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '"'
            . ' stroke-dashoffset="' . round(-$off, 2) . '"><title>' . e($it['label'] . ': ' . money($it['value']) . ' (' . round($frac * 100, 1) . '%)') . '</title></circle>';
        $off += $len;
    }
    $svg .= '</svg>';

    $centro = '';
    if ($centroValor !== '') {
        $centro = '<div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">'
            . '<span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">' . e($centroTitulo) . '</span>'
            . '<span class="text-base font-extrabold text-slate-800">' . $centroValor . '</span></div>';
    }

    // Leyenda con barra propia: con pocos segmentos, una lista de texto suelta
    // deja la mitad de la tarjeta en blanco.
    $leyenda = '<div class="flex-1 min-w-0 w-full space-y-3">';
    foreach ($items as $k => $it) {
        $pct = round((float) $it['value'] / $total * 100, 1);
        $col = e($it['color'] ?? rep_color($k));
        $leyenda .= '<div>'
            . '<div class="flex items-center gap-2.5 text-sm mb-1.5">'
            . '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' . $col . '"></span>'
            . '<span class="text-slate-600 font-medium truncate flex-1">' . e($it['label']) . '</span>'
            . '<span class="text-slate-700 font-semibold tabular-nums whitespace-nowrap">' . money($it['value'], false) . '</span>'
            . '<span class="text-slate-400 text-xs w-12 text-right tabular-nums">' . $pct . '%</span></div>'
            . '<div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">'
            . '<div class="h-full rounded-full transition-all duration-700" style="width:' . max($pct, 0.8) . '%;background:' . $col . '"></div>'
            . '</div></div>';
    }
    $leyenda .= '</div>';

    return '<div class="flex flex-col sm:flex-row sm:items-center gap-6">'
        . '<div class="relative shrink-0 mx-auto sm:mx-0">' . $svg . $centro . '</div>' . $leyenda . '</div>';
}

/** Barra apilada horizontal de 100% (composición de un total). */
function barraApilada(array $items): string
{
    $total = 0.0;
    foreach ($items as $i) $total += (float) $i['value'];
    if ($total <= 0) return '';
    $h = '<div class="flex h-3 rounded-full overflow-hidden bg-slate-100">';
    foreach ($items as $k => $it) {
        $p = (float) $it['value'] / $total * 100;
        if ($p <= 0) continue;
        $h .= '<div style="width:' . round($p, 2) . '%;background:' . e($it['color'] ?? rep_color($k)) . '" title="' . e($it['label'] . ': ' . round($p, 1) . '%')  . '"></div>';
    }
    return $h . '</div>';
}

/** Anillo de progreso (donut simple de un valor). */
function donut(float $pct, string $color = '#2563eb', int $size = 84): string
{
    $r = 32; $c = 2 * M_PI * $r;
    $off = $c * (1 - max(0, min(100, $pct)) / 100);
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 80 80" class="-rotate-90">'
        . '<circle cx="40" cy="40" r="' . $r . '" fill="none" stroke="#e2e8f0" stroke-width="8"/>'
        . '<circle cx="40" cy="40" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="8" stroke-linecap="round" stroke-dasharray="' . round($c, 2) . '" stroke-dashoffset="' . round($off, 2) . '"/>'
        . '</svg>';
}
