<?php
/**
 * Controlled reset for Bank Reconciliation workspace data.
 *
 * Keeps bank accounts, chart of accounts, journals, and GL entries intact.
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
$toolEnabled = defined('ALLOW_BANK_RECON_RESET_TOOL') && ALLOW_BANK_RECON_RESET_TOOL === true;
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
$action = $_POST['action'] ?? '';
$message = '';
$error = '';
$preview = [];
$executionResults = [];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function br_reset_table_exists(PDO $conn, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function br_reset_count(PDO $conn, string $sql, array $params): int {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

function br_reset_audit_table(PDO $conn): void {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS re_bank_reconciliation_reset_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT(11) NOT NULL,
            user_id INT(11) DEFAULT NULL,
            database_name VARCHAR(128) NOT NULL,
            bank_account_ids_json LONGTEXT NOT NULL,
            result_json LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(80) DEFAULT NULL,
            session_id VARCHAR(128) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_re_bank_reset_audit_company (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function br_reset_steps(int $companyId, array $bankIds, array $glAccountIds): array {
    if (!$bankIds) return [];
    $bankPh = implode(',', array_fill(0, count($bankIds), '?'));
    $bankParams = array_merge([$companyId], $bankIds);
    $steps = [
        ['Bank Reconciliation', 're_bank_reconciliation_matches', 'Delete confirmed/void match records', "SELECT COUNT(*) FROM re_bank_reconciliation_matches WHERE company_id = ? AND bank_account_id IN ($bankPh)", "DELETE FROM re_bank_reconciliation_matches WHERE company_id = ? AND bank_account_id IN ($bankPh)", $bankParams, 'DELETE'],
        ['Bank Reconciliation', 're_bank_statement_lines', 'Delete imported statement lines', "SELECT COUNT(*) FROM re_bank_statement_lines WHERE company_id = ? AND bank_account_id IN ($bankPh)", "DELETE FROM re_bank_statement_lines WHERE company_id = ? AND bank_account_id IN ($bankPh)", $bankParams, 'DELETE'],
        ['Bank Reconciliation', 're_bank_import_batches', 'Delete import batches', "SELECT COUNT(*) FROM re_bank_import_batches WHERE company_id = ? AND bank_account_id IN ($bankPh)", "DELETE FROM re_bank_import_batches WHERE company_id = ? AND bank_account_id IN ($bankPh)", $bankParams, 'DELETE'],
        ['Bank Reconciliation', 're_bank_reconciliation_audit', 'Delete bank reconciliation audit rows', "SELECT COUNT(*) FROM re_bank_reconciliation_audit WHERE company_id = ? AND bank_account_id IN ($bankPh)", "DELETE FROM re_bank_reconciliation_audit WHERE company_id = ? AND bank_account_id IN ($bankPh)", $bankParams, 'DELETE'],
        ['Bank Reconciliation', 're_bank_reconciliation_locks', 'Delete reconciliation period locks', "SELECT COUNT(*) FROM re_bank_reconciliation_locks WHERE company_id = ? AND bank_account_id IN ($bankPh)", "DELETE FROM re_bank_reconciliation_locks WHERE company_id = ? AND bank_account_id IN ($bankPh)", $bankParams, 'DELETE'],
    ];
    if ($glAccountIds) {
        $glPh = implode(',', array_fill(0, count($glAccountIds), '?'));
        $glParams = array_merge([$companyId], $glAccountIds);
        $steps[] = ['General Ledger', 're_general_ledger', 'Clear old reconciliation flags on bank GL lines', "SELECT COUNT(*) FROM re_general_ledger WHERE company_id = ? AND account_id IN ($glPh) AND is_reconciled = 1", "UPDATE re_general_ledger SET is_reconciled = 0, reconciled_at = NULL, reconciled_by = NULL WHERE company_id = ? AND account_id IN ($glPh)", $glParams, 'UPDATE'];
    }
    return $steps;
}

function br_reset_preview(PDO $conn, array $steps): array {
    $out = [];
    foreach ($steps as [$category, $table, $label, $countSql, $execSql, $params, $mode]) {
        $exists = br_reset_table_exists($conn, $table);
        $count = $exists ? br_reset_count($conn, $countSql, $params) : 0;
        $status = !$exists ? 'missing' : ($count < 0 ? 'error' : 'ready');
        $out[] = compact('category', 'table', 'label', 'countSql', 'execSql', 'params', 'mode', 'count', 'status');
    }
    return $out;
}

function br_reset_execute(PDO $conn, array $preview): array {
    $results = [];
    $conn->beginTransaction();
    try {
        foreach ($preview as $step) {
            if ($step['status'] !== 'ready' || (int)$step['count'] <= 0) {
                $results[] = $step + ['affected' => 0, 'result' => 'skipped'];
                continue;
            }
            $stmt = $conn->prepare($step['execSql']);
            $stmt->execute($step['params']);
            $results[] = $step + ['affected' => $stmt->rowCount(), 'result' => 'done'];
        }
        $conn->commit();
        return $results;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

$bankStmt = $conn->prepare("
    SELECT ba.id, ba.account_name, ba.bank_name, ba.gl_account_id, coa.account_code, coa.account_name AS gl_name
    FROM re_bank_accounts ba
    JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
    WHERE ba.company_id = ? AND ba.is_active = 1
    ORDER BY coa.account_code, ba.account_name
");
$bankStmt->execute([$companyId]);
$bankAccounts = $bankStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedBankIds = array_values(array_unique(array_map('intval', $_POST['bank_account_ids'] ?? [])));
$selectAllBanks = !empty($_POST['select_all_banks']);
if ($selectAllBanks && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedBankIds = array_map(static fn($row) => (int)$row['id'], $bankAccounts);
}
$selectedBankRows = array_values(array_filter($bankAccounts, static fn($row) => in_array((int)$row['id'], $selectedBankIds, true)));
$selectedGlIds = array_values(array_unique(array_map(static fn($row) => (int)$row['gl_account_id'], $selectedBankRows)));

if (!$isOwnerAdmin) {
    $error = 'Forbidden. Owner/Admin role is required.';
} elseif (!$toolEnabled) {
    $error = 'Tool is disabled. Define ALLOW_BANK_RECON_RESET_TOOL as true in config to enable it.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $preview = br_reset_preview($conn, br_reset_steps($companyId, $selectedBankIds, $selectedGlIds));
    if (!$selectedBankIds) {
        $error = 'Select at least one bank account.';
    } elseif ($action === 'execute') {
        $confirmed = !empty($_POST['backup_confirmed'])
            && !empty($_POST['duplicate_confirmed'])
            && !empty($_POST['dry_run_reviewed'])
            && trim((string)($_POST['confirm_phrase'] ?? '')) === 'RESET BANK RECONCILIATION';
        if (!$confirmed) {
            $error = 'Execution blocked. Confirm backup, duplicated/test database, dry-run review, and exact phrase.';
        } else {
            try {
                br_reset_audit_table($conn);
                $executionResults = br_reset_execute($conn, $preview);
                $conn->prepare("
                    INSERT INTO re_bank_reconciliation_reset_audit
                        (company_id, user_id, database_name, bank_account_ids_json, result_json, ip_address, session_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $companyId,
                    $userId,
                    $dbName,
                    json_encode($selectedBankIds, JSON_UNESCAPED_SLASHES),
                    json_encode($executionResults, JSON_UNESCAPED_SLASHES),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    session_id(),
                ]);
                $message = 'Bank reconciliation workspace reset completed.';
            } catch (Throwable $e) {
                $error = 'Reset failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Bank Reconciliation Reset';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-bank"></i> Bank Reconciliation Reset</div>
    <a href="../accounting/bank_reconciliation.php" class="btn btn-outline-secondary">Workbench</a>
</div>

<div class="alert alert-danger">
    <strong>This clears only the reconciliation workspace.</strong>
    It deletes imported bank statement lines, matches, locks, and audit rows, then clears old reconciliation flags on bank GL lines. It does not delete bank accounts, journals, receipts, vendor payments, or GL entries.
</div>
<?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <div><strong>Current database:</strong> <code><?= h($dbName) ?></code></div>
        <div><strong>Company ID:</strong> <?= (int)$companyId ?></div>
        <div><strong>Tool enabled:</strong> <span class="badge bg-<?= $toolEnabled ? 'success' : 'danger' ?>"><?= $toolEnabled ? 'yes' : 'no' ?></span></div>
        <div class="small text-muted mt-2">To execute, enable <code>ALLOW_BANK_RECON_RESET_TOOL</code>, run dry-run, confirm backup, and type the phrase.</div>
    </div>
</div>

<form method="post" class="card card-round mb-4">
    <div class="card-header bg-white d-flex justify-content-between">
        <strong>Reset Scope</strong>
        <span class="badge bg-secondary"><?= count($bankAccounts) ?> active bank accounts</span>
    </div>
    <div class="card-body">
        <?php csrf_field(); ?>
        <label class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="select_all_banks" value="1" <?= $selectAllBanks ? 'checked' : '' ?>>
            <span class="form-check-label fw-semibold">Select all active bank accounts</span>
        </label>
        <div class="row g-2">
            <?php foreach ($bankAccounts as $bank): ?>
                <div class="col-md-6">
                    <label class="form-check border rounded p-2">
                        <input type="checkbox" class="form-check-input ms-0 me-2" name="bank_account_ids[]" value="<?= (int)$bank['id'] ?>" <?= in_array((int)$bank['id'], $selectedBankIds, true) ? 'checked' : '' ?>>
                        <span class="form-check-label">
                            <strong><?= h($bank['account_code'] . ' - ' . $bank['gl_name']) ?></strong><br>
                            <small class="text-muted"><?= h($bank['account_name'] . ($bank['bank_name'] ? ' (' . $bank['bank_name'] . ')' : '')) ?></small>
                        </span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <hr>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-check"><input type="checkbox" class="form-check-input" name="backup_confirmed" value="1" <?= !empty($_POST['backup_confirmed']) ? 'checked' : '' ?>> <span class="form-check-label">External backup/export is done.</span></label></div>
            <div class="col-md-4"><label class="form-check"><input type="checkbox" class="form-check-input" name="duplicate_confirmed" value="1" <?= !empty($_POST['duplicate_confirmed']) ? 'checked' : '' ?>> <span class="form-check-label">This is duplicated/test DB or approved reset window.</span></label></div>
            <div class="col-md-4"><label class="form-check"><input type="checkbox" class="form-check-input" name="dry_run_reviewed" value="1" <?= !empty($_POST['dry_run_reviewed']) ? 'checked' : '' ?>> <span class="form-check-label">I reviewed the dry-run table below.</span></label></div>
            <div class="col-md-6"><label class="form-label">Type confirmation phrase</label><input type="text" name="confirm_phrase" class="form-control" value="<?= h($_POST['confirm_phrase'] ?? '') ?>" placeholder="RESET BANK RECONCILIATION"></div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button name="action" value="dry_run" class="btn btn-primary" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?>>Dry Run</button>
            <button name="action" value="execute" class="btn btn-danger" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?> onclick="return confirm('Execute bank reconciliation reset for selected bank accounts?');">Execute Reset</button>
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
                    <td><?= h($row['label']) ?></td>
                    <td><?= h($row['mode']) ?></td>
                    <td class="text-end"><?= (int)$row['count'] ?></td>
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
                <tr><td><code><?= h($row['table']) ?></code></td><td class="text-end"><?= (int)$row['count'] ?></td><td class="text-end"><?= (int)$row['affected'] ?></td><td><?= h($row['result']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-header bg-white"><strong>What stays after reset</strong></div>
    <div class="card-body">
        <ul class="mb-0">
            <li>Posted receipts, vendor payments, journals, and GL entries stay unchanged.</li>
            <li>The workbench can still show unreconciled GL book lines. That is correct accounting evidence waiting for statement import/matching.</li>
            <li>For a fresh go-live view, use the workbench date filters after the reset, or reset lease financial records separately where appropriate.</li>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
