<?php
/**
 * Unit/regression tests for RE invoice income account role mapping.
 * Run: php tools/test_re_income_account_mapping.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/realestate/includes/re_income_account_roles.php';
require_once __DIR__ . '/../modules/realestate/accounting/accounting_engine.php';
require_once __DIR__ . '/../modules/realestate/accounting/accounting_integration.php';

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  $msg\n";
        $passed++;
    } else {
        echo "FAIL  $msg\n";
        $failed++;
    }
}

function assert_eq($expected, $actual, string $msg): void
{
    assert_true($expected === $actual, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

echo "=== RE Income Account Role Mapping Tests ===\n";

// --- Role selection (no DB) ---
$r = re_income_role_for_obligation('rent', 'revenue', '1br');
assert_eq('RESIDENTIAL_RENT_INCOME', $r['role'], 'residential rent');

$r = re_income_role_for_obligation('rent', 'revenue', 'shop');
assert_eq('COMMERCIAL_RENT_INCOME', $r['role'], 'commercial rent');

$r = re_income_role_for_obligation('service', 'service', '1br', 'Chiller Fees split period 1');
assert_eq('SERVICE_CHARGE_INCOME', $r['role'], 'chiller/service fee via type+class');

$r = re_income_role_for_obligation('parking', 'service', '1br');
assert_eq('SERVICE_CHARGE_INCOME', $r['role'], 'parking');

$r = re_income_role_for_obligation('store', 'service', '1br');
assert_eq('SERVICE_CHARGE_INCOME', $r['role'], 'store');

$r = re_income_role_for_obligation('utility', null, '1br');
assert_eq('SERVICE_CHARGE_INCOME', $r['role'], 'utility');

$r = re_income_role_for_obligation('access_card', null, '1br');
assert_eq('SERVICE_CHARGE_INCOME', $r['role'], 'access_card');

$r = re_income_role_for_obligation('admin_fee', 'revenue', '1br');
assert_eq('OTHER_INCOME', $r['role'], 'admin fee');

$r = re_income_role_for_obligation('commission', 'revenue', '1br');
assert_eq('OTHER_INCOME', $r['role'], 'commission');

$r = re_income_role_for_obligation('other', 'pass_through', '1br', 'Ejari Fees');
assert_eq('OTHER_INCOME', $r['role'], 'ejari/other pass_through');

$r = re_income_role_for_obligation('penalty', 'penalty', '1br');
assert_eq('PENALTY_INCOME', $r['role'], 'penalty');

$r = re_income_role_for_obligation('security_deposit', 'liability', '1br');
assert_true(!empty($r['skip_income']), 'security deposit skip income');

$r = re_income_role_for_obligation('vat', 'revenue', '1br');
assert_true(!empty($r['skip_income']), 'vat skip income');

$r = re_income_role_for_obligation('weird_future_type', null, '1br', 'Something Odd');
assert_eq('OTHER_INCOME', $r['role'], 'unknown category → OTHER_INCOME');
assert_true(!empty($r['unmapped']), 'unknown marked unmapped');

// Legacy keyword only when type/class absent
$r = re_income_role_for_obligation(null, null, '1br', 'Monthly Service Charge');
assert_eq('SERVICE_CHARGE_INCOME', $r['role'], 'legacy keyword service/charge');

$r = re_income_role_for_obligation(null, null, '1br', 'Late Fee Charge');
assert_eq('PENALTY_INCOME', $r['role'], 'legacy keyword late/penalty wins over charge');

// Seed defaults live only in helper
$defaults = re_income_account_role_seed_defaults();
assert_eq('4110', $defaults['RESIDENTIAL_RENT_INCOME'], 'seed residential');
assert_eq('4120', $defaults['COMMERCIAL_RENT_INCOME'], 'seed commercial');
assert_eq('4200', $defaults['SERVICE_CHARGE_INCOME'], 'seed service');
assert_eq('4300', $defaults['PENALTY_INCOME'], 'seed penalty');
assert_eq('4400', $defaults['OTHER_INCOME'], 'seed other');

// --- COA resolution (company 2) ---
$companyId = 2;
$codes = [];
foreach (['RESIDENTIAL_RENT_INCOME', 'COMMERCIAL_RENT_INCOME', 'SERVICE_CHARGE_INCOME', 'PENALTY_INCOME', 'OTHER_INCOME'] as $role) {
    $res = re_resolve_income_account($conn, $companyId, $role);
    assert_true(!empty($res['account']['id']), "resolve $role has account");
    $codes[$role] = (string)$res['account_code'];
}
assert_eq('4200', $codes['SERVICE_CHARGE_INCOME'] ?? null, 'service resolves to seed 4200');

// Explicit income_account_id preferred
$serviceAcc = re_resolve_income_account($conn, $companyId, 'SERVICE_CHARGE_INCOME');
$rentAcc = re_resolve_income_account($conn, $companyId, 'RESIDENTIAL_RENT_INCOME');
$line = re_resolve_income_account_for_line($conn, $companyId, [
    'income_account_id' => (int)$serviceAcc['account']['id'],
    'obligation_type' => 'rent',
    'accounting_class' => 'revenue',
    'unit_type' => '1br',
    'item_name' => 'Monthly Rent',
]);
assert_true(!empty($line['used_explicit']), 'explicit account preferred over rent type');
assert_eq((int)$serviceAcc['account']['id'], (int)$line['account']['id'], 'explicit id used');

// Structured chiller path (regression for INV-2026-00401 class of bug)
$chiller = re_resolve_income_account_for_line($conn, $companyId, [
    'obligation_type' => 'service',
    'accounting_class' => 'service',
    'unit_type' => '1br',
    'item_name' => 'Chiller Fees split period 1 (2026-01-01 to 2026-01-31)',
]);
assert_eq('SERVICE_CHARGE_INCOME', $chiller['role'], 'chiller role SERVICE_CHARGE_INCOME');
assert_eq('4200', $chiller['account_code'], 'chiller posts to Service Charge Income');
assert_true(empty($chiller['unmapped']), 'chiller not unmapped');

// Mixed-line roles differ
$rentLine = re_resolve_income_account_for_line($conn, $companyId, [
    'obligation_type' => 'rent', 'accounting_class' => 'revenue', 'unit_type' => '1br', 'item_name' => 'Monthly Rent',
]);
$svcLine = re_resolve_income_account_for_line($conn, $companyId, [
    'obligation_type' => 'service', 'accounting_class' => 'service', 'unit_type' => '1br', 'item_name' => 'Chiller',
]);
assert_true((int)$rentLine['account']['id'] !== (int)$svcLine['account']['id'], 'mixed-line rent vs service different accounts');

// VAT / non-VAT: VAT skips income; taxable service does not
$vatLine = re_resolve_income_account_for_line($conn, $companyId, [
    'obligation_type' => 'vat', 'accounting_class' => 'revenue', 'unit_type' => '1br', 'item_name' => 'Separate VAT',
]);
assert_true(!empty($vatLine['skip_income']), 'VAT line skips income account');
assert_true(empty($svcLine['skip_income']), 'service line does not skip income');

// determine_income_account legacy wrapper no longer defaults chiller-like names to rent
$legacyCode = determine_income_account('Chiller Fees split period 1', $companyId, '1br');
assert_eq('4200', $legacyCode, 'legacy wrapper maps chiller name via keyword to service');

$legacyUnknown = determine_income_account('Mystery Fee XYZ', $companyId, '1br');
assert_eq('4400', $legacyUnknown, 'legacy wrapper unknown → other income not rent');

echo "\n=== Journal engine smoke (balanced create, rolled back) ===\n";
try {
    $conn->beginTransaction();
    $svc = re_resolve_income_account($conn, $companyId, 'SERVICE_CHARGE_INCOME');
    $ar = re_income_find_account_by_code($conn, $companyId, '1310');
    $vat = re_income_find_account_by_code($conn, $companyId, '2310');
    assert_true($svc['account'] && $ar && $vat, 'COA accounts for smoke journal exist');
    if ($svc['account'] && $ar && $vat) {
        $result = create_and_post_journal(
            $companyId,
            'manual',
            'manual',
            null,
            [
                ['account_id' => (int)$ar['id'], 'debit' => 105.00, 'credit' => 0, 'description' => 'test AR', 'reference' => 'TEST-IM'],
                ['account_id' => (int)$svc['account']['id'], 'debit' => 0, 'credit' => 100.00, 'description' => 'test service income', 'reference' => 'TEST-IM'],
                ['account_id' => (int)$vat['id'], 'debit' => 0, 'credit' => 5.00, 'description' => 'test VAT', 'reference' => 'TEST-IM'],
            ],
            'Income mapping regression smoke',
            date('Y-m-d'),
            null
        );
        assert_true(!empty($result['success']), 'journal engine posts balanced entry: ' . ($result['error'] ?? 'ok'));
    }
    $conn->rollBack();
    echo "PASS  smoke journal rolled back\n";
    $passed++;
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "FAIL  journal smoke: " . $e->getMessage() . "\n";
    $failed++;
}

echo "\nPassed: $passed  Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
