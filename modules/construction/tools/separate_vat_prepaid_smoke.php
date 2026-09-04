<?php
/**
 * Separate VAT prepaid liability model smoke test.
 * Usage: php modules/construction/tools/separate_vat_prepaid_smoke.php
 *
 * Validates BR-CO-SHOP-002 redesign:
 * - Receipt → prepaid Output VAT liability (2330), no VAT Tax Invoice
 * - Monthly invoices are Tax Invoices (full Output VAT)
 * - Consume prepaid → outstanding = rent only
 * - Payment workspace balances use collectible (rent)
 * - Cheque plan still creates VAT_SEPARATE (payment workflow unchanged)
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
if (!is_link('/tmp/mysql.sock') && !file_exists('/tmp/mysql.sock')) {
    @symlink('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock');
}
require_once $root . '/includes/db_connect.php';
require_once $root . '/modules/construction/includes/construction_income_helpers.php';
require_once $root . '/modules/construction/includes/construction_shop_rental_helpers.php';
require_once $root . '/modules/construction/includes/construction_accounting_integration.php';
require_once $root . '/modules/construction/includes/construction_receipt_allocation_service.php';

$passed = 0;
$failed = 0;

function sv_assert(bool $ok, string $label, $meta = null): void {
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}" . ($meta !== null ? ' ' . json_encode($meta, JSON_UNESCAPED_SLASHES) : '') . "\n";
        $failed++;
    }
}

function sv_sum(array $periods, string $key): float {
    $s = 0.0;
    foreach ($periods as $p) {
        $s = round($s + (float)($p[$key] ?? 0), 2);
    }
    return $s;
}

echo "=== Separate VAT prepaid liability smoke ===\n";

sv_assert(defined('CO_ACCOUNT_PREPAID_OUTPUT_VAT') && CO_ACCOUNT_PREPAID_OUTPUT_VAT === '2330', 'COA constant 2330');
$acc = find_account_by_code(CO_ACCOUNT_PREPAID_OUTPUT_VAT, 3);
sv_assert(!empty($acc['id']), 'COA 2330 exists for company 3', $acc);

$contract = [
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'rent_amount' => 120000,
    'vat_rate' => 5,
    'vat_mode' => 'exclusive',
    'vat_collection_method' => CO_SHOP_VAT_SEPARATE,
    'payment_frequency' => 'monthly',
    'concession_enabled' => 0,
];

$totals = co_shop_contract_rent_totals($contract);
sv_assert(abs($totals['vat'] - 6000) < 0.02, 'contract vat = 6000', $totals);

$months = co_shop_build_earning_months($contract);
sv_assert(count($months) === 12, '12 earning months');
sv_assert(abs(sv_sum($months, 'vat') - 6000) < 0.02, 'VAT distributed on schedule', sv_sum($months, 'vat'));
sv_assert(abs((float)$months[0]['gross'] - 10500) < 0.02, 'month1 invoice total 10500', $months[0]);

$buckets = co_shop_distribute_rent_cheques($contract, $months, 12);
sv_assert(abs(sv_sum($buckets, 'vat')) < 0.02, 'rent cheques exclude VAT (payment workflow)', sv_sum($buckets, 'vat'));

sv_assert(co_shop_cheque_preferred_invoice_kinds(['notes' => 'VAT_SEPARATE']) === [], 'VAT_SEPARATE → no invoice kinds');
sv_assert(co_receipt_is_prepaid_vat_cheque_purpose([['notes' => 'VAT_SEPARATE']]), 'prepaid VAT purpose detector');
sv_assert(!co_receipt_is_prepaid_vat_cheque_purpose([['notes' => 'COMBINED_FIRST']]), 'combined first is not pure prepaid');

// Live contract 9 (if present): collectible = rent after prepaid_vat_applied migration
try {
    $s = co_shop_contract_financial_summary($conn, 3, 9);
    if ($s) {
        $rentOpen = null;
        foreach ($s['open_invoices'] ?? [] as $o) {
            if (($o['kind'] ?? '') === 'rent') {
                $rentOpen = $o;
                break;
            }
        }
        if ($rentOpen) {
            sv_assert(
                abs((float)$rentOpen['balance'] - (float)$rentOpen['subtotal']) < 0.02
                || abs((float)$rentOpen['balance'] - ((float)$rentOpen['total_amount'] - (float)$rentOpen['prepaid_vat_applied'])) < 0.02,
                'live rent open balance excludes prepaid VAT',
                [
                    'balance' => $rentOpen['balance'],
                    'subtotal' => $rentOpen['subtotal'],
                    'applied' => $rentOpen['prepaid_vat_applied'] ?? null,
                    'total' => $rentOpen['total_amount'],
                ]
            );
            $ws = co_receipt_open_invoices_for_contract($conn, 3, 9);
            $wsRent = null;
            foreach ($ws as $w) {
                if (($w['kind'] ?? '') === 'rent') {
                    $wsRent = $w;
                    break;
                }
            }
            if ($wsRent) {
                sv_assert(
                    abs((float)$wsRent['balance'] - (float)$rentOpen['balance']) < 0.02,
                    'workspace balance matches financial summary',
                    $wsRent
                );
            }
        } else {
            echo "INFO  contract 9 has no open rent invoices\n";
        }
    }
} catch (Throwable $e) {
    echo "INFO  skip live contract 9: {$e->getMessage()}\n";
}

// DB path: create temp contract, schedule (no VAT row), prepaid receipt, invoice, verify journals
$companyId = 3;
try {
    $clientId = (int)$conn->query("SELECT id FROM co_clients WHERE company_id = {$companyId} ORDER BY id LIMIT 1")->fetchColumn();
    $shopUnitId = (int)$conn->query("SELECT id FROM co_shop_units WHERE company_id = {$companyId} ORDER BY id LIMIT 1")->fetchColumn();
    $bankId = (int)$conn->query("SELECT id FROM re_chart_of_accounts WHERE company_id = {$companyId} AND account_code = '1230' AND is_active = 1 LIMIT 1")->fetchColumn();
    if (!$bankId) {
        $bankId = (int)$conn->query("SELECT id FROM re_chart_of_accounts WHERE company_id = {$companyId} AND account_code IN ('1210','1110') AND is_active = 1 LIMIT 1")->fetchColumn();
    }
    sv_assert($clientId > 0 && $shopUnitId > 0 && $bankId > 0, 'fixtures available', compact('clientId', 'shopUnitId', 'bankId'));

    $conn->beginTransaction();
    $cn = 'SMOKE-PPV-' . date('YmdHis');
    $conn->prepare("
        INSERT INTO co_shop_rental_contracts
            (company_id, shop_unit_id, client_id, contract_number, start_date, end_date, rent_amount, vat_rate, vat_mode,
             vat_collection_method, payment_frequency, status, commission_enabled, accrual_deferred_rent)
        VALUES (?, ?, ?, ?, '2026-01-01', '2026-12-31', 120000, 5, 'exclusive', 'separate', 'monthly', 'active', 0, 0)
    ")->execute([$companyId, $shopUnitId, $clientId, $cn]);
    $contractId = (int)$conn->lastInsertId();

    $created = co_shop_generate_schedules_impl($conn, $companyId, $contractId);
    $rows = $conn->prepare("SELECT * FROM co_shop_rent_schedules WHERE company_id = ? AND contract_id = ?");
    $rows->execute([$companyId, $contractId]);
    $sched = $rows->fetchAll(PDO::FETCH_ASSOC);
    $rentRows = array_values(array_filter($sched, static fn($r) => ($r['schedule_type'] ?? 'rent') === 'rent'));
    $vatRows = array_values(array_filter($sched, static fn($r) => ($r['schedule_type'] ?? '') === 'vat'));
    sv_assert(count($rentRows) === 12, '12 rent schedules', count($rentRows));
    sv_assert(count($vatRows) === 0, 'no VAT-only schedule created', count($vatRows));
    sv_assert($created === 12, 'created count = 12 rent only', $created);

    // Prepaid VAT receipt
    $rcpt = co_shop_record_prepaid_vat_receipt(
        $conn, $companyId, $clientId, $contractId, 6000.0, $bankId, '2026-01-05', 'SMOKE-VAT', 1
    );
    sv_assert($rcpt['payment_id'] > 0, 'prepaid receipt created', $rcpt);
    sv_assert(abs(co_shop_contract_prepaid_vat_balance($conn, $companyId, $contractId) - 6000) < 0.02, 'prepaid balance 6000 after receipt');

    $pay = $conn->prepare("SELECT receipt_purpose, prepaid_vat_amount, journal_id FROM co_client_payments WHERE id = ? AND company_id = ?");
    $pay->execute([(int)$rcpt['payment_id'], $companyId]);
    $payRow = $pay->fetch(PDO::FETCH_ASSOC);
    sv_assert(($payRow['receipt_purpose'] ?? '') === 'prepaid_output_vat', 'receipt_purpose prepaid_output_vat', $payRow);

    $jl = $conn->prepare("
        SELECT a.account_code, jl.debit_amount, jl.credit_amount
        FROM re_journal_lines jl
        JOIN re_chart_of_accounts a ON a.id = jl.account_id
        WHERE jl.journal_id = ?
        ORDER BY jl.line_number
    ");
    $jl->execute([(int)$payRow['journal_id']]);
    $lines = $jl->fetchAll(PDO::FETCH_ASSOC);
    $by = [];
    foreach ($lines as $ln) {
        $by[$ln['account_code']] = $ln;
    }
    sv_assert(isset($by['2330']) && (float)$by['2330']['credit_amount'] >= 5999.99, 'receipt Cr 2330', $by);
    sv_assert(!isset($by['1310']) || (float)($by['1310']['credit_amount'] ?? 0) < 0.01, 'receipt does not credit AR', $by);

    // Invoice first month
    $sch = $rentRows[0];
    $invId = co_create_income_invoice(
        $conn, $companyId, $clientId, 0, 'shop_rental', (int)$sch['id'],
        'SMOKE-INV-' . time(), $sch['due_date'], $sch['due_date'],
        (float)$sch['net_amount'], (float)$sch['vat_amount'], 'Smoke rent', 0, 1
    );
    // income account
    $inc = co_default_income_account_id($conn, $companyId, 'shop_rental');
    if ($inc > 0) {
        $conn->prepare("UPDATE co_client_invoices SET income_account_id = ? WHERE id = ?")->execute([$inc, $invId]);
    }
    $post = co_post_client_invoice_to_accounting($invId, $companyId, 1);
    sv_assert(!empty($post['success']), 'invoice post ok', $post);
    $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ?")->execute([$post['journal_id'], $invId]);

    $inv = $conn->prepare("SELECT * FROM co_client_invoices WHERE id = ?");
    $inv->execute([$invId]);
    $invRow = $inv->fetch(PDO::FETCH_ASSOC);
    sv_assert(abs((float)$invRow['vat_amount'] - 500) < 0.02, 'invoice vat 500', $invRow);
    sv_assert(abs((float)$invRow['prepaid_vat_applied'] - 500) < 0.02, 'prepaid applied 500', $invRow);
    $coll = co_shop_invoice_collectible_amount($conn, $companyId, $invRow);
    sv_assert(abs($coll - 10000) < 0.02, 'collectible = rent 10000', $coll);
    $open = co_shop_invoice_open_balance($conn, $companyId, $invId);
    sv_assert(abs($open - 10000) < 0.02, 'open balance = 10000', $open);
    sv_assert(abs(co_shop_contract_prepaid_vat_balance($conn, $companyId, $contractId) - 5500) < 0.02, 'prepaid remaining 5500');

    $jl->execute([(int)$post['journal_id']]);
    $invLines = $jl->fetchAll(PDO::FETCH_ASSOC);
    $iby = [];
    foreach ($invLines as $ln) {
        $code = $ln['account_code'];
        if (!isset($iby[$code])) {
            $iby[$code] = ['d' => 0.0, 'c' => 0.0];
        }
        $iby[$code]['d'] += (float)$ln['debit_amount'];
        $iby[$code]['c'] += (float)$ln['credit_amount'];
    }
    sv_assert(($iby['1310']['d'] ?? 0) >= 10499.99, 'invoice Dr AR gross', $iby);
    sv_assert(($iby['2310']['c'] ?? 0) >= 499.99, 'invoice Cr Output VAT 2310', $iby);

    // Consume journal exists
    $cj = $conn->prepare("
        SELECT id FROM re_journal_headers
        WHERE company_id = ? AND reference_type = 'co_prepaid_vat_apply' AND reference_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $cj->execute([$companyId, $invId]);
    $consumeId = (int)$cj->fetchColumn();
    sv_assert($consumeId > 0, 'consume journal posted', $consumeId);
    $jl->execute([$consumeId]);
    $cLines = $jl->fetchAll(PDO::FETCH_ASSOC);
    $cby = [];
    foreach ($cLines as $ln) {
        $cby[$ln['account_code']] = $ln;
    }
    sv_assert(isset($cby['2330']) && (float)$cby['2330']['debit_amount'] >= 499.99, 'consume Dr 2330', $cby);
    sv_assert(isset($cby['1310']) && (float)$cby['1310']['credit_amount'] >= 499.99, 'consume Cr AR', $cby);

    $conn->rollBack();
    echo "INFO  rolled back temporary smoke contract\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sv_assert(false, 'DB prepaid liability smoke', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n=== Result: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
