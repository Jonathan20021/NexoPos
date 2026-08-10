# Tiendas, costeo de importaciones y área de Dirección (P16)

Importers TyE, S. A. no vende «lo suyo»: distribuye varias marcas, cada una con su propia
cara ante el cliente, y las importa. Este paquete cubre las tres consecuencias de eso:

1. **Tiendas** — la factura de L'Occitane sale con el logo de L'Occitane.
2. **Liquidación de importaciones** — cuánto cuesta de verdad la mercancía puesta en almacén.
3. **Área de Dirección** — el tablero de la CEO: año contra año, costos y carga histórica.

Migración: `database/migracion_tiendas_p16.sql` (idempotente, MariaDB 10.4 y MySQL 8).

---

## 1. Tiendas (marcas comerciales)

**Una tienda es una identidad, no un local ni una razón social.**

| | Sucursal | Tienda |
|---|---|---|
| Responde a | ¿Dónde se vende? | ¿Con qué marca se vende? |
| Gobierna | Stock, caja, usuarios, permisos | Logo, colores, dirección impresa, política de devolución |
| Es un límite de seguridad | **Sí** | No: solo cambia el papel |

Son **independientes a propósito**. Un local puede atender dos marcas y una marca puede
estar en varios locales; por eso `tiendas` no cuelga de `sucursales` ni al revés.

### El emisor fiscal sigue siendo la empresa

Un solo RNC y una sola secuencia de NCF/e-CF. La tienda pone la marca en el papel, no en
la declaración. `tiendas.rnc` existe solo como referencia administrativa:
`tienda_marca()` **siempre** devuelve el RNC de la empresa.

Si algún día cada marca fuera una razón social distinta, habría que rehacer NCF, 606/607 y
la facturación electrónica ya certificada. No se hizo porque hoy no es el caso.

### El interruptor: mientras no haya tiendas, nada cambia

`tiendas_hay()` gobierna todo el módulo. Sin ninguna tienda creada, el POS no pide elegir
marca, el catálogo no se filtra y los comprobantes salen con los datos de la empresa —
exactamente igual que antes de la migración. Eso permite desplegar sin migrar el catálogo
de golpe. Por lo mismo, la migración **no crea** una tienda «Principal» de cortesía.

### Cómo viaja la marca hasta el papel

```
producto.tienda_id  →  tienda activa del POS  →  ventas.tienda_id (congelado)  →  ticket / factura
```

- **El catálogo del POS** muestra los artículos de la marca activa **más los que no tienen
  marca** (esos se venden desde cualquiera).
- **Una factura lleva un solo logo.** Si el carrito mezcla artículos de dos marcas,
  `registrarVentaPOS()` corta y dice cuáles son. No hay respuesta correcta para «¿qué logo
  imprimo?» y adivinar es peor que parar.
- **`ventas.tienda_id` queda congelado.** Reimprimir una factura de hace un año tiene que
  dar el mismo logo aunque el producto haya cambiado de marca desde entonces.
- Si nadie eligió marca (facturar una cotización, un pedido de la tienda en línea), se
  **deduce del artículo**. Así los llamadores del servidor no tienen que enterarse de que
  existen las tiendas.

### API (`includes/tiendas.php`)

| Función | Para qué |
|---|---|
| `tiendas_disponible()` | ¿Está aplicada la migración? Comprobar **antes** de cualquier consulta |
| `tiendas_hay()` | ¿Hay al menos una tienda activa? El interruptor del módulo |
| `tiendas_activas()` / `tiendas_opciones()` | Catálogo para selectores |
| `tienda_actual()` / `tienda_actual_id()` / `tienda_set($id)` | La marca activa del POS (vive en sesión) |
| `tienda_marca(?$id)` | **Lo que se imprime.** Arreglo completo con caída a los datos de la empresa |
| `tienda_marca_de_venta($venta)` | La marca de una venta ya emitida |
| `tienda_logo_url()` / `tienda_logo_datauri()` | Logo para el HTML / para el PDF |
| `tiendaScope()` / `selectTiendaFiltro()` / `tienda_chip()` | Filtros y presentación en listados |

`tienda_marca()` devuelve **siempre** un arreglo completo: cada campo que la tienda no
define cae al de la empresa. Por eso el código que imprime no pregunta «¿hay tienda?» en
cada línea.

### El comprobante

`modules/pos/ticket.php` produce dos documentos con la misma marca:

- **Ticket térmico** (80 mm): logo, línea del punto de venta, SKU bajo cada línea, desglose
  de impuestos (base gravada / exenta), **código de barras Code 128 del número de factura**
  —para que en un cambio la cajera lo lea en vez de teclearlo—, política de devoluciones y,
  al pie en letra pequeña, quién emite legalmente.
- **Factura A4 en PDF** (`?pdf=1`): la misma identidad con el color de la marca en la
  cabecera, los encabezados de la tabla y la línea del total.

`pdf_brand_header($titulo, $sub, $marca)` acepta la marca como tercer parámetro. **Sin ese
parámetro se comporta exactamente como antes**, así que los demás PDF del sistema no
cambiaron.

El desglose de impuestos reparte el descuento del encabezado proporcionalmente, igual que
lo hizo `registrarVentaPOS()` al calcular el ITBIS. Si no, la base impresa no cuadraría con
el impuesto cobrado.

---

## 2. Liquidación de importaciones

Un embarque no cuesta lo que dice la factura del proveedor. Cuesta el FOB **más** el flete,
el seguro, el arancel, la agencia aduanal y el transporte interno. Vender con el costo de la
factura es creer que se gana 40% cuando se gana 22%.

### Tres reglas que no se pueden romper

**1. Es un documento de COSTO, no de dinero.**
No registra la deuda al proveedor ni el pago de la agencia aduanal: de eso ya se encargan
Compras y Cuentas por Pagar. Si además lo hiciera aquí, los gastos del mes saldrían
duplicados y la utilidad hundida a la mitad. La UI lo dice en el modal de aplicar.

**2. El ITBIS de aduana NO es costo.**
Es un adelanto que se compensa contra el ITBIS cobrado en la venta. Meterlo al costo infla
el inventario un 18% y hunde el margen. Por eso cada gasto tiene `al_costo`, y el tipo
`itbis` nace con `al_costo = 0`.

**3. Los centavos tienen que cuadrar.**
La suma de lo prorrateado entre las líneas es **exactamente** el total de gastos. Repartir
con `round()` y ya deja diferencias de céntimos que, con 300 líneas, descuadran el
inventario: el resto se le suma a la línea de mayor base, que es donde menos distorsiona.

### Dos modos

| Modo | Cuándo | Qué hace al aplicar |
|---|---|---|
| `entrada` | El embarque todavía no está en el inventario | **Entra** la mercancía al costo real y fija el costo del catálogo |
| `recosteo` | La compra ya se registró y la mercancía ya entró; ahora llegó la factura de la agencia | **No mueve ni una unidad**: solo corrige el costo |

El modo `recosteo` copia las líneas de la compra elegida: es exactamente la mercancía que
entró, y volver a teclearla es la vía rápida a un costo que no cuadra con el inventario.

### Reparto de los gastos (`prorrateo`)

- `valor` — proporcional al FOB de cada línea. Es lo estándar y lo que usa la aduana.
- `cantidad` — por unidades.
- `peso` / `volumen` — lo correcto cuando el flete manda: repartir por valor el flete de
  mercancía barata y pesada le carga el costo al artículo caro y liviano, que no es quien
  llenó el contenedor.

Si la base elegida no sirve (todo peso 0), se cae a cantidad; si tampoco, a partes iguales.
**Nunca se deja de repartir**: los gastos existen aunque la base no sirva.

### Estados

```
borrador ──► transito ──► aplicada
    └────────────┴──────────┴──► anulada
```

Solo `borrador` y `transito` se pueden editar. Ninguno de los dos toca inventario ni costos:
la mercancía en tránsito se ve en el panel con su costo proyectado y nada más.

### Aplicar y anular

- **Aplicar** bloquea la fila con `FOR UPDATE` y relee el estado dentro de la transacción:
  dos personas pulsando el botón a la vez entrarían la mercancía dos veces. Recorre las
  líneas **en orden de `producto_id`** (regla de `docs/CONCURRENCIA.md`) y guarda el
  `costo_anterior` de cada producto.
- **Anular** deshace lo que hizo: saca del inventario lo que metió y devuelve cada costo.
  Si parte de esa mercancía ya se vendió, la salida dejaría el stock en negativo y la
  operación **se cae entera con un mensaje claro** — que es lo correcto: no se puede
  «des-importar» algo que ya salió por la puerta.
- Las ventas ya emitidas **conservan su costo** (`venta_detalles.costo_unitario` está
  congelado). Recostear el pasado reescribiría márgenes ya reportados.

### Lotes

Un embarque de mercancía regulada entra con su lote y su vencimiento
(`liquidacion_detalles.lote`). Si el producto tiene `controla_lote` y la línea no lo trae,
la mercancía cae en `SIN-LOTE` y los reportes sanitarios la señalan; el modal de aplicar
avisa antes. Ver `docs/SANIDAD-Y-AUDITORIAS.md`.

---

## 3. Área de Dirección

`modules/direccion/` + `includes/direccion.php`. Permisos: `direccion.ver` y
`direccion.importar` (separados porque importar sube —y revierte— un año de datos).

Todas las consultas respetan el **mismo criterio contable** que el resto de reportes; nada
erosiona más la confianza que dos pantallas dando cifras distintas:

```
Ingresos       = subtotal − descuento     (SIN ITBIS: se recauda para la DGII)
Costo          = ventas.costo_total
Utilidad bruta = ingresos − costo
```

### Panel (`index.php`)

Año contra año, mes contra mes, curva de 24 meses, ventas por marca, mercancía en tránsito
y últimas cargas históricas. Todo enlaza a la pantalla que lo explica: el panel resume, no
reemplaza.

### Año contra año (`comparativo.php`)

Matriz de doce meses con los dos años lado a lado, más el desglose por tienda, sucursal,
categoría y canal.

**La comparación es honesta con el año en curso:** si el año A es el actual, los totales de
B se cortan en la misma fecha. Comparar doce meses contra ocho siempre dice que vamos peor.
(El 29 de febrero se corta en el 28 cuando el año de comparación no es bisiesto: una fecha
inválida deja el comparativo en cero sin avisar.)

### Reportería de costos (`costos.php`)

Costo de la mercancía vendida, margen real, inventario a costo con sus días de cobertura,
recargo de importación del periodo, costo por tienda y por categoría, los 20 artículos que
más costo consumen y —la sección que justifica la pantalla— **lo que se está vendiendo por
debajo de su costo**, con enlace directo a repreciar.

Esa lista deja de ser teórica en cuanto se aplica una liquidación: el costo real sube y
quedan artículos cuyo precio de lista se quedó en el costo de la factura del proveedor.

### Carga histórica (`importar.php` + `includes/importador.php`)

Flujo de tres pasos, y el del medio es el importante:

1. **Subir** — CSV o Excel, hasta 25 MB. Resuelve el BOM de Excel, el separador `;` y el
   acentuado en Latin-1.
2. **Mapear y revisar** — el sistema dice qué va a entrar, qué va a rechazar y por qué.
   **No escribe nada todavía.**
3. **Cargar** — en tandas de 100 documentos.

**Cuatro reglas del importador:**

- **No mueve inventario.** El stock de hoy ya refleja la realidad del almacén.
- **No consume NCF.** Esos comprobantes ya se emitieron en el sistema viejo. Si el archivo
  trae el NCF, se guarda tal cual.
- **No genera movimientos de caja ni cuentas por cobrar.** Duplicaría el flujo de efectivo
  de un año ya cerrado. Sí alimenta ventas, márgenes y comparativos.
- **Todo lleva la marca de su lote** (`importacion_id`). Un archivo mal mapeado se revierte
  con un botón en vez de restaurando un respaldo completo.

**Detalle o resumen.** Si se mapea la columna «Producto», el archivo es *detallado*: los
importes son por línea y las filas con el mismo número de factura se agrupan. Si no, cada
fila es una factura completa.

**Reimportar el mismo archivo no duplica**: las facturas cuyo número o NCF ya existen se
omiten y se avisa en la revisión.

**Al revertir**, las ventas del lote se borran; los clientes que nacieron con él se borran
solo si no dejaron rastro en ninguna otra parte. Un cliente que ya compró de verdad se
conserva (se le quita la marca del lote): borrarlo se llevaría por delante ventas reales.

El archivo subido reposa en `storage/importaciones/`, **fuera del alcance web**
(`.htaccess` con `Require all denied`), y se borra solo a los 7 días: son datos de clientes.

---

## Tablas nuevas

| Tabla | Qué guarda |
|---|---|
| `tiendas` | La identidad comercial: nombre, logo, color, dirección impresa, política de devolución |
| `liquidaciones` | El embarque: modo, referencia, moneda, tasa, método de prorrateo, estado y totales |
| `liquidacion_detalles` | Una línea por artículo, con su FOB, su prorrateo, su costo final y el `costo_anterior` que permite anular |
| `liquidacion_gastos` | Flete, seguro, arancel, aduana… con `al_costo` para separar lo recuperable |
| `importaciones` | El lote de carga histórica: qué archivo, cuántas filas, qué entró y si se revirtió |

Columnas añadidas: `productos.tienda_id`, `ventas.tienda_id`, `compras.tienda_id`,
`ventas.importacion_id`, `clientes.importacion_id`.

Todas las claves foráneas a `tiendas` son `ON DELETE SET NULL`: borrar una marca nunca puede
borrar ventas. (La UI, además, desactiva en vez de borrar cuando hay comprobantes emitidos.)
`importacion_id` **no** tiene clave foránea a propósito: purgar lotes viejos no puede
arrastrar ventas ni quedar bloqueado por ellas.
