<?php
declare(strict_types=1);

/**
 * Finance > Allocations: prepaid expenses and accrued revenue spread by month,
 * pending until approved, posted as Allocation Journal Vouchers; editing,
 * stopping, older schedules, from a supplier invoice, and alongside assets on
 * one Pending Approval list.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs migrations up to fin_allocation_journals.sql and two active companies.
 *
 *     php tests/fin_allocation_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';
require_once __DIR__ . '/../includes/fin_allocation_helper.php';
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
function refusal(callable $fn): string
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return '';
}

if (!fin_coa_table_ready($conn) || !fin_alc_ready($conn) || !fin_asset_ready($conn)) {
    echo "Run migrations/fin_fixed_assets.sql and fin_allocation_journals.sql first.\n";
    exit(1);
}
$companies = $conn->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
[$a, $b] = array_map('intval', $companies);

$conn->beginTransaction();
try {
    fin_test_clear_extras($conn, $a, $b);
    $conn->prepare("DELETE FROM fin_ledger_entries WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_voucher_history WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_vouchers WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("UPDATE fin_coa_accounts SET parent_id = NULL WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_coa_accounts WHERE company_id IN (?, ?)")->execute([$a, $b]);
    fin_test_load_chart($conn, $a);
    $idOf = static function (string $code) use ($conn, $a): int {
        $s = $conn->prepare("SELECT id FROM fin_coa_accounts WHERE company_id = ? AND code = ?");
        $s->execute([$a, $code]);
        return (int)$s->fetchColumn();
    };
    $prepaid = $idOf('1120003');
    $benefits = $idOf('5300005');
    $clients = $idOf('1120001');
    $sales = $idOf('4100001');
    $ins = ['kind' => 'prepaid', 'name' => 'Staff insurance', 'debit_account_id' => $benefits, 'credit_account_id' => $prepaid,
            'amount' => '12,000', 'months' => '12', 'start_date' => '2026-01-16', 'post_from' => '', 'notes' => ''];

    // 1. What is required.
    check('kind required', 'Choose Prepaid expense or Accrued revenue.', refusal(static fn() => fin_alc_prepare($conn, $a, array_merge($ins, ['kind' => 'x']))));
    check('two different accounts', 'The two accounts must be different.', refusal(static fn() => fin_alc_prepare($conn, $a, array_merge($ins, ['credit_account_id' => $benefits]))));
    check('amount required', 'Enter the amount to spread.', refusal(static fn() => fin_alc_prepare($conn, $a, array_merge($ins, ['amount' => '']))));
    check('months 1 to 120', true, str_contains(refusal(static fn() => fin_alc_prepare($conn, $a, array_merge($ins, ['months' => '121']))), '1 to 120'));
    check('expense account named for prepaid', 'Choose the expense account.', refusal(static fn() => fin_alc_prepare($conn, $a, array_merge($ins, ['debit_account_id' => 0]))));

    // 2. Insurance 12,000 from 16 Jan: Jan 16/31 days, then 1,000, Jan next year the rest.
    $sid = fin_alc_create($conn, $a, $ins, 3, '2026-04-02');
    $p = fin_auto_entries($conn, $a, 'pending', 'allocation', $sid);
    check('Jan-Mar due on 2 Apr', ['2026-01-31', '2026-02-28', '2026-03-31'], array_column($p, 'entry_date'));
    check('amounts', ['516.13', '1000.00', '1000.00'], array_column($p, 'amount'));
    check('narration', 'Prepaid expense Staff insurance - Jan 2026', $p[0]['narration']);
    $s = fin_alc_get($conn, $a, $sid);
    $sch = fin_alc_schedule($conn, $a, $s);
    check('13 calendar months, last takes the rest', [13, 483.87], [count($sch), $sch[12]['amount']]);

    // 3. Approve: Allocation Journal Vouchers.
    $made = fin_auto_approve($conn, $a, array_column($p, 'id'), 9);
    $v = fin_voucher_get($conn, $a, $made[0]);
    check('an AJV on the month end', ['aj', 'AJV-2026-0001', '2026-01-31', '516.13'], [$v['type'], $v['voucher_no'], $v['voucher_date'], $v['amount']]);
    check('Dr expense, Cr prepaid', [['5300005', '516.13', '0.00'], ['1120003', '0.00', '516.13']], array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit']], $v['lines']));
    check('totals', [0.0, 2516.13, 9483.87], array_values(array_intersect_key(fin_alc_totals($s, fin_alc_schedule($conn, $a, $s)), ['before' => 1, 'posted' => 1, 'left' => 1])));
    check('an AJV cannot be edited', true, str_contains(refusal(static fn() => fin_voucher_update($conn, $a, $made[0], ['date' => '2026-01-31'], 9)), 'monthly schedule'));
    fin_voucher_delete($conn, $a, $made[2], 9);
    check('deleting March sends it back', 'pending', fin_alc_schedule($conn, $a, $s)[2]['state']);

    // 4. Posted: only name and notes; stop on 15 Jun.
    fin_alc_update($conn, $a, $sid, array_merge($ins, ['name' => 'Staff insurance 2026', 'amount' => '1']), 9, '2026-04-02');
    check('renamed, amount kept', ['Staff insurance 2026', '12000.00'], [fin_alc_get($conn, $a, $sid)['name'], fin_alc_get($conn, $a, $sid)['amount']]);
    check('cannot delete once posted', true, str_contains(refusal(static fn() => fin_alc_delete($conn, $a, $sid)), 'are posted'));
    fin_alc_stop($conn, $a, $sid, '2026-06-15', 9, '2026-07-01');
    $p = fin_auto_entries($conn, $a, 'pending', 'allocation', $sid);
    check('Mar-May whole, June half, nothing after', [['2026-03-01', '1000.00'], ['2026-04-01', '1000.00'], ['2026-05-01', '1000.00'], ['2026-06-01', '500.00']], array_map(static fn($r) => [$r['period'], $r['amount']], $p));

    // 5. Accrued revenue, older: started Oct 2025, the system posts from Jan 2026.
    $acc = fin_alc_create($conn, $a, ['kind' => 'accrued', 'name' => 'Service fee', 'debit_account_id' => $clients, 'credit_account_id' => $sales,
        'amount' => '6000', 'months' => '6', 'start_date' => '2025-10-01', 'post_from' => '2026-01', 'notes' => ''], 3, '2026-04-02');
    $sa = fin_alc_get($conn, $a, $acc);
    check('Jan-Mar made, Oct-Dec before the system', [['2026-01-01', '2026-02-01', '2026-03-01'], 3000.0], [array_column(fin_auto_entries($conn, $a, 'pending', 'allocation', $acc), 'period'), fin_alc_totals($sa, fin_alc_schedule($conn, $a, $sa))['before']]);
    $made = fin_auto_approve($conn, $a, [fin_auto_entries($conn, $a, 'pending', 'allocation', $acc)[0]['id']], 9);
    check('Dr accrued revenue, Cr revenue', [['1120001', '1000.00', '0.00'], ['4100001', '0.00', '1000.00']], array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit']], fin_voucher_get($conn, $a, $made[0])['lines']));

    // 6. Before anything is posted: full edit, months made again; delete.
    $tmp = fin_alc_create($conn, $a, array_merge($ins, ['name' => 'Licence', 'amount' => '2400', 'start_date' => '2026-01-01']), 3, '2026-04-02');
    fin_alc_update($conn, $a, $tmp, array_merge($ins, ['name' => 'Licence', 'amount' => '3600', 'start_date' => '2026-01-01']), 3, '2026-04-02');
    check('remade at 300', ['300.00', '300.00', '300.00'], array_column(fin_auto_entries($conn, $a, 'pending', 'allocation', $tmp), 'amount'));
    fin_alc_delete($conn, $a, $tmp);
    check('deleted with its months', [null, []], [fin_alc_get($conn, $a, $tmp), fin_auto_entries($conn, $a, null, 'allocation', $tmp)]);

    // 7. Assets and allocations share Pending Approval; each posts as its own kind.
    fin_asset_create($conn, $a, ['name' => 'Laptop', 'asset_account_id' => $idOf('1210001'), 'accum_account_id' => $idOf('1210003'), 'expense_account_id' => $idOf('5500001'),
        'cost' => '3600', 'life_months' => 36, 'start_date' => '2026-03-01', 'post_from' => '', 'notes' => ''], 3, '2026-04-02');
    $all = fin_auto_entries($conn, $a, 'pending');
    $kinds = array_values(array_unique(array_column($all, 'source_type')));
    sort($kinds);
    check('both kinds pending', ['allocation', 'asset'], $kinds);
    $mix = [];
    foreach ($all as $e) {
        $mix[$e['source_type']] ??= (int)$e['id'];
    }
    $made = fin_auto_approve($conn, $a, array_values($mix), 9);
    $types = array_map(static fn($id) => fin_voucher_get($conn, $a, $id)['type'], $made);
    sort($types);
    check('one approve, two kinds', ['aj', 'fj'], $types);
    check('source names on the list', true, in_array('Laptop', array_column(fin_auto_entries($conn, $a, null), 'source_name'), true) && in_array('Service fee', array_column(fin_auto_entries($conn, $a, null), 'source_name'), true));

    // 8. From a supplier invoice.
    fin_settings_save($conn, $a, ['si_payable' => $idOf('2110001'), 'si_vat_input' => $idOf('1120004')], 1);
    $conn->prepare("INSERT INTO re_vendors (company_id, vendor_name, status) VALUES (?, 'ZZ Test Insurer', 'active')")->execute([$a]);
    $insurer = (int)$conn->lastInsertId();
    $si = fin_si_create($conn, $a, ['vendor_id' => $insurer, 'invoice_no' => 'P-1', 'date' => '2026-07-01', 'narration' => 'Car insurance', 'vat_rate' => '5',
        'lines' => [['account_id' => $prepaid, 'amount' => '2400']]], [['/dev/null', 'inv.pdf', 'pdf', 1]], 1, static fn($f, $t) => true);
    $pre = fin_alc_from_invoice($conn, $a, $si);
    check('filled from the invoice', ['prepaid', 'Car insurance', $prepaid, '2400.00', '2026-07-01'], [$pre['kind'], $pre['name'], $pre['credit_account_id'], $pre['amount'], $pre['start_date']]);

    // 9. Another company sees none of it.
    check('other company: nothing', [[], null], [fin_alc_list($conn, $b), fin_alc_get($conn, $b, $sid)]);
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
