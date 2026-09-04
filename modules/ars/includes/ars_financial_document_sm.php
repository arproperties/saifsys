<?php
/**
 * ARS Financial Document State Machine
 *
 * Lifecycle (Phase 2B):
 * Draft → Validated → Posted → Partially Paid → Paid → Closed
 * Optional: Cancelled, Voided, Reversed, Refunded
 *
 * No document may skip required transitions.
 */

/** @return list<string> */
function ars_fin_doc_statuses(): array {
    return [
        'draft',
        'validated',
        'posted',
        'partially_paid',
        'paid',
        'closed',
        'cancelled',
        'voided',
        'reversed',
        'refunded',
    ];
}

/**
 * Allowed directed transitions: from => list of to.
 * @return array<string,list<string>>
 */
function ars_fin_doc_allowed_transitions(): array {
    return [
        'draft' => ['validated', 'cancelled', 'voided'],
        'validated' => ['posted', 'cancelled', 'voided'],
        'posted' => ['partially_paid', 'paid', 'voided', 'reversed', 'refunded', 'closed'],
        'partially_paid' => ['paid', 'partially_paid', 'refunded', 'reversed', 'closed'],
        'paid' => ['refunded', 'closed', 'reversed'],
        'closed' => [],
        'cancelled' => [],
        'voided' => [],
        'reversed' => [],
        'refunded' => ['closed'],
    ];
}

/**
 * Permission action keys (checked via ars_require_booking_action / ars_user_has_* when available).
 * @return array<string,string>
 */
function ars_fin_doc_transition_permissions(): array {
    return [
        'draft->validated' => 'booking_edit',
        'validated->posted' => 'booking_confirm',
        'posted->partially_paid' => 'booking_payment',
        'posted->paid' => 'booking_payment',
        'partially_paid->paid' => 'booking_payment',
        'partially_paid->partially_paid' => 'booking_payment',
        'posted->closed' => 'booking_edit',
        'paid->closed' => 'booking_edit',
        'refunded->closed' => 'booking_edit',
        'draft->cancelled' => 'booking_cancel',
        'validated->cancelled' => 'booking_cancel',
        'draft->voided' => 'booking_cancel',
        'validated->voided' => 'booking_cancel',
        'posted->voided' => 'booking_cancel',
        'posted->reversed' => 'booking_cancel',
        'partially_paid->reversed' => 'booking_cancel',
        'paid->reversed' => 'booking_cancel',
        'posted->refunded' => 'booking_payment',
        'partially_paid->refunded' => 'booking_payment',
        'paid->refunded' => 'booking_payment',
    ];
}

/**
 * @return array{allowed:bool,error:?string,code:?string,permission:?string}
 */
function ars_fin_doc_can_transition(?string $from, string $to): array {
    $from = $from === null || $from === '' ? 'draft' : strtolower($from);
    $to = strtolower($to);

    if (!in_array($to, ars_fin_doc_statuses(), true)) {
        return [
            'allowed' => false,
            'error' => 'Unknown target status: ' . $to,
            'code' => 'validation_failed',
            'permission' => null,
        ];
    }

    $map = ars_fin_doc_allowed_transitions();
    $allowed = $map[$from] ?? [];
    if (!in_array($to, $allowed, true)) {
        return [
            'allowed' => false,
            'error' => "Transition not allowed: {$from} → {$to}",
            'code' => 'validation_failed',
            'permission' => null,
        ];
    }

    $permKey = $from . '->' . $to;
    $perms = ars_fin_doc_transition_permissions();

    return [
        'allowed' => true,
        'error' => null,
        'code' => null,
        'permission' => $perms[$permKey] ?? 'booking_edit',
    ];
}

/**
 * Financial lock behaviour by status.
 * @return array{locked:bool,allows_amendment_docs:bool,note:string}
 */
function ars_fin_doc_lock_behaviour(string $status): array {
    $status = strtolower($status);
    return match ($status) {
        'draft', 'validated', 'cancelled', 'voided' => [
            'locked' => false,
            'allows_amendment_docs' => false,
            'note' => 'Pre-post or terminal non-posted; booking lock driven by booking ops status',
        ],
        'posted', 'partially_paid', 'paid', 'refunded' => [
            'locked' => true,
            'allows_amendment_docs' => true,
            'note' => 'Posted financial document; amendments emit new documents only',
        ],
        'closed', 'reversed' => [
            'locked' => true,
            'allows_amendment_docs' => false,
            'note' => 'Terminal posted lifecycle',
        ],
        default => [
            'locked' => true,
            'allows_amendment_docs' => false,
            'note' => 'Unknown status — fail closed',
        ],
    };
}

/**
 * Whether a journal is required when entering $to from $from.
 */
function ars_fin_doc_journal_required(?string $from, string $to): bool {
    $from = $from === null || $from === '' ? 'draft' : strtolower($from);
    $to = strtolower($to);
    if ($from === 'validated' && $to === 'posted') {
        return true;
    }
    if (in_array($to, ['reversed'], true) && in_array($from, ['posted', 'partially_paid', 'paid'], true)) {
        return true;
    }
    return false;
}

/**
 * Activity Center event_type for a transition.
 */
function ars_fin_doc_activity_event(?string $from, string $to): string {
    $to = strtolower($to);
    return match ($to) {
        'validated' => 'document_validated',
        'posted' => 'invoice_created',
        'partially_paid' => 'payment_allocated',
        'paid' => 'document_paid',
        'closed' => 'document_closed',
        'cancelled' => 'document_cancelled',
        'voided' => 'document_voided',
        'reversed' => 'document_reversed',
        'refunded' => 'document_refunded',
        default => 'document_status_changed',
    };
}

/**
 * Apply status transition + audit row. Caller owns outer transaction.
 *
 * @return array{success:bool,error:?string,code:?string}
 */
function ars_fin_doc_transition(
    PDO $conn,
    int $companyId,
    int $documentId,
    string $toStatus,
    ?int $userId = null,
    ?string $note = null
): array {
    if ($companyId <= 0 || $documentId <= 0) {
        return ['success' => false, 'error' => 'company_id and document_id required', 'code' => 'company_mismatch'];
    }

    $stmt = $conn->prepare("
        SELECT id, company_id, status FROM ars_financial_documents
        WHERE id = ? AND company_id = ?
        LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$documentId, $companyId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        return ['success' => false, 'error' => 'Document not found for company', 'code' => 'company_mismatch'];
    }

    $from = (string) $doc['status'];
    $check = ars_fin_doc_can_transition($from, $toStatus);
    if (!$check['allowed']) {
        return ['success' => false, 'error' => $check['error'], 'code' => $check['code']];
    }

    $sets = ['status = ?', 'updated_at = NOW()'];
    $params = [strtolower($toStatus)];

    if (strtolower($toStatus) === 'validated') {
        $sets[] = 'validated_at = NOW()';
        $sets[] = 'validated_by = ?';
        $params[] = $userId;
    }
    if (strtolower($toStatus) === 'posted') {
        $sets[] = 'posted_at = NOW()';
        $sets[] = 'posted_by = ?';
        $params[] = $userId;
    }
    if (strtolower($toStatus) === 'closed') {
        $sets[] = 'closed_at = NOW()';
    }

    $params[] = $documentId;
    $params[] = $companyId;
    $sql = 'UPDATE ars_financial_documents SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ?';
    $conn->prepare($sql)->execute($params);

    $conn->prepare("
        INSERT INTO ars_financial_document_transitions
            (company_id, document_id, from_status, to_status, changed_by, note)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $companyId,
        $documentId,
        $from,
        strtolower($toStatus),
        $userId,
        $note,
    ]);

    return ['success' => true, 'error' => null, 'code' => null];
}

/**
 * Derive payment status from balances (posted | partially_paid | paid).
 */
function ars_fin_doc_status_from_balances(float $total, float $allocated): string {
    $total = round($total, 2);
    $allocated = round($allocated, 2);
    if ($allocated <= 0.00001) {
        return 'posted';
    }
    if ($allocated + 0.00001 >= $total) {
        return 'paid';
    }
    return 'partially_paid';
}
