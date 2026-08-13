-- ============================================================================
--  NexoPOS · P18 — Los conceptos que la nómina de verdad necesita
-- ----------------------------------------------------------------------------
--  El módulo solo sabía hacer una cosa: sueldo ÷ período, menos AFP, SFS e ISR.
--  Seis columnas de `nomina_detalles` existían y se guardaban SIEMPRE EN CERO
--  porque la pantalla no tenía dónde escribirlas.
--
--  Para Importers eso no da: su hoja de nómina maneja préstamos al empleado,
--  prima vacacional, feriados, incentivos y reembolsos. Con el módulo como
--  estaba, un préstamo no se podía descontar y una comisión no se podía pagar.
--
--  Aquí se añaden los conceptos que faltaban. El mapeo con las columnas del
--  Excel del cliente («Segunda quincena Julio 2026 definitivo.xlsx», hoja
--  «2do nomina de Julio», encabezados en la fila 2) es este:
--
--    E  Sueldo Quincenal ............. salario_base      (prorrateado por días)
--    F  Días pagados .................. dias_trabajados / dias_base
--    G  Prima Vacacional .............. prima_vacacional        ← nueva
--    H  Total feriado/horas ext. ...... monto_horas_extra
--    I  Otras Remuneraciones .......... otros_ingresos + comisiones
--    J  Reembolso al empleado ......... reembolso               ← nueva
--    K  Vacaciones diferencial ........ vacaciones_diferencial  ← nueva
--    L  Incentivos .................... bonificaciones
--    M  Descuentos de días ............ descuento_dias          ← nueva
--    N  Ingresos cotizable seg. social  total_ingresos
--    O  AFP 2.87% ..................... afp
--    P  SFS 3.04% ..................... sfs
--    Q  Per-Cápita Quincenal .......... per_capita              ← nueva
--    R  ISR Quincenal ................. isr
--    S  Total Retenciones ............. total_deducciones
--    T  Cuentas por cobrar empleados .. otras_deducciones
--    U  Total neto a depositar ........ salario_neto
--
--  `dias_base` guarda cuántos días son una jornada completa del período —11.91
--  en la quincena de Importers, que es su convenio de días laborables pagados—.
--  Se guarda POR LÍNEA y no como constante porque un convenio puede cambiar, y
--  una nómina ya cerrada tiene que poder recalcularse igual dentro de dos años.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

DROP PROCEDURE IF EXISTS nexo_add_col;
DELIMITER //
CREATE PROCEDURE nexo_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN def TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t)
     AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- ---------------------------------------------------------------------------
--  Ingresos
-- ---------------------------------------------------------------------------

-- Se paga en el neto pero NO entra en la base de cotización, igual que en la
-- hoja del contador. PENDIENTE de confirmar con él si debería cotizar.
CALL nexo_add_col('nomina_detalles', 'prima_vacacional',
  "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Col. G — se paga, no cotiza (confirmar con el contador)'");

CALL nexo_add_col('nomina_detalles', 'reembolso',
  "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Col. J — reembolso de gastos al empleado'");

CALL nexo_add_col('nomina_detalles', 'vacaciones_diferencial',
  "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Col. K'");

-- ---------------------------------------------------------------------------
--  Descuentos
-- ---------------------------------------------------------------------------

-- Baja la base cotizable (ausencias). Distinto de `otras_deducciones`, que es
-- el préstamo y NO toca la base: solo el neto.
CALL nexo_add_col('nomina_detalles', 'descuento_dias',
  "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Col. M — resta de la base cotizable'");

CALL nexo_add_col('nomina_detalles', 'per_capita',
  "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Col. Q — per-cápita del plan de salud'");

-- ---------------------------------------------------------------------------
--  Prorrateo
-- ---------------------------------------------------------------------------

-- Días que son una jornada completa del período. El Excel usa 11.91 en la
-- quincena: `=(Sueldo/2/11.91)*DíasPagados`. Con días = base, el resultado es
-- exactamente medio sueldo, así que una quincena normal cuadra al centavo.
CALL nexo_add_col('nomina_detalles', 'dias_base',
  "DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'Días de una jornada completa del período'");

DROP PROCEDURE IF EXISTS nexo_add_col;

-- ============================================================================
--  Comprobación:
--    SHOW COLUMNS FROM nomina_detalles;
-- ============================================================================
