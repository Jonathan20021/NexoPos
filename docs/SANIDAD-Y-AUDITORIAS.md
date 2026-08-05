# Cumplimiento sanitario y auditorías — NexoPOS

Importers TyE recibe inspecciones de **Salud Pública (MSP / DIGEMAPS)**, **PROCONSUMIDOR**,
**Ministerio de Agricultura** e **INDOCAL**. Este módulo sostiene la evidencia que piden.

> **Lo primero, y es importante que quede claro:** a diferencia de la DGII —que publica
> formatos de archivo oficiales (606/607/608, ya implementados en `modules/finanzas/dgii.php`)—
> **ninguna de esas entidades define un formato digital oficial**. Una inspección sanitaria es
> **documental**: el inspector pide *ver* papeles y datos en el momento. Por eso aquí no hay
> «generar el archivo de Salud Pública»: hay evidencia ordenada, imprimible y exportable.

Las tres preguntas que aparecen siempre, y dónde se responden:

| Pregunta del inspector | Dónde se responde |
|---|---|
| ¿Este producto tiene registro sanitario vigente? | Reportes → **Registros sanitarios** |
| ¿Hay mercancía vencida a la venta? | Reportes → **Control de vencimientos** |
| Si un lote sale malo, ¿a quién se le vendió? | Reportes → **Trazabilidad de lote** |

Y para entregar todo junto: Reportes → **Expediente de auditoría**.

---

## 1. Piezas

| Archivo | Qué hace |
|---|---|
| `includes/sanidad.php` | Vigencias, alta de lotes, consumo FEFO, trazabilidad, descuadres |
| `database/migracion_sanidad_p13.sql` | Campos sanitarios, tablas `lotes` y `lote_movimientos`, permisos |
| `modules/inventario/lotes.php` | Pantalla operativa: identificar, bloquear, dar de baja |
| `modules/reportes/expediente_auditoria.php` | El documento consolidado para la inspección |
| `modules/reportes/registros_sanitarios.php` | Vigencia del registro de cada producto |
| `modules/reportes/vencimientos.php` | Vencidos y por vencer, con el dinero inmovilizado |
| `modules/reportes/trazabilidad.php` | Retiro del mercado: proveedor → lote → clientes |
| `modules/reportes/proveedores_sanitario.php` | Licencias y qué regulados surte cada proveedor |

## 2. El control se activa producto a producto

`productos.regulado` marca la mercancía sujeta a control sanitario.
`productos.controla_lote` añade además lote y vencimiento.

**Un producto no regulado se comporta exactamente igual que antes de este módulo.** No es una
promesa: está verificado en las pruebas (`ajustarStock` sobre un producto sin control no crea
ningún lote ni cambia nada). Así el peso operativo cae solo donde la ley lo exige.

## 3. Dónde vive la existencia

`inventario_stock` **sigue siendo la verdad** para vender y para todos los reportes de siempre.
`lotes` DESGLOSA esa existencia en los productos con `controla_lote`.

Las dos se mueven **dentro de la misma transacción**, desde `ajustarStock()`. Ese es el único
punto por donde pasa cualquier movimiento de stock del sistema, así que enganchar ahí da
trazabilidad completa en ventas, devoluciones, transferencias, conteos, compras y el escáner
de almacén — sin tocar cada módulo por separado.

Aun así, `san_descuadres()` compara ambas cifras y el Expediente lo reporta: un control
sanitario que miente es peor que no tenerlo.

## 4. FEFO, no FIFO

Se despacha primero **lo que antes vence**, no lo que antes entró.

Con fecha de caducidad es lo correcto: un lote que entró después puede vencer primero, y
sacarlo más tarde garantiza que se dañe en el almacén. El orden es
`(fecha_vencimiento IS NULL), fecha_vencimiento ASC, id ASC` — los lotes sin fecha van al final.

## 5. Las reglas duras

- **No se vende producto vencido.** Si al despachar solo queda existencia vencida, la operación
  **falla entera y revierte**. Es duro a propósito: vender vencido es lo que cierra un negocio
  en una inspección de PROCONSUMIDOR.
- **No se vende un lote bloqueado.** Bloquear es el retiro del mercado: la mercancía se queda
  en el almacén pero fuera de circulación, sin borrar nada, mientras se decide si se devuelve
  o se destruye. Exige un motivo escrito, que es lo que se le enseña al inspector.
- **Una baja SÍ puede sacar lo vencido o bloqueado.** Para eso existe: es la única forma de
  retirarlo del inventario dejando rastro en el kardex.
- **Una compra de mercancía con `controla_lote` sin número de lote se rechaza.** Dejarla pasar
  rompe la trazabilidad justo en el punto donde nace, y después ya no hay forma de recomponerla.

## 6. `SIN-LOTE`: la existencia heredada

Al **encender** `controla_lote` en un producto que ya tiene existencias, esas unidades no
pertenecen a ningún lote. `san_sembrar_lote_inicial()` las deposita en un lote llamado
`SIN-LOTE`, y los reportes las marcan como **pendientes de identificar**.

La alternativa —bloquear la venta hasta que alguien capture todos los lotes— dejaría la tienda
sin poder vender. Se identifican después desde Inventario → **Lotes y vencimientos**.

## 7. Trazabilidad

`lote_movimientos` es el libro. Una fila por cada entrada y salida de cada lote, con
`referencia_tipo`/`referencia_id` apuntando a la venta, compra o ajuste que la causó.

**Por qué una tabla y no una columna en `venta_detalles`:** una línea de venta puede consumir
DOS lotes (se acaba uno y sigue con el siguiente). Con una columna, ese caso no cabe.

`san_trazabilidad($loteId)` cruza esos movimientos con `ventas` y `clientes` y devuelve la
cadena completa: proveedor → compra → lote → facturas → clientes, con teléfono y correo.
Sin datos de contacto, «saber a quién se le vendió» no sirve para avisarle.

## 8. El lote sobrevive al cambiar de manos

Un producto trazable dejaría de serlo justo cuando más falta hace si perdiera su lote al
moverse. `san_mover_conservando_lotes()` lo evita en los casos donde la mercancía cambia de
sitio y vuelve:

- **Transferencia entre sucursales.** Al enviar, FEFO decide qué lotes salen y queda anotado.
  Al recibir, el destino recrea ESOS MISMOS lotes con su fecha de vencimiento, en vez de meter
  todo en un saco sin identificar.
- **Devolución de venta.** La mercancía vuelve al lote del que salió, con su caducidad. Hay que
  saber cuándo vence lo que se repone en el estante.
- **Rechazo o anulación de transferencia.** Los lotes regresan a su sucursal de origen.

El parámetro `$buscar` permite apuntar al documento de SALIDA cuando el de vuelta lleva otra
referencia: una devolución se registra con su propio id, pero sus lotes están guardados bajo la
venta original.

Si no hay rastro (por ejemplo, una transferencia enviada ANTES de activar el control de lote),
el resto entra sin identificar en lugar de perderse.

## 9. Avisos

Se generan con el mismo motor que el resto (`notif_gen_sanidad()` en `includes/notificaciones.php`),
sin cron:

- Registro sanitario **vencido** o producto regulado **sin registro** → crítica
- Registro **por vencer** en `SAN_DIAS_AVISO_REGISTRO` (120 días) → media.
  Son 120 y no 30 porque **una renovación ante DIGEMAPS tarda meses**.
- **Lote vencido con existencia**, por sucursal → crítica
- Lote que vence en 30 días, por sucursal → alta

## 10. Permisos

| Permiso | Para qué |
|---|---|
| `sanidad.ver` | Abrir la pantalla de lotes |
| `sanidad.editar` | Editar la ficha sanitaria de productos y proveedores |
| `sanidad.lotes` | Identificar y corregir lotes |
| `sanidad.bloquear` | Bloquear/liberar (retiro del mercado) |
| `sanidad.baja` | Dar de baja mercancía vencida |
| `reportes.sanidad` | Los cinco reportes de cumplimiento |

`sanidad.bloquear` y `sanidad.baja` **no** se conceden automáticamente en la migración: son las
dos acciones que sacan producto de circulación y valen dinero. Se otorgan a mano desde Roles.

## 11. Al extender

- Si añades un flujo que mueva stock, **usa `ajustarStock()`** y pásale el lote cuando sea una
  entrada. Si escribes directo en `inventario_stock`, rompes la trazabilidad en silencio.
- Antes de consultar cualquier tabla del módulo, comprueba `san_disponible()`: el código puede
  desplegarse antes que la migración, y el sistema debe seguir vendiendo igual mientras tanto.
