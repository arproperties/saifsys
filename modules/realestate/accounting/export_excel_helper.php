<?php
/**
 * Accounting reports - Excel export helper (PhpSpreadsheet)
 * Falls back to CSV if PhpSpreadsheet not available.
 */

function accounting_export_excel_or_csv(array $rows, array $columnKeys, string $filename, string $title = '') {
    $vendorPath = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($vendorPath)) {
        require_once $vendorPath;
    }
    if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        accounting_export_csv($rows, $columnKeys, $filename);
        return;
    }
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr(preg_replace('/[^a-z0-9]/i', '', $filename), 0, 31));
    $row = 1;
    if ($title !== '') {
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columnKeys));
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $row = 3;
    }
    $col = 1;
    foreach ($columnKeys as $displayName) {
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col++) . $row, $displayName);
    }
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    foreach ($rows as $dataRow) {
        $col = 1;
        foreach (array_keys($columnKeys) as $key) {
            $val = $dataRow[$key] ?? '';
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            if (is_numeric($val) && $val !== '') {
                $sheet->setCellValue($coord, (float)$val);
            } else {
                $sheet->setCellValue($coord, (string)$val);
            }
            $col++;
        }
        $row++;
    }
    foreach (range(1, count($columnKeys)) as $c) {
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function accounting_export_csv(array $rows, array $columnKeys, string $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, array_values($columnKeys));
    foreach ($rows as $dataRow) {
        $csvRow = [];
        foreach (array_keys($columnKeys) as $key) {
            $csvRow[] = $dataRow[$key] ?? '';
        }
        fputcsv($out, $csvRow);
    }
    fclose($out);
    exit;
}

function accounting_can_excel() {
    $vendorPath = __DIR__ . '/../../../vendor/autoload.php';
    if (!file_exists($vendorPath)) return false;
    require_once $vendorPath;
    return class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
}
