<?php
/**
 * Cuentas por pagar: lo que le debes a tus proveedores.
 *
 * Es el espejo exacto de cuentas por cobrar, que ya existía. Antes una compra a
 * crédito solo podía estar «pagada» o «no pagada» (una fecha); no se podía
 * abonar RD$50.000 de una factura de RD$200.000, que es como se paga de verdad.
 *
 * Dos reglas de contabilidad que este archivo hace cumplir:
 *
 * 1. UNA COMPRA A CRÉDITO NO SACA DINERO DE LA CAJA EL DÍA DE LA COMPRA.
 *    Antes sí lo hacía: toda compra registraba un gasto de efectivo aunque fuera
 *    a 90 días, así que el flujo de caja mostraba salidas que no habían ocurrido.
 *    Ahora el movimiento de dinero se registra AL PAGAR, que es cuando sale.
 *
 * 2. LA DIFERENCIA CAMBIARIA ES UN RESULTADO, NO UN AJUSTE INVISIBLE.
 *    Si debes US$1.000 anotados a RD$58 y pagas cuando el dólar está a RD$60,
 *    esos RD$2.000 de más son una pérdida real del negocio. Se registran como
 *    gasto (o ingreso si el dólar bajó) y quedan a la vista.
 */

/** ¿Está aplicada la migración P11? Evita romper una base sin actualizar. */
function cxp_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'pagos_proveedores'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Compras con saldo pendiente de un proveedor, de la más vieja a la más nueva. */
function cxp_compras(int $proveedorId): array
{
    return qAll(
        "SELECT c.*, s.nombre AS sucursal
           FROM compras c
           JOIN sucursales s ON s.id = c.sucursal_id
          WHERE c.proveedor_id = ? AND c.estado <> 'anulada' AND c.saldo > 0.01
          ORDER BY c.fecha, c.id", [$proveedorId]
    );
}

/** Resumen de la deuda total, para las tarjetas de la pantalla. */
function cxp_resumen(): array
{
    $r = qOne(
        "SELECT COALESCE(SUM(c.saldo), 0) total,
                COUNT(DISTINCT c.proveedor_id) proveedores,
                COUNT(*) facturas,
                COALESCE(SUM(CASE WHEN c.fecha < (CURDATE() - INTERVAL 30 DAY) THEN c.saldo ELSE 0 END), 0) vencido
           FROM compras c
          WHERE c.estado <> 'anulada' AND c.saldo > 0.01"
    ) ?: [];

    return [
        'total'       => (float) ($r['total'] ?? 0),
        'proveedores' => (int) ($r['proveedores'] ?? 0),
        'facturas'    => (int) ($r['facturas'] ?? 0),
        'vencido'     => (float) ($r['vencido'] ?? 0),
    ];
}

/**
 * Antigüedad de la deuda por tramos. Es el reporte que de verdad se mira antes
 * de decidir a quién se le paga esta semana.
 */
function cxp_antiguedad(): array
{
    $r = qOne(
        "SELECT
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha) <= 30 THEN saldo ELSE 0 END), 0) t0,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha) BETWEEN 31 AND 60 THEN saldo ELSE 0 END), 0) t31,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha) BETWEEN 61 AND 90 THEN saldo ELSE 0 END), 0) t61,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha) > 90 THEN saldo ELSE 0 END), 0) t90
           FROM compras
          WHERE estado <> 'anulada' AND saldo > 0.01"
    ) ?: [];

    return [
        '0-30 días'   => (float) ($r['t0'] ?? 0),
        '31-60 días'  => (float) ($r['t31'] ?? 0),
        '61-90 días'  => (float) ($r['t61'] ?? 0),
        'Más de 90'   => (float) ($r['t90'] ?? 0),
    ];
}

/**
 * Registra un pago a un proveedor.
 *
 * Se aplica a una factura concreta o, si no se indica ninguna, a las más
 * antiguas primero (que es como se salda una cuenta corriente).
 *
 * @param array $in  proveedor_id, compra_id?, monto (en la moneda del pago),
 *                   moneda_id?, tasa?, metodo_pago_id, referencia?, notas?, fecha?
 * @return array ['pago_id','aplicado_base','diferencia','facturas'=>int]
 */
function cxp_registrarPago(array $in): array
{
    if (!cxp_disponible()) throw new RuntimeException('Falta aplicar la migración de cuentas por pagar.');

    $proveedorId = (int) ($in['proveedor_id'] ?? 0);
    $compraId    = (int) ($in['compra_id'] ?? 0) ?: null;
    $monto       = round((float) ($in['monto'] ?? 0), 2);
    $monedaId    = (int) ($in['moneda_id'] ?? 0) ?: (int) monedaBase()['id'];
    $tasa        = max(0.000001, (float) ($in['tasa'] ?? mon_tasa($monedaId)));
    $metodoId    = (int) ($in['metodo_pago_id'] ?? 0) ?: null;
    $referencia  = mb_substr(trim((string) ($in['referencia'] ?? '')), 0, 60) ?: null;
    $notas       = mb_substr(trim((string) ($in['notas'] ?? '')), 0, 255) ?: null;
    $fecha       = $in['fecha'] ?? date('Y-m-d H:i:s');

    if ($monto <= 0) throw new RuntimeException('El monto del pago debe ser mayor que cero.');

    $sid = current_sucursal_id();
    if ($sid === null) throw new RuntimeException('Selecciona una sucursal antes de registrar el pago.');

    // txReintentable, no tx: varias sucursales pueden pagar a la vez y un
    // interbloqueo de InnoDB no es un error del negocio.
    return txReintentable(function () use ($proveedorId, $compraId, $monto, $monedaId, $tasa,
                                           $metodoId, $referencia, $notas, $fecha, $sid) {

        $prov = qOne("SELECT id, nombre, balance FROM proveedores WHERE id = ? FOR UPDATE", [$proveedorId]);
        if (!$prov) throw new RuntimeException('Proveedor no válido.');

        $metodo = qOne("SELECT id, nombre, afecta_caja FROM metodos_pago WHERE id = ? AND activo = 1 AND es_credito = 0", [$metodoId]);
        if (!$metodo) throw new RuntimeException('Selecciona una forma de pago válida.');

        // Facturas a saldar. El bloqueo va SIEMPRE en orden de id: dos pagos
        // simultáneos al mismo proveedor no pueden cruzarse y trabarse.
        $sql = "SELECT id, numero, moneda_id, tasa_cambio, saldo, saldo_moneda
                  FROM compras
                 WHERE proveedor_id = ? AND estado <> 'anulada' AND saldo > 0.01 ";
        $par = [$proveedorId];
        if ($compraId) { $sql .= "AND id = ? "; $par[] = $compraId; }
        $sql .= "ORDER BY id FOR UPDATE";
        $facturas = qAll($sql, $par);

        if (!$facturas) throw new RuntimeException('Este proveedor no tiene facturas pendientes.');

        $baseId = (int) monedaBase()['id'];
        $pagoEsBase = $monedaId === $baseId;

        // Aquí está el nudo de todo el archivo.
        //
        // Una deuda en dólares NO es una deuda en pesos congelada. Si debes
        // US$600 anotados a RD$58 y pagas los US$600 cuando el dólar está a
        // RD$62, la deuda queda saldada por completo: pagaste lo que debías.
        // Lo que ocurre es que salieron RD$37.200 del banco para cancelar una
        // deuda que en libros valía RD$34.800. Esos RD$2.400 no son «pagar de
        // más», son una PÉRDIDA CAMBIARIA, y así hay que registrarlos.
        //
        // Por eso se llevan tres cifras distintas, que solo coinciden cuando no
        // hay moneda extranjera de por medio:
        //   · reduccion → cuánto baja la deuda en libros (a la tasa de la compra)
        //   · salida    → cuánto dinero sale del banco   (a la tasa de hoy)
        //   · diferencia = salida − reduccion
        $restanteMoneda = $monto;                    // en la moneda del pago
        $restanteBase   = mon_aBase($monto, $tasa);  // pesos disponibles hoy

        $reduccionTotal = 0.0;   // baja de la deuda
        $salidaTotal    = 0.0;   // sale del banco
        $diferencia     = 0.0;
        $afectadas      = 0;

        foreach ($facturas as $f) {
            if ($restanteBase <= 0.009) break;

            $saldoBase   = (float) $f['saldo'];
            $saldoMoneda = (float) $f['saldo_moneda'];
            $tasaFactura = max(0.000001, (float) $f['tasa_cambio']);
            $facturaBase = (int) $f['moneda_id'] === $baseId || !$f['moneda_id'];

            if ($facturaBase) {
                // Deuda en pesos: no hay exposición al tipo de cambio.
                $reduccion  = min($restanteBase, $saldoBase);
                $salida     = $reduccion;
                $aplicarMon = $reduccion;
                $dif        = 0.0;
            } else {
                // Deuda en moneda extranjera. Se salda en ESA moneda.
                // Si el pago viene en pesos, se convierte a la tasa de hoy de la
                // moneda de la factura.
                $tasaHoy  = $pagoEsBase ? mon_tasa((int) $f['moneda_id']) : $tasa;
                $puedeMon = $pagoEsBase ? ($restanteBase / $tasaHoy) : $restanteMoneda;

                $aplicarMon = round(min($puedeMon, $saldoMoneda), 2);
                if ($aplicarMon <= 0) continue;

                $reduccion = round($aplicarMon * $tasaFactura, 2);   // a la tasa de la compra
                $salida    = round($aplicarMon * $tasaHoy, 2);       // a la tasa de hoy
                $dif       = round($salida - $reduccion, 2);
            }

            q("UPDATE compras
                  SET saldo = GREATEST(0, saldo - ?),
                      saldo_moneda = GREATEST(0, saldo_moneda - ?),
                      fecha_pago = CASE WHEN saldo - ? <= 0.01 THEN COALESCE(fecha_pago, ?) ELSE fecha_pago END
                WHERE id = ?",
              [$reduccion, $aplicarMon, $reduccion, date('Y-m-d', strtotime($fecha)), (int) $f['id']]);

            $reduccionTotal += $reduccion;
            $salidaTotal    += $salida;
            $diferencia     += $dif;
            $restanteBase    = round($restanteBase - $salida, 2);
            $restanteMoneda  = round($restanteMoneda - ($pagoEsBase ? $salida : $aplicarMon), 2);
            $afectadas++;
        }

        $reduccionTotal = round($reduccionTotal, 2);
        $salidaTotal    = round($salidaTotal, 2);
        $diferencia     = round($diferencia, 2);

        if ($reduccionTotal <= 0) throw new RuntimeException('El pago no se pudo aplicar a ninguna factura.');
        if ($restanteBase > 0.009) {
            throw new RuntimeException(
                'El pago supera lo que se le debe a este proveedor. Sobran ' . money($restanteBase)
                . '. Ajusta el monto: la deuda pendiente cubierta por este pago es ' . money($salidaTotal) . '.');
        }

        // Saldo del proveedor: siempre con UPDATE relativo, nunca leyendo y
        // escribiendo desde PHP (se perderían pagos simultáneos).
        q("UPDATE proveedores SET balance = GREATEST(0, balance - ?) WHERE id = ?", [$reduccionTotal, $proveedorId]);

        $aplicado = $salidaTotal;   // lo que de verdad salió del banco

        $pagoId = dbInsert('pagos_proveedores', [
            'proveedor_id'   => $proveedorId,
            'compra_id'      => $compraId,
            'sucursal_id'    => $sid,
            'monto'          => $aplicado,
            'moneda_id'      => $monedaId,
            'monto_moneda'   => $monto,
            'tasa_cambio'    => $tasa,
            'diferencia_cambiaria' => round($diferencia, 2),
            'metodo_pago_id' => $metodoId,
            'referencia'     => $referencia,
            'notas'          => $notas,
            'usuario_id'     => (int) current_user()['id'],
            'fecha'          => $fecha,
        ]);

        // AQUÍ sale el dinero, no el día de la compra.
        $tipoCuenta = (int) $metodo['afecta_caja'] === 1 ? 'efectivo' : 'banco';
        registrarTransaccion('gasto', $aplicado, [
            'sucursal_id'     => $sid,
            'cuenta_id'       => cuentaFinancieraIdPorTipo($tipoCuenta, $sid),
            'categoria_id'    => categoriaFinancieraId('gasto', 'Pago a Proveedores'),
            'descripcion'     => 'Pago a ' . $prov['nombre'] . ($referencia ? ' · ' . $referencia : ''),
            'referencia_tipo' => 'pago_proveedor',
            'referencia_id'   => $pagoId,
            'fecha'           => $fecha,
        ]);

        // La diferencia cambiaria no mueve efectivo: es resultado puro. Por eso
        // va SIN cuenta_id, igual que la depreciación.
        if (abs($diferencia) >= 0.01) {
            registrarTransaccion($diferencia > 0 ? 'gasto' : 'ingreso', abs(round($diferencia, 2)), [
                'sucursal_id'     => $sid,
                'cuenta_id'       => null,
                'categoria_id'    => categoriaFinancieraId($diferencia > 0 ? 'gasto' : 'ingreso', 'Diferencia Cambiaria'),
                'descripcion'     => 'Diferencia cambiaria · pago a ' . $prov['nombre'],
                'referencia_tipo' => 'diferencia_cambiaria',
                'referencia_id'   => $pagoId,
                'fecha'           => $fecha,
            ]);
        }

        // Si el pago salió del cajón, el cuadre tiene que enterarse.
        //
        // Antes esto era «y si hay sesión abierta»: sin caja abierta el egreso se
        // saltaba en silencio, el dinero salía igual —la cuenta de efectivo sí se
        // descontaba— y al cerrar aparecía un faltante que nadie había causado.
        //
        // No se bloquea el pago: hay quien paga en efectivo desde la oficina, sin
        // cajón de por medio. Pero se devuelve la señal para poder avisarlo con la
        // cifra delante, en vez de callarse.
        $sinCaja = false;
        if ((int) $metodo['afecta_caja'] === 1) {
            $sesion = cajaSesionAbierta($sid, (int) current_user()['id']);
            if ($sesion) {
                dbInsert('caja_movimientos', [
                    'caja_sesion_id' => (int) $sesion['id'],
                    'tipo'           => 'egreso',
                    'concepto'       => 'Pago a ' . $prov['nombre'] . ' #' . $pagoId,
                    'monto'          => $aplicado,
                    'usuario_id'     => (int) current_user()['id'],
                    'created_at'     => $fecha,
                ]);
            } else {
                $sinCaja = true;
            }
        }

        return [
            'pago_id'       => $pagoId,
            'aplicado_base' => $aplicado,        // salió del banco
            'reduccion'     => $reduccionTotal,  // bajó la deuda en libros
            'diferencia'    => $diferencia,      // + pérdida cambiaria, − ganancia
            'facturas'      => $afectadas,
            'sin_caja'      => $sinCaja,   // salió en efectivo sin arqueo donde anotarlo
        ];
    });
}

/*
 * Aquí vivía `cxp_recalcularBalance()`, que reescribía el balance del proveedor
 * con la suma de sus compras. Su comentario decía que la usaban la verificación
 * de integridad y la anulación de una compra: no la llamaba nadie. La anulación
 * hace `balance = balance - saldo`, que es lo correcto, y la comprobación vive
 * ahora en Integridad de datos, que solo lee.
 *
 * Se retiró porque escribía el saldo en ABSOLUTO: leer, sumar en PHP y escribir
 * pierde cualquier pago que ocurra en medio (convención 4). Dejarla ahí, muerta
 * y con el nombre que invita a usarla, era un pie de banco esperando a alguien.
 */

/** Historial de pagos de un proveedor. */
function cxp_pagos(int $proveedorId, int $limite = 50): array
{
    return qAll(
        "SELECT p.*, m.nombre AS metodo, mo.codigo AS moneda_codigo, mo.simbolo AS moneda_simbolo,
                c.numero AS compra_numero, u.nombre AS usuario
           FROM pagos_proveedores p
           LEFT JOIN metodos_pago m ON m.id = p.metodo_pago_id
           LEFT JOIN monedas mo     ON mo.id = p.moneda_id
           LEFT JOIN compras c      ON c.id = p.compra_id
           LEFT JOIN usuarios u     ON u.id = p.usuario_id
          WHERE p.proveedor_id = ?
          ORDER BY p.id DESC
          LIMIT " . max(1, min(500, $limite)),
        [$proveedorId]
    );
}
