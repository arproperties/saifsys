<?php
/**
 * Tenant Portal — Verify token and set password (complete registration)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');
$lease_info = null; // set when token valid, for display

if ($token === '') {
    $error = 'Invalid or missing verification link. Please use the link from your email or request a new one from the registration page.';
} else {
    $token_hash = hash('sha256', $token);
    $stmt = $conn->prepare("
        SELECT v.id, v.lease_id, v.email_or_phone, v.used_at,
               l.company_id, l.lease_number, l.tenant_id,
               t.first_name, t.last_name, t.email
        FROM tenant_portal_verification_codes v
        JOIN re_leases l ON l.id = v.lease_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE v.code_hash = ? AND v.used_at IS NULL AND v.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token_hash]);
    $lease_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lease_info) {
        $error = 'This link has expired or has already been used. Please go to the registration page and request a new verification link.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $token_post = trim($_POST['token'] ?? '');
    if ($token_post && !$lease_info) {
        $token_hash = hash('sha256', $token_post);
        $stmt = $conn->prepare("
            SELECT v.id, v.lease_id, v.email_or_phone, v.used_at,
                   l.company_id, l.lease_number, l.tenant_id,
                   t.first_name, t.last_name, t.email
            FROM tenant_portal_verification_codes v
            JOIN re_leases l ON l.id = v.lease_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE v.code_hash = ? AND v.used_at IS NULL AND v.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token_hash]);
        $lease_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($lease_info && $error === '') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $lease_id = (int)$lease_info['lease_id'];
            $tenant_id = (int)$lease_info['tenant_id'];
            $company_id = (int)$lease_info['company_id'];
            $email = $lease_info['email'] ? trim($lease_info['email']) : '';
            if ($email === '') {
                $error = 'No email on file for this tenant. Portal access requires an email. Please contact management.';
            } else {
            $existing = $conn->prepare("SELECT id FROM tenant_portal_users WHERE lease_id = ? LIMIT 1");
            $existing->execute([$lease_id]);
            if ($existing->fetchColumn()) {
                $error = 'A portal account for this lease already exists. Please use the login page.';
            } else {
                $display_name = trim($lease_info['first_name'] . ' ' . $lease_info['last_name']);
                $password_hash = password_hash($password, PASSWORD_BCRYPT);

                $conn->beginTransaction();
                try {
                    $conn->prepare("
                        INSERT INTO tenant_portal_users (tenant_id, lease_id, company_id, email, password_hash, display_name, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending_approval')
                    ")->execute([$tenant_id, $lease_id, $company_id, $email ?: null, $password_hash, $display_name ?: null]);

                    $conn->prepare("UPDATE tenant_portal_verification_codes SET used_at = NOW() WHERE code_hash = ?")->execute([$token_hash]);

                    $conn->commit();
                    $success = 'Your account has been created. It is pending approval by management. You will be able to log in once approved. We will notify you by email when your access is activated.';
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
}

$base = get_base_path() ? get_base_path() . '/' : '';
$login_url = 'login.php';
$register_url = $base . 'tenant_portal/register.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f4c75">
    <title>Tenant Portal — Set password</title>
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
                        <p class="text-muted small mb-4">Set your password to complete registration</p>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <div class="d-flex flex-column flex-sm-row gap-2">
                                <a href="<?= htmlspecialchars($register_url) ?>" class="btn btn-outline-primary">Back to registration</a>
                                <a href="<?= htmlspecialchars($login_url) ?>" class="btn btn-primary">Go to login</a>
                            </div>
                        <?php elseif ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <a href="<?= htmlspecialchars($login_url) ?>" class="btn btn-primary w-100">Go to login</a>
                        <?php elseif ($lease_info): ?>
                            <p class="text-muted small mb-3">Lease: <strong><?= htmlspecialchars($lease_info['lease_number']) ?></strong>. Choose a password to complete registration.</p>
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required minlength="8" placeholder="At least 8 characters">
                                </div>
                                <div class="mb-3">
                                    <label for="password_confirm" class="form-label">Confirm password</label>
                                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="8">
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Create account</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
