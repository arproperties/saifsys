<?php
/**
 * Tenant Portal — Accept admin invitation (token in link)
 * Tenant sets password; account is created with status approved (no approval step).
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

$error = '';
$success = '';
$invite_info = null;
$token = trim($_GET['token'] ?? '');

if ($token !== '') {
    $token_hash = hash('sha256', $token);
    $stmt = $conn->prepare("
        SELECT i.id, i.lease_id, i.tenant_id, i.used_at,
               l.company_id, l.lease_number,
               t.first_name, t.last_name, t.email
        FROM tenant_portal_invites i
        JOIN re_leases l ON l.id = i.lease_id
        JOIN re_tenants t ON t.id = i.tenant_id
        WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token_hash]);
    $invite_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invite_info) {
        $error = 'This invitation link has expired or has already been used. Please contact management for a new link.';
    }
} else {
    $error = 'Invalid or missing invitation link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite_info && $error === '') {
    csrf_verify();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $lease_id = (int)$invite_info['lease_id'];
        $tenant_id = (int)$invite_info['tenant_id'];
        $company_id = (int)$invite_info['company_id'];
        $email = $invite_info['email'] ? trim($invite_info['email']) : '';
        if ($email === '') {
            $error = 'No email on file for this tenant. Please add an email in the tenant record and try again.';
        } else {
            $existing = $conn->prepare("SELECT id FROM tenant_portal_users WHERE lease_id = ? LIMIT 1");
            $existing->execute([$lease_id]);
            if ($existing->fetchColumn()) {
                $error = 'A portal account for this lease already exists. Please use the login page.';
            } else {
                $display_name = trim($invite_info['first_name'] . ' ' . $invite_info['last_name']);
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                $token_hash = hash('sha256', $token);

                $conn->beginTransaction();
                try {
                    $conn->prepare("
                        INSERT INTO tenant_portal_users (tenant_id, lease_id, company_id, email, password_hash, display_name, status, approved_at, approved_by)
                        VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW(), NULL)
                    ")->execute([$tenant_id, $lease_id, $company_id, $email, $password_hash, $display_name ?: null]);

                    $conn->prepare("UPDATE tenant_portal_invites SET used_at = NOW() WHERE token_hash = ?")->execute([$token_hash]);

                    $conn->commit();
                    $success = 'Your account has been created. You can log in with your email and password.';
                } catch (Exception $e) {
                    $conn->rollBack();
                    $error = (defined('APP_ENV') && APP_ENV === 'dev')
                        ? 'Something went wrong: ' . htmlspecialchars($e->getMessage())
                        : 'Something went wrong. Please try again or contact management.';
                }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f4c75">
    <title>Tenant Portal — Accept invitation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css" rel="stylesheet">
    <link href="includes/tenant_portal.css" rel="stylesheet">
</head>
<body>
    <div class="container py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-6 col-lg-5">
                <div class="portal-card card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="card-title mb-2">Tenant Portal</h4>
                        <p class="text-muted small mb-4">Accept your invitation and set your password</p>
                        <?php if ($error && !$invite_info): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <a href="<?= htmlspecialchars($login_url) ?>" class="btn btn-primary w-100">Go to login</a>
                        <?php elseif ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <a href="<?= htmlspecialchars($login_url) ?>" class="btn btn-primary w-100">Log in</a>
                        <?php elseif ($invite_info): ?>
                            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                            <p class="text-muted small mb-3">You have been invited to access the Tenant Portal for lease <strong><?= htmlspecialchars($invite_info['lease_number']) ?></strong>. Choose a password to activate your account.</p>
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required minlength="8" placeholder="At least 8 characters">
                                </div>
                                <div class="mb-3">
                                    <label for="password_confirm" class="form-label">Confirm password</label>
                                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="8">
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Create account &amp; log in</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
