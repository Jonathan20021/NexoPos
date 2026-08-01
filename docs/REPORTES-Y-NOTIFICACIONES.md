# Centro de Reportes y Centro de Notificaciones

Dos módulos nuevos que responden a lo mismo: que la dirección, finanzas y contabilidad
no tengan que pedirle números a nadie ni enterarse tarde de un problema.

---

## 1. Centro de Notificaciones

### Qué hace
La campana de la barra superior deja de ser un enlace a «stock bajo» y pasa a ser un
centro real de alertas: el sistema se revisa a sí mismo y avisa de lo que requiere acción.

### Cómo está pensado
Se guarda **una fila por situación viva**, no por evento. Si el stock sigue bajo mañana,
no nace otra notificación: se actualiza la misma (la columna `clave` deduplica). Cuando el
problema desaparece, la fila pasa a `resuelta` sola. Resultado: la campana muestra la
realidad de hoy y nunca acumula basura vieja.

El barrido corre **sin cron**: la primera visita que llega pasados 5 minutos reclama el
turno con un `UPDATE` atómico sobre `sistema_estado` y hace el recorrido. Aunque entren
diez peticiones en el mismo segundo, solo una hace el trabajo.

### Qué vigila
| Alerta | Prioridad | Se resuelve cuando |
|---|---|---|
| Productos sin existencia por sucursal | Crítica | Se repone el stock |
| Productos bajo el mínimo por sucursal | Alta | Se repone el stock |
| Clientes con saldo vencido a +30 días | Alta | Se cobra |
| Clientes sobre su límite de crédito | Alta | Baja el saldo o sube el límite |
| Secuencia NCF por agotarse o agotada | Media/Crítica | Se carga un rango nuevo |
| Secuencia NCF por vencer o vencida | Media/Crítica | Se renueva la autorización |
| Cajas abiertas de días anteriores | Media/Alta | Se cierra la caja |
| Transferencias enviadas sin recibir | Alta | La sucursal destino las recibe |
| Tareas del CRM vencidas (al responsable) | Alta | Se completan o cancelan |
| Pedidos en línea sin confirmar | Alta | Se confirman |
| Metas de venta en riesgo | Media | Se recupera el ritmo o cierra el periodo |
| Comisiones esperando aprobación | Media | Se aprueban |
| Solicitudes de vacaciones sin responder | Media | Se aprueban o rechazan |
| Nóminas en borrador | Media | Se procesan |
| Declaración 606/607/608 e IT-1 del mes | Media/Alta | Pasa el día 20 |
| Cumpleaños de empleados | Informativa | Cambia el día |
| Productos con precio bajo el costo | Media | Se corrige la lista de precios |

### Quién ve qué
Cada notificación lleva `permiso`, `sucursal_id` y opcionalmente `usuario_id`.
Solo la ve quien tiene ese permiso, alcanza esa sucursal y (si aplica) es el destinatario.
La marca de leída es **por usuario**: que el gerente la lea no la apaga para el cajero.

### Dónde
- Widget: `includes/layout/notificaciones.php` (campana de la barra superior).
- Página completa: `modules/notificaciones/index.php` (filtros por categoría y sin leer).
- Motor y consultas: `includes/notificaciones.php`.
- Alertas críticas también en el Dashboard, arriba del todo.

---

## 2. Centro de Reportes

`modules/reportes/index.php` es el índice: 20 reportes agrupados en cuatro bloques,
cada uno con su permiso independiente.

### Dirección General (`reportes.ejecutivo`)
| Reporte | Para qué |
|---|---|
| **Panel ejecutivo** | KPIs con variación contra el periodo anterior, tendencia de 12 meses, resultado por sucursal, canal de captación, productos más rentables, mejores clientes, metas, embudo CRM y salud financiera. |
| **Comparativo de periodos** | Actual vs. anterior vs. mismo periodo del año pasado, con desglose por sucursal, canal, vendedor y categoría. |
| **Clientes y concentración** | Ranking ABC (Pareto), riesgo de dependencia de pocos clientes, y lista de clientes dormidos para llamar. |
| **Comparativo de sucursales** | Cada local lado a lado: venta, margen, gastos, utilidad, ticket, inventario y venta por empleado. |

### Finanzas (`reportes.finanzas`)
| Reporte | Para qué |
|---|---|
| **Estado de resultados** | P&L con % sobre ingresos y comparación contra el periodo anterior, con el gasto abierto por categoría. |
| **Flujo de efectivo** | Entradas, salidas, saldo acumulado día a día, por cuenta y por concepto. Liquidez, no rentabilidad. |
| **Cuentas por cobrar** | Antigüedad 0-30/31-60/61-90/91-120/+120 por cliente, reconstruida aplicando los abonos a las facturas más viejas (FIFO). |
| **Cuentas por pagar** | Deuda con proveedores por antigüedad y compras del periodo con ITBIS y retenciones. |
| **Análisis de gastos** | Gasto por categoría y sucursal, comparado contra el periodo anterior y contra los ingresos. |
| **Rentabilidad** | Margen por producto, categoría, marca, vendedor o sucursal; detecta ventas por debajo del costo. |

### Contabilidad (`reportes.contabilidad`)
| Reporte | Para qué |
|---|---|
| **Libro diario** | Asientos debe/haber derivados de las operaciones reales, con balanza de comprobación. Se entrega tal cual a la contabilidad externa. |
| **Balance general** | Activo, pasivo y patrimonio a una fecha, con razón corriente, prueba ácida y endeudamiento. |
| **ITBIS y retenciones** | La liquidación que va al IT-1: débito fiscal − crédito fiscal − retenciones, con histórico de 12 meses. |
| **Inventario valorizado** | Existencias a costo y a precio de venta, rotación, días de inventario y mercancía dormida. |
| **Resumen de nómina** | Bruto, AFP, SFS, ISR y neto por empleado y departamento, más el aporte patronal TSS estimado. |
| **Auxiliar de cuentas** | Mayor por cuenta financiera con saldo corrido, para cruzar contra el estado de cuenta del banco. |

### Operación y Ventas (`reportes.operacion`)
| Reporte | Para qué |
|---|---|
| **Libro de ventas** | Factura por factura con NCF, cliente, vendedor, forma de pago y margen. Filtros por estado, comprobante y vendedor. |
| **Desempeño de productos** | Más vendidos, cobertura en días, riesgo de quiebre y productos que no rotaron. |
| **Desempeño del equipo** | Venta, margen, ticket, clientes, descuentos, devoluciones, cumplimiento de meta y comisión por vendedor. |
| **Horarios y tráfico** | A qué hora y qué día se vende, con mapa de calor semanal y rendimiento por turno de caja. |

### Criterio contable (por qué los números cuadran entre reportes)
- **Ingresos = `subtotal − descuento`**, sin ITBIS. El ITBIS se cobra por cuenta de la DGII:
  meterlo como ingreso infla la venta y falsea el margen.
- **Utilidad bruta = ingresos − `ventas.costo_total`**.
- **Gastos operativos** excluyen la compra de mercancía (es inventario: su costo entra al
  resultado cuando el producto se vende) y las devoluciones (ya restan del ingreso).
  Se filtran siempre con `rep_where_gastos()`.
- **Otros ingresos** excluyen las ventas y los cobros de abonos (cobrar algo ya facturado
  no es ingreso nuevo).

Saltarse esto es lo que hace que dos reportes del mismo sistema den cifras distintas.

### Exportación
Los 20 reportes se descargan en **Excel (.xlsx)** y en **PDF con el logo de la empresa**
desde la misma barra de filtros. Todos respetan el periodo, la sucursal activa y los filtros
aplicados en pantalla.

---

## Instalación

- **Instalación nueva:** ya viene todo en `database/schema.sql`; el instalador siembra los
  permisos desde `app/permissions.php`.
- **Base existente:** aplicar una vez
  `database/migracion_notificaciones_p3.sql` (crea `notificaciones`,
  `notificacion_lecturas` y `sistema_estado`, siembra los permisos nuevos y se los concede
  a todo rol que ya podía ver reportes). Es idempotente.

Si la migración no se ha aplicado, el sistema **no se rompe**: la campana no muestra nada
y la página de notificaciones explica qué hay que ejecutar.
