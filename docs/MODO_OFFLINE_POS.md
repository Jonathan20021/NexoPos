# Modo Offline del Punto de Venta (Fase 1)

El POS sigue vendiendo aunque se caiga el internet. Las ventas se guardan en el
navegador y se sincronizan solas al volver la conexión. **El comprobante fiscal
(NCF) siempre lo asigna el servidor al sincronizar, nunca el navegador**, para
que la secuencia de NCF jamás se duplique ni deje huecos.

## Cómo funciona

1. **Cargar el POS con internet al menos una vez.** El *Service Worker*
   (`sw.js`) guarda en caché la pantalla del POS y sus recursos (Tailwind,
   Alpine, fuentes). Después la pantalla abre aunque no haya conexión.
2. **Vender sin internet.** Al confirmar una venta sin conexión:
   - Se genera un **UUID** en el navegador (identidad única de esa venta).
   - La venta se guarda en **IndexedDB** (base local del navegador).
   - Se muestra un **ticket provisional** (sin valor fiscal, sin NCF todavía).
   - El stock en pantalla se descuenta para no sobrevender.
3. **Reconexión.** Al volver el internet (evento `online`, volver a la pestaña,
   o cada 30 s), el motor envía cada venta pendiente a `sync_venta.php`, que:
   - Revalida precios, stock y permisos en el servidor.
   - Asigna el **NCF** y el número de venta definitivos.
   - Registra la venta, el pago, el movimiento de stock y la transacción.
4. **Sin duplicados.** Cada venta lleva su UUID. Si una sincronización se
   reintenta (por ejemplo, se cortó la red justo después de guardar), el
   servidor detecta que ese UUID ya existe y **devuelve la venta existente sin
   crear otra ni consumir otro NCF** (idempotencia).

## Barra de estado (en el POS)

- 🟡 **Sin conexión** — se está vendiendo offline; las ventas se guardan.
- 🔵 **Sincronizando N venta(s)…** — hay cola y se está enviando.
- 🔴 **N venta(s) con error · revisar** — una venta no se pudo registrar al
  sincronizar (p. ej. stock insuficiente). Se abre un panel para revisarlas y
  descartarlas; **no** se reintentan en bucle para no bloquear la cola.

## Piezas técnicas

| Archivo | Rol |
|---|---|
| `includes/venta_pos.php` | `registrarVentaPOS()`: lógica única de la venta (online y offline). Idempotente por `ventas.uuid`. Asigna NCF aquí. |
| `modules/pos/sync_venta.php` | Endpoint JSON de sincronización. Valida sesión, permiso `pos.vender`, CSRF por cabecera `X-CSRF` y caja abierta. |
| `modules/pos/guardar_venta.php` | Camino clásico (form POST) que ahora también usa `registrarVentaPOS()`. |
| `assets/js/pos-offline.js` | Motor del navegador: IndexedDB, detección de red, cola, reintentos, `window.PosOffline`. |
| `sw.js` | Service Worker: cachea la cáscara del POS. Nunca intercepta POST. |
| `manifest.php` | Manifest PWA (instalable). Respeta la base de la instalación. |
| `database/migracion_offline_p1.sql` | Agrega `ventas.uuid CHAR(36)` + índice `UNIQUE uq_ventas_uuid`. |

## Reglas de seguridad fiscal

- El NCF **solo** se asigna en el servidor, al momento de sincronizar.
- La venta offline conserva su **fecha/hora real** (nunca a futuro).
- Si al sincronizar no hay caja abierta, la venta **espera** (no se pierde) y se
  reintenta cuando se abra una caja.
- Un error de negocio (stock, NCF agotado, permiso) **no** descarta ni duplica:
  marca la venta para revisión manual.

## Alcance de la Fase 1 y siguientes

- **Fase 1 (esta):** venta offline con NCF asignado al sincronizar, cola
  idempotente, ticket provisional, PWA instalable.
- **Fase 2 (futuro):** bloques de NCF reservados por terminal para imprimir el
  comprobante fiscal definitivo incluso estando offline.

## Verificación realizada

- **Idempotencia (servidor):** 3 envíos del mismo UUID → 1 venta, 1 NCF, fecha
  real conservada.
- **Extremo a extremo (Chrome real):** login → POS → offline → venta
  (ticket provisional, 1 en cola, BD intacta) → reconexión → sincroniza →
  venta en BD con UUID y NCF asignado por el servidor.

## Pendiente en producción

Tras hacer **Update From Remote**, ejecutar una vez en la base de datos de
producción (phpMyAdmin o consola MySQL de cPanel):

```sql
SOURCE database/migracion_offline_p1.sql;
```

Es idempotente: si ya se aplicó, solo informa que la columna/índice ya existen.
