# Correos automáticos de la tienda en línea

NexoPOS envía correos transaccionales con [Resend](https://resend.com).

## Regla de oro

**Un correo que falla nunca rompe la operación que lo disparó.** Si Resend está
caído, el pedido se registra igual, el estado cambia igual y el link se guarda
igual. El fallo queda anotado en la tabla `correos_enviados` con el error exacto.

Ninguna función de [`includes/mail.php`](includes/mail.php) lanza excepciones.

## Qué se envía y cuándo

| Evento | Destinatario | Se dispara en |
|---|---|---|
| `nuevo_cliente` | Cliente | Al crear el pedido en la tienda |
| `nuevo_sucursal` | Sucursal (o la empresa) | Al crear el pedido |
| `link_pago` | Cliente | Al guardar el link de pago del pedido |
| `estado_listo` | Cliente | Al marcar el pedido «listo» |
| `estado_entregado` | Cliente | Al marcar el pedido «entregado» |
| `estado_cancelado` | Cliente | Al cancelar el pedido |

Los estados `pendiente` y `confirmado` **no** generan correo: no le dicen nada
útil al cliente y solo ensucian su bandeja.

El correo del cliente es **obligatorio** en el checkout de la tienda. El de la
sucursal se toma de *Configuración → Sucursales → Email*; si está vacío, se usa
el correo de la empresa. Si tampoco hay, el aviso a la tienda se omite y queda
registrado.

## Configuración

Todo vive en `config/config.local.php`, que está en `.gitignore` y **nunca** se
sube al repositorio:

```php
define('RESEND_API_KEY', 're_xxxxxxxxxxxx');
define('MAIL_FROM', 'Tu Empresa <pedidos@tudominio.com>');
define('MAIL_REPLY_TO', 'contacto@tudominio.com');
```

Requisitos:

1. El dominio de `MAIL_FROM` **debe estar verificado** en Resend. Si no, Resend
   responde `403 domain is not verified` y no se envía nada.
2. Conviene crear una API key **restringida a ese dominio**, no una key de cuenta
   con acceso a todos tus dominios.
3. Si dejas `RESEND_API_KEY` vacía, el sistema no envía correos y sigue
   funcionando con normalidad.

## Cuando un correo no llega

Mira la tabla `correos_enviados`. Ahí está todo:

```sql
SELECT created_at, evento, destinatario, estado, proveedor_id, error
  FROM correos_enviados
 ORDER BY id DESC LIMIT 20;
```

- `estado = 'enviado'` y `proveedor_id` con el id de Resend → búscalo en el panel
  de Resend para ver si se entregó, rebotó o cayó en spam.
- `estado = 'fallido'` → el mensaje de `error` dice exactamente qué pasó
  (key inválida, dominio sin verificar, destinatario mal escrito).

## Probar sin molestar a nadie

Resend tiene buzones de prueba que aceptan correo y no llegan a ninguna persona:

- `delivered@resend.dev` — se entrega correctamente.
- `bounced@resend.dev` — se acepta y luego rebota.
- `complained@resend.dev` — se marca como spam.

Úsalos para verificar la integración antes de apuntar a correos reales.

---

## Dónde va la API key en el servidor

En cPanel, abre `/home2/neetjbte/nexo.kyrosrd.com/config/config.local.php` y
**añade estas tres líneas al final**, sin borrar las de la base de datos:

```php
define('RESEND_API_KEY', 're_tu_key_aqui');
define('MAIL_FROM', 'Comercial Dominicana SRL <pedidos@kyrosrd.com>');
define('MAIL_REPLY_TO', 'pedidos@kyrosrd.com');
```

Ese archivo está en `.gitignore`, así que un *Update From Remote* nunca lo
sobrescribe ni lo sube a GitHub.
