<?php
/**
 * Conciliación bancaria: cruza los movimientos del sistema contra el estado de
 * cuenta que emite el banco.
 *
 * Solo aplica a cuentas con estado de cuenta (banco / tarjeta / transferencia).
 * El EFECTIVO no se concilia aquí: su arqueo es el cierre de caja, que ya existe
 * y cuenta el dinero físico. Mezclarlos sería duplicar esa lógica.
 *
 * La aritmética es la clásica: al saldo del banco se le suma lo que el banco
 * todavía no ha acreditado y se le resta lo que todavía no ha debitado; el
 * resultado debe ser idéntico al saldo en libros. Si no lo es, falta registrar
 * algo (o sobra), y esa diferencia es justamente lo que hay que investigar.
 */

/** Cuentas conciliables. El efectivo queda fuera a propósito (ver arriba). */
function conciliacionCuentas(): array
{
    return qAll(
        "SELECT c.*, s.nombre AS sucursal
           FROM cuentas_financieras c
           LEFT JOIN sucursales s ON s.id = c.sucursal_id
          WHERE c.activo = 1 AND c.tipo IN ('banco','tarjeta','transferencia')
          ORDER BY c.nombre"
    );
}

/**
 * Saldo en libros de la cuenta A UNA FECHA.
 *
 * Se recalcula desde `saldo_inicial` + movimientos hasta el corte. NO se usa
 * `cuentas_financieras.balance`: ese es el saldo de hoy, y una conciliación
 * siempre es a una fecha pasada.
 */
function conciliacionSaldoLibros(int $cuentaId, string $fechaCorte): float
{
    $ini = (float) qVal("SELECT saldo_inicial FROM cuentas_financieras WHERE id = ?", [$cuentaId]);
    $mov = (float) qVal(
        "SELECT COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0)
           FROM transacciones WHERE cuenta_id = ? AND fecha <= ?",
        [$cuentaId, $fechaCorte]
    );
    return round($ini + $mov, 2);
}

/** Movimientos de la cuenta hasta el corte, con su estado de conciliación. */
function conciliacionMovimientos(int $cuentaId, string $fechaCorte): array
{
    return qAll(
        "SELECT t.*, cf.nombre AS categoria, u.nombre AS usuario
           FROM transacciones t
           LEFT JOIN categorias_financieras cf ON cf.id = t.categoria_id
           LEFT JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.cuenta_id = ? AND t.fecha <= ?
          ORDER BY t.fecha DESC, t.id DESC",
        [$cuentaId, $fechaCorte]
    );
}

/**
 * Resumen de la conciliación a la fecha de corte.
 *
 * En tránsito = lo que ya está en libros pero el banco todavía no refleja, es
 * decir, los movimientos aún NO marcados como conciliados.
 */
function conciliacionResumen(int $cuentaId, string $fechaCorte, float $saldoBanco): array
{
    $libros = conciliacionSaldoLibros($cuentaId, $fechaCorte);
    $t = qOne(
        "SELECT
            COALESCE(SUM(CASE WHEN conciliada = 0 AND tipo = 'ingreso' THEN monto ELSE 0 END), 0) AS ti,
            COALESCE(SUM(CASE WHEN conciliada = 0 AND tipo = 'gasto'   THEN monto ELSE 0 END), 0) AS tg,
            COUNT(CASE WHEN conciliada = 0 THEN 1 END) AS pendientes,
            COUNT(CASE WHEN conciliada = 1 THEN 1 END) AS conciliadas
           FROM transacciones WHERE cuenta_id = ? AND fecha <= ?",
        [$cuentaId, $fechaCorte]
    );
    $ti = (float) $t['ti'];
    $tg = (float) $t['tg'];
    $ajustado = round($saldoBanco + $ti - $tg, 2);

    return [
        'saldo_libros'      => $libros,
        'saldo_banco'       => round($saldoBanco, 2),
        'transito_ingresos' => $ti,
        'transito_gastos'   => $tg,
        'saldo_ajustado'    => $ajustado,
        'diferencia'        => round($ajustado - $libros, 2),
        'pendientes'        => (int) $t['pendientes'],
        'conciliadas'       => (int) $t['conciliadas'],
        'cuadra'            => abs(round($ajustado - $libros, 2)) < 0.01,
    ];
}

/**
 * Cierra el corte: deja constancia del cuadre y amarra los movimientos marcados
 * a esta conciliación, con lo que quedan bloqueados (ya no se pueden desmarcar).
 *
 * Solo se cierra si la diferencia es cero: una conciliación que no cuadra no
 * está conciliada, y cerrarla escondería el problema que hay que investigar.
 */
function conciliacionCerrar(int $cuentaId, string $fechaCorte, float $saldoBanco, ?string $notas = null): int
{
    return tx(function () use ($cuentaId, $fechaCorte, $saldoBanco, $notas) {
        $r = conciliacionResumen($cuentaId, $fechaCorte, $saldoBanco);
        if (!$r['cuadra']) {
            throw new RuntimeException('La conciliación no cuadra: hay una diferencia de ' . money($r['diferencia']) . '. Revisa los movimientos antes de cerrar.');
        }
        if (qVal("SELECT 1 FROM conciliaciones WHERE cuenta_id = ? AND fecha_corte = ?", [$cuentaId, $fechaCorte])) {
            throw new RuntimeException('Ya existe una conciliación cerrada para esa cuenta y fecha de corte.');
        }

        $id = dbInsert('conciliaciones', [
            'cuenta_id'         => $cuentaId,
            'fecha_corte'       => $fechaCorte,
            'saldo_banco'       => $r['saldo_banco'],
            'saldo_libros'      => $r['saldo_libros'],
            'transito_ingresos' => $r['transito_ingresos'],
            'transito_gastos'   => $r['transito_gastos'],
            'diferencia'        => $r['diferencia'],
            'estado'            => 'cerrada',
            'notas'             => $notas ?: null,
            'usuario_id'        => current_user()['id'] ?? null,
        ]);

        // Solo se amarran las que aún no pertenecen a otro corte.
        q("UPDATE transacciones SET conciliacion_id = ?
            WHERE cuenta_id = ? AND fecha <= ? AND conciliada = 1 AND conciliacion_id IS NULL",
          [$id, $cuentaId, $fechaCorte]);

        return $id;
    });
}

/** Historial de cortes cerrados de una cuenta. */
function conciliacionHistorial(int $cuentaId, int $limite = 12): array
{
    return qAll(
        "SELECT c.*, u.nombre AS usuario,
                (SELECT COUNT(*) FROM transacciones t WHERE t.conciliacion_id = c.id) AS movimientos
           FROM conciliaciones c
           LEFT JOIN usuarios u ON u.id = c.usuario_id
          WHERE c.cuenta_id = ?
          ORDER BY c.fecha_corte DESC
          LIMIT " . (int) $limite,
        [$cuentaId]
    );
}
