# Verificación en dos pasos (OTP por correo)

Al iniciar sesión, después de la contraseña, el sistema envía un código de 6 dígitos
al correo del usuario. Sin ese código no se abre la sesión.

**Por qué.** Una contraseña se comparte, se apunta en un papel pegado al monitor y se
reutiliza en otros sitios que sí se filtran. En este sistema con una contraseña se
factura, se ajusta inventario y se cierra caja. El segundo factor convierte «alguien
averiguó la clave» en «alguien averiguó la clave y además tiene el correo».

| Pieza | Dónde |
|---|---|
| Motor (emisión, verificación, límites, equipos) | `includes/otp.php` |
| Orquestación del login en dos pasos | `app/auth.php` |
| Paso 1 (usuario + contraseña) | `modules/auth/login.php` |
| Paso 2 (código) | `modules/auth/verificar.php` |
| Panel de administración | `modules/admin/seguridad.php` |
| Lo que ve el usuario de sí mismo | `modules/auth/perfil.php` |
| Migración | `database/migracion_otp_login_p14.sql` |

---

## Instalación

1. Aplicar `database/migracion_otp_login_p14.sql` (idempotente; vale en MariaDB 10.4 y MySQL 8).
2. Tener Resend configurado en `config/config.local.php` (`RESEND_API_KEY`, `MAIL_FROM`).
   Ver `CORREOS.md`.
3. Listo: la política nace en **«siempre»** y todos los usuarios quedan con la
   verificación activa. Se ajusta en **Administración → Seguridad de acceso**.

Si el código se despliega **antes** que la migración, no pasa nada: `otp_disponible()`
lo detecta y el login sigue funcionando exactamente como antes.

---

## El flujo, paso a paso

```
1. POST usuario + contraseña   ──► login_intentar()
     ├─ ¿IP o cuenta bloqueada por fallos?      → error, no se toca la base
     ├─ ¿Contraseña mala?                       → mismo mensaje que «usuario inexistente»
     ├─ ¿No hace falta segundo factor?          → login_establecer_sesion() → dashboard
     └─ Hace falta  → otp_emitir() → $_SESSION['otp_login'] → /modules/auth/verificar

2. POST código                 ──► login_confirmar_otp()
     ├─ otp_verificar(): un intento atómico, un password_verify, un solo uso
     ├─ Se relee la cuenta (pudieron desactivarla entre el paso 1 y el 2)
     ├─ ¿Marcó «recordar equipo»? → otp_recordar_dispositivo()
     └─ login_establecer_sesion() → dashboard
```

**La sesión no existe hasta el final.** Entre un paso y otro solo vive
`$_SESSION['otp_login']`, que no concede ningún permiso: `is_logged_in()` sigue en
falso y `can()` devuelve falso para todo.

---

## Decisiones que no son obvias

**El código no se guarda en claro.** Se guarda su `password_hash()` (bcrypt). Un
volcado de la base no entrega códigos utilizables: probar el millón de combinaciones a
~80 ms cada una tarda días y el código caduca en minutos.

**El intento se cuenta antes de compararlo.** `otp_verificar()` hace primero un
`UPDATE ... SET intentos = intentos + 1 WHERE ... AND intentos < max_intentos` y mira el
`rowCount()`. Así dos peticiones en paralelo no pueden gastar el mismo intento dos veces
ni pasarse del tope probando en ráfaga. El `password_verify`, que es la parte lenta, va
después.

**Emitir un código anula el anterior.** Nunca hay dos códigos válidos a la vez.

**El paso intermedio está atado al navegador** (hash del `User-Agent`). Un identificador
de sesión robado a mitad del flujo no sirve para terminar de entrar.

**Un re-login rápido reutiliza el código en lugar de mandar otro.** La espera de 60 s
entre envíos se aplica solo al botón «Enviar otro código». Aplicarla también a la
emisión inicial rompía un caso real: cerrar sesión y volver a entrar en menos de un
minuto dejaba a la persona fuera, con la contraseña correcta y sin culpa alguna. Si ya
hay un código vivo, intacto y de menos de un minuto, se reutiliza el que la persona ya
tiene en su bandeja.

**Cambiar la contraseña retira los equipos de confianza.** Cambiar la contraseña es lo
que se hace cuando se sospecha que alguien la conoce; dejarle equipos marcados sería
dejarle una puerta abierta sin segundo factor.

**Sin correo configurado NO se exige el código.** Si no hay `RESEND_API_KEY`, no hay
forma de entregar el código y exigirlo dejaría al cliente fuera de su propio sistema por
un dato de configuración del servidor. En ese caso se deja entrar solo con contraseña y
se avisa a gritos: banner rojo en Seguridad de acceso y alerta crítica en el centro de
notificaciones. Para cerrar también esa puerta, ver `OTP_EXIGIR_SIEMPRE` más abajo.

Un fallo **puntual** del envío (Resend caído) es distinto: ahí el código sí se emite y la
persona debe reintentar. No se deja pasar a nadie.

---

## Modos de la política

Se eligen en **Administración → Seguridad de acceso** (permiso `seguridad.gestionar`).

| Modo | Cuándo pide código |
|---|---|
| `siempre` | En cada inicio de sesión. **Es el valor de fábrica.** |
| `dispositivo_nuevo` | Solo cuando el equipo no está marcado como de confianza. |
| `nunca` | Desactivado. |

Además: `otp_vigencia_min` (2–60, por omisión 10) y `otp_recordar_dias` (0–365, por
omisión 30; 0 desactiva los equipos de confianza).

Por usuario, `usuarios.otp_activo` permite eximir una cuenta concreta (un equipo de
servicio, una caja sin correo). Una cuenta exenta aparece marcada en ámbar en Usuarios y
en Seguridad de acceso, y genera una notificación mientras siga así.

---

## Equipos de confianza

Solo en modo `dispositivo_nuevo`. Al verificar el código, la persona puede marcar «no
volver a pedirme el código en este equipo».

- La cookie `NEXOPOS_DISP` lleva un token aleatorio de 32 bytes; en la base solo vive su
  SHA-256. Robar la base no da acceso.
- El token se **rota** cada vez que se vuelve a marcar el equipo.
- El usuario ve y retira sus equipos en **Mi Perfil**; un administrador puede retirar los
  de cualquiera desde Seguridad de acceso.
- Caducan solos y la purga los borra.

---

## Límites (constantes en `includes/otp.php`)

| Constante | Valor | Qué frena |
|---|---|---|
| `OTP_LONGITUD` | 6 | dígitos del código |
| `OTP_MAX_INTENTOS` | 5 | fallos por código; al sexto el código muere |
| `OTP_REENVIO_ESPERA` | 60 s | entre dos reenvíos pedidos a mano |
| `OTP_MAX_ENVIOS_HORA` | 10 | techo duro de correos por usuario y hora |
| `OTP_PENDIENTE_VIDA_MIN` | 20 min | vida del paso intermedio |
| `OTP_PURGA_DIAS` | 45 | histórico que se conserva |
| `LOGIN_VENTANA_MIN` | 15 min | ventana deslizante del contador de fallos |
| `LOGIN_MAX_FALLOS_CUENTA` | 5 | fallos de contraseña que bloquean una cuenta |
| `LOGIN_MAX_FALLOS_IP` | 20 | fallos de contraseña que bloquean una IP entera |

Los bloqueos **se levantan solos** al vencer la ventana. Un administrador puede
levantarlos antes desde Seguridad de acceso → «Intentos fallidos en curso».

El contador solo cuenta los fallos **posteriores al último acierto**: quien falla cuatro
veces y entra bien a la quinta no arrastra esos cuatro fallos a su siguiente descuido.

---

## Llaves de emergencia (`config/config.local.php`)

```php
// Apaga la verificación en dos pasos pase lo que pase. Manda sobre la política
// guardada en la base: es la salida cuando nadie puede entrar.
define('OTP_DESACTIVADO', true);

// Lo contrario: exige el código aunque el correo no esté configurado.
// Con esto, si Resend no funciona, NADIE entra. Úsalo a conciencia.
define('OTP_EXIGIR_SIEMPRE', true);
```

Estando definida, `OTP_DESACTIVADO` se anuncia con un banner rojo en Seguridad de acceso
para que nadie se olvide de quitarla.

---

## Probar en local (XAMPP, sin correo)

En `APP_ENV = development` **y** sin `RESEND_API_KEY`, la pantalla de verificación
muestra el código en un recuadro violeta. Nunca ocurre en producción ni con Resend
configurado: la condición exige las dos cosas.

Si tu `config.local.php` sí tiene la key y quieres probar sin enviar correos, define
antes de cargar `bootstrap.php`:

```php
define('RESEND_API_KEY', '');       // gana sobre la de config.local.php
define('OTP_EXIGIR_SIEMPRE', true); // para que la política se siga exigiendo
```

---

## Dónde mirar cuando algo va mal

| Síntoma | Dónde |
|---|---|
| No llega el código | Seguridad de acceso → «Últimos códigos emitidos»: hay una columna de estado con el error de Resend |
| Alguien no puede entrar | «Intentos fallidos en curso»: mira si está bloqueado y desbloquéalo |
| Quién pidió un código y desde dónde | Tabla `login_otp` (ip, user_agent, created_at) y bitácora `auditoria` (`otp_enviado`) |
| Cómo entró alguien | `auditoria`, acción `login`: la descripción dice si fue con código, por equipo de confianza o exento |
| Accesos de mi propia cuenta | Mi Perfil → «Últimos intentos de acceso a tu cuenta» |
