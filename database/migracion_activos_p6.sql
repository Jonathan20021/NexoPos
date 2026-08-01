-- ============================================================
--  Migración P6 — Activos fijos y depreciación
--  Aplicar UNA vez sobre instalaciones existentes.
--  Idempotente: se puede correr de nuevo sin romper nada.
--  En instalaciones nuevas NO hace falta: ya viene en schema.sql.
-- ============================================================
--
--  El balance general decía, con razón, que no incluía activos fijos: el
--  sistema no los conocía. Faltaba el mostrador, la nevera, la camioneta y las
--  computadoras — que son patrimonio de la empresa y se desgastan cada mes.
--
--  Sin esto, el balance subestima el activo y el estado de resultados no
--  registra la depreciación, que es un gasto real aunque no salga dinero de
--  la caja.
-- ============================================================

CREATE TABLE IF NOT EXISTS activos_fijos (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo                 VARCHAR(20)  NOT NULL,
  nombre                 VARCHAR(150) NOT NULL,
  descripcion            VARCHAR(500) NULL,
  -- Categoría fiscal del Código Tributario dominicano (art. 287):
  -- 1 = edificaciones (5% anual) · 2 = vehículos, equipos y computadoras (25%)
  -- 3 = cualquier otro bien (15%). Se guarda para que la contabilidad pueda
  -- hacer el cálculo fiscal, que difiere del contable.
  categoria_dgii         TINYINT UNSIGNED NOT NULL DEFAULT 3,
  tipo                   VARCHAR(40)  NOT NULL DEFAULT 'otros',
  sucursal_id            INT UNSIGNED NULL,
  proveedor_id           INT UNSIGNED NULL,
  factura                VARCHAR(40)  NULL,
  fecha_adquisicion      DATE NOT NULL,
  costo                  DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_residual         DECIMAL(14,2) NOT NULL DEFAULT 0,
  vida_util_meses        SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  depreciacion_acumulada DECIMAL(14,2) NOT NULL DEFAULT 0,
  -- 'activo' se deprecia cada mes; 'depreciado' ya llegó a su valor residual;
  -- 'baja' se retiró; 'vendido' se dio de baja con un ingreso por la venta.
  estado                 ENUM('activo','depreciado','baja','vendido') NOT NULL DEFAULT 'activo',
  fecha_baja             DATE NULL,
  motivo_baja            VARCHAR(255) NULL,
  valor_venta            DECIMAL(14,2) NULL,
  notas                  VARCHAR(500) NULL,
  usuario_id             INT UNSIGNED NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_activo_codigo (codigo),
  KEY idx_activo_estado (estado),
  KEY idx_activo_sucursal (sucursal_id),
  CONSTRAINT chk_activo_valores CHECK (costo >= 0 AND valor_residual >= 0 AND vida_util_meses > 0),
  CONSTRAINT fk_activo_sucursal  FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id)  ON DELETE SET NULL,
  CONSTRAINT fk_activo_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un asiento por activo y periodo. La clave única impide depreciar dos veces
-- el mismo mes, que es el error clásico al correr la depreciación dos veces.
CREATE TABLE IF NOT EXISTS depreciaciones (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activo_id         INT UNSIGNED NOT NULL,
  periodo           CHAR(7) NOT NULL,               -- 'YYYY-MM'
  monto             DECIMAL(14,2) NOT NULL DEFAULT 0,
  acumulado_antes   DECIMAL(14,2) NOT NULL DEFAULT 0,
  acumulado_despues DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_neto        DECIMAL(14,2) NOT NULL DEFAULT 0,
  transaccion_id    BIGINT UNSIGNED NULL,
  usuario_id        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dep_activo_periodo (activo_id, periodo),
  KEY idx_dep_periodo (periodo),
  CONSTRAINT fk_dep_activo      FOREIGN KEY (activo_id)      REFERENCES activos_fijos(id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_transaccion FOREIGN KEY (transaccion_id) REFERENCES transacciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contador del correlativo AF-000001.
INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'activos_fijos.codigo.AF', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM activos_fijos WHERE codigo LIKE 'AF-%';

-- Permisos (el catálogo vive en app/permissions.php).
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
  ('activos.ver',      'activos', 'Finanzas', 'Activos fijos — Ver'),
  ('activos.crear',    'activos', 'Finanzas', 'Activos fijos — Registrar'),
  ('activos.editar',   'activos', 'Finanzas', 'Activos fijos — Editar'),
  ('activos.depreciar','activos', 'Finanzas', 'Activos fijos — Correr la depreciación mensual'),
  ('activos.baja',     'activos', 'Finanzas', 'Activos fijos — Dar de baja o vender');

-- Quien ya administraba finanzas puede ver y registrar activos. Depreciar y dar
-- de baja se conceden aparte desde Roles: ambas mueven el resultado del periodo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p1 ON p1.id = rp.permiso_id AND p1.clave = 'finanzas.crear'
  JOIN permisos p2 ON p2.clave IN ('activos.ver','activos.crear','activos.editar');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.modulo = 'activos' WHERE r.es_super = 1;

-- Categoría de gasto para la depreciación (se crea sola si no existe).
INSERT IGNORE INTO categorias_financieras (tipo, nombre) VALUES ('gasto', 'Depreciación');

-- ---------- Verificación ----------
SELECT 'Tabla activos_fijos'   AS componente, IF(COUNT(*)=1,'OK','FALTA') AS estado FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='activos_fijos'
UNION ALL SELECT 'Tabla depreciaciones', IF(COUNT(*)=1,'OK','FALTA') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='depreciaciones'
UNION ALL SELECT 'Permisos de activos (5)', IF(COUNT(*)=5,'OK',CONCAT('hay ',COUNT(*))) FROM permisos WHERE modulo='activos'
UNION ALL SELECT 'Contador AF', IF(COUNT(*)=1,'OK','FALTA') FROM contadores WHERE nombre='activos_fijos.codigo.AF'
UNION ALL SELECT 'Categoría de gasto Depreciación', IF(COUNT(*)>=1,'OK','FALTA') FROM categorias_financieras WHERE tipo='gasto' AND nombre='Depreciación';
