<?php
/**
 * Real Estate Accounting - Add/Edit Manual Journal Entry
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/accounting_engine.php';
require_once __DIR__ . '/journal_attachments_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$journalId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$templateId = !empty($_GET['template_id']) ? (int)$_GET['template_id'] : null;
$journal = null;
$journalLines = [];
$templateDescription = '';
$success = '';
$error = '';

// Load from template (new entry only)
if (!$journalId && $templateId) {
    $stmt = $conn->prepare("SELECT * FROM re_journal_templates WHERE id = ? AND company_id = ?");
    $stmt->execute([$templateId, $currentCompanyId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($template) {
        $templateDescription = $template['description'] ?? '';
        $decoded = json_decode($template['lines_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $i => $row) {
                $journalLines[] = [
                    'account_id' => (int)($row['account_id'] ?? 0),
                    'debit_amount' => (float)($row['debit'] ?? 0),
                    'credit_amount' => (float)($row['credit'] ?? 0),
                    'description' => (string)($row['description'] ?? ''),
                    'reference' => (string)($row['reference'] ?? ''),
                ];
            }
        }
    }
}

// Get journal if editing
if ($journalId) {
    $stmt = $conn->prepare("
        SELECT * FROM re_journal_headers
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$journalId, $currentCompanyId]);
    $journal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($journal) {
        if ($journal['is_posted']) {
            $error = "Cannot edit posted journal. Create a reversal entry instead.";
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
    }
}

// Get all active accounts
$accounts = $conn->prepare("
    SELECT id, account_code, account_name, account_type, normal_balance
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0
    ORDER BY account_code
");
$accounts->execute([$currentCompanyId]);
$allAccounts = $accounts->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Remove one attachment (posted from the standalone delete form below the main form)
    if (($_POST['action'] ?? '') === 'delete_attachment') {
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        if ($journalId && $journal && $attachmentId && journal_attachment_delete($conn, $attachmentId, $currentCompanyId)) {
            $success = 'Attachment deleted.';
        } else {
            $error = 'Could not delete the attachment.';
        }
        $attachmentDeleteOnly = true;
    }

    $journalDate = $_POST['journal_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $journalTypeForm = $_POST['journal_type'] ?? 'manual';
    $lines = json_decode($_POST['journal_lines'] ?? '[]', true);

    $journalType = in_array($journalTypeForm, ['manual', 'opening_balance']) ? $journalTypeForm : 'manual';

    if (!empty($attachmentDeleteOnly)) {
        // Nothing else to do for a delete-attachment post.
    } elseif (empty($lines) || count($lines) < 2) {
        $error = "Journal must have at least 2 lines";
    } else {
        // Build journal lines array
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
                'reference' => trim($line['reference'] ?? '')
            ];
        }
        
        if (count($journalLinesArray) < 2) {
            $error = "Journal must have at least 2 valid lines";
        } else {
            if ($journalId && $journal && !$journal['is_posted']) {
                // Update existing unposted journal — enforce period lock
                if (is_period_locked($currentCompanyId, $journalDate)) {
                    $error = 'Cannot save: the selected date falls in a closed (locked) period.';
                } else {
                    $conn->prepare("DELETE FROM re_journal_lines WHERE journal_id = ?")->execute([$journalId]);
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
                            $line['debit'], $line['credit'], $line['description'], $line['reference']
                        ]);
                    }
                    $success = "Journal entry updated successfully";
                    $upload = journal_attachments_save($conn, $_FILES['attachments'] ?? [], $journalId, $currentCompanyId, $userId);
                    if ($upload['saved'] > 0) {
                        $success .= sprintf(' %d attachment%s added.', $upload['saved'], $upload['saved'] === 1 ? '' : 's');
                    }
                    if ($upload['errors']) {
                        $error = implode(' ', $upload['errors']);
                    }
                }
            } else {
                // Create new journal (period lock and duplicate check are in create_journal_entry)
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
                    $success = "Journal entry created successfully. Journal #: {$result['journal_number']}";
                    $newJournalId = (int)($result['journal_id'] ?? 0);
                    if ($newJournalId) {
                        $upload = journal_attachments_save($conn, $_FILES['attachments'] ?? [], $newJournalId, $currentCompanyId, $userId);
                        if ($upload['saved'] > 0) {
                            $success .= sprintf(' %d attachment%s uploaded.', $upload['saved'], $upload['saved'] === 1 ? '' : 's');
                        }
                        if ($upload['errors']) {
                            $error = implode(' ', $upload['errors']);
                        }
                    }
                    $journal = null;
                    $journalLines = [];
                } else {
                    $error = $result['error'];
                }
            }
        }
    }
}

// Existing attachments (edit mode) - loaded after POST so deletions/uploads are reflected
$existingAttachments = ($journalId && $journal)
    ? journal_attachments_list($conn, $journalId, $currentCompanyId)
    : [];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $journalId ? 'Edit Journal Entry' : 'New Journal Entry';
require_once __DIR__ . '/../includes/re_layout_header.php';
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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-journal-plus"></i> <?= $journalId ? 'Edit' : 'New' ?> Journal Entry
    </div>
    <div>
        <a href="journal_entry_list.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<form method="POST" id="journalForm" enctype="multipart/form-data">
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
                    <select name="journal_type" class="form-select">
                        <option value="manual" <?= ($journal && ($journal['journal_type'] ?? '') === 'manual') ? 'selected' : '' ?>>Manual</option>
                        <option value="opening_balance" <?= ($journal && ($journal['journal_type'] ?? '') === 'opening_balance') ? 'selected' : '' ?>>Opening Balance</option>
                    </select>
                </div>
                <div class="col-md-7 mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" 
                           value="<?= $journal ? h($journal['description']) : h($templateDescription) ?>" 
                           placeholder="Journal entry description">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Journal Lines -->
    <div class="card card-round mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-list-ul"></i> Journal Lines</h6>
            <div class="d-flex gap-2">
                <?php if (!$journalId): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSaveAsTemplate" onclick="openSaveTemplateModal()">
                    <i class="bi bi-journal-bookmark"></i> Save as template
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-primary" onclick="addJournalLine()">
                    <i class="bi bi-plus-circle"></i> Add Line
                </button>
            </div>
        </div>
        <div class="card-body">
            <div id="journalLinesContainer">
                <!-- Lines will be added here by JavaScript -->
            </div>
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

    <!-- Attachments -->
    <div class="card card-round mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-paperclip"></i> Attachments<?= $existingAttachments ? ' (' . count($existingAttachments) . ')' : '' ?></h6>
        </div>
        <div class="card-body">
            <?php if ($existingAttachments): ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><i class="bi bi-file-earmark"></i> File</th>
                                <th style="width: 12%">Size</th>
                                <th style="width: 20%">Uploaded</th>
                                <th style="width: 20%" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existingAttachments as $att): ?>
                                <tr>
                                    <td>
                                        <i class="bi <?= h(journal_attachment_icon($att['file_name'])) ?>"></i>
                                        <?= h($att['file_name']) ?>
                                    </td>
                                    <td><?= h(journal_attachment_size($att['file_size'] ?? null)) ?></td>
                                    <td>
                                        <small class="text-muted">
                                            <?= h($att['uploaded_by_name'] ?: 'System') ?><br>
                                            <?= date('M d, Y H:i', strtotime($att['uploaded_at'])) ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <a href="journal_attachment_file.php?id=<?= (int)$att['id'] ?>" target="_blank"
                                           class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="journal_attachment_file.php?id=<?= (int)$att['id'] ?>&mode=download"
                                           class="btn btn-sm btn-outline-secondary" title="Download">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <button type="submit" form="deleteAttachmentForm" name="attachment_id"
                                                value="<?= (int)$att['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"
                                                onclick="return confirm('Delete this attachment? This cannot be undone.');">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <label class="form-label">Add Files</label>
            <input type="file" name="attachments[]" class="form-control" multiple
                   accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.xls,.xlsx,.doc,.docx,.csv,.txt">
            <div class="form-text">
                Supporting documents for this journal (invoice, receipt, bank advice, approval email).
                PDF, image, Word, Excel, CSV or text - up to 10 MB each.
                Files are saved when you <?= $journalId ? 'update' : 'create' ?> the journal entry.
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

<?php if ($existingAttachments): ?>
<form method="POST" id="deleteAttachmentForm" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_attachment">
</form>
<?php endif; ?>

<script>
const accounts = <?= json_encode($allAccounts) ?>;
let lineCounter = 0;
let journalLines = <?= json_encode($journalLines) ?>;

// Initialize with existing lines if editing
if (journalLines.length > 0) {
    journalLines.forEach(line => {
        addJournalLine(line.account_id, line.debit_amount, line.credit_amount, line.description, line.reference);
    });
} else {
    // Add 2 empty lines for new journal
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
                   value="${description}" placeholder="Line description">
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
    const lineDiv = btn.closest('.journal-line');
    lineDiv.remove();
    updateTotals();
}

function updateTotals() {
    let totalDebit = 0;
    let totalCredit = 0;
    
    document.querySelectorAll('.journal-line').forEach(line => {
        const debit = parseFloat(line.querySelector('.debit-input').value) || 0;
        const credit = parseFloat(line.querySelector('.credit-input').value) || 0;
        totalDebit += debit;
        totalCredit += credit;
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

function openSaveTemplateModal() {
    const lines = [];
    document.querySelectorAll('.journal-line').forEach(line => {
        const accountId = line.querySelector('.account-select').value;
        const debit = parseFloat(line.querySelector('.debit-input').value) || 0;
        const credit = parseFloat(line.querySelector('.credit-input').value) || 0;
        const description = (line.querySelector('.description-input') || {}).value || '';
        if (accountId && (debit > 0 || credit > 0)) {
            lines.push({ account_id: accountId, debit: debit, credit: credit, description: description });
        }
    });
    if (lines.length < 2) {
        alert('Add at least 2 lines with amounts before saving as template.');
        return;
    }
    document.getElementById('templateLinesJson').value = JSON.stringify(lines);
    const modal = document.getElementById('saveTemplateModal');
    if (modal && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }
}

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
    
    // Validate balance
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

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
