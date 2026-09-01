-- ---------------------------------------------------------------------------
-- Permiso propio para el comparativo de sucursales.
--
-- Estaba dentro de `reportes.ejecutivo`, el paquete de dirección: para que una
-- encargada pudiera comparar la venta de los locales había que darle también el
-- panel ejecutivo, el comparativo de periodos y el ranking de clientes, es
-- decir, la utilidad y la concentración de toda la empresa.
--
-- La fila TIENE que existir en `permisos` aunque la pantalla de Roles dibuje las
-- casillas desde `permission_catalog()`: al guardar se busca el permiso en esta
-- tabla, y sin la fila la concesión se pierde en silencio. Ya pasó con e-CF.
--
-- Idempotente: se puede correr las veces que haga falta.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('reportes.sucursales', 'reportes', 'Reportes',
  'Centro de Reportes — Comparativo de sucursales (sin abrir el resto de dirección)');

-- Quien ya tenía el paquete de dirección sigue entrando por su camino de
-- siempre; el informe acepta cualquiera de los dos permisos.
