<?php
/**
 * Bulk Tenant Email — preview unique recipients and confirm send.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/bulk_tenant_email_helper.php';

$companyId = re_bulk_email_require_access($conn);
$userId = (int)(current_user_id() ?: 0);
$brand = getBrandSettings($conn);
$reLayoutFluid = true;

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$preview = $_SESSION['re_bulk_email_preview'] ?? null;
if (!is_array($preview) || (int)($preview['company_id'] ?? 0) !== $companyId) {
    header('Location: bulk_tenant_email.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'cancel') {
        unset($_SESSION['re_bulk_email_preview']);
        header('Location: bulk_tenant_email.php');
        exit;
    }
    if ($action === 'confirm') {
        $confirm = trim((string)($_POST['confirm_text'] ?? ''));
        if (strcasecmp($confirm, 'SEND') !== 0) {
            $error = 'Type SEND to confirm.';
        } else {
            $leaseIds = array_map('intval', $preview['lease_ids'] ?? []);
            $filters = is_array($preview['filters'] ?? null) ? $preview['filters'] : [];
            // Server-side revalidation — do not trust session snapshot fields for identity.
            $validated = re_bulk_email_validate_selected_leases($conn, $companyId, $leaseIds, $filters);
            if (empty($validated['ok'])) {
                $error = $validated['error'] ?? 'Recipient validation failed.';
            } else {
                $created = re_bulk_email_create_campaign(
                    $conn,
                    $companyId,
                    $userId,
                    (string)($preview['subject'] ?? ''),
                    (string)($preview['body_html'] ?? ''),
                    $validated['recipients'],
                    (int)$validated['selected_count'],
                    (int)$validated['excluded_count'],
                    $filters,
                    is_array($preview['attachment'] ?? null) ? $preview['attachment'] : null
                );
                if (empty($created['ok'])) {
                    $error = $created['error'] ?? 'Could not create campaign.';
                } else {
                    unset($_SESSION['re_bulk_email_preview']);
                    header('Location: bulk_tenant_email_view.php?id=' . (int)$created['campaign_id'] . '&autostart=1');
                    exit;
                }
            }
        }
    }
}

// Fresh unique list for display (from DB)
$validated = re_bulk_email_validate_selected_leases(
    $conn,
    $companyId,
    array_map('intval', $preview['lease_ids'] ?? []),
    is_array($preview['filters'] ?? null) ? $preview['filters'] : []
);
$recipients = !empty($validated['ok']) ? ($validated['recipients'] ?? []) : [];
$sample = $recipients[0] ?? [
    'tenant_name' => 'Sample Tenant',
    'building_name' => 'Sample Building',
    'unit_number' => '00',
];
$sampleSubject = re_bulk_email_apply_placeholders((string)$preview['subject'], $sample);
$sampleBody = re_bulk_email_apply_placeholders(re_bulk_email_sanitize_html((string)$preview['body_html']), $sample);

$pageTitle = 'Preview Bulk Email';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="mb-3">
    <a href="bulk_tenant_email.php" class="btn btn-sm btn-outline-secondary">&larr; Back to compose</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if (empty($validated['ok'])): ?>
    <div class="alert alert-warning"><?= h($validated['error'] ?? 'Recipients no longer valid. Go back and reselect.') ?></div>
<?php else: ?>

<div class="alert alert-info">
    <strong><?= (int)count($recipients) ?></strong> unique email recipient(s)
    from <strong><?= (int)$validated['selected_count'] ?></strong> selected lease(s).
    <?php if ((int)$validated['excluded_count'] > 0): ?>
        <span class="text-muted">(<?= (int)$validated['excluded_count'] ?> selected without valid email were dropped.)</span>
    <?php endif; ?>
    Each address will receive a separate email (no shared To/Cc/Bcc).
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card card-round mb-3">
            <div class="card-header">Unique recipients</div>
            <div class="table-responsive" style="max-height:420px;overflow:auto;">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Tenant</th><th>Building / Unit</th><th>Email</th></tr></thead>
                    <tbody>
                    <?php foreach ($recipients as $r): ?>
                        <tr>
                            <td><?= h($r['tenant_name']) ?></td>
                            <td><?= h($r['building_name']) ?> / <?= h($r['unit_number']) ?></td>
                            <td><?= h($r['email']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-round mb-3">
            <div class="card-header">Sample message (first recipient placeholders)</div>
            <div class="card-body">
                <div class="mb-2"><strong>Subject:</strong> <?= h($sampleSubject) ?></div>
                <?php if (!empty($preview['attachment']['name'])): ?>
                    <div class="mb-2 small text-muted"><i class="bi bi-paperclip"></i> <?= h($preview['attachment']['name']) ?></div>
                <?php endif; ?>
                <div class="border rounded p-3 bg-light"><?= $sampleBody ?></div>
            </div>
        </div>
        <div class="card card-round">
            <div class="card-body">
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="confirm">
                    <label class="form-label">Type <strong>SEND</strong> to confirm</label>
                    <input type="text" name="confirm_text" class="form-control mb-3" autocomplete="off" required>
                    <button type="submit" class="btn btn-danger w-100 mb-2">Confirm and queue send</button>
                </form>
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-outline-secondary w-100">Cancel</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
