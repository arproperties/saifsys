<?php
declare(strict_types=1);

/**
 * Finance > PDC Receivables: a lease's cheques move from the tenant to PDC
 * Receivables on their dates, one PDC Receivable voucher (pdc) per cheque.
 * The worked example of the 7 Nov 2026 requirements email: rent 120,000 in four
 * cheques of 30,000, Ejari 300 with VAT inside it.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs the finance migrations up to fin_pdc_receivables.sql, a company with
 * an active lease and a second active company.
 *
 *     php tests/fin_pdc_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_pdc_helper.php';
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

// 1. No database needed: the PDC account for a building.
$pdcs = [['id' => 1, 'code' => '1290001', 'name' => 'PDC RECEIVABLES - AYLA RESIDENCES'],
         ['id' => 2, 'code' => '1290002', 'name' => 'PDC RECEIVABLES - PARK PLACE'],
         ['id' => 3, 'code' => '1290003', 'name' => 'PDC RECEIVABLES - VILLA']];
$pdcFor = static fn(?string $b) => fin_pdc_account_for($pdcs, $b)['code'] ?? null;
check('PDC account for AYLA RESIDENCE (plural aside)', '1290001', $pdcFor('AYLA RESIDENCE'));
check('PDC account for PARK PLACE', '1290002', $pdcFor('PARK PLACE'));
check('a villa goes to the villa account', '1290003', $pdcFor('Villa 267 Dubai South'));
check('so does a villa named another way', '1290003', $pdcFor('SHARJAH VILLA'));
check('a building with no PDC account gets nothing', null, $pdcFor('Gents Camp (G+5 Building)'));

if (!fin_coa_table_ready($conn) || !fin_voucher_tables_ready($conn) || !fin_pdc_ready($conn)) {
    echo "Run the finance migrations up to fin_pdc_receivables.sql first.\n";
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
    fin_test_clear_extras($conn, $a, $b);
    $conn->prepare("DELETE FROM fin_ledger_entries WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_voucher_history WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_vouchers WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("UPDATE fin_coa_accounts SET parent_id = NULL WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_coa_accounts WHERE company_id IN (?, ?)")->execute([$a, $b]);
    fin_test_load_chart($conn, $a);
    fin_test_load_chart($conn, $b);
    foreach ([['1121005', 'SUNDRY DEBTORS'], ['2112009', 'Deferred Revenue'], ['4130004', 'EJARI FEE RECEIVED'],
              ['2250001', 'VAT OUTPUT - COMMERCIAL UNITS'], ['1290000', 'PDC RECEIVABLES']] as [$code, $name]) {
        fin_coa_add_account($conn, $a, $code, $name);
    }

    $stmt = $conn->prepare("SELECT id FROM re_leases WHERE company_id = ? AND status = 'active' ORDER BY id LIMIT 1");
    $stmt->execute([$a]);
    $leaseId = (int)$stmt->fetchColumn();
    $conn->prepare("UPDATE re_leases SET contract_amount = 120000, admin_fees = 0, chiller_fees = 0, ejari_fees = 300, security_deposit = 0, commission_fees = 0, amc_amount = 0, is_renewal_lease = 1 WHERE id = ?")->execute([$leaseId]);
    $lease = fin_lease_posting_get($conn, $a, $leaseId);
    $pdcAcc = fin_coa_add_account($conn, $a, '1290001', 'PDC RECEIVABLES - ' . ($lease['building_name'] ?: 'TEST'));
    if (!$lease['building_name']) {
        $conn->prepare("UPDATE re_buildings b JOIN re_units u ON u.building_id = b.id JOIN re_leases l ON l.unit_id = u.id SET b.name = 'TEST' WHERE l.id = ?")->execute([$leaseId]);
    }

    // The example's cheques, plus what must never be posted.
    $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ?")->execute([$leaseId]);
    $cheque = $conn->prepare("INSERT INTO re_lease_cheques (company_id, lease_id, cheque_number, cheque_date, cheque_amount, payment_method, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ([['T-1', '2026-01-01', 30000, 'cheque', 'cleared'], ['T-2', '2026-04-01', 30000, 'cheque', 'deposited'],
              ['T-3', '2026-07-01', 30000, 'cheque', 'pending'], ['T-4', '2026-10-01', 30000, 'cheque', 'pending'],
              ['T-5', '2027-01-01', 30000, 'cheque', 'pending'],
              ['BT', '2026-02-01', 500, 'bank_transfer', 'cleared'], ['X-1', '2026-03-01', 700, 'cheque', 'cancelled'],
              ['X-2', '2026-03-01', 800, 'cheque', 'replaced'], ['X-3', '2026-03-01', 900, 'cheque', 'bounced'],
              ['OLD', '2025-12-15', 100, 'cheque', 'cleared']] as $row) {
        $cheque->execute(array_merge([$a, $leaseId], $row));
    }
    $chequeId = static function (string $no) use ($conn, $leaseId): int {
        $s = $conn->prepare("SELECT id FROM re_lease_cheques WHERE lease_id = ? AND cheque_number = ?");
        $s->execute([$leaseId, $no]);
        return (int)$s->fetchColumn();
    };

    // 2. Nothing moves before the lease has its Lease Voucher.
    check('no cheque is due before the lease is posted', [], fin_pdc_due($conn, $a, '2026-11-11', $leaseId));

    // 3. Posting the lease: the Ejari VAT, and the cheques already due move at once.
    $lv = fin_lease_post($conn, $a, $leaseId, ['date' => '2026-01-01', 'amounts' => array_map('strval', fin_lease_posting_amounts($lease))], 7);
    $lvLines = fin_voucher_get($conn, $a, $lv)['lines'];
    check('the Lease Voucher: tenant, deferred revenue, Ejari 285.71, VAT 14.29',
        [['1121005', '120300.00', '0.00'], ['2112009', '0.00', '120000.00'], ['4130004', '0.00', '285.71'], ['2250001', '0.00', '14.29']],
        array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit']], $lvLines));
    $today = fin_pdc_today();
    $stmt = $conn->prepare("SELECT c.cheque_number FROM fin_pdc_entries p JOIN re_lease_cheques c ON c.id = p.cheque_id WHERE p.lease_id = ? ORDER BY c.cheque_date, c.id");
    $stmt->execute([$leaseId]);
    check('the cheques due by ' . $today . ' are posted with the lease; not transfers, cancelled, replaced, bounced or future ones',
        ['OLD', 'T-1', 'T-2', 'T-3', 'T-4'], $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $conn->prepare("SELECT voucher_id FROM fin_pdc_entries WHERE cheque_id = ?");
    $stmt->execute([$chequeId('T-2')]);
    $v = fin_voucher_get($conn, $a, (int)$stmt->fetchColumn());
    check('a PDC Receivable voucher on the cheque date', ['pdc', '2026-04-01', '30000.00', 'T-2'], [$v['type'], $v['voucher_date'], $v['amount'], $v['reference']]);
    check('numbered PDCR', true, str_starts_with($v['voucher_no'], 'PDCR-2026-'));
    check('Dr PDC Receivables, Cr the tenant', [['1290001', '30000.00', '0.00'], ['1121005', '0.00', '30000.00']],
        array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit']], $v['lines']));
    $tenantKey = fin_partner_key($lvLines[0]['partner_type'], $lvLines[0]['partner_id']);
    check('the tenant is tagged on the Lease Voucher', true, (string)$tenantKey !== '');
    check('both lines are the tenant\'s, as on the Lease Voucher', [$tenantKey, $tenantKey],
        [fin_partner_key($v['lines'][0]['partner_type'], $v['lines'][0]['partner_id']), fin_partner_key($v['lines'][1]['partner_type'], $v['lines'][1]['partner_id'])]);
    $stmt->execute([$chequeId('OLD')]);
    check('a cheque dated before the Lease Voucher is posted on its date', '2026-01-01', fin_voucher_get($conn, $a, (int)$stmt->fetchColumn())['voucher_date']);
    check('listed under PDC Receivables', 5, count(fin_voucher_list($conn, $a, 'pdc')));

    // 4. The tenant: 120,300 charged, 120,100 moved to PDC (four cheques and the old one), 200 left.
    $bal = static function (string $code) use ($conn, $a): string {
        $s = $conn->prepare("SELECT FORMAT(COALESCE(SUM(e.debit - e.credit), 0), 2) FROM fin_ledger_entries e JOIN fin_coa_accounts c ON c.id = e.account_id WHERE e.company_id = ? AND c.code = ? AND e.is_void = 0");
        $s->execute([$a, $code]);
        return (string)$s->fetchColumn();
    };
    check('tenant balance after the four cheques due (and the old one)', '200.00', $bal('1121005'));
    check('PDC Receivables holds them', '120,100.00', $bal('1290001'));

    // 5. Once only; the future cheque waits for its date.
    check('running again posts nothing', [], fin_pdc_post_due($conn, $a, $today)['posted']);
    check('the 2027 cheque posts on its date', 1, count(fin_pdc_post_due($conn, $a, '2027-01-01')['posted']));
    check('the extra test cheque then takes the tenant past zero', '-29,800.00', $bal('1121005'));

    // 6. A deleted PDC voucher is not raised again.
    $stmt->execute([$chequeId('T-3')]);
    fin_voucher_delete($conn, $a, (int)$stmt->fetchColumn(), 7);
    check('a deleted PDC voucher stays deleted', [], fin_pdc_post_due($conn, $a, '2027-01-01')['posted']);

    // 7. A PDC voucher can be edited like a journal, never typed in new.
    $form = fin_voucher_form_data($v);
    $form['narration'] = 'Edited';
    check('a PDC voucher can be edited', true, fin_voucher_update($conn, $a, (int)$v['id'], $form, 7));
    check('and stays a PDC voucher', 'pdc', fin_voucher_get($conn, $a, (int)$v['id'])['type']);

    // 8. A building with no PDC account waits, and says so.
    $conn->prepare("UPDATE fin_coa_accounts SET is_active = 0 WHERE id = ?")->execute([$pdcAcc]);
    $cheque->execute([$a, $leaseId, 'W-1', '2026-05-05', 1000, 'cheque', 'pending']);
    $r = fin_pdc_post_due($conn, $a, $today);
    check('no PDC account: nothing posted', [], $r['posted']);
    check('and the cheque is listed as waiting', true, str_contains($r['waiting'][0] ?? '', 'W-1') && str_contains($r['waiting'][0], 'no PDC Receivables account'));

    // 9. Another company sees none of it.
    check('another company has no PDC vouchers', 0, count(fin_voucher_list($conn, $b, 'pdc')));
    check('nor cheques due', [], fin_pdc_due($conn, $b, '2027-12-31'));
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
