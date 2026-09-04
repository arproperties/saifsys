<?php
require_once __DIR__ . '/includes/portal_auth.php';

if (is_portal_guest()) {
    header('Location: ' . portal_base_url() . '/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare("
            SELECT pu.*, ag.first_name, ag.last_name
            FROM portal_users pu
            LEFT JOIN ars_guests ag ON ag.id = pu.guest_id
            WHERE pu.email = ? AND pu.user_type = 'guest'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email or password.';
        } elseif ($user['status'] === 'suspended') {
            $error = 'Your account has been suspended. Please contact support.';
        } elseif ($user['status'] === 'unverified') {
            $error = 'Please verify your email before logging in.';
        } else {
            $guest = ['first_name' => $user['first_name'] ?? '', 'last_name' => $user['last_name'] ?? ''];
            portal_login_set_session($user, $guest);

            $conn->prepare("UPDATE portal_users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

            $next = $_SESSION['portal_next'] ?? '';
            unset($_SESSION['portal_next']);
            $dest = $next ?: portal_base_url() . '/dashboard.php';
            header('Location: ' . $dest);
            exit;
        }
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container">
    <div class="auth-wrapper">
        <div class="auth-card">
            <h2><i class="bi bi-house-heart me-2"></i>Welcome Back</h2>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control form-control-lg" required
                           value="<?= h($_POST['email'] ?? '') ?>" placeholder="your@email.com">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Password</label>
                    <input type="password" name="password" class="form-control form-control-lg" required placeholder="Enter password">
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <a href="forgot_password.php" class="text-decoration-none small">Forgot password?</a>
                </div>
                <button type="submit" class="btn btn-portal w-100 btn-lg">Login</button>
            </form>

            <hr>
            <p class="text-center text-muted mb-0">
                Don't have an account? <a href="register.php" class="fw-semibold text-decoration-none" style="color:var(--portal-primary)">Sign Up</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
