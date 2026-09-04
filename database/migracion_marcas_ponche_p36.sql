-- ---------------------------------------------------------------------------
-- Las marcas del reloj, una por una.
--
-- `asistencias` guarda el día resumido: entrada, salida y horas. Eso es lo que
-- necesita la nómina, pero pierde lo de en medio. Nancy ponchó cuatro veces el
-- 24 de agosto —10:23, 10:24, 13:25 y 18:02— y solo quedaban la primera y la
-- última: la salida a almorzar y el regreso desaparecían, y con ellos la única
-- forma de responder «¿a qué hora se fue a comer?».
--
-- Esta tabla guarda el dato en bruto, tal como lo dio el aparato, para poder
-- consultarlo entero. `asistencias` se sigue calculando A PARTIR de aquí.
--
-- Dos cosas que hacen falta y no son obvias:
--
--   biotime_id  ÚNICO. Es el identificador de la marca en el reloj, y es lo
--               que hace que traer dos veces el mismo rango no duplique nada.
--
--   desfase_min La diferencia entre la hora de la marca y la de subida. En RD
--               tiene que ser de −240 minutos (UTC−4). Cuando no lo es, el
--               aparato tiene el reloj mal puesto y su hora no es de fiar; en
--               el histórico de este cliente hay una marca con 17 horas de
--               desvío. Sin guardar esto no hay forma de detectarlo después.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS asistencia_marcas (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  biotime_id    BIGINT UNSIGNED NOT NULL,
  emp_code      VARCHAR(20)  NOT NULL,
  empleado_id   INT UNSIGNED NULL,
  fecha         DATE         NOT NULL,
  hora          TIME         NOT NULL,
  marcada_en    DATETIME     NOT NULL,
  subida_en     DATETIME     NULL,
  desfase_min   INT          NULL,
  terminal      VARCHAR(80)  NULL,
  verificacion  VARCHAR(30)  NULL,
  nombre_reloj  VARCHAR(120) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marca_reloj (biotime_id),
  KEY ix_marca_fecha (fecha),
  KEY ix_marca_empleado (empleado_id, fecha),
  KEY ix_marca_code (emp_code, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
