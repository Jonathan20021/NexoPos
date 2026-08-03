-- ============================================================================
--  Migración P9 — Marketing profesional (campañas, segmentos, automatización)
--
--  Convierte el envío masivo básico en un módulo de marketing completo:
--
--    · Segmentos guardados con reglas de negocio (RFM: recencia, frecuencia,
--      monto) en vez de dos listas fijas.
--    · Campañas por correo (Resend, automático) y por WhatsApp (wa.me, asistido)
--      con plantilla, promoción destacada, programación y prueba A/B de asunto.
--    · Una fila por destinatario (campana_envios): permite reanudar un envío
--      interrumpido, no repetir a nadie, medir aperturas y clics, y llevar la
--      cola de WhatsApp.
--    · Automatizaciones que corren solas: bienvenida, cumpleaños, recompra,
--      cliente inactivo, gracias por su compra y aviso de saldo.
--    · Bajas (opt-out) con enlace propio en cada correo.
--
--  Idempotente: se puede correr varias veces. Reversión al final.
-- ============================================================================

-- Obligatorio: los textos de fábrica llevan tildes, ñ y emojis. Sin esto, el
-- cliente de MySQL en Windows los inserta con la codificación de la consola y
-- «¿Te aparto el tuyo?» queda guardado como «┬┐Te aparto el tuyo?».
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
--  1. Segmentos guardados
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS marketing_segmentos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(120) NOT NULL,
  descripcion     VARCHAR(255) NULL,
  -- Contactabilidad
  requiere_email    TINYINT(1) NOT NULL DEFAULT 1,
  requiere_telefono TINYINT(1) NOT NULL DEFAULT 0,
  -- Estado comercial
  tipo_cliente    ENUM('cualquiera','contado','credito') NOT NULL DEFAULT 'cualquiera',
  deuda           ENUM('cualquiera','con','sin') NOT NULL DEFAULT 'cualquiera',
  sucursal_id     INT UNSIGNED NULL,          -- compró alguna vez en esta sucursal
  categoria_id    INT UNSIGNED NULL,          -- compró algún producto de esta categoría
  -- Recencia (días desde la última compra)
  dias_sin_comprar_min INT NULL,              -- inactivo: hace AL MENOS N días
  dias_sin_comprar_max INT NULL,              -- reciente: hace COMO MUCHO N días
  incluir_sin_compras  TINYINT(1) NOT NULL DEFAULT 1,
  -- Frecuencia y monto (histórico de ventas completadas)
  compras_min     INT NULL,
  gasto_min       DECIMAL(12,2) NULL,
  gasto_max       DECIMAL(12,2) NULL,
  -- Cumpleaños: 0 = no filtra, 1..12 = mes fijo, 13 = mes en curso
  cumple_mes      TINYINT NOT NULL DEFAULT 0,
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_seg_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  2. Plantillas reutilizables
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS marketing_plantillas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(120) NOT NULL,
  categoria      ENUM('promocion','bienvenida','cumpleanos','recompra','inactivo','cobranza','aviso','temporada') NOT NULL DEFAULT 'promocion',
  asunto         VARCHAR(180) NOT NULL,
  preheader      VARCHAR(180) NULL,
  contenido      MEDIUMTEXT NOT NULL,
  cta_texto      VARCHAR(60) NULL,
  cta_url        VARCHAR(255) NULL,
  whatsapp_texto TEXT NULL,
  es_sistema     TINYINT(1) NOT NULL DEFAULT 0,   -- las de fábrica no se pueden borrar
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_plt_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  3. Campañas: columnas nuevas
--     Una sola guarda: o están todas o no está ninguna.
-- ---------------------------------------------------------------------------
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campanas' AND COLUMN_NAME = 'canal');
SET @s := IF(@falta, 'ALTER TABLE campanas
    ADD COLUMN canal           ENUM(''email'',''whatsapp'',''ambos'') NOT NULL DEFAULT ''email'' AFTER contenido,
    ADD COLUMN segmento_id     INT UNSIGNED NULL AFTER segmento,
    ADD COLUMN plantilla_id    INT UNSIGNED NULL AFTER segmento_id,
    ADD COLUMN promocion_id    INT UNSIGNED NULL AFTER plantilla_id,
    ADD COLUMN preheader       VARCHAR(180) NULL AFTER asunto,
    ADD COLUMN asunto_b        VARCHAR(180) NULL AFTER preheader,
    ADD COLUMN cta_texto       VARCHAR(60) NULL AFTER promocion_id,
    ADD COLUMN cta_url         VARCHAR(255) NULL AFTER cta_texto,
    ADD COLUMN imagen          VARCHAR(255) NULL AFTER cta_url,
    ADD COLUMN whatsapp_texto  TEXT NULL AFTER imagen,
    ADD COLUMN programada_at   DATETIME NULL AFTER whatsapp_texto,
    ADD COLUMN automatizacion_id INT UNSIGNED NULL AFTER programada_at,
    ADD COLUMN aperturas       INT NOT NULL DEFAULT 0 AFTER fallidos,
    ADD COLUMN clics           INT NOT NULL DEFAULT 0 AFTER aperturas,
    ADD COLUMN bajas           INT NOT NULL DEFAULT 0 AFTER clics,
    ADD COLUMN updated_at      DATETIME NULL AFTER enviada_at',
  'SELECT ''campanas ya extendida''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- El estado ahora tiene más pasos (programada, enviando, pausada, cancelada).
-- Es un superconjunto del anterior: correrlo dos veces no hace daño.
ALTER TABLE campanas
  MODIFY COLUMN estado ENUM('borrador','programada','enviando','enviada','parcial','pausada','cancelada')
  NOT NULL DEFAULT 'borrador';

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campanas' AND INDEX_NAME = 'idx_campana_programada');
SET @s := IF(@falta, 'ALTER TABLE campanas ADD KEY idx_campana_programada (estado, programada_at)',
                     'SELECT ''idx_campana_programada ya existe''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
--  4. Envíos: una fila por destinatario
--     Es el corazón del módulo. Sin esto no hay reanudación, ni métricas
--     por persona, ni cola de WhatsApp.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campana_envios (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campana_id   INT UNSIGNED NOT NULL,
  cliente_id   INT UNSIGNED NULL,
  canal        ENUM('email','whatsapp') NOT NULL DEFAULT 'email',
  destino      VARCHAR(180) NOT NULL,          -- correo o teléfono normalizado
  nombre       VARCHAR(150) NULL,
  token        CHAR(32) NOT NULL,              -- identifica el envío en el rastreo
  variante     CHAR(1) NOT NULL DEFAULT 'A',   -- prueba A/B del asunto
  estado       ENUM('pendiente','enviado','fallido','omitido') NOT NULL DEFAULT 'pendiente',
  proveedor_id VARCHAR(80) NULL,
  error        VARCHAR(255) NULL,
  enviado_at   DATETIME NULL,
  abierto_at   DATETIME NULL,
  clic_at      DATETIME NULL,
  aperturas    INT NOT NULL DEFAULT 0,
  clics        INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_envio_token (token),
  UNIQUE KEY uq_envio_destino (campana_id, canal, destino),
  KEY idx_envio_campana (campana_id, estado),
  KEY idx_envio_cliente (cliente_id),
  KEY idx_envio_clic (clic_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  5. Automatizaciones (correos que salen solos)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS marketing_automatizaciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave          VARCHAR(40) NOT NULL,
  nombre         VARCHAR(140) NOT NULL,
  disparador     ENUM('bienvenida','cumpleanos','recompra','inactivo','post_venta','saldo_pendiente') NOT NULL,
  dias           INT NOT NULL DEFAULT 0,        -- significado según el disparador
  canal          ENUM('email','whatsapp','ambos') NOT NULL DEFAULT 'email',
  asunto         VARCHAR(180) NOT NULL,
  preheader      VARCHAR(180) NULL,
  contenido      MEDIUMTEXT NOT NULL,
  cta_texto      VARCHAR(60) NULL,
  cta_url        VARCHAR(255) NULL,
  whatsapp_texto TEXT NULL,
  promocion_id   INT UNSIGNED NULL,
  tope_dia       INT NOT NULL DEFAULT 200,      -- freno de seguridad por corrida
  activo         TINYINT(1) NOT NULL DEFAULT 0, -- nace apagada: la enciende una persona
  enviados       INT NOT NULL DEFAULT 0,
  ultimo_run     DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auto_clave (clave),
  KEY idx_auto_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora: impide que la misma automatización le escriba dos veces al mismo
-- cliente por el mismo motivo (el cumpleaños de este año, esa venta concreta…).
CREATE TABLE IF NOT EXISTS marketing_automatizacion_log (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  automatizacion_id INT UNSIGNED NOT NULL,
  cliente_id        INT UNSIGNED NOT NULL,
  periodo           VARCHAR(40) NOT NULL,       -- '2026', '2026-08', 'venta:1234'
  campana_id        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auto_log (automatizacion_id, cliente_id, periodo),
  KEY idx_auto_log_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  6. Bajas (opt-out). Un correo comercial sin salida es spam.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS marketing_bajas (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  canal      ENUM('email','whatsapp') NOT NULL DEFAULT 'email',
  destino    VARCHAR(180) NOT NULL,
  cliente_id INT UNSIGNED NULL,
  campana_id INT UNSIGNED NULL,
  motivo     VARCHAR(180) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_baja_destino (canal, destino)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  7. Clientes: cumpleaños y consentimiento
-- ---------------------------------------------------------------------------
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'fecha_nacimiento');
SET @s := IF(@falta, 'ALTER TABLE clientes
    ADD COLUMN fecha_nacimiento DATE NULL AFTER direccion,
    ADD COLUMN acepta_marketing TINYINT(1) NOT NULL DEFAULT 1 AFTER fecha_nacimiento,
    ADD KEY idx_cli_cumple (fecha_nacimiento)',
  'SELECT ''clientes ya tiene fecha_nacimiento''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
--  8. Promociones: material para el correo (solo presentación, no toca el cálculo)
-- ---------------------------------------------------------------------------
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promociones' AND COLUMN_NAME = 'descripcion');
SET @s := IF(@falta, 'ALTER TABLE promociones
    ADD COLUMN descripcion VARCHAR(255) NULL AFTER nombre,
    ADD COLUMN imagen      VARCHAR(255) NULL AFTER descripcion',
  'SELECT ''promociones ya tiene descripcion''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
--  9. Permisos (grupo Marketing)
-- ---------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'marketing.ver' c,'marketing' m,'Marketing' g,'Marketing — Ver el panel' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='marketing.ver');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'marketing.segmentos' c,'marketing' m,'Marketing' g,'Marketing — Crear y editar segmentos' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='marketing.segmentos');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'marketing.plantillas' c,'marketing' m,'Marketing' g,'Marketing — Crear y editar plantillas' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='marketing.plantillas');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'marketing.automatizar' c,'marketing' m,'Marketing' g,'Marketing — Encender y configurar automatizaciones' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='marketing.automatizar');
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (SELECT 'campanas.whatsapp' c,'campanas' m,'Marketing' g,'Campañas — Consola de envío por WhatsApp' d) t
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave='campanas.whatsapp');

-- Se conceden a quien ya podía ver campañas, y siempre al rol super.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pr ON pr.id = rp.permiso_id AND pr.clave = 'campanas.ver'
  JOIN permisos p  ON p.clave IN ('marketing.ver','marketing.segmentos','marketing.plantillas','marketing.automatizar','campanas.whatsapp')
 WHERE NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = rp.rol_id AND x.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r JOIN permisos p ON p.clave IN ('marketing.ver','marketing.segmentos','marketing.plantillas','marketing.automatizar','campanas.whatsapp')
 WHERE r.es_super = 1
   AND NOT EXISTS (SELECT 1 FROM rol_permisos x WHERE x.rol_id = r.id AND x.permiso_id = p.id);

-- ---------------------------------------------------------------------------
--  10. Segmentos de fábrica
--      Cubren los seis casos que una PyME usa el 90% del tiempo.
-- ---------------------------------------------------------------------------
INSERT INTO marketing_segmentos (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
        dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, gasto_min, cumple_mes)
SELECT * FROM (SELECT 'Todos los contactables' n, 'Clientes activos con correo válido y que aceptan promociones' d,
        1 a, 0 b, 'cualquiera' c, 'cualquiera' e, NULL f, NULL g, 1 h, NULL i, NULL j, 0 k) t
WHERE NOT EXISTS (SELECT 1 FROM marketing_segmentos WHERE nombre = 'Todos los contactables');

INSERT INTO marketing_segmentos (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
        dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, gasto_min, cumple_mes)
SELECT * FROM (SELECT 'Clientes frecuentes' n, 'Compraron 3 veces o más en el histórico' d,
        1 a, 0 b, 'cualquiera' c, 'cualquiera' e, NULL f, NULL g, 0 h, 3 i, NULL j, 0 k) t
WHERE NOT EXISTS (SELECT 1 FROM marketing_segmentos WHERE nombre = 'Clientes frecuentes');

INSERT INTO marketing_segmentos (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
        dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, gasto_min, cumple_mes)
SELECT * FROM (SELECT 'Dormidos (90 días sin comprar)' n, 'No compran hace 90 días o más: el segmento que más recupera venta' d,
        1 a, 0 b, 'cualquiera' c, 'cualquiera' e, 90 f, NULL g, 0 h, 1 i, NULL j, 0 k) t
WHERE NOT EXISTS (SELECT 1 FROM marketing_segmentos WHERE nombre = 'Dormidos (90 días sin comprar)');

INSERT INTO marketing_segmentos (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
        dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, gasto_min, cumple_mes)
SELECT * FROM (SELECT 'Compraron este mes' n, 'Compra en los últimos 30 días: ideal para venta cruzada' d,
        1 a, 0 b, 'cualquiera' c, 'cualquiera' e, NULL f, 30 g, 0 h, NULL i, NULL j, 0 k) t
WHERE NOT EXISTS (SELECT 1 FROM marketing_segmentos WHERE nombre = 'Compraron este mes');

INSERT INTO marketing_segmentos (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
        dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, gasto_min, cumple_mes)
SELECT * FROM (SELECT 'Cumpleañeros del mes' n, 'Cumplen años en el mes en curso' d,
        1 a, 0 b, 'cualquiera' c, 'cualquiera' e, NULL f, NULL g, 1 h, NULL i, NULL j, 13 k) t
WHERE NOT EXISTS (SELECT 1 FROM marketing_segmentos WHERE nombre = 'Cumpleañeros del mes');

INSERT INTO marketing_segmentos (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
        dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, gasto_min, cumple_mes)
SELECT * FROM (SELECT 'Con saldo pendiente' n, 'Tienen balance por cobrar (avisos de cobranza)' d,
        0 a, 1 b, 'credito' c, 'con' e, NULL f, NULL g, 1 h, NULL i, NULL j, 0 k) t
WHERE NOT EXISTS (SELECT 1 FROM marketing_segmentos WHERE nombre = 'Con saldo pendiente');

INSERT INTO marketing_segmentos (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
        dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, gasto_min, cumple_mes)
SELECT * FROM (SELECT 'WhatsApp: todos con teléfono' n, 'Clientes activos con un número válido para wa.me' d,
        0 a, 1 b, 'cualquiera' c, 'cualquiera' e, NULL f, NULL g, 1 h, NULL i, NULL j, 0 k) t
WHERE NOT EXISTS (SELECT 1 FROM marketing_segmentos WHERE nombre = 'WhatsApp: todos con teléfono');

-- ---------------------------------------------------------------------------
--  11. Plantillas de fábrica
--      Redactadas en español dominicano, listas para usar. Variables:
--      {{cliente}} {{empresa}} {{promo}} {{descuento}} {{vigencia}} {{saldo}} {{telefono}}
-- ---------------------------------------------------------------------------
INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema)
SELECT * FROM (SELECT
  'Promoción de temporada' n, 'promocion' c,
  '{{cliente}}, aprovecha {{descuento}} por tiempo limitado' a,
  'Aprovecha antes de que termine la promoción' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Preparamos algo para ti: <strong>{{promo}}</strong> con <strong>{{descuento}}</strong>.</p><p>La promoción está vigente {{vigencia}}. Pasa por la tienda o escríbenos y te apartamos lo tuyo.</p>' t,
  'Ver la promoción' b,
  'Hola {{cliente}}, te escribo de {{empresa}}. Tenemos {{promo}} con {{descuento}}, vigente {{vigencia}}. ¿Te aparto el tuyo?' w,
  1 s) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_plantillas WHERE nombre = 'Promoción de temporada');

INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema)
SELECT * FROM (SELECT
  'Bienvenida a un cliente nuevo' n, 'bienvenida' c,
  '¡Bienvenido a {{empresa}}, {{cliente}}!' a,
  'Gracias por tu primera compra' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por confiar en <strong>{{empresa}}</strong>. Nos alegra tenerte con nosotros.</p><p>Cualquier cosa que necesites, escríbenos por aquí o al {{telefono}}: te atendemos de una vez.</p>' t,
  'Ver el catálogo' b,
  'Hola {{cliente}}, ¡bienvenido a {{empresa}}! Cualquier cosa que necesites, escríbeme por aquí.' w,
  1 s) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_plantillas WHERE nombre = 'Bienvenida a un cliente nuevo');

INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema)
SELECT * FROM (SELECT
  'Feliz cumpleaños' n, 'cumpleanos' c,
  '🎉 ¡Feliz cumpleaños, {{cliente}}!' a,
  'Tenemos un regalo para ti' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>De parte de todo el equipo de <strong>{{empresa}}</strong>: ¡feliz cumpleaños!</p><p>Para celebrarlo contigo, este mes tienes <strong>{{descuento}}</strong> en tu compra. Solo menciónalo al pagar.</p>' t,
  'Reclamar mi descuento' b,
  '¡Feliz cumpleaños, {{cliente}}! 🎉 De parte de {{empresa}}. Este mes tienes {{descuento}} en tu compra.' w,
  1 s) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_plantillas WHERE nombre = 'Feliz cumpleaños');

INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema)
SELECT * FROM (SELECT
  'Te extrañamos (cliente dormido)' n, 'inactivo' c,
  '{{cliente}}, hace tiempo no te vemos' a,
  'Vuelve con un descuento de nuestra parte' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Notamos que hace un tiempo no pasas por <strong>{{empresa}}</strong>, y queremos que vuelvas.</p><p>Te dejamos <strong>{{descuento}}</strong> en tu próxima compra, vigente {{vigencia}}.</p>' t,
  'Volver a comprar' b,
  'Hola {{cliente}}, te escribo de {{empresa}}. Hace tiempo no te vemos y te dejamos {{descuento}} en tu próxima compra. ¿Te muestro lo que llegó nuevo?' w,
  1 s) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_plantillas WHERE nombre = 'Te extrañamos (cliente dormido)');

INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema)
SELECT * FROM (SELECT
  'Gracias por tu compra' n, 'recompra' c,
  'Gracias por tu compra, {{cliente}}' a,
  '¿Todo bien con lo que te llevaste?' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por tu compra en <strong>{{empresa}}</strong>. Esperamos que todo esté perfecto.</p><p>Si algo no salió como esperabas, responde este correo o llámanos al {{telefono}}: lo resolvemos.</p>' t,
  'Ver novedades' b,
  'Hola {{cliente}}, gracias por tu compra en {{empresa}}. ¿Todo bien con lo que te llevaste?' w,
  1 s) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_plantillas WHERE nombre = 'Gracias por tu compra');

INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema)
SELECT * FROM (SELECT
  'Recordatorio de saldo pendiente' n, 'cobranza' c,
  'Recordatorio de tu cuenta con {{empresa}}' a,
  'Tu saldo pendiente al día de hoy' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Te escribimos para recordarte que tu cuenta con <strong>{{empresa}}</strong> tiene un saldo pendiente de <strong>{{saldo}}</strong>.</p><p>Si ya realizaste el pago, ignora este mensaje. Cualquier duda, llámanos al {{telefono}}.</p>' t,
  '' b,
  'Hola {{cliente}}, le escribo de {{empresa}}. Su cuenta tiene un saldo pendiente de {{saldo}}. Si ya realizó el pago, haga caso omiso de este mensaje.' w,
  1 s) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_plantillas WHERE nombre = 'Recordatorio de saldo pendiente');

INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema)
SELECT * FROM (SELECT
  'Llegó mercancía nueva' n, 'aviso' c,
  'Llegó lo nuevo a {{empresa}}' a,
  'Mira lo que acaba de entrar' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Acaba de llegar mercancía nueva a <strong>{{empresa}}</strong> y queríamos que fueras de los primeros en verla.</p><p>Pasa por la tienda o escríbenos y te enviamos fotos.</p>' t,
  'Ver lo nuevo' b,
  'Hola {{cliente}}, le escribo de {{empresa}}. Acaba de llegar mercancía nueva, ¿le mando fotos?' w,
  1 s) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_plantillas WHERE nombre = 'Llegó mercancía nueva');

-- ---------------------------------------------------------------------------
--  12. Automatizaciones de fábrica (TODAS APAGADAS)
--      Nacen inactivas a propósito: nadie debe descubrir que su sistema empezó
--      a escribirle a sus clientes sin habérselo pedido.
-- ---------------------------------------------------------------------------
INSERT INTO marketing_automatizaciones (clave, nombre, disparador, dias, canal, asunto, preheader, contenido, cta_texto, whatsapp_texto, activo)
SELECT * FROM (SELECT 'bienvenida' k, 'Bienvenida al cliente nuevo' n, 'bienvenida' d, 0 i, 'email' c,
  '¡Bienvenido a {{empresa}}, {{cliente}}!' a, 'Gracias por tu primera compra' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por confiar en <strong>{{empresa}}</strong>. Nos alegra tenerte con nosotros.</p><p>Cualquier cosa que necesites, escríbenos o llámanos al {{telefono}}.</p>' t,
  'Ver el catálogo' b,
  'Hola {{cliente}}, ¡bienvenido a {{empresa}}! Cualquier cosa que necesites, escríbeme por aquí.' w, 0 ac) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_automatizaciones WHERE clave = 'bienvenida');

INSERT INTO marketing_automatizaciones (clave, nombre, disparador, dias, canal, asunto, preheader, contenido, cta_texto, whatsapp_texto, activo)
SELECT * FROM (SELECT 'cumpleanos' k, 'Felicitación de cumpleaños' n, 'cumpleanos' d, 0 i, 'email' c,
  '🎉 ¡Feliz cumpleaños, {{cliente}}!' a, 'Tenemos un regalo para ti' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>De parte de todo el equipo de <strong>{{empresa}}</strong>: ¡feliz cumpleaños!</p><p>Para celebrarlo, este mes tienes un descuento especial en tu compra. Solo menciónalo al pagar.</p>' t,
  'Reclamar mi descuento' b,
  '¡Feliz cumpleaños, {{cliente}}! 🎉 De parte de {{empresa}}.' w, 0 ac) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_automatizaciones WHERE clave = 'cumpleanos');

INSERT INTO marketing_automatizaciones (clave, nombre, disparador, dias, canal, asunto, preheader, contenido, cta_texto, whatsapp_texto, activo)
SELECT * FROM (SELECT 'post_venta' k, 'Gracias por tu compra' n, 'post_venta' d, 2 i, 'email' c,
  'Gracias por tu compra, {{cliente}}' a, '¿Todo bien con lo que te llevaste?' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por tu compra en <strong>{{empresa}}</strong>. Esperamos que todo esté perfecto.</p><p>Si algo no salió como esperabas, responde este correo o llámanos al {{telefono}}.</p>' t,
  '' b,
  'Hola {{cliente}}, gracias por tu compra en {{empresa}}. ¿Todo bien con lo que te llevaste?' w, 0 ac) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_automatizaciones WHERE clave = 'post_venta');

INSERT INTO marketing_automatizaciones (clave, nombre, disparador, dias, canal, asunto, preheader, contenido, cta_texto, whatsapp_texto, activo)
SELECT * FROM (SELECT 'recompra' k, 'Recordatorio de recompra' n, 'recompra' d, 45 i, 'email' c,
  '{{cliente}}, ¿te hace falta reponer?' a, 'Pasaron unas semanas de tu última compra' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Ya pasaron unas semanas desde tu última compra en <strong>{{empresa}}</strong>. Si te hace falta reponer, escríbenos y te lo dejamos listo.</p>' t,
  'Volver a comprar' b,
  'Hola {{cliente}}, le escribo de {{empresa}}. ¿Le hace falta reponer? Se lo dejo listo.' w, 0 ac) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_automatizaciones WHERE clave = 'recompra');

INSERT INTO marketing_automatizaciones (clave, nombre, disparador, dias, canal, asunto, preheader, contenido, cta_texto, whatsapp_texto, activo)
SELECT * FROM (SELECT 'inactivo' k, 'Recuperar cliente dormido' n, 'inactivo' d, 90 i, 'email' c,
  '{{cliente}}, hace tiempo no te vemos' a, 'Vuelve con un descuento de nuestra parte' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Notamos que hace un tiempo no pasas por <strong>{{empresa}}</strong>, y queremos que vuelvas.</p><p>Escríbenos y te contamos lo que llegó nuevo.</p>' t,
  'Volver a comprar' b,
  'Hola {{cliente}}, le escribo de {{empresa}}. Hace tiempo no le vemos, ¿le muestro lo que llegó nuevo?' w, 0 ac) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_automatizaciones WHERE clave = 'inactivo');

INSERT INTO marketing_automatizaciones (clave, nombre, disparador, dias, canal, asunto, preheader, contenido, cta_texto, whatsapp_texto, activo)
SELECT * FROM (SELECT 'saldo_pendiente' k, 'Aviso de saldo pendiente' n, 'saldo_pendiente' d, 30 i, 'email' c,
  'Recordatorio de tu cuenta con {{empresa}}' a, 'Tu saldo pendiente al día de hoy' p,
  '<p>Hola <strong>{{cliente}}</strong>,</p><p>Te recordamos que tu cuenta con <strong>{{empresa}}</strong> tiene un saldo pendiente de <strong>{{saldo}}</strong>.</p><p>Si ya realizaste el pago, ignora este mensaje.</p>' t,
  '' b,
  'Hola {{cliente}}, le escribo de {{empresa}}. Su cuenta tiene un saldo pendiente de {{saldo}}. Si ya realizó el pago, haga caso omiso.' w, 0 ac) x
WHERE NOT EXISTS (SELECT 1 FROM marketing_automatizaciones WHERE clave = 'saldo_pendiente');

-- ---------------------------------------------------------------------------
--  Verificación
-- ---------------------------------------------------------------------------
SELECT 'tabla marketing_segmentos'  item, COUNT(*) ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_segmentos'
UNION ALL SELECT 'tabla marketing_plantillas', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_plantillas'
UNION ALL SELECT 'tabla campana_envios', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campana_envios'
UNION ALL SELECT 'tabla marketing_automatizaciones', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_automatizaciones'
UNION ALL SELECT 'tabla marketing_bajas', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_bajas'
UNION ALL SELECT 'campanas.canal', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campanas' AND COLUMN_NAME='canal'
UNION ALL SELECT 'clientes.fecha_nacimiento', COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clientes' AND COLUMN_NAME='fecha_nacimiento'
UNION ALL SELECT 'segmentos de fábrica', COUNT(*) FROM marketing_segmentos
UNION ALL SELECT 'plantillas de fábrica', COUNT(*) FROM marketing_plantillas
UNION ALL SELECT 'automatizaciones (apagadas)', COUNT(*) FROM marketing_automatizaciones
UNION ALL SELECT 'permisos marketing', COUNT(*) FROM permisos WHERE clave LIKE 'marketing.%' OR clave = 'campanas.whatsapp';

-- ============================================================================
--  REVERSIÓN
--    DROP TABLE IF EXISTS marketing_automatizacion_log, marketing_automatizaciones,
--                         marketing_bajas, campana_envios, marketing_plantillas,
--                         marketing_segmentos;
--    ALTER TABLE campanas
--      DROP COLUMN canal, DROP COLUMN segmento_id, DROP COLUMN plantilla_id,
--      DROP COLUMN promocion_id, DROP COLUMN preheader, DROP COLUMN asunto_b,
--      DROP COLUMN cta_texto, DROP COLUMN cta_url, DROP COLUMN imagen,
--      DROP COLUMN whatsapp_texto, DROP COLUMN programada_at,
--      DROP COLUMN automatizacion_id, DROP COLUMN aperturas, DROP COLUMN clics,
--      DROP COLUMN bajas, DROP COLUMN updated_at, DROP KEY idx_campana_programada;
--    ALTER TABLE clientes DROP COLUMN fecha_nacimiento, DROP COLUMN acepta_marketing;
--    ALTER TABLE promociones DROP COLUMN descripcion, DROP COLUMN imagen;
--    DELETE FROM permisos WHERE clave LIKE 'marketing.%' OR clave = 'campanas.whatsapp';
-- ============================================================================
