-- ============================================================================
--  Migración P11 — Cuentas por pagar, monedas y cotizaciones
--
--  Tres huecos que se tapan a la vez porque se necesitan entre ellos: un
--  importador compra en dólares a crédito y cotiza en dólares antes de vender.
--
--  1. CUENTAS POR PAGAR REALES
--     Hasta ahora una compra a crédito solo podía estar «pagada» o «no pagada»
--     (compras.fecha_pago). No se podía abonar. Ahora cada compra lleva su saldo
--     y cada proveedor su balance, igual que ya hacían clientes y ventas.
--
--  2. MONEDAS
--     La moneda base sigue siendo el peso: TODOS los importes de contabilidad,
--     reportes y DGII se guardan en RD$ exactamente como hasta hoy. Lo que se
--     añade es el importe pactado en la moneda extranjera y la tasa usada, para
--     saber qué se acordó de verdad y calcular la diferencia cambiaria al pagar.
--     Ningún reporte existente cambia de resultado.
--
--  3. COTIZACIONES
--     Documento previo a la venta, con vigencia, que se convierte en factura
--     respetando el precio cotizado.
--
--  Idempotente. Reversión al final.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
--  1. Monedas
--     `tasa` = cuántos pesos vale 1 unidad de esa moneda. La base siempre 1.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS monedas (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo     VARCHAR(3)   NOT NULL,          -- DOP, USD, EUR
  nombre     VARCHAR(60)  NOT NULL,
  simbolo    VARCHAR(6)   NOT NULL,
  tasa       DECIMAL(14,6) NOT NULL DEFAULT 1,
  es_base    TINYINT(1)   NOT NULL DEFAULT 0,
  activo     TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_moneda_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO monedas (codigo, nombre, simbolo, tasa, es_base, activo)
SELECT * FROM (SELECT 'DOP' a, 'Peso dominicano' b, 'RD$' c, 1.000000 d, 1 e, 1 f) t
WHERE NOT EXISTS (SELECT 1 FROM monedas WHERE codigo = 'DOP');
INSERT INTO monedas (codigo, nombre, simbolo, tasa, es_base, activo)
SELECT * FROM (SELECT 'USD' a, 'Dólar estadounidense' b, 'US$' c, 60.000000 d, 0 e, 1 f) t
WHERE NOT EXISTS (SELECT 1 FROM monedas WHERE codigo = 'USD');
INSERT INTO monedas (codigo, nombre, simbolo, tasa, es_base, activo)
SELECT * FROM (SELECT 'EUR' a, 'Euro' b, '€' c, 65.000000 d, 0 e, 0 f) t
WHERE NOT EXISTS (SELECT 1 FROM monedas WHERE codigo = 'EUR');

-- ---------------------------------------------------------------------------
--  2. Cuentas por pagar
-- ---------------------------------------------------------------------------
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores' AND COLUMN_NAME = 'balance');
SET @s := IF(@falta, 'ALTER TABLE proveedores
    ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER direccion,
    ADD KEY idx_prov_balance (balance)',
  'SELECT ''proveedores ya tiene balance''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compras' AND COLUMN_NAME = 'saldo');
SET @s := IF(@falta, 'ALTER TABLE compras
    ADD COLUMN moneda_id    INT UNSIGNED NULL AFTER proveedor_id,
    ADD COLUMN tasa_cambio  DECIMAL(14,6) NOT NULL DEFAULT 1 AFTER moneda_id,
    ADD COLUMN total_moneda DECIMAL(14,2) NULL AFTER total,
    ADD COLUMN saldo        DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_moneda,
    ADD COLUMN saldo_moneda DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER saldo,
    ADD KEY idx_compra_saldo (saldo)',
  'SELECT ''compras ya tiene saldo''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS pagos_proveedores (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id   INT UNSIGNED NOT NULL,
  compra_id      INT UNSIGNED NULL,            -- NULL = abono general a la cuenta
  sucursal_id    INT UNSIGNED NULL,
  monto          DECIMAL(12,2) NOT NULL,       -- SIEMPRE en pesos (lo que salió de caja)
  moneda_id      INT UNSIGNED NULL,            -- moneda en la que se pagó
  monto_moneda   DECIMAL(14,2) NULL,           -- importe en esa moneda
  tasa_cambio    DECIMAL(14,6) NOT NULL DEFAULT 1,
  diferencia_cambiaria DECIMAL(12,2) NOT NULL DEFAULT 0,  -- + perdiste, − ganaste
  metodo_pago_id INT UNSIGNED NULL,
  referencia     VARCHAR(60) NULL,             -- número de cheque o transferencia
  notas          VARCHAR(255) NULL,
  usuario_id     INT UNSIGNED NULL,
  fecha          DATETIME NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pp_proveedor (proveedor_id),
  KEY idx_pp_compra (compra_id),
  KEY idx_pp_fecha (fecha),
  CONSTRAINT fk_pp_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Poner al día las compras que ya existen:
--   · a crédito y sin fecha de pago  → quedan debiendo su total
--   · lo demás                       → saldo cero
-- Solo se toca una vez (se detecta porque aún no tienen moneda asignada).
SET @sinMigrar := (SELECT COUNT(*) FROM compras WHERE moneda_id IS NULL);
SET @base := (SELECT id FROM monedas WHERE es_base = 1 LIMIT 1);

UPDATE compras
   SET moneda_id = @base, tasa_cambio = 1, total_moneda = total,
       saldo = CASE WHEN estado <> 'anulada' AND forma_pago = 4 AND fecha_pago IS NULL THEN total ELSE 0 END,
       saldo_moneda = CASE WHEN estado <> 'anulada' AND forma_pago = 4 AND fecha_pago IS NULL THEN total ELSE 0 END
 WHERE moneda_id IS NULL;

-- El balance del proveedor es la suma de los saldos de sus compras.
UPDATE proveedores p
   SET p.balance = COALESCE((SELECT SUM(c.saldo) FROM compras c
                              WHERE c.proveedor_id = p.id AND c.estado <> 'anulada'), 0)
 WHERE @sinMigrar > 0;

-- ---------------------------------------------------------------------------
--  3. Cotizaciones
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizaciones (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero       VARCHAR(30) NOT NULL,
  cliente_id   INT UNSIGNED NOT NULL,
  sucursal_id  INT UNSIGNED NOT NULL,
  fecha        DATE NOT NULL,
  validez_dias INT NOT NULL DEFAULT 15,
  vence        DATE NOT NULL,
  moneda_id    INT UNSIGNED NULL,
  tasa_cambio  DECIMAL(14,6) NOT NULL DEFAULT 1,
  subtotal     DECIMAL(14,2) NOT NULL DEFAULT 0,   -- en la moneda del documento
  descuento    DECIMAL(14,2) NOT NULL DEFAULT 0,
  itbis        DECIMAL(14,2) NOT NULL DEFAULT 0,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_base   DECIMAL(14,2) NOT NULL DEFAULT 0,   -- el mismo total en pesos
  estado       ENUM('borrador','enviada','aceptada','rechazada','vencida','facturada') NOT NULL DEFAULT 'borrador',
  condiciones  TEXT NULL,
  notas        VARCHAR(500) NULL,
  venta_id     INT UNSIGNED NULL,                  -- factura generada al aceptarla
  usuario_id   INT UNSIGNED NULL,
  enviada_at   DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cot_numero (numero),
  KEY idx_cot_cliente (cliente_id),
  KEY idx_cot_estado (estado, vence),
  KEY idx_cot_sucursal (sucursal_id),
  CONSTRAINT fk_cot_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizacion_detalles (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id   INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NULL,
  descripcion     VARCHAR(255) NOT NULL,
  cantidad        DECIMAL(12,3) NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(14,2) NOT NULL DEFAULT 0,
  itbis           DECIMAL(14,2) NOT NULL DEFAULT 0,
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
  orden           INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_cotd_cot (cotizacion_id),
  CONSTRAINT fk_cotd_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  4. Permisos
-- ---------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cxp.ver' c,'cxp' m,'Inventario' g,'Cuentas por Pagar — Ver' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='cxp.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cxp.pagar' c,'cxp' m,'Inventario' g,'Cuentas por Pagar — Registrar pagos' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='cxp.pagar');

INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cotizaciones.ver' c,'cotizaciones' m,'Ventas' g,'Cotizaciones — Ver' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='cotizaciones.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cotizaciones.crear' c,'cotizaciones' m,'Ventas' g,'Cotizaciones — Crear y editar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='cotizaciones.crear');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cotizaciones.eliminar' c,'cotizaciones' m,'Ventas' g,'Cotizaciones — Eliminar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='cotizaciones.eliminar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cotizaciones.facturar' c,'cotizaciones' m,'Ventas' g,'Cotizaciones — Convertir en factura' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='cotizaciones.facturar');

INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'monedas.gestionar' c,'monedas' m,'Administración' g,'Monedas y tasa de cambio' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='monedas.gestionar');

-- Quien ya podía ver compras, ve cuentas por pagar.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'compras.ver'
  JOIN permisos p  ON p.clave IN ('cxp.ver','cxp.pagar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

-- Quien ya podía ver ventas, cotiza.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'ventas.ver'
  JOIN permisos p  ON p.clave IN ('cotizaciones.ver','cotizaciones.crear','cotizaciones.facturar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

-- El rol super lo tiene todo.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r
  JOIN permisos p ON p.clave IN ('cxp.ver','cxp.pagar','cotizaciones.ver','cotizaciones.crear',
                                 'cotizaciones.eliminar','cotizaciones.facturar','monedas.gestionar')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

-- Categorías financieras para los movimientos nuevos.
INSERT INTO categorias_financieras (tipo, nombre)
SELECT * FROM (SELECT 'gasto' a, 'Pago a Proveedores' b) t
WHERE NOT EXISTS (SELECT 1 FROM categorias_financieras WHERE tipo='gasto' AND nombre='Pago a Proveedores');
INSERT INTO categorias_financieras (tipo, nombre)
SELECT * FROM (SELECT 'gasto' a, 'Diferencia Cambiaria' b) t
WHERE NOT EXISTS (SELECT 1 FROM categorias_financieras WHERE tipo='gasto' AND nombre='Diferencia Cambiaria');
INSERT INTO categorias_financieras (tipo, nombre)
SELECT * FROM (SELECT 'ingreso' a, 'Diferencia Cambiaria' b) t
WHERE NOT EXISTS (SELECT 1 FROM categorias_financieras WHERE tipo='ingreso' AND nombre='Diferencia Cambiaria');

-- ---------------------------------------------------------------------------
--  Verificación
-- ---------------------------------------------------------------------------
SELECT 'tabla monedas' item, COUNT(*) ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='monedas'
UNION ALL SELECT 'monedas sembradas', COUNT(*) FROM monedas
UNION ALL SELECT 'proveedores.balance', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='proveedores' AND COLUMN_NAME='balance'
UNION ALL SELECT 'compras.saldo', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compras' AND COLUMN_NAME='saldo'
UNION ALL SELECT 'tabla pagos_proveedores', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_proveedores'
UNION ALL SELECT 'tabla cotizaciones', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cotizaciones'
UNION ALL SELECT 'tabla cotizacion_detalles', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cotizacion_detalles'
UNION ALL SELECT 'permisos nuevos', COUNT(*) FROM permisos WHERE clave LIKE 'cxp.%' OR clave LIKE 'cotizaciones.%' OR clave='monedas.gestionar'
UNION ALL SELECT 'compras con deuda', COUNT(*) FROM compras WHERE saldo > 0
UNION ALL SELECT 'proveedores con balance', COUNT(*) FROM proveedores WHERE balance > 0;

-- ============================================================================
--  REVERSIÓN
--    DROP TABLE IF EXISTS cotizacion_detalles, cotizaciones, pagos_proveedores, monedas;
--    ALTER TABLE proveedores DROP COLUMN balance;
--    ALTER TABLE compras DROP COLUMN moneda_id, DROP COLUMN tasa_cambio,
--      DROP COLUMN total_moneda, DROP COLUMN saldo, DROP COLUMN saldo_moneda;
--    DELETE FROM permisos WHERE clave LIKE 'cxp.%' OR clave LIKE 'cotizaciones.%' OR clave='monedas.gestionar';
-- ============================================================================
