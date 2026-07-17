# Transferencias entre sucursales

Mueven inventario de una sucursal a otra con un flujo de aprobación. Se accede en
**Inventario → Transferencias**.

## Flujo de estados

```
 borrador ──enviar──► enviada ──recibir──► recibida
   │                    │
   │ editar/eliminar    ├─ rechazar ─► rechazada   (el destino no la acepta)
   │                    └─ anular ───► anulada      (el origen se arrepiente)
```

| Estado | Stock | Quién actúa |
|---|---|---|
| **borrador** | **no mueve nada** | origen: edita, elimina o envía |
| **enviada** | descontado del origen | destino: recibe o rechaza · origen: anula |
| **recibida** | sumado al destino | — (final) |
| **rechazada** | devuelto al origen | — (final, guarda el motivo) |
| **anulada** | devuelto al origen | — (final) |

El **borrador** es la novedad: permite armar la transferencia sin comprometer stock,
revisarla y enviarla después. El flujo directo de antes sigue existiendo: al crear
puedes pulsar **«Enviar ahora»** y salta directo a *enviada*.

## Reglas

- **El stock solo se mueve al enviar.** Un borrador se puede editar y borrar sin
  consecuencias porque nunca tocó el inventario.
- **Enviar valida stock en el origen** en el momento del envío (no al crear el
  borrador): así no se bloquea nada por adelantado y se comprueba con el stock real.
- **Recibir suma al destino; rechazar y anular devuelven al origen.** La conservación
  del inventario está probada: origen − N y destino + N tras recibir; vuelta a cero
  tras rechazar/anular.
- **Rechazar exige un motivo**, que queda en el detalle de la transferencia.
- Toda la lógica de stock vive en `includes/operaciones.php`
  (`transferenciaEnviar()`, `transferenciaDevolverStock()`), no en la pantalla, y
  pasa por `ajustarStock()` — nunca se toca `inventario_stock` a mano.

## Permisos

| Permiso | Para qué |
|---|---|
| `transferencias.ver` | Ver el listado y el detalle |
| `transferencias.crear` | Crear y editar borradores |
| `transferencias.enviar` | Enviar (esto descuenta stock) |
| `transferencias.recibir` | Recibir en el destino |
| `transferencias.rechazar` | Rechazar en el destino |
| `transferencias.anular` | Anular una enviada, desde el origen |

Separar **crear** de **enviar** permite que una persona arme la transferencia y otra
—con más responsabilidad— la despache. La migración concede *enviar* a quien ya podía
*crear* y *rechazar* a quien ya podía *recibir*, para no dejar a nadie a medias.

## Alcance por sucursal

Una transferencia toca dos sucursales, así que el listado muestra las del **origen o
destino** del usuario. Enviar/anular son del origen; recibir/rechazar, del destino.
El Super Admin y los usuarios «todas las sucursales» ven y operan todas.

## Puesta en marcha

Aplica [`database/migracion_transferencias_p1.sql`](database/migracion_transferencias_p1.sql)
(idempotente; amplía el ENUM de estado, añade trazabilidad y los permisos nuevos). Las
instalaciones nuevas ya nacen con el esquema.
