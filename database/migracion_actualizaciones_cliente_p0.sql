-- ============================================================================
--  Migración P0 — Actualizaciones solicitadas por la cliente (Kyros)
--
--  Cubre: muestras a RD$0.00, turno de caja, trazabilidad de cliente,
--  permiso ventas.muestra y base de metas KPI (tabla, sin UI todavía).
--
--  Idempotente. Compatible con MySQL 8 y MariaDB. Reversión al final.
--  No borra datos, no elimina columnas, no toca lógica existente.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) VENTA_DETALLES: soporte de muestras a nivel de línea.
--
--    es_muestra:      1 = línea entregada como muestra (precio 0, sin ingreso).
--    precio_original: precio real del producto al momento de la muestra, para
--                     trazabilidad (cuánto se "regaló"). NO afecta el total.
--    Centinela: venta_detalles.es_muestra
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles' AND COLUMN_NAME='es_muestra');
SET @s := IF(@c=0,
  'ALTER TABLE venta_detalles
     ADD COLUMN es_muestra TINYINT(1) NOT NULL DEFAULT 0 AFTER subtotal,
     ADD COLUMN precio_original DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER es_muestra',
  'SELECT ''venta_detalles ya tiene columnas de muestra''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2) CAJA_SESIONES: turno opcional (mañana/tarde/noche o texto libre).
--    No cambia la fórmula de cierre; es solo clasificación y filtro.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='caja_sesiones' AND COLUMN_NAME='turno');
SET @s := IF(@c=0,
  'ALTER TABLE caja_sesiones ADD COLUMN turno VARCHAR(50) NULL AFTER usuario_id',
  'SELECT ''caja_sesiones.turno ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3) CLIENTES: trazabilidad del usuario que creó el registro.
--    NULL admitido: los clientes viejos no tienen creador conocido.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clientes' AND COLUMN_NAME='created_by');
SET @s := IF(@c=0,
  'ALTER TABLE clientes ADD COLUMN created_by INT UNSIGNED NULL AFTER activo',
  'SELECT ''clientes.created_by ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 4) PERMISO ventas.muestra.
--
--    Controla quién puede facturar una línea como muestra a RD$0.00.
--    Se concede por defecto a Administrador y Gerente de Sucursal. El Super
--    Administrador lo tiene automáticamente (is_super). Para que una cajera
--    pueda dar muestras, concédelo a su rol en Roles y Permisos.
-- ---------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'ventas.muestra' AS c, 'ventas' AS m, 'Ventas' AS g, 'Ventas — Facturar muestras (RD$0.00)' AS d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'ventas.muestra');

-- Concesión a los roles administrativos/supervisores existentes, sin duplicar.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'ventas.muestra'
 WHERE (r.es_super = 1 OR r.nombre IN ('Administrador', 'Gerente de Sucursal'))
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

-- ---------------------------------------------------------------------------
-- 5) METAS_VENTAS (P0.6): base de KPI. Se crea la tabla; la UI es fase posterior.
--
--    Una meta puede ser por sucursal (usuario_id NULL), por vendedor
--    (sucursal_id + usuario_id) o global (ambos NULL). El progreso se derivará
--    de `ventas` en la implementación futura; aquí no se conecta a nada.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metas_ventas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  periodo_inicio DATE NOT NULL,
  periodo_fin DATE NOT NULL,
  moneda VARCHAR(10) NOT NULL DEFAULT 'RD$',
  monto_objetivo DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado ENUM('activa','cerrada','cancelada') NOT NULL DEFAULT 'activa',
  notas VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_meta_sucursal (sucursal_id),
  KEY idx_meta_usuario (usuario_id),
  KEY idx_meta_periodo (periodo_inicio, periodo_fin),
  CONSTRAINT chk_meta_periodo CHECK (periodo_fin >= periodo_inicio),
  CONSTRAINT chk_meta_monto CHECK (monto_objetivo >= 0),
  CONSTRAINT fk_meta_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
  CONSTRAINT fk_meta_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6) VERIFICACIÓN
-- ---------------------------------------------------------------------------
SELECT 'venta_detalles.es_muestra' AS item, COUNT(*) AS ok
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles' AND COLUMN_NAME='es_muestra'
UNION ALL SELECT 'caja_sesiones.turno', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='caja_sesiones' AND COLUMN_NAME='turno'
UNION ALL SELECT 'clientes.created_by', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clientes' AND COLUMN_NAME='created_by'
UNION ALL SELECT 'permiso ventas.muestra', COUNT(*) FROM permisos WHERE clave='ventas.muestra'
UNION ALL SELECT 'roles con ventas.muestra', COUNT(*) FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.clave='ventas.muestra'
UNION ALL SELECT 'tabla metas_ventas', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='metas_ventas';

-- ---------------------------------------------------------------------------
-- REVERSIÓN (solo si hiciera falta deshacer):
--   ALTER TABLE venta_detalles DROP COLUMN es_muestra, DROP COLUMN precio_original;
--   ALTER TABLE caja_sesiones DROP COLUMN turno;
--   ALTER TABLE clientes DROP COLUMN created_by;
--   DELETE FROM permisos WHERE clave='ventas.muestra';  -- rol_permisos cae por FK
--   DROP TABLE metas_ventas;
-- ---------------------------------------------------------------------------
