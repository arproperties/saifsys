<?php
require_once __DIR__ . '/includes/portal_auth.php';

if (is_portal_guest()) {
    header('Location: ' . portal_base_url() . '/dashboard.php');
    exit;
}

$arsCompanyId = getArsCompanyId($conn);
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['password_confirm'] ?? '';

    if (!$firstName || !$lastName || !$email || !$password) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM portal_users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $error = 'An account with this email already exists. <a href="login.php">Login instead?</a>';
        } else {
            try {
                $conn->beginTransaction();

                $conn->prepare("
                    INSERT INTO ars_guests (company_id, first_name, last_name, email, phone, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ")->execute([$arsCompanyId, $firstName, $lastName, $email, $phone]);
                $guestId = (int)$conn->lastInsertId();

                $verifyToken = bin2hex(random_bytes(32));
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $conn->prepare("
                    INSERT INTO portal_users (company_id, user_type, guest_id, email, password_hash, display_name, phone, status, verification_token)
                    VALUES (?, 'guest', ?, ?, ?, ?, ?, 'active', ?)
                ")->execute([$arsCompanyId, $guestId, $email, $passwordHash, $firstName . ' ' . $lastName, $phone, $verifyToken]);
                $portalUserId = (int)$conn->lastInsertId();

                $conn->prepare("UPDATE ars_guests SET portal_user_id = ? WHERE id = ?")->execute([$portalUserId, $guestId]);
                $conn->prepare("UPDATE portal_users SET email_verified_at = NOW(), status = 'active' WHERE id = ?")->execute([$portalUserId]);

                $conn->commit();

                $portalUser = ['id' => $portalUserId, 'guest_id' => $guestId, 'company_id' => $arsCompanyId];
                $guest = ['first_name' => $firstName, 'last_name' => $lastName];
                portal_login_set_session($portalUser, $guest);

                header('Location: ' . portal_base_url() . '/dashboard.php');
                exit;

            } catch (PDOException $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

$pageTitle = 'Sign Up';
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container">
    <div class="auth-wrapper">
        <div class="auth-card">
            <h2><i class="bi bi-person-plus me-2"></i>Create Account</h2>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= $error ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required value="<?= h($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required value="<?= h($_POST['last_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email *</label>
                    <input type="email" name="email" class="form-control" required value="<?= h($_POST['email'] ?? '') ?>" placeholder="your@email.com">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="tel" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>" placeholder="+971 50 000 0000">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="6" placeholder="At least 6 characters">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Confirm Password *</label>
                    <input type="password" name="password_confirm" class="form-control" required placeholder="Repeat password">
                </div>
                <button type="submit" class="btn btn-portal w-100 btn-lg">Create Account</button>
            </form>

            <hr>
            <p class="text-center text-muted mb-0">
                Already have an account? <a href="login.php" class="fw-semibold text-decoration-none" style="color:var(--portal-primary)">Login</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
