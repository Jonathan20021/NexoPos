-- ============================================================================
--  NexoPOS · P24 — Amonestaciones y régimen disciplinario
-- ----------------------------------------------------------------------------
--  Una amonestación no es una nota en un cuaderno: es la pieza con la que se
--  arma un expediente. Si el día de mañana hay un despido, lo que decide si
--  estuvo justificado es si existe constancia ESCRITA, con hechos concretos,
--  fechas y la firma del trabajador —o la constancia de que se negó a firmar
--  delante de testigos—.
--
--  ---------------------------------------------------------------------------
--  LOS 15 DÍAS DEL ARTÍCULO 90
--
--  El derecho a despedir por una falta CADUCA a los 15 días de que el empleador
--  tuvo conocimiento de ella. Es el plazo que más se pierde por no mirarlo: se
--  documenta la falta, pasan tres semanas, y para cuando alguien decide actuar
--  ya no se puede alegar esa causa.
--
--  Por eso se guardan DOS fechas distintas —cuándo ocurrió el hecho y cuándo se
--  supo— y el módulo cuenta los días que quedan.
--
--  ---------------------------------------------------------------------------
--  EL CATÁLOGO DE FALTAS ES EDITABLE, A PROPÓSITO
--
--  Se siembra con las faltas más comunes redactadas en lenguaje llano y
--  clasificadas por gravedad. La `referencia_legal` queda como texto libre para
--  que el abogado del cliente escriba el numeral exacto del artículo 88 que
--  aplique: inventar numerales sería peor que dejarlos en blanco.
--
--  Idempotente. Vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS amonestacion_faltas (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    nombre           VARCHAR(180)  NOT NULL,
    gravedad         VARCHAR(12)   NOT NULL DEFAULT 'leve',   -- leve | grave | muy_grave
    referencia_legal VARCHAR(180)  NULL,
    descripcion      TEXT          NULL,
    activo           TINYINT(1)    NOT NULL DEFAULT 1,
    UNIQUE KEY uq_falta_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO amonestacion_faltas (nombre, gravedad, descripcion)
SELECT * FROM (
    SELECT 'Llegadas tarde reiteradas' AS nombre, 'leve' AS gravedad,
           'Incumplimiento del horario acordado en más de una ocasión.' AS descripcion
    UNION ALL SELECT 'Ausencia sin aviso', 'grave',
           'No presentarse a trabajar sin comunicarlo ni justificarlo.'
    UNION ALL SELECT 'Abandono del puesto en horario laboral', 'grave',
           'Dejar el puesto sin autorización durante la jornada.'
    UNION ALL SELECT 'Incumplimiento de instrucciones del supervisor', 'grave',
           'Desobedecer una instrucción legítima relacionada con el trabajo.'
    UNION ALL SELECT 'Trato inadecuado a un cliente', 'grave',
           'Conducta irrespetuosa o negligente en la atención al público.'
    UNION ALL SELECT 'Trato inadecuado a un compañero o superior', 'grave',
           'Falta de respeto, agresión verbal u hostigamiento.'
    UNION ALL SELECT 'Descuido en el manejo de mercancía o equipos', 'grave',
           'Daño o pérdida por negligencia en bienes de la empresa.'
    UNION ALL SELECT 'Incumplimiento de normas de higiene y seguridad', 'grave',
           'No seguir los protocolos que protegen al personal o al producto.'
    UNION ALL SELECT 'Faltante o descuadre de caja no justificado', 'muy_grave',
           'Diferencia en el arqueo sin explicación.'
    UNION ALL SELECT 'Uso indebido de bienes o información de la empresa', 'muy_grave',
           'Aprovechamiento personal de recursos, datos o mercancía.'
    UNION ALL SELECT 'Falsedad en registros o documentos', 'muy_grave',
           'Alterar asistencia, ventas, inventario o cualquier registro.'
    UNION ALL SELECT 'Presentarse en condiciones que impiden trabajar', 'muy_grave',
           'Estado que impide desempeñar la labor con seguridad.'
) AS nuevas
WHERE NOT EXISTS (SELECT 1 FROM amonestacion_faltas f WHERE f.nombre = nuevas.nombre);

CREATE TABLE IF NOT EXISTS amonestaciones (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    numero             VARCHAR(20)   NOT NULL,
    empleado_id        INT UNSIGNED  NOT NULL,
    falta_id           INT           NULL,
    tipo               VARCHAR(16)   NOT NULL DEFAULT 'escrita',  -- verbal | escrita | suspension
    gravedad           VARCHAR(12)   NOT NULL DEFAULT 'leve',

    -- DOS fechas distintas y no una: el plazo del art. 90 corre desde que la
    -- empresa SUPO, no desde que el hecho ocurrió.
    fecha_hecho        DATE          NOT NULL,
    fecha_conocimiento DATE          NOT NULL,
    fecha_emision      DATE          NOT NULL,

    -- Los hechos, en concreto. Una amonestación que dice «mala actitud» no
    -- sostiene nada: hace falta qué pasó, cuándo y dónde.
    hechos             TEXT          NOT NULL,
    referencia_legal   VARCHAR(180)  NULL,
    medida             TEXT          NULL,

    dias_suspension    INT           NOT NULL DEFAULT 0,
    suspension_desde   DATE          NULL,
    suspension_hasta   DATE          NULL,

    -- Descargo del trabajador: su versión. Que exista es lo que convierte el
    -- papel en un expediente y no en una acusación unilateral.
    descargo           TEXT          NULL,
    descargo_at        DATETIME      NULL,

    estado             VARCHAR(24)   NOT NULL DEFAULT 'borrador',
                       -- borrador | notificada | firmada | rehusó_firmar | anulada
    notificada_at      DATETIME      NULL,
    firmada_at         DATETIME      NULL,
    testigo1           VARCHAR(140)  NULL,
    testigo2           VARCHAR(140)  NULL,

    supervisor         VARCHAR(140)  NULL,
    notas              TEXT          NULL,
    usuario_id         INT UNSIGNED  NULL,
    anulada_por        INT UNSIGNED  NULL,
    anulada_at         DATETIME      NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_amon_numero (numero),
    KEY idx_amon_empleado (empleado_id),
    KEY idx_amon_estado (estado),
    KEY idx_amon_fecha (fecha_conocimiento),
    CONSTRAINT fk_amon_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_amon_falta FOREIGN KEY (falta_id) REFERENCES amonestacion_faltas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Permisos
-- ----------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (
    SELECT 'amonestaciones.ver' AS clave, 'amonestaciones' AS modulo, 'Recursos Humanos' AS grupo,
           'Amonestaciones — Ver el expediente disciplinario' AS descripcion
    UNION ALL SELECT 'amonestaciones.crear', 'amonestaciones', 'Recursos Humanos',
           'Amonestaciones — Levantar y notificar'
    UNION ALL SELECT 'amonestaciones.anular', 'amonestaciones', 'Recursos Humanos',
           'Amonestaciones — Anular y dejar sin efecto'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.modulo = 'amonestaciones'
 WHERE r.nombre IN ('Administrador', 'Recursos Humanos')
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- El gerente levanta las de su gente y las consulta, pero no las anula: quien
-- amonesta no debería poder borrar la amonestación.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave IN ('amonestaciones.ver', 'amonestaciones.crear')
 WHERE r.nombre = 'Gerente de Sucursal'
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- ============================================================================
--  Comprobación:
--    SELECT COUNT(*) FROM amonestacion_faltas;              -- 12 faltas sembradas
--    SELECT clave FROM permisos WHERE modulo='amonestaciones';  -- 3
-- ============================================================================
