<?php
/**
 * Finance > Supplier Invoices (voucher type si).
 *
 * A supplier's bill, booked against a supplier of Vendor Management (re_vendors).
 * Each line is an expense (or asset) account with its amount before VAT; the
 * invoice carries one VAT rate, 5% or 0%. It posts:
 *   Dr each line account        net amount
 *   Dr Input VAT account        VAT (when there is any)
 *   Cr Supplier payable account total, with the supplier as partner
 * The two accounts are chosen per company in Finance Settings (fin_settings).
 *
 * The supplier's own invoice number can be booked once per supplier: a deleted
 * invoice frees it. Every invoice needs at least one scanned copy (image or PDF);
 * files live in uploads/fin_attachments and are served by fin_attachment_file.php.
 */

require_once __DIR__ . '/fin_voucher_helper.php';

const FIN_SI_VAT_RATES = ['5' => 5.0, '0' => 0.0];
// What the VAT on the invoice may differ from rate x amount, for the supplier's rounding.
const FIN_SI_VAT_TOLERANCE = 1.00;

/** Settings an accountant chooses once per company: key => label. */
const FIN_SETTINGS = [
    'si_vat_input' => 'Input VAT account (supplier invoices)',
    'si_payable'   => 'Supplier payable account (supplier invoices)',
    'sp_advance'   => 'Supplier advance account (advance payments)',
];

const FIN_ATTACH_DIR = __DIR__ . '/../uploads/fin_attachments';
const FIN_ATTACH_MAX_BYTES = 10 * 1024 * 1024;
// Extension => what the file must really be and the type it is served with.
const FIN_ATTACH_TYPES = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

function fin_si_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('fin_supplier_invoices', 'fin_voucher_attachments', 'fin_settings')");
    $chk->execute();
    return (int)$chk->fetchColumn() === 3 && fin_voucher_tables_ready($conn) && fin_partner_ready($conn);
}

/** The company's saved settings: key => account id (null when not chosen). */
function fin_settings_get(PDO $conn, int $companyId): array
{
    $out = array_fill_keys(array_keys(FIN_SETTINGS), null);
    $stmt = $conn->prepare("SELECT setting_key, account_id FROM fin_settings WHERE company_id = ?");
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        if (array_key_exists($k, $out)) {
            $out[$k] = $v !== null ? (int)$v : null;
        }
    }
    return $out;
}

/** Save the settings: key => account id (0 clears it). Each account must be one a voucher can post to. */
function fin_settings_save(PDO $conn, int $companyId, array $values, ?int $userId): void
{
    $accounts = array_column(fin_voucher_ledger_accounts($conn, $companyId), null, 'id');
    $ins = $conn->prepare("INSERT INTO fin_settings (company_id, setting_key, account_id, updated_by) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), updated_by = VALUES(updated_by)");
    foreach (FIN_SETTINGS as $key => $label) {
        if (!array_key_exists($key, $values)) {
            continue;
        }
        $id = (int)$values[$key];
        if ($id !== 0 && !isset($accounts[$id])) {
            throw new InvalidArgumentException($label . ': choose an account of this company.');
        }
        $ins->execute([$companyId, $key, $id ?: null, $userId]);
    }
}

/** Active suppliers of Vendor Management for the company: id => name, by name. */
function fin_si_suppliers(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("SELECT id, TRIM(vendor_name) FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
    $stmt->execute([$companyId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * The account last booked for each supplier, so the form can suggest it:
 * vendor id => account id (the first line of the supplier's latest invoice).
 */
function fin_si_last_accounts(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("
        SELECT s.vendor_id, e.account_id
          FROM fin_supplier_invoices s
          JOIN fin_vouchers v ON v.id = s.voucher_id AND v.status = 'posted'
          JOIN fin_ledger_entries e ON e.voucher_id = s.voucher_id AND e.line_no = 1
         WHERE s.company_id = ?
           AND s.voucher_id = (SELECT MAX(s2.voucher_id) FROM fin_supplier_invoices s2
                                 JOIN fin_vouchers v2 ON v2.id = s2.voucher_id AND v2.status = 'posted'
                                WHERE s2.company_id = s.company_id AND s2.vendor_id = s.vendor_id)
    ");
    $stmt->execute([$companyId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['vendor_id']] = (int)$r['account_id'];
    }
    return $out;
}

/**
 * Check what was entered for a supplier invoice and work out what it posts.
 *
 * $data: vendor_id, invoice_no, date (invoice date), narration (description),
 * vat_rate ('5' or '0'), vat_amount (optional; worked out when blank) and
 * lines, each [account_id, description, amount] with the amount before VAT.
 * Returns vendor_id, vendor (name), invoice_no, date, narration, vat_rate, net,
 * vat, total and postings ([account id, description, debit, credit, partner]).
 * Throws InvalidArgumentException with a message fit to show the user.
 */
function fin_si_prepare(PDO $conn, int $companyId, array $data): array
{
    $vendorId = (int)($data['vendor_id'] ?? 0);
    $suppliers = fin_si_suppliers($conn, $companyId);
    if (!isset($suppliers[$vendorId])) {
        throw new InvalidArgumentException('Choose the supplier.');
    }
    $invoiceNo = trim((string)($data['invoice_no'] ?? ''));
    if ($invoiceNo === '') {
        throw new InvalidArgumentException('Enter the supplier invoice number.');
    }
    if (mb_strlen($invoiceNo) > 100) {
        throw new InvalidArgumentException('The supplier invoice number is too long (100 characters at most).');
    }
    $date = trim((string)($data['date'] ?? ''));
    if (!fin_voucher_valid_date($date)) {
        throw new InvalidArgumentException('Enter a valid invoice date.');
    }
    $narration = trim((string)($data['narration'] ?? ''));
    if ($narration === '') {
        throw new InvalidArgumentException('Enter a description.');
    }
    if (mb_strlen($narration) > 255) {
        throw new InvalidArgumentException('The description is too long (255 characters at most).');
    }
    $rateKey = (string)($data['vat_rate'] ?? '');
    if (!isset(FIN_SI_VAT_RATES[$rateKey])) {
        throw new InvalidArgumentException('Choose the VAT: 5% or 0%.');
    }
    $rate = FIN_SI_VAT_RATES[$rateKey];

    $settings = fin_settings_get($conn, $companyId);
    $accounts = array_column(fin_voucher_ledger_accounts($conn, $companyId), null, 'id');
    $payableId = (int)$settings['si_payable'];
    $vatId = (int)$settings['si_vat_input'];
    if (!isset($accounts[$payableId])) {
        throw new InvalidArgumentException('Choose the supplier payable account in Finance Settings first.');
    }
    if ($rate > 0 && !isset($accounts[$vatId])) {
        throw new InvalidArgumentException('Choose the Input VAT account in Finance Settings first.');
    }

    $postings = [];
    $net = 0.0;
    $n = 0;
    foreach ((is_array($data['lines'] ?? null) ? $data['lines'] : []) as $line) {
        $n++;
        $accountId = (int)($line['account_id'] ?? 0);
        $desc = trim((string)($line['description'] ?? ''));
        $amount = fin_voucher_parse_amount($line['amount'] ?? '');
        if ($amount === null) {
            throw new InvalidArgumentException("Line {$n}: the amount is not a number.");
        }
        if ($accountId === 0 && $amount == 0 && $desc === '') {
            continue; // an empty row left on the form
        }
        if (!isset($accounts[$accountId])) {
            throw new InvalidArgumentException("Line {$n}: choose the expense account.");
        }
        if (in_array($accountId, [$payableId, $vatId], true)) {
            throw new InvalidArgumentException("Line {$n}: the supplier payable and Input VAT accounts are posted by the invoice itself.");
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException("Line {$n}: enter an amount greater than zero.");
        }
        if (mb_strlen($desc) > 255) {
            throw new InvalidArgumentException("Line {$n}: the description is too long (255 characters at most).");
        }
        $postings[] = [$accountId, $desc !== '' ? $desc : $narration, $amount, 0.0, null];
        $net += $amount;
    }
    if (!$postings) {
        throw new InvalidArgumentException('Add at least one line.');
    }
    $net = round($net, 2);

    $expectedVat = round($net * $rate / 100, 2);
    $vatRaw = trim((string)($data['vat_amount'] ?? ''));
    $vat = $vatRaw === '' ? $expectedVat : fin_voucher_parse_amount($vatRaw);
    if ($vat === null) {
        throw new InvalidArgumentException('The VAT amount is not a number.');
    }
    if ($rate == 0 && $vat != 0) {
        throw new InvalidArgumentException('An invoice at 0% VAT cannot have a VAT amount.');
    }
    if (abs($vat - $expectedVat) > FIN_SI_VAT_TOLERANCE + 0.004) {
        throw new InvalidArgumentException('The VAT amount (' . number_format($vat, 2) . ') is too far from 5% of ' . number_format($net, 2) . ' (' . number_format($expectedVat, 2) . ').');
    }
    $total = round($net + $vat, 2);
    if ($total > FIN_VOUCHER_MAX_AMOUNT) {
        throw new InvalidArgumentException('The invoice total is too large.');
    }

    $supplier = $suppliers[$vendorId];
    if ($vat > 0) {
        $postings[] = [$vatId, 'Input VAT ' . $invoiceNo, $vat, 0.0, null];
    }
    $postings[] = [$payableId, mb_substr($supplier . ' inv ' . $invoiceNo, 0, 255), 0.0, $total, 're_vendor:' . $vendorId];

    return [
        'vendor_id' => $vendorId, 'vendor' => mb_substr($supplier, 0, 150), 'invoice_no' => $invoiceNo,
        'date' => $date, 'narration' => $narration, 'vat_rate' => $rate,
        'net' => $net, 'vat' => $vat, 'total' => $total, 'postings' => $postings,
    ];
}

/** Refuses an invoice number this supplier already has on a posted invoice (other than $exceptId). */
function fin_si_check_duplicate(PDO $conn, int $companyId, int $vendorId, string $invoiceNo, int $exceptId = 0): void
{
    // Lock the supplier, so two people booking the same number at once cannot both get in.
    $conn->prepare("SELECT id FROM re_vendors WHERE id = ? AND company_id = ? FOR UPDATE")->execute([$vendorId, $companyId]);
    $stmt = $conn->prepare("
        SELECT v.voucher_no FROM fin_supplier_invoices s
          JOIN fin_vouchers v ON v.id = s.voucher_id AND v.status = 'posted'
         WHERE s.company_id = ? AND s.vendor_id = ? AND TRIM(s.invoice_no) = ? AND s.voucher_id <> ?
         LIMIT 1");
    $stmt->execute([$companyId, $vendorId, $invoiceNo, $exceptId]);
    $dup = $stmt->fetchColumn();
    if ($dup !== false) {
        throw new InvalidArgumentException("This supplier's invoice {$invoiceNo} is already booked as {$dup}.");
    }
}

/**
 * Check uploaded files before anything is saved. $files is one entry of $_FILES
 * in the "name[]" shape. Returns the files to keep, each [tmp, name, ext, size].
 * Throws InvalidArgumentException with a message fit to show the user.
 */
function fin_attach_check(array $files): array
{
    $out = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ((array)($files['name'] ?? []) as $i => $name) {
        $name = (string)$name;
        $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException("\"{$name}\" is too large (10 MB at most).");
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException("\"{$name}\" did not upload. Please try again.");
        }
        $tmp = (string)$files['tmp_name'][$i];
        $size = (int)$files['size'][$i];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(FIN_ATTACH_TYPES[$ext])) {
            throw new InvalidArgumentException("\"{$name}\": attach a PDF or an image (JPG, PNG, WEBP).");
        }
        if ($size <= 0 || $size > FIN_ATTACH_MAX_BYTES) {
            throw new InvalidArgumentException("\"{$name}\" is empty or larger than 10 MB.");
        }
        // The content must be what the name says, whatever the browser claimed.
        if ($finfo->file($tmp) !== FIN_ATTACH_TYPES[$ext]) {
            throw new InvalidArgumentException("\"{$name}\" is not a real " . strtoupper($ext) . ' file.');
        }
        $out[] = [$tmp, mb_substr($name, 0, 255), $ext, $size];
    }
    return $out;
}

/**
 * Store checked files against a voucher. Returns the paths written, so a caller
 * whose transaction fails can remove them again.
 * $move is how a file reaches its place (move_uploaded_file; tests pass copy).
 */
function fin_attach_store(PDO $conn, int $companyId, int $voucherId, array $checked, ?int $userId, ?callable $move = null): array
{
    $move = $move ?? 'move_uploaded_file';
    if (!is_dir(FIN_ATTACH_DIR) && !mkdir(FIN_ATTACH_DIR, 0755, true) && !is_dir(FIN_ATTACH_DIR)) {
        throw new RuntimeException('Could not create ' . FIN_ATTACH_DIR);
    }
    // Served only through fin_attachment_file.php: refuse direct links, also on a fresh server.
    if (!file_exists(FIN_ATTACH_DIR . '/.htaccess')) {
        file_put_contents(FIN_ATTACH_DIR . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $ins = $conn->prepare("INSERT INTO fin_voucher_attachments (company_id, voucher_id, file_name, stored_name, file_size, uploaded_by) VALUES (?,?,?,?,?,?)");
    $written = [];
    foreach ($checked as [$tmp, $name, $ext, $size]) {
        $stored = 'v' . $voucherId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $path = FIN_ATTACH_DIR . '/' . $stored;
        if (!$move($tmp, $path)) {
            throw new RuntimeException("Could not store {$name}");
        }
        $written[] = $path;
        $ins->execute([$companyId, $voucherId, $name, $stored, $size, $userId]);
    }
    return $written;
}

/** Files attached to a voucher and not removed, oldest first. */
function fin_attach_list(PDO $conn, int $companyId, int $voucherId): array
{
    $stmt = $conn->prepare("
        SELECT a.id, a.file_name, a.file_size, a.uploaded_at, COALESCE(NULLIF(TRIM(u.fullname), ''), u.username) AS uploaded_by_name
          FROM fin_voucher_attachments a
          LEFT JOIN user u ON u.id = a.uploaded_by
         WHERE a.voucher_id = ? AND a.company_id = ? AND a.removed_at IS NULL
         ORDER BY a.id");
    $stmt->execute([$voucherId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Save and post a supplier invoice with its files. Returns the voucher id.
 * $data is described at fin_si_prepare(); $checked comes from fin_attach_check().
 */
function fin_si_create(PDO $conn, int $companyId, array $data, array $checked, ?int $userId = null, ?callable $move = null): int
{
    $p = fin_si_prepare($conn, $companyId, $data);
    if (!$checked) {
        throw new InvalidArgumentException('Attach the supplier invoice (image or PDF).');
    }
    $written = [];
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        fin_si_check_duplicate($conn, $companyId, $p['vendor_id'], $p['invoice_no']);
        $voucherNo = fin_voucher_next_no($conn, $companyId, 'si', $p['date']);
        $conn->prepare("INSERT INTO fin_vouchers (company_id, type, voucher_no, voucher_date, party, reference, narration, amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$companyId, 'si', $voucherNo, $p['date'], $p['vendor'], $p['invoice_no'], $p['narration'], $p['total'], $userId]);
        $voucherId = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO fin_supplier_invoices (voucher_id, company_id, vendor_id, invoice_no, vat_rate, net_amount, vat_amount, total_amount) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$voucherId, $companyId, $p['vendor_id'], $p['invoice_no'], $p['vat_rate'], $p['net'], $p['vat'], $p['total']]);
        fin_voucher_post_lines($conn, $companyId, $voucherId, $p['date'], $p['postings'], $userId);
        $written = fin_attach_store($conn, $companyId, $voucherId, $checked, $userId, $move);
        fin_voucher_log($conn, $companyId, $voucherId, 'created', $userId, null, fin_voucher_snapshot($conn, $companyId, $voucherId));
        if ($ownTx) {
            $conn->commit();
        }
        return $voucherId;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        array_map('unlink', $written);
        throw $e;
    }
}

/**
 * Change a posted supplier invoice: details and lines are replaced by $data,
 * files in $removeIds are taken off and $checked are added. At least one file
 * must remain. Returns false when nothing was different.
 */
function fin_si_update(PDO $conn, int $companyId, int $id, array $data, array $checked, array $removeIds, ?int $userId = null, ?callable $move = null): bool
{
    $written = [];
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $stmt = $conn->prepare("SELECT type, status FROM fin_vouchers WHERE id = ? AND company_id = ? FOR UPDATE");
        $stmt->execute([$id, $companyId]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$v || $v['type'] !== 'si') {
            throw new InvalidArgumentException('Supplier invoice not found in this company.');
        }
        if ($v['status'] !== 'posted') {
            throw new InvalidArgumentException('A deleted invoice cannot be edited.');
        }
        $p = fin_si_prepare($conn, $companyId, $data);
        fin_si_check_duplicate($conn, $companyId, $p['vendor_id'], $p['invoice_no'], $id);
        // Payments already settle part of it: same supplier, and no less than what is paid.
        $allocated = fin_voucher_links($conn, $companyId, $id)['allocated'];
        if ($allocated > 0) {
            $current = fin_si_get($conn, $companyId, $id);
            if ((int)$current['vendor_id'] !== $p['vendor_id']) {
                throw new InvalidArgumentException('Payments are allocated to this invoice, so its supplier cannot change. Remove the allocations first.');
            }
            if ($p['total'] + 0.004 < $allocated) {
                throw new InvalidArgumentException('The total cannot go below what is already paid against it (' . number_format($allocated, 2) . ').');
            }
        }

        $kept = array_column(fin_attach_list($conn, $companyId, $id), 'id');
        $removeIds = array_values(array_intersect(array_map('intval', $removeIds), array_map('intval', $kept)));
        if (count($kept) - count($removeIds) + count($checked) < 1) {
            throw new InvalidArgumentException('Keep or attach at least one copy of the invoice.');
        }
        $before = fin_voucher_snapshot($conn, $companyId, $id);

        $conn->prepare("UPDATE fin_vouchers SET voucher_date = ?, party = ?, reference = ?, narration = ?, amount = ? WHERE id = ? AND company_id = ?")
            ->execute([$p['date'], $p['vendor'], $p['invoice_no'], $p['narration'], $p['total'], $id, $companyId]);
        $conn->prepare("UPDATE fin_supplier_invoices SET vendor_id = ?, invoice_no = ?, vat_rate = ?, net_amount = ?, vat_amount = ?, total_amount = ? WHERE voucher_id = ? AND company_id = ?")
            ->execute([$p['vendor_id'], $p['invoice_no'], $p['vat_rate'], $p['net'], $p['vat'], $p['total'], $id, $companyId]);
        $conn->prepare("DELETE FROM fin_ledger_entries WHERE voucher_id = ? AND company_id = ?")->execute([$id, $companyId]);
        fin_voucher_post_lines($conn, $companyId, $id, $p['date'], $p['postings'], $userId);
        if ($removeIds) {
            $rm = $conn->prepare("UPDATE fin_voucher_attachments SET removed_by = ?, removed_at = NOW() WHERE id = ? AND voucher_id = ? AND company_id = ?");
            foreach ($removeIds as $rid) {
                $rm->execute([$userId, $rid, $id, $companyId]);
            }
        }
        $written = fin_attach_store($conn, $companyId, $id, $checked, $userId, $move);

        $after = fin_voucher_snapshot($conn, $companyId, $id);
        $changed = $before != $after;
        if ($changed) {
            fin_voucher_log($conn, $companyId, $id, 'edited', $userId, $before, $after);
        }
        if ($ownTx) {
            $conn->commit();
        }
        return $changed;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        array_map('unlink', $written);
        throw $e;
    }
}

/** A supplier invoice's own details (vendor_id, invoice_no, vat_rate, net, vat, total), or null. */
function fin_si_get(PDO $conn, int $companyId, int $voucherId): ?array
{
    $stmt = $conn->prepare("SELECT vendor_id, invoice_no, vat_rate, net_amount, vat_amount, total_amount FROM fin_supplier_invoices WHERE voucher_id = ? AND company_id = ?");
    $stmt->execute([$voucherId, $companyId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** What a saved invoice holds, as the form takes it: only the expense lines, VAT and supplier lines left out. */
function fin_si_form_data(PDO $conn, int $companyId, array $v): array
{
    $si = fin_si_get($conn, $companyId, (int)$v['id']);
    // Posted in order: the expense lines, then Input VAT when there is any, then the supplier.
    $lines = [];
    $expense = array_slice($v['lines'], 0, max(0, count($v['lines']) - 1 - ($si && (float)$si['vat_amount'] > 0 ? 1 : 0)));
    foreach ($expense as $l) {
        $lines[] = [
            'account_id'  => (int)$l['account_id'],
            'description' => (string)$l['description'] === (string)$v['narration'] ? '' : (string)$l['description'],
            'amount'      => number_format((float)$l['debit'], 2, '.', ''),
        ];
    }
    return [
        'vendor_id'  => $si ? (int)$si['vendor_id'] : 0,
        'invoice_no' => $si ? (string)$si['invoice_no'] : (string)$v['reference'],
        'date'       => $v['voucher_date'],
        'narration'  => (string)$v['narration'],
        'vat_rate'   => $si && (float)$si['vat_rate'] == 0 ? '0' : '5',
        'vat_amount' => $si ? number_format((float)$si['vat_amount'], 2, '.', '') : '',
        'lines'      => $lines,
    ];
}
