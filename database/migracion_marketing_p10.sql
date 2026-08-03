-- ============================================================================
--  Migración P10 — Diseño del correo personalizable
--
--  Los colores del correo estaban fijos en el código (verde). Un cliente con
--  marca azul o roja no tenía forma de cambiarlos sin tocar PHP, y el objetivo
--  del módulo es justamente que no haga falta.
--
--  Se guardan en `empresa` porque es donde vive el resto de la identidad
--  (nombre, logo, teléfono) y porque `setting()` ya lee de ahí.
--
--  Idempotente. Reversión al final.
-- ============================================================================

SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa' AND COLUMN_NAME = 'mkt_color');
SET @s := IF(@falta, 'ALTER TABLE empresa
    ADD COLUMN mkt_color        VARCHAR(7)   NOT NULL DEFAULT ''#15803D'' AFTER logo,
    ADD COLUMN mkt_color_boton  VARCHAR(7)   NOT NULL DEFAULT ''#15803D'' AFTER mkt_color,
    ADD COLUMN mkt_fondo        VARCHAR(7)   NOT NULL DEFAULT ''#F1F5F9'' AFTER mkt_color_boton,
    ADD COLUMN mkt_mostrar_logo TINYINT(1)   NOT NULL DEFAULT 1 AFTER mkt_fondo,
    ADD COLUMN mkt_pie          VARCHAR(255) NULL AFTER mkt_mostrar_logo',
  'SELECT ''empresa ya tiene el diseño del correo''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Permiso para tocar el diseño (va con el resto de Marketing).
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'marketing.diseno' c,'marketing' m,'Marketing' g,'Marketing — Diseño del correo (colores y logo)' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='marketing.diseno');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'marketing.plantillas'
  JOIN permisos p  ON p.clave = 'marketing.diseno'
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave = 'marketing.diseno'
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

SELECT 'empresa.mkt_color' item, COUNT(*) ok FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empresa' AND COLUMN_NAME='mkt_color'
UNION ALL SELECT 'empresa.mkt_pie', COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empresa' AND COLUMN_NAME='mkt_pie'
UNION ALL SELECT 'permiso marketing.diseno', COUNT(*) FROM permisos WHERE clave='marketing.diseno';

-- REVERSIÓN:
--   ALTER TABLE empresa DROP COLUMN mkt_color, DROP COLUMN mkt_color_boton,
--     DROP COLUMN mkt_fondo, DROP COLUMN mkt_mostrar_logo, DROP COLUMN mkt_pie;
--   DELETE FROM permisos WHERE clave='marketing.diseno';
