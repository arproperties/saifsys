<?php
/**
 * Real Estate income account roles → Chart of Accounts resolution.
 *
 * Business logic (invoice engine / posting) works with roles only.
 * Default COA codes live here as seed defaults. A future configurable
 * mapping table can replace seed lookup inside re_income_role_account_code()
 * without changing callers.
 *
 * Do not hardcode GL account numbers in post_invoice_to_accounting or the invoice engine.
 */
declare(strict_types=1);

require_once __DIR__ . '/lease_vat_calculator.php';

/** @return list<string> */
function re_income_account_role_codes(): array
{
    return [
        'RESIDENTIAL_RENT_INCOME',
        'COMMERCIAL_RENT_INCOME',
        'SERVICE_CHARGE_INCOME',
        'PENALTY_INCOME',
        'OTHER_INCOME',
    ];
}

/**
 * Seed defaults only — mirrors standard RE COA from seed_real_estate_chart_of_accounts.
 * Future configurable mapping replaces this seam in re_income_role_account_code().
 *
 * @return array<string,string> role => account_code
 */
function re_income_account_role_seed_defaults(): array
{
    return [
        'RESIDENTIAL_RENT_INCOME' => '4110',
        'COMMERCIAL_RENT_INCOME' => '4120',
        'SERVICE_CHARGE_INCOME' => '4200',
        'PENALTY_INCOME' => '4300',
        'OTHER_INCOME' => '4400',
    ];
}

/**
 * Resolve account_code for a role within a company.
 * Today: seed defaults. Future: optional company mapping table / settings (same signature).
 */
function re_income_role_account_code(PDO $conn, int $companyId, string $role): ?string
{
    $role = strtoupper(trim($role));
    if ($role === '') {
        return null;
    }

    // FUTURE HOOK: company-level role map / settings lookup using $conn + $companyId.
    // Until then, seed defaults only (codes live exclusively in this helper).
    $defaults = re_income_account_role_seed_defaults();
    return $defaults[$role] ?? $defaults['OTHER_INCOME'] ?? null;
}

/**
 * Load active COA row by account code (company-scoped).
 */
function re_income_find_account_by_code(PDO $conn, int $companyId, string $accountCode): ?array
{
    $st = $conn->prepare("
        SELECT * FROM re_chart_of_accounts
        WHERE company_id = ? AND account_code = ? AND is_active = 1
        LIMIT 1
    ");
    $st->execute([$companyId, $accountCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Resolve COA account row for an income role.
 *
 * @return array{account: ?array, role: string, account_code: ?string, used_fallback: bool, unmapped: bool, error: ?string}
 */
function re_resolve_income_account(PDO $conn, int $companyId, string $role, bool $allowOtherFallback = true): array
{
    $role = strtoupper(trim($role));
    $unmapped = !in_array($role, re_income_account_role_codes(), true);
    if ($role === '' || $unmapped) {
        $role = 'OTHER_INCOME';
        $unmapped = true;
    }

    $code = re_income_role_account_code($conn, $companyId, $role);
    $account = $code ? re_income_find_account_by_code($conn, $companyId, $code) : null;
    $usedFallback = false;

    if (!$account && $allowOtherFallback && $role !== 'OTHER_INCOME') {
        $otherCode = re_income_role_account_code($conn, $companyId, 'OTHER_INCOME');
        $account = $otherCode ? re_income_find_account_by_code($conn, $companyId, $otherCode) : null;
        $usedFallback = (bool)$account;
        if ($account) {
            $code = $otherCode;
            $role = 'OTHER_INCOME';
            $unmapped = true;
        }
    }

    if (!$account) {
        return [
            'account' => null,
            'role' => $role,
            'account_code' => $code,
            'used_fallback' => $usedFallback,
            'unmapped' => $unmapped,
            'error' => 'Income account not found for role ' . $role . ($code ? " (code {$code})" : ''),
        ];
    }

    return [
        'account' => $account,
        'role' => $role,
        'account_code' => $code,
        'used_fallback' => $usedFallback,
        'unmapped' => $unmapped,
        'error' => null,
    ];
}

/**
 * Legacy keyword detection on item names — lowest priority fallback only.
 */
function re_income_role_from_item_name(string $itemName, string $unitType = ''): ?string
{
    $n = strtolower($itemName);
    if ($n === '') {
        return null;
    }
    if (str_contains($n, 'penalty') || str_contains($n, 'late fee') || str_contains($n, 'late_fee')) {
        return 'PENALTY_INCOME';
    }
    if (str_contains($n, 'service') || str_contains($n, 'charge') || str_contains($n, 'chiller')
        || str_contains($n, 'parking') || str_contains($n, 'utility') || str_contains($n, 'access card')) {
        return 'SERVICE_CHARGE_INCOME';
    }
    if (str_contains($n, 'admin') || str_contains($n, 'commission') || str_contains($n, 'ejari')) {
        return 'OTHER_INCOME';
    }
    if (str_contains($n, 'rent')) {
        return lease_vat_unit_is_commercial($unitType)
            ? 'COMMERCIAL_RENT_INCOME'
            : 'RESIDENTIAL_RENT_INCOME';
    }
    return null;
}

/**
 * Map structured obligation classification → income role.
 * Priority: accounting_class → obligation_type → item_name keywords → OTHER_INCOME.
 *
 * Security deposit / liability / vat are not income roles (returns null).
 *
 * @return array{role: ?string, unmapped: bool, skip_income: bool, reason: string}
 */
function re_income_role_for_obligation(
    ?string $obligationType,
    ?string $accountingClass,
    string $unitType = '',
    ?string $itemName = null
): array {
    $type = strtolower(trim((string)$obligationType));
    $class = strtolower(trim((string)$accountingClass));

    if ($type === 'security_deposit' || $class === 'liability') {
        return [
            'role' => null,
            'unmapped' => false,
            'skip_income' => true,
            'reason' => 'Security deposit / liability is never revenue',
        ];
    }
    if ($type === 'vat') {
        return [
            'role' => null,
            'unmapped' => false,
            'skip_income' => true,
            'reason' => 'VAT uses Output VAT posting, not income',
        ];
    }

    // 1) accounting_class
    if ($class === 'service') {
        return ['role' => 'SERVICE_CHARGE_INCOME', 'unmapped' => false, 'skip_income' => false, 'reason' => 'accounting_class=service'];
    }
    if ($class === 'penalty') {
        return ['role' => 'PENALTY_INCOME', 'unmapped' => false, 'skip_income' => false, 'reason' => 'accounting_class=penalty'];
    }
    if ($class === 'pass_through') {
        // Ejari and similar pass-through currently post as Other Income (temporary)
        return ['role' => 'OTHER_INCOME', 'unmapped' => false, 'skip_income' => false, 'reason' => 'accounting_class=pass_through→OTHER_INCOME'];
    }

    // 2) obligation_type
    switch ($type) {
        case 'rent':
            $role = lease_vat_unit_is_commercial($unitType)
                ? 'COMMERCIAL_RENT_INCOME'
                : 'RESIDENTIAL_RENT_INCOME';
            return ['role' => $role, 'unmapped' => false, 'skip_income' => false, 'reason' => 'obligation_type=rent'];
        case 'service':
        case 'parking':
        case 'store':
        case 'utility':
        case 'access_card':
            return ['role' => 'SERVICE_CHARGE_INCOME', 'unmapped' => false, 'skip_income' => false, 'reason' => 'obligation_type=' . $type];
        case 'penalty':
            return ['role' => 'PENALTY_INCOME', 'unmapped' => false, 'skip_income' => false, 'reason' => 'obligation_type=penalty'];
        case 'admin_fee':
        case 'commission':
        case 'other':
            return ['role' => 'OTHER_INCOME', 'unmapped' => false, 'skip_income' => false, 'reason' => 'obligation_type=' . $type];
    }

    // 3) legacy item_name keywords
    if ($itemName !== null && $itemName !== '') {
        $fromName = re_income_role_from_item_name($itemName, $unitType);
        if ($fromName !== null) {
            return ['role' => $fromName, 'unmapped' => false, 'skip_income' => false, 'reason' => 'item_name keyword fallback'];
        }
    }

    // 4) unknown → Other Income (explicitly marked unmapped)
    return [
        'role' => 'OTHER_INCOME',
        'unmapped' => true,
        'skip_income' => false,
        'reason' => 'unmapped category→OTHER_INCOME',
    ];
}

/**
 * Full resolve for an invoice line context.
 *
 * Priority:
 * 1) explicit income_account_id (validated)
 * 2) structured obligation class/type (+ item_name fallback inside role helper)
 * 3) OTHER_INCOME when unmapped
 *
 * @param array<string,mixed> $ctx keys: income_account_id?, obligation_type?, accounting_class?, unit_type?, item_name?
 * @return array{account: ?array, role: ?string, account_code: ?string, unmapped: bool, skip_income: bool, used_explicit: bool, reason: string, error: ?string}
 */
function re_resolve_income_account_for_line(PDO $conn, int $companyId, array $ctx): array
{
    $explicitId = (int)($ctx['income_account_id'] ?? 0);
    if ($explicitId > 0) {
        $st = $conn->prepare("
            SELECT * FROM re_chart_of_accounts
            WHERE id = ? AND company_id = ? AND is_active = 1
            LIMIT 1
        ");
        $st->execute([$explicitId, $companyId]);
        $account = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($account) {
            return [
                'account' => $account,
                'role' => null,
                'account_code' => (string)($account['account_code'] ?? ''),
                'unmapped' => false,
                'skip_income' => false,
                'used_explicit' => true,
                'reason' => 'explicit income_account_id',
                'error' => null,
            ];
        }
        // Invalid explicit id → continue structured resolution
    }

    $mapped = re_income_role_for_obligation(
        isset($ctx['obligation_type']) ? (string)$ctx['obligation_type'] : null,
        isset($ctx['accounting_class']) ? (string)$ctx['accounting_class'] : null,
        (string)($ctx['unit_type'] ?? ''),
        isset($ctx['item_name']) ? (string)$ctx['item_name'] : null
    );

    if (!empty($mapped['skip_income'])) {
        return [
            'account' => null,
            'role' => null,
            'account_code' => null,
            'unmapped' => false,
            'skip_income' => true,
            'used_explicit' => false,
            'reason' => (string)$mapped['reason'],
            'error' => null,
        ];
    }

    $role = (string)($mapped['role'] ?? 'OTHER_INCOME');
    $resolved = re_resolve_income_account($conn, $companyId, $role, true);
    $unmapped = !empty($mapped['unmapped']) || !empty($resolved['unmapped']) || !empty($resolved['used_fallback']);

    return [
        'account' => $resolved['account'],
        'role' => $resolved['role'],
        'account_code' => $resolved['account_code'],
        'unmapped' => $unmapped,
        'skip_income' => false,
        'used_explicit' => false,
        'reason' => (string)$mapped['reason'],
        'error' => $resolved['error'],
    ];
}

/**
 * Read-only: historical invoice income credits that do not match expected role mapping.
 * Does not reverse or repair journals.
 *
 * @return list<array<string,mixed>>
 */
function re_income_mispost_candidates(PDO $conn, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $sql = "
        SELECT
            i.company_id,
            i.id AS invoice_id,
            i.invoice_number,
            i.invoice_date,
            ii.id AS invoice_item_id,
            ii.item_name,
            ii.obligation_id,
            o.obligation_type,
            o.accounting_class,
            o.description AS obligation_description,
            jh.id AS journal_id,
            jh.journal_number,
            coa.account_code AS posted_income_code,
            coa.account_name AS posted_income_name,
            jl.credit_amount AS posted_income_credit,
            u.unit_type
        FROM re_invoice_items ii
        INNER JOIN re_invoices i ON i.id = ii.invoice_id AND i.company_id = ii.company_id
        INNER JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
        INNER JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
        LEFT JOIN re_units u ON u.id = l.unit_id AND u.company_id = l.company_id
        INNER JOIN re_journal_headers jh
            ON jh.company_id = i.company_id
           AND jh.reference_type = 'invoice'
           AND jh.reference_id = i.id
           AND jh.is_posted = 1
           AND COALESCE(jh.is_reversed, 0) = 0
        INNER JOIN re_journal_lines jl ON jl.journal_id = jh.id AND jl.credit_amount > 0
        INNER JOIN re_chart_of_accounts coa ON coa.id = jl.account_id AND coa.company_id = jh.company_id
        WHERE i.company_id = ?
          AND coa.account_type = 'Income'
          AND o.obligation_type NOT IN ('vat', 'security_deposit')
        ORDER BY i.invoice_date, i.id, ii.id, jl.id
    ";

    $st = $conn->prepare($sql);
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $misposts = [];
    foreach ($rows as $row) {
        $expected = re_income_role_for_obligation(
            (string)$row['obligation_type'],
            (string)$row['accounting_class'],
            (string)($row['unit_type'] ?? ''),
            (string)$row['item_name']
        );
        if (!empty($expected['skip_income'])) {
            continue;
        }
        $role = (string)($expected['role'] ?? 'OTHER_INCOME');
        $expectedCode = re_income_role_account_code($conn, $companyId, $role);
        $postedCode = (string)$row['posted_income_code'];

        $isRentPosted = in_array($postedCode, ['4110', '4120'], true);
        $expectsNonRent = !in_array($role, ['RESIDENTIAL_RENT_INCOME', 'COMMERCIAL_RENT_INCOME'], true);
        $codeMismatch = $expectedCode && $postedCode !== $expectedCode;

        if (($expectsNonRent && $isRentPosted) || $codeMismatch) {
            $misposts[] = [
                'company_id' => $row['company_id'],
                'invoice_number' => $row['invoice_number'],
                'invoice_id' => $row['invoice_id'],
                'invoice_item_id' => $row['invoice_item_id'],
                'invoice_date' => $row['invoice_date'],
                'journal_number' => $row['journal_number'],
                'journal_id' => $row['journal_id'],
                'obligation_id' => $row['obligation_id'],
                'obligation_type' => $row['obligation_type'],
                'accounting_class' => $row['accounting_class'],
                'item_name' => $row['item_name'],
                'posted_income_code' => $postedCode,
                'posted_income_name' => $row['posted_income_name'],
                'posted_income_credit' => $row['posted_income_credit'],
                'expected_role' => $role,
                'expected_account_code' => $expectedCode,
                'reason' => $expected['reason'] ?? '',
            ];
        }
    }

    return $misposts;
}
