-- ============================================================
--  Migración P3 — Centro de Notificaciones + Centro de Reportes
--  Aplicar UNA vez sobre instalaciones existentes (producción).
--  Idempotente: se puede correr de nuevo sin romper nada.
--  En instalaciones nuevas NO hace falta: ya viene en schema.sql.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Estado interno del sistema (llave/valor) ----------
-- Se usa para acotar cada cuánto corre el motor de notificaciones sin
-- necesidad de un cron: un solo UPDATE atómico gana la carrera entre
-- peticiones simultáneas.
CREATE TABLE IF NOT EXISTS sistema_estado (
  clave      VARCHAR(60)  NOT NULL,
  valor      VARCHAR(255) NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Notificaciones ----------
-- Una fila por «situación viva» del negocio (no por evento). La `clave`
-- deduplica: si el stock sigue bajo mañana, se actualiza la misma fila en vez
-- de crear una nueva. Cuando la situación se resuelve, estado='resuelta'.
CREATE TABLE IF NOT EXISTS notificaciones (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave           VARCHAR(120) NOT NULL,                 -- deduplicación (ej. stock_bajo:3)
  tipo            VARCHAR(40)  NOT NULL,                 -- familia: stock_bajo, cxc_vencida, ...
  categoria       ENUM('inventario','ventas','finanzas','fiscal','crm','rrhh','sistema') NOT NULL DEFAULT 'sistema',
  prioridad       ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  titulo          VARCHAR(150) NOT NULL,
  mensaje         VARCHAR(300) NULL,
  url             VARCHAR(255) NULL,
  icono           VARCHAR(30)  NOT NULL DEFAULT 'bell',
  color           VARCHAR(20)  NOT NULL DEFAULT 'blue',
  sucursal_id     INT UNSIGNED NULL,                     -- NULL = todas las sucursales
  usuario_id      INT UNSIGNED NULL,                     -- NULL = para todo el que tenga el permiso
  permiso         VARCHAR(60)  NULL,                     -- permiso requerido para verla
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

-- Marca de lectura por usuario (una notificación la ven varios usuarios).
CREATE TABLE IF NOT EXISTS notificacion_lecturas (
  notificacion_id BIGINT UNSIGNED NOT NULL,
  usuario_id      INT UNSIGNED NOT NULL,
  leida_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notificacion_id, usuario_id),
  KEY idx_nl_usuario (usuario_id),
  CONSTRAINT fk_nl_notif   FOREIGN KEY (notificacion_id) REFERENCES notificaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_nl_usuario FOREIGN KEY (usuario_id)      REFERENCES usuarios(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  Permisos nuevos (Centro de Reportes)
--  El catálogo vive en app/permissions.php; aquí solo se siembran las
--  filas para las bases ya instaladas.
-- ============================================================
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
  ('reportes.ver',          'reportes', 'Reportes', 'Centro de Reportes — Ver el centro de reportes'),
  ('reportes.ejecutivo',    'reportes', 'Reportes', 'Centro de Reportes — Reportes de dirección (CEO)'),
  ('reportes.finanzas',     'reportes', 'Reportes', 'Centro de Reportes — Reportes financieros'),
  ('reportes.contabilidad', 'reportes', 'Reportes', 'Centro de Reportes — Reportes contables y fiscales'),
  ('reportes.operacion',    'reportes', 'Reportes', 'Centro de Reportes — Reportes de operación y ventas');

-- Reubica el permiso histórico `reportes.ver` en el nuevo grupo.
UPDATE permisos SET grupo = 'Reportes', descripcion = 'Centro de Reportes — Ver el centro de reportes'
 WHERE clave = 'reportes.ver';

-- Todo rol que ya podía ver reportes conserva el acceso a los nuevos bloques.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p1 ON p1.id = rp.permiso_id AND p1.clave = 'reportes.ver'
  JOIN permisos p2 ON p2.clave IN ('reportes.ejecutivo', 'reportes.finanzas', 'reportes.contabilidad', 'reportes.operacion');
