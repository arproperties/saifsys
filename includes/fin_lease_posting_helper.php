<?php
/**
 * Finance > Lease Posting: raises the opening entry of a lease as a journal voucher.
 *
 *   Dr  Sundry Debtors (the tenant)      everything the lease charges, tagged with the tenant
 *   Cr  Deferred Revenue                 the contract amount
 *   Cr  Admin Fee / Chiller Fees / Ejari Income / Other Income
 *   Cr  Security Deposit
 *
 * The rows and their accounts are fixed (FIN_LEASE_POSTING_ROWS); the amounts
 * come from the lease and can be changed before posting. The voucher keeps the
 * lease (fin_vouchers.lease_id): a lease is posted once, and can be posted again
 * only after that voucher is deleted.
 */

require_once __DIR__ . '/fin_voucher_helper.php';

/** Row key => what it is called, the account code it posts to, its side and its colour group. */
const FIN_LEASE_POSTING_ROWS = [
    'tenant'   => ['label' => 'Tenant',           'code' => '1121005', 'side' => 'debit',  'kind' => 'debtor'],
    'contract' => ['label' => 'Contract',         'code' => '2112009', 'side' => 'credit', 'kind' => 'liability'],
    'admin'    => ['label' => 'Admin Fee',        'code' => '4100010', 'side' => 'credit', 'kind' => 'income'],
    'chiller'  => ['label' => 'Chiller Fee',      'code' => '4100009', 'side' => 'credit', 'kind' => 'income'],
    'ejari'    => ['label' => 'Ejari Fee',        'code' => '4100008', 'side' => 'credit', 'kind' => 'income'],
    'deposit'  => ['label' => 'Security Deposit', 'code' => '2112010', 'side' => 'credit', 'kind' => 'liability'],
    'other'    => ['label' => 'Other Charges',    'code' => '4200001', 'side' => 'credit', 'kind' => 'income'],
];

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

/** Leases with their tenant, unit and the voucher they are posted as (voucher_id is null when not posted). */
function fin_lease_posting_query(string $where): string
{
    return "
        SELECT l.id, l.tenant_id, l.lease_number, l.status, l.start_date, l.end_date,
               l.contract_amount, l.admin_fees, l.chiller_fees, l.ejari_fees, l.security_deposit,
               l.commission_fees, l.amc_amount,
               COALESCE(NULLIF(TRIM(IF(t.tenant_type = 'company', t.company_name, '')), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', t.first_name, t.last_name)), ''),
                        NULLIF(TRIM(t.company_name), ''),
                        CONCAT('Tenant #', l.tenant_id)) AS tenant_name,
               u.unit_number,
               v.id AS voucher_id, v.voucher_no
          FROM re_leases l
          LEFT JOIN re_tenants t ON t.id = l.tenant_id
          LEFT JOIN re_units u ON u.id = l.unit_id
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

/** Row key => the amount the lease gives it. The tenant is debited with the sum of the rest. */
function fin_lease_posting_amounts(array $lease): array
{
    $out = [
        'contract' => (float)$lease['contract_amount'],
        'admin'    => (float)$lease['admin_fees'],
        'chiller'  => (float)$lease['chiller_fees'],
        'ejari'    => (float)$lease['ejari_fees'],
        'deposit'  => (float)$lease['security_deposit'],
        'other'    => (float)$lease['commission_fees'] + (float)$lease['amc_amount'],
    ];
    $out = array_map(static fn($n) => round($n, 2), $out);
    return ['tenant' => round(array_sum($out), 2)] + $out;
}

/**
 * Post a lease as a journal voucher. Returns the voucher id.
 * $data: date (Y-m-d) and amounts (row key => amount as typed).
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
