-- ============================================================================
--  Migración P1 — Metas de venta / KPI (permisos)
--
--  La tabla metas_ventas ya se creó en migracion_actualizaciones_cliente_p0.sql.
--  Aquí solo se agregan los permisos del módulo. Idempotente.
-- ============================================================================

INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'metas.ver' AS c, 'metas' AS m, 'Finanzas' AS g, 'Metas de venta — Ver' AS d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'metas.ver');

INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'metas.gestionar' AS c, 'metas' AS m, 'Finanzas' AS g, 'Metas de venta — Crear/editar' AS d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'metas.gestionar');

-- Se conceden a los roles que ya ven reportes financieros (Super/Admin/Gerente).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'reportes.ver'
  JOIN permisos p  ON p.clave IN ('metas.ver', 'metas.gestionar')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

-- El Super Administrador los tiene automáticamente (is_super), pero se aseguran
-- también de forma explícita por si un rol super no tuviera reportes.ver.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('metas.ver', 'metas.gestionar')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

SELECT 'permisos de metas' AS chequeo, COUNT(*) AS filas FROM permisos WHERE clave LIKE 'metas.%'
UNION ALL SELECT 'roles con metas.ver', COUNT(*) FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.clave='metas.ver';

-- REVERSIÓN:  DELETE FROM permisos WHERE clave LIKE 'metas.%';
