-- ============================================================================
--  Migración P3 — Modo offline Fase 2 (NCF fiscal definitivo offline)
--
--  Cada TERMINAL (dispositivo del POS) reserva rangos de NCF tallados del maestro
--  ncf_secuencias. Estando offline, el navegador toma un NCF de su reserva y lo
--  imprime como comprobante fiscal definitivo; al sincronizar, el servidor valida
--  que ese NCF pertenece a una reserva del terminal y registra la venta con él.
--
--  Garantías fiscales:
--   - Cada rango se talla del maestro bajo bloqueo (FOR UPDATE): dos terminales
--     nunca reciben el mismo número. El maestro salta el rango: online y offline
--     jamás se solapan.
--   - ventas.ncf pasa a UNIQUE: red de seguridad absoluta contra duplicados.
--
--  Idempotente en MySQL 8 y MariaDB. Reversión al final.
-- ============================================================================

-- 1) Terminales del POS (identidad por token de dispositivo del navegador).
CREATE TABLE IF NOT EXISTS pos_terminales (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_token CHAR(36) NOT NULL,                 -- generado y guardado en el navegador
  nombre       VARCHAR(80) NULL,
  sucursal_id  INT UNSIGNED NULL,
  ultimo_visto DATETIME NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_terminal_token (device_token),
  KEY idx_terminal_sucursal (sucursal_id),
  CONSTRAINT fk_terminal_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Rangos de NCF reservados (delegados) a un terminal para uso offline.
CREATE TABLE IF NOT EXISTS ncf_reservas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  terminal_id     INT UNSIGNED NOT NULL,
  secuencia_id    INT UNSIGNED NOT NULL,          -- de qué secuencia maestra se talló
  tipo            VARCHAR(10) NOT NULL,            -- B01, B02
  prefijo         VARCHAR(5)  NOT NULL DEFAULT 'B',
  secuencia_desde INT UNSIGNED NOT NULL,
  secuencia_hasta INT UNSIGNED NOT NULL,          -- rango inclusivo [desde, hasta]
  vencimiento     DATE NULL,
  estado          ENUM('activa','devuelta','vencida') NOT NULL DEFAULT 'activa',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reserva_terminal (terminal_id, tipo, estado),
  KEY idx_reserva_rango (tipo, secuencia_desde, secuencia_hasta),
  CONSTRAINT fk_reserva_terminal FOREIGN KEY (terminal_id) REFERENCES pos_terminales(id) ON DELETE CASCADE,
  CONSTRAINT fk_reserva_secuencia FOREIGN KEY (secuencia_id) REFERENCES ncf_secuencias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) ventas.ncf pasa de índice normal a UNIQUE (backstop anti-duplicado).
--    Solo se aplica si no hay NCF repetidos; si los hubiera, se informa y no rompe.
SET @dups := (SELECT COUNT(*) FROM (
  SELECT ncf FROM ventas WHERE ncf IS NOT NULL GROUP BY ncf HAVING COUNT(*) > 1
) d);
SET @ya := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND INDEX_NAME='uq_ventas_ncf');
SET @tiene_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND INDEX_NAME='idx_ventas_ncf');

-- Quitar el índice normal solo si vamos a poner el UNIQUE (sin duplicados y aún no existe).
SET @s := IF(@ya=0 AND @dups=0 AND @tiene_idx>0,
  'ALTER TABLE ventas DROP INDEX idx_ventas_ncf',
  'SELECT ''idx_ventas_ncf: sin cambios''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@ya=0 AND @dups=0,
  'ALTER TABLE ventas ADD UNIQUE KEY uq_ventas_ncf (ncf)',
  IF(@dups>0, 'SELECT ''ATENCION: hay NCF duplicados; no se creo uq_ventas_ncf''',
              'SELECT ''uq_ventas_ncf ya existe'''));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4) Permiso para gestionar terminales (se siembra en app/permissions.php; aquí
--    no hay tabla de catálogo que tocar).

-- Verificación.
SELECT 'pos_terminales' AS item, COUNT(*) AS ok FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_terminales'
UNION ALL SELECT 'ncf_reservas', COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncf_reservas'
UNION ALL SELECT 'uq_ventas_ncf', COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND INDEX_NAME='uq_ventas_ncf'
UNION ALL SELECT 'ncf duplicados (debe ser 0)', @dups;

-- REVERSIÓN:
--   DROP TABLE IF EXISTS ncf_reservas;
--   DROP TABLE IF EXISTS pos_terminales;
--   ALTER TABLE ventas DROP INDEX uq_ventas_ncf, ADD KEY idx_ventas_ncf (ncf);
