<?php
/**
 * Centro de Reportes — cimientos compartidos.
 *
 * Todos los reportes usan el mismo periodo, el mismo alcance por sucursal, la
 * misma barra de filtros y las mismas tarjetas de KPI. Así la dirección, finanzas
 * y contabilidad leen SIEMPRE los mismos números con la misma cara.
 */

/* ============================================================
 *  Catálogo de reportes (fuente única: hub, menú y buscador)
 * ============================================================ */

/**
 * @return array<string,array{titulo:string,descripcion:string,icono:string,color:string,permiso:string,reportes:array}>
 */
function rep_catalogo(): array
{
    return [
        // Las tres pantallas de la CEO viven fuera de modules/reportes/ (tienen su
        // propio permiso y su propia navegación), pero se listan aquí para que el
        // hub sea de verdad el índice de todo lo que se puede consultar. Una ruta
        // con «/» se resuelve desde modules/.
        'ceo' => [
            'titulo' => 'Área de Dirección',
            'descripcion' => 'El tablero de la CEO: año contra año, costos y carga histórica.',
            'icono' => 'trending', 'color' => 'violet', 'permiso' => 'direccion.ver',
            'reportes' => [
                ['direccion/index.php', 'Panel de Dirección', 'Año contra año, mes contra mes, ventas por marca y mercancía en camino, en una sola pantalla.', 'dashboard'],
                ['direccion/comparativo.php', 'Año contra año', 'Matriz de doce meses con los dos años lado a lado y la variación de cada mes, por tienda, sucursal y categoría.', 'chart'],
                ['direccion/costos.php', 'Reportería de costos', 'Costo de lo vendido, margen real, inventario a costo, recargo de importación y artículos que se venden bajo costo.', 'coins'],
            ],
        ],
        'direccion' => [
            'titulo' => 'Dirección General',
            'descripcion' => 'La foto completa del negocio para quien decide.',
            'icono' => 'trending', 'color' => 'violet', 'permiso' => 'reportes.ejecutivo',
            'reportes' => [
                ['ejecutivo.php', 'Panel ejecutivo', 'KPIs del periodo, tendencia de 12 meses, márgenes, metas y alertas en una sola pantalla.', 'dashboard'],
                ['comparativo.php', 'Comparativo de periodos', 'Mes contra mes y año contra año, con variación por sucursal, canal y categoría.', 'chart'],
                ['clientes.php', 'Clientes y concentración', 'Ranking ABC (Pareto), recencia, frecuencia y riesgo de dependencia de pocos clientes.', 'users'],
                // Quinto elemento = permiso PROPIO del informe. Quien lo tenga ve
                // este y solo este, sin entrar al resto del grupo.
                ['sucursales.php', 'Comparativo de sucursales', 'Venta, margen, ticket y productividad por local, lado a lado.', 'store', 'reportes.sucursales'],
            ],
        ],
        'finanzas' => [
            'titulo' => 'Finanzas',
            'descripcion' => 'Rentabilidad, liquidez y cobranza.',
            'icono' => 'dollar', 'color' => 'emerald', 'permiso' => 'reportes.finanzas',
            'reportes' => [
                ['estado_resultados.php', 'Estado de resultados', 'P&L mensual con % sobre ventas y comparación contra el periodo anterior.', 'receipt'],
                ['flujo_caja.php', 'Flujo de efectivo', 'Entradas, salidas y saldo acumulado por día y por cuenta.', 'cash'],
                ['cxc.php', 'Cuentas por cobrar', 'Antigüedad de saldos 0-30/31-60/61-90/+90 por cliente y exposición al crédito.', 'wallet'],
                ['cxp.php', 'Cuentas por pagar', 'Compras a crédito por proveedor con antigüedad y vencimientos.', 'truck'],
                ['gastos.php', 'Análisis de gastos', 'Gasto por categoría y sucursal, tendencia mensual y peso sobre la venta.', 'pie'],
                ['rentabilidad.php', 'Rentabilidad', 'Margen por producto, categoría, sucursal y vendedor. Detecta ventas bajo costo.', 'percent'],
            ],
        ],
        'contabilidad' => [
            'titulo' => 'Contabilidad',
            'descripcion' => 'Soporte para el cierre y la DGII.',
            'icono' => 'shield', 'color' => 'blue', 'permiso' => 'reportes.contabilidad',
            'reportes' => [
                ['libro_diario.php', 'Libro diario', 'Todos los asientos del periodo: ventas, compras, gastos, nómina y movimientos de caja.', 'list'],
                ['balance.php', 'Balance general', 'Activo, pasivo y patrimonio a una fecha, derivado de los saldos del sistema.', 'layers'],
                ['impuestos.php', 'ITBIS y retenciones', 'ITBIS cobrado vs. adelantado, retenciones y saldo a pagar del periodo.', 'percent'],
                // Quinto elemento = permiso propio: revisar existencias no obliga a
                // abrir el libro diario ni la nómina. Ver rep_catalogo_visible().
                ['inventario_valorizado.php', 'Inventario valorizado', 'Existencias a costo y a precio de venta, por sucursal y categoría.', 'box', 'reportes.inventario'],
                ['nomina.php', 'Resumen de nómina', 'Bruto, AFP, SFS, ISR y neto por periodo, empleado y departamento.', 'id'],
                // Mira el presente, no lo ya pagado: cuánto cuesta hoy la gente
                // contratada. Es la cifra para decidir si un local se sostiene.
                ['costo_plantilla.php', 'Costo de la plantilla', 'Lo que cuesta la gente contratada hoy, por sucursal y por marca, con los aportes patronales.', 'users'],
                ['auxiliar_cuentas.php', 'Auxiliar de cuentas', 'Mayor por cuenta financiera con saldo inicial, movimientos y saldo final.', 'briefcase'],
            ],
        ],
        'sanidad' => [
            'titulo' => 'Cumplimiento sanitario',
            'descripcion' => 'La evidencia que piden Salud Publica, PROCONSUMIDOR, Agricultura e INDOCAL.',
            'icono' => 'shield', 'color' => 'rose', 'permiso' => 'reportes.sanidad',
            'reportes' => [
                ['expediente_auditoria.php', 'Expediente de auditoria', 'El documento consolidado para entregar en una inspeccion: semaforo de cumplimiento, registros, vencidos y proveedores.', 'file'],
                ['registros_sanitarios.php', 'Registros sanitarios', 'Vigencia del registro de cada producto regulado: sin registro, vencidos y por vencer.', 'shield'],
                ['vencimientos.php', 'Control de vencimientos', 'Mercancia vencida y proxima a vencer por lote y sucursal, con el dinero inmovilizado.', 'clock'],
                ['trazabilidad.php', 'Trazabilidad de lote', 'Retiro del mercado: de que proveedor entro un lote y a que clientes salio, con sus facturas.', 'search'],
                ['proveedores_sanitario.php', 'Ficha sanitaria de proveedores', 'Licencia, vigencia y que productos regulados surte cada proveedor.', 'truck'],
            ],
        ],
        'operacion' => [
            'titulo' => 'Operación y Ventas',
            'descripcion' => 'El día a día del piso de venta.',
            'icono' => 'cart', 'color' => 'amber', 'permiso' => 'reportes.operacion',
            'reportes' => [
                ['ventas_detalle.php', 'Libro de ventas', 'Detalle factura por factura con NCF, cliente, vendedor, método de pago y margen.', 'receipt'],
                ['productos.php', 'Desempeño de productos', 'Más vendidos, sin rotación, quiebres de stock y días de inventario.', 'package'],
                ['vendedores.php', 'Desempeño del equipo', 'Venta, margen, ticket promedio, descuentos y cumplimiento de meta por vendedor.', 'users'],
                ['horarios.php', 'Horarios y tráfico', 'A qué hora y qué día se vende, para ajustar turnos e inventario.', 'clock'],
            ],
        ],
    ];
}

/**
 * Reportes visibles para el usuario actual.
 *
 * Los permisos van por GRUPO —dirección, finanzas, contabilidad, operación,
 * sanidad—, que es lo razonable para casi todo. Pero había informes atrapados
 * en el grupo equivocado para ciertos puestos: comparar la venta de los locales
 * vivía dentro del paquete de la CEO, así que dárselo a una encargada le abría
 * también la utilidad de la empresa y el ranking de clientes.
 *
 * Por eso un informe puede declarar su PROPIO permiso (quinto elemento). Quien
 * tenga el del grupo sigue viéndolo todo; quien solo tenga el del informe ve
 * ese y nada más, y el grupo aparece con esa única tarjeta dentro.
 */
function rep_catalogo_visible(): array
{
    $out = [];
    foreach (rep_catalogo() as $k => $g) {
        if (can($g['permiso'])) { $out[$k] = $g; continue; }
        $sueltos = array_values(array_filter($g['reportes'],
            fn($r) => isset($r[4]) && can($r[4])));
        if ($sueltos) $out[$k] = array_merge($g, ['reportes' => $sueltos]);
    }
    return $out;
}

/* ============================================================
 *  Periodo
 * ============================================================ */

/** Presets del selector de periodo: clave => etiqueta. */
function rep_presets(): array
{
    return [
        'hoy'         => 'Hoy',
        'ayer'        => 'Ayer',
        'semana'      => 'Esta semana',
        'mes'         => 'Este mes',
        'mes_pasado'  => 'Mes pasado',
        'trimestre'   => 'Trimestre',
        'anio'        => 'Este año',
        'anio_pasado' => 'Año pasado',
    ];
}

/**
 * Resuelve el periodo del reporte desde la URL.
 *
 * @return array{desde:string,hasta:string,ini:string,fin:string,label:string,preset:string,
 *               dias:int,prev_desde:string,prev_hasta:string,prev_ini:string,prev_fin:string}
 */
function rep_periodo(string $porDefecto = 'mes'): array
{
    $preset = (string) get('periodo', '');
    $desde  = trim((string) get('desde'));
    $hasta  = trim((string) get('hasta'));

    // Fechas explícitas mandan sobre el preset.
    if ($desde !== '' || $hasta !== '') {
        $preset = 'personalizado';
    } elseif ($preset === '' || (!isset(rep_presets()[$preset]) && $preset !== 'personalizado')) {
        $preset = $porDefecto;
    }

    switch ($preset) {
        case 'hoy':         $d = date('Y-m-d');  $h = date('Y-m-d'); break;
        case 'ayer':        $d = date('Y-m-d', strtotime('-1 day')); $h = $d; break;
        case 'semana':      $d = date('Y-m-d', strtotime('monday this week')); $h = date('Y-m-d'); break;
        case 'mes_pasado':  $d = date('Y-m-01', strtotime('first day of last month'));
                            $h = date('Y-m-t', strtotime('last day of last month')); break;
        case 'trimestre':   $tri = (int) ceil((int) date('n') / 3);
                            $d = date('Y-m-01', mktime(0, 0, 0, ($tri - 1) * 3 + 1, 1));
                            $h = date('Y-m-t', mktime(0, 0, 0, $tri * 3, 1)); break;
        case 'anio':        $d = date('Y-01-01'); $h = date('Y-12-31'); break;
        case 'anio_pasado': $d = date('Y-01-01', strtotime('-1 year')); $h = date('Y-12-31', strtotime('-1 year')); break;
        case 'personalizado':
            $d = ($desde && strtotime($desde)) ? date('Y-m-d', strtotime($desde)) : date('Y-m-01');
            $h = ($hasta && strtotime($hasta)) ? date('Y-m-d', strtotime($hasta)) : date('Y-m-t');
            break;
        case 'mes':
        default:            $d = date('Y-m-01'); $h = date('Y-m-t'); $preset = 'mes'; break;
    }
    if ($d > $h) [$d, $h] = [$h, $d];

    $dias = (int) floor((strtotime($h) - strtotime($d)) / 86400) + 1;

    // Periodo anterior de la misma longitud, justo antes.
    if ($preset === 'mes' || $preset === 'mes_pasado') {
        $pd = date('Y-m-01', strtotime($d . ' -1 month'));
        $ph = date('Y-m-t', strtotime($d . ' -1 month'));
    } elseif ($preset === 'anio' || $preset === 'anio_pasado') {
        $pd = date('Y-m-d', strtotime($d . ' -1 year'));
        $ph = date('Y-m-d', strtotime($h . ' -1 year'));
    } else {
        $ph = date('Y-m-d', strtotime($d . ' -1 day'));
        $pd = date('Y-m-d', strtotime($ph . ' -' . ($dias - 1) . ' day'));
    }

    $labels = rep_presets();
    $label  = $labels[$preset] ?? (fechaCorta($d) . ' al ' . fechaCorta($h));
    if ($preset !== 'hoy' && $preset !== 'ayer') {
        $label .= ' · ' . fechaCorta($d) . ' al ' . fechaCorta($h);
    } else {
        $label = $labels[$preset] . ' · ' . fechaCorta($d);
    }

    return [
        'desde' => $d, 'hasta' => $h, 'ini' => $d . ' 00:00:00', 'fin' => $h . ' 23:59:59',
        'label' => $label, 'preset' => $preset, 'dias' => $dias,
        'prev_desde' => $pd, 'prev_hasta' => $ph,
        'prev_ini' => $pd . ' 00:00:00', 'prev_fin' => $ph . ' 23:59:59',
    ];
}

/** Nombre de la sucursal en curso para los encabezados. */
function rep_alcance_sucursal(): string
{
    $f = sucursalFiltroActual();
    if ($f) {
        return (string) (qVal("SELECT nombre FROM sucursales WHERE id = ?", [$f]) ?: 'Sucursal');
    }
    $sid = current_sucursal_id();
    if ($sid === null) return 'Todas las sucursales';
    return (string) (current_user()['sucursal_nombre'] ?? 'Sucursal');
}

/* ============================================================
 *  UI compartida
 * ============================================================ */

/** Variación porcentual entre dos periodos. null cuando no hay base de comparación. */
function rep_delta(float $actual, float $anterior): ?float
{
    if (abs($anterior) < 0.005) return $actual > 0 ? 100.0 : null;
    return round(($actual - $anterior) / abs($anterior) * 100, 1);
}

/**
 * Tarjeta de KPI y su rejilla.
 *
 * La implementación vive en components.php: un informe y un listado no pueden
 * enseñar dos tarjetas distintas del mismo dato. Se conservan los nombres
 * `rep_*` porque los usan los 27 informes.
 */
function rep_kpi(array $o): string { return kpi($o); }

function rep_kpis(array $kpis, int $cols = 4): string { return kpis($kpis, $cols); }

/**
 * Cabecera de una sección/tarjeta de reporte. Se cierra con rep_fin().
 *
 * La tarjeta ocupa TODA la altura de su celda del grid (`h-full`): si no, dos
 * tarjetas de distinta altura en la misma fila dejan un escalón blanco feo.
 */
function rep_seccion(string $titulo, string $sub = '', string $icono = '', string $color = 'blue', string $extra = ''): string
{
    $h  = '<section class="card overflow-hidden mb-5 print-break h-full flex flex-col">';
    $h .= '<div class="flex items-start justify-between gap-3 p-5 pb-4">';
    $h .= '<div class="flex items-start gap-3 min-w-0">';
    if ($icono) {
        $h .= '<span class="w-10 h-10 rounded-xl ' . ui_tono($color) . ' flex items-center justify-center shrink-0">'
            . icon($icono, 'w-5 h-5') . '</span>';
    }
    $h .= '<div class="min-w-0"><h3 class="font-bold text-slate-800 leading-tight">' . e($titulo) . '</h3>';
    if ($sub) $h .= '<p class="text-sm text-slate-400 mt-0.5">' . e($sub) . '</p>';
    $h .= '</div></div>';
    if ($extra) $h .= '<div class="shrink-0 flex items-center gap-2 flex-wrap justify-end">' . $extra . '</div>';
    // El cuerpo crece para empujar el pie hasta abajo y que las tarjetas de una
    // misma fila terminen alineadas.
    return $h . '</div><div class="flex-1 flex flex-col">';
}

function rep_fin(): string
{
    return '</div></section>';
}

/**
 * Tabla de reporte.
 * $headers: ['Texto', ['Texto','right'], ...]   $filas: [[celda, ...], ...] (HTML ya escapado)
 * $opts: total (fila de totales), vacio (mensaje), compacta (bool)
 */
function rep_tabla(array $headers, array $filas, array $opts = []): string
{
    if (!$filas) {
        // Centrado y estirado: dentro de una tarjeta alta, un mensaje pegado
        // arriba deja un hueco blanco enorme debajo.
        return '<div class="flex-1 flex items-center justify-center px-5 pb-6">' . empty_state(
            $opts['vacio_titulo'] ?? 'Sin datos en este periodo',
            $opts['vacio'] ?? 'Prueba con otro rango de fechas o cambia la sucursal.',
            $opts['vacio_icono'] ?? 'chart'
        ) . '</div>';
    }
    $al = fn($a) => $a === 'right' ? ' class="text-right"' : ($a === 'center' ? ' class="text-center"' : '');

    $h = '<div class="overflow-x-auto"><table class="data-table"><thead><tr>';
    foreach ($headers as $th) {
        [$txt, $a] = is_array($th) ? [$th[0], $th[1] ?? ''] : [$th, ''];
        $h .= '<th' . $al($a) . '>' . e($txt) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($filas as $f) {
        $h .= '<tr>';
        foreach ($f as $i => $celda) {
            $th = $headers[$i] ?? '';
            $a  = is_array($th) ? ($th[1] ?? '') : '';
            $h .= '<td' . $al($a) . '>' . $celda . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody>';
    if (!empty($opts['total'])) {
        $h .= '<tfoot><tr class="bg-slate-50 font-bold text-slate-800">';
        foreach ($opts['total'] as $i => $celda) {
            $th = $headers[$i] ?? '';
            $a  = is_array($th) ? ($th[1] ?? '') : '';
            $cls = $a === 'right' ? 'text-right' : ($a === 'center' ? 'text-center' : '');
            $h .= '<td class="px-4 py-3.5 border-t-2 border-slate-200 ' . $cls . '">' . $celda . '</td>';
        }
        $h .= '</tr></tfoot>';
    }
    return $h . '</table></div>';
}

/** Barra horizontal con etiqueta, valor y porcentaje. */
function rep_barra(string $label, string $valor, float $pct, string $colorHex = '', string $sub = ''): string
{
    $colorHex = $colorHex ?: marca_app();
    $pct = max(0, min(100, $pct));
    return '<div class="mb-3.5">'
        . '<div class="flex items-baseline justify-between gap-3 text-sm mb-1.5">'
        . '<span class="font-medium text-slate-600 truncate">' . e($label)
        . ($sub ? ' <span class="text-slate-400 font-normal">· ' . e($sub) . '</span>' : '') . '</span>'
        . '<span class="text-slate-500 font-semibold whitespace-nowrap tabular-nums">' . $valor
        . ' <span class="text-slate-400 font-medium">' . number_format($pct, 1) . '%</span></span></div>'
        . '<div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">'
        . '<div class="h-full rounded-full transition-all duration-700" style="width:' . max($pct, 0.8) . '%;background:' . e($colorHex) . '"></div>'
        . '</div></div>';
}

/** Paleta estable para categorías/series. */
function rep_color(int $i): string
{
    $p = [marca_app(), '#10b981', '#f59e0b', '#8b5cf6', '#f43f5e', '#06b6d4', '#6366f1', '#ec4899', '#84cc16', '#0ea5e9', '#f97316', '#64748b'];
    return $p[$i % count($p)];
}

/** Mapea el color nombrado de una categoría a hexadecimal. */
function rep_color_nombre(?string $nombre): string
{
    $m = ['blue' => marca_app(), 'emerald' => '#10b981', 'amber' => '#f59e0b', 'rose' => '#f43f5e',
          'indigo' => '#6366f1', 'cyan' => '#06b6d4', 'sky' => '#0ea5e9', 'pink' => '#ec4899',
          'slate' => '#64748b', 'violet' => '#8b5cf6'];
    return $m[$nombre ?? ''] ?? '#64748b';
}

/**
 * Barra de filtros estándar de los reportes: periodo + sucursal + acciones.
 * $opts: sucursal (bool), extra (HTML de campos adicionales), acciones (HTML)
 *
 * NO todos los informes tienen periodo. «Control de vencimientos», «Registros
 * sanitarios» y «Proveedores con registro» son fotos del día de hoy: preguntan
 * cómo está el inventario AHORA, no qué pasó entre dos fechas. Esos pasan un
 * `$p` con solo `label`, y hasta ahora se les pintaba igual el selector de
 * periodo —que no hacía nada— mientras PHP avisaba de seis claves inexistentes
 * en cada carga. Si no hay periodo, no se dibuja: el filtro que miente es peor
 * que el filtro que falta.
 */
function rep_filtros(array $p, array $opts = []): string
{
    $base = $_GET;
    unset($base['periodo'], $base['desde'], $base['hasta'], $base['export'], $base['p']);

    $conPeriodo = isset($p['preset']);
    $abierto = $conPeriodo && $p['preset'] === 'personalizado';

    $h  = '<div class="card p-4 mb-5 no-print" x-data="{avanzado:' . ($abierto ? 'true' : 'false') . '}">';
    $h .= '<div class="flex flex-wrap items-center gap-2">';

    // Presets como segmentos.
    if ($conPeriodo) {
        $h .= '<div class="flex flex-wrap items-center gap-1 p-1 bg-slate-100 rounded-xl">';
        foreach (rep_presets() as $k => $lbl) {
            $qs = array_merge($base, ['periodo' => $k]);
            $act = $p['preset'] === $k;
            $h .= '<a href="?' . e(http_build_query($qs)) . '" class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition '
                . ($act ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800') . '">' . e($lbl) . '</a>';
        }
        $h .= '<button type="button" @click="avanzado=!avanzado" :class="avanzado ? \'bg-white text-blue-700 shadow-sm\' : \'text-slate-500 hover:text-slate-800\'"'
            . ' class="px-3 py-1.5 rounded-lg text-[13px] font-semibold transition inline-flex items-center gap-1.5">'
            . icon('calendar', 'w-3.5 h-3.5') . ' Personalizado</button>';
        $h .= '</div>';
    } elseif (!empty($p['label'])) {
        // Sin periodo, la barra dice de cuándo es la foto.
        $h .= '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 text-[13px] font-semibold text-slate-600">'
            . icon('calendar', 'w-3.5 h-3.5') . ' ' . e($p['label']) . '</span>';
    }

    $selSuc = !empty($opts['sucursal']) ? selectSucursalFiltro() : '';
    if ($selSuc || !empty($opts['extra'])) {
        $h .= '<form method="get" class="flex flex-wrap items-center gap-2">';
        if ($conPeriodo) {
            $h .= '<input type="hidden" name="periodo" value="' . e($p['preset']) . '">';
            if ($p['preset'] === 'personalizado') {
                $h .= '<input type="hidden" name="desde" value="' . e($p['desde']) . '">'
                    . '<input type="hidden" name="hasta" value="' . e($p['hasta']) . '">';
            }
        }
        // Conserva los filtros propios del reporte (vista, cuenta_id, estado...)
        // que no se envían en este formulario; si no, cambiar de sucursal los borra.
        $enviados = ['sucursal_id' => 1];
        preg_match_all('/name="([^"]+)"/', $selSuc . ($opts['extra'] ?? ''), $m);
        foreach ($m[1] as $campo) $enviados[$campo] = 1;
        foreach ($base as $k => $v) {
            if (isset($enviados[$k]) || !is_scalar($v)) continue;
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        $h .= $selSuc . ($opts['extra'] ?? '');
        $h .= '<button type="submit" class="btn btn-ghost btn-sm">' . icon('filter', 'w-3.5 h-3.5') . ' Aplicar</button>';
        $h .= '</form>';
    }

    $h .= '<div class="ml-auto flex items-center gap-2">' . ($opts['acciones'] ?? rep_acciones()) . '</div>';
    $h .= '</div>';

    // Rango personalizado (se despliega). Sin periodo no hay rango que elegir.
    if ($conPeriodo) {
        $h .= '<form method="get" x-show="avanzado" x-cloak x-transition class="flex flex-wrap items-end gap-3 mt-4 pt-4 border-t border-slate-100">';
        foreach ($base as $k => $v) {
            if (is_scalar($v)) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        $h .= '<div><label class="label">Desde</label><input type="date" name="desde" value="' . e($p['desde']) . '" class="input w-auto"></div>';
        $h .= '<div><label class="label">Hasta</label><input type="date" name="hasta" value="' . e($p['hasta']) . '" class="input w-auto"></div>';
        $h .= '<button type="submit" class="btn btn-primary">' . icon('check', 'w-4 h-4') . ' Aplicar rango</button>';
        $h .= '</form>';
    }

    return $h . '</div>';
}

/** Botones de exportación/impresión de un reporte. */
function rep_acciones(bool $excel = true, bool $pdf = true): string
{
    $h = '';
    if ($excel) {
        $u = '?' . http_build_query(array_merge($_GET, ['export' => 'excel']));
        $h .= '<a href="' . e($u) . '" class="btn btn-ghost btn-sm no-print" title="Descargar en Excel">' . icon('download', 'w-3.5 h-3.5') . ' Excel</a>';
    }
    if ($pdf) {
        $u = '?' . http_build_query(array_merge($_GET, ['export' => 'pdf']));
        $h .= '<a href="' . e($u) . '" target="_blank" rel="noopener" class="btn btn-ghost btn-sm no-print" title="Abrir en PDF">' . icon('print', 'w-3.5 h-3.5') . ' PDF</a>';
    }
    return $h;
}

/** Encabezado que solo aparece al imprimir / exportar a PDF desde el navegador. */
function rep_encabezado_impresion(string $titulo, array $p): string
{
    // Los informes sin periodo (fotos del día) llevan su etiqueta en vez del rango.
    $cuando = isset($p['desde'], $p['hasta'])
        ? 'Periodo: ' . fechaCorta($p['desde']) . ' al ' . fechaCorta($p['hasta'])
        : (string) ($p['label'] ?? fechaLarga(date('Y-m-d')));

    return '<div class="print-only mb-4">'
        . '<h2 style="font-size:18px;font-weight:800;color:#0f172a;margin:0">' . e(setting('nombre', APP_NAME)) . ' — ' . e($titulo) . '</h2>'
        . '<p style="font-size:12px;color:#475569;margin:2px 0 0">' . e($cuando)
        . ' · ' . e(rep_alcance_sucursal()) . ' · Generado ' . fechaHora(date('Y-m-d H:i:s')) . '</p></div>';
}

/** Acciones de la cabecera de página: volver al hub + imprimir. */
function rep_barra_titulo(string $extra = '', bool $exportable = true): string
{
    // 23 de los 27 informes saben exportarse a PDF y a Excel desde hace tiempo
    // —`export_solicitado()` + `export_tabla()`— pero NINGUNO enseñaba el botón.
    // Lo único que había era «Imprimir», que dispara la impresión del navegador
    // y saca la página con su cabecera, su fecha y sin las tablas.
    //
    // El PDF de verdad sale por `pdf_tabla()`, que lleva el membrete de la
    // empresa, y el Excel por PhpSpreadsheet. Los botones se ponen aquí una vez
    // y los heredan los 23.
    $botones = '';
    if ($exportable) {
        $botones = '<a href="' . e(rep_url_export('excel')) . '" class="btn btn-ghost no-print">'
                 . icon('download', 'w-4 h-4') . ' Excel</a>'
                 . '<a href="' . e(rep_url_export('pdf')) . '" target="_blank" rel="noopener" class="btn btn-ghost no-print">'
                 . icon('file', 'w-4 h-4') . ' PDF</a>';
    }
    return $extra . $botones
        . '<a href="' . e(url('modules/reportes/index.php')) . '" class="btn btn-ghost no-print">'
        . icon('grid', 'w-4 h-4') . ' Centro de reportes</a>';
}

/**
 * La URL del informe actual con `export=…`, conservando los filtros.
 *
 * Sin conservarlos, el PDF saldría del período por defecto y no del que la
 * persona está mirando: el documento diría una cosa y la pantalla otra.
 */
function rep_url_export(string $formato): string
{
    $qs = $_GET;
    unset($qs['export']);
    $qs['export'] = $formato;
    return strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') . '?' . http_build_query($qs);
}

/**
 * Cabecera estándar de un reporte: título, subtítulo con periodo y alcance,
 * encabezado de impresión y barra de filtros. Devuelve el HTML posterior al
 * layout_start() (que hay que llamar antes).
 */
function rep_abrir(string $titulo, array $p, array $opts = []): string
{
    return rep_encabezado_impresion($titulo, $p) . rep_filtros($p, $opts);
}

/** Subtítulo uniforme para layout_start(). */
function rep_subtitulo(array $p): string
{
    return $p['label'] . ' · ' . rep_alcance_sucursal();
}

/**
 * Alcance de sucursal para los reportes: respeta la sucursal activa Y el filtro
 * de la URL. Devuelve [fragmentoWhere, params].
 */
function rep_scope(string $col = 'v.sucursal_id'): array
{
    return sucursalFiltro($col);
}

/* ============================================================
 *  Criterio contable compartido
 * ============================================================
 *  `transacciones` es el libro de caja: ahí entra TODO lo que mueve dinero,
 *  incluidas las compras de mercancía y los cobros de cuentas por cobrar. Para
 *  el estado de resultados hay que sacarlos, o el número miente:
 *
 *   · Compra de mercancía  → es inventario, no gasto. El costo entra al P&L
 *     cuando se vende (ventas.costo_total), no cuando se compra.
 *   · Devolución           → ya se resta de la línea de ingresos.
 *   · Cobro de un abono    → es cobrar algo ya facturado, no un ingreso nuevo.
 *   · Venta                → ya está en la línea de ingresos.
 */

/**
 * Qué ventas cuentan como ingreso del periodo.
 *
 * Las tres son distintas y no da igual cuál se use:
 *
 *   · «completada»  vendida y en pie.
 *   · «devuelta»    vendida y devuelta ENTERA después. El estado cambia; la
 *                   venta existió y su nota de crédito la anula.
 *   · «anulada»     el comprobante no llegó a valer: se borran sus
 *                   transacciones y se devuelve el saldo. Como si no hubiera
 *                   pasado, y por eso queda fuera.
 *
 * Las devueltas SÍ entran, porque `rep_devoluciones()` les resta la nota de
 * crédito aparte. Dejarlas fuera Y restar la nota descuenta el mismo importe
 * dos veces —el estado de resultados llegaba a dar ingresos negativos— y, peor,
 * una devolución PARCIAL no cambia el estado, así que solo se corregían las
 * totales: el informe se comportaba distinto según si el cliente devolvió todo
 * o la mitad.
 */
function rep_estados_venta(string $a = 'v'): string
{
    return "$a.estado IN ('completada','devuelta')";
}

/**
 * Lo que hay que restarle al periodo por devoluciones: base, ITBIS y COSTO.
 *
 * El costo importa tanto como el ingreso: la mercancía devuelta vuelve al
 * estante, así que su costo deja de ser costo de ventas. Restar solo el ingreso
 * y dejar el costo puesto hunde el margen del periodo.
 *
 * La devolución pesa en el periodo en que se EMITE la nota de crédito, no en el
 * de la venta original: es un documento fiscal con su propia fecha, y así lo
 * declara el 607.
 *
 * `total` es base + ITBIS: lo que se le reembolsó al cliente. Se usa donde la
 * venta se mide por `ventas.total` (el tablero) en vez de por su base.
 *
 * @return array{base:float,itbis:float,total:float,costo:float}
 */
function rep_devoluciones(string $ini, string $fin, string $scopeD = '1=1', array $scopeDP = []): array
{
    $r = qOne(
        "SELECT COALESCE(SUM(d.subtotal),0) base, COALESCE(SUM(d.itbis),0) itbis
           FROM devoluciones d WHERE d.created_at BETWEEN ? AND ? AND $scopeD",
        array_merge([$ini, $fin], $scopeDP)
    ) ?: [];

    // El costo sale de la línea de venta original. Una devolución sin línea
    // enlazada (las de antes de que se guardara el vínculo) aporta 0: se prefiere
    // quedarse corto en la recuperación de costo antes que inventarse una cifra.
    $costo = (float) qVal(
        "SELECT COALESCE(SUM(dd.cantidad * vd.costo_unitario),0)
           FROM devolucion_detalles dd
           JOIN devoluciones d ON d.id = dd.devolucion_id
           LEFT JOIN venta_detalles vd ON vd.id = dd.venta_detalle_id
          WHERE d.created_at BETWEEN ? AND ? AND $scopeD",
        array_merge([$ini, $fin], $scopeDP)
    );

    $base  = (float) ($r['base'] ?? 0);
    $itbis = (float) ($r['itbis'] ?? 0);
    return ['base' => $base, 'itbis' => $itbis, 'total' => round($base + $itbis, 2), 'costo' => $costo];
}

/**
 * Devoluciones agrupadas por mes: ['2026-08' => ['base'=>…, 'costo'=>…], …].
 *
 * La usan las series de 12 meses. Sin esto una gráfica mensual dibuja la venta
 * bruta al lado de un total neto, y el lector ve dos alturas distintas para el
 * mismo mes sin que nada lo explique.
 */
function rep_devoluciones_por_mes(string $desde, string $scopeD = '1=1', array $scopeDP = []): array
{
    $filas = qAll(
        "SELECT DATE_FORMAT(d.created_at,'%Y-%m') AS ym,
                COALESCE(SUM(d.subtotal),0) AS base,
                COALESCE(SUM(dd.costo),0)   AS costo
           FROM devoluciones d
           LEFT JOIN (SELECT x.devolucion_id, SUM(x.cantidad * vd.costo_unitario) costo
                        FROM devolucion_detalles x
                        LEFT JOIN venta_detalles vd ON vd.id = x.venta_detalle_id
                       GROUP BY x.devolucion_id) dd ON dd.devolucion_id = d.id
          WHERE d.created_at >= ? AND $scopeD
          GROUP BY ym",
        array_merge([$desde], $scopeDP)
    );
    $m = [];
    foreach ($filas as $r) $m[$r['ym']] = ['base' => (float) $r['base'], 'costo' => (float) $r['costo']];
    return $m;
}

/**
 * Devoluciones agrupadas por la dimensión que se le pida: ['etiqueta' => ['base','costo']].
 *
 * La devolución se atribuye a la sucursal, el canal, el vendedor o el cliente
 * de la VENTA original, no a quien la tramitó: lo que se está corrigiendo es
 * aquella venta. Se une `ventas` siempre, así que el alcance de sucursal se
 * expresa igual que en las consultas de venta (`v.sucursal_id`).
 *
 * @param string $etiqueta Expresión SELECT de la etiqueta (la misma de la venta).
 * @param string $groupBy  Expresión GROUP BY.
 * @param string $joins    JOINs extra que necesite la etiqueta.
 */
function rep_devoluciones_por(string $etiqueta, string $groupBy, string $joins,
                              string $ini, string $fin, string $scope = '1=1', array $scopeP = []): array
{
    $filas = qAll(
        "SELECT $etiqueta AS et,
                COALESCE(SUM(d.subtotal),0) AS base,
                COALESCE(SUM(d.itbis),0)    AS itbis,
                COALESCE(SUM(dd.costo),0)   AS costo
           FROM devoluciones d
           JOIN ventas v ON v.id = d.venta_id
           LEFT JOIN (SELECT x.devolucion_id, SUM(x.cantidad * vd.costo_unitario) costo
                        FROM devolucion_detalles x
                        LEFT JOIN venta_detalles vd ON vd.id = x.venta_detalle_id
                       GROUP BY x.devolucion_id) dd ON dd.devolucion_id = d.id
           $joins
          WHERE d.created_at BETWEEN ? AND ? AND $scope
          GROUP BY $groupBy",
        array_merge([$ini, $fin], $scopeP)
    );
    $m = [];
    foreach ($filas as $r) {
        $m[(string) $r['et']] = ['base' => (float) $r['base'], 'itbis' => (float) $r['itbis'],
                                  'total' => round((float) $r['base'] + (float) $r['itbis'], 2),
                                  'costo' => (float) $r['costo']];
    }
    return $m;
}

/**
 * Devoluciones por DÍA: ['2026-08-14' => ['base','itbis','total','costo'], …].
 *
 * Para las curvas diarias. Un día puede salir en negativo si solo hubo
 * devoluciones: es correcto, y en una curva acumulada se ve como el escalón
 * hacia abajo que de verdad fue.
 */
function rep_devoluciones_por_dia(string $ini, string $fin, string $scopeD = '1=1', array $scopeDP = []): array
{
    $filas = qAll(
        "SELECT DATE(d.created_at) AS f,
                COALESCE(SUM(d.subtotal),0) AS base,
                COALESCE(SUM(d.itbis),0)    AS itbis,
                COALESCE(SUM(dd.costo),0)   AS costo
           FROM devoluciones d
           LEFT JOIN (SELECT x.devolucion_id, SUM(x.cantidad * vd.costo_unitario) costo
                        FROM devolucion_detalles x
                        LEFT JOIN venta_detalles vd ON vd.id = x.venta_detalle_id
                       GROUP BY x.devolucion_id) dd ON dd.devolucion_id = d.id
          WHERE d.created_at BETWEEN ? AND ? AND $scopeD
          GROUP BY DATE(d.created_at)",
        array_merge([$ini, $fin], $scopeDP)
    );
    $m = [];
    foreach ($filas as $r) {
        $m[(string) $r['f']] = ['base' => (float) $r['base'], 'itbis' => (float) $r['itbis'],
                                 'total' => round((float) $r['base'] + (float) $r['itbis'], 2),
                                 'costo' => (float) $r['costo']];
    }
    return $m;
}

/**
 * Devoluciones agrupadas por PRODUCTO, para los rankings que se arman desde las
 * líneas. La clave es la misma que usan esas consultas —el id del producto, o su
 * descripción cuando la línea no enlaza a catálogo— para que casen sin adivinar.
 *
 * OJO CON EL ITBIS: `devolucion_detalles.subtotal` lo lleva DENTRO (es lo que
 * se le reembolsa al cliente), mientras que el lado de la venta suma base sin
 * impuesto. Restar la línea tal cual descontaba de más justo el ITBIS devuelto.
 * Se le quita usando el ITBIS de la línea original, en la misma proporción con
 * la que se calculó al devolver.
 *
 * @return array<string,array{unidades:float,base:float,costo:float}>
 */
function rep_devoluciones_por_linea(string $ini, string $fin, string $scope = '1=1', array $scopeP = []): array
{
    $filas = qAll(
        "SELECT COALESCE(p.id, dd.descripcion) AS k,
                COALESCE(SUM(dd.cantidad),0) AS unidades,
                COALESCE(SUM(dd.subtotal - COALESCE(vd.itbis * dd.cantidad / NULLIF(vd.cantidad,0), 0)),0) AS base,
                COALESCE(SUM(dd.cantidad * vd.costo_unitario),0) AS costo
           FROM devolucion_detalles dd
           JOIN devoluciones d ON d.id = dd.devolucion_id
           JOIN ventas v ON v.id = d.venta_id
           LEFT JOIN productos p ON p.id = dd.producto_id
           LEFT JOIN venta_detalles vd ON vd.id = dd.venta_detalle_id
          WHERE d.created_at BETWEEN ? AND ? AND $scope
          GROUP BY COALESCE(p.id, dd.descripcion)",
        array_merge([$ini, $fin], $scopeP)
    );
    $m = [];
    foreach ($filas as $r) {
        $m[(string) $r['k']] = ['unidades' => (float) $r['unidades'],
                                'base' => (float) $r['base'], 'costo' => (float) $r['costo']];
    }
    return $m;
}

/** WHERE de gastos OPERATIVOS (nómina, comisiones, alquiler, servicios...). */
function rep_where_gastos(string $a = 't'): string
{
    // 'pago_proveedor' queda fuera por la misma razón que 'compra': es mercancía,
    // que es inventario y ya pesa en el costo de ventas. Contarlo aquí sería
    // cargar dos veces la misma compra al resultado.
    //
    // La diferencia cambiaria SÍ entra: es un gasto financiero real del periodo.
    return "$a.tipo = 'gasto' AND ($a.referencia_tipo IS NULL OR $a.referencia_tipo NOT IN ('compra','devolucion','pago_proveedor'))";
}

/** WHERE de otros ingresos que NO vienen de facturar. */
function rep_where_otros_ingresos(string $a = 't'): string
{
    return "$a.tipo = 'ingreso' AND ($a.referencia_tipo IS NULL OR $a.referencia_tipo NOT IN ('venta','abono'))";
}

/**
 * WHERE del flujo de EFECTIVO: excluye los movimientos que no mueven dinero.
 *
 * La depreciación es el caso claro: es un gasto real que baja la utilidad, pero
 * no sale un peso de la caja. Contarla como salida de efectivo haría que el
 * flujo mostrara menos dinero del que hay de verdad.
 */
function rep_where_flujo(string $a = 't'): string
{
    // 'diferencia_cambiaria' se suma aquí: el dinero que salió por el cambio del
    // dólar YA está dentro del pago al proveedor. Contarla aparte sacaría de la
    // caja un dinero que solo salió una vez.
    return "($a.referencia_tipo IS NULL OR $a.referencia_tipo NOT IN ('depreciacion','diferencia_cambiaria'))";
}

/** Gasto operativo del periodo dentro del alcance actual. */
function rep_gastos_operativos(string $desde, string $hasta): float
{
    [$w, $par] = rep_scope('t.sucursal_id');
    return (float) qVal(
        "SELECT COALESCE(SUM(t.monto),0) FROM transacciones t
          WHERE " . rep_where_gastos() . " AND t.fecha BETWEEN ? AND ? AND $w",
        array_merge([$desde, $hasta], $par)
    );
}

/** Otros ingresos del periodo dentro del alcance actual. */
function rep_otros_ingresos(string $desde, string $hasta): float
{
    [$w, $par] = rep_scope('t.sucursal_id');
    return (float) qVal(
        "SELECT COALESCE(SUM(t.monto),0) FROM transacciones t
          WHERE " . rep_where_otros_ingresos() . " AND t.fecha BETWEEN ? AND ? AND $w",
        array_merge([$desde, $hasta], $par)
    );
}

/** Serie de meses (YYYY-MM) hacia atrás desde hoy. */
function rep_meses_atras(int $n = 12): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $out[] = date('Y-m', $t);
    }
    return $out;
}

/** Etiqueta corta de un 'YYYY-MM' (Ene 26). */
function rep_mes_label(string $ym): string
{
    $t = strtotime($ym . '-01');
    return mesNombre((int) date('n', $t), true) . ' ' . date('y', $t);
}
