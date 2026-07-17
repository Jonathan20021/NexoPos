<?php
/**
 * Operaciones de negocio compartidas y atómicas.
 * Centralizan la lógica crítica para garantizar consistencia en todos los módulos.
 * IMPORTANTE: estas funciones deben llamarse DENTRO de una transacción (tx()).
 */

/**
 * Ajusta el stock de un producto en una sucursal y registra el movimiento (kardex).
 * $delta positivo = entrada, negativo = salida. Devuelve el nuevo stock.
 */
function ajustarStock(int $productoId, int $sucursalId, float $delta, string $tipo,
                      ?string $refTipo = null, ?int $refId = null, float $costo = 0, string $motivo = ''): float
{
    $row = qOne("SELECT id, cantidad FROM inventario_stock WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE", [$productoId, $sucursalId]);
    $anterior = $row ? (float) $row['cantidad'] : 0.0;
    $nuevo = round($anterior + $delta, 3);

    // La validación se hace después del FOR UPDATE para impedir stock negativo
    // incluso cuando dos ventas/transferencias compiten al mismo tiempo.
    if ($nuevo < 0) {
        throw new RuntimeException('Stock insuficiente para completar la operación. Disponible: ' . qty($anterior) . '.');
    }

    if ($row) {
        q("UPDATE inventario_stock SET cantidad = ? WHERE id = ?", [$nuevo, $row['id']]);
    } else {
        dbInsert('inventario_stock', ['producto_id' => $productoId, 'sucursal_id' => $sucursalId, 'cantidad' => $nuevo]);
    }

    $u = current_user();
    dbInsert('movimientos_inventario', [
        'producto_id'    => $productoId,
        'sucursal_id'    => $sucursalId,
        'tipo'           => $tipo,
        'cantidad'       => round($delta, 3),
        'stock_anterior' => $anterior,
        'stock_nuevo'    => $nuevo,
        'costo_unitario' => $costo,
        'referencia_tipo'=> $refTipo,
        'referencia_id'  => $refId,
        'motivo'         => $motivo,
        'usuario_id'     => $u['id'] ?? null,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    return $nuevo;
}

/** Stock actual de un producto en una sucursal. */
function stockActual(int $productoId, int $sucursalId): float
{
    return (float) qVal("SELECT cantidad FROM inventario_stock WHERE producto_id = ? AND sucursal_id = ?", [$productoId, $sucursalId]);
}

/**
 * Devuelve y consume el siguiente NCF de la secuencia indicada (B01/B02). Null si no hay.
 * Formato resultante: B02 + 8 dígitos => B0200000001
 */
function siguienteNCF(string $tipo): ?string
{
    $row = qOne("SELECT * FROM ncf_secuencias WHERE tipo = ? AND activo = 1 FOR UPDATE", [$tipo]);
    if (!$row) return null;
    $sec = (int) $row['secuencia_actual'];
    if ($sec > (int) $row['secuencia_hasta']) return null;
    if (!empty($row['vencimiento']) && $row['vencimiento'] < date('Y-m-d')) return null;
    $ncf = $row['tipo'] . str_pad((string) $sec, 8, '0', STR_PAD_LEFT);
    q("UPDATE ncf_secuencias SET secuencia_actual = secuencia_actual + 1 WHERE id = ?", [$row['id']]);
    return $ncf;
}

/**
 * Registra una transacción financiera (ingreso/gasto) y actualiza el balance de la cuenta.
 * Devuelve el id de la transacción.
 */
function registrarTransaccion(string $tipo, float $monto, array $opts = []): int
{
    if (!in_array($tipo, ['ingreso', 'gasto'], true) || $monto <= 0) {
        throw new InvalidArgumentException('La transacción financiera debe tener un tipo y monto válidos.');
    }
    $cuentaId = $opts['cuenta_id'] ?? null;
    $id = dbInsert('transacciones', [
        'sucursal_id'     => $opts['sucursal_id'] ?? current_sucursal_id(),
        'cuenta_id'       => $cuentaId,
        'tipo'            => $tipo,
        'categoria_id'    => $opts['categoria_id'] ?? null,
        'monto'           => $monto,
        'descripcion'     => $opts['descripcion'] ?? '',
        'referencia_tipo' => $opts['referencia_tipo'] ?? 'manual',
        'referencia_id'   => $opts['referencia_id'] ?? null,
        'fecha'           => $opts['fecha'] ?? date('Y-m-d'),
        'usuario_id'      => current_user()['id'] ?? null,
        'created_at'      => date('Y-m-d H:i:s'),
    ]);
    if ($cuentaId) {
        $signo = $tipo === 'ingreso' ? '+' : '-';
        q("UPDATE cuentas_financieras SET balance = balance $signo ? WHERE id = ?", [$monto, $cuentaId]);
    }
    return $id;
}

/**
 * Cuenta activa preferida por tipo. Prioriza la cuenta de la sucursal y usa
 * una cuenta global como respaldo.
 */
function cuentaFinancieraIdPorTipo(string $tipo, ?int $sucursalId): ?int
{
    if (!in_array($tipo, ['efectivo', 'banco', 'otro'], true)) return null;
    if ($sucursalId !== null) {
        $id = qVal(
            "SELECT id FROM cuentas_financieras
             WHERE tipo = ? AND activo = 1 AND (sucursal_id = ? OR sucursal_id IS NULL)
             ORDER BY sucursal_id IS NULL, id LIMIT 1",
            [$tipo, $sucursalId]
        );
        return $id !== null ? (int) $id : null;
    }
    $id = qVal("SELECT id FROM cuentas_financieras WHERE tipo = ? AND activo = 1 AND sucursal_id IS NULL ORDER BY id LIMIT 1", [$tipo]);
    return $id !== null ? (int) $id : null;
}

/** Busca el id de una categoría financiera por nombre (la crea si hace falta). */
function categoriaFinancieraId(string $tipo, string $nombre): int
{
    if (!in_array($tipo, ['ingreso', 'gasto'], true) || trim($nombre) === '') {
        throw new InvalidArgumentException('La categoría financiera no es válida.');
    }
    q(
        "INSERT INTO categorias_financieras (tipo, nombre) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
        [$tipo, trim($nombre)]
    );
    return lastId();
}

/** Sesión de caja abierta del usuario en la sucursal (o null). */
function cajaSesionAbierta(int $sucursalId, ?int $usuarioId = null): ?array
{
    $u = $usuarioId ?? (current_user()['id'] ?? 0);
    return qOne("SELECT cs.*, c.nombre AS caja_nombre FROM caja_sesiones cs JOIN cajas c ON c.id = cs.caja_id
                 WHERE cs.sucursal_id = ? AND cs.usuario_id = ? AND cs.estado = 'abierta' ORDER BY cs.id DESC LIMIT 1", [$sucursalId, $u]);
}

/**
 * Sesión abierta de una caja/terminal, sea de quien sea. Es distinto de
 * cajaSesionAbierta(): aquí importa la TERMINAL, no el usuario. Se usa para
 * impedir que alguien abra una caja que otra persona dejó abierta.
 */
function cajaAbiertaPorCaja(int $cajaId): ?array
{
    return qOne(
        "SELECT cs.*, c.nombre AS caja_nombre, u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
           FROM caja_sesiones cs
           JOIN cajas c ON c.id = cs.caja_id
           LEFT JOIN usuarios u ON u.id = cs.usuario_id
          WHERE cs.caja_id = ? AND cs.estado = 'abierta' ORDER BY cs.id DESC LIMIT 1",
        [$cajaId]
    );
}

/**
 * Valida si se puede abrir una caja/terminal. Devuelve [true, ''] si se puede,
 * o [false, motivo] con un mensaje claro de quién la tiene abierta.
 */
function validarAperturaCaja(int $cajaId, int $usuarioId): array
{
    $abierta = cajaAbiertaPorCaja($cajaId);
    if (!$abierta) return [true, ''];

    $quien = trim(($abierta['usuario_nombre'] ?? '') . ' ' . ($abierta['usuario_apellido'] ?? '')) ?: 'otro usuario';
    $desde = $abierta['abierta_at'] ? date('d/m/Y h:i A', strtotime($abierta['abierta_at'])) : '';
    $propia = (int) $abierta['usuario_id'] === $usuarioId;

    $msg = $propia
        ? "Ya tienes abierta la caja «{$abierta['caja_nombre']}»" . ($desde ? " desde el $desde" : '') . '. Ciérrala antes de abrir otra.'
        : "La caja «{$abierta['caja_nombre']}» ya está abierta por $quien" . ($desde ? " desde el $desde" : '') . '. Debe cerrarla antes de que otra persona la use.';
    return [false, $msg];
}

// ---------------------------------------------------------------------------
//  Transferencias entre sucursales (lógica de estados/stock)
// ---------------------------------------------------------------------------

/**
 * Envía una transferencia en borrador: valida stock y lo descuenta del origen.
 * Se comparte entre "crear y enviar directo" y "enviar un borrador guardado".
 * Debe llamarse DENTRO de una transacción.
 */
function transferenciaEnviar(int $id): void
{
    $t = qOne("SELECT * FROM transferencias WHERE id=? FOR UPDATE", [$id]);
    if (!$t || $t['estado'] !== 'borrador') throw new RuntimeException('Solo se puede enviar una transferencia en borrador.');
    if (!can_access_sucursal($t['sucursal_origen_id'])) throw new RuntimeException('Solo la sucursal de origen puede enviar esta transferencia.');
    $det = qAll("SELECT * FROM transferencia_detalles WHERE transferencia_id=?", [$id]);
    if (!$det) throw new RuntimeException('El borrador no tiene productos.');
    foreach ($det as $d) {
        if (stockActual((int) $d['producto_id'], (int) $t['sucursal_origen_id']) < (float) $d['cantidad']) {
            $nom = qVal("SELECT nombre FROM productos WHERE id=?", [$d['producto_id']]);
            throw new RuntimeException('Stock insuficiente en origen para «' . $nom . '».');
        }
    }
    foreach ($det as $d) {
        ajustarStock((int) $d['producto_id'], (int) $t['sucursal_origen_id'], -(float) $d['cantidad'], 'transferencia_salida', 'transferencia', $id, 0, 'Transferencia ' . $t['numero'] . ' (salida)');
    }
    dbUpdate('transferencias', ['estado' => 'enviada', 'enviada_por' => current_user()['id'] ?? null, 'enviada_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
}

/** Devuelve el stock al origen (compartido por rechazar y anular). En transacción. */
function transferenciaDevolverStock(array $t): void
{
    foreach (qAll("SELECT * FROM transferencia_detalles WHERE transferencia_id=?", [$t['id']]) as $d) {
        ajustarStock((int) $d['producto_id'], (int) $t['sucursal_origen_id'], (float) $d['cantidad'], 'transferencia_entrada', 'transferencia_devuelta', (int) $t['id'], 0, 'Devolución transferencia ' . $t['numero']);
    }
}
