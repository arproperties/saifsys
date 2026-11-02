<?php
/**
 * Finance > monthly scheduled entries (fin_auto_entries).
 *
 * A schedule (an asset's depreciation; prepaid and accrued allocations later)
 * makes one entry per month. Each is made automatically as 'pending' once its
 * date has come, and is posted as a journal only when Accounts approve it:
 * an asset's as a Fixed Journal Voucher (fj), a prepaid / accrued allocation's
 * as an Allocation Journal Voucher (aj). Accounts can also skip a month,
 * and bring a skipped one back. Deleting the posted journal sends the month
 * back to pending.
 *
 * Amounts are spread over months like this: equal months, a part month by its
 * days (16-30 April is 15 of 30 days, so half a month), and the last month takes
 * whatever is left, so the months always add up to the whole amount.
 */

require_once __DIR__ . '/fin_voucher_helper.php';

/** Source type => the voucher kind its entries post as. */
const FIN_AUTO_SOURCES = ['asset' => 'fj', 'allocation' => 'aj'];

/** A date $months calendar months after $date, the day kept when the month has it (31 Jan + 1 = 28/29 Feb). */
function fin_add_months(string $date, int $months): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    $m += $months;
    $y += intdiv($m - 1, 12);
    $m = ($m - 1) % 12 + 1;
    $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    return sprintf('%04d-%02d-%02d', $y, $m, min($d, $last));
}

/** The last day of a schedule that starts on $start and runs $months months. */
function fin_schedule_end(string $start, int $months): string
{
    return date('Y-m-d', strtotime(fin_add_months($start, $months) . ' -1 day'));
}

/**
 * Spread $amount over $months months from $start. Returns one row per calendar
 * month: period (first of the month), date (last of the month), days (days of
 * the schedule in it), amount. $cut ends it early (an asset stopped): later
 * months are dropped and the cut month counts its days up to $cut.
 */
function fin_schedule_spread(float $amount, int $months, string $start, ?string $cut = null): array
{
    $end = fin_schedule_end($start, $months);
    $stop = ($cut !== null && $cut < $end) ? $cut : $end;
    $monthly = $amount / $months;
    $rows = [];
    $sum = 0.0;
    for ($period = substr($start, 0, 7) . '-01'; $period <= $stop; $period = fin_add_months($period, 1)) {
        $monthEnd = date('Y-m-t', strtotime($period));
        $from = max($start, $period);
        $to = min($stop, $monthEnd);
        $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        $inMonth = (int)date('t', strtotime($period));
        $amt = round($monthly * $days / $inMonth, 2);
        $rows[] = ['period' => $period, 'date' => $monthEnd, 'days' => $days, 'amount' => $amt];
        $sum += $amt;
    }
    // The last month takes what is left, so the whole amount is written off exactly.
    if ($rows && $stop === $end) {
        $i = count($rows) - 1;
        $rows[$i]['amount'] = round($amount - ($sum - $rows[$i]['amount']), 2);
    }
    return $rows;
}

/**
 * Make the pending entries that are due (their date on or before $today) for
 * every schedule of a company. Entries already made, posted or skipped are left
 * as they are. Returns how many were made.
 */
function fin_auto_generate(PDO $conn, int $companyId, ?string $today = null): int
{
    $today = $today ?? date('Y-m-d');
    $n = 0;
    foreach (['fin_asset_generate', 'fin_alc_generate'] as $fn) {
        if (function_exists($fn)) {
            $n += $fn($conn, $companyId, $today);
        }
    }
    return $n;
}

/** Adds one month's entry unless that month already has one. Returns 1 when added. */
function fin_auto_add(PDO $conn, int $companyId, string $sourceType, int $sourceId, string $period, string $date, float $amount, string $narration, array $lines): int
{
    if ($amount <= 0) {
        return 0;
    }
    $stmt = $conn->prepare("INSERT IGNORE INTO fin_auto_entries (company_id, source_type, source_id, period, entry_date, amount, narration, lines_json) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$companyId, $sourceType, $sourceId, $period, $date, $amount, mb_substr($narration, 0, 255), json_encode($lines)]);
    return $stmt->rowCount();
}

/**
 * Entries of a company, oldest first, each with the name of its schedule
 * (`source_name`) and, once posted, its voucher number. $status narrows them.
 */
function fin_auto_entries(PDO $conn, int $companyId, ?string $status = 'pending', ?string $sourceType = null, ?int $sourceId = null): array
{
    $sql = "
        SELECT e.*, v.voucher_no,
               CASE e.source_type WHEN 'asset' THEN (SELECT a.name FROM fin_assets a WHERE a.id = e.source_id)
                                  WHEN 'allocation' THEN (SELECT s.name FROM fin_alloc_schedules s WHERE s.id = e.source_id) END AS source_name,
               COALESCE(NULLIF(TRIM(u.fullname), ''), u.username) AS decided_by_name
          FROM fin_auto_entries e
          LEFT JOIN fin_vouchers v ON v.id = e.voucher_id
          LEFT JOIN user u ON u.id = e.decided_by
         WHERE e.company_id = ?";
    $args = [$companyId];
    foreach (['e.status' => $status, 'e.source_type' => $sourceType, 'e.source_id' => $sourceId] as $col => $val) {
        if ($val !== null) {
            $sql .= " AND {$col} = ?";
            $args[] = $val;
        }
    }
    $stmt = $conn->prepare($sql . " ORDER BY e.entry_date, e.source_type, e.source_id, e.id");
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Approve pending entries: each is posted as its journal on its own date.
 * Returns the vouchers made. Entries no longer pending are refused.
 */
function fin_auto_approve(PDO $conn, int $companyId, array $ids, ?int $userId): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new InvalidArgumentException('Tick the entries to approve.');
    }
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT * FROM fin_auto_entries WHERE id IN ($in) AND company_id = ? ORDER BY entry_date, id FOR UPDATE");
        $stmt->execute(array_merge($ids, [$companyId]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new InvalidArgumentException('An entry ticked does not exist in this company.');
        }
        $made = [];
        $mark = $conn->prepare("UPDATE fin_auto_entries SET status = 'posted', voucher_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ?");
        foreach ($rows as $r) {
            if ($r['status'] !== 'pending') {
                throw new InvalidArgumentException($r['narration'] . ' is no longer pending. Refresh the page.');
            }
            $voucherId = fin_voucher_create($conn, $companyId, FIN_AUTO_SOURCES[$r['source_type']], [
                'date' => $r['entry_date'],
                'narration' => $r['narration'],
                'lines' => json_decode($r['lines_json'], true),
            ], $userId);
            $mark->execute([$voucherId, $userId, $r['id']]);
            $made[] = $voucherId;
        }
        if ($ownTx) {
            $conn->commit();
        }
        return $made;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/** Skip pending entries (nothing is posted for those months), or bring skipped ones back ($restore). */
function fin_auto_skip(PDO $conn, int $companyId, array $ids, ?int $userId, bool $restore = false): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new InvalidArgumentException('Tick the entries first.');
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    [$from, $to] = $restore ? ['skipped', 'pending'] : ['pending', 'skipped'];
    $stmt = $conn->prepare("UPDATE fin_auto_entries SET status = ?, decided_by = ?, decided_at = NOW() WHERE id IN ($in) AND company_id = ? AND status = ?");
    $stmt->execute(array_merge([$to, $restore ? null : $userId], $ids, [$companyId, $from]));
    return $stmt->rowCount();
}
