<?php
/**
 * Reservas de NCF por terminal — Modo offline del POS, Fase 2.
 * ---------------------------------------------------------------------------
 * Permite que el POS imprima el comprobante fiscal DEFINITIVO (con NCF real)
 * aunque esté sin internet. Cada terminal reserva por adelantado rangos de NCF
 * tallados del maestro `ncf_secuencias`; estando offline, el navegador toma un
 * número de su reserva y, al sincronizar, el servidor valida que ese NCF
 * pertenece a una reserva del terminal y registra la venta con él.
 *
 * Garantías fiscales:
 *  - El rango se talla bajo bloqueo (FOR UPDATE) y el maestro salta por encima:
 *    dos terminales nunca reciben el mismo número, y online/offline no se solapan.
 *  - El camino ONLINE no cambia: sigue usando siguienteNCF() sobre el maestro.
 *  - `ventas.ncf` es UNIQUE: red de seguridad última contra cualquier duplicado.
 */

/** Formatea un NCF: tipo (B01/B02) + 8 dígitos. Igual que siguienteNCF(). */
function ncfFormatear(string $tipo, int $seq): string
{
    return $tipo . str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
}

/** Descompone un NCF "B0200000010" en ['tipo'=>'B02','seq'=>10]. null si no calza. */
function ncfPartes(string $ncf): ?array
{
    if (!preg_match('/^([A-Z]\d{2})(\d{8})$/', $ncf, $m)) return null;
    return ['tipo' => $m[1], 'seq' => (int) $m[2]];
}

/**
 * Registra/actualiza un terminal por su token de dispositivo (generado en el
 * navegador y guardado en localStorage). Devuelve la fila del terminal.
 */
function terminalUpsert(string $token, ?int $sid, ?string $nombre = null): array
{
    if (!preg_match('/^[a-f0-9-]{16,40}$/i', $token)) {
        throw new RuntimeException('Token de terminal inválido.');
    }
    $existe = qOne("SELECT * FROM pos_terminales WHERE device_token = ?", [$token]);
    if ($existe) {
        q("UPDATE pos_terminales SET ultimo_visto = NOW(), sucursal_id = COALESCE(?, sucursal_id), activo = 1 WHERE id = ?",
          [$sid, (int) $existe['id']]);
        return qOne("SELECT * FROM pos_terminales WHERE id = ?", [(int) $existe['id']]);
    }
    $nombre = $nombre ?: ('Terminal ' . strtoupper(substr($token, 0, 4)));
    $id = dbInsert('pos_terminales', [
        'device_token' => $token, 'nombre' => $nombre, 'sucursal_id' => $sid,
        'ultimo_visto' => date('Y-m-d H:i:s'), 'activo' => 1,
    ]);
    return qOne("SELECT * FROM pos_terminales WHERE id = ?", [$id]);
}

/**
 * Talla un rango de hasta $cantidad NCF del maestro y lo delega a un terminal.
 * Devuelve ['ncfs'=>[...cadenas...], 'reserva_id'=>int, 'vencimiento'=>?string].
 * Si el maestro no tiene números disponibles/vigentes, 'ncfs' viene vacío.
 */
function reservarNCF(int $terminalId, string $tipo, int $cantidad): array
{
    $cantidad = max(0, min(500, $cantidad)); // techo defensivo por llamada
    if ($cantidad === 0) return ['ncfs' => [], 'reserva_id' => 0, 'vencimiento' => null];

    return tx(function () use ($terminalId, $tipo, $cantidad) {
        $seq = qOne("SELECT * FROM ncf_secuencias WHERE tipo = ? AND activo = 1 FOR UPDATE", [$tipo]);
        if (!$seq) return ['ncfs' => [], 'reserva_id' => 0, 'vencimiento' => null];
        if (!empty($seq['vencimiento']) && $seq['vencimiento'] < date('Y-m-d')) {
            return ['ncfs' => [], 'reserva_id' => 0, 'vencimiento' => null];
        }
        $desde = (int) $seq['secuencia_actual'];
        $topeMaestro = (int) $seq['secuencia_hasta'];
        if ($desde > $topeMaestro) return ['ncfs' => [], 'reserva_id' => 0, 'vencimiento' => null];

        $hasta = min($desde + $cantidad - 1, $topeMaestro);
        // El maestro salta por encima del rango tallado: online nunca reusará estos.
        q("UPDATE ncf_secuencias SET secuencia_actual = ? WHERE id = ?", [$hasta + 1, (int) $seq['id']]);

        $reservaId = dbInsert('ncf_reservas', [
            'terminal_id' => $terminalId, 'secuencia_id' => (int) $seq['id'], 'tipo' => $tipo,
            'prefijo' => $seq['prefijo'], 'secuencia_desde' => $desde, 'secuencia_hasta' => $hasta,
            'vencimiento' => $seq['vencimiento'], 'estado' => 'activa',
        ]);

        $ncfs = [];
        for ($n = $desde; $n <= $hasta; $n++) $ncfs[] = ncfFormatear($tipo, $n);
        return ['ncfs' => $ncfs, 'reserva_id' => $reservaId, 'vencimiento' => $seq['vencimiento']];
    });
}

/**
 * ¿El NCF pertenece a una reserva ACTIVA de este terminal? Devuelve la fila de la
 * reserva o null. Se usa al sincronizar una venta offline para validar el NCF que
 * el navegador ya imprimió.
 */
function ncfReservaDeTerminal(int $terminalId, string $ncf): ?array
{
    $p = ncfPartes($ncf);
    if (!$p) return null;
    return qOne(
        "SELECT * FROM ncf_reservas
          WHERE terminal_id = ? AND tipo = ? AND estado = 'activa'
            AND ? BETWEEN secuencia_desde AND secuencia_hasta
          LIMIT 1",
        [$terminalId, $p['tipo'], $p['seq']]
    );
}

/** Tipo de comprobante ('consumidor'|'credito_fiscal') -> tipo de NCF (B02|B01). */
function ncfTipoDeComprobante(string $comprobante): string
{
    return $comprobante === 'credito_fiscal' ? 'B01' : 'B02';
}

/**
 * Cuántos NCF de una reserva ya se emitieron (aparecen en ventas). El resto del
 * rango son números aún disponibles para el terminal (o huecos si la reserva se
 * cierra sin usarlos).
 */
function reservaEmitidos(array $reserva): int
{
    return (int) qVal(
        "SELECT COUNT(*) FROM ventas
          WHERE ncf IS NOT NULL AND ncf BETWEEN ? AND ?",
        [ncfFormatear($reserva['tipo'], (int) $reserva['secuencia_desde']),
         ncfFormatear($reserva['tipo'], (int) $reserva['secuencia_hasta'])]
    );
}

/**
 * Cierra una reserva (deja de entregar sus números). Si su tramo final no usado
 * es contiguo con la cabeza del maestro, se lo devuelve para no dejar huecos;
 * si no, los no usados quedan como hueco permanente (la DGII lo admite).
 * Devuelve ['devueltos'=>int, 'huecos'=>int].
 */
function devolverReserva(int $reservaId): array
{
    return tx(function () use ($reservaId) {
        $r = qOne("SELECT * FROM ncf_reservas WHERE id = ? AND estado = 'activa' FOR UPDATE", [$reservaId]);
        if (!$r) return ['devueltos' => 0, 'huecos' => 0];

        $desde = (int) $r['secuencia_desde'];
        $hasta = (int) $r['secuencia_hasta'];
        // El primer número NO emitido del rango: los emitidos son un prefijo contiguo
        // porque el navegador entrega en orden. Buscamos el mayor emitido.
        $maxEmitido = (int) qVal(
            "SELECT MAX(CAST(SUBSTRING(ncf,4) AS UNSIGNED)) FROM ventas
              WHERE ncf BETWEEN ? AND ?",
            [ncfFormatear($r['tipo'], $desde), ncfFormatear($r['tipo'], $hasta)]
        );
        $primerLibre = $maxEmitido > 0 ? $maxEmitido + 1 : $desde;
        $noUsados = max(0, $hasta - $primerLibre + 1);

        $devueltos = 0;
        if ($noUsados > 0) {
            $seq = qOne("SELECT * FROM ncf_secuencias WHERE id = ? FOR UPDATE", [(int) $r['secuencia_id']]);
            // Solo se puede devolver si el tramo libre es contiguo con la cabeza del maestro.
            if ($seq && (int) $seq['secuencia_actual'] === $hasta + 1) {
                q("UPDATE ncf_secuencias SET secuencia_actual = ? WHERE id = ?", [$primerLibre, (int) $seq['id']]);
                $devueltos = $noUsados;
                $noUsados = 0;
            }
        }
        q("UPDATE ncf_reservas SET estado = 'devuelta' WHERE id = ?", [$reservaId]);
        return ['devueltos' => $devueltos, 'huecos' => $noUsados];
    });
}

/** Terminales con su resumen de reservas (para la pantalla de administración). */
function terminalesResumen(): array
{
    $terminales = qAll(
        "SELECT t.*, s.nombre AS sucursal
           FROM pos_terminales t
           LEFT JOIN sucursales s ON s.id = t.sucursal_id
          ORDER BY t.activo DESC, t.ultimo_visto DESC, t.id"
    );
    foreach ($terminales as &$t) {
        $reservas = qAll(
            "SELECT * FROM ncf_reservas WHERE terminal_id = ? ORDER BY tipo, secuencia_desde",
            [(int) $t['id']]
        );
        $disp = ['B01' => 0, 'B02' => 0];
        foreach ($reservas as &$r) {
            $total = (int) $r['secuencia_hasta'] - (int) $r['secuencia_desde'] + 1;
            $emit  = reservaEmitidos($r);
            $r['total'] = $total;
            $r['emitidos'] = $emit;
            $r['disponibles'] = $r['estado'] === 'activa' ? max(0, $total - $emit) : 0;
            if ($r['estado'] === 'activa' && isset($disp[$r['tipo']])) {
                $disp[$r['tipo']] += $r['disponibles'];
            }
        }
        unset($r);
        $t['reservas'] = $reservas;
        $t['disp_b01'] = $disp['B01'];
        $t['disp_b02'] = $disp['B02'];
    }
    unset($t);
    return $terminales;
}
