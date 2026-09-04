<?php
/**
 * Real Estate Accounting - Period Close
 * Close a fiscal period and prepare for next period
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

require_once __DIR__ . '/accounting_engine.php';

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$success = '';
$error = '';

$fiscalYearId = !empty($_GET['fiscal_year_id']) ? (int)$_GET['fiscal_year_id'] : null;

if (!$fiscalYearId) {
    header('Location: periods.php');
    exit;
}

// Get fiscal year info
$stmt = $conn->prepare("
    SELECT * FROM re_fiscal_years
    WHERE id = ? AND company_id = ?
");
$stmt->execute([$fiscalYearId, $currentCompanyId]);
$fiscalYear = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fiscalYear) {
    header('Location: periods.php');
    exit;
}

// Get period summary
$summary = [
    'total_journals' => 0,
    'total_debit' => 0,
    'total_credit' => 0,
    'total_invoices' => 0,
    'total_payments' => 0
];

$stmt = $conn->prepare("
    SELECT COUNT(*) as count, SUM(total_debit) as total_debit, SUM(total_credit) as total_credit
    FROM re_journal_headers
    WHERE company_id = ? AND journal_date BETWEEN ? AND ? AND is_posted = 1
");
$stmt->execute([$currentCompanyId, $fiscalYear['start_date'], $fiscalYear['end_date']]);
$journalSummary = $stmt->fetch(PDO::FETCH_ASSOC);
$summary['total_journals'] = (int)$journalSummary['count'];
$summary['total_debit'] = (float)$journalSummary['total_debit'];
$summary['total_credit'] = (float)$journalSummary['total_credit'];

// Handle period close
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'close_period') {
        $closedBy = current_user_id();
        $result = close_fiscal_year($fiscalYearId, $closedBy);
        if ($result['success']) {
            $success = "Fiscal year closed successfully. Closing entries have been posted (Journal ID: " . $result['journal_id'] . "). All transactions for this period are now locked.";
        } else {
            $error = "Error closing period: " . ($result['error'] ?? 'Unknown error');
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Close Period';
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
        <i class="bi bi-lock"></i> Close Period: <?= h($fiscalYear['year_name']) ?>
    </div>
    <div>
        <a href="periods.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Periods
        </a>
    </div>
</div>

<?php if ($fiscalYear['is_closed']): ?>
    <div class="alert alert-warning">
        <i class="bi bi-lock-fill"></i> This fiscal year is already closed and locked.
    </div>
<?php endif; ?>

<!-- Period Summary -->
<div class="card card-round mb-4">
    <div class="card-header bg-light">
        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Period Summary</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Fiscal Year:</strong><br>
                <?= h($fiscalYear['year_name']) ?>
            </div>
            <div class="col-md-3">
                <strong>Period:</strong><br>
                <?= date('F d, Y', strtotime($fiscalYear['start_date'])) ?> to 
                <?= date('F d, Y', strtotime($fiscalYear['end_date'])) ?>
            </div>
            <div class="col-md-3">
                <strong>Status:</strong><br>
                <?php if ($fiscalYear['is_closed']): ?>
                    <span class="badge bg-danger">Closed</span>
                <?php else: ?>
                    <span class="badge bg-success">Open</span>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <strong>Total Journals:</strong><br>
                <span class="h5"><?= number_format($summary['total_journals']) ?></span>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
                <strong>Total Debit:</strong><br>
                <span class="h4 text-primary"><?= number_format($summary['total_debit'], 2) ?> AED</span>
            </div>
            <div class="col-md-6">
                <strong>Total Credit:</strong><br>
                <span class="h4 text-primary"><?= number_format($summary['total_credit'], 2) ?> AED</span>
            </div>
        </div>
    </div>
</div>

<!-- Close Period Form -->
<?php if (!$fiscalYear['is_closed']): ?>
    <div class="card card-round">
        <div class="card-header bg-warning text-dark">
            <h6 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Close Period</h6>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <strong>Warning:</strong> Closing a period will lock all transactions for this fiscal year. 
                This action cannot be undone. Make sure all transactions are posted and reconciled before closing.
            </div>
            
            <form method="POST" onsubmit="return confirm('Are you sure you want to close this period? This action cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="close_period">
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="confirmClose" required>
                    <label class="form-check-label" for="confirmClose">
                        I confirm that all transactions for this period are correct and ready to be closed.
                    </label>
                </div>
                
                <button type="submit" class="btn btn-warning" id="closeButton" disabled>
                    <i class="bi bi-lock"></i> Close Period
                </button>
            </form>
        </div>
    </div>

    <script>
    document.getElementById('confirmClose').addEventListener('change', function() {
        document.getElementById('closeButton').disabled = !this.checked;
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
