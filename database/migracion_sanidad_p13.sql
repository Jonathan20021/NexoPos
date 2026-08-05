-- ============================================================================
--  NexoPOS · P13 — Cumplimiento sanitario y trazabilidad por lote
-- ----------------------------------------------------------------------------
--  Importers TyE recibe inspecciones de Salud Pública (MSP / DIGEMAPS),
--  PROCONSUMIDOR, Ministerio de Agricultura e INDOCAL. A diferencia de la DGII,
--  ninguna de esas entidades define un formato de archivo oficial: la inspección
--  es DOCUMENTAL. El inspector pide ver, en el momento, tres cosas:
--
--    1. Que cada producto regulado tenga su registro sanitario VIGENTE.
--    2. Que no haya mercancía VENCIDA a la venta.
--    3. Que ante un problema se pueda decir a quién se le vendió el lote
--       afectado (trazabilidad para un retiro del mercado).
--
--  Este modelo sostiene las tres.
--
--  DECISIÓN CLAVE: el control por lote se activa producto a producto
--  (`productos.controla_lote`). Un tornillo no lleva lote; una crema sí. Así el
--  peso operativo cae solo donde la ley lo exige y el resto del catálogo sigue
--  funcionando exactamente igual que antes.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

-- ---------------------------------------------------------------------------
--  Helper: añadir una columna solo si no existe (MySQL 8 no soporta
--  `ADD COLUMN IF NOT EXISTS`, que MariaDB sí).
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS nexo_add_col;
DELIMITER //
CREATE PROCEDURE nexo_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- ---------------------------------------------------------------------------
-- 1. Productos: ficha sanitaria
-- ---------------------------------------------------------------------------
CALL nexo_add_col('productos', 'regulado',            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sujeto a control sanitario'");
CALL nexo_add_col('productos', 'controla_lote',       "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exige lote y vencimiento al entrar'");
CALL nexo_add_col('productos', 'registro_sanitario',  "VARCHAR(60) NULL");
CALL nexo_add_col('productos', 'registro_entidad',    "VARCHAR(40) NULL COMMENT 'DIGEMAPS, Agricultura, INDOCAL…'");
CALL nexo_add_col('productos', 'registro_categoria',  "VARCHAR(40) NULL COMMENT 'cosmetico, higiene, suplemento, natural, limpieza, quimico'");
CALL nexo_add_col('productos', 'registro_emision',    "DATE NULL");
CALL nexo_add_col('productos', 'registro_vencimiento',"DATE NULL");
CALL nexo_add_col('productos', 'registro_titular',    "VARCHAR(180) NULL COMMENT 'A nombre de quién está el registro'");
CALL nexo_add_col('productos', 'fabricante',          "VARCHAR(180) NULL");
CALL nexo_add_col('productos', 'pais_origen',         "VARCHAR(60) NULL");
CALL nexo_add_col('productos', 'vida_util_dias',      "INT UNSIGNED NULL COMMENT 'Para sugerir el vencimiento al capturar un lote'");

-- Índices de apoyo a los reportes de vigencia.
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos' AND INDEX_NAME='idx_p_regulado');
SET @s := IF(@x=0,'ALTER TABLE productos ADD KEY idx_p_regulado (regulado, registro_vencimiento)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2. Proveedores: licencia sanitaria
--    El inspector pregunta a quién se le compra y si ese proveedor está
--    habilitado. Sin esto, la respuesta es «déjame buscar el papel».
-- ---------------------------------------------------------------------------
CALL nexo_add_col('proveedores', 'licencia_sanitaria',     "VARCHAR(60) NULL");
CALL nexo_add_col('proveedores', 'licencia_vencimiento',   "DATE NULL");
CALL nexo_add_col('proveedores', 'pais_origen',            "VARCHAR(60) NULL");
CALL nexo_add_col('proveedores', 'notas_sanitarias',       "VARCHAR(255) NULL");

-- ---------------------------------------------------------------------------
-- 3. Lotes. La existencia de un producto con control de lote vive AQUÍ además
--    de en `inventario_stock`: esa tabla sigue siendo la verdad para vender y
--    para los reportes de siempre, y los lotes la desglosan.
--
--    `bloqueado` permite retirar un lote de circulación sin borrar nada — es lo
--    que se hace cuando el fabricante emite una alerta y todavía no se sabe si
--    hay que destruirlo.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lotes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(60) NOT NULL COMMENT 'Número de lote del fabricante',
  fecha_vencimiento DATE NULL,
  fecha_fabricacion DATE NULL,
  cantidad DECIMAL(12,3) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  proveedor_id INT UNSIGNED NULL,
  compra_id INT UNSIGNED NULL,
  bloqueado TINYINT(1) NOT NULL DEFAULT 0,
  motivo_bloqueo VARCHAR(255) NULL,
  registro_sanitario VARCHAR(60) NULL COMMENT 'Copia del registro vigente al entrar el lote',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Un mismo número de lote es un único registro por producto y sucursal: si no,
  -- dos entradas del mismo lote crearían dos saldos y la trazabilidad se parte.
  UNIQUE KEY uq_lote (producto_id, sucursal_id, codigo),
  KEY idx_lote_venc (fecha_vencimiento),
  KEY idx_lote_prod_venc (producto_id, sucursal_id, fecha_vencimiento),
  KEY idx_lote_codigo (codigo),
  CONSTRAINT fk_lote_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT fk_lote_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
  CONSTRAINT fk_lote_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Movimientos de lote — el libro de trazabilidad.
--
--    Una línea de venta puede consumir DOS lotes (se acaba uno y sigue con el
--    siguiente), así que la relación no cabe como una columna en venta_detalles:
--    necesita su propia tabla, con una fila por lote tocado.
--
--    De aquí sale la respuesta a la pregunta del inspector: «este lote, ¿a quién
--    se le vendió?».
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lote_movimientos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lote_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(30) NOT NULL COMMENT 'entrada, venta, devolucion, ajuste, transferencia_salida, transferencia_entrada, baja',
  cantidad DECIMAL(12,3) NOT NULL COMMENT '+ entra, − sale',
  saldo_anterior DECIMAL(12,3) NOT NULL DEFAULT 0,
  saldo_nuevo DECIMAL(12,3) NOT NULL DEFAULT 0,
  referencia_tipo VARCHAR(30) NULL,
  referencia_id INT UNSIGNED NULL,
  motivo VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_lm_lote (lote_id, id),
  KEY idx_lm_ref (referencia_tipo, referencia_id),
  KEY idx_lm_producto (producto_id, created_at),
  CONSTRAINT fk_lm_lote FOREIGN KEY (lote_id) REFERENCES lotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Permisos del módulo
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('sanidad.ver',      'sanidad',  'Cumplimiento', 'Cumplimiento sanitario — Ver registros y lotes'),
 ('sanidad.editar',   'sanidad',  'Cumplimiento', 'Cumplimiento sanitario — Editar la ficha sanitaria del producto'),
 ('sanidad.lotes',    'sanidad',  'Cumplimiento', 'Cumplimiento sanitario — Registrar y corregir lotes'),
 ('sanidad.bloquear', 'sanidad',  'Cumplimiento', 'Cumplimiento sanitario — Bloquear o liberar un lote (retiro del mercado)'),
 ('sanidad.baja',     'sanidad',  'Cumplimiento', 'Cumplimiento sanitario — Dar de baja mercancía vencida'),
 ('reportes.sanidad', 'reportes', 'Reportes',     'Centro de Reportes — Reportes de cumplimiento sanitario');

-- Se conceden a quien ya administra el inventario; los roles `es_super` no los
-- necesitan porque can() les devuelve true sin consultar la tabla.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p ON p.id = rp.permiso_id
  JOIN permisos p2 ON p2.clave IN ('sanidad.ver','sanidad.editar','sanidad.lotes','reportes.sanidad')
 WHERE p.clave = 'productos.editar';

-- Bloquear un lote y dar de baja mercancía se otorgan a mano desde Roles: son
-- las dos acciones que sacan producto de circulación y valen dinero.

DROP PROCEDURE IF EXISTS nexo_add_col;
