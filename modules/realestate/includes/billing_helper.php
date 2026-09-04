<?php
/**
 * Billing Helper Functions
 * Utility functions for billing operations
 */

if (!function_exists('generate_invoice_number')) {
    /**
     * Generate unique invoice number
     * Format: INV-YYYY-XXXXX (e.g., INV-2025-00001)
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param string $prefix Invoice prefix (default: 'INV')
     * @return string Invoice number
     */
    function generate_invoice_number(PDO $conn, int $companyId, string $prefix = 'INV'): string {
        $year = date('Y');
        
        // Get or create sequence for this year
        $stmt = $conn->prepare("
            SELECT sequence_number FROM re_invoice_sequences 
            WHERE company_id = ? AND year = ?
        ");
        $stmt->execute([$companyId, $year]);
        $sequence = $stmt->fetchColumn();
        
        if ($sequence === false) {
            // Create new sequence
            $stmt = $conn->prepare("
                INSERT INTO re_invoice_sequences (company_id, year, prefix, sequence_number)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$companyId, $year, $prefix]);
            $sequence = 0;
        }
        
        // Increment sequence
        $stmt = $conn->prepare("
            UPDATE re_invoice_sequences 
            SET sequence_number = sequence_number + 1
            WHERE company_id = ? AND year = ?
        ");
        $stmt->execute([$companyId, $year]);
        
        // Generate invoice number
        $sequenceNumber = $sequence + 1;
        return sprintf('%s-%s-%05d', $prefix, $year, $sequenceNumber);
    }
}

if (!function_exists('calculate_penalty')) {
    /**
     * Calculate penalty amount based on rule
     * 
     * @param array $penaltyRule Penalty rule from database
     * @param float $baseAmount Base amount to calculate penalty on
     * @param int $daysOverdue Days overdue (for per_day calculation)
     * @return float Penalty amount
     */
    function calculate_penalty(array $penaltyRule, float $baseAmount, int $daysOverdue = 0): float {
        $gracePeriod = (int)($penaltyRule['grace_period_days'] ?? 0);
        $effectiveDays = max(0, $daysOverdue - $gracePeriod);
        
        if ($effectiveDays <= 0 && $penaltyRule['penalty_type'] === 'late_payment') {
            return 0; // Within grace period
        }
        
        $penalty = 0;
        
        switch ($penaltyRule['calculation_method']) {
            case 'fixed':
                $penalty = (float)($penaltyRule['amount'] ?? 0);
                break;
                
            case 'percentage':
                $percentage = (float)($penaltyRule['percentage'] ?? 0);
                $penalty = ($baseAmount * $percentage) / 100;
                break;
                
            case 'per_day':
                $perDay = (float)($penaltyRule['per_day_amount'] ?? 0);
                $penalty = $perDay * $effectiveDays;
                break;
        }
        
        // Apply max penalty cap if set
        if (!empty($penaltyRule['max_penalty_amount'])) {
            $maxPenalty = (float)$penaltyRule['max_penalty_amount'];
            $penalty = min($penalty, $maxPenalty);
        }
        
        return round($penalty, 2);
    }
}

if (!function_exists('create_billing_item_from_service_charge')) {
    /**
     * Create billing item from recurring service charge
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param array $serviceCharge Service charge record
     * @param string $billingPeriodStart Billing period start date
     * @param string $billingPeriodEnd Billing period end date
     * @param string $dueDate Due date
     * @return int|null Billing item ID or null on failure
     */
    function create_billing_item_from_service_charge(
        PDO $conn,
        int $companyId,
        array $serviceCharge,
        string $billingPeriodStart,
        string $billingPeriodEnd,
        string $dueDate
    ): ?int {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_billing_items
                (company_id, lease_id, item_type, item_name, item_description, amount, 
                 quantity, unit_price, total_amount, billing_period_start, billing_period_end, 
                 due_date, service_charge_id)
                VALUES (?, ?, 'service_charge', ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $serviceCharge['lease_id'],
                $serviceCharge['charge_name'],
                $serviceCharge['charge_description'] ?? '',
                $serviceCharge['amount'],
                $serviceCharge['amount'],
                $serviceCharge['amount'],
                $billingPeriodStart,
                $billingPeriodEnd,
                $dueDate,
                $serviceCharge['id']
            ]);
            return $conn->lastInsertId();
        } catch (Exception $e) {
            error_log("Error creating billing item from service charge: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('create_penalty_billing_item')) {
    /**
     * Create billing item for penalty
     * 
     * @param PDO $conn Database connection
     * @param int $companyId Company ID
     * @param int $leaseId Lease ID
     * @param array $penaltyRule Penalty rule
     * @param float $baseAmount Base amount for penalty calculation
     * @param int $daysOverdue Days overdue
     * @param string $dueDate Due date
     * @param int|null $installmentId Linked installment ID
     * @return int|null Billing item ID or null on failure
     */
    function create_penalty_billing_item(
        PDO $conn,
        int $companyId,
        int $leaseId,
        array $penaltyRule,
        float $baseAmount,
        int $daysOverdue,
        string $dueDate,
        ?int $installmentId = null
    ): ?int {
        $penaltyAmount = calculate_penalty($penaltyRule, $baseAmount, $daysOverdue);
        
        if ($penaltyAmount <= 0) {
            return null; // No penalty to apply
        }
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_billing_items
                (company_id, lease_id, item_type, item_name, item_description, amount, 
                 quantity, unit_price, total_amount, billing_date, due_date, penalty_rule_id, installment_id)
                VALUES (?, ?, 'penalty', ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $leaseId,
                $penaltyRule['rule_name'],
                $penaltyRule['rule_description'] ?? '',
                $penaltyAmount,
                $penaltyAmount,
                $penaltyAmount,
                date('Y-m-d'),
                $dueDate,
                $penaltyRule['id'],
                $installmentId
            ]);
            return $conn->lastInsertId();
        } catch (Exception $e) {
            error_log("Error creating penalty billing item: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('re_generate_lease_penalties')) {
    /**
     * Generate Late-Fee and Bounced-Cheque penalty billing items for a lease.
     *
     * This is the writing counterpart that used to run on every lease_view GET.
     * It is now invoked explicitly (Repair action, save, or cron) so that viewing
     * a lease never mutates data. Idempotent: it skips installments that already
     * have a matching penalty billing item.
     *
     * @return int Number of penalty items created.
     */
    function re_generate_lease_penalties(PDO $conn, int $companyId, int $leaseId): int
    {
        $created = 0;
        $today = date('Y-m-d');

        // Load installments with their linked cheque status.
        $stmt = $conn->prepare("
            SELECT li.id, li.installment_date, li.amount, li.status,
                   c.cheque_amount, c.status AS cheque_status
            FROM re_lease_installments li
            LEFT JOIN re_post_dated_cheques c
                ON c.installment_id = li.id AND c.lease_id = li.lease_id
            WHERE li.lease_id = ? AND li.company_id = ?
        ");
        $stmt->execute([$leaseId, $companyId]);
        $installments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$installments) {
            return 0;
        }

        // Late payment penalties.
        $lateRule = $conn->prepare("SELECT * FROM re_penalty_rules WHERE company_id = ? AND penalty_type = 'late_payment' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $lateRule->execute([$companyId]);
        $lateRule = $lateRule->fetch(PDO::FETCH_ASSOC);
        if ($lateRule) {
            foreach ($installments as $inst) {
                $instStatus = (string)$inst['status'];
                $instDate = (string)($inst['installment_date'] ?? '');
                if (($inst['cheque_status'] ?? '') === 'returned') {
                    continue;
                }
                if (($instStatus === 'pending' || $instStatus === 'overdue') && $instDate && $instDate < $today) {
                    $exists = $conn->prepare("SELECT 1 FROM re_billing_items WHERE lease_id = ? AND installment_id = ? AND penalty_rule_id = ? AND company_id = ? LIMIT 1");
                    $exists->execute([$leaseId, $inst['id'], $lateRule['id'], $companyId]);
                    if (!$exists->fetchColumn()) {
                        $daysOverdue = (int)((strtotime($today) - strtotime($instDate)) / 86400);
                        if (create_penalty_billing_item($conn, $companyId, $leaseId, $lateRule, (float)$inst['amount'], $daysOverdue, $today, (int)$inst['id'])) {
                            $created++;
                        }
                    }
                }
            }
        }

        // Bounced cheque penalties.
        $bouncedRule = $conn->prepare("SELECT * FROM re_penalty_rules WHERE company_id = ? AND penalty_type = 'bounced_cheque' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $bouncedRule->execute([$companyId]);
        $bouncedRule = $bouncedRule->fetch(PDO::FETCH_ASSOC);
        if ($bouncedRule) {
            foreach ($installments as $inst) {
                if (($inst['cheque_status'] ?? '') === 'bounced' && !empty($inst['id'])) {
                    $exists = $conn->prepare("SELECT 1 FROM re_billing_items WHERE lease_id = ? AND installment_id = ? AND penalty_rule_id = ? AND company_id = ? LIMIT 1");
                    $exists->execute([$leaseId, $inst['id'], $bouncedRule['id'], $companyId]);
                    if (!$exists->fetchColumn()) {
                        $baseAmount = (float)($inst['cheque_amount'] ?? $inst['amount']);
                        if (create_penalty_billing_item($conn, $companyId, $leaseId, $bouncedRule, $baseAmount, 0, $today, (int)$inst['id'])) {
                            $created++;
                        }
                    }
                }
            }
        }

        return $created;
    }
}

