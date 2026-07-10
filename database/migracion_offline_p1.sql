-- ============================================================================
--  Migración P3 — Modo offline Fase 1 (identidad idempotente de la venta)
--
--  El POS puede vender sin internet: guarda la venta localmente con un UUID y la
--  sincroniza al volver la conexión. ventas.uuid garantiza que reenviar la misma
--  venta NO cree un duplicado (ni consuma un NCF de más).
--
--  Idempotente. UNIQUE sobre uuid admite múltiples NULL (ventas normales).
--  Reversión al final.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='uuid');
SET @s := IF(@c=0,
  'ALTER TABLE ventas ADD COLUMN uuid CHAR(36) NULL AFTER numero',
  'SELECT ''ventas.uuid ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND INDEX_NAME='uq_ventas_uuid');
SET @s := IF(@i=0,
  'ALTER TABLE ventas ADD UNIQUE KEY uq_ventas_uuid (uuid)',
  'SELECT ''uq_ventas_uuid ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'ventas.uuid' AS item, COUNT(*) AS ok
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='uuid'
UNION ALL SELECT 'indice uq_ventas_uuid', COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND INDEX_NAME='uq_ventas_uuid';

-- REVERSIÓN:
--   ALTER TABLE ventas DROP INDEX uq_ventas_uuid, DROP COLUMN uuid;
