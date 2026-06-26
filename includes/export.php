<?php
/**
 * Exportación de datos a Excel (.xlsx, PhpSpreadsheet) y PDF (Dompdf), con la marca de la empresa.
 * Las implementaciones viven en includes/excel.php y includes/pdf.php.
 */

function quiere_excel(): bool { return ($_GET['export'] ?? '') === 'excel'; }
function quiere_pdf(): bool { return ($_GET['export'] ?? '') === 'pdf'; }
function export_solicitado(): bool { return in_array($_GET['export'] ?? '', ['excel', 'pdf'], true); }

/**
 * Exporta un listado tabular a Excel o PDF según ?export=. Termina la ejecución si exporta.
 * @param array $filas  cada fila es un arreglo de valores en el orden de $headers.
 */
function export_tabla(string $nombre, array $headers, array $filas, ?string $titulo = null): void
{
    $titulo = $titulo ?: ucfirst(str_replace('_', ' ', $nombre));
    if (quiere_pdf() && function_exists('pdf_tabla')) {
        pdf_tabla($titulo, $headers, $filas, $nombre, count($headers) > 6 ? 'landscape' : 'portrait');
    }
    if (function_exists('exportExcel')) {
        exportExcel($nombre, $headers, $filas, $titulo);
    }
    exportCSVraw($nombre, $headers, $filas); // respaldo si no hay PhpSpreadsheet
}

/** Respaldo CSV (BOM UTF-8) si las librerías no estuvieran disponibles. */
function exportCSVraw(string $nombre, array $headers, array $filas): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($filas as $f) fputcsv($out, array_values($f));
    fclose($out);
    exit;
}

/** Botones "Excel" y "PDF" para la cabecera de un listado. */
function export_buttons(array $extra = []): string
{
    $base = array_merge($_GET, $extra);
    $excel = '?' . http_build_query(array_merge($base, ['export' => 'excel']));
    $pdf   = '?' . http_build_query(array_merge($base, ['export' => 'pdf']));
    return '<a href="' . e($excel) . '" class="btn btn-ghost no-print">' . icon('download', 'w-4 h-4') . ' Excel</a>'
        . '<a href="' . e($pdf) . '" target="_blank" class="btn btn-ghost no-print">' . icon('print', 'w-4 h-4') . ' PDF</a>';
}
