<?php
/**
 * Generación de los Formatos de Envío de Datos de la DGII (606, 607 y 608).
 *
 * IMPORTANTE — dos detalles del archivo TXT que la DGII NO documenta en sus
 * instructivos (asume que usas su plantilla de Excel con macros):
 *
 *   1. El separador de campos. Aquí se usa el pipe «|», que es la estructura
 *      que produce la herramienta oficial.
 *   2. El zero-padding del «Tipo de Bienes y Servicios» a dos posiciones (01..11).
 *
 * Antes del primer envío real, el archivo generado DEBE pasar por la herramienta
 * de pre-validación de la DGII. Ver DGII.md.
 *
 * Los reportes son POR RNC, no por sucursal: siempre incluyen todas las
 * sucursales de la empresa. El filtro de sucursal de la interfaz es solo para
 * revisar en pantalla, nunca afecta el archivo que se envía.
 */

const DGII_SEPARADOR = '|';
const DGII_SALTO     = "\r\n";
const DGII_MAX_REGISTROS = 10000;

/** Compras reportables en el período (AAAAMM). Excluye anuladas y las que no tienen NCF. */
function dgiiFilas606(string $periodo, ?int $sucursalId = null): array
{
    $cond = ["c.estado <> 'anulada'", "c.ncf IS NOT NULL", "c.ncf <> ''",
             "DATE_FORMAT(c.fecha_comprobante, '%Y%m') = ?"];
    $params = [$periodo];
    if ($sucursalId) { $cond[] = 'c.sucursal_id = ?'; $params[] = $sucursalId; }

    return qAll(
        "SELECT c.*, p.rnc AS proveedor_rnc, p.tipo_id AS proveedor_tipo_id, p.nombre AS proveedor_nombre
           FROM compras c
           LEFT JOIN proveedores p ON p.id = c.proveedor_id
          WHERE " . implode(' AND ', $cond) . "
          ORDER BY c.fecha_comprobante, c.id",
        $params
    );
}

/** Ventas reportables en el período. Las anuladas van al 608, no al 607. */
function dgiiFilas607(string $periodo, ?int $sucursalId = null): array
{
    $cond = ["v.estado <> 'anulada'", "v.ncf IS NOT NULL", "v.ncf <> ''",
             "DATE_FORMAT(v.fecha, '%Y%m') = ?"];
    $params = [$periodo];
    if ($sucursalId) { $cond[] = 'v.sucursal_id = ?'; $params[] = $sucursalId; }

    $ventas = qAll(
        "SELECT v.*, cl.rnc_cedula AS cliente_doc, cl.tipo_id AS cliente_tipo_id, cl.nombre AS cliente_nombre
           FROM ventas v
           LEFT JOIN clientes cl ON cl.id = v.cliente_id
          WHERE " . implode(' AND ', $cond) . "
          ORDER BY v.fecha, v.id",
        $params
    );
    if (!$ventas) return [];

    // Desglose de cobro (columnas 17-23): se deriva de venta_pagos, la fuente de verdad.
    $ids = array_column($ventas, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $pagos = qAll(
        "SELECT vp.venta_id, m.dgii_tipo_pago, SUM(vp.monto) AS monto
           FROM venta_pagos vp JOIN metodos_pago m ON m.id = vp.metodo_pago_id
          WHERE vp.venta_id IN ($ph)
          GROUP BY vp.venta_id, m.dgii_tipo_pago",
        $ids
    );
    $porVenta = [];
    foreach ($pagos as $p) {
        $porVenta[(int) $p['venta_id']][(int) $p['dgii_tipo_pago']] = (float) $p['monto'];
    }
    foreach ($ventas as &$v) {
        $v['desglose_pago'] = $porVenta[(int) $v['id']] ?? [];
    }
    return $ventas;
}

/** Comprobantes anulados del período. */
function dgiiFilas608(string $periodo, ?int $sucursalId = null): array
{
    $cond = ["DATE_FORMAT(fecha_comprobante, '%Y%m') = ?"];
    $params = [$periodo];
    if ($sucursalId) { $cond[] = 'sucursal_id = ?'; $params[] = $sucursalId; }
    return qAll("SELECT * FROM comprobantes_anulados WHERE " . implode(' AND ', $cond) . " ORDER BY fecha_comprobante, id", $params);
}

// ---------------------------------------------------------------------------
//  Pre-validación: replica las reglas de los instructivos antes de exportar.
// ---------------------------------------------------------------------------

/** @return array{0:array,1:array} [errores, advertencias] */
function dgiiValidar(string $formato, array $filas, array $empresa): array
{
    $errores = [];
    $avisos  = [];

    if (!dgiiSoloDigitos($empresa['rnc'] ?? '')) {
        $errores[] = ['ref' => 'Empresa', 'msg' => 'La empresa no tiene RNC configurado. Ve a Configuración → Empresa.'];
    }
    if (count($filas) > DGII_MAX_REGISTROS) {
        $errores[] = ['ref' => 'Archivo', 'msg' => 'El formato admite un máximo de ' . number_format(DGII_MAX_REGISTROS) . ' registros; hay ' . count($filas) . '.'];
    }

    foreach ($filas as $f) {
        if ($formato === '606') {
            $ref = $f['numero'];
            if (!dgiiNcfValido($f['ncf'])) $errores[] = ['ref' => $ref, 'msg' => 'NCF con estructura inválida: ' . $f['ncf']];
            if (!dgiiSoloDigitos($f['proveedor_rnc'] ?? '')) {
                $errores[] = ['ref' => $ref, 'msg' => 'El proveedor no tiene RNC o cédula registrada.'];
            }
            if (!isset(dgiiTiposBienServicio()[(int) $f['tipo_bien_servicio']])) {
                $errores[] = ['ref' => $ref, 'msg' => 'Tipo de bienes y servicios fuera del catálogo oficial.'];
            }
            if (!isset(dgiiFormasPago606()[(int) $f['forma_pago']])) {
                $errores[] = ['ref' => $ref, 'msg' => 'Forma de pago fuera del catálogo oficial.'];
            }
            $tieneRetencion = (float) $f['itbis_retenido'] > 0 || (float) $f['monto_retencion_renta'] > 0 || $f['tipo_retencion_isr'] !== null;
            if ($tieneRetencion && empty($f['fecha_pago'])) {
                $errores[] = ['ref' => $ref, 'msg' => 'Informa retenciones sin Fecha de Pago (casilla 7 del 606).'];
            }
            if (abs(((float) $f['monto_bienes'] + (float) $f['monto_servicios']) - (float) $f['subtotal']) > 0.02) {
                $errores[] = ['ref' => $ref, 'msg' => 'Bienes + servicios no cuadra con el monto facturado.'];
            }
            if ((float) $f['itbis_costo'] > (float) $f['itbis']) {
                $errores[] = ['ref' => $ref, 'msg' => 'El ITBIS llevado al costo no puede superar el ITBIS facturado.'];
            }
        }

        if ($formato === '607') {
            $ref = $f['numero'];
            if (!dgiiNcfValido($f['ncf'])) $errores[] = ['ref' => $ref, 'msg' => 'NCF con estructura inválida: ' . $f['ncf']];
            if (!isset(dgiiTiposIngreso()[(int) $f['tipo_ingreso']])) {
                $errores[] = ['ref' => $ref, 'msg' => 'Tipo de ingreso fuera del catálogo oficial.'];
            }
            // Regla del instructivo: el desglose de cobro debe sumar el total de la factura.
            $sumaPagos = array_sum($f['desglose_pago'] ?? []);
            if (abs($sumaPagos - (float) $f['total']) > 0.02) {
                $errores[] = ['ref' => $ref, 'msg' => 'El desglose de cobro (' . money($sumaPagos) . ') no suma el total de la venta (' . money($f['total']) . ').'];
            }
            $tieneRetencion = (float) $f['itbis_retenido_terceros'] > 0 || (float) $f['retencion_renta_terceros'] > 0;
            if ($tieneRetencion && empty($f['fecha_retencion'])) {
                $errores[] = ['ref' => $ref, 'msg' => 'Informa retenciones sin Fecha de Retención (casilla 7 del 607).'];
            }
            // Crédito fiscal sin RNC del comprador: la DGII lo rechaza.
            if ($f['tipo_comprobante'] === 'credito_fiscal' && !dgiiSoloDigitos($f['cliente_doc'] ?? '')) {
                $errores[] = ['ref' => $ref, 'msg' => 'Comprobante de crédito fiscal sin RNC/Cédula del cliente.'];
            }
            if ($f['tipo_comprobante'] === 'consumidor' && !dgiiSoloDigitos($f['cliente_doc'] ?? '')) {
                $avisos[] = ['ref' => $ref, 'msg' => 'Consumidor final sin documento: se reportará con los campos 1 y 2 en blanco.'];
            }
        }

        if ($formato === '608') {
            $ref = $f['ncf'];
            if (!dgiiNcfValido($f['ncf'])) $errores[] = ['ref' => $ref, 'msg' => 'NCF con estructura inválida.'];
            if (!isset(dgiiTiposAnulacion()[(int) $f['tipo_anulacion']])) {
                $errores[] = ['ref' => $ref, 'msg' => 'Tipo de anulación fuera del catálogo oficial.'];
            }
        }
    }
    return [$errores, $avisos];
}

// ---------------------------------------------------------------------------
//  Serialización del archivo TXT
// ---------------------------------------------------------------------------

function dgiiLinea(array $campos): string
{
    return implode(DGII_SEPARADOR, $campos);
}

/** Encabezado: <formato>|<RNC>|<AAAAMM>|<cantidad de registros> */
function dgiiEncabezado(string $formato, string $rnc, string $periodo, int $cantidad): string
{
    return dgiiLinea([$formato, dgiiSoloDigitos($rnc), $periodo, (string) $cantidad]);
}

function dgiiTxt606(array $filas, array $empresa, string $periodo): string
{
    $lineas = [dgiiEncabezado('606', $empresa['rnc'], $periodo, count($filas))];
    foreach ($filas as $f) {
        $itbis      = (float) $f['itbis'];
        $itbisCosto = (float) $f['itbis_costo'];
        $lineas[] = dgiiLinea([
            dgiiSoloDigitos($f['proveedor_rnc']),                        //  1
            (string) (int) $f['proveedor_tipo_id'],                      //  2
            str_pad((string) (int) $f['tipo_bien_servicio'], 2, '0', STR_PAD_LEFT), // 3
            strtoupper($f['ncf']),                                       //  4
            strtoupper((string) $f['ncf_modificado']),                   //  5
            dgiiFecha($f['fecha_comprobante']),                          //  6
            dgiiFecha($f['fecha_pago']),                                 //  7
            dgiiMonto($f['monto_servicios']),                            //  8
            dgiiMonto($f['monto_bienes']),                               //  9
            dgiiMonto((float) $f['monto_servicios'] + (float) $f['monto_bienes']), // 10
            dgiiMonto($itbis),                                           // 11
            dgiiMonto($f['itbis_retenido']),                             // 12
            dgiiMonto($f['itbis_proporcionalidad']),                     // 13
            dgiiMonto($itbisCosto),                                      // 14
            dgiiMonto($itbis - $itbisCosto),                             // 15  ITBIS por adelantar
            dgiiMonto($f['itbis_percibido']),                            // 16
            $f['tipo_retencion_isr'] !== null ? (string) (int) $f['tipo_retencion_isr'] : '', // 17
            dgiiMonto($f['monto_retencion_renta']),                      // 18
            dgiiMonto($f['isr_percibido']),                              // 19
            dgiiMonto($f['impuesto_selectivo']),                         // 20
            dgiiMonto($f['otros_impuestos']),                            // 21
            dgiiMonto($f['propina_legal']),                              // 22
            (string) (int) $f['forma_pago'],                             // 23
        ]);
    }
    return implode(DGII_SALTO, $lineas) . DGII_SALTO;
}

function dgiiTxt607(array $filas, array $empresa, string $periodo): string
{
    $lineas = [dgiiEncabezado('607', $empresa['rnc'], $periodo, count($filas))];
    foreach ($filas as $f) {
        $doc = dgiiSoloDigitos($f['cliente_doc'] ?? '');
        $d   = $f['desglose_pago'] ?? [];
        $lineas[] = dgiiLinea([
            $doc,                                                        //  1
            $doc !== '' ? (string) (int) $f['cliente_tipo_id'] : '',     //  2
            strtoupper($f['ncf']),                                       //  3
            strtoupper((string) $f['ncf_modificado']),                   //  4
            (string) (int) $f['tipo_ingreso'],                           //  5
            dgiiFecha($f['fecha']),                                      //  6
            dgiiFecha($f['fecha_retencion']),                            //  7
            dgiiMonto((float) $f['subtotal'] - (float) $f['descuento']), //  8  sin impuestos
            dgiiMonto($f['itbis']),                                      //  9
            dgiiMonto($f['itbis_retenido_terceros']),                    // 10
            dgiiMonto($f['itbis_percibido']),                            // 11
            dgiiMonto($f['retencion_renta_terceros']),                   // 12
            dgiiMonto($f['isr_percibido']),                              // 13
            dgiiMonto($f['impuesto_selectivo']),                         // 14
            dgiiMonto($f['otros_impuestos']),                            // 15
            dgiiMonto($f['propina_legal']),                              // 16
            dgiiMonto($d[1] ?? 0),                                       // 17  Efectivo
            dgiiMonto($d[2] ?? 0),                                       // 18  Cheque/Transferencia/Depósito
            dgiiMonto($d[3] ?? 0),                                       // 19  Tarjeta
            dgiiMonto($d[4] ?? 0),                                       // 20  Venta a crédito
            dgiiMonto($d[5] ?? 0),                                       // 21  Bonos
            dgiiMonto($d[6] ?? 0),                                       // 22  Permuta
            dgiiMonto($d[7] ?? 0),                                       // 23  Otras formas
        ]);
    }
    return implode(DGII_SALTO, $lineas) . DGII_SALTO;
}

function dgiiTxt608(array $filas, array $empresa, string $periodo): string
{
    $lineas = [dgiiEncabezado('608', $empresa['rnc'], $periodo, count($filas))];
    foreach ($filas as $f) {
        $lineas[] = dgiiLinea([
            strtoupper($f['ncf']),
            dgiiFecha($f['fecha_comprobante']),
            (string) (int) $f['tipo_anulacion'],
        ]);
    }
    return implode(DGII_SALTO, $lineas) . DGII_SALTO;
}

/** Devuelve las filas del período según el formato. */
function dgiiFilas(string $formato, string $periodo, ?int $sucursalId = null): array
{
    return match ($formato) {
        '606' => dgiiFilas606($periodo, $sucursalId),
        '607' => dgiiFilas607($periodo, $sucursalId),
        '608' => dgiiFilas608($periodo, $sucursalId),
        default => throw new InvalidArgumentException('Formato DGII no soportado.'),
    };
}

/** Construye el contenido completo del TXT. */
function dgiiTxt(string $formato, array $filas, array $empresa, string $periodo): string
{
    return match ($formato) {
        '606' => dgiiTxt606($filas, $empresa, $periodo),
        '607' => dgiiTxt607($filas, $empresa, $periodo),
        '608' => dgiiTxt608($filas, $empresa, $periodo),
        default => throw new InvalidArgumentException('Formato DGII no soportado.'),
    };
}

// ---------------------------------------------------------------------------
//  IT-1 — Declaración Jurada del ITBIS
// ---------------------------------------------------------------------------

/**
 * Resumen del IT-1 del período.
 *
 * Se deriva de las MISMAS filas que se declaran en el 606 y el 607, así el IT-1
 * siempre cuadra con lo que se envió. Aquí no se reimplementan las reglas de
 * inclusión (NCF emitido, no anulada, período): son las de dgiiFilas606/607.
 *
 * A diferencia del 606/607/608, el IT-1 NO es un archivo de envío: es la
 * declaración que se llena en la Oficina Virtual. Esto produce las cifras para
 * transcribirla, no un TXT.
 *
 * El desglose gravado/exento sale de `venta_detalles`, no de `ventas`: la línea
 * es la fuente de verdad porque una misma venta puede mezclar productos gravados
 * y exentos. Las muestras (es_muestra) ya entran con subtotal 0, así que no
 * suman operaciones por construcción.
 */
function dgiiIt1(string $periodo, ?int $sucursalId = null): array
{
    $ventas  = dgiiFilas607($periodo, $sucursalId);
    $compras = dgiiFilas606($periodo, $sucursalId);

    // --- Ventas: operaciones gravadas vs exentas, tomadas de la línea ---
    $porVenta = [];
    if ($ventas) {
        $ids = array_column($ventas, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        foreach (qAll(
            "SELECT venta_id,
                    COALESCE(SUM(CASE WHEN itbis > 0 THEN subtotal ELSE 0 END), 0) AS gravada,
                    COALESCE(SUM(CASE WHEN itbis <= 0 THEN subtotal ELSE 0 END), 0) AS exenta
               FROM venta_detalles
              WHERE venta_id IN ($ph)
              GROUP BY venta_id",
            $ids
        ) as $r) {
            $porVenta[(int) $r['venta_id']] = $r;
        }
    }

    $gravadas = 0.0; $exentas = 0.0; $debito = 0.0;
    $retenidoTerceros = 0.0; $percibidoVentas = 0.0; $totalFacturado = 0.0;

    foreach ($ventas as $v) {
        $sub  = (float) $v['subtotal'];
        $desc = (float) $v['descuento'];
        // El descuento se guarda a nivel de venta, no de línea: se prorratea
        // sobre la base para no declarar operaciones más altas que las reales.
        $factor = $sub > 0 ? ($sub - $desc) / $sub : 1.0;
        $d = $porVenta[(int) $v['id']] ?? ['gravada' => 0, 'exenta' => 0];

        $gravadas += (float) $d['gravada'] * $factor;
        $exentas  += (float) $d['exenta']  * $factor;
        $debito           += (float) $v['itbis'];
        $retenidoTerceros += (float) ($v['itbis_retenido_terceros'] ?? 0);
        $percibidoVentas  += (float) ($v['itbis_percibido'] ?? 0);
        $totalFacturado   += (float) $v['total'];
    }

    // --- Compras: ITBIS adelantado (crédito fiscal) ---
    // Misma regla que la columna 15 del 606: facturado − llevado al costo.
    // No se reimplementa aparte para que 606 e IT-1 nunca discrepen.
    $credito = 0.0; $retenidoAProveedores = 0.0; $totalCompras = 0.0;
    foreach ($compras as $c) {
        $credito              += (float) $c['itbis'] - (float) ($c['itbis_costo'] ?? 0);
        $retenidoAProveedores += (float) ($c['itbis_retenido'] ?? 0);
        $totalCompras         += (float) $c['monto_bienes'] + (float) $c['monto_servicios'];
    }

    // Débito − crédito: positivo es ITBIS a pagar, negativo es saldo a favor.
    $diferencia = $debito - $credito;
    // Lo que tus clientes te retuvieron ya lo enteraron ellos: se acredita.
    // Lo que tú le retuviste a un proveedor lo debes enterar tú: se suma.
    $aPagar = $diferencia - $retenidoTerceros - $percibidoVentas + $retenidoAProveedores;

    return [
        'periodo'                => $periodo,
        'ventas_registros'       => count($ventas),
        'compras_registros'      => count($compras),
        'operaciones'            => round($gravadas + $exentas, 2),
        'gravadas'               => round($gravadas, 2),
        'exentas'                => round($exentas, 2),
        'total_facturado'        => round($totalFacturado, 2),
        'total_compras'          => round($totalCompras, 2),
        'debito'                 => round($debito, 2),
        'credito'                => round($credito, 2),
        'diferencia'             => round($diferencia, 2),
        'retenido_terceros'      => round($retenidoTerceros, 2),
        'percibido_ventas'       => round($percibidoVentas, 2),
        'retenido_a_proveedores' => round($retenidoAProveedores, 2),
        'a_pagar'                => round($aPagar, 2),
    ];
}

/**
 * Avisos del IT-1: cosas que el sistema no puede resolver solo y el contador
 * debe considerar antes de declarar. No bloquean nada.
 */
function dgiiIt1Avisos(string $periodo, array $it1, array $empresa): array
{
    $avisos = [];

    if (empty($empresa['rnc'])) {
        $avisos[] = ['ref' => 'Empresa', 'msg' => 'Falta el RNC en Configuración → Empresa.'];
    }

    // Una devolución rebaja el ITBIS facturado mediante una nota de crédito (B04).
    // El sistema registra la devolución pero todavía no emite ese comprobante, así
    // que el débito fiscal de arriba no la descuenta: hay que hacerlo a mano.
    $dev = qOne(
        "SELECT COUNT(*) AS n, COALESCE(SUM(d.total), 0) AS monto
           FROM devoluciones d
           JOIN ventas v ON v.id = d.venta_id
          WHERE DATE_FORMAT(d.created_at, '%Y%m') = ?",
        [$periodo]
    );
    if ($dev && (int) $dev['n'] > 0) {
        $avisos[] = [
            'ref' => 'Devoluciones',
            'msg' => (int) $dev['n'] . ' devolución(es) por ' . money($dev['monto']) . ' en el período. '
                   . 'El ITBIS facturado no las descuenta: se rebajan con una nota de crédito (B04) que aún se emite fuera del sistema.',
        ];
    }

    if ($it1['diferencia'] < 0) {
        $avisos[] = ['ref' => 'Saldo a favor', 'msg' => 'El crédito fiscal supera al débito: el período arroja saldo a favor, no pago.'];
    }

    if ($it1['ventas_registros'] === 0 && $it1['compras_registros'] === 0) {
        $avisos[] = ['ref' => 'Sin operaciones', 'msg' => 'No hubo operaciones con NCF. La declaración se presenta igual, en cero.'];
    }

    return $avisos;
}
