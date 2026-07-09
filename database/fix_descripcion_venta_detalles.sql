-- ============================================================================
--  Corrige las líneas de venta/devolución con `descripcion` vacía.
--
--  Causa: el instalador (install/index.php) guardaba venta_detalles.descripcion
--  como cadena vacía. Esa columna es la que muestra el nombre del producto en
--  el ticket, en el detalle de la venta y en el formulario de devoluciones.
--
--  Es idempotente: se puede ejecutar varias veces sin efecto adicional.
--  Solo toca filas con descripcion vacía o NULL, y nunca borra nada.
-- ============================================================================

-- 1) Diagnóstico ANTES (ejecuta esto primero y anota los números)
SELECT 'venta_detalles vacias' AS chequeo, COUNT(*) AS filas
  FROM venta_detalles WHERE descripcion IS NULL OR descripcion = ''
UNION ALL
SELECT 'devolucion_detalles vacias', COUNT(*)
  FROM devolucion_detalles WHERE descripcion IS NULL OR descripcion = ''
UNION ALL
SELECT 'venta_detalles huerfanas (sin producto)', COUNT(*)
  FROM venta_detalles vd LEFT JOIN productos p ON p.id = vd.producto_id
  WHERE (vd.descripcion IS NULL OR vd.descripcion = '') AND p.id IS NULL
UNION ALL
SELECT 'ventas SIN ninguna linea de detalle', COUNT(*)
  FROM ventas v WHERE NOT EXISTS (SELECT 1 FROM venta_detalles WHERE venta_id = v.id);

-- 2) Backfill: toma el nombre actual del producto.
UPDATE venta_detalles vd
  JOIN productos p ON p.id = vd.producto_id
   SET vd.descripcion = p.nombre
 WHERE vd.descripcion IS NULL OR vd.descripcion = '';

UPDATE devolucion_detalles dd
  JOIN productos p ON p.id = dd.producto_id
   SET dd.descripcion = p.nombre
 WHERE dd.descripcion IS NULL OR dd.descripcion = '';

-- 3) Red de seguridad: líneas cuyo producto ya no existe (producto_id NULL o
--    borrado). Se marcan de forma legible en vez de dejarlas en blanco.
UPDATE venta_detalles vd
  LEFT JOIN productos p ON p.id = vd.producto_id
   SET vd.descripcion = '(producto no disponible)'
 WHERE (vd.descripcion IS NULL OR vd.descripcion = '') AND p.id IS NULL;

UPDATE devolucion_detalles dd
  LEFT JOIN productos p ON p.id = dd.producto_id
   SET dd.descripcion = '(producto no disponible)'
 WHERE (dd.descripcion IS NULL OR dd.descripcion = '') AND p.id IS NULL;

-- 4) Verificación DESPUÉS: ambas filas deben devolver 0.
SELECT 'venta_detalles vacias (debe ser 0)' AS chequeo, COUNT(*) AS filas
  FROM venta_detalles WHERE descripcion IS NULL OR descripcion = ''
UNION ALL
SELECT 'devolucion_detalles vacias (debe ser 0)', COUNT(*)
  FROM devolucion_detalles WHERE descripcion IS NULL OR descripcion = '';
