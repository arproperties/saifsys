<?php
/**
 * Lightweight XLSX read/write for bank statement import (no Composer / PhpSpreadsheet).
 * Works on PHP 8.2+ with ext-zip.
 */
declare(strict_types=1);

function re_bank_xlsx_col_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

function re_bank_xlsx_index_to_col(int $index): string
{
    $index++;
    $col = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $col = chr(65 + $mod) . $col;
        $index = (int) (($index - $mod) / 26);
    }
    return $col;
}

function re_bank_excel_serial_to_ymd(float $serial): ?string
{
    if ($serial < 1) {
        return null;
    }
    try {
        $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
        $days = (int) floor($serial);
        return $base->modify('+' . $days . ' days')->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** @return list<string> */
function re_bank_xlsx_load_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }
    $doc = @simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }
    $out = [];
    foreach ($doc->si as $si) {
        if (isset($si->t)) {
            $out[] = (string) $si->t;
        } elseif (isset($si->r)) {
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) ($run->t ?? '');
            }
            $out[] = $text;
        } else {
            $out[] = '';
        }
    }
    return $out;
}

function re_bank_xlsx_first_sheet_path(ZipArchive $zip): ?string
{
    $wb = $zip->getFromName('xl/workbook.xml');
    if ($wb === false) {
        return 'xl/worksheets/sheet1.xml';
    }
    $wbDoc = @simplexml_load_string($wb);
    if ($wbDoc === false || !isset($wbDoc->sheets->sheet[0])) {
        return 'xl/worksheets/sheet1.xml';
    }
    $rid = (string) ($wbDoc->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id ?? '');
    if ($rid === '') {
        return 'xl/worksheets/sheet1.xml';
    }
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($rels === false) {
        return 'xl/worksheets/sheet1.xml';
    }
    $relsDoc = @simplexml_load_string($rels);
    if ($relsDoc === false) {
        return 'xl/worksheets/sheet1.xml';
    }
    foreach ($relsDoc->Relationship as $rel) {
        if ((string) $rel['Id'] === $rid) {
            $target = (string) $rel['Target'];
            if (str_starts_with($target, '/')) {
                return ltrim($target, '/');
            }
            return 'xl/' . ltrim($target, '/');
        }
    }
    return 'xl/worksheets/sheet1.xml';
}

/**
 * @return array<int, array<int, string>> rowNum => colIdx => value
 */
function re_bank_xlsx_read_grid(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP Zip extension is required to read XLSX files.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open Excel file.');
    }

    $shared = re_bank_xlsx_load_shared_strings($zip);
    $sheetPath = re_bank_xlsx_first_sheet_path($zip);
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Worksheet not found in Excel file.');
    }

    $doc = @simplexml_load_string($sheetXml);
    if ($doc === false || !isset($doc->sheetData)) {
        throw new RuntimeException('Invalid worksheet data.');
    }

    $grid = [];
    foreach ($doc->sheetData->row as $row) {
        $rowNum = (int) ($row['r'] ?? 0);
        if ($rowNum <= 0) {
            continue;
        }
        foreach ($row->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
                continue;
            }
            $colIdx = re_bank_xlsx_col_to_index($m[1]);
            $type = (string) ($cell['t'] ?? '');
            $value = '';
            if ($type === 's') {
                $value = $shared[(int) ($cell->v ?? 0)] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } elseif ($type === 'str') {
                $value = (string) ($cell->v ?? '');
            } else {
                $value = (string) ($cell->v ?? '');
            }
            $grid[$rowNum][$colIdx] = trim($value);
        }
    }

    return $grid;
}

/**
 * Parse .xlsx bank statement without PhpSpreadsheet.
 *
 * @return array{rows: list<array<string,mixed>>, error: ?string}
 */
function re_bank_parse_statement_xlsx_native(string $path): array
{
    if (!function_exists('re_bank_resolve_import_columns')) {
        require_once __DIR__ . '/re_bank_reco_core.php';
    }

    try {
        $grid = re_bank_xlsx_read_grid($path);
    } catch (Throwable $e) {
        return ['rows' => [], 'error' => $e->getMessage()];
    }

    if (!$grid) {
        return ['rows' => [], 'error' => 'Excel file has no data rows'];
    }

    $maxRow = max(array_keys($grid));
    $headerInfo = null;
    $scanTo = min($maxRow, 25);
    for ($r = 1; $r <= $scanTo; $r++) {
        if (!isset($grid[$r])) {
            continue;
        }
        $maxCol = max(array_keys($grid[$r]));
        $headers = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $headers[$c] = $grid[$r][$c] ?? '';
        }
        $map = re_bank_resolve_import_columns(array_values($headers));
        if ($map) {
            $headerInfo = ['row' => $r, 'map' => $map];
            break;
        }
    }

    if (!$headerInfo) {
        return ['rows' => [], 'error' => 'Could not find required columns. Use: date, description, reference, Debit, Credit (or a single amount column).'];
    }

    $headerRow = $headerInfo['row'];
    $colMap = $headerInfo['map'];
    $rows = [];

    for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
        if (!isset($grid[$r])) {
            continue;
        }
        $rowCells = $grid[$r];
        $cells = [];
        foreach ($colMap as $field => $idx) {
            if ($field === 'reference' && $idx < 0) {
                continue;
            }
            $raw = $rowCells[$idx] ?? '';
            if ($field === 'date') {
                if (is_numeric($raw) && (float) $raw > 1000) {
                    $cells[$idx] = re_bank_excel_serial_to_ymd((float) $raw) ?? (string) $raw;
                } else {
                    $cells[$idx] = re_bank_normalize_import_date($raw) ?? (string) $raw;
                }
            } else {
                $cells[$idx] = (string) $raw;
            }
        }

        $built = re_bank_build_import_row_from_cells($cells, $colMap, $r);
        if ($built) {
            $rows[] = $built;
        }
    }

    return ['rows' => $rows, 'error' => null];
}

/** @return string absolute path */
function re_bank_import_template_xlsx_path(): string
{
    return dirname(__DIR__) . '/accounting/templates/bank_statement_import_template.xlsx';
}

function re_bank_import_template_temp_path(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 're_bank_statement_import_template.xlsx';
}

function re_bank_import_template_cache_dir_writable(): bool
{
    $dir = dirname(re_bank_import_template_xlsx_path());
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    $parent = dirname($dir);
    return is_dir($parent) && is_writable($parent);
}

function re_bank_import_template_xlsx_exists(): bool
{
    $path = re_bank_import_template_xlsx_path();
    return is_file($path) && filesize($path) > 100;
}

function re_bank_serve_import_template_xlsx(): void
{
    $path = re_bank_import_template_xlsx_path();
    if (!re_bank_import_template_xlsx_exists()) {
        $built = false;
        if (re_bank_import_template_cache_dir_writable()) {
            $built = re_bank_build_import_template_xlsx_file($path);
        }
        if (!$built) {
            $path = re_bank_import_template_temp_path();
            if (!is_file($path) || filesize($path) <= 100) {
                if (!re_bank_build_import_template_xlsx_file($path)) {
                    header('Content-Type: text/plain; charset=utf-8');
                    http_response_code(500);
                    echo 'Template file could not be created.';
                    exit;
                }
            }
        }
    }
    if (!is_file($path)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo 'Template file could not be created.';
        exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="bank_statement_import_template.xlsx"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: max-age=86400');
    readfile($path);
    exit;
}

/** Build minimal styled XLSX on disk (ZipArchive only). */
function re_bank_build_import_template_xlsx_file(string $destPath): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    // Balance (running balance after each line) is optional for import but required for Statement balance on reports.
    $headers = ['date', 'description', 'reference', 'Debit', 'Credit', 'Balance'];
    $samples = [
        ['30-06-2026', 'OUTWARD CLEARING CHQ DEPOSIT', '26181026972519190282', '1500.00', '0.00', '148500.00'],
        ['30-06-2026', 'OUTWARD CLEARING CHQ DEPOSIT', '26181026972519190282', '1500.00', '0.00', '150000.00'],
        ['25-06-2026', 'BANKNET TRANSFER', '26181026972519190282', '0.00', '10879.00', '151500.00'],
        ['25-06-2026', 'VALUE ADDED TAX', '--', '0.00', '5.00', '140621.00'],
    ];

    $shared = [];
    $sharedIndex = static function (string $s) use (&$shared): int {
        $key = array_search($s, $shared, true);
        if ($key !== false) {
            return (int) $key;
        }
        $shared[] = $s;
        return count($shared) - 1;
    };

    $allRows = array_merge([$headers], $samples);
    foreach ($allRows as $row) {
        foreach ($row as $val) {
            $sharedIndex((string) $val);
        }
    }

    $sheetRows = '';
    $rowNum = 0;
    foreach ($allRows as $row) {
        $rowNum++;
        $cells = '';
        foreach ($row as $i => $val) {
            $col = re_bank_xlsx_index_to_col((int) $i);
            if ($rowNum === 1) {
                $cells .= '<c r="' . $col . $rowNum . '" t="s" s="1"><v>' . $sharedIndex((string) $val) . '</v></c>';
            } elseif ($i >= 3) {
                $num = str_replace(',', '', (string) $val);
                $cells .= '<c r="' . $col . $rowNum . '" s="2"><v>' . htmlspecialchars($num, ENT_XML1) . '</v></c>';
            } else {
                $cells .= '<c r="' . $col . $rowNum . '" t="s"><v>' . $sharedIndex((string) $val) . '</v></c>';
            }
        }
        $sheetRows .= '<row r="' . $rowNum . '" spans="1:6">' . $cells . '</row>';
    }

    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $s) {
        $sharedXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $sharedXml .= '</sst>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '</cellXfs></styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet0" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    if (!is_writable($dir)) {
        return false;
    }

    $tmp = $destPath . '.tmp';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->close();

    return rename($tmp, $destPath);
}

/**
 * Safe PhpSpreadsheet bootstrap (skips Composer platform_check fatals on PHP 8.2).
 */
function re_bank_try_load_phpspreadsheet(): bool
{
    if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        return true;
    }
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return false;
    }
    try {
        ob_start();
        require_once $autoload;
        ob_end_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        error_log('re_bank_try_load_phpspreadsheet: ' . $e->getMessage());
        return false;
    }
    return class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class);
}
