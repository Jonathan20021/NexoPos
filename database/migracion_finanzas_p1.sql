-- ============================================================================
--  Migración P1 — Finanzas
--
--  1) Comisiones con flujo de estados: pendiente -> aprobada -> pagada (o anulada).
--     Antes se calculaban al vuelo y se "pagaban" en un solo paso; ahora quedan
--     registradas, con aprobación previa al pago y trazabilidad de quién/ cuándo.
--  2) Cuentas financieras: nuevos tipos 'tarjeta' y 'transferencia' + saldo_inicial
--     (el saldo con que se abre la cuenta, que se conserva aparte del balance vivo).
--
--  Idempotente (comprobaciones con information_schema). Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Tabla comisiones
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comisiones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  sucursal_id    INT UNSIGNED NULL,
  periodo_desde  DATE NOT NULL,
  periodo_hasta  DATE NOT NULL,
  base           DECIMAL(14,2) NOT NULL DEFAULT 0,   -- subtotal - descuento (sin ITBIS)
  pct            DECIMAL(6,2)  NOT NULL DEFAULT 0,    -- % vigente al generar
  monto          DECIMAL(14,2) NOT NULL DEFAULT 0,    -- base * pct / 100
  ventas_cant    INT UNSIGNED  NOT NULL DEFAULT 0,
  estado         ENUM('pendiente','aprobada','pagada','anulada') NOT NULL DEFAULT 'pendiente',
  transaccion_id BIGINT UNSIGNED NULL,                -- gasto generado al pagar
  notas          VARCHAR(255) NULL,
  generada_por   INT UNSIGNED NULL,
  aprobada_por   INT UNSIGNED NULL,
  aprobada_at    DATETIME NULL,
  pagada_por     INT UNSIGNED NULL,
  pagada_at      DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_comision_periodo (usuario_id, periodo_desde, periodo_hasta),
  KEY idx_com_estado (estado),
  KEY idx_com_sucursal (sucursal_id),
  CONSTRAINT fk_com_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_com_transaccion FOREIGN KEY (transaccion_id) REFERENCES transacciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) Permisos del módulo comisiones
-- ---------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'comisiones.ver' c,'comisiones' m,'Finanzas' g,'Comisiones — Ver' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='comisiones.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'comisiones.generar' c,'comisiones' m,'Finanzas' g,'Comisiones — Generar/registrar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='comisiones.generar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'comisiones.aprobar' c,'comisiones' m,'Finanzas' g,'Comisiones — Aprobar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='comisiones.aprobar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'comisiones.pagar' c,'comisiones' m,'Finanzas' g,'Comisiones — Pagar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='comisiones.pagar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'comisiones.anular' c,'comisiones' m,'Finanzas' g,'Comisiones — Anular' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='comisiones.anular');

-- Se conceden a los roles que ya ven reportes financieros (Super/Admin/Gerente).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'reportes.ver'
  JOIN permisos p  ON p.clave IN ('comisiones.ver','comisiones.generar','comisiones.aprobar','comisiones.pagar','comisiones.anular')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

-- Aseguramiento explícito para roles super.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('comisiones.ver','comisiones.generar','comisiones.aprobar','comisiones.pagar','comisiones.anular')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

-- ---------------------------------------------------------------------------
-- 3) Cuentas financieras: tipos nuevos + saldo_inicial
-- ---------------------------------------------------------------------------
-- 3a) Ampliar el ENUM de tipo (solo si aún no incluye 'tarjeta').
SET @tieneTarjeta := (SELECT LOCATE('tarjeta', COLUMN_TYPE) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_financieras' AND COLUMN_NAME='tipo');
SET @s := IF(@tieneTarjeta=0,
  'ALTER TABLE cuentas_financieras MODIFY COLUMN tipo ENUM(''efectivo'',''banco'',''tarjeta'',''transferencia'',''otro'') NOT NULL DEFAULT ''efectivo''',
  'SELECT ''ENUM tipo ya ampliado''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3b) Columna saldo_inicial (se conserva el saldo de apertura; el balance evoluciona aparte).
SET @tieneSI := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_financieras' AND COLUMN_NAME='saldo_inicial');
SET @s := IF(@tieneSI=0,
  'ALTER TABLE cuentas_financieras ADD COLUMN saldo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER tipo',
  'SELECT ''saldo_inicial ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Verificación
SELECT 'tabla comisiones' item, COUNT(*) ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='comisiones'
UNION ALL SELECT 'permisos comisiones', COUNT(*) FROM permisos WHERE clave LIKE 'comisiones.%'
UNION ALL SELECT 'cuentas.saldo_inicial', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_financieras' AND COLUMN_NAME='saldo_inicial'
UNION ALL SELECT 'tipo con tarjeta', LOCATE('tarjeta',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_financieras' AND COLUMN_NAME='tipo'))>0;

-- REVERSIÓN:
--   DROP TABLE IF EXISTS comisiones;
--   DELETE FROM permisos WHERE clave LIKE 'comisiones.%';
--   ALTER TABLE cuentas_financieras DROP COLUMN saldo_inicial,
--     MODIFY COLUMN tipo ENUM('efectivo','banco','otro') NOT NULL DEFAULT 'efectivo';
