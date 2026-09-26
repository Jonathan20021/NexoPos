<?php
/**
 * Servicio de exportación a Excel (.xlsx) con PhpSpreadsheet, con la marca de la empresa.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Texto que empieza por = + - @ entra como TEXTO, no como fórmula. Lo demás,
 * igual que siempre (números, fechas, vacíos). Para libros armados con
 * setCellValue()/fromArray() que llevan nombres escritos por usuarios: un
 * producto o una campaña llamada «=HYPERLINK(…)» no debe ejecutarse al abrir
 * el Excel. excel_llenar_hoja() ya escribe todo texto como texto explícito.
 */
if (!class_exists('ExcelSinFormulasBinder')) {
    class ExcelSinFormulasBinder extends \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder
    {
        public function bindValue(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell, mixed $value): bool
        {
            if (is_string($value) && $value !== '' && strpbrk($value[0], '=+-@') !== false && !is_numeric($value)) {
                $cell->setValueExplicit($value, DataType::TYPE_STRING);
                return true;
            }
            return parent::bindValue($cell, $value);
        }
    }
}

/** Nombre de hoja válido para Excel (31 caracteres, sin \\ / ? * [ ] :). */
function excel_nombre_hoja(string $t): string
{
    return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $t), 0, 31) ?: 'Datos';
}

/**
 * Llena una hoja con la cabecera de marca, encabezados con estilo, datos,
 * bordes, autoancho y el encabezado congelado. La comparten el Excel de una
 * tabla y el libro de varias hojas.
 */
function excel_llenar_hoja($sheet, string $titulo, array $headers, array $filas, string $nombreHoja = ''): void
{
    $emp = $GLOBALS['empresa'] ?? [];
    $sheet->setTitle(excel_nombre_hoja($nombreHoja !== '' ? $nombreHoja : $titulo));

    $n = max(1, count($headers));
    $lastCol = Coordinate::stringFromColumnIndex($n);

    // ---- Marca / título ----
    $sheet->setCellValue('A1', $emp['nombre'] ?? APP_NAME);
    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A2', $titulo);
    $sheet->mergeCells("A2:{$lastCol}2");
    $sub = trim((!empty($emp['rnc']) ? 'RNC: ' . $emp['rnc'] . '   ' : '') . 'Generado: ' . date('d/m/Y h:i A'));
    $sheet->setCellValue('A3', $sub);
    $sheet->mergeCells("A3:{$lastCol}3");
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('111827');
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('2563EB');
    $sheet->getStyle('A3')->getFont()->setSize(9)->getColor()->setRGB('6B7280');

    // ---- Encabezados (fila 5) ----
    $hr = 5;
    foreach ($headers as $i => $col) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $hr, (string) $col);
    }
    $headerRange = "A{$hr}:{$lastCol}{$hr}";
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
    $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($hr)->setRowHeight(20);

    // ---- Datos ----
    $r = $hr + 1;
    foreach ($filas as $row) {
        $c = 1;
        foreach ($row as $val) {
            $ref = Coordinate::stringFromColumnIndex($c) . $r;
            if (is_int($val) || is_float($val)) {
                $sheet->setCellValueExplicit($ref, $val, DataType::TYPE_NUMERIC);
            } elseif (is_string($val) && is_numeric($val) && !preg_match('/^0\d/', $val) && strlen($val) < 15) {
                $sheet->setCellValueExplicit($ref, (float) $val, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit($ref, (string) $val, DataType::TYPE_STRING);
            }
            $c++;
        }
        $r++;
    }
    $lastRow = max($hr, $r - 1);

    // ---- Bordes + autoancho + congelar ----
    $sheet->getStyle("A{$hr}:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
    for ($i = 1; $i <= $n; $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    $sheet->freezePane('A' . ($hr + 1));
}

/**
 * Un libro con varias hojas, cada una con el formato de exportExcel(), y una
 * portada opcional (pares etiqueta => valor: periodo, filtros, moneda…).
 * @param array $hojas [[nombre de hoja, título, encabezados, filas], …]
 */
function exportExcelLibro(string $nombre, array $hojas, array $portada = [], string $tituloPortada = ''): void
{
    while (ob_get_level() > 0) ob_end_clean();
    $ss = new Spreadsheet();
    $primera = true;
    if ($portada) {
        excel_llenar_hoja($ss->getActiveSheet(), $tituloPortada ?: 'Portada', ['Dato', 'Valor'],
            array_map(fn($k, $v) => [$k, (string) $v], array_keys($portada), $portada), 'Portada');
        $primera = false;
    }
    foreach ($hojas as [$nombreHoja, $titulo, $headers, $filas]) {
        $sheet = $primera ? $ss->getActiveSheet() : $ss->createSheet();
        $primera = false;
        excel_llenar_hoja($sheet, $titulo, $headers, $filas, $nombreHoja);
    }
    $ss->setActiveSheetIndex(0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

/**
 * Genera y descarga un .xlsx con cabecera de marca, encabezados con estilo y autoancho.
 * @param array $filas  Lista de filas; cada fila es un arreglo en el orden de $headers.
 */
function exportExcel(string $nombre, array $headers, array $filas, ?string $titulo = null): void
{
    $titulo = $titulo ?: ucfirst(str_replace('_', ' ', $nombre));
    exportExcelLibro($nombre, [[$titulo, $titulo, $headers, $filas]]);
}
