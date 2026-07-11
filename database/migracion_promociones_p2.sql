-- ============================================================================
--  Migración P2 — Promociones (marca / categoría / producto / temporada)
--
--  Descuentos automáticos por vigencia de fechas ("temporada"), aplicables a todo
--  el catálogo, a una categoría, a una marca o a un producto. Se aplican tanto en
--  el POS como en la tienda online (o solo en uno, según el canal). El precio se
--  recalcula SIEMPRE en el servidor; el navegador nunca decide el descuento.
--
--  Idempotente. Reversión al final.
-- ============================================================================

CREATE TABLE IF NOT EXISTS promociones (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(120) NOT NULL,
  tipo         ENUM('porcentaje','monto') NOT NULL DEFAULT 'porcentaje',
  valor        DECIMAL(12,2) NOT NULL DEFAULT 0,       -- % (0-100) o RD$ según tipo
  alcance      ENUM('todos','categoria','marca','producto') NOT NULL DEFAULT 'todos',
  objetivo_id  INT UNSIGNED NULL,                      -- id de categoría/marca/producto (NULL si 'todos')
  canal        ENUM('ambos','pos','tienda') NOT NULL DEFAULT 'ambos',
  fecha_inicio DATE NOT NULL,
  fecha_fin    DATE NOT NULL,
  prioridad    INT NOT NULL DEFAULT 0,                 -- desempate cuando varias aplican con igual descuento
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_promo_vigencia (activo, fecha_inicio, fecha_fin),
  KEY idx_promo_alcance (alcance, objetivo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permisos (grupo Marketing)
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'promociones.ver' c,'promociones' m,'Marketing' g,'Promociones — Ver' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='promociones.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'promociones.crear' c,'promociones' m,'Marketing' g,'Promociones — Crear' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='promociones.crear');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'promociones.editar' c,'promociones' m,'Marketing' g,'Promociones — Editar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='promociones.editar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'promociones.eliminar' c,'promociones' m,'Marketing' g,'Promociones — Eliminar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='promociones.eliminar');

-- Se conceden a los roles que ya ven reportes (Super/Admin/Gerente).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'reportes.ver'
  JOIN permisos p  ON p.clave IN ('promociones.ver','promociones.crear','promociones.editar','promociones.eliminar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('promociones.ver','promociones.crear','promociones.editar','promociones.eliminar')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

SELECT 'tabla promociones' item, COUNT(*) ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='promociones'
UNION ALL SELECT 'permisos promociones', COUNT(*) FROM permisos WHERE clave LIKE 'promociones.%';

-- REVERSIÓN:
--   DROP TABLE IF EXISTS promociones;
--   DELETE FROM permisos WHERE clave LIKE 'promociones.%';
