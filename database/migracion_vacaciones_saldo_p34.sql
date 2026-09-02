-- ---------------------------------------------------------------------------
-- Las vacaciones tienen saldo, y las ya disfrutadas no se pagan otra vez.
--
-- Dos cosas que costaban dinero:
--
--   1. Los días se contaban de calendario (datediff + 1). El art. 177 concede
--      días LABORABLES, así que una quincena de asueto se apuntaba como 14 días
--      cuando legalmente eran 12: dos días de más consumidos del derecho de la
--      persona, cada vez.
--
--   2. La liquidación pagaba las vacaciones proporcionales del año de servicio
--      en curso SIN restar las que la persona ya se había tomado en ese mismo
--      año. Se pagaban dos veces: una al disfrutarlas y otra al liquidar.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'vacaciones'
     AND COLUMN_NAME  = 'dias_laborables'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE vacaciones ADD COLUMN dias_laborables DECIMAL(6,2) NULL DEFAULT NULL AFTER dias',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Lo que ya estaba apuntado conserva su número: recontarlo hacia atrás
-- cambiaría saldos que alguien ya dio por buenos.
UPDATE vacaciones SET dias_laborables = dias WHERE dias_laborables IS NULL;
