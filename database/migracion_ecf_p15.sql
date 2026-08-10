-- ============================================================================
--  NexoPOS · P15 — Facturación Electrónica (e-CF) · Proveedor LUGANIS
-- ----------------------------------------------------------------------------
--  La DGII no recibe el e-CF directamente: se envía a un proveedor certificado
--  (LUGANIS) que arma el XML, lo firma, lo remite a la DGII y al comprador y
--  devuelve un acuse. El intercambio NO es JSON: es un archivo TXT delimitado
--  por pipes, codificado en Base64, dentro de un JSON de dos campos.
--
--  DECISIÓN CLAVE: el e-CF convive con el NCF preimpreso, no lo reemplaza de
--  golpe. Pasar de B01/B02 a E31/E32 es un corte fiscal que se hace en una fecha
--  concreta y con la certificación aprobada. Por eso:
--
--    · `ecf_config.activo` empieza en 0. Con el interruptor apagado el POS sigue
--      facturando exactamente como hoy y nada de esto se ejecuta.
--    · Las secuencias e-CF viven en la MISMA tabla `ncf_secuencias` (tipo E31,
--      E32…), así que el control de rangos, vencimiento y las reservas por
--      terminal para modo offline funcionan sin duplicar lógica.
--    · Se guarda la trama TXT enviada tal cual. Ante una revisión, lo que vale
--      es lo que se transmitió, no lo que la base pueda reconstruir hoy.
--
--  DIFERENCIA DE FORMATO que obliga a tocar el esquema: el NCF preimpreso son
--  8 dígitos (B0200000001, 11 caracteres) y el e-NCF son 10 (E310000000001,
--  13 caracteres). 10 dígitos no caben en INT UNSIGNED, así que las columnas de
--  secuencia pasan a BIGINT.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

-- ---------------------------------------------------------------------------
--  Helpers: añadir columna / índice solo si no existen (MySQL 8 no soporta
--  `ADD COLUMN IF NOT EXISTS`, que MariaDB sí).
-- ---------------------------------------------------------------------------
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
-- 1. Configuración del proveedor
--
--    Fila única. Las credenciales pueden vivir aquí (cómodo para certificar) o
--    en config/config.local.php como constantes ECF_USUARIO / ECF_CLAVE, que
--    tienen precedencia. En producción se recomienda lo segundo: config.local
--    no se versiona ni entra en los respaldos de la base.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecf_config (
  id                TINYINT UNSIGNED NOT NULL DEFAULT 1,
  activo            TINYINT(1)   NOT NULL DEFAULT 0,   -- interruptor maestro
  ambiente          ENUM('stage','produccion') NOT NULL DEFAULT 'stage',
  url_stage         VARCHAR(180) NOT NULL DEFAULT 'https://rd.stage-api.tech-luganis.net',
  url_produccion    VARCHAR(180) NULL,                 -- la entrega el consultor de LUGANIS
  usuario           VARCHAR(120) NULL,
  clave             VARCHAR(255) NULL,
  device_id         VARCHAR(120) NULL,                 -- debe ser estable: ata la sesión
  app_version       VARCHAR(20)  NOT NULL DEFAULT '1.0.0',
  latitud           DECIMAL(11,8) NULL,
  longitud          DECIMAL(11,8) NULL,
  ip_publica        VARCHAR(45)  NULL,                 -- IPv4 del servidor emisor
  envio_automatico  TINYINT(1)   NOT NULL DEFAULT 0,   -- emitir al cerrar la venta
  reintentos_max    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  -- Sesión viva. El token dura 3600 s; se refresca ~5 min antes de vencer en vez
  -- de hacer login en cada envío, como pide el manual (§10.d).
  access_token      TEXT NULL,
  refresh_token     TEXT NULL,
  token_expira      DATETIME NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_ecf_config_fila_unica CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ecf_config (id) VALUES (1)
  ON DUPLICATE KEY UPDATE id = id;

-- ---------------------------------------------------------------------------
-- 2. Documentos electrónicos emitidos
--
--    `uq_ecf_origen` es la red de idempotencia: una venta no puede generar dos
--    e-CF aunque se pulse "enviar" dos veces o el POST dé timeout y se reintente.
--    `uq_ecf_encf` protege el otro lado: nunca dos documentos con la misma
--    secuencia autorizada.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecf_documentos (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo_ecf          CHAR(2)      NOT NULL,             -- Tabla 1: 31,32,33,34…
  encf              VARCHAR(13)  NOT NULL,             -- E310000000001
  origen            ENUM('venta','devolucion','prueba') NOT NULL,
  origen_id         INT UNSIGNED NULL,                 -- ventas.id / devoluciones.id
  sucursal_id       INT UNSIGNED NULL,
  usuario_id        INT UNSIGNED NULL,
  rnc_emisor        VARCHAR(11)  NOT NULL,
  rnc_comprador     VARCHAR(11)  NULL,
  razon_social_comprador VARCHAR(150) NULL,
  fecha_emision     DATE         NOT NULL,
  total             DECIMAL(14,2) NOT NULL DEFAULT 0,
  -- Lo enviado, tal cual. `archivo` es el nombre exigido por el manual (§6.7).
  archivo           VARCHAR(40)  NOT NULL,             -- 132944372E310000000001.txt
  trama             MEDIUMTEXT   NOT NULL,             -- TXT en claro, con CRLF
  track_id          CHAR(36)     NULL,                 -- UUID v4 que devuelve /send
  estado            ENUM('pendiente','enviado','aceptado','rechazado','error')
                    NOT NULL DEFAULT 'pendiente',
  estado_detalle    VARCHAR(500) NULL,
  codigo_respuesta  VARCHAR(40)  NULL,
  respuesta_envio   MEDIUMTEXT   NULL,                 -- JSON crudo de /send
  respuesta_estado  MEDIUMTEXT   NULL,                 -- JSON crudo de /read/trackId
  intentos          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  proximo_intento   DATETIME     NULL,                 -- reintento con espera creciente
  enviado_at        DATETIME     NULL,
  consultado_at     DATETIME     NULL,
  -- Código QR de la Representación Impresa, como data URI listo para <img>.
  --
  -- NO se puede generar aquí: lleva el código de seguridad derivado de la firma
  -- digital, que solo tiene el proveedor. Se descarga una vez y se guarda, para
  -- que reimprimir un ticket no dependa de la red ni gaste una llamada.
  qr                MEDIUMTEXT   NULL,
  qr_at             DATETIME     NULL,
  qr_intentos       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ecf_encf (encf),
  UNIQUE KEY uq_ecf_origen (origen, origen_id),
  KEY idx_ecf_estado (estado, proximo_intento),
  KEY idx_ecf_track (track_id),
  KEY idx_ecf_fecha (fecha_emision),
  KEY idx_ecf_sucursal (sucursal_id, fecha_emision),
  CONSTRAINT fk_ecf_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para instalaciones donde la tabla ya existía sin estas columnas (el
-- CREATE TABLE IF NOT EXISTS de arriba no las añadiría).
CALL nexo_add_col('ecf_documentos', 'qr',          "MEDIUMTEXT NULL COMMENT 'QR de la RI como data URI'");
CALL nexo_add_col('ecf_documentos', 'qr_at',       "DATETIME NULL");
CALL nexo_add_col('ecf_documentos', 'qr_intentos', "TINYINT UNSIGNED NOT NULL DEFAULT 0");

-- ---------------------------------------------------------------------------
-- 3. Bitácora de llamadas a la API
--
--    Imprescindible durante la certificación: el manual no publica el catálogo
--    de códigos de error, así que la única forma de aprenderlos es guardando lo
--    que el proveedor responde. La clave y el token se ofuscan antes de grabar.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecf_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  documento_id  BIGINT UNSIGNED NULL,
  operacion     VARCHAR(20)  NOT NULL,                 -- login|refresh|logout|send|consulta|descarga
  metodo        VARCHAR(8)   NOT NULL,
  url           VARCHAR(255) NOT NULL,
  http_code     SMALLINT     NOT NULL DEFAULT 0,
  ms            INT UNSIGNED NOT NULL DEFAULT 0,
  peticion      MEDIUMTEXT   NULL,
  respuesta     MEDIUMTEXT   NULL,
  error         VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ecflog_doc (documento_id),
  KEY idx_ecflog_fecha (created_at),
  CONSTRAINT fk_ecflog_doc FOREIGN KEY (documento_id) REFERENCES ecf_documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Secuencias: cabida para 10 dígitos
--
--    El e-NCF usa 10 dígitos y 9,999,999,999 desborda INT UNSIGNED (máx.
--    4,294,967,295). Se amplía a BIGINT en el maestro y en las reservas por
--    terminal, que comparten el mismo número.
-- ---------------------------------------------------------------------------
ALTER TABLE ncf_secuencias
  MODIFY COLUMN secuencia_actual BIGINT UNSIGNED NOT NULL DEFAULT 1,
  MODIFY COLUMN secuencia_hasta  BIGINT UNSIGNED NOT NULL DEFAULT 99999999;

ALTER TABLE ncf_reservas
  MODIFY COLUMN secuencia_desde BIGINT UNSIGNED NOT NULL,
  MODIFY COLUMN secuencia_hasta BIGINT UNSIGNED NOT NULL;

-- ---------------------------------------------------------------------------
-- 5. Emisor: datos que exige la sección EMIS y que hoy no existen
-- ---------------------------------------------------------------------------
CALL nexo_add_col('empresa', 'ecf_nombre_comercial',   "VARCHAR(150) NULL COMMENT 'EMIS pos.3'");
CALL nexo_add_col('empresa', 'ecf_municipio',          "CHAR(6) NULL COMMENT 'EMIS pos.6 — Tabla 8'");
CALL nexo_add_col('empresa', 'ecf_provincia',          "CHAR(6) NULL COMMENT 'EMIS pos.7 — Tabla 8'");
CALL nexo_add_col('empresa', 'ecf_website',            "VARCHAR(50) NULL COMMENT 'EMIS pos.10'");
CALL nexo_add_col('empresa', 'ecf_actividad_economica',"VARCHAR(100) NULL COMMENT 'EMIS pos.11'");

-- Cada sucursal factura desde su propia dirección.
CALL nexo_add_col('sucursales', 'ecf_municipio', "CHAR(6) NULL COMMENT 'Tabla 8'");
CALL nexo_add_col('sucursales', 'ecf_provincia', "CHAR(6) NULL COMMENT 'Tabla 8'");

-- ---------------------------------------------------------------------------
-- 6. Impuesto por línea: de un booleano a los 5 estados de la Tabla 13
--
--    `productos.itbis_aplica` solo distingue "lleva ITBIS" de "no lleva". El
--    e-CF exige por línea: 0 no facturable · 1 ITBIS 18% · 2 ITBIS 16% ·
--    3 ITBIS 0% · 4 Exento. Un producto exento y uno al 0% son cosas distintas
--    para la DGII y hoy el sistema no puede diferenciarlos.
--
--    El valor se siembra desde itbis_aplica para no romper el catálogo actual:
--    lo que hoy lleva ITBIS queda en 1 (18%) y lo que no, en 4 (exento).
-- ---------------------------------------------------------------------------
CALL nexo_add_col('productos', 'ecf_indicador_facturacion',
                  "TINYINT UNSIGNED NULL COMMENT 'Tabla 13: 0 no facturable, 1 18%, 2 16%, 3 0%, 4 exento'");
CALL nexo_add_col('productos', 'ecf_impuesto_adicional',
                  "VARCHAR(3) NULL COMMENT 'Tabla 10: 001 propina, 003/004 ISC servicios, 006-039 ISC'");

UPDATE productos
   SET ecf_indicador_facturacion = CASE WHEN itbis_aplica = 1 THEN 1 ELSE 4 END
 WHERE ecf_indicador_facturacion IS NULL;

-- Unidad de medida: mapeo del catálogo propio a la Tabla 9. 43 = UND.
CALL nexo_add_col('unidades', 'ecf_codigo', "TINYINT UNSIGNED NULL COMMENT 'Tabla 9. Unidad_Medida'");

UPDATE unidades SET ecf_codigo = CASE UPPER(abreviatura)
    WHEN 'UND' THEN 43 WHEN 'UD'  THEN 43 WHEN 'U'   THEN 43 WHEN 'PZA' THEN 34
    WHEN 'CAJ' THEN 6  WHEN 'CJ'  THEN 6  WHEN 'BOL' THEN 2  WHEN 'PAQ' THEN 31
    WHEN 'KG'  THEN 21 WHEN 'GR'  THEN 17 WHEN 'G'   THEN 17 WHEN 'LB'  THEN 23
    WHEN 'LT'  THEN 24 WHEN 'L'   THEN 24 WHEN 'ML'  THEN 59 WHEN 'GL'  THEN 15
    WHEN 'M'   THEN 26 WHEN 'M2'  THEN 27 WHEN 'M3'  THEN 28 WHEN 'CM'  THEN 8
    WHEN 'DOC' THEN 13 WHEN 'PAR' THEN 32 WHEN 'ROL' THEN 35 WHEN 'SAC' THEN 46
    WHEN 'LAT' THEN 47 WHEN 'BOT' THEN 5  WHEN 'GAL' THEN 15 WHEN 'ONZ' THEN 61
    WHEN 'OZ'  THEN 61 WHEN 'HOR' THEN 19 WHEN 'DIA' THEN 12 WHEN 'SERV' THEN 43
    ELSE 43 END
 WHERE ecf_codigo IS NULL;

-- ---------------------------------------------------------------------------
-- 7. Congelar en la línea de venta lo que se declaró
--
--    Si mañana el producto cambia de tasa o de unidad, el e-CF ya emitido debe
--    seguir contando lo que se declaró ese día. Derivarlo por JOIN a `productos`
--    reescribiría el pasado.
-- ---------------------------------------------------------------------------
CALL nexo_add_col('venta_detalles', 'ecf_indicador_facturacion', "TINYINT UNSIGNED NULL COMMENT 'Tabla 13'");
CALL nexo_add_col('venta_detalles', 'ecf_unidad_medida',         "TINYINT UNSIGNED NULL COMMENT 'Tabla 9'");
CALL nexo_add_col('venta_detalles', 'ecf_bien_servicio',         "TINYINT UNSIGNED NULL COMMENT 'Tabla 14: 1 bien, 2 servicio'");
CALL nexo_add_col('venta_detalles', 'ecf_impuesto_adicional',    "VARCHAR(3) NULL COMMENT 'Tabla 10'");

-- ---------------------------------------------------------------------------
-- 8. Venta: tipo de e-CF emitido
--
--    `tipo_comprobante` solo distingue consumidor/crédito fiscal. Se guarda
--    aparte el tipo real por si más adelante entran 44 (regímenes especiales),
--    45 (gubernamental) o 46 (exportaciones), que no se derivan de ese ENUM.
-- ---------------------------------------------------------------------------
CALL nexo_add_col('ventas', 'ecf_tipo', "CHAR(2) NULL COMMENT 'Tabla 1. Vacío = comprobante preimpreso'");

-- ---------------------------------------------------------------------------
-- 9. Secuencias e-CF de arranque, DESACTIVADAS
--
--    Se crean apagadas y con rango 0 a propósito: los rangos reales los autoriza
--    la DGII por tipo y hay que cargarlos a mano en Configuración → Comprobantes.
--    Nacer "activas" con un rango inventado sería la forma más rápida de emitir
--    una secuencia no autorizada.
-- ---------------------------------------------------------------------------
INSERT INTO ncf_secuencias (tipo, descripcion, prefijo, secuencia_actual, secuencia_hasta, vencimiento, activo)
SELECT * FROM (
  SELECT 'E31' AS tipo, 'Factura de Crédito Fiscal Electrónica' AS descripcion, 'E' AS prefijo,
         1 AS secuencia_actual, 0 AS secuencia_hasta, NULL AS vencimiento, 0 AS activo
  UNION ALL SELECT 'E32', 'Factura de Consumo Electrónica',  'E', 1, 0, NULL, 0
  UNION ALL SELECT 'E33', 'Nota de Débito Electrónica',      'E', 1, 0, NULL, 0
  UNION ALL SELECT 'E34', 'Nota de Crédito Electrónica',     'E', 1, 0, NULL, 0
) AS nuevas
WHERE NOT EXISTS (SELECT 1 FROM ncf_secuencias s WHERE s.tipo = nuevas.tipo);

-- ---------------------------------------------------------------------------
-- 10. Permisos del módulo
--
--     Sin estas filas el módulo queda inaccesible para todo el mundo menos el
--     super administrador: la pantalla de Roles dibuja las casillas desde
--     `permission_catalog()`, pero al guardar busca el permiso en la tabla
--     (`SELECT id FROM permisos WHERE clave = ?`) y, si no existe, la concesión
--     se pierde en silencio. Se detectó al preparar el despliegue de P16.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('ecf.ver',        'ecf', 'Finanzas', 'Facturación Electrónica (e-CF) — Ver el panel y los comprobantes emitidos'),
 ('ecf.configurar', 'ecf', 'Finanzas', 'Facturación Electrónica (e-CF) — Configurar credenciales, ambiente y secuencias'),
 ('ecf.emitir',     'ecf', 'Finanzas', 'Facturación Electrónica (e-CF) — Emitir, reenviar y consultar comprobantes');

-- Ver el panel va con quien ya lleva la DGII: es la misma persona.
-- CONFIGURAR y EMITIR no se conceden solos: quien cambia el ambiente o los
-- rangos de secuencia puede emitir comprobantes fiscales reales. Se otorgan a
-- mano desde Roles y Permisos.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p  ON p.id = rp.permiso_id
  JOIN permisos p2 ON p2.clave = 'ecf.ver'
 WHERE p.clave = 'dgii.ver';

DROP PROCEDURE IF EXISTS nexo_add_col;
