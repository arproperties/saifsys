<?php
/**
 * Construction income workflow helpers.
 */

require_once __DIR__ . '/construction_helpers.php';
require_once __DIR__ . '/construction_accounting_integration.php';

function co_client_type_options(): array {
    return [
        'customer' => 'Customer',
        'tenant' => 'Shop Tenant',
        'related_company' => 'Related Company',
        'maintenance_customer' => 'Maintenance Customer',
    ];
}

function co_income_source_options(): array {
    return [
        'construction_project' => 'Construction Project',
        'shop_rental' => 'Shop Rental',
        'shop_commission' => 'Shop Tenant Commission',
        'shop_termination_penalty' => 'Shop Termination Penalty',
        'shop_charge' => 'Shop Contract Charge', // Key Money and custom charges; prefer charge name in UI when joined
        'camp_management' => 'Camp Agent Settlement',
        'maintenance_service' => 'Maintenance Service',
        'manual' => 'Manual Income',
    ];
}

function co_income_source_label(?string $sourceType): string {
    $options = co_income_source_options();
    return $options[$sourceType ?: 'manual'] ?? ucfirst(str_replace('_', ' ', (string)$sourceType));
}

function co_client_invoice_columns_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_client_invoices', 'source_type')
        && co_db_column_exists($conn, 'co_client_invoices', 'income_account_id')
        && co_db_column_exists($conn, 'co_client_invoices', 'subtotal');
}

function co_client_invoice_full_schema(PDO $conn): void {
    // Skip CREATE when tables already exist — MySQL DDL (including IF NOT EXISTS)
    // implicitly commits any open transaction and breaks outer begin/commit flows.
    if (!co_db_table_exists($conn, 'co_client_invoice_lines')) {
        $conn->exec("
            CREATE TABLE co_client_invoice_lines (
                id INT(11) NOT NULL AUTO_INCREMENT,
                company_id INT(11) NOT NULL,
                invoice_id INT(11) NOT NULL,
                line_number INT(11) NOT NULL DEFAULT 1,
                description VARCHAR(500) DEFAULT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                income_account_id INT(11) DEFAULT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                vat_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_co_client_lines_invoice (company_id, invoice_id),
                KEY idx_co_client_lines_account (income_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
    foreach ([
        'quantity' => "DECIMAL(12,2) NOT NULL DEFAULT 1.00 AFTER description",
        'unit_price' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER quantity",
        'income_account_id' => "INT(11) DEFAULT NULL AFTER unit_price",
        'vat_pct' => "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER amount",
        'vat_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER vat_pct",
        'line_total' => "DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER vat_amount",
    ] as $column => $definition) {
        if (!co_db_column_exists($conn, 'co_client_invoice_lines', $column)) {
            $conn->exec("ALTER TABLE co_client_invoice_lines ADD COLUMN `$column` $definition");
        }
    }
    if (!co_db_table_exists($conn, 'co_client_invoice_documents')) {
        $conn->exec("
            CREATE TABLE co_client_invoice_documents (
                id INT(11) NOT NULL AUTO_INCREMENT,
                company_id INT(11) NOT NULL,
                client_invoice_id INT(11) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT 'Invoice Attachment',
                file_path VARCHAR(500) NOT NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                uploaded_by INT(11) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_co_client_inv_doc_company (company_id),
                KEY idx_co_client_inv_doc_invoice (client_invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

function co_client_payment_columns_ready(PDO $conn): bool {
    return co_db_column_exists($conn, 'co_client_payments', 'pay_account_id')
        && co_db_column_exists($conn, 'co_client_payments', 'client_id');
}

function co_client_allocations_ready(PDO $conn): bool {
    return co_db_table_exists($conn, 'co_client_payment_allocations');
}

function co_income_contract_tables_ready(PDO $conn): bool {
    return co_db_table_exists($conn, 'co_shop_rental_contracts')
        && co_db_table_exists($conn, 'co_camp_management_contracts')
        && co_db_table_exists($conn, 'co_maintenance_contracts');
}

function co_invoice_status_badge(string $status): string {
    $map = [
        'draft' => 'secondary',
        'sent' => 'primary',
        'partial' => 'warning text-dark',
        'paid' => 'success',
        'cancelled' => 'danger',
    ];
    return $map[$status] ?? 'secondary';
}

function co_source_default_income_code(string $sourceType): string {
    return [
        'shop_rental' => CO_ACCOUNT_SHOP_RENTAL_INCOME,
        'shop_commission' => CO_ACCOUNT_SHOP_COMMISSION_INCOME,
        'shop_termination_penalty' => CO_ACCOUNT_SHOP_TERMINATION_PENALTY,
        'shop_charge' => CO_ACCOUNT_SHOP_RENTAL_INCOME, // Key Money sets income_account_id to 4170 at invoice create
        'camp_management' => CO_ACCOUNT_CAMP_MANAGEMENT_INCOME,
        'maintenance_service' => CO_ACCOUNT_MAINTENANCE_INCOME,
        'construction_project' => CO_ACCOUNT_CONSTRUCTION_INCOME,
        'manual' => CO_ACCOUNT_CONSTRUCTION_INCOME,
    ][$sourceType] ?? CO_ACCOUNT_CONSTRUCTION_INCOME;
}

function co_fetch_income_accounts(PDO $conn, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT id, account_code, account_name
        FROM re_chart_of_accounts
        WHERE company_id = ?
          AND is_active = 1
          AND is_header = 0
          AND account_type = 'Income'
        ORDER BY account_code
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function co_default_income_account_id(PDO $conn, int $companyId, string $sourceType): int {
    $account = find_account_by_code(co_source_default_income_code($sourceType), $companyId);
    return $account ? (int)$account['id'] : 0;
}

function co_next_document_number(PDO $conn, int $companyId, string $prefix, string $table, string $column): string {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9-]/i', '', $prefix));
    $like = $prefix . '-' . date('Ym') . '-%';
    $stmt = $conn->prepare("SELECT COUNT(*) FROM {$table} WHERE company_id = ? AND {$column} LIKE ?");
    $stmt->execute([$companyId, $like]);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . '-' . date('Ym') . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function co_income_add_months(string $date, int $months): string {
    $dt = new DateTime($date);
    $dt->modify('+' . $months . ' month');
    return $dt->format('Y-m-d');
}

function co_frequency_months(string $frequency): int {
    return [
        'monthly' => 1,
        'quarterly' => 3,
        'semi_annual' => 6,
        'annual' => 12,
        'one_time' => 0,
    ][$frequency] ?? 1;
}

function co_generate_period_schedules(PDO $conn, string $table, int $companyId, int $contractId, string $startDate, ?string $endDate, string $frequency, float $amount, float $vatRate): int {
    if ($frequency === 'one_time') {
        $periodEnd = $endDate ?: $startDate;
        $vat = round($amount * $vatRate / 100, 2);
        $stmt = $conn->prepare("
            INSERT IGNORE INTO {$table}
                (company_id, contract_id, period_start, period_end, due_date, amount, vat_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$companyId, $contractId, $startDate, $periodEnd, $startDate, $amount, $vat]);
        return $stmt->rowCount();
    }

    $months = co_frequency_months($frequency);
    $cursor = new DateTime($startDate);
    $end = new DateTime($endDate ?: $startDate);
    $created = 0;
    $stmt = $conn->prepare("
        INSERT IGNORE INTO {$table}
            (company_id, contract_id, period_start, period_end, due_date, amount, vat_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    while ($cursor <= $end) {
        $periodStart = $cursor->format('Y-m-d');
        $periodEndDt = clone $cursor;
        $periodEndDt->modify('+' . $months . ' month -1 day');
        if ($periodEndDt > $end) {
            $periodEndDt = clone $end;
        }
        $periodEnd = $periodEndDt->format('Y-m-d');
        $vat = round($amount * $vatRate / 100, 2);
        $stmt->execute([$companyId, $contractId, $periodStart, $periodEnd, $periodStart, $amount, $vat]);
        $created += $stmt->rowCount();
        $cursor->modify('+' . $months . ' month');
    }

    return $created;
}

function co_months_inclusive(string $startDate, string $endDate): int {
    $start = new DateTime(date('Y-m-01', strtotime($startDate)));
    $end = new DateTime(date('Y-m-01', strtotime($endDate)));
    return max(1, ((int)$start->diff($end)->y * 12) + (int)$start->diff($end)->m + 1);
}

function co_create_shop_cheque_plan(PDO $conn, int $companyId, int $contractId, int $rentChequeCount, int $depositChequeCount): int {
    require_once __DIR__ . '/construction_shop_rental_helpers.php';
    return co_shop_create_cheque_plan_impl($conn, $companyId, $contractId, $rentChequeCount, $depositChequeCount);
}

function co_generate_shop_schedules_from_cheques(PDO $conn, int $companyId, int $contractId): int {
    require_once __DIR__ . '/construction_shop_rental_helpers.php';
    return co_shop_generate_schedules_impl($conn, $companyId, $contractId);
}

function co_update_client_invoice_status(PDO $conn, int $companyId, int $invoiceId): void {
    if (!co_client_allocations_ready($conn)) {
        return;
    }
    $stmt = $conn->prepare("
        SELECT i.id, i.total_amount, i.subtotal, i.vat_amount, i.source_type, i.source_id, i.status, i.journal_id,
               COALESCE(SUM(a.allocated_amount), 0) AS paid_amount
        FROM co_client_invoices i
        LEFT JOIN co_client_payment_allocations a
               ON a.invoice_id = i.id
              AND a.company_id = i.company_id
        WHERE i.id = ? AND i.company_id = ?
        GROUP BY i.id, i.total_amount, i.subtotal, i.vat_amount, i.source_type, i.source_id, i.status, i.journal_id
    ");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice || ($invoice['status'] ?? '') === 'cancelled') {
        return;
    }
    if (!function_exists('co_shop_invoice_collectible_amount')) {
        require_once __DIR__ . '/construction_shop_rental_helpers.php';
    }
    $total = function_exists('co_shop_invoice_collectible_amount')
        ? co_shop_invoice_collectible_amount($conn, $companyId, $invoice)
        : (float)$invoice['total_amount'];
    $paid = (float)$invoice['paid_amount'];
    $status = 'sent';
    if ($paid <= 0.005) {
        $status = !empty($invoice['journal_id']) ? 'sent' : (($invoice['status'] ?? 'draft') === 'draft' ? 'draft' : 'sent');
    } elseif ($paid + 0.005 >= $total) {
        $status = 'paid';
    } else {
        $status = 'partial';
    }
    $conn->prepare("UPDATE co_client_invoices SET status = ? WHERE id = ? AND company_id = ?")
        ->execute([$status, $invoiceId, $companyId]);
}

function co_allocate_client_payment(PDO $conn, int $companyId, int $clientId, int $paymentId, int $invoiceId, float $amount): void {
    if (!co_client_allocations_ready($conn) || $amount <= 0) {
        return;
    }
    $stmt = $conn->prepare("
        SELECT i.id, i.total_amount, i.subtotal, i.vat_amount, i.source_type, i.source_id,
               COALESCE(SUM(a.allocated_amount), 0) AS allocated_amount
        FROM co_client_invoices i
        LEFT JOIN co_client_payment_allocations a
               ON a.invoice_id = i.id
              AND a.company_id = i.company_id
        WHERE i.company_id = ?
          AND i.client_id = ?
          AND i.id = ?
          AND i.status <> 'cancelled'
        GROUP BY i.id, i.total_amount, i.subtotal, i.vat_amount, i.source_type, i.source_id
    ");
    $stmt->execute([$companyId, $clientId, $invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return;
    }
    if (!function_exists('co_shop_invoice_collectible_amount')) {
        require_once __DIR__ . '/construction_shop_rental_helpers.php';
    }
    $collectible = function_exists('co_shop_invoice_collectible_amount')
        ? co_shop_invoice_collectible_amount($conn, $companyId, $invoice)
        : (float)$invoice['total_amount'];
    $open = max(0, round($collectible - (float)$invoice['allocated_amount'], 2));
    $allocate = min(round($amount, 2), $open);
    if ($allocate <= 0) {
        return;
    }
    $insert = $conn->prepare("
        INSERT INTO co_client_payment_allocations
            (company_id, payment_id, invoice_id, allocated_amount)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE allocated_amount = VALUES(allocated_amount)
    ");
    $insert->execute([$companyId, $paymentId, $invoiceId, $allocate]);
    co_update_client_invoice_status($conn, $companyId, $invoiceId);
}

/**
 * Compute subtotal, VAT, and total for income invoices.
 *
 * @return array{subtotal:float,vat_amount:float,total:float}
 */
function co_compute_invoice_vat_amounts(float $enteredAmount, float $vatRate, string $vatMode): array
{
    $vatMode = ($vatMode === 'inclusive') ? 'inclusive' : 'exclusive';
    $enteredAmount = round($enteredAmount, 2);

    if ($enteredAmount <= 0) {
        return ['subtotal' => 0.0, 'vat_amount' => 0.0, 'total' => 0.0];
    }

    if ($vatMode === 'inclusive') {
        if ($vatRate <= 0) {
            return ['subtotal' => $enteredAmount, 'vat_amount' => 0.0, 'total' => $enteredAmount];
        }
        $vatAmount = round($enteredAmount * $vatRate / (100 + $vatRate), 2);
        $subtotal = round($enteredAmount - $vatAmount, 2);

        return ['subtotal' => $subtotal, 'vat_amount' => $vatAmount, 'total' => $enteredAmount];
    }

    $subtotal = $enteredAmount;
    $vatAmount = round($subtotal * ($vatRate / 100), 2);

    return [
        'subtotal' => $subtotal,
        'vat_amount' => $vatAmount,
        'total' => round($subtotal + $vatAmount, 2),
    ];
}

function co_parse_client_invoice_items(array $items, int $defaultIncomeAccountId, float $defaultVatPct, string $vatMode): array {
    $lines = [];
    $subtotal = 0.0;
    $vat = 0.0;
    $total = 0.0;
    foreach ($items as $item) {
        $desc = trim((string)($item['description'] ?? ''));
        $qty = (float)($item['quantity'] ?? 1);
        $entered = (float)($item['unit_price'] ?? 0);
        $vatPct = isset($item['vat_pct']) && $item['vat_pct'] !== '' ? (float)$item['vat_pct'] : $defaultVatPct;
        $accountId = (int)($item['income_account_id'] ?? 0) ?: $defaultIncomeAccountId;
        if ($qty <= 0 || $entered < 0 || $accountId <= 0) continue;
        $amounts = co_compute_invoice_vat_amounts(round($qty * $entered, 2), $vatPct, $vatMode);
        if ($amounts['total'] <= 0) continue;
        $lines[] = [
            'description' => $desc !== '' ? $desc : 'Income invoice line',
            'quantity' => $qty,
            'unit_price' => $entered,
            'income_account_id' => $accountId,
            'amount' => $amounts['subtotal'],
            'vat_pct' => $vatPct,
            'vat_amount' => $amounts['vat_amount'],
            'line_total' => $amounts['total'],
        ];
        $subtotal += $amounts['subtotal'];
        $vat += $amounts['vat_amount'];
        $total += $amounts['total'];
    }
    return ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'vat_amount' => round($vat, 2), 'total' => round($total, 2)];
}

function co_insert_client_invoice_lines(PDO $conn, int $companyId, int $invoiceId, array $lines): void {
    co_client_invoice_full_schema($conn);
    $stmt = $conn->prepare("
        INSERT INTO co_client_invoice_lines
            (company_id, invoice_id, line_number, description, quantity, unit_price, income_account_id, amount, vat_pct, vat_amount, line_total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $lineNo = 1;
    foreach ($lines as $line) {
        $stmt->execute([
            $companyId,
            $invoiceId,
            $lineNo++,
            $line['description'] ?: null,
            $line['quantity'] ?? 1,
            $line['unit_price'] ?? $line['amount'],
            $line['income_account_id'] ?? null,
            $line['amount'],
            $line['vat_pct'] ?? 0,
            $line['vat_amount'] ?? 0,
            $line['line_total'] ?? (($line['amount'] ?? 0) + ($line['vat_amount'] ?? 0)),
        ]);
    }
}

function co_client_invoice_lines(PDO $conn, int $companyId, int $invoiceId): array {
    if (!co_db_table_exists($conn, 'co_client_invoice_lines')) return [];
    $stmt = $conn->prepare("SELECT * FROM co_client_invoice_lines WHERE company_id = ? AND invoice_id = ? ORDER BY line_number, id");
    $stmt->execute([$companyId, $invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function co_create_income_invoice(PDO $conn, int $companyId, int $clientId, int $projectId, string $sourceType, int $sourceId, string $invoiceNumber, string $invoiceDate, ?string $dueDate, float $subtotal, float $vatAmount, string $description, int $incomeAccountId, int $userId, ?array $lines = null, ?int $contractChargeId = null): int {
    co_client_invoice_full_schema($conn);
    $total = round($subtotal + $vatAmount, 2);
    $hasChargeCol = co_db_column_exists($conn, 'co_client_invoices', 'contract_charge_id');
    if ($hasChargeCol) {
        $stmt = $conn->prepare("
            INSERT INTO co_client_invoices
                (company_id, project_id, client_id, source_type, source_id, contract_charge_id, invoice_number, invoice_date, due_date, subtotal, total_amount, vat_amount, description, income_account_id, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?)
        ");
        $stmt->execute([
            $companyId,
            $projectId ?: null,
            $clientId,
            $sourceType,
            $sourceId ?: null,
            $contractChargeId ?: null,
            $invoiceNumber,
            $invoiceDate,
            $dueDate ?: $invoiceDate,
            $subtotal,
            $total,
            $vatAmount,
            $description ?: null,
            $incomeAccountId ?: null,
            $userId ?: null,
        ]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO co_client_invoices
                (company_id, project_id, client_id, source_type, source_id, invoice_number, invoice_date, due_date, subtotal, total_amount, vat_amount, description, income_account_id, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?)
        ");
        $stmt->execute([
            $companyId,
            $projectId ?: null,
            $clientId,
            $sourceType,
            $sourceId ?: null,
            $invoiceNumber,
            $invoiceDate,
            $dueDate ?: $invoiceDate,
            $subtotal,
            $total,
            $vatAmount,
            $description ?: null,
            $incomeAccountId ?: null,
            $userId ?: null,
        ]);
    }
    $invoiceId = (int)$conn->lastInsertId();
    if ($lines === null) {
        $lines = [[
            'description' => $description ?: co_income_source_label($sourceType),
            'quantity' => 1,
            'unit_price' => $subtotal,
            'income_account_id' => $incomeAccountId ?: null,
            'amount' => $subtotal,
            'vat_pct' => $subtotal > 0 ? round($vatAmount / $subtotal * 100, 2) : 0,
            'vat_amount' => $vatAmount,
            'line_total' => $total,
        ]];
    }
    co_insert_client_invoice_lines($conn, $companyId, $invoiceId, $lines);
    return $invoiceId;
}
