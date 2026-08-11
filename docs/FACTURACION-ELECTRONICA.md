# Facturación Electrónica (e-CF) — Integración con LUGANIS

La DGII no recibe el comprobante electrónico directamente. Se envía a **LUGANIS
CORP**, un proveedor certificado que arma el XML, lo firma con certificado
digital, lo remite a la DGII y al comprador, y devuelve un acuse.

Y el punto que define todo el diseño: **no se envía JSON con la factura**. Se
envía un **archivo TXT delimitado por pipes, codificado en Base64, dentro de un
JSON de dos campos**. La API es diminuta; el trabajo está en construir la trama.

## Estado

| Pieza | Estado |
|---|---|
| Tipos 31, 32, 33, 34, 44 y 45 | Implementados y verificados contra los ejemplos oficiales |
| Tipos 41, 43, 46 y 47 | **No implementados** — no son comprobantes de venta (compras, gastos, exportaciones, pagos al exterior). Fixtures guardados |
| Envío en línea (API REST) | Implementado |
| Envío en lote (S3) | **No implementado** |
| Emisión al vender, facturar un pedido y devolver | Conectada |
| Cola automática (tick + cron) y alertas | Implementada |
| **Probado contra el ambiente real** | **Sí** — 32 y 34 aceptados, firmados y con QR. 31, 44 y 45 se detienen en el `145`: falta el rango autorizado. Ver «Certificación: dónde está el muro» |

**El e-CF convive con el NCF preimpreso, no lo reemplaza.** Con
`ecf_config.activo = 0` —el valor de fábrica— el POS factura exactamente como
siempre. El corte a E31/E32 es una decisión fiscal con fecha, que se toma cuando
la certificación esté aprobada y los rangos autorizados cargados: se hace
encendiendo ese interruptor y nada más.

---

## Documentación de origen

Los cinco documentos del proveedor viven fuera del repositorio:

| Documento | Qué aporta |
|---|---|
| **LUG-OPE-MA-001** Manual de Integración v03 | Endpoints, autenticación, canal en lote, límites |
| **LUG-OPE-PT-001** Estructura Archivo TXT v03 (136 pp) | Ficha de cada campo con sus reglas condicionales |
| **LUG-OPE-MA-006** Datos TXT para Desarrolladores v1 | La tabla de posiciones por tipo — la de uso diario |
| **LUG-OPE-MA-002** Catálogo de Tablas v4 | Las 18 tablas de códigos |
| **Ejemplos básicos_Archivos TXT.xlsx** | Tramas reales por casuística. **El más útil de todos** |

Las tramas del Excel están extraídas en
[`database/ecf_ejemplos/`](../database/ecf_ejemplos/) y son la base de las pruebas.

---

## Archivos

| Archivo | Responsabilidad |
|---|---|
| [`includes/ecf_catalogos.php`](../includes/ecf_catalogos.php) | Las 18 tablas oficiales y el formato de valores |
| [`includes/ecf_provincias.php`](../includes/ecf_provincias.php) | Tabla 8: 582 códigos territoriales |
| [`includes/ecf_trama.php`](../includes/ecf_trama.php) | Layouts por tipo, construcción y validación de la trama |
| [`includes/ecf_api.php`](../includes/ecf_api.php) | Cliente HTTP: login, envío, consulta, descargas |
| [`includes/ecf.php`](../includes/ecf.php) | Traducción venta/devolución → e-CF, emisión y cola |
| [`modules/pos/nota_credito.php`](../modules/pos/nota_credito.php) | Comprobante de la devolución: térmico y PDF, con el QR |
| [`modules/finanzas/ecf.php`](../modules/finanzas/ecf.php) | Pantalla: configuración, diagnóstico y consola |
| [`modules/finanzas/ecf_cron.php`](../modules/finanzas/ecf_cron.php) | Punto de entrada del cron para la cola |
| [`database/migracion_ecf_p15.sql`](../database/migracion_ecf_p15.sql) | Esquema |

---

## Pruebas

Dos suites, ninguna necesita credenciales ni red:

```bash
php database/ecf_ejemplos/verificar.php
```

Toma cada trama oficial, la parsea con nuestro layout, la vuelve a generar y
compara carácter por carácter. **127 de 127** de los tipos implementados salen
idénticas (los 20 restantes son de tipos que no se implementan). Detecta el error más caro de todos —un campo corrido de posición—
que si no solo aparecería cuando la DGII rechace el comprobante.

```bash
php database/ecf_ejemplos/probar_reglas.php
```

**75 pruebas** del criterio que los ejemplos no cubren: el umbral de
RD$250,000, la obligatoriedad del comprador en el crédito fiscal, la tolerancia
de ±1, el redondeo, los campos repetibles, el saneado de caracteres que
romperían la trama y la generación del QR desde el timbre de la DGII.

Correr ambas después de tocar cualquier layout.

```bash
mysql -u root -e "DROP DATABASE IF EXISTS inventario_pos_ecftest; CREATE DATABASE inventario_pos_ecftest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysqldump -u root --single-transaction inventario_pos | mysql -u root inventario_pos_ecftest
php database/ecf_ejemplos/probar_pos.php
```

**79 pruebas** del enganche al POS, sobre un clon desechable: registra ventas de
verdad y comprueba que apagado nada cambia, que encendido toma E32/E31 y crea su
documento, y que **con el proveedor caído la venta se completa igual**. También
cubre el QR (que no se pida antes de tiempo y se sirva de la caché) y la cola
(que el tick no gaste el turno en balde, que dos ticks seguidos no procesen dos
veces y que un comprobante atascado genere alerta), y las notas de crédito
(que declaren la base y no el reembolso, y que distingan anulación de parcial). Se niega a correr si la base no
termina en `_ecftest`.

```bash
php database/ecf_ejemplos/probar_cotizador.php     # sobre el mismo clon
```

**38 pruebas** del cotizador: descuento por línea y global, facturación parcial
que no excede lo cotizado, conceptos libres que se facturan como servicio sin
tocar inventario, precio pactado respetado, y que el total cotizado y el
facturado no puedan separarse.

```bash
php database/ecf_ejemplos/probar_proveedor.php     # necesita red y credenciales
```

**14 pasos contra el ambiente REAL de LUGANIS**: login → venta → emisión →
trackId → estado aceptado → timbre → QR → PDF y XML firmados → ticket. Es la
única suite que sale a la red; si no hay credenciales, sale sin ejecutar nada.

---

## Lo que solo se supo probando contra el ambiente real

El manual deja fuera los cuerpos de respuesta (están como capturas) y el catálogo
de errores. Todo lo de esta sección se obtuvo llamando a la API de verdad, y
varias cosas **contradicen** al documento.

### Las dos cuentas hacen lo mismo

LUGANIS entrega dos usuarios, «envío» y «consulta». Se sondearon los cuatro
servicios con ambas y **ninguna recibió 403**: las dos pueden enviar, consultar y
descargar. Basta con una sesión. Se usa la de envío porque su token dura más.

### El token NO dura 3600 segundos

| Cuenta | Vigencia real |
|---|---|
| envío | **2400 s** (40 min) |
| consulta | **600 s** (10 min) |

Viene en `data.token.expiresIn`. El manual dice 3600 en ambos casos. Darlo por
hecho significa seguir usando un token muerto y comer 401 a media jornada, así
que se lee siempre de la respuesta y el margen de refresco se calcula sobre la
vigencia de *ese* token, no sobre una constante.

### La IP pública es obligatoria de verdad

Sin `providerIpAddress` el login falla entero con **1003 «Falta la información de
geolocalización»**. No comprueba que sea la IP real —acepta cualquier IPv4 bien
formada—, pero el campo tiene que venir. `ecfIpPublica()` garantiza que nunca
vaya vacío.

### El login va anidado en `deviceInfo`

Confirmado empíricamente: con los campos planos que describe la *tabla* de
parámetros la API responde **1001 «Faltan datos obligatorios»**; anidados como
en el *ejemplo* curl, responde 200. El ejemplo tenía razón.

### Códigos de respuesta observados

| Código | Significado |
|---|---|
| `0` | Transacción exitosa |
| `0002` | Formato de `filename` inválido — se espera `RncE99Secuencial.ext` |
| `0006` | El ticket no se encontró en los registros |
| `0010` | El campo `filecontent` es obligatorio |
| `1001` | Faltan datos obligatorios |
| `1003` | Falta la información de geolocalización |
| `1006` | El RNC del nombre del archivo no corresponde al de la compañía en sesión |
| `2007` | El documento de identidad indicado es de otra empresa |

Estados del documento observados en la consulta del trackId: **`Pendiente`** →
**`Aceptado`** (y `ACCEPTED` en el servicio STATUS).

Están en `ecfCodigosRespuesta()`. La lista no es completa —irá creciendo con lo
que aparezca en `ecf_log`— y nada del flujo depende de que un código esté ahí.

### Un error puede venir con HTTP 200

Los códigos `0006`, `1006` y `2007` llegan con **HTTP 200**. El sobre
`status.code` vale «0» aunque el documento haya sido rechazado: ese cero dice que
la *consulta* salió bien, no el comprobante. Por eso `ecfInterpretarEstado()` lee
`data.status` (`Aceptado` / `ACCEPTED`) y no el código de arriba — leer el
equivocado sería dar por bueno lo que la DGII rechazó.

### El acuse tarda: 2-4 s una venta, hasta un minuto una nota de crédito

El envío es inmediato, pero el documento **no queda «Aceptado» al instante**:
pasa por un estado intermedio `Pendiente`. Medido contra el ambiente real:

| Documento | Tiempo hasta la firma |
|---|---|
| Venta (E31/E32) | 2-4 s |
| Nota de crédito (E34) | hasta ~54 s |

La nota de crédito tarda mucho más porque el proveedor no solo la valida a ella:
además tiene que localizar y comprobar el e-CF al que hace referencia, y eso pasa
por la DGII.

Eso importa porque el QR solo existe una vez firmado. Consultar el estado justo
después del envío llega demasiado pronto y el ticket sale sin QR — que fue
exactamente lo que ocurrió en la primera prueba en producción.

Por eso `ecfResolverComprobante()` insiste antes de imprimir, con una escalera
distinta según el documento:

- `ECF_ESPERAS_ACUSE` — venta: inmediato, 1,5 s, 2 s y 3 s (**hasta 6,5 s**).
  Coste medido: ~3,7 s, de los que 2,2 s son del proveedor.
- `ECF_ESPERAS_ACUSE_NC` — nota de crédito: dos escalones más, **hasta 14 s**.

La escalera de la nota de crédito se corta a los 14 s **a propósito**, aun
sabiendo que a veces no llegará. Esperar el minuto entero dejaría al cajero
mirando una pantalla congelada con el cliente delante, y la devolución ya está
hecha y transmitida: lo único pendiente es el acuse. Lo que cubre ese hueco es la
cadencia de reconsulta de la cola, que a partir de ahí repregunta cada 15 s.

Si en algún momento pesa más la velocidad en el mostrador que la impresión
completa, basta dejar `ECF_ESPERAS_ACUSE = [0]`: el ticket sale sin QR y la cola
lo completa para la reimpresión.

### El QR no es una imagen: es la URL del timbre

`/client-service/download/QR` devuelve JSON con la URL de consulta de la DGII:

```
https://fc.dgii.gov.do/testecf/consultatimbrefc
   ?RncEmisor=102616541&ENCF=E320000091646
   &MontoTotal=177.00&CodigoSeguridad=eat/8K
```

El `CodigoSeguridad` son los **primeros 6 caracteres de la firma digital** del
XML, por eso la URL tiene que venir del proveedor. La imagen la dibuja
`ecfQrDesdeUrl()` con `chillerlan/php-qrcode`, así se imprime al tamaño que
convenga en un ticket térmico y no depende de la red.

### Las descargas son JSON, no binario

Los cuatro servicios (`QR`, `PDF`, `XML`, `STATUS`) responden
`application/json`. El archivo viene dentro, en `data.detail.base64FileContent`,
con su nombre en `data.detail.filename`. Pedirlos como binario —como se hacía al
principio— devuelve el JSON crudo.

### La cuenta está atada a un RNC

Las credenciales de prueba pertenecen a **RNC 102616541 · L OCCITANE EN PROVENCE
(IMPORTERS)**. Enviar con otro RNC en el nombre del archivo da `1006`. Para
certificar hay que emitir con ese RNC.

---

## Decisiones que no son obvias

### DERE va antes que FPAG

El índice de secciones de MA-006 y PT-001 las enumera «… ITEM, FPAG, DERE», pero
**las 17 tramas de ejemplo que usan DERE la escriben justo después de los ITEM**,
dejando FPAG al final. Sin una sola excepción, en los tipos 31, 32, 33, 34, 41,
44, 45 y 46. Se sigue el ejemplo: es una trama que el proveedor entrega para
copiar, mientras que el índice es una tabla descriptiva. Está pendiente de
confirmar; cambiarlo es intercambiar dos claves en `ecfLayout()`.

### Un layout por tipo, no uno parametrizado

La sección ITEM no tiene las mismas posiciones entre tipos. El 31 lleva los
campos de retención en las posiciones 4-6 y no lleva los de minería; el 32 es al
revés; el 33 y el 34 llevan ambos (33 campos por línea). Se ve en los ejemplos:
`ITEM|1||1||||PRODUCTO…` (31) frente a `ITEM|1||1|PRODUCTO…` (32).

### El descuento se prorratea a la línea

NexoPOS guarda el descuento en la cabecera de la venta. El e-CF tiene sección
para descuentos globales (DERE), pero exige declarar a qué tasa de ITBIS afecta
(Tabla 17), y una venta con productos al 18%, al 16% y exentos no cabe en un
solo indicador. Por eso el descuento se reparte por línea igual que ya se hacía
con el ITBIS, y **el descuento de la última línea absorbe el redondeo** para que
la suma cuadre al centavo con la venta registrada.

### Exento y 0% no son lo mismo

`productos.itbis_aplica` es booleano y no alcanza: la Tabla 13 distingue
*no facturable*, *18%*, *16%*, *0%* y *exento*. El 0% es una operación gravada a
tasa cero; el exento está fuera del impuesto. La migración siembra el indicador
desde `itbis_aplica` (lo que llevaba ITBIS queda en 18%, el resto en exento) y a
partir de ahí **hay que revisar el catálogo producto por producto**.

### El indicador se congela en la línea de venta

`venta_detalles.ecf_*` guarda la tasa, la unidad y el bien/servicio del momento
de la venta. Derivarlos por JOIN a `productos` reescribiría el pasado cada vez
que un producto cambie de tasa.

### El cliente busca los valores por varios nombres

Se escribió así porque el manual no publica los nombres de los campos JSON, y se
mantiene aunque ya se conozcan: `ecfBuscarValor()` localiza el token o el
trackId por cualquiera de sus nombres plausibles, en vez de depender de una ruta
fija como `data.token.accessToken`. Si el proveedor mueve un campo de sitio entre
ambientes o versiones, sigue funcionando.

Y **siempre** se guarda la respuesta cruda en `ecf_log`: es lo que permitió
descubrir todo lo de la sección anterior, y lo que permitirá diagnosticar lo que
venga.

`ecfInterpretarEstado()` sí es determinista: lee `data.status` («Aceptado» /
«ACCEPTED»). Solo cae a heurística de texto si ese campo faltara, y ante la duda
deja «enviado» para volver a consultar. Dar por bueno un comprobante que la DGII
rechazó sería el peor error posible.

### Un documento inválido no quema secuencia… salvo que ya esté quemada

`ecfEmitir()` construye y valida **antes** de tomar el e-NCF, porque recuperar un
número gastado obliga a reportarlo como anulado ante la DGII.

Pero cuando la emisión viene del POS el número ya se tomó dentro de la
transacción de la venta. Ahí no hay vuelta atrás, y abandonar sería peor:
quedaría una secuencia autorizada sin ningún documento que la respalde,
invisible en la bandeja e imposible de cuadrar. Por eso, si el documento no
valida y el e-NCF **ya está asignado**, se registra igual en estado `error` con
el detalle de qué le falta (`ecfRegistrarInvalido()`), para que alguien lo
corrija y lo reenvíe o lo anule formalmente.

### La emisión vive FUERA de la transacción de la venta

Cuando `registrarVentaPOS()` llama a `ecfEmitirSeguro()`, la venta ya está
cerrada: la mercancía salió, el cliente pagó, el stock bajó y el e-NCF se
imprimió. Si la emisión viviera dentro de la transacción, un proveedor caído
desharía todo eso y el cajero vería un error por algo ajeno a la venta.

**Una venta cerrada no se deshace porque el proveedor esté caído.** Lo que queda
pendiente es transmitir, y para eso está la cola. `ecfEmitirSeguro()` nunca
lanza; lo peor que devuelve es un aviso. Y el envío desde el POS usa un tiempo
de espera corto (`ECF_TIMEOUT_POS`, 8 s) en vez de los 90 s del envío normal:
detrás hay un cliente esperando en el mostrador.

### El contenido del QR viene del proveedor; la imagen la dibujamos aquí

El QR codifica la URL del timbre, que lleva el código de seguridad derivado de la
firma digital: **eso** solo puede darlo el proveedor. La imagen, en cambio, se
genera localmente con `chillerlan/php-qrcode`, y eso trae tres ventajas:

- Se imprime **al tamaño que convenga** en un ticket térmico, sin depender de la
  resolución de una imagen ajena.
- **No hay una segunda descarga** por cada reimpresión.
- Se pinta con `image-rendering: pixelated`, que es lo que impide que el
  navegador interpole los módulos al escalar. **Un QR emborronado no escanea.**

Reglas de operación:

- **Solo se pide cuando el comprobante está aceptado.** Antes de firmarse no hay
  código de seguridad, así que pedirlo sería gastar una llamada para nada.
- **Se guarda y no se vuelve a pedir** (`ecf_documentos.qr` y `qr_url`). La cola
  además lo trae sola en cuanto el documento pasa a aceptado.
- **Termina siempre en data URI.** La política de seguridad de la app
  (`img-src 'self' data: blob:`) bloquea imágenes de dominios externos, y Dompdf
  lo incrusta en el PDF sin salir a la red.
- Si no se consigue, **el ticket se imprime igual**: un fallo de red nunca puede
  dejar a un cliente sin comprobante. Tras `ECF_QR_INTENTOS_MAX` (3) intentos
  deja de insistir para no repetir la llamada en cada reimpresión.

`ecfQrNormalizar()` sigue tolerando que llegue un PNG/JPEG/SVG crudo, un JSON con
base64 o un data URI, por si el formato cambiara.

### La nota de crédito no puede declarar el importe reembolsado

`devolucion_detalles.precio_unitario` guarda lo que se le devolvió al cliente,
o sea **base + ITBIS**: es el dinero que salió de la caja. Pero la trama declara
`IndicadorMontoGravado = 0`, que significa «estos montos NO llevan ITBIS».

Usarlo tal cual haría que la DGII calculara otro 18% encima. Sobre una venta de
2,450 + 441 de ITBIS, la nota acreditaría **520 pesos de impuesto que nunca se
cobraron**. Por eso la base se reconstruye: se rehace la misma proporción que
aplicó la devolución sobre la línea de venta original y, si esa línea ya no
existe, se despeja con la tasa del indicador. La última línea absorbe el
redondeo para que la suma cuadre con `devoluciones.subtotal`.

### Anular no es lo mismo que devolver una parte

El código de modificación (Tabla 18) estaba fijo en 1:

| Código | Cuándo |
|---|---|
| `1` Anula el NCF modificado | Se devolvió **todo** y es la única devolución de esa venta |
| `3` Corrige montos | Devolución **parcial**, o ya hubo devoluciones previas |

El 1 le dice a la DGII que la factura entera queda sin efecto. Usarlo en una
devolución parcial borraría del registro una venta que sigue viva por el resto
del importe. Los ejemplos oficiales del proveedor lo confirman: sus casos de
«Anulación e-NCF» usan 1 y los de «Corrección Monto» usan 3.

### La nota de crédito se imprime con lo declarado, no con lo reembolsado

`modules/pos/nota_credito.php` es el comprobante de la devolución: térmico de
80 mm y PDF A4, con la marca de la venta original y el QR de la DGII. La
devolución termina ahí, igual que una venta termina en su ticket.

Dos datos NO se recalculan al imprimir: se leen de la trama enviada, vía
`ecfDeclaradoDeDevolucion()`.

**El código de modificación.** `ecfCodigoModificacionDevolucion()` mira cuántas
devoluciones tiene la venta *hoy*. Si después de emitir esta nota se hizo otra,
recalcular daría un número distinto del que la DGII tiene guardado, y la
representación impresa dejaría de representar nada.

**Los importes por línea.** En `devolucion_detalles` el subtotal es lo que se
reembolsó, **con el ITBIS dentro** —es el dinero que salió de la caja—. La trama
declara la base sin impuesto, reconstruida con el mismo reparto y redondeo que
hizo la venta. Imprimir el reembolso bajo una columna que suma hacia un total
etiquetado «base acreditada» daría un papel que se contradice: líneas por
RD$ 2,891.00 sobre una base de RD$ 2,450.00.

Cuando la nota es en papel (B04 preimpreso, sin e-CF) no hay trama que leer: se
imprimen las líneas de la base de datos y la columna se rotula «Importe» en vez
de «Base», que es lo que realmente son.

### El corte hay que hacerlo con los terminales drenados

Con el modo offline, un terminal puede tener reservados números B02 que todavía
no ha usado. Si el interruptor se enciende a media jornada, esas ventas llegarán
a sincronizar con un número de la serie vieja.

`registrarVentaPOS()` los **acepta**: el comprobante ya está impreso y en manos
del cliente, y rechazarlo no lo des-imprime, solo hace perder la venta. Entra
como preimpresa (sin e-CF, porque su número no es un e-NCF) y se le deja una
nota explicándolo. Aun así, lo correcto es hacer el corte con las cajas cerradas
y las reservas agotadas.

---

## Cómo avanza la cola

Tres mecanismos, del más inmediato al más tardío. **Ninguno es imprescindible
para que los demás funcionen**, y ninguno puede tumbar una venta:

**1. Al vender.** Con `envio_automatico` encendido, cada venta transmite su
comprobante en el momento (espera corta: `ECF_TIMEOUT_POS`, 8 s). Es el camino
normal y deja la cola casi siempre vacía.

**2. Tick oportunista.** `ecfTickSiToca()` se dispara al pintar la barra
superior, con el mismo enganche que las notificaciones y el motor de marketing.
Solo entra si el e-CF está encendido **y hay trabajo real**; un turno cada
`ECF_TICK_SEGUNDOS` (20) entre todos los usuarios, reclamado de forma atómica en
`sistema_estado` para que diez cajas abiertas no lancen diez pasadas contra el
proveedor a la vez. Lote de 3 documentos y espera corta.

Se comprueba si hay trabajo **antes** de reclamar el turno: quemarlo en balde
dejaría la cola parada otro intervalo entero justo cuando entre trabajo de
verdad. Por eso veinte segundos no salen caros — con la cola vacía, que es lo
normal, el tick no llega ni a reclamar turno.

**Cada cuánto se repregunta por un comprobante ya enviado.** No es un número
fijo, sino `ECF_RECONSULTA`, que se aprieta o se afloja según lo que lleve
transmitido:

| Enviado hace | Se vuelve a consultar cada |
|---|---|
| menos de 2 min | 15 s |
| menos de 30 min | 2 min |
| más | 10 min |

El motivo es que el proveedor no tarda lo mismo en todo: una venta queda firmada
en 2-4 s, pero una nota de crédito puede tardar cerca de un minuto porque además
valida el e-CF al que hace referencia. Con el intervalo plano de diez minutos que
había antes, un comprobante que ya estaba aceptado se pasaba un cuarto de hora
sin QR por pura espera administrativa. Insistir sirve cuando la respuesta está
por llegar; cuando el documento se atascó de verdad, insistir no arregla nada y
solo gasta llamadas, y por eso el último tramo afloja.

**3. Cron real** — `modules/finanzas/ecf_cron.php`. Es el único que funciona con
la tienda cerrada, y con el e-CF encendido **conviene ponerlo**: un comprobante
sin transmitir es un problema fiscal, no una tarea que pueda esperar a que
alguien entre al sistema.

En cPanel → Cron Jobs, cada 5 minutos:

```bash
/usr/local/bin/php /home2/usuario/dominio/modules/finanzas/ecf_cron.php
```

O por URL, definiendo antes `ECF_CRON_KEY` en `config/config.local.php` (sin esa
clave el endpoint devuelve 403 y no se expone):

```bash
curl -s "https://tudominio.com/modules/finanzas/ecf_cron?key=LA_CLAVE"
```

Hace varias pasadas por corrida, para al quedarse sin trabajo o a los 5 minutos
—lo que ocurra primero, para que dos ejecuciones no se solapen— e imprime un
resumen. **Sale con código 1 si algo quedó fallido o en error**, así que el cron
de cPanel puede avisar por correo.

### Y si aun así se atasca

`notif_gen_ecf()` vigila tres situaciones y las publica en el centro de alertas
con permiso `ecf.ver`:

| Situación | Prioridad | Qué significa |
|---|---|---|
| En estado `error` | crítica | Se gastó un e-NCF y no se pudo transmitir: hay que corregir y reenviar, o anular ante la DGII |
| `pendiente` hace más de 1 hora | alta | La cola no avanza: revisar la conexión y que el cron corra |
| `enviado` hace más de 24 h sin acuse | media | Transmitido pero la DGII no resuelve |

Son notificaciones de **situación viva**, no de evento: cuando el problema se
arregla se cierran solas. Sin esto, un comprobante atascado no se nota — la
venta se cobró, el cliente se fue con su ticket y todo parece normal.

---

## Puesta en marcha

1. Aplicar `database/migracion_ecf_p15.sql`.
2. Pedir a LUGANIS: **credenciales**, **URL de producción** y el **certificado
   digital** (o usar el de la empresa).
3. Completar en Configuración → Empresa: RNC, dirección, y los campos nuevos
   `ecf_municipio` / `ecf_provincia` (códigos de la Tabla 8) y actividad económica.
4. Revisar el **indicador de ITBIS** de cada producto (Tabla 13).
5. Cargar los **rangos autorizados** en Finanzas → Facturación Electrónica →
   Secuencias, con su fecha de vencimiento.
6. Probar la conexión y emitir contra el ambiente de pruebas desde la Consola.
7. Solo entonces, encender el interruptor y cambiar el ambiente a producción.

### Si la red inspecciona el tráfico TLS

En la red donde se desarrolló esto hay un **FortiGate** que intercepta TLS y
re-firma los certificados, y además **bloquea `*.tech-luganis.net` por estar sin
categorizar** (FortiGuard, categoría *Unrated*). Dos problemas distintos:

- **El certificado**: el CA del equipo suele estar confiado en Windows, pero PHP
  no consulta el almacén de Windows sino su propio `curl.cainfo`. La salida es
  añadir ese CA a un bundle propio (`config/ca-ecf.local.crt`, no versionado) y
  apuntar `ECF_CA_BUNDLE` ahí. **Nunca desactivar la verificación**: lo que viaja
  son datos fiscales firmados.
- **El bloqueo**: no hay salida técnica desde la aplicación. Hay que pedirle a TI
  que permita el dominio y lo exima de la inspección SSL. El cliente detecta la
  página de bloqueo y lo dice con todas las letras en vez de reportar un 403
  genérico que haría perder medio día revisando credenciales sanas.

---

## Certificación: dónde está el muro

Probado el 11-08-2026 contra el ambiente de pruebas, con ventas reales por el
camino del POS. **Los seis tipos generan documentos que la DGII acepta
estructuralmente**; los cuatro sin rango autorizado se detienen todos en el
mismo sitio:

| Tipo | Resultado |
|---|---|
| 31 Crédito Fiscal | RECHAZADO · **145** |
| 44 Regímenes Especiales | RECHAZADO · **145** |
| 45 Gubernamental | RECHAZADO · **145** |
| 32 / 34 | **ACEPTADOS**, con QR — tienen rango autorizado |

`145` es «Fecha de vencimiento de secuencia inválida»: la secuencia no está
autorizada. **No es un problema de código y no se arregla programando.**

Lo que sí se arregló para llegar hasta aquí: el tipo 44 fallaba antes con el
código `3` del esquema XSD —`Totales` con un hijo `MontoGravadoTotal` inválido—
porque nuestras líneas iban gravadas. Ver «El exento es el comprador».

### La cadena de validación tiene tres capas

Conviene saberlo para leer un rechazo:

1. **El parser de LUGANIS** valida la estructura de la trama. Si falla, no hay
   trackId y el error trae el **nombre exacto del campo** (código `3002`).
2. **El esquema XSD de la DGII** valida el XML que arma el proveedor. Error
   código `3`, con el elemento concreto.
3. **La DGII** valida secuencia y contenido: `145` (secuencia no autorizada),
   `1209` (número ya utilizado).

### Lo que hay que pedirle a LUGANIS

Los rangos de e-NCF autorizados en pruebas para **E31**, **E33**, **E44** y
**E45**. Con E32 y E34 ya se emite, se acepta y se obtiene QR.

> **RNC de prueba de LUGANIS: `131880681`.** Uno inventado hace que el XML salga
> sin `RNCComprador` y el XSD lo rechace. Los tipos 31 y 45 exigen identificar al
> comprador.

---

## Pendiente

### Reportes 606/607/608

Siguen siendo obligación aparte y **no** los toca esta integración. Bajo
facturación electrónica el alcance del 607 cambia; es una pregunta para el
contador, no para el manual del proveedor.

---

## Preguntas para el consultor de LUGANIS

Varias de las dudas originales quedaron **resueltas probando** contra el ambiente
real (ver la sección «Lo que solo se supo probando»): el catálogo de errores se
fue reconstruyendo, los estados del trackId son `Aceptado`/`ACCEPTED`, la forma
de las respuestas está documentada arriba, y el login va anidado en `deviceInfo`.

Lo que sigue abierto:

**Importante**

1. **Idempotencia del envío.** Reenviamos un `filename` cuyo e-NCF ya existía en
   la cuenta y la API respondió `0` con un trackId nuevo, sin avisar de
   duplicado. ¿Es el comportamiento esperado? En un POS, un reintento tras un
   timeout no debe poder duplicar un comprobante.
2. **URL de producción** y proceso de certificación.
3. **Vigencia del token.** El manual dice 3600 s; el ambiente devuelve 2400 s
   (cuenta de envío) y 600 s (consulta). ¿Es intencional y se mantiene en
   producción?
4. **Las dos cuentas.** Probamos las dos contra los cuatro servicios y ninguna
   recibió 403: ambas pueden enviar, consultar y descargar. ¿Es así a propósito o
   en producción sí estarán separadas por permisos?
5. **Orden de DERE y FPAG.** Seguimos los ejemplos (DERE justo después de ITEM),
   que contradicen el índice de secciones. Nuestros comprobantes se aceptaron sin
   DERE, así que el punto sigue sin verificarse. ¿Cuál es el correcto?

**Correcciones para la documentación**

6. **`providerIpAddress` no es opcional**: sin él, el login falla con 1003. La
   tabla de §6.4 no deja claro que sea condición de aceptación.
7. **`filename` declarado Alfanumérico(26)**: con RNC de 9 dígitos da exactamente
   26, pero con cédula de 11 da 28. ¿Cuál es el largo real?
8. **Nombre del archivo en lote**: §5.1(e) dice `132944372-E31-20240909170704.txt`
   y §8.4 dice `132944372E3116042024090025.txt`. Son incompatibles.
9. **PT-001 remite a «la Tabla 12»** para los códigos de impuestos adicionales;
   la Tabla 12 es Tipo_Moneda y esos códigos están en la **Tabla 10**.
10. Los ejemplos curl de PDF, XML y QR apuntan a `pe.stage-api.tech-luganis.net`
    (dominio de **Perú**) y con doble barra.
11. **Reglas de redondeo (§5.3)**: los rótulos están cruzados respecto a sus
    ejemplos. Se implementó redondeo normal a 2 decimales.
12. **Las respuestas de los servicios de descarga** son JSON con el archivo en
    `data.detail.base64FileContent`, y el de QR devuelve la URL del timbre, no
    una imagen. Convendría decirlo en el manual: la captura no lo transmite.
