<?php
/**
 * Tenant Portal — Profile (change password)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);

$tpuId = (int)($_SESSION[TENANT_PORTAL_USER_SESSION_KEY] ?? 0);
$isLegacy = !$tpuId && (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (strlen($new) < 8) {
        $message = 'New password must be at least 8 characters.';
        $messageType = 'danger';
    } elseif ($new !== $confirm) {
        $message = 'New passwords do not match.';
        $messageType = 'danger';
    } elseif ($tpuId) {
        $row = $conn->prepare("SELECT password_hash FROM tenant_portal_users WHERE id = ? AND status = 'approved'");
        $row->execute([$tpuId]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'danger';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $conn->prepare("UPDATE tenant_portal_users SET password_hash = ? WHERE id = ?")->execute([$hash, $tpuId]);
            $message = 'Your password has been updated.';
            $messageType = 'success';
        }
    } elseif ($isLegacy) {
        $uid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
        $row = $conn->prepare("SELECT password FROM user WHERE id = ?");
        $row->execute([$uid]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $message = 'Session error. Please log in again.';
            $messageType = 'danger';
        } else {
            $stored = (string)($row['password'] ?? '');
            $ok = false;
            if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
                $ok = password_verify($current, $stored);
            } elseif (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
                $ok = (md5($current) === strtolower($stored));
            } else {
                $ok = hash_equals($stored, $current);
            }
            if (!$ok) {
                $message = 'Current password is incorrect.';
                $messageType = 'danger';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $conn->prepare("UPDATE user SET password = ? WHERE id = ?")->execute([$hash, $uid]);
                $message = 'Your password has been updated.';
                $messageType = 'success';
            }
        }
    } else {
        $message = 'Unable to update password. Please log in again.';
        $messageType = 'danger';
    }
}

require_once __DIR__ . '/includes/tenant_lease_loader.php';
$pageTitle = 'Profile';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Profile</h4>
<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="portal-card card">
    <div class="card-header">Change password</div>
    <div class="card-body">
        <form method="POST" class="mw-500">
            <?php csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label">Current password</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="mb-3">
                <label class="form-label">New password (at least 8 characters)</label>
                <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm new password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary w-100 w-md-auto">Update password</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
