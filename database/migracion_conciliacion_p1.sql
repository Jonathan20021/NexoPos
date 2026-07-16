-- ============================================================================
--  Migración P1 — Conciliación bancaria
--
--  Cruza los movimientos del sistema (transacciones) contra el estado de cuenta
--  del banco: se marca lo que ya apareció en el banco y lo que queda "en tránsito"
--  explica la diferencia entre el saldo del banco y el saldo en libros.
--
--  Solo aplica a cuentas de banco/tarjeta/transferencia. El efectivo NO se
--  concilia aquí: su arqueo es el cierre de caja, que ya existe.
--
--  Idempotente (comprobaciones con information_schema). Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Tabla conciliaciones (una por cuenta y fecha de corte)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conciliaciones (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cuenta_id         INT UNSIGNED NOT NULL,
  fecha_corte       DATE NOT NULL,
  saldo_banco       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- el que dice el estado de cuenta
  saldo_libros      DECIMAL(14,2) NOT NULL DEFAULT 0,   -- saldo_inicial + movimientos hasta el corte
  transito_ingresos DECIMAL(14,2) NOT NULL DEFAULT 0,   -- depósitos que el banco aún no refleja
  transito_gastos   DECIMAL(14,2) NOT NULL DEFAULT 0,   -- pagos que el banco aún no refleja
  diferencia        DECIMAL(14,2) NOT NULL DEFAULT 0,   -- debe cerrar en 0
  estado            ENUM('cerrada') NOT NULL DEFAULT 'cerrada',
  notas             VARCHAR(255) NULL,
  usuario_id        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conciliacion_corte (cuenta_id, fecha_corte),
  KEY idx_conc_cuenta (cuenta_id),
  CONSTRAINT fk_conc_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas_financieras(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) transacciones: marca de conciliada + a qué conciliación quedó amarrada
--    conciliacion_id NULL = marcada pero aún no cerrada (se puede desmarcar).
--    Con conciliacion_id = quedó dentro de un corte cerrado: ya no se toca.
-- ---------------------------------------------------------------------------
SET @tieneConc := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transacciones' AND COLUMN_NAME='conciliada');
SET @s := IF(@tieneConc=0,
  'ALTER TABLE transacciones ADD COLUMN conciliada TINYINT(1) NOT NULL DEFAULT 0 AFTER fecha',
  'SELECT ''transacciones.conciliada ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @tieneConcId := (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transacciones' AND COLUMN_NAME='conciliacion_id');
SET @s := IF(@tieneConcId=0,
  'ALTER TABLE transacciones ADD COLUMN conciliacion_id INT UNSIGNED NULL AFTER conciliada',
  'SELECT ''transacciones.conciliacion_id ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Índice para listar rápido lo pendiente de conciliar por cuenta y fecha.
SET @tieneIdx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transacciones' AND INDEX_NAME='idx_tr_conciliacion');
SET @s := IF(@tieneIdx=0,
  'ALTER TABLE transacciones ADD INDEX idx_tr_conciliacion (cuenta_id, fecha, conciliada)',
  'SELECT ''idx_tr_conciliacion ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- FK a conciliaciones (se añade aparte porque la tabla puede crearse en esta misma corrida).
SET @tieneFk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transacciones' AND CONSTRAINT_NAME='fk_tr_conciliacion');
SET @s := IF(@tieneFk=0,
  'ALTER TABLE transacciones ADD CONSTRAINT fk_tr_conciliacion FOREIGN KEY (conciliacion_id) REFERENCES conciliaciones(id) ON DELETE SET NULL',
  'SELECT ''fk_tr_conciliacion ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3) Backfill de saldo_inicial
--
--     Las cuentas creadas ANTES de que existiera `saldo_inicial` (migracion_finanzas_p1)
--     guardaron su saldo de apertura dentro de `balance`, y la columna nueva quedó en 0.
--     La conciliación calcula el saldo en libros como apertura + movimientos, así que
--     esas cuentas darían un saldo falso.
--
--     La apertura se despeja por definición:  apertura = balance − movimientos.
--     Es idempotente: para una cuenta ya correcta el despeje da 0 y no cambia nada,
--     y al repetir la migración el WHERE saldo_inicial = 0 ya no la alcanza.
-- ---------------------------------------------------------------------------
UPDATE cuentas_financieras c
   SET c.saldo_inicial = c.balance - COALESCE((
         SELECT SUM(CASE WHEN t.tipo = 'ingreso' THEN t.monto ELSE -t.monto END)
           FROM transacciones t WHERE t.cuenta_id = c.id), 0)
 WHERE c.saldo_inicial = 0;

-- ---------------------------------------------------------------------------
-- 4) Permisos del módulo conciliación
-- ---------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'conciliacion.ver' c,'conciliacion' m,'Finanzas' g,'Conciliación bancaria — Ver' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='conciliacion.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'conciliacion.conciliar' c,'conciliacion' m,'Finanzas' g,'Conciliación bancaria — Marcar movimientos' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='conciliacion.conciliar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'conciliacion.cerrar' c,'conciliacion' m,'Finanzas' g,'Conciliación bancaria — Cerrar corte' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='conciliacion.cerrar');

-- Se conceden a los roles que ya administran finanzas (misma lógica que comisiones).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'finanzas.ver'
  JOIN permisos p  ON p.clave IN ('conciliacion.ver','conciliacion.conciliar','conciliacion.cerrar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

-- Aseguramiento explícito para roles super.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('conciliacion.ver','conciliacion.conciliar','conciliacion.cerrar')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

-- Verificación
SELECT 'tabla conciliaciones' item, COUNT(*) ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conciliaciones'
UNION ALL SELECT 'transacciones.conciliada', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transacciones' AND COLUMN_NAME='conciliada'
UNION ALL SELECT 'transacciones.conciliacion_id', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transacciones' AND COLUMN_NAME='conciliacion_id'
UNION ALL SELECT 'permisos conciliacion', COUNT(*) FROM permisos WHERE clave LIKE 'conciliacion.%';

-- REVERSIÓN:
--   ALTER TABLE transacciones DROP FOREIGN KEY fk_tr_conciliacion;
--   ALTER TABLE transacciones DROP INDEX idx_tr_conciliacion,
--     DROP COLUMN conciliacion_id, DROP COLUMN conciliada;
--   DROP TABLE IF EXISTS conciliaciones;
--   DELETE FROM permisos WHERE clave LIKE 'conciliacion.%';
