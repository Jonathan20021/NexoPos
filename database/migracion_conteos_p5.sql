-- ============================================================
--  Migración P5 — Conteo físico de inventario (toma de inventario)
--  Aplicar UNA vez sobre instalaciones existentes.
--  Idempotente: se puede correr de nuevo sin romper nada.
--  En instalaciones nuevas NO hace falta: ya viene en schema.sql.
-- ============================================================
--
--  Hasta ahora la única forma de corregir el inventario era ajustar producto
--  por producto desde la pantalla de Stock. Eso sirve para un error puntual,
--  pero no para la toma de inventario que toda tienda hace cada cierto tiempo:
--  contar el almacén completo, comparar contra el sistema y cuadrar.
--
--  Un conteo congela la existencia teórica al abrirlo, deja capturar lo contado
--  con calma (la tienda puede seguir vendiendo), muestra faltantes y sobrantes
--  valorizados, y al aplicarlo genera los movimientos de kardex correspondientes
--  para que el inventario quede cuadrado y con trazabilidad de quién lo hizo.
-- ============================================================

CREATE TABLE IF NOT EXISTS conteos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero         VARCHAR(30)  NOT NULL,
  sucursal_id    INT UNSIGNED NOT NULL,
  categoria_id   INT UNSIGNED NULL,              -- NULL = todo el catálogo
  descripcion    VARCHAR(150) NOT NULL,
  estado         ENUM('abierto','aplicado','cancelado') NOT NULL DEFAULT 'abierto',
  notas          VARCHAR(500) NULL,
  usuario_id     INT UNSIGNED NULL,              -- quién lo abrió
  aplicado_por   INT UNSIGNED NULL,
  aplicado_at    DATETIME NULL,
  cancelado_por  INT UNSIGNED NULL,
  cancelado_at   DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conteo_numero (numero),
  KEY idx_conteo_sucursal (sucursal_id, estado),
  CONSTRAINT fk_conteo_sucursal  FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id),
  CONSTRAINT fk_conteo_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conteo_detalles (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conteo_id      INT UNSIGNED NOT NULL,
  producto_id    INT UNSIGNED NOT NULL,
  -- Existencia según el sistema en el momento de abrir el conteo. Se congela
  -- para que la diferencia sea contra una foto fija y no contra un número que
  -- se mueve mientras se cuenta.
  stock_teorico  DECIMAL(12,3) NOT NULL DEFAULT 0,
  stock_contado  DECIMAL(12,3) NULL,             -- NULL = todavía sin contar
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,  -- congelado, para valorizar
  contado_por    INT UNSIGNED NULL,
  contado_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conteo_producto (conteo_id, producto_id),
  KEY idx_cd_producto (producto_id),
  CONSTRAINT fk_cdet_conteo   FOREIGN KEY (conteo_id)   REFERENCES conteos(id) ON DELETE CASCADE,
  CONSTRAINT fk_cdet_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contador del correlativo CNT-000001 (ver docs/CONCURRENCIA.md).
INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'conteos.numero.CNT', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM conteos WHERE numero LIKE 'CNT-%';

-- Permisos del módulo (el catálogo vive en app/permissions.php).
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
  ('conteos.ver',      'conteos', 'Inventario', 'Conteo físico de inventario — Ver'),
  ('conteos.crear',    'conteos', 'Inventario', 'Conteo físico de inventario — Abrir conteo'),
  ('conteos.contar',   'conteos', 'Inventario', 'Conteo físico de inventario — Capturar cantidades'),
  ('conteos.aplicar',  'conteos', 'Inventario', 'Conteo físico de inventario — Aplicar ajustes al stock'),
  ('conteos.cancelar', 'conteos', 'Inventario', 'Conteo físico de inventario — Cancelar conteo');

-- Quien ya podía ajustar inventario puede llevar un conteo; aplicar los ajustes
-- se concede aparte desde Roles, porque es la firma que mueve el inventario.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p1 ON p1.id = rp.permiso_id AND p1.clave = 'inventario.ajustar'
  JOIN permisos p2 ON p2.clave IN ('conteos.ver','conteos.crear','conteos.contar');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.modulo = 'conteos' WHERE r.es_super = 1;

-- ---------- Verificación ----------
SELECT 'Tabla conteos'          AS componente, IF(COUNT(*)=1,'OK','FALTA') AS estado FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteos'
UNION ALL SELECT 'Tabla conteo_detalles', IF(COUNT(*)=1,'OK','FALTA') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_detalles'
UNION ALL SELECT 'Permisos de conteos (5)', IF(COUNT(*)=5,'OK',CONCAT('hay ',COUNT(*))) FROM permisos WHERE modulo='conteos'
UNION ALL SELECT 'Contador CNT', IF(COUNT(*)=1,'OK','FALTA') FROM contadores WHERE nombre='conteos.numero.CNT';
