<?php
/**
 * Catálogos oficiales del Comprobante Fiscal Electrónico (e-CF).
 *
 * Fuente: LUG-OPE-MA-002 «Catálogo de Tablas» v4 (04/03/2025), del proveedor
 * LUGANIS CORP. Son los ÚNICOS valores que la plataforma acepta: no inventar,
 * no renumerar, no traducir.
 *
 * La Tabla 8 (Provincias y Municipios) vive aparte por tamaño, en
 * includes/ecf_provincias.php.
 *
 * OJO al leer el documento del proveedor: la ficha de campos (LUG-OPE-PT-001)
 * remite repetidamente a «la Tabla 12» para los códigos de impuestos
 * adicionales, pero la Tabla 12 es Tipo_Moneda. Los códigos 001-039 están en la
 * Tabla 10, que es lo que implementa este archivo.
 */

require_once __DIR__ . '/ecf_provincias.php';

/* ============================================================
 *  TABLAS DEL CATÁLOGO OFICIAL
 * ============================================================ */

/** Tabla 1 — Tipos de e-CF que se pueden emitir. */
function ecfTiposComprobante(): array
{
    return [
        '31' => 'Factura de Crédito Fiscal Electrónica',
        '32' => 'Factura de Consumo Electrónica',
        '33' => 'Nota de Débito Electrónica',
        '34' => 'Nota de Crédito Electrónica',
        '41' => 'Comprobante de Compras Electrónico',
        '43' => 'Comprobante de Gastos Menores Electrónico',
        '44' => 'Comprobante de Regímenes Especiales Electrónica',
        '45' => 'Comprobante Gubernamental Electrónico',
        '46' => 'Comprobante de Exportaciones Electrónico',
        '47' => 'Comprobante de Pagos al Exterior Electrónico',
    ];
}

/**
 * Tipos que este sistema sabe generar hoy.
 *
 * Los demás (41, 43, 44, 45, 46, 47) tienen secciones y órdenes de campo
 * distintos; se implementan cuando el negocio los necesite. Declararlos aquí
 * sin generador sería ofrecer algo que rompe al usarse.
 */
function ecfTiposSoportados(): array
{
    return ['31', '32', '33', '34'];
}

/** Tabla 2 — Indicador de Nota de Crédito (plazo de deducción del ITBIS). */
function ecfIndicadoresNotaCredito(): array
{
    return [
        0 => 'Emisión del e-CF modificado ≤ 30 días calendario',
        1 => 'Emisión del e-CF modificado > 30 días calendario',
    ];
}

/** Tabla 3 — ¿Los montos de la línea ya traen el ITBIS incluido? */
function ecfIndicadoresMontoGravado(): array
{
    return [
        0 => 'Los montos NO tienen ITBIS incluido',
        1 => 'Los montos tienen ITBIS incluido',
    ];
}

/** Tabla 4 — Tipo de Ingresos. Se transmite con dos posiciones ("01"…"06"). */
function ecfTiposIngreso(): array
{
    return [
        1 => 'Ingresos por operaciones (No financieros)',
        2 => 'Ingresos Financieros',
        3 => 'Ingresos Extraordinarios',
        4 => 'Ingresos por Arrendamientos',
        5 => 'Ingresos por Venta de Activo Depreciable',
        6 => 'Otros Ingresos',
    ];
}

/** Tabla 5 — Tipo de Pago. */
function ecfTiposPago(): array
{
    return [1 => 'Contado', 2 => 'Crédito', 3 => 'Gratuito'];
}

/**
 * Tabla 6 — Formas de Pago (sección FPAG).
 *
 * No confundir con el catálogo del 607 (`dgiiTiposPago607`), que llega hasta 7:
 * aquí «7» es Nota de crédito y «Otras» se corrió al 8.
 */
function ecfFormasPago(): array
{
    return [
        1 => 'Efectivo',
        2 => 'Cheque/Transferencia/Depósito',
        3 => 'Tarjeta de Débito/Crédito',
        4 => 'Venta a Crédito',
        5 => 'Bonos o Certificados de regalo',
        6 => 'Permuta',
        7 => 'Nota de crédito',
        8 => 'Otras Formas de pago',
    ];
}

/** Tabla 7 — Tipo de Cuenta Bancaria de origen del pago. */
function ecfTiposCuentaPago(): array
{
    return ['CT' => 'Cuenta Corriente', 'AH' => 'Cuenta de Ahorro', 'OT' => 'Otra'];
}

/** Tabla 9 — Unidad de Medida. 43 = UND es el valor de uso general. */
function ecfUnidadesMedida(): array
{
    return [
        1 => 'Barril', 2 => 'Bolsa', 3 => 'Bote', 4 => 'Bultos', 5 => 'Botella',
        6 => 'Caja/Cajón', 7 => 'Cajetilla', 8 => 'Centímetro', 9 => 'Cilindro',
        10 => 'Conjunto', 11 => 'Contenedor', 12 => 'Día', 13 => 'Docena',
        14 => 'Fardo', 15 => 'Galones', 16 => 'Grado', 17 => 'Gramo', 18 => 'Granel',
        19 => 'Hora', 20 => 'Huacal', 21 => 'Kilogramo', 22 => 'Kilovatio Hora',
        23 => 'Libra', 24 => 'Litro', 25 => 'Lote', 26 => 'Metro',
        27 => 'Metro Cuadrado', 28 => 'Metro Cúbico', 29 => 'Millones de Unidades Térmicas',
        30 => 'Minuto', 31 => 'Paquete', 32 => 'Par', 33 => 'Pie', 34 => 'Pieza',
        35 => 'Rollo', 36 => 'Sobre', 37 => 'Segundo', 38 => 'Tanque', 39 => 'Tonelada',
        40 => 'Tubo', 41 => 'Yarda', 42 => 'Yarda cuadrada', 43 => 'Unidad',
        44 => 'Elemento', 45 => 'Millar', 46 => 'Saco', 47 => 'Lata', 48 => 'Display',
        49 => 'Bidón', 50 => 'Ración', 51 => 'Quintal', 52 => 'Toneladas de registro bruto',
        53 => 'Pie cuadrado', 54 => 'Pasajero', 55 => 'Pulgadas',
        56 => 'Parqueo barcos en muelle', 57 => 'Bandeja', 58 => 'Hectárea',
        59 => 'Mililitro', 60 => 'Miligramo', 61 => 'Onzas', 62 => 'Onzas Troy',
    ];
}

/**
 * Tabla 10 — Tipos de Impuestos Adicionales.
 *
 * ADVERTENCIA OPERATIVA: las tasas ESPECÍFICAS (códigos 006-022, alcohol y
 * tabaco) las ajusta el Banco Central cada trimestre. Los valores de abajo son
 * los del documento v4 (vigencia enero-marzo 2025, Resolución AR1-2024-00008) y
 * quedan aquí solo como referencia para la pantalla: el monto del impuesto se
 * calcula con el dato del producto, no con esta constante.
 */
function ecfImpuestosAdicionales(): array
{
    return [
        '001' => ['abrev' => 'Propina Legal',  'tasa' => '10%',   'desc' => 'Propina Legal'],
        '002' => ['abrev' => 'CDT',            'tasa' => '2%',    'desc' => 'Contribución al Desarrollo de las Telecomunicaciones (Ley 153-98 Art. 45)'],
        '003' => ['abrev' => 'ISC',            'tasa' => '16%',   'desc' => 'Servicios de Seguros en general'],
        '004' => ['abrev' => 'ISC',            'tasa' => '10%',   'desc' => 'Servicios de Telecomunicaciones'],
        '005' => ['abrev' => 'Primera Placa',  'tasa' => '17%',   'desc' => 'Impuesto sobre el Primer Registro de Vehículos'],
        '006' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Cerveza'],
        '007' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Vinos de uva'],
        '008' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Vermut y demás vinos de uvas frescas'],
        '009' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Demás bebidas fermentadas'],
        '010' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Alcohol etílico sin desnaturalizar (≥ 80%)'],
        '011' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Alcohol etílico sin desnaturalizar (< 80%)'],
        '012' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Aguardientes de uva'],
        '013' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Whisky'],
        '014' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Ron y demás aguardientes de caña'],
        '015' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Gin y Ginebra'],
        '016' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Vodka'],
        '017' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Licores'],
        '018' => ['abrev' => 'ISC Específico', 'tasa' => '731.71', 'desc' => 'Los demás (bebidas y alcoholes)'],
        '019' => ['abrev' => 'ISC Específico', 'tasa' => '61.89', 'desc' => 'Cigarrillos con tabaco, cajetilla de 20 unidades'],
        '020' => ['abrev' => 'ISC Específico', 'tasa' => '61.89', 'desc' => 'Los demás cigarrillos de 20 unidades'],
        '021' => ['abrev' => 'ISC Específico', 'tasa' => '30.95', 'desc' => 'Cigarrillos de 10 unidades'],
        '022' => ['abrev' => 'ISC Específico', 'tasa' => '30.95', 'desc' => 'Los demás cigarrillos de 10 unidades'],
        '023' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Cerveza'],
        '024' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Vinos de uva'],
        '025' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Vermut y demás vinos de uvas frescas'],
        '026' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Demás bebidas fermentadas'],
        '027' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Alcohol etílico sin desnaturalizar (≥ 80%)'],
        '028' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Alcohol etílico sin desnaturalizar (< 80%)'],
        '029' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Aguardientes de uva'],
        '030' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Whisky'],
        '031' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Ron y demás aguardientes de caña'],
        '032' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Gin y Ginebra'],
        '033' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Vodka'],
        '034' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Licores'],
        '035' => ['abrev' => 'ISC AdValorem', 'tasa' => '10%', 'desc' => 'Los demás (bebidas y alcoholes)'],
        '036' => ['abrev' => 'ISC AdValorem', 'tasa' => '20%', 'desc' => 'Cigarrillos con tabaco, cajetilla de 20 unidades'],
        '037' => ['abrev' => 'ISC AdValorem', 'tasa' => '20%', 'desc' => 'Los demás cigarrillos de 20 unidades'],
        '038' => ['abrev' => 'ISC AdValorem', 'tasa' => '20%', 'desc' => 'Cigarrillos de 10 unidades'],
        '039' => ['abrev' => 'ISC AdValorem', 'tasa' => '20%', 'desc' => 'Los demás cigarrillos de 10 unidades'],
    ];
}

/** Tabla 11 — Vía de Transporte. */
function ecfViasTransporte(): array
{
    return ['01' => 'Terrestre', '02' => 'Marítimo', '03' => 'Aérea'];
}

/** Tabla 12 — Monedas admitidas (código ISO). */
function ecfMonedas(): array
{
    return [
        'BRL' => 'Real brasileño',       'CAD' => 'Dólar canadiense',
        'CHF' => 'Franco suizo',         'CHY' => 'Yuan chino',
        'XDR' => 'Derecho especial de giro (unidad de cuenta del FMI)',
        'DKK' => 'Corona danesa',        'EUR' => 'Euro',
        'GBP' => 'Libra esterlina',      'JPY' => 'Yen japonés',
        'NOK' => 'Corona noruega',       'SCP' => 'Libra escocesa',
        'SEK' => 'Corona sueca',         'USD' => 'Dólar estadounidense',
        'VEF' => 'Bolívar fuerte venezolano', 'HTG' => 'Gourde haitiana',
        'MXN' => 'Peso mexicano',
    ];
}

/**
 * Tabla 13 — Indicador de Facturación (condición de ITBIS de cada línea).
 *
 * «Exento» y «ITBIS 0%» NO son lo mismo para la DGII: el 0% es una operación
 * gravada a tasa cero (da derecho a crédito fiscal) y el exento está fuera del
 * impuesto. Por eso `productos.itbis_aplica`, que es booleano, no alcanza.
 */
function ecfIndicadoresFacturacion(): array
{
    return [
        0 => 'No facturable',
        1 => 'ITBIS 1 (18%)',
        2 => 'ITBIS 2 (16%)',
        3 => 'ITBIS 3 (0%)',
        4 => 'Exento',
    ];
}

/** Tasa de ITBIS que corresponde a cada indicador de la Tabla 13. */
function ecfTasaPorIndicador(int $indicador): float
{
    return [0 => 0.0, 1 => 18.0, 2 => 16.0, 3 => 0.0, 4 => 0.0][$indicador] ?? 0.0;
}

/** Tabla 14 — ¿La línea es un Bien o un Servicio? */
function ecfIndicadoresBienServicio(): array
{
    return [1 => 'Bien', 2 => 'Servicio'];
}

/** Tabla 15 — Tipo de Afiliación (solo facturación de minerales). */
function ecfTiposAfiliacion(): array
{
    return [1 => 'Afiliada', 2 => 'No Afiliada'];
}

/** Tabla 16 — Tipo de Liquidación (solo facturación de minerales). */
function ecfTiposLiquidacion(): array
{
    return [1 => 'Provisional', 2 => 'Final'];
}

/** Tabla 17 — Indicador de facturación del Descuento o Recargo global. */
function ecfIndicadoresDescuentoRecargo(): array
{
    return [1 => 'ITBIS 1 (18%)', 2 => 'ITBIS 2 (16%)', 3 => 'ITBIS 3 (0%)', 4 => 'Exento'];
}

/** Tabla 18 — Código de Modificación (notas de crédito y débito). */
function ecfCodigosModificacion(): array
{
    return [
        1 => 'Anula el NCF modificado',
        2 => 'Corrige el texto del comprobante fiscal modificado',
        3 => 'Corrige montos del NCF modificado',
        4 => 'Reemplazo de NCF emitido en contingencia',
        5 => 'Referencia a Factura de Consumo Electrónica',
    ];
}

/* ============================================================
 *  PUENTES ENTRE EL MODELO INTERNO Y EL CATÁLOGO OFICIAL
 * ============================================================ */

/**
 * `metodos_pago.dgii_tipo_pago` (semántica del 607, 1-7) → Tabla 6 (1-8).
 *
 * Los códigos 1-6 coinciden. El 7 no: en el 607 es «Otras formas» y en la
 * Tabla 6 el 7 es «Nota de crédito», así que «otras» se corre al 8. Escribir
 * un 7 aquí declararía la venta como pagada con nota de crédito.
 */
function ecfFormaPagoDesde607(int $tipo607): int
{
    return $tipo607 === 7 ? 8 : max(1, min(6, $tipo607));
}

/** `ventas.tipo_comprobante` → tipo de e-CF de la Tabla 1. */
function ecfTipoDesdeComprobante(string $tipoComprobante): string
{
    return $tipoComprobante === 'credito_fiscal' ? '31' : '32';
}

/** `productos.tipo` → Tabla 14. */
function ecfBienServicioDesdeProducto(?string $tipo): int
{
    return $tipo === 'servicio' ? 2 : 1;
}

/**
 * Indicador de facturación (Tabla 13) a partir de la tasa de ITBIS aplicada.
 * Se usa como red al facturar un producto anterior a la migración.
 */
function ecfIndicadorDesdeTasa(float $tasa): int
{
    if ($tasa >= 17.5) return 1;   // 18%
    if ($tasa >= 15.5) return 2;   // 16%
    return 3;                       // 0%
}

/**
 * ¿El documento del comprador es RNC/Cédula (numérico) o extranjero?
 * Devuelve ['rnc' => string|null, 'extranjero' => string|null].
 *
 * El e-CF separa los dos campos: RNCComprador solo admite 9 u 11 dígitos, y
 * cualquier otra identificación (pasaporte incluido) va en IdentificadorExtranjero.
 */
function ecfIdentificacionComprador(?string $documento, int $tipoId = 1): array
{
    $doc = preg_replace('/\D+/', '', (string) $documento);
    $esRnc = $tipoId !== 3 && ($doc !== '' && (strlen($doc) === 9 || strlen($doc) === 11));
    if ($esRnc) return ['rnc' => $doc, 'extranjero' => null];

    $libre = trim((string) $documento);
    return ['rnc' => null, 'extranjero' => $libre !== '' ? substr($libre, 0, 20) : null];
}

/* ============================================================
 *  FORMATO DE VALORES EN LA TRAMA
 * ============================================================ */

/**
 * Monto con 2 decimales: punto decimal, sin separador de millares.
 * PT-001 exige «16 enteros y 2 decimales ≥ 0».
 */
function ecfMonto($n): string
{
    return number_format(round((float) $n, 2), 2, '.', '');
}

/**
 * Precio unitario con 4 decimales.
 * Excepción declarada en MA-001 §5.3 para «Precio Unitario Ítem»,
 * «Precio Unitario Ítem Otra Moneda» y «Tipo de Cambio».
 */
function ecfPrecio($n): string
{
    return number_format(round((float) $n, 4), 4, '.', '');
}

/** Cantidad con hasta 2 decimales (los ejemplos oficiales usan 2 fijos). */
function ecfCantidad($n): string
{
    return number_format(round((float) $n, 2), 2, '.', '');
}

/** Subcantidad: única magnitud con 3 decimales (MA-001 §5.3). */
function ecfSubcantidad($n): string
{
    return number_format(round((float) $n, 3), 3, '.', '');
}

/** Porcentaje con 2 decimales («3 enteros y 2 decimales»). */
function ecfPorcentaje($n): string
{
    return number_format(round((float) $n, 2), 2, '.', '');
}

/** Fecha en el formato de la trama: dd-mm-aaaa. Cadena vacía si no hay fecha. */
function ecfFecha($fecha): string
{
    if (empty($fecha)) return '';
    $ts = is_numeric($fecha) ? (int) $fecha : strtotime((string) $fecha);
    return $ts ? date('d-m-Y', $ts) : '';
}

/** Tipo de Ingresos con dos posiciones, como exige la Tabla 4. */
function ecfTipoIngreso($n): string
{
    return str_pad((string) max(1, min(6, (int) $n)), 2, '0', STR_PAD_LEFT);
}

/* ============================================================
 *  SECUENCIA ELECTRÓNICA (e-NCF)
 * ============================================================ */

/**
 * Formatea un e-NCF: E + tipo (2) + secuencia (10) = 13 caracteres.
 * Ej.: ecfFormatearENCF('32', 99150) => 'E320000099150'
 */
function ecfFormatearENCF(string $tipoEcf, int $secuencia): string
{
    return 'E' . str_pad($tipoEcf, 2, '0', STR_PAD_LEFT)
         . str_pad((string) $secuencia, 10, '0', STR_PAD_LEFT);
}

/** ¿Es un e-NCF bien formado? (E + 2 dígitos de tipo + 10 dígitos) */
function ecfENCFValido(?string $encf): bool
{
    if (!preg_match('/^E(\d{2})\d{10}$/', (string) $encf, $m)) return false;
    return isset(ecfTiposComprobante()[$m[1]]);
}

/** Descompone un e-NCF en ['tipo' => '32', 'secuencia' => 99150]. Null si no calza. */
function ecfPartesENCF(string $encf): ?array
{
    if (!ecfENCFValido($encf)) return null;
    return ['tipo' => substr($encf, 1, 2), 'secuencia' => (int) substr($encf, 3)];
}

/**
 * Nombre del archivo TXT para el envío en línea (MA-001 §6.7).
 * Formato: RNC + e-NCF + .txt  →  132944372E310000000001.txt
 *
 * El manual declara el campo como Alfanumérico(26), largo que solo cuadra con
 * un RNC de 9 dígitos; con cédula de 11 salen 28 caracteres. Se construye igual
 * porque es lo único coherente con la nomenclatura descrita — está anotado como
 * punto a confirmar con el consultor en docs/FACTURACION-ELECTRONICA.md.
 */
function ecfNombreArchivo(string $rnc, string $encf): string
{
    return preg_replace('/\D+/', '', $rnc) . $encf . '.txt';
}
