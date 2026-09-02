# Nómina, TSS y prestaciones laborales

Todo lo que toca el trabajo de quien lleva la nómina: el cálculo quincenal, la
TSS, el volante que se le entrega a cada persona, la regalía pascual y la
liquidación de quien sale.

---

## 1. El cálculo de la quincena

`includes/nomina.php` · `calcNominaRD()`

- **AFP 2.87% y SFS 3.04%** sobre la base cotizable del período, con los topes de
  la Ley 87-01 si están encendidos (ver §2).
- **ISR sobre el equivalente MENSUAL**, prorrateado al período. La escala es
  anual y progresiva: anualizar una quincena (×12 sobre medio mes) da la mitad de
  la renta real y baja de tramo a casi todo el mundo. Se saca el ISR del mes
  completo y recién ahí se prorratea.
- **Base cotizable** = sueldo del período + horas extra + otras remuneraciones +
  reembolso + vacaciones diferencial + incentivos − descuento de días.
  La **prima vacacional queda fuera** (criterio del contador del cliente,
  pendiente de confirmar); se paga en el neto sin tocar AFP, SFS ni ISR.
- **La cuota de préstamo se cobra hasta donde alcance el sueldo.** Sin ese tope,
  una cuota mayor que lo devengado daba un neto negativo. Lo que no cupo se
  devuelve aparte para que la pantalla lo avise en vez de darlo por cobrado.

### Dos correcciones sobre la hoja del cliente
Su Excel tiene dos fórmulas que no hacen lo que sus propias columnas dicen. Hoy
no se nota porque esas columnas están en cero; el día que se usen, sí:

- `U (neto) = N − S` **ignora la columna T**, el préstamo: con su fórmula un
  préstamo se registra y no se descuenta. Aquí sí se resta.
- `N` ignora la columna G, la prima vacacional, y `U` tampoco la suma: con su
  fórmula la prima **no se pagaría nunca**. Aquí se paga.

En cuanto alguien tenga préstamo o prima, el sistema dará distinto que su hoja.
Es lo correcto y hay que avisárselo al contador.

---

## 2. TSS

`includes/tss.php` · `modules/rrhh/tss.php`

Tasas, topes y salario mínimo cotizable viven en `tss_parametros`, con vigencia
por fecha: la nómina de marzo sigue cotizando con el mínimo de marzo aunque hoy
sea otro. Ante la TSS eso no se reescribe.

**El tope es por régimen y es MENSUAL** — SFS 10 salarios mínimos, AFP 20, SRL 4,
INFOTEP sin tope. En una nómina quincenal hay que partirlo por el mismo factor
que el sueldo, o a un sueldo alto se le cotizaría el doble: media base contra un
tope entero no lo corta nunca.

> **Pendiente del contador.** `salario_minimo_cotizable` está en 0 y
> `aplicar_topes` en 0, así que **hoy no se aplica ningún tope**: el sueldo más
> alto del padrón cotiza completo. La pantalla simula el impacto antes de dejar
> encenderlos —nadie debería cambiar lo que se le retiene a 57 personas sin ver
> primero el número— y la campana lo recuerda.

La pantalla tiene cuatro pestañas: parámetros (con su historial de vigencias),
declaración del mes (base cotizable y aportes de las dos partes, exportable),
**pago del mes** (§3) y novedades (ingresos, salidas y cambios de salario).

---

## 3. El pago del mes — y el 20% del costo que no salía en el resultado

Una nómina mueve **tres** cosas y el sistema solo registraba una:

| | Qué es | Cuándo sale el dinero |
|---|---|---|
| **Neto** | Lo que cobra la gente | Al pagar la nómina — se registraba |
| **Retenciones** | AFP, SFS, ISR y per cápita: la empresa las guarda y las remite | Al pagar la TSS y el IR-3 — **no se registraba** |
| **Aporte patronal** | SFS 7.09%, AFP 7.10%, riesgos 1.10%, INFOTEP 1% | Al pagar la TSS — **no se registraba** |

Medido sobre la segunda quincena de julio de 2026 con el padrón real:

    bruto                950,980.83
    aporte patronal      154,914.87
    COSTO REAL         1,105,895.70
    en el resultado      877,721.39   (solo el neto)
    FALTABA              228,174.31   (20.6%)

`transacciones` es un libro de **caja**: el gasto entra cuando el dinero sale.
Así que no hacía falta inventar un asiento de devengo — lo que faltaba era la
pantalla donde se registra que se pagó. Es la pestaña **Pago del mes** de la
pantalla de TSS: calcula lo que se debe a la Tesorería y a la DGII por separado
(son dos pagos con dos plazos), deja registrar cada uno contra una cuenta, y ahí
es donde el costo entra al resultado, repartido en tres categorías legibles:
*Nómina*, *Seguridad Social (TSS)* y *Retenciones e impuestos*.

Dos comprobaciones que hace la pantalla:

- **Compara lo retenido con lo declarado.** Lo retenido es lo que las nóminas le
  quitaron de verdad a la gente; lo declarado es lo que sale de aplicar los
  parámetros sobre la base del mes completo. Normalmente coinciden, pero el tope
  de la TSS es **mensual** y la nómina lo aplica quincena a quincena: en cuanto
  los topes se enciendan, un sueldo alto puede dar distinto. A la Tesorería se le
  paga lo declarado y la diferencia sale en pantalla, no escondida.
- **No deja pagar un mes sin nómina confirmada.** `tssDeclaracionMes()` cae al
  padrón cuando no encuentra nóminas —para poder simular— y sin ese corte se
  podía registrar el pago de un mes de hace tres años calculado con los sueldos
  de hoy, y meter ese gasto inventado en el resultado.

Un pago por período y tipo, con índice único: pagar dos veces la TSS de agosto es
un error de dedo, no un caso de uso.

---

## 4. Volante de pago

`modules/rrhh/volante.php` — `?nomina=<id>` para todos, `&empleado=<id>` para uno.

Dos por hoja, con la línea de corte. Ingresos, deducciones, neto, el aporte que
además pone la empresa a la TSS, y línea de firma.

**Los totales del volante no son las columnas de la tabla, y es a propósito.**
`total_ingresos` guarda la base cotizable, que deja fuera la prima vacacional, y
`total_deducciones` no incluye la cuota de préstamo. Con esas dos, «total
ingresos − total deducciones» no da el neto que la persona cobró. El volante suma
los conceptos de verdad y cuadra por construcción:

    (base + prima) − (retenciones + préstamo) = neto

---

## 5. Regalía pascual

`includes/regalia.php` · `modules/rrhh/regalia.php`

- Se paga **a más tardar el 20 de diciembre** (art. 219).
- Es **una duodécima parte del salario ordinario devengado en el año calendario**.
  Lo proporcional sale solo: se divide entre doce lo que de verdad ganó.
- **No paga ISR** (art. 222) **ni cotiza a la TSS**. Su nómina se guarda con AFP,
  SFS e ISR en cero: no es un olvido.

| Entra en «salario ordinario» | No entra |
|---|---|
| Sueldo del período | Horas extra — el art. 220 excluye el salario extraordinario |
| Comisiones | Reembolsos — no son salario |
| Vacaciones (diferencial) | Prima vacacional, bonificaciones, otros ingresos |

Es un criterio, no un dogma: la pantalla lo dice y deja corregir el monto de cada
persona antes de generar nada.

### Se mide en DÍAS, no en meses
Un sistema que arrancó a mitad de año no tiene las nóminas de enero a junio y la
gente sí las cobró. Además, un mes puede tener **solo una quincena** cargada: si
«hay nómina en julio» marcara el mes como cubierto, se perdería la otra mitad sin
que nadie lo viera. Cada nómina cubre los días de su período y lo que queda
descubierto se completa con el sueldo del padrón, prorrateado. Cada fila dice
cuánto vino de cada fuente.

### La fecha de ingreso importa
La regalía se devenga desde el día que la persona entró. Si el padrón se cargó
con una fecha marcador —58 empleados con el mismo día— a quien lleva cinco años
se le calcularía media regalía. La pantalla lo avisa en rojo y la campana también.

La regalía **no se edita desde la pantalla de Nómina**: «actualizar contra el
padrón» la recalcularía como una quincena corriente y la destruiría.

---

## 6. Prestaciones laborales

`includes/prestaciones.php` · `modules/rrhh/prestaciones.php` ·
`modules/rrhh/prestacion_doc.php`

Una liquidación se negocia y se firma; el sistema no la decide. Lo que hace es
calcular la cifra que sale de aplicar la ley a los datos que ya están en base,
dejar constancia de qué escala se aplicó y **dejar corregir cada renglón**.

**Salario diario** = salario mensual ÷ 23.83 (divisor del Ministerio de Trabajo).

| Concepto | Escala |
|---|---|
| **Preaviso** · art. 76 | 3-6 meses: 7 días · 6-12 meses: 14 · 1 año o más: 28 |
| **Cesantía** · art. 80 | 3-6 meses: 6 días · 6-12 meses: 13 · 1-5 años: 21 por año · más de 5: 23 por año |
| **Vacaciones** · art. 177 | 1-5 años: 14 días laborables · 5 años o más: 18. Proporcional al año en curso |
| **Regalía proporcional** · art. 219 | Una duodécima del salario ordinario del año hasta la salida |

La fracción de año en la cesantía se paga proporcional, y el tramo se aplica a
**todos** los años, no en escalones: siete años son 23 × 7, no 21 × 5 + 23 × 2.
Es la lectura literal del art. 80 numeral 5 y la del calculador oficial. Si el
abogado laboral del cliente usa otra, el número se corrige en pantalla.

Preaviso y cesantía **solo se pagan** cuando la salida la provoca la empresa o
cuando la justicia la equipara a eso (desahucio del empleador, despido
injustificado, dimisión justificada). Vacaciones, regalía y salario pendiente se
pagan siempre.

El saldo del préstamo viene precargado en las deducciones: la autorización de
descuento que el trabajador firmó permite compensarlo con la liquidación.

**El documento no lleva ninguna frase de renuncia a derechos**, a propósito. El
recibo hace constar un pago; lo que se renuncie o no es materia de un acuerdo que
redacta un abogado, no una plantilla del sistema.

---

## 7. Permisos

| Clave | Qué abre |
|---|---|
| `rrhh_nomina.ver` / `.procesar` / `.pagar` | Nómina y regalía |
| `tss.ver` / `tss.configurar` / `tss.pagar` | TSS: declaración, novedades, parámetros y el pago del mes |
| `reportes.nomina` | *Resumen de nómina* y *Costo de la plantilla*, sin abrir el resto de contabilidad |
| `prestaciones.ver` / `.crear` / `.pagar` / `.anular` | Liquidaciones |

> **Cuidado con `reportes.ver`.** Es el permiso de ENTRAR al Centro de Reportes,
> no el de ver la utilidad. `modules/finanzas/reportes.php` —el estado de
> resultados de la empresa— se guardaba con él, así que cualquiera a quien se le
> diera acceso al hub para un informe suelto se llevaba de paso el P&L completo.
> Ahora pide `reportes.ejecutivo` o `reportes.finanzas`.

---

## 8. Lo que avisa la campana

`notif_gen_nomina_calendario()` en `includes/notificaciones.php`:

| Alerta | Se resuelve cuando |
|---|---|
| Topes de cotización apagados por falta del salario mínimo cotizable | Se configura y se encienden |
| Regalía pascual sin generar (desde 75 días antes del 20/12) | Se genera y se confirma |
| TSS del mes que cerró, con su autodeterminación lista | Cambia el mes |
| TSS e ISR de un mes cerrado sin pagar, con su importe | Se registra el pago |
| Cédulas que no pasan el dígito verificador | Se corrigen en la ficha |
| Empleados con la misma fecha de ingreso (marcador de carga) | Se corrigen las fechas |

---

## 9. Migraciones

- `migracion_permiso_reportes_nomina_p28.sql` — permiso `reportes.nomina`.
- `migracion_regalia_p29.sql` — tipo `regalia` en `nominas`.
- `migracion_prestaciones_p30.sql` — tabla `prestaciones` y sus permisos.
- `migracion_tss_p22.sql` — parámetros y novedades de la TSS.
- `migracion_tss_pagos_p31.sql` — tabla `tss_pagos`, categorías de gasto y `tss.pagar`.
