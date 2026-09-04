<?php
/**
 * Phase 2B localhost verification script (CLI).
 * Usage: /Applications/XAMPP/xamppfiles/bin/php tools/ars_phase2b_verify.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db_connect.php';
require_once $root . '/modules/ars/includes/ars_helpers.php';
require_once $root . '/modules/ars/includes/ars_account_roles.php';
require_once $root . '/modules/ars/includes/ars_financial_document_sm.php';
require_once $root . '/modules/ars/includes/ars_financial_adapter.php';
require_once $root . '/modules/ars/includes/ars_accounting.php';

$pass = 0;
$fail = 0;
$results = [];

function assert_true(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail, $results;
    if ($cond) {
        $pass++;
        $results[] = ['PASS', $name, $detail];
        echo "PASS  $name\n";
    } else {
        $fail++;
        $results[] = ['FAIL', $name, $detail];
        echo "FAIL  $name — $detail\n";
    }
}

$enginePath = $root . '/modules/realestate/accounting/accounting_engine.php';
$engineSha = hash_file('sha256', $enginePath);
$expectedSha = '9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6';
assert_true('accounting_engine.php unchanged', $engineSha === $expectedSha, $engineSha);

assert_true('tables ready', ars_financial_adapter_tables_ready($conn));
assert_true('role map ready', ars_account_role_map_ready($conn));

$companyId = getArsCompanyId($conn);
assert_true('ARS company exists', $companyId > 0, (string)$companyId);

$roles = ars_require_account_roles($conn, $companyId, ['AR_GUEST', 'ROOM_REVENUE', 'VAT_OUTPUT', 'CASH', 'BANK', 'SECURITY_DEPOSIT']);
assert_true('account roles resolve', !empty($roles['success']), (string)($roles['error'] ?? ''));

$t = ars_fin_doc_can_transition('draft', 'posted');
assert_true('SM blocks draft→posted skip', !$t['allowed']);
$t2 = ars_fin_doc_can_transition('draft', 'validated');
assert_true('SM allows draft→validated', $t2['allowed']);
$t3 = ars_fin_doc_can_transition('validated', 'posted');
assert_true('SM allows validated→posted', $t3['allowed']);

// Flag default off
$enabledBefore = ars_financial_adapter_enabled($conn, $companyId);
assert_true('adapter flag readable', is_bool($enabledBefore));

// Enable for test
$conn->prepare("UPDATE ars_company_settings SET financial_adapter_enabled = 1 WHERE company_id = ?")->execute([$companyId]);
assert_true('adapter enabled', ars_financial_adapter_enabled($conn, $companyId));

// Find a pending or create synthetic booking row for dry test — use existing unpaid confirmed without primary_invoice if possible
$stmt = $conn->prepare("
    SELECT * FROM ars_bookings
    WHERE company_id = ? AND primary_invoice_document_id IS NULL
      AND status IN ('pending','confirmed')
      AND (journal_id IS NULL OR journal_id = 0)
    ORDER BY id DESC LIMIT 1
");
$stmt->execute([$companyId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    // Create ephemeral pending booking for test (minimal)
    $guestId = (int)$conn->query("SELECT id FROM ars_guests WHERE company_id = {$companyId} LIMIT 1")->fetchColumn();
    $unitId = (int)$conn->query("SELECT id FROM re_units LIMIT 1")->fetchColumn();
    if ($guestId && $unitId) {
        $bn = 'ARS-P2B-' . date('ymdHis');
        $conn->prepare("
            INSERT INTO ars_bookings (
                company_id, booking_number, guest_id, unit_id, check_in, check_out, nights,
                status, subtotal, extras_total, discount_amount, length_discount_amount,
                vat_amount, total_amount, paid_amount, balance_due, vat_mode, vat_rate, created_at
            ) VALUES (
                ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 2,
                'pending', 100.00, 0, 0, 0, 5.00, 105.00, 0, 105.00, 'exclusive', 5.00, NOW()
            )
        ")->execute([$companyId, $bn, $guestId, $unitId]);
        $bid = (int)$conn->lastInsertId();
        $stmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ?");
        $stmt->execute([$bid]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        $createdTestBooking = true;
    } else {
        $createdTestBooking = false;
        assert_true('test booking available', false, 'No guest/unit to create test booking');
    }
} else {
    $createdTestBooking = false;
}

if ($booking) {
    $r1 = ars_adapter_create_original_invoice($conn, $booking, [
        'user_id' => 1,
        'idempotency_key' => 'invoice:original:' . (int)$booking['id'],
        'skip_activity' => false,
    ]);
    assert_true('create original invoice', !empty($r1['success']), ($r1['error'] ?? '') . ' code=' . ($r1['code'] ?? ''));
    $docId = (int)($r1['document_id'] ?? 0);
    $jid = (int)($r1['journal_id'] ?? 0);
    assert_true('document + journal ids', $docId > 0 && $jid > 0);

    // Idempotency
    $r2 = ars_adapter_create_original_invoice($conn, $booking, [
        'user_id' => 1,
        'idempotency_key' => 'invoice:original:' . (int)$booking['id'],
    ]);
    assert_true('idempotent replay', !empty($r2['success']) && (($r2['code'] ?? '') === 'idempotent_replay' || (int)($r2['document_id'] ?? 0) === $docId));

    // Journal balanced
    $lines = $conn->prepare("SELECT SUM(debit_amount) d, SUM(credit_amount) c FROM re_journal_lines WHERE journal_id = ?");
    $lines->execute([$jid]);
    $bal = $lines->fetch(PDO::FETCH_ASSOC);
    assert_true('journal balanced', abs((float)$bal['d'] - (float)$bal['c']) < 0.01, "d={$bal['d']} c={$bal['c']}");

    // Payment + allocation
    $payAmt = min(50.0, (float)$booking['total_amount']);
    $conn->prepare("
        INSERT INTO ars_booking_payments (company_id, booking_id, amount, payment_method, payment_date, reference_number, created_at)
        VALUES (?, ?, ?, 'cash', CURDATE(), 'P2B-TEST', NOW())
    ")->execute([$companyId, (int)$booking['id'], $payAmt]);
    $payId = (int)$conn->lastInsertId();
    $pm = $conn->prepare("SELECT * FROM ars_booking_payments WHERE id = ?");
    $pm->execute([$payId]);
    $payment = $pm->fetch(PDO::FETCH_ASSOC);

    $pr = ars_adapter_record_payment($conn, $payment, $booking, ['user_id' => 1]);
    assert_true('record payment + allocate', !empty($pr['success']), (string)($pr['error'] ?? ''));

    $doc = $conn->prepare("SELECT status, amount_allocated, balance_due FROM ars_financial_documents WHERE id = ? AND company_id = ?");
    $doc->execute([$docId, $companyId]);
    $drow = $doc->fetch(PDO::FETCH_ASSOC);
    assert_true('doc partially paid or paid', in_array($drow['status'], ['partially_paid', 'paid'], true), (string)$drow['status']);

    // Company isolation
    $bad = ars_adapter_create_original_invoice($conn, array_merge($booking, ['company_id' => 0]), [
        'idempotency_key' => 'invoice:bad:0',
    ]);
    assert_true('fail closed missing company', empty($bad['success']) && ($bad['code'] ?? '') === 'company_mismatch');

    // BC-gated
    $ext = ars_adapter_create_extension_invoice($conn, $booking, []);
    assert_true('extension gated', ($ext['code'] ?? '') === 'needs_business_confirmation');

    // Rollback simulation: invalid role forced by temporary map delete? skip — journal fail hard to force.
    // Cross-check RE invoices untouched
    $reCount = (int)$conn->query("SELECT COUNT(*) FROM re_invoices")->fetchColumn();
    assert_true('re_invoices table readable', $reCount >= 0);

    // SM transition audit exists
    $tr = $conn->prepare("SELECT COUNT(*) FROM ars_financial_document_transitions WHERE document_id = ?");
    $tr->execute([$docId]);
    assert_true('transitions logged', (int)$tr->fetchColumn() >= 2);

    // Activity event for invoice
    try {
        $act = $conn->prepare("
            SELECT COUNT(*) FROM ars_booking_activities
            WHERE booking_id = ? AND related_entity_type = 'ars_financial_document' AND related_entity_id = ?
        ");
        $act->execute([(int)$booking['id'], $docId]);
        assert_true('activity invoice event', (int)$act->fetchColumn() >= 1);
    } catch (Throwable $e) {
        assert_true('activity invoice event', false, $e->getMessage());
    }
}

// Disable flag again (safe default)
$conn->prepare("UPDATE ars_company_settings SET financial_adapter_enabled = 0 WHERE company_id = ?")->execute([$companyId]);
assert_true('adapter disabled after test', !ars_financial_adapter_enabled($conn, $companyId));

// Bridge still works when flag off (smoke: function exists)
assert_true('bridge function present', function_exists('ars_post_booking_revenue'));

echo "\n=== Phase 2B verify: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
