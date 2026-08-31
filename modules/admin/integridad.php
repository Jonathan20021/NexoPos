<?php
/**
 * Verificación de integridad de los datos.
 *
 * Comprueba que las cifras del sistema cuadran entre sí: que el stock coincide
 * con el kardex, que ningún comprobante está duplicado, que los balances de
 * clientes y cuentas se corresponden con sus movimientos. Es el chequeo que
 * conviene mirar después de un día fuerte con todas las sucursales vendiendo.
 *
 * Solo LEE. Nada de lo que hay aquí modifica datos.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('configuracion.ver');

/** Ejecuta una comprobación y devuelve su resultado normalizado. */
function chk(string $titulo, string $explica, callable $consulta, string $comoArreglar = ''): array
{
    try {
        [$n, $detalle] = $consulta();
        return ['titulo' => $titulo, 'explica' => $explica, 'n' => (int) $n,
                'detalle' => $detalle, 'arreglar' => $comoArreglar, 'error' => null];
    } catch (Throwable $e) {
        return ['titulo' => $titulo, 'explica' => $explica, 'n' => -1, 'detalle' => [],
                'arreglar' => $comoArreglar, 'error' => $e->getMessage()];
    }
}

$grupos = [];

/* ============================================================
 *  Comprobantes y correlativos
 * ============================================================ */
$comprobantes = [];

$comprobantes[] = chk(
    'Números de documento duplicados',
    'Dos ventas, compras o devoluciones no pueden compartir el mismo número. Si ocurriera, la contabilidad no cuadraría.',
    function () {
        $t = 0; $d = [];
        foreach ([['ventas', 'numero'], ['compras', 'numero'], ['devoluciones', 'numero'],
                  ['transferencias', 'numero'], ['pedidos', 'numero']] as [$tabla, $col]) {
            $rows = qAll("SELECT `$col` v, COUNT(*) c FROM `$tabla` GROUP BY `$col` HAVING c > 1 LIMIT 10");
            foreach ($rows as $r) $d[] = $tabla . ': ' . $r['v'] . ' (' . $r['c'] . ' veces)';
            $t += count($rows);
        }
        return [$t, $d];
    },
    'Contacta soporte: hay que renumerar los documentos afectados.'
);

$comprobantes[] = chk(
    'NCF duplicados',
    'Un mismo comprobante fiscal usado en dos ventas es un problema serio ante la DGII.',
    fn() => [
        (int) qVal("SELECT COUNT(*) FROM (SELECT ncf FROM ventas WHERE ncf IS NOT NULL GROUP BY ncf HAVING COUNT(*)>1) x"),
        qCol("SELECT ncf FROM ventas WHERE ncf IS NOT NULL GROUP BY ncf HAVING COUNT(*)>1 LIMIT 10"),
    ],
    'Anula una de las ventas y vuelve a emitirla con un NCF nuevo.'
);

$comprobantes[] = chk(
    'NCF consumidos sin comprobante emitido',
    'La secuencia avanzó más que los comprobantes realmente emitidos. Un hueco pequeño es normal si se anuló una venta; uno grande hay que justificarlo.',
    /**
     * El hueco se mide desde el PRIMER comprobante realmente emitido de la
     * serie, no desde el número 1.
     *
     * Un NCF preimpreso empieza en 1, pero un e-CF arranca donde la DGII
     * autorizó el rango: aquí, en 900001. Restando desde 1, la pantalla
     * denunciaba «900.028 números consumidos sin comprobante» en una serie con
     * tres facturas — 4,5 millones de hallazgos fantasma en total, que además
     * tapaban los huecos de verdad.
     *
     * `ncf_secuencias` no guarda el inicio del rango, así que el primer número
     * emitido es la mejor referencia disponible. Si la serie no tiene ningún
     * comprobante todavía, no hay forma de saber dónde empezaba y no se inventa
     * un hueco: se omite.
     */
    function () {
        $d = [];
        $total = 0;
        foreach (qAll("SELECT tipo, secuencia_actual FROM ncf_secuencias WHERE activo = 1") as $s) {
            $like = $s['tipo'] . '%';
            // Los 3 primeros caracteres son la serie (B02, E31…); el resto, el número.
            $primero = qVal(
                "SELECT MIN(n) FROM (
                     SELECT CAST(SUBSTRING(ncf, 4) AS UNSIGNED) n FROM ventas       WHERE ncf LIKE ?
                     UNION ALL
                     SELECT CAST(SUBSTRING(ncf, 4) AS UNSIGNED) n FROM devoluciones WHERE ncf LIKE ?
                 ) x",
                [$like, $like]
            );
            if ($primero === null) continue;   // serie sin estrenar: nada que contar

            $emitidos = (int) qVal("SELECT COUNT(*) FROM ventas WHERE ncf LIKE ?", [$like])
                      + (int) qVal("SELECT COUNT(*) FROM devoluciones WHERE ncf LIKE ?", [$like]);
            $consumidos = (int) $s['secuencia_actual'] - (int) $primero;
            $hueco = $consumidos - $emitidos;
            if ($hueco > 0) {
                $total += $hueco;
                $d[] = $s['tipo'] . ': ' . number_format($hueco) . ' número(s) consumidos sin comprobante'
                     . ' (desde el ' . $primero . ')';
            }
        }
        return [$total, $d];
    },
    'Repórtalos en el formato 608 (comprobantes anulados) si corresponde.'
);

$grupos[] = ['titulo' => 'Comprobantes fiscales', 'icono' => 'receipt', 'color' => 'blue', 'checks' => $comprobantes];

/* ============================================================
 *  Inventario
 * ============================================================ */
$inventario = [];

$inventario[] = chk(
    'Existencias en negativo',
    'Ningún producto puede tener menos de cero unidades. Si aparece, se vendió mercancía que el sistema no tenía registrada.',
    fn() => [
        (int) qVal("SELECT COUNT(*) FROM inventario_stock WHERE cantidad < 0"),
        qAll("SELECT p.nombre, su.nombre sucursal, s.cantidad
                FROM inventario_stock s JOIN productos p ON p.id=s.producto_id
                JOIN sucursales su ON su.id=s.sucursal_id
               WHERE s.cantidad < 0 LIMIT 10"),
    ],
    'Haz un ajuste de inventario con el conteo físico real.'
);

$inventario[] = chk(
    'Existencias que no cuadran con el kardex',
    'La cantidad guardada debe ser la misma con la que cerró el último movimiento del producto. Si no lo es, alguien tocó la existencia por fuera del sistema.',
    function () {
        /*
         * Se compara contra el SALDO DE CIERRE del último movimiento, no contra
         * la suma de los movimientos.
         *
         * Sumar los deltas solo vale si el producto empezó en cero y todo lo que
         * tiene entró por el kardex. En cuanto se carga una existencia inicial
         * —que es como arranca cualquier implantación— la suma deja de ser el
         * stock para siempre: un artículo que abrió con 25 unidades, vendió y
         * devolvió hasta quedar en 21 daba «suma de movimientos: −4» y la
         * pantalla lo denunciaba eternamente, mandando a hacer un conteo físico
         * que no hacía falta. Con 300 productos recién cargados, habría gritado
         * por los 300.
         *
         * `ajustarStock()` escribe la existencia y el `stock_nuevo` del
         * movimiento en la misma transacción, así que si difieren es justo lo
         * que este chequeo busca: alguien editó la tabla por fuera.
         *
         * El kardex se agrupa UNA vez y se cruza con las existencias. La versión
         * anterior lanzaba una subconsulta correlacionada por cada fila de stock
         * y además la repetía para el detalle: con 180.000 movimientos medimos
         * 4.255 ms; así son 200 ms, y de paso una sola consulta sirve para el
         * conteo y para el detalle.
         *
         * Los productos sin ningún movimiento quedan fuera (JOIN, no LEFT JOIN):
         * su existencia es una apertura y el kardex no afirma nada sobre ella.
         */
        $rows = qAll(
            "SELECT p.nombre, su.nombre sucursal, s.cantidad, u.stock_nuevo AS kardex
               FROM inventario_stock s
               JOIN productos p   ON p.id  = s.producto_id
               JOIN sucursales su ON su.id = s.sucursal_id
               JOIN (SELECT m.producto_id, m.sucursal_id, m.stock_nuevo
                       FROM movimientos_inventario m
                       JOIN (SELECT producto_id, sucursal_id, MAX(id) ult
                               FROM movimientos_inventario
                              GROUP BY producto_id, sucursal_id) x
                         ON x.producto_id = m.producto_id
                        AND x.sucursal_id = m.sucursal_id
                        AND x.ult = m.id
                    ) u ON u.producto_id = s.producto_id AND u.sucursal_id = s.sucursal_id
              WHERE ABS(s.cantidad - u.stock_nuevo) > 0.001"
        );
        return [count($rows), array_slice($rows, 0, 10)];
    },
    can('conteos.crear')
        ? 'Levanta un conteo físico (Inventario → Conteo físico): cuenta el almacén y el sistema ajusta la diferencia dejando el movimiento en el kardex.'
        : 'Registra un ajuste de inventario para dejar constancia de la diferencia.'
);

/* ---------- Cumplimiento sanitario ---------- */
// Solo se añaden si el módulo está instalado: el código puede desplegarse antes
// que la migración, y esta pantalla no debe reventar mientras tanto.
if (san_disponible()) {
    $inventario[] = chk(
        'Existencia que no cuadra con sus lotes',
        'En los productos con control sanitario, la suma de los lotes tiene que ser igual a la existencia. '
        . 'Las dos se mueven en la misma transacción, así que no debería haber ninguna diferencia; '
        . 'si aparece, la trazabilidad que se le enseña a un inspector está mintiendo.',
        function () {
            $rows = san_descuadres();
            return [count($rows), array_slice($rows, 0, 10)];
        },
        'Revisa los lotes del producto en Inventario → Lotes y vencimientos y corrige la cantidad del lote que corresponda.'
    );

    $inventario[] = chk(
        'Mercancía vencida todavía en existencia',
        'Lotes cuya fecha de vencimiento ya pasó y que siguen con unidades en el almacén. '
        . 'El sistema impide venderlos, pero PROCONSUMIDOR sanciona tenerlos en el área de venta.',
        function () {
            [$sc, $sp] = sucursalScope('l.sucursal_id');
            $rows = qAll(
                "SELECT p.codigo, p.nombre, l.codigo AS lote, s.nombre AS sucursal,
                        l.fecha_vencimiento, l.cantidad
                   FROM lotes l
                   JOIN productos p  ON p.id = l.producto_id
                   JOIN sucursales s ON s.id = l.sucursal_id
                  WHERE l.cantidad > 0 AND l.fecha_vencimiento IS NOT NULL
                    AND l.fecha_vencimiento < CURDATE() AND $sc
                  ORDER BY l.fecha_vencimiento LIMIT 50", $sp
            );
            return [count($rows), array_slice($rows, 0, 10)];
        },
        'Retíralos del área de venta y dales de baja en Inventario → Lotes y vencimientos.'
    );

    $inventario[] = chk(
        'Productos regulados sin registro sanitario',
        'Productos marcados como sujetos a control sanitario a los que les falta el número de registro. '
        . 'Sin ese dato no se pueden justificar ante Salud Pública.',
        function () {
            $rows = qAll(
                "SELECT codigo, nombre, registro_categoria
                   FROM productos
                  WHERE regulado = 1 AND activo = 1
                    AND (registro_sanitario IS NULL OR registro_sanitario = '')
                  ORDER BY nombre LIMIT 50"
            );
            return [count($rows), array_slice($rows, 0, 10)];
        },
        'Cárgales el número de registro en Inventario → Productos, en la ficha sanitaria.'
    );
}

$inventario[] = chk(
    'Devoluciones que no devolvieron la mercancía',
    'Cuando se devuelve un producto físico, la unidad tiene que volver a la existencia y quedar anotada en el kardex. '
    . 'Si la devolución está registrada pero el movimiento no existe, esa unidad se le cobró al cliente, se le reembolsó '
    . 'y sigue contando como vendida: el inventario dice que hay una menos de las que hay.',
    function () {
        $rows = qCol(
            "SELECT CONCAT(d.numero, ' · ', dd.descripcion, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM dd.cantidad)), ')')
               FROM devoluciones d
               JOIN devolucion_detalles dd ON dd.devolucion_id = d.id
               JOIN productos p ON p.id = dd.producto_id AND p.tipo = 'producto'
              WHERE NOT EXISTS (SELECT 1 FROM movimientos_inventario mi
                                 WHERE mi.referencia_tipo = 'devolucion'
                                   AND mi.referencia_id = d.id
                                   AND mi.producto_id = dd.producto_id)
              ORDER BY d.id DESC"
        );
        return [count($rows), array_slice($rows, 0, 10)];
    },
    'Corrige la existencia con un ajuste de inventario y deja el motivo escrito, para que el kardex explique de dónde salió.'
);

$grupos[] = ['titulo' => 'Inventario', 'icono' => 'box', 'color' => 'amber', 'checks' => $inventario];

/* ============================================================
 *  Documentos incompletos
 * ============================================================ */
$documentos = [];

$documentos[] = chk(
    'Ventas sin líneas de detalle',
    'Una venta sin productos indica que algo se cortó a mitad de camino.',
    fn() => [
        (int) qVal("SELECT COUNT(*) FROM ventas v WHERE NOT EXISTS(SELECT 1 FROM venta_detalles d WHERE d.venta_id=v.id)"),
        qCol("SELECT numero FROM ventas v WHERE NOT EXISTS(SELECT 1 FROM venta_detalles d WHERE d.venta_id=v.id) LIMIT 10"),
    ],
    'Anula esas ventas: no representan una operación real.'
);

$documentos[] = chk(
    'Ventas completadas sin forma de pago',
    'Toda venta cerrada debe tener registrado cómo se cobró.',
    fn() => [
        (int) qVal("SELECT COUNT(*) FROM ventas v WHERE v.estado='completada' AND NOT EXISTS(SELECT 1 FROM venta_pagos p WHERE p.venta_id=v.id)"),
        qCol("SELECT numero FROM ventas v WHERE v.estado='completada' AND NOT EXISTS(SELECT 1 FROM venta_pagos p WHERE p.venta_id=v.id) LIMIT 10"),
    ]
);

$documentos[] = chk(
    'Compras sin líneas de detalle',
    'Una compra sin productos no pudo haber movido inventario.',
    fn() => [
        (int) qVal("SELECT COUNT(*) FROM compras c WHERE NOT EXISTS(SELECT 1 FROM compra_detalles d WHERE d.compra_id=c.id)"),
        qCol("SELECT numero FROM compras c WHERE NOT EXISTS(SELECT 1 FROM compra_detalles d WHERE d.compra_id=c.id) LIMIT 10"),
    ]
);

$documentos[] = chk(
    'Devoluciones por encima de lo vendido',
    'No se puede devolver más cantidad de la que salió en la factura original.',
    fn() => [
        (int) qVal(
            "SELECT COUNT(*) FROM (
                SELECT dd.venta_detalle_id, SUM(dd.cantidad) dev
                  FROM devolucion_detalles dd
                 WHERE dd.venta_detalle_id IS NOT NULL
                 GROUP BY dd.venta_detalle_id) t
              JOIN venta_detalles vd ON vd.id = t.venta_detalle_id
             WHERE t.dev > vd.cantidad + 0.001"
        ),
        [],
    ],
    'Revisa esas devoluciones y corrige las cantidades.'
);

$grupos[] = ['titulo' => 'Documentos completos', 'icono' => 'file', 'color' => 'indigo', 'checks' => $documentos];

/* ============================================================
 *  Dinero
 * ============================================================ */
$dinero = [];

$dinero[] = chk(
    'Saldo de clientes contra sus facturas a crédito',
    'El saldo pendiente de cada cliente debe ser lo facturado a crédito menos lo abonado y lo devuelto.',
    function () {
        $rows = qAll(
            "SELECT c.id, c.nombre, c.balance,
                    COALESCE((SELECT SUM(vp.monto) FROM ventas v
                                JOIN venta_pagos vp ON vp.venta_id = v.id
                                JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id AND mp.es_credito = 1
                               WHERE v.cliente_id = c.id AND v.estado = 'completada'),0) AS credito,
                    COALESCE((SELECT SUM(pc.monto) FROM pagos_clientes pc WHERE pc.cliente_id = c.id),0) AS abonos,
                    COALESCE((SELECT SUM(d.total) FROM devoluciones d
                                JOIN ventas v2 ON v2.id = d.venta_id
                               WHERE v2.cliente_id = c.id),0) AS devuelto
               FROM clientes c
              WHERE c.balance <> 0
                 OR EXISTS (SELECT 1 FROM ventas v3 WHERE v3.cliente_id = c.id)"
        );
        $malos = [];
        foreach ($rows as $r) {
            // El esperado se recorta a cero ANTES de comparar, no solo al
            // imprimirlo: `clientes.balance` tiene un CHECK de no negativo, así
            // que un cliente que devolvió más de lo que compró a crédito guarda
            // 0 y eso es correcto. Comparando contra el crudo, la pantalla lo
            // denunciaba y acto seguido imprimía «registra RD$ 0.00, debería ser
            // RD$ 0.00»: una diferencia imposible de entender y de corregir.
            $esperado = max(0.0, (float) $r['credito'] - (float) $r['abonos'] - (float) $r['devuelto']);
            if (abs((float) $r['balance'] - $esperado) > 0.05) {
                $malos[] = $r['nombre'] . ': registra ' . money($r['balance']) . ', debería ser ' . money($esperado);
            }
        }
        return [count($malos), array_slice($malos, 0, 10)];
    },
    'Una diferencia suele venir de un abono registrado sin factura de respaldo. Revísalo en Cuentas por Cobrar.'
);

$dinero[] = chk(
    'Balance de cuentas contra sus movimientos',
    'El balance de cada cuenta debe ser su saldo inicial más los ingresos menos los gastos registrados.',
    function () {
        $rows = qAll(
            "SELECT cf.id, cf.nombre, cf.balance, cf.saldo_inicial,
                    COALESCE((SELECT SUM(CASE WHEN t.tipo='ingreso' THEN t.monto ELSE -t.monto END)
                                FROM transacciones t WHERE t.cuenta_id = cf.id),0) AS movimientos
               FROM cuentas_financieras cf WHERE cf.activo = 1"
        );
        $malos = [];
        foreach ($rows as $r) {
            $esperado = (float) $r['saldo_inicial'] + (float) $r['movimientos'];
            if (abs((float) $r['balance'] - $esperado) > 0.05) {
                $malos[] = $r['nombre'] . ': registra ' . money($r['balance']) . ', sus movimientos dan ' . money($esperado);
            }
        }
        return [count($malos), array_slice($malos, 0, 10)];
    },
    'Suele pasar si se editó el balance a mano. La conciliación bancaria toma los movimientos como fuente de verdad.'
);

$dinero[] = chk(
    'Devoluciones que no devolvieron el dinero',
    'Una devolución que no fue a crédito tiene que dejar su salida en alguna cuenta: efectivo si se pagó en efectivo, '
    . 'banco si se pagó con tarjeta o transferencia. Si no está, el cliente cobró su reembolso pero los libros siguen '
    . 'contando ese dinero como si estuviera en la empresa.',
    function () {
        $rows = qCol(
            "SELECT CONCAT(d.numero, ' · ', s.nombre, ' · ', FORMAT(d.total, 2))
               FROM devoluciones d
               JOIN sucursales s ON s.id = d.sucursal_id
              WHERE d.total > 0.009
                AND COALESCE((SELECT MAX(m.es_credito) FROM venta_pagos vp
                                JOIN metodos_pago m ON m.id = vp.metodo_pago_id
                               WHERE vp.venta_id = d.venta_id), 0) = 0
                AND NOT EXISTS (SELECT 1 FROM transacciones t
                                 WHERE t.referencia_tipo = 'devolucion' AND t.referencia_id = d.id)
              ORDER BY d.id DESC"
        );
        return [count($rows), array_slice($rows, 0, 10)];
    },
    'Registra la salida a mano en Finanzas → Transacciones, con la devolución como referencia, o revierte la devolución si nunca se pagó.'
);

$dinero[] = chk(
    'Reembolsos en efectivo que no salieron del cajón',
    'Si la venta se cobró en efectivo, el reembolso sale del cajón y tiene que aparecer como egreso en la caja del turno. '
    . 'Cuando falta, el cuadre acusa un faltante que el cajero no causó.',
    function () {
        $rows = qCol(
            "SELECT CONCAT(d.numero, ' · ', s.nombre, ' · ', FORMAT(d.total, 2))
               FROM devoluciones d
               JOIN sucursales s ON s.id = d.sucursal_id
              WHERE d.total > 0.009
                AND EXISTS (SELECT 1 FROM venta_pagos vp
                              JOIN metodos_pago m ON m.id = vp.metodo_pago_id
                             WHERE vp.venta_id = d.venta_id AND m.afecta_caja = 1 AND m.es_credito = 0)
                AND NOT EXISTS (SELECT 1 FROM caja_movimientos cm
                                 WHERE cm.tipo = 'egreso' AND cm.concepto = CONCAT('Reembolso ', d.numero))
              ORDER BY d.id DESC"
        );
        return [count($rows), array_slice($rows, 0, 10)];
    },
    'Anótalo como egreso de caja con el número de la devolución en el concepto. El sistema ya exige caja abierta para reembolsar en efectivo, así que aquí solo deberían salir devoluciones anteriores a ese cambio.'
);

$grupos[] = ['titulo' => 'Dinero', 'icono' => 'wallet', 'color' => 'emerald', 'checks' => $dinero];

/* ============================================================
 *  Operación concurrente
 * ============================================================ */
$operacion = [];

$operacion[] = chk(
    'Cajas con más de una sesión abierta',
    'Una caja solo puede tener un turno abierto a la vez; si no, el arqueo no cuadra.',
    fn() => [
        (int) qVal("SELECT COUNT(*) FROM (SELECT caja_id FROM caja_sesiones WHERE estado='abierta' GROUP BY caja_id HAVING COUNT(*)>1) x"),
        qCol("SELECT c.nombre FROM caja_sesiones cs JOIN cajas c ON c.id=cs.caja_id
               WHERE cs.estado='abierta' GROUP BY cs.caja_id HAVING COUNT(*)>1 LIMIT 10"),
    ],
    'Cierra las sesiones sobrantes desde Caja.'
);

$operacion[] = chk(
    'Contadores de numeración desfasados',
    'El contador de cada serie debe ir por delante del último documento emitido. Si se queda atrás, el sistema tendría que buscar hueco en cada venta.',
    function () {
        $series = [
            ['ventas.numero.VTA', 'ventas', 'numero', 'VTA'],
            ['compras.numero.COM', 'compras', 'numero', 'COM'],
            ['devoluciones.numero.DEV', 'devoluciones', 'numero', 'DEV'],
            ['transferencias.numero.TRF', 'transferencias', 'numero', 'TRF'],
        ];
        $malos = [];
        foreach ($series as [$clave, $tabla, $col, $pref]) {
            $contador = qVal("SELECT valor FROM contadores WHERE nombre = ?", [$clave]);
            if ($contador === null) continue;
            $max = (int) qVal(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`$col`,'-',-1) AS UNSIGNED)),0) FROM `$tabla` WHERE `$col` LIKE ?",
                [$pref . '-%']
            );
            if ((int) $contador < $max) {
                $malos[] = $pref . ': contador en ' . $contador . ', último documento ' . $max;
            }
        }
        return [count($malos), $malos];
    },
    'Ejecuta de nuevo database/migracion_concurrencia_p4.sql: vuelve a sembrar los contadores.'
);

$operacion[] = chk(
    'Ventas sin sucursal o sin usuario válidos',
    'Toda venta debe poder rastrearse hasta la sucursal y el cajero que la hizo.',
    fn() => [
        (int) qVal("SELECT COUNT(*) FROM ventas v LEFT JOIN sucursales s ON s.id=v.sucursal_id
                     LEFT JOIN usuarios u ON u.id=v.usuario_id WHERE s.id IS NULL OR u.id IS NULL"),
        [],
    ]
);

$grupos[] = ['titulo' => 'Operación multi-sucursal', 'icono' => 'store', 'color' => 'violet', 'checks' => $operacion];

/* ---------- Resumen ---------- */
$totalChecks = 0; $conProblema = 0; $conError = 0;
foreach ($grupos as $g) {
    foreach ($g['checks'] as $c) {
        $totalChecks++;
        if ($c['n'] > 0) $conProblema++;
        if ($c['n'] < 0) $conError++;
    }
}
$todoBien = $conProblema === 0 && $conError === 0;

layout_start(
    'Verificación de integridad',
    $totalChecks . ' comprobaciones sobre los datos del sistema',
    '<button type="button" onclick="location.reload()" class="btn btn-ghost no-print">' . icon('history', 'w-4 h-4') . ' Volver a verificar</button>'
);
?>

<!-- Resumen -->
<div class="card p-6 mb-5 <?= $todoBien ? 'border-emerald-200 bg-emerald-50/40' : 'border-amber-200 bg-amber-50/40' ?>">
  <div class="flex items-start gap-4">
    <span class="w-14 h-14 rounded-2xl <?= $todoBien ? 'bg-emerald-100 text-emerald-600' : 'bg-amber-100 text-amber-600' ?> flex items-center justify-center shrink-0">
      <?= icon($todoBien ? 'check' : 'alert', 'w-7 h-7') ?>
    </span>
    <div class="flex-1 min-w-0">
      <h2 class="text-lg font-extrabold text-slate-800">
        <?= $todoBien ? 'Los datos están consistentes' : $conProblema . ' comprobación(es) con hallazgos' ?>
      </h2>
      <p class="text-sm text-slate-600 mt-1 leading-relaxed">
        <?php if ($todoBien): ?>
          Las <?= $totalChecks ?> comprobaciones pasaron. Los comprobantes no están duplicados, el inventario
          cuadra con su kardex y los balances coinciden con sus movimientos.
        <?php else: ?>
          Nada de esto detiene la operación, pero conviene revisarlo. Cada hallazgo explica qué significa y cómo corregirlo.
        <?php endif; ?>
      </p>
      <div class="flex flex-wrap gap-2 mt-3">
        <?= badge($totalChecks - $conProblema - $conError . ' correctas', 'emerald') ?>
        <?php if ($conProblema): ?><?= badge($conProblema . ' con hallazgos', 'amber') ?><?php endif; ?>
        <?php if ($conError): ?><?= badge($conError . ' no se pudieron ejecutar', 'rose') ?><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php foreach ($grupos as $g):
  $fondo = ['blue' => 'bg-blue-50 text-blue-600', 'amber' => 'bg-amber-50 text-amber-600',
            'indigo' => 'bg-indigo-50 text-indigo-600', 'emerald' => 'bg-emerald-50 text-emerald-600',
            'violet' => 'bg-violet-50 text-violet-600'][$g['color']];
?>
  <section class="card overflow-hidden mb-5">
    <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100">
      <span class="w-9 h-9 rounded-xl <?= $fondo ?> flex items-center justify-center shrink-0"><?= icon($g['icono'], 'w-4 h-4') ?></span>
      <h3 class="font-bold text-slate-800"><?= e($g['titulo']) ?></h3>
    </div>
    <ul class="divide-y divide-slate-100">
      <?php foreach ($g['checks'] as $c):
        $estado = $c['n'] < 0 ? 'error' : ($c['n'] > 0 ? 'aviso' : 'ok');
        $ico = ['ok' => ['check', 'bg-emerald-50 text-emerald-600'],
                'aviso' => ['alert', 'bg-amber-50 text-amber-600'],
                'error' => ['x', 'bg-rose-50 text-rose-600']][$estado];
      ?>
        <li class="flex items-start gap-4 px-5 py-4">
          <span class="w-9 h-9 rounded-xl <?= $ico[1] ?> flex items-center justify-center shrink-0"><?= icon($ico[0], 'w-4 h-4') ?></span>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h4 class="font-semibold text-slate-800"><?= e($c['titulo']) ?></h4>
              <?php if ($estado === 'ok'): ?>
                <?= badge('Correcto', 'emerald') ?>
              <?php elseif ($estado === 'aviso'): ?>
                <?= badge($c['n'] . ' hallazgo' . ($c['n'] === 1 ? '' : 's'), 'amber') ?>
              <?php else: ?>
                <?= badge('No se pudo verificar', 'rose') ?>
              <?php endif; ?>
            </div>
            <p class="text-[13px] text-slate-500 mt-1 leading-relaxed"><?= e($c['explica']) ?></p>

            <?php if ($c['error']): ?>
              <p class="text-xs text-rose-600 mt-2 font-mono bg-rose-50 rounded-lg px-3 py-2"><?= e($c['error']) ?></p>
            <?php endif; ?>

            <?php if ($c['n'] > 0 && $c['detalle']): ?>
              <ul class="mt-2.5 space-y-1">
                <?php foreach ($c['detalle'] as $d): ?>
                  <li class="text-[12.5px] text-slate-600 bg-slate-50 rounded-lg px-3 py-1.5">
                    <?php if (is_array($d)): ?>
                      <?= e(implode(' · ', array_map(fn($v) => is_numeric($v) ? qty($v) : (string) $v, $d))) ?>
                    <?php else: ?>
                      <?= e((string) $d) ?>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if ($c['n'] > 0 && $c['arreglar']): ?>
              <p class="text-[12.5px] text-blue-700 mt-2.5 flex items-start gap-1.5">
                <?= icon('arrow-right', 'w-3.5 h-3.5 shrink-0 mt-0.5') ?>
                <span><?= e($c['arreglar']) ?></span>
              </p>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>

<p class="text-xs text-slate-400 text-center">
  Esta pantalla solo lee datos: nada de lo que se muestra aquí modifica el sistema.
</p>

<?php layout_end(); ?>
