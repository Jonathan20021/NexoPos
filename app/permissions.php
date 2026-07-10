<?php
/**
 * Catálogo único de permisos. Fuente de verdad para:
 *  - El instalador (siembra la tabla `permisos`)
 *  - La pantalla de Roles (asignación de permisos)
 *  - Las verificaciones can('modulo.accion')
 */
function permission_catalog(): array
{
    $crud = ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'eliminar' => 'Eliminar'];

    return [
        'Administración' => [
            'sucursales'    => ['label' => 'Sucursales', 'acciones' => $crud],
            'usuarios'      => ['label' => 'Usuarios', 'acciones' => $crud],
            'roles'         => ['label' => 'Roles y Permisos', 'acciones' => $crud],
            'configuracion' => ['label' => 'Configuración', 'acciones' => ['ver' => 'Ver', 'editar' => 'Editar']],
            'auditoria'     => ['label' => 'Auditoría / Logs', 'acciones' => ['ver' => 'Ver']],
        ],
        'Inventario' => [
            'categorias'     => ['label' => 'Categorías', 'acciones' => $crud],
            'productos'      => ['label' => 'Productos', 'acciones' => $crud],
            'inventario'     => ['label' => 'Stock y Ajustes', 'acciones' => ['ver' => 'Ver', 'ajustar' => 'Ajustar']],
            'proveedores'    => ['label' => 'Proveedores', 'acciones' => $crud],
            'compras'        => ['label' => 'Compras', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'anular' => 'Anular']],
            'transferencias' => ['label' => 'Transferencias', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'recibir' => 'Recibir', 'anular' => 'Anular']],
        ],
        'Ventas' => [
            'pos'          => ['label' => 'Punto de Venta', 'acciones' => ['ver' => 'Ver', 'vender' => 'Vender']],
            'caja'         => ['label' => 'Caja', 'acciones' => ['ver' => 'Ver', 'abrir' => 'Abrir', 'cerrar' => 'Cerrar', 'movimiento' => 'Movimientos']],
            'ventas'       => ['label' => 'Ventas', 'acciones' => ['ver' => 'Ver', 'anular' => 'Anular', 'muestra' => 'Facturar muestras (RD$0.00)']],
            'pedidos'      => ['label' => 'Pedidos en línea', 'acciones' => ['ver' => 'Ver', 'gestionar' => 'Cambiar estado']],
            'devoluciones' => ['label' => 'Devoluciones', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear']],
            'clientes'     => ['label' => 'Clientes', 'acciones' => $crud],
        ],
        'Recursos Humanos' => [
            'rrhh_empleados'     => ['label' => 'Empleados', 'acciones' => $crud],
            'rrhh_departamentos' => ['label' => 'Departamentos y Puestos', 'acciones' => $crud],
            'rrhh_asistencia'    => ['label' => 'Asistencia', 'acciones' => ['ver' => 'Ver', 'registrar' => 'Registrar']],
            'rrhh_nomina'        => ['label' => 'Nómina', 'acciones' => ['ver' => 'Ver', 'procesar' => 'Procesar', 'pagar' => 'Pagar']],
            'rrhh_vacaciones'    => ['label' => 'Vacaciones y Licencias', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'aprobar' => 'Aprobar']],
        ],
        'Finanzas' => [
            'finanzas' => ['label' => 'Ingresos y Gastos', 'acciones' => $crud],
            'reportes' => ['label' => 'Reportes', 'acciones' => ['ver' => 'Ver']],
            'metas'    => ['label' => 'Metas de Venta', 'acciones' => ['ver' => 'Ver', 'gestionar' => 'Crear/editar']],
            'dgii'     => ['label' => 'Reportes DGII', 'acciones' => ['ver' => 'Ver', 'generar' => 'Generar archivo']],
        ],
    ];
}

/** Devuelve [clave => ['modulo','grupo','descripcion']] para todas las acciones. */
function permission_keys(): array
{
    $keys = [];
    foreach (permission_catalog() as $grupo => $mods) {
        foreach ($mods as $mod => $cfg) {
            foreach ($cfg['acciones'] as $acc => $label) {
                $keys[$mod . '.' . $acc] = [
                    'modulo'      => $mod,
                    'grupo'       => $grupo,
                    'descripcion' => $cfg['label'] . ' — ' . $label,
                ];
            }
        }
    }
    return $keys;
}
