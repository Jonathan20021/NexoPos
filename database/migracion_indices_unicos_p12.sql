-- ============================================================================
--  NexoPOS · P12 — Índices únicos que faltaban en las bases antiguas
-- ----------------------------------------------------------------------------
--  `database/schema.sql` declara estos dos índices, así que toda instalación
--  nueva los tiene. Las bases creadas con un schema anterior NO, y la diferencia
--  no es cosmética:
--
--  · `categorias_financieras (tipo, nombre)` — `categoriaFinancieraId()`
--    (includes/operaciones.php) resuelve la categoría con
--    `INSERT ... ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)`. Ese idiom
--    DEPENDE del índice único: sin él no hay clave duplicada que disparar, así
--    que cada venta, cada compra y cada cobro insertan una categoría nueva. Se
--    detectó una base de producción con «Ventas» repetida cinco veces y «Compra
--    de Mercancía» seis: 32 filas donde debían existir 14. Los reportes siguen
--    cuadrando (suman por tipo), pero los desgloses por categoría salen partidos
--    en pedazos y el desplegable de Finanzas se llena de opciones repetidas.
--
--  · `metodos_pago (nombre)` — impide dos formas de pago con el mismo nombre,
--    que en la caja son indistinguibles.
--
--  ANTES DE CORRER ESTO hay que dejar la tabla sin duplicados; si los hubiera,
--  `ALTER TABLE` falla y no toca nada. El bloque 1 los fusiona conservando el
--  id más bajo de cada (tipo, nombre) y reapuntando las transacciones que
--  colgaban de las copias, para no dejar movimientos huérfanos.
--
--  Es idempotente y vale igual en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Fusionar categorías financieras repetidas.
--    Primero se reapuntan las transacciones a la categoría que se conserva
--    (la de id más bajo) y solo después se borran las copias: al revés se
--    perdería la categoría de movimientos ya registrados.
-- ---------------------------------------------------------------------------
UPDATE transacciones t
  JOIN categorias_financieras dup ON dup.id = t.categoria_id
  JOIN (SELECT tipo, nombre, MIN(id) AS id_bueno
          FROM categorias_financieras
         GROUP BY tipo, nombre) k
    ON k.tipo = dup.tipo AND k.nombre = dup.nombre
   SET t.categoria_id = k.id_bueno
 WHERE t.categoria_id <> k.id_bueno;

DELETE cf FROM categorias_financieras cf
  JOIN categorias_financieras base
    ON base.tipo = cf.tipo AND base.nombre = cf.nombre AND base.id < cf.id;

-- ---------------------------------------------------------------------------
-- 2. Índice único de categorías financieras.
-- ---------------------------------------------------------------------------
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorias_financieras'
                   AND INDEX_NAME = 'uq_cat_fin_tipo_nombre');
SET @sql := IF(@existe = 0,
    'ALTER TABLE categorias_financieras ADD UNIQUE KEY uq_cat_fin_tipo_nombre (tipo, nombre)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3. Índice único de métodos de pago. Solo se crea si no hay nombres repetidos:
--    fusionarlos automáticamente cambiaría la forma de pago de ventas ya
--    cobradas, y eso lo tiene que decidir una persona, no una migración.
-- ---------------------------------------------------------------------------
SET @dups := (SELECT COUNT(*) FROM (
                SELECT nombre FROM metodos_pago GROUP BY nombre HAVING COUNT(*) > 1
              ) x);
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metodos_pago'
                   AND INDEX_NAME = 'uq_metodo_nombre');
SET @sql := IF(@existe = 0 AND @dups = 0,
    'ALTER TABLE metodos_pago ADD UNIQUE KEY uq_metodo_nombre (nombre)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
