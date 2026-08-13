-- ============================================================================
--  NexoPOS · P20 — Qué marca representa cada local
-- ----------------------------------------------------------------------------
--  El módulo de tiendas separó desde el principio dos cosas que se confunden:
--
--    Sucursal = DÓNDE se vende (stock, caja, usuarios).
--    Tienda   = CON QUÉ MARCA se vende (logo, colores, mensaje del ticket).
--
--  Esa separación es correcta y no se toca. Pero faltaba lo evidente: **no
--  existía ninguna relación entre las dos**. `productos`, `ventas`, `compras` y
--  `liquidaciones` tienen `tienda_id`; `sucursales` no.
--
--  Sin ese enlace, la tienda pública no puede saber que «Inglot Punta Cana» se
--  presenta como INGLOT: tendría que adivinarlo del nombre del local, que es
--  exactamente la clase de regla que se rompe el día que alguien renombre una
--  sucursal.
--
--  ---------------------------------------------------------------------------
--  POR QUÉ UNA SOLA MARCA POR LOCAL, SI UN LOCAL PUEDE ATENDER VARIAS
--
--  `sucursales.tienda_id` NO dice «este local solo vende esta marca». Dice
--  «este local se PRESENTA como esta marca»: es la identidad de su escaparate.
--
--  El caso de un local que factura con dos marcas sigue resuelto donde estaba
--  —`ventas.tienda_id` y `productos.tienda_id`—, y no se ve afectado. Aquí se
--  responde otra pregunta, la del cliente que abre la tienda en línea y elige
--  un local: qué logo y qué color tiene que ver.
--
--  Nulable a propósito: una oficina o un almacén no representa ninguna marca y
--  debe caer a la identidad de la empresa, no a una marca cualquiera.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

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

CALL nexo_add_col('sucursales', 'tienda_id',
  "INT UNSIGNED NULL COMMENT 'Marca con la que este local se presenta al cliente'");
CALL nexo_add_idx('sucursales', 'idx_suc_tienda', '`tienda_id`');

DROP PROCEDURE IF EXISTS nexo_add_col;
DROP PROCEDURE IF EXISTS nexo_add_idx;

-- ============================================================================
--  Comprobación:
--    SELECT s.nombre, t.nombre AS marca
--      FROM sucursales s LEFT JOIN tiendas t ON t.id = s.tienda_id
--     ORDER BY s.nombre;
-- ============================================================================
