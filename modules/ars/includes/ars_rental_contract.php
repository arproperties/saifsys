<?php
/**
 * ARS Rental Contract: the Short-Term Lease Agreement (English | Arabic) made from a booking or typed in,
 * saved as draft -> final (PDF) -> cancelled. Works like Leases > Bed Space Contract.
 *
 * Letterheads are the same re_letterheads list the Bed Space Contract uses (kept per company), and the
 * letterhead upload / delete code is reused from realestate/includes/bed_space_contract_helper.php
 * without changing it. ARS gets its own built-in letterhead: the ARS logo (assets/ars_rental_letterhead.png).
 */

require_once dirname(__DIR__, 2) . '/realestate/includes/bed_space_contract_helper.php';

const ARC_TEMPLATE_FILE = __DIR__ . '/../templates/ars_rental_contract.html';
const ARC_LETTERHEAD_IMAGE = 'modules/ars/assets/ars_rental_letterhead.png';
const ARC_LANDLORD_DEFAULT = 'Ain Al Reem Real Estate L.L.C.';

/** Contract fields typed on the page (column => label). */
const ARC_FIELDS = [
    'lease_no' => 'Lease ID',
    'hide_lease_id' => 'Hide Lease ID',
    'contract_date' => 'Date',
    'landlord_name' => 'Landlord Name',
    'tenant_name' => 'Tenant',
    'tenant_eid' => 'Emirates ID No.',
    'tenant_passport' => 'Passport No.',
    'tenant_mobile' => 'Mobile No.',
    'tenant_email' => 'Email',
    'tenant_address' => 'Address',
    'contract_type' => 'Contract Type',
    'start_date' => 'Period: From',
    'end_date' => 'Period: To',
    'unit_no' => 'Unit No',
    'unit_type' => 'Unit Type',
    'rent' => 'Rent (AED)',
    'terms_of_payment' => 'Terms of Payment',
    'security_deposit' => 'Security Deposit (AED)',
];

if (!function_exists('arc_ensure_tables')) {
    function arc_ensure_tables(PDO $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        bsc_ensure_tables($conn); // re_letterheads
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `ars_rental_contracts` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `booking_id` INT(11) DEFAULT NULL,
              `letterhead_id` INT(11) DEFAULT NULL,
              `lease_no` VARCHAR(100) DEFAULT NULL,
              `hide_lease_id` VARCHAR(1) DEFAULT NULL,
              `contract_date` DATE DEFAULT NULL,
              `landlord_name` VARCHAR(255) DEFAULT NULL,
              `tenant_name` VARCHAR(255) DEFAULT NULL,
              `tenant_eid` VARCHAR(100) DEFAULT NULL,
              `tenant_passport` VARCHAR(100) DEFAULT NULL,
              `tenant_mobile` VARCHAR(100) DEFAULT NULL,
              `tenant_email` VARCHAR(200) DEFAULT NULL,
              `tenant_address` TEXT DEFAULT NULL,
              `contract_type` VARCHAR(100) DEFAULT NULL,
              `start_date` DATE DEFAULT NULL,
              `end_date` DATE DEFAULT NULL,
              `unit_no` VARCHAR(100) DEFAULT NULL,
              `unit_type` VARCHAR(100) DEFAULT NULL,
              `rent` DECIMAL(12,2) DEFAULT NULL,
              `terms_of_payment` VARCHAR(255) DEFAULT NULL,
              `security_deposit` DECIMAL(12,2) DEFAULT NULL,
              `status` ENUM('draft','final','cancelled') NOT NULL DEFAULT 'draft',
              `contract_path` VARCHAR(500) DEFAULT NULL,
              `generated_at` DATETIME DEFAULT NULL,
              `confirmed_at` DATETIME DEFAULT NULL,
              `confirmed_by` INT(11) DEFAULT NULL,
              `cancelled_at` DATETIME DEFAULT NULL,
              `cancelled_by` INT(11) DEFAULT NULL,
              `cancel_reason` TEXT DEFAULT NULL,
              `created_by` INT(11) DEFAULT NULL,
              `updated_by` INT(11) DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_company` (`company_id`),
              KEY `idx_booking` (`booking_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Existing installs: the table was created before unit_type existed.
        $hasUnitType = $conn->query("SHOW COLUMNS FROM `ars_rental_contracts` LIKE 'unit_type'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasUnitType) {
            $conn->exec("ALTER TABLE `ars_rental_contracts` ADD COLUMN `unit_type` VARCHAR(100) DEFAULT NULL AFTER `unit_no`");
        }
        $hasHideLease = $conn->query("SHOW COLUMNS FROM `ars_rental_contracts` LIKE 'hide_lease_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasHideLease) {
            $conn->exec("ALTER TABLE `ars_rental_contracts` ADD COLUMN `hide_lease_id` VARCHAR(1) DEFAULT NULL AFTER `lease_no`");
        }
        $done = true;
    }

    /**
     * Letterheads of the ARS company. The first call adds the built-in ARS one (the ARS logo),
     * so the Bed Space code does not add its Ain Al Reem one here.
     */
    function arc_letterheads(PDO $conn, int $companyId): array
    {
        arc_ensure_tables($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_letterheads WHERE company_id = ? AND is_builtin = 1");
        $stmt->execute([$companyId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM re_letterheads WHERE company_id = ? AND is_default = 1");
            $stmt->execute([$companyId]);
            $hasDefault = (int)$stmt->fetchColumn() > 0;
            $conn->prepare("
                INSERT INTO re_letterheads (company_id, name, type, header_path, margin_top, margin_bottom, is_builtin, is_default, is_active)
                VALUES (?, 'ARS', 'images', ?, 45, 20, 1, ?, 1)
            ")->execute([$companyId, ARC_LETTERHEAD_IMAGE, $hasDefault ? 0 : 1]);
        }
        return bsc_letterheads($conn, $companyId, true);
    }

    function arc_letterhead(PDO $conn, int $companyId, ?int $letterheadId): array
    {
        arc_letterheads($conn, $companyId);
        return bsc_letterhead($conn, $companyId, $letterheadId);
    }

    /** Header and footer images of an image letterhead. No Ain Al Reem fallback here: missing = none. */
    function arc_letterhead_images(array $lh): array
    {
        return [
            'header' => bsc_image_data_uri($lh['header_path'] ?? null),
            'footer' => bsc_image_data_uri($lh['footer_path'] ?? null),
        ];
    }

    function arc_header_html(array $images): string
    {
        return $images['header']
            ? '<div style="text-align:center;"><img src="' . $images['header'] . '" style="width:100%; height:100px;"></div>'
            : '';
    }

    function arc_footer_html(array $images): string
    {
        return $images['footer']
            ? '<div style="text-align:center;"><img src="' . $images['footer'] . '" style="width:100%; height:80px;"></div>'
            : '';
    }

    function arc_template_html(): string
    {
        $html = @file_get_contents(ARC_TEMPLATE_FILE);
        if ($html === false || $html === '') {
            throw new RuntimeException('The contract template file is missing (modules/ars/templates/ars_rental_contract.html).');
        }
        return $html;
    }

    function arc_contract(PDO $conn, int $companyId, int $id): ?array
    {
        arc_ensure_tables($conn);
        $stmt = $conn->prepare("SELECT * FROM ars_rental_contracts WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function arc_contracts(PDO $conn, int $companyId): array
    {
        arc_ensure_tables($conn);
        $stmt = $conn->prepare("
            SELECT c.*, lh.name AS letterhead_name
            FROM ars_rental_contracts c
            LEFT JOIN re_letterheads lh ON lh.id = c.letterhead_id
            WHERE c.company_id = ?
            ORDER BY c.id DESC
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Bookings to pick from in "Fill from booking": upcoming and current ones, newest first. */
    function arc_bookings(PDO $conn, int $companyId, int $keepId = 0): array
    {
        $stmt = $conn->prepare("
            SELECT b.id, b.booking_number, b.check_in, b.check_out, b.status,
                   g.first_name, g.last_name, u.unit_number, bl.name AS building_name
            FROM ars_bookings b
            LEFT JOIN ars_guests g ON g.id = b.guest_id
            LEFT JOIN re_units u ON u.id = b.unit_id
            LEFT JOIN re_buildings bl ON bl.id = u.building_id
            WHERE b.company_id = ?
              AND ((b.status NOT IN ('cancelled', 'expired') AND b.check_out >= CURDATE() - INTERVAL 60 DAY) OR b.id = ?)
            ORDER BY b.check_in DESC
            LIMIT 500
        ");
        $stmt->execute([$companyId, $keepId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Contract field values taken from a booking and its guest. Lease ID and Date stay as typed. */
    function arc_values_from_booking(PDO $conn, int $companyId, int $bookingId): ?array
    {
        $stmt = $conn->prepare("
            SELECT b.*, g.first_name, g.last_name, g.email, g.phone, g.id_type, g.id_number, g.address,
                   u.unit_number, bl.name AS building_name
            FROM ars_bookings b
            LEFT JOIN ars_guests g ON g.id = b.guest_id
            LEFT JOIN re_units u ON u.id = b.unit_id
            LEFT JOIN re_buildings bl ON bl.id = u.building_id
            WHERE b.id = ? AND b.company_id = ?
        ");
        $stmt->execute([$bookingId, $companyId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return null;
        }
        $idNumber = trim((string)$b['id_number']);
        return [
            'tenant_name' => trim($b['first_name'] . ' ' . $b['last_name']),
            'tenant_eid' => $b['id_type'] === 'emirates_id' ? $idNumber : '',
            'tenant_passport' => $b['id_type'] === 'passport' ? $idNumber : '',
            'tenant_mobile' => (string)$b['phone'],
            'tenant_email' => (string)$b['email'],
            'tenant_address' => (string)$b['address'],
            'start_date' => (string)$b['check_in'],
            'end_date' => (string)$b['check_out'],
            'unit_no' => trim($b['unit_number'] . ($b['building_name'] ? ', ' . $b['building_name'] : '')),
            'rent' => $b['total_amount'] !== null ? number_format((float)$b['total_amount'], 2, '.', '') : '',
            'security_deposit' => (float)$b['deposit_amount'] > 0 ? number_format((float)$b['deposit_amount'], 2, '.', '') : '',
        ];
    }

    /** Field values from the posted form or query string, cleaned up. */
    function arc_input(array $src): array
    {
        $out = [];
        foreach (array_keys(ARC_FIELDS) as $key) {
            $val = trim((string)($src[$key] ?? ''));
            if (in_array($key, ['contract_date', 'start_date', 'end_date'], true)) {
                $val = preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : '';
            } elseif (in_array($key, ['rent', 'security_deposit'], true)) {
                $val = str_replace(',', '', $val);
                $val = is_numeric($val) ? number_format((float)$val, 2, '.', '') : '';
            }
            $out[$key] = $val;
        }
        $out['booking_id'] = (int)($src['booking_id'] ?? 0) ?: null;
        $out['letterhead_id'] = (int)($src['letterhead_id'] ?? 0) ?: null;
        return $out;
    }

    /** Saves a draft (new when $id is null) and returns its id. Final and cancelled ones are locked. */
    function arc_save_contract(PDO $conn, int $companyId, ?int $id, array $data, ?int $userId): int
    {
        arc_ensure_tables($conn);
        $cols = array_merge(array_keys(ARC_FIELDS), ['booking_id', 'letterhead_id']);
        $vals = array_map(fn($c) => ($data[$c] ?? '') === '' ? null : $data[$c], $cols);
        if ($id) {
            $existing = arc_contract($conn, $companyId, $id);
            if (!$existing || $existing['status'] !== 'draft') {
                throw new RuntimeException('Only draft contracts can be edited.');
            }
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
            $stmt = $conn->prepare("UPDATE ars_rental_contracts SET $set, updated_by = ? WHERE id = ? AND company_id = ? AND status = 'draft'");
            $stmt->execute(array_merge($vals, [$userId, $id, $companyId]));
            return $id;
        }
        $list = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $marks = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $conn->prepare("INSERT INTO ars_rental_contracts (company_id, $list, created_by) VALUES (?, $marks, ?)");
        $stmt->execute(array_merge([$companyId], $vals, [$userId]));
        return (int)$conn->lastInsertId();
    }

    function arc_delete_contract(PDO $conn, int $companyId, int $id): void
    {
        $c = arc_contract($conn, $companyId, $id);
        if (!$c) {
            throw new RuntimeException('Contract not found.');
        }
        if ($c['status'] !== 'draft') {
            throw new RuntimeException('Only draft contracts can be deleted.');
        }
        $conn->prepare("DELETE FROM ars_rental_contracts WHERE id = ? AND company_id = ? AND status = 'draft'")->execute([$id, $companyId]);
    }

    /** Confirms a saved draft as final: makes the PDF from the saved fields and locks the contract. */
    function arc_confirm_contract(PDO $conn, int $companyId, int $id, ?int $userId): void
    {
        $c = arc_contract($conn, $companyId, $id);
        if (!$c) {
            throw new RuntimeException('Contract not found.');
        }
        if ($c['status'] !== 'draft') {
            throw new RuntimeException('Only draft contracts can be confirmed.');
        }
        if (trim((string)$c['tenant_name']) === '') {
            throw new RuntimeException('Enter the tenant before confirming.');
        }
        $letterhead = arc_letterhead($conn, $companyId, (int)$c['letterhead_id'] ?: null);
        $pdfPath = arc_generate_pdf(arc_fill(arc_template_html(), $c), $letterhead, $id);
        $conn->prepare("
            UPDATE ars_rental_contracts
            SET contract_path = ?, generated_at = NOW(), status = 'final', confirmed_at = NOW(), confirmed_by = ?
            WHERE id = ? AND company_id = ? AND status = 'draft'
        ")->execute([$pdfPath, $userId, $id, $companyId]);
    }

    function arc_cancel_contract(PDO $conn, int $companyId, int $id, string $reason, ?int $userId): void
    {
        $c = arc_contract($conn, $companyId, $id);
        if (!$c) {
            throw new RuntimeException('Contract not found.');
        }
        if ($c['status'] !== 'final') {
            throw new RuntimeException('Only final contracts can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Please enter the reason for cancelling.');
        }
        $conn->prepare("
            UPDATE ars_rental_contracts
            SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
            WHERE id = ? AND company_id = ? AND status = 'final'
        ")->execute([$userId, trim($reason), $id, $companyId]);
    }

    /** "1st day of August 2026" */
    function arc_long_date(string $ymd): string
    {
        $t = strtotime($ymd);
        $d = (int)date('j', $t);
        $suffix = ($d % 100 >= 11 && $d % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$d % 10] ?? 'th');
        return $d . $suffix . ' day of ' . date('F Y', $t);
    }

    /** Whole number in English words: 3800 -> "Three Thousand Eight Hundred". */
    function arc_words_en(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
            'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $under1000 = function (int $n) use ($ones, $tens): string {
            $out = [];
            if ($n >= 100) {
                $out[] = $ones[intdiv($n, 100)] . ' Hundred';
                $n %= 100;
            }
            if ($n >= 20) {
                $out[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
            } elseif ($n > 0) {
                $out[] = $ones[$n];
            }
            return implode(' ', $out);
        };
        $parts = [];
        foreach ([1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'] as $size => $name) {
            if ($n >= $size) {
                $parts[] = $under1000(intdiv($n, $size)) . ' ' . $name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = $under1000($n);
        }
        return implode(' ', $parts);
    }

    /** Whole number in Arabic words: 3800 -> "ثلاثة آلاف وثمانمائة". */
    function arc_words_ar(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }
        $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
        $teens = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
        $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
        $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
        $under1000 = function (int $n) use ($ones, $teens, $tens, $hundreds): string {
            $out = [];
            if ($n >= 100) {
                $out[] = $hundreds[intdiv($n, 100)];
                $n %= 100;
            }
            if ($n >= 20) {
                $out[] = $n % 10 ? $ones[$n % 10] . ' و' . $tens[intdiv($n, 10)] : $tens[intdiv($n, 10)];
            } elseif ($n >= 10) {
                $out[] = $teens[$n - 10];
            } elseif ($n > 0) {
                $out[] = $ones[$n];
            }
            return implode(' و', $out);
        };
        // [one, two, 3-10 plural, 11+ singular]
        $scale = function (int $count, array $w) use ($under1000): string {
            if ($count === 1) return $w[0];
            if ($count === 2) return $w[1];
            if ($count <= 10) return $under1000($count) . ' ' . $w[2];
            // 200 before a noun drops its nun: مائتا ألف
            return preg_replace('/^مائتان$/u', 'مائتا', $under1000($count)) . ' ' . $w[3];
        };
        $parts = [];
        if ($n >= 1000000) {
            $parts[] = $scale(intdiv($n, 1000000), ['مليون', 'مليونان', 'ملايين', 'مليون']);
            $n %= 1000000;
        }
        if ($n >= 1000) {
            $parts[] = $scale(intdiv($n, 1000), ['ألف', 'ألفان', 'آلاف', 'ألف']);
            $n %= 1000;
        }
        if ($n > 0) {
            $parts[] = $under1000($n);
        }
        return implode(' و', $parts);
    }

    /** Amount in words with fils, e.g. "Three Thousand Eight Hundred and 50 Fils". */
    function arc_money_words(string $amount, string $lang): string
    {
        $whole = (int)floor((float)$amount);
        $fils = (int)round(((float)$amount - $whole) * 100);
        if ($lang === 'ar') {
            return arc_words_ar($whole) . ($fils ? ' و' . $fils . ' فلس' : '');
        }
        return arc_words_en($whole) . ($fils ? ' and ' . $fils . ' Fils' : '');
    }


    /** Fills the template fields. Empty fields print a blank line. */
    function arc_fill(string $html, array $data): string
    {
        $blank = '_______________';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $val = fn($k) => trim((string)($data[$k] ?? ''));
        $text = fn($k) => $val($k) !== '' ? $e($val($k)) : $blank;
        $date = fn($k) => !empty($data[$k]) ? date('d/m/Y', strtotime($data[$k])) : $blank;
        $money = fn($k) => $val($k) !== '' ? number_format((float)$data[$k], 2) : $blank;
        $words = fn($k, $lang) => $val($k) !== '' ? $e(arc_money_words($val($k), $lang)) : $blank;

        $replacements = [
            '{{LEASE_ID}}' => $text('lease_no'),
            '{{DATE}}' => $date('contract_date'),
            '{{DATE_LONG}}' => !empty($data['contract_date']) ? arc_long_date($data['contract_date']) : $blank,
            '{{LANDLORD_NAME}}' => $text('landlord_name'),
            '{{TENANT_NAME}}' => $text('tenant_name'),
            '{{TENANT_EID}}' => $text('tenant_eid'),
            '{{TENANT_PASSPORT}}' => $text('tenant_passport'),
            '{{TENANT_ADDRESS}}' => $text('tenant_address'),
            '{{TENANT_MOBILE}}' => $text('tenant_mobile'),
            '{{TENANT_EMAIL}}' => $text('tenant_email'),
            '{{CONTRACT_TYPE}}' => $text('contract_type'),
            '{{START_DATE}}' => $date('start_date'),
            '{{END_DATE}}' => $date('end_date'),
            '{{UNIT_NO}}' => $text('unit_no'),
            '{{UNIT_TYPE}}' => $text('unit_type'),
            '{{RENT}}' => $money('rent'),
            '{{RENT_WORDS_EN}}' => $words('rent', 'en'),
            '{{RENT_WORDS_AR}}' => $words('rent', 'ar'),
            '{{TERMS_OF_PAYMENT}}' => $text('terms_of_payment'),
            '{{DEPOSIT}}' => $money('security_deposit'),
            '{{DEPOSIT_WORDS_EN}}' => $words('security_deposit', 'en'),
        ];
        // Lease ID hidden: drop the whole "Lease ID:" line from the contract header.
        if ($val('hide_lease_id') === '1') {
            $html = str_replace('Lease ID: {{LEASE_ID}}', '', $html);
        }
        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /** Browser preview on an A4-width sheet. A PDF letterhead uses the Bed Space preview (pdf.js). */
    function arc_preview_html(string $filledHtml, array $letterhead, string $letterheadPdfUrl): string
    {
        if (bsc_letterhead_pdf_file($letterhead)) {
            return bsc_preview_html($filledHtml, $letterhead, '', $letterheadPdfUrl);
        }
        $style = '<style>
            body { background: #e9ecef !important; }
            .arc-sheet { width: 210mm; max-width: 100%; box-sizing: border-box; margin: 12px auto; padding: 8mm 15mm 10mm; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.15); }
            .arc-sheet > .arc-head { margin: 0 0 6mm; }
            .arc-sheet > .arc-foot { margin: 8mm 0 0; }
        </style>';
        $images = arc_letterhead_images($letterhead);
        $head = '<div class="arc-head">' . arc_header_html($images) . '</div>';
        $foot = '<div class="arc-foot">' . arc_footer_html($images) . '</div>';
        $html = str_replace('</head>', $style . '</head>', $filledHtml);
        $html = preg_replace('/<body([^>]*)>/', '<body$1><div class="arc-sheet">' . str_replace('$', '\$', $head), $html, 1);
        return str_replace('</body>', $foot . '</div></body>', $html);
    }

    /** Makes the PDF with mPDF (same settings as the Bed Space Contract) and returns its relative path. */
    function arc_generate_pdf(string $filledHtml, array $letterhead, int $contractId): string
    {
        $base = bsc_base_path();
        $autoload = $base . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new Exception('Composer autoload not found. Run composer require mpdf/mpdf');
        }
        require_once $autoload;
        if (!class_exists('\\Mpdf\\Mpdf')) {
            throw new Exception('mPDF is not installed. Run composer require mpdf/mpdf');
        }
        foreach ([$base . '/uploads/contracts', $base . '/uploads/temp'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        $pdfLetterhead = bsc_letterhead_pdf_file($letterhead);
        $images = arc_letterhead_images($letterhead);
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => $pdfLetterhead ? (float)$letterhead['margin_top'] : ($images['header'] ? 38 : 15),
            'margin_bottom' => $pdfLetterhead ? (float)$letterhead['margin_bottom'] : ($images['footer'] ? 30 : 15),
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_header' => 6,
            'tempDir' => $base . '/uploads/temp',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
        $mpdf->SetDirectionality('auto');
        if ($pdfLetterhead) {
            $mpdf->SetDocTemplate($pdfLetterhead, true);
        } else {
            if ($images['header']) {
                $mpdf->SetHTMLHeader(arc_header_html($images));
            }
            if ($images['footer']) {
                $mpdf->SetHTMLFooter(arc_footer_html($images));
            }
        }
        $mpdf->WriteHTML($filledHtml);
        $rel = 'uploads/contracts/ars_rental_contract_' . $contractId . '_' . date('YmdHis') . '.pdf';
        $mpdf->Output($base . '/' . $rel, \Mpdf\Output\Destination::FILE);
        return $rel;
    }
}
