<?php
/**
 * Safe Construction supplier delete (co_suppliers only).
 *
 * - Block if supplier has invoices or payments.
 * - Block advance VAT docs, advance refunds, or non-zero advance balance.
 * - Allow delete when only construction Quick Paid Expenses exist: erp_delete_expense then remove.
 * - Soft children: documents, recurring templates; unlink contractors; then DELETE supplier.
 *
 * Never touches re_vendors / re_vendor_* (Real Estate).
 */

require_once __DIR__ . '/../../../includes/erp_expense_posting.php';
require_once __DIR__ . '/construction_supplier_ap_helpers.php';

function co_supplier_delete_table_exists(PDO $conn, string $table): bool
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

function co_supplier_delete_count(PDO $conn, string $sql, array $params): int
{
    $st = $conn->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * @return array{ok:bool,error?:string,can_delete?:bool,blockers?:list<string>,qpe_count?:int,supplier_name?:string}
 */
function co_supplier_delete_precheck(PDO $conn, int $companyId, int $supplierId): array
{
    if ($companyId <= 0) {
        return ['ok' => false, 'error' => 'Company context is required.', 'can_delete' => false];
    }
    if ($supplierId <= 0) {
        return ['ok' => false, 'error' => 'Supplier is required.', 'can_delete' => false];
    }

    $ss = $conn->prepare('SELECT id, supplier_name FROM co_suppliers WHERE id = ? AND company_id = ? LIMIT 1');
    $ss->execute([$supplierId, $companyId]);
    $supplier = $ss->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        return ['ok' => false, 'error' => 'Supplier not found.', 'can_delete' => false];
    }

    $blockers = [];
    $params = [$companyId, $supplierId];

    if (co_supplier_delete_table_exists($conn, 'co_supplier_invoices')) {
        $invoiceCount = co_supplier_delete_count(
            $conn,
            'SELECT COUNT(*) FROM co_supplier_invoices WHERE company_id = ? AND supplier_id = ?',
            $params
        );
        if ($invoiceCount > 0) {
            $blockers[] = $invoiceCount . ' supplier invoice(s)';
        }
    }

    if (co_supplier_delete_table_exists($conn, 'co_supplier_payments')) {
        $paymentCount = co_supplier_delete_count(
            $conn,
            'SELECT COUNT(*) FROM co_supplier_payments WHERE company_id = ? AND supplier_id = ?',
            $params
        );
        if ($paymentCount > 0) {
            $blockers[] = $paymentCount . ' supplier payment(s)';
        }
    }

    if (co_supplier_delete_table_exists($conn, 'co_supplier_advance_vat_documents')) {
        $vatCount = co_supplier_delete_count(
            $conn,
            'SELECT COUNT(*) FROM co_supplier_advance_vat_documents WHERE company_id = ? AND supplier_id = ?',
            $params
        );
        if ($vatCount > 0) {
            $blockers[] = $vatCount . ' advance VAT document(s)';
        }
    }

    if (co_supplier_delete_table_exists($conn, 'co_supplier_advance_refunds')) {
        $refundCount = co_supplier_delete_count(
            $conn,
            'SELECT COUNT(*) FROM co_supplier_advance_refunds WHERE company_id = ? AND supplier_id = ?',
            $params
        );
        if ($refundCount > 0) {
            $blockers[] = $refundCount . ' advance refund(s)';
        }
    }

    if (co_supplier_delete_table_exists($conn, 'co_supplier_advance_balances')) {
        $bal = $conn->prepare('SELECT COALESCE(balance_aed, 0) FROM co_supplier_advance_balances WHERE company_id = ? AND supplier_id = ? LIMIT 1');
        $bal->execute($params);
        $balance = (float)($bal->fetchColumn() ?: 0);
        if (abs($balance) > 0.005) {
            $blockers[] = 'non-zero advance balance (' . number_format($balance, 2) . ' AED)';
        }
    }

    $qpeCount = 0;
    if (co_supplier_delete_table_exists($conn, 'erp_expense_headers')) {
        $qpeCount = co_supplier_delete_count(
            $conn,
            "SELECT COUNT(*) FROM erp_expense_headers
             WHERE company_id = ? AND co_supplier_id = ? AND source_module = 'construction'",
            $params
        );
    }

    if ($blockers) {
        return [
            'ok' => true,
            'can_delete' => false,
            'blockers' => $blockers,
            'qpe_count' => $qpeCount,
            'supplier_name' => (string)$supplier['supplier_name'],
            'error' => 'Cannot delete this supplier while financial records exist: '
                . implode(', ', $blockers)
                . '. Set the supplier inactive instead, or reverse/void those documents first.',
        ];
    }

    return [
        'ok' => true,
        'can_delete' => true,
        'blockers' => [],
        'qpe_count' => $qpeCount,
        'supplier_name' => (string)$supplier['supplier_name'],
    ];
}

/**
 * @return array{ok:bool,error?:string,cancelled?:int,deleted?:int}
 */
function co_supplier_delete_quick_paid_expenses(PDO $conn, int $companyId, int $supplierId, ?int $userId): array
{
    if (!co_supplier_delete_table_exists($conn, 'erp_expense_headers')) {
        return ['ok' => true, 'cancelled' => 0, 'deleted' => 0];
    }

    $st = $conn->prepare("
        SELECT id, status, journal_id, expense_number
        FROM erp_expense_headers
        WHERE company_id = ? AND co_supplier_id = ? AND source_module = 'construction'
        ORDER BY id ASC
    ");
    $st->execute([$companyId, $supplierId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cancelled = 0;
    $deleted = 0;

    foreach ($rows as $row) {
        $expenseId = (int)$row['id'];
        $hadJournal = ((string)($row['status'] ?? '') === 'posted') || ((int)($row['journal_id'] ?? 0) > 0);
        $res = erp_delete_expense($conn, $expenseId, $companyId, $userId, 'construction');
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
function co_supplier_delete(PDO $conn, int $companyId, int $supplierId, ?int $userId = null): array
{
    $pre = co_supplier_delete_precheck($conn, $companyId, $supplierId);
    if (empty($pre['ok'])) {
        return ['success' => false, 'error' => $pre['error'] ?? 'Delete precheck failed.'];
    }
    if (empty($pre['can_delete'])) {
        return ['success' => false, 'error' => $pre['error'] ?? 'Supplier cannot be deleted.'];
    }

    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }

        $lock = $conn->prepare('SELECT id, supplier_name FROM co_suppliers WHERE id = ? AND company_id = ? FOR UPDATE');
        $lock->execute([$supplierId, $companyId]);
        $supplier = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) {
            throw new RuntimeException('Supplier not found.');
        }

        $pre2 = co_supplier_delete_precheck($conn, $companyId, $supplierId);
        if (empty($pre2['can_delete'])) {
            throw new RuntimeException($pre2['error'] ?? 'Supplier cannot be deleted.');
        }

        $qpe = co_supplier_delete_quick_paid_expenses($conn, $companyId, $supplierId, $userId);
        if (empty($qpe['ok'])) {
            throw new RuntimeException($qpe['error'] ?? 'Failed to remove Quick Paid Expenses.');
        }

        if (co_supplier_delete_table_exists($conn, 'co_supplier_documents')) {
            $conn->prepare('DELETE FROM co_supplier_documents WHERE company_id = ? AND supplier_id = ?')
                ->execute([$companyId, $supplierId]);
        }
        if (co_supplier_delete_table_exists($conn, 'co_supplier_recurring_invoices')) {
            $conn->prepare('DELETE FROM co_supplier_recurring_invoices WHERE company_id = ? AND supplier_id = ?')
                ->execute([$companyId, $supplierId]);
        }
        if (co_supplier_delete_table_exists($conn, 'co_supplier_advance_balances')) {
            $conn->prepare('DELETE FROM co_supplier_advance_balances WHERE company_id = ? AND supplier_id = ?')
                ->execute([$companyId, $supplierId]);
        }
        if (co_supplier_delete_table_exists($conn, 'co_supplier_advance_applications')) {
            $conn->prepare('DELETE FROM co_supplier_advance_applications WHERE company_id = ? AND supplier_id = ?')
                ->execute([$companyId, $supplierId]);
        }
        if (co_supplier_delete_table_exists($conn, 'co_contractors')) {
            $conn->prepare('UPDATE co_contractors SET supplier_id = NULL WHERE company_id = ? AND supplier_id = ?')
                ->execute([$companyId, $supplierId]);
        }

        $del = $conn->prepare('DELETE FROM co_suppliers WHERE id = ? AND company_id = ?');
        $del->execute([$supplierId, $companyId]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Supplier could not be deleted.');
        }

        if ($ownTx) {
            $conn->commit();
        }

        $msg = 'Supplier “' . $supplier['supplier_name'] . '” deleted.';
        if (!empty($qpe['deleted'])) {
            $msg .= ' Removed ' . (int)$qpe['deleted'] . ' Quick Paid Expense record(s)';
            if (!empty($qpe['cancelled'])) {
                $msg .= ' (' . (int)$qpe['cancelled'] . ' journal(s) reversed)';
            }
            $msg .= '.';
        }

        $details = $msg
            . ' [supplier_id=' . $supplierId
            . '; qpe_deleted=' . (int)($qpe['deleted'] ?? 0)
            . '; qpe_journals_reversed=' . (int)($qpe['cancelled'] ?? 0)
            . ']';

        co_supplier_ap_audit(
            $conn,
            $companyId,
            $supplierId,
            null,
            null,
            'supplier_deleted',
            (string)$supplier['supplier_name'],
            null,
            null,
            $msg,
            $userId !== null ? (int)$userId : null,
            'supplier_delete',
            null
        );

        if (function_exists('accounting_audit_log')) {
            accounting_audit_log(
                $companyId,
                'delete_supplier',
                'co_supplier',
                $supplierId,
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
