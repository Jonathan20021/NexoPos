# El ponche: BioTime Cloud → Nexo

Importers marca entrada y salida en un reloj biométrico de ZKTeco alojado en
`https://importers.biotime.mx` (BioTime Cloud 2.0). Nexo tiene su propia tabla
`asistencias` que hoy **se llena a mano**.

**Estado: la conexión funciona y está probada. La integración NO se puede hacer
todavía, y el motivo no es técnico.** Ver §5.

---

## 1. La conexión: probada de punta a punta

`php pruebas/biotime.php` entra, saca token y lee las tres listas.

```
POST /jwt-api-token-auth/
{ "email": "...", "password": "...", "company": "importers" }
→ { "token": "eyJ..." }          (289 caracteres)

GET /iclock/api/transactions/?start_time=...&end_time=...
Authorization: JWT eyJ...
```

La nube **no** se autentica como el manual de BioTime 8.0, que dice
`{username, password}`. Pide `{email, password, company}`, donde `company` es el
subdominio. Con el cuerpo del manual contesta 400 sin decir qué falta.

## 2. Qué da cada endpoint — *corregido*

> Una versión anterior de este documento decía que `/att/api/transactionReport/`
> traía el día ya resuelto contra el turno, con la tardanza calculada. **Es
> falso.** Se comprobó contra el servidor: devuelve una fila por PONCHE, con el
> nombre y el departamento pegados. Ni turno, ni horas, ni tardanza.

| Endpoint | Qué devuelve | Columnas que importan |
|---|---|---|
| `/iclock/api/transactions/` | Una fila por ponche | `emp_code`, `punch_time`, `punch_state`, `verify_type` |
| `/att/api/transactionReport/` | Una fila por ponche, con nombre y departamento | `emp_code`, `att_date`, `punch_time`, `dept_name`, `position_name` |
| `/att/api/firstLastReport/` | **Una fila por persona y día** | `emp_code`, `att_date`, `weekday`, `first_punch`, `last_punch`, `total_time` |
| `/personnel/api/employees/` | El padrón | `emp_code`, `first_name`, `last_name`, `department` |

**`firstLastReport` es el bueno**, y encaja casi columna por columna:

| BioTime | Nexo `asistencias` |
|---|---|
| `emp_code` | `empleado_id` (vía equivalencia — §5a) |
| `att_date` | `fecha` |
| `first_punch` | `hora_entrada` |
| `last_punch` | `hora_salida` |
| `total_time` | `horas_trabajadas` |

La clave única `(empleado_id, fecha)` ya existe, así que sincronizar dos veces
el mismo día actualiza en vez de duplicar. Eso está resuelto de nacimiento.

## 3. La tardanza no sale de ninguna parte

Nexo no tiene tabla de horarios, y **BioTime tampoco tiene turnos configurados**:

```
/att/api/shift/             404
/att/api/timeInterval/      404
/att/api/schedule/          404
/att/api/employeeSchedule/  404
```

`firstLastReport` da la hora de llegada, no si esa hora es tarde. `asistencias`
tiene el estado `tardanza`, pero nadie lo puede rellenar: haría falta configurar
los turnos en BioTime —donde es su función— o crear en Nexo una tabla de
jornadas que hoy no existe. **Mientras tanto, lo que se puede traer es hora de
entrada, hora de salida y horas trabajadas. Nada más.**

## 4. `punch_state` viene en 255

Todas las marcas traen `punch_state: "255"`, que en BioTime significa «sin
especificar»: nadie declaró si era entrada o salida. Por eso hay que deducirlas
por hora —la primera y la última del día—, que es justo lo que hace
`firstLastReport`. Funciona mientras ninguna jornada cruce la medianoche.

## 5. Lo que bloquea, con números

### a) El reloj no se está usando

Leído del propio servidor, todo su histórico:

| | |
|---|---|
| Marcas en total, desde siempre | **41** |
| Primera / última | 29-07-2026 / 31-08-2026 |
| En septiembre | **0** |
| Personas dadas de alta | 48 |
| Personas que han ponchado **alguna vez** | **6** |
| De esas 41 marcas, cuántas son de «Admin» | **18** |
| Días distintos con alguna marca | 11 |

Seis personas, once días, y casi la mitad de las marcas son de la cuenta del
instalador. Esto es un piloto que se probó a finales de julio y no llegó a
entrar en producción. **Conectar Nexo a esto llenaría `asistencias` de ausencias
falsas**: quien no ponchó aparecería como que no vino.

### b) Los dos padrones no se parecen

Ninguna de las 48 personas del reloj casa por `codigo` ni por `cedula`: el reloj
usa números correlativos (1, 2, 3…) que no significan nada en Nexo.

Casando por nombre, que es lo único que queda:

| | |
|---|---|
| Coinciden dos palabras o más | 27 |
| Coincide una sola palabra (dudosas) | 16 |
| Sin ninguna coincidencia | 5 |
| Personas de más en Nexo, por cabezas | 8 (56 activas contra 48 en el reloj) |
| Sin coincidencia de nombre en el reloj | 24 — más que 8 porque los nombres están mal escritos allá |

Y las dudosas no se pueden resolver solas, porque los nombres del reloj están
mal escritos o incompletos:

```
Martzabel Lora     ¿es Maritzabel Lora Piña?   (el emparejador automático la dio a «Soraya Lora Mercedes»)
Nicole Fuenteliza  ¿es Nicole Anabel Fuenzalida G.?
Nayalis Acosta     ¿es Nayeli Acosta?
Dennis Peguero     ¿es Denisse Scarlete Peguero?
Manuel Casado      ¿es Manuel Alejandro Rodríguez?   — casi seguro que no
```

El primer caso es exactamente por qué esto no se puede automatizar: el
emparejamiento por nombre **eligió a la persona equivocada**. Y una equivocación
aquí no es un dato feo, es el ponche de una persona cargado a la nómina de otra.

## 6. Qué hace falta antes de programar nada

1. **Que el reloj se use.** Sin marcas no hay nada que traer. Esto es de
   operaciones, no de programación.
2. **Que su padrón se ponga al día**: dar de alta a los 24 que faltan, quitar a
   los 5 que ya no están, y escribir los nombres bien.
3. **Turnos**, si se quiere tardanza. En BioTime, que es su trabajo.
4. **Una columna de equivalencia** (`empleados.biotime_emp_code`) confirmada
   persona por persona por alguien que las conozca. Nunca automática.

Con eso resuelto, la sincronización es corta: `firstLastReport` del día
anterior, casar por la equivalencia, y un upsert sobre `(empleado_id, fecha)`.

Falta decidir además **qué pasa con lo corregido a mano**: si alguien olvidó
ponchar la salida y se le puso a mano en Nexo, la siguiente sincronización no
debería pisarlo. Lo sensato es marcar la fila como tocada a mano y respetarla,
avisando de la diferencia.

## 7. La sincronización, y por qué cada regla está ahí

`bioSincronizar($desde, $hasta)` trae los días y los deja en `asistencias`.
Ninguna de estas reglas es estilo: cada una tapa un fallo visto en los datos
reales de este cliente. Si se quita, vuelve el fallo.

| Regla | El fallo que evita |
|---|---|
| **No escribe ausencias.** Solo toca días con marca | «No hay marca» y «no vino» son cosas distintas. Aquí ponchan 6 de 48: dar por ausente a quien no marcó llenaría la nómina de faltas falsas |
| **No pisa `origen = 'manual'`** | Alguien corrigió una salida que se olvidó de ponchar; la siguiente pasada la borraría. Se respeta y se avisa de la diferencia |
| **No adivina a nadie.** Solo `biotime_emp_code` | El emparejamiento por nombre eligió mal al probarlo. Un código sin equivalencia se informa, nunca se asigna |
| **Una sola marca → salida vacía** | Poner la misma hora en entrada y salida diría «trabajó cero horas», que es mentira y además se paga |
| **Fecha partida a mano, no con `strtotime()`** | El reloj manda «05-08-2026», día primero. `strtotime()` lo lee como 8 de mayo y guarda la fila en otro mes **sin quejarse** |
| **Jornada que cruza medianoche → incompleta** | Restar daría horas negativas. «Primera y última del día» ya partió esa jornada en dos |
| **Nada fuera del rango pedido** | Sincronizar «ayer» no puede tocar el mes pasado si el servidor devuelve de más |
| **Una fila mala no tumba las demás** | Una persona sin emparejar no puede impedir que entren las otras cincuenta |
| **La caché del padrón se tira en cada pasada** | Guardar la fila con su `estado` entre dos pasadas daría de alta la asistencia de alguien que ya se fue |
| **Índice único en `biotime_emp_code`** | Dos personas con el mismo código = los ponches de una en la nómina de la otra |

### El reloj del aparato

`punch_time` viene en hora local de RD y `upload_time` en UTC, así que la
diferencia tiene que ser de **−240 minutos**. En los datos de este cliente lo es
en 28 de 31 marcas; las tres que se salen —una hasta 17 horas— son del aparato
en montaje con el reloj mal puesto. `bioRelojDeFiar()` lo comprueba con 15
minutos de tolerancia, porque un retraso de subida es normal y un desajuste de
horas no.

**No hay que convertir zonas horarias.** `punch_time` se usa tal cual.

### Dónde se hace todo esto

**Recursos Humanos → Reloj biométrico** (`modules/rrhh/ponche.php`). Ahí se
empareja a la gente y se traen los ponches, con permisos y auditoría. Pide
`rrhh_asistencia.ver` para mirar y `rrhh_asistencia.registrar` para guardar.

La columna de la derecha viene **vacía a propósito**. La flecha «¿esta?» marca a
quien más se parece por el nombre, pero es una pista, no una prueba: los nombres
del reloj vienen con erratas. Lo que quede en «sin asignar» simplemente no entra.

Reasignar un código de una persona a otra **libera antes el anterior**. Sin eso,
el índice único rechaza el guardado y el emparejamiento se queda a medias.

### Corregir a mano gana siempre

Cualquier guardado desde **Asistencia** marca la fila como `origen = 'manual'`, y
la sincronización ya no la toca: avisa de la diferencia y deja lo del humano.

Esto faltaba y se desplegó roto: `asistencia.php` no escribía `origen`, así que
una salida corregida a mano volvía a la del reloj en la pasada siguiente. Y el
aviso comparaba **solo la entrada**, con lo que el caso más común —olvidó
ponchar la salida y se la pusieron— no decía nada: ahí la entrada coincide y la
salida no. Ahora compara las dos.

### El CSV, para quien prefiera Excel

### Cómo se empareja a la gente

Dos pasos, a propósito:

```
php pruebas/biotime_emparejar.php --proponer   → storage/biotime_emparejamiento.csv
php pruebas/biotime_emparejar.php --aplicar    → guarda solo lo marcado «si»
```

La máquina propone y **una persona que las conoce decide**. Nada se aplica solo.
Al proponerlo contra el padrón real: 27 probables, 16 dudosas, 5 sin parecido, y
8 personas de Nexo que no están dadas de alta en el reloj.

### Cómo corre

```
php modules/rrhh/ponche_cron.php              # últimos 3 días
php modules/rrhh/ponche_cron.php --dias=7
php modules/rrhh/ponche_cron.php --simular    # dice qué haría, sin escribir
```

Trae **tres días, no uno**: un aparato que estuvo sin red sube sus marcas tarde,
y una ventana de un día las perdería para siempre porque nadie vuelve a mirar
el ponche de anteayer. Repetir días ya traídos no cuesta nada —es idempotente—.

Sale con **código 1 cuando hay algo que mirar** (gente sin emparejar, una
corrección manual que no coincide, alguien inactivo que sigue ponchando), para
que el cron avise en vez de fallar en silencio.

Por URL exige `PONCHE_CRON_KEY`; sin esa constante el endpoint no se expone.

## 8. Qué hay escrito

- `includes/biotime.php` — el cliente: `bioToken()`, `bioGet()`, `bioLista()`
  (sigue `next` con tope de vueltas), `bioEmpleados()`, `bioPonches()`,
  `bioReporte()`, `bioDiagnostico()` y `bioPorQueNoEntra()`.
- `pruebas/biotime.php` — el diagnóstico. No escribe nada.
- `pruebas/biotime_clave.php` — guarda la contraseña sin que pase por la línea
  de comandos ni por el historial del shell.
- `pruebas/biotime_sync.php` — 35 comprobaciones de las reglas de §7, con filas
  inyectadas a mano: prueba los casos que aún no han ocurrido pero ocurrirán.
- `pruebas/biotime_emparejar.php` — propone y aplica la equivalencia.
- `modules/rrhh/ponche_cron.php` — el disparador.
- `database/migracion_ponche_biotime_p35.sql` — `empleados.biotime_emp_code`
  (único), `asistencias.origen` y `asistencias.biotime_sync_at`.

Las credenciales están en `config/config.local.php`, que no se versiona.
`bioOfuscar()` tapa la contraseña y el token incluso en los mensajes de error.

Conviene que la integración use **una cuenta propia**, no la de una persona: si
esa persona se va y le desactivan el usuario, el ponche deja de entrar y nadie
sabe por qué.
