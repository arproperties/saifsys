<?php
/**
 * Payroll accounting helpers.
 *
 * Payroll is posted per company and per payroll type so WPS and cash runs never
 * share the same journal.
 */

require_once __DIR__ . '/../../includes/gl_posting.php';
require_once __DIR__ . '/../../modules/realestate/accounting/accounting_engine.php';

function hr_payroll_payment_types(): array
{
    return [
        'wps' => 'WPS (Bank Transfer)',
        'cash' => 'Cash',
    ];
}

function hr_payroll_payment_type_label(?string $type): string
{
    $types = hr_payroll_payment_types();
    return $types[$type ?: 'wps'] ?? ucfirst((string)$type);
}

function hr_payroll_run_type_label(?string $type): string
{
    return ($type ?: 'wps') === 'cash' ? 'Cash Payroll' : 'WPS Payroll';
}

function hr_payroll_company(PDO $conn, int $companyId): array
{
    if ($companyId <= 0) {
        throw new RuntimeException('Payroll company_id is required for accounting posting.');
    }

    $stmt = $conn->prepare("SELECT id, name, business_type, is_active FROM companies WHERE id = ? LIMIT 1");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company || (int)($company['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Payroll company is missing or inactive.');
    }

    return $company;
}

function hr_payroll_accounting_system_for_company(array $company): string
{
    return strtolower((string)($company['business_type'] ?? '')) === 'cleaning'
        ? 'standalone'
        : 'shared';
}

function hr_payroll_table_exists(PDO $conn, string $table): bool
{
    static $cache = [];
    $key = $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function hr_payroll_column_exists(PDO $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = "{$table}.{$column}";
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function hr_payroll_find_account_by_no(PDO $conn, array $accountNos, int $companyId): ?array
{
    if (!$accountNos) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($accountNos), '?'));
    $hasCompany = hr_payroll_column_exists($conn, 'chart_of_accounts', 'company_id');
    $params = $accountNos;

    $companySql = '';
    if ($hasCompany) {
        $companySql = " AND company_id IN (?, 1) ORDER BY CASE WHEN company_id = ? THEN 0 ELSE 1 END, FIELD(account_no, {$placeholders})";
        $params = array_merge($accountNos, [$companyId, $companyId], $accountNos);
    } else {
        $companySql = " ORDER BY FIELD(account_no, {$placeholders})";
        $params = array_merge($accountNos, $accountNos);
    }

    $stmt = $conn->prepare("
        SELECT id, account_no, name, type, normal_balance
        FROM chart_of_accounts
        WHERE is_active = 1 AND is_header = 0 AND account_no IN ({$placeholders})
        {$companySql}
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function hr_payroll_accounting_accounts(PDO $conn, int $companyId, string $payrollType): array
{
    $payrollType = $payrollType === 'cash' ? 'cash' : 'wps';

    if (hr_payroll_table_exists($conn, 'payroll_account_settings')) {
        $stmt = $conn->prepare("
            SELECT
                se.id AS salary_expense_account_id,
                se.account_no AS salary_expense_account_no,
                se.name AS salary_expense_account_name,
                cr.id AS credit_account_id,
                cr.account_no AS credit_account_no,
                cr.name AS credit_account_name
            FROM payroll_account_settings pas
            JOIN chart_of_accounts se ON se.id = pas.salary_expense_account_id AND se.is_active = 1
            JOIN chart_of_accounts cr ON cr.id = pas.credit_account_id AND cr.is_active = 1
            WHERE pas.company_id = ? AND pas.payroll_type = ?
            LIMIT 1
        ");
        $stmt->execute([$companyId, $payrollType]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($settings) {
            return [
                'salary_expense' => [
                    'id' => (int)$settings['salary_expense_account_id'],
                    'account_no' => $settings['salary_expense_account_no'],
                    'name' => $settings['salary_expense_account_name'],
                ],
                'credit' => [
                    'id' => (int)$settings['credit_account_id'],
                    'account_no' => $settings['credit_account_no'],
                    'name' => $settings['credit_account_name'],
                ],
            ];
        }
    }

    $salary = hr_payroll_find_account_by_no($conn, ['5210', '5200', '5010'], $companyId);
    $credit = $payrollType === 'cash'
        ? hr_payroll_find_account_by_no($conn, ['1010', '1050', '1020'], $companyId)
        : hr_payroll_find_account_by_no($conn, ['2130', '1020', '1010'], $companyId);

    if (!$salary) {
        throw new RuntimeException('Payroll salary expense account is missing. Add account 5210 Salaries & Wages.');
    }
    if (!$credit) {
        $label = $payrollType === 'cash' ? 'cash account 1010' : 'WPS payable/bank account 2130 or 1020';
        throw new RuntimeException("Payroll credit account is missing. Add {$label}.");
    }

    return [
        'salary_expense' => $salary,
        'credit' => $credit,
    ];
}

function hr_payroll_shared_find_account_by_code(PDO $conn, array $accountCodes, int $companyId): ?array
{
    if (!$accountCodes) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($accountCodes), '?'));
    $params = array_merge([$companyId], $accountCodes, $accountCodes);

    $stmt = $conn->prepare("
        SELECT id, account_code, account_name, account_type, normal_balance
        FROM re_chart_of_accounts
        WHERE company_id = ?
          AND is_active = 1
          AND COALESCE(is_header, 0) = 0
          AND account_code IN ({$placeholders})
        ORDER BY FIELD(account_code, {$placeholders})
        LIMIT 1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function hr_payroll_shared_accounting_accounts(PDO $conn, int $companyId, string $payrollType): array
{
    $payrollType = $payrollType === 'cash' ? 'cash' : 'wps';

    $salary = hr_payroll_shared_find_account_by_code($conn, ['5290', '5210', '5100'], $companyId);
    $credit = $payrollType === 'cash'
        ? hr_payroll_shared_find_account_by_code($conn, ['1110', '1210'], $companyId)
        : hr_payroll_shared_find_account_by_code($conn, ['2135', '2130', '1210'], $companyId);

    if (!$salary) {
        throw new RuntimeException("Shared accounting salary expense account is missing for company #{$companyId}. Add account 5290 Payroll Salaries.");
    }
    if (!$credit) {
        $label = $payrollType === 'cash' ? 'cash account 1110' : 'WPS payable account 2135';
        throw new RuntimeException("Shared accounting {$label} is missing for company #{$companyId}.");
    }

    return [
        'salary_expense' => $salary,
        'credit' => $credit,
    ];
}

function hr_payroll_existing_journal_id(PDO $conn, int $runId, int $companyId): ?int
{
    $hasCompany = hr_payroll_column_exists($conn, 'gl_journals', 'company_id');
    $sql = "
        SELECT id
        FROM gl_journals
        WHERE source = 'payroll' AND source_id = ? AND is_posted = 1 AND is_reversed = 0
    ";
    $params = [$runId];
    if ($hasCompany) {
        $sql .= " AND company_id = ?";
        $params[] = $companyId;
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

function hr_payroll_existing_shared_journal_id(PDO $conn, int $runId, int $companyId): ?int
{
    $stmt = $conn->prepare("
        SELECT id
        FROM re_journal_headers
        WHERE company_id = ?
          AND reference_type = 'payroll'
          AND reference_id = ?
          AND is_posted = 1
          AND COALESCE(is_reversed, 0) = 0
        LIMIT 1
    ");
    $stmt->execute([$companyId, $runId]);
    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

function hr_payroll_mark_accounting_result(PDO $conn, int $runId, ?int $journalId, ?string $error): void
{
    $sets = [];
    $params = [];

    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_journal_id')) {
        $sets[] = 'accounting_journal_id = ?';
        $params[] = $journalId;
    }
    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_posted_at')) {
        $sets[] = 'accounting_posted_at = ' . ($journalId ? 'NOW()' : 'NULL');
    }
    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_system')) {
        $sets[] = 'accounting_system = NULL';
    }
    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_error')) {
        $sets[] = 'accounting_error = ?';
        $params[] = $error ? substr($error, 0, 500) : null;
    }

    if (!$sets) {
        return;
    }

    $params[] = $runId;
    $stmt = $conn->prepare("UPDATE payroll_runs SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);
}

function hr_payroll_mark_accounting_success(PDO $conn, int $runId, int $journalId, string $accountingSystem): void
{
    $sets = [];
    $params = [];

    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_journal_id')) {
        $sets[] = 'accounting_journal_id = ?';
        $params[] = $journalId;
    }
    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_system')) {
        $sets[] = 'accounting_system = ?';
        $params[] = $accountingSystem;
    }
    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_posted_at')) {
        $sets[] = 'accounting_posted_at = NOW()';
    }
    if (hr_payroll_column_exists($conn, 'payroll_runs', 'accounting_error')) {
        $sets[] = 'accounting_error = NULL';
    }

    if (!$sets) {
        return;
    }

    $params[] = $runId;
    $stmt = $conn->prepare("UPDATE payroll_runs SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);
}

function hr_payroll_validate_run_for_accounting(PDO $conn, array $run, int $companyId, string $payrollType): array
{
    $runId = (int)$run['id'];
    if ($runId <= 0) {
        throw new RuntimeException('Payroll run id is required for accounting posting.');
    }
    if ((int)($run['company_id'] ?? 0) !== $companyId) {
        throw new RuntimeException('Payroll run company does not match accounting company.');
    }

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS item_count,
            COALESCE(SUM(pi.net_pay), 0) AS net_total,
            SUM(CASE WHEN e.company_id <> ? THEN 1 ELSE 0 END) AS company_mismatch_count,
            SUM(CASE WHEN COALESCE(e.payment_type, 'wps') <> ? THEN 1 ELSE 0 END) AS payment_mismatch_count
        FROM payroll_items pi
        JOIN employees e ON e.id = pi.employee_id
        WHERE pi.payroll_run_id = ?
    ");
    $stmt->execute([$companyId, $payrollType, $runId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $itemCount = (int)($totals['item_count'] ?? 0);
    $netTotal = round((float)($totals['net_total'] ?? 0), 2);
    if ($itemCount === 0 || $netTotal <= 0) {
        throw new RuntimeException('Cannot post accounting for an empty or zero payroll run.');
    }
    if ((int)($totals['company_mismatch_count'] ?? 0) > 0) {
        throw new RuntimeException('Payroll contains employees from a different company. Accounting post blocked.');
    }
    if ((int)($totals['payment_mismatch_count'] ?? 0) > 0) {
        throw new RuntimeException('Payroll contains employees from a different payment type. Accounting post blocked.');
    }

    return ['item_count' => $itemCount, 'net_total' => $netTotal];
}

function hr_payroll_post_accounting(PDO $conn, array $run, ?int $createdBy = null): int
{
    $runId = (int)$run['id'];
    $companyId = (int)($run['company_id'] ?? 1);
    $payrollType = (($run['payroll_type'] ?? 'wps') === 'cash') ? 'cash' : 'wps';
    $company = hr_payroll_company($conn, $companyId);
    $accountingSystem = hr_payroll_accounting_system_for_company($company);

    $existing = $accountingSystem === 'standalone'
        ? hr_payroll_existing_journal_id($conn, $runId, $companyId)
        : hr_payroll_existing_shared_journal_id($conn, $runId, $companyId);
    if ($existing) {
        hr_payroll_mark_accounting_success($conn, $runId, $existing, $accountingSystem);
        return $existing;
    }

    $totals = hr_payroll_validate_run_for_accounting($conn, $run, $companyId, $payrollType);
    $netTotal = $totals['net_total'];
    $runLabel = hr_payroll_run_type_label($payrollType);
    $period = $run['period_from'] . ' to ' . $run['period_to'];
    $memo = "{$runLabel} #{$runId} ({$period})";

    if ($accountingSystem === 'shared') {
        $accounts = hr_payroll_shared_accounting_accounts($conn, $companyId, $payrollType);
        $result = create_and_post_journal(
            $companyId,
            'expense',
            'payroll',
            $runId,
            [
                [
                    'account_id' => (int)$accounts['salary_expense']['id'],
                    'description' => "Salary expense - {$memo}",
                    'reference' => "PAYROLL-{$runId}",
                    'debit' => $netTotal,
                    'credit' => 0,
                ],
                [
                    'account_id' => (int)$accounts['credit']['id'],
                    'description' => ($payrollType === 'cash' ? 'Cash payroll payable/payment - ' : 'WPS payroll payable/payment - ') . $memo,
                    'reference' => "PAYROLL-{$runId}",
                    'debit' => 0,
                    'credit' => $netTotal,
                ],
            ],
            $memo,
            $run['period_to'],
            $createdBy
        );

        if (empty($result['success']) || empty($result['journal_id'])) {
            throw new RuntimeException('Shared accounting post failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        $journalId = (int)$result['journal_id'];
        hr_payroll_mark_accounting_success($conn, $runId, $journalId, $accountingSystem);
        return $journalId;
    }

    $accounts = hr_payroll_accounting_accounts($conn, $companyId, $payrollType);
    $journalId = gl_create_journal($conn, [
        'date' => $run['period_to'],
        'source' => 'payroll',
        'source_id' => $runId,
        'company_id' => $companyId,
        'memo' => $memo,
        'created_by' => $createdBy,
    ], [
        [
            'account_id' => (int)$accounts['salary_expense']['id'],
            'desc' => "Salary expense - {$memo}",
            'debit' => $netTotal,
            'credit' => 0,
        ],
        [
            'account_id' => (int)$accounts['credit']['id'],
            'desc' => ($payrollType === 'cash' ? 'Cash payroll payable/payment - ' : 'WPS payroll payable/payment - ') . $memo,
            'debit' => 0,
            'credit' => $netTotal,
        ],
    ]);

    hr_payroll_mark_accounting_success($conn, $runId, $journalId, $accountingSystem);
    return $journalId;
}
