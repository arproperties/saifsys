<?php
/**
 * Construction Module Helpers
 */

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Format money for display
 */
function co_format_money($amount, $currency = 'AED') {
    return $currency . ' ' . number_format((float)$amount, 2);
}

/**
 * Get project status badge class
 */
function co_project_status_badge($status) {
    $map = [
        'draft' => 'secondary',
        'active' => 'success',
        'on_hold' => 'warning',
        'completed' => 'info',
        'cancelled' => 'danger'
    ];
    return $map[$status] ?? 'secondary';
}

/**
 * Get project status display label
 */
function co_project_status_label($status) {
    $map = [
        'draft' => 'Draft',
        'active' => 'Active',
        'on_hold' => 'On Hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled'
    ];
    return $map[$status] ?? ucfirst($status ?? '');
}

function co_db_table_exists(PDO $conn, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        $cache[$table] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function co_db_column_exists(PDO $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function co_supplier_allocations_ready(PDO $conn): bool {
    return co_db_table_exists($conn, 'co_supplier_payment_allocations');
}

function co_payment_account_column_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_supplier_payments', 'pay_account_id');
}

function co_expense_account_column_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_supplier_invoices', 'expense_account_id');
}

function co_supplier_invoice_bill_fields_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_supplier_invoices', 'notes')
        && co_db_column_exists($conn, 'co_supplier_invoice_items', 'vat_treatment');
}

function co_supplier_invoice_ensure_bill_fields(PDO $conn): void {
    co_supplier_invoice_full_schema($conn);
    $invoiceColumns = [
        'payment_terms' => "ALTER TABLE co_supplier_invoices ADD COLUMN payment_terms VARCHAR(100) DEFAULT NULL AFTER due_date",
        'place_of_supply' => "ALTER TABLE co_supplier_invoices ADD COLUMN place_of_supply VARCHAR(100) DEFAULT 'Dubai' AFTER payment_terms",
        'vat_treatment' => "ALTER TABLE co_supplier_invoices ADD COLUMN vat_treatment VARCHAR(30) NOT NULL DEFAULT 'vat_registered' AFTER place_of_supply",
        'order_number' => "ALTER TABLE co_supplier_invoices ADD COLUMN order_number VARCHAR(100) DEFAULT NULL AFTER vat_treatment",
        'permit_number' => "ALTER TABLE co_supplier_invoices ADD COLUMN permit_number VARCHAR(100) DEFAULT NULL AFTER order_number",
        'notes' => "ALTER TABLE co_supplier_invoices ADD COLUMN notes TEXT DEFAULT NULL AFTER reference",
    ];
    foreach ($invoiceColumns as $column => $sql) {
        if (!co_db_column_exists($conn, 'co_supplier_invoices', $column)) {
            try {
                $conn->exec($sql);
            } catch (Throwable $e) {
                // ignore duplicate column races
            }
        }
    }
    if (!co_db_column_exists($conn, 'co_supplier_invoice_items', 'vat_treatment')) {
        try {
            $conn->exec("ALTER TABLE co_supplier_invoice_items ADD COLUMN vat_treatment VARCHAR(30) NOT NULL DEFAULT 'standard' AFTER subtotal");
        } catch (Throwable $e) {
            // ignore duplicate column races
        }
    }
}

function co_supplier_invoice_full_schema(PDO $conn): void {
    // Skip CREATE when tables already exist — MySQL DDL (including IF NOT EXISTS)
    // implicitly commits any open transaction and breaks outer begin/commit flows.
    if (!co_db_table_exists($conn, 'co_supplier_invoice_items')) {
        $conn->exec("
            CREATE TABLE co_supplier_invoice_items (
                id INT(11) NOT NULL AUTO_INCREMENT,
                company_id INT(11) NOT NULL,
                invoice_id INT(11) NOT NULL,
                project_id INT(11) DEFAULT NULL,
                expense_account_id INT(11) DEFAULT NULL,
                description VARCHAR(500) DEFAULT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                vat_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_co_supplier_items_invoice (company_id, invoice_id),
                KEY idx_co_supplier_items_project (project_id),
                KEY idx_co_supplier_items_account (expense_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
    if (!co_db_table_exists($conn, 'co_supplier_recurring_invoices')) {
        $conn->exec("
            CREATE TABLE co_supplier_recurring_invoices (
                id INT(11) NOT NULL AUTO_INCREMENT,
                company_id INT(11) NOT NULL,
                supplier_id INT(11) NOT NULL,
                source_invoice_id INT(11) DEFAULT NULL,
                template_name VARCHAR(255) NOT NULL,
                invoice_number_prefix VARCHAR(100) DEFAULT NULL,
                frequency ENUM('weekly','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly',
                next_invoice_date DATE DEFAULT NULL,
                end_date DATE DEFAULT NULL,
                due_days INT(11) NOT NULL DEFAULT 0,
                description VARCHAR(500) DEFAULT NULL,
                reference VARCHAR(100) DEFAULT NULL,
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                lines_json LONGTEXT DEFAULT NULL,
                status ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
                last_generated_invoice_date DATE DEFAULT NULL,
                last_generated_invoice_id INT(11) DEFAULT NULL,
                created_by INT(11) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_co_rec_supplier_company (company_id, status, next_invoice_date),
                KEY idx_co_rec_supplier_supplier (company_id, supplier_id),
                KEY idx_co_rec_supplier_source (source_invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

function co_parse_supplier_invoice_items(array $items, ?int $defaultProjectId = null, ?int $defaultAccountId = null, float $defaultVatPct = 5.0): array {
    $lines = [];
    $subtotal = 0.0;
    $vat = 0.0;
    $total = 0.0;
    foreach ($items as $item) {
        $description = trim((string)($item['description'] ?? ''));
        $projectId = (int)($item['project_id'] ?? 0) ?: $defaultProjectId;
        $accountId = (int)($item['expense_account_id'] ?? 0) ?: $defaultAccountId;
        $qty = (float)($item['quantity'] ?? 1);
        $rate = (float)($item['unit_price'] ?? 0);
        if ($qty <= 0 || $rate < 0 || $accountId <= 0) continue;

        $vatTreatment = trim((string)($item['vat_treatment'] ?? ''));
        if ($vatTreatment !== '') {
            $vatRate = isset($item['vat_rate']) && $item['vat_rate'] !== ''
                ? (float)$item['vat_rate']
                : $defaultVatPct;
            $lineSubtotal = round($qty * $rate, 2);
            $lineVat = ($vatTreatment === 'standard' && $vatRate > 0)
                ? round($lineSubtotal * ($vatRate / 100), 2)
                : 0.0;
            $vatPct = ($vatTreatment === 'standard') ? $vatRate : 0.0;
        } else {
            $vatPct = isset($item['vat_pct']) && $item['vat_pct'] !== '' ? (float)$item['vat_pct'] : $defaultVatPct;
            $lineSubtotal = round($qty * $rate, 2);
            $lineVat = round($lineSubtotal * ($vatPct / 100), 2);
            $vatTreatment = $vatPct > 0 ? 'standard' : 'out_of_scope';
        }
        $lineTotal = $lineSubtotal + $lineVat;
        $lines[] = [
            'description' => $description !== '' ? $description : 'Supplier invoice line',
            'project_id' => $projectId ?: null,
            'expense_account_id' => $accountId,
            'quantity' => $qty,
            'unit_price' => $rate,
            'subtotal' => $lineSubtotal,
            'vat_treatment' => $vatTreatment,
            'vat_pct' => $vatPct,
            'vat_amount' => $lineVat,
            'line_total' => $lineTotal,
        ];
        $subtotal += $lineSubtotal;
        $vat += $lineVat;
        $total += $lineTotal;
    }
    return ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'vat_amount' => round($vat, 2), 'total' => round($total, 2)];
}

function co_insert_supplier_invoice_items(PDO $conn, int $companyId, int $invoiceId, array $lines): void {
    if (!$lines) return;
    co_supplier_invoice_full_schema($conn);
    $hasVatTreatment = co_db_column_exists($conn, 'co_supplier_invoice_items', 'vat_treatment');
    if ($hasVatTreatment) {
        $stmt = $conn->prepare("
            INSERT INTO co_supplier_invoice_items
                (company_id, invoice_id, project_id, expense_account_id, description, quantity, unit_price, subtotal, vat_treatment, vat_pct, vat_amount, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO co_supplier_invoice_items
                (company_id, invoice_id, project_id, expense_account_id, description, quantity, unit_price, subtotal, vat_pct, vat_amount, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
    }
    foreach ($lines as $line) {
        if ($hasVatTreatment) {
            $stmt->execute([
                $companyId,
                $invoiceId,
                $line['project_id'] ?: null,
                $line['expense_account_id'] ?: null,
                $line['description'] ?: null,
                $line['quantity'],
                $line['unit_price'],
                $line['subtotal'],
                $line['vat_treatment'] ?? 'standard',
                $line['vat_pct'],
                $line['vat_amount'],
                $line['line_total'],
            ]);
        } else {
            $stmt->execute([
                $companyId,
                $invoiceId,
                $line['project_id'] ?: null,
                $line['expense_account_id'] ?: null,
                $line['description'] ?: null,
                $line['quantity'],
                $line['unit_price'],
                $line['subtotal'],
                $line['vat_pct'],
                $line['vat_amount'],
                $line['line_total'],
            ]);
        }
    }
}

function co_supplier_invoice_lines(PDO $conn, int $companyId, int $invoiceId): array {
    if (!co_db_table_exists($conn, 'co_supplier_invoice_items')) return [];
    $stmt = $conn->prepare("SELECT * FROM co_supplier_invoice_items WHERE company_id = ? AND invoice_id = ? ORDER BY id");
    $stmt->execute([$companyId, $invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function co_supplier_recurring_next_date(string $invoiceDate, string $frequency): string {
    $step = ['weekly'=>'+1 week','quarterly'=>'+3 months','semi_annual'=>'+6 months','annual'=>'+1 year','monthly'=>'+1 month'][$frequency] ?? '+1 month';
    return date('Y-m-d', strtotime($step, strtotime($invoiceDate)));
}

function co_supplier_recurring_invoice_number(PDO $conn, int $companyId, array $rec, string $invoiceDate): string {
    $prefix = trim((string)($rec['invoice_number_prefix'] ?? '')) ?: ('REC-SINV-' . (int)$rec['id']);
    $prefix = preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefix) ?: ('REC-SINV-' . (int)$rec['id']);
    $base = substr($prefix, 0, 70) . '-' . date('Ymd', strtotime($invoiceDate));
    $candidate = $base;
    $i = 2;
    $stmt = $conn->prepare("SELECT id FROM co_supplier_invoices WHERE company_id = ? AND supplier_id = ? AND invoice_number = ? LIMIT 1");
    while (true) {
        $stmt->execute([$companyId, (int)$rec['supplier_id'], $candidate]);
        if (!$stmt->fetchColumn()) return $candidate;
        $candidate = substr($base, 0, 92) . '-' . $i++;
    }
}

function co_generate_due_recurring_supplier_invoices(PDO $conn, int $companyId, ?int $userId = null): array {
    $result = ['generated' => 0, 'invoice_ids' => [], 'errors' => []];
    try {
        co_supplier_invoice_full_schema($conn);
        $due = $conn->prepare("SELECT id FROM co_supplier_recurring_invoices WHERE company_id = ? AND status = 'active' AND next_invoice_date IS NOT NULL AND next_invoice_date <= CURDATE() AND (end_date IS NULL OR next_invoice_date <= end_date) ORDER BY next_invoice_date ASC, id ASC LIMIT 50");
        $due->execute([$companyId]);
        foreach (array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN) ?: []) as $id) {
            try {
                $conn->beginTransaction();
                $lock = $conn->prepare("SELECT * FROM co_supplier_recurring_invoices WHERE id = ? AND company_id = ? FOR UPDATE");
                $lock->execute([$id, $companyId]);
                $rec = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$rec || $rec['status'] !== 'active' || empty($rec['next_invoice_date']) || $rec['next_invoice_date'] > date('Y-m-d')) {
                    $conn->commit();
                    continue;
                }
                $invoiceDate = (string)$rec['next_invoice_date'];
                if (!empty($rec['last_generated_invoice_date']) && $rec['last_generated_invoice_date'] === $invoiceDate) {
                    $next = co_supplier_recurring_next_date($invoiceDate, (string)$rec['frequency']);
                    $newStatus = (!empty($rec['end_date']) && $next > $rec['end_date']) ? 'paused' : 'active';
                    $conn->prepare("UPDATE co_supplier_recurring_invoices SET next_invoice_date = ?, status = ? WHERE id = ? AND company_id = ?")->execute([$newStatus === 'paused' ? null : $next, $newStatus, $id, $companyId]);
                    $conn->commit();
                    continue;
                }
                $lines = json_decode((string)($rec['lines_json'] ?? ''), true);
                if (!is_array($lines) || !$lines) throw new RuntimeException('Recurring template has no lines.');
                $lineData = co_parse_supplier_invoice_items($lines, null, null, 5);
                if (!$lineData['lines']) throw new RuntimeException('Recurring template has no valid invoice lines.');
                $invoiceNumber = co_supplier_recurring_invoice_number($conn, $companyId, $rec, $invoiceDate);
                $dueDate = date('Y-m-d', strtotime('+' . max(0, (int)$rec['due_days']) . ' days', strtotime($invoiceDate)));
                $headerAccount = (int)($lineData['lines'][0]['expense_account_id'] ?? 0) ?: null;
                $headerProject = (int)($lineData['lines'][0]['project_id'] ?? 0) ?: null;
                if (co_expense_account_column_ready($conn)) {
                    $ins = $conn->prepare("INSERT INTO co_supplier_invoices (company_id, supplier_id, project_id, expense_account_id, invoice_number, invoice_date, due_date, subtotal, vat_pct, vat_amount, total, description, reference, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->execute([$companyId, (int)$rec['supplier_id'], $headerProject, $headerAccount, $invoiceNumber, $invoiceDate, $dueDate, $lineData['subtotal'], $lineData['subtotal'] > 0 ? round($lineData['vat_amount'] / $lineData['subtotal'] * 100, 2) : 0, $lineData['vat_amount'], $lineData['total'], $rec['description'] ?: null, $rec['reference'] ?: null, $userId]);
                } else {
                    $ins = $conn->prepare("INSERT INTO co_supplier_invoices (company_id, supplier_id, project_id, invoice_number, invoice_date, due_date, subtotal, vat_pct, vat_amount, total, description, reference, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->execute([$companyId, (int)$rec['supplier_id'], $headerProject, $invoiceNumber, $invoiceDate, $dueDate, $lineData['subtotal'], $lineData['subtotal'] > 0 ? round($lineData['vat_amount'] / $lineData['subtotal'] * 100, 2) : 0, $lineData['vat_amount'], $lineData['total'], $rec['description'] ?: null, $rec['reference'] ?: null, $userId]);
                }
                $invoiceId = (int)$conn->lastInsertId();
                co_insert_supplier_invoice_items($conn, $companyId, $invoiceId, $lineData['lines']);
                $post = co_post_supplier_invoice_to_accounting($invoiceId, $companyId, $userId);
                if (empty($post['success'])) throw new RuntimeException($post['error'] ?? 'Accounting posting failed.');
                if (!empty($post['journal_id'])) {
                    $conn->prepare("UPDATE co_supplier_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")->execute([(int)$post['journal_id'], $invoiceId, $companyId]);
                }
                $next = co_supplier_recurring_next_date($invoiceDate, (string)$rec['frequency']);
                $newStatus = (!empty($rec['end_date']) && $next > $rec['end_date']) ? 'paused' : 'active';
                $conn->prepare("UPDATE co_supplier_recurring_invoices SET last_generated_invoice_date = ?, last_generated_invoice_id = ?, next_invoice_date = ?, status = ? WHERE id = ? AND company_id = ?")->execute([$invoiceDate, $invoiceId, $newStatus === 'paused' ? null : $next, $newStatus, $id, $companyId]);
                $conn->commit();
                $result['generated']++;
                $result['invoice_ids'][] = $invoiceId;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $result['errors'][] = 'Template #' . $id . ': ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    if ($result['errors']) error_log('Construction recurring supplier invoice warning: ' . implode(' | ', array_slice($result['errors'], 0, 5)));
    return $result;
}

function co_fetch_expense_accounts(PDO $conn, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT id, account_code, account_name, account_type
        FROM re_chart_of_accounts
        WHERE company_id = ?
          AND is_active = 1
          AND is_header = 0
        ORDER BY account_type, account_code
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function co_default_expense_account_code(?string $projectType): string {
    return ($projectType === 'OWNER') ? '1515' : '5125';
}

function co_supplier_invoice_paid_amount(PDO $conn, int $companyId, int $invoiceId): float {
    $paid = 0.0;
    if (co_supplier_allocations_ready($conn)) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(allocated_amount), 0)
            FROM co_supplier_payment_allocations
            WHERE company_id = ? AND invoice_id = ?
        ");
        $stmt->execute([$companyId, $invoiceId]);
        $paid += (float)$stmt->fetchColumn();
    }
    if (function_exists('co_supplier_advance_schema_ready') && co_supplier_advance_schema_ready($conn)) {
        if (!function_exists('co_supplier_advance_applied_on_invoice')) {
            require_once __DIR__ . '/construction_supplier_advance_helpers.php';
        }
        $paid += co_supplier_advance_applied_on_invoice($conn, $companyId, $invoiceId);
    }
    return round($paid, 2);
}

function co_fetch_payment_accounts(PDO $conn, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT id, account_code, account_name, account_type
        FROM re_chart_of_accounts
        WHERE company_id = ?
          AND is_active = 1
          AND is_header = 0
          AND account_type = 'Asset'
          AND (
            account_code LIKE '11%'
            OR account_code LIKE '12%'
            OR LOWER(account_name) LIKE '%cash%'
            OR LOWER(account_name) LIKE '%bank%'
          )
        ORDER BY account_code
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function co_auto_allocate_supplier_payment(PDO $conn, int $companyId, int $supplierId, int $paymentId, float $amount): void {
    if (!co_supplier_allocations_ready($conn) || $amount <= 0) {
        return;
    }

    $statusSql = '';
    if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
        $statusSql = " AND si.status NOT IN ('draft', 'voided') ";
    }

    $stmt = $conn->prepare("
        SELECT si.id, si.total,
               COALESCE(SUM(a.allocated_amount), 0) AS allocated_amount
        FROM co_supplier_invoices si
        LEFT JOIN co_supplier_payment_allocations a
               ON a.invoice_id = si.id
              AND a.company_id = si.company_id
        WHERE si.company_id = ?
          AND si.supplier_id = ?
          AND si.journal_id IS NOT NULL
          {$statusSql}
        GROUP BY si.id, si.total, si.due_date, si.invoice_date
        HAVING si.total - allocated_amount > 0.005
        ORDER BY COALESCE(si.due_date, si.invoice_date) ASC, si.invoice_date ASC, si.id ASC
    ");
    $stmt->execute([$companyId, $supplierId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = round($amount, 2);
    $insert = $conn->prepare("
        INSERT INTO co_supplier_payment_allocations
            (company_id, payment_id, invoice_id, allocated_amount)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE allocated_amount = allocated_amount + VALUES(allocated_amount)
    ");

    $touched = [];
    foreach ($invoices as $invoice) {
        if ($remaining <= 0.005) {
            break;
        }
        $alreadyPaid = function_exists('co_supplier_invoice_paid_amount')
            ? co_supplier_invoice_paid_amount($conn, $companyId, (int)$invoice['id'])
            : (float)$invoice['allocated_amount'];
        $open = round((float)$invoice['total'] - $alreadyPaid, 2);
        if ($open <= 0) {
            continue;
        }
        $allocate = min($remaining, $open);
        $insert->execute([$companyId, $paymentId, (int)$invoice['id'], $allocate]);
        $touched[] = (int)$invoice['id'];
        $remaining = round($remaining - $allocate, 2);
    }
    if (function_exists('co_supplier_invoice_refresh_status')) {
        foreach (array_unique($touched) as $invId) {
            co_supplier_invoice_refresh_status($conn, $companyId, $invId);
        }
    }
}

/** Allowed document file extensions (lowercase) */
function co_document_allowed_extensions() {
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
}

/** Max upload size in bytes (10 MB) */
function co_document_max_upload_bytes() {
    return 10 * 1024 * 1024;
}

/**
 * Handle document file upload. Returns ['path' => relative path] or ['error' => message].
 * Relative path is suitable for storing in co_documents.file_path and for URL: appBase/path
 */
function co_handle_document_upload($file_key = 'document_file') {
    if (empty($_FILES[$file_key]) || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'No file selected.'];
    }
    $f = $_FILES[$file_key];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msg = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server limit.',
            UPLOAD_ERR_FORM_SIZE => 'File too large.',
            UPLOAD_ERR_PARTIAL => 'Upload incomplete.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload error.',
            UPLOAD_ERR_CANT_WRITE => 'Server cannot save file.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server.'
        ];
        return ['error' => $msg[$f['error']] ?? 'Upload failed.'];
    }
    if ($f['size'] > co_document_max_upload_bytes()) {
        return ['error' => 'File too large. Maximum ' . (co_document_max_upload_bytes() / 1024 / 1024) . ' MB allowed.'];
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, co_document_allowed_extensions(), true)) {
        return ['error' => 'File type not allowed. Allowed: ' . implode(', ', co_document_allowed_extensions()) . '.'];
    }
    $appRoot = dirname(__DIR__, 3);
    $dir = $appRoot . '/uploads/construction_documents';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            return ['error' => 'Cannot create upload directory.'];
        }
    }
    $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $safeName;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        return ['error' => 'Failed to save uploaded file.'];
    }
    return ['path' => 'uploads/construction_documents/' . $safeName];
}

/**
 * Delete a document by id and company_id. Removes DB row and, if local upload, the file.
 * Returns true on success, false if not found or delete failed.
 */
function co_delete_document($conn, $id, $company_id) {
    $id = (int)$id;
    $company_id = (int)$company_id;
    $stmt = $conn->prepare("SELECT file_path FROM co_documents WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $file_path = $row['file_path'];
    $stmt = $conn->prepare("DELETE FROM co_documents WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    if ($stmt->rowCount() === 0) return false;
    if ($file_path && strpos($file_path, 'http') !== 0) {
        $appRoot = dirname(__DIR__, 3);
        $fullPath = $appRoot . '/' . $file_path;
        if (is_file($fullPath)) @unlink($fullPath);
    }
    return true;
}
