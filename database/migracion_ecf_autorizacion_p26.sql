-- ============================================================================
--  NexoPOS · P26 — De qué autorización sale cada secuencia
-- ----------------------------------------------------------------------------
--  Hasta ahora `ncf_secuencias` guardaba el rango y su vencimiento, pero no de
--  DÓNDE salía. Con los rangos de prueba daba igual; con los reales no: cada
--  bloque lo autoriza la DGII con un **número de autorización**, y ese número
--  es lo que se cita cuando alguien pregunta de dónde viene un e-NCF.
--
--  Sin esta columna, la única forma de saberlo es buscar el PDF en el correo.
--
--  Idempotente. Vale en MariaDB 10.4 y en MySQL 8 —que NO soporta
--  `ADD COLUMN IF NOT EXISTS`, de ahí el rodeo por information_schema—.
-- ============================================================================

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ncf_secuencias'
             AND COLUMN_NAME = 'autorizacion');
SET @s := IF(@c = 0,
  'ALTER TABLE ncf_secuencias
     ADD COLUMN autorizacion VARCHAR(20) NULL AFTER vencimiento,
     ADD COLUMN autorizada_at DATE NULL AFTER autorizacion,
     ADD COLUMN ambiente VARCHAR(12) NOT NULL DEFAULT ''stage'' AFTER autorizada_at',
  'SELECT ''ncf_secuencias ya tiene los datos de autorización''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Las secuencias que hay ahora son las de PRUEBA. Se marcan como tales para
-- que no se confundan nunca con un rango autorizado de verdad: el día del
-- cambio a producción se ve de un vistazo cuál es cuál.
UPDATE ncf_secuencias
   SET ambiente = 'stage'
 WHERE tipo LIKE 'E%' AND autorizacion IS NULL AND ambiente <> 'stage';

-- ============================================================================
--  Comprobación:
--    SELECT tipo, secuencia_actual, secuencia_hasta, vencimiento,
--           autorizacion, ambiente FROM ncf_secuencias WHERE tipo LIKE 'E%';
--    -- todas deben salir con ambiente='stage' y autorizacion NULL
-- ============================================================================
