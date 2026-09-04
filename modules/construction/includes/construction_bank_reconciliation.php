<?php
/**
 * Construction module — bank reconciliation helpers (re_bank_accounts + re_general_ledger).
 */
declare(strict_types=1);

require_once __DIR__ . '/construction_helpers.php';

function co_bank_period_from_date(string $date): string
{
    return substr($date, 0, 7);
}

function co_bank_is_period_locked(PDO $conn, int $bank_account_id, string $dateYmd): bool
{
    if (!co_db_table_exists($conn, 'co_reconciliation_period_locks')) {
        return false;
    }
    $p = co_bank_period_from_date($dateYmd);
    $st = $conn->prepare('SELECT 1 FROM co_reconciliation_period_locks WHERE bank_account_id = ? AND period = ? AND locked = 1 LIMIT 1');
    $st->execute([$bank_account_id, $p]);
    return (bool) $st->fetchColumn();
}

/** @return array<string,mixed>|null */
function co_bank_verify_account(PDO $conn, int $bank_account_id, int $company_id): ?array
{
    $st = $conn->prepare('
        SELECT ba.*, coa.account_code, coa.account_name AS coa_name
        FROM re_bank_accounts ba
        JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id AND coa.company_id = ba.company_id
        WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1
        LIMIT 1
    ');
    $st->execute([$bank_account_id, $company_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function co_bank_line_matched_sum(PDO $conn, int $line_id): float
{
    if (!co_db_table_exists($conn, 'co_reconciliation_matches')) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount_matched), 0) FROM co_reconciliation_matches
        WHERE bank_statement_line_id = ? AND status IN ('proposed','confirmed')
    ");
    $st->execute([$line_id]);
    return round((float) $st->fetchColumn(), 2);
}

function co_bank_line_remaining(PDO $conn, array $line): float
{
    $amt = (float) ($line['amount'] ?? 0);
    $matched = co_bank_line_matched_sum($conn, (int) $line['id']);
    return round(max(0, abs($amt) - $matched), 2);
}

function co_gl_entry_matched_sum(PDO $conn, int $gl_id, ?string $statusFilter = null): float
{
    if (!co_db_table_exists($conn, 'co_reconciliation_matches')) {
        return 0.0;
    }
    $sql = "
        SELECT COALESCE(SUM(amount_matched), 0) FROM co_reconciliation_matches
        WHERE system_id = ? AND status IN ('proposed','confirmed')
    ";
    if ($statusFilter === 'confirmed') {
        $sql = "
            SELECT COALESCE(SUM(amount_matched), 0) FROM co_reconciliation_matches
            WHERE system_id = ? AND status = 'confirmed'
        ";
    }
    $st = $conn->prepare($sql);
    $st->execute([$gl_id]);
    return round((float) $st->fetchColumn(), 2);
}

function co_gl_entry_amount(array $gl): float
{
    $debit = round((float) ($gl['debit_amount'] ?? 0), 2);
    $credit = round((float) ($gl['credit_amount'] ?? 0), 2);
    return $debit > 0 ? $debit : $credit;
}

/**
 * @return list<array{id:int,bank_statement_line_id:int,amount_matched:float,status:string}>
 */
function co_match_duplicate_warnings(PDO $conn, string $system_type, int $system_id, ?int $exclude_match_id = null): array
{
    $sql = "
        SELECT m.id, m.bank_statement_line_id, m.amount_matched, m.status
        FROM co_reconciliation_matches m
        WHERE m.system_type = ? AND m.system_id = ? AND m.status IN ('proposed','confirmed')
    ";
    $params = [$system_type, $system_id];
    if ($exclude_match_id) {
        $sql .= ' AND m.id <> ?';
        $params[] = $exclude_match_id;
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function co_source_hash(int $bank_account_id, string $date, string $amount, string $desc, string $ref): string
{
    $payload = $bank_account_id . '|' . $date . '|' . $amount . '|' . $desc . '|' . $ref;
    return hash('sha256', $payload);
}

function co_bank_reco_tables_ready(PDO $conn): bool
{
    return co_db_table_exists($conn, 'co_bank_statement_lines')
        && co_db_table_exists($conn, 'co_reconciliation_matches');
}

function co_bank_sync_gl_reconciled_flag(PDO $conn, int $gl_id, int $company_id, ?int $user_id = null): void
{
    if (!co_db_column_exists($conn, 're_general_ledger', 'is_reconciled')) {
        return;
    }
    $confirmed = co_gl_entry_matched_sum($conn, $gl_id, 'confirmed');
    $st = $conn->prepare('SELECT debit_amount, credit_amount FROM re_general_ledger WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$gl_id, $company_id]);
    $gl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$gl) {
        return;
    }
    $entryAmt = co_gl_entry_amount($gl);
    if ($confirmed >= $entryAmt - 0.009) {
        $up = $conn->prepare('UPDATE re_general_ledger SET is_reconciled = 1, reconciled_at = NOW(), reconciled_by = ? WHERE id = ? AND company_id = ?');
        $up->execute([$user_id ?: null, $gl_id, $company_id]);
    } else {
        $up = $conn->prepare('UPDATE re_general_ledger SET is_reconciled = 0, reconciled_at = NULL, reconciled_by = NULL WHERE id = ? AND company_id = ?');
        $up->execute([$gl_id, $company_id]);
    }
}

function co_bank_normalize_header_cell(string $h): string
{
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    $h = strtolower(trim($h));
    $h = preg_replace('/[\s_\-]+/', '', $h);
    return $h;
}

/** @return array<string,int>|null */
function co_bank_resolve_import_columns(array $headers): ?array
{
    $aliases = [
        'date' => ['date', 'txndate', 'transactiondate', 'valuedate', 'postingdate', 'bookdate'],
        'amount' => ['amount', 'amt', 'value', 'transactionamount'],
        'description' => ['description', 'desc', 'details', 'narration', 'memo', 'particulars'],
        'reference' => ['reference', 'ref', 'referenceno', 'chequeno', 'referencenumber', 'chqno'],
        'debit' => ['debit', 'withdrawal', 'withdrawals', 'dr', 'debitamount', 'moneyout', 'paidout'],
        'credit' => ['credit', 'deposit', 'deposits', 'cr', 'creditamount', 'moneyin', 'paidin'],
    ];

    $normalized = [];
    foreach ($headers as $i => $h) {
        $normalized[$i] = co_bank_normalize_header_cell((string) $h);
    }

    $map = [];
    foreach ($aliases as $field => $names) {
        foreach ($normalized as $idx => $norm) {
            if ($norm === '' || isset($map[$field])) {
                continue;
            }
            if ($norm === $field || in_array($norm, $names, true)) {
                $map[$field] = $idx;
                break;
            }
        }
    }

    if (!isset($map['date'], $map['description'])) {
        if (count($headers) >= 5 && ($normalized[0] ?? '') === 'date' && ($normalized[3] ?? '') === 'debit') {
            return ['date' => 0, 'description' => 1, 'reference' => 2, 'debit' => 3, 'credit' => 4];
        }
        if (count($headers) >= 3) {
            return ['date' => 0, 'amount' => 1, 'description' => 2, 'reference' => count($headers) > 3 ? 3 : -1];
        }
        return null;
    }

    if (!isset($map['reference'])) {
        $map['reference'] = -1;
    }

    $hasAmount = isset($map['amount']);
    $hasDebitCredit = isset($map['debit']) || isset($map['credit']);
    if (!$hasAmount && !$hasDebitCredit) {
        return null;
    }

    return $map;
}

/**
 * @param array<int, mixed> $cells
 * @return array{line:int,date:mixed,amount?:string,debit?:string,credit?:string,description:string,reference:string}|null
 */
function co_bank_build_import_row_from_cells(array $cells, array $colMap, int $lineNum): ?array
{
    $dateRaw = $cells[$colMap['date']] ?? '';
    $desc = trim((string) ($cells[$colMap['description']] ?? ''));
    $refIdx = $colMap['reference'] ?? -1;
    $ref = $refIdx >= 0 ? trim((string) ($cells[$refIdx] ?? '')) : '';
    if ($ref === '--' || $ref === '-') {
        $ref = '';
    }

    $row = [
        'line' => $lineNum,
        'date' => is_string($dateRaw) ? trim($dateRaw) : $dateRaw,
        'description' => $desc,
        'reference' => $ref,
    ];

    if (isset($colMap['debit']) || isset($colMap['credit'])) {
        $debitIdx = $colMap['debit'] ?? -1;
        $creditIdx = $colMap['credit'] ?? -1;
        if ($debitIdx >= 0) {
            $row['debit'] = trim((string) ($cells[$debitIdx] ?? ''));
        }
        if ($creditIdx >= 0) {
            $row['credit'] = trim((string) ($cells[$creditIdx] ?? ''));
        }
    } elseif (isset($colMap['amount'])) {
        $row['amount'] = trim((string) ($cells[$colMap['amount']] ?? ''));
    }

    $emptyAmount = !isset($row['amount']) && empty($row['debit']) && empty($row['credit']);
    if (($row['date'] === '' || $row['date'] === null) && $desc === '' && $emptyAmount) {
        return null;
    }

    return $row;
}

/** @param mixed $raw */
function co_bank_normalize_import_date($raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if ($raw instanceof \DateTimeInterface) {
        return $raw->format('Y-m-d');
    }
    if (is_numeric($raw) && (float) $raw > 1000 && class_exists(\PhpOffice\PhpSpreadsheet\Shared\Date::class)) {
        try {
            return date('Y-m-d', (int) \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float) $raw));
        } catch (Throwable $e) {
            // fall through to string parsing
        }
    }

    $s = trim((string) $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }

    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $s, $parts)) {
        $y = (int) $parts[3];
        if ($y < 100) {
            $y += ($y >= 70 ? 1900 : 2000);
        }
        $a = (int) $parts[1];
        $b = (int) $parts[2];
        // Prefer DD-MM-YYYY (UAE bank statements) when unambiguous or when day > 12
        foreach ([[$a, $b, $y], [$b, $a, $y]] as [$day, $month, $year]) {
            if ($day > 12 && $month <= 12) {
                // First segment must be day
                if (checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
        }
        foreach ([[$a, $b, $y], [$b, $a, $y]] as [$day, $month, $year]) {
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
    }

    foreach (['d-m-Y', 'd/m/Y', 'd.m.Y', 'j-n-Y', 'd-m-y', 'd/m/y'] as $fmt) {
        $dt = \DateTime::createFromFormat($fmt, $s);
        if ($dt instanceof \DateTime) {
            $errs = \DateTime::getLastErrors();
            if (!$errs || ($errs['warning_count'] === 0 && $errs['error_count'] === 0)) {
                return $dt->format('Y-m-d');
            }
        }
    }

    $ts = strtotime($s);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

/** @param mixed $raw */
function co_bank_normalize_import_amount($raw): ?float
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_numeric($raw)) {
        return round((float) $raw, 2);
    }

    $s = trim((string) $raw);
    $s = str_replace(' ', '', $s);
    if (preg_match('/^\((.+)\)$/', $s, $m)) {
        $s = '-' . $m[1];
    }
    // 1,500.00 or 10,879.00
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s) || preg_match('/^-?\d+,\d{2}$/', $s)) {
        $s = str_replace(',', '', $s);
    } elseif (substr_count($s, ',') === 1 && !str_contains($s, '.')) {
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    $s = preg_replace('/^[A-Z]{3}/i', '', $s) ?? $s;
    if (!is_numeric($s)) {
        return null;
    }

    return round((float) $s, 2);
}

/**
 * Parse uploaded bank statement (CSV, XLSX, or XLS).
 *
 * @return array{rows: list<array{line:int,date:string,amount:string,description:string,reference:string}>, error: ?string}
 */
function co_bank_parse_statement_upload(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        require_once __DIR__ . '/construction_bank_reco_xlsx.php';
        return co_bank_parse_statement_xlsx_native($path);
    }
    if ($ext === 'xls') {
        return co_bank_parse_statement_excel($path);
    }

    return co_bank_parse_statement_csv($path);
}

/**
 * @return array{rows: list<array{line:int,date:string,amount:string,description:string,reference:string}>, error: ?string}
 */
function co_bank_parse_statement_csv(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['rows' => [], 'error' => 'Empty file'];
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $firstLine = strtok($raw, "\r\n");
    if ($firstLine === false) {
        return ['rows' => [], 'error' => 'Empty file'];
    }

    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return ['rows' => [], 'error' => 'Could not read file'];
    }

    $header = fgetcsv($fh, 0, $delimiter, '"', '\\');
    if (!$header) {
        fclose($fh);
        return ['rows' => [], 'error' => 'Missing header row'];
    }

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? $header[0];
    }

    $colMap = co_bank_resolve_import_columns($header);
    if (!$colMap) {
        fclose($fh);
        return ['rows' => [], 'error' => 'Could not find required columns. Row 1 must include: date, description, and amount or Debit/Credit columns (reference optional).'];
    }

    $rows = [];
    $lineNum = 1;
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $lineNum++;
        if (!$row || (count($row) === 1 && trim((string) ($row[0] ?? '')) === '')) {
            continue;
        }
        $built = co_bank_build_import_row_from_cells($row, $colMap, $lineNum);
        if ($built) {
            $rows[] = $built;
        }
    }
    fclose($fh);

    return ['rows' => $rows, 'error' => null];
}

function co_bank_excel_cell_display_value(?\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
{
    if ($cell === null) {
        return '';
    }
    $formatted = trim((string) $cell->getFormattedValue());
    if ($formatted !== '' && $formatted !== '-') {
        return $formatted;
    }
    $raw = $cell->getValue();
    if ($raw === null || $raw === '') {
        return '';
    }
    return trim((string) $raw);
}

/**
 * @return array{header_row:int,map:array<string,int>,headers:list<string>}|null
 */
function co_bank_find_excel_import_header(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $maxScan = 25): ?array
{
    $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($r = 1; $r <= $maxScan; $r++) {
        $headers = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $headers[] = co_bank_excel_cell_display_value($sheet->getCell($col . $r));
        }
        $map = co_bank_resolve_import_columns($headers);
        if ($map) {
            return ['header_row' => $r, 'map' => $map, 'headers' => $headers];
        }
    }
    return null;
}

/**
 * @return array{rows: list<array{line:int,date:string,amount:string,description:string,reference:string}>, error: ?string}
 */
function co_bank_parse_statement_excel(string $path): array
{
    require_once __DIR__ . '/construction_bank_reco_xlsx.php';
    if (!co_bank_try_load_phpspreadsheet()) {
        return ['rows' => [], 'error' => 'Excel .xls import requires PhpSpreadsheet. Please save the file as .xlsx or upload CSV.'];
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestRow();

        $headerInfo = co_bank_find_excel_import_header($sheet);
        if (!$headerInfo) {
            return ['rows' => [], 'error' => 'Could not find required columns. Use: date, description, reference, Debit, Credit (or a single amount column).'];
        }

        $headerRow = $headerInfo['header_row'];
        $colMap = $headerInfo['map'];
        if ($highestRow < $headerRow + 1) {
            return ['rows' => [], 'error' => 'Excel file has no data rows'];
        }

        $rows = [];
        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $cells = [];
            $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
            for ($c = 1; $c <= $highestCol; $c++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $cell = $sheet->getCell($col . $r);
                $idx = $c - 1;
                if ($idx === $colMap['date']) {
                    $dateRaw = $cell->getValue();
                    $cells[$idx] = co_bank_normalize_import_date($dateRaw)
                        ?? co_bank_normalize_import_date($cell->getFormattedValue())
                        ?? co_bank_excel_cell_display_value($cell);
                } else {
                    $cells[$idx] = co_bank_excel_cell_display_value($cell);
                }
            }

            $built = co_bank_build_import_row_from_cells($cells, $colMap, $r);
            if ($built) {
                $rows[] = $built;
            }
        }

        return ['rows' => $rows, 'error' => null];
    } catch (Throwable $e) {
        return ['rows' => [], 'error' => 'Excel read failed: ' . $e->getMessage()];
    }
}

/** Download styled XLSX import template (date, description, reference, Debit, Credit). */
function co_bank_rec_download_import_template_xlsx(): void
{
    require_once __DIR__ . '/construction_bank_reco_xlsx.php';
    co_bank_serve_import_template_xlsx();
}

function co_bank_reco_money(float $value): float
{
    return round($value, 2);
}

/** @return array{default_rate:float,input_vat_account_id:?int,output_vat_account_id:?int} */
function co_bank_reco_vat_config(PDO $conn, int $companyId): array
{
    require_once __DIR__ . '/construction_accounting_integration.php';
    $input = find_account_by_code(CO_ACCOUNT_INPUT_VAT, $companyId);
    $output = find_account_by_code(CO_ACCOUNT_OUTPUT_VAT, $companyId);
    return [
        'default_rate' => 5.0,
        'input_vat_account_id' => $input ? (int) $input['id'] : null,
        'output_vat_account_id' => $output ? (int) $output['id'] : null,
    ];
}

/** @return array{net:float,vat:float,gross:float} */
function co_bank_reco_vat_split(float $grossAmount, string $vatTreatment, float $vatRate): array
{
    $grossAmount = co_bank_reco_money(abs($grossAmount));
    if ($vatTreatment !== 'standard' || $vatRate <= 0) {
        return ['net' => $grossAmount, 'vat' => 0.0, 'gross' => $grossAmount];
    }
    $vat = co_bank_reco_money($grossAmount * $vatRate / (100 + $vatRate));
    $net = co_bank_reco_money($grossAmount - $vat);
    return ['net' => $net, 'vat' => $vat, 'gross' => $grossAmount];
}

/**
 * @return list<array<string,mixed>>
 */
function co_bank_reco_build_create_journal_lines(
    int $offsetAccountId,
    int $bankGlId,
    float $amount,
    bool $isSpent,
    string $description,
    string $ref,
    string $vatTreatment,
    float $vatRate,
    ?int $inputVatAccountId,
    ?int $outputVatAccountId
): array {
    $split = co_bank_reco_vat_split($amount, $vatTreatment, $vatRate);
    $vatAccountId = $isSpent ? $inputVatAccountId : $outputVatAccountId;

    if ($split['vat'] > 0.009) {
        if (!$vatAccountId) {
            throw new RuntimeException('VAT accounts not configured. Run Setup Accounts (Input VAT / Output VAT).');
        }
        if ($isSpent) {
            return [
                ['account_id' => $offsetAccountId, 'debit' => $split['net'], 'credit' => 0, 'description' => $description, 'reference' => $ref],
                ['account_id' => $vatAccountId, 'debit' => $split['vat'], 'credit' => 0, 'description' => 'VAT — ' . $description, 'reference' => $ref],
                ['account_id' => $bankGlId, 'debit' => 0, 'credit' => $split['gross'], 'description' => $description, 'reference' => $ref],
            ];
        }
        return [
            ['account_id' => $bankGlId, 'debit' => $split['gross'], 'credit' => 0, 'description' => $description, 'reference' => $ref],
            ['account_id' => $offsetAccountId, 'debit' => 0, 'credit' => $split['net'], 'description' => $description, 'reference' => $ref],
            ['account_id' => $vatAccountId, 'debit' => 0, 'credit' => $split['vat'], 'description' => 'VAT — ' . $description, 'reference' => $ref],
        ];
    }

    if ($isSpent) {
        return [
            ['account_id' => $offsetAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $ref],
            ['account_id' => $bankGlId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $ref],
        ];
    }
    return [
        ['account_id' => $bankGlId, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'reference' => $ref],
        ['account_id' => $offsetAccountId, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'reference' => $ref],
    ];
}

function co_bank_reco_has_permission(PDO $conn, string $permissionKey): bool
{
    require_once dirname(__DIR__, 3) . '/includes/permissions.php';
    require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';

    if (has_permission($permissionKey, MODULE_CONSTRUCTION, $conn)) {
        return true;
    }

    return has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);
}

function co_bank_reco_require_permission(PDO $conn, string $permissionKey): void
{
    if (!co_bank_reco_has_permission($conn, $permissionKey)) {
        if (php_sapi_name() === 'cli') {
            throw new RuntimeException('Permission denied: ' . $permissionKey);
        }
        http_response_code(403);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_contains($_SERVER['REQUEST_URI'] ?? '', '/ajax/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }
        die('Permission denied.');
    }
}

function co_bank_reco_audit(
    PDO $conn,
    int $companyId,
    int $bankAccountId,
    ?int $statementLineId,
    ?int $matchId,
    string $action,
    ?array $oldValue,
    ?array $newValue,
    ?int $userId,
    ?int $relatedTransactionId = null
): void {
    if (!co_db_table_exists($conn, 'co_bank_reconciliation_audit')) {
        return;
    }
    try {
        $st = $conn->prepare('
            INSERT INTO co_bank_reconciliation_audit
              (company_id, bank_account_id, statement_line_id, match_id, action, related_transaction_id, old_value, new_value, user_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $st->execute([
            $companyId,
            $bankAccountId,
            $statementLineId,
            $matchId,
            $action,
            $relatedTransactionId,
            $oldValue ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            $newValue ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('co_bank_reco_audit: ' . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/../../../includes/audit_bridge.php';
        audit_bridge_construction_bank_reco(
            $conn,
            $companyId,
            $action,
            $statementLineId,
            $matchId,
            $userId,
            $newValue ?: $oldValue
        );
    } catch (Throwable $e) {
        error_log('co_bank_reco_audit bridge: ' . $e->getMessage());
    }
}

function co_bank_line_status(PDO $conn, array $line): string
{
    if (!co_db_column_exists($conn, 'co_bank_statement_lines', 'status')) {
        $rem = co_bank_line_remaining($conn, $line);
        return $rem <= 0.009 ? 'reconciled' : 'unreconciled';
    }
    return (string) ($line['status'] ?? 'unreconciled');
}

function co_bank_refresh_line_status(PDO $conn, int $lineId, int $companyId): void
{
    if (!co_db_column_exists($conn, 'co_bank_statement_lines', 'status')) {
        return;
    }
    $st = $conn->prepare('SELECT * FROM co_bank_statement_lines WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$lineId, $companyId]);
    $line = $st->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        return;
    }
    $rem = co_bank_line_remaining($conn, $line);
    $status = $rem <= 0.009 ? 'reconciled' : (string) ($line['status'] ?? 'unreconciled');
    if ($status === 'reconciled') {
        // keep reconciled
    } elseif (in_array($status, ['discussed', 'ignored', 'duplicate'], true) && $rem > 0.009) {
        // preserve manual statuses until fully matched
    } elseif ($rem > 0.009) {
        $prop = $conn->prepare("SELECT COUNT(*) FROM co_reconciliation_matches WHERE bank_statement_line_id = ? AND status = 'proposed'");
        $prop->execute([$lineId]);
        $status = ((int) $prop->fetchColumn()) > 0 ? 'suggested' : 'unreconciled';
    }
    $conn->prepare('UPDATE co_bank_statement_lines SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
        ->execute([$status, $lineId, $companyId]);
}

/** @return array<string,mixed>|null */
function co_bank_get_statement_line(PDO $conn, int $lineId, int $companyId): ?array
{
    $st = $conn->prepare('
        SELECT l.*, b.gl_account_id, coa.account_code, coa.account_name AS coa_name, b.account_name AS bank_name
        FROM co_bank_statement_lines l
        JOIN re_bank_accounts b ON b.id = l.bank_account_id AND b.company_id = l.company_id
        JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        WHERE l.id = ? AND l.company_id = ?
        LIMIT 1
    ');
    $st->execute([$lineId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['remaining'] = co_bank_line_remaining($conn, $row);
    $row['is_spent'] = (float) $row['amount'] < 0;
    $row['is_received'] = (float) $row['amount'] > 0;
    $row['abs_amount'] = round(abs((float) $row['amount']), 2);
    return $row;
}

function co_bank_erp_balance(PDO $conn, int $glAccountId, int $companyId, string $asOfDate): float
{
    $st = $conn->prepare('
        SELECT COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0)
        FROM re_general_ledger
        WHERE account_id = ? AND company_id = ? AND entry_date <= ?
    ');
    $st->execute([$glAccountId, $companyId, $asOfDate]);
    return co_bank_reco_money((float) $st->fetchColumn());
}

function co_bank_statement_balance(PDO $conn, int $bankAccountId, int $companyId, string $asOfDate): ?float
{
    $st = $conn->prepare('
        SELECT balance_after
        FROM co_bank_statement_lines
        WHERE bank_account_id = ? AND company_id = ? AND txn_date <= ?
        ORDER BY txn_date DESC, id DESC
        LIMIT 1
    ');
    $st->execute([$bankAccountId, $companyId, $asOfDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['balance_after'] === null) {
        return null;
    }
    return co_bank_reco_money((float) $row['balance_after']);
}

function co_bank_confidence_label(float $score): string
{
    if ($score >= 90) {
        return 'High';
    }
    if ($score >= 70) {
        return 'Medium';
    }
    return 'Low';
}

/**
 * Contacts for Create tab (Who): clients, suppliers, contractors.
 *
 * @return array{clients:list<array{id:int,name:string}>,suppliers:list<array{id:int,name:string}>,contractors:list<array{id:int,name:string}>}
 */
function co_bank_reco_contacts(PDO $conn, int $companyId): array
{
    $out = ['clients' => [], 'suppliers' => [], 'contractors' => []];
    if (co_db_table_exists($conn, 'co_clients')) {
        $st = $conn->prepare('SELECT id, client_name AS name FROM co_clients WHERE company_id = ? ORDER BY client_name');
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['clients'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }
    }
    if (co_db_table_exists($conn, 'co_suppliers')) {
        $st = $conn->prepare('SELECT id, supplier_name AS name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name');
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['suppliers'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }
    }
    if (co_db_table_exists($conn, 'co_contractors')) {
        $st = $conn->prepare('SELECT id, contractor_name AS name FROM co_contractors WHERE company_id = ? AND is_active = 1 ORDER BY contractor_name');
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['contractors'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }
    }
    return $out;
}

/** @return array{type:?string,id:?int,name:?string} */
function co_bank_reco_resolve_contact(PDO $conn, int $companyId, ?string $contactType, ?int $contactId): array
{
    if (!$contactType || !$contactId) {
        return ['type' => null, 'id' => null, 'name' => null];
    }
    $map = [
        'client' => ['co_clients', 'client_name'],
        'supplier' => ['co_suppliers', 'supplier_name'],
        'contractor' => ['co_contractors', 'contractor_name'],
    ];
    if (!isset($map[$contactType])) {
        return ['type' => null, 'id' => null, 'name' => null];
    }
    [$table, $col] = $map[$contactType];
    if (!co_db_table_exists($conn, $table)) {
        return ['type' => null, 'id' => null, 'name' => null];
    }
    $st = $conn->prepare("SELECT id, {$col} AS name FROM {$table} WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$contactId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['type' => null, 'id' => null, 'name' => null];
    }
    return ['type' => $contactType, 'id' => (int) $row['id'], 'name' => (string) $row['name']];
}

/**
 * Guess a contact from statement line text (for Create tab pre-fill).
 *
 * @return array{type:string,id:int,name:string,score:float}|null
 */
function co_bank_reco_guess_contact(PDO $conn, int $companyId, string $text, bool $preferReceived = false): ?array
{
    $hay = mb_strtolower(trim($text), 'UTF-8');
    if (mb_strlen($hay) < 3) {
        return null;
    }
    $contacts = co_bank_reco_contacts($conn, $companyId);
    $groups = $preferReceived
        ? ['clients' => 'client', 'suppliers' => 'supplier', 'contractors' => 'contractor']
        : ['suppliers' => 'supplier', 'contractors' => 'contractor', 'clients' => 'client'];
    $best = null;
    foreach ($groups as $key => $type) {
        foreach ($contacts[$key] as $c) {
            $name = mb_strtolower(trim($c['name']), 'UTF-8');
            if ($name === '' || mb_strlen($name) < 3) {
                continue;
            }
            $score = 0.0;
            if ($hay === $name) {
                $score = 100;
            } elseif (str_contains($hay, $name)) {
                $score = 80 + min(15, mb_strlen($name) / 3);
            } elseif (str_contains($name, $hay)) {
                $score = 60;
            } else {
                similar_text($hay, $name, $pct);
                if ($pct >= 55) {
                    $score = $pct;
                }
            }
            if ($score >= 55 && (!$best || $score > $best['score'])) {
                $best = ['type' => $type, 'id' => (int) $c['id'], 'name' => $c['name'], 'score' => $score];
            }
        }
    }
    return $best;
}

function co_bank_reco_description_with_contact(string $description, ?string $contactName, string $transactionType): string
{
    $description = trim($description);
    $contactName = trim((string) $contactName);
    if ($contactName === '') {
        return $description;
    }
    if ($description !== '' && str_contains(mb_strtolower($description, 'UTF-8'), mb_strtolower($contactName, 'UTF-8'))) {
        return $description;
    }
    $prefix = match ($transactionType) {
        'client_receipt' => 'Client receipt',
        'supplier_payment' => 'Supplier payment',
        'contractor_payment' => 'Contractor payment',
        'bank_charge' => 'Bank charge',
        'cash_withdrawal' => 'Cash withdrawal',
        default => 'Payment',
    };
    $base = $prefix . ': ' . $contactName;
    return $description !== '' ? ($base . ' — ' . $description) : $base;
}

/**
 * Phase 1 GL-only suggestion engine (amount + date + reference).
 *
 * @return list<array<string,mixed>>
 */
function co_bank_reco_gl_suggestions(PDO $conn, int $companyId, array $line): array
{
    require_once __DIR__ . '/construction_bank_reco_engine.php';
    return co_bank_reco_suggestions_for_line($conn, $companyId, $line, 50);
}

/**
 * Unreconciled GL bank lines to pick manually on the Match tab.
 *
 * @return list<array<string,mixed>>
 */
function co_bank_reco_gl_candidates_for_line(PDO $conn, int $companyId, array $line, int $limit = 50): array
{
    $glAccountId = (int) ($line['gl_account_id'] ?? 0);
    if ($glAccountId <= 0) {
        return [];
    }
    $abs = round(abs((float) ($line['amount'] ?? 0)), 2);
    $date = (string) ($line['txn_date'] ?? date('Y-m-d'));
    $isInflow = (float) ($line['amount'] ?? 0) > 0;
    $amountCol = $isInflow ? 'debit_amount' : 'credit_amount';
    $systemType = $isInflow ? 'gl_inflow' : 'gl_outflow';

    $st = $conn->prepare("
        SELECT gl.id, gl.entry_date, gl.debit_amount, gl.credit_amount, gl.description, gl.reference, jh.journal_number
        FROM re_general_ledger gl
        LEFT JOIN re_journal_headers jh ON jh.id = gl.journal_id
        WHERE gl.company_id = ? AND gl.account_id = ?
          AND gl.{$amountCol} > 0
          AND gl.entry_date BETWEEN DATE_SUB(?, INTERVAL 60 DAY) AND DATE_ADD(?, INTERVAL 60 DAY)
        ORDER BY ABS(gl.{$amountCol} - ?) ASC, ABS(DATEDIFF(gl.entry_date, ?)) ASC, gl.id DESC
        LIMIT 200
    ");
    $st->execute([$companyId, $glAccountId, $date, $date, $abs, $date]);

    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $gl) {
        $gid = (int) $gl['id'];
        $entryAmt = co_gl_entry_amount($gl);
        $sysRem = round($entryAmt - co_gl_entry_matched_sum($conn, $gid), 2);
        if ($sysRem <= 0.009) {
            continue;
        }
        $items[] = [
            'system_type' => $systemType,
            'system_id' => $gid,
            'txn_date' => $gl['entry_date'],
            'amount' => $entryAmt,
            'remaining' => $sysRem,
            'reference' => $gl['reference'] ?? '',
            'description' => $gl['description'] ?? '',
            'label' => trim(($gl['journal_number'] ?? 'GL') . ' — ' . ($gl['description'] ?: $gl['reference'] ?: ('#' . $gid))),
            'amount_diff' => round(abs($entryAmt - $abs), 2),
        ];
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

/**
 * @param list<array{line:int,date:string,amount:string,description:string,reference:string,value_date?:string,cheque_no?:string,debit?:string,credit?:string,balance?:string}> $rows
 * @return array{rows:list<array<string,mixed>>,summary:array<string,mixed>,error:?string}
 */
function co_bank_rec_import_preview(PDO $conn, int $companyId, int $bankAccountId, array $rows, string $fileName): array
{
    $valid = [];
    $duplicates = [];
    $errors = [];
    $totalDebits = 0.0;
    $totalCredits = 0.0;
    $closingBalance = null;

    foreach ($rows as $r) {
        $lineNo = (int) ($r['line'] ?? 0);
        $d = co_bank_normalize_import_date($r['date'] ?? '');
        if ($d === null) {
            $errors[] = ['line' => $lineNo, 'reason' => 'Invalid date'];
            continue;
        }
        $debit = 0.0;
        $credit = 0.0;
        $amt = null;
        if (array_key_exists('debit', $r) || array_key_exists('credit', $r)) {
            $debitRaw = trim((string) ($r['debit'] ?? ''));
            $creditRaw = trim((string) ($r['credit'] ?? ''));
            if ($debitRaw !== '') {
                $debit = (float) co_bank_normalize_import_amount($debitRaw);
            }
            if ($creditRaw !== '') {
                $credit = (float) co_bank_normalize_import_amount($creditRaw);
            }
            if ($debit > 0 || $credit > 0) {
                $amt = round($credit - $debit, 2);
            }
        } else {
            $amt = co_bank_normalize_import_amount($r['amount'] ?? '');
        }
        if ($amt === null || abs($amt) < 0.001) {
            $errors[] = ['line' => $lineNo, 'reason' => 'Invalid amount'];
            continue;
        }
        $desc = mb_substr(trim((string) ($r['description'] ?? '')), 0, 500);
        $ref = trim((string) ($r['reference'] ?? ''));
        $refDb = $ref !== '' ? mb_substr($ref, 0, 120) : null;
        $balance = isset($r['balance']) && $r['balance'] !== '' ? co_bank_normalize_import_amount($r['balance']) : null;
        $hash = co_source_hash($bankAccountId, $d, (string) $amt, $desc, (string) ($refDb ?? ''));
        $chk = $conn->prepare('SELECT id FROM co_bank_statement_lines WHERE bank_account_id = ? AND source_hash = ? LIMIT 1');
        $chk->execute([$bankAccountId, $hash]);
        if ($chk->fetch()) {
            $duplicates[] = ['line' => $lineNo, 'date' => $d, 'amount' => $amt, 'description' => $desc];
            continue;
        }
        if ($amt < 0) {
            $totalDebits += abs($amt);
        } else {
            $totalCredits += $amt;
        }
        if ($balance !== null) {
            $closingBalance = $balance;
        }
        $valid[] = [
            'line' => $lineNo,
            'txn_date' => $d,
            'value_date' => !empty($r['value_date']) ? co_bank_normalize_import_date($r['value_date']) : null,
            'amount' => $amt,
            'debit_amount' => $amt < 0 ? abs($amt) : 0,
            'credit_amount' => $amt > 0 ? $amt : 0,
            'description' => $desc,
            'reference' => $refDb,
            'cheque_no' => !empty($r['cheque_no']) ? mb_substr((string) $r['cheque_no'], 0, 80) : null,
            'balance_after' => $balance,
            'source_hash' => $hash,
        ];
    }

    return [
        'rows' => $valid,
        'summary' => [
            'file_name' => $fileName,
            'total_rows' => count($rows),
            'valid_rows' => count($valid),
            'duplicate_rows' => count($duplicates),
            'error_rows' => count($errors),
            'total_debits' => co_bank_reco_money($totalDebits),
            'total_credits' => co_bank_reco_money($totalCredits),
            'closing_balance' => $closingBalance,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ],
        'error' => null,
    ];
}

/**
 * @param list<array<string,mixed>> $validRows
 * @return array{success:bool,batch_id:?int,inserted:int,error:?string}
 */
function co_bank_rec_import_confirm(PDO $conn, int $companyId, int $bankAccountId, string $fileName, array $validRows, ?int $userId = null): array
{
    if (!$validRows) {
        return ['success' => false, 'batch_id' => null, 'inserted' => 0, 'error' => 'No valid rows to import'];
    }
    $dates = array_column($validRows, 'txn_date');
    sort($dates);
    $opening = null;
    $closing = null;
    $totalDebits = 0.0;
    $totalCredits = 0.0;
    foreach ($validRows as $row) {
        $totalDebits += (float) ($row['debit_amount'] ?? 0);
        $totalCredits += (float) ($row['credit_amount'] ?? 0);
        if (isset($row['balance_after']) && $row['balance_after'] !== null) {
            $closing = (float) $row['balance_after'];
        }
    }

    try {
        $conn->beginTransaction();
        $batchSql = '
            INSERT INTO co_bank_import_batches
              (company_id, bank_account_id, file_name, imported_by, row_count, notes';
        $batchVals = [$companyId, $bankAccountId, $fileName, $userId ?: null, 0, null];
        if (co_db_column_exists($conn, 'co_bank_import_batches', 'statement_start_date')) {
            $batchSql .= ', statement_start_date, statement_end_date, opening_balance, closing_balance, total_debits, total_credits, status';
            $batchVals = array_merge($batchVals, [$dates[0] ?? null, $dates ? $dates[count($dates) - 1] : null, $opening, $closing, $totalDebits, $totalCredits, 'imported']);
        }
        $batchSql .= ') VALUES (' . implode(',', array_fill(0, count($batchVals), '?')) . ')';
        $conn->prepare($batchSql)->execute($batchVals);
        $batchId = (int) $conn->lastInsertId();

        $hasExtended = co_db_column_exists($conn, 'co_bank_statement_lines', 'debit_amount');
        $inserted = 0;
        foreach ($validRows as $row) {
            if (co_bank_is_period_locked($conn, $bankAccountId, $row['txn_date'])) {
                continue;
            }
            if ($hasExtended) {
                $ins = $conn->prepare('
                    INSERT INTO co_bank_statement_lines
                      (company_id, bank_account_id, txn_date, value_date, amount, debit_amount, credit_amount, description, reference, cheque_no, balance_after, import_batch_id, source_hash, currency, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $ins->execute([
                    $companyId, $bankAccountId, $row['txn_date'], $row['value_date'] ?? null,
                    $row['amount'], $row['debit_amount'], $row['credit_amount'],
                    $row['description'], $row['reference'], $row['cheque_no'] ?? null,
                    $row['balance_after'] ?? null, $batchId, $row['source_hash'], 'AED', 'unreconciled', $userId ?: null,
                ]);
            } else {
                $ins = $conn->prepare('
                    INSERT INTO co_bank_statement_lines
                      (company_id, bank_account_id, txn_date, amount, description, reference, import_batch_id, source_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $ins->execute([
                    $companyId, $bankAccountId, $row['txn_date'], $row['amount'],
                    $row['description'], $row['reference'], $batchId, $row['source_hash'],
                ]);
            }
            $inserted++;
        }
        $conn->prepare('UPDATE co_bank_import_batches SET row_count = ? WHERE id = ?')->execute([$inserted, $batchId]);
        co_bank_reco_audit($conn, $companyId, $bankAccountId, null, null, 'import_batch', null, [
            'batch_id' => $batchId,
            'inserted' => $inserted,
            'file_name' => $fileName,
        ], $userId);
        $conn->commit();
        return ['success' => true, 'batch_id' => $batchId, 'inserted' => $inserted, 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'batch_id' => null, 'inserted' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Confirm proposed match(es) for a statement line.
 *
 * @param list<int> $matchIds
 * @return array{success:bool,confirmed:int,errors:list<string>}
 */
function co_bank_reco_confirm_matches(PDO $conn, int $companyId, array $matchIds, ?int $userId = null): array
{
    $confirmed = 0;
    $errors = [];
    foreach ($matchIds as $mid) {
        $st = $conn->prepare('
            SELECT m.*, l.txn_date, l.bank_account_id, l.company_id
            FROM co_reconciliation_matches m
            INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
            WHERE m.id = ? AND l.company_id = ?
        ');
        $st->execute([(int) $mid, $companyId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m || $m['status'] !== 'proposed') {
            $errors[] = "Match #{$mid}: not proposed or missing";
            continue;
        }
        if (co_bank_is_period_locked($conn, (int) $m['bank_account_id'], $m['txn_date'])) {
            $errors[] = "Match #{$mid}: period locked";
            continue;
        }
        $up = $conn->prepare("
            UPDATE co_reconciliation_matches
            SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
            WHERE id = ? AND status = 'proposed'
        ");
        $up->execute([$userId ?: null, (int) $mid]);
        if ($up->rowCount()) {
            $confirmed++;
            co_bank_sync_gl_reconciled_flag($conn, (int) $m['system_id'], $companyId, $userId);
            co_bank_refresh_line_status($conn, (int) $m['bank_statement_line_id'], $companyId);
            co_bank_reco_audit($conn, $companyId, (int) $m['bank_account_id'], (int) $m['bank_statement_line_id'], (int) $mid, 'confirm_match', ['status' => 'proposed'], ['status' => 'confirmed'], $userId, (int) $m['system_id']);
        }
    }
    return ['success' => $confirmed > 0 || !$errors, 'confirmed' => $confirmed, 'errors' => $errors];
}

/**
 * Undo a confirmed match (Remove & Redo). Reverses created journals when applicable.
 *
 * @return array{success:bool,reversed:bool,voided_match_ids:list<int>,error:?string}
 */
function co_bank_reco_undo_match(PDO $conn, int $companyId, int $matchId, ?int $userId, bool $reverseCreated = true): array
{
    $st = $conn->prepare('
        SELECT m.*, l.txn_date, l.bank_account_id, l.company_id, l.id AS statement_line_id
        FROM co_reconciliation_matches m
        INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE m.id = ? AND l.company_id = ? AND m.status IN (\'proposed\',\'confirmed\')
    ');
    $st->execute([$matchId, $companyId]);
    $match = $st->fetch(PDO::FETCH_ASSOC);
    if (!$match) {
        return ['success' => false, 'reversed' => false, 'voided_match_ids' => [], 'error' => 'Match not found or already void'];
    }

    $locked = co_bank_is_period_locked($conn, (int) $match['bank_account_id'], $match['txn_date']);
    if ($locked && !co_bank_reco_has_permission($conn, 'construction.bank_reconciliation.admin_override')) {
        return ['success' => false, 'reversed' => false, 'voided_match_ids' => [], 'error' => 'Period locked — admin override required'];
    }

    $journalId = (int) ($match['created_transaction_id'] ?? 0);
    $method = (string) ($match['match_method'] ?? 'match');
    $needsReverse = $reverseCreated && $journalId > 0 && in_array($method, ['create', 'adjustment', 'transfer'], true);

    try {
        $conn->beginTransaction();
        $voided = [];
        $reversed = false;

        if ($needsReverse) {
            require_once dirname(__DIR__, 3) . '/modules/realestate/accounting/accounting_engine.php';
            $rev = reverse_journal($journalId, 'Bank reconciliation undo match #' . $matchId, $userId);
            if (empty($rev['success'])) {
                throw new RuntimeException($rev['error'] ?? 'Could not reverse journal');
            }
            $reversed = true;

            if ($method === 'transfer') {
                $stPair = $conn->prepare("
                    SELECT id FROM co_reconciliation_matches
                    WHERE created_transaction_id = ? AND status IN ('proposed','confirmed') AND id <> ?
                ");
                $stPair->execute([$journalId, $matchId]);
                foreach ($stPair->fetchAll(PDO::FETCH_COLUMN) as $pairId) {
                    $voided[] = co_bank_reco_void_match_row($conn, (int) $pairId, $companyId, $userId);
                }
            }
        }

        $voided[] = co_bank_reco_void_match_row($conn, $matchId, $companyId, $userId);
        $voided = array_values(array_filter(array_unique($voided)));

        co_bank_refresh_line_status($conn, (int) $match['statement_line_id'], $companyId);
        co_bank_reco_audit($conn, $companyId, (int) $match['bank_account_id'], (int) $match['statement_line_id'], $matchId, 'undo_match', ['status' => $match['status']], ['status' => 'void', 'reversed' => $reversed], $userId, $journalId ?: null);

        $conn->commit();
        return ['success' => true, 'reversed' => $reversed, 'voided_match_ids' => $voided, 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'reversed' => false, 'voided_match_ids' => [], 'error' => $e->getMessage()];
    }
}

function co_bank_reco_void_match_row(PDO $conn, int $matchId, int $companyId, ?int $userId): int
{
    $st = $conn->prepare('
        SELECT m.*, l.company_id
        FROM co_reconciliation_matches m
        INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
        WHERE m.id = ? AND l.company_id = ? AND m.status IN (\'proposed\',\'confirmed\')
    ');
    $st->execute([$matchId, $companyId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return 0;
    }
    $conn->prepare("
        UPDATE co_reconciliation_matches
        SET status = 'void', voided_by = ?, voided_at = NOW()
        WHERE id = ? AND status IN ('proposed','confirmed')
    ")->execute([$userId ?: null, $matchId]);
    co_bank_sync_gl_reconciled_flag($conn, (int) $m['system_id'], $companyId, $userId);
    co_bank_refresh_line_status($conn, (int) $m['bank_statement_line_id'], $companyId);
    return $matchId;
}

/**
 * @return list<array<string,mixed>>
 */
function co_bank_reco_history(PDO $conn, int $companyId, ?int $bankAccountId = null, string $dateFrom = '', string $dateTo = '', int $limit = 200): array
{
    $sql = "
        SELECT m.*, l.txn_date, l.description AS line_description, l.reference AS line_reference, l.amount AS line_amount,
               b.account_name AS bank_name, coa.account_code,
               gl.description AS gl_description, gl.reference AS gl_reference,
               jh.journal_number, jh.reference_type
        FROM co_reconciliation_matches m
        INNER JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
        INNER JOIN re_bank_accounts b ON b.id = l.bank_account_id AND b.company_id = l.company_id
        INNER JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        LEFT JOIN re_general_ledger gl ON gl.id = m.system_id
        LEFT JOIN re_journal_headers jh ON jh.id = gl.journal_id
        WHERE l.company_id = ? AND m.status = 'confirmed'
    ";
    $params = [$companyId];
    if ($bankAccountId) {
        $sql .= ' AND l.bank_account_id = ?';
        $params[] = $bankAccountId;
    }
    if ($dateFrom !== '') {
        $sql .= ' AND COALESCE(m.confirmed_at, l.txn_date) >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $sql .= ' AND COALESCE(m.confirmed_at, l.txn_date) <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    $sql .= ' ORDER BY m.confirmed_at DESC, m.id DESC LIMIT ' . max(1, min(500, $limit));

    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $userJoin = co_db_table_exists($conn, 'user') ? 'user' : (co_db_table_exists($conn, 'users') ? 'users' : null);
    $nameCol = 'username';
    if ($userJoin === 'user') {
        $nameCol = co_db_column_exists($conn, 'user', 'fullname') ? 'fullname' : 'username';
    } elseif ($userJoin === 'users' && co_db_column_exists($conn, 'users', 'full_name')) {
        $nameCol = 'full_name';
    }

    foreach ($rows as &$row) {
        $row['confirmed_by_name'] = '';
        if ($userJoin && !empty($row['confirmed_by'])) {
            $u = $conn->prepare("SELECT {$nameCol} FROM {$userJoin} WHERE id = ? LIMIT 1");
            $u->execute([(int) $row['confirmed_by']]);
            $row['confirmed_by_name'] = (string) ($u->fetchColumn() ?: '');
        }
        $row['method_label'] = ucfirst(str_replace('_', ' ', (string) ($row['match_method'] ?? 'match')));
    }
    unset($row);

    return $rows;
}
