<?php
/**
 * Rent month-end cut-off (accrual by calendar month).
 *
 * Older Invoice Mode rent invoices cover lease-anniversary periods
 * (e.g. 23 Oct - 22 Nov) and book the whole month's rent on the invoice date.
 * This engine leaves those invoices, receipts and tenant credit untouched and
 * posts adjusting journals so each calendar month carries only its own days:
 *
 *   On the invoice date:   Dr Rent Income        Cr 2430 Deferred Rent Income   (days after month end)
 *   On the 1st of month:   Dr 2430               Cr Rent Income                  (that month's days)
 *
 * Journals use reference_type 'rent_cutoff' / 'rent_cutoff_release:YYYY-MM'
 * with reference_id = invoice id. Sync is idempotent: if the invoice changes
 * or is voided, the old cut-off journals are reversed on their own dates and
 * the correct ones are posted again.
 */
declare(strict_types=1);

require_once __DIR__ . '/../accounting/accounting_engine.php';

// Invoices whose revenue journal is dated before this are left as they were.
// Set to cover every Invoice Mode invoice: a later start would leave the first
// month short (the prior month's invoices would keep its days).
const RE_RENT_CUTOFF_FROM = '2000-01-01';
const RE_RENT_CUTOFF_ACCOUNT_CODE = '2430';

if (!function_exists('re_rent_cutoff_account')) {
    /**
     * Find or create 2430 Deferred Rent Income next to 2410.
     *
     * @return array<string,mixed>|null
     */
    function re_rent_cutoff_account(PDO $conn, int $companyId, bool $create = true): ?array
    {
        $account = find_account_by_code(RE_RENT_CUTOFF_ACCOUNT_CODE, $companyId);
        if ($account || !$create) {
            return $account ?: null;
        }
        $sibling = find_account_by_code('2410', $companyId);
        $parentId = $sibling['parent_id'] ?? null;
        if (!$parentId) {
            $header = find_account_by_code('2400', $companyId);
            $parentId = $header['id'] ?? null;
        }
        $conn->prepare("
            INSERT INTO re_chart_of_accounts
                (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
            VALUES (?, ?, 'Deferred Rent Income', 'Liability', ?, 'credit', 0, 1, ?)
        ")->execute([
            $companyId,
            RE_RENT_CUTOFF_ACCOUNT_CODE,
            $parentId,
            'Rent invoiced but belonging to the next calendar month (month-end cut-off). Separate from tenant credit in 2410.',
        ]);
        return find_account_by_code(RE_RENT_CUTOFF_ACCOUNT_CODE, $companyId) ?: null;
    }
}

if (!function_exists('re_rent_cutoff_month_days')) {
    /**
     * Days of a period falling in each calendar month.
     *
     * @return array<string,int> 'YYYY-MM' => days
     */
    function re_rent_cutoff_month_days(string $periodStart, string $periodEnd): array
    {
        $months = [];
        $day = new DateTimeImmutable($periodStart);
        $last = new DateTimeImmutable($periodEnd);
        $guard = 0;
        while ($day <= $last && $guard < 800) {
            $key = $day->format('Y-m');
            $months[$key] = ($months[$key] ?? 0) + 1;
            $day = $day->modify('+1 day');
            $guard++;
        }
        return $months;
    }
}

if (!function_exists('re_rent_cutoff_plan')) {
    /**
     * The cut-off journals an invoice should have. Empty when none are needed.
     *
     * @return list<array{reference_type:string,date:string,description:string,lines:list<array<string,mixed>>}>
     */
    function re_rent_cutoff_plan(PDO $conn, int $companyId, int $invoiceId, int $deferredAccountId): array
    {
        $inv = $conn->prepare("SELECT id, invoice_number, status FROM re_invoices WHERE id = ? AND company_id = ? LIMIT 1");
        $inv->execute([$invoiceId, $companyId]);
        $invoice = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || (string)$invoice['status'] === 'cancelled') {
            return [];
        }

        $ob = $conn->prepare("
            SELECT o.period_start, o.period_end
            FROM re_invoice_items ii
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
            WHERE ii.company_id = ? AND ii.invoice_id = ? AND o.obligation_type = 'rent'
              AND o.period_start IS NOT NULL AND o.period_end IS NOT NULL
            LIMIT 1
        ");
        $ob->execute([$companyId, $invoiceId]);
        $period = $ob->fetch(PDO::FETCH_ASSOC);
        if (!$period || (string)$period['period_end'] < (string)$period['period_start']) {
            return [];
        }

        $jh = $conn->prepare("
            SELECT id, journal_date
            FROM re_journal_headers
            WHERE company_id = ? AND reference_type = 'invoice' AND reference_id = ?
              AND journal_type = 'invoice' AND is_posted = 1 AND COALESCE(is_reversed, 0) = 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $jh->execute([$companyId, $invoiceId]);
        $journal = $jh->fetch(PDO::FETCH_ASSOC);
        if (!$journal || (string)$journal['journal_date'] < RE_RENT_CUTOFF_FROM) {
            return [];
        }
        $invoiceMonth = substr((string)$journal['journal_date'], 0, 7);

        $months = re_rent_cutoff_month_days((string)$period['period_start'], (string)$period['period_end']);
        $totalDays = array_sum($months);
        $laterMonths = array_filter($months, static fn($m) => $m > $invoiceMonth, ARRAY_FILTER_USE_KEY);
        if ($totalDays <= 0 || !$laterMonths) {
            return [];
        }

        // Revenue actually booked by the invoice journal, per income account.
        $rev = $conn->prepare("
            SELECT l.account_id, SUM(l.credit_amount - l.debit_amount) AS amount
            FROM re_journal_lines l
            JOIN re_chart_of_accounts a ON a.id = l.account_id
            WHERE l.journal_id = ? AND a.account_type = 'Income'
            GROUP BY l.account_id
            HAVING amount > 0
        ");
        $rev->execute([(int)$journal['id']]);
        $revenue = $rev->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$revenue) {
            return [];
        }

        $number = (string)$invoice['invoice_number'];
        $deferLines = [];
        $releases = [];
        foreach ($revenue as $r) {
            $accountId = (int)$r['account_id'];
            $amount = (float)$r['amount'];
            $deferred = 0.0;
            foreach ($laterMonths as $month => $days) {
                $part = round($amount * $days / $totalDays, 2);
                if ($part <= 0) {
                    continue;
                }
                $deferred += $part;
                $releases[$month][] = ['account_id' => $accountId, 'amount' => $part, 'days' => $days];
            }
            $deferred = round($deferred, 2);
            if ($deferred > 0) {
                $deferLines[] = ['account_id' => $accountId, 'debit' => $deferred, 'credit' => 0, 'description' => "Rent cut-off {$number}: later-month days deferred", 'reference' => $number];
            }
        }
        if (!$deferLines) {
            return [];
        }
        $deferTotal = round(array_sum(array_column($deferLines, 'debit')), 2);
        $deferLines[] = ['account_id' => $deferredAccountId, 'debit' => 0, 'credit' => $deferTotal, 'description' => "Rent cut-off {$number}: later-month days deferred", 'reference' => $number];

        $plan = [[
            'reference_type' => 'rent_cutoff',
            'date' => (string)$journal['journal_date'],
            'description' => "Rent month-end cut-off {$number} ({$period['period_start']} to {$period['period_end']})",
            'lines' => $deferLines,
        ]];
        foreach ($releases as $month => $parts) {
            $lines = [];
            $total = 0.0;
            foreach ($parts as $p) {
                $lines[] = ['account_id' => $p['account_id'], 'debit' => 0, 'credit' => $p['amount'], 'description' => "Rent cut-off {$number}: {$p['days']} of {$totalDays} days in {$month}", 'reference' => $number];
                $total += $p['amount'];
            }
            array_unshift($lines, ['account_id' => $deferredAccountId, 'debit' => round($total, 2), 'credit' => 0, 'description' => "Rent cut-off {$number}: released for {$month}", 'reference' => $number]);
            $plan[] = [
                'reference_type' => 'rent_cutoff_release:' . $month,
                'date' => $month . '-01',
                'description' => "Rent month-end cut-off {$number}: release for {$month}",
                'lines' => $lines,
            ];
        }
        return $plan;
    }
}

if (!function_exists('re_rent_cutoff_existing')) {
    /**
     * Live (posted, not reversed) cut-off journals for an invoice.
     *
     * @return list<array<string,mixed>>
     */
    function re_rent_cutoff_existing(PDO $conn, int $companyId, int $invoiceId): array
    {
        $stmt = $conn->prepare("
            SELECT id, reference_type, journal_date
            FROM re_journal_headers
            WHERE company_id = ? AND reference_id = ?
              AND (reference_type = 'rent_cutoff' OR reference_type LIKE 'rent_cutoff_release:%')
              AND journal_type = 'adjustment' AND is_posted = 1 AND COALESCE(is_reversed, 0) = 0
            ORDER BY id
        ");
        $stmt->execute([$companyId, $invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lineStmt = $conn->prepare("SELECT account_id, debit_amount, credit_amount FROM re_journal_lines WHERE journal_id = ? ORDER BY line_number");
        foreach ($rows as &$row) {
            $lineStmt->execute([(int)$row['id']]);
            $row['lines'] = $lineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('re_rent_cutoff_signature')) {
    function re_rent_cutoff_signature(array $journals, bool $fromPlan): string
    {
        $parts = [];
        foreach ($journals as $j) {
            $lines = [];
            foreach ($j['lines'] as $l) {
                $dr = $fromPlan ? (float)$l['debit'] : (float)$l['debit_amount'];
                $cr = $fromPlan ? (float)$l['credit'] : (float)$l['credit_amount'];
                $lines[] = (int)$l['account_id'] . ':' . number_format($dr, 2, '.', '') . ':' . number_format($cr, 2, '.', '');
            }
            sort($lines);
            $parts[] = $j['reference_type'] . '@' . ($fromPlan ? $j['date'] : $j['journal_date']) . '=' . implode(',', $lines);
        }
        sort($parts);
        return implode('|', $parts);
    }
}

if (!function_exists('re_rent_cutoff_sync_invoice')) {
    /**
     * Make an invoice's cut-off journals match what it needs now.
     *
     * @return array{success:bool,action:string,error?:string}
     */
    function re_rent_cutoff_sync_invoice(PDO $conn, int $companyId, int $invoiceId, ?int $userId = null, bool $dryRun = false): array
    {
        $account = re_rent_cutoff_account($conn, $companyId, !$dryRun) ?: ($dryRun ? ['id' => 0] : null);
        if (!$account) {
            return ['success' => false, 'action' => 'error', 'error' => 'Deferred Rent Income account (2430) could not be created.'];
        }
        $plan = re_rent_cutoff_plan($conn, $companyId, $invoiceId, (int)$account['id']);
        $existing = re_rent_cutoff_existing($conn, $companyId, $invoiceId);
        if (re_rent_cutoff_signature($plan, true) === re_rent_cutoff_signature($existing, false)) {
            return ['success' => true, 'action' => 'unchanged'];
        }
        $action = $existing ? ($plan ? 'replaced' : 'reversed') : 'posted';
        if ($dryRun) {
            return ['success' => true, 'action' => $action];
        }

        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }
        try {
            foreach ($existing as $j) {
                $rev = reverse_journal((int)$j['id'], 'Rent cut-off recalculated', $userId, (string)$j['journal_date']);
                if (empty($rev['success'])) {
                    throw new RuntimeException('Could not reverse cut-off journal #' . $j['id'] . ': ' . ($rev['error'] ?? 'unknown'));
                }
            }
            foreach ($plan as $j) {
                $res = create_and_post_journal($companyId, 'adjustment', $j['reference_type'], $invoiceId, $j['lines'], $j['description'], $j['date'], $userId);
                if (empty($res['success'])) {
                    throw new RuntimeException('Could not post cut-off journal: ' . ($res['error'] ?? 'unknown'));
                }
            }
            if ($startedHere) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'action' => 'error', 'error' => $e->getMessage()];
        }
        return ['success' => true, 'action' => $action];
    }
}

if (!function_exists('re_rent_cutoff_sync_company')) {
    /**
     * Sync every rent invoice that spans a month end, plus any invoice that
     * still carries cut-off journals (e.g. voided since).
     *
     * @return array<string,mixed>
     */
    function re_rent_cutoff_sync_company(PDO $conn, int $companyId, ?int $userId = null, bool $dryRun = false): array
    {
        $stmt = $conn->prepare("
            SELECT DISTINCT i.id
            FROM re_invoices i
            JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = ii.company_id
            WHERE i.company_id = ? AND o.obligation_type = 'rent'
              AND i.invoice_date >= DATE_SUB(?, INTERVAL 2 MONTH)
              AND DATE_FORMAT(o.period_start, '%Y-%m') <> DATE_FORMAT(o.period_end, '%Y-%m')
            UNION
            SELECT DISTINCT reference_id
            FROM re_journal_headers
            WHERE company_id = ? AND journal_type = 'adjustment' AND is_posted = 1 AND COALESCE(is_reversed, 0) = 0
              AND (reference_type = 'rent_cutoff' OR reference_type LIKE 'rent_cutoff_release:%')
        ");
        $stmt->execute([$companyId, RE_RENT_CUTOFF_FROM, $companyId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $stats = ['checked' => count($ids), 'unchanged' => 0, 'posted' => 0, 'replaced' => 0, 'reversed' => 0, 'error' => 0, 'errors' => []];
        foreach ($ids as $invoiceId) {
            $res = re_rent_cutoff_sync_invoice($conn, $companyId, $invoiceId, $userId, $dryRun);
            $stats[$res['action']] = ($stats[$res['action']] ?? 0) + 1;
            if (empty($res['success'])) {
                $stats['errors'][] = 'Invoice #' . $invoiceId . ': ' . ($res['error'] ?? 'unknown');
            }
        }
        return $stats;
    }
}
