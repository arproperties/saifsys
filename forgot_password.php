<?php
/**
 * Main ERP — Forgot password (request reset link by email).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/password_reset.php';

$brand = getBrandSettings($conn);

if (function_exists('is_logged_in') ? is_logged_in() : (isset($_SESSION['user']['id']) || isset($_SESSION['user_id']))) {
    header('Location: index');
    exit;
}

$error = '';
$success = '';
$MAX_ATTEMPTS = 5;
$WINDOW_SEC = 15 * 60;
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (!isset($_SESSION['rate_forgot'])) {
    $_SESSION['rate_forgot'] = [];
}
$bucket = &$_SESSION['rate_forgot'][$ip];
if (empty($bucket) || (time() - (int)($bucket['ts'] ?? 0)) > $WINDOW_SEC) {
    $bucket = ['c' => 0, 'ts' => time()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($bucket['c'] >= $MAX_ATTEMPTS) {
        http_response_code(429);
        $error = 'Too many attempts. Please try again in a few minutes.';
    } else {
        $bucket['c']++;
        $bucket['ts'] = time();

        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '') {
            $error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!user_password_resets_table_ready($conn)) {
            $error = 'Password reset is not available yet. Please contact an administrator.';
        } else {
            // Same message whether or not the email exists (no account enumeration).
            $success = "If that email is registered, we've sent a reset link. Check your inbox (and spam). The link expires in 1 hour.";

            $user = find_user_for_password_reset($conn, $email);
            if ($user) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $emailLower = strtolower(trim($user['email']));

                invalidate_user_password_resets($conn, (int)$user['id']);
                // Use MySQL NOW() for expiry so PHP/MySQL timezone skew cannot expire the link immediately.
                $conn->prepare("
                    INSERT INTO user_password_resets (user_id, email, token_hash, expires_at, request_ip)
                    VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)
                ")->execute([(int)$user['id'], $emailLower, $tokenHash, substr($ip, 0, 45)]);

                $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
                $stmt->execute();
                $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$emailSettings) {
                    $conn->prepare("DELETE FROM user_password_resets WHERE token_hash = ?")->execute([$tokenHash]);
                    $error = 'Email is not configured. Please contact an administrator to reset your password.';
                    $success = '';
                } else {
                    $resetUrl = password_reset_absolute_url($token);
                    $displayName = $user['fullname'] ?: $user['username'];
                    $systemName = (string)($brand['system_name'] ?? 'HeroSysgro');
                    $subject = 'Reset your ' . $systemName . ' password';
                    $html = '
<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#222">
  <p>Hello ' . htmlspecialchars($displayName) . ',</p>
  <p>We received a request to reset your password for <strong>' . htmlspecialchars($systemName) . '</strong>.</p>
  <p>Username: <strong>' . htmlspecialchars($user['username']) . '</strong></p>
  <p>Click the button below to choose a new password (valid for 1 hour):</p>
  <p>
    <a href="' . htmlspecialchars($resetUrl) . '"
       style="display:inline-block;padding:12px 22px;background:#0f4c75;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">
      Reset password
    </a>
  </p>
  <p>Or copy this link into your browser:</p>
  <p style="word-break:break-all;color:#555">' . htmlspecialchars($resetUrl) . '</p>
  <p>If you did not request this, you can ignore this email. Your password will not change.</p>
  <p>— ' . htmlspecialchars($systemName) . '</p>
</body></html>';

                    require_once __DIR__ . '/includes/mailer.php';
                    $mailResult = send_smtp_mail($emailSettings, $user['email'], $subject, $html);
                    if (empty($mailResult['ok'])) {
                        $conn->prepare("DELETE FROM user_password_resets WHERE token_hash = ?")->execute([$tokenHash]);
                        $error = 'We could not send the reset email. Please try again later or contact an administrator.';
                        $success = '';
                        error_log('forgot_password mail failed: ' . (string)($mailResult['error'] ?? 'unknown'));
                    } else {
                        try {
                            require_once __DIR__ . '/includes/AuditService.php';
                            AuditService::logEvent([
                                'action' => 'password_reset_requested',
                                'module' => 'auth',
                                'object_type' => 'user',
                                'object_id' => (string)$user['id'],
                                'object_ref' => $user['username'],
                                'summary' => 'Password reset email requested',
                                'source' => 'user',
                                'success' => true,
                                'user_id' => (int)$user['id'],
                                'user_name' => $user['username'],
                            ]);
                        } catch (Throwable $ignored) {
                        }
                    }
                }
            }
        }
    }
}

$loginUrl = 'login';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Forgot password – <?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: <?= htmlspecialchars($brand['primary_color'] ?? '#0f4c75', ENT_QUOTES, 'UTF-8') ?>;
      --primary-dark: <?= htmlspecialchars($brand['primary_dark'] ?? '#0a3554', ENT_QUOTES, 'UTF-8') ?>;
    }
    body {
      min-height: 100vh; margin: 0;
      font-family: Inter, system-ui, sans-serif;
      background: linear-gradient(135deg, #0b1c2c 0%, #123a56 45%, #1b4f73 100%);
      display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .card-auth {
      width: 100%; max-width: 440px; background: #fff; border-radius: 22px;
      box-shadow: 0 24px 60px rgba(0,0,0,.28); padding: 2rem 1.75rem;
    }
    .brand { font-family: "Playfair Display", serif; color: var(--primary); font-size: 1.45rem; margin: 0; }
    .btn-primary { background: var(--primary); border-color: var(--primary); }
    .btn-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
    a { color: var(--primary); }
  </style>
</head>
<body>
  <div class="card-auth">
    <div class="mb-3">
      <p class="brand mb-1"><?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?></p>
      <h1 class="h4 mb-1">Forgot password</h1>
      <p class="text-muted small mb-0">Enter the email on your account. We’ll send a secure link to set a new password.</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
      <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($success === ''): ?>
    <form method="post" autocomplete="username">
      <?php csrf_field(); ?>
      <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input type="email" class="form-control" name="email" id="email" required autofocus
               placeholder="name@example.com" value="<?= htmlspecialchars(trim((string)($_POST['email'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <button type="submit" class="btn btn-primary w-100 mb-3">Send reset link</button>
    </form>
    <?php endif; ?>

    <div class="text-center small">
      <a href="<?= htmlspecialchars($loginUrl) ?>"><i class="bi bi-arrow-left"></i> Back to sign in</a>
    </div>
  </div>
</body>
</html>
