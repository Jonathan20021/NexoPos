-- ---------------------------------------------------------------------------
--  NexoPOS · P40 — Todo el Promotion Cockpit configurable desde la pantalla
-- ---------------------------------------------------------------------------
--  La P39 dejó en el código los catálogos del cockpit: tipos de descuento,
--  motivos del descuento en caja, canales de la marca y su regla, rubros de
--  inversión, KPIs capturados a mano y los parámetros (año fiscal, cuántas
--  promociones «menos activadas», etc.). La marca los quiere cambiar ella,
--  sin programador. Aquí pasan a tablas, sembradas con los mismos valores que
--  tenía el código: aplicar esta migración no cambia ningún número.
--
--    cockpit_tipos         la fila del cockpit. Un tipo puede ser familia de
--                          promoción, motivo de descuento en caja, o ambos.
--    cockpit_canales       los canales en que reporta la marca
--    cockpit_canal_reglas  cómo se decide el canal de una venta (por canal de
--                          captación o por tipo de comprobante; el resto cae
--                          en el canal por defecto)
--    kpi_rubros            rubros de inversión (español e inglés para el Excel)
--    kpi_metricas_def      KPIs capturados a mano, con su «rol» (tráfico,
--                          sesiones web) para calcular conversiones
--    cockpit_parametros    clave → valor
--
--  Requiere la P39. Idempotente. Reversión al final.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cockpit_tipos (
  clave          VARCHAR(40)  NOT NULL,
  nombre         VARCHAR(80)  NOT NULL,              -- cómo se ve en el cockpit
  color          CHAR(7)      NOT NULL DEFAULT '#64748b',
  es_promocion   TINYINT(1)   NOT NULL DEFAULT 0,    -- se puede asignar a una promoción
  etiqueta_caja  VARCHAR(80)  NULL,                  -- si no es NULL, es un motivo del POS
  sistema        TINYINT(1)   NOT NULL DEFAULT 0,    -- lo usa el cálculo: no se borra
  orden          INT          NOT NULL DEFAULT 0,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_tipos (clave, nombre, color, es_promocion, etiqueta_caja, sistema, orden) VALUES
  ('set_regalo',  'Sets de regalo (Gift sets)',                   '#2a78d6', 1, NULL, 0, 10),
  ('calendario',  'Calendario de adviento',                       '#eb6834', 1, NULL, 0, 20),
  ('gwp',         'Regalo con compra (GWP / PWP)',                '#1baf7a', 1, NULL, 0, 30),
  ('crm',         'Clientes y fidelidad (CRM)',                   '#eda100', 1, 'Cliente frecuente / fidelidad', 0, 40),
  ('operacion',   'Operación especial (Black Friday, aniversario…)', '#e87ba4', 1, NULL, 0, 50),
  ('temporada',   'Temporada / rebajas (Sales)',                  '#008300', 1, NULL, 0, 60),
  ('lanzamiento', 'Lanzamiento',                                  '#4a3aa7', 1, NULL, 0, 70),
  ('empleados',   'Descuento de empleados',                       '#e34948', 1, 'Empleado', 0, 80),
  ('outlet',      'Outlet / liquidación',                         '#78716c', 1, 'Producto con defecto / liquidación', 0, 90),
  ('otro',        'Otro descuento',                               '#a8a29e', 1, 'Otro', 0, 100),
  ('cortesia',    'Cortesía / servicio al cliente',               '#57534e', 0, 'Cortesía / servicio', 0, 110),
  ('manual',      'Descuento manual en caja',                     '#475569', 0, 'Descuento manual', 1, 120),
  ('promocion',   'Promoción sin clasificar',                     '#94a3b8', 0, NULL, 1, 130),
  ('muestra',     'Muestras y regalos (RD$0)',                    '#64748b', 0, NULL, 1, 140),
  ('negociado',   'Precio negociado (cotización)',                '#334155', 0, NULL, 1, 150),
  ('sin',         'Sin promoción',                                '#cbd5e1', 0, NULL, 1, 160);

CREATE TABLE IF NOT EXISTS cockpit_canales (
  clave     VARCHAR(20) NOT NULL,
  nombre    VARCHAR(60) NOT NULL,
  nombre_en VARCHAR(60) NULL,             -- encabezado del Excel de la marca
  orden     INT NOT NULL DEFAULT 0,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_canales (clave, nombre, nombre_en, orden) VALUES
  ('retail',  'Retail (tiendas)',      'Retail',         10),
  ('mayoreo', 'Mayoreo / corporativo', 'Wholesale',      20),
  ('web',     'Web (tienda online)',   'Web',            30),
  ('social',  'Social selling',        'Social Selling', 40);

CREATE TABLE IF NOT EXISTS cockpit_canal_reglas (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- canal_venta: el canal de captación de la venta es igual a `valor`.
  -- comprobante: el tipo de comprobante NO es consumidor final (valor = 'no_consumidor')
  --              o es exactamente `valor` (credito_fiscal, gubernamental…).
  tipo   ENUM('canal_venta','comprobante') NOT NULL DEFAULT 'canal_venta',
  valor  VARCHAR(40) NOT NULL,
  canal  VARCHAR(20) NOT NULL,
  orden  INT NOT NULL DEFAULT 0,          -- gana la primera regla que se cumple
  PRIMARY KEY (id),
  UNIQUE KEY uq_regla (tipo, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_canal_reglas (tipo, valor, canal, orden) VALUES
  ('canal_venta', 'Tienda online', 'web',     10),
  ('canal_venta', 'Instagram',     'social',  20),
  ('canal_venta', 'WhatsApp',      'social',  30),
  ('canal_venta', 'Facebook',      'social',  40),
  ('canal_venta', 'TikTok',        'social',  50),
  ('comprobante', 'no_consumidor', 'mayoreo', 60);

CREATE TABLE IF NOT EXISTS kpi_rubros (
  clave     VARCHAR(40) NOT NULL,
  nombre    VARCHAR(120) NOT NULL,
  nombre_en VARCHAR(120) NULL,
  orden     INT NOT NULL DEFAULT 0,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO kpi_rubros (clave, nombre, nombre_en, orden) VALUES
  ('entrenamiento', 'Entrenamiento retail',                          'RETAIL TRAINING', 10),
  ('merchandising', 'Merchandising / animación en tienda (dummies)', 'MERCHANDISING/IN-STORE ANIMATION(DUMMIES)', 20),
  ('lanzamiento',   'Evento de lanzamiento',                          'LAUNCH EVENT', 30),
  ('pr',            'PR (seeding, artículos…)',                       'PR (seeding, articles, etc…)', 40),
  ('alianzas',      'Alianzas (marcas, influencers…)',                'PARTNERSHIPS (Brands, Influencers etc…)', 50),
  ('mall',          'Activaciones en mall, podios, pop-ups',          'MALL ANIMATIONS,PODIUMS, POP UPS', 60),
  ('paid_media',    'Medios pagados (Google Ads, Meta Ads)',          'PAID MEDIA (Google Ads, Meta Ads)', 70),
  ('ecommerce',     'E-commerce (incl. medios pagados)',              'ECOMMERCE (INCL. PAID MEDIA)', 80),
  ('ooh',           'Publicidad tradicional (vallas, mall, OOH)',     'ADVERTISING TRADITIONAL MEDIA (BILLBOARDS/MALL TAKE OVER/OOH)', 90),
  ('otros',         'Otras inversiones (regalos a top clientes…)',    'OTHER MARKETING INVESTMENTS', 100);

CREATE TABLE IF NOT EXISTS kpi_metricas_def (
  clave          VARCHAR(40) NOT NULL,
  grupo          VARCHAR(60) NOT NULL,
  nombre         VARCHAR(120) NOT NULL,
  nombre_en      VARCHAR(120) NULL,
  unidad         ENUM('num','pct','money') NOT NULL DEFAULT 'num',
  -- trafico: facturas ÷ esto = conversión en tienda; sesiones_web: pedidos web ÷ esto.
  rol            VARCHAR(20) NULL,
  menor_es_mejor TINYINT(1) NOT NULL DEFAULT 0,   -- la rotación del equipo baja = bueno
  orden          INT NOT NULL DEFAULT 0,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO kpi_metricas_def (clave, grupo, nombre, nombre_en, unidad, rol, menor_es_mejor, orden) VALUES
  ('trafico',            'Generales',                'Visitantes en tienda (tráfico)',               'Store traffic',            'num',   'trafico',      0, 10),
  ('nps',                'Generales',                'NPS',                                          'NPS results',              'num',   NULL,           0, 20),
  ('emv',                'Influencer / PR',          'EMV (valor mediático ganado)',                 'EMV',                      'money', NULL,           0, 30),
  ('impresiones_pr',     'Influencer / PR',          'Impresiones',                                  'Impressions',              'num',   NULL,           0, 40),
  ('alcance_pr',         'Influencer / PR',          'Alcance (reach)',                              'Reach',                    'num',   NULL,           0, 50),
  ('engagement_pr',      'Influencer / PR',          'Tasa de interacción (engagement)',             'Engagement Rate',          'pct',   NULL,           0, 60),
  ('impresiones_medios', 'OOH / Campañas en medios', 'Impresiones',                                  'Impressions',              'num',   NULL,           0, 70),
  ('sesiones_web',       'E-commerce',               'Sesiones en la tienda online',                 'Online store sessions',    'num',   'sesiones_web', 0, 80),
  ('asistentes',         'Entrenamiento',            'Beauty hosts que asistieron',                  'Beauty Hosts who attended','num',   NULL,           0, 90),
  ('encuesta',           'Entrenamiento',            'Satisfacción de la encuesta',                  'Attendees Survey Feedbacks','pct',  NULL,           0, 100),
  ('completitud',        'Entrenamiento',            'Tasa de completitud (MTS, talleres, tareas)',  'Completion rate',          'pct',   NULL,           0, 110),
  ('productividad',      'Entrenamiento',            'Crecimiento de productividad (antes/después)', 'Attendees productivity growth','pct', NULL,          0, 120),
  ('rotacion',           'Incentivos retail',        'Rotación del equipo',                          'Team turnover rate',       'pct',   NULL,           1, 130),
  ('nps_experiencia',    'Experiencia a la medida',  'NPS de la experiencia',                        'NPS results',              'num',   NULL,           0, 140);

CREATE TABLE IF NOT EXISTS cockpit_parametros (
  clave VARCHAR(40) NOT NULL,
  valor TEXT NULL,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cockpit_parametros (clave, valor) VALUES
  ('mes_inicio_fiscal',  '4'),
  ('periodo_defecto',    'ytd'),
  ('canal_defecto',      'retail'),
  ('menos_activadas',    '30'),
  ('top_skus',           '20'),
  ('dias_max',           '93'),
  -- Una opción por línea (CHAR(10) y no '\n': vale con o sin NO_BACKSLASH_ESCAPES).
  ('canales_captacion',  CONCAT_WS(CHAR(10), 'Mostrador', 'Instagram', 'WhatsApp', 'Facebook', 'Referido', 'Otro'));

-- Permiso para cambiarlo todo (también en permission_catalog()).
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'cockpit.configurar' c, 'cockpit' m, 'Marketing' g, 'Promotion Cockpit — Configurar catálogos, canales y parámetros' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'cockpit.configurar');

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'promociones.editar'
  JOIN permisos p  ON p.clave = 'cockpit.configurar'
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave = 'cockpit.configurar'
 WHERE r.es_super = 1 AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

SELECT 'cockpit_tipos' item, COUNT(*) filas FROM cockpit_tipos
UNION ALL SELECT 'cockpit_canales', COUNT(*) FROM cockpit_canales
UNION ALL SELECT 'cockpit_canal_reglas', COUNT(*) FROM cockpit_canal_reglas
UNION ALL SELECT 'kpi_rubros', COUNT(*) FROM kpi_rubros
UNION ALL SELECT 'kpi_metricas_def', COUNT(*) FROM kpi_metricas_def
UNION ALL SELECT 'cockpit_parametros', COUNT(*) FROM cockpit_parametros;

-- REVERSIÓN:
--   DROP TABLE IF EXISTS cockpit_parametros, kpi_metricas_def, kpi_rubros, cockpit_canal_reglas, cockpit_canales, cockpit_tipos;
--   DELETE FROM permisos WHERE clave = 'cockpit.configurar';
