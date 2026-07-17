# Modo Offline del Punto de Venta

El POS sigue vendiendo aunque se caiga el internet. Las ventas se guardan en el
navegador y se sincronizan solas al volver la conexión.

Hay dos fases, y **ambas están activas**:

- **Fase 1** — venta offline con cola idempotente y ticket **provisional**; el NCF
  lo asigna el servidor al sincronizar.
- **Fase 2** — cada terminal reserva por adelantado rangos de NCF, así que estando
  offline imprime el **comprobante fiscal definitivo** en el acto. Si el colchón de
  NCF reservados se agota, la venta cae con elegancia al ticket provisional de la
  Fase 1.

**En ningún caso el navegador inventa un NCF:** o toma uno de un rango que el
servidor le reservó (Fase 2), o espera a que el servidor le asigne uno al
sincronizar (Fase 1). La secuencia de NCF jamás se duplica.

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
- 🟢 **NCF fiscales offline: N B02 · M B01** (Fase 2) — cuántos comprobantes
  definitivos puede emitir el terminal sin conexión. Si llega a 0, avisa que se
  emitirá ticket provisional.

## Piezas técnicas

| Archivo | Rol |
|---|---|
| `includes/venta_pos.php` | `registrarVentaPOS()`: lógica única de la venta (online y offline). Idempotente por `ventas.uuid`. Asigna NCF aquí. |
| `modules/pos/sync_venta.php` | Endpoint JSON de sincronización. Valida sesión, permiso `pos.vender`, CSRF por cabecera `X-CSRF` y caja abierta. |
| `modules/pos/guardar_venta.php` | Camino clásico (form POST) que ahora también usa `registrarVentaPOS()`. |
| `assets/js/pos-offline.js` | Motor del navegador: IndexedDB, detección de red, cola, reintentos, colchón de NCF reservado, `window.PosOffline`. |
| `includes/ncf_reservas.php` | **(Fase 2)** Reserva rangos de NCF por terminal, valida pertenencia, devuelve tramos no usados. |
| `modules/pos/terminal_sync.php` | **(Fase 2)** Endpoint JSON que talla y entrega NCF al terminal mientras hay conexión. |
| `modules/pos/terminales.php` | **(Fase 2)** Pantalla de administración: terminales, sus reservas, consumo y devolución de bloques. |
| `sw.js` | Service Worker: cachea la cáscara del POS. Nunca intercepta POST. |
| `manifest.php` | Manifest PWA (instalable). Respeta la base de la instalación. |
| `database/migracion_offline_p1.sql` | Agrega `ventas.uuid CHAR(36)` + índice `UNIQUE uq_ventas_uuid`. |
| `database/migracion_offline_p2.sql` | **(Fase 2)** Tablas `pos_terminales` y `ncf_reservas`; `ventas.ncf` pasa a `UNIQUE`. |

## Reglas de seguridad fiscal

- El NCF **solo** se asigna en el servidor, al momento de sincronizar.
- La venta offline conserva su **fecha/hora real** (nunca a futuro).
- Si al sincronizar no hay caja abierta, la venta **espera** (no se pierde) y se
  reintenta cuando se abra una caja.
- Un error de negocio (stock, NCF agotado, permiso) **no** descarta ni duplica:
  marca la venta para revisión manual.

## Fase 2 — NCF fiscal definitivo estando offline

### La idea
Cada **terminal** (un dispositivo del POS) se identifica con un *token de
dispositivo* que se genera una vez y vive en el `localStorage` del navegador.
Mientras hay internet, el terminal le pide al servidor un colchón de NCF por
adelantado (por defecto 40 B02 y 12 B01, se rellena cuando baja del umbral). El
servidor **talla** ese rango de la secuencia general y lo **delega** al terminal.

Estando offline, el navegador toma el siguiente NCF de su colchón local y lo
imprime como comprobante fiscal definitivo. Al reconectar, la venta se sincroniza
y el servidor **valida** que ese NCF pertenece a una reserva activa del terminal
antes de registrarla.

### Por qué no se duplica ni se solapa
- El rango se talla **bajo bloqueo** (`FOR UPDATE`) y la secuencia general **salta
  por encima**. Dos terminales nunca reciben el mismo número, y una venta online
  (que sigue usando la secuencia general) nunca choca con una offline.
- `ventas.ncf` es **UNIQUE**: aunque todo lo demás fallara, la base de datos
  rechaza cualquier duplicado.
- La venta sigue siendo **idempotente por UUID**: reenviarla no consume otro NCF.
- Al sincronizar, el servidor revalida tipo de comprobante, pertenencia a la
  reserva y que el NCF no esté ya emitido.

### Huecos en la secuencia
Un bloque reservado que no se agota deja **NCF sin usar** (huecos). La DGII lo
admite: no se reutilizan ni se sale del rango autorizado. Para minimizarlos:
- El colchón es pequeño y se **reutiliza** entre episodios offline del mismo
  dispositivo (persiste en IndexedDB), no se re-talla cada vez.
- En **Ventas → Terminales offline** se puede **Devolver** un bloque: si su tramo
  final no usado es contiguo con la cabeza de la secuencia general, se recupera; si
  no, esos números quedan como hueco y se informan para el contador.

### Alcance
- **B02 (consumidor) y B01 (crédito fiscal)** llevan colchón offline. Una venta
  offline cuyo tipo se quedó sin NCF reservado cae al ticket provisional (Fase 1).
- El camino **online no cambió**: sigue asignando NCF desde la secuencia general.

## Verificación realizada

- **Idempotencia (servidor):** 3 envíos del mismo UUID → 1 venta, 1 NCF, fecha
  real conservada.
- **Extremo a extremo (Chrome real):** login → POS → offline → venta
  (ticket provisional, 1 en cola, BD intacta) → reconexión → sincroniza →
  venta en BD con UUID y NCF asignado por el servidor.

## Pendiente en producción

Tras hacer **Update From Remote**, ejecutar una vez en la base de datos de
producción (phpMyAdmin o consola MySQL de cPanel), en orden:

```sql
SOURCE database/migracion_offline_p1.sql;
SOURCE database/migracion_offline_p2.sql;
```

Ambas son idempotentes: si ya se aplicaron, solo informan que las columnas/índices/
tablas ya existen. La `p2` crea `pos_terminales` y `ncf_reservas` y convierte
`ventas.ncf` en `UNIQUE` (si hubiera NCF duplicados heredados, **no** rompe: avisa y
deja el índice sin crear para que se corrijan primero).

Además, para que un rol no-superadministrador administre los terminales, hay que
concederle el permiso **Punto de Venta → Terminales offline (NCF)** en Roles y
Permisos. El superadministrador ya lo tiene.

### Configurar el colchón
En **Configuración → Comprobantes (NCF)** deben existir las secuencias **B02** y
**B01** con rango vigente: de ahí se tallan las reservas. Si una secuencia está
agotada o vencida, el terminal no reserva y las ventas offline de ese tipo caen a
ticket provisional.
