-- ---------------------------------------------------------------------------
-- Permiso propio para los informes de nómina y costo de plantilla.
--
-- Vivían dentro de `reportes.contabilidad`, que también abre el libro diario,
-- el balance general, el ITBIS y el auxiliar de cuentas. Quien lleva la nómina
-- y la TSS no necesita nada de eso, y por no dárselo se quedaba sin PODER ABRIR
-- los dos informes que se hicieron para su trabajo: el «Resumen de nómina»
-- —que el propio informe describe como «útil para la TSS y el IR-3»— y el
-- «Costo de la plantilla».
--
-- Se concede al rol de TSS/Nómina y a todo rol que ya podía ver la nómina.
--
-- Idempotente.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO permisos (clave, modulo, grupo, descripcion) VALUES
 ('reportes.nomina', 'reportes', 'Reportes',
  'Centro de Reportes — Resumen de nómina y Costo de la plantilla (sin abrir el resto de contabilidad)');

-- A quien ya podía ver la nómina.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pv ON pv.id = rp.permiso_id AND pv.clave = 'rrhh_nomina.ver'
  CROSS JOIN permisos p
 WHERE p.clave = 'reportes.nomina';

-- Y el permiso de entrada al Centro de Reportes: sin él, el hub no abre y el
-- informe queda inalcanzable aunque tenga su permiso propio.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT rp.rol_id, p.id
  FROM rol_permisos rp
  JOIN permisos pv ON pv.id = rp.permiso_id AND pv.clave = 'rrhh_nomina.ver'
  CROSS JOIN permisos p
 WHERE p.clave = 'reportes.ver';
