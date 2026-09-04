<?php
/**
 * Unified Customer Push Notifications — manual admin sending.
 *
 * Step 1 only: manual sends for Tenant Mode and ARS Guest Mode. Automatic event
 * triggers are intentionally left for Step 2.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/tenant_notifications.php';
require_once __DIR__ . '/../../includes/customer_push_notifications.php';
require_once __DIR__ . '/../ars/includes/ars_guest_notifications.php';

require_login();
if (!has_role('Owner', $conn) && !has_role('Admin', $conn) && !user_has_module_access($conn, current_user_id() ?: 0, MODULE_REALESTATE) && !user_has_module_access($conn, current_user_id() ?: 0, MODULE_ARS)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
customer_push_ensure_schema($conn);
ars_guest_notifications_ensure_schema($conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function cp_action_entity_type(string $userType, string $actionType): ?string {
    if ($userType === 'tenant') {
        return [
            'payments' => 'payment',
            'maintenance' => 'maintenance',
            'cleaning' => 'cleaning',
            'pest' => 'pest_control',
            'extra_service' => 'extra_service',
            'renewal' => 'renewal',
            'document' => 'document',
            'general' => null,
        ][$actionType] ?? null;
    }
    return [
        'booking' => 'booking',
        'payment' => 'payment',
        'lifecycle_request' => 'lifecycle_request',
        'document' => 'document',
        'receipt' => 'receipt',
        'checkin' => 'booking',
        'checkout' => 'booking',
        'general' => null,
    ][$actionType] ?? null;
}

function cp_guest_event_type(string $actionType): string {
    return [
        'booking' => 'manual_booking',
        'payment' => 'manual_payment',
        'lifecycle_request' => 'manual_lifecycle_request',
        'document' => 'manual_document',
        'receipt' => 'manual_receipt',
        'checkin' => 'manual_checkin',
        'checkout' => 'manual_checkout',
        'general' => 'manual_general',
    ][$actionType] ?? 'manual_general';
}

/**
 * @return list<array<string,mixed>>
 */
function cp_tenant_recipients(PDO $conn, int $companyId, string $target, array $post): array {
    $baseSelect = "
        SELECT DISTINCT
            tpu.id AS tenant_portal_user_id,
            tpu.tenant_id,
            tpu.company_id,
            tpu.display_name,
            tpu.email,
            l.id AS lease_id
        FROM tenant_portal_users tpu
        LEFT JOIN re_leases l ON l.id = (
            SELECT l2.id
            FROM re_leases l2
            WHERE l2.company_id = tpu.company_id
              AND l2.tenant_id = tpu.tenant_id
              AND l2.status IN ('active','renewed')
            ORDER BY l2.end_date DESC, l2.id DESC
            LIMIT 1
        )
    ";
    $where = ["tpu.company_id = ?", "tpu.status = 'approved'"];
    $params = [$companyId];

    if ($target === 'tenant') {
        $id = (int)($post['tenant_portal_user_id'] ?? 0);
        if ($id <= 0) return [];
        $where[] = 'tpu.id = ?';
        $params[] = $id;
    } elseif ($target === 'building') {
        $buildingId = (int)($post['building_id'] ?? 0);
        if ($buildingId <= 0) return [];
        $baseSelect .= " INNER JOIN re_leases tl ON tl.tenant_id = tpu.tenant_id AND tl.company_id = tpu.company_id
                         INNER JOIN re_units tu ON tu.id = tl.unit_id AND tu.company_id = tl.company_id ";
        $where[] = "tl.status IN ('active','renewed')";
        $where[] = 'tu.building_id = ?';
        $params[] = $buildingId;
    } elseif ($target === 'units') {
        $unitIds = array_values(array_filter(array_map('intval', (array)($post['unit_ids'] ?? []))));
        if ($unitIds === []) return [];
        $baseSelect .= " INNER JOIN re_leases tl ON tl.tenant_id = tpu.tenant_id AND tl.company_id = tpu.company_id
                         INNER JOIN re_units tu ON tu.id = tl.unit_id AND tu.company_id = tl.company_id ";
        $where[] = "tl.status IN ('active','renewed')";
        $where[] = 'tu.id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')';
        array_push($params, ...$unitIds);
    } elseif ($target !== 'all') {
        return [];
    }

    $stmt = $conn->prepare($baseSelect . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY tpu.display_name, tpu.email');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function cp_guest_recipients(PDO $conn, int $companyId, string $target, array $post): array {
    $select = "
        SELECT DISTINCT
            pu.id AS guest_portal_user_id,
            g.id AS guest_id,
            g.company_id,
            CONCAT(g.first_name, ' ', g.last_name) AS display_name,
            g.email,
            b.id AS booking_id
        FROM ars_guests g
        LEFT JOIN portal_users pu ON pu.guest_id = g.id AND pu.company_id = g.company_id AND pu.user_type = 'guest'
        LEFT JOIN ars_bookings b ON b.id = (
            SELECT b2.id
            FROM ars_bookings b2
            WHERE b2.company_id = g.company_id
              AND b2.guest_id = g.id
              AND b2.status IN ('pending','confirmed','checked_in')
              AND b2.check_out >= CURDATE()
            ORDER BY b2.check_in ASC, b2.id DESC
            LIMIT 1
        )
    ";
    $where = ['g.company_id = ?', 'COALESCE(g.is_active, 1) = 1'];
    $params = [$companyId];

    if ($target === 'guest') {
        $guestId = (int)($post['guest_id'] ?? 0);
        if ($guestId <= 0) return [];
        $where[] = 'g.id = ?';
        $params[] = $guestId;
    } elseif ($target === 'active_bookings') {
        $select .= " INNER JOIN ars_bookings ab ON ab.guest_id = g.id AND ab.company_id = g.company_id ";
        $where[] = "ab.status IN ('pending','confirmed','checked_in')";
        $where[] = 'ab.check_out >= CURDATE()';
    } elseif ($target === 'booking') {
        $bookingId = (int)($post['booking_id'] ?? 0);
        if ($bookingId <= 0) return [];
        $select .= " INNER JOIN ars_bookings ab ON ab.guest_id = g.id AND ab.company_id = g.company_id ";
        $where[] = 'ab.id = ?';
        $params[] = $bookingId;
    } elseif ($target === 'unit') {
        $unitId = (int)($post['guest_unit_id'] ?? 0);
        if ($unitId <= 0) return [];
        $select .= " INNER JOIN ars_bookings ab ON ab.guest_id = g.id AND ab.company_id = g.company_id ";
        $where[] = 'ab.unit_id = ?';
        $where[] = 'ab.check_out >= CURDATE()';
        $params[] = $unitId;
    } elseif ($target !== 'all') {
        return [];
    }

    $stmt = $conn->prepare($select . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY display_name, g.email');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$tenantActions = ['payments','maintenance','cleaning','pest','extra_service','renewal','document','general'];
$guestActions = ['booking','payment','lifecycle_request','document','receipt','checkin','checkout','general'];
$flash = $_SESSION['customer_push_flash'] ?? null;
unset($_SESSION['customer_push_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $userType = (string)($_POST['user_type'] ?? 'tenant');
    $targetScope = (string)($_POST['target_scope'] ?? '');
    $actionType = (string)($_POST['action_type'] ?? 'general');
    $title = customer_push_clean($_POST['title'] ?? '', 200);
    $message = customer_push_clean($_POST['message'] ?? '', 500);
    $confirmAll = !empty($_POST['confirm_all']);

    $allowedActions = $userType === 'guest' ? $guestActions : $tenantActions;
    if (!in_array($userType, ['tenant','guest'], true) || !in_array($actionType, $allowedActions, true) || $title === '' || $message === '') {
        $_SESSION['customer_push_flash'] = ['danger', 'Please complete the notification form.'];
        header('Location: customer_push_notifications.php');
        exit;
    }
    if (in_array($targetScope, ['all'], true) && !$confirmAll) {
        $_SESSION['customer_push_flash'] = ['danger', 'Please confirm before sending to all customers.'];
        header('Location: customer_push_notifications.php');
        exit;
    }

    $recipients = $userType === 'tenant'
        ? cp_tenant_recipients($conn, $currentCompanyId, $targetScope, $_POST)
        : cp_guest_recipients($conn, $currentCompanyId, $targetScope, $_POST);

    if ($recipients === []) {
        $_SESSION['customer_push_flash'] = ['warning', 'No recipients matched the selected target.'];
        header('Location: customer_push_notifications.php');
        exit;
    }

    $batch = $conn->prepare("
        INSERT INTO customer_notification_batches
            (company_id, user_type_scope, target_scope, target_label, action_type, title, message, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $batch->execute([
        $currentCompanyId,
        $userType,
        $targetScope,
        customer_push_clean($_POST['target_label'] ?? $targetScope, 255),
        $actionType,
        $title,
        $message,
        current_user_id(),
    ]);
    $batchId = (int)$conn->lastInsertId();

    $totals = ['tokens' => 0, 'sent' => 0, 'failed' => 0, 'invalid' => 0];
    foreach ($recipients as $r) {
        if ($userType === 'tenant') {
            $identity = [
                'company_id' => $currentCompanyId,
                'tenant_portal_user_id' => (int)$r['tenant_portal_user_id'],
                'tenant_id' => (int)$r['tenant_id'],
                'lease_id' => !empty($r['lease_id']) ? (int)$r['lease_id'] : null,
            ];
            $notificationId = tenant_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'tenant_portal_user_id' => $identity['tenant_portal_user_id'],
                'tenant_id' => $identity['tenant_id'],
                'lease_id' => $identity['lease_id'],
                'type' => 'manual_' . $actionType,
                'entity_type' => cp_action_entity_type('tenant', $actionType),
                'title' => $title,
                'body' => $message,
                'skip_push' => true,
            ]);
            $result = customer_push_send_to_identity($conn, 'tenant', $identity, $title, $message, [
                'action_type' => $actionType,
                'entity_type' => cp_action_entity_type('tenant', $actionType),
                'lease_id' => $identity['lease_id'],
            ], $notificationId, null, $batchId, 'tenant_' . $targetScope, current_user_id());
        } else {
            $bookingId = !empty($r['booking_id']) ? (int)$r['booking_id'] : null;
            $identity = [
                'company_id' => $currentCompanyId,
                'guest_portal_user_id' => !empty($r['guest_portal_user_id']) ? (int)$r['guest_portal_user_id'] : null,
                'guest_id' => (int)$r['guest_id'],
                'booking_id' => $bookingId,
            ];
            $notificationId = ars_guest_notification_create($conn, [
                'company_id' => $currentCompanyId,
                'guest_id' => $identity['guest_id'],
                'booking_id' => $bookingId,
                'event_type' => cp_guest_event_type($actionType),
                'title' => $title,
                'message' => $message,
                'cta_route' => $bookingId ? '/guest/bookings/' . $bookingId : '/guest/notifications',
                'meta' => ['manual' => true, 'action_type' => $actionType],
                'skip_push' => true,
            ]);
            $result = customer_push_send_to_identity($conn, 'guest', $identity, $title, $message, [
                'action_type' => $actionType,
                'entity_type' => cp_action_entity_type('guest', $actionType),
                'booking_id' => $bookingId,
            ], null, $notificationId, $batchId, 'guest_' . $targetScope, current_user_id());
        }
        foreach ($totals as $k => $_) {
            $totals[$k] += (int)$result[$k];
        }
    }
    customer_push_update_batch_counts($conn, $batchId, count($recipients), $totals);

    $_SESSION['customer_push_flash'] = [
        'success',
        'Notification batch sent to ' . count($recipients) . ' recipient(s). Tokens: ' . $totals['tokens'] . ', sent: ' . $totals['sent'] . ', failed: ' . $totals['failed'] . ', invalid: ' . $totals['invalid'] . '.',
    ];
    header('Location: customer_push_notifications.php');
    exit;
}

$tenants = $conn->prepare("
    SELECT tpu.id, COALESCE(NULLIF(tpu.display_name, ''), tpu.email, CONCAT('Tenant #', tpu.tenant_id)) AS label, tpu.email
    FROM tenant_portal_users tpu
    WHERE tpu.company_id = ? AND tpu.status = 'approved'
    ORDER BY label
    LIMIT 500
");
$tenants->execute([$currentCompanyId]);
$tenantOptions = $tenants->fetchAll(PDO::FETCH_ASSOC) ?: [];

$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? AND is_active = 1 ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildingOptions = $buildings->fetchAll(PDO::FETCH_ASSOC) ?: [];

$units = $conn->prepare("
    SELECT u.id, CONCAT(b.name, ' - ', u.unit_number) AS label
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id
    WHERE u.company_id = ?
    ORDER BY b.name, u.unit_number
");
$units->execute([$currentCompanyId]);
$unitOptions = $units->fetchAll(PDO::FETCH_ASSOC) ?: [];

$guests = $conn->prepare("
    SELECT id, CONCAT(first_name, ' ', last_name, COALESCE(CONCAT(' - ', email), '')) AS label
    FROM ars_guests
    WHERE company_id = ? AND COALESCE(is_active, 1) = 1
    ORDER BY first_name, last_name
    LIMIT 500
");
$guests->execute([$currentCompanyId]);
$guestOptions = $guests->fetchAll(PDO::FETCH_ASSOC) ?: [];

$bookings = $conn->prepare("
    SELECT b.id, CONCAT(b.booking_number, ' - ', g.first_name, ' ', g.last_name, ' - ', u.unit_number) AS label
    FROM ars_bookings b
    JOIN ars_guests g ON g.id = b.guest_id
    JOIN re_units u ON u.id = b.unit_id
    WHERE b.company_id = ?
    ORDER BY b.created_at DESC
    LIMIT 500
");
$bookings->execute([$currentCompanyId]);
$bookingOptions = $bookings->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recentBatches = $conn->prepare("
    SELECT *
    FROM customer_notification_batches
    WHERE company_id = ?
    ORDER BY id DESC
    LIMIT 10
");
$recentBatches->execute([$currentCompanyId]);
$batchRows = $recentBatches->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Communication Center — Quick Push';
require_once __DIR__ . '/includes/re_layout_header.php';
$ccTab = 'push';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="page-header-label">Tenant Communication Center</div>
        <div class="text-muted">Quick Push — manual Firebase + in-app notifications for Tenant and ARS Guest modes.</div>
    </div>
</div>

<?php require __DIR__ . '/includes/re_communication_center_tabs.php'; ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
<?php endif; ?>

<div class="alert alert-info">
    Firebase credentials are loaded from <code>FIREBASE_SERVICE_ACCOUNT_PATH</code> on the server. Keep the service account JSON outside the public webroot.
</div>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="POST" id="pushForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="target_label" id="targetLabel" value="">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Customer Mode</label>
                    <select name="user_type" id="userType" class="form-select">
                        <option value="tenant">Tenant Mode</option>
                        <option value="guest">ARS Guest Mode</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tenant Target</label>
                    <select name="target_scope" id="tenantTarget" class="form-select target-select" data-mode="tenant">
                        <option value="tenant">One tenant</option>
                        <option value="building">Tenants by building</option>
                        <option value="units">Tenants by selected units</option>
                        <option value="all">All tenants</option>
                    </select>
                    <select name="guest_target_scope" id="guestTargetMirror" class="form-select target-select d-none" data-mode="guest" disabled>
                        <option value="guest">One ARS guest</option>
                        <option value="active_bookings">Guests with active/upcoming bookings</option>
                        <option value="booking">Guests by booking</option>
                        <option value="unit">Guests by unit</option>
                        <option value="all">All ARS guests</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tenant Action</label>
                    <select name="action_type" id="tenantAction" class="form-select action-select" data-mode="tenant">
                        <?php foreach ($tenantActions as $a): ?><option value="<?= h($a) ?>"><?= h(ucwords(str_replace('_', ' ', $a))) ?></option><?php endforeach; ?>
                    </select>
                    <select name="guest_action_type" id="guestActionMirror" class="form-select action-select d-none" data-mode="guest" disabled>
                        <?php foreach ($guestActions as $a): ?><option value="<?= h($a) ?>"><?= h(ucwords(str_replace('_', ' ', $a))) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Confirm All Sends</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="confirm_all" value="1" id="confirmAll">
                        <label class="form-check-label" for="confirmAll">Required for all tenants/guests</label>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-2 tenant-field">
                <div class="col-md-4 target-field" data-target="tenant">
                    <label class="form-label">Tenant</label>
                    <select name="tenant_portal_user_id" class="form-select">
                        <option value="">Select tenant</option>
                        <?php foreach ($tenantOptions as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 target-field d-none" data-target="building">
                    <label class="form-label">Building</label>
                    <select name="building_id" class="form-select">
                        <option value="">Select building</option>
                        <?php foreach ($buildingOptions as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 target-field d-none" data-target="units">
                    <label class="form-label">Units</label>
                    <select name="unit_ids[]" class="form-select" multiple size="6">
                        <?php foreach ($unitOptions as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['label']) ?></option><?php endforeach; ?>
                    </select>
                    <small class="text-muted">Hold Cmd/Ctrl to select multiple units.</small>
                </div>
            </div>

            <div class="row g-3 mt-2 guest-field d-none">
                <div class="col-md-4 guest-target-field" data-target="guest">
                    <label class="form-label">ARS Guest</label>
                    <select name="guest_id" class="form-select">
                        <option value="">Select guest</option>
                        <?php foreach ($guestOptions as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 guest-target-field d-none" data-target="booking">
                    <label class="form-label">Booking</label>
                    <select name="booking_id" class="form-select">
                        <option value="">Select booking</option>
                        <?php foreach ($bookingOptions as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 guest-target-field d-none" data-target="unit">
                    <label class="form-label">Unit</label>
                    <select name="guest_unit_id" class="form-select">
                        <option value="">Select unit</option>
                        <?php foreach ($unitOptions as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-3">
                <div class="col-md-5">
                    <label class="form-label">Title</label>
                    <input name="title" class="form-control" maxlength="200" required placeholder="Short notification title">
                </div>
                <div class="col-md-7">
                    <label class="form-label">Message</label>
                    <input name="message" class="form-control" maxlength="500" required placeholder="Short message only. No private financial/document details.">
                </div>
            </div>

            <div class="mt-4">
                <button class="btn btn-primary"><i class="bi bi-send"></i> Send Notification</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-body">
        <h5 class="mb-3">Recent Manual Batches</h5>
        <?php if (!$batchRows): ?>
            <p class="text-muted mb-0">No push batches yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Date</th><th>Mode</th><th>Target</th><th>Title</th><th>Recipients</th><th>Tokens</th><th>Sent</th><th>Failed</th><th>Invalid</th></tr></thead>
                    <tbody>
                    <?php foreach ($batchRows as $b): ?>
                        <tr>
                            <td><?= h($b['created_at']) ?></td>
                            <td><?= h($b['user_type_scope']) ?></td>
                            <td><?= h($b['target_scope']) ?></td>
                            <td><?= h($b['title']) ?></td>
                            <td><?= (int)$b['recipient_count'] ?></td>
                            <td><?= (int)$b['token_count'] ?></td>
                            <td><?= (int)$b['sent_count'] ?></td>
                            <td><?= (int)$b['failed_count'] ?></td>
                            <td><?= (int)$b['invalid_token_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
  const userType = document.getElementById('userType');
  const tenantTarget = document.getElementById('tenantTarget');
  const guestTarget = document.getElementById('guestTargetMirror');
  const tenantAction = document.getElementById('tenantAction');
  const guestAction = document.getElementById('guestActionMirror');
  const targetLabel = document.getElementById('targetLabel');

  function sync() {
    const mode = userType.value;
    const isGuest = mode === 'guest';
    document.querySelectorAll('.tenant-field').forEach(el => el.classList.toggle('d-none', isGuest));
    document.querySelectorAll('.guest-field').forEach(el => el.classList.toggle('d-none', !isGuest));
    tenantTarget.classList.toggle('d-none', isGuest);
    tenantTarget.disabled = isGuest;
    guestTarget.classList.toggle('d-none', !isGuest);
    guestTarget.disabled = !isGuest;
    tenantAction.classList.toggle('d-none', isGuest);
    tenantAction.disabled = isGuest;
    guestAction.classList.toggle('d-none', !isGuest);
    guestAction.disabled = !isGuest;
    if (isGuest) {
      guestTarget.name = 'target_scope';
      guestAction.name = 'action_type';
      tenantTarget.name = 'tenant_target_scope';
      tenantAction.name = 'tenant_action_type';
    } else {
      tenantTarget.name = 'target_scope';
      tenantAction.name = 'action_type';
      guestTarget.name = 'guest_target_scope';
      guestAction.name = 'guest_action_type';
    }
    const target = isGuest ? guestTarget.value : tenantTarget.value;
    document.querySelectorAll('.target-field').forEach(el => el.classList.toggle('d-none', el.dataset.target !== target));
    document.querySelectorAll('.guest-target-field').forEach(el => el.classList.toggle('d-none', el.dataset.target !== target));
    targetLabel.value = (isGuest ? guestTarget : tenantTarget).selectedOptions[0]?.textContent || target;
  }
  [userType, tenantTarget, guestTarget].forEach(el => el.addEventListener('change', sync));
  sync();
})();
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
