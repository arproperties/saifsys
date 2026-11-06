<?php
/**
 * Finance > Supplier payments and allocations.
 *
 * Pay a Supplier makes an ordinary payment voucher (pv, paid from a Cash & banks
 * account) in one of three ways:
 *   bills       Against Bills - Dr supplier payable; settles the invoices ticked
 *   on_account  On Account    - Dr supplier payable; settles nothing yet
 *   advance     Advance       - Dr supplier advance account; settles nothing yet
 * An on-account payment or an advance is linked to an invoice later, in
 * Supplier Allocations. Linking an advance also moves it to the payable with a
 * journal (supplier payable Dr, advance Cr) dated on the later of the payment
 * and the invoice; the payment and the invoice keep their own dates. Linking an
 * on-account payment needs no entry: both already sit on the payable.
 *
 * An invoice's outstanding amount is its total less its allocations; a
 * payment's unallocated amount is its amount less its allocations.
 */

require_once __DIR__ . '/fin_supplier_invoice_helper.php';

const FIN_SP_MODES = [
    'bills'      => 'Against Bills',
    'on_account' => 'On Account',
    'advance'    => 'Advance',
];

function fin_sp_ready(PDO $conn): bool
{
    return fin_si_ready($conn) && fin_alloc_ready($conn);
}

/**
 * Supplier invoices with what is paid and outstanding, oldest first.
 * $vendorId narrows to one supplier; $openOnly leaves out the paid ones.
 */
function fin_sp_invoices(PDO $conn, int $companyId, ?int $vendorId = null, bool $openOnly = true): array
{
    $sql = "
        SELECT v.id, v.voucher_no, v.voucher_date, v.narration, s.vendor_id, s.invoice_no, s.total_amount,
               COALESCE((SELECT SUM(a.amount) FROM fin_payment_allocations a WHERE a.invoice_voucher_id = v.id AND a.removed_at IS NULL), 0) AS paid
          FROM fin_supplier_invoices s
          JOIN fin_vouchers v ON v.id = s.voucher_id AND v.status = 'posted'
         WHERE s.company_id = ?";
    $args = [$companyId];
    if ($vendorId !== null) {
        $sql .= " AND s.vendor_id = ?";
        $args[] = $vendorId;
    }
    $stmt = $conn->prepare($sql . " ORDER BY v.voucher_date, v.id");
    $stmt->execute($args);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['paid'] = round((float)$r['paid'], 2);
        $r['outstanding'] = round((float)$r['total_amount'] - $r['paid'], 2);
        if ($openOnly && $r['outstanding'] <= 0) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

/** "Paid", "Partly paid" or "Unpaid" for an invoice row of fin_sp_invoices(), as a badge. */
function fin_sp_status_badge(float $total, float $paid): string
{
    if ($paid <= 0) {
        return '<span class="badge text-bg-warning">Unpaid</span>';
    }
    return $paid + 0.004 >= $total ? '<span class="badge text-bg-success">Paid</span>' : '<span class="badge text-bg-info">Partly paid</span>';
}

/** Supplier payments made On Account or as Advance with some of them not yet allocated, oldest first. */
function fin_sp_unallocated(PDO $conn, int $companyId, ?int $vendorId = null): array
{
    $sql = "
        SELECT v.id, v.voucher_no, v.voucher_date, v.reference, p.vendor_id, p.mode, p.amount,
               COALESCE((SELECT SUM(a.amount) FROM fin_payment_allocations a WHERE a.payment_voucher_id = v.id AND a.removed_at IS NULL), 0) AS allocated
          FROM fin_supplier_payments p
          JOIN fin_vouchers v ON v.id = p.voucher_id AND v.status = 'posted'
         WHERE p.company_id = ? AND p.mode IN ('on_account', 'advance')";
    $args = [$companyId];
    if ($vendorId !== null) {
        $sql .= " AND p.vendor_id = ?";
        $args[] = $vendorId;
    }
    $stmt = $conn->prepare($sql . " ORDER BY v.voucher_date, v.id");
    $stmt->execute($args);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['allocated'] = round((float)$r['allocated'], 2);
        $r['remaining'] = round((float)$r['amount'] - $r['allocated'], 2);
        if ($r['remaining'] > 0) {
            $out[] = $r;
        }
    }
    return $out;
}

/** A supplier payment's own details (vendor_id, mode, amount), or null when the voucher is not one. */
function fin_sp_get(PDO $conn, int $companyId, int $voucherId): ?array
{
    $stmt = $conn->prepare("SELECT vendor_id, mode, amount FROM fin_supplier_payments WHERE voucher_id = ? AND company_id = ?");
    $stmt->execute([$voucherId, $companyId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Allocations of a supplier's On Account and Advance payments (not removed), newest first. */
function fin_sp_vendor_allocations(PDO $conn, int $companyId, int $vendorId): array
{
    $stmt = $conn->prepare("
        SELECT a.id, a.amount, a.created_at, a.payment_voucher_id, a.invoice_voucher_id, a.transfer_voucher_id,
               pv.voucher_no AS payment_no, pv.voucher_date AS payment_date, sp.mode,
               iv.voucher_no AS invoice_no, iv.voucher_date AS invoice_date, si.invoice_no AS supplier_invoice_no,
               tv.voucher_no AS transfer_no,
               COALESCE(NULLIF(TRIM(u.fullname), ''), u.username) AS created_by_name
          FROM fin_payment_allocations a
          JOIN fin_vouchers pv ON pv.id = a.payment_voucher_id
          JOIN fin_supplier_payments sp ON sp.voucher_id = a.payment_voucher_id
          JOIN fin_vouchers iv ON iv.id = a.invoice_voucher_id
          JOIN fin_supplier_invoices si ON si.voucher_id = a.invoice_voucher_id
          LEFT JOIN fin_vouchers tv ON tv.id = a.transfer_voucher_id
          LEFT JOIN user u ON u.id = a.created_by
         WHERE a.company_id = ? AND sp.vendor_id = ? AND sp.mode <> 'bills' AND a.removed_at IS NULL
         ORDER BY a.id DESC");
    $stmt->execute([$companyId, $vendorId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Allocations of a payment or of an invoice (not removed), each with both voucher
 * numbers and dates, the transfer voucher if any, and who made it.
 */
function fin_sp_allocations(PDO $conn, int $companyId, int $voucherId): array
{
    $stmt = $conn->prepare("
        SELECT a.id, a.amount, a.created_at, a.payment_voucher_id, a.invoice_voucher_id, a.transfer_voucher_id,
               pv.voucher_no AS payment_no, pv.voucher_date AS payment_date, sp.mode,
               iv.voucher_no AS invoice_no, iv.voucher_date AS invoice_date, si.invoice_no AS supplier_invoice_no,
               tv.voucher_no AS transfer_no,
               COALESCE(NULLIF(TRIM(u.fullname), ''), u.username) AS created_by_name
          FROM fin_payment_allocations a
          JOIN fin_vouchers pv ON pv.id = a.payment_voucher_id
          JOIN fin_supplier_payments sp ON sp.voucher_id = a.payment_voucher_id
          JOIN fin_vouchers iv ON iv.id = a.invoice_voucher_id
          JOIN fin_supplier_invoices si ON si.voucher_id = a.invoice_voucher_id
          LEFT JOIN fin_vouchers tv ON tv.id = a.transfer_voucher_id
          LEFT JOIN user u ON u.id = a.created_by
         WHERE a.company_id = ? AND (a.payment_voucher_id = ? OR a.invoice_voucher_id = ?) AND a.removed_at IS NULL
         ORDER BY a.id");
    $stmt->execute([$companyId, $voucherId, $voucherId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check [invoice id => amount] against a supplier's open invoices: each invoice
 * the supplier's, each amount above zero and within what is outstanding.
 * Blank or zero amounts are dropped. Returns [invoice id => amount, ...] and
 * the open invoices by id. Locks the invoices, so call inside the transaction.
 */
function fin_sp_check_allocations(PDO $conn, int $companyId, int $vendorId, array $wanted): array
{
    // Lock the invoices first, so two people cannot both pay the same outstanding amount.
    $ids = array_values(array_filter(array_map('intval', array_keys($wanted))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $conn->prepare("SELECT id FROM fin_vouchers WHERE id IN ($in) AND company_id = ? FOR UPDATE")->execute(array_merge($ids, [$companyId]));
    }
    $open = array_column(fin_sp_invoices($conn, $companyId, $vendorId), null, 'id');
    $out = [];
    foreach ($wanted as $invoiceId => $raw) {
        $amount = fin_voucher_parse_amount($raw);
        if ($amount === null) {
            throw new InvalidArgumentException('An amount to allocate is not a number.');
        }
        if ($amount <= 0) {
            continue;
        }
        $invoiceId = (int)$invoiceId;
        if (!isset($open[$invoiceId])) {
            throw new InvalidArgumentException('An invoice chosen is not an open invoice of this supplier.');
        }
        if ($amount > $open[$invoiceId]['outstanding'] + 0.004) {
            throw new InvalidArgumentException($open[$invoiceId]['voucher_no'] . ': ' . number_format($amount, 2) . ' is more than its outstanding ' . number_format($open[$invoiceId]['outstanding'], 2) . '.');
        }
        $out[$invoiceId] = $amount;
    }
    return [$out, $open];
}

/**
 * Pay a supplier. Returns the payment voucher id.
 * $data: date, cash_account_id, vendor_id, mode (bills, on_account, advance),
 * reference, narration, and for bills `allocations` [invoice id => amount]
 * (the payment is their sum), otherwise `amount`.
 * Throws InvalidArgumentException with a message fit to show the user.
 */
function fin_sp_create(PDO $conn, int $companyId, array $data, ?int $userId = null): int
{
    $vendorId = (int)($data['vendor_id'] ?? 0);
    $suppliers = fin_si_suppliers($conn, $companyId);
    if (!isset($suppliers[$vendorId])) {
        throw new InvalidArgumentException('Choose the supplier.');
    }
    $mode = (string)($data['mode'] ?? '');
    if (!isset(FIN_SP_MODES[$mode])) {
        throw new InvalidArgumentException('Choose how the payment is made: Against Bills, On Account or Advance.');
    }
    $settings = fin_settings_get($conn, $companyId);
    $accounts = array_column(fin_voucher_ledger_accounts($conn, $companyId), null, 'id');
    $debitId = (int)($mode === 'advance' ? $settings['sp_advance'] : $settings['si_payable']);
    if (!isset($accounts[$debitId])) {
        throw new InvalidArgumentException('Choose the ' . ($mode === 'advance' ? 'supplier advance' : 'supplier payable') . ' account in Finance Settings first.');
    }

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $allocations = [];
        if ($mode === 'bills') {
            [$allocations] = fin_sp_check_allocations($conn, $companyId, $vendorId, (array)($data['allocations'] ?? []));
            if (!$allocations) {
                throw new InvalidArgumentException('Enter the amount paid against at least one invoice.');
            }
            $amount = round(array_sum($allocations), 2);
        } else {
            $amount = fin_voucher_parse_amount($data['amount'] ?? '');
            if ($amount === null || $amount <= 0) {
                throw new InvalidArgumentException('Enter the amount paid.');
            }
        }

        $supplier = $suppliers[$vendorId];
        // The usual payment voucher: the line is the supplier's, the paid-from account is credited.
        $id = fin_voucher_create($conn, $companyId, 'pv', [
            'date' => $data['date'] ?? '',
            'cash_account_id' => $data['cash_account_id'] ?? 0,
            'party' => mb_substr($supplier, 0, 150),
            'reference' => $data['reference'] ?? '',
            'narration' => trim((string)($data['narration'] ?? '')) !== '' ? $data['narration'] : FIN_SP_MODES[$mode] . ' - ' . $supplier,
            'lines' => [['account_id' => $debitId, 'description' => FIN_SP_MODES[$mode], 'partner' => 're_vendor:' . $vendorId, 'amount' => (string)$amount]],
        ], $userId);
        $conn->prepare("INSERT INTO fin_supplier_payments (voucher_id, company_id, vendor_id, mode, amount) VALUES (?,?,?,?,?)")
            ->execute([$id, $companyId, $vendorId, $mode, $amount]);
        $ins = $conn->prepare("INSERT INTO fin_payment_allocations (company_id, payment_voucher_id, invoice_voucher_id, amount, created_by) VALUES (?,?,?,?,?)");
        foreach ($allocations as $invoiceId => $amt) {
            $ins->execute([$companyId, $id, $invoiceId, $amt, $userId]);
        }
        if ($ownTx) {
            $conn->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Link an On Account payment or an Advance to invoices of the same supplier:
 * [invoice id => amount], in all no more than what is left of the payment.
 * An advance also gets its transfer journal per invoice. Returns the number of
 * invoices linked. Throws InvalidArgumentException with a message fit to show.
 */
function fin_sp_allocate(PDO $conn, int $companyId, int $paymentId, array $wanted, ?int $userId = null): int
{
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $stmt = $conn->prepare("SELECT v.voucher_no, v.voucher_date, v.status, p.vendor_id, p.mode, p.amount
                                  FROM fin_vouchers v JOIN fin_supplier_payments p ON p.voucher_id = v.id
                                 WHERE v.id = ? AND v.company_id = ? FOR UPDATE");
        $stmt->execute([$paymentId, $companyId]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay || $pay['status'] !== 'posted') {
            throw new InvalidArgumentException('Payment not found in this company.');
        }
        if ($pay['mode'] === 'bills') {
            throw new InvalidArgumentException('A payment against bills is allocated when it is made.');
        }
        $vendorId = (int)$pay['vendor_id'];
        [$allocations, $open] = fin_sp_check_allocations($conn, $companyId, $vendorId, $wanted);
        if (!$allocations) {
            throw new InvalidArgumentException('Enter the amount to allocate to at least one invoice.');
        }
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM fin_payment_allocations WHERE payment_voucher_id = ? AND removed_at IS NULL");
        $stmt->execute([$paymentId]);
        $left = round((float)$pay['amount'] - (float)$stmt->fetchColumn(), 2);
        $total = round(array_sum($allocations), 2);
        if ($total > $left + 0.004) {
            throw new InvalidArgumentException('Only ' . number_format($left, 2) . ' of ' . $pay['voucher_no'] . ' is left to allocate; ' . number_format($total, 2) . ' was entered.');
        }

        $settings = fin_settings_get($conn, $companyId);
        $suppliers = fin_si_suppliers($conn, $companyId);
        $ins = $conn->prepare("INSERT INTO fin_payment_allocations (company_id, payment_voucher_id, invoice_voucher_id, amount, transfer_voucher_id, created_by) VALUES (?,?,?,?,?,?)");
        foreach ($allocations as $invoiceId => $amt) {
            $transferId = null;
            if ($pay['mode'] === 'advance') {
                // Advance account to payable, on the later date: the advance is only used once the invoice exists.
                $inv = $open[$invoiceId];
                $date = max($pay['voucher_date'], $inv['voucher_date']);
                $payable = (int)$settings['si_payable'];
                $advance = (int)$settings['sp_advance'];
                if (!$payable || !$advance) {
                    throw new InvalidArgumentException('Choose the supplier payable and supplier advance accounts in Finance Settings first.');
                }
                $partner = 're_vendor:' . $vendorId;
                $transferId = fin_voucher_create($conn, $companyId, 'jv', [
                    'date' => $date,
                    'party' => mb_substr($suppliers[$vendorId] ?? '', 0, 150),
                    'reference' => $pay['voucher_no'] . ' → ' . $inv['voucher_no'],
                    'narration' => mb_substr('Advance ' . $pay['voucher_no'] . ' applied to ' . $inv['voucher_no'] . ' (inv ' . $inv['invoice_no'] . ')', 0, 255),
                    'lines' => [
                        ['account_id' => $payable, 'description' => '', 'partner' => $partner, 'debit' => (string)$amt, 'credit' => ''],
                        ['account_id' => $advance, 'description' => '', 'partner' => $partner, 'debit' => '', 'credit' => (string)$amt],
                    ],
                ], $userId);
            }
            $ins->execute([$companyId, $paymentId, $invoiceId, $amt, $transferId, $userId]);
        }
        if ($ownTx) {
            $conn->commit();
        }
        return count($allocations);
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Take an allocation off: the invoice is outstanding again and the payment free
 * to allocate. Its transfer journal, if any, is deleted.
 * A payment Against Bills is all allocations, so its allocations stay with it:
 * delete the payment instead.
 */
function fin_sp_unallocate(PDO $conn, int $companyId, int $allocationId, ?int $userId = null): void
{
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $stmt = $conn->prepare("SELECT a.id, a.transfer_voucher_id, p.mode FROM fin_payment_allocations a
                                  JOIN fin_supplier_payments p ON p.voucher_id = a.payment_voucher_id
                                 WHERE a.id = ? AND a.company_id = ? AND a.removed_at IS NULL FOR UPDATE");
        $stmt->execute([$allocationId, $companyId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            throw new InvalidArgumentException('Allocation not found in this company.');
        }
        if ($a['mode'] === 'bills') {
            throw new InvalidArgumentException('This was paid against the bill directly. Delete the payment voucher to undo it.');
        }
        $conn->prepare("UPDATE fin_payment_allocations SET removed_by = ?, removed_at = NOW() WHERE id = ?")->execute([$userId, $allocationId]);
        if ($a['transfer_voucher_id'] !== null) {
            fin_voucher_void($conn, $companyId, (int)$a['transfer_voucher_id'], $userId);
        }
        if ($ownTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}
