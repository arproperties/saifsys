<?php
/**
 * Tenant Portal — Cleaning service requests
 * Total hours = cleaners × hours. Amount = (rate_per_hour × total hours) + (materials_fee_per_hour × total hours) if materials requested.
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

$tablesExist = false;
try {
    $conn->query("SELECT 1 FROM tenant_cleaning_requests LIMIT 1");
    $conn->query("SELECT 1 FROM re_cleaning_rates LIMIT 1");
    $tablesExist = true;
} catch (Throwable $e) {}

$rates = null;
if ($tablesExist) {
    $r = $conn->prepare("SELECT rate_per_hour_aed, materials_fee_aed FROM re_cleaning_rates WHERE company_id = ? LIMIT 1");
    $r->execute([$company_id]);
    $rates = $r->fetch(PDO::FETCH_ASSOC);
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    csrf_verify();
    $num_cleaners = max(1, min(20, (int)($_POST['num_cleaners'] ?? 1)));
    $num_hours = max(0.5, min(24 * 7, (float)($_POST['num_hours'] ?? 1)));
    $has_materials = !empty($_POST['has_materials']);
    $service_date_raw = trim($_POST['service_date'] ?? '');
    $service_time = trim($_POST['service_time'] ?? '');
    $service_date_sql = null;
    if ($service_date_raw !== '') {
        try {
            $d = new DateTime($service_date_raw);
            $service_date_sql = $d->format('Y-m-d');
        } catch (Throwable $e) {
            $service_date_sql = null;
        }
    }

    $rate_per_hour = $rates ? (float)$rates['rate_per_hour_aed'] : 0;
    $materials_fee_per_hour = $rates ? (float)$rates['materials_fee_aed'] : 0;
    $total_hours = $num_cleaners * $num_hours;
    $total = ($rate_per_hour * $total_hours) + ($has_materials ? $materials_fee_per_hour * $total_hours : 0);
    $total = round($total, 2);

    $ins = $conn->prepare("
        INSERT INTO tenant_cleaning_requests (tenant_id, lease_id, company_id, service_date, service_time, num_cleaners, num_hours, has_materials, rate_per_hour_snapshot, materials_fee_snapshot, total_amount_aed, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $ins->execute([
        $tenant_id,
        $lease_id,
        $company_id,
        $service_date_sql,
        $service_time ?: null,
        $num_cleaners,
        $num_hours,
        $has_materials ? 1 : 0,
        $rate_per_hour ?: null,
        $has_materials ? $materials_fee_per_hour : null,
        $total ?: null
    ]);

    $message = 'Your cleaning request has been submitted. Management will review and contact you.';
    $messageType = 'success';

    if (file_exists(__DIR__ . '/../modules/realestate/includes/re_email_helper.php')) {
        require_once __DIR__ . '/../modules/realestate/includes/re_email_helper.php';
        if (function_exists('send_cleaning_request_notification')) {
            send_cleaning_request_notification($conn, (int)$conn->lastInsertId(), $company_id);
        }
    }
}

$requests = [];
$hasCashColumn = false;
if ($tablesExist) {
    $selectCols = "id, service_date, service_time, num_cleaners, num_hours, has_materials, rate_per_hour_snapshot, materials_fee_snapshot, total_amount_aed, status, admin_notes, created_at";
    try {
        $conn->query("SELECT cash_payment_request_id FROM tenant_cleaning_requests LIMIT 1");
        $hasCashColumn = true;
        $selectCols .= ", cash_payment_request_id";
    } catch (Throwable $e) {}
    $req = $conn->prepare("
        SELECT {$selectCols}
        FROM tenant_cleaning_requests WHERE lease_id = ? ORDER BY created_at DESC
    ");
    $req->execute([$lease_id]);
    $requests = $req->fetchAll(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Cleaning';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Cleaning service</h4>
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if (!$tablesExist): ?>
    <div class="alert alert-info">Cleaning requests are not configured yet. Please contact management.</div>
<?php else: ?>
    <div class="portal-card card mb-4">
        <div class="card-header">Request cleaning</div>
        <div class="card-body">
            <?php if ($rates && ((float)$rates['rate_per_hour_aed'] > 0 || (float)$rates['materials_fee_aed'] > 0)): ?>
                <p class="small text-muted mb-3">
                    Rate: <?= number_format((float)$rates['rate_per_hour_aed'], 2) ?> AED per hour per cleaner.
                    <?php if ((float)$rates['materials_fee_aed'] > 0): ?>Materials: <?= number_format((float)$rates['materials_fee_aed'], 2) ?> AED per hour (when requested).<?php endif; ?>
                </p>
            <?php endif; ?>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Number of cleaners</label>
                        <input type="number" name="num_cleaners" class="form-control" min="1" max="20" value="<?= h($_POST['num_cleaners'] ?? '1') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Number of hours</label>
                        <input type="number" name="num_hours" class="form-control" min="0.5" step="0.5" value="<?= h($_POST['num_hours'] ?? '1') ?>" required>
                    </div>
                    <div class="col-md-4 d-flex align-items-end pb-2">
                        <div class="form-check">
                            <input type="checkbox" name="has_materials" id="has_materials" class="form-check-input" value="1" <?= !empty($_POST['has_materials']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="has_materials">Include cleaning materials</label>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label">Preferred service date</label>
                        <input type="date" name="service_date" class="form-control" value="<?= h($_POST['service_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Preferred time (optional)</label>
                        <input type="time" name="service_time" class="form-control" value="<?= h($_POST['service_time'] ?? '') ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3 w-100 w-md-auto">Submit request</button>
            </form>
        </div>
    </div>

    <h5 class="section-title">Your cleaning requests</h5>
    <div class="portal-table-wrap">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Requested</th>
                    <th>Service date</th>
                    <th>Cleaners</th>
                    <th>Hours</th>
                    <th>Materials</th>
                    <th>Amount (AED)</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Response</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r):
                    $canPay = $r['status'] === 'approved' && (float)($r['total_amount_aed'] ?? 0) > 0;
                    $cpr = null;
                    if ($hasCashColumn && !empty($r['cash_payment_request_id']) && file_exists(__DIR__ . '/../modules/realestate/includes/cash_payment_helper.php')) {
                        require_once __DIR__ . '/../modules/realestate/includes/cash_payment_helper.php';
                        if (cash_payment_tables_exist($conn)) {
                            $c = $conn->prepare("SELECT request_number, status FROM re_cash_payment_requests WHERE id = ?");
                            $c->execute([(int)$r['cash_payment_request_id']]);
                            $cpr = $c->fetch(PDO::FETCH_ASSOC);
                        }
                    }
                ?>
                    <tr>
                        <td data-label="Requested"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                        <td data-label="Service date">
                            <?php if (!empty($r['service_date'])): ?>
                                <?= date('M j, Y', strtotime($r['service_date'])) ?><?= $r['service_time'] ? ' &middot; ' . h($r['service_time']) : '' ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td data-label="Cleaners"><?= (int)$r['num_cleaners'] ?></td>
                        <td data-label="Hours"><?= h($r['num_hours']) ?></td>
                        <td data-label="Materials"><?= $r['has_materials'] ? 'Yes' : 'No' ?></td>
                        <td data-label="Amount (AED)"><?= $r['total_amount_aed'] !== null ? number_format((float)$r['total_amount_aed'], 2) : '—' ?></td>
                        <td data-label="Payment">
                            <?php if ($canPay): ?>
                                <a href="pay_cleaning.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Pay</a>
                                <?php if ($cpr): ?>
                                    <span class="badge bg-info ms-1" title="Cash: <?= h($cpr['request_number']) ?>"><?= h($cpr['request_number']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td data-label="Status"><span class="badge bg-<?= $r['status'] === 'approved' ? 'success' : ($r['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= h($r['status']) ?></span></td>
                        <td data-label="Response"><?= $r['admin_notes'] ? nl2br(h($r['admin_notes'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (empty($requests)): ?>
        <p class="portal-empty">No cleaning requests yet.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
