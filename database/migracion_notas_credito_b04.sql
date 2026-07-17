-- ============================================================================
--  Migración — Notas de crédito (B04) sobre devoluciones
--
--  Cierra el hueco fiscal que dejó ver el IT-1: una devolución rebaja el ITBIS
--  facturado mediante una nota de crédito (B04) que referencia el NCF original.
--  Antes la devolución no emitía comprobante, así que el débito fiscal no la
--  descontaba. Ahora la devolución de una venta con NCF emite un B04, que entra
--  al 607 y baja el débito del IT-1.
--
--  Idempotente. Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Columnas fiscales en devoluciones
--     ncf             — el NCF de la nota de crédito (B04)
--     ncf_modificado  — el NCF de la venta que se está corrigiendo
--     subtotal / itbis — desglose de lo devuelto (el 607 los necesita por separado)
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devoluciones' AND COLUMN_NAME='ncf');
SET @s := IF(@c=0, 'ALTER TABLE devoluciones ADD COLUMN ncf VARCHAR(19) NULL AFTER motivo, ADD COLUMN ncf_modificado VARCHAR(19) NULL AFTER ncf', 'SELECT ''devoluciones.ncf ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devoluciones' AND COLUMN_NAME='subtotal');
SET @s := IF(@c=0, 'ALTER TABLE devoluciones ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER ncf_modificado, ADD COLUMN itbis DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal', 'SELECT ''devoluciones.subtotal ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Índice para que el 607/IT-1 filtren rápido las devoluciones con NCF por período.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devoluciones' AND INDEX_NAME='idx_dev_ncf');
SET @s := IF(@c=0, 'ALTER TABLE devoluciones ADD INDEX idx_dev_ncf (ncf)', 'SELECT ''idx_dev_ncf ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2) Secuencia de NCF para notas de crédito (B04)
--     Se siembra un rango por defecto igual que B01/B02. En producción se ajusta
--     en Configuración → Comprobantes (NCF) con el rango real que asignó la DGII.
-- ---------------------------------------------------------------------------
INSERT INTO ncf_secuencias (tipo, descripcion, prefijo, secuencia_actual, secuencia_hasta, vencimiento, activo)
SELECT 'B04', 'Nota de Crédito', 'B', 1, 99999999, '2027-12-31', 1
WHERE NOT EXISTS (SELECT 1 FROM ncf_secuencias WHERE tipo = 'B04');

-- Verificación
SELECT 'devoluciones.ncf' item, COUNT(*) ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devoluciones' AND COLUMN_NAME='ncf'
UNION ALL SELECT 'devoluciones.itbis', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devoluciones' AND COLUMN_NAME='itbis'
UNION ALL SELECT 'secuencia B04', COUNT(*) FROM ncf_secuencias WHERE tipo='B04';

-- REVERSIÓN:
--   ALTER TABLE devoluciones DROP INDEX idx_dev_ncf,
--     DROP COLUMN itbis, DROP COLUMN subtotal, DROP COLUMN ncf_modificado, DROP COLUMN ncf;
--   DELETE FROM ncf_secuencias WHERE tipo='B04';
