<?php
/**
 * Service charge schedule helpers — standalone billing rows vs merge/split into cheques.
 */
declare(strict_types=1);

require_once __DIR__ . '/lease_schedule_engine.php';
require_once __DIR__ . '/lease_installment_schedule.php';
require_once __DIR__ . '/lease_payment_schedule_helper.php';

if (!function_exists('re_service_charge_table_has_column')) {
    function re_service_charge_table_has_column(PDO $conn, string $column): bool
    {
        static $cache = [];
        $key = 're_service_charges.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 're_service_charges'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$column]);
        return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    }
}

if (!function_exists('re_service_charge_ensure_apply_mode_enum')) {
    function re_service_charge_ensure_apply_mode_enum(PDO $conn): void
    {
        static $checked = false;
        if ($checked || !re_service_charge_table_has_column($conn, 'apply_mode')) {
            $checked = true;
            return;
        }
        $stmt = $conn->query("SHOW COLUMNS FROM re_service_charges LIKE 'apply_mode'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)($col['Type'] ?? ''), 'split_all_installments') === false) {
            $conn->exec("
                ALTER TABLE re_service_charges
                MODIFY COLUMN apply_mode ENUM('standalone','merge_first_installment','split_all_installments') NOT NULL DEFAULT 'standalone'
            ");
        }
        $checked = true;
    }
}

if (!function_exists('re_service_charge_ensure_apply_mode_column')) {
    function re_service_charge_ensure_apply_mode_column(PDO $conn): void
    {
        if (re_service_charge_table_has_column($conn, 'apply_mode')) {
            re_service_charge_ensure_apply_mode_enum($conn);
            return;
        }
        $conn->exec("
            ALTER TABLE re_service_charges
            ADD COLUMN apply_mode ENUM('standalone','merge_first_installment','split_all_installments') NOT NULL DEFAULT 'standalone'
            AFTER recurrence_type
        ");
    }
}

if (!function_exists('re_service_charge_normalize_apply_mode')) {
    function re_service_charge_normalize_apply_mode(string $mode): string
    {
        return match ($mode) {
            'merge_first_installment' => 'merge_first_installment',
            'split_all_installments' => 'split_all_installments',
            default => 'standalone',
        };
    }
}

if (!function_exists('re_service_charge_apply_mode_on_schedule')) {
    function re_service_charge_apply_mode_on_schedule(string $mode): bool
    {
        return in_array($mode, ['merge_first_installment', 'split_all_installments'], true);
    }
}

if (!function_exists('re_service_charge_interval_spec')) {
    function re_service_charge_interval_spec(string $recurrenceType): string
    {
        return match ($recurrenceType) {
            'quarterly' => '+3 months',
            'annually' => '+12 months',
            default => '+1 month',
        };
    }
}

if (!function_exists('re_service_charge_count_billing_periods')) {
    function re_service_charge_count_billing_periods(array $charge, ?string $leaseEndDate): int
    {
        $start = new DateTime((string)($charge['start_date'] ?? date('Y-m-d')));
        $end = !empty($charge['end_date'])
            ? new DateTime((string)$charge['end_date'])
            : (!empty($leaseEndDate) ? new DateTime($leaseEndDate) : clone $start);
        if ($end < $start) {
            return 1;
        }

        $isRecurring = !empty($charge['is_recurring']) && (($charge['recurrence_type'] ?? '') !== 'one_time');
        if (!$isRecurring) {
            return 1;
        }

        $intervalSpec = re_service_charge_interval_spec((string)($charge['recurrence_type'] ?? 'monthly'));
        $periods = 0;
        $due = clone $start;
        do {
            $periods++;
            $next = (clone $due)->modify($intervalSpec);
            if (!$isRecurring) {
                break;
            }
            $due = $next;
        } while ($due <= $end);

        return max(1, $periods);
    }
}

if (!function_exists('re_service_charge_total_for_cheque_split')) {
    /**
     * One-time: use entered amount. Recurring: per-period amount × billing periods in range.
     */
    function re_service_charge_total_for_cheque_split(array $charge, ?string $leaseEndDate): float
    {
        $amount = round((float)($charge['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return 0.0;
        }
        $periods = re_service_charge_count_billing_periods($charge, $leaseEndDate);
        return round($amount * $periods, 2);
    }
}

if (!function_exists('re_service_charge_split_amounts')) {
    /**
     * Split a total evenly across N rows; last row absorbs rounding remainder.
     *
     * @return list<float>
     */
    function re_service_charge_split_amounts(float $total, int $count): array
    {
        $total = round($total, 2);
        if ($count <= 0) {
            return [];
        }
        if ($count === 1) {
            return [$total];
        }

        $base = floor(($total / $count) * 100) / 100;
        $amounts = array_fill(0, $count, $base);
        $amounts[$count - 1] = round($total - ($base * ($count - 1)), 2);
        return $amounts;
    }
}

if (!function_exists('re_service_charge_find_open_installments')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_service_charge_find_open_installments(PDO $conn, int $companyId, int $leaseId): array
    {
        require_once __DIR__ . '/lease_termination_helper.php';

        $locked = function_exists('lease_locked_installment_ids')
            ? lease_locked_installment_ids($conn, $leaseId, $companyId)
            : [];

        $stmt = $conn->prepare("
            SELECT id, installment_date, amount, status, installment_type, payment_id
            FROM re_lease_installments
            WHERE lease_id = ? AND company_id = ?
              AND status IN ('pending', 'overdue')
            ORDER BY installment_date ASC, id ASC
        ");
        $stmt->execute([$leaseId, $companyId]);

        $open = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $instId = (int)$row['id'];
            if (isset($locked[$instId])) {
                continue;
            }
            if (function_exists('re_installment_has_collected_schedule')
                && re_installment_has_collected_schedule($conn, $instId, $leaseId, $companyId)) {
                continue;
            }
            if ((string)$row['status'] === 'cancelled') {
                continue;
            }
            $open[] = $row;
        }

        return $open;
    }
}

if (!function_exists('re_service_charge_find_first_open_installment')) {
    /**
     * @return array<string,mixed>|null
     */
    function re_service_charge_find_first_open_installment(PDO $conn, int $companyId, int $leaseId): ?array
    {
        $open = re_service_charge_find_open_installments($conn, $companyId, $leaseId);
        return $open[0] ?? null;
    }
}

if (!function_exists('re_service_charge_apply_amount_to_installment')) {
    /**
     * Increase one installment/cheque and create a linked billing item slice.
     *
     * @return array<string,mixed>
     */
    function re_service_charge_apply_amount_to_installment(
        PDO $conn,
        int $companyId,
        int $leaseId,
        int $serviceChargeId,
        array $charge,
        array $installment,
        float $sliceAmount,
        string $noteSuffix,
        string $auditReason,
        ?int $userId = null
    ): array {
        $sliceAmount = round($sliceAmount, 2);
        if ($sliceAmount <= 0) {
            return ['success' => false, 'error' => 'Split slice amount must be greater than zero.'];
        }

        $installmentId = (int)$installment['id'];
        $instDate = (string)$installment['installment_date'];

        $exists = $conn->prepare("
            SELECT id FROM re_billing_items
            WHERE company_id = ? AND lease_id = ? AND service_charge_id = ? AND installment_id = ?
            LIMIT 1
        ");
        $exists->execute([$companyId, $leaseId, $serviceChargeId, $installmentId]);
        if ($exists->fetchColumn()) {
            return ['success' => true, 'already_applied' => true, 'installment_id' => $installmentId, 'slice_amount' => $sliceAmount];
        }

        $pdcStmt = $conn->prepare("
            SELECT id, cheque_number, cheque_amount, status
            FROM re_post_dated_cheques
            WHERE company_id = ? AND lease_id = ? AND installment_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $pdcStmt->execute([$companyId, $leaseId, $installmentId]);
        $pdc = $pdcStmt->fetch(PDO::FETCH_ASSOC);

        if ($pdc && !in_array((string)$pdc['status'], ['pending', 'overdue'], true)) {
            return ['success' => false, 'error' => 'Cheque for installment #' . $installmentId . ' is not pending and cannot be increased.'];
        }

        $newInstAmount = round((float)$installment['amount'] + $sliceAmount, 2);
        $conn->prepare("
            UPDATE re_lease_installments
            SET amount = ?,
                notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
            WHERE id = ? AND lease_id = ? AND company_id = ?
        ")->execute([
            $newInstAmount,
            'Includes service charge #' . $serviceChargeId . ' (' . (string)($charge['charge_name'] ?? 'service') . ') ' . number_format($sliceAmount, 2) . ' AED' . $noteSuffix,
            $installmentId,
            $leaseId,
            $companyId,
        ]);

        $chequeId = null;
        if ($pdc) {
            $chequeId = (int)$pdc['id'];
            $newChequeAmount = round((float)$pdc['cheque_amount'] + $sliceAmount, 2);
            $conn->prepare("
                UPDATE re_post_dated_cheques
                SET cheque_amount = ?,
                    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?)),
                    updated_at = NOW()
                WHERE id = ? AND company_id = ? AND lease_id = ?
            ")->execute([
                $newChequeAmount,
                'Includes service charge: ' . (string)($charge['charge_name'] ?? 'service') . ' +' . number_format($sliceAmount, 2) . ' AED',
                $chequeId,
                $companyId,
                $leaseId,
            ]);
            if (function_exists('re_payment_schedule_log_change')) {
                $modeStmt = $conn->prepare("SELECT COALESCE(accounting_mode,'legacy') FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
                $modeStmt->execute([$leaseId, $companyId]);
                $acctMode = (string)$modeStmt->fetchColumn();
                re_payment_schedule_log_change(
                    $conn,
                    $companyId,
                    $leaseId,
                    $installmentId,
                    $chequeId,
                    'cheque_amount',
                    (float)$pdc['cheque_amount'],
                    $newChequeAmount,
                    $userId,
                    $auditReason,
                    'billing_service_charges',
                    $acctMode
                );
            }
        }

        $conn->prepare("
            INSERT INTO re_billing_items
                (company_id, lease_id, item_type, item_name, item_description, amount,
                 quantity, unit_price, total_amount, billing_period_start, billing_period_end,
                 billing_date, due_date, service_charge_id, installment_id, status, notes, created_by)
            VALUES (?, ?, 'service_charge', ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ")->execute([
            $companyId,
            $leaseId,
            (string)($charge['charge_name'] ?? 'Service Charge'),
            (string)($charge['charge_description'] ?? 'Applied to installment/cheque'),
            $sliceAmount,
            $sliceAmount,
            $sliceAmount,
            $instDate,
            $instDate,
            $instDate,
            $instDate,
            $serviceChargeId,
            $installmentId,
            'Applied to installment #' . $installmentId . ($pdc ? ' / cheque ' . (string)($pdc['cheque_number'] ?? '') : '') . '.',
            $userId,
        ]);

        return [
            'success' => true,
            'installment_id' => $installmentId,
            'cheque_id' => $chequeId,
            'new_installment_amount' => $newInstAmount,
            'cheque_number' => $pdc['cheque_number'] ?? null,
            'slice_amount' => $sliceAmount,
        ];
    }
}

if (!function_exists('re_service_charge_merge_into_first_installment')) {
    /**
     * Add a one-time service charge amount to the first open installment and its cheque.
     *
     * @return array<string,mixed>
     */
    function re_service_charge_merge_into_first_installment(
        PDO $conn,
        int $companyId,
        int $leaseId,
        int $serviceChargeId,
        array $charge,
        ?int $userId = null
    ): array {
        re_service_charge_ensure_apply_mode_column($conn);

        $amount = round((float)($charge['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Charge amount must be greater than zero.'];
        }

        $installment = re_service_charge_find_first_open_installment($conn, $companyId, $leaseId);
        if (!$installment) {
            return ['success' => false, 'error' => 'No open first installment found to merge this charge into.'];
        }

        $result = re_service_charge_apply_amount_to_installment(
            $conn,
            $companyId,
            $leaseId,
            $serviceChargeId,
            $charge,
            $installment,
            $amount,
            ' merged into this row.',
            'Service charge #' . $serviceChargeId . ' merged into first open cheque',
            $userId
        );
        if (empty($result['success'])) {
            return $result;
        }

        lease_sync_cheque_amounts($conn, $leaseId, $companyId);

        $conn->prepare("
            UPDATE re_service_charges
            SET apply_mode = 'merge_first_installment', updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$serviceChargeId, $companyId]);

        return [
            'success' => true,
            'installment_id' => (int)$result['installment_id'],
            'cheque_id' => $result['cheque_id'] ?? null,
            'new_installment_amount' => (float)($result['new_installment_amount'] ?? 0),
            'cheque_number' => $result['cheque_number'] ?? null,
        ];
    }
}

if (!function_exists('re_service_charge_split_across_installments')) {
    /**
     * Split a service charge total evenly across all open installments/cheques.
     *
     * @return array<string,mixed>
     */
    function re_service_charge_split_across_installments(
        PDO $conn,
        int $companyId,
        int $leaseId,
        int $serviceChargeId,
        array $charge,
        ?int $userId = null
    ): array {
        re_service_charge_ensure_apply_mode_column($conn);

        $leaseStmt = $conn->prepare("SELECT end_date FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $leaseStmt->execute([$leaseId, $companyId]);
        $leaseEndDate = $leaseStmt->fetchColumn() ?: null;

        $total = re_service_charge_total_for_cheque_split($charge, $leaseEndDate ? (string)$leaseEndDate : null);
        if ($total <= 0) {
            return ['success' => false, 'error' => 'Charge amount must be greater than zero.'];
        }

        $installments = re_service_charge_find_open_installments($conn, $companyId, $leaseId);
        if ($installments === []) {
            return ['success' => false, 'error' => 'No open installments found to split this charge across.'];
        }

        $slices = re_service_charge_split_amounts($total, count($installments));
        $applied = [];
        $chequeNumbers = [];

        foreach ($installments as $index => $installment) {
            $slice = (float)($slices[$index] ?? 0);
            if ($slice <= 0) {
                continue;
            }
            $result = re_service_charge_apply_amount_to_installment(
                $conn,
                $companyId,
                $leaseId,
                $serviceChargeId,
                $charge,
                $installment,
                $slice,
                ' split across cheques.',
                'Service charge #' . $serviceChargeId . ' split across open cheques (slice ' . number_format($slice, 2) . ' AED)',
                $userId
            );
            if (empty($result['success'])) {
                return $result;
            }
            $applied[] = [
                'installment_id' => (int)$result['installment_id'],
                'cheque_number' => $result['cheque_number'] ?? null,
                'slice_amount' => $slice,
                'new_installment_amount' => (float)($result['new_installment_amount'] ?? 0),
            ];
            if (!empty($result['cheque_number'])) {
                $chequeNumbers[] = (string)$result['cheque_number'];
            }
            // Refresh amount for next iteration if same installment somehow repeated (shouldn't happen)
            $installment['amount'] = (float)($result['new_installment_amount'] ?? $installment['amount']);
        }

        lease_sync_cheque_amounts($conn, $leaseId, $companyId);

        $conn->prepare("
            UPDATE re_service_charges
            SET apply_mode = 'split_all_installments', updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([$serviceChargeId, $companyId]);

        $avgSlice = count($applied) > 0 ? round($total / count($applied), 2) : 0.0;

        return [
            'success' => true,
            'total_amount' => $total,
            'installment_count' => count($applied),
            'average_slice' => $avgSlice,
            'slices' => $applied,
            'cheque_numbers' => $chequeNumbers,
        ];
    }
}

if (!function_exists('re_service_charge_schedule_adjustment_total')) {
    /**
     * Extra service charges merged into installment/cheque rows are part of the
     * operational schedule but not in lease contract fields — add them so
     * Operational Collection Comparison stays balanced.
     */
    function re_service_charge_schedule_adjustment_total(PDO $conn, int $companyId, int $leaseId): float
    {
        if (!re_service_charge_table_has_column($conn, 'apply_mode')) {
            return 0.0;
        }
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM re_service_charges
            WHERE company_id = ?
              AND lease_id = ?
              AND is_active = 1
              AND apply_mode IN ('merge_first_installment', 'split_all_installments')
        ");
        $stmt->execute([$companyId, $leaseId]);
        return round((float)$stmt->fetchColumn(), 2);
    }
}

if (!function_exists('re_service_charge_sync_invoice_mode_obligations')) {
    function re_service_charge_sync_invoice_mode_obligations(PDO $conn, int $companyId, int $leaseId, ?int $userId): void
    {
        $modeStmt = $conn->prepare("SELECT COALESCE(accounting_mode,'legacy') FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $modeStmt->execute([$leaseId, $companyId]);
        if ((string)$modeStmt->fetchColumn() !== 'invoice') {
            return;
        }
        require_once __DIR__ . '/obligation_engine.php';
        require_once __DIR__ . '/invoice_engine.php';
        if (function_exists('re_obligation_engine_generate_for_lease')) {
            re_obligation_engine_generate_for_lease($conn, $companyId, $leaseId, $userId);
        }
        if (function_exists('re_invoice_engine_prepare_candidates_for_lease')) {
            re_invoice_engine_prepare_candidates_for_lease($conn, $companyId, $leaseId, $userId);
        }
    }
}
