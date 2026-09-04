<?php
/**
 * Operational lease payment / cheque schedule helpers.
 *
 * These helpers do not create invoices, receipts, journals, or recognition rows.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/module_access.php';
require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
require_once __DIR__ . '/lease_vat_calculator.php';

if (!function_exists('re_payment_schedule_setting')) {
    function re_payment_schedule_setting(PDO $conn, string $key, string $default = ''): string
    {
        try {
            $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : (string)$value;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('re_payment_schedule_validation_mode')) {
    function re_payment_schedule_validation_mode(PDO $conn): string
    {
        $mode = re_payment_schedule_setting($conn, 're_payment_schedule_validation_mode', 'authorized_override');
        return in_array($mode, ['strict', 'authorized_override', 'warning_only'], true) ? $mode : 'authorized_override';
    }
}

if (!function_exists('re_payment_schedule_can_override_mismatch')) {
    function re_payment_schedule_can_override_mismatch(PDO $conn): bool
    {
        return has_role('Owner', $conn)
            || has_role('Admin', $conn)
            || has_role('Accountant', $conn)
            || has_role('Account', $conn)
            || has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
    }
}

if (!function_exists('re_payment_schedule_normalize_method')) {
    function re_payment_schedule_normalize_method(?string $method): string
    {
        $method = (string)$method;
        return in_array($method, ['cheque', 'bank_transfer', 'cash', 'card', 'other'], true) ? $method : 'cheque';
    }
}

if (!function_exists('re_payment_schedule_expected_breakdown')) {
    /**
     * @return array<string,float>
     */
    function re_payment_schedule_expected_breakdown(array $leaseLike): array
    {
        $annualRent = round((float)($leaseLike['annual_rent'] ?? 0), 2);
        $securityDeposit = !empty($leaseLike['is_renewal_lease']) ? 0.0 : round((float)($leaseLike['security_deposit'] ?? 0), 2);
        $chillerFees = round((float)($leaseLike['chiller_fees'] ?? 0), 2);
        $ejariFees = round((float)($leaseLike['ejari_fees'] ?? 0), 2);
        $adminFees = round((float)($leaseLike['admin_fees'] ?? 0), 2);
        $commissionFees = round((float)($leaseLike['commission_fees'] ?? 0), 2);
        $vatRate = (float)($leaseLike['lease_vat_rate'] ?? 5.0);
        if ($vatRate <= 0) {
            $vatRate = 5.0;
        }

        $rentVat = !empty($leaseLike['vat_applicable_on_rent'])
            ? round($annualRent * ($vatRate / 100), 2)
            : 0.0;
        $extraVatEnabled = !array_key_exists('vat_applicable_on_extra_charges', $leaseLike)
            || !empty($leaseLike['vat_applicable_on_extra_charges']);
        $adminVat = $extraVatEnabled ? round($adminFees * ($vatRate / 100), 2) : 0.0;
        $commissionVat = $extraVatEnabled ? round($commissionFees * ($vatRate / 100), 2) : 0.0;
        $chillerVat = $extraVatEnabled ? round($chillerFees * ($vatRate / 100), 2) : 0.0;

        $breakdown = [
            'annual_rent' => $annualRent,
            'rent_vat' => $rentVat,
            'security_deposit' => $securityDeposit,
            'chiller_fees' => $chillerFees,
            'chiller_vat' => $chillerVat,
            'ejari_fees' => $ejariFees,
            'admin_fees' => $adminFees,
            'admin_vat' => $adminVat,
            'commission_fees' => $commissionFees,
            'commission_vat' => $commissionVat,
            'other_core_charges' => 0.0,
        ];
        $breakdown['total_expected'] = round(array_sum($breakdown), 2);
        return $breakdown;
    }
}

if (!function_exists('re_payment_schedule_sum_posted_rows')) {
    function re_payment_schedule_sum_posted_rows(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += max(0.0, (float)($row['amount'] ?? 0));
        }
        return round($total, 2);
    }
}

if (!function_exists('re_payment_schedule_validate_totals')) {
    /**
     * @return array{valid:bool,requires_approval:bool,error:string,expected_total:float,scheduled_total:float,difference:float,mode:string}
     */
    function re_payment_schedule_validate_totals(PDO $conn, float $expectedTotal, float $scheduledTotal, bool $approvalChecked, string $reason): array
    {
        $expectedTotal = round($expectedTotal, 2);
        $scheduledTotal = round($scheduledTotal, 2);
        $difference = round($expectedTotal - $scheduledTotal, 2);
        $mode = re_payment_schedule_validation_mode($conn);
        if (abs($difference) <= 0.02) {
            return ['valid' => true, 'requires_approval' => false, 'error' => '', 'expected_total' => $expectedTotal, 'scheduled_total' => $scheduledTotal, 'difference' => $difference, 'mode' => $mode];
        }
        if ($mode === 'warning_only') {
            return ['valid' => true, 'requires_approval' => false, 'error' => '', 'expected_total' => $expectedTotal, 'scheduled_total' => $scheduledTotal, 'difference' => $difference, 'mode' => $mode];
        }
        if ($mode === 'strict') {
            return ['valid' => false, 'requires_approval' => false, 'error' => 'The total scheduled payments must match the expected lease collection total before saving.', 'expected_total' => $expectedTotal, 'scheduled_total' => $scheduledTotal, 'difference' => $difference, 'mode' => $mode];
        }
        if (!re_payment_schedule_can_override_mismatch($conn)) {
            return ['valid' => false, 'requires_approval' => true, 'error' => 'The total scheduled payments do not match the expected lease collection total. An authorized approval is required.', 'expected_total' => $expectedTotal, 'scheduled_total' => $scheduledTotal, 'difference' => $difference, 'mode' => $mode];
        }
        if (!$approvalChecked || trim($reason) === '') {
            return ['valid' => false, 'requires_approval' => true, 'error' => 'The total scheduled payments do not match the expected lease collection total. Please approve the mismatch and enter a reason before saving.', 'expected_total' => $expectedTotal, 'scheduled_total' => $scheduledTotal, 'difference' => $difference, 'mode' => $mode];
        }
        return ['valid' => true, 'requires_approval' => true, 'error' => '', 'expected_total' => $expectedTotal, 'scheduled_total' => $scheduledTotal, 'difference' => $difference, 'mode' => $mode];
    }
}

if (!function_exists('re_payment_schedule_row_locked')) {
    function re_payment_schedule_row_locked(PDO $conn, array $row): bool
    {
        $status = (string)($row['status'] ?? $row['cheque_status'] ?? '');
        if (in_array($status, ['cleared', 'returned'], true)) {
            return true;
        }
        if (!empty($row['payment_id'])) {
            try {
                $stmt = $conn->prepare("SELECT 1 FROM re_payments WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$row['payment_id']]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            } catch (Throwable $e) {}
        }
        if (!empty($row['invoice_id'])) {
            try {
                $stmt = $conn->prepare("SELECT 1 FROM re_invoices WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$row['invoice_id']]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            } catch (Throwable $e) {}
        }
        try {
            $instId = (int)($row['installment_id'] ?? $row['id'] ?? 0);
            if ($instId > 0) {
                $stmt = $conn->prepare("SELECT 1 FROM re_payment_allocations WHERE installment_id = ? LIMIT 1");
                $stmt->execute([$instId]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            }
        } catch (Throwable $e) {}
        try {
            $chequeId = (int)($row['cheque_id'] ?? 0);
            if ($chequeId > 0) {
                $stmt = $conn->prepare("SELECT 1 FROM re_legal_cheque_escalations WHERE cheque_id = ? LIMIT 1");
                $stmt->execute([$chequeId]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            }
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('re_payment_schedule_log_change')) {
    function re_payment_schedule_log_change(PDO $conn, int $companyId, int $leaseId, ?int $rowId, ?int $chequeId, string $field, $oldValue, $newValue, ?int $userId, string $reason, string $source, string $accountingMode): void
    {
        if ((string)$oldValue === (string)$newValue) {
            return;
        }
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_lease_payment_schedule_audit
                    (company_id, lease_id, schedule_row_id, cheque_id, field_name, old_value, new_value, changed_by, reason, source, accounting_mode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$companyId, $leaseId, $rowId, $chequeId, $field, (string)$oldValue, (string)$newValue, $userId, $reason ?: null, $source, $accountingMode === 'invoice' ? 'invoice' : 'legacy']);
        } catch (Throwable $e) {
            error_log('Payment schedule audit failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('re_payment_schedule_log_mismatch_approval')) {
    function re_payment_schedule_log_mismatch_approval(PDO $conn, int $companyId, int $leaseId, float $expectedTotal, float $scheduledTotal, float $difference, ?int $userId, string $reason, string $source, string $accountingMode): void
    {
        if (abs($difference) <= 0.02) {
            return;
        }
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_lease_payment_schedule_mismatch_approvals
                    (company_id, lease_id, expected_total, scheduled_total, difference_amount, approved_by, reason, source_page, accounting_mode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$companyId, $leaseId, $expectedTotal, $scheduledTotal, $difference, $userId, $reason, $source, $accountingMode === 'invoice' ? 'invoice' : 'legacy']);
        } catch (Throwable $e) {
            error_log('Payment schedule mismatch approval audit failed: ' . $e->getMessage());
        }
    }
}

