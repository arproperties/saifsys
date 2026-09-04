<?php
/**
 * Real Estate Accounting - Chart of Accounts Management
 * View, add, edit and manage all GL accounts
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../includes/coa_ui.php';

require_login();
$coaLayout = coa_resolve_layout();
$hasReFinancial = has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn);
$hasCoFinancial = has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);
$hasArsFinancial = has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn)
    || has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn);
if (!$hasReFinancial && !$hasCoFinancial && !$hasArsFinancial) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $accountCode = trim($_POST['account_code'] ?? '');
        $accountName = trim($_POST['account_name'] ?? '');
        $accountType = $_POST['account_type'] ?? 'Asset';
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $normalBalance = $_POST['normal_balance'] ?? 'debit';
        $isHeader = !empty($_POST['is_header']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        
        if ($accountCode && $accountName) {
            // Check for duplicate account code
            $checkStmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = ?");
            $checkStmt->execute([$currentCompanyId, $accountCode]);
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = 'Account code already exists.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO re_chart_of_accounts
                    (company_id, account_code, account_name, account_type, parent_id, 
                     normal_balance, is_header, description, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $currentCompanyId, $accountCode, $accountName, $accountType, 
                    $parentId, $normalBalance, $isHeader, $description
                ]);
                $_SESSION['success'] = 'Account created successfully.';
            }
        } else {
            $_SESSION['error'] = 'Please fill in required fields (Account Code and Account Name).';
        }
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $accountCode = trim($_POST['account_code'] ?? '');
        $accountName = trim($_POST['account_name'] ?? '');
        $accountType = $_POST['account_type'] ?? 'Asset';
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $normalBalance = $_POST['normal_balance'] ?? 'debit';
        $isHeader = !empty($_POST['is_header']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        
        if ($accountCode && $accountName && $id) {
            // Check for duplicate account code (excluding current record)
            $checkStmt = $conn->prepare("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_code = ? AND id != ?");
            $checkStmt->execute([$currentCompanyId, $accountCode, $id]);
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = 'Account code already exists.';
            } else {
                $stmt = $conn->prepare("
                    UPDATE re_chart_of_accounts
                    SET account_code = ?, account_name = ?, account_type = ?, parent_id = ?,
                        normal_balance = ?, is_header = ?, description = ?, is_active = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $accountCode, $accountName, $accountType, $parentId, 
                    $normalBalance, $isHeader, $description, $isActive, $id, $currentCompanyId
                ]);
                $_SESSION['success'] = 'Account updated successfully.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        // Check if account has transactions
        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_general_ledger WHERE account_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'Cannot delete account with existing transactions. Deactivate it instead.';
        } else {
            // Check if account has child accounts
            $stmt = $conn->prepare("SELECT COUNT(*) FROM re_chart_of_accounts WHERE parent_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error'] = 'Cannot delete account with child accounts. Delete child accounts first.';
            } else {
                // Check if linked to bank accounts
                $stmt = $conn->prepare("SELECT COUNT(*) FROM re_bank_accounts WHERE gl_account_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    $_SESSION['error'] = 'Cannot delete account linked to bank accounts.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM re_chart_of_accounts WHERE id = ? AND company_id = ?");
                    $stmt->execute([$id, $currentCompanyId]);
                    $_SESSION['success'] = 'Account deleted successfully.';
                }
            }
        }
    }
    
    header('Location: ' . basename($_SERVER['PHP_SELF'] ?? 'chart_of_accounts.php'));
    exit;
}

// Filter by account type
$filterType = $_GET['type'] ?? '';

// Get all accounts
$query = "
    SELECT coa.*, 
           parent.account_code as parent_code, 
           parent.account_name as parent_name,
           (SELECT COUNT(*) FROM re_general_ledger gl WHERE gl.account_id = coa.id) as transaction_count
    FROM re_chart_of_accounts coa
    LEFT JOIN re_chart_of_accounts parent ON parent.id = coa.parent_id
    WHERE coa.company_id = ?
";
$params = [$currentCompanyId];

if ($filterType) {
    $query .= " AND coa.account_type = ?";
    $params[] = $filterType;
}

$query .= " ORDER BY coa.account_code";

$accounts = $conn->prepare($query);
$accounts->execute($params);
$accounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

// Get parent accounts (for dropdown)
$parentAccounts = $conn->prepare("
    SELECT id, account_code, account_name, account_type
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_header = 1
    ORDER BY account_code
");
$parentAccounts->execute([$currentCompanyId]);
$parentAccounts = $parentAccounts->fetchAll(PDO::FETCH_ASSOC);

// Count by type
$typeCounts = [];
foreach ($accounts as $acc) {
    $type = $acc['account_type'];
    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Chart of Accounts';
$accountingBase = ($coaLayout === 'construction') ? 'reports/' : (($coaLayout === 'realestate') ? '' : '../realestate/accounting/');
coa_require_header($coaLayout);
?>

<style>
.account-row-header { background-color: #f8f9fa; font-weight: 600; }
.account-code { font-family: 'Consolas', 'Monaco', monospace; font-weight: 600; }
.type-badge { font-size: 0.75rem; }
.filter-btn.active { font-weight: bold; box-shadow: 0 0 0 2px rgba(var(--bs-primary-rgb), 0.5); }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-list-columns-reverse"></i> Chart of Accounts</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
        <i class="bi bi-plus-circle"></i> Add Account
    </button>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= h($_SESSION['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= h($_SESSION['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Filter Buttons -->
<div class="mb-3">
    <div class="btn-group" role="group">
        <a href="chart_of_accounts.php" class="btn btn-outline-secondary filter-btn <?= empty($filterType) ? 'active' : '' ?>">
            All (<?= count($accounts) ?>)
        </a>
        <a href="?type=Asset" class="btn btn-outline-primary filter-btn <?= $filterType === 'Asset' ? 'active' : '' ?>">
            <i class="bi bi-box-seam"></i> Assets (<?= $typeCounts['Asset'] ?? 0 ?>)
        </a>
        <a href="?type=Liability" class="btn btn-outline-danger filter-btn <?= $filterType === 'Liability' ? 'active' : '' ?>">
            <i class="bi bi-credit-card"></i> Liabilities (<?= $typeCounts['Liability'] ?? 0 ?>)
        </a>
        <a href="?type=Equity" class="btn btn-outline-success filter-btn <?= $filterType === 'Equity' ? 'active' : '' ?>">
            <i class="bi bi-bank2"></i> Equity (<?= $typeCounts['Equity'] ?? 0 ?>)
        </a>
        <a href="?type=Income" class="btn btn-outline-info filter-btn <?= $filterType === 'Income' ? 'active' : '' ?>">
            <i class="bi bi-arrow-down-circle"></i> Income (<?= $typeCounts['Income'] ?? 0 ?>)
        </a>
        <a href="?type=Expense" class="btn btn-outline-warning filter-btn <?= $filterType === 'Expense' ? 'active' : '' ?>">
            <i class="bi bi-arrow-up-circle"></i> Expenses (<?= $typeCounts['Expense'] ?? 0 ?>)
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <?= $filterType ? h($filterType) . ' Accounts' : 'All Accounts' ?>
        </h5>
        <div>
            <input type="text" id="searchInput" class="form-control form-control-sm" 
                   placeholder="Search accounts..." style="width: 250px;">
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
            <div class="alert alert-info m-3">
                <i class="bi bi-info-circle"></i> No accounts found. Click "Add Account" to create your first account.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="accountsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th>Parent Account</th>
                            <th>Normal Balance</th>
                            <th class="text-center">Transactions</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr class="<?= $account['is_header'] ? 'account-row-header' : '' ?>">
                                <td class="account-code"><?= h($account['account_code']) ?></td>
                                <td>
                                    <?php if ($account['is_header']): ?>
                                        <i class="bi bi-folder text-warning"></i>
                                    <?php endif; ?>
                                    <?= h($account['account_name']) ?>
                                    <?php if ($account['description']): ?>
                                        <i class="bi bi-info-circle text-muted" 
                                           title="<?= h($account['description']) ?>" 
                                           data-bs-toggle="tooltip"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $typeColors = [
                                        'Asset' => 'primary',
                                        'Liability' => 'danger',
                                        'Equity' => 'success',
                                        'Income' => 'info',
                                        'Expense' => 'warning'
                                    ];
                                    $color = $typeColors[$account['account_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?> type-badge"><?= h($account['account_type']) ?></span>
                                </td>
                                <td>
                                    <?php if ($account['parent_code']): ?>
                                        <span class="text-muted"><?= h($account['parent_code']) ?></span> - 
                                        <?= h($account['parent_name']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $account['normal_balance'] === 'debit' ? 'secondary' : 'dark' ?>">
                                        <?= ucfirst($account['normal_balance']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($account['transaction_count'] > 0): ?>
                                        <a href="<?= h($accountingBase) ?>general_ledger.php?account_id=<?= (int)$account['id'] ?>" class="badge bg-primary">
                                            <?= number_format($account['transaction_count']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($account['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" 
                                            onclick="editAccount(<?= htmlspecialchars(json_encode($account)) ?>)"
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($account['transaction_count'] == 0): ?>
                                    <form method="POST" class="d-inline" 
                                          onsubmit="return confirm('Are you sure you want to delete this account?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Account Code *</label>
                            <input type="text" name="account_code" class="form-control" required
                                   placeholder="e.g., 1240">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Account Name *</label>
                            <input type="text" name="account_name" class="form-control" required
                                   placeholder="e.g., FAB Bank Account">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Type *</label>
                            <select name="account_type" class="form-select" required onchange="updateNormalBalance(this)">
                                <option value="Asset">Asset</option>
                                <option value="Liability">Liability</option>
                                <option value="Equity">Equity</option>
                                <option value="Income">Income</option>
                                <option value="Expense">Expense</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Normal Balance *</label>
                            <select name="normal_balance" class="form-select" required>
                                <option value="debit">Debit</option>
                                <option value="credit">Credit</option>
                            </select>
                            <small class="text-muted">Usually: Assets/Expenses=Debit, Liabilities/Equity/Income=Credit</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parent Account (Optional)</label>
                        <select name="parent_id" class="form-select">
                            <option value="">-- No Parent (Top Level) --</option>
                            <?php foreach ($parentAccounts as $parent): ?>
                                <option value="<?= $parent['id'] ?>">
                                    <?= h($parent['account_code']) ?> - <?= h($parent['account_name']) ?>
                                    (<?= h($parent['account_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Optional description for this account"></textarea>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_header" id="add_is_header" value="1">
                        <label class="form-check-label" for="add_is_header">
                            <i class="bi bi-folder"></i> Header Account (parent only, no transactions)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editAccountForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Account Code *</label>
                            <input type="text" name="account_code" id="edit_account_code" class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Account Name *</label>
                            <input type="text" name="account_name" id="edit_account_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Type *</label>
                            <select name="account_type" id="edit_account_type" class="form-select" required>
                                <option value="Asset">Asset</option>
                                <option value="Liability">Liability</option>
                                <option value="Equity">Equity</option>
                                <option value="Income">Income</option>
                                <option value="Expense">Expense</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Normal Balance *</label>
                            <select name="normal_balance" id="edit_normal_balance" class="form-select" required>
                                <option value="debit">Debit</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parent Account (Optional)</label>
                        <select name="parent_id" id="edit_parent_id" class="form-select">
                            <option value="">-- No Parent (Top Level) --</option>
                            <?php foreach ($parentAccounts as $parent): ?>
                                <option value="<?= $parent['id'] ?>">
                                    <?= h($parent['account_code']) ?> - <?= h($parent['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_header" id="edit_is_header" value="1">
                                <label class="form-check-label" for="edit_is_header">
                                    <i class="bi bi-folder"></i> Header Account
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label" for="edit_is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Update Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Search functionality
document.getElementById('searchInput').addEventListener('input', function() {
    const search = this.value.toLowerCase();
    const rows = document.querySelectorAll('#accountsTable tbody tr');
    
    rows.forEach(row => {
        const code = row.cells[0].textContent.toLowerCase();
        const name = row.cells[1].textContent.toLowerCase();
        const type = row.cells[2].textContent.toLowerCase();
        
        if (code.includes(search) || name.includes(search) || type.includes(search)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

// Auto-update normal balance based on account type
function updateNormalBalance(select) {
    const type = select.value;
    const normalBalanceSelect = select.closest('form').querySelector('select[name="normal_balance"]');
    
    if (['Asset', 'Expense'].includes(type)) {
        normalBalanceSelect.value = 'debit';
    } else {
        normalBalanceSelect.value = 'credit';
    }
}

// Edit account
function editAccount(account) {
    document.getElementById('edit_id').value = account.id;
    document.getElementById('edit_account_code').value = account.account_code;
    document.getElementById('edit_account_name').value = account.account_name;
    document.getElementById('edit_account_type').value = account.account_type;
    document.getElementById('edit_normal_balance').value = account.normal_balance;
    document.getElementById('edit_parent_id').value = account.parent_id || '';
    document.getElementById('edit_description').value = account.description || '';
    document.getElementById('edit_is_header').checked = account.is_header == 1;
    document.getElementById('edit_is_active').checked = account.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editAccountModal')).show();
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php coa_require_footer($coaLayout); ?>
