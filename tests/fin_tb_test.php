<?php
declare(strict_types=1);

/**
 * Finance > Trial Balance for the new chart.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql,
 * fin_vouchers.sql and two active companies.
 *
 *     php tests/fin_tb_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';
require_once __DIR__ . '/fin_fixture.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $what, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "ok   - {$what}\n";
        return;
    }
    $failures++;
    echo "FAIL - {$what}\n       expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}
function refused(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}

// 1. No database needed.
check('whole month label', 'Jan 2026', fin_tb_period_label('2026-01-01', '2026-01-31'));
check('part month label', '05 Jan 2026 - 20 Jan 2026', fin_tb_period_label('2026-01-05', '2026-01-20'));
check('real date accepted', true, fin_tb_valid_date('2026-02-28'));
check('impossible date refused', false, fin_tb_valid_date('2026-02-30'));
check('text refused', false, fin_tb_valid_date('January'));
foreach (fin_test_journals() as [$date, $desc, $lines]) {
    $net = 0.0;
    foreach ($lines as [$code, $debit, $credit]) {
        $net += $debit - $credit;
    }
    if (abs($net) > 0.004) {
        check("sample journal balances: {$desc} {$date}", 0.0, round($net, 2));
    }
}
echo "ok   - every sample journal balances\n";

if (!fin_coa_table_ready($conn) || !fin_ledger_table_ready($conn)) {
    echo "Run migrations/fin_chart_of_accounts.sql and migrations/fin_ledger_entries.sql first.\n";
    exit(1);
}
$companies = $conn->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($companies) < 2) {
    echo "Needs two active companies.\n";
    exit(1);
}
[$a, $b] = array_map('intval', $companies);

$conn->beginTransaction();
try {
    $conn->prepare("DELETE FROM fin_ledger_entries WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_vouchers WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("UPDATE fin_coa_accounts SET parent_id = NULL WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_coa_accounts WHERE company_id IN (?, ?)")->execute([$a, $b]);

    // 2. A test chart in both companies, test journals in company A only.
    fin_test_load_chart($conn, $a);
    fin_test_load_chart($conn, $b);
    fin_test_load_journals($conn, $a);
    check('default period is the month of the latest entry', ['2026-02-01', '2026-02-28'], fin_tb_default_period($conn, $a));

    // 3. January 2026.
    $tb = fin_trial_balance($conn, $a, '2026-01-01', '2026-01-31');
    $r = array_column($tb['rows'], null, 'code');
    $t = $tb['totals'];

    check('bank: initial debit', 486320.75, $r['1112001']['initial_debit']);
    check('bank: initial credit', 0.0, $r['1112001']['initial_credit']);
    check('bank: period debit', 204500.0, $r['1112001']['period_debit']);
    check('bank: period credit', 117800.0, $r['1112001']['period_credit']);
    check('bank: end debit', 573020.75, $r['1112001']['end_debit']);
    check('bank: type is its heading', 'Bank accounts', $r['1112001']['type']);

    check('suppliers: initial on the credit side', 164300.25, $r['2110001']['initial_credit']);
    check('suppliers: end credit', 147600.25, $r['2110001']['end_credit']);
    check('suppliers: end debit', 0.0, $r['2110001']['end_debit']);

    check('sales: no initial balance', 0.0, $r['4100001']['initial_credit']);
    check('sales: end credit', 84500.0, $r['4100001']['end_credit']);
    check('February sale is outside January', 84500.0, $r['4100001']['period_credit']);

    check('untouched account stays at zero', 0.0, $r['1113001']['end_debit']);
    check('untouched account has no activity', false, $r['1113001']['has_activity']);
    check('used account has activity', true, $r['1112001']['has_activity']);

    // 4. Headings add up the columns of what is under them.
    check('cash & banks initial debit', 498820.75, $r['1110000']['initial_debit']);
    check('cash & banks end debit', 583680.25, $r['1110000']['end_debit']);
    check('fixed assets shows both sides: debit', 380000.0, $r['1210000']['end_debit']);
    check('fixed assets shows both sides: credit', 98950.0, $r['1210000']['end_credit']);
    check('assets class end debit', 1324480.75, $r['1000000']['end_debit']);
    check('heading with activity below it is active', true, $r['1000000']['has_activity']);
    check('heading with nothing below it is not', false, $r['9000000']['has_activity']);

    // 5. Totals balance in every pair.
    check('initial balances', $t['initial_debit'], $t['initial_credit']);
    check('period balances', $t['period_debit'], $t['period_credit']);
    check('end balances', $t['end_debit'], $t['end_credit']);
    check('initial total', 1334971.25, $t['initial_debit']);
    check('period total', 512440.5, $t['period_debit']);
    $classes = array_filter($tb['rows'], static fn($x) => $x['level'] === 'class');
    check('class rows add up to the total', $t['end_debit'], round(array_sum(array_column($classes, 'end_debit')), 2));

    // 6. Other periods.
    $feb = array_column(fin_trial_balance($conn, $a, '2026-02-01', '2026-02-28')['rows'], null, 'code');
    check('February starts where January ended', 573020.75, $feb['1112001']['initial_debit']);
    check('February bank end', 588020.75, $feb['1112001']['end_debit']);
    $dec = fin_trial_balance($conn, $a, '2025-12-01', '2025-12-31');
    check('December has no initial balance', 0.0, $dec['totals']['initial_debit']);
    check('December period holds the opening journal', 1334971.25, $dec['totals']['period_debit']);
    $early = fin_trial_balance($conn, $a, '2025-01-01', '2025-01-31');
    check('a period before any entry is all zero', 0.0, $early['totals']['end_debit'] + $early['totals']['period_debit']);

    // 7. Companies do not see each other's entries.
    $other = fin_trial_balance($conn, $b, '2026-01-01', '2026-01-31');
    check('company B has the accounts', count($tb['rows']), count($other['rows']));
    check('company B has no figures', 0.0, $other['totals']['end_debit'] + $other['totals']['period_debit']);

    // 7b. Balance sheet as at 31 January 2026.
    $bs = fin_balance_sheet($conn, $a, '2026-01-31');
    $assets = array_column($bs['sections']['assets'], null, 'code');
    $liab = array_column($bs['sections']['liabilities'], null, 'code');
    check('bs: total assets', 1225530.75, $bs['totals']['assets']);
    check('bs: total liabilities', 420271.25, $bs['totals']['liabilities']);
    check('bs: equity before profit', 803000.0, $bs['totals']['equity']);
    check('bs: profit to date is revenue less expenses', 2259.5, $bs['totals']['profit']);
    check('bs: liabilities and equity', 1225530.75, $bs['totals']['liabilities_equity']);
    check('bs: it balances', 0.0, $bs['totals']['difference']);
    check('bs: bank', 573020.75, $assets['1112001']['amount']);
    check('bs: accumulated depreciation reduces the assets', -98950.0, $assets['1210003']['amount']);
    check('bs: a heading adds up what is under it', 583680.25, $assets['1110000']['amount']);
    check('bs: suppliers shown as a positive liability', 147600.25, $liab['2110001']['amount']);
    check('bs: revenue and expense accounts are not listed', [], array_values(array_filter(
        array_merge(...array_values($bs['sections'])), static fn($r) => in_array($r['code'][0], ['4', '5'], true)
    )));
    $early = fin_balance_sheet($conn, $a, '2025-12-31');
    check('bs: on the opening date there is no profit yet', 0.0, $early['totals']['profit']);
    check('bs: and it balances', 0.0, $early['totals']['difference']);
    check('bs: company B is all zero', 0.0, fin_balance_sheet($conn, $b, '2026-01-31')['totals']['assets']);

    // 7c. Profit and loss.
    $pl = fin_profit_loss($conn, $a, '2026-01-01', '2026-01-31');
    $rev = array_column($pl['sections']['revenue'], null, 'code');
    $exp = array_column($pl['sections']['expenses'], null, 'code');
    check('pl: January revenue, returns taken off', 128600.0, $pl['totals']['revenue']);
    check('pl: January expenses', 126340.5, $pl['totals']['expenses']);
    check('pl: January profit', 2259.5, $pl['totals']['profit']);
    check('pl: sales returns reduce revenue', -2100.0, $rev['4100005']['amount']);
    check('pl: a heading adds up what is under it', 44640.5, $exp['5300000']['amount']);
    check('pl: balance sheet accounts are not listed', [], array_values(array_filter(
        array_merge(...array_values($pl['sections'])), static fn($r) => !in_array($r['code'][0], ['4', '5'], true)
    )));
    check('pl: the first quarter adds the February sale', 143600.0, fin_profit_loss($conn, $a, '2026-01-01', '2026-03-31')['totals']['revenue']);
    check('pl: February alone', 15000.0, fin_profit_loss($conn, $a, '2026-02-01', '2026-02-28')['totals']['profit']);
    check('pl: the year matches profit to date on the balance sheet', fin_balance_sheet($conn, $a, '2026-12-31')['totals']['profit'], fin_profit_loss($conn, $a, '2026-01-01', '2026-12-31')['totals']['profit']);
    check('pl: nothing before the first entry', 0.0, fin_profit_loss($conn, $a, '2025-01-01', '2025-12-31')['totals']['profit']);

    // 7d. Report periods.
    $p = static fn(array $q): array => array_intersect_key(fin_report_period($q, '2026-10-09'), array_flip(['period', 'from', 'to', 'error']));
    check('period: no filter is the month of the fallback', ['period' => 'month', 'error' => '', 'from' => '2026-10-01', 'to' => '2026-10-31'], $p([]));
    check('period: a month', ['period' => 'month', 'error' => '', 'from' => '2026-02-01', 'to' => '2026-02-28'], $p(['period' => 'month', 'month' => '2026-02']));
    check('period: a quarter', ['period' => 'quarter', 'error' => '', 'from' => '2026-04-01', 'to' => '2026-06-30'], $p(['period' => 'quarter', 'year' => '2026', 'q' => '2']));
    check('period: the last quarter', ['period' => 'quarter', 'error' => '', 'from' => '2025-10-01', 'to' => '2025-12-31'], $p(['period' => 'quarter', 'year' => '2025', 'q' => '4']));
    check('period: a quarter with no year is the current one', ['period' => 'quarter', 'error' => '', 'from' => '2026-10-01', 'to' => '2026-12-31'], $p(['period' => 'quarter']));
    check('period: a year', ['period' => 'year', 'error' => '', 'from' => '2025-01-01', 'to' => '2025-12-31'], $p(['period' => 'year', 'year' => '2025']));
    check('period: custom dates', ['period' => 'custom', 'error' => '', 'from' => '2026-01-15', 'to' => '2026-03-10'], $p(['period' => 'custom', 'from' => '2026-01-15', 'to' => '2026-03-10']));
    check('period: custom dates the wrong way round fall back', '2026-10-01', $p(['period' => 'custom', 'from' => '2026-03-10', 'to' => '2026-01-15'])['from']);
    check('period: and say so', true, $p(['period' => 'custom', 'from' => '2026-03-10', 'to' => '2026-01-15'])['error'] !== '');
    check('period: a fifth quarter is refused', true, $p(['period' => 'quarter', 'year' => '2026', 'q' => '5'])['error'] !== '');
    check('period: a bad month is refused', true, $p(['period' => 'month', 'month' => '2026-13'])['error'] !== '');

    // 7e. General ledger for January 2026.
    $gl = fin_general_ledger($conn, $a, '2026-01-01', '2026-01-31');
    $g = array_column($gl['accounts'], null, 'code');
    $jan = fin_trial_balance($conn, $a, '2026-01-01', '2026-01-31');
    $janRows = array_column($jan['rows'], null, 'code');
    check('gl: debits of the period match the trial balance', $jan['totals']['period_debit'], $gl['totals']['debit']);
    check('gl: and equal the credits', $gl['totals']['debit'], $gl['totals']['credit']);
    check('gl: bank opening', 486320.75, $g['1112001']['opening']);
    check('gl: bank has its four January entries', 4, count($g['1112001']['lines']));
    check('gl: bank running balance ends at the closing', 573020.75, $g['1112001']['lines'][3]['balance']);
    check('gl: bank closing', 573020.75, $g['1112001']['closing']);
    check('gl: capital has no entries but is listed for its balance', [0, -500000.0], [count($g['3100001']['lines']), $g['3100001']['closing']]);
    check('gl: an account with nothing is left out', false, isset($g['1111002']));
    check('gl: headings are not listed', false, isset($g['1110000']));
    $glCodes = array_column($gl['accounts'], 'code');
    $glSorted = $glCodes;
    sort($glSorted, SORT_STRING);
    check('gl: accounts come in code order', $glSorted, $glCodes);
    $cashBanks = fin_general_ledger($conn, $a, '2026-01-01', '2026-01-31', (int)$janRows['1110000']['id']);
    check('gl: under Cash & banks only its accounts show', ['1111001', '1112001'], array_column($cashBanks['accounts'], 'code'));
    $oneAccount = fin_general_ledger($conn, $a, '2026-01-01', '2026-01-31', (int)$janRows['1112001']['id']);
    check('gl: one account on its own', ['1112001'], array_column($oneAccount['accounts'], 'code'));
    check('gl: company B has nothing', [], fin_general_ledger($conn, $b, '2026-01-01', '2026-01-31')['accounts']);

    // 8. A void voucher leaves the trial balance.
    $first = $conn->prepare("SELECT id FROM fin_vouchers WHERE company_id = ? ORDER BY voucher_date, id LIMIT 1");
    $first->execute([$a]);
    fin_voucher_delete($conn, $a, (int)$first->fetchColumn(), null);
    $after = fin_trial_balance($conn, $a, '2026-01-01', '2026-01-31');
    check('voided opening journal: no initial balance left', 0.0, $after['totals']['initial_debit']);
    check('the period is untouched', 512440.5, $after['totals']['period_debit']);
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
