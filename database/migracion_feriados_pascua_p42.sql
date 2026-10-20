-- ---------------------------------------------------------------------------
-- P42 · Viernes Santo y Corpus Christi en su día
--
-- jorFeriadosRD() calculaba la Pascua con easter_date(), que devuelve la
-- medianoche en la zona del ENTORNO (TZ, casi siempre UTC en el servidor). Al
-- formatearla en hora de Santo Domingo caía el sábado, así que Viernes Santo
-- se guardaba en jueves y Corpus Christi en miércoles: un día antes.
-- El código ya está corregido (includes/jornadas.php); esto arregla lo que ya
-- se hubiera sembrado con el error.
--
-- Solo mueve un feriado si:
--   · es automático (los puestos a mano no se tocan nunca), y
--   · está EXACTAMENTE un día antes de su fecha correcta, que es la huella del
--     error. Cualquier otra fecha la puso alguien a propósito.
-- UPDATE IGNORE: si en la fecha correcta ya hay otro feriado (UNIQUE por
-- fecha), esa fila se salta en vez de romper la importación.
--
-- La asistencia ya calculada de esos días NO se recalcula aquí: si hace falta,
-- se recalcula el periodo desde RR.HH. → Asistencia.
--
-- Idempotente: la segunda vez no encuentra nada que mover. Si la tabla
-- feriados no existe (falta la P38), no hace nada.
-- ---------------------------------------------------------------------------

DROP TEMPORARY TABLE IF EXISTS tmp_pascua;
CREATE TEMPORARY TABLE tmp_pascua (nombre VARCHAR(80) NOT NULL, mal DATE NOT NULL, ok DATE NOT NULL);
INSERT INTO tmp_pascua (nombre, mal, ok) VALUES
  ('Viernes Santo', '2024-03-28', '2024-03-29'),
  ('Corpus Christi', '2024-05-29', '2024-05-30'),
  ('Viernes Santo', '2025-04-17', '2025-04-18'),
  ('Corpus Christi', '2025-06-18', '2025-06-19'),
  ('Viernes Santo', '2026-04-02', '2026-04-03'),
  ('Corpus Christi', '2026-06-03', '2026-06-04'),
  ('Viernes Santo', '2027-03-25', '2027-03-26'),
  ('Corpus Christi', '2027-05-26', '2027-05-27'),
  ('Viernes Santo', '2028-04-13', '2028-04-14'),
  ('Corpus Christi', '2028-06-14', '2028-06-15'),
  ('Viernes Santo', '2029-03-29', '2029-03-30'),
  ('Corpus Christi', '2029-05-30', '2029-05-31'),
  ('Viernes Santo', '2030-04-18', '2030-04-19'),
  ('Corpus Christi', '2030-06-19', '2030-06-20'),
  ('Viernes Santo', '2031-04-10', '2031-04-11'),
  ('Corpus Christi', '2031-06-11', '2031-06-12'),
  ('Viernes Santo', '2032-03-25', '2032-03-26'),
  ('Corpus Christi', '2032-05-26', '2032-05-27'),
  ('Viernes Santo', '2033-04-14', '2033-04-15'),
  ('Corpus Christi', '2033-06-15', '2033-06-16'),
  ('Viernes Santo', '2034-04-06', '2034-04-07'),
  ('Corpus Christi', '2034-06-07', '2034-06-08'),
  ('Viernes Santo', '2035-03-22', '2035-03-23'),
  ('Corpus Christi', '2035-05-23', '2035-05-24');

SET @hay := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feriados');
SET @s := IF(@hay = 1,
  'UPDATE IGNORE feriados f JOIN tmp_pascua t ON t.nombre = f.nombre AND t.mal = f.fecha SET f.fecha = t.ok WHERE f.automatico = 1',
  'SELECT 0');
-- ROW_COUNT() se lee justo después de EXECUTE: DEALLOCATE lo pone a cero.
PREPARE st FROM @s; EXECUTE st; SET @movidos := IF(@hay = 1, ROW_COUNT(), 0); DEALLOCATE PREPARE st;

SELECT 'Feriados de Semana Santa corregidos' AS item, @movidos AS movidos,
       IF(@hay = 1, 'OK', 'sin tabla feriados (P38): nada que corregir') AS estado;

DROP TEMPORARY TABLE IF EXISTS tmp_pascua;
