-- ============================================================================
--  Migración: correos automáticos de los pedidos en línea (Resend)
--
--  Idempotente. Compatible con MySQL 8 y MariaDB. Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Bitácora de correos. Sin esto, un correo que no llega es invisible.
--
--    Se guarda tanto el envío exitoso (con el id de Resend, para rastrearlo en
--    su panel) como el fallo con su mensaje de error.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS correos_enviados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id INT UNSIGNED NULL,
  evento VARCHAR(40) NOT NULL,          -- nuevo_cliente, nuevo_sucursal, link_pago, estado_listo...
  destinatario VARCHAR(180) NOT NULL,
  asunto VARCHAR(180) NOT NULL,
  estado ENUM('enviado','fallido') NOT NULL,
  proveedor_id VARCHAR(80) NULL,        -- id que devuelve Resend
  error VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_correo_pedido (pedido_id),
  KEY idx_correo_estado (estado),
  KEY idx_correo_fecha (created_at),
  CONSTRAINT fk_correo_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) El correo del cliente pasa a ser obligatorio en los pedidos nuevos.
--    Los pedidos viejos pueden no tenerlo, así que la columna sigue admitiendo
--    NULL: la exigencia vive en el formulario, no en el esquema.
--    Solo se amplía el tamaño para que quepan direcciones largas.
-- ---------------------------------------------------------------------------
SET @c := (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pedidos' AND COLUMN_NAME='cliente_email');
SET @s := IF(@c IS NOT NULL AND @c < 180,
  'ALTER TABLE pedidos MODIFY COLUMN cliente_email VARCHAR(180) NULL',
  'SELECT ''cliente_email ya tiene el tamaño adecuado''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3) Verificación
-- ---------------------------------------------------------------------------
SELECT 'tabla correos_enviados' AS chequeo, COUNT(*) AS filas
  FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correos_enviados'
UNION ALL
SELECT 'sucursales con email (reciben avisos)', COUNT(*) FROM sucursales WHERE email IS NOT NULL AND email <> ''
UNION ALL
SELECT 'pedidos con correo del cliente', COUNT(*) FROM pedidos WHERE cliente_email IS NOT NULL AND cliente_email <> '';

-- ---------------------------------------------------------------------------
-- REVERSIÓN:
--   DROP TABLE correos_enviados;
-- ---------------------------------------------------------------------------
