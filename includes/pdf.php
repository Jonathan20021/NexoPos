<?php
/**
 * Servicio de generación de PDF profesional con Dompdf.
 * Documentos con la marca de la empresa (logo + datos) configurable desde la UI.
 */

use Dompdf\Dompdf;
use Dompdf\Options;

/** Devuelve el logo de la empresa como data-URI base64 (o null). */
function pdf_logo_datauri(): ?string
{
    $logo = setting('logo');
    if (!$logo) return null;
    $path = dirname(__DIR__) . '/' . ltrim((string) $logo, '/');
    if (!is_file($path)) return null;
    $bin = @file_get_contents($path);
    if ($bin === false) return null;
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/png') : 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/** CSS base para los PDF (Dompdf soporta CSS 2.1; se usa layout de tablas). */
function pdf_css(): string
{
    return '<style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { color: #1f2937; font-size: 11px; margin: 0; }
        .brand { width: 100%; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 14px; }
        .brand td { vertical-align: middle; }
        .brand .logo { width: 64px; }
        .brand .logo img { max-width: 60px; max-height: 60px; }
        .brand .empresa { font-size: 15px; font-weight: bold; color: #111827; }
        .brand .sub { color: #6b7280; font-size: 10px; }
        .brand .doc { text-align: right; }
        .brand .doc .titulo { font-size: 16px; font-weight: bold; color: #2563eb; }
        .brand .doc .fecha { color: #6b7280; font-size: 10px; }
        table.tbl { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.tbl th { background: #2563eb; color: #fff; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; }
        table.tbl td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10.5px; }
        table.tbl tr:nth-child(even) td { background: #f8fafc; }
        .num { text-align: right; }
        .meta { color: #9ca3af; font-size: 9px; margin-top: 14px; }
        .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; }
        .totales td { padding: 4px 8px; }
        .totales .lbl { color: #6b7280; }
        .totales .val { text-align: right; font-weight: bold; }
        .total-final { font-size: 14px; color: #111827; }
        h3 { color:#111827; font-size:13px; margin:14px 0 6px; }
        .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:9px; font-weight:bold; }
    </style>';
}

/** Cabecera con la marca de la empresa. */
function pdf_brand_header(string $titulo, string $subtituloDoc = ''): string
{
    $e = $GLOBALS['empresa'] ?? [];
    $logo = pdf_logo_datauri();
    $logoCell = $logo
        ? '<td class="logo"><img src="' . $logo . '"></td>'
        : '<td class="logo"><div style="width:54px;height:54px;background:#2563eb;border-radius:10px;color:#fff;font-size:26px;font-weight:bold;text-align:center;line-height:54px;">' . htmlspecialchars(mb_substr($e['nombre'] ?? 'N', 0, 1)) . '</div></td>';
    $info = '<div class="empresa">' . htmlspecialchars($e['nombre'] ?? APP_NAME) . '</div>';
    if (!empty($e['rnc'])) $info .= '<div class="sub">RNC: ' . htmlspecialchars($e['rnc']) . '</div>';
    if (!empty($e['direccion'])) $info .= '<div class="sub">' . htmlspecialchars($e['direccion']) . '</div>';
    if (!empty($e['telefono'])) $info .= '<div class="sub">Tel: ' . htmlspecialchars($e['telefono']) . (!empty($e['email']) ? ' · ' . htmlspecialchars($e['email']) : '') . '</div>';

    return '<table class="brand"><tr>'
        . $logoCell
        . '<td style="padding-left:10px;">' . $info . '</td>'
        . '<td class="doc"><div class="titulo">' . htmlspecialchars($titulo) . '</div>'
        . ($subtituloDoc ? '<div class="fecha">' . htmlspecialchars($subtituloDoc) . '</div>' : '')
        . '<div class="fecha">' . date('d/m/Y h:i A') . '</div></td>'
        . '</tr></table>';
}

/** Renderiza y envía un PDF. $modo: 'inline' (ver) o 'download'. */
function pdf_render(string $bodyHtml, string $filename, string $orientation = 'portrait', string $modo = 'inline'): void
{
    while (ob_get_level() > 0) ob_end_clean();
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isPhpEnabled', true); // numeración de páginas
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('defaultPaperSize', 'A4');

    $dompdf = new Dompdf($options);
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8">' . pdf_css() . '</head><body>'
        . $bodyHtml
        . '<script type="text/php">
            if (isset($pdf)) {
                $w = $pdf->get_width(); $h = $pdf->get_height();
                $txt = "Página {PAGE_NUM} de {PAGE_COUNT}";
                $font = $fontMetrics->getFont("DejaVu Sans", "normal");
                $pdf->page_text($w - 120, $h - 28, $txt, $font, 8, array(0.6,0.6,0.6));
                $pdf->page_text(40, $h - 28, "' . addslashes(APP_NAME) . '", $font, 8, array(0.7,0.7,0.7));
            }
          </script>'
        . '</body></html>';

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', $orientation);
    $dompdf->render();
    $dompdf->stream($filename . '.pdf', ['Attachment' => $modo === 'download']);
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
