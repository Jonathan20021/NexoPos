-- ============================================================
--  Migración P7 — Índices de rendimiento
--  Aplicar UNA vez sobre instalaciones existentes.
--  Idempotente: se puede correr de nuevo sin romper nada.
--  En instalaciones nuevas NO hace falta: ya viene en schema.sql.
-- ============================================================
--
--  No cambia ningún dato: solo añade índices. Medido con 60.000 ventas,
--  180.000 líneas de detalle y 180.000 movimientos de kardex — el volumen de
--  un par de años con varias sucursales operando.
--
--  El kardex se consulta constantemente por producto Y sucursal a la vez
--  (verificación de integridad, valorización de inventario, conteos), pero solo
--  existían índices por cada columna suelta. Con el compuesto, la búsqueda pasa
--  de recorrer 9.014 filas a 6.007 y habilita el plan agrupado.
-- ============================================================

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_inventario'
                AND INDEX_NAME = 'idx_mov_prod_suc') > 0,
             'DO 0',
             'CREATE INDEX idx_mov_prod_suc ON movimientos_inventario (producto_id, sucursal_id)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Las líneas de venta se agrupan por producto en casi todos los reportes
-- (rentabilidad, desempeño de productos, panel ejecutivo).
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalles'
                AND INDEX_NAME = 'idx_vd_producto_venta') > 0,
             'DO 0',
             'CREATE INDEX idx_vd_producto_venta ON venta_detalles (producto_id, venta_id)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Los listados y reportes filtran ventas por estado y fecha a la vez.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
                AND INDEX_NAME = 'idx_v_estado_fecha') > 0,
             'DO 0',
             'CREATE INDEX idx_v_estado_fecha ON ventas (estado, fecha)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- El libro de caja y el flujo de efectivo recorren transacciones por tipo y fecha.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacciones'
                AND INDEX_NAME = 'idx_tr_tipo_fecha') > 0,
             'DO 0',
             'CREATE INDEX idx_tr_tipo_fecha ON transacciones (tipo, fecha)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- Verificación ----------
SELECT 'idx_mov_prod_suc'     AS indice, IF(COUNT(*)>0,'OK','FALTA') AS estado FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='idx_mov_prod_suc'
UNION ALL SELECT 'idx_vd_producto_venta', IF(COUNT(*)>0,'OK','FALTA') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='idx_vd_producto_venta'
UNION ALL SELECT 'idx_v_estado_fecha',    IF(COUNT(*)>0,'OK','FALTA') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='idx_v_estado_fecha'
UNION ALL SELECT 'idx_tr_tipo_fecha',     IF(COUNT(*)>0,'OK','FALTA') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='idx_tr_tipo_fecha';
