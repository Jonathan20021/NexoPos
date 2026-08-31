<?php
/**
 * Catálogos y utilidades de los Formatos de Envío de Datos de la DGII.
 *
 * Fuente: instructivos oficiales de la DGII (Norma General 07-2018 y 05-2019).
 *   606 - Compras de Bienes y Servicios ..... 23 columnas
 *   607 - Ventas de Bienes y Servicios ...... 23 columnas
 *   608 - Comprobantes Anulados ..............  3 columnas
 *
 * Los códigos de esta lista son los ÚNICOS valores que la DGII acepta.
 * No inventar ni renumerar.
 */

/** 606, columna 3: Tipo de Bienes y Servicios Comprados. */
function dgiiTiposBienServicio(): array
{
    return [
        1  => 'Gastos de personal',
        2  => 'Gastos por trabajos, suministros y servicios',
        3  => 'Arrendamientos',
        4  => 'Gastos de activos fijos',
        5  => 'Gastos de representación',
        6  => 'Otras deducciones admitidas',
        7  => 'Gastos financieros',
        8  => 'Gastos extraordinarios',
        9  => 'Compras y gastos que formarán parte del costo de venta',
        10 => 'Adquisiciones de activos',
        11 => 'Gastos de seguros',
    ];
}

/** 606, columna 17: Tipo de Retención en ISR. */
function dgiiTiposRetencionIsr(): array
{
    return [
        1 => 'Alquileres',
        2 => 'Honorarios por servicios',
        3 => 'Otras rentas',
        4 => 'Otras rentas (rentas presuntas)',
        5 => 'Intereses pagados a personas jurídicas residentes',
        6 => 'Intereses pagados a personas físicas residentes',
        7 => 'Retención por proveedores del Estado',
        8 => 'Juegos telefónicos',
        9 => 'Retenciones subsector de ganadería de carne bovina',
    ];
}

/** 606, columna 23: Forma de Pago. */
function dgiiFormasPago606(): array
{
    return [
        1 => 'Efectivo',
        2 => 'Cheques/Transferencias/Depósito',
        3 => 'Tarjeta crédito/débito',
        4 => 'Compra a crédito',
        5 => 'Permuta',
        6 => 'Notas de crédito',
        7 => 'Mixto',
    ];
}

/**
 * De qué cuenta sale el dinero, según la forma de pago que se informa en el 606.
 *
 * El 606 usa su propio vocabulario, distinto al de `metodos_pago.afecta_caja`
 * que emplea el punto de venta. Sin traducirlo, toda compra que no fuera a
 * crédito descontaba la CAJA DE EFECTIVO —también la pagada por transferencia o
 * con tarjeta—: el efectivo bajaba por dinero que nunca salió del cajón y el
 * banco se quedaba alto.
 *
 * Devuelve null cuando la operación no mueve dinero de ninguna cuenta. La
 * permuta y la nota de crédito son eso: la mercancía entró, el gasto es real y
 * se registra, pero no salió un peso de ningún sitio (mismo criterio que la
 * diferencia cambiaria en `includes/cxp.php`).
 *
 * «Mixto» se queda en efectivo porque el formato no dice cómo se repartió, y
 * repartirlo a ojo sería inventarse el dato. La compra a crédito (4) no llega
 * aquí: esa no paga hoy, registra deuda.
 */
function dgiiCuentaPorFormaPago(?int $forma): ?string
{
    return match ((int) $forma) {
        2, 3    => 'banco',     // cheque, transferencia o depósito · tarjeta
        5, 6    => null,        // permuta · nota de crédito
        default => 'efectivo',  // 1 efectivo, 7 mixto y sin especificar
    };
}

/** 607, columna 5: Tipo de Ingreso. */
function dgiiTiposIngreso(): array
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

/**
 * 607, columnas 17-23: desglose del cobro.
 * Es también la semántica de metodos_pago.dgii_tipo_pago.
 */
function dgiiTiposPago607(): array
{
    return [
        1 => 'Efectivo',
        2 => 'Cheque/Transferencia/Depósito',
        3 => 'Tarjeta Débito/Crédito',
        4 => 'Venta a Crédito',
        5 => 'Bonos o Certificados de Regalo',
        6 => 'Permuta',
        7 => 'Otras Formas de Ventas',
    ];
}

/** 608, columna 3: Tipo de Anulación. */
function dgiiTiposAnulacion(): array
{
    return [
        1  => 'Deterioro de factura preimpresa',
        2  => 'Errores de impresión (factura preimpresa)',
        3  => 'Impresión defectuosa',
        4  => 'Corrección de la información',
        5  => 'Cambio de productos',
        6  => 'Devolución de productos',
        7  => 'Omisión de productos',
        8  => 'Errores en secuencia de NCF',
        9  => 'Por cese de operaciones',
        10 => 'Pérdida o hurto de talonarios',
    ];
}

/** 606, columna 2 / 607, columna 2: Tipo de Identificación. */
function dgiiTiposIdentificacion(bool $incluirPasaporte = false): array
{
    $t = [1 => 'RNC', 2 => 'Cédula'];
    if ($incluirPasaporte) $t[3] = 'Pasaporte / ID tributaria';
    return $t;
}

/**
 * Convierte el tipo de pago del 607 (1-7) al código de «Forma de Pago» del 606.
 * Los códigos 1-4 coinciden; del 5 en adelante NO.
 *   607: 5 Bonos, 6 Permuta, 7 Otras
 *   606: 5 Permuta, 6 Notas de crédito, 7 Mixto
 */
function dgiiTipoPago607a606(int $tipo607): int
{
    return match ($tipo607) {
        1, 2, 3, 4 => $tipo607,
        6          => 5, // Permuta
        default    => 7, // Bonos y «otras» no tienen equivalente: se reportan como Mixto
    };
}

/** Deja solo los dígitos de un RNC o cédula (la DGII los exige sin guiones ni espacios). */
function dgiiSoloDigitos(?string $doc): string
{
    return preg_replace('/\D+/', '', (string) $doc);
}

/**
 * Deduce el Tipo de Identificación por la longitud del documento.
 * En RD el RNC tiene 9 dígitos y la cédula 11.
 * Devuelve null si no puede determinarlo (documento vacío o longitud atípica).
 */
function dgiiTipoIdPorDocumento(?string $doc): ?int
{
    $d = dgiiSoloDigitos($doc);
    return match (strlen($d)) {
        9       => 1, // RNC
        11      => 2, // Cédula
        default => null,
    };
}

/**
 * ¿El RNC o la cédula pueden existir de verdad?
 *
 * Los dos llevan dígito verificador, así que un número mal tecleado se detecta
 * sin consultar a nadie. Y conviene detectarlo temprano: la DGII rechaza el
 * archivo 606 o 607 ENTERO por un solo RNC mal formado —no la línea, el archivo—
 * y en la TSS un empleado con la cédula cambiada sencillamente no cuadra, así
 * que sus cotizaciones no se pueden declarar.
 *
 * RNC (9 dígitos): se pesan los ocho primeros por 7,9,8,6,5,4,3,2, se toma el
 * resto entre 11 y el verificador es 11 menos ese resto; resto 0 da 2 y resto 1
 * da 1.
 *
 * Cédula (11 dígitos): los diez primeros alternan peso 1 y 2, a los productos
 * de dos cifras se les resta 9 y el verificador es lo que falta para la decena.
 *
 * Devuelve ['digitos','tipo','valido','motivo']. `valido` es false también
 * cuando no hay documento: quien llame decide si eso le importa.
 *
 * ESTO AVISA, NO PROHÍBE. Se factura a extranjeros con pasaporte y a entidades
 * con numeraciones que no siguen la regla; bloquear el guardado dejaría a la
 * caja sin poder atender a un cliente real. El sitio donde sí es un error duro
 * es el archivo que se le manda a la DGII.
 */
function dgiiRevisarDocumento(?string $doc): array
{
    $d = dgiiSoloDigitos($doc);

    if ($d === '') {
        return ['digitos' => '', 'tipo' => null, 'valido' => false, 'motivo' => 'Sin documento.'];
    }

    if (strlen($d) === 9) {
        $pesos = [7, 9, 8, 6, 5, 4, 3, 2];
        $suma  = 0;
        for ($i = 0; $i < 8; $i++) $suma += (int) $d[$i] * $pesos[$i];
        $resto = $suma % 11;
        $dv    = $resto === 0 ? 2 : ($resto === 1 ? 1 : 11 - $resto);

        return ['digitos' => $d, 'tipo' => 'rnc', 'valido' => $dv === (int) $d[8],
                'motivo' => $dv === (int) $d[8] ? ''
                    : 'El dígito verificador del RNC no cuadra: con esos ocho primeros dígitos debería terminar en ' . $dv . '.'];
    }

    if (strlen($d) === 11) {
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $x = (int) $d[$i] * (($i % 2) ? 2 : 1);
            $suma += $x > 9 ? $x - 9 : $x;
        }
        $dv = (10 - $suma % 10) % 10;

        return ['digitos' => $d, 'tipo' => 'cedula', 'valido' => $dv === (int) $d[10],
                'motivo' => $dv === (int) $d[10] ? ''
                    : 'El dígito verificador de la cédula no cuadra: con esos diez primeros dígitos debería terminar en ' . $dv . '.'];
    }

    return ['digitos' => $d, 'tipo' => null, 'valido' => false,
            'motivo' => 'Un RNC tiene 9 dígitos y una cédula 11; este tiene ' . strlen($d) . '.'];
}

/** Atajo: ¿es un RNC o una cédula con el verificador correcto? */
function dgiiDocumentoValido(?string $doc): bool
{
    return dgiiRevisarDocumento($doc)['valido'];
}

/** Valida la estructura de un NCF: 11 o 13 posiciones (e-CF), o 19 para comprobantes previos a mayo 2018. */
function dgiiNcfValido(?string $ncf): bool
{
    $n = strtoupper(trim((string) $ncf));
    if ($n === '') return false;
    return (bool) preg_match('/^[A-Z0-9]{11}$|^[A-Z0-9]{13}$|^[A-Z0-9]{19}$/', $n);
}

/** Período de reporte en formato AAAAMM (ej. 202607). */
function dgiiPeriodo(string $fecha): string
{
    return date('Ym', strtotime($fecha));
}

/** Fecha en formato AAAAMMDD; cadena vacía si es null (la DGII acepta el campo en blanco). */
function dgiiFecha(?string $fecha): string
{
    return $fecha ? date('Ymd', strtotime($fecha)) : '';
}

/** Monto con punto decimal y 2 posiciones, como exige el instructivo (ej. 10.18). */
function dgiiMonto($n): string
{
    return number_format((float) $n, 2, '.', '');
}

/** Nombre de archivo oficial: DGII_F_<formato>_<RNC>_<AAAAMM>.TXT */
function dgiiNombreArchivo(string $formato, string $rnc, string $periodo): string
{
    return 'DGII_F_' . $formato . '_' . dgiiSoloDigitos($rnc) . '_' . $periodo . '.TXT';
}
