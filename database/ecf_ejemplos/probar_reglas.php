<?php
/**
 * Pruebas de las reglas del e-CF que los ejemplos oficiales no cubren.
 *
 *   php database/ecf_ejemplos/probar_reglas.php
 *
 * verificar.php comprueba que el FORMATO sale idéntico al del proveedor. Este
 * archivo comprueba el CRITERIO: el umbral de RD$250,000, la obligatoriedad del
 * comprador en el crédito fiscal, la tolerancia de ±1, el prorrateo del
 * descuento y el saneado de caracteres que romperían la trama.
 *
 * No toca la base de datos ni la red: son documentos armados a mano.
 */

require_once dirname(__DIR__, 2) . '/includes/ecf_trama.php';
require_once dirname(__DIR__, 2) . '/includes/ecf.php';   // ecfQrNormalizar(); no toca la base al cargarse

$pruebas = 0;
$fallos  = [];

/** Afirma que una condición se cumple. */
function afirmar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $pruebas, $fallos;
    $pruebas++;
    if ($condicion) {
        echo "  ✓ $titulo\n";
    } else {
        echo "  ✗ $titulo" . ($detalle ? "\n      $detalle" : '') . "\n";
        $fallos[] = $titulo;
    }
}

/** Documento mínimo válido al que cada prueba le cambia lo suyo. */
function docBase(string $tipo = '32', array $items = []): array
{
    $items = $items ?: [[
        'NumeroLinea' => 1, 'IndicadorFacturacion' => '1', 'NombreItem' => 'PRODUCTO',
        'IndicadorBienoServicio' => '1', 'CantidadItem' => ecfCantidad(1),
        'UnidadMedida' => '43', 'PrecioUnitarioItem' => ecfPrecio(100),
        'MontoItem' => ecfMonto(100),
    ]];

    $doc = [
        'tipo_ecf' => $tipo,
        'IDOC' => [
            'TipoeCF' => $tipo, 'eNCF' => ecfFormatearENCF($tipo, 1),
            'IndicadorMontoGravado' => '0', 'TipoIngresos' => '01', 'TipoPago' => '1',
            'FechaEmision' => ecfFecha('2026-08-07'),
        ],
        'EMIS' => [
            'RNCEmisor' => '132944372', 'RazonSocialEmisor' => 'EMPRESA DE PRUEBA SRL',
            'DireccionEmisor' => 'Av. 27 de Febrero, D. N.',
        ],
        'COMP' => ['RNCComprador' => '', 'RazonSocialComprador' => ''],
        'ITEM' => $items,
    ];
    if ($tipo === '31' || $tipo === '33') {
        $doc['IDOC']['FechaVencimientoSecuencia'] = ecfFecha('2026-12-31');
    }
    if ($tipo === '33' || $tipo === '34') {
        $doc['INFR'] = [
            'NCFModificado' => 'E320000000001', 'FechaNCFModificado' => ecfFecha('2026-08-01'),
            'CodigoModificacion' => '1',
        ];
        $doc['IDOC']['IndicadorNotaCredito'] = '0';
    }
    return $doc;
}

/** ¿Alguno de los errores menciona este texto? */
function hayError(array $errores, string $aguja): bool
{
    foreach ($errores as $e) if (stripos($e, $aguja) !== false) return true;
    return false;
}

echo "\n", str_repeat('=', 74), "\n";
echo "  Reglas de negocio del e-CF\n";
echo str_repeat('=', 74), "\n\n";

/* ---------------------------------------------------------------- Estructura */
echo "Cantidad de campos por sección (según MA-006)\n";

$anchos = [
    '31' => ['IDOC' => 15, 'EMIS' => 17, 'COMP' => 16, 'ITEM' => 29, 'FPAG' => 2, 'DERE' => 9],
    '32' => ['IDOC' => 14, 'EMIS' => 17, 'COMP' => 17, 'ITEM' => 30, 'FPAG' => 2, 'DERE' => 9],
    '33' => ['IDOC' => 15, 'EMIS' => 17, 'COMP' => 17, 'ITEM' => 33, 'FPAG' => 2, 'DERE' => 9, 'INFR' => 5],
    '34' => ['IDOC' => 11, 'EMIS' => 17, 'COMP' => 17, 'ITEM' => 33, 'DERE' => 9, 'INFR' => 5],
];
foreach ($anchos as $tipo => $esperado) {
    $layout = ecfLayout($tipo);
    foreach ($esperado as $seccion => $n) {
        afirmar(
            "Tipo $tipo · $seccion tiene $n campos",
            isset($layout[$seccion]) && count($layout[$seccion]) === $n,
            'generados: ' . (isset($layout[$seccion]) ? count($layout[$seccion]) : 'sección ausente')
        );
    }
}
afirmar('El tipo 34 no lleva sección FPAG', !isset(ecfLayout('34')['FPAG']));

/* -------------------------------------------------------------- Umbral 250k */
echo "\nUmbral de RD\$250,000 en la Factura de Consumo (tipo 32)\n";

$bajoUmbral = docBase('32');
afirmar('Por debajo del umbral no exige identificar al comprador',
    !hayError(ecfValidarDocumento($bajoUmbral), '250,000'));

$sobreUmbral = docBase('32', [[
    'NumeroLinea' => 1, 'IndicadorFacturacion' => '1', 'NombreItem' => 'PRODUCTO CARO',
    'IndicadorBienoServicio' => '1', 'CantidadItem' => ecfCantidad(1),
    'UnidadMedida' => '43', 'PrecioUnitarioItem' => ecfPrecio(250000),
    'MontoItem' => ecfMonto(250000),
]]);
afirmar('Desde RD$250,000 exige identificar al comprador',
    hayError(ecfValidarDocumento($sobreUmbral), '250,000'));

$sobreUmbral['COMP'] = ['RNCComprador' => '131880681', 'RazonSocialComprador' => 'CLIENTE PRUEBA'];
afirmar('Con RNC y razón social, sobre el umbral pasa',
    ecfValidarDocumento($sobreUmbral) === []);

$extranjero = $sobreUmbral;
$extranjero['COMP'] = ['RNCComprador' => '', 'IdentificadorExtranjero' => 'AB-998877',
                       'RazonSocialComprador' => 'FOREIGN BUYER LLC'];
afirmar('El identificador extranjero sirve en lugar del RNC',
    ecfValidarDocumento($extranjero) === []);

/* ------------------------------------------------------- Crédito fiscal (31) */
echo "\nFactura de Crédito Fiscal (tipo 31)\n";

$credito = docBase('31');
afirmar('Sin RNC del comprador, el crédito fiscal se rechaza',
    hayError(ecfValidarDocumento($credito), 'RNC o cédula del comprador'));

$credito['COMP'] = ['RNCComprador' => '131880681', 'RazonSocialComprador' => 'CLIENTE PRUEBA'];
afirmar('Con RNC y razón social, el crédito fiscal pasa',
    ecfValidarDocumento($credito) === []);

$sinVence = $credito;
unset($sinVence['IDOC']['FechaVencimientoSecuencia']);
afirmar('El tipo 31 exige fecha de vencimiento de la secuencia',
    hayError(ecfValidarDocumento($sinVence), 'vencimiento de la secuencia'));

/* ------------------------------------------------------------- Tolerancia ±1 */
echo "\nTolerancia por transacción (MA-001 §5.2)\n";

$casi = docBase('32', [[
    'NumeroLinea' => 1, 'IndicadorFacturacion' => '1', 'NombreItem' => 'PRODUCTO',
    'IndicadorBienoServicio' => '1', 'CantidadItem' => ecfCantidad(3),
    'UnidadMedida' => '43', 'PrecioUnitarioItem' => ecfPrecio(33.333),
    'MontoItem' => ecfMonto(100.00),   // 3 × 33.333 = 99.999 → dentro de ±1
]]);
afirmar('Una diferencia de céntimos entra en la tolerancia',
    !hayError(ecfValidarDocumento($casi), 'tolerancia'));

$fuera = $casi;
$fuera['ITEM'][0]['MontoItem'] = ecfMonto(150.00);
afirmar('Una diferencia mayor a 1.00 se rechaza',
    hayError(ecfValidarDocumento($fuera), 'tolerancia'));

$conDescuento = docBase('32', [[
    'NumeroLinea' => 1, 'IndicadorFacturacion' => '1', 'NombreItem' => 'PRODUCTO',
    'IndicadorBienoServicio' => '1', 'CantidadItem' => ecfCantidad(5),
    'UnidadMedida' => '43', 'PrecioUnitarioItem' => ecfPrecio(75000),
    'DescuentoMonto' => ecfMonto(37500), 'MontoItem' => ecfMonto(337500),
]]);
// Supera los RD$250,000, así que además hay que identificar al comprador.
$conDescuento['COMP'] = ['RNCComprador' => '131880681', 'RazonSocialComprador' => 'CLIENTE PRUEBA'];
afirmar('La tolerancia contempla el descuento de la línea',
    ecfValidarDocumento($conDescuento) === [],
    implode(' | ', ecfValidarDocumento($conDescuento)));

/* --------------------------------------------------------- Nota de crédito */
echo "\nNota de Crédito Electrónica (tipo 34)\n";

$nota = docBase('34');
afirmar('La nota de crédito válida pasa', ecfValidarDocumento($nota) === []);

$sinRef = $nota;
$sinRef['INFR']['NCFModificado'] = '';
afirmar('Sin comprobante referenciado se rechaza',
    hayError(ecfValidarDocumento($sinRef), 'referenciar'));

$correccionTexto = $nota;
$correccionTexto['INFR']['CodigoModificacion'] = '2';
afirmar('Corrección de texto (código 2) con monto distinto de cero se rechaza',
    hayError(ecfValidarDocumento($correccionTexto), 'monto cero'));

$correccionTexto['ITEM'][0]['MontoItem'] = ecfMonto(0);
$correccionTexto['ITEM'][0]['PrecioUnitarioItem'] = ecfPrecio(0);
afirmar('Corrección de texto con monto cero pasa',
    ecfValidarDocumento($correccionTexto) === []);

$codMalo = $nota;
$codMalo['INFR']['CodigoModificacion'] = '9';
afirmar('Un código de modificación fuera de la Tabla 18 se rechaza',
    hayError(ecfValidarDocumento($codMalo), 'Tabla 18'));

/* ------------------------------------------------- Saneado de la trama */
echo "\nSaneado de caracteres que romperían la trama\n";

$sucio = docBase('32', [[
    'NumeroLinea' => 1, 'IndicadorFacturacion' => '1',
    'NombreItem' => "TORNILLO 1/2\" | ROJO\r\nSEGUNDA LÍNEA [x]; y",
    'IndicadorBienoServicio' => '1', 'CantidadItem' => ecfCantidad(1),
    'UnidadMedida' => '43', 'PrecioUnitarioItem' => ecfPrecio(100),
    'MontoItem' => ecfMonto(100),
]]);
$tramaSucia   = ecfConstruirTrama($sucio);
$lineasSucias = explode("\r\n", $tramaSucia);

// COMP viene vacía y por eso NO se escribe (MA-001 §10.b): la línea ITEM se
// busca por prefijo, nunca por una posición fija.
$lineaItem = '';
foreach ($lineasSucias as $l) if (str_starts_with($l, 'ITEM|')) $lineaItem = $l;

afirmar('El pipe dentro de un nombre no corre los campos de lugar',
    count(ecfDividirLinea($lineaItem)) === 31,   // ITEM + 30 campos
    'campos: ' . count(ecfDividirLinea($lineaItem)));
afirmar('El salto de línea dentro de un nombre no crea una línea nueva',
    count($lineasSucias) === 3, 'líneas: ' . count($lineasSucias));
afirmar('Los corchetes y el punto y coma se neutralizan',
    !str_contains($lineaItem, '[x]'));
afirmar('Una sección sin datos no se escribe (MA-001 §10.b)',
    !str_contains($tramaSucia, 'COMP|'));

$largo = docBase('32', [[
    'NumeroLinea' => 1, 'IndicadorFacturacion' => '1',
    'NombreItem' => str_repeat('A', 200),
    'IndicadorBienoServicio' => '1', 'CantidadItem' => ecfCantidad(1),
    'UnidadMedida' => '43', 'PrecioUnitarioItem' => ecfPrecio(100),
    'MontoItem' => ecfMonto(100),
]]);
$lineaLarga = '';
foreach (explode("\r\n", ecfConstruirTrama($largo)) as $l) {
    if (str_starts_with($l, 'ITEM|')) $lineaLarga = $l;
}
$campoNombre = ecfDividirLinea($lineaLarga)[4];
afirmar('El nombre se recorta al máximo de 80 caracteres',
    mb_strlen($campoNombre) === 80, 'largo: ' . mb_strlen($campoNombre));

/* ------------------------------------------------------------- Repetibles */
echo "\nCampos repetibles\n";

afirmar('Dos códigos de ítem se serializan con «;»',
    ecfRepetible([['EAN13', '7467397392256'], ['EAN13', '6958347455288']])
        === '[EAN13|7467397392256;EAN13|6958347455288]');
afirmar('Un subdescuento en monto deja vacío el porcentaje',
    ecfRepetible([['$', '', '50000.00']]) === '[$||50000.00]');
afirmar('Mezcla de porcentaje y monto, como en la trama oficial',
    ecfRepetible([['%', '10.00', '37500.00'], ['$', '', '50000.00']])
        === '[%|10.00|37500.00;$||50000.00]');
afirmar('Una repetición totalmente vacía no ensucia la trama',
    ecfRepetible([['', '', '']]) === '');
afirmar('Sin repeticiones, el campo queda vacío', ecfRepetible([]) === '');

/* ---------------------------------------------------------- Redondeo */
echo "\nRedondeo (MA-001 §5.3)\n";

afirmar('Tercer decimal < 5 baja: 1150.2134 → 1150.21', ecfMonto(1150.2134) === '1150.21');
afirmar('Tercer decimal > 5 sube: 1150.2196 → 1150.22', ecfMonto(1150.2196) === '1150.22');
afirmar('El precio unitario admite 4 decimales', ecfPrecio(75000) === '75000.0000');
afirmar('La subcantidad admite 3 decimales', ecfSubcantidad(1.2345) === '1.234' || ecfSubcantidad(1.2345) === '1.235');
afirmar('Los montos no llevan separador de millares', ecfMonto(1234567.891) === '1234567.89');

/* --------------------------------------------------------------- e-NCF */
echo "\nSecuencia electrónica\n";

afirmar('E + tipo + 10 dígitos', ecfFormatearENCF('32', 99150) === 'E320000099150');
afirmar('Un e-NCF válido se reconoce', ecfENCFValido('E310000000001'));
afirmar('Un NCF preimpreso no pasa por e-NCF', !ecfENCFValido('B0200000001'));
afirmar('Un tipo inexistente se rechaza', !ecfENCFValido('E990000000001'));
afirmar('El e-NCF debe coincidir con el tipo declarado', (function () {
    $d = docBase('32');
    $d['IDOC']['eNCF'] = ecfFormatearENCF('31', 1);
    return hayError(ecfValidarDocumento($d), 'no corresponde al tipo');
})());

/* ------------------------------------------------------- Código QR de la RI */
echo "\nCódigo QR de la Representación Impresa\n";

// El manual muestra la respuesta de este servicio como una captura de pantalla,
// así que no se sabe en qué forma llega el QR. Se contemplan todas las
// plausibles y se comprueba que nada que no sea una imagen se cuele como tal.
$png = "\x89PNG\r\n\x1a\n" . str_repeat('X', 200);
$jpg = "\xFF\xD8\xFF" . str_repeat('Y', 200);
$b64 = base64_encode($png);

afirmar('PNG crudo se convierte en data URI',
    str_starts_with((string) ecfQrNormalizar($png, null), 'data:image/png;base64,'));
afirmar('JPEG crudo se reconoce por sus bytes mágicos',
    str_starts_with((string) ecfQrNormalizar($jpg, null), 'data:image/jpeg;base64,'));
afirmar('SVG crudo se reconoce',
    str_starts_with((string) ecfQrNormalizar('<svg xmlns="http://www.w3.org/2000/svg"/>', null),
                    'data:image/svg+xml;base64,'));
afirmar('JSON con el base64 dentro',
    str_starts_with((string) ecfQrNormalizar('{}', ['data' => ['qrCode' => $b64]]),
                    'data:image/png;base64,'));
afirmar('Un data URI ya formado se respeta tal cual',
    ecfQrNormalizar('{}', ['qr' => 'data:image/png;base64,AAAA']) === 'data:image/png;base64,AAAA');
afirmar('Base64 suelto en el cuerpo',
    str_starts_with((string) ecfQrNormalizar($b64, null), 'data:image/png;base64,'));
afirmar('Base64 en variante URL-safe',
    str_starts_with((string) ecfQrNormalizar(strtr($b64, '+/', '-_'), null), 'data:image/png;base64,'));
afirmar('Un texto cualquiera no se toma por imagen',
    ecfQrNormalizar('hola mundo', null) === null);
afirmar('Respuesta vacía devuelve null', ecfQrNormalizar('', null) === null);
afirmar('JSON sin QR devuelve null', ecfQrNormalizar('', ['status' => ['code' => '0']]) === null);

echo "\n", str_repeat('-', 74), "\n";
printf("  %d pruebas · %d fallos\n\n", $pruebas, count($fallos));
if ($fallos) {
    echo "  Fallaron:\n";
    foreach ($fallos as $f) echo "   - $f\n";
    echo "\n";
    exit(1);
}
echo "  ✓ Todas las reglas se cumplen.\n\n";
exit(0);
