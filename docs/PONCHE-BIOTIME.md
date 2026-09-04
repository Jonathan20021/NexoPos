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
| Gente de Nexo que **no está** en el reloj | 24 de 56 |

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

## 7. Qué hay escrito

- `includes/biotime.php` — el cliente: `bioToken()`, `bioGet()`, `bioLista()`
  (sigue `next` con tope de vueltas), `bioEmpleados()`, `bioPonches()`,
  `bioReporte()`, `bioDiagnostico()` y `bioPorQueNoEntra()`.
- `pruebas/biotime.php` — el diagnóstico. No escribe nada.
- `pruebas/biotime_clave.php` — guarda la contraseña sin que pase por la línea
  de comandos ni por el historial del shell.

Las credenciales están en `config/config.local.php`, que no se versiona.
`bioOfuscar()` tapa la contraseña y el token incluso en los mensajes de error.

Conviene que la integración use **una cuenta propia**, no la de una persona: si
esa persona se va y le desactivan el usuario, el ponche deja de entrar y nadie
sabe por qué.
