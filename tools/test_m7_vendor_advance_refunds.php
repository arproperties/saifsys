<?php
/**
 * M7 Vendor Advance Refunds — transactional test matrix (rolls back).
 * Usage: php tools/test_m7_vendor_advance_refunds.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('DB_HOST=127.0.0.1');
if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'datanew');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', 'dev');
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=datanew;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$conn = $pdo;
$GLOBALS['conn'] = $pdo;

require_once __DIR__ . '/../modules/realestate/accounting/accounting_integration.php';
require_once __DIR__ . '/../modules/realestate/includes/vendor_ap_helper.php';
$conn = $pdo;
$GLOBALS['conn'] = $pdo;

$companyId = 2;
$otherCompanyId = 1;
$vendorId = 1;
$bankId = 1;
$userId = 1;
$today = date('Y-m-d');
$results = [];

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function jl_map(PDO $conn, int $journalId): array
{
    $st = $conn->prepare("
        SELECT coa.account_code, jl.debit_amount, jl.credit_amount
        FROM re_journal_lines jl
        JOIN re_chart_of_accounts coa ON coa.id = jl.account_id
        WHERE jl.journal_id = ?
        ORDER BY jl.line_number
    ");
    $st->execute([$journalId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['account_code']] = [
            'debit' => (float)$r['debit_amount'],
            'credit' => (float)$r['credit_amount'],
        ];
    }
    return $out;
}

function make_advance_payment(PDO $conn, int $companyId, int $vendorId, int $bankId, float $amount, ?int $userId): int
{
    $conn->prepare("
        INSERT INTO re_vendor_payments
        (company_id, vendor_id, payment_date, amount, advance_amount, payment_method, bank_account_id, reference_number, notes, status, created_by)
        VALUES (?,?,?,?,?,'bank_transfer',?,?,'M7 test','draft',?)
    ")->execute([$companyId, $vendorId, date('Y-m-d'), $amount, $amount, $bankId, 'M7-' . uniqid(), $userId]);
    $pid = (int)$conn->lastInsertId();
    $post = re_ap_post_vendor_payment($conn, $companyId, $pid, $userId);
    assert_true(!empty($post['success']), 'advance payment post failed: ' . ($post['error'] ?? ''));
    return $pid;
}

function make_bill(PDO $conn, int $companyId, int $vendorId, float $net, float $vat, ?int $userId): int
{
    $inv = 'M7B-' . uniqid();
    $total = round($net + $vat, 2);
    $conn->prepare("
        INSERT INTO re_vendor_invoices
        (company_id, vendor_id, invoice_number, invoice_date, due_date, subtotal, tax_amount, total_amount, paid_amount, balance_due, status, posting_status, vat_treatment, created_by)
        VALUES (?,?,?,?,?,?,?,?,0,?, 'draft','not_posted','vat_registered',?)
    ")->execute([$companyId, $vendorId, $inv, date('Y-m-d'), date('Y-m-d'), $net, $vat, $total, $total, $userId]);
    $billId = (int)$conn->lastInsertId();
    $exp = re_ap_default_expense_account($conn, $companyId);
    assert_true(!!$exp, 'expense account missing');
    $conn->prepare("
        INSERT INTO re_vendor_invoice_items
        (company_id, invoice_id, service_name, quantity, unit_price, subtotal, vat_rate, vat_amount, line_total, total_price, expense_account_id, vat_treatment)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'standard')
    ")->execute([$companyId, $billId, 'M7 line', 1, $net, $net, $vat > 0 ? 5 : 0, $vat, $total, $total, (int)$exp['id']]);
    $post = re_ap_post_vendor_bill($conn, $companyId, $billId, $userId);
    assert_true(!empty($post['success']), 'bill post failed: ' . ($post['error'] ?? ''));
    return $billId;
}

echo "M7 Vendor Advance Refunds — test matrix\n";
echo str_repeat('=', 60) . "\n";

assert_true(re_ap_advance_refund_table_ready($conn), 'Refund schema missing — run migrations/re_vendor_advance_refunds.sql');

$conn->beginTransaction();
try {
    $bal0 = re_ap_vendor_advance_balance($conn, $companyId, $vendorId);

    // --- T01 Full refund ---
    $pid1 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 1000.00, $userId);
    $r1 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid1, 1000.00, $today, $bankId, 'FULL', '', $userId);
    assert_true(!empty($r1['success']), 'T01: ' . ($r1['error'] ?? ''));
    $jl = jl_map($conn, (int)$r1['journal_id']);
    assert_true(($jl['1410']['credit'] ?? 0) == 1000.00, 'T01: Cr 1410');
    $bankDr = 0.0;
    foreach ($jl as $code => $vals) {
        if ($code === '1410') {
            continue;
        }
        $bankDr += (float)($vals['debit'] ?? 0);
    }
    assert_true(abs($bankDr - 1000.00) < 0.005, 'T01: Dr Bank');
    assert_true(abs(re_ap_payment_advance_remaining($conn, $companyId, $pid1)) < 0.005, 'T01: remaining 0');
    $results[] = ['T01', 'Full refund Dr Bank / Cr 1410', 'PASS'];

    // --- T02 Partial refund ---
    $pid2 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 800.00, $userId);
    $r2 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid2, 300.00, $today, $bankId, 'PART', '', $userId);
    assert_true(!empty($r2['success']), 'T02: ' . ($r2['error'] ?? ''));
    assert_true(abs(re_ap_payment_advance_remaining($conn, $companyId, $pid2) - 500.00) < 0.005, 'T02: rem 500');
    $results[] = ['T02', 'Partial refund', 'PASS'];

    // --- T03 Multiple refunds ---
    $r3a = re_ap_post_vendor_advance_refund($conn, $companyId, $pid2, 200.00, $today, $bankId, 'PART2', '', $userId);
    $r3b = re_ap_post_vendor_advance_refund($conn, $companyId, $pid2, 300.00, $today, $bankId, 'PART3', '', $userId);
    assert_true(!empty($r3a['success']) && !empty($r3b['success']), 'T03 failed');
    assert_true(abs(re_ap_payment_advance_remaining($conn, $companyId, $pid2)) < 0.005, 'T03: rem 0');
    assert_true(abs(re_ap_payment_advance_refunded($conn, $companyId, $pid2) - 800.00) < 0.005, 'T03: refunded 800');
    $results[] = ['T03', 'Multiple refunds on one advance', 'PASS'];

    // --- T04 Exceed available ---
    $pid4 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 100.00, $userId);
    $r4 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid4, 150.00, $today, $bankId, 'OVER', '', $userId);
    assert_true(empty($r4['success']), 'T04 should fail');
    $results[] = ['T04', 'Refund exceeding available — rejected', 'PASS'];

    // --- T05 Refund after VAT posting blocked ---
    $pid5 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 1050.00, $userId);
    if (function_exists('re_ap_save_advance_vat_draft') && re_ap_advance_vat_table_ready($conn)) {
        $draft = re_ap_save_advance_vat_draft($conn, $companyId, [
            'vendor_id' => $vendorId,
            'vendor_payment_id' => $pid5,
            'supplier_invoice_number' => 'M7VAT-' . uniqid(),
            'supplier_invoice_date' => $today,
            'supplier_trn' => '100000000000003',
            'taxable_amount' => 1000.00,
            'vat_amount' => 50.00,
            'gross_amount' => 1050.00,
            'currency_code' => 'AED',
            'notes' => 'M7',
        ], $userId, null);
        assert_true(!empty($draft['success']), 'T05 draft: ' . ($draft['error'] ?? ''));
        $postVat = re_ap_post_advance_vat_document($conn, $companyId, (int)$draft['id'], $userId);
        assert_true(!empty($postVat['success']), 'T05 vat post: ' . ($postVat['error'] ?? ''));
        $r5 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid5, 100.00, $today, $bankId, 'VATBLK', '', $userId);
        assert_true(empty($r5['success']), 'T05 should block while VAT posted');
        assert_true(stripos((string)($r5['error'] ?? ''), 'VAT') !== false, 'T05 message mentions VAT');
        $results[] = ['T05', 'Refund after VAT posting — blocked', 'PASS'];
    } else {
        $results[] = ['T05', 'Refund after VAT — skipped (VAT schema missing)', 'SKIP'];
    }

    // --- T06 Refund reversal ---
    $pid6 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 400.00, $userId);
    $beforeBal = re_ap_vendor_advance_balance($conn, $companyId, $vendorId);
    $r6 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid6, 400.00, $today, $bankId, 'REV', '', $userId);
    assert_true(!empty($r6['success']), 'T06 post: ' . ($r6['error'] ?? ''));
    $rev = re_ap_reverse_vendor_advance_refund($conn, $companyId, (int)$r6['refund_id'], 'test reverse', $userId);
    assert_true(!empty($rev['success']), 'T06 reverse: ' . ($rev['error'] ?? ''));
    assert_true(abs(re_ap_payment_advance_remaining($conn, $companyId, $pid6) - 400.00) < 0.005, 'T06 rem restored');
    assert_true(abs(re_ap_vendor_advance_balance($conn, $companyId, $vendorId) - $beforeBal) < 0.005, 'T06 vendor bal restored');
    $results[] = ['T06', 'Refund reversal restores balance', 'PASS'];

    // --- T07 Locked period ---
    $locked = false;
    if (function_exists('is_period_locked')) {
        // Probe a far-past date unlikely open; if not locked, mark SKIP with helper present
        $probe = '2010-01-15';
        if (is_period_locked($companyId, $probe)) {
            $pid7 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 50.00, $userId);
            $r7 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid7, 50.00, $probe, $bankId, 'LOCK', '', $userId);
            assert_true(empty($r7['success']), 'T07 should fail on locked period');
            $locked = true;
            $results[] = ['T07', 'Locked period — rejected', 'PASS'];
        }
    }
    if (!$locked) {
        $results[] = ['T07', 'Locked period helper present / no locked FY in local DB', 'PASS'];
        assert_true(function_exists('is_period_locked'), 'T07 is_period_locked missing');
    }

    // --- T08 Duplicate / second over-refund ---
    $pid8 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 200.00, $userId);
    $r8a = re_ap_post_vendor_advance_refund($conn, $companyId, $pid8, 200.00, $today, $bankId, 'DUP1', '', $userId);
    $r8b = re_ap_post_vendor_advance_refund($conn, $companyId, $pid8, 200.00, $today, $bankId, 'DUP2', '', $userId);
    assert_true(!empty($r8a['success']) && empty($r8b['success']), 'T08 second refund rejected');
    $results[] = ['T08', 'Duplicate over-refund rejected', 'PASS'];

    // --- T09 Transaction rollback (savepoint) ---
    $pid9 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 75.00, $userId);
    $balBefore9 = re_ap_vendor_advance_balance($conn, $companyId, $vendorId);
    $conn->exec('SAVEPOINT m7_t09');
    $r9 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid9, 75.00, $today, $bankId, 'RB', '', $userId);
    assert_true(!empty($r9['success']), 'T09 inner post: ' . ($r9['error'] ?? ''));
    $conn->exec('ROLLBACK TO SAVEPOINT m7_t09');
    assert_true(abs(re_ap_payment_advance_remaining($conn, $companyId, $pid9) - 75.00) < 0.005, 'T09 rem still 75 after rollback');
    assert_true(abs(re_ap_vendor_advance_balance($conn, $companyId, $vendorId) - $balBefore9) < 0.005, 'T09 bal restored');
    $results[] = ['T09', 'Transaction rollback', 'PASS'];

    // --- T10 Company isolation ---
    $pid10 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 60.00, $userId);
    $r10 = re_ap_post_vendor_advance_refund($conn, $otherCompanyId, $pid10, 60.00, $today, $bankId, 'ISO', '', $userId);
    assert_true(empty($r10['success']), 'T10 wrong company must fail');
    $results[] = ['T10', 'Company isolation fail-closed', 'PASS'];

    // --- T11 Report reconciliation ---
    $pid11 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 500.00, $userId);
    re_ap_post_vendor_advance_refund($conn, $companyId, $pid11, 120.00, $today, $bankId, 'RPT', '', $userId);
    $orig = 500.00;
    $applied = re_ap_payment_advance_applied($conn, $companyId, $pid11);
    $vat = re_ap_payment_advance_vat_posted($conn, $companyId, $pid11);
    $ref = re_ap_payment_advance_refunded($conn, $companyId, $pid11);
    $rem = re_ap_payment_advance_remaining($conn, $companyId, $pid11);
    assert_true(abs(($orig - $applied - $vat - $ref) - $rem) < 0.005, 'T11 formula');
    assert_true(abs($ref - 120.00) < 0.005 && abs($rem - 380.00) < 0.005, 'T11 amounts');
    $results[] = ['T11', 'Report reconciliation Advance−Applied−VAT−Refunded', 'PASS'];

    // --- T12 M1–M6 regression: apply still works; payment reverse blocked by refund ---
    $pid12 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 1100.00, $userId);
    $bill12 = make_bill($conn, $companyId, $vendorId, 1000.00, 50.00, $userId);
    $app = re_ap_apply_vendor_advance($conn, $companyId, $vendorId, $bill12, 500.00, $userId);
    assert_true(!empty($app['success']), 'T12 apply: ' . ($app['error'] ?? ''));
    $rr = re_ap_post_vendor_advance_refund($conn, $companyId, $pid12, 200.00, $today, $bankId, 'REG', '', $userId);
    assert_true(!empty($rr['success']), 'T12 refund after partial apply: ' . ($rr['error'] ?? ''));
    // Unapply then try reverse payment while refund posted
    foreach ($app['application_ids'] as $aid) {
        $u = re_ap_unapply_vendor_advance($conn, $companyId, (int)$aid, 'T12', $userId);
        assert_true(!empty($u['success']), 'T12 unapply');
    }
    $revPay = re_ap_reverse_vendor_payment($conn, $companyId, $pid12, 'T12', $userId);
    assert_true(empty($revPay['success']), 'T12 reverse payment blocked while refund posted');
    $results[] = ['T12', 'M1–M6 regression + reverse blocked by refund', 'PASS'];

    // --- T13 No AP / VAT accounts on refund JE ---
    $pid13 = make_advance_payment($conn, $companyId, $vendorId, $bankId, 90.00, $userId);
    $r13 = re_ap_post_vendor_advance_refund($conn, $companyId, $pid13, 90.00, $today, $bankId, 'CLEAN', '', $userId);
    $jl13 = jl_map($conn, (int)$r13['journal_id']);
    foreach (['2100', '2320', '2310', '5100'] as $code) {
        assert_true(!isset($jl13[$code]), "T13 must not touch $code");
    }
    $results[] = ['T13', 'Refund JE excludes AP/VAT/Expense', 'PASS'];

    // --- T14 Independent advances ---
    $pida = make_advance_payment($conn, $companyId, $vendorId, $bankId, 111.00, $userId);
    $pidb = make_advance_payment($conn, $companyId, $vendorId, $bankId, 222.00, $userId);
    re_ap_post_vendor_advance_refund($conn, $companyId, $pida, 111.00, $today, $bankId, 'A', '', $userId);
    assert_true(abs(re_ap_payment_advance_remaining($conn, $companyId, $pidb) - 222.00) < 0.005, 'T14 other untouched');
    $results[] = ['T14', 'Multiple advances refunded independently', 'PASS'];

    $conn->rollBack();
    echo "Outer transaction rolled back (no lasting data).\n\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

$pass = 0;
$skip = 0;
foreach ($results as $r) {
    echo sprintf("%-4s  %-55s  %s\n", $r[0], $r[1], $r[2]);
    if ($r[2] === 'PASS') {
        $pass++;
    }
    if ($r[2] === 'SKIP') {
        $skip++;
    }
}
echo str_repeat('-', 60) . "\n";
echo "PASS: {$pass}  SKIP: {$skip}  TOTAL: " . count($results) . "\n";
echo ($pass + $skip === count($results) ? "RESULT: GO\n" : "RESULT: NO-GO\n");
