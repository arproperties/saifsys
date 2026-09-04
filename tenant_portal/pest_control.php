<?php
/**
 * Tenant Portal — Pest control requests
 * Price is based on unit type (studio, 1 BHK, 2 BHK, 3 BHK). Rates set by management.
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

// Normalize unit_type to key used in re_pest_control_rates (studio, 1_bhk, 2_bhk, 3_bhk)
$unit_type_raw = $lease['unit_type'] ?? '';
$unit_type_key = strtolower(trim($unit_type_raw));
$unit_type_key = preg_replace('/\s+/', '_', $unit_type_key);
if (!in_array($unit_type_key, ['studio', '1_bhk', '2_bhk', '3_bhk'], true)) {
    if (preg_match('/studio/i', $unit_type_raw)) $unit_type_key = 'studio';
    elseif (preg_match('/1\s*bhk|1br|1bed/i', $unit_type_raw)) $unit_type_key = '1_bhk';
    elseif (preg_match('/2\s*bhk|2br|2bed/i', $unit_type_raw)) $unit_type_key = '2_bhk';
    elseif (preg_match('/3\s*bhk|3br|3bed/i', $unit_type_raw)) $unit_type_key = '3_bhk';
    else $unit_type_key = 'studio'; // fallback
}

$tablesExist = false;
try {
    $conn->query("SELECT 1 FROM tenant_pest_control_requests LIMIT 1");
    $conn->query("SELECT 1 FROM re_pest_control_rates LIMIT 1");
    $tablesExist = true;
} catch (Throwable $e) {}

$price = null;
if ($tablesExist) {
    $pr = $conn->prepare("SELECT price_aed FROM re_pest_control_rates WHERE company_id = ? AND unit_type = ? LIMIT 1");
    $pr->execute([$company_id, $unit_type_key]);
    $row = $pr->fetch(PDO::FETCH_ASSOC);
    $price = $row ? (float)$row['price_aed'] : null;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    csrf_verify();
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

    $price_snapshot = $price;
    $total = $price_snapshot !== null ? round($price_snapshot, 2) : null;

    $ins = $conn->prepare("
        INSERT INTO tenant_pest_control_requests (tenant_id, lease_id, company_id, service_date, service_time, unit_type, price_snapshot, total_amount_aed, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $ins->execute([
        $tenant_id,
        $lease_id,
        $company_id,
        $service_date_sql,
        $service_time ?: null,
        $unit_type_key,
        $price_snapshot,
        $total
    ]);

    $message = 'Your pest control request has been submitted. Management will review and contact you.';
    $messageType = 'success';

    if (file_exists(__DIR__ . '/../modules/realestate/includes/re_email_helper.php')) {
        require_once __DIR__ . '/../modules/realestate/includes/re_email_helper.php';
        if (function_exists('send_pest_control_request_notification')) {
            send_pest_control_request_notification($conn, (int)$conn->lastInsertId(), $company_id);
        }
    }
}

$requests = [];
$hasCashColumn = false;
if ($tablesExist) {
    $selectCols = "id, service_date, service_time, unit_type, price_snapshot, total_amount_aed, status, admin_notes, created_at";
    try {
        $conn->query("SELECT cash_payment_request_id FROM tenant_pest_control_requests LIMIT 1");
        $hasCashColumn = true;
        $selectCols .= ", cash_payment_request_id";
    } catch (Throwable $e) {}
    $req = $conn->prepare("
        SELECT {$selectCols}
        FROM tenant_pest_control_requests WHERE lease_id = ? ORDER BY created_at DESC
    ");
    $req->execute([$lease_id]);
    $requests = $req->fetchAll(PDO::FETCH_ASSOC);
}

$unit_type_label = ucwords(str_replace('_', ' ', $unit_type_key));
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Pest control';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Pest control</h4>
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if (!$tablesExist): ?>
    <div class="alert alert-info">Pest control requests are not configured yet. Please contact management.</div>
<?php else: ?>
    <div class="portal-card card mb-4">
        <div class="card-header">Request pest control</div>
        <div class="card-body">
            <p class="small text-muted mb-3">Your unit type: <strong><?= h($lease['unit_type'] ?? $unit_type_label) ?></strong>. Price is based on unit size (set by management).</p>
            <?php if ($price !== null): ?>
                <p class="mb-3"><strong>Price for <?= h($unit_type_label) ?>: <?= number_format($price, 2) ?> AED</strong></p>
            <?php elseif ($price === null && $tablesExist): ?>
                <p class="text-warning mb-3">No price set for this unit type. Management will confirm the amount.</p>
            <?php endif; ?>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="unit_type" value="<?= h($unit_type_key) ?>">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Preferred service date</label>
                        <input type="date" name="service_date" class="form-control" value="<?= h($_POST['service_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Preferred time (optional)</label>
                        <input type="time" name="service_time" class="form-control" value="<?= h($_POST['service_time'] ?? '') ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 w-md-auto">Submit request</button>
            </form>
        </div>
    </div>

    <h5 class="section-title">Your pest control requests</h5>
    <div class="portal-table-wrap">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Requested</th>
                    <th>Service date</th>
                    <th>Unit type</th>
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
                        <td data-label="Unit type"><?= h(ucwords(str_replace('_', ' ', $r['unit_type']))) ?></td>
                        <td data-label="Amount (AED)"><?= $r['total_amount_aed'] !== null ? number_format((float)$r['total_amount_aed'], 2) : '—' ?></td>
                        <td data-label="Payment">
                            <?php if ($canPay): ?>
                                <a href="pay_pest_control.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Pay</a>
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
        <p class="portal-empty">No pest control requests yet.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
