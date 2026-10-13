<?php
declare(strict_types=1);

/**
 * Finance > Chart of Accounts (New): codes, per-company charts, the starting chart.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs migrations/fin_chart_of_accounts.sql and two active companies.
 *
 *     php tests/fin_coa_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_coa_helper.php';
require_once __DIR__ . '/../includes/fin_voucher_helper.php';
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

// 1. Codes, no database needed.
check('1000000 is a class', 'class', fin_coa_level_for_code('1000000'));
check('1100000 is a group', 'group', fin_coa_level_for_code('1100000'));
check('1110000 is a sub-group', 'sub_group', fin_coa_level_for_code('1110000'));
check('1112000 is a category', 'category', fin_coa_level_for_code('1112000'));
check('1112001 is a ledger', 'ledger', fin_coa_level_for_code('1112001'));
check('3100001 is a ledger', 'ledger', fin_coa_level_for_code('3100001'));
check('6 digits is not a code', null, fin_coa_level_for_code('111200'));
check('a leading 0 is not a code', null, fin_coa_level_for_code('0112001'));
check('letters are not a code', null, fin_coa_level_for_code('11A2001'));
check('headings above 1112001', ['1112000', '1110000', '1100000', '1000000'], fin_coa_ancestor_codes('1112001'));
check('headings above 3100001', ['3100000', '3000000'], fin_coa_ancestor_codes('3100001'));
check('assets are debit', 'debit', fin_coa_default_normal_balance('1112001'));
check('liabilities are credit', 'credit', fin_coa_default_normal_balance('2110001'));
check('revenue is credit', 'credit', fin_coa_default_normal_balance('4100001'));
check('expenses are debit', 'debit', fin_coa_default_normal_balance('5300001'));

// Codes offered when an account is added by picking its place in the chart.
$sheet = ['1000000', '1100000', '1110000', '1111000', '1111001'];
check('an empty chart starts at 1000000', '1000000', fin_coa_suggest_code([], null, false));
check('the next 1st group is 2000000', '2000000', fin_coa_suggest_code($sheet, null, false));
check('2nd group under 1000000', '1200000', fin_coa_suggest_code($sheet, '1000000', false));
check('3rd group under 1100000', '1120000', fin_coa_suggest_code($sheet, '1100000', false));
check('4th group under 1110000', '1112000', fin_coa_suggest_code($sheet, '1110000', false));
check('first 4th group under a new 3rd group', '1121000', fin_coa_suggest_code(['1120000'], '1120000', false));
check('ledger under 1111000 follows the last one', '1111002', fin_coa_suggest_code($sheet, '1111000', true));
check('first ledger under a 4th group', '1112001', fin_coa_suggest_code(['1112000'], '1112000', true));
check('ledger straight under a 2nd group', '3100002', fin_coa_suggest_code(['3100000', '3100001'], '3100000', true));
check('no group fits under a 4th group', null, fin_coa_suggest_code($sheet, '1111000', false));
check('no ledger at the top of the chart', null, fin_coa_suggest_code($sheet, null, true));
check('nothing goes under a ledger', null, fin_coa_suggest_code($sheet, '1111001', true));
check('a tenth 2nd group has no room', null, fin_coa_suggest_code(
    ['1100000', '1200000', '1300000', '1400000', '1500000', '1600000', '1700000', '1800000', '1900000'], '1000000', false
));
check('a gap left by a missing group is reused', '1200000', fin_coa_suggest_code(['1100000', '1300000'], '1000000', false));
check('ledger 998 is the last one', null, fin_coa_suggest_code(['1111998'], '1111000', true));

// Total lines.
check('1111999 is a total', 'total', fin_coa_level_for_code('1111999'));
check('1199999 is a total', 'total', fin_coa_level_for_code('1199999'));
check('1111999 closes a 4th group', 'category', fin_coa_total_of('1111999'));
check('1119999 closes a 3rd group', 'sub_group', fin_coa_total_of('1119999'));
check('1199999 closes a 2nd group', 'group', fin_coa_total_of('1199999'));
check('1999999 closes a 1st group', 'class', fin_coa_total_of('1999999'));
check('a total line is not counted as the last ledger', '1111003', fin_coa_suggest_code(['1111000', '1111002', '1111999'], '1111000', true));

// The starting chart is the Chart Of Accounts sheet, line for line.
$starting = fin_coa_sample_chart();
$sorted = array_column($starting, 0);
sort($sorted);
check('starting chart has every line of the sheet but the two clearing accounts', 319, count($starting));
check('no clearing accounts', [], array_values(array_filter(array_column($starting, 0), static fn($c) => $c[0] === '9')));
check('starting chart is in code order', $sorted, array_column($starting, 0));
foreach ([fin_coa_sample_chart(), fin_test_chart()] as $list) {
    $codes = array_column($list, 0);
    check('codes are unique', count($codes), count(array_unique($codes)));
    check('every code is valid', [], array_values(array_filter($codes, static fn($c) => fin_coa_level_for_code($c) === null)));
}
$sample = fin_test_chart();

if (!fin_coa_table_ready($conn)) {
    echo "Run migrations/fin_chart_of_accounts.sql first.\n";
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
    // Ledger entries point at the accounts and vouchers, so they go first where those tables exist.
    foreach (['fin_ledger_entries', 'fin_vouchers'] as $t) {
        if ((int)$conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")->fetchColumn() > 0) {
            $conn->prepare("DELETE FROM {$t} WHERE company_id IN (?, ?)")->execute([$a, $b]);
        }
    }
    $conn->prepare("UPDATE fin_coa_accounts SET parent_id = NULL WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_coa_accounts WHERE company_id IN (?, ?)")->execute([$a, $b]);

    $get = static function (int $companyId, string $code) use ($conn): ?array {
        $s = $conn->prepare("SELECT * FROM fin_coa_accounts WHERE company_id = ? AND code = ?");
        $s->execute([$companyId, $code]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $countFor = static function (int $companyId) use ($conn): int {
        $s = $conn->prepare("SELECT COUNT(*) FROM fin_coa_accounts WHERE company_id = ?");
        $s->execute([$companyId]);
        return (int)$s->fetchColumn();
    };

    // 2. The starting chart loads into one company only, once.
    check('starting chart adds every account', count($starting), fin_coa_load_sample($conn, $b, null));
    check('company A stays empty', 0, $countFor($a));
    check('a second load is refused', true, refused(static fn() => fin_coa_load_sample($conn, $b, null)));
    check('still one copy after the refusal', count($starting), $countFor($b));
    check('AHLI bank account as printed', 'AHLI 0001 555999 301', $get($b, '1112001')['name']);
    check('profit & loss account as printed', 'PROFIT & LOSS A/C', $get($b, '3200002')['name']);
    check('3200002 sits under Retained earnings', (int)$get($b, '3200000')['id'], (int)$get($b, '3200002')['parent_id']);
    check('unnamed lines stay unnamed', '', $get($b, '5411000')['name']);
    check('a 4th group total sits under its heading', (int)$get($b, '1111000')['id'], (int)$get($b, '1111999')['parent_id']);
    check('a 3rd group total sits under its heading', (int)$get($b, '1110000')['id'], (int)$get($b, '1119999')['parent_id']);
    check('a 1st group total sits under its heading', (int)$get($b, '1000000')['id'], (int)$get($b, '1999999')['parent_id']);
    $plain = fin_coa_accounts($conn, $b);
    $full = array_column(fin_coa_accounts($conn, $b, true), null, 'code');
    check('totals are left out unless asked for', [], array_values(array_filter($plain, static fn($r) => $r['level'] === 'total')));
    check('the chart page gets every line', count($starting), count($full));
    check('a total line is not a posting account', false, $full['1111999']['is_leaf']);
    check('cash in hand holds 2 ledgers, the total not counted', 2, $full['1111000']['ledger_count']);
    $postable = array_column(fin_voucher_ledger_accounts($conn, $b), 'code');
    check('vouchers cannot post to a total line', [], array_values(array_filter($postable, static fn($c) => substr($c, 4) === '999')));
    check('vouchers can post to 1111001', true, in_array('1111001', $postable, true));
    $conn->prepare("UPDATE fin_coa_accounts SET parent_id = NULL WHERE company_id = ?")->execute([$b]);
    $conn->prepare("DELETE FROM fin_coa_accounts WHERE company_id = ?")->execute([$b]);

    // The rest uses the larger test chart.
    fin_test_load_chart($conn, $a);
    check('company A holds the test chart', count($sample), $countFor($a));
    check('company B is empty again', 0, $countFor($b));

    // 3. Parents follow the code, including skipped levels.
    $bank = $get($a, '1112001');
    check('1112001 sits under 1112000', (int)$get($a, '1112000')['id'], (int)$bank['parent_id']);
    check('1112000 sits under 1110000', (int)$get($a, '1110000')['id'], (int)$get($a, '1112000')['parent_id']);
    check('3100001 sits directly under 3100000', (int)$get($a, '3100000')['id'], (int)$get($a, '3100001')['parent_id']);
    check('a class has no parent', null, $get($a, '1000000')['parent_id']);
    check('accumulated depreciation is credit', 'credit', $get($a, '1210003')['normal_balance']);

    // 4. Counts shown on the page.
    $tree = fin_coa_accounts($conn, $a);
    $byCode = array_column($tree, null, 'code');
    $ledgers = count(array_filter($sample, static fn($r) => fin_coa_level_for_code($r[0]) === 'ledger'));
    check('class counts add up to all ledgers', $ledgers, array_sum(array_map(
        static fn($r) => $r['level'] === 'class' ? $r['ledger_count'] : 0,
        $tree
    )));
    check('bank accounts holds 1 ledger', 1, $byCode['1112000']['ledger_count']);
    check('cash & banks holds 4 ledgers', 4, $byCode['1110000']['ledger_count']);
    check('1112001 is 4 levels down', 4, $byCode['1112001']['depth']);
    check('3100001 is 2 levels down', 2, $byCode['3100001']['depth']);

    // 5. The same code can live in two companies.
    fin_coa_add_account($conn, $b, '1000000', 'Assets');
    fin_coa_add_account($conn, $b, '1000001', 'Petty cash');
    check('company B has its own 1000000', 'Assets', $get($b, '1000000')['name']);
    check('company B ledger hangs off company B class', (int)$get($b, '1000000')['id'], (int)$get($b, '1000001')['parent_id']);
    check('company A is unchanged', count($sample), $countFor($a));

    // 6. What gets refused.
    check('duplicate code in the same company', true, refused(static fn() => fin_coa_add_account($conn, $a, '1112001', 'Again')));
    fin_coa_add_account($conn, $a, '1112999', 'Bank accounts - total');
    check('a code ending 999 is saved as a total line', 'total', $get($a, '1112999')['level']);
    check('and 1112001 stays a posting account', true, array_column(fin_coa_accounts($conn, $a, true), null, 'code')['1112001']['is_leaf']);
    check('empty name', true, refused(static fn() => fin_coa_add_account($conn, $a, '1112002', '  ')));
    check('bad code', true, refused(static fn() => fin_coa_add_account($conn, $a, '11120', 'Short')));
    fin_coa_add_account($conn, $b, '7000001', 'No heading');
    check('ledger with no heading above it sits at the top', null, $get($b, '7000001')['parent_id']);
    fin_coa_add_account($conn, $b, '7000000', 'Class seven');
    check('and moves under its class once that exists', (int)$get($b, '7000000')['id'], (int)$get($b, '7000001')['parent_id']);

    // 7. A heading added later takes over the accounts in its range.
    fin_coa_add_account($conn, $a, '1121000', 'Client receivables');
    check('new category sits under its sub-group', (int)$get($a, '1120000')['id'], (int)$get($a, '1121000')['parent_id']);
    check('ledgers outside the range stay put', (int)$get($a, '1120000')['id'], (int)$get($a, '1120001')['parent_id']);
    fin_coa_add_account($conn, $a, '3110000', 'Share capital');
    fin_coa_add_account($conn, $a, '3110001', 'Ordinary shares');
    fin_coa_add_account($conn, $a, '3111000', 'Issued shares');
    check('3110001 stays under 3110000', (int)$get($a, '3110000')['id'], (int)$get($a, '3110001')['parent_id']);
    fin_coa_add_account($conn, $b, '1200001', 'Vehicle');
    fin_coa_add_account($conn, $b, '1200000', 'Non-current assets');
    check('1200001 moves under the new 1200000', (int)$get($b, '1200000')['id'], (int)$get($b, '1200001')['parent_id']);
    check('1000001 stays under the class', (int)$get($b, '1000000')['id'], (int)$get($b, '1000001')['parent_id']);

    // 8. Editing.
    fin_coa_update_account($conn, $a, (int)$bank['id'], 'AHLI main account', 'debit', true);
    check('name changes', 'AHLI main account', $get($a, '1112001')['name']);
    check('code does not change', '1112001', $get($a, '1112001')['code']);
    check('heading with active accounts cannot be switched off', true, refused(
        static fn() => fin_coa_update_account($conn, $a, (int)$get($a, '1112000')['id'], 'Bank accounts', 'debit', false)
    ));
    fin_coa_update_account($conn, $a, (int)$bank['id'], 'AHLI main account', 'debit', false);
    check('ledger can be switched off', 0, (int)$get($a, '1112001')['is_active']);
    check('another company cannot edit it', true, refused(
        static fn() => fin_coa_update_account($conn, $b, (int)$bank['id'], 'Hijack', 'debit', true)
    ));
    check('name survives the refused edit', 'AHLI main account', $get($a, '1112001')['name']);
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
