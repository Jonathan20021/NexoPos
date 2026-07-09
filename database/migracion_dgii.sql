-- ============================================================================
--  Migración: campos fiscales DGII (Formatos 606, 607 y 608)
--
--  Fuente: instructivos oficiales de la DGII (Norma General 07-2018 y 05-2019)
--    606 - Compras de Bienes y Servicios ..... 23 columnas
--    607 - Ventas de Bienes y Servicios ...... 23 columnas
--    608 - Comprobantes Anulados ..............  3 columnas
--
--  Idempotente: cada bloque comprueba una columna centinela antes de aplicar.
--  Compatible con MySQL 8 y MariaDB (no usa ADD COLUMN IF NOT EXISTS).
--
--  REVERSIÓN al final del archivo.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) PROVEEDORES: Tipo de Identificación (606, columna 2)
--    1 = RNC   |   2 = Cédula
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='proveedores' AND COLUMN_NAME='tipo_id');
SET @s := IF(@c=0,
  'ALTER TABLE proveedores ADD COLUMN tipo_id TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER rnc',
  'SELECT ''proveedores.tipo_id ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2) CLIENTES: Tipo de Identificación (607, columna 2)
--    1 = RNC   |   2 = Cédula   |   3 = Pasaporte / ID tributaria
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clientes' AND COLUMN_NAME='tipo_id');
SET @s := IF(@c=0,
  'ALTER TABLE clientes ADD COLUMN tipo_id TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER rnc_cedula',
  'SELECT ''clientes.tipo_id ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3) METODOS_PAGO: mapeo a la clasificación de la DGII.
--    Semántica del 607 (columnas 17-23):
--      1 Efectivo | 2 Cheque/Transferencia/Depósito | 3 Tarjeta Débito/Crédito
--      4 Venta a Crédito | 5 Bonos o Certificados de Regalo | 6 Permuta
--      7 Otras Formas de Ventas
--    El 606 (columna 23 «Forma de Pago») reutiliza 1-4 y difiere en 5-7;
--    la conversión se hace en código, no en la base.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='metodos_pago' AND COLUMN_NAME='dgii_tipo_pago');
SET @s := IF(@c=0,
  'ALTER TABLE metodos_pago ADD COLUMN dgii_tipo_pago TINYINT UNSIGNED NOT NULL DEFAULT 7 AFTER es_credito',
  'SELECT ''metodos_pago.dgii_tipo_pago ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 4) COMPRAS: campos del Formato 606
--    Centinela: compras.ncf
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compras' AND COLUMN_NAME='ncf');
SET @s := IF(@c=0, '
ALTER TABLE compras
  ADD COLUMN ncf VARCHAR(19) NULL AFTER numero,
  ADD COLUMN ncf_modificado VARCHAR(19) NULL AFTER ncf,
  ADD COLUMN tipo_bien_servicio TINYINT UNSIGNED NULL AFTER proveedor_id,
  ADD COLUMN fecha_comprobante DATE NULL AFTER fecha,
  ADD COLUMN fecha_pago DATE NULL AFTER fecha_comprobante,
  ADD COLUMN monto_bienes DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN monto_servicios DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER monto_bienes,
  ADD COLUMN itbis_retenido DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER itbis,
  ADD COLUMN itbis_proporcionalidad DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER itbis_retenido,
  ADD COLUMN itbis_costo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER itbis_proporcionalidad,
  ADD COLUMN itbis_percibido DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER itbis_costo,
  ADD COLUMN tipo_retencion_isr TINYINT UNSIGNED NULL AFTER itbis_percibido,
  ADD COLUMN monto_retencion_renta DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER tipo_retencion_isr,
  ADD COLUMN isr_percibido DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER monto_retencion_renta,
  ADD COLUMN impuesto_selectivo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER isr_percibido,
  ADD COLUMN otros_impuestos DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER impuesto_selectivo,
  ADD COLUMN propina_legal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER otros_impuestos,
  ADD COLUMN forma_pago TINYINT UNSIGNED NULL AFTER propina_legal,
  ADD KEY idx_compras_ncf (ncf),
  ADD KEY idx_compras_comprobante (fecha_comprobante)
', 'SELECT ''compras ya migrada''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 5) VENTAS: campos del Formato 607
--    El desglose de cobro (columnas 17-23) NO se duplica aquí: se deriva de
--    venta_pagos + metodos_pago.dgii_tipo_pago, que es la fuente de verdad.
--    Centinela: ventas.tipo_ingreso
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='tipo_ingreso');
SET @s := IF(@c=0, '
ALTER TABLE ventas
  ADD COLUMN ncf_modificado VARCHAR(19) NULL AFTER ncf,
  ADD COLUMN tipo_ingreso TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER ncf_modificado,
  ADD COLUMN fecha_retencion DATE NULL AFTER fecha,
  ADD COLUMN itbis_retenido_terceros DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER itbis,
  ADD COLUMN itbis_percibido DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER itbis_retenido_terceros,
  ADD COLUMN retencion_renta_terceros DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER itbis_percibido,
  ADD COLUMN isr_percibido DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER retencion_renta_terceros,
  ADD COLUMN impuesto_selectivo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER isr_percibido,
  ADD COLUMN otros_impuestos DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER impuesto_selectivo,
  ADD COLUMN propina_legal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER otros_impuestos,
  ADD KEY idx_ventas_ncf (ncf)
', 'SELECT ''ventas ya migrada''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 6) COMPROBANTES ANULADOS: Formato 608
--    Tipo de anulación (10 códigos oficiales):
--      1 Deterioro de factura preimpresa
--      2 Errores de impresión (factura preimpresa)
--      3 Impresión defectuosa
--      4 Corrección de la información
--      5 Cambio de productos
--      6 Devolución de productos
--      7 Omisión de productos
--      8 Errores en secuencia de NCF
--      9 Por cese de operaciones
--     10 Pérdida o hurto de talonarios
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comprobantes_anulados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ncf VARCHAR(19) NOT NULL,
  fecha_comprobante DATE NOT NULL,
  tipo_anulacion TINYINT UNSIGNED NOT NULL,
  venta_id INT UNSIGNED NULL,
  sucursal_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  notas VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_anulado_ncf (ncf),
  KEY idx_anulado_fecha (fecha_comprobante),
  KEY fk_anul_venta (venta_id),
  CONSTRAINT chk_tipo_anulacion CHECK (tipo_anulacion BETWEEN 1 AND 10),
  CONSTRAINT fk_anul_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7) BACKFILL de la data existente (solo filas sin valor).
-- ---------------------------------------------------------------------------

-- Tipo de identificación: en RD el RNC tiene 9 dígitos y la cédula 11.
UPDATE proveedores SET tipo_id = CASE
    WHEN CHAR_LENGTH(REGEXP_REPLACE(COALESCE(rnc,''), '[^0-9]', '')) = 11 THEN 2 ELSE 1 END;

UPDATE clientes SET tipo_id = CASE
    WHEN CHAR_LENGTH(REGEXP_REPLACE(COALESCE(rnc_cedula,''), '[^0-9]', '')) = 11 THEN 2 ELSE 1 END;

-- Mapeo de los métodos de pago que trae el sistema por defecto.
UPDATE metodos_pago SET dgii_tipo_pago = 1 WHERE nombre LIKE '%Efectivo%';
UPDATE metodos_pago SET dgii_tipo_pago = 3 WHERE nombre LIKE '%Tarjeta%';
UPDATE metodos_pago SET dgii_tipo_pago = 2 WHERE nombre LIKE '%Transferencia%' OR nombre LIKE '%Cheque%' OR nombre LIKE '%Dep_sito%';
UPDATE metodos_pago SET dgii_tipo_pago = 4 WHERE es_credito = 1;

-- Compras históricas: mercancía pagada en efectivo, que es lo que hace compras.php.
--   tipo_bien_servicio 9 = «Compras y gastos que formarán parte del costo de venta»
--   forma_pago 1 = Efectivo
UPDATE compras SET fecha_comprobante = fecha WHERE fecha_comprobante IS NULL;
UPDATE compras SET monto_bienes = subtotal WHERE monto_bienes = 0 AND subtotal > 0;
UPDATE compras SET tipo_bien_servicio = 9 WHERE tipo_bien_servicio IS NULL;
UPDATE compras SET forma_pago = 1 WHERE forma_pago IS NULL;

-- ---------------------------------------------------------------------------
-- 8) VERIFICACIÓN
-- ---------------------------------------------------------------------------
SELECT 'compras sin NCF (no reportables al 606)' AS chequeo, COUNT(*) AS filas FROM compras WHERE ncf IS NULL OR ncf = ''
UNION ALL SELECT 'compras sin tipo_bien_servicio', COUNT(*) FROM compras WHERE tipo_bien_servicio IS NULL
UNION ALL SELECT 'compras: bienes+servicios <> subtotal', COUNT(*) FROM compras WHERE ABS((monto_bienes+monto_servicios)-subtotal) > 0.02
UNION ALL SELECT 'ventas sin NCF (no reportables al 607)', COUNT(*) FROM ventas WHERE ncf IS NULL OR ncf = ''
UNION ALL SELECT 'metodos_pago sin mapeo DGII', COUNT(*) FROM metodos_pago WHERE dgii_tipo_pago = 7;

-- ---------------------------------------------------------------------------
-- REVERSIÓN (solo si hiciera falta deshacer):
--   DROP TABLE comprobantes_anulados;
--   ALTER TABLE compras DROP COLUMN ncf, DROP COLUMN ncf_modificado, DROP COLUMN tipo_bien_servicio,
--     DROP COLUMN fecha_comprobante, DROP COLUMN fecha_pago, DROP COLUMN monto_bienes,
--     DROP COLUMN monto_servicios, DROP COLUMN itbis_retenido, DROP COLUMN itbis_proporcionalidad,
--     DROP COLUMN itbis_costo, DROP COLUMN itbis_percibido, DROP COLUMN tipo_retencion_isr,
--     DROP COLUMN monto_retencion_renta, DROP COLUMN isr_percibido, DROP COLUMN impuesto_selectivo,
--     DROP COLUMN otros_impuestos, DROP COLUMN propina_legal, DROP COLUMN forma_pago;
--   ALTER TABLE ventas DROP COLUMN ncf_modificado, DROP COLUMN tipo_ingreso, DROP COLUMN fecha_retencion,
--     DROP COLUMN itbis_retenido_terceros, DROP COLUMN itbis_percibido, DROP COLUMN retencion_renta_terceros,
--     DROP COLUMN isr_percibido, DROP COLUMN impuesto_selectivo, DROP COLUMN otros_impuestos,
--     DROP COLUMN propina_legal;
--   ALTER TABLE proveedores DROP COLUMN tipo_id;
--   ALTER TABLE clientes DROP COLUMN tipo_id;
--   ALTER TABLE metodos_pago DROP COLUMN dgii_tipo_pago;
-- ---------------------------------------------------------------------------
