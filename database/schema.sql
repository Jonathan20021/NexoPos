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
  activo TINYINT(1) NOT NULL DEFAULT 1,
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
  tipo ENUM('producto','servicio') NOT NULL DEFAULT 'producto',
  precio_compra DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_venta DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis_aplica TINYINT(1) NOT NULL DEFAULT 1,
  stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0,
  imagen VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_producto_codigo (codigo),
  KEY idx_p_barras (codigo_barras),
  KEY idx_p_categoria (categoria_id),
  KEY idx_p_nombre (nombre),
  CONSTRAINT chk_producto_valores_no_negativos CHECK (precio_compra >= 0 AND precio_venta >= 0 AND stock_minimo >= 0),
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
  proveedor_id INT UNSIGNED NULL,
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
  estado ENUM('pendiente','recibida','anulada') NOT NULL DEFAULT 'recibida',
  notas VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_compra_numero (numero),
  KEY idx_compras_ncf (ncf),
  KEY idx_compras_comprobante (fecha_comprobante),
  KEY idx_c_sucursal (sucursal_id),
  CONSTRAINT fk_c_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
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
  tipo ENUM('contado','credito') NOT NULL DEFAULT 'contado',
  limite_credito DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,                  -- usuario que registró el cliente (trazabilidad)
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cliente_codigo (codigo),
  KEY idx_cli_nombre (nombre),
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

-- ===================== VENTAS =====================
DROP TABLE IF EXISTS ventas;
CREATE TABLE ventas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero VARCHAR(30) NOT NULL,
  uuid CHAR(36) NULL,                    -- identidad idempotente para ventas creadas offline (sync)
  sucursal_id INT UNSIGNED NOT NULL,
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
  KEY idx_ventas_ncf (ncf),
  KEY idx_ventas_canal (canal_venta),
  KEY idx_v_sucursal (sucursal_id),
  KEY idx_v_fecha (fecha),
  KEY idx_v_cliente (cliente_id),
  KEY idx_v_sesion (caja_sesion_id),
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
DROP TABLE IF EXISTS promociones;
CREATE TABLE promociones (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(120) NOT NULL,
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

-- Campañas por correo (envío masivo sobre Resend).
DROP TABLE IF EXISTS campanas;
CREATE TABLE campanas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(140) NOT NULL,
  asunto      VARCHAR(180) NOT NULL,
  contenido   MEDIUMTEXT NOT NULL,
  segmento    ENUM('con_email','con_deuda') NOT NULL DEFAULT 'con_email',
  estado      ENUM('borrador','enviada','parcial') NOT NULL DEFAULT 'borrador',
  total       INT NOT NULL DEFAULT 0,
  enviados    INT NOT NULL DEFAULT 0,
  fallidos    INT NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviada_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_campana_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
