-- ============================================================================
--  Migración P2 — Canal de venta (medición de marketing / Instagram)
--
--  La cliente hace anuncios en Instagram y no tiene forma de medir su impacto.
--  Se agrega el canal por el que llegó cada venta, para reportar ventas por canal.
--
--  Idempotente. Compatible con MySQL 8 y MariaDB. Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) VENTAS: canal de captación.
--    NULL admitido: las ventas viejas no tienen canal conocido y se agrupan
--    como «Sin especificar» en los reportes.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='canal_venta');
SET @s := IF(@c=0,
  'ALTER TABLE ventas ADD COLUMN canal_venta VARCHAR(40) NULL AFTER notas',
  'SELECT ''ventas.canal_venta ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Índice para el reporte de ventas por canal.
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND INDEX_NAME='idx_ventas_canal');
SET @s := IF(@i=0,
  'ALTER TABLE ventas ADD KEY idx_ventas_canal (canal_venta)',
  'SELECT ''idx_ventas_canal ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2) Los pedidos de la tienda que ya se facturaron son, por definición, canal
--    «Tienda online». Se marca el histórico.
-- ---------------------------------------------------------------------------
UPDATE ventas v
   JOIN pedidos p ON p.venta_id = v.id
   SET v.canal_venta = 'Tienda online'
 WHERE v.canal_venta IS NULL;

-- ---------------------------------------------------------------------------
-- 3) Verificación
-- ---------------------------------------------------------------------------
SELECT 'ventas.canal_venta' AS item, COUNT(*) AS ok
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='canal_venta'
UNION ALL SELECT 'ventas con canal', COUNT(*) FROM ventas WHERE canal_venta IS NOT NULL;

-- REVERSIÓN:
--   ALTER TABLE ventas DROP KEY idx_ventas_canal, DROP COLUMN canal_venta;
