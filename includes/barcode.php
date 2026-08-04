<?php
/**
 * Códigos de barras — validación, generación y dibujo.
 *
 * Todo se resuelve en PHP puro y se dibuja en SVG: no hace falta GD, ni Imagick,
 * ni ninguna librería nueva. El SVG es vectorial, así que la etiqueta sale nítida
 * en cualquier impresora (una imagen PNG a 96 dpi se imprime borrosa y el lector
 * falla justo cuando más prisa hay).
 *
 * Simbologías:
 *   · EAN-13  — el estándar del comercio. Es el que ya viene impreso en la
 *               mercancía de marca (Nestlé, Samsung, Adidas…). 12 dígitos + verificador.
 *   · UPC-A   — 12 dígitos, común en mercancía de Estados Unidos. Se guarda tal cual;
 *               al leerlo, un lector suele reportarlo como EAN-13 con un 0 delante.
 *   · EAN-8   — versión corta para envases pequeños.
 *   · Code128 — alfanumérico y de largo libre. Es el que usamos para etiquetar por
 *               SKU cuando el producto no trae código de fábrica.
 *
 * CÓDIGO INTERNO: cuando un producto no trae código impreso, `barcode_generar_interno()`
 * arma un EAN-13 válido con prefijo 200. GS1 reserva el rango 200–299 para
 * "circulación restringida dentro de una empresa": son códigos legales para usar
 * puertas adentro y que por diseño NUNCA chocarán con el código de un fabricante.
 * No inventamos códigos en rangos ajenos, que es el error clásico y termina con dos
 * productos distintos leyendo igual.
 */

/* ============================================================
 *  Validación y tipos
 * ============================================================ */

/** Deja solo lo que un lector puede devolver: sin espacios ni caracteres de control. */
function barcode_normalizar(?string $v): string
{
    $v = trim((string) $v);
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
    return trim($v);
}

/**
 * Dígito verificador de la familia EAN/UPC.
 * Se pesa 3-1-3-1… empezando por la DERECHA del cuerpo del código; hacerlo desde
 * la izquierda da un resultado distinto según el largo (EAN-8 vs EAN-13) y es el
 * fallo más habitual al implementarlo.
 */
function barcode_ean_dv(string $cuerpo): string
{
    $suma = 0;
    $peso = 3;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += ((int) $cuerpo[$i]) * $peso;
        $peso = $peso === 3 ? 1 : 3;
    }
    return (string) ((10 - ($suma % 10)) % 10);
}

/** ¿El dígito verificador que trae el código cuadra con su cuerpo? */
function barcode_ean_valido(string $v): bool
{
    if (!preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $v)) return false;
    return barcode_ean_dv(substr($v, 0, -1)) === substr($v, -1);
}

/**
 * Simbología con la que se debe dibujar un valor.
 * Devuelve 'ean13' | 'ean8' | 'upca' | 'code128' | '' (vacío = no se puede dibujar).
 */
function barcode_tipo(?string $v): string
{
    $v = barcode_normalizar($v);
    if ($v === '') return '';
    if (preg_match('/^\d{13}$/', $v) && barcode_ean_valido($v)) return 'ean13';
    if (preg_match('/^\d{12}$/', $v) && barcode_ean_valido($v)) return 'upca';
    if (preg_match('/^\d{8}$/', $v)  && barcode_ean_valido($v)) return 'ean8';
    // Code128 subconjunto B: ASCII imprimible de 32 a 126.
    if (preg_match('/^[\x20-\x7E]+$/', $v)) return 'code128';
    return '';
}

/** Nombre legible de la simbología, para mostrarlo en pantalla. */
function barcode_tipo_label(string $tipo): string
{
    return [
        'ean13'    => 'EAN-13',
        'upca'     => 'UPC-A',
        'ean8'     => 'EAN-8',
        'code128'  => 'Code 128',
    ][$tipo] ?? '—';
}

/**
 * Revisa un código tecleado o escaneado antes de guardarlo.
 * Devuelve ['ok'=>bool, 'valor'=>string, 'tipo'=>string, 'error'=>string, 'aviso'=>string].
 *
 * DÓNDE SE ES ESTRICTO Y POR QUÉ. Solo se RECHAZA un código de 13 dígitos con el
 * verificador equivocado. EAN-13 es el estándar del comercio y ningún lector
 * devuelve jamás uno inválido (el propio lector comprueba el dígito antes de
 * emitirlo), así que un EAN-13 que no cuadra está tecleado a mano y está mal.
 *
 * Con 8, 12 o 14 dígitos NO se rechaza: muchísimas empresas arrastran códigos
 * internos numéricos de ese largo desde su Excel de siempre, que no pretenden ser
 * EAN-8 ni UPC-A. Se aceptan y se imprimen en Code 128, que se lee igual de bien.
 * Rechazarlos obligaría a renumerar el catálogo entero solo para poder guardarlo.
 */
function barcode_validar(?string $v): array
{
    $v = barcode_normalizar($v);
    $r = ['ok' => false, 'valor' => $v, 'tipo' => '', 'error' => '', 'aviso' => ''];

    if ($v === '') { $r['ok'] = true; return $r; }   // vacío es válido: el código es opcional
    if (mb_strlen($v) > 60) { $r['error'] = 'El código de barras no puede pasar de 60 caracteres.'; return $r; }

    $soloDigitos = preg_match('/^\d+$/', $v) === 1;

    // 13 dígitos = EAN-13. Aquí sí se corta: es un dígito mal tecleado.
    if ($soloDigitos && strlen($v) === 13 && !barcode_ean_valido($v)) {
        $correcto = substr($v, 0, -1) . barcode_ean_dv(substr($v, 0, -1));
        $r['error'] = 'Ese EAN-13 no es válido: el dígito verificador no cuadra. Con esos 12 primeros dígitos el código correcto sería ' . $correcto . '.';
        return $r;
    }

    $tipo = barcode_tipo($v);
    if ($tipo === '') { $r['error'] = 'Ese código tiene caracteres que ningún lector estándar puede imprimir.'; return $r; }

    $r['ok'] = true;
    $r['tipo'] = $tipo;

    if ($tipo === 'code128' && $soloDigitos) {
        $r['aviso'] = in_array(strlen($v), [8, 12, 14], true)
            ? 'El código ' . $v . ' no es un ' . (strlen($v) === 8 ? 'EAN-8' : (strlen($v) === 12 ? 'UPC-A' : 'ITF-14'))
              . ' válido (su dígito verificador no cuadra), así que se guarda como código interno y se imprimirá en Code 128. Se lee igual de bien.'
            : 'Se imprimirá como Code 128 por su largo. Para el estándar del comercio usa 13 dígitos (EAN-13).';
    }
    return $r;
}

/* ============================================================
 *  Generación de códigos internos
 * ============================================================ */

/**
 * Próximo EAN-13 interno libre (prefijo 200 = circulación restringida).
 * Estructura: 200 + 9 dígitos de correlativo + verificador.
 *
 * El correlativo se reserva con el mismo contador atómico del resto del sistema
 * (UPDATE … LAST_INSERT_ID), no con SELECT MAX()+1: dos personas etiquetando a la
 * vez en dos sucursales generarían el mismo código y uno moriría contra el UNIQUE.
 * Ver docs/CONCURRENCIA.md.
 */
function barcode_generar_interno(): string
{
    $clave = 'productos.codigo_barras.EAN';
    q("INSERT IGNORE INTO contadores (nombre, valor) VALUES (?, 0)", [$clave]);

    // Hasta 20 vueltas: si alguien tecleó a mano un código del rango interno, se
    // salta el ocupado en vez de reventar al guardar.
    for ($i = 0; $i < 20; $i++) {
        q("UPDATE contadores SET valor = LAST_INSERT_ID(valor + 1) WHERE nombre = ?", [$clave]);
        $n = (int) db()->lastInsertId();
        if ($n <= 0) throw new RuntimeException('No se pudo reservar el correlativo del código de barras.');
        if ($n > 999999999) throw new RuntimeException('Se agotó el rango de códigos internos.');

        $cuerpo = '200' . str_pad((string) $n, 9, '0', STR_PAD_LEFT);
        $codigo = $cuerpo . barcode_ean_dv($cuerpo);
        if (!qVal("SELECT 1 FROM productos WHERE codigo_barras = ?", [$codigo])) {
            return $codigo;
        }
    }
    throw new RuntimeException('No se pudo generar un código de barras interno libre.');
}

/** ¿Este código lo generamos nosotros (prefijo interno) o viene del fabricante? */
function barcode_es_interno(?string $v): bool
{
    $v = barcode_normalizar($v);
    return preg_match('/^2\d{12}$/', $v) === 1;
}

/* ============================================================
 *  Dibujo (SVG)
 * ============================================================ */

/** Patrones de las tres tablas de EAN/UPC. */
function barcode_tablas_ean(): array
{
    static $t = null;
    if ($t !== null) return $t;
    return $t = [
        // Izquierda, paridad impar
        'L' => ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'],
        // Izquierda, paridad par
        'G' => ['0100111','0110011','0011011','0100001','0011101','0111001','0000101','0010001','0001001','0010111'],
        // Derecha (siempre el complemento de L)
        'R' => ['1110010','1100110','1101100','1000010','1011100','1001110','1010000','1000100','1001000','1110100'],
        // Qué combinación L/G lleva la mitad izquierda según el primer dígito
        'P' => ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'],
    ];
}

/** Anchos de barra/espacio de los 107 caracteres de Code 128. */
function barcode_tabla_code128(): array
{
    static $t = null;
    if ($t !== null) return $t;
    return $t = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112',
    ];
}

/**
 * Cadena de bits de un Code 128.
 * Arranca en subconjunto C cuando el código empieza con 4 o más dígitos y vuelve a C
 * en cada tramo par de 6 o más: comprime dos dígitos por símbolo, así una etiqueta
 * de 25 mm sigue siendo legible en vez de salir apretada.
 */
function barcode_bits_code128(string $v): string
{
    $tabla = barcode_tabla_code128();
    $codigos = [];

    $usarC = fn(string $s, int $i, bool $inicio): bool =>
        preg_match('/^\d{' . ($inicio ? 4 : 6) . ',}/', substr($s, $i)) === 1
        || (strlen($s) - $i >= 2 && preg_match('/^\d+$/', substr($s, $i)) && (strlen($s) - $i) % 2 === 0);

    $modo = $usarC($v, 0, true) ? 'C' : 'B';
    $codigos[] = $modo === 'C' ? 105 : 104;   // START C / START B

    $i = 0;
    $n = strlen($v);
    while ($i < $n) {
        if ($modo === 'C') {
            if ($i + 1 < $n && ctype_digit($v[$i]) && ctype_digit($v[$i + 1])) {
                $codigos[] = (int) substr($v, $i, 2);
                $i += 2;
                continue;
            }
            $codigos[] = 100;                 // CODE B
            $modo = 'B';
            continue;
        }
        if ($usarC($v, $i, false)) {
            $codigos[] = 99;                  // CODE C
            $modo = 'C';
            continue;
        }
        $codigos[] = ord($v[$i]) - 32;
        $i++;
    }

    // Verificador módulo 103: START + Σ(posición × valor).
    $suma = $codigos[0];
    for ($k = 1; $k < count($codigos); $k++) $suma += $codigos[$k] * $k;
    $codigos[] = $suma % 103;
    $codigos[] = 106;                          // STOP

    $bits = '';
    foreach ($codigos as $c) {
        $anchos = $tabla[$c];
        $negro = true;
        for ($j = 0; $j < strlen($anchos); $j++) {
            $bits .= str_repeat($negro ? '1' : '0', (int) $anchos[$j]);
            $negro = !$negro;
        }
    }
    return $bits;
}

/** Cadena de bits de un EAN-13 / UPC-A / EAN-8. */
function barcode_bits_ean(string $v, string $tipo): string
{
    $t = barcode_tablas_ean();
    if ($tipo === 'upca') { $v = '0' . $v; $tipo = 'ean13'; }   // UPC-A es un EAN-13 con 0 delante

    if ($tipo === 'ean8') {
        $bits = '101';
        for ($i = 0; $i < 4; $i++) $bits .= $t['L'][(int) $v[$i]];
        $bits .= '01010';
        for ($i = 4; $i < 8; $i++) $bits .= $t['R'][(int) $v[$i]];
        return $bits . '101';
    }

    $paridad = $t['P'][(int) $v[0]];
    $bits = '101';
    for ($i = 1; $i <= 6; $i++) $bits .= $t[$paridad[$i - 1]][(int) $v[$i]];
    $bits .= '01010';
    for ($i = 7; $i <= 12; $i++) $bits .= $t['R'][(int) $v[$i]];
    return $bits . '101';
}

/**
 * Dibuja el código de barras como SVG.
 *
 * $opts: alto (px del área de barras), modulo (ancho de la barra fina),
 *        texto (bool: imprimir los dígitos debajo), clase (CSS del <svg>).
 *
 * La zona muda (el margen blanco de los lados) NO es decorativa: sin ella el lector
 * no encuentra dónde empieza el símbolo. Por eso va dentro del SVG y no depende de
 * que la hoja de estilos deje aire alrededor.
 */
function barcode_svg(?string $valor, array $opts = []): string
{
    $valor = barcode_normalizar($valor);
    $tipo  = barcode_tipo($valor);
    if ($tipo === '') return '';

    $alto   = (float) ($opts['alto'] ?? 46);
    $mod    = (float) ($opts['modulo'] ?? 1.6);
    $texto  = $opts['texto'] ?? true;
    $clase  = $opts['clase'] ?? '';
    $fuente = max(7.0, $mod * 5.2);

    $esEan = $tipo !== 'code128';
    $bits  = $esEan ? barcode_bits_ean($valor, $tipo) : barcode_bits_code128($valor);
    $n     = strlen($bits);

    // Zona muda: EAN manda 11 módulos a la izquierda y 7 a la derecha; en Code 128
    // el mínimo son 10 a cada lado.
    $mudaIzq = $esEan ? 11 : 10;
    $mudaDer = $esEan ? 7 : 10;

    $altoTexto = $texto ? $fuente + 2 : 0;
    $anchoTotal = ($n + $mudaIzq + $mudaDer) * $mod;
    $altoTotal  = $alto + $altoTexto;

    // En EAN las barras de guarda bajan más que el resto: es lo que le da al ojo
    // (y al escáner) la referencia de dónde parte cada mitad.
    $guardas = [];
    if ($tipo === 'ean8') {
        $guardas = [[0, 3], [31, 5], [64, 3]];
    } elseif ($esEan) {
        $guardas = [[0, 3], [45, 5], [92, 3]];
    }
    $esGuarda = function (int $i) use ($guardas): bool {
        foreach ($guardas as [$ini, $len]) if ($i >= $ini && $i < $ini + $len) return true;
        return false;
    };
    $bajada = $esEan ? $fuente * 0.75 : 0;

    // Las barras se emiten como DOS <path> (uno para las normales y otro para las
    // guardas, que son más largas) en vez de un <rect> por barra. Es la misma
    // geometría ocupando la tercera parte: una hoja de 500 etiquetas pasa de
    // cientos de miles de nodos a mil. Con listados largos y hojas grandes, la
    // diferencia se nota tanto al descargar como al pintar.
    $dNormal = '';
    $dGuarda = '';
    $i = 0;
    while ($i < $n) {
        if ($bits[$i] === '0') { $i++; continue; }
        $ini = $i;
        while ($i < $n && $bits[$i] === '1') $i++;
        $ancho = round(($i - $ini) * $mod, 3);
        $x     = round(($mudaIzq + $ini) * $mod, 3);
        $guard = $esGuarda($ini);
        $h     = round($alto + ($guard ? $bajada : 0), 3);
        // Rectángulo cerrado: bajar, ir a la derecha, subir, cerrar.
        $d = 'M' . $x . ' 0h' . $ancho . 'v' . $h . 'h-' . $ancho . 'z';
        if ($guard) $dGuarda .= $d; else $dNormal .= $d;
    }
    $rects = ($dNormal !== '' ? '<path d="' . $dNormal . '"/>' : '')
           . ($dGuarda !== '' ? '<path d="' . $dGuarda . '"/>' : '');

    $svgTexto = '';
    if ($texto) {
        $y = $altoTotal - 1.5;
        $centro = fn(float $x, string $s, float $fs) =>
            '<text x="' . round($x, 2) . '" y="' . round($y, 2) . '" font-size="' . round($fs, 2)
            . '" text-anchor="middle" font-family="monospace" letter-spacing="' . round($mod * 0.5, 2) . '">' . e($s) . '</text>';

        if ($tipo === 'ean13' || $tipo === 'upca') {
            $d = $tipo === 'upca' ? '0' . $valor : $valor;
            // El primer dígito va FUERA del símbolo, a la izquierda: así se imprime
            // en cualquier producto de supermercado.
            $svgTexto .= '<text x="' . round($mod * 2, 2) . '" y="' . round($y, 2) . '" font-size="' . round($fuente, 2)
                . '" text-anchor="start" font-family="monospace">' . e($d[0]) . '</text>';
            $svgTexto .= $centro(($mudaIzq + 3 + 21) * $mod, substr($d, 1, 6), $fuente);
            $svgTexto .= $centro(($mudaIzq + 50 + 21) * $mod, substr($d, 7, 6), $fuente);
        } elseif ($tipo === 'ean8') {
            $svgTexto .= $centro(($mudaIzq + 3 + 14) * $mod, substr($valor, 0, 4), $fuente);
            $svgTexto .= $centro(($mudaIzq + 36 + 14) * $mod, substr($valor, 4, 4), $fuente);
        } else {
            $svgTexto .= $centro($anchoTotal / 2, $valor, $fuente * 0.9);
        }
    }

    return '<svg class="' . e($clase) . '" viewBox="0 0 ' . round($anchoTotal, 2) . ' ' . round($altoTotal, 2) . '"'
        . ' width="' . round($anchoTotal, 2) . '" height="' . round($altoTotal, 2) . '"'
        . ' xmlns="http://www.w3.org/2000/svg" shape-rendering="crispEdges" role="img"'
        . ' aria-label="Código de barras ' . e($valor) . '">'
        . '<rect width="100%" height="100%" fill="#fff"/>'
        . '<g fill="#000">' . $rects . '</g>'
        . '<g fill="#000">' . $svgTexto . '</g>'
        . '</svg>';
}

/* ============================================================
 *  Escáner (lado del navegador)
 * ============================================================ */

/**
 * Carga el escáner en la página y le dice dónde está el lector de respaldo.
 * Se llama una sola vez por página, justo antes de `layout_end()`.
 */
function escaner_script(): string
{
    static $puesto = false;
    if ($puesto) return '';
    $puesto = true;

    return '<script src="' . e(asset('js/escaner.js')) . '"></script>'
        . '<script>NexoEscaner.configurar({vendor: ' . json_encode(asset('js/vendor/zxing-browser.min.js')) . '});</script>';
}

/**
 * Botón para abrir el escáner de cámara. Se oculta solo si el navegador no puede
 * usarla, para no ofrecer algo que va a fallar.
 * $onLeer es JavaScript que recibe la variable `codigo`.
 */
function escaner_boton(string $onLeer, string $titulo = 'Escanear código', string $clase = 'btn btn-soft'): string
{
    $js = 'NexoEscaner.abrir({titulo:' . json_encode($titulo) . ',onLeer:function(codigo){' . $onLeer . '}})';
    return '<button type="button" class="' . e($clase) . '" title="' . e($titulo) . '"'
        . ' onclick="' . e($js) . '">' . icon('barcode', 'w-4 h-4') . ' Escanear</button>';
}

/**
 * Busca un producto por lo que devolvió el lector.
 * Un lector puede entregar el mismo artículo de varias formas, así que se prueban
 * en orden: código de barras exacto → SKU exacto → UPC-A con el 0 que EAN-13 le
 * añade delante (y al revés) → EAN-13 sin el cero.
 */
function barcode_buscar_producto(string $codigo): ?array
{
    $codigo = barcode_normalizar($codigo);
    if ($codigo === '') return null;

    $intentos = [$codigo];
    if (preg_match('/^\d{12}$/', $codigo)) $intentos[] = '0' . $codigo;
    if (preg_match('/^0\d{12}$/', $codigo)) $intentos[] = substr($codigo, 1);

    foreach ($intentos as $c) {
        $p = qOne("SELECT * FROM productos WHERE codigo_barras = ? LIMIT 1", [$c]);
        if ($p) return $p;
    }
    foreach ($intentos as $c) {
        $p = qOne("SELECT * FROM productos WHERE codigo = ? LIMIT 1", [$c]);
        if ($p) return $p;
    }
    return null;
}
