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
    // Solo lo activa código del servidor (facturar una cotización). Ver más abajo.
    $preciosPactados = !empty($ctx['precios_pactados']);

    $cart        = is_array($in['cart'] ?? null) ? $in['cart'] : [];
    $descuento   = max(0.0, (float) ($in['descuento'] ?? 0));
    $clienteId   = (int) ($in['cliente_id'] ?? 1) ?: 1;
    // Se valida contra los comprobantes REALMENTE disponibles: si alguien manda
    // «gubernamental» sin secuencia E45 viva, cae a consumidor en vez de romper
    // la venta a medias.
    $comprobante = array_key_exists((string) ($in['comprobante'] ?? ''), ncfComprobantesDisponibles())
        ? (string) $in['comprobante'] : 'consumidor';
    $metodoId    = (int) ($in['metodo_pago_id'] ?? 1) ?: 1;
    $canal       = in_array($in['canal'] ?? '', canalesVenta(), true) ? $in['canal'] : 'Mostrador';
    $uuid        = preg_match('/^[a-f0-9-]{16,40}$/i', (string) ($in['uuid'] ?? '')) ? $in['uuid'] : null;
    $tasaItbis   = (float) setting('itbis_tasa', DEFAULT_ITBIS);

    // Tienda (marca comercial) con la que se factura. Solo decide qué logo y qué
    // datos se imprimen; el emisor fiscal sigue siendo la empresa.
    // Se valida contra el catálogo: el navegador no puede imprimir la marca que
    // le dé la gana. Si no llega ninguna, más abajo se deduce del carrito, y así
    // los llamadores del servidor (facturar una cotización, la tienda en línea)
    // no tienen que enterarse de que existen las tiendas.
    $tiendaId = (int) ($in['tienda_id'] ?? 0) ?: null;
    if ($tiendaId !== null && !array_key_exists($tiendaId, tiendas_opciones())) {
        throw new RuntimeException('La tienda seleccionada no existe o está inactiva.');
    }

    // NCF pre-asignado offline (Fase 2): el navegador tomó un número de la reserva
    // del terminal y ya lo imprimió. Se validará contra esa reserva más abajo.
    $ncfOffline  = isset($in['ncf']) && is_string($in['ncf']) ? trim($in['ncf']) : '';
    $terminalId  = (int) ($ctx['terminal_id'] ?? 0);

    // Fecha: en offline se conserva el momento real de la venta; nunca a futuro.
    $fecha = date('Y-m-d H:i:s');
    if (!empty($in['fecha']) && ($ts = strtotime((string) $in['fecha'])) && $ts <= time()) {
        $fecha = date('Y-m-d H:i:s', $ts);
    }

    if (!$cart) throw new RuntimeException('El carrito está vacío.');

    // txReintentable y no tx: con varias cajas vendiendo a la vez, InnoDB puede
    // abortar una transacción por interbloqueo o espera de bloqueo. Eso no es un
    // problema del negocio y no debe llegarle al cajero como «error»: se reintenta
    // y la venta entra. Los errores reales (stock, crédito, NCF) suben igual.
    $resultado = txReintentable(function () use ($cart, $sid, $uid, $sesion, $descuento, $clienteId, $comprobante, $metodoId, $tasaItbis, $puedeMuestra, $canal, $uuid, $fecha, $ncfOffline, $terminalId, $preciosPactados, $tiendaId) {
        // Idempotencia: si esta venta (por UUID) ya existe, devolverla sin duplicar.
        if ($uuid !== null) {
            $ya = qOne("SELECT id, numero, ncf, total FROM ventas WHERE uuid = ?", [$uuid]);
            if ($ya) {
                return ['id' => (int) $ya['id'], 'numero' => $ya['numero'], 'ncf' => $ya['ncf'], 'total' => (float) $ya['total'], 'duplicada' => true];
            }
        }

        // 1) Recalcular en el servidor (no se confía en el cliente).
        $subtotal = 0.0; $itbisBruto = 0.0; $costoTotal = 0.0; $lineas = [];
        $tiendasEnCarrito = [];
        foreach ($cart as $item) {
            $pid = (int) ($item['id'] ?? 0);
            $cant = (float) ($item['cant'] ?? 0);
            if ($pid <= 0 || $cant <= 0) continue;
            $esMuestra = !empty($item['muestra']);
            if ($esMuestra && !$puedeMuestra) {
                throw new RuntimeException('No tienes permiso para facturar muestras (RD$0.00).');
            }
            $p = qOne(
                "SELECT p.id, p.nombre, p.codigo, p.precio_venta, p.precio_compra, p.itbis_aplica, p.tipo,
                        p.categoria_id, p.marca_id, p.tienda_id,
                        p.ecf_indicador_facturacion, p.ecf_impuesto_adicional, u.ecf_codigo AS ecf_unidad
                   FROM productos p
                   LEFT JOIN unidades u ON u.id = p.unidad_id
                  WHERE p.id = ? AND p.activo = 1",
                [$pid]
            );
            if (!$p) throw new RuntimeException('Producto no disponible.');
            if (!empty($p['tienda_id'])) $tiendasEnCarrito[(int) $p['tienda_id']] = $p['nombre'];
            if ($p['tipo'] === 'producto') {
                $stock = stockActual($pid, $sid);
                if ($cant > $stock) throw new RuntimeException('Stock insuficiente de «' . $p['nombre'] . '» (disponible: ' . qty($stock) . ').');
            }
            // Precio con promoción vigente (se calcula en el servidor). Una muestra
            // ignora la promoción: su precio es 0 de todos modos.
            $precioReal = aplicarPromocion((float) $p['precio_venta'], $p, 'pos')['precio'];
            $precio = $esMuestra ? 0.0 : $precioReal;

            // Precio pactado en una cotización aceptada.
            //
            // El navegador NUNCA decide un precio: por eso arriba se recalcula
            // todo contra el catálogo. Pero una cotización firmada es un
            // compromiso, y si el precio de lista subió entre la cotización y la
            // factura, el cliente tiene que pagar lo que se le prometió. Solo se
            // acepta cuando quien llama lo marca explícitamente, y ese marcado
            // únicamente lo pone código del servidor que leyó el precio de la
            // propia cotización guardada en la base.
            if (!$esMuestra && $preciosPactados && isset($item['precio']) && (float) $item['precio'] >= 0) {
                $precio = round((float) $item['precio'], 2);
            }
            $base   = round($precio * $cant, 2);

            // Régimen especial: el EXENTO ES EL COMPRADOR, no el producto.
            //
            // Zonas francas, misiones diplomáticas e instituciones sin fines de
            // lucro no pagan ITBIS por lo que compran, sea lo que sea. Así que
            // en este comprobante ninguna línea lo lleva aunque el artículo
            // normalmente tribute — y por eso la exención no puede deducirse del
            // catálogo, tiene que venir del tipo de comprobante.
            //
            // También decide el comprobante electrónico: con las líneas
            // gravadas, el proveedor arma un `Totales` con MontoGravadoTotal y
            // el esquema de la DGII rechaza el tipo 44, que solo admite exento.
            $exentoPorRegimen = $comprobante === 'regimen_especial';

            $itbis  = ($esMuestra || $exentoPorRegimen || !$p['itbis_aplica'])
                ? 0.0
                : round($base * $tasaItbis / 100, 2);
            $subtotal   += $base;
            $itbisBruto += $itbis;
            $costoTotal += (float) $p['precio_compra'] * $cant;
            // Descripción propia de la línea.
            //
            // La pide el cotizador: un concepto libre («Instalación y montaje»)
            // se factura sobre un producto genérico de tipo servicio, pero en el
            // comprobante tiene que leerse el concepto real, no «Servicio». Va
            // con la MISMA guarda que el precio pactado —solo cuando lo marca
            // código del servidor— porque el navegador no puede decidir lo que
            // dice una factura.
            $nombreLinea = $p['nombre'];
            if ($preciosPactados && !empty($item['descripcion'])) {
                $nombreLinea = mb_substr(trim((string) $item['descripcion']), 0, 180);
            }

            $lineas[] = [
                'pid' => $pid, 'nombre' => $nombreLinea, 'tipo' => $p['tipo'], 'cant' => $cant,
                'precio' => $precio, 'costo' => (float) $p['precio_compra'], 'base' => $base, 'itbis' => $itbis,
                'es_muestra' => $esMuestra ? 1 : 0, 'precio_original' => $esMuestra ? $precioReal : 0.0,
                // Datos fiscales CONGELADOS. Si mañana el producto cambia de tasa
                // o de unidad, el comprobante ya emitido debe seguir declarando lo
                // que se declaró ese día; derivarlo por JOIN reescribiría el pasado.
                // 4 = exento (Tabla 1). En un régimen especial manda el
                // comprobante por encima del indicador del producto.
                'ecf_indicador' => $exentoPorRegimen
                    ? 4
                    : ($p['ecf_indicador_facturacion'] !== null
                        ? (int) $p['ecf_indicador_facturacion']
                        : ($p['itbis_aplica'] ? ecfIndicadorDesdeTasa($tasaItbis) : 4)),
                'ecf_unidad'    => $p['ecf_unidad'] !== null ? (int) $p['ecf_unidad'] : 43,
                'ecf_bien'      => ecfBienServicioDesdeProducto($p['tipo']),
                'ecf_impuesto'  => $p['ecf_impuesto_adicional'] ?: null,
            ];
        }
        if (!$lineas) throw new RuntimeException('No hay líneas válidas en la venta.');

        // 1.b) Coherencia de la marca.
        //
        // Una factura lleva UN logo. Si el carrito trae artículos de dos marcas
        // distintas, no hay respuesta correcta: se corta y se dice cuáles son,
        // en vez de imprimir el logo equivocado sobre la mercancía de otro.
        // Los artículos sin marca acompañan a cualquiera.
        $tienda = $tiendaId;
        if (count($tiendasEnCarrito) > 1) {
            $nombres = array_map(fn($id) => tiendas_opciones()[$id] ?? ('#' . $id), array_keys($tiendasEnCarrito));
            throw new RuntimeException('El carrito mezcla artículos de ' . implode(' y ', $nombres)
                . '. Una factura solo puede llevar el logo de una tienda: sepáralas en dos ventas.');
        }
        if ($tiendasEnCarrito) {
            $delCarrito = (int) array_key_first($tiendasEnCarrito);
            if ($tienda === null) {
                // Nadie eligió marca (cotización facturada, pedido en línea):
                // se toma la del propio artículo.
                $tienda = $delCarrito;
            } elseif ($tienda !== $delCarrito) {
                throw new RuntimeException('«' . $tiendasEnCarrito[$delCarrito] . '» pertenece a otra tienda. '
                    . 'Cambia la tienda activa del punto de venta o quítalo del carrito.');
            }
        }

        $descuento = min($descuento, $subtotal);
        $factor    = $subtotal > 0 ? ($subtotal - $descuento) / $subtotal : 1;
        $itbisTotal = round($itbisBruto * $factor, 2);
        $total = round(($subtotal - $descuento) + $itbisTotal, 2);

        $metodo = qOne("SELECT id, nombre, afecta_caja, es_credito FROM metodos_pago WHERE id = ? AND activo = 1", [$metodoId]);
        if (!$metodo) throw new RuntimeException('Método de pago no válido o inactivo.');
        $cli = qOne("SELECT id, nombre, balance, limite_credito FROM clientes WHERE id = ? AND activo = 1 FOR UPDATE", [$clienteId]);
        if (!$cli) throw new RuntimeException('Cliente no válido o inactivo.');

        // 2) NCF. Online: lo asigna el servidor desde el maestro (siguienteNCF).
        //    Offline (Fase 2): el navegador ya lo tomó de la reserva del terminal y
        //    lo imprimió; aquí se VALIDA que pertenezca a esa reserva y no esté usado.
        //
        //    Con la facturación electrónica encendida la serie cambia sola a
        //    E31/E32: el número se toma DENTRO de esta transacción, igual que el
        //    preimpreso, para que el comprobante impreso y el que se declara a la
        //    DGII sean siempre el mismo.
        $tipoNcf = ncfTipoDeComprobante($comprobante);
        $ncfDeSerieAnterior = false;
        if ($ncfOffline !== '') {
            if ($terminalId <= 0) {
                throw new RuntimeException('Falta identificar el terminal para validar el NCF offline.');
            }
            $p = ncfPartes($ncfOffline);

            // El terminal pudo tomar el número ANTES de que se encendiera la
            // facturación electrónica. Ese comprobante ya está impreso y en manos
            // del cliente: rechazarlo aquí no lo des-imprime, solo hace perder la
            // venta. Se acepta la serie contraria equivalente y la venta entra
            // como preimpresa (no se le genera e-CF, porque su número no es un
            // e-NCF). Queda avisado en las notas para que se revise al cuadrar.
            // Gubernamental y régimen especial no tienen talonario preimpreso
            // configurado, así que no hay serie contraria que aceptar: solo vale
            // su propio tipo.
            $pares = ['E31' => 'B01', 'B01' => 'E31', 'E32' => 'B02', 'B02' => 'E32'];
            $serieContraria = $pares[$tipoNcf] ?? $tipoNcf;

            if (!$p || !in_array($p['tipo'], [$tipoNcf, $serieContraria], true)) {
                throw new RuntimeException('El NCF offline no corresponde al tipo de comprobante.');
            }
            $ncfDeSerieAnterior = $p['tipo'] !== $tipoNcf;
            if (!ncfReservaDeTerminal($terminalId, $ncfOffline)) {
                throw new RuntimeException('El NCF offline no pertenece a una reserva activa de este terminal.');
            }
            if (qOne("SELECT id FROM ventas WHERE ncf = ?", [$ncfOffline])) {
                throw new RuntimeException('El NCF offline ya fue registrado en otra venta.');
            }
            $ncf = $ncfOffline;
        } else {
            $ncf = siguienteNCF($tipoNcf);
            if ($ncf === null) {
                throw new RuntimeException('No hay una secuencia NCF activa, vigente y disponible para este comprobante.');
            }
        }

        // 3) Cabecera.
        $numero = nextNumero('ventas', 'numero', 'VTA');
        $ventaId = dbInsert('ventas', [
            // Sin caja abierta la venta se registra igual, sin sesión. El POS
            // exige caja antes de vender, pero facturar una cotización desde la
            // oficina es normal y no tiene por qué haber un cajón abierto.
            'numero' => $numero, 'sucursal_id' => $sid, 'caja_sesion_id' => $sesion ? (int) $sesion['id'] : null,
            // Congelada: reimprimir esta factura mañana tiene que dar el mismo
            // logo aunque el producto cambie de marca.
            'tienda_id' => $tienda,
            'cliente_id' => $clienteId, 'usuario_id' => $uid, 'fecha' => $fecha,
            'subtotal' => $subtotal, 'descuento' => $descuento, 'itbis' => $itbisTotal, 'total' => $total,
            'costo_total' => $costoTotal, 'tipo_comprobante' => $comprobante, 'ncf' => $ncf, 'estado' => 'completada',
            'canal_venta' => $canal, 'uuid' => $uuid,
            // Vacío cuando el comprobante es preimpreso; '31'/'32' cuando es electrónico.
            'ecf_tipo' => ecfENCFValido($ncf) ? substr($ncf, 1, 2) : null,
            'notas' => $ncfDeSerieAnterior
                ? 'Comprobante de la serie anterior, reservado por el terminal antes del corte a facturación electrónica.'
                : null,
        ]);

        // 4) Detalles, en el orden en que el cajero armó el carrito (así sale el ticket).
        foreach ($lineas as $l) {
            $itbisLinea = $l['es_muestra'] ? 0.0 : round($l['itbis'] * $factor, 2);
            dbInsert('venta_detalles', [
                'venta_id' => $ventaId, 'producto_id' => $l['pid'], 'descripcion' => $l['nombre'],
                'cantidad' => $l['cant'], 'precio_unitario' => $l['precio'], 'costo_unitario' => $l['costo'],
                'descuento' => 0, 'itbis' => $itbisLinea, 'subtotal' => $l['base'],
                'es_muestra' => $l['es_muestra'], 'precio_original' => $l['precio_original'],
                'ecf_indicador_facturacion' => $l['ecf_indicador'],
                'ecf_unidad_medida'         => $l['ecf_unidad'],
                'ecf_bien_servicio'         => $l['ecf_bien'],
                'ecf_impuesto_adicional'    => $l['ecf_impuesto'],
            ]);
        }

        // 5) Descuento de stock SIEMPRE en orden de producto_id.
        //    Dos cajas de la misma sucursal vendiendo los mismos artículos en
        //    distinto orden se bloqueaban en cruz (A espera el lápiz que tiene B,
        //    B espera el cuaderno que tiene A) y InnoDB mataba una de las dos.
        //    Bloqueando siempre en el mismo orden, ese interbloqueo no existe.
        $ordenStock = $lineas;
        usort($ordenStock, fn($a, $b) => $a['pid'] <=> $b['pid']);
        foreach ($ordenStock as $l) {
            if ($l['tipo'] !== 'producto') continue;
            $motivo = $l['es_muestra'] ? 'Muestra ' . $numero : 'Venta ' . $numero;
            ajustarStock($l['pid'], $sid, -$l['cant'], 'venta', 'venta', $ventaId, $l['costo'], $motivo);
        }

        // 6) Pago.
        dbInsert('venta_pagos', ['venta_id' => $ventaId, 'metodo_pago_id' => $metodoId, 'monto' => $total]);

        // 7) Crédito vs contado.
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

    // ------------------------------------------------------------------
    //  Comprobante Fiscal Electrónico
    // ------------------------------------------------------------------
    //  FUERA de la transacción, y a propósito.
    //
    //  Aquí ya hay una venta cerrada: la mercancía salió, el cliente pagó, el
    //  stock bajó y el e-NCF se imprimió. Si esto viviera dentro de la
    //  transacción, un proveedor caído o una red lenta desharían todo eso y el
    //  cajero vería un error por algo que no tiene nada que ver con la venta.
    //
    //  Lo que falta es TRANSMITIR, y para eso está la cola con reintentos.
    //  ecfEmitirSeguro() nunca lanza: lo peor que puede pasar es que el
    //  comprobante quede pendiente y se avise.
    if (!$resultado['duplicada'] && ecfActivo() && ecfENCFValido((string) ($resultado['ncf'] ?? ''))) {
        $emision = ecfEmitirSeguro('venta', (int) $resultado['id']);
        $resultado['ecf'] = [
            'ok'       => $emision['ok'],
            'encf'     => $emision['encf'],
            'track_id' => $emision['track_id'],
            'mensaje'  => $emision['mensaje'],
        ];
    }

    return $resultado;
}
