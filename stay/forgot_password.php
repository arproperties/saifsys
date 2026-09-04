<?php
require_once __DIR__ . '/includes/portal_auth.php';

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM portal_users WHERE email = ? AND user_type = 'guest' AND status != 'suspended' LIMIT 1");
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $conn->prepare("UPDATE portal_users SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
                ->execute([$token, $expires, $userId]);

            $resetUrl = portal_base_url() . '/reset_password.php?token=' . urlencode($token);
            // In production, send email with $resetUrl. For now, show it.
        }

        $success = 'If an account with that email exists, a password reset link has been sent.';
    }
}

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container">
    <div class="auth-wrapper">
        <div class="auth-card">
            <h2><i class="bi bi-key me-2"></i>Reset Password</h2>
            <p class="text-muted text-center mb-4">Enter your email and we'll send you a reset link.</p>

            <?php if ($success): ?>
            <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control form-control-lg" required placeholder="your@email.com">
                </div>
                <button type="submit" class="btn btn-portal w-100 btn-lg">Send Reset Link</button>
            </form>

            <hr>
            <p class="text-center text-muted mb-0">
                <a href="login.php" class="fw-semibold text-decoration-none" style="color:var(--portal-primary)">Back to Login</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
