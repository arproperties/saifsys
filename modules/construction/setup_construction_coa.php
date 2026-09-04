<?php
/**
 * Construction Module — Setup Chart of Accounts (one-click for current company)
 * Adds 1515, 5125, 2145, 2125 to re_chart_of_accounts if base COA exists.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

$cid = (int)(current_company_id($conn) ?: 0);
if ($cid <= 0) {
    http_response_code(400);
    echo 'Company context is required.';
    exit;
}

// Check existing construction accounts and parents
$accounts = [
    CO_ACCOUNT_AR => ['name' => 'Accounts Receivable - Customers/Tenants', 'type' => 'Asset', 'parent' => 'asset', 'normal' => 'debit', 'description' => 'Construction customer and tenant receivables'],
    CO_ACCOUNT_CIP => ['name' => 'Construction in Progress', 'type' => 'Asset', 'parent' => 'asset', 'normal' => 'debit', 'description' => 'Construction work in progress (OWNER projects)'],
    CO_ACCOUNT_CONSTRUCTION_INCOME => ['name' => 'Construction / Manual Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'Construction client and manual income'],
    CO_ACCOUNT_CAMP_MANAGEMENT_INCOME => ['name' => 'Camp Rental Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'Gross camp rental income collected through management agents'],
    CO_ACCOUNT_SHOP_RENTAL_INCOME => ['name' => 'Shop Rental Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'Ground-floor shop rental income'],
    CO_ACCOUNT_MAINTENANCE_INCOME => ['name' => 'Maintenance Service Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'Maintenance service income'],
    CO_ACCOUNT_SHOP_COMMISSION_INCOME => ['name' => 'Shop Tenant Commission Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'Commission / letting fee charged to shop tenants (Madar Al Wadi)'],
    CO_ACCOUNT_SHOP_TERMINATION_PENALTY => ['name' => 'Early Termination Penalty Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'Early termination / break-clause penalty charged to shop tenants'],
    CO_ACCOUNT_DEPOSIT_RECOVERY_INCOME => ['name' => 'Deposit Recovery Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'Damage, utility, cleaning and forfeiture recoveries from security deposits'],
    CO_ACCOUNT_SHOP_KEY_MONEY => ['name' => 'Key Money Income', 'type' => 'Income', 'parent' => 'income', 'normal' => 'credit', 'description' => 'One-time Key Money charged to shop tenants'],
    CO_ACCOUNT_PROJECT_COGS => ['name' => 'Project Cost / Construction COGS', 'type' => 'Expense', 'parent' => 'expense', 'normal' => 'debit', 'description' => 'Project costs (CLIENT projects)'],
    CO_ACCOUNT_AGENT_COMMISSION_EXPENSE => ['name' => 'Agent Commission / Deductions Expense', 'type' => 'Expense', 'parent' => 'expense', 'normal' => 'debit', 'description' => 'Camp management agent commissions and settlement deductions'],
    CO_ACCOUNT_CONTRACTOR_PAYABLE => ['name' => 'Contractor Payable', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'Amounts owed to contractors'],
    CO_ACCOUNT_RETENTION_PAYABLE => ['name' => 'Retention Payable', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'Contractor retention held'],
    CO_ACCOUNT_SUPPLIER_PAYABLE => ['name' => 'Supplier Payable', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'Amounts owed to suppliers / trade payables'],
    CO_ACCOUNT_INPUT_VAT => ['name' => 'Input VAT / VAT Recoverable', 'type' => 'Asset', 'parent' => 'asset', 'normal' => 'debit', 'description' => 'VAT on purchases recoverable from FTA'],
    CO_ACCOUNT_OUTPUT_VAT => ['name' => 'Output VAT', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'VAT on sales / collected from customers'],
    CO_ACCOUNT_PREPAID_OUTPUT_VAT => ['name' => 'VAT Collected in Advance', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'Separate VAT cash held until monthly tax invoices recognize Output VAT'],
    CO_ACCOUNT_SECURITY_DEPOSITS => ['name' => 'Tenant Security Deposits', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'Refundable shop tenant security deposits'],
    CO_ACCOUNT_DEFERRED_RENT_REVENUE => ['name' => 'Deferred Rent Revenue', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'Rent billed/collected before it is earned under accrual recognition'],
    CO_ACCOUNT_CLIENT_ADVANCES => ['name' => 'Client Advances / Tenant Credit', 'type' => 'Liability', 'parent' => 'liability', 'normal' => 'credit', 'description' => 'Unallocated client overpayments and tenant credit balances'],
];

// Prefer prepaid parent 1500 for supplier advances when present.
$advanceDefaultCode = CO_SUPPLIER_ADVANCE_DEFAULT_CODE;
$accounts[$advanceDefaultCode] = [
    'name' => 'Supplier Advances',
    'type' => 'Asset',
    'parent' => 'asset',
    'normal' => 'debit',
    'description' => 'Supplier advances and prepayments held as current asset until applied to supplier invoices',
];
$status = [];
foreach (array_keys($accounts) as $code) {
    $stmt = $conn->prepare("SELECT id, account_name FROM re_chart_of_accounts WHERE company_id = ? AND account_code = ? AND is_active = 1");
    $stmt->execute([$cid, $code]);
    $status[$code] = $stmt->fetch(PDO::FETCH_ASSOC) ? true : false;
}

$parent_asset = null;
$parent_liability = null;
$parent_expense = null;
$parent_prepaid = null;
$stmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '1000' LIMIT 1");
$stmt->execute([$cid]);
$parent_asset = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '1500' LIMIT 1");
$stmt->execute([$cid]);
$parent_prepaid = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code IN ('2100','2000') ORDER BY account_code LIMIT 1");
$stmt->execute([$cid]);
$parent_liability = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '5000' LIMIT 1");
$stmt->execute([$cid]);
$parent_expense = $stmt->fetchColumn();
$stmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = '4000' LIMIT 1");
$stmt->execute([$cid]);
$parent_income = $stmt->fetchColumn();

$baseOk = $parent_asset && $parent_liability && $parent_expense && $parent_income;

$msg = '';
$err = '';
$advanceCodeSetting = co_supplier_advance_account_code($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postAction = (string)($_POST['action'] ?? 'create_accounts');
    if ($postAction === 'save_advance_setting') {
        $newCode = trim((string)($_POST['advance_account_code'] ?? ''));
        if ($newCode === '' || !preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $newCode)) {
            $err = 'Enter a valid Supplier Advances account code.';
        } else {
            $chk = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = ? AND is_active = 1 AND is_header = 0 LIMIT 1");
            $chk->execute([$cid, $newCode]);
            if (!$chk->fetchColumn()) {
                $err = 'Account code ' . $newCode . ' was not found (or is a header) for this company. Create it first, then save.';
            } else {
                co_supplier_save_setting($conn, CO_SUPPLIER_ADVANCE_SETTING_KEY, $newCode);
                $advanceCodeSetting = $newCode;
                $msg = 'Supplier Advances account code saved: ' . $newCode;
            }
        }
    } elseif ($baseOk) {
    $created = 0;
    foreach ($accounts as $code => $account) {
        if (!empty($status[$code])) {
            continue;
        }
        $parentId = [
            'asset' => ($code === $advanceDefaultCode && $parent_prepaid) ? $parent_prepaid : $parent_asset,
            'liability' => $parent_liability,
            'expense' => $parent_expense,
            'income' => $parent_income,
        ][$account['parent']] ?? null;
        if (!$parentId) {
            continue;
        }
        $stmt = $conn->prepare("
            INSERT INTO re_chart_of_accounts
                (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
            VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?)
        ");
        $stmt->execute([$cid, $code, $account['name'], $account['type'], $parentId, $account['normal'], $account['description']]);
        $created++;
    }
    if ($created > 0) {
        $msg = "Construction accounts created successfully. You can now post project costs, supplier invoices, and income workflows to accounting.";
        foreach (array_keys($accounts) as $code) {
            $stmt = $conn->prepare("SELECT 1 FROM re_chart_of_accounts WHERE company_id = ? AND account_code = ? AND is_active = 1");
            $stmt->execute([$cid, $code]);
            $status[$code] = (bool)$stmt->fetch();
        }
        if (empty(co_supplier_setting($conn, CO_SUPPLIER_ADVANCE_SETTING_KEY, ''))) {
            co_supplier_save_setting($conn, CO_SUPPLIER_ADVANCE_SETTING_KEY, $advanceDefaultCode);
            $advanceCodeSetting = $advanceDefaultCode;
        }
    } else {
        $msg = "All construction accounts already exist. No changes made.";
    }
    }
}

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$pageTitle = 'Setup Construction Accounts';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="index.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
    <h1 class="h4 mb-0">Setup Construction Chart of Accounts</h1>
    <p class="text-muted mb-0">Add construction-specific expense, payable, receivable, income, VAT, deposit, and supplier advance accounts for the current company.</p>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<?php if (!$baseOk): ?>
<div class="alert alert-warning">
    <strong>Base chart of accounts is missing.</strong> The construction accounts need parent accounts (1000 Current Assets, 2100/2000 Liabilities, 4000 Income, 5000 Operating Expenses). Run the base COA for this company first:
    <ol class="mb-0 mt-2">
        <li>Open <code>migrations/seed_real_estate_chart_of_accounts.sql</code>, set <code>@company_id = <?= (int)$cid ?></code>, then run it in phpMyAdmin or MySQL.</li>
        <li>Return here and click &quot;Create missing construction accounts&quot;.</li>
    </ol>
    <p class="mb-0 mt-2 small">See <code>migrations/README_CONSTRUCTION_COA.md</code> for details.</p>
</div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-header bg-white"><h6 class="mb-0">Supplier Advances account (configurable)</h6></div>
    <div class="card-body">
        <p class="small text-muted mb-3">Payment remainders and advance applications use this asset account. Default <strong>1410</strong>. Do not hardcode in posting — change here.</p>
        <form method="post" class="row g-3 align-items-end">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_advance_setting">
            <div class="col-md-4">
                <label class="form-label">Account code</label>
                <input type="text" name="advance_account_code" class="form-control" required maxlength="20" value="<?= h($advanceCodeSetting) ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary">Save Advance Account</button>
            </div>
            <div class="col-12 small text-muted">
                Resolved account:
                <?php
                $resolved = co_supplier_advance_account($conn, $cid);
                echo $resolved
                    ? h(($resolved['account_code'] ?? '') . ' — ' . ($resolved['account_name'] ?? ''))
                    : '<span class="text-danger">Not found for this company — create ' . h($advanceCodeSetting) . ' below or pick an existing asset code.</span>';
                ?>
            </div>
        </form>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-header bg-white"><h6 class="mb-0">Construction accounts (company ID <?= (int)$cid ?>)</h6></div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Code</th><th>Account</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $code => $label): ?>
                <tr>
                    <td><?= h($code) ?></td>
                    <td><?= h($label['name'] . ' (' . $label['type'] . ')') ?></td>
                    <td><?= $status[$code] ? '<span class="badge bg-success">Exists</span>' : '<span class="badge bg-secondary">Missing</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($baseOk): ?>
        <form method="post" class="mt-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create_accounts">
            <button type="submit" class="btn btn-primary">Create missing construction accounts</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
