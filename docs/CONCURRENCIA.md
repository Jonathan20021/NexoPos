# Concurrencia multi-sucursal

Qué se revisó, qué estaba mal, qué se corrigió y cómo se comprobó, para que el sistema
aguante varias sucursales vendiendo al mismo tiempo sin perder nada.

---

## 1. Correlativos duplicados — el fallo grave

**Cómo estaba.** `nextNumero()` hacía `SELECT MAX(numero)+1` y después insertaba. Entre
leer y escribir hay un hueco de milisegundos. Dos cajas vendiendo en ese instante leían el
mismo máximo, construían el mismo `VTA-000053` y la segunda moría contra el índice UNIQUE:
**el cajero veía un error y perdía la venta**.

**Comprobado.** Reproduje el método viejo con 8 procesos en paralelo pidiendo 25 números
cada uno:

| Método | Generados | Únicos | Colisiones |
|---|---|---|---|
| Anterior (`MAX+1`) | 200 | **1** | **199** |
| Actual (contador atómico) | 200 | 200 | **0** |

**Cómo quedó.** Tabla `contadores` con una fila por serie. El número se reserva con
`UPDATE contadores SET valor = LAST_INSERT_ID(valor + 1)`, que es indivisible: cada
llamada se lleva un número distinto vengan de donde vengan. Si alguien insertó un número a
mano por fuera, `nextNumero()` lo detecta y salta al siguiente libre en vez de reventar.

`previewNumero()` es la versión que **no consume**: se usa para sugerir el código en un
formulario. Usar `nextNumero()` ahí quemaría un correlativo en cada visita a la pantalla.

---

## 2. Interbloqueos por orden de bloqueo

**Cómo estaba.** Cada venta bloqueaba las filas de stock en el orden en que el cajero armó
el carrito. Dos cajas de la misma sucursal vendiendo los mismos artículos en orden distinto
se quedaban esperando la una a la otra (A tiene el lápiz y quiere el cuaderno; B tiene el
cuaderno y quiere el lápiz) e InnoDB mataba una de las dos transacciones.

**Cómo quedó.** El stock **siempre** se toca en orden de `producto_id`. Con un orden único
el ciclo no puede formarse. Se aplicó en ventas, compras, devoluciones, transferencias,
anulaciones y facturación de pedidos (`ORDER BY producto_id` en las consultas que alimentan
esos bucles, y `usort()` donde las líneas vienen del formulario).

---

## 3. Reintento ante choques transitorios

Aun con el orden correcto, InnoDB puede abortar una transacción por interbloqueo (1213) o
por agotar la espera de un bloqueo (1205). **Eso no es un error del negocio**: es la base
diciendo «vuelve a intentarlo». Si llega a la pantalla, el cajero pierde la venta sin motivo.

`txReintentable()` reintenta hasta 3 veces con espera creciente y algo de azar (para que los
reintentos no vuelvan a chocar entre sí). **Solo** reintenta esos dos códigos: un error real
—stock insuficiente, cliente sin crédito, NCF agotado— sube tal cual, porque reintentarlo no
arreglaría nada y ocultaría el problema.

Está activo en: ventas del POS, compras, devoluciones, transferencias, ajustes de stock,
abonos de clientes, anulaciones, facturación de pedidos, tienda en línea y reserva de NCF.

---

## 4. Primera venta de un producto

**Cómo estaba.** `ajustarStock()` buscaba la fila de existencias con `FOR UPDATE`; si el
producto nunca se había movido en esa sucursal, no había fila que bloquear. Dos ventas
simultáneas no encontraban nada, las dos intentaban insertarla y una moría contra el índice
UNIQUE (o quedaban enganchadas en un bloqueo de hueco).

**Cómo quedó.** Se garantiza la fila con `INSERT ... ON DUPLICATE KEY UPDATE` **antes** de
bloquearla. El `FOR UPDATE` cae siempre sobre un registro real y solo serializa.

---

## 5. Apertura de caja duplicada

**Cómo estaba.** Se consultaba «¿esta caja está abierta?» y después se insertaba la sesión.
Dos cajeros pulsando «Abrir» a la vez veían la caja libre y quedaban **dos sesiones abiertas
sobre el mismo cajón**, con el arqueo descuadrado.

**Cómo quedó.** La comprobación y la inserción van juntas en una transacción, con la caja
bloqueada (`SELECT ... FOR UPDATE` sobre `cajas`).

**Comprobado.** 8 procesos abriendo la misma caja en el mismo instante: **1 abre, 7 reciben
el mensaje de que ya está abierta**, y queda exactamente 1 sesión en la base.

---

## 6. Lo que ya estaba bien

No todo estaba roto. Estas partes se auditaron y **no necesitaron cambios**:

- **NCF.** `siguienteNCF()` ya bloqueaba la secuencia con `FOR UPDATE`. Ninguna venta puede
  repetir un comprobante fiscal, y si la venta falla el número se devuelve solo (no deja hueco).
- **Balances.** Todos los saldos se mueven con `balance = balance ± ?`, que la base resuelve
  de forma atómica. No hay lectura-modificación-escritura que se pueda pisar.
- **Stock negativo.** La validación va *después* del `FOR UPDATE`, así que dos ventas
  compitiendo por la última unidad no pueden dejar el inventario en negativo.
- **Ventas offline.** Idempotentes por `uuid` con índice UNIQUE: reenviar la misma venta no
  la duplica.

---

## 7. Cómo se comprobó

Todo contra una **copia** de la base, nunca sobre los datos reales.

**500 operaciones concurrentes con 10 procesos en paralelo:**
- Fase 1 — 300 ventas de los mismos 3 productos, en orden aleatorio en cada venta.
- Fase 2 — 200 operaciones mezclando ventas, compras y ajustes de inventario.

**Resultado: 0 errores.** Ni un interbloqueo, ni una espera agotada, ni una clave duplicada.

**Prueba de sobreventa:** 10 unidades disponibles, 8 procesos intentando llevarse 5 cada uno
en el mismo instante → **exactamente 2 ventas aceptadas y 6 rechazadas**, existencia final
en 0, nunca negativa, y las 6 rechazadas **no dejaron rastro** (ni venta, ni línea, ni
movimiento de kardex, ni NCF consumido).

**Auditoría posterior:**

| Comprobación | Resultado |
|---|---|
| Correlativos duplicados | 0 |
| NCF duplicados | 0 |
| Huecos en la secuencia NCF | 0 |
| Existencias negativas | 0 |
| Existencias distintas del kardex | 0 nuevas |
| Ventas sin detalle o sin pago | 0 |
| Compras sin detalle | 0 |

---

## 8. Verificación permanente

`modules/admin/integridad.php` (Administración → Integridad de datos) corre estas mismas
14 comprobaciones cuando se quiera, explica qué significa cada hallazgo y cómo corregirlo.
Solo lee: no modifica nada. Conviene mirarla después de un día fuerte de operación.

---

## 9. Reglas para quien siga desarrollando

1. **Nunca** calcules un correlativo con `MAX(...)+1`. Usa `nextNumero()`.
2. Si un bucle toca varios productos, **ordena por `producto_id`** antes de mover stock.
3. Toda escritura que mueva stock o dinero va dentro de `txReintentable()`.
4. Los saldos se actualizan con `balance = balance ± ?`, nunca leyendo y luego escribiendo.
5. Antes de insertar algo que deba ser único por una condición (una sesión de caja abierta
   por cajón), bloquea la fila padre con `FOR UPDATE` dentro de la misma transacción.
6. Las validaciones que dependen de un valor bloqueado van **después** del `FOR UPDATE`.

---

## 10. Migración

- **Instalación nueva:** ya viene en `database/schema.sql`.
- **Base existente:** aplicar una vez `database/migracion_concurrencia_p4.sql`. Crea la
  tabla `contadores`, la siembra con los máximos actuales (sin repetir ni saltar números) y
  añade tres índices que faltaban para el trabajo concurrente. Es idempotente.
