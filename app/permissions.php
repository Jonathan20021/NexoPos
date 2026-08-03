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
            'monedas'       => ['label' => 'Monedas y tasa de cambio', 'acciones' => ['gestionar' => 'Ver y actualizar la tasa']],
        ],
        'Inventario' => [
            'categorias'     => ['label' => 'Categorías', 'acciones' => $crud],
            'productos'      => ['label' => 'Productos', 'acciones' => $crud],
            'inventario'     => ['label' => 'Stock y Ajustes', 'acciones' => ['ver' => 'Ver', 'ajustar' => 'Ajustar']],
            'conteos'        => ['label' => 'Conteo físico de inventario', 'acciones' => [
                'ver'      => 'Ver',
                'crear'    => 'Abrir conteo',
                'contar'   => 'Capturar cantidades',
                'aplicar'  => 'Aplicar ajustes al stock',
                'cancelar' => 'Cancelar conteo',
            ]],
            'proveedores'    => ['label' => 'Proveedores', 'acciones' => $crud],
            'compras'        => ['label' => 'Compras', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'anular' => 'Anular']],
            'cxp'            => ['label' => 'Cuentas por Pagar', 'acciones' => ['ver' => 'Ver', 'pagar' => 'Registrar pagos']],
            'transferencias' => ['label' => 'Transferencias', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear/editar borrador', 'enviar' => 'Enviar', 'recibir' => 'Recibir', 'rechazar' => 'Rechazar', 'anular' => 'Anular']],
        ],
        'Ventas' => [
            'pos'          => ['label' => 'Punto de Venta', 'acciones' => ['ver' => 'Ver', 'vender' => 'Vender', 'terminales' => 'Terminales offline (NCF)']],
            'caja'         => ['label' => 'Caja', 'acciones' => ['ver' => 'Ver', 'abrir' => 'Abrir', 'cerrar' => 'Cerrar', 'movimiento' => 'Movimientos']],
            'ventas'       => ['label' => 'Ventas', 'acciones' => ['ver' => 'Ver', 'anular' => 'Anular', 'muestra' => 'Facturar muestras (RD$0.00)']],
            'pedidos'      => ['label' => 'Pedidos en línea', 'acciones' => ['ver' => 'Ver', 'gestionar' => 'Cambiar estado']],
            'devoluciones' => ['label' => 'Devoluciones', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear']],
            'clientes'     => ['label' => 'Clientes', 'acciones' => $crud],
            'cotizaciones' => ['label' => 'Cotizaciones', 'acciones' => [
                'ver'      => 'Ver',
                'crear'    => 'Crear y editar',
                'eliminar' => 'Eliminar',
                'facturar' => 'Convertir en factura',
            ]],
        ],
        'Recursos Humanos' => [
            'rrhh_empleados'     => ['label' => 'Empleados', 'acciones' => $crud],
            'rrhh_departamentos' => ['label' => 'Departamentos y Puestos', 'acciones' => $crud],
            'rrhh_asistencia'    => ['label' => 'Asistencia', 'acciones' => ['ver' => 'Ver', 'registrar' => 'Registrar']],
            'rrhh_nomina'        => ['label' => 'Nómina', 'acciones' => ['ver' => 'Ver', 'procesar' => 'Procesar', 'pagar' => 'Pagar']],
            'rrhh_vacaciones'    => ['label' => 'Vacaciones y Licencias', 'acciones' => ['ver' => 'Ver', 'crear' => 'Crear', 'aprobar' => 'Aprobar']],
        ],
        'Finanzas' => [
            'finanzas'   => ['label' => 'Ingresos y Gastos', 'acciones' => $crud],
            'activos'    => ['label' => 'Activos fijos y depreciación', 'acciones' => [
                'ver'       => 'Ver',
                'crear'     => 'Registrar',
                'editar'    => 'Editar',
                'depreciar' => 'Correr la depreciación mensual',
                'baja'      => 'Dar de baja o vender',
            ]],
            'comisiones' => ['label' => 'Comisiones', 'acciones' => ['ver' => 'Ver', 'generar' => 'Generar/registrar', 'aprobar' => 'Aprobar', 'pagar' => 'Pagar', 'anular' => 'Anular']],
            'conciliacion' => ['label' => 'Conciliación bancaria', 'acciones' => ['ver' => 'Ver', 'conciliar' => 'Marcar movimientos', 'cerrar' => 'Cerrar corte']],
            'metas'    => ['label' => 'Metas de Venta', 'acciones' => ['ver' => 'Ver', 'gestionar' => 'Crear/editar']],
            'dgii'     => ['label' => 'Reportes DGII', 'acciones' => ['ver' => 'Ver', 'generar' => 'Generar archivo']],
        ],
        'Reportes' => [
            'reportes' => ['label' => 'Centro de Reportes', 'acciones' => [
                'ver'          => 'Ver el centro de reportes',
                'ejecutivo'    => 'Reportes de dirección (CEO)',
                'finanzas'     => 'Reportes financieros',
                'contabilidad' => 'Reportes contables y fiscales',
                'operacion'    => 'Reportes de operación y ventas',
            ]],
        ],
        'Marketing' => [
            'promociones' => ['label' => 'Promociones', 'acciones' => $crud],
            'campanas'    => ['label' => 'Campañas (correo y WhatsApp)', 'acciones' => [
                'ver'      => 'Ver',
                'crear'    => 'Crear',
                'editar'   => 'Editar',
                'eliminar' => 'Eliminar',
                'enviar'   => 'Enviar correos',
                'whatsapp' => 'Consola de envío por WhatsApp',
            ]],
            'marketing'   => ['label' => 'Marketing (panel, segmentos y automatización)', 'acciones' => [
                'ver'        => 'Ver el panel',
                'segmentos'  => 'Crear y editar segmentos',
                'plantillas' => 'Crear y editar plantillas',
                'automatizar' => 'Encender y configurar automatizaciones',
                'diseno'     => 'Diseño del correo (colores y logo)',
            ]],
        ],
        'CRM' => [
            'crm' => ['label' => 'CRM (ficha 360°, embudo y seguimientos)', 'acciones' => [
                'ver'      => 'Ver',
                'crear'    => 'Crear oportunidades, interacciones y tareas',
                'editar'   => 'Editar',
                'eliminar' => 'Eliminar',
                'avanzar'  => 'Mover etapa del embudo (ganar/perder)',
            ]],
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
