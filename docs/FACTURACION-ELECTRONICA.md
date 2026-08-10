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
| Tipos 31, 32, 33 y 34 | Implementados y verificados contra los ejemplos oficiales |
| Tipos 41, 43, 44, 45, 46 y 47 | **No implementados** (fixtures guardados, sin generador) |
| Envío en línea (API REST) | Implementado |
| Envío en lote (S3) | **No implementado** |
| Emisión al vender, facturar un pedido y devolver | Conectada |
| Cola automática (tick + cron) y alertas | Implementada |

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

Las 147 tramas del Excel están extraídas en
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
compara carácter por carácter. **112 de 112** de los tipos implementados salen
idénticas. Detecta el error más caro de todos —un campo corrido de posición—
que si no solo aparecería cuando la DGII rechace el comprobante.

```bash
php database/ecf_ejemplos/probar_reglas.php
```

**71 pruebas** del criterio que los ejemplos no cubren: el umbral de
RD$250,000, la obligatoriedad del comprador en el crédito fiscal, la tolerancia
de ±1, el redondeo, los campos repetibles, el saneado de caracteres que
romperían la trama y la normalización del QR.

Correr ambas después de tocar cualquier layout.

```bash
mysql -u root -e "DROP DATABASE IF EXISTS inventario_pos_ecftest; CREATE DATABASE inventario_pos_ecftest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysqldump -u root --single-transaction inventario_pos | mysql -u root inventario_pos_ecftest
php database/ecf_ejemplos/probar_pos.php
```

**62 pruebas** del enganche al POS, sobre un clon desechable: registra ventas de
verdad y comprueba que apagado nada cambia, que encendido toma E32/E31 y crea su
documento, y que **con el proveedor caído la venta se completa igual**. También
cubre el QR (que no se pida antes de tiempo y se sirva de la caché) y la cola
(que el tick no gaste el turno en balde, que dos ticks seguidos no procesen dos
veces y que un comprobante atascado genere alerta). Se niega a correr si la base no
termina en `_ecftest`.

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

### El cliente lee las respuestas «a tientas»

El manual muestra los cuerpos de respuesta como **capturas de pantalla**, así que
los nombres de los campos JSON no están publicados. En vez de adivinar uno, se
busca el valor por varios nombres plausibles y **siempre** se guarda la respuesta
cruda en `ecf_log`. Con el primer contacto real quedan a la vista los nombres
verdaderos y se fijan en `ecfClavesToken()` / `ecfClavesTrackId()`.

Por lo mismo, `ecfInterpretarEstado()` es deliberadamente conservador: solo marca
«aceptado» ante una señal inequívoca. Lo ambiguo se queda en «enviado» y se
vuelve a consultar. Dar por bueno un comprobante que la DGII rechazó sería el
peor error posible.

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

### El QR no se puede generar aquí

El código QR de la Representación Impresa lleva el **código de seguridad**
derivado de la firma digital del comprobante. Esa firma la pone el proveedor,
así que el QR solo puede venir de él: se descarga con
`GET /client-service/download/QR/…` y se guarda en `ecf_documentos.qr` como
data URI.

Tres consecuencias de diseño:

- **Solo se pide cuando el comprobante está aceptado.** Antes de firmarse no
  existe, así que pedirlo sería gastar una llamada para nada.
- **Se guarda y no se vuelve a pedir.** Reimprimir el ticket de un cliente que
  vuelve al mostrador no puede depender de que el proveedor conteste. La cola
  además lo trae sola en cuanto el documento pasa a aceptado.
- **Termina siempre en data URI.** La política de seguridad de la app
  (`img-src 'self' data: blob:`) bloquea imágenes de dominios externos, así que
  un `<img src="https://…">` del proveedor no se vería; y Dompdf lo incrusta en
  el PDF sin salir a la red.

Como el manual muestra la respuesta de ese servicio como una captura,
`ecfQrNormalizar()` tolera que llegue un PNG/JPEG/SVG crudo, un JSON con el
base64 dentro, un data URI ya formado o una URL. Si no encuentra el QR devuelve
null y **el ticket se imprime igual**: un fallo de red nunca puede dejar a un
cliente sin comprobante. Tras `ECF_QR_INTENTOS_MAX` (3) intentos deja de
insistir para no repetir la llamada en cada reimpresión.

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
`ECF_TICK_MINUTOS` (5) entre todos los usuarios, reclamado de forma atómica en
`sistema_estado` para que diez cajas abiertas no lancen diez pasadas contra el
proveedor a la vez. Lote de 3 documentos y espera corta.

Se comprueba si hay trabajo **antes** de reclamar el turno: quemarlo en balde
dejaría la cola parada otros cinco minutos justo cuando entre trabajo de verdad.

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

## Pendiente

### Reportes 606/607/608

Siguen siendo obligación aparte y **no** los toca esta integración. Bajo
facturación electrónica el alcance del 607 cambia; es una pregunta para el
contador, no para el manual del proveedor.

---

## Preguntas para el consultor de LUGANIS

Antes de certificar conviene aclarar esto. Lo que está implementado funciona con
la interpretación anotada al lado, pero son puntos donde el documento no cierra:

**Bloqueantes**

1. **Catálogo de códigos de error.** La respuesta trae `status.code` pero no
   existe la lista. Sin ella no se puede distinguir un rechazo definitivo de uno
   reintentable.
2. **Estados posibles de un trackId.** La respuesta de consulta aparece como
   imagen en el manual; los valores no están en texto.
3. **Forma exacta de las respuestas JSON** (login y envío), o la colección de
   Postman.
4. **URL de producción.**
5. **Idempotencia**: si el POST `/send` da timeout, ¿reenviar el mismo `filename`
   duplica el e-CF o lo rechaza? Crítico en un POS.

**Inconsistencias del documento**

6. **Orden de DERE y FPAG** (ver arriba). ¿Manda el índice o los ejemplos?
7. **Login**: la tabla de parámetros lista `appVersion`, `os`, `deviceId`… como
   campos planos del body; el ejemplo curl los anida en `deviceInfo`. Se
   implementó el ejemplo.
8. **Nombre del archivo en lote**: §5.1(e) dice `132944372-E31-20240909170704.txt`
   y §8.4 dice `132944372E3116042024090025.txt`. Son incompatibles.
9. **`filename` declarado Alfanumérico(26)**: con RNC de 9 dígitos da exactamente
   26, pero **con cédula de 11 da 28**. ¿Cuál es el largo real del campo?
10. **PT-001 remite a «la Tabla 12»** para los códigos de impuestos adicionales;
    la Tabla 12 es Tipo_Moneda y esos códigos están en la **Tabla 10**.
11. Los ejemplos curl de PDF, XML y QR apuntan a `pe.stage-api.tech-luganis.net`
    (dominio de **Perú**) y con doble barra.
12. **Reglas de redondeo (§5.3)**: los rótulos están cruzados respecto a sus
    ejemplos. Se implementó redondeo normal a 2 decimales, que es lo que ambos
    ejemplos muestran.
