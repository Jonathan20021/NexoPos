-- ---------------------------------------------------------------------------
--  NexoPOS · P38 — Horarios, feriados y el día completo
-- ---------------------------------------------------------------------------
--  Hasta ahora el reloj decía a qué hora llegó cada quien y nadie podía decir
--  si esa hora era tarde. `asistencias.estado` tenía «tardanza» desde el primer
--  día y NUNCA se pudo rellenar: no hay con qué comparar. Lo mismo con las
--  ausencias, los feriados y los días de descanso.
--
--  Cuatro piezas, y cada una tapa un agujero concreto:
--
--    jornadas / jornada_dias
--      El horario. Una fila POR DÍA de la semana en vez de siete pares de
--      columnas, porque en RD el sábado casi nunca es igual que el resto
--      —8-12 es lo común— y porque cambiar un día no debe costar un ALTER.
--
--    feriados
--      Un feriado no es una falta ni una tardanza. Sin calendario, el 27 de
--      febrero saldría como que no vino toda la empresa. Además `vacaciones`
--      cuenta días laborables y hoy solo descuenta domingos: un feriado dentro
--      de unas vacaciones le come al empleado un día de su derecho.
--
--    empleados.jornada_id
--      NULL a propósito y permitido: quien no tiene horario asignado NO recibe
--      tardanza calculada. Inventarle una jornada de oficina a quien trabaja
--      por turnos es peor que no decir nada — acaba en un descuento que nadie
--      puede defender.
--
--    asistencias: jornada_id, tardanza_min, salida_temprana_min, horas_esperadas
--      Se guarda QUÉ horario se aplicó, no solo el resultado. Si mañana alguien
--      cambia la jornada, el día de ayer conserva con qué se juzgó; si no, el
--      histórico cambiaría de significado por debajo y una amonestación por
--      tardanza dejaría de cuadrar con su propio expediente.
--
--  Idempotente. Vale en MariaDB 10.4 y en MySQL 8.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

/* ========================================================================
 *  El horario
 * ===================================================================== */
CREATE TABLE IF NOT EXISTS jornadas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(80)  NOT NULL,
  -- Minutos de gracia antes de que llegar tarde CUENTE como tardanza. Sin
  -- tolerancia, el tráfico de un martes convierte a toda la oficina en
  -- impuntual y el dato deja de servir para nada.
  tolerancia_min  SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  -- Descanso no pagado que se resta de las horas del día. El reloj solo da
  -- primera y última marca: sin esto, la hora de almuerzo se paga.
  almuerzo_min    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  -- A partir de cuántos minutos por encima de la jornada se cuenta extra.
  -- Quedarse cuatro minutos recogiendo no es una hora extra.
  extra_desde_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  notas           VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jornada_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jornada_dias (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  jornada_id   INT UNSIGNED NOT NULL,
  -- 1 = lunes … 7 = domingo, igual que DAYOFWEEK de ISO y que date('N') de PHP.
  -- Se elige ese orden y no el de MySQL (1 = domingo) para que el código PHP
  -- y el SQL digan el mismo número: dos convenios en un mismo sistema es un
  -- error esperando fecha.
  dia_semana   TINYINT UNSIGNED NOT NULL,
  labora       TINYINT(1) NOT NULL DEFAULT 1,
  hora_entrada TIME NULL,
  hora_salida  TIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jornada_dia (jornada_id, dia_semana),
  CONSTRAINT fk_jdia_jornada FOREIGN KEY (jornada_id) REFERENCES jornadas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ========================================================================
 *  El calendario de feriados
 * ===================================================================== */
CREATE TABLE IF NOT EXISTS feriados (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha      DATE NOT NULL,
  nombre     VARCHAR(80) NOT NULL,
  -- Los de la Ley 139-97 se calculan; los que declara el Gobierno sobre la
  -- marcha —un duelo nacional, una jornada electoral— se añaden a mano. Marcar
  -- cuáles son cuáles permite regenerar el año sin borrar lo que puso alguien.
  automatico TINYINT(1) NOT NULL DEFAULT 1,
  pagado     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_feriado_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ========================================================================
 *  A quién se le aplica
 * ===================================================================== */
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados'
             AND COLUMN_NAME = 'jornada_id');
SET @s := IF(@c = 0,
  'ALTER TABLE empleados ADD COLUMN jornada_id INT UNSIGNED NULL DEFAULT NULL AFTER puesto_id',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados'
             AND CONSTRAINT_NAME = 'fk_emp_jornada');
SET @s := IF(@c = 0,
  'ALTER TABLE empleados ADD CONSTRAINT fk_emp_jornada
     FOREIGN KEY (jornada_id) REFERENCES jornadas(id) ON DELETE SET NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ========================================================================
 *  El resultado del día
 * ===================================================================== */
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencias'
             AND COLUMN_NAME = 'tardanza_min');
SET @s := IF(@c = 0,
  'ALTER TABLE asistencias
     ADD COLUMN jornada_id          INT UNSIGNED NULL DEFAULT NULL AFTER sucursal_id,
     ADD COLUMN tardanza_min        SMALLINT NOT NULL DEFAULT 0 AFTER horas_extra,
     ADD COLUMN salida_temprana_min SMALLINT NOT NULL DEFAULT 0 AFTER tardanza_min,
     ADD COLUMN horas_esperadas     DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER salida_temprana_min',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- `feriado` y `descanso` faltaban en el enum. Sin ellos, un domingo y el 27 de
-- febrero tenían que guardarse como «presente» o como «ausente», y las dos
-- cosas son mentira.
SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencias'
             AND COLUMN_NAME = 'estado');
SET @s := IF(LOCATE('feriado', @t) = 0,
  "ALTER TABLE asistencias MODIFY COLUMN estado
     ENUM('presente','ausente','tardanza','permiso','vacaciones','licencia','feriado','descanso')
     NOT NULL DEFAULT 'presente'",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ========================================================================
 *  Los permisos
 *
 *  Todo en el sistema tiene permiso. Se insertan en `permisos` Y se declaran
 *  en `permission_catalog()`: la pantalla de Roles solo pinta lo que está en
 *  el catálogo, y `roles.php` BORRA al guardar lo que no esté allí. Añadirlos
 *  solo aquí los haría desaparecer en el primer guardado de un rol.
 * ===================================================================== */
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (
  SELECT 'rrhh_jornadas.ver'       AS clave, 'rrhh_jornadas' AS modulo, 'Recursos Humanos' AS grupo,
         'Horarios de trabajo — Ver'                                  AS descripcion UNION ALL
  SELECT 'rrhh_jornadas.gestionar', 'rrhh_jornadas', 'Recursos Humanos',
         'Horarios de trabajo — Crear horarios y asignarlos a la gente'               UNION ALL
  SELECT 'rrhh_feriados.ver',       'rrhh_feriados', 'Recursos Humanos',
         'Feriados — Ver el calendario'                                               UNION ALL
  SELECT 'rrhh_feriados.gestionar', 'rrhh_feriados', 'Recursos Humanos',
         'Feriados — Añadir, quitar y regenerar el año'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

-- El Administrador los recibe de una vez: es el rol que ya tiene todo, y
-- dejarlo fuera obligaría a ir a buscarlos a mano tras cada despliegue.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.modulo IN ('rrhh_jornadas', 'rrhh_feriados')
 WHERE r.nombre = 'Administrador'
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- ---------------------------------------------------------------------------
--  Comprobación:
--    SELECT * FROM jornadas;
--    SELECT * FROM jornada_dias ORDER BY jornada_id, dia_semana;
--    SELECT COUNT(*) FROM feriados;
--    SHOW COLUMNS FROM asistencias LIKE 'estado';
--
--  Los feriados NO se siembran aquí: los calcula `jorSembrarFeriados()` en
--  includes/jornadas.php, que aplica la Ley 139-97 —el traslado al lunes— año
--  por año. Una lista escrita a mano en SQL caduca el 31 de diciembre.
-- ---------------------------------------------------------------------------
