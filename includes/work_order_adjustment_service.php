<?php
/**
 * Service Management Phase 3 — adjustment requests & accountant resolution.
 */

require_once __DIR__ . '/work_order_financial_guard.php';
require_once __DIR__ . '/ar_helpers.php';
require_once __DIR__ . '/service_accounting_service.php';
require_once __DIR__ . '/cleaning_order_cancellation_helper.php';

if (!function_exists('sm_parse_cancellation_meta_from_adjustment')) {
    /**
     * Extract category/details from an adjustment cancellation request (reason + notes).
     *
     * @return array{category:string, details:string, summary:string}
     */
    function sm_parse_cancellation_meta_from_adjustment(array $req): array
    {
        $reason = trim((string)($req['reason'] ?? ''));
        $notes = trim((string)($req['notes'] ?? ''));
        $category = '';
        $details = '';

        if ($notes !== '' && preg_match('/Cancellation category:\s*([^\r\n]+)\s*\nDetails:\s*(.+)/is', $notes, $m)) {
            $category = cleaning_order_cancel_normalize_category(trim($m[1]));
            $details = trim($m[2]);
            // Drop optional "extra notes" section if present after a blank line — keep full details block
            if (preg_match('/^(.*?)(?:\n\n|$)/s', $details, $dm)) {
                // Keep full details including extra notes for cancellation_details
            }
        }

        if ($category === '' && $reason !== '' && preg_match('/^(Cleaner|Driver|Management|Client)\s*:\s*(.+)$/is', $reason, $m)) {
            $category = cleaning_order_cancel_normalize_category(trim($m[1]));
            if ($details === '') {
                $details = trim($m[2]);
            }
        }

        if ($details === '') {
            $details = $reason !== '' ? $reason : $notes;
        }

        $summary = cleaning_order_cancel_summary($category, $details);
        if ($summary === '') {
            $summary = $details !== '' ? $details : 'Cancelled via Accounts adjustment request';
        }

        return [
            'category' => $category,
            'details' => $details,
            'summary' => $summary,
        ];
    }
}

if (!function_exists('sm_apply_cancellation_meta_to_order')) {
    /**
     * Write cancellation tracking fields onto make_order (used by adjustment approval + backfill).
     */
    function sm_apply_cancellation_meta_to_order(
        PDO $conn,
        int $orderId,
        array $meta,
        ?int $cancelledBy,
        ?string $cancelledAt = null
    ): void {
        $sets = [
            "status = 'cancelled'",
            'cancel_reason = ?',
            'cancelled_by = ?',
            'cancelled_at = COALESCE(?, cancelled_at, NOW())',
        ];
        $params = [
            $meta['summary'] !== '' ? $meta['summary'] : null,
            $cancelledBy && $cancelledBy > 0 ? $cancelledBy : null,
            $cancelledAt,
        ];

        if (wo_column_exists($conn, 'ops_status')) {
            $sets[] = "ops_status = 'cancelled'";
        }
        if (function_exists('cleaning_order_cancel_column_exists') && cleaning_order_cancel_column_exists($conn, 'cancellation_category')) {
            $sets[] = 'cancellation_category = ?';
            $params[] = $meta['category'] !== '' ? $meta['category'] : null;
        }
        if (function_exists('cleaning_order_cancel_column_exists') && cleaning_order_cancel_column_exists($conn, 'cancellation_details')) {
            $sets[] = 'cancellation_details = ?';
            $params[] = $meta['details'] !== '' ? $meta['details'] : null;
        }

        $params[] = $orderId;
        $conn->prepare('UPDATE make_order SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }
}

if (!function_exists('sm_backfill_cancellation_meta_from_adjustments')) {
    /**
     * Repair cancelled WOs missing category/details after adjustment approval (idempotent).
     */
    function sm_backfill_cancellation_meta_from_adjustments(PDO $conn, int $limit = 200): int
    {
        if (!sm_adj_table_exists($conn)) {
            return 0;
        }
        $st = $conn->prepare("
            SELECT mo.id AS order_id, r.*
            FROM make_order mo
            INNER JOIN sm_adjustment_requests r
              ON r.order_id = mo.id
             AND r.request_type = 'cancellation'
             AND r.status = 'approved'
            WHERE mo.status = 'cancelled'
              AND (
                COALESCE(mo.cancellation_category, '') = ''
                OR COALESCE(mo.cancellation_details, '') = ''
                OR mo.cancelled_at IS NULL
                OR mo.cancelled_by IS NULL
              )
            ORDER BY r.reviewed_at DESC, r.id DESC
            LIMIT {$limit}
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seen = [];
        $n = 0;
        foreach ($rows as $row) {
            $oid = (int)$row['order_id'];
            if (isset($seen[$oid])) {
                continue;
            }
            $seen[$oid] = true;
            $meta = sm_parse_cancellation_meta_from_adjustment($row);
            $by = (int)($row['requested_by'] ?? 0);
            if ($by <= 0) {
                $by = (int)($row['reviewed_by'] ?? 0);
            }
            $at = !empty($row['reviewed_at']) ? (string)$row['reviewed_at'] : null;
            sm_apply_cancellation_meta_to_order($conn, $oid, $meta, $by, $at);
            $n++;
        }
        return $n;
    }
}

if (!function_exists('sm_adj_table_exists')) {
    function sm_adj_table_exists(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $st = $conn->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_adjustment_requests'
            ");
            $st->execute();
            $ok = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('sm_user_can_request_adjustment')) {
    function sm_user_can_request_adjustment(PDO $conn): bool
    {
        $roles = current_user_roles($conn);
        if (array_intersect(['Owner', 'Admin', 'Account', 'Operation'], $roles)) {
            return true;
        }
        if (!function_exists('has_permission')) {
            require_once __DIR__ . '/permissions.php';
        }
        return has_permission('sm.jobs.request_adjustment', defined('MODULE_CLEANING') ? MODULE_CLEANING : 'cleaning', $conn);
    }
}

if (!function_exists('sm_user_can_approve_adjustment')) {
    function sm_user_can_approve_adjustment(PDO $conn): bool
    {
        return sm_user_can_finalize($conn);
    }
}

if (!function_exists('sm_log_financial_change')) {
    function sm_log_financial_change(
        PDO $conn,
        int $orderId,
        string $field,
        $oldVal,
        $newVal,
        string $source,
        ?int $userId,
        ?int $requestId = null
    ): void {
        if (!sm_adj_table_exists($conn)) {
            return;
        }
        try {
            $st = $conn->prepare("
                INSERT INTO sm_financial_change_log
                  (order_id, adjustment_request_id, field_name, old_value, new_value, change_source, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([
                $orderId,
                $requestId,
                $field,
                $oldVal === null ? null : (string)$oldVal,
                $newVal === null ? null : (string)$newVal,
                $source,
                $userId,
            ]);
        } catch (Throwable $e) {
            error_log('sm_log_financial_change: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sm_apply_adjustment_totals_to_order')) {
    /**
     * After an approved adjustment, sync make_order display + frozen totals.
     */
    function sm_apply_adjustment_totals_to_order(PDO $conn, int $orderId, array $req): void
    {
        $newSub = round((float)($req['requested_subtotal'] ?? 0), 2);
        $newVat = round((float)($req['requested_vat'] ?? 0), 2);
        $newGrand = round((float)($req['requested_grand'] ?? 0), 2);

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
    }
}

if (!function_exists('sm_get_order_invoice')) {
    function sm_get_order_invoice(PDO $conn, int $orderId): ?array
    {
        $st = $conn->prepare("SELECT * FROM invoices WHERE order_id = ? AND status <> 'void' LIMIT 1");
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sm_pending_adjustment_for_order')) {
    function sm_pending_adjustment_for_order(PDO $conn, int $orderId): ?array
    {
        if (!sm_adj_table_exists($conn)) {
            return null;
        }
        $st = $conn->prepare("
            SELECT * FROM sm_adjustment_requests
            WHERE order_id = ? AND status = 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sm_create_adjustment_request')) {
    /**
     * @return array{success:bool, request_id?:int, message:string}
     */
    function sm_create_adjustment_request(PDO $conn, int $orderId, array $data, ?int $userId = null): array
    {
        if (!sm_adj_table_exists($conn)) {
            return ['success' => false, 'message' => 'Adjustment requests are not enabled. Run Phase 3 migration first.'];
        }
        if (!sm_user_can_request_adjustment($conn)) {
            return ['success' => false, 'message' => 'You do not have permission to request adjustments.'];
        }

        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'Work order not found.'];
        }
        if (!wo_financial_is_locked($conn, $orderId) && !wo_is_finalized($conn, $order)) {
            return ['success' => false, 'message' => 'Adjustments are only for finalized or financially locked work orders. Edit the job directly instead.'];
        }
        if (sm_pending_adjustment_for_order($conn, $orderId)) {
            return ['success' => false, 'message' => 'There is already a pending adjustment request for this work order.'];
        }

        $type = (string)($data['request_type'] ?? 'other');
        $allowed = ['amount_decrease', 'amount_increase', 'cancellation', 'other'];
        if (!in_array($type, $allowed, true)) {
            $type = 'other';
        }

        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            return ['success' => false, 'message' => 'Reason is required.'];
        }
        $notes = trim((string)($data['notes'] ?? ''));

        $curSub = round((float)($order['frozen_subtotal'] ?? $order['total'] ?? 0), 2);
        $curVat = round((float)($order['frozen_vat_amount'] ?? $order['vat_amount'] ?? 0), 2);
        $curGrand = round((float)($order['frozen_grand_total'] ?? $order['grand_total'] ?? ($curSub + $curVat)), 2);

        $reqGrand = isset($data['requested_grand']) ? round((float)$data['requested_grand'], 2) : null;
        $reqSub = isset($data['requested_subtotal']) ? round((float)$data['requested_subtotal'], 2) : null;
        $reqVat = isset($data['requested_vat']) ? round((float)$data['requested_vat'], 2) : null;

        if (in_array($type, ['amount_decrease', 'amount_increase'], true)) {
            if ($reqGrand === null || $reqGrand < 0) {
                return ['success' => false, 'message' => 'Proposed new total is required for amount changes.'];
            }
            if (abs($reqGrand - $curGrand) < 0.01) {
                return ['success' => false, 'message' => 'Proposed total is the same as the current frozen total.'];
            }
            if ($type === 'amount_decrease' && $reqGrand > $curGrand) {
                return ['success' => false, 'message' => 'For a decrease, proposed total must be less than the current total.'];
            }
            if ($type === 'amount_increase' && $reqGrand < $curGrand) {
                return ['success' => false, 'message' => 'For an increase, proposed total must be greater than the current total.'];
            }
            if ($reqSub === null && $curSub > 0 && $curGrand > 0) {
                $ratio = $reqGrand / max(0.01, $curGrand);
                $reqSub = round($curSub * $ratio, 2);
                $reqVat = round($reqGrand - $reqSub, 2);
            }
        } elseif ($type === 'cancellation') {
            $reqGrand = 0.0;
            $reqSub = 0.0;
            $reqVat = 0.0;
        }

        $delta = ($reqGrand !== null) ? round($reqGrand - $curGrand, 2) : null;
        $invoice = sm_get_order_invoice($conn, $orderId);
        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);

        $st = $conn->prepare("
            INSERT INTO sm_adjustment_requests
              (order_id, invoice_id, request_type, status, reason, notes,
               current_subtotal, current_vat, current_grand,
               requested_subtotal, requested_vat, requested_grand, delta_grand, requested_by)
            VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $orderId,
            $invoice ? (int)$invoice['id'] : null,
            $type,
            $reason,
            $notes !== '' ? $notes : null,
            $curSub,
            $curVat,
            $curGrand,
            $reqSub,
            $reqVat,
            $reqGrand,
            $delta,
            $userId,
        ]);
        $requestId = (int)$conn->lastInsertId();

        require_once __DIR__ . '/AuditService.php';
        AuditService::logCreate('sm_adjustment_requests', $requestId, [
            'order_id' => $orderId,
            'request_type' => $type,
            'delta_grand' => $delta,
        ], "Adjustment request #{$requestId} for WO #{$orderId}: {$type}");

        return [
            'success' => true,
            'request_id' => $requestId,
            'message' => 'Adjustment request submitted. Accounts will review and post the credit note or supplementary invoice.',
        ];
    }
}

if (!function_exists('sm_next_credit_note_number')) {
    function sm_next_credit_note_number(PDO $conn): string
    {
        $year = date('Y');
        $maxQ = $conn->prepare("
            SELECT MAX(CAST(SUBSTRING_INDEX(credit_note_number,'-',-1) AS UNSIGNED))
            FROM credit_notes WHERE credit_note_number LIKE ?
        ");
        $maxQ->execute(["CN-$year-%"]);
        $next = (int)($maxQ->fetchColumn() ?: 0) + 1;
        return sprintf('CN-%s-%05d', $year, $next);
    }
}

if (!function_exists('sm_create_credit_note_for_adjustment')) {
    function sm_create_credit_note_for_adjustment(
        PDO $conn,
        array $request,
        float $amount,
        string $description,
        int $userId
    ): int {
        if ($amount <= 0) {
            throw new RuntimeException('Credit note amount must be positive.');
        }
        $st = $conn->prepare('SELECT client_id FROM make_order WHERE id = ?');
        $st->execute([(int)$request['order_id']]);
        $clientId = (int)$st->fetchColumn();
        if ($clientId <= 0) {
            throw new RuntimeException('Work order has no client.');
        }

        $invoiceId = (int)($request['invoice_id'] ?? 0) ?: null;
        $cnNo = sm_next_credit_note_number($conn);
        $vatRate = 5.0;
        $subtotal = round($amount / (1 + $vatRate / 100), 2);
        $taxAmount = round($amount - $subtotal, 2);

        $ins = $conn->prepare("
            INSERT INTO credit_notes
              (credit_note_number, client_id, invoice_id, credit_note_date, reference,
               reason, reason_description, subtotal, tax_amount, total_amount,
               status, notes, created_by, issued_at)
            VALUES (?, ?, ?, CURDATE(), ?, 'adjustment', ?, ?, ?, ?, 'issued', ?, ?, NOW())
        ");
        $ins->execute([
            $cnNo,
            $clientId,
            $invoiceId,
            'WO#' . (int)$request['order_id'] . ' ADJ#' . (int)$request['id'],
            $description,
            $subtotal,
            $taxAmount,
            $amount,
            'Auto-created from adjustment request #' . (int)$request['id'],
            $userId,
        ]);
        $cnId = (int)$conn->lastInsertId();

        $conn->prepare("
            INSERT INTO credit_note_items
              (credit_note_id, description, quantity, unit_price, line_total, tax_rate, tax_amount)
            VALUES (?, ?, 1, ?, ?, ?, ?)
        ")->execute([$cnId, $description, $subtotal, $subtotal, $vatRate, $taxAmount]);

        $glAccounts = [];
        $glQ = $conn->query("SELECT id, account_no FROM chart_of_accounts WHERE account_no IN ('CN-001', 'CN-002')");
        while ($row = $glQ->fetch(PDO::FETCH_ASSOC)) {
            $glAccounts[$row['account_no']] = (int)$row['id'];
        }
        $glIns = $conn->prepare("
            INSERT INTO credit_note_gl_postings
              (credit_note_id, gl_account_id, debit_amount, credit_amount, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!empty($glAccounts['CN-001'])) {
            $glIns->execute([$cnId, $glAccounts['CN-001'], $amount, 0, "Credit Note $cnNo", $userId]);
        }
        if (!empty($glAccounts['CN-002'])) {
            $glIns->execute([$cnId, $glAccounts['CN-002'], 0, $amount, "Credit Note $cnNo", $userId]);
        }

        if ($invoiceId) {
            $conn->prepare("
                INSERT INTO credit_note_allocations
                  (credit_note_id, invoice_id, allocated_amount, created_by, notes)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $cnId,
                $invoiceId,
                $amount,
                $userId,
                'Auto-allocated from adjustment request #' . (int)$request['id'],
            ]);
            $conn->prepare("UPDATE credit_notes SET status = 'allocated' WHERE id = ?")->execute([$cnId]);
        }

        return $cnId;
    }
}

if (!function_exists('sm_create_supplementary_invoice')) {
    function sm_create_supplementary_invoice(
        PDO $conn,
        array $request,
        float $subtotal,
        float $vat,
        float $total,
        int $userId
    ): int {
        if ($total <= 0) {
            throw new RuntimeException('Supplementary invoice total must be positive.');
        }
        $orderId = (int)$request['order_id'];
        $st = $conn->prepare('SELECT mo.*, c.terms FROM make_order mo LEFT JOIN client c ON c.id = mo.client_id WHERE mo.id = ?');
        $st->execute([$orderId]);
        $mo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$mo) {
            throw new RuntimeException('Work order not found.');
        }

        $year = date('Y');
        $maxQ = $conn->prepare("
            SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no,'-',-1) AS UNSIGNED))
            FROM invoices WHERE invoice_no LIKE ?
        ");
        $maxQ->execute(["ADJ-$year-%"]);
        $seq = (int)($maxQ->fetchColumn() ?: 0) + 1;
        $invoiceNo = sprintf('ADJ-%s-%05d', $year, $seq);

        $issueDate = $mo['service_date'] ?: date('Y-m-d');
        $terms = $mo['terms'] ?? ($mo['payment'] ?? 'cash');
        if (function_exists('ar_compute_due_date')) {
            $dueDate = ar_compute_due_date($issueDate, (string)$terms);
        } else {
            $dueDate = $issueDate;
        }

        $ins = $conn->prepare("
            INSERT INTO invoices
              (order_id, client_id, invoice_no, issue_date, due_date, terms, currency,
               subtotal, discount_amount, vat_rate, vat_amount, rounding, total,
               status, notes, created_at, created_by)
            VALUES
              (NULL, ?, ?, ?, ?, ?, 'AED', ?, 0, ?, ?, 0, ?, 'issued', ?, NOW(), ?)
        ");
        $ins->execute([
            (int)$mo['client_id'],
            $invoiceNo,
            $issueDate,
            $dueDate,
            $terms,
            $subtotal,
            (float)($mo['vat_rate'] ?? 5),
            $vat,
            $total,
            'Supplementary invoice for WO #' . $orderId . ' (adjustment request #' . (int)$request['id'] . ')',
            $userId,
        ]);
        $invoiceId = (int)$conn->lastInsertId();

        $conn->prepare("
            INSERT INTO invoice_items
              (invoice_id, line_no, description, qty, unit_price, line_subtotal, vat_rate, vat_value, line_total)
            VALUES (?, 1, ?, 1, ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            'Additional charge — WO #' . $orderId,
            $subtotal,
            $subtotal,
            (float)($mo['vat_rate'] ?? 5),
            $vat,
            $total,
        ]);

        $conn->prepare("
            UPDATE invoices SET subtotal = ?, vat_amount = ?, total = ?, balance_due = ? WHERE id = ?
        ")->execute([$subtotal, $vat, $total, $total, $invoiceId]);

        ar_post_or_repost_invoice($conn, $invoiceId);
        return $invoiceId;
    }
}

if (!function_exists('sm_approve_adjustment_request')) {
    /**
     * @return array{success:bool, message:string, credit_note_id?:int, adjustment_invoice_id?:int}
     */
    function sm_approve_adjustment_request(PDO $conn, int $requestId, ?int $userId = null, string $reviewNotes = ''): array
    {
        if (!sm_user_can_approve_adjustment($conn)) {
            return ['success' => false, 'message' => 'Only Admin or Accountant can approve adjustments.'];
        }
        if (!sm_adj_table_exists($conn)) {
            return ['success' => false, 'message' => 'Adjustment module not installed.'];
        }

        $conn->beginTransaction();
        $st = $conn->prepare('SELECT * FROM sm_adjustment_requests WHERE id = ? FOR UPDATE');
        $st->execute([$requestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        try {
            if (!$req) {
                throw new RuntimeException('Request not found.');
            }
            if ($req['status'] !== 'pending') {
                throw new RuntimeException('Request is not pending (status: ' . $req['status'] . ').');
            }

            $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
            $orderId = (int)$req['order_id'];
            $type = (string)$req['request_type'];
            $delta = (float)($req['delta_grand'] ?? 0);
            $creditNoteId = null;
            $adjInvoiceId = null;
            $resolution = 'manual';

            if ($type === 'cancellation') {
                $invoice = sm_get_order_invoice($conn, $orderId);
                if ($invoice) {
                    $allocSt = $conn->prepare("SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE invoice_id = ?");
                    $allocSt->execute([(int)$invoice['id']]);
                    $allocAmt = (float)$allocSt->fetchColumn();

                    if ($allocAmt > 0 || in_array($invoice['status'], ['paid', 'partially_paid'], true)) {
                        $amount = (float)$invoice['total'];
                        $creditNoteId = sm_create_credit_note_for_adjustment(
                            $conn,
                            $req,
                            $amount,
                            'Cancellation credit — WO #' . $orderId,
                            (int)$userId
                        );
                        $resolution = 'credit_note';
                    } else {
                        $conn->prepare("UPDATE invoices SET status = 'void', updated_at = NOW() WHERE id = ?")
                            ->execute([(int)$invoice['id']]);
                        ar_post_or_repost_invoice($conn, (int)$invoice['id']);
                        $resolution = 'void_invoice';
                    }
                }
                // Persist category/details/who/when for Cancellations tab (same fields as direct cancel).
                $cancelMeta = sm_parse_cancellation_meta_from_adjustment($req);
                $cancelledBy = (int)($req['requested_by'] ?? 0);
                if ($cancelledBy <= 0) {
                    $cancelledBy = (int)$userId;
                }
                sm_apply_cancellation_meta_to_order($conn, $orderId, $cancelMeta, $cancelledBy, null);
            } elseif ($type === 'amount_decrease' || ($delta < -0.01 && in_array($type, ['other', 'amount_increase'], true))) {
                $amount = abs($delta > 0 ? 0 : $delta);
                if ($amount < 0.01) {
                    $amount = abs((float)$req['current_grand'] - (float)$req['requested_grand']);
                }
                $creditNoteId = sm_create_credit_note_for_adjustment(
                    $conn,
                    $req,
                    $amount,
                    'Amount reduction — WO #' . $orderId,
                    (int)$userId
                );
                $resolution = 'credit_note';
                sm_log_financial_change($conn, $orderId, 'frozen_grand_total', $req['current_grand'], $req['requested_grand'], 'adjustment_approved', $userId, $requestId);
                sm_apply_adjustment_totals_to_order($conn, $orderId, $req);
            } elseif ($type === 'amount_increase' || $delta > 0.01) {
                $addSub = round(abs($delta) / 1.05, 2);
                $addVat = round(abs($delta) - $addSub, 2);
                if ($req['requested_subtotal'] !== null && $req['requested_grand'] !== null) {
                    $addSub = max(0, round((float)$req['requested_subtotal'] - (float)$req['current_subtotal'], 2));
                    $addVat = max(0, round((float)$req['requested_vat'] - (float)$req['current_vat'], 2));
                }
                $adjInvoiceId = sm_create_supplementary_invoice($conn, $req, $addSub, $addVat, abs($delta), (int)$userId);
                $resolution = 'supplementary_invoice';
                sm_log_financial_change($conn, $orderId, 'frozen_grand_total', $req['current_grand'], $req['requested_grand'], 'adjustment_approved', $userId, $requestId);
                sm_apply_adjustment_totals_to_order($conn, $orderId, $req);
            } else {
                $resolution = 'manual';
            }

            $conn->prepare("
                UPDATE sm_adjustment_requests
                SET status = 'approved',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_notes = ?,
                    resolution_type = ?,
                    credit_note_id = ?,
                    adjustment_invoice_id = ?
                WHERE id = ?
            ")->execute([
                $userId,
                $reviewNotes !== '' ? $reviewNotes : null,
                $resolution,
                $creditNoteId,
                $adjInvoiceId,
                $requestId,
            ]);

            $svc = new ServiceAccountingService($conn);
            $svc->invalidateFinancialCache();

            require_once __DIR__ . '/AuditService.php';
            AuditService::logUpdate('sm_adjustment_requests', $requestId, $req, [
                'status' => 'approved',
                'resolution_type' => $resolution,
                'credit_note_id' => $creditNoteId,
                'adjustment_invoice_id' => $adjInvoiceId,
            ], "Approved adjustment request #{$requestId} for WO #{$orderId}");

            $conn->commit();

            $msg = 'Adjustment approved.';
            if ($creditNoteId) {
                $msg .= ' Credit note #' . $creditNoteId . ' created.';
            }
            if ($adjInvoiceId) {
                $msg .= ' Supplementary invoice #' . $adjInvoiceId . ' created and posted.';
            }
            if ($resolution === 'void_invoice') {
                $msg .= ' Original invoice voided.';
            }
            if ($resolution === 'manual') {
                $msg .= ' Marked for manual accounting follow-up.';
            }

            return [
                'success' => true,
                'message' => $msg,
                'credit_note_id' => $creditNoteId,
                'adjustment_invoice_id' => $adjInvoiceId,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('sm_reject_adjustment_request')) {
    function sm_reject_adjustment_request(PDO $conn, int $requestId, string $reviewNotes, ?int $userId = null): array
    {
        if (!sm_user_can_approve_adjustment($conn)) {
            return ['success' => false, 'message' => 'Only Admin or Accountant can reject adjustments.'];
        }
        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $st = $conn->prepare("
            UPDATE sm_adjustment_requests
            SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
            WHERE id = ? AND status = 'pending'
        ");
        $st->execute([$userId, $reviewNotes, $requestId]);
        if ($st->rowCount() === 0) {
            return ['success' => false, 'message' => 'Request not found or not pending.'];
        }
        return ['success' => true, 'message' => 'Adjustment request rejected.'];
    }
}

if (!function_exists('sm_mark_order_complete')) {
    function sm_mark_order_complete(PDO $conn, int $orderId, ?int $userId = null): array
    {
        if (!sm_user_can_mark_complete($conn)) {
            return ['success' => false, 'message' => 'You do not have permission to mark jobs complete.'];
        }
        $order = wo_load_order($conn, $orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'Work order not found.'];
        }
        if (($order['status'] ?? '') === 'cancelled') {
            return ['success' => false, 'message' => 'Cancelled jobs cannot be marked complete.'];
        }
        if (wo_is_finalized($conn, $order)) {
            return ['success' => false, 'message' => 'Job is already finalized.'];
        }

        $conn->prepare("
            UPDATE make_order SET status = 'completed', updated_at = NOW() WHERE id = ?
        ")->execute([$orderId]);
        wo_sync_ops_status_column($conn, $orderId, 'completed');

        require_once __DIR__ . '/AuditService.php';
        $actorId = function_exists('current_user_id') ? current_user_id() : null;
        AuditService::logUpdate('make_order', $orderId, $order, ['status' => 'completed'], "Marked WO #{$orderId} complete", $actorId ? (int)$actorId : null);

        return ['success' => true, 'message' => 'Job marked as completed. Ready for accountant to finalize.'];
    }
}
