-- ---------------------------------------------------------------------------
-- P39 · Precios en moneda extranjera y tasa de mercado automática
--
-- Dos cosas que faltaban, y que salieron a la luz cargando el catálogo de
-- INGLOT: sus 876 productos tienen la lista de precios en DÓLARES, y
-- `productos` no tenía dónde decirlo. Al guardarlos tal cual, el punto de
-- venta cobraría RD$29 por un labial de US$29.
--
-- ---------------------------------------------------------------------------
--  LAS DOS TASAS, Y POR QUÉ SON DOS
-- ---------------------------------------------------------------------------
-- `monedas.tasa` es la TASA DE VENTA: la que usan el POS y los documentos. Se
-- cambia A MANO y no se mueve sola. Decisión del negocio: el precio de góndola
-- no puede cambiar cada mañana porque el dólar se movió diez centavos, y una
-- etiqueta impresa dejaría de cuadrar con la caja el mismo día.
--
-- `monedas.tasa_mercado` es la REFERENCIA que se descarga sola. No cobra nada:
-- existe para que la pantalla pueda decir «el mercado está en 61.25 y tú sigues
-- vendiendo a 60.00» y para que la campana avise cuando la diferencia se hace
-- grande o cuando hace semanas que nadie revisa la tasa.
--
-- Nunca se reescribe el pasado. Cada compra, cotización y venta ya congela la
-- tasa con la que se hizo; esto solo cambia lo que se propone de aquí en
-- adelante. Es la misma regla de docs/CXP-MONEDAS-COTIZACIONES.md.
--
-- Idempotente. Probada en MariaDB 10.4 y MySQL 8.0.
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

DROP PROCEDURE IF EXISTS nexo_add_idx;
DELIMITER //
CREATE PROCEDURE nexo_add_idx(IN t VARCHAR(64), IN i VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = i) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD KEY `', i, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- ===========================================================================
-- 1. La moneda del producto
-- ---------------------------------------------------------------------------
-- NULL = moneda base (pesos), que es como se comportan hoy los 878 productos.
-- Así la columna se puede añadir sin tocar ni un precio: lo que no declara
-- moneda sigue siendo pesos, exactamente igual que antes.
-- ===========================================================================
CALL nexo_add_col('productos', 'moneda_id', 'INT UNSIGNED NULL COMMENT "NULL = moneda base (RD$)" AFTER `precio_venta`');
CALL nexo_add_idx('productos', 'idx_prod_moneda', '(`moneda_id`)');

-- ===========================================================================
-- 2. La tasa de mercado que se descarga sola
-- ===========================================================================
CALL nexo_add_col('monedas', 'tasa_mercado',        'DECIMAL(14,6) NULL COMMENT "Referencia descargada. NO se usa para cobrar." AFTER `tasa`');
CALL nexo_add_col('monedas', 'tasa_mercado_at',     'DATETIME NULL AFTER `tasa_mercado`');
CALL nexo_add_col('monedas', 'tasa_mercado_fuente', 'VARCHAR(40) NULL AFTER `tasa_mercado_at`');
CALL nexo_add_col('monedas', 'auto_actualizar',     'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Descargar la tasa de mercado de esta moneda" AFTER `tasa_mercado_fuente`');
CALL nexo_add_col('monedas', 'tasa_revisada_at',    'DATETIME NULL COMMENT "Última vez que una persona confirmó la tasa de venta" AFTER `auto_actualizar`');

-- El dólar es la única que interesa seguir hoy. El euro está inactivo.
UPDATE monedas SET auto_actualizar = 1 WHERE codigo = 'USD';

-- Para que la alerta de «hace semanas que nadie la revisa» no dispare el primer
-- día contra una fecha nula, se siembra con la última vez que se tocó la fila.
UPDATE monedas SET tasa_revisada_at = COALESCE(tasa_revisada_at, updated_at, NOW()) WHERE es_base = 0;

-- ===========================================================================
-- 3. Historial de la tasa
--
-- `auditoria` ya registra quién cambió qué, pero en texto libre: sirve para
-- responsabilizar, no para graficar ni para responder «¿a qué tasa estábamos el
-- 3 de agosto?». Esta tabla sí, y es lo que alimenta la pantalla de monedas.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS monedas_historial (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  moneda_id   INT UNSIGNED NOT NULL,
  tasa        DECIMAL(14,6) NOT NULL,                      -- la que quedó vigente
  origen      ENUM('manual','automatica') NOT NULL DEFAULT 'manual',
  campo       ENUM('venta','mercado') NOT NULL DEFAULT 'venta',
  fuente      VARCHAR(40) NULL,                            -- de dónde salió la automática
  usuario_id  INT UNSIGNED NULL,
  nota        VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mh_moneda (moneda_id, campo, created_at),
  CONSTRAINT fk_mh_moneda FOREIGN KEY (moneda_id) REFERENCES monedas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Punto de partida del historial: la tasa que hay hoy, para que la pantalla no
-- aparezca vacía y para no perder de dónde se venía.
INSERT INTO monedas_historial (moneda_id, tasa, origen, campo, nota)
SELECT m.id, m.tasa, 'manual', 'venta', 'Tasa vigente al aplicar la migración P39'
  FROM monedas m
 WHERE m.es_base = 0
   AND NOT EXISTS (SELECT 1 FROM monedas_historial h WHERE h.moneda_id = m.id AND h.campo = 'venta');

DROP PROCEDURE IF EXISTS nexo_add_col;
DROP PROCEDURE IF EXISTS nexo_add_idx;

-- ---------------------------------------------------------------------------
-- REVERSIÓN (si hiciera falta deshacerla)
--   ALTER TABLE productos DROP COLUMN moneda_id;
--   ALTER TABLE monedas DROP COLUMN tasa_mercado, DROP COLUMN tasa_mercado_at,
--     DROP COLUMN tasa_mercado_fuente, DROP COLUMN auto_actualizar,
--     DROP COLUMN tasa_revisada_at;
--   DROP TABLE monedas_historial;
-- Los precios de los productos NO se tocan en ningún caso: lo que esta
-- migración añade es la etiqueta de en qué moneda están, nunca el número.
-- ---------------------------------------------------------------------------
