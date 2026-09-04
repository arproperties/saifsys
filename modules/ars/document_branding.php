<?php
/**
 * ARS Document Branding — controls company name/address/contact/logo/colors on PDF docs.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';

$arsCompanyId = arsPageAuth($conn);
$arsCompany = get_company($conn, $arsCompanyId);
arsEnsureDocumentBrandingSettings($conn);

$success = $error = '';
$settings = getArsSettings($conn, $arsCompanyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim((string)($_POST['doc_brand_name'] ?? ''));
    $address = trim((string)($_POST['doc_brand_address'] ?? ''));
    $email = trim((string)($_POST['doc_brand_email'] ?? ''));
    $phone = trim((string)($_POST['doc_brand_phone'] ?? ''));
    $trn = trim((string)($_POST['doc_brand_trn'] ?? ''));
    $primary = trim((string)($_POST['doc_brand_primary_color'] ?? '#0f4c75'));
    $accent = trim((string)($_POST['doc_brand_accent_color'] ?? '#eef3fb'));
    $removeLogo = !empty($_POST['remove_logo']);

    if ($primary !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) {
        $error = 'Primary color must be a hex value like #0f4c75.';
    } elseif ($accent !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
        $error = 'Accent color must be a hex value like #eef3fb.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Brand email is not valid.';
    }

    $logoPath = (string)($settings['doc_brand_logo_path'] ?? '');
    if ($error === '' && $removeLogo) {
        if ($logoPath !== '') {
            $abs = dirname(__DIR__, 2) . '/' . ltrim($logoPath, '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $logoPath = '';
    }

    if ($error === '' && !empty($_FILES['doc_brand_logo']['name'])) {
        $file = $_FILES['doc_brand_logo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Logo upload failed.';
        } else {
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
            if (!in_array($ext, $allowed, true)) {
                $error = 'Logo must be PNG, JPG, GIF, or WEBP.';
            } elseif ((int)$file['size'] > 2 * 1024 * 1024) {
                $error = 'Logo must be 2 MB or smaller.';
            } else {
                $dir = dirname(__DIR__, 2) . '/uploads/ars_document_branding/' . $arsCompanyId;
                if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                    $error = 'Could not create logo upload directory.';
                } else {
                    @chmod($dir, 0777);
                    $stored = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $dest = $dir . '/' . $stored;
                    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
                        $error = 'Could not save logo file.';
                    } else {
                        if ($logoPath !== '') {
                            $old = dirname(__DIR__, 2) . '/' . ltrim($logoPath, '/');
                            if (is_file($old)) {
                                @unlink($old);
                            }
                        }
                        $logoPath = 'uploads/ars_document_branding/' . $arsCompanyId . '/' . $stored;
                    }
                }
            }
        }
    }

    if ($error === '') {
        try {
            $exists = $conn->prepare('SELECT id FROM ars_company_settings WHERE company_id = ? LIMIT 1');
            $exists->execute([$arsCompanyId]);
            if (!$exists->fetchColumn()) {
                $conn->prepare('INSERT INTO ars_company_settings (company_id) VALUES (?)')->execute([$arsCompanyId]);
            }
            $conn->prepare("
                UPDATE ars_company_settings SET
                    doc_brand_name = ?,
                    doc_brand_address = ?,
                    doc_brand_email = ?,
                    doc_brand_phone = ?,
                    doc_brand_trn = ?,
                    doc_brand_logo_path = ?,
                    doc_brand_primary_color = ?,
                    doc_brand_accent_color = ?,
                    updated_at = NOW()
                WHERE company_id = ?
            ")->execute([
                $name !== '' ? $name : null,
                $address !== '' ? $address : null,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $trn !== '' ? $trn : null,
                $logoPath !== '' ? $logoPath : null,
                $primary !== '' ? $primary : null,
                $accent !== '' ? $accent : null,
                $arsCompanyId,
            ]);
            $success = 'Document branding saved. New PDFs will use these details (cached PDFs refresh automatically).';
            $settings = getArsSettings($conn, $arsCompanyId);
        } catch (Throwable $e) {
            $error = 'Failed to save branding: ' . $e->getMessage();
        }
    }
}

$settings = getArsSettings($conn, $arsCompanyId);
$logoPreview = (string)($settings['doc_brand_logo_path'] ?? '');
$primaryVal = (string)($settings['doc_brand_primary_color'] ?? '#0f4c75');
$accentVal = (string)($settings['doc_brand_accent_color'] ?? '#eef3fb');
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primaryVal)) {
    $primaryVal = '#0f4c75';
}
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $accentVal)) {
    $accentVal = '#eef3fb';
}

$pageTitle = 'Document Branding';
ars_shell_begin([
    'title' => 'Document Branding',
    'subtitle' => 'Controls company identity on booking confirmation, tax invoice, and payment receipt PDFs',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Settings', 'href' => 'settings.php'],
        ['label' => 'Document Branding'],
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

<form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
<?php csrf_field(); ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="ars-card">
            <div class="card-header"><i class="bi bi-building me-2"></i>Company details on PDFs</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Company / trade name</label>
                    <input type="text" name="doc_brand_name" class="form-control" maxlength="160"
                           value="<?= h((string)($settings['doc_brand_name'] ?? '')) ?>"
                           placeholder="<?= h((string)($arsCompany['name'] ?? 'ARS Home Rentals')) ?>">
                    <div class="form-text">Leave blank to fall back to the company record name.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Address</label>
                    <textarea name="doc_brand_address" class="form-control" rows="3" placeholder="Building, street, city, country"><?= h((string)($settings['doc_brand_address'] ?? '')) ?></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="doc_brand_email" class="form-control" maxlength="160"
                               value="<?= h((string)($settings['doc_brand_email'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="text" name="doc_brand_phone" class="form-control" maxlength="60"
                               value="<?= h((string)($settings['doc_brand_phone'] ?? '')) ?>">
                    </div>
                </div>
                <div class="mb-0 mt-3">
                    <label class="form-label fw-semibold">TRN (VAT)</label>
                    <input type="text" name="doc_brand_trn" class="form-control" maxlength="60"
                           value="<?= h((string)($settings['doc_brand_trn'] ?? '')) ?>"
                           placeholder="Optional tax registration number">
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ars-card mb-4">
            <div class="card-header"><i class="bi bi-palette me-2"></i>Colors</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Primary (titles / header rule)</label>
                    <input type="color" name="doc_brand_primary_color" class="form-control form-control-color w-100" value="<?= h($primaryVal) ?>">
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold">Accent (invoice table header)</label>
                    <input type="color" name="doc_brand_accent_color" class="form-control form-control-color w-100" value="<?= h($accentVal) ?>">
                </div>
            </div>
        </div>
        <div class="ars-card">
            <div class="card-header"><i class="bi bi-image me-2"></i>Logo</div>
            <div class="card-body">
                <?php if ($logoPreview !== ''): ?>
                    <div class="mb-3 p-2 border rounded text-center bg-light">
                        <img src="<?= h('../../' . ltrim($logoPreview, '/')) ?>" alt="Logo preview" style="max-height:72px;max-width:100%">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="removeLogo">
                        <label class="form-check-label" for="removeLogo">Remove current logo</label>
                    </div>
                <?php endif; ?>
                <label class="form-label fw-semibold">Upload logo</label>
                <input type="file" name="doc_brand_logo" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp">
                <div class="form-text">PNG/JPG/GIF/WEBP, max 2 MB. Shown on confirmation, receipt, and tax invoice.</div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 d-flex gap-2 flex-wrap">
    <button type="submit" class="btn btn-ars"><i class="bi bi-check-lg me-2"></i>Save branding</button>
    <a href="settings.php" class="btn btn-outline-secondary">Back to Company Settings</a>
</div>
</form>

<?php ars_shell_end(); ?>
