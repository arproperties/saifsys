<?php
/**
 * Construction Accounting — Add/Edit Manual Journal Entry
 * Uses shared re_* accounting engine; presentation is Construction-only.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../realestate/accounting/accounting_engine.php';

require_login();
if (!has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_CONSTRUCTION);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();

$journalId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$journal = null;
$journalLines = [];
$success = '';
$error = '';

if ($journalId) {
    $stmt = $conn->prepare('SELECT * FROM re_journal_headers WHERE id = ? AND company_id = ?');
    $stmt->execute([$journalId, $currentCompanyId]);
    $journal = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($journal) {
        if ($journal['is_posted']) {
            $error = 'Cannot edit posted journal. Create a reversal entry instead.';
            $journal = null;
        } else {
            $stmt = $conn->prepare("
                SELECT jl.*, coa.account_code, coa.account_name
                FROM re_journal_lines jl
                JOIN re_chart_of_accounts coa ON coa.id = jl.account_id
                WHERE jl.journal_id = ?
                ORDER BY jl.line_number
            ");
            $stmt->execute([$journalId]);
            $journalLines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $error = 'Journal entry not found for the current company.';
        $journalId = null;
    }
}

$accounts = $conn->prepare("
    SELECT id, account_code, account_name, account_type, normal_balance
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0
    ORDER BY account_code
");
$accounts->execute([$currentCompanyId]);
$allAccounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $journalDate = $_POST['journal_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $journalTypeForm = $_POST['journal_type'] ?? 'manual';
    $lines = json_decode($_POST['journal_lines'] ?? '[]', true);

    $journalType = in_array($journalTypeForm, ['manual', 'opening_balance'], true) ? $journalTypeForm : 'manual';

    if (empty($lines) || count($lines) < 2) {
        $error = 'Journal must have at least 2 lines';
    } else {
        $journalLinesArray = [];
        foreach ($lines as $line) {
            if (empty($line['account_id']) || (empty($line['debit']) && empty($line['credit']))) {
                continue;
            }
            $journalLinesArray[] = [
                'account_id' => (int)$line['account_id'],
                'debit' => (float)($line['debit'] ?? 0),
                'credit' => (float)($line['credit'] ?? 0),
                'description' => trim($line['description'] ?? ''),
                'reference' => trim($line['reference'] ?? ''),
            ];
        }

        if (count($journalLinesArray) < 2) {
            $error = 'Journal must have at least 2 valid lines';
        } elseif ($journalId && $journal && !$journal['is_posted']) {
            if (is_period_locked($currentCompanyId, $journalDate)) {
                $error = 'Cannot save: the selected date falls in a closed (locked) period.';
            } else {
                $conn->prepare('DELETE FROM re_journal_lines WHERE journal_id = ?')->execute([$journalId]);
                $totalDebit = array_sum(array_column($journalLinesArray, 'debit'));
                $totalCredit = array_sum(array_column($journalLinesArray, 'credit'));
                try {
                    $stmt = $conn->prepare("
                        UPDATE re_journal_headers
                        SET journal_date = ?, description = ?, total_debit = ?, total_credit = ?,
                            modified_by = ?, modified_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$journalDate, $description, $totalDebit, $totalCredit, $userId, $journalId, $currentCompanyId]);
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'modified_by') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                        $stmt = $conn->prepare("
                            UPDATE re_journal_headers
                            SET journal_date = ?, description = ?, total_debit = ?, total_credit = ?
                            WHERE id = ? AND company_id = ?
                        ");
                        $stmt->execute([$journalDate, $description, $totalDebit, $totalCredit, $journalId, $currentCompanyId]);
                    } else {
                        throw $e;
                    }
                }
                $lineNumber = 1;
                foreach ($journalLinesArray as $line) {
                    $stmt = $conn->prepare("
                        INSERT INTO re_journal_lines
                        (company_id, journal_id, account_id, line_number, debit_amount, credit_amount, description, reference)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $currentCompanyId, $journalId, $line['account_id'], $lineNumber++,
                        $line['debit'], $line['credit'], $line['description'], $line['reference'],
                    ]);
                }
                $_SESSION['success'] = 'Journal entry updated successfully';
                header('Location: journal_entry_view.php?id=' . (int)$journalId);
                exit;
            }
        } else {
            $result = create_journal_entry(
                $currentCompanyId,
                $journalType,
                null,
                null,
                $journalLinesArray,
                $description,
                $journalDate,
                $userId
            );

            if ($result['success']) {
                $_SESSION['success'] = "Journal entry created successfully. Journal #: {$result['journal_number']}";
                header('Location: journal_entry_view.php?id=' . (int)$result['journal_id']);
                exit;
            }
            $error = $result['error'];
        }
    }
}

$pageTitle = $journalId ? 'Edit Journal Entry' : 'New Journal Entry';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= h($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-header-label">
        <i class="bi bi-journal-plus"></i> <?= $journalId ? 'Edit' : 'New' ?> Journal Entry
    </div>
    <div>
        <a href="journal_entry_list.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<form method="POST" id="journalForm">
    <?= csrf_field() ?>

    <div class="card card-round mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-info-circle"></i> Journal Information</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Journal Date *</label>
                    <input type="date" name="journal_date" id="journalDate" class="form-control"
                           value="<?= $journal ? h($journal['journal_date']) : date('Y-m-d') ?>" required>
                    <div id="backdateWarning" class="small text-warning mt-1" style="display:none;">
                        <i class="bi bi-exclamation-triangle"></i> Backdating: ensure the period is not closed (Period Management).
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Journal Type</label>
                    <select name="journal_type" class="form-select" <?= $journalId ? 'disabled' : '' ?>>
                        <option value="manual" <?= ($journal && ($journal['journal_type'] ?? '') === 'manual') ? 'selected' : '' ?>>Manual</option>
                        <option value="opening_balance" <?= ($journal && ($journal['journal_type'] ?? '') === 'opening_balance') ? 'selected' : '' ?>>Opening Balance</option>
                    </select>
                    <?php if ($journalId): ?>
                        <input type="hidden" name="journal_type" value="<?= h($journal['journal_type'] ?? 'manual') ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-7 mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control"
                           value="<?= $journal ? h($journal['description']) : '' ?>"
                           placeholder="Journal entry description">
                </div>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-list-ul"></i> Journal Lines</h6>
            <button type="button" class="btn btn-sm btn-primary" onclick="addJournalLine()">
                <i class="bi bi-plus-circle"></i> Add Line
            </button>
        </div>
        <div class="card-body">
            <div id="journalLinesContainer"></div>
            <input type="hidden" name="journal_lines" id="journalLinesJson">

            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="alert alert-info mb-0">
                        <strong>Total Debit:</strong> <span id="totalDebit">0.00</span> AED<br>
                        <strong>Total Credit:</strong> <span id="totalCredit">0.00</span> AED<br>
                        <strong>Difference:</strong> <span id="difference" class="fw-bold">0.00</span> AED
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="journal_entry_list.php" class="btn btn-secondary">
            <i class="bi bi-x-circle"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> <?= $journalId ? 'Update' : 'Create' ?> Journal Entry
        </button>
    </div>
</form>

<script>
const accounts = <?= json_encode($allAccounts) ?>;
let lineCounter = 0;
let journalLines = <?= json_encode($journalLines) ?>;

if (journalLines.length > 0) {
    journalLines.forEach(line => {
        addJournalLine(line.account_id, line.debit_amount, line.credit_amount, line.description, line.reference);
    });
} else {
    addJournalLine();
    addJournalLine();
}

function addJournalLine(accountId = '', debit = '', credit = '', description = '', reference = '') {
    const container = document.getElementById('journalLinesContainer');
    const lineDiv = document.createElement('div');
    lineDiv.className = 'row mb-3 journal-line';
    lineDiv.dataset.lineIndex = lineCounter++;

    lineDiv.innerHTML = `
        <div class="col-md-4">
            <label class="form-label">Account *</label>
            <select class="form-select account-select" required>
                <option value="">-- Select Account --</option>
                ${accounts.map(acc => `
                    <option value="${acc.id}" data-type="${acc.account_type}" data-balance="${acc.normal_balance}"
                            ${accountId == acc.id ? 'selected' : ''}>
                        ${acc.account_code} - ${acc.account_name}
                    </option>
                `).join('')}
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Debit</label>
            <input type="number" step="0.01" min="0" class="form-control debit-input"
                   value="${debit}" placeholder="0.00" onchange="updateTotals()">
        </div>
        <div class="col-md-2">
            <label class="form-label">Credit</label>
            <input type="number" step="0.01" min="0" class="form-control credit-input"
                   value="${credit}" placeholder="0.00" onchange="updateTotals()">
        </div>
        <div class="col-md-3">
            <label class="form-label">Description</label>
            <input type="text" class="form-control description-input"
                   value="${description || ''}" placeholder="Line description">
        </div>
        <div class="col-md-1">
            <label class="form-label">&nbsp;</label>
            <button type="button" class="btn btn-danger w-100" onclick="removeLine(this)">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;

    container.appendChild(lineDiv);
    updateTotals();
}

function removeLine(btn) {
    btn.closest('.journal-line').remove();
    updateTotals();
}

function updateTotals() {
    let totalDebit = 0;
    let totalCredit = 0;

    document.querySelectorAll('.journal-line').forEach(line => {
        totalDebit += parseFloat(line.querySelector('.debit-input').value) || 0;
        totalCredit += parseFloat(line.querySelector('.credit-input').value) || 0;
    });

    document.getElementById('totalDebit').textContent = totalDebit.toFixed(2);
    document.getElementById('totalCredit').textContent = totalCredit.toFixed(2);

    const difference = totalDebit - totalCredit;
    const diffEl = document.getElementById('difference');
    diffEl.textContent = Math.abs(difference).toFixed(2);
    diffEl.className = Math.abs(difference) < 0.01 ? 'fw-bold text-success' : 'fw-bold text-danger';
}

function toggleBackdateWarning() {
    const dateInput = document.getElementById('journalDate');
    const warn = document.getElementById('backdateWarning');
    if (!dateInput || !warn) return;
    const today = new Date().toISOString().slice(0, 10);
    warn.style.display = dateInput.value && dateInput.value < today ? 'block' : 'none';
}
document.getElementById('journalDate').addEventListener('change', toggleBackdateWarning);
toggleBackdateWarning();

document.getElementById('journalForm').addEventListener('submit', function(e) {
    const lines = [];
    document.querySelectorAll('.journal-line').forEach(line => {
        const accountId = line.querySelector('.account-select').value;
        const debit = parseFloat(line.querySelector('.debit-input').value) || 0;
        const credit = parseFloat(line.querySelector('.credit-input').value) || 0;
        const description = line.querySelector('.description-input').value;

        if (accountId && (debit > 0 || credit > 0)) {
            lines.push({
                account_id: accountId,
                debit: debit,
                credit: credit,
                description: description,
                reference: ''
            });
        }
    });

    document.getElementById('journalLinesJson').value = JSON.stringify(lines);

    const totalDebit = parseFloat(document.getElementById('totalDebit').textContent);
    const totalCredit = parseFloat(document.getElementById('totalCredit').textContent);

    if (Math.abs(totalDebit - totalCredit) > 0.01) {
        e.preventDefault();
        alert('Journal does not balance! Debit and Credit must be equal.');
        return false;
    }

    if (lines.length < 2) {
        e.preventDefault();
        alert('Journal must have at least 2 lines.');
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
