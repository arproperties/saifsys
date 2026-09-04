<?php
/**
 * Real Estate Module - Legal Notices (list)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$typeFilter = $_GET['notice_type'] ?? 'all';
$statusFilter = $_GET['delivery_status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = ["n.company_id = ?"];
$params = [$currentCompanyId];
if ($typeFilter !== 'all' && array_key_exists($typeFilter, legal_notice_types())) {
    $where[] = "n.notice_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter !== 'all' && array_key_exists($statusFilter, legal_delivery_statuses())) {
    $where[] = "n.delivery_status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(n.reference_number LIKE ? OR n.subject LIKE ? OR n.recipient_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$stmt = $conn->prepare("
    SELECT n.*, c.case_number
    FROM re_legal_notices n
    LEFT JOIN re_legal_cases c ON c.id = n.case_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY n.created_at DESC
");
$stmt->execute($params);
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Legal Notices';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-envelope-paper"></i> Legal Notices</h1>
            <div class="d-flex gap-2">
                <a href="legal_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="legal_notice_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Notice</a>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <div class="card mb-4"><div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Reference, subject, recipient">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="notice_type" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <?php foreach (legal_notice_types() as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="delivery_status" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <?php foreach (legal_delivery_statuses() as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button>
                    <a href="legal_notices.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div></div>

        <div class="card"><div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr>
                        <th>Reference</th><th>Type</th><th>Subject</th><th>Recipient</th><th>Case</th>
                        <th>Issue</th><th>Deadline</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($notices)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No legal notices found.</td></tr>
                        <?php else: foreach ($notices as $n): ?>
                            <tr>
                                <td><strong><?= h($n['reference_number']) ?></strong></td>
                                <td><small><?= h(legal_notice_types()[$n['notice_type']] ?? $n['notice_type']) ?></small></td>
                                <td><?= h($n['subject']) ?></td>
                                <td><small><?= h($n['recipient_name'] ?: '-') ?></small></td>
                                <td><?= $n['case_number'] ? '<a href="legal_case_view.php?id=' . (int)$n['case_id'] . '">' . h($n['case_number']) . '</a>' : '<span class="text-muted">-</span>' ?></td>
                                <td><small><?= $n['issue_date'] ? h(date('M d, Y', strtotime($n['issue_date']))) : '-' ?></small></td>
                                <td><small><?= $n['response_deadline'] ? h(date('M d, Y', strtotime($n['response_deadline']))) : '-' ?></small></td>
                                <td><span class="badge bg-secondary"><?= h(legal_delivery_statuses()[$n['delivery_status']] ?? $n['delivery_status']) ?></span></td>
                                <td class="text-nowrap">
                                    <a href="legal_notice_view.php?id=<?= (int)$n['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-eye"></i></a>
                                    <a href="legal_notice_pdf.php?id=<?= (int)$n['id'] ?>&mode=view" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info"><i class="bi bi-file-earmark-pdf"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div></div>

<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
