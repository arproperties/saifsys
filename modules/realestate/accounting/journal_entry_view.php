<?php
/**
 * Real Estate Accounting - View Journal Entry
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

if (!$journalId) {
    header('Location: journal_entry_list.php');
    exit;
}

// Helper to load journal header (approval fields from jh.* when migration run)
function load_journal_header(PDO $conn, int $journalId, int $companyId): ?array {
    $stmt = $conn->prepare("
        SELECT jh.*, 
               u.username as created_by_name,
               u2.username as posted_by_name
        FROM re_journal_headers jh
        LEFT JOIN user u ON u.id = jh.created_by
        LEFT JOIN user u2 ON u2.id = jh.posted_by
        WHERE jh.id = ? AND jh.company_id = ?
    ");
    $stmt->execute([$journalId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Resolve view company for deep links (e.g. ARS journals while session is another company).
 * Fail closed: only switch/load when user has access to the journal's company.
 */
function resolve_journal_view_company(PDO $conn, int $journalId, int $sessionCompanyId, ?int $userId): int {
    $hintCompany = !empty($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

    $stmt = $conn->prepare('SELECT company_id FROM re_journal_headers WHERE id = ? LIMIT 1');
    $stmt->execute([$journalId]);
    $journalCompanyId = (int)($stmt->fetchColumn() ?: 0);
    if ($journalCompanyId <= 0) {
        return $sessionCompanyId;
    }

    if ($journalCompanyId === $sessionCompanyId) {
        return $sessionCompanyId;
    }

    $uid = (int)($userId ?? 0);
    $canAccess = false;
    if ($uid > 0) {
        if (user_has_company_access($conn, $uid, $journalCompanyId)) {
            $canAccess = true;
        } elseif (function_exists('has_role') && (has_role('Owner', $conn) || has_role('Admin', $conn) || has_role('Manager', $conn))) {
            $canAccess = true;
        }
    }

    // Optional explicit company_id must match the journal and be allowed.
    if ($hintCompany > 0 && $hintCompany !== $journalCompanyId) {
        return $sessionCompanyId;
    }

    if ($canAccess) {
        // Align session so post/reverse actions stay company-scoped correctly.
        set_current_company($journalCompanyId);
        return $journalCompanyId;
    }

    return $sessionCompanyId;
}

$currentCompanyId = resolve_journal_view_company($conn, $journalId, $currentCompanyId, $userId);

// Helper to load journal lines
function load_journal_lines(PDO $conn, int $journalId): array {
    $stmt = $conn->prepare("
        SELECT jl.*, coa.account_code, coa.account_name, coa.account_type
        FROM re_journal_lines jl
        JOIN re_chart_of_accounts coa ON coa.id = jl.account_id
        WHERE jl.journal_id = ?
        ORDER BY jl.line_number
    ");
    $stmt->execute([$journalId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Initial load
$journal = load_journal_header($conn, $journalId, $currentCompanyId);

if (!$journal) {
    header('Location: journal_entry_list.php');
    exit;
}

// Get journal lines
$journalLines = load_journal_lines($conn, $journalId);

// GL accounts that are bank accounts. Used only to decide which journal lines
// get a link through to their account ledger.
function load_bank_accounts_by_gl(PDO $conn, int $companyId): array {
    try {
        $stmt = $conn->prepare("
            SELECT gl_account_id, id
            FROM re_bank_accounts
            WHERE company_id = ? AND is_active = 1 AND gl_account_id IS NOT NULL
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Where a journal's source document lives, or null when that reference_type has
 * no detail page of its own (vendor_payment, tenant_credit, ars_*, payroll ...).
 * Those journals stay plain text rather than linking somewhere unhelpful.
 */
function journal_source_document_url(?string $referenceType, int $referenceId): ?string {
    if (!$referenceType || $referenceId <= 0) {
        return null;
    }
    $pages = [
        'payment'        => '../payment_view.php',
        'invoice'        => '../billing_invoice_view.php',
        'vendor_invoice' => 'vendor_bill_view.php',
    ];
    if (!isset($pages[$referenceType])) {
        return null;
    }
    return $pages[$referenceType] . '?id=' . $referenceId;
}

$bankAccountsByGl = load_bank_accounts_by_gl($conn, $currentCompanyId);
// Ledger window: the exact date of this journal entry, so the ledger opens on
// the day you came from rather than the whole month.
$journalTs = strtotime($journal['journal_date']);
$statementFrom = date('Y-m-d', $journalTs);
$statementTo = $statementFrom;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'post' && !$journal['is_posted']) {
        $result = post_journal($journalId, $userId);
        if ($result['success']) {
            $_SESSION['success'] = 'Journal entry posted successfully';
            header('Location: journal_entry_view.php?id=' . $journalId);
            exit;
        } else {
            $_SESSION['error'] = 'Error posting journal: ' . $result['error'];
        }
    } elseif ($action === 'submit_approval' && !$journal['is_posted']) {
        $result = submit_journal_for_approval($journalId, $userId);
        if ($result['success']) {
            $_SESSION['success'] = 'Journal submitted for approval';
            header('Location: journal_entry_view.php?id=' . $journalId);
            exit;
        } else {
            $_SESSION['error'] = $result['error'];
        }
    } elseif ($action === 'approve' && !$journal['is_posted']) {
        $result = approve_journal($journalId, $userId);
        if ($result['success']) {
            $_SESSION['success'] = 'Journal approved. You can now post it.';
            header('Location: journal_entry_view.php?id=' . $journalId);
            exit;
        } else {
            $_SESSION['error'] = $result['error'];
        }
    } elseif ($action === 'upload_attachment') {
        $upload = journal_attachments_save($conn, $_FILES['attachments'] ?? [], $journalId, $currentCompanyId, $userId);
        if ($upload['saved'] > 0) {
            $_SESSION['success'] = sprintf('%d attachment%s uploaded.', $upload['saved'], $upload['saved'] === 1 ? '' : 's');
        }
        if ($upload['errors']) {
            $_SESSION['error'] = implode(' ', $upload['errors']);
        } elseif ($upload['saved'] === 0) {
            $_SESSION['error'] = 'No file selected.';
        }
        header('Location: journal_entry_view.php?id=' . $journalId);
        exit;
    } elseif ($action === 'delete_attachment') {
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        if ($attachmentId && journal_attachment_delete($conn, $attachmentId, $currentCompanyId)) {
            $_SESSION['success'] = 'Attachment deleted.';
        } else {
            $_SESSION['error'] = 'Could not delete the attachment.';
        }
        header('Location: journal_entry_view.php?id=' . $journalId);
        exit;
    } elseif ($action === 'reverse' && $journal['is_posted'] && !$journal['is_reversed']) {
        $reason = trim($_POST['reason'] ?? '');
        $result = reverse_journal($journalId, $reason, $userId);
        if ($result['success']) {
            $_SESSION['success'] = 'Journal entry reversed successfully. Reversal Journal #: ' . $result['reversal_journal_id'];
            header('Location: journal_entry_view.php?id=' . $journalId);
            exit;
        } else {
            $_SESSION['error'] = 'Error reversing journal: ' . $result['error'];
        }
    }
}

// Refresh journal data after actions
if (isset($_SESSION['success']) || isset($_SESSION['error'])) {
    $journal = load_journal_header($conn, $journalId, $currentCompanyId);
    $journalLines = load_journal_lines($conn, $journalId);
}

$attachments = journal_attachments_list($conn, $journalId, $currentCompanyId);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Journal Entry Details';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= h($_SESSION['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= h($_SESSION['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i class="bi bi-journal-text"></i> Journal Entry: <?= h($journal['journal_number']) ?>
    </div>
    <div>
        <a href="journal_entry_list.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
        <?php if (!$journal['is_posted']): ?>
            <a href="journal_entry_add.php?id=<?= $journalId ?>" class="btn btn-outline-info">
                <i class="bi bi-pencil"></i> Edit
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Journal Summary -->
<div class="card card-round mb-4">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Journal Information</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Journal Number:</strong><br>
                <code><?= h($journal['journal_number']) ?></code>
            </div>
            <div class="col-md-3">
                <strong>Date:</strong><br>
                <?= date('F d, Y', strtotime($journal['journal_date'])) ?>
            </div>
            <div class="col-md-3">
                <strong>Type:</strong><br>
                <span class="badge bg-info"><?= ucfirst(h($journal['journal_type'])) ?></span>
            </div>
            <div class="col-md-3">
                <strong>Status:</strong><br>
                <?php if ($journal['is_posted']): ?>
                    <span class="badge bg-success">
                        <i class="bi bi-check-circle"></i> Posted
                    </span>
                    <?php if ($journal['is_reversed']): ?>
                        <span class="badge bg-warning">Reversed</span>
                    <?php endif; ?>
                <?php else: ?>
                    <?php
                    $approvalStatus = $journal['approval_status'] ?? null;
                    if ($approvalStatus === 'submitted'): ?>
                        <span class="badge bg-info">Submitted for approval</span>
                    <?php elseif ($approvalStatus === 'approved'): ?>
                        <span class="badge bg-primary">Approved</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?= $approvalStatus === 'draft' ? 'Draft' : 'Unposted' ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($journal['description']): ?>
            <div class="row mt-2">
                <div class="col-12">
                    <strong>Description:</strong><br>
                    <?= h($journal['description']) ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($journal['reference_type'] && $journal['reference_id']): ?>
            <div class="row mt-2">
                <div class="col-12">
                    <strong>Reference:</strong><br>
                    <?php $sourceUrl = journal_source_document_url($journal['reference_type'], (int)$journal['reference_id']); ?>
                    <?php if ($sourceUrl): ?>
                        <a href="<?= h($sourceUrl) ?>" title="Open the source document">
                            <code><?= h($journal['reference_type']) ?> #<?= $journal['reference_id'] ?></code>
                            <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    <?php else: ?>
                        <code><?= h($journal['reference_type']) ?> #<?= $journal['reference_id'] ?></code>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="row mt-2">
            <div class="col-md-3">
                <strong>Total Debit:</strong><br>
                <span class="h5 text-primary"><?= number_format($journal['total_debit'], 2) ?> AED</span>
            </div>
            <div class="col-md-3">
                <strong>Total Credit:</strong><br>
                <span class="h5 text-primary"><?= number_format($journal['total_credit'], 2) ?> AED</span>
            </div>
            <div class="col-md-3">
                <strong>Created By:</strong><br>
                <?= h($journal['created_by_name'] ?: 'System') ?>
                <small class="text-muted"><?= date('M d, Y H:i', strtotime($journal['created_at'])) ?></small>
            </div>
            <?php if ($journal['is_posted']): ?>
                <div class="col-md-3">
                    <strong>Posted By:</strong><br>
                    <?= h($journal['posted_by_name'] ?: 'System') ?>
                    <small class="text-muted"><?= date('M d, Y H:i', strtotime($journal['posted_at'])) ?></small>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Journal Lines -->
<div class="card card-round mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-ul"></i> Journal Lines (<?= count($journalLines) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 5%">#</th>
                        <th style="width: 10%"><i class="bi bi-hash"></i> Account Code</th>
                        <th style="width: 30%"><i class="bi bi-tag"></i> Account Name</th>
                        <th style="width: 10%"><i class="bi bi-tag"></i> Type</th>
                        <th style="width: 20%"><i class="bi bi-file-text"></i> Description</th>
                        <th style="width: 12.5%" class="text-end"><i class="bi bi-arrow-down-left"></i> Debit</th>
                        <th style="width: 12.5%" class="text-end"><i class="bi bi-arrow-up-right"></i> Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($journalLines as $line): ?>
                        <tr>
                            <td><?= $line['line_number'] ?></td>
                            <td><code><?= h($line['account_code']) ?></code></td>
                            <td>
                                <?php $bankAccountId = $bankAccountsByGl[$line['account_id']] ?? null; ?>
                                <?php if ($bankAccountId): ?>
                                    <a href="general_ledger.php?<?= h(http_build_query([
                                            'account_id' => (int) $line['account_id'],
                                            'date_from' => $statementFrom,
                                            'date_to' => $statementTo,
                                        ])) ?>"
                                       title="View this bank account's ledger">
                                        <?= h($line['account_name']) ?>
                                        <i class="bi bi-box-arrow-up-right small"></i>
                                    </a>
                                <?php else: ?>
                                    <?= h($line['account_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark"><?= h($line['account_type']) ?></span>
                            </td>
                            <td><?= h($line['description'] ?: '-') ?></td>
                            <td class="text-end">
                                <?php if ($line['debit_amount'] > 0): ?>
                                    <strong><?= number_format($line['debit_amount'], 2) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($line['credit_amount'] > 0): ?>
                                    <strong><?= number_format($line['credit_amount'], 2) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-dark fw-bold">
                        <td colspan="5" class="text-end">TOTAL:</td>
                        <td class="text-end"><?= number_format($journal['total_debit'], 2) ?></td>
                        <td class="text-end"><?= number_format($journal['total_credit'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Actions -->
<?php
$approvalStatus = $journal['approval_status'] ?? null;
$canPost = !$journal['is_posted']; // Post allowed from draft or approved (optional workflow)
$showSubmit = !$journal['is_posted'] && $approvalStatus === 'draft';
$showApprove = !$journal['is_posted'] && $approvalStatus === 'submitted';
?>
<?php if ($showSubmit): ?>
    <div class="card card-round mb-4">
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_approval">
                <button type="submit" class="btn btn-info">
                    <i class="bi bi-send"></i> Submit for Approval
                </button>
                <small class="text-muted ms-3">An approver can then approve and post this journal</small>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php if ($showApprove): ?>
    <div class="card card-round mb-4">
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Approve Journal
                </button>
                <small class="text-muted ms-3">After approval, the journal can be posted to the General Ledger</small>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php if ($canPost): ?>
    <div class="card card-round mb-4">
        <div class="card-body">
            <form method="POST" onsubmit="return confirm('Are you sure you want to post this journal entry? This action cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="post">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle"></i> Post Journal Entry
                </button>
                <small class="text-muted ms-3">Posting will create entries in the General Ledger</small>
            </form>
        </div>
    </div>
<?php elseif (!$journal['is_posted'] && $approvalStatus && $approvalStatus !== 'approved'): ?>
    <div class="card card-round mb-4">
        <div class="card-body">
            <p class="text-muted mb-0">
                <?php if ($approvalStatus === 'draft'): ?>
                    <i class="bi bi-info-circle"></i> Submit for approval to allow an approver to review and post this journal.
                <?php else: ?>
                    <i class="bi bi-hourglass-split"></i> Waiting for approval. Once approved, this journal can be posted.
                <?php endif; ?>
            </p>
        </div>
    </div>
<?php endif; ?>
<?php if ($journal['is_posted'] && !$journal['is_reversed']): ?>
    <div class="card card-round mb-4">
        <div class="card-body">
            <form method="POST" onsubmit="return confirm('Are you sure you want to reverse this journal entry? A reversal entry will be created.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reverse">
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Reason for Reversal</label>
                        <input type="text" name="reason" class="form-control" placeholder="Enter reason for reversal" required>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-arrow-counterclockwise"></i> Reverse Journal Entry
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>