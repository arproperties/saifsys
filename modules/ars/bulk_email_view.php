<?php
/**
 * Bulk Email — the list of sent emails, or one email with its progress and
 * per-guest result (?id=). Sending runs from this page in small batches.
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

$campaignId = (int)($_GET['id'] ?? 0);
$newButton = ars_ui_button('New email', ['href' => 'bulk_email.php', 'size' => 'sm', 'icon' => 'plus']);

// ---- List of sent emails -------------------------------------------------
if ($campaignId <= 0) {
    $st = $conn->prepare("
        SELECT c.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS sender_name
        FROM ars_bulk_email_campaigns c
        LEFT JOIN user u ON u.id = c.created_by
        WHERE c.company_id = ?
        ORDER BY c.id DESC
        LIMIT 200
    ");
    $st->execute([$arsCompanyId]);
    $campaigns = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pageTitle = 'Sent Bulk Emails';
    ars_shell_begin([
        'title' => 'Sent emails',
        'breadcrumbs' => [
            ['label' => 'ARS', 'href' => 'index.php'],
            ['label' => 'Bulk Email', 'href' => 'bulk_email.php'],
            ['label' => 'Sent'],
        ],
        'actions_html' => $newButton,
        'legacy_bootstrap' => true,
    ]);
    ?>
    <div class="ars-card">
        <?php if (!$campaigns): ?>
        <div class="p-4"><?= ars_ui_empty_state('No bulk emails yet', 'Emails you send to guests are listed here.') ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table ars-table ars-mobile-cards mb-0 align-middle">
                <thead>
                    <tr><th>Date</th><th>Subject</th><th>Sent by</th><th class="text-center">Sent</th><th class="text-center">Failed</th><th class="text-center">Pending</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                    <tr class="ars-row-click" style="cursor:pointer" onclick="location.href='bulk_email_view.php?id=<?= (int)$c['id'] ?>'">
                        <td data-label="Date" class="small text-nowrap"><?= h(date('d M Y H:i', strtotime((string)$c['created_at']))) ?></td>
                        <td data-label="Subject" class="fw-semibold"><?= h($c['subject']) ?></td>
                        <td data-label="Sent by" class="small"><?= h($c['sender_name'] ?? '—') ?></td>
                        <td data-label="Sent" class="text-center"><?= (int)$c['sent_count'] ?></td>
                        <td data-label="Failed" class="text-center <?= (int)$c['failed_count'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int)$c['failed_count'] ?></td>
                        <td data-label="Pending" class="text-center <?= (int)$c['pending_count'] > 0 ? 'text-warning fw-semibold' : '' ?>"><?= (int)$c['pending_count'] ?></td>
                        <td data-label="Status"><?= h(ars_bulk_email_status_label((string)$c['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
    ars_shell_end();
    exit;
}

// ---- One email -----------------------------------------------------------
$campaign = ars_bulk_email_get_campaign($conn, $arsCompanyId, $campaignId);
if (!$campaign) {
    header('Location: bulk_email_view.php');
    exit;
}
$autostart = !empty($_GET['autostart']);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'retry_failed') {
        $n = ars_bulk_email_retry_failed($conn, $arsCompanyId, $campaignId);
        $message = $n > 0
            ? 'Trying ' . $n . ' failed email(s) again. Emails already sent are not sent twice.'
            : 'Nothing to retry.';
        $autostart = $n > 0;
        $campaign = ars_bulk_email_get_campaign($conn, $arsCompanyId, $campaignId);
    }
}

$st = $conn->prepare("
    SELECT * FROM ars_bulk_email_recipients
    WHERE campaign_id = ? AND company_id = ?
    ORDER BY FIELD(status,'failed','pending','sending','sent'), guest_name
");
$st->execute([$campaignId, $arsCompanyId]);
$recipients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pending = (int)$campaign['pending_count'];
$failed = (int)$campaign['failed_count'];
$sample = $recipients[0] ?? [];
$badge = ['sent' => 'bg-success', 'failed' => 'bg-danger', 'pending' => 'bg-secondary', 'sending' => 'bg-secondary'];

$pageTitle = 'Bulk Email';
ars_shell_begin([
    'title' => (string)$campaign['subject'],
    'subtitle' => date('d M Y H:i', strtotime((string)$campaign['created_at'])) . ' · ' . ars_bulk_email_status_label((string)$campaign['status']),
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Bulk Email', 'href' => 'bulk_email.php'],
        ['label' => 'Sent', 'href' => 'bulk_email_view.php'],
        ['label' => '#' . $campaignId],
    ],
    'actions_html' => $newButton,
    'legacy_bootstrap' => true,
]);
?>

<?php if ($message): ?>
<div class="alert alert-info"><?= h($message) ?></div>
<?php endif; ?>
<div id="beProgress" class="alert alert-secondary d-none"></div>

<div class="row g-3 mb-3">
    <div class="col-4" id="beSent"><?= ars_ds_stat_tile('Sent', (string)(int)$campaign['sent_count'], ['tone' => 'ok', 'icon' => 'check']) ?></div>
    <div class="col-4" id="beFailed"><?= ars_ds_stat_tile('Failed', (string)$failed, ['icon' => 'x']) ?></div>
    <div class="col-4" id="bePending"><?= ars_ds_stat_tile('Pending', (string)$pending, ['icon' => 'clock']) ?></div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($pending > 0): ?>
    <button type="button" class="btn btn-ars" id="beContinue"><i class="bi bi-play-fill me-1"></i>Continue sending (<?= $pending ?>)</button>
    <?php endif; ?>
    <?php if ($failed > 0): ?>
    <form method="post" onsubmit="return confirm('Try the failed emails again?');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="retry_failed">
        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-arrow-repeat me-1"></i>Retry failed (<?= $failed ?>)</button>
    </form>
    <?php endif; ?>
</div>

<div class="row g-3 align-items-start">
    <div class="col-lg-5">
        <div class="ars-card p-3">
            <div class="small text-muted mb-2">Message<?= $sample ? ' · as ' . h($sample['guest_name']) . ' received it' : '' ?></div>
            <div class="border rounded p-3 bg-light"><?= ars_bulk_email_render_body((string)$campaign['body_text'], $sample) ?></div>
            <?php foreach (ars_bulk_email_campaign_files($campaign) as $f): ?>
            <div class="small text-muted mt-2"><i class="bi bi-paperclip"></i> <?= h($f['name']) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="ars-card">
            <div class="table-responsive" style="max-height:70vh;overflow:auto">
                <table class="table ars-table mb-0 align-middle">
                    <thead><tr><th>Guest</th><th>Unit</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($recipients as $r): ?>
                        <tr>
                            <td>
                                <a href="guest_view.php?id=<?= (int)$r['guest_id'] ?>" class="fw-semibold"><?= h($r['guest_name']) ?></a>
                                <div class="small text-muted"><?= h($r['email']) ?></div>
                            </td>
                            <td><?= h($r['unit_number'] !== '' ? $r['unit_number'] : '—') ?></td>
                            <td>
                                <span class="badge <?= $badge[$r['status']] ?? 'bg-secondary' ?>"><?= h(ucfirst((string)$r['status'])) ?></span>
                                <?php if (!empty($r['sent_at'])): ?>
                                <span class="small text-muted"><?= h(date('d M H:i', strtotime((string)$r['sent_at']))) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($r['error_message'])): ?>
                                <div class="small text-danger"><?= h($r['error_message']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var campaignId = <?= (int)$campaignId ?>;
    var csrf = <?= json_encode(csrf_token()) ?>;
    var box = document.getElementById('beProgress');
    var btn = document.getElementById('beContinue');
    var sending = false;

    function tile(id, n) {
        document.querySelector('#' + id + ' .ars-tabular').textContent = n;
    }

    function show(text, tone) {
        box.className = 'alert alert-' + tone;
        box.textContent = text;
    }

    async function run() {
        if (sending) return;
        sending = true;
        if (btn) btn.disabled = true;
        show('Sending… keep this page open.', 'secondary');
        try {
            while (true) {
                var res = await fetch('ajax_bulk_email_send.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({_csrf: csrf, campaign_id: String(campaignId)})
                });
                var data = await res.json();
                if (!data.ok) {
                    show(data.error || 'Sending failed.', 'danger');
                    break;
                }
                tile('beSent', data.sent_total);
                tile('beFailed', data.failed_total);
                tile('bePending', data.pending_remaining);
                if (data.done) {
                    show('Finished. ' + data.sent_total + ' sent, ' + data.failed_total + ' failed.', data.failed_total > 0 ? 'warning' : 'success');
                    setTimeout(function () { location.href = 'bulk_email_view.php?id=' + campaignId; }, 900);
                    return;
                }
                if (data.stopped) {
                    show('Stopped: the mail server refused several emails in a row (' + (data.last_error || 'no reason given')
                        + '). The rest are still pending. Try "Continue sending" later.', 'warning');
                    break;
                }
                if (!data.processed) {
                    show('Another window is already sending this email.', 'warning');
                    break;
                }
                show('Sending… ' + data.sent_total + ' sent, ' + data.pending_remaining + ' to go. Keep this page open.', 'secondary');
            }
        } catch (e) {
            show('Connection lost. Emails already sent are kept. Press "Continue sending".', 'warning');
        }
        sending = false;
        if (btn) btn.disabled = false;
    }

    if (btn) btn.addEventListener('click', run);
    <?php if ($autostart && $pending > 0): ?>
    run();
    <?php endif; ?>
})();
</script>

<?php ars_shell_end(); ?>
