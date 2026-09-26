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

### Comprobar la instalación

**Configuración del cockpit → Estado** dice, parte por parte, qué está vivo en esta base y el
paso exacto para lo que falta (qué migración correr, o qué definir en `config.local.php`), y
qué se pierde mientras tanto. Si falta algo, todas las pestañas de Configuración lo avisan.
Sin la P39/P40 la pantalla muestra solo ese estado en vez de un mensaje sin salida.

Además mira las ventas de los últimos 7 días: si las columnas existen pero menos del 95% de
las líneas trae precio de lista, algún servidor o terminal sigue con el código anterior (las
ventas históricas importadas no cuentan: nunca traen precio de lista).

Una P40 a medias (una tabla que falta) ya no tumba nada: el cockpit, el POS y las promociones
siguen con los valores de fábrica hasta que se complete.

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

## Objetivos de descuento

La marca fija cuánto acepta descontar, y el cockpit lo vigila:

- **Configuración → Parámetros**: *tasa de descuento objetivo* y *venta en promoción
  objetivo* (máximos, en % de la venta bruta). Vacío = sin objetivo.
- **Configuración → Tipos**: *tope desc. %* por tipo (p. ej. GWP ≤ 15%).
- **Resumen**: las tarjetas muestran «obj. ≤ X%» en verde o rojo, los gráficos mes a mes
  llevan la línea del objetivo y la tabla por tipo marca en rojo el tipo que pasa su tope.
- **Lo más importante**, el **resumen por correo** y la tarjeta de hallazgos ponen primero
  lo que está fuera de objetivo (un tipo con menos de 0,5% de la venta no dispara aviso).
- **Notificación** a quien ve el cockpit cuando el mes en curso de toda la empresa pasa la
  tasa objetivo (desde el día 5; con dos o tres días la tasa baila demasiado).

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

## Reglas del rastro en la venta

- El motivo del descuento en caja se valida contra **todos** los motivos, activos o no: una
  venta hecha sin conexión conserva el que eligió el cajero aunque después se apague.
- El descuento global de una cotización facturada es **negociado**, no de caja.
- Un canal de venta desconocido cae en «Mostrador», como antes del cockpit.
- La venta solo necesita sus tres columnas de la P39 para registrar el rastro; los pedidos
  de la tienda en línea tienen su propia comprobación (instalaciones sin tienda no las tienen).
  Una vez confirmadas, se recuerda en la sesión: el POS no consulta el esquema en cada cobro.
- Editar una promoción cuya familia se apagó no la deja sin clasificar.
- La familia de una promoción se lee de la promoción, no se congela en la venta: reclasificar
  una promoción cambia también su pasado en el cockpit. Es a propósito (corregir una mala
  clasificación arregla toda su historia); si la casa matriz necesita cifras ya reportadas
  inmutables, exportarlas al cerrar el periodo.

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

## Las pestañas del cockpit

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
5. **Efectividad** — ¿funcionó cada promoción? (ver abajo).
6. **Simulador** — ¿qué pasaría con una promoción que aún no existe? (ver abajo).

Cada pestaña exporta su tabla a Excel y PDF.

## Efectividad de cada promoción

Para cada promoción usada en el periodo, sus productos en los días de vigencia contra el
mismo número de días **justo antes** (hasta 28): unidades por día antes → durante, aumento %,
**margen incremental** (margen por día durante − antes, por los días) y **retorno** (margen
incremental ÷ descuento regalado).

- Veredicto: *rentable* (vendió más y ganó margen), *ganó margen sin vender más*, *cara*
  (vendió más de 5% más pero perdió margen),
  *sin efecto* (no movió la venta) o *sin base*.
- Sin base cuando la promoción dura más de 90 días (el «antes» sería otra temporada) o cuando
  no hay ventas antes de su inicio. Se dice por qué en la tabla.
- Es una lectura, no un experimento: la base puede traer otra temporada u otra promoción.
- Además: quién compra en promoción (nuevos, recurrentes, sin identificar) y los clientes
  **dependientes** (2+ facturas y 80%+ de su venta con descuento), con enlace a su ficha.

## Simulador

Describe una promoción (a qué aplica: todo, categoría, marca, segmento, línea o un SKU;
porcentaje o monto fijo; días) y el cockpit calcula venta neta, margen y descuento regalado
con y sin ella, y el **punto de equilibrio**: cuánto deben subir las unidades para ganar el
mismo margen (margen de hoy ÷ margen con promoción a igual volumen − 1). Si el precio queda
bajo el costo, no hay equilibrio y lo dice.

- **Base**: lo vendido en los últimos N días (28 por defecto) con los filtros de arriba, al
  precio y costo reales. El precio con promoción se aplica sobre el precio de lista y nunca
  sube lo que ya se cobraba más barato.
- **Aumento esperado**: si no se escribe, se propone la mediana de las promociones de
  profundidad parecida (±5 pts) medidas en Efectividad durante el último año.
- El control deslizante recalcula al instante tarjetas, veredicto y la marca en el gráfico
  (margen según el aumento de unidades, contra la línea sin promoción).
- **Más suave o más fuerte**: la misma promoción a 10, 15, 20, 25, 30 y 40% (o a la mitad,
  ¾, 1¼, 1½ y el doble del monto), cada una con su equilibrio y el margen que dejaría con el
  aumento que dieron promociones de esa profundidad (o el elegido, si no hay historia). Se
  marca la de mejor margen; un clic la simula.
- **Crear esta promoción**: abre Marketing → Promociones con el formulario ya lleno (tipo,
  valor, alcance, objetivo y vigencia de los días simulados). Se revisa y se guarda allí.
  Solo para alcances que existen en promociones (todo, categoría, marca, producto) y con el
  cockpit en la moneda base.
- No incluye tráfico a otros productos ni la venta que solo se adelanta: orden de magnitud,
  no presupuesto. La cuenta vive en `cockpit_sim_calcular()` y la cubren las pruebas.

## Vistas guardadas

Botón **Vistas** en la cabecera: guarda la pestaña y los filtros actuales con un nombre
(tabla `cockpit_vistas`, P40), para uno o compartida con el equipo. Si las fechas son un
periodo rápido (este mes, mes pasado, año a la fecha…) se guarda el **periodo** y no las
fechas (`?periodo=mes_pasado`), así la vista siempre abre actualizada. Solo el autor puede
borrar la suya; con el mismo nombre, se reemplaza.

### Resumen por correo

Cada vista propia puede enviarse sola: **cada lunes** (la semana cerrada, lunes a domingo) o
**cada día 1** (el mes cerrado). El correo trae venta bruta, tasa de descuento, % de venta en
promoción, venta neta y margen contra el año anterior, lo más importante que encontró el
cockpit y un botón que abre la vista en ese periodo. «Ver correo» muestra cómo llegaría.

- Respeta los filtros de la vista (sucursal, canal, marca, segmento, línea, moneda, mismas
  tiendas) y **se calcula como el dueño de la vista**: su sucursal y sus permisos, nunca los
  de quien tenga la sesión abierta cuando corre el motor (`cockpit_como_usuario()`).
- Un periodo se manda una sola vez (`cockpit_vistas.ultimo_periodo`, reclamado antes de
  enviar). Si el envío falla, se libera y se reintenta en la siguiente pasada. Al activarlo
  no se manda el periodo ya cerrado: el primero llega en el próximo cierre.
- Corre sin cron, con el mismo enganche que las notificaciones, como máximo una vez por hora
  (3 vistas por pasada); y también desde `modules/marketing/cron.php` si hay cron real.
- Si un envío falla, esa vista espera 6 horas antes de reintentar (`ultimo_intento`): una
  dirección rechazada no acapara las pasadas de las demás.
- Seguridad: el préstamo de sesión se deshace también ante un error fatal (tiempo o memoria
  agotados) con una función de cierre, que PHP corre antes de guardar la sesión.
- Necesita el correo configurado (`RESEND_API_KEY`, `MAIL_FROM`); sin él no se envía nada.
  Deja de enviarse si el dueño se desactiva o pierde `cockpit.ver`.

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


## Lectura rápida, monedas y comparaciones

- **Lo más importante** (arriba del Resumen): frases generadas con los datos del periodo —
  cuánto cambió la tasa de descuento y el margen, qué tipo cuesta más, cuál descuenta más
  hondo que el año pasado, qué movió el margen y qué promociones vigentes nadie usó—, cada
  una con su enlace al detalle. `cockpit_hallazgos()`; no consulta nada nuevo.
- **Moneda de reporte**: el cockpit se puede ver en USD, EUR u otra moneda. Siempre a una
  **tasa fija** (Configuración → Parámetros → «Tasas fijas de reporte», `USD=59.50`), nunca
  a la del día: si no hay tasa fija se ofrece la del catálogo de monedas avisando que es la
  del día. Los porcentajes no cambian con la moneda.
- **Comparar con**: mismas fechas del año anterior o **mismo día de la semana** (52 semanas
  antes: el Black Friday contra el Black Friday). Si alguien fija a mano las fechas del año
  anterior se respetan; si no, siguen a las de este año.
- **Copiar enlace**: los filtros viven en la URL; el botón copia la vista exacta.
- En el teléfono los filtros se pliegan.

## Campañas: proyección, comparativo y avisos

- **Proyección al cierre** (campañas en curso): lo vendido hasta ayer escalado con la **forma
  del año anterior** (qué parte de su venta llevaba a esa altura), no con una línea recta.
  Sin año anterior útil, promedio diario. Barra contra la meta.
- **Listado = comparativo**: venta neta, crecimiento, % de meta y MER de cada campaña, y un
  gráfico con todas lado a lado.
- **Notificaciones** (campana del sistema): promoción vigente hace una semana que nadie usó
  (a quien puede editar promociones) y campaña en curso que cerraría por debajo del 85% de su
  meta (a quien ve campañas).
- El formulario de campaña tiene «Alinear por día de la semana» para el periodo comparable.
- **Promociones de la campaña**: en el tablero, las promociones usadas dentro de sus fechas y
  su alcance (sucursal/tienda), con venta, descuento, peso en el descuento y el veredicto de
  Efectividad; enlace al cockpit con esas fechas. También sale en el Excel (hoja PROMOTIONS).

Rendimiento: la efectividad lee las ventas en una sola pasada producto × día para todas las
ventanas (antes una consulta por promoción): 1,3 s → 0,4 s con 60.000 ventas.
