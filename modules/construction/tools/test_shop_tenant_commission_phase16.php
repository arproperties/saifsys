<?php
/**
 * Phase 1.6 regression — tenant commission (% / fixed / multi-shop / partial / full).
 * CLI only. Uses company 3 (Madar Al Wadi).
 */
declare(strict_types=1);

// XAMPP MySQL socket (PHP "localhost" expects /tmp/mysql.sock on macOS).
$xamppSock = '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
if (is_file($xamppSock) && !file_exists('/tmp/mysql.sock')) {
    @symlink($xamppSock, '/tmp/mysql.sock');
}

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';

/** @var PDO $conn */

$companyId = 3;
$userId = 1;
$payAccountId = 61; // Mashreq bank
$ok = 0;
$fail = 0;

function assert_true(bool $cond, string $label): void {
    global $ok, $fail;
    if ($cond) {
        echo "OK  {$label}\n";
        $ok++;
    } else {
        echo "FAIL {$label}\n";
        $fail++;
    }
}

function assert_eq($expected, $actual, string $label): void {
    $match = abs((float)$expected - (float)$actual) < 0.005
        || (string)$expected === (string)$actual;
    assert_true($match, $label . " (expected={$expected}, actual={$actual})");
}

echo "=== Phase 1.6 Tenant Commission regression ===\n";

assert_true(co_shop_commission_schema_ready($conn), 'commission schema ready');
$acct = find_account_by_code(CO_ACCOUNT_SHOP_COMMISSION_INCOME, $companyId);
assert_true(!empty($acct), 'income account 4140 present');
$vat = find_account_by_code(CO_ACCOUNT_OUTPUT_VAT, $companyId);
assert_true(!empty($vat), 'VAT account 2310 present');
$ar = find_account_by_code(CO_ACCOUNT_AR, $companyId);
assert_true(!empty($ar), 'AR account 1310 present');

// --- Percent compute (contract 4: rent 60k → 3k) ---
$c4 = $conn->query("SELECT * FROM co_shop_rental_contracts WHERE id=4 AND company_id=3")->fetch();
assert_true((bool)$c4, 'contract 4 exists');
$amt = co_shop_commission_amounts($c4);
assert_eq(3000, $amt['net'], 'percent 5% of 60000 = 3000 net');
assert_eq(150, $amt['vat'], 'VAT 5% of 3000 = 150');
assert_eq(3150, $amt['gross'], 'gross 3150');

// --- Fixed amount save (contract 1, no invoice) ---
$c1 = $conn->query("SELECT * FROM co_shop_rental_contracts WHERE id=1 AND company_id=3")->fetch();
assert_true(empty($c1['commission_invoice_id']), 'contract 1 has no commission invoice');
$saved = co_shop_save_commission_settings($conn, $companyId, 1, [
    'commission_enabled' => 1,
    'commission_basis' => 'fixed',
    'commission_percent' => 5,
    'commission_fixed_amount' => 4500,
    'commission_manual_override' => 0,
    'commission_vat_enabled' => 1,
    'commission_vat_rate' => 5,
]);
assert_eq(4500, $saved['net'], 'fixed basis net 4500');
assert_eq(225, $saved['vat'], 'fixed VAT 225');
// restore percent default for contract 1
co_shop_save_commission_settings($conn, $companyId, 1, [
    'commission_enabled' => 1,
    'commission_basis' => 'percent',
    'commission_percent' => 5,
    'commission_fixed_amount' => 0,
    'commission_manual_override' => 0,
    'commission_vat_enabled' => 1,
    'commission_vat_rate' => 5,
]);

// --- Multi-shop: attach available LS-04 to contract 4; commission stays contract-level ---
$primary = (int)$c4['shop_unit_id'];
$extraShop = 6;
co_shop_sync_contract_shops($conn, $companyId, 4, [$primary, $extraShop], $primary);
$label = co_shop_contract_shops_label($conn, $companyId, 4);
assert_true(str_contains($label, ',') || substr_count($label, 'LS-') >= 2 || str_contains($label, ' / ') || str_contains($label, ' & ') || strlen($label) > 8, 'multi-shop label: ' . $label);
$c4b = $conn->query("SELECT * FROM co_shop_rental_contracts WHERE id=4 AND company_id=3")->fetch();
$amtMulti = co_shop_commission_amounts($c4b);
assert_eq(3000, $amtMulti['net'], 'multi-shop does not multiply commission');

// --- Generate commission invoice (contract 4) ---
if (!empty($c4b['commission_invoice_id'])) {
    echo "SKIP generate — contract 4 already has commission invoice #{$c4b['commission_invoice_id']}\n";
    $invoiceId = (int)$c4b['commission_invoice_id'];
} else {
    $gen = co_shop_generate_commission_invoice($conn, $companyId, 4, $userId, date('Y-m-d'));
    $invoiceId = (int)$gen['invoice_id'];
    assert_true($invoiceId > 0, 'generated commission invoice id=' . $invoiceId);
    assert_true((int)$gen['journal_id'] > 0, 'posted journal id=' . $gen['journal_id']);
}

$inv = $conn->prepare("SELECT * FROM co_client_invoices WHERE id=? AND company_id=?");
$inv->execute([$invoiceId, $companyId]);
$invRow = $inv->fetch();
assert_eq('shop_commission', $invRow['source_type'], 'source_type shop_commission');
assert_eq(4, $invRow['source_id'], 'source_id = contract id');
assert_eq(3000, $invRow['subtotal'], 'invoice net 3000');
assert_eq(150, $invRow['vat_amount'], 'invoice VAT 150');
assert_eq(3150, $invRow['total_amount'], 'invoice gross 3150');

// Journal lines: Dr 1310, Cr 4140, Cr 2310
$jl = $conn->prepare("
    SELECT a.account_code, l.debit_amount AS debit, l.credit_amount AS credit
    FROM re_journal_lines l
    JOIN re_chart_of_accounts a ON a.id = l.account_id
    WHERE l.journal_id = ? AND a.company_id = ?
    ORDER BY l.id
");
$jl->execute([(int)$invRow['journal_id'], $companyId]);
$lines = $jl->fetchAll();
$byCode = [];
foreach ($lines as $l) {
    $byCode[$l['account_code']] = $l;
}
assert_true(isset($byCode['1310']) && (float)$byCode['1310']['debit'] > 0, 'Dr AR 1310');
assert_true(isset($byCode['4140']) && (float)$byCode['4140']['credit'] >= 2999.99, 'Cr commission 4140');
assert_true(isset($byCode['2310']) && (float)$byCode['2310']['credit'] >= 149.99, 'Cr VAT 2310');

$status = co_shop_commission_status($conn, $companyId, $conn->query("SELECT * FROM co_shop_rental_contracts WHERE id=4")->fetch());
assert_eq('invoiced', $status['status'], 'status invoiced before payment');
assert_eq(3150, $status['outstanding'], 'outstanding = gross before payment');

// --- Partial payment ---
$openBefore = co_shop_invoice_open_balance($conn, $companyId, $invoiceId);
if ($openBefore > 2000) {
    $partial = co_shop_record_invoice_payment($conn, $companyId, $invoiceId, 1000, $payAccountId, date('Y-m-d'), 'COMM-PARTIAL-TEST', $userId);
    assert_eq(1000, $partial['allocated'], 'partial allocated 1000');
    $status2 = co_shop_commission_status($conn, $companyId, $conn->query("SELECT * FROM co_shop_rental_contracts WHERE id=4")->fetch());
    assert_eq('partial', $status2['status'], 'status partial');
    assert_eq(2150, $status2['outstanding'], 'outstanding after partial 2150');
} else {
    echo "SKIP partial — open balance already {$openBefore}\n";
}

// --- Full remaining payment ---
$open = co_shop_invoice_open_balance($conn, $companyId, $invoiceId);
if ($open > 0.005) {
    $full = co_shop_record_invoice_payment($conn, $companyId, $invoiceId, $open, $payAccountId, date('Y-m-d'), 'COMM-FULL-TEST', $userId);
    assert_eq($open, $full['allocated'], 'full remaining allocated');
}
$status3 = co_shop_commission_status($conn, $companyId, $conn->query("SELECT * FROM co_shop_rental_contracts WHERE id=4")->fetch());
assert_eq('collected', $status3['status'], 'status collected');
assert_eq(0, $status3['outstanding'], 'outstanding zero');

// --- Fixed amount generate on contract 2 ---
$c2 = $conn->query("SELECT * FROM co_shop_rental_contracts WHERE id=2 AND company_id=3")->fetch();
if (empty($c2['commission_invoice_id'])) {
    co_shop_save_commission_settings($conn, $companyId, 2, [
        'commission_enabled' => 1,
        'commission_basis' => 'fixed',
        'commission_percent' => 5,
        'commission_fixed_amount' => 2000,
        'commission_manual_override' => 0,
        'commission_vat_enabled' => 1,
        'commission_vat_rate' => 5,
    ]);
    $gen2 = co_shop_generate_commission_invoice($conn, $companyId, 2, $userId, date('Y-m-d'));
    $inv2 = $conn->prepare("SELECT * FROM co_client_invoices WHERE id=?");
    $inv2->execute([$gen2['invoice_id']]);
    $r2 = $inv2->fetch();
    assert_eq(2000, $r2['subtotal'], 'fixed invoice net 2000');
    assert_eq(2100, $r2['total_amount'], 'fixed invoice gross 2100');
    $pay2 = co_shop_record_invoice_payment($conn, $companyId, (int)$gen2['invoice_id'], 2100, $payAccountId, date('Y-m-d'), 'COMM-FIXED-FULL', $userId);
    assert_eq(2100, $pay2['allocated'], 'fixed full payment');
} else {
    echo "SKIP fixed generate — contract 2 already invoiced\n";
}

// Control center snapshot
$snap = co_shop_control_center_snapshot($conn, $companyId);
assert_true(isset($snap['commission']['invoiced']), 'control center has commission block');
assert_true((float)$snap['commission']['invoiced'] > 0, 'control center commission invoiced > 0');

echo "\n=== Results: {$ok} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);
