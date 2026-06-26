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
