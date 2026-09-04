<?php
/**
 * HR company documents — vocabulary and file helpers.
 *
 * Company-level statutory documents (trade licence, MOA, Ejari, establishment card,
 * power of attorney, VAT and corporate tax certificates). Files land in
 * uploads/company_docs/<company_id>/ and are only
 * ever linked through hr/company_document_file.php — never as a bare uploads/ href.
 */

if (!defined('HR_COMPANY_DOC_DIR')) {
    define('HR_COMPANY_DOC_DIR', dirname(__DIR__, 2) . '/uploads/company_docs');
}
if (!defined('HR_COMPANY_DOC_REL')) {
    define('HR_COMPANY_DOC_REL', 'uploads/company_docs');
}
if (!defined('HR_COMPANY_DOC_MAX_BYTES')) {
    define('HR_COMPANY_DOC_MAX_BYTES', 10 * 1024 * 1024); // 10 MB per file
}

/**
 * Fixed document catalog. Codes are stored in hr_company_documents.doc_type.
 *
 * 'expected' drives the "Missing types" KPI: set it to false for a document a
 * company is not obliged to hold, so it is not reported as missing.
 *
 * @return array<string,array{label:string,icon:string,has_expiry:bool,expected:bool,order:int}>
 */
if (!function_exists('hr_company_document_types')) {
    function hr_company_document_types(): array
    {
        return [
            'trade_license' => [
                'label' => 'Trade License',
                'icon' => 'file-badge',
                'has_expiry' => true,
                'expected' => true,
                'order' => 10,
            ],
            'moa' => [
                'label' => 'Memorandum of Association (MOA)',
                'icon' => 'scroll-text',
                'has_expiry' => false,
                'expected' => true,
                'order' => 20,
            ],
            'ejari' => [
                'label' => 'Ejari / Tenancy Contract',
                'icon' => 'home',
                'has_expiry' => true,
                'expected' => true,
                'order' => 30,
            ],
            'establishment_card' => [
                'label' => 'Establishment Card',
                'icon' => 'id-card',
                'has_expiry' => true,
                'expected' => true,
                'order' => 40,
            ],
            'power_of_attorney' => [
                'label' => 'Power of Attorney',
                'icon' => 'stamp',
                'has_expiry' => false,
                'expected' => true,
                'order' => 50,
            ],
            // FTA registration certificates carry no expiry date.
            'vat_certificate' => [
                'label' => 'VAT Certificate',
                'icon' => 'receipt',
                'has_expiry' => false,
                'expected' => true,
                'order' => 60,
            ],
            'corporate_tax_certificate' => [
                'label' => 'Corporate Tax Certificate',
                'icon' => 'landmark',
                'has_expiry' => false,
                'expected' => true,
                'order' => 70,
            ],
            'other' => [
                'label' => 'Other',
                'icon' => 'file',
                'has_expiry' => false,
                'expected' => false,
                'order' => 90,
            ],
        ];
    }
}

/** Human label for a stored doc_type; unknown codes fall back to the raw value. */
if (!function_exists('hr_company_document_type_label')) {
    function hr_company_document_type_label(string $code): string
    {
        $types = hr_company_document_types();
        return $types[$code]['label'] ?? ($code !== '' ? $code : '—');
    }
}

if (!function_exists('hr_company_document_type_is_valid')) {
    function hr_company_document_type_is_valid(string $code): bool
    {
        return array_key_exists($code, hr_company_document_types());
    }
}

/**
 * Expiry badge — same thresholds as hr/documents.php::badgeDate() so both screens read alike.
 */
if (!function_exists('hr_company_document_expiry_badge')) {
    function hr_company_document_expiry_badge(?string $date): string
    {
        $date = trim((string)$date);
        if ($date === '' || $date === '0000-00-00') {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        $diff = (int)floor(($ts - strtotime(date('Y-m-d'))) / 86400);
        if ($diff < 0) {
            return '<span class="badge bg-danger">Expired</span>';
        }
        if ($diff <= 30) {
            return '<span class="badge bg-warning text-dark">' . $diff . 'd</span>';
        }
        return '<span class="badge bg-success">' . $diff . 'd</span>';
    }
}

if (!function_exists('hr_company_document_allowed_extensions')) {
    function hr_company_document_allowed_extensions(): array
    {
        return ['pdf', 'jpg', 'jpeg', 'png'];
    }
}

/**
 * Content-Type to serve a stored file with, derived from the extension WE assigned on
 * upload — never from the browser-supplied type, which the uploader controls.
 * Returns null for anything that must not be rendered inline.
 */
if (!function_exists('hr_company_document_inline_mime')) {
    function hr_company_document_inline_mime(string $fileName): ?string
    {
        static $inlineSafe = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return $inlineSafe[$ext] ?? null;
    }
}

/** Absolute filesystem path for a stored root-relative uploads/... path. */
if (!function_exists('hr_company_document_absolute_path')) {
    function hr_company_document_absolute_path(?string $relative): string
    {
        $path = trim((string)$relative);
        if ($path === '') {
            return '';
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return dirname(__DIR__, 2) . '/' . $path;
    }
}

/**
 * Ensure uploads/company_docs/<companyId>/ exists and that the parent folder denies
 * direct web access. Returns the absolute directory path, or '' if it can't be made.
 */
if (!function_exists('hr_company_document_upload_dir')) {
    function hr_company_document_upload_dir(int $companyId): string
    {
        $root = HR_COMPANY_DOC_DIR;
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            return '';
        }

        // Files are only ever served through hr/company_document_file.php, which checks
        // login and company access. uploads/ has no deny rule of its own, so drop one here.
        $denyFile = $root . '/.htaccess';
        if (!file_exists($denyFile)) {
            @file_put_contents(
                $denyFile,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            );
        }

        $dir = $root . '/' . $companyId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        return $dir;
    }
}

/**
 * Validate and store one uploaded company document.
 *
 * @param array $file One entry of $_FILES.
 * @return array{ok:bool,error:string,file_path:?string,file_name:?string,file_size:?int,mime_type:?string}
 *         ok=true with file_path=null means "no file was chosen" (not an error).
 */
if (!function_exists('hr_company_document_store_upload')) {
    function hr_company_document_store_upload(array $file, int $companyId, string $docType): array
    {
        $blank = ['ok' => true, 'error' => '', 'file_path' => null, 'file_name' => null, 'file_size' => null, 'mime_type' => null];
        $fail = function (string $msg) use ($blank) {
            return array_merge($blank, ['ok' => false, 'error' => $msg]);
        };

        $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($errCode === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') {
            return $blank; // nothing chosen
        }
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            return $fail('The file is larger than the server upload limit.');
        }
        if ($errCode !== UPLOAD_ERR_OK) {
            return $fail('Upload failed (PHP upload error ' . (int)$errCode . ').');
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            return $fail('Upload failed: the file was not received correctly.');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return $fail('The selected file is empty.');
        }
        if ($size > HR_COMPANY_DOC_MAX_BYTES) {
            return $fail('The file exceeds the ' . (int)(HR_COMPANY_DOC_MAX_BYTES / 1024 / 1024) . ' MB limit.');
        }

        $originalName = (string)$file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, hr_company_document_allowed_extensions(), true)) {
            return $fail('File type not allowed. Use PDF, JPG or PNG.');
        }

        $dir = hr_company_document_upload_dir($companyId);
        if ($dir === '') {
            return $fail('Upload failed: cannot create the company documents folder.');
        }

        $typeSlug = preg_replace('/[^a-z0-9_]/', '', strtolower($docType)) ?: 'doc';
        $safeName = $typeSlug . '_' . $companyId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $dir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return $fail('Upload failed: cannot write to the company documents folder.');
        }

        return [
            'ok' => true,
            'error' => '',
            'file_path' => HR_COMPANY_DOC_REL . '/' . $companyId . '/' . $safeName,
            'file_name' => substr($originalName, 0, 255),
            'file_size' => $size,
            // Resolved from the extension we just assigned, never from $file['type'].
            'mime_type' => hr_company_document_inline_mime($safeName) ?? 'application/octet-stream',
        ];
    }
}

/** Delete a stored file from disk, guarding against paths outside uploads/. */
if (!function_exists('hr_company_document_unlink')) {
    function hr_company_document_unlink(?string $relative): void
    {
        $abs = hr_company_document_absolute_path($relative);
        if ($abs === '') {
            return;
        }
        $real = realpath($abs);
        $root = realpath(dirname(__DIR__, 2) . '/uploads');
        if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return;
        }
        if (is_file($real)) {
            @unlink($real);
        }
    }
}
