<?php
/**
 * Real Estate Accounting - Recurring Journals
 * Define and generate recurring journal entries
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/accounting_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'generate' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("SELECT * FROM re_recurring_journals WHERE id = ? AND company_id = ? AND is_active = 1");
        $stmt->execute([$id, $currentCompanyId]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rec) {
            $lines = json_decode($rec['lines_json'], true);
            if (is_array($lines) && count($lines) >= 2) {
                $journalLines = [];
                foreach ($lines as $l) {
                    $journalLines[] = [
                        'account_id' => (int)$l['account_id'],
                        'debit' => (float)($l['debit'] ?? 0),
                        'credit' => (float)($l['credit'] ?? 0),
                        'description' => $l['description'] ?? '',
                        'reference' => ''
                    ];
                }
                $journalDate = $rec['next_run_date'];
                $result = create_and_post_journal(
                    $currentCompanyId,
                    'recurring',
                    'recurring_journal',
                    $id,
                    $journalLines,
                    $rec['description'] ?: $rec['name'],
                    $journalDate,
                    $userId
                );
                if ($result['success']) {
                    $next = date('Y-m-d', strtotime($rec['frequency'] === 'monthly' ? '+1 month' : '+1 week', strtotime($journalDate)));
                    $conn->prepare("UPDATE re_recurring_journals SET next_run_date = ?, last_generated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$next, $id, $currentCompanyId]);
                    $success = 'Journal ' . $result['journal_number'] . ' created and posted for ' . $journalDate . '. Next run: ' . $next;
                } else {
                    $error = $result['error'];
                }
            } else {
                $error = 'Invalid or empty lines.';
            }
        } else {
            $error = 'Recurring journal not found or inactive.';
        }
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $stmt = $conn->prepare("DELETE FROM re_recurring_journals WHERE id = ? AND company_id = ?");
        $stmt->execute([(int)$_POST['id'], $currentCompanyId]);
        $success = 'Recurring journal deleted.';
    }
}

$stmt = $conn->prepare("SELECT * FROM re_recurring_journals WHERE company_id = ? ORDER BY next_run_date ASC");
$stmt->execute([$currentCompanyId]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Recurring Journals';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= h($success) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= h($error) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-arrow-repeat"></i> Recurring Journals</div>
    <a href="recurring_journal_add.php" class="btn btn-primary"><i class="bi bi-plus"></i> Add Recurring</a>
</div>

<div class="card card-round">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Frequency</th>
                        <th>Next Run</th>
                        <th>Last Generated</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No recurring journals. <a href="recurring_journal_add.php">Add one</a>.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $r): ?>
                            <tr>
                                <td><strong><?= h($r['name']) ?></strong></td>
                                <td><?= h($r['frequency']) ?></td>
                                <td><?= date('Y-m-d', strtotime($r['next_run_date'])) ?></td>
                                <td><?= $r['last_generated_at'] ? date('Y-m-d H:i', strtotime($r['last_generated_at'])) : '-' ?></td>
                                <td><?= $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                <td>
                                    <?php if ($r['is_active']): ?>
                                        <form method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="generate">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">Generate Now</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="recurring_journal_add.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this recurring journal?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
