<?php
/**
 * Dashboard.
 *
 * Está pensado para responder, en el orden en que a uno le importan:
 *   1. ¿Hay algo que requiera mi atención ahora mismo?
 *   2. ¿Cómo va HOY comparado con un día normal?
 *   3. ¿Cómo va el mes contra el anterior?
 *   4. ¿Qué sucursal, producto o meta necesita que me meta?
 *
 * Cada cifra lleva su contexto: un número solo no dice si está bien o mal.
 * El detalle financiero (utilidad, margen) se muestra solo a quien puede ver
 * finanzas o reportes: un cajero no tiene por qué ver la ganancia de la empresa.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();

$sid    = current_sucursal_id();
$scopeV = $sid === null ? '1=1' : 'v.sucursal_id = ' . (int) $sid;
$scopeS = $sid === null ? '1=1' : 's.sucursal_id = ' . (int) $sid;
// Las devoluciones cuelgan de su propia sucursal, no de la de la venta.
$scopeDv = $sid === null ? '1=1' : 'd.sucursal_id = ' . (int) $sid;
$verDinero = can('finanzas.ver') || can('reportes.ver') || is_super();

/*
 * QUÉ PUEDE VER ESTE USUARIO EN EL TABLERO
 *
 * El menú lateral se filtra por permiso desde siempre; el tablero no lo hacía.
 * Como es la pantalla de entrada de TODO el mundo (`require_login()` a secas),
 * un administrador de nómina —sin un solo permiso de ventas— entraba y veía la
 * facturación del mes, el estado de la caja, los productos más vendidos y las
 * últimas facturas con el nombre de cada cliente.
 *
 * Los `can()` que había cubrían los enlaces «Ver todas →» de cada tarjeta, no la
 * tarjeta: se ocultaba el botón y se enseñaba el dato, que es justo al revés.
 *
 * Cada bloque se abre ahora con el permiso del módulo que resume. Quien no tenga
 * ninguno ve a dónde ir, no una pantalla en blanco.
 */
// `reportes.ver` NO entra en esta lista, y es el mismo error que ya se contó
// arriba: es el permiso de ENTRAR al Centro de Reportes, no el de ver la
// facturación. Estaba aquí, así que en cuanto se le dio el hub al administrador
// de nómina —para que abriera «Resumen de nómina», que es suyo— volvió a ver la
// venta del mes, el ticket promedio y las últimas facturas. Los permisos que SÍ
// implican ventas son estos.
$verVentas     = can_any(['ventas.ver', 'pos.ver', 'pos.vender',
                          'reportes.ejecutivo', 'reportes.operacion', 'finanzas.ver']);
$verCaja       = can('caja.ver');
$verSucursales = can_any(['reportes.ejecutivo', 'reportes.sucursales']);
$verProductos  = can_any(['reportes.operacion', 'productos.ver', 'inventario.ver']);
$verInventario = can_any(['inventario.ver', 'productos.ver']);
$verMetas      = can('metas.ver');
// Recursos humanos: quien administra nómina y TSS también tiene un tablero.
// Antes se le enseñaba la facturación de la empresa; después de taparla se le
// quedó la pantalla en blanco, que tampoco es su tablero. Es el suyo el que
// faltaba, no el de ventas el que sobraba.
$verEmpleados  = can('rrhh_empleados.ver');
$verNomina     = can('rrhh_nomina.ver');
$verTss        = can('tss.ver');
$verPrestamos  = can('prestamos.ver') && function_exists('presDisponible') && presDisponible();
$verVacaciones = can('rrhh_vacaciones.ver');
$verRRHH       = $verEmpleados || $verNomina || $verTss || $verPrestamos || $verVacaciones;

// Compras y CRM: mismo motivo que recursos humanos. Quien lleva las cuentas
// por pagar o el embudo de ventas también entra por aquí.
$verCompras = can_any(['compras.ver', 'proveedores.ver', 'cxp.ver']);
$verCrm     = can('crm.ver');
// Mover mercancía entre tiendas y contarla es media jornada de quien lleva el
// inventario, y no aparecía por ningún lado en la pantalla de entrada.
$verTraslados = can('transferencias.ver');
$verConteos   = can('conteos.ver');
$verOperacion = $verTraslados || $verConteos;

$verNada       = !$verVentas && !$verCaja && !$verProductos && !$verInventario
                 && !$verMetas && !$verRRHH && !$verCompras && !$verCrm && !$verOperacion;

$hoy       = date('Y-m-d');
$inicioMes = date('Y-m-01');
$finMes    = date('Y-m-t');
$diaMes    = (int) date('j');
$diasMes   = (int) date('t');

/*
 * Los rangos van sobre la columna `fecha` tal cual, NO con DATE(v.fecha):
 * `ventas.fecha` es DATETIME e indexada y envolverla en una función anula el
 * índice (ver docs/CONVENCIONES-DEV.md).
 */
$mesIni  = $inicioMes . ' 00:00:00';
$mesFin  = $finMes . ' 23:59:59';
$prevIni = date('Y-m-01', strtotime('first day of last month')) . ' 00:00:00';
$prevFin = date('Y-m-t', strtotime('last day of last month')) . ' 23:59:59';
$hoyIni  = $hoy . ' 00:00:00';
$hoyFin  = $hoy . ' 23:59:59';

// Mismo día de la semana, semana pasada: en comercio es la comparación honesta.
// Un lunes contra un domingo no dice nada; un lunes contra el lunes anterior sí.
$semPasada    = date('Y-m-d', strtotime('-7 days'));
$semPasadaIni = $semPasada . ' 00:00:00';
$semPasadaFin = $semPasada . ' 23:59:59';

/* ============================================================
 *  HOY
 * ============================================================ */
$hoyTot = qOne(
    "SELECT COALESCE(SUM(v.total),0) total, COUNT(*) n,
            COALESCE(SUM(v.subtotal - v.descuento - v.costo_total),0) utilidad,
            COUNT(DISTINCT v.cliente_id) clientes
       FROM ventas v
      WHERE " . rep_estados_venta() . " AND $scopeV AND v.fecha BETWEEN '$hoyIni' AND '$hoyFin'"
) ?: [];
// El tablero dice lo mismo que los informes de dirección: neto de
// devoluciones. Aquí se mide por `ventas.total`, así que lo que se resta es el
// total de la nota de crédito —con su ITBIS— y no solo la base.
$devHoy = rep_devoluciones($hoyIni, $hoyFin, $scopeDv);
$ventasHoy   = (float) ($hoyTot['total'] ?? 0) - $devHoy['total'];
$nVentasHoy  = (int) ($hoyTot['n'] ?? 0);
$utilidadHoy = (float) ($hoyTot['utilidad'] ?? 0) - $devHoy['base'] + $devHoy['costo'];
$ticketHoy   = $nVentasHoy > 0 ? $ventasHoy / $nVentasHoy : 0;

$ventasSemPasada = (float) qVal(
    "SELECT COALESCE(SUM(v.total),0) FROM ventas v
      WHERE " . rep_estados_venta() . " AND $scopeV AND v.fecha BETWEEN '$semPasadaIni' AND '$semPasadaFin'"
) - rep_devoluciones($semPasadaIni, $semPasadaFin, $scopeDv)['total'];
$deltaHoy = $ventasSemPasada > 0 ? round(($ventasHoy - $ventasSemPasada) / $ventasSemPasada * 100, 1) : null;

// Ritmo del día por hora, para ver si la tarde va floja o el pico ya pasó.
//
// Este SÍ va en bruto, a diferencia del resto del tablero: mide actividad de
// venta, no resultado. Restarle una devolución de una venta de otro día dejaría
// una barra negativa en una gráfica que se dibuja por altura, y además diría
// que a esa hora no se vendió cuando sí se vendió.
$porHora = array_fill(6, 17, 0.0);   // 06:00 a 22:00
foreach (qAll(
    "SELECT HOUR(v.fecha) h, COALESCE(SUM(v.total),0) t FROM ventas v
      WHERE v.estado='completada' AND $scopeV AND v.fecha BETWEEN '$hoyIni' AND '$hoyFin'
      GROUP BY HOUR(v.fecha)"
) as $r) {
    $h = (int) $r['h'];
    if ($h >= 6 && $h <= 22) $porHora[$h] = (float) $r['t'];
}
$maxHora = max(1, max($porHora));
$horaActual = (int) date('G');

/* ============================================================
 *  MES ACTUAL Y ANTERIOR
 * ============================================================ */
$mesAct = qOne(
    "SELECT COALESCE(SUM(v.total),0) total, COUNT(*) n,
            COALESCE(SUM(v.subtotal - v.descuento - v.costo_total),0) utilidad,
            COALESCE(SUM(v.subtotal - v.descuento),0) neto,
            COUNT(DISTINCT v.cliente_id) clientes
       FROM ventas v
      WHERE " . rep_estados_venta() . " AND $scopeV AND v.fecha BETWEEN '$mesIni' AND '$mesFin'"
) ?: [];
$mesPre = qOne(
    "SELECT COALESCE(SUM(v.total),0) total, COUNT(*) n,
            COALESCE(SUM(v.subtotal - v.descuento - v.costo_total),0) utilidad,
            COUNT(DISTINCT v.cliente_id) clientes
       FROM ventas v
      WHERE " . rep_estados_venta() . " AND $scopeV AND v.fecha BETWEEN '$prevIni' AND '$prevFin'"
) ?: [];

$devMes  = rep_devoluciones($mesIni, $mesFin, $scopeDv);
$devPrev = rep_devoluciones($prevIni, $prevFin, $scopeDv);
$ventasMes   = (float) ($mesAct['total'] ?? 0) - $devMes['total'];
$nVentasMes  = (int) ($mesAct['n'] ?? 0);
$utilidadMes = (float) ($mesAct['utilidad'] ?? 0) - $devMes['base'] + $devMes['costo'];
$netoMes     = (float) ($mesAct['neto'] ?? 0) - $devMes['base'];
$clientesMes = (int) ($mesAct['clientes'] ?? 0);
$ticketMes   = $nVentasMes > 0 ? $ventasMes / $nVentasMes : 0;
$margenMes   = $netoMes > 0 ? $utilidadMes / $netoMes * 100 : 0;

$ventasPrev   = (float) ($mesPre['total'] ?? 0) - $devPrev['total'];
$nVentasPrev  = (int) ($mesPre['n'] ?? 0);
$utilidadPrev = (float) ($mesPre['utilidad'] ?? 0) - $devPrev['base'] + $devPrev['costo'];
$clientesPrev = (int) ($mesPre['clientes'] ?? 0);
$ticketPrev   = $nVentasPrev > 0 ? $ventasPrev / $nVentasPrev : 0;

$delta = fn(float $a, float $b): ?float => abs($b) < 0.005 ? ($a > 0 ? 100.0 : null) : round(($a - $b) / abs($b) * 100, 1);

/*
 * Proyección al cierre. Dos cuidados:
 *  - Se calcula sobre los días YA CERRADOS, no sobre los transcurridos: hoy va
 *    a medias y meterlo hunde la proyección durante toda la mañana.
 *  - Los primeros días del mes la muestra es demasiado corta para proyectar
 *    nada (el día 1 sería «un día × 31»). Por debajo de 4 días no se enseña.
 */
$diasCerrados = $diaMes - 1;
$proyectable  = $diasCerrados >= 3;
$proyeccion   = $proyectable ? ($ventasMes - $ventasHoy) / $diasCerrados * $diasMes : 0.0;

/* ============================================================
 *  CURVA ACUMULADA: este mes contra el anterior
 * ============================================================ */
/** Ventas del mes por día, ya netas: la curva acumulada tiene que llegar al
 *  mismo sitio que la cifra del mes que hay justo encima. */
$porDia = function (string $ini, string $fin) use ($scopeV, $scopeDv): array {
    $m = [];
    foreach (qAll(
        "SELECT DAY(v.fecha) d, COALESCE(SUM(v.total),0) t FROM ventas v
          WHERE " . rep_estados_venta() . " AND $scopeV AND v.fecha BETWEEN '$ini' AND '$fin'
          GROUP BY DAY(v.fecha)"
    ) as $r) $m[(int) $r['d']] = (float) $r['t'];
    foreach (rep_devoluciones_por_dia($ini, $fin, $scopeDv) as $f => $dv) {
        $d = (int) date('j', strtotime($f));
        $m[$d] = ($m[$d] ?? 0.0) - $dv['total'];
    }
    return $m;
};
$diaMesAct = $porDia($mesIni, $mesFin);
$diaMesPre = $porDia($prevIni, $prevFin);

$labels = $acumAct = $acumPre = [];
$aAct = $aPre = 0.0;
$diasPrev = (int) date('t', strtotime($prevIni));
for ($d = 1; $d <= $diasMes; $d++) {
    $labels[] = (string) $d;
    if ($d <= $diaMes) { $aAct += $diaMesAct[$d] ?? 0; $acumAct[] = $aAct; }
    $aPre += $diaMesPre[$d] ?? 0;
    if ($d <= $diasPrev) $acumPre[] = $aPre;
}

/*
 * Los primeros días de cada mes «este mes» está casi vacío y los rankings
 * saldrían en blanco justo cuando el encargado abre el sistema. Si el mes aún
 * no tiene ventas, las listas miran a los últimos 30 días y lo dicen en el
 * subtítulo. No cuesta ninguna consulta extra: solo cambia el rango.
 */
$mesArrancado = $nVentasMes > 0;
$listaIni     = $mesArrancado ? $mesIni : date('Y-m-d', strtotime('-29 days')) . ' 00:00:00';
$listaFin     = $mesArrancado ? $mesFin : $hoyFin;
$listaLabel   = $mesArrancado ? 'Este mes' : 'Últimos 30 días';

/*
 * Serie de los últimos 7 días, armada en PHP con los totales por día que ya
 * trajimos: sirve de relleno útil en la tarjeta de hoy cuando todavía no se ha
 * vendido nada (a primera hora de la mañana, siempre).
 */
$ultimos7 = [];
for ($i = 6; $i >= 0; $i--) {
    $f   = strtotime("-$i days");
    $dia = (int) date('j', $f);
    $val = date('Y-m', $f) === date('Y-m')
        ? ($diaMesAct[$dia] ?? 0)
        : (date('Y-m', $f) === date('Y-m', strtotime($prevIni)) ? ($diaMesPre[$dia] ?? 0) : 0);
    $ultimos7[] = ['etiqueta' => ['D', 'L', 'M', 'M', 'J', 'V', 'S'][(int) date('w', $f)], 'fecha' => $f, 'valor' => (float) $val];
}
$max7 = max(1, max(array_column($ultimos7, 'valor')));
$hay7 = array_sum(array_column($ultimos7, 'valor')) > 0;

/* ============================================================
 *  CAJA — estado operativo, lo primero que mira un encargado
 * ============================================================ */
$cajasAbiertas = qAll(
    "SELECT cs.id, cs.monto_apertura, cs.abierta_at, c.nombre AS caja, su.nombre AS sucursal,
            CONCAT(u.nombre,' ',u.apellido) AS cajero,
            COALESCE((SELECT SUM(vp.monto) FROM venta_pagos vp
                        JOIN ventas v2 ON v2.id = vp.venta_id AND v2.estado='completada'
                        JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id AND mp.afecta_caja = 1
                       WHERE v2.caja_sesion_id = cs.id),0) AS efectivo,
            COALESCE((SELECT SUM(v3.total) FROM ventas v3
                       WHERE v3.caja_sesion_id = cs.id AND v3.estado='completada'),0) AS vendido
       FROM caja_sesiones cs
       JOIN cajas c       ON c.id  = cs.caja_id
       JOIN sucursales su ON su.id = cs.sucursal_id
       JOIN usuarios u    ON u.id  = cs.usuario_id
      WHERE cs.estado = 'abierta'" . ($sid !== null ? ' AND cs.sucursal_id = ' . (int) $sid : '') . "
      ORDER BY cs.abierta_at LIMIT 4"
);

/* ============================================================
 *  SUCURSALES — solo tiene sentido si el usuario ve más de una
 * ============================================================ */
$porSucursal = [];
if ($sid === null && count(sucursales_visibles()) > 1) {
    $porSucursal = qAll(
        "SELECT su.nombre AS sucursal, COUNT(v.id) n, COALESCE(SUM(v.total),0) total
           FROM ventas v JOIN sucursales su ON su.id = v.sucursal_id
          WHERE " . rep_estados_venta() . " AND v.fecha BETWEEN '$listaIni' AND '$listaFin'
          GROUP BY v.sucursal_id, su.nombre ORDER BY total DESC"
    );
    $devSuc = rep_devoluciones_por('su.nombre', 'v.sucursal_id, su.nombre',
        'JOIN sucursales su ON su.id = v.sucursal_id', $listaIni, $listaFin);
    foreach ($porSucursal as $i => $s) {
        $porSucursal[$i]['total'] = (float) $s['total'] - ($devSuc[(string) $s['sucursal']]['total'] ?? 0.0);
    }
    usort($porSucursal, fn($a, $b) => $b['total'] <=> $a['total']);
}
$totalSuc = array_sum(array_column($porSucursal, 'total')) ?: 1;

/* ============================================================
 *  MERCANCÍA EN MOVIMIENTO: TRASLADOS Y CONTEOS
 * ============================================================ */
$oper = [];
if ($verOperacion) {
    if ($verTraslados) {
        // Origen y destino se miran por separado: lo que espera aprobación es
        // asunto de la tienda que lo manda, y lo que está por recibir, de la que
        // lo espera. Un mismo traslado no es lo mismo desde cada lado.
        $scopeO = $sid === null ? '1=1' : 't.sucursal_origen_id = ' . (int) $sid;
        $scopeD = $sid === null ? '1=1' : 't.sucursal_destino_id = ' . (int) $sid;
        $oper['por_aprobar'] = (int) qVal("SELECT COUNT(*) FROM transferencias t WHERE t.estado='pendiente' AND $scopeO");
        $oper['por_recibir'] = (int) qVal("SELECT COUNT(*) FROM transferencias t WHERE t.estado='enviada' AND $scopeD");
        $oper['varadas']     = (int) qVal(
            "SELECT COUNT(*) FROM transferencias t
              WHERE t.estado='enviada' AND t.enviada_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND $scopeD");
        $oper['borradores']  = (int) qVal("SELECT COUNT(*) FROM transferencias t WHERE t.estado='borrador' AND $scopeO");
    }
    if ($verConteos) {
        $scopeCo = $sid === null ? '1=1' : 'c.sucursal_id = ' . (int) $sid;
        $oper['conteos'] = qAll(
            "SELECT c.id, c.numero, su.nombre AS sucursal, c.created_at,
                    COUNT(d.id) lineas, COALESCE(SUM(d.stock_contado IS NOT NULL),0) contadas
               FROM conteos c
               JOIN sucursales su ON su.id = c.sucursal_id
               LEFT JOIN conteo_detalles d ON d.conteo_id = c.id
              WHERE c.estado='abierto' AND $scopeCo
              GROUP BY c.id, c.numero, su.nombre, c.created_at
              ORDER BY c.created_at LIMIT 4");
    }
}

/* ============================================================
 *  COMPRAS Y PROVEEDORES
 * ============================================================ */
$compras = [];
if ($verCompras) {
    $scopeC = $sid === null ? '1=1' : 'c.sucursal_id = ' . (int) $sid;
    $compras['mes'] = qOne(
        "SELECT COUNT(*) n, COALESCE(SUM(c.total),0) total FROM compras c
          WHERE c.estado <> 'anulada' AND $scopeC AND c.fecha BETWEEN ? AND ?",
        [substr($mesIni, 0, 10), substr($mesFin, 0, 10)]
    ) ?: ['n' => 0, 'total' => 0];
    // La deuda no se filtra por sucursal: se le paga al proveedor, no a la tienda.
    $compras['deuda'] = function_exists('cxp_disponible') && cxp_disponible()
        ? cxp_resumen() : null;
}

/* ============================================================
 *  CRM
 * ============================================================ */
$crm = [];
if ($verCrm) {
    $scopeO = $sid === null ? '1=1' : 'o.sucursal_id = ' . (int) $sid;
    $crm['embudo'] = qOne(
        "SELECT COUNT(*) n, COALESCE(SUM(o.valor_estimado),0) valor,
                COALESCE(SUM(o.valor_estimado * o.probabilidad / 100),0) ponderado
           FROM crm_oportunidades o
          WHERE o.etapa NOT IN ('ganada','perdida') AND $scopeO"
    ) ?: ['n' => 0, 'valor' => 0, 'ponderado' => 0];
    $crm['ganadas'] = qOne(
        "SELECT COUNT(*) n, COALESCE(SUM(o.valor_estimado),0) valor
           FROM crm_oportunidades o
          WHERE o.etapa = 'ganada' AND $scopeO AND o.fecha_cierre_real BETWEEN ? AND ?",
        [substr($mesIni, 0, 10), substr($mesFin, 0, 10)]
    ) ?: ['n' => 0, 'valor' => 0];
    // Solo las mías cuando no soy quien las reparte: un vendedor no necesita el
    // pendiente de toda la oficina en su pantalla de entrada.
    $uid = (int) (current_user()['id'] ?? 0);
    $crm['tareas'] = (int) qVal(
        "SELECT COUNT(*) FROM crm_tareas t
          WHERE t.estado = 'pendiente' AND t.vence_at < NOW()
            AND (t.asignado_a = ? OR t.asignado_a IS NULL)", [$uid]
    );
}

/* ============================================================
 *  RECURSOS HUMANOS
 * ============================================================ */
$rrhh = [];
if ($verRRHH) {
    $scopeE = $sid === null ? '1=1' : '(e.sucursal_id = ' . (int) $sid . ' OR e.sucursal_id IS NULL)';
    $scopeN = $sid === null ? '1=1' : '(n.sucursal_id = ' . (int) $sid . ' OR n.sucursal_id IS NULL)';

    if ($verEmpleados) {
        $rrhh['plantilla'] = qOne(
            "SELECT COUNT(*) n, COALESCE(SUM(e.salario),0) masa
               FROM empleados e WHERE e.estado='activo' AND $scopeE"
        ) ?: ['n' => 0, 'masa' => 0];
        // La cédula es lo que identifica a la persona en la TSS: si no cuadra,
        // ese empleado no cotiza. Se avisa aquí porque es la pantalla de entrada.
        $rrhh['cedulas'] = [];
        foreach (qAll("SELECT e.nombre, e.apellido, e.cedula FROM empleados e
                        WHERE e.estado='activo' AND $scopeE") as $e) {
            if (!dgiiDocumentoValido($e['cedula'])) {
                $rrhh['cedulas'][] = trim($e['nombre'] . ' ' . $e['apellido']);
            }
        }
    }
    if ($verNomina) {
        // La regalía queda fuera de «última nómina»: su período termina el 31 de
        // diciembre, así que en cuanto se genera se convierte en la más reciente
        // y tapa la quincena de verdad hasta fin de año. Tiene su propia pantalla.
        $rrhh['nomina'] = qOne("SELECT n.* FROM nominas n
                                 WHERE n.tipo <> 'regalia' AND $scopeN
                                 ORDER BY n.fecha_hasta DESC, n.id DESC LIMIT 1");
        $rrhh['por_pagar'] = (int) qVal("SELECT COUNT(*) FROM nominas n
                                          WHERE n.estado <> 'pagada' AND $scopeN");
    }
    if ($verTss) {
        $rrhh['tss'] = tssDeclaracionMes(date('Y-m'));
        // Lo que de verdad hay que hacer no es mirar el mes en curso —que va a
        // medias— sino pagar lo de los meses ya cerrados. Y mientras eso no se
        // registre, ese costo (más del 20% de lo que cuesta la nómina) no está
        // en el resultado. Se miran tres meses hacia atrás: más que eso ya no es
        // un olvido, es otra conversación.
        $rrhh['tss_deuda'] = ['total' => 0.0, 'meses' => []];
        if (function_exists('tssObligacionesMes')) {
            for ($i = 1; $i <= 3; $i++) {
                $m = date('Y-m', strtotime("-$i month"));
                $ob = tssObligacionesMes($m);
                if ($ob['nominas'] === 0) continue;
                $falta = 0.0;
                if (empty($ob['tss']['pago'])) $falta += (float) $ob['tss']['total'];
                if (empty($ob['isr']['pago'])) $falta += (float) $ob['isr']['total'];
                if ($falta <= 0.005) continue;
                $rrhh['tss_deuda']['total'] += $falta;
                $rrhh['tss_deuda']['meses'][] = $m;
            }
        }
    }
    if ($verPrestamos) $rrhh['pres'] = presResumen();
    if ($verVacaciones) {
        $rrhh['vacaciones'] = (int) qVal("SELECT COUNT(*) FROM vacaciones WHERE estado='solicitada'");
    }
}

/* ============================================================
 *  METAS ACTIVAS
 * ============================================================ */
$metas = [];
if (can('metas.ver')) {
    $metas = qAll(
        "SELECT m.*, CONCAT(u.nombre,' ',u.apellido) AS vendedor, su.nombre AS sucursal
           FROM metas_ventas m
           LEFT JOIN usuarios u    ON u.id  = m.usuario_id
           LEFT JOIN sucursales su ON su.id = m.sucursal_id
          WHERE m.estado='activa' AND ? BETWEEN m.periodo_inicio AND m.periodo_fin
          ORDER BY m.periodo_fin LIMIT 3",
        [$hoy]
    );
}

/* ============================================================
 *  LISTAS DE APOYO
 * ============================================================ */
$topProductos = qAll(
    "SELECT p.id, p.nombre, c.nombre AS categoria, COALESCE(c.color,'slate') AS color,
            SUM(vd.cantidad) AS unidades, SUM(vd.subtotal - vd.descuento) AS total
       FROM venta_detalles vd
       JOIN ventas v ON v.id = vd.venta_id
       JOIN productos p ON p.id = vd.producto_id
       LEFT JOIN categorias c ON c.id = p.categoria_id
      WHERE " . rep_estados_venta() . " AND $scopeV AND v.fecha BETWEEN '$listaIni' AND '$listaFin'
      GROUP BY p.id, p.nombre, c.nombre, c.color ORDER BY total DESC"
);
// Se recorta a cinco DESPUÉS de restar lo devuelto: si no, el más vendido puede
// ser el que más volvió.
$devLin = rep_devoluciones_por_linea($listaIni, $listaFin, $scopeV);
foreach ($topProductos as $i => $t) {
    $dv = $devLin[(string) $t['id']] ?? ['unidades' => 0.0, 'base' => 0.0];
    $topProductos[$i]['unidades'] = (float) $t['unidades'] - $dv['unidades'];
    $topProductos[$i]['total']    = (float) $t['total'] - $dv['base'];
}
usort($topProductos, fn($a, $b) => $b['total'] <=> $a['total']);
$topProductos = array_slice($topProductos, 0, 5);
$maxProd = max(1, max(array_column($topProductos, 'total') ?: [1]));

/*
 * Las 6 últimas ventas se resuelven en dos pasos a propósito. Si se pone el
 * ORDER BY ... LIMIT sobre la consulta con los cuatro JOIN, el optimizador une
 * primero y ordena después: 387 ms recorriendo 60.000 filas para devolver 6.
 * Eligiendo los ids solos, `idx_v_fecha` se lee al revés y para en la sexta.
 */
$scopeUlt = $sid === null ? '1=1' : 'sucursal_id = ' . (int) $sid;
$recientes = qAll(
    "SELECT v.id, v.numero, v.total, v.fecha, v.estado, su.nombre AS sucursal,
            COALESCE(cl.nombre,'Consumidor final') AS cliente, CONCAT(u.nombre,' ',u.apellido) AS vendedor
       FROM (SELECT id FROM ventas WHERE $scopeUlt ORDER BY fecha DESC LIMIT 6) ult
       JOIN ventas v ON v.id = ult.id
       JOIN sucursales su ON su.id = v.sucursal_id
       LEFT JOIN clientes cl ON cl.id = v.cliente_id
       JOIN usuarios u ON u.id = v.usuario_id
      ORDER BY v.fecha DESC"
);

$stockBajo = qAll(
    "SELECT p.nombre, p.stock_minimo, s.cantidad, su.nombre AS sucursal
       FROM inventario_stock s
       JOIN productos p ON p.id = s.producto_id
       JOIN sucursales su ON su.id = s.sucursal_id
      WHERE p.activo=1 AND p.tipo='producto' AND s.cantidad <= p.stock_minimo AND $scopeS
      ORDER BY s.cantidad ASC LIMIT 6"
);
// Cuántos hay en total y cuántos ya en cero: sin esto la tarjeta enseña seis
// filas y no se sabe si son todos o la punta de un problema mayor.
$stockResumen = qOne(
    "SELECT COUNT(*) n, COALESCE(SUM(s.cantidad <= 0),0) agotados
       FROM inventario_stock s
       JOIN productos p ON p.id = s.producto_id
      WHERE p.activo=1 AND p.tipo='producto' AND s.cantidad <= p.stock_minimo AND $scopeS"
) ?: ['n' => 0, 'agotados' => 0];

$alertas = array_slice(array_filter(
    notif_listar(['limit' => 20]),
    fn($n) => in_array($n['prioridad'], ['critica', 'alta'], true)
), 0, 3);

$catColors = ['blue'=>marca_app(),'emerald'=>'#10b981','amber'=>'#f59e0b','rose'=>'#f43f5e','indigo'=>'#6366f1',
              'cyan'=>'#06b6d4','sky'=>'#0ea5e9','pink'=>'#ec4899','slate'=>'#64748b','violet'=>'#8b5cf6'];

$saludo = (int) date('G') < 12 ? 'Buenos días' : ((int) date('G') < 19 ? 'Buenas tardes' : 'Buenas noches');
$nombreUsuario = current_user()['nombre'] ?? '';

$acciones = '';
if (can('pos.vender')) $acciones .= '<a href="' . url('modules/pos/index.php') . '" class="btn btn-primary">' . icon('cart', 'w-4 h-4') . ' Nueva venta</a>';
if (can('reportes.ver')) $acciones .= '<a href="' . url('modules/reportes/index.php') . '" class="btn btn-ghost">' . icon('chart', 'w-4 h-4') . ' Reportes</a>';

layout_start(
    $saludo . ($nombreUsuario ? ', ' . $nombreUsuario : ''),
    ($sid === null ? 'Todas las sucursales' : (current_user()['sucursal_nombre'] ?? '')) . ' · ' . fechaLarga($hoy),
    $acciones
);
?>

<?php if ($alertas): ?>
<!-- Requiere atención -->
<div class="flex flex-col sm:flex-row gap-3 mb-5">
  <?php foreach ($alertas as $a):
    [$icoCls, $barra] = notif_estilo($a['color']);
    $href = $a['url']
        ? url('modules/notificaciones/ir.php?id=' . (int) $a['id'] . '&_t=' . urlencode(csrf_token()))
        : url('modules/notificaciones/index.php');
  ?>
    <a href="<?= e($href) ?>" class="card flex-1 flex items-start gap-3 p-3.5 relative overflow-hidden hover:shadow-soft hover:-translate-y-0.5 transition-all duration-200 group">
      <span class="absolute left-0 inset-y-0 w-1 <?= $barra ?>"></span>
      <span class="w-9 h-9 rounded-xl <?= $icoCls ?> flex items-center justify-center shrink-0"><?= icon($a['icono'], 'w-4 h-4') ?></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[13px] font-semibold text-slate-800 leading-snug group-hover:text-blue-700 transition-colors"><?= e($a['titulo']) ?></span>
        <?php if ($a['mensaje']): ?>
          <span class="block text-[11.5px] text-slate-500 mt-0.5 line-clamp-1"><?= e($a['mensaje']) ?></span>
        <?php endif; ?>
      </span>
      <span class="text-slate-300 group-hover:text-blue-600 group-hover:translate-x-0.5 transition-all shrink-0 mt-1"><?= icon('arrow-right', 'w-4 h-4') ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($verNada): ?>
  <!-- Sin un solo permiso de venta, caja o inventario no hay tablero que
       enseñar. Antes se le pintaba a esta persona la facturación del mes y la
       lista de clientes; ahora se le dice qué SÍ puede hacer. -->
  <?= empty_state(
      'Tu tablero está en tus módulos',
      'Tu usuario trabaja con ' . implode(', ', array_map(
          fn($g) => $g[0], array_filter(nav_groups(), fn($g) => $g[0] !== 'Principal' && array_filter(
              $g[1], fn($i) => $i[3] === null || can($i[3])
          ))
      )) . '. Ábrelos desde el menú de la izquierda.',
      'grid'
  ) ?>
<?php endif; ?>

<!-- ============ HOY + CAJA ============ -->
<?php if ($verVentas || $verCaja): ?>
<div class="grid grid-cols-1 <?= ($verVentas && $verCaja) ? 'lg:grid-cols-3' : '' ?> gap-5 mb-5">

  <!-- Hoy -->
  <?php if ($verVentas): ?>
  <section class="card <?= $verCaja ? 'lg:col-span-2' : '' ?> p-6 relative overflow-hidden flex flex-col">
    <div class="absolute -top-24 -right-16 w-72 h-72 bg-blue-500/[0.04] rounded-full pointer-events-none"></div>

    <div class="relative flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">Ventas de hoy</p>
        <!-- La cifra escala con el ancho: fijarla en 2.75rem la partía en dos
             líneas en pantallas de 1440 px con importes de ocho dígitos. -->
        <p class="text-[clamp(1.9rem,3.2vw,2.75rem)] leading-none font-extrabold text-slate-800 tracking-tight tabular-nums mt-2"><?= money($ventasHoy) ?></p>
        <div class="flex flex-wrap items-center gap-2.5 mt-3">
          <?php if ($deltaHoy !== null): ?>
            <span class="badge <?= $deltaHoy >= 0 ? 'stat-trend-up' : 'stat-trend-down' ?>">
              <?= icon($deltaHoy >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') ?> <?= number_format(abs($deltaHoy), 1) ?>%
            </span>
            <span class="text-[12.5px] text-slate-400">
              vs. <?= e(mb_strtolower(['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'][(int) date('w', strtotime($semPasada))])) ?> pasado
              (<?= money($ventasSemPasada, false) ?>)
            </span>
          <?php else: ?>
            <span class="text-[12.5px] text-slate-400">Sin ventas el mismo día de la semana pasada para comparar</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="flex gap-6 sm:gap-8">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Facturas</p>
          <p class="text-2xl font-extrabold text-slate-800 tabular-nums mt-1"><?= number_format($nVentasHoy) ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Ticket</p>
          <p class="text-2xl font-extrabold text-slate-800 tabular-nums mt-1"><?= money($ticketHoy, false) ?></p>
        </div>
        <?php if ($verDinero): ?>
          <div>
            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Utilidad</p>
            <p class="text-2xl font-extrabold <?= $utilidadHoy >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> tabular-nums mt-1"><?= money($utilidadHoy, false) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Ritmo: por horas si ya se vendió hoy; si no, los últimos 7 días.
         Con el gráfico por horas vacío la tarjeta quedaba hueca justo a primera
         hora de la mañana, que es cuando más se mira. -->
    <?php if ($ventasHoy <= 0 && !$hay7): ?>
      <!-- Ni hoy ni la semana: no hay nada que graficar, se ofrece la acción. -->
      <div class="relative mt-6 flex-1 flex flex-col items-center justify-center text-center py-8">
        <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center mb-3"><?= icon('cart', 'w-6 h-6') ?></div>
        <p class="text-sm font-semibold text-slate-700">Sin ventas en los últimos siete días</p>
        <p class="text-xs text-slate-400 mt-1 max-w-[300px]">En cuanto se facture, aquí verás el ritmo del día hora por hora.</p>
        <?php if (can('pos.vender')): ?>
          <a href="<?= e(url('modules/pos/index.php')) ?>" class="btn btn-primary mt-4"><?= icon('cart', 'w-4 h-4') ?> Ir al punto de venta</a>
        <?php endif; ?>
      </div>
    <?php elseif ($ventasHoy <= 0): ?>
      <div class="relative mt-6 flex-1 flex flex-col justify-end">
        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400 mb-3">Últimos 7 días</p>
        <div class="flex items-end justify-between gap-2 h-24 min-h-[60px]">
          <?php foreach ($ultimos7 as $d):
            $esHoy = date('Y-m-d', $d['fecha']) === $hoy;
          ?>
            <div class="flex-1 flex flex-col justify-end h-full group relative">
              <?php if ($d['valor'] > 0): ?>
                <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10.5px] font-semibold text-slate-700 bg-white border border-slate-200 rounded px-1.5 py-0.5 opacity-0 group-hover:opacity-100 transition whitespace-nowrap shadow-sm z-10">
                  <?= money($d['valor'], false) ?>
                </span>
              <?php endif; ?>
              <div class="w-full rounded-t transition-all duration-500 <?= $esHoy ? 'bg-slate-200' : ($d['valor'] > 0 ? 'bg-blue-400' : 'bg-slate-100') ?>"
                   style="height:<?= $d['valor'] > 0 ? max($d['valor'] / $max7 * 100, 5) : 3 ?>%"
                   title="<?= e(date('d/m/Y', $d['fecha'])) ?> · <?= money($d['valor']) ?>"></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="flex justify-between mt-2 gap-2">
          <?php foreach ($ultimos7 as $d): ?>
            <span class="flex-1 text-center text-[10.5px] font-semibold <?= date('Y-m-d', $d['fecha']) === $hoy ? 'text-blue-600' : 'text-slate-400' ?>"><?= $d['etiqueta'] ?></span>
          <?php endforeach; ?>
        </div>
        <p class="text-[11.5px] text-slate-400 mt-2.5">
          Todavía no hay ventas hoy · así vienen los últimos siete días
        </p>
      </div>
    <?php else: ?>
    <div class="relative mt-7 flex-1 flex flex-col justify-end">
      <?php
        // Las horas con venta van en azul aunque estén «adelantadas» respecto al
        // reloj: colorear por hora en vez de por dato dejaba en gris barras que
        // sí tenían ventas. El gris queda solo para las horas sin nada.
        $horaPico = array_search($maxHora, $porHora, true);
      ?>
      <div class="flex items-end justify-between gap-[3px] h-28 min-h-[70px]">
        <?php foreach ($porHora as $h => $v):
          $pct     = $v / $maxHora * 100;
          $esAhora = $h === $horaActual;
          $tono    = $v <= 0 ? 'bg-slate-100' : ($esAhora ? 'bg-blue-600' : ($h === $horaPico ? 'bg-blue-500' : 'bg-blue-400'));
        ?>
          <div class="flex-1 flex flex-col justify-end h-full group relative">
            <?php if ($v > 0): ?>
              <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10.5px] font-semibold text-slate-700 bg-white border border-slate-200 rounded px-1.5 py-0.5 opacity-0 group-hover:opacity-100 transition whitespace-nowrap shadow-sm z-10">
                <?= sprintf('%02d:00', $h) ?> · <?= money($v, false) ?>
              </span>
            <?php endif; ?>
            <div class="w-full rounded-t transition-all duration-500 <?= $tono ?> <?= $esAhora ? 'ring-2 ring-blue-200' : '' ?>"
                 style="height:<?= $v > 0 ? max($pct, 5) : 3 ?>%"
                 title="<?= sprintf('%02d:00', $h) ?> · <?= money($v) ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="flex justify-between mt-2 text-[10.5px] text-slate-400 font-medium">
        <span>6 AM</span><span>12 M</span><span>6 PM</span><span>10 PM</span>
      </div>
      <p class="text-[11.5px] text-slate-400 mt-2.5">
        Ritmo del día · la mejor hora hasta ahora fue <strong class="text-slate-500"><?= sprintf('%02d:00', (int) $horaPico) ?></strong>
      </p>
    </div>
    <?php endif; ?>
  </section>

  <?php endif; ?>

  <!-- Caja -->
  <?php if ($verCaja): ?>
  <section class="card p-5 flex flex-col">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-bold text-slate-800">Estado de caja</h3>
      <?php if (can('caja.ver')): ?>
        <a href="<?= e(url('modules/pos/caja.php')) ?>" class="text-[13px] font-semibold text-blue-600 hover:text-blue-700">Abrir →</a>
      <?php endif; ?>
    </div>

    <?php if (!$cajasAbiertas): ?>
      <div class="flex-1 flex flex-col items-center justify-center text-center py-6">
        <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mb-3"><?= icon('cash', 'w-6 h-6') ?></div>
        <p class="text-sm font-semibold text-slate-700">Ninguna caja abierta</p>
        <p class="text-xs text-slate-400 mt-1 max-w-[200px]">Para vender en el punto de venta hay que abrir una caja primero.</p>
      </div>
    <?php else: ?>
      <div class="flex-1 space-y-3">
        <?php foreach ($cajasAbiertas as $c):
          $enCaja = (float) $c['monto_apertura'] + (float) $c['efectivo'];
          $horas  = max(0, (int) floor((time() - strtotime($c['abierta_at'])) / 3600));
          // Pasadas 48 horas «785h» no se lee; en días sí, y además es la señal
          // de que a esa caja se le olvidó el cierre.
          $abierta = $horas < 48 ? $horas . 'h' : (int) floor($horas / 24) . ' días';
        ?>
          <div class="rounded-xl border border-slate-200 p-3.5">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-[13.5px] font-semibold text-slate-800 truncate"><?= e($c['caja']) ?></p>
                <p class="text-[11.5px] text-slate-400 truncate"><?= e($c['cajero']) ?><?= $sid === null ? ' · ' . e($c['sucursal']) : '' ?></p>
              </div>
              <span class="badge <?= $horas >= 12 ? 'badge-amber' : 'badge-emerald' ?> shrink-0"><?= e($abierta) ?></span>
            </div>
            <div class="flex items-end justify-between mt-3 pt-3 border-t border-slate-100">
              <div>
                <p class="text-[10.5px] font-bold uppercase tracking-wide text-slate-400">Efectivo esperado</p>
                <p class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($enCaja, false) ?></p>
              </div>
              <div class="text-right">
                <p class="text-[10.5px] font-bold uppercase tracking-wide text-slate-400">Vendido</p>
                <p class="text-sm font-semibold text-slate-600 tabular-nums"><?= money($c['vendido'], false) ?></p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============ KPIs DEL MES ============ -->
<?php if ($verVentas): ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
  <?php
  $kpis = [
    ['Ventas del mes', money($ventasMes), 'cash', 'blue', $delta($ventasMes, $ventasPrev),
     number_format($nVentasMes) . ' factura(s) · mes anterior RD$ ' . numAbrev($ventasPrev), false],
    ['Ticket promedio', money($ticketMes), 'receipt', 'indigo', $delta($ticketMes, $ticketPrev),
     'Cuánto gasta cada cliente por compra', false],
    // COUNT(DISTINCT cliente_id) deja fuera las ventas a consumidor final
    // (cliente_id NULL). Se dice en la nota para que nadie lo lea como
    // «cuánta gente entró a la tienda».
    ['Clientes atendidos', number_format($clientesMes), 'users', 'cyan', $delta((float) $clientesMes, (float) $clientesPrev),
     'Identificados este mes, sin consumidor final', false],
  ];
  if ($verDinero) {
    $kpis[] = ['Utilidad del mes', money($utilidadMes), 'trending', $utilidadMes >= 0 ? 'emerald' : 'rose',
      $delta($utilidadMes, $utilidadPrev), 'Margen ' . number_format($margenMes, 1) . '% sobre la venta', false];
  } else {
    $kpis[] = $proyectable
      ? ['Proyección del mes', money($proyeccion), 'target', 'violet', null,
         'Al ritmo de los ' . $diasCerrados . ' día(s) ya cerrados', false]
      : ['Cierre del mes anterior', money($ventasPrev), 'target', 'violet', null,
         'Referencia: aún es pronto para proyectar', false];
  }
  $fondo = ['blue'=>'bg-blue-50 text-blue-600','indigo'=>'bg-indigo-50 text-indigo-600','cyan'=>'bg-cyan-50 text-cyan-600',
            'emerald'=>'bg-emerald-50 text-emerald-600','rose'=>'bg-rose-50 text-rose-600','violet'=>'bg-violet-50 text-violet-600'];
  foreach ($kpis as [$lbl, $val, $ico, $col, $d, $nota]): ?>
    <div class="card p-5">
      <div class="flex items-start justify-between gap-2">
        <div class="w-10 h-10 rounded-xl <?= $fondo[$col] ?> flex items-center justify-center shrink-0"><?= icon($ico, 'w-5 h-5') ?></div>
        <?php if ($d !== null): ?>
          <span class="badge <?= $d >= 0 ? 'stat-trend-up' : 'stat-trend-down' ?>" title="Contra el mes anterior completo">
            <?= icon($d >= 0 ? 'arrow-up' : 'arrow-down', 'w-3 h-3') ?> <?= number_format(abs($d), 1) ?>%
          </span>
        <?php endif; ?>
      </div>
      <p class="text-[12.5px] font-medium text-slate-500 mt-4"><?= e($lbl) ?></p>
      <!-- Escala con el ancho pero nunca se recorta: un importe cortado con
           puntos suspensivos es peor que uno que ocupa dos líneas. -->
      <p class="text-[clamp(1.05rem,1.5vw,1.6rem)] leading-tight font-extrabold text-slate-800 tabular-nums tracking-tight mt-0.5"><?= $val ?></p>
      <p class="text-[11.5px] text-slate-400 mt-1.5 leading-relaxed"><?= e($nota) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<!-- ============ CURVA DEL MES + SUCURSALES ============ -->
<div class="grid grid-cols-1 <?= ($porSucursal && $verSucursales) ? 'lg:grid-cols-3' : '' ?> gap-5 mb-5">
  <section class="card p-5 <?= $porSucursal ? 'lg:col-span-2' : '' ?> flex flex-col">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h3 class="font-bold text-slate-800">Ritmo del mes</h3>
        <p class="text-sm text-slate-400">Acumulado día a día contra el mes anterior</p>
      </div>
      <div class="text-right">
        <?php if ($proyectable): ?>
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Proyección al cierre</p>
          <p class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($proyeccion) ?></p>
          <p class="text-[11px] text-slate-400">al ritmo de <?= $diasCerrados ?> día(s) cerrados</p>
        <?php else: ?>
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Cierre del mes anterior</p>
          <p class="text-lg font-extrabold text-slate-800 tabular-nums"><?= money($ventasPrev) ?></p>
          <p class="text-[11px] text-slate-400">aún es pronto para proyectar</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="flex-1">
      <?= lineChart([
          ['nombre' => 'Este mes', 'color' => marca_app(), 'valores' => $acumAct, 'area' => true],
          ['nombre' => 'Mes anterior', 'color' => '#cbd5e1', 'valores' => $acumPre, 'punteada' => true],
      ], $labels, ['alto' => 250]) ?>
    </div>
    <?php if ($ventasPrev > 0):
      $mismoDiaPrev = 0.0;
      for ($d = 1; $d <= min($diaMes, $diasPrev); $d++) $mismoDiaPrev += $diaMesPre[$d] ?? 0;
      $difAcum = $ventasMes - $mismoDiaPrev;
    ?>
      <p class="text-[12.5px] text-slate-500 mt-3 pt-3 border-t border-slate-100">
        A día <?= $diaMes ?>, el mes pasado llevaba <strong class="text-slate-700"><?= money($mismoDiaPrev, false) ?></strong>.
        Vas <strong class="<?= $difAcum >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $difAcum >= 0 ? 'adelantado' : 'atrasado' ?>
        <?= money(abs($difAcum), false) ?></strong>.
      </p>
    <?php endif; ?>
  </section>

  <?php if ($porSucursal && $verSucursales): ?>
    <section class="card p-5 flex flex-col">
      <div class="flex items-start justify-between gap-2">
        <div>
          <h3 class="font-bold text-slate-800">Por sucursal</h3>
          <p class="text-sm text-slate-400"><?= e($listaLabel) ?></p>
        </div>
        <?php if (can('reportes.ejecutivo')): ?>
          <a href="<?= e(url('modules/reportes/comparativo.php')) ?>" class="text-[13px] font-semibold text-blue-600 hover:text-blue-700 shrink-0">Comparar →</a>
        <?php endif; ?>
      </div>

      <div class="flex items-baseline gap-2 mt-4 pb-4 border-b border-slate-100">
        <span class="text-2xl font-extrabold text-slate-800 tabular-nums"><?= money($totalSuc) ?></span>
        <span class="text-[12px] text-slate-400">en <?= count($porSucursal) ?> sucursal(es)</span>
      </div>

      <div class="flex-1 flex flex-col justify-evenly py-2">
        <?php foreach ($porSucursal as $i => $s):
          $pct    = (float) $s['total'] / $totalSuc * 100;
          $ticket = (int) $s['n'] > 0 ? (float) $s['total'] / (int) $s['n'] : 0;
        ?>
          <div class="py-2">
            <div class="flex items-baseline justify-between gap-2 mb-1.5">
              <span class="text-[13.5px] font-semibold text-slate-700 truncate flex items-center gap-2">
                <span class="w-2 h-2 rounded-full shrink-0" style="background:<?= rep_color($i) ?>"></span><?= e($s['sucursal']) ?>
              </span>
              <span class="text-[13px] font-bold text-slate-800 tabular-nums shrink-0"><?= money($s['total'], false) ?></span>
            </div>
            <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full rounded-full transition-all duration-700" style="width:<?= max($pct, 1.5) ?>%;background:<?= rep_color($i) ?>"></div>
            </div>
            <div class="flex justify-between mt-1.5 text-[11px] text-slate-400">
              <span><?= number_format((int) $s['n']) ?> factura(s) · ticket <?= money($ticket, false) ?></span>
              <span class="font-semibold text-slate-500"><?= number_format($pct, 1) ?>%</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============ METAS + TOP PRODUCTOS ============ -->
<?php
// La tarjeta de la derecha cambia de contenido según haya metas o no, así que
// su permiso también: metas si las hay, inventario si no.
$verDerecha = ($metas && $verMetas) || (!$metas && $verInventario);
?>
<?php if ($verProductos || $verDerecha): ?>
<div class="grid grid-cols-1 <?= ($verProductos && $verDerecha) ? 'lg:grid-cols-2' : '' ?> gap-5 mb-5">
  <?php if ($verProductos): ?>
  <section class="card p-5 flex flex-col">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h3 class="font-bold text-slate-800">Productos que más venden</h3>
        <p class="text-sm text-slate-400"><?= e($listaLabel) ?>, por ingreso</p>
      </div>
      <?php if (can('reportes.operacion')): ?>
        <a href="<?= e(url('modules/reportes/productos.php')) ?>" class="text-[13px] font-semibold text-blue-600 hover:text-blue-700">Ver todos →</a>
      <?php endif; ?>
    </div>
    <div class="flex-1 flex flex-col justify-center">
      <?php if (!$topProductos): ?>
        <?= empty_state('Sin ventas en el período', 'Cuando factures verás aquí qué se mueve.', 'package') ?>
      <?php else: foreach ($topProductos as $i => $p): ?>
        <div class="flex items-center gap-3 mb-3.5 last:mb-0">
          <span class="w-6 h-6 rounded-lg bg-slate-100 text-slate-500 text-[11px] font-bold flex items-center justify-center shrink-0"><?= $i + 1 ?></span>
          <div class="min-w-0 flex-1">
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-[13.5px] font-semibold text-slate-700 truncate"><?= e($p['nombre']) ?></span>
              <span class="text-[13px] font-bold text-slate-800 tabular-nums shrink-0"><?= money($p['total'], false) ?></span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden mt-1.5">
              <div class="h-full rounded-full" style="width:<?= max((float) $p['total'] / $maxProd * 100, 2) ?>%;background:<?= $catColors[$p['color']] ?? '#64748b' ?>"></div>
            </div>
            <p class="text-[11px] text-slate-400 mt-1"><?= qty($p['unidades']) ?> unidad(es) · <?= e($p['categoria'] ?: 'Sin categoría') ?></p>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <?php endif; ?>

  <?php if ($verDerecha): ?>
  <section class="card p-5 flex flex-col">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h3 class="font-bold text-slate-800"><?= $metas ? 'Cumplimiento de metas' : 'Inventario en alerta' ?></h3>
        <p class="text-sm text-slate-400">
          <?php if ($metas): ?>
            Metas activas de este periodo
          <?php elseif ((int) $stockResumen['n'] > 0): ?>
            <?= number_format((int) $stockResumen['n']) ?> producto(s) bajo el mínimo<?php
              if ((int) $stockResumen['agotados'] > 0): ?>, <span class="font-semibold text-rose-600"><?= number_format((int) $stockResumen['agotados']) ?> agotado(s)</span><?php endif; ?>
          <?php else: ?>
            Productos por debajo del mínimo
          <?php endif; ?>
        </p>
      </div>
      <?php if ($metas && can('metas.ver')): ?>
        <a href="<?= e(url('modules/finanzas/metas.php')) ?>" class="text-[13px] font-semibold text-blue-600 hover:text-blue-700">Gestionar →</a>
      <?php elseif (!$metas && can('inventario.ver')): ?>
        <a href="<?= e(url('modules/inventario/stock.php')) ?>" class="text-[13px] font-semibold text-blue-600 hover:text-blue-700">Ver stock →</a>
      <?php endif; ?>
    </div>

    <div class="flex-1 flex flex-col justify-center">
      <?php if ($metas): ?>
        <?php foreach ($metas as $m):
          $pr = metaProgreso($m);
          $quien = $m['vendedor'] ?: ($m['sucursal'] ?: 'Meta global');
          $col = rep_color_nombre(metaColor($pr['pct']));
        ?>
          <div class="mb-5 last:mb-0">
            <div class="flex items-baseline justify-between gap-2 mb-2">
              <span class="text-[13.5px] font-semibold text-slate-700 truncate"><?= e($quien) ?></span>
              <span class="text-[13px] text-slate-500 tabular-nums shrink-0">
                <strong class="text-slate-800"><?= money($pr['vendido'], false) ?></strong> / <?= money($pr['objetivo'], false) ?>
              </span>
            </div>
            <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full rounded-full transition-all duration-700" style="width:<?= max($pr['pct'], 1.5) ?>%;background:<?= $col ?>"></div>
            </div>
            <div class="flex justify-between mt-1.5 text-[11px] text-slate-400">
              <span><?= number_format($pr['pct'], 1) ?>% cumplido</span>
              <span><?= $pr['dias_restantes'] ?> día(s) · faltan <?= money($pr['falta'], false) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php elseif (!$stockBajo): ?>
        <div class="flex flex-col items-center text-center py-6">
          <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-500 flex items-center justify-center mb-3"><?= icon('check', 'w-6 h-6') ?></div>
          <p class="text-sm font-semibold text-slate-700">Inventario en orden</p>
          <p class="text-xs text-slate-400 mt-1 max-w-[220px]">Ningún producto está por debajo de su stock mínimo.</p>
        </div>
      <?php else: foreach ($stockBajo as $sb): ?>
        <div class="flex items-center gap-3 mb-4 last:mb-0">
          <span class="w-9 h-9 rounded-xl <?= (float) $sb['cantidad'] <= 0 ? 'bg-rose-50 text-rose-600' : 'bg-amber-50 text-amber-600' ?> flex items-center justify-center shrink-0">
            <?= icon('package', 'w-4 h-4') ?>
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-[13.5px] font-semibold text-slate-700 truncate"><?= e($sb['nombre']) ?></p>
            <p class="text-[11px] text-slate-400 truncate"><?= e($sb['sucursal']) ?></p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-bold <?= (float) $sb['cantidad'] <= 0 ? 'text-rose-600' : 'text-amber-600' ?> tabular-nums"><?= qty($sb['cantidad']) ?></p>
            <p class="text-[10.5px] text-slate-400">mín <?= qty($sb['stock_minimo']) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
        <?php if ((int) $stockResumen['n'] > count($stockBajo)): ?>
          <p class="text-[11.5px] text-slate-400 mt-1 pt-3 border-t border-slate-100">
            y <?= number_format((int) $stockResumen['n'] - count($stockBajo)) ?> producto(s) más por debajo del mínimo.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============ VENTAS RECIENTES ============ -->
<?php if ($verVentas): ?>
<section class="card overflow-hidden">
  <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
    <div>
      <h3 class="font-bold text-slate-800">Últimas ventas</h3>
      <p class="text-sm text-slate-400">Lo más reciente que pasó por caja</p>
    </div>
    <?php if (can('ventas.ver')): ?>
      <a href="<?= e(url('modules/pos/ventas.php')) ?>" class="text-[13px] font-semibold text-blue-600 hover:text-blue-700">Ver todas →</a>
    <?php endif; ?>
  </div>
  <?php if (!$recientes): ?>
    <?= empty_state('Aún no hay ventas', 'En cuanto factures, las verás aquí.', 'receipt',
        can('pos.vender') ? '<a href="' . e(url('modules/pos/index.php')) . '" class="btn btn-primary">' . icon('cart', 'w-4 h-4') . ' Ir al punto de venta</a>' : '') ?>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead><tr>
          <th>Factura</th><th>Cliente</th><th>Vendedor</th>
          <?php if ($sid === null): ?><th>Sucursal</th><?php endif; ?>
          <th>Cuándo</th><th class="text-right">Total</th><th class="text-center">Estado</th>
        </tr></thead>
        <tbody>
          <?php foreach ($recientes as $r): ?>
            <tr>
              <td>
                <?php if (can('ventas.ver')): ?>
                  <a href="<?= e(url('modules/pos/ticket.php?id=' . (int) $r['id'])) ?>" target="_blank" rel="noopener"
                     class="font-semibold text-slate-700 hover:text-blue-700"><?= e($r['numero']) ?></a>
                <?php else: ?>
                  <span class="font-semibold text-slate-700"><?= e($r['numero']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-slate-600"><?= e($r['cliente']) ?></td>
              <td class="text-slate-500 text-[13px]"><?= e($r['vendedor']) ?></td>
              <?php if ($sid === null): ?><td class="text-slate-500 text-[13px]"><?= e($r['sucursal']) ?></td><?php endif; ?>
              <td class="text-slate-500 text-[13px] whitespace-nowrap"><?= e(tiempoRelativo($r['fecha'])) ?></td>
              <td class="text-right font-bold text-slate-800 tabular-nums"><?= money($r['total']) ?></td>
              <td class="text-center"><?= badgeFor($r['estado']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ============ MERCANCÍA EN MOVIMIENTO ============ -->
<?php if ($verOperacion): ?>
<section class="mb-6">
  <div class="flex items-center gap-2 mb-4">
    <?= icon('transfer', 'w-5 h-5 text-slate-400') ?>
    <h2 class="text-lg font-bold text-slate-800">Mercancía en movimiento</h2>
  </div>
  <?php
  $to = [];
  if ($verTraslados) {
      $to[] = ['label' => 'Esperando autorización', 'valor' => number_format($oper['por_aprobar']),
          'icono' => 'clock', 'color' => $oper['por_aprobar'] > 0 ? 'amber' : 'slate',
          'href' => url('modules/inventario/transferencias.php?estado=pendiente'),
          'nota' => $oper['por_aprobar'] > 0 ? 'La mercancía sigue en el origen' : 'Nada detenido'];
      $to[] = ['label' => 'Por recibir', 'valor' => number_format($oper['por_recibir']),
          'icono' => 'truck', 'color' => $oper['varadas'] > 0 ? 'rose' : ($oper['por_recibir'] > 0 ? 'sky' : 'slate'),
          'href' => url('modules/inventario/transferencias.php?estado=enviada'),
          'nota' => $oper['varadas'] > 0
              ? number_format($oper['varadas']) . ($oper['varadas'] === 1 ? ' lleva' : ' llevan') . ' más de 7 días en el aire'
              : 'Mercancía en camino hacia aquí'];
      $to[] = ['label' => 'Borradores de traslado', 'valor' => number_format($oper['borradores']),
          'icono' => 'edit', 'color' => $oper['borradores'] > 0 ? 'indigo' : 'slate',
          'href' => url('modules/inventario/transferencias.php?estado=borrador'),
          'nota' => 'Sin mandar a aprobación'];
  }
  if ($verConteos) {
      $nc = count($oper['conteos'] ?? []);
      $to[] = ['label' => 'Conteos sin aplicar', 'valor' => number_format($nc),
          'icono' => 'clipboard', 'color' => $nc > 0 ? 'amber' : 'emerald',
          'href' => url('modules/inventario/conteos.php'),
          'nota' => $nc > 0 ? 'El inventario no se corrige hasta aplicarlos' : 'Ningún conteo abierto'];
  }
  echo kpis($to, min(4, max(2, count($to))));
  ?>
  <?php if (!empty($oper['conteos'])): ?>
    <div class="card overflow-hidden">
      <?= toolbar('<h3 class="font-bold text-slate-800">Conteos que quedaron a medias</h3>',
          '<a href="' . e(url('modules/inventario/conteos.php')) . '" class="btn btn-ghost btn-sm">Ver todos</a>') ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>Conteo</th><th>Tienda</th><th class="text-center">Avance</th><th>Abierto</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($oper['conteos'] as $c):
                $lin = (int) $c['lineas']; $con = (int) $c['contadas'];
                $pct = $lin > 0 ? round($con / $lin * 100) : 0; ?>
              <tr>
                <td class="font-semibold text-slate-700"><?= e($c['numero']) ?></td>
                <td class="text-slate-600"><?= e($c['sucursal']) ?></td>
                <td class="text-center">
                  <span class="text-sm font-semibold text-slate-700"><?= $con ?>/<?= $lin ?></span>
                  <span class="block text-[11px] text-slate-400"><?= $pct ?>% contado</span>
                </td>
                <td class="text-slate-500 text-[13px] whitespace-nowrap"><?= e(tiempoRelativo($c['created_at'])) ?></td>
                <td class="text-right">
                  <a href="<?= e(url('modules/inventario/conteo.php?id=' . (int) $c['id'])) ?>"
                     class="btn btn-soft btn-sm">Continuar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ============ COMPRAS Y PROVEEDORES ============ -->
<?php if ($verCompras): ?>
<section class="mb-6">
  <div class="flex items-center gap-2 mb-4">
    <?= icon('truck', 'w-5 h-5 text-slate-400') ?>
    <h2 class="text-lg font-bold text-slate-800">Compras y proveedores</h2>
  </div>
  <?php
  $tc = [];
  $tc[] = ['label' => 'Comprado este mes', 'valor' => money((float) $compras['mes']['total']),
      'icono' => 'truck', 'color' => 'blue', 'href' => url('modules/inventario/compras.php'),
      'nota' => (int) $compras['mes']['n'] . ' factura(s) de compra'];
  if ($compras['deuda'] !== null) {
      $dd = $compras['deuda'];
      $tc[] = ['label' => 'Se le debe a proveedores', 'valor' => money($dd['total']),
          'icono' => 'wallet', 'color' => 'amber', 'href' => url('modules/inventario/cuentas_pagar.php'),
          'nota' => $dd['facturas'] . ' factura(s) · ' . $dd['proveedores'] . ' proveedor(es)'];
      $tc[] = ['label' => 'Vencido a más de 30 días', 'valor' => money($dd['vencido']),
          'icono' => 'alert', 'color' => $dd['vencido'] > 0 ? 'rose' : 'emerald',
          'href' => url('modules/inventario/cuentas_pagar.php'),
          'nota' => $dd['vencido'] > 0 ? 'Págalo o negocia el plazo' : 'Nada vencido, al día'];
  }
  echo kpis($tc, min(3, max(2, count($tc))));
  ?>
</section>
<?php endif; ?>

<!-- ============ CRM ============ -->
<?php if ($verCrm): ?>
<section class="mb-6">
  <div class="flex items-center gap-2 mb-4">
    <?= icon('trending', 'w-5 h-5 text-slate-400') ?>
    <h2 class="text-lg font-bold text-slate-800">Embudo de ventas</h2>
  </div>
  <?= kpis([
      ['label' => 'Oportunidades abiertas', 'valor' => number_format((int) $crm['embudo']['n']),
       'icono' => 'briefcase', 'color' => 'indigo', 'href' => url('modules/crm/oportunidades.php'),
       'nota' => money((float) $crm['embudo']['valor'], false) . ' en juego'],
      ['label' => 'Valor ponderado', 'valor' => money((float) $crm['embudo']['ponderado']),
       'icono' => 'chart', 'color' => 'violet', 'href' => url('modules/crm/index.php'),
       'nota' => 'Cada oportunidad por su probabilidad'],
      ['label' => 'Ganadas este mes', 'valor' => money((float) $crm['ganadas']['valor']),
       'icono' => 'check', 'color' => 'emerald', 'href' => url('modules/crm/oportunidades.php'),
       'nota' => (int) $crm['ganadas']['n'] . ' oportunidad(es) cerrada(s)'],
  ], 3) ?>
  <?php if (!empty($crm['tareas'])): ?>
    <a href="<?= e(url('modules/crm/tareas.php')) ?>"
       class="card p-4 flex items-start gap-3 hover:border-rose-300 hover:shadow-pop transition">
      <?= icon('alert', 'w-5 h-5 text-rose-500 mt-0.5 shrink-0') ?>
      <div class="min-w-0">
        <p class="font-semibold text-slate-800">
          <?= (int) $crm['tareas'] ?> <?= (int) $crm['tareas'] === 1 ? 'tarea vencida' : 'tareas vencidas' ?>
        </p>
        <p class="text-sm text-slate-500 leading-snug mt-0.5">
          Seguimientos tuyos cuya fecha ya pasó. Un cliente que espera una llamada que no llega
          se enfría solo.
        </p>
      </div>
    </a>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ============ RECURSOS HUMANOS ============ -->
<?php if ($verRRHH): ?>
<section class="mb-6">
  <div class="flex items-center gap-2 mb-4">
    <?= icon('users', 'w-5 h-5 text-slate-400') ?>
    <h2 class="text-lg font-bold text-slate-800">Recursos humanos</h2>
  </div>
  <?php
  $tarjetas = [];
  if ($verEmpleados) {
      $tarjetas[] = ['label' => 'Empleados activos', 'valor' => number_format((int) $rrhh['plantilla']['n']),
          'icono' => 'users', 'color' => 'blue', 'href' => url('modules/rrhh/empleados.php'),
          'nota' => 'Masa salarial ' . money((float) $rrhh['plantilla']['masa'], false) . ' al mes'];
  }
  if ($verNomina) {
      $n = $rrhh['nomina'] ?? null;
      $tarjetas[] = ['label' => 'Última nómina', 'valor' => $n ? money((float) $n['total_neto']) : '—',
          'icono' => 'wallet', 'color' => $n && $n['estado'] === 'pagada' ? 'emerald' : 'amber',
          'href' => url('modules/rrhh/nomina.php'),
          'nota' => $n
              ? e($n['descripcion']) . ' · ' . e(ucfirst($n['estado']))
              : 'Todavía no hay ninguna generada'];
  }
  if ($verTss) {
      $t = $rrhh['tss']['totales'] ?? [];
      $deuda = (float) ($rrhh['tss_deuda']['total'] ?? 0);
      $tarjetas[] = ['label' => 'TSS de este mes', 'valor' => money((float) ($t['general'] ?? 0)),
          'icono' => 'shield', 'color' => 'violet',
          'href' => url('modules/rrhh/tss.php?tab=declaracion'),
          'nota' => 'Empleado ' . money((float) ($t['empleado'] ?? 0), false)
                  . ' · empresa ' . money((float) ($t['empleador'] ?? 0), false)];
      $tarjetas[] = ['label' => 'TSS e IR-3 sin pagar', 'valor' => money($deuda),
          'icono' => 'dollar', 'color' => $deuda > 0 ? 'rose' : 'emerald',
          'href' => url('modules/rrhh/tss.php?tab=pagos'),
          'nota' => $deuda > 0
              ? 'De ' . count($rrhh['tss_deuda']['meses']) . ' mes(es) cerrado(s) · no está en el resultado'
              : 'Los meses cerrados están saldados'];
  }
  if ($verPrestamos) {
      $pr = $rrhh['pres'] ?? [];
      $tarjetas[] = ['label' => 'Préstamos activos', 'valor' => number_format((int) ($pr['activos'] ?? 0)),
          'icono' => 'cash', 'color' => 'cyan', 'href' => url('modules/rrhh/prestamos.php'),
          'nota' => 'Saldo ' . money((float) ($pr['saldo'] ?? 0), false)
                  . ' · cuota del mes ' . money((float) ($pr['cuota_mes'] ?? 0), false)];
  }
  echo kpis($tarjetas, min(4, max(2, count($tarjetas))));

  // Solo lo que hay que hacer. Una tarjeta de pendientes vacía es ruido.
  $pendientes = [];
  if (!empty($rrhh['cedulas'])) {
      $c = $rrhh['cedulas'];
      $pendientes[] = ['icono' => 'alert', 'color' => 'amber',
          'titulo' => count($c) . (count($c) === 1 ? ' cédula que no cuadra' : ' cédulas que no cuadran'),
          'texto' => 'En la TSS la cédula identifica a la persona: así no cuadra y ese empleado se queda '
                   . 'sin cotizar. ' . e(implode(', ', array_slice($c, 0, 4)))
                   . (count($c) > 4 ? ' y ' . (count($c) - 4) . ' más' : '') . '.',
          'href' => url('modules/rrhh/empleados.php')];
  }
  if (!empty($rrhh['tss_deuda']['total'])) {
      $ms = $rrhh['tss_deuda']['meses'];
      $pendientes[] = ['icono' => 'shield', 'color' => 'rose',
          'titulo' => money($rrhh['tss_deuda']['total']) . ' de TSS e ISR sin registrar',
          'texto' => 'De ' . implode(', ', $ms) . '. Son las retenciones de la gente más el aporte '
                   . 'patronal: mientras no se registre el pago, ese costo no aparece en el resultado.',
          'href' => url('modules/rrhh/tss.php?tab=pagos&mes=' . $ms[0])];
  }
  if (!empty($rrhh['por_pagar'])) {
      $n = (int) $rrhh['por_pagar'];
      $pendientes[] = ['icono' => 'clock', 'color' => 'blue',
          'titulo' => $n . ($n === 1 ? ' nómina sin pagar' : ' nóminas sin pagar'),
          'texto' => 'Quedan en borrador o procesadas, a la espera de marcarlas pagadas.',
          'href' => url('modules/rrhh/nomina.php')];
  }
  if (!empty($rrhh['vacaciones'])) {
      $n = (int) $rrhh['vacaciones'];
      $pendientes[] = ['icono' => 'calendar', 'color' => 'indigo',
          'titulo' => $n . ($n === 1 ? ' solicitud de vacaciones' : ' solicitudes de vacaciones'),
          'texto' => 'Esperando aprobación.',
          'href' => url('modules/rrhh/vacaciones.php')];
  }
  ?>
  <?php if ($pendientes): ?>
    <div class="grid grid-cols-1 lg:grid-cols-<?= min(3, count($pendientes)) ?> gap-4">
      <?php foreach ($pendientes as $pd): ?>
        <a href="<?= e($pd['href']) ?>"
           class="card p-4 flex items-start gap-3 hover:border-<?= $pd['color'] ?>-300 hover:shadow-pop transition">
          <?= icon($pd['icono'], 'w-5 h-5 text-' . $pd['color'] . '-500 mt-0.5 shrink-0') ?>
          <div class="min-w-0">
            <p class="font-semibold text-slate-800"><?= e($pd['titulo']) ?></p>
            <p class="text-sm text-slate-500 leading-snug mt-0.5"><?= $pd['texto'] ?></p>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php layout_end(); ?>
