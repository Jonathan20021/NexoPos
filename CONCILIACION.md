# Conciliación bancaria

Cruza los movimientos del sistema contra el **estado de cuenta** que emite el banco.
Se accede en **Finanzas → Conciliación bancaria** con el permiso `conciliacion.ver`
(`conciliacion.conciliar` para marcar, `conciliacion.cerrar` para cerrar el corte).

---

## Qué se concilia y qué no

Solo las cuentas con estado de cuenta: **banco, tarjeta y transferencia**.

El **efectivo no se concilia aquí**: su arqueo es el **cierre de caja**, que ya existe
y cuenta el dinero físico. Meter el efectivo en esta pantalla duplicaría esa lógica y
daría dos verdades distintas para lo mismo.

## La aritmética

Es la conciliación clásica. «En tránsito» es lo que ya está en libros pero el banco
todavía no refleja, es decir, los movimientos **aún no marcados**:

```
  Saldo según el banco                      (del estado de cuenta)
+ Depósitos en tránsito                     (el banco aún no los acreditó)
− Pagos en tránsito                         (el banco aún no los debitó)
= Saldo bancario ajustado   →  debe ser igual al Saldo según libros
```

Si queda **diferencia**, falta registrar un movimiento o alguno está mal marcado.
Esa diferencia es justamente lo que hay que investigar: es el propósito del ejercicio.

## Decisiones que conviene conocer

**El saldo en libros se recalcula a la fecha de corte**, como
`saldo_inicial + movimientos hasta el corte`. **No se usa `cuentas_financieras.balance`**:
ese es el saldo de *hoy*, y una conciliación siempre es a una fecha pasada.

**No se cierra un corte que no cuadra.** Una conciliación con diferencia no está
conciliada; cerrarla escondería el problema. El botón solo aparece con diferencia cero.

**Al cerrar, los movimientos marcados quedan bloqueados** (`transacciones.conciliacion_id`).
Ya no se pueden desmarcar: un período conciliado es un hecho cerrado. Los cortes
posteriores solo toman los movimientos que aún no pertenecen a ninguno.

**Un corte por cuenta y fecha** (`UNIQUE (cuenta_id, fecha_corte)`): no se puede
cerrar dos veces el mismo período.

## El backfill de `saldo_inicial`

Las cuentas creadas **antes** de que existiera `saldo_inicial` (ver
`migracion_finanzas_p1.sql`) guardaron su saldo de apertura dentro de `balance`, y la
columna nueva quedó en `0`. Como el saldo en libros se calcula desde la apertura, esas
cuentas habrían dado un saldo falso.

`migracion_conciliacion_p1.sql` lo corrige despejando la apertura por definición:

```
apertura = balance − movimientos
```

Es idempotente: en una cuenta ya correcta el despeje da `0` y no cambia nada.

## Si el balance no cuadra con los movimientos

La pantalla avisa cuando `saldo_inicial + movimientos ≠ balance`. Suele significar que
alguien tocó el saldo a mano, porque todo movimiento debería pasar por
`registrarTransaccion()`. **La conciliación usa los movimientos**, que son la fuente de
verdad; el aviso está para que se investigue el desfase.

## Puesta en marcha

1. Aplica [`database/migracion_conciliacion_p1.sql`](database/migracion_conciliacion_p1.sql)
   (idempotente; las instalaciones nuevas ya nacen con el esquema).
2. Verifica el **saldo inicial** de tus cuentas de banco en *Finanzas → Cuentas*.
3. Concilia con el estado de cuenta en la mano: marca lo que el banco ya reflejó,
   escribe el saldo del banco y cierra el corte cuando la diferencia sea cero.
