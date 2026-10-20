# Cuentas por pagar, monedas y cotizaciones

Tres piezas que se construyeron juntas porque se necesitan entre ellas: un
importador compra en dólares a crédito y cotiza en dólares antes de vender.

Migración: [`migracion_cxp_monedas_cotizaciones_p11.sql`](../database/migracion_cxp_monedas_cotizaciones_p11.sql)
Motores: [`includes/cxp.php`](../includes/cxp.php) · [`includes/monedas.php`](../includes/monedas.php) · [`includes/cotizaciones.php`](../includes/cotizaciones.php)

---

## 1. Cuentas por pagar

Antes una compra a crédito solo podía estar **pagada o no pagada** (una fecha).
No se podía abonar RD$50.000 de una factura de RD$200.000, que es como se paga
de verdad a un suplidor.

Ahora cada compra lleva su `saldo` y cada proveedor su `balance`, exactamente
igual que ya hacían las ventas y los clientes. La pantalla está en
**Inventario → Cuentas por Pagar**, con antigüedad de la deuda por tramos.

### El defecto que se corrigió de paso

Toda compra registraba un gasto de efectivo el día de la compra, **aunque fuera
a 90 días**. El flujo de caja mostraba salidas que no habían ocurrido.

```
ANTES:  compra a crédito → sale el dinero hoy (falso)
AHORA:  compra a crédito → nace la deuda
        pago             → sale el dinero (verdad)
```

### Dónde entra cada movimiento

| Movimiento | ¿Gasto operativo? | ¿Flujo de caja? | Por qué |
|---|---|---|---|
| Compra al contado | no | sí | Es inventario, ya pesa en el costo de ventas |
| Compra a crédito | no | no | Todavía no salió dinero |
| Pago a proveedor | **no** | sí | Es la misma mercancía: contarla aquí sería duplicarla |
| Diferencia cambiaria | **sí** | no | Gasto financiero real; el dinero ya salió con el pago |

Esas cuatro filas son las que hacen `rep_where_gastos()` y `rep_where_flujo()`.
Si añades un movimiento nuevo, decide en qué fila cae **antes** de escribirlo.

---

## 2. Monedas

**La contabilidad sigue en pesos.** Todos los importes de `transacciones`,
reportes, DGII e IT-1 son RD$ igual que antes. Ningún reporte existente cambia
de resultado.

Lo que se añade es que los documentos guardan **las dos caras**: el importe en su
moneda y el equivalente en pesos a la tasa de ese día. Convertir al vuelo en los
reportes habría hecho que el pasado cambiara cada vez que se mueve el dólar.

La tasa se teclea a mano en **Administración → Monedas y tasa**. No hay API
gratuita del Banco Central que se pueda consultar sin permiso, y una tasa sacada
de una web cualquiera puede descuadrar una contabilidad.

### La diferencia cambiaria, que es donde está el truco

Una deuda en dólares **no es una deuda en pesos congelada**:

```
Debes         US$600  anotados a RD$58  →  RD$34.800 en libros
Pagas         US$600  con el dólar a 62 →  RD$37.200 salen del banco
Deuda saldada: completa. No pagaste de más.
Los RD$2.400 de diferencia son una PÉRDIDA CAMBIARIA.
```

Por eso `cxp_registrarPago()` lleva tres cifras que solo coinciden cuando no hay
moneda extranjera:

- `reduccion` — cuánto baja la deuda en libros (a la tasa de la compra)
- `salida` — cuánto dinero sale del banco (a la tasa de hoy)
- `diferencia` = salida − reduccion

La primera versión de este código rechazaba ese pago con «el pago supera lo que
se le debe». Lo encontró la prueba del ciclo completo, no la revisión a ojo.

---

### 2b. Precios de catálogo en otra moneda (P39)

Un producto puede tener su precio en dólares: `productos.moneda_id`. Vacío = pesos,
que es como se comporta el resto del catálogo y como se comportaba **todo** antes de
la P39 — la columna se añadió sin tocar ni un precio.

**La conversión ocurre al vender, y una sola vez.** `mon_precio_base()` pasa el precio
a pesos con la tasa de venta, y a partir de ahí ya no hay monedas: lo que se calcula,
se imprime y se guarda en `venta_detalles` son pesos **congelados**. Reimprimir esa
factura dentro de un año da el mismo número aunque el dólar se haya ido al doble.

Convertir *antes* de aplicar promociones no es un detalle de orden: un descuento de
«RD$100 menos» sobre un precio de 29 dólares sin convertir regalaría la mercancía.

Quien muestre un precio de catálogo pasa por `mon_precio_base()` / `mon_costo_base()`.
Para sumar dentro de SQL —el inventario valorizado agrega sobre miles de filas— están
`mon_sql_join()` y `mon_sql_precio()`, que dejan la conversión dentro de la consulta
en vez de convertirla en un bucle de PHP.

**Dónde se aplica.** Además del POS, la cotización y el inventario valorizado, pasan
por la conversión: la tienda en línea (listado y checkout) y la factura de sus
pedidos, el buscador global, las etiquetas de precio, el escáner de almacén (ficha y
costo del kárdex), stock, conteos, el simulador del cockpit, la pantalla de
liquidación y los informes de dirección, costos, balance, ejecutivo, ajustes,
existencias, productos, rentabilidad, reposición, sucursales y traslados. Un
`SELECT p.precio_venta` sin convertir en una pantalla nueva es el mismo defecto:
enseña «RD$ 29» de algo que cuesta 29 dólares.

**El camino de vuelta.** Compras y liquidaciones calculan el costo en pesos y lo
escriben en el catálogo. `mon_costo_a_producto()` lo devuelve a la moneda del
producto antes de guardarlo (con la tasa del documento si es la misma moneda); sin
eso, un artículo en dólares quedaba con un costo de 690 «dólares» y margen negativo.

**El importador** pregunta en qué moneda vienen los precios del archivo (o los lee de
una columna «Moneda»), y en *Importar → ¿Cargaste precios en otra moneda?* se corrige
de una vez lo ya cargado, por lote de importación o por marca.

### 2c. Las DOS tasas, y por qué son dos

| | Qué es | Quién la mueve |
|---|---|---|
| `monedas.tasa` | La **tasa de venta**. La que cobra el POS y proponen los documentos. | Una persona, a mano |
| `monedas.tasa_mercado` | La **referencia** descargada. No cobra nada. | El sistema, cada `MON_REFRESCO_HORAS` |

Son dos a propósito. El precio de góndola no puede cambiar cada mañana porque el dólar
se movió diez centavos: una etiqueta impresa dejaría de cuadrar con la caja el mismo
día, y el cliente que vuelve mañana vería otro precio. Así que **lo automático avisa,
no cobra**.

El riesgo de una tasa fija no es que esté mal: es que se olvide. Por eso
`tasa_revisada_at` guarda cuándo la miró una persona por última vez —hay un botón
«Está bien» que la sella sin cambiarla— y la campana avisa de dos cosas distintas:
que el mercado se separó más de `MON_ALERTA_PCT`, y que nadie la revisa hace
`MON_ALERTA_DIAS` días. Solo avisa si hay productos con el precio en esa moneda; sin
catálogo en dólares la tasa de venta no cobra nada y el aviso sería ruido.

### 2d. De dónde sale la tasa de mercado

**No hay API oficial gratuita del Banco Central.** El BCRD publica en PDF y la DGII
también. Lo único que sirve BCRD en JSON es un proveedor comercial con cuota. Así que
las fuentes de `mon_fuentes()` son de **mercado**, no oficiales: para una referencia
sirven, para una declaración no.

Van en cascada y la primera que responda algo creíble gana. «Creíble» es literal: si
la tasa se aleja más de `MON_TOPE_SALTO_PCT` de la última conocida, se descarta y se
prueba la siguiente — una fuente puede cambiar de formato sin avisar, y una tasa
absurda guardada como referencia dispararía la alerta todos los días.

Se descarga con el mismo enganche que las notificaciones y el marketing
(`mon_tick_si_toca()` en la barra superior), sin cron. Timeout corto: detrás de esa
llamada hay alguien esperando a que cargue una página.

Entre dos intentos pasan al menos `MON_REINTENTO_MIN` minutos, acierten o fallen
(turno atómico en `sistema_estado`, como el barrido de notificaciones). Antes, con las
fuentes caídas, cada carga de página volvía a probarlas y esperaba sus timeouts.
`modules/marketing/cron.php` también llama al tick, para quien tenga cron.

### 2e. Tasa de venta automática (P41)

`database/migracion_tasa_automatica_p41.sql` añade `monedas.venta_auto` y
`monedas.venta_margen`, y deja el **dólar en automático**. Con `venta_auto = 1`, cada
descarga pone la tasa de venta en `mercado × (1 + venta_margen / 100)`, redondeada a
2 decimales (`mon_aplicar_venta_auto()`), y la anota en `monedas_historial` como
`automatica`. Se descarga cada `MON_REFRESCO_HORAS_AUTO` horas en vez de cada 12.

Es lo contrario de la regla de la sección 2c, y por eso es por moneda y reversible:
en *Administración → Monedas* se elige «Que se actualice sola con el mercado» o
«Poner la tasa a mano». El margen (−20 % a +20 %) sirve para cobrar el dólar algo por
encima del mercado y cubrir la reposición.

En automático no hay aviso de desfase ni de tasa rancia —la venta sigue al mercado por
definición—; en su lugar la campana avisa si en `MON_AUTO_ATRASO_HORAS` horas ninguna
fuente ha respondido, porque entonces la caja sigue cobrando con la última tasa.
Las ventas ya hechas no cambian: cada una congeló sus pesos.

### 2f. Productos en las dos monedas

La lista de productos enseña compra y venta en pesos y en la moneda de referencia
(`mon_referencia()`: el dólar si está activo). El importe que se cargó es el exacto;
el otro lleva «≈» porque sale de la tasa del día (`mon_dos_monedas()`). El formulario
enseña la equivalencia mientras se escribe, y el Excel exporta las dos monedas además
de «Precio compra / Precio venta / Moneda» tal como se cargaron, que es lo que el
importador vuelve a leer.

Al editar un producto, **cambiar la moneda no cambia los números**: si se cargó en pesos
por error y los números ya eran dólares, basta con cambiar la moneda. Si de verdad
estaban en la otra moneda, el formulario ofrece «Convertir RD$ → US$» con la tasa del
día.

### 2g. Cobrar en dólares en el POS

En efectivo, el cajero elige si recibe **RD$ o US$**. En dólares, el POS convierte a
la tasa de venta, da el cambio en pesos y avisa si falta. La venta, la caja y la DGII
siguen en pesos; el pago queda anotado en `ventas.notas` («Pago en efectivo: US$ 50.00
a tasa 60.00 = RD$ 3,000.00; cambio RD$ 1,562.76.») y se imprime en el ticket, para
que al cuadrar se sepa por qué hay dólares en el cajón. La conversión la hace el
servidor con su tasa (`pos_nota_pago_moneda()`), no el navegador.

### Pruebas

`php pruebas/monedas.php` cubre la ida y la vuelta de los precios, las dos monedas de
la lista, `mon_resolver()`, la tasa automática y la nota del pago en dólares. Escribe
dentro de una transacción que deshace al terminar.

## 3. Cotizaciones

Documento previo a la factura, con vigencia, PDF de marca y envío por correo.
**Ventas → Cotizaciones**.

### Dos reglas

**Una cotización no toca nada.** No mueve stock, no genera asientos, no reserva
mercancía. Es una oferta.

**El precio cotizado se respeta.** Si el precio de lista sube entre la cotización
y la factura, el cliente paga lo que se le prometió.

### Cómo se factura

No duplica ni una línea de la lógica de ventas: arma el carrito y llama a
`registrarVentaPOS()`, la misma función del POS. Así una factura nacida de una
cotización es idéntica a cualquier otra: NCF, kardex, caja, comisiones.

Para respetar el precio pactado se añadió `$ctx['precios_pactados']` a esa
función. **Solo lo activa código del servidor** que leyó los precios de la
cotización guardada en la base; el navegador nunca puede fijar un precio. Sin esa
marca, `registrarVentaPOS()` sigue recalculando todo contra el catálogo como
siempre.

Una línea escrita a mano (sin producto del catálogo) no se puede facturar: el
sistema lo dice al intentarlo, en vez de inventar un producto.

### PDF

Mismo motor (Dompdf) y misma hoja de estilos que la factura, vía
`pdf_brand_header()` y `pdf_css()`. Se añadió `pdf_bytes()` para poder adjuntar
el **mismo** documento a un correo: `pdf_render()` termina con `exit` y no
servía. Separar el armado del envío evita tener dos PDF distintos del mismo papel.

---

## Qué mirar si algo no cuadra

- **La deuda de un proveedor no coincide con sus facturas** →
  `cxp_recalcularBalance($proveedorId)` la recalcula desde las compras.
- **El flujo de caja muestra de más** → revisa que el movimiento nuevo esté en
  la tabla de arriba y en `rep_where_flujo()`.
- **Una compra a crédito no aparece en Cuentas por Pagar** → tiene que tener
  `forma_pago = 4` (compra a crédito, código DGII) y sin fecha de pago.
- **Una cotización en dólares factura por un importe raro** → se convierte con la
  tasa **de la cotización**, no la de hoy: es el precio que se prometió.
