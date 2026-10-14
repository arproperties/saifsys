<?php
declare(strict_types=1);

/**
 * Finance > Lease Posting: a lease raised as a journal voucher.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs the finance migrations up to fin_lease_posting.sql, a company with an
 * active lease and a second active company.
 *
 *     php tests/fin_lease_posting_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';
require_once __DIR__ . '/../includes/fin_lease_posting_helper.php';
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

// 1. No database needed.
$sample = ['contract_amount' => '60000.00', 'admin_fees' => '500.00', 'chiller_fees' => '0.00', 'ejari_fees' => '220.00',
           'security_deposit' => '3000.00', 'commission_fees' => '100.00', 'amc_amount' => '50.50'];
check('amounts from a lease, in the order of the lease page',
    ['tenant' => 63870.5, 'contract' => 60000.0, 'deposit' => 3000.0, 'chiller' => 0.0, 'ejari' => 220.0, 'admin' => 500.0, 'commission' => 100.0, 'amc' => 50.5],
    fin_lease_posting_amounts($sample));
check('a renewal has no security deposit', ['tenant' => 60870.5, 'deposit' => 0.0],
    array_intersect_key(fin_lease_posting_amounts(['is_renewal_lease' => '1'] + $sample), ['tenant' => 1, 'deposit' => 1]));

$income = [['id' => 1, 'code' => '4100008', 'name' => 'Ejari Income Recieved'], ['id' => 2, 'code' => '4200001', 'name' => 'OTHER INCOME']];
check('commission goes to Other Income while the chart has no commission account', '4200001', fin_lease_posting_default('commission', $income, [])['code']);
check('a commission account is picked once it exists', '4100011',
    fin_lease_posting_default('commission', array_merge($income, [['id' => 3, 'code' => '4100011', 'name' => 'Commission Income']]), [])['code']);

$defer = [['id' => 1, 'code' => '2112009', 'name' => 'Deferred Revenue'],
          ['id' => 2, 'code' => '2112012', 'name' => 'Deferred Revenue - Ayla'],
          ['id' => 3, 'code' => '2112013', 'name' => 'Deferred Revenue - Park Place'],
          ['id' => 4, 'code' => '2112014', 'name' => 'Deferred Revenue - Park']];
$pickFor = static fn(?string $b) => fin_lease_posting_contract_default($defer, $b)['code'] ?? null;
check('contract account for AYLA RESIDENCE', '2112012', $pickFor('AYLA RESIDENCE'));
check('contract account for PARK PLACE (longest name wins)', '2112013', $pickFor('PARK PLACE'));
check('contract account for a building with its own word only', '2112014', $pickFor('Park Avenue'));
check('a part of a word does not match', '2112009', $pickFor('Aylan Tower'));
check('any other building gets 2112009', '2112009', $pickFor('Gents Camp (G+5 Building)'));
check('no building gets 2112009', '2112009', $pickFor(null));
check('no 2112009 and no match', null, fin_lease_posting_contract_default([$defer[1]], 'Gents Camp'));

if (!fin_coa_table_ready($conn) || !fin_voucher_tables_ready($conn) || !fin_lease_posting_ready($conn)) {
    echo "Run the finance migrations up to fin_lease_posting.sql first.\n";
    exit(1);
}
$a = (int)$conn->query("SELECT l.company_id FROM re_leases l JOIN companies c ON c.id = l.company_id AND c.is_active = 1 WHERE l.status = 'active' ORDER BY l.company_id LIMIT 1")->fetchColumn();
$stmt = $conn->prepare("SELECT id FROM companies WHERE is_active = 1 AND id <> ? ORDER BY id LIMIT 1");
$stmt->execute([$a]);
$b = (int)$stmt->fetchColumn();
if (!$a || !$b) {
    echo "Needs a company with an active lease and a second active company.\n";
    exit(1);
}

$conn->beginTransaction();
try {
    $conn->prepare("DELETE FROM fin_ledger_entries WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_voucher_history WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_vouchers WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("UPDATE fin_coa_accounts SET parent_id = NULL WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_coa_accounts WHERE company_id IN (?, ?)")->execute([$a, $b]);
    fin_test_load_chart($conn, $a);
    fin_test_load_chart($conn, $b);

    // 2. The chart has Other Income only: the other rows have no account yet.
    $acc = fin_lease_posting_accounts($conn, $a);
    check('a row whose account is missing', null, $acc['contract']);
    check('a row whose account exists', '4200001', $acc['amc']['code']);

    $stmt = $conn->prepare("SELECT id FROM re_leases WHERE company_id = ? AND status = 'active' ORDER BY id LIMIT 2");
    $stmt->execute([$a]);
    $leaseIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $leaseId = $leaseIds[0];
    $conn->prepare("UPDATE re_leases SET contract_amount = 60000, admin_fees = 500, chiller_fees = 0, ejari_fees = 220, security_deposit = 3000, commission_fees = 100, amc_amount = 50.50 WHERE id = ?")->execute([$leaseId]);
    $lease = fin_lease_posting_get($conn, $a, $leaseId);
    $amounts = fin_lease_posting_amounts($lease);
    check('lease is not posted yet', null, $lease['voucher_id']);
    check('tenant has a name', true, trim((string)$lease['tenant_name']) !== '');
    check('another company cannot see the lease', null, fin_lease_posting_get($conn, $b, $leaseId));
    check('the lease is in the list', true, in_array($leaseId, array_map('intval', array_column(fin_lease_posting_leases($conn, $a), 'id')), true));

    $post = static fn(int $company, int $lease, array $amt, string $date = '2026-03-01') =>
        fin_lease_post($conn, $company, $lease, ['date' => $date, 'amounts' => $amt], 7);

    check('a missing account stops the posting', true, str_contains(refusal(static fn() => $post($a, $leaseId, $amounts)), 'account 1121005 is not in the chart'));

    foreach ([['1121005', 'SUNDRY DEBTORS'], ['2112009', 'Deferred Revenue'], ['2112010', 'Security Deposit'],
              ['4100008', 'Ejari Income Recieved'], ['4100009', 'Chiller Fees'], ['4100010', 'Admin Fee']] as [$code, $name]) {
        fin_coa_add_account($conn, $a, $code, $name);
    }

    // 3. What is refused.
    check('debit and credit must be equal', true, str_contains(refusal(static fn() => $post($a, $leaseId, ['tenant' => '100'] + $amounts)), 'must be equal'));
    check('an amount must be a number', true, str_contains(refusal(static fn() => $post($a, $leaseId, ['admin' => 'abc'] + $amounts)), 'Admin Fee'));
    check('nothing to post', 'Enter the amounts to post.', refusal(static fn() => $post($a, $leaseId, [])));
    check('a date is needed', 'Enter a valid voucher date.', refusal(static fn() => $post($a, $leaseId, $amounts, '')));
    check('another company cannot post it', true, refusal(static fn() => $post($b, $leaseId, $amounts)) !== '');
    check('nothing was saved by the refusals', 0, (int)$conn->query("SELECT COUNT(*) FROM fin_vouchers WHERE company_id = {$a}")->fetchColumn());

    // 4. Posting.
    $id = $post($a, $leaseId, $amounts);
    $v = fin_voucher_get($conn, $a, $id);
    check('posted as a journal voucher', 'jv', $v['type']);
    check('voucher keeps the lease', $leaseId, (int)$v['lease_id']);
    check('voucher amount is the tenant total', '63870.50', $v['amount']);
    check('party is the tenant', mb_substr($lease['tenant_name'], 0, 150), $v['party']);
    check('reference is the lease number', $lease['lease_number'], $v['reference']);
    check('lines: debtor first, zero rows left out',
        [['1121005', '63870.50', '0.00'], ['2112009', '0.00', '60000.00'], ['2112010', '0.00', '3000.00'],
         ['4100008', '0.00', '220.00'], ['4100010', '0.00', '500.00'], ['4200001', '0.00', '100.00'], ['4200001', '0.00', '50.50']],
        array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit']], $v['lines']));
    $tb = array_column(fin_trial_balance($conn, $a, '2026-03-01', '2026-03-31')['rows'], null, 'code');
    check('trial balance shows the debtor', 63870.5, (float)$tb['1121005']['period_debit']);

    // 5. Once only, until the voucher is deleted.
    check('lease now shows its voucher', $v['voucher_no'], fin_lease_posting_get($conn, $a, $leaseId)['voucher_no']);
    check('a posted lease cannot be posted again', true, str_contains(refusal(static fn() => $post($a, $leaseId, $amounts)), $v['voucher_no']));
    fin_voucher_delete($conn, $a, $id, 7);
    check('deleting the voucher frees the lease', null, fin_lease_posting_get($conn, $a, $leaseId)['voucher_id']);
    $again = $post($a, $leaseId, ['tenant' => '60,000.00', 'contract' => '60000'], '2026-03-05');
    check('and it can be posted again, with changed amounts', '60000.00', fin_voucher_get($conn, $a, $again)['amount']);
    check('the list marks it posted', [$again], array_values(array_map('intval', array_filter(array_column(fin_lease_posting_leases($conn, $a), 'voucher_id')))));

    // 5b. The contract can go to another Deferred Revenue account, and only to one.
    fin_voucher_delete($conn, $a, $again, 7);
    fin_coa_add_account($conn, $a, '2112012', 'Deferred Revenue - Test');
    $choices = array_column(fin_lease_posting_contract_accounts($conn, $a), 'id', 'code');
    check('the Deferred Revenue accounts are the choices', ['2112009', '2112012'], array_map('strval', array_keys($choices)));
    $other = (int)$acc['amc']['id'];
    check('a non-deferred account is refused', 'Contract Amount: choose a Deferred Revenue account.',
        refusal(static fn() => fin_lease_post($conn, $a, $leaseId, ['date' => '2026-03-05', 'amounts' => $amounts, 'accounts' => ['contract' => $other]], 7)));
    check('commission cannot go to a liability', 'Commission Fees: choose a revenue account.',
        refusal(static fn() => fin_lease_post($conn, $a, $leaseId, ['date' => '2026-03-05', 'amounts' => $amounts, 'accounts' => ['commission' => (int)$choices['2112012']]], 7)));
    $admin = (int)fin_lease_posting_accounts($conn, $a)['admin']['id'];
    $chosen = fin_lease_post($conn, $a, $leaseId, ['date' => '2026-03-05', 'amounts' => $amounts, 'accounts' => ['contract' => (int)$choices['2112012'], 'commission' => $admin]], 7);
    $codes = array_column(fin_voucher_get($conn, $a, $chosen)['lines'], 'code');
    check('the contract goes to the chosen account', ['1121005', '2112012'], array_slice($codes, 0, 2));
    check('the commission goes to the chosen account', '4100010', $codes[5]);

    // 6. Only active leases.
    $conn->prepare("UPDATE re_leases SET status = 'expired' WHERE id = ?")->execute([$leaseIds[1] ?? 0]);
    if (isset($leaseIds[1])) {
        check('a lease that is not active is refused', 'Choose an active contract of this company.', refusal(static fn() => $post($a, $leaseIds[1], ['tenant' => '10', 'contract' => '10'])));
    }
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
