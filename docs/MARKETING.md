# Marketing — campañas, segmentos y automatización

Módulo para preparar promociones personalizadas y hacérselas llegar a los
clientes: **por correo de forma automática** (Resend) y **por WhatsApp de forma
asistida** (wa.me).

Motor: [`includes/marketing.php`](../includes/marketing.php).
Pantallas: [`modules/marketing/`](../modules/marketing/).
Base de datos: [`database/migracion_marketing_p9.sql`](../database/migracion_marketing_p9.sql).

---

## Lo primero: qué es automático y qué no

| | Correo (Resend) | WhatsApp (wa.me) |
|---|---|---|
| Elegir a quién | automático | automático |
| Redactar y personalizar | automático | automático |
| **Enviar** | **automático** | **un clic por cliente** |
| Aperturas | sí (píxel) | no aplica |
| Clics | sí | sí (enlace rastreado) |
| Bajas | sí (enlace en el pie) | manual |

**wa.me es un enlace, no una API.** Abre la conversación con el mensaje ya
escrito, pero el botón «enviar» lo pulsa una persona. Automatizarlo de verdad
exige la **API oficial de WhatsApp Business**: plantillas aprobadas por Meta,
un proveedor (Twilio, 360dialog…) y costo por mensaje. Mientras tanto, el
sistema prepara todo lo demás y la consola de envío deja el trabajo en unos tres
segundos por cliente en vez de dos minutos.

Prometer «envío masivo automático por WhatsApp» con wa.me sería mentira, y por
eso la interfaz lo dice en voz alta donde corresponde.

---

## Las siete piezas

### 1. Segmentos — `segmentos.php`
Un segmento guarda **reglas**, no una lista de personas. Se evalúa en el momento
del envío: un cliente que hoy cumple 90 días sin comprar entra solo al segmento
de dormidos.

Reglas disponibles: contactabilidad (correo/teléfono), tipo de cliente, saldo
pendiente, sucursal donde compró, categoría que compró, días desde la última
compra (mínimo y máximo), número de compras, gasto acumulado y mes de cumpleaños.

Vienen siete de fábrica: *Todos los contactables*, *Clientes frecuentes*,
*Dormidos (90 días)*, *Compraron este mes*, *Cumpleañeros del mes*, *Con saldo
pendiente* y *WhatsApp: todos con teléfono*.

> **Gasto acumulado** es el total facturado **con ITBIS**: lo que el cliente
> realmente pagó. Es distinto del criterio contable de los reportes
> (ingresos = subtotal − descuento, sin ITBIS) y es a propósito: para segmentar
> importa el bolsillo del cliente, no la utilidad de la empresa.

### 2. Plantillas — `plantillas.php`
Textos reutilizables con asunto, cuerpo, botón y versión de WhatsApp. Al crear
una campaña **se copian, no se enlazan**: editar la plantilla mañana no cambia lo
que ya se envió ayer.

Variables: `{{cliente}}` `{{nombre}}` `{{empresa}}` `{{telefono}}` `{{promo}}`
`{{descuento}}` `{{vigencia}}` `{{saldo}}` `{{tienda}}`.

### 3. Campañas — `campanas.php` y `campana.php`
El editor tiene todo: asunto (con **prueba A/B** opcional), preheader, cuerpo,
imagen de cabecera, promoción destacada (se dibuja como cupón), botón con enlace
rastreado y mensaje de WhatsApp.

Estados: `borrador → programada → enviando → enviada | parcial`, más `pausada` y
`cancelada`. Una campaña que ya salió **no se puede editar**: cambiar el texto
dejaría un historial que no coincide con lo que recibieron los clientes.

### 4. Envío por lotes
No hay un único POST que tarde cinco minutos. El navegador va pidiendo lotes por
AJAX (`accion=api_lote`) y una barra de progreso avanza; cada lote es **una sola
llamada** a Resend (`/emails/batch`, hasta 100 correos) gracias a
`mail_enviar_lote()`.

Cada destinatario es una fila en `campana_envios`. De ahí sale todo lo demás:

- un corte de conexión no pierde nada: el envío se reanuda donde quedó;
- nadie recibe el mismo correo dos veces (`UNIQUE (campana_id, canal, destino)`);
- los fallidos se reintentan con un botón;
- recalcular la audiencia tras editar el segmento **solo añade** a quien falte.

### 5. Rastreo — `t.php`
- **Apertura:** píxel de 1×1 (`?t=TOKEN&a=o`).
- **Clic:** redirección con registro (`?t=TOKEN&a=c&u=DESTINO`).

`u` **no** es un redirector abierto: solo se acepta un destino que la propia
campaña publicó (su botón, un enlace de su contenido) o una URL del mismo
sistema. Un redirector que acepte cualquier cosa convierte tu dominio en
herramienta de phishing.

### 6. Bajas — `baja.php`
Todo correo lleva enlace de baja en el pie. La baja **se confirma con un POST**,
nunca con el simple clic del enlace: los antivirus y escáneres de correo abren
todos los enlaces de un mensaje y darían de baja a gente que nunca lo pidió.

Al darse de baja: se registra en `marketing_bajas`, se pone
`clientes.acepta_marketing = 0` (deja de entrar en cualquier segmento) y sus
envíos pendientes pasan a `omitido`. Los correos de sus **pedidos** siguen
llegando: son servicio, no publicidad.

### 7. Automatizaciones — `automatizaciones.php`

| Regla | Se dispara | No se repite |
|---|---|---|
| Bienvenida | N días tras registrarse | una vez en la vida |
| Después de una compra | N días tras la venta | una vez por venta |
| Cumpleaños | N días antes del cumpleaños | una vez al año |
| Recompra | N días desde la última compra | una vez por esa compra |
| Cliente dormido | N días sin comprar | una vez al mes |
| Saldo pendiente | N días desde la venta a crédito | una vez al mes |

**Todas nacen apagadas.** Nadie debe descubrir que su sistema empezó a
escribirle a sus clientes sin habérselo pedido.

Una automatización no envía: crea la campaña del periodo y encola. Así hereda
reintentos, rastreo, bajas y métricas del motor de campañas, y su historial se
ve como una campaña más. `marketing_automatizacion_log` (con clave única
`automatizacion_id + cliente_id + periodo`) es lo que impide repetir.

---

## Cómo corre el motor sin cron

Igual que el barrido de notificaciones: la primera visita después de
`MKT_TICK_MINUTOS` reclama el turno con un UPDATE atómico sobre `sistema_estado`
y hace la pasada (`mkt_tick_si_toca()` en `includes/layout/notificaciones.php`).
Solo entra si hay trabajo real pendiente, para no cobrarle el tiempo a nadie.

**Para que salga aunque nadie esté conectado** (una campaña programada a las 6:00
a.m. con la tienda cerrada), añade el cron en cPanel cada 5 minutos:

```
/usr/local/bin/php /home2/usuario/dominio/modules/marketing/cron.php
```

O por URL, definiendo antes la clave en `config/config.local.php`:

```php
define('MKT_CRON_KEY', 'una-cadena-larga-y-aleatoria');
```

```
curl -s "https://tudominio.com/modules/marketing/cron?key=LA_CLAVE"
```

### Enlaces absolutos bajo cron
En un correo no valen las rutas relativas. `mkt_url_abs()` resuelve el dominio
por este orden: `APP_URL` si es absoluta → variable de entorno `APP_PUBLIC_URL`
→ la petición en curso. **Bajo cron (CLI) no hay petición**, así que si usas cron
define una de las dos primeras o los enlaces saldrán rotos:

```php
define('APP_URL', 'https://nexo.kyrosrd.com');
```

---

## Métricas y atribución

- **Tasa de apertura** = aperturas ÷ enviados. Es orientativa: quien bloquea
  imágenes no cuenta, y quien usa protección de privacidad de correo cuenta de
  más.
- **Tasa de clic** = clics ÷ enviados. Esta sí es dura.
- **Ventas atribuidas** = compras de los destinatarios dentro de los
  `MKT_ATRIBUCION_DIAS` (14) siguientes al envío.

La atribución es **una correlación por ventana de tiempo**, útil para comparar
campañas entre sí. No prueba que la venta ocurriera *por* el correo: el cliente
pudo haber venido igual. Dicho de otro modo, sirve para decidir qué campaña
repetir, no para justificar un presupuesto ante un banco.

El panel muestra además **quién hizo clic y no ha comprado**: son las llamadas
que valen, interés demostrado sin cerrar.

---

## Configuración necesaria

En `config/config.local.php` (git-ignorado):

```php
define('RESEND_API_KEY', 're_xxxxxxxxxxxx');
define('MAIL_FROM',      'Tu Empresa <promociones@tudominio.com>');
define('MAIL_REPLY_TO',  'contacto@tudominio.com');
define('MKT_CRON_KEY',   'clave-larga-aleatoria');   // solo si usas cron por URL
```

El dominio de `MAIL_FROM` **debe estar verificado en Resend** o responde
`403 domain is not verified`. Detalles en [`CORREOS.md`](../CORREOS.md).

Sin clave de Resend el módulo sigue funcionando: se preparan campañas y se usa
la consola de WhatsApp, pero ningún correo sale y la interfaz lo avisa.

---

## Permisos

| Clave | Para qué |
|---|---|
| `marketing.ver` | Panel de marketing |
| `marketing.segmentos` | Crear y editar segmentos |
| `marketing.plantillas` | Crear y editar plantillas |
| `marketing.automatizar` | Encender y configurar automatizaciones |
| `campanas.ver/crear/editar/eliminar` | CRUD de campañas |
| `campanas.enviar` | Enviar y programar correos |
| `campanas.whatsapp` | Consola de envío por WhatsApp |

`campanas.enviar` y `marketing.automatizar` son los dos que hay que dar con
cuidado: el primero escribe a toda la base de clientes; el segundo la deja
escribiendo sola.

---

## Buenas prácticas que evitan terminar en spam

1. **Envíate una prueba antes.** Está en el panel derecho de cada campaña.
2. **Nunca compres listas.** Escribe solo a clientes que te dieron su correo.
3. **No borres el enlace de baja.** Se añade solo; quitarlo es la vía rápida a
   que tu dominio quede marcado.
4. **Verifica el dominio en Resend** y configura SPF, DKIM y DMARC.
5. **Ritmo humano en WhatsApp.** Tandas cortas y espaciadas: WhatsApp restringe
   cuentas que envían cientos de mensajes idénticos de golpe.
6. **Frecuencia.** Una o dos campañas al mes por cliente. Más que eso multiplica
   las bajas sin multiplicar las ventas.

---

## Rendimiento

El histórico agregado de compras (`COUNT`, `SUM`, `MAX(fecha)` por cliente) es lo
más caro del módulo. `mkt_segmento_sql()` **solo lo cruza cuando alguna regla lo
necesita**: los segmentos por cumpleaños, saldo o contactabilidad no lo tocan.

Los conteos de la pantalla de segmentos se piden por AJAX, uno por tarjeta: con
datos reales, calcular catorce de golpe al abrir la página la haría lenta.

Como en el resto del sistema, los filtros de fecha van **sobre la columna cruda**
(`enviado_at >= ?`), nunca envueltos en `DATE()`, que anula el índice.
