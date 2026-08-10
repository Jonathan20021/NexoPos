<?php
/**
 * Emisión de Comprobantes Fiscales Electrónicos (e-CF).
 *
 * Une las tres piezas: toma una venta o una devolución del sistema, la traduce
 * a la estructura del proveedor (ecf_trama.php), la envía (ecf_api.php) y
 * guarda el resultado en `ecf_documentos`.
 *
 * ---------------------------------------------------------------------------
 *  EL e-CF CONVIVE CON EL NCF PREIMPRESO
 * ---------------------------------------------------------------------------
 *  Nada de esto se dispara solo. Con `ecf_config.activo = 0` —el valor de
 *  fábrica— el POS factura exactamente como siempre y estas funciones no se
 *  ejecutan. Pasar de B01/B02 a E31/E32 es un corte fiscal con fecha, hecho
 *  cuando la DGII apruebe la certificación y con los rangos autorizados
 *  cargados. Hasta entonces esto se usa solo desde la consola de pruebas.
 *
 * ---------------------------------------------------------------------------
 *  DÓNDE VA EL DESCUENTO DE LA VENTA
 * ---------------------------------------------------------------------------
 *  NexoPOS guarda el descuento en la CABECERA de la venta y lo prorratea sobre
 *  las líneas para el ITBIS. El e-CF tiene una sección para descuentos globales
 *  (DERE), pero exige declarar a qué tasa de ITBIS afecta (Tabla 17), y una
 *  venta con productos al 18%, al 16% y exentos no cabe en un solo indicador.
 *
 *  Por eso el descuento se prorratea a nivel de línea, igual que ya se hace con
 *  el ITBIS: cada ítem lleva su DescuentoMonto y la suma de los MontoItem da
 *  exactamente `subtotal − descuento`. Así cuadra la tolerancia global que pide
 *  el manual (§5.2.ii) sin inventar a qué tasa pertenece el descuento.
 */

require_once __DIR__ . '/ecf_catalogos.php';
require_once __DIR__ . '/ecf_trama.php';
require_once __DIR__ . '/ecf_api.php';

/** ¿Está encendida la facturación electrónica? */
function ecfActivo(): bool
{
    return (int) (ecfConfig()['activo'] ?? 0) === 1;
}

/**
 * Segundos que el POS espera al proveedor antes de dejar el comprobante en cola.
 *
 * Corto a propósito. Detrás de esta llamada hay un cajero con un cliente
 * delante: el envío por defecto usa 90 s, que en el mostrador es una eternidad.
 * El e-NCF ya está impreso y el documento ya está registrado, así que rendirse
 * pronto no pierde nada — solo aplaza la transmisión a la cola.
 */
const ECF_TIMEOUT_POS = 8;

/* ============================================================
 *  SECUENCIA ELECTRÓNICA
 * ============================================================ */

/**
 * Consume y devuelve el siguiente e-NCF del tipo indicado, o null si no hay.
 *
 * Delega en siguienteNCF(), que es la ÚNICA puerta por la que se consumen
 * secuencias, electrónicas o preimpresas. Tener dos caminos que incrementen el
 * mismo contador es la receta para un salto o un número repetido.
 *
 * Debe llamarse dentro de una transacción (el FOR UPDATE vive allí).
 */
function ecfSiguienteENCF(string $tipoEcf): ?string
{
    return siguienteNCF('E' . $tipoEcf);
}

/** Fecha de vencimiento de la secuencia autorizada (obligatoria en 31 y 33). */
function ecfVencimientoSecuencia(string $tipoEcf): ?string
{
    return qVal("SELECT vencimiento FROM ncf_secuencias WHERE tipo = ?", ['E' . $tipoEcf]) ?: null;
}

/** Diagnóstico de una secuencia: si no se puede emitir, explica por qué. */
function ecfEstadoSecuencia(string $tipoEcf): array
{
    $row = qOne("SELECT * FROM ncf_secuencias WHERE tipo = ?", ['E' . $tipoEcf]);
    if (!$row) {
        return ['ok' => false, 'mensaje' => 'No existe la secuencia E' . $tipoEcf . '.'];
    }
    if (!(int) $row['activo']) {
        return ['ok' => false, 'mensaje' => 'La secuencia E' . $tipoEcf . ' está desactivada.',
                'fila' => $row];
    }
    if ((int) $row['secuencia_actual'] > (int) $row['secuencia_hasta']) {
        return ['ok' => false, 'mensaje' => 'La secuencia E' . $tipoEcf . ' se agotó: hay que solicitar un rango nuevo a la DGII.',
                'fila' => $row];
    }
    if (!empty($row['vencimiento']) && $row['vencimiento'] < date('Y-m-d')) {
        return ['ok' => false, 'mensaje' => 'La secuencia E' . $tipoEcf . ' venció el ' . fechaCorta($row['vencimiento']) . '.',
                'fila' => $row];
    }
    $quedan = (int) $row['secuencia_hasta'] - (int) $row['secuencia_actual'] + 1;
    return ['ok' => true, 'quedan' => $quedan, 'fila' => $row,
            'mensaje' => 'Quedan ' . number_format($quedan) . ' comprobantes disponibles.'];
}

/* ============================================================
 *  DATOS DEL EMISOR
 * ============================================================ */

/**
 * Sección EMIS a partir de la empresa y la sucursal que factura.
 *
 * La dirección y el municipio se toman de la sucursal cuando los tiene: el e-CF
 * declara «el domicilio DONDE SE REALIZA LA FACTURACIÓN» (PT-001), que en una
 * operación multi-sucursal no es la casa matriz.
 */
function ecfSeccionEmisor(?int $sucursalId, array $extra = []): array
{
    $empresa  = $GLOBALS['empresa'] ?? [];
    $sucursal = $sucursalId ? qOne("SELECT * FROM sucursales WHERE id = ?", [$sucursalId]) : null;

    $telefonos = array_values(array_filter([
        trim((string) ($sucursal['telefono'] ?? '')),
        trim((string) ($empresa['telefono'] ?? '')),
    ]));
    $telefonos = array_slice(array_unique($telefonos), 0, 3);  // el manual admite hasta 3

    return [
        'RNCEmisor'                  => preg_replace('/\D+/', '', (string) ($empresa['rnc'] ?? '')),
        'RazonSocialEmisor'          => $empresa['nombre'] ?? '',
        'NombreComercial'            => $empresa['ecf_nombre_comercial'] ?? '',
        'Sucursal'                   => $sucursal['nombre'] ?? '',
        'DireccionEmisor'            => $sucursal['direccion'] ?: ($empresa['direccion'] ?? ''),
        'Municipio'                  => $sucursal['ecf_municipio'] ?: ($empresa['ecf_municipio'] ?? ''),
        'Provincia'                  => $sucursal['ecf_provincia'] ?: ($empresa['ecf_provincia'] ?? ''),
        'TelefonoEmisor'             => ecfRepetible($telefonos),
        'CorreoEmisor'               => $empresa['email'] ?? '',
        'WebSite'                    => $empresa['ecf_website'] ?? '',
        'ActividadEconomica'         => $empresa['ecf_actividad_economica'] ?? '',
        'CodigoVendedor'             => $extra['vendedor'] ?? '',
        'NumeroFacturaInterna'       => $extra['numero_interno'] ?? '',
        'NumeroPedidoInterno'        => '',
        'ZonaVenta'                  => '',
        'RutaVenta'                  => '',
        'InformacionAdicionalEmisor' => '',
    ];
}

/** Sección COMP a partir de un cliente del sistema. */
function ecfSeccionComprador(?array $cliente, string $tipoEcf): array
{
    $id = ecfIdentificacionComprador(
        $cliente['rnc_cedula'] ?? null,
        (int) ($cliente['tipo_id'] ?? 1)
    );

    $comp = [
        'RNCComprador'                  => $id['rnc'] ?? '',
        'RazonSocialComprador'          => $cliente['nombre'] ?? '',
        'ContactoComprador'             => $cliente['telefono'] ?? '',
        'CorreoComprador'               => $cliente['email'] ?? '',
        'DireccionComprador'            => $cliente['direccion'] ?? '',
        'MunicipioComprador'            => '',
        'ProvinciaComprador'            => '',
        'FechaEntrega'                  => '',
        'ContactoEntrega'               => '',
        'DireccionEntrega'              => '',
        'TelefonoAdicional'             => '',
        'FechaOrdenCompra'              => '',
        'NumeroOrdenCompra'             => '',
        'CodigoInternoComprador'        => $cliente['codigo'] ?? '',
        'ResponsablePago'               => '',
        'Informacionadicionalcomprador' => '',
    ];

    // El 31 no tiene campo para identificación extranjera; el resto sí.
    if ($tipoEcf !== '31') {
        $comp['IdentificadorExtranjero'] = $id['extranjero'] ?? '';
    }
    return $comp;
}

/* ============================================================
 *  CONSTRUCCIÓN DEL DOCUMENTO
 * ============================================================ */

/**
 * Traduce una venta a la estructura del e-CF.
 *
 * @param int         $ventaId
 * @param string|null $encf    e-NCF ya asignado; null para previsualizar sin consumir secuencia.
 */
function ecfDocumentoDeVenta(int $ventaId, ?string $encf = null): array
{
    $venta = qOne("SELECT * FROM ventas WHERE id = ?", [$ventaId]);
    if (!$venta) throw new RuntimeException('La venta no existe.');

    $tipo = $venta['ecf_tipo'] ?: ecfTipoDesdeComprobante((string) $venta['tipo_comprobante']);
    $cliente = $venta['cliente_id']
        ? qOne("SELECT * FROM clientes WHERE id = ?", [(int) $venta['cliente_id']])
        : null;

    $detalles = qAll(
        "SELECT vd.*, p.tipo AS producto_tipo, p.ecf_indicador_facturacion AS prod_indicador,
                p.ecf_impuesto_adicional AS prod_impuesto, u.ecf_codigo AS unidad_ecf
           FROM venta_detalles vd
           LEFT JOIN productos p ON p.id = vd.producto_id
           LEFT JOIN unidades  u ON u.id = p.unidad_id
          WHERE vd.venta_id = ?
          ORDER BY vd.id",
        [$ventaId]
    );
    if (!$detalles) throw new RuntimeException('La venta no tiene líneas de detalle.');

    // Prorrateo del descuento de cabecera (ver cabecera del archivo).
    $subtotal  = (float) $venta['subtotal'];
    $descuento = (float) $venta['descuento'];
    $factor    = $subtotal > 0 ? ($subtotal - $descuento) / $subtotal : 1.0;

    $items = [];
    $acumuladoDescuento = 0.0;
    $ultima = count($detalles) - 1;

    foreach ($detalles as $i => $d) {
        $base = (float) $d['subtotal'];

        // El descuento de la última línea absorbe el redondeo de las anteriores,
        // así la suma de descuentos da EXACTAMENTE el de la cabecera y el total
        // del comprobante cuadra al centavo con la venta registrada.
        if ($i === $ultima) {
            $descLinea = round($descuento - $acumuladoDescuento, 2);
        } else {
            $descLinea = round($base * (1 - $factor), 2);
            $acumuladoDescuento += $descLinea;
        }
        $descLinea = max(0.0, min($descLinea, $base));

        // Indicador de facturación: se usa el congelado en la línea; si la venta
        // es anterior a la migración, se cae al del producto y, en último caso,
        // se deduce de si la línea llevó ITBIS.
        $indicador = $d['ecf_indicador_facturacion'] ?? $d['prod_indicador'] ?? null;
        if ($indicador === null || $indicador === '') {
            $indicador = ((float) $d['itbis'] > 0)
                ? ecfIndicadorDesdeTasa((float) setting('itbis_tasa', DEFAULT_ITBIS))
                : 4;
        }

        $bienServicio = $d['ecf_bien_servicio']
            ?: ecfBienServicioDesdeProducto($d['producto_tipo'] ?? 'producto');
        $unidad = $d['ecf_unidad_medida'] ?? $d['unidad_ecf'] ?? 43;
        $impuestoAdicional = $d['ecf_impuesto_adicional'] ?? $d['prod_impuesto'] ?? null;

        $item = [
            'NumeroLinea'            => $i + 1,
            'TipoCodigoItem'         => '',
            'IndicadorFacturacion'   => (string) (int) $indicador,
            'NombreItem'             => $d['descripcion'],
            'IndicadorBienoServicio' => (string) (int) $bienServicio,
            'DescripcionItem'        => '',
            'CantidadItem'           => ecfCantidad($d['cantidad']),
            'UnidadMedida'           => (string) (int) $unidad,
            'PrecioUnitarioItem'     => ecfPrecio($d['precio_unitario']),
            'DescuentoMonto'         => $descLinea > 0 ? ecfMonto($descLinea) : '',
            // Subdescuento en monto: el campo de porcentaje va vacío, como en la
            // trama oficial `[%|10.00|37500.00;$||50000.00]`.
            'SubDescuento'           => $descLinea > 0 ? ecfRepetible([['$', '', ecfMonto($descLinea)]]) : '',
            'MontoItem'              => ecfMonto($base - $descLinea),
        ];
        if ($impuestoAdicional) {
            $item['TipoImpuesto'] = ecfRepetible([$impuestoAdicional]);
        }
        $items[] = $item;
    }

    // Formas de pago. Si la venta no tiene desglose (ventas antiguas), se omite
    // la sección: FPAG es opcional y es preferible callar a inventar.
    $pagos = qAll(
        "SELECT vp.monto, mp.dgii_tipo_pago, mp.es_credito
           FROM venta_pagos vp
           JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
          WHERE vp.venta_id = ?
          ORDER BY vp.id",
        [$ventaId]
    );
    $fpag = [];
    foreach ($pagos as $p) {
        if ((float) $p['monto'] <= 0) continue;
        $fpag[] = [
            'FormaPago' => (string) ecfFormaPagoDesde607((int) $p['dgii_tipo_pago']),
            'MontoPago' => ecfMonto($p['monto']),
        ];
    }
    $fpag = array_slice($fpag, 0, 7);   // el manual admite hasta 7

    // Tipo de pago (Tabla 5). Una muestra a RD$0.00 es una entrega gratuita.
    $esCredito = false;
    foreach ($pagos as $p) if ((int) $p['es_credito'] === 1) $esCredito = true;
    $tipoPago = (float) $venta['total'] <= 0 ? 3 : ($esCredito ? 2 : 1);

    $idoc = [
        'TipoeCF'                => $tipo,
        'eNCF'                   => $encf ?? '',
        'IndicadorEnvioDiferido' => '',
        'IndicadorMontoGravado'  => '0',   // los precios de NexoPOS son sin ITBIS
        'TipoIngresos'           => ecfTipoIngreso($venta['tipo_ingreso'] ?? 1),
        'TipoPago'               => (string) $tipoPago,
        'FechaLimitePago'        => '',
        'TerminoPago'            => '',
        'TipoCuentaPago'         => '',
        'NumeroCuentaPago'       => '',
        'BancoPago'              => '',
        'FechaDesde'             => '',
        'FechaHasta'             => '',
        'FechaEmision'           => ecfFecha($venta['fecha']),
    ];
    if ($tipo === '31' || $tipo === '33') {
        $idoc['FechaVencimientoSecuencia'] = ecfFecha(ecfVencimientoSecuencia($tipo));
    }
    if ($tipoPago === 2) {
        // Venta a crédito: la fecha límite es condicional-obligatoria. Sin plazo
        // pactado se usa la fecha de la venta, que es el criterio más prudente.
        $idoc['FechaLimitePago'] = ecfFecha($venta['fecha']);
    }

    $doc = [
        'tipo_ecf' => $tipo,
        'IDOC' => $idoc,
        'EMIS' => ecfSeccionEmisor((int) $venta['sucursal_id'], ['numero_interno' => $venta['numero']]),
        'COMP' => ecfSeccionComprador($cliente, $tipo),
        'ITEM' => $items,
    ];
    if ($fpag) $doc['FPAG'] = $fpag;

    return $doc;
}

/**
 * Traduce una devolución a una Nota de Crédito Electrónica (tipo 34).
 *
 * `IndicadorNotaCredito` (Tabla 2) distingue si la factura corregida tiene más
 * de 30 días calendario: pasado ese plazo la nota ya NO da derecho a deducir el
 * ITBIS, y declararlo mal altera el débito fiscal del período.
 */
function ecfDocumentoDeDevolucion(int $devolucionId, ?string $encf = null): array
{
    $dev = qOne("SELECT * FROM devoluciones WHERE id = ?", [$devolucionId]);
    if (!$dev) throw new RuntimeException('La devolución no existe.');

    $venta = qOne("SELECT * FROM ventas WHERE id = ?", [(int) $dev['venta_id']]);
    if (!$venta) throw new RuntimeException('La venta original de la devolución no existe.');

    $cliente = $venta['cliente_id']
        ? qOne("SELECT * FROM clientes WHERE id = ?", [(int) $venta['cliente_id']])
        : null;

    // `venta_detalle_id` admite NULL y la propia línea de devolución ya trae
    // descripción, cantidad y precio: se usa LEFT JOIN para no perder líneas
    // que no apunten a un detalle de la venta original.
    $detalles = qAll(
        "SELECT dd.*,
                vd.ecf_indicador_facturacion, vd.ecf_unidad_medida, vd.ecf_bien_servicio,
                p.tipo AS producto_tipo, p.ecf_indicador_facturacion AS prod_indicador,
                u.ecf_codigo AS unidad_ecf
           FROM devolucion_detalles dd
           LEFT JOIN venta_detalles vd ON vd.id = dd.venta_detalle_id
           LEFT JOIN productos p ON p.id = COALESCE(dd.producto_id, vd.producto_id)
           LEFT JOIN unidades  u ON u.id = p.unidad_id
          WHERE dd.devolucion_id = ?
          ORDER BY dd.id",
        [$devolucionId]
    );
    if (!$detalles) throw new RuntimeException('La devolución no tiene líneas.');

    $items = [];
    foreach ($detalles as $i => $d) {
        $indicador = $d['ecf_indicador_facturacion'] ?? $d['prod_indicador'] ?? null;
        if ($indicador === null || $indicador === '') $indicador = 1;

        $cant   = (float) $d['cantidad'];
        $precio = (float) $d['precio_unitario'];

        $items[] = [
            'NumeroLinea'            => $i + 1,
            'TipoCodigoItem'         => '',
            'IndicadorFacturacion'   => (string) (int) $indicador,
            'NombreItem'             => $d['descripcion'],
            'IndicadorBienoServicio' => (string) (int) ($d['ecf_bien_servicio']
                                          ?: ecfBienServicioDesdeProducto($d['producto_tipo'] ?? 'producto')),
            'DescripcionItem'        => '',
            'CantidadItem'           => ecfCantidad($cant),
            'UnidadMedida'           => (string) (int) ($d['ecf_unidad_medida'] ?? $d['unidad_ecf'] ?? 43),
            'PrecioUnitarioItem'     => ecfPrecio($precio),
            'MontoItem'              => ecfMonto(round($cant * $precio, 2)),
        ];
    }

    $dias = (int) floor((strtotime((string) $dev['created_at']) - strtotime((string) $venta['fecha'])) / 86400);

    return [
        'tipo_ecf' => '34',
        'IDOC' => [
            'TipoeCF'                => '34',
            'eNCF'                   => $encf ?? '',
            'IndicadorNotaCredito'   => $dias > 30 ? '1' : '0',
            'IndicadorEnvioDiferido' => '',
            'IndicadorMontoGravado'  => '0',
            'TipoIngresos'           => ecfTipoIngreso($venta['tipo_ingreso'] ?? 1),
            'TipoPago'               => '1',
            'FechaLimitePago'        => '',
            'FechaDesde'             => '',
            'FechaHasta'             => '',
            'FechaEmision'           => ecfFecha($dev['created_at']),
        ],
        'EMIS' => ecfSeccionEmisor((int) $dev['sucursal_id'], ['numero_interno' => $dev['numero']]),
        'COMP' => ecfSeccionComprador($cliente, '34'),
        'ITEM' => $items,
        'INFR' => [
            // Referencia al comprobante corregido: si la venta ya se emitió como
            // e-CF se usa ese e-NCF; si no, el NCF preimpreso que llevaba.
            'NCFModificado'        => $venta['ncf'] ?? '',
            'RNCOtroContribuyente' => '',
            'FechaNCFModificado'   => ecfFecha($venta['fecha']),
            'CodigoModificacion'   => '1',   // Tabla 18: anula el comprobante
            'RazonModificacion'    => $dev['motivo'] ?? 'Devolución de mercancía',
        ],
    ];
}

/* ============================================================
 *  EMISIÓN
 * ============================================================ */

/**
 * Emite el e-CF de una venta.
 *
 * Es idempotente por partida doble: el índice único (origen, origen_id) de
 * `ecf_documentos` impide que una misma venta genere dos comprobantes aunque se
 * pulse «emitir» dos veces, y si ya existe un documento se devuelve ese en vez
 * de gastar otra secuencia.
 *
 * @return array ['ok' => bool, 'documento_id' => ?int, 'encf' => ?string,
 *                'track_id' => ?string, 'mensaje' => string, 'errores' => string[]]
 */
function ecfEmitirVenta(int $ventaId, array $opciones = []): array
{
    return ecfEmitir('venta', $ventaId, $opciones);
}

/** Emite la Nota de Crédito Electrónica de una devolución. */
function ecfEmitirDevolucion(int $devolucionId, array $opciones = []): array
{
    return ecfEmitir('devolucion', $devolucionId, $opciones);
}

/**
 * e-NCF que la venta o la devolución ya tiene asignado, o null si lleva un
 * comprobante preimpreso (o ninguno).
 */
function ecfENCFAsignado(string $origen, int $origenId): ?string
{
    $tabla = $origen === 'venta' ? 'ventas' : ($origen === 'devolucion' ? 'devoluciones' : null);
    if ($tabla === null) return null;

    $ncf = (string) qVal("SELECT ncf FROM $tabla WHERE id = ?", [$origenId]);
    return ecfENCFValido($ncf) ? $ncf : null;
}

/**
 * Emite sin poder tumbar a quien la llama. SIEMPRE devuelve, nunca lanza.
 *
 * Es la que se usa desde el flujo de venta. La regla es simple y no se negocia:
 * **una venta cerrada no se deshace porque el proveedor esté caído**. La
 * mercancía ya salió, el cliente ya pagó y el e-NCF ya se imprimió; lo que queda
 * pendiente es transmitirlo, y para eso está la cola con reintentos.
 *
 * Si algo falla se registra en la bitácora y se devuelve el motivo para
 * enseñarlo como aviso, nunca como error de la venta.
 */
function ecfEmitirSeguro(string $origen, int $origenId): array
{
    $cfg = ecfConfig();
    $opciones = [
        // Con el envío automático apagado, el comprobante se registra y se queda
        // en cola: se transmite desde la pantalla o desde la tarea programada.
        'solo_registrar' => empty($cfg['envio_automatico']),
        'timeout'        => ECF_TIMEOUT_POS,
    ];

    try {
        return $origen === 'venta'
            ? ecfEmitirVenta($origenId, $opciones)
            : ecfEmitirDevolucion($origenId, $opciones);
    } catch (Throwable $e) {
        ecfRegistrarLlamada([
            'operacion' => 'emision',
            'metodo'    => 'INTERNO',
            'url'       => $origen . '/' . $origenId,
            'error'     => $e->getMessage(),
        ]);
        return ['ok' => false, 'documento_id' => null, 'encf' => null, 'track_id' => null,
                'errores' => [],
                'mensaje' => 'El comprobante quedó pendiente de transmitir: ' . $e->getMessage()];
    }
}

/**
 * Motor común de emisión.
 *
 * El orden importa: primero se construye y valida el documento SIN consumir
 * secuencia, y solo cuando está limpio se toma el e-NCF y se registra. Un
 * documento inválido no debe quemar un número autorizado, porque recuperarlo
 * obliga a reportarlo como anulado ante la DGII.
 */
function ecfEmitir(string $origen, int $origenId, array $opciones = []): array
{
    $fallo = static fn(string $msg, array $errs = []): array => [
        'ok' => false, 'documento_id' => null, 'encf' => null, 'track_id' => null,
        'mensaje' => $msg, 'errores' => $errs,
    ];

    if (!in_array($origen, ['venta', 'devolucion'], true)) {
        return $fallo('Origen no válido: ' . $origen);
    }

    // ¿Ya se emitió? Se devuelve el existente sin tocar nada.
    $ya = qOne("SELECT * FROM ecf_documentos WHERE origen = ? AND origen_id = ?", [$origen, $origenId]);
    if ($ya && in_array($ya['estado'], ['enviado', 'aceptado'], true)) {
        return ['ok' => true, 'documento_id' => (int) $ya['id'], 'encf' => $ya['encf'],
                'track_id' => $ya['track_id'], 'errores' => [],
                'mensaje' => 'Este documento ya tenía e-CF ' . $ya['encf'] . '.'];
    }

    // 1) Construir y validar sin consumir secuencia.
    try {
        $doc = $origen === 'venta'
            ? ecfDocumentoDeVenta($origenId)
            : ecfDocumentoDeDevolucion($origenId);
    } catch (Throwable $e) {
        return $fallo('No se pudo preparar el documento: ' . $e->getMessage());
    }

    $tipo = $doc['tipo_ecf'];

    // ¿La venta o la devolución YA tomó su e-NCF al registrarse?
    //
    // Con el e-CF encendido, el número se asigna dentro de la MISMA transacción
    // que crea la venta: así el comprobante que se imprime y el que se declara
    // son el mismo, y una caída aquí no deja una venta sin número ni un número
    // sin venta. En ese caso este método reutiliza el asignado; consumir otro
    // dejaría el impreso y el declarado en desacuerdo.
    $encfAsignado = ecfENCFAsignado($origen, $origenId);

    if ($encfAsignado === null) {
        $estadoSec = ecfEstadoSecuencia($tipo);
        if (!$estadoSec['ok']) return $fallo($estadoSec['mensaje']);
    }

    // Se valida con el e-NCF definitivo si ya lo hay, y si no con uno de relleno
    // para no falsear el resultado por un campo vacío.
    $docPrueba = $doc;
    $docPrueba['IDOC']['eNCF'] = $encfAsignado ?? ecfFormatearENCF($tipo, 1);
    $errores = ecfValidarDocumento($docPrueba);

    if ($errores && $encfAsignado === null) {
        // Todavía no se ha gastado ningún número: se aborta sin dejar rastro.
        return $fallo('El documento no cumple las reglas del e-CF.', $errores);
    }
    if ($errores) {
        // El número YA se consumió al registrar la venta. Abandonar aquí dejaría
        // una secuencia autorizada sin documento que la respalde: invisible en la
        // bandeja e imposible de cuadrar contra la DGII. Se registra en estado de
        // error para que aparezca y alguien la resuelva (corregir el dato y
        // reenviar, o anularla formalmente).
        return ecfRegistrarInvalido($doc, $tipo, $origen, $origenId, $encfAsignado, $errores);
    }

    // 2) Tomar el e-NCF (si hace falta) y registrar el documento, en una sola
    //    transacción.
    try {
        $registro = tx(function () use ($doc, $tipo, $origen, $origenId, $encfAsignado) {
            $encf = $encfAsignado ?? ecfSiguienteENCF($tipo);
            if ($encf === null) {
                throw new RuntimeException('No hay secuencia E' . $tipo . ' disponible.');
            }
            $doc['IDOC']['eNCF'] = $encf;
            $trama = ecfConstruirTrama($doc);

            $empresa = $GLOBALS['empresa'] ?? [];
            $rnc = preg_replace('/\D+/', '', (string) ($empresa['rnc'] ?? ''));

            $total = 0.0;
            foreach ($doc['ITEM'] as $it) $total += (float) $it['MontoItem'];

            $sucursalId = $origen === 'venta'
                ? (int) qVal("SELECT sucursal_id FROM ventas WHERE id = ?", [$origenId])
                : (int) qVal("SELECT sucursal_id FROM devoluciones WHERE id = ?", [$origenId]);

            $id = dbInsert('ecf_documentos', [
                'tipo_ecf'   => $tipo,
                'encf'       => $encf,
                'origen'     => $origen,
                'origen_id'  => $origenId,
                'sucursal_id'=> $sucursalId ?: null,
                'usuario_id' => current_user()['id'] ?? null,
                'rnc_emisor' => $rnc,
                'rnc_comprador'          => $doc['COMP']['RNCComprador'] ?: null,
                'razon_social_comprador' => $doc['COMP']['RazonSocialComprador'] ?: null,
                'fecha_emision' => date('Y-m-d'),
                'total'      => round($total, 2),
                'archivo'    => ecfNombreArchivo($rnc, $encf),
                'trama'      => $trama,
                'estado'     => 'pendiente',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['id' => $id, 'encf' => $encf, 'trama' => $trama,
                    'archivo' => ecfNombreArchivo($rnc, $encf)];
        });
    } catch (Throwable $e) {
        return $fallo('No se pudo registrar el documento: ' . $e->getMessage());
    }

    // 3) Enviar. Si falla, el documento queda registrado y reintentable: la
    //    secuencia ya se consumió y debe rendirse cuenta de ella igual.
    if (!empty($opciones['solo_registrar'])) {
        return ['ok' => true, 'documento_id' => $registro['id'], 'encf' => $registro['encf'],
                'track_id' => null, 'errores' => [],
                'mensaje' => 'Documento generado (' . $registro['encf'] . '), pendiente de envío.'];
    }

    return ecfEnviarDocumento((int) $registro['id'], $opciones);
}

/**
 * Deja constancia de un e-NCF ya consumido cuyo documento no pasa las
 * validaciones. Queda en estado «error», visible en la bandeja, con el detalle
 * de qué le falta.
 *
 * La trama se guarda igual aunque esté incompleta: es la evidencia de qué se
 * intentó emitir con ese número.
 */
function ecfRegistrarInvalido(array $doc, string $tipo, string $origen, int $origenId,
                              string $encf, array $errores): array
{
    $doc['IDOC']['eNCF'] = $encf;
    $empresa = $GLOBALS['empresa'] ?? [];
    $rnc = preg_replace('/\D+/', '', (string) ($empresa['rnc'] ?? ''));

    $total = 0.0;
    foreach ($doc['ITEM'] ?? [] as $it) $total += (float) ($it['MontoItem'] ?? 0);

    $tabla = $origen === 'venta' ? 'ventas' : 'devoluciones';
    $sucursalId = (int) qVal("SELECT sucursal_id FROM $tabla WHERE id = ?", [$origenId]);

    try {
        $id = dbInsert('ecf_documentos', [
            'tipo_ecf'    => $tipo,
            'encf'        => $encf,
            'origen'      => $origen,
            'origen_id'   => $origenId,
            'sucursal_id' => $sucursalId ?: null,
            'usuario_id'  => current_user()['id'] ?? null,
            'rnc_emisor'  => $rnc,
            'rnc_comprador'          => $doc['COMP']['RNCComprador'] ?: null,
            'razon_social_comprador' => $doc['COMP']['RazonSocialComprador'] ?: null,
            'fecha_emision' => date('Y-m-d'),
            'total'       => round($total, 2),
            'archivo'     => ecfNombreArchivo($rnc, $encf),
            'trama'       => ecfConstruirTrama($doc),
            'estado'      => 'error',
            'estado_detalle' => mb_substr(implode(' · ', $errores), 0, 500),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'documento_id' => null, 'encf' => $encf, 'track_id' => null,
                'errores' => $errores,
                'mensaje' => 'El e-NCF ' . $encf . ' quedó sin registrar: ' . $e->getMessage()];
    }

    return ['ok' => false, 'documento_id' => $id, 'encf' => $encf, 'track_id' => null,
            'errores' => $errores,
            'mensaje' => 'El e-NCF ' . $encf . ' se emitió pero el documento no cumple las reglas del e-CF. '
                       . 'Queda en la bandeja para corregirlo y reenviarlo.'];
}

/**
 * Envía (o reintenta) un documento ya registrado.
 *
 * La espera entre reintentos crece (1, 2, 4, 8… minutos) para no martillar al
 * proveedor cuando está caído, que es justo cuando más peticiones se acumulan.
 */
function ecfEnviarDocumento(int $documentoId, array $opciones = []): array
{
    $doc = qOne("SELECT * FROM ecf_documentos WHERE id = ?", [$documentoId]);
    if (!$doc) return ['ok' => false, 'documento_id' => null, 'encf' => null,
                       'track_id' => null, 'mensaje' => 'El documento no existe.', 'errores' => []];

    if (in_array($doc['estado'], ['enviado', 'aceptado'], true)) {
        return ['ok' => true, 'documento_id' => $documentoId, 'encf' => $doc['encf'],
                'track_id' => $doc['track_id'], 'errores' => [],
                'mensaje' => 'El documento ya había sido enviado.'];
    }

    $r = ecfEnviarTrama($doc['archivo'], $doc['trama'], $documentoId, $opciones);
    $intentos = (int) $doc['intentos'] + 1;

    if ($r['ok']) {
        dbUpdate('ecf_documentos', [
            'track_id'        => $r['track_id'],
            'estado'          => 'enviado',
            'estado_detalle'  => $r['mensaje'],
            'respuesta_envio' => $r['raw'],
            'intentos'        => $intentos,
            'proximo_intento' => null,
            'enviado_at'      => date('Y-m-d H:i:s'),
        ], 'id = ?', [$documentoId]);

        return ['ok' => true, 'documento_id' => $documentoId, 'encf' => $doc['encf'],
                'track_id' => $r['track_id'], 'errores' => [],
                'mensaje' => 'e-CF ' . $doc['encf'] . ' enviado. Ticket: ' . $r['track_id']];
    }

    $maximo = (int) (ecfConfig()['reintentos_max'] ?? 5);
    $agotado = !$r['reintentable'] || $intentos >= $maximo;

    dbUpdate('ecf_documentos', [
        'estado'          => $agotado ? 'error' : 'pendiente',
        'estado_detalle'  => $r['mensaje'],
        'codigo_respuesta'=> null,
        'respuesta_envio' => $r['raw'],
        'intentos'        => $intentos,
        'proximo_intento' => $agotado ? null : date('Y-m-d H:i:s', time() + 60 * (2 ** min($intentos - 1, 6))),
    ], 'id = ?', [$documentoId]);

    return ['ok' => false, 'documento_id' => $documentoId, 'encf' => $doc['encf'],
            'track_id' => null, 'errores' => [],
            'mensaje' => $r['mensaje']];
}

/* ============================================================
 *  CÓDIGO QR DE LA REPRESENTACIÓN IMPRESA
 * ============================================================ */

/** Intentos máximos de descarga del QR antes de dejar de insistir. */
const ECF_QR_INTENTOS_MAX = 3;

/**
 * Dibuja el código QR de la Representación Impresa a partir del timbre.
 *
 * El servicio de QR del proveedor NO devuelve una imagen: devuelve la URL de
 * consulta del timbre en la DGII, así:
 *
 *   https://fc.dgii.gov.do/testecf/consultatimbrefc
 *      ?RncEmisor=102616541&ENCF=E320000000001
 *      &MontoTotal=55250.00&CodigoSeguridad=HDsJrI
 *
 * El `CodigoSeguridad` sale de la firma digital, por eso la URL tiene que venir
 * del proveedor. La imagen, en cambio, la generamos aquí: así se puede imprimir
 * al tamaño que convenga en un ticket térmico y no depende de la red.
 *
 * Se usa corrección de errores M y `pixelated` al pintar: un QR interpolado al
 * escalar deja de leerse, y un QR que no escanea no sirve de nada.
 */
function ecfQrDesdeUrl(string $url): ?string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) return null;
    if (!class_exists(\chillerlan\QRCode\QRCode::class)) return null;

    try {
        $opciones = new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
            'eccLevel'        => \chillerlan\QRCode\Common\EccLevel::M,
            'scale'           => 6,
            'quietzoneSize'   => 2,
            'outputBase64'    => true,
        ]);
        $data = (new \chillerlan\QRCode\QRCode($opciones))->render($url);
        return is_string($data) && str_starts_with($data, 'data:image/') ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Convierte lo que devuelva el proveedor en un data URI listo para <img>.
 *
 * El ambiente real entrega una URL (ver ecfQrDesdeUrl), pero se siguen tolerando
 * las otras formas plausibles por si cambiara entre ambientes o versiones: PNG
 * crudo, JSON con el base64 dentro, o un data URI ya formado.
 *
 * Siempre se termina en data URI porque la política de seguridad de la app
 * (`img-src 'self' data: blob:`) bloquea imágenes de dominios externos.
 *
 * @return string|null data URI, o null si no se pudo interpretar.
 */
function ecfQrNormalizar(string $crudo, ?array $json): ?string
{
    // 1) Binario directo: se reconoce por los bytes mágicos.
    $firmas = [
        "\x89PNG\r\n\x1a\n" => 'image/png',
        "\xFF\xD8\xFF"      => 'image/jpeg',
        'GIF87a'            => 'image/gif',
        'GIF89a'            => 'image/gif',
    ];
    foreach ($firmas as $magia => $mime) {
        if (strncmp($crudo, $magia, strlen($magia)) === 0) {
            return 'data:' . $mime . ';base64,' . base64_encode($crudo);
        }
    }
    if (stripos(ltrim($crudo), '<svg') === 0) {
        return 'data:image/svg+xml;base64,' . base64_encode($crudo);
    }

    // 2) JSON: se busca el valor por los nombres plausibles.
    $valor = $json ? ecfBuscarValor($json, ['qr', 'qrCode', 'qr_code', 'image', 'imagen',
                                            'base64', 'content', 'contenido', 'data', 'url']) : null;
    $valor = is_string($valor) ? trim($valor) : (is_string($crudo) ? trim($crudo) : '');
    if ($valor === '') return null;

    // Ya viene como data URI.
    if (stripos($valor, 'data:image/') === 0) return $valor;

    // Es una URL. NO se descarga: es el timbre de la DGII, una página de
    // consulta, no una imagen. Lo que hay que hacer es codificarla como QR.
    if (preg_match('#^https?://#i', $valor)) {
        return ecfQrDesdeUrl($valor);
    }

    // Base64 suelto (se tolera el formato URL-safe).
    $limpio = strtr(preg_replace('/\s+/', '', $valor), '-_', '+/');
    if (preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $limpio) && strlen($limpio) > 40) {
        $bin = base64_decode($limpio, true);
        if ($bin !== false && $bin !== '') return ecfQrNormalizar($bin, null);
    }

    return null;
}

/**
 * QR del comprobante, listo para pintar. Null si todavía no hay.
 *
 * Se descarga UNA vez y se guarda: reimprimir un ticket no debe depender de la
 * red ni gastar una llamada al proveedor.
 *
 * Solo se pide cuando el comprobante está aceptado, porque el QR lleva el código
 * de seguridad derivado de la firma digital y antes de firmarse no existe.
 *
 * Nunca lanza: un ticket tiene que poder imprimirse aunque el proveedor no
 * conteste.
 */
function ecfQrDataUri(int $documentoId, bool $intentarDescarga = true): ?string
{
    $doc = qOne("SELECT id, encf, estado, rnc_emisor, qr, qr_intentos FROM ecf_documentos WHERE id = ?", [$documentoId]);
    if (!$doc) return null;
    if (!empty($doc['qr'])) return (string) $doc['qr'];

    if (!$intentarDescarga) return null;
    if ($doc['estado'] !== 'aceptado') return null;
    if ((int) $doc['qr_intentos'] >= ECF_QR_INTENTOS_MAX) return null;

    try {
        // Se cuenta el intento ANTES de pedirlo: si el proveedor responde algo
        // que no se entiende, no queremos reintentarlo en cada reimpresión.
        q("UPDATE ecf_documentos SET qr_intentos = qr_intentos + 1 WHERE id = ?", [$documentoId]);

        $r = ecfDescargarRecurso('QR', (string) $doc['rnc_emisor'], (string) $doc['encf'], $documentoId);
        if (!$r['ok']) return null;

        // El proveedor entrega la URL del timbre; la imagen la dibujamos aquí.
        $dataUri = !empty($r['url'])
            ? ecfQrDesdeUrl((string) $r['url'])
            : ecfQrNormalizar((string) ($r['contenido'] ?? ''), $r['json'] ?? null);
        if ($dataUri === null) return null;

        // Se guarda también la URL: sirve para imprimirla como texto, para
        // reconstruir el QR a otro tamaño y para verificar a mano ante la DGII.
        dbUpdate('ecf_documentos', [
            'qr'     => $dataUri,
            'qr_url' => $r['url'] ?? null,
            'qr_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$documentoId]);
        return $dataUri;
    } catch (Throwable $e) {
        ecfRegistrarLlamada([
            'documento_id' => $documentoId, 'operacion' => 'descarga', 'metodo' => 'GET',
            'url' => 'QR/' . $doc['encf'], 'error' => $e->getMessage(),
        ]);
        return null;
    }
}

/**
 * QR de una venta, para el ticket. Null si la venta no es electrónica o el
 * comprobante todavía no está aceptado.
 */
function ecfQrDeVenta(int $ventaId): ?string
{
    $id = qVal("SELECT id FROM ecf_documentos WHERE origen = 'venta' AND origen_id = ?", [$ventaId]);
    return $id ? ecfQrDataUri((int) $id) : null;
}

/** Consulta el estado en el proveedor y lo refleja en `ecf_documentos`. */
function ecfActualizarEstado(int $documentoId, array $opciones = []): array
{
    $doc = qOne("SELECT * FROM ecf_documentos WHERE id = ?", [$documentoId]);
    if (!$doc)             return ['ok' => false, 'mensaje' => 'El documento no existe.'];
    if (!$doc['track_id']) return ['ok' => false, 'mensaje' => 'El documento aún no tiene ticket de seguimiento.'];

    $r = ecfConsultarTrackId((string) $doc['track_id'], $documentoId, $opciones);
    if (!$r['ok']) return ['ok' => false, 'mensaje' => $r['mensaje']];

    dbUpdate('ecf_documentos', [
        'estado'           => $r['estado'],
        'estado_detalle'   => $r['mensaje'],
        'codigo_respuesta' => $r['codigo'] ?? null,
        'respuesta_estado' => $r['raw'],
        'consultado_at'    => date('Y-m-d H:i:s'),
    ], 'id = ?', [$documentoId]);

    // Recién aceptado: se aprovecha el viaje para traer el QR. Así, cuando el
    // cliente vuelva a pedir su factura, ya está guardado y la reimpresión no
    // depende de que el proveedor conteste.
    if ($r['estado'] === 'aceptado') ecfQrDataUri($documentoId);

    return ['ok' => true, 'estado' => $r['estado'], 'mensaje' => $r['mensaje']];
}

/**
 * Procesa la cola: envía lo pendiente y refresca el estado de lo enviado.
 *
 * @param int   $limite   Documentos como máximo por fase.
 * @param array $opciones ['timeout' => segundos de espera por envío]
 * @return array Resumen contable de lo hecho.
 */
function ecfProcesarCola(int $limite = 25, array $opciones = []): array
{
    $resumen = ['enviados' => 0, 'fallidos' => 0, 'consultados' => 0];
    $limite  = max(1, min(500, $limite));

    $pendientes = qAll(
        "SELECT id FROM ecf_documentos
          WHERE estado = 'pendiente'
            AND (proximo_intento IS NULL OR proximo_intento <= NOW())
          ORDER BY id LIMIT $limite"
    );
    foreach ($pendientes as $p) {
        $r = ecfEnviarDocumento((int) $p['id'], $opciones);
        $r['ok'] ? $resumen['enviados']++ : $resumen['fallidos']++;
    }

    // Los enviados se vuelven a consultar hasta que resuelvan. Se espacia 10
    // minutos para no preguntar lo mismo cada vez que alguien abre una página.
    $enviados = qAll(
        "SELECT id FROM ecf_documentos
          WHERE estado = 'enviado' AND track_id IS NOT NULL
            AND (consultado_at IS NULL OR consultado_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
          ORDER BY id LIMIT $limite"
    );
    foreach ($enviados as $e) {
        if (ecfActualizarEstado((int) $e['id'], $opciones)['ok']) $resumen['consultados']++;
    }

    return $resumen;
}

/* ============================================================
 *  TAREA PROGRAMADA
 * ============================================================ */

/** Minutos mínimos entre dos pasadas oportunistas de la cola. */
const ECF_TICK_MINUTOS = 5;

/** Documentos por pasada oportunista. Ver ecfTickSiToca() para el porqué. */
const ECF_TICK_LOTE = 3;

/** ¿Está el módulo instalado? (la migración p15 pudo no haberse aplicado) */
function ecfDisponible(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) qVal("SHOW TABLES LIKE 'ecf_documentos'");
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * Reclama el turno de la cola de forma atómica. Devuelve true UNA sola vez cada
 * ECF_TICK_MINUTOS aunque entren diez peticiones en el mismo segundo.
 *
 * Es el mismo mecanismo que usan el barrido de notificaciones y el motor de
 * marketing: un UPDATE condicional sobre `sistema_estado` cuyo rowCount decide
 * quién se lleva el turno. Sin él, diez cajas abiertas a la vez lanzarían diez
 * pasadas simultáneas contra el proveedor.
 */
function ecfReclamarTurno(int $minutos = ECF_TICK_MINUTOS): bool
{
    $st = q(
        "INSERT INTO sistema_estado (clave, valor, updated_at)
         VALUES ('ecf_cola_tick', UNIX_TIMESTAMP(), NOW())
         ON DUPLICATE KEY UPDATE
            valor      = IF(updated_at < (NOW() - INTERVAL ? MINUTE), UNIX_TIMESTAMP(), valor),
            updated_at = IF(updated_at < (NOW() - INTERVAL ? MINUTE), NOW(), updated_at)",
        [$minutos, $minutos]
    );
    return $st->rowCount() > 0;
}

/**
 * ¿Hay algo que hacer? Dos consultas baratas por índice.
 *
 * Se comprueba ANTES de reclamar el turno para no quemarlo en balde: si no hay
 * trabajo, quemar el turno dejaría la cola parada otros cinco minutos.
 */
function ecfHayTrabajoEnCola(): bool
{
    $pendiente = qVal(
        "SELECT 1 FROM ecf_documentos
          WHERE estado = 'pendiente'
            AND (proximo_intento IS NULL OR proximo_intento <= NOW())
          LIMIT 1"
    );
    if ($pendiente) return true;

    return (bool) qVal(
        "SELECT 1 FROM ecf_documentos
          WHERE estado = 'enviado' AND track_id IS NOT NULL
            AND (consultado_at IS NULL OR consultado_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
          LIMIT 1"
    );
}

/**
 * Corre la cola si toca, sin necesidad de cron.
 *
 * Se llama al pintar la barra superior, así que la regla es no hacer esperar a
 * nadie ni tumbar una página pase lo que pase:
 *
 *  · Solo entra si el e-CF está encendido y hay trabajo REAL pendiente.
 *  · Un turno cada ECF_TICK_MINUTOS entre todos los usuarios.
 *  · Lote pequeño (ECF_TICK_LOTE) y espera corta por documento: en el peor caso
 *    el usuario desafortunado pierde unos segundos, no minutos.
 *  · Cualquier error se traga; la página se pinta igual.
 *
 * Esto es una RED DE SEGURIDAD, no el mecanismo principal. Con
 * `envio_automatico` encendido cada venta transmite su propio comprobante en el
 * momento y la cola queda casi siempre vacía: aquí solo caen los rezagados y las
 * consultas de estado. Para una operación seria, además, el cron de
 * `modules/finanzas/ecf_cron.php`.
 */
function ecfTickSiToca(): void
{
    if (!ecfDisponible() || !ecfActivo() || !ecfConfigurado()) return;

    try {
        if (!ecfHayTrabajoEnCola()) return;
        if (!ecfReclamarTurno()) return;

        @set_time_limit(60);
        ignore_user_abort(true);
        ecfProcesarCola(ECF_TICK_LOTE, ['timeout' => ECF_TIMEOUT_POS]);
    } catch (Throwable $e) {
        if (APP_ENV !== 'production') error_log('[e-CF] ' . $e->getMessage());
    }
}

/* ============================================================
 *  DIAGNÓSTICO
 * ============================================================ */

/**
 * Revisa que estén los datos maestros que el e-CF exige y que hoy pueden faltar.
 * Se usa en la pantalla de configuración para que nadie descubra el hueco justo
 * cuando intenta facturar.
 */
function ecfDiagnostico(): array
{
    $empresa = $GLOBALS['empresa'] ?? [];
    $checks = [];

    $rnc = preg_replace('/\D+/', '', (string) ($empresa['rnc'] ?? ''));
    $checks[] = [
        'ok' => in_array(strlen($rnc), [9, 11], true),
        'titulo' => 'RNC de la empresa',
        'detalle' => $rnc !== '' ? 'RNC ' . $rnc : 'Sin RNC configurado (Configuración → Empresa).',
    ];
    $checks[] = [
        'ok' => trim((string) ($empresa['direccion'] ?? '')) !== '',
        'titulo' => 'Dirección del emisor',
        'detalle' => 'Es obligatoria en la sección EMIS de todos los tipos de e-CF.',
    ];
    $checks[] = [
        'ok' => ecfConfigurado(),
        'titulo' => 'Credenciales del proveedor',
        'detalle' => ecfConfigurado()
            ? 'Usuario configurado.'
            : 'Faltan usuario y clave (los entrega LUGANIS).',
    ];

    $sinIndicador = (int) qVal("SELECT COUNT(*) FROM productos WHERE activo = 1 AND ecf_indicador_facturacion IS NULL");
    $checks[] = [
        'ok' => $sinIndicador === 0,
        'titulo' => 'Indicador de ITBIS por producto',
        'detalle' => $sinIndicador === 0
            ? 'Todos los productos activos tienen su indicador de la Tabla 13.'
            : "$sinIndicador producto(s) activo(s) sin indicador de facturación.",
    ];

    foreach (ecfTiposSoportados() as $t) {
        $s = ecfEstadoSecuencia($t);
        $checks[] = [
            'ok' => $s['ok'],
            'titulo' => 'Secuencia E' . $t . ' — ' . ecfTiposComprobante()[$t],
            'detalle' => $s['mensaje'],
            'aviso' => !$s['ok'],   // sin rango autorizado no es un error de instalación
        ];
    }

    $checks[] = [
        'ok' => is_file(dirname(__DIR__) . '/config/ca-ecf.local.crt') || !ecfCaBundle(),
        'titulo' => 'Verificación TLS',
        'detalle' => ecfCaBundle()
            ? 'Usando bundle propio: ' . basename((string) ecfCaBundle())
            : 'Usando el bundle de CA por defecto de PHP.',
    ];

    return $checks;
}
