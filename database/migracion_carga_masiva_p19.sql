-- ============================================================================
--  NexoPOS · P19 — Carga masiva de catálogo, existencias y embarques
-- ----------------------------------------------------------------------------
--  El cargador de archivos (`includes/importador.php`) se construyó para que la
--  CEO metiera un año de clientes y ventas del sistema anterior. Ya resuelve lo
--  difícil: Excel y CSV con el BOM y el separador de Excel en español, mapeo de
--  columnas por alias, vista previa antes de escribir, tandas de 100 dentro de
--  transacciones y un lote que se revierte con un botón.
--
--  Solo sabía cargar dos cosas. Para una importadora con miles de SKU eso deja
--  fuera justo lo que más duele:
--
--    · El catálogo se crea producto por producto.
--    · Las existencias del almacén se capturan cantidad por cantidad.
--    · Cada línea de un embarque es un envío del formulario, y el producto
--      tiene que existir de antes: un contenedor de 300 artículos nuevos son
--      300 altas a mano y después 300 líneas de a una.
--
--  Esta migración abre el esquema para los tres tipos nuevos.
--
--  ---------------------------------------------------------------------------
--  POR QUÉ `importacion_id` EN TRES TABLAS MÁS
--
--  Es lo que hace la carga reversible. Sin la marca del lote, un archivo mal
--  mapeado se deshace restaurando un respaldo completo —y con él se va también
--  todo lo que pasó después—. Con la marca, se deshace lo que entró y nada más.
--
--  Cada tipo se revierte distinto, y por eso la marca va en una tabla distinta:
--
--    productos ............. el producto nace con la marca. Al revertir se
--                            borra el que no dejó rastro y se CONSERVA sin
--                            marca el que ya se vendió o ya tiene existencia:
--                            borrarlo se llevaría por delante ventas reales.
--    conteos ............... la carga de existencias no escribe stock, crea un
--                            conteo en borrador. Revertir es borrar el conteo
--                            que todavía nadie aplicó.
--    liquidacion_detalles .. las líneas que el packing list metió en una
--                            liquidación. Se quitan si la liquidación sigue
--                            editable.
--
--  ---------------------------------------------------------------------------
--  LA REGLA QUE NO SE PUEDE ROMPER
--
--  La carga de existencias NO escribe en `inventario_stock`. Genera un conteo
--  físico en borrador y deja que se aplique por el camino de siempre.
--
--  Un UPDATE directo dejaría el almacén con cantidades que no tienen origen:
--  `movimientos_inventario` no cuadraría con la existencia, el costeo mentiría
--  y una auditoría no podría explicar de dónde salió una unidad. Pasando por el
--  conteo, el ajuste entra al kardex con su motivo, su usuario y su fecha,
--  exactamente igual que si lo hubieran contado en el estante.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

-- ---------------------------------------------------------------------------
--  Los tres tipos nuevos
-- ---------------------------------------------------------------------------
--  Un ENUM es una restricción del esquema que ninguna prueba de lógica alcanza:
--  el código puede estar impecable y la carga revienta con «Data truncated».
--  Lo aprendimos en P17 con `ventas.tipo_comprobante`.

ALTER TABLE importaciones
  MODIFY COLUMN tipo
  ENUM('clientes','ventas','productos','existencias','embarque') NOT NULL;

-- `monto` guardaba la suma importada de ventas. Ahora también el valor FOB de
-- un embarque, que es lo que la CEO quiere ver en el historial del lote.

-- ---------------------------------------------------------------------------
--  La marca del lote en las tres tablas destino
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS nexo_add_col;
DELIMITER //
CREATE PROCEDURE nexo_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN def TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t)
     AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

DROP PROCEDURE IF EXISTS nexo_add_idx;
DELIMITER //
CREATE PROCEDURE nexo_add_idx(IN t VARCHAR(64), IN idx VARCHAR(64), IN cols TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t)
     AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = idx) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD INDEX `', idx, '` (', cols, ')');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- Nulable a propósito: lo que se creó a mano no pertenece a ningún lote, y esa
-- es justamente la diferencia que la reversión necesita distinguir.
CALL nexo_add_col('productos', 'importacion_id',
  "INT UNSIGNED NULL COMMENT 'Lote de carga masiva que creó este producto'");
CALL nexo_add_idx('productos', 'idx_prod_importacion', '`importacion_id`');

CALL nexo_add_col('conteos', 'importacion_id',
  "INT UNSIGNED NULL COMMENT 'Lote de carga de existencias que generó este conteo'");
CALL nexo_add_idx('conteos', 'idx_conteo_importacion', '`importacion_id`');

CALL nexo_add_col('liquidacion_detalles', 'importacion_id',
  "INT UNSIGNED NULL COMMENT 'Lote de packing list que metió esta línea'");
CALL nexo_add_idx('liquidacion_detalles', 'idx_liqdet_importacion', '`importacion_id`');

DROP PROCEDURE IF EXISTS nexo_add_col;
DROP PROCEDURE IF EXISTS nexo_add_idx;

-- ============================================================================
--  Comprobación:
--    SHOW COLUMNS FROM importaciones LIKE 'tipo';
--    SHOW COLUMNS FROM productos LIKE 'importacion_id';
--    SHOW COLUMNS FROM conteos LIKE 'importacion_id';
--    SHOW COLUMNS FROM liquidacion_detalles LIKE 'importacion_id';
-- ============================================================================
