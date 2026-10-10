<?php
/**
 * Bulk Email — final recipient list and a sample, then confirm.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';
require_once __DIR__ . '/includes/ars_bulk_email.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
ars_bulk_email_ensure_schema($conn);

$draft = $_SESSION['ars_bulk_email_preview'] ?? null;
if (!is_array($draft) || (int)($draft['company_id'] ?? 0) !== $arsCompanyId) {
    header('Location: bulk_email.php');
    exit;
}
$files = is_array($draft['attachments'] ?? null) ? $draft['attachments'] : [];

// The list is read again from the database on every load and on confirm.
$validated = ars_bulk_email_validate_selection($conn, $arsCompanyId, (array)$draft['guest_ids'], (array)$draft['filters']);
$recipients = !empty($validated['ok']) ? $validated['recipients'] : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'cancel') {
        ars_bulk_email_delete_files($arsCompanyId, $files);
        unset($_SESSION['ars_bulk_email_preview']);
        header('Location: bulk_email.php');
        exit;
    }
    if ($action === 'confirm') {
        if (strcasecmp(trim((string)($_POST['confirm_text'] ?? '')), 'SEND') !== 0) {
            $error = 'Type SEND to confirm.';
        } elseif (!$recipients) {
            $error = $validated['error'] ?? 'No recipients.';
        } else {
            $created = ars_bulk_email_create_campaign(
                $conn,
                $arsCompanyId,
                (int)(current_user_id() ?: 0),
                (string)$draft['subject'],
                (string)$draft['body_text'],
                $recipients,
                (int)$validated['selected_count'],
                (int)$validated['excluded_count'],
                (array)$draft['filters'],
                $files
            );
            if (empty($created['ok'])) {
                $error = $created['error'] ?? 'Could not save the email.';
            } else {
                unset($_SESSION['ars_bulk_email_preview']);
                header('Location: bulk_email_view.php?id=' . (int)$created['campaign_id'] . '&autostart=1');
                exit;
            }
        }
    }
}

$sample = $recipients[0] ?? [];
$pageTitle = 'Preview Bulk Email';
ars_shell_begin([
    'title' => 'Preview',
    'subtitle' => count($recipients) . ' email' . (count($recipients) === 1 ? '' : 's') . ' ready to send',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Bulk Email', 'href' => 'bulk_email.php'],
        ['label' => 'Preview'],
    ],
    'actions_html' => ars_ui_button('Edit', [
        'href' => 'bulk_email.php?edit=1',
        'variant' => 'secondary',
        'size' => 'sm',
        'icon' => 'pencil',
    ]),
    'legacy_bootstrap' => true,
]);
?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$recipients): ?>
<div class="alert alert-warning"><?= h($validated['error'] ?? 'The selected guests are no longer in this list. Go back and select again.') ?></div>
<?php else: ?>
<div class="row g-3 align-items-start">
    <div class="col-lg-6">
        <div class="ars-card">
            <div class="p-3 border-bottom fw-semibold">
                Recipients <span class="text-muted fw-normal">· <?= count($recipients) ?></span>
                <?php if ((int)$validated['excluded_count'] > 0): ?>
                <div class="small text-muted fw-normal"><?= (int)$validated['excluded_count'] ?> selected guest(s) left out: same email as another guest, or no longer in this list.</div>
                <?php endif; ?>
            </div>
            <div class="table-responsive" style="max-height:60vh;overflow:auto">
                <table class="table ars-table mb-0 align-middle">
                    <tbody>
                    <?php foreach ($recipients as $r): ?>
                        <tr>
                            <td><?= h($r['guest_name']) ?></td>
                            <td><?= h($r['unit_number'] !== '' ? $r['unit_number'] : '—') ?></td>
                            <td class="small text-muted"><?= h($r['email']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="ars-card p-3 mb-3">
            <div class="small text-muted mb-2">As <?= h($sample['guest_name']) ?> will receive it</div>
            <div class="fw-semibold mb-2"><?= h(ars_bulk_email_render_subject((string)$draft['subject'], $sample)) ?></div>
            <div class="border rounded p-3 bg-light"><?= ars_bulk_email_render_body((string)$draft['body_text'], $sample) ?></div>
            <?php foreach ($files as $f): ?>
            <div class="small text-muted mt-2"><i class="bi bi-paperclip"></i> <?= h($f['name']) ?> · <?= number_format($f['size'] / 1024) ?> KB</div>
            <?php endforeach; ?>
        </div>
        <div class="ars-card p-3">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="confirm">
                <label class="form-label fw-semibold" for="beConfirm">Type SEND to email <?= count($recipients) ?> guest<?= count($recipients) === 1 ? '' : 's' ?></label>
                <input type="text" name="confirm_text" id="beConfirm" class="form-control mb-3" autocomplete="off" autocapitalize="characters" required>
                <button type="submit" class="btn btn-ars w-100 mb-2"><i class="bi bi-send me-1"></i>Send now</button>
            </form>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-outline-secondary w-100">Cancel</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php ars_shell_end(); ?>
