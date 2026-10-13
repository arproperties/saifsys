<?php
declare(strict_types=1);

/**
 * Finance > Vouchers for the new chart: journal, petty cash, payment, receipt,
 * and how they reach the account ledger and the trial balance.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql,
 * fin_vouchers.sql and two active companies.
 *
 *     php tests/fin_voucher_test.php
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
check('plain amount', 1250.5, fin_voucher_parse_amount('1250.50'));
check('amount with commas', 1250.5, fin_voucher_parse_amount('1,250.50'));
check('empty amount is zero', 0.0, fin_voucher_parse_amount(''));
check('text is not an amount', null, fin_voucher_parse_amount('abc'));
check('negative is not an amount', null, fin_voucher_parse_amount('-5'));
check('debit balance label', '1,250.00 Dr', fin_balance_label(1250.0));
check('credit balance label', '300.00 Cr', fin_balance_label(-300.0));
check('zero balance label', '0.00', fin_balance_label(0.0));

if (!fin_coa_table_ready($conn) || !fin_voucher_tables_ready($conn)) {
    echo "Run migrations/fin_chart_of_accounts.sql, fin_ledger_entries.sql, fin_vouchers.sql and fin_partners.sql first.\n";
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
    fin_test_load_chart($conn, $a);
    fin_test_load_chart($conn, $b);

    $idOf = static function (int $companyId, string $code) use ($conn): int {
        $s = $conn->prepare("SELECT id FROM fin_coa_accounts WHERE company_id = ? AND code = ?");
        $s->execute([$companyId, $code]);
        return (int)$s->fetchColumn();
    };
    $cash = $idOf($a, '1111001');
    $petty = $idOf($a, '1111002');
    $bank = $idOf($a, '1112001');
    $clients = $idOf($a, '1120001');
    $suppliers = $idOf($a, '2110001');
    $sales = $idOf($a, '4100001');
    $rent = $idOf($a, '5400001');
    $cleaning = $idOf($a, '5400004');
    $capital = $idOf($a, '3100001');
    $tbRow = static function (int $companyId, string $code) use ($conn): array {
        return array_column(fin_trial_balance($conn, $companyId, '2026-03-01', '2026-03-31')['rows'], null, 'code')[$code];
    };

    // 2. Which accounts money can move through.
    $codes = static fn(array $rows): array => array_column($rows, 'code');
    check('petty cash offers cash in hand only', ['1111001', '1111002'], $codes(fin_voucher_cash_accounts($conn, $a, 'pc')));
    check('payments offer cash and banks', ['1111001', '1111002', '1112001', '1113001'], $codes(fin_voucher_cash_accounts($conn, $a, 'pv')));
    check('receipts offer cash and banks', ['1111001', '1111002', '1112001', '1113001'], $codes(fin_voucher_cash_accounts($conn, $a, 'rv')));

    // 3. Journal voucher.
    $jv = fin_voucher_create($conn, $a, 'jv', ['date' => '2026-03-01', 'narration' => 'Capital introduced', 'lines' => [
        ['account_id' => $bank, 'description' => '', 'debit' => '100,000.00', 'credit' => ''],
        ['account_id' => $capital, 'description' => 'Owner', 'debit' => '', 'credit' => '100000'],
        ['account_id' => 0, 'description' => '', 'debit' => '', 'credit' => ''],
    ]], 7);
    $v = fin_voucher_get($conn, $a, $jv);
    check('jv number', 'JV-2026-0001', $v['voucher_no']);
    check('jv amount', '100000.00', $v['amount']);
    check('jv status', 'posted', $v['status']);
    check('jv keeps two lines, empty row dropped', 2, count($v['lines']));
    check('jv line shows the account', '1112001', $v['lines'][0]['code']);
    check('jv line falls back to the narration', 'Capital introduced', $v['lines'][0]['description']);
    check('jv line keeps its own description', 'Owner', $v['lines'][1]['description']);

    // 4. Payment voucher: lines are debited, the paid-from account is credited.
    $pv = fin_voucher_create($conn, $a, 'pv', ['date' => '2026-03-05', 'party' => 'Landlord LLC', 'reference' => 'CHQ 1001', 'narration' => 'March rent', 'cash_account_id' => $bank, 'lines' => [
        ['account_id' => $rent, 'description' => '', 'amount' => '12000'],
        ['account_id' => $suppliers, 'description' => 'Old bill', 'amount' => '3000.50'],
    ]]);
    $v = fin_voucher_get($conn, $a, $pv);
    check('pv number', 'PV-2026-0001', $v['voucher_no']);
    check('pv amount', '15000.50', $v['amount']);
    check('pv has three lines', 3, count($v['lines']));
    check('pv debits the expense', '12000.00', $v['lines'][0]['debit']);
    check('pv credits the bank last', ['1112001', '15000.50'], [$v['lines'][2]['code'], $v['lines'][2]['credit']]);
    check('pv party saved', 'Landlord LLC', $v['party']);

    // 5. Receipt voucher: the received-into account is debited, lines are credited.
    $rv = fin_voucher_create($conn, $a, 'rv', ['date' => '2026-03-08', 'party' => 'Walk-in client', 'cash_account_id' => $bank, 'lines' => [
        ['account_id' => $sales, 'description' => '', 'amount' => '8,400'],
    ]]);
    $v = fin_voucher_get($conn, $a, $rv);
    check('rv number', 'RV-2026-0001', $v['voucher_no']);
    check('rv debits the bank first', ['1112001', '8400.00'], [$v['lines'][0]['code'], $v['lines'][0]['debit']]);
    check('rv credits sales', ['4100001', '8400.00'], [$v['lines'][1]['code'], $v['lines'][1]['credit']]);

    // 6. Petty cash: paid from a cash-in-hand account.
    $pc = fin_voucher_create($conn, $a, 'pc', ['date' => '2026-03-10', 'party' => 'Cleaner', 'cash_account_id' => $petty, 'lines' => [
        ['account_id' => $cleaning, 'description' => 'Supplies', 'amount' => '75.25'],
    ]]);
    $v = fin_voucher_get($conn, $a, $pc);
    check('pc number', 'PC-2026-0001', $v['voucher_no']);
    check('pc credits petty cash', ['1111002', '75.25'], [$v['lines'][1]['code'], $v['lines'][1]['credit']]);
    check('petty cash cannot be paid from the bank', true, refused(static fn() => fin_voucher_create($conn, $a, 'pc', [
        'date' => '2026-03-10', 'cash_account_id' => $bank, 'lines' => [['account_id' => $cleaning, 'amount' => '10']],
    ])));

    // 7. Numbers run per kind and per year.
    $pv2 = fin_voucher_create($conn, $a, 'pv', ['date' => '2026-03-12', 'cash_account_id' => $cash, 'lines' => [['account_id' => $rent, 'amount' => '100']]]);
    check('second pv', 'PV-2026-0002', fin_voucher_get($conn, $a, $pv2)['voucher_no']);
    $pv3 = fin_voucher_create($conn, $a, 'pv', ['date' => '2027-01-02', 'cash_account_id' => $cash, 'lines' => [['account_id' => $rent, 'amount' => '100']]]);
    check('a new year starts at 0001', 'PV-2027-0001', fin_voucher_get($conn, $a, $pv3)['voucher_no']);

    // 8. They reach the trial balance.
    check('bank: 100000 + 8400 in', 108400.0, $tbRow($a, '1112001')['period_debit']);
    check('bank: 15000.50 out', 15000.5, $tbRow($a, '1112001')['period_credit']);
    check('bank: end balance', 93399.5, $tbRow($a, '1112001')['end_debit']);
    check('petty cash ends in credit', 75.25, $tbRow($a, '1111002')['end_credit']);
    check('rent expense', 12100.0, $tbRow($a, '5400001')['end_debit']);
    check('sales', 8400.0, $tbRow($a, '4100001')['end_credit']);
    $t = fin_trial_balance($conn, $a, '2026-03-01', '2026-03-31')['totals'];
    check('trial balance still balances', $t['end_debit'], $t['end_credit']);

    // 9. And the account ledger, with links back to the vouchers.
    $l = fin_account_ledger($conn, $a, $bank, '2026-03-01', '2026-03-31');
    check('ledger has three bank lines', 3, count($l['lines']));
    check('ledger lines in date order', ['JV-2026-0001', 'PV-2026-0001', 'RV-2026-0001'], array_column($l['lines'], 'voucher_no'));
    check('ledger line points at its voucher', $pv, (int)$l['lines'][1]['voucher_id']);
    check('running balance after the payment', 84999.5, $l['lines'][1]['balance']);
    check('ledger closing equals the trial balance', $tbRow($a, '1112001')['end_debit'], $l['closing']);
    check('ledger opening is zero', 0.0, $l['opening']);
    $l2 = fin_account_ledger($conn, $a, $bank, '2026-03-06', '2026-03-31');
    check('opening carries what came before', 84999.5, $l2['opening']);
    check('another company cannot open the account', null, fin_account_ledger($conn, $b, $bank, '2026-03-01', '2026-03-31'));

    // 10. Lists.
    check('pv list, newest first', ['PV-2027-0001', 'PV-2026-0002', 'PV-2026-0001'], array_column(fin_voucher_list($conn, $a, 'pv'), 'voucher_no'));
    check('pv list by period', ['PV-2026-0002', 'PV-2026-0001'], array_column(fin_voucher_list($conn, $a, 'pv', '2026-01-01', '2026-12-31'), 'voucher_no'));
    check('jv list holds only journals', ['JV-2026-0001'], array_column(fin_voucher_list($conn, $a, 'jv'), 'voucher_no'));
    check('company B sees none', [], fin_voucher_list($conn, $b, 'pv'));
    check('company B cannot open the voucher', null, fin_voucher_get($conn, $b, $pv));

    // 11. What gets refused.
    $bad = static fn(string $type, array $data): bool => refused(static fn() => fin_voucher_create($conn, $a, $type, $data));
    $line = ['account_id' => $rent, 'amount' => '10'];
    check('unbalanced journal', true, $bad('jv', ['date' => '2026-03-01', 'lines' => [
        ['account_id' => $bank, 'debit' => '100', 'credit' => ''], ['account_id' => $capital, 'debit' => '', 'credit' => '90'],
    ]]));
    check('journal with one line', true, $bad('jv', ['date' => '2026-03-01', 'lines' => [['account_id' => $bank, 'debit' => '100', 'credit' => '']]]));
    check('line with debit and credit', true, $bad('jv', ['date' => '2026-03-01', 'lines' => [
        ['account_id' => $bank, 'debit' => '100', 'credit' => '100'], ['account_id' => $capital, 'debit' => '', 'credit' => '100'],
    ]]));
    check('bad date', true, $bad('pv', ['date' => '2026-02-30', 'cash_account_id' => $bank, 'lines' => [$line]]));
    check('no lines', true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => []]));
    check('zero amount', true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => [['account_id' => $rent, 'amount' => '0']]]));
    check('amount that is not a number', true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => [['account_id' => $rent, 'amount' => '12x']]]));
    check('no paid-from account', true, $bad('pv', ['date' => '2026-03-01', 'lines' => [$line]]));
    check('paid from an account that is not cash or bank', true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $rent, 'lines' => [$line]]));
    check('line on the paid-from account itself', true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => [['account_id' => $bank, 'amount' => '10']]]));
    check('heading account', true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => [['account_id' => $idOf($a, '5400000'), 'amount' => '10']]]));
    check("another company's account", true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => [['account_id' => $idOf($b, '5400001'), 'amount' => '10']]]));
    check('unknown voucher type', true, $bad('xx', ['date' => '2026-03-01', 'lines' => [$line]]));
    fin_coa_update_account($conn, $a, $cleaning, 'Cleaning', 'debit', false);
    check('inactive account', true, $bad('pv', ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => [['account_id' => $cleaning, 'amount' => '10']]]));
    $count = $conn->prepare("SELECT COUNT(*) FROM fin_vouchers WHERE company_id = ?");
    $count->execute([$a]);
    check('refused vouchers leave nothing behind', 6, (int)$count->fetchColumn());

    // 11b. The last level of a branch takes entries, heading or not.
    $posting = static fn(): array => array_column(fin_voucher_ledger_accounts($conn, $a), 'code');
    $lone = fin_coa_add_account($conn, $a, '6000000', 'Other');
    $group = fin_coa_add_account($conn, $a, '6100000', 'Other group');
    check('a heading with accounts under it is not offered', false, in_array('6000000', $posting(), true));
    check('a heading with nothing under it is offered', true, in_array('6100000', $posting(), true));
    check('an account with headings above it is not a Level 1 choice', $lone, (int)array_column(fin_voucher_account_tree($conn, $a), 'parent_id', 'code')['6100000']);
    $hv = fin_voucher_create($conn, $a, 'jv', ['date' => '2026-03-25', 'lines' => [
        ['account_id' => $group, 'debit' => '250', 'credit' => ''], ['account_id' => $capital, 'debit' => '', 'credit' => '250'],
    ]]);
    check('an entry can be recorded on it', '6100000', fin_voucher_get($conn, $a, $hv)['lines'][0]['code']);
    check('it shows in the trial balance', 250.0, $tbRow($a, '6100000')['end_debit']);
    check('and rolls up to the heading above', 250.0, $tbRow($a, '6000000')['end_debit']);
    check('its ledger lists the entry', 1, count(fin_account_ledger($conn, $a, $group, '2026-03-01', '2026-03-31')['lines']));
    // An account added under it later takes over as the last level; what was posted stays.
    $under = fin_coa_add_account($conn, $a, '6100001', 'Other ledger');
    check('once it has an account under it, it is no longer offered', false, in_array('6100000', $posting(), true));
    check('the new last level is offered', true, in_array('6100001', $posting(), true));
    check('what was posted to it stays in the trial balance', 250.0, $tbRow($a, '6100000')['end_debit']);
    fin_voucher_create($conn, $a, 'jv', ['date' => '2026-03-26', 'lines' => [
        ['account_id' => $under, 'debit' => '40', 'credit' => ''], ['account_id' => $capital, 'debit' => '', 'credit' => '40'],
    ]]);
    check('heading shows its own entry plus the new account', 290.0, $tbRow($a, '6100000')['end_debit']);
    $t = fin_trial_balance($conn, $a, '2026-03-01', '2026-03-31')['totals'];
    check('trial balance total counts each entry once', $t['end_debit'], $t['end_credit']);
    fin_voucher_delete($conn, $a, $hv, null);

    // 12. Voiding.
    check('another company cannot void it', true, refused(static fn() => fin_voucher_delete($conn, $b, $pv, null)));
    fin_voucher_delete($conn, $a, $pv, 7);
    $v = fin_voucher_get($conn, $a, $pv);
    check('voucher is void', 'void', $v['status']);
    check('its lines are kept', 3, count($v['lines']));
    check('voiding twice is refused', true, refused(static fn() => fin_voucher_delete($conn, $a, $pv, 7)));
    check('bank no longer shows the payment', 0.0, $tbRow($a, '1112001')['period_credit']);
    check('rent drops to the remaining payment', 100.0, $tbRow($a, '5400001')['end_debit']);
    check('ledger drops the void line', ['JV-2026-0001', 'RV-2026-0001'], array_column(fin_account_ledger($conn, $a, $bank, '2026-03-01', '2026-03-31')['lines'], 'voucher_no'));
    $t = fin_trial_balance($conn, $a, '2026-03-01', '2026-03-31')['totals'];
    check('trial balance balances after the void', $t['end_debit'], $t['end_credit']);
    $pv4 = fin_voucher_create($conn, $a, 'pv', ['date' => '2026-03-20', 'cash_account_id' => $bank, 'lines' => [$line]]);
    check('a void number is not reused', 'PV-2026-0003', fin_voucher_get($conn, $a, $pv4)['voucher_no']);
    check('deleted vouchers are left off the list', false, in_array($pv, array_map('intval', array_column(fin_voucher_list($conn, $a, 'pv'), 'id')), true));
    check('and shown when asked for', true, in_array($pv, array_map('intval', array_column(fin_voucher_list($conn, $a, 'pv', null, null, true), 'id')), true));

    // 13. Editing.
    $ev = fin_voucher_create($conn, $a, 'pv', ['date' => '2026-03-21', 'party' => 'Landlord', 'narration' => 'Rent', 'cash_account_id' => $bank, 'lines' => [
        ['account_id' => $rent, 'description' => '', 'amount' => '500'],
    ]], 7);
    $no = fin_voucher_get($conn, $a, $ev)['voucher_no'];
    $rentBefore = $tbRow($a, '5400001')['end_debit'];
    $formData = fin_voucher_form_data(fin_voucher_get($conn, $a, $ev));
    check('the form gets the cash account back', $bank, $formData['cash_account_id']);
    check('the form gets the lines without the cash line', [['account_id' => $rent, 'description' => '', 'partner' => '', 'debit' => '500.00', 'credit' => '', 'amount' => '500.00']], $formData['lines']);
    check('saving the form unchanged changes nothing', false, fin_voucher_update($conn, $a, $ev, $formData, 9));
    $formData['party'] = 'Cleaner';
    $formData['date'] = '2026-03-22';
    $formData['lines'] = [['account_id' => $suppliers, 'description' => 'March', 'amount' => '650']];
    check('an edit is saved', true, fin_voucher_update($conn, $a, $ev, $formData, 9));
    $v = fin_voucher_get($conn, $a, $ev);
    check('the number stays', $no, $v['voucher_no']);
    check('the amount follows the lines', '650.00', $v['amount']);
    check('the date follows', '2026-03-22', $v['voucher_date']);
    check('the lines are replaced, not added to', ['2110001', '1112001'], array_column($v['lines'], 'code'));
    check('rent no longer carries the old line', $rentBefore - 500.0, $tbRow($a, '5400001')['end_debit']);
    $t = fin_trial_balance($conn, $a, '2026-03-01', '2026-03-31')['totals'];
    check('trial balance balances after the edit', $t['end_debit'], $t['end_credit']);
    check('an edit with no lines is refused', true, refused(static fn() => fin_voucher_update($conn, $a, $ev, ['date' => '2026-03-22', 'cash_account_id' => $bank, 'lines' => []], 9)));
    check('and leaves the voucher as it was', '650.00', fin_voucher_get($conn, $a, $ev)['amount']);
    check('another company cannot edit it', true, refused(static fn() => fin_voucher_update($conn, $b, $ev, $formData, 9)));
    fin_voucher_delete($conn, $a, $ev, 11);
    check('a deleted voucher cannot be edited', true, refused(static fn() => fin_voucher_update($conn, $a, $ev, $formData, 9)));

    // 14. History: who added, edited and deleted.
    $h = array_reverse(fin_voucher_history($conn, $a, ['voucher_id' => $ev]));
    check('history has the add, the edit and the delete', ['created', 'edited', 'deleted'], array_column($h, 'action'));
    check('each with its user', [7, 9, 11], array_map('intval', array_column($h, 'user_id')));
    check('the edit lists what changed', [
        ['Date', '21 Mar 2026', '22 Mar 2026'],
        ['Party', 'Landlord', 'Cleaner'],
        ['Amount', '500.00', '650.00'],
        ['Line removed', '5400001 Rent · Dr 500.00 · Rent', ''],
        ['Line removed', '1112001 AHLI 0001 555999 301 · Cr 500.00 · Rent', ''],
        ['Line added', '', '2110001 Suppliers · Dr 650.00 · March'],
        ['Line added', '', '1112001 AHLI 0001 555999 301 · Cr 650.00 · Rent'],
    ], $h[1]['changes']);
    check('history can be narrowed to deletes', ['deleted'], array_values(array_unique(array_column(fin_voucher_history($conn, $a, ['action' => 'deleted']), 'action'))));
    check('another company does not see it', [], fin_voucher_history($conn, $b, ['voucher_id' => $ev]));
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
