<?php
/**
 * Real Estate Module - Legal Notice View / Edit
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$isOwnerAdmin = has_role('Owner', $conn) || has_role('Admin', $conn);
$noticeId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

function legal_load_notice(PDO $conn, int $id, int $companyId) {
    $stmt = $conn->prepare("
        SELECT n.*, c.case_number,
               COALESCE(NULLIF(usr.fullname,''), usr.username) AS created_name
        FROM re_legal_notices n
        LEFT JOIN re_legal_cases c ON c.id = n.case_id
        LEFT JOIN user usr ON usr.id = n.created_by
        WHERE n.id = ? AND n.company_id = ?
    ");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$notice = legal_load_notice($conn, $noticeId, $currentCompanyId);
if (!$notice) {
    $_SESSION['error'] = 'Legal notice not found.';
    header('Location: legal_notices.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $noticeType = $_POST['notice_type'] ?? $notice['notice_type'];
        $recipientName = trim($_POST['recipient_name'] ?? '');
        $recipientAddress = trim($_POST['recipient_address'] ?? '');
        $recipientEmail = trim($_POST['recipient_email'] ?? '');
        $deliveryMethod = $_POST['delivery_method'] ?? $notice['delivery_method'];
        $deliveryStatus = $_POST['delivery_status'] ?? $notice['delivery_status'];
        $issueDate = $_POST['issue_date'] ?: null;
        $responseDeadline = $_POST['response_deadline'] ?: null;
        $reminderDays = isset($_POST['reminder_days_before']) ? max(0, (int)$_POST['reminder_days_before']) : 3;

        if (!array_key_exists($noticeType, legal_notice_types())) $noticeType = 'warning';
        if (!array_key_exists($deliveryMethod, legal_notice_delivery_methods())) $deliveryMethod = 'email';
        if (!array_key_exists($deliveryStatus, legal_delivery_statuses())) $deliveryStatus = 'draft';

        if ($subject === '') {
            $error = 'Subject is required.';
        } else {
            $deliveredAt = $notice['delivered_at'];
            if (in_array($deliveryStatus, ['sent','delivered','acknowledged'], true) && empty($deliveredAt)) {
                $deliveredAt = date('Y-m-d H:i:s');
            }
            $conn->prepare("
                UPDATE re_legal_notices SET
                    notice_type = ?, subject = ?, body = ?, recipient_name = ?, recipient_address = ?, recipient_email = ?,
                    delivery_method = ?, delivery_status = ?, delivered_at = ?, issue_date = ?, response_deadline = ?,
                    deadline_date = ?, reminder_days_before = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([
                $noticeType, $subject, $body, $recipientName ?: null, $recipientAddress ?: null, $recipientEmail ?: null,
                $deliveryMethod, $deliveryStatus, $deliveredAt, $issueDate, $responseDeadline,
                $responseDeadline, $reminderDays, $noticeId, $currentCompanyId,
            ]);
            if ($deliveryStatus !== $notice['delivery_status'] && $notice['case_id']) {
                legal_log_event($conn, (int)$notice['case_id'], 'notice_sent', 'Notice ' . $notice['reference_number'] . ' status: ' . $deliveryStatus);
            }
            legal_audit('update', 're_legal_notice', $noticeId, 'Updated legal notice ' . $notice['reference_number']);
            $_SESSION['success'] = 'Notice updated.';
            header('Location: legal_notice_view.php?id=' . $noticeId);
            exit;
        }
    }

    if ($action === 'delete' && $isOwnerAdmin) {
        $conn->prepare("DELETE FROM re_legal_notices WHERE id = ? AND company_id = ?")->execute([$noticeId, $currentCompanyId]);
        legal_audit('delete', 're_legal_notice', $noticeId, 'Deleted legal notice ' . $notice['reference_number']);
        $_SESSION['success'] = 'Notice deleted.';
        header('Location: legal_notices.php');
        exit;
    }
}

$notice = legal_load_notice($conn, $noticeId, $currentCompanyId);

$pageTitle = 'Notice ' . $notice['reference_number'];
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h1 class="mb-1"><i class="bi bi-envelope-paper"></i> <?= h($notice['reference_number']) ?></h1>
                <div><span class="badge bg-light text-dark border"><?= h(legal_notice_types()[$notice['notice_type']] ?? $notice['notice_type']) ?></span>
                     <span class="badge bg-secondary"><?= h(legal_delivery_statuses()[$notice['delivery_status']] ?? $notice['delivery_status']) ?></span>
                     <?php if ($notice['case_number']): ?><a href="legal_case_view.php?id=<?= (int)$notice['case_id'] ?>" class="badge bg-info text-decoration-none">Case <?= h($notice['case_number']) ?></a><?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="legal_notice_pdf.php?id=<?= (int)$noticeId ?>&mode=view" target="_blank" rel="noopener" class="btn btn-outline-info"><i class="bi bi-file-earmark-pdf"></i> View PDF</a>
                <a href="legal_notice_pdf.php?id=<?= (int)$noticeId ?>&mode=download" class="btn btn-outline-primary"><i class="bi bi-download"></i> Download PDF</a>
                <a href="legal_notices.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?= h($_SESSION['success']) ?></div><?php unset($_SESSION['success']); endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Notice Content</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Notice Type</label>
                        <select name="notice_type" class="form-select">
                            <?php foreach (legal_notice_types() as $k => $v): ?>
                                <option value="<?= h($k) ?>" <?= $notice['notice_type'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Subject *</label>
                        <input type="text" name="subject" class="form-control" required value="<?= h($notice['subject']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Body</label>
                        <textarea name="body" class="form-control" rows="8"><?= h($notice['body']) ?></textarea>
                    </div>
                </div>
            </div></div>

            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Recipient</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Recipient Name</label><input type="text" name="recipient_name" class="form-control" value="<?= h($notice['recipient_name']) ?>"></div>
                    <div class="col-md-6"><label class="form-label">Recipient Email</label><input type="email" name="recipient_email" class="form-control" value="<?= h($notice['recipient_email']) ?>"></div>
                    <div class="col-12"><label class="form-label">Recipient Address</label><textarea name="recipient_address" class="form-control" rows="2"><?= h($notice['recipient_address']) ?></textarea></div>
                </div>
            </div></div>

            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Delivery & Deadline</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">Delivery Method</label>
                        <select name="delivery_method" class="form-select">
                            <?php foreach (legal_notice_delivery_methods() as $k => $v): ?>
                                <option value="<?= h($k) ?>" <?= $notice['delivery_method'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Delivery Status</label>
                        <select name="delivery_status" class="form-select">
                            <?php foreach (legal_delivery_statuses() as $k => $v): ?>
                                <option value="<?= h($k) ?>" <?= $notice['delivery_status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label">Issue Date</label><input type="date" name="issue_date" class="form-control" value="<?= h($notice['issue_date']) ?>"></div>
                    <div class="col-md-2"><label class="form-label">Response Deadline</label><input type="date" name="response_deadline" class="form-control" value="<?= h($notice['response_deadline']) ?>"></div>
                    <div class="col-md-2"><label class="form-label">Remind (days)</label><input type="number" name="reminder_days_before" class="form-control" min="0" value="<?= h($notice['reminder_days_before']) ?>"></div>
                </div>
                <?php if ($notice['delivered_at']): ?><small class="text-muted d-block mt-2">First marked delivered/sent: <?= h(date('M d, Y H:i', strtotime($notice['delivered_at']))) ?></small><?php endif; ?>
            </div></div>

            <div class="d-flex gap-2 mb-5">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save</button>
                <?php if ($isOwnerAdmin): ?>
                <button type="submit" formnovalidate name="action" value="delete" class="btn btn-outline-danger" onclick="return confirm('Delete this notice?');"><i class="bi bi-trash"></i> Delete</button>
                <?php endif; ?>
            </div>
        </form>

<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
