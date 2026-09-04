<?php
/**
 * Real Estate Module - Penalty Rules Management
 * Configure penalty rules for late payments, bounced cheques, etc.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_rule') {
        $ruleName = $_POST['rule_name'] ?? '';
        $ruleDescription = $_POST['rule_description'] ?? '';
        $penaltyType = $_POST['penalty_type'] ?? 'late_payment';
        $calculationMethod = $_POST['calculation_method'] ?? 'fixed';
        $amount = $_POST['amount'] ?? 0;
        $percentage = $_POST['percentage'] ?? 0;
        $perDayAmount = $_POST['per_day_amount'] ?? 0;
        $gracePeriodDays = $_POST['grace_period_days'] ?? 0;
        // Convert empty string to NULL for optional max_penalty_amount
        $maxPenaltyAmount = !empty($_POST['max_penalty_amount']) ? (float)$_POST['max_penalty_amount'] : null;
        
        if ($ruleName) {
            $stmt = $conn->prepare("
                INSERT INTO re_penalty_rules
                (company_id, rule_name, rule_description, penalty_type, calculation_method, 
                 amount, percentage, per_day_amount, grace_period_days, max_penalty_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $ruleName, $ruleDescription, $penaltyType, $calculationMethod,
                $amount, $percentage, $perDayAmount, $gracePeriodDays, $maxPenaltyAmount
            ]);
            $_SESSION['success'] = 'Penalty rule created successfully.';
        }
    } elseif ($action === 'toggle_active') {
        $ruleId = (int)$_POST['rule_id'];
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $conn->prepare("UPDATE re_penalty_rules SET is_active = ? WHERE id = ? AND company_id = ?")
            ->execute([$isActive, $ruleId, $currentCompanyId]);
        $_SESSION['success'] = 'Penalty rule updated.';
    } elseif ($action === 'update_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $ruleName = trim($_POST['rule_name'] ?? '');
        $ruleDescription = trim($_POST['rule_description'] ?? '');
        $penaltyType = $_POST['penalty_type'] ?? 'late_payment';
        $calculationMethod = $_POST['calculation_method'] ?? 'fixed';
        $amount = (float)($_POST['amount'] ?? 0);
        $percentage = (float)($_POST['percentage'] ?? 0);
        $perDayAmount = (float)($_POST['per_day_amount'] ?? 0);
        $gracePeriodDays = max(0, (int)($_POST['grace_period_days'] ?? 0));
        $maxPenaltyAmount = !empty($_POST['max_penalty_amount']) ? (float)$_POST['max_penalty_amount'] : null;
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($ruleId > 0 && $ruleName !== '') {
            $stmt = $conn->prepare("
                UPDATE re_penalty_rules
                SET rule_name = ?,
                    rule_description = ?,
                    penalty_type = ?,
                    calculation_method = ?,
                    amount = ?,
                    percentage = ?,
                    per_day_amount = ?,
                    grace_period_days = ?,
                    max_penalty_amount = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $ruleName,
                $ruleDescription,
                $penaltyType,
                $calculationMethod,
                $amount,
                $percentage,
                $perDayAmount,
                $gracePeriodDays,
                $maxPenaltyAmount,
                $isActive,
                $ruleId,
                $currentCompanyId,
            ]);
            $_SESSION['success'] = 'Penalty rule updated successfully.';
        }
    }
    
    header('Location: billing_penalties.php');
    exit;
}

// Get penalty rules
$penaltyRules = $conn->prepare("
    SELECT * FROM re_penalty_rules
    WHERE company_id = ?
    ORDER BY penalty_type, rule_name
");
$penaltyRules->execute([$currentCompanyId]);
$penaltyRules = $penaltyRules->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Penalty Rules';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-exclamation-triangle"></i> Penalty Rules Management</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRuleModal">
                <i class="bi bi-plus-circle"></i> Create Penalty Rule
            </button>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-list"></i> Penalty Rules</h5>
            </div>
            <div class="card-body">
                <?php if (empty($penaltyRules)): ?>
                    <p class="text-muted">No penalty rules configured. Create one to get started.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Rule Name</th>
                                    <th>Penalty Type</th>
                                    <th>Calculation Method</th>
                                    <th>Amount/Percentage</th>
                                    <th>Grace Period</th>
                                    <th>Max Penalty</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($penaltyRules as $rule): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($rule['rule_name']) ?></strong>
                                            <?php if ($rule['rule_description']): ?>
                                                <br><small class="text-muted"><?= h($rule['rule_description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $rule['penalty_type'] === 'late_payment' ? 'warning' : 
                                                ($rule['penalty_type'] === 'bounced_cheque' ? 'danger' : 'secondary') 
                                            ?>">
                                                <?= ucfirst(str_replace('_', ' ', $rule['penalty_type'])) ?>
                                            </span>
                                        </td>
                                        <td><?= ucfirst(str_replace('_', ' ', $rule['calculation_method'])) ?></td>
                                        <td>
                                            <?php if ($rule['calculation_method'] === 'fixed'): ?>
                                                <?= number_format($rule['amount'], 2) ?> AED
                                            <?php elseif ($rule['calculation_method'] === 'percentage'): ?>
                                                <?= number_format($rule['percentage'], 2) ?>%
                                            <?php elseif ($rule['calculation_method'] === 'per_day'): ?>
                                                <?= number_format($rule['per_day_amount'], 2) ?> AED/day
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $rule['grace_period_days'] ?> days</td>
                                        <td>
                                            <?= $rule['max_penalty_amount'] ? number_format($rule['max_penalty_amount'], 2) . ' AED' : 'No limit' ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                                <input type="hidden" name="is_active" value="<?= $rule['is_active'] ? 0 : 1 ?>">
                                                <button type="submit" class="btn btn-sm btn-<?= $rule['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $rule['is_active'] ? 'Active' : 'Inactive' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <?php
                                                $rulePayload = [
                                                    'id' => (int)$rule['id'],
                                                    'rule_name' => $rule['rule_name'],
                                                    'rule_description' => $rule['rule_description'],
                                                    'penalty_type' => $rule['penalty_type'],
                                                    'calculation_method' => $rule['calculation_method'],
                                                    'amount' => (float)$rule['amount'],
                                                    'percentage' => (float)$rule['percentage'],
                                                    'per_day_amount' => (float)$rule['per_day_amount'],
                                                    'grace_period_days' => (int)$rule['grace_period_days'],
                                                    'max_penalty_amount' => $rule['max_penalty_amount'],
                                                    'is_active' => (int)$rule['is_active'],
                                                ];
                                            ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-rule="<?= h(json_encode($rulePayload, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG)) ?>"
                                                    onclick="editRule(this)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Rule Modal -->
    <div class="modal fade" id="createRuleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Penalty Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_rule">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Rule Name *</label>
                            <input type="text" name="rule_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="rule_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Penalty Type *</label>
                                <select name="penalty_type" class="form-select" required>
                                    <option value="late_payment">Late Payment</option>
                                    <option value="bounced_cheque">Bounced Cheque</option>
                                    <option value="violation">Violation</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Calculation Method *</label>
                                <select name="calculation_method" id="calculation_method" class="form-select" required>
                                    <option value="fixed">Fixed Amount</option>
                                    <option value="percentage">Percentage</option>
                                    <option value="per_day">Per Day</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3" id="fixedAmountDiv">
                                <label class="form-label">Fixed Amount (AED)</label>
                                <input type="number" step="0.01" name="amount" class="form-control" value="0">
                            </div>
                            <div class="col-md-4 mb-3" id="percentageDiv" style="display: none;">
                                <label class="form-label">Percentage (%)</label>
                                <input type="number" step="0.01" name="percentage" class="form-control" value="0">
                            </div>
                            <div class="col-md-4 mb-3" id="perDayDiv" style="display: none;">
                                <label class="form-label">Per Day Amount (AED)</label>
                                <input type="number" step="0.01" name="per_day_amount" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grace Period (Days)</label>
                                <input type="number" name="grace_period_days" class="form-control" value="0" min="0">
                                <small class="form-text text-muted">Days before penalty applies</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Max Penalty Amount (AED)</label>
                                <input type="number" step="0.01" name="max_penalty_amount" class="form-control" placeholder="No limit">
                                <small class="form-text text-muted">Maximum penalty cap (optional)</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Rule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Rule Modal -->
    <div class="modal fade" id="editRuleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Penalty Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_rule">
                    <input type="hidden" name="rule_id" id="edit_rule_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Rule Name *</label>
                            <input type="text" name="rule_name" id="edit_rule_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="rule_description" id="edit_rule_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Penalty Type *</label>
                                <select name="penalty_type" id="edit_penalty_type" class="form-select" required>
                                    <option value="late_payment">Late Payment</option>
                                    <option value="bounced_cheque">Bounced Cheque</option>
                                    <option value="violation">Violation</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Calculation Method *</label>
                                <select name="calculation_method" id="edit_calculation_method" class="form-select" required>
                                    <option value="fixed">Fixed Amount</option>
                                    <option value="percentage">Percentage</option>
                                    <option value="per_day">Per Day</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3" id="editFixedAmountDiv">
                                <label class="form-label">Fixed Amount (AED)</label>
                                <input type="number" step="0.01" name="amount" id="edit_amount" class="form-control" value="0">
                            </div>
                            <div class="col-md-4 mb-3" id="editPercentageDiv" style="display: none;">
                                <label class="form-label">Percentage (%)</label>
                                <input type="number" step="0.01" name="percentage" id="edit_percentage" class="form-control" value="0">
                            </div>
                            <div class="col-md-4 mb-3" id="editPerDayDiv" style="display: none;">
                                <label class="form-label">Per Day Amount (AED)</label>
                                <input type="number" step="0.01" name="per_day_amount" id="edit_per_day_amount" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grace Period (Days)</label>
                                <input type="number" name="grace_period_days" id="edit_grace_period_days" class="form-control" value="0" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Max Penalty Amount (AED)</label>
                                <input type="number" step="0.01" name="max_penalty_amount" id="edit_max_penalty_amount" class="form-control" placeholder="No limit">
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="edit_is_active">
                            <label class="form-check-label" for="edit_is_active">Rule is active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php
$pageScripts = <<<HTML
<script>
function toggleCalculationFields(prefix, method) {
    document.getElementById(prefix + 'FixedAmountDiv').style.display = method === 'fixed' ? 'block' : 'none';
    document.getElementById(prefix + 'PercentageDiv').style.display = method === 'percentage' ? 'block' : 'none';
    document.getElementById(prefix + 'PerDayDiv').style.display = method === 'per_day' ? 'block' : 'none';
}

document.getElementById('calculation_method')?.addEventListener('change', function() {
    toggleCalculationFields('', this.value);
});

document.getElementById('edit_calculation_method')?.addEventListener('change', function() {
    toggleCalculationFields('edit', this.value);
});

function editRule(button) {
    var rule = JSON.parse(button.getAttribute('data-rule'));
    document.getElementById('edit_rule_id').value = rule.id || '';
    document.getElementById('edit_rule_name').value = rule.rule_name || '';
    document.getElementById('edit_rule_description').value = rule.rule_description || '';
    document.getElementById('edit_penalty_type').value = rule.penalty_type || 'late_payment';
    document.getElementById('edit_calculation_method').value = rule.calculation_method || 'fixed';
    document.getElementById('edit_amount').value = rule.amount || 0;
    document.getElementById('edit_percentage').value = rule.percentage || 0;
    document.getElementById('edit_per_day_amount').value = rule.per_day_amount || 0;
    document.getElementById('edit_grace_period_days').value = rule.grace_period_days || 0;
    document.getElementById('edit_max_penalty_amount').value = rule.max_penalty_amount || '';
    document.getElementById('edit_is_active').checked = String(rule.is_active) === '1';
    toggleCalculationFields('edit', document.getElementById('edit_calculation_method').value);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editRuleModal')).show();
}
</script>
HTML;
?>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

