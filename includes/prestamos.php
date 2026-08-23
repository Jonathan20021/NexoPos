<?php
/**
 * Préstamos y avances a empleados, con descuento por nómina.
 *
 * ============================================================================
 *  LO QUE PROTEGE ESTE ARCHIVO
 * ============================================================================
 *
 * El Código de Trabajo protege el salario. Las retenciones obligatorias —TSS e
 * ISR— van primero, y lo voluntario solo puede salir de lo que queda. Además
 * hace falta el consentimiento escrito del trabajador para retenerle algo que
 * no sea obligatorio.
 *
 * Aquí eso se traduce en tres cosas concretas:
 *
 *   1. El tope se mide sobre el NETO, no sobre el bruto. Descontar el 30% de un
 *      bruto puede dejar a alguien sin nada después de la TSS y el ISR.
 *   2. Un préstamo sin autorización registrada NO descuenta.
 *   3. Pasarse del tope no está prohibido, pero exige que alguien escriba por
 *      qué, y queda marcado en el expediente.
 *
 * El porcentaje NO está escrito en el código: cambia con la interpretación
 * legal y el cliente tiene que poder ajustarlo con su abogado.
 */

/** Configuración del límite legal. Una sola fila. */
function presConfig(): array
{
    static $c = null;
    if ($c !== null) return $c;
    try {
        $c = qOne("SELECT * FROM prestamo_config ORDER BY id LIMIT 1") ?: [];
    } catch (Throwable $e) { $c = []; }
    return $c = $c + ['tope_pct_neto' => 30.0, 'neto_minimo_protegido' => 0.0, 'exige_autorizacion' => 1];
}

/** ¿Está instalado el módulo? */
function presDisponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { qVal("SELECT 1 FROM prestamos LIMIT 1"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

function presTipos(): array      { return ['prestamo' => 'Préstamo', 'avance' => 'Avance de sueldo']; }
function presPeriodicidades(): array { return ['quincenal' => 'Quincenal', 'mensual' => 'Mensual']; }
function presEstados(): array
{
    return ['activo' => ['Activo', 'sky'], 'saldado' => ['Saldado', 'emerald'], 'anulado' => ['Anulado', 'slate']];
}

/**
 * Cuadro de amortización francés: cuota constante.
 *
 * Con tasa 0 —que es el caso normal de un préstamo de empresa— se reparte el
 * capital en partes iguales. El AJUSTE DEL ÚLTIMO PAGO no es cosmético: sin él,
 * 10,000 en 3 cuotas deja 0.01 de saldo vivo para siempre y el préstamo nunca
 * llega a «saldado».
 *
 * @return array<int, array{numero:int,fecha:string,capital:float,interes:float,total:float,saldo:float}>
 */
function presAmortizar(float $monto, int $cuotas, float $tasaAnual, string $periodicidad, string $primeraCuota): array
{
    $monto  = round(max(0.0, $monto), 2);
    $cuotas = max(1, $cuotas);
    $porAnio = $periodicidad === 'mensual' ? 12 : 24;
    $i = $tasaAnual > 0 ? ($tasaAnual / 100) / $porAnio : 0.0;

    $cuotaFija = $i > 0
        ? round($monto * $i / (1 - pow(1 + $i, -$cuotas)), 2)
        : round($monto / $cuotas, 2);

    $plan = [];
    $saldo = $monto;
    $fecha = $primeraCuota;
    for ($n = 1; $n <= $cuotas; $n++) {
        $interes = $i > 0 ? round($saldo * $i, 2) : 0.0;
        $capital = round($cuotaFija - $interes, 2);
        // Última cuota: se lleva lo que quede, céntimos de redondeo incluidos.
        if ($n === $cuotas || $capital > $saldo) {
            $capital = round($saldo, 2);
            $cuotaFija = round($capital + $interes, 2);
        }
        $saldo = round($saldo - $capital, 2);
        $plan[] = [
            'numero'  => $n,
            'fecha'   => $fecha,
            'capital' => $capital,
            'interes' => $interes,
            'total'   => round($capital + $interes, 2),
            'saldo'   => $saldo,
        ];
        $fecha = $periodicidad === 'mensual'
            ? date('Y-m-d', strtotime($fecha . ' +1 month'))
            : date('Y-m-d', strtotime($fecha . ' +15 days'));
    }
    return $plan;
}

/**
 * ¿Cabe esta cuota en el salario de esta persona sin pasarse del tope legal?
 *
 * Se calcula sobre el NETO del período —después de TSS e ISR—, no sobre el
 * bruto. Devuelve siempre el detalle, tenga o no problema, para que la pantalla
 * pueda enseñar el número y no solo un «no se puede».
 */
function presCabeLegal(int $empleadoId, float $cuota, string $periodicidad = 'quincenal'): array
{
    $cfg = presConfig();
    $e = qOne("SELECT salario FROM empleados WHERE id = ?", [$empleadoId]);
    $salario = (float) ($e['salario'] ?? 0);
    $factor  = $periodicidad === 'mensual' ? 1.0 : 0.5;
    $diasBase = nominaDiasBase($periodicidad === 'mensual' ? 'mensual' : 'quincenal');

    $c = calcNominaRD($salario, ['dias_base' => $diasBase, 'dias_trabajados' => $diasBase], $factor);
    // El neto que devuelve calcNominaRD ya trae descontado lo obligatorio.
    $neto = (float) $c['neto'];

    // Lo que ya se le está descontando por OTROS préstamos vivos del período.
    $yaComprometido = presCuotaDelPeriodo($empleadoId, date('Y-m-d'), date('Y-m-d', strtotime('+15 days')));

    $tope = round($neto * ((float) $cfg['tope_pct_neto'] / 100), 2);
    $piso = (float) $cfg['neto_minimo_protegido'];
    $netoTrasCuota = round($neto - $yaComprometido - $cuota, 2);

    $cabe = ($cuota + $yaComprometido) <= $tope && ($piso <= 0 || $netoTrasCuota >= $piso);

    return [
        'cabe'            => $cabe,
        'neto'            => $neto,
        'tope_pct'        => (float) $cfg['tope_pct_neto'],
        'tope_monto'      => $tope,
        'ya_comprometido' => $yaComprometido,
        'cuota'           => round($cuota, 2),
        'neto_resultante' => $netoTrasCuota,
        'piso'            => $piso,
        'exceso'          => max(0.0, round(($cuota + $yaComprometido) - $tope, 2)),
    ];
}

/**
 * Cuotas que le tocan a una persona dentro de un período de nómina.
 *
 * Es lo que la nómina descuenta sola. Solo cuenta lo PENDIENTE y de préstamos
 * activos y autorizados: un préstamo sin firma no retiene nada.
 */
function presCuotaDelPeriodo(int $empleadoId, string $desde, string $hasta): float
{
    if (!presDisponible()) return 0.0;
    $cfg = presConfig();
    $exige = (int) $cfg['exige_autorizacion'] === 1 ? ' AND p.autorizado = 1' : '';
    return round((float) qVal(
        "SELECT COALESCE(SUM(c.total), 0) FROM prestamo_cuotas c
           JOIN prestamos p ON p.id = c.prestamo_id
          WHERE p.empleado_id = ? AND p.estado = 'activo' $exige
            AND c.estado = 'pendiente' AND c.fecha_prevista BETWEEN ? AND ?",
        [$empleadoId, $desde, $hasta]
    ), 2);
}

/** Las cuotas concretas del período, para poder marcarlas después. */
function presCuotasDelPeriodo(int $empleadoId, string $desde, string $hasta): array
{
    if (!presDisponible()) return [];
    $cfg = presConfig();
    $exige = (int) $cfg['exige_autorizacion'] === 1 ? ' AND p.autorizado = 1' : '';
    return qAll(
        "SELECT c.*, p.numero AS prestamo_numero FROM prestamo_cuotas c
           JOIN prestamos p ON p.id = c.prestamo_id
          WHERE p.empleado_id = ? AND p.estado = 'activo' $exige
            AND c.estado = 'pendiente' AND c.fecha_prevista BETWEEN ? AND ?
          ORDER BY c.fecha_prevista, c.id",
        [$empleadoId, $desde, $hasta]
    );
}

/**
 * Marca como descontadas las cuotas que cobró una nómina, y salda el préstamo
 * si ya no queda nada.
 *
 * Se llama al CONFIRMAR la nómina, no al generarla: un borrador se puede
 * borrar, y una cuota marcada como cobrada por un borrador que nunca se pagó
 * sería una deuda que se esfuma sin que nadie pagara nada.
 */
function presAplicarCobro(int $empleadoId, int $nominaDetalleId, string $desde, string $hasta, float $montoCobrado): int
{
    if (!presDisponible() || $montoCobrado <= 0) return 0;
    $n = 0;
    $restante = round($montoCobrado, 2);
    foreach (presCuotasDelPeriodo($empleadoId, $desde, $hasta) as $c) {
        if ($restante < 0.01) break;
        // Si se descontó menos de lo previsto, no se da la cuota por cobrada:
        // mejor que quede pendiente a que desaparezca sin haberse pagado.
        if ($restante + 0.01 < (float) $c['total']) break;
        dbUpdate('prestamo_cuotas', [
            'estado' => 'descontada',
            'nomina_detalle_id' => $nominaDetalleId,
            'descontada_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $c['id']]);
        $restante = round($restante - (float) $c['total'], 2);
        $n++;
        presRecalcularSaldo((int) $c['prestamo_id']);
    }
    return $n;
}

/** Recalcula el saldo vivo desde las cuotas y salda el préstamo si procede. */
function presRecalcularSaldo(int $prestamoId): void
{
    $pend = (float) qVal("SELECT COALESCE(SUM(capital),0) FROM prestamo_cuotas
                           WHERE prestamo_id = ? AND estado = 'pendiente'", [$prestamoId]);
    $p = qOne("SELECT estado FROM prestamos WHERE id = ?", [$prestamoId]);
    $datos = ['saldo' => round($pend, 2)];
    if ($pend < 0.01 && ($p['estado'] ?? '') === 'activo') $datos['estado'] = 'saldado';
    dbUpdate('prestamos', $datos, 'id = ?', [$prestamoId]);
}

/** Resumen para tarjetas y para la ficha del empleado. */
function presResumen(?int $empleadoId = null): array
{
    if (!presDisponible()) return ['activos' => 0, 'saldo' => 0.0, 'cuota_mes' => 0.0, 'atrasadas' => 0, 'sin_autorizar' => 0];
    $w = $empleadoId ? ' AND p.empleado_id = ' . (int) $empleadoId : '';
    $r = qOne(
        "SELECT COALESCE(SUM(p.estado='activo'),0) activos,
                COALESCE(SUM(CASE WHEN p.estado='activo' THEN p.saldo ELSE 0 END),0) saldo,
                COALESCE(SUM(p.estado='activo' AND p.autorizado=0),0) sin_autorizar
           FROM prestamos p WHERE 1=1 $w");
    $mes = qOne(
        "SELECT COALESCE(SUM(c.total),0) cuota_mes,
                COALESCE(SUM(c.fecha_prevista < CURDATE()),0) atrasadas
           FROM prestamo_cuotas c JOIN prestamos p ON p.id=c.prestamo_id
          WHERE c.estado='pendiente' AND p.estado='activo'
            AND c.fecha_prevista <= LAST_DAY(CURDATE()) $w");
    return [
        'activos'       => (int) $r['activos'],
        'saldo'         => round((float) $r['saldo'], 2),
        'sin_autorizar' => (int) $r['sin_autorizar'],
        'cuota_mes'     => round((float) $mes['cuota_mes'], 2),
        'atrasadas'     => (int) $mes['atrasadas'],
    ];
}
