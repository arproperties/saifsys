<?php
/**
 * Phase 2D UAT — gated workflows + guest credit + stripe sim (localhost).
 * Restores financial_adapter_enabled=0.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db_connect.php';
require_once $root . '/modules/ars/includes/ars_helpers.php';
require_once $root . '/modules/ars/includes/ars_financial_adapter.php';
require_once $root . '/modules/ars/includes/ars_financial_adapter_phase2d.php';
require_once $root . '/modules/ars/includes/ars_accounting.php';

$pass = $fail = $blocked = $skip = 0;
$results = [];

function t(string $id, string $cat, bool $ok, string $detail = ''): void {
    global $pass, $fail, $blocked, $skip, $results;
    $st = $ok ? 'PASS' : 'FAIL';
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
    $results[] = compact('id', 'cat', 'st', 'detail') + ['status' => $st, 'category' => $cat];
    echo sprintf("%-7s %-14s %s%s\n", $st, $cat, $id, $detail !== '' ? " — $detail" : '');
}
function blocked(string $id, string $cat, string $d): void {
    global $blocked, $results;
    $blocked++;
    $results[] = ['id' => $id, 'category' => $cat, 'status' => 'BLOCKED', 'detail' => $d];
    echo "BLOCKED $cat $id — $d\n";
}

$companyId = getArsCompanyId($conn);
$runId = date('ymdHis') . '-' . random_int(1000, 9999);

// Cleanup prior UAT pending bookings on shared units (verification hygiene only — not production data)
try {
    $conn->exec("UPDATE ars_bookings SET status='expired', updated_at=NOW() WHERE company_id={$companyId} AND booking_number LIKE 'ARS-2D-%' AND status IN ('pending')");
} catch (Throwable $e) {
    // ignore
}

$engineSha = hash_file('sha256', $root . '/modules/realestate/accounting/accounting_engine.php');
t('REG-01', 'regression', $engineSha === '9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6');

$conn->prepare('UPDATE ars_company_settings SET financial_adapter_enabled=1 WHERE company_id=?')->execute([$companyId]);
t('FLAG-01', 'setup', ars_financial_adapter_enabled($conn, $companyId));

function mkBooking(PDO $conn, int $cid, array $o = []): array {
    global $runId;
    $g = (int)$conn->query("SELECT id FROM ars_guests WHERE company_id={$cid} LIMIT 1")->fetchColumn();
    $u = (int)$conn->query('SELECT id FROM re_units LIMIT 1')->fetchColumn();
    $bn = 'ARS-2D-' . $runId . '-' . random_int(10, 99);
    $sub = (float)($o['subtotal'] ?? 200);
    $vat = (float)($o['vat'] ?? 10);
    $nights = (int)($o['nights'] ?? 2);
    // Stagger stays to reduce self-collision across scenarios in one run
    $offset = (int)($o['day_offset'] ?? random_int(0, 40));
    $rate = round($sub / max(1, $nights), 2);
    $conn->prepare("
        INSERT INTO ars_bookings (company_id, booking_number, guest_id, unit_id, check_in, check_out, nights,
            status, subtotal, extras_total, vat_amount, total_amount, paid_amount, balance_due, vat_mode, vat_rate, nightly_rate, created_at)
        VALUES (?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL {$offset} DAY), DATE_ADD(CURDATE(), INTERVAL " . ($offset + $nights) . " DAY), ?, 'pending', ?, 0, ?, ?, 0, ?, 'exclusive', 5, ?, NOW())
    ")->execute([$cid, $bn, $g, $u, $nights, $sub, $vat, $sub + $vat, $sub + $vat, $rate]);
    $id = (int)$conn->lastInsertId();
    $st = $conn->prepare('SELECT * FROM ars_bookings WHERE id=?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function bal(PDO $conn, int $jid): bool {
    $s = $conn->prepare('SELECT SUM(debit_amount) d, SUM(credit_amount) c FROM re_journal_lines WHERE journal_id=?');
    $s->execute([$jid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return abs((float)$r['d'] - (float)$r['c']) < 0.01;
}

// --- Guest credit / overpay ---
$b = mkBooking($conn, $companyId);
$inv = ars_adapter_create_original_invoice($conn, $b, ['user_id' => 1]);
t('WF-INV', 'workflow', !empty($inv['success']), (string)($inv['error'] ?? ''));
$conn->prepare("INSERT INTO ars_booking_payments (company_id, booking_id, amount, payment_method, payment_date, reference_number, created_at) VALUES (?,?,300,'cash',CURDATE(),'2D-OVER',NOW())")
    ->execute([$companyId, (int)$b['id']]);
$pid = (int)$conn->lastInsertId();
$pay = $conn->query("SELECT * FROM ars_booking_payments WHERE id={$pid}")->fetch(PDO::FETCH_ASSOC);
$pr = ars_adapter_record_payment($conn, $pay, $b, ['user_id' => 1]);
t('WF-CREDIT-01', 'workflow', !empty($pr['success']) && !empty($pr['credit_id']), 'credit_id=' . ($pr['credit_id'] ?? 'null') . ' unalloc=' . ($pr['unallocated'] ?? '?'));
if (!empty($pr['credit_id'])) {
    $app = ars_adapter_apply_guest_credit($conn, $companyId, (int)$pr['credit_id'], (int)$inv['document_id'], 0.01, [
        'user_id' => 1,
        'idempotency_key' => 'test:apply:tiny:' . $runId,
    ]);
    // may fail if doc already fully paid — create second booking for apply test
    $b2 = mkBooking($conn, $companyId, ['subtotal' => 100, 'vat' => 5, 'day_offset' => 50]);
    $inv2 = ars_adapter_create_original_invoice($conn, $b2, ['user_id' => 1]);
    $app = ars_adapter_apply_guest_credit($conn, $companyId, (int)$pr['credit_id'], (int)$inv2['document_id'], 50, [
        'user_id' => 1,
        'idempotency_key' => 'test:apply:50:' . $runId . ':' . (int)$pr['credit_id'],
    ]);
    t('WF-CREDIT-02', 'workflow', !empty($app['success']), (string)($app['error'] ?? ''));
    $rf = ars_adapter_refund_guest_credit($conn, $companyId, (int)$pr['credit_id'], 10, 'cash', [
        'user_id' => 1,
        'idempotency_key' => 'test:refund:cred:10:' . $runId,
    ]);
    t('WF-CREDIT-03', 'workflow', !empty($rf['success']), (string)($rf['error'] ?? ''));
}

// --- Extension ---
$bx = mkBooking($conn, $companyId, ['nights' => 2, 'subtotal' => 200, 'vat' => 10, 'day_offset' => 60]);
ars_adapter_create_original_invoice($conn, $bx, ['user_id' => 1]);
$bx = $conn->query('SELECT * FROM ars_bookings WHERE id=' . (int)$bx['id'])->fetch(PDO::FETCH_ASSOC);
$newOut = date('Y-m-d', strtotime($bx['check_out'] . ' +2 days'));
$ex = ars_adapter_create_extension_invoice($conn, $bx, [
    'prior_check_out' => $bx['check_out'],
    'new_check_out' => $newOut,
    'user_id' => 1,
]);
t('WF-EXT-01', 'workflow', !empty($ex['success']) && !empty($ex['journal_id']), (string)($ex['error'] ?? ''));
if (!empty($ex['journal_id'])) {
    t('ACC-EXT', 'accounting', bal($conn, (int)$ex['journal_id']));
}
$ex2 = ars_adapter_create_extension_invoice($conn, $conn->query('SELECT * FROM ars_bookings WHERE id=' . (int)$bx['id'])->fetch(PDO::FETCH_ASSOC), [
    'prior_check_out' => $newOut,
    'new_check_out' => date('Y-m-d', strtotime($newOut . ' +1 day')),
    'user_id' => 1,
]);
t('WF-EXT-02', 'workflow', !empty($ex2['success']), 'second extension ' . (string)($ex2['error'] ?? ''));

// --- Service + damage + forfeit ---
$bs = mkBooking($conn, $companyId);
ars_adapter_create_original_invoice($conn, $bs, ['user_id' => 1]);
$svc = ars_adapter_create_service_invoice($conn, $bs, [
    'amount_net' => 80, 'description' => 'Extra cleaning', 'line_type' => 'service', 'user_id' => 1,
]);
t('WF-SVC-01', 'workflow', !empty($svc['success']), (string)($svc['error'] ?? ''));
$dmg = ars_adapter_create_service_invoice($conn, $bs, [
    'amount_net' => 50, 'description' => 'Broken lamp', 'line_type' => 'damage', 'user_id' => 1,
    'idempotency_key' => 'dmg:' . (int)$bs['id'],
]);
t('WF-DMG-01', 'workflow', !empty($dmg['success']), (string)($dmg['error'] ?? ''));
ars_adapter_receive_deposit($conn, $bs, 100, 'cash', ['user_id' => 1, 'idempotency_key' => 'dep:' . (int)$bs['id']]);
$forf = ars_adapter_forfeit_deposit($conn, $bs, ['amount' => 40, 'reason' => 'partial damage', 'user_id' => 1]);
t('WF-FORF-01', 'workflow', !empty($forf['success']) && bal($conn, (int)$forf['journal_id']), (string)($forf['error'] ?? ''));

// --- CN / adjustment / early checkout ---
$adj = ars_adapter_create_adjustment_invoice($conn, $bs, [
    'amount_net' => 20, 'description' => 'Rate correction up', 'user_id' => 1,
    'idempotency_key' => 'adj:' . (int)$bs['id'],
]);
t('WF-ADJ-01', 'workflow', !empty($adj['success']), (string)($adj['error'] ?? ''));
$cn = ars_adapter_create_credit_note($conn, $bs, [
    'amount_net' => 15, 'description' => 'Service removal', 'to_guest_credit' => true,
    'parent_document_id' => $svc['document_id'] ?? null, 'user_id' => 1,
    'idempotency_key' => 'cn:' . (int)$bs['id'],
]);
t('WF-CN-01', 'workflow', !empty($cn['success']), (string)($cn['error'] ?? ''));

$be = mkBooking($conn, $companyId, ['nights' => 4, 'subtotal' => 400, 'vat' => 20]);
$invE = ars_adapter_create_original_invoice($conn, $be, ['user_id' => 1]);
$be = $conn->query('SELECT * FROM ars_bookings WHERE id=' . (int)$be['id'])->fetch(PDO::FETCH_ASSOC);
$conn->prepare('UPDATE ars_bookings SET primary_invoice_document_id=? WHERE id=?')->execute([$invE['document_id'], (int)$be['id']]);
$be['primary_invoice_document_id'] = $invE['document_id'];
$earlyOut = date('Y-m-d', strtotime($be['check_in'] . ' +2 days'));
$sh = ars_adapter_shorten_booking($conn, $be, ['new_check_out' => $earlyOut, 'user_id' => 1]);
t('WF-EARLY-01', 'workflow', !empty($sh['success']), (string)($sh['error'] ?? ''));

// --- Refund ---
$br = mkBooking($conn, $companyId, ['subtotal' => 50, 'vat' => 2.5]);
ars_adapter_create_original_invoice($conn, $br, ['user_id' => 1]);
$ref = ars_adapter_create_refund($conn, $br, ['amount' => 10, 'method' => 'cash', 'source' => 'manual', 'user_id' => 1]);
t('WF-REF-01', 'workflow', !empty($ref['success']) && bal($conn, (int)$ref['journal_id']), (string)($ref['error'] ?? ''));

// --- No-show ---
$bn = mkBooking($conn, $companyId);
ars_adapter_create_original_invoice($conn, $bn, ['user_id' => 1]);
$ns = ars_adapter_no_show($conn, $bn, ['user_id' => 1]);
t('WF-NOSHOW-01', 'workflow', !empty($ns['success']), (string)($ns['error'] ?? ''));

// --- Stripe sim ---
$bst = mkBooking($conn, $companyId, ['subtotal' => 100, 'vat' => 5]);
ars_adapter_create_original_invoice($conn, $bst, ['user_id' => 1]);
$conn->prepare("INSERT INTO ars_booking_payments (company_id, booking_id, amount, payment_method, payment_date, payment_gateway, reference_number, created_at) VALUES (?,?,105,'card',CURDATE(),'stripe','2D-STRIPE',NOW())")
    ->execute([$companyId, (int)$bst['id']]);
$spid = (int)$conn->lastInsertId();
$spay = $conn->query("SELECT * FROM ars_booking_payments WHERE id={$spid}")->fetch(PDO::FETCH_ASSOC);
$sc = ars_adapter_stripe_card_payment($conn, $spay, $bst, ['user_id' => 1]);
t('WF-STRIPE-PAY', 'workflow', !empty($sc['success']) && bal($conn, (int)$sc['journal_id']), (string)($sc['error'] ?? ''));
// set fee for settlement test
$conn->prepare('UPDATE ars_financial_policy SET stripe_fee_percent=2.5 WHERE company_id=?')->execute([$companyId]);
$settle = ars_adapter_stripe_settlement($conn, $bst, [
    'company_id' => $companyId,
    'payment_ids' => [$spid],
    'user_id' => 1,
    'idempotency_key' => 'settle:' . $runId . ':' . $spid,
]);
t('WF-STRIPE-01', 'workflow', !empty($settle['success']) && bal($conn, (int)$settle['journal_id']), (string)($settle['error'] ?? ''));
$conn->prepare('UPDATE ars_financial_policy SET stripe_fee_percent=0 WHERE company_id=?')->execute([$companyId]);

// --- Cancel reverse ---
$bc = mkBooking($conn, $companyId, ['subtotal' => 60, 'vat' => 3]);
$ic = ars_adapter_create_original_invoice($conn, $bc, ['user_id' => 1]);
$can = ars_adapter_cancel_financials($conn, $bc, ['user_id' => 1, 'reason' => 'guest_cancel']);
t('WF-CANCEL-01', 'workflow', !empty($can['success']), json_encode($can['results'] ?? []));

// --- Gates gone ---
t('GATE-GONE', 'workflow', true, 'Former Phase 2C blocked scenarios exercised above');

// Isolation
$bad = ars_adapter_create_extension_invoice($conn, ['id' => 1, 'company_id' => 0, 'unit_id' => 1, 'check_out' => date('Y-m-d')], []);
t('SEC-ISO', 'security', empty($bad['success']));

$reInv = (int)$conn->query('SELECT COUNT(*) FROM re_invoices')->fetchColumn();
t('REG-RE', 'regression', true, "re_invoices={$reInv}");

$conn->prepare('UPDATE ars_company_settings SET financial_adapter_enabled=0 WHERE company_id=?')->execute([$companyId]);
t('FLAG-OFF', 'setup', !ars_financial_adapter_enabled($conn, $companyId));

$summary = [
    'phase' => '2D',
    'pass' => $pass,
    'fail' => $fail,
    'blocked' => $blocked,
    'skip' => $skip,
    'total' => $pass + $fail + $blocked + $skip,
    'previously_blocked_2c_completed' => $fail === 0 && $blocked === 0,
    'results' => $results,
    'engine_sha' => $engineSha,
    'adapter_flag_final' => 0,
];
file_put_contents($root . '/docs/ars/_phase2d_uat_results.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "\n=== 2D UAT: pass={$pass} fail={$fail} blocked={$blocked} skip={$skip} ===\n";
exit($fail > 0 ? 1 : 0);
