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
| Traslados esperando autorización | Media/Alta | Quien aprueba los autoriza o los devuelve |
| Conteos físicos abiertos sin aplicar | Media/Alta | Se aplican o se cancelan |
| Tareas del CRM vencidas (al responsable) | Alta | Se completan o cancelan |
| Pedidos en línea sin confirmar | Alta | Se confirman |
| Metas de venta en riesgo | Media | Se recupera el ritmo o cierra el periodo |
| Comisiones esperando aprobación | Media | Se aprueban |
| Solicitudes de vacaciones sin responder | Media | Se aprueban o rechazan |
| Nóminas en borrador | Media | Se procesan |
| Declaración 606/607/608 e IT-1 del mes | Media/Alta | Pasa el día 20 |
| Cumpleaños de empleados | Informativa | Cambia el día |
| Productos con precio bajo el costo | Media | Se corrige la lista de precios |
| Registros sanitarios y lotes por vencer | Media/Crítica | Se renueva el registro o sale el lote |
| e-CF rechazados o sin respuesta de la DGII | Alta | Se corrige y se reenvía |
| Intentos de acceso fallidos seguidos | Alta | Cesan o se bloquea la cuenta |

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

`modules/reportes/index.php` es el índice: 32 informes en seis bloques. Cada bloque
tiene su permiso; además, un informe suelto puede llevar el suyo propio y entonces se
ve sin abrir el resto del grupo (columna «permiso propio»). Así la encargada de
inventario abre las existencias de las trece tiendas sin ver el libro diario ni la
nómina. Lo resuelve `rep_catalogo_visible()`.

### Área de Dirección (`direccion.ver`)
| Reporte | Para qué | Permiso propio |
|---|---|---|
| **Panel de Dirección** | Año contra año, mes contra mes, ventas por marca y mercancía en camino, en una sola pantalla. | — |
| **Año contra año** | Matriz de doce meses con los dos años lado a lado y la variación de cada mes, por tienda, sucursal y categoría. | — |
| **Reportería de costos** | Costo de lo vendido, margen real, inventario a costo, recargo de importación y artículos que se venden bajo costo. | — |

### Dirección General (`reportes.ejecutivo`)
| Reporte | Para qué | Permiso propio |
|---|---|---|
| **Panel ejecutivo** | KPIs del periodo, tendencia de 12 meses, márgenes, metas y alertas en una sola pantalla. | — |
| **Comparativo de periodos** | Mes contra mes y año contra año, con variación por sucursal, canal y categoría. | — |
| **Clientes y concentración** | Ranking ABC (Pareto), recencia, frecuencia y riesgo de dependencia de pocos clientes. | — |
| **Comparativo de sucursales** | Venta, margen, ticket y productividad por local, lado a lado. | `reportes.sucursales` |

### Finanzas (`reportes.finanzas`)
| Reporte | Para qué | Permiso propio |
|---|---|---|
| **Estado de resultados** | P&L mensual con % sobre ventas y comparación contra el periodo anterior. | — |
| **Flujo de efectivo** | Entradas, salidas y saldo acumulado por día y por cuenta. | — |
| **Cuentas por cobrar** | Antigüedad de saldos 0-30/31-60/61-90/+90 por cliente y exposición al crédito. | — |
| **Cuentas por pagar** | Compras a crédito por proveedor con antigüedad y vencimientos. | — |
| **Análisis de gastos** | Gasto por categoría y sucursal, tendencia mensual y peso sobre la venta. | — |
| **Rentabilidad** | Margen por producto, categoría, sucursal y vendedor. Detecta ventas bajo costo. | — |

### Contabilidad (`reportes.contabilidad`)
| Reporte | Para qué | Permiso propio |
|---|---|---|
| **Libro diario** | Todos los asientos del periodo: ventas, compras, gastos, nómina y movimientos de caja. | — |
| **Balance general** | Activo, pasivo y patrimonio a una fecha, derivado de los saldos del sistema. | — |
| **ITBIS y retenciones** | ITBIS cobrado vs. adelantado, retenciones y saldo a pagar del periodo. | — |
| **Inventario valorizado** | Existencias a costo y a precio de venta, por sucursal y categoría. | `reportes.inventario` |
| **Existencias por tienda** | El mismo artículo en todos los locales, lado a lado, con lo que está bajo mínimo o en cero. | `reportes.inventario` |
| **Ajustes y mermas** | Todo lo que bajó o subió la existencia sin una venta detrás, con su nota, su responsable y su costo. Más los **faltantes en tránsito**: lo que salió de una tienda y nunca llegó a la otra, en su propia sección porque no es un ajuste de nadie. | `reportes.inventario` |
| **Resumen de nómina** | Bruto, AFP, SFS, ISR y neto por periodo, empleado y departamento. | — |
| **Costo de la plantilla** | Lo que cuesta la gente contratada hoy, por sucursal y por marca, con los aportes patronales. | — |
| **Auxiliar de cuentas** | Mayor por cuenta financiera con saldo inicial, movimientos y saldo final. | — |

### Cumplimiento sanitario (`reportes.sanidad`)
| Reporte | Para qué | Permiso propio |
|---|---|---|
| **Expediente de auditoria** | El documento consolidado para entregar en una inspeccion: semaforo de cumplimiento, registros, vencidos y proveedores. | — |
| **Registros sanitarios** | Vigencia del registro de cada producto regulado: sin registro, vencidos y por vencer. | — |
| **Control de vencimientos** | Mercancia vencida y proxima a vencer por lote y sucursal, con el dinero inmovilizado. | `reportes.vencimientos` |
| **Trazabilidad de lote** | Retiro del mercado: de que proveedor entro un lote y a que clientes salio, con sus facturas. | — |
| **Ficha sanitaria de proveedores** | Licencia, vigencia y que productos regulados surte cada proveedor. | — |

### Operación y Ventas (`reportes.operacion`)
| Reporte | Para qué | Permiso propio |
|---|---|---|
| **Libro de ventas** | Detalle factura por factura con NCF, cliente, vendedor, método de pago y margen. | — |
| **Desempeño de productos** | Más vendidos, sin rotación, quiebres de stock y días de inventario. | — |
| **Desempeño del equipo** | Venta, margen, ticket promedio, descuentos y cumplimiento de meta por vendedor. | — |
| **Horarios y tráfico** | A qué hora y qué día se vende, para ajustar turnos e inventario. | — |
| **Movimiento entre tiendas** | Qué salió de dónde y a dónde, con su motivo y quién autorizó la salida. | `transferencias.ver` |

### Qué mover y a dónde
`modules/reportes/reposicion.php` — el paso siguiente a *Existencias por tienda*.
Aquel dice dónde está la mercancía; este dice qué mover.

Para cada artículo en cada tienda:

    venta diaria = unidades vendidas en la ventana / días de la ventana
    necesita     = MAX(stock mínimo, venta diaria × días de cobertura)
    déficit      = necesita − existencia
    excedente    = existencia − necesita

Luego reparte lo que sobra en unas tiendas hacia lo que falta en otras,
empezando por el excedente más grande —así salen menos traslados y más gordos—
y agrupa el resultado **por ruta**, que es como se crean los traslados. Lo que no
alcanza a cubrirse no se inventa: sale como «hay que comprar».

Los **días de cobertura** son la única suposición y está arriba, editable. La
venta diaria no lo es: es lo que de verdad se vendió, neto de devoluciones.

Cuando no hay nada que proponer, la pantalla distingue dos casos que no son lo
mismo: que todo esté surtido, o que **no haya con qué calcularlo** —ningún
artículo con stock mínimo y ninguna venta en la ventana—. Decir lo primero
cuando pasa lo segundo es mentir con cara tranquilizadora.

**Del informe al traslado sin retecleaqr.** Cada ruta lleva un botón que abre el
formulario de transferencia **ya relleno** con sus líneas: origen, destino,
productos y cantidades. Viaja por la URL como `sug=idProducto:cantidad,...` y la
pantalla de transferencias lo trata como lo que es —una sugerencia, no una
orden—: comprueba que el producto exista y esté activo, que la cantidad sea
positiva y que origen y destino sean sucursales distintas a las que quien mira
tenga acceso, y toma el **nombre de la base**, no el que venga en la URL. Si algo
no cuadra lo dice, en vez de abrir un formulario con basura dentro.

Reutiliza el mismo evento con el que se edita un borrador, con `id = 0`: así no
hay dos caminos que mantener.

### Criterio contable (por qué los números cuadran entre reportes)
- **Ingresos = `subtotal − descuento`**, sin ITBIS. El ITBIS se cobra por cuenta de la DGII:
  meterlo como ingreso infla la venta y falsea el margen.
- **Utilidad bruta = ingresos − `ventas.costo_total`**.
- **Gastos operativos** excluyen la compra de mercancía (es inventario: su costo entra al
  resultado cuando el producto se vende) y las devoluciones (ya restan del ingreso).
  Se filtran siempre con `rep_where_gastos()`.
- **Otros ingresos** excluyen las ventas y los cobros de abonos (cobrar algo ya facturado
  no es ingreso nuevo).
- **Qué venta cuenta**: `rep_estados_venta()` → `estado IN ('completada','devuelta')`, y
  después se resta lo devuelto con `rep_devoluciones*()`. Dejar fuera la `devuelta` borra la
  factura ENTERA aunque solo hubiese vuelto una unidad de cinco; contarla sin restar la
  devolución cuenta mercancía que ya está otra vez en el almacén. Hay que hacer las dos cosas.
- **El costo también se resta**: lo devuelto vuelve al inventario y deja de ser costo de
  venta. Si solo se resta el ingreso, el margen sale hundido.

Saltarse esto es lo que hace que dos reportes del mismo sistema den cifras distintas.

#### Quién aplica el criterio
Lo siguen el Panel de entrada, el Panel ejecutivo, el Comparativo de periodos, el Estado de
resultados, Desempeño de productos, Desempeño del equipo, Comparativo de sucursales e
Inventario valorizado (para la rotación).

Dos excepciones a propósito:
- **Horarios y tráfico** cuenta la venta «devuelta» pero NO resta la devolución: mide a qué
  hora entró gente y compró, y esa compra ocurrió a esa hora aunque parte volviera después.
  El propio informe lo dice en pantalla.
- **Libro de ventas** lista cada factura con su estado, así que no agrega nada que netear.

Siguen pendientes de alinear —y por eso pueden discrepar— Rentabilidad, Clientes y
concentración, Libro diario, Balance general, ITBIS y retenciones, Análisis de gastos y las
tres pantallas del Área de Dirección. En las contables (libro diario, balance, ITBIS) el
tratamiento de la devolución es una decisión contable, no un descuido: la venta original
emitió su NCF y la devolución emite su nota de crédito, y ambas tienen que aparecer.

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
