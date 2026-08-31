<?php
/**
 * Banco de pruebas del RNC y la cédula dominicanos.
 *
 *   php pruebas/documentos.php
 *
 * `dgiiRevisarDocumento()` es lo único que separa un número mal tecleado de un
 * archivo 606/607 rechazado por la DGII —rechaza el archivo entero, no la
 * línea— y de un empleado que no cuadra en la TSS y se queda sin cotizar.
 *
 * Los RNC de abajo son reales y públicos (el de la propia empresa y el del
 * ambiente de pruebas del proveedor de e-CF). Las cédulas están fabricadas a
 * propósito: se calculó el verificador correcto y se anotó al lado, para no
 * meter la cédula de nadie en el repositorio.
 *
 * Devuelve 0 si todo pasa y 1 si algo falla.
 */

require_once dirname(__DIR__) . '/includes/dgii.php';

$fallos = 0;
$total  = 0;

function comprueba(string $caso, $obtenido, $esperado): void
{
    global $fallos, $total;
    $total++;
    $ok = $obtenido === $esperado;
    if (!$ok) $fallos++;
    printf("  %s %-58s %s\n", $ok ? 'OK   ' : 'FALLA', $caso,
        $ok ? '' : 'obtenido ' . var_export($obtenido, true) . ', esperado ' . var_export($esperado, true));
}

function titulo(string $t): void { echo "\n== $t ==\n"; }

/* ============================================================
 *  RNC
 * ============================================================ */
titulo('RNC de 9 dígitos');
comprueba('el RNC de la empresa es válido', dgiiDocumentoValido('102616541'), true);
comprueba('acepta el RNC con guiones y espacios', dgiiDocumentoValido(' 1-02-61654-1 '), true);
comprueba('reconoce que es un RNC', dgiiRevisarDocumento('102616541')['tipo'], 'rnc');
comprueba('un dígito cambiado ya no cuadra', dgiiDocumentoValido('102616542'), false);
comprueba('dos dígitos traspuestos tampoco', dgiiDocumentoValido('102616514'), false);
comprueba('el motivo dice en qué debería terminar',
    str_contains(dgiiRevisarDocumento('102616542')['motivo'], 'terminar en 1'), true);
comprueba('otro RNC real también pasa', dgiiDocumentoValido('131880681'), true);

/* ============================================================
 *  Cédula
 * ============================================================ */
titulo('Cédula de 11 dígitos');
// 402-1234567-? : peso 1,2 alternado → el verificador que sale es 8.
comprueba('cédula con el verificador correcto', dgiiDocumentoValido('40212345678'), true);
comprueba('reconoce que es una cédula', dgiiRevisarDocumento('40212345678')['tipo'], 'cedula');
comprueba('acepta el formato con guiones', dgiiDocumentoValido('402-1234567-8'), true);
comprueba('con el verificador cambiado no pasa', dgiiDocumentoValido('40212345679'), false);
comprueba('el motivo dice en qué debería terminar',
    str_contains(dgiiRevisarDocumento('40212345679')['motivo'], 'terminar en 8'), true);
// 001-0000001-? : el verificador que sale es 7.
comprueba('cédula de la serie 001', dgiiDocumentoValido('00100000017'), true);

/* ============================================================
 *  Lo que no es ninguno de los dos
 * ============================================================ */
titulo('Documentos que no son RNC ni cédula');
comprueba('vacío no es válido', dgiiDocumentoValido(''), false);
comprueba('null no revienta', dgiiDocumentoValido(null), false);
comprueba('vacío no se inventa un tipo', dgiiRevisarDocumento('')['tipo'], null);
comprueba('un largo raro no se inventa un tipo', dgiiRevisarDocumento('1234567')['tipo'], null);
comprueba('el motivo del largo raro cuenta los dígitos',
    str_contains(dgiiRevisarDocumento('1234567')['motivo'], 'tiene 7'), true);
comprueba('un pasaporte con letras se queda sin tipo',
    dgiiRevisarDocumento('AB123456')['tipo'], null);

/* ============================================================
 *  Coherencia con lo que ya existía
 * ============================================================ */
titulo('Coherencia con dgiiTipoIdPorDocumento()');
comprueba('el RNC es tipo 1 para la DGII', dgiiTipoIdPorDocumento('102616541'), 1);
comprueba('la cédula es tipo 2 para la DGII', dgiiTipoIdPorDocumento('40212345678'), 2);
comprueba('los dos coinciden en qué es un RNC',
    dgiiRevisarDocumento('102616541')['tipo'] === 'rnc' && dgiiTipoIdPorDocumento('102616541') === 1, true);

/* ============================================================
 *  El verificador cubre las diez terminaciones
 * ============================================================ */
titulo('Solo una terminación puede ser la buena');
$validasRnc = 0;
for ($d = 0; $d <= 9; $d++) if (dgiiDocumentoValido('10261654' . $d)) $validasRnc++;
comprueba('de los diez finales posibles, el RNC acepta uno', $validasRnc, 1);

$validasCed = 0;
for ($d = 0; $d <= 9; $d++) if (dgiiDocumentoValido('4021234567' . $d)) $validasCed++;
comprueba('de los diez finales posibles, la cédula acepta uno', $validasCed, 1);

echo "\n" . ($fallos === 0
        ? "TODO EN VERDE ($total comprobaciones)"
        : "$fallos de $total FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
