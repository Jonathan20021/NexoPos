<?php
/**
 * Cotizaciones: el documento que va antes de la factura.
 *
 * En venta a empresas casi ninguna factura sale de la nada: primero el cliente
 * pide precio, se le manda una cotización con vigencia, y cuando la acepta se
 * convierte en factura. Este archivo cubre ese ciclo completo.
 *
 * Dos decisiones que conviene entender:
 *
 * · UNA COTIZACIÓN NO TOCA NADA. No mueve stock, no genera asientos, no reserva
 *   mercancía. Es una oferta. Todo eso ocurre al facturarla, y lo hace
 *   `registrarVentaPOS()`, la misma función del POS: así una factura nacida de
 *   una cotización es idéntica a cualquier otra (NCF, kardex, caja, comisiones).
 *
 * · EL PRECIO COTIZADO SE RESPETA. Si el precio de lista sube entre la
 *   cotización y la factura, el cliente paga lo que se le prometió. Los precios
 *   viajan desde la cotización guardada en la base, nunca desde el navegador.
 */

/** ¿Está aplicada la migración P11? */
function cot_disponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'cotizaciones'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Etiqueta y color de cada estado. */
function cot_estados(): array
{
    return [
        'borrador'  => ['Borrador',  'slate'],
        'enviada'   => ['Enviada',   'sky'],
        'aceptada'  => ['Aceptada',  'emerald'],
        'rechazada' => ['Rechazada', 'rose'],
        'vencida'   => ['Vencida',   'amber'],
        // El flujo actual cierra la cotización al facturar, así que «parcial» no
        // se asigna hoy. Está en el ENUM y aquí porque el día que se quiera
        // dejar el resto pendiente no haga falta ni migración ni parche en la
        // pantalla — y porque una fila con un estado sin etiqueta rompería la
        // lista en vez de mostrarla.
        'parcial'   => ['Parcial',   'cyan'],
        'facturada' => ['Facturada', 'indigo'],
    ];
}

/**
 * Estado que se MUESTRA. Una cotización enviada cuya fecha de vigencia ya pasó
 * está vencida aunque en la base siga diciendo «enviada»: se calcula al vuelo
 * para no depender de que alguien corra un proceso todas las noches.
 */
function cot_estadoVisible(array $c): string
{
    if (in_array($c['estado'], ['borrador', 'enviada'], true) && $c['vence'] < date('Y-m-d')) {
        return 'vencida';
    }
    return $c['estado'];
}

/* ============================================================
 *  CONFIGURACIÓN DEL COTIZADOR
 * ============================================================ */

/**
 * La fila única de `cotizacion_config`, con valores por defecto si la migración
 * P16 todavía no se aplicó.
 *
 * Se cachea en memoria: la leen el editor, el PDF y el correo dentro de la
 * misma petición, y es una fila que no cambia a mitad de camino.
 */
function cot_config(bool $recargar = false): array
{
    static $cfg = null;
    if ($cfg !== null && !$recargar) return $cfg;

    $base = [
        'validez_dias' => 15, 'condiciones' => null, 'pie' => null,
        'mensaje_cierre' => null, 'prefijo' => 'COT',
        'mostrar_itbis' => 1, 'mostrar_sku' => 1, 'mostrar_descuento' => 1,
        'producto_servicio_id' => null, 'campos' => null,
    ];
    try {
        $fila = qOne("SELECT * FROM cotizacion_config WHERE id = 1");
    } catch (Throwable $e) {
        $fila = null;                       // sin migración: valores de fábrica
    }
    return $cfg = array_merge($base, $fila ?: []);
}

/** Guarda la configuración y limpia la caché. */
function cot_guardarConfig(array $d): void
{
    $campos = [];
    foreach ((array) ($d['campos'] ?? []) as $c) {
        $etiqueta = trim((string) ($c['etiqueta'] ?? ''));
        if ($etiqueta === '') continue;
        // La clave se deriva de la etiqueta: quien configura escribe «Orden de
        // compra», no un identificador.
        $clave = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(cot_sinAcentos($etiqueta)));
        $campos[] = ['clave' => trim($clave, '_'), 'etiqueta' => mb_substr($etiqueta, 0, 60)];
        if (count($campos) >= 8) break;     // un formulario, no una base de datos
    }

    $fila = [
        'validez_dias'         => max(1, min(365, (int) ($d['validez_dias'] ?? 15))),
        'condiciones'          => trim((string) ($d['condiciones'] ?? '')) ?: null,
        'pie'                  => mb_substr(trim((string) ($d['pie'] ?? '')), 0, 500) ?: null,
        'mensaje_cierre'       => mb_substr(trim((string) ($d['mensaje_cierre'] ?? '')), 0, 255) ?: null,
        'prefijo'              => strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($d['prefijo'] ?? 'COT'))) ?: 'COT',
        'mostrar_itbis'        => !empty($d['mostrar_itbis']) ? 1 : 0,
        'mostrar_sku'          => !empty($d['mostrar_sku']) ? 1 : 0,
        'mostrar_descuento'    => !empty($d['mostrar_descuento']) ? 1 : 0,
        'producto_servicio_id' => (int) ($d['producto_servicio_id'] ?? 0) ?: null,
        'campos'               => $campos ? json_encode($campos, JSON_UNESCAPED_UNICODE) : null,
    ];

    if (qVal("SELECT 1 FROM cotizacion_config WHERE id = 1")) {
        dbUpdate('cotizacion_config', $fila, 'id = 1', []);
    } else {
        $fila['id'] = 1;
        dbInsert('cotizacion_config', $fila);
    }
    cot_config(true);
}

/** Quita acentos para derivar la clave de un campo propio. */
function cot_sinAcentos(string $s): string
{
    return strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
    ]);
}

/** Definición de los campos propios: [['clave','etiqueta'], …]. */
function cot_campos(): array
{
    $j = cot_config()['campos'] ?? null;
    if (!$j) return [];
    $c = json_decode((string) $j, true);
    return is_array($c) ? $c : [];
}

/** Valores de los campos propios de una cotización: ['clave' => 'valor']. */
function cot_camposValores(array $c): array
{
    $j = $c['campos_extra'] ?? null;
    if (!$j) return [];
    $v = json_decode((string) $j, true);
    return is_array($v) ? $v : [];
}

/**
 * El producto de servicio al que se enlazan las líneas libres al facturar.
 * Devuelve null si no está configurado o si el que hay ya no sirve.
 */
function cot_productoServicio(): ?array
{
    $id = (int) (cot_config()['producto_servicio_id'] ?? 0);
    if (!$id) return null;
    return qOne("SELECT id, nombre, tipo, activo FROM productos WHERE id = ? AND activo = 1 AND tipo = 'servicio'", [$id]);
}

/** ¿Se puede seguir editando? Una vez facturada, no. */
function cot_editable(array $c): bool
{
    return in_array($c['estado'], ['borrador', 'enviada', 'vencida', 'rechazada'], true);
}

/**
 * Recalcula los totales a partir de las líneas.
 *
 * Hay DOS niveles de descuento y el orden importa:
 *
 *   1. El de la línea, que es lo que se negoció de ese renglón. Se guarda como
 *      porcentaje Y como monto: el porcentaje es lo pactado, el monto es lo
 *      aplicado, y con redondeo a dos decimales uno no siempre se deduce del
 *      otro. `cotizacion_detalles.subtotal` queda YA NETO de este descuento.
 *   2. El global, que se aplica encima del subtotal y se reparte
 *      proporcionalmente, igual que en el POS.
 *
 * El ITBIS se calcula sobre la línea YA rebajada —se tributa sobre lo que se
 * cobra, no sobre el precio de lista— y después se ajusta por el descuento
 * global con el mismo factor que usa la venta. Si no coincidieran, la factura
 * que sale de la cotización no cuadraría con ella.
 *
 * @param array $lineas [['producto_id','descripcion','cantidad','precio_unitario',
 *                        'itbis_aplica','descuento_pct','descuento_monto','es_servicio'], ...]
 * @param float $descuento Descuento GLOBAL, encima de los de línea.
 * @return array ['subtotal','descuento','descuento_lineas','bruto','itbis','total','lineas']
 */
function cot_totales(array $lineas, float $descuento = 0.0): array
{
    $tasa = (float) setting('itbis_tasa', DEFAULT_ITBIS);
    $bruto = 0.0;            // suma a precio de lista, antes de rebajar nada
    $subtotal = 0.0;         // suma ya neta de los descuentos de línea
    $descLineas = 0.0;
    $itbisBruto = 0.0;
    $out = [];

    foreach ($lineas as $i => $l) {
        $cant   = max(0, round((float) ($l['cantidad'] ?? 0), 3));
        $precio = max(0, round((float) ($l['precio_unitario'] ?? 0), 2));
        if ($cant <= 0) continue;

        $base = round($precio * $cant, 2);

        // El porcentaje manda si viene; si no, se toma el monto tal cual. Nunca
        // puede rebajar más que la propia línea.
        $pct = min(100.0, max(0.0, round((float) ($l['descuento_pct'] ?? 0), 2)));
        $dm  = $pct > 0
            ? round($base * $pct / 100, 2)
            : min($base, max(0.0, round((float) ($l['descuento_monto'] ?? 0), 2)));
        $dm  = min($dm, $base);

        $neto  = round($base - $dm, 2);
        $itbis = !empty($l['itbis_aplica']) ? round($neto * $tasa / 100, 2) : 0.0;

        $bruto      += $base;
        $descLineas += $dm;
        $subtotal   += $neto;
        $itbisBruto += $itbis;

        $out[] = [
            'producto_id'     => (int) ($l['producto_id'] ?? 0) ?: null,
            'descripcion'     => mb_substr(trim((string) ($l['descripcion'] ?? '')), 0, 255),
            'cantidad'        => $cant,
            'precio_unitario' => $precio,
            'descuento_pct'   => $pct,
            'descuento_monto' => $dm,
            'itbis'           => $itbis,
            'subtotal'        => $neto,
            'es_servicio'     => !empty($l['es_servicio']) ? 1 : 0,
            'orden'           => $i,
        ];
    }

    $descuento = min(max(0.0, round($descuento, 2)), $subtotal);
    $factor    = $subtotal > 0 ? ($subtotal - $descuento) / $subtotal : 1;
    $itbis     = round($itbisBruto * $factor, 2);

    return [
        'bruto'            => round($bruto, 2),
        'descuento_lineas' => round($descLineas, 2),
        'subtotal'         => round($subtotal, 2),
        'descuento'        => $descuento,
        'itbis'            => $itbis,
        'total'            => round(($subtotal - $descuento) + $itbis, 2),
        'lineas'           => $out,
    ];
}

/**
 * Resuelve si cada línea lleva ITBIS mirando el CATÁLOGO, no lo que llegue del
 * navegador.
 *
 * El editor tenía una casilla de ITBIS por línea, y eso permitía cotizar sin
 * impuesto un producto gravado. La factura no respeta esa casilla —
 * `registrarVentaPOS()` lee el impuesto del producto, como debe— así que el
 * cliente recibía un total distinto del que se le prometió.
 *
 * Que un artículo tribute no es materia de negociación: lo decide el producto y
 * la ley. El precio sí se pacta; el impuesto, no. Resolviéndolo aquí, el total
 * de la cotización y el de la factura no pueden separarse.
 *
 * Las líneas libres siguen al producto de servicio, que es sobre el que se
 * facturarán.
 */
function cot_resolverItbis(array $lineas): array
{
    $ids = [];
    foreach ($lineas as $l) {
        $pid = (int) ($l['producto_id'] ?? 0);
        if ($pid > 0) $ids[$pid] = true;
    }

    $mapa = [];
    if ($ids) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        foreach (qAll("SELECT id, itbis_aplica FROM productos WHERE id IN ($marcas)", array_keys($ids)) as $p) {
            $mapa[(int) $p['id']] = (int) $p['itbis_aplica'];
        }
    }
    $servicio = cot_productoServicio();
    $itbisServicio = $servicio
        ? (int) qVal("SELECT itbis_aplica FROM productos WHERE id = ?", [(int) $servicio['id']])
        : null;

    foreach ($lineas as $i => $l) {
        $pid = (int) ($l['producto_id'] ?? 0);
        if ($pid > 0 && isset($mapa[$pid]))      $lineas[$i]['itbis_aplica'] = $mapa[$pid];
        elseif ($pid <= 0 && $itbisServicio !== null) $lineas[$i]['itbis_aplica'] = $itbisServicio;
        // Si no se puede resolver (producto borrado, servicio sin configurar) se
        // deja lo que venía: es mejor guardar la cotización que perderla.
    }
    return $lineas;
}

/**
 * Crea o actualiza una cotización con sus líneas.
 * @return int id de la cotización
 */
function cot_guardar(array $datos, array $lineas): int
{
    if (!cot_disponible()) throw new RuntimeException('Falta aplicar la migración de cotizaciones.');

    $lineas = cot_resolverItbis($lineas);

    $id        = (int) ($datos['id'] ?? 0);
    $clienteId = (int) ($datos['cliente_id'] ?? 0);
    if (!$clienteId || !qVal("SELECT 1 FROM clientes WHERE id = ? AND activo = 1", [$clienteId])) {
        throw new RuntimeException('Selecciona un cliente válido.');
    }

    $t = cot_totales($lineas, (float) ($datos['descuento'] ?? 0));
    if (!$t['lineas']) throw new RuntimeException('Agrega al menos una línea con cantidad y precio.');

    $monedaId = (int) ($datos['moneda_id'] ?? 0) ?: (int) monedaBase()['id'];
    $tasa     = max(0.000001, (float) ($datos['tasa_cambio'] ?? mon_tasa($monedaId)));
    $cfg      = cot_config();
    $validez  = max(1, min(365, (int) ($datos['validez_dias'] ?? $cfg['validez_dias'])));
    $fecha    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($datos['fecha'] ?? '')) ? $datos['fecha'] : date('Y-m-d');

    $sid = (int) ($datos['sucursal_id'] ?? 0) ?: (int) (current_sucursal_id() ?? 0);
    if (!$sid) throw new RuntimeException('Selecciona una sucursal antes de cotizar.');

    $fila = [
        'cliente_id'   => $clienteId,
        'sucursal_id'  => $sid,
        'fecha'        => $fecha,
        'validez_dias' => $validez,
        'vence'        => date('Y-m-d', strtotime($fecha . ' +' . $validez . ' days')),
        'moneda_id'    => $monedaId,
        'tasa_cambio'  => $tasa,
        'subtotal'     => $t['subtotal'],
        'descuento'    => $t['descuento'],
        'itbis'        => $t['itbis'],
        'total'        => $t['total'],
        'total_base'   => mon_aBase($t['total'], $tasa),
        'condiciones'  => trim((string) ($datos['condiciones'] ?? '')) ?: ($cfg['condiciones'] ?: null),
        'campos_extra' => cot_camposExtraJson($datos['campos_extra'] ?? []),
        'notas'        => mb_substr(trim((string) ($datos['notas'] ?? '')), 0, 500) ?: null,
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    return tx(function () use ($id, $fila, $t, $cfg) {
        if ($id > 0) {
            $actual = qOne("SELECT estado FROM cotizaciones WHERE id = ?", [$id]);
            if (!$actual) throw new RuntimeException('Cotización no encontrada.');
            if (!cot_editable($actual)) throw new RuntimeException('Una cotización facturada ya no se puede editar.');
            dbUpdate('cotizaciones', $fila, 'id = ?', [$id]);
            q("DELETE FROM cotizacion_detalles WHERE cotizacion_id = ?", [$id]);
        } else {
            $fila['numero']     = nextNumero('cotizaciones', 'numero', $cfg['prefijo'] ?: 'COT');
            $fila['estado']     = 'borrador';
            $fila['usuario_id'] = (int) current_user()['id'];
            $id = dbInsert('cotizaciones', $fila);
        }

        foreach ($t['lineas'] as $l) {
            $l['cotizacion_id'] = $id;
            dbInsert('cotizacion_detalles', $l);
        }
        return $id;
    });
}

/**
 * Normaliza los valores de los campos propios contra su definición.
 *
 * Solo sobreviven las claves que la configuración declara: si mañana se quita
 * el campo «Orden de compra», su valor deja de arrastrarse en las cotizaciones
 * que se sigan editando.
 */
function cot_camposExtraJson($valores): ?string
{
    $definidos = cot_campos();
    if (!$definidos || !is_array($valores)) return null;

    $out = [];
    foreach ($definidos as $c) {
        $v = trim((string) ($valores[$c['clave']] ?? ''));
        if ($v !== '') $out[$c['clave']] = mb_substr($v, 0, 180);
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

/** Cotización con su cliente y su moneda. */
function cot_obtener(int $id): ?array
{
    return qOne(
        "SELECT c.*, cl.nombre AS cliente, cl.rnc_cedula, cl.email AS cliente_email,
                cl.telefono AS cliente_telefono, cl.direccion AS cliente_direccion,
                s.nombre AS sucursal, mo.codigo AS moneda_codigo, mo.simbolo AS moneda_simbolo,
                mo.es_base AS moneda_es_base, u.nombre AS vendedor, u.apellido AS vendedor_ape
           FROM cotizaciones c
           JOIN clientes cl   ON cl.id = c.cliente_id
           JOIN sucursales s  ON s.id = c.sucursal_id
           LEFT JOIN monedas mo ON mo.id = c.moneda_id
           LEFT JOIN usuarios u ON u.id = c.usuario_id
          WHERE c.id = ?", [$id]
    );
}

/** Líneas de una cotización. */
function cot_lineas(int $id): array
{
    return qAll(
        "SELECT cd.*, p.codigo AS sku
           FROM cotizacion_detalles cd
           LEFT JOIN productos p ON p.id = cd.producto_id
          WHERE cd.cotizacion_id = ?
          ORDER BY cd.orden, cd.id", [$id]);
}

/**
 * Convierte una cotización en factura, con lo que el cliente se lleve.
 *
 * No duplica ni una línea de la lógica de ventas: arma el carrito y llama a
 * `registrarVentaPOS()`, que es lo que ya asigna NCF, mueve stock, cuadra caja,
 * calcula comisiones y emite el e-CF. Lo que aporta esta función son tres cosas
 * que la venta no puede saber:
 *
 * · QUÉ SE LLEVA EL CLIENTE. Rara vez es todo. `$seleccion` dice cuánto de cada
 *   línea entra en la factura; lo que queda fuera NO se factura y se guarda
 *   como descartado. La cotización se cierra igual: deja por escrito qué se
 *   ofreció y qué no se vendió, que es información de negocio.
 *
 * · EL PRECIO PACTADO. Si el de lista subió, manda el de la cotización. Se pasa
 *   el precio ya NETO del descuento de esa línea, porque la venta solo entiende
 *   un descuento global.
 *
 * · EL DESCUENTO GLOBAL, A PRORRATA. Si se factura la mitad de la cotización,
 *   aplicar el descuento global entero regalaría el doble de lo pactado.
 *
 * @param array|null $seleccion [cotizacion_detalle_id => cantidad]. null = todo.
 * @return array resultado de registrarVentaPOS
 */
function cot_facturar(int $id, int $metodoPagoId, ?array $seleccion = null): array
{
    $c = cot_obtener($id);
    if (!$c) throw new RuntimeException('Cotización no encontrada.');
    if ($c['estado'] === 'facturada') throw new RuntimeException('Esta cotización ya se facturó.');

    $lineas = cot_lineas($id);
    if (!$lineas) throw new RuntimeException('La cotización no tiene líneas.');

    $tasa      = max(0.000001, (float) $c['tasa_cambio']);
    $servicio  = cot_productoServicio();
    $cart      = [];
    $facturado = [];        // detalle_id => cantidad, para dejar constancia
    $netoSel   = 0.0;       // importe neto que sí entra en la factura
    $netoTotal = 0.0;       // importe neto de la cotización completa

    foreach ($lineas as $l) {
        $cotizada = (float) $l['cantidad'];
        $neto     = (float) $l['subtotal'];       // ya descontada la línea
        $netoTotal += $neto;

        // Sin selección se factura todo; con selección, solo lo pedido.
        $cant = $seleccion === null
            ? $cotizada
            : round((float) ($seleccion[(int) $l['id']] ?? 0), 3);

        if ($cant <= 0) continue;
        if ($cant > $cotizada + 0.0001) {
            throw new RuntimeException('No se puede facturar ' . qty($cant) . ' de «' . $l['descripcion']
                . '»: la cotización solo tiene ' . qty($cotizada) . '.');
        }

        // Producto real, o el genérico de servicio para un concepto libre.
        $pid = (int) ($l['producto_id'] ?? 0);
        $descripcion = null;
        if (!$pid) {
            if (!$servicio) {
                throw new RuntimeException('La línea «' . $l['descripcion'] . '» no tiene producto del catálogo. '
                    . 'Para facturar conceptos libres, elige el producto de servicio en '
                    . 'Cotizaciones → Ajustes.');
            }
            $pid = (int) $servicio['id'];
            $descripcion = $l['descripcion'];     // lo que se lee en la factura
        }

        // Precio unitario NETO: lo pactado ya con su descuento de línea dentro.
        $precioNeto = $cotizada > 0 ? round($neto / $cotizada, 4) : 0.0;
        $netoSel   += round($precioNeto * $cant, 2);

        $item = [
            'id'     => $pid,
            'cant'   => $cant,
            'precio' => mon_aBase($precioNeto, $tasa),
        ];
        if ($descripcion !== null) $item['descripcion'] = $descripcion;
        $cart[] = $item;

        $facturado[(int) $l['id']] = $cant;
    }

    if (!$cart) throw new RuntimeException('Marca al menos una línea con cantidad para facturar.');

    // El descuento global se reparte según la porción que se factura.
    $proporcion = $netoTotal > 0 ? min(1.0, $netoSel / $netoTotal) : 1.0;
    $descuento  = round((float) $c['descuento'] * $proporcion, 2);

    $sid    = (int) $c['sucursal_id'];
    $uid    = (int) current_user()['id'];
    $sesion = cajaSesionAbierta($sid, $uid);

    $r = registrarVentaPOS([
        'cart'           => $cart,
        'descuento'      => mon_aBase($descuento, $tasa),
        'cliente_id'     => (int) $c['cliente_id'],
        'comprobante'    => !empty($c['rnc_cedula']) ? 'credito_fiscal' : 'consumidor',
        'metodo_pago_id' => $metodoPagoId,
        'canal'          => 'Cotización',
    ], [
        'sid' => $sid, 'uid' => $uid, 'sesion' => $sesion,
        'puede_muestra' => false,
        'precios_pactados' => true,     // ← autorizado aquí, con precios leídos de la base
    ]);

    // Se registra QUÉ se facturó de cada línea. Las que quedaron en cero son lo
    // que el cliente no se llevó, y así se puede leer meses después.
    foreach ($lineas as $l) {
        dbUpdate('cotizacion_detalles',
                 ['cantidad_facturada' => $facturado[(int) $l['id']] ?? 0],
                 'id = ?', [(int) $l['id']]);
    }

    dbUpdate('cotizaciones', [
        'estado'     => 'facturada',
        'venta_id'   => (int) $r['id'],
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    $parcial = count($facturado) < count($lineas)
        || abs($netoSel - $netoTotal) > 0.01;
    audit('cotizaciones', 'editar',
          "Cotización {$c['numero']} facturada como {$r['numero']}" . ($parcial ? ' (parcial)' : ''),
          ['tabla' => 'cotizaciones', 'registro_id' => $id]);

    return $r + ['parcial' => $parcial];
}

/* ============================================================
 *  PDF
 * ============================================================ */

/**
 * Documento PDF de la cotización, con la marca de la empresa.
 * Usa el mismo motor (Dompdf) y la misma hoja de estilos que la factura.
 */
/**
 * La cotización en PDF, en HORIZONTAL.
 *
 * El apaisado no es capricho: una cotización se lee comparando renglones
 * —cantidad, precio, descuento, importe— y en vertical esas columnas se
 * estrujan mientras sobra media hoja en blanco. Con el A4 tumbado hay 766 pt
 * de ancho útil, así que los datos de cabecera caben en tres cajas en fila en
 * vez de apilarse, y la tabla respira.
 *
 * Dompdf entiende CSS 2.1: el layout va con tablas, no con flexbox.
 */
function cot_pdf_html(array $c, array $lineas): string
{
    $cfg     = cot_config();
    $esBase  = (int) ($c['moneda_es_base'] ?? 1) === 1;
    $moneda  = fn(float $v) => $esBase ? money($v) : ($c['moneda_simbolo'] . ' ' . number_format($v, 2, '.', ','));
    // En las celdas de la tabla el símbolo sobra: se repite en cada fila, parte
    // los números en dos líneas y no aporta nada que los totales no digan ya.
    $celda   = fn(float $v) => $esBase ? money($v, false) : number_format($v, 2, '.', ',');
    $vencida = cot_estadoVisible($c) === 'vencida';
    $esc     = fn($x) => htmlspecialchars((string) $x);
    $color   = marca_app();

    // Columnas opcionales. Un negocio que vende a consumidor final no quiere el
    // ITBIS desglosado, y la de descuento solo estorba si nadie la usa: además
    // de estar activada tiene que haber algún descuento real.
    $verItbis = !empty($cfg['mostrar_itbis']);
    $verSku   = !empty($cfg['mostrar_sku']);
    $verDesc  = !empty($cfg['mostrar_descuento'])
                && array_sum(array_map(fn($l) => (float) ($l['descuento_monto'] ?? 0), $lineas)) > 0.001;

    $h = pdf_brand_header('COTIZACIÓN', $c['numero'], null, true);   // compacta: apaisado

    /* ---------------------------------------------------------------------
     *  Fila de cabecera: cliente, documento y —si el negocio los definió— sus
     *  campos propios. Son celdas de una misma fila para que midan igual sin
     *  fijar alturas, que en Dompdf recortan en vez de estirar.
     * ------------------------------------------------------------------ */
    $extra = '';
    $vals  = cot_camposValores($c);
    foreach (cot_campos() as $cp) {
        $v = $vals[$cp['clave']] ?? '';
        if ($v === '') continue;
        $extra .= '<tr><td class="dato" style="color:#8A93A5;">' . $esc($cp['etiqueta']) . '</td>'
                . '<td class="dato num"><strong>' . $esc($v) . '</strong></td></tr>';
    }

    $cajaCliente = '<div class="box-tit">Cotizado a</div>'
        . '<div class="nombre-fuerte">' . $esc($c['cliente']) . '</div>'
        . '<div class="dato">'
        . (!empty($c['rnc_cedula'])        ? 'RNC/Cédula ' . $esc($c['rnc_cedula']) . '<br>' : '')
        . (!empty($c['cliente_direccion']) ? $esc($c['cliente_direccion']) . '<br>' : '')
        . (!empty($c['cliente_telefono'])  ? $esc($c['cliente_telefono']) : '')
        . '</div>';

    $cajaDoc = '<div class="box-tit">Documento</div>'
        . '<div class="qr-encf">' . $esc($c['numero']) . '</div>'
        . '<table style="width:100%; margin-top:6px;">'
        . '<tr><td class="dato" style="color:#8A93A5;">Fecha</td><td class="dato num"><strong>' . fechaCorta($c['fecha']) . '</strong></td></tr>'
        . '<tr><td class="dato" style="color:#8A93A5;">Válida hasta</td><td class="dato num"><strong>' . fechaCorta($c['vence'])
        . ($vencida ? ' <span style="color:#B45309;">(vencida)</span>' : '') . '</strong></td></tr>'
        . '<tr><td class="dato" style="color:#8A93A5;">Sucursal</td><td class="dato num"><strong>' . $esc($c['sucursal']) . '</strong></td></tr>'
        . (!$esBase ? '<tr><td class="dato" style="color:#8A93A5;">Moneda</td><td class="dato num"><strong>'
            . $esc($c['moneda_codigo']) . ' · tasa '
            . rtrim(rtrim(number_format((float) $c['tasa_cambio'], 4, '.', ','), '0'), '.') . '</strong></td></tr>' : '')
        . '</table>';

    $h .= '<table style="width:100%; margin-bottom:13px; border-spacing:0;"><tr>';
    if ($extra !== '') {
        $h .= '<td class="box box-acento" style="border-left-color:' . $color . '; width:35%;">' . $cajaCliente . '</td>'
            . '<td style="width:2%; border:0;"></td>'
            . '<td class="box" style="width:31%;">' . $cajaDoc . '</td>'
            . '<td style="width:2%; border:0;"></td>'
            . '<td class="box" style="width:30%;">'
            . '<div class="box-tit">Referencias</div><table style="width:100%;">' . $extra . '</table></td>';
    } else {
        $h .= '<td class="box box-acento" style="border-left-color:' . $color . '; width:49%;">' . $cajaCliente . '</td>'
            . '<td style="width:2%; border:0;"></td>'
            . '<td class="box" style="width:49%;">' . $cajaDoc . '</td>';
    }
    $h .= '</tr></table>';

    /* ------------------------------- Líneas ---------------------------- */
    $anchoDesc = $verDesc ? ($verItbis ? '44%' : '52%') : ($verItbis ? '52%' : '60%');
    $h .= '<table class="tbl"><thead><tr>'
        . '<th style="background:' . $color . '; width:' . $anchoDesc . ';">Descripción</th>'
        . '<th style="background:' . $color . '; width:8%;" class="num">Cant.</th>'
        . '<th style="background:' . $color . '; width:14%;" class="num">Precio</th>'
        . ($verDesc  ? '<th style="background:' . $color . '; width:10%;" class="num">Desc.</th>' : '')
        . ($verItbis ? '<th style="background:' . $color . '; width:11%;" class="num">ITBIS</th>' : '')
        . '<th style="background:' . $color . '; width:15%;" class="num">Importe</th>'
        . '</tr></thead><tbody>';
    foreach ($lineas as $l) {
        $dm  = (float) ($l['descuento_monto'] ?? 0);
        $pct = (float) ($l['descuento_pct'] ?? 0);
        $h .= '<tr><td><strong>' . $esc($l['descripcion']) . '</strong>'
            . ($verSku && !empty($l['sku']) ? '  <span class="sku">' . $esc($l['sku']) . '</span>' : '')
            . (empty($l['producto_id']) ? '  <span class="sku">servicio</span>' : '')
            . '</td>'
            . '<td class="num">' . qty($l['cantidad']) . '</td>'
            . '<td class="num">' . $celda((float) $l['precio_unitario']) . '</td>'
            . ($verDesc ? '<td class="num">' . ($dm > 0
                    ? '−' . $celda($dm)
                      . ($pct > 0 ? '  <span class="sku">' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%</span>' : '')
                    : '—') . '</td>' : '')
            . ($verItbis ? '<td class="num">' . $celda((float) $l['itbis']) . '</td>' : '')
            . '<td class="num"><strong>' . $celda((float) $l['subtotal']) . '</strong></td></tr>';
    }
    $h .= '</tbody></table>';

    /* ------------------- Cierre: condiciones | notas | totales ---------- */
    $condiciones = $c['condiciones'] ?: $cfg['condiciones'];
    $cajaCond = $condiciones
        ? '<div class="box"><div class="box-tit">Condiciones</div>'
          . '<div class="qr-nota">' . nl2br($esc($condiciones)) . '</div></div>'
        : '';
    $cajaNotas = !empty($c['notas'])
        ? '<div class="box"><div class="box-tit">Notas</div>'
          . '<div class="qr-nota">' . $esc($c['notas']) . '</div></div>'
        : '';

    $der = '<table class="tot">'
        . '<tr><td class="lbl">Subtotal</td><td class="val">' . $moneda((float) $c['subtotal']) . '</td></tr>'
        . ((float) $c['descuento'] > 0 ? '<tr><td class="lbl">Descuento</td><td class="val" style="color:#BE123C;">−' . $moneda((float) $c['descuento']) . '</td></tr>' : '')
        . ($verItbis ? '<tr><td class="lbl">ITBIS</td><td class="val">' . $moneda((float) $c['itbis']) . '</td></tr>' : '')
        . '</table>'
        . '<div class="total-bloque" style="background:' . $color . ';"><table style="width:100%"><tr>'
        . '<td class="lbl">TOTAL</td><td class="val">' . $moneda((float) $c['total']) . '</td>'
        . '</tr></table></div>';
    if (!$esBase) {
        $der .= '<p class="qr-nota" style="text-align:right; margin-top:5px;">Equivalente: ' . money((float) $c['total_base']) . '</p>';
    }

    // En apaisado los tres bloques caben en una fila; el total queda a la
    // derecha, que es donde se busca, y sin dejar una franja vacía debajo.
    $h .= '<table style="width:100%; margin-top:13px; border-spacing:0;"><tr>'
        . '<td style="width:40%; vertical-align:top;">' . $cajaCond . '</td>'
        . '<td style="width:2%;"></td>'
        . '<td style="width:29%; vertical-align:top;">' . $cajaNotas . '</td>'
        . '<td style="width:2%;"></td>'
        . '<td style="width:27%; vertical-align:top;">' . $der . '</td>'
        . '</tr></table>';

    // El mensaje de cierre va DENTRO de la banda de pie, no como párrafo suelto.
    // Suelto empujaba la página: con seis líneas el documento se partía en dos
    // solo para llevarse una frase de cortesía a la segunda hoja.
    $h .= pdf_pie(
        ($cfg['mensaje_cierre'] ? '<span style="color:#4B5563;">' . $esc($cfg['mensaje_cierre']) . '</span><br>' : '')
        . 'Válida hasta el ' . fechaCorta($c['vence'])
        . '. Los precios pueden variar después de esa fecha.'
        . (!$esBase ? ' El importe en pesos se calculará a la tasa vigente el día de la facturación.' : '')
        . ($cfg['pie'] ? '<br>' . $esc($cfg['pie']) : '')
    );

    return $h;
}

/**
 * Envía la cotización al cliente por correo, con el PDF adjunto.
 * Nunca lanza excepción: un correo que falla no puede tumbar la pantalla.
 *
 * @return array{ok:bool, error:?string}
 */
function cot_enviarCorreo(int $id): array
{
    $c = cot_obtener($id);
    if (!$c) return ['ok' => false, 'error' => 'Cotización no encontrada.'];

    $para = trim((string) $c['cliente_email']);
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El cliente no tiene un correo válido en su ficha.'];
    }
    if (!mail_configurado()) {
        return ['ok' => false, 'error' => 'El correo no está configurado (falta la clave de Resend).'];
    }

    $emp     = $GLOBALS['empresa'] ?? [];
    $esBase  = (int) ($c['moneda_es_base'] ?? 1) === 1;
    $totalTx = $esBase ? money((float) $c['total'])
                       : $c['moneda_simbolo'] . ' ' . number_format((float) $c['total'], 2, '.', ',');

    $cuerpo = '<p>Hola <strong>' . e($c['cliente']) . '</strong>,</p>'
        . '<p>Le enviamos la cotización <strong>' . e($c['numero']) . '</strong> por un total de <strong>'
        . e($totalTx) . '</strong>.</p>'
        . '<p>Es válida hasta el <strong>' . e(fechaCorta($c['vence'])) . '</strong>. '
        . 'Encontrará el detalle en el archivo adjunto.</p>'
        . '<p>Cualquier duda, responda a este correo'
        . (!empty($emp['telefono']) ? ' o llámenos al ' . e($emp['telefono']) : '') . '.</p>';

    $html = mail_plantilla('Cotización ' . $c['numero'], $cuerpo, $emp,
                           'Cotización ' . $c['numero'] . ' · válida hasta ' . fechaCorta($c['vence']));

    $adjunto = null;
    if (function_exists('pdf_bytes')) {
        try {
            $adjunto = [[
                'filename' => 'cotizacion_' . $c['numero'] . '.pdf',
                'content'  => base64_encode(pdf_bytes(cot_pdf_html($c, cot_lineas($id)), 'landscape')),
            ]];
        } catch (Throwable $e) {
            $adjunto = null;   // sin adjunto es peor, pero mejor que no enviar nada
        }
    }

    $r = mail_enviar($para, 'Cotización ' . $c['numero'] . ' · ' . ($emp['nombre'] ?? APP_NAME), $html,
                     $adjunto ? ['attachments' => $adjunto] : []);

    mail_registrar(null, 'cotizacion', $para, 'Cotización ' . $c['numero'], $r);

    if ($r['ok'] && $c['estado'] === 'borrador') {
        dbUpdate('cotizaciones', ['estado' => 'enviada', 'enviada_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    } elseif ($r['ok']) {
        dbUpdate('cotizaciones', ['enviada_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    return ['ok' => $r['ok'], 'error' => $r['error']];
}

/** Mensaje de WhatsApp para mandarle la cotización al cliente. */
function cot_textoWhatsapp(array $c): string
{
    $emp    = $GLOBALS['empresa'] ?? [];
    $esBase = (int) ($c['moneda_es_base'] ?? 1) === 1;
    $total  = $esBase ? money((float) $c['total'])
                      : $c['moneda_simbolo'] . ' ' . number_format((float) $c['total'], 2, '.', ',');

    return 'Hola ' . $c['cliente'] . ', le escribo de ' . ($emp['nombre'] ?? APP_NAME) . '. '
         . 'Le preparé la cotización ' . $c['numero'] . ' por ' . $total . ', válida hasta el '
         . fechaCorta($c['vence']) . '. Se la envío por aquí. ¿Le parece bien?';
}
