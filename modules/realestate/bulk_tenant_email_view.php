<?php
/**
 * Bulk Tenant Email — campaign detail, continue send, retry failed.
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
$brand = getBrandSettings($conn);
$reLayoutFluid = true;
$campaignId = (int)($_GET['id'] ?? 0);
$autostart = !empty($_GET['autostart']);

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$campaign = $campaignId > 0 ? re_bulk_email_get_campaign($conn, $companyId, $campaignId) : null;
if (!$campaign) {
    http_response_code(404);
    die('Campaign not found.');
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'retry_failed') {
        $res = re_bulk_email_retry_failed($conn, $companyId, $campaignId);
        if (!empty($res['ok'])) {
            $message = 'Re-queued ' . (int)$res['requeued'] . ' failed recipient(s). Successful sends were not touched.';
            $autostart = ((int)$res['requeued'] > 0);
        } else {
            $message = $res['error'] ?? 'Retry failed.';
            $messageType = 'danger';
        }
        $campaign = re_bulk_email_get_campaign($conn, $companyId, $campaignId);
    }
}

$recipients = $conn->prepare("
    SELECT * FROM re_bulk_email_recipients
    WHERE campaign_id = ? AND company_id = ?
    ORDER BY FIELD(status,'pending','sending','failed','sent','skipped_duplicate','excluded_invalid'), id ASC
");
$recipients->execute([$campaignId, $companyId]);
$recipients = $recipients->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pending = (int)$campaign['pending_count'];
$failed = (int)$campaign['failed_count'];
$canContinue = $pending > 0;
$canRetry = $failed > 0;

$pageTitle = 'Bulk Email Campaign #' . $campaignId;
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <a href="bulk_tenant_email_history.php" class="btn btn-sm btn-outline-secondary mb-2">&larr; History</a>
        <h4 class="mb-0"><?= h($campaign['subject']) ?></h4>
        <small class="text-muted">Campaign #<?= (int)$campaignId ?> · <?= h($campaign['created_at']) ?> · Status: <strong id="campStatus"><?= h($campaign['status']) ?></strong></small>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canContinue): ?>
            <button type="button" class="btn btn-primary" id="btnContinue"><i class="bi bi-play-fill"></i> Continue pending</button>
        <?php endif; ?>
        <?php if ($canRetry): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Retry failed recipients only? Successful emails will not be resent.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="retry_failed">
                <button type="submit" class="btn btn-outline-warning">Retry failed (<?= $failed ?>)</button>
            </form>
        <?php endif; ?>
        <a href="bulk_tenant_email.php" class="btn btn-outline-secondary">New campaign</a>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-<?= h($messageType) ?>"><?= h($message) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted small">Unique</div><div class="fs-4" id="cntUnique"><?= (int)$campaign['unique_email_count'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted small">Sent</div><div class="fs-4 text-success" id="cntSent"><?= (int)$campaign['sent_count'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted small">Failed</div><div class="fs-4 text-danger" id="cntFailed"><?= (int)$campaign['failed_count'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round"><div class="card-body"><div class="text-muted small">Pending</div><div class="fs-4" id="cntPending"><?= (int)$campaign['pending_count'] ?></div></div></div></div>
</div>

<div class="card card-round mb-3">
    <div class="card-header">Message</div>
    <div class="card-body">
        <?php foreach (re_bulk_email_campaign_attachments($campaign) as $att): ?>
            <div class="small text-muted mb-1"><i class="bi bi-paperclip"></i> <?= h($att['name']) ?></div>
        <?php endforeach; ?>
        <div class="border rounded p-3 bg-light"><?= re_bulk_email_sanitize_html((string)$campaign['body_html']) ?></div>
        <div class="form-text mt-2">Sent campaigns cannot be edited or deleted. Use Continue for pending, or Retry failed only.</div>
    </div>
</div>

<div id="sendProgress" class="alert alert-secondary d-none"><div><span id="sendProgressText">Sending batch…</span></div><div id="sendHourText" class="small mt-1 opacity-75"></div></div>

<div class="card card-round">
    <div class="card-header">Recipients</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" id="recipientTable">
            <thead class="table-light">
                <tr>
                    <th>Tenant</th>
                    <th>Building / Unit</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recipients as $r): ?>
                <tr data-id="<?= (int)$r['id'] ?>" data-status="<?= h($r['status']) ?>">
                    <td><?= h($r['tenant_name']) ?></td>
                    <td><?= h($r['building_name']) ?> / <?= h($r['unit_number']) ?></td>
                    <td><?= h($r['email']) ?></td>
                    <td class="rec-status"><span class="badge bg-<?= $r['status'] === 'sent' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h($r['status']) ?></span></td>
                    <td><?= (int)$r['attempts'] ?></td>
                    <td class="small text-danger"><?= h($r['error_message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const campaignId = <?= (int)$campaignId ?>;
const csrf = <?= json_encode(csrf_token()) ?>;
let sending = false;

async function processBatch() {
  const res = await fetch('ajax_bulk_tenant_email_send.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({_csrf: csrf, campaign_id: String(campaignId)})
  });
  return res.json();
}

function tone(box, name) {
  box.className = 'alert alert-' + name;
}

function countdownText(secs) {
  const m = Math.floor(secs / 60), s = secs % 60;
  return m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
}

/**
 * The mail server allows a fixed number of messages per clock hour. When this
 * hour's allowance is spent we wait for the next one and carry on, rather than
 * hammering the server or abandoning the tenants still queued.
 */
function waitForNextHour(data, box, txt) {
  const until = (data.resume_at_ts || 0) * 1000 || (Date.now() + (data.retry_after || 60) * 1000);
  tone(box, 'warning');
  return new Promise(resolve => {
    const tick = () => {
      const left = Math.max(0, Math.round((until - Date.now()) / 1000));
      const at = new Date(until).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
      txt.textContent = `Sent ${data.hour_used || 0} of ${data.hour_limit || 50} allowed this hour. `
        + `${data.pending_remaining || 0} tenant(s) still queued — continuing at ${at} (in ${countdownText(left)}).`;
      if (left <= 0) {
        clearInterval(timer);
        tone(box, 'secondary');
        txt.textContent = 'New hour — resuming…';
        resolve();
      }
    };
    const timer = setInterval(tick, 1000);
    tick();
  });
}

async function continueSending() {
  if (sending) return;
  sending = true;
  const box = document.getElementById('sendProgress');
  const txt = document.getElementById('sendProgressText');
  const hourTxt = document.getElementById('sendHourText');
  const btn = document.getElementById('btnContinue');
  if (btn) btn.disabled = true;
  box.classList.remove('d-none');
  try {
    let guard = 0;
    while (guard < 2000) {
      guard++;
      const data = await processBatch();
      if (!data.ok) {
        txt.textContent = data.error || 'Send error';
        tone(box, 'danger');
        break;
      }
      document.getElementById('cntSent').textContent = data.sent_total ?? document.getElementById('cntSent').textContent;
      document.getElementById('cntFailed').textContent = data.failed_total ?? document.getElementById('cntFailed').textContent;
      document.getElementById('cntPending').textContent = data.pending_remaining ?? '0';
      if (data.campaign_status) document.getElementById('campStatus').textContent = data.campaign_status;
      if (data.hour_limit) {
        hourTxt.textContent = `This hour: ${data.hour_used || 0} of ${data.hour_limit} sent.`;
      }

      if (data.done) {
        tone(box, 'success');
        txt.textContent = 'All pending deliveries finished.';
        setTimeout(() => location.reload(), 800);
        break;
      }

      if (data.paused) {
        // Hour is full. Wait it out, then keep going with the rest.
        await waitForNextHour(data, box, txt);
        continue;
      }

      txt.textContent = `Batch sent ${data.sent || 0}, failed ${data.failed || 0}. Pending ${data.pending_remaining || 0}.`;

      if ((data.processed || 0) === 0 && (data.pending_remaining || 0) > 0) {
        // nothing claimed — stop to avoid loop
        break;
      }
    }
  } catch (e) {
    box.classList.remove('d-none');
    tone(box, 'warning');
    txt.textContent = 'Connection interrupted. Successfully sent emails were kept. Reopen and click Continue pending.';
  } finally {
    sending = false;
    if (btn) btn.disabled = false;
  }
}

document.getElementById('btnContinue')?.addEventListener('click', continueSending);
<?php if ($autostart && $canContinue): ?>
continueSending();
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
