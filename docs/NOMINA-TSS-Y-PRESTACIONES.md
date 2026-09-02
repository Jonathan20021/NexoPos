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

### Una nómina por período
Nada impedía procesar la quincena del 1 al 15 dos veces, y si las dos se
confirmaban, **todo lo que suma por período las contaba a las dos**: la
declaración de la TSS, el aporte patronal, el costo del personal en el
resultado. Un error de dedo caro y silencioso.

Ahora el período idéntico se bloquea nombrando la que ya existe. El que solo se
**solapa** se avisa después de crearla —una corrida extra sobre parte del mes sí
puede ser lo que se quiere— y dice quién cotizará doble.

Para liberar un período hay que **eliminar** la nómina: `nominas.estado` es
borrador/procesada/pagada y no existe «anulada», así que mandar a anularla sería
mandar a hacer algo imposible.

### El Excel del contador
`nominaExportarExcel()` reproduce las 23 columnas de la hoja que el contador ya
conoce, agrupadas por sucursal y con su fila de totales. Cédulas y cuentas van
como **texto**: de ir como número pierden el cero inicial, que es justo lo que le
pasó al Excel del cliente en dos cuentas.

Las columnas D («Sueldo Mensual») y E («Sueldo Quincenal») salen las dos de la
línea de nómina, que es histórica. Antes la D salía de `empleados.salario` —el
sueldo de HOY— así que bastaba un aumento para que reexportar una quincena ya
cerrada diera D con el sueldo nuevo y E con el viejo: 170,000 contra 75,000, sin
relación entre sí. Un documento cerrado que se reexporta distinto no vale como
respaldo, así que desde la P32 el sueldo mensual se **congela** en
`nomina_detalles.salario_mensual` al generar.

El archivo cuadra por dentro con la fórmula corregida
`U = N + G − S − T`, distinta de la del Excel del cliente (ver abajo).

### El archivo del banco
`nominaExportarBanco()` —botón «Archivo banco» de la nómina— arma el CSV de
transferencias. Entra quien cobra **por transferencia** y tiene cuenta; el resto
sale **nombrado en el pie**, nunca callado:

- `REVISAR: la cuenta no tiene 11 dígitos` — dos cuentas del padrón perdieron el
  cero inicial dentro del propio Excel del cliente, por venir guardadas como
  número.
- `FUERA DEL ARCHIVO: sin cuenta, se pagan aparte`.
- `FUERA DEL ARCHIVO: no cobran por transferencia` — y si además tienen una
  cuenta guardada lo dice, porque suele ser un método mal puesto en la ficha.

Ese último caso era un riesgo real: el archivo se armaba mirando solo si había
cuenta, así que a quien le cierran la cuenta y se le pasa a efectivo sin borrar
el número viejo entraba en el archivo **y** cobraba en caja. Se le pagaba dos
veces y no se veía hasta cuadrar el mes.

### Quien entra o sale a mitad de período
Al generar la nómina se ponía la jornada completa a todo el mundo, incluido quien
se incorporó a mitad de quincena. Medido con el padrón real: alguien de 50.000
que entra el día 11 de una quincena del 1 al 15 cobraba los **25.000 completos**
en vez de los **8.333** que trabajó. Dieciséis mil seiscientos de más por persona,
salvo que quien lleva la nómina se acordara de corregir los días fila por fila.

`nominaDiasDelPeriodo()` prorratea por días **naturales** —que es lo que mide la
permanencia— y devuelve el resultado en los días base del convenio del cliente,
que es la unidad con la que calcula `calcNominaRD()`:

    días = díasBase × (días naturales trabajados / días naturales del período)

Quien estuvo el período entero recibe `díasBase` exacto, así que la quincena
corriente no cambia ni un centavo. Y la pantalla **lo dice** al terminar,
nombrando a quién se le prorrateó: pagar medio período es una decisión que hay
que ver y poder corregir en la rejilla, no algo que ocurra por lo bajo.

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
**pago del mes** (§3) y novedades.

### Las tasas van validadas
Los campos se teclean en **porcentaje** (7.09) y se guardan en tanto por uno
(0.0709). Saltarse la coma —709— se guardaba tal cual: una tasa del 709%.
Comprobado sobre el padrón real, la quincena siguiente le retiene a un sueldo de
29,998 unos **43,047 de AFP y 45,596 de SFS sobre una base de 14,999**, y el neto
de las 57 personas queda en **cero**, sin un aviso y sin nada raro en pantalla.

`tssValidarParametros()` rechaza cualquier tasa fuera de [0, 25%] —ninguna de la
Ley 87-01 pasa del 7.10%, y ese margen generoso atrapa igual el error de
multiplicar por cien—, los topes fuera de [0, 100] salarios mínimos y un mínimo
cotizable de siete cifras. Una tasa en **cero** sí se guarda —un régimen puede
desaparecer por ley— pero avisa en voz alta: dejar de retenerle a la plantilla
entera no puede pasar en silencio.

### Las novedades se anotan solas
`tssNovedad()` existía desde la P22 y **nadie la llamaba**: la pestaña deducía
ingresos y salidas del padrón y no veía jamás un cambio de salario, que es
justamente la novedad que cambia lo que se cotiza. Ahora la ficha del empleado
las anota al guardar — alta, cambio de salario (con el antes y el después),
salida, reingreso y licencia — y lo dice en pantalla.

Para eso hacía falta que la ficha guardara la **fecha de salida**, que el
formulario no tenía. De ella cuelgan tres cosas: la última quincena que sí
corresponde cobrar (`nomina.php` la exige expresamente: «solo si consta su fecha
de salida»), la novedad de la TSS y la liquidación de prestaciones. Sin ella,
marcar a alguien inactivo lo borraba de la nómina en curso sin dejar rastro de
cuándo se fue.

### Qué nómina entra en la declaración de un mes
Entra toda la que **solape** el mes, y su importe se reparte por los días que
caen dentro (`tssLineasDelMes()`). Una quincena normal cae entera y se reparte al
100%, así que el caso corriente no cambia ni un centavo.

Antes se buscaban las que caben **enteras** dentro del mes, y un período a
caballo —del 26 de agosto al 10 de septiembre— no cabe entero en ninguno de los
dos: desaparecía de la TSS. Peor aún, como la función cae al padrón cuando no
encuentra nóminas, ese mes se declaraba con el sueldo de las 57 personas
(RD$ 1,920,877.75) en vez de con lo que de verdad se pagó a cinco (RD$ 134,996):
catorce veces de más, y la pestaña de pago ofrecía registrar ese importe.

La regalía queda fuera **siempre**. Antes la salvaba el accidente de que su
período (el año completo) no cabía en ningún mes; con una ventana de solape
habría aparecido en los doce.

Cuando de verdad no hay nómina, la declaración cae al padrón **para poder
simular** y lo dice en pantalla; `confirmada` queda en `false` y el registro del
pago se niega, porque un gasto calculado con los sueldos de hoy sobre un mes sin
nómina es un gasto que nadie hizo.

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

### Los tres sitios donde sale la misma cifra
El costo de la nómina se ve en tres pantallas y las tres tienen que decir lo
mismo: **TSS → Pago del mes**, el informe **Resumen de nómina** y el libro de
caja. Sobre julio de 2026: 1,105,895.70 en las tres, al centavo.

Lo que rompía ese cuadre en el informe:

- Sumaba las nóminas en **cualquier estado**, así que un borrador —editable
  entero— contaba como real. Ahora entran solo las confirmadas, con un selector
  para incluir borradores que avisa de que entonces no cuadra con nada.
- Sumaba la **regalía**, cuyo período es el año completo, en todos los meses del
  año: en julio decía 1,200,958.85 donde la nómina real fue 950,980.83, un 26%
  de más. La regalía se enseña aparte porque no cotiza ni paga ISR.
- Calculaba el aporte patronal sobre `salario_base` —el sueldo prorrateado— en
  vez de sobre la base cotizable, y sin pasar por `tssAportes()`, o sea sin
  topes. Ahora usa el mismo motor que la pantalla de TSS, agrupando por empleado
  **y por mes** porque el tope de la Ley 87-01 es mensual.

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

## 4b. Certificación de ingresos y retenciones

`modules/rrhh/constancia_isr.php` — `?empleado=<id>&anio=<yyyy>` para una,
`?anio=<yyyy>` para todo el que cobró ese año, una por hoja.

El papel que un empleado pide para un préstamo, una visa o para declarar por su
cuenta si tiene otros ingresos. Lleva el detalle mes a mes —ingresos gravados,
AFP, SFS, ISR retenido y neto—, el resumen del año y una línea de firma.

Dos criterios que lo hacen válido:

- **Solo lo pagado.** Una nómina confirmada pero sin pagar es una retención que
  todavía no ocurrió, y certificar dinero que no se movió es firmar algo falso.
  Si quedan nóminas del año sin pagar, el documento lo dice en el pie en vez de
  dar un número corto sin explicación.
- **La regalía va aparte y fuera de la base.** Está exenta de ISR (art. 222):
  sumarla a los ingresos gravados haría que las cuentas del empleado no cuadren
  con lo que se le retuvo.

Se llega desde la ficha del empleado —solo si ya cobró algo este año— y, en
bloque, desde el informe *Resumen de nómina*.

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

Al marcarla **pagada** se registra el gasto en el libro de caja con su propia
categoría, *Prestaciones laborales*, y se descuenta de la cuenta elegida. Sin
eso, marcarla pagada cambiaba el estado y nada más: un millón y medio de pesos
que no aparecían en el resultado ni salían de ninguna cuenta. Mezclarla con
«Nómina» tampoco vale — escondería lo que cuestan las salidas del año.

**El documento no lleva ninguna frase de renuncia a derechos**, a propósito. El
recibo hace constar un pago; lo que se renuncie o no es materia de un acuerdo que
redacta un abogado, no una plantilla del sistema.

---

## 6b. Los informes de William

| Informe | Qué contesta |
|---|---|
| **Resumen de nómina** | Qué se pagó en un periodo, por empleado y por departamento, con el aporte patronal y el costo real |
| **Costo de la plantilla** | Cuánto cuesta AL MES la gente contratada hoy, por sucursal y por marca |
| **Provisiones laborales** | Cuánto se DEBE hoy por derechos ya devengados y no pagados |
| **Variación entre nóminas** | Por qué esta quincena costó distinto que la anterior |

### Provisiones laborales
`modules/reportes/provisiones.php`

La nómina dice lo que se pagó; este dice lo que se debe. Regalía devengada del
año, vacaciones generadas del año de servicio en curso y cesantía acumulada —que
es la mayor de las tres con diferencia y la que crece cada año sin que nadie la
vea—. Agrupado por sucursal y por marca.

Cada renglón sale de las mismas funciones que la liquidación (`plab_*`,
`regaliaDeEmpleado`), no de una fórmula paralela: el día que alguien se vaya, el
número del informe y el de su liquidación tienen que ser el mismo.

Dos cosas que dice en voz alta:

- **La cesantía solo es exigible** en desahucio, despido injustificado o dimisión
  justificada; quien renuncia no la cobra. Provisionarla al 100% es el criterio
  conservador y la decisión es del contador, así que se puede sacar del total.
- **Si el padrón tiene fechas de ingreso marcador**, el pasivo sale ridículamente
  bajo y parece una buena noticia. La antigüedad es el motor de la cesantía.

El **preaviso no se provisiona** —es un evento, no un derecho que se acumule— y
sale a la derecha solo como referencia. Tampoco entran las vacaciones de años
anteriores: el sistema no sabe qué se disfrutó si no se registró.

### Variación entre nóminas
`modules/reportes/variacion_nomina.php`

«La quincena subió ochenta mil pesos, ¿por qué?» se contestaba abriendo las dos
corridas en dos pestañas. Aquí la diferencia se descompone en **altas, bajas,
cambios de sueldo, días trabajados y cada concepto variable**, y las causas
**suman exactamente la diferencia**: si no cuadran, la pantalla lo dice en rojo,
porque una explicación que no cuadra es peor que ninguna.

Un aumento y un cambio de días se separan aunque ocurran a la vez. El sueldo del
período es `mensual × factor × días/díasBase`, y la resta se escribe en dos trozos:

    por el aumento .... (mensual_B − mensual_A) × factor × díasB/díasBaseB
    por los días ...... mensual_A × factor × (díasB/díasBaseB − díasA/díasBaseA)

No es una aproximación: sumadas dan exactamente la diferencia del sueldo.

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
- `migracion_salario_nomina_p32.sql` — `nomina_detalles.salario_mensual` congelado.
