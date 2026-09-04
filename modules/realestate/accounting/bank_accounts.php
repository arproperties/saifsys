<?php
/**
 * Real Estate Accounting - Bank Accounts Management
 * Add, edit and manage bank accounts for payment processing
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../includes/re_bank_reco_core.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$asOfDate = date('Y-m-d');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $accountName = trim($_POST['account_name'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $iban = trim($_POST['iban'] ?? '');
        $swiftCode = trim($_POST['swift_code'] ?? '');
        $currency = $_POST['currency'] ?? 'AED';
        $glAccountId = (int)($_POST['gl_account_id'] ?? 0);
        $openingBalance = (float)($_POST['opening_balance'] ?? 0);
        
        if ($accountName && $glAccountId) {
            $stmt = $conn->prepare("
                INSERT INTO re_bank_accounts
                (company_id, account_name, bank_name, account_number, iban, swift_code, 
                 currency, gl_account_id, opening_balance, current_balance, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $currentCompanyId, $accountName, $bankName, $accountNumber, $iban, 
                $swiftCode, $currency, $glAccountId, $openingBalance, $openingBalance
            ]);
            $_SESSION['success'] = 'Bank account created successfully.';
        } else {
            $_SESSION['error'] = 'Please fill in required fields (Account Name and GL Account).';
        }
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $accountName = trim($_POST['account_name'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $iban = trim($_POST['iban'] ?? '');
        $swiftCode = trim($_POST['swift_code'] ?? '');
        $currency = $_POST['currency'] ?? 'AED';
        $glAccountId = (int)($_POST['gl_account_id'] ?? 0);
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        
        if ($accountName && $glAccountId && $id) {
            $stmt = $conn->prepare("
                UPDATE re_bank_accounts
                SET account_name = ?, bank_name = ?, account_number = ?, iban = ?, 
                    swift_code = ?, currency = ?, gl_account_id = ?, is_active = ?
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $accountName, $bankName, $accountNumber, $iban, $swiftCode, 
                $currency, $glAccountId, $isActive, $id, $currentCompanyId
            ]);
            $_SESSION['success'] = 'Bank account updated successfully.';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Check if bank account has transactions
        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_general_ledger WHERE account_id IN (SELECT gl_account_id FROM re_bank_accounts WHERE id = ?)");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = 'Cannot delete bank account with existing transactions. Deactivate it instead.';
        } else {
            $stmt = $conn->prepare("DELETE FROM re_bank_accounts WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $currentCompanyId]);
            $_SESSION['success'] = 'Bank account deleted successfully.';
        }
    }
    
    header('Location: bank_accounts.php');
    exit;
}

// Get bank accounts
$bankAccounts = $conn->prepare("
    SELECT ba.*, coa.account_code, coa.account_name as gl_account_name
    FROM re_bank_accounts ba
    JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
    WHERE ba.company_id = ?
    ORDER BY coa.account_code, ba.account_name
");
$bankAccounts->execute([$currentCompanyId]);
$bankAccounts = $bankAccounts->fetchAll(PDO::FETCH_ASSOC);

// Display GL book balance (same source as Bank Reconciliation). Stored current_balance is only set at create.
foreach ($bankAccounts as &$account) {
    $glId = (int) ($account['gl_account_id'] ?? 0);
    $account['display_balance'] = $glId > 0
        ? re_bank_erp_balance($conn, $glId, (int) $currentCompanyId, $asOfDate)
        : (float) ($account['current_balance'] ?? 0);
}
unset($account);

// Get available GL accounts (bank/cash type accounts - 1100-1299)
$glAccounts = $conn->prepare("
    SELECT id, account_code, account_name, account_type
    FROM re_chart_of_accounts
    WHERE company_id = ? 
    AND is_active = 1 
    AND (account_code LIKE '11%' OR account_code LIKE '12%')
    ORDER BY account_code
");
$glAccounts->execute([$currentCompanyId]);
$glAccounts = $glAccounts->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Bank Accounts';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-bank2"></i> Bank Accounts</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
        <i class="bi bi-plus-circle"></i> Add Bank Account
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

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Bank Accounts (<?= count($bankAccounts) ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($bankAccounts)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No bank accounts configured yet. 
                Click "Add Bank Account" to create your first bank account.
            </div>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> <strong>Important:</strong> 
                You need to add bank accounts before you can record payments via bank transfer, cheque, or cash deposit.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Account Name</th>
                            <th>Bank</th>
                            <th>Account Number</th>
                            <th>GL Account</th>
                            <th>Currency</th>
                            <th>Current Balance <span class="text-muted fw-normal small">(ERP / GL as of <?= h($asOfDate) ?>)</span></th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bankAccounts as $account): ?>
                            <tr>
                                <td><strong><?= h($account['account_name']) ?></strong></td>
                                <td><?= h($account['bank_name'] ?: '-') ?></td>
                                <td><?= h($account['account_number'] ?: '-') ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= h($account['account_code']) ?></span>
                                    <?= h($account['gl_account_name']) ?>
                                </td>
                                <td><?= h($account['currency']) ?></td>
                                <td class="text-end <?= $account['display_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= number_format((float) $account['display_balance'], 2) ?> <?= h($account['currency']) ?>
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
                                            onclick="editAccount(<?= htmlspecialchars(json_encode($account)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline" 
                                          onsubmit="return confirm('Are you sure you want to delete this bank account?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $account['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Bank Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bank2"></i> Add Bank Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Name *</label>
                            <input type="text" name="account_name" class="form-control" required
                                   placeholder="e.g., ADCB Business Account">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control"
                                   placeholder="e.g., Abu Dhabi Commercial Bank">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control"
                                   placeholder="Bank account number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN</label>
                            <input type="text" name="iban" class="form-control"
                                   placeholder="e.g., AE123456789012345678901">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">SWIFT Code</label>
                            <input type="text" name="swift_code" class="form-control"
                                   placeholder="e.g., ADCBAEAA">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Currency</label>
                            <select name="currency" class="form-select">
                                <option value="AED" selected>AED - UAE Dirham</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Opening Balance (AED)</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control" value="0">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Link to GL Account *</label>
                        <select name="gl_account_id" class="form-select" required>
                            <option value="">-- Select GL Account --</option>
                            <?php foreach ($glAccounts as $gl): ?>
                                <option value="<?= $gl['id'] ?>">
                                    <?= h($gl['account_code']) ?> - <?= h($gl['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select the Chart of Accounts entry for this bank account</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Create Bank Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Bank Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editAccountForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Bank Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Name *</label>
                            <input type="text" name="account_name" id="edit_account_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" id="edit_bank_name" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" id="edit_account_number" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN</label>
                            <input type="text" name="iban" id="edit_iban" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">SWIFT Code</label>
                            <input type="text" name="swift_code" id="edit_swift_code" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Currency</label>
                            <select name="currency" id="edit_currency" class="form-select">
                                <option value="AED">AED - UAE Dirham</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - British Pound</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label" for="edit_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Link to GL Account *</label>
                        <select name="gl_account_id" id="edit_gl_account_id" class="form-select" required>
                            <option value="">-- Select GL Account --</option>
                            <?php foreach ($glAccounts as $gl): ?>
                                <option value="<?= $gl['id'] ?>">
                                    <?= h($gl['account_code']) ?> - <?= h($gl['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Update Bank Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editAccount(account) {
    document.getElementById('edit_id').value = account.id;
    document.getElementById('edit_account_name').value = account.account_name;
    document.getElementById('edit_bank_name').value = account.bank_name || '';
    document.getElementById('edit_account_number').value = account.account_number || '';
    document.getElementById('edit_iban').value = account.iban || '';
    document.getElementById('edit_swift_code').value = account.swift_code || '';
    document.getElementById('edit_currency').value = account.currency || 'AED';
    document.getElementById('edit_gl_account_id').value = account.gl_account_id;
    document.getElementById('edit_is_active').checked = account.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editAccountModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
