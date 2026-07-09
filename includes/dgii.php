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
