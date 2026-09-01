<?php
/**
 * Deja la base lista para empezar a operar de verdad.
 *
 *   php database/limpiar_datos_prueba.php            (solo enseña lo que haría)
 *   php database/limpiar_datos_prueba.php --aplicar
 *
 * ============================================================================
 *  QUÉ BORRA Y QUÉ NO
 * ============================================================================
 *
 * BORRA lo que se generó probando: ventas, devoluciones, comprobantes
 * electrónicos y su bitácora, movimientos de inventario, transacciones, caja,
 * nóminas y cotizaciones. Devuelve las existencias al número con el que
 * arrancaron y pone los contadores a cero, para que la primera venta de verdad
 * sea la VTA-000001.
 *
 * NO BORRA el catálogo (productos, categorías, marcas), las sucursales, las
 * tiendas, los usuarios y roles, los 58 empleados con sus departamentos y
 * puestos, ni los parámetros de la TSS. Eso es configuración real y costó
 * cargarla.
 *
 * TAMPOCO BORRA la auditoría. Es el registro de quién hizo qué, y una limpieza
 * que empieza borrando el rastro de la limpieza no es una limpieza.
 *
 * ---------------------------------------------------------------------------
 *  LAS SECUENCIAS ELECTRÓNICAS
 *
 * Las que había eran del rango de certificación (9000xx). Se sustituyen por las
 * dos AUTORIZADAS por la DGII el 27/08/2026:
 *
 *   E31  Crédito Fiscal      1085 → 3084     vence 31/12/2027   aut. 6005458872
 *   E32  Consumo            12001 → 16213    sin vencimiento    aut. 6005458879
 *
 * El vencimiento de la E32 va en blanco a propósito: la autorización dice «N/A»
 * y ese campo solo es obligatorio en la 31 y la 33. Poner una fecha inventada es
 * exactamente lo que hacía que la DGII rechazara con el código 145.
 *
 * Las de papel (B01, B02, B04) se quedan como están: nunca se usaron y son el
 * respaldo si el proveedor electrónico se cae.
 *
 * ---------------------------------------------------------------------------
 *  ES IRREVERSIBLE
 *
 * Antes de correrlo con --aplicar hay que tener el respaldo hecho. Se avisa por
 * pantalla y se pide confirmación escrita.
 */

$raiz    = dirname(__DIR__);
$aplicar = in_array('--aplicar', $argv, true);
$forzar  = in_array('--si-estoy-seguro', $argv, true);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/cli.php';
$_SERVER['HTTP_HOST'] = 'localhost';
ob_start();
require $raiz . '/app/bootstrap.php';
ob_end_clean();

/* ============================================================
 *  Las secuencias oficiales
 * ============================================================ */
const SECUENCIAS_OFICIALES = [
    ['tipo' => 'E31', 'descripcion' => 'Factura de Crédito Fiscal Electrónica',
     'desde' => 1085,  'hasta' => 3084,  'vencimiento' => '2027-12-31',
     'autorizacion' => '6005458872', 'autorizada_at' => '2026-08-27'],
    ['tipo' => 'E32', 'descripcion' => 'Factura de Consumo Electrónica',
     'desde' => 12001, 'hasta' => 16213, 'vencimiento' => null,
     'autorizacion' => '6005458879', 'autorizada_at' => '2026-08-27'],
];

/* ============================================================
 *  Lo que hay ahora
 * ============================================================ */
$cuenta = static function (string $tabla, string $where = '1=1'): int {
    try { return (int) qVal("SELECT COUNT(*) FROM `$tabla` WHERE $where"); }
    catch (Throwable $e) { return -1; }
};

$borrar = [
    'ecf_log'                => 'Bitácora de llamadas al proveedor de e-CF',
    'ecf_documentos'         => 'Comprobantes electrónicos emitidos en certificación',
    'devolucion_detalles'    => 'Líneas de devolución',
    'devoluciones'           => 'Devoluciones',
    'comprobantes_anulados'  => 'Comprobantes anulados (formato 608)',
    'venta_detalles'         => 'Líneas de venta',
    'venta_pagos'            => 'Cobros de las ventas',
    'ventas'                 => 'Ventas',
    'movimientos_inventario' => 'Movimientos de inventario (kardex)',
    'transacciones'          => 'Ingresos y gastos (incluye el pago de nómina)',
    'caja_movimientos'       => 'Movimientos de caja',
    'caja_sesiones'          => 'Sesiones de caja',
    'nomina_detalles'        => 'Líneas de nómina (los 58 empleados por período)',
    'nominas'                => 'Nóminas',
    'cotizacion_detalles'    => 'Líneas de cotización',
    'cotizaciones'           => 'Cotizaciones',
    'ncf_reservas'           => 'Reservas de NCF',
    'notificaciones'         => 'Alertas del centro de notificaciones',
];

echo "\n" . str_repeat('=', 74) . "\n";
echo "  LIMPIEZA DE DATOS DE PRUEBA · " . DB_NAME . " en " . DB_HOST . "\n";
echo str_repeat('=', 74) . "\n";
echo $aplicar ? "  MODO: APLICAR (irreversible)\n\n" : "  MODO: solo mostrar. Añade --aplicar para ejecutar.\n\n";

echo "SE BORRA\n";
$total = 0;
foreach ($borrar as $t => $desc) {
    $n = $cuenta($t);
    if ($n < 0) { printf("  %-24s (la tabla no existe)\n", $t); continue; }
    $total += $n;
    printf("  %-24s %5d   %s\n", $t, $n, $desc);
}
$cliPrueba = qAll("SELECT id, codigo, nombre FROM clientes WHERE id <> 1");
foreach ($cliPrueba as $c) printf("  %-24s %5d   Cliente de prueba: %s\n", 'clientes', 1, $c['nombre']);

echo "\nSE REINICIA\n";
$stock = qAll(
    "SELECT s.producto_id, s.sucursal_id, p.nombre, s.cantidad,
            (SELECT m.stock_anterior FROM movimientos_inventario m
              WHERE m.producto_id = s.producto_id AND m.sucursal_id = s.sucursal_id
              ORDER BY m.id LIMIT 1) AS inicial
       FROM inventario_stock s JOIN productos p ON p.id = s.producto_id
      WHERE s.cantidad <> 0"
);
foreach ($stock as $s) {
    printf("  %-24s %s: %s → %s\n", 'inventario_stock', mb_substr($s['nombre'], 0, 30),
        qty($s['cantidad']), qty($s['inicial'] ?? 0));
}
foreach (qAll("SELECT nombre, balance FROM cuentas_financieras WHERE balance <> 0") as $c) {
    printf("  %-24s %s: %s → 0.00\n", 'cuentas_financieras', mb_substr($c['nombre'], 0, 30), money($c['balance'], false));
}
foreach (qAll("SELECT nombre, valor FROM contadores WHERE nombre IN
               ('ventas.numero.VTA','devoluciones.numero.DEV','cotizaciones.numero.COT') AND valor > 0") as $c) {
    printf("  %-24s %s: %s → 0\n", 'contadores', $c['nombre'], $c['valor']);
}

echo "\nSE SUSTITUYE\n";
foreach (qAll("SELECT tipo, secuencia_actual, secuencia_hasta, vencimiento FROM ncf_secuencias
                WHERE prefijo = 'E' ORDER BY tipo") as $s) {
    printf("  %-24s %s de certificación (%s → %s) se elimina\n", 'ncf_secuencias', $s['tipo'],
        $s['secuencia_actual'], $s['secuencia_hasta']);
}
foreach (SECUENCIAS_OFICIALES as $s) {
    printf("  %-24s %s OFICIAL %s → %s · vence %s · aut. %s\n", '', $s['tipo'],
        $s['desde'], $s['hasta'], $s['vencimiento'] ?? 'sin vencimiento', $s['autorizacion']);
}

echo "\nNO SE TOCA\n";
foreach (['productos' => 'catálogo', 'categorias' => 'categorías', 'tiendas' => 'marcas',
          'sucursales' => 'sucursales', 'usuarios' => 'usuarios', 'roles' => 'roles',
          'permisos' => 'permisos', 'empleados' => 'empleados', 'departamentos' => 'departamentos',
          'puestos' => 'puestos', 'tss_parametros' => 'parámetros de la TSS',
          'auditoria' => 'auditoría (el registro de quién hizo qué)'] as $t => $d) {
    $n = $cuenta($t);
    if ($n >= 0) printf("  %-24s %5d   %s\n", $t, $n, $d);
}

if (!$aplicar) {
    echo "\n  Nada se ha modificado. Para ejecutarlo:\n";
    echo "      php database/limpiar_datos_prueba.php --aplicar --si-estoy-seguro\n\n";
    exit(0);
}

if (!$forzar) {
    echo "\n  ✗ Falta --si-estoy-seguro. Es irreversible: haz el respaldo antes.\n\n";
    exit(1);
}

/* ============================================================
 *  Ejecución
 * ============================================================ */
echo "\n" . str_repeat('-', 74) . "\n  EJECUTANDO\n" . str_repeat('-', 74) . "\n";

tx(function () use ($borrar, $stock) {
    // Se apagan las claves foráneas durante el borrado: el orden correcto
    // existe, pero una tabla nueva con una foránea que nadie recuerde dejaría
    // esto a medias. Se vuelven a encender al terminar, dentro de la misma
    // transacción, así que la base nunca queda sin control de integridad.
    q("SET FOREIGN_KEY_CHECKS = 0");

    foreach (array_keys($borrar) as $t) {
        try { q("DELETE FROM `$t`"); printf("  vaciada  %s\n", $t); }
        catch (Throwable $e) { printf("  OJO      %s: %s\n", $t, $e->getMessage()); }
    }
    q("DELETE FROM clientes WHERE id <> 1");
    echo "  vaciada  clientes (se conserva el Cliente Genérico)\n";

    // Existencias como estaban antes de la primera prueba.
    foreach ($stock as $s) {
        q("UPDATE inventario_stock SET cantidad = ? WHERE producto_id = ? AND sucursal_id = ?",
          [(float) ($s['inicial'] ?? 0), (int) $s['producto_id'], (int) $s['sucursal_id']]);
    }
    printf("  repuesto stock de %d producto(s)\n", count($stock));

    q("UPDATE cuentas_financieras SET balance = 0");
    echo "  saldos de las cuentas a cero\n";

    q("UPDATE contadores SET valor = 0
        WHERE nombre IN ('ventas.numero.VTA','devoluciones.numero.DEV','cotizaciones.numero.COT')");
    echo "  contadores de ventas, devoluciones y cotizaciones a cero\n";

    // Secuencias: fuera las de certificación, dentro las autorizadas.
    q("DELETE FROM ncf_secuencias WHERE prefijo = 'E'");
    foreach (SECUENCIAS_OFICIALES as $s) {
        dbInsert('ncf_secuencias', [
            'tipo'             => $s['tipo'],
            'descripcion'      => $s['descripcion'],
            'prefijo'          => 'E',
            'secuencia_actual' => $s['desde'],
            'secuencia_hasta'  => $s['hasta'],
            'vencimiento'      => $s['vencimiento'],
            'autorizacion'     => $s['autorizacion'],
            'autorizada_at'    => $s['autorizada_at'],
            'ambiente'         => 'produccion',
            'activo'           => 1,
        ]);
        printf("  cargada  %s oficial: %s → %s\n", $s['tipo'], $s['desde'], $s['hasta']);
    }

    q("SET FOREIGN_KEY_CHECKS = 1");
});

echo "\n" . str_repeat('=', 74) . "\n  COMPROBACIÓN FINAL\n" . str_repeat('=', 74) . "\n";
foreach (['ventas', 'devoluciones', 'ecf_documentos', 'nominas', 'transacciones',
          'caja_sesiones', 'movimientos_inventario'] as $t) {
    printf("  %-24s %d\n", $t, $cuenta($t));
}
printf("  %-24s %d  (los 58 del padrón siguen ahí)\n", 'empleados', $cuenta('empleados'));
printf("  %-24s %d\n", 'productos', $cuenta('productos'));
foreach (qAll("SELECT tipo, secuencia_actual, secuencia_hasta, vencimiento, autorizacion
                 FROM ncf_secuencias ORDER BY prefijo, tipo") as $s) {
    printf("  secuencia %-5s %s → %s · vence %s · aut. %s\n", $s['tipo'], $s['secuencia_actual'],
        $s['secuencia_hasta'], (string) ($s['vencimiento'] ?: 'N/A'), (string) ($s['autorizacion'] ?: '—'));
}
echo "\n";
