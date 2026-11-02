<?php
declare(strict_types=1);

/**
 * Finance > Supplier Invoices: what one posts, the VAT, the duplicate check,
 * the files, editing and deleting.
 *
 * Runs against the local database inside a transaction that is rolled back;
 * the files it stores are removed at the end.
 * Needs migrations/fin_supplier_invoices.sql and two active companies.
 *
 *     php tests/fin_supplier_invoice_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/fin_tb_helper.php';
require_once __DIR__ . '/../includes/fin_supplier_invoice_helper.php';
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
/** The message a refused call gives, or '' when it was not refused. */
function refusal(callable $fn): string
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return '';
}

if (!fin_coa_table_ready($conn) || !fin_si_ready($conn)) {
    echo "Run migrations/fin_supplier_invoices.sql first.\n";
    exit(1);
}
$companies = $conn->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($companies) < 2) {
    echo "Needs two active companies.\n";
    exit(1);
}
[$a, $b] = array_map('intval', $companies);

// Sample files: a real PDF and PNG, and a text file pretending to be a PDF.
$tmpDir = sys_get_temp_dir() . '/fin_si_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir);
file_put_contents("$tmpDir/inv.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
file_put_contents("$tmpDir/inv.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
file_put_contents("$tmpDir/fake.pdf", "<script>alert(1)</script>");
$upload = static function (array $names) use ($tmpDir): array {
    $f = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($names as $as => $real) {
        $f['name'][] = $as;
        $f['tmp_name'][] = "$tmpDir/$real";
        $f['error'][] = UPLOAD_ERR_OK;
        $f['size'][] = filesize("$tmpDir/$real");
    }
    return $f;
};
$copy = static fn(string $from, string $to): bool => copy($from, $to);
$stored = [];

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
    $idOf = static function (int $companyId, string $code) use ($conn): int {
        $s = $conn->prepare("SELECT id FROM fin_coa_accounts WHERE company_id = ? AND code = ?");
        $s->execute([$companyId, $code]);
        return (int)$s->fetchColumn();
    };
    $payable = $idOf($a, '2110001');
    $vatIn = $idOf($a, '1120004');
    $cleaning = $idOf($a, '5400004');
    $maint = $idOf($a, '5400005');
    $vendor = static function (int $companyId, string $name) use ($conn): int {
        $conn->prepare("INSERT INTO re_vendors (company_id, vendor_name, status) VALUES (?, ?, 'active')")->execute([$companyId, $name]);
        return (int)$conn->lastInsertId();
    };
    $acme = $vendor($a, 'ZZ Test Acme Cleaning');
    $bolt = $vendor($a, 'ZZ Test Bolt Maintenance');
    $other = $vendor($b, 'ZZ Test Other Company Supplier');

    $data = [
        'vendor_id' => $acme, 'invoice_no' => 'INV-100', 'date' => '2026-04-15', 'narration' => 'April cleaning',
        'vat_rate' => '5', 'vat_amount' => '',
        'lines' => [
            ['account_id' => $cleaning, 'description' => '', 'amount' => '1,000.00'],
            ['account_id' => $maint, 'description' => 'Pump fix', 'amount' => '250.50'],
            ['account_id' => 0, 'description' => '', 'amount' => ''],
        ],
    ];

    // 1. Settings must be chosen first.
    check('no payable account yet', 'Choose the supplier payable account in Finance Settings first.', refusal(static fn() => fin_si_prepare($conn, $a, $data)));
    check('a setting must be an account of the company', true, refusal(static fn() => fin_settings_save($conn, $a, ['si_payable' => $idOf($b, '2110001')], 1)) !== '');
    fin_settings_save($conn, $a, ['si_payable' => $payable, 'si_vat_input' => $vatIn], 1);
    check('settings saved', ['si_vat_input' => $vatIn, 'si_payable' => $payable, 'sp_advance' => null], fin_settings_get($conn, $a));

    // 2. What is required.
    $without = static fn(string $k, $v = '') => array_merge($data, [$k => $v]);
    check('supplier required', 'Choose the supplier.', refusal(static fn() => fin_si_prepare($conn, $a, $without('vendor_id', 0))));
    check("another company's supplier refused", 'Choose the supplier.', refusal(static fn() => fin_si_prepare($conn, $a, $without('vendor_id', $other))));
    check('invoice number required', 'Enter the supplier invoice number.', refusal(static fn() => fin_si_prepare($conn, $a, $without('invoice_no', '  '))));
    check('date required', 'Enter a valid invoice date.', refusal(static fn() => fin_si_prepare($conn, $a, $without('date', '2026-02-30'))));
    check('description required', 'Enter a description.', refusal(static fn() => fin_si_prepare($conn, $a, $without('narration'))));
    check('VAT rate required', 'Choose the VAT: 5% or 0%.', refusal(static fn() => fin_si_prepare($conn, $a, $without('vat_rate', '10'))));
    check('a line cannot use the payable account', true, str_contains(refusal(static fn() => fin_si_prepare($conn, $a, $without('lines', [['account_id' => $payable, 'amount' => '10']]))), 'posted by the invoice itself'));
    check('a file is required', 'Attach the supplier invoice (image or PDF).', refusal(static fn() => fin_si_create($conn, $a, $data, [], 1, $copy)));

    // 3. VAT.
    $p = fin_si_prepare($conn, $a, $data);
    check('net', 1250.5, $p['net']);
    check('5% VAT worked out', 62.53, $p['vat']);
    check('total', 1313.03, $p['total']);
    check('VAT typed to match the supplier is kept', 62.0, fin_si_prepare($conn, $a, $without('vat_amount', '62.00'))['vat']);
    check('VAT too far off refused', true, str_contains(refusal(static fn() => fin_si_prepare($conn, $a, $without('vat_amount', '70'))), 'too far'));
    $zero = fin_si_prepare($conn, $a, array_merge($data, ['vat_rate' => '0']));
    check('0% has no VAT', 0.0, $zero['vat']);
    check('0% posts no VAT line', 3, count($zero['postings']));
    check('0% with a VAT amount refused', 'An invoice at 0% VAT cannot have a VAT amount.', refusal(static fn() => fin_si_prepare($conn, $a, array_merge($data, ['vat_rate' => '0', 'vat_amount' => '5']))));

    // 4. Files.
    check('a text file named .pdf refused', true, str_contains(refusal(static fn() => fin_attach_check($upload(['scan.pdf' => 'fake.pdf']))), 'not a real PDF'));
    check('other types refused', true, str_contains(refusal(static fn() => fin_attach_check($upload(['scan.html' => 'inv.pdf']))), 'attach a PDF or an image'));
    $files = fin_attach_check($upload(['Acme INV-100.pdf' => 'inv.pdf', 'photo.png' => 'inv.png']));
    check('real PDF and PNG accepted', 2, count($files));

    // 5. Book it.
    $id = fin_si_create($conn, $a, $data, $files, 7, $copy);
    $v = fin_voucher_get($conn, $a, $id);
    check('number', 'SI-2026-0001', $v['voucher_no']);
    check('type', 'si', $v['type']);
    check('date is the invoice date', '2026-04-15', $v['voucher_date']);
    check('party is the supplier', 'ZZ Test Acme Cleaning', $v['party']);
    check('reference is the supplier invoice no.', 'INV-100', $v['reference']);
    check('amount is the total', '1313.03', $v['amount']);
    $lines = array_map(static fn($l) => [$l['code'], $l['debit'], $l['credit'], fin_partner_key($l['partner_type'], $l['partner_id'])], $v['lines']);
    check('posts expense Dr, VAT Dr, supplier Cr', [
        ['5400004', '1000.00', '0.00', ''],
        ['5400005', '250.50', '0.00', ''],
        ['1120004', '62.53', '0.00', ''],
        ['2110001', '0.00', '1313.03', 're_vendor:' . $acme],
    ], $lines);
    $att = fin_attach_list($conn, $a, $id);
    $stored = array_merge($stored, array_map(static fn($r) => $r['stored_name'], $conn->query("SELECT stored_name FROM fin_voucher_attachments WHERE voucher_id = $id")->fetchAll(PDO::FETCH_ASSOC)));
    check('two files kept', ['Acme INV-100.pdf', 'photo.png'], array_column($att, 'file_name'));
    check('files are on disk', true, array_reduce($stored, static fn($ok, $s) => $ok && is_file(FIN_ATTACH_DIR . '/' . $s), true));
    check('the folder refuses direct links', true, is_file(FIN_ATTACH_DIR . '/.htaccess'));
    $si = fin_si_get($conn, $a, $id);
    check('split kept', ['1250.50', '62.53', '1313.03'], [$si['net_amount'], $si['vat_amount'], $si['total_amount']]);
    $tb = array_column(fin_trial_balance($conn, $a, '2026-04-01', '2026-04-30')['rows'], null, 'code');
    check('in the trial balance', '1313.03', number_format((float)$tb['2110001']['period_credit'], 2, '.', ''));
    check('supplier balance', -1313.03, fin_partner_balances($conn, $a, '2026-04-30', 'supplier')['totals']['balance']);
    check('suggests the last account for the supplier', [$acme => $cleaning], fin_si_last_accounts($conn, $a));

    // 6. The same invoice number for the same supplier.
    $more = $upload(['b.pdf' => 'inv.pdf']);
    check('duplicate refused', 'This supplier\'s invoice INV-100 is already booked as SI-2026-0001.', refusal(static fn() => fin_si_create($conn, $a, $data, fin_attach_check($more), 7, $copy)));
    check('duplicate refused whatever the case', true, refusal(static fn() => fin_si_create($conn, $a, $without('invoice_no', 'inv-100'), fin_attach_check($more), 7, $copy)) !== '');
    $id2 = fin_si_create($conn, $a, $without('vendor_id', $bolt), fin_attach_check($more), 7, $copy);
    check('same number, other supplier is fine', 'SI-2026-0002', fin_voucher_get($conn, $a, $id2)['voucher_no']);

    // 7. The form gets it back.
    $form = fin_si_form_data($conn, $a, fin_voucher_get($conn, $a, $id));
    check('form: supplier, number, rate', [$acme, 'INV-100', '5', '62.53'], [$form['vendor_id'], $form['invoice_no'], $form['vat_rate'], $form['vat_amount']]);
    check('form: only the expense lines', [[$cleaning, '', '1000.00'], [$maint, 'Pump fix', '250.50']], array_map(static fn($l) => [$l['account_id'], $l['description'], $l['amount']], $form['lines']));

    // 8. Edit: 0% now, one file off, no new file.
    $pdfId = (int)$att[0]['id'];
    $edited = array_merge($form, ['vat_rate' => '0', 'vat_amount' => '']);
    check('edit saved', true, fin_si_update($conn, $a, $id, $edited, [], [$pdfId], 9, $copy));
    $v = fin_voucher_get($conn, $a, $id);
    check('edit: total without VAT', '1250.50', $v['amount']);
    check('edit: no VAT line now', ['5400004', '5400005', '2110001'], array_column($v['lines'], 'code'));
    check('edit: one file left', ['photo.png'], array_column(fin_attach_list($conn, $a, $id), 'file_name'));
    check('the last file cannot go', 'Keep or attach at least one copy of the invoice.', refusal(static fn() => fin_si_update($conn, $a, $id, $edited, [], [(int)$att[1]['id']], 9, $copy)));
    check('cannot take another invoice\'s number', true, str_contains(refusal(static fn() => fin_si_update($conn, $a, $id2, array_merge(fin_si_form_data($conn, $a, fin_voucher_get($conn, $a, $id2)), ['vendor_id' => $acme]), [], [], 9, $copy)), 'already booked'));
    check('saving unchanged changes nothing', false, fin_si_update($conn, $a, $id, $edited, [], [], 9, $copy));
    check('the generic edit refuses it', 'A supplier invoice is changed on its own form.', refusal(static fn() => fin_voucher_update($conn, $a, $id, ['date' => '2026-04-15'], 9)));
    check('another company cannot edit it', true, refusal(static fn() => fin_si_update($conn, $b, $id, $edited, [], [], 9, $copy)) !== '');
    $h = array_reverse(fin_voucher_history($conn, $a, ['voucher_id' => $id]));
    $changes = array_column($h[1]['changes'], 0);
    check('history: added, edited', ['created', 'edited'], array_column($h, 'action'));
    check('history shows VAT and file changes', true, in_array('VAT', $changes, true) && in_array('Attachments', $changes, true));

    // 9. Delete frees the number.
    fin_voucher_delete($conn, $a, $id, 11);
    check('deleted leaves the balance', 0.0, (float)$conn->query("SELECT COALESCE(SUM(credit - debit), 0) FROM fin_ledger_entries WHERE voucher_id = $id AND is_void = 0")->fetchColumn());
    $id3 = fin_si_create($conn, $a, $data, fin_attach_check($more), 7, $copy);
    check('number can be booked again after delete', 'SI-2026-0003', fin_voucher_get($conn, $a, $id3)['voucher_no']);
    $stored = array_merge($stored, $conn->query("SELECT stored_name FROM fin_voucher_attachments WHERE voucher_id IN ($id2, $id3)")->fetchAll(PDO::FETCH_COLUMN));

    // 10. A failed save leaves no file behind.
    $before = count(glob(FIN_ATTACH_DIR . '/v*'));
    refusal(static fn() => fin_si_create($conn, $a, $data, fin_attach_check($more), 7, $copy));
    check('refused save writes no file', $before, count(glob(FIN_ATTACH_DIR . '/v*')));

    // 11. Other voucher kinds are untouched.
    check('jv form cannot post as si', 'Unknown voucher type.', refusal(static fn() => fin_voucher_prepare($conn, $a, 'si', [])));
} finally {
    $conn->rollBack();
    foreach ($stored as $s) {
        @unlink(FIN_ATTACH_DIR . '/' . $s);
    }
    array_map('unlink', glob("$tmpDir/*"));
    rmdir($tmpDir);
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
