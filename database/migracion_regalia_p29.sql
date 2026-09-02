-- ---------------------------------------------------------------------------
-- La regalía pascual como un tipo de nómina propio.
--
-- El «salario de Navidad» (Código de Trabajo, arts. 219-222) no es una quincena
-- más: su base es una duodécima parte del salario ordinario del AÑO, no paga
-- ISR y no cotiza a la TSS. Pero sí se paga, sí sale de una cuenta, sí necesita
-- su archivo de banco y sí lleva volante, así que se guarda como una nómina y
-- reutiliza todo eso en vez de nacer con tablas paralelas.
--
-- Lo único que hace falta es que el `tipo` la admita. Se AÑADE al enum, no se
-- reescribe: quitar un valor de un enum con filas dentro las deja en blanco.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

SET @ya := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'nominas'
     AND COLUMN_NAME  = 'tipo'
     AND COLUMN_TYPE LIKE '%regalia%'
);

SET @sql := IF(@ya = 0,
  "ALTER TABLE nominas MODIFY COLUMN tipo ENUM('mensual','quincenal','semanal','regalia') NOT NULL DEFAULT 'mensual'",
  'DO 0');

PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;
