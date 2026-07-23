# CRM — Ficha 360°, embudo de ventas y seguimientos

Módulo de relación comercial integrado a NexoPOS. Multi-sucursal y conectado a
clientes, ventas, cuentas por cobrar y usuarios existentes.

## Concepto multi-sucursal
- Los **clientes son globales** (un mismo cliente compra en cualquier sucursal).
- Cada **oportunidad, interacción y tarea pertenece a UNA sucursal** y se filtra
  con `sucursalScope()`. Un usuario atado a una sucursal solo ve el CRM de la suya;
  un usuario en «Todas las sucursales» ve y elige sucursal al crear.
- Al crear registros, la sucursal se resuelve con `crm_resolver_sucursal()`
  (`modules/crm/_crm.php`): fuerza la del usuario si está fijado a una, o toma la
  del formulario cuando opera en «todas». Valida acceso con `can_access_sucursal()`.

## Tablas (`database/schema.sql`)
- `crm_oportunidades` — embudo/pipeline. Etapas: prospecto → contactado → propuesta
  → negociación → **ganada / perdida**. Campos: valor_estimado, probabilidad,
  fuente (canal), responsable_id (usuario), fecha_cierre_estimada/real,
  motivo_perdida, `venta_id` (enlace opcional a la venta al ganar). Código `OPT-#####`.
- `crm_interacciones` — bitácora de contactos (llamada, whatsapp, email, visita,
  reunión, nota). Opcionalmente ligada a una oportunidad.
- `crm_tareas` — agenda/seguimientos con vencimiento, prioridad y estado
  (pendiente/completada/cancelada). Ligable a cliente y/o oportunidad.

Al **eliminar un cliente** se arrastran sus registros de CRM (`ON DELETE CASCADE`).

## Permisos (`app/permissions.php`, grupo «CRM», módulo `crm`)
`crm.ver`, `crm.crear`, `crm.editar`, `crm.eliminar`, `crm.avanzar` (mover etapa /
ganar / perder). En instalación nueva el instalador los siembra desde el catálogo y
los concede a Super Administrador, Administrador y Gerente de Sucursal.

## Páginas (`modules/crm/`)
| Archivo | Ruta de menú | Qué hace |
|---|---|---|
| `index.php` | Embudo de Ventas | Tablero Kanban por etapa + KPIs (valor pipeline, ganadas/perdidas del mes, tareas vencidas). Mover etapa desde cada tarjeta. |
| `oportunidades.php` | Oportunidades | CRUD completo, filtros (etapa/responsable), export Excel/PDF, avanzar etapa inline. |
| `interacciones.php` | Interacciones | Bitácora CRUD + export. |
| `tareas.php` | Tareas y Seguimientos | Agenda con filtros (pendientes/vencidas/completadas), completar/cancelar. |
| `cliente.php?id=` | Ficha 360° | Centro de mando del cliente: datos + KPIs (total comprado, balance CxC, pipeline), oportunidades, bitácora, tareas, últimas ventas y abonos. Registra interacción/oportunidad/tarea sin salir de la ficha. |
| `_crm.php` | — | Helpers compartidos (etapas, tipos, prioridades, resolución de sucursal). |

La **Ficha 360°** se abre desde el botón con ícono de identidad en cada fila de
`modules/pos/clientes.php`, y desde los enlaces de cliente en todas las páginas CRM.

## Instalación en producción (BD existente)
Correr una vez: `database/migracion_crm_p1.sql` (idempotente). Crea las 3 tablas,
siembra los 5 permisos y los asigna a Super Administrador, Administrador y Gerente.
Instalaciones nuevas no lo necesitan: ya está en `schema.sql`.

## Convenciones seguidas
Patrón de página de módulo, PRG con `flash()`, CSRF en cada form, `require_perm()`
por acción, `audit()` tras crear/editar/eliminar, modales Alpine con `jsEvent()`,
`export_tabla()`, `paginar()/paginacion()`. Ver `docs/CONVENCIONES-DEV.md`.
