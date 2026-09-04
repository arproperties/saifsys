<?php
/**
 * Construction Suppliers / AP — Phase 0 foundation helpers.
 * Company fail-closed, AP audit, diagnostics helpers.
 * Does NOT call RE vendor_ap_helper. Shared engine only via co_post_supplier_*.
 */

if (!function_exists('co_db_table_exists')) {
    require_once __DIR__ . '/construction_helpers.php';
}

/**
 * Fail-closed company context for Suppliers/AP monetary pages.
 */
function co_supplier_require_company_id(PDO $conn): int {
    $cid = current_company_id($conn);
    if (!$cid || (int)$cid <= 0) {
        http_response_code(400);
        echo 'Company context is required. Select a company before continuing.';
        exit;
    }
    return (int)$cid;
}

function co_supplier_ap_audit_schema_ready(PDO $conn): bool {
    return function_exists('co_db_table_exists') && co_db_table_exists($conn, 'co_supplier_ap_audit');
}

/**
 * Best-effort AP audit write (never throws to callers).
 */
function co_supplier_ap_audit(
    PDO $conn,
    int $companyId,
    ?int $supplierId,
    ?int $invoiceId,
    ?int $paymentId,
    string $action,
    ?string $oldValue = null,
    ?string $newValue = null,
    ?float $amount = null,
    string $reason = '',
    ?int $userId = null,
    string $source = 'system',
    ?int $journalId = null
): void {
    if ($companyId <= 0 || $action === '' || !co_supplier_ap_audit_schema_ready($conn)) {
        return;
    }
    try {
        $st = $conn->prepare("
            INSERT INTO co_supplier_ap_audit
                (company_id, supplier_id, supplier_invoice_id, supplier_payment_id,
                 action_type, old_value, new_value, amount, reason, changed_by, source, related_journal_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $companyId,
            $supplierId,
            $invoiceId,
            $paymentId,
            $action,
            $oldValue,
            $newValue,
            $amount,
            $reason !== '' ? $reason : null,
            $userId,
            $source,
            $journalId,
        ]);
    } catch (Throwable $e) {
        error_log('Construction supplier AP audit failed: ' . $e->getMessage());
    }
}

/**
 * @return list<array{title:string,rows:list<array<string,mixed>>}>
 */
function co_supplier_ap_diagnostics(PDO $conn, int $companyId): array {
    $sections = [];
    if ($companyId <= 0) {
        return [['title' => 'Company context missing', 'rows' => [['error' => 'Select a company.']]]];
    }

    $queries = [
        'Draft supplier invoices (no GL journal)' => "
            SELECT si.id, si.invoice_number, s.supplier_name, si.invoice_date, si.total
            FROM co_supplier_invoices si
            JOIN co_suppliers s ON s.id = si.supplier_id AND s.company_id = si.company_id
            WHERE si.company_id = ? AND si.journal_id IS NULL
            ORDER BY si.invoice_date DESC, si.id DESC
            LIMIT 100
        ",
        'Posted invoices missing journal header' => "
            SELECT si.id, si.invoice_number, si.journal_id, s.supplier_name
            FROM co_supplier_invoices si
            JOIN co_suppliers s ON s.id = si.supplier_id AND s.company_id = si.company_id
            WHERE si.company_id = ?
              AND si.journal_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM re_journal_headers j
                  WHERE j.id = si.journal_id AND j.company_id = si.company_id
              )
            LIMIT 100
        ",
        'Payments without allocation rows (when allocation table ready)' => "
            SELECT sp.id, sp.payment_date, s.supplier_name, sp.amount, sp.journal_id
            FROM co_supplier_payments sp
            JOIN co_suppliers s ON s.id = sp.supplier_id AND s.company_id = sp.company_id
            WHERE sp.company_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM co_supplier_payment_allocations a
                  WHERE a.company_id = sp.company_id AND a.payment_id = sp.id
              )
            ORDER BY sp.payment_date DESC
            LIMIT 100
        ",
        'Allocation total exceeds payment amount' => "
            SELECT sp.id, s.supplier_name, sp.amount AS payment_amount,
                   COALESCE(SUM(a.allocated_amount), 0) AS allocated_total
            FROM co_supplier_payments sp
            JOIN co_suppliers s ON s.id = sp.supplier_id AND s.company_id = sp.company_id
            LEFT JOIN co_supplier_payment_allocations a
                   ON a.payment_id = sp.id AND a.company_id = sp.company_id
            WHERE sp.company_id = ?
            GROUP BY sp.id, s.supplier_name, sp.amount
            HAVING allocated_total > sp.amount + 0.005
            LIMIT 100
        ",
        'Overdue posted open invoices' => "
            SELECT si.id, si.invoice_number, s.supplier_name, si.due_date, si.total,
                   COALESCE((
                       SELECT SUM(a.allocated_amount)
                       FROM co_supplier_payment_allocations a
                       WHERE a.company_id = si.company_id AND a.invoice_id = si.id
                   ), 0) AS paid_amount
            FROM co_supplier_invoices si
            JOIN co_suppliers s ON s.id = si.supplier_id AND s.company_id = si.company_id
            WHERE si.company_id = ?
              AND si.journal_id IS NOT NULL
              AND COALESCE(si.due_date, si.invoice_date) < CURDATE()
            HAVING (si.total - paid_amount) > 0.005
            ORDER BY COALESCE(si.due_date, si.invoice_date) ASC
            LIMIT 100
        ",
        'Duplicate invoice numbers per supplier' => "
            SELECT supplier_id, invoice_number, COUNT(*) AS cnt
            FROM co_supplier_invoices
            WHERE company_id = ?
            GROUP BY supplier_id, invoice_number
            HAVING COUNT(*) > 1
            LIMIT 100
        ",
        'COA: Supplier Payable 2110 missing' => "
            SELECT '2110' AS account_code, 'Supplier Payable required' AS note
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM re_chart_of_accounts
                WHERE company_id = ? AND account_code = '2110' AND is_active = 1
            )
        ",
        'COA: Input VAT 2130 missing' => "
            SELECT '2130' AS account_code, 'Input VAT required' AS note
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM re_chart_of_accounts
                WHERE company_id = ? AND account_code = '2130' AND is_active = 1
            )
        ",
    ];

    $allocReady = function_exists('co_supplier_allocations_ready') && co_supplier_allocations_ready($conn);
    foreach ($queries as $title => $sql) {
        if (!$allocReady && (
            str_contains($title, 'without allocation')
            || str_contains($title, 'Allocation total')
            || str_contains($title, 'Overdue posted')
        )) {
            if (str_contains($title, 'without allocation') || str_contains($title, 'Allocation total')) {
                $sections[] = [
                    'title' => $title,
                    'rows' => [['info' => 'Skipped — run construction_finance_consolidation.sql for allocations.']],
                ];
                continue;
            }
            // Overdue without alloc table: paid = 0
            $sql = "
                SELECT si.id, si.invoice_number, s.supplier_name, si.due_date, si.total, 0 AS paid_amount
                FROM co_supplier_invoices si
                JOIN co_suppliers s ON s.id = si.supplier_id AND s.company_id = si.company_id
                WHERE si.company_id = ?
                  AND si.journal_id IS NOT NULL
                  AND COALESCE(si.due_date, si.invoice_date) < CURDATE()
                  AND si.total > 0.005
                ORDER BY COALESCE(si.due_date, si.invoice_date) ASC
                LIMIT 100
            ";
        }
        try {
            $st = $conn->prepare($sql);
            $st->execute([$companyId]);
            $sections[] = ['title' => $title, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []];
        } catch (Throwable $e) {
            $sections[] = ['title' => $title, 'rows' => [['error' => $e->getMessage()]]];
        }
    }

    return $sections;
}

/* ========== Phase 1 — Invoice lifecycle (strict immutability) ========== */

const CO_SUPPLIER_INV_STATUSES = ['draft', 'posted', 'partially_paid', 'paid', 'voided'];

function co_supplier_invoice_lifecycle_ready(PDO $conn): bool {
    return function_exists('co_db_column_exists')
        && co_db_column_exists($conn, 'co_supplier_invoices', 'status');
}

function co_supplier_invoice_load(PDO $conn, int $companyId, int $invoiceId): ?array {
    if ($companyId <= 0 || $invoiceId <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT si.*, s.supplier_name
        FROM co_supplier_invoices si
        JOIN co_suppliers s ON s.id = si.supplier_id AND s.company_id = si.company_id
        WHERE si.id = ? AND si.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function co_supplier_invoice_status(array $invoice): string {
    $status = strtolower(trim((string)($invoice['status'] ?? '')));
    if (in_array($status, CO_SUPPLIER_INV_STATUSES, true)) {
        return $status;
    }
    // Legacy rows before Phase 1 migration
    return !empty($invoice['journal_id']) ? 'posted' : 'draft';
}

function co_supplier_invoice_can_edit(array $invoice): bool {
    return co_supplier_invoice_status($invoice) === 'draft';
}

function co_supplier_invoice_is_readonly(array $invoice): bool {
    return !co_supplier_invoice_can_edit($invoice);
}

function co_supplier_invoice_status_label(string $status): string {
    $map = [
        'draft' => 'Draft',
        'posted' => 'Posted',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
        'voided' => 'Voided',
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function co_supplier_invoice_status_badge_class(string $status): string {
    $map = [
        'draft' => 'secondary',
        'posted' => 'primary',
        'partially_paid' => 'warning text-dark',
        'paid' => 'success',
        'voided' => 'dark',
    ];
    return $map[$status] ?? 'secondary';
}

/**
 * Refresh status from journal + allocations. Never moves voided/draft incorrectly.
 */
function co_supplier_invoice_refresh_status(PDO $conn, int $companyId, int $invoiceId): string {
    $inv = co_supplier_invoice_load($conn, $companyId, $invoiceId);
    if (!$inv) {
        return '';
    }
    if (!co_supplier_invoice_lifecycle_ready($conn)) {
        return co_supplier_invoice_status($inv);
    }
    $current = co_supplier_invoice_status($inv);
    if ($current === 'voided') {
        return 'voided';
    }
    if (empty($inv['journal_id'])) {
        $conn->prepare("UPDATE co_supplier_invoices SET status = 'draft' WHERE id = ? AND company_id = ?")
            ->execute([$invoiceId, $companyId]);
        return 'draft';
    }
    $paid = function_exists('co_supplier_invoice_paid_amount')
        ? co_supplier_invoice_paid_amount($conn, $companyId, $invoiceId)
        : 0.0;
    $total = round((float)$inv['total'], 2);
    if ($total > 0 && $paid + 0.005 >= $total) {
        $next = 'paid';
    } elseif ($paid > 0.005) {
        $next = 'partially_paid';
    } else {
        $next = 'posted';
    }
    $conn->prepare("UPDATE co_supplier_invoices SET status = ? WHERE id = ? AND company_id = ? AND status <> 'voided'")
        ->execute([$next, $invoiceId, $companyId]);
    return $next;
}

function co_supplier_unique_invoice_number(PDO $conn, int $companyId, int $supplierId, string $base): string {
    $base = trim($base) !== '' ? $base : ('INV-' . date('Ymd'));
    $base = substr(preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?: ('INV-' . date('Ymd')), 0, 80);
    $candidate = $base;
    $i = 2;
    $st = $conn->prepare("
        SELECT id FROM co_supplier_invoices
        WHERE company_id = ? AND supplier_id = ? AND invoice_number = ?
        LIMIT 1
    ");
    while (true) {
        $st->execute([$companyId, $supplierId, $candidate]);
        if (!$st->fetchColumn()) {
            return $candidate;
        }
        $candidate = substr($base, 0, 88) . '-' . $i;
        $i++;
        if ($i > 500) {
            return $base . '-' . uniqid();
        }
    }
}

/**
 * Void a posted unpaid invoice (reverse GL). Drafts should use delete instead.
 * @return array{success:bool,reversal_journal_id:?int,already_void?:bool,error:?string}
 */
function co_supplier_void_invoice(
    PDO $conn,
    int $companyId,
    int $invoiceId,
    string $reason,
    ?int $userId = null
): array {
    if (!function_exists('reverse_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    if (!co_supplier_invoice_lifecycle_ready($conn)) {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => 'Run migrations/construction_supplier_ap_phase1_lifecycle.sql'];
    }
    $inv = co_supplier_invoice_load($conn, $companyId, $invoiceId);
    if (!$inv) {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => 'Invoice not found.'];
    }
    $status = co_supplier_invoice_status($inv);
    if ($status === 'voided') {
        return ['success' => true, 'reversal_journal_id' => null, 'already_void' => true, 'error' => null];
    }
    if ($status === 'draft') {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => 'Draft invoices should be deleted, not voided.'];
    }
    if (in_array($status, ['partially_paid', 'paid'], true)) {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => 'Reverse or reallocate payments before voiding this invoice.'];
    }
    $paid = co_supplier_invoice_paid_amount($conn, $companyId, $invoiceId);
    if ($paid > 0.005) {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => 'This invoice has payments allocated. Reverse/unallocate payments before voiding.'];
    }
    if (function_exists('co_supplier_reverse_advance_vat_links_on_invoice')) {
        co_supplier_reverse_advance_vat_links_on_invoice($conn, $companyId, $invoiceId, $userId);
    } elseif (is_file(__DIR__ . '/construction_supplier_advance_vat_helpers.php')) {
        require_once __DIR__ . '/construction_supplier_advance_vat_helpers.php';
        co_supplier_reverse_advance_vat_links_on_invoice($conn, $companyId, $invoiceId, $userId);
    }
    $journalId = (int)($inv['journal_id'] ?? 0);
    if ($journalId <= 0) {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => 'Posted journal was not found for this invoice.'];
    }

    // reverse_journal / create_and_post_journal own their transactions — do not wrap them.
    $reverse = reverse_journal($journalId, $reason !== '' ? $reason : 'Supplier invoice voided', $userId);
    if (empty($reverse['success'])) {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => $reverse['error'] ?? 'Journal reversal failed.'];
    }
    $reversalId = isset($reverse['reversal_journal_id']) ? (int)$reverse['reversal_journal_id'] : null;
    try {
        $conn->prepare("
            UPDATE co_supplier_invoices
            SET status = 'voided',
                void_reason = ?,
                voided_at = NOW(),
                voided_by = ?,
                updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ")->execute([
            $reason !== '' ? $reason : 'Voided',
            $userId,
            $invoiceId,
            $companyId,
        ]);
        co_supplier_ap_audit(
            $conn,
            $companyId,
            (int)$inv['supplier_id'],
            $invoiceId,
            null,
            'invoice_voided',
            $status,
            'voided',
            (float)$inv['total'],
            $reason !== '' ? $reason : 'Supplier invoice voided',
            $userId,
            'supplier_invoice',
            $reversalId ?: $journalId
        );
        return ['success' => true, 'reversal_journal_id' => $reversalId, 'error' => null];
    } catch (Throwable $e) {
        return ['success' => false, 'reversal_journal_id' => $reversalId, 'error' => $e->getMessage()];
    }
}

/**
 * Copy invoice (and lines) as a new draft linked via amended_from_invoice_id.
 * @return array{success:bool,invoice_id:?int,error:?string}
 */
function co_supplier_copy_invoice_as_draft(
    PDO $conn,
    int $companyId,
    int $invoiceId,
    ?int $userId = null,
    string $reason = 'Amendment copy'
): array {
    $inv = co_supplier_invoice_load($conn, $companyId, $invoiceId);
    if (!$inv) {
        return ['success' => false, 'invoice_id' => null, 'error' => 'Invoice not found.'];
    }
    $supplierId = (int)$inv['supplier_id'];
    $newNumber = co_supplier_unique_invoice_number(
        $conn,
        $companyId,
        $supplierId,
        (string)$inv['invoice_number'] . '-AMEND-' . date('Ymd')
    );
    $desc = trim((string)($inv['description'] ?? ''));
    $desc = trim($desc . "\nAmendment of invoice #" . $inv['invoice_number'] . '. ' . $reason);

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $cols = ['company_id', 'supplier_id', 'project_id', 'invoice_number', 'invoice_date', 'due_date',
            'subtotal', 'vat_pct', 'vat_amount', 'total', 'description', 'reference', 'created_by'];
        $vals = [
            $companyId, $supplierId, $inv['project_id'] ?: null, $newNumber, $inv['invoice_date'], $inv['due_date'] ?: null,
            (float)$inv['subtotal'], (float)$inv['vat_pct'], (float)$inv['vat_amount'], (float)$inv['total'],
            $desc ?: null, $inv['reference'] ?: null, $userId,
        ];
        if (co_db_column_exists($conn, 'co_supplier_invoices', 'expense_account_id')) {
            $cols[] = 'expense_account_id';
            $vals[] = $inv['expense_account_id'] ?? null;
        }
        foreach (['payment_terms', 'place_of_supply', 'vat_treatment', 'order_number', 'permit_number'] as $opt) {
            if (co_db_column_exists($conn, 'co_supplier_invoices', $opt)) {
                $cols[] = $opt;
                $vals[] = $inv[$opt] ?? null;
            }
        }
        if (co_supplier_invoice_lifecycle_ready($conn)) {
            $cols[] = 'status';
            $vals[] = 'draft';
            $cols[] = 'amended_from_invoice_id';
            $vals[] = $invoiceId;
        }
        $colSql = implode(', ', $cols);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare("INSERT INTO co_supplier_invoices ($colSql) VALUES ($ph)")->execute($vals);
        $newId = (int)$conn->lastInsertId();

        if (co_db_table_exists($conn, 'co_supplier_invoice_items')) {
            $lines = $conn->prepare("SELECT * FROM co_supplier_invoice_items WHERE company_id = ? AND invoice_id = ? ORDER BY id");
            $lines->execute([$companyId, $invoiceId]);
            $rows = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows) {
                $sample = $rows[0];
                $insertCols = array_values(array_filter(array_keys($sample), static fn($c) => $c !== 'id'));
                $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
                $colSql2 = implode(',', array_map(static fn($c) => '`' . str_replace('`', '', $c) . '`', $insertCols));
                $lineIns = $conn->prepare("INSERT INTO co_supplier_invoice_items ($colSql2) VALUES ($placeholders)");
                foreach ($rows as $ln) {
                    $rowVals = [];
                    foreach ($insertCols as $c) {
                        $rowVals[] = ($c === 'invoice_id') ? $newId : $ln[$c];
                    }
                    $lineIns->execute($rowVals);
                }
            }
        }

        co_supplier_ap_audit(
            $conn,
            $companyId,
            $supplierId,
            $newId,
            null,
            'invoice_amend_draft_created',
            (string)$invoiceId,
            (string)$newId,
            (float)$inv['total'],
            $reason,
            $userId,
            'supplier_invoice',
            null
        );
        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'invoice_id' => $newId, 'error' => null];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'invoice_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Void posted unpaid invoice then create linked amendment draft.
 * @return array{success:bool,void:?array,draft_invoice_id:?int,error:?string}
 */
function co_supplier_void_and_amend_invoice(
    PDO $conn,
    int $companyId,
    int $invoiceId,
    string $reason,
    ?int $userId = null
): array {
    $void = co_supplier_void_invoice($conn, $companyId, $invoiceId, $reason !== '' ? $reason : 'Void for amendment', $userId);
    if (empty($void['success'])) {
        return ['success' => false, 'void' => $void, 'draft_invoice_id' => null, 'error' => $void['error'] ?? 'Void failed'];
    }
    $copy = co_supplier_copy_invoice_as_draft($conn, $companyId, $invoiceId, $userId, $reason !== '' ? $reason : 'Void + Amend');
    if (empty($copy['success'])) {
        return ['success' => false, 'void' => $void, 'draft_invoice_id' => null, 'error' => $copy['error'] ?? 'Amend draft failed'];
    }
    return [
        'success' => true,
        'void' => $void,
        'draft_invoice_id' => (int)$copy['invoice_id'],
        'error' => null,
    ];
}

/**
 * Guarded payment reverse: reverse GL, remove allocations, refresh invoice statuses, delete payment row.
 * @return array{success:bool,error:?string,invoice_ids:list<int>}
 */
function co_supplier_reverse_payment(
    PDO $conn,
    int $companyId,
    int $paymentId,
    string $reason,
    ?int $userId = null
): array {
    if (!function_exists('reverse_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    if (!function_exists('co_supplier_advance_schema_ready')) {
        require_once __DIR__ . '/construction_supplier_advance_helpers.php';
    }
    $stmt = $conn->prepare("SELECT * FROM co_supplier_payments WHERE id = ? AND company_id = ?");
    $stmt->execute([$paymentId, $companyId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) {
        return ['success' => false, 'error' => 'Payment not found.', 'invoice_ids' => []];
    }

    $invoiceIds = [];
    if (function_exists('co_supplier_allocations_ready') && co_supplier_allocations_ready($conn)) {
        $a = $conn->prepare("SELECT invoice_id FROM co_supplier_payment_allocations WHERE company_id = ? AND payment_id = ?");
        $a->execute([$companyId, $paymentId]);
        $invoiceIds = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    if (co_supplier_advance_schema_ready($conn)) {
        $apps = $conn->prepare("
            SELECT COUNT(*) FROM co_supplier_advance_applications
            WHERE company_id = ? AND supplier_payment_id = ? AND status = 'posted'
        ");
        $apps->execute([$companyId, $paymentId]);
        if ((int)$apps->fetchColumn() > 0) {
            return [
                'success' => false,
                'error' => 'Unapply all advance applications from this payment before reversing it.',
                'invoice_ids' => $invoiceIds,
            ];
        }
        if (function_exists('co_supplier_payment_advance_vat_posted')
            && co_supplier_payment_advance_vat_posted($conn, $companyId, $paymentId) > 0.005) {
            return [
                'success' => false,
                'error' => 'Reverse all Advance VAT documents on this payment before reversing it.',
                'invoice_ids' => $invoiceIds,
            ];
        }
        if (function_exists('co_supplier_payment_advance_refunded')
            && co_supplier_payment_advance_refunded($conn, $companyId, $paymentId) > 0.005) {
            return [
                'success' => false,
                'error' => 'Reverse all advance refunds on this payment before reversing it.',
                'invoice_ids' => $invoiceIds,
            ];
        }
    }

    $journalId = (int)($pay['journal_id'] ?? 0);
    // reverse_journal owns its transaction — call before local deletes.
    if ($journalId > 0) {
        $reverse = reverse_journal($journalId, $reason !== '' ? $reason : 'Supplier payment reversed', $userId);
        if (empty($reverse['success'])) {
            return ['success' => false, 'error' => $reverse['error'] ?? 'Could not reverse payment journal.', 'invoice_ids' => $invoiceIds];
        }
    }

    try {
        $advanceRemaining = 0.0;
        if (co_supplier_advance_schema_ready($conn)) {
            $advanceRemaining = co_supplier_payment_advance_remaining($conn, $companyId, $paymentId);
            if ($advanceRemaining > 0.005) {
                co_supplier_adjust_advance_balance($conn, $companyId, (int)$pay['supplier_id'], -$advanceRemaining);
            }
        }
        if (function_exists('co_supplier_allocations_ready') && co_supplier_allocations_ready($conn)) {
            $conn->prepare("DELETE FROM co_supplier_payment_allocations WHERE company_id = ? AND payment_id = ?")
                ->execute([$companyId, $paymentId]);
        }
        $conn->prepare("DELETE FROM co_supplier_payments WHERE id = ? AND company_id = ?")
            ->execute([$paymentId, $companyId]);
        foreach ($invoiceIds as $invId) {
            if ($invId > 0) {
                co_supplier_invoice_refresh_status($conn, $companyId, $invId);
            }
        }
        co_supplier_ap_audit(
            $conn,
            $companyId,
            (int)$pay['supplier_id'],
            null,
            $paymentId,
            'payment_reversed',
            $advanceRemaining > 0.005 ? (string)$advanceRemaining : null,
            (string)($pay['reference'] ?? ''),
            (float)$pay['amount'],
            $reason !== '' ? $reason : 'Supplier payment reversed',
            $userId,
            'supplier_payment',
            $journalId ?: null
        );
        return ['success' => true, 'error' => null, 'invoice_ids' => $invoiceIds];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'invoice_ids' => $invoiceIds];
    }
}

/**
 * Optional status filter SQL fragment for open/posted (non-draft, non-void) invoices.
 */
function co_supplier_invoice_open_status_sql(PDO $conn, string $alias = 'si'): string {
    if (!co_supplier_invoice_lifecycle_ready($conn)) {
        return '';
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'si';
    return " AND {$a}.status NOT IN ('draft', 'voided') ";
}

