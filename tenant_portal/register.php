<?php
/**
 * Tenant Portal — Self-registration (lease number + email or phone)
 * Sends verification email; tenant completes signup in verify.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $lease_number = trim($_POST['lease_number'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $is_email = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
    $is_phone = preg_match('/^[\d\s\-\+\(\)]{8,20}$/', $contact) === 1;

    if ($lease_number === '' || $contact === '') {
        $error = 'Please enter lease number and email or phone number.';
    } elseif (!$is_email && !$is_phone) {
        $error = 'Please enter a valid email address or phone number.';
    } else {
        // Find lease and tenant; contact must match tenant email or phone
        $stmt = $conn->prepare("
            SELECT l.id AS lease_id, l.company_id, l.lease_number, l.tenant_id,
                   t.first_name, t.last_name, t.email, t.phone
            FROM re_leases l
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.lease_number = ? AND l.status IN ('active', 'expired')
            LIMIT 1
        ");
        $stmt->execute([$lease_number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $error = 'No active or recent lease found with this lease number. Please check and try again, or contact management.';
        } else {
            $tenant_email = $row['email'] ? trim(strtolower($row['email'])) : '';
            $tenant_phone = preg_replace('/\s+/', '', $row['phone'] ?? '');
            $contact_normalized = $is_email ? strtolower($contact) : preg_replace('/\s+/', '', $contact);
            $match = false;
            if ($is_email && $tenant_email && $tenant_email === $contact_normalized) {
                $match = true;
            }
            if ($is_phone && $tenant_phone && $tenant_phone === $contact_normalized) {
                $match = true;
            }
            // Also allow partial phone match (last 8 digits) for flexibility
            if ($is_phone && $tenant_phone && strlen($contact_normalized) >= 8 && substr($tenant_phone, -8) === substr($contact_normalized, -8)) {
                $match = true;
            }

            if (!$match) {
                $error = 'The email or phone you entered does not match our records for this lease. Please use the contact details on your lease, or contact management.';
            } else {
                // Check if this lease already has an approved portal account (new or legacy)
                $chk = $conn->prepare("
                    (SELECT 1 FROM tenant_portal_users WHERE lease_id = ? AND status = 'approved' LIMIT 1)
                    UNION ALL
                    (SELECT 1 FROM tenant_portal_accounts WHERE lease_id = ? AND status = 'approved' LIMIT 1)
                ");
                $chk->execute([$row['lease_id'], $row['lease_id']]);
                if ($chk->fetchColumn()) {
                    $error = 'A portal account already exists for this lease. Please use the login page or reset your password.';
                } else {
                    $tenant_name = trim($row['first_name'] . ' ' . $row['last_name']);
                    $lease_num = $row['lease_number'];

                    if ($is_email) {
                        // Create verification token (24h validity)
                        $token = bin2hex(random_bytes(32));
                        $token_hash = hash('sha256', $token);
                        $expires = date('Y-m-d H:i:s', time() + 24 * 3600);
                        $conn->prepare("
                            INSERT INTO tenant_portal_verification_codes (lease_id, email_or_phone, code_hash, expires_at)
                            VALUES (?, ?, ?, ?)
                        ")->execute([$row['lease_id'], $contact, $token_hash, $expires]);

                        $verify_url = tenant_portal_url('verify.php?token=' . urlencode($token));
                        $stmt = $conn->prepare("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1");
                        $stmt->execute();
                        $emailSettings = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($emailSettings) {
                            $subject = 'Complete your Tenant Portal registration';
                            $html = "
                            <html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                            <p>Hello " . htmlspecialchars($tenant_name) . ",</p>
                            <p>You requested access to the Tenant Portal for lease <strong>" . htmlspecialchars($lease_num) . "</strong>.</p>
                            <p>Click the link below to set your password and complete registration (link valid for 24 hours):</p>
                            <p><a href='" . htmlspecialchars($verify_url) . "' style='display:inline-block; padding:10px 20px; background:#007bff; color:#fff; text-decoration:none; border-radius:6px;'>Set password &amp; complete registration</a></p>
                            <p>Or copy this link into your browser:</p>
                            <p style='word-break:break-all; color:#666;'>" . htmlspecialchars($verify_url) . "</p>
                            <p>If you did not request this, please ignore this email.</p>
                            <p>— Property Management</p>
                            </body></html>";
                            require_once __DIR__ . '/../includes/mailer.php';
                            $result = send_smtp_mail($emailSettings, $contact, $subject, $html);
                            if ($result['ok']) {
                                $success = 'A verification link has been sent to your email. Please check your inbox and click the link to set your password and complete registration. The link expires in 24 hours.';
                            } else {
                                $error = 'We could not send the verification email. Please try again later or contact management.';
                                $conn->prepare("DELETE FROM tenant_portal_verification_codes WHERE lease_id = ? AND code_hash = ?")->execute([$row['lease_id'], $token_hash]);
                            }
                        } else {
                            $error = 'Email is not configured. Please contact management to complete registration.';
                            $conn->prepare("DELETE FROM tenant_portal_verification_codes WHERE lease_id = ? AND code_hash = ?")->execute([$row['lease_id'], $token_hash]);
                        }
                    } else {
                        $success = 'Verification by phone is not yet available. Please contact management with your lease number and we will send you an invitation by email.';
                    }
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
    <title>Tenant Portal — Register</title>
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
                        <p class="text-muted small mb-4">Enter your lease number and the email or phone on your lease. We will send you a link to set your password. Your account will be activated after admin approval.</p>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= nl2br(htmlspecialchars($success)) ?></div>
                            <a href="<?= htmlspecialchars($login_url) ?>" class="btn btn-primary w-100">Go to login</a>
                        <?php else: ?>
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <div class="mb-3">
                                    <label for="lease_number" class="form-label">Lease number</label>
                                    <input type="text" class="form-control" id="lease_number" name="lease_number" value="<?= htmlspecialchars($_POST['lease_number'] ?? '') ?>" required placeholder="e.g. 101-0001">
                                </div>
                                <div class="mb-3">
                                    <label for="contact" class="form-label">Email or phone (as on lease)</label>
                                    <input type="text" class="form-control" id="contact" name="contact" value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>" required placeholder="email@example.com or +971...">
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Send verification link</button>
                            </form>
                            <hr class="my-4">
                            <p class="mb-0 text-center small"><a href="<?= htmlspecialchars($login_url) ?>" class="text-decoration-none">Already have an account? Log in</a></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
