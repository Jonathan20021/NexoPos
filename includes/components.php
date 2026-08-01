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
        ['Ventas', [
            ['Punto de Venta', 'cart', url('modules/pos/index.php'), 'pos.ver'],
            ['Caja', 'cash', url('modules/pos/caja.php'), 'caja.ver'],
            ['Ventas', 'receipt', url('modules/pos/ventas.php'), 'ventas.ver'],
            ['Pedidos en línea', 'store', url('modules/pos/pedidos.php'), 'pedidos.ver'],
            ['Devoluciones', 'undo', url('modules/pos/devoluciones.php'), 'devoluciones.ver'],
            ['Clientes', 'users', url('modules/pos/clientes.php'), 'clientes.ver'],
            ['Cuentas por Cobrar', 'wallet', url('modules/pos/cuentas_cobrar.php'), 'clientes.ver'],
            ['Terminales offline', 'cash', url('modules/pos/terminales.php'), 'pos.terminales'],
        ]],
        ['Inventario', [
            ['Productos', 'box', url('modules/inventario/productos.php'), 'productos.ver'],
            ['Categorías', 'tag', url('modules/inventario/categorias.php'), 'categorias.ver'],
            ['Marcas y Unidades', 'layers', url('modules/inventario/catalogos.php'), 'productos.ver'],
            ['Stock', 'layers', url('modules/inventario/stock.php'), 'inventario.ver'],
            ['Conteo físico', 'clipboard', url('modules/inventario/conteos.php'), 'conteos.ver'],
            ['Movimientos', 'history', url('modules/inventario/movimientos.php'), 'inventario.ver'],
            ['Compras', 'truck', url('modules/inventario/compras.php'), 'compras.ver'],
            ['Proveedores', 'briefcase', url('modules/inventario/proveedores.php'), 'proveedores.ver'],
            ['Transferencias', 'transfer', url('modules/inventario/transferencias.php'), 'transferencias.ver'],
        ]],
        ['Recursos Humanos', [
            ['Empleados', 'id', url('modules/rrhh/empleados.php'), 'rrhh_empleados.ver'],
            ['Asistencia', 'clock', url('modules/rrhh/asistencia.php'), 'rrhh_asistencia.ver'],
            ['Nómina', 'wallet', url('modules/rrhh/nomina.php'), 'rrhh_nomina.ver'],
            ['Vacaciones y Licencias', 'sun', url('modules/rrhh/vacaciones.php'), 'rrhh_vacaciones.ver'],
            ['Departamentos', 'building', url('modules/rrhh/departamentos.php'), 'rrhh_departamentos.ver'],
        ]],
        ['CRM', [
            ['Embudo de Ventas', 'trending', url('modules/crm/index.php'), 'crm.ver'],
            ['Oportunidades', 'briefcase', url('modules/crm/oportunidades.php'), 'crm.ver'],
            ['Interacciones', 'phone', url('modules/crm/interacciones.php'), 'crm.ver'],
            ['Tareas y Seguimientos', 'check', url('modules/crm/tareas.php'), 'crm.ver'],
        ]],
        ['Marketing', [
            ['Promociones', 'percent', url('modules/marketing/promociones.php'), 'promociones.ver'],
            ['Campañas por correo', 'mail', url('modules/marketing/campanas.php'), 'campanas.ver'],
        ]],
        ['Finanzas', [
            ['Ingresos y Gastos', 'dollar', url('modules/finanzas/index.php'), 'finanzas.ver'],
            ['Cuentas', 'wallet', url('modules/finanzas/cuentas.php'), 'finanzas.ver'],
            ['Conciliación bancaria', 'check', url('modules/finanzas/conciliacion.php'), 'conciliacion.ver'],
            ['Comisiones', 'percent', url('modules/finanzas/comisiones.php'), 'comisiones.ver'],
            ['Metas de Venta', 'trending', url('modules/finanzas/metas.php'), 'metas.ver'],
            ['Reportes DGII', 'shield', url('modules/finanzas/dgii.php'), 'dgii.ver'],
            ['IT-1 · ITBIS', 'percent', url('modules/finanzas/it1.php'), 'dgii.ver'],
        ]],
        ['Reportes', [
            ['Centro de Reportes', 'grid', url('modules/reportes/index.php'), 'reportes.ver'],
            ['Panel ejecutivo', 'trending', url('modules/reportes/ejecutivo.php'), 'reportes.ejecutivo'],
            ['Estado de resultados', 'receipt', url('modules/reportes/estado_resultados.php'), 'reportes.finanzas'],
            ['Flujo de efectivo', 'cash', url('modules/reportes/flujo_caja.php'), 'reportes.finanzas'],
            ['Cuentas por cobrar', 'wallet', url('modules/reportes/cxc.php'), 'reportes.finanzas'],
            ['Contabilidad y DGII', 'shield', url('modules/reportes/libro_diario.php'), 'reportes.contabilidad'],
            ['Reporte gerencial', 'chart', url('modules/finanzas/reportes.php'), 'reportes.ver'],
        ]],
        ['Administración', [
            ['Sucursales', 'store', url('modules/admin/sucursales.php'), 'sucursales.ver'],
            ['Usuarios', 'user', url('modules/admin/usuarios.php'), 'usuarios.ver'],
            ['Roles y Permisos', 'shield', url('modules/admin/roles.php'), 'roles.ver'],
            ['Configuración', 'settings', url('modules/admin/configuracion.php'), 'configuracion.ver'],
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
