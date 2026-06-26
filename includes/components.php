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
            ['Devoluciones', 'undo', url('modules/pos/devoluciones.php'), 'devoluciones.ver'],
            ['Clientes', 'users', url('modules/pos/clientes.php'), 'clientes.ver'],
            ['Cuentas por Cobrar', 'wallet', url('modules/pos/cuentas_cobrar.php'), 'clientes.ver'],
        ]],
        ['Inventario', [
            ['Productos', 'box', url('modules/inventario/productos.php'), 'productos.ver'],
            ['Categorías', 'tag', url('modules/inventario/categorias.php'), 'categorias.ver'],
            ['Marcas y Unidades', 'layers', url('modules/inventario/catalogos.php'), 'productos.ver'],
            ['Stock', 'layers', url('modules/inventario/stock.php'), 'inventario.ver'],
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
        ['Finanzas', [
            ['Ingresos y Gastos', 'dollar', url('modules/finanzas/index.php'), 'finanzas.ver'],
            ['Cuentas', 'wallet', url('modules/finanzas/cuentas.php'), 'finanzas.ver'],
            ['Comisiones', 'percent', url('modules/finanzas/comisiones.php'), 'reportes.ver'],
            ['Reportes', 'chart', url('modules/finanzas/reportes.php'), 'reportes.ver'],
        ]],
        ['Administración', [
            ['Sucursales', 'store', url('modules/admin/sucursales.php'), 'sucursales.ver'],
            ['Usuarios', 'user', url('modules/admin/usuarios.php'), 'usuarios.ver'],
            ['Roles y Permisos', 'shield', url('modules/admin/roles.php'), 'roles.ver'],
            ['Configuración', 'settings', url('modules/admin/configuracion.php'), 'configuracion.ver'],
            ['Auditoría / Logs', 'list', url('modules/admin/auditoria.php'), 'auditoria.ver'],
            ['Respaldo', 'download', url('modules/admin/respaldo.php'), 'configuracion.ver'],
        ]],
    ];
}

function navActive(string $fullUrl): bool
{
    $path = parse_url($fullUrl, PHP_URL_PATH) ?: '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return $path !== '' && $script !== '' && strpos($script, $path) !== false;
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

/** Renderiza los mensajes flash. */
function render_flashes(): void
{
    $iconos = ['success' => 'check', 'error' => 'alert', 'warning' => 'alert', 'info' => 'bell'];
    $estilos = [
        'success' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
        'error'   => 'bg-rose-50 border-rose-200 text-rose-800',
        'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
        'info'    => 'bg-sky-50 border-sky-200 text-sky-800',
    ];
    foreach (get_flashes() as $f) {
        $est = $estilos[$f['tipo']] ?? $estilos['info'];
        $ic = $iconos[$f['tipo']] ?? 'bell';
        echo '<div x-data="{show:true}" x-show="show" x-transition class="flex items-start gap-3 rounded-xl border px-4 py-3 mb-4 text-sm font-medium ' . $est . '">'
            . icon($ic, 'w-5 h-5 shrink-0 mt-0.5') . '<span class="flex-1">' . e($f['mensaje']) . '</span>'
            . '<button @click="show=false" class="opacity-60 hover:opacity-100">' . icon('x', 'w-4 h-4') . '</button></div>';
    }
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
function search_box(string $placeholder = 'Buscar...', array $hidden = []): string
{
    $h = '';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return '<form method="get" class="relative w-full sm:w-80">' . $h
        . '<span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400">' . icon('search', 'w-4 h-4') . '</span>'
        . '<input type="text" name="q" value="' . e($_GET['q'] ?? '') . '" placeholder="' . e($placeholder) . '" class="input pl-10">'
        . '</form>';
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
