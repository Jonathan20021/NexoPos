<?php
/**
 * Instalador de NexoPOS.
 *  - Web:  abre  /proyecto-inventario-pos/install/index.php  y pulsa "Instalar".
 *  - CLI:  php install/index.php
 * Crea la base de datos, ejecuta el esquema y siembra datos de ejemplo.
 */
define('NEXOPOS_INSTALLER', true);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$cli = (php_sapi_name() === 'cli');
$log = [];
$errores = [];
$instalado = false;

function paso(string $msg) { global $log; $log[] = $msg; }

function ejecutarInstalacion(bool $produccion = false): void
{
    global $errores;

    // 1) Crear base de datos (en hosting compartido suele existir ya y el usuario
    //    no tiene privilegio para crearla: lo intentamos y, si falla, continuamos).
    try {
        $root = db(true);
        $root->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        paso('Base de datos «' . DB_NAME . '» lista.');
    } catch (Throwable $e) {
        paso('Usando la base de datos existente «' . DB_NAME . '».');
    }

    // 2) Ejecutar esquema
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    foreach (explode(';', $sql) as $stmt) {
        $lineas = array_filter(explode("\n", $stmt), fn($l) => strpos(ltrim($l), '--') !== 0);
        $limpio = trim(implode("\n", $lineas));
        if ($limpio === '') continue;
        db()->exec($limpio);
    }
    paso('Esquema creado: ' . (int) qVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?", [DB_NAME]) . ' tablas.');

    // 3) Sembrar datos
    sembrar();
    if ($produccion) {
        limpiarDemo();
        paso('Instalación de producción: solo configuración esencial (sin datos de ejemplo).');
    } else {
        paso('Datos de ejemplo insertados correctamente.');
    }

    // 4) Bloqueo de instalación
    @file_put_contents(__DIR__ . '/installed.lock', date('c'));
}

/** Elimina los datos de demostración dejando solo la configuración esencial. */
function limpiarDemo(): void
{
    $pdo = db();
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $tablas = ['venta_pagos', 'venta_detalles', 'devolucion_detalles', 'devoluciones', 'ventas',
        'compra_detalles', 'compras', 'transferencia_detalles', 'transferencias',
        'movimientos_inventario', 'transacciones', 'pagos_clientes',
        'caja_movimientos', 'caja_sesiones', 'inventario_stock', 'productos', 'proveedores',
        'asistencias', 'nomina_detalles', 'nominas', 'vacaciones', 'empleados'];
    foreach ($tablas as $t) $pdo->exec("DELETE FROM `$t`");
    $pdo->exec("DELETE FROM clientes WHERE id <> 1");
    // Quitar usuarios de demostración con contraseña por defecto (deja solo 'admin')
    $pdo->exec("DELETE FROM usuarios WHERE usuario IN ('gerente','cajero')");
    $pdo->exec("UPDATE clientes SET balance = 0");
    $pdo->exec("UPDATE cuentas_financieras SET balance = 0");
    $pdo->exec("UPDATE ncf_secuencias SET secuencia_actual = 1");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

function sembrar(): void
{
    tx(function () {
        // ----- Empresa -----
        dbInsert('empresa', [
            'nombre' => 'Comercial Dominicana SRL',
            'rnc' => '1-31-12345-6',
            'direccion' => 'Av. Winston Churchill #45, Santo Domingo',
            'telefono' => '809-555-0100',
            'email' => 'info@comercialrd.do',
            'moneda' => 'RD$',
            'itbis_tasa' => 18.00,
            'mensaje_ticket' => '¡Gracias por su compra! Vuelva pronto.',
        ]);

        // ----- Sucursales -----
        $sucPrincipal = dbInsert('sucursales', ['codigo' => 'SUC-01', 'nombre' => 'Sucursal Principal', 'direccion' => 'Av. Winston Churchill #45, Santo Domingo', 'telefono' => '809-555-0101', 'whatsapp' => '18095550101', 'horario' => 'Lun a Sáb, 8:00 AM - 8:00 PM', 'encargado' => 'Carlos Méndez']);
        $sucSantiago  = dbInsert('sucursales', ['codigo' => 'SUC-02', 'nombre' => 'Sucursal Santiago', 'direccion' => 'Calle del Sol #88, Santiago', 'telefono' => '809-555-0202', 'whatsapp' => '18095550202', 'horario' => 'Lun a Sáb, 8:00 AM - 7:00 PM', 'encargado' => 'Laura Peña']);

        // ----- Permisos (desde el catálogo) -----
        foreach (permission_keys() as $clave => $info) {
            dbInsert('permisos', ['clave' => $clave, 'modulo' => $info['modulo'], 'grupo' => $info['grupo'], 'descripcion' => $info['descripcion']]);
        }

        // ----- Roles -----
        $rSuper  = dbInsert('roles', ['nombre' => 'Super Administrador', 'descripcion' => 'Acceso total al sistema y todas las sucursales', 'es_super' => 1, 'es_sistema' => 1]);
        $rAdmin  = dbInsert('roles', ['nombre' => 'Administrador', 'descripcion' => 'Administra todos los módulos', 'es_sistema' => 1]);
        $rGerente= dbInsert('roles', ['nombre' => 'Gerente de Sucursal', 'descripcion' => 'Gestiona su sucursal: ventas, inventario y reportes']);
        $rCajero = dbInsert('roles', ['nombre' => 'Cajero', 'descripcion' => 'Opera el punto de venta y la caja']);
        $rAlmacen= dbInsert('roles', ['nombre' => 'Almacén / Inventario', 'descripcion' => 'Gestiona productos, stock y compras']);
        $rRRHH   = dbInsert('roles', ['nombre' => 'Recursos Humanos', 'descripcion' => 'Gestiona empleados, nómina y asistencia']);

        $permisos = qAll("SELECT id, clave, modulo FROM permisos");
        $asignar = function ($rolId, callable $f) use ($permisos) {
            foreach ($permisos as $p) {
                if ($f($p['clave'], $p['modulo'])) {
                    q("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?,?)", [$rolId, $p['id']]);
                }
            }
        };
        $todos = fn($c, $m) => true;
        $asignar($rSuper, $todos);
        $asignar($rAdmin, $todos);
        $asignar($rGerente, fn($c, $m) => !in_array($m, ['roles', 'usuarios', 'configuracion', 'auditoria', 'rrhh_nomina']) || str_ends_with($c, '.ver'));
        $ventasMods = ['pos', 'caja', 'ventas', 'devoluciones', 'clientes'];
        $asignar($rCajero, fn($c, $m) => in_array($m, $ventasMods) || in_array($c, ['productos.ver', 'inventario.ver']));
        $invMods = ['categorias', 'productos', 'inventario', 'proveedores', 'compras', 'transferencias'];
        $asignar($rAlmacen, fn($c, $m) => in_array($m, $invMods));
        $asignar($rRRHH, fn($c, $m) => str_starts_with($m, 'rrhh_'));

        // ----- Usuarios -----
        $uAdmin = dbInsert('usuarios', ['sucursal_id' => null, 'rol_id' => $rSuper, 'nombre' => 'Admin', 'apellido' => 'Principal', 'usuario' => 'admin', 'email' => 'admin@nexopos.do', 'password_hash' => password_hash('admin123', PASSWORD_DEFAULT), 'telefono' => '809-555-0001']);
        $uGerente = dbInsert('usuarios', ['sucursal_id' => $sucPrincipal, 'rol_id' => $rGerente, 'nombre' => 'Carlos', 'apellido' => 'Méndez', 'usuario' => 'gerente', 'email' => 'gerente@nexopos.do', 'password_hash' => password_hash('gerente123', PASSWORD_DEFAULT), 'comision_pct' => 1.00]);
        $uCajero = dbInsert('usuarios', ['sucursal_id' => $sucPrincipal, 'rol_id' => $rCajero, 'nombre' => 'María', 'apellido' => 'Rodríguez', 'usuario' => 'cajero', 'email' => 'cajero@nexopos.do', 'password_hash' => password_hash('cajero123', PASSWORD_DEFAULT), 'comision_pct' => 2.50]);
        $vendedores = [$uAdmin, $uGerente, $uCajero];

        // ----- Métodos de pago -----
        // dgii_tipo_pago mapea al desglose de cobro del Formato 607 (columnas 17-23).
        $mpEfectivo = dbInsert('metodos_pago', ['nombre' => 'Efectivo', 'afecta_caja' => 1, 'dgii_tipo_pago' => 1]);
        dbInsert('metodos_pago', ['nombre' => 'Tarjeta de Crédito/Débito', 'afecta_caja' => 0, 'dgii_tipo_pago' => 3]);
        dbInsert('metodos_pago', ['nombre' => 'Transferencia', 'afecta_caja' => 0, 'dgii_tipo_pago' => 2]);
        dbInsert('metodos_pago', ['nombre' => 'Crédito (Cuenta)', 'afecta_caja' => 0, 'es_credito' => 1, 'dgii_tipo_pago' => 4]);

        // ----- NCF -----
        dbInsert('ncf_secuencias', ['tipo' => 'B02', 'descripcion' => 'Consumidor Final', 'prefijo' => 'B', 'secuencia_actual' => 1, 'secuencia_hasta' => 99999999, 'vencimiento' => date('Y-12-31', strtotime('+1 year'))]);
        dbInsert('ncf_secuencias', ['tipo' => 'B01', 'descripcion' => 'Crédito Fiscal', 'prefijo' => 'B', 'secuencia_actual' => 1, 'secuencia_hasta' => 99999999, 'vencimiento' => date('Y-12-31', strtotime('+1 year'))]);

        // ----- Unidades -----
        $unidades = [];
        foreach ([['Unidad', 'UND'], ['Libra', 'LB'], ['Galón', 'GAL'], ['Caja', 'CAJ'], ['Paquete', 'PAQ'], ['Litro', 'LT']] as $u) {
            $unidades[] = dbInsert('unidades', ['nombre' => $u[0], 'abreviatura' => $u[1]]);
        }

        // ----- Marcas -----
        $marcas = [];
        foreach (['Genérica', 'Nike', 'Adidas', 'Samsung', 'LG', 'Nestlé', 'Bohemia'] as $m) {
            $marcas[$m] = dbInsert('marcas', ['nombre' => $m]);
        }

        // ----- Categorías (productos diversos) -----
        $cats = [];
        $catData = [
            ['Bebidas', 'sky'], ['Alimentos', 'amber'], ['Limpieza', 'cyan'], ['Electrónica', 'indigo'],
            ['Ropa y Calzado', 'rose'], ['Ferretería', 'slate'], ['Hogar', 'emerald'], ['Salud y Belleza', 'pink'],
        ];
        foreach ($catData as $c) {
            $cats[$c[0]] = dbInsert('categorias', ['nombre' => $c[0], 'color' => $c[1]]);
        }

        // ----- Proveedores -----
        $prov1 = dbInsert('proveedores', ['codigo' => 'PRV-001', 'nombre' => 'Distribuidora Nacional SRL', 'rnc' => '1-01-00001-1', 'contacto' => 'José Ramírez', 'telefono' => '809-200-1000', 'email' => 'ventas@disnacional.do']);
        $prov2 = dbInsert('proveedores', ['codigo' => 'PRV-002', 'nombre' => 'Importadora del Caribe', 'rnc' => '1-01-00002-2', 'contacto' => 'Ana Gómez', 'telefono' => '809-200-2000', 'email' => 'compras@impcaribe.do']);

        // ----- Productos -----
        $productos = [];
        $catalogo = [
            ['Refresco Cola 2L', 'Bebidas', 'Genérica', 45, 75, 'UND'],
            ['Agua Mineral 1 Galón', 'Bebidas', 'Genérica', 30, 55, 'GAL'],
            ['Cerveza Bohemia 12oz', 'Bebidas', 'Bohemia', 38, 65, 'UND'],
            ['Arroz Selecto 5 Libras', 'Alimentos', 'Genérica', 120, 185, 'PAQ'],
            ['Aceite Vegetal 1L', 'Alimentos', 'Genérica', 95, 145, 'LT'],
            ['Café Molido 1 Libra', 'Alimentos', 'Nestlé', 140, 210, 'LB'],
            ['Detergente en Polvo 5kg', 'Limpieza', 'Genérica', 210, 295, 'PAQ'],
            ['Cloro 1 Galón', 'Limpieza', 'Genérica', 55, 95, 'GAL'],
            ['Audífonos Bluetooth', 'Electrónica', 'Samsung', 850, 1450, 'UND'],
            ['Cargador USB-C 25W', 'Electrónica', 'Samsung', 320, 595, 'UND'],
            ['Bombillo LED 9W', 'Electrónica', 'LG', 75, 135, 'UND'],
            ['Tenis Deportivos', 'Ropa y Calzado', 'Nike', 1800, 2950, 'UND'],
            ['Camiseta Algodón', 'Ropa y Calzado', 'Adidas', 280, 550, 'UND'],
            ['Martillo 16oz', 'Ferretería', 'Genérica', 165, 285, 'UND'],
            ['Juego de Destornilladores', 'Ferretería', 'Genérica', 240, 425, 'CAJ'],
            ['Set de Toallas', 'Hogar', 'Genérica', 350, 595, 'PAQ'],
            ['Almohada Viscoelástica', 'Hogar', 'Genérica', 420, 750, 'UND'],
            ['Shampoo 750ml', 'Salud y Belleza', 'Genérica', 110, 195, 'UND'],
            ['Jabón de Tocador x3', 'Salud y Belleza', 'Genérica', 60, 110, 'PAQ'],
            ['Pasta Dental 100ml', 'Salud y Belleza', 'Genérica', 48, 89, 'UND'],
        ];
        $i = 1;
        foreach ($catalogo as $p) {
            $pid = dbInsert('productos', [
                'codigo' => 'SKU-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'codigo_barras' => '74' . str_pad((string) (1000000 + $i), 10, '0', STR_PAD_LEFT),
                'nombre' => $p[0],
                'categoria_id' => $cats[$p[1]],
                'marca_id' => $marcas[$p[2]],
                'unidad_id' => $unidades[0],
                'precio_compra' => $p[3],
                'precio_venta' => $p[4],
                'itbis_aplica' => 1,
                'stock_minimo' => 10,
            ]);
            $productos[$pid] = ['nombre' => $p[0], 'precio_compra' => $p[3], 'precio_venta' => $p[4]];
            // Stock inicial en ambas sucursales
            foreach ([$sucPrincipal, $sucSantiago] as $suc) {
                $stockIni = mt_rand(15, 80);
                dbInsert('inventario_stock', ['producto_id' => $pid, 'sucursal_id' => $suc, 'cantidad' => $stockIni]);
                dbInsert('movimientos_inventario', [
                    'producto_id' => $pid, 'sucursal_id' => $suc, 'tipo' => 'entrada',
                    'cantidad' => $stockIni, 'stock_anterior' => 0, 'stock_nuevo' => $stockIni,
                    'costo_unitario' => $p[3], 'referencia_tipo' => 'inicial', 'motivo' => 'Inventario inicial',
                    'created_at' => date('Y-m-d H:i:s', strtotime('-40 days')),
                ]);
            }
            $i++;
        }

        // ----- Clientes -----
        dbInsert('clientes', ['codigo' => 'CLI-00001', 'nombre' => 'Cliente Genérico', 'tipo' => 'contado']);
        dbInsert('clientes', ['codigo' => 'CLI-00002', 'nombre' => 'Supermercado El Ahorro', 'rnc_cedula' => '1-30-55555-5', 'telefono' => '809-300-1111', 'tipo' => 'credito', 'limite_credito' => 50000]);
        dbInsert('clientes', ['codigo' => 'CLI-00003', 'nombre' => 'Juan Pérez', 'rnc_cedula' => '001-1234567-8', 'telefono' => '809-400-2222', 'tipo' => 'contado']);

        // ----- RRHH: Departamentos y Puestos -----
        $depAdmin = dbInsert('departamentos', ['nombre' => 'Administración', 'descripcion' => 'Dirección y administración general']);
        $depVentas= dbInsert('departamentos', ['nombre' => 'Ventas', 'descripcion' => 'Equipo comercial y cajas']);
        $depAlm   = dbInsert('departamentos', ['nombre' => 'Almacén', 'descripcion' => 'Gestión de inventario']);
        $depRRHH  = dbInsert('departamentos', ['nombre' => 'Recursos Humanos', 'descripcion' => 'Gestión del personal']);

        $puGerente = dbInsert('puestos', ['departamento_id' => $depAdmin, 'nombre' => 'Gerente General', 'salario_base' => 85000]);
        $puCajero  = dbInsert('puestos', ['departamento_id' => $depVentas, 'nombre' => 'Cajero', 'salario_base' => 24000]);
        $puVend    = dbInsert('puestos', ['departamento_id' => $depVentas, 'nombre' => 'Vendedor', 'salario_base' => 28000]);
        $puAlm     = dbInsert('puestos', ['departamento_id' => $depAlm, 'nombre' => 'Encargado de Almacén', 'salario_base' => 32000]);
        $puRRHH    = dbInsert('puestos', ['departamento_id' => $depRRHH, 'nombre' => 'Auxiliar de RRHH', 'salario_base' => 30000]);

        $emps = [
            ['Carlos', 'Méndez', '001-1111111-1', $sucPrincipal, $depAdmin, $puGerente, 85000, 'M'],
            ['María', 'Rodríguez', '001-2222222-2', $sucPrincipal, $depVentas, $puCajero, 24000, 'F'],
            ['Pedro', 'Santana', '031-3333333-3', $sucSantiago, $depVentas, $puVend, 28000, 'M'],
            ['Laura', 'Peña', '031-4444444-4', $sucSantiago, $depAdmin, $puGerente, 80000, 'F'],
            ['José', 'Fernández', '001-5555555-5', $sucPrincipal, $depAlm, $puAlm, 32000, 'M'],
            ['Ana', 'Jiménez', '001-6666666-6', $sucPrincipal, $depRRHH, $puRRHH, 30000, 'F'],
        ];
        $e = 1;
        foreach ($emps as $em) {
            dbInsert('empleados', [
                'codigo' => 'EMP-' . str_pad((string) $e, 4, '0', STR_PAD_LEFT),
                'sucursal_id' => $em[3], 'departamento_id' => $em[4], 'puesto_id' => $em[5],
                'nombre' => $em[0], 'apellido' => $em[1], 'cedula' => $em[2], 'genero' => $em[7],
                'telefono' => '809-' . mt_rand(200, 899) . '-' . mt_rand(1000, 9999),
                'fecha_ingreso' => date('Y-m-d', strtotime('-' . mt_rand(200, 1200) . ' days')),
                'salario' => $em[6], 'metodo_pago' => 'transferencia', 'banco' => 'Banco Popular',
            ]);
            $e++;
        }

        // ----- Finanzas: categorías y cuentas -----
        $catVentas = dbInsert('categorias_financieras', ['tipo' => 'ingreso', 'nombre' => 'Ventas']);
        dbInsert('categorias_financieras', ['tipo' => 'ingreso', 'nombre' => 'Otros Ingresos']);
        $catMerc = dbInsert('categorias_financieras', ['tipo' => 'gasto', 'nombre' => 'Compra de Mercancía']);
        $catNomina = dbInsert('categorias_financieras', ['tipo' => 'gasto', 'nombre' => 'Nómina']);
        $catAlq = dbInsert('categorias_financieras', ['tipo' => 'gasto', 'nombre' => 'Alquiler']);
        $catServ = dbInsert('categorias_financieras', ['tipo' => 'gasto', 'nombre' => 'Servicios (luz, agua, internet)']);
        dbInsert('categorias_financieras', ['tipo' => 'gasto', 'nombre' => 'Otros Gastos']);

        $cuentaPrin = dbInsert('cuentas_financieras', ['sucursal_id' => $sucPrincipal, 'nombre' => 'Caja General - Principal', 'tipo' => 'efectivo']);
        $cuentaSant = dbInsert('cuentas_financieras', ['sucursal_id' => $sucSantiago, 'nombre' => 'Caja General - Santiago', 'tipo' => 'efectivo']);
        dbInsert('cuentas_financieras', ['sucursal_id' => null, 'nombre' => 'Banco Popular', 'tipo' => 'banco', 'balance' => 250000]);

        // ----- Cajas físicas -----
        dbInsert('cajas', ['sucursal_id' => $sucPrincipal, 'nombre' => 'Caja 1']);
        dbInsert('cajas', ['sucursal_id' => $sucPrincipal, 'nombre' => 'Caja 2']);
        dbInsert('cajas', ['sucursal_id' => $sucSantiago, 'nombre' => 'Caja 1']);

        // ----- Ventas de ejemplo (últimos 14 días) para poblar dashboard/reportes -----
        $pids = array_keys($productos);
        $ventaNum = 1;
        $sucCuenta = [$sucPrincipal => $cuentaPrin, $sucSantiago => $cuentaSant];
        for ($d = 14; $d >= 0; $d--) {
            $ventasDia = mt_rand(2, 5);
            for ($v = 0; $v < $ventasDia; $v++) {
                $suc = mt_rand(0, 1) ? $sucPrincipal : $sucSantiago;
                $nItems = mt_rand(1, 4);
                $subtotal = 0; $itbis = 0; $costo = 0; $lineas = [];
                $elegidos = (array) array_rand(array_flip($pids), min($nItems, count($pids)));
                foreach ($elegidos as $pid) {
                    $cant = mt_rand(1, 3);
                    $precio = $productos[$pid]['precio_venta'];
                    $base = $precio * $cant;
                    $iti = round($base * 0.18, 2);
                    $subtotal += $base; $itbis += $iti; $costo += $productos[$pid]['precio_compra'] * $cant;
                    $lineas[] = ['pid' => $pid, 'cant' => $cant, 'precio' => $precio, 'itbis' => $iti, 'subtotal' => $base];
                }
                $total = $subtotal + $itbis;
                $fecha = date('Y-m-d H:i:s', strtotime("-$d days " . mt_rand(8, 19) . ':' . mt_rand(10, 59) . ':00'));
                $vid = dbInsert('ventas', [
                    'numero' => 'VTA-' . str_pad((string) $ventaNum, 6, '0', STR_PAD_LEFT),
                    'sucursal_id' => $suc, 'caja_sesion_id' => null, 'cliente_id' => 1, 'usuario_id' => $vendedores[array_rand($vendedores)],
                    'fecha' => $fecha, 'subtotal' => $subtotal, 'descuento' => 0, 'itbis' => $itbis,
                    'total' => $total, 'costo_total' => $costo, 'tipo_comprobante' => 'consumidor', 'estado' => 'completada',
                    'created_at' => $fecha,
                ]);
                foreach ($lineas as $l) {
                    dbInsert('venta_detalles', [
                        'venta_id' => $vid, 'producto_id' => $l['pid'], 'descripcion' => $productos[$l['pid']]['nombre'],
                        'cantidad' => $l['cant'], 'precio_unitario' => $l['precio'],
                        'costo_unitario' => $productos[$l['pid']]['precio_compra'],
                        'itbis' => $l['itbis'], 'subtotal' => $l['subtotal'],
                    ]);
                    // Descontar stock + movimiento
                    $stockRow = qOne("SELECT id, cantidad FROM inventario_stock WHERE producto_id=? AND sucursal_id=?", [$l['pid'], $suc]);
                    if ($stockRow) {
                        $nuevo = max(0, $stockRow['cantidad'] - $l['cant']);
                        q("UPDATE inventario_stock SET cantidad=? WHERE id=?", [$nuevo, $stockRow['id']]);
                        dbInsert('movimientos_inventario', [
                            'producto_id' => $l['pid'], 'sucursal_id' => $suc, 'tipo' => 'venta',
                            'cantidad' => -$l['cant'], 'stock_anterior' => $stockRow['cantidad'], 'stock_nuevo' => $nuevo,
                            'costo_unitario' => $productos[$l['pid']]['precio_compra'],
                            'referencia_tipo' => 'venta', 'referencia_id' => $vid, 'usuario_id' => 1, 'created_at' => $fecha,
                        ]);
                    }
                }
                dbInsert('venta_pagos', ['venta_id' => $vid, 'metodo_pago_id' => 1, 'monto' => $total]);
                dbInsert('transacciones', [
                    'sucursal_id' => $suc, 'cuenta_id' => $sucCuenta[$suc], 'tipo' => 'ingreso',
                    'categoria_id' => $catVentas, 'monto' => $total, 'descripcion' => 'Venta VTA-' . str_pad((string) $ventaNum, 6, '0', STR_PAD_LEFT),
                    'referencia_tipo' => 'venta', 'referencia_id' => $vid, 'fecha' => date('Y-m-d', strtotime($fecha)), 'usuario_id' => 1, 'created_at' => $fecha,
                ]);
                $ventaNum++;
            }
        }

        // ----- Gastos de ejemplo -----
        dbInsert('transacciones', ['sucursal_id' => $sucPrincipal, 'cuenta_id' => $cuentaPrin, 'tipo' => 'gasto', 'categoria_id' => $catAlq, 'monto' => 45000, 'descripcion' => 'Alquiler del local', 'referencia_tipo' => 'manual', 'fecha' => date('Y-m-01'), 'usuario_id' => 1]);
        dbInsert('transacciones', ['sucursal_id' => $sucPrincipal, 'cuenta_id' => $cuentaPrin, 'tipo' => 'gasto', 'categoria_id' => $catServ, 'monto' => 12500, 'descripcion' => 'Electricidad y agua', 'referencia_tipo' => 'manual', 'fecha' => date('Y-m-05'), 'usuario_id' => 1]);
        dbInsert('transacciones', ['sucursal_id' => $sucSantiago, 'cuenta_id' => $cuentaSant, 'tipo' => 'gasto', 'categoria_id' => $catServ, 'monto' => 8900, 'descripcion' => 'Internet y teléfono', 'referencia_tipo' => 'manual', 'fecha' => date('Y-m-08'), 'usuario_id' => 1]);
    });
}

// ---------- Ejecución ----------
$lockFile = __DIR__ . '/installed.lock';
$bloqueado = is_file($lockFile) && APP_ENV === 'production';

$yaInstalado = false;
try {
    $yaInstalado = (bool) qVal("SELECT 1 FROM empresa LIMIT 1");
} catch (Throwable $e) {
    $yaInstalado = false;
}

// Una instalación existente solo puede reemplazarla un superadministrador
// autenticado. El primer despliegue (sin tablas) sigue disponible públicamente.
if (!$cli && $yaInstalado && !is_super()) {
    $bloqueado = true;
    http_response_code(403);
    $errores[] = 'La reinstalación requiere iniciar sesión como superadministrador.';
}

$produccion = $cli ? in_array('--produccion', $argv ?? [], true) : (post('produccion') === 'si');
$debeInstalar = $cli || (isPost() && post('confirmar') === 'si');

if (!$cli && isPost()) {
    verify_csrf();
}

if ($bloqueado && !$cli) {
    $errores[] = 'El sistema ya está instalado. Por seguridad, elimina la carpeta /install del servidor.';
} elseif ($debeInstalar) {
    try {
        if ($yaInstalado && post('forzar') !== 'si' && !$cli) {
            $errores[] = 'El sistema ya está instalado. Marca «reinstalar» para borrar y empezar de cero.';
        } else {
            if ($yaInstalado) {
                // Reinstalación: el esquema con DROP TABLE limpia todo.
                paso('Reinstalando: se eliminarán los datos existentes.');
            }
            ejecutarInstalacion($produccion);
            $instalado = true;
        }
    } catch (Throwable $e) {
        $errores[] = $e->getMessage();
    }
}

if ($cli) {
    echo "== Instalador NexoPOS (CLI) ==\n";
    foreach ($log as $l) echo " [OK] $l\n";
    foreach ($errores as $er) echo " [ERROR] $er\n";
    echo $instalado ? "\nInstalación completada. Usuario: admin / admin123\n" : "\nNo se completó la instalación.\n";
    exit($instalado ? 0 : 1);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalador · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-6">
<div class="max-w-lg w-full bg-white rounded-2xl shadow-xl border border-slate-200 p-8">
  <div class="flex items-center gap-3 mb-6">
    <div class="w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-xl font-extrabold shadow-lg shadow-blue-600/30">N</div>
    <div>
      <h1 class="text-xl font-extrabold text-slate-800"><?= e(APP_NAME) ?></h1>
      <p class="text-sm text-slate-500">Asistente de instalación</p>
    </div>
  </div>

  <?php if ($instalado): ?>
    <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4 mb-5">
      <p class="font-semibold text-emerald-800 mb-2">✓ Instalación completada</p>
      <ul class="text-sm text-emerald-700 space-y-1">
        <?php foreach ($log as $l): ?><li>• <?= e($l) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 mb-5 text-sm">
      <p class="font-semibold text-slate-700 mb-2">Credenciales de acceso</p>
      <table class="w-full text-slate-600">
        <tr><td class="py-1 font-medium">Super Admin</td><td><code class="bg-white px-2 py-0.5 rounded border">admin</code> / <code class="bg-white px-2 py-0.5 rounded border">admin123</code></td></tr>
        <tr><td class="py-1 font-medium">Gerente</td><td><code class="bg-white px-2 py-0.5 rounded border">gerente</code> / <code class="bg-white px-2 py-0.5 rounded border">gerente123</code></td></tr>
        <tr><td class="py-1 font-medium">Cajero</td><td><code class="bg-white px-2 py-0.5 rounded border">cajero</code> / <code class="bg-white px-2 py-0.5 rounded border">cajero123</code></td></tr>
      </table>
    </div>
    <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 mb-5 text-sm">
      <p class="font-semibold text-amber-800 mb-1">⚠ Importante para producción</p>
      <ul class="list-disc list-inside space-y-1 text-amber-700">
        <li>Cambia de inmediato la contraseña de <strong>admin</strong> (menú usuario → Mi perfil).</li>
        <li><strong>Elimina la carpeta <code>/install</code></strong> del servidor por seguridad.</li>
      </ul>
    </div>
    <a href="<?= e(url('modules/auth/login.php')) ?>" class="block text-center bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-3 rounded-xl transition">Ir al inicio de sesión →</a>
  <?php else: ?>
    <?php foreach ($errores as $er): ?>
      <div class="rounded-xl bg-rose-50 border border-rose-200 p-3 mb-4 text-sm text-rose-700">⚠ <?= e($er) ?></div>
    <?php endforeach; ?>
    <p class="text-slate-600 text-sm mb-5">Este asistente creará la base de datos <code class="bg-slate-100 px-1.5 py-0.5 rounded">«<?= e(DB_NAME) ?>»</code>, todas las tablas y datos de ejemplo (sucursales, productos, empleados y ventas de demostración).</p>
    <?php if (!$bloqueado): ?>
    <form method="post" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="confirmar" value="si">
      <label class="flex items-start gap-2 text-sm text-slate-700 bg-blue-50 border border-blue-200 rounded-xl p-3">
        <input type="checkbox" name="produccion" value="si" class="mt-0.5">
        <span><strong>Instalación de producción</strong> (recomendado al desplegar): instala solo la
        configuración esencial, <strong>sin</strong> productos, ventas ni empleados de demostración.</span>
      </label>
      <?php if ($yaInstalado): ?>
        <label class="flex items-start gap-2 text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-3">
          <input type="checkbox" name="forzar" value="si" class="mt-0.5">
          <span>Ya existe una instalación. <strong>Reinstalar borrará todos los datos actuales.</strong> Marca esta casilla para continuar.</span>
        </label>
      <?php endif; ?>
      <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-3 rounded-xl transition shadow-lg shadow-blue-600/30">Instalar ahora</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  <p class="text-xs text-slate-400 mt-6 text-center">PHP <?= PHP_VERSION ?> · <?= e(DB_HOST) ?></p>
</div>
</body>
</html>
