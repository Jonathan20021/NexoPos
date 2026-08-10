-- ============================================================================
--  NexoPOS · P16 — Tiendas (marcas comerciales), costeo de importación
--                  y carga histórica para Dirección
-- ----------------------------------------------------------------------------
--  Importers TyE, S. A. no vende «lo suyo»: distribuye varias marcas y cada una
--  se presenta al cliente con su propia cara. La factura de L'Occitane tiene que
--  salir con el logo de L'Occitane, no con el de la importadora.
--
--  TRES DECISIONES QUE EXPLICAN TODO ESTE ARCHIVO
--
--  1. La TIENDA es identidad comercial, no un local ni una razón social.
--     Sucursal = dónde se vende (stock, caja, usuarios). Tienda = con qué marca
--     se vende (logo, colores, mensaje del ticket, política de devolución).
--     Son independientes a propósito: un mismo local puede atender dos marcas y
--     una marca puede estar en varios locales. Por eso `tiendas` NO cuelga de
--     `sucursales` ni al revés.
--
--  2. El EMISOR FISCAL sigue siendo la empresa. Un solo RNC, una sola secuencia
--     de NCF/e-CF. La tienda pone la marca en el papel; la DGII sigue viendo a
--     Importers TyE. Por eso aquí no hay secuencias NCF por tienda: habría que
--     rehacer el 606/607 y la facturación electrónica ya certificada.
--
--  3. La LIQUIDACIÓN de importación es un documento de COSTO, no de dinero.
--     Dice cuánto costó de verdad cada unidad puesta en el almacén (FOB + flete
--     + seguro + arancel + gastos aduanales) y entra la mercancía a ese costo.
--     No registra la deuda al proveedor ni el pago de los gastos: eso ya lo
--     hacen Compras y Cuentas por Pagar, y duplicarlo inflaría los gastos del
--     mes. Ver docs/TIENDAS-Y-DIRECCION.md.
--
--  Idempotente. Vale en MariaDB 10.4 y en MySQL 8. Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
--  Helpers: MySQL 8 no soporta `ADD COLUMN IF NOT EXISTS` (MariaDB sí).
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
-- 1. TIENDAS — la identidad comercial que sale impresa
-- ---------------------------------------------------------------------------
--    `encabezado` es la línea que va bajo el nombre en el ticket («L297 · World
--    Trade Center»), tomada de cómo imprimen las cadenas: identifica el punto de
--    venta ante el propio cliente cuando va a devolver algo.
--
--    `politica_devolucion` es TEXT y se imprime al pie: PROCONSUMIDOR exige que
--    la política esté a la vista, y en la práctica el ticket es donde el cliente
--    la lee.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS tiendas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(120) NOT NULL COMMENT 'Nombre comercial: el que ve el cliente',
  razon_social VARCHAR(180) NULL COMMENT 'Solo si difiere del nombre comercial',
  rnc VARCHAR(30) NULL COMMENT 'Informativo. El emisor fiscal es la empresa',
  direccion VARCHAR(255) NULL,
  ciudad VARCHAR(80) NULL,
  telefono VARCHAR(40) NULL,
  whatsapp VARCHAR(20) NULL,
  email VARCHAR(120) NULL,
  sitio_web VARCHAR(140) NULL,
  logo VARCHAR(255) NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#2563eb' COMMENT 'Acento de la factura y el ticket',
  encabezado VARCHAR(140) NULL COMMENT 'Línea bajo el nombre en el ticket',
  mensaje_ticket VARCHAR(255) NULL,
  politica_devolucion TEXT NULL,
  pie_factura VARCHAR(255) NULL,
  orden SMALLINT NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tienda_codigo (codigo),
  KEY idx_tienda_activo (activo, orden, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. La tienda en las tablas de operación.
--
--    NULL significa «sin marca asignada» y se comporta exactamente como antes
--    de esta migración: el ticket sale con los datos de la empresa. Eso permite
--    desplegar sin migrar el catálogo de golpe.
--
--    En `ventas` se guarda la tienda con la que se facturó, no se deduce del
--    producto: una factura ya impresa no puede cambiar de logo porque mañana el
--    producto se reasigne a otra marca.
-- ---------------------------------------------------------------------------
CALL nexo_add_col('productos', 'tienda_id', "INT UNSIGNED NULL COMMENT 'Marca comercial a la que pertenece'");
CALL nexo_add_col('ventas',    'tienda_id', "INT UNSIGNED NULL COMMENT 'Marca con la que se facturó (congelada)'");
CALL nexo_add_col('compras',   'tienda_id', "INT UNSIGNED NULL COMMENT 'Marca a la que se le compró la mercancía'");

CALL nexo_add_idx('productos', 'idx_p_tienda',  '(tienda_id, activo)');
CALL nexo_add_idx('ventas',    'idx_v_tienda',  '(tienda_id, fecha)');
CALL nexo_add_idx('compras',   'idx_c_tienda',  '(tienda_id, fecha)');

-- Claves foráneas con ON DELETE SET NULL: borrar una marca nunca puede borrar
-- ventas. (La UI desactiva en vez de borrar cuando hay historial.)
SET @x := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos' AND CONSTRAINT_NAME='fk_p_tienda');
SET @s := IF(@x=0,'ALTER TABLE productos ADD CONSTRAINT fk_p_tienda FOREIGN KEY (tienda_id) REFERENCES tiendas(id) ON DELETE SET NULL','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @x := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND CONSTRAINT_NAME='fk_v_tienda');
SET @s := IF(@x=0,'ALTER TABLE ventas ADD CONSTRAINT fk_v_tienda FOREIGN KEY (tienda_id) REFERENCES tiendas(id) ON DELETE SET NULL','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @x := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compras' AND CONSTRAINT_NAME='fk_c_tienda');
SET @s := IF(@x=0,'ALTER TABLE compras ADD CONSTRAINT fk_c_tienda FOREIGN KEY (tienda_id) REFERENCES tiendas(id) ON DELETE SET NULL','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ===========================================================================
-- 3. LIQUIDACIÓN DE IMPORTACIÓN — el costo real puesto en almacén
-- ---------------------------------------------------------------------------
--    `modo`:
--      entrada  → el embarque todavía no está en el inventario. Al aplicar, la
--                 mercancía ENTRA al costo final calculado aquí.
--      recosteo → la compra ya se registró y la mercancía ya entró (al costo de
--                 la factura del proveedor). Al aplicar NO se mueve cantidad:
--                 solo se corrige el costo unitario del producto. Es el caso de
--                 «llegó, lo metimos, y la semana siguiente llegó la factura de
--                 la agencia aduanal».
--
--    `prorrateo` decide cómo se reparten los gastos entre las líneas. Por valor
--    es lo estándar y lo que usa la DGII para valor en aduana; por peso o
--    volumen es lo correcto cuando el flete manda (mercancía barata y pesada).
--
--    Los importes se guardan SIEMPRE en pesos además de en la moneda pactada:
--    la contabilidad vive en pesos y un reporte del año pasado no puede cambiar
--    porque hoy subió el dólar (ver docs/CXP-MONEDAS-COTIZACIONES.md).
-- ===========================================================================
CREATE TABLE IF NOT EXISTS liquidaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  tienda_id INT UNSIGNED NULL,
  sucursal_id INT UNSIGNED NOT NULL COMMENT 'Almacén donde entra la mercancía',
  proveedor_id INT UNSIGNED NULL,
  compra_id INT UNSIGNED NULL COMMENT 'Solo en modo recosteo',
  modo ENUM('entrada','recosteo') NOT NULL DEFAULT 'entrada',
  referencia VARCHAR(60) NULL COMMENT 'BL, contenedor, DUA o factura del embarque',
  fecha DATE NOT NULL,
  fecha_llegada DATE NULL,
  moneda_id INT UNSIGNED NULL,
  tasa_cambio DECIMAL(14,6) NOT NULL DEFAULT 1,
  prorrateo ENUM('valor','cantidad','peso','volumen') NOT NULL DEFAULT 'valor',
  fob DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Mercancía en pesos',
  gastos DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Gastos que SÍ entran al costo',
  gastos_no_costo DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'ITBIS adelantado y otros recuperables',
  costo_total DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'fob + gastos',
  estado ENUM('borrador','transito','aplicada','anulada') NOT NULL DEFAULT 'borrador',
  notas VARCHAR(500) NULL,
  usuario_id INT UNSIGNED NULL,
  aplicada_at DATETIME NULL,
  aplicada_por INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_liq_numero (numero),
  KEY idx_liq_estado (estado, fecha),
  KEY idx_liq_sucursal (sucursal_id, estado),
  KEY idx_liq_tienda (tienda_id, fecha),
  KEY idx_liq_compra (compra_id),
  CONSTRAINT fk_liq_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
  CONSTRAINT fk_liq_tienda FOREIGN KEY (tienda_id) REFERENCES tiendas(id) ON DELETE SET NULL,
  CONSTRAINT fk_liq_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL,
  CONSTRAINT fk_liq_compra FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una línea por artículo del embarque.
--   costo_anterior guarda el `precio_compra` que tenía el producto ANTES de
--   aplicar. Sin eso, anular una liquidación no puede devolver el costo viejo y
--   el margen de todos los reportes queda torcido para siempre.
CREATE TABLE IF NOT EXISTS liquidacion_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  liquidacion_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  costo_moneda DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT 'FOB unitario en la moneda del embarque',
  costo_fob DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT 'FOB unitario en pesos',
  peso DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'kg por unidad (prorrateo por peso)',
  volumen DECIMAL(12,4) NOT NULL DEFAULT 0 COMMENT 'm3 por unidad (prorrateo por volumen)',
  prorrateo DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Gastos asignados a la línea completa',
  costo_final DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT 'Costo unitario puesto en almacén',
  costo_anterior DECIMAL(12,2) NULL COMMENT 'precio_compra del producto antes de aplicar',
  PRIMARY KEY (id),
  UNIQUE KEY uq_liqdet (liquidacion_id, producto_id),
  KEY idx_liqdet_producto (producto_id),
  CONSTRAINT chk_liqdet_valores CHECK (cantidad > 0 AND costo_moneda >= 0 AND costo_fob >= 0 AND peso >= 0 AND volumen >= 0),
  CONSTRAINT fk_liqdet_liq FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_liqdet_producto FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un embarque de cosmética entra con su lote y su vencimiento. Si el producto
-- está marcado con `controla_lote` y aquí no se captura nada, la mercancía cae
-- en SIN-LOTE y la trazabilidad queda coja justo en la entrada, que es donde
-- más falta hace. Ver docs/SANIDAD-Y-AUDITORIAS.md.
CALL nexo_add_col('liquidacion_detalles', 'lote',        "VARCHAR(60) NULL COMMENT 'Lote del embarque (mercancía regulada)'");
CALL nexo_add_col('liquidacion_detalles', 'vencimiento', "DATE NULL");

-- Gastos del embarque.
--   `al_costo = 0` es la trampa clásica del costeo dominicano: el ITBIS pagado
--   en aduana NO es costo, es un adelanto que se compensa contra el ITBIS
--   cobrado. Meterlo al costo infla el inventario un 18% y hunde el margen.
CREATE TABLE IF NOT EXISTS liquidacion_gastos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  liquidacion_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'otros' COMMENT 'flete, seguro, arancel, itbis, aduana, transporte, otros',
  concepto VARCHAR(140) NOT NULL,
  moneda_id INT UNSIGNED NULL,
  tasa_cambio DECIMAL(14,6) NOT NULL DEFAULT 1,
  monto_moneda DECIMAL(14,2) NOT NULL DEFAULT 0,
  monto DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'En pesos',
  al_costo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = recuperable, no entra al costo (ITBIS)',
  orden SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_liqgas_liq (liquidacion_id, orden),
  CONSTRAINT chk_liqgas_monto CHECK (monto >= 0 AND monto_moneda >= 0),
  CONSTRAINT fk_liqgas_liq FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. CARGA HISTÓRICA — un año de clientes y ventas del sistema anterior
-- ---------------------------------------------------------------------------
--    Todo lo que entra por un archivo queda marcado con su lote. Sin eso, un
--    archivo mal mapeado se mezcla con las ventas reales y no hay forma de
--    separarlos: habría que restaurar un respaldo completo. Con el lote, se
--    revierte con un botón.
--
--    Una venta histórica NO mueve stock (el inventario de hoy ya refleja la
--    realidad), NO consume NCF (los comprobantes ya se emitieron en el sistema
--    viejo) y NO genera movimientos de caja (duplicaría el flujo de efectivo de
--    un año ya cerrado). Sí alimenta ventas, márgenes y comparativos.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS importaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo ENUM('clientes','ventas') NOT NULL,
  archivo VARCHAR(200) NULL,
  filas INT UNSIGNED NOT NULL DEFAULT 0,
  creados INT UNSIGNED NOT NULL DEFAULT 0,
  actualizados INT UNSIGNED NOT NULL DEFAULT 0,
  omitidos INT UNSIGNED NOT NULL DEFAULT 0,
  monto DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'Suma importada (solo ventas)',
  estado ENUM('procesada','revertida') NOT NULL DEFAULT 'procesada',
  detalle MEDIUMTEXT NULL COMMENT 'JSON: mapeo usado, avisos y filas rechazadas',
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revertida_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_imp_tipo (tipo, estado, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL nexo_add_col('ventas',   'importacion_id', "INT UNSIGNED NULL COMMENT 'Lote de carga histórica que la creó'");
CALL nexo_add_col('clientes', 'importacion_id', "INT UNSIGNED NULL COMMENT 'Lote de carga histórica que lo creó'");
CALL nexo_add_idx('ventas',   'idx_v_importacion',   '(importacion_id)');
CALL nexo_add_idx('clientes', 'idx_cli_importacion', '(importacion_id)');

-- Sin FK a propósito: si algún día se purgan lotes viejos de `importaciones`,
-- las ventas no pueden desaparecer con ellos ni quedar bloqueando el borrado.

-- ---------------------------------------------------------------------------
-- 5. Índice de apoyo para los comparativos año contra año.
--    El panel de Dirección barre 24 meses agrupando por mes; sin un índice que
--    empiece por estado y fecha, con 60.000 ventas eso es un recorrido completo
--    por cada tarjeta de la pantalla.
-- ---------------------------------------------------------------------------
CALL nexo_add_idx('ventas', 'idx_v_estado_fecha_tienda', '(estado, fecha, tienda_id)');

-- ===========================================================================
-- 6. Permisos
-- ===========================================================================
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('tiendas.ver',         'tiendas',       'Administración', 'Tiendas y marcas — Ver'),
 ('tiendas.crear',       'tiendas',       'Administración', 'Tiendas y marcas — Crear'),
 ('tiendas.editar',      'tiendas',       'Administración', 'Tiendas y marcas — Editar'),
 ('tiendas.eliminar',    'tiendas',       'Administración', 'Tiendas y marcas — Eliminar'),
 ('liquidaciones.ver',    'liquidaciones', 'Inventario',    'Liquidación de importaciones — Ver'),
 ('liquidaciones.crear',  'liquidaciones', 'Inventario',    'Liquidación de importaciones — Crear y editar el borrador'),
 ('liquidaciones.aplicar','liquidaciones', 'Inventario',    'Liquidación de importaciones — Aplicar (entra mercancía y fija el costo)'),
 ('liquidaciones.anular', 'liquidaciones', 'Inventario',    'Liquidación de importaciones — Anular'),
 ('direccion.ver',       'direccion',     'Dirección',      'Área de Dirección — Ver el panel, comparativos y costos'),
 ('direccion.importar',  'direccion',     'Dirección',      'Área de Dirección — Cargar y revertir datos históricos');

-- Quien ya administra sucursales administra las marcas: es la misma persona.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p  ON p.id = rp.permiso_id
  JOIN permisos p2 ON p2.clave IN ('tiendas.ver','tiendas.crear','tiendas.editar','tiendas.eliminar')
 WHERE p.clave = 'sucursales.editar';

-- Quien registra compras ve y prepara liquidaciones. APLICAR y ANULAR no se
-- conceden solos: aplicar mueve inventario y reescribe el costo de venta.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p  ON p.id = rp.permiso_id
  JOIN permisos p2 ON p2.clave IN ('liquidaciones.ver','liquidaciones.crear')
 WHERE p.clave = 'compras.crear';

-- El área de Dirección va con los reportes de dirección (CEO). Importar y
-- revertir tampoco se concede solo: sube un año entero de datos.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p  ON p.id = rp.permiso_id
  JOIN permisos p2 ON p2.clave = 'direccion.ver'
 WHERE p.clave = 'reportes.ejecutivo';

-- ---------------------------------------------------------------------------
-- 7. Sin datos de ejemplo.
--    Crear una tienda «Principal» automáticamente parece amable y no lo es: en
--    cuanto exista una tienda, el POS empieza a exigir elegir marca y a filtrar
--    el catálogo. Mientras no haya ninguna, el sistema se comporta igual que
--    antes de esta migración. La primera tienda la crea una persona.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS nexo_add_col;
DROP PROCEDURE IF EXISTS nexo_add_idx;

-- ============================================================================
--  REVERSIÓN (pegar a mano si hiciera falta)
-- ----------------------------------------------------------------------------
--  ALTER TABLE ventas    DROP FOREIGN KEY fk_v_tienda, DROP COLUMN tienda_id, DROP COLUMN importacion_id;
--  ALTER TABLE productos DROP FOREIGN KEY fk_p_tienda, DROP COLUMN tienda_id;
--  ALTER TABLE compras   DROP FOREIGN KEY fk_c_tienda, DROP COLUMN tienda_id;
--  ALTER TABLE clientes  DROP COLUMN importacion_id;
--  DROP TABLE IF EXISTS liquidacion_gastos, liquidacion_detalles, liquidaciones, importaciones, tiendas;
--  DELETE FROM permisos WHERE modulo IN ('tiendas','liquidaciones','direccion');
-- ============================================================================
