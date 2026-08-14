-- ============================================================================
--  NexoPOS · P21 — Las secuencias que faltaban: E44 y E45
-- ----------------------------------------------------------------------------
--  P15 sembró cuatro secuencias e-CF: E31, E32, E33 y E34. Después se añadió el
--  soporte de dos tipos más —E44 (regímenes especiales) y E45 (gubernamental)—:
--  el generador de trama los produce, `ecfLayout()` tiene su plantilla, P17 los
--  metió en el ENUM de `ventas.tipo_comprobante` y se verificaron byte a byte
--  contra los ejemplos oficiales de la DGII.
--
--  Pero nadie les creó su fila en `ncf_secuencias`.
--
--  ---------------------------------------------------------------------------
--  QUÉ SIGNIFICABA ESO
--
--  Que no se podían emitir. `ncfComprobantesDisponibles()` solo ofrece
--  gubernamental y régimen especial si su secuencia está viva, y sin fila no hay
--  secuencia. El punto de venta hacía lo correcto —caer a consumidor final en
--  vez de romper la venta— así que el fallo NO daba error: la venta salía como
--  E32 y nadie se enteraba de que había pedido otra cosa.
--
--  Lo destapó la prueba contra el ambiente de LUGANIS: se pedía gubernamental y
--  volvía un e-NCF que empezaba por E32.
--
--  ---------------------------------------------------------------------------
--  NACEN APAGADAS, COMO LAS OTRAS CUATRO
--
--  Mismo criterio que P15: rango 0 y `activo = 0`. Los rangos los autoriza la
--  DGII por tipo y se cargan a mano en Configuración → Comprobantes. Nacer
--  activas con un rango inventado sería la forma más rápida de emitir una
--  secuencia no autorizada.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

-- El juego de caracteres se declara aquí: sin esto, `mysql < archivo` lo
-- interpreta como latin1 y las tildes entran mal codificadas. Pasó al aplicarla
-- la primera vez en local.
SET NAMES utf8mb4;

INSERT INTO ncf_secuencias (tipo, descripcion, prefijo, secuencia_actual, secuencia_hasta, vencimiento, activo)
SELECT * FROM (
  SELECT 'E44' AS tipo, 'Comprobante de Regímenes Especiales Electrónico' AS descripcion, 'E' AS prefijo,
         1 AS secuencia_actual, 0 AS secuencia_hasta, NULL AS vencimiento, 0 AS activo
  UNION ALL SELECT 'E45', 'Comprobante Gubernamental Electrónico', 'E', 1, 0, NULL, 0
) AS nuevas
WHERE NOT EXISTS (SELECT 1 FROM ncf_secuencias s WHERE s.tipo = nuevas.tipo);

-- Repara la descripción si una pasada anterior la guardó mal codificada.
UPDATE ncf_secuencias SET descripcion = 'Comprobante de Regímenes Especiales Electrónico'
 WHERE tipo = 'E44' AND descripcion <> 'Comprobante de Regímenes Especiales Electrónico';
UPDATE ncf_secuencias SET descripcion = 'Comprobante Gubernamental Electrónico'
 WHERE tipo = 'E45' AND descripcion <> 'Comprobante Gubernamental Electrónico';

-- ============================================================================
--  Comprobación:
--    SELECT tipo, descripcion, activo FROM ncf_secuencias WHERE tipo LIKE 'E%' ORDER BY tipo;
--    -- deben salir seis: E31, E32, E33, E34, E44 y E45
-- ============================================================================
