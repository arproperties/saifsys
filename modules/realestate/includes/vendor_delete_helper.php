<?php
/**
 * Safe Real Estate vendor delete.
 *
 * Confirmed product rule (user request):
 * - Block if vendor has bills (re_vendor_invoices) or payments (re_vendor_payments).
 * - Allow delete when only Quick Paid Expenses exist: reverse posted journals, then remove QPE rows.
 * - Also remove non-AP vendor children that would block DELETE (agreements, performance, etc.).
 * - Block AMC contracts and inventory POs (financial history outside AP bills/payments).
 */

require_once __DIR__ . '/../../../includes/erp_expense_posting.php';

function re_vendor_table_exists(PDO $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $st = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $st->execute([$table]);
        $cache[$table] = ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function re_vendor_count(PDO $conn, string $sql, array $params): int
{
    $st = $conn->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * @return array{ok:bool,error?:string,can_delete?:bool,blockers?:list<string>,qpe_count?:int,vendor_name?:string}
 */
function re_vendor_delete_precheck(PDO $conn, int $companyId, int $vendorId): array
{
    if ($companyId <= 0) {
        return ['ok' => false, 'error' => 'Company context is required.', 'can_delete' => false];
    }
    if ($vendorId <= 0) {
        return ['ok' => false, 'error' => 'Vendor is required.', 'can_delete' => false];
    }

    $vs = $conn->prepare('SELECT id, vendor_name FROM re_vendors WHERE id = ? AND company_id = ? LIMIT 1');
    $vs->execute([$vendorId, $companyId]);
    $vendor = $vs->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) {
        return ['ok' => false, 'error' => 'Vendor not found.', 'can_delete' => false];
    }

    $blockers = [];
    $params = [$companyId, $vendorId];

    $invoiceCount = re_vendor_count(
        $conn,
        'SELECT COUNT(*) FROM re_vendor_invoices WHERE company_id = ? AND vendor_id = ?',
        $params
    );
    if ($invoiceCount > 0) {
        $blockers[] = $invoiceCount . ' vendor bill(s) / invoice(s)';
    }

    $paymentCount = re_vendor_count(
        $conn,
        'SELECT COUNT(*) FROM re_vendor_payments WHERE company_id = ? AND vendor_id = ?',
        $params
    );
    if ($paymentCount > 0) {
        $blockers[] = $paymentCount . ' vendor payment(s)';
    }

    if (re_vendor_table_exists($conn, 're_amc_contracts')) {
        $amcCount = re_vendor_count(
            $conn,
            'SELECT COUNT(*) FROM re_amc_contracts WHERE company_id = ? AND vendor_id = ?',
            $params
        );
        if ($amcCount > 0) {
            $blockers[] = $amcCount . ' AMC contract(s)';
        }
    }

    if (re_vendor_table_exists($conn, 'inv_purchase_orders')) {
        $poCount = re_vendor_count(
            $conn,
            'SELECT COUNT(*) FROM inv_purchase_orders WHERE company_id = ? AND vendor_id = ?',
            $params
        );
        if ($poCount > 0) {
            $blockers[] = $poCount . ' inventory purchase order(s)';
        }
    }

    $qpeCount = 0;
    if (re_vendor_table_exists($conn, 'erp_expense_headers')) {
        $qpeCount = re_vendor_count(
            $conn,
            "SELECT COUNT(*) FROM erp_expense_headers
             WHERE company_id = ? AND vendor_id = ? AND source_module = 'realestate'",
            $params
        );
    }

    if ($blockers) {
        return [
            'ok' => true,
            'can_delete' => false,
            'blockers' => $blockers,
            'qpe_count' => $qpeCount,
            'vendor_name' => (string)$vendor['vendor_name'],
            'error' => 'Cannot delete this vendor while financial records exist: ' . implode(', ', $blockers) . '. Set the vendor inactive instead, or reverse/void those documents first.',
        ];
    }

    return [
        'ok' => true,
        'can_delete' => true,
        'blockers' => [],
        'qpe_count' => $qpeCount,
        'vendor_name' => (string)$vendor['vendor_name'],
    ];
}

/**
 * Reverse posted QPE journals, then hard-delete QPE rows for the vendor.
 *
 * @return array{ok:bool,error?:string,cancelled?:int,deleted?:int}
 */
function re_vendor_delete_quick_paid_expenses(PDO $conn, int $companyId, int $vendorId, ?int $userId): array
{
    if (!re_vendor_table_exists($conn, 'erp_expense_headers')) {
        return ['ok' => true, 'cancelled' => 0, 'deleted' => 0];
    }

    $st = $conn->prepare("
        SELECT id, status, journal_id, expense_number
        FROM erp_expense_headers
        WHERE company_id = ? AND vendor_id = ? AND source_module = 'realestate'
        ORDER BY id ASC
    ");
    $st->execute([$companyId, $vendorId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cancelled = 0;
    $deleted = 0;

    foreach ($rows as $row) {
        $expenseId = (int)$row['id'];
        $hadJournal = ((string)($row['status'] ?? '') === 'posted') || ((int)($row['journal_id'] ?? 0) > 0);
        $res = erp_delete_expense($conn, $expenseId, $companyId, $userId, 'realestate');
        if (empty($res['success'])) {
            return [
                'ok' => false,
                'error' => 'Could not remove Quick Paid Expense '
                    . ($row['expense_number'] ?: ('#' . $expenseId))
                    . ': ' . ($res['error'] ?? 'unknown error'),
            ];
        }
        if ($hadJournal || !empty($res['reversed'])) {
            $cancelled++;
        }
        $deleted++;
    }

    return ['ok' => true, 'cancelled' => $cancelled, 'deleted' => $deleted];
}

/**
 * @return array{success:bool,error?:string,message?:string}
 */
function re_vendor_delete(PDO $conn, int $companyId, int $vendorId, ?int $userId = null): array
{
    $pre = re_vendor_delete_precheck($conn, $companyId, $vendorId);
    if (empty($pre['ok'])) {
        return ['success' => false, 'error' => $pre['error'] ?? 'Delete precheck failed.'];
    }
    if (empty($pre['can_delete'])) {
        return ['success' => false, 'error' => $pre['error'] ?? 'Vendor cannot be deleted.'];
    }

    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }

        // Lock vendor row
        $lock = $conn->prepare('SELECT id, vendor_name FROM re_vendors WHERE id = ? AND company_id = ? FOR UPDATE');
        $lock->execute([$vendorId, $companyId]);
        $vendor = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$vendor) {
            throw new RuntimeException('Vendor not found.');
        }

        // Re-check blockers inside transaction
        $pre2 = re_vendor_delete_precheck($conn, $companyId, $vendorId);
        if (empty($pre2['can_delete'])) {
            throw new RuntimeException($pre2['error'] ?? 'Vendor cannot be deleted.');
        }

        $qpe = re_vendor_delete_quick_paid_expenses($conn, $companyId, $vendorId, $userId);
        if (empty($qpe['ok'])) {
            throw new RuntimeException($qpe['error'] ?? 'Failed to remove Quick Paid Expenses.');
        }

        // Non-AP children that would otherwise block / orphan
        if (re_vendor_table_exists($conn, 're_service_agreements')) {
            $conn->prepare('DELETE FROM re_service_agreements WHERE company_id = ? AND vendor_id = ?')
                ->execute([$companyId, $vendorId]);
        }
        if (re_vendor_table_exists($conn, 're_vendor_performance')) {
            $conn->prepare('DELETE FROM re_vendor_performance WHERE company_id = ? AND vendor_id = ?')
                ->execute([$companyId, $vendorId]);
        }
        if (re_vendor_table_exists($conn, 're_vendor_recurring_bills')) {
            $conn->prepare('DELETE FROM re_vendor_recurring_bills WHERE company_id = ? AND vendor_id = ?')
                ->execute([$companyId, $vendorId]);
        }
        if (re_vendor_table_exists($conn, 're_vendor_advance_balances')) {
            $conn->prepare('DELETE FROM re_vendor_advance_balances WHERE company_id = ? AND vendor_id = ?')
                ->execute([$companyId, $vendorId]);
        }
        if (re_vendor_table_exists($conn, 're_vendor_documents')) {
            $conn->prepare('DELETE FROM re_vendor_documents WHERE company_id = ? AND vendor_id = ?')
                ->execute([$companyId, $vendorId]);
        }
        if (re_vendor_table_exists($conn, 're_maintenance_requests')) {
            $conn->prepare('UPDATE re_maintenance_requests SET vendor_id = NULL WHERE company_id = ? AND vendor_id = ?')
                ->execute([$companyId, $vendorId]);
        }

        $del = $conn->prepare('DELETE FROM re_vendors WHERE id = ? AND company_id = ?');
        $del->execute([$vendorId, $companyId]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Vendor could not be deleted.');
        }

        if ($ownTx) {
            $conn->commit();
        }

        $msg = 'Vendor “' . $vendor['vendor_name'] . '” deleted.';
        if (!empty($qpe['deleted'])) {
            $msg .= ' Removed ' . (int)$qpe['deleted'] . ' Quick Paid Expense record(s)';
            if (!empty($qpe['cancelled'])) {
                $msg .= ' (' . (int)$qpe['cancelled'] . ' journal(s) reversed)';
            }
            $msg .= '.';
        }

        $details = $msg
            . ' [vendor_id=' . $vendorId
            . '; qpe_deleted=' . (int)($qpe['deleted'] ?? 0)
            . '; qpe_journals_reversed=' . (int)($qpe['cancelled'] ?? 0)
            . ']';
        if (function_exists('accounting_audit_log')) {
            accounting_audit_log(
                $companyId,
                'delete_vendor',
                're_vendor',
                $vendorId,
                $userId,
                $details
            );
        }

        return ['success' => true, 'message' => $msg];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
