-- ============================================================================
--  Migración: agrega devolucion_detalles.venta_detalle_id
--
--  Bases creadas con un esquema anterior no tienen esta columna. El módulo de
--  devoluciones la consulta al calcular cuánto se devolvió ya de cada línea,
--  así que la página muere con «Unknown column 'dd.venta_detalle_id'» y la
--  tabla de productos se ve vacía.
--
--  Idempotente: no hace nada si la columna ya existe.
-- ============================================================================

SET @hay_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'devolucion_detalles'
                   AND COLUMN_NAME = 'venta_detalle_id');

SET @sql := IF(@hay_col = 0,
  'ALTER TABLE devolucion_detalles
     ADD COLUMN venta_detalle_id INT UNSIGNED NULL AFTER devolucion_id,
     ADD KEY idx_dd_venta_detalle (venta_detalle_id),
     ADD CONSTRAINT fk_dd_venta_detalle FOREIGN KEY (venta_detalle_id)
         REFERENCES venta_detalles(id) ON DELETE SET NULL',
  'SELECT ''columna venta_detalle_id ya existe'' AS estado');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Restricción de valores presente en schema.sql pero ausente en bases viejas.
SET @hay_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'devolucion_detalles'
                   AND CONSTRAINT_NAME = 'chk_devolucion_detalle_valores');

SET @sql2 := IF(@hay_chk = 0,
  'ALTER TABLE devolucion_detalles
     ADD CONSTRAINT chk_devolucion_detalle_valores
     CHECK (cantidad > 0 AND precio_unitario >= 0 AND subtotal >= 0)',
  'SELECT ''check ya existe'' AS estado');
PREPARE st2 FROM @sql2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- ---------------------------------------------------------------------------
-- REVERSIÓN (solo si hiciera falta deshacer):
--   ALTER TABLE devolucion_detalles DROP FOREIGN KEY fk_dd_venta_detalle;
--   ALTER TABLE devolucion_detalles DROP INDEX idx_dd_venta_detalle;
--   ALTER TABLE devolucion_detalles DROP COLUMN venta_detalle_id;
-- ---------------------------------------------------------------------------
