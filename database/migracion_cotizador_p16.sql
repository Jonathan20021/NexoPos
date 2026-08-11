-- ============================================================================
--  NexoPOS · P16 — Cotizador: descuento por línea, líneas de servicio,
--                  facturación parcial y plantilla configurable
-- ----------------------------------------------------------------------------
--  Cuatro carencias del cotizador actual, en orden de lo que más dolía:
--
--  1. FACTURAR ERA TODO O NADA. El cliente pide diez y se lleva cuatro, y no
--     había forma de emitir la factura por cuatro. Ahora cada línea recuerda
--     `cantidad_facturada` y la cotización se cierra dejando por escrito lo que
--     el cliente NO se llevó — que también es información: dice qué se ofreció
--     y no se vendió.
--
--  2. UNA LÍNEA ESCRITA A MANO BLOQUEABA LA FACTURA ENTERA. Cotizar
--     «instalación» o «flete» dejaba la cotización imposible de convertir,
--     porque el motor de ventas exige un producto del catálogo. Se marcan como
--     `es_servicio` y al facturar se enlazan a un producto de tipo servicio
--     —que no toca inventario— llevando su descripción real.
--
--  3. SOLO HABÍA DESCUENTO GLOBAL. Negociar un renglón obligaba a bajar el
--     precio a mano y perder el rastro de cuánto se rebajó. Ahora el descuento
--     por línea se guarda como porcentaje Y como monto: el porcentaje es lo que
--     se pactó, el monto es lo que se aplicó, y con redondeos no siempre uno se
--     deduce del otro.
--
--  4. TODO ERA FIJO. La validez, las condiciones, el pie y las columnas que se
--     imprimen se reescribían en cada cotización. Pasan a `cotizacion_config`,
--     que además guarda los campos propios del negocio (obra, orden de compra,
--     contacto) que salen en el documento.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

-- ---------------------------------------------------------------------------
--  Helpers: añadir columna solo si no existe (MySQL 8 no soporta
--  `ADD COLUMN IF NOT EXISTS`, que MariaDB sí).
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

-- ---------------------------------------------------------------------------
--  Líneas de la cotización
-- ---------------------------------------------------------------------------

-- El porcentaje pactado y el monto realmente aplicado. Se guardan los dos
-- porque con redondeo a dos decimales el monto no siempre se reconstruye desde
-- el porcentaje, y en una negociación importa poder enseñar ambos.
CALL nexo_add_col('cotizacion_detalles', 'descuento_pct',
                  "DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Descuento pactado en la línea, en %'");
CALL nexo_add_col('cotizacion_detalles', 'descuento_monto',
                  "DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Descuento aplicado en la línea, en importe'");

-- Cuánto de esta línea llegó a facturarse. Es lo que permite cerrar la
-- cotización dejando constancia de lo que el cliente no se llevó.
CALL nexo_add_col('cotizacion_detalles', 'cantidad_facturada',
                  "DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Unidades que entraron en la factura'");

-- Línea sin producto del catálogo que aun así se factura (instalación, flete,
-- montaje). Al convertir se enlaza al producto de servicio de la configuración.
CALL nexo_add_col('cotizacion_detalles', 'es_servicio',
                  "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Concepto libre facturable como servicio'");

-- ---------------------------------------------------------------------------
--  Cabecera de la cotización
-- ---------------------------------------------------------------------------

-- Campos propios del negocio, como objeto JSON {clave: valor}. Van en TEXT y no
-- en JSON nativo porque MariaDB 10.4 lo trata como alias de LONGTEXT y no
-- aporta nada aquí; lo que se guarda son cuatro pares de cadenas.
CALL nexo_add_col('cotizaciones', 'campos_extra',
                  "TEXT NULL COMMENT 'Valores de los campos propios definidos en cotizacion_config'");

-- ---------------------------------------------------------------------------
--  Configuración del cotizador (una sola fila)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_config (
  id                    TINYINT UNSIGNED NOT NULL DEFAULT 1,

  -- Valores por defecto al crear una cotización nueva.
  validez_dias          SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  condiciones           TEXT NULL,
  pie                   VARCHAR(500) NULL,
  mensaje_cierre        VARCHAR(255) NULL,

  -- Numeración. El prefijo es lo único configurable: el correlativo lo sigue
  -- llevando nextNumero(), que ya garantiza que no se repita bajo concurrencia.
  prefijo               VARCHAR(10) NOT NULL DEFAULT 'COT',

  -- Qué se imprime. Un negocio que vende a consumidor final no quiere ver una
  -- columna de ITBIS desglosada; uno que vende a empresas, sí.
  mostrar_itbis         TINYINT(1) NOT NULL DEFAULT 1,
  mostrar_sku           TINYINT(1) NOT NULL DEFAULT 1,
  mostrar_descuento     TINYINT(1) NOT NULL DEFAULT 1,

  -- Producto de tipo servicio al que se enlazan las líneas libres al facturar.
  -- NULL = todavía sin configurar; el cotizador avisa antes de dejar facturar
  -- una línea de servicio.
  producto_servicio_id  INT UNSIGNED NULL,

  -- Definición de los campos propios: [{clave, etiqueta}, …].
  campos                TEXT NULL,

  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La fila única. INSERT IGNORE para que volver a correr la migración no pise
-- lo que el cliente ya haya configurado.
INSERT IGNORE INTO cotizacion_config (id, condiciones, mensaje_cierre) VALUES (
  1,
  'Precios sujetos a cambio sin previo aviso una vez vencida esta cotización.\nLa mercancía se despacha contra confirmación de disponibilidad.',
  'Gracias por su interés. Quedamos atentos a su confirmación.'
);

-- ---------------------------------------------------------------------------
--  Estado: se añade «parcial» al catálogo aunque el flujo elegido cierre la
--  cotización al facturar. Un ENUM que no lo contemple obligaría a otra
--  migración el día que se quiera dejar el resto pendiente, y ampliar un ENUM
--  sobre una tabla con datos es más caro que preverlo ahora.
-- ---------------------------------------------------------------------------
ALTER TABLE cotizaciones
  MODIFY COLUMN estado ENUM('borrador','enviada','aceptada','rechazada','vencida','parcial','facturada')
  NOT NULL DEFAULT 'borrador';

DROP PROCEDURE IF EXISTS nexo_add_col;

-- ============================================================================
--  Comprobación rápida tras aplicar:
--
--    SELECT descuento_pct, descuento_monto, cantidad_facturada, es_servicio
--      FROM cotizacion_detalles LIMIT 1;
--    SELECT * FROM cotizacion_config;
-- ============================================================================
