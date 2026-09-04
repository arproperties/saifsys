<?php
/**
 * Tenant Portal — Forgot password (request reset by email)
 * Sends reset link to tenant_portal_users (approved) by email.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

$error = '';
$success = '';

function tenant_portal_find_reset_account(PDO $conn, string $email): ?array {
    $email = strtolower(trim($email));

    $stmt = $conn->prepare("
        SELECT id, email, display_name, 'tenant_portal_users' AS account_type
        FROM tenant_portal_users
        WHERE LOWER(TRIM(email)) = ? AND status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        return $user;
    }

    $stmt = $conn->prepare("
        SELECT u.id,
               COALESCE(NULLIF(u.email, ''), u.username) AS email,
               COALESCE(NULLIF(u.fullname, ''), u.username) AS display_name,
               'legacy_user' AS account_type
        FROM user u
        JOIN tenant_portal_accounts tpa ON tpa.user_id = u.id AND tpa.status = 'approved'
        WHERE (LOWER(TRIM(u.email)) = ? OR LOWER(TRIM(u.username)) = ?)
          AND COALESCE(u.is_active, 1) = 1
        LIMIT 1
    ");
    $stmt->execute([$email, $email]);
    $legacy = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($legacy && filter_var($legacy['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return $legacy;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $email_lower = strtolower($email);
        $user = tenant_portal_find_reset_account($conn, $email_lower);

        // Always show same message so we don't reveal whether email is registered
        $success = "If that email is registered, we've sent a reset link. Please check your inbox. The link expires in 1 hour.";

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            // Invalidate any existing resets for this email (optional: keep one per email)
            $conn->prepare("DELETE FROM tenant_portal_password_resets WHERE email = ?")->execute([$email_lower]);

            $conn->prepare("
                INSERT INTO tenant_portal_password_resets (email, token_hash, expires_at)
                VALUES (?, ?, ?)
            ")->execute([$email_lower, $token_hash, $expires_at]);

            $reset_url = tenant_portal_url('reset_password.php?token=' . urlencode($token));
            $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
            $stmt->execute();
            $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($emailSettings) {
                $display_name = $user['display_name'] ?: $user['email'];
                $subject = 'Reset your Tenant Portal password';
                $html = "
                <html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                <p>Hello " . htmlspecialchars($display_name) . ",</p>
                <p>You requested a password reset for the Tenant Portal.</p>
                <p>Click the link below to set a new password (link valid for 1 hour):</p>
                <p><a href='" . htmlspecialchars($reset_url) . "' style='display:inline-block; padding:10px 20px; background:#0f4c75; color:#fff; text-decoration:none; border-radius:6px;'>Reset password</a></p>
                <p>Or copy this link into your browser:</p>
                <p style='word-break:break-all; color:#666;'>" . htmlspecialchars($reset_url) . "</p>
                <p>If you did not request this, please ignore this email. Your password will not be changed.</p>
                <p>— Property Management</p>
                </body></html>";
                require_once __DIR__ . '/../includes/mailer.php';
                $result = send_smtp_mail($emailSettings, $user['email'], $subject, $html);
                if (!$result['ok']) {
                    $conn->prepare("DELETE FROM tenant_portal_password_resets WHERE email = ? AND token_hash = ?")->execute([$email_lower, $token_hash]);
                    $error = 'We could not send the reset email. Please try again later or contact management.';
                    $success = '';
                }
            } else {
                $conn->prepare("DELETE FROM tenant_portal_password_resets WHERE email = ? AND token_hash = ?")->execute([$email_lower, $token_hash]);
                $error = 'Email is not configured. Please contact management to reset your password.';
                $success = '';
            }
        }
    }
}

$login_url = 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f4c75">
    <title>Forgot password — Tenant Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --tp-primary: #0f4c75; --tp-primary-light: #1b6ca8; }
        * { font-family: Inter, system-ui, sans-serif; box-sizing: border-box; }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #f0f4f8 0%, #c3cfe2 100%);
            display: flex; align-items: center; justify-content: center;
            padding: 20px; margin: 0;
        }
        .auth-container { width: 100%; max-width: 440px; }
        .auth-card {
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1), 0 0 0 1px rgba(255,255,255,0.5);
        }
        .brand-logo {
            width: 72px; height: 72px; margin: 0 auto 1.25rem;
            background: linear-gradient(135deg, var(--tp-primary), var(--tp-primary-light));
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.5rem; font-weight: 700;
        }
        .form-control:focus { border-color: var(--tp-primary); box-shadow: 0 0 0 0.2rem rgba(15, 76, 117, 0.25); }
        .btn-primary { background: linear-gradient(135deg, var(--tp-primary), var(--tp-primary-light)); border: none; }
        .btn-primary:hover { opacity: 0.95; }
        .forgot-link { color: var(--tp-primary); text-decoration: none; font-weight: 500; }
        .forgot-link:hover { text-decoration: underline; color: var(--tp-primary-light); }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-logo">TP</div>
            <h1 class="h4 text-center mb-1">Forgot password</h1>
            <p class="text-muted text-center small mb-4">Enter your email and we’ll send you a reset link.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <a href="<?= htmlspecialchars($login_url) ?>" class="btn btn-primary w-100">Back to login</a>
            <?php else: ?>
                <form method="post">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="your@email.com" autocomplete="email">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-3">Send reset link</button>
                </form>
                <p class="mb-0 text-center small">
                    <a href="<?= htmlspecialchars($login_url) ?>" class="forgot-link">Back to login</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
