<?php
/**
 * Construction — Admin tool: retire historical co_contractor_payments
 *
 * Workflow A — Verified Match (BR-CO-BP-004):
 *   Confirm matching Supplier/AP reference, reverse journal, delete row, audit.
 *
 * Workflow B — Business Approved Legacy Retirement (BR-CO-BP-007):
 *   No Supplier/AP match required. Administrator confirmation + mandatory reason.
 *   Reverse journals via reverse_journal(), delete ops rows, full audit log.
 *   Supports single-row and bulk (all remaining for company).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/construction_helpers.php';
require_once __DIR__ . '/../includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn);

require_once __DIR__ . '/../../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn);
if (!$cid) {
    http_response_code(403);
    die('Company context required.');
}
$userId = current_user_id();
$msg = '';
$err = '';
$bulkResults = [];

if (!function_exists('reverse_journal')) {
    require_once __DIR__ . '/../../realestate/accounting/accounting_engine.php';
}

const CO_RETIRE_MODE_VERIFIED = 'verified_match';
const CO_RETIRE_MODE_LEGACY = 'business_approved_legacy';

function co_retirement_log_ready(PDO $conn): bool {
    try {
        return (bool)$conn->query("SHOW TABLES LIKE 'co_contractor_payment_retirement_log'")->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function co_retirement_mode_column_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = (bool)$conn->query("SHOW COLUMNS FROM co_contractor_payment_retirement_log LIKE 'retirement_mode'")->fetch();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function co_contractor_payment_bank_reco_blocked(PDO $conn, int $companyId, int $paymentId): bool {
    $tables = [
        "SELECT COUNT(*) FROM co_bank_reco_matches WHERE company_id = ? AND source_type = 'co_contractor_payment' AND source_id = ?",
        "SELECT COUNT(*) FROM bank_reconciliation_matches WHERE company_id = ? AND source_type = 'co_contractor_payment' AND source_id = ?",
    ];
    foreach ($tables as $sql) {
        try {
            $st = $conn->prepare($sql);
            $st->execute([$companyId, $paymentId]);
            if ((int)$st->fetchColumn() > 0) {
                return true;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return false;
}

/**
 * Load one contractor payment scoped to company.
 * @return array<string,mixed>|null
 */
function co_retirement_load_payment(PDO $conn, int $companyId, int $paymentId): ?array {
    $st = $conn->prepare("
        SELECT cp.*, c.contractor_name, p.project_code, p.project_name
        FROM co_contractor_payments cp
        JOIN co_project_contractors pc ON pc.id = cp.project_contractor_id AND pc.company_id = cp.company_id
        JOIN co_contractors c ON c.id = pc.contractor_id AND c.company_id = cp.company_id
        JOIN co_projects p ON p.id = pc.project_id AND p.company_id = cp.company_id
        WHERE cp.id = ? AND cp.company_id = ?
    ");
    $st->execute([$paymentId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Reverse journal (if posted) + audit + delete one contractor payment.
 * @return array{ok:bool,error?:string,reverse_journal_id?:?int,mode?:string}
 */
function co_retire_contractor_payment(
    PDO $conn,
    int $companyId,
    int $paymentId,
    string $mode,
    string $reason,
    ?int $userId
): array {
    if (!in_array($mode, [CO_RETIRE_MODE_VERIFIED, CO_RETIRE_MODE_LEGACY], true)) {
        return ['ok' => false, 'error' => 'Invalid retirement mode.'];
    }
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A reason / verification note is required.'];
    }
    if (!co_retirement_log_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/construction_contractor_supplier_link.sql first.'];
    }
    if ($mode === CO_RETIRE_MODE_LEGACY && !co_retirement_mode_column_ready($conn)) {
        return ['ok' => false, 'error' => 'Run migrations/construction_contractor_payment_retirement_mode.sql first.'];
    }

    $pay = co_retirement_load_payment($conn, $companyId, $paymentId);
    if (!$pay) {
        return ['ok' => false, 'error' => 'Payment not found.'];
    }
    if (co_contractor_payment_bank_reco_blocked($conn, $companyId, $paymentId)) {
        return ['ok' => false, 'error' => 'Payment is linked in bank reconciliation. Unmatch it before retiring.'];
    }

    $reverseJournalId = null;
    $journalId = !empty($pay['journal_id']) ? (int)$pay['journal_id'] : null;
    if ($journalId) {
        $jh = $conn->prepare("SELECT id, is_posted, is_reversed FROM re_journal_headers WHERE id = ? AND company_id = ?");
        $jh->execute([$journalId, $companyId]);
        $header = $jh->fetch(PDO::FETCH_ASSOC);
        if ($header && !empty($header['is_posted']) && empty($header['is_reversed'])) {
            $prefix = $mode === CO_RETIRE_MODE_LEGACY
                ? 'Business Approved Legacy Retirement of contractor payment #'
                : 'Retire contractor payment #';
            $rev = reverse_journal($journalId, $prefix . $paymentId . ': ' . $reason, $userId);
            if (empty($rev['success'])) {
                return ['ok' => false, 'error' => 'Journal reverse failed: ' . (string)($rev['error'] ?? 'unknown error')];
            }
            $reverseJournalId = (int)($rev['reversal_journal_id'] ?? 0);
        }
    }

    try {
        $conn->beginTransaction();
        if (co_retirement_mode_column_ready($conn)) {
            $log = $conn->prepare("
                INSERT INTO co_contractor_payment_retirement_log
                (company_id, contractor_payment_id, project_contractor_id, payment_date, amount, net_paid, retention_held,
                 journal_id, reverse_journal_id, verified_against_note, retirement_mode, retired_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $log->execute([
                $companyId,
                $paymentId,
                (int)$pay['project_contractor_id'],
                $pay['payment_date'],
                $pay['amount'],
                $pay['net_paid'],
                $pay['retention_held'],
                $journalId,
                $reverseJournalId,
                $reason,
                $mode,
                $userId,
            ]);
        } else {
            $note = ($mode === CO_RETIRE_MODE_LEGACY ? '[business_approved_legacy] ' : '[verified_match] ') . $reason;
            $log = $conn->prepare("
                INSERT INTO co_contractor_payment_retirement_log
                (company_id, contractor_payment_id, project_contractor_id, payment_date, amount, net_paid, retention_held,
                 journal_id, reverse_journal_id, verified_against_note, retired_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $log->execute([
                $companyId,
                $paymentId,
                (int)$pay['project_contractor_id'],
                $pay['payment_date'],
                $pay['amount'],
                $pay['net_paid'],
                $pay['retention_held'],
                $journalId,
                $reverseJournalId,
                $note,
                $userId,
            ]);
        }
        $del = $conn->prepare("DELETE FROM co_contractor_payments WHERE id = ? AND company_id = ?");
        $del->execute([$paymentId, $companyId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['ok' => false, 'error' => 'Delete/audit failed: ' . $e->getMessage()];
    }

    return [
        'ok' => true,
        'reverse_journal_id' => $reverseJournalId,
        'mode' => $mode,
        'amount' => (float)$pay['amount'],
        'journal_id' => $journalId,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'retire_verified') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $note = trim($_POST['verified_against_note'] ?? '');
        $confirm = !empty($_POST['confirm_retire']);
        if (!$confirm) {
            $err = 'You must confirm Supplier/AP verification before retiring.';
        } elseif ($paymentId <= 0) {
            $err = 'Invalid payment.';
        } else {
            $res = co_retire_contractor_payment($conn, $cid, $paymentId, CO_RETIRE_MODE_VERIFIED, $note, $userId);
            if ($res['ok']) {
                $msg = 'Retired contractor payment #' . $paymentId . ' (Verified Match). Dashboards and reports refresh from live queries.';
            } else {
                $err = $res['error'] ?? 'Retirement failed.';
            }
        }
    } elseif ($action === 'retire_legacy_one') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $reason = trim($_POST['legacy_reason'] ?? '');
        $confirm = !empty($_POST['confirm_legacy']);
        $ack = !empty($_POST['ack_no_match_required']);
        if (!$confirm || !$ack) {
            $err = 'Confirm Business Approved Legacy Retirement and acknowledge that Supplier/AP matching is not required.';
        } elseif (strlen($reason) < 15) {
            $err = 'Mandatory reason must be at least 15 characters (business approval / why legacy retirement applies).';
        } elseif ($paymentId <= 0) {
            $err = 'Invalid payment.';
        } else {
            $res = co_retire_contractor_payment($conn, $cid, $paymentId, CO_RETIRE_MODE_LEGACY, $reason, $userId);
            if ($res['ok']) {
                $msg = 'Legacy-retired contractor payment #' . $paymentId . '. Journal reversed if posted. Dashboards/reports use Supplier/AP only.';
            } else {
                $err = $res['error'] ?? 'Legacy retirement failed.';
            }
        }
    } elseif ($action === 'retire_legacy_bulk') {
        $reason = trim($_POST['legacy_reason'] ?? '');
        $confirm = !empty($_POST['confirm_legacy_bulk']);
        $ack = !empty($_POST['ack_no_match_required_bulk']);
        $typed = trim($_POST['bulk_confirm_phrase'] ?? '');
        if (!$confirm || !$ack) {
            $err = 'Confirm bulk Business Approved Legacy Retirement and acknowledge no Supplier/AP match is required.';
        } elseif (strcasecmp($typed, 'RETIRE ALL LEGACY') !== 0) {
            $err = 'Type RETIRE ALL LEGACY exactly to confirm bulk retirement.';
        } elseif (strlen($reason) < 15) {
            $err = 'Mandatory reason must be at least 15 characters.';
        } else {
            $ids = $conn->prepare("SELECT id FROM co_contractor_payments WHERE company_id = ? ORDER BY id");
            $ids->execute([$cid]);
            $paymentIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $okCount = 0;
            $failCount = 0;
            foreach ($paymentIds as $pid) {
                $res = co_retire_contractor_payment($conn, $cid, $pid, CO_RETIRE_MODE_LEGACY, $reason, $userId);
                $bulkResults[] = [
                    'id' => $pid,
                    'ok' => !empty($res['ok']),
                    'error' => $res['error'] ?? null,
                    'reverse_journal_id' => $res['reverse_journal_id'] ?? null,
                ];
                if (!empty($res['ok'])) {
                    $okCount++;
                } else {
                    $failCount++;
                    // Continue remaining rows; report failures
                }
            }
            if ($okCount > 0 && $failCount === 0) {
                $msg = "Business Approved Legacy Retirement complete: {$okCount} payment(s) retired. Dashboards and reports now exclude them.";
            } elseif ($okCount > 0) {
                $msg = "Partial legacy retirement: {$okCount} succeeded, {$failCount} failed. Review errors below.";
                $err = 'Some payments could not be retired (see bulk results).';
            } else {
                $err = $failCount > 0
                    ? 'Bulk legacy retirement failed for all selected payments.'
                    : 'No contractor payments found to retire.';
            }
        }
    }
}

$payments = $conn->prepare("
    SELECT cp.*, c.id AS contractor_id, c.contractor_name, p.id AS project_id, p.project_code, p.project_name,
           c.supplier_id
    FROM co_contractor_payments cp
    JOIN co_project_contractors pc ON pc.id = cp.project_contractor_id AND pc.company_id = cp.company_id
    JOIN co_contractors c ON c.id = pc.contractor_id AND c.company_id = cp.company_id
    JOIN co_projects p ON p.id = pc.project_id AND p.company_id = cp.company_id
    WHERE cp.company_id = ?
    ORDER BY cp.payment_date DESC, cp.id DESC
");
$payments->execute([$cid]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

$legacyTotal = 0.0;
$legacyJournalCount = 0;
foreach ($payments as $p) {
    $legacyTotal += (float)$p['amount'];
    if (!empty($p['journal_id'])) {
        $legacyJournalCount++;
    }
}

$logRows = [];
if (co_retirement_log_ready($conn)) {
    $ls = $conn->prepare("SELECT * FROM co_contractor_payment_retirement_log WHERE company_id = ? ORDER BY retired_at DESC LIMIT 80");
    $ls->execute([$cid]);
    $logRows = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$candidatePays = $conn->prepare("
    SELECT sp.id, sp.payment_date, sp.amount, sp.reference, s.supplier_name
    FROM co_supplier_payments sp
    JOIN co_suppliers s ON s.id = sp.supplier_id AND s.company_id = sp.company_id
    WHERE sp.company_id = ? AND sp.journal_id IS NOT NULL
    ORDER BY sp.payment_date DESC, sp.id DESC
    LIMIT 40
");
$candidatePays->execute([$cid]);
$candidatePays = $candidatePays->fetchAll(PDO::FETCH_ASSOC) ?: [];

$toolReady = co_retirement_log_ready($conn);
$legacyModeReady = co_retirement_mode_column_ready($conn);

$pageTitle = 'Retire Old Contractor Payments';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>

<div class="mb-4">
    <h1 class="h4 mb-1">Retire Old Contractor Payments</h1>
    <p class="text-muted mb-0">
        Two controlled workflows. Nothing is deleted automatically.
        Dashboards and reports recompute from live Supplier/AP queries after retirement.
    </p>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$toolReady): ?>
<div class="alert alert-warning">Run <code>migrations/construction_contractor_supplier_link.sql</code> before retiring payments.</div>
<?php endif; ?>
<?php if ($toolReady && !$legacyModeReady): ?>
<div class="alert alert-warning">Run <code>migrations/construction_contractor_payment_retirement_mode.sql</code> to enable Business Approved Legacy Retirement audit mode.</div>
<?php endif; ?>

<?php if (!empty($bulkResults)): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Bulk retirement results</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Pay#</th><th>Status</th><th>Reversal JNL</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($bulkResults as $br): ?>
                <tr class="<?= $br['ok'] ? '' : 'table-danger' ?>">
                    <td>#<?= (int)$br['id'] ?></td>
                    <td><?= $br['ok'] ? 'OK' : 'FAIL' ?></td>
                    <td><?= $br['reverse_journal_id'] ? '#' . (int)$br['reverse_journal_id'] : '—' ?></td>
                    <td class="small"><?= h($br['error'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-verified" data-bs-toggle="tab" data-bs-target="#pane-verified" type="button" role="tab">Verified Match</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-legacy" data-bs-toggle="tab" data-bs-target="#pane-legacy" type="button" role="tab">Business Approved Legacy Retirement</button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pane-verified" role="tabpanel">
        <div class="alert alert-light border small">
            <strong>Workflow A — Verified Match.</strong>
            Use when the economic event already exists correctly in Supplier/AP.
            Enter the matching Supplier Payment / Invoice reference, confirm, then retire.
        </div>
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card card-round">
                    <div class="card-header bg-white"><h6 class="mb-0">Open Contractor Payments (<?= count($payments) ?>)</h6></div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="p-4 text-muted">No contractor payment rows remain for this company.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th><th>Date</th><th>Contractor / Project</th>
                                        <th class="text-end">Amount</th><th class="text-end">Net</th><th class="text-end">Retention</th>
                                        <th>Journal</th><th style="min-width:260px">Retire (Verified)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($payments as $pay): ?>
                                    <tr>
                                        <td>#<?= (int)$pay['id'] ?></td>
                                        <td><?= h($pay['payment_date']) ?></td>
                                        <td>
                                            <a href="../contractor_view.php?id=<?= (int)$pay['contractor_id'] ?>"><?= h($pay['contractor_name']) ?></a><br>
                                            <small><a href="../project_view.php?id=<?= (int)$pay['project_id'] ?>"><?= h($pay['project_code']) ?></a></small>
                                        </td>
                                        <td class="text-end"><?= co_format_money($pay['amount']) ?></td>
                                        <td class="text-end"><?= co_format_money($pay['net_paid']) ?></td>
                                        <td class="text-end"><?= co_format_money($pay['retention_held']) ?></td>
                                        <td><?= $pay['journal_id'] ? '#' . (int)$pay['journal_id'] : '—' ?></td>
                                        <td>
                                            <form method="post" class="border rounded p-2 bg-light" onsubmit="return confirm('Retire payment #<?= (int)$pay['id'] ?> after Supplier/AP verification?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="retire_verified">
                                                <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                                <input type="text" name="verified_against_note" class="form-control form-control-sm mb-1" required placeholder="Verified vs SUP-PAY / INV …" minlength="3">
                                                <div class="form-check mb-1">
                                                    <input class="form-check-input" type="checkbox" name="confirm_retire" value="1" id="cv<?= (int)$pay['id'] ?>" required>
                                                    <label class="form-check-label small" for="cv<?= (int)$pay['id'] ?>">I verified against Supplier/AP</label>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-danger" <?= $toolReady ? '' : 'disabled' ?>>Retire</button>
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
            </div>
            <div class="col-lg-4">
                <div class="card card-round mb-3">
                    <div class="card-header bg-white"><h6 class="mb-0">Recent Supplier Payments (reference)</h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height:360px;overflow:auto">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Date</th><th>Supplier</th><th class="text-end">Amount</th></tr></thead>
                                <tbody>
                                <?php foreach ($candidatePays as $sp): ?>
                                    <tr>
                                        <td class="small"><a href="../supplier_payment_view.php?id=<?= (int)$sp['id'] ?>"><?= h($sp['payment_date']) ?></a></td>
                                        <td class="small"><?= h($sp['supplier_name']) ?></td>
                                        <td class="text-end small"><?= co_format_money($sp['amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($candidatePays)): ?>
                                    <tr><td colspan="3" class="text-muted p-3">No supplier payments found.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-legacy" role="tabpanel">
        <div class="alert alert-warning small">
            <strong>Workflow B — Business Approved Legacy Retirement.</strong>
            For organizations that have officially retired Contractor Payments and adopted Supplier/AP as the sole financial source of truth.
            <strong>Supplier/AP matching is not required.</strong>
            Each retirement reverses related posted journals via the standard <code>reverse_journal</code> process, deletes the ops row, and writes a complete audit log.
            Bank-reconciled payments remain blocked until unmatched.
        </div>

        <?php if (!empty($payments)): ?>
        <div class="card card-round mb-3 border-warning">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Bulk retire all remaining (<?= count($payments) ?>)</h6>
                <span class="badge bg-warning text-dark"><?= co_format_money($legacyTotal) ?> · <?= (int)$legacyJournalCount ?> journals</span>
            </div>
            <div class="card-body">
                <form method="post" onsubmit="return confirm('Business Approved Legacy Retirement will reverse up to <?= (int)$legacyJournalCount ?> journal(s) and delete <?= count($payments) ?> payment row(s). Continue?');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="retire_legacy_bulk">
                    <div class="mb-2">
                        <label class="form-label">Mandatory business reason *</label>
                        <textarea name="legacy_reason" class="form-control" rows="3" required minlength="15" placeholder="e.g. Board/accounting approval YYYY-MM-DD: Contractor Payment workflow retired; Supplier/AP is sole SoT. Legacy CP rows are incorrect historical entries."><?= h($_POST['legacy_reason'] ?? '') ?></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="ack_no_match_required_bulk" value="1" id="ackBulk" required>
                        <label class="form-check-label" for="ackBulk">I acknowledge Supplier/AP matching is <strong>not</strong> required for this mode</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="confirm_legacy_bulk" value="1" id="confirmBulk" required>
                        <label class="form-check-label" for="confirmBulk">I am an authorized administrator approving Legacy Retirement for this company</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type <code>RETIRE ALL LEGACY</code> to confirm *</label>
                        <input type="text" name="bulk_confirm_phrase" class="form-control" required autocomplete="off" placeholder="RETIRE ALL LEGACY">
                    </div>
                    <button type="submit" class="btn btn-warning" <?= ($toolReady && $legacyModeReady) ? '' : 'disabled' ?>>
                        Retire all remaining legacy payments
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card card-round">
            <div class="card-header bg-white"><h6 class="mb-0">Single-row Legacy Retirement</h6></div>
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                    <div class="p-4 text-muted">No contractor payment rows remain for this company.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th><th>Date</th><th>Contractor / Project</th>
                                <th class="text-end">Amount</th><th>Journal</th>
                                <th style="min-width:300px">Legacy Retire</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td>#<?= (int)$pay['id'] ?></td>
                                <td><?= h($pay['payment_date']) ?></td>
                                <td>
                                    <?= h($pay['contractor_name']) ?><br>
                                    <small><?= h($pay['project_code']) ?></small>
                                </td>
                                <td class="text-end"><?= co_format_money($pay['amount']) ?></td>
                                <td><?= $pay['journal_id'] ? '#' . (int)$pay['journal_id'] : '—' ?></td>
                                <td>
                                    <form method="post" class="border rounded p-2 bg-light" onsubmit="return confirm('Legacy-retire payment #<?= (int)$pay['id'] ?>? Posted journal will be reversed.');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="retire_legacy_one">
                                        <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                        <textarea name="legacy_reason" class="form-control form-control-sm mb-1" rows="2" required minlength="15" placeholder="Mandatory business reason…"></textarea>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" name="ack_no_match_required" value="1" id="ack<?= (int)$pay['id'] ?>" required>
                                            <label class="form-check-label small" for="ack<?= (int)$pay['id'] ?>">No Supplier/AP match required</label>
                                        </div>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" name="confirm_legacy" value="1" id="cl<?= (int)$pay['id'] ?>" required>
                                            <label class="form-check-label small" for="cl<?= (int)$pay['id'] ?>">Admin approves Legacy Retirement</label>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-warning" <?= ($toolReady && $legacyModeReady) ? '' : 'disabled' ?>>Legacy Retire</button>
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
    </div>
</div>

<?php if (!empty($logRows)): ?>
<div class="card card-round mt-3">
    <div class="card-header bg-white"><h6 class="mb-0">Retirement audit log</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:360px;overflow:auto">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th><th>Pay#</th><th>Mode</th><th class="text-end">Amount</th>
                        <th>JNL</th><th>Rev JNL</th><th>Reason / Note</th><th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logRows as $lr):
                    $mode = $lr['retirement_mode'] ?? '';
                    if ($mode === '' && str_starts_with((string)($lr['verified_against_note'] ?? ''), '[business_approved_legacy]')) {
                        $mode = CO_RETIRE_MODE_LEGACY;
                    } elseif ($mode === '') {
                        $mode = CO_RETIRE_MODE_VERIFIED;
                    }
                ?>
                    <tr>
                        <td class="small text-nowrap"><?= h($lr['retired_at']) ?></td>
                        <td class="small">#<?= (int)$lr['contractor_payment_id'] ?></td>
                        <td class="small">
                            <?php if ($mode === CO_RETIRE_MODE_LEGACY): ?>
                                <span class="badge bg-warning text-dark">Legacy</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Verified</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small"><?= co_format_money($lr['amount'] ?? 0) ?></td>
                        <td class="small"><?= !empty($lr['journal_id']) ? '#' . (int)$lr['journal_id'] : '—' ?></td>
                        <td class="small"><?= !empty($lr['reverse_journal_id']) ? '#' . (int)$lr['reverse_journal_id'] : '—' ?></td>
                        <td class="small"><?= h($lr['verified_against_note']) ?></td>
                        <td class="small"><?= (int)($lr['retired_by'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
