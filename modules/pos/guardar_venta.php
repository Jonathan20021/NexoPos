<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_perm('pos.vender');
verify_csrf();

$sid = current_sucursal_id();
$uid = (int) current_user()['id'];

if ($sid === null) { flash('error', 'Selecciona una sucursal para vender.'); redirect('modules/pos/index.php'); }

$sesion = cajaSesionAbierta($sid, $uid);
if (!$sesion) { flash('error', 'Debes abrir la caja antes de vender.'); redirect('modules/pos/caja.php'); }

$cart = json_decode(post('cart', '[]'), true);
if (!is_array($cart) || count($cart) === 0) { flash('error', 'El carrito está vacío.'); redirect('modules/pos/index.php'); }

$descuento    = max(0, postNum('descuento'));
$clienteId    = postInt('cliente_id') ?: 1;
$comprobante  = post('comprobante') === 'credito_fiscal' ? 'credito_fiscal' : 'consumidor';
$metodoId     = postInt('metodo_pago_id') ?: 1;
$tasaItbis    = (float) setting('itbis_tasa', DEFAULT_ITBIS);
$puedeMuestra = can('ventas.muestra'); // el navegador no decide esto

try {
    $ventaId = tx(function () use ($cart, $sid, $uid, $sesion, $descuento, $clienteId, $comprobante, $metodoId, $tasaItbis, $puedeMuestra) {
        // 1) Recalcular en el servidor (no se confía en el cliente)
        $subtotal = 0.0; $itbisBruto = 0.0; $costoTotal = 0.0; $lineas = [];
        foreach ($cart as $item) {
            $pid = (int) ($item['id'] ?? 0);
            $cant = (float) ($item['cant'] ?? 0);
            if ($pid <= 0 || $cant <= 0) continue;
            // Bandera de muestra: se re-valida el permiso en el servidor.
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
            // La muestra se cobra en 0: no suma a subtotal ni genera ITBIS. Pero sí
            // consume inventario, así que su costo real sí cuenta en el costo de venta.
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

        // 2) NCF
        $ncf = siguienteNCF($comprobante === 'credito_fiscal' ? 'B01' : 'B02');
        if ($ncf === null) {
            throw new RuntimeException('No hay una secuencia NCF activa, vigente y disponible para este comprobante.');
        }

        // 3) Cabecera
        $numero = nextNumero('ventas', 'numero', 'VTA');
        $ventaId = dbInsert('ventas', [
            'numero' => $numero, 'sucursal_id' => $sid, 'caja_sesion_id' => (int) $sesion['id'],
            'cliente_id' => $clienteId, 'usuario_id' => $uid, 'fecha' => date('Y-m-d H:i:s'),
            'subtotal' => $subtotal, 'descuento' => $descuento, 'itbis' => $itbisTotal, 'total' => $total,
            'costo_total' => $costoTotal, 'tipo_comprobante' => $comprobante, 'ncf' => $ncf, 'estado' => 'completada',
        ]);

        // 4) Detalles + descuento de stock
        foreach ($lineas as $l) {
            // El descuento global prorratea solo las líneas cobradas; la muestra ya es 0.
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

        // 5) Pago
        dbInsert('venta_pagos', ['venta_id' => $ventaId, 'metodo_pago_id' => $metodoId, 'monto' => $total]);

        // 6) Crédito vs contado
        $esCredito = (int) $metodo['es_credito'] === 1;

        if ($esCredito) {
            // Venta a crédito: genera cuenta por cobrar, no entra efectivo
            if ($clienteId <= 1) throw new RuntimeException('Selecciona un cliente registrado para una venta a crédito.');
            if ((float) $cli['limite_credito'] > 0 && ((float) $cli['balance'] + $total) > (float) $cli['limite_credito']) {
                throw new RuntimeException('La venta supera el límite de crédito de ' . $cli['nombre'] . ' (límite ' . money($cli['limite_credito']) . ', balance ' . money($cli['balance']) . ').');
            }
            q("UPDATE clientes SET balance = balance + ? WHERE id = ?", [$total, $clienteId]);
        } else {
            // Contado: registra el ingreso en finanzas
            $tipoCuenta = (int) $metodo['afecta_caja'] === 1 ? 'efectivo' : 'banco';
            $cuentaId = cuentaFinancieraIdPorTipo($tipoCuenta, $sid);
            if ($total > 0) {
                registrarTransaccion('ingreso', $total, [
                    'sucursal_id' => $sid, 'cuenta_id' => $cuentaId,
                    'categoria_id' => categoriaFinancieraId('ingreso', 'Ventas'),
                    'descripcion' => 'Venta ' . $numero, 'referencia_tipo' => 'venta', 'referencia_id' => $ventaId,
                ]);
            }
        }

        return $ventaId;
    });

    audit('pos', 'vender', 'Venta registrada', ['tabla' => 'ventas', 'registro_id' => $ventaId]);
    flash('success', 'Venta registrada correctamente.');
    redirect('modules/pos/ticket.php?id=' . $ventaId . '&print=1');

} catch (Throwable $e) {
    flash('error', $e->getMessage());
    redirect('modules/pos/index.php');
}
