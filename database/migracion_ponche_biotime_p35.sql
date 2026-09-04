-- ---------------------------------------------------------------------------
-- El ponche del reloj biométrico entra en `asistencias`.
--
-- Tres columnas, y cada una evita un fallo concreto:
--
--   empleados.biotime_emp_code
--     El reloj identifica por un correlativo (1, 2, 3…) que no significa nada
--     en Nexo. Emparejar por nombre elige mal: al probarlo, «Martzabel Lora»
--     fue a dar a «Soraya Lora Mercedes» cuando es «Maritzabel Lora Piña». Una
--     equivocación aquí es el ponche de una persona en la nómina de otra, así
--     que la equivalencia se guarda EXPLÍCITA y la confirma un humano. Índice
--     único: dos personas no pueden compartir el mismo código de reloj.
--
--   asistencias.origen
--     Quién escribió la fila la última vez. Si alguien corrigió un día a mano
--     —olvidó ponchar la salida y se la pusieron—, la sincronización siguiente
--     NO puede pisarlo: avisa de la diferencia y deja lo del humano.
--
--   asistencias.biotime_sync_at
--     Cuándo se trajo. Sin esto no hay forma de saber si una fila es de ayer o
--     de hace tres semanas cuando el cron llevaba días caído.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'biotime_emp_code'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE empleados ADD COLUMN biotime_emp_code VARCHAR(20) NULL DEFAULT NULL AFTER codigo',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'empleados' AND INDEX_NAME = 'uq_empleados_biotime'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE empleados ADD UNIQUE KEY uq_empleados_biotime (biotime_emp_code)',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'asistencias' AND COLUMN_NAME = 'origen'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE asistencias ADD COLUMN origen VARCHAR(10) NOT NULL DEFAULT 'manual' AFTER notas",
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'asistencias' AND COLUMN_NAME = 'biotime_sync_at'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE asistencias ADD COLUMN biotime_sync_at DATETIME NULL DEFAULT NULL AFTER origen',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Todo lo que ya estaba lo escribió una persona: es lo único que había.
UPDATE asistencias SET origen = 'manual' WHERE origen IS NULL OR origen = '';
