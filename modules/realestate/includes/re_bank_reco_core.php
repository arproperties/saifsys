<?php
/**
 * Real Estate bank reconciliation — core helpers (permissions, import, workbench).
 */
declare(strict_types=1);

require_once __DIR__ . '/bank_reconciliation_helper.php';

if (!function_exists('re_db_table_exists')) {
    function re_db_table_exists(PDO $conn, string $table): bool
    {
        try {
            $conn->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('re_db_column_exists')) {
    function re_db_column_exists(PDO $conn, string $table, string $column): bool
    {
        try {
            $st = $conn->prepare("
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                LIMIT 1
            ");
            $st->execute([$table, $column]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('re_bank_reco_tables_ready')) {
    function re_bank_reco_tables_ready(PDO $conn): bool
    {
        return re_bank_rec_schema_ready($conn);
    }
}

if (!function_exists('re_bank_reco_has_permission')) {
    function re_bank_reco_has_permission(PDO $conn, string $permissionKey): bool
    {
        require_once dirname(__DIR__, 3) . '/includes/permissions.php';
        require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';

        if (has_permission($permissionKey, MODULE_REALESTATE, $conn)) {
            return true;
        }

        return has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
    }
}

if (!function_exists('re_bank_reco_require_permission')) {
    function re_bank_reco_require_permission(PDO $conn, string $permissionKey): void
    {
        if (!re_bank_reco_has_permission($conn, $permissionKey)) {
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
}

if (!function_exists('re_bank_verify_account')) {
    function re_bank_verify_account(PDO $conn, int $bankAccountId, int $companyId): ?array
    {
        return re_bank_rec_bank_account($conn, $companyId, $bankAccountId);
    }
}

if (!function_exists('re_bank_line_remaining')) {
    function re_bank_line_remaining(PDO $conn, array $line): float
    {
        $lineId = (int) ($line['id'] ?? 0);
        $companyId = (int) ($line['company_id'] ?? 0);
        $target = abs((float) ($line['net_amount'] ?? $line['amount'] ?? 0));
        if ($lineId <= 0) {
            return re_bank_rec_money($target);
        }
        $st = $conn->prepare("
            SELECT COALESCE(SUM(matched_amount), 0)
            FROM re_bank_reconciliation_matches
            WHERE company_id = ? AND statement_line_id = ? AND status = 'confirmed'
        ");
        $st->execute([$companyId, $lineId]);
        $matched = abs((float) $st->fetchColumn());
        return re_bank_rec_money(max(0, $target - $matched));
    }
}

if (!function_exists('re_bank_get_statement_line')) {
    function re_bank_get_statement_line(PDO $conn, int $lineId, int $companyId): ?array
    {
        $st = $conn->prepare('
            SELECT l.*, b.gl_account_id, coa.account_code, coa.account_name AS coa_name, b.account_name AS bank_name
            FROM re_bank_statement_lines l
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
        $row['amount'] = (float) $row['net_amount'];
        $row['txn_date'] = (string) $row['statement_date'];
        $row['remaining'] = re_bank_line_remaining($conn, $row);
        $row['is_spent'] = (float) $row['net_amount'] < 0;
        $row['is_received'] = (float) $row['net_amount'] > 0;
        $row['abs_amount'] = round(abs((float) $row['net_amount']), 2);
        return $row;
    }
}

if (!function_exists('re_bank_erp_balance')) {
    function re_bank_erp_balance(PDO $conn, int $glAccountId, int $companyId, string $asOfDate): float
    {
        $st = $conn->prepare('
            SELECT COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0)
            FROM re_general_ledger
            WHERE account_id = ? AND company_id = ? AND entry_date <= ?
        ');
        $st->execute([$glAccountId, $companyId, $asOfDate]);
        return re_bank_rec_money((float) $st->fetchColumn());
    }
}

if (!function_exists('re_bank_statement_balance')) {
    function re_bank_statement_balance(PDO $conn, int $bankAccountId, int $companyId, string $asOfDate): ?float
    {
        $st = $conn->prepare('
            SELECT running_balance
            FROM re_bank_statement_lines
            WHERE bank_account_id = ? AND company_id = ? AND statement_date <= ?
            ORDER BY statement_date DESC, id DESC
            LIMIT 1
        ');
        $st->execute([$bankAccountId, $companyId, $asOfDate]);
        $val = $st->fetchColumn();
        if ($val === false || $val === null) {
            return null;
        }
        return re_bank_rec_money((float) $val);
    }
}

if (!function_exists('re_bank_reco_contacts')) {
    /** @return array{tenants:list<array{id:int,name:string}>,vendors:list<array{id:int,name:string}>} */
    function re_bank_reco_contacts(PDO $conn, int $companyId): array
    {
        $out = ['tenants' => [], 'vendors' => []];
        try {
            $st = $conn->prepare("
                SELECT id, COALESCE(NULLIF(company_name,''), CONCAT(first_name,' ',last_name)) AS name
                FROM re_tenants WHERE company_id = ? ORDER BY name
            ");
            $st->execute([$companyId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out['tenants'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
            }
        } catch (Throwable $e) {
        }
        try {
            $st = $conn->prepare('SELECT id, vendor_name AS name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name');
            $st->execute([$companyId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out['vendors'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}

if (!function_exists('re_bank_normalize_import_date')) {
    function re_bank_normalize_import_date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('re_bank_import_header_map')) {
    function re_bank_import_header_map(array $header): ?array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            $key = strtolower(trim((string) $name));
            $key = str_replace([' ', '-', '_'], '', $key);
            if (in_array($key, ['date', 'transactiondate', 'statementdate', 'postingdate'], true)) {
                $map['date'] = (int) $idx;
            }
            if (in_array($key, ['valuedate'], true)) {
                $map['value_date'] = (int) $idx;
            }
            if (in_array($key, ['description', 'narration', 'details', 'transactiondetails'], true)) {
                $map['description'] = (int) $idx;
            }
            if (in_array($key, ['reference', 'ref', 'chequenumber', 'chequeno', 'transactionref'], true)) {
                $map['reference'] = (int) $idx;
            }
            if (in_array($key, ['amount', 'netamount'], true)) {
                $map['amount'] = (int) $idx;
            }
            if (in_array($key, ['debit', 'withdrawal', 'withdrawals'], true)) {
                $map['debit'] = (int) $idx;
            }
            if (in_array($key, ['credit', 'deposit', 'deposits'], true)) {
                $map['credit'] = (int) $idx;
            }
            if (in_array($key, ['balance', 'runningbalance'], true)) {
                $map['running_balance'] = (int) $idx;
            }
        }
        if (!isset($map['date']) || !isset($map['description'])) {
            return null;
        }
        if (!isset($map['amount']) && !isset($map['debit']) && !isset($map['credit'])) {
            return null;
        }
        return $map;
    }
}

if (!function_exists('re_bank_resolve_import_columns')) {
    function re_bank_resolve_import_columns(array $headers): ?array
    {
        return re_bank_import_header_map($headers);
    }
}

if (!function_exists('re_bank_clean_import_amount')) {
    function re_bank_clean_import_amount(string $value): float
    {
        return (float) str_replace([',', 'AED', ' '], '', $value);
    }
}

if (!function_exists('re_bank_build_import_row_from_cells')) {
    function re_bank_build_import_row_from_cells(array $cells, array $colMap, int $lineNo): ?array
    {
        $get = static function (string $field) use ($cells, $colMap): string {
            if (!isset($colMap[$field])) {
                return '';
            }
            return trim((string) ($cells[$colMap[$field]] ?? ''));
        };

        $date = re_bank_normalize_import_date($get('date'));
        if (!$date) {
            return null;
        }
        $description = $get('description');
        if ($description === '') {
            return null;
        }

        $debit = 0.0;
        $credit = 0.0;
        $net = 0.0;
        if (isset($colMap['amount'])) {
            $net = re_bank_clean_import_amount($get('amount'));
            if ($net >= 0) {
                $credit = $net;
            } else {
                $debit = abs($net);
            }
        } else {
            $debit = re_bank_clean_import_amount($get('debit'));
            $credit = re_bank_clean_import_amount($get('credit'));
            $net = $credit - $debit;
        }
        if (abs($net) < 0.0001 && $debit < 0.0001 && $credit < 0.0001) {
            return null;
        }

        $row = [
            'statement_date' => $date,
            'value_date' => ($v = re_bank_normalize_import_date($get('value_date'))) ? $v : null,
            'description' => $description,
            'reference' => ($ref = $get('reference')) !== '' ? $ref : null,
            'debit_amount' => re_bank_rec_money($debit),
            'credit_amount' => re_bank_rec_money($credit),
            'net_amount' => re_bank_rec_money($net),
            '_line' => $lineNo,
        ];
        if (isset($colMap['running_balance'])) {
            $bal = $get('running_balance');
            if ($bal !== '') {
                $row['running_balance'] = re_bank_rec_money(re_bank_clean_import_amount($bal));
            }
        }
        return $row;
    }
}

if (!function_exists('re_bank_parse_statement_csv')) {
    function re_bank_parse_statement_csv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return ['rows' => [], 'error' => 'Could not read CSV file'];
        }
        $header = null;
        $colMap = null;
        $rows = [];
        $lineNo = 0;
        while (($data = fgetcsv($fh)) !== false) {
            $lineNo++;
            if (!$header) {
                $header = $data;
                $colMap = re_bank_import_header_map($header);
                if (!$colMap) {
                    fclose($fh);
                    return ['rows' => [], 'error' => 'Could not find required columns. Use: date, description, reference, Debit, Credit (or amount).'];
                }
                continue;
            }
            $cells = [];
            foreach ($colMap as $field => $idx) {
                $cells[$idx] = (string) ($data[$idx] ?? '');
            }
            $built = re_bank_build_import_row_from_cells($cells, $colMap, $lineNo);
            if ($built) {
                $rows[] = $built;
            }
        }
        fclose($fh);
        return ['rows' => $rows, 'error' => null];
    }
}

if (!function_exists('re_bank_parse_statement_upload')) {
    function re_bank_parse_statement_upload(string $path, string $fileName): array
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return re_bank_parse_statement_csv($path);
        }
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            require_once __DIR__ . '/re_bank_reco_xlsx.php';
            return re_bank_parse_statement_xlsx_native($path);
        }
        return ['rows' => [], 'error' => 'Unsupported file type. Upload CSV or XLSX.'];
    }
}

if (!function_exists('re_bank_rec_import_preview')) {
    function re_bank_rec_import_preview(PDO $conn, int $companyId, int $bankAccountId, array $parsedRows, string $fileName): array
    {
        $rows = [];
        $errors = [];
        $duplicates = 0;
        $totalDebits = 0.0;
        $totalCredits = 0.0;
        $closing = null;

        foreach ($parsedRows as $raw) {
            $lineNo = (int) ($raw['_line'] ?? 0);
            $date = (string) ($raw['statement_date'] ?? '');
            if ($date === '') {
                $errors[] = ['line' => $lineNo, 'reason' => 'Missing date'];
                continue;
            }
            if (re_bank_rec_period_locked($conn, $companyId, $bankAccountId, $date)) {
                $errors[] = ['line' => $lineNo, 'reason' => 'Period locked: ' . $date];
                continue;
            }
            $net = re_bank_rec_money($raw['net_amount'] ?? 0);
            $ref = trim((string) ($raw['reference'] ?? ''));
            $desc = trim((string) ($raw['description'] ?? ''));
            $hash = re_bank_rec_hash($bankAccountId, $date, $net, $ref, $desc);

            $dup = $conn->prepare('SELECT 1 FROM re_bank_statement_lines WHERE company_id = ? AND bank_account_id = ? AND source_hash = ? LIMIT 1');
            $dup->execute([$companyId, $bankAccountId, $hash]);
            $isDup = (bool) $dup->fetchColumn();
            if ($isDup) {
                $duplicates++;
            }

            $debit = re_bank_rec_money($raw['debit_amount'] ?? ($net < 0 ? abs($net) : 0));
            $credit = re_bank_rec_money($raw['credit_amount'] ?? ($net > 0 ? $net : 0));
            $totalDebits += $debit;
            $totalCredits += $credit;
            if (isset($raw['running_balance']) && $raw['running_balance'] !== '') {
                $closing = (float) $raw['running_balance'];
            }

            $rows[] = array_merge($raw, [
                'txn_date' => $date,
                'amount' => $net,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                '_duplicate' => $isDup,
                'source_hash' => $hash,
            ]);
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($parsedRows),
                'valid_rows' => count($rows) - $duplicates,
                'duplicate_rows' => $duplicates,
                'error_rows' => count($errors),
                'errors' => array_slice($errors, 0, 10),
                'total_debits' => re_bank_rec_money($totalDebits),
                'total_credits' => re_bank_rec_money($totalCredits),
                'closing_balance' => $closing,
                'file_name' => $fileName,
            ],
        ];
    }
}

if (!function_exists('re_bank_workbench_format_suggestion')) {
    function re_bank_workbench_format_suggestion(array $s): array
    {
        $sourceTable = (string) ($s['source_table'] ?? '');
        $sourceId = (int) ($s['source_id'] ?? 0);
        $glLineId = (int) ($s['gl_line_id'] ?? 0);
        return [
            'system_type' => $sourceTable,
            'system_id' => $sourceTable === 're_general_ledger' ? $glLineId : $sourceId,
            'match_type' => (string) ($s['match_type'] ?? 'payment'),
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'gl_line_id' => $glLineId ?: null,
            'label' => (string) ($s['label'] ?? ''),
            'score' => (float) ($s['score'] ?? 0),
            'confidence' => (string) ($s['confidence'] ?? 'Low'),
            'amount' => (float) ($s['amount'] ?? 0),
            'txn_date' => (string) ($s['txn_date'] ?? ''),
            'reference' => (string) ($s['reference'] ?? ''),
            'party_name' => (string) ($s['party_name'] ?? ''),
            'reasons' => array_values(array_filter(array_map('strval', (array) ($s['reasons'] ?? [])))),
            'name_matched' => !empty($s['name_matched']),
        ];
    }
}

if (!function_exists('re_bank_reco_gl_candidates_for_line')) {
    function re_bank_reco_gl_candidates_for_line(PDO $conn, int $companyId, array $line, int $limit = 50): array
    {
        if (!function_exists('re_bank_reco_find_transactions')) {
            require_once __DIR__ . '/re_bank_reco_engine.php';
        }
        $date = (string) ($line['statement_date'] ?? $line['txn_date'] ?? '');
        $abs = abs((float) ($line['net_amount'] ?? $line['amount'] ?? 0));
        return array_slice(re_bank_reco_find_transactions($conn, $companyId, $line, [
            'date_from' => date('Y-m-d', strtotime($date . ' -30 days')),
            'date_to' => date('Y-m-d', strtotime($date . ' +30 days')),
            'amount' => $abs > 0 ? (string) $abs : '',
            'unreconciled_only' => true,
        ]), 0, $limit);
    }
}

if (!function_exists('re_bank_reco_suggestions_for_workbench')) {
    function re_bank_reco_suggestions_for_workbench(PDO $conn, int $companyId, int $statementLineId): array
    {
        $raw = re_bank_rec_suggestions($conn, $companyId, $statementLineId);
        return array_map('re_bank_workbench_format_suggestion', $raw);
    }
}

if (!function_exists('re_bank_reco_line_display_status')) {
    function re_bank_reco_line_display_status(PDO $conn, array $line): string
    {
        $remaining = re_bank_line_remaining($conn, $line);
        if ($remaining <= 0.009 && abs((float) ($line['net_amount'] ?? 0)) > 0) {
            return 'matched';
        }
        $status = (string) ($line['status'] ?? 'unmatched');
        return match ($status) {
            'partially_matched' => 'partially_matched',
            'investigating' => 'investigating',
            'ignored' => 'ignored',
            default => 'unmatched',
        };
    }
}

if (!function_exists('re_gl_entry_amount')) {
    function re_gl_entry_amount(array $gl): float
    {
        $debit = round((float) ($gl['debit_amount'] ?? 0), 2);
        $credit = round((float) ($gl['credit_amount'] ?? 0), 2);
        return $debit > 0 ? $debit : $credit;
    }
}

if (!function_exists('re_gl_entry_matched_sum')) {
    function re_gl_entry_matched_sum(PDO $conn, int $glLineId, ?string $statusFilter = null): float
    {
        $sql = "
            SELECT COALESCE(SUM(matched_amount), 0)
            FROM re_bank_reconciliation_matches
            WHERE gl_line_id = ? AND status IN ('suggested','confirmed')
        ";
        if ($statusFilter === 'confirmed') {
            $sql = "
                SELECT COALESCE(SUM(matched_amount), 0)
                FROM re_bank_reconciliation_matches
                WHERE gl_line_id = ? AND status = 'confirmed'
            ";
        }
        $st = $conn->prepare($sql);
        $st->execute([$glLineId]);
        return re_bank_rec_money((float) $st->fetchColumn());
    }
}

if (!function_exists('re_gl_entry_remaining')) {
    function re_gl_entry_remaining(PDO $conn, int $glLineId, int $companyId): float
    {
        $st = $conn->prepare('SELECT debit_amount, credit_amount FROM re_general_ledger WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$glLineId, $companyId]);
        $gl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$gl) {
            return 0.0;
        }
        $entryAmt = re_gl_entry_amount($gl);
        $matched = re_gl_entry_matched_sum($conn, $glLineId, 'confirmed');
        return re_bank_rec_money(max(0, $entryAmt - $matched));
    }
}

if (!function_exists('re_bank_sync_gl_reconciled_flag')) {
    function re_bank_sync_gl_reconciled_flag(PDO $conn, int $glLineId, int $companyId, ?int $userId = null): void
    {
        if (!re_db_column_exists($conn, 're_general_ledger', 'is_reconciled')) {
            return;
        }
        $st = $conn->prepare('SELECT debit_amount, credit_amount FROM re_general_ledger WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$glLineId, $companyId]);
        $gl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$gl) {
            return;
        }
        $confirmed = re_gl_entry_matched_sum($conn, $glLineId, 'confirmed');
        $entryAmt = re_gl_entry_amount($gl);
        if ($confirmed >= $entryAmt - 0.009) {
            $conn->prepare('UPDATE re_general_ledger SET is_reconciled = 1, reconciled_at = NOW(), reconciled_by = ? WHERE id = ? AND company_id = ?')
                ->execute([$userId ?: null, $glLineId, $companyId]);
        } else {
            $conn->prepare('UPDATE re_general_ledger SET is_reconciled = 0, reconciled_at = NULL, reconciled_by = NULL WHERE id = ? AND company_id = ?')
                ->execute([$glLineId, $companyId]);
        }
    }
}

if (!function_exists('re_bank_reco_confidence_label')) {
    function re_bank_reco_confidence_label(float $score): string
    {
        if ($score >= 90) {
            return 'High';
        }
        if ($score >= 70) {
            return 'Medium';
        }
        return 'Low';
    }
}

if (!function_exists('re_bank_reco_void_match_row')) {
    function re_bank_reco_void_match_row(PDO $conn, int $matchId, int $companyId, ?int $userId): int
    {
        $st = $conn->prepare('
            SELECT m.* FROM re_bank_reconciliation_matches m
            WHERE m.id = ? AND m.company_id = ? AND m.status IN (\'suggested\',\'confirmed\')
        ');
        $st->execute([$matchId, $companyId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            return 0;
        }
        $conn->prepare("UPDATE re_bank_reconciliation_matches SET status = 'void' WHERE id = ? AND company_id = ?")
            ->execute([$matchId, $companyId]);
        if (!empty($m['gl_line_id'])) {
            re_bank_sync_gl_reconciled_flag($conn, (int) $m['gl_line_id'], $companyId, $userId);
        }
        re_bank_rec_refresh_line_status($conn, $companyId, (int) $m['statement_line_id']);
        return $matchId;
    }
}

if (!function_exists('re_bank_reco_undo_match')) {
    /** @return array{success:bool,reversed:bool,voided_match_ids:list<int>,error:?string} */
    function re_bank_reco_undo_match(PDO $conn, int $companyId, int $matchId, ?int $userId, bool $reverseCreated = true): array
    {
        $st = $conn->prepare('
            SELECT m.*, l.statement_date, l.bank_account_id, l.id AS statement_line_id
            FROM re_bank_reconciliation_matches m
            INNER JOIN re_bank_statement_lines l ON l.id = m.statement_line_id
            WHERE m.id = ? AND m.company_id = ? AND m.status IN (\'suggested\',\'confirmed\')
        ');
        $st->execute([$matchId, $companyId]);
        $match = $st->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            return ['success' => false, 'reversed' => false, 'voided_match_ids' => [], 'error' => 'Match not found or already void'];
        }

        if (re_bank_rec_period_locked($conn, $companyId, (int) $match['bank_account_id'], (string) $match['statement_date'])) {
            if (!re_bank_reco_has_permission($conn, 'realestate.bank_reconciliation.admin_override')) {
                return ['success' => false, 'reversed' => false, 'voided_match_ids' => [], 'error' => 'Period locked — admin override required'];
            }
        }

        $journalId = (int) ($match['created_transaction_id'] ?? 0);
        $method = (string) ($match['match_method'] ?? 'match');
        $needsReverse = $reverseCreated && $journalId > 0 && in_array($method, ['create', 'adjustment', 'transfer'], true);

        try {
            $conn->beginTransaction();
            $voided = [];
            $reversed = false;

            if ($needsReverse) {
                require_once __DIR__ . '/../accounting/accounting_engine.php';
                $rev = reverse_journal($journalId, 'Bank reconciliation undo match #' . $matchId, $userId);
                if (empty($rev['success'])) {
                    throw new RuntimeException($rev['error'] ?? 'Could not reverse journal');
                }
                $reversed = true;

                if ($method === 'transfer') {
                    $stPair = $conn->prepare("
                        SELECT id FROM re_bank_reconciliation_matches
                        WHERE created_transaction_id = ? AND company_id = ? AND status IN ('suggested','confirmed') AND id <> ?
                    ");
                    $stPair->execute([$journalId, $companyId, $matchId]);
                    foreach ($stPair->fetchAll(PDO::FETCH_COLUMN) as $pairId) {
                        $voided[] = re_bank_reco_void_match_row($conn, (int) $pairId, $companyId, $userId);
                    }
                }
            }

            $voided[] = re_bank_reco_void_match_row($conn, $matchId, $companyId, $userId);
            $voided = array_values(array_filter(array_unique($voided)));

            re_bank_rec_audit($conn, $companyId, (int) $match['bank_account_id'], (int) $match['statement_line_id'], $matchId, 'undo_match', (string) $match['status'], 'void', $userId, $reversed ? 'reversed' : '');

            $conn->commit();
            return ['success' => true, 'reversed' => $reversed, 'voided_match_ids' => $voided, 'error' => null];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'reversed' => false, 'voided_match_ids' => [], 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_bank_reco_history')) {
    /** @return list<array<string,mixed>> */
    function re_bank_reco_history(PDO $conn, int $companyId, ?int $bankAccountId = null, string $dateFrom = '', string $dateTo = '', int $limit = 200): array
    {
        $sql = "
            SELECT m.*, l.statement_date AS txn_date, l.description AS line_description, l.reference AS line_reference,
                   l.net_amount AS line_amount, b.account_name AS bank_name, coa.account_code,
                   gl.description AS gl_description, gl.reference AS gl_reference, jh.journal_number, jh.reference_type
            FROM re_bank_reconciliation_matches m
            INNER JOIN re_bank_statement_lines l ON l.id = m.statement_line_id
            INNER JOIN re_bank_accounts b ON b.id = l.bank_account_id AND b.company_id = l.company_id
            INNER JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
            LEFT JOIN re_general_ledger gl ON gl.id = m.gl_line_id
            LEFT JOIN re_journal_headers jh ON jh.id = gl.journal_id
            WHERE l.company_id = ? AND m.status = 'confirmed'
        ";
        $params = [$companyId];
        if ($bankAccountId) {
            $sql .= ' AND l.bank_account_id = ?';
            $params[] = $bankAccountId;
        }
        if ($dateFrom !== '') {
            $sql .= ' AND COALESCE(m.matched_at, l.statement_date) >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $sql .= ' AND COALESCE(m.matched_at, l.statement_date) <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $sql .= ' ORDER BY m.matched_at DESC, m.id DESC LIMIT ' . max(1, min(500, $limit));

        $st = $conn->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $userJoin = re_db_table_exists($conn, 'user') ? 'user' : (re_db_table_exists($conn, 'users') ? 'users' : null);
        $nameCol = 'username';
        if ($userJoin === 'user') {
            $nameCol = re_db_column_exists($conn, 'user', 'fullname') ? 'fullname' : 'username';
        } elseif ($userJoin === 'users' && re_db_column_exists($conn, 'users', 'full_name')) {
            $nameCol = 'full_name';
        }

        foreach ($rows as &$row) {
            $row['confirmed_at'] = $row['matched_at'] ?? null;
            $row['amount_matched'] = $row['matched_amount'] ?? 0;
            $row['confirmed_by_name'] = '';
            if ($userJoin && !empty($row['matched_by'])) {
                $u = $conn->prepare("SELECT {$nameCol} FROM {$userJoin} WHERE id = ? LIMIT 1");
                $u->execute([(int) $row['matched_by']]);
                $row['confirmed_by_name'] = (string) ($u->fetchColumn() ?: '');
            }
            $row['method_label'] = ucfirst(str_replace('_', ' ', (string) ($row['match_method'] ?? 'match')));
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('re_bank_reco_vat_config')) {
    /** @return array{default_rate:float,input_vat_account_id:?int,output_vat_account_id:?int} */
    function re_bank_reco_vat_config(PDO $conn, int $companyId): array
    {
        require_once __DIR__ . '/../accounting/accounting_engine.php';
        $defaultRate = 5.0;
        $inputVatId = null;
        $outputVatId = null;
        try {
            $st = $conn->prepare('
                SELECT vat_rate, input_vat_account_id, output_vat_account_id
                FROM re_vat_config
                WHERE company_id = ? AND is_active = 1
                ORDER BY effective_from DESC
                LIMIT 1
            ');
            $st->execute([$companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $defaultRate = (float) ($row['vat_rate'] ?? 5);
                $inputVatId = !empty($row['input_vat_account_id']) ? (int) $row['input_vat_account_id'] : null;
                $outputVatId = !empty($row['output_vat_account_id']) ? (int) $row['output_vat_account_id'] : null;
            }
        } catch (Throwable $e) {
        }
        if (!$inputVatId) {
            $a = find_account_by_code('2320', $companyId);
            $inputVatId = $a ? (int) $a['id'] : null;
        }
        if (!$outputVatId) {
            $a = find_account_by_code('2310', $companyId);
            $outputVatId = $a ? (int) $a['id'] : null;
        }
        return [
            'default_rate' => $defaultRate,
            'input_vat_account_id' => $inputVatId,
            'output_vat_account_id' => $outputVatId,
        ];
    }
}

if (!function_exists('re_bank_reco_vat_split')) {
    /** @return array{net:float,vat:float,gross:float} */
    function re_bank_reco_vat_split(float $grossAmount, string $vatTreatment, float $vatRate): array
    {
        $grossAmount = re_bank_rec_money(abs($grossAmount));
        if ($vatTreatment !== 'standard' || $vatRate <= 0) {
            return ['net' => $grossAmount, 'vat' => 0.0, 'gross' => $grossAmount];
        }
        $vat = re_bank_rec_money($grossAmount * $vatRate / (100 + $vatRate));
        $net = re_bank_rec_money($grossAmount - $vat);
        return ['net' => $net, 'vat' => $vat, 'gross' => $grossAmount];
    }
}

if (!function_exists('re_bank_reco_build_create_journal_lines')) {
    /**
     * Build journal lines for bank reco Create (bank amount is VAT-inclusive when standard VAT applies).
     *
     * @return list<array<string,mixed>>
     */
    function re_bank_reco_build_create_journal_lines(
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
        $split = re_bank_reco_vat_split($amount, $vatTreatment, $vatRate);
        $vatAccountId = $isSpent ? $inputVatAccountId : $outputVatAccountId;

        if ($split['vat'] > 0.009) {
            if (!$vatAccountId) {
                throw new RuntimeException('VAT accounts not configured. Set up VAT in Accounting → VAT Configuration.');
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
}
