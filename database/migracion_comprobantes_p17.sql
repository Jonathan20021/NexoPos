-- ============================================================================
--  NexoPOS · P17 — Gubernamental y régimen especial en `ventas`
-- ----------------------------------------------------------------------------
--  `ventas.tipo_comprobante` era un ENUM de dos valores. Al añadir los tipos 44
--  (regímenes especiales) y 45 (gubernamental) al POS, la venta reventaba con
--  «Data truncated for column 'tipo_comprobante'» — un error que NO aparece en
--  ninguna prueba de generación de trama, solo al intentar guardar la venta.
--
--  Lo encontró una prueba real contra el proveedor. Vale la pena anotarlo: un
--  ENUM es una restricción del esquema que ninguna prueba de lógica alcanza.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

ALTER TABLE ventas
  MODIFY COLUMN tipo_comprobante
  ENUM('consumidor','credito_fiscal','gubernamental','regimen_especial')
  NOT NULL DEFAULT 'consumidor';

-- ============================================================================
--  Comprobación:
--    SHOW COLUMNS FROM ventas LIKE 'tipo_comprobante';
-- ============================================================================
