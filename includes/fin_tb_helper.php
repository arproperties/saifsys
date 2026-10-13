<?php
/**
 * Finance > Trial Balance for the new chart (fin_coa_accounts).
 *
 * Reads fin_ledger_entries only. For a period it gives every account three
 * pairs of figures: the balance before the period, the debits and credits
 * inside it, and the balance at its end. Headings add up the columns of the
 * accounts under them, so a heading can show a debit and a credit side at once.
 */

require_once __DIR__ . '/fin_coa_helper.php';
require_once __DIR__ . '/fin_voucher_helper.php';

const FIN_TB_COLUMNS = ['initial_debit', 'initial_credit', 'period_debit', 'period_credit', 'end_debit', 'end_credit'];

function fin_ledger_table_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_ledger_entries'");
    $chk->execute();
    return (int)$chk->fetchColumn() > 0;
}

function fin_tb_valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** First and last day of the month holding the company's latest entry, or of this month. */
function fin_tb_default_period(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("SELECT MAX(entry_date) FROM fin_ledger_entries WHERE company_id = ? AND is_void = 0");
    $stmt->execute([$companyId]);
    $last = $stmt->fetchColumn() ?: date('Y-m-d');
    return [date('Y-m-01', strtotime($last)), date('Y-m-t', strtotime($last))];
}

/** "Jan 2026" for a whole month, otherwise "05 Jan 2026 - 20 Jan 2026". */
function fin_tb_period_label(string $from, string $to): string
{
    if ($from === date('Y-m-01', strtotime($from)) && $to === date('Y-m-t', strtotime($from))) {
        return date('M Y', strtotime($from));
    }
    return date('d M Y', strtotime($from)) . ' - ' . date('d M Y', strtotime($to));
}

/**
 * Trial balance for one company and period (both dates included).
 * Returns ['rows' => chart rows in tree order with the six figures, a `type`
 * and `has_activity`, 'totals' => the six figures over all accounts with entries].
 */
function fin_trial_balance(PDO $conn, int $companyId, string $from, string $to): array
{
    $stmt = $conn->prepare("
        SELECT account_id,
               SUM(CASE WHEN entry_date < ? THEN debit - credit ELSE 0 END) AS initial_net,
               SUM(CASE WHEN entry_date >= ? THEN debit ELSE 0 END) AS period_debit,
               SUM(CASE WHEN entry_date >= ? THEN credit ELSE 0 END) AS period_credit
          FROM fin_ledger_entries
         WHERE company_id = ? AND is_void = 0 AND entry_date <= ?
         GROUP BY account_id
    ");
    $stmt->execute([$from, $from, $from, $companyId, $to]);
    $sums = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sums[(int)$s['account_id']] = $s;
    }

    $rows = fin_coa_accounts($conn, $companyId);
    $byId = [];
    foreach ($rows as $i => $r) {
        $byId[(int)$r['id']] = $i;
        foreach (FIN_TB_COLUMNS as $c) {
            $rows[$i][$c] = 0.0;
        }
        $rows[$i]['has_activity'] = false;
        $rows[$i]['type'] = '';
    }

    $totals = array_fill_keys(FIN_TB_COLUMNS, 0.0);
    foreach ($rows as $i => $r) {
        if ($r['is_leaf']) {
            $parentIdx = $r['parent_id'] !== null ? ($byId[(int)$r['parent_id']] ?? null) : null;
            $rows[$i]['type'] = $parentIdx !== null ? $rows[$parentIdx]['name'] : '';
        }

        // Normally only last-level accounts hold entries, but an account posted to
        // before others were added under it keeps its own figures.
        $s = $sums[(int)$r['id']] ?? null;
        if (!$s) {
            continue;
        }
        $initial = round((float)$s['initial_net'], 2);
        $pd = round((float)$s['period_debit'], 2);
        $pc = round((float)$s['period_credit'], 2);
        $end = round($initial + $pd - $pc, 2);
        $vals = [
            'initial_debit'  => $initial > 0 ? $initial : 0.0,
            'initial_credit' => $initial < 0 ? -$initial : 0.0,
            'period_debit'   => $pd,
            'period_credit'  => $pc,
            'end_debit'      => $end > 0 ? $end : 0.0,
            'end_credit'     => $end < 0 ? -$end : 0.0,
        ];

        // The account itself, then every heading above it.
        $idx = $i;
        $guard = 0;
        while ($idx !== null && $guard++ < 10) {
            foreach ($vals as $c => $v) {
                $rows[$idx][$c] = round($rows[$idx][$c] + $v, 2);
            }
            $rows[$idx]['has_activity'] = true;
            $pid = $rows[$idx]['parent_id'];
            $idx = $pid !== null ? ($byId[(int)$pid] ?? null) : null;
        }
        foreach ($vals as $c => $v) {
            $totals[$c] = round($totals[$c] + $v, 2);
        }
    }

    return ['rows' => $rows, 'totals' => $totals];
}

/**
 * Period a report was asked for, from its filter: `period` is month (with
 * `month` = 2026-10), quarter (`year`, `q` = 1 to 4), year (`year`) or custom
 * (`from`, `to`). Anything missing or wrong falls back to the month of
 * $fallback (a Y-m-d date) and sets an error.
 * Returns ['period', 'from', 'to', 'month', 'year', 'q', 'error'].
 */
function fin_report_period(array $q, string $fallback): array
{
    $period = (string)($q['period'] ?? 'month');
    $base = strtotime($fallback);
    $out = [
        'period' => in_array($period, ['month', 'quarter', 'year', 'custom'], true) ? $period : 'month',
        'month'  => date('Y-m', $base),
        'year'   => (int)date('Y', $base),
        'q'      => (int)ceil((int)date('n', $base) / 3),
        'error'  => '',
    ];
    $year = (int)($q['year'] ?? 0);
    $yearOk = $year >= 1990 && $year <= 2100;

    if ($out['period'] === 'month' && isset($q['month'])) {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$q['month'])) {
            $out['month'] = (string)$q['month'];
        } else {
            $out['error'] = 'Choose a valid month.';
        }
    } elseif ($out['period'] === 'quarter' && (isset($q['year']) || isset($q['q']))) {
        $quarter = (int)($q['q'] ?? 0);
        if ($yearOk && $quarter >= 1 && $quarter <= 4) {
            [$out['year'], $out['q']] = [$year, $quarter];
        } else {
            $out['error'] = 'Choose a valid year and quarter.';
        }
    } elseif ($out['period'] === 'year' && isset($q['year'])) {
        if ($yearOk) {
            $out['year'] = $year;
        } else {
            $out['error'] = 'Choose a valid year.';
        }
    }

    if ($out['period'] === 'custom') {
        $from = (string)($q['from'] ?? '');
        $to = (string)($q['to'] ?? '');
        if (fin_tb_valid_date($from) && fin_tb_valid_date($to) && $from <= $to) {
            return $out + ['from' => $from, 'to' => $to];
        }
        $out['error'] = 'Choose a valid period: the From date must be on or before the To date.';
        $out['period'] = 'month';
    }
    if ($out['error'] !== '') {
        $out['period'] = 'month';
    }
    if ($out['period'] === 'quarter') {
        $first = sprintf('%04d-%02d-01', $out['year'], ($out['q'] - 1) * 3 + 1);
        return $out + ['from' => $first, 'to' => date('Y-m-t', strtotime($first . ' +2 months'))];
    }
    if ($out['period'] === 'year') {
        return $out + ['from' => $out['year'] . '-01-01', 'to' => $out['year'] . '-12-31'];
    }
    return $out + ['from' => $out['month'] . '-01', 'to' => date('Y-m-t', strtotime($out['month'] . '-01'))];
}

/**
 * Profit and loss of one company for a period (both dates included).
 *
 * Accounts are placed by the first digit of their code: 4 revenue, 5 expenses.
 * Returns ['sections' => revenue and expenses, each the chart rows in tree order
 * with an `amount` (positive on the side the section normally sits on),
 * 'totals' => revenue, expenses and profit (negative for a loss)].
 */
function fin_profit_loss(PDO $conn, int $companyId, string $from, string $to): array
{
    $sectionOf = ['4' => 'revenue', '5' => 'expenses'];
    $sections = ['revenue' => [], 'expenses' => []];
    $totals = ['revenue' => 0.0, 'expenses' => 0.0];

    foreach (fin_trial_balance($conn, $companyId, $from, $to)['rows'] as $r) {
        $key = $sectionOf[$r['code'][0]] ?? null;
        if ($key === null) {
            continue;
        }
        $net = round($r['period_debit'] - $r['period_credit'], 2);
        $r['amount'] = $key === 'revenue' ? -$net : $net;
        $sections[$key][] = $r;
        // A row with nothing above it already carries everything under it.
        if ($r['parent_id'] === null) {
            $totals[$key] = round($totals[$key] + $r['amount'], 2);
        }
    }

    $totals['profit'] = round($totals['revenue'] - $totals['expenses'], 2);
    return ['sections' => $sections, 'totals' => $totals];
}

/**
 * General ledger of one company for a period (both dates included): every
 * account that has a balance brought forward or entries in the period, in code
 * order, each with its entries and a running balance.
 * $underId limits it to one account, or to the accounts under one heading.
 * Returns ['accounts' => each with code, name, id, 'opening', 'lines' (date,
 * voucher, description, debit, credit, running `balance`), 'debit', 'credit'
 * and 'closing', 'totals' => debit and credit of the period over all of them].
 * Balances are debit-positive.
 */
function fin_general_ledger(PDO $conn, int $companyId, string $from, string $to, ?int $underId = null): array
{
    $chart = fin_coa_accounts($conn, $companyId);

    // The account asked for and everything under it. The chart is in tree order,
    // so a parent is always seen before its children.
    $wanted = null;
    if ($underId) {
        $wanted = [$underId => true];
        foreach ($chart as $r) {
            if ($r['parent_id'] !== null && isset($wanted[(int)$r['parent_id']])) {
                $wanted[(int)$r['id']] = true;
            }
        }
    }

    $stmt = $conn->prepare("SELECT account_id, SUM(debit - credit) FROM fin_ledger_entries WHERE company_id = ? AND is_void = 0 AND entry_date < ? GROUP BY account_id");
    $stmt->execute([$companyId, $from]);
    $opening = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $conn->prepare("
        SELECT e.account_id, e.entry_date, e.description, e.debit, e.credit,
               e.partner_type, e.partner_id, " . fin_partner_name_sql('e') . " AS partner_name,
               v.id AS voucher_id, v.voucher_no, v.type, v.party
          FROM fin_ledger_entries e
          JOIN fin_vouchers v ON v.id = e.voucher_id
         WHERE e.company_id = ? AND e.is_void = 0 AND e.entry_date BETWEEN ? AND ?
         ORDER BY e.entry_date, e.voucher_id, e.line_no, e.id
    ");
    $stmt->execute([$companyId, $from, $to]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $lines[(int)$l['account_id']][] = $l;
    }

    $accounts = [];
    $totals = ['debit' => 0.0, 'credit' => 0.0];
    foreach ($chart as $r) {
        $id = (int)$r['id'];
        if ($wanted !== null && !isset($wanted[$id])) {
            continue;
        }
        $open = round((float)($opening[$id] ?? 0), 2);
        $own = $lines[$id] ?? [];
        if (!$own && abs($open) < 0.005) {
            continue;
        }
        $balance = $open;
        $debit = $credit = 0.0;
        foreach ($own as $i => $l) {
            $balance = round($balance + (float)$l['debit'] - (float)$l['credit'], 2);
            $debit += (float)$l['debit'];
            $credit += (float)$l['credit'];
            $own[$i]['balance'] = $balance;
        }
        $accounts[] = [
            'id' => $id, 'code' => $r['code'], 'name' => $r['name'], 'is_active' => $r['is_active'],
            'opening' => $open, 'lines' => $own, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'closing' => $balance,
        ];
        $totals['debit'] = round($totals['debit'] + $debit, 2);
        $totals['credit'] = round($totals['credit'] + $credit, 2);
    }
    return ['accounts' => $accounts, 'totals' => $totals];
}

/**
 * Balance sheet of one company as at a date (that day included).
 *
 * Accounts are placed by the first digit of their code: 1 assets, 2 liabilities,
 * 3 equity. Revenue (4) less expenses (5) up to the date is the profit or loss
 * to date; the books are not closed into equity, so it is added to equity here
 * as one line of its own.
 * Returns ['sections' => assets, liabilities and equity, each the chart rows in
 * tree order with an `amount` (positive on the side the section normally sits
 * on), 'totals' => assets, liabilities, equity (without the profit), profit,
 * equity_total, liabilities_equity and difference (0 when the sheet balances)].
 */
function fin_balance_sheet(PDO $conn, int $companyId, string $asAt): array
{
    $sectionOf = ['1' => 'assets', '2' => 'liabilities', '3' => 'equity'];
    $sections = ['assets' => [], 'liabilities' => [], 'equity' => []];
    $totals = ['assets' => 0.0, 'liabilities' => 0.0, 'equity' => 0.0, 'profit' => 0.0];

    foreach (fin_trial_balance($conn, $companyId, $asAt, $asAt)['rows'] as $r) {
        $net = round($r['end_debit'] - $r['end_credit'], 2);
        $digit = $r['code'][0];
        // A row with nothing above it already carries everything under it.
        $isTop = $r['parent_id'] === null;
        if (isset($sectionOf[$digit])) {
            $key = $sectionOf[$digit];
            $r['amount'] = $digit === '1' ? $net : -$net;
            $sections[$key][] = $r;
            if ($isTop) {
                $totals[$key] = round($totals[$key] + $r['amount'], 2);
            }
        } elseif ($isTop && ($digit === '4' || $digit === '5')) {
            $totals['profit'] = round($totals['profit'] - $net, 2);
        }
    }

    $totals['equity_total'] = round($totals['equity'] + $totals['profit'], 2);
    $totals['liabilities_equity'] = round($totals['liabilities'] + $totals['equity_total'], 2);
    $totals['difference'] = round($totals['assets'] - $totals['liabilities_equity'], 2);
    return ['sections' => $sections, 'totals' => $totals];
}
