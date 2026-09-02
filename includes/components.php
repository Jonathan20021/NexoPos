<?php
/**
 * Componentes de UI y estructura de navegación.
 */

/** Estructura del menú lateral. Cada item: [label, icono, url, permiso|null, badge?]. */
function nav_groups(): array
{
    return [
        ['Principal', [
            ['Dashboard', 'dashboard', url('modules/dashboard/index.php'), null],
        ]],
        ['Dirección', [
            ['Panel de Dirección', 'trending', url('modules/direccion/index.php'), 'direccion.ver'],
            ['Año contra año', 'chart', url('modules/direccion/comparativo.php'), 'direccion.ver'],
            ['Reportería de costos', 'coins', url('modules/direccion/costos.php'), 'direccion.ver'],
            ['Mercancía en liquidación', 'truck', url('modules/inventario/liquidaciones.php'), 'liquidaciones.ver'],
            ['Cargar datos históricos', 'download', url('modules/direccion/importar.php'), 'direccion.importar'],
        ]],
        ['Ventas', [
            ['Punto de Venta', 'cart', url('modules/pos/index.php'), 'pos.ver'],
            ['Caja', 'cash', url('modules/pos/caja.php'), 'caja.ver'],
            ['Ventas', 'receipt', url('modules/pos/ventas.php'), 'ventas.ver'],
            ['Pedidos en línea', 'store', url('modules/pos/pedidos.php'), 'pedidos.ver'],
            ['Devoluciones', 'undo', url('modules/pos/devoluciones.php'), 'devoluciones.ver'],
            ['Clientes', 'users', url('modules/pos/clientes.php'), 'clientes.ver'],
            ['Cotizaciones', 'file', url('modules/pos/cotizaciones.php'), 'cotizaciones.ver'],
            ['Cuentas por Cobrar', 'wallet', url('modules/pos/cuentas_cobrar.php'), 'clientes.ver'],
            ['Terminales offline', 'cash', url('modules/pos/terminales.php'), 'pos.terminales'],
        ]],
        ['Inventario', [
            ['Productos', 'box', url('modules/inventario/productos.php'), 'productos.ver'],
            ['Escáner de almacén', 'barcode', url('modules/inventario/escaner.php'), 'inventario.ver'],
            ['Etiquetas de barras', 'tag', url('modules/inventario/etiquetas.php'), 'productos.etiquetas'],
            ['Categorías', 'tag', url('modules/inventario/categorias.php'), 'categorias.ver'],
            ['Marcas y Unidades', 'layers', url('modules/inventario/catalogos.php'), 'productos.ver'],
            ['Stock', 'layers', url('modules/inventario/stock.php'), 'inventario.ver'],
            ['Conteo físico', 'clipboard', url('modules/inventario/conteos.php'), 'conteos.ver'],
            ['Lotes y vencimientos', 'shield', url('modules/inventario/lotes.php'), 'sanidad.ver'],
            ['Movimientos', 'history', url('modules/inventario/movimientos.php'), 'inventario.ver'],
            ['Compras', 'truck', url('modules/inventario/compras.php'), 'compras.ver'],
            ['Liquidación de importaciones', 'package', url('modules/inventario/liquidaciones.php'), 'liquidaciones.ver'],
            ['Proveedores', 'briefcase', url('modules/inventario/proveedores.php'), 'proveedores.ver'],
            ['Cuentas por Pagar', 'wallet', url('modules/inventario/cuentas_pagar.php'), 'cxp.ver'],
            ['Transferencias', 'transfer', url('modules/inventario/transferencias.php'), 'transferencias.ver'],
            // Va justo después: quien autoriza entra aquí, no al listado.
            ['Autorizaciones', 'check', url('modules/inventario/aprobaciones.php'), 'transferencias.aprobar'],
        ]],
        ['Recursos Humanos', [
            ['Empleados', 'id', url('modules/rrhh/empleados.php'), 'rrhh_empleados.ver'],
            ['Asistencia', 'clock', url('modules/rrhh/asistencia.php'), 'rrhh_asistencia.ver'],
            ['Nómina', 'wallet', url('modules/rrhh/nomina.php'), 'rrhh_nomina.ver'],
            ['Regalía pascual', 'sun', url('modules/rrhh/regalia.php'), 'rrhh_nomina.ver'],
            ['Vacaciones y Licencias', 'sun', url('modules/rrhh/vacaciones.php'), 'rrhh_vacaciones.ver'],
            ['Préstamos', 'wallet', url('modules/rrhh/prestamos.php'), 'prestamos.ver'],
            ['Amonestaciones', 'alert', url('modules/rrhh/amonestaciones.php'), 'amonestaciones.ver'],
            ['Prestaciones laborales', 'scale', url('modules/rrhh/prestaciones.php'), 'prestaciones.ver'],
            ['TSS', 'shield', url('modules/rrhh/tss.php'), 'tss.ver'],
            ['Departamentos', 'building', url('modules/rrhh/departamentos.php'), 'rrhh_departamentos.ver'],
        ]],
        ['CRM', [
            ['Embudo de Ventas', 'trending', url('modules/crm/index.php'), 'crm.ver'],
            ['Oportunidades', 'briefcase', url('modules/crm/oportunidades.php'), 'crm.ver'],
            ['Interacciones', 'phone', url('modules/crm/interacciones.php'), 'crm.ver'],
            ['Tareas y Seguimientos', 'check', url('modules/crm/tareas.php'), 'crm.ver'],
        ]],
        ['Marketing', [
            ['Panel de Marketing', 'megaphone', url('modules/marketing/index.php'), 'marketing.ver'],
            ['Campañas', 'mail', url('modules/marketing/campanas.php'), 'campanas.ver'],
            ['Envíos por WhatsApp', 'phone', url('modules/marketing/whatsapp.php'), 'campanas.whatsapp'],
            ['Automatizaciones', 'pulse', url('modules/marketing/automatizaciones.php'), 'marketing.automatizar'],
            ['Segmentos de clientes', 'users', url('modules/marketing/segmentos.php'), 'marketing.segmentos'],
            ['Plantillas', 'file', url('modules/marketing/plantillas.php'), 'marketing.plantillas'],
            ['Diseño del correo', 'sun', url('modules/marketing/diseno.php'), 'marketing.diseno'],
            ['Promociones', 'percent', url('modules/marketing/promociones.php'), 'promociones.ver'],
        ]],
        ['Finanzas', [
            ['Ingresos y Gastos', 'dollar', url('modules/finanzas/index.php'), 'finanzas.ver'],
            ['Cuentas', 'wallet', url('modules/finanzas/cuentas.php'), 'finanzas.ver'],
            ['Activos fijos', 'building', url('modules/finanzas/activos.php'), 'activos.ver'],
            ['Conciliación bancaria', 'check', url('modules/finanzas/conciliacion.php'), 'conciliacion.ver'],
            ['Comisiones', 'percent', url('modules/finanzas/comisiones.php'), 'comisiones.ver'],
            ['Metas de Venta', 'trending', url('modules/finanzas/metas.php'), 'metas.ver'],
            ['Reportes DGII', 'shield', url('modules/finanzas/dgii.php'), 'dgii.ver'],
            ['IT-1 · ITBIS', 'percent', url('modules/finanzas/it1.php'), 'dgii.ver'],
            ['Facturación Electrónica', 'receipt', url('modules/finanzas/ecf.php'), 'ecf.ver'],
        ]],
        ['Reportes', [
            ['Centro de Reportes', 'grid', url('modules/reportes/index.php'), 'reportes.ver'],
            ['Panel ejecutivo', 'trending', url('modules/reportes/ejecutivo.php'), 'reportes.ejecutivo'],
            ['Estado de resultados', 'receipt', url('modules/reportes/estado_resultados.php'), 'reportes.finanzas'],
            ['Flujo de efectivo', 'cash', url('modules/reportes/flujo_caja.php'), 'reportes.finanzas'],
            ['Cuentas por cobrar', 'wallet', url('modules/reportes/cxc.php'), 'reportes.finanzas'],
            ['Contabilidad y DGII', 'shield', url('modules/reportes/libro_diario.php'), 'reportes.contabilidad'],
            ['Expediente de auditoría', 'shield', url('modules/reportes/expediente_auditoria.php'), 'reportes.sanidad'],
            ['Reporte gerencial', 'chart', url('modules/finanzas/reportes.php'), 'reportes.ejecutivo'],
        ]],
        ['Administración', [
            ['Sucursales', 'store', url('modules/admin/sucursales.php'), 'sucursales.ver'],
            ['Tiendas y marcas', 'tag', url('modules/admin/tiendas.php'), 'tiendas.ver'],
            ['Usuarios', 'user', url('modules/admin/usuarios.php'), 'usuarios.ver'],
            ['Roles y Permisos', 'shield', url('modules/admin/roles.php'), 'roles.ver'],
            ['Configuración', 'settings', url('modules/admin/configuracion.php'), 'configuracion.ver'],
            ['Seguridad de acceso', 'lock', url('modules/admin/seguridad.php'), 'configuracion.ver'],
            ['Monedas y tasa', 'coins', url('modules/admin/monedas.php'), 'monedas.gestionar'],
            ['Auditoría / Logs', 'list', url('modules/admin/auditoria.php'), 'auditoria.ver'],
            ['Integridad de datos', 'shield', url('modules/admin/integridad.php'), 'configuracion.ver'],
            ['Respaldo', 'download', url('modules/admin/respaldo.php'), 'configuracion.ver'],
        ]],
    ];
}

/**
 * ¿El enlace del menú corresponde a la página actual?
 *
 * Con las URLs sin extensión, el enlace llega como «/base/modules/pos/» y el
 * SCRIPT_NAME sigue siendo «/base/modules/pos/index.php». Se normalizan ambos a
 * la misma forma y se comparan EXACTO: comparar por substring marcaría «Punto de
 * Venta» como activo en todas las páginas de /modules/pos/.
 */
function navActive(string $fullUrl): bool
{
    $normalizar = static function (string $p): string {
        $p = parse_url($p, PHP_URL_PATH) ?: '';
        if (str_ends_with($p, '/'))          $p .= 'index';
        elseif (str_ends_with($p, '.php'))   $p = substr($p, 0, -4);
        return $p;
    };
    $path   = $normalizar($fullUrl);
    $script = $normalizar($_SERVER['SCRIPT_NAME'] ?? '');
    return $path !== '' && $script !== '' && $path === $script;
}

/** Badge / etiqueta de color. */
function badge(string $texto, string $color = 'slate', string $extra = ''): string
{
    return '<span class="badge badge-' . e($color) . ' ' . e($extra) . '">' . e($texto) . '</span>';
}

/** Badge a partir de un estado conocido. */
function badgeFor(string $estado): string
{
    [$txt, $col] = badgeEstado($estado);
    return badge($txt, $col);
}

/** Avatar con iniciales y color determinístico. */
function avatar(string $nombre, string $size = 'w-9 h-9'): string
{
    $colores = ['bg-blue-100 text-blue-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-700', 'bg-rose-100 text-rose-700', 'bg-indigo-100 text-indigo-700', 'bg-cyan-100 text-cyan-700', 'bg-pink-100 text-pink-700'];
    $ini = strtoupper(mb_substr(trim($nombre), 0, 1));
    $partes = preg_split('/\s+/', trim($nombre));
    if (count($partes) > 1) $ini .= strtoupper(mb_substr(end($partes), 0, 1));
    $c = $colores[abs(crc32($nombre)) % count($colores)];
    return '<span class="' . $size . ' rounded-full ' . $c . ' inline-flex items-center justify-center font-semibold text-sm shrink-0">' . e($ini) . '</span>';
}

/**
 * Renderiza los mensajes flash como avisos flotantes.
 *
 * Se muestran arriba a la derecha y se van solos a los 6 segundos (los errores
 * se quedan hasta que el usuario los cierre: si algo falló, hay que leerlo).
 * El temporizador se detiene al pasar el ratón por encima.
 */
function render_flashes(): void
{
    $flashes = get_flashes();
    if (!$flashes) return;

    $iconos = ['success' => 'check', 'error' => 'alert', 'warning' => 'alert', 'info' => 'bell'];
    $estilos = [
        'success' => ['border-emerald-200', 'bg-emerald-50 text-emerald-600'],
        'error'   => ['border-rose-200',    'bg-rose-50 text-rose-600'],
        'warning' => ['border-amber-200',   'bg-amber-50 text-amber-600'],
        'info'    => ['border-sky-200',     'bg-sky-50 text-sky-600'],
    ];

    echo '<div class="toast-stack no-print" role="status" aria-live="polite">';
    foreach ($flashes as $f) {
        $tipo = $f['tipo'];
        [$borde, $ico] = $estilos[$tipo] ?? $estilos['info'];
        $auto = $tipo === 'error' ? '0' : '6000';
        echo '<div x-data="{show:false, t:null}" x-init="'
            . '$nextTick(() => show = true);'
            . 'if (' . $auto . ') t = setTimeout(() => show = false, ' . $auto . ')"'
            . ' @mouseenter="clearTimeout(t)"'
            . ' x-show="show"'
            . ' x-transition:enter="transition ease-out duration-200"'
            . ' x-transition:enter-start="opacity-0 translate-x-6"'
            . ' x-transition:enter-end="opacity-100 translate-x-0"'
            . ' x-transition:leave="transition ease-in duration-150"'
            . ' x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 translate-x-6"'
            . ' style="display:none" class="toast ' . $borde . '">'
            . '<span class="w-8 h-8 rounded-xl ' . $ico . ' flex items-center justify-center shrink-0">' . icon($iconos[$tipo] ?? 'bell', 'w-4 h-4') . '</span>'
            . '<span class="flex-1 text-slate-700 leading-relaxed pt-1">' . e($f['mensaje']) . '</span>'
            . '<button type="button" @click="show=false" aria-label="Cerrar aviso" class="text-slate-300 hover:text-slate-600 transition p-1 -m-1 shrink-0">'
            . icon('x', 'w-4 h-4') . '</button></div>';
    }
    echo '</div>';
}

/** Estado vacío para tablas/listas. */
function empty_state(string $titulo, string $mensaje = '', string $icono = 'box', string $accion = ''): string
{
    return '<div class="flex flex-col items-center justify-center text-center py-16 px-6">'
        . '<div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mb-4">' . icon($icono, 'w-8 h-8') . '</div>'
        . '<h3 class="text-base font-semibold text-slate-700">' . e($titulo) . '</h3>'
        . ($mensaje ? '<p class="text-sm text-slate-400 mt-1 max-w-sm">' . e($mensaje) . '</p>' : '')
        . ($accion ? '<div class="mt-5">' . $accion . '</div>' : '')
        . '</div>';
}

/** Fragmento WHERE para filtrar por la sucursal activa (null = todas). */
function sucursalScope(string $col = 'sucursal_id'): array
{
    $sid = current_sucursal_id();
    if ($sid === null) return ['1=1', []];
    return ["$col = ?", [$sid]];
}

/**
 * Igual que sucursalScope(), pero además aplica el filtro ?sucursal_id= de la URL.
 *
 * La sucursal activa es un límite de seguridad y no se puede burlar desde la URL;
 * el filtro solo puede acotar más, nunca ampliar. Devuelve [where, params].
 */
function sucursalFiltro(string $col = 'sucursal_id'): array
{
    [$where, $params] = sucursalScope($col);
    $filtro = (int) get('sucursal_id');
    if ($filtro > 0 && can_access_sucursal($filtro)) {
        $where .= " AND $col = ?";
        $params[] = $filtro;
    }
    return [$where, $params];
}

/** La sucursal elegida en el filtro de la URL, o null. Útil para marcar el <select>. */
function sucursalFiltroActual(): ?int
{
    $filtro = (int) get('sucursal_id');
    return ($filtro > 0 && can_access_sucursal($filtro)) ? $filtro : null;
}

/**
 * <select> de sucursales para los filtros de los listados.
 * Devuelve cadena vacía cuando el usuario solo puede ver una sucursal: no tendría
 * nada que elegir, y un filtro de una sola opción es ruido.
 */
function selectSucursalFiltro(): string
{
    $sucursales = sucursales_visibles();
    if (count($sucursales) < 2) return '';
    $actual = sucursalFiltroActual();
    $h  = '<select name="sucursal_id" aria-label="Filtrar por sucursal" class="select cursor-pointer">';
    $h .= '<option value="">Todas las sucursales</option>';
    foreach ($sucursales as $s) {
        $sel = $actual === (int) $s['id'] ? ' selected' : '';
        $h .= '<option value="' . (int) $s['id'] . '"' . $sel . '>' . e($s['nombre']) . '</option>';
    }
    return $h . '</select>';
}

/** Lista de sucursales visibles para el usuario actual. */
function sucursales_visibles(): array
{
    $u = current_user();
    if (!empty($u['es_super']) || $u['sucursal_id'] === null) {
        return qAll("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");
    }
    return qAll("SELECT id, nombre FROM sucursales WHERE id = ? AND activo = 1", [$u['sucursal_id']]);
}

/** Genera un atributo onclick que despacha un CustomEvent (para abrir modales). */
function jsEvent(string $event, array $detail = []): string
{
    // Se despacha sobre window con bubbles:true para que SIEMPRE llegue al
    // listener @evento.window del modal (un onclick inline usaría el elemento).
    $payload = 'window.dispatchEvent(new CustomEvent(' . json_encode($event) . ',{bubbles:true,detail:' . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) . '}))';
    return e($payload);
}

/** Botón primario de cabecera que dispara un evento (abrir modal de creación). */
function btn_nuevo(string $event, string $label): string
{
    return '<button onclick="' . jsEvent($event) . '" class="btn btn-primary">' . icon('plus', 'w-4 h-4') . ' ' . e($label) . '</button>';
}

/** Caja de búsqueda estándar (GET). */
/**
 * Buscador que se envía solo mientras escribes (ver `data-buscar` en footer.php).
 * Sigue siendo un <form> normal: sin JavaScript funciona pulsando Enter.
 */
function search_box(string $placeholder = 'Buscar...', array $hidden = []): string
{
    $q = $_GET['q'] ?? '';
    $h = '';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';

    $limpiar = '';
    if ($q !== '') {
        $qs = $_GET; unset($qs['q'], $qs['p']);
        $href = '?' . http_build_query($qs);
        $limpiar = '<a href="' . e($href) . '" title="Limpiar búsqueda" aria-label="Limpiar búsqueda"
            class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors duration-200 cursor-pointer">'
            . icon('x', 'w-4 h-4') . '</a>';
    }

    return '<form method="get" class="relative w-full sm:w-80">' . $h
        . '<input type="hidden" name="p" value="1">'
        . '<span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">' . icon('search', 'w-4 h-4') . '</span>'
        . '<input type="search" name="q" data-buscar value="' . e($q) . '" placeholder="' . e($placeholder) . '"'
        . ' aria-label="' . e($placeholder) . '" autocomplete="off" class="input pl-10 pr-9">'
        . $limpiar . '</form>';
}

/**
 * Calcula el tramo a mostrar. Devuelve el arreglo que espera paginacion().
 * `p` fuera de rango se ajusta al último tramo válido en vez de mostrar una
 * página vacía (pasa al borrar registros estando en la última página).
 */
function paginar(int $total, int $porPagina = 25): array
{
    $porPagina = max(1, $porPagina);
    $totalPag  = max(1, (int) ceil($total / $porPagina));
    $pagina    = max(1, (int) get('p'));
    if ($pagina > $totalPag) $pagina = $totalPag;
    return [
        'total'     => $total,
        'porPagina' => $porPagina,
        'totalPag'  => $totalPag,
        'pagina'    => $pagina,
        'offset'    => ($pagina - 1) * $porPagina,
        'desde'     => $total ? ($pagina - 1) * $porPagina + 1 : 0,
        'hasta'     => min($pagina * $porPagina, $total),
    ];
}

/** Pie de paginación. Conserva los filtros de la URL. Vacío si sobra una sola página. */
function paginacion(array $pg): string
{
    if ($pg['totalPag'] <= 1) {
        return $pg['total']
            ? '<div class="px-4 py-3 border-t border-slate-100 text-sm text-slate-400">'
              . number_format($pg['total']) . ' registro' . ($pg['total'] === 1 ? '' : 's') . '</div>'
            : '';
    }

    $enlace = function (int $i) {
        $qs = $_GET; $qs['p'] = $i;
        return '?' . e(http_build_query($qs));
    };
    $btn = 'px-3 py-1.5 rounded-lg font-semibold transition-colors duration-200 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500';

    $h = '<nav aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 p-4 border-t border-slate-100 text-sm">';
    $h .= '<span class="text-slate-400">Mostrando ' . number_format($pg['desde']) . '–' . number_format($pg['hasta'])
        . ' de ' . number_format($pg['total']) . '</span>';
    $h .= '<div class="flex items-center gap-1">';

    if ($pg['pagina'] > 1) {
        $h .= '<a href="' . $enlace($pg['pagina'] - 1) . '" rel="prev" aria-label="Página anterior" class="' . $btn . ' text-slate-500 hover:bg-slate-100">' . icon('arrow-left', 'w-4 h-4') . '</a>';
    }
    if ($pg['pagina'] > 3) {
        $h .= '<a href="' . $enlace(1) . '" class="' . $btn . ' text-slate-500 hover:bg-slate-100">1</a>';
        if ($pg['pagina'] > 4) $h .= '<span class="px-1 text-slate-300" aria-hidden="true">…</span>';
    }
    for ($i = max(1, $pg['pagina'] - 2); $i <= min($pg['totalPag'], $pg['pagina'] + 2); $i++) {
        $actual = $i === $pg['pagina'];
        $h .= '<a href="' . $enlace($i) . '"' . ($actual ? ' aria-current="page"' : '')
            . ' class="' . $btn . ' ' . ($actual ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100') . '">' . $i . '</a>';
    }
    if ($pg['pagina'] < $pg['totalPag'] - 2) {
        if ($pg['pagina'] < $pg['totalPag'] - 3) $h .= '<span class="px-1 text-slate-300" aria-hidden="true">…</span>';
        $h .= '<a href="' . $enlace($pg['totalPag']) . '" class="' . $btn . ' text-slate-500 hover:bg-slate-100">' . $pg['totalPag'] . '</a>';
    }
    if ($pg['pagina'] < $pg['totalPag']) {
        $h .= '<a href="' . $enlace($pg['pagina'] + 1) . '" rel="next" aria-label="Página siguiente" class="' . $btn . ' text-slate-500 hover:bg-slate-100">' . icon('arrow-right', 'w-4 h-4') . '</a>';
    }
    return $h . '</div></nav>';
}

/* ==========================================================================
 *  Piezas de listado
 *
 *  Todo lo de aquí abajo existía ya, pero copiado a mano en decenas de
 *  páginas: 51 tarjetas de KPI, 59 formularios de borrado, 95 botones de
 *  acción y 40 barras de filtro, cada copia con su propia deriva. Un tono de
 *  gris distinto aquí, un `title` que falta allá, un borrado sin confirmar.
 *
 *  Tenerlo en un solo sitio no es limpieza: es la única forma de que la
 *  aplicación se vea igual en todas partes cuando alguien toque una página
 *  suelta dentro de seis meses.
 * ========================================================================== */

/** Fondos de icono por color. Compartidos por kpi(), rep_kpi() y rep_seccion(). */
function ui_tono(string $color = 'blue'): string
{
    $tonos = [
        'blue' => 'bg-blue-50 text-blue-600', 'emerald' => 'bg-emerald-50 text-emerald-600',
        'violet' => 'bg-violet-50 text-violet-600', 'amber' => 'bg-amber-50 text-amber-600',
        'rose' => 'bg-rose-50 text-rose-600', 'indigo' => 'bg-indigo-50 text-indigo-600',
        'cyan' => 'bg-cyan-50 text-cyan-600', 'slate' => 'bg-slate-100 text-slate-500',
        'sky' => 'bg-sky-50 text-sky-600', 'pink' => 'bg-pink-50 text-pink-600',
    ];
    return $tonos[$color] ?? $tonos['blue'];
}

/**
 * Tarjeta de indicador.
 *
 * $o: valor, label, icono, color, delta (float|null), nota, invertir, href.
 *
 * `valor` entra como HTML ya formateado (viene de money(), number_format()…);
 * `label` y `nota` se escapan. `href` la convierte en enlace: un KPI que dice
 * «12 productos bajo mínimo» debería poder llevarte a esos 12.
 */
function kpi(array $o): string
{
    $h = '<div class="flex items-start justify-between gap-3">';
    $h .= '<div class="w-11 h-11 rounded-xl ' . ui_tono($o['color'] ?? 'blue') . ' flex items-center justify-center shrink-0">'
        . icon($o['icono'] ?? 'chart', 'w-5 h-5') . '</div>';

    $delta = $o['delta'] ?? null;
    if ($delta !== null) {
        // `invertir` para las magnitudes donde bajar es la buena noticia (gastos,
        // devoluciones, morosidad): sin esto, un −20% de gastos se pintaría rojo.
        $bueno = !empty($o['invertir']) ? $delta <= 0 : $delta >= 0;
        $h .= '<span class="badge ' . ($bueno ? 'stat-trend-up' : 'stat-trend-down') . '" title="Contra el periodo anterior">'
            . icon($delta >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') . ' ' . number_format(abs($delta), 1) . '%</span>';
    }
    $h .= '</div>';
    $h .= '<p class="text-sm text-slate-500 mt-4">' . e($o['label'] ?? '') . '</p>';
    $h .= '<p class="text-[26px] leading-tight font-extrabold text-slate-800 mt-0.5 tabular-nums">' . ($o['valor'] ?? '—') . '</p>';
    if (!empty($o['nota'])) $h .= '<p class="text-xs text-slate-400 mt-1.5">' . $o['nota'] . '</p>';

    if (!empty($o['href'])) {
        return '<a href="' . e($o['href']) . '" class="card p-5 print-break block hover:border-blue-300 hover:shadow-pop transition">' . $h . '</a>';
    }
    return '<div class="card p-5 print-break">' . $h . '</div>';
}

/** Rejilla de indicadores. */
function kpis(array $lista, int $cols = 4): string
{
    $c = [
        '2' => 'sm:grid-cols-2', '3' => 'sm:grid-cols-2 xl:grid-cols-3',
        '4' => 'sm:grid-cols-2 xl:grid-cols-4', '5' => 'sm:grid-cols-2 xl:grid-cols-5',
    ];
    $h = '<div class="grid grid-cols-1 ' . ($c[(string) $cols] ?? $c['4']) . ' gap-4 mb-5">';
    foreach ($lista as $k) $h .= $k ? kpi($k) : '';
    return $h . '</div>';
}

/**
 * Cabecera de una tarjeta de listado: buscador y filtros a la izquierda,
 * contador o acciones a la derecha. Va DENTRO de `.card overflow-hidden`.
 */
function toolbar(string $izquierda, string $derecha = ''): string
{
    return '<div class="p-4 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">'
        . '<div class="flex items-center gap-2 flex-wrap min-w-0">' . $izquierda . '</div>'
        . ($derecha ? '<div class="flex items-center gap-2 flex-wrap shrink-0">' . $derecha . '</div>' : '')
        . '</div>';
}

/** Contador para la derecha de una toolbar: «1,204 productos». */
function toolbar_conteo(int $n, string $singular, string $plural = ''): string
{
    $plural = $plural ?: $singular . 's';
    return '<span class="text-sm text-slate-400 whitespace-nowrap tabular-nums">'
        . number_format($n) . ' ' . e($n === 1 ? $singular : $plural) . '</span>';
}

/**
 * Botón-icono de una fila de tabla.
 *
 * $o: icono, titulo, href | onclick, color (blue por defecto), target, aria.
 * El `title` es obligatorio de hecho: son botones sin texto, y sin él la fila
 * es indescifrable para quien use lector de pantalla.
 *
 * `aria` sirve para distinguir la fila cuando el título se repite: veinticinco
 * botones que anuncian «Anular» son indistinguibles con lector de pantalla.
 * Con `aria` se anuncia «Anular la venta VTA-000012». Por defecto es el título.
 */
function btn_icono(array $o): string
{
    $colores = [
        'blue' => 'hover:text-blue-600 hover:bg-blue-50', 'rose' => 'hover:text-rose-600 hover:bg-rose-50',
        'emerald' => 'hover:text-emerald-600 hover:bg-emerald-50', 'amber' => 'hover:text-amber-600 hover:bg-amber-50',
        'violet' => 'hover:text-violet-600 hover:bg-violet-50', 'slate' => 'hover:text-slate-700 hover:bg-slate-100',
    ];
    $cls = 'p-2 rounded-lg text-slate-400 transition ' . ($colores[$o['color'] ?? 'blue'] ?? $colores['blue']);
    $titulo = $o['titulo'] ?? '';
    $ico = icon($o['icono'] ?? 'eye', 'w-4 h-4');
    $attrs = ' class="' . $cls . '" title="' . e($titulo) . '" aria-label="' . e($o['aria'] ?? $titulo) . '"';

    if (!empty($o['href'])) {
        $t = !empty($o['target']) ? ' target="' . e($o['target']) . '" rel="noopener"' : '';
        return '<a href="' . e($o['href']) . '"' . $t . $attrs . '>' . $ico . '</a>';
    }
    return '<button type="button" onclick="' . ($o['onclick'] ?? '') . '"' . $attrs . '>' . $ico . '</button>';
}

/**
 * Borrado de una fila: formulario POST con CSRF y confirmación.
 *
 * El `onsubmit="return confirm(…)"` NO es el diálogo feo del navegador: el
 * script de footer.php lo intercepta y lo cambia por el modal de la casa. Hay
 * que emitirlo con esa forma exacta o la fila se queda con el confirm nativo.
 *
 * $o: id, pregunta, accion ('eliminar'), campo ('id'), icono, titulo, aria, extra (campos ocultos).
 */
function btn_eliminar(array $o): string
{
    $pregunta = $o['pregunta'] ?? '¿Eliminar este registro?';
    $extra = '';
    foreach (($o['extra'] ?? []) as $k => $v) {
        $extra .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    $titulo = $o['titulo'] ?? 'Eliminar';
    return '<form method="post" class="inline" onsubmit="return confirm(\'' . e($pregunta) . '\')">'
        . csrf_field()
        . '<input type="hidden" name="accion" value="' . e($o['accion'] ?? 'eliminar') . '">'
        . '<input type="hidden" name="' . e($o['campo'] ?? 'id') . '" value="' . e($o['id'] ?? '') . '">'
        . $extra
        . '<button class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition"'
        . ' title="' . e($titulo) . '" aria-label="' . e($o['aria'] ?? $titulo) . '">' . icon($o['icono'] ?? 'trash', 'w-4 h-4') . '</button>'
        . '</form>';
}

/** Agrupa los botones de la columna de acciones, alineados a la derecha. */
function acciones(array $botones): string
{
    return '<div class="flex items-center justify-end gap-1">' . implode('', array_filter($botones)) . '</div>';
}

/** Inicia una página completa (head + layout + cabecera). */
function layout_start(string $titulo, string $subtitulo = '', string $acciones = ''): void
{
    $GLOBALS['page_title'] = $titulo;
    $GLOBALS['page_subtitle'] = $subtitulo;
    $GLOBALS['page_actions'] = $acciones;
    require __DIR__ . '/layout/header.php';
}

function layout_end(): void
{
    require __DIR__ . '/layout/footer.php';
}
