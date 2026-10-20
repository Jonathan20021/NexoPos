<?php
/**
 * Banco de pruebas de las monedas (includes/monedas.php).
 *
 *   php pruebas/monedas.php
 *
 * El catálogo puede estar en dólares y la caja cobra en pesos: cada conversión
 * que falla aquí es un precio mal cobrado o un margen mal calculado en la
 * tienda. Cubre la ida (precio → pesos), la vuelta (costo en pesos → moneda
 * del producto), la tasa de venta automática y la nota del pago en dólares.
 *
 * Necesita la base (lee `monedas`), pero todo lo que escribe va dentro de una
 * transacción que se deshace al final. Devuelve 0 si todo pasa y 1 si falla.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$fallos = 0;
$total  = 0;

function comprueba(string $caso, $obtenido, $esperado, float $tol = 0.001): void
{
    global $fallos, $total;
    $total++;
    $ok = is_float($esperado) ? abs((float) $obtenido - $esperado) <= $tol : $obtenido === $esperado;
    if (!$ok) $fallos++;
    printf("  %s %-66s %s\n", $ok ? 'OK   ' : 'FALLA', $caso,
        $ok ? '' : 'obtenido ' . var_export($obtenido, true) . ', esperado ' . var_export($esperado, true));
}

function titulo(string $t): void { echo "\n== $t ==\n"; }

if (!mon_p39()) {
    echo "Falta la P39 (migracion_moneda_producto_p39.sql): no hay nada que probar.\n";
    exit(0);
}

$usd = (int) qVal("SELECT id FROM monedas WHERE codigo = 'USD'");
if (!$usd) { echo "No hay dólar en `monedas`.\n"; exit(1); }

db()->beginTransaction();
try {
    // Punto de partida conocido: dólar activo, a 60, venta a mano.
    q("UPDATE monedas SET tasa = 60, activo = 1, tasa_mercado = NULL, tasa_mercado_at = NULL WHERE id = ?", [$usd]);
    if (mon_p41()) q("UPDATE monedas SET venta_auto = 0, venta_margen = 0 WHERE id = ?", [$usd]);
    monedas(true, true);

    /* ============================================================ */
    titulo('Ida: el precio del catálogo en pesos');
    comprueba('US$29 a 60 → RD$1,740', mon_precio_base(['precio_venta' => 29, 'moneda_id' => $usd]), 1740.0);
    comprueba('un producto sin moneda está en pesos', mon_precio_base(['precio_venta' => 1200, 'moneda_id' => null]), 1200.0);
    comprueba('el costo también: US$11.50 → RD$690', mon_costo_base(['precio_compra' => 11.5, 'moneda_id' => $usd]), 690.0);
    comprueba('importe suelto con moneda', mon_precio_base(10.0, $usd), 600.0);

    titulo('Vuelta: un costo en pesos a la moneda del producto');
    comprueba('RD$690 a un producto en dólares → US$11.50', mon_costo_a_producto(690, $usd), 11.5);
    comprueba('con la tasa del documento (62) en vez de la de venta', mon_costo_a_producto(720, $usd, 62.0), 11.61);
    comprueba('un producto en pesos lo guarda tal cual', mon_costo_a_producto(690, null), 690.0);
    comprueba('ida y vuelta devuelven el mismo costo',
        mon_costo_a_producto(mon_costo_base(['precio_compra' => 37.25, 'moneda_id' => $usd]), $usd), 37.25);

    titulo('Las dos monedas de una celda');
    $d = mon_dos_monedas(29, $usd);
    comprueba('producto en dólares: pesos', $d['base'], 1740.0);
    comprueba('producto en dólares: el dólar es el exacto', $d['ref'], 29.0);
    comprueba('producto en dólares: original = ref', $d['original'], 'ref');
    $d = mon_dos_monedas(1200, null);
    comprueba('producto en pesos: el dólar sale de la tasa', $d['ref'], 20.0);
    comprueba('producto en pesos: original = base', $d['original'], 'base');
    comprueba('la referencia es el dólar', mon_referencia()['codigo'] ?? null, 'USD');

    titulo('La moneda escrita por una persona');
    comprueba('«US$» es el dólar', mon_resolver('US$'), $usd);
    comprueba('«dólares» es el dólar', mon_resolver('dólares'), $usd);
    comprueba('«pesos» es la base (null)', mon_resolver('pesos'), null);
    comprueba('«RD$» es la base (null)', mon_resolver('RD$'), null);
    comprueba('lo desconocido es false', mon_resolver('doblones'), false);

    titulo('Pago en efectivo con dólares (POS)');
    comprueba('con cambio', pos_nota_pago_moneda(['moneda_id' => $usd, 'monto' => 50], 1437.24),
        'Pago en efectivo: US$ 50.00 a tasa 60.00 = ' . money(3000) . '; cambio ' . money(1562.76) . '.');
    comprueba('exacto: sin cambio', pos_nota_pago_moneda(['moneda_id' => $usd, 'monto' => 20], 1200),
        'Pago en efectivo: US$ 20.00 a tasa 60.00 = ' . money(1200) . '.');
    comprueba('corto: dice cuánto faltó', pos_nota_pago_moneda(['moneda_id' => $usd, 'monto' => 10], 700),
        'Pago en efectivo: US$ 10.00 a tasa 60.00 = ' . money(600) . '; faltaron ' . money(100) . '.');
    comprueba('en pesos no hay nota', pos_nota_pago_moneda(['moneda_id' => (int) monedaBase()['id'], 'monto' => 50], 10), null);
    comprueba('monto vacío no deja nota', pos_nota_pago_moneda(['moneda_id' => $usd, 'monto' => 0], 10), null);
    comprueba('sin pago no hay nota', pos_nota_pago_moneda(null, 10), null);
    comprueba('el monto del navegador no puede traer la tasa', pos_nota_pago_moneda(['moneda_id' => $usd, 'monto' => 1, 'tasa' => 999], 0),
        'Pago en efectivo: US$ 1.00 a tasa 60.00 = ' . money(60) . '; cambio ' . money(60) . '.');

    if (mon_p41()) {
        titulo('Tasa de venta automática (P41)');
        $m = qOne("SELECT * FROM monedas WHERE id = ?", [$usd]);
        comprueba('a mano: el mercado no la toca', mon_aplicar_venta_auto($usd, 62.3456), null);
        comprueba('a mano: la tasa sigue en 60', (float) qVal("SELECT tasa FROM monedas WHERE id = ?", [$usd]), 60.0);

        q("UPDATE monedas SET venta_auto = 1, venta_margen = 1.5 WHERE id = ?", [$usd]);
        $m = qOne("SELECT * FROM monedas WHERE id = ?", [$usd]);
        comprueba('mercado 62.3456 + 1.5 % = 63.28', mon_venta_desde_mercado($m, 62.3456), 63.28);
        comprueba('margen negativo: 60 − 1 % = 59.40', mon_venta_desde_mercado(['venta_margen' => -1] + $m, 60), 59.4);
        comprueba('en automático la aplica', mon_aplicar_venta_auto($usd, 62.3456, 'prueba'), 63.28);
        comprueba('y la caja cobra con ella', mon_precio_base(['precio_venta' => 29, 'moneda_id' => $usd]), 1835.12);
        comprueba('el mismo mercado otra vez no la cambia', mon_aplicar_venta_auto($usd, 62.3456, 'prueba'), null);
        comprueba('queda en el historial como automática',
            qVal("SELECT origen FROM monedas_historial WHERE moneda_id = ? AND campo = 'venta' ORDER BY id DESC LIMIT 1", [$usd]), 'automatica');

        q("UPDATE monedas SET tasa_mercado = 62.3456, tasa_mercado_at = ?, tasa_revisada_at = '2020-01-01' WHERE id = ?",
          [date('Y-m-d H:i:s'), $usd]);
        $d = mon_desfase($usd);
        comprueba('en automático no avisa de desfase', $d['avisar'], false);
        comprueba('ni de tasa rancia aunque nadie la mire', $d['rancia'], false);
        comprueba('descarga reciente: no hay atraso', $d['atraso'], false);
        q("UPDATE monedas SET tasa_mercado_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - (MON_AUTO_ATRASO_HORAS + 1) * 3600), $usd]);
        comprueba('sin descargar en ' . MON_AUTO_ATRASO_HORAS . ' h: atraso', mon_desfase($usd)['atraso'], true);
    }
} finally {
    db()->rollBack();
    monedas(true, true);
}

printf("\n%d pruebas, %d fallos\n", $total, $fallos);
exit($fallos ? 1 : 0);
