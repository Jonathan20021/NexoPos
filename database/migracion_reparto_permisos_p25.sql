-- ============================================================================
--  NexoPOS · P25 — El reparto de permisos, en el repositorio
-- ----------------------------------------------------------------------------
--  El 2026-08-19 se repartieron trece permisos que no tenía ningún rol. Pero se
--  hizo con un script suelto contra producción, así que **el reparto no vivía
--  en ninguna parte**: cualquier entorno nuevo —o una restauración desde el
--  repo— volvía a nacer con esos trece huérfanos.
--
--  Lo destapó `probar_permisos.php`: pasaba en producción y fallaba en local.
--
--  ---------------------------------------------------------------------------
--  LOS DOS CRITERIOS DEL REPARTO
--
--  1. SEGREGACIÓN DE FUNCIONES. Quien captura no aprueba. Almacén cuenta y
--     prepara la liquidación, pero aplicar el ajuste al stock o dar entrada a
--     la mercancía —que fija costos— lo hace el gerente.
--
--  2. LO IRREVERSIBLE, ARRIBA. Anular una liquidación, dar de baja un activo,
--     correr la depreciación, tocar credenciales fiscales o la tasa de cambio
--     son cosas de las que no se vuelve con un botón: solo Administrador.
--
--  La excepción deliberada es `sanidad.bloquear`: retirar un lote de la venta
--  es urgente y REVERSIBLE —existe «liberar»—, así que quien está en el almacén
--  tiene que poder hacerlo sin buscar a nadie. Darlo de BAJA, que destruye
--  valor de inventario, ya no.
--
--  Idempotente: en producción, donde ya se aplicó a mano, no cambia nada.
-- ============================================================================

SET NAMES utf8mb4;

-- Solo para Administrador: irreversible o de alcance contable.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
  ON p.clave IN ('activos.baja', 'activos.depreciar', 'direccion.importar',
                 'ecf.configurar', 'liquidaciones.anular', 'monedas.gestionar')
 WHERE r.nombre = 'Administrador' AND r.activo = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- Administrador y Gerente: operación del encargado sobre lo que ya puede ver,
-- y cierre de procesos que capturó otro.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
  ON p.clave IN ('ecf.emitir', 'cotizaciones.eliminar', 'sanidad.baja',
                 'conteos.aplicar', 'conteos.cancelar', 'liquidaciones.aplicar')
 WHERE r.nombre IN ('Administrador', 'Gerente de Sucursal') AND r.activo = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- Y hasta el almacén: urgencia sanitaria, y se puede deshacer.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave = 'sanidad.bloquear'
 WHERE r.nombre IN ('Administrador', 'Gerente de Sucursal', 'Almacén / Inventario') AND r.activo = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- ============================================================================
--  Comprobación — no debe devolver ninguna fila:
--
--    SELECT p.clave FROM permisos p
--     WHERE NOT EXISTS (SELECT 1 FROM rol_permisos rp
--                        JOIN roles r ON r.id = rp.rol_id AND r.activo = 1 AND r.es_super = 0
--                       WHERE rp.permiso_id = p.id);
--
--  O, mejor:  php database/ecf_ejemplos/probar_permisos.php
-- ============================================================================
