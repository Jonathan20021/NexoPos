-- ============================================================================
--  Migración P1 — Transferencias con flujo de aprobación
--
--  Antes: al crear se enviaba de una (descontaba stock del origen al instante).
--  Ahora se admite además:
--    * borrador  — no mueve stock; se edita y se elimina; luego se envía.
--    * rechazada — el destino puede rechazar una enviada; el stock vuelve al origen.
--
--  El flujo viejo (crear-y-enviar directo) sigue disponible: es solo una opción.
--
--  Idempotente. Reversión al final.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Ampliar el ENUM de estado (solo si aún no incluye 'borrador').
-- ---------------------------------------------------------------------------
SET @tieneBorrador := (SELECT LOCATE('borrador', COLUMN_TYPE) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='estado');
SET @s := IF(@tieneBorrador=0,
  'ALTER TABLE transferencias MODIFY COLUMN estado ENUM(''borrador'',''pendiente'',''enviada'',''recibida'',''rechazada'',''anulada'') NOT NULL DEFAULT ''borrador''',
  'SELECT ''ENUM estado ya ampliado''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 2) Trazabilidad: quién envió / recibió / rechazó y cuándo.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='enviada_por');
SET @s := IF(@c=0, 'ALTER TABLE transferencias ADD COLUMN enviada_por INT UNSIGNED NULL, ADD COLUMN enviada_at DATETIME NULL', 'SELECT ''enviada_por ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='recibida_por');
SET @s := IF(@c=0, 'ALTER TABLE transferencias ADD COLUMN recibida_por INT UNSIGNED NULL, ADD COLUMN recibida_at DATETIME NULL', 'SELECT ''recibida_por ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='motivo_rechazo');
SET @s := IF(@c=0, 'ALTER TABLE transferencias ADD COLUMN motivo_rechazo VARCHAR(255) NULL', 'SELECT ''motivo_rechazo ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- 3) Permisos nuevos: enviar (sacar de borrador) y rechazar.
--    'crear' pasa a significar "crear/editar el borrador"; 'enviar' es el que
--    realmente descuenta stock, para poder separar quién arma de quién despacha.
-- ---------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'transferencias.enviar' c,'transferencias' m,'Inventario' g,'Transferencias — Enviar (descuenta stock)' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='transferencias.enviar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'transferencias.rechazar' c,'transferencias' m,'Inventario' g,'Transferencias — Rechazar' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='transferencias.rechazar');

-- Quien ya podía crear transferencias, ahora también puede enviar; quien podía
-- recibir, ahora también puede rechazar (son las dos caras del mismo rol).
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'transferencias.crear'
  JOIN permisos p  ON p.clave = 'transferencias.enviar'
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'transferencias.recibir'
  JOIN permisos p  ON p.clave = 'transferencias.rechazar'
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

-- Aseguramiento explícito para roles super.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('transferencias.enviar','transferencias.rechazar')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

-- Verificación
SELECT 'ENUM con borrador' item, LOCATE('borrador',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='estado'))>0 ok
UNION ALL SELECT 'ENUM con rechazada', LOCATE('rechazada',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='estado'))>0
UNION ALL SELECT 'col motivo_rechazo', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='motivo_rechazo'
UNION ALL SELECT 'permisos enviar/rechazar', COUNT(*) FROM permisos WHERE clave IN ('transferencias.enviar','transferencias.rechazar');

-- REVERSIÓN:
--   ALTER TABLE transferencias DROP COLUMN enviada_por, DROP COLUMN enviada_at,
--     DROP COLUMN recibida_por, DROP COLUMN recibida_at, DROP COLUMN motivo_rechazo,
--     MODIFY COLUMN estado ENUM('pendiente','enviada','recibida','anulada') NOT NULL DEFAULT 'pendiente';
--   DELETE FROM permisos WHERE clave IN ('transferencias.enviar','transferencias.rechazar');
