# Promotion Cockpit y KPIs de campañas

Lo que pidió la marca (L'Occitane) para medir sus promociones y sus campañas como lo hace la
casa matriz, pero con los datos del POS y sin copiar nada a mano en Excel.

| Pantalla | Ruta | Permiso |
|---|---|---|
| Promotion Cockpit | Marketing → Promotion Cockpit (`modules/marketing/cockpit.php`) | `cockpit.ver` |
| KPIs de campañas | Marketing → KPIs de campañas (`modules/marketing/kpi_campanas.php`) | `kpi_campanas.*` |
| Configuración del cockpit | Marketing → Configuración del cockpit (`modules/marketing/cockpit_config.php`) | `cockpit.configurar` |

Las dos aparecen también en el Centro de Reportes (grupo Operación y Ventas).

## Instalación

1. Ejecutar `database/migracion_cockpit_promociones_p39.sql` y después
   `database/migracion_cockpit_config_p40.sql` (las dos idempotentes; reversión al final).
2. Clasificar las promociones: en **Marketing → Promociones**, cada una lleva ahora un
   **código** y un **tipo de descuento** (sets de regalo, calendario, GWP, CRM, operación
   especial, temporada, lanzamiento, empleados, outlet). El tipo es la fila del cockpit.
3. Clasificar los productos: **segmento** (Body, Face, Hand…), **línea** (Almond, Shea,
   Immortelle…) y **producto héroe**. Uno a uno en la ficha del producto, o todos de golpe en
   el cockpit → pestaña Producto → **Clasificar** (pegar desde Excel: `SKU; segmento; línea; héroe`),
   o en Configuración del cockpit → Productos (tabla editable, filtro «sin clasificar» y
   renombrar/unir un segmento o una línea en todos los productos).

## Todo se configura desde la pantalla (P40)

Nada de lo que clasifica o reporta el cockpit está fijo en el código. En **Marketing →
Configuración del cockpit**:

| Pestaña | Qué se cambia |
|---|---|
| Tipos de descuento | nombre, **color** en los gráficos, orden, si es familia de promoción, si es **motivo del POS** (etiqueta en caja), activo. Crear y borrar (solo si nunca se usó). |
| Canales | canales de la marca (nombre y nombre en inglés para el Excel) y las **reglas** que mandan cada venta a su canal: por canal de captación o por tipo de comprobante; gana la primera. Vista previa con las ventas reales de 24 meses. |
| Rubros de inversión | las filas de la hoja INVESTMENT, en español y en inglés. |
| KPIs de campañas | los KPIs capturados a mano: grupo, nombre, inglés, unidad, **rol** (tráfico → conversión en tienda; sesiones web → conversión online) y «menor es mejor». |
| Parámetros | periodo al abrir el cockpit, mes de inicio del año fiscal, canal por defecto, cuántas «menos activadas», SKUs del top, días del detalle diario y **los canales de captación que ofrece el POS**. |
| Productos | segmento, línea y héroe de cada producto. |

Las **claves** no se renombran nunca (las guardan las ventas y las promociones): se cambia
el nombre o se desactiva, y lo histórico conserva su nombre. Los tipos marcados «lo usa el
cálculo» (sin promoción, muestra, negociado, promoción sin clasificar, descuento manual) se
renombran y se recolorean, pero no se borran ni se desactivan.

Sin la P40 todo sigue funcionando con los valores de siempre (los de la siembra).

## Gráficos interactivos

Los gráficos del cockpit y de las campañas son **Apache ECharts** (licencia Apache 2.0),
guardado en `assets/js/vendor/echarts.min.js`: no depende de ningún CDN. Todos tienen tooltip
con la cifra exacta, **Guardar imagen**, **Ver datos** (la tabla, accesible) y **Restaurar**;
las series de tiempo permiten **acercar** (rueda, arrastre o la barra de abajo) y cambiar entre
líneas y barras. Varios **profundizan al tocarlos**:

- Resumen: el costo por tipo y las burbujas llevan al Detallado filtrado por ese tipo.
- Detallado: el Pareto y el gráfico de uso contra profundidad filtran la tabla por tipo.
- Producto: el mapa de árbol entra de tipo → promoción → producto y la barra de abajo vuelve.
- Sell-out: tocar un canal, un segmento o una línea filtra todo el cockpit por él; el gráfico
  solar abre cada segmento en sus líneas.

Los gráficos nuevos: puente del margen (cascada volumen/mezcla/tasa/producto), venta bruta mes
a mes, costo por tipo, profundidad contra rentabilidad, Pareto de descuentos, uso contra
profundidad, mapa de la venta con descuento, héroes contra el resto, segmento → línea,
sell-out mes a mes; y en cada campaña, venta por canal contra meta, venta y facturas por día,
inversión por rubro y SKUs foco.

En código: `grafico()`, `grafico_ty_ly()`, `grafico_lineas_ty_ly()` y `grafico_cascada()` en
`includes/graficos.php`; el formato, los tooltips y el clic viven en `assets/js/nexo-graficos.js`.

El código puede subir antes que la migración: el POS sigue vendiendo igual y las pantallas
nuevas avisan que falta la actualización.

## Por qué hizo falta tocar la venta

Una promoción bajaba el precio de la línea y **no dejaba rastro**: `venta_detalles.descuento`
quedaba en 0 y el precio de lista se perdía. Sin eso no hay forma de saber cuánto costó una
promoción. Desde la P39, `registrarVentaPOS()` guarda en cada línea:

- `precio_lista` — el precio de catálogo al vender.
- `promocion_id` — la promoción que ganó (sin FK: borrar una promo vieja no puede fallar).

Los pedidos de la tienda online hacen lo mismo en `pedido_detalles` al crearse (ahí se
calcula su promoción) y lo pasan a la venta cuando se facturan en `modules/pos/pedidos.php`.

Y en la factura `ventas.descuento_motivo` — por qué el cajero hizo el descuento manual
(empleado, fidelidad, cortesía, liquidación, otro). El POS muestra el selector «Motivo» en
cuanto se escribe un descuento; un valor que no está en el catálogo cae en «manual».

**Ventas anteriores a la P39:** su descuento de promoción no se conoce. El cockpit las cuenta
con venta bruta = lo cobrado, las trata como «sin promoción» y **avisa en pantalla** qué
porcentaje de la venta está en esa situación. No se reconstruye nada con precios de hoy.

## Cómo se mide (includes/cockpit.php)

| Concepto | Definición |
|---|---|
| Venta bruta (GS) | precio de lista × cantidad. Una muestra cuenta a su precio de lista. |
| Descuento en caja | el descuento de la factura repartido entre sus líneas por su subtotal |
| Venta neta (NS) | subtotal de la línea − su parte del descuento en caja (sin ITBIS, igual que los reportes) |
| Costo (SMC) | `costo_unitario × cantidad`, congelado al vender |
| Tasa de descuento | (GS − NS) / GS |
| Pts perdidos | descuento del tipo / venta bruta **total**. Las filas suman la tasa de descuento total. |

Las ventas son las de `rep_estados_venta()` (facturadas, antes de devoluciones).

Cada línea cae en **un** tipo: muestra → familia de la promoción que ganó → precio negociado
(cotización) → motivo del descuento en caja → sin promoción.

### Efectos sobre el margen (TY contra LY)

Con m = margen / venta bruta, s = peso del tipo, d = tasa de descuento, k = costo / venta bruta:

- **Volumen** = (GS_ty − GS_ly) · s_ly · m_ly
- **Mezcla** = GS_ty · (s_ty − s_ly) · m_ly
- **Tasa de descuento** = −GS_t,ty · (d_ty − d_ly)
- **Mezcla de producto** = −GS_t,ty · (k_ty − k_ly)

Los cuatro suman **exactamente** la variación del margen del tipo, y las filas la del total.
`php pruebas/cockpit.php` lo comprueba (24 casos).

### Canales de la marca

`cockpit_canal_sql()` es la única regla: Web = tienda online; Social selling = Instagram,
WhatsApp, Facebook, TikTok; Mayoreo = facturas con crédito fiscal o gubernamentales;
Retail = el resto. Si la marca lo define distinto, se cambia ahí.

## Las cuatro pestañas del cockpit

1. **Resumen** — venta bruta/neta y margen con año anterior; tasa de descuento y % de venta en
   promoción mes a mes; composición (descuento directo vs. regalos); tabla por tipo con los
   efectos sobre el margen. Filtros: sucursal, tienda, canal, marca, segmento, **mismas
   tiendas** (sucursales que vendieron en los dos periodos) y fechas TY/LY libres.
2. **Detallado** — stacking: % de venta en promoción, descuentos promedio por factura (y su
   curva), distribución de la venta por número de descuentos; detalle promoción por promoción
   con activaciones y ticket medio. «30 menos activadas» incluye las promociones vigentes que
   **nadie usó**.
3. **Producto** — héroes contra el resto; árbol tipo → promoción → producto.
4. **Sell-out** — participación y crecimiento por sucursal, canal, tienda, segmento y línea.

Cada pestaña exporta su tabla a Excel y PDF.

## KPIs de campañas (includes/kpi_campanas.php)

Réplica del archivo «Holiday KPIs to track». Una campaña tiene fechas de este año, periodo
comparable del año anterior (se guarda: el Black Friday no cae el mismo día), sucursal y
tienda opcionales, meta total y por canal, SKUs foco, inversión por rubro (con tasa € opcional)
y los KPIs que no pasan por caja.

Calculado solo: venta neta, facturas, ticket medio, clientes nuevos (primera compra de la
historia dentro de la campaña) por canal; día por día contra el comparable; SKUs foco con
participación y diferencia en puntos; UPT, conversión (si se captura el tráfico), CPA, MER,
ROI incremental, AOV y conversión web (si se capturan las sesiones), clientes que repiten.

Capturado a mano: tráfico, NPS, EMV, impresiones, alcance, engagement, sesiones web,
entrenamiento (asistentes, encuesta, completitud, productividad), rotación del equipo.

**Excel de la marca**: el botón genera el libro con las hojas `SKUS REPORT`, `GLOBAL KPIs`,
`DAILY` (la hoja Black Friday), `INVESTMENT` y `KPIs`, con los encabezados en inglés del
archivo original, listo para enviar a la casa matriz.
