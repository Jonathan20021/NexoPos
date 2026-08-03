# Cuentas por pagar, monedas y cotizaciones

Tres piezas que se construyeron juntas porque se necesitan entre ellas: un
importador compra en dólares a crédito y cotiza en dólares antes de vender.

Migración: [`migracion_cxp_monedas_cotizaciones_p11.sql`](../database/migracion_cxp_monedas_cotizaciones_p11.sql)
Motores: [`includes/cxp.php`](../includes/cxp.php) · [`includes/monedas.php`](../includes/monedas.php) · [`includes/cotizaciones.php`](../includes/cotizaciones.php)

---

## 1. Cuentas por pagar

Antes una compra a crédito solo podía estar **pagada o no pagada** (una fecha).
No se podía abonar RD$50.000 de una factura de RD$200.000, que es como se paga
de verdad a un suplidor.

Ahora cada compra lleva su `saldo` y cada proveedor su `balance`, exactamente
igual que ya hacían las ventas y los clientes. La pantalla está en
**Inventario → Cuentas por Pagar**, con antigüedad de la deuda por tramos.

### El defecto que se corrigió de paso

Toda compra registraba un gasto de efectivo el día de la compra, **aunque fuera
a 90 días**. El flujo de caja mostraba salidas que no habían ocurrido.

```
ANTES:  compra a crédito → sale el dinero hoy (falso)
AHORA:  compra a crédito → nace la deuda
        pago             → sale el dinero (verdad)
```

### Dónde entra cada movimiento

| Movimiento | ¿Gasto operativo? | ¿Flujo de caja? | Por qué |
|---|---|---|---|
| Compra al contado | no | sí | Es inventario, ya pesa en el costo de ventas |
| Compra a crédito | no | no | Todavía no salió dinero |
| Pago a proveedor | **no** | sí | Es la misma mercancía: contarla aquí sería duplicarla |
| Diferencia cambiaria | **sí** | no | Gasto financiero real; el dinero ya salió con el pago |

Esas cuatro filas son las que hacen `rep_where_gastos()` y `rep_where_flujo()`.
Si añades un movimiento nuevo, decide en qué fila cae **antes** de escribirlo.

---

## 2. Monedas

**La contabilidad sigue en pesos.** Todos los importes de `transacciones`,
reportes, DGII e IT-1 son RD$ igual que antes. Ningún reporte existente cambia
de resultado.

Lo que se añade es que los documentos guardan **las dos caras**: el importe en su
moneda y el equivalente en pesos a la tasa de ese día. Convertir al vuelo en los
reportes habría hecho que el pasado cambiara cada vez que se mueve el dólar.

La tasa se teclea a mano en **Administración → Monedas y tasa**. No hay API
gratuita del Banco Central que se pueda consultar sin permiso, y una tasa sacada
de una web cualquiera puede descuadrar una contabilidad.

### La diferencia cambiaria, que es donde está el truco

Una deuda en dólares **no es una deuda en pesos congelada**:

```
Debes         US$600  anotados a RD$58  →  RD$34.800 en libros
Pagas         US$600  con el dólar a 62 →  RD$37.200 salen del banco
Deuda saldada: completa. No pagaste de más.
Los RD$2.400 de diferencia son una PÉRDIDA CAMBIARIA.
```

Por eso `cxp_registrarPago()` lleva tres cifras que solo coinciden cuando no hay
moneda extranjera:

- `reduccion` — cuánto baja la deuda en libros (a la tasa de la compra)
- `salida` — cuánto dinero sale del banco (a la tasa de hoy)
- `diferencia` = salida − reduccion

La primera versión de este código rechazaba ese pago con «el pago supera lo que
se le debe». Lo encontró la prueba del ciclo completo, no la revisión a ojo.

---

## 3. Cotizaciones

Documento previo a la factura, con vigencia, PDF de marca y envío por correo.
**Ventas → Cotizaciones**.

### Dos reglas

**Una cotización no toca nada.** No mueve stock, no genera asientos, no reserva
mercancía. Es una oferta.

**El precio cotizado se respeta.** Si el precio de lista sube entre la cotización
y la factura, el cliente paga lo que se le prometió.

### Cómo se factura

No duplica ni una línea de la lógica de ventas: arma el carrito y llama a
`registrarVentaPOS()`, la misma función del POS. Así una factura nacida de una
cotización es idéntica a cualquier otra: NCF, kardex, caja, comisiones.

Para respetar el precio pactado se añadió `$ctx['precios_pactados']` a esa
función. **Solo lo activa código del servidor** que leyó los precios de la
cotización guardada en la base; el navegador nunca puede fijar un precio. Sin esa
marca, `registrarVentaPOS()` sigue recalculando todo contra el catálogo como
siempre.

Una línea escrita a mano (sin producto del catálogo) no se puede facturar: el
sistema lo dice al intentarlo, en vez de inventar un producto.

### PDF

Mismo motor (Dompdf) y misma hoja de estilos que la factura, vía
`pdf_brand_header()` y `pdf_css()`. Se añadió `pdf_bytes()` para poder adjuntar
el **mismo** documento a un correo: `pdf_render()` termina con `exit` y no
servía. Separar el armado del envío evita tener dos PDF distintos del mismo papel.

---

## Qué mirar si algo no cuadra

- **La deuda de un proveedor no coincide con sus facturas** →
  `cxp_recalcularBalance($proveedorId)` la recalcula desde las compras.
- **El flujo de caja muestra de más** → revisa que el movimiento nuevo esté en
  la tabla de arriba y en `rep_where_flujo()`.
- **Una compra a crédito no aparece en Cuentas por Pagar** → tiene que tener
  `forma_pago = 4` (compra a crédito, código DGII) y sin fecha de pago.
- **Una cotización en dólares factura por un importe raro** → se convierte con la
  tasa **de la cotización**, no la de hoy: es el precio que se prometió.
