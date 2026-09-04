<?php
/**
 * Real Estate Document Settings — company letterhead for invoices, receipts, vendor SOA.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/includes/re_pdf_helpers.php';

require_login();
if (!(has_role('Owner', $conn) || has_role('Admin', $conn) || has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn))) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$cid = (int)(current_company_id($conn) ?: 0);
if ($cid <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$msg = '';
$err = '';

function re_load_company_settings(PDO $conn, int $cid): array
{
    $stmt = $conn->prepare("
        SELECT cs.*, c.name AS company_name
        FROM companies c
        LEFT JOIN company_settings cs ON cs.company_id = c.id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$cid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function re_handle_document_logo_upload(int $companyId): ?string
{
    if (empty($_FILES['logo_file']) || ($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES['logo_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Logo file exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'Logo file is too large.',
            UPLOAD_ERR_PARTIAL => 'Logo upload was incomplete.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the uploaded logo.',
            UPLOAD_ERR_EXTENSION => 'Logo upload was blocked by a server extension.',
        ];
        throw new RuntimeException($messages[$file['error']] ?? 'Logo upload failed.');
    }
    if ((int)$file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo file is too large. Maximum allowed size is 2 MB.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        throw new RuntimeException('Logo must be PNG, JPG, JPEG, GIF, or WEBP.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowedMimes = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMimes[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Uploaded logo is not a valid image file.');
    }
    $ext = $allowedMimes[$mime];

    $appRoot = dirname(__DIR__, 2);
    $uploadDir = $appRoot . '/uploads/realestate_logos';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0777, true)) {
            throw new RuntimeException('Cannot create logo upload directory. Please create uploads/realestate_logos and make it writable.');
        }
        @chmod($uploadDir, 0777);
    }
    if (!is_writable($uploadDir)) {
        @chmod($uploadDir, 0777);
    }
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Logo upload directory is not writable. Please set write permission for uploads/realestate_logos.');
    }

    $filename = 'company_' . $companyId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to save uploaded logo.');
    }

    return 'uploads/realestate_logos/' . $filename;
}

$company = re_load_company_settings($conn, $cid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $logoPath = (string)($company['logo_path'] ?? '');
        if (!empty($_POST['remove_logo'])) {
            $logoPath = '';
        }
        $uploadedLogoPath = re_handle_document_logo_upload($cid);
        if ($uploadedLogoPath !== null) {
            $logoPath = $uploadedLogoPath;
        }

        $data = [
            'legal_name' => trim($_POST['legal_name'] ?? ''),
            'trade_name' => trim($_POST['trade_name'] ?? ''),
            'trn' => trim($_POST['trn'] ?? ''),
            'currency_code' => trim($_POST['currency_code'] ?? 'AED') ?: 'AED',
            'address_line1' => trim($_POST['address_line1'] ?? ''),
            'address_line2' => trim($_POST['address_line2'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state_region' => trim($_POST['state_region'] ?? ''),
            'postcode' => trim($_POST['postcode'] ?? ''),
            'country' => trim($_POST['country'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'logo_path' => $logoPath,
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'bank_account_no' => trim($_POST['bank_account_no'] ?? ''),
            'bank_iban' => trim($_POST['bank_iban'] ?? ''),
            'bank_swift' => trim($_POST['bank_swift'] ?? ''),
        ];
        if ($data['legal_name'] === '') {
            throw new RuntimeException('Legal company name is required.');
        }

        $conn->beginTransaction();
        $stmt = $conn->prepare("
            INSERT INTO company_settings
                (company_id, legal_name, trade_name, trn, currency_code, address_line1, address_line2, city, state_region, postcode, country, phone, email, website, logo_path, bank_name, bank_account_no, bank_iban, bank_swift)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                legal_name = VALUES(legal_name),
                trade_name = VALUES(trade_name),
                trn = VALUES(trn),
                currency_code = VALUES(currency_code),
                address_line1 = VALUES(address_line1),
                address_line2 = VALUES(address_line2),
                city = VALUES(city),
                state_region = VALUES(state_region),
                postcode = VALUES(postcode),
                country = VALUES(country),
                phone = VALUES(phone),
                email = VALUES(email),
                website = VALUES(website),
                logo_path = VALUES(logo_path),
                bank_name = VALUES(bank_name),
                bank_account_no = VALUES(bank_account_no),
                bank_iban = VALUES(bank_iban),
                bank_swift = VALUES(bank_swift),
                updated_at = NOW()
        ");
        $stmt->execute([
            $cid,
            $data['legal_name'],
            $data['trade_name'] ?: null,
            $data['trn'] ?: null,
            $data['currency_code'],
            $data['address_line1'] ?: null,
            $data['address_line2'] ?: null,
            $data['city'] ?: null,
            $data['state_region'] ?: null,
            $data['postcode'] ?: null,
            $data['country'] ?: null,
            $data['phone'] ?: null,
            $data['email'] ?: null,
            $data['website'] ?: null,
            $data['logo_path'] ?: null,
            $data['bank_name'] ?: null,
            $data['bank_account_no'] ?: null,
            $data['bank_iban'] ?: null,
            $data['bank_swift'] ?: null,
        ]);
        re_pdf_save_document_settings($conn, $cid, [
            'show_logo' => isset($_POST['show_logo']),
            'show_trn' => isset($_POST['show_trn']),
            'show_phone' => isset($_POST['show_phone']),
            'show_email' => isset($_POST['show_email']),
            'show_address' => isset($_POST['show_address']),
            'show_bank_details' => isset($_POST['show_bank_details']),
        ]);
        $conn->commit();
        $msg = 'Document settings saved. Invoices, receipts, and vendor statements will use these details.';
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $err = $e->getMessage();
    }
}

$company = re_load_company_settings($conn, $cid);
$docSettings = re_pdf_document_settings($conn, $cid);
$pageTitle = 'Document Settings';
require_once __DIR__ . '/includes/re_layout_header.php';
$currentLogoPath = trim((string)($company['logo_path'] ?? ''));
$currentLogoUrl = $currentLogoPath !== '' ? $appBase . '/' . ltrim($currentLogoPath, '/') : '';
?>
<div class="mb-4">
    <h1 class="h4 mb-0">Document Settings</h1>
    <p class="text-muted mb-0">Control the company details shown on Real Estate invoices, payment receipts, and vendor statements for the current company.</p>
</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card card-round">
    <div class="card-body">
        <?php csrf_field(); ?>
        <h6 class="mb-3">Company Details</h6>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Legal Name *</label><input type="text" name="legal_name" class="form-control" required value="<?= h($company['legal_name'] ?? $company['company_name'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Trade Name</label><input type="text" name="trade_name" class="form-control" value="<?= h($company['trade_name'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">TRN</label><input type="text" name="trn" class="form-control" value="<?= h($company['trn'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Currency</label><input type="text" name="currency_code" class="form-control" value="<?= h($company['currency_code'] ?? 'AED') ?>"></div>
            <div class="col-md-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= h($company['phone'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($company['email'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Address Line 1</label><input type="text" name="address_line1" class="form-control" value="<?= h($company['address_line1'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Address Line 2</label><input type="text" name="address_line2" class="form-control" value="<?= h($company['address_line2'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= h($company['city'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">State / Region</label><input type="text" name="state_region" class="form-control" value="<?= h($company['state_region'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Postcode</label><input type="text" name="postcode" class="form-control" value="<?= h($company['postcode'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Country</label><input type="text" name="country" class="form-control" value="<?= h($company['country'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Website</label><input type="text" name="website" class="form-control" value="<?= h($company['website'] ?? '') ?>"></div>
            <div class="col-md-6">
                <label class="form-label">Company Logo</label>
                <input type="file" name="logo_file" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp">
                <div class="form-text">Upload PNG, JPG, GIF, or WEBP. Maximum 2 MB.</div>
                <?php if ($currentLogoUrl): ?>
                    <div class="d-flex align-items-center gap-3 mt-2">
                        <img src="<?= h($currentLogoUrl) ?>" alt="Current logo" style="max-height:60px;max-width:160px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;padding:6px;background:#fff;">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo">
                            <label class="form-check-label" for="remove_logo">Remove current logo</label>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <h6 class="mt-4 mb-3">Bank Details</h6>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= h($company['bank_name'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Account No.</label><input type="text" name="bank_account_no" class="form-control" value="<?= h($company['bank_account_no'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">IBAN</label><input type="text" name="bank_iban" class="form-control" value="<?= h($company['bank_iban'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">SWIFT</label><input type="text" name="bank_swift" class="form-control" value="<?= h($company['bank_swift'] ?? '') ?>"></div>
        </div>

        <h6 class="mt-4 mb-3">Show On PDF Documents</h6>
        <div class="row g-2">
            <?php foreach ([
                'show_logo' => 'Logo',
                'show_trn' => 'TRN number',
                'show_phone' => 'Phone number',
                'show_email' => 'Email',
                'show_address' => 'Address',
                'show_bank_details' => 'Bank details on invoice / receipt',
            ] as $key => $label): ?>
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="<?= h($key) ?>" id="<?= h($key) ?>" value="1" <?= ($docSettings[$key] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= h($key) ?>"><?= h($label) ?></label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4"><button type="submit" class="btn btn-primary">Save Document Settings</button></div>
    </div>
</form>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
