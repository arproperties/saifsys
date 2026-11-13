<?php
/**
 * Real Estate Module - Send Document Expiry Alerts
 * Manually trigger document expiry alerts
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/compliance_helper.php';
require_once __DIR__ . '/includes/compliance_ui.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$results = null;

// Preview the summary e-mail in the browser (nothing is sent)
if (($_GET['preview'] ?? '') === 'summary') {
    $companyName = (string)($conn->query("SELECT name FROM companies WHERE id = " . (int)$currentCompanyId)->fetchColumn() ?: 'Real Estate');
    $rows = re_doc_digest_rows($conn, $currentCompanyId);
    header('Content-Type: text/html; charset=UTF-8');
    echo $rows
        ? re_doc_digest_html($companyName, $rows, get_base_url())
        : '<p style="font-family:Arial;padding:24px">Nothing to report – no expired or expiring documents on active leases.</p>';
    exit;
}

// Handle manual alert sending
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_expiry_alerts') {
        $results = check_and_send_document_expiry_alerts($conn, $currentCompanyId, true);
        $_SESSION[$results['sent'] > 0 ? 'success' : 'error'] = $results['message'] ?: 'Nothing was sent.';
    } elseif ($action === 'update_compliance_status') {
        $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
        if ($unitId) {
            $result = update_unit_compliance_status($conn, $currentCompanyId, $unitId);
            $_SESSION['success'] = 'Compliance status updated for unit.';
        }
    } elseif ($action === 'update_all_units') {
        // Get all units
        $units = $conn->prepare("SELECT id FROM re_units WHERE company_id = ?");
        $units->execute([$currentCompanyId]);
        $unitIds = $units->fetchAll(PDO::FETCH_COLUMN);
        
        $updated = 0;
        foreach ($unitIds as $unitId) {
            update_unit_compliance_status($conn, $currentCompanyId, $unitId);
            $updated++;
        }
        $_SESSION['success'] = "Updated compliance status for {$updated} units.";
    }
    // Post/Redirect/Get: a page refresh must not send the e-mail again
    header('Location: compliance_send_alerts.php');
    exit;
}

// Figures (same rule as the summary e-mail: active leases, current copies only)
$digestRows = re_doc_digest_rows($conn, $currentCompanyId);
$nonCompliant = $conn->prepare("SELECT COUNT(*) FROM re_unit_legal_status WHERE company_id = ? AND legal_status = 'non_compliant'");
$nonCompliant->execute([$currentCompanyId]);
$stats = [
    'expired'             => count(array_filter($digestRows, static function ($r) { return $r['days_left'] < 0; })),
    'expiring_soon'       => count(array_filter($digestRows, static function ($r) { return $r['days_left'] >= 0; })),
    'new'                 => count(array_filter($digestRows, static function ($r) { return $r['is_new']; })),
    'leases'              => count(re_doc_digest_group($digestRows)),
    'non_compliant_units' => (int)$nonCompliant->fetchColumn(),
];
$recipients = re_doc_digest_all_recipients($conn, $currentCompanyId);
$lastSent   = re_doc_digest_last_sent($conn, $currentCompanyId);
$weekday    = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][(int)RE_DOC_DIGEST_WEEKLY_DAY] ?? 'Monday';

// Recent summaries
$history = $conn->prepare("
    SELECT alert_sent_date, COUNT(*) AS items,
           SUM(days_before_expiry < 0) AS expired_items,
           MAX(alert_sent_to) AS sent_to
    FROM re_document_expiry_alerts
    WHERE company_id = ? AND status = 'sent'
    GROUP BY alert_sent_date
    ORDER BY alert_sent_date DESC
    LIMIT 8
");
$history->execute([$currentCompanyId]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$total = $stats['expired'] + $stats['expiring_soon'];
$pageTitle = 'Send Compliance Alerts';
require_once __DIR__ . '/includes/re_layout_header.php';

ob_start(); ?>
<a class="cx-btn" href="?preview=summary" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview e-mail</a>
<form method="POST" class="d-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_expiry_alerts">
    <button type="submit" class="cx-btn cx-btn-primary" <?= ($recipients && $total) ? '' : 'disabled' ?>
            onclick="return confirm('Send ONE summary e-mail listing <?= (int)$total ?> documents across <?= (int)$stats['leases'] ?> leases to:\n<?= h(implode(', ', $recipients)) ?>?')">
        <i class="bi bi-send"></i> Send summary now
    </button>
</form>
<?php
$actions = ob_get_clean();
re_cx_header('alerts', 'Expiry Alerts', 'One professional summary e-mail, grouped by lease, instead of one e-mail per document.', $actions);
re_cx_flash();
?>

<?php if (!$recipients): ?>
    <div class="cx-note bg-red" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <div><strong>No recipients set.</strong> The summary cannot be sent until at least one e-mail address is added.
             <a href="../../settings.php?tab=re_email" class="ms-1">Settings → Real Estate Email Notifications → Document Expiry Email(s)</a></div>
    </div>
<?php endif; ?>

<div class="cx-kpis">
    <div class="cx-kpi">
        <div class="cx-kpi-icon bg-red"><i class="bi bi-x-octagon"></i></div>
        <div><div class="cx-kpi-label">Expired</div><div class="cx-kpi-value t-red"><?= $stats['expired'] ?></div>
             <div class="cx-kpi-note">on active leases</div></div>
    </div>
    <div class="cx-kpi">
        <div class="cx-kpi-icon bg-amber"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="cx-kpi-label">Expiring soon</div><div class="cx-kpi-value t-amber"><?= $stats['expiring_soon'] ?></div>
             <div class="cx-kpi-note">within reminder window</div></div>
    </div>
    <div class="cx-kpi">
        <div class="cx-kpi-icon bg-blue"><i class="bi bi-house-door"></i></div>
        <div><div class="cx-kpi-label">Leases</div><div class="cx-kpi-value t-blue"><?= $stats['leases'] ?></div>
             <div class="cx-kpi-note">in the next summary</div></div>
    </div>
    <div class="cx-kpi">
        <div class="cx-kpi-icon bg-grey"><i class="bi bi-bell"></i></div>
        <div><div class="cx-kpi-label">Not yet reported</div><div class="cx-kpi-value"><?= $stats['new'] ?></div>
             <div class="cx-kpi-note">will be marked NEW</div></div>
    </div>
</div>

<div class="cx-grid-2">
    <div>
        <section class="cx-card" aria-labelledby="cx-sum-title">
            <div class="cx-card-head">
                <div><h2 class="cx-card-title" id="cx-sum-title"><i class="bi bi-envelope-paper"></i> Tenant Document Expiry Summary</h2>
                     <p class="cx-card-sub">Who gets it, when it goes out and what it contains.</p></div>
            </div>
            <div class="cx-card-body">
                <ul class="cx-list">
                    <li><span class="k">Recipients</span>
                        <span><?php if ($recipients): foreach ($recipients as $r): ?>
                                <span class="cx-pill bg-blue no-dot me-1 mb-1"><?= h($r) ?></span>
                              <?php endforeach; ?>
                              <a href="../../settings.php?tab=re_email" class="d-block mt-1" style="font-size:.85rem;">Change in Settings →</a>
                              <?php else: ?><span class="cx-pill bg-red no-dot">Not set</span><?php endif; ?></span></li>
                    <li><span class="k">Last sent</span><span><?= $lastSent ? h(date('l, d M Y', strtotime($lastSent))) : 'Never' ?></span></li>
                    <li><span class="k">Daily</span><span>Sent only when a document newly enters its reminder window or newly expires.</span></li>
                    <li><span class="k">Weekly</span><span>Full open list every <?= h($weekday) ?>, even when nothing is new.</span></li>
                    <li><span class="k">Included</span><span>Active leases only. Expired / terminated leases and documents already replaced by a newer copy are left out.</span></li>
                </ul>
            </div>
        </section>

        <section class="cx-card" aria-labelledby="cx-hist-title">
            <div class="cx-card-head"><h2 class="cx-card-title" id="cx-hist-title"><i class="bi bi-clock-history"></i> Recent summaries</h2></div>
            <?php if (!$history): ?>
                <div class="cx-empty" style="padding:24px;"><i class="bi bi-inbox" style="color:var(--cx-muted)"></i>No summary has been sent yet.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="cx-table">
                    <thead><tr><th>Date</th><th class="num">New items</th><th class="num">Of which expired</th><th class="cx-hide-sm">Sent to</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $hrow): ?>
                        <tr><td class="cx-strong"><?= h(date('d M Y', strtotime($hrow['alert_sent_date']))) ?></td>
                            <td class="num"><?= (int)$hrow['items'] ?></td>
                            <td class="num t-red"><?= (int)$hrow['expired_items'] ?></td>
                            <td class="cx-hide-sm cx-dim"><?= h($hrow['sent_to']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <section class="cx-card" aria-labelledby="cx-how-title">
            <div class="cx-card-head"><h2 class="cx-card-title" id="cx-how-title"><i class="bi bi-signpost-split"></i> How it works</h2></div>
            <div class="cx-card-body">
                <ol class="cx-steps">
                    <li><span>Each document type has its reminder days (default 30) in <a href="compliance_document_types.php">Document Types</a>.</span></li>
                    <li><span>Every morning the system checks documents on active leases.</span></li>
                    <li><span>If something is new, one summary e-mail goes out, grouped by lease, most urgent first.</span></li>
                    <li><span>Staff collect the renewed copy and upload it to the lease.</span></li>
                    <li><span>The item drops out of the next summary automatically.</span></li>
                </ol>
            </div>
        </section>

        <section class="cx-card" aria-labelledby="cx-unit-title">
            <div class="cx-card-head"><h2 class="cx-card-title" id="cx-unit-title"><i class="bi bi-shield-check"></i> Unit compliance</h2></div>
            <div class="cx-card-body">
                <div class="d-flex align-items-baseline gap-2 mb-1">
                    <span class="cx-kpi-value <?= $stats['non_compliant_units'] ? 't-red' : 't-green' ?>"><?= $stats['non_compliant_units'] ?></span>
                    <span class="cx-dim">non-compliant units</span>
                </div>
                <p class="cx-dim mb-3">Recalculates each unit's compliance score from its documents (expired, missing).</p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_all_units">
                    <button type="submit" class="cx-btn w-100 justify-content-center"
                            onclick="return confirm('Recalculate compliance status for all units?')">
                        <i class="bi bi-arrow-clockwise"></i> Recalculate all units
                    </button>
                </form>
            </div>
        </section>
    </div>
</div>

<?php re_cx_footer(); ?>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
