<?php
/**
 * Finance > Lease Posting: raises the opening entry of a lease as a journal voucher.
 *
 *   Dr  Sundry Debtors (the tenant)      everything the lease charges, tagged with the tenant
 *   Cr  Deferred Revenue                 the contract amount
 *   Cr  Security Deposit                 not on a renewal: the deposit is not collected again
 *   Cr  Chiller Fees / Ejari Income / Admin Fee / Commission / AMC
 *
 * The rows follow the Lease Information card of lease_view.php, in its order and
 * with its names. Their accounts are fixed (FIN_LEASE_POSTING_ROWS), except:
 *   - the contract goes to any "Deferred Revenue" account, by default the one
 *     named after the lease's building ("Deferred Revenue - Ayla" for AYLA
 *     RESIDENCE), else 2112009;
 *   - the commission goes to any revenue account (4xxxxxx), by default the first
 *     one named "...Commission...", else 4200001 OTHER INCOME.
 * The amounts come from the lease and can be changed before posting. The voucher keeps the
 * lease (fin_vouchers.lease_id): a lease is posted once, and can be posted again
 * only after that voucher is deleted.
 */

require_once __DIR__ . '/fin_voucher_helper.php';

/** Row key => what it is called, the account code it posts to, its side and its colour group. */
const FIN_LEASE_POSTING_ROWS = [
    'tenant'     => ['label' => 'Tenant',           'code' => '1121005', 'side' => 'debit',  'kind' => 'debtor'],
    'contract'   => ['label' => 'Contract Amount',  'code' => '2112009', 'side' => 'credit', 'kind' => 'liability'],
    'deposit'    => ['label' => 'Security Deposit', 'code' => '2112010', 'side' => 'credit', 'kind' => 'liability'],
    'chiller'    => ['label' => 'Chiller Fees',     'code' => '4100009', 'side' => 'credit', 'kind' => 'income'],
    'ejari'      => ['label' => 'Ejari Fees',       'code' => '4100008', 'side' => 'credit', 'kind' => 'income'],
    'admin'      => ['label' => 'Admin Fees',       'code' => '4100010', 'side' => 'credit', 'kind' => 'income'],
    'commission' => ['label' => 'Commission Fees',  'code' => '4200001', 'side' => 'credit', 'kind' => 'income'],
    'amc'        => ['label' => 'AMC Amount',       'code' => '4200001', 'side' => 'credit', 'kind' => 'income'],
];

/** The rows whose account is picked from a list on the page. */
const FIN_LEASE_POSTING_CHOOSABLE = ['contract', 'commission'];

function fin_lease_posting_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_vouchers' AND COLUMN_NAME = 'lease_id'");
    $chk->execute();
    return (int)$chk->fetchColumn() === 1;
}

/** Row key => the company's account for it (id, code, name), or null when the chart does not have it. */
function fin_lease_posting_accounts(PDO $conn, int $companyId): array
{
    $byCode = array_column(fin_voucher_ledger_accounts($conn, $companyId), null, 'code');
    $out = [];
    foreach (FIN_LEASE_POSTING_ROWS as $key => $row) {
        $out[$key] = $byCode[$row['code']] ?? null;
    }
    return $out;
}

/** The accounts the contract can be credited to: every postable account named "Deferred Revenue…", by code. */
function fin_lease_posting_contract_accounts(PDO $conn, int $companyId): array
{
    return array_values(array_filter(
        fin_voucher_ledger_accounts($conn, $companyId),
        static fn($a) => $a['code'] === FIN_LEASE_POSTING_ROWS['contract']['code'] || stripos($a['name'], 'deferred revenue') === 0
    ));
}

/**
 * The contract account for a building: the one whose name after "Deferred Revenue"
 * is a word or words of the building's name ("Ayla" in "AYLA RESIDENCE"), the
 * longest such match winning; else 2112009; else null.
 */
function fin_lease_posting_contract_default(array $choices, ?string $building): ?array
{
    $words = static fn(string $s): string => ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s))) . ' ';
    $bld = $words((string)$building);
    $best = null;
    $bestLen = 0;
    foreach ($choices as $a) {
        $suffix = trim($words(preg_replace('/^deferred revenue/i', '', $a['name'])));
        if ($suffix !== '' && strlen($suffix) > $bestLen && str_contains($bld, ' ' . $suffix . ' ')) {
            $best = $a;
            $bestLen = strlen($suffix);
        }
    }
    if ($best) {
        return $best;
    }
    foreach ($choices as $a) {
        if ($a['code'] === FIN_LEASE_POSTING_ROWS['contract']['code']) {
            return $a;
        }
    }
    return null;
}

/** The accounts a choosable row can post to (FIN_LEASE_POSTING_CHOOSABLE), by code. */
function fin_lease_posting_choices(PDO $conn, int $companyId, string $key): array
{
    if ($key === 'contract') {
        return fin_lease_posting_contract_accounts($conn, $companyId);
    }
    // Commission: any revenue account.
    return array_values(array_filter(fin_voucher_ledger_accounts($conn, $companyId), static fn($a) => str_starts_with($a['code'], '4')));
}

/** The account a choosable row starts at for this lease, or null when the chart has none. */
function fin_lease_posting_default(string $key, array $choices, array $lease): ?array
{
    if ($key === 'contract') {
        return fin_lease_posting_contract_default($choices, $lease['building_name'] ?? null);
    }
    foreach ($choices as $a) {
        if (stripos($a['name'], 'commission') !== false) {
            return $a;
        }
    }
    foreach ($choices as $a) {
        if ($a['code'] === FIN_LEASE_POSTING_ROWS[$key]['code']) {
            return $a;
        }
    }
    return null;
}

/** Leases with their tenant, unit and the voucher they are posted as (voucher_id is null when not posted). */
function fin_lease_posting_query(string $where): string
{
    return "
        SELECT l.id, l.tenant_id, l.lease_number, l.status, l.start_date, l.end_date,
               l.contract_amount, l.admin_fees, l.chiller_fees, l.ejari_fees, l.security_deposit,
               l.commission_fees, l.amc_amount, l.is_renewal_lease,
               COALESCE(NULLIF(TRIM(IF(t.tenant_type = 'company', t.company_name, '')), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', t.first_name, t.last_name)), ''),
                        NULLIF(TRIM(t.company_name), ''),
                        CONCAT('Tenant #', l.tenant_id)) AS tenant_name,
               u.unit_number, b.name AS building_name,
               v.id AS voucher_id, v.voucher_no
          FROM re_leases l
          LEFT JOIN re_tenants t ON t.id = l.tenant_id
          LEFT JOIN re_units u ON u.id = l.unit_id
          LEFT JOIN re_buildings b ON b.id = u.building_id
          LEFT JOIN fin_vouchers v ON v.lease_id = l.id AND v.company_id = l.company_id AND v.status = 'posted'
         WHERE l.company_id = ? AND {$where}";
}

/** The company's active leases, by lease number: the contracts that can be posted. */
function fin_lease_posting_leases(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare(fin_lease_posting_query("l.status = 'active'") . " ORDER BY l.lease_number, l.id");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** One lease of this company as fin_lease_posting_leases() gives it, or null. */
function fin_lease_posting_get(PDO $conn, int $companyId, int $leaseId): ?array
{
    $stmt = $conn->prepare(fin_lease_posting_query('l.id = ?') . " ORDER BY v.id DESC LIMIT 1");
    $stmt->execute([$companyId, $leaseId]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);
    return $lease ?: null;
}

/**
 * Row key => the amount the lease gives it, as lease_view.php shows it: a renewal
 * has no security deposit (it is not collected again). The tenant is debited
 * with the sum of the rest.
 */
function fin_lease_posting_amounts(array $lease): array
{
    $out = [
        'contract'   => (float)$lease['contract_amount'],
        'deposit'    => !empty($lease['is_renewal_lease']) ? 0.0 : (float)$lease['security_deposit'],
        'chiller'    => (float)$lease['chiller_fees'],
        'ejari'      => (float)$lease['ejari_fees'],
        'admin'      => (float)$lease['admin_fees'],
        'commission' => (float)$lease['commission_fees'],
        'amc'        => (float)$lease['amc_amount'],
    ];
    $out = array_map(static fn($n) => round($n, 2), $out);
    return ['tenant' => round(array_sum($out), 2)] + $out;
}

/**
 * Post a lease as a journal voucher. Returns the voucher id.
 * $data: date (Y-m-d), amounts (row key => amount as typed) and accounts (row
 * key => account id, for the choosable rows; left out = that row's default).
 * Throws InvalidArgumentException with a message fit to show the user.
 */
function fin_lease_post(PDO $conn, int $companyId, int $leaseId, array $data, ?int $userId = null): int
{
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        // The lock keeps two people from posting the same lease at once.
        $conn->prepare("SELECT id FROM re_leases WHERE id = ? AND company_id = ? FOR UPDATE")->execute([$leaseId, $companyId]);
        $lease = fin_lease_posting_get($conn, $companyId, $leaseId);
        if (!$lease || $lease['status'] !== 'active') {
            throw new InvalidArgumentException('Choose an active contract of this company.');
        }
        if ($lease['voucher_id']) {
            throw new InvalidArgumentException('This contract is already posted as ' . $lease['voucher_no'] . '.');
        }

        $accounts = fin_lease_posting_accounts($conn, $companyId);
        foreach (FIN_LEASE_POSTING_CHOOSABLE as $key) {
            $choices = fin_lease_posting_choices($conn, $companyId, $key);
            $pick = (int)($data['accounts'][$key] ?? 0);
            if (!$pick) {
                $accounts[$key] = fin_lease_posting_default($key, $choices, $lease);
                continue;
            }
            $chosen = array_values(array_filter($choices, static fn($a) => (int)$a['id'] === $pick));
            if (!$chosen) {
                throw new InvalidArgumentException(FIN_LEASE_POSTING_ROWS[$key]['label'] . ': choose ' . ($key === 'contract' ? 'a Deferred Revenue' : 'a revenue') . ' account.');
            }
            $accounts[$key] = $chosen[0];
        }
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        // The debtor line is the tenant's, so it counts in the tenant's balance.
        $tenant = 'tenant:' . (int)$lease['tenant_id'];
        if (!isset(fin_partners($conn, $companyId)[$tenant])) {
            $tenant = '';
        }
        $lines = [];
        foreach (FIN_LEASE_POSTING_ROWS as $key => $row) {
            $label = $key === 'tenant' ? $lease['tenant_name'] : $row['label'];
            $amount = fin_voucher_parse_amount($amounts[$key] ?? '');
            if ($amount === null) {
                throw new InvalidArgumentException("{$label}: the amount is not a number.");
            }
            if ($amount == 0) {
                continue;
            }
            if (!$accounts[$key]) {
                throw new InvalidArgumentException("{$label}: account {$row['code']} is not in the chart of accounts.");
            }
            $lines[] = [
                'account_id'  => (int)$accounts[$key]['id'],
                'description' => mb_substr($label . ' - ' . $lease['lease_number'], 0, 255),
                'partner'     => $key === 'tenant' ? $tenant : '',
                'debit'       => $row['side'] === 'debit' ? $amount : '',
                'credit'      => $row['side'] === 'credit' ? $amount : '',
            ];
        }
        if (!$lines) {
            throw new InvalidArgumentException('Enter the amounts to post.');
        }

        $unit = trim((string)$lease['unit_number']);
        $voucherId = fin_voucher_create($conn, $companyId, 'jv', [
            'date'      => $data['date'] ?? '',
            'party'     => mb_substr($lease['tenant_name'], 0, 150),
            'reference' => $lease['lease_number'],
            'narration' => mb_substr('Lease posting ' . $lease['lease_number'] . ' - ' . $lease['tenant_name'] . ($unit !== '' ? ' - Unit ' . $unit : ''), 0, 255),
            'lines'     => $lines,
        ], $userId);
        $conn->prepare("UPDATE fin_vouchers SET lease_id = ? WHERE id = ? AND company_id = ?")->execute([$leaseId, $voucherId, $companyId]);

        if ($ownTx) {
            $conn->commit();
        }
        return $voucherId;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}
