-- ============================================================
--  Migración P8 — Índice de cobertura para las líneas de venta
--  Aplicar UNA vez sobre instalaciones existentes.
--  Idempotente: se puede correr de nuevo sin romper nada.
--  En instalaciones nuevas NO hace falta: ya viene en schema.sql.
-- ============================================================
--
--  No cambia ningún dato: solo añade un índice.
--
--  El problema: todo reporte que pregunta «¿qué se vendió en este período?»
--  entra por `ventas` filtrando por fecha y salta a `venta_detalles` por
--  `venta_id`. El índice `idx_vd_venta` solo tiene `venta_id`, así que por cada
--  línea hay que ir además a buscar la fila completa para leer producto_id,
--  cantidad, subtotal y descuento. Con un mes de 10.800 ventas son ~32.000
--  saltos aleatorios a disco.
--
--  Medido con 60.000 ventas y 180.000 líneas, mes completo:
--    top de productos del dashboard ..... 3.039 ms  ->  318 ms
--    página completa del dashboard ...... 4.600 ms  ->  550 ms
--
--  Al llevar las cuatro columnas dentro del índice, la unión se resuelve sin
--  tocar la tabla («Using index»). Cuesta ~30 bytes por línea de venta.
--  Beneficia igual a Reportes → Productos, Rentabilidad y el panel ejecutivo.
-- ============================================================

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalles'
                AND INDEX_NAME = 'idx_vd_venta_cobertura') > 0,
             'DO 0',
             'CREATE INDEX idx_vd_venta_cobertura ON venta_detalles (venta_id, producto_id, cantidad, subtotal, descuento)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Las estadísticas del optimizador quedan viejas tras crear un índice sobre una
-- tabla grande; sin esto puede seguir eligiendo el plan anterior un buen rato.
ANALYZE TABLE venta_detalles;

-- ---------- Verificación ----------
SELECT 'idx_vd_venta_cobertura' AS indice,
       IF(COUNT(*) = 5, 'OK', CONCAT('FALTA (', COUNT(*), ' de 5 columnas)')) AS estado
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'idx_vd_venta_cobertura';
