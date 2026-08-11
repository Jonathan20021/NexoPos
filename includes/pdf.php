<?php
/**
 * Servicio de generación de PDF profesional con Dompdf.
 * Documentos con la marca de la empresa (logo + datos) configurable desde la UI.
 */

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Logo de la empresa como data-URI base64, o null si no hay ninguno.
 *
 * Cae al logotipo de la aplicación cuando la empresa no cargó el suyo, igual
 * que hacen el ticket y la barra lateral: un reporte con un cuadrito de
 * iniciales cuando existe un logotipo perfectamente válido no tiene sentido.
 */
function pdf_logo_datauri(): ?string
{
    $logo = setting('logo') ?: marca_app_logo();
    if (!$logo) return null;
    $path = dirname(__DIR__) . '/' . ltrim((string) $logo, '/');
    if (!is_file($path)) return null;
    $bin = @file_get_contents($path);
    if ($bin === false) return null;
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/png') : 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/**
 * CSS base para los PDF.
 *
 * Dompdf entiende CSS 2.1: nada de flexbox ni grid, así que el layout se arma
 * con tablas. `border-radius` y `position:fixed` sí funcionan, y de ahí sale la
 * banda de pie anclada abajo.
 *
 * Las clases viejas (.totales, .total-final, .badge, h3) se mantienen porque
 * las usan cotizaciones, reportes y listados; el rediseño añade encima sin
 * romper lo que ya imprimía bien.
 */
function pdf_css(): string
{
    $c = marca_app();
    return '<style>
        @page { margin: 34px 38px 62px 38px; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { color: #333A48; font-size: 10.5px; margin: 0; line-height: 1.45; }

        /* ---- Cabecera ---- */
        .brand { width: 100%; margin-bottom: 0; }
        .brand td { vertical-align: top; }
        .brand .logo { width: 190px; }
        .brand .logo img { max-height: 46px; max-width: 195px; }
        .brand .empresa { font-size: 13.5px; font-weight: bold; color: #111827; }
        .brand .sub { color: #6B7280; font-size: 9.5px; line-height: 1.5; }
        .brand .doc { text-align: right; }
        .doc-tipo  { font-size: 19px; font-weight: bold; letter-spacing: 1.5px; color: ' . $c . '; }
        .doc-num   { font-size: 12px; font-weight: bold; color: #111827; }
        .doc-fecha { color: #8A93A5; font-size: 9.5px; }
        /* Regla de la marca: separa la cabecera sin cargar la página. */
        .regla { height: 2.5px; background: ' . $c . '; font-size: 0; line-height: 0;
                 margin: 8px 0 14px; }

        /* ---- Cajas de datos ---- */
        .box { border: 1px solid #E5E7EB; border-radius: 7px; padding: 11px 13px; }
        .box-acento { border-left: 3px solid ' . $c . '; }
        /* Igual que .box pero sobre el <td>. Dos celdas de una misma fila miden
           lo mismo por definición, así que las cajas se emparejan sin fijar
           alturas —que en Dompdf recortan el contenido en vez de estirarse—. */
        td.box { vertical-align: top; }
        .box-tit { font-size: 8px; color: #8A93A5; text-transform: uppercase;
                   letter-spacing: 1px; font-weight: bold; margin-bottom: 5px; }
        .dato { font-size: 10px; color: #4B5563; }
        .dato strong { color: #111827; }
        .nombre-fuerte { font-size: 12.5px; font-weight: bold; color: #111827; }

        /* ---- Tabla de líneas ---- */
        table.tbl { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.tbl th { background: ' . $c . '; color: #fff; text-align: left; padding: 8px 9px;
                       font-size: 8.5px; text-transform: uppercase; letter-spacing: .8px; }
        table.tbl td { padding: 7px 9px; border-bottom: 1px solid #EDF0F4; font-size: 10px; }
        table.tbl tr:nth-child(even) td { background: #FAFBFD; }
        .num { text-align: right; }
        .sku { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5px; color: #98A2B3; }

        /* ---- Totales ---- */
        .tot { width: 100%; }
        .tot td { padding: 5px 12px; font-size: 10.5px; }
        .tot .lbl { color: #6B7280; }
        .tot .val { text-align: right; font-weight: bold; color: #111827; }
        /* El total va en bloque lleno: es el dato que se busca primero. */
        .total-bloque { background: ' . $c . '; border-radius: 7px; padding: 9px 12px; margin-top: 4px; }
        .total-bloque .lbl { color: #fff; font-size: 10px; font-weight: bold; letter-spacing: .6px; }
        .total-bloque .val { color: #fff; font-size: 15px; font-weight: bold; text-align: right; }

        /* ---- Bloque fiscal (QR + timbre) ---- */
        .qr-caja { border: 1px solid #E5E7EB; border-radius: 7px; padding: 10px 12px; }
        .qr-caja img { width: 78px; height: 78px; }
        .qr-nota { font-size: 8.5px; color: #6B7280; line-height: 1.5; }
        .qr-encf { font-family: "DejaVu Sans Mono", monospace; font-size: 10px;
                   font-weight: bold; color: #111827; }

        /* ---- Pie anclado ---- */
        .pie-banda { position: fixed; bottom: -52px; left: 0; right: 0;
                     border-top: 1px solid #E5E7EB; padding-top: 7px;
                     color: #98A2B3; font-size: 8.5px; text-align: center; line-height: 1.5; }

        /* ---- Compatibilidad con los documentos que ya existían ---- */
        .meta { color: #9CA3AF; font-size: 9px; margin-top: 14px; }
        .totales td { padding: 4px 8px; }
        .totales .lbl { color: #6B7280; }
        .totales .val { text-align: right; font-weight: bold; }
        .total-final { font-size: 14px; color: #111827; }
        h3 { color: #111827; font-size: 13px; margin: 14px 0 6px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; }
    </style>';
}

/**
 * Cabecera con la marca del documento.
 *
 * `$marca` es un arreglo de tienda_marca(): si se pasa, el documento sale con el
 * logo, los colores y los datos de esa tienda en vez de los de la empresa. Se
 * usa en la factura, donde el cliente compró «en L'Occitane» y no «en la
 * importadora». Sin `$marca` el comportamiento es el de siempre.
 */
function pdf_brand_header(string $titulo, string $subtituloDoc = '', ?array $marca = null, bool $compacto = false): string
{
    $e = $GLOBALS['empresa'] ?? [];

    if ($marca !== null) {
        $nombre    = (string) $marca['nombre'];
        $rnc       = $marca['rnc'] ?? null;
        $direccion = trim((string) ($marca['direccion'] ?? '') . ($marca['ciudad'] ? ', ' . $marca['ciudad'] : ''), ', ');
        $telefono  = $marca['telefono'] ?? null;
        $email     = $marca['email'] ?? null;
        $extra     = $marca['encabezado'] ?? null;
        $color     = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($marca['color'] ?? '')) ? $marca['color'] : marca_app();
        $logo      = function_exists('tienda_logo_datauri') ? tienda_logo_datauri($marca) : null;
    } else {
        $nombre    = $e['nombre'] ?? APP_NAME;
        $rnc       = $e['rnc'] ?? null;
        $direccion = $e['direccion'] ?? null;
        $telefono  = $e['telefono'] ?? null;
        $email     = $e['email'] ?? null;
        $extra     = null;
        $color     = marca_app();
        $logo      = pdf_logo_datauri();
    }

    $esc = fn($s) => htmlspecialchars((string) $s);

    // El logotipo va a su tamaño real, no encajado en un cuadrito: casi todos
    // los logos comerciales son apaisados y meterlos en 54x54 los deja
    // ilegibles. Sin logo, la inicial sobre el color de la marca.
    $logoCell = $logo
        ? '<td class="logo"><img src="' . $logo . '"></td>'
        : '<td class="logo" style="width:58px;"><table style="width:46px;height:46px;background:' . $color
          . ';border-radius:9px;"><tr><td style="color:#fff;font-size:21px;font-weight:bold;text-align:center;vertical-align:middle;">'
          . $esc(mb_strtoupper(mb_substr((string) $nombre, 0, 1))) . '</td></tr></table></td>';

    // Los datos del emisor van bajo el logotipo, no al lado: así el logo respira
    // y la fila de arriba queda para lo que identifica el documento.
    $info = '<div class="empresa">' . $esc($nombre) . '</div>';
    if ($extra)     $info .= '<div class="sub">' . $esc($extra) . '</div>';
    if ($rnc)       $info .= '<div class="sub">RNC ' . $esc($rnc) . '</div>';
    if ($direccion) $info .= '<div class="sub">' . $esc($direccion) . '</div>';
    if ($telefono)  $info .= '<div class="sub">' . $esc($telefono) . ($email ? '  ·  ' . $esc($email) : '') . '</div>';
    elseif ($email) $info .= '<div class="sub">' . $esc($email) . '</div>';

    $bloqueDoc = '<td class="doc">'
        . '<div class="doc-tipo" style="color:' . $color . ';">' . $esc($titulo) . '</div>'
        . ($subtituloDoc ? '<div class="doc-num">' . $esc($subtituloDoc) . '</div>' : '')
        . '<div class="doc-fecha">' . date('d/m/Y h:i A') . '</div>'
        . '</td>';

    // Compacta: los datos del emisor van AL LADO del logotipo en vez de debajo.
    // En apaisado la hoja solo tiene 595 pt de alto y una cabecera apilada se
    // come casi la mitad; a lo ancho, en cambio, sobra sitio.
    $tabla = $compacto
        ? '<table class="brand"><tr>' . $logoCell
          . '<td style="padding-left:14px; padding-right:14px;">' . $info . '</td>'
          . $bloqueDoc . '</tr></table>'
        : '<table class="brand"><tr>' . $logoCell . $bloqueDoc . '</tr>'
          . '<tr><td colspan="2" style="padding-top:4px;">' . $info . '</td></tr></table>';

    return $tabla . '<div class="regla" style="background:' . $color . ';"></div>';
}

/**
 * Banda de pie del documento, anclada al borde inferior de cada página.
 *
 * Va aparte del contenido para que una factura de tres líneas no deje la hoja
 * con el aire flotando: el pie cierra la página y el vacío se lee como margen.
 */
function pdf_pie(string $texto): string
{
    return '<div class="pie-banda">' . $texto . '</div>';
}

/** Renderiza y envía un PDF. $modo: 'inline' (ver) o 'download'. */
/**
 * Genera el PDF y devuelve sus bytes en vez de enviarlo al navegador.
 *
 * Existe para adjuntarlo a un correo (una cotización que se le manda al
 * cliente). `pdf_render()` termina con `exit`, así que no servía: se separó el
 * armado del envío para que ambos usen el MISMO documento y no haya dos PDF
 * distintos del mismo papel.
 */
function pdf_bytes(string $bodyHtml, string $orientation = 'portrait'): string
{
    $dompdf = pdf_documento($bodyHtml, $orientation);
    return (string) $dompdf->output();
}

/**
 * Documento HTML completo que se le entrega a Dompdf.
 *
 * Estaba escrito dos veces, una en pdf_documento() y otra en pdf_render(). Con
 * dos copias bastaba tocar una —el pie, por ejemplo— para que el PDF que se
 * manda por correo dejara de ser igual al que se ve en pantalla.
 *
 * El pie automático solo numera páginas. La línea de quién emite la pone cada
 * documento con pdf_pie(), porque no todos quieren la misma: una factura lleva
 * el emisor legal y un listado interno no lleva nada.
 */
function pdf_html(string $bodyHtml): string
{
    return '<!DOCTYPE html><html><head><meta charset="utf-8">' . pdf_css() . '</head><body>'
        . $bodyHtml
        . '<script type="text/php">
            if (isset($pdf)) {
                $w = $pdf->get_width(); $h = $pdf->get_height();
                $font = $fontMetrics->getFont("DejaVu Sans", "normal");
                $pdf->page_text($w - 118, $h - 26, "Página {PAGE_NUM} de {PAGE_COUNT}", $font, 8, array(0.62,0.64,0.70));
            }
          </script>'
        . '</body></html>';
}

/** Arma el Dompdf ya renderizado. Uso interno de pdf_render() y pdf_bytes(). */
function pdf_documento(string $bodyHtml, string $orientation = 'portrait'): Dompdf
{
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isPhpEnabled', true); // numeración de páginas
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('defaultPaperSize', 'A4');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(pdf_html($bodyHtml), 'UTF-8');
    $dompdf->setPaper('A4', $orientation);
    $dompdf->render();
    return $dompdf;
}

function pdf_render(string $bodyHtml, string $filename, string $orientation = 'portrait', string $modo = 'inline'): void
{
    while (ob_get_level() > 0) ob_end_clean();
    pdf_documento($bodyHtml, $orientation)
        ->stream($filename . '.pdf', ['Attachment' => $modo === 'download']);
    exit;
}

/** PDF genérico de un listado (tabla con la marca). */
function pdf_tabla(string $titulo, array $headers, array $filas, string $filename, string $orientation = 'landscape'): void
{
    $html = pdf_brand_header($titulo);
    $html .= '<table class="tbl"><thead><tr>';
    foreach ($headers as $col) $html .= '<th>' . htmlspecialchars((string) $col) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($filas as $row) {
        $html .= '<tr>';
        foreach ($row as $c) {
            $cls = is_numeric($c) ? ' class="num"' : '';
            $html .= '<td' . $cls . '>' . htmlspecialchars((string) $c) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p class="meta">' . count($filas) . ' registros · Generado por ' . htmlspecialchars(current_user()['nombre'] ?? '') . ' ' . htmlspecialchars(current_user()['apellido'] ?? '') . '</p>';
    pdf_render($html, $filename, $orientation, 'inline');
}
