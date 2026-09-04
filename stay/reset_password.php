<?php
require_once __DIR__ . '/includes/portal_auth.php';

$token = trim($_GET['token'] ?? '');
$error = $success = '';
$validToken = false;

if ($token) {
    $stmt = $conn->prepare("
        SELECT id FROM portal_users
        WHERE reset_token = ? AND reset_token_expires > NOW() AND user_type = 'guest'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $validToken = (bool)$stmt->fetchColumn();
}

if (!$token || !$validToken) {
    $error = 'Invalid or expired reset link. Please request a new one.';
}

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->prepare("
            UPDATE portal_users
            SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL
            WHERE reset_token = ? AND reset_token_expires > NOW()
        ")->execute([$hash, $token]);
        $success = 'Your password has been reset. You can now login.';
        $validToken = false;
    }
}

$pageTitle = 'Reset Password';
require_once __DIR__ . '/includes/portal_layout_header.php';
?>

<div class="container">
    <div class="auth-wrapper">
        <div class="auth-card">
            <h2><i class="bi bi-shield-lock me-2"></i>New Password</h2>

            <?php if ($success): ?>
            <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?= $success ?>
                <a href="login.php" class="fw-semibold">Login now</a>
            </div>
            <?php endif; ?>
            <?php if ($error && !$validToken): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?>
                <a href="forgot_password.php" class="fw-semibold ms-1">Request new link</a>
            </div>
            <?php endif; ?>

            <?php if ($validToken): ?>
            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Password</label>
                    <input type="password" name="password" class="form-control form-control-lg" required minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Confirm Password</label>
                    <input type="password" name="password_confirm" class="form-control form-control-lg" required>
                </div>
                <button type="submit" class="btn btn-portal w-100 btn-lg">Set New Password</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/portal_layout_footer.php'; ?>
