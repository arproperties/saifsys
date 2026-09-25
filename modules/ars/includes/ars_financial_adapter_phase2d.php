<?php
/**
 * ARS Financial Adapter — Phase 2D gated workflows
 * Bound by PHASE2D_FINAL_BUSINESS_RULE_REGISTER.md (localhost interim rules).
 */

require_once __DIR__ . '/ars_financial_adapter.php';
require_once __DIR__ . '/ars_availability.php';

function ars_financial_policy(PDO $conn, int $companyId): array {
    static $cache = [];
    if (isset($cache[$companyId])) {
        return $cache[$companyId];
    }
    $defaults = [
        'early_checkout_refundable' => 1,
        'cancellation_fee_percent' => 0.0,
        'no_show_fee_mode' => 'keep_revenue',
        'stripe_fee_percent' => 0.0,
        'overpay_to_guest_credit' => 1,
    ];
    try {
        $st = $conn->prepare('SELECT * FROM ars_financial_policy WHERE company_id = ? LIMIT 1');
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cache[$companyId] = $row ? array_merge($defaults, $row) : $defaults;
    } catch (Throwable $e) {
        $cache[$companyId] = $defaults;
    }
    return $cache[$companyId];
}

function ars_adapter_vat_split(float $net, array $booking): array {
    $mode = strtolower((string)($booking['vat_mode'] ?? 'exclusive'));
    $rate = (float)($booking['vat_rate'] ?? 5);
    if ($mode === 'none' || $rate <= 0) {
        return ['net' => round($net, 2), 'vat' => 0.0, 'total' => round($net, 2)];
    }
    if ($mode === 'inclusive') {
        $total = round($net, 2);
        $vat = round($total - ($total / (1 + $rate / 100)), 2);
        return ['net' => round($total - $vat, 2), 'vat' => $vat, 'total' => $total];
    }
    $vat = round($net * $rate / 100, 2);
    return ['net' => round($net, 2), 'vat' => $vat, 'total' => round($net + $vat, 2)];
}

/**
 * Create guest credit from overpayment (liability).
 */
function ars_adapter_create_guest_credit(PDO $conn, array $payload): array {
    $companyId = (int)($payload['company_id'] ?? 0);
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }
    $amount = round((float)($payload['amount'] ?? 0), 2);
    if ($amount <= 0) {
        return ars_adapter_fail('Credit amount must be positive', 'validation_failed');
    }
    $idem = (string)($payload['idempotency_key'] ?? '');
    if ($idem !== '') {
        $chk = $conn->prepare('SELECT * FROM ars_guest_credits WHERE company_id=? AND idempotency_key=? LIMIT 1');
        $chk->execute([$companyId, $idem]);
        $ex = $chk->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            return ars_adapter_ok(['code' => 'idempotent_replay', 'credit_id' => (int)$ex['id'], 'replay' => true]);
        }
    }

    $glCompanyId = ars_financial_gl_company_id($conn, $companyId);
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return $err;
    }

    $roles = ars_require_account_roles($conn, $glCompanyId, ['GUEST_CREDIT', 'AR_GUEST']);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }

    // Payment already DR cash CR AR for full P. Reclass excess: DR AR / CR GUEST_CREDIT
    // If source is 'payment_reclass', journal that. If 'direct', same.
    $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : null;
    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $conn->prepare("
            INSERT INTO ars_guest_credits (
                company_id, guest_id, booking_id, payment_id, source_type, amount, balance,
                status, idempotency_key, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?)
        ")->execute([
            $companyId,
            (int)$payload['guest_id'],
            $payload['booking_id'] ?? null,
            $payload['payment_id'] ?? null,
            $payload['source_type'] ?? 'overpayment',
            $amount,
            $amount,
            $idem ?: null,
            $payload['notes'] ?? null,
            $userId,
        ]);
        $creditId = (int)$conn->lastInsertId();

        $journalId = null;
        if (!empty($payload['post_reclass'])) {
            $ar = $roles['accounts']['AR_GUEST'];
            $gc = $roles['accounts']['GUEST_CREDIT'];
            $jr = create_and_post_journal(
                $glCompanyId,
                'adjustment',
                'ars_guest_credit',
                $creditId,
                [
                    ['account_id' => $ar['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Reclass overpay to guest credit', 'reference' => (string)$creditId],
                    ['account_id' => $gc['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Guest credit liability', 'reference' => (string)$creditId],
                ],
                'ARS Guest Credit',
                date('Y-m-d'),
                $userId
            );
            if (empty($jr['success'])) {
                if ($ownTxn && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                return ars_adapter_fail($jr['error'] ?? 'credit journal failed', 'journal_failed');
            }
            $journalId = (int)$jr['journal_id'];
            $conn->prepare('UPDATE ars_guest_credits SET journal_id=? WHERE id=? AND company_id=?')
                ->execute([$journalId, $creditId, $companyId]);
        }

        if ($ownTxn) {
            $conn->commit();
        }

        if (!empty($payload['booking_id'])) {
            ars_adapter_log_activity($conn, [
                'company_id' => $companyId,
                'booking_id' => (int)$payload['booking_id'],
                'event_category' => 'payment',
                'event_type' => 'guest_credit_created',
                'title' => 'Guest credit AED ' . number_format($amount, 2),
                'related_entity_type' => 'ars_guest_credit',
                'related_entity_id' => $creditId,
                'related_journal_id' => $journalId,
                'created_by' => $userId,
                'source' => 'system',
                'dedupe_key' => 'guest_credit:' . $creditId,
            ]);
        }

        return ars_adapter_ok(['credit_id' => $creditId, 'journal_id' => $journalId]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

/**
 * Apply guest credit to an open document.
 */
function ars_adapter_apply_guest_credit(PDO $conn, int $companyId, int $creditId, int $documentId, float $amount, array $opts = []): array {
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }
    $amount = round($amount, 2);
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $idem = (string)($opts['idempotency_key'] ?? ('credit:apply:' . $creditId . ':' . $documentId . ':' . $amount));

    $glCompanyId = ars_financial_gl_company_id($conn, $companyId);
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return $err;
    }

    $roles = ars_require_account_roles($conn, $glCompanyId, ['GUEST_CREDIT', 'AR_GUEST']);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }

    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $c = $conn->prepare('SELECT * FROM ars_guest_credits WHERE id=? AND company_id=? FOR UPDATE');
        $c->execute([$creditId, $companyId]);
        $credit = $c->fetch(PDO::FETCH_ASSOC);
        if (!$credit || (float)$credit['balance'] < $amount - 0.001) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail('Insufficient credit balance', 'validation_failed');
        }
        $d = $conn->prepare('SELECT * FROM ars_financial_documents WHERE id=? AND company_id=? FOR UPDATE');
        $d->execute([$documentId, $companyId]);
        $doc = $d->fetch(PDO::FETCH_ASSOC);
        if (!$doc || (float)$doc['balance_due'] < $amount - 0.001) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail('Document balance insufficient', 'over_allocation');
        }

        $jr = create_and_post_journal(
            $glCompanyId,
            'payment',
            'ars_guest_credit_application',
            $creditId,
            [
                ['account_id' => $roles['accounts']['GUEST_CREDIT']['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Apply guest credit', 'reference' => (string)$documentId],
                ['account_id' => $roles['accounts']['AR_GUEST']['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'AR settlement via credit', 'reference' => (string)$documentId],
            ],
            'ARS Apply Guest Credit',
            date('Y-m-d'),
            $userId
        );
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];

        $conn->prepare("
            INSERT INTO ars_guest_credit_applications
                (company_id, credit_id, document_id, booking_id, amount, journal_id, idempotency_key, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$companyId, $creditId, $documentId, (int)$doc['booking_id'], $amount, $journalId, $idem, $userId]);

        $newBal = round((float)$credit['balance'] - $amount, 2);
        $applied = round((float)$credit['amount_applied'] + $amount, 2);
        $status = $newBal <= 0.001 ? 'closed' : 'partial';
        $conn->prepare('UPDATE ars_guest_credits SET balance=?, amount_applied=?, status=? WHERE id=? AND company_id=?')
            ->execute([max(0, $newBal), $applied, $status, $creditId, $companyId]);

        $newAlloc = round((float)$doc['amount_allocated'] + $amount, 2);
        $newDue = round((float)$doc['total_amount'] - $newAlloc, 2);
        $newStatus = ars_fin_doc_status_from_balances((float)$doc['total_amount'], $newAlloc);
        $conn->prepare('UPDATE ars_financial_documents SET amount_allocated=?, balance_due=?, status=? WHERE id=? AND company_id=?')
            ->execute([$newAlloc, max(0, $newDue), $newStatus, $documentId, $companyId]);

        if ($ownTxn) {
            $conn->commit();
        }

        ars_adapter_log_activity($conn, [
            'company_id' => $companyId,
            'booking_id' => (int)$doc['booking_id'],
            'event_category' => 'payment',
            'event_type' => 'guest_credit_applied',
            'title' => 'Applied guest credit AED ' . number_format($amount, 2),
            'related_entity_type' => 'ars_financial_document',
            'related_entity_id' => $documentId,
            'related_journal_id' => $journalId,
            'created_by' => $userId,
            'source' => 'system',
            'dedupe_key' => $idem,
        ]);

        return ars_adapter_ok(['journal_id' => $journalId, 'credit_balance' => max(0, $newBal)]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

function ars_adapter_refund_guest_credit(PDO $conn, int $companyId, int $creditId, float $amount, string $method, array $opts = []): array {
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }
    $amount = round($amount, 2);
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $cashRole = ars_cash_or_bank_role($method);
    $glCompanyId = ars_financial_gl_company_id($conn, $companyId);
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return $err;
    }

    $roles = ars_require_account_roles($conn, $glCompanyId, ['GUEST_CREDIT', $cashRole]);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }

    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $c = $conn->prepare('SELECT * FROM ars_guest_credits WHERE id=? AND company_id=? FOR UPDATE');
        $c->execute([$creditId, $companyId]);
        $credit = $c->fetch(PDO::FETCH_ASSOC);
        if (!$credit || (float)$credit['balance'] < $amount - 0.001) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail('Insufficient credit', 'validation_failed');
        }

        $year = date('Y');
        $cnt = $conn->prepare('SELECT COUNT(*)+1 FROM ars_refunds WHERE company_id=? AND YEAR(created_at)=YEAR(NOW())');
        $cnt->execute([$companyId]);
        $refNo = 'ARS-REF-' . $year . '-' . str_pad((string)$cnt->fetchColumn(), 5, '0', STR_PAD_LEFT);

        $conn->prepare("
            INSERT INTO ars_refunds (company_id, booking_id, refund_number, amount, method, status, idempotency_key, notes, created_by)
            VALUES (?, ?, ?, ?, ?, 'draft', ?, 'guest_credit_refund', ?)
        ")->execute([
            $companyId, $credit['booking_id'], $refNo, $amount, $method,
            $opts['idempotency_key'] ?? ('refund:credit:' . $creditId . ':' . $amount), $userId,
        ]);
        $refundId = (int)$conn->lastInsertId();

        $jr = create_and_post_journal(
            $glCompanyId,
            'refund',
            'ars_refund',
            $refundId,
            [
                ['account_id' => $roles['accounts']['GUEST_CREDIT']['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Refund guest credit', 'reference' => $refNo],
                ['account_id' => $roles['accounts'][$cashRole]['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Credit refund paid', 'reference' => $refNo],
            ],
            'ARS Guest Credit Refund',
            date('Y-m-d'),
            $userId
        );
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];
        $conn->prepare("UPDATE ars_refunds SET status='posted', journal_id=?, posted_at=NOW() WHERE id=?")
            ->execute([$journalId, $refundId]);

        $newBal = round((float)$credit['balance'] - $amount, 2);
        $refAmt = round((float)$credit['amount_refunded'] + $amount, 2);
        $conn->prepare("UPDATE ars_guest_credits SET balance=?, amount_refunded=?, status=? WHERE id=?")
            ->execute([max(0, $newBal), $refAmt, $newBal <= 0.001 ? 'refunded' : 'partial', $creditId]);

        if ($ownTxn) {
            $conn->commit();
        }
        return ars_adapter_ok(['refund_id' => $refundId, 'journal_id' => $journalId]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

/**
 * Generic AR invoice poster used by extension/service/adjustment.
 */
function ars_adapter_post_ar_invoice(
    PDO $conn,
    array $booking,
    string $documentType,
    array $amounts,
    array $docLines,
    string $revenueRole,
    array $opts = []
): array {
    if (!ars_financial_adapter_tables_ready($conn)) {
        return ars_adapter_fail('Tables missing', 'validation_failed');
    }
    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    $companyId = $opsCompanyId; // ARS docs/rows stay on ops company
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $idem = (string)($opts['idempotency_key'] ?? '');
    if ($idem !== '') {
        $ex = ars_adapter_find_by_idempotency($conn, $companyId, $idem);
        if ($ex) {
            return ars_adapter_ok([
                'code' => 'idempotent_replay',
                'document_id' => (int)$ex['id'],
                'journal_id' => $ex['journal_id'] ? (int)$ex['journal_id'] : null,
                'replay' => true,
            ]);
        }
    }

    $rolesNeeded = ['AR_GUEST', $revenueRole];
    if ($amounts['vat'] > 0) {
        $rolesNeeded[] = 'VAT_OUTPUT';
    }
    $roles = ars_require_account_roles($conn, $glCompanyId, $rolesNeeded);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }

    $prefix = match ($documentType) {
        'extension_invoice' => 'ARS-EXT',
        'service_invoice' => 'ARS-SVC',
        'adjustment_invoice' => 'ARS-ADJ',
        'credit_note' => 'ARS-CN',
        default => 'ARS-INV',
    };
    $documentDate = (string)($opts['document_date'] ?? date('Y-m-d'));
    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $docNo = ars_adapter_next_document_number($conn, $companyId, $prefix);
        $ins = ars_adapter_insert_document(
            $conn, $companyId, $booking, $documentType, $docNo, $documentDate,
            $amounts, $docLines, $idem ?: null, $userId, $opts['parent_document_id'] ?? null, 'draft'
        );
        if (!$ins['success']) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail((string)$ins['error'], (string)$ins['code']);
        }
        $documentId = (int)$ins['document_id'];
        ars_fin_doc_transition($conn, $companyId, $documentId, 'validated', $userId, 'auto');

        $isCredit = ($documentType === 'credit_note');
        $ar = $roles['accounts']['AR_GUEST'];
        $rev = $roles['accounts'][$revenueRole];
        $lines = [];
        if ($isCredit) {
            // DR revenue, DR VAT, CR AR
            $lines[] = ['account_id' => $rev['id'], 'debit' => $amounts['net'], 'credit' => 0, 'description' => 'CN revenue', 'reference' => $docNo];
            if ($amounts['vat'] > 0) {
                $lines[] = ['account_id' => $roles['accounts']['VAT_OUTPUT']['id'], 'debit' => $amounts['vat'], 'credit' => 0, 'description' => 'CN VAT', 'reference' => $docNo];
            }
            $lines[] = ['account_id' => $ar['id'], 'debit' => 0, 'credit' => $amounts['total'], 'description' => 'CN AR', 'reference' => $docNo];
        } else {
            $lines[] = ['account_id' => $ar['id'], 'debit' => $amounts['total'], 'credit' => 0, 'description' => 'AR', 'reference' => $docNo];
            $lines[] = ['account_id' => $rev['id'], 'debit' => 0, 'credit' => $amounts['net'], 'description' => 'Revenue', 'reference' => $docNo];
            if ($amounts['vat'] > 0) {
                $lines[] = ['account_id' => $roles['accounts']['VAT_OUTPUT']['id'], 'debit' => 0, 'credit' => $amounts['vat'], 'description' => 'VAT', 'reference' => $docNo];
            }
        }

        $jr = create_and_post_journal(
            $glCompanyId,
            $isCredit ? 'credit_note' : 'invoice',
            'ars_financial_document',
            $documentId,
            $lines,
            'ARS ' . $documentType . ' ' . $docNo,
            $documentDate,
            $userId
        );
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];
        $conn->prepare('UPDATE ars_financial_documents SET journal_id=? WHERE id=? AND company_id=?')
            ->execute([$journalId, $documentId, $companyId]);
        ars_fin_doc_transition($conn, $companyId, $documentId, 'posted', $userId, 'posted');

        if ($documentType === 'extension_invoice' && !empty($opts['extension_meta'])) {
            $m = $opts['extension_meta'];
            $conn->prepare("
                INSERT INTO ars_extension_documents (document_id, company_id, prior_check_out, new_check_out, added_nights, rate_basis)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $documentId, $companyId, $m['prior_check_out'] ?? null, $m['new_check_out'] ?? null,
                $m['added_nights'] ?? null, $m['rate_basis'] ?? null,
            ]);
        }
        if ($documentType === 'credit_note') {
            $conn->prepare("
                INSERT INTO ars_credit_notes (document_id, company_id, reason_code, applies_to_document_id)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $documentId, $companyId, $opts['reason_code'] ?? 'other', $opts['parent_document_id'] ?? null,
            ]);
            // Reduce parent allocated tracking: increase parent credit by reducing AR — parent balance_due decreases conceptually via open-item; for unpaid parent, reduce total? Per register: CN does not edit parent totals; track via AR. Optionally reduce parent balance if unpaid.
            if (!empty($opts['parent_document_id'])) {
                $p = $conn->prepare('SELECT * FROM ars_financial_documents WHERE id=? AND company_id=? FOR UPDATE');
                $p->execute([(int)$opts['parent_document_id'], $companyId]);
                $parent = $p->fetch(PDO::FETCH_ASSOC);
                if ($parent && (float)$parent['balance_due'] > 0) {
                    $reduce = min((float)$parent['balance_due'], $amounts['total']);
                    $newDue = round((float)$parent['balance_due'] - $reduce, 2);
                    // Treat CN as reducing open balance without changing amount_allocated
                    $conn->prepare('UPDATE ars_financial_documents SET balance_due=?, updated_at=NOW() WHERE id=? AND company_id=?')
                        ->execute([max(0, $newDue), (int)$parent['id'], $companyId]);
                }
            }
        }
        if ($documentType === 'adjustment_invoice') {
            $conn->prepare("
                INSERT INTO ars_adjustments (document_id, company_id, reason_code, applies_to_document_id)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $documentId, $companyId, $opts['reason_code'] ?? 'manual', $opts['parent_document_id'] ?? null,
            ]);
        }

        if ($ownTxn) {
            $conn->commit();
        }

        ars_adapter_log_activity($conn, [
            'company_id' => $companyId,
            'booking_id' => (int)$booking['id'],
            'event_category' => 'accounting',
            'event_type' => $documentType . '_posted',
            'title' => $docNo . ' posted',
            'related_entity_type' => 'ars_financial_document',
            'related_entity_id' => $documentId,
            'related_document_number' => $docNo,
            'related_journal_id' => $journalId,
            'created_by' => $userId,
            'source' => 'system',
            'dedupe_key' => $documentType . ':' . $documentId,
        ]);

        return ars_adapter_ok([
            'document_id' => $documentId,
            'journal_id' => $journalId,
            'document_number' => $docNo,
        ]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

function ars_adapter_create_extension_invoice_impl(PDO $conn, array $booking, array $opts = []): array {
    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    $companyId = $opsCompanyId; // ARS docs/rows stay on ops company
    $newOut = (string)($opts['new_check_out'] ?? '');
    $priorOut = (string)($opts['prior_check_out'] ?? $booking['check_out']);
    if ($newOut === '' || $newOut <= $priorOut) {
        return ars_adapter_fail('new_check_out must be after prior check_out', 'validation_failed');
    }
    $avail = ars_check_availability($conn, (int)$booking['unit_id'], $priorOut, $newOut, (int)$booking['id']);
    if (empty($avail['available'])) {
        $labels = array_map(static fn($c) => $c['label'] ?? 'conflict', $avail['conflicts'] ?? []);
        return ars_adapter_fail('Unit not available for extension: ' . implode(', ', $labels), 'validation_failed');
    }
    $added = (int)((strtotime($newOut) - strtotime($priorOut)) / 86400);
    if ($added <= 0) {
        return ars_adapter_fail('No nights added', 'validation_failed');
    }
    // The caller may price the added nights itself — the Extend tab bills each
    // logged period at the rate the office agreed for it, which is not always
    // the booking's own nightly rate.
    $rate = round((float)($opts['rate'] ?? 0), 2);
    if ($rate <= 0) {
        $rate = (float)(($booking['rate_override'] ?? null) ?: $booking['nightly_rate'] ?? 0);
    }
    if ($rate <= 0) {
        return ars_adapter_fail('Nightly rate missing', 'validation_failed');
    }
    $net = round($rate * $added, 2);
    $amounts = ars_adapter_vat_split($net, $booking);
    $lines = [[
        'line_type' => 'room',
        'description' => "Extension {$added} night(s)",
        'quantity' => $added,
        'unit_price' => $rate,
        'line_total' => $amounts['net'],
        'vat_amount' => $amounts['vat'],
        'account_role' => 'ROOM_REVENUE',
    ]];
    $r = ars_adapter_post_ar_invoice($conn, $booking, 'extension_invoice', $amounts, $lines, 'ROOM_REVENUE', array_merge($opts, [
        'idempotency_key' => $opts['idempotency_key'] ?? ('invoice:extension:' . (int)$booking['id'] . ':' . $newOut),
        'extension_meta' => [
            'prior_check_out' => $priorOut,
            'new_check_out' => $newOut,
            'added_nights' => $added,
            'rate_basis' => (string)$rate,
        ],
    ]));
    // The Extend tab owns the booking's dates itself (the log is the record of
    // how long the guest stayed, and billing an older period must not pull the
    // check-out back), so it opts out and re-syncs afterwards.
    $moveDates = !array_key_exists('update_booking_dates', $opts) || !empty($opts['update_booking_dates']);
    if (!empty($r['success']) && empty($r['replay']) && $moveDates) {
        $nights = (int)$booking['nights'] + $added;
        $conn->prepare('UPDATE ars_bookings SET check_out=?, nights=?, updated_at=NOW() WHERE id=? AND company_id=?')
            ->execute([$newOut, $nights, (int)$booking['id'], $companyId]);
    }
    return $r;
}

function ars_adapter_create_service_invoice_impl(PDO $conn, array $booking, array $opts = []): array {
    $net = round((float)($opts['amount_net'] ?? $opts['amount'] ?? 0), 2);
    if ($net <= 0) {
        return ars_adapter_fail('Service amount required', 'validation_failed');
    }
    $lineType = (string)($opts['line_type'] ?? 'service');
    $role = $lineType === 'damage' ? 'DAMAGE_REVENUE' : 'ADDITIONAL_SERVICE_REVENUE';
    $amounts = ars_adapter_vat_split($net, $booking);
    $desc = (string)($opts['description'] ?? ($lineType === 'damage' ? 'Damage charge' : 'Additional service'));
    $lines = [[
        'line_type' => $lineType,
        'description' => $desc,
        'quantity' => (float)($opts['quantity'] ?? 1),
        'unit_price' => $net,
        'line_total' => $amounts['net'],
        'vat_amount' => $amounts['vat'],
        'account_role' => $role,
        'related_charge_id' => $opts['charge_id'] ?? null,
    ]];
    $r = ars_adapter_post_ar_invoice($conn, $booking, 'service_invoice', $amounts, $lines, $role, array_merge($opts, [
        'idempotency_key' => $opts['idempotency_key'] ?? ('invoice:service:' . (int)$booking['id'] . ':' . md5($desc . $net)),
    ]));
    if (!empty($r['success']) && !empty($opts['apply_deposit']) && $lineType === 'damage') {
        $apply = min($amounts['total'], (float)($opts['deposit_apply_amount'] ?? $amounts['total']));
        if ($apply > 0) {
            $dep = ars_adapter_apply_deposit_to_ar($conn, $booking, $apply, array_merge($opts, [
                'document_id' => $r['document_id'],
                'idempotency_key' => 'deposit:apply:' . (int)$booking['id'] . ':' . (int)$r['document_id'],
            ]));
            $r['deposit_apply'] = $dep;
        }
    }
    return $r;
}

function ars_adapter_create_adjustment_invoice_impl(PDO $conn, array $booking, array $opts = []): array {
    $net = round((float)($opts['amount_net'] ?? 0), 2);
    if ($net <= 0) {
        return ars_adapter_fail('Adjustment net amount required', 'validation_failed');
    }
    $amounts = ars_adapter_vat_split($net, $booking);
    $role = (string)($opts['account_role'] ?? 'ROOM_REVENUE');
    $lines = [[
        'line_type' => 'adjustment',
        'description' => (string)($opts['description'] ?? 'Price adjustment'),
        'quantity' => 1,
        'unit_price' => $amounts['net'],
        'line_total' => $amounts['net'],
        'vat_amount' => $amounts['vat'],
        'account_role' => $role,
    ]];
    return ars_adapter_post_ar_invoice($conn, $booking, 'adjustment_invoice', $amounts, $lines, $role, $opts);
}

function ars_adapter_create_credit_note_impl(PDO $conn, array $booking, array $opts = []): array {
    $net = round((float)($opts['amount_net'] ?? 0), 2);
    if ($net <= 0) {
        return ars_adapter_fail('Credit note net amount required', 'validation_failed');
    }
    $amounts = ars_adapter_vat_split($net, $booking);
    $role = (string)($opts['account_role'] ?? 'ROOM_REVENUE');
    $lines = [[
        'line_type' => 'credit',
        'description' => (string)($opts['description'] ?? 'Credit note'),
        'quantity' => 1,
        'unit_price' => $amounts['net'],
        'line_total' => $amounts['net'],
        'vat_amount' => $amounts['vat'],
        'account_role' => $role,
    ]];
    $r = ars_adapter_post_ar_invoice($conn, $booking, 'credit_note', $amounts, $lines, $role, $opts);
    // Default: create guest credit for CN total when invoice already paid / opts say so
    if (!empty($r['success']) && !empty($opts['to_guest_credit']) && empty($r['replay'])) {
        $gc = ars_adapter_create_guest_credit($conn, [
            'company_id' => (int)$booking['company_id'],
            'guest_id' => (int)$booking['guest_id'],
            'booking_id' => (int)$booking['id'],
            'credit_note_document_id' => $r['document_id'],
            'source_type' => 'credit_note',
            'amount' => $amounts['total'],
            'post_reclass' => true,
            'idempotency_key' => 'credit:from_cn:' . (int)$r['document_id'],
            'user_id' => $opts['user_id'] ?? null,
        ]);
        $r['guest_credit'] = $gc;
    }
    return $r;
}

function ars_adapter_apply_deposit_to_ar(PDO $conn, array $booking, float $amount, array $opts = []): array {
    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    $companyId = $opsCompanyId; // ARS docs/rows stay on ops company
    $amount = round($amount, 2);
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $roles = ars_require_account_roles($conn, $glCompanyId, ['SECURITY_DEPOSIT', 'AR_GUEST']);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }
    $idem = (string)($opts['idempotency_key'] ?? ('deposit:to_ar:' . (int)$booking['id'] . ':' . $amount));
    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $year = date('Y');
        $cnt = $conn->prepare('SELECT COUNT(*)+1 FROM ars_security_deposits WHERE company_id=? AND YEAR(created_at)=YEAR(NOW())');
        $cnt->execute([$companyId]);
        $depNo = 'ARS-DEP-' . $year . '-' . str_pad((string)$cnt->fetchColumn(), 5, '0', STR_PAD_LEFT);
        $conn->prepare("
            INSERT INTO ars_security_deposits (company_id, booking_id, deposit_number, event_type, amount, status, idempotency_key, created_by, notes)
            VALUES (?, ?, ?, 'forfeit', ?, 'draft', ?, ?, 'apply_to_ar')
        ")->execute([$companyId, (int)$booking['id'], $depNo, $amount, $idem, $userId]);
        $depositId = (int)$conn->lastInsertId();
        $jr = create_and_post_journal(
            $glCompanyId, 'deposit', 'ars_security_deposit', $depositId,
            [
                ['account_id' => $roles['accounts']['SECURITY_DEPOSIT']['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Deposit applied to AR', 'reference' => $depNo],
                ['account_id' => $roles['accounts']['AR_GUEST']['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'AR cleared via deposit', 'reference' => $depNo],
            ],
            'ARS Deposit Apply to AR', date('Y-m-d'), $userId
        );
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];
        $conn->prepare("UPDATE ars_security_deposits SET status='posted', journal_id=?, posted_at=NOW() WHERE id=?")
            ->execute([$journalId, $depositId]);
        if (!empty($opts['document_id'])) {
            $docId = (int)$opts['document_id'];
            $d = $conn->prepare('SELECT * FROM ars_financial_documents WHERE id=? AND company_id=? FOR UPDATE');
            $d->execute([$docId, $companyId]);
            $doc = $d->fetch(PDO::FETCH_ASSOC);
            if ($doc) {
                $apply = min($amount, (float)$doc['balance_due']);
                $newAlloc = round((float)$doc['amount_allocated'] + $apply, 2);
                $newDue = round((float)$doc['total_amount'] - $newAlloc, 2);
                $st = ars_fin_doc_status_from_balances((float)$doc['total_amount'], $newAlloc);
                $conn->prepare('UPDATE ars_financial_documents SET amount_allocated=?, balance_due=?, status=? WHERE id=?')
                    ->execute([$newAlloc, max(0, $newDue), $st, $docId]);
            }
        }
        if ($ownTxn) {
            $conn->commit();
        }
        return ars_adapter_ok(['deposit_id' => $depositId, 'journal_id' => $journalId]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

function ars_adapter_forfeit_deposit_impl(PDO $conn, array $booking, array $opts = []): array {
    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    $companyId = $opsCompanyId; // ARS docs/rows stay on ops company
    $amount = round((float)($opts['amount'] ?? 0), 2);
    $reason = trim((string)($opts['reason'] ?? ''));
    if ($amount <= 0 || $reason === '') {
        return ars_adapter_fail('Forfeit amount and reason required', 'validation_failed');
    }
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $roles = ars_require_account_roles($conn, $glCompanyId, ['SECURITY_DEPOSIT', 'FORFEIT_REVENUE']);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }
    $idem = (string)($opts['idempotency_key'] ?? ('deposit:forfeit:' . (int)$booking['id'] . ':' . $amount . ':' . md5($reason)));
    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $year = date('Y');
        $cnt = $conn->prepare('SELECT COUNT(*)+1 FROM ars_security_deposits WHERE company_id=? AND YEAR(created_at)=YEAR(NOW())');
        $cnt->execute([$companyId]);
        $depNo = 'ARS-DEP-' . $year . '-' . str_pad((string)$cnt->fetchColumn(), 5, '0', STR_PAD_LEFT);
        $conn->prepare("
            INSERT INTO ars_security_deposits (company_id, booking_id, deposit_number, event_type, amount, status, idempotency_key, notes, created_by)
            VALUES (?, ?, ?, 'forfeit', ?, 'draft', ?, ?, ?)
        ")->execute([$companyId, (int)$booking['id'], $depNo, $amount, $idem, $reason, $userId]);
        $depositId = (int)$conn->lastInsertId();
        $jr = create_and_post_journal(
            $glCompanyId, 'deposit', 'ars_security_deposit', $depositId,
            [
                ['account_id' => $roles['accounts']['SECURITY_DEPOSIT']['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Deposit forfeit', 'reference' => $depNo],
                ['account_id' => $roles['accounts']['FORFEIT_REVENUE']['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Forfeit income: ' . $reason, 'reference' => $depNo],
            ],
            'ARS Deposit Forfeit', date('Y-m-d'), $userId
        );
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];
        $conn->prepare("UPDATE ars_security_deposits SET status='posted', journal_id=?, posted_at=NOW() WHERE id=?")
            ->execute([$journalId, $depositId]);
        if ($ownTxn) {
            $conn->commit();
        }
        ars_adapter_log_activity($conn, [
            'company_id' => $companyId,
            'booking_id' => (int)$booking['id'],
            'event_category' => 'payment',
            'event_type' => 'deposit_forfeited',
            'title' => 'Deposit forfeited ' . $depNo,
            'related_entity_type' => 'ars_security_deposit',
            'related_entity_id' => $depositId,
            'related_journal_id' => $journalId,
            'created_by' => $userId,
            'source' => 'system',
            'dedupe_key' => $idem,
        ]);
        return ars_adapter_ok(['deposit_id' => $depositId, 'journal_id' => $journalId]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

function ars_adapter_create_refund_impl(PDO $conn, array $booking, array $opts = []): array {
    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    $companyId = $opsCompanyId; // ARS docs/rows stay on ops company
    $amount = round((float)($opts['amount'] ?? 0), 2);
    $method = (string)($opts['method'] ?? 'cash');
    $source = (string)($opts['source'] ?? 'manual');
    if ($amount <= 0) {
        return ars_adapter_fail('Refund amount required', 'validation_failed');
    }
    if ($source === 'guest_credit' && !empty($opts['credit_id'])) {
        return ars_adapter_refund_guest_credit($conn, $companyId, (int)$opts['credit_id'], $amount, $method, $opts);
    }
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $cashRole = ars_cash_or_bank_role($method);
    // Stay refund reduces AR (or revenue if source credit_note already reduced AR) — default DR AR CR cash reverses a payment
    $roles = ars_require_account_roles($conn, $glCompanyId, ['AR_GUEST', $cashRole]);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }
    $idem = (string)($opts['idempotency_key'] ?? ('refund:stay:' . (int)$booking['id'] . ':' . $amount . ':' . $source));
    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $year = date('Y');
        $cnt = $conn->prepare('SELECT COUNT(*)+1 FROM ars_refunds WHERE company_id=? AND YEAR(created_at)=YEAR(NOW())');
        $cnt->execute([$companyId]);
        $refNo = 'ARS-REF-' . $year . '-' . str_pad((string)$cnt->fetchColumn(), 5, '0', STR_PAD_LEFT);
        $conn->prepare("
            INSERT INTO ars_refunds (company_id, booking_id, refund_number, payment_id, credit_note_document_id, amount, method, status, idempotency_key, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)
        ")->execute([
            $companyId, (int)$booking['id'], $refNo, $opts['payment_id'] ?? null, $opts['credit_note_document_id'] ?? null,
            $amount, $method, $idem, $source, $userId,
        ]);
        $refundId = (int)$conn->lastInsertId();
        $jr = create_and_post_journal(
            $glCompanyId, 'refund', 'ars_refund', $refundId,
            [
                ['account_id' => $roles['accounts']['AR_GUEST']['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Stay refund AR', 'reference' => $refNo],
                ['account_id' => $roles['accounts'][$cashRole]['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Refund paid', 'reference' => $refNo],
            ],
            'ARS Stay Refund', date('Y-m-d'), $userId
        );
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];
        $conn->prepare("UPDATE ars_refunds SET status='posted', journal_id=?, posted_at=NOW() WHERE id=?")
            ->execute([$journalId, $refundId]);
        if ($ownTxn) {
            $conn->commit();
        }
        ars_adapter_log_activity($conn, [
            'company_id' => $companyId,
            'booking_id' => (int)$booking['id'],
            'event_category' => 'payment',
            'event_type' => 'refund_completed',
            'title' => 'Refund ' . $refNo,
            'related_entity_type' => 'ars_refund',
            'related_entity_id' => $refundId,
            'related_journal_id' => $journalId,
            'created_by' => $userId,
            'source' => 'system',
            'dedupe_key' => $idem,
        ]);
        return ars_adapter_ok(['refund_id' => $refundId, 'journal_id' => $journalId]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

function ars_adapter_shorten_booking_impl(PDO $conn, array $booking, array $opts = []): array {
    $policy = ars_financial_policy($conn, (int)$booking['company_id']);
    $newOut = (string)($opts['new_check_out'] ?? '');
    $priorOut = (string)$booking['check_out'];
    if ($newOut === '' || $newOut >= $priorOut || $newOut <= $booking['check_in']) {
        return ars_adapter_fail('Invalid early check_out', 'validation_failed');
    }
    if (empty($policy['early_checkout_refundable'])) {
        $conn->prepare('UPDATE ars_bookings SET check_out=?, nights=DATEDIFF(?, check_in), updated_at=NOW() WHERE id=? AND company_id=?')
            ->execute([$newOut, $newOut, (int)$booking['id'], (int)$booking['company_id']]);
        return ars_adapter_ok(['dates_updated' => true, 'credit_note' => null, 'note' => 'Non-refundable policy']);
    }
    $removed = (int)((strtotime($priorOut) - strtotime($newOut)) / 86400);
    $rate = (float)(($booking['rate_override'] ?? null) ?: $booking['nightly_rate'] ?? 0);
    $net = round($rate * $removed, 2);
    $parentId = $booking['primary_invoice_document_id'] ?? null;
    $cn = ars_adapter_create_credit_note_impl($conn, $booking, [
        'amount_net' => $net,
        'description' => "Early checkout unused {$removed} night(s)",
        'parent_document_id' => $parentId,
        'reason_code' => 'early_checkout',
        'to_guest_credit' => empty($opts['refund_method']),
        'account_role' => 'ROOM_REVENUE',
        'user_id' => $opts['user_id'] ?? null,
        'idempotency_key' => $opts['idempotency_key'] ?? ('cn:early:' . (int)$booking['id'] . ':' . $newOut),
    ]);
    if (!empty($cn['success'])) {
        $conn->prepare('UPDATE ars_bookings SET check_out=?, nights=DATEDIFF(?, check_in), updated_at=NOW() WHERE id=? AND company_id=?')
            ->execute([$newOut, $newOut, (int)$booking['id'], (int)$booking['company_id']]);
        if (!empty($opts['refund_method']) && !empty($cn['guest_credit']['credit_id'])) {
            ars_adapter_refund_guest_credit($conn, (int)$booking['company_id'], (int)$cn['guest_credit']['credit_id'], $net, (string)$opts['refund_method'], $opts);
        }
    }
    return $cn;
}

function ars_adapter_no_show_impl(PDO $conn, array $booking, array $opts = []): array {
    $policy = ars_financial_policy($conn, (int)$booking['company_id']);
    $mode = (string)($policy['no_show_fee_mode'] ?? 'keep_revenue');
    if ($mode === 'reverse_like_cancel') {
        return ars_adapter_cancel_financials_impl($conn, $booking, array_merge($opts, ['reason' => 'no_show']));
    }
    $conn->prepare("UPDATE ars_bookings SET status='cancelled', cancellation_reason=?, cancelled_at=NOW(), updated_at=NOW() WHERE id=? AND company_id=?")
        ->execute(['no_show', (int)$booking['id'], (int)$booking['company_id']]);
    ars_adapter_log_activity($conn, [
        'company_id' => (int)$booking['company_id'],
        'booking_id' => (int)$booking['id'],
        'event_category' => 'operational',
        'event_type' => 'no_show_recorded',
        'title' => 'No-show — revenue retained per policy',
        'created_by' => $opts['user_id'] ?? null,
        'source' => 'system',
        'dedupe_key' => 'no_show:' . (int)$booking['id'],
    ]);
    return ars_adapter_ok(['mode' => 'keep_revenue', 'status' => 'cancelled']);
}

function ars_adapter_cancel_financials_impl(PDO $conn, array $booking, array $opts = []): array {
    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    $companyId = $opsCompanyId; // ARS docs/rows stay on ops company
    $policy = ars_financial_policy($conn, $companyId);
    $feePct = (float)($policy['cancellation_fee_percent'] ?? 0);
    $results = [];
    if ($feePct > 0) {
        $base = (float)($booking['total_amount'] ?? 0);
        $feeNet = round($base * $feePct / 100 / (1 + ((float)($booking['vat_rate'] ?? 5) / 100)), 2);
        // simpler: fee on net subtotal
        $feeNet = round(((float)$booking['subtotal']) * $feePct / 100, 2);
        if ($feeNet > 0) {
            $results['fee'] = ars_adapter_create_adjustment_invoice_impl($conn, $booking, [
                'amount_net' => $feeNet,
                'description' => 'Cancellation fee ' . $feePct . '%',
                'account_role' => 'LATE_FEE_REVENUE',
                'reason_code' => 'cancellation_fee',
                'user_id' => $opts['user_id'] ?? null,
                'idempotency_key' => 'adj:cancel_fee:' . (int)$booking['id'],
            ]);
        }
    }
    $docs = $conn->prepare("
        SELECT id FROM ars_financial_documents
        WHERE company_id=? AND booking_id=? AND document_type IN ('original_invoice','extension_invoice')
          AND status IN ('posted','partially_paid','paid') AND reversal_journal_id IS NULL
    ");
    $docs->execute([$companyId, (int)$booking['id']]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $docId) {
        $results['reverse_' . $docId] = ars_adapter_reverse_document($conn, $companyId, (int)$docId, (string)($opts['reason'] ?? 'cancelled'), $opts['user_id'] ?? null);
    }
    return ars_adapter_ok(['results' => $results]);
}

function ars_adapter_stripe_card_payment_impl(PDO $conn, array $payment, array $booking, array $opts = []): array {
    // Force clearing role for stripe/card gateway
    $payment = array_merge($payment, ['payment_method' => 'card']);
    $opts['cash_role_override'] = 'STRIPE_CLEARING';
    return ars_adapter_record_payment_with_role($conn, $payment, $booking, $opts);
}

function ars_adapter_record_payment_with_role(PDO $conn, array $payment, array $booking, array $opts = []): array {
    // Temporarily patch method path by setting a synthetic method handled below via opts
    $opts['__role'] = $opts['cash_role_override'] ?? null;
    if (empty($opts['__role'])) {
        return ars_adapter_record_payment($conn, $payment, $booking, $opts);
    }
    // Duplicate core of record_payment with role override — call internal by mutating payment_method mapping
    // Use bank_transfer trick won't work; implement inline thin wrapper:
    $opsCompanyId = ars_adapter_ops_company_id($booking);
    $glCompanyId = ars_adapter_posting_company_id($conn, $booking);
    $companyId = $opsCompanyId;
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }
    if (!empty($payment['journal_id'])) {
        return ars_adapter_ok(['code' => 'idempotent_replay', 'journal_id' => (int)$payment['journal_id'], 'replay' => true]);
    }
    $role = (string)$opts['__role'];
    $roles = ars_require_account_roles($conn, $glCompanyId, [$role, 'AR_GUEST']);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }
    $amount = round((float)$payment['amount'], 2);
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $paymentId = (int)$payment['id'];
    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $jr = create_and_post_journal(
            $glCompanyId, 'payment', 'ars_payment', $paymentId,
            [
                ['account_id' => $roles['accounts'][$role]['id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Stripe clearing', 'reference' => $booking['booking_number']],
                ['account_id' => $roles['accounts']['AR_GUEST']['id'], 'debit' => 0, 'credit' => $amount, 'description' => 'AR settlement', 'reference' => $booking['booking_number']],
            ],
            'ARS Stripe Payment', $payment['payment_date'] ?? date('Y-m-d'), $userId
        );
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];
        $conn->prepare('UPDATE ars_booking_payments SET journal_id=? WHERE id=? AND company_id=?')
            ->execute([$journalId, $paymentId, $companyId]);
        $alloc = ars_adapter_allocate_payment($conn, [
            'company_id' => $companyId,
            'booking_id' => (int)$booking['id'],
            'payment_id' => $paymentId,
            'amount' => $amount,
            'allocation_date' => $payment['payment_date'] ?? date('Y-m-d'),
            'journal_id' => $journalId,
            'idempotency_key' => 'alloc:payment:' . $paymentId,
            'user_id' => $userId,
            'guest_id' => (int)($booking['guest_id'] ?? 0),
        ], ['nested' => true]);
        if ($ownTxn) {
            $conn->commit();
        }
        return ars_adapter_ok(['journal_id' => $journalId, 'allocation_ids' => $alloc['allocation_ids'] ?? [], 'unallocated' => $alloc['unallocated'] ?? 0, 'credit_id' => $alloc['credit_id'] ?? null]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}

function ars_adapter_stripe_settlement_impl(PDO $conn, array $opts = []): array {
    $companyId = (int)($opts['company_id'] ?? 0);
    if ($err = ars_adapter_assert_company($companyId)) {
        return $err;
    }
    $glCompanyId = ars_financial_gl_company_id($conn, $companyId);
    if ($err = ars_adapter_assert_company($glCompanyId)) {
        return ars_adapter_fail('Missing financial (GL) company_id — fail closed', 'company_mismatch');
    }
    $paymentIds = $opts['payment_ids'] ?? [];
    if (!$paymentIds) {
        return ars_adapter_fail('payment_ids required', 'validation_failed');
    }
    $policy = ars_financial_policy($conn, $companyId);
    $feePct = (float)($policy['stripe_fee_percent'] ?? 0);
    $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $idem = (string)($opts['idempotency_key'] ?? ('stripe:settle:' . md5(json_encode($paymentIds))));

    $roles = ars_require_account_roles($conn, $glCompanyId, ['STRIPE_CLEARING', 'BANK', 'STRIPE_FEE']);
    if (!$roles['success']) {
        return ars_adapter_fail((string)$roles['error'], (string)$roles['code']);
    }

    $ownTxn = !$conn->inTransaction();
    try {
        if ($ownTxn) {
            $conn->beginTransaction();
        }
        $gross = 0.0;
        foreach ($paymentIds as $pid) {
            $p = $conn->prepare('SELECT amount FROM ars_booking_payments WHERE id=? AND company_id=?');
            $p->execute([(int)$pid, $companyId]);
            $gross += (float)$p->fetchColumn();
        }
        $gross = round($gross, 2);
        $fee = round($gross * $feePct / 100, 2);
        $net = round($gross - $fee, 2);
        $year = date('Y');
        $cnt = $conn->prepare('SELECT COUNT(*)+1 FROM ars_stripe_settlements WHERE company_id=? AND YEAR(created_at)=YEAR(NOW())');
        $cnt->execute([$companyId]);
        $num = 'ARS-SET-' . $year . '-' . str_pad((string)$cnt->fetchColumn(), 5, '0', STR_PAD_LEFT);
        $conn->prepare("
            INSERT INTO ars_stripe_settlements (company_id, settlement_number, settlement_date, gross_amount, fee_amount, net_amount, status, idempotency_key, created_by)
            VALUES (?, ?, CURDATE(), ?, ?, ?, 'draft', ?, ?)
        ")->execute([$companyId, $num, $gross, $fee, $net, $idem, $userId]);
        $setId = (int)$conn->lastInsertId();
        foreach ($paymentIds as $pid) {
            $amt = $conn->prepare('SELECT amount FROM ars_booking_payments WHERE id=?');
            $amt->execute([(int)$pid]);
            $a = (float)$amt->fetchColumn();
            $conn->prepare('INSERT INTO ars_stripe_settlement_lines (company_id, settlement_id, payment_id, amount) VALUES (?,?,?,?)')
                ->execute([$companyId, $setId, (int)$pid, $a]);
        }
        $jlines = [
            ['account_id' => $roles['accounts']['BANK']['id'], 'debit' => $net, 'credit' => 0, 'description' => 'Stripe net deposit', 'reference' => $num],
            ['account_id' => $roles['accounts']['STRIPE_CLEARING']['id'], 'debit' => 0, 'credit' => $gross, 'description' => 'Clear Stripe clearing', 'reference' => $num],
        ];
        if ($fee > 0) {
            array_splice($jlines, 1, 0, [[
                'account_id' => $roles['accounts']['STRIPE_FEE']['id'], 'debit' => $fee, 'credit' => 0, 'description' => 'Stripe fee', 'reference' => $num,
            ]]);
        }
        $jr = create_and_post_journal($companyId, 'payment', 'ars_stripe_settlement', $setId, $jlines, 'ARS Stripe Settlement', date('Y-m-d'), $userId);
        if (empty($jr['success'])) {
            if ($ownTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ars_adapter_fail($jr['error'] ?? 'journal failed', 'journal_failed');
        }
        $journalId = (int)$jr['journal_id'];
        $conn->prepare("UPDATE ars_stripe_settlements SET status='posted', journal_id=?, posted_at=NOW() WHERE id=?")
            ->execute([$journalId, $setId]);
        if ($ownTxn) {
            $conn->commit();
        }
        return ars_adapter_ok(['settlement_id' => $setId, 'journal_id' => $journalId, 'gross' => $gross, 'fee' => $fee, 'net' => $net]);
    } catch (Throwable $e) {
        if ($ownTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ars_adapter_fail($e->getMessage(), 'journal_failed');
    }
}
