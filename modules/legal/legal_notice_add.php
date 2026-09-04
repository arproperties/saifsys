<?php
/**
 * Real Estate Module - Create Legal Notice
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

$caseId = !empty($_GET['case_id']) ? (int)$_GET['case_id'] : (!empty($_POST['case_id']) ? (int)$_POST['case_id'] : 0);
$case = null;
if ($caseId > 0) {
    $cs = $conn->prepare("
        SELECT c.*, t.first_name, t.last_name, t.company_name, t.tenant_type, t.email AS tenant_email, t.address AS tenant_address
        FROM re_legal_cases c LEFT JOIN re_tenants t ON t.id = c.tenant_id
        WHERE c.id = ? AND c.company_id = ? AND c.deleted_at IS NULL
    ");
    $cs->execute([$caseId, $currentCompanyId]);
    $case = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $noticeType = $_POST['notice_type'] ?? 'warning';
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $recipientAddress = trim($_POST['recipient_address'] ?? '');
    $recipientEmail = trim($_POST['recipient_email'] ?? '');
    $deliveryMethod = $_POST['delivery_method'] ?? 'email';
    $issueDate = $_POST['issue_date'] ?: null;
    $responseDeadline = $_POST['response_deadline'] ?: null;
    $reminderDays = isset($_POST['reminder_days_before']) ? max(0, (int)$_POST['reminder_days_before']) : 3;

    if (!array_key_exists($noticeType, legal_notice_types())) $noticeType = 'warning';
    if (!array_key_exists($deliveryMethod, legal_notice_delivery_methods())) $deliveryMethod = 'email';

    if ($subject === '') {
        $error = 'Subject is required.';
    } else {
        try {
            $ref = legal_generate_notice_reference($conn, $currentCompanyId);
            $tenantId = $case['tenant_id'] ?? null;
            $leaseId = $case['lease_id'] ?? null;
            $unitId = $case['unit_id'] ?? null;
            $buildingId = $case['building_id'] ?? null;

            $stmt = $conn->prepare("
                INSERT INTO re_legal_notices
                (company_id, case_id, notice_type, reference_number, subject, body, recipient_name,
                 recipient_address, recipient_email, tenant_id, lease_id, unit_id, building_id,
                 issue_date, response_deadline, deadline_date, reminder_days_before, delivery_method,
                 delivery_status, created_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'draft', ?, NOW(), NOW())
            ");
            $stmt->execute([
                $currentCompanyId, $caseId ?: null, $noticeType, $ref, $subject, $body, $recipientName ?: null,
                $recipientAddress ?: null, $recipientEmail ?: null, $tenantId, $leaseId, $unitId, $buildingId,
                $issueDate, $responseDeadline, $responseDeadline, $reminderDays, $deliveryMethod,
                current_user_id(),
            ]);
            $noticeId = (int)$conn->lastInsertId();

            if ($caseId > 0) {
                legal_log_event($conn, $caseId, 'notice_sent', 'Notice drafted: ' . $ref . ' (' . (legal_notice_types()[$noticeType] ?? $noticeType) . ')');
            }
            legal_audit('insert', 're_legal_notice', $noticeId, 'Created legal notice ' . $ref);

            $_SESSION['success'] = 'Legal notice ' . $ref . ' created.';
            header('Location: legal_notice_view.php?id=' . $noticeId);
            exit;
        } catch (Throwable $e) {
            $error = 'Error creating notice: ' . $e->getMessage();
        }
    }
}

// Default body templates
$templates = [
    'warning' => "This letter serves as a formal warning regarding [issue]. You are required to remedy this matter within the stipulated period to avoid further legal action.",
    'demand_payment' => "You are hereby formally notified that an outstanding amount of AED [amount] is due and payable. You are required to settle this amount in full within the period stated below, failing which legal proceedings will be initiated without further notice.",
    'eviction_30day' => "You are hereby served notice to vacate the leased premises within 30 days from the date of this notice, in accordance with the tenancy law and the terms of your lease agreement.",
    'eviction_12month_notarized' => "You are hereby served notarized notice to vacate the leased premises within 12 months from the date of this notice, pursuant to the applicable tenancy law.",
    'cheque_bounce_demand' => "Your cheque no. [cheque_no] dated [date] for AED [amount] has been returned unpaid. You are required to settle the said amount in cash within the period below, failing which a criminal/civil complaint will be filed.",
    'contract_termination' => "This letter is to formally notify you of the termination of the tenancy contract for the reasons stated herein, effective as per the terms of the agreement and applicable law.",
    'final_notice' => "This is a FINAL NOTICE. Despite previous communications, the matter remains unresolved. Failing immediate action, the matter will be escalated to the competent authorities/courts.",
    'other' => "",
];

$pf = function (string $k, $default = '') use ($case) {
    if (isset($_POST[$k])) return $_POST[$k];
    return $default;
};

// Prefill recipient from case tenant
$prefName = '';
$prefAddress = '';
$prefEmail = '';
if ($case) {
    $prefName = legal_tenant_name($case);
    $prefAddress = $case['tenant_address'] ?? '';
    $prefEmail = $case['tenant_email'] ?? '';
}

$pageTitle = 'New Legal Notice';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-envelope-paper"></i> New Legal Notice</h1>
            <a href="<?= $caseId ? 'legal_case_view.php?id=' . (int)$caseId : 'legal_notices.php' ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?php if ($case): ?>
            <div class="alert alert-info"><i class="bi bi-link-45deg"></i> Linked to case <strong><?= h($case['case_number']) ?></strong> - <?= h($case['title']) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">

            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Notice</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Notice Type</label>
                        <select name="notice_type" id="noticeType" class="form-select">
                            <?php foreach (legal_notice_types() as $k => $v): ?>
                                <option value="<?= h($k) ?>" <?= $pf('notice_type','warning') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Subject *</label>
                        <input type="text" name="subject" class="form-control" required value="<?= h($pf('subject')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Body</label>
                        <textarea name="body" id="noticeBody" class="form-control" rows="8"><?= h($pf('body')) ?></textarea>
                        <small class="text-muted">Selecting a notice type loads a default template (only when the body is empty).</small>
                    </div>
                </div>
            </div></div>

            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Recipient</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Recipient Name</label>
                        <input type="text" name="recipient_name" class="form-control" value="<?= h($pf('recipient_name', $prefName)) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Recipient Email</label>
                        <input type="email" name="recipient_email" class="form-control" value="<?= h($pf('recipient_email', $prefEmail)) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Recipient Address</label>
                        <textarea name="recipient_address" class="form-control" rows="2"><?= h($pf('recipient_address', $prefAddress)) ?></textarea>
                    </div>
                </div>
            </div></div>

            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Delivery & Deadline</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Delivery Method</label>
                        <select name="delivery_method" class="form-select">
                            <?php foreach (legal_notice_delivery_methods() as $k => $v): ?>
                                <option value="<?= h($k) ?>" <?= $pf('delivery_method','email') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Issue Date</label>
                        <input type="date" name="issue_date" class="form-control" value="<?= h($pf('issue_date', date('Y-m-d'))) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Response Deadline</label>
                        <input type="date" name="response_deadline" class="form-control" value="<?= h($pf('response_deadline')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Remind (days before)</label>
                        <input type="number" name="reminder_days_before" class="form-control" min="0" value="<?= h($pf('reminder_days_before','3')) ?>">
                    </div>
                </div>
            </div></div>

            <div class="d-flex gap-2 mb-5">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Create Notice</button>
                <a href="<?= $caseId ? 'legal_case_view.php?id=' . (int)$caseId : 'legal_notices.php' ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>

<?php
$templatesJson = json_encode($templates);
$pageScripts = <<<HTML
<script>
(function () {
    var templates = $templatesJson;
    var typeSel = document.getElementById('noticeType');
    var bodyEl = document.getElementById('noticeBody');
    if (!typeSel || !bodyEl) return;
    typeSel.addEventListener('change', function () {
        if (bodyEl.value.trim() === '' && templates[this.value]) {
            bodyEl.value = templates[this.value];
        }
    });
})();
</script>
HTML;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
