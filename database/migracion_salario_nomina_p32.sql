-- ---------------------------------------------------------------------------
-- El sueldo mensual queda congelado en la línea de nómina.
--
-- El Excel que se le entrega al contador tiene dos columnas: «Sueldo Mensual»
-- (D) y «Sueldo Quincenal» (E). La quincenal sale de la línea de nómina, que es
-- histórica; la mensual salía de `empleados.salario`, que es el sueldo de HOY.
--
-- Consecuencia: en cuanto alguien recibe un aumento, volver a exportar una
-- quincena YA CERRADA saca D con el sueldo nuevo y E con el viejo. Las dos
-- columnas dejan de guardar relación y el contador pregunta por qué. Una nómina
-- confirmada es un documento cerrado: reexportarla tiene que dar el mismo
-- archivo.
--
-- Las filas que ya existen se rellenan con el sueldo actual del padrón, que es
-- lo mejor disponible: para ellas el dato histórico se perdió cuando se cambió
-- el sueldo, si es que se cambió. De aquí en adelante se guarda al generar.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'nomina_detalles'
     AND COLUMN_NAME  = 'salario_mensual'
);

SET @sql := IF(@existe = 0,
  'ALTER TABLE nomina_detalles ADD COLUMN salario_mensual DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER salario_base',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Relleno de lo que ya estaba. Solo toca las filas en cero, así que repetirlo
-- no pisa un valor bueno.
UPDATE nomina_detalles nd
  JOIN empleados e ON e.id = nd.empleado_id
   SET nd.salario_mensual = e.salario
 WHERE nd.salario_mensual = 0;
