<?php
/**
 * Main ERP — Reset password via emailed token.
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
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$validReset = null;

if (!user_password_resets_table_ready($conn)) {
    $error = 'Password reset is not available yet. Please contact an administrator.';
} elseif ($token === '') {
    $error = 'Invalid or missing reset link. Please use the link from your email or request a new one.';
} else {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("
        SELECT r.id, r.user_id, r.email, r.expires_at, r.used_at, u.username, u.fullname
        FROM user_password_resets r
        JOIN `user` u ON u.id = r.user_id
        WHERE r.token_hash = ?
          AND r.used_at IS NULL
          AND r.expires_at > NOW()
          AND COALESCE(u.is_active, 1) = 1
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $validReset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$validReset) {
        // Distinguish used vs expired vs unknown for clearer UX (token itself is not revealed).
        $chk = $conn->prepare("
            SELECT used_at, expires_at, (expires_at > NOW()) AS not_expired
            FROM user_password_resets
            WHERE token_hash = ?
            LIMIT 1
        ");
        $chk->execute([$tokenHash]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['used_at'])) {
            $error = 'This reset link has already been used. Please request a new one if you still need to change your password.';
        } elseif ($row && empty($row['not_expired'])) {
            $error = 'This reset link has expired. Please request a new one.';
        } else {
            $error = 'This reset link is not valid. If you requested several times, open the newest email, or request a new link.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validReset && $error === '') {
    csrf_verify();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $tokenHash = hash('sha256', $token);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $userId = (int)$validReset['user_id'];

        $conn->beginTransaction();
        try {
            $up = $conn->prepare("UPDATE `user` SET password = ?, updated_at = NOW() WHERE id = ?");
            $up->execute([$passwordHash, $userId]);
            if ($up->rowCount() === 0) {
                throw new RuntimeException('User not found.');
            }

            $conn->prepare("UPDATE user_password_resets SET used_at = NOW() WHERE token_hash = ?")
                ->execute([$tokenHash]);

            // Invalidate other unused tokens for this user.
            $conn->prepare("
                UPDATE user_password_resets
                   SET used_at = NOW()
                 WHERE user_id = ? AND used_at IS NULL
            ")->execute([$userId]);

            // Drop remember-me sessions so old devices cannot stay signed in.
            try {
                $conn->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$userId]);
            } catch (Throwable $ignored) {
            }

            $conn->commit();

            try {
                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::logEvent([
                    'action' => 'password_reset_completed',
                    'module' => 'auth',
                    'object_type' => 'user',
                    'object_id' => (string)$userId,
                    'object_ref' => (string)($validReset['username'] ?? ''),
                    'summary' => 'Password reset completed via email link',
                    'source' => 'user',
                    'success' => true,
                    'user_id' => $userId,
                    'user_name' => (string)($validReset['username'] ?? ''),
                ]);
            } catch (Throwable $ignored) {
            }

            header('Location: login?reset=1');
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = 'Something went wrong. Please try again or contact an administrator.';
            error_log('reset_password failed: ' . $e->getMessage());
        }
    }
}

$forgotUrl = 'forgot_password';
$loginUrl = 'login';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Reset password – <?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?></title>
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
      <h1 class="h4 mb-1">Set a new password</h1>
      <?php if ($validReset && $error === ''): ?>
        <p class="text-muted small mb-0">
          Account:
          <strong><?= htmlspecialchars((string)($validReset['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
        </p>
      <?php else: ?>
        <p class="text-muted small mb-0">Choose a strong password for your account.</p>
      <?php endif; ?>
    </div>

    <?php if ($error !== '' && (!$validReset || $_SERVER['REQUEST_METHOD'] !== 'POST')): ?>
      <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <div class="d-grid gap-2">
        <a class="btn btn-outline-primary" href="<?= htmlspecialchars($forgotUrl) ?>">Request new link</a>
        <a class="btn btn-link" href="<?= htmlspecialchars($loginUrl) ?>">Back to sign in</a>
      </div>
    <?php elseif ($validReset): ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="new-password">
        <?php csrf_field(); ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-3">
          <label class="form-label" for="password">New password</label>
          <input type="password" class="form-control" name="password" id="password" required minlength="8" autofocus>
          <div class="form-text">At least 8 characters.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password_confirm">Confirm password</label>
          <input type="password" class="form-control" name="password_confirm" id="password_confirm" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">Update password</button>
      </form>
      <div class="text-center small">
        <a href="<?= htmlspecialchars($loginUrl) ?>">Back to sign in</a>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
