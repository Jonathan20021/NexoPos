-- ============================================================================
--  NexoPOS · P22 — TSS completa: los topes que faltaban
-- ----------------------------------------------------------------------------
--  La nómina calcula AFP y SFS sobre TODO el salario. La Ley 87-01 no funciona
--  así: cada régimen deja de cotizar por encima de un múltiplo del salario
--  mínimo cotizable.
--
--      SFS  ..... hasta 10 salarios mínimos cotizables
--      AFP  ..... hasta 20
--      SRL  ..... hasta  4   (riesgos laborales, lo paga solo la empresa)
--      INFOTEP .. sin tope: es sobre la nómina, no sobre cada sueldo
--
--  Con una nómina de 1.9 millones y un sueldo de 150,000, esto NO es un detalle
--  académico: hoy se está aportando riesgos laborales sobre 150,000 cuando el
--  tope lo corta mucho antes. El propio código lo tenía anotado como pendiente
--  en COSTO_PENDIENTE_CONFIRMAR.
--
--  ---------------------------------------------------------------------------
--  POR QUÉ UNA TABLA Y NO UNAS CONSTANTES
--
--  El salario mínimo cotizable lo cambia la ley, y cuando cambia NO se puede
--  recalcular hacia atrás: la nómina de marzo cotizó con el mínimo de marzo y
--  así tiene que quedar ante la TSS. Por eso cada juego de parámetros lleva su
--  `vigencia_desde` y nunca se edita el anterior: se añade uno nuevo.
--
--  ---------------------------------------------------------------------------
--  NACE APAGADA, A PROPÓSITO
--
--  `aplicar_topes = 0`. Aplicar un tope con un salario mínimo equivocado
--  cambiaría lo que se le retiene a 57 personas sin que nadie lo haya revisado.
--  La pantalla calcula y ENSEÑA lo que cambiaría, y el contador lo enciende
--  cuando confirme la cifra. Mientras esté apagada, la nómina se comporta
--  exactamente igual que hoy.
--
--  Idempotente. Vale en MariaDB 10.4 y en MySQL 8.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tss_parametros (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    vigencia_desde           DATE         NOT NULL,
    -- El salario mínimo cotizable que publica la TSS. De él salen los topes.
    salario_minimo_cotizable DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Tasas en tanto por uno. Se guardan aunque hoy sean las de la ley general:
    -- si mañana cambia una, no hay que tocar código ni redesplegar.
    sfs_empleado             DECIMAL(6,5)  NOT NULL DEFAULT 0.03040,
    sfs_empleador            DECIMAL(6,5)  NOT NULL DEFAULT 0.07090,
    afp_empleado             DECIMAL(6,5)  NOT NULL DEFAULT 0.02870,
    afp_empleador            DECIMAL(6,5)  NOT NULL DEFAULT 0.07100,
    -- Riesgos laborales: 1% fijo + hasta 0.3% variable según siniestralidad.
    srl_empleador            DECIMAL(6,5)  NOT NULL DEFAULT 0.01100,
    infotep_empleador        DECIMAL(6,5)  NOT NULL DEFAULT 0.01000,

    -- Topes, en MÚLTIPLOS del salario mínimo cotizable. 0 = sin tope.
    tope_sfs_sm              DECIMAL(6,2)  NOT NULL DEFAULT 10.00,
    tope_afp_sm              DECIMAL(6,2)  NOT NULL DEFAULT 20.00,
    tope_srl_sm              DECIMAL(6,2)  NOT NULL DEFAULT 4.00,

    aplicar_topes            TINYINT(1)    NOT NULL DEFAULT 0,
    confirmado_por           VARCHAR(120)  NULL,
    confirmado_at            DATETIME      NULL,
    notas                    TEXT          NULL,
    usuario_id               INT UNSIGNED  NULL,
    created_at               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tss_vigencia (vigencia_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila inicial: las tasas de la ley general y los topes APAGADOS. El salario
-- mínimo cotizable queda en cero justamente para que se note que falta.
INSERT INTO tss_parametros (vigencia_desde, salario_minimo_cotizable, aplicar_topes, notas)
SELECT '2026-01-01', 0.00, 0,
       'Tasas de la Ley 87-01. FALTA el salario mínimo cotizable vigente: sin él no se pueden aplicar los topes. Lo confirma el contador.'
 WHERE NOT EXISTS (SELECT 1 FROM tss_parametros);

-- ----------------------------------------------------------------------------
--  Novedades para el archivo de autodeterminación
--
--  La TSS no solo quiere el sueldo del mes: quiere saber QUÉ pasó con cada
--  persona —entró, salió, le cambió el sueldo, estuvo de licencia— y con qué
--  fecha. Sin esto, el archivo declara una plantilla plana y las altas y bajas
--  se pierden.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tss_novedades (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id   INT UNSIGNED NOT NULL,
    tipo          VARCHAR(24)  NOT NULL,   -- ingreso | salida | cambio_salario | licencia | reingreso
    fecha         DATE         NOT NULL,
    salario_antes DECIMAL(12,2) NULL,
    salario_despues DECIMAL(12,2) NULL,
    dias          DECIMAL(6,2) NULL,
    motivo        VARCHAR(200) NULL,
    -- Se marca cuando ya se declaró, para no repetirla en el archivo siguiente.
    declarada_en  VARCHAR(7)   NULL,        -- 'YYYY-MM'
    usuario_id    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_nov_empleado (empleado_id),
    KEY idx_nov_periodo (declarada_en),
    KEY idx_nov_fecha (fecha),
    CONSTRAINT fk_nov_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Permisos
-- ----------------------------------------------------------------------------
INSERT INTO permisos (clave, modulo, grupo, descripcion)
SELECT * FROM (
    SELECT 'tss.ver' AS clave, 'tss' AS modulo, 'Recursos Humanos' AS grupo,
           'TSS — Ver parámetros, aportes y archivo de autodeterminación' AS descripcion
    UNION ALL SELECT 'tss.configurar', 'tss', 'Recursos Humanos',
           'TSS — Cambiar el salario mínimo cotizable, tasas y topes'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

-- Quien ya administra, administra esto. Ver el reporte también el gerente.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
  ON p.clave IN ('tss.ver', 'tss.configurar')
 WHERE r.nombre = 'Administrador'
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.clave = 'tss.ver'
 WHERE r.nombre IN ('Gerente de Sucursal', 'Recursos Humanos')
   AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- ============================================================================
--  Comprobación:
--    SELECT vigencia_desde, salario_minimo_cotizable, aplicar_topes FROM tss_parametros;
--    -- debe salir una fila con 0.00 y aplicar_topes = 0
--    SELECT clave FROM permisos WHERE modulo = 'tss';
--    -- deben salir tss.ver y tss.configurar
-- ============================================================================
