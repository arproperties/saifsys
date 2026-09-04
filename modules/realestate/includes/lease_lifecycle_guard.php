<?php
/**
 * Guardrails for lease deletion, restoration, and status changes.
 */

require_once __DIR__ . '/../../../includes/AuditService.php';

function re_lease_column_exists(PDO $conn, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 're_leases'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$column]);
        $cache[$column] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function re_lease_not_deleted_sql(PDO $conn, string $alias = 'l'): string
{
    return re_lease_column_exists($conn, 'deleted_at') ? "{$alias}.deleted_at IS NULL" : "1=1";
}

function re_lease_deleted_sql(PDO $conn, string $alias = 'l'): string
{
    return re_lease_column_exists($conn, 'deleted_at') ? "{$alias}.deleted_at IS NOT NULL" : "1=0";
}

function re_lease_lifecycle_audit(string $action, int $leaseId, array $oldData, array $newData = [], string $summary = ''): void
{
    $companyId = null;
    if (!empty($oldData['company_id'])) {
        $companyId = (int)$oldData['company_id'];
    } elseif (!empty($newData['company_id'])) {
        $companyId = (int)$newData['company_id'];
    }
    $ref = !empty($oldData['lease_number'])
        ? (string)$oldData['lease_number']
        : (!empty($newData['lease_number']) ? (string)$newData['lease_number'] : ('Lease #' . $leaseId));

    AuditService::logEvent([
        'action' => $action,
        'module' => 'realestate',
        'company_id' => $companyId,
        'object_type' => 're_leases',
        'object_id' => (string)$leaseId,
        'object_ref' => $ref,
        'summary' => $summary !== '' ? $summary : (AuditService::actionLabel($action) . ' ' . $ref),
        'old_data' => $oldData ?: null,
        'new_data' => $newData ?: null,
        'success' => true,
    ]);
}

function re_lease_load_for_lifecycle(PDO $conn, int $companyId, int $leaseId, bool $includeDeleted = false): ?array
{
    $where = "id = ? AND company_id = ?";
    $params = [$leaseId, $companyId];
    if (!$includeDeleted && re_lease_column_exists($conn, 'deleted_at')) {
        $where .= " AND deleted_at IS NULL";
    }

    $stmt = $conn->prepare("SELECT * FROM re_leases WHERE {$where} LIMIT 1");
    $stmt->execute($params);
    $lease = $stmt->fetch(PDO::FETCH_ASSOC);

    return $lease ?: null;
}

function re_lease_linked_renewal_workflow(PDO $conn, int $companyId, int $leaseId): ?array
{
    $stmt = $conn->prepare("
        SELECT rw.id, rw.status, rw.lease_id, rw.new_lease_id
        FROM re_lease_renewal_workflows rw
        JOIN re_leases old_l ON old_l.id = rw.lease_id
        WHERE old_l.company_id = ?
          AND (rw.lease_id = ? OR rw.new_lease_id = ?)
        ORDER BY rw.id DESC
        LIMIT 1
    ");
    $stmt->execute([$companyId, $leaseId, $leaseId]);
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);

    return $workflow ?: null;
}

function re_lease_soft_delete(PDO $conn, int $companyId, int $leaseId, int $userId, string $reason = ''): array
{
    if (!re_lease_column_exists($conn, 'deleted_at')) {
        throw new RuntimeException('Soft-delete columns are missing. Run migrations/realestate_lease_lifecycle_safeguards.sql first.');
    }

    $lease = re_lease_load_for_lifecycle($conn, $companyId, $leaseId);
    if (!$lease) {
        throw new RuntimeException('Lease not found.');
    }
    if (($lease['status'] ?? '') !== 'draft') {
        throw new RuntimeException('Only draft leases can be archived.');
    }

    $workflow = re_lease_linked_renewal_workflow($conn, $companyId, $leaseId);
    if ($workflow) {
        throw new RuntimeException('This draft lease belongs to Renewal Workflow #' . (int)$workflow['id'] . '. Use the workflow page to cancel/recreate it.');
    }

    $conn->prepare("
        UPDATE re_leases
        SET deleted_at = NOW(),
            deleted_by = ?,
            delete_reason = ?,
            restored_at = NULL,
            restored_by = NULL,
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND status = 'draft' AND deleted_at IS NULL
    ")->execute([$userId ?: null, $reason ?: 'Archived from leases list', $leaseId, $companyId]);

    $updated = re_lease_load_for_lifecycle($conn, $companyId, $leaseId, true) ?: [];
    re_lease_lifecycle_audit('soft_delete', $leaseId, $lease, $updated, 'Archived draft lease instead of hard deletion.');

    return $updated;
}

function re_lease_restore(PDO $conn, int $companyId, int $leaseId, int $userId): array
{
    if (!re_lease_column_exists($conn, 'deleted_at')) {
        throw new RuntimeException('Soft-delete columns are missing. Run migrations/realestate_lease_lifecycle_safeguards.sql first.');
    }

    $lease = re_lease_load_for_lifecycle($conn, $companyId, $leaseId, true);
    if (!$lease || empty($lease['deleted_at'])) {
        throw new RuntimeException('Deleted lease not found.');
    }

    $conn->prepare("
        UPDATE re_leases
        SET deleted_at = NULL,
            delete_reason = NULL,
            restored_at = NOW(),
            restored_by = ?,
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND deleted_at IS NOT NULL
    ")->execute([$userId ?: null, $leaseId, $companyId]);

    $updated = re_lease_load_for_lifecycle($conn, $companyId, $leaseId, true) ?: [];
    re_lease_lifecycle_audit('restore', $leaseId, $lease, $updated, 'Restored archived draft lease.');

    return $updated;
}

/**
 * Permanently (hard) delete a lease and EVERY record tied to it, including
 * accounting (journals, GL, sub-ledgers, postings), payments, allocations,
 * cheques, installments, invoices, billing, legal links, documents, etc.
 *
 * Owner-only — the caller MUST verify has_role('Owner', $conn) before calling.
 * Runs inside a single transaction with FK checks disabled so deletion order
 * never blocks; the table list below is intentionally exhaustive so no orphan
 * rows can survive. Missing tables/columns are skipped gracefully.
 *
 * @return array<string,int> Map of table => rows deleted (for reporting).
 */
function re_lease_hard_delete(PDO $conn, int $companyId, int $leaseId, int $userId): array
{
    $lease = re_lease_load_for_lifecycle($conn, $companyId, $leaseId, true);
    if (!$lease) {
        throw new RuntimeException('Lease not found.');
    }

    $report = [];
    $del = function (string $sql, array $params, string $label) use ($conn, &$report): void {
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $report[$label] = ($report[$label] ?? 0) + $stmt->rowCount();
        } catch (Throwable $e) {
            // Optional/absent table or column — safe to skip for a clean delete.
        }
    };
    $ids = function (string $sql, array $params) use ($conn): array {
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return array_map('intval', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn($v) => $v !== null));
        } catch (Throwable $e) {
            return [];
        }
    };
    $inClause = function (array $list): string {
        return implode(',', array_fill(0, count($list), '?'));
    };

    // ---- Collect source-document ids the accounting layer references ----
    $paymentIds     = $ids("SELECT id FROM re_payments WHERE lease_id = ?", [$leaseId]);
    $invoiceIds     = $ids("SELECT id FROM re_invoices WHERE lease_id = ?", [$leaseId]);
    $installmentIds = $ids("SELECT id FROM re_lease_installments WHERE lease_id = ? AND company_id = ?", [$leaseId, $companyId]);

    // Journal headers: by direct lease reference, by payment, by invoice, by deferred payment, + recognition journals.
    $journalIds = [];
    $journalIds = array_merge($journalIds, $ids("SELECT id FROM re_journal_headers WHERE reference_type = 'lease' AND reference_id = ? AND company_id = ?", [$leaseId, $companyId]));
    if ($paymentIds) {
        $journalIds = array_merge($journalIds, $ids("SELECT id FROM re_journal_headers WHERE reference_type IN ('payment','deferred_payment') AND company_id = ? AND reference_id IN (" . $inClause($paymentIds) . ")", array_merge([$companyId], $paymentIds)));
    }
    if ($invoiceIds) {
        $journalIds = array_merge($journalIds, $ids("SELECT id FROM re_journal_headers WHERE reference_type = 'invoice' AND company_id = ? AND reference_id IN (" . $inClause($invoiceIds) . ")", array_merge([$companyId], $invoiceIds)));
    }
    $journalIds = array_merge($journalIds, $ids("SELECT recognition_journal_id FROM re_rent_recognition_schedule WHERE lease_id = ? AND recognition_journal_id IS NOT NULL", [$leaseId]));
    $journalIds = array_values(array_unique(array_filter($journalIds)));

    $conn->beginTransaction();
    try {
        $conn->exec('SET FOREIGN_KEY_CHECKS = 0');

        // ---- Accounting: ledgers first, then lines, then headers ----
        if ($journalIds) {
            $jc = $inClause($journalIds);
            $del("DELETE FROM re_general_ledger WHERE journal_id IN ($jc)", $journalIds, 're_general_ledger');
            $del("DELETE FROM re_account_ledger_entries WHERE journal_id IN ($jc)", $journalIds, 're_account_ledger_entries');
            $del("DELETE FROM re_accounting_postings WHERE journal_id IN ($jc)", $journalIds, 're_accounting_postings');
            $del("DELETE FROM re_journal_lines WHERE journal_id IN ($jc)", $journalIds, 're_journal_lines');
            $del("DELETE FROM re_journal_headers WHERE id IN ($jc)", $journalIds, 're_journal_headers');
        }
        // Postings referenced by source document, in case any lacked a journal id.
        if ($paymentIds) {
            $del("DELETE FROM re_accounting_postings WHERE company_id = ? AND source_type IN ('payment','deferred_payment') AND source_id IN (" . $inClause($paymentIds) . ")", array_merge([$companyId], $paymentIds), 're_accounting_postings');
        }
        $del("DELETE FROM re_accounting_postings WHERE company_id = ? AND source_type = 'lease' AND source_id = ?", [$companyId, $leaseId], 're_accounting_postings');

        // ---- Payment-linked rows ----
        if ($paymentIds) {
            $pc = $inClause($paymentIds);
            $del("DELETE FROM re_payment_allocations WHERE payment_id IN ($pc)", $paymentIds, 're_payment_allocations');
            $del("DELETE FROM re_tenant_credit_transactions WHERE payment_id IN ($pc)", $paymentIds, 're_tenant_credit_transactions');
        }
        if ($installmentIds) {
            $ic = $inClause($installmentIds);
            $del("DELETE FROM re_payment_allocations WHERE installment_id IN ($ic)", $installmentIds, 're_payment_allocations');
            $del("DELETE FROM re_tenant_credit_transactions WHERE installment_id IN ($ic)", $installmentIds, 're_tenant_credit_transactions');
        }

        // ---- Invoices ----
        if ($invoiceIds) {
            $del("DELETE FROM re_invoice_items WHERE invoice_id IN (" . $inClause($invoiceIds) . ")", $invoiceIds, 're_invoice_items');
        }
        $del("DELETE FROM re_invoices WHERE lease_id = ?", [$leaseId], 're_invoices');

        // ---- Payments (after their journals/allocations are gone) ----
        $del("DELETE FROM re_payments WHERE lease_id = ?", [$leaseId], 're_payments');

        // ---- Cheques, billing, recognition, installments ----
        $del("DELETE FROM re_bounced_cheque_alerts WHERE lease_id = ?", [$leaseId], 're_bounced_cheque_alerts');
        $del("DELETE FROM re_legal_cheque_escalations WHERE lease_id = ?", [$leaseId], 're_legal_cheque_escalations');
        $del("DELETE FROM re_post_dated_cheques WHERE lease_id = ?", [$leaseId], 're_post_dated_cheques');
        $del("DELETE FROM re_lease_cheques WHERE lease_id = ?", [$leaseId], 're_lease_cheques');
        $del("DELETE FROM re_billing_items WHERE lease_id = ?", [$leaseId], 're_billing_items');
        $del("DELETE FROM re_rent_recognition_schedule WHERE lease_id = ?", [$leaseId], 're_rent_recognition_schedule');
        $del("DELETE FROM re_lease_installments WHERE lease_id = ?", [$leaseId], 're_lease_installments');

        // ---- Lease child / operational tables ----
        foreach ([
            're_lease_units', 're_lease_expiry_reminders', 're_lease_renewal_workflows',
            're_move_ins', 're_move_outs', 're_move_operations', 're_move_out_notices',
            're_service_charges', 're_ejari_tracking', 're_meter_readings',
            're_overdue_rent_alerts', 're_upcoming_due_alerts', 're_payment_notifications',
            're_compliance_status', 're_tenant_issues_violations', 're_tenant_payment_behavior',
            're_tenant_unit_history', 're_tenant_notifications',
            'tenant_cleaning_requests', 'tenant_pest_control_requests',
            'tenant_extra_service_requests', 'tenant_portal_verification_codes',
        ] as $tbl) {
            $del("DELETE FROM {$tbl} WHERE lease_id = ?", [$leaseId], $tbl);
        }
        // Renewal workflows may also point forward via new_lease_id.
        $del("DELETE FROM re_lease_renewal_workflows WHERE new_lease_id = ?", [$leaseId], 're_lease_renewal_workflows');

        // ---- Maintenance: keep history but detach from the lease ----
        $del("UPDATE re_maintenance_requests SET lease_id = NULL WHERE lease_id = ?", [$leaseId], 're_maintenance_requests(detached)');

        // ---- Legal cases / notices / polymorphic links / documents / tasks ----
        $del("DELETE FROM re_legal_case_links WHERE link_type = 'lease' AND link_id = ?", [$leaseId], 're_legal_case_links');
        $del("DELETE FROM re_legal_notices WHERE lease_id = ?", [$leaseId], 're_legal_notices');
        $del("DELETE FROM re_legal_cases WHERE lease_id = ? AND company_id = ?", [$leaseId, $companyId], 're_legal_cases');
        $del("DELETE FROM re_documents WHERE related_type = 'lease' AND related_id = ?", [$leaseId], 're_documents');
        $del("DELETE FROM re_missing_documents WHERE related_type = 'lease' AND related_id = ?", [$leaseId], 're_missing_documents');
        $del("DELETE FROM re_tasks WHERE related_type = 'lease' AND related_id = ?", [$leaseId], 're_tasks');

        // ---- The lease itself ----
        $del("DELETE FROM re_leases WHERE id = ? AND company_id = ?", [$leaseId, $companyId], 're_leases');

        // ---- Free the unit if no other active lease remains ----
        if (!empty($lease['unit_id'])) {
            try {
                $conn->prepare("
                    UPDATE re_units u
                    SET u.status = 'vacant', u.updated_at = NOW()
                    WHERE u.id = ? AND u.company_id = ?
                      AND NOT EXISTS (
                        SELECT 1 FROM re_leases ol
                        WHERE ol.unit_id = u.id AND ol.company_id = u.company_id
                          AND ol.status = 'active'
                      )
                ")->execute([(int)$lease['unit_id'], $companyId]);
            } catch (Throwable $e) { /* unit status optional */ }
        }

        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
        re_lease_lifecycle_audit('hard_delete', $leaseId, $lease, [], 'Permanently deleted lease and all related records (Owner clean delete).');
        $conn->commit();
        return $report;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        try { $conn->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e2) {}
        throw $e;
    }
}

if (!function_exists('re_lease_status_label')) {
    function re_lease_status_label(string $status): string
    {
        $labels = [
            'draft' => 'Draft',
            'active' => 'Active',
            'expired' => 'Expired',
            'terminated' => 'Terminated',
            'renewed' => 'Renewed',
            'has_legal_case' => 'Has Legal Case',
        ];
        return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('re_lease_valid_statuses')) {
    /** @return list<string> */
    function re_lease_valid_statuses(): array
    {
        return ['draft', 'active', 'expired', 'terminated', 'renewed', 'has_legal_case'];
    }
}

/**
 * Statuses that occupy a unit and block a new lease booking.
 * @return list<string>
 */
if (!function_exists('re_lease_occupying_statuses')) {
    function re_lease_occupying_statuses(): array
    {
        return ['active'];
    }
}

function re_lease_allowed_status_transitions(array $lease): array
{
    $status = (string)($lease['status'] ?? '');
    $map = [
        'draft' => ['active'],
        // has_legal_case: frees unit for rebooking; keep lease for legal/AR follow-up
        'active' => ['expired', 'has_legal_case'],
        'expired' => [],
        'renewed' => [],
        'terminated' => [],
        'has_legal_case' => [],
    ];

    return $map[$status] ?? [];
}

function re_lease_change_status_guarded(PDO $conn, int $companyId, int $leaseId, string $newStatus, int $userId): array
{
    $validStatuses = re_lease_valid_statuses();
    if (!in_array($newStatus, $validStatuses, true)) {
        throw new RuntimeException('Invalid status.');
    }
    if ($newStatus === 'terminated') {
        throw new RuntimeException('Use the Terminate Lease workflow so termination date, returned cheques, and revenue recognition are handled correctly.');
    }
    if ($newStatus === 'renewed') {
        throw new RuntimeException('Use the Renewal Workflow to mark a lease renewed/converted.');
    }

    $lease = re_lease_load_for_lifecycle($conn, $companyId, $leaseId);
    if (!$lease) {
        throw new RuntimeException('Lease not found.');
    }
    if (($lease['status'] ?? '') === $newStatus) {
        return $lease;
    }

    $allowed = re_lease_allowed_status_transitions($lease);
    if (!in_array($newStatus, $allowed, true)) {
        throw new RuntimeException('Status change from "' . ($lease['status'] ?? '') . '" to "' . $newStatus . '" is not allowed.');
    }

    if (!empty($lease['is_renewal_lease']) && $newStatus === 'active') {
        $workflow = re_lease_linked_renewal_workflow($conn, $companyId, $leaseId);
        if ($workflow) {
            throw new RuntimeException('Renewal draft leases must be activated from the Renewal Workflow Approve & Convert action.');
        }
    }

    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE re_leases SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
            ->execute([$newStatus, $leaseId, $companyId]);

        if ($newStatus === 'active') {
            $conn->prepare("
                UPDATE re_units u
                JOIN re_leases l ON l.unit_id = u.id
                SET u.status = 'occupied', u.updated_at = NOW()
                WHERE l.id = ? AND l.company_id = ?
            ")->execute([$leaseId, $companyId]);
        }

        if (($lease['status'] ?? '') === 'active' && $newStatus !== 'active') {
            $conn->prepare("
                UPDATE re_units u
                JOIN re_leases l ON l.unit_id = u.id
                SET u.status = 'vacant', u.updated_at = NOW()
                WHERE l.id = ?
                  AND l.company_id = ?
                  AND NOT EXISTS (
                    SELECT 1 FROM re_leases other_l
                    WHERE other_l.unit_id = l.unit_id
                      AND other_l.company_id = l.company_id
                      AND other_l.id <> l.id
                      AND other_l.status = 'active'
                      AND " . re_lease_not_deleted_sql($conn, 'other_l') . "
                  )
            ")->execute([$leaseId, $companyId]);
        }

        $updated = re_lease_load_for_lifecycle($conn, $companyId, $leaseId) ?: [];
        re_lease_lifecycle_audit('status_change', $leaseId, $lease, $updated, 'Lease status changed from ' . ($lease['status'] ?? '') . ' to ' . $newStatus . '.');
        $conn->commit();

        return $updated;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function re_lease_activate_due_renewals(PDO $conn, int $companyId, ?int $userId = null): array
{
    $result = ['activated' => 0, 'workflow_ids' => [], 'errors' => []];

    try {
        $stmt = $conn->prepare("
            SELECT rw.id
            FROM re_lease_renewal_workflows rw
            JOIN re_leases nl ON nl.id = rw.new_lease_id
            JOIN re_leases ol ON ol.id = rw.lease_id
            WHERE ol.company_id = ?
              AND nl.company_id = ol.company_id
              AND rw.status = 'converted'
              AND nl.status = 'draft'
              AND nl.start_date <= CURDATE()
              AND " . re_lease_not_deleted_sql($conn, 'nl') . "
              AND " . re_lease_not_deleted_sql($conn, 'ol') . "
            ORDER BY nl.start_date ASC, rw.id ASC
            LIMIT 50
        ");
        $stmt->execute([$companyId]);
        $workflowIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        foreach ($workflowIds as $workflowId) {
            try {
                $conn->beginTransaction();

                $lock = $conn->prepare("
                    SELECT rw.id, rw.lease_id, rw.new_lease_id,
                           ol.status AS old_status, ol.end_date AS old_end_date, ol.unit_id AS old_unit_id,
                           nl.status AS new_status, nl.start_date AS new_start_date, nl.unit_id AS new_unit_id
                    FROM re_lease_renewal_workflows rw
                    JOIN re_leases ol ON ol.id = rw.lease_id AND ol.company_id = ?
                    JOIN re_leases nl ON nl.id = rw.new_lease_id AND nl.company_id = ol.company_id
                    WHERE rw.id = ?
                    FOR UPDATE
                ");
                $lock->execute([$companyId, $workflowId]);
                $row = $lock->fetch(PDO::FETCH_ASSOC);

                if (!$row || ($row['new_status'] ?? '') !== 'draft' || empty($row['new_start_date']) || $row['new_start_date'] > date('Y-m-d')) {
                    $conn->commit();
                    continue;
                }

                $oldLeaseId = (int)$row['lease_id'];
                $newLeaseId = (int)$row['new_lease_id'];

                $conn->prepare("UPDATE re_leases SET status = 'active', updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'draft'")
                    ->execute([$newLeaseId, $companyId]);

                if (($row['old_status'] ?? '') === 'active') {
                    $conn->prepare("
                        UPDATE re_leases
                        SET status = 'expired',
                            move_out_date = COALESCE(move_out_date, end_date),
                            updated_at = NOW()
                        WHERE id = ? AND company_id = ? AND status = 'active'
                    ")->execute([$oldLeaseId, $companyId]);
                }

                $conn->prepare("
                    UPDATE re_units
                    SET status = 'occupied', updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ")->execute([(int)$row['new_unit_id'], $companyId]);

                re_lease_lifecycle_audit(
                    'renewal_auto_activation',
                    $newLeaseId,
                    ['status' => $row['new_status'], 'workflow_id' => $workflowId, 'old_lease_id' => $oldLeaseId],
                    ['status' => 'active', 'old_lease_status' => 'expired'],
                    'Due renewal lease activated on start date; previous lease expired.'
                );

                $conn->commit();
                $result['activated']++;
                $result['workflow_ids'][] = $workflowId;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $result['errors'][] = 'Workflow #' . $workflowId . ': ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }

    if (!empty($result['errors'])) {
        error_log('Due renewal activation warning: ' . implode(' | ', array_slice($result['errors'], 0, 5)));
    }

    return $result;
}
