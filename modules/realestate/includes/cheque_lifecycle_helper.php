<?php
/**
 * Phase 6 cheque lifecycle helper.
 *
 * Cheques are operational payment instruments. For Invoice Mode leases, cleared
 * money must be handled through receipts and allocations.
 */
declare(strict_types=1);

require_once __DIR__ . '/accounting_mode_helper.php';
require_once __DIR__ . '/billing_helper.php';
require_once __DIR__ . '/obligation_engine.php';

if (!function_exists('re_cheque_statuses')) {
    function re_cheque_statuses(): array
    {
        return [
            'draft',
            'pending',
            'collected',
            'hold',
            'held_by_finance',
            'deposited',
            'cleared',
            'bounced',
            'replaced',
            'cancelled',
            'returned',
            'legal_escalated',
        ];
    }
}

if (!function_exists('re_cheque_normalize_status')) {
    function re_cheque_normalize_status(?string $status): string
    {
        $status = (string)$status;
        if ($status === 'hold') {
            return 'held_by_finance';
        }
        return in_array($status, re_cheque_statuses(), true) ? $status : 'pending';
    }
}

if (!function_exists('re_cheque_allowed_next_statuses')) {
    function re_cheque_allowed_next_statuses(string $status, string $accountingMode = 'legacy'): array
    {
        $status = re_cheque_normalize_status($status);
        $map = [
            'draft' => ['collected', 'cancelled'],
            'pending' => ['collected', 'held_by_finance', 'deposited', 'bounced', 'cancelled', 'returned', 'replaced', 'legal_escalated'],
            'collected' => ['held_by_finance', 'deposited', 'bounced', 'cancelled', 'returned', 'replaced', 'legal_escalated'],
            'held_by_finance' => ['collected', 'deposited', 'bounced', 'cancelled', 'returned', 'replaced', 'legal_escalated'],
            'deposited' => ['cleared', 'bounced', 'returned', 'replaced', 'legal_escalated'],
            'cleared' => [],
            'bounced' => ['replaced', 'legal_escalated', 'cancelled'],
            'replaced' => ['legal_escalated'],
            'cancelled' => ['collected'],
            'returned' => ['collected'],
            'legal_escalated' => ['replaced'],
        ];
        $allowed = $map[$status] ?? [];
        if ($accountingMode === 'invoice') {
            // Invoice Mode clearing is performed by receipt allocation confirmation.
            $allowed = array_values(array_filter($allowed, static fn($next) => $next !== 'cleared'));
        }
        return $allowed;
    }
}

if (!function_exists('re_cheque_load')) {
    function re_cheque_load(PDO $conn, int $companyId, int $chequeId): ?array
    {
        $stmt = $conn->prepare("
            SELECT c.*, l.accounting_mode, l.tenant_id, l.unit_id
            FROM re_post_dated_cheques c
            JOIN re_leases l ON l.id = c.lease_id AND l.company_id = c.company_id
            WHERE c.id = ? AND c.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$chequeId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('re_cheque_log_lifecycle')) {
    function re_cheque_log_lifecycle(PDO $conn, int $companyId, int $chequeId, int $leaseId, ?string $oldStatus, string $newStatus, ?int $userId, string $reason, string $sourcePage, ?int $receiptId = null, ?int $replacementChequeId = null, string $accountingMode = 'legacy'): void
    {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_cheque_lifecycle_audit
                    (company_id, cheque_id, lease_id, old_status, new_status, changed_by, reason, source_page, related_receipt_id, replacement_cheque_id, accounting_mode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $chequeId,
                $leaseId,
                $oldStatus,
                $newStatus,
                $userId,
                $reason ?: null,
                $sourcePage,
                $receiptId,
                $replacementChequeId,
                $accountingMode === 'invoice' ? 'invoice' : 'legacy',
            ]);
        } catch (Throwable $e) {
            error_log('Cheque lifecycle audit failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('re_cheque_has_deposit_bank_account_column')) {
    function re_cheque_has_deposit_bank_account_column(PDO $conn): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $stmt = $conn->query("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 're_post_dated_cheques'
                  AND COLUMN_NAME = 'deposit_bank_account_id'
            ");
            $exists = ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('re_cheque_update_status')) {
    function re_cheque_update_status(PDO $conn, int $companyId, int $chequeId, string $newStatus, ?int $userId, string $reason = '', string $sourcePage = 'system', ?int $receiptId = null, ?int $replacementChequeId = null, bool $allowInvoiceClear = false, array $options = []): array
    {
        $cheque = re_cheque_load($conn, $companyId, $chequeId);
        if (!$cheque) {
            return ['success' => false, 'error' => 'Cheque not found.'];
        }
        $oldStatus = re_cheque_normalize_status($cheque['status'] ?? 'pending');
        $newStatus = re_cheque_normalize_status($newStatus);
        $accountingMode = re_accounting_normalize_mode((string)($cheque['accounting_mode'] ?? 'legacy'));

        if ($newStatus === 'cleared' && $accountingMode === 'invoice' && !$allowInvoiceClear) {
            return ['success' => false, 'error' => 'Invoice Mode cheques must be cleared through Receipt Allocation.'];
        }

        if ($oldStatus !== $newStatus && !in_array($newStatus, re_cheque_allowed_next_statuses($oldStatus, $accountingMode), true) && !($newStatus === 'cleared' && $allowInvoiceClear)) {
            return ['success' => false, 'error' => "Status change from {$oldStatus} to {$newStatus} is not allowed."];
        }

        $today = date('Y-m-d');
        $depositedDate = $cheque['deposited_date'] ?? null;
        $clearedDate = $cheque['cleared_date'] ?? null;
        $bouncedDate = $cheque['bounced_date'] ?? null;
        $bouncedReason = $cheque['bounced_reason'] ?? null;
        $bankName = $cheque['bank_name'] ?? null;
        $depositBankAccountId = isset($cheque['deposit_bank_account_id']) && (int)$cheque['deposit_bank_account_id'] > 0
            ? (int)$cheque['deposit_bank_account_id']
            : null;

        if ($newStatus === 'deposited' && empty($depositedDate)) {
            $depositedDate = $today;
        } elseif ($newStatus === 'cleared') {
            $clearedDate = $today;
            $bouncedDate = null;
            $bouncedReason = null;
        } elseif ($newStatus === 'bounced') {
            $bouncedDate = !empty($options['bounced_date']) ? (string)$options['bounced_date'] : $today;
            $clearedDate = null;
            $bouncedReason = $reason ?: ($bouncedReason ?: 'Marked bounced');
            if (array_key_exists('bank_name', $options)) {
                $bankCandidate = trim((string)$options['bank_name']);
                if ($bankCandidate !== '') {
                    $bankName = $bankCandidate;
                }
            }
            if (array_key_exists('deposit_bank_account_id', $options)) {
                $depositBankAccountId = (int)$options['deposit_bank_account_id'] > 0
                    ? (int)$options['deposit_bank_account_id']
                    : null;
            }
        } elseif (in_array($newStatus, ['cancelled', 'returned'], true)) {
            $depositedDate = null;
            $clearedDate = null;
            $bouncedDate = null;
            $bouncedReason = null;
        }

        $dbStatus = $newStatus === 'held_by_finance' ? 'held_by_finance' : $newStatus;
        $hasDepositBankCol = re_cheque_has_deposit_bank_account_column($conn);
        if ($hasDepositBankCol) {
            $stmt = $conn->prepare("
                UPDATE re_post_dated_cheques
                SET status = ?, deposited_date = ?, cleared_date = ?, bounced_date = ?,
                    bounced_reason = ?, bank_name = ?, deposit_bank_account_id = ?,
                    payment_id = COALESCE(?, payment_id), updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $dbStatus,
                $depositedDate ?: null,
                $clearedDate ?: null,
                $bouncedDate ?: null,
                $bouncedReason ?: null,
                $bankName ?: null,
                $newStatus === 'bounced' ? $depositBankAccountId : ($depositBankAccountId ?: ($cheque['deposit_bank_account_id'] ?? null)),
                $receiptId,
                $chequeId,
                $companyId,
            ]);
        } else {
            $stmt = $conn->prepare("
                UPDATE re_post_dated_cheques
                SET status = ?, deposited_date = ?, cleared_date = ?, bounced_date = ?,
                    bounced_reason = ?, bank_name = ?, payment_id = COALESCE(?, payment_id), updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$dbStatus, $depositedDate ?: null, $clearedDate ?: null, $bouncedDate ?: null, $bouncedReason ?: null, $bankName ?: null, $receiptId, $chequeId, $companyId]);
        }

        if (!empty($cheque['installment_id'])) {
            try {
                if ($hasDepositBankCol) {
                    $conn->prepare("
                        UPDATE re_lease_cheques
                        SET status = ?, deposited_date = ?, cleared_date = ?, bounced_date = ?,
                            bounced_reason = ?, bank_name = ?, deposit_bank_account_id = ?,
                            settlement_payment_id = COALESCE(?, settlement_payment_id), updated_at = NOW()
                        WHERE lease_id = ? AND installment_id = ?
                    ")->execute([
                        $dbStatus,
                        $depositedDate ?: null,
                        $clearedDate ?: null,
                        $bouncedDate ?: null,
                        $bouncedReason ?: null,
                        $bankName ?: null,
                        $newStatus === 'bounced' ? $depositBankAccountId : ($depositBankAccountId ?: null),
                        $receiptId,
                        $cheque['lease_id'],
                        $cheque['installment_id'],
                    ]);
                } else {
                    $conn->prepare("
                        UPDATE re_lease_cheques
                        SET status = ?, deposited_date = ?, cleared_date = ?, bounced_date = ?,
                            bounced_reason = ?, bank_name = ?, settlement_payment_id = COALESCE(?, settlement_payment_id), updated_at = NOW()
                        WHERE lease_id = ? AND installment_id = ?
                    ")->execute([$dbStatus, $depositedDate ?: null, $clearedDate ?: null, $bouncedDate ?: null, $bouncedReason ?: null, $bankName ?: null, $receiptId, $cheque['lease_id'], $cheque['installment_id']]);
                }
            } catch (Throwable $e) {
                // Mirror table may not have all Phase 6 columns yet on partially migrated installs.
            }
        }

        re_cheque_log_lifecycle($conn, $companyId, $chequeId, (int)$cheque['lease_id'], $oldStatus, $newStatus, $userId, $reason, $sourcePage, $receiptId, $replacementChequeId, $accountingMode);

        try {
            require_once __DIR__ . '/../../../includes/audit_bridge.php';
            $chequeRef = trim((string)($cheque['cheque_number'] ?? ''));
            if ($chequeRef === '') {
                $chequeRef = 'Cheque #' . $chequeId;
            }
            audit_bridge_re_ops(
                $conn,
                $companyId,
                'cheque_status_changed',
                're_post_dated_cheques',
                $chequeId,
                $chequeRef,
                'Changed cheque ' . $chequeRef . ' from ' . $oldStatus . ' to ' . $newStatus
                    . ($reason !== '' ? (' — ' . $reason) : ''),
                ['status' => $oldStatus],
                [
                    'status' => $newStatus,
                    'lease_id' => (int)$cheque['lease_id'],
                    'reason' => $reason,
                    'source_page' => $sourcePage,
                ],
                $userId
            );
        } catch (Throwable $e) {
            error_log('cheque_status audit: ' . $e->getMessage());
        }

        return ['success' => true, 'cheque' => $cheque, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'accounting_mode' => $accountingMode];
    }
}

if (!function_exists('re_cheque_create_bounced_penalty')) {
    function re_cheque_create_bounced_penalty(PDO $conn, int $companyId, array $cheque, ?int $userId = null): ?int
    {
        $installmentId = !empty($cheque['installment_id']) ? (int)$cheque['installment_id'] : null;
        if (!$installmentId) {
            return null;
        }
        $ruleStmt = $conn->prepare("SELECT * FROM re_penalty_rules WHERE company_id = ? AND penalty_type = 'bounced_cheque' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $ruleStmt->execute([$companyId]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            return null;
        }
        $exists = $conn->prepare("SELECT id FROM re_billing_items WHERE lease_id = ? AND installment_id = ? AND penalty_rule_id = ? AND company_id = ? LIMIT 1");
        $exists->execute([(int)$cheque['lease_id'], $installmentId, (int)$rule['id'], $companyId]);
        $existingId = $exists->fetchColumn();
        if ($existingId) {
            return (int)$existingId;
        }
        $billingItemId = create_penalty_billing_item(
            $conn,
            $companyId,
            (int)$cheque['lease_id'],
            $rule,
            (float)($cheque['cheque_amount'] ?? 0),
            0,
            date('Y-m-d'),
            $installmentId
        );
        if ($billingItemId && re_accounting_normalize_mode((string)($cheque['accounting_mode'] ?? 'legacy')) === 'invoice') {
            try {
                re_obligation_engine_generate_for_lease($conn, $companyId, (int)$cheque['lease_id'], $userId);
            } catch (Throwable $e) {
                error_log('Could not refresh obligations after bounced cheque penalty: ' . $e->getMessage());
            }
        }
        return $billingItemId;
    }
}

