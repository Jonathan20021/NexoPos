<?php
/**
 * Creación de una venta del POS. Fuente ÚNICA de la lógica: la usan tanto la venta
 * normal (guardar_venta.php) como la sincronización de ventas offline (sync_venta.php).
 *
 * === Modo offline (Fase 1) ===
 * Cuando se cae el internet, el POS guarda la venta localmente (IndexedDB) con un
 * UUID generado en el navegador y sigue vendiendo. Al volver la conexión, cada venta
 * pendiente se envía a sync_venta.php, que llama a esta misma función.
 *
 * Reglas de seguridad fiscal:
 *  - El NCF se asigna AQUÍ, en el servidor, en el momento de sincronizar. Nunca
 *    offline. Así la secuencia de comprobantes nunca se duplica ni deja huecos.
 *  - La venta es IDEMPOTENTE por UUID: si el mismo UUID ya se registró (porque una
 *    sincronización se reintentó), se devuelve la venta existente y NO se crea otra.
 *  - Precios, stock y permisos se REVALIDAN aquí; el navegador nunca decide.
 *
 * Devuelve: ['id','numero','ncf','total','duplicada'(bool)].
 * Lanza RuntimeException con un mensaje claro si la venta no puede registrarse.
 */
function registrarVentaPOS(array $in, array $ctx): array
{
    $sid          = (int) $ctx['sid'];
    $uid          = (int) $ctx['uid'];
    $sesion       = $ctx['sesion'];
    $puedeMuestra = !empty($ctx['puede_muestra']);

    $cart        = is_array($in['cart'] ?? null) ? $in['cart'] : [];
    $descuento   = max(0.0, (float) ($in['descuento'] ?? 0));
    $clienteId   = (int) ($in['cliente_id'] ?? 1) ?: 1;
    $comprobante = ($in['comprobante'] ?? '') === 'credito_fiscal' ? 'credito_fiscal' : 'consumidor';
    $metodoId    = (int) ($in['metodo_pago_id'] ?? 1) ?: 1;
    $canal       = in_array($in['canal'] ?? '', canalesVenta(), true) ? $in['canal'] : 'Mostrador';
    $uuid        = preg_match('/^[a-f0-9-]{16,40}$/i', (string) ($in['uuid'] ?? '')) ? $in['uuid'] : null;
    $tasaItbis   = (float) setting('itbis_tasa', DEFAULT_ITBIS);

    // Fecha: en offline se conserva el momento real de la venta; nunca a futuro.
    $fecha = date('Y-m-d H:i:s');
    if (!empty($in['fecha']) && ($ts = strtotime((string) $in['fecha'])) && $ts <= time()) {
        $fecha = date('Y-m-d H:i:s', $ts);
    }

    if (!$cart) throw new RuntimeException('El carrito está vacío.');

    return tx(function () use ($cart, $sid, $uid, $sesion, $descuento, $clienteId, $comprobante, $metodoId, $tasaItbis, $puedeMuestra, $canal, $uuid, $fecha) {
        // Idempotencia: si esta venta (por UUID) ya existe, devolverla sin duplicar.
        if ($uuid !== null) {
            $ya = qOne("SELECT id, numero, ncf, total FROM ventas WHERE uuid = ?", [$uuid]);
            if ($ya) {
                return ['id' => (int) $ya['id'], 'numero' => $ya['numero'], 'ncf' => $ya['ncf'], 'total' => (float) $ya['total'], 'duplicada' => true];
            }
        }

        // 1) Recalcular en el servidor (no se confía en el cliente).
        $subtotal = 0.0; $itbisBruto = 0.0; $costoTotal = 0.0; $lineas = [];
        foreach ($cart as $item) {
            $pid = (int) ($item['id'] ?? 0);
            $cant = (float) ($item['cant'] ?? 0);
            if ($pid <= 0 || $cant <= 0) continue;
            $esMuestra = !empty($item['muestra']);
            if ($esMuestra && !$puedeMuestra) {
                throw new RuntimeException('No tienes permiso para facturar muestras (RD$0.00).');
            }
            $p = qOne("SELECT id, nombre, precio_venta, precio_compra, itbis_aplica, tipo FROM productos WHERE id = ? AND activo = 1", [$pid]);
            if (!$p) throw new RuntimeException('Producto no disponible.');
            if ($p['tipo'] === 'producto') {
                $stock = stockActual($pid, $sid);
                if ($cant > $stock) throw new RuntimeException('Stock insuficiente de «' . $p['nombre'] . '» (disponible: ' . qty($stock) . ').');
            }
            $precioReal = (float) $p['precio_venta'];
            $precio = $esMuestra ? 0.0 : $precioReal;
            $base   = round($precio * $cant, 2);
            $itbis  = ($esMuestra || !$p['itbis_aplica']) ? 0.0 : round($base * $tasaItbis / 100, 2);
            $subtotal   += $base;
            $itbisBruto += $itbis;
            $costoTotal += (float) $p['precio_compra'] * $cant;
            $lineas[] = [
                'pid' => $pid, 'nombre' => $p['nombre'], 'tipo' => $p['tipo'], 'cant' => $cant,
                'precio' => $precio, 'costo' => (float) $p['precio_compra'], 'base' => $base, 'itbis' => $itbis,
                'es_muestra' => $esMuestra ? 1 : 0, 'precio_original' => $esMuestra ? $precioReal : 0.0,
            ];
        }
        if (!$lineas) throw new RuntimeException('No hay líneas válidas en la venta.');

        $descuento = min($descuento, $subtotal);
        $factor    = $subtotal > 0 ? ($subtotal - $descuento) / $subtotal : 1;
        $itbisTotal = round($itbisBruto * $factor, 2);
        $total = round(($subtotal - $descuento) + $itbisTotal, 2);

        $metodo = qOne("SELECT id, nombre, afecta_caja, es_credito FROM metodos_pago WHERE id = ? AND activo = 1", [$metodoId]);
        if (!$metodo) throw new RuntimeException('Método de pago no válido o inactivo.');
        $cli = qOne("SELECT id, nombre, balance, limite_credito FROM clientes WHERE id = ? AND activo = 1 FOR UPDATE", [$clienteId]);
        if (!$cli) throw new RuntimeException('Cliente no válido o inactivo.');

        // 2) NCF (siempre en el servidor).
        $ncf = siguienteNCF($comprobante === 'credito_fiscal' ? 'B01' : 'B02');
        if ($ncf === null) {
            throw new RuntimeException('No hay una secuencia NCF activa, vigente y disponible para este comprobante.');
        }

        // 3) Cabecera.
        $numero = nextNumero('ventas', 'numero', 'VTA');
        $ventaId = dbInsert('ventas', [
            'numero' => $numero, 'sucursal_id' => $sid, 'caja_sesion_id' => (int) $sesion['id'],
            'cliente_id' => $clienteId, 'usuario_id' => $uid, 'fecha' => $fecha,
            'subtotal' => $subtotal, 'descuento' => $descuento, 'itbis' => $itbisTotal, 'total' => $total,
            'costo_total' => $costoTotal, 'tipo_comprobante' => $comprobante, 'ncf' => $ncf, 'estado' => 'completada',
            'canal_venta' => $canal, 'uuid' => $uuid,
        ]);

        // 4) Detalles + descuento de stock.
        foreach ($lineas as $l) {
            $itbisLinea = $l['es_muestra'] ? 0.0 : round($l['itbis'] * $factor, 2);
            dbInsert('venta_detalles', [
                'venta_id' => $ventaId, 'producto_id' => $l['pid'], 'descripcion' => $l['nombre'],
                'cantidad' => $l['cant'], 'precio_unitario' => $l['precio'], 'costo_unitario' => $l['costo'],
                'descuento' => 0, 'itbis' => $itbisLinea, 'subtotal' => $l['base'],
                'es_muestra' => $l['es_muestra'], 'precio_original' => $l['precio_original'],
            ]);
            if ($l['tipo'] === 'producto') {
                $motivo = $l['es_muestra'] ? 'Muestra ' . $numero : 'Venta ' . $numero;
                ajustarStock($l['pid'], $sid, -$l['cant'], 'venta', 'venta', $ventaId, $l['costo'], $motivo);
            }
        }

        // 5) Pago.
        dbInsert('venta_pagos', ['venta_id' => $ventaId, 'metodo_pago_id' => $metodoId, 'monto' => $total]);

        // 6) Crédito vs contado.
        if ((int) $metodo['es_credito'] === 1) {
            if ($clienteId <= 1) throw new RuntimeException('Selecciona un cliente registrado para una venta a crédito.');
            if ((float) $cli['limite_credito'] > 0 && ((float) $cli['balance'] + $total) > (float) $cli['limite_credito']) {
                throw new RuntimeException('La venta supera el límite de crédito de ' . $cli['nombre'] . '.');
            }
            q("UPDATE clientes SET balance = balance + ? WHERE id = ?", [$total, $clienteId]);
        } else {
            $tipoCuenta = (int) $metodo['afecta_caja'] === 1 ? 'efectivo' : 'banco';
            if ($total > 0) {
                registrarTransaccion('ingreso', $total, [
                    'sucursal_id' => $sid, 'cuenta_id' => cuentaFinancieraIdPorTipo($tipoCuenta, $sid),
                    'categoria_id' => categoriaFinancieraId('ingreso', 'Ventas'),
                    'descripcion' => 'Venta ' . $numero, 'referencia_tipo' => 'venta', 'referencia_id' => $ventaId,
                    'fecha' => substr($fecha, 0, 10),
                ]);
            }
        }

        return ['id' => $ventaId, 'numero' => $numero, 'ncf' => $ncf, 'total' => $total, 'duplicada' => false];
    });
}
