<?php
/**
 * Income reclass repair session view: dry-run, execute, reverse, reconciliation
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../includes/re_income_reclass_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);
if (!has_role('Owner', $conn)) {
    http_response_code(403);
    die('Access denied. This tool is available to Owner only.');
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_fmt($n) { return number_format((float)$n, 2); }

$sessionId = (int)($_GET['id'] ?? $_POST['session_id'] ?? 0);
$message = '';
$messageType = 'info';

if (!re_income_reclass_schema_ready($conn)) {
    http_response_code(503);
    die('Repair tables not installed. Apply migrations/re_income_reclass_repair.sql after approval.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $sessionId = (int)($_POST['session_id'] ?? $sessionId);

    if ($action === 'save_correction_date') {
        $corr = trim((string)($_POST['correction_date'] ?? ''));
        if ($corr !== '' && is_period_locked($companyId, $corr)) {
            $message = 'Correction date is in a locked fiscal year.';
            $messageType = 'danger';
        } else {
            $conn->prepare("UPDATE re_income_reclass_sessions SET correction_date = ?, status = IF(status='dry_run_passed','draft',status) WHERE id = ? AND company_id = ?")
                ->execute([$corr !== '' ? $corr : null, $sessionId, $companyId]);
            $message = 'Correction date saved. Re-run Dry Run.';
            $messageType = 'success';
        }
    } elseif ($action === 'dry_run') {
        $res = re_income_reclass_dry_run($conn, $companyId, $sessionId);
        $message = $res['success'] ? 'Dry run passed. Review preview then Execute.' : ('Dry run issues: ' . ($res['error'] ?? ''));
        $messageType = $res['success'] ? 'success' : 'warning';
    } elseif ($action === 'execute') {
        $confirm = trim((string)($_POST['confirm_session'] ?? ''));
        $sess = re_income_reclass_load_session($conn, $companyId, $sessionId);
        if (!$sess || $confirm !== (string)$sess['session_number']) {
            $message = 'Confirmation failed. Type the exact session number to execute.';
            $messageType = 'danger';
        } else {
            $res = re_income_reclass_execute($conn, $companyId, $sessionId, current_user_id());
            $message = $res['success']
                ? ('Execute completed. Repaired=' . (int)($res['results']['repaired'] ?? 0))
                : ('Execute finished with issues: ' . ($res['error'] ?? ''));
            $messageType = $res['success'] ? 'success' : 'warning';
        }
    } elseif ($action === 'reverse_session') {
        $confirm = trim((string)($_POST['confirm_session'] ?? ''));
        $sess = re_income_reclass_load_session($conn, $companyId, $sessionId);
        if (!$sess || $confirm !== (string)$sess['session_number']) {
            $message = 'Confirmation failed for reverse.';
            $messageType = 'danger';
        } else {
            $res = re_income_reclass_reverse_session($conn, $companyId, $sessionId, current_user_id(), 'Session reverse');
            $message = $res['success'] ? ('Reversed ' . (int)$res['reversed'] . ' lines') : ('Reverse issues: ' . ($res['error'] ?? ''));
            $messageType = $res['success'] ? 'success' : 'warning';
        }
    } elseif ($action === 'reverse_line') {
        $lineId = (int)($_POST['line_id'] ?? 0);
        $res = re_income_reclass_reverse_line($conn, $companyId, $lineId, current_user_id(), 'Line reverse');
        $message = $res['success'] ? 'Line reversed.' : ('Reverse failed: ' . ($res['error'] ?? ''));
        $messageType = $res['success'] ? 'success' : 'danger';
    }
}

$session = re_income_reclass_load_session($conn, $companyId, $sessionId);
if (!$session) {
    http_response_code(404);
    die('Session not found for this company.');
}
$lines = re_income_reclass_load_lines($conn, $companyId, $sessionId);
$dry = $session['dry_run_json'] ? json_decode((string)$session['dry_run_json'], true) : null;
$reconAfter = $session['recon_after_json'] ? json_decode((string)$session['recon_after_json'], true) : null;
$reconBefore = $session['recon_before_json'] ? json_decode((string)$session['recon_before_json'], true) : null;

$pageTitle = 'Repair Session ' . $session['session_number'];
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="page-header-label mb-0">
        <i class="bi bi-journal-check"></i> <?= h($session['session_number']) ?>
        <span class="badge bg-secondary ms-2"><?= h($session['status']) ?></span>
    </div>
    <div class="d-flex gap-2">
        <a href="income_reclass_sessions.php" class="btn btn-outline-secondary">All Sessions</a>
        <a href="income_mispost_report.php" class="btn btn-outline-secondary">Mispost Report</a>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-<?= h($messageType) ?>"><?= h($message) ?></div><?php endif; ?>

<div class="alert alert-warning">
    Repairs only reclassify GL income (Dr wrong / Cr correct). Original invoice journals, AR, VAT, receipts and allocations stay unchanged.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-2"><div class="card card-round"><div class="card-body text-center"><div class="small text-muted">Selected</div><div class="h4 mb-0"><?= (int)$session['selected_count'] ?></div></div></div></div>
    <div class="col-md-2"><div class="card card-round"><div class="card-body text-center"><div class="small text-muted">Repaired</div><div class="h4 mb-0"><?= (int)$session['repaired_count'] ?></div></div></div></div>
    <div class="col-md-2"><div class="card card-round"><div class="card-body text-center"><div class="small text-muted">Failed</div><div class="h4 mb-0"><?= (int)$session['failed_count'] ?></div></div></div></div>
    <div class="col-md-2"><div class="card card-round"><div class="card-body text-center"><div class="small text-muted">Skipped</div><div class="h4 mb-0"><?= (int)$session['skipped_count'] ?></div></div></div></div>
    <div class="col-md-2"><div class="card card-round"><div class="card-body text-center"><div class="small text-muted">Amount</div><div class="h5 mb-0"><?= money_fmt($session['total_amount']) ?></div></div></div></div>
    <div class="col-md-2"><div class="card card-round"><div class="card-body text-center"><div class="small text-muted">Journal range</div><div class="small mb-0"><?= $session['repair_journal_id_min'] ? ('#'.(int)$session['repair_journal_id_min'].'–'.(int)$session['repair_journal_id_max']) : '—' ?></div></div></div></div>
</div>

<div class="card card-round mb-3">
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?php csrf_field(); ?>
            <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
            <input type="hidden" name="action" value="save_correction_date">
            <div class="col-md-4">
                <label class="form-label">Correction date (open period, when original is locked)</label>
                <input type="date" name="correction_date" class="form-control" value="<?= h((string)($session['correction_date'] ?? '')) ?>">
            </div>
            <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Save Date</button></div>
        </form>
        <hr>
        <?php
        $canDryRun = !in_array((string)$session['status'], ['completed', 'reversed'], true);
        $canExecute = in_array((string)$session['status'], ['dry_run_passed', 'partial'], true);
        ?>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" class="d-inline"><?php csrf_field(); ?>
                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                <input type="hidden" name="action" value="dry_run">
                <button class="btn btn-info text-white" type="submit" <?= $canDryRun ? '' : 'disabled' ?>>Dry Run (no journals)</button>
            </form>
            <form method="post" class="d-inline-flex gap-2 align-items-center" onsubmit="return confirm('Execute repair journals for this session?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                <input type="hidden" name="action" value="execute">
                <input type="text" name="confirm_session" class="form-control form-control-sm" placeholder="Type <?= h($session['session_number']) ?>" <?= $canExecute ? 'required' : 'disabled' ?>>
                <button class="btn btn-danger" type="submit" <?= $canExecute ? '' : 'disabled' ?>>Execute Repair</button>
            </form>
            <form method="post" class="d-inline-flex gap-2 align-items-center" onsubmit="return confirm('Reverse ALL repaired lines in this session?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                <input type="hidden" name="action" value="reverse_session">
                <input type="text" name="confirm_session" class="form-control form-control-sm" placeholder="Type <?= h($session['session_number']) ?>" required>
                <button class="btn btn-outline-warning" type="submit">Reverse Session</button>
            </form>
        </div>
    </div>
</div>

<?php if (is_array($dry)): ?>
<div class="card card-round mb-3">
    <div class="card-header"><strong>Dry Run Preview</strong>
        <span class="badge bg-<?= !empty($dry['passed']) ? 'success' : 'warning' ?> ms-2">
            OK <?= (int)($dry['ok_count'] ?? 0) ?> / Fail <?= (int)($dry['fail_count'] ?? 0) ?> / Skip <?= (int)($dry['skip_count'] ?? 0) ?>
        </span>
    </div>
    <div class="card-body small">
        Amount OK: <?= money_fmt($dry['total_ok_amount'] ?? 0) ?> AED
        <?php if (!empty($dry['ran_at'])): ?> · Ran <?= h($dry['ran_at']) ?><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (is_array($reconBefore) || is_array($reconAfter)): ?>
<div class="card card-round mb-3">
    <div class="card-header"><strong>Reconciliation</strong></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Before</h6>
                <pre class="small bg-light p-2"><?= h(json_encode($reconBefore, JSON_PRETTY_PRINT)) ?></pre>
            </div>
            <div class="col-md-6">
                <h6>After</h6>
                <pre class="small bg-light p-2"><?= h(json_encode($reconAfter, JSON_PRETTY_PRINT)) ?></pre>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-header"><strong>Candidates in session</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Status</th>
                    <th>Invoice</th>
                    <th>Original JRN</th>
                    <th>Type</th>
                    <th>Wrong → Correct</th>
                    <th class="text-end">Amount</th>
                    <th>Repair JRN</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><span class="badge bg-light text-dark"><?= h($line['status']) ?></span></td>
                        <td><a href="../billing_invoice_view.php?id=<?= (int)$line['invoice_id'] ?>"><?= h($line['invoice_number']) ?></a></td>
                        <td><a href="journal_entry_view.php?id=<?= (int)$line['original_journal_id'] ?>"><?= h($line['journal_number']) ?></a></td>
                        <td><code><?= h($line['obligation_type']) ?></code></td>
                        <td><code><?= h($line['posted_account_code']) ?></code> → <code><?= h($line['expected_account_code']) ?></code></td>
                        <td class="text-end"><?= money_fmt($line['amount']) ?></td>
                        <td>
                            <?php if (!empty($line['repair_journal_id'])): ?>
                                <a href="journal_entry_view.php?id=<?= (int)$line['repair_journal_id'] ?>">#<?= (int)$line['repair_journal_id'] ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= h((string)($line['correction_date'] ?: $line['original_journal_date'])) ?></td>
                        <td>
                            <?php if ($line['status'] === 'repaired'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Reverse this repair line?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                                    <input type="hidden" name="action" value="reverse_line">
                                    <input type="hidden" name="line_id" value="<?= (int)$line['id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" type="submit">Reverse</button>
                                </form>
                            <?php elseif (!empty($line['error_message'])): ?>
                                <span class="small text-danger"><?= h($line['error_message']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
