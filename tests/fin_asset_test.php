<?php
declare(strict_types=1);

/**
 * Finance > Fixed Assets: the month-by-month spread (part months by days), the
 * pending entries, approving them as Fixed Journal Vouchers, skipping, older
 * assets that start part-way, editing, stopping and deleting.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs migrations up to fin_fixed_assets.sql and two active companies.
 *
 *     php tests/fin_asset_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';
require_once __DIR__ . '/../includes/fin_asset_helper.php';
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

// 1. The spread, no database needed.
$s = fin_schedule_spread(60000, 60, '2026-04-16');
check('60 months from 16 Apr run into 61 calendar months', 61, count($s));
check('April is half a month', [15, 500.0], [$s[0]['days'], $s[0]['amount']]);
check('May is a full month', 1000.0, $s[1]['amount']);
check('the last month (Apr 2031) takes the other half', ['2031-04-01', 15, 500.0], [$s[60]['period'], $s[60]['days'], $s[60]['amount']]);
check('adds up to the cost', 60000.0, round(array_sum(array_column($s, 'amount')), 2));
check('entry dated the month end', '2026-04-30', $s[0]['date']);
$s = fin_schedule_spread(12000, 12, '2026-01-16');
check('16 Jan: 16 of 31 days', 516.13, $s[0]['amount']);
check('the remainder closes it exactly', [483.87, 12000.0], [$s[12]['amount'], round(array_sum(array_column($s, 'amount')), 2)]);
$s = fin_schedule_spread(5000, 12, '2026-04-01');
check('starting on the 1st: 12 whole months', [12, '2027-03-01'], [count($s), $s[11]['period']]);
check('cut on 15 Jun: June half, nothing after', [500.0, 1000.0, 500.0], array_column(fin_schedule_spread(60000, 60, '2026-04-16', '2026-06-15'), 'amount'));
check('31 Jan plus a month is 28 Feb', '2026-02-28', fin_add_months('2026-01-31', 1));
check('life label', ['5 years', '1 year 6 months', '7 months'], [fin_asset_life_label(60), fin_asset_life_label(18), fin_asset_life_label(7)]);

if (!fin_coa_table_ready($conn) || !fin_asset_ready($conn)) {
    echo "Run migrations/fin_fixed_assets.sql first.\n";
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
    $tangible = $idOf('1210001');
    $accum = $idOf('1210003');
    $depVeh = $idOf('5500006');
    $car = ['name' => 'Van', 'asset_account_id' => $tangible, 'accum_account_id' => $accum, 'expense_account_id' => $depVeh,
            'cost' => '60,000', 'life_months' => 60, 'start_date' => '2026-04-16', 'post_from' => '', 'notes' => ''];

    // 2. What is required.
    check('three different accounts', true, str_contains(refusal(static fn() => fin_asset_prepare($conn, $a, array_merge($car, ['accum_account_id' => $tangible]))), 'three different'));
    check('cost required', 'Enter the purchase cost.', refusal(static fn() => fin_asset_prepare($conn, $a, array_merge($car, ['cost' => '0']))));
    check('life required', 'Enter the useful life (1 month to 50 years).', refusal(static fn() => fin_asset_prepare($conn, $a, array_merge($car, ['life_months' => 0]))));
    check('first month inside the life', true, str_contains(refusal(static fn() => fin_asset_prepare($conn, $a, array_merge($car, ['post_from' => '2026-03']))), 'within the useful life'));
    check("another company's account refused", 'Choose the asset account.', refusal(static fn() => fin_asset_prepare($conn, $b, $car)));

    // 3. Added on 2 Nov 2026: April to October are due, as pending; nothing posted.
    $van = fin_asset_create($conn, $a, $car, 3, '2026-11-02');
    $pending = fin_auto_entries($conn, $a, 'pending', 'asset', $van);
    check('7 months due (Apr-Oct)', 7, count($pending));
    check('first pending: 30 Apr, 500', ['2026-04-30', '500.00'], [$pending[0]['entry_date'], $pending[0]['amount']]);
    check('narration', 'Depreciation Van - Apr 2026', $pending[0]['narration']);
    check('nothing in the ledger yet', 0, (int)$conn->query("SELECT COUNT(*) FROM fin_vouchers WHERE company_id = $a AND type = 'fj'")->fetchColumn());
    check('making again adds nothing', 0, fin_auto_generate($conn, $a, '2026-11-02'));
    check('November comes due on its last day', 1, fin_auto_generate($conn, $a, '2026-11-30'));

    // 4. Approve April and May.
    $made = fin_auto_approve($conn, $a, [$pending[0]['id'], $pending[1]['id']], 9);
    check('two vouchers', 2, count($made));
    $v = fin_voucher_get($conn, $a, $made[0]);
    check('a Fixed Journal Voucher on the month end', ['fj', 'FJV-2026-0001', '2026-04-30', '500.00'], [$v['type'], $v['voucher_no'], $v['voucher_date'], $v['amount']]);
    check('Dr depreciation, Cr accumulated', [['5500006', '500.00', '0.00'], ['1210003', '0.00', '500.00']], array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit']], $v['lines']));
    check('posted by the approver', 9, (int)$v['created_by']);
    check('approving again refused', true, str_contains(refusal(static fn() => fin_auto_approve($conn, $a, [$pending[0]['id']], 9)), 'no longer pending'));
    check('nothing ticked refused', 'Tick the entries to approve.', refusal(static fn() => fin_auto_approve($conn, $a, [], 9)));
    check('another company cannot approve', true, refusal(static fn() => fin_auto_approve($conn, $b, [$pending[2]['id']], 9)) !== '');
    $tb = array_column(fin_trial_balance($conn, $a, '2026-04-01', '2026-05-31')['rows'], null, 'code');
    check('accumulated depreciation 1,500', 1500.0, (float)$tb['1210003']['period_credit']);
    $sched = fin_asset_schedule($conn, $a, fin_asset_get($conn, $a, $van));
    check('schedule states', ['posted', 'posted', 'pending', 'pending'], array_column(array_slice($sched, 0, 4), 'state'));
    check('later months not made yet', 'due later', $sched[12]['state']);
    $t = fin_asset_totals(fin_asset_get($conn, $a, $van), $sched);
    check('totals', [0.0, 1500.0, 58500.0, 1000.0], [$t['before'], $t['posted'], $t['book_value'], $t['monthly']]);
    check('a scheduled entry cannot be edited', true, str_contains(refusal(static fn() => fin_voucher_update($conn, $a, $made[0], ['date' => '2026-04-30'], 9)), 'monthly schedule'));

    // 5. Skip June, bring it back.
    check('skip', 1, fin_auto_skip($conn, $a, [$pending[2]['id']], 9));
    check('skipped is not pending', 5, count(fin_auto_entries($conn, $a, 'pending', 'asset', $van)));
    check('restore', 1, fin_auto_skip($conn, $a, [$pending[2]['id']], 9, true));
    check('pending again', 6, count(fin_auto_entries($conn, $a, 'pending', 'asset', $van)));

    // 6. Deleting the posted voucher sends the month back.
    fin_voucher_delete($conn, $a, $made[1], 9);
    check('May back to pending', 'pending', fin_asset_schedule($conn, $a, fin_asset_get($conn, $a, $van))[1]['state']);

    // 7. Once posted, only name and notes change.
    fin_asset_update($conn, $a, $van, array_merge($car, ['name' => 'Van 12345', 'cost' => '1']), 9, '2026-11-02');
    $g = fin_asset_get($conn, $a, $van);
    check('renamed, cost kept', ['Van 12345', '60000.00'], [$g['name'], $g['cost']]);
    check('cannot delete with a posted month', true, str_contains(refusal(static fn() => fin_asset_delete($conn, $a, $van)), 'are posted'));

    // 8. Stop on 15 Jun: June counts half, nothing after.
    check('cannot stop before a posted month', true, str_contains(refusal(static fn() => fin_asset_stop($conn, $a, $van, '2026-04-20', 9, '2026-11-02')), 'already posted'));
    fin_asset_stop($conn, $a, $van, '2026-06-15', 9, '2026-11-02');
    $p = fin_auto_entries($conn, $a, 'pending', 'asset', $van);
    check('May and a half June left', [['2026-05-01', '1000.00'], ['2026-06-01', '500.00']], array_map(static fn($r) => [$r['period'], $r['amount']], $p));
    $stopped = fin_asset_schedule($conn, $a, fin_asset_get($conn, $a, $van));
    check('stopped schedule ends in June', '2026-06-01', $stopped[count($stopped) - 1]['period']);
    fin_asset_stop($conn, $a, $van, null, 9, '2026-11-02');
    check('started again: May-Oct pending again', 6, count(fin_auto_entries($conn, $a, 'pending', 'asset', $van)));

    // 9. An older asset: bought Jan 2024, the system takes over from Jan 2026.
    $old = fin_asset_create($conn, $a, array_merge($car, ['name' => 'Laptop', 'cost' => '3600', 'life_months' => 36, 'start_date' => '2024-01-01', 'post_from' => '2026-01']), 3, '2026-03-15');
    $og = fin_asset_get($conn, $a, $old);
    $os = fin_asset_schedule($conn, $a, $og);
    check('only Jan and Feb 2026 made', ['2026-01-01', '2026-02-01'], array_column(fin_auto_entries($conn, $a, 'pending', 'asset', $old), 'period'));
    check('2024-2025 count as before the system', [2400.0, 1200.0], [fin_asset_totals($og, $os)['before'], fin_asset_totals($og, $os)['book_value']]);

    // 10. Before anything is posted, everything can change and the months are made again.
    fin_asset_update($conn, $a, $old, array_merge($car, ['name' => 'Laptop', 'cost' => '7200', 'life_months' => 36, 'start_date' => '2024-01-01', 'post_from' => '2026-01']), 3, '2026-03-15');
    check('pending remade at 200 a month', ['200.00', '200.00'], array_column(fin_auto_entries($conn, $a, 'pending', 'asset', $old), 'amount'));
    fin_asset_delete($conn, $a, $old);
    check('deleted with its months', [null, 0], [fin_asset_get($conn, $a, $old), count(fin_auto_entries($conn, $a, null, 'asset', $old))]);

    // 11. From a supplier invoice.
    fin_settings_save($conn, $a, ['si_payable' => $idOf('2110001'), 'si_vat_input' => $idOf('1120004')], 1);
    $conn->prepare("INSERT INTO re_vendors (company_id, vendor_name, status) VALUES (?, 'ZZ Test Motors', 'active')")->execute([$a]);
    $motors = (int)$conn->lastInsertId();
    $si = fin_si_create($conn, $a, ['vendor_id' => $motors, 'invoice_no' => 'M-1', 'date' => '2026-09-10', 'narration' => 'Pickup truck', 'vat_rate' => '5',
        'lines' => [['account_id' => $tangible, 'amount' => '80000']]], [['/dev/null', 'inv.pdf', 'pdf', 1]], 1, static fn($f, $t) => true);
    $pre = fin_asset_from_invoice($conn, $a, $si);
    check('filled from the invoice', ['Pickup truck', $tangible, '80000.00', '2026-09-10'], [$pre['name'], $pre['asset_account_id'], $pre['cost'], $pre['start_date']]);
    $truck = fin_asset_create($conn, $a, array_merge($car, $pre, ['life_months' => 60, 'post_from' => '']), 3, '2026-11-02');
    check('linked to the invoice', [['id' => $truck, 'name' => 'Pickup truck']], array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], fin_assets_of_invoice($conn, $a, $si)));
    check('the account pair is suggested next time', [$tangible => [$accum, $depVeh]], fin_asset_last_accounts($conn, $a));
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
