# El ponche: BioTime Cloud → Nexo

Importers marca entrada y salida en un reloj biométrico de ZKTeco alojado en
`https://importers.biotime.mx` (BioTime Cloud 2.0). Nexo tiene su propia tabla
`asistencias` que hoy **se llena a mano**, mirando la otra pantalla.

Este documento recoge lo que se comprobó del API, cómo encaja con Nexo y las
decisiones que faltan.

---

## 1. Sí se puede: el API está y responde

Comprobado sin credenciales, solo mirando qué contesta cada ruta:

| Ruta | Código | Qué significa |
|---|---|---|
| `/api/docs/` | 200 | La documentación está publicada |
| `/jwt-api-token-auth/` | 405 | Existe; solo acepta POST |
| `/iclock/api/transactions/` | 401 | Existe; pide token. **Los ponches** |
| `/att/api/transactionReport/` | 401 | Existe; pide token. **El día ya calculado** |
| `/att/api/firstLastReport/` | 401 | Existe; primera y última marca del día |
| `/personnel/api/employees/` | 401 | Existe; pide token. **El padrón** |
| `/personnel/api/departments/` | 401 | Existe |
| `/iclock/api/terminals/` | 401 | Existe; los aparatos |

Un 401 es la mejor noticia posible aquí: la ruta existe y solo falta el token.
Un 404 habría querido decir que la nube no expone esa parte.

## 2. La nube no se autentica como el manual

El manual de BioTime 8.0 —el de servidor— dice que el cuerpo es
`{username, password}`. **La nube pide `{email, password, company}`**, donde
`company` es el subdominio. Con el cuerpo del manual contesta 400 sin explicar
qué falta, que es exactamente el rato perdido que este párrafo evita.

```
POST /jwt-api-token-auth/
{ "email": "...", "password": "...", "company": "importers" }
→ { "token": "eyJ..." }

GET /iclock/api/transactions/?start_time=...&end_time=...
Authorization: JWT eyJ...
```

## 3. Nexo no tiene turnos, y eso decide el diseño

`asistencias` guarda `estado` con `presente`, `ausente`, `tardanza`… pero **no
existe ninguna tabla de horarios**. El informe de «horarios» es de ventas por
hora, no de jornadas. Sin saber a qué hora entra cada quien, Nexo **no puede
deducir una tardanza**: solo sabría a qué hora llegó, no si llegó tarde.

BioTime sí tiene los turnos configurados —es su trabajo—, así que hay dos
formas de traer el dato y no son equivalentes:

| | `/iclock/api/transactions/` | `/att/api/transactionReport/` |
|---|---|---|
| Qué trae | Cada marca suelta | El día resuelto contra el turno |
| Entrada y salida | Hay que deducirlas (primera y última) | Vienen dadas |
| Tardanza | **Imposible sin turnos** | Viene calculada |
| Horas extra | Se estiman mal | Vienen calculadas |
| Riesgo | Reimplementar en Nexo la lógica de turnos, sin los datos | Depender de que los turnos estén bien puestos en BioTime |

**Recomendación: el reporte calculado.** Reimplementar turnos en Nexo sería
construir un segundo BioTime peor, y con dos fuentes que se contradicen gana la
discusión el que grite más fuerte, no el que tenga razón.

## 4. Lo que casa con lo que

| BioTime | Nexo `asistencias` |
|---|---|
| `emp_code` | `empleado_id` (vía equivalencia — ver abajo) |
| fecha del reporte | `fecha` |
| primera marca | `hora_entrada` |
| última marca | `hora_salida` |
| total trabajado | `horas_trabajadas` |
| tiempo extra | `horas_extra` |
| tardanza / ausencia | `estado` |

La clave única `(empleado_id, fecha)` ya existe, así que sincronizar dos veces
el mismo día actualiza en vez de duplicar. Eso está resuelto de nacimiento.

## 5. Lo que falta decidir

**a) Cómo se casa cada persona.** Nexo identifica por `empleados.codigo` y
`empleados.cedula`; el reloj por `emp_code`. Si no coinciden, hace falta una
columna de equivalencia (`empleados.biotime_emp_code`) y llenarla una vez.
`php pruebas/biotime.php` cuenta exactamente cuántos casan y por dónde, y lista
los que no. **Hasta saber ese número no se puede escribir la sincronización.**

**b) Qué pasa con lo corregido a mano.** Si alguien arregló un día en Nexo
—olvidó ponchar la salida y se le puso a mano—, ¿la siguiente sincronización lo
pisa? Lo sensato es que **no**: marcar la fila como tocada a mano y respetarla,
avisando de la discrepancia. Pero es una decisión del cliente, no técnica.

**c) Cada cuánto.** Una vez al día de madrugada basta para la nómina. Si se
quiere ver el ponche del día en vivo, hace falta cada pocos minutos y conviene
mirar antes si el plan de la nube tiene tope de llamadas.

**d) Jornadas que cruzan medianoche.** Si alguna tienda cierra pasada la
medianoche, «primera y última marca del día» parte la jornada en dos. Con
horario de centro comercial no pasa, pero conviene confirmarlo.

## 6. Qué hay escrito ya

- `includes/biotime.php` — el cliente: `bioToken()`, `bioGet()`, `bioLista()`
  (sigue `next` hasta el final, con tope de vueltas), `bioEmpleados()`,
  `bioPonches()`, `bioReporte()` y `bioDiagnostico()`.
- `pruebas/biotime.php` — el diagnóstico. No escribe nada: dice si entra, cuánta
  gente casa y por qué campo, y enseña un día real con todos sus campos.
- `config/config.local.example.php` — las cuatro constantes, documentadas.

**Las credenciales no están en el repositorio ni en la base de datos.** Van en
`config/config.local.php`, que no se versiona, y `bioOfuscar()` tapa la
contraseña y el token incluso en los mensajes de error.

Conviene que la integración use **una cuenta propia**, no la de una persona: si
esa persona se va y se le desactiva el usuario, la sincronización deja de andar
y nadie sabe por qué.

## 7. El siguiente paso

```
php pruebas/biotime.php
```

Lo que imprima decide el resto: si casan todos, la sincronización es directa; si
no, primero la columna de equivalencia.
