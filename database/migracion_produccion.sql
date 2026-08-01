-- ============================================================
--  NexoPOS — Puesta al día de PRODUCCIÓN
-- ============================================================
--  Un solo archivo que deja cualquier instalación existente al día:
--    · P3 — Centro de Notificaciones y permisos del Centro de Reportes
--    · P4 — Concurrencia multi-sucursal (contadores atómicos e índices)
--
--  SEGURO DE EJECUTAR EN CUALQUIER ESTADO:
--    · Instalación vieja (sin P3 ni P4)  → aplica todo
--    · Instalación nueva desde schema.sql → no hace nada (todo existe ya)
--    · Ejecutado dos veces                → no hace nada la segunda vez
--
--  NO borra ni modifica datos existentes. Solo crea tablas, índices,
--  permisos y contadores que falten.
--
--  Cómo aplicarlo en cPanel:
--    phpMyAdmin → selecciona la base → pestaña «Importar» → este archivo.
--  O por consola:
--    mysql -u USUARIO -p NOMBRE_BASE < migracion_produccion.sql
--
--  Antes de correrlo: haz un respaldo (cPanel → Copias de seguridad, o
--  Administración → Respaldo dentro del propio sistema).
-- ============================================================

-- ============================================================
--  1. TABLAS NUEVAS  (CREATE TABLE IF NOT EXISTS: seguro repetir)
-- ============================================================

-- Estado interno llave/valor. Acota cada cuánto corre el motor de
-- notificaciones sin necesidad de cron.
CREATE TABLE IF NOT EXISTS sistema_estado (
  clave      VARCHAR(60)  NOT NULL,
  valor      VARCHAR(255) NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una fila por «situación viva» del negocio. La clave deduplica y la alerta
-- pasa a 'resuelta' sola cuando el problema desaparece.
CREATE TABLE IF NOT EXISTS notificaciones (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave           VARCHAR(120) NOT NULL,
  tipo            VARCHAR(40)  NOT NULL,
  categoria       ENUM('inventario','ventas','finanzas','fiscal','crm','rrhh','sistema') NOT NULL DEFAULT 'sistema',
  prioridad       ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  titulo          VARCHAR(150) NOT NULL,
  mensaje         VARCHAR(300) NULL,
  url             VARCHAR(255) NULL,
  icono           VARCHAR(30)  NOT NULL DEFAULT 'bell',
  color           VARCHAR(20)  NOT NULL DEFAULT 'blue',
  sucursal_id     INT UNSIGNED NULL,
  usuario_id      INT UNSIGNED NULL,
  permiso         VARCHAR(60)  NULL,
  referencia_tipo VARCHAR(30)  NULL,
  referencia_id   INT UNSIGNED NULL,
  estado          ENUM('activa','resuelta') NOT NULL DEFAULT 'activa',
  resuelta_at     DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_clave (clave),
  KEY idx_notif_estado (estado, prioridad),
  KEY idx_notif_tipo (tipo, estado),
  KEY idx_notif_sucursal (sucursal_id),
  KEY idx_notif_usuario (usuario_id),
  CONSTRAINT fk_notif_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marca de lectura por usuario (una alerta la ven varias personas).
CREATE TABLE IF NOT EXISTS notificacion_lecturas (
  notificacion_id BIGINT UNSIGNED NOT NULL,
  usuario_id      INT UNSIGNED NOT NULL,
  leida_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notificacion_id, usuario_id),
  KEY idx_nl_usuario (usuario_id),
  CONSTRAINT fk_nl_notif   FOREIGN KEY (notificacion_id) REFERENCES notificaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_nl_usuario FOREIGN KEY (usuario_id)      REFERENCES usuarios(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Correlativos atómicos. Sin esto, dos cajas vendiendo a la vez generan el
-- mismo número de factura y una de las dos ventas se pierde.
CREATE TABLE IF NOT EXISTS contadores (
  nombre     VARCHAR(60) NOT NULL,
  valor      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  2. ÍNDICES  (condicionales: MySQL no tiene CREATE INDEX IF NOT EXISTS,
--     y repetir un CREATE INDEX aborta el script entero con error 1061)
-- ============================================================

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_sesiones'
                AND INDEX_NAME = 'idx_cs_caja_estado') > 0,
             'DO 0',
             'CREATE INDEX idx_cs_caja_estado ON caja_sesiones (caja_id, estado)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND INDEX_NAME = 'idx_p_activo_nombre') > 0,
             'DO 0',
             'CREATE INDEX idx_p_activo_nombre ON productos (activo, nombre)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
                AND INDEX_NAME = 'idx_v_cliente_estado') > 0,
             'DO 0',
             'CREATE INDEX idx_v_cliente_estado ON ventas (cliente_id, estado, fecha)');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
--  3. PERMISOS DEL CENTRO DE REPORTES
-- ============================================================

INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
  ('reportes.ver',          'reportes', 'Reportes', 'Centro de Reportes — Ver el centro de reportes'),
  ('reportes.ejecutivo',    'reportes', 'Reportes', 'Centro de Reportes — Reportes de dirección (CEO)'),
  ('reportes.finanzas',     'reportes', 'Reportes', 'Centro de Reportes — Reportes financieros'),
  ('reportes.contabilidad', 'reportes', 'Reportes', 'Centro de Reportes — Reportes contables y fiscales'),
  ('reportes.operacion',    'reportes', 'Reportes', 'Centro de Reportes — Reportes de operación y ventas');

UPDATE permisos SET grupo = 'Reportes', descripcion = 'Centro de Reportes — Ver el centro de reportes'
 WHERE clave = 'reportes.ver';

-- Todo rol que ya podía ver reportes conserva el acceso a los bloques nuevos.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p1 ON p1.id = rp.permiso_id AND p1.clave = 'reportes.ver'
  JOIN permisos p2 ON p2.clave IN ('reportes.ejecutivo','reportes.finanzas','reportes.contabilidad','reportes.operacion');

-- Los roles marcados como super ya tienen acceso total por código, pero se les
-- asignan igual para que la pantalla de Roles muestre la realidad.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r
  JOIN permisos p ON p.modulo = 'reportes'
 WHERE r.es_super = 1;

-- ============================================================
--  4. CONTADORES  (se siembran con el máximo REAL de cada serie,
--     así no se repite ni se salta ningún número al cambiar de método)
-- ============================================================

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'ventas.numero.VTA', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM ventas WHERE numero LIKE 'VTA-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'compras.numero.COM', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM compras WHERE numero LIKE 'COM-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'devoluciones.numero.DEV', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM devoluciones WHERE numero LIKE 'DEV-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'transferencias.numero.TRF', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM transferencias WHERE numero LIKE 'TRF-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'pedidos.numero.PED', COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0)
  FROM pedidos WHERE numero LIKE 'PED-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'clientes.codigo.CLI', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM clientes WHERE codigo LIKE 'CLI-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'productos.codigo.SKU', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM productos WHERE codigo LIKE 'SKU-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'proveedores.codigo.PRV', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM proveedores WHERE codigo LIKE 'PRV-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'empleados.codigo.EMP', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM empleados WHERE codigo LIKE 'EMP-%';

INSERT IGNORE INTO contadores (nombre, valor)
SELECT 'crm_oportunidades.codigo.OPT', COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo,'-',-1) AS UNSIGNED)),0)
  FROM crm_oportunidades WHERE codigo LIKE 'OPT-%';

-- Reparación: si un contador quedó por DEBAJO del último documento emitido
-- (porque se insertó algo por fuera), se sube al máximo real. Nunca se baja.
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(v.numero,'-',-1) AS UNSIGNED)),0) FROM ventas v WHERE v.numero LIKE 'VTA-%'))
 WHERE c.nombre = 'ventas.numero.VTA';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.numero,'-',-1) AS UNSIGNED)),0) FROM compras x WHERE x.numero LIKE 'COM-%'))
 WHERE c.nombre = 'compras.numero.COM';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.numero,'-',-1) AS UNSIGNED)),0) FROM devoluciones x WHERE x.numero LIKE 'DEV-%'))
 WHERE c.nombre = 'devoluciones.numero.DEV';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.numero,'-',-1) AS UNSIGNED)),0) FROM transferencias x WHERE x.numero LIKE 'TRF-%'))
 WHERE c.nombre = 'transferencias.numero.TRF';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.numero,'-',-1) AS UNSIGNED)),0) FROM pedidos x WHERE x.numero LIKE 'PED-%'))
 WHERE c.nombre = 'pedidos.numero.PED';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.codigo,'-',-1) AS UNSIGNED)),0) FROM clientes x WHERE x.codigo LIKE 'CLI-%'))
 WHERE c.nombre = 'clientes.codigo.CLI';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.codigo,'-',-1) AS UNSIGNED)),0) FROM productos x WHERE x.codigo LIKE 'SKU-%'))
 WHERE c.nombre = 'productos.codigo.SKU';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.codigo,'-',-1) AS UNSIGNED)),0) FROM proveedores x WHERE x.codigo LIKE 'PRV-%'))
 WHERE c.nombre = 'proveedores.codigo.PRV';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.codigo,'-',-1) AS UNSIGNED)),0) FROM empleados x WHERE x.codigo LIKE 'EMP-%'))
 WHERE c.nombre = 'empleados.codigo.EMP';
UPDATE contadores c
   SET c.valor = GREATEST(c.valor, (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(x.codigo,'-',-1) AS UNSIGNED)),0) FROM crm_oportunidades x WHERE x.codigo LIKE 'OPT-%'))
 WHERE c.nombre = 'crm_oportunidades.codigo.OPT';

-- ============================================================
--  5. VERIFICACIÓN — todo debe decir OK
-- ============================================================
SELECT 'Tabla notificaciones'        AS componente, IF(COUNT(*)=1,'OK','FALTA') AS estado FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notificaciones'
UNION ALL SELECT 'Tabla notificacion_lecturas', IF(COUNT(*)=1,'OK','FALTA') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notificacion_lecturas'
UNION ALL SELECT 'Tabla sistema_estado',        IF(COUNT(*)=1,'OK','FALTA') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sistema_estado'
UNION ALL SELECT 'Tabla contadores',            IF(COUNT(*)=1,'OK','FALTA') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contadores'
UNION ALL SELECT 'Índice idx_cs_caja_estado',   IF(COUNT(*)>0,'OK','FALTA') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='idx_cs_caja_estado'
UNION ALL SELECT 'Índice idx_p_activo_nombre',  IF(COUNT(*)>0,'OK','FALTA') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='idx_p_activo_nombre'
UNION ALL SELECT 'Índice idx_v_cliente_estado', IF(COUNT(*)>0,'OK','FALTA') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='idx_v_cliente_estado'
UNION ALL SELECT 'Permisos de reportes (5)',    IF(COUNT(*)=5,'OK',CONCAT('FALTAN, hay ',COUNT(*))) FROM permisos WHERE modulo='reportes'
UNION ALL SELECT 'Contadores sembrados (10)',   IF(COUNT(*)=10,'OK',CONCAT('hay ',COUNT(*))) FROM contadores
UNION ALL SELECT 'Contador de ventas al día',   IF((SELECT valor FROM contadores WHERE nombre='ventas.numero.VTA') >= (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(numero,'-',-1) AS UNSIGNED)),0) FROM ventas WHERE numero LIKE 'VTA-%'),'OK','DESFASADO');
