<?php
/**
 * Finalize work order → invoice + GL + lock (Admin/Accountant only).
 */

require_once __DIR__ . '/work_order_financial_guard.php';
require_once __DIR__ . '/ar_helpers.php';
require_once __DIR__ . '/service_accounting_service.php';

if (!function_exists('wo_finalize_work_order')) {
    /**
     * @return array{success:bool, invoice_id?:int, message:string}
     */
    function wo_finalize_work_order(PDO $conn, int $orderId, ?int $userId = null): array
    {
        if (!sm_user_can_finalize($conn)) {
            return ['success' => false, 'message' => 'Only Admin or Accountant can finalize and generate invoices.'];
        }

        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'Work order not found.'];
        }

        // Hybrid orders: header totals must match order_services before freeze/invoice
        wo_sync_order_totals_from_services($conn, $orderId);
        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'Work order not found.'];
        }

        [$can, $reason] = wo_can_finalize($conn, $order);
        if (!$can) {
            return ['success' => false, 'message' => $reason];
        }

        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $ownsTxn = !$conn->inTransaction();
        if ($ownsTxn) {
            $conn->beginTransaction();
        }

        try {
            $sub = round((float)($order['total'] ?? 0), 2);
            $vat = round((float)($order['vat_amount'] ?? 0), 2);
            $grand = round((float)($order['grand_total'] ?? ($sub + $vat)), 2);

            if (wo_column_exists($conn, 'frozen_subtotal')) {
                $conn->prepare("
                    UPDATE make_order
                    SET frozen_subtotal = ?,
                        frozen_vat_amount = ?,
                        frozen_grand_total = ?,
                        ops_status = 'completed'
                    WHERE id = ?
                ")->execute([$sub, $vat, $grand, $orderId]);
            }

            $invoiceId = ar_ensure_invoice_for_order($conn, $orderId, $userId);
            if (!$invoiceId) {
                throw new RuntimeException('Could not create or refresh invoice for this work order.');
            }

            $conn->prepare('UPDATE make_order SET invoice_id = ? WHERE id = ?')->execute([(int)$invoiceId, $orderId]);

            if (wo_column_exists($conn, 'is_finalized')) {
                $conn->prepare("
                    UPDATE make_order
                    SET is_finalized = 1,
                        finalized_at = NOW(),
                        finalized_by = ?,
                        status = IF(status = 'cancelled', status, 'invoiced'),
                        ops_status = 'completed'
                    WHERE id = ?
                ")->execute([$userId, $orderId]);
            } else {
                $conn->prepare("UPDATE make_order SET status = 'invoiced' WHERE id = ? AND status <> 'cancelled'")
                    ->execute([$orderId]);
            }

            $svc = new ServiceAccountingService($conn);
            $svc->invalidateFinancialCache();

            require_once __DIR__ . '/AuditService.php';
            AuditService::logUpdate('make_order', $orderId, $order, [
                'is_finalized' => 1,
                'invoice_id' => (int)$invoiceId,
                'frozen_grand_total' => $grand,
            ], "Finalized work order #{$orderId} — invoice generated and GL posted", $userId ? (int)$userId : null);

            if ($ownsTxn) {
                $conn->commit();
            }

            $invNo = $conn->prepare('SELECT invoice_no FROM invoices WHERE id = ?');
            $invNo->execute([(int)$invoiceId]);
            $no = (string)$invNo->fetchColumn();

            return [
                'success' => true,
                'invoice_id' => (int)$invoiceId,
                'message' => 'Finalized successfully. Invoice ' . ($no ?: '#' . $invoiceId) . ' created and posted.',
            ];
        } catch (Throwable $e) {
            if ($ownsTxn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
