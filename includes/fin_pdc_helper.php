<?php
/**
 * Finance > PDC Receivables: a tenant's post-dated cheques move from the tenant's
 * debtor account to PDC Receivables, one voucher (pdc, PDCR-YYYY-NNNN) per cheque:
 *
 *   Dr  PDC Receivables (the building's, tagged with the tenant)   cheque amount
 *   Cr  Sundry Debtors (the tenant, as on the lease's Lease Voucher) cheque amount
 *
 * The cheques are the ones recorded on the lease (re_lease_cheques). Every cheque
 * moves as soon as it is recorded, future ones too, each voucher dated with its own
 * cheque date (so a 2027 cheque counts in the balances from its date), once:
 *   - it is a cheque (not cash, a transfer or a card), with an amount;
 *   - it is not a draft, cancelled, replaced, bounced, returned or in legal;
 *   - its lease has a posted Lease Voucher (that voucher names the debtor account);
 *   - its building has a PDC Receivables account (the one under "PDC RECEIVABLES"
 *     named after the building; a villa goes to the "VILLA" one).
 * A cheque dated before the Lease Voucher is posted on the Lease Voucher's date.
 * Posting happens when a lease is posted, when PDC Receivables is opened and daily
 * (cron/fin_pdc_receivables.php, for cheques added to a lease later).
 *
 * fin_pdc_entries keeps one row per cheque posted, so a cheque is never posted twice;
 * a deleted PDC voucher is not raised again. Clearing a cheque (Dr Bank, Cr PDC) and
 * a returned cheque are recorded by Accounts by hand.
 */

require_once __DIR__ . '/fin_voucher_helper.php';
require_once __DIR__ . '/fin_lease_posting_helper.php';

/** Cheque statuses that are still a cheque in hand or banked: these are posted. */
const FIN_PDC_STATUSES = ['pending', 'collected', 'hold', 'held_by_finance', 'deposited', 'cleared'];

function fin_pdc_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('fin_pdc_entries', 're_lease_cheques'))
      + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_vouchers' AND COLUMN_NAME = 'type' AND COLUMN_TYPE LIKE '%''pdc''%')");
    $chk->execute();
    return (int)$chk->fetchColumn() === 3 && fin_lease_posting_ready($conn);
}

/** The postable accounts under the "PDC RECEIVABLES" heading, by code. */
function fin_pdc_accounts(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("
        SELECT a.id
          FROM fin_coa_accounts a
          JOIN fin_coa_accounts p ON p.id = a.parent_id AND p.company_id = a.company_id
         WHERE a.company_id = ? AND p.name LIKE 'pdc receivable%'
    ");
    $stmt->execute([$companyId]);
    $under = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    return array_values(array_filter(fin_voucher_ledger_accounts($conn, $companyId), static fn($a) => isset($under[(int)$a['id']])));
}

/**
 * The PDC account for a building: the one whose name after "PDC RECEIVABLES -" is
 * the building's name or a word or words of it, plurals aside ("PDC RECEIVABLES -
 * AYLA RESIDENCES" for AYLA RESIDENCE, "... - VILLA" for SHARJAH VILLA), the longest
 * match winning; else null.
 */
function fin_pdc_account_for(array $choices, ?string $building): ?array
{
    $words = static fn(string $s): string => ' ' . trim(preg_replace('/s\b/', '', preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)))) . ' ';
    $bld = $words((string)$building);
    $best = null;
    $bestLen = 0;
    foreach ($choices as $a) {
        $name = trim($words(preg_replace('/^\s*pdc\s+receivables?[\s\-]*/i', '', $a['name'])));
        if ($name !== '' && strlen($name) > $bestLen && str_contains($bld, ' ' . $name . ' ')) {
            $best = $a;
            $bestLen = strlen($name);
        }
    }
    return $best;
}

/** The cheques recorded and not posted yet ($leaseId 0 = every lease), by cheque date. */
function fin_pdc_due(PDO $conn, int $companyId, int $leaseId = 0): array
{
    $in = implode(',', array_fill(0, count(FIN_PDC_STATUSES), '?'));
    $stmt = $conn->prepare("
        SELECT c.id, c.lease_id, c.cheque_number, c.cheque_date, c.cheque_amount, c.bank_name,
               v.id AS lease_voucher_id, v.voucher_date AS lease_voucher_date
          FROM re_lease_cheques c
          JOIN fin_vouchers v ON v.lease_id = c.lease_id AND v.company_id = c.company_id AND v.type = 'lv' AND v.status = 'posted'
          LEFT JOIN fin_pdc_entries p ON p.cheque_id = c.id
         WHERE c.company_id = ? AND c.payment_method = 'cheque' AND c.status IN ({$in})
           AND c.cheque_amount > 0 AND c.cheque_date IS NOT NULL
           AND p.id IS NULL" . ($leaseId ? ' AND c.lease_id = ?' : '') . "
         ORDER BY c.cheque_date, c.id");
    $stmt->execute(array_merge([$companyId], FIN_PDC_STATUSES, $leaseId ? [$leaseId] : []));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Post every cheque recorded and not posted yet ($leaseId 0 = every lease of the company).
 * Returns ['posted' => voucher ids, 'waiting' => messages for cheques that could not be posted].
 * Inside a caller's transaction the cheques post in it; otherwise each cheque is its own.
 */
function fin_pdc_post_due(PDO $conn, int $companyId, int $leaseId = 0, ?int $userId = null): array
{
    $out = ['posted' => [], 'waiting' => []];
    $pdcAccounts = null;
    $leases = [];
    foreach (fin_pdc_due($conn, $companyId, $leaseId) as $c) {
        $lid = (int)$c['lease_id'];
        $leases[$lid] ??= fin_lease_posting_get($conn, $companyId, $lid);
        $lease = $leases[$lid];
        $pdcAccounts ??= fin_pdc_accounts($conn, $companyId);
        $pdc = $lease ? fin_pdc_account_for($pdcAccounts, $lease['building_name']) : null;
        if (!$pdc) {
            $out['waiting'][] = 'Cheque ' . $c['cheque_number'] . ' (' . ($lease['lease_number'] ?? 'lease #' . $lid) . '): no PDC Receivables account for ' . ($lease['building_name'] ?? 'its building') . '.';
            continue;
        }
        // The debtor is credited where the Lease Voucher debited it.
        $stmt = $conn->prepare("SELECT account_id, partner_type, partner_id FROM fin_ledger_entries WHERE voucher_id = ? AND company_id = ? AND debit > 0 ORDER BY id LIMIT 1");
        $stmt->execute([(int)$c['lease_voucher_id'], $companyId]);
        $debtor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$debtor) {
            $out['waiting'][] = 'Cheque ' . $c['cheque_number'] . ' (' . $lease['lease_number'] . '): its Lease Voucher has no tenant line.';
            continue;
        }
        $partner = (string)fin_partner_key($debtor['partner_type'], $debtor['partner_id']);
        $date = max($c['cheque_date'], $c['lease_voucher_date']);
        $unit = trim((string)$lease['unit_number']);
        $label = 'PDC ' . $c['cheque_number'] . ' - ' . $lease['lease_number'];

        $ownTx = !$conn->inTransaction();
        if ($ownTx) {
            $conn->beginTransaction();
        }
        try {
            // The cheque's row goes in first: a second run at the same time stops here.
            $ins = $conn->prepare("INSERT IGNORE INTO fin_pdc_entries (company_id, cheque_id, lease_id, amount, created_by) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$companyId, (int)$c['id'], $lid, $c['cheque_amount'], $userId]);
            if ($ins->rowCount() === 0) {
                if ($ownTx) {
                    $conn->rollBack();
                }
                continue;
            }
            $voucherId = fin_voucher_create($conn, $companyId, 'pdc', [
                'date'      => $date,
                'party'     => mb_substr($lease['tenant_name'], 0, 150),
                'reference' => mb_substr((string)$c['cheque_number'], 0, 100),
                'narration' => mb_substr('PDC cheque ' . $c['cheque_number'] . ' - ' . $lease['lease_number'] . ' - ' . $lease['tenant_name'] . ($unit !== '' ? ' - Unit ' . $unit : ''), 0, 255),
                'lines'     => [
                    ['account_id' => (int)$pdc['id'], 'description' => mb_substr($label, 0, 255), 'partner' => $partner, 'debit' => $c['cheque_amount'], 'credit' => ''],
                    ['account_id' => (int)$debtor['account_id'], 'description' => mb_substr($label, 0, 255), 'partner' => $partner, 'debit' => '', 'credit' => $c['cheque_amount']],
                ],
            ], $userId);
            $conn->prepare("UPDATE fin_pdc_entries SET voucher_id = ? WHERE cheque_id = ?")->execute([$voucherId, (int)$c['id']]);
            if ($ownTx) {
                $conn->commit();
            }
            $out['posted'][] = $voucherId;
        } catch (Throwable $e) {
            if ($ownTx) {
                $conn->rollBack();
            }
            if (!$ownTx) {
                throw $e;
            }
            error_log('fin_pdc_post_due: cheque ' . $c['id'] . ': ' . $e->getMessage());
            $out['waiting'][] = 'Cheque ' . $c['cheque_number'] . ' (' . $lease['lease_number'] . '): ' . ($e instanceof InvalidArgumentException ? $e->getMessage() : 'could not be posted.');
        }
    }
    return $out;
}

/** Today in Dubai: PHP on live runs in UTC, the business day is Dubai's. */
function fin_pdc_today(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
}
