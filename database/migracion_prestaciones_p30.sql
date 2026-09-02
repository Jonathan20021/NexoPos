-- ---------------------------------------------------------------------------
-- Liquidación de prestaciones laborales.
--
-- Preaviso, cesantía, vacaciones y regalía proporcional de quien sale. Hasta
-- ahora esto se calculaba en una hoja suelta y se firmaba sin que quedara en el
-- sistema ni el número ni la escala que se aplicó.
--
-- La tabla se llama `prestaciones` y NO `liquidaciones` a propósito: ese nombre
-- ya lo ocupa el costeo de importaciones (modules/inventario/liquidaciones.php),
-- que es otra cosa completamente distinta.
--
-- Todo lo que se necesita para reimprimir el documento queda CONGELADO en la
-- fila —fecha de ingreso, sueldo, salario diario— porque la ficha del empleado
-- cambia y un papel firmado no puede cambiar con ella.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS prestaciones (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  numero            VARCHAR(30)  NOT NULL,
  empleado_id       INT UNSIGNED NOT NULL,
  causa             VARCHAR(40)  NOT NULL,
  fecha_ingreso     DATE         NOT NULL,
  fecha_salida      DATE         NOT NULL,
  dias_servicio     INT          NOT NULL DEFAULT 0,
  salario_mensual   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  salario_diario    DECIMAL(12,2) NOT NULL DEFAULT 0.00,

  preaviso_dias     DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  preaviso_monto    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cesantia_dias     DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  cesantia_monto    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  vacaciones_dias   DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  vacaciones_monto  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  regalia_monto     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  salario_pendiente DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  otros_monto       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  otros_concepto    VARCHAR(160)  NULL,
  -- Lo que se le descuenta: saldo de préstamo, adelantos, faltantes aceptados.
  deducciones       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  deducciones_concepto VARCHAR(200) NULL,

  total             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  estado            ENUM('borrador','firmada','pagada','anulada') NOT NULL DEFAULT 'borrador',
  notas             VARCHAR(500)  NULL,
  pagada_at         DATETIME      NULL,
  cuenta_id         INT UNSIGNED  NULL,
  usuario_id        INT UNSIGNED  NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_prestaciones_numero (numero),
  KEY idx_prestaciones_empleado (empleado_id, fecha_salida),
  KEY idx_prestaciones_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permisos propios: liquidar a alguien no es procesar una quincena.
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('prestaciones.ver',    'prestaciones', 'Recursos Humanos', 'Prestaciones laborales — Ver y calcular liquidaciones'),
 ('prestaciones.crear',  'prestaciones', 'Recursos Humanos', 'Prestaciones laborales — Guardar y firmar una liquidación'),
 ('prestaciones.pagar',  'prestaciones', 'Recursos Humanos', 'Prestaciones laborales — Marcar como pagada'),
 ('prestaciones.anular', 'prestaciones', 'Recursos Humanos', 'Prestaciones laborales — Anular una liquidación');

-- A quien ya lleva la nómina: ver y calcular. Firmar y pagar se conceden aparte.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pv ON pv.id = rp.permiso_id AND pv.clave = 'rrhh_nomina.ver'
  CROSS JOIN permisos p
 WHERE p.clave IN ('prestaciones.ver', 'prestaciones.crear');

-- Y todo a quien administra RRHH por completo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pv ON pv.id = rp.permiso_id AND pv.clave = 'rrhh_nomina.pagar'
  CROSS JOIN permisos p
 WHERE p.clave IN ('prestaciones.pagar', 'prestaciones.anular');

-- La liquidación es dinero que sale y tiene que entrar al resultado con nombre
-- propio: mezclarla con «Nómina» esconde lo que cuestan las salidas del año.
INSERT IGNORE INTO categorias_financieras (tipo, nombre) VALUES
 ('gasto', 'Prestaciones laborales');
