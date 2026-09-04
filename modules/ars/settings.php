<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_stripe.php';
require_once __DIR__ . '/includes/ars_shell.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$arsCompany = get_company($conn, $arsCompanyId);
ars_stripe_ensure_schema($conn);

$success = $error = '';
$currentSettings = getArsSettings($conn, $arsCompanyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pendingExpiry    = max(1, (int)($_POST['pending_expiry_hours'] ?? 24));
    $defaultVat       = max(0, (float)($_POST['default_vat_rate'] ?? 5));
    $checkInTime      = $_POST['default_check_in_time'] ?? '15:00';
    $checkOutTime     = $_POST['default_check_out_time'] ?? '12:00';
    $cleaningCompany  = !empty($_POST['cleaning_company_id']) ? (int)$_POST['cleaning_company_id'] : null;
    $cleaningFee      = max(0, (float)($_POST['default_cleaning_fee'] ?? 0));
    $defaultWorker    = !empty($_POST['default_worker_id']) ? (int)$_POST['default_worker_id'] : null;
    $defaultDriver    = !empty($_POST['default_driver_id']) ? (int)$_POST['default_driver_id'] : null;
    $currency         = trim($_POST['currency'] ?? 'AED') ?: 'AED';
    $bookingEmails    = trim((string)($_POST['booking_notification_emails'] ?? ''));
    $stripeEnabled    = isset($_POST['stripe_enabled']) ? 1 : 0;
    $stripeMode       = ($_POST['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    $stripeCurrency   = strtoupper(trim((string)($_POST['stripe_default_currency'] ?? 'AED'))) ?: 'AED';
    $stripePolicy     = in_array(($_POST['stripe_payment_policy'] ?? 'full'), ['full','deposit','partial'], true)
        ? $_POST['stripe_payment_policy'] : 'full';
    $stripeDepositPct = max(0, min(100, (float)($_POST['stripe_deposit_percentage'] ?? 20)));
    $stripeAutoConfirm = isset($_POST['stripe_auto_confirm']) ? 1 : 0;
    $stripeSuccessUrl = trim((string)($_POST['stripe_success_url'] ?? ''));
    $stripeFailedUrl  = trim((string)($_POST['stripe_failed_url'] ?? ''));
    $financialAdapterEnabled = isset($_POST['financial_adapter_enabled']) ? 1 : 0;
    $stripePkTest = trim((string)($_POST['stripe_publishable_key_test'] ?? ''));
    $stripePkLive = trim((string)($_POST['stripe_publishable_key_live'] ?? ''));
    $stripeSkTest = trim((string)($_POST['stripe_secret_key_test'] ?? ''));
    $stripeSkLive = trim((string)($_POST['stripe_secret_key_live'] ?? ''));
    $stripeWhTest = trim((string)($_POST['stripe_webhook_secret_test'] ?? ''));
    $stripeWhLive = trim((string)($_POST['stripe_webhook_secret_live'] ?? ''));
    $stripeSkTestEnc = $stripeSkTest !== '' ? ars_stripe_encrypt_secret($stripeSkTest) : ($currentSettings['stripe_secret_key_test_enc'] ?? null);
    $stripeSkLiveEnc = $stripeSkLive !== '' ? ars_stripe_encrypt_secret($stripeSkLive) : ($currentSettings['stripe_secret_key_live_enc'] ?? null);
    $stripeWhTestEnc = $stripeWhTest !== '' ? ars_stripe_encrypt_secret($stripeWhTest) : ($currentSettings['stripe_webhook_secret_test_enc'] ?? null);
    $stripeWhLiveEnc = $stripeWhLive !== '' ? ars_stripe_encrypt_secret($stripeWhLive) : ($currentSettings['stripe_webhook_secret_live_enc'] ?? null);

    $stripeCandidate = array_merge($currentSettings, [
        'stripe_enabled' => $stripeEnabled,
        'stripe_mode' => $stripeMode,
        'stripe_publishable_key_test' => $stripePkTest,
        'stripe_secret_key_test_enc' => $stripeSkTestEnc,
        'stripe_webhook_secret_test_enc' => $stripeWhTestEnc,
        'stripe_publishable_key_live' => $stripePkLive,
        'stripe_secret_key_live_enc' => $stripeSkLiveEnc,
        'stripe_webhook_secret_live_enc' => $stripeWhLiveEnc,
        'stripe_default_currency' => $stripeCurrency,
        'stripe_payment_policy' => $stripePolicy,
        'stripe_deposit_percentage' => $stripeDepositPct,
        'stripe_auto_confirm' => $stripeAutoConfirm,
        'stripe_success_url' => $stripeSuccessUrl,
        'stripe_failed_url' => $stripeFailedUrl,
    ]);
    $stripeErrors = ars_stripe_validate_settings($stripeCandidate);
    if ($stripeErrors) {
        $error = implode(' ', $stripeErrors);
    }

    try {
        if ($error !== '') {
            throw new RuntimeException($error);
        }
        $stmt = $conn->prepare("SELECT id FROM ars_company_settings WHERE company_id = ? LIMIT 1");
        $stmt->execute([$arsCompanyId]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $conn->prepare("
                UPDATE ars_company_settings SET
                    pending_expiry_hours = ?, default_vat_rate = ?,
                    default_check_in_time = ?, default_check_out_time = ?,
                    cleaning_company_id = ?, default_cleaning_fee = ?,
                    default_worker_id = ?, default_driver_id = ?,
                    currency = ?, booking_notification_emails = ?,
                    stripe_enabled = ?, stripe_mode = ?,
                    stripe_publishable_key_test = ?, stripe_secret_key_test_enc = ?, stripe_webhook_secret_test_enc = ?,
                    stripe_publishable_key_live = ?, stripe_secret_key_live_enc = ?, stripe_webhook_secret_live_enc = ?,
                    stripe_default_currency = ?, stripe_payment_policy = ?, stripe_deposit_percentage = ?,
                    stripe_auto_confirm = ?, stripe_success_url = ?, stripe_failed_url = ?,
                    updated_at = NOW()
                WHERE company_id = ?
            ")->execute([$pendingExpiry, $defaultVat, $checkInTime, $checkOutTime,
                         $cleaningCompany, $cleaningFee, $defaultWorker, $defaultDriver,
                         $currency, $bookingEmails,
                         $stripeEnabled, $stripeMode,
                         $stripePkTest, $stripeSkTestEnc, $stripeWhTestEnc,
                         $stripePkLive, $stripeSkLiveEnc, $stripeWhLiveEnc,
                         $stripeCurrency, $stripePolicy, $stripeDepositPct,
                         $stripeAutoConfirm, $stripeSuccessUrl ?: null, $stripeFailedUrl ?: null,
                         $arsCompanyId]);
        } else {
            $conn->prepare("
                INSERT INTO ars_company_settings
                    (company_id, pending_expiry_hours, default_vat_rate, default_check_in_time, default_check_out_time,
                     cleaning_company_id, default_cleaning_fee, default_worker_id, default_driver_id, currency,
                     booking_notification_emails, stripe_enabled, stripe_mode,
                     stripe_publishable_key_test, stripe_secret_key_test_enc, stripe_webhook_secret_test_enc,
                     stripe_publishable_key_live, stripe_secret_key_live_enc, stripe_webhook_secret_live_enc,
                     stripe_default_currency, stripe_payment_policy, stripe_deposit_percentage,
                     stripe_auto_confirm, stripe_success_url, stripe_failed_url)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$arsCompanyId, $pendingExpiry, $defaultVat, $checkInTime, $checkOutTime,
                         $cleaningCompany, $cleaningFee, $defaultWorker, $defaultDriver, $currency,
                         $bookingEmails, $stripeEnabled, $stripeMode,
                         $stripePkTest, $stripeSkTestEnc, $stripeWhTestEnc,
                         $stripePkLive, $stripeSkLiveEnc, $stripeWhLiveEnc,
                         $stripeCurrency, $stripePolicy, $stripeDepositPct,
                         $stripeAutoConfirm, $stripeSuccessUrl ?: null, $stripeFailedUrl ?: null]);
        }
        try {
            $col = $conn->query("SHOW COLUMNS FROM ars_company_settings LIKE 'financial_adapter_enabled'");
            if ($col && $col->fetch(PDO::FETCH_ASSOC)) {
                $conn->prepare("UPDATE ars_company_settings SET financial_adapter_enabled = ? WHERE company_id = ?")
                    ->execute([$financialAdapterEnabled, $arsCompanyId]);
            }
        } catch (Throwable $ignored) {}
        $success = 'Settings saved successfully.';
        try {
            require_once __DIR__ . '/../../includes/AuditService.php';
            AuditService::logEvent([
                'action' => 'update',
                'module' => 'ars',
                'company_id' => $arsCompanyId,
                'object_type' => 'ars_company_settings',
                'object_id' => (string)$arsCompanyId,
                'object_ref' => 'ARS settings',
                'summary' => 'Updated ARS company settings (secrets not logged)',
                'new_data' => [
                    'pending_expiry_hours' => $pendingExpiry,
                    'default_vat_rate' => $defaultVat,
                    'stripe_enabled' => $stripeEnabled,
                    'stripe_mode' => $stripeMode,
                    'financial_adapter_enabled' => $financialAdapterEnabled,
                ],
                'source' => 'user',
                'success' => true,
            ]);
        } catch (Throwable $ignored) {}
    } catch (Throwable $e) {
        $error = 'Failed to save settings: ' . $e->getMessage();
    }
}

$settings = getArsSettings($conn, $arsCompanyId);

// Load cleaning companies for dropdown
$cleaningCompanies = [];
try {
    $stmt = $conn->query("SELECT id, name FROM companies WHERE business_type = 'cleaning' AND is_active = 1 ORDER BY name");
    $cleaningCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Load workers for cleaner dropdown
$workers = [];
try {
    $stmt = $conn->query("SELECT id, worker_name, nickname FROM workers ORDER BY worker_name");
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Load drivers for driver dropdown (separate table)
$drivers = [];
try {
    $stmt = $conn->query("SELECT id, driver_name, nickname FROM driver ORDER BY nickname");
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$pageTitle = 'Settings';
ars_shell_begin([
    'title' => 'Settings',
    'subtitle' => 'Company: ' . ($arsCompany['name'] ?? ('#' . $arsCompanyId))
        . ' · Currency: ' . ($settings['currency'] ?? 'AED')
        . ' · Company-level ARS configuration',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Settings'],
    ],
    'legacy_bootstrap' => true,
]);
?>


<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<form method="POST" class="needs-validation" novalidate>
<?php csrf_field(); ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="ars-card">
                <div class="card-header"><i class="bi bi-currency-exchange me-2"></i>Financial</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default VAT Rate (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="default_vat_rate" class="form-control" value="<?= h($settings['default_vat_rate']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Currency</label>
                        <input type="text" name="currency" class="form-control" value="<?= h($settings['currency']) ?>" maxlength="10" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="ars-card">
                <div class="card-header"><i class="bi bi-clock me-2"></i>Booking Rules</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Check-in Time</label>
                        <input type="time" name="default_check_in_time" class="form-control" value="<?= h(substr($settings['default_check_in_time'], 0, 5)) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Check-out Time</label>
                        <input type="time" name="default_check_out_time" class="form-control" value="<?= h(substr($settings['default_check_out_time'], 0, 5)) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Pending Booking Expiry (hours)</label>
                        <input type="number" min="1" max="720" name="pending_expiry_hours" class="form-control" value="<?= h($settings['pending_expiry_hours']) ?>" required>
                        <small class="text-muted">Unpaid pending bookings auto-expire after this many hours.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Booking Notification Email(s)</label>
                        <textarea name="booking_notification_emails" class="form-control" rows="3" placeholder="admin@example.com, reservations@example.com"><?= h($settings['booking_notification_emails'] ?? '') ?></textarea>
                        <small class="text-muted">Separate multiple emails with commas or new lines. These addresses receive an email when a guest submits a pending booking from the mobile app.</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="financialAdapterEnabled" name="financial_adapter_enabled" value="1" <?= !empty($settings['financial_adapter_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="financialAdapterEnabled">Enable ARS Financial Adapter (Phase 2B)</label>
                        </div>
                        <div class="form-text">When off, posting uses the legacy bridge. When on, confirm/payment/deposit use Option B documents + account role mapping. Default off for safe rollout.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-12">
            <div class="ars-card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span><i class="bi bi-credit-card-2-front me-2"></i>Stripe Payment Configuration</span>
                    <span class="badge <?= !empty($settings['stripe_enabled']) ? 'bg-success' : 'bg-secondary' ?>">
                        <?= !empty($settings['stripe_enabled']) ? 'Enabled' : 'Disabled' ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="stripeEnabled" name="stripe_enabled" value="1" <?= !empty($settings['stripe_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="stripeEnabled">Enable Stripe</label>
                            </div>
                            <small class="text-muted">When disabled, mobile payments cannot create Stripe PaymentIntents.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Stripe Mode</label>
                            <select name="stripe_mode" class="form-select">
                                <option value="test" <?= (($settings['stripe_mode'] ?? 'test') === 'test') ? 'selected' : '' ?>>Test</option>
                                <option value="live" <?= (($settings['stripe_mode'] ?? 'test') === 'live') ? 'selected' : '' ?>>Live</option>
                            </select>
                            <small class="text-muted">The selected mode controls which key set the API uses.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Default Currency</label>
                            <input type="text" name="stripe_default_currency" class="form-control" maxlength="3" value="<?= h($settings['stripe_default_currency'] ?? 'AED') ?>" placeholder="AED">
                        </div>

                        <div class="col-lg-6">
                            <div class="border rounded-3 p-3 h-100">
                                <h6 class="fw-bold mb-3"><i class="bi bi-bug me-1"></i>Test Keys</h6>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Publishable Key</label>
                                    <input type="text" name="stripe_publishable_key_test" class="form-control" value="<?= h($settings['stripe_publishable_key_test'] ?? '') ?>" placeholder="pk_test_...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Secret Key</label>
                                    <input type="password" name="stripe_secret_key_test" class="form-control" value="" placeholder="<?= !empty($settings['stripe_secret_key_test_enc']) ? 'Saved - leave blank to keep' : 'sk_test_...' ?>">
                                </div>
                                <div>
                                    <label class="form-label fw-semibold">Webhook Secret</label>
                                    <input type="password" name="stripe_webhook_secret_test" class="form-control" value="" placeholder="<?= !empty($settings['stripe_webhook_secret_test_enc']) ? 'Saved - leave blank to keep' : 'whsec_...' ?>">
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="border rounded-3 p-3 h-100">
                                <h6 class="fw-bold mb-3"><i class="bi bi-shield-check me-1"></i>Live Keys</h6>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Publishable Key</label>
                                    <input type="text" name="stripe_publishable_key_live" class="form-control" value="<?= h($settings['stripe_publishable_key_live'] ?? '') ?>" placeholder="pk_live_...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Secret Key</label>
                                    <input type="password" name="stripe_secret_key_live" class="form-control" value="" placeholder="<?= !empty($settings['stripe_secret_key_live_enc']) ? 'Saved - leave blank to keep' : 'sk_live_...' ?>">
                                </div>
                                <div>
                                    <label class="form-label fw-semibold">Webhook Secret</label>
                                    <input type="password" name="stripe_webhook_secret_live" class="form-control" value="" placeholder="<?= !empty($settings['stripe_webhook_secret_live_enc']) ? 'Saved - leave blank to keep' : 'whsec_...' ?>">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Payment Policy</label>
                            <select name="stripe_payment_policy" class="form-select">
                                <option value="full" <?= (($settings['stripe_payment_policy'] ?? 'full') === 'full') ? 'selected' : '' ?>>Full payment required</option>
                                <option value="deposit" <?= (($settings['stripe_payment_policy'] ?? 'full') === 'deposit') ? 'selected' : '' ?>>Deposit only</option>
                                <option value="partial" <?= (($settings['stripe_payment_policy'] ?? 'full') === 'partial') ? 'selected' : '' ?>>Partial payment allowed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Deposit Percentage</label>
                            <div class="input-group">
                                <input type="number" min="0" max="100" step="0.01" name="stripe_deposit_percentage" class="form-control" value="<?= h($settings['stripe_deposit_percentage'] ?? 20) ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-4 pt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="stripeAutoConfirm" name="stripe_auto_confirm" value="1" <?= !empty($settings['stripe_auto_confirm']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="stripeAutoConfirm">Auto-confirm booking after successful payment</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Success Redirect URL (optional)</label>
                            <input type="url" name="stripe_success_url" class="form-control" value="<?= h($settings['stripe_success_url'] ?? '') ?>" placeholder="https://...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Failed Redirect URL (optional)</label>
                            <input type="url" name="stripe_failed_url" class="form-control" value="<?= h($settings['stripe_failed_url'] ?? '') ?>" placeholder="https://...">
                        </div>
                    </div>
                    <div class="alert alert-info small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Secret and webhook keys are encrypted before storage and are never displayed back on this page. Use the matching Stripe webhook endpoint:
                        <code>/api/customer/v1/stripe/webhook?mode=test</code> or <code>?mode=live</code>.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="ars-card">
                <div class="card-header"><i class="bi bi-stars me-2"></i>Cleaning Integration</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cleaning Company</label>
                        <select name="cleaning_company_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($cleaningCompanies as $cc): ?>
                            <option value="<?= $cc['id'] ?>" <?= ($settings['cleaning_company_id'] == $cc['id']) ? 'selected' : '' ?>><?= h($cc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Work orders are created in this company on guest check-out.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Cleaning Fee (<?= h($settings['currency']) ?>)</label>
                        <input type="number" step="0.01" min="0" name="default_cleaning_fee" class="form-control" value="<?= h($settings['default_cleaning_fee'] ?? 0) ?>">
                        <small class="text-muted">Fee amount set on each auto-created work order.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Cleaner (Worker)</label>
                        <select name="default_worker_id" class="form-select">
                            <option value="">-- Not Assigned --</option>
                            <?php foreach ($workers as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= (($settings['default_worker_id'] ?? '') == $w['id']) ? 'selected' : '' ?>><?= h($w['worker_name']) ?><?= $w['nickname'] ? ' (' . h($w['nickname']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">This worker will be auto-assigned to all ARS cleaning orders.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Driver</label>
                        <select name="default_driver_id" class="form-select">
                            <option value="">-- Not Assigned --</option>
                            <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (($settings['default_driver_id'] ?? '') == $d['id']) ? 'selected' : '' ?>><?= h($d['nickname'] ?: $d['driver_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">This driver will be auto-assigned to all ARS cleaning orders.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-lg-6">
            <div class="ars-card mb-3">
                <div class="card-header"><i class="bi bi-file-earmark-richtext me-2"></i>Document Branding</div>
                <div class="card-body">
                    <p class="mb-2">Set the company name, address, email, phone, TRN, logo, and colors used on booking confirmation, tax invoice, and payment receipt PDFs.</p>
                    <a href="document_branding.php" class="btn btn-ars-outline btn-sm"><i class="bi bi-palette me-1"></i>Open Document Branding</a>
                </div>
            </div>
            <div class="ars-card mb-3">
                <div class="card-header"><i class="bi bi-globe me-2"></i>Customer Portal</div>
                <div class="card-body">
                    <?php
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $stayBase = dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/stay/';
                    $portalUrl = $protocol . '://' . $host . $stayBase;
                    ?>
                    <p class="mb-1"><strong>Portal URL:</strong></p>
                    <a href="<?= h($portalUrl) ?>" target="_blank" class="text-decoration-none"><?= h($portalUrl) ?> <i class="bi bi-box-arrow-up-right ms-1"></i></a>
                    <p class="text-muted small mt-2 mb-0">Guests can browse units, search availability, register, and submit bookings through this portal.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-ars"><i class="bi bi-check-lg me-2"></i>Save Settings</button>
    </div>
</form>

<?php ars_shell_end(); ?>
