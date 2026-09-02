-- ---------------------------------------------------------------------------
-- Permiso propio para el control de vencimientos.
--
-- Vivía dentro de `reportes.sanidad`, que también abre el expediente de
-- auditoría, los registros sanitarios, la trazabilidad de lote y las fichas de
-- los proveedores. Quien vigila que no se venza la mercancía no necesita nada
-- de eso.
--
-- Se concede al rol que lleva el inventario de las tiendas.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('reportes.vencimientos', 'reportes', 'Reportes',
  'Centro de Reportes — Control de vencimientos (sin abrir el resto de sanidad)');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'reportes.vencimientos'
 WHERE r.nombre = 'Encargada de Facturación Hotel';
