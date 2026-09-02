# Control de salidas de inventario

## Por qué existe

Lo pidió la dirección con estas palabras:

> «Lo que no pueden es que saquen una mercancía sin un permiso, una aprobación, sin una
> nota, de por qué sacaron un producto. Como de un inventario, decir que si habían
> veinte, ahora hay quince.»

Traducido a reglas: **ninguna existencia baja sin que quede escrito quién lo autorizó y
por qué**. Este documento reúne los tres caminos por los que el inventario puede bajar sin
una venta detrás y qué control lleva cada uno.

| Camino | Quién lo autoriza | Nota obligatoria | Dónde queda el rastro |
|---|---|---|---|
| Traslado entre tiendas | Alguien con `transferencias.aprobar` | Sí, al solicitarlo | Informe *Movimiento entre tiendas* |
| Ajuste manual de existencia | Quien tiene `inventario.ajustar` | Sí, siempre | Informe *Ajustes y mermas* |
| Conteo físico que baja existencia | Quien tiene `conteos.aplicar` | Sí, al aplicarlo | Informe *Ajustes y mermas* |

Lo que **no** entra aquí porque ya tiene su documento y su informe: ventas, compras y
devoluciones.

---

## 1. Traslado entre tiendas

Antes, crear una transferencia y enviarla era un solo paso y la mercancía salía sin que
nadie más se enterara. Ahora son cuatro estados y el stock **no se mueve hasta que alguien
autoriza**.

```
borrador  ──solicitar──▶  pendiente  ──aprobar──▶  enviada  ──recibir──▶  recibida
                              │
                              └──devolver a borrador (con motivo)
```

- `transferenciaSolicitar()` — de borrador a pendiente. Exige motivo escrito, al menos un
  producto y existencia suficiente en el origen. **No mueve stock**: solo pide permiso.
- `transferenciaEnviar()` — de pendiente a enviada. **Aquí sale el stock del origen.**
  Necesita `transferencias.aprobar`.
- `transferenciaDevolverABorrador()` — rechaza con un motivo que queda guardado.
- Recibir en destino no cambió.

**Nadie aprueba lo suyo.** `transferenciaEnviar()` rechaza la operación si quien aprueba es
el mismo usuario que la solicitó, aunque tenga el permiso. Un control que se puede saltar
uno mismo no es un control.

### Cómo se entera quien aprueba
1. **Correo** — `transferenciaAvisarAprobadores()` escribe a todos los usuarios activos con
   `transferencias.aprobar` (o super administrador) menos al solicitante. Si el correo no
   está configurado, la pantalla lo dice: *avisado por correo* y *no se pudo avisar* son
   mensajes distintos a propósito.
2. **Panel** — `modules/inventario/aprobaciones.php` lista lo pendiente con producto,
   cantidad, existencia actual del origen (en rojo si ya no alcanza), motivo y quién lo
   pidió. Aprobar o devolver se hace desde ahí.
3. **Campana** — `notif_gen_transferencias_por_aprobar()` levanta un aviso de prioridad
   alta, dirigido a `transferencias.aprobar` y a la sucursal de origen.

---

## 2. Ajuste manual de existencia

`modules/inventario/stock.php` ya exigía motivo y sigue exigiéndolo: sin texto, el ajuste
no se guarda. El motivo viaja al kardex (`movimientos_inventario.motivo`).

---

## 3. Conteo físico

El conteo era el hueco: aplicaba las diferencias y dejaba en el kardex el número del
documento («Conteo CNT-000012»), que dice de dónde salió la corrección pero no qué pasó con
la mercancía. Justo el caso de las veinte que pasan a quince.

Desde P27, al aplicar:

- Si alguna línea **baja** la existencia, la explicación es **obligatoria**. El modal la
  marca con asterisco y el servidor la vuelve a validar dentro de la transacción, contra las
  líneas reales, porque entre abrir el modal y pulsar aplicar alguien pudo cambiar lo
  contado.
- Si el conteo solo encuentra mercancía de más, la nota es opcional: no es el riesgo que se
  quería cubrir.
- La explicación se guarda en `conteos.justificacion` y se copia al motivo de **cada**
  movimiento del kardex: `Conteo CNT-000012 · Mercancía rota en el traslado`.
- Una vez aplicado, el conteo la muestra en su cabecera como constancia.

Quien cuenta y quien aplica no tienen por qué ser la misma persona: `conteos.contar` y
`conteos.aplicar` son permisos distintos, y en Importers los tiene Administración y
Gerencia de Sucursal, no quien levanta el conteo.

---

## 4. Dónde se revisa todo esto

**Informe *Ajustes y mermas*** (`modules/reportes/ajustes.php`, permiso propio
`reportes.inventario`). Mira solo los movimientos de tipo `ajuste`, `entrada` y `salida`
—los que pueden mover existencia sin documento comercial— y responde:

- cuántas unidades faltaron y cuántas aparecieron, con su costo;
- agrupado **por causa**, no por documento: recorta el prefijo `Conteo XXX · ` para que la
  misma razón sume a través de varios conteos;
- quién lo hizo y **cuántos de sus ajustes quedaron sin nota**;
- qué producto se descuadra una y otra vez (si repite mes tras mes, el problema no es el
  conteo);
- el detalle: de cuánto a cuánto, con la explicación y un enlace al conteo de origen.

Un ajuste sin nota sale arriba, en rojo, con su propio filtro. Cuentan como «sin explicación»
tanto el motivo vacío como el que solo lleva el número del conteo.

**Informe *Movimiento entre tiendas*** (`modules/reportes/transferencias.php`, permiso propio
`transferencias.ver`): qué salió de dónde, con su motivo y quién autorizó la salida, más las
rutas más transitadas y aviso de traslados varados más de siete días.

---

## Permisos

| Clave | Qué abre |
|---|---|
| `transferencias.crear` | Levantar el borrador y cargarle productos |
| `transferencias.enviar` | Mandar el borrador a aprobación (`transferenciaSolicitar`) |
| `transferencias.aprobar` | Autorizar la salida y abrir el panel de aprobaciones. **Aquí sale el stock** |
| `transferencias.recibir` | Recibirla en destino |
| `transferencias.rechazar` / `transferencias.anular` | Cerrar la que no procede |
| `inventario.ajustar` | Ajustar existencia a mano |
| `conteos.crear` / `conteos.contar` | Levantar un conteo y capturar cantidades |
| `conteos.aplicar` | Aplicar las diferencias al inventario |
| `reportes.inventario` | *Existencias por tienda* y *Ajustes y mermas* |

## Migraciones

- `database/migracion_control_salidas_p17.sql` — estados de la transferencia, motivo de
  rechazo, fechas y el permiso `transferencias.aprobar`.
- `database/migracion_justificacion_conteo_p27.sql` — `conteos.justificacion`.
