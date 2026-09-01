-- ---------------------------------------------------------------------------
-- Control de salidas de mercancía y reportes de inventario
--
-- Pedido por la dirección: nadie saca mercancía de una tienda sin que alguien
-- lo apruebe y sin dejar escrito por qué. «Si había veinte y ahora hay quince,
-- que se sepa quién y por qué.»
--
-- 1) transferencias.aprobar
--    La transferencia deja de descontar stock al enviarla: pasa a «pendiente» y
--    el stock NO se mueve hasta que alguien con este permiso la aprueba. El
--    estado ya existía en el enum de `transferencias` desde el principio; nunca
--    se había usado.
--
--    Se concede a los roles de supervisión, NO a quien opera. Si lo tuviera
--    quien la solicita, aprobar la suya propia no sería una aprobación.
--    Los super administradores lo tienen por serlo.
--
-- 2) reportes.inventario
--    El inventario valorizado vivía dentro de `reportes.contabilidad`, que
--    también abre el libro diario, el balance, los impuestos y la nómina. Quien
--    revisa existencias no necesita nada de eso.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('transferencias.aprobar', 'transferencias', 'Inventario',
  'Aprobar la salida de mercancía de una transferencia'),
 ('reportes.inventario', 'reportes', 'Reportes',
  'Centro de Reportes — Inventario valorizado (sin abrir el resto de contabilidad)');

-- Quien supervisa aprueba. Los super administradores no necesitan la fila.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'transferencias.aprobar'
 WHERE r.es_super = 0
   AND r.nombre IN ('Administrador', 'Gerente de Sucursal');
