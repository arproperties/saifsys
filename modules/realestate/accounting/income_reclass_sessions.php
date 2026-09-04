<?php
/**
 * Income reclass repair sessions list
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
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

$sessions = [];
if (re_income_reclass_schema_ready($conn)) {
    $st = $conn->prepare("
        SELECT * FROM re_income_reclass_sessions
        WHERE company_id = ?
        ORDER BY id DESC
        LIMIT 200
    ");
    $st->execute([$companyId]);
    $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Income Repair Sessions';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="page-header-label mb-0"><i class="bi bi-journal-check"></i> Income Repair Sessions</div>
    <a href="income_mispost_report.php" class="btn btn-outline-secondary">Back to Mispost Report</a>
</div>

<?php if (!re_income_reclass_schema_ready($conn)): ?>
<div class="alert alert-warning">Repair tables not installed. Apply <code>migrations/re_income_reclass_repair.sql</code> after approval.</div>
<?php endif; ?>

<div class="card card-round">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Session</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end">Selected</th>
                    <th class="text-end">Repaired</th>
                    <th class="text-end">Failed</th>
                    <th class="text-end">Amount</th>
                    <th>Journals</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$sessions): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No sessions yet</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td><a href="income_reclass_session_view.php?id=<?= (int)$s['id'] ?>"><code><?= h($s['session_number']) ?></code></a></td>
                            <td><span class="badge bg-secondary"><?= h($s['status']) ?></span></td>
                            <td><?= h($s['created_at']) ?></td>
                            <td class="text-end"><?= (int)$s['selected_count'] ?></td>
                            <td class="text-end"><?= (int)$s['repaired_count'] ?></td>
                            <td class="text-end"><?= (int)$s['failed_count'] ?></td>
                            <td class="text-end"><?= money_fmt($s['total_amount']) ?></td>
                            <td class="small">
                                <?php if ($s['repair_journal_id_min']): ?>
                                    #<?= (int)$s['repair_journal_id_min'] ?>–<?= (int)$s['repair_journal_id_max'] ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
