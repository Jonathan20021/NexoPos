-- ============================================================
--  Migración P4 — Concurrencia multi-sucursal
--  Aplicar UNA vez sobre instalaciones existentes (producción).
--  Idempotente: se puede correr de nuevo sin romper nada.
--  En instalaciones nuevas NO hace falta: ya viene en schema.sql.
-- ============================================================
--
--  Problema que resuelve:
--  Los correlativos (VTA-000053, COM-000012...) se calculaban con
--  «SELECT MAX(...) + 1» justo antes de insertar. Con dos cajas vendiendo a la
--  vez, ambas leían el mismo máximo, generaban el mismo número y una de las dos
--  ventas moría contra el índice UNIQUE: el cajero veía un error y perdía la
--  venta. Con muchas sucursales en simultáneo eso pasa a diario.
--
--  Ahora cada serie tiene su contador y el número se reserva con un UPDATE
--  atómico, que es indivisible aunque entren mil peticiones en el mismo instante.
-- ============================================================

CREATE TABLE IF NOT EXISTS contadores (
  nombre     VARCHAR(60) NOT NULL,          -- ej. ventas.numero.VTA
  valor      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Siembra cada contador con el máximo que ya exista, para no repetir números
-- ni dejar huecos al pasar del método viejo al nuevo.
INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'ventas.numero.VTA', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM ventas WHERE numero LIKE 'VTA-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'compras.numero.COM', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM compras WHERE numero LIKE 'COM-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'devoluciones.numero.DEV', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM devoluciones WHERE numero LIKE 'DEV-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'transferencias.numero.TRF', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM transferencias WHERE numero LIKE 'TRF-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'pedidos.numero.PED', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM pedidos WHERE numero LIKE 'PED-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'clientes.codigo.CLI', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM clientes WHERE codigo LIKE 'CLI-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'productos.codigo.SKU', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM productos WHERE codigo LIKE 'SKU-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'proveedores.codigo.PRV', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM proveedores WHERE codigo LIKE 'PRV-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'empleados.codigo.EMP', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM empleados WHERE codigo LIKE 'EMP-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'crm_oportunidades.codigo.OPT', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM crm_oportunidades WHERE codigo LIKE 'OPT-%';

-- ============================================================
--  Índices que faltaban para el trabajo concurrente
-- ============================================================
--  Se crean de forma CONDICIONAL: MySQL no tiene CREATE INDEX IF NOT EXISTS y
--  repetir un CREATE INDEX aborta el script completo con el error 1061. Sin
--  esto, correr la migración sobre una instalación nueva (donde schema.sql ya
--  los trae) reventaría el despliegue a medias.

-- «¿Esta caja ya está abierta?» recorría toda caja_sesiones en cada apertura.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_sesiones'
                AND INDEX_NAME = 'idx_cs_caja_estado') > 0,
             'DO 0', 'CREATE INDEX idx_cs_caja_estado ON caja_sesiones (caja_id, estado)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- La búsqueda global y el POS listan productos activos por nombre sin parar.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND INDEX_NAME = 'idx_p_activo_nombre') > 0,
             'DO 0', 'CREATE INDEX idx_p_activo_nombre ON productos (activo, nombre)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- La antigüedad de cuentas por cobrar recorre las ventas a crédito por cliente.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
                AND INDEX_NAME = 'idx_v_cliente_estado') > 0,
             'DO 0', 'CREATE INDEX idx_v_cliente_estado ON ventas (cliente_id, estado, fecha)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
