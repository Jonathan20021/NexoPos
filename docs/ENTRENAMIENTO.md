# Centro de Entrenamiento (P37)

Un temario completo del sistema que **se recorta solo con los permisos de quien entra**.

Migración: `database/migracion_entrenamiento_p37.sql` (idempotente, MariaDB 10.4 y MySQL 8).

---

## Por qué existe así

El sistema tiene 13 módulos y más de 70 permisos repartidos entre cajeros, almacén, RRHH,
contabilidad y dirección. **Nadie usa los 13**: cada persona usa los dos o tres que su rol le
abre. Un manual único obliga a cada quien a buscar su parte entre las de los demás, y lo que
pasa en la práctica es que no lo lee nadie.

Por eso una lección declara **el mismo permiso que abre la pantalla que enseña**. Quien no
puede ver la nómina tampoco ve su lección — no por secreto, sino porque estudiar algo que no
se puede abrir es tiempo perdido y ensucia el avance con lecciones que nunca va a completar.

Medido contra los roles reales del sistema:

| Rol | Lecciones | Rutas visibles |
|---|---:|---|
| Cajero | 21 | fundamentos, POS, algo de inventario, clientes |
| Almacén / Inventario | 26 | fundamentos, inventario, compras, traslados, sanidad |
| Recursos Humanos | 16 | fundamentos, RRHH |
| Gerente de Sucursal | 67 | las doce |
| Administrador | 68 | las doce |

**Consecuencia de diseño: todos los porcentajes se calculan sobre lo VISIBLE para esa
persona.** Un cajero con 21 lecciones y un administrador con 68 llegan los dos al 100%.
Comparar «lecciones hechas» entre roles no significaría nada.

---

## Contenido

12 rutas · 69 lecciones · 265 pasos · 77 preguntas · 51 términos de glosario · ~462 minutos.

| Ruta | Lecciones | Permiso de entrada |
|---|---:|---|
| Primeros pasos | 8 | *(ninguno — es para todos)* |
| Punto de venta y caja | 8 | `pos.ver` / `caja.ver` / `ventas.ver` |
| Inventario y almacén | 9 | `productos.ver` / `inventario.ver` / `conteos.ver` |
| Compras, proveedores y CxP | 5 | `compras.ver` / `proveedores.ver` / `cxp.ver` / `liquidaciones.ver` |
| Traslados entre tiendas | 3 | `transferencias.*` |
| Clientes, crédito y CRM | 4 | `clientes.ver` / `crm.ver` |
| Recursos Humanos y nómina | 8 | `rrhh_*` / `tss.ver` |
| Finanzas y obligaciones fiscales | 8 | `finanzas.ver` / `dgii.ver` / `ecf.ver` / … |
| Reportes y dirección | 4 | `reportes.ver` / `direccion.ver` |
| Marketing | 4 | `marketing.ver` / `campanas.ver` / `promociones.ver` |
| Cumplimiento sanitario | 2 | `sanidad.ver` / `reportes.sanidad` |
| Administración del sistema | 5 | `usuarios.ver` / `roles.ver` / `configuracion.ver` / … |

El contenido **vive en código**, no en la base: se versiona con el resto del sistema y no hay
que migrar datos cuando una pantalla cambia. En la base solo está lo que es de cada persona.

---

## Archivos

| Archivo | Responsabilidad |
|---|---|
| `includes/entrenamiento.php` | Motor: recorte por permisos, avance, evaluaciones, certificados, ayuda contextual, glosario |
| `includes/entrenamiento_temario_operacion.php` | Fundamentos, POS, inventario, compras, traslados, clientes/CRM |
| `includes/entrenamiento_temario_gestion.php` | RRHH, finanzas, reportes, marketing, sanidad, administración |
| `modules/entrenamiento/index.php` | El hub: avance propio, «continúa donde lo dejaste» y las rutas |
| `modules/entrenamiento/ruta.php` | Detalle de una ruta y su evaluación |
| `modules/entrenamiento/leccion.php` | El reproductor paso a paso (y la vista completa para imprimir) |
| `modules/entrenamiento/evaluacion.php` | Examen y resultado con explicación de cada respuesta |
| `modules/entrenamiento/certificado.php` | Certificado imprimible con folio verificable |
| `modules/entrenamiento/equipo.php` | Avance del equipo (supervisión) |
| `modules/entrenamiento/glosario.php` | 51 términos del sistema y del negocio dominicano |
| `modules/entrenamiento/progreso.php` | POST del avance propio (patrón PRG) |

---

## Permisos

El Centro de Entrenamiento **no lleva permiso de entrada**: todo el que entra al sistema tiene
derecho a que le expliquen lo que puede tocar, y el temario ya se recorta solo. Lo que sí lleva
permiso es mirar el avance ajeno, que es supervisión de personal:

- `entrenamiento.equipo` — ver el avance y las evaluaciones de todo el equipo.
  La migración lo concede a quien ya tiene `usuarios.ver`.
- `entrenamiento.asignar` — reiniciarle el avance a otra persona (cambio de puesto).
  La migración lo concede a quien ya tiene `usuarios.editar`.

---

## Decisiones que conviene conocer

**El avance se recorre con `?paso=N`, no con un carrusel de JavaScript.** Cada paso queda en el
historial del navegador, se puede enlazar y compartir, funciona sin JavaScript y —lo que
importa— el avance se registra sin depender de que el navegador consiga avisar.

**`paso_actual` solo avanza** (`GREATEST`). Volver atrás a repasar el paso 2 no debe borrar que
ya se había llegado al 6.

**Repetir una lección completada no la reabre**: sube `veces` y suma el tiempo. El repaso es
bueno; que el avance baje por repasar, no.

**Toda escritura invalida la caché de `ent_progreso()`.** Sin eso, completar una lección y
preguntar en la misma petición cuánto lleva la ruta devuelve la foto de ANTES, y el aviso de
«ya puedes presentar la evaluación» no aparece nunca. Fue un fallo real durante el desarrollo.

**Guardar el avance nunca tumba la lección.** Las escrituras van en `try/catch`: una sesión
viva de un usuario ya borrado rompe la clave foránea, y sería absurdo que eso dejara a alguien
sin poder consultar el manual.

**Solo se evalúa con la ruta terminada.** Evaluar sin haber leído convierte el certificado en
una lotería de cuatro opciones. Y las preguntas salen de las lecciones **visibles**: incluir la
de una lección que el permiso ocultó sería preguntar por algo que nunca se mostró.

**El certificado no se guarda: se deriva.** Existe si y solo si están todas las lecciones
completadas Y hay una evaluación aprobada. Si a alguien le reinician el avance, deja de
existir. No hay documento almacenado que pueda contradecir al sistema. El folio se deriva de
(usuario, ruta, fecha) con un hash: es verificable y no revela cuántos se han emitido.

**El avance ajeno se mide con los permisos del otro.** `ent_con_permisos()` le presta a `can()`
los permisos de ese rol durante un instante, en vez de reimplementar aquí la lógica de permisos
—que es la forma segura de que las dos se separen con el tiempo—. Se calcula **una vez por
rol**, no una por usuario.

**Sin la migración, el temario se lee igual.** `ent_disponible()` comprueba las tablas; lo único
que se pierde es guardar el avance, y la pantalla lo avisa. El código puede desplegarse antes
que la migración.

---

## Ayuda contextual

El botón del libro en la barra superior ofrece **la lección que explica la pantalla actual**.
El índice se construye solo invirtiendo el campo `pantalla` de cada lección
(`ent_indice_pantallas()`): añadir una lección con su `pantalla` la conecta al botón sin tocar
nada más. Cuando dos lecciones reclaman la misma pantalla gana la primera del temario, que por
el orden didáctico es la introductoria.

Las lecciones también salen en el buscador global (`Ctrl`/`⌘` + `K`), al final de los
resultados: quien busca «nómina» quiere la nómina, y la lección que la explica solo debajo.

---

## Cómo añadir o cambiar contenido

Todo el temario es un arreglo PHP. El formato de una lección está documentado en la cabecera de
`entrenamiento_temario_operacion.php`. Reglas que no se negocian:

1. **El `permiso` de la lección es el mismo que abre la pantalla que enseña.** Si no coinciden,
   alguien estudiará algo que no puede usar.
2. **Cifras de negocio inventadas, nunca.** Los ejemplos usan importes obviamente didácticos
   (RD$ 100.00) o hablan en términos relativos. Es la misma regla que rige el resto de la
   interfaz.
3. **Nada de porcentajes legales memorizados.** El temario explica el CRITERIO (por qué el ISR
   se calcula sobre el equivalente mensual); los valores vigentes los tiene el sistema en
   Recursos Humanos → TSS. Un manual con una tasa vieja es peor que ninguno.
4. **Cada lección apunta a su pantalla real** con `pantalla` / `ruta`: entrenar sin poder abrir
   lo que se explica no entrena a nadie. Hay una prueba fácil de hacer: recorrer
   `ent_pantallas_de_leccion()` de todo el catálogo y comprobar que cada archivo existe.
5. Añadir una ruta nueva no requiere tocar el motor ni el menú: basta con su entrada en el
   arreglo del temario.

---

## Verificado

- Renderizado sin errores de las siete pantallas, con datos reales de la base local.
- Recorte por permisos comprobado contra los seis roles del sistema (tabla de arriba).
- Ciclo completo: completar lecciones → evaluación aprobada (10/10) y no aprobada (0/9) →
  certificado emitido solo en la aprobada.
- Todas las pantallas referenciadas por el temario existen en el repositorio.
- **Cero scroll horizontal** en las siete pantallas a 360, 390, 768, 1024, 1280 y 1440 px.

> Al medir 1024 px salió a la luz que la barra superior **ya venía apretada**: con el menú
> lateral ocupando 260 px le quedaban 755 y su bloque derecho medía 813. El botón de ayuda lo
> empeoraba. Se resolvió moviendo la fecha de `lg` a `xl` (`includes/layout/topbar.php`): es lo
> prescindible de esa fila —está en cada documento y en el sistema operativo—, así que es lo
> que cede el sitio.
