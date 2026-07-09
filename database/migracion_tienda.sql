-- ============================================================================
--  Migración: Tienda online pública (catálogo por sucursal, pickup y link de pago)
--
--  Idempotente. Compatible con MySQL 8 y MariaDB.
--  Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) SUCURSALES: datos que necesita la tienda pública.
--    whatsapp: número desde el que atiende esa sucursal (solo dígitos, con país).
--    tienda_activa: permite sacar una sucursal del catálogo sin desactivarla.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sucursales' AND COLUMN_NAME='whatsapp');
SET @s := IF(@c=0,
  'ALTER TABLE sucursales
     ADD COLUMN whatsapp VARCHAR(20) NULL AFTER telefono,
     ADD COLUMN horario VARCHAR(120) NULL AFTER whatsapp,
     ADD COLUMN tienda_activa TINYINT(1) NOT NULL DEFAULT 1 AFTER activo',
  'SELECT ''sucursales ya migrada''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2) EMPRESA: link de pago que se envía al cliente por WhatsApp.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empresa' AND COLUMN_NAME='link_pago');
SET @s := IF(@c=0,
  'ALTER TABLE empresa
     ADD COLUMN link_pago VARCHAR(255) NULL AFTER mensaje_ticket,
     ADD COLUMN tienda_activa TINYINT(1) NOT NULL DEFAULT 1 AFTER link_pago',
  'SELECT ''empresa ya migrada''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3) PEDIDOS de la tienda online.
--
--    Un pedido NO descuenta stock: es una solicitud. El stock se mueve cuando
--    el pedido se convierte en venta desde el POS. Así la tienda nunca descuadra
--    el inventario ni la caja.
--
--    token: identificador público del pedido (el cliente lo usa para consultarlo
--    sin autenticarse). Nunca se expone el id.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pedidos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  token CHAR(32) NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  cliente_nombre VARCHAR(150) NOT NULL,
  cliente_telefono VARCHAR(40) NOT NULL,
  cliente_email VARCHAR(120) NULL,
  cliente_documento VARCHAR(30) NULL,
  notas VARCHAR(500) NULL,
  metodo_pago ENUM('pickup','link_pago') NOT NULL DEFAULT 'pickup',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado ENUM('pendiente','confirmado','listo','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  venta_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pedido_numero (numero),
  UNIQUE KEY uq_pedido_token (token),
  KEY idx_pedido_sucursal (sucursal_id),
  KEY idx_pedido_estado (estado),
  KEY fk_pedido_venta (venta_id),
  CONSTRAINT chk_pedido_total CHECK (total >= 0),
  CONSTRAINT fk_pedido_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
  CONSTRAINT fk_pedido_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedido_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NULL,
  descripcion VARCHAR(180) NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  precio_unitario DECIMAL(12,2) NOT NULL,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pd_pedido (pedido_id),
  CONSTRAINT chk_pedido_detalle CHECK (cantidad > 0 AND precio_unitario >= 0),
  CONSTRAINT fk_pd_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3b) Link de pago POR PEDIDO.
--
--     Cada pedido tiene su propio enlace, porque el monto cambia en cada venta.
--     empresa.link_pago queda solo como respaldo opcional cuando el comercio
--     usa un enlace genérico.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedidos' AND COLUMN_NAME='link_pago');
SET @s := IF(@c=0,
  'ALTER TABLE pedidos
     ADD COLUMN link_pago VARCHAR(500) NULL AFTER metodo_pago,
     ADD COLUMN link_pago_enviado_at DATETIME NULL AFTER link_pago',
  'SELECT ''pedidos.link_pago ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3c) Confirmación de pago.
--
--     Un pedido con link de pago no puede avanzar a «listo» ni «entregado», ni
--     facturarse, hasta que alguien confirme que el cliente realmente pagó.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedidos' AND COLUMN_NAME='pago_confirmado_at');
SET @s := IF(@c=0,
  'ALTER TABLE pedidos
     ADD COLUMN pago_confirmado_at DATETIME NULL AFTER link_pago_enviado_at,
     ADD COLUMN pago_confirmado_por INT UNSIGNED NULL AFTER pago_confirmado_at',
  'SELECT ''pedidos.pago_confirmado_at ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 4) PERMISOS del módulo de pedidos.
-- ---------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'pedidos.ver' AS c, 'pedidos' AS m, 'Ventas' AS g, 'Pedidos en línea — Ver' AS d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'pedidos.ver');

INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'pedidos.gestionar' AS c, 'pedidos' AS m, 'Ventas' AS g, 'Pedidos en línea — Cambiar estado' AS d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'pedidos.gestionar');

-- Se conceden a los roles que ya pueden ver ventas.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'ventas.ver'
  JOIN permisos p  ON p.clave IN ('pedidos.ver', 'pedidos.gestionar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

-- ---------------------------------------------------------------------------
-- 5) Verificación
-- ---------------------------------------------------------------------------
SELECT 'sucursales con WhatsApp configurado' AS chequeo, COUNT(*) AS filas FROM sucursales WHERE whatsapp IS NOT NULL AND whatsapp <> ''
UNION ALL SELECT 'sucursales visibles en la tienda', COUNT(*) FROM sucursales WHERE activo=1 AND tienda_activa=1
UNION ALL SELECT 'permisos de pedidos creados', COUNT(*) FROM permisos WHERE clave LIKE 'pedidos.%'
UNION ALL SELECT 'roles con acceso a pedidos.ver', COUNT(*) FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.clave='pedidos.ver';

-- ---------------------------------------------------------------------------
-- REVERSIÓN:
--   ALTER TABLE pedidos DROP COLUMN link_pago, DROP COLUMN link_pago_enviado_at;
--   DROP TABLE pedido_detalles; DROP TABLE pedidos;
--   ALTER TABLE sucursales DROP COLUMN whatsapp, DROP COLUMN horario, DROP COLUMN tienda_activa;
--   ALTER TABLE empresa DROP COLUMN link_pago, DROP COLUMN tienda_activa;
--   DELETE FROM permisos WHERE clave LIKE 'pedidos.%';
-- ---------------------------------------------------------------------------
