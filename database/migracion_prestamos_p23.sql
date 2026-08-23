-- ============================================================================
--  NexoPOS · P23 — Préstamos y avances a empleados
-- ----------------------------------------------------------------------------
--  Hoy el préstamo se captura como un número suelto en la columna «otras
--  deducciones» de cada quincena. Eso significa que:
--
--    · nadie sabe cuánto debe realmente una persona,
--    · si se olvida capturarlo una quincena, esa cuota se pierde,
--    · no hay papel que firmar ni cuadro de amortización,
--    · y no hay nada que impida descontar más de lo que la ley permite.
--
--  Esto lo convierte en un préstamo de verdad: monto, cuotas, calendario, saldo
--  vivo y descuento automático en la nómina del período que toca.
--
--  ---------------------------------------------------------------------------
--  EL LÍMITE LEGAL DE DESCUENTO
--
--  El Código de Trabajo protege el salario: las retenciones obligatorias —TSS e
--  ISR— van primero, y lo voluntario solo puede salir de lo que queda. Además
--  hace falta el consentimiento escrito del trabajador, que es el documento que
--  el módulo imprime.
--
--  El PORCENTAJE máximo descontable se guarda como parámetro y no escrito en el
--  código, por dos razones: cambia con la interpretación legal y el cliente
--  tiene que poder ajustarlo con su abogado. Nace en 30% del neto, que es
--  conservador; la pantalla avisa cuando una cuota lo pasa y NO deja guardarla
--  sin que alguien lo autorice a propósito.
--
--  Idempotente. Vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS prestamos (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    numero            VARCHAR(20)   NOT NULL,
    empleado_id       INT UNSIGNED  NOT NULL,
    -- Un avance de sueldo es un préstamo a una sola cuota y sin interés; se
    -- distingue para que el papel diga lo que es.
    tipo              VARCHAR(16)   NOT NULL DEFAULT 'prestamo',  -- prestamo | avance
    monto             DECIMAL(12,2) NOT NULL,
    tasa_anual        DECIMAL(6,3)  NOT NULL DEFAULT 0.000,
    cuotas            INT           NOT NULL DEFAULT 1,
    periodicidad      VARCHAR(12)   NOT NULL DEFAULT 'quincenal', -- quincenal | mensual
    fecha_desembolso  DATE          NOT NULL,
    fecha_primera_cuota DATE        NOT NULL,
    motivo            VARCHAR(255)  NULL,
    -- Saldo VIVO de capital. Se recalcula desde las cuotas, nunca se ajusta a mano.
    saldo             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado            VARCHAR(16)   NOT NULL DEFAULT 'activo',    -- activo | saldado | anulado
    -- Constancia de que la persona autorizó el descuento, que es lo que exige
    -- el Código de Trabajo para retener algo que no sea obligatorio.
    autorizado        TINYINT(1)    NOT NULL DEFAULT 0,
    autorizado_at     DATETIME      NULL,
    -- Se rellena si alguien guardó una cuota por encima del tope legal.
    excede_tope       TINYINT(1)    NOT NULL DEFAULT 0,
    excede_motivo     VARCHAR(255)  NULL,
    notas             TEXT          NULL,
    usuario_id        INT UNSIGNED  NULL,
    anulado_por       INT UNSIGNED  NULL,
    anulado_at        DATETIME      NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prestamo_numero (numero),
    KEY idx_prestamo_empleado (empleado_id),
    KEY idx_prestamo_estado (estado),
    CONSTRAINT fk_prestamo_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  El cuadro de amortización. Se genera al crear el préstamo y NO se recalcula:
--  es lo que la persona firmó.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS prestamo_cuotas (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    prestamo_id        INT           NOT NULL,
    numero             INT           NOT NULL,
    fecha_prevista     DATE          NOT NULL,
    capital            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    interes            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    saldo_despues      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado             VARCHAR(16)   NOT NULL DEFAULT 'pendiente', -- pendiente | descontada | condonada
    -- De qué línea de nómina salió. Es la trazabilidad: sin esto, «pagada» es
    -- una palabra sin respaldo.
    nomina_detalle_id  INT UNSIGNED  NULL,
    descontada_at      DATETIME      NULL,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cuota (prestamo_id, numero),
    KEY idx_cuota_fecha (fecha_prevista, estado),
    KEY idx_cuota_nomina (nomina_detalle_id),
    CONSTRAINT fk_cuota_prestamo FOREIGN KEY (prestamo_id) REFERENCES prestamos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Parámetro del límite legal. Una sola fila.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS prestamo_config (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    -- Porcentaje MÁXIMO del salario neto que puede irse en cuotas de préstamo.
    tope_pct_neto         DECIMAL(5,2)  NOT NULL DEFAULT 30.00,
    -- Suelo absoluto: por debajo de esto no se descuenta aunque el % lo permita.
    neto_minimo_protegido DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    exige_autorizacion    TINYINT(1)    NOT NULL DEFAULT 1,
    notas                 TEXT          NULL,
    updated_at            DATETIME      NULL,
    usuario_id            INT UNSIGNED  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO prestamo_config (tope_pct_neto, neto_minimo_protegido, exige_autorizacion, notas)
SELECT 30.00, 0.00, 1,
       'Tope conservador del 30% del neto. El Código de Trabajo protege el salario y exige consentimiento escrito para retener lo que no es obligatorio; el porcentaje exacto debe confirmarlo el abogado del cliente.'
 WHERE NOT EXISTS (SELECT 1 FROM prestamo_config);

-- ----------------------------------------------------------------------------
--  Permisos
-- ----------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (
    SELECT 'prestamos.ver' AS clave, 'prestamos' AS modulo, 'Recursos Humanos' AS grupo,
           'Préstamos a empleados — Ver y consultar saldos' AS descripcion
    UNION ALL SELECT 'prestamos.crear', 'prestamos', 'Recursos Humanos',
           'Préstamos a empleados — Otorgar y generar el cuadro de amortización'
    UNION ALL SELECT 'prestamos.anular', 'prestamos', 'Recursos Humanos',
           'Préstamos a empleados — Anular o condonar cuotas'
    UNION ALL SELECT 'prestamos.configurar', 'prestamos', 'Recursos Humanos',
           'Préstamos a empleados — Cambiar el tope legal de descuento'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.modulo = 'prestamos'
 WHERE r.nombre = 'Administrador'
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- RRHH otorga y consulta, pero NO anula ni toca el tope legal: quien concede no
-- debería poder borrar la deuda que concedió.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave IN ('prestamos.ver', 'prestamos.crear')
 WHERE r.nombre = 'Recursos Humanos'
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave = 'prestamos.ver'
 WHERE r.nombre = 'Gerente de Sucursal'
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- ============================================================================
--  Comprobación:
--    SELECT tope_pct_neto, exige_autorizacion FROM prestamo_config;  -- 30.00, 1
--    SELECT clave FROM permisos WHERE modulo = 'prestamos';          -- deben salir 4
-- ============================================================================
