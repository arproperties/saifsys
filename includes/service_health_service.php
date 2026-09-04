<?php
/**
 * Service Management — extended read-only health checks (WO/invoice/GL/dashboard).
 */

require_once __DIR__ . '/accounting_health_service.php';
require_once __DIR__ . '/service_accounting_service.php';
require_once __DIR__ . '/service_management_settings.php';
require_once __DIR__ . '/cleaning_accounting_context.php';

if (!function_exists('service_health_invoice_exists_sql')) {
    function service_health_invoice_exists_sql(): string
    {
        return "(
            mo.invoice_id IS NOT NULL
            OR EXISTS (
                SELECT 1 FROM invoices inv_direct
                WHERE inv_direct.order_id = mo.id
                  AND COALESCE(inv_direct.status, '') <> 'void'
            )
        )";
    }
}

if (!function_exists('service_health_collect')) {
    /**
     * @return array<string, array{count:int, rows:array, severity?:string, description?:string}>
     */
    function service_health_collect(PDO $conn, ?int $companyId = null): array
    {
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $base = accounting_health_collect($conn, $companyId);

        $sections = [
            'completed_without_invoice' => [
                'severity' => 'critical',
                'description' => 'Completed or invoiced work orders with no linked non-void invoice.',
                'rows' => [],
                'count' => 0,
            ],
            'invoice_without_work_order' => [
                'severity' => 'critical',
                'description' => 'Invoices linked to a missing work order (broken order_id).',
                'rows' => [],
                'count' => 0,
            ],
            'accepted_legacy_orphan_invoices' => [
                'severity' => 'info',
                'description' => 'Historical standalone invoices without a work order — accepted legacy baseline.',
                'rows' => [],
                'count' => 0,
            ],
            'wo_invoice_amount_mismatch' => [
                'severity' => 'critical',
                'description' => 'Work order grand total differs from linked invoice total.',
                'rows' => [],
                'count' => 0,
            ],
            'wo_edited_after_invoice_posted' => [
                'severity' => 'warning',
                'description' => 'Deprecated — see WO vs Invoice Amount Mismatch (timestamp-only drift excluded).',
                'rows' => [],
                'count' => 0,
            ],
            'cancelled_wo_active_invoice' => [
                'severity' => 'critical',
                'description' => 'Cancelled work orders still tied to a non-void invoice.',
                'rows' => [],
                'count' => 0,
            ],
            'expenses_missing_gl' => [
                'severity' => 'critical',
                'description' => 'Posted expenses without an active GL journal.',
                'rows' => [],
                'count' => 0,
            ],
            'payments_without_allocation' => [
                'severity' => 'warning',
                'description' => 'Receipts with no invoice allocation (unapplied cash).',
                'rows' => [],
                'count' => 0,
            ],
            'dashboard_metric_mismatches' => [
                'severity' => 'warning',
                'description' => 'Legacy/cache metrics differ from ServiceAccountingService live values.',
                'rows' => [],
                'count' => 0,
            ],
            'pnl_invoice_mismatch_mtd' => [
                'severity' => 'info',
                'description' => 'Month-to-date invoice register total vs GL revenue (timing differences expected).',
                'rows' => [],
                'count' => 0,
            ],
        ];

        $moCompany = '';
        $moParams = [];
        if (gl_column_exists($conn, 'make_order', 'company_id')) {
            $moCompany = ' AND mo.company_id = ?';
            $moParams[] = $companyId;
        }

        $invExists = service_health_invoice_exists_sql();

        // Completed / invoiced WOs without invoice
        $sql = "
            SELECT mo.id AS order_id,
                   COALESCE(mo.service_date, mo.`date`) AS service_date,
                   mo.client_name,
                   mo.status,
                   COALESCE(mo.grand_total, mo.total) AS wo_total
            FROM make_order mo
            WHERE COALESCE(mo.status, '') IN ('completed', 'invoiced')
              AND NOT {$invExists}
              {$moCompany}
            ORDER BY mo.id DESC
            LIMIT 200
        ";
        $st = $conn->prepare($sql);
        $st->execute($moParams);
        $sections['completed_without_invoice']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sections['completed_without_invoice']['count'] = count($sections['completed_without_invoice']['rows']);

        // Invoice with broken WO link (order_id set but make_order missing)
        $invParams = [];
        $invCompany = gl_column_exists($conn, 'invoices', 'company_id') ? ' AND i.company_id = ?' : '';
        if ($invCompany !== '') {
            $invParams[] = $companyId;
        }
        $st = $conn->prepare("
            SELECT i.id, i.invoice_no, i.order_id, i.issue_date, i.total, i.status
            FROM invoices i
            LEFT JOIN make_order mo ON mo.id = i.order_id
            WHERE i.status <> 'void'
              AND i.order_id IS NOT NULL
              AND mo.id IS NULL
              {$invCompany}
            ORDER BY i.id DESC
            LIMIT 200
        ");
        $st->execute($invParams);
        $sections['invoice_without_work_order']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sections['invoice_without_work_order']['count'] = count($sections['invoice_without_work_order']['rows']);

        // Accepted legacy standalone invoices (no work order link)
        if (sm_health_exclude_orphan_invoices($conn)) {
            $orphanParams = [];
            $orphanCompany = gl_column_exists($conn, 'invoices', 'company_id') ? ' AND i.company_id = ?' : '';
            if ($orphanCompany !== '') {
                $orphanParams[] = $companyId;
            }
            $st = $conn->prepare("
                SELECT i.id, i.invoice_no, i.issue_date, i.total, i.status
                FROM invoices i
                WHERE i.status <> 'void'
                  AND i.order_id IS NULL
                  {$orphanCompany}
                ORDER BY i.id DESC
                LIMIT 200
            ");
            $st->execute($orphanParams);
            $sections['accepted_legacy_orphan_invoices']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $sections['accepted_legacy_orphan_invoices']['count'] = count($sections['accepted_legacy_orphan_invoices']['rows']);
        }

        // Amount mismatch
        $st = $conn->prepare("
            SELECT mo.id AS order_id,
                   i.id AS invoice_id,
                   i.invoice_no,
                   COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) AS wo_total,
                   i.total AS invoice_total,
                   ABS(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) - i.total) AS difference
            FROM make_order mo
            INNER JOIN invoices i ON i.order_id = mo.id AND i.status <> 'void'
            WHERE ABS(COALESCE(mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)) - i.total) > 0.02
              {$moCompany}
            ORDER BY difference DESC
            LIMIT 200
        ");
        $st->execute($moParams);
        $sections['wo_invoice_amount_mismatch']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sections['wo_invoice_amount_mismatch']['count'] = count($sections['wo_invoice_amount_mismatch']['rows']);

        // WO edited after invoice posted — deprecated counter (amount mismatches covered below)
        $sections['wo_edited_after_invoice_posted']['rows'] = [];
        $sections['wo_edited_after_invoice_posted']['count'] = 0;

        // Cancelled WO with active invoice
        $st = $conn->prepare("
            SELECT mo.id AS order_id,
                   i.id AS invoice_id,
                   i.invoice_no,
                   i.status AS invoice_status,
                   i.total AS invoice_total
            FROM make_order mo
            INNER JOIN invoices i ON i.order_id = mo.id
            WHERE mo.status = 'cancelled'
              AND i.status <> 'void'
              {$moCompany}
            ORDER BY mo.id DESC
            LIMIT 200
        ");
        $st->execute($moParams);
        $sections['cancelled_wo_active_invoice']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sections['cancelled_wo_active_invoice']['count'] = count($sections['cancelled_wo_active_invoice']['rows']);

        // Expenses missing GL
        $expParams = [];
        $expCompany = gl_column_exists($conn, 'expenses', 'company_id') ? ' AND e.company_id = ?' : '';
        if ($expCompany !== '') {
            $expParams[] = $companyId;
        }
        $st = $conn->prepare("
            SELECT e.id, e.reference_no, e.expense_date, e.total, e.status
            FROM expenses e
            WHERE e.status = 'posted'
              {$expCompany}
              AND NOT EXISTS (
                SELECT 1 FROM gl_journals j
                WHERE j.source = 'expense' AND j.source_id = e.id
                  AND j.is_posted = 1 AND j.is_reversed = 0
              )
            ORDER BY e.expense_date DESC
            LIMIT 200
        ");
        $st->execute($expParams);
        $sections['expenses_missing_gl']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sections['expenses_missing_gl']['count'] = count($sections['expenses_missing_gl']['rows']);

        // Unallocated receipts
        $rcpParams = [];
        $rcpCompany = gl_column_exists($conn, 'receipts', 'company_id') ? ' AND r.company_id = ?' : '';
        if ($rcpCompany !== '') {
            $rcpParams[] = $companyId;
        }
        $st = $conn->prepare("
            SELECT r.id, r.receipt_no, r.receipt_date, r.amount,
                   COALESCE(SUM(ra.amount_applied), 0) AS allocated
            FROM receipts r
            LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
            WHERE 1=1 {$rcpCompany}
            GROUP BY r.id
            HAVING allocated < 0.01
            ORDER BY r.receipt_date DESC
            LIMIT 200
        ");
        $st->execute($rcpParams);
        $sections['payments_without_allocation']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sections['payments_without_allocation']['count'] = count($sections['payments_without_allocation']['rows']);

        // Dashboard / accounting service comparison
        if (sm_accounting_compare_mode($conn)) {
            $svc = new ServiceAccountingService($conn, $companyId);
            $mismatches = $svc->compareWithLegacy();
            foreach ($mismatches as $m) {
                $metric = (string)($m['metric'] ?? '');
                $row = [
                    'metric' => $metric,
                    'legacy_value' => $m['legacy'] ?? '',
                    'service_value' => $m['service'] ?? '',
                    'difference' => $m['difference'] ?? '',
                    'note' => $m['note'] ?? '',
                    'suggested_action' => service_health_suggested_action($metric),
                ];
                if (strpos($metric, 'invoice_register_vs_gl') !== false) {
                    $sections['pnl_invoice_mismatch_mtd']['rows'][] = $row;
                } else {
                    $sections['dashboard_metric_mismatches']['rows'][] = $row;
                }
            }
            $sections['dashboard_metric_mismatches']['count'] = count($sections['dashboard_metric_mismatches']['rows']);
            $sections['pnl_invoice_mismatch_mtd']['count'] = count($sections['pnl_invoice_mismatch_mtd']['rows']);
        }

        // Merge accounting health into service health output
        $out = array_merge($base, $sections);

        $criticalKeys = [
            'duplicate_invoice_journals', 'missing_invoice_journals', 'broken_allocations',
            'invalid_trade_receivable_lines', 'completed_without_invoice', 'invoice_without_work_order',
            'wo_invoice_amount_mismatch', 'cancelled_wo_active_invoice', 'expenses_missing_gl',
        ];
        $warningKeys = [
            'void_invoice_active_journals', 'allocation_status_mismatches', 'overallocated_receipts',
            'payments_without_allocation', 'dashboard_metric_mismatches',
        ];
        $infoKeys = ['accepted_legacy_orphan_invoices', 'pnl_invoice_mismatch_mtd'];

        $critical = 0;
        $warning = 0;
        foreach ($out as $key => $section) {
            if (!is_array($section) || !isset($section['count'])) {
                continue;
            }
            $c = (int)$section['count'];
            if ($c <= 0) {
                continue;
            }
            if (in_array($key, $criticalKeys, true)) {
                $critical += $c;
            } elseif (in_array($key, $warningKeys, true)) {
                $warning += $c;
            }
        }

        $info = 0;
        foreach ($infoKeys as $infoKey) {
            $info += (int)($out[$infoKey]['count'] ?? 0);
        }

        $out['summary'] = [
            'critical_count' => $critical,
            'warning_count' => $warning,
            'info_count' => $info,
            'accounting_service_enabled' => sm_use_accounting_service($conn),
            'compare_mode' => sm_accounting_compare_mode($conn),
        ];

        return $out;
    }
}

if (!function_exists('service_health_suggested_action')) {
    function service_health_suggested_action(string $metric): string
    {
        $map = [
            'completed_without_invoice' => 'Finalize job or create invoice via accountant workflow.',
            'invoice_without_work_order' => 'Broken order_id — link invoice to work order or void.',
            'accepted_legacy_orphan_invoices' => 'Accepted legacy standalone billing — no action required.',
            'wo_invoice_amount_mismatch' => 'Run Live Data Repair → Sync WO from invoice (invoice wins).',
            'wo_edited_after_invoice_posted' => 'Run Live Data Repair → Sync WO from invoice.',
            'cancelled_wo_active_invoice' => 'Void invoice or post credit note.',
            'missing_invoice_journals' => 'Run Live Data Repair → Backfill missing invoice GL.',
            'duplicate_invoice_journals' => 'Run Live Data Repair → Fix duplicate invoice journals.',
            'allocation_status_mismatches' => 'Run Live Data Repair → Refresh allocation status.',
            'expenses_missing_gl' => 'Run Live Data Repair → Post missing expense GL.',
            'cache_ar_summary_ar_total' => 'Clear dashboard cache via System Health → Refresh.',
            'invoice_register_vs_gl_revenue_mtd' => 'Compare invoice issue dates vs journal dates; review reposts.',
        ];
        foreach ($map as $prefix => $action) {
            if (strpos($metric, $prefix) !== false) {
                return $action;
            }
        }
        return 'Review row details; no automatic fix.';
    }
}

if (!function_exists('service_health_section_labels')) {
    function service_health_section_labels(): array
    {
        return [
            'completed_without_invoice' => ['Completed Without Invoice', 'danger'],
            'invoice_without_work_order' => ['Broken Invoice / WO Link', 'danger'],
            'accepted_legacy_orphan_invoices' => ['Accepted Legacy Orphans', 'info'],
            'wo_invoice_amount_mismatch' => ['WO vs Invoice Amount Mismatch', 'danger'],
            'wo_edited_after_invoice_posted' => ['WO Edited After Invoice Posted', 'secondary'],
            'cancelled_wo_active_invoice' => ['Cancelled WO With Active Invoice', 'danger'],
            'expenses_missing_gl' => ['Expenses Missing GL', 'danger'],
            'payments_without_allocation' => ['Unallocated Receipts', 'warning'],
            'dashboard_metric_mismatches' => ['Dashboard Metric Mismatches', 'warning'],
            'pnl_invoice_mismatch_mtd' => ['P&L vs Invoice Register (MTD)', 'info'],
            'duplicate_invoice_journals' => ['Duplicate Invoice Journals', 'danger'],
            'missing_invoice_journals' => ['Missing Invoice Journals', 'danger'],
            'void_invoice_active_journals' => ['Void Invoice With Active Journals', 'warning'],
            'duplicate_receipt_journals' => ['Duplicate Receipt Journals', 'danger'],
            'missing_receipt_journals' => ['Missing Receipt Journals', 'danger'],
            'allocation_status_mismatches' => ['Allocation Status Mismatches', 'warning'],
            'overallocated_receipts' => ['Overallocated Receipts', 'warning'],
            'broken_allocations' => ['Broken Allocations', 'danger'],
            'invalid_trade_receivable_lines' => ['Invalid Trade Receivable Lines', 'danger'],
        ];
    }
}
