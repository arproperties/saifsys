<?php
/**
 * Schema helper — Service Management Phase 7 (expenses workflow, prepaid, JV).
 */
require_once __DIR__ . '/../includes/db_connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/plain; charset=utf-8');
}

function sm7_table_exists(PDO $conn, string $table): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function sm7_col_exists(PDO $conn, string $table, string $col): bool
{
    $st = $conn->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

echo "=== SM Phase 7 schema (expenses, prepaid, JV) ===\n";

$sqlFile = __DIR__ . '/../migrations/sm_phase7_expenses_jv.sql';
if (is_file($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || stripos($stmt, 'CREATE TABLE') === false) {
            continue;
        }
        try {
            $conn->exec($stmt);
            if (preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $stmt, $m)) {
                echo "OK: table {$m[1]}\n";
            }
        } catch (Throwable $e) {
            echo "WARN: {$e->getMessage()}\n";
        }
    }
}

if (sm7_table_exists($conn, 'expenses')) {
    if (!sm7_col_exists($conn, 'expenses', 'expense_type')) {
        $conn->exec("
            ALTER TABLE expenses
            ADD COLUMN expense_type ENUM('operating','prepaid','payroll','other') NOT NULL DEFAULT 'operating' AFTER status
        ");
        echo "OK: expenses.expense_type\n";
    } else {
        echo "SKIP: expenses.expense_type\n";
    }

    if (!sm7_col_exists($conn, 'expenses', 'submitted_at')) {
        $conn->exec("ALTER TABLE expenses ADD COLUMN submitted_at DATETIME NULL AFTER created_by");
        echo "OK: expenses.submitted_at\n";
    }
    if (!sm7_col_exists($conn, 'expenses', 'approved_by')) {
        $conn->exec("ALTER TABLE expenses ADD COLUMN approved_by INT UNSIGNED NULL AFTER submitted_at");
        echo "OK: expenses.approved_by\n";
    }
    if (!sm7_col_exists($conn, 'expenses', 'approved_at')) {
        $conn->exec("ALTER TABLE expenses ADD COLUMN approved_at DATETIME NULL AFTER approved_by");
        echo "OK: expenses.approved_at\n";
    }
    if (!sm7_col_exists($conn, 'expenses', 'prepaid_months')) {
        $conn->exec("ALTER TABLE expenses ADD COLUMN prepaid_months INT NULL AFTER expense_type");
        echo "OK: expenses.prepaid_months\n";
    }
    if (!sm7_col_exists($conn, 'expenses', 'prepaid_expense_account_no')) {
        $conn->exec("ALTER TABLE expenses ADD COLUMN prepaid_expense_account_no VARCHAR(20) NULL AFTER prepaid_months");
        echo "OK: expenses.prepaid_expense_account_no\n";
    }

    try {
        $conn->exec("
            ALTER TABLE expenses
            MODIFY COLUMN status ENUM('draft','pending_approval','posted','void') NOT NULL DEFAULT 'posted'
        ");
        echo "OK: expenses.status enum extended\n";
    } catch (Throwable $e) {
        echo "WARN status enum: {$e->getMessage()}\n";
    }
}

$settings = [
    'sm_expense_requires_approval' => '0',
    'sm_prepaid_asset_account' => '1310',
    'sm_phase7_enabled' => '1',
];
foreach ($settings as $key => $val) {
    $st = $conn->prepare("SELECT COUNT(*) FROM settings WHERE `key` = ?");
    $st->execute([$key]);
    if ((int)$st->fetchColumn() === 0) {
        $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)")->execute([$key, $val]);
        echo "OK: setting {$key}\n";
    }
}

echo "=== Phase 7 complete ===\n";
