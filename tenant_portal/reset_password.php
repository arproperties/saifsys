<?php
/**
 * Tenant Portal — Reset password (valid token required)
 * GET/POST with token; validates token then allows setting new password.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');
$valid_reset = null; // set when token is valid (email + row info for display)

function tenant_portal_reset_account_type(PDO $conn, string $email): ?string {
    $email = strtolower(trim($email));

    $stmt = $conn->prepare("
        SELECT id
        FROM tenant_portal_users
        WHERE LOWER(TRIM(email)) = ? AND status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        return 'tenant_portal_users';
    }

    $stmt = $conn->prepare("
        SELECT u.id
        FROM user u
        JOIN tenant_portal_accounts tpa ON tpa.user_id = u.id AND tpa.status = 'approved'
        WHERE (LOWER(TRIM(u.email)) = ? OR LOWER(TRIM(u.username)) = ?)
          AND COALESCE(u.is_active, 1) = 1
        LIMIT 1
    ");
    $stmt->execute([$email, $email]);
    if ($stmt->fetchColumn()) {
        return 'legacy_user';
    }

    return null;
}

if ($token === '') {
    $error = 'Invalid or missing reset link. Please use the link from your email or request a new one from the forgot password page.';
} else {
    $token_hash = hash('sha256', $token);
    $stmt = $conn->prepare("
        SELECT id, email, expires_at, used_at
        FROM tenant_portal_password_resets
        WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token_hash]);
    $valid_reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$valid_reset) {
        $error = 'This link has expired or has already been used. Please go to the forgot password page and request a new link.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $token_post = trim($_POST['token'] ?? '');
    if ($token_post && !$valid_reset) {
        $token_hash = hash('sha256', $token_post);
        $stmt = $conn->prepare("
            SELECT id, email, expires_at, used_at
            FROM tenant_portal_password_resets
            WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token_hash]);
        $valid_reset = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($valid_reset && $error === '') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $email = $valid_reset['email'];
            $token_hash = hash('sha256', $token_post ?: $token);
            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            $conn->beginTransaction();
            try {
                $accountType = tenant_portal_reset_account_type($conn, $email);
                if ($accountType === 'tenant_portal_users') {
                    $up = $conn->prepare("
                        UPDATE tenant_portal_users
                        SET password_hash = ?, updated_at = NOW()
                        WHERE LOWER(TRIM(email)) = ? AND status = 'approved'
                    ");
                    $up->execute([$password_hash, strtolower(trim($email))]);
                } elseif ($accountType === 'legacy_user') {
                    $up = $conn->prepare("
                        UPDATE user u
                        JOIN tenant_portal_accounts tpa ON tpa.user_id = u.id AND tpa.status = 'approved'
                        SET u.password = ?
                        WHERE (LOWER(TRIM(u.email)) = ? OR LOWER(TRIM(u.username)) = ?)
                          AND COALESCE(u.is_active, 1) = 1
                    ");
                    $emailLookup = strtolower(trim($email));
                    $up->execute([$password_hash, $emailLookup, $emailLookup]);
                } else {
                    throw new Exception('User not found or not approved');
                }
                if ($up->rowCount() === 0) {
                    throw new Exception('User not found or not approved');
                }
                $conn->prepare("UPDATE tenant_portal_password_resets SET used_at = NOW() WHERE token_hash = ?")->execute([$token_hash]);
                $conn->commit();
                $_SESSION['tenant_portal_password_reset_success'] = 1;
                header('Location: login.php?reset=1');
                exit;
            } catch (Exception $e) {
                $conn->rollBack();
                $error = (defined('APP_ENV') && APP_ENV === 'dev')
                    ? 'Something went wrong: ' . htmlspecialchars($e->getMessage())
                    : 'Something went wrong. Please try again or contact management.';
            }
        }
    }
}

$login_url = 'login.php';
$forgot_url = 'forgot_password.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f4c75">
    <title>Reset password — Tenant Portal</title>
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
            <h1 class="h4 text-center mb-1">Reset password</h1>
            <p class="text-muted text-center small mb-4">Choose a new password for your Tenant Portal account.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <div class="d-flex flex-column flex-sm-row gap-2">
                    <a href="<?= htmlspecialchars($forgot_url) ?>" class="btn btn-outline-primary">Request new link</a>
                    <a href="<?= htmlspecialchars($login_url) ?>" class="btn btn-primary">Back to login</a>
                </div>
            <?php elseif ($valid_reset): ?>
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label for="password" class="form-label">New password</label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="8" placeholder="At least 8 characters" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">Confirm password</label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-3">Set new password</button>
                </form>
                <p class="mb-0 text-center small">
                    <a href="<?= htmlspecialchars($login_url) ?>" class="forgot-link">Back to login</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
