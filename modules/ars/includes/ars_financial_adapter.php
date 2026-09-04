<?php
/**
 * ARS Financial Adapter — sole supported path from ARS ops → shared accounting engine.
 * Does NOT modify accounting_engine.php. Does NOT write re_invoices.
 *
 * Feature flag: ars_company_settings.financial_adapter_enabled (default 0).
 */

require_once __DIR__ . '/ars_helpers.php';
require_once __DIR__ . '/ars_account_roles.php';
require_once __DIR__ . '/ars_financial_document_sm.php';
require_once dirname(__DIR__, 2) . '/realestate/accounting/accounting_engine.php';

if (!function_exists('ars_booking_activity_log')) {
    require_once __DIR__ . '/ars_activity.php';
}

function ars_financial_adapter_tables_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'ars_financial_documents'");
        $ready = (bool) ($stmt && $stmt->fetchColumn());
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function ars_financial_adapter_enabled(PDO $conn, ?int $companyId = null): bool {
    if (!ars_financial_adapter_tables_ready($conn)) {
        return false;
    }
    $settings = getArsSettings($conn, $companyId);
    return !empty($settings['financial_adapter_enabled']);
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function ars_adapter_fail(string $error, string $code, array $extra = []): array {
    return array_merge([
        'success' => false,
        'error' => $error,
        'code' => $code,
        'document_id' => null,
        'journal_id' => null,
    ], $extra);
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function ars_adapter_ok(array $extra = []): array {
    return array_merge([
        'success' => true,
        'error' => null,
        'code' => null,
    ], $extra);
}

function ars_adapter_ops_company_id(array $booking): int {
    return (int) ($booking['company_id'] ?? 0);
}

/**
 * GL / journal posting company (BR-ARS-FIN-001 → RE company).
 * ARS document rows stay on ops company via ars_adapter_ops_company_id().
 */
function ars_adapter_posting_company_id(PDO $conn, array $booking): int {
    return ars_financial_gl_company_id($conn, ars_adapter_ops_company_id($booking));
}

function ars_adapter_assert_company(int $companyId): ?array {
    if ($companyId <= 0) {
        return ars_adapter_fail('Missing company_id — fail closed', 'company_mismatch');
    }
    return null;
}

/**
 * Find existing document by idempotency key (company-scoped).
 * @return array<string,mixed>|null
 */
function ars_adapter_find_by_idempotency(PDO $conn, int $companyId, string $key): ?array {
    if ($key === '') {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT * FROM ars_financial_documents
        WHERE company_id = ? AND idempotency_key = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ars_adapter_next_document_number(PDO $conn, int $companyId, string $prefix): string {
    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM ars_financial_documents
        WHERE company_id = ? AND document_number LIKE ?
    ");
    $stmt->execute([$companyId, $like]);
    $seq = ((int) $stmt->fetchColumn()) + 1;
    return $prefix . '-' . $year . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
}

/**
 * Safe activity log — never throws into financial txn after commit preference.
 * Call AFTER successful commit when possible.
 *
 * @param array<string,mixed> $payload
 */
function ars_adapter_log_activity(PDO $conn, array $payload): void {
    try {
        if (function_exists('ars_booking_activity_log')) {
            ars_booking_activity_log($conn, $payload);
        }
    } catch (Throwable $e) {
        error_log('ARS adapter activity log failed: ' . $e->getMessage());
    }
}

/**
 * Booking net revenue amounts — mirrors ars_post_booking_revenue.
 * @return array{net:float,vat:float,total:float}
 */
function ars_adapter_booking_revenue_amounts(array $booking): array {
    if (isset($booking['net_amount']) && $booking['net_amount'] !== null && $booking['net_amount'] !== '') {
        $netRevenue = round((float) $booking['net_amount'], 2);
    } else {
        $netRevenue = round(
            (float) $booking['subtotal']
            - (float) ($booking['length_discount_amount'] ?? 0)
            - (float) ($booking['discount_amount'] ?? 0)
            + (float) ($booking['extras_total'] ?? 0),
            2
        );
    }
    $vatAmount = round((float) ($booking['vat_amount'] ?? 0), 2);
    return [
        'net' => $netRevenue,
        'vat' => $vatAmount,
        'total' => round($netRevenue + $vatAmount, 2),
    ];
}

/**
 * Insert document header + lines (caller owns transaction).
 *
 * @param list<array<string,mixed>> $lines
 * @return array{success:bool,document_id:?int,error:?string,code:?string}
 */
function ars_adapter_insert_document(
    PDO $conn,
    int $companyId,
    array $booking,
    string $documentType,
    string $documentNumber,
    string $documentDate,
    array $amounts,
    array $lines,
    ?string $idempotencyKey,
    ?int $userId,
    ?int $parentDocumentId = null,
    string $status = 'draft'
): array {
    $conn->prepare("
        INSERT INTO ars_financial_documents (
            company_id, booking_id, guest_id, document_type, document_number, document_date,
            status, currency, subtotal, vat_amount, total_amount, amount_allocated, balance_due,
            vat_mode, vat_rate, parent_document_id, idempotency_key, source, created_by
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, 0.00, ?,
            ?, ?, ?, ?, 'adapter', ?
        )
    ")->execute([
        $companyId,
        (int) $booking['id'],
        $booking['guest_id'] ?? null,
        $documentType,
        $documentNumber,
        $documentDate,
        $status,
        $booking['currency'] ?? 'AED',
        $amounts['net'],
        $amounts['vat'],
        $amounts['total'],
        $amounts['total'],
        $booking['vat_mode'] ?? null,
        $booking['vat_rate'] ?? null,
        $parentDocumentId,
        $idempotencyKey,
        $userId,
    ]);
    $documentId = (int) $conn->lastInsertId();
    if ($documentId <= 0) {
        return ['success' => false, 'document_id' => null, 'error' => 'Failed to insert document', 'code' => 'validation_failed'];
    }

    $lineStmt = $conn->prepare("
        INSERT INTO ars_financial_document_lines (
            company_id, document_id, line_no, line_type, description, quantity, unit_price,
            line_total, vat_rate, vat_amount, account_role, related_charge_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $n = 1;
    foreach ($lines as $line) {
        $lineStmt->execute([
            $companyId,
            $documentId,
            $n++,
            $line['line_type'] ?? 'other',
            $line['description'] ?? '',
            $line['quantity'] ?? 1,
            $line['unit_price'] ?? 0,
            $line['line_total'] ?? 0,
            $line['vat_rate'] ?? null,
            $line['vat_amount'] ?? 0,
            $line['account_role'] ?? null,
            $line['related_charge_id'] ?? null,
        ]);
    }

    $conn->prepare("
        INSERT INTO ars_financial_document_transitions
            (company_id, document_id, from_status, to_status, changed_by, note)
        VALUES (?, ?, NULL, ?, ?, 'created')
    ")->execute([$companyId, $documentId, $status, $userId]);

    return ['success' => true, 'document_id' => $documentId, 'error' => null, 'code' => null];
}

/**
 * EVT-01 — Original booking invoice + journal (mirror of ars_post_booking_revenue).
 *
 * @param array<string,mixed> $booking
 * @param array<string,mixed> $opts user_id, idempotency_key, document_date, skip_activity
 */
function ars_adapter_create_original_invoice(PDO $conn, array $booking, array $opts = []): array {
    if (!ars_financial_adapter_tables_ready($conn)) {
        return ars_adapter_fail('Financial document tables not migrated', 'validation_failed');
    }

    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    if ($err = ars_adapter_assert_company($opsCompanyId)) {
        return $err;
    }
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return ars_adapter_fail('Missing financial (GL) company_id — fail closed', 'company_mismatch');
    }

    $userId = isset($opts['user_id']) ? (int) $opts['user_id'] : null;
    $idem = (string) ($opts['idempotency_key'] ?? ('invoice:original:' . (int) $booking['id']));

    $existing = ars_adapter_find_by_idempotency($conn, $opsCompanyId, $idem);
    if ($existing) {
        return ars_adapter_ok([
            'code' => 'idempotent_replay',
            'document_id' => (int) $existing['id'],
            'journal_id' => $existing['journal_id'] ? (int) $existing['journal_id'] : null,
            'replay' => true,
        ]);
    }

    $roles = ars_require_account_roles($conn, $glCompanyId, ['AR_GUEST', 'ROOM_REVENUE', 'VAT_OUTPUT']);
    if (!$roles['success']) {
        return ars_adapter_fail((string) $roles['error'], (string) $roles['code']);
    }
    $ar = $roles['accounts']['AR_GUEST'];
    $rev = $roles['accounts']['ROOM_REVENUE'];
    $vat = $roles['accounts']['VAT_OUTPUT'];

    $amounts = ars_adapter_booking_revenue_amounts($booking);
    if ($amounts['total'] <= 0) {
        return ars_adapter_fail('Invoice total must be positive', 'validation_failed');
    }

    $documentDate = (string) ($opts['document_date'] ?? (
        !empty($booking['is_historical']) && !empty($booking['check_in'])
            ? $booking['check_in']
            : date('Y-m-d')
    ));

    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }

        $docNo = ars_adapter_next_document_number($conn, $opsCompanyId, 'ARS-INV');
        $docLines = [
            [
                'line_type' => 'room',
                'description' => 'Room Revenue - Booking ' . $booking['booking_number'],
                'quantity' => 1,
                'unit_price' => $amounts['net'],
                'line_total' => $amounts['net'],
                'vat_rate' => $booking['vat_rate'] ?? null,
                'vat_amount' => $amounts['vat'],
                'account_role' => 'ROOM_REVENUE',
            ],
        ];
        if ($amounts['vat'] > 0) {
            $docLines[] = [
                'line_type' => 'vat',
                'description' => 'Output VAT - Booking ' . $booking['booking_number'],
                'quantity' => 1,
                'unit_price' => $amounts['vat'],
                'line_total' => $amounts['vat'],
                'vat_amount' => 0,
                'account_role' => 'VAT_OUTPUT',
            ];
        }

        $ins = ars_adapter_insert_document(
            $conn, $opsCompanyId, $booking, 'original_invoice', $docNo, $documentDate,
            $amounts, $docLines, $idem, $userId, null, 'draft'
        );
        if (!$ins['success']) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail((string) $ins['error'], (string) $ins['code']);
        }
        $documentId = (int) $ins['document_id'];

        $t1 = ars_fin_doc_transition($conn, $opsCompanyId, $documentId, 'validated', $userId, 'auto-validate');
        if (!$t1['success']) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail((string) $t1['error'], (string) $t1['code']);
        }

        $journalLines = [
            [
                'account_id' => $ar['id'],
                'debit' => $amounts['total'],
                'credit' => 0,
                'description' => 'AR - Booking ' . $booking['booking_number'],
                'reference' => $booking['booking_number'],
            ],
            [
                'account_id' => $rev['id'],
                'debit' => 0,
                'credit' => $amounts['net'],
                'description' => 'Room Revenue - Booking ' . $booking['booking_number'],
                'reference' => $booking['booking_number'],
            ],
        ];
        if ($amounts['vat'] > 0) {
            $journalLines[] = [
                'account_id' => $vat['id'],
                'debit' => 0,
                'credit' => $amounts['vat'],
                'description' => 'Output VAT - Booking ' . $booking['booking_number'],
                'reference' => $booking['booking_number'],
            ];
        }

        $jr = create_and_post_journal(
            $glCompanyId,
            'invoice',
            'ars_financial_document',
            $documentId,
            $journalLines,
            'ARS Booking Revenue: ' . $booking['booking_number'],
            $documentDate,
            $userId
        );

        if (empty($jr['success']) || empty($jr['journal_id'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail(
                $jr['error'] ?? 'Journal posting failed',
                'journal_failed',
                ['document_id' => null]
            );
        }

        $journalId = (int) $jr['journal_id'];
        $conn->prepare("
            UPDATE ars_financial_documents SET journal_id = ? WHERE id = ? AND company_id = ?
        ")->execute([$journalId, $documentId, $opsCompanyId]);

        $t2 = ars_fin_doc_transition($conn, $opsCompanyId, $documentId, 'posted', $userId, 'posted with journal');
        if (!$t2['success']) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail((string) $t2['error'], (string) $t2['code']);
        }

        $conn->prepare("
            UPDATE ars_bookings
            SET journal_id = ?, primary_invoice_document_id = ?
            WHERE id = ? AND company_id = ?
        ")->execute([$journalId, $documentId, (int) $booking['id'], $opsCompanyId]);

        if ($ownTxn) {
            $conn->commit();
        }

        if (empty($opts['skip_activity'])) {
            ars_adapter_log_activity($conn, [
                'company_id' => $opsCompanyId,
                'booking_id' => (int) $booking['id'],
                'event_category' => 'accounting',
                'event_type' => 'invoice_created',
                'title' => 'Original invoice ' . $docNo . ' posted',
                'related_entity_type' => 'ars_financial_document',
                'related_entity_id' => $documentId,
                'related_document_number' => $docNo,
                'related_journal_id' => $journalId,
                'created_by' => $userId,
                'source' => 'system',
                'dedupe_key' => 'invoice_created:' . $documentId,
            ]);
        }

        return ars_adapter_ok([
            'document_id' => $documentId,
            'journal_id' => $journalId,
            'document_number' => $docNo,
        ]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        if (str_contains($e->getMessage(), 'uq_ars_fin_doc_idem') || str_contains($e->getMessage(), 'Duplicate')) {
            $existing = ars_adapter_find_by_idempotency($conn, $opsCompanyId, $idem);
            if ($existing) {
                return ars_adapter_ok([
                    'code' => 'idempotent_replay',
                    'document_id' => (int) $existing['id'],
                    'journal_id' => $existing['journal_id'] ? (int) $existing['journal_id'] : null,
                    'replay' => true,
                ]);
            }
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

/**
 * EVT payment — record payment journal + allocate to open documents.
 *
 * @param array<string,mixed> $payment must include id, company_id, amount, payment_method, payment_date
 * @param array<string,mixed> $booking
 * @param array<string,mixed> $opts
 */
function ars_adapter_record_payment(PDO $conn, array $payment, array $booking, array $opts = []): array {
    if (!ars_financial_adapter_tables_ready($conn)) {
        return ars_adapter_fail('Financial document tables not migrated', 'validation_failed');
    }

    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    if ($err = ars_adapter_assert_company($opsCompanyId)) {
        return $err;
    }
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return ars_adapter_fail('Missing financial (GL) company_id — fail closed', 'company_mismatch');
    }
    if ((int) ($payment['company_id'] ?? 0) !== $opsCompanyId) {
        return ars_adapter_fail('Payment/booking company mismatch', 'company_mismatch');
    }

    $userId = isset($opts['user_id']) ? (int) $opts['user_id'] : null;
    $paymentId = (int) ($payment['id'] ?? 0);
    $idem = (string) ($opts['idempotency_key'] ?? ('payment:journal:' . $paymentId));

    // Idempotency via payment.journal_id already set
    if (!empty($payment['journal_id'])) {
        return ars_adapter_ok([
            'code' => 'idempotent_replay',
            'journal_id' => (int) $payment['journal_id'],
            'document_id' => $payment['financial_document_id'] ?? null,
            'replay' => true,
        ]);
    }

    $method = (string) ($payment['payment_method'] ?? 'cash');
    $receiptCode = (string) ($opts['receipt_account_code'] ?? $payment['receipt_account_code'] ?? '');
    $receipt = ars_resolve_receipt_account($conn, $glCompanyId, $method, $receiptCode);
    if (!$receipt['success']) {
        return ars_adapter_fail((string) $receipt['error'], (string) ($receipt['code'] ?? 'validation_failed'));
    }

    $roles = ars_require_account_roles($conn, $glCompanyId, ['AR_GUEST']);
    if (!$roles['success']) {
        return ars_adapter_fail((string) $roles['error'], (string) $roles['code']);
    }

    $amount = round((float) $payment['amount'], 2);
    if ($amount <= 0) {
        return ars_adapter_fail('Payment amount must be positive', 'validation_failed');
    }

    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }

        $cash = $receipt['account'];
        $ar = $roles['accounts']['AR_GUEST'];
        $ref = $payment['reference_number'] ?: $booking['booking_number'];

        $jr = create_and_post_journal(
            $glCompanyId,
            'payment',
            'ars_payment',
            $paymentId,
            [
                [
                    'account_id' => $cash['id'],
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Payment received - ' . $booking['booking_number'],
                    'reference' => $ref,
                ],
                [
                    'account_id' => $ar['id'],
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'AR settlement - ' . $booking['booking_number'],
                    'reference' => $ref,
                ],
            ],
            'ARS Payment: ' . $booking['booking_number'],
            $payment['payment_date'] ?? date('Y-m-d'),
            $userId
        );

        if (empty($jr['success']) || empty($jr['journal_id'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'Payment journal failed', 'journal_failed');
        }
        $journalId = (int) $jr['journal_id'];

        $conn->prepare("UPDATE ars_booking_payments SET journal_id = ?, receipt_account_code = ? WHERE id = ? AND company_id = ?")
            ->execute([$journalId, $receipt['account_code'], $paymentId, $opsCompanyId]);

        $alloc = ars_adapter_allocate_payment($conn, [
            'company_id' => $opsCompanyId,
            'booking_id' => (int) $booking['id'],
            'payment_id' => $paymentId,
            'amount' => $amount,
            'allocation_date' => $payment['payment_date'] ?? date('Y-m-d'),
            'journal_id' => $journalId,
            'idempotency_key' => 'alloc:payment:' . $paymentId,
            'user_id' => $userId,
        ], ['nested' => true]);

        if (!$alloc['success'] && ($alloc['code'] ?? '') !== 'idempotent_replay') {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return $alloc;
        }

        if ($ownTxn) {
            $conn->commit();
        }

        // Phase 2D: preserve unallocated overpayment as guest credit
        $creditId = null;
        $unallocated = (float)($alloc['unallocated'] ?? 0);
        if ($unallocated > 0.001) {
            require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
            $policy = ars_financial_policy($conn, $opsCompanyId);
            if (!empty($policy['overpay_to_guest_credit'])) {
                // Payment JV credited full amount to AR; reclass excess to guest credit
                $gc = ars_adapter_create_guest_credit($conn, [
                    'company_id' => $opsCompanyId,
                    'guest_id' => (int)($booking['guest_id'] ?? 0),
                    'booking_id' => (int)$booking['id'],
                    'payment_id' => $paymentId,
                    'source_type' => 'overpayment',
                    'amount' => $unallocated,
                    'post_reclass' => true,
                    'idempotency_key' => 'credit:overpay:' . $paymentId,
                    'user_id' => $userId,
                ]);
                if (!empty($gc['success'])) {
                    $creditId = $gc['credit_id'] ?? null;
                }
            }
        }

        if (empty($opts['skip_activity'])) {
            ars_adapter_log_activity($conn, [
                'company_id' => $opsCompanyId,
                'booking_id' => (int) $booking['id'],
                'event_category' => 'payment',
                'event_type' => 'payment_recorded',
                'title' => 'Payment posted via adapter (AED ' . number_format($amount, 2) . ')',
                'related_entity_type' => 'ars_booking_payment',
                'related_entity_id' => $paymentId,
                'related_journal_id' => $journalId,
                'created_by' => $userId,
                'source' => 'system',
                'dedupe_key' => 'payment_recorded:' . $paymentId,
            ]);
        }

        return ars_adapter_ok([
            'journal_id' => $journalId,
            'document_id' => $alloc['document_id'] ?? null,
            'allocation_ids' => $alloc['allocation_ids'] ?? [],
            'unallocated' => $unallocated,
            'credit_id' => $creditId,
        ]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

/**
 * Allocate payment amount across open posted invoices (FIFO by document_date, id).
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $opts nested=true skips own transaction
 */
function ars_adapter_allocate_payment(PDO $conn, array $payload, array $opts = []): array {
    $companyId = (int) ($payload['company_id'] ?? 0);
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }

    $bookingId = (int) $payload['booking_id'];
    $paymentId = (int) $payload['payment_id'];
    $remaining = round((float) $payload['amount'], 2);
    $allocDate = (string) ($payload['allocation_date'] ?? date('Y-m-d'));
    $journalId = isset($payload['journal_id']) ? (int) $payload['journal_id'] : null;
    $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
    $idem = (string) ($payload['idempotency_key'] ?? ('alloc:payment:' . $paymentId));

    $chk = $conn->prepare("
        SELECT id FROM ars_payment_allocations
        WHERE company_id = ? AND idempotency_key = ?
        LIMIT 1
    ");
    $chk->execute([$companyId, $idem]);
    if ($chk->fetchColumn()) {
        return ars_adapter_ok(['code' => 'idempotent_replay', 'replay' => true, 'allocation_ids' => []]);
    }

    $docs = $conn->prepare("
        SELECT id, total_amount, amount_allocated, balance_due, status
        FROM ars_financial_documents
        WHERE company_id = ? AND booking_id = ?
          AND document_type IN ('original_invoice','extension_invoice','service_invoice','adjustment_invoice')
          AND status IN ('posted','partially_paid')
          AND balance_due > 0
        ORDER BY document_date ASC, id ASC
        FOR UPDATE
    ");

    $ownTxn = empty($opts['nested']) && !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }

        $docs->execute([$companyId, $bookingId]);
        $openDocs = $docs->fetchAll(PDO::FETCH_ASSOC);
        $allocationIds = [];
        $firstDocId = null;
        $n = 0;

        foreach ($openDocs as $doc) {
            if ($remaining <= 0.00001) {
                break;
            }
            $balance = round((float) $doc['balance_due'], 2);
            $apply = min($remaining, $balance);
            if ($apply <= 0) {
                continue;
            }

            $key = $n === 0 ? $idem : ($idem . ':' . $n);
            $conn->prepare("
                INSERT INTO ars_payment_allocations (
                    company_id, booking_id, payment_id, document_id, amount,
                    allocation_date, journal_id, status, idempotency_key, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
            ")->execute([
                $companyId, $bookingId, $paymentId, (int) $doc['id'], $apply,
                $allocDate, $journalId, $key, $userId,
            ]);
            $allocationIds[] = (int) $conn->lastInsertId();
            if ($firstDocId === null) {
                $firstDocId = (int) $doc['id'];
            }

            $newAllocated = round((float) $doc['amount_allocated'] + $apply, 2);
            $newBalance = round((float) $doc['total_amount'] - $newAllocated, 2);
            $newStatus = ars_fin_doc_status_from_balances((float) $doc['total_amount'], $newAllocated);

            $conn->prepare("
                UPDATE ars_financial_documents
                SET amount_allocated = ?, balance_due = ?, status = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([$newAllocated, max(0, $newBalance), $newStatus, (int) $doc['id'], $companyId]);

            if ($newStatus !== $doc['status']) {
                $conn->prepare("
                    INSERT INTO ars_financial_document_transitions
                        (company_id, document_id, from_status, to_status, changed_by, note)
                    VALUES (?, ?, ?, ?, ?, 'payment allocation')
                ")->execute([$companyId, (int) $doc['id'], $doc['status'], $newStatus, $userId]);
            }

            $remaining = round($remaining - $apply, 2);
            $n++;
        }

        if ($firstDocId) {
            $conn->prepare("
                UPDATE ars_booking_payments SET financial_document_id = ?
                WHERE id = ? AND company_id = ?
            ")->execute([$firstDocId, $paymentId, $companyId]);
        }

        if ($ownTxn) {
            $conn->commit();
        }

        return ars_adapter_ok([
            'allocation_ids' => $allocationIds,
            'document_id' => $firstDocId,
            'unallocated' => $remaining,
        ]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'uq_ars_alloc')) {
            return ars_adapter_ok(['code' => 'idempotent_replay', 'replay' => true]);
        }
        return ars_adapter_fail($e->getMessage(), 'over_allocation');
    }
}

/**
 * Deposit received — mirror ars_post_deposit_received + ars_security_deposits row.
 */
function ars_adapter_receive_deposit(
    PDO $conn,
    array $booking,
    float $amount,
    string $method,
    array $opts = []
): array {
    if (!ars_financial_adapter_tables_ready($conn)) {
        return ars_adapter_fail('Financial document tables not migrated', 'validation_failed');
    }

    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    if ($err = ars_adapter_assert_company($opsCompanyId)) {
        return $err;
    }
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return ars_adapter_fail('Missing financial (GL) company_id — fail closed', 'company_mismatch');
    }

    $userId = isset($opts['user_id']) ? (int) $opts['user_id'] : null;
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return ars_adapter_fail('Deposit amount must be positive', 'validation_failed');
    }

    $idem = (string) ($opts['idempotency_key'] ?? ('deposit:received:' . (int) $booking['id'] . ':' . $amount));

    $stmt = $conn->prepare("
        SELECT * FROM ars_security_deposits
        WHERE company_id = ? AND idempotency_key = ?
        LIMIT 1
    ");
    $stmt->execute([$opsCompanyId, $idem]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return ars_adapter_ok([
            'code' => 'idempotent_replay',
            'deposit_id' => (int) $existing['id'],
            'journal_id' => $existing['journal_id'] ? (int) $existing['journal_id'] : null,
            'replay' => true,
        ]);
    }

    $receiptCode = (string) ($opts['receipt_account_code'] ?? '');
    $receipt = ars_resolve_receipt_account($conn, $glCompanyId, $method, $receiptCode);
    if (!$receipt['success']) {
        return ars_adapter_fail((string) $receipt['error'], (string) ($receipt['code'] ?? 'validation_failed'));
    }
    $roles = ars_require_account_roles($conn, $glCompanyId, ['SECURITY_DEPOSIT']);
    if (!$roles['success']) {
        return ars_adapter_fail((string) $roles['error'], (string) $roles['code']);
    }

    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }

        $year = date('Y');
        $cnt = $conn->prepare("SELECT COUNT(*)+1 FROM ars_security_deposits WHERE company_id = ? AND YEAR(created_at)=YEAR(NOW())");
        $cnt->execute([$opsCompanyId]);
        $depNo = 'ARS-DEP-' . $year . '-' . str_pad((string) $cnt->fetchColumn(), 5, '0', STR_PAD_LEFT);

        $conn->prepare("
            INSERT INTO ars_security_deposits (
                company_id, booking_id, deposit_number, event_type, amount, status,
                idempotency_key, created_by
            ) VALUES (?, ?, ?, 'received', ?, 'draft', ?, ?)
        ")->execute([$opsCompanyId, (int) $booking['id'], $depNo, $amount, $idem, $userId]);
        $depositId = (int) $conn->lastInsertId();

        $cash = $receipt['account'];
        $depAcct = $roles['accounts']['SECURITY_DEPOSIT'];

        $jr = create_and_post_journal(
            $glCompanyId,
            'deposit',
            'ars_security_deposit',
            $depositId,
            [
                [
                    'account_id' => $cash['id'],
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Deposit received - ' . $booking['booking_number'],
                    'reference' => $booking['booking_number'],
                ],
                [
                    'account_id' => $depAcct['id'],
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Guest deposit liability - ' . $booking['booking_number'],
                    'reference' => $booking['booking_number'],
                ],
            ],
            'ARS Security Deposit: ' . $booking['booking_number'],
            date('Y-m-d'),
            $userId
        );

        if (empty($jr['success']) || empty($jr['journal_id'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'Deposit journal failed', 'journal_failed');
        }
        $journalId = (int) $jr['journal_id'];

        $conn->prepare("
            UPDATE ars_security_deposits
            SET status = 'posted', journal_id = ?, receipt_account_code = ?, posted_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$journalId, $receipt['account_code'], $depositId, $opsCompanyId]);

        $conn->prepare("
            UPDATE ars_bookings SET deposit_journal_id = ? WHERE id = ? AND company_id = ?
        ")->execute([$journalId, (int) $booking['id'], $opsCompanyId]);

        if ($ownTxn) {
            $conn->commit();
        }

        if (empty($opts['skip_activity'])) {
            ars_adapter_log_activity($conn, [
                'company_id' => $opsCompanyId,
                'booking_id' => (int) $booking['id'],
                'event_category' => 'payment',
                'event_type' => 'deposit_received',
                'title' => 'Security deposit received ' . $depNo,
                'related_entity_type' => 'ars_security_deposit',
                'related_entity_id' => $depositId,
                'related_document_number' => $depNo,
                'related_journal_id' => $journalId,
                'created_by' => $userId,
                'source' => 'system',
                'dedupe_key' => 'deposit_received:' . $depositId,
            ]);
        }

        return ars_adapter_ok(['deposit_id' => $depositId, 'journal_id' => $journalId]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

/**
 * Deposit refund — mirror bridge.
 */
function ars_adapter_refund_deposit(
    PDO $conn,
    array $booking,
    float $refundAmount,
    string $method,
    array $opts = []
): array {
    if (!ars_financial_adapter_tables_ready($conn)) {
        return ars_adapter_fail('Financial document tables not migrated', 'validation_failed');
    }

    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    if ($err = ars_adapter_assert_company($opsCompanyId)) {
        return $err;
    }
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return ars_adapter_fail('Missing financial (GL) company_id — fail closed', 'company_mismatch');
    }

    $userId = isset($opts['user_id']) ? (int) $opts['user_id'] : null;
    $refundAmount = round($refundAmount, 2);
    if ($refundAmount <= 0) {
        return ars_adapter_fail('Refund amount must be positive', 'validation_failed');
    }

    $idem = (string) ($opts['idempotency_key'] ?? ('deposit:refund:' . (int) $booking['id'] . ':' . $refundAmount . ':' . time()));
    // Prefer stable key when provided by caller for true idempotency

    $receiptCode = (string) ($opts['receipt_account_code'] ?? '');
    $receipt = ars_resolve_receipt_account($conn, $glCompanyId, $method, $receiptCode);
    if (!$receipt['success']) {
        return ars_adapter_fail((string) $receipt['error'], (string) ($receipt['code'] ?? 'validation_failed'));
    }
    $roles = ars_require_account_roles($conn, $glCompanyId, ['SECURITY_DEPOSIT']);
    if (!$roles['success']) {
        return ars_adapter_fail((string) $roles['error'], (string) $roles['code']);
    }

    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }

        $year = date('Y');
        $cnt = $conn->prepare("SELECT COUNT(*)+1 FROM ars_security_deposits WHERE company_id = ? AND YEAR(created_at)=YEAR(NOW())");
        $cnt->execute([$opsCompanyId]);
        $depNo = 'ARS-DEP-' . $year . '-' . str_pad((string) $cnt->fetchColumn(), 5, '0', STR_PAD_LEFT);

        $eventType = ($opts['full'] ?? true) ? 'full_refund' : 'partial_refund';
        $conn->prepare("
            INSERT INTO ars_security_deposits (
                company_id, booking_id, deposit_number, event_type, amount, status,
                idempotency_key, created_by
            ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)
        ")->execute([$opsCompanyId, (int) $booking['id'], $depNo, $eventType, $refundAmount, $idem, $userId]);
        $depositId = (int) $conn->lastInsertId();

        $cash = $receipt['account'];
        $depAcct = $roles['accounts']['SECURITY_DEPOSIT'];

        $jr = create_and_post_journal(
            $glCompanyId,
            'refund',
            'ars_security_deposit',
            $depositId,
            [
                [
                    'account_id' => $depAcct['id'],
                    'debit' => $refundAmount,
                    'credit' => 0,
                    'description' => 'Deposit refund - ' . $booking['booking_number'],
                    'reference' => $booking['booking_number'],
                ],
                [
                    'account_id' => $cash['id'],
                    'debit' => 0,
                    'credit' => $refundAmount,
                    'description' => 'Deposit refund paid - ' . $booking['booking_number'],
                    'reference' => $booking['booking_number'],
                ],
            ],
            'ARS Deposit Refund: ' . $booking['booking_number'],
            date('Y-m-d'),
            $userId
        );

        if (empty($jr['success']) || empty($jr['journal_id'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'Deposit refund journal failed', 'journal_failed');
        }
        $journalId = (int) $jr['journal_id'];

        $conn->prepare("
            UPDATE ars_security_deposits
            SET status = 'posted', journal_id = ?, receipt_account_code = ?, posted_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$journalId, $receipt['account_code'], $depositId, $opsCompanyId]);

        $conn->prepare("
            UPDATE ars_bookings SET deposit_refund_journal_id = ? WHERE id = ? AND company_id = ?
        ")->execute([$journalId, (int) $booking['id'], $opsCompanyId]);

        if ($ownTxn) {
            $conn->commit();
        }

        if (empty($opts['skip_activity'])) {
            ars_adapter_log_activity($conn, [
                'company_id' => $opsCompanyId,
                'booking_id' => (int) $booking['id'],
                'event_category' => 'payment',
                'event_type' => 'deposit_refunded',
                'title' => 'Security deposit refunded ' . $depNo,
                'related_entity_type' => 'ars_security_deposit',
                'related_entity_id' => $depositId,
                'related_document_number' => $depNo,
                'related_journal_id' => $journalId,
                'created_by' => $userId,
                'source' => 'system',
                'dedupe_key' => 'deposit_refunded:' . $depositId,
            ]);
        }

        return ars_adapter_ok(['deposit_id' => $depositId, 'journal_id' => $journalId]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

/**
 * Reverse original revenue document (cancel path) — reverse_journal + doc status.
 */
function ars_adapter_reverse_document(PDO $conn, int $companyId, int $documentId, string $reason, ?int $userId = null): array {
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }

    $stmt = $conn->prepare("SELECT * FROM ars_financial_documents WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$documentId, $companyId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        return ars_adapter_fail('Document not found', 'company_mismatch');
    }
    if (empty($doc['journal_id'])) {
        $t = ars_fin_doc_transition($conn, $companyId, $documentId, 'voided', $userId, $reason);
        return $t['success']
            ? ars_adapter_ok(['document_id' => $documentId])
            : ars_adapter_fail((string) $t['error'], (string) $t['code']);
    }

    $rev = reverse_journal((int) $doc['journal_id'], $reason, $userId);
    if (empty($rev['success'])) {
        return ars_adapter_fail($rev['error'] ?? 'Reverse failed', 'journal_failed');
    }

    $reversalId = $rev['reversal_journal_id'] ?? null;
    $conn->prepare("
        UPDATE ars_financial_documents
        SET reversal_journal_id = ?, status = 'reversed', updated_at = NOW()
        WHERE id = ? AND company_id = ?
    ")->execute([$reversalId, $documentId, $companyId]);

    $conn->prepare("
        INSERT INTO ars_financial_document_transitions
            (company_id, document_id, from_status, to_status, changed_by, note)
        VALUES (?, ?, ?, 'reversed', ?, ?)
    ")->execute([$companyId, $documentId, $doc['status'], $userId, $reason]);

    return ars_adapter_ok([
        'document_id' => $documentId,
        'journal_id' => $reversalId,
        'reversal_journal_id' => $reversalId,
    ]);
}

/**
 * Phase 2D — previously gated workflows (interim localhost rules).
 */
function ars_adapter_create_extension_invoice(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_create_extension_invoice_impl($conn, $booking, $opts);
}

function ars_adapter_create_service_invoice(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_create_service_invoice_impl($conn, $booking, $opts);
}

function ars_adapter_create_adjustment_invoice(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_create_adjustment_invoice_impl($conn, $booking, $opts);
}

function ars_adapter_create_credit_note(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_create_credit_note_impl($conn, $booking, $opts);
}

function ars_adapter_forfeit_deposit(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_forfeit_deposit_impl($conn, $booking, $opts);
}

function ars_adapter_create_refund(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_create_refund_impl($conn, $booking, $opts);
}

function ars_adapter_stripe_settlement(PDO $conn, array $booking = [], array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    // $booking unused; company from opts
    if (empty($opts['company_id']) && !empty($booking['company_id'])) {
        $opts['company_id'] = (int)$booking['company_id'];
    }
    return ars_adapter_stripe_settlement_impl($conn, $opts);
}

function ars_adapter_shorten_booking(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_shorten_booking_impl($conn, $booking, $opts);
}

function ars_adapter_no_show(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_no_show_impl($conn, $booking, $opts);
}

function ars_adapter_cancel_financials(PDO $conn, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_cancel_financials_impl($conn, $booking, $opts);
}

function ars_adapter_stripe_card_payment(PDO $conn, array $payment, array $booking, array $opts = []): array {
    require_once __DIR__ . '/ars_financial_adapter_phase2d.php';
    return ars_adapter_stripe_card_payment_impl($conn, $payment, $booking, $opts);
}

/**
 * Read-only amendment document preview enrichment (no side effects).
 *
 * @param array<string,mixed> $impact from Phase 1 lock helpers
 * @return list<array<string,mixed>>
 */
function ars_adapter_detect_amendment_documents(array $impact): array {
    $docs = [];
    if (!empty($impact['nights_changed']) || !empty($impact['checkout_changed'])) {
        $docs[] = [
            'document_type' => 'extension_invoice',
            'note' => 'May emit extension invoice',
            'blocked' => false,
        ];
    }
    if (!empty($impact['charges_added'])) {
        $docs[] = [
            'document_type' => 'service_invoice',
            'note' => 'May emit service invoice',
            'blocked' => false,
        ];
    }
    if (!empty($impact['rate_reduced']) || !empty($impact['early_checkout'])) {
        $docs[] = [
            'document_type' => 'credit_note',
            'note' => 'May emit credit note',
            'blocked' => false,
        ];
    }
    if (!empty($impact['rate_increased'])) {
        $docs[] = [
            'document_type' => 'adjustment_invoice',
            'note' => 'May emit adjustment invoice',
            'blocked' => false,
        ];
    }
    return $docs;
}
