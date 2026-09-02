-- ---------------------------------------------------------------------------
-- El pago mensual de la TSS y del ISR retenido.
--
-- ============================================================================
--  EL AGUJERO QUE CIERRA
-- ============================================================================
--
-- Al pagar una nómina el sistema registraba un gasto por el NETO. Pero el costo
-- de una nómina no es el neto:
--
--   · lo que se le RETIENE a la gente (AFP, SFS e ISR) es dinero que la empresa
--     guarda y tiene que remitir a la TSS y a la DGII, y
--   · el APORTE PATRONAL (SFS 7.09%, AFP 7.10%, riesgos 1.10%, INFOTEP 1%) sale
--     íntegro del bolsillo de la empresa y no aparecía por ningún lado.
--
-- Medido sobre la segunda quincena de julio de 2026 del padrón real: costo real
-- 1,105,895.70 y en el resultado entraban 877,721.39. Faltaba el 20.6%, unos
-- 228,174 por quincena.
--
-- `transacciones` es un libro de CAJA: el gasto entra cuando el dinero sale. Así
-- que no se inventa un asiento de devengo; lo que faltaba era la pantalla donde
-- se registra que se pagó a la TSS y a la DGII. Esta tabla guarda esos pagos.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tss_pagos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  periodo      CHAR(7)      NOT NULL,               -- 'YYYY-MM'
  -- 'tss'  = retención AFP/SFS del empleado + aporte patronal → Tesorería
  -- 'isr'  = ISR retenido a los asalariados → DGII (IR-3)
  tipo         ENUM('tss','isr') NOT NULL,
  monto        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  -- Congelado en la fila: el desglose que se declaró ese mes. Recalcularlo
  -- después daría otro número si cambian los parámetros o se toca una nómina.
  ret_empleado DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  aporte_patronal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  fecha_pago   DATE         NOT NULL,
  cuenta_id    INT UNSIGNED NULL,
  referencia   VARCHAR(60)  NULL,                   -- núm. de recibo o comprobante
  notas        VARCHAR(300) NULL,
  usuario_id   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Un solo pago por período y tipo: pagar dos veces la TSS de agosto es un
  -- error de dedo, no un caso de uso.
  UNIQUE KEY uq_tss_pago (periodo, tipo),
  KEY idx_tss_pago_fecha (fecha_pago)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categorías de gasto propias: mezclarlas con «Nómina» impide ver en el estado
-- de resultados cuánto cuesta la seguridad social, que es una cifra que la
-- dirección pregunta sola.
INSERT IGNORE INTO categorias_financieras (tipo, nombre) VALUES
 ('gasto', 'Seguridad Social (TSS)'),
 ('gasto', 'Retenciones e impuestos');

-- Permiso propio: declarar y pagar no es lo mismo que mirar la declaración.
INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('tss.pagar', 'tss', 'Recursos Humanos',
  'TSS — Registrar el pago mensual a la Tesorería y del ISR retenido');

-- A quien ya puede pagar la nómina.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pv ON pv.id = rp.permiso_id AND pv.clave = 'rrhh_nomina.pagar'
  CROSS JOIN permisos p
 WHERE p.clave = 'tss.pagar';
