-- ============================================================
--  NexoPOS — Esquema de base de datos
--  Sistema multi-sucursal: Inventario, POS, RRHH, Finanzas
--  MySQL / MariaDB · InnoDB · utf8mb4
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ===================== CONFIGURACIÓN =====================
DROP TABLE IF EXISTS empresa;
CREATE TABLE empresa (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(150) NOT NULL DEFAULT 'Mi Empresa',
  rnc VARCHAR(30) NULL,
  direccion VARCHAR(255) NULL,
  telefono VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  moneda VARCHAR(10) NOT NULL DEFAULT 'RD$',
  itbis_tasa DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  logo VARCHAR(255) NULL,
  -- Diseño de los correos (Marketing → Diseño del correo). Afecta a TODOS los
  -- envíos, incluidos los de pedidos de la tienda.
  mkt_color        VARCHAR(7)   NOT NULL DEFAULT '#15803D',  -- barra superior
  mkt_color_boton  VARCHAR(7)   NOT NULL DEFAULT '#15803D',  -- botones de acción
  mkt_fondo        VARCHAR(7)   NOT NULL DEFAULT '#F1F5F9',  -- fondo (neutro: sirve con cualquier marca)
  mkt_mostrar_logo TINYINT(1)   NOT NULL DEFAULT 1,
  mkt_pie          VARCHAR(255) NULL,
  -- Verificación en dos pasos al iniciar sesión (ver docs/OTP-LOGIN.md).
  otp_modo         VARCHAR(20)      NOT NULL DEFAULT 'siempre',  -- siempre | dispositivo_nuevo | nunca
  otp_vigencia_min TINYINT UNSIGNED NOT NULL DEFAULT 10,         -- minutos que vive el código
  otp_recordar_dias SMALLINT UNSIGNED NOT NULL DEFAULT 30,       -- días de un equipo de confianza; 0 = no permitir
  mensaje_ticket VARCHAR(255) NULL DEFAULT '¡Gracias por su compra!',
  link_pago VARCHAR(255) NULL,                  -- se envía al cliente por WhatsApp
  tienda_activa TINYINT(1) NOT NULL DEFAULT 1,  -- interruptor general de la tienda pública
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== SUCURSALES =====================
DROP TABLE IF EXISTS sucursales;
CREATE TABLE sucursales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  direccion VARCHAR(255) NULL,
  telefono VARCHAR(40) NULL,
  whatsapp VARCHAR(20) NULL,          -- número de la tienda online (con código de país)
  horario VARCHAR(120) NULL,          -- se muestra al cliente en la tienda
  email VARCHAR(120) NULL,
  encargado VARCHAR(120) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  tienda_activa TINYINT(1) NOT NULL DEFAULT 1,  -- visible en la tienda pública
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sucursal_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== TIENDAS (MARCAS COMERCIALES) =====================
-- La empresa distribuye varias marcas y cada una se presenta al cliente con su
-- propia cara: la factura de L'Occitane lleva el logo de L'Occitane.
--
-- Tienda ≠ sucursal, y son independientes a propósito. Sucursal = DÓNDE se
-- vende (stock, caja, usuarios). Tienda = CON QUÉ MARCA se vende. Un local
-- puede atender dos marcas y una marca puede estar en varios locales.
--
-- El emisor fiscal sigue siendo la empresa: un solo RNC y una sola secuencia de
-- NCF. La tienda pone la marca en el papel, no en la declaración.
-- Ver docs/TIENDAS-Y-DIRECCION.md.
DROP TABLE IF EXISTS tiendas;
CREATE TABLE tiendas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(120) NOT NULL,           -- nombre comercial: el que ve el cliente
  razon_social VARCHAR(180) NULL,         -- solo si difiere del nombre comercial
  rnc VARCHAR(30) NULL,                   -- informativo; el emisor fiscal es la empresa
  direccion VARCHAR(255) NULL,
  ciudad VARCHAR(80) NULL,
  telefono VARCHAR(40) NULL,
  whatsapp VARCHAR(20) NULL,
  email VARCHAR(120) NULL,
  sitio_web VARCHAR(140) NULL,
  logo VARCHAR(255) NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#2563eb',  -- acento de la factura y el ticket
  encabezado VARCHAR(140) NULL,           -- línea bajo el nombre en el ticket
  mensaje_ticket VARCHAR(255) NULL,
  politica_devolucion TEXT NULL,          -- se imprime al pie (PROCONSUMIDOR)
  pie_factura VARCHAR(255) NULL,
  orden SMALLINT NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tienda_codigo (codigo),
  KEY idx_tienda_activo (activo, orden, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== ROLES Y PERMISOS =====================
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(80) NOT NULL,
  descripcion VARCHAR(255) NULL,
  es_super TINYINT(1) NOT NULL DEFAULT 0,
  es_sistema TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rol_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS permisos;
CREATE TABLE permisos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave VARCHAR(80) NOT NULL,
  modulo VARCHAR(50) NOT NULL,
  grupo VARCHAR(50) NOT NULL,
  descripcion VARCHAR(150) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permiso_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS rol_permisos;
CREATE TABLE rol_permisos (
  rol_id INT UNSIGNED NOT NULL,
  permiso_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  KEY idx_rp_permiso (permiso_id),
  CONSTRAINT fk_rp_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== USUARIOS =====================
DROP TABLE IF EXISTS usuarios;
CREATE TABLE usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NULL,           -- NULL = acceso a todas las sucursales
  rol_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  apellido VARCHAR(80) NOT NULL,
  usuario VARCHAR(60) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  telefono VARCHAR(40) NULL,
  avatar VARCHAR(255) NULL,
  comision_pct DECIMAL(5,2) NOT NULL DEFAULT 0,   -- % de comisión sobre sus ventas
  otp_activo TINYINT(1) NOT NULL DEFAULT 1,       -- pide código de verificación al entrar
  activo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acceso DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario (usuario),
  UNIQUE KEY uq_email (email),
  KEY idx_u_sucursal (sucursal_id),
  KEY idx_u_rol (rol_id),
  CONSTRAINT fk_u_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL,
  CONSTRAINT fk_u_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== AUDITORÍA =====================
DROP TABLE IF EXISTS auditoria;
CREATE TABLE auditoria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NULL,
  usuario_nombre VARCHAR(160) NULL,
  sucursal_id INT UNSIGNED NULL,
  modulo VARCHAR(50) NOT NULL,
  accion VARCHAR(50) NOT NULL,
  descripcion VARCHAR(255) NULL,
  tabla_afectada VARCHAR(60) NULL,
  registro_id VARCHAR(40) NULL,
  datos_anteriores TEXT NULL,
  datos_nuevos TEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_fecha (created_at),
  KEY idx_audit_modulo (modulo),
  KEY idx_audit_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== INVENTARIO: CATÁLOGOS =====================
DROP TABLE IF EXISTS categorias;
CREATE TABLE categorias (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  color VARCHAR(20) NOT NULL DEFAULT 'blue',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categoria (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS marcas;
CREATE TABLE marcas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marca (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS unidades;
CREATE TABLE unidades (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(50) NOT NULL,
  abreviatura VARCHAR(10) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS proveedores;
CREATE TABLE proveedores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  rnc VARCHAR(30) NULL,
  tipo_id TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- DGII 606 col.2: 1=RNC, 2=Cédula
  contacto VARCHAR(120) NULL,
  telefono VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  direccion VARCHAR(255) NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,   -- lo que se le debe (cuentas por pagar)
  activo TINYINT(1) NOT NULL DEFAULT 1,
  -- Ficha sanitaria: el inspector pregunta a quién se le compra la mercancía
  -- regulada y si ese proveedor está habilitado.
  licencia_sanitaria VARCHAR(60) NULL,
  licencia_vencimiento DATE NULL,
  pais_origen VARCHAR(60) NULL,
  notas_sanitarias VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proveedor_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== PRODUCTOS =====================
DROP TABLE IF EXISTS productos;
CREATE TABLE productos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(40) NOT NULL,             -- SKU
  codigo_barras VARCHAR(60) NULL,
  nombre VARCHAR(180) NOT NULL,
  descripcion VARCHAR(255) NULL,
  categoria_id INT UNSIGNED NULL,
  marca_id INT UNSIGNED NULL,
  unidad_id INT UNSIGNED NULL,
  tienda_id INT UNSIGNED NULL,             -- marca comercial (NULL = sin marca asignada)
  tipo ENUM('producto','servicio') NOT NULL DEFAULT 'producto',
  precio_compra DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_venta DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis_aplica TINYINT(1) NOT NULL DEFAULT 1,
  stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0,
  imagen VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  -- ===== Ficha sanitaria (ver docs/SANIDAD-Y-AUDITORIAS.md) =====
  -- El control se activa producto a producto: un tornillo no lleva registro,
  -- una crema sí. Así el peso operativo cae solo donde la ley lo exige.
  regulado TINYINT(1) NOT NULL DEFAULT 0,             -- sujeto a control sanitario
  controla_lote TINYINT(1) NOT NULL DEFAULT 0,        -- exige lote y vencimiento al entrar
  registro_sanitario VARCHAR(60) NULL,
  registro_entidad VARCHAR(40) NULL,                  -- DIGEMAPS, Agricultura, INDOCAL…
  registro_categoria VARCHAR(40) NULL,                -- cosmetico, higiene, suplemento…
  registro_emision DATE NULL,
  registro_vencimiento DATE NULL,
  registro_titular VARCHAR(180) NULL,
  fabricante VARCHAR(180) NULL,
  pais_origen VARCHAR(60) NULL,
  vida_util_dias INT UNSIGNED NULL,                   -- sugiere el vencimiento al capturar un lote
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_p_regulado (regulado, registro_vencimiento),
  UNIQUE KEY uq_producto_codigo (codigo),
  -- Único, no un índice normal: dos productos con el mismo código de barras
  -- harían que el escáner cobre el artículo equivocado. Los NULL no chocan
  -- entre sí, así que la mercancía sin código convive sin problema.
  UNIQUE KEY uq_producto_barras (codigo_barras),
  KEY idx_p_categoria (categoria_id),
  KEY idx_p_nombre (nombre),
  KEY idx_p_activo_nombre (activo, nombre),
  KEY idx_p_tienda (tienda_id, activo),
  CONSTRAINT chk_producto_valores_no_negativos CHECK (precio_compra >= 0 AND precio_venta >= 0 AND stock_minimo >= 0),
  CONSTRAINT fk_p_tienda FOREIGN KEY (tienda_id) REFERENCES tiendas(id) ON DELETE SET NULL,
  CONSTRAINT fk_p_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
  CONSTRAINT fk_p_marca FOREIGN KEY (marca_id) REFERENCES marcas(id) ON DELETE SET NULL,
  CONSTRAINT fk_p_unidad FOREIGN KEY (unidad_id) REFERENCES unidades(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock por sucursal
DROP TABLE IF EXISTS inventario_stock;
CREATE TABLE inventario_stock (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock (producto_id, sucursal_id),
  KEY idx_st_sucursal (sucursal_id),
  CONSTRAINT chk_stock_no_negativo CHECK (cantidad >= 0),
  CONSTRAINT fk_st_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  CONSTRAINT fk_st_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kardex / movimientos de inventario
DROP TABLE IF EXISTS movimientos_inventario;
CREATE TABLE movimientos_inventario (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','salida','ajuste','compra','venta','devolucion','transferencia_salida','transferencia_entrada') NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  stock_anterior DECIMAL(12,3) NOT NULL DEFAULT 0,
  stock_nuevo DECIMAL(12,3) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  referencia_tipo VARCHAR(30) NULL,
  referencia_id INT UNSIGNED NULL,
  motivo VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mov_producto (producto_id),
  KEY idx_mov_sucursal (sucursal_id),
  KEY idx_mov_fecha (created_at),
  KEY idx_mov_prod_suc (producto_id, sucursal_id),
  CONSTRAINT fk_mov_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  CONSTRAINT fk_mov_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== COMPRAS =====================
DROP TABLE IF EXISTS compras;
CREATE TABLE compras (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  -- ===== Campos del Formato 606 de la DGII =====
  ncf VARCHAR(19) NULL,                 -- col.4  comprobante emitido por el proveedor
  ncf_modificado VARCHAR(19) NULL,      -- col.5  NCF afectado por nota de crédito/débito
  sucursal_id INT UNSIGNED NOT NULL,
  tienda_id INT UNSIGNED NULL,          -- marca a la que se le compró la mercancía
  proveedor_id INT UNSIGNED NULL,
  moneda_id    INT UNSIGNED NULL,              -- moneda de la factura del proveedor
  tasa_cambio  DECIMAL(14,6) NOT NULL DEFAULT 1,
  saldo        DECIMAL(12,2) NOT NULL DEFAULT 0,   -- pendiente de pago, en pesos
  saldo_moneda DECIMAL(14,2) NOT NULL DEFAULT 0,   -- el mismo pendiente en su moneda
  tipo_bien_servicio TINYINT UNSIGNED NULL, -- col.3  catálogo 1..11
  fecha DATE NOT NULL,
  fecha_comprobante DATE NULL,          -- col.6
  fecha_pago DATE NULL,                 -- col.7  obligatoria si hay retenciones
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  monto_bienes DECIMAL(12,2) NOT NULL DEFAULT 0,     -- col.9
  monto_servicios DECIMAL(12,2) NOT NULL DEFAULT 0,  -- col.8
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,            -- col.11
  itbis_retenido DECIMAL(12,2) NOT NULL DEFAULT 0,        -- col.12
  itbis_proporcionalidad DECIMAL(12,2) NOT NULL DEFAULT 0,-- col.13
  itbis_costo DECIMAL(12,2) NOT NULL DEFAULT 0,           -- col.14
  itbis_percibido DECIMAL(12,2) NOT NULL DEFAULT 0,       -- col.16
  tipo_retencion_isr TINYINT UNSIGNED NULL,               -- col.17 catálogo 1..9
  monto_retencion_renta DECIMAL(12,2) NOT NULL DEFAULT 0, -- col.18
  isr_percibido DECIMAL(12,2) NOT NULL DEFAULT 0,         -- col.19
  impuesto_selectivo DECIMAL(12,2) NOT NULL DEFAULT 0,    -- col.20
  otros_impuestos DECIMAL(12,2) NOT NULL DEFAULT 0,       -- col.21
  propina_legal DECIMAL(12,2) NOT NULL DEFAULT 0,         -- col.22
  forma_pago TINYINT UNSIGNED NULL,                       -- col.23 catálogo 1..7
  descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_moneda DECIMAL(14,2) NULL,             -- total pactado en la moneda de la factura
  estado ENUM('pendiente','recibida','anulada') NOT NULL DEFAULT 'recibida',
  notas VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_compra_numero (numero),
  KEY idx_compras_ncf (ncf),
  KEY idx_compras_comprobante (fecha_comprobante),
  KEY idx_c_sucursal (sucursal_id),
  KEY idx_c_tienda (tienda_id, fecha),
  CONSTRAINT fk_c_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
  CONSTRAINT fk_c_tienda FOREIGN KEY (tienda_id) REFERENCES tiendas(id) ON DELETE SET NULL,
  CONSTRAINT fk_c_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS compra_detalles;
CREATE TABLE compra_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  costo_unitario DECIMAL(12,2) NOT NULL,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cd_compra (compra_id),
  CONSTRAINT chk_compra_detalle_valores CHECK (cantidad > 0 AND costo_unitario > 0 AND itbis >= 0 AND subtotal >= 0),
  CONSTRAINT fk_cd_compra FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
  CONSTRAINT fk_cd_producto FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== TRANSFERENCIAS ENTRE SUCURSALES =====================
DROP TABLE IF EXISTS transferencias;
CREATE TABLE transferencias (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  sucursal_origen_id INT UNSIGNED NOT NULL,
  sucursal_destino_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  estado ENUM('borrador','pendiente','enviada','recibida','rechazada','anulada') NOT NULL DEFAULT 'borrador',
  notas VARCHAR(255) NULL,
  motivo_rechazo VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  enviada_por INT UNSIGNED NULL,
  enviada_at DATETIME NULL,
  recibida_por INT UNSIGNED NULL,
  recibida_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transf_numero (numero),
  CONSTRAINT fk_t_origen FOREIGN KEY (sucursal_origen_id) REFERENCES sucursales(id),
  CONSTRAINT fk_t_destino FOREIGN KEY (sucursal_destino_id) REFERENCES sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS transferencia_detalles;
CREATE TABLE transferencia_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transferencia_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_td_transf (transferencia_id),
  CONSTRAINT chk_transferencia_cantidad_positiva CHECK (cantidad > 0),
  CONSTRAINT fk_td_transf FOREIGN KEY (transferencia_id) REFERENCES transferencias(id) ON DELETE CASCADE,
  CONSTRAINT fk_td_producto FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== CLIENTES =====================
DROP TABLE IF EXISTS clientes;
CREATE TABLE clientes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  rnc_cedula VARCHAR(30) NULL,
  tipo_id TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- DGII 607 col.2: 1=RNC, 2=Cédula, 3=Pasaporte
  telefono VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  direccion VARCHAR(255) NULL,
  fecha_nacimiento DATE NULL,                    -- habilita la felicitación automática de cumpleaños
  acepta_marketing TINYINT(1) NOT NULL DEFAULT 1, -- 0 = pidió no recibir promociones (opt-out)
  tipo ENUM('contado','credito') NOT NULL DEFAULT 'contado',
  limite_credito DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,                  -- usuario que registró el cliente (trazabilidad)
  importacion_id INT UNSIGNED NULL,              -- lote de carga histórica que lo creó
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cliente_codigo (codigo),
  KEY idx_cli_nombre (nombre),
  KEY idx_cli_cumple (fecha_nacimiento),
  KEY idx_cli_importacion (importacion_id),
  CONSTRAINT chk_cliente_credito_no_negativo CHECK (limite_credito >= 0 AND balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Abonos / pagos de clientes a crédito (cuentas por cobrar)
DROP TABLE IF EXISTS pagos_clientes;
CREATE TABLE pagos_clientes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NULL,
  monto DECIMAL(12,2) NOT NULL,
  metodo_pago_id INT UNSIGNED NULL,
  notas VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  fecha DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pc_cliente (cliente_id),
  CONSTRAINT fk_pc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== POS / CAJA =====================
DROP TABLE IF EXISTS metodos_pago;
CREATE TABLE metodos_pago (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(50) NOT NULL,
  afecta_caja TINYINT(1) NOT NULL DEFAULT 1,   -- efectivo afecta el conteo de caja
  es_credito TINYINT(1) NOT NULL DEFAULT 0,    -- venta a crédito (genera cuenta por cobrar)
  -- DGII 607 col.17-23: 1 Efectivo, 2 Cheque/Transf/Depósito, 3 Tarjeta, 4 Crédito,
  -- 5 Bonos, 6 Permuta, 7 Otras formas.
  dgii_tipo_pago TINYINT UNSIGNED NOT NULL DEFAULT 7,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_metodo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cajas;
CREATE TABLE cajas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(60) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_caja_sucursal (sucursal_id),
  CONSTRAINT fk_caja_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS caja_sesiones;
CREATE TABLE caja_sesiones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  caja_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  turno VARCHAR(50) NULL,                            -- Mañana / Tarde / Noche (clasificación y filtro)
  monto_apertura DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_ventas DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_efectivo DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_tarjeta DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_otros DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_ingresos DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_egresos DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_devoluciones DECIMAL(12,2) NOT NULL DEFAULT 0,
  efectivo_esperado DECIMAL(12,2) NOT NULL DEFAULT 0,
  monto_cierre_real DECIMAL(12,2) NULL,
  diferencia DECIMAL(12,2) NULL,
  estado ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
  notas VARCHAR(255) NULL,
  abierta_at DATETIME NOT NULL,
  cerrada_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cs_sucursal (sucursal_id),
  KEY idx_cs_estado (estado),
  KEY idx_cs_caja_estado (caja_id, estado),
  CONSTRAINT fk_cs_caja FOREIGN KEY (caja_id) REFERENCES cajas(id),
  CONSTRAINT fk_cs_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
  CONSTRAINT fk_cs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS caja_movimientos;
CREATE TABLE caja_movimientos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  caja_sesion_id INT UNSIGNED NOT NULL,
  tipo ENUM('ingreso','egreso') NOT NULL,
  concepto VARCHAR(150) NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm_sesion (caja_sesion_id),
  CONSTRAINT fk_cm_sesion FOREIGN KEY (caja_sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Secuencias de NCF (comprobantes fiscales RD)
DROP TABLE IF EXISTS ncf_secuencias;
CREATE TABLE ncf_secuencias (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(10) NOT NULL,               -- B01 (crédito fiscal), B02 (consumidor final)
  descripcion VARCHAR(80) NULL,
  prefijo VARCHAR(5) NOT NULL DEFAULT 'B',
  secuencia_actual INT UNSIGNED NOT NULL DEFAULT 1,
  secuencia_hasta INT UNSIGNED NOT NULL DEFAULT 99999999,
  vencimiento DATE NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ncf_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Terminales del POS (modo offline Fase 2): identidad por token de dispositivo.
DROP TABLE IF EXISTS pos_terminales;
CREATE TABLE pos_terminales (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_token CHAR(36) NOT NULL,                 -- generado y guardado en el navegador
  nombre       VARCHAR(80) NULL,
  sucursal_id  INT UNSIGNED NULL,
  ultimo_visto DATETIME NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_terminal_token (device_token),
  KEY idx_terminal_sucursal (sucursal_id),
  CONSTRAINT fk_terminal_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rangos de NCF reservados (delegados) a un terminal para imprimir el comprobante
-- fiscal definitivo estando offline. Se tallan del maestro ncf_secuencias.
DROP TABLE IF EXISTS ncf_reservas;
CREATE TABLE ncf_reservas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  terminal_id     INT UNSIGNED NOT NULL,
  secuencia_id    INT UNSIGNED NOT NULL,          -- de qué secuencia maestra se talló
  tipo            VARCHAR(10) NOT NULL,            -- B01, B02
  prefijo         VARCHAR(5)  NOT NULL DEFAULT 'B',
  secuencia_desde INT UNSIGNED NOT NULL,
  secuencia_hasta INT UNSIGNED NOT NULL,          -- rango inclusivo [desde, hasta]
  vencimiento     DATE NULL,
  estado          ENUM('activa','devuelta','vencida') NOT NULL DEFAULT 'activa',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reserva_terminal (terminal_id, tipo, estado),
  KEY idx_reserva_rango (tipo, secuencia_desde, secuencia_hasta),
  CONSTRAINT fk_reserva_terminal FOREIGN KEY (terminal_id) REFERENCES pos_terminales(id) ON DELETE CASCADE,
  CONSTRAINT fk_reserva_secuencia FOREIGN KEY (secuencia_id) REFERENCES ncf_secuencias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== VENTAS =====================
DROP TABLE IF EXISTS ventas;
CREATE TABLE ventas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  uuid CHAR(36) NULL,                    -- identidad idempotente para ventas creadas offline (sync)
  sucursal_id INT UNSIGNED NOT NULL,
  tienda_id INT UNSIGNED NULL,           -- marca con la que se facturó (congelada: no se deduce del producto)
  importacion_id INT UNSIGNED NULL,      -- lote de carga histórica que la creó (NULL = venta real del sistema)
  caja_sesion_id INT UNSIGNED NULL,
  cliente_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NOT NULL,
  fecha DATETIME NOT NULL,
  fecha_retencion DATE NULL,            -- DGII 607 col.7
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,                    -- col.9
  itbis_retenido_terceros DECIMAL(12,2) NOT NULL DEFAULT 0,  -- col.10
  itbis_percibido DECIMAL(12,2) NOT NULL DEFAULT 0,          -- col.11
  retencion_renta_terceros DECIMAL(12,2) NOT NULL DEFAULT 0, -- col.12
  isr_percibido DECIMAL(12,2) NOT NULL DEFAULT 0,            -- col.13
  impuesto_selectivo DECIMAL(12,2) NOT NULL DEFAULT 0,       -- col.14
  otros_impuestos DECIMAL(12,2) NOT NULL DEFAULT 0,          -- col.15
  propina_legal DECIMAL(12,2) NOT NULL DEFAULT 0,            -- col.16
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  tipo_comprobante ENUM('consumidor','credito_fiscal') NOT NULL DEFAULT 'consumidor',
  ncf VARCHAR(20) NULL,                 -- col.3
  ncf_modificado VARCHAR(19) NULL,      -- col.4
  tipo_ingreso TINYINT UNSIGNED NOT NULL DEFAULT 1, -- col.5 catálogo 1..6
  estado ENUM('completada','anulada','devuelta') NOT NULL DEFAULT 'completada',
  notas VARCHAR(255) NULL,
  canal_venta VARCHAR(40) NULL,                     -- canal de captación (marketing): Instagram, WhatsApp, Mostrador...
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venta_numero (numero),
  UNIQUE KEY uq_ventas_uuid (uuid),
  UNIQUE KEY uq_ventas_ncf (ncf),
  KEY idx_ventas_canal (canal_venta),
  KEY idx_v_sucursal (sucursal_id),
  KEY idx_v_fecha (fecha),
  KEY idx_v_estado_fecha (estado, fecha),
  KEY idx_v_cliente (cliente_id),
  KEY idx_v_cliente_estado (cliente_id, estado, fecha),
  KEY idx_v_sesion (caja_sesion_id),
  KEY idx_v_tienda (tienda_id, fecha),
  KEY idx_v_importacion (importacion_id),
  -- El panel de Dirección barre 24 meses agrupando por mes y por marca.
  KEY idx_v_estado_fecha_tienda (estado, fecha, tienda_id),
  CONSTRAINT fk_v_tienda FOREIGN KEY (tienda_id) REFERENCES tiendas(id) ON DELETE SET NULL,
  CONSTRAINT fk_v_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
  CONSTRAINT fk_v_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_v_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS venta_detalles;
CREATE TABLE venta_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NULL,
  descripcion VARCHAR(180) NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  precio_unitario DECIMAL(12,2) NOT NULL,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL,
  es_muestra TINYINT(1) NOT NULL DEFAULT 0,          -- línea entregada como muestra (RD$0.00)
  precio_original DECIMAL(12,2) NOT NULL DEFAULT 0,  -- precio real de la muestra (trazabilidad)
  PRIMARY KEY (id),
  KEY idx_vd_venta (venta_id),
  KEY idx_vd_producto (producto_id),
  KEY idx_vd_producto_venta (producto_id, venta_id),
  -- Cobertura: los reportes entran por `ventas` filtrando fecha y saltan aquí por
  -- venta_id. Con estas columnas dentro del índice la unión no toca la tabla.
  -- Sin él, el top de productos del dashboard pasa de 318 ms a 3 segundos con
  -- un mes de 10.800 ventas. Ver database/migracion_rendimiento_p8.sql.
  KEY idx_vd_venta_cobertura (venta_id, producto_id, cantidad, subtotal, descuento),
  CONSTRAINT chk_venta_detalle_valores CHECK (cantidad > 0 AND precio_unitario >= 0 AND costo_unitario >= 0 AND descuento >= 0 AND itbis >= 0 AND subtotal >= 0),
  CONSTRAINT fk_vd_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
  CONSTRAINT fk_vd_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS venta_pagos;
CREATE TABLE venta_pagos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venta_id INT UNSIGNED NOT NULL,
  metodo_pago_id INT UNSIGNED NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  referencia VARCHAR(60) NULL,
  PRIMARY KEY (id),
  KEY idx_vp_venta (venta_id),
  CONSTRAINT fk_vp_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
  CONSTRAINT fk_vp_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS devoluciones;
CREATE TABLE devoluciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  venta_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  motivo VARCHAR(255) NULL,
  ncf VARCHAR(19) NULL,               -- NCF de la nota de crédito (B04)
  ncf_modificado VARCHAR(19) NULL,    -- NCF de la venta que corrige
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dev_numero (numero),
  KEY idx_dev_venta (venta_id),
  KEY idx_dev_ncf (ncf),
  CONSTRAINT fk_dev_venta FOREIGN KEY (venta_id) REFERENCES ventas(id),
  CONSTRAINT fk_dev_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS devolucion_detalles;
CREATE TABLE devolucion_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  devolucion_id INT UNSIGNED NOT NULL,
  venta_detalle_id INT UNSIGNED NULL,
  producto_id INT UNSIGNED NULL,
  descripcion VARCHAR(180) NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  precio_unitario DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_dd_dev (devolucion_id),
  KEY idx_dd_venta_detalle (venta_detalle_id),
  CONSTRAINT chk_devolucion_detalle_valores CHECK (cantidad > 0 AND precio_unitario >= 0 AND subtotal >= 0),
  CONSTRAINT fk_dd_dev FOREIGN KEY (devolucion_id) REFERENCES devoluciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_dd_venta_detalle FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== TIENDA ONLINE =====================
-- Un pedido NO descuenta stock: es una solicitud. El inventario se mueve cuando
-- el pedido se convierte en venta desde el POS.
-- token: identificador público; el cliente consulta su pedido sin autenticarse.
DROP TABLE IF EXISTS pedido_detalles;
DROP TABLE IF EXISTS pedidos;
CREATE TABLE pedidos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  token CHAR(32) NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  cliente_nombre VARCHAR(150) NOT NULL,
  cliente_telefono VARCHAR(40) NOT NULL,
  cliente_email VARCHAR(180) NULL,
  cliente_documento VARCHAR(30) NULL,
  notas VARCHAR(500) NULL,
  metodo_pago ENUM('pickup','link_pago') NOT NULL DEFAULT 'pickup',
  -- Cada pedido lleva su propio enlace: el monto cambia en cada venta.
  link_pago VARCHAR(500) NULL,
  link_pago_enviado_at DATETIME NULL,
  -- Sin esta confirmación un pedido con link de pago no avanza ni se factura.
  pago_confirmado_at DATETIME NULL,
  pago_confirmado_por INT UNSIGNED NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado ENUM('pendiente','confirmado','listo','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  venta_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pedido_numero (numero),
  UNIQUE KEY uq_pedido_token (token),
  KEY idx_pedido_sucursal (sucursal_id),
  KEY idx_pedido_estado (estado),
  KEY fk_pedido_venta (venta_id),
  CONSTRAINT chk_pedido_total CHECK (total >= 0),
  CONSTRAINT fk_pedido_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
  CONSTRAINT fk_pedido_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedido_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NULL,
  descripcion VARCHAR(180) NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  precio_unitario DECIMAL(12,2) NOT NULL,
  itbis DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pd_pedido (pedido_id),
  CONSTRAINT chk_pedido_detalle CHECK (cantidad > 0 AND precio_unitario >= 0),
  CONSTRAINT fk_pd_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metas de venta / KPI (P0.6). Meta por sucursal, por vendedor o global.
-- El progreso se deriva de `ventas`; la UI es una fase posterior.
DROP TABLE IF EXISTS metas_ventas;
CREATE TABLE metas_ventas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  periodo_inicio DATE NOT NULL,
  periodo_fin DATE NOT NULL,
  moneda VARCHAR(10) NOT NULL DEFAULT 'RD$',
  monto_objetivo DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado ENUM('activa','cerrada','cancelada') NOT NULL DEFAULT 'activa',
  notas VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_meta_sucursal (sucursal_id),
  KEY idx_meta_usuario (usuario_id),
  KEY idx_meta_periodo (periodo_inicio, periodo_fin),
  CONSTRAINT chk_meta_periodo CHECK (periodo_fin >= periodo_inicio),
  CONSTRAINT chk_meta_monto CHECK (monto_objetivo >= 0),
  CONSTRAINT fk_meta_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
  CONSTRAINT fk_meta_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de los correos automáticos de la tienda. Sin esto, un correo que no
-- llega es invisible: aquí queda el id de Resend o el error exacto.
DROP TABLE IF EXISTS correos_enviados;
CREATE TABLE correos_enviados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id INT UNSIGNED NULL,
  campana_id INT UNSIGNED NULL,          -- campaña que originó el correo (si aplica)
  evento VARCHAR(40) NOT NULL,
  destinatario VARCHAR(180) NOT NULL,
  asunto VARCHAR(180) NOT NULL,
  estado ENUM('enviado','fallido') NOT NULL,
  proveedor_id VARCHAR(80) NULL,
  error VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_correo_pedido (pedido_id),
  KEY idx_correo_campana (campana_id),
  KEY idx_correo_estado (estado),
  KEY idx_correo_fecha (created_at),
  CONSTRAINT fk_correo_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formato 608 de la DGII: comprobantes fiscales anulados en el período.
-- tipo_anulacion (catálogo oficial): 1 Deterioro de factura preimpresa,
-- 2 Errores de impresión, 3 Impresión defectuosa, 4 Corrección de la información,
-- 5 Cambio de productos, 6 Devolución de productos, 7 Omisión de productos,
-- 8 Errores en secuencia de NCF, 9 Por cese de operaciones, 10 Pérdida o hurto de talonarios.
DROP TABLE IF EXISTS comprobantes_anulados;
CREATE TABLE comprobantes_anulados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ncf VARCHAR(19) NOT NULL,
  fecha_comprobante DATE NOT NULL,
  tipo_anulacion TINYINT UNSIGNED NOT NULL,
  venta_id INT UNSIGNED NULL,
  sucursal_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  notas VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_anulado_ncf (ncf),
  KEY idx_anulado_fecha (fecha_comprobante),
  KEY fk_anul_venta (venta_id),
  CONSTRAINT chk_tipo_anulacion CHECK (tipo_anulacion BETWEEN 1 AND 10),
  CONSTRAINT fk_anul_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== RRHH =====================
DROP TABLE IF EXISTS departamentos;
CREATE TABLE departamentos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT fk_dep_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS puestos;
CREATE TABLE puestos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  departamento_id INT UNSIGNED NULL,
  nombre VARCHAR(100) NOT NULL,
  salario_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT fk_puesto_dep FOREIGN KEY (departamento_id) REFERENCES departamentos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS empleados;
CREATE TABLE empleados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(20) NOT NULL,
  sucursal_id INT UNSIGNED NULL,
  departamento_id INT UNSIGNED NULL,
  puesto_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  nombre VARCHAR(80) NOT NULL,
  apellido VARCHAR(80) NOT NULL,
  cedula VARCHAR(20) NOT NULL,
  fecha_nacimiento DATE NULL,
  genero ENUM('M','F','O') NULL,
  telefono VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  direccion VARCHAR(255) NULL,
  fecha_ingreso DATE NOT NULL,
  fecha_salida DATE NULL,
  tipo_contrato ENUM('indefinido','temporal','por_obra') NOT NULL DEFAULT 'indefinido',
  salario DECIMAL(12,2) NOT NULL DEFAULT 0,
  metodo_pago ENUM('efectivo','transferencia','cheque') NOT NULL DEFAULT 'efectivo',
  banco VARCHAR(60) NULL,
  cuenta_bancaria VARCHAR(40) NULL,
  estado ENUM('activo','inactivo','vacaciones','licencia') NOT NULL DEFAULT 'activo',
  foto VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_empleado_codigo (codigo),
  UNIQUE KEY uq_empleado_cedula (cedula),
  KEY idx_emp_sucursal (sucursal_id),
  CONSTRAINT fk_emp_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_dep FOREIGN KEY (departamento_id) REFERENCES departamentos(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_puesto FOREIGN KEY (puesto_id) REFERENCES puestos(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asistencias;
CREATE TABLE asistencias (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  empleado_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NULL,
  fecha DATE NOT NULL,
  hora_entrada TIME NULL,
  hora_salida TIME NULL,
  horas_trabajadas DECIMAL(5,2) NOT NULL DEFAULT 0,
  horas_extra DECIMAL(5,2) NOT NULL DEFAULT 0,
  estado ENUM('presente','ausente','tardanza','permiso','vacaciones','licencia') NOT NULL DEFAULT 'presente',
  notas VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asistencia (empleado_id, fecha),
  KEY idx_asis_fecha (fecha),
  CONSTRAINT fk_asis_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS nominas;
CREATE TABLE nominas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NULL,
  descripcion VARCHAR(120) NOT NULL,
  tipo ENUM('mensual','quincenal','semanal') NOT NULL DEFAULT 'mensual',
  fecha_desde DATE NOT NULL,
  fecha_hasta DATE NOT NULL,
  total_bruto DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_deducciones DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_neto DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado ENUM('borrador','procesada','pagada') NOT NULL DEFAULT 'borrador',
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nom_sucursal (sucursal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS nomina_detalles;
CREATE TABLE nomina_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nomina_id INT UNSIGNED NOT NULL,
  empleado_id INT UNSIGNED NOT NULL,
  salario_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  dias_trabajados DECIMAL(5,2) NOT NULL DEFAULT 0,
  horas_extra DECIMAL(6,2) NOT NULL DEFAULT 0,
  monto_horas_extra DECIMAL(12,2) NOT NULL DEFAULT 0,
  bonificaciones DECIMAL(12,2) NOT NULL DEFAULT 0,
  comisiones DECIMAL(12,2) NOT NULL DEFAULT 0,
  otros_ingresos DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_ingresos DECIMAL(12,2) NOT NULL DEFAULT 0,
  afp DECIMAL(12,2) NOT NULL DEFAULT 0,
  sfs DECIMAL(12,2) NOT NULL DEFAULT 0,
  isr DECIMAL(12,2) NOT NULL DEFAULT 0,
  otras_deducciones DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_deducciones DECIMAL(12,2) NOT NULL DEFAULT 0,
  salario_neto DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_nd_nomina (nomina_id),
  CONSTRAINT fk_nd_nomina FOREIGN KEY (nomina_id) REFERENCES nominas(id) ON DELETE CASCADE,
  CONSTRAINT fk_nd_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS vacaciones;
CREATE TABLE vacaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empleado_id INT UNSIGNED NOT NULL,
  tipo ENUM('vacaciones','licencia') NOT NULL DEFAULT 'vacaciones',
  subtipo VARCHAR(40) NULL,                  -- enfermedad, personal, maternidad, duelo...
  fecha_solicitud DATE NOT NULL,
  fecha_desde DATE NOT NULL,
  fecha_hasta DATE NOT NULL,
  dias INT NOT NULL DEFAULT 0,
  con_goce TINYINT(1) NOT NULL DEFAULT 1,
  estado ENUM('solicitada','aprobada','rechazada','disfrutada') NOT NULL DEFAULT 'solicitada',
  motivo VARCHAR(255) NULL,
  aprobado_por INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vac_empleado (empleado_id),
  CONSTRAINT fk_vac_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== FINANZAS =====================
DROP TABLE IF EXISTS categorias_financieras;
CREATE TABLE categorias_financieras (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo ENUM('ingreso','gasto') NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_fin_tipo_nombre (tipo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cuentas_financieras;
CREATE TABLE cuentas_financieras (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NULL,
  nombre VARCHAR(100) NOT NULL,
  tipo ENUM('efectivo','banco','tarjeta','transferencia','otro') NOT NULL DEFAULT 'efectivo',
  saldo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0,   -- saldo de apertura (se conserva aparte del balance vivo)
  balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT fk_cuenta_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS transacciones;
CREATE TABLE transacciones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sucursal_id INT UNSIGNED NULL,
  cuenta_id INT UNSIGNED NULL,
  tipo ENUM('ingreso','gasto') NOT NULL,
  categoria_id INT UNSIGNED NULL,
  monto DECIMAL(14,2) NOT NULL,
  descripcion VARCHAR(255) NULL,
  referencia_tipo VARCHAR(30) NULL,          -- venta, compra, nomina, manual
  referencia_id INT UNSIGNED NULL,
  fecha DATE NOT NULL,
  conciliada TINYINT(1) NOT NULL DEFAULT 0,  -- marcada en la conciliación bancaria
  conciliacion_id INT UNSIGNED NULL,         -- corte cerrado al que pertenece (bloquea la marca)
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tr_sucursal (sucursal_id),
  KEY idx_tr_fecha (fecha),
  KEY idx_tr_tipo (tipo),
  KEY idx_tr_tipo_fecha (tipo, fecha),
  KEY idx_tr_conciliacion (cuenta_id, fecha, conciliada),
  CONSTRAINT chk_transaccion_monto_positivo CHECK (monto > 0),
  CONSTRAINT fk_tr_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas_financieras(id) ON DELETE SET NULL,
  CONSTRAINT fk_tr_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_financieras(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conciliaciones bancarias: un corte cerrado congela las transacciones marcadas.
DROP TABLE IF EXISTS conciliaciones;
CREATE TABLE conciliaciones (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cuenta_id         INT UNSIGNED NOT NULL,
  fecha_corte       DATE NOT NULL,
  saldo_banco       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- lo que dice el estado del banco
  saldo_libros      DECIMAL(14,2) NOT NULL DEFAULT 0,   -- saldo de la cuenta en el sistema
  transito_ingresos DECIMAL(14,2) NOT NULL DEFAULT 0,   -- depósitos en tránsito (aún no en el banco)
  transito_gastos   DECIMAL(14,2) NOT NULL DEFAULT 0,   -- cheques/pagos en tránsito
  diferencia        DECIMAL(14,2) NOT NULL DEFAULT 0,   -- saldo_banco - saldo_libros ajustado
  estado            ENUM('cerrada') NOT NULL DEFAULT 'cerrada',
  notas             VARCHAR(255) NULL,
  usuario_id        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conc_cuenta_corte (cuenta_id, fecha_corte),
  KEY idx_conc_cuenta (cuenta_id),
  CONSTRAINT fk_conc_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas_financieras(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comisiones de vendedores con flujo de estados: pendiente -> aprobada -> pagada.
DROP TABLE IF EXISTS comisiones;
CREATE TABLE comisiones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  sucursal_id    INT UNSIGNED NULL,
  periodo_desde  DATE NOT NULL,
  periodo_hasta  DATE NOT NULL,
  base           DECIMAL(14,2) NOT NULL DEFAULT 0,   -- subtotal - descuento (sin ITBIS)
  pct            DECIMAL(6,2)  NOT NULL DEFAULT 0,    -- % vigente al generar
  monto          DECIMAL(14,2) NOT NULL DEFAULT 0,    -- base * pct / 100
  ventas_cant    INT UNSIGNED  NOT NULL DEFAULT 0,
  estado         ENUM('pendiente','aprobada','pagada','anulada') NOT NULL DEFAULT 'pendiente',
  transaccion_id BIGINT UNSIGNED NULL,
  notas          VARCHAR(255) NULL,
  generada_por   INT UNSIGNED NULL,
  aprobada_por   INT UNSIGNED NULL,
  aprobada_at    DATETIME NULL,
  pagada_por     INT UNSIGNED NULL,
  pagada_at      DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_comision_periodo (usuario_id, periodo_desde, periodo_hasta),
  KEY idx_com_estado (estado),
  KEY idx_com_sucursal (sucursal_id),
  CONSTRAINT fk_com_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_com_transaccion FOREIGN KEY (transaccion_id) REFERENCES transacciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promociones (descuentos automáticos por temporada/categoría/marca/producto).
-- `descripcion` e `imagen` son material de marketing: se ven en el correo de una
-- campaña, no intervienen en el cálculo del precio.
DROP TABLE IF EXISTS promociones;
CREATE TABLE promociones (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(120) NOT NULL,
  descripcion  VARCHAR(255) NULL,
  imagen       VARCHAR(255) NULL,
  tipo         ENUM('porcentaje','monto') NOT NULL DEFAULT 'porcentaje',
  valor        DECIMAL(12,2) NOT NULL DEFAULT 0,
  alcance      ENUM('todos','categoria','marca','producto') NOT NULL DEFAULT 'todos',
  objetivo_id  INT UNSIGNED NULL,
  canal        ENUM('ambos','pos','tienda') NOT NULL DEFAULT 'ambos',
  fecha_inicio DATE NOT NULL,
  fecha_fin    DATE NOT NULL,
  prioridad    INT NOT NULL DEFAULT 0,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_promo_vigencia (activo, fecha_inicio, fecha_fin),
  KEY idx_promo_alcance (alcance, objetivo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== MONEDAS · CxP · COTIZACIONES ==========================
-- La contabilidad vive en pesos: todos los importes de `transacciones`,
-- reportes y DGII son RD$. Lo que se guarda aparte es lo pactado en otra
-- moneda y su tasa, para calcular la diferencia cambiaria al pagar.

DROP TABLE IF EXISTS monedas;
CREATE TABLE monedas (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo     VARCHAR(3)   NOT NULL,
  nombre     VARCHAR(60)  NOT NULL,
  simbolo    VARCHAR(6)   NOT NULL,
  tasa       DECIMAL(14,6) NOT NULL DEFAULT 1,   -- pesos por 1 unidad
  es_base    TINYINT(1)   NOT NULL DEFAULT 0,
  activo     TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_moneda_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO monedas (codigo, nombre, simbolo, tasa, es_base, activo) VALUES
  ('DOP', 'Peso dominicano', 'RD$', 1.000000, 1, 1),
  ('USD', 'Dólar estadounidense', 'US$', 60.000000, 0, 1),
  ('EUR', 'Euro', '€', 65.000000, 0, 0);

-- Pagos a proveedores. `monto` es SIEMPRE lo que salió del banco, en pesos.
DROP TABLE IF EXISTS pagos_proveedores;
CREATE TABLE pagos_proveedores (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id   INT UNSIGNED NOT NULL,
  compra_id      INT UNSIGNED NULL,
  sucursal_id    INT UNSIGNED NULL,
  monto          DECIMAL(12,2) NOT NULL,
  moneda_id      INT UNSIGNED NULL,
  monto_moneda   DECIMAL(14,2) NULL,
  tasa_cambio    DECIMAL(14,6) NOT NULL DEFAULT 1,
  diferencia_cambiaria DECIMAL(12,2) NOT NULL DEFAULT 0,
  metodo_pago_id INT UNSIGNED NULL,
  referencia     VARCHAR(60) NULL,
  notas          VARCHAR(255) NULL,
  usuario_id     INT UNSIGNED NULL,
  fecha          DATETIME NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pp_proveedor (proveedor_id),
  KEY idx_pp_compra (compra_id),
  KEY idx_pp_fecha (fecha),
  CONSTRAINT fk_pp_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cotizaciones: los importes van en la moneda del documento; `total_base` en pesos.
DROP TABLE IF EXISTS cotizaciones;
CREATE TABLE cotizaciones (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero       VARCHAR(30) NOT NULL,
  cliente_id   INT UNSIGNED NOT NULL,
  sucursal_id  INT UNSIGNED NOT NULL,
  fecha        DATE NOT NULL,
  validez_dias INT NOT NULL DEFAULT 15,
  vence        DATE NOT NULL,
  moneda_id    INT UNSIGNED NULL,
  tasa_cambio  DECIMAL(14,6) NOT NULL DEFAULT 1,
  subtotal     DECIMAL(14,2) NOT NULL DEFAULT 0,
  descuento    DECIMAL(14,2) NOT NULL DEFAULT 0,
  itbis        DECIMAL(14,2) NOT NULL DEFAULT 0,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_base   DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado       ENUM('borrador','enviada','aceptada','rechazada','vencida','facturada') NOT NULL DEFAULT 'borrador',
  condiciones  TEXT NULL,
  notas        VARCHAR(500) NULL,
  venta_id     INT UNSIGNED NULL,
  usuario_id   INT UNSIGNED NULL,
  enviada_at   DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cot_numero (numero),
  KEY idx_cot_cliente (cliente_id),
  KEY idx_cot_estado (estado, vence),
  KEY idx_cot_sucursal (sucursal_id),
  CONSTRAINT fk_cot_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS cotizacion_detalles;
CREATE TABLE cotizacion_detalles (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id   INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NULL,
  descripcion     VARCHAR(255) NOT NULL,
  cantidad        DECIMAL(12,3) NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(14,2) NOT NULL DEFAULT 0,
  itbis           DECIMAL(14,2) NOT NULL DEFAULT 0,
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
  orden           INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_cotd_cot (cotizacion_id),
  CONSTRAINT fk_cotd_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== MARKETING (campañas, segmentos, automatización) =======
-- Ver docs/MARKETING.md. El correo sale solo (Resend); el WhatsApp se prepara
-- aquí y se despacha desde la consola, porque wa.me exige un clic humano.

-- Segmentos: reglas guardadas, no listas congeladas. Se evalúan al enviar.
DROP TABLE IF EXISTS marketing_segmentos;
CREATE TABLE marketing_segmentos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(120) NOT NULL,
  descripcion     VARCHAR(255) NULL,
  requiere_email    TINYINT(1) NOT NULL DEFAULT 1,
  requiere_telefono TINYINT(1) NOT NULL DEFAULT 0,
  tipo_cliente    ENUM('cualquiera','contado','credito') NOT NULL DEFAULT 'cualquiera',
  deuda           ENUM('cualquiera','con','sin') NOT NULL DEFAULT 'cualquiera',
  sucursal_id     INT UNSIGNED NULL,
  categoria_id    INT UNSIGNED NULL,
  dias_sin_comprar_min INT NULL,
  dias_sin_comprar_max INT NULL,
  incluir_sin_compras  TINYINT(1) NOT NULL DEFAULT 1,
  compras_min     INT NULL,
  gasto_min       DECIMAL(12,2) NULL,
  gasto_max       DECIMAL(12,2) NULL,
  cumple_mes      TINYINT NOT NULL DEFAULT 0,   -- 0 no filtra · 1..12 mes · 13 mes en curso
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_seg_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plantillas de mensaje. Al crear una campaña se COPIAN, no se enlazan.
DROP TABLE IF EXISTS marketing_plantillas;
CREATE TABLE marketing_plantillas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(120) NOT NULL,
  categoria      ENUM('promocion','bienvenida','cumpleanos','recompra','inactivo','cobranza','aviso','temporada') NOT NULL DEFAULT 'promocion',
  asunto         VARCHAR(180) NOT NULL,
  preheader      VARCHAR(180) NULL,
  contenido      MEDIUMTEXT NOT NULL,
  cta_texto      VARCHAR(60) NULL,
  cta_url        VARCHAR(255) NULL,
  whatsapp_texto TEXT NULL,
  es_sistema     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_plt_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Automatizaciones: reglas que encolan mensajes solas. Nacen APAGADAS.
DROP TABLE IF EXISTS marketing_automatizaciones;
CREATE TABLE marketing_automatizaciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave          VARCHAR(40) NOT NULL,
  nombre         VARCHAR(140) NOT NULL,
  disparador     ENUM('bienvenida','cumpleanos','recompra','inactivo','post_venta','saldo_pendiente') NOT NULL,
  dias           INT NOT NULL DEFAULT 0,
  canal          ENUM('email','whatsapp','ambos') NOT NULL DEFAULT 'email',
  asunto         VARCHAR(180) NOT NULL,
  preheader      VARCHAR(180) NULL,
  contenido      MEDIUMTEXT NOT NULL,
  cta_texto      VARCHAR(60) NULL,
  cta_url        VARCHAR(255) NULL,
  whatsapp_texto TEXT NULL,
  promocion_id   INT UNSIGNED NULL,
  tope_dia       INT NOT NULL DEFAULT 200,
  activo         TINYINT(1) NOT NULL DEFAULT 0,
  enviados       INT NOT NULL DEFAULT 0,
  ultimo_run     DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auto_clave (clave),
  KEY idx_auto_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora antirrepetición de las automatizaciones.
DROP TABLE IF EXISTS marketing_automatizacion_log;
CREATE TABLE marketing_automatizacion_log (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  automatizacion_id INT UNSIGNED NOT NULL,
  cliente_id        INT UNSIGNED NOT NULL,
  periodo           VARCHAR(40) NOT NULL,      -- '2026', '2026-08', 'venta:1234'
  campana_id        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auto_log (automatizacion_id, cliente_id, periodo),
  KEY idx_auto_log_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bajas (opt-out). Un correo comercial sin salida es spam.
DROP TABLE IF EXISTS marketing_bajas;
CREATE TABLE marketing_bajas (
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

-- Campañas por correo y WhatsApp.
DROP TABLE IF EXISTS campanas;
CREATE TABLE campanas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(140) NOT NULL,
  asunto      VARCHAR(180) NOT NULL,
  preheader   VARCHAR(180) NULL,
  asunto_b    VARCHAR(180) NULL,               -- variante B de la prueba A/B
  contenido   MEDIUMTEXT NOT NULL,
  canal       ENUM('email','whatsapp','ambos') NOT NULL DEFAULT 'email',
  segmento    ENUM('con_email','con_deuda') NOT NULL DEFAULT 'con_email',  -- histórico
  segmento_id INT UNSIGNED NULL,
  plantilla_id INT UNSIGNED NULL,
  promocion_id INT UNSIGNED NULL,
  cta_texto   VARCHAR(60) NULL,
  cta_url     VARCHAR(255) NULL,
  imagen      VARCHAR(255) NULL,
  whatsapp_texto TEXT NULL,
  programada_at  DATETIME NULL,
  automatizacion_id INT UNSIGNED NULL,
  estado      ENUM('borrador','programada','enviando','enviada','parcial','pausada','cancelada') NOT NULL DEFAULT 'borrador',
  total       INT NOT NULL DEFAULT 0,
  enviados    INT NOT NULL DEFAULT 0,
  fallidos    INT NOT NULL DEFAULT 0,
  aperturas   INT NOT NULL DEFAULT 0,
  clics       INT NOT NULL DEFAULT 0,
  bajas       INT NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviada_at  DATETIME NULL,
  updated_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_campana_estado (estado),
  KEY idx_campana_programada (estado, programada_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una fila por destinatario. Permite reanudar un envío, no repetir a nadie,
-- medir aperturas y clics, y llevar la cola de WhatsApp.
DROP TABLE IF EXISTS campana_envios;
CREATE TABLE campana_envios (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campana_id   INT UNSIGNED NOT NULL,
  cliente_id   INT UNSIGNED NULL,
  canal        ENUM('email','whatsapp') NOT NULL DEFAULT 'email',
  destino      VARCHAR(180) NOT NULL,
  nombre       VARCHAR(150) NULL,
  token        CHAR(32) NOT NULL,
  variante     CHAR(1) NOT NULL DEFAULT 'A',
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

-- ===================== CRM (embudo, bitácora y agenda) =====================
-- El CRM es multi-sucursal: los clientes son globales (compran en cualquier
-- sucursal) pero cada oportunidad, interacción y tarea pertenece a UNA sucursal
-- y se filtra con sucursalScope(). Al eliminar un cliente se arrastran sus
-- registros de CRM (ON DELETE CASCADE) para no dejar huérfanos.

-- Oportunidades de venta (embudo / pipeline).
DROP TABLE IF EXISTS crm_oportunidades;
CREATE TABLE crm_oportunidades (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo                VARCHAR(20)  NOT NULL,
  cliente_id            INT UNSIGNED NOT NULL,
  sucursal_id           INT UNSIGNED NOT NULL,
  titulo                VARCHAR(150) NOT NULL,
  descripcion           VARCHAR(500) NULL,
  etapa                 ENUM('prospecto','contactado','propuesta','negociacion','ganada','perdida') NOT NULL DEFAULT 'prospecto',
  valor_estimado        DECIMAL(12,2) NOT NULL DEFAULT 0,
  probabilidad          TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0..100 %
  fuente                VARCHAR(60)  NULL,                     -- canal de captación (Instagram, Referido, Mostrador...)
  responsable_id        INT UNSIGNED NULL,                     -- usuario/vendedor a cargo
  fecha_cierre_estimada DATE NULL,
  fecha_cierre_real     DATE NULL,
  motivo_perdida        VARCHAR(255) NULL,
  venta_id              INT UNSIGNED NULL,                     -- venta generada al ganar (enlaza el CRM con las ventas)
  created_by            INT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_opo_codigo (codigo),
  KEY idx_opo_sucursal (sucursal_id),
  KEY idx_opo_cliente (cliente_id),
  KEY idx_opo_etapa (etapa),
  KEY idx_opo_responsable (responsable_id),
  CONSTRAINT fk_opo_cliente   FOREIGN KEY (cliente_id)     REFERENCES clientes(id)   ON DELETE CASCADE,
  CONSTRAINT fk_opo_sucursal  FOREIGN KEY (sucursal_id)    REFERENCES sucursales(id),
  CONSTRAINT fk_opo_respons   FOREIGN KEY (responsable_id) REFERENCES usuarios(id)   ON DELETE SET NULL,
  CONSTRAINT fk_opo_venta     FOREIGN KEY (venta_id)       REFERENCES ventas(id)     ON DELETE SET NULL,
  CONSTRAINT chk_opo_prob     CHECK (probabilidad BETWEEN 0 AND 100),
  CONSTRAINT chk_opo_valor    CHECK (valor_estimado >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de interacciones con el cliente (llamadas, visitas, WhatsApp, notas...).
DROP TABLE IF EXISTS crm_interacciones;
CREATE TABLE crm_interacciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id     INT UNSIGNED NOT NULL,
  oportunidad_id INT UNSIGNED NULL,
  sucursal_id    INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NULL,                            -- quién registró la interacción
  tipo           ENUM('llamada','whatsapp','email','visita','reunion','nota') NOT NULL DEFAULT 'nota',
  asunto         VARCHAR(150) NOT NULL,
  detalle        VARCHAR(1000) NULL,
  fecha          DATETIME NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_int_cliente (cliente_id),
  KEY idx_int_sucursal (sucursal_id),
  KEY idx_int_oportunidad (oportunidad_id),
  KEY idx_int_fecha (fecha),
  CONSTRAINT fk_int_cliente     FOREIGN KEY (cliente_id)     REFERENCES clientes(id)          ON DELETE CASCADE,
  CONSTRAINT fk_int_oportunidad FOREIGN KEY (oportunidad_id) REFERENCES crm_oportunidades(id) ON DELETE SET NULL,
  CONSTRAINT fk_int_sucursal    FOREIGN KEY (sucursal_id)    REFERENCES sucursales(id),
  CONSTRAINT fk_int_usuario     FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tareas / seguimientos con vencimiento (agenda comercial).
DROP TABLE IF EXISTS crm_tareas;
CREATE TABLE crm_tareas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id     INT UNSIGNED NULL,
  oportunidad_id INT UNSIGNED NULL,
  sucursal_id    INT UNSIGNED NOT NULL,
  asignado_a     INT UNSIGNED NULL,                            -- usuario responsable
  titulo         VARCHAR(150) NOT NULL,
  detalle        VARCHAR(500) NULL,
  vence_at       DATETIME NULL,
  prioridad      ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  estado         ENUM('pendiente','completada','cancelada') NOT NULL DEFAULT 'pendiente',
  completada_at  DATETIME NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tar_sucursal (sucursal_id),
  KEY idx_tar_asignado (asignado_a),
  KEY idx_tar_estado (estado),
  KEY idx_tar_vence (vence_at),
  KEY idx_tar_cliente (cliente_id),
  KEY idx_tar_oportunidad (oportunidad_id),
  CONSTRAINT fk_tar_cliente     FOREIGN KEY (cliente_id)     REFERENCES clientes(id)          ON DELETE CASCADE,
  CONSTRAINT fk_tar_oportunidad FOREIGN KEY (oportunidad_id) REFERENCES crm_oportunidades(id) ON DELETE CASCADE,
  CONSTRAINT fk_tar_sucursal    FOREIGN KEY (sucursal_id)    REFERENCES sucursales(id),
  CONSTRAINT fk_tar_asignado    FOREIGN KEY (asignado_a)     REFERENCES usuarios(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== ACTIVOS FIJOS Y DEPRECIACIÓN =====================
-- El mostrador, la nevera, la camioneta: patrimonio que se desgasta cada mes.
-- Sin esto el balance subestima el activo y el estado de resultados se salta la
-- depreciación, que es un gasto real aunque no salga dinero de la caja.
DROP TABLE IF EXISTS depreciaciones;
DROP TABLE IF EXISTS activos_fijos;
CREATE TABLE activos_fijos (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo                 VARCHAR(20)  NOT NULL,
  nombre                 VARCHAR(150) NOT NULL,
  descripcion            VARCHAR(500) NULL,
  -- Categoría fiscal del Código Tributario dominicano (art. 287):
  -- 1 = edificaciones (5%) · 2 = vehículos y equipos (25%) · 3 = otros (15%).
  categoria_dgii         TINYINT UNSIGNED NOT NULL DEFAULT 3,
  tipo                   VARCHAR(40)  NOT NULL DEFAULT 'otros',
  sucursal_id            INT UNSIGNED NULL,
  proveedor_id           INT UNSIGNED NULL,
  factura                VARCHAR(40)  NULL,
  fecha_adquisicion      DATE NOT NULL,
  costo                  DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_residual         DECIMAL(14,2) NOT NULL DEFAULT 0,
  vida_util_meses        SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  depreciacion_acumulada DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado                 ENUM('activo','depreciado','baja','vendido') NOT NULL DEFAULT 'activo',
  fecha_baja             DATE NULL,
  motivo_baja            VARCHAR(255) NULL,
  valor_venta            DECIMAL(14,2) NULL,
  notas                  VARCHAR(500) NULL,
  usuario_id             INT UNSIGNED NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_activo_codigo (codigo),
  KEY idx_activo_estado (estado),
  KEY idx_activo_sucursal (sucursal_id),
  CONSTRAINT chk_activo_valores CHECK (costo >= 0 AND valor_residual >= 0 AND vida_util_meses > 0),
  CONSTRAINT fk_activo_sucursal  FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id)  ON DELETE SET NULL,
  CONSTRAINT fk_activo_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un asiento por activo y periodo. La clave única impide depreciar dos veces el
-- mismo mes, que es el error clásico al correr la depreciación repetida.
CREATE TABLE depreciaciones (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activo_id         INT UNSIGNED NOT NULL,
  periodo           CHAR(7) NOT NULL,               -- 'YYYY-MM'
  monto             DECIMAL(14,2) NOT NULL DEFAULT 0,
  acumulado_antes   DECIMAL(14,2) NOT NULL DEFAULT 0,
  acumulado_despues DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_neto        DECIMAL(14,2) NOT NULL DEFAULT 0,
  transaccion_id    BIGINT UNSIGNED NULL,
  usuario_id        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dep_activo_periodo (activo_id, periodo),
  KEY idx_dep_periodo (periodo),
  CONSTRAINT fk_dep_activo      FOREIGN KEY (activo_id)      REFERENCES activos_fijos(id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_transaccion FOREIGN KEY (transaccion_id) REFERENCES transacciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== CONTEO FÍSICO DE INVENTARIO =====================
-- La toma de inventario: contar el almacén, comparar contra el sistema y
-- cuadrar. La existencia teórica se congela al abrir el conteo para que la
-- diferencia sea contra una foto fija y la tienda pueda seguir vendiendo.
DROP TABLE IF EXISTS conteo_detalles;
DROP TABLE IF EXISTS conteos;
CREATE TABLE conteos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero         VARCHAR(30)  NOT NULL,
  sucursal_id    INT UNSIGNED NOT NULL,
  categoria_id   INT UNSIGNED NULL,              -- NULL = todo el catálogo
  descripcion    VARCHAR(150) NOT NULL,
  estado         ENUM('abierto','aplicado','cancelado') NOT NULL DEFAULT 'abierto',
  notas          VARCHAR(500) NULL,
  usuario_id     INT UNSIGNED NULL,
  aplicado_por   INT UNSIGNED NULL,
  aplicado_at    DATETIME NULL,
  cancelado_por  INT UNSIGNED NULL,
  cancelado_at   DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conteo_numero (numero),
  KEY idx_conteo_sucursal (sucursal_id, estado),
  CONSTRAINT fk_conteo_sucursal  FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id),
  CONSTRAINT fk_conteo_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conteo_detalles (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conteo_id      INT UNSIGNED NOT NULL,
  producto_id    INT UNSIGNED NOT NULL,
  stock_teorico  DECIMAL(12,3) NOT NULL DEFAULT 0,   -- foto al abrir el conteo
  stock_contado  DECIMAL(12,3) NULL,                 -- NULL = todavía sin contar
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,   -- congelado, para valorizar
  contado_por    INT UNSIGNED NULL,
  contado_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conteo_producto (conteo_id, producto_id),
  KEY idx_cd_producto (producto_id),
  CONSTRAINT fk_cdet_conteo   FOREIGN KEY (conteo_id)   REFERENCES conteos(id) ON DELETE CASCADE,
  CONSTRAINT fk_cdet_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  CUMPLIMIENTO SANITARIO — lotes y trazabilidad
--  Ver docs/SANIDAD-Y-AUDITORIAS.md
-- ============================================================================

-- `inventario_stock` sigue siendo la verdad para vender y para los reportes de
-- siempre. `lotes` DESGLOSA esa existencia en los productos con `controla_lote`.
-- Las dos se mueven dentro de la misma transacción, desde ajustarStock().
CREATE TABLE lotes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  producto_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(60) NOT NULL,                 -- número de lote del fabricante
  fecha_vencimiento DATE NULL,
  fecha_fabricacion DATE NULL,
  cantidad DECIMAL(12,3) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  proveedor_id INT UNSIGNED NULL,
  compra_id INT UNSIGNED NULL,
  bloqueado TINYINT(1) NOT NULL DEFAULT 0,     -- retiro del mercado: no se vende, no se borra
  motivo_bloqueo VARCHAR(255) NULL,
  registro_sanitario VARCHAR(60) NULL,         -- copia del registro vigente al entrar el lote
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Un mismo número de lote es un único registro por producto y sucursal: si no,
  -- dos entradas del mismo lote crearían dos saldos y la trazabilidad se parte.
  UNIQUE KEY uq_lote (producto_id, sucursal_id, codigo),
  KEY idx_lote_venc (fecha_vencimiento),
  KEY idx_lote_prod_venc (producto_id, sucursal_id, fecha_vencimiento),
  KEY idx_lote_codigo (codigo),
  CONSTRAINT fk_lote_producto  FOREIGN KEY (producto_id)  REFERENCES productos(id),
  CONSTRAINT fk_lote_sucursal  FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id),
  CONSTRAINT fk_lote_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El libro de trazabilidad: una fila por cada lote tocado.
-- Va en tabla propia y no como columna de venta_detalles porque UNA línea de
-- venta puede consumir DOS lotes (se acaba uno y sigue con el siguiente).
CREATE TABLE lote_movimientos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lote_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  sucursal_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(30) NOT NULL,                   -- entrada, venta, devolucion, ajuste, baja, bloqueo…
  cantidad DECIMAL(12,3) NOT NULL,             -- + entra, − sale
  saldo_anterior DECIMAL(12,3) NOT NULL DEFAULT 0,
  saldo_nuevo DECIMAL(12,3) NOT NULL DEFAULT 0,
  referencia_tipo VARCHAR(30) NULL,
  referencia_id INT UNSIGNED NULL,
  motivo VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_lm_lote (lote_id, id),
  KEY idx_lm_ref (referencia_tipo, referencia_id),
  KEY idx_lm_producto (producto_id, created_at),
  CONSTRAINT fk_lm_lote FOREIGN KEY (lote_id) REFERENCES lotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== SISTEMA / CONCURRENCIA =====================
-- Correlativos (VTA-000123, COM-000045...). Un contador por serie.
-- El número se reserva con un UPDATE atómico en vez de «SELECT MAX(...)+1»:
-- con dos cajas vendiendo en el mismo segundo, el método viejo generaba el mismo
-- número en ambas y una de las dos ventas moría contra el índice UNIQUE.
DROP TABLE IF EXISTS contadores;
CREATE TABLE contadores (
  nombre     VARCHAR(60) NOT NULL,          -- ej. ventas.numero.VTA
  valor      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== SEGURIDAD DE ACCESO (2FA) =====================
-- Verificación en dos pasos por correo. Ver docs/OTP-LOGIN.md.
-- El código NUNCA se guarda en claro: solo su bcrypt. Un volcado de la base no
-- entrega códigos utilizables.
DROP TABLE IF EXISTS login_otp;
CREATE TABLE login_otp (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  proposito VARCHAR(20) NOT NULL DEFAULT 'login',
  codigo_hash VARCHAR(255) NOT NULL,
  destino VARCHAR(120) NOT NULL,
  intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_intentos TINYINT UNSIGNED NOT NULL DEFAULT 5,
  enviado TINYINT(1) NOT NULL DEFAULT 0,
  error_envio VARCHAR(255) NULL,
  proveedor_id VARCHAR(80) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  ua_hash CHAR(64) NULL,                    -- ata el código al navegador que lo pidió
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

-- Contador para los bloqueos por fuerza bruta. `clave` es el sujeto del límite:
-- 'login:admin' (cuenta) o 'ip:186.x.x.x'. Sin FK a usuarios: también se cuentan
-- los intentos contra cuentas inexistentes, que son la señal de un diccionario.
DROP TABLE IF EXISTS login_intentos;
CREATE TABLE login_intentos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(20) NOT NULL,                -- password | otp | envio
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

-- Equipos de confianza («no me pidas el código en esta caja»). La cookie lleva un
-- token aleatorio; aquí solo vive su SHA-256.
DROP TABLE IF EXISTS login_dispositivos;
CREATE TABLE login_dispositivos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  nombre VARCHAR(80) NOT NULL,
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

-- ===================== SISTEMA / NOTIFICACIONES =====================
-- Estado interno llave/valor. Acota cada cuánto corre el motor de
-- notificaciones sin necesidad de cron: un UPDATE atómico gana la carrera
-- entre peticiones simultáneas.
DROP TABLE IF EXISTS sistema_estado;
CREATE TABLE sistema_estado (
  clave      VARCHAR(60)  NOT NULL,
  valor      VARCHAR(255) NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una fila por «situación viva» del negocio (no por evento). La `clave`
-- deduplica: si el stock sigue bajo mañana se actualiza la misma fila en vez de
-- crear otra. Cuando la situación desaparece, estado='resuelta'.
DROP TABLE IF EXISTS notificacion_lecturas;
DROP TABLE IF EXISTS notificaciones;
CREATE TABLE notificaciones (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave           VARCHAR(120) NOT NULL,                 -- deduplicación (ej. stock_bajo:3)
  tipo            VARCHAR(40)  NOT NULL,                 -- familia: stock_bajo, cxc_vencida, ...
  categoria       ENUM('inventario','ventas','finanzas','fiscal','crm','rrhh','sistema') NOT NULL DEFAULT 'sistema',
  prioridad       ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  titulo          VARCHAR(150) NOT NULL,
  mensaje         VARCHAR(300) NULL,
  url             VARCHAR(255) NULL,
  icono           VARCHAR(30)  NOT NULL DEFAULT 'bell',
  color           VARCHAR(20)  NOT NULL DEFAULT 'blue',
  sucursal_id     INT UNSIGNED NULL,                     -- NULL = todas las sucursales
  usuario_id      INT UNSIGNED NULL,                     -- NULL = para todo el que tenga el permiso
  permiso         VARCHAR(60)  NULL,                     -- permiso requerido para verla
  referencia_tipo VARCHAR(30)  NULL,
  referencia_id   INT UNSIGNED NULL,
  estado          ENUM('activa','resuelta') NOT NULL DEFAULT 'activa',
  resuelta_at     DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_clave (clave),
  KEY idx_notif_estado (estado, prioridad),
  KEY idx_notif_tipo (tipo, estado),
  KEY idx_notif_sucursal (sucursal_id),
  KEY idx_notif_usuario (usuario_id),
  CONSTRAINT fk_notif_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marca de lectura por usuario (una notificación la ven varios usuarios).
CREATE TABLE notificacion_lecturas (
  notificacion_id BIGINT UNSIGNED NOT NULL,
  usuario_id      INT UNSIGNED NOT NULL,
  leida_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notificacion_id, usuario_id),
  KEY idx_nl_usuario (usuario_id),
  CONSTRAINT fk_nl_notif   FOREIGN KEY (notificacion_id) REFERENCES notificaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_nl_usuario FOREIGN KEY (usuario_id)      REFERENCES usuarios(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== LIQUIDACIÓN DE IMPORTACIONES =====================
-- El costo real de la mercancía puesta en almacén: FOB + flete + seguro +
-- arancel + gastos aduanales, repartidos entre los artículos del embarque.
--
-- Es un documento de COSTO, no de dinero: no registra la deuda al proveedor ni
-- el pago de los gastos (de eso ya se encargan Compras y Cuentas por Pagar, y
-- duplicarlo inflaría los gastos del mes). Ver docs/TIENDAS-Y-DIRECCION.md.
DROP TABLE IF EXISTS liquidaciones;
CREATE TABLE liquidaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  tienda_id INT UNSIGNED NULL,
  sucursal_id INT UNSIGNED NOT NULL,     -- almacén donde entra la mercancía
  proveedor_id INT UNSIGNED NULL,
  compra_id INT UNSIGNED NULL,           -- solo en modo recosteo
  -- entrada  = el embarque aún no está en inventario; al aplicar, ENTRA al costo final.
  -- recosteo = la compra ya entró la mercancía; al aplicar solo se corrige el costo.
  modo ENUM('entrada','recosteo') NOT NULL DEFAULT 'entrada',
  referencia VARCHAR(60) NULL,           -- BL, contenedor, DUA o factura del embarque
  fecha DATE NOT NULL,
  fecha_llegada DATE NULL,
  moneda_id INT UNSIGNED NULL,
  tasa_cambio DECIMAL(14,6) NOT NULL DEFAULT 1,
  prorrateo ENUM('valor','cantidad','peso','volumen') NOT NULL DEFAULT 'valor',
  fob DECIMAL(14,2) NOT NULL DEFAULT 0,             -- mercancía en pesos
  gastos DECIMAL(14,2) NOT NULL DEFAULT 0,          -- gastos que SÍ entran al costo
  gastos_no_costo DECIMAL(14,2) NOT NULL DEFAULT 0, -- ITBIS adelantado y otros recuperables
  costo_total DECIMAL(14,2) NOT NULL DEFAULT 0,     -- fob + gastos
  estado ENUM('borrador','transito','aplicada','anulada') NOT NULL DEFAULT 'borrador',
  notas VARCHAR(500) NULL,
  usuario_id INT UNSIGNED NULL,
  aplicada_at DATETIME NULL,
  aplicada_por INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_liq_numero (numero),
  KEY idx_liq_estado (estado, fecha),
  KEY idx_liq_sucursal (sucursal_id, estado),
  KEY idx_liq_tienda (tienda_id, fecha),
  KEY idx_liq_compra (compra_id),
  CONSTRAINT fk_liq_sucursal  FOREIGN KEY (sucursal_id)  REFERENCES sucursales(id),
  CONSTRAINT fk_liq_tienda    FOREIGN KEY (tienda_id)    REFERENCES tiendas(id)     ON DELETE SET NULL,
  CONSTRAINT fk_liq_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL,
  CONSTRAINT fk_liq_compra    FOREIGN KEY (compra_id)    REFERENCES compras(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- costo_anterior guarda el precio_compra que tenía el producto ANTES de aplicar.
-- Sin eso, anular una liquidación no puede devolver el costo viejo y el margen
-- de todos los reportes queda torcido para siempre.
DROP TABLE IF EXISTS liquidacion_detalles;
CREATE TABLE liquidacion_detalles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  liquidacion_id INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  costo_moneda DECIMAL(14,4) NOT NULL DEFAULT 0,  -- FOB unitario en la moneda del embarque
  costo_fob DECIMAL(14,4) NOT NULL DEFAULT 0,     -- FOB unitario en pesos
  peso DECIMAL(12,3) NOT NULL DEFAULT 0,          -- kg por unidad (prorrateo por peso)
  volumen DECIMAL(12,4) NOT NULL DEFAULT 0,       -- m3 por unidad (prorrateo por volumen)
  prorrateo DECIMAL(14,2) NOT NULL DEFAULT 0,     -- gastos asignados a la línea completa
  costo_final DECIMAL(14,4) NOT NULL DEFAULT 0,   -- costo unitario puesto en almacén
  costo_anterior DECIMAL(12,2) NULL,
  -- Un embarque de mercancía regulada entra con su lote: si no se captura aquí,
  -- la trazabilidad queda coja justo en la entrada.
  lote VARCHAR(60) NULL,
  vencimiento DATE NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_liqdet (liquidacion_id, producto_id),
  KEY idx_liqdet_producto (producto_id),
  CONSTRAINT chk_liqdet_valores CHECK (cantidad > 0 AND costo_moneda >= 0 AND costo_fob >= 0 AND peso >= 0 AND volumen >= 0),
  CONSTRAINT fk_liqdet_liq      FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_liqdet_producto FOREIGN KEY (producto_id)    REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- al_costo = 0 es la trampa clásica del costeo dominicano: el ITBIS pagado en
-- aduana NO es costo, es un adelanto que se compensa contra el ITBIS cobrado.
-- Meterlo al costo infla el inventario un 18% y hunde el margen.
DROP TABLE IF EXISTS liquidacion_gastos;
CREATE TABLE liquidacion_gastos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  liquidacion_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'otros',  -- flete, seguro, arancel, itbis, aduana, transporte, otros
  concepto VARCHAR(140) NOT NULL,
  moneda_id INT UNSIGNED NULL,
  tasa_cambio DECIMAL(14,6) NOT NULL DEFAULT 1,
  monto_moneda DECIMAL(14,2) NOT NULL DEFAULT 0,
  monto DECIMAL(14,2) NOT NULL DEFAULT 0,     -- en pesos
  al_costo TINYINT(1) NOT NULL DEFAULT 1,     -- 0 = recuperable, no entra al costo
  orden SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_liqgas_liq (liquidacion_id, orden),
  CONSTRAINT chk_liqgas_monto CHECK (monto >= 0 AND monto_moneda >= 0),
  CONSTRAINT fk_liqgas_liq FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== CARGA HISTÓRICA (DIRECCIÓN) =====================
-- Todo lo que entra por un archivo queda marcado con su lote. Sin eso, un
-- archivo mal mapeado se mezcla con las ventas reales y solo se separa
-- restaurando un respaldo completo. Con el lote, se revierte con un botón.
--
-- Sin FK desde ventas/clientes a propósito: purgar lotes viejos no puede
-- arrastrar ventas ni quedar bloqueado por ellas.
DROP TABLE IF EXISTS importaciones;
CREATE TABLE importaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo ENUM('clientes','ventas') NOT NULL,
  archivo VARCHAR(200) NULL,
  filas INT UNSIGNED NOT NULL DEFAULT 0,
  creados INT UNSIGNED NOT NULL DEFAULT 0,
  actualizados INT UNSIGNED NOT NULL DEFAULT 0,
  omitidos INT UNSIGNED NOT NULL DEFAULT 0,
  monto DECIMAL(16,2) NOT NULL DEFAULT 0,    -- suma importada (solo ventas)
  estado ENUM('procesada','revertida') NOT NULL DEFAULT 'procesada',
  detalle MEDIUMTEXT NULL,                   -- JSON: mapeo usado, avisos y filas rechazadas
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revertida_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_imp_tipo (tipo, estado, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== Datos de fábrica de Marketing =========================
-- Segmentos y plantillas listos para usar, y las seis automatizaciones APAGADAS.
-- Nacen apagadas a propósito: nadie debe descubrir que su sistema empezó a
-- escribirle a sus clientes sin habérselo pedido.

INSERT INTO marketing_segmentos
  (nombre, descripcion, requiere_email, requiere_telefono, tipo_cliente, deuda,
   dias_sin_comprar_min, dias_sin_comprar_max, incluir_sin_compras, compras_min, cumple_mes) VALUES
  ('Todos los contactables', 'Clientes activos con correo válido y que aceptan promociones', 1, 0, 'cualquiera', 'cualquiera', NULL, NULL, 1, NULL, 0),
  ('Clientes frecuentes', 'Compraron 3 veces o más en el histórico', 1, 0, 'cualquiera', 'cualquiera', NULL, NULL, 0, 3, 0),
  ('Dormidos (90 días sin comprar)', 'No compran hace 90 días o más: el segmento que más recupera venta', 1, 0, 'cualquiera', 'cualquiera', 90, NULL, 0, 1, 0),
  ('Compraron este mes', 'Compra en los últimos 30 días: ideal para venta cruzada', 1, 0, 'cualquiera', 'cualquiera', NULL, 30, 0, NULL, 0),
  ('Cumpleañeros del mes', 'Cumplen años en el mes en curso', 1, 0, 'cualquiera', 'cualquiera', NULL, NULL, 1, NULL, 13),
  ('Con saldo pendiente', 'Tienen balance por cobrar (avisos de cobranza)', 0, 1, 'credito', 'con', NULL, NULL, 1, NULL, 0),
  ('WhatsApp: todos con teléfono', 'Clientes activos con un número válido para wa.me', 0, 1, 'cualquiera', 'cualquiera', NULL, NULL, 1, NULL, 0);

INSERT INTO marketing_plantillas (nombre, categoria, asunto, preheader, contenido, cta_texto, whatsapp_texto, es_sistema) VALUES
  ('Promoción de temporada', 'promocion', '{{cliente}}, aprovecha {{descuento}} por tiempo limitado', 'Aprovecha antes de que termine la promoción',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Preparamos algo para ti: <strong>{{promo}}</strong> con <strong>{{descuento}}</strong>.</p><p>La promoción está vigente {{vigencia}}. Pasa por la tienda o escríbenos y te apartamos lo tuyo.</p>',
   'Ver la promoción', 'Hola {{cliente}}, te escribo de {{empresa}}. Tenemos {{promo}} con {{descuento}}, vigente {{vigencia}}. ¿Te aparto el tuyo?', 1),
  ('Bienvenida a un cliente nuevo', 'bienvenida', '¡Bienvenido a {{empresa}}, {{cliente}}!', 'Gracias por tu primera compra',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por confiar en <strong>{{empresa}}</strong>. Nos alegra tenerte con nosotros.</p><p>Cualquier cosa que necesites, escríbenos por aquí o al {{telefono}}: te atendemos de una vez.</p>',
   'Ver el catálogo', 'Hola {{cliente}}, ¡bienvenido a {{empresa}}! Cualquier cosa que necesites, escríbeme por aquí.', 1),
  ('Feliz cumpleaños', 'cumpleanos', '🎉 ¡Feliz cumpleaños, {{cliente}}!', 'Tenemos un regalo para ti',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>De parte de todo el equipo de <strong>{{empresa}}</strong>: ¡feliz cumpleaños!</p><p>Para celebrarlo contigo, este mes tienes <strong>{{descuento}}</strong> en tu compra. Solo menciónalo al pagar.</p>',
   'Reclamar mi descuento', '¡Feliz cumpleaños, {{cliente}}! 🎉 De parte de {{empresa}}. Este mes tienes {{descuento}} en tu compra.', 1),
  ('Te extrañamos (cliente dormido)', 'inactivo', '{{cliente}}, hace tiempo no te vemos', 'Vuelve con un descuento de nuestra parte',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Notamos que hace un tiempo no pasas por <strong>{{empresa}}</strong>, y queremos que vuelvas.</p><p>Te dejamos <strong>{{descuento}}</strong> en tu próxima compra, vigente {{vigencia}}.</p>',
   'Volver a comprar', 'Hola {{cliente}}, te escribo de {{empresa}}. Hace tiempo no te vemos y te dejamos {{descuento}} en tu próxima compra. ¿Te muestro lo que llegó nuevo?', 1),
  ('Gracias por tu compra', 'recompra', 'Gracias por tu compra, {{cliente}}', '¿Todo bien con lo que te llevaste?',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por tu compra en <strong>{{empresa}}</strong>. Esperamos que todo esté perfecto.</p><p>Si algo no salió como esperabas, responde este correo o llámanos al {{telefono}}: lo resolvemos.</p>',
   'Ver novedades', 'Hola {{cliente}}, gracias por tu compra en {{empresa}}. ¿Todo bien con lo que te llevaste?', 1),
  ('Recordatorio de saldo pendiente', 'cobranza', 'Recordatorio de tu cuenta con {{empresa}}', 'Tu saldo pendiente al día de hoy',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Te escribimos para recordarte que tu cuenta con <strong>{{empresa}}</strong> tiene un saldo pendiente de <strong>{{saldo}}</strong>.</p><p>Si ya realizaste el pago, ignora este mensaje. Cualquier duda, llámanos al {{telefono}}.</p>',
   NULL, 'Hola {{cliente}}, le escribo de {{empresa}}. Su cuenta tiene un saldo pendiente de {{saldo}}. Si ya realizó el pago, haga caso omiso de este mensaje.', 1),
  ('Llegó mercancía nueva', 'aviso', 'Llegó lo nuevo a {{empresa}}', 'Mira lo que acaba de entrar',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Acaba de llegar mercancía nueva a <strong>{{empresa}}</strong> y queríamos que fueras de los primeros en verla.</p><p>Pasa por la tienda o escríbenos y te enviamos fotos.</p>',
   'Ver lo nuevo', 'Hola {{cliente}}, le escribo de {{empresa}}. Acaba de llegar mercancía nueva, ¿le mando fotos?', 1);

INSERT INTO marketing_automatizaciones (clave, nombre, disparador, dias, canal, asunto, preheader, contenido, cta_texto, whatsapp_texto, activo) VALUES
  ('bienvenida', 'Bienvenida al cliente nuevo', 'bienvenida', 0, 'email', '¡Bienvenido a {{empresa}}, {{cliente}}!', 'Gracias por tu primera compra',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por confiar en <strong>{{empresa}}</strong>. Nos alegra tenerte con nosotros.</p><p>Cualquier cosa que necesites, escríbenos o llámanos al {{telefono}}.</p>',
   'Ver el catálogo', 'Hola {{cliente}}, ¡bienvenido a {{empresa}}! Cualquier cosa que necesites, escríbeme por aquí.', 0),
  ('cumpleanos', 'Felicitación de cumpleaños', 'cumpleanos', 0, 'email', '🎉 ¡Feliz cumpleaños, {{cliente}}!', 'Tenemos un regalo para ti',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>De parte de todo el equipo de <strong>{{empresa}}</strong>: ¡feliz cumpleaños!</p><p>Para celebrarlo, este mes tienes un descuento especial en tu compra. Solo menciónalo al pagar.</p>',
   'Reclamar mi descuento', '¡Feliz cumpleaños, {{cliente}}! 🎉 De parte de {{empresa}}.', 0),
  ('post_venta', 'Gracias por tu compra', 'post_venta', 2, 'email', 'Gracias por tu compra, {{cliente}}', '¿Todo bien con lo que te llevaste?',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Gracias por tu compra en <strong>{{empresa}}</strong>. Esperamos que todo esté perfecto.</p><p>Si algo no salió como esperabas, responde este correo o llámanos al {{telefono}}.</p>',
   NULL, 'Hola {{cliente}}, gracias por tu compra en {{empresa}}. ¿Todo bien con lo que te llevaste?', 0),
  ('recompra', 'Recordatorio de recompra', 'recompra', 45, 'email', '{{cliente}}, ¿te hace falta reponer?', 'Pasaron unas semanas de tu última compra',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Ya pasaron unas semanas desde tu última compra en <strong>{{empresa}}</strong>. Si te hace falta reponer, escríbenos y te lo dejamos listo.</p>',
   'Volver a comprar', 'Hola {{cliente}}, le escribo de {{empresa}}. ¿Le hace falta reponer? Se lo dejo listo.', 0),
  ('inactivo', 'Recuperar cliente dormido', 'inactivo', 90, 'email', '{{cliente}}, hace tiempo no te vemos', 'Vuelve con un descuento de nuestra parte',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Notamos que hace un tiempo no pasas por <strong>{{empresa}}</strong>, y queremos que vuelvas.</p><p>Escríbenos y te contamos lo que llegó nuevo.</p>',
   'Volver a comprar', 'Hola {{cliente}}, le escribo de {{empresa}}. Hace tiempo no le vemos, ¿le muestro lo que llegó nuevo?', 0),
  ('saldo_pendiente', 'Aviso de saldo pendiente', 'saldo_pendiente', 30, 'email', 'Recordatorio de tu cuenta con {{empresa}}', 'Tu saldo pendiente al día de hoy',
   '<p>Hola <strong>{{cliente}}</strong>,</p><p>Te recordamos que tu cuenta con <strong>{{empresa}}</strong> tiene un saldo pendiente de <strong>{{saldo}}</strong>.</p><p>Si ya realizaste el pago, ignora este mensaje.</p>',
   NULL, 'Hola {{cliente}}, le escribo de {{empresa}}. Su cuenta tiene un saldo pendiente de {{saldo}}. Si ya realizó el pago, haga caso omiso.', 0);

SET FOREIGN_KEY_CHECKS = 1;
