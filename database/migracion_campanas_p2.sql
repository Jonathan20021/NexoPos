-- ============================================================================
--  Migración P2 — Campañas por correo (sobre Resend)
--
--  Envío de correos masivos a los clientes (promos, avisos) usando la misma
--  infraestructura de Resend que ya usan los correos de pedidos. Cada campaña
--  guarda su contenido, el segmento de destinatarios y el resultado del envío;
--  cada correo individual queda registrado en correos_enviados.
--
--  Idempotente. Reversión al final.
-- ============================================================================

CREATE TABLE IF NOT EXISTS campanas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(140) NOT NULL,
  asunto      VARCHAR(180) NOT NULL,
  contenido   MEDIUMTEXT NOT NULL,                    -- cuerpo (HTML sencillo) que va dentro de la plantilla
  segmento    ENUM('con_email','con_deuda') NOT NULL DEFAULT 'con_email',
  estado      ENUM('borrador','enviada','parcial') NOT NULL DEFAULT 'borrador',
  total       INT NOT NULL DEFAULT 0,
  enviados    INT NOT NULL DEFAULT 0,
  fallidos    INT NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviada_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_campana_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enlazar cada correo enviado con su campaña (sin FK: enlace lógico, idempotente).
SET @tieneCol := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correos_enviados' AND COLUMN_NAME='campana_id');
SET @s := IF(@tieneCol=0,
  'ALTER TABLE correos_enviados ADD COLUMN campana_id INT UNSIGNED NULL AFTER pedido_id, ADD KEY idx_correo_campana (campana_id)',
  'SELECT ''campana_id ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Permisos (grupo Marketing)
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'campanas.ver' c,'campanas' m,'Marketing' g,'Campañas — Ver' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='campanas.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'campanas.crear' c,'campanas' m,'Marketing' g,'Campañas — Crear' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='campanas.crear');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'campanas.editar' c,'campanas' m,'Marketing' g,'Campañas — Editar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='campanas.editar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'campanas.eliminar' c,'campanas' m,'Marketing' g,'Campañas — Eliminar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='campanas.eliminar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'campanas.enviar' c,'campanas' m,'Marketing' g,'Campañas — Enviar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='campanas.enviar');

-- Concedidos a roles que ya ven reportes (Super/Admin/Gerente).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'reportes.ver'
  JOIN permisos p  ON p.clave IN ('campanas.ver','campanas.crear','campanas.editar','campanas.eliminar','campanas.enviar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('campanas.ver','campanas.crear','campanas.editar','campanas.eliminar','campanas.enviar')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

SELECT 'tabla campanas' item, COUNT(*) ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campanas'
UNION ALL SELECT 'correos.campana_id', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correos_enviados' AND COLUMN_NAME='campana_id'
UNION ALL SELECT 'permisos campanas', COUNT(*) FROM permisos WHERE clave LIKE 'campanas.%';

-- REVERSIÓN:
--   DROP TABLE IF EXISTS campanas;
--   ALTER TABLE correos_enviados DROP COLUMN campana_id;
--   DELETE FROM permisos WHERE clave LIKE 'campanas.%';
