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
            // Marcas comerciales con las que se factura (logo, colores, política
            // del ticket). No es un permiso fiscal: el emisor sigue siendo la
            // empresa. Ver docs/TIENDAS-Y-DIRECCION.md.
            'tiendas'       => ['label' => 'Tiendas y marcas', 'acciones' => $crud],
            'usuarios'      => ['label' => 'Usuarios', 'acciones' => $crud],
            'roles'         => ['label' => 'Roles y Permisos', 'acciones' => $crud],
            'configuracion' => ['label' => 'Configuración', 'acciones' => ['ver' => 'Ver', 'editar' => 'Editar']],
            'auditoria'     => ['label' => 'Auditoría / Logs', 'acciones' => ['ver' => 'Ver']],
            // Se separa de `configuracion` a propósito: quien puede eximir a un
            // usuario del segundo factor puede debilitar el acceso de todos.
            'seguridad'     => ['label' => 'Seguridad de acceso', 'acciones' => ['gestionar' => 'Cambiar la política de verificación y revocar equipos']],
            'monedas'       => ['label' => 'Monedas y tasa de cambio', 'acciones' => ['gestionar' => 'Ver y actualizar la tasa']],
        ],
        'Inventario' => [
            'categorias'     => ['label' => 'Categorías', 'acciones' => $crud],
            'productos'      => ['label' => 'Productos', 'acciones' => $crud + ['etiquetas' => 'Imprimir etiquetas con código de barras']],
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
            // Aplicar se separa de crear a propósito: es la firma que entra la
            // mercancía al inventario y reescribe el costo de venta del catálogo.
            'liquidaciones'  => ['label' => 'Liquidación de importaciones', 'acciones' => [
                'ver'     => 'Ver',
                'crear'   => 'Crear y editar el borrador',
                'aplicar' => 'Aplicar (entra mercancía y fija el costo)',
                'anular'  => 'Anular',
            ]],
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
            // Se separa de `dgii` a propósito: quien puede cambiar el ambiente o
            // los rangos de secuencia puede emitir comprobantes fiscales reales.
            'ecf'      => ['label' => 'Facturación Electrónica (e-CF)', 'acciones' => [
                'ver'        => 'Ver el panel y los comprobantes emitidos',
                'configurar' => 'Configurar credenciales, ambiente y secuencias',
                'emitir'     => 'Emitir, reenviar y consultar comprobantes',
            ]],
        ],
        'Dirección' => [
            // El área de la CEO. `importar` se separa de `ver` porque sube (y
            // revierte) un año entero de datos de un golpe.
            'direccion' => ['label' => 'Área de Dirección', 'acciones' => [
                'ver'      => 'Ver el panel, los comparativos y la reportería de costos',
                'importar' => 'Cargar y revertir datos históricos',
            ]],
        ],
        'Reportes' => [
            'reportes' => ['label' => 'Centro de Reportes', 'acciones' => [
                'ver'          => 'Ver el centro de reportes',
                'ejecutivo'    => 'Reportes de dirección (CEO)',
                'finanzas'     => 'Reportes financieros',
                'contabilidad' => 'Reportes contables y fiscales',
                'operacion'    => 'Reportes de operación y ventas',
                'sanidad'      => 'Reportes de cumplimiento sanitario',
            ]],
        ],
        'Cumplimiento' => [
            'sanidad' => ['label' => 'Cumplimiento sanitario y lotes', 'acciones' => [
                'ver'      => 'Ver registros y lotes',
                'editar'   => 'Editar la ficha sanitaria del producto y del proveedor',
                'lotes'    => 'Registrar y corregir lotes',
                'bloquear' => 'Bloquear o liberar un lote (retiro del mercado)',
                'baja'     => 'Dar de baja mercancía vencida',
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
