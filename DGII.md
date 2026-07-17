# Reportes DGII — Formatos de Envío de Datos

NexoPOS genera los tres formatos que la Norma General 07-2018 exige remitir
mensualmente, **a más tardar el día 15 del mes siguiente**:

| Formato | Qué reporta | Columnas | Origen de los datos |
|---|---|---|---|
| **606** | Compras de bienes y servicios | 23 | `compras` con NCF, estado ≠ anulada |
| **607** | Ventas de bienes y servicios | 23 | `ventas` con NCF (≠ anulada) + notas de crédito **B04** de `devoluciones` |
| **608** | Comprobantes anulados | 3 | `comprobantes_anulados` |

Se accede en **Finanzas → Reportes DGII**. Requiere el permiso `dgii.ver`
para consultar y `dgii.generar` para descargar el archivo.

Además, el sistema arma el resumen del **IT-1** (Declaración Jurada del ITBIS)
derivado de esos mismos datos — ver la sección [IT-1](#it-1--declaración-jurada-del-itbis).

---

## Dos supuestos que debes validar antes del primer envío

Los instructivos oficiales de la DGII definen con precisión las columnas y sus
valores permitidos, pero **no documentan la estructura del archivo TXT**: dan por
sentado que llenas su plantilla de Excel con macros y que esa macro lo genera.

Este módulo asume dos cosas que no pudimos confirmar en un documento oficial:

1. **El separador de campos es el pipe `|`.**
2. **El «Tipo de Bienes y Servicios» del 606 se escribe con dos posiciones** (`01`…`11`).

Por eso, **antes del primer envío real**, pasa el archivo generado por la
herramienta de **pre-validación** de la Oficina Virtual de la DGII. Una vez que
la DGII te acepte un período, los siguientes salen con la misma estructura.

Si consigues un TXT ya aceptado (tu contador suele tenerlo), compáralo contra el
que genera el sistema. Ese es el mejor chequeo posible.

---

## Pedidos de la tienda en línea

Un pedido de la tienda **no es una venta**: no descuenta inventario ni emite NCF.
Cuando el cliente llega a retirar, se pulsa **Facturar** en *Pedidos en línea* y el
sistema crea la venta real: emite el NCF, descuenta el stock, registra el cobro en
la caja abierta y asienta el ingreso en Finanzas. Desde ese momento la venta entra
al 607 como cualquier otra.

Se factura al **precio que se le cotizó al cliente**, no al precio actual del
catálogo: si el precio subió entre el pedido y el retiro, no se le cobra de más.

## Reglas de negocio implementadas

- **Los reportes son por RNC, no por sucursal.** El archivo siempre incluye todas
  las sucursales de la empresa. El filtro de sucursal de la pantalla es solo para
  revisar en pantalla y jamás afecta lo que se exporta.
- Una venta **anulada** sale del 607 y entra al 608. Nunca aparece en ambos.
- Solo se reportan comprobantes con **NCF realmente emitido**. Una venta sin NCF
  no entra al 607, y una venta anulada sin NCF no entra al 608.
- El **desglose de cobro del 607** (columnas 17-23) se deriva de `venta_pagos`
  cruzado con `metodos_pago.dgii_tipo_pago`. No se duplica en `ventas`.
- El **monto facturado del 606** se separa en bienes y servicios según el campo
  `tipo` de cada producto de la compra.
- El **ITBIS por adelantar** (606, columna 15) se calcula como
  `ITBIS facturado − ITBIS llevado al costo`, como indica el instructivo.

## Pre-validación propia

Antes de permitir la descarga, el sistema replica las validaciones del instructivo
y bloquea el botón si encuentra errores:

- El NCF debe tener 11, 13 o 19 posiciones alfanuméricas.
- La empresa debe tener RNC configurado (Configuración → Empresa).
- Máximo 10,000 registros por archivo.
- **606**: el proveedor necesita RNC o cédula; el tipo de bien/servicio y la forma
  de pago deben estar en el catálogo; si informas retenciones de ITBIS o ISR,
  la Fecha de Pago es obligatoria (casilla 7); bienes + servicios debe cuadrar con
  el monto facturado; el ITBIS llevado al costo no puede superar al facturado.
- **607**: el desglose de cobro debe sumar exactamente el total de la venta; si
  informas retenciones, la Fecha de Retención es obligatoria; un comprobante de
  crédito fiscal exige el RNC del cliente.
- **608**: el tipo de anulación debe ser uno de los 10 códigos oficiales.

Las advertencias (por ejemplo, consumidor final sin documento) no bloquean la
descarga, pero se muestran para que las revises.

---

## IT-1 — Declaración Jurada del ITBIS

El IT-1 **no es un archivo de envío**: es la declaración que se llena en la Oficina
Virtual. Por eso el sistema no genera un TXT, sino el **resumen del período** para
transcribirlo. Se accede en **Finanzas → IT-1 · ITBIS** (permiso `dgii.ver`) y se
puede exportar en PDF para el contador.

Las cifras se derivan de **las mismas filas que se declaran en el 606 y el 607**
(`dgiiIt1()` llama a `dgiiFilas606/607`), así que el IT-1 **siempre cuadra con lo
que se envió**. No reimplementa las reglas de inclusión.

| Cifra | De dónde sale |
|---|---|
| Operaciones gravadas / exentas | `venta_detalles`: la **línea** es la fuente de verdad, porque una venta puede mezclar productos gravados y exentos |
| Total de operaciones | gravadas + exentas (equivale a `subtotal − descuento` de las ventas del 607) |
| ITBIS facturado (**débito fiscal**) | `ventas.itbis` de las ventas del 607 |
| ITBIS adelantado (**crédito fiscal**) | 606, columna 15: **facturado − llevado al costo** |
| Retenciones / percepciones | `ventas.itbis_retenido_terceros`, `ventas.itbis_percibido`, `compras.itbis_retenido` |

**El descuento se prorratea.** Vive a nivel de venta, no de línea; si no se
prorrateara sobre la base de cada línea, se declararían operaciones más altas que
las reales. Las **muestras** entran con subtotal 0, así que no suman operaciones
por construcción.

**Cómo se llega al monto a pagar:**

```
débito − crédito = diferencia          (negativa = saldo a favor)
diferencia − retenido por terceros − percibido + retenido a proveedores = a pagar
```

Lo que tus clientes te retuvieron ya lo enteraron ellos: se acredita. Lo que tú le
retuviste a un proveedor lo debes enterar tú: se suma.

### Devoluciones y notas de crédito (B04)

Una **devolución** rebaja el ITBIS facturado mediante una **nota de crédito (B04)**,
y el sistema **la emite automáticamente**: al devolver una venta que llevaba NCF, se
consume la secuencia B04, se guarda el NCF de la venta corregida (`ncf_modificado`) y
se registra el desglose base/ITBIS de lo devuelto.

- La B04 **entra en el 607** como una fila propia que referencia el NCF original, y
  **hereda el tipo de comprobante de la venta** (así, si corrige un crédito fiscal,
  exige el RNC del cliente igual que la factura).
- La B04 **baja el débito del IT-1** por su ITBIS (probado: el débito cae exactamente
  el ITBIS devuelto). El hueco anterior quedó cerrado.

Configura la secuencia B04 en **Configuración → Comprobantes (NCF)** con el rango real
que asignó la DGII. Si la venta original **no tenía NCF** (o no había secuencia B04
activa), la devolución se registra igual pero **sin** nota de crédito; el IT-1 lo avisa
para que ese caso se maneje con el contador.

---

## Catálogos oficiales

Todos viven en [`includes/dgii.php`](includes/dgii.php) y son la única fuente.
No los dupliques en otros módulos.

**Tipo de identificación** — 1 RNC · 2 Cédula · 3 Pasaporte (solo 607).

**Tipo de bienes y servicios (606, col. 3)** — 1 Gastos de personal · 2 Trabajos,
suministros y servicios · 3 Arrendamientos · 4 Gastos de activos fijos ·
5 Gastos de representación · 6 Otras deducciones admitidas · 7 Gastos financieros ·
8 Gastos extraordinarios · 9 Compras y gastos del costo de venta ·
10 Adquisiciones de activos · 11 Gastos de seguros.

**Forma de pago (606, col. 23)** — 1 Efectivo · 2 Cheques/Transferencias/Depósito ·
3 Tarjeta crédito/débito · 4 Compra a crédito · 5 Permuta · 6 Notas de crédito · 7 Mixto.

**Tipo de retención en ISR (606, col. 17)** — 1 Alquileres · 2 Honorarios por
servicios · 3 Otras rentas · 4 Otras rentas (presuntas) · 5 Intereses a personas
jurídicas residentes · 6 Intereses a personas físicas residentes · 7 Proveedores
del Estado · 8 Juegos telefónicos · 9 Ganadería de carne bovina.

**Tipo de ingreso (607, col. 5)** — 1 Operaciones (no financieros) · 2 Financieros ·
3 Extraordinarios · 4 Arrendamientos · 5 Venta de activo depreciable · 6 Otros.

**Desglose de cobro (607, col. 17-23)** — 1 Efectivo · 2 Cheque/Transferencia/Depósito ·
3 Tarjeta débito/crédito · 4 Venta a crédito · 5 Bonos o certificados de regalo ·
6 Permuta · 7 Otras formas.

> Ojo: los códigos 1-4 coinciden entre el 606 y el 607, pero **del 5 en adelante no**.
> La conversión está en `dgiiTipoPago607a606()`; no la reimplementes a mano.

**Tipo de anulación (608, col. 3)** — 1 Deterioro de factura preimpresa ·
2 Errores de impresión · 3 Impresión defectuosa · 4 Corrección de la información ·
5 Cambio de productos · 6 Devolución de productos · 7 Omisión de productos ·
8 Errores en secuencia de NCF · 9 Por cese de operaciones · 10 Pérdida o hurto de talonarios.

---

## Puesta en marcha

1. Aplica [`database/migracion_dgii.sql`](database/migracion_dgii.sql) (idempotente,
   funciona en MySQL 8 y MariaDB). Las instalaciones nuevas ya nacen con el esquema.
2. Configura el **RNC de la empresa** en Configuración → Empresa. Sin él no se
   genera ningún archivo.
3. Revisa el mapeo `dgii_tipo_pago` de tus métodos de pago si creaste alguno propio.
   El valor por defecto es `7` (Otras formas), que probablemente no es lo que quieres.
4. Verifica que tus proveedores tengan RNC o cédula. Sin eso, sus compras no
   pasan la validación del 606.
5. Configura la secuencia **B04 (nota de crédito)** en Configuración → Comprobantes
   con el rango real que asignó la DGII. Sin ella, las devoluciones no emiten nota de
   crédito y su ITBIS no baja el débito automáticamente.

## Si no tuviste operaciones en el mes

La DGII exige remitir los tres formatos **de manera informativa** aunque no haya
operaciones. El sistema genera el archivo con el encabezado y cero registros.
