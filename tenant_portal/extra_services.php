<?php
/**
 * Tenant Portal — Extra service requests (parking, storage, cleaning, pest control, other)
 * Period-based pricing when management has set monthly rates; optional online payment.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];
$tenant_id = (int)$lease['tenant_id'];
$company_id = (int)$lease['company_id'];

$allowed_types = ['extra_parking', 'storage', 'other'];
$monthly_billed_types = ['extra_parking', 'storage'];

// Check if pricing columns exist (migration tenant_extra_services_enhance.sql)
$hasPricingColumns = false;
try {
    $conn->query("SELECT period_from, period_to, monthly_rate_aed, total_amount_aed, payment_status FROM tenant_extra_service_requests LIMIT 1");
    $hasPricingColumns = true;
} catch (Throwable $e) {
    // Old schema
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $service_type = trim($_POST['service_type'] ?? 'other');
    if (!in_array($service_type, $allowed_types, true)) {
        $service_type = 'other';
    }
    $description = trim($_POST['description'] ?? '');
    $period_from = trim($_POST['period_from'] ?? '');
    $period_to = trim($_POST['period_to'] ?? '');

    $period_from_sql = null;
    $period_to_sql = null;
    $monthly_rate_sql = null;
    $total_amount_sql = null;
    $payment_status_sql = 'n_a';

    if ($hasPricingColumns && in_array($service_type, $monthly_billed_types, true) && $period_from && $period_to) {
        $from_ts = strtotime($period_from);
        $to_ts = strtotime($period_to);
        if ($from_ts && $to_ts && $to_ts >= $from_ts) {
            $period_from_sql = date('Y-m-d', $from_ts);
            $period_to_sql = date('Y-m-d', $to_ts);
            $rateRow = $conn->prepare("SELECT monthly_amount_aed FROM re_extra_service_rates WHERE company_id = ? AND service_type = ? AND is_active = 1 LIMIT 1");
            $rateRow->execute([$company_id, $service_type]);
            $rateRow = $rateRow->fetch(PDO::FETCH_ASSOC);
            if ($rateRow && (float)$rateRow['monthly_amount_aed'] > 0) {
                $monthly_rate_sql = (float)$rateRow['monthly_amount_aed'];
                $days = max(1, ($to_ts - $from_ts) / 86400);
                $from_d = (int) date('j', $from_ts);
                $from_m = (int) date('n', $from_ts);
                $from_y = (int) date('Y', $from_ts);
                $to_d = (int) date('j', $to_ts);
                $to_m = (int) date('n', $to_ts);
                $to_y = (int) date('Y', $to_ts);
                $cal_months = ($to_y - $from_y) * 12 + ($to_m - $from_m);
                $extra_days = ($to_d >= $from_d) ? ($to_d - $from_d + 1) : 0;
                // One full year (364–366 days) = exactly 12 months
                if ($days >= 364 && $days <= 366) {
                    $total_amount_sql = round(12 * $monthly_rate_sql, 2);
                } else {
                    $total_amount_sql = round($cal_months * $monthly_rate_sql + ($extra_days / 30.44) * $monthly_rate_sql, 2);
                }
                $payment_status_sql = $total_amount_sql > 0 ? 'pending_payment' : 'n_a';
            }
        }
    }

    if ($hasPricingColumns) {
        $ins = $conn->prepare("
            INSERT INTO tenant_extra_service_requests (tenant_id, lease_id, company_id, service_type, description, status, period_from, period_to, monthly_rate_aed, total_amount_aed, payment_status)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
        ");
        $ins->execute([$tenant_id, $lease_id, $company_id, $service_type, $description ?: null, $period_from_sql, $period_to_sql, $monthly_rate_sql, $total_amount_sql ?: null, $payment_status_sql]);
    } else {
        $ins = $conn->prepare("
            INSERT INTO tenant_extra_service_requests (tenant_id, lease_id, company_id, service_type, description, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $ins->execute([$tenant_id, $lease_id, $company_id, $service_type, $description ?: null]);
    }
    $requestId = (int) $conn->lastInsertId();
    $message = 'Your request has been submitted. Management will review and contact you.' . ($total_amount_sql > 0 ? ' You can pay online from the list below once approved.' : '');
    $messageType = 'success';

    if ($requestId && file_exists(__DIR__ . '/../modules/realestate/includes/re_email_helper.php')) {
        require_once __DIR__ . '/../modules/realestate/includes/re_email_helper.php';
        send_extra_service_request_notification($conn, $requestId, $company_id);
    }
}

$selectCols = "id, service_type, description, status, admin_notes, created_at, approved_at";
if ($hasPricingColumns) {
    $selectCols .= ", period_from, period_to, monthly_rate_aed, total_amount_aed, payment_status";
}
try {
    $conn->query("SELECT cash_payment_request_id FROM tenant_extra_service_requests LIMIT 1");
    $selectCols .= ", cash_payment_request_id";
} catch (Throwable $e) {}
$hasCashColumn = strpos($selectCols, 'cash_payment_request_id') !== false;
$requests = $conn->prepare("
    SELECT {$selectCols}
    FROM tenant_extra_service_requests
    WHERE lease_id = ?
    ORDER BY created_at DESC
");
$requests->execute([$lease_id]);
$requests = $requests->fetchAll(PDO::FETCH_ASSOC);

// Load rates for display (e.g. "300 AED/month" in form)
$rates = [];
if ($hasPricingColumns) {
    $r = $conn->prepare("SELECT service_type, monthly_amount_aed FROM re_extra_service_rates WHERE company_id = ? AND is_active = 1");
    $r->execute([$company_id]);
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $rates[$row['service_type']] = (float)$row['monthly_amount_aed'];
    }
}

$pageTitle = 'Extra services';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Extra service requests</h4>
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="portal-card card mb-4">
    <div class="card-header">Request a service</div>
    <div class="card-body">
        <form method="POST" id="extraServiceForm">
            <?php csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label">Service type</label>
                <select name="service_type" id="service_type" class="form-select">
                    <option value="extra_parking">Extra parking</option>
                    <option value="storage">Storage</option>
                    <option value="other">Other</option>
                </select>
                <small class="text-muted" id="rateHint"></small>
            </div>
            <?php if ($hasPricingColumns): ?>
            <div class="mb-3 row g-2" id="periodRow">
                <div class="col-md-6">
                    <label class="form-label">Period from</label>
                    <input type="date" name="period_from" id="period_from" class="form-control" min="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Period to</label>
                    <input type="date" name="period_to" id="period_to" class="form-control">
                </div>
                <p class="small text-muted mb-0" id="periodSummary"></p>
            </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">Description (optional)</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Additional details..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary w-100 w-md-auto">Submit request</button>
        </form>
    </div>
</div>

<h5 class="section-title">Your requests</h5>
<div class="portal-table-wrap">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <?php if ($hasPricingColumns): ?><th>Period</th><th>Amount</th><th>Payment</th><?php endif; ?>
                <th>Description</th>
                <th>Status</th>
                <th>Response</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $row): ?>
                <tr>
                    <td data-label="Date"><?= htmlspecialchars(date('M j, Y', strtotime($row['created_at']))) ?></td>
                    <td data-label="Type"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['service_type']))) ?></td>
                    <?php if ($hasPricingColumns): ?>
                    <td data-label="Period">
                        <?php if (!empty($row['period_from']) && !empty($row['period_to'])): ?>
                            <?= date('M j, Y', strtotime($row['period_from'])) ?> – <?= date('M j, Y', strtotime($row['period_to'])) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="Amount">
                        <?php if (!empty($row['total_amount_aed'])): ?>
                            <?= number_format((float)$row['total_amount_aed'], 2) ?> AED
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="Payment">
                        <?php if (($row['payment_status'] ?? 'n_a') === 'pending_payment' && (float)($row['total_amount_aed'] ?? 0) > 0): ?>
                            <a href="pay_extra_service.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-primary">Pay</a>
                            <?php if (!empty($row['cash_payment_request_id'])): ?>
                                <?php
                                $cpr = null;
                                if (file_exists(__DIR__ . '/../modules/realestate/includes/cash_payment_helper.php')) {
                                    require_once __DIR__ . '/../modules/realestate/includes/cash_payment_helper.php';
                                    if (cash_payment_tables_exist($conn)) {
                                        $c = $conn->prepare("SELECT request_number, status FROM re_cash_payment_requests WHERE id = ?");
                                        $c->execute([(int)$row['cash_payment_request_id']]);
                                        $cpr = $c->fetch(PDO::FETCH_ASSOC);
                                    }
                                }
                                if ($cpr): ?>
                                <span class="badge bg-info ms-1" title="Cash: <?= htmlspecialchars($cpr['request_number']) ?>"><?= htmlspecialchars($cpr['request_number']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif (($row['payment_status'] ?? '') === 'paid'): ?>
                            <span class="badge bg-success">Paid</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td data-label="Description"><?= nl2br(htmlspecialchars($row['description'] ?: '—')) ?></td>
                    <td data-label="Status"><span class="badge bg-<?= $row['status'] === 'approved' ? 'success' : ($row['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                    <td data-label="Response"><?= $row['admin_notes'] ? nl2br(htmlspecialchars($row['admin_notes'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (empty($requests)): ?>
    <p class="portal-empty">No extra service requests yet.</p>
<?php endif; ?>

<?php if ($hasPricingColumns && !empty($rates)): ?>
<script>
(function() {
    var rates = <?= json_encode($rates) ?>;
    var sel = document.getElementById('service_type');
    var hint = document.getElementById('rateHint');
    var periodFrom = document.getElementById('period_from');
    var periodTo = document.getElementById('period_to');
    var summary = document.getElementById('periodSummary');
    function updateHint() {
        var v = sel && sel.value;
        if (rates[v]) hint.textContent = rates[v] + ' AED per month';
        else hint.textContent = '';
    }
    function updateSummary() {
        if (!periodFrom || !periodTo || !summary) return;
        var from = periodFrom.value, to = periodTo.value;
        if (!from || !to) { summary.textContent = ''; return; }
        var v = sel && sel.value;
        var rate = rates[v];
        if (!rate) { summary.textContent = ''; return; }
        var a = new Date(from), b = new Date(to);
        if (b < a) { summary.textContent = 'To date must be after from date.'; return; }
        var daysDiff = Math.max(1, Math.round((b - a) / (24 * 60 * 60 * 1000)));
        var exactAmount, periodLabel;
        var fromD = a.getDate(), fromM = a.getMonth() + 1, fromY = a.getFullYear();
        var toD = b.getDate(), toM = b.getMonth() + 1, toY = b.getFullYear();
        var calMonths = (toY - fromY) * 12 + (toM - fromM);
        var extraDays = (toD >= fromD) ? (toD - fromD + 1) : 0;
        if (daysDiff >= 364 && daysDiff <= 366) {
            periodLabel = '1 year (12 months)';
            exactAmount = 12 * rate;
        } else {
            exactAmount = calMonths * rate + (extraDays / 30.44) * rate;
            var parts = [];
            if (calMonths > 0) parts.push(calMonths === 1 ? '1 month' : calMonths + ' months');
            if (extraDays > 0) parts.push(extraDays === 1 ? '1 day' : extraDays + ' days');
            periodLabel = parts.length ? parts.join(', ') : '1 day';
        }
        summary.textContent = daysDiff + ' day(s) (' + periodLabel + ') × ' + rate + ' AED/month = ' + exactAmount.toFixed(2) + ' AED total';
    }
    if (sel) sel.addEventListener('change', updateHint);
    if (periodFrom) periodFrom.addEventListener('change', updateSummary);
    if (periodTo) periodTo.addEventListener('change', updateSummary);
    updateHint();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
