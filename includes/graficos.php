<?php
/**
 * Gráficos interactivos (Apache ECharts, servido desde assets/js/vendor).
 *
 * El PHP arma la «option» de ECharts como un arreglo y grafico() la pinta en
 * un contenedor; assets/js/nexo-graficos.js le pone formato de cifras,
 * tooltips, ejes abreviados, zoom, «guardar imagen», «ver datos» (tabla
 * accesible) y el clic para profundizar (un punto con 'url' navega).
 *
 * Para los gráficos pequeños de siempre (sparkline, barras de tarjeta) sigue
 * existiendo includes/charts.php, que es SVG puro y no carga nada.
 *
 * Reglas (ver docs/PROMOTION-COCKPIT.md):
 *   · nunca dos ejes de valores en el mismo gráfico: dos medidas → dos gráficos;
 *   · el año anterior va en gris (GRAF_LY) y este año en el primer color;
 *   · los nombres que vienen de la base se escapan: en `tip` con e(), y el
 *     resto lo escapa el propio nexo-graficos.js.
 */

const GRAF_TY = '#2a78d6';
const GRAF_LY = '#c3c2b7';
const GRAF_BUENO = '#0ca30c';
const GRAF_MALO = '#d03b3b';

/** Carga las librerías una sola vez por página. */
function graficos_script(): string
{
    static $hecho = false;
    if ($hecho) return '';
    $hecho = true;
    return '<script src="' . e(asset('js/vendor/echarts.min.js')) . '"></script>'
        . '<script src="' . e(asset('js/nexo-graficos.js')) . '"></script>'
        . '<script>NexoGraficos.moneda=' . json_encode((string) setting('moneda', 'RD$')) . ';</script>';
}

/**
 * Contenedor + montaje de un gráfico.
 *
 * @param array  $option  option de ECharts (series, xAxis, yAxis…). En los ejes
 *                        de valor se puede poner 'formato' para cambiar el del gráfico.
 * @param array  $cfg     formato: money0|money|pct|num|dec|x · titulo · tipos: ['line','bar']
 *                        · herramientas: false para ocultar la caja de herramientas
 * @param string $alto    alto CSS del contenedor
 * @param string $aria    descripción para lectores de pantalla
 */
function grafico(array $option, array $cfg = [], string $alto = '320px', string $aria = ''): string
{
    static $n = 0;
    $id = 'graf' . (++$n) . substr(md5((string) mt_rand()), 0, 4);
    $json = json_encode($option, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    return graficos_script()
        . '<div id="' . $id . '" class="w-full" style="height:' . e($alto) . '" role="img" aria-label="' . e($aria ?: ($cfg['titulo'] ?? 'Gráfico')) . '"></div>'
        . '<script>NexoGraficos.montar(' . json_encode($id) . ',' . $json . ',' . $cfgJson . ');</script>';
}

/** Barras agrupadas «este año contra el anterior» sobre categorías. */
function grafico_ty_ly(array $categorias, array $ty, array $ly, array $cfg = [], string $alto = '300px', array $extra = []): string
{
    $horizontal = !empty($cfg['horizontal']);
    $cat = ['type' => 'category', 'data' => array_values($categorias), 'axisLabel' => ['interval' => 0, 'width' => 170, 'overflow' => 'truncate']];
    $val = ['type' => 'value'];
    $serie = fn(string $nombre, array $datos, string $color) => [
        'name' => $nombre, 'type' => 'bar', 'data' => array_values($datos), 'barMaxWidth' => 22, 'barGap' => '15%',
        'itemStyle' => ['color' => $color, 'borderRadius' => $horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]],
        'emphasis' => ['focus' => 'series'],
    ];
    unset($cfg['horizontal']);
    return grafico(array_replace_recursive([
        'legend' => ['data' => ['Este año', 'Año anterior']],
        'xAxis' => $horizontal ? $val : $cat,
        'yAxis' => $horizontal ? array_merge($cat, ['inverse' => true]) : $val,
        'series' => [$serie('Este año', $ty, GRAF_TY), $serie('Año anterior', $ly, GRAF_LY)],
    ], $extra), $cfg, $alto);
}

/** Líneas «este año contra el anterior» sobre un eje de tiempo o de meses. */
function grafico_lineas_ty_ly(array $etiquetas, array $ty, array $ly, array $cfg = [], string $alto = '260px', bool $zoom = false): string
{
    $opt = [
        'legend' => ['data' => ['Este año', 'Año anterior']],
        'xAxis' => ['type' => 'category', 'boundaryGap' => false, 'data' => array_values($etiquetas),
                    'axisLabel' => ['alignMinLabel' => 'left', 'alignMaxLabel' => 'right']],
        'yAxis' => ['type' => 'value', 'scale' => !empty($cfg['escala'])],
        'series' => [
            ['name' => 'Este año', 'type' => 'line', 'data' => array_values($ty), 'smooth' => 0.3, 'symbolSize' => 8, 'showSymbol' => count($etiquetas) <= 31,
             'lineStyle' => ['width' => 2, 'color' => GRAF_TY], 'itemStyle' => ['color' => GRAF_TY],
             'areaStyle' => ['color' => ['type' => 'linear', 'x' => 0, 'y' => 0, 'x2' => 0, 'y2' => 1,
                 'colorStops' => [['offset' => 0, 'color' => 'rgba(42,120,214,0.18)'], ['offset' => 1, 'color' => 'rgba(42,120,214,0)']]]]],
            ['name' => 'Año anterior', 'type' => 'line', 'data' => array_values($ly), 'smooth' => 0.3, 'symbolSize' => 8, 'showSymbol' => count($etiquetas) <= 31,
             'lineStyle' => ['width' => 2, 'color' => GRAF_LY, 'type' => 'dashed'], 'itemStyle' => ['color' => GRAF_LY]],
        ],
    ];
    if ($zoom) {
        $opt['dataZoom'] = [['type' => 'inside'], ['type' => 'slider', 'height' => 22, 'bottom' => 4, 'borderColor' => '#e2e8f0',
            'fillerColor' => 'rgba(42,120,214,0.12)', 'handleStyle' => ['color' => GRAF_TY]]];
    }
    unset($cfg['escala']);
    return grafico($opt, $cfg + ['tipos' => ['line', 'bar']], $alto);
}

/**
 * Cascada (puente): de un valor inicial a uno final pasando por los efectos.
 * @param array $pasos [[etiqueta, valor], …] entre el inicio y el fin
 */
function grafico_cascada(string $etqIni, float $ini, array $pasos, string $etqFin, float $fin, array $cfg = [], string $alto = '320px'): string
{
    $cats = [$etqIni]; $base = [0]; $vals = [['value' => round($ini, 2), 'itemStyle' => ['color' => GRAF_LY]]];
    $acum = $ini;
    foreach ($pasos as [$etq, $v]) {
        $cats[] = $etq;
        $v = (float) $v;
        $base[] = round($v >= 0 ? $acum : $acum + $v, 2);
        $vals[] = ['value' => round(abs($v), 2), 'real' => round($v, 2), 'itemStyle' => ['color' => $v >= 0 ? GRAF_BUENO : GRAF_MALO]];
        $acum += $v;
    }
    $cats[] = $etqFin; $base[] = 0; $vals[] = ['value' => round($fin, 2), 'itemStyle' => ['color' => GRAF_TY]];
    // El tooltip enseña el efecto con su signo, no la altura de la barra.
    $moneda = (string) setting('moneda', 'RD$');
    foreach ($vals as $i => &$d) {
        $real = $d['real'] ?? $d['value'];
        $d['tip'] = '<b>' . e($cats[$i]) . '</b><br>' . ($i > 0 && $i < count($vals) - 1 ? ($real >= 0 ? '+' : '−') : '')
            . e($moneda) . ' ' . number_format(abs($real), 0);
    }
    unset($d);
    return grafico([
        'tooltip' => ['trigger' => 'item'],
        'xAxis' => ['type' => 'category', 'data' => $cats, 'axisLabel' => ['interval' => 0, 'width' => 90, 'overflow' => 'break']],
        'yAxis' => ['type' => 'value', 'scale' => true],
        'series' => [
            ['name' => '_base', 'type' => 'bar', 'stack' => 't', 'data' => $base, 'itemStyle' => ['color' => 'transparent'], 'emphasis' => ['disabled' => true], 'tooltip' => ['show' => false]],
            ['name' => 'Margen', 'type' => 'bar', 'stack' => 't', 'data' => $vals, 'barMaxWidth' => 48, 'itemStyle' => ['borderRadius' => 4],
             'label' => ['show' => true, 'position' => 'top', 'fontSize' => 11, 'color' => '#52514e', 'formato' => 'num']],
        ],
    ], $cfg, $alto);
}
