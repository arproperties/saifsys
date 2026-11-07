<?php
/**
 * Bed Space Rental Agreement: agreements, letterheads, the template, preview and PDF.
 * Kept apart from contract_pdf_generator.php so the Tenancy Contract stays as it is.
 */

const BSC_TEMPLATE_NAME = 'Bed Space Rental Agreement';
const BSC_LETTERHEAD_DIR = 'uploads/contracts/letterheads';

/** Agreement fields typed on the page (column => label). */
const BSC_FIELDS = [
    'plot_no' => 'Plot No',
    'agreement_date' => 'Date',
    'landlord_name' => 'Landlord Name',
    'tenant_name' => 'Tenant Name',
    'subject_of_tenancy' => 'Subject of Tenancy',
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'unit_no' => 'Unit No',
    'bed_space_no' => 'Bed Space Number',
    'rent' => 'Rent (AED)',
    'terms_of_payment' => 'Terms of Payment',
    'security_deposit' => 'Security Deposit (AED)',
];

if (!function_exists('bsc_base_path')) {
    function bsc_base_path(): string
    {
        return dirname(__DIR__, 3);
    }

    function bsc_ensure_tables(PDO $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `re_letterheads` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `name` VARCHAR(150) NOT NULL,
              `type` ENUM('images','pdf') NOT NULL DEFAULT 'images',
              `header_path` VARCHAR(500) DEFAULT NULL,
              `footer_path` VARCHAR(500) DEFAULT NULL,
              `pdf_path` VARCHAR(500) DEFAULT NULL,
              `margin_top` DECIMAL(5,1) NOT NULL DEFAULT 45.0,
              `margin_bottom` DECIMAL(5,1) NOT NULL DEFAULT 30.0,
              `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
              `is_default` TINYINT(1) NOT NULL DEFAULT 0,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_by` INT(11) DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `re_bed_space_contracts` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `lease_id` INT(11) DEFAULT NULL,
              `letterhead_id` INT(11) DEFAULT NULL,
              `plot_no` VARCHAR(100) DEFAULT NULL,
              `agreement_date` DATE DEFAULT NULL,
              `landlord_name` VARCHAR(255) DEFAULT NULL,
              `tenant_name` VARCHAR(255) DEFAULT NULL,
              `subject_of_tenancy` VARCHAR(255) DEFAULT NULL,
              `start_date` DATE DEFAULT NULL,
              `end_date` DATE DEFAULT NULL,
              `unit_no` VARCHAR(100) DEFAULT NULL,
              `bed_space_no` VARCHAR(50) DEFAULT NULL,
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
              KEY `idx_lease` (`lease_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done = true;
    }

    /**
     * All letterheads of the company. The first call adds the built-in Ain Al Reem one,
     * which uses the existing uploads/contracts/header.* and footer.* files.
     */
    function bsc_letterheads(PDO $conn, int $companyId, bool $activeOnly = false): array
    {
        bsc_ensure_tables($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM re_letterheads WHERE company_id = ? AND is_builtin = 1");
        $stmt->execute([$companyId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM re_letterheads WHERE company_id = ? AND is_default = 1");
            $stmt->execute([$companyId]);
            $hasDefault = (int)$stmt->fetchColumn() > 0;
            $stmt = $conn->prepare("
                INSERT INTO re_letterheads (company_id, name, is_builtin, is_default, is_active)
                VALUES (?, 'Ain Al Reem', 1, ?, 1)
            ");
            $stmt->execute([$companyId, $hasDefault ? 0 : 1]);
        }
        $sql = "SELECT * FROM re_letterheads WHERE company_id = ?" . ($activeOnly ? " AND is_active = 1" : "")
            . " ORDER BY is_default DESC, is_builtin DESC, name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The chosen letterhead, else the default, else the built-in one. */
    function bsc_letterhead(PDO $conn, int $companyId, ?int $letterheadId): array
    {
        $all = bsc_letterheads($conn, $companyId, true);
        foreach ($all as $lh) {
            if ($letterheadId && (int)$lh['id'] === $letterheadId) {
                return $lh;
            }
        }
        foreach ($all as $lh) {
            if ((int)$lh['is_default'] === 1) {
                return $lh;
            }
        }
        foreach ($all as $lh) {
            if ((int)$lh['is_builtin'] === 1) {
                return $lh;
            }
        }
        return $all[0] ?? ['id' => 0, 'name' => 'Ain Al Reem', 'header_path' => null, 'footer_path' => null, 'is_builtin' => 1];
    }

    function bsc_image_data_uri(?string $relPath): ?string
    {
        if (!$relPath) {
            return null;
        }
        $full = bsc_base_path() . '/' . ltrim($relPath, '/');
        if (!is_file($full) || !is_readable($full)) {
            return null;
        }
        $info = @getimagesize($full);
        if (!$info) {
            return null;
        }
        return 'data:' . $info['mime'] . ';base64,' . base64_encode(file_get_contents($full));
    }

    /** The existing uploads/contracts/header.* or footer.* used by the Tenancy Contract. */
    function bsc_builtin_image_path(string $kind): ?string
    {
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $rel = 'uploads/contracts/' . $kind . '.' . $ext;
            if (is_file(bsc_base_path() . '/' . $rel)) {
                return $rel;
            }
        }
        return null;
    }

    /**
     * Header and footer images of a letterhead as data URIs.
     * A missing image falls back to the Ain Al Reem one, then to the text version (null).
     */
    function bsc_letterhead_images(array $lh): array
    {
        $out = [];
        foreach (['header', 'footer'] as $kind) {
            $uri = bsc_image_data_uri($lh[$kind . '_path'] ?? null);
            if ($uri === null) {
                $uri = bsc_image_data_uri(bsc_builtin_image_path($kind));
            }
            $out[$kind] = $uri;
        }
        return $out;
    }

    function bsc_header_html(array $images, string $companyName): string
    {
        if ($images['header']) {
            return '<div style="text-align:center;"><img src="' . $images['header'] . '" style="width:100%; height:100px;"></div>';
        }
        $companyName = htmlspecialchars($companyName ?: 'AIN AL REEM PROPERTIES L.L.C.', ENT_QUOTES, 'UTF-8');
        return '<div style="text-align:center;border-bottom:1px solid #000;font-family:dejavusans;font-size:8px;padding-bottom:4px;">'
            . '<div style="font-size:11px;font-weight:bold;">' . $companyName . '</div>'
            . '<div>Real Estate and Management Company</div>'
            . '</div>';
    }

    function bsc_footer_html(array $images): string
    {
        if ($images['footer']) {
            return '<div style="text-align:center;"><img src="' . $images['footer'] . '" style="width:100%; height:80px;"></div>';
        }
        return '<div style="text-align:center;font-family:dejavusans;font-size:7px;border-top:1px solid #ddd;padding-top:3px;">'
            . '<div>Phone: +971 544603667; +971 562436573</div>'
            . '<div>Email: ar.properties15@gmail.com</div>'
            . '<div>Address: OFFICE P01, AYLA RESIDENCE, AL BARSHA SOUTH FOURTH, DUBAI</div>'
            . '</div>';
    }

    /**
     * The Bed Space template row. Added on first use from templates/bed_space_rental_agreement.html,
     * inactive so it does not show in the Tenancy Contract template lists on the lease pages.
     */
    function bsc_template(PDO $conn, int $companyId, ?int $userId): array
    {
        $stmt = $conn->prepare("SELECT * FROM re_contract_templates WHERE company_id = ? AND template_name = ? ORDER BY id LIMIT 1");
        $stmt->execute([$companyId, BSC_TEMPLATE_NAME]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tpl) {
            return $tpl;
        }
        $html = file_get_contents(dirname(__DIR__) . '/templates/bed_space_rental_agreement.html');
        $stmt = $conn->prepare("
            INSERT INTO re_contract_templates
                (company_id, template_name, template_type, description, template_content, template_html, template_format, is_active, is_default, created_by)
            VALUES (?, ?, 'standard', ?, '', ?, 'html', 0, 0, ?)
        ");
        $stmt->execute([
            $companyId,
            BSC_TEMPLATE_NAME,
            'Used by Leases > Bed Space Contract. Inactive so it is not offered on the Tenancy Contract screens.',
            $html,
            $userId,
        ]);
        $stmt = $conn->prepare("SELECT * FROM re_contract_templates WHERE id = ?");
        $stmt->execute([(int)$conn->lastInsertId()]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** The company name, used as the landlord on a new agreement and in the text header. */
    function bsc_company_name(PDO $conn, int $companyId): string
    {
        $stmt = $conn->prepare("SELECT name FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        return (string)$stmt->fetchColumn();
    }

    /** Agreement field values taken from a lease, the same way the Tenancy Contract fills them. */
    function bsc_values_from_lease(PDO $conn, int $companyId, int $leaseId): ?array
    {
        $stmt = $conn->prepare("
            SELECT l.*,
                   t.first_name, t.last_name, t.company_name AS tenant_company_name,
                   u.unit_number,
                   b.name AS building_name, b.plot_number,
                   c.name AS company_name
            FROM re_leases l
            JOIN re_tenants t ON t.id = l.tenant_id
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN companies c ON c.id = l.company_id
            WHERE l.id = ? AND l.company_id = ?
        ");
        $stmt->execute([$leaseId, $companyId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lease) {
            return null;
        }

        $tenantName = trim((string)($lease['tenant_company_name'] ?? ''));
        if ($tenantName === '') {
            $tenantName = trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''));
        }

        $propertyName = $lease['building_name'] ?? '';
        $unitNo = $lease['unit_number'] ?? '';
        if (!empty($lease['is_multi_unit'])) {
            $stmt = $conn->prepare("
                SELECT u.unit_number, b.name AS building_name
                FROM re_lease_units lu
                JOIN re_units u ON u.id = lu.unit_id
                JOIN re_buildings b ON b.id = u.building_id
                WHERE lu.lease_id = ?
                ORDER BY lu.sort_order ASC, lu.id ASC
            ");
            $stmt->execute([$lease['id']]);
            $leaseUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($leaseUnits) {
                $unitNo = implode(', ', array_filter(array_map(fn($u) => trim((string)$u['unit_number']), $leaseUnits), 'strlen'));
                $buildingNames = array_values(array_unique(array_filter(array_map(fn($u) => trim((string)$u['building_name']), $leaseUnits), 'strlen')));
                if ($buildingNames) {
                    $propertyName = implode(', ', $buildingNames);
                }
            }
        }

        if (isset($lease['number_of_installments']) && (int)$lease['number_of_installments'] > 0) {
            $installments = (int)$lease['number_of_installments'];
        } else {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM re_lease_installments WHERE lease_id = ?');
            $stmt->execute([$lease['id']]);
            $installments = (int)$stmt->fetchColumn() ?: 12;
        }
        $paymentMethod = !empty($lease['payment_method']) ? ucfirst(str_replace('_', ' ', $lease['payment_method'])) : 'Cheque';
        $annualRent = isset($lease['annual_rent']) ? (float)$lease['annual_rent'] : ((float)($lease['monthly_rent'] ?? 0) * 12);

        return [
            'plot_no' => (string)($lease['plot_number'] ?? ''),
            'landlord_name' => (string)($lease['company_name'] ?? ''),
            'tenant_name' => $tenantName,
            'subject_of_tenancy' => (string)$propertyName,
            'start_date' => (string)($lease['start_date'] ?? ''),
            'end_date' => (string)($lease['end_date'] ?? ''),
            'unit_no' => (string)$unitNo,
            'rent' => number_format($annualRent, 2, '.', ''),
            'terms_of_payment' => $installments . ' installments via ' . $paymentMethod,
            'security_deposit' => number_format((float)($lease['security_deposit'] ?? 0), 2, '.', ''),
        ];
    }

    function bsc_agreement(PDO $conn, int $companyId, int $id): ?array
    {
        bsc_ensure_tables($conn);
        $stmt = $conn->prepare("SELECT * FROM re_bed_space_contracts WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function bsc_agreements(PDO $conn, int $companyId): array
    {
        bsc_ensure_tables($conn);
        $stmt = $conn->prepare("
            SELECT c.*, lh.name AS letterhead_name
            FROM re_bed_space_contracts c
            LEFT JOIN re_letterheads lh ON lh.id = c.letterhead_id
            WHERE c.company_id = ?
            ORDER BY c.id DESC
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Field values from the posted form or query string, cleaned up. */
    function bsc_input(array $src): array
    {
        $out = [];
        foreach (array_keys(BSC_FIELDS) as $key) {
            $val = trim((string)($src[$key] ?? ''));
            if (in_array($key, ['agreement_date', 'start_date', 'end_date'], true)) {
                $val = preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : '';
            } elseif (in_array($key, ['rent', 'security_deposit'], true)) {
                $val = str_replace(',', '', $val);
                $val = is_numeric($val) ? number_format((float)$val, 2, '.', '') : '';
            }
            $out[$key] = $val;
        }
        $out['lease_id'] = (int)($src['lease_id'] ?? 0) ?: null;
        $out['letterhead_id'] = (int)($src['letterhead_id'] ?? 0) ?: null;
        return $out;
    }

    /** Saves a draft agreement (new when $id is null) and returns its id. Final and cancelled ones are locked. */
    function bsc_save_agreement(PDO $conn, int $companyId, ?int $id, array $data, ?int $userId): int
    {
        bsc_ensure_tables($conn);
        $cols = array_merge(array_keys(BSC_FIELDS), ['lease_id', 'letterhead_id']);
        $vals = array_map(fn($c) => ($data[$c] ?? '') === '' ? null : $data[$c], $cols);
        if ($id) {
            $existing = bsc_agreement($conn, $companyId, $id);
            if (!$existing || $existing['status'] !== 'draft') {
                throw new RuntimeException('Only draft agreements can be edited.');
            }
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
            $stmt = $conn->prepare("UPDATE re_bed_space_contracts SET $set, updated_by = ? WHERE id = ? AND company_id = ? AND status = 'draft'");
            $stmt->execute(array_merge($vals, [$userId, $id, $companyId]));
            return $id;
        }
        $list = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $marks = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $conn->prepare("INSERT INTO re_bed_space_contracts (company_id, $list, created_by) VALUES (?, $marks, ?)");
        $stmt->execute(array_merge([$companyId], $vals, [$userId]));
        return (int)$conn->lastInsertId();
    }

    function bsc_status_badge(string $status, string $extraClass = ''): string
    {
        $map = [
            'draft' => ['bg-warning text-dark', 'Draft'],
            'final' => ['bg-success', 'Final'],
            'cancelled' => ['bg-danger', 'Cancelled'],
        ];
        [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
        return '<span class="badge ' . $cls . ($extraClass ? ' ' . $extraClass : '') . '">' . $label . '</span>';
    }

    /** Deletes a draft agreement; final and cancelled ones are kept. */
    function bsc_delete_agreement(PDO $conn, int $companyId, int $id): void
    {
        $agreement = bsc_agreement($conn, $companyId, $id);
        if (!$agreement) {
            throw new RuntimeException('Agreement not found.');
        }
        if ($agreement['status'] !== 'draft') {
            throw new RuntimeException('Only draft agreements can be deleted.');
        }
        $conn->prepare("DELETE FROM re_bed_space_contracts WHERE id = ? AND company_id = ? AND status = 'draft'")->execute([$id, $companyId]);
    }

    /** Confirms a saved draft as final: makes the PDF from the saved fields and locks the agreement. */
    function bsc_confirm_agreement(PDO $conn, int $companyId, int $id, ?int $userId, string $companyName): void
    {
        $agreement = bsc_agreement($conn, $companyId, $id);
        if (!$agreement) {
            throw new RuntimeException('Agreement not found.');
        }
        if ($agreement['status'] !== 'draft') {
            throw new RuntimeException('Only draft agreements can be confirmed.');
        }
        $template = bsc_template($conn, $companyId, $userId);
        $letterhead = bsc_letterhead($conn, $companyId, (int)$agreement['letterhead_id'] ?: null);
        $pdfPath = bsc_generate_pdf(bsc_fill($template['template_html'], $agreement), $letterhead, $companyName, $id);
        $conn->prepare("
            UPDATE re_bed_space_contracts
            SET contract_path = ?, generated_at = NOW(), status = 'final', confirmed_at = NOW(), confirmed_by = ?
            WHERE id = ? AND company_id = ? AND status = 'draft'
        ")->execute([$pdfPath, $userId, $id, $companyId]);
    }

    /** Cancels a final agreement; the reason is required. */
    function bsc_cancel_agreement(PDO $conn, int $companyId, int $id, string $reason, ?int $userId): void
    {
        $agreement = bsc_agreement($conn, $companyId, $id);
        if (!$agreement) {
            throw new RuntimeException('Agreement not found.');
        }
        if ($agreement['status'] !== 'final') {
            throw new RuntimeException('Only final agreements can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Please enter the reason for cancelling.');
        }
        $conn->prepare("
            UPDATE re_bed_space_contracts
            SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
            WHERE id = ? AND company_id = ? AND status = 'final'
        ")->execute([$userId, trim($reason), $id, $companyId]);
    }

    /** Fills the template placeholders from the agreement fields. Empty fields print a blank line. */
    function bsc_fill(string $html, array $data): string
    {
        $blank = '_______________';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $text = fn($k) => trim((string)($data[$k] ?? '')) !== '' ? $e(trim((string)$data[$k])) : $blank;
        $date = fn($k) => !empty($data[$k]) ? date('d/m/Y', strtotime($data[$k])) : $blank;
        $money = fn($k) => ($data[$k] ?? '') !== '' ? number_format((float)$data[$k], 2) : $blank;

        $replacements = [
            // No blank line for Plot No: only the value, when filled in
            '{{PLOT_NO}}' => $e(trim((string)($data['plot_no'] ?? ''))),
            '{{TODAY_DATE}}' => $date('agreement_date'),
            '{{LANDLORD_NAME}}' => $text('landlord_name'),
            '{{TENANT_NAME}}' => $text('tenant_name'),
            '{{PROPERTY_NAME}}' => $text('subject_of_tenancy'),
            '{{START_DATE}}' => $date('start_date'),
            '{{END_DATE}}' => $date('end_date'),
            '{{UNIT_NO}}' => $text('unit_no'),
            '{{BED_SPACE_NO}}' => $text('bed_space_no'),
            '{{ANNUAL_RENT}}' => $money('rent'),
            '{{TERMS_OF_PAYMENT}}' => $text('terms_of_payment'),
            '{{SECURITY_DEPOSIT}}' => $money('security_deposit'),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /** A letterhead that is a full-page PDF whose file is there. */
    function bsc_letterhead_pdf_file(array $lh): ?string
    {
        if (($lh['type'] ?? 'images') !== 'pdf' || empty($lh['pdf_path'])) {
            return null;
        }
        $full = bsc_base_path() . '/' . ltrim($lh['pdf_path'], '/');
        return is_file($full) ? $full : null;
    }

    /**
     * Browser preview: the filled contract on an A4-width sheet with the letterhead shown.
     * A PDF letterhead is drawn with pdf.js: its top and bottom (the kept-clear margins) above and below the text.
     */
    function bsc_preview_html(string $filledHtml, array $letterhead, string $companyName, string $letterheadPdfUrl = ''): string
    {
        $style = '<style>
            body { background: #e9ecef !important; }
            .bsc-sheet { width: 210mm; max-width: 100%; box-sizing: border-box; margin: 12px auto; padding: 8mm 15mm 10mm; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.15); }
            .bsc-sheet > .bsc-head { margin: 0 -7mm 8mm; }
            .bsc-sheet > .bsc-foot { margin: 10mm -7mm 0; }
            .bsc-sheet.bsc-pdf { padding: 0 15mm; }
            .bsc-pdf > .bsc-head, .bsc-pdf > .bsc-foot { margin: 0 -15mm; }
            .bsc-pdf > .bsc-head { margin-bottom: 4mm; }
            .bsc-pdf > .bsc-foot { margin-top: 6mm; }
            .bsc-pdf img.bsc-lh { display: block; width: 100%; }
            .page-break { border-top: 2px dashed #adb5bd; margin: 10mm -15mm; position: relative; }
            .page-break::after { content: "Page 2"; position: absolute; top: -9px; left: 50%; transform: translateX(-50%); background: #fff; color: #6c757d; font-size: 10px; padding: 0 8px; }
        </style>';
        $html = str_replace('</head>', $style . '</head>', $filledHtml);

        if ($letterheadPdfUrl !== '' && bsc_letterhead_pdf_file($letterhead)) {
            $mt = (float)$letterhead['margin_top'];
            $mb = (float)$letterhead['margin_bottom'];
            $head = '<div class="bsc-head"><img class="bsc-lh" id="bscLhTop" alt=""></div>';
            $foot = '<div class="bsc-foot"><img class="bsc-lh" id="bscLhBottom" alt=""></div>'
                . '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>'
                . '<script>
                    pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
                    pdfjsLib.getDocument(' . json_encode($letterheadPdfUrl) . ').promise
                        .then(function (doc) { return doc.getPage(1); })
                        .then(function (page) {
                            var v = page.getViewport({ scale: 2.5 });
                            var c = document.createElement("canvas");
                            c.width = v.width; c.height = v.height;
                            return page.render({ canvasContext: c.getContext("2d"), viewport: v }).promise.then(function () {
                                var pxPerMm = v.height / (page.view[3] * 25.4 / 72);
                                function crop(y, h) {
                                    var o = document.createElement("canvas");
                                    o.width = c.width; o.height = Math.max(1, Math.round(h));
                                    o.getContext("2d").drawImage(c, 0, Math.round(y), c.width, o.height, 0, 0, c.width, o.height);
                                    return o.toDataURL("image/png");
                                }
                                document.getElementById("bscLhTop").src = crop(0, ' . $mt . ' * pxPerMm);
                                document.getElementById("bscLhBottom").src = crop(c.height - ' . $mb . ' * pxPerMm, ' . $mb . ' * pxPerMm);
                            });
                        });
                </script>';
            $html = preg_replace('/<body([^>]*)>/', '<body$1><div class="bsc-sheet bsc-pdf">' . $head, $html, 1);
            return str_replace('</body>', $foot . '</div></body>', $html);
        }

        $images = bsc_letterhead_images($letterhead);
        $head = '<div class="bsc-head">' . bsc_header_html($images, $companyName) . '</div>';
        $foot = '<div class="bsc-foot">' . bsc_footer_html($images) . '</div>';
        $html = preg_replace('/<body([^>]*)>/', '<body$1><div class="bsc-sheet">' . str_replace('$', '\$', $head), $html, 1);
        return str_replace('</body>', $foot . '</div></body>', $html);
    }

    /**
     * Makes the PDF with mPDF (same settings as the Tenancy Contract) and returns its relative path.
     * A PDF letterhead is put behind every page, with its own top and bottom margins.
     */
    function bsc_generate_pdf(string $filledHtml, array $letterhead, string $companyName, int $agreementId): string
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
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => $pdfLetterhead ? (float)$letterhead['margin_top'] : 35,
            'margin_bottom' => $pdfLetterhead ? (float)$letterhead['margin_bottom'] : 30,
            'margin_left' => 15,
            'margin_right' => 15,
            'tempDir' => $base . '/uploads/temp',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
        $mpdf->SetDirectionality('auto');
        if ($pdfLetterhead) {
            $mpdf->SetDocTemplate($pdfLetterhead, true);
        } else {
            $images = bsc_letterhead_images($letterhead);
            $mpdf->SetHTMLHeader(bsc_header_html($images, $companyName));
            $mpdf->SetHTMLFooter(bsc_footer_html($images));
        }
        $mpdf->WriteHTML($filledHtml);

        $rel = 'uploads/contracts/bed_space_agreement_' . $agreementId . '_' . date('YmdHis') . '.pdf';
        $mpdf->Output($base . '/' . $rel, \Mpdf\Output\Destination::FILE);
        return $rel;
    }

    /** Saves an uploaded header/footer image and returns its relative path, or null when none was sent. */
    function bsc_save_letterhead_upload(string $field, int $companyId): ?string
    {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $file = $_FILES[$field];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Image upload failed (error ' . (int)$file['error'] . ').');
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Image is larger than 5 MB.');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2] ?? 0] ?? null;
        if (!$ext) {
            throw new RuntimeException('Please upload a PNG or JPG image.');
        }
        $dir = bsc_base_path() . '/' . BSC_LETTERHEAD_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Cannot create ' . BSC_LETTERHEAD_DIR . '.');
        }
        $name = 'c' . $companyId . '_' . $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('Failed to save the uploaded image.');
        }
        return BSC_LETTERHEAD_DIR . '/' . $name;
    }

    /**
     * Saves an uploaded full-page letterhead PDF and returns its relative path, or null when none was sent.
     * The file is opened with mPDF first, so a PDF it cannot use (e.g. password protected) is refused now.
     */
    function bsc_save_letterhead_pdf_upload(string $field, int $companyId): ?string
    {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $file = $_FILES[$field];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('PDF upload failed (error ' . (int)$file['error'] . ').');
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('PDF is larger than 10 MB.');
        }
        if (file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') {
            throw new RuntimeException('Please upload a PDF file (in Word: File > Save As > PDF).');
        }
        require_once bsc_base_path() . '/vendor/autoload.php';
        try {
            $check = new \Mpdf\Mpdf(['tempDir' => bsc_base_path() . '/uploads/temp']);
            if ($check->setSourceFile($file['tmp_name']) < 1) {
                throw new RuntimeException('The PDF has no pages.');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('This PDF cannot be used as a letterhead: ' . $e->getMessage());
        }
        $dir = bsc_base_path() . '/' . BSC_LETTERHEAD_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Cannot create ' . BSC_LETTERHEAD_DIR . '.');
        }
        $name = 'c' . $companyId . '_page_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('Failed to save the uploaded PDF.');
        }
        return BSC_LETTERHEAD_DIR . '/' . $name;
    }

    /** Add or edit a letterhead from the popup. Returns the message to show. */
    function bsc_save_letterhead(PDO $conn, int $companyId, ?int $letterheadId, array $post, ?int $userId): string
    {
        $existing = null;
        if ($letterheadId) {
            $stmt = $conn->prepare("SELECT * FROM re_letterheads WHERE id = ? AND company_id = ?");
            $stmt->execute([$letterheadId, $companyId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $name = trim($post['lh_name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Letterhead name is required.');
        }
        $isDefault = !empty($post['lh_default']) ? 1 : 0;
        // The built-in Ain Al Reem letterhead stays an image one
        $type = ($existing && (int)$existing['is_builtin'] === 1) ? 'images' : (($post['lh_type'] ?? 'images') === 'pdf' ? 'pdf' : 'images');
        $marginTop = max(0, min(150, (float)($post['lh_margin_top'] ?? 45)));
        $marginBottom = max(0, min(150, (float)($post['lh_margin_bottom'] ?? 30)));

        $headerPath = $existing['header_path'] ?? null;
        $footerPath = $existing['footer_path'] ?? null;
        $pdfPath = $existing['pdf_path'] ?? null;
        if ($type === 'pdf') {
            $pdfPath = bsc_save_letterhead_pdf_upload('letterhead_pdf', $companyId) ?: $pdfPath;
            if (!$pdfPath) {
                throw new RuntimeException('Please upload the letterhead PDF.');
            }
        } else {
            $headerPath = bsc_save_letterhead_upload('header_image', $companyId) ?: $headerPath;
            $footerPath = bsc_save_letterhead_upload('footer_image', $companyId) ?: $footerPath;
            if (!$headerPath && !($existing && (int)$existing['is_builtin'] === 1)) {
                throw new RuntimeException('Please upload a header image.');
            }
        }

        $conn->beginTransaction();
        try {
            if ($isDefault) {
                $conn->prepare("UPDATE re_letterheads SET is_default = 0 WHERE company_id = ?")->execute([$companyId]);
            }
            if ($existing) {
                $stmt = $conn->prepare("
                    UPDATE re_letterheads
                    SET name = ?, type = ?, header_path = ?, footer_path = ?, pdf_path = ?, margin_top = ?, margin_bottom = ?, is_default = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$name, $type, $headerPath, $footerPath, $pdfPath, $marginTop, $marginBottom, $isDefault, $existing['id'], $companyId]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO re_letterheads (company_id, name, type, header_path, footer_path, pdf_path, margin_top, margin_bottom, is_default, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([$companyId, $name, $type, $headerPath, $footerPath, $pdfPath, $marginTop, $marginBottom, $isDefault, $userId]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        return $existing ? 'Letterhead updated.' : 'Letterhead added.';
    }

    /** Deletes a letterhead; the built-in Ain Al Reem one cannot be deleted. */
    function bsc_delete_letterhead(PDO $conn, int $companyId, int $letterheadId): string
    {
        $stmt = $conn->prepare("SELECT * FROM re_letterheads WHERE id = ? AND company_id = ?");
        $stmt->execute([$letterheadId, $companyId]);
        $lh = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lh) {
            throw new RuntimeException('Letterhead not found.');
        }
        if ((int)$lh['is_builtin'] === 1) {
            throw new RuntimeException('The Ain Al Reem letterhead cannot be deleted.');
        }
        $conn->prepare("DELETE FROM re_letterheads WHERE id = ? AND company_id = ?")->execute([$letterheadId, $companyId]);
        if ((int)$lh['is_default'] === 1) {
            $conn->prepare("UPDATE re_letterheads SET is_default = 1 WHERE company_id = ? AND is_builtin = 1")->execute([$companyId]);
        }
        return 'Letterhead deleted.';
    }
}
