-- ===========================================================================
--  NexoPOS · PUESTA AL DÍA DE PRODUCCIÓN — octubre 2026
-- ===========================================================================
--  Un solo archivo para dejar la base de producción lista para esta entrega:
--
--    1. P39 · Moneda del producto y tasa de mercado   (migracion_moneda_producto_p39.sql)
--    2. P39 · Promotion Cockpit y KPIs de campañas    (migracion_cockpit_promociones_p39.sql)
--    3. P40 · Cockpit configurable desde la pantalla  (migracion_cockpit_config_p40.sql)
--    4. P41 · Tasa de venta automática del dólar      (migracion_tasa_automatica_p41.sql)
--    5. P42 · Viernes Santo y Corpus Christi en su día (migracion_feriados_pascua_p42.sql)
--
--  Es la concatenación literal de esos cinco archivos, en ese orden, más una
--  comprobación de requisitos al principio y una tabla de verificación al
--  final. Si se corrige alguno de los cinco, hay que regenerar este.
--
--  CÓMO SE APLICA (ver DESPLIEGUE.md, sección «Entrega de octubre 2026»):
--    0. Respaldo de la base (cPanel → Copias de seguridad).
--    1. PRIMERO el código: cPanel → Git Version Control → Update From Remote.
--    2. DESPUÉS esto: phpMyAdmin → la base → Importar → este archivo, con el
--       juego de caracteres en utf-8. O por consola:
--         mysql -u USUARIO -p NOMBRE_BASE < database/actualizacion_produccion_2026_10.sql
--    3. La última tabla que imprime debe decir OK en todas las filas.
--
--  IDEMPOTENTE Y NO DESTRUCTIVO: se puede correr las veces que haga falta. No
--  borra ni cambia precios, ventas ni ningún dato existente; añade columnas,
--  tablas, catálogos de fábrica y permisos. El dólar queda con la tasa de venta
--  automática SOLO la primera vez: si después alguien la pasa a mano, volver a
--  correr el archivo no se lo cambia.
--
--  Probado en MariaDB 10.4; escrito para MySQL 8.0 (sin ADD COLUMN IF NOT
--  EXISTS ni sintaxis exclusiva de MariaDB).
-- ===========================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
--  0. Requisitos: migraciones anteriores que esta entrega da por aplicadas.
--     Si alguna dice FALTA, aplica ese archivo antes y vuelve a correr este.
-- ---------------------------------------------------------------------------
SELECT 'Requisito' AS paso, 'tabla monedas (P11)' AS item,
       IF(COUNT(*) = 1, 'OK', 'FALTA: migracion_cxp_monedas_cotizaciones_p11.sql') AS estado
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas'
UNION ALL
SELECT 'Requisito', 'tabla promociones (P2)',
       IF(COUNT(*) = 1, 'OK', 'FALTA: migracion_promociones_p2.sql')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promociones'
UNION ALL
SELECT 'Requisito', 'tabla pedido_detalles (tienda en línea)',
       IF(COUNT(*) = 1, 'OK', 'FALTA: migracion_tienda.sql')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_detalles'
UNION ALL
SELECT 'Requisito', 'tabla sistema_estado (P3/P4)',
       IF(COUNT(*) = 1, 'OK', 'FALTA: migracion_produccion.sql')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sistema_estado'
UNION ALL
SELECT 'Requisito', 'tabla tiendas (P16)',
       IF(COUNT(*) = 1, 'OK', 'FALTA: migracion_tiendas_p16.sql')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tiendas';


-- ###########################################################################
--  1. P39 · Moneda del producto y tasa de mercado
--     (contenido literal de database/migracion_moneda_producto_p39.sql)
-- ###########################################################################

-- ---------------------------------------------------------------------------
-- P39 · Precios en moneda extranjera y tasa de mercado automática
--
-- Dos cosas que faltaban, y que salieron a la luz cargando el catálogo de
-- INGLOT: sus 876 productos tienen la lista de precios en DÓLARES, y
-- `productos` no tenía dónde decirlo. Al guardarlos tal cual, el punto de
-- venta cobraría RD$29 por un labial de US$29.
--
-- ---------------------------------------------------------------------------
--  LAS DOS TASAS, Y POR QUÉ SON DOS
-- ---------------------------------------------------------------------------
-- `monedas.tasa` es la TASA DE VENTA: la que usan el POS y los documentos. Se
-- cambia A MANO y no se mueve sola. Decisión del negocio: el precio de góndola
-- no puede cambiar cada mañana porque el dólar se movió diez centavos, y una
-- etiqueta impresa dejaría de cuadrar con la caja el mismo día.
--
-- `monedas.tasa_mercado` es la REFERENCIA que se descarga sola. No cobra nada:
-- existe para que la pantalla pueda decir «el mercado está en 61.25 y tú sigues
-- vendiendo a 60.00» y para que la campana avise cuando la diferencia se hace
-- grande o cuando hace semanas que nadie revisa la tasa.
--
-- Nunca se reescribe el pasado. Cada compra, cotización y venta ya congela la
-- tasa con la que se hizo; esto solo cambia lo que se propone de aquí en
-- adelante. Es la misma regla de docs/CXP-MONEDAS-COTIZACIONES.md.
--
-- Idempotente. Probada en MariaDB 10.4 y MySQL 8.0.
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

DROP PROCEDURE IF EXISTS nexo_add_idx;
DELIMITER //
CREATE PROCEDURE nexo_add_idx(IN t VARCHAR(64), IN i VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = i) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD KEY `', i, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- ===========================================================================
-- 1. La moneda del producto
-- ---------------------------------------------------------------------------
-- NULL = moneda base (pesos), que es como se comportan hoy los 878 productos.
-- Así la columna se puede añadir sin tocar ni un precio: lo que no declara
-- moneda sigue siendo pesos, exactamente igual que antes.
-- ===========================================================================
CALL nexo_add_col('productos', 'moneda_id', 'INT UNSIGNED NULL COMMENT "NULL = moneda base (RD$)" AFTER `precio_venta`');
CALL nexo_add_idx('productos', 'idx_prod_moneda', '(`moneda_id`)');

-- ===========================================================================
-- 2. La tasa de mercado que se descarga sola
-- ===========================================================================
CALL nexo_add_col('monedas', 'tasa_mercado',        'DECIMAL(14,6) NULL COMMENT "Referencia descargada. NO se usa para cobrar." AFTER `tasa`');
CALL nexo_add_col('monedas', 'tasa_mercado_at',     'DATETIME NULL AFTER `tasa_mercado`');
CALL nexo_add_col('monedas', 'tasa_mercado_fuente', 'VARCHAR(40) NULL AFTER `tasa_mercado_at`');
CALL nexo_add_col('monedas', 'auto_actualizar',     'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Descargar la tasa de mercado de esta moneda" AFTER `tasa_mercado_fuente`');
CALL nexo_add_col('monedas', 'tasa_revisada_at',    'DATETIME NULL COMMENT "Última vez que una persona confirmó la tasa de venta" AFTER `auto_actualizar`');

-- El dólar es la única que interesa seguir hoy. El euro está inactivo.
UPDATE monedas SET auto_actualizar = 1 WHERE codigo = 'USD';

-- Para que la alerta de «hace semanas que nadie la revisa» no dispare el primer
-- día contra una fecha nula, se siembra con la última vez que se tocó la fila.
UPDATE monedas SET tasa_revisada_at = COALESCE(tasa_revisada_at, updated_at, NOW()) WHERE es_base = 0;

-- ===========================================================================
-- 3. Historial de la tasa
--
-- `auditoria` ya registra quién cambió qué, pero en texto libre: sirve para
-- responsabilizar, no para graficar ni para responder «¿a qué tasa estábamos el
-- 3 de agosto?». Esta tabla sí, y es lo que alimenta la pantalla de monedas.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS monedas_historial (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  moneda_id   INT UNSIGNED NOT NULL,
  tasa        DECIMAL(14,6) NOT NULL,                      -- la que quedó vigente
  origen      ENUM('manual','automatica') NOT NULL DEFAULT 'manual',
  campo       ENUM('venta','mercado') NOT NULL DEFAULT 'venta',
  fuente      VARCHAR(40) NULL,                            -- de dónde salió la automática
  usuario_id  INT UNSIGNED NULL,
  nota        VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mh_moneda (moneda_id, campo, created_at),
  CONSTRAINT fk_mh_moneda FOREIGN KEY (moneda_id) REFERENCES monedas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Punto de partida del historial: la tasa que hay hoy, para que la pantalla no
-- aparezca vacía y para no perder de dónde se venía.
INSERT INTO monedas_historial (moneda_id, tasa, origen, campo, nota)
SELECT m.id, m.tasa, 'manual', 'venta', 'Tasa vigente al aplicar la migración P39'
  FROM monedas m
 WHERE m.es_base = 0
   AND NOT EXISTS (SELECT 1 FROM monedas_historial h WHERE h.moneda_id = m.id AND h.campo = 'venta');

DROP PROCEDURE IF EXISTS nexo_add_col;
DROP PROCEDURE IF EXISTS nexo_add_idx;

-- ---------------------------------------------------------------------------
-- REVERSIÓN (si hiciera falta deshacerla)
--   ALTER TABLE productos DROP COLUMN moneda_id;
--   ALTER TABLE monedas DROP COLUMN tasa_mercado, DROP COLUMN tasa_mercado_at,
--     DROP COLUMN tasa_mercado_fuente, DROP COLUMN auto_actualizar,
--     DROP COLUMN tasa_revisada_at;
--   DROP TABLE monedas_historial;
-- Los precios de los productos NO se tocan en ningún caso: lo que esta
-- migración añade es la etiqueta de en qué moneda están, nunca el número.
-- ---------------------------------------------------------------------------

-- ###########################################################################
--  2. P39 · Promotion Cockpit y KPIs de campañas
--     (contenido literal de database/migracion_cockpit_promociones_p39.sql)
-- ###########################################################################

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

-- Los pedidos de la tienda online calculan su promoción al crearse: la guardan
-- ahí y se copia a la venta cuando se factura.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedido_detalles' AND COLUMN_NAME='precio_lista');
SET @s := IF(@c=0, 'ALTER TABLE pedido_detalles ADD COLUMN precio_lista DECIMAL(12,2) NULL AFTER precio_unitario', 'SELECT ''pedido_detalles.precio_lista ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedido_detalles' AND COLUMN_NAME='promocion_id');
SET @s := IF(@c=0, 'ALTER TABLE pedido_detalles ADD COLUMN promocion_id INT UNSIGNED NULL AFTER precio_lista', 'SELECT ''pedido_detalles.promocion_id ya existe''');
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
--   ALTER TABLE pedido_detalles DROP COLUMN promocion_id, DROP COLUMN precio_lista;
--   ALTER TABLE promociones DROP COLUMN tipo_descuento, DROP COLUMN codigo;
--   ALTER TABLE productos DROP COLUMN es_heroe, DROP COLUMN linea, DROP COLUMN segmento;
--   DELETE FROM permisos WHERE clave LIKE 'cockpit.%' OR clave LIKE 'kpi_campanas.%';

-- ###########################################################################
--  3. P40 · Cockpit configurable desde la pantalla
--     (contenido literal de database/migracion_cockpit_config_p40.sql)
-- ###########################################################################

-- ---------------------------------------------------------------------------
--  NexoPOS · P40 — Todo el Promotion Cockpit configurable desde la pantalla
-- ---------------------------------------------------------------------------
--  La P39 dejó en el código los catálogos del cockpit: tipos de descuento,
--  motivos del descuento en caja, canales de la marca y su regla, rubros de
--  inversión, KPIs capturados a mano y los parámetros (año fiscal, cuántas
--  promociones «menos activadas», etc.). La marca los quiere cambiar ella,
--  sin programador. Aquí pasan a tablas, sembradas con los mismos valores que
--  tenía el código: aplicar esta migración no cambia ningún número.
--
--    cockpit_tipos         la fila del cockpit. Un tipo puede ser familia de
--                          promoción, motivo de descuento en caja, o ambos.
--    cockpit_canales       los canales en que reporta la marca
--    cockpit_canal_reglas  cómo se decide el canal de una venta (por canal de
--                          captación o por tipo de comprobante; el resto cae
--                          en el canal por defecto)
--    kpi_rubros            rubros de inversión (español e inglés para el Excel)
--    kpi_metricas_def      KPIs capturados a mano, con su «rol» (tráfico,
--                          sesiones web) para calcular conversiones
--    cockpit_parametros    clave → valor
--    cockpit_vistas        vistas guardadas (pestaña + filtros) por usuario
--
--  Requiere la P39. Idempotente. Reversión al final.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cockpit_tipos (
  clave          VARCHAR(40)  NOT NULL,
  nombre         VARCHAR(80)  NOT NULL,              -- cómo se ve en el cockpit
  color          CHAR(7)      NOT NULL DEFAULT '#64748b',
  es_promocion   TINYINT(1)   NOT NULL DEFAULT 0,    -- se puede asignar a una promoción
  etiqueta_caja  VARCHAR(80)  NULL,                  -- si no es NULL, es un motivo del POS
  sistema        TINYINT(1)   NOT NULL DEFAULT 0,    -- lo usa el cálculo: no se borra
  orden          INT          NOT NULL DEFAULT 0,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_tipos (clave, nombre, color, es_promocion, etiqueta_caja, sistema, orden) VALUES
  ('set_regalo',  'Sets de regalo (Gift sets)',                   '#2a78d6', 1, NULL, 0, 10),
  ('calendario',  'Calendario de adviento',                       '#eb6834', 1, NULL, 0, 20),
  ('gwp',         'Regalo con compra (GWP / PWP)',                '#1baf7a', 1, NULL, 0, 30),
  ('crm',         'Clientes y fidelidad (CRM)',                   '#eda100', 1, 'Cliente frecuente / fidelidad', 0, 40),
  ('operacion',   'Operación especial (Black Friday, aniversario…)', '#e87ba4', 1, NULL, 0, 50),
  ('temporada',   'Temporada / rebajas (Sales)',                  '#008300', 1, NULL, 0, 60),
  ('lanzamiento', 'Lanzamiento',                                  '#4a3aa7', 1, NULL, 0, 70),
  ('empleados',   'Descuento de empleados',                       '#e34948', 1, 'Empleado', 0, 80),
  ('outlet',      'Outlet / liquidación',                         '#78716c', 1, 'Producto con defecto / liquidación', 0, 90),
  ('otro',        'Otro descuento',                               '#a8a29e', 1, 'Otro', 0, 100),
  ('cortesia',    'Cortesía / servicio al cliente',               '#57534e', 0, 'Cortesía / servicio', 0, 110),
  ('manual',      'Descuento manual en caja',                     '#475569', 0, 'Descuento manual', 1, 120),
  ('promocion',   'Promoción sin clasificar',                     '#94a3b8', 0, NULL, 1, 130),
  ('muestra',     'Muestras y regalos (RD$0)',                    '#64748b', 0, NULL, 1, 140),
  ('negociado',   'Precio negociado (cotización)',                '#334155', 0, NULL, 1, 150),
  ('sin',         'Sin promoción',                                '#cbd5e1', 0, NULL, 1, 160);

CREATE TABLE IF NOT EXISTS cockpit_canales (
  clave     VARCHAR(20) NOT NULL,
  nombre    VARCHAR(60) NOT NULL,
  nombre_en VARCHAR(60) NULL,             -- encabezado del Excel de la marca
  orden     INT NOT NULL DEFAULT 0,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_canales (clave, nombre, nombre_en, orden) VALUES
  ('retail',  'Retail (tiendas)',      'Retail',         10),
  ('mayoreo', 'Mayoreo / corporativo', 'Wholesale',      20),
  ('web',     'Web (tienda online)',   'Web',            30),
  ('social',  'Social selling',        'Social Selling', 40);

CREATE TABLE IF NOT EXISTS cockpit_canal_reglas (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- canal_venta: el canal de captación de la venta es igual a `valor`.
  -- comprobante: el tipo de comprobante NO es consumidor final (valor = 'no_consumidor')
  --              o es exactamente `valor` (credito_fiscal, gubernamental…).
  tipo   ENUM('canal_venta','comprobante') NOT NULL DEFAULT 'canal_venta',
  valor  VARCHAR(40) NOT NULL,
  canal  VARCHAR(20) NOT NULL,
  orden  INT NOT NULL DEFAULT 0,          -- gana la primera regla que se cumple
  PRIMARY KEY (id),
  UNIQUE KEY uq_regla (tipo, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_canal_reglas (tipo, valor, canal, orden) VALUES
  ('canal_venta', 'Tienda online', 'web',     10),
  ('canal_venta', 'Instagram',     'social',  20),
  ('canal_venta', 'WhatsApp',      'social',  30),
  ('canal_venta', 'Facebook',      'social',  40),
  ('canal_venta', 'TikTok',        'social',  50),
  ('comprobante', 'no_consumidor', 'mayoreo', 60);

CREATE TABLE IF NOT EXISTS kpi_rubros (
  clave     VARCHAR(40) NOT NULL,
  nombre    VARCHAR(120) NOT NULL,
  nombre_en VARCHAR(120) NULL,
  orden     INT NOT NULL DEFAULT 0,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO kpi_rubros (clave, nombre, nombre_en, orden) VALUES
  ('entrenamiento', 'Entrenamiento retail',                          'RETAIL TRAINING', 10),
  ('merchandising', 'Merchandising / animación en tienda (dummies)', 'MERCHANDISING/IN-STORE ANIMATION(DUMMIES)', 20),
  ('lanzamiento',   'Evento de lanzamiento',                          'LAUNCH EVENT', 30),
  ('pr',            'PR (seeding, artículos…)',                       'PR (seeding, articles, etc…)', 40),
  ('alianzas',      'Alianzas (marcas, influencers…)',                'PARTNERSHIPS (Brands, Influencers etc…)', 50),
  ('mall',          'Activaciones en mall, podios, pop-ups',          'MALL ANIMATIONS,PODIUMS, POP UPS', 60),
  ('paid_media',    'Medios pagados (Google Ads, Meta Ads)',          'PAID MEDIA (Google Ads, Meta Ads)', 70),
  ('ecommerce',     'E-commerce (incl. medios pagados)',              'ECOMMERCE (INCL. PAID MEDIA)', 80),
  ('ooh',           'Publicidad tradicional (vallas, mall, OOH)',     'ADVERTISING TRADITIONAL MEDIA (BILLBOARDS/MALL TAKE OVER/OOH)', 90),
  ('otros',         'Otras inversiones (regalos a top clientes…)',    'OTHER MARKETING INVESTMENTS', 100);

CREATE TABLE IF NOT EXISTS kpi_metricas_def (
  clave          VARCHAR(40) NOT NULL,
  grupo          VARCHAR(60) NOT NULL,
  nombre         VARCHAR(120) NOT NULL,
  nombre_en      VARCHAR(120) NULL,
  unidad         ENUM('num','pct','money') NOT NULL DEFAULT 'num',
  -- trafico: facturas ÷ esto = conversión en tienda; sesiones_web: pedidos web ÷ esto.
  rol            VARCHAR(20) NULL,
  menor_es_mejor TINYINT(1) NOT NULL DEFAULT 0,   -- la rotación del equipo baja = bueno
  orden          INT NOT NULL DEFAULT 0,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO kpi_metricas_def (clave, grupo, nombre, nombre_en, unidad, rol, menor_es_mejor, orden) VALUES
  ('trafico',            'Generales',                'Visitantes en tienda (tráfico)',               'Store traffic',            'num',   'trafico',      0, 10),
  ('nps',                'Generales',                'NPS',                                          'NPS results',              'num',   NULL,           0, 20),
  ('emv',                'Influencer / PR',          'EMV (valor mediático ganado)',                 'EMV',                      'money', NULL,           0, 30),
  ('impresiones_pr',     'Influencer / PR',          'Impresiones',                                  'Impressions',              'num',   NULL,           0, 40),
  ('alcance_pr',         'Influencer / PR',          'Alcance (reach)',                              'Reach',                    'num',   NULL,           0, 50),
  ('engagement_pr',      'Influencer / PR',          'Tasa de interacción (engagement)',             'Engagement Rate',          'pct',   NULL,           0, 60),
  ('impresiones_medios', 'OOH / Campañas en medios', 'Impresiones',                                  'Impressions',              'num',   NULL,           0, 70),
  ('sesiones_web',       'E-commerce',               'Sesiones en la tienda online',                 'Online store sessions',    'num',   'sesiones_web', 0, 80),
  ('asistentes',         'Entrenamiento',            'Beauty hosts que asistieron',                  'Beauty Hosts who attended','num',   NULL,           0, 90),
  ('encuesta',           'Entrenamiento',            'Satisfacción de la encuesta',                  'Attendees Survey Feedbacks','pct',  NULL,           0, 100),
  ('completitud',        'Entrenamiento',            'Tasa de completitud (MTS, talleres, tareas)',  'Completion rate',          'pct',   NULL,           0, 110),
  ('productividad',      'Entrenamiento',            'Crecimiento de productividad (antes/después)', 'Attendees productivity growth','pct', NULL,          0, 120),
  ('rotacion',           'Incentivos retail',        'Rotación del equipo',                          'Team turnover rate',       'pct',   NULL,           1, 130),
  ('nps_experiencia',    'Experiencia a la medida',  'NPS de la experiencia',                        'NPS results',              'num',   NULL,           0, 140);

CREATE TABLE IF NOT EXISTS cockpit_parametros (
  clave VARCHAR(40) NOT NULL,
  valor TEXT NULL,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_parametros (clave, valor) VALUES
  ('mes_inicio_fiscal',  '4'),
  ('periodo_defecto',    'ytd'),
  ('canal_defecto',      'retail'),
  ('menos_activadas',    '30'),
  ('top_skus',           '20'),
  ('dias_max',           '93'),
  -- Una opción por línea (CHAR(10) y no '\n': vale con o sin NO_BACKSLASH_ESCAPES).
  ('canales_captacion',  CONCAT_WS(CHAR(10), 'Mostrador', 'Instagram', 'WhatsApp', 'Facebook', 'Referido', 'Otro'));

-- Vistas guardadas: un nombre para una combinación de pestaña y filtros (lo
-- que va en la URL). Compartida = la ven todos los que entran al cockpit.
CREATE TABLE IF NOT EXISTS cockpit_vistas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(80)  NOT NULL,
  query       VARCHAR(1000) NOT NULL,
  compartida  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cv_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resumen por correo de una vista: '' (no), 'semanal' (la semana cerrada, cada
-- lunes) o 'mensual' (el mes cerrado, cada día 1). ultimo_periodo = último día
-- del periodo ya enviado: evita mandar dos veces el mismo.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cockpit_vistas' AND COLUMN_NAME='frecuencia');
SET @s := IF(@c=0, 'ALTER TABLE cockpit_vistas ADD COLUMN frecuencia VARCHAR(10) NOT NULL DEFAULT '''' AFTER compartida, ADD COLUMN ultimo_periodo DATE NULL AFTER frecuencia', 'SELECT ''cockpit_vistas.frecuencia ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- Último intento de envío: una vista cuyo correo falla espera antes de reintentar.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cockpit_vistas' AND COLUMN_NAME='ultimo_intento');
SET @s := IF(@c=0, 'ALTER TABLE cockpit_vistas ADD COLUMN ultimo_intento DATETIME NULL AFTER ultimo_periodo', 'SELECT ''cockpit_vistas.ultimo_intento ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Tope de descuento por tipo (% de su venta bruta): el objetivo de la marca
-- para cada familia. NULL = sin tope.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cockpit_tipos' AND COLUMN_NAME='tope_desc_pct');
SET @s := IF(@c=0, 'ALTER TABLE cockpit_tipos ADD COLUMN tope_desc_pct DECIMAL(5,2) NULL AFTER etiqueta_caja', 'SELECT ''cockpit_tipos.tope_desc_pct ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Permiso para cambiarlo todo (también en permission_catalog()).
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cockpit.configurar' c, 'cockpit' m, 'Marketing' g, 'Promotion Cockpit — Configurar catálogos, canales y parámetros' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'cockpit.configurar');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'promociones.editar'
  JOIN permisos p  ON p.clave = 'cockpit.configurar'
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave = 'cockpit.configurar'
 WHERE r.es_super = 1 AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

SELECT 'cockpit_tipos' item, COUNT(*) filas FROM cockpit_tipos
UNION ALL SELECT 'cockpit_canales', COUNT(*) FROM cockpit_canales
UNION ALL SELECT 'cockpit_canal_reglas', COUNT(*) FROM cockpit_canal_reglas
UNION ALL SELECT 'kpi_rubros', COUNT(*) FROM kpi_rubros
UNION ALL SELECT 'kpi_metricas_def', COUNT(*) FROM kpi_metricas_def
UNION ALL SELECT 'cockpit_parametros', COUNT(*) FROM cockpit_parametros
UNION ALL SELECT 'cockpit_vistas', COUNT(*) FROM cockpit_vistas;

-- REVERSIÓN:
--   DROP TABLE IF EXISTS cockpit_vistas, cockpit_parametros, kpi_metricas_def, kpi_rubros, cockpit_canal_reglas, cockpit_canales, cockpit_tipos;
--   DELETE FROM permisos WHERE clave = 'cockpit.configurar';

-- ###########################################################################
--  4. P41 · Tasa de venta automática
--     (contenido literal de database/migracion_tasa_automatica_p41.sql)
-- ###########################################################################

-- ---------------------------------------------------------------------------
-- P41 · La tasa de venta puede seguir al mercado sola
--
-- La P39 dejó la tasa de venta a mano a propósito: que el precio de góndola no
-- cambie cada mañana. La tienda pide lo contrario: su catálogo está en dólares
-- y quiere cobrar al dólar del día sin que nadie tenga que escribirlo.
--
-- Las dos formas conviven, moneda por moneda:
--
--   venta_auto = 0  → como hasta ahora: la tasa de venta se escribe a mano y
--                     la de mercado solo avisa.
--   venta_auto = 1  → cada vez que se descarga la tasa de mercado, la de venta
--                     pasa a ser  mercado × (1 + venta_margen / 100).
--                     El margen sirve para vender un poco por encima del
--                     mercado (p. ej. +1.5 %) y cubrir la reposición.
--
-- Igual que antes, nunca se reescribe el pasado: cada venta congela en pesos
-- lo que cobró, y cada cambio de tasa queda en monedas_historial.
--
-- Idempotente. Requiere la P39 (migracion_moneda_producto_p39.sql).
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

-- ¿Es la primera vez? Solo entonces se enciende el dólar (más abajo): si se
-- vuelve a correr después de que alguien lo pasara a mano, no se le pisa.
SET @p41_primera := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'venta_auto');

CALL nexo_add_col('monedas', 'venta_auto',   'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "La tasa de venta sigue a la de mercado" AFTER `auto_actualizar`');
CALL nexo_add_col('monedas', 'venta_margen', 'DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT "% sobre la tasa de mercado (puede ser negativo)" AFTER `venta_auto`');

-- El dólar pasa a automático: es lo que pidió la tienda. Se puede apagar en
-- Administración → Monedas y volver a la tasa a mano. Solo la primera vez.
UPDATE monedas SET venta_auto = 1, auto_actualizar = 1 WHERE codigo = 'USD' AND es_base = 0 AND @p41_primera = 1;

DROP PROCEDURE IF EXISTS nexo_add_col;

-- Verificación: las dos columnas deben decir OK.
SELECT 'monedas.venta_auto' AS item,
       IF(COUNT(*) = 1, 'OK', 'FALTA') AS estado
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'venta_auto'
UNION ALL
SELECT 'monedas.venta_margen', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'venta_margen';

-- ---------------------------------------------------------------------------
-- Reversión
--   ALTER TABLE monedas DROP COLUMN venta_margen, DROP COLUMN venta_auto;
-- ---------------------------------------------------------------------------

-- ###########################################################################
--  5. P42 · Viernes Santo y Corpus Christi en su día
--     (contenido literal de database/migracion_feriados_pascua_p42.sql)
-- ###########################################################################

-- ---------------------------------------------------------------------------
-- P42 · Viernes Santo y Corpus Christi en su día
--
-- jorFeriadosRD() calculaba la Pascua con easter_date(), que devuelve la
-- medianoche en la zona del ENTORNO (TZ, casi siempre UTC en el servidor). Al
-- formatearla en hora de Santo Domingo caía el sábado, así que Viernes Santo
-- se guardaba en jueves y Corpus Christi en miércoles: un día antes.
-- El código ya está corregido (includes/jornadas.php); esto arregla lo que ya
-- se hubiera sembrado con el error.
--
-- Solo mueve un feriado si:
--   · es automático (los puestos a mano no se tocan nunca), y
--   · está EXACTAMENTE un día antes de su fecha correcta, que es la huella del
--     error. Cualquier otra fecha la puso alguien a propósito.
-- UPDATE IGNORE: si en la fecha correcta ya hay otro feriado (UNIQUE por
-- fecha), esa fila se salta en vez de romper la importación.
--
-- La asistencia ya calculada de esos días NO se recalcula aquí: si hace falta,
-- se recalcula el periodo desde RR.HH. → Asistencia.
--
-- Idempotente: la segunda vez no encuentra nada que mover. Si la tabla
-- feriados no existe (falta la P38), no hace nada.
-- ---------------------------------------------------------------------------

DROP TEMPORARY TABLE IF EXISTS tmp_pascua;
CREATE TEMPORARY TABLE tmp_pascua (nombre VARCHAR(80) NOT NULL, mal DATE NOT NULL, ok DATE NOT NULL);
INSERT INTO tmp_pascua (nombre, mal, ok) VALUES
  ('Viernes Santo', '2024-03-28', '2024-03-29'),
  ('Corpus Christi', '2024-05-29', '2024-05-30'),
  ('Viernes Santo', '2025-04-17', '2025-04-18'),
  ('Corpus Christi', '2025-06-18', '2025-06-19'),
  ('Viernes Santo', '2026-04-02', '2026-04-03'),
  ('Corpus Christi', '2026-06-03', '2026-06-04'),
  ('Viernes Santo', '2027-03-25', '2027-03-26'),
  ('Corpus Christi', '2027-05-26', '2027-05-27'),
  ('Viernes Santo', '2028-04-13', '2028-04-14'),
  ('Corpus Christi', '2028-06-14', '2028-06-15'),
  ('Viernes Santo', '2029-03-29', '2029-03-30'),
  ('Corpus Christi', '2029-05-30', '2029-05-31'),
  ('Viernes Santo', '2030-04-18', '2030-04-19'),
  ('Corpus Christi', '2030-06-19', '2030-06-20'),
  ('Viernes Santo', '2031-04-10', '2031-04-11'),
  ('Corpus Christi', '2031-06-11', '2031-06-12'),
  ('Viernes Santo', '2032-03-25', '2032-03-26'),
  ('Corpus Christi', '2032-05-26', '2032-05-27'),
  ('Viernes Santo', '2033-04-14', '2033-04-15'),
  ('Corpus Christi', '2033-06-15', '2033-06-16'),
  ('Viernes Santo', '2034-04-06', '2034-04-07'),
  ('Corpus Christi', '2034-06-07', '2034-06-08'),
  ('Viernes Santo', '2035-03-22', '2035-03-23'),
  ('Corpus Christi', '2035-05-23', '2035-05-24');

SET @hay := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feriados');
SET @s := IF(@hay = 1,
  'UPDATE IGNORE feriados f JOIN tmp_pascua t ON t.nombre = f.nombre AND t.mal = f.fecha SET f.fecha = t.ok WHERE f.automatico = 1',
  'SELECT 0');
-- ROW_COUNT() se lee justo después de EXECUTE: DEALLOCATE lo pone a cero.
PREPARE st FROM @s; EXECUTE st; SET @movidos := IF(@hay = 1, ROW_COUNT(), 0); DEALLOCATE PREPARE st;

SELECT 'Feriados de Semana Santa corregidos' AS item, @movidos AS movidos,
       IF(@hay = 1, 'OK', 'sin tabla feriados (P38): nada que corregir') AS estado;

DROP TEMPORARY TABLE IF EXISTS tmp_pascua;


-- ===========================================================================
--  VERIFICACIÓN FINAL — todas las filas deben decir OK
-- ===========================================================================
SELECT 'P39 moneda' AS migracion, 'productos.moneda_id' AS item,
       IF(COUNT(*) = 1, 'OK', 'FALTA') AS estado
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'moneda_id'
UNION ALL SELECT 'P39 moneda', 'monedas.tasa_mercado', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'tasa_mercado'
UNION ALL SELECT 'P39 moneda', 'monedas.tasa_revisada_at', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME = 'tasa_revisada_at'
UNION ALL SELECT 'P39 moneda', 'tabla monedas_historial', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas_historial'
UNION ALL SELECT 'P39 cockpit', 'venta_detalles.precio_lista', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalles' AND COLUMN_NAME = 'precio_lista'
UNION ALL SELECT 'P39 cockpit', 'venta_detalles.promocion_id', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalles' AND COLUMN_NAME = 'promocion_id'
UNION ALL SELECT 'P39 cockpit', 'ventas.descuento_motivo', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'descuento_motivo'
UNION ALL SELECT 'P39 cockpit', 'pedido_detalles.precio_lista', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_detalles' AND COLUMN_NAME = 'precio_lista'
UNION ALL SELECT 'P39 cockpit', 'productos.segmento / linea / es_heroe', IF(COUNT(*) = 3, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME IN ('segmento', 'linea', 'es_heroe')
UNION ALL SELECT 'P39 cockpit', 'promociones.codigo / tipo_descuento', IF(COUNT(*) = 2, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promociones' AND COLUMN_NAME IN ('codigo', 'tipo_descuento')
UNION ALL SELECT 'P39 cockpit', 'tablas kpi_campana* (5)', IF(COUNT(*) = 5, 'OK', 'FALTA')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('kpi_campanas', 'kpi_campana_productos', 'kpi_campana_inversiones', 'kpi_campana_metricas', 'kpi_campana_metas')
UNION ALL SELECT 'P39 cockpit', 'permisos cockpit.ver y kpi_campanas.* (5)', IF(COUNT(*) = 5, 'OK', 'FALTA')
  FROM permisos WHERE clave IN ('cockpit.ver', 'kpi_campanas.ver', 'kpi_campanas.crear', 'kpi_campanas.editar', 'kpi_campanas.eliminar')
UNION ALL SELECT 'P40', 'tablas de configuración del cockpit (7)', IF(COUNT(*) = 7, 'OK', 'FALTA')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('cockpit_tipos', 'cockpit_canales', 'cockpit_canal_reglas', 'kpi_rubros', 'kpi_metricas_def', 'cockpit_parametros', 'cockpit_vistas')
UNION ALL SELECT 'P40', 'tipos de descuento sembrados', IF(COUNT(*) > 0, 'OK', 'FALTA') FROM cockpit_tipos
UNION ALL SELECT 'P40', 'cockpit_tipos.tope_desc_pct', IF(COUNT(*) = 1, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cockpit_tipos' AND COLUMN_NAME = 'tope_desc_pct'
UNION ALL SELECT 'P40', 'permiso cockpit.configurar', IF(COUNT(*) = 1, 'OK', 'FALTA') FROM permisos WHERE clave = 'cockpit.configurar'
UNION ALL SELECT 'P41', 'monedas.venta_auto / venta_margen', IF(COUNT(*) = 2, 'OK', 'FALTA')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monedas' AND COLUMN_NAME IN ('venta_auto', 'venta_margen');

-- Informativo: cómo queda el dólar. «automática» = la tasa de venta sigue al
-- mercado (se cambia en Administración → Monedas).
SELECT codigo, tasa AS tasa_venta, IF(venta_auto = 1, 'automática', 'a mano') AS tasa_de_venta,
       venta_margen AS margen_pct, IF(activo = 1, 'activa', 'inactiva') AS estado
  FROM monedas WHERE es_base = 0 ORDER BY activo DESC, codigo;
