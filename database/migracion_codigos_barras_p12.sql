-- ============================================================================
--  NexoPOS · P12 — Códigos de barras y escaneo con teléfono
-- ----------------------------------------------------------------------------
--  · `productos.codigo_barras` pasa a ser ÚNICO. Es la pieza que hace confiable
--    todo lo demás: si dos productos comparten código, el escáner no puede saber
--    cuál te dieron y el POS cobraría el equivocado. MySQL permite tantos NULL
--    como haga falta en un índice único, así que los productos sin código
--    (servicios, mercancía a granel) siguen conviviendo sin problema.
--  · Se añade el permiso para imprimir etiquetas.
--
--  Es idempotente: se puede correr dos veces sin romper nada. Válida tanto en
--  MariaDB 10.4 (desarrollo) como en MySQL 8.0 (producción), por eso los índices
--  se crean con SQL dinámico en vez de `IF NOT EXISTS` (que MySQL 8 no soporta).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Cadena vacía y NULL significan lo mismo («este producto no tiene código»),
--    pero para un índice único NO son lo mismo: '' choca con '' y NULL no.
--    Se unifican en NULL antes de crear el índice.
-- ---------------------------------------------------------------------------
UPDATE productos SET codigo_barras = NULL WHERE codigo_barras = '';

-- ---------------------------------------------------------------------------
-- 2. Índice único. Antes se retira el índice normal que ya existía
--    (`idx_p_barras`), porque el único ya sirve para buscar.
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                   AND INDEX_NAME = 'idx_p_barras');
SET @sql := IF(@existe > 0, 'ALTER TABLE productos DROP INDEX idx_p_barras', 'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                   AND INDEX_NAME = 'uq_producto_barras');
SET @sql := IF(@existe = 0,
    'ALTER TABLE productos ADD UNIQUE KEY uq_producto_barras (codigo_barras)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3. Permiso nuevo: imprimir etiquetas con código de barras.
--    Se concede a los roles que ya administran el catálogo (los que pueden
--    editar productos). Los roles `es_super` no lo necesitan: `can()` les
--    devuelve true sin consultar la tabla.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion)
VALUES ('productos.etiquetas', 'productos', 'Inventario',
        'Productos — Imprimir etiquetas con código de barras');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, (SELECT id FROM permisos WHERE clave = 'productos.etiquetas')
  FROM rol_permisos rp
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE p.clave = 'productos.editar';

-- ---------------------------------------------------------------------------
-- 4. Contador de los códigos internos (prefijo 200, circulación restringida).
--    Se siembra en el mayor código interno que ya exista para no repetir
--    ninguno si la base ya traía productos etiquetados a mano.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'productos.codigo_barras.EAN',
       COALESCE(MAX(CAST(SUBSTRING(codigo_barras, 4, 9) AS UNSIGNED)), 0)
  FROM productos
 WHERE codigo_barras REGEXP '^200[0-9]{10}$';
