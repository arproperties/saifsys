<?php
declare(strict_types=1);

/**
 * Finance > Partners: ledger lines that carry a tenant or supplier, and the
 * balance and statement they add up to.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs the finance migrations up to fin_partners.sql, a company with a tenant
 * and a second active company.
 *
 *     php tests/fin_partner_test.php
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
check('a partner key is read', ['tenant', 12], fin_partner_parse('tenant:12'));
check('an unknown kind is not a partner', null, fin_partner_parse('staff:12'));
check('a key without a number is not a partner', null, fin_partner_parse('tenant:x'));
check('a line with a partner gives its key', 're_vendor:7', fin_partner_key('re_vendor', '7'));
check('a line without a partner gives none', '', fin_partner_key(null, null));

if (!fin_coa_table_ready($conn) || !fin_voucher_tables_ready($conn) || !fin_lease_posting_ready($conn)) {
    echo "Run the finance migrations up to fin_partners.sql first.\n";
    exit(1);
}
$a = (int)$conn->query("SELECT t.company_id FROM re_tenants t JOIN companies c ON c.id = t.company_id AND c.is_active = 1 GROUP BY t.company_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$stmt = $conn->prepare("SELECT id FROM companies WHERE is_active = 1 AND id <> ? ORDER BY id LIMIT 1");
$stmt->execute([$a]);
$b = (int)$stmt->fetchColumn();
if (!$a || !$b) {
    echo "Needs a company with a tenant and a second active company.\n";
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

    // A supplier of each company, so the test does not lean on the data.
    $conn->prepare("INSERT INTO re_vendors (company_id, vendor_name) VALUES (?, 'Zz Test Supplier')")->execute([$a]);
    $supplier = 're_vendor:' . (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO re_vendors (company_id, vendor_name) VALUES (?, 'Other Company Supplier')")->execute([$b]);
    $foreign = 're_vendor:' . (int)$conn->lastInsertId();
    $tenantId = (int)$conn->query("SELECT id FROM re_tenants WHERE company_id = {$a} ORDER BY id LIMIT 1")->fetchColumn();
    $tenant = 'tenant:' . $tenantId;

    $stmt = $conn->prepare("SELECT code, id FROM fin_coa_accounts WHERE company_id = ?");
    $stmt->execute([$a]);
    $id = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    [$bank, $debtors, $creditors, $sales, $rent] = [$id['1112001'], $id['1120001'], $id['2110001'], $id['4100001'], $id['5400001']];

    // 2. Who can be chosen.
    $partners = fin_partners($conn, $a);
    check('the tenant is a partner', 'tenant', $partners[$tenant]['kind'] ?? null);
    check('the supplier is a partner', ['supplier', 'Zz Test Supplier'], [$partners[$supplier]['kind'] ?? null, $partners[$supplier]['name'] ?? null]);
    check("another company's supplier is not", false, isset($partners[$foreign]));
    check('tenants come before suppliers', 'tenant', reset($partners)['kind']);
    check('nobody has a balance yet', [], fin_partner_balances($conn, $a, '2026-12-31')['rows']);

    // 3. Tenant: charged 1,000, paid 400.
    $jv = fin_voucher_create($conn, $a, 'jv', ['date' => '2026-03-01', 'narration' => 'Rent charged', 'lines' => [
        ['account_id' => $debtors, 'partner' => $tenant, 'debit' => '1000', 'credit' => ''],
        ['account_id' => $sales, 'debit' => '', 'credit' => '1000'],
    ]], 7);
    $v = fin_voucher_get($conn, $a, $jv);
    check('the line keeps its partner', ['tenant', $tenantId, $partners[$tenant]['name']], [$v['lines'][0]['partner_type'], (int)$v['lines'][0]['partner_id'], $v['lines'][0]['partner_name']]);
    check('a line without one stays without', [null, null], [$v['lines'][1]['partner_type'], $v['lines'][1]['partner_name']]);

    $rv = fin_voucher_create($conn, $a, 'rv', ['date' => '2026-03-10', 'cash_account_id' => $bank, 'lines' => [
        ['account_id' => $debtors, 'partner' => $tenant, 'amount' => '400'],
    ]], 7);
    $v = fin_voucher_get($conn, $a, $rv);
    check('"Received from" left blank takes the partner', mb_substr($partners[$tenant]['name'], 0, 150), $v['party']);
    check('the bank line has no partner', null, $v['lines'][0]['partner_type']);
    check('the form gets the partner back', $tenant, fin_voucher_form_data($v)['lines'][0]['partner']);

    // 4. Supplier: billed 500, paid 200.
    fin_voucher_create($conn, $a, 'jv', ['date' => '2026-03-05', 'narration' => 'Rent bill', 'lines' => [
        ['account_id' => $rent, 'debit' => '500', 'credit' => ''],
        ['account_id' => $creditors, 'partner' => $supplier, 'debit' => '', 'credit' => '500'],
    ]], 7);
    $pv = fin_voucher_create($conn, $a, 'pv', ['date' => '2026-04-02', 'party' => 'Typed name', 'cash_account_id' => $bank, 'lines' => [
        ['account_id' => $creditors, 'partner' => $supplier, 'amount' => '200'],
    ]], 7);
    check('a typed "Paid to" is kept', 'Typed name', fin_voucher_get($conn, $a, $pv)['party']);

    // 5. Balances.
    $rows = array_column(fin_partner_balances($conn, $a, '2026-12-31')['rows'], null, 'key');
    check('two partners have a balance', [$tenant, $supplier], array_keys($rows));
    check('the tenant owes 600', [1000.0, 400.0, 600.0], [$rows[$tenant]['debit'], $rows[$tenant]['credit'], $rows[$tenant]['balance']]);
    check('the supplier is owed 300', [200.0, 500.0, -300.0], [$rows[$supplier]['debit'], $rows[$supplier]['credit'], $rows[$supplier]['balance']]);
    check('totals add them up', ['debit' => 1200.0, 'credit' => 900.0, 'balance' => 300.0], fin_partner_balances($conn, $a, '2026-12-31')['totals']);
    check('tenants only', [$tenant], array_column(fin_partner_balances($conn, $a, '2026-12-31', 'tenant')['rows'], 'key'));
    check('suppliers only', [$supplier], array_column(fin_partner_balances($conn, $a, '2026-12-31', 'supplier')['rows'], 'key'));
    check('before the payment the supplier is owed 500', -500.0, array_column(fin_partner_balances($conn, $a, '2026-03-31')['rows'], 'balance', 'key')[$supplier]);
    check('another company sees none of it', [], fin_partner_balances($conn, $b, '2026-12-31')['rows']);
    check('the trial balance is unchanged by partners', 600.0, (float)array_column(fin_trial_balance($conn, $a, '2026-03-01', '2026-03-31')['rows'], null, 'code')['1120001']['end_debit']);

    // 6. Statement.
    $l = fin_partner_ledger($conn, $a, $tenant, '2026-03-01', '2026-03-31');
    check('tenant statement lists both lines', ['JV-2026-0001', 'RV-2026-0001'], array_column($l['lines'], 'voucher_no'));
    check('each line names its account', '1120001', $l['lines'][0]['code']);
    check('running balance', [1000.0, 600.0], array_column($l['lines'], 'balance'));
    check('closing balance', [0.0, 600.0], [$l['opening'], $l['closing']]);
    $l = fin_partner_ledger($conn, $a, $supplier, '2026-04-01', '2026-04-30');
    check('opening balance is what came before', [-500.0, -300.0], [$l['opening'], $l['closing']]);
    check('a partner of another company has no statement', null, fin_partner_ledger($conn, $a, $foreign, '2026-01-01', '2026-12-31'));
    check('nor does a key that is not a partner', null, fin_partner_ledger($conn, $a, 'staff:1', '2026-01-01', '2026-12-31'));
    $al = fin_account_ledger($conn, $a, $debtors, '2026-03-01', '2026-03-31');
    check('the account ledger names the partner', $partners[$tenant]['name'], $al['lines'][0]['partner_name']);

    // 7. What is refused.
    $line = static fn(string $partner) => ['date' => '2026-03-01', 'cash_account_id' => $bank, 'lines' => [['account_id' => $debtors, 'partner' => $partner, 'amount' => '10']]];
    check("another company's partner is refused", true, str_contains(refusal(static fn() => fin_voucher_create($conn, $a, 'rv', $line($foreign))), 'tenant or supplier'));
    check('a made-up partner is refused', true, str_contains(refusal(static fn() => fin_voucher_create($conn, $a, 'rv', $line('tenant:999999999'))), 'Line 1'));

    // 8. Edits and deletes.
    $data = fin_voucher_form_data(fin_voucher_get($conn, $a, $rv));
    $data['lines'][0]['amount'] = '450';
    fin_voucher_update($conn, $a, $rv, $data, 7);
    check('an edit keeps the partner', 550.0, array_column(fin_partner_balances($conn, $a, '2026-12-31')['rows'], 'balance', 'key')[$tenant]);
    $data['lines'][0]['partner'] = '';
    check('taking the partner off is a change', true, fin_voucher_update($conn, $a, $rv, $data, 7));
    $changes = fin_voucher_history($conn, $a, ['voucher_id' => $rv])[0]['changes'];
    check('the history shows who was taken off', true, str_contains($changes[0][1], $partners[$tenant]['name']));
    check('and the receipt leaves the balance', 1000.0, array_column(fin_partner_balances($conn, $a, '2026-12-31')['rows'], 'balance', 'key')[$tenant]);
    fin_voucher_delete($conn, $a, $jv, 7);
    check('a deleted voucher leaves the balance', false, isset(array_column(fin_partner_balances($conn, $a, '2026-12-31')['rows'], null, 'key')[$tenant]));

    // 9. A posted lease debits its tenant.
    $stmt = $conn->prepare("SELECT l.id, l.tenant_id FROM re_leases l JOIN re_tenants t ON t.id = l.tenant_id AND t.company_id = l.company_id WHERE l.company_id = ? AND l.status = 'active' ORDER BY l.id LIMIT 1");
    $stmt->execute([$a]);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lease) {
        foreach ([['1121005', 'SUNDRY DEBTORS'], ['2112009', 'Deferred Revenue']] as [$code, $name]) {
            fin_coa_add_account($conn, $a, $code, $name);
        }
        $lv = fin_voucher_get($conn, $a, fin_lease_post($conn, $a, (int)$lease['id'], ['date' => '2026-05-01', 'amounts' => ['tenant' => '5000', 'contract' => '5000']], 7));
        check('lease posting tags the debtor line', ['1121005', 'tenant', (int)$lease['tenant_id']], [$lv['lines'][0]['code'], $lv['lines'][0]['partner_type'], (int)$lv['lines'][0]['partner_id']]);
        check('and leaves the other lines alone', null, $lv['lines'][1]['partner_type']);
        check('the tenant now owes the lease', 5000.0, (float)fin_partner_ledger($conn, $a, 'tenant:' . (int)$lease['tenant_id'], '2026-05-01', '2026-05-31')['debit']);
    } else {
        echo "skip - no active lease to post\n";
    }
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
