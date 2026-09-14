<?php
/**
 * ARS Account Role Mapping
 * Resolves accounting roles → GL account codes → re_chart_of_accounts rows.
 * Financial Adapter must never hard-code COA IDs.
 */

require_once dirname(__DIR__, 2) . '/realestate/accounting/accounting_engine.php';

/** @return list<string> */
function ars_account_role_codes(): array {
    return [
        'AR_GUEST',
        'ROOM_REVENUE',
        'ADDITIONAL_SERVICE_REVENUE',
        'DAMAGE_REVENUE',
        'VAT_OUTPUT',
        'SECURITY_DEPOSIT',
        'DEFERRED_REVENUE',
        'STRIPE_CLEARING',
        'BANK',
        'CASH',
        'REFUND',
        'BAD_DEBT',
        'DISCOUNT',
        'GUEST_CREDIT',
        'STRIPE_FEE',
        'FORFEIT_REVENUE',
        'LATE_FEE_REVENUE',
    ];
}

/**
 * Default role → account_code when map row missing (mirrors current bridge).
 * @return array<string,string>
 */
function ars_account_role_defaults(): array {
    return [
        'AR_GUEST' => '1310',
        'ROOM_REVENUE' => '4100',
        'ADDITIONAL_SERVICE_REVENUE' => '4100',
        'DAMAGE_REVENUE' => '4100',
        'VAT_OUTPUT' => '2310',
        'SECURITY_DEPOSIT' => '2200',
        'DEFERRED_REVENUE' => '2400',
        'STRIPE_CLEARING' => '1110',
        'BANK' => '1210',
        'CASH' => '1110',
        'REFUND' => '1110',
        'BAD_DEBT' => '4100',
        'DISCOUNT' => '4100',
        'ROUNDING' => '4100',
        'GUEST_CREDIT' => '2210',
        'STRIPE_FEE' => '5510',
        'FORFEIT_REVENUE' => '4900',
        'LATE_FEE_REVENUE' => '4300',
        'STRIPE_CLEARING' => '1130',
        'DAMAGE_REVENUE' => '4200',
        'ADDITIONAL_SERVICE_REVENUE' => '4200',
    ];
}

function ars_account_role_map_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'ars_account_role_map'");
        $ready = (bool) ($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Resolve account_code for a role within a company.
 */
function ars_resolve_account_code(PDO $conn, int $companyId, string $roleCode): ?string {
    if ($companyId <= 0) {
        return null;
    }
    $roleCode = strtoupper(trim($roleCode));
    if ($roleCode === '') {
        return null;
    }

    if (ars_account_role_map_ready($conn)) {
        $stmt = $conn->prepare("
            SELECT account_code FROM ars_account_role_map
            WHERE company_id = ? AND role_code = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$companyId, $roleCode]);
        $code = $stmt->fetchColumn();
        if ($code !== false && $code !== null && $code !== '') {
            return (string) $code;
        }
    }

    $defaults = ars_account_role_defaults();
    return $defaults[$roleCode] ?? null;
}

/**
 * Resolve full COA row for a role. Fail closed if company or account missing.
 *
 * @return array{success:bool,account:?array,account_code:?string,error:?string,code:?string}
 */
function ars_resolve_account_by_role(PDO $conn, int $companyId, string $roleCode): array {
    if ($companyId <= 0) {
        return [
            'success' => false,
            'account' => null,
            'account_code' => null,
            'error' => 'Missing company_id for account role resolution',
            'code' => 'company_mismatch',
        ];
    }

    $accountCode = ars_resolve_account_code($conn, $companyId, $roleCode);
    if ($accountCode === null) {
        return [
            'success' => false,
            'account' => null,
            'account_code' => null,
            'error' => 'Unknown account role: ' . $roleCode,
            'code' => 'missing_coa',
        ];
    }

    $account = find_account_by_code($accountCode, $companyId);
    if (!$account) {
        return [
            'success' => false,
            'account' => null,
            'account_code' => $accountCode,
            'error' => "Missing COA account {$accountCode} for role {$roleCode} (company {$companyId})",
            'code' => 'missing_coa',
        ];
    }

    return [
        'success' => true,
        'account' => $account,
        'account_code' => $accountCode,
        'error' => null,
        'code' => null,
    ];
}

/**
 * Cash vs bank role from payment method.
 */
function ars_cash_or_bank_role(string $method): string {
    return ($method === 'bank_transfer') ? 'BANK' : 'CASH';
}

/**
 * Ensure required roles resolve for a company.
 *
 * @param list<string> $roles
 * @return array{success:bool,error:?string,code:?string,accounts:array<string,array>}
 */
function ars_require_account_roles(PDO $conn, int $companyId, array $roles): array {
    $accounts = [];
    foreach ($roles as $role) {
        $resolved = ars_resolve_account_by_role($conn, $companyId, $role);
        if (!$resolved['success']) {
            return [
                'success' => false,
                'error' => $resolved['error'],
                'code' => $resolved['code'],
                'accounts' => $accounts,
            ];
        }
        $accounts[$role] = $resolved['account'];
    }
    return ['success' => true, 'error' => null, 'code' => null, 'accounts' => $accounts];
}

/**
 * BR-ARS-FIN-001: Real Estate GL company for all ARS journals (ops company stays on booking).
 * Default = company_id 2 when it is realestate. Optional override: ars_company_settings.financial_company_id.
 */
function ars_financial_gl_company_id(PDO $conn, ?int $opsCompanyId = null): int {
    static $cache = [];
    $opsKey = (int) ($opsCompanyId ?? 0);
    if (isset($cache[$opsKey])) {
        return $cache[$opsKey];
    }

    if (function_exists('getArsSettings')) {
        $settings = getArsSettings($conn, $opsCompanyId);
        $configured = (int) ($settings['financial_company_id'] ?? 0);
        if ($configured > 0) {
            $cache[$opsKey] = $configured;
            return $configured;
        }
    }

    try {
        $stmt = $conn->prepare("
            SELECT id FROM companies
            WHERE id = 2 AND is_active = 1 AND COALESCE(business_type, '') = 'realestate'
            LIMIT 1
        ");
        $stmt->execute();
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            $cache[$opsKey] = $id;
            return $id;
        }
    } catch (Throwable $e) {
        // fall through
    }

    try {
        $stmt = $conn->query("
            SELECT id FROM companies
            WHERE is_active = 1 AND COALESCE(business_type, '') = 'realestate'
            ORDER BY id ASC
            LIMIT 1
        ");
        $id = (int) ($stmt ? ($stmt->fetchColumn() ?: 0) : 0);
        if ($id > 0) {
            $cache[$opsKey] = $id;
            return $id;
        }
    } catch (Throwable $e) {
        // fall through
    }

    $cache[$opsKey] = 0;
    return 0;
}

/**
 * Cash receipt picker: active leaf accounts under Cash parent (1100), excluding Stripe clearing (1140).
 * Bank receipt picker: active leaf accounts under Bank Accounts parent (1200).
 * New RE cash/bank COA rows appear automatically — no hardcoded 1250 ceiling.
 *
 * @return list<string> codes only (for validation helpers / docs)
 */
function ars_receipt_cash_account_codes(): array {
    // Legacy default codes (docs / fallback). Live picker uses COA parent 1100.
    return ['1110', '1120', '1130', '1150'];
}

/** @return list<string> */
function ars_receipt_bank_account_codes(): array {
    // Legacy defaults. Live picker uses COA parent 1200 (includes 1260+).
    return ['1210', '1220', '1230', '1240', '1250', '1260'];
}

/**
 * Methods whose receipt picker lists bank accounts (card / online settle into a bank).
 */
function ars_receipt_method_uses_bank(string $method): bool {
    return in_array($method, ['bank_transfer', 'card', 'online'], true);
}

/**
 * Parent COA code for receipt pickers on the RE GL company.
 */
function ars_receipt_parent_code_for_method(string $method): string {
    return ars_receipt_method_uses_bank($method) ? '1200' : '1100';
}

/**
 * Codes never offered as cash/bank receipt accounts (clearing / control).
 *
 * @return list<string>
 */
function ars_receipt_excluded_account_codes(): array {
    return ['1140']; // HH Stripe Clearing — not a desk cash/bank receipt account
}

/**
 * Allow-list for method: bank_transfer / card / online → banks; otherwise cash.
 * Prefer ars_receipt_account_options() / ars_resolve_receipt_account() which read live COA.
 *
 * @return list<string>
 */
function ars_receipt_allowlist_for_method(string $method): array {
    return ars_receipt_method_uses_bank($method)
        ? ars_receipt_bank_account_codes()
        : ars_receipt_cash_account_codes();
}

/**
 * Load active COA rows for cash or bank picker (GL company).
 * Banks = children of 1200; cash = children of 1100 (excludes Stripe clearing 1140).
 *
 * @return list<array{account_code:string,account_name:string,id:int}>
 */
function ars_receipt_account_options(PDO $conn, int $glCompanyId, string $method): array {
    if ($glCompanyId <= 0) {
        return [];
    }
    $parentCode = ars_receipt_parent_code_for_method($method);
    $excluded = ars_receipt_excluded_account_codes();
    $placeholders = $excluded !== [] ? implode(',', array_fill(0, count($excluded), '?')) : '';
    $excludeSql = $placeholders !== '' ? " AND c.account_code NOT IN ($placeholders)" : '';

    try {
        $stmt = $conn->prepare("
            SELECT c.id, c.account_code, c.account_name
            FROM re_chart_of_accounts c
            INNER JOIN re_chart_of_accounts p ON p.id = c.parent_id AND p.company_id = c.company_id
            WHERE c.company_id = ?
              AND c.is_active = 1
              AND COALESCE(c.is_header, 0) = 0
              AND p.account_code = ?
              {$excludeSql}
            ORDER BY c.account_code
        ");
        $params = array_merge([$glCompanyId, $parentCode], $excluded);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('ARS receipt account options: ' . $e->getMessage());
        $rows = [];
    }

    // Fallback if parent_id linkage missing on older COA rows: code-range filter.
    if ($rows === []) {
        if (ars_receipt_method_uses_bank($method)) {
            $rangeSql = "AND c.account_code REGEXP '^[0-9]+$' AND CAST(c.account_code AS UNSIGNED) BETWEEN 1210 AND 1299";
        } else {
            $rangeSql = "AND c.account_code REGEXP '^[0-9]+$' AND CAST(c.account_code AS UNSIGNED) BETWEEN 1110 AND 1199";
        }
        try {
            $stmt = $conn->prepare("
                SELECT c.id, c.account_code, c.account_name
                FROM re_chart_of_accounts c
                WHERE c.company_id = ?
                  AND c.is_active = 1
                  AND COALESCE(c.is_header, 0) = 0
                  {$rangeSql}
                  {$excludeSql}
                ORDER BY c.account_code
            ");
            $stmt->execute(array_merge([$glCompanyId], $excluded));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('ARS receipt account options fallback: ' . $e->getMessage());
            $rows = [];
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'account_code' => (string) $row['account_code'],
            'account_name' => (string) $row['account_name'],
        ];
    }
    return $out;
}

/**
 * Validate operator-selected receipt account against live COA picker set + GL company.
 *
 * @return array{success:bool,account:?array,account_code:?string,error:?string,code:?string}
 */
function ars_resolve_receipt_account(
    PDO $conn,
    int $glCompanyId,
    string $method,
    ?string $accountCode
): array {
    if ($glCompanyId <= 0) {
        return [
            'success' => false,
            'account' => null,
            'account_code' => null,
            'error' => 'Missing financial company for receipt account',
            'code' => 'company_mismatch',
        ];
    }

    $code = trim((string) $accountCode);
    if ($code === '') {
        return [
            'success' => false,
            'account' => null,
            'account_code' => null,
            'error' => 'Select the cash or bank GL account for this transaction.',
            'code' => 'validation_failed',
        ];
    }

    $options = ars_receipt_account_options($conn, $glCompanyId, $method);
    $allowedCodes = array_column($options, 'account_code');
    if (!in_array($code, $allowedCodes, true)) {
        $kind = ars_receipt_method_uses_bank($method) ? 'bank' : 'cash';
        return [
            'success' => false,
            'account' => null,
            'account_code' => $code,
            'error' => "Account {$code} is not allowed for {$kind} receipts. Add it under RE Bank Accounts (1200) or Cash (1100) and keep it Active.",
            'code' => 'validation_failed',
        ];
    }

    $account = find_account_by_code($code, $glCompanyId);
    if (!$account || empty($account['is_active'])) {
        return [
            'success' => false,
            'account' => null,
            'account_code' => $code,
            'error' => "COA account {$code} not found or inactive on financial company {$glCompanyId}",
            'code' => 'missing_coa',
        ];
    }

    return [
        'success' => true,
        'account' => $account,
        'account_code' => $code,
        'error' => null,
        'code' => null,
    ];
}

/** Ensure receipt_account_code columns exist (idempotent). */
function ars_ensure_receipt_account_columns(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $chk = $conn->query("SHOW COLUMNS FROM ars_booking_payments LIKE 'receipt_account_code'");
        if (!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE ars_booking_payments ADD COLUMN receipt_account_code VARCHAR(32) NULL DEFAULT NULL AFTER payment_method");
        }
    } catch (Throwable $e) {
        error_log('ARS receipt_account_code on payments: ' . $e->getMessage());
    }
    try {
        $chk = $conn->query("SHOW COLUMNS FROM ars_security_deposits LIKE 'receipt_account_code'");
        if (!$chk || !$chk->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE ars_security_deposits ADD COLUMN receipt_account_code VARCHAR(32) NULL DEFAULT NULL AFTER amount");
        }
    } catch (Throwable $e) {
        error_log('ARS receipt_account_code on deposits: ' . $e->getMessage());
    }
}
