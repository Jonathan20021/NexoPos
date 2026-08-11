<?php
/**
 * Generador de la trama TXT del e-CF.
 *
 * Fuente: LUG-OPE-PT-001 «Estructura Archivo TXT» v03 y LUG-OPE-MA-006 «Datos
 * TXT para Desarrolladores» v1. Cada layout de abajo está verificado contra las
 * tramas oficiales del archivo «Ejemplos básicos_Archivos TXT.xlsx».
 *
 * ---------------------------------------------------------------------------
 *  POR QUÉ HAY UN LAYOUT POR TIPO Y NO UNO PARAMETRIZADO
 * ---------------------------------------------------------------------------
 *  La sección ITEM NO tiene las mismas posiciones en todos los tipos:
 *
 *    · El 31 lleva Indicador de Retención, ITBIS Retenido e ISR Retenido en las
 *      posiciones 4-6, y NO lleva los campos de minería.
 *    · El 32 no lleva los de retención (su posición 4 es NombreItem) pero SÍ
 *      lleva PesoNetoKilogramo, PesoNetoMineria, TipoAfiliacion y Liquidacion.
 *    · El 33 y el 34 llevan ambos grupos: 33 campos por línea.
 *
 *  Se ve en los ejemplos oficiales: `ITEM|1||1||||PRODUCTO…` (31) frente a
 *  `ITEM|1||1|PRODUCTO…` (32). Un solo constructor "inteligente" con banderas
 *  terminaría corriendo un campo de lugar sin que nadie lo note hasta que la
 *  DGII rechace el lote.
 *
 * ---------------------------------------------------------------------------
 *  REGLAS DEL FORMATO
 * ---------------------------------------------------------------------------
 *  · Separador de campos: «|». Fin de línea: CRLF. Codificación: UTF-8 sin BOM.
 *  · Una sección sin datos NO se escribe (MA-001 §10.b).
 *  · Los campos vacíos del final SÍ se escriben: los ejemplos oficiales cierran
 *    EMIS y COMP con pipes de relleno hasta completar la sección.
 *  · Campos repetibles: entre corchetes, subcampos con «|» y repeticiones
 *    con «;»  →  [EAN13|746739;EAN13|695834]
 */

require_once __DIR__ . '/ecf_catalogos.php';

/* ============================================================
 *  METADATOS DE CAMPO (largo máximo y tipo)
 * ============================================================
 *  Los nombres de campo son consistentes entre tipos de e-CF, así que el largo
 *  máximo se declara una sola vez. Truncar aquí evita rechazos por desborde:
 *  un nombre de producto de 90 caracteres reventaría el campo de 80.
 */
function ecfCamposMeta(): array
{
    static $meta = null;
    if ($meta !== null) return $meta;

    return $meta = [
        // --- IDOC ---
        'TipoeCF'                   => ['max' => 2,    'tipo' => 'num'],
        'eNCF'                      => ['max' => 13,   'tipo' => 'alfanum'],
        'FechaVencimientoSecuencia' => ['max' => 10,   'tipo' => 'alfanum'],
        'IndicadorNotaCredito'      => ['max' => 1,    'tipo' => 'num'],
        'IndicadorEnvioDiferido'    => ['max' => 1,    'tipo' => 'num'],
        'IndicadorMontoGravado'     => ['max' => 1,    'tipo' => 'num'],
        'TipoIngresos'              => ['max' => 2,    'tipo' => 'num'],
        'TipoPago'                  => ['max' => 1,    'tipo' => 'num'],
        'FechaLimitePago'           => ['max' => 10,   'tipo' => 'alfanum'],
        'TerminoPago'               => ['max' => 15,   'tipo' => 'alfanum'],
        'TipoCuentaPago'            => ['max' => 2,    'tipo' => 'alfa'],
        'NumeroCuentaPago'          => ['max' => 28,   'tipo' => 'alfanum'],
        'BancoPago'                 => ['max' => 75,   'tipo' => 'alfanum'],
        'FechaDesde'                => ['max' => 10,   'tipo' => 'alfanum'],
        'FechaHasta'                => ['max' => 10,   'tipo' => 'alfanum'],
        'FechaEmision'              => ['max' => 10,   'tipo' => 'alfanum'],

        // --- EMIS ---
        'RNCEmisor'                 => ['max' => 11,   'tipo' => 'num'],
        'RazonSocialEmisor'         => ['max' => 150,  'tipo' => 'alfanum'],
        'NombreComercial'           => ['max' => 150,  'tipo' => 'alfanum'],
        'Sucursal'                  => ['max' => 20,   'tipo' => 'alfanum'],
        'DireccionEmisor'           => ['max' => 100,  'tipo' => 'alfanum'],
        'Municipio'                 => ['max' => 6,    'tipo' => 'num'],
        'Provincia'                 => ['max' => 6,    'tipo' => 'num'],
        'TelefonoEmisor'            => ['max' => 12,   'tipo' => 'repetible'],
        'CorreoEmisor'              => ['max' => 80,   'tipo' => 'alfanum'],
        'WebSite'                   => ['max' => 50,   'tipo' => 'alfanum'],
        'ActividadEconomica'        => ['max' => 100,  'tipo' => 'alfanum'],
        'CodigoVendedor'            => ['max' => 60,   'tipo' => 'alfanum'],
        'NumeroFacturaInterna'      => ['max' => 20,   'tipo' => 'alfanum'],
        'NumeroPedidoInterno'       => ['max' => 20,   'tipo' => 'num'],
        'ZonaVenta'                 => ['max' => 20,   'tipo' => 'alfanum'],
        'RutaVenta'                 => ['max' => 20,   'tipo' => 'alfanum'],
        'InformacionAdicionalEmisor'=> ['max' => 250,  'tipo' => 'alfanum'],

        // --- COMP ---
        'RNCComprador'              => ['max' => 11,   'tipo' => 'num'],
        'IdentificadorExtranjero'   => ['max' => 20,   'tipo' => 'alfanum'],
        'RazonSocialComprador'      => ['max' => 150,  'tipo' => 'alfanum'],
        'ContactoComprador'         => ['max' => 80,   'tipo' => 'alfanum'],
        'CorreoComprador'           => ['max' => 80,   'tipo' => 'alfanum'],
        'DireccionComprador'        => ['max' => 100,  'tipo' => 'alfanum'],
        'MunicipioComprador'        => ['max' => 6,    'tipo' => 'num'],
        'ProvinciaComprador'        => ['max' => 6,    'tipo' => 'num'],
        'FechaEntrega'              => ['max' => 10,   'tipo' => 'alfanum'],
        'ContactoEntrega'           => ['max' => 100,  'tipo' => 'alfanum'],
        'DireccionEntrega'          => ['max' => 100,  'tipo' => 'alfanum'],
        'TelefonoAdicional'         => ['max' => 12,   'tipo' => 'alfanum'],
        'FechaOrdenCompra'          => ['max' => 10,   'tipo' => 'alfanum'],
        'NumeroOrdenCompra'         => ['max' => 20,   'tipo' => 'alfanum'],
        'CodigoInternoComprador'    => ['max' => 20,   'tipo' => 'alfanum'],
        'ResponsablePago'           => ['max' => 20,   'tipo' => 'alfa'],
        'Informacionadicionalcomprador' => ['max' => 150, 'tipo' => 'alfanum'],

        // --- INFA ---
        'FechaEmbarque'             => ['max' => 10,   'tipo' => 'alfanum'],
        'NumeroEmbarque'            => ['max' => 25,   'tipo' => 'alfanum'],
        'NumeroContenedor'          => ['max' => 100,  'tipo' => 'alfanum'],
        'NumeroReferencia'          => ['max' => 20,   'tipo' => 'num'],
        'PesoBruto'                 => ['max' => 18,   'tipo' => 'num'],
        'PesoNeto'                  => ['max' => 18,   'tipo' => 'num'],
        'UnidadPesoBruto'           => ['max' => 2,    'tipo' => 'num'],
        'UnidadPesoNeto'            => ['max' => 2,    'tipo' => 'num'],
        'CantidadBulto'             => ['max' => 18,   'tipo' => 'num'],
        'UnidadBulto'               => ['max' => 2,    'tipo' => 'num'],
        'VolumenBulto'              => ['max' => 18,   'tipo' => 'num'],
        'UnidadVolumen'             => ['max' => 2,    'tipo' => 'num'],

        // --- TRAN ---
        'Conductor'                 => ['max' => 20,   'tipo' => 'alfanum'],
        'DocumentoTransporte'       => ['max' => 20,   'tipo' => 'num'],
        'Ficha'                     => ['max' => 10,   'tipo' => 'alfanum'],
        'Placa'                     => ['max' => 7,    'tipo' => 'alfanum'],
        'RutaTransporte'            => ['max' => 20,   'tipo' => 'alfanum'],
        'ZonaTransporte'            => ['max' => 20,   'tipo' => 'alfanum'],
        'NumeroAlbaran'             => ['max' => 20,   'tipo' => 'alfanum'],

        // --- OTMN ---
        'TipoMoneda'                => ['max' => 3,    'tipo' => 'alfa'],
        'TipoCambio'                => ['max' => 7,    'tipo' => 'num'],

        // --- ITEM ---
        'NumeroLinea'               => ['max' => 5,    'tipo' => 'num'],
        'TipoCodigoItem'            => ['max' => 50,   'tipo' => 'repetible'],
        'IndicadorFacturacion'      => ['max' => 1,    'tipo' => 'num'],
        'IndicadorAgenteRetencionoPercepcion' => ['max' => 1, 'tipo' => 'num'],
        'MontoITBISRetenido'        => ['max' => 18,   'tipo' => 'num'],
        'MontoISRRetenido'          => ['max' => 18,   'tipo' => 'num'],
        'NombreItem'                => ['max' => 80,   'tipo' => 'alfanum'],
        'IndicadorBienoServicio'    => ['max' => 1,    'tipo' => 'num'],
        'DescripcionItem'           => ['max' => 1000, 'tipo' => 'alfanum'],
        'CantidadItem'              => ['max' => 18,   'tipo' => 'num'],
        'UnidadMedida'              => ['max' => 2,    'tipo' => 'num'],
        'CantidadReferencia'        => ['max' => 18,   'tipo' => 'num'],
        'UnidadReferencia'          => ['max' => 2,    'tipo' => 'num'],
        'SubcantidadCodigo'         => ['max' => 50,   'tipo' => 'repetible'],
        'GradosAlcohol'             => ['max' => 5,    'tipo' => 'num'],
        'PrecioUnitarioReferencia'  => ['max' => 18,   'tipo' => 'num'],
        'FechaElaboracion'          => ['max' => 10,   'tipo' => 'alfanum'],
        'FechaVencimientoItem'      => ['max' => 10,   'tipo' => 'alfanum'],
        'PesoNetoKilogramo'         => ['max' => 19,   'tipo' => 'num'],
        'PesoNetoMineria'           => ['max' => 19,   'tipo' => 'num'],
        'TipoAfiliacion'            => ['max' => 1,    'tipo' => 'num'],
        'Liquidacion'               => ['max' => 1,    'tipo' => 'num'],
        'PrecioUnitarioItem'        => ['max' => 20,   'tipo' => 'num'],
        'DescuentoMonto'            => ['max' => 18,   'tipo' => 'num'],
        'SubDescuento'              => ['max' => 60,   'tipo' => 'repetible'],
        'RecargoMonto'              => ['max' => 18,   'tipo' => 'num'],
        'SubRecargo'                => ['max' => 60,   'tipo' => 'repetible'],
        'TipoImpuesto'              => ['max' => 3,    'tipo' => 'repetible'],
        'PrecioOtraMoneda'          => ['max' => 20,   'tipo' => 'num'],
        'DescuentoOtraMoneda'       => ['max' => 18,   'tipo' => 'num'],
        'RecargoOtraMoneda'         => ['max' => 18,   'tipo' => 'num'],
        'MontoItemOtraMoneda'       => ['max' => 18,   'tipo' => 'num'],
        'MontoItem'                 => ['max' => 18,   'tipo' => 'num'],

        // --- FPAG ---
        'FormaPago'                 => ['max' => 2,    'tipo' => 'num'],
        'MontoPago'                 => ['max' => 18,   'tipo' => 'num'],

        // --- DERE ---
        'TipoAjuste'                => ['max' => 1,    'tipo' => 'alfa'],
        'IndicadorNorma1007'        => ['max' => 1,    'tipo' => 'num'],
        'DescripcionDescuentooRecargo' => ['max' => 45, 'tipo' => 'alfa'],
        'TipoValor'                 => ['max' => 1,    'tipo' => 'alfa'],
        'ValorDescuentooRecargo'    => ['max' => 5,    'tipo' => 'num'],
        'MontoDescuentooRecargo'    => ['max' => 18,   'tipo' => 'num'],
        'MontoDescuentooRecargoOtraMoneda' => ['max' => 18, 'tipo' => 'num'],
        'IndicadorFacturacionDescuentooRecargo' => ['max' => 1, 'tipo' => 'num'],

        // --- INFR ---
        'NCFModificado'             => ['max' => 19,   'tipo' => 'alfanum'],
        'RNCOtroContribuyente'      => ['max' => 11,   'tipo' => 'num'],
        'FechaNCFModificado'        => ['max' => 10,   'tipo' => 'alfanum'],
        'CodigoModificacion'        => ['max' => 1,    'tipo' => 'num'],
        'RazonModificacion'         => ['max' => 90,   'tipo' => 'alfa'],
    ];
}

/* ============================================================
 *  LAYOUTS POR TIPO DE e-CF
 * ============================================================ */

/** Secciones comunes a varios tipos, para no repetirlas. */
function ecfSeccionesComunes(): array
{
    return [
        'EMIS' => [
            'RNCEmisor', 'RazonSocialEmisor', 'NombreComercial', 'Sucursal', 'DireccionEmisor',
            'Municipio', 'Provincia', 'TelefonoEmisor', 'CorreoEmisor', 'WebSite',
            'ActividadEconomica', 'CodigoVendedor', 'NumeroFacturaInterna', 'NumeroPedidoInterno',
            'ZonaVenta', 'RutaVenta', 'InformacionAdicionalEmisor',
        ],
        // COMP del 31: exige RNC y razón social, y NO tiene IdentificadorExtranjero.
        'COMP_31' => [
            'RNCComprador', 'RazonSocialComprador', 'ContactoComprador', 'CorreoComprador',
            'DireccionComprador', 'MunicipioComprador', 'ProvinciaComprador', 'FechaEntrega',
            'ContactoEntrega', 'DireccionEntrega', 'TelefonoAdicional', 'FechaOrdenCompra',
            'NumeroOrdenCompra', 'CodigoInternoComprador', 'ResponsablePago',
            'Informacionadicionalcomprador',
        ],
        // COMP del 32/33/34: intercala IdentificadorExtranjero en la posición 2.
        'COMP_32' => [
            'RNCComprador', 'IdentificadorExtranjero', 'RazonSocialComprador', 'ContactoComprador',
            'CorreoComprador', 'DireccionComprador', 'MunicipioComprador', 'ProvinciaComprador',
            'FechaEntrega', 'ContactoEntrega', 'DireccionEntrega', 'TelefonoAdicional',
            'FechaOrdenCompra', 'NumeroOrdenCompra', 'CodigoInternoComprador', 'ResponsablePago',
            'Informacionadicionalcomprador',
        ],
        'INFA' => [
            'FechaEmbarque', 'NumeroEmbarque', 'NumeroContenedor', 'NumeroReferencia',
            'PesoBruto', 'PesoNeto', 'UnidadPesoBruto', 'UnidadPesoNeto',
            'CantidadBulto', 'UnidadBulto', 'VolumenBulto', 'UnidadVolumen',
        ],
        'TRAN' => [
            'Conductor', 'DocumentoTransporte', 'Ficha', 'Placa',
            'RutaTransporte', 'ZonaTransporte', 'NumeroAlbaran',
        ],
        'OTMN' => ['TipoMoneda', 'TipoCambio'],
        'FPAG' => ['FormaPago', 'MontoPago'],
        'DERE' => [
            'NumeroLinea', 'TipoAjuste', 'IndicadorNorma1007', 'DescripcionDescuentooRecargo',
            'TipoValor', 'ValorDescuentooRecargo', 'MontoDescuentooRecargo',
            'MontoDescuentooRecargoOtraMoneda', 'IndicadorFacturacionDescuentooRecargo',
        ],
        'INFR' => [
            'NCFModificado', 'RNCOtroContribuyente', 'FechaNCFModificado',
            'CodigoModificacion', 'RazonModificacion',
        ],
    ];
}

/**
 * Layout completo por tipo de e-CF: sección => lista ordenada de campos.
 * El orden de las claves es el orden en que se escriben las líneas.
 */
function ecfLayout(string $tipoEcf): array
{
    $c = ecfSeccionesComunes();

    // Bloque de retenciones: solo 31, 33 y 34 (el 32 no lo lleva).
    $retenciones = ['IndicadorAgenteRetencionoPercepcion', 'MontoITBISRetenido', 'MontoISRRetenido'];
    // Bloque de minería: solo 32, 33 y 34 (el 31 no lo lleva).
    $mineria = ['PesoNetoKilogramo', 'PesoNetoMineria', 'TipoAfiliacion', 'Liquidacion'];

    $itemBase = function (array $conRetencion, array $conMineria): array {
        return array_merge(
            ['NumeroLinea', 'TipoCodigoItem', 'IndicadorFacturacion'],
            $conRetencion,
            ['NombreItem', 'IndicadorBienoServicio', 'DescripcionItem', 'CantidadItem',
             'UnidadMedida', 'CantidadReferencia', 'UnidadReferencia', 'SubcantidadCodigo',
             'GradosAlcohol', 'PrecioUnitarioReferencia', 'FechaElaboracion', 'FechaVencimientoItem'],
            $conMineria,
            ['PrecioUnitarioItem', 'DescuentoMonto', 'SubDescuento', 'RecargoMonto', 'SubRecargo',
             'TipoImpuesto', 'PrecioOtraMoneda', 'DescuentoOtraMoneda', 'RecargoOtraMoneda',
             'MontoItemOtraMoneda', 'MontoItem']
        );
    };

    $idocLargo = [
        'TipoeCF', 'eNCF', 'FechaVencimientoSecuencia', 'IndicadorEnvioDiferido',
        'IndicadorMontoGravado', 'TipoIngresos', 'TipoPago', 'FechaLimitePago', 'TerminoPago',
        'TipoCuentaPago', 'NumeroCuentaPago', 'BancoPago', 'FechaDesde', 'FechaHasta', 'FechaEmision',
    ];

    switch ($tipoEcf) {
        case '31':  // Factura de Crédito Fiscal — 29 campos por ítem
            return [
                'IDOC' => $idocLargo,
                'EMIS' => $c['EMIS'],
                'COMP' => $c['COMP_31'],
                'INFA' => $c['INFA'],
                'TRAN' => $c['TRAN'],
                'OTMN' => $c['OTMN'],
                'ITEM' => $itemBase($retenciones, []),
                'DERE' => $c['DERE'],
                'FPAG' => $c['FPAG'],
            ];

        case '32':  // Factura de Consumo — sin FechaVencimientoSecuencia, 30 campos por ítem
            return [
                'IDOC' => array_values(array_diff($idocLargo, ['FechaVencimientoSecuencia'])),
                'EMIS' => $c['EMIS'],
                'COMP' => $c['COMP_32'],
                'INFA' => $c['INFA'],
                'TRAN' => $c['TRAN'],
                'OTMN' => $c['OTMN'],
                'ITEM' => $itemBase([], $mineria),
                'DERE' => $c['DERE'],
                'FPAG' => $c['FPAG'],
            ];

        case '33':  // Nota de Débito — 33 campos por ítem, con INFR
            return [
                'IDOC' => $idocLargo,
                'EMIS' => $c['EMIS'],
                'COMP' => $c['COMP_32'],
                'INFA' => $c['INFA'],
                'TRAN' => $c['TRAN'],
                'OTMN' => $c['OTMN'],
                'ITEM' => $itemBase($retenciones, $mineria),
                'DERE' => $c['DERE'],
                'FPAG' => $c['FPAG'],
                'INFR' => $c['INFR'],
            ];

        case '34':  // Nota de Crédito — SIN FPAG (una nota de crédito no se cobra)
            return [
                'IDOC' => [
                    'TipoeCF', 'eNCF', 'IndicadorNotaCredito', 'IndicadorEnvioDiferido',
                    'IndicadorMontoGravado', 'TipoIngresos', 'TipoPago', 'FechaLimitePago',
                    'FechaDesde', 'FechaHasta', 'FechaEmision',
                ],
                'EMIS' => $c['EMIS'],
                'COMP' => $c['COMP_32'],
                'INFA' => $c['INFA'],
                'TRAN' => $c['TRAN'],
                'OTMN' => $c['OTMN'],
                'ITEM' => $itemBase($retenciones, $mineria),
                'DERE' => $c['DERE'],
                'INFR' => $c['INFR'],
            ];

        case '44':  // Comprobante de Regímenes Especiales — el que NO paga ITBIS
            //
            // Es el comprobante de quien está exento por régimen: zonas francas,
            // diplomáticos, instituciones sin fines de lucro. Se emite igual que
            // una factura, pero el ítem va con IndicadorFacturacion = 4 (exento)
            // y sin impuesto.
            //
            // Dos diferencias de estructura frente a los demás:
            //
            //  · El ítem NO lleva el bloque de referencia (cantidad y unidad de
            //    referencia, subcantidad, grados de alcohol, precio de
            //    referencia): son campos de bebidas alcohólicas y combustibles,
            //    que tributan por volumen y no encajan en un exento.
            //
            //  · El descuento NO lleva IndicadorNorma1007. La norma 10-07 regula
            //    la retención en el crédito fiscal; en un régimen especial no
            //    hay nada que retener.
            return [
                'IDOC' => array_values(array_diff($idocLargo, ['FechaVencimientoSecuencia'])),
                'EMIS' => $c['EMIS'],
                'COMP' => $c['COMP_32'],
                'INFA' => $c['INFA'],
                'TRAN' => $c['TRAN'],
                'OTMN' => $c['OTMN'],
                'ITEM' => array_merge(
                    ['NumeroLinea', 'TipoCodigoItem', 'IndicadorFacturacion', 'NombreItem',
                     'IndicadorBienoServicio', 'DescripcionItem', 'CantidadItem', 'UnidadMedida',
                     'FechaElaboracion', 'FechaVencimientoItem', 'PrecioUnitarioItem',
                     'DescuentoMonto', 'SubDescuento', 'RecargoMonto', 'SubRecargo',
                     'TipoImpuesto', 'PrecioOtraMoneda', 'DescuentoOtraMoneda',
                     'RecargoOtraMoneda', 'MontoItemOtraMoneda', 'MontoItem']
                ),
                'DERE' => array_values(array_diff($c['DERE'], ['IndicadorNorma1007'])),
                'FPAG' => $c['FPAG'],
            ];

        case '45':  // Comprobante Gubernamental — ventas al Estado
            //
            // Lo pide cualquier institución pública al comprar. Estructuralmente
            // es el más parecido al crédito fiscal —mismo IDOC largo y mismo
            // bloque de comprador, porque el Estado se identifica con su RNC—,
            // pero el ítem va limpio: ni retenciones ni minería.
            return [
                'IDOC' => $idocLargo,
                'EMIS' => $c['EMIS'],
                'COMP' => $c['COMP_31'],
                'INFA' => $c['INFA'],
                'TRAN' => $c['TRAN'],
                'OTMN' => $c['OTMN'],
                'ITEM' => $itemBase([], []),
                'DERE' => $c['DERE'],
                'FPAG' => $c['FPAG'],
            ];
    }

    throw new InvalidArgumentException(
        'El tipo de e-CF «' . $tipoEcf . '» no tiene generador implementado. '
        . 'Disponibles: ' . implode(', ', ecfTiposSoportados()) . '.'
    );
}

/** Secciones que se repiten (una línea por elemento) frente a las de línea única. */
function ecfSeccionesRepetidas(): array
{
    return ['ITEM', 'FPAG', 'DERE'];
}

/*
 * ---------------------------------------------------------------------------
 *  ORDEN DE LAS SECCIONES: DERE VA ANTES QUE FPAG
 * ---------------------------------------------------------------------------
 *  El índice de secciones de MA-006 y PT-001 las enumera «… ITEM, FPAG, DERE»,
 *  pero las 17 tramas de ejemplo del proveedor que usan DERE —de los tipos 31,
 *  32, 33, 34, 41, 44, 45 y 46, sin una sola excepción— la escriben JUSTO
 *  DESPUÉS de los ITEM y dejan FPAG al final:
 *
 *      ITEM|4||4|PRODUCTO EXENTO|…|50000.00
 *      DERE|1|D||DESCUENTO GLOBAL|%|10.00|37500.00||1
 *      FPAG|3|500000.00
 *
 *  Se sigue el ejemplo y no el índice: el índice es una tabla descriptiva de
 *  qué secciones existen, mientras que los ejemplos son tramas que el proveedor
 *  entrega para copiar y que presumiblemente pasaron por su validador. Tiene
 *  sentido estructural además: DERE ajusta los totales de ITEM y FPAG cierra.
 *
 *  Está anotado como punto a confirmar con el consultor. Si respondiera que el
 *  orden correcto es el del índice, basta con intercambiar las dos claves en
 *  cada layout de ecfLayout(): no hay más lógica que dependa de esto.
 */

/* ============================================================
 *  SANEADO DE VALORES
 * ============================================================ */

/**
 * Limpia un valor de texto para que no rompa la trama.
 *
 * El «|» separa campos y el CRLF separa líneas: si cualquiera se cuela dentro
 * del nombre de un producto, todo lo que sigue se corre de posición y el
 * documento se emite mal (o peor: se emite bien pero con los datos cambiados).
 * También se quitan corchetes y punto y coma, que delimitan las repeticiones.
 */
function ecfTexto($valor, int $max): string
{
    $s = (string) $valor;
    $s = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $s);
    $s = str_replace(['|', '[', ']', ';'], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);
    return mb_substr($s, 0, $max, 'UTF-8');
}

/**
 * Arma un campo repetible: [a|b;c|d].
 *
 * @param array $repeticiones Lista de repeticiones. Cada una puede ser un
 *                            escalar (campo simple) o un arreglo de subcampos.
 */
function ecfRepetible(array $repeticiones): string
{
    $partes = [];
    foreach ($repeticiones as $rep) {
        $sub = is_array($rep) ? $rep : [$rep];
        $limpio = array_map(static function ($v) {
            return str_replace(['|', '[', ']', ';'], '', (string) $v);
        }, $sub);
        // Una repetición totalmente vacía no aporta nada y ensucia la trama.
        if (implode('', $limpio) === '') continue;
        $partes[] = implode('|', $limpio);
    }
    return $partes ? '[' . implode(';', $partes) . ']' : '';
}

/** Aplica el metadato del campo (largo máximo y tipo) a un valor ya calculado. */
function ecfValorCampo(string $campo, $valor): string
{
    if ($valor === null || $valor === '') return '';

    $meta = ecfCamposMeta()[$campo] ?? ['max' => 255, 'tipo' => 'alfanum'];

    // Los repetibles ya vienen formateados por ecfRepetible(): no se tocan.
    if ($meta['tipo'] === 'repetible') return (string) $valor;

    if ($meta['tipo'] === 'num') {
        // Se conserva el formato decimal que ya trae (ecfMonto/ecfPrecio).
        return (string) $valor;
    }

    return ecfTexto($valor, $meta['max']);
}

/* ============================================================
 *  CONSTRUCCIÓN DE LA TRAMA
 * ============================================================ */

/**
 * Construye la trama TXT completa.
 *
 * @param array $doc Documento normalizado:
 *   [
 *     'tipo_ecf' => '32',
 *     'IDOC' => ['eNCF' => 'E320000000001', ...],
 *     'EMIS' => [...], 'COMP' => [...], 'INFA' => [...], 'TRAN' => [...],
 *     'OTMN' => [...],
 *     'ITEM' => [ [...], [...] ],   // una entrada por línea
 *     'FPAG' => [ [...] ],
 *     'DERE' => [ [...] ],
 *     'INFR' => [...],
 *   ]
 * @return string Trama con saltos CRLF, sin BOM.
 */
function ecfConstruirTrama(array $doc): string
{
    $tipo = (string) ($doc['tipo_ecf'] ?? '');
    $layout = ecfLayout($tipo);
    $repetidas = ecfSeccionesRepetidas();

    $lineas = [];
    foreach ($layout as $seccion => $campos) {
        $datos = $doc[$seccion] ?? null;

        // Una sección sin datos NO se escribe (MA-001 §10.b).
        if ($datos === null || $datos === [] ) continue;

        if (in_array($seccion, $repetidas, true)) {
            foreach ($datos as $fila) {
                if (!is_array($fila)) continue;
                $lineas[] = ecfLineaSeccion($seccion, $campos, $fila);
            }
            continue;
        }

        // Sección de línea única: si todos sus campos vienen vacíos, se omite.
        $tieneAlgo = false;
        foreach ($campos as $campo) {
            if (($datos[$campo] ?? '') !== '' && ($datos[$campo] ?? null) !== null) { $tieneAlgo = true; break; }
        }
        if (!$tieneAlgo) continue;

        $lineas[] = ecfLineaSeccion($seccion, $campos, $datos);
    }

    // CRLF, como en las tramas oficiales (verificado decodificando el Base64 del
    // ejemplo de MA-001 §6.7). Sin salto final.
    return implode("\r\n", $lineas);
}

/** Arma una línea: PREFIJO|campo1|campo2|… con todos los campos de la sección. */
function ecfLineaSeccion(string $seccion, array $campos, array $datos): string
{
    $valores = [$seccion];
    foreach ($campos as $campo) {
        $valores[] = ecfValorCampo($campo, $datos[$campo] ?? '');
    }
    return implode('|', $valores);
}

/**
 * Codifica la trama para el envío: UTF-8 sin BOM → Base64 (MA-001 §5.1.f/g).
 *
 * Se elimina el BOM si el dato de origen lo trajera: el manual advierte que un
 * BOM rompe la generación del XML con acentos y ñ.
 */
function ecfTramaBase64(string $trama): string
{
    $bom = "\xEF\xBB\xBF";
    if (strncmp($trama, $bom, 3) === 0) $trama = substr($trama, 3);
    return base64_encode($trama);
}

/* ============================================================
 *  LECTURA DE UNA TRAMA
 * ============================================================ */

/**
 * Parte una línea por «|» respetando los corchetes.
 *
 * Un split ingenuo rompería [EAN13|746739;EAN13|695834] en pedazos y correría
 * de posición todo lo que viene detrás.
 */
function ecfDividirLinea(string $linea): array
{
    $campos = [];
    $actual = '';
    $dentro = false;
    $n = strlen($linea);
    for ($i = 0; $i < $n; $i++) {
        $ch = $linea[$i];
        if ($ch === '[') { $dentro = true;  $actual .= $ch; continue; }
        if ($ch === ']') { $dentro = false; $actual .= $ch; continue; }
        if ($ch === '|' && !$dentro) { $campos[] = $actual; $actual = ''; continue; }
        $actual .= $ch;
    }
    $campos[] = $actual;
    return $campos;
}

/**
 * Convierte una trama TXT en la estructura que consume ecfConstruirTrama().
 * Sirve para releer lo que se envió y para las pruebas de ida y vuelta.
 *
 * @throws InvalidArgumentException si el tipo no tiene layout implementado.
 */
function ecfParsearTrama(string $trama): array
{
    $lineas = preg_split('/\r\n|\r|\n/', trim($trama));
    $primera = ecfDividirLinea($lineas[0] ?? '');
    $tipo = $primera[1] ?? '';

    $layout = ecfLayout($tipo);
    $repetidas = ecfSeccionesRepetidas();
    $doc = ['tipo_ecf' => $tipo];

    foreach ($lineas as $linea) {
        if (trim($linea) === '') continue;
        $campos = ecfDividirLinea($linea);
        $seccion = array_shift($campos);
        if (!isset($layout[$seccion])) continue;

        $fila = [];
        foreach ($layout[$seccion] as $i => $campo) {
            $fila[$campo] = $campos[$i] ?? '';
        }

        if (in_array($seccion, $repetidas, true)) {
            $doc[$seccion][] = $fila;
        } else {
            $doc[$seccion] = $fila;
        }
    }
    return $doc;
}

/* ============================================================
 *  VALIDACIÓN PREVIA AL ENVÍO
 * ============================================================ */

/**
 * Revisa la trama antes de gastar un e-NCF.
 *
 * Replica las reglas que el manual declara explícitas (MA-001 §5.2), porque un
 * rechazo del proveedor consume igual la secuencia y obliga a emitir una nota
 * de crédito para corregir.
 *
 * @return array Lista de mensajes de error. Vacía = listo para enviar.
 */
function ecfValidarDocumento(array $doc): array
{
    $errores = [];
    $tipo = (string) ($doc['tipo_ecf'] ?? '');

    if (!in_array($tipo, ecfTiposSoportados(), true)) {
        return ["El tipo de e-CF «$tipo» no está soportado por el generador."];
    }

    $idoc = $doc['IDOC'] ?? [];
    $emis = $doc['EMIS'] ?? [];
    $comp = $doc['COMP'] ?? [];
    $items = $doc['ITEM'] ?? [];

    // --- Identificación del documento ---
    if (!ecfENCFValido($idoc['eNCF'] ?? '')) {
        $errores[] = 'El e-NCF «' . ($idoc['eNCF'] ?? '') . '» no tiene el formato E + tipo + 10 dígitos.';
    } elseif (substr((string) $idoc['eNCF'], 1, 2) !== $tipo) {
        $errores[] = 'El e-NCF no corresponde al tipo de comprobante que se está emitiendo.';
    }
    if (empty($idoc['FechaEmision'])) $errores[] = 'Falta la fecha de emisión.';
    if (in_array($tipo, ['31', '33'], true) && empty($idoc['FechaVencimientoSecuencia'])) {
        $errores[] = 'El tipo ' . $tipo . ' exige la fecha de vencimiento de la secuencia autorizada.';
    }
    // Venta a crédito: el manual la marca condicional-obligatoria.
    if ((string) ($idoc['TipoPago'] ?? '') === '2' && empty($idoc['FechaLimitePago'])) {
        $errores[] = 'Una venta a crédito exige la fecha límite de pago.';
    }

    // --- Emisor ---
    $rncEmisor = preg_replace('/\D+/', '', (string) ($emis['RNCEmisor'] ?? ''));
    if (!in_array(strlen($rncEmisor), [9, 11], true)) {
        $errores[] = 'El RNC del emisor debe tener 9 u 11 dígitos (Configuración → Empresa).';
    }
    if (trim((string) ($emis['RazonSocialEmisor'] ?? '')) === '') $errores[] = 'Falta la razón social del emisor.';
    if (trim((string) ($emis['DireccionEmisor'] ?? '')) === '')   $errores[] = 'Falta la dirección del emisor.';

    // --- Comprador ---
    $rncComprador = preg_replace('/\D+/', '', (string) ($comp['RNCComprador'] ?? ''));
    $total = 0.0;
    foreach ($items as $it) $total += (float) ($it['MontoItem'] ?? 0);

    if ($tipo === '31') {
        // El crédito fiscal siempre identifica al comprador (obligatoriedad 1).
        if (!in_array(strlen($rncComprador), [9, 11], true)) {
            $errores[] = 'La Factura de Crédito Fiscal exige el RNC o cédula del comprador (9 u 11 dígitos).';
        }
        if (trim((string) ($comp['RazonSocialComprador'] ?? '')) === '') {
            $errores[] = 'La Factura de Crédito Fiscal exige la razón social del comprador.';
        }
    } elseif (in_array($tipo, ['32', '33', '34'], true) && $total >= 250000) {
        // Regla del umbral: PT-001, sección COMP del tipo 32.
        $tieneId = in_array(strlen($rncComprador), [9, 11], true)
                || trim((string) ($comp['IdentificadorExtranjero'] ?? '')) !== '';
        if (!$tieneId) {
            $errores[] = 'Desde RD$250,000.00 hay que identificar al comprador con RNC/cédula o identificador extranjero.';
        }
        if (trim((string) ($comp['RazonSocialComprador'] ?? '')) === '') {
            $errores[] = 'Desde RD$250,000.00 hay que informar el nombre o razón social del comprador.';
        }
    }

    // --- Líneas de detalle ---
    if (!$items) {
        $errores[] = 'El documento no tiene líneas de detalle.';
    }
    if (count($items) > 1000) {
        $errores[] = 'El e-CF admite un máximo de 1,000 líneas de detalle (tiene ' . count($items) . ').';
    }

    foreach ($items as $i => $it) {
        $n = $i + 1;
        if (trim((string) ($it['NombreItem'] ?? '')) === '') {
            $errores[] = "Línea $n: falta el nombre del ítem.";
        }
        $ind = $it['IndicadorFacturacion'] ?? '';
        if (!array_key_exists((int) $ind, ecfIndicadoresFacturacion()) || $ind === '') {
            $errores[] = "Línea $n: el indicador de facturación «$ind» no está en la Tabla 13.";
        }

        // Tolerancia por transacción (MA-001 §5.2.i): ±1 unidad entre el monto
        // declarado y precio × cantidad ajustado por descuentos y recargos.
        $cant     = (float) ($it['CantidadItem'] ?? 0);
        $precio   = (float) ($it['PrecioUnitarioItem'] ?? 0);
        $desc     = (float) ($it['DescuentoMonto'] ?? 0);
        $rec      = (float) ($it['RecargoMonto'] ?? 0);
        $declarado = (float) ($it['MontoItem'] ?? 0);
        $esperado  = ($cant * $precio) - $desc + $rec;
        if (abs($esperado - $declarado) > 1.0) {
            $errores[] = sprintf(
                'Línea %d: el monto declarado (%s) no cuadra con cantidad × precio − descuento + recargo (%s). '
                . 'La tolerancia es de ±1.00.',
                $n, ecfMonto($declarado), ecfMonto($esperado)
            );
        }
    }

    // --- Formas de pago ---
    // Solo se valida si la sección viene: FPAG es opcional (obligatoriedad 3) y
    // el tipo 34 ni siquiera la tiene.
    $fpag = $doc['FPAG'] ?? [];
    if ($fpag) {
        if (count($fpag) > 7) {
            $errores[] = 'Se admiten hasta 7 formas de pago distintas (hay ' . count($fpag) . ').';
        }
        foreach ($fpag as $p) {
            $forma = (int) ($p['FormaPago'] ?? 0);
            if (!array_key_exists($forma, ecfFormasPago())) {
                $errores[] = "La forma de pago «$forma» no está en la Tabla 6.";
            }
        }
    }

    // --- Información de referencia (notas de crédito y débito) ---
    if (in_array($tipo, ['33', '34'], true)) {
        $infr = $doc['INFR'] ?? [];
        if (trim((string) ($infr['NCFModificado'] ?? '')) === '') {
            $errores[] = 'Una nota de crédito o débito debe referenciar el comprobante que modifica.';
        }
        if (empty($infr['FechaNCFModificado'])) {
            $errores[] = 'Falta la fecha del comprobante modificado.';
        }
        $cod = (int) ($infr['CodigoModificacion'] ?? 0);
        if (!array_key_exists($cod, ecfCodigosModificacion())) {
            $errores[] = 'El código de modificación debe ser uno de los 5 valores de la Tabla 18.';
        }
        // Corrección de texto: la Tabla 18 código 2 obliga a monto cero.
        if ($cod === 2) {
            foreach ($items as $i => $it) {
                if ((float) ($it['MontoItem'] ?? 0) != 0.0) {
                    $errores[] = 'Línea ' . ($i + 1) . ': una nota de crédito por corrección de texto '
                               . '(código 2) debe llevar monto cero.';
                }
            }
        }
    }

    // --- Límite de tamaño de la trama (relevante en envío por lotes) ---
    if (isset($doc['__trama']) && strlen($doc['__trama']) > 10000) {
        $errores[] = 'La trama supera los 10,000 caracteres permitidos por comprobante.';
    }

    return $errores;
}
