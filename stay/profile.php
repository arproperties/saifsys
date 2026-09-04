<?php
require_once __DIR__ . '/includes/portal_auth.php';
require_portal_login();

$portalUserId = current_portal_user_id();
$guestId      = current_portal_guest_id();

$stmt = $conn->prepare("
    SELECT pu.email, ag.first_name, ag.last_name, ag.phone, ag.nationality
    FROM portal_users pu
    LEFT JOIN ars_guests ag ON ag.id = pu.guest_id
    WHERE pu.id = ?
    LIMIT 1
");
$stmt->execute([$portalUserId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) { header('Location: dashboard.php'); exit; }

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName   = trim($_POST['first_name'] ?? '');
        $lastName    = trim($_POST['last_name'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $nationality = trim($_POST['nationality'] ?? '');

        if (!$firstName || !$lastName) {
            $error = 'First name and last name are required.';
        } else {
            $conn->prepare("UPDATE ars_guests SET first_name = ?, last_name = ?, phone = ?, nationality = ? WHERE id = ?")
                ->execute([$firstName, $lastName, $phone, $nationality, $guestId]);
            $conn->prepare("UPDATE portal_users SET display_name = ?, phone = ? WHERE id = ?")
                ->execute([$firstName . ' ' . $lastName, $phone, $portalUserId]);
            $_SESSION['portal_display_name'] = $firstName . ' ' . $lastName;
            $profile['first_name'] = $firstName;
            $profile['last_name'] = $lastName;
            $profile['phone'] = $phone;
            $profile['nationality'] = $nationality;
            $success = 'Profile updated successfully.';
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $pwStmt = $conn->prepare("SELECT password_hash FROM portal_users WHERE id = ? LIMIT 1");
        $pwStmt->execute([$portalUserId]);
        $hash = $pwStmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $conn->prepare("UPDATE portal_users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $portalUserId]);
            $success = 'Password changed successfully.';
        }
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container py-4">
    <h2 class="fw-bold mb-4"><i class="bi bi-person me-2"></i>My Profile</h2>

    <?php if ($success): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Personal Info -->
        <div class="col-lg-6">
            <div class="portal-card p-4">
                <h5 class="fw-bold mb-3">Personal Information</h5>
                <form method="post">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required value="<?= h($profile['first_name']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required value="<?= h($profile['last_name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" class="form-control bg-light" value="<?= h($profile['email']) ?>" disabled>
                            <small class="text-muted">Email cannot be changed</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Phone</label>
                            <input type="tel" name="phone" class="form-control" value="<?= h($profile['phone']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Nationality</label>
                            <input type="text" name="nationality" class="form-control" value="<?= h($profile['nationality']) ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-portal mt-3">Save Changes</button>
                </form>
            </div>
        </div>

        <!-- Password -->
        <div class="col-lg-6">
            <div class="portal-card p-4">
                <h5 class="fw-bold mb-3">Change Password</h5>
                <form method="post">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">New Password</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-portal">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
