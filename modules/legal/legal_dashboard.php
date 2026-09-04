<?php
/**
 * Real Estate Module - Legal Dashboard
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

// Stats
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS open_cases,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
        SUM(CASE WHEN deadline_date IS NOT NULL AND deadline_date < CURDATE() AND status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN deadline_date IS NOT NULL AND deadline_date >= CURDATE() AND deadline_date <= DATE_ADD(CURDATE(), INTERVAL reminder_days_before DAY) AND status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS due_soon,
        COALESCE(SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN claim_amount ELSE 0 END),0) AS open_claim,
        COALESCE(SUM(recovered_amount),0) AS recovered
    FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL
");
$statsStmt->execute([$currentCompanyId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Status breakdown
$breakdown = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL GROUP BY status");
$breakdown->execute([$currentCompanyId]);
$breakdown = $breakdown->fetchAll(PDO::FETCH_KEY_PAIR);

// Overdue cases
$overdueStmt = $conn->prepare("
    SELECT c.id, c.case_number, c.title, c.status, c.priority, c.deadline_date,
           COALESCE(NULLIF(usr.fullname,''), usr.username) AS assigned_name
    FROM re_legal_cases c LEFT JOIN user usr ON usr.id = c.assigned_to
    WHERE c.company_id = ? AND c.deleted_at IS NULL AND c.deadline_date IS NOT NULL
      AND c.deadline_date < CURDATE() AND c.status NOT IN ('settled','closed','withdrawn')
    ORDER BY c.deadline_date ASC LIMIT 10
");
$overdueStmt->execute([$currentCompanyId]);
$overdueCases = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

// Due soon cases
$dueSoonStmt = $conn->prepare("
    SELECT c.id, c.case_number, c.title, c.status, c.priority, c.deadline_date,
           COALESCE(NULLIF(usr.fullname,''), usr.username) AS assigned_name
    FROM re_legal_cases c LEFT JOIN user usr ON usr.id = c.assigned_to
    WHERE c.company_id = ? AND c.deleted_at IS NULL AND c.deadline_date IS NOT NULL
      AND c.deadline_date >= CURDATE() AND c.deadline_date <= DATE_ADD(CURDATE(), INTERVAL c.reminder_days_before DAY)
      AND c.status NOT IN ('settled','closed','withdrawn')
    ORDER BY c.deadline_date ASC LIMIT 10
");
$dueSoonStmt->execute([$currentCompanyId]);
$dueSoonCases = $dueSoonStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent cases
$recentStmt = $conn->prepare("
    SELECT c.id, c.case_number, c.title, c.status, c.case_type, c.created_at
    FROM re_legal_cases c WHERE c.company_id = ? AND c.deleted_at IS NULL
    ORDER BY c.created_at DESC LIMIT 8
");
$recentStmt->execute([$currentCompanyId]);
$recentCases = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Upcoming notice deadlines
$noticeStmt = $conn->prepare("
    SELECT n.id, n.reference_number, n.notice_type, n.response_deadline, n.delivery_status, n.case_id
    FROM re_legal_notices n
    WHERE n.company_id = ? AND n.response_deadline IS NOT NULL
      AND n.response_deadline >= CURDATE() AND n.delivery_status NOT IN ('acknowledged')
    ORDER BY n.response_deadline ASC LIMIT 8
");
$noticeStmt->execute([$currentCompanyId]);
$upcomingNotices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Legal Dashboard';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-bank"></i> Legal Dashboard</h1>
            <div class="d-flex gap-2">
                <a href="legal_cheque_notifications.php" class="btn btn-outline-danger"><i class="bi bi-bell"></i> Cheque Notifications</a>
                <a href="legal_cases.php" class="btn btn-outline-secondary"><i class="bi bi-briefcase"></i> All Cases</a>
                <a href="legal_billing_cheques.php" class="btn btn-outline-secondary"><i class="bi bi-bank"></i> PDC Cheques</a>
                <a href="legal_documents.php" class="btn btn-outline-secondary"><i class="bi bi-folder2-open"></i> Documents</a>
                <a href="legal_notices.php" class="btn btn-outline-secondary"><i class="bi bi-envelope-paper"></i> Notices</a>
                <a href="legal_case_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Case</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6"><div class="card text-center border-primary h-100"><div class="card-body">
                <i class="bi bi-folder2-open fs-3 text-primary"></i>
                <h2 class="mb-0"><?= (int)($stats['open_cases'] ?? 0) ?></h2><small class="text-muted">Open Cases</small>
            </div></div></div>
            <a class="col-md-3 col-6 text-decoration-none" href="legal_cases.php?deadline=overdue"><div class="card text-center border-danger h-100"><div class="card-body">
                <i class="bi bi-exclamation-triangle fs-3 text-danger"></i>
                <h2 class="mb-0 text-danger"><?= (int)($stats['overdue'] ?? 0) ?></h2><small class="text-muted">Overdue Actions</small>
            </div></div></a>
            <a class="col-md-3 col-6 text-decoration-none" href="legal_cases.php?deadline=due_soon"><div class="card text-center border-warning h-100"><div class="card-body">
                <i class="bi bi-clock fs-3 text-warning"></i>
                <h2 class="mb-0 text-warning"><?= (int)($stats['due_soon'] ?? 0) ?></h2><small class="text-muted">Due Soon</small>
            </div></div></a>
            <div class="col-md-3 col-6"><div class="card text-center border-success h-100"><div class="card-body">
                <i class="bi bi-cash-coin fs-3 text-success"></i>
                <h4 class="mb-0 text-success"><?= number_format((float)($stats['recovered'] ?? 0), 0) ?></h4><small class="text-muted">Recovered (AED)</small>
            </div></div></div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6"><div class="card text-center h-100"><div class="card-body">
                <h3 class="mb-0"><?= (int)($stats['total'] ?? 0) ?></h3><small class="text-muted">Total Cases</small>
            </div></div></div>
            <div class="col-md-3 col-6"><div class="card text-center h-100"><div class="card-body">
                <h3 class="mb-0"><?= (int)($stats['drafts'] ?? 0) ?></h3><small class="text-muted">Drafts</small>
            </div></div></div>
            <div class="col-md-6"><div class="card h-100"><div class="card-body">
                <small class="text-muted">Open Claim Exposure</small>
                <h3 class="mb-0"><?= number_format((float)($stats['open_claim'] ?? 0), 2) ?> AED</h3>
            </div></div></div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header bg-danger text-white"><h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Overdue Legal Actions</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($overdueCases)): ?>
                        <p class="text-muted p-3 mb-0">No overdue actions. </p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                            <thead><tr><th>Case</th><th>Deadline</th><th>Assigned</th></tr></thead>
                            <tbody>
                            <?php foreach ($overdueCases as $c): ?>
                                <tr>
                                    <td><a href="legal_case_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['case_number']) ?></a><br><small class="text-muted"><?= h($c['title']) ?></small></td>
                                    <td><span class="text-danger fw-bold"><?= h(date('M d, Y', strtotime($c['deadline_date']))) ?></span></td>
                                    <td><small><?= h($c['assigned_name'] ?: '-') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header bg-warning"><h5 class="mb-0"><i class="bi bi-clock"></i> Due Soon</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($dueSoonCases)): ?>
                        <p class="text-muted p-3 mb-0">Nothing due soon.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                            <thead><tr><th>Case</th><th>Deadline</th><th>Assigned</th></tr></thead>
                            <tbody>
                            <?php foreach ($dueSoonCases as $c): ?>
                                <tr>
                                    <td><a href="legal_case_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['case_number']) ?></a><br><small class="text-muted"><?= h($c['title']) ?></small></td>
                                    <td><span class="text-warning fw-bold"><?= h(date('M d, Y', strtotime($c['deadline_date']))) ?></span></td>
                                    <td><small><?= h($c['assigned_name'] ?: '-') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div></div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Cases</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($recentCases)): ?>
                        <p class="text-muted p-3 mb-0">No cases yet.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                            <tbody>
                            <?php foreach ($recentCases as $c): ?>
                                <tr>
                                    <td><a href="legal_case_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['case_number']) ?></a> <small class="text-muted"><?= h($c['title']) ?></small></td>
                                    <td><span class="badge bg-<?= legal_status_color($c['status']) ?>"><?= h(legal_case_statuses()[$c['status']] ?? $c['status']) ?></span></td>
                                    <td class="text-end"><small class="text-muted"><?= h(date('M d', strtotime($c['created_at']))) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header"><h5 class="mb-0"><i class="bi bi-envelope-paper"></i> Upcoming Notice Deadlines</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($upcomingNotices)): ?>
                        <p class="text-muted p-3 mb-0">No upcoming notice deadlines.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                            <tbody>
                            <?php foreach ($upcomingNotices as $n): ?>
                                <tr>
                                    <td><a href="legal_notice_view.php?id=<?= (int)$n['id'] ?>"><?= h($n['reference_number']) ?></a><br><small class="text-muted"><?= h(legal_notice_types()[$n['notice_type']] ?? $n['notice_type']) ?></small></td>
                                    <td><?= h(date('M d, Y', strtotime($n['response_deadline']))) ?></td>
                                    <td><span class="badge bg-secondary"><?= h(legal_delivery_statuses()[$n['delivery_status']] ?? $n['delivery_status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div></div>
            </div>
        </div>

<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
