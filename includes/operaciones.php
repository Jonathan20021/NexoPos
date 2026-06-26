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

/** Busca el id de una categoría financiera por nombre (la crea si hace falta). */
function categoriaFinancieraId(string $tipo, string $nombre): int
{
    $id = (int) qVal("SELECT id FROM categorias_financieras WHERE tipo = ? AND nombre = ?", [$tipo, $nombre]);
    if (!$id) $id = dbInsert('categorias_financieras', ['tipo' => $tipo, 'nombre' => $nombre]);
    return $id;
}

/** Sesión de caja abierta del usuario en la sucursal (o null). */
function cajaSesionAbierta(int $sucursalId, ?int $usuarioId = null): ?array
{
    $u = $usuarioId ?? (current_user()['id'] ?? 0);
    return qOne("SELECT cs.*, c.nombre AS caja_nombre FROM caja_sesiones cs JOIN cajas c ON c.id = cs.caja_id
                 WHERE cs.sucursal_id = ? AND cs.usuario_id = ? AND cs.estado = 'abierta' ORDER BY cs.id DESC LIMIT 1", [$sucursalId, $u]);
}
