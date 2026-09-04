<?php
/**
 * Real Estate Accounting - Add/Edit Recurring Journal
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$id = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$rec = null;
$success = '';
$error = '';

$accounts = $conn->prepare("SELECT id, account_code, account_name FROM re_chart_of_accounts WHERE company_id = ? AND is_active = 1 AND is_header = 0 ORDER BY account_code");
$accounts->execute([$currentCompanyId]);
$allAccounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

if ($id) {
    $stmt = $conn->prepare("SELECT * FROM re_recurring_journals WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $currentCompanyId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $frequency = $_POST['frequency'] === 'weekly' ? 'weekly' : 'monthly';
    $dayOfMonth = (int)($_POST['day_of_month'] ?? 1);
    if ($dayOfMonth < 1 || $dayOfMonth > 28) $dayOfMonth = 1;
    $nextRun = $_POST['next_run_date'] ?? date('Y-m-d');
    $linesJson = $_POST['lines_json'] ?? '[]';
    $lines = json_decode($linesJson, true);
    if (!$name) {
        $error = 'Name is required.';
    } elseif (!is_array($lines) || count($lines) < 2) {
        $error = 'At least 2 lines are required.';
    } else {
        $valid = [];
        foreach ($lines as $l) {
            if (empty($l['account_id'])) continue;
            $valid[] = [
                'account_id' => (int)$l['account_id'],
                'debit' => (float)($l['debit'] ?? 0),
                'credit' => (float)($l['credit'] ?? 0),
                'description' => trim($l['description'] ?? '')
            ];
        }
        if (count($valid) < 2) {
            $error = 'At least 2 valid lines required.';
        } else {
            $json = json_encode($valid);
            if ($id && $rec) {
                $stmt = $conn->prepare("UPDATE re_recurring_journals SET name=?, description=?, frequency=?, day_of_month=?, next_run_date=?, lines_json=?, is_active=1 WHERE id=? AND company_id=?");
                $stmt->execute([$name, $description, $frequency, $dayOfMonth, $nextRun, $json, $id, $currentCompanyId]);
                $success = 'Recurring journal updated.';
            } else {
                $stmt = $conn->prepare("INSERT INTO re_recurring_journals (company_id, name, description, frequency, day_of_month, next_run_date, lines_json, created_by) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$currentCompanyId, $name, $description, $frequency, $dayOfMonth, $nextRun, $json, current_user_id()]);
                $success = 'Recurring journal created.';
            }
            $rec = null;
            $id = null;
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = $id ? 'Edit Recurring Journal' : 'Add Recurring Journal';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= h($success) ?> <a href="recurring_journals.php">Back to list</a> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= h($error) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><?= $id ? 'Edit' : 'Add' ?> Recurring Journal</div>
    <a href="recurring_journals.php" class="btn btn-outline-secondary">Back</a>
</div>

<div class="card card-round">
    <div class="card-body">
        <form method="POST" id="recurForm">
            <?= csrf_field() ?>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Name *</label>
                    <input type="text" name="name" class="form-control" value="<?= $rec ? h($rec['name']) : '' ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Frequency</label>
                    <select name="frequency" class="form-select">
                        <option value="monthly" <?= ($rec && $rec['frequency'] === 'weekly') ? '' : 'selected' ?>>Monthly</option>
                        <option value="weekly" <?= ($rec && $rec['frequency'] === 'weekly') ? 'selected' : '' ?>>Weekly</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Day (1-28)</label>
                    <input type="number" name="day_of_month" class="form-control" min="1" max="28" value="<?= $rec ? (int)$rec['day_of_month'] : 1 ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Next Run *</label>
                    <input type="date" name="next_run_date" class="form-control" value="<?= $rec ? h($rec['next_run_date']) : date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" value="<?= $rec ? h($rec['description']) : '' ?>">
            </div>
            <h6>Lines</h6>
            <div id="linesContainer"></div>
            <button type="button" class="btn btn-sm btn-outline-primary mb-2" id="addLine">Add Line</button>
            <input type="hidden" name="lines_json" id="linesJson" value="<?= h($rec ? $rec['lines_json'] : '[]') ?>">
            <hr>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>

<script>
const accounts = <?= json_encode($allAccounts) ?>;
let lineIndex = 0;
const initialLines = <?= json_encode($rec ? json_decode($rec['lines_json'], true) : []) ?>;

function addLine(data = {}) {
    const div = document.createElement('div');
    div.className = 'row g-2 mb-2 line-row';
    let opts = accounts.map(a => `<option value="${a.id}" ${data.account_id == a.id ? 'selected' : ''}>${a.account_code} - ${a.account_name}</option>`).join('');
    div.innerHTML = `
        <div class="col-md-4"><select class="form-select form-select-sm account" required><option value="">Account</option>${opts}</select></div>
        <div class="col-md-2"><input type="number" step="0.01" class="form-control form-control-sm debit" placeholder="Debit" value="${data.debit || ''}"></div>
        <div class="col-md-2"><input type="number" step="0.01" class="form-control form-control-sm credit" placeholder="Credit" value="${data.credit || ''}"></div>
        <div class="col-md-3"><input type="text" class="form-control form-control-sm desc" placeholder="Description" value="${data.description || ''}"></div>
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-danger remove-line">×</button></div>
    `;
    document.getElementById('linesContainer').appendChild(div);
    div.querySelector('.remove-line').addEventListener('click', () => { div.remove(); serialize(); });
    lineIndex++;
    serialize();
}

function serialize() {
    const rows = document.querySelectorAll('.line-row');
    const lines = [];
    rows.forEach(r => {
        const accountId = r.querySelector('.account').value;
        const debit = parseFloat(r.querySelector('.debit').value) || 0;
        const credit = parseFloat(r.querySelector('.credit').value) || 0;
        if (accountId && (debit > 0 || credit > 0))
            lines.push({ account_id: accountId, debit, credit, description: r.querySelector('.desc').value });
    });
    document.getElementById('linesJson').value = JSON.stringify(lines);
}

document.getElementById('addLine').addEventListener('click', () => addLine());
document.getElementById('recurForm').addEventListener('submit', function() { serialize(); });
document.getElementById('recurForm').addEventListener('change', serialize);

if (initialLines && initialLines.length) {
    initialLines.forEach(l => addLine(l));
} else {
    addLine();
    addLine();
}
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
