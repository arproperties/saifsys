<?php
/**
 * Controlled Real Estate reset tool for duplicated databases only.
 *
 * Disabled unless ALLOW_REAL_ESTATE_RESET_TOOL is explicitly true.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$roles = current_user_roles($conn);
$isOwnerAdmin = in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
$toolEnabled = defined('ALLOW_REAL_ESTATE_RESET_TOOL') && ALLOW_REAL_ESTATE_RESET_TOOL === true;
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
$action = $_POST['action'] ?? '';
$message = '';
$error = '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function re_reset_table_exists(PDO $conn, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function re_reset_count(PDO $conn, string $sql, array $params): int {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

function re_reset_options(): array {
    return [
        'lease' => 'Delete lease transactions',
        'accounting' => 'Delete Real Estate accounting transactions',
        'vendor_ap' => 'Delete vendor/AP/expense transactions',
        'bank_rec' => 'Delete bank reconciliation transactions',
        'unit_status' => 'Reset unit occupancy/status to vacant',
    ];
}

function re_reset_selected_options(array $post): array {
    $selected = [];
    foreach (array_keys(re_reset_options()) as $key) {
        $selected[$key] = !empty($post['opt'][$key]);
    }
    $selected['seq_lease'] = !empty($post['seq']['lease']);
    $selected['seq_invoice'] = !empty($post['seq']['invoice']);
    $selected['seq_receipt'] = !empty($post['seq']['receipt']);
    $selected['seq_journal'] = !empty($post['seq']['journal']);
    $selected['seq_vendor_bill'] = !empty($post['seq']['vendor_bill']);
    $selected['seq_expense'] = !empty($post['seq']['expense']);
    return $selected;
}

function re_reset_journal_where(string $alias = 'jh'): string {
    $realEstateRefs = [
        'invoice', 'payment', 'deposit', 'refund', 'lease', 'deferred_payment',
        'recognition_schedule', 'recurring_journal', 'vendor_invoice', 'vendor_payment',
        'security_deposit_receipt', 'security_deposit_settlement', 'bank_reconciliation',
        'credit_note', 'tenant_credit',
    ];
    $quoted = implode(',', array_map(static fn($v) => "'" . $v . "'", $realEstateRefs));
    return "{$alias}.company_id = ? AND (
        {$alias}.reference_type IN ({$quoted})
        OR {$alias}.reference_type LIKE 're\\_%'
        OR (
            {$alias}.reference_type = 'expense'
            AND EXISTS (
                SELECT 1 FROM erp_expense_headers eh
                WHERE eh.id = {$alias}.reference_id
                  AND eh.company_id = {$alias}.company_id
                  AND eh.source_module = 'realestate'
            )
        )
    ) AND COALESCE({$alias}.reference_type, '') NOT LIKE 'co\\_%'";
}

function re_reset_audit_table(PDO $conn): void {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS re_admin_reset_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT(11) NOT NULL,
            user_id INT(11) DEFAULT NULL,
            database_name VARCHAR(128) NOT NULL,
            action VARCHAR(80) NOT NULL,
            selected_options_json LONGTEXT DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(80) DEFAULT NULL,
            session_id VARCHAR(128) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_re_admin_reset_company (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function re_reset_steps(PDO $conn, int $companyId, array $opts): array {
    $steps = [];
    $p = [$companyId];
    $leaseIds = "SELECT id FROM re_leases WHERE company_id = ?";
    $paymentIds = "SELECT p.id FROM re_payments p JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id WHERE p.company_id = ?";
    $invoiceIds = "SELECT i.id FROM re_invoices i JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id WHERE i.company_id = ?";
    $billingIds = "SELECT id FROM re_billing_items WHERE company_id = ? AND lease_id IN ({$leaseIds})";
    $erpExpenseIds = "SELECT id FROM erp_expense_headers WHERE company_id = ? AND source_module = 'realestate'";
    $journalWhere = re_reset_journal_where('jh');

    $add = function (bool $enabled, string $category, string $table, string $action, string $countSql, string $deleteSql, array $params, string $mode = 'DELETE') use (&$steps): void {
        if (!$enabled) return;
        $steps[] = compact('category', 'table', 'action', 'countSql', 'deleteSql', 'params', 'mode');
    };

    if ($opts['lease']) {
        $add(true, 'Lease', 're_billing_item_payment_allocations', 'Delete service billing allocations', "SELECT COUNT(*) FROM re_billing_item_payment_allocations WHERE billing_item_id IN ({$billingIds})", "DELETE FROM re_billing_item_payment_allocations WHERE billing_item_id IN ({$billingIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_receipt_allocations', 'Delete receipt allocations', "SELECT COUNT(*) FROM re_receipt_allocations WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_receipt_allocations WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_payment_allocations', 'Delete payment allocations', "SELECT COUNT(*) FROM re_payment_allocations WHERE payment_id IN ({$paymentIds})", "DELETE FROM re_payment_allocations WHERE payment_id IN ({$paymentIds})", [$companyId]);
        $add(true, 'Lease', 're_invoice_items', 'Delete invoice items', "SELECT COUNT(*) FROM re_invoice_items WHERE invoice_id IN ({$invoiceIds})", "DELETE FROM re_invoice_items WHERE invoice_id IN ({$invoiceIds})", [$companyId]);
        $add(true, 'Lease', 're_invoice_candidates', 'Delete invoice candidates', "SELECT COUNT(*) FROM re_invoice_candidates WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_invoice_candidates WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_obligations', 'Delete obligations', "SELECT COUNT(*) FROM re_obligations WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_obligations WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_invoices', 'Delete issued invoices', "SELECT COUNT(*) FROM re_invoices WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_invoices WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_tenant_credit_transactions', 'Delete tenant credit transactions', "SELECT COUNT(*) FROM re_tenant_credit_transactions WHERE company_id = ?", "DELETE FROM re_tenant_credit_transactions WHERE company_id = ?", $p);
        $add(true, 'Lease', 're_tenant_credit_balances', 'Delete tenant credit balances', "SELECT COUNT(*) FROM re_tenant_credit_balances WHERE company_id = ?", "DELETE FROM re_tenant_credit_balances WHERE company_id = ?", $p);
        $add(true, 'Lease', 're_payments', 'Delete receipts/payments', "SELECT COUNT(*) FROM re_payments WHERE id IN ({$paymentIds})", "DELETE FROM re_payments WHERE id IN ({$paymentIds})", [$companyId]);
        $add(true, 'Lease', 're_security_deposit_audit', 'Delete security deposit audit', "SELECT COUNT(*) FROM re_security_deposit_audit WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_security_deposit_audit WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_security_deposit_deductions', 'Delete security deposit deductions', "SELECT COUNT(*) FROM re_security_deposit_deductions WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_security_deposit_deductions WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_security_deposit_settlements', 'Delete security deposit settlements', "SELECT COUNT(*) FROM re_security_deposit_settlements WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_security_deposit_settlements WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        foreach (['re_cheque_lifecycle_audit','re_legal_cheque_escalations','re_bounced_cheque_alerts','re_cheque_reminder_log'] as $t) {
            $add(true, 'Lease', $t, 'Delete cheque related rows', "SELECT COUNT(*) FROM {$t} WHERE lease_id IN ({$leaseIds})", "DELETE FROM {$t} WHERE lease_id IN ({$leaseIds})", [$companyId]);
        }
        foreach (['re_post_dated_cheques','re_lease_cheques','re_lease_installments','re_lease_units','re_lease_expiry_reminders','re_lease_payment_schedule_audit','re_lease_payment_schedule_mismatch_approvals','re_lease_accounting_mode_audit','re_rent_recognition_schedule'] as $t) {
            $add(true, 'Lease', $t, 'Delete lease schedule/links', "SELECT COUNT(*) FROM {$t} WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM {$t} WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        }
        foreach (['re_renewal_electronic_signatures','re_renewal_negotiation_messages','re_renewal_portal_events','re_renewal_tenant_uploads','re_lease_renewal_rent_history'] as $t) {
            $add(true, 'Lease', $t, 'Delete renewal details', "SELECT COUNT(*) FROM {$t} WHERE workflow_id IN (SELECT id FROM re_lease_renewal_workflows WHERE lease_id IN ({$leaseIds}) OR new_lease_id IN ({$leaseIds}))", "DELETE FROM {$t} WHERE workflow_id IN (SELECT id FROM re_lease_renewal_workflows WHERE lease_id IN ({$leaseIds}) OR new_lease_id IN ({$leaseIds}))", [$companyId, $companyId, $companyId, $companyId]);
        }
        $add(true, 'Lease', 're_lease_renewal_workflows', 'Delete renewal workflows', "SELECT COUNT(*) FROM re_lease_renewal_workflows WHERE lease_id IN ({$leaseIds}) OR new_lease_id IN ({$leaseIds})", "DELETE FROM re_lease_renewal_workflows WHERE lease_id IN ({$leaseIds}) OR new_lease_id IN ({$leaseIds})", [$companyId, $companyId, $companyId, $companyId]);
        foreach (['re_move_out_damages','re_move_out_photos'] as $t) {
            $add(true, 'Lease', $t, 'Delete move-out children', "SELECT COUNT(*) FROM {$t} WHERE move_out_id IN (SELECT id FROM re_move_outs WHERE company_id = ? AND lease_id IN ({$leaseIds}))", "DELETE FROM {$t} WHERE move_out_id IN (SELECT id FROM re_move_outs WHERE company_id = ? AND lease_id IN ({$leaseIds}))", [$companyId, $companyId]);
        }
        foreach (['re_move_in_checklist_items','re_move_in_photos'] as $t) {
            $add(true, 'Lease', $t, 'Delete move-in children', "SELECT COUNT(*) FROM {$t} WHERE move_in_id IN (SELECT id FROM re_move_ins WHERE company_id = ? AND lease_id IN ({$leaseIds}))", "DELETE FROM {$t} WHERE move_in_id IN (SELECT id FROM re_move_ins WHERE company_id = ? AND lease_id IN ({$leaseIds}))", [$companyId, $companyId]);
        }
        foreach (['re_move_outs','re_move_out_notices','re_move_ins','re_move_operations','re_tenant_refunds','re_service_charges','re_billing_items','re_tenant_notifications','re_tenant_unit_history'] as $t) {
            $add(true, 'Lease', $t, 'Delete lease operational rows', "SELECT COUNT(*) FROM {$t} WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM {$t} WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        }
        $add(true, 'Lease', 're_documents', 'Delete lease documents only', "SELECT COUNT(*) FROM re_documents WHERE company_id = ? AND related_type = 'lease' AND related_id IN ({$leaseIds})", "DELETE FROM re_documents WHERE company_id = ? AND related_type = 'lease' AND related_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_missing_documents', 'Delete lease missing-document rows', "SELECT COUNT(*) FROM re_missing_documents WHERE related_type = 'lease' AND related_id IN ({$leaseIds})", "DELETE FROM re_missing_documents WHERE related_type = 'lease' AND related_id IN ({$leaseIds})", [$companyId]);
        $add(true, 'Lease', 're_task_attachments', 'Delete attachments for lease tasks', "SELECT COUNT(*) FROM re_task_attachments WHERE task_id IN (SELECT id FROM re_tasks WHERE related_type = 'lease' AND related_id IN ({$leaseIds}))", "DELETE FROM re_task_attachments WHERE task_id IN (SELECT id FROM re_tasks WHERE related_type = 'lease' AND related_id IN ({$leaseIds}))", [$companyId]);
        foreach (['re_task_comments','re_task_history','re_task_assignees','re_task_subtasks','re_task_label_links'] as $t) {
            $add(true, 'Lease', $t, 'Delete lease task children', "SELECT COUNT(*) FROM {$t} WHERE task_id IN (SELECT id FROM re_tasks WHERE related_type = 'lease' AND related_id IN ({$leaseIds}))", "DELETE FROM {$t} WHERE task_id IN (SELECT id FROM re_tasks WHERE related_type = 'lease' AND related_id IN ({$leaseIds}))", [$companyId]);
        }
        $add(true, 'Lease', 're_tasks', 'Delete lease tasks', "SELECT COUNT(*) FROM re_tasks WHERE related_type = 'lease' AND related_id IN ({$leaseIds})", "DELETE FROM re_tasks WHERE related_type = 'lease' AND related_id IN ({$leaseIds})", [$companyId]);
        foreach (['re_maintenance_photos'] as $t) {
            $add(true, 'Lease', $t, 'Delete maintenance photos for lease requests', "SELECT COUNT(*) FROM {$t} WHERE request_id IN (SELECT id FROM re_maintenance_requests WHERE company_id = ? AND lease_id IN ({$leaseIds}))", "DELETE FROM {$t} WHERE request_id IN (SELECT id FROM re_maintenance_requests WHERE company_id = ? AND lease_id IN ({$leaseIds}))", [$companyId, $companyId]);
        }
        $add(true, 'Lease', 're_maintenance_requests', 'Delete maintenance linked to leases', "SELECT COUNT(*) FROM re_maintenance_requests WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_maintenance_requests WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        foreach (['re_legal_case_costs','re_legal_case_events','re_legal_case_links'] as $t) {
            $add(true, 'Lease', $t, 'Delete legal case children linked to lease cases', "SELECT COUNT(*) FROM {$t} WHERE case_id IN (SELECT id FROM re_legal_cases WHERE company_id = ? AND lease_id IN ({$leaseIds}))", "DELETE FROM {$t} WHERE case_id IN (SELECT id FROM re_legal_cases WHERE company_id = ? AND lease_id IN ({$leaseIds}))", [$companyId, $companyId]);
        }
        foreach (['re_legal_hearings','re_legal_notices'] as $t) {
            $add(true, 'Lease', $t, 'Delete legal linked to leases', "SELECT COUNT(*) FROM {$t} WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM {$t} WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        }
        $add(true, 'Lease', 're_legal_cases', 'Delete legal cases linked to leases', "SELECT COUNT(*) FROM re_legal_cases WHERE company_id = ? AND lease_id IN ({$leaseIds})", "DELETE FROM re_legal_cases WHERE company_id = ? AND lease_id IN ({$leaseIds})", [$companyId, $companyId]);
        $add(true, 'Lease', 're_leases', 'Delete leases', "SELECT COUNT(*) FROM re_leases WHERE company_id = ?", "DELETE FROM re_leases WHERE company_id = ?", $p);
    }

    if ($opts['vendor_ap']) {
        $vendorInvoiceIds = "SELECT id FROM re_vendor_invoices WHERE company_id = ?";
        $vendorPaymentIds = "SELECT id FROM re_vendor_payments WHERE company_id = ?";
        foreach ([
            ['re_vendor_payment_allocations', 'vendor_payment_id', $vendorPaymentIds],
            ['re_vendor_bill_attachments', 'vendor_invoice_id', $vendorInvoiceIds],
            ['re_vendor_invoice_items', 'invoice_id', $vendorInvoiceIds],
        ] as [$t, $col, $ids]) {
            $add(true, 'Vendor/AP', $t, 'Delete vendor/AP children', "SELECT COUNT(*) FROM {$t} WHERE company_id = ? AND {$col} IN ({$ids})", "DELETE FROM {$t} WHERE company_id = ? AND {$col} IN ({$ids})", [$companyId, $companyId]);
        }
        foreach (['re_vendor_payments','re_vendor_invoices','re_vendor_recurring_bills','re_vendor_ap_audit'] as $t) {
            $add(true, 'Vendor/AP', $t, 'Delete vendor/AP transactions', "SELECT COUNT(*) FROM {$t} WHERE company_id = ?", "DELETE FROM {$t} WHERE company_id = ?", $p);
        }
        $add(true, 'Expenses', 'erp_expense_attachments', 'Delete Real Estate expense attachments', "SELECT COUNT(*) FROM erp_expense_attachments WHERE expense_id IN ({$erpExpenseIds})", "DELETE FROM erp_expense_attachments WHERE expense_id IN ({$erpExpenseIds})", [$companyId]);
        $add(true, 'Expenses', 'erp_expense_lines', 'Delete Real Estate expense lines', "SELECT COUNT(*) FROM erp_expense_lines WHERE expense_id IN ({$erpExpenseIds})", "DELETE FROM erp_expense_lines WHERE expense_id IN ({$erpExpenseIds})", [$companyId]);
        $add(true, 'Expenses', 'erp_expense_headers', 'Delete Real Estate expense headers', "SELECT COUNT(*) FROM erp_expense_headers WHERE company_id = ? AND source_module = 'realestate'", "DELETE FROM erp_expense_headers WHERE company_id = ? AND source_module = 'realestate'", $p);
    }

    if ($opts['accounting']) {
        foreach (['re_general_ledger','re_account_ledger_entries','re_accounting_postings'] as $t) {
            $add(true, 'Accounting', $t, 'Delete Real Estate GL/posting rows', "SELECT COUNT(*) FROM {$t} x JOIN re_journal_headers jh ON jh.id = x.journal_id WHERE {$journalWhere}", "DELETE x FROM {$t} x JOIN re_journal_headers jh ON jh.id = x.journal_id WHERE {$journalWhere}", $p);
        }
        $add(true, 'Accounting', 're_journal_lines', 'Delete Real Estate journal lines', "SELECT COUNT(*) FROM re_journal_lines jl JOIN re_journal_headers jh ON jh.id = jl.journal_id WHERE {$journalWhere}", "DELETE jl FROM re_journal_lines jl JOIN re_journal_headers jh ON jh.id = jl.journal_id WHERE {$journalWhere}", $p);
        $add(true, 'Accounting', 're_journal_headers', 'Delete Real Estate journal headers', "SELECT COUNT(*) FROM re_journal_headers jh WHERE {$journalWhere}", "DELETE jh FROM re_journal_headers jh WHERE {$journalWhere}", $p);
        $add(true, 'Accounting', 're_accounting_audit_log', 'Delete accounting audit rows', "SELECT COUNT(*) FROM re_accounting_audit_log WHERE company_id = ?", "DELETE FROM re_accounting_audit_log WHERE company_id = ?", $p);
        $add(true, 'Accounting', 're_account_ledgers', 'Delete tenant/vendor ledger headers', "SELECT COUNT(*) FROM re_account_ledgers WHERE company_id = ? AND sub_account_type IN ('tenant','vendor')", "DELETE FROM re_account_ledgers WHERE company_id = ? AND sub_account_type IN ('tenant','vendor')", $p);
    }

    if ($opts['bank_rec']) {
        foreach (['re_bank_reconciliation_matches','re_bank_statement_lines','re_bank_import_batches','re_bank_reconciliation_audit','re_bank_reconciliation_locks'] as $t) {
            $add(true, 'Bank Reconciliation', $t, 'Delete bank reconciliation rows', "SELECT COUNT(*) FROM {$t} WHERE company_id = ?", "DELETE FROM {$t} WHERE company_id = ?", $p);
        }
    }

    if ($opts['unit_status']) {
        $add(true, 'Unit Status', 're_units', 'Reset lease-driven occupied units to vacant', "SELECT COUNT(*) FROM re_units u WHERE u.company_id = ? AND u.status = 'occupied' AND NOT EXISTS (SELECT 1 FROM ars_bookings ab WHERE ab.unit_id = u.id AND ab.status NOT IN ('cancelled','expired') AND CURDATE() >= ab.check_in AND CURDATE() < ab.check_out)", "UPDATE re_units u SET u.status = 'vacant', u.updated_at = NOW() WHERE u.company_id = ? AND u.status = 'occupied' AND NOT EXISTS (SELECT 1 FROM ars_bookings ab WHERE ab.unit_id = u.id AND ab.status NOT IN ('cancelled','expired') AND CURDATE() >= ab.check_in AND CURDATE() < ab.check_out)", $p, 'UPDATE');
    }

    $seqMap = [
        'seq_invoice' => ['re_invoice_sequences', 'Invoice sequence'],
        'seq_receipt' => ['re_receipt_sequences', 'Receipt sequence'],
        'seq_journal' => ['re_journal_sequences', 'Journal sequence'],
        'seq_expense' => ['erp_expense_seq', 'Expense sequence'],
        'seq_vendor_bill' => ['re_vendor_recurring_bills', 'Vendor recurring templates already handled in AP scope'],
    ];
    foreach ($seqMap as $key => [$table, $label]) {
        if (!empty($opts[$key]) && $table !== 're_vendor_recurring_bills') {
            $add(true, 'Sequences', $table, 'Reset ' . $label, "SELECT COUNT(*) FROM {$table} WHERE company_id = ?", "DELETE FROM {$table} WHERE company_id = ?", $p);
        }
    }

    return $steps;
}

function re_reset_preview(PDO $conn, array $steps): array {
    $out = [];
    foreach ($steps as $step) {
        $exists = re_reset_table_exists($conn, $step['table']);
        $count = $exists ? re_reset_count($conn, $step['countSql'], $step['params']) : 0;
        $out[] = $step + ['exists' => $exists, 'row_count' => $count, 'status' => $exists ? ($count < 0 ? 'error' : 'ready') : 'missing'];
    }
    return $out;
}

function re_reset_execute(PDO $conn, array $preview): array {
    $results = [];
    $conn->beginTransaction();
    try {
        foreach ($preview as $step) {
            if (!$step['exists'] || (int)$step['row_count'] <= 0) {
                $results[] = $step + ['deleted' => 0, 'result' => 'skipped'];
                continue;
            }
            $stmt = $conn->prepare($step['deleteSql']);
            $stmt->execute($step['params']);
            $results[] = $step + ['deleted' => $stmt->rowCount(), 'result' => 'done'];
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return $results;
}

$selected = re_reset_selected_options($_POST);
$steps = re_reset_steps($conn, $companyId, $selected);
$preview = [];
$executionResults = [];

if (!$isOwnerAdmin) {
    $error = 'Forbidden. Owner/Admin role is required.';
} elseif (!$toolEnabled) {
    $error = 'Reset tool is disabled. Define ALLOW_REAL_ESTATE_RESET_TOOL as true in configuration to enable it.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $preview = re_reset_preview($conn, $steps);
    if ($action === 'execute') {
        $phraseOk = trim((string)($_POST['confirm_phrase'] ?? '')) === 'RESET REAL ESTATE DATA';
        $backupOk = !empty($_POST['backup_confirmed']);
        $duplicateOk = !empty($_POST['duplicate_confirmed']);
        $dryRunOk = !empty($_POST['dry_run_reviewed']);
        if (!$phraseOk || !$backupOk || !$duplicateOk || !$dryRunOk) {
            $error = 'Execution blocked. Complete backup confirmation, duplicated database confirmation, dry-run review, and exact typed phrase.';
        } else {
            try {
                re_reset_audit_table($conn);
                $executionResults = re_reset_execute($conn, $preview);
                $conn->prepare("
                    INSERT INTO re_admin_reset_audit
                        (company_id, user_id, database_name, action, selected_options_json, result_json, ip_address, session_id)
                    VALUES (?, ?, ?, 'execute_reset', ?, ?, ?, ?)
                ")->execute([
                    $companyId,
                    $userId,
                    $dbName,
                    json_encode($selected, JSON_UNESCAPED_SLASHES),
                    json_encode($executionResults, JSON_UNESCAPED_SLASHES),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    session_id(),
                ]);
                $message = 'Reset completed. Audit log entry created.';
            } catch (Throwable $e) {
                $error = 'Reset failed: ' . $e->getMessage();
            }
        }
    }
}

$unknownTables = [
    're_tasks / re_task_* not linked to lease',
    're_documents not linked to lease',
    're_maintenance_* not linked to lease',
    're_legal_* not linked to lease',
    're_unit_public_media / viewing requests / applications',
    're_amc_*',
    'co_* construction transaction tables',
    'manual journals without reference_type',
];

$pageTitle = 'Real Estate Reset Tool';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-exclamation-triangle"></i> Real Estate Reset Tool</div>
    <a href="../index.php" class="btn btn-outline-secondary">Back</a>
</div>

<div class="alert alert-danger">
    <strong>This will permanently delete Real Estate leases and accounting transactions from this database.</strong>
    Use only on the duplicated database before go-live.
</div>

<?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <div><strong>Current database:</strong> <code><?= h($dbName) ?></code></div>
        <div><strong>Company ID:</strong> <?= (int)$companyId ?></div>
        <div><strong>Tool enabled:</strong> <span class="badge bg-<?= $toolEnabled ? 'success' : 'danger' ?>"><?= $toolEnabled ? 'yes' : 'no' ?></span></div>
        <div class="small text-muted mt-2">External backup required before execution. Recommended: export this database using phpMyAdmin or mysqldump.</div>
    </div>
</div>

<form method="post" class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Reset Scope</strong></div>
    <div class="card-body">
        <?php csrf_field(); ?>
        <div class="row g-3">
            <?php foreach (re_reset_options() as $key => $label): ?>
                <div class="col-md-6">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="opt[<?= h($key) ?>]" value="1" <?= !empty($selected[$key]) ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= h($label) ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <hr>
        <h6>Optional Sequence Reset</h6>
        <div class="row g-2">
            <?php foreach (['lease'=>'Reset lease numbering sequence','invoice'=>'Reset invoice numbering sequence','receipt'=>'Reset receipt numbering sequence','journal'=>'Reset journal numbering sequence','expense'=>'Reset old expense sequence'] as $key => $label): ?>
                <div class="col-md-4">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="seq[<?= h($key) ?>]" value="1" <?= !empty($selected['seq_' . $key]) ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= h($label) ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <hr>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="backup_confirmed" value="1" <?= !empty($_POST['backup_confirmed']) ? 'checked' : '' ?>>
                    <span class="form-check-label">I confirm an external SQL backup/export has been completed.</span>
                </label>
            </div>
            <div class="col-md-6">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="duplicate_confirmed" value="1" <?= !empty($_POST['duplicate_confirmed']) ? 'checked' : '' ?>>
                    <span class="form-check-label">I confirm this is the duplicated database, not live production.</span>
                </label>
            </div>
            <div class="col-md-6">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="dry_run_reviewed" value="1" <?= !empty($_POST['dry_run_reviewed']) ? 'checked' : '' ?>>
                    <span class="form-check-label">I reviewed the dry-run table below.</span>
                </label>
            </div>
            <div class="col-md-6">
                <label class="form-label">Type confirmation phrase</label>
                <input type="text" name="confirm_phrase" class="form-control" value="<?= h($_POST['confirm_phrase'] ?? '') ?>" placeholder="RESET REAL ESTATE DATA">
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button name="action" value="dry_run" class="btn btn-primary" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?>>Dry Run</button>
            <button name="action" value="execute" class="btn btn-danger" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?> onclick="return confirm('Execute destructive Real Estate reset on this database?');">Execute Reset</button>
        </div>
    </div>
</form>

<?php if ($preview): ?>
<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Dry Run Results</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>Category</th><th>Table</th><th>Action</th><th>Mode</th><th class="text-end">Rows</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($preview as $row): ?>
                <tr>
                    <td><?= h($row['category']) ?></td>
                    <td><code><?= h($row['table']) ?></code></td>
                    <td><?= h($row['action']) ?></td>
                    <td><?= h($row['mode']) ?></td>
                    <td class="text-end"><?= (int)$row['row_count'] ?></td>
                    <td><span class="badge bg-<?= $row['status'] === 'ready' ? 'primary' : ($row['status'] === 'missing' ? 'secondary' : 'danger') ?>"><?= h($row['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($executionResults): ?>
<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Execution Summary</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Table</th><th class="text-end">Preview Rows</th><th class="text-end">Affected Rows</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($executionResults as $row): ?>
                <tr><td><code><?= h($row['table']) ?></code></td><td class="text-end"><?= (int)$row['row_count'] ?></td><td class="text-end"><?= (int)$row['deleted'] ?></td><td><?= h($row['result']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-header bg-white"><strong>Unknown / Not Deleted Automatically</strong></div>
    <div class="card-body">
        <ul class="mb-0">
            <?php foreach ($unknownTables as $label): ?><li><?= h($label) ?></li><?php endforeach; ?>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
