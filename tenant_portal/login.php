<?php
/**
 * Tenant Portal — Login
 * Design aligned with main system login: Welcome back, icons, Forgot password, gradient button.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

$dashboard_url = 'dashboard.php';
$register_url = 'register.php';

if (is_tenant_user($conn)) {
    header('Location: ' . $dashboard_url);
    exit;
}

$error = '';
$success = '';
if (!empty($_GET['reset']) && !empty($_SESSION['tenant_portal_password_reset_success'])) {
    $success = 'Your password has been reset. You can now sign in with your new password.';
    unset($_SESSION['tenant_portal_password_reset_success']);
}
$MAX_ATTEMPTS = 5;
$WINDOW_SEC = 15 * 60;
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!isset($_SESSION['tenant_login_rate'])) {
    $_SESSION['tenant_login_rate'] = [];
}
$bucket = &$_SESSION['tenant_login_rate'][$ip];
if (empty($bucket) || (time() - ($bucket['ts'] ?? 0)) > $WINDOW_SEC) {
    $bucket = ['c' => 0, 'ts' => time()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $login = trim($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($bucket['c'] >= $MAX_ATTEMPTS) {
        $error = 'Too many attempts. Please try again in a few minutes.';
    } elseif ($login === '' || $password === '') {
        $error = 'Please enter your email (or username) and password.';
    } else {
        $ok = false;
        $stmt = $conn->prepare("
            SELECT id, tenant_id, lease_id, company_id, email, password_hash, display_name, status
            FROM tenant_portal_users
            WHERE email = ? AND status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$login]);
        $tpu = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tpu && password_verify($password, $tpu['password_hash'])) {
            $ok = true;
            $conn->prepare("UPDATE tenant_portal_users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$tpu['id']]);
            $bucket['c'] = 0;
            $bucket['ts'] = time();
            session_regenerate_id(true);
            $_SESSION[TENANT_PORTAL_USER_SESSION_KEY] = (int)$tpu['id'];
            $_SESSION['tenant_portal_lease_id'] = (int)$tpu['lease_id'];
            $_SESSION['tenant_portal_tenant_id'] = (int)$tpu['tenant_id'];
            $_SESSION['tenant_portal_company_id'] = (int)$tpu['company_id'];
            $_SESSION['tenant_portal_display_name'] = $tpu['display_name'] ?? $tpu['email'];
            $next = $_SESSION['tenant_portal_next'] ?? $dashboard_url;
            unset($_SESSION['tenant_portal_next']);
            $next = preg_replace('#/tenant_portal/tenant_portal/#', '/tenant_portal/', $next);
            header('Location: ' . $next);
            exit;
        }
        if (!$ok) {
            $stmt = $conn->prepare("SELECT u.id, u.username, u.fullname, u.password FROM user u WHERE u.username = ? LIMIT 1");
            $stmt->execute([$login]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $stored = (string)($user['password'] ?? '');
                if ($stored !== '' && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2'))) {
                    $ok = password_verify($password, $stored);
                } elseif ($stored !== '' && preg_match('/^[a-f0-9]{32}$/i', $stored)) {
                    $ok = (md5($password) === strtolower($stored));
                } else {
                    $ok = hash_equals($stored, $password);
                }
                if ($ok) {
                    $stmt = $conn->prepare("SELECT status FROM tenant_portal_accounts WHERE user_id = ? LIMIT 1");
                    $stmt->execute([(int)$user['id']]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$account) {
                        $error = 'This account is not a Tenant Portal account. Please use the main system login.';
                        $ok = false;
                    } elseif ($account['status'] === 'pending_approval') {
                        $error = 'Your account is pending approval. You will be notified when access is activated.';
                        $ok = false;
                    } elseif ($account['status'] !== 'approved') {
                        $error = 'Your portal access has been suspended or revoked. Please contact management.';
                        $ok = false;
                    } else {
                        $bucket['c'] = 0;
                        $bucket['ts'] = time();
                        session_regenerate_id(true);
                        require_once __DIR__ . '/../includes/auth.php';
                        set_logged_in_user((int)$user['id'], $user['username'] ?? null, $user['fullname'] ?? null);
                        $rq = $conn->prepare("SELECT r.id, r.name FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?");
                        $rq->execute([(int)$user['id']]);
                        $roles = $rq->fetchAll(PDO::FETCH_ASSOC);
                        $_SESSION['roles'] = array_column($roles, 'id');
                        $_SESSION['role_names'] = array_column($roles, 'name');
                        $next = $_SESSION['tenant_portal_next'] ?? $dashboard_url;
                        unset($_SESSION['tenant_portal_next']);
                        $next = preg_replace('#/tenant_portal/tenant_portal/#', '/tenant_portal/', $next);
                        header('Location: ' . $next);
                        exit;
                    }
                }
            }
        }
        if (!$ok && $error === '') {
            $bucket['c'] = ($bucket['c'] ?? 0) + 1;
            $bucket['ts'] = time();
            $error = 'Invalid email/username or password.';
        }
    }
}
$systemName = 'Tenant Portal';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Login — <?= htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f4c75">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --tp-login-primary: #0f4c75;
            --tp-login-primary-light: #1b6ca8;
            --tp-login-card-radius: 24px;
            --tp-login-input-radius: 12px;
            --tp-transition: all 0.3s ease;
        }
        * { font-family: Inter, system-ui, -apple-system, sans-serif; box-sizing: border-box; }
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
            border-radius: var(--tp-login-card-radius);
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1), 0 0 0 1px rgba(255,255,255,0.5);
        }
        .brand-logo {
            width: 72px; height: 72px; margin: 0 auto 1.25rem;
            background: linear-gradient(135deg, var(--tp-login-primary), var(--tp-login-primary-light));
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.5rem; font-weight: 700; letter-spacing: 0.5px;
        }
        .auth-title { font-size: 1.75rem; font-weight: 700; color: #1a1a1a; margin-bottom: 0.35rem; }
        .auth-subtitle { color: #6c757d; font-size: 0.95rem; }
        .form-floating-custom { position: relative; margin-bottom: 1.25rem; }
        .form-floating-custom input {
            width: 100%; padding: 1rem 1rem 1rem 3rem;
            border: 2px solid #e0e0e0; border-radius: var(--tp-login-input-radius);
            font-size: 1rem; background: #fafafa; outline: none; transition: var(--tp-transition);
        }
        .form-floating-custom input:focus {
            border-color: var(--tp-login-primary); background: #fff;
            box-shadow: 0 0 0 4px rgba(15, 76, 117, 0.1);
        }
        .form-floating-custom .input-icon {
            position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
            color: #6c757d; font-size: 1.2rem;
        }
        .form-floating-custom input:focus ~ .input-icon { color: var(--tp-login-primary); }
        .form-floating-custom .toggle-password {
            position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: #6c757d; font-size: 1.2rem; cursor: pointer; padding: 0.5rem;
        }
        .btn-login {
            width: 100%; padding: 1rem;
            background: linear-gradient(135deg, var(--tp-login-primary), var(--tp-login-primary-light));
            color: white; border: none; border-radius: var(--tp-login-input-radius);
            font-size: 1.05rem; font-weight: 600; cursor: pointer;
            box-shadow: 0 8px 20px rgba(15, 76, 117, 0.3);
            transition: var(--tp-transition);
        }
        .btn-login:hover { color: white; transform: translateY(-2px); box-shadow: 0 12px 24px rgba(15, 76, 117, 0.35); }
        .alert-error {
            background: #fff0f0; border: 2px solid #ffcdd2; border-radius: var(--tp-login-input-radius);
            padding: 1rem; margin-bottom: 1.25rem; color: #c62828;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .security-badge { text-align: center; margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px solid #e0e0e0; }
        .security-badge .security-text { display: flex; align-items: center; justify-content: center; gap: 0.5rem; color: #6c757d; font-size: 0.85rem; }
        .security-badge .copyright { color: #9e9e9e; font-size: 0.8rem; margin-top: 0.5rem; }
        .forgot-link { color: var(--tp-login-primary); text-decoration: none; font-size: 0.9rem; font-weight: 500; }
        .forgot-link:hover { text-decoration: underline; color: var(--tp-login-primary-light); }
        @media (max-width: 576px) {
            .auth-card { padding: 2rem 1.5rem; }
            .auth-title { font-size: 1.5rem; }
        }
        input[type="text"], input[type="password"] { font-size: 16px !important; }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-logo">TP</div>
            <h1 class="auth-title text-center">Welcome back</h1>
            <p class="auth-subtitle text-center mb-4">Sign in to continue to <?= htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') ?></p>

            <?php if ($success): ?>
                <div class="alert alert-success mb-3" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <form method="post" id="loginForm">
                <?php csrf_field(); ?>
                <div class="form-floating-custom">
                    <i class="input-icon bi bi-person"></i>
                    <input type="text" name="login" id="login" value="<?= htmlspecialchars($_POST['login'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Email or username" required autocomplete="username">
                </div>
                <div class="form-floating-custom">
                    <i class="input-icon bi bi-lock"></i>
                    <input type="password" name="password" id="password" placeholder="Password" required autocomplete="current-password">
                    <button type="button" class="toggle-password" id="togglePassword" tabindex="-1" aria-label="Toggle password">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="d-flex justify-content-end mb-3">
                    <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                </div>
                <button type="submit" class="btn btn-login">Sign In</button>
            </form>

            <hr class="my-4">
            <p class="mb-0 text-center small">
                <a href="<?= htmlspecialchars($register_url) ?>" class="forgot-link">Register for portal access</a>
            </p>

            <div class="security-badge">
                <div class="security-text">
                    <i class="bi bi-shield-check"></i>
                    <span>Secured access</span>
                </div>
                <div class="copyright">© <?= date('Y') ?> <?= htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
    <script>
        var p = document.getElementById('password');
        var t = document.getElementById('togglePassword');
        if (t && p) t.addEventListener('click', function() {
            var type = p.getAttribute('type') === 'password' ? 'text' : 'password';
            p.setAttribute('type', type);
            this.querySelector('i').classList.toggle('bi-eye');
            this.querySelector('i').classList.toggle('bi-eye-slash');
        });
    </script>
</body>
</html>
