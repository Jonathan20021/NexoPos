-- ---------------------------------------------------------------------------
-- P37 · Centro de Entrenamiento (Academia NexoPOS)
--
-- El sistema tiene 13 módulos y 71 permisos repartidos entre cajeros, almacén,
-- RRHH, contabilidad y dirección. Nadie usa los 13: cada persona usa los dos o
-- tres que su rol le abre. Por eso el entrenamiento NO es un manual único —
-- es un temario que se recorta solo con los permisos de quien entra.
--
-- El contenido del temario vive en código (`includes/entrenamiento_*.php`),
-- no en la base: se versiona con el resto del sistema y no hay que migrar
-- datos cuando una pantalla cambia. Aquí solo se guarda lo que es de cada
-- persona: por dónde va, qué terminó y qué sacó en la evaluación.
--
-- Idempotente. Probada en MariaDB 10.4 (local) y MySQL 8.0 (producción).
-- ---------------------------------------------------------------------------

-- ===================== AVANCE POR LECCIÓN =====================
-- Una fila por (usuario, lección). La clave única es lo que permite escribir
-- con INSERT ... ON DUPLICATE KEY UPDATE sin leer antes: dos pestañas abiertas
-- en la misma lección no crean dos filas ni pierden el avance de la otra.
CREATE TABLE IF NOT EXISTS entrenamiento_progreso (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  ruta_clave     VARCHAR(60)  NOT NULL,               -- ruta del temario (ej. 'pos')
  leccion_clave  VARCHAR(80)  NOT NULL,               -- lección (ej. 'pos.abrir-caja')
  estado         ENUM('en_curso','completada') NOT NULL DEFAULT 'en_curso',
  paso_actual    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pasos_total    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  segundos       INT UNSIGNED NOT NULL DEFAULT 0,     -- tiempo dedicado (aprox.)
  veces          SMALLINT UNSIGNED NOT NULL DEFAULT 1,-- cuántas veces la ha repasado
  iniciado_at    DATETIME NULL,
  completado_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ent_usuario_leccion (usuario_id, leccion_clave),
  KEY idx_ent_usuario_ruta (usuario_id, ruta_clave),
  KEY idx_ent_estado (estado, completado_at),
  CONSTRAINT fk_ent_prog_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== EVALUACIONES =====================
-- Historial completo: cada intento deja su fila. El certificado se emite con
-- el MEJOR intento, pero los intentos fallidos no se borran — para un puesto
-- de caja o de nómina, cuántas veces hizo falta también es información.
CREATE TABLE IF NOT EXISTS entrenamiento_evaluaciones (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  ruta_clave     VARCHAR(60)  NOT NULL,
  puntaje        SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- respuestas correctas
  total          SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- preguntas presentadas
  porcentaje     DECIMAL(5,2) NOT NULL DEFAULT 0,
  aprobado       TINYINT(1)   NOT NULL DEFAULT 0,
  segundos       INT UNSIGNED NOT NULL DEFAULT 0,
  respuestas     TEXT NULL,                            -- JSON: pregunta => elegida
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eval_usuario_ruta (usuario_id, ruta_clave, aprobado),
  KEY idx_eval_fecha (created_at),
  CONSTRAINT fk_ent_eval_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== PERMISOS =====================
-- El entrenamiento en sí NO lleva permiso: todo el que entra al sistema tiene
-- derecho a que le expliquen lo que puede tocar, y el temario ya se recorta
-- solo con sus permisos (quien no puede ver la nómina tampoco ve su lección).
--
-- Lo que sí lleva permiso es mirar el avance ajeno: eso es supervisión de
-- personal, no formación propia.
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('entrenamiento.equipo',  'entrenamiento', 'Entrenamiento',
  'Centro de Entrenamiento — Ver el avance y las evaluaciones de todo el equipo'),
 ('entrenamiento.asignar', 'entrenamiento', 'Entrenamiento',
  'Centro de Entrenamiento — Reiniciar el avance de otra persona');

-- Quien ya administra usuarios supervisa gente: hereda la vista de equipo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pv ON pv.id = rp.permiso_id AND pv.clave = 'usuarios.ver'
  CROSS JOIN permisos p
 WHERE p.clave = 'entrenamiento.equipo';

-- Reiniciarle el avance a otro es más que mirar: solo a quien ya edita usuarios.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pv ON pv.id = rp.permiso_id AND pv.clave = 'usuarios.editar'
  CROSS JOIN permisos p
 WHERE p.clave = 'entrenamiento.asignar';
