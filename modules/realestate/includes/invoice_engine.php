<?php
/**
 * Phase 3 invoice candidate / issuance engine.
 *
 * Candidate preparation:
 * - writes only to re_invoice_candidates
 * - does not assign invoice numbers
 * - does not create invoices
 *
 * Issuance:
 * - issues only approved candidates whose eligible_on <= CURRENT_DATE
 * - assigns official invoice numbers only at issuance time
 * - creates re_invoices / re_invoice_items linked to the source obligation
 *
 * Invoice issuance is the Phase 3 monthly revenue recognition event for
 * Invoice Mode leases: Dr AR / Cr Rent Revenue / Cr VAT Output.
 * No receipts, payments, cheques, allocations, tenant notifications, or reports
 * are created here.
 */
declare(strict_types=1);

require_once __DIR__ . '/obligation_engine.php';
require_once __DIR__ . '/lease_vat_calculator.php';
require_once __DIR__ . '/billing_helper.php';
require_once __DIR__ . '/re_income_account_roles.php';
require_once __DIR__ . '/../accounting/accounting_integration.php';

const RE_INVOICE_ENGINE_VERSION = 'phase3.2';
const RE_INVOICE_CANDIDATE_SOURCE = 'invoice_candidate_phase3';
const RE_INVOICE_ISSUANCE_SOURCE = 'invoice_issuance_phase3';
const RE_INVOICE_REJECTED_SOURCE = 'obligation_engine_phase3';

if (!function_exists('re_invoice_engine_money')) {
    function re_invoice_engine_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_invoice_engine_schema_ready')) {
    function re_invoice_engine_schema_ready(PDO $conn): bool
    {
        return re_obligation_table_exists($conn, 're_invoices')
            && re_obligation_table_exists($conn, 're_invoice_items')
            && re_obligation_table_exists($conn, 're_invoice_sequences')
            && re_obligation_table_exists($conn, 're_invoice_candidates')
            && re_obligation_column_exists($conn, 're_invoices', 'invoice_key')
            && re_obligation_column_exists($conn, 're_invoices', 'invoice_source')
            && re_obligation_column_exists($conn, 're_invoices', 'engine_version')
            && re_obligation_column_exists($conn, 're_invoices', 'generated_at')
            && re_obligation_column_exists($conn, 're_invoices', 'generated_by')
            && re_obligation_column_exists($conn, 're_invoice_items', 'obligation_id')
            && re_obligation_column_exists($conn, 're_obligations', 'invoice_id');
    }
}

if (!function_exists('re_invoice_engine_candidate_key')) {
    function re_invoice_engine_candidate_key(int $obligationId): string
    {
        return 'phase3:candidate:obligation:' . $obligationId;
    }
}

if (!function_exists('re_invoice_engine_invoice_key')) {
    function re_invoice_engine_invoice_key(int $candidateId): string
    {
        return 'phase3:issued:candidate:' . $candidateId;
    }
}

if (!function_exists('re_invoice_engine_load_invoice_mode_lease')) {
    /**
     * @return array<string,mixed>
     */
    function re_invoice_engine_load_invoice_mode_lease(PDO $conn, int $companyId, int $leaseId): array
    {
        $lease = re_obligation_preview_load_lease($conn, $companyId, $leaseId);
        if (!$lease) {
            return ['success' => false, 'error' => 'Lease not found.'];
        }

        $featureEnabled = re_accounting_phase1_feature_enabled($conn);
        $mode = re_accounting_mode_for_lease($conn, $lease);
        if (!$featureEnabled) {
            return ['success' => false, 'error' => 'Invoice Mode feature flag is disabled.', 'lease' => $lease, 'accounting_mode' => $mode];
        }
        if ($mode !== 'invoice') {
            return ['success' => false, 'error' => 'This lease is still in Legacy accounting mode. No invoice candidates can be prepared.', 'lease' => $lease, 'accounting_mode' => $mode];
        }
        if (!re_invoice_engine_schema_ready($conn)) {
            return ['success' => false, 'error' => 'Phase 3 invoice candidate migration has not been applied.', 'lease' => $lease, 'accounting_mode' => $mode];
        }

        return [
            'success' => true,
            'lease' => $lease,
            'feature_enabled' => $featureEnabled,
            'accounting_mode' => $mode,
        ];
    }
}

if (!function_exists('re_invoice_engine_candidate_source_obligations')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_invoice_engine_candidate_source_obligations(PDO $conn, int $companyId, int $leaseId): array
    {
        if (!re_obligation_engine_schema_ready($conn)) {
            return [];
        }

        $candidateJoin = re_obligation_table_exists($conn, 're_invoice_candidates')
            ? "LEFT JOIN re_invoice_candidates c ON c.obligation_id = o.id AND c.company_id = o.company_id AND c.status NOT IN ('cancelled','void')"
            : '';
        $candidateFilter = re_obligation_table_exists($conn, 're_invoice_candidates')
            ? 'AND c.id IS NULL'
            : '';

        $stmt = $conn->prepare("
            SELECT o.*
            FROM re_obligations o
            {$candidateJoin}
            WHERE o.company_id = ?
              AND o.lease_id = ?
              AND o.obligation_key IS NOT NULL
              AND o.generated_at IS NOT NULL
              AND o.invoice_id IS NULL
              AND o.status = 'open'
              AND o.accounting_class <> 'liability'
              AND o.obligation_type <> 'security_deposit'
              AND o.total_amount > 0
              AND NOT EXISTS (
                    SELECT 1
                    FROM re_invoice_items ii
                    INNER JOIN re_invoices i ON i.id = ii.invoice_id AND i.company_id = ii.company_id
                    WHERE ii.company_id = o.company_id
                      AND ii.obligation_id = o.id
                      AND i.status <> 'cancelled'
              )
              {$candidateFilter}
            ORDER BY o.due_date ASC, o.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_invoice_engine_line_from_obligation')) {
    /**
     * VAT is calculated at issuance/preview time, not at future candidate creation.
     *
     * @return array<string,mixed>
     */
    function re_invoice_engine_line_from_obligation(array $lease, array $obligation): array
    {
        $type = (string)($obligation['obligation_type'] ?? 'other');
        $subtotal = re_invoice_engine_money($obligation['subtotal_amount'] ?? 0);
        $taxRate = (float)($obligation['tax_rate'] ?? 0);
        $taxAmount = re_invoice_engine_money($obligation['vat_amount'] ?? 0);
        $taxTreatment = (string)($obligation['tax_treatment'] ?? 'exempt');
        $unitType = (string)($lease['unit_type'] ?? '');
        $taxLabel = 'VAT Exempt';

        if ($type === 'vat') {
            // Separate VAT cheque / obligation_type=vat: face is tax-only (subtotal may be 0).
            // Do not recompute tax as subtotal * rate — that would zero the line.
            $taxAmount = re_invoice_engine_money($obligation['vat_amount'] ?? $obligation['total_amount'] ?? 0);
            if ($taxAmount <= 0.005 && $subtotal > 0.005) {
                $taxAmount = $subtotal;
                $subtotal = 0.0;
            }
            $taxRate = $taxRate > 0 ? $taxRate : 5.0;
            $taxTreatment = 'standard';
            $taxLabel = re_invoice_engine_money($taxRate) . '% VAT (separate)';
        } elseif ($type === 'rent') {
            if (lease_vat_unit_is_commercial($unitType)) {
                $taxRate = 5.0;
                $taxAmount = re_invoice_engine_money($obligation['vat_amount'] ?? ($subtotal * 0.05));
                $taxTreatment = 'standard';
                $taxLabel = '5% VAT';
            } else {
                $taxRate = 0.0;
                $taxAmount = 0.0;
                $taxTreatment = 'exempt';
                $taxLabel = 'VAT Exempt';
            }
        } elseif ($taxTreatment === 'standard' && $taxRate > 0) {
            $taxAmount = re_invoice_engine_money($subtotal * ($taxRate / 100));
            $taxLabel = re_invoice_engine_money($taxRate) . '% VAT';
        } elseif ($taxTreatment === 'out_of_scope') {
            $taxRate = 0.0;
            $taxAmount = 0.0;
            $taxLabel = 'Out of Scope';
        }

        $period = '';
        if (!empty($obligation['period_start']) || !empty($obligation['period_end'])) {
            $period = ' (' . trim((string)($obligation['period_start'] ?? '') . ' to ' . (string)($obligation['period_end'] ?? '')) . ')';
        }

        $name = match ($type) {
            'rent' => 'Monthly Rent' . $period,
            'vat' => 'Separate VAT',
            'admin_fee' => 'Admin Fee',
            'commission' => 'Commission Fee',
            'service' => ((string)($obligation['description'] ?? '') !== '' ? (string)$obligation['description'] : 'Service Charge') . $period,
            'parking' => 'Parking Fee' . $period,
            default => ((string)($obligation['description'] ?? '') !== '' ? (string)$obligation['description'] : ucwords(str_replace('_', ' ', $type))) . $period,
        };

        return [
            'obligation_id' => (int)($obligation['obligation_id'] ?? $obligation['id']),
            'eligible_on' => (string)($obligation['eligible_on'] ?? $obligation['due_date'] ?? date('Y-m-d')),
            'item_name' => $name,
            'item_description' => trim((string)($obligation['description'] ?? '') . ' - ' . $taxLabel),
            'billing_item_id' => ((string)($obligation['source_type'] ?? '') === 'billing_item' && !empty($obligation['source_id']))
                ? (int)$obligation['source_id']
                : null,
            'quantity' => 1,
            'unit_price' => $subtotal,
            'tax_rate' => re_invoice_engine_money($taxRate),
            'tax_amount' => $taxAmount,
            'line_total' => re_invoice_engine_money($subtotal + $taxAmount),
            'tax_treatment' => $taxTreatment,
            'tax_label' => $taxLabel,
            'obligation_type' => $type,
            'accounting_class' => (string)($obligation['accounting_class'] ?? ''),
        ];
    }
}

if (!function_exists('re_invoice_engine_attach_income_account')) {
    /**
     * Resolve and attach income_account_id using structured obligation classification.
     * VAT / liability lines skip income account (null).
     *
     * @param array<string,mixed> $lease
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    function re_invoice_engine_attach_income_account(PDO $conn, int $companyId, array $lease, array $line): array
    {
        $resolved = re_resolve_income_account_for_line($conn, $companyId, [
            'income_account_id' => $line['income_account_id'] ?? null,
            'obligation_type' => $line['obligation_type'] ?? null,
            'accounting_class' => $line['accounting_class'] ?? null,
            'unit_type' => (string)($lease['unit_type'] ?? ''),
            'item_name' => (string)($line['item_name'] ?? ''),
        ]);

        if (!empty($resolved['skip_income'])) {
            $line['income_account_id'] = null;
            $line['income_role'] = null;
            $line['income_unmapped'] = false;
            return $line;
        }

        if (empty($resolved['account']['id'])) {
            throw new RuntimeException($resolved['error'] ?: 'Unable to resolve income account for invoice line.');
        }

        $line['income_account_id'] = (int)$resolved['account']['id'];
        $line['income_role'] = $resolved['role'];
        $line['income_unmapped'] = !empty($resolved['unmapped']);
        return $line;
    }
}

if (!function_exists('re_invoice_engine_candidate_plan_for_lease')) {
    /**
     * @return array<string,mixed>
     */
    function re_invoice_engine_candidate_plan_for_lease(PDO $conn, int $companyId, int $leaseId): array
    {
        $ready = re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }

        $lease = $ready['lease'];
        $obligations = re_invoice_engine_candidate_source_obligations($conn, $companyId, $leaseId);
        $plans = [];
        $summary = ['subtotal' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'count' => 0, 'eligible_now' => 0, 'future' => 0];
        $today = date('Y-m-d');

        foreach ($obligations as $obligation) {
            $line = re_invoice_engine_line_from_obligation($lease, $obligation);
            $eligibleOn = (string)$line['eligible_on'];
            $plans[] = [
                'candidate_key' => re_invoice_engine_candidate_key((int)$obligation['id']),
                'eligible_on' => $eligibleOn,
                'obligation' => $obligation,
                'line' => $line,
                'subtotal' => $line['unit_price'],
                'tax_amount' => $line['tax_amount'],
                'total_amount' => $line['line_total'],
                'is_eligible_now' => $eligibleOn <= $today,
            ];
            $summary['subtotal'] += (float)$line['unit_price'];
            $summary['vat'] += (float)$line['tax_amount'];
            $summary['total'] += (float)$line['line_total'];
            $summary['count']++;
            $summary[$eligibleOn <= $today ? 'eligible_now' : 'future']++;
        }

        foreach ($summary as $key => $value) {
            $summary[$key] = in_array($key, ['count', 'eligible_now', 'future'], true) ? (int)$value : re_invoice_engine_money($value);
        }

        return [
            'success' => true,
            'feature_enabled' => $ready['feature_enabled'],
            'accounting_mode' => $ready['accounting_mode'],
            'lease' => $lease,
            'candidate_plans' => $plans,
            'summary' => $summary,
            'diagnostics' => re_invoice_engine_diagnostics($conn, $companyId, $leaseId),
        ];
    }
}

if (!function_exists('re_invoice_engine_prepare_candidates_for_lease')) {
    /**
     * @return array<string,mixed>
     */
    function re_invoice_engine_prepare_candidates_for_lease(PDO $conn, int $companyId, int $leaseId, ?int $userId = null): array
    {
        $planResult = re_invoice_engine_candidate_plan_for_lease($conn, $companyId, $leaseId);
        if (empty($planResult['success'])) {
            return $planResult;
        }

        $stats = ['created' => 0, 'already_exists' => 0, 'total_planned' => count($planResult['candidate_plans'] ?? [])];
        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }

        try {
            $insert = $conn->prepare("
                INSERT INTO re_invoice_candidates (
                    company_id, lease_id, obligation_id, candidate_key, eligible_on,
                    status, invoice_id, notes, engine_version,
                    prepared_by, prepared_at, approved_by, approved_at
                ) VALUES (?, ?, ?, ?, ?, 'approved', NULL, ?, ?, ?, NOW(), ?, NOW())
            ");

            foreach ($planResult['candidate_plans'] as $plan) {
                $obligationId = (int)$plan['obligation']['id'];
                $exists = $conn->prepare("
                    SELECT id, status, invoice_id
                    FROM re_invoice_candidates
                    WHERE company_id = ? AND obligation_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $exists->execute([$companyId, $obligationId]);
                $existingCand = $exists->fetch(PDO::FETCH_ASSOC);
                if ($existingCand) {
                    $candStatus = (string)($existingCand['status'] ?? '');
                    $linkedInvoiceId = (int)($existingCand['invoice_id'] ?? 0);
                    $linkedInvoiceCancelled = false;
                    if ($linkedInvoiceId > 0) {
                        $invSt = $conn->prepare("SELECT status FROM re_invoices WHERE id = ? AND company_id = ? LIMIT 1");
                        $invSt->execute([$linkedInvoiceId, $companyId]);
                        $linkedInvoiceCancelled = ((string)$invSt->fetchColumn() === 'cancelled');
                    }
                    // After void/repair, cancelled candidates for still-open obligations can be reopened.
                    if (in_array($candStatus, ['cancelled', 'void'], true)
                        && ($linkedInvoiceId <= 0 || $linkedInvoiceCancelled)
                    ) {
                        $conn->prepare("
                            UPDATE re_invoice_candidates
                            SET status = 'approved',
                                eligible_on = ?,
                                candidate_key = ?,
                                invoice_id = NULL,
                                notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, 'Re-approved after void/repair')),
                                approved_by = ?,
                                approved_at = NOW()
                            WHERE id = ? AND company_id = ?
                        ")->execute([
                            (string)$plan['eligible_on'],
                            (string)$plan['candidate_key'],
                            $userId,
                            (int)$existingCand['id'],
                            $companyId,
                        ]);
                        $stats['created']++;
                        continue;
                    }
                    $stats['already_exists']++;
                    continue;
                }

                $insert->execute([
                    $companyId,
                    $leaseId,
                    $obligationId,
                    (string)$plan['candidate_key'],
                    (string)$plan['eligible_on'],
                    'Prepared from obligation #' . $obligationId . '. Official invoice number is assigned only when issued.',
                    RE_INVOICE_ENGINE_VERSION,
                    $userId,
                    $userId,
                ]);
                $stats['created']++;
            }

            if ($startedHere) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => 'Could not prepare invoice candidates: ' . $e->getMessage(), 'stats' => $stats];
        }

        return [
            'success' => true,
            'stats' => $stats,
            'diagnostics' => re_invoice_engine_diagnostics($conn, $companyId, $leaseId),
        ];
    }
}

if (!function_exists('re_invoice_engine_issuable_candidates')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_invoice_engine_issuable_candidates(PDO $conn, int $companyId, int $leaseId): array
    {
        if (!re_invoice_engine_schema_ready($conn)) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT c.*, o.obligation_type, o.accounting_class, o.status AS obligation_status,
                   o.description, o.period_start, o.period_end, o.due_date,
                   o.tax_treatment, o.tax_rate, o.subtotal_amount, o.vat_amount, o.total_amount,
                   o.source_type, o.source_id
            FROM re_invoice_candidates c
            JOIN re_obligations o ON o.id = c.obligation_id AND o.company_id = c.company_id
            WHERE c.company_id = ?
              AND c.lease_id = ?
              AND c.status = 'approved'
              AND c.eligible_on <= CURDATE()
              AND c.invoice_id IS NULL
              AND o.invoice_id IS NULL
              AND o.status = 'open'
              AND o.accounting_class <> 'liability'
              AND o.obligation_type <> 'security_deposit'
              AND NOT EXISTS (
                    SELECT 1
                    FROM re_invoice_items ii
                    INNER JOIN re_invoices i ON i.id = ii.invoice_id AND i.company_id = ii.company_id
                    WHERE ii.company_id = o.company_id
                      AND ii.obligation_id = o.id
                      AND i.status <> 'cancelled'
              )
            ORDER BY c.eligible_on ASC, c.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_invoice_engine_insert_invoice')) {
    function re_invoice_engine_insert_invoice(PDO $conn, int $companyId, int $leaseId, int $candidateId, array $line, ?int $userId): int
    {
        $invoiceNumber = generate_invoice_number($conn, $companyId, 'INV');
        $invoiceKey = re_invoice_engine_invoice_key($candidateId);
        $issueDate = (string)($line['eligible_on'] ?? date('Y-m-d'));
        $notes = 'Issued by Phase 3 invoice issuance engine from candidate #' . $candidateId . ' / obligation #' . (int)$line['obligation_id'] . '. Revenue recognized on issuance.';

        $stmt = $conn->prepare("
            INSERT INTO re_invoices (
                company_id, lease_id, invoice_number, invoice_key, invoice_source,
                invoice_date, due_date, subtotal, tax_rate, tax_amount, discount_amount,
                total_amount, outstanding_amount, status, notes,
                engine_version, generated_at, generated_by, created_by
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, 0.00,
                ?, ?, 'sent', ?,
                ?, NOW(), ?, ?
            )
        ");
        $stmt->execute([
            $companyId,
            $leaseId,
            $invoiceNumber,
            $invoiceKey,
            RE_INVOICE_ISSUANCE_SOURCE,
            $issueDate,
            (string)$line['eligible_on'],
            (float)$line['unit_price'],
            (float)$line['tax_rate'],
            (float)$line['tax_amount'],
            (float)$line['line_total'],
            (float)$line['line_total'],
            $notes,
            RE_INVOICE_ENGINE_VERSION,
            $userId,
            $userId,
        ]);

        return (int)$conn->lastInsertId();
    }
}

if (!function_exists('re_invoice_engine_insert_line')) {
    function re_invoice_engine_insert_line(PDO $conn, int $companyId, int $invoiceId, array $line): void
    {
        $incomeAccountId = !empty($line['income_account_id']) ? (int)$line['income_account_id'] : null;
        $stmt = $conn->prepare("
            INSERT INTO re_invoice_items (
                company_id, invoice_id, billing_item_id, obligation_id,
                item_name, item_description, quantity, unit_price,
                tax_rate, tax_amount, line_total, display_order, income_account_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([
            $companyId,
            $invoiceId,
            $line['billing_item_id'],
            (int)$line['obligation_id'],
            (string)$line['item_name'],
            (string)$line['item_description'],
            (float)$line['quantity'],
            (float)$line['unit_price'],
            (float)$line['tax_rate'],
            (float)$line['tax_amount'],
            (float)$line['line_total'],
            $incomeAccountId,
        ]);
    }
}

if (!function_exists('re_invoice_engine_issue_eligible_for_lease')) {
    /**
     * @return array<string,mixed>
     */
    function re_invoice_engine_issue_eligible_for_lease(PDO $conn, int $companyId, int $leaseId, ?int $userId = null): array
    {
        $ready = re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }

        $candidates = re_invoice_engine_issuable_candidates($conn, $companyId, $leaseId);
        $stats = ['issued' => 0, 'skipped' => 0, 'failed' => 0, 'total_eligible' => count($candidates)];
        $errors = [];

        foreach ($candidates as $candidate) {
            $candidateId = (int)$candidate['id'];
            $result = re_invoice_engine_issue_candidate_for_lease($conn, $companyId, $leaseId, $candidateId, $userId);
            if (!empty($result['success'])) {
                $stats['issued']++;
                continue;
            }
            $stats['failed']++;
            $errors[] = '#' . $candidateId . ': ' . (string)($result['error'] ?? 'Unknown issue error');
        }

        // Candidates may have been concurrently issued or become non-issuable
        // between the initial list and per-candidate lock.
        $stats['skipped'] = max(0, $stats['total_eligible'] - $stats['issued'] - $stats['failed']);

        if ($stats['failed'] > 0) {
            return [
                'success' => false,
                'error' => 'Some eligible invoice candidates could not be issued: ' . implode(' | ', array_slice($errors, 0, 5)),
                'stats' => $stats,
                'diagnostics' => re_invoice_engine_diagnostics($conn, $companyId, $leaseId),
            ];
        }

        return [
            'success' => true,
            'stats' => $stats,
            'diagnostics' => re_invoice_engine_diagnostics($conn, $companyId, $leaseId),
        ];
    }
}

if (!function_exists('re_invoice_engine_issue_candidate_for_lease')) {
    /**
     * Issue exactly one approved candidate. Official invoice numbering happens
     * inside this function only after all eligibility guards pass.
     *
     * @return array<string,mixed>
     */
    function re_invoice_engine_issue_candidate_for_lease(PDO $conn, int $companyId, int $leaseId, int $candidateId, ?int $userId = null): array
    {
        $ready = re_invoice_engine_load_invoice_mode_lease($conn, $companyId, $leaseId);
        if (empty($ready['success'])) {
            return $ready;
        }

        $lease = $ready['lease'];
        $startedHere = !$conn->inTransaction();
        if ($startedHere) {
            $conn->beginTransaction();
        }

        try {
            $stmt = $conn->prepare("
                SELECT c.*, o.obligation_type, o.accounting_class, o.status AS obligation_status,
                       o.invoice_id AS obligation_invoice_id, o.description, o.period_start, o.period_end,
                       o.due_date, o.tax_treatment, o.tax_rate, o.subtotal_amount, o.vat_amount,
                       o.total_amount, o.source_type, o.source_id
                FROM re_invoice_candidates c
                JOIN re_obligations o ON o.id = c.obligation_id AND o.company_id = c.company_id
                WHERE c.id = ? AND c.company_id = ? AND c.lease_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$candidateId, $companyId, $leaseId]);
            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$candidate) {
                throw new RuntimeException('Candidate not found.');
            }
            if (!empty($candidate['invoice_id']) || !empty($candidate['obligation_invoice_id'])) {
                throw new RuntimeException('Candidate is already linked to an invoice.');
            }
            if ((string)$candidate['status'] !== 'approved') {
                throw new RuntimeException('Only approved candidates can be issued.');
            }
            if ((string)$candidate['eligible_on'] > date('Y-m-d')) {
                throw new RuntimeException('Candidate is not eligible for issuance yet.');
            }
            if ((string)$candidate['obligation_status'] !== 'open'
                || (string)$candidate['accounting_class'] === 'liability'
                || (string)$candidate['obligation_type'] === 'security_deposit') {
                throw new RuntimeException('Source obligation is not invoiceable.');
            }

            $existingLine = $conn->prepare("
                SELECT 1
                FROM re_invoice_items ii
                INNER JOIN re_invoices i ON i.id = ii.invoice_id AND i.company_id = ii.company_id
                WHERE ii.company_id = ? AND ii.obligation_id = ? AND i.status <> 'cancelled'
                LIMIT 1
            ");
            $existingLine->execute([$companyId, (int)$candidate['obligation_id']]);
            if ($existingLine->fetchColumn()) {
                throw new RuntimeException('This obligation already has an invoice line.');
            }

            $invoiceKey = re_invoice_engine_invoice_key($candidateId);
            $existingInvoice = $conn->prepare("
                SELECT id, status
                FROM re_invoices
                WHERE company_id = ? AND invoice_key = ?
                LIMIT 1
                FOR UPDATE
            ");
            $existingInvoice->execute([$companyId, $invoiceKey]);
            $existingInvRow = $existingInvoice->fetch(PDO::FETCH_ASSOC);
            if ($existingInvRow && (string)$existingInvRow['status'] !== 'cancelled') {
                throw new RuntimeException('This candidate already has an issued invoice.');
            }
            if ($existingInvRow && (string)$existingInvRow['status'] === 'cancelled') {
                // Allow re-issue after void by renaming the cancelled invoice key.
                $conn->prepare("
                    UPDATE re_invoices
                    SET invoice_key = CONCAT(invoice_key, ':voided:', id)
                    WHERE id = ? AND company_id = ?
                ")->execute([(int)$existingInvRow['id'], $companyId]);
            }

            $line = re_invoice_engine_line_from_obligation($lease, $candidate);
            $line = re_invoice_engine_attach_income_account($conn, $companyId, $lease, $line);
            $invoiceId = re_invoice_engine_insert_invoice($conn, $companyId, $leaseId, $candidateId, $line, $userId);
            re_invoice_engine_insert_line($conn, $companyId, $invoiceId, $line);

            $posting = post_invoice_to_accounting($invoiceId, $companyId, $userId);
            if (empty($posting['success'])) {
                throw new RuntimeException('Invoice created but accounting recognition failed: ' . ($posting['error'] ?? 'Unknown error'));
            }
            $journalId = !empty($posting['journal_id']) ? (int)$posting['journal_id'] : 0;
            if ($journalId <= 0) {
                throw new RuntimeException('Invoice created but accounting recognition did not return a journal id.');
            }

            $conn->prepare("
                UPDATE re_obligations
                SET recognition_status = 'recognised',
                    recognition_journal_id = ?
                WHERE id = ? AND company_id = ?
            ")->execute([
                $journalId,
                (int)$candidate['obligation_id'],
                $companyId,
            ]);

            $conn->prepare("
                UPDATE re_invoice_candidates
                SET invoice_id = ?, status = 'issued', issued_at = NOW()
                WHERE id = ? AND company_id = ? AND invoice_id IS NULL AND status = 'approved'
            ")->execute([$invoiceId, $candidateId, $companyId]);

            // Auto-apply only when credit fully settles the new invoice — never chip leftover fils.
            $creditApplication = function_exists('apply_tenant_credit_to_invoice_accounting')
                ? apply_tenant_credit_to_invoice_accounting($invoiceId, $companyId, $userId, true)
                : ['success' => true, 'applied' => 0.0];
            if (empty($creditApplication['success'])) {
                throw new RuntimeException('Invoice created but tenant credit application failed: ' . ($creditApplication['error'] ?? 'Unknown error'));
            }

            if ($startedHere) {
                $conn->commit();
            }

            return ['success' => true, 'invoice_id' => $invoiceId, 'journal_id' => $journalId, 'credit_applied' => (float)($creditApplication['applied'] ?? 0)];
        } catch (Throwable $e) {
            if ($startedHere && $conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'error' => 'Could not issue candidate: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('re_invoice_engine_void_unpaid_invoice')) {
    /**
     * Void an unpaid issued invoice and reverse its revenue recognition journal.
     *
     * @return array<string,mixed>
     */
    function re_invoice_engine_void_unpaid_invoice(PDO $conn, int $companyId, int $invoiceId, string $reason, ?int $userId = null, ?string $reversalDate = null): array
    {
        $stmt = $conn->prepare("
            SELECT i.*, l.accounting_mode
            FROM re_invoices i
            JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
            WHERE i.id = ? AND i.company_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$invoiceId, $companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return ['success' => false, 'error' => 'Invoice not found.'];
        }
        if ((string)$invoice['status'] === 'cancelled') {
            return ['success' => true, 'already_void' => true];
        }
        if (in_array((string)$invoice['status'], ['paid', 'partial'], true)) {
            return ['success' => false, 'error' => 'Invoice #' . ($invoice['invoice_number'] ?? $invoiceId) . ' has payments and cannot be voided automatically.'];
        }

        $allocStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount_allocated), 0)
            FROM re_receipt_allocations
            WHERE company_id = ? AND invoice_id = ?
        ");
        $allocStmt->execute([$companyId, $invoiceId]);
        if ((float)$allocStmt->fetchColumn() > 0.005) {
            return ['success' => false, 'error' => 'Invoice #' . ($invoice['invoice_number'] ?? $invoiceId) . ' has receipt allocations.'];
        }

        $itemStmt = $conn->prepare("
            SELECT obligation_id
            FROM re_invoice_items
            WHERE company_id = ? AND invoice_id = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $itemStmt->execute([$companyId, $invoiceId]);
        $obligationId = (int)$itemStmt->fetchColumn();

        $journalStmt = $conn->prepare("
            SELECT id
            FROM re_journal_headers
            WHERE company_id = ?
              AND reference_type = 'invoice'
              AND reference_id = ?
              AND is_posted = 1
              AND is_reversed = 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $journalStmt->execute([$companyId, $invoiceId]);
        $journalId = (int)$journalStmt->fetchColumn();

        if ($journalId > 0) {
            $reverse = reverse_journal($journalId, $reason, $userId, $reversalDate);
            if (empty($reverse['success'])) {
                return ['success' => false, 'error' => 'Could not reverse invoice journal: ' . ($reverse['error'] ?? 'unknown error')];
            }
        }

        $conn->prepare("
            UPDATE re_invoices
            SET status = 'cancelled',
                outstanding_amount = 0,
                notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
            WHERE id = ? AND company_id = ?
        ")->execute([$reason, $invoiceId, $companyId]);

        // Free unique obligation→item links so the obligation can be re-invoiced after void.
        $conn->prepare("
            UPDATE re_invoice_items
            SET obligation_id = NULL
            WHERE company_id = ? AND invoice_id = ? AND obligation_id IS NOT NULL
        ")->execute([$companyId, $invoiceId]);

        if ($obligationId > 0) {
            $conn->prepare("
                UPDATE re_obligations
                SET status = 'cancelled',
                    recognition_status = 'skipped',
                    notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
                WHERE id = ? AND company_id = ?
            ")->execute([$reason, $obligationId, $companyId]);
        }

        $conn->prepare("
            UPDATE re_invoice_candidates
            SET status = 'cancelled',
                notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, ?))
            WHERE company_id = ? AND invoice_id = ?
        ")->execute([$reason, $companyId, $invoiceId]);

        return ['success' => true, 'invoice_id' => $invoiceId, 'journal_reversed' => $journalId > 0];
    }
}

if (!function_exists('re_invoice_engine_diagnostics')) {
    /**
     * @return array<string,mixed>
     */
    function re_invoice_engine_diagnostics(PDO $conn, int $companyId, int $leaseId): array
    {
        if (!re_invoice_engine_schema_ready($conn)) {
            return [
                'candidate_source_obligations' => [],
                'candidates_awaiting_issuance' => [],
                'eligible_candidates' => [],
                'linked_invoices' => [],
                'future_issued_invoices' => [],
                'duplicate_invoice_keys' => [],
                'duplicate_obligation_lines' => [],
            ];
        }

        $candidateSource = re_invoice_engine_candidate_source_obligations($conn, $companyId, $leaseId);

        $awaitingStmt = $conn->prepare("
            SELECT c.*, o.obligation_type, o.description, o.due_date, o.total_amount, o.source_type
            FROM re_invoice_candidates c
            JOIN re_obligations o ON o.id = c.obligation_id AND o.company_id = c.company_id
            WHERE c.company_id = ? AND c.lease_id = ? AND c.invoice_id IS NULL AND c.status = 'approved'
            ORDER BY c.eligible_on ASC, c.id ASC
        ");
        $awaitingStmt->execute([$companyId, $leaseId]);
        $awaiting = $awaitingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($awaiting as &$awaitRow) {
            $awaitRow['obligation_label'] = re_obligation_display_label(
                $awaitRow['obligation_type'] ?? null,
                $awaitRow['source_type'] ?? null,
                $awaitRow['description'] ?? null,
                null
            );
        }
        unset($awaitRow);

        $eligible = array_values(array_filter($awaiting, static function (array $row): bool {
            return (string)($row['eligible_on'] ?? '') <= date('Y-m-d');
        }));

        $linkedStmt = $conn->prepare("
            SELECT c.id AS candidate_id, c.eligible_on, c.status AS candidate_status,
                   o.id AS obligation_id, o.obligation_type,
                   i.id AS invoice_id, i.invoice_number, i.invoice_key, i.invoice_source,
                   i.invoice_date, i.due_date, i.subtotal, i.tax_amount, i.total_amount, i.status,
                   ii.id AS invoice_item_id
            FROM re_invoice_candidates c
            JOIN re_obligations o ON o.id = c.obligation_id AND o.company_id = c.company_id
            JOIN re_invoices i ON i.id = c.invoice_id AND i.company_id = c.company_id
            LEFT JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.obligation_id = o.id AND ii.company_id = c.company_id
            WHERE c.company_id = ? AND c.lease_id = ?
            ORDER BY i.invoice_date ASC, i.id ASC
        ");
        $linkedStmt->execute([$companyId, $leaseId]);

        $futureIssuedStmt = $conn->prepare("
            SELECT i.id AS invoice_id, i.invoice_number, i.invoice_key, i.invoice_source,
                   i.invoice_date, i.due_date, i.generated_at,
                   o.id AS obligation_id, o.obligation_type, o.due_date AS obligation_due_date,
                   CASE
                       WHEN i.invoice_date > CURDATE() THEN 'future_invoice_date'
                       WHEN i.generated_at IS NOT NULL AND DATE(i.generated_at) < o.due_date THEN 'generated_before_eligibility'
                       ELSE 'review'
                   END AS diagnostic_reason
            FROM re_invoices i
            JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
            WHERE i.company_id = ?
              AND i.lease_id = ?
              AND i.invoice_source = ?
              AND (
                    i.invoice_date > CURDATE()
                    OR (i.generated_at IS NOT NULL AND DATE(i.generated_at) < o.due_date)
              )
            ORDER BY i.invoice_date ASC, i.id ASC
        ");
        $futureIssuedStmt->execute([$companyId, $leaseId, RE_INVOICE_REJECTED_SOURCE]);

        $dupKeyStmt = $conn->prepare("
            SELECT invoice_key, COUNT(*) AS duplicate_count
            FROM re_invoices
            WHERE company_id = ? AND lease_id = ? AND invoice_key IS NOT NULL
            GROUP BY invoice_key
            HAVING COUNT(*) > 1
        ");
        $dupKeyStmt->execute([$companyId, $leaseId]);

        $dupLineStmt = $conn->prepare("
            SELECT ii.obligation_id, COUNT(*) AS duplicate_count
            FROM re_invoice_items ii
            JOIN re_invoices i ON i.id = ii.invoice_id AND i.company_id = ii.company_id
            WHERE ii.company_id = ? AND i.lease_id = ? AND ii.obligation_id IS NOT NULL
            GROUP BY ii.obligation_id
            HAVING COUNT(*) > 1
        ");
        $dupLineStmt->execute([$companyId, $leaseId]);

        return [
            'candidate_source_obligations' => $candidateSource,
            'candidates_awaiting_issuance' => $awaiting,
            'eligible_candidates' => $eligible,
            'linked_invoices' => $linkedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'future_issued_invoices' => $futureIssuedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'duplicate_invoice_keys' => $dupKeyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'duplicate_obligation_lines' => $dupLineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }
}

if (!function_exists('re_invoice_engine_lease_invoices_all')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_invoice_engine_lease_invoices_all(PDO $conn, int $companyId, int $leaseId): array
    {
        $stmt = $conn->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.due_date, i.status,
                   i.total_amount, i.outstanding_amount, i.notes,
                   o.id AS obligation_id, o.obligation_type, o.status AS obligation_status,
                   o.source_type, o.description AS obligation_description,
                   sc.charge_name AS service_charge_name
            FROM re_invoices i
            LEFT JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
            LEFT JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
            LEFT JOIN re_billing_items bi
                ON bi.id = o.source_id AND o.source_type = 'billing_item' AND bi.company_id = o.company_id
            LEFT JOIN re_service_charges sc
                ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
            WHERE i.company_id = ? AND i.lease_id = ?
            GROUP BY i.id, i.invoice_number, i.invoice_date, i.due_date, i.status,
                     i.total_amount, i.outstanding_amount, i.notes,
                     o.id, o.obligation_type, o.status, o.source_type, o.description, sc.charge_name
            ORDER BY i.invoice_date ASC, i.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['obligation_label'] = re_obligation_display_label(
                $row['obligation_type'] ?? null,
                $row['source_type'] ?? null,
                $row['obligation_description'] ?? null,
                $row['service_charge_name'] ?? null
            );
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('re_invoice_engine_lease_candidates_all')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_invoice_engine_lease_candidates_all(PDO $conn, int $companyId, int $leaseId): array
    {
        if (!re_obligation_table_exists($conn, 're_invoice_candidates')) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT c.id, c.status, c.eligible_on, c.invoice_id, c.notes,
                   o.id AS obligation_id, o.obligation_type, o.due_date, o.status AS obligation_status, o.total_amount,
                   o.source_type, o.description AS obligation_description,
                   sc.charge_name AS service_charge_name,
                   i.invoice_number, i.status AS invoice_status
            FROM re_invoice_candidates c
            JOIN re_obligations o ON o.id = c.obligation_id AND o.company_id = c.company_id
            LEFT JOIN re_billing_items bi
                ON bi.id = o.source_id AND o.source_type = 'billing_item' AND bi.company_id = o.company_id
            LEFT JOIN re_service_charges sc
                ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
            LEFT JOIN re_invoices i ON i.id = c.invoice_id AND i.company_id = c.company_id
            WHERE c.company_id = ? AND c.lease_id = ?
            ORDER BY c.eligible_on ASC, c.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['obligation_label'] = re_obligation_display_label(
                $row['obligation_type'] ?? null,
                $row['source_type'] ?? null,
                $row['obligation_description'] ?? null,
                $row['service_charge_name'] ?? null
            );
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('re_invoice_engine_void_reversal_audit')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_invoice_engine_void_reversal_audit(PDO $conn, int $companyId, int $leaseId): array
    {
        $stmt = $conn->prepare("
            SELECT i.id AS invoice_id, i.invoice_number, i.invoice_date, i.status AS invoice_status,
                   orig.id AS original_journal_id, orig.journal_number AS original_journal_number,
                   orig.is_reversed, rev.id AS reversal_journal_id, rev.journal_number AS reversal_journal_number,
                   rev.journal_date AS reversal_date
            FROM re_invoices i
            LEFT JOIN re_journal_headers orig ON orig.company_id = i.company_id
                AND orig.reference_type = 'invoice' AND orig.reference_id = i.id AND orig.is_reversed = 1
            LEFT JOIN re_journal_headers rev ON rev.company_id = i.company_id
                AND rev.id = orig.reversal_journal_id
            WHERE i.company_id = ? AND i.lease_id = ? AND i.status = 'cancelled'
            ORDER BY i.invoice_date ASC, i.id ASC
        ");
        $stmt->execute([$companyId, $leaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
