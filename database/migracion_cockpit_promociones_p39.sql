-- ---------------------------------------------------------------------------
--  NexoPOS · P39 — Promotion Cockpit y seguimiento de KPIs de campañas
-- ---------------------------------------------------------------------------
--  La marca (L'Occitane) pide medir sus promociones como lo hace la casa
--  matriz: venta bruta, descuento, venta neta y margen POR TIPO de descuento,
--  este año contra el anterior, y el tablero de KPIs de cada campaña
--  (Holiday, Black Friday…) con su inversión de marketing.
--
--  Hasta hoy eso era imposible por una razón concreta: la promoción bajaba el
--  precio de la línea y NO dejaba rastro. `venta_detalles.descuento` quedaba
--  en 0 y el precio de lista se perdía, así que nadie podía saber cuánto costó
--  una promoción ni cuál se aplicó. Esta migración empieza a guardarlo.
--
--    promociones.codigo / tipo_descuento
--      El código interno de la promo y su familia (sets de regalo, GWP,
--      operación especial, outlet…). Es la fila del cockpit.
--
--    venta_detalles.precio_lista / promocion_id
--      El precio de catálogo en el momento de vender y la promo que ganó.
--      NULL en las ventas anteriores a esta migración: su descuento de promo
--      no se conoce y el cockpit lo dice en vez de inventarlo.
--
--    ventas.descuento_motivo
--      Por qué el cajero hizo un descuento manual (empleado, fidelidad,
--      cortesía…). Sin esto todos caen en «descuento manual en caja».
--
--    productos.segmento / linea / es_heroe
--      La clasificación de la marca: segmento (Body, Face, Hand…), línea
--      (Almond, Shea, Immortelle…) y productos héroe.
--
--    kpi_campanas (+ productos, inversiones, métricas, metas)
--      El archivo «Holiday KPIs to track»: fechas este año y el comparable del
--      año pasado, SKUs foco, metas por canal, inversión por rubro y los KPIs
--      que no salen del POS (alcance, impresiones, NPS, sesiones web…).
--
--  Idempotente. Vale en MariaDB 10.4 y en MySQL 8. Reversión al final.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

/* ========================================================================
 *  1) Rastro de la promoción en la venta
 * ===================================================================== */
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='promociones' AND COLUMN_NAME='codigo');
SET @s := IF(@c=0, 'ALTER TABLE promociones ADD COLUMN codigo VARCHAR(40) NULL AFTER nombre', 'SELECT ''promociones.codigo ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='promociones' AND COLUMN_NAME='tipo_descuento');
SET @s := IF(@c=0, 'ALTER TABLE promociones ADD COLUMN tipo_descuento VARCHAR(40) NULL AFTER codigo', 'SELECT ''promociones.tipo_descuento ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles' AND COLUMN_NAME='precio_lista');
SET @s := IF(@c=0, 'ALTER TABLE venta_detalles ADD COLUMN precio_lista DECIMAL(12,2) NULL AFTER precio_unitario', 'SELECT ''venta_detalles.precio_lista ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles' AND COLUMN_NAME='promocion_id');
SET @s := IF(@c=0, 'ALTER TABLE venta_detalles ADD COLUMN promocion_id INT UNSIGNED NULL AFTER precio_lista', 'SELECT ''venta_detalles.promocion_id ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='descuento_motivo');
SET @s := IF(@c=0, 'ALTER TABLE ventas ADD COLUMN descuento_motivo VARCHAR(40) NULL AFTER descuento', 'SELECT ''ventas.descuento_motivo ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Sin FK a propósito: borrar una promoción vieja no puede fallar por las ventas
-- que ya la usaron; el cockpit la muestra como «Promoción eliminada».
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles' AND INDEX_NAME='idx_vd_promocion');
SET @s := IF(@c=0, 'ALTER TABLE venta_detalles ADD KEY idx_vd_promocion (promocion_id)', 'SELECT ''idx_vd_promocion ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ========================================================================
 *  2) Clasificación de la marca en el producto
 * ===================================================================== */
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos' AND COLUMN_NAME='segmento');
SET @s := IF(@c=0, 'ALTER TABLE productos ADD COLUMN segmento VARCHAR(60) NULL AFTER marca_id', 'SELECT ''productos.segmento ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos' AND COLUMN_NAME='linea');
SET @s := IF(@c=0, 'ALTER TABLE productos ADD COLUMN linea VARCHAR(60) NULL AFTER segmento', 'SELECT ''productos.linea ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos' AND COLUMN_NAME='es_heroe');
SET @s := IF(@c=0, 'ALTER TABLE productos ADD COLUMN es_heroe TINYINT(1) NOT NULL DEFAULT 0 AFTER linea', 'SELECT ''productos.es_heroe ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* ========================================================================
 *  3) Seguimiento de KPIs de campañas
 * ===================================================================== */
CREATE TABLE IF NOT EXISTS kpi_campanas (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(120) NOT NULL,
  descripcion   VARCHAR(255) NULL,
  fecha_inicio  DATE NOT NULL,
  fecha_fin     DATE NOT NULL,
  -- Periodo comparable del año anterior. Se guarda y no se deduce: el Black
  -- Friday no cae el mismo día del calendario dos años seguidos.
  ly_inicio     DATE NOT NULL,
  ly_fin        DATE NOT NULL,
  sucursal_id   INT UNSIGNED NULL,          -- NULL = todas
  tienda_id     INT UNSIGNED NULL,          -- NULL = todas las marcas
  meta_ventas   DECIMAL(14,2) NOT NULL DEFAULT 0,
  -- Pesos por euro para reportar la inversión a la casa matriz. Opcional.
  tasa_eur      DECIMAL(12,4) NULL,
  notas         TEXT NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_kc_fechas (fecha_inicio, fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kpi_campana_productos (
  campana_id  INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  orden       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (campana_id, producto_id),
  CONSTRAINT fk_kcp_campana  FOREIGN KEY (campana_id)  REFERENCES kpi_campanas(id) ON DELETE CASCADE,
  CONSTRAINT fk_kcp_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kpi_campana_inversiones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campana_id  INT UNSIGNED NOT NULL,
  rubro       VARCHAR(40) NOT NULL,         -- clave de kpi_rubros_inversion()
  detalle     VARCHAR(160) NULL,
  monto       DECIMAL(14,2) NOT NULL DEFAULT 0,  -- RD$
  resultado   VARCHAR(255) NULL,            -- KPI logrado o comentario
  PRIMARY KEY (id),
  KEY idx_kci_campana (campana_id),
  CONSTRAINT fk_kci_campana FOREIGN KEY (campana_id) REFERENCES kpi_campanas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KPIs que no salen del sistema: se capturan a mano.
CREATE TABLE IF NOT EXISTS kpi_campana_metricas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campana_id  INT UNSIGNED NOT NULL,
  metrica     VARCHAR(40) NOT NULL,         -- clave de kpi_metricas_manuales()
  valor       DECIMAL(16,2) NULL,
  valor_ly    DECIMAL(16,2) NULL,
  nota        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kcm (campana_id, metrica),
  CONSTRAINT fk_kcm_campana FOREIGN KEY (campana_id) REFERENCES kpi_campanas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Meta de venta neta por canal (Retail, Mayoreo, Web, Social selling).
CREATE TABLE IF NOT EXISTS kpi_campana_metas (
  campana_id  INT UNSIGNED NOT NULL,
  canal       VARCHAR(20) NOT NULL,
  meta        DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (campana_id, canal),
  CONSTRAINT fk_kcmeta_campana FOREIGN KEY (campana_id) REFERENCES kpi_campanas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ========================================================================
 *  4) Permisos (grupo Marketing). Declarados también en permission_catalog().
 * ===================================================================== */
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cockpit.ver' c, 'cockpit' m, 'Marketing' g, 'Promotion Cockpit — Ver el análisis de descuentos y margen' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'cockpit.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'kpi_campanas.ver' c, 'kpi_campanas' m, 'Marketing' g, 'KPIs de campañas — Ver' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'kpi_campanas.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'kpi_campanas.crear' c, 'kpi_campanas' m, 'Marketing' g, 'KPIs de campañas — Crear' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'kpi_campanas.crear');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'kpi_campanas.editar' c, 'kpi_campanas' m, 'Marketing' g, 'KPIs de campañas — Editar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'kpi_campanas.editar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'kpi_campanas.eliminar' c, 'kpi_campanas' m, 'Marketing' g, 'KPIs de campañas — Eliminar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'kpi_campanas.eliminar');

-- Se conceden a quien ya administra promociones.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'promociones.ver'
  JOIN permisos p  ON p.clave IN ('cockpit.ver','kpi_campanas.ver','kpi_campanas.crear','kpi_campanas.editar','kpi_campanas.eliminar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('cockpit.ver','kpi_campanas.ver','kpi_campanas.crear','kpi_campanas.editar','kpi_campanas.eliminar')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

/* ========================================================================
 *  5) Verificación
 * ===================================================================== */
SELECT 'venta_detalles.precio_lista' AS item, COUNT(*) AS ok FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalles' AND COLUMN_NAME = 'precio_lista'
UNION ALL SELECT 'productos.linea', COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'linea'
UNION ALL SELECT 'tabla kpi_campanas', COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_campanas'
UNION ALL SELECT 'permisos nuevos', COUNT(*) FROM permisos WHERE clave LIKE 'cockpit.%' OR clave LIKE 'kpi_campanas.%';

-- REVERSIÓN:
--   DROP TABLE IF EXISTS kpi_campana_metas, kpi_campana_metricas, kpi_campana_inversiones, kpi_campana_productos, kpi_campanas;
--   ALTER TABLE venta_detalles DROP KEY idx_vd_promocion, DROP COLUMN promocion_id, DROP COLUMN precio_lista;
--   ALTER TABLE ventas DROP COLUMN descuento_motivo;
--   ALTER TABLE promociones DROP COLUMN tipo_descuento, DROP COLUMN codigo;
--   ALTER TABLE productos DROP COLUMN es_heroe, DROP COLUMN linea, DROP COLUMN segmento;
--   DELETE FROM permisos WHERE clave LIKE 'cockpit.%' OR clave LIKE 'kpi_campanas.%';
