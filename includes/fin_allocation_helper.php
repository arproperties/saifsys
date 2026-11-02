<?php
/**
 * Finance > Allocations (fin_alloc_schedules): prepaid expenses and accrued
 * revenue spread over months.
 *
 *   prepaid  paid ahead (insurance, rent, licence): each month
 *            Dr the expense, Cr the prepaid account
 *   accrued  earned but not yet billed: each month
 *            Dr the accrued revenue account, Cr the revenue
 *
 * The amount is spread like an asset's depreciation (fin_schedule_spread():
 * part months by days, the last month takes what is left). Each month is made
 * as pending and posted as an Allocation Journal Voucher (aj) when Accounts
 * approve it (fin_auto_entry_helper.php). post_from is the first month the
 * system posts; earlier months are already in the opening balances.
 * Once a month is posted, only the name and notes change; a schedule can be
 * stopped (ends on the stop date). One can start from a supplier invoice.
 *
 * Functions are named fin_alc_*: fin_alloc_* belongs to supplier payment allocations.
 */

require_once __DIR__ . '/fin_auto_entry_helper.php';
require_once __DIR__ . '/fin_supplier_invoice_helper.php';

/** Kind => label, and the label of its debit and credit accounts. */
const FIN_ALC_KINDS = [
    'prepaid' => ['label' => 'Prepaid expense', 'debit' => 'Expense account (Dr each month)', 'credit' => 'Prepaid account (Cr each month)'],
    'accrued' => ['label' => 'Accrued revenue', 'debit' => 'Accrued revenue account (Dr each month)', 'credit' => 'Revenue account (Cr each month)'],
];
const FIN_ALC_MAX_MONTHS = 120;

function fin_alc_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('fin_alloc_schedules', 'fin_auto_entries')");
    $chk->execute();
    return (int)$chk->fetchColumn() === 2 && fin_si_ready($conn);
}

/** The schedule of this company with its account codes and names, or null. */
function fin_alc_get(PDO $conn, int $companyId, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT s.*, CONCAT(ad.code, ' ', ad.name) AS debit_account, CONCAT(ac.code, ' ', ac.name) AS credit_account, sv.voucher_no AS source_no
          FROM fin_alloc_schedules s
          JOIN fin_coa_accounts ad ON ad.id = s.debit_account_id
          JOIN fin_coa_accounts ac ON ac.id = s.credit_account_id
          LEFT JOIN fin_vouchers sv ON sv.id = s.source_voucher_id
         WHERE s.id = ? AND s.company_id = ?");
    $stmt->execute([$id, $companyId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** The schedule's months, each with its state as for assets (see fin_asset_schedule()). */
function fin_alc_schedule(PDO $conn, int $companyId, array $s): array
{
    $made = [];
    foreach (fin_auto_entries($conn, $companyId, null, 'allocation', (int)$s['id']) as $e) {
        $made[$e['period']] = $e;
    }
    $cut = $s['status'] === 'stopped' ? $s['stop_date'] : null;
    $rows = fin_schedule_spread((float)$s['amount'], (int)$s['months'], $s['start_date'], $cut);
    foreach ($rows as $i => $r) {
        $e = $made[$r['period']] ?? null;
        $rows[$i]['state'] = $e ? $e['status'] : ($r['period'] < $s['post_from'] ? 'before' : 'due later');
        $rows[$i]['voucher_id'] = $e ? $e['voucher_id'] : null;
        $rows[$i]['voucher_no'] = $e['voucher_no'] ?? null;
        if ($e) {
            $rows[$i]['amount'] = (float)$e['amount'];
        }
    }
    return $rows;
}

/** Totals of a schedule: before the system, posted, and what is still to post. */
function fin_alc_totals(array $s, array $schedule): array
{
    $before = $posted = 0.0;
    foreach ($schedule as $r) {
        if ($r['state'] === 'before') {
            $before += $r['amount'];
        } elseif ($r['state'] === 'posted') {
            $posted += $r['amount'];
        }
    }
    return ['before' => round($before, 2), 'posted' => round($posted, 2),
            'left' => round((float)$s['amount'] - $before - $posted, 2), 'monthly' => round((float)$s['amount'] / (int)$s['months'], 2)];
}

/** The company's schedules, newest first, each with its totals. */
function fin_alc_list(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("SELECT id FROM fin_alloc_schedules WHERE company_id = ? ORDER BY start_date DESC, id DESC");
    $stmt->execute([$companyId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $s = fin_alc_get($conn, $companyId, (int)$id);
        $s['totals'] = fin_alc_totals($s, fin_alc_schedule($conn, $companyId, $s));
        $out[] = $s;
    }
    return $out;
}

function fin_alc_posted_count(PDO $conn, int $companyId, int $id): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM fin_auto_entries WHERE company_id = ? AND source_type = 'allocation' AND source_id = ? AND status = 'posted'");
    $stmt->execute([$companyId, $id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Check a schedule as entered. $data: kind, name, debit_account_id,
 * credit_account_id, amount, months, start_date, post_from (YYYY-MM),
 * source_voucher_id (optional), notes. Returns the row to save.
 */
function fin_alc_prepare(PDO $conn, int $companyId, array $data): array
{
    $kind = (string)($data['kind'] ?? '');
    if (!isset(FIN_ALC_KINDS[$kind])) {
        throw new InvalidArgumentException('Choose Prepaid expense or Accrued revenue.');
    }
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        throw new InvalidArgumentException('Enter a name (150 characters at most).');
    }
    $accounts = array_column(fin_voucher_ledger_accounts($conn, $companyId), null, 'id');
    $debit = (int)($data['debit_account_id'] ?? 0);
    $credit = (int)($data['credit_account_id'] ?? 0);
    if (!isset($accounts[$debit])) {
        throw new InvalidArgumentException('Choose the ' . strtolower(trim(strtok(FIN_ALC_KINDS[$kind]['debit'], '('))) . '.');
    }
    if (!isset($accounts[$credit])) {
        throw new InvalidArgumentException('Choose the ' . strtolower(trim(strtok(FIN_ALC_KINDS[$kind]['credit'], '('))) . '.');
    }
    if ($debit === $credit) {
        throw new InvalidArgumentException('The two accounts must be different.');
    }
    $amount = fin_voucher_parse_amount($data['amount'] ?? '');
    if ($amount === null || $amount <= 0 || $amount > FIN_VOUCHER_MAX_AMOUNT) {
        throw new InvalidArgumentException('Enter the amount to spread.');
    }
    $months = (int)($data['months'] ?? 0);
    if ($months < 1 || $months > FIN_ALC_MAX_MONTHS) {
        throw new InvalidArgumentException('Enter the number of months (1 to ' . FIN_ALC_MAX_MONTHS . ').');
    }
    $start = trim((string)($data['start_date'] ?? ''));
    if (!fin_voucher_valid_date($start)) {
        throw new InvalidArgumentException('Enter the start date.');
    }
    $postFrom = trim((string)($data['post_from'] ?? ''));
    if ($postFrom === '') {
        $postFrom = substr($start, 0, 7);
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $postFrom) || !fin_voucher_valid_date($postFrom . '-01')) {
        throw new InvalidArgumentException('Choose the first month to post.');
    }
    $postFrom .= '-01';
    if ($postFrom < substr($start, 0, 7) . '-01' || $postFrom > fin_schedule_end($start, $months)) {
        throw new InvalidArgumentException('The first month to post must fall within the months of the schedule.');
    }
    $source = (int)($data['source_voucher_id'] ?? 0);
    if ($source) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM fin_vouchers WHERE id = ? AND company_id = ? AND type = 'si' AND status = 'posted'");
        $stmt->execute([$source, $companyId]);
        if (!(int)$stmt->fetchColumn()) {
            throw new InvalidArgumentException('The supplier invoice of this schedule was not found.');
        }
    }
    $notes = trim((string)($data['notes'] ?? ''));
    if (mb_strlen($notes) > 255) {
        throw new InvalidArgumentException('The notes are too long (255 characters at most).');
    }
    return ['kind' => $kind, 'name' => $name, 'debit_account_id' => $debit, 'credit_account_id' => $credit, 'amount' => $amount,
            'months' => $months, 'start_date' => $start, 'post_from' => $postFrom, 'source_voucher_id' => $source ?: null, 'notes' => $notes !== '' ? $notes : null];
}

/** Add a schedule and make its months already due. Returns its id. */
function fin_alc_create(PDO $conn, int $companyId, array $data, ?int $userId, ?string $today = null): int
{
    $p = fin_alc_prepare($conn, $companyId, $data);
    $conn->prepare("INSERT INTO fin_alloc_schedules (company_id, kind, name, debit_account_id, credit_account_id, amount, months, start_date, post_from, source_voucher_id, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$companyId, $p['kind'], $p['name'], $p['debit_account_id'], $p['credit_account_id'], $p['amount'], $p['months'], $p['start_date'], $p['post_from'], $p['source_voucher_id'], $p['notes'], $userId]);
    $id = (int)$conn->lastInsertId();
    fin_alc_generate($conn, $companyId, $today ?? date('Y-m-d'), $id);
    return $id;
}

/** Change a schedule: everything before a month is posted (months not decided are made again), after that only name and notes. */
function fin_alc_update(PDO $conn, int $companyId, int $id, array $data, ?int $userId, ?string $today = null): void
{
    $s = fin_alc_get($conn, $companyId, $id);
    if (!$s) {
        throw new InvalidArgumentException('Schedule not found in this company.');
    }
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        if (fin_alc_posted_count($conn, $companyId, $id) > 0) {
            $name = trim((string)($data['name'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || mb_strlen($notes) > 255) {
                throw new InvalidArgumentException('Enter a name (150 characters at most) and notes of 255 at most.');
            }
            $conn->prepare("UPDATE fin_alloc_schedules SET name = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$name, $notes !== '' ? $notes : null, $userId, $id]);
        } else {
            $p = fin_alc_prepare($conn, $companyId, $data + ['source_voucher_id' => $s['source_voucher_id']]);
            $conn->prepare("UPDATE fin_alloc_schedules SET kind = ?, name = ?, debit_account_id = ?, credit_account_id = ?, amount = ?, months = ?, start_date = ?, post_from = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$p['kind'], $p['name'], $p['debit_account_id'], $p['credit_account_id'], $p['amount'], $p['months'], $p['start_date'], $p['post_from'], $p['notes'], $userId, $id]);
            $conn->prepare("DELETE FROM fin_auto_entries WHERE company_id = ? AND source_type = 'allocation' AND source_id = ? AND status = 'pending'")->execute([$companyId, $id]);
            fin_alc_generate($conn, $companyId, $today ?? date('Y-m-d'), $id);
        }
        if ($ownTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/** Stop a schedule on a date (the month counts its days up to it, later months are dropped), or start it again ($stopDate null). */
function fin_alc_stop(PDO $conn, int $companyId, int $id, ?string $stopDate, ?int $userId, ?string $today = null): void
{
    $s = fin_alc_get($conn, $companyId, $id);
    if (!$s) {
        throw new InvalidArgumentException('Schedule not found in this company.');
    }
    if ($stopDate !== null) {
        if (!fin_voucher_valid_date($stopDate) || $stopDate < $s['start_date']) {
            throw new InvalidArgumentException('Enter a stop date on or after the start date.');
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM fin_auto_entries WHERE company_id = ? AND source_type = 'allocation' AND source_id = ? AND status = 'posted' AND period >= ?");
        $stmt->execute([$companyId, $id, substr($stopDate, 0, 7) . '-01']);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Months from the stop month on are already posted. Delete those Allocation Journal Vouchers first.');
        }
    }
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare("UPDATE fin_alloc_schedules SET status = ?, stop_date = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$stopDate !== null ? 'stopped' : 'active', $stopDate, $userId, $id]);
        $from = $stopDate !== null ? substr($stopDate, 0, 7) . '-01' : '0000-01-01';
        $conn->prepare("DELETE FROM fin_auto_entries WHERE company_id = ? AND source_type = 'allocation' AND source_id = ? AND status <> 'posted' AND period >= ?")
            ->execute([$companyId, $id, $from]);
        fin_alc_generate($conn, $companyId, $today ?? date('Y-m-d'), $id);
        if ($ownTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/** Delete a schedule with no posted month, with its pending and skipped months. */
function fin_alc_delete(PDO $conn, int $companyId, int $id): void
{
    if (!fin_alc_get($conn, $companyId, $id)) {
        throw new InvalidArgumentException('Schedule not found in this company.');
    }
    if (fin_alc_posted_count($conn, $companyId, $id) > 0) {
        throw new InvalidArgumentException('Months of this schedule are posted. Stop it instead, or delete those Allocation Journal Vouchers first.');
    }
    $conn->prepare("DELETE FROM fin_auto_entries WHERE company_id = ? AND source_type = 'allocation' AND source_id = ?")->execute([$companyId, $id]);
    $conn->prepare("DELETE FROM fin_alloc_schedules WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
}

/** Make the pending months due by $today, for all of a company's schedules or one. Returns how many were made. */
function fin_alc_generate(PDO $conn, int $companyId, string $today, ?int $id = null): int
{
    if (!fin_alc_ready($conn)) {
        return 0;
    }
    $sql = "SELECT id FROM fin_alloc_schedules WHERE company_id = ?";
    $args = [$companyId];
    if ($id !== null) {
        $sql .= " AND id = ?";
        $args[] = $id;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $s = fin_alc_get($conn, $companyId, (int)$sid);
        $cut = $s['status'] === 'stopped' ? $s['stop_date'] : null;
        foreach (fin_schedule_spread((float)$s['amount'], (int)$s['months'], $s['start_date'], $cut) as $r) {
            if ($r['period'] < $s['post_from'] || $r['date'] > $today) {
                continue;
            }
            $n += fin_auto_add($conn, $companyId, 'allocation', (int)$s['id'], $r['period'], $r['date'], $r['amount'],
                FIN_ALC_KINDS[$s['kind']]['label'] . ' ' . $s['name'] . ' - ' . date('M Y', strtotime($r['period'])), [
                    ['account_id' => (int)$s['debit_account_id'], 'description' => '', 'debit' => (string)$r['amount'], 'credit' => ''],
                    ['account_id' => (int)$s['credit_account_id'], 'description' => '', 'debit' => '', 'credit' => (string)$r['amount']],
                ]);
        }
    }
    return $n;
}

/**
 * What a prepaid schedule starts with when it comes from a supplier invoice:
 * its description, the amount before VAT, the invoice date, and its first
 * line's account as the prepaid account (credited each month).
 */
function fin_alc_from_invoice(PDO $conn, int $companyId, int $voucherId): ?array
{
    $v = fin_voucher_get($conn, $companyId, $voucherId);
    $si = $v && $v['type'] === 'si' && $v['status'] === 'posted' ? fin_si_get($conn, $companyId, $voucherId) : null;
    if (!$si) {
        return null;
    }
    return [
        'kind' => 'prepaid',
        'name' => mb_substr((string)$v['narration'], 0, 150),
        'credit_account_id' => (int)$v['lines'][0]['account_id'],
        'amount' => number_format((float)$si['net_amount'], 2, '.', ''),
        'start_date' => $v['voucher_date'],
        'source_voucher_id' => $voucherId,
        'source_no' => $v['voucher_no'],
    ];
}
