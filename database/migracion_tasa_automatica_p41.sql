-- ---------------------------------------------------------------------------
-- P41 · La tasa de venta puede seguir al mercado sola
--
-- La P39 dejó la tasa de venta a mano a propósito: que el precio de góndola no
-- cambie cada mañana. La tienda pide lo contrario: su catálogo está en dólares
-- y quiere cobrar al dólar del día sin que nadie tenga que escribirlo.
--
-- Las dos formas conviven, moneda por moneda:
--
--   venta_auto = 0  → como hasta ahora: la tasa de venta se escribe a mano y
--                     la de mercado solo avisa.
--   venta_auto = 1  → cada vez que se descarga la tasa de mercado, la de venta
--                     pasa a ser  mercado × (1 + venta_margen / 100).
--                     El margen sirve para vender un poco por encima del
--                     mercado (p. ej. +1.5 %) y cubrir la reposición.
--
-- Igual que antes, nunca se reescribe el pasado: cada venta congela en pesos
-- lo que cobró, y cada cambio de tasa queda en monedas_historial.
--
-- Idempotente. Requiere la P39 (migracion_moneda_producto_p39.sql).
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS nexo_add_col;
DELIMITER //
CREATE PROCEDURE nexo_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- ¿Es la primera vez? Solo entonces se enciende el dólar (más abajo): si se
-- vuelve a correr después de que alguien lo pasara a mano, no se le pisa.
SET @p41_primera := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'venta_auto');

CALL nexo_add_col('monedas', 'venta_auto',   'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "La tasa de venta sigue a la de mercado" AFTER `auto_actualizar`');
CALL nexo_add_col('monedas', 'venta_margen', 'DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT "% sobre la tasa de mercado (puede ser negativo)" AFTER `venta_auto`');

-- El dólar pasa a automático: es lo que pidió la tienda. Se puede apagar en
-- Administración → Monedas y volver a la tasa a mano. Solo la primera vez.
UPDATE monedas SET venta_auto = 1, auto_actualizar = 1 WHERE codigo = 'USD' AND es_base = 0 AND @p41_primera = 1;

DROP PROCEDURE IF EXISTS nexo_add_col;

-- Verificación: las dos columnas deben decir OK.
SELECT 'monedas.venta_auto' AS item,
       IF(COUNT(*) = 1, 'OK', 'FALTA') AS estado
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'venta_auto'
UNION ALL
SELECT 'monedas.venta_margen', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'venta_margen';

-- ---------------------------------------------------------------------------
-- Reversión
--   ALTER TABLE monedas DROP COLUMN venta_margen, DROP COLUMN venta_auto;
-- ---------------------------------------------------------------------------
