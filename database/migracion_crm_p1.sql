-- ============================================================
--  Migración CRM P1 — Embudo de ventas, bitácora e agenda
--  Aplicar UNA vez sobre instalaciones existentes (producción).
--  Idempotente: se puede correr de nuevo sin romper nada.
--  En instalaciones nuevas NO hace falta: ya viene en schema.sql.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Tablas ----------
CREATE TABLE IF NOT EXISTS crm_oportunidades (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo                VARCHAR(20)  NOT NULL,
  cliente_id            INT UNSIGNED NOT NULL,
  sucursal_id           INT UNSIGNED NOT NULL,
  titulo                VARCHAR(150) NOT NULL,
  descripcion           VARCHAR(500) NULL,
  etapa                 ENUM('prospecto','contactado','propuesta','negociacion','ganada','perdida') NOT NULL DEFAULT 'prospecto',
  valor_estimado        DECIMAL(12,2) NOT NULL DEFAULT 0,
  probabilidad          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fuente                VARCHAR(60)  NULL,
  responsable_id        INT UNSIGNED NULL,
  fecha_cierre_estimada DATE NULL,
  fecha_cierre_real     DATE NULL,
  motivo_perdida        VARCHAR(255) NULL,
  venta_id              INT UNSIGNED NULL,
  created_by            INT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_opo_codigo (codigo),
  KEY idx_opo_sucursal (sucursal_id),
  KEY idx_opo_cliente (cliente_id),
  KEY idx_opo_etapa (etapa),
  KEY idx_opo_responsable (responsable_id),
  CONSTRAINT fk_opo_cliente   FOREIGN KEY (cliente_id)     REFERENCES clientes(id)   ON DELETE CASCADE,
  CONSTRAINT fk_opo_sucursal  FOREIGN KEY (sucursal_id)    REFERENCES sucursales(id),
  CONSTRAINT fk_opo_respons   FOREIGN KEY (responsable_id) REFERENCES usuarios(id)   ON DELETE SET NULL,
  CONSTRAINT fk_opo_venta     FOREIGN KEY (venta_id)       REFERENCES ventas(id)     ON DELETE SET NULL,
  CONSTRAINT chk_opo_prob     CHECK (probabilidad BETWEEN 0 AND 100),
  CONSTRAINT chk_opo_valor    CHECK (valor_estimado >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_interacciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id     INT UNSIGNED NOT NULL,
  oportunidad_id INT UNSIGNED NULL,
  sucursal_id    INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NULL,
  tipo           ENUM('llamada','whatsapp','email','visita','reunion','nota') NOT NULL DEFAULT 'nota',
  asunto         VARCHAR(150) NOT NULL,
  detalle        VARCHAR(1000) NULL,
  fecha          DATETIME NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_int_cliente (cliente_id),
  KEY idx_int_sucursal (sucursal_id),
  KEY idx_int_oportunidad (oportunidad_id),
  KEY idx_int_fecha (fecha),
  CONSTRAINT fk_int_cliente     FOREIGN KEY (cliente_id)     REFERENCES clientes(id)          ON DELETE CASCADE,
  CONSTRAINT fk_int_oportunidad FOREIGN KEY (oportunidad_id) REFERENCES crm_oportunidades(id) ON DELETE SET NULL,
  CONSTRAINT fk_int_sucursal    FOREIGN KEY (sucursal_id)    REFERENCES sucursales(id),
  CONSTRAINT fk_int_usuario     FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_tareas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id     INT UNSIGNED NULL,
  oportunidad_id INT UNSIGNED NULL,
  sucursal_id    INT UNSIGNED NOT NULL,
  asignado_a     INT UNSIGNED NULL,
  titulo         VARCHAR(150) NOT NULL,
  detalle        VARCHAR(500) NULL,
  vence_at       DATETIME NULL,
  prioridad      ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  estado         ENUM('pendiente','completada','cancelada') NOT NULL DEFAULT 'pendiente',
  completada_at  DATETIME NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tar_sucursal (sucursal_id),
  KEY idx_tar_asignado (asignado_a),
  KEY idx_tar_estado (estado),
  KEY idx_tar_vence (vence_at),
  KEY idx_tar_cliente (cliente_id),
  KEY idx_tar_oportunidad (oportunidad_id),
  CONSTRAINT fk_tar_cliente     FOREIGN KEY (cliente_id)     REFERENCES clientes(id)          ON DELETE CASCADE,
  CONSTRAINT fk_tar_oportunidad FOREIGN KEY (oportunidad_id) REFERENCES crm_oportunidades(id) ON DELETE CASCADE,
  CONSTRAINT fk_tar_sucursal    FOREIGN KEY (sucursal_id)    REFERENCES sucursales(id),
  CONSTRAINT fk_tar_asignado    FOREIGN KEY (asignado_a)     REFERENCES usuarios(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Permisos (catálogo) ----------
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
  ('crm.ver',      'crm', 'CRM', 'CRM (ficha 360°, embudo y seguimientos) — Ver'),
  ('crm.crear',    'crm', 'CRM', 'CRM (ficha 360°, embudo y seguimientos) — Crear oportunidades, interacciones y tareas'),
  ('crm.editar',   'crm', 'CRM', 'CRM (ficha 360°, embudo y seguimientos) — Editar'),
  ('crm.eliminar', 'crm', 'CRM', 'CRM (ficha 360°, embudo y seguimientos) — Eliminar'),
  ('crm.avanzar',  'crm', 'CRM', 'CRM (ficha 360°, embudo y seguimientos) — Mover etapa del embudo (ganar/perder)');

-- ---------- Asignación a roles ----------
-- Super Administrador y Administrador (roles de sistema): CRM completo.
-- Gerente de Sucursal: también completo (mismo criterio que el instalador).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
JOIN permisos p ON p.modulo = 'crm'
WHERE r.es_super = 1 OR r.es_sistema = 1 OR r.nombre = 'Gerente de Sucursal';
