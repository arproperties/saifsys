<?php
declare(strict_types=1);

/**
 * Finance > Supplier payments: Against Bills, On Account and Advance, linking
 * them to invoices later, and what deleting either side does.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Needs migrations/fin_supplier_invoices.sql, fin_supplier_payments.sql and
 * two active companies.
 *
 *     php tests/fin_supplier_payment_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';
require_once __DIR__ . '/../includes/fin_supplier_payment_helper.php';
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

if (!fin_coa_table_ready($conn) || !fin_sp_ready($conn)) {
    echo "Run migrations/fin_supplier_invoices.sql and fin_supplier_payments.sql first.\n";
    exit(1);
}
$companies = $conn->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
[$a, $b] = array_map('intval', $companies);

$conn->beginTransaction();
try {
    fin_test_clear_extras($conn, $a, $b);
    $conn->prepare("DELETE FROM fin_ledger_entries WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_voucher_history WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_vouchers WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("UPDATE fin_coa_accounts SET parent_id = NULL WHERE company_id IN (?, ?)")->execute([$a, $b]);
    $conn->prepare("DELETE FROM fin_coa_accounts WHERE company_id IN (?, ?)")->execute([$a, $b]);
    fin_test_load_chart($conn, $a);
    $idOf = static function (string $code) use ($conn, $a): int {
        $s = $conn->prepare("SELECT id FROM fin_coa_accounts WHERE company_id = ? AND code = ?");
        $s->execute([$a, $code]);
        return (int)$s->fetchColumn();
    };
    $bank = $idOf('1112001');
    $payable = $idOf('2110001');
    $vatIn = $idOf('1120004');
    $advance = $idOf('1120002');
    $cleaning = $idOf('5400004');
    $vendor = static function (string $name) use ($conn, $a): int {
        $conn->prepare("INSERT INTO re_vendors (company_id, vendor_name, status) VALUES (?, ?, 'active')")->execute([$a, $name]);
        return (int)$conn->lastInsertId();
    };
    $acme = $vendor('ZZ Test Acme');
    $bolt = $vendor('ZZ Test Bolt');
    fin_settings_save($conn, $a, ['si_payable' => $payable, 'si_vat_input' => $vatIn], 1);

    // Invoices are booked with a file; the tests store none, so they go in straight.
    $invoice = static function (int $vendorId, string $no, string $date, string $net) use ($conn, $a, $cleaning): int {
        $tmp = tempnam(sys_get_temp_dir(), 'fin');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF\n");
        $id = fin_si_create($conn, $a, ['vendor_id' => $vendorId, 'invoice_no' => $no, 'date' => $date, 'narration' => "Invoice $no", 'vat_rate' => '0',
            'lines' => [['account_id' => $cleaning, 'amount' => $net]]], [[$tmp, 'inv.pdf', 'pdf', 10]], 1, static fn($f, $t) => true);
        unlink($tmp);
        return $id;
    };
    $balance = static function (string $code, int $vendorId) use ($conn, $a): float {
        $s = $conn->prepare("SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM fin_ledger_entries e JOIN fin_coa_accounts c ON c.id = e.account_id
                              WHERE e.company_id = ? AND c.code = ? AND e.partner_type = 're_vendor' AND e.partner_id = ? AND e.is_void = 0");
        $s->execute([$a, $code, $vendorId]);
        return round((float)$s->fetchColumn(), 2);
    };
    $outstanding = static fn(int $inv): float => array_column(fin_sp_invoices($conn, $a, null, false), 'outstanding', 'id')[$inv];

    $i1 = $invoice($acme, 'A-1', '2026-05-10', '1000');
    $i2 = $invoice($acme, 'A-2', '2026-05-20', '600');
    $i3 = $invoice($acme, 'A-3', '2026-08-31', '5000');
    $ib = $invoice($bolt, 'B-1', '2026-05-10', '300');
    check('supplier owes start', -6600.0, $balance('2110001', $acme));

    // 1. Settings.
    $pay = ['date' => '2026-06-01', 'cash_account_id' => $bank, 'vendor_id' => $acme, 'mode' => 'advance', 'amount' => '5000', 'reference' => 'TT 1', 'narration' => ''];
    check('advance needs the advance account', 'Choose the supplier advance account in Finance Settings first.', refusal(static fn() => fin_sp_create($conn, $a, $pay)));
    fin_settings_save($conn, $a, ['sp_advance' => $advance], 1);
    check('mode required', true, str_contains(refusal(static fn() => fin_sp_create($conn, $a, array_merge($pay, ['mode' => 'x']))), 'Against Bills, On Account or Advance'));

    // 2. Against Bills: one payment settles A-1 fully and A-2 in part.
    $bills = array_merge($pay, ['mode' => 'bills', 'date' => '2026-06-05', 'allocations' => [$i1 => '1,000', $i2 => '200', $ib => '']]);
    check("another supplier's bill refused", 'An invoice chosen is not an open invoice of this supplier.', refusal(static fn() => fin_sp_create($conn, $a, array_merge($bills, ['allocations' => [$ib => '100']]))));
    check('more than outstanding refused', true, str_contains(refusal(static fn() => fin_sp_create($conn, $a, array_merge($bills, ['allocations' => [$i2 => '601']]))), 'more than its outstanding'));
    check('nothing ticked refused', 'Enter the amount paid against at least one invoice.', refusal(static fn() => fin_sp_create($conn, $a, array_merge($bills, ['allocations' => [$i1 => '0']]))));
    $p1 = fin_sp_create($conn, $a, $bills, 5);
    $v = fin_voucher_get($conn, $a, $p1);
    check('it is a payment voucher', ['pv', 'PV-2026-0001', '1200.00'], [$v['type'], $v['voucher_no'], $v['amount']]);
    check('Dr supplier payable, Cr bank', [['2110001', '1200.00', '0.00', 're_vendor:' . $acme], ['1112001', '0.00', '1200.00', '']],
        array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit'], fin_partner_key($l['partner_type'], $l['partner_id'])], $v['lines']));
    check('A-1 paid', 0.0, $outstanding($i1));
    check('A-2 partly paid', 400.0, $outstanding($i2));
    check('open invoices of Acme', ['SI-2026-0002', 'SI-2026-0003'], array_column(fin_sp_invoices($conn, $a, $acme), 'voucher_no'));
    check('supplier owes less', -5400.0, $balance('2110001', $acme));
    check('status badges', ['Paid', 'Partly paid', 'Unpaid'], array_map(static fn($r) => strip_tags(fin_sp_status_badge((float)$r['total_amount'], $r['paid'])), array_slice(fin_sp_invoices($conn, $a, $acme, false), 0, 3)));
    check('bills payment has nothing to allocate later', [], fin_sp_unallocated($conn, $a, $acme));
    check('cannot edit a supplier payment', 'A supplier payment cannot be edited. Delete it and enter it again.', refusal(static fn() => fin_voucher_update($conn, $a, $p1, fin_voucher_form_data(fin_voucher_get($conn, $a, $p1)), 5)));

    // 3. Advance on 15 April, invoice A-3 dated 31 August.
    $p2 = fin_sp_create($conn, $a, array_merge($pay, ['date' => '2026-04-15', 'amount' => '5,000']), 5);
    $v = fin_voucher_get($conn, $a, $p2);
    check('advance: Dr advance account for the supplier', ['1120002', '5000.00', 're_vendor:' . $acme], [$v['lines'][0]['code'], $v['lines'][0]['debit'], fin_partner_key($v['lines'][0]['partner_type'], $v['lines'][0]['partner_id'])]);
    check('advance waits to be allocated', [[$p2, 5000.0]], array_map(static fn($r) => [(int)$r['id'], $r['remaining']], fin_sp_unallocated($conn, $a, $acme)));
    check('payable untouched by advance', -5400.0, $balance('2110001', $acme));
    check('advance balance', 5000.0, $balance('1120002', $acme));

    // 4. Link the advance to A-3.
    check('more than the advance refused', true, str_contains(refusal(static fn() => fin_sp_allocate($conn, $a, $p2, [$i3 => '5000', $i2 => '400'])), 'Only 5,000.00'));
    check('bills payment cannot be allocated again', 'A payment against bills is allocated when it is made.', refusal(static fn() => fin_sp_allocate($conn, $a, $p1, [$i2 => '1'])));
    check('linked', 1, fin_sp_allocate($conn, $a, $p2, [$i3 => '5000'], 6));
    $al = fin_sp_allocations($conn, $a, $i3);
    check('allocation keeps both dates', ['2026-04-15', '2026-08-31', '5000.00'], [$al[0]['payment_date'], $al[0]['invoice_date'], $al[0]['amount']]);
    $t = fin_voucher_get($conn, $a, (int)$al[0]['transfer_voucher_id']);
    check('transfer dated on the invoice date', ['jv', '2026-08-31'], [$t['type'], $t['voucher_date']]);
    check('transfer: Dr payable, Cr advance', [['2110001', '5000.00', '0.00'], ['1120002', '0.00', '5000.00']], array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit']], $t['lines']));
    check('A-3 paid', 0.0, $outstanding($i3));
    check('advance used up', 0.0, $balance('1120002', $acme));
    check('supplier owes only A-2 now', -400.0, $balance('2110001', $acme));
    check('original payment date kept', '2026-04-15', fin_voucher_get($conn, $a, $p2)['voucher_date']);
    check('transfer cannot be deleted by hand', true, str_contains(refusal(static fn() => fin_voucher_delete($conn, $a, (int)$t['id'], 1)), 'Supplier Allocations'));
    check('transfer cannot be edited by hand', true, str_contains(refusal(static fn() => fin_voucher_update($conn, $a, (int)$t['id'], fin_voucher_form_data($t), 1)), 'Supplier Allocations'));

    // 5. Invoice with payments: guarded edit.
    $form3 = fin_si_form_data($conn, $a, fin_voucher_get($conn, $a, $i3));
    check('cannot cut the total below what is paid', true, str_contains(refusal(static fn() => fin_si_update($conn, $a, $i3, array_merge($form3, ['lines' => [['account_id' => $cleaning, 'amount' => '4000']]]), [], [], 1)), 'cannot go below'));
    check('cannot change supplier once paid', true, str_contains(refusal(static fn() => fin_si_update($conn, $a, $i3, array_merge($form3, ['vendor_id' => $bolt]), [], [], 1)), 'supplier cannot change'));

    // 6. Remove the allocation: the invoice is open again, the transfer gone.
    fin_sp_unallocate($conn, $a, (int)$al[0]['id'], 7);
    check('A-3 open again', 5000.0, $outstanding($i3));
    check('transfer deleted', 'void', fin_voucher_get($conn, $a, (int)$t['id'])['status']);
    check('advance back', 5000.0, $balance('1120002', $acme));
    check('payable back', -5400.0, $balance('2110001', $acme));
    $bal = fin_sp_allocations($conn, $a, $i1);
    check('bills allocation cannot be removed alone', true, str_contains(refusal(static fn() => fin_sp_unallocate($conn, $a, (int)$bal[0]['id'], 7)), 'Delete the payment voucher'));

    // 7. On Account, linked in two parts with no extra entry.
    $p3 = fin_sp_create($conn, $a, array_merge($pay, ['mode' => 'on_account', 'date' => '2026-07-01', 'amount' => '3000']), 5);
    check('on account: Dr payable', '2110001', fin_voucher_get($conn, $a, $p3)['lines'][0]['code']);
    $vouchersBefore = (int)$conn->query("SELECT COUNT(*) FROM fin_vouchers WHERE company_id = $a")->fetchColumn();
    fin_sp_allocate($conn, $a, $p3, [$i2 => '400', $i3 => '1000'], 6);
    check('no journal for on-account link', $vouchersBefore, (int)$conn->query("SELECT COUNT(*) FROM fin_vouchers WHERE company_id = $a")->fetchColumn());
    check('A-2 paid by two payments', 0.0, $outstanding($i2));
    check('1,600 of it left', [[$p3, 1600.0]], array_map(static fn($r) => [(int)$r['id'], $r['remaining']], array_values(array_filter(fin_sp_unallocated($conn, $a, $acme), static fn($r) => (int)$r['id'] === $p3))));

    // 8. Deleting: an invoice frees its payments; a payment reopens its invoices.
    fin_voucher_delete($conn, $a, $i3, 1);
    check('deleting A-3 frees the payment', 3000.0 - 400.0, array_column(fin_sp_unallocated($conn, $a, $acme), 'remaining', 'id')[$p3]);
    fin_voucher_delete($conn, $a, $p1, 1);
    check('deleting the bills payment reopens A-1', 1000.0, $outstanding($i1));
    check('A-2 keeps its on-account part', 200.0, $outstanding($i2));

    // 9. Another company sees none of it.
    check('other company: no invoices', [], fin_sp_invoices($conn, $b));
    check('other company cannot allocate', 'Payment not found in this company.', refusal(static fn() => fin_sp_allocate($conn, $b, $p3, [$i1 => '1'])));
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
