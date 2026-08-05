-- ============================================================================
--  NexoPOS · P14 — Verificación en dos pasos (OTP por correo) al iniciar sesión
-- ----------------------------------------------------------------------------
--  Una contraseña sola ya no basta para entrar a un sistema que mueve dinero,
--  inventario y datos fiscales. Este módulo añade un segundo factor: un código
--  de 6 dígitos que llega al correo del usuario y vive pocos minutos.
--
--  DECISIONES QUE EXPLICAN EL MODELO:
--
--  1. El código NUNCA se guarda en claro. Se guarda su `password_hash()`
--     (bcrypt). Si alguien lee la base, no puede usar los códigos vivos: probar
--     el millón de combinaciones a ~80 ms cada una tarda días, y el código
--     caduca en minutos.
--
--  2. `login_intentos` es una bitácora corta pensada para CONTAR, no para
--     auditar (para eso está `auditoria`). De ahí salen los bloqueos por fuerza
--     bruta: X fallos por usuario y por IP dentro de una ventana de tiempo.
--     Se purga sola.
--
--  3. `login_dispositivos` sostiene el «no volver a pedir el código en este
--     equipo». La cookie lleva un token aleatorio de 32 bytes; aquí solo vive su
--     SHA-256. Robar la base no da acceso; robar la cookie caduca solo.
--
--  4. La política es de la EMPRESA (`empresa.otp_modo`) y se puede apagar por
--     usuario (`usuarios.otp_activo`) para una cuenta de servicio o un equipo
--     sin correo.
--
--  Es idempotente y vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

-- ---------------------------------------------------------------------------
--  Helper: añadir una columna solo si no existe (MySQL 8 no soporta
--  `ADD COLUMN IF NOT EXISTS`, que MariaDB sí).
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS nexo_add_col;
DELIMITER //
CREATE PROCEDURE nexo_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- ---------------------------------------------------------------------------
-- 1. Política de la empresa
--
--    `otp_modo`:
--      siempre           → pide código en cada inicio de sesión (el más seguro)
--      dispositivo_nuevo → solo cuando el equipo no está marcado como de confianza
--      nunca             → desactivado
-- ---------------------------------------------------------------------------
CALL nexo_add_col('empresa', 'otp_modo',          "VARCHAR(20) NOT NULL DEFAULT 'siempre' COMMENT 'siempre | dispositivo_nuevo | nunca'");
CALL nexo_add_col('empresa', 'otp_vigencia_min',  "TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Minutos que vive el código'");
CALL nexo_add_col('empresa', 'otp_recordar_dias', "SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Días que dura un equipo de confianza; 0 = no permitir'");

-- ---------------------------------------------------------------------------
-- 2. Interruptor por usuario.
--
--    Se deja en 1 para todos: activar la seguridad por omisión y desactivarla a
--    mano es el orden correcto. Al revés, nadie la enciende nunca.
-- ---------------------------------------------------------------------------
CALL nexo_add_col('usuarios', 'otp_activo', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Esta cuenta usa verificación en dos pasos'");

-- ---------------------------------------------------------------------------
-- 3. Códigos emitidos.
--
--    Una fila por código. No se borran al usarse: el histórico corto es lo que
--    permite responder «¿desde qué IP pidieron el código de este usuario?».
--    `otp_limpiar()` purga lo que ya no sirve.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_otp (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  proposito VARCHAR(20) NOT NULL DEFAULT 'login',
  codigo_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt del código; jamás el código en claro',
  destino VARCHAR(120) NOT NULL COMMENT 'Correo al que se envió',
  intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_intentos TINYINT UNSIGNED NOT NULL DEFAULT 5,
  enviado TINYINT(1) NOT NULL DEFAULT 0,
  error_envio VARCHAR(255) NULL,
  proveedor_id VARCHAR(80) NULL COMMENT 'Id del mensaje en Resend',
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  ua_hash CHAR(64) NULL COMMENT 'Ata el código al navegador que lo pidió',
  expira_en DATETIME NOT NULL,
  usado_en DATETIME NULL,
  anulado_en DATETIME NULL,
  motivo_anulacion VARCHAR(60) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_otp_usuario (usuario_id, created_at),
  KEY idx_otp_vivo (usuario_id, proposito, usado_en, anulado_en),
  KEY idx_otp_purga (created_at),
  CONSTRAINT fk_otp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Intentos (contador para los bloqueos).
--
--    `clave` es el sujeto del límite: 'ip:186.x.x.x', 'user:12', 'login:admin'.
--    Sin usuario_id obligatorio: también se registran los intentos contra
--    cuentas que no existen, que es justo la señal de un ataque por diccionario.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_intentos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(20) NOT NULL COMMENT 'password | otp | envio',
  clave VARCHAR(190) NOT NULL,
  exito TINYINT(1) NOT NULL DEFAULT 0,
  usuario_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  detalle VARCHAR(120) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_li_ventana (tipo, clave, exito, created_at),
  KEY idx_li_purga (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Equipos de confianza.
--
--    UNIQUE sobre el hash del token: dos equipos no pueden compartir cookie, y
--    un token robado y ya rotado deja de valer.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_dispositivos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 del token que viaja en la cookie',
  nombre VARCHAR(80) NOT NULL COMMENT 'Chrome en Windows, Safari en iPhone…',
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  ultimo_uso DATETIME NULL,
  expira_en DATETIME NOT NULL,
  revocado_en DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_disp_token (token_hash),
  KEY idx_disp_usuario (usuario_id, revocado_en, expira_en),
  CONSTRAINT fk_disp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Permiso del panel de seguridad
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('seguridad.gestionar', 'seguridad', 'Administración', 'Seguridad de acceso — Cambiar la política de verificación y revocar equipos');

-- Se concede a quien ya puede editar la configuración del sistema.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p2.id
  FROM rol_permisos rp
  JOIN permisos p  ON p.id = rp.permiso_id
  JOIN permisos p2 ON p2.clave = 'seguridad.gestionar'
 WHERE p.clave = 'configuracion.editar';

DROP PROCEDURE IF EXISTS nexo_add_col;
