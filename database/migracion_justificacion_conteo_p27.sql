-- ---------------------------------------------------------------------------
-- Justificación obligatoria cuando un conteo BAJA el inventario.
--
-- La dirección lo pidió con estas palabras: «lo que no pueden es que saquen una
-- mercancía sin un permiso, sin una nota, de por qué sacaron un producto; si
-- habían veinte, ahora hay quince».
--
-- El ajuste a mano ya exigía motivo. El conteo físico no: aplicaba las
-- diferencias y dejaba en el kardex el número del documento («Conteo
-- CNT-000012»), que dice de dónde salió la corrección pero no qué pasó con la
-- mercancía. Esta columna guarda esa explicación en el propio conteo, y el
-- código la copia además al motivo de cada movimiento para que salga en el
-- informe de ajustes y mermas.
--
-- Solo se exige cuando hay faltantes. Un conteo que únicamente encuentra
-- mercancía de más no es el riesgo que se quería cubrir.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'conteos'
     AND COLUMN_NAME  = 'justificacion'
);

SET @sql := IF(@existe = 0,
  'ALTER TABLE conteos ADD COLUMN justificacion VARCHAR(300) NULL AFTER notas',
  'DO 0');

PREPARE st FROM @sql;
EXECUTE st;
DEALLOCATE PREPARE st;
