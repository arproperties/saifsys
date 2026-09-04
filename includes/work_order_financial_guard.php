<?php
/**
 * Work order financial lock helpers (Service Management Phase 2).
 */

require_once __DIR__ . '/service_management_settings.php';

if (!function_exists('wo_column_exists')) {
    function wo_column_exists(PDO $conn, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        $st = $conn->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = ?
        ");
        $st->execute([$column]);
        return $cache[$column] = ((int)$st->fetchColumn() > 0);
    }
}

if (!function_exists('wo_should_auto_invoice')) {
    /** When true, legacy auto-invoice on save is still allowed. */
    function wo_should_auto_invoice(PDO $conn): bool
    {
        return !sm_defer_auto_invoice($conn);
    }
}

if (!function_exists('wo_invoice_from_operations_locked')) {
    /**
     * Invoices created from Operations work orders must not be edited directly after finalize.
     * Use Adjustment Requests (credit note / supplementary invoice) instead.
     *
     * @return array{locked: bool, reason: string, order_id: ?int}
     */
    function wo_invoice_from_operations_locked(PDO $conn, int $invoiceId): array
    {
        $st = $conn->prepare('SELECT order_id FROM invoices WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $orderId = (int)$st->fetchColumn();
        if ($orderId <= 0) {
            return ['locked' => false, 'reason' => '', 'order_id' => null];
        }

        $order = wo_load_order($conn, $orderId);
        if ($order && wo_is_finalized($conn, $order)) {
            return [
                'locked' => true,
                'reason' => 'This invoice is linked to finalized work order #' . $orderId
                    . '. Direct edits are not allowed — use Operations → Request Adjustment, then Accounts → Adjustments to approve.',
                'order_id' => $orderId,
            ];
        }

        if (wo_financial_is_locked($conn, $orderId)) {
            $detail = wo_financial_lock_reason($conn, $orderId);
            return [
                'locked' => true,
                'reason' => ($detail !== '' ? $detail . ' ' : '')
                    . 'Direct invoice edits are not allowed — use Operations → Request Adjustment, then Accounts → Adjustments to approve.',
                'order_id' => $orderId,
            ];
        }

        return ['locked' => false, 'reason' => '', 'order_id' => $orderId];
    }
}

if (!function_exists('wo_sync_order_totals_from_services')) {
    /**
     * Align make_order money columns with order_services line sums (hybrid orders).
     */
    function wo_sync_order_totals_from_services(PDO $conn, int $orderId): bool
    {
        require_once __DIR__ . '/work_order_pricing_service.php';
        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return false;
        }

        $computed = wo_pricing_totals_from_order_services($conn, $orderId, $order);
        if (!$computed) {
            return false;
        }

        $changed =
            abs((float)($order['total'] ?? 0) - $computed['subtotal']) > 0.02
            || abs((float)($order['vat_amount'] ?? 0) - $computed['vat_amount']) > 0.02
            || abs((float)($order['grand_total'] ?? 0) - $computed['grand_total']) > 0.02;

        if (!$changed) {
            return false;
        }

        $conn->prepare('
            UPDATE make_order
            SET total = ?, amount_afc = ?, vat_amount = ?, grand_total = ?
            WHERE id = ?
        ')->execute([
            $computed['subtotal'],
            $computed['subtotal'],
            $computed['vat_amount'],
            $computed['grand_total'],
            $orderId,
        ]);

        return true;
    }
}

if (!function_exists('wo_sync_finalized_order_from_invoice')) {
    /**
     * One-time repair: align finalized WO totals with its issued invoice (no AR/GL changes).
     * Use when invoice was corrected directly but make_order frozen totals are stale.
     *
     * @return array{success:bool, message:string, changed?:bool, old_grand?:float, new_grand?:float}
     */
    function wo_sync_finalized_order_from_invoice(PDO $conn, int $orderId, ?int $userId = null): array
    {
        if (PHP_SAPI !== 'cli' && !sm_user_can_finalize($conn)) {
            return ['success' => false, 'message' => 'Only Admin or Accountant can sync work order totals.'];
        }

        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'Work order not found.'];
        }
        if (!wo_is_finalized($conn, $order)) {
            return ['success' => false, 'message' => 'This sync is only for finalized work orders.'];
        }

        require_once __DIR__ . '/work_order_adjustment_service.php';
        if (sm_pending_adjustment_for_order($conn, $orderId)) {
            return ['success' => false, 'message' => 'Cancel or complete the pending adjustment request before syncing.'];
        }

        $st = $conn->prepare("
            SELECT id, invoice_no, subtotal, vat_amount, total, status
            FROM invoices
            WHERE order_id = ?
              AND status NOT IN ('void', 'draft')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$orderId]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return ['success' => false, 'message' => 'No issued invoice found for this work order.'];
        }

        $woGrand = round((float)($order['frozen_grand_total'] ?? $order['grand_total'] ?? 0), 2);
        $invGrand = round((float)($inv['total'] ?? 0), 2);
        if ($invGrand <= 0) {
            return ['success' => false, 'message' => 'Invoice total is zero — cannot sync.'];
        }
        if (abs($woGrand - $invGrand) < 0.02) {
            return ['success' => true, 'message' => 'Work order already matches invoice total (AED ' . number_format($invGrand, 2) . ').', 'changed' => false];
        }

        $newSub = round((float)($inv['subtotal'] ?? 0), 2);
        $newVat = round((float)($inv['vat_amount'] ?? 0), 2);
        $newGrand = $invGrand;

        $sets = [
            'total = ?',
            'amount_afc = ?',
            'vat_amount = ?',
            'grand_total = ?',
        ];
        $params = [$newSub, $newSub, $newVat, $newGrand];
        if (wo_column_exists($conn, 'frozen_subtotal')) {
            $sets[] = 'frozen_subtotal = ?';
            $sets[] = 'frozen_vat_amount = ?';
            $sets[] = 'frozen_grand_total = ?';
            $params[] = $newSub;
            $params[] = $newVat;
            $params[] = $newGrand;
        }
        $params[] = $orderId;
        $conn->prepare('UPDATE make_order SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        require_once __DIR__ . '/AuditService.php';
        AuditService::logUpdate('make_order', $orderId, [
            'grand_total' => $woGrand,
            'frozen_grand_total' => $order['frozen_grand_total'] ?? null,
        ], [
            'grand_total' => $newGrand,
            'frozen_grand_total' => $newGrand,
            'synced_from_invoice' => (int)$inv['id'],
        ], "Synced WO #{$orderId} totals from invoice " . ($inv['invoice_no'] ?? ('#' . $inv['id'])) . " (no AR/GL change)", $userId);

        return [
            'success' => true,
            'changed' => true,
            'message' => sprintf(
                'Work order #%d synced to invoice %s: AED %s → AED %s (invoice unchanged; no extra AR document).',
                $orderId,
                $inv['invoice_no'] ?? ('#' . $inv['id']),
                number_format($woGrand, 2),
                number_format($newGrand, 2)
            ),
            'old_grand' => $woGrand,
            'new_grand' => $newGrand,
        ];
    }
}

if (!function_exists('wo_maybe_sync_invoice_after_order_change')) {
    /**
     * Legacy path only: create/refresh invoice after WO save when defer flag is off and order is not locked.
     * @return int|null invoice id
     */
    function wo_maybe_sync_invoice_after_order_change(PDO $conn, int $orderId, ?int $userId = null, bool $repostGl = true): ?int
    {
        if (!wo_should_auto_invoice($conn) || wo_financial_is_locked($conn, $orderId)) {
            return null;
        }
        require_once __DIR__ . '/ar_helpers.php';
        $invoiceId = ar_ensure_invoice_for_order($conn, $orderId, $userId);
        if ($invoiceId && $repostGl) {
            require_once __DIR__ . '/gl_posting.php';
            gl_repost_invoice($conn, (int)$invoiceId);
        }
        return $invoiceId ? (int)$invoiceId : null;
    }
}

if (!function_exists('wo_normalize_worker_ids')) {
    function wo_normalize_worker_ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }
}

if (!function_exists('wo_services_signature')) {
    function wo_services_signature(array $rows): string
    {
        $norm = [];
        foreach ($rows as $r) {
            $norm[] = [
                'service_id' => (int)($r['service_id'] ?? 0),
                'qty' => round((float)($r['qty'] ?? 0), 2),
                'unit' => (string)($r['unit'] ?? ''),
                'unit_price' => round((float)($r['unit_price'] ?? 0), 2),
                'vat_rate' => round((float)($r['vat_rate'] ?? 0), 2),
            ];
        }
        usort($norm, fn($a, $b) => $a['service_id'] <=> $b['service_id']);
        return md5(json_encode($norm));
    }
}

if (!function_exists('sm_user_can_finalize')) {
    /** Admin / Accountant / Owner — NOT receptionist Operation role alone. */
    function sm_user_can_finalize(PDO $conn): bool
    {
        $roles = current_user_roles($conn);
        if (array_intersect(['Owner', 'Admin', 'Account'], $roles)) {
            return true;
        }
        if (!function_exists('has_permission')) {
            require_once __DIR__ . '/permissions.php';
        }
        if (has_permission('sm.jobs.finalize', defined('MODULE_CLEANING') ? MODULE_CLEANING : 'cleaning', $conn)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('sm_user_can_mark_complete')) {
    function sm_user_can_mark_complete(PDO $conn): bool
    {
        $roles = current_user_roles($conn);
        if (array_intersect(['Owner', 'Admin', 'Account', 'Operation'], $roles)) {
            return true;
        }
        if (!function_exists('has_permission')) {
            require_once __DIR__ . '/permissions.php';
        }
        return has_permission('sm.jobs.complete', defined('MODULE_CLEANING') ? MODULE_CLEANING : 'cleaning', $conn)
            || has_permission('workorders.complete', defined('MODULE_CLEANING') ? MODULE_CLEANING : 'cleaning', $conn);
    }
}

if (!function_exists('wo_map_status_to_ops')) {
    function wo_map_status_to_ops(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'cancelled') {
            return 'cancelled';
        }
        if (in_array($status, ['completed', 'invoiced', 'paid'], true)) {
            return 'completed';
        }
        return 'open';
    }
}

if (!function_exists('wo_display_workflow_status')) {
    /**
     * Legacy WO status label for UI/export — reflects invoice payment when WO is invoiced.
     */
    function wo_display_workflow_status(array $order): string
    {
        $legacy = strtolower(trim((string)($order['status'] ?? '')));
        $invoiceStatus = strtolower(trim((string)($order['invoice_status'] ?? '')));

        if (in_array($legacy, ['invoiced', 'paid'], true) && $invoiceStatus !== '') {
            if ($invoiceStatus === 'paid') {
                return 'paid';
            }
            if ($invoiceStatus === 'partially_paid') {
                return 'partially_paid';
            }
        }

        return $legacy;
    }
}

if (!function_exists('wo_load_order')) {
    function wo_load_order(PDO $conn, int $orderId): ?array
    {
        $st = $conn->prepare('SELECT * FROM make_order WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('wo_is_finalized')) {
    function wo_is_finalized(PDO $conn, array $order): bool
    {
        if (wo_column_exists($conn, 'is_finalized') && (int)($order['is_finalized'] ?? 0) === 1) {
            return true;
        }
        return false;
    }
}

if (!function_exists('wo_ops_is_locked')) {
    /**
     * Operational lock (schedule / status / complete / reassign).
     * Only true Finalize fully locks Operations.
     */
    function wo_ops_is_locked(PDO $conn, array $order): bool
    {
        return wo_is_finalized($conn, $order);
    }
}

if (!function_exists('wo_invoice_activity')) {
    /**
     * Non-void invoice + payment/GL activity for a work order.
     *
     * @return array{
     *   has_invoice: bool,
     *   has_activity: bool,
     *   invoice_id: ?int,
     *   invoice_no: string,
     *   invoice_status: string,
     *   alloc_cnt: int,
     *   posted_gl: bool,
     *   reason: string
     * }
     */
    function wo_invoice_activity(PDO $conn, int $orderId): array
    {
        $empty = [
            'has_invoice' => false,
            'has_activity' => false,
            'invoice_id' => null,
            'invoice_no' => '',
            'invoice_status' => '',
            'alloc_cnt' => 0,
            'posted_gl' => false,
            'reason' => '',
        ];
        if ($orderId <= 0) {
            return $empty;
        }

        $st = $conn->prepare("
            SELECT i.id, i.invoice_no, i.status,
                   (SELECT COUNT(*) FROM receipt_allocations ra WHERE ra.invoice_id = i.id) AS alloc_cnt
            FROM invoices i
            WHERE i.order_id = ? AND i.status <> 'void'
            ORDER BY i.id DESC
            LIMIT 1
        ");
        $st->execute([$orderId]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return $empty;
        }

        $invId = (int)$inv['id'];
        $status = strtolower(trim((string)($inv['status'] ?? '')));
        $allocCnt = (int)($inv['alloc_cnt'] ?? 0);
        $postedGl = false;
        $gj = $conn->prepare("
            SELECT COUNT(*) FROM gl_journals
            WHERE source = 'invoice' AND source_id = ? AND is_posted = 1 AND is_reversed = 0
        ");
        $gj->execute([$invId]);
        $postedGl = ((int)$gj->fetchColumn() > 0);

        $hasActivity = in_array($status, ['issued', 'paid', 'partially_paid'], true)
            || $allocCnt > 0
            || $postedGl;

        $reason = '';
        if ($hasActivity) {
            $label = (string)($inv['invoice_no'] ?? ('#' . $invId));
            if (in_array($status, ['paid', 'partially_paid'], true) || $allocCnt > 0) {
                $reason = "Invoice {$label} has payment/allocation activity.";
            } elseif ($postedGl) {
                $reason = "Invoice {$label} is issued and posted to the ledger.";
            } else {
                $reason = "Invoice {$label} is issued.";
            }
        }

        return [
            'has_invoice' => true,
            'has_activity' => $hasActivity,
            'invoice_id' => $invId,
            'invoice_no' => (string)($inv['invoice_no'] ?? ''),
            'invoice_status' => $status,
            'alloc_cnt' => $allocCnt,
            'posted_gl' => $postedGl,
            'reason' => $reason,
        ];
    }
}

if (!function_exists('wo_has_financial_activity')) {
    function wo_has_financial_activity(PDO $conn, int $orderId): bool
    {
        return wo_invoice_activity($conn, $orderId)['has_activity'] === true;
    }
}

if (!function_exists('wo_financial_is_locked')) {
    /**
     * Money / pricing fields cannot change without Accounts adjustment.
     * Finalized always locks. In defer-invoice mode, issued/paid/allocated/posted invoices also lock money.
     */
    function wo_financial_is_locked(PDO $conn, int $orderId): bool
    {
        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return false;
        }
        if (wo_is_finalized($conn, $order)) {
            return true;
        }
        if (!sm_defer_auto_invoice($conn)) {
            return false;
        }
        return wo_has_financial_activity($conn, $orderId);
    }
}

if (!function_exists('wo_ops_can_direct_cancel')) {
    /**
     * Direct Operations cancel is allowed only when there is no issued/posted/paid/allocated invoice.
     * Otherwise use Accounts void / credit / refund / adjustment workflows.
     *
     * @return array{0: bool, 1: string} [allowed, reason_if_blocked]
     */
    function wo_ops_can_direct_cancel(PDO $conn, int $orderId): array
    {
        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return [false, 'Work order not found.'];
        }
        if (wo_is_finalized($conn, $order)) {
            return [false, 'This work order is finalized. Use Accounts → Adjustment Requests (cancellation / credit note).'];
        }
        $activity = wo_invoice_activity($conn, $orderId);
        if ($activity['has_activity']) {
            return [
                false,
                $activity['reason']
                    . ' Cancel via Accounts (void / credit note / refund) — not directly from Operations.',
            ];
        }
        return [true, ''];
    }
}

if (!function_exists('wo_ops_lock_reason')) {
    function wo_ops_lock_reason(PDO $conn, array $order): string
    {
        if (!wo_ops_is_locked($conn, $order)) {
            return '';
        }
        return 'Finalized — fully locked. Use Accounts → Adjustment Requests for changes.';
    }
}

if (!function_exists('wo_financial_lock_reason')) {
    function wo_financial_lock_reason(PDO $conn, int $orderId): string
    {
        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return '';
        }
        if (wo_is_finalized($conn, $order)) {
            return wo_ops_lock_reason($conn, $order);
        }
        if (!wo_financial_is_locked($conn, $orderId)) {
            return '';
        }
        $activity = wo_invoice_activity($conn, $orderId);
        if ($activity['reason'] !== '') {
            return $activity['reason']
                . ' Money fields are locked; schedule/status can still change until finalize.';
        }
        return 'Financially locked. Money fields require an Accounts adjustment.';
    }
}

if (!function_exists('wo_assert_ops_editable')) {
    /** @throws RuntimeException */
    function wo_assert_ops_editable(PDO $conn, array $order, string $context = 'edit'): void
    {
        if (!wo_ops_is_locked($conn, $order)) {
            return;
        }
        throw new RuntimeException(wo_ops_lock_reason($conn, $order) . ' Context: ' . $context);
    }
}

if (!function_exists('wo_can_finalize')) {
    function wo_can_finalize(PDO $conn, array $order): array
    {
        if (wo_is_finalized($conn, $order)) {
            return [false, 'This work order is already finalized.'];
        }
        if (($order['status'] ?? '') === 'cancelled') {
            return [false, 'Cancelled work orders cannot be finalized.'];
        }
        $status = strtolower((string)($order['status'] ?? ''));
        if (!in_array($status, ['completed', 'invoiced'], true)) {
            return [false, 'Mark the job as completed before finalizing (current status: ' . ($order['status'] ?? 'unknown') . ').'];
        }
        $grand = (float)($order['grand_total'] ?? 0);
        if ($grand <= 0) {
            $grand = (float)($order['total'] ?? 0) + (float)($order['vat_amount'] ?? 0);
        }
        if ($grand <= 0) {
            return [false, 'Work order total must be greater than zero before finalizing.'];
        }
        return [true, ''];
    }
}

if (!function_exists('wo_assert_financial_editable')) {
    /** @throws RuntimeException */
    function wo_assert_financial_editable(PDO $conn, int $orderId, string $context = 'edit'): void
    {
        if (!wo_financial_is_locked($conn, $orderId)) {
            return;
        }
        $reason = wo_financial_lock_reason($conn, $orderId);
        throw new RuntimeException(
            ($reason !== '' ? $reason : 'This work order is financially locked.')
            . ' Context: ' . $context
        );
    }
}

if (!function_exists('wo_sync_ops_status_column')) {
    function wo_sync_ops_status_column(PDO $conn, int $orderId, string $legacyStatus): void
    {
        if (!wo_column_exists($conn, 'ops_status')) {
            return;
        }
        $ops = wo_map_status_to_ops($legacyStatus);
        $conn->prepare('UPDATE make_order SET ops_status = ? WHERE id = ?')->execute([$ops, $orderId]);
    }
}

if (!function_exists('wo_order_hours_for_pricing')) {
    function wo_order_hours_for_pricing(array $row): float
    {
        $hours = (float)($row['hours'] ?? 0);
        if ($hours > 0) {
            return $hours;
        }
        $start = (string)($row['start_time'] ?? '');
        $end = (string)($row['end_time'] ?? '');
        if ($start !== '' && $end !== '' && preg_match('/^(\d{1,2}):(\d{2})/', $start, $sm) && preg_match('/^(\d{1,2}):(\d{2})/', $end, $em)) {
            $sMin = ((int)$sm[1]) * 60 + (int)$sm[2];
            $eMin = ((int)$em[1]) * 60 + (int)$em[2];
            if ($eMin > $sMin) {
                return round(($eMin - $sMin) / 60, 2);
            }
        }
        return 0.0;
    }
}

if (!function_exists('wo_order_rate_for_pricing')) {
    function wo_order_rate_for_pricing(array $row): float
    {
        foreach (['hourly_rate', 'fee_charged', 'client_rate'] as $key) {
            $val = (float)($row[$key] ?? 0);
            if ($val > 0) {
                return $val;
            }
        }
        return 0.0;
    }
}

if (!function_exists('wo_is_vat_included_mode')) {
    /** @return bool|null null when unknown / column absent */
    function wo_is_vat_included_mode(array $row): ?bool
    {
        if (!array_key_exists('vat_included', $row) || $row['vat_included'] === null || $row['vat_included'] === '') {
            return null;
        }
        $v = strtolower(trim((string)$row['vat_included']));
        if (in_array($v, ['yes', '1', 'true'], true)) {
            return true;
        }
        if (in_array($v, ['no', '0', 'false'], true)) {
            return false;
        }
        return null;
    }
}

if (!function_exists('wo_order_customer_total')) {
    /**
     * Customer-facing order total (incl. VAT).
     * Uses grand_total — same source as Worker Availability day totals.
     */
    function wo_order_customer_total(array $row): float
    {
        $isFinalized = (int)($row['is_finalized'] ?? 0) === 1;
        if ($isFinalized) {
            $frozen = (float)($row['frozen_grand_total'] ?? 0);
            if ($frozen > 0) {
                return round($frozen, 2);
            }
        }
        $grand = (float)($row['grand_total'] ?? 0);
        if ($grand > 0) {
            return round($grand, 2);
        }
        return round((float)($row['total'] ?? 0) + (float)($row['vat_amount'] ?? 0), 2);
    }
}

if (!function_exists('wo_order_customer_total_sql')) {
    /** SQL expression for SUM/SELECT — matches Worker Availability grand_total sums. */
    function wo_order_customer_total_sql(PDO $conn): string
    {
        if (wo_column_exists($conn, 'frozen_grand_total') && wo_column_exists($conn, 'is_finalized')) {
            return "CASE WHEN COALESCE(mo.is_finalized,0)=1 AND COALESCE(mo.frozen_grand_total,0)>0 "
                . "THEN mo.frozen_grand_total "
                . "ELSE COALESCE(NULLIF(mo.grand_total, 0), mo.total + COALESCE(mo.vat_amount, 0)) END";
        }
        return 'COALESCE(NULLIF(mo.grand_total, 0), mo.total + COALESCE(mo.vat_amount, 0))';
    }
}
