<?php
/**
 * Real Estate — Tenant Portal: pending approvals & send invite
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/url_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);
require_role(['Owner', 'Admin'], $conn); // or a Real Estate manager role

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 0;

$message = '';
$messageType = '';

// Approve / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $account_id = (int)($_POST['account_id'] ?? 0);

    if ($action === 'approve' && $account_id) {
        $stmt = $conn->prepare("
            UPDATE tenant_portal_users
            SET status = 'approved', approved_at = NOW(), approved_by = ?
            WHERE id = ? AND status = 'pending_approval' AND company_id = ?
        ");
        $stmt->execute([current_user_id(), $account_id, $currentCompanyId]);
        if ($stmt->rowCount()) {
            $message = 'Portal access approved.';
            $messageType = 'success';
        }
    } elseif ($action === 'reject' && $account_id) {
        $stmt = $conn->prepare("
            UPDATE tenant_portal_users
            SET status = 'revoked'
            WHERE id = ? AND status = 'pending_approval' AND company_id = ?
        ");
        $stmt->execute([$account_id, $currentCompanyId]);
        if ($stmt->rowCount()) {
            $message = 'Portal request rejected.';
            $messageType = 'success';
        }
    }

    // Send invite (create token and optionally email)
    if ($action === 'send_invite') {
        $lease_id        = (int)($_POST['lease_id'] ?? 0);
        $filter_building = (int)($_POST['building_id'] ?? 0);
        $send_email      = !empty($_POST['send_email']);
        if ($lease_id) {
            $lease = $conn->prepare("
                SELECT l.id, l.lease_number, l.tenant_id, l.company_id, t.email, t.first_name, t.last_name,
                       u.building_id
                FROM re_leases l
                JOIN re_tenants t ON t.id = l.tenant_id
                JOIN re_units u ON u.id = l.unit_id
                WHERE l.id = ? AND l.company_id = ?
            ");
            $lease->execute([$lease_id, $currentCompanyId]);
            $lease = $lease->fetch(PDO::FETCH_ASSOC);
            if ($lease && $filter_building > 0 && (int) $lease['building_id'] !== $filter_building) {
                $message     = 'Selected lease does not match the chosen building.';
                $messageType = 'warning';
            } elseif ($lease) {
                $existing = $conn->prepare("
                    SELECT 1 FROM tenant_portal_users WHERE lease_id = ? AND status = 'approved'
                    UNION ALL
                    SELECT 1 FROM tenant_portal_accounts WHERE lease_id = ? AND status = 'approved' LIMIT 1
                ");
                $existing->execute([$lease_id, $lease_id]);
                if ($existing->fetchColumn()) {
                    $message = 'This lease already has an active portal account.';
                    $messageType = 'warning';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $token);
                    $expires = date('Y-m-d H:i:s', time() + 7 * 24 * 3600);
                    $conn->prepare("
                        INSERT INTO tenant_portal_invites (lease_id, tenant_id, token_hash, expires_at, created_by)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([$lease_id, $lease['tenant_id'], $token_hash, $expires, current_user_id()]);
                    $invite_url = tenant_portal_absolute_url('invite.php?token=' . urlencode($token));

                    if ($send_email && !empty($lease['email'])) {
                        require_once __DIR__ . '/../../includes/mailer.php';
                        $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
                        $stmt->execute();
                        $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($emailSettings) {
                            $name = trim($lease['first_name'] . ' ' . $lease['last_name']);
                            $subject = 'You are invited to the Tenant Portal';
                            $html = "
                            <html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                            <p>Hello " . htmlspecialchars($name) . ",</p>
                            <p>You have been invited to access the Tenant Portal for lease <strong>" . htmlspecialchars($lease['lease_number']) . "</strong>.</p>
                            <p>Click the link below to set your password and activate your account (link valid for 7 days):</p>
                            <p><a href='" . htmlspecialchars($invite_url) . "' style='display:inline-block; padding:10px 20px; background:#007bff; color:#fff; text-decoration:none; border-radius:6px;'>Activate portal account</a></p>
                            <p>Or copy this link: " . htmlspecialchars($invite_url) . "</p>
                            <p>— Property Management</p>
                            </body></html>";
                            $result = send_smtp_mail($emailSettings, $lease['email'], $subject, $html);
                            if ($result['ok']) {
                                $message = 'Invitation sent by email to ' . htmlspecialchars($lease['email']) . '.';
                                $messageType = 'success';
                            } else {
                                $message = 'Invite created but email failed. Share this link with the tenant: ' . $invite_url;
                                $messageType = 'warning';
                            }
                        } else {
                            $message = 'Invite created. Email not configured — share this link with the tenant: ' . $invite_url;
                            $messageType = 'info';
                        }
                    } else {
                        $message = 'Invite created. Share this link with the tenant (valid 7 days): ' . $invite_url;
                        $messageType = 'info';
                    }
                }
            }
        }
    }
}

$pending = $conn->prepare("
    SELECT tpu.id, tpu.lease_id, tpu.created_at, tpu.email,
           l.lease_number, t.first_name, t.last_name
    FROM tenant_portal_users tpu
    JOIN re_leases l ON l.id = tpu.lease_id
    JOIN re_tenants t ON t.id = tpu.tenant_id
    WHERE tpu.company_id = ? AND tpu.status = 'pending_approval'
    ORDER BY tpu.created_at DESC
");
$pending->execute([$currentCompanyId]);
$pending = $pending->fetchAll(PDO::FETCH_ASSOC);

$buildingsForInvite = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildingsForInvite->execute([$currentCompanyId]);
$buildingsForInvite = $buildingsForInvite->fetchAll(PDO::FETCH_ASSOC);

$leasesForInvite = $conn->prepare("
    SELECT l.id, l.lease_number, b.id AS building_id, b.name AS building_name,
           (SELECT 1 FROM tenant_portal_users WHERE lease_id = l.id AND status = 'approved' LIMIT 1) AS has_portal_tpu,
           (SELECT 1 FROM tenant_portal_accounts WHERE lease_id = l.id AND status = 'approved' LIMIT 1) AS has_portal_legacy
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE l.company_id = ? AND l.status IN ('active', 'expired')
    ORDER BY b.name, l.lease_number
");
$leasesForInvite->execute([$currentCompanyId]);
$leasesForInvite = $leasesForInvite->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Tenant Portal';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">Tenant Portal</div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?> alert-dismissible fade show">
        <?= nl2br(htmlspecialchars($message)) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pending">Pending approvals</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#invite">Send invite</button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pending">
        <div class="card">
            <div class="card-body">
                <?php if (empty($pending)): ?>
                    <p class="text-muted mb-0">No pending portal requests.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Lease</th>
                                    <th>Tenant</th>
                                    <th>Email</th>
                                    <th>Requested</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending as $row): ?>
                                    <tr>
                                        <td><?= h($row['lease_number']) ?></td>
                                        <td><?= h($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                        <td><?= h($row['email']) ?></td>
                                        <td><?= date('M j, Y H:i', strtotime($row['created_at'])) ?></td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Reject this portal request?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="account_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="invite">
        <div class="card">
            <div class="card-body">
                <p class="text-muted">Send an invitation link to a tenant so they can set their password and access the portal (no approval step).</p>
                <form method="POST" id="sendInviteForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="send_invite">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label" for="inviteBuilding"><i class="bi bi-building me-1"></i>Building</label>
                            <select name="building_id" id="inviteBuilding" class="form-select">
                                <option value="">All buildings</option>
                                <?php foreach ($buildingsForInvite as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5 col-lg-4">
                            <label class="form-label" for="inviteLease">Lease</label>
                            <select name="lease_id" id="inviteLease" class="form-select" required>
                                <option value="">— Select lease —</option>
                                <?php foreach ($leasesForInvite as $l): ?>
                                    <?php $has_portal = ($l['has_portal_tpu'] ?? 0) || ($l['has_portal_legacy'] ?? 0); if (!$has_portal): ?>
                                        <option value="<?= (int)$l['id'] ?>" data-building-id="<?= (int)$l['building_id'] ?>">
                                            <?= h($l['lease_number']) ?> — <?= h($l['building_name']) ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="send_email" id="send_email" checked>
                                <label class="form-check-label" for="send_email">Send link by email to tenant</label>
                            </div>
                        </div>
                        <div class="col-md-3 col-lg-2">
                            <button type="submit" class="btn btn-primary">Create invite &amp; send</button>
                        </div>
                    </div>
                </form>
                <script>
                (function () {
                    var building = document.getElementById('inviteBuilding');
                    var leaseSel = document.getElementById('inviteLease');
                    if (!building || !leaseSel) return;
                    function filterLeases() {
                        var bid = building.value;
                        leaseSel.querySelectorAll('option').forEach(function (opt) {
                            if (!opt.value) {
                                opt.hidden = false;
                                opt.disabled = false;
                                return;
                            }
                            var ok = !bid || String(opt.getAttribute('data-building-id')) === bid;
                            opt.hidden = !ok;
                            opt.disabled = !ok;
                        });
                        var cur = leaseSel.options[leaseSel.selectedIndex];
                        if (cur && (cur.hidden || cur.disabled)) {
                            leaseSel.value = '';
                        }
                    }
                    building.addEventListener('change', filterLeases);
                    filterLeases();
                })();
                </script>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
