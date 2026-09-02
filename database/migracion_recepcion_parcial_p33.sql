-- ---------------------------------------------------------------------------
-- Recibir lo que llegó, no lo que se envió.
--
-- Recibir un traslado agregaba SIEMPRE la cantidad enviada. Si salieron 10 y
-- llegaron 8, el sistema decía 10 y el estante tenía 8: un fantasma que no
-- aparecía hasta el conteo físico siguiente, meses después, sin rastro de dónde
-- se perdió.
--
-- Ahora cada línea guarda cuánto llegó de verdad y el traslado guarda por qué
-- faltó. La diferencia deja de ser invisible: sale en «Ajustes y mermas».
--
-- Idempotente.
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'transferencia_detalles'
     AND COLUMN_NAME  = 'cantidad_recibida'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE transferencia_detalles ADD COLUMN cantidad_recibida DECIMAL(12,3) NULL DEFAULT NULL AFTER cantidad',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'transferencias'
     AND COLUMN_NAME  = 'notas_recepcion'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE transferencias ADD COLUMN notas_recepcion VARCHAR(500) NULL DEFAULT NULL AFTER motivo_rechazo',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Lo ya recibido se recibió entero: esa era la única opción que había. Sin
-- esto, todo el histórico parecería pendiente de cuadrar. Solo toca las filas
-- en NULL, así que repetirlo no pisa un valor bueno.
UPDATE transferencia_detalles td
  JOIN transferencias t ON t.id = td.transferencia_id
   SET td.cantidad_recibida = td.cantidad
 WHERE t.estado = 'recibida' AND td.cantidad_recibida IS NULL;
