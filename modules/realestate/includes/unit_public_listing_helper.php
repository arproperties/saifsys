<?php
/**
 * Real Estate — public long-term unit listing helper.
 *
 * Used by ERP pages only in Phase 1. It keeps Find Your Home fields/media/leads
 * separate from ARS short-term stays and tenant portal records.
 */

declare(strict_types=1);

if (!function_exists('re_unit_public_column_exists')) {
    function re_unit_public_column_exists(PDO $conn, string $table, string $column): bool {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('re_unit_public_index_exists')) {
    function re_unit_public_index_exists(PDO $conn, string $table, string $index): bool {
        $stmt = $conn->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$index]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('re_unit_public_add_column')) {
    function re_unit_public_add_column(PDO $conn, string $table, string $column, string $definition): void {
        if (!re_unit_public_column_exists($conn, $table, $column)) {
            $conn->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

if (!function_exists('re_unit_public_ensure_schema')) {
    function re_unit_public_ensure_schema(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;

        // ARS already introduced some reusable listing columns in many installs.
        re_unit_public_add_column($conn, 're_units', 'listing_title', "VARCHAR(200) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'short_description', "TEXT DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'amenities_json', "TEXT DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'rental_mode', "ENUM('long_term','short_term','both') NOT NULL DEFAULT 'long_term'");

        re_unit_public_add_column($conn, 're_units', 'publish_to_mobile', "TINYINT(1) NOT NULL DEFAULT 0");
        re_unit_public_add_column($conn, 're_units', 'marketing_status', "ENUM('ready_to_move','under_maintenance','coming_soon','reserved') NOT NULL DEFAULT 'ready_to_move'");
        re_unit_public_add_column($conn, 're_units', 'listing_description', "TEXT DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'cheques_count', "TINYINT UNSIGNED DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'security_deposit', "DECIMAL(12,2) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'commission', "DECIMAL(12,2) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'unit_size_sqft', "DECIMAL(10,2) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'bedrooms', "TINYINT UNSIGNED DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'bathrooms', "DECIMAL(3,1) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'parking_count', "TINYINT UNSIGNED DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'balcony', "TINYINT(1) NOT NULL DEFAULT 0");
        re_unit_public_add_column($conn, 're_units', 'floor_plan_file', "VARCHAR(500) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'virtual_tour_url', "VARCHAR(500) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'map_url', "VARCHAR(500) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'latitude', "DECIMAL(10,8) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'longitude', "DECIMAL(11,8) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'whatsapp_contact', "VARCHAR(50) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'call_contact', "VARCHAR(50) DEFAULT NULL");
        re_unit_public_add_column($conn, 're_units', 'featured', "TINYINT(1) NOT NULL DEFAULT 0");
        re_unit_public_add_column($conn, 're_units', 'sort_order', "INT NOT NULL DEFAULT 0");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS re_unit_public_media (
                id INT NOT NULL AUTO_INCREMENT,
                company_id INT NOT NULL,
                unit_id INT NOT NULL,
                media_type ENUM('photo','floor_plan') NOT NULL DEFAULT 'photo',
                file_path VARCHAR(500) NOT NULL,
                file_name VARCHAR(255) DEFAULT NULL,
                mime_type VARCHAR(100) DEFAULT NULL,
                file_size INT DEFAULT NULL,
                title VARCHAR(150) DEFAULT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                uploaded_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_unit_media (unit_id, media_type, sort_order),
                KEY idx_primary_photo (unit_id, media_type, is_primary),
                KEY idx_company_unit (company_id, unit_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS re_unit_viewing_requests (
                id INT NOT NULL AUTO_INCREMENT,
                company_id INT NOT NULL,
                unit_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                phone VARCHAR(50) NOT NULL,
                email VARCHAR(150) DEFAULT NULL,
                preferred_date DATE DEFAULT NULL,
                preferred_time TIME DEFAULT NULL,
                message TEXT DEFAULT NULL,
                status ENUM('new','contacted','viewing_scheduled','completed','cancelled','closed') NOT NULL DEFAULT 'new',
                admin_notes TEXT DEFAULT NULL,
                assigned_to INT DEFAULT NULL,
                reviewed_by INT DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_viewing_unit_status (unit_id, status),
                KEY idx_viewing_company_status (company_id, status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS re_unit_lease_applications (
                id INT NOT NULL AUTO_INCREMENT,
                company_id INT NOT NULL,
                unit_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                phone VARCHAR(50) NOT NULL,
                email VARCHAR(150) DEFAULT NULL,
                nationality VARCHAR(100) DEFAULT NULL,
                employer VARCHAR(150) DEFAULT NULL,
                move_in_date DATE DEFAULT NULL,
                occupants_count TINYINT UNSIGNED DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                status ENUM('new','contacted','documents_requested','applied','converted','rejected','closed') NOT NULL DEFAULT 'new',
                converted_tenant_id INT DEFAULT NULL,
                converted_lease_id INT DEFAULT NULL,
                admin_notes TEXT DEFAULT NULL,
                assigned_to INT DEFAULT NULL,
                reviewed_by INT DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_application_unit_status (unit_id, status),
                KEY idx_application_company_status (company_id, status, created_at),
                KEY idx_application_converted (converted_tenant_id, converted_lease_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try {
            if (!re_unit_public_index_exists($conn, 're_units', 'idx_mobile_listing')) {
                $conn->exec("ALTER TABLE re_units ADD INDEX idx_mobile_listing (publish_to_mobile, rental_mode, status, marketing_status, featured, sort_order)");
            }
            if (!re_unit_public_index_exists($conn, 're_units', 'idx_mobile_price')) {
                $conn->exec("ALTER TABLE re_units ADD INDEX idx_mobile_price (annual_rent)");
            }
            if (!re_unit_public_index_exists($conn, 're_units', 'idx_mobile_bedrooms')) {
                $conn->exec("ALTER TABLE re_units ADD INDEX idx_mobile_bedrooms (bedrooms)");
            }
            if (!re_unit_public_index_exists($conn, 're_units', 'idx_mobile_building')) {
                $conn->exec("ALTER TABLE re_units ADD INDEX idx_mobile_building (building_id, publish_to_mobile)");
            }
        } catch (Throwable $e) {
            error_log('Find Home unit listing indexes skipped: ' . $e->getMessage());
        }

        try {
            $conn->exec("UPDATE re_units SET unit_size_sqft = ROUND(area_sqm * 10.7639, 2) WHERE unit_size_sqft IS NULL AND area_sqm IS NOT NULL");
            $conn->exec("
                UPDATE re_units
                SET bedrooms = CASE unit_type
                    WHEN 'studio' THEN 0
                    WHEN '1br' THEN 1
                    WHEN '2br' THEN 2
                    WHEN '3br' THEN 3
                    WHEN '4br' THEN 4
                    ELSE bedrooms
                END
                WHERE bedrooms IS NULL
            ");
        } catch (Throwable $e) {
            error_log('Find Home unit listing backfill skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('re_unit_public_media_url')) {
    function re_unit_public_media_url(?string $path): string {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return '../../' . ltrim($path, '/');
    }
}

if (!function_exists('re_unit_public_media_rows')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_unit_public_media_rows(PDO $conn, int $companyId, int $unitId, ?string $type = null): array {
        $sql = "SELECT * FROM re_unit_public_media WHERE company_id = ? AND unit_id = ?";
        $params = [$companyId, $unitId];
        if ($type !== null) {
            $sql .= " AND media_type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY is_primary DESC, sort_order ASC, id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_unit_public_status_label')) {
    function re_unit_public_status_label(string $status): string {
        return [
            'ready_to_move' => 'Ready to Move',
            'under_maintenance' => 'Under Maintenance',
            'coming_soon' => 'Coming Soon',
            'reserved' => 'Reserved',
        ][$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('re_unit_public_status_badge')) {
    function re_unit_public_status_badge(string $status): string {
        return [
            'ready_to_move' => 'success',
            'under_maintenance' => 'warning',
            'coming_soon' => 'info',
            'reserved' => 'secondary',
        ][$status] ?? 'secondary';
    }
}

if (!function_exists('re_unit_public_parse_amenities')) {
    /**
     * @return list<string>
     */
    function re_unit_public_parse_amenities(?string $raw): array {
        $raw = trim((string)$raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded), fn($v) => trim($v) !== ''));
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}

if (!function_exists('re_unit_public_normalize_amenities')) {
    function re_unit_public_normalize_amenities(string $raw): ?string {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
        return $items ? json_encode($items, JSON_UNESCAPED_SLASHES) : null;
    }
}

if (!function_exists('re_unit_public_upload_error_message')) {
    function re_unit_public_upload_error_message(int $code): string {
        return [
            UPLOAD_ERR_INI_SIZE => 'The file is larger than the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The file is larger than the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temp folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        ][$code] ?? 'Unknown upload error.';
    }
}

if (!function_exists('re_unit_public_unit_allowed')) {
    function re_unit_public_unit_allowed(PDO $conn, int $companyId, int $unitId): bool {
        $stmt = $conn->prepare("SELECT 1 FROM re_units WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$unitId, $companyId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('re_unit_public_save_uploaded_media')) {
    function re_unit_public_save_uploaded_media(PDO $conn, int $companyId, int $unitId, string $mediaType, array $file, ?int $uploadedBy = null, ?string &$error = null): ?int {
        $error = null;
        if (!in_array($mediaType, ['photo', 'floor_plan'], true)) {
            $error = 'Invalid media type.';
            return null;
        }
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $error = re_unit_public_upload_error_message($uploadError);
            return null;
        }

        $allowedImages = [
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/x-png' => 'png',
            'image/webp' => 'webp',
        ];
        $allowedPlans = $allowedImages + ['application/pdf' => 'pdf'];
        $mime = (string)($file['type'] ?? '');
        $nameExt = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
            ][$nameExt] ?? '';
        }
        $allowed = $mediaType === 'photo' ? $allowedImages : $allowedPlans;
        if (!isset($allowed[$mime])) {
            $error = $mediaType === 'photo'
                ? 'Unsupported photo type. Please upload JPG, PNG, or WebP.'
                : 'Unsupported floor plan type. Please upload JPG, PNG, WebP, or PDF.';
            return null;
        }

        $baseDir = dirname(__DIR__, 3) . '/uploads/realestate/unit_public/' . $unitId . '/' . ($mediaType === 'photo' ? 'photos' : 'floor_plans');
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            error_log('unit public media: could not create ' . $baseDir);
            $error = 'Could not create the upload folder. Please check server permissions.';
            return null;
        }
        @chmod(dirname($baseDir), 0777);
        @chmod($baseDir, 0777);
        if (!is_writable($baseDir)) {
            error_log('unit public media: dir not writable ' . $baseDir);
            $error = 'Upload folder is not writable. Please check server permissions.';
            return null;
        }

        $ext = $allowed[$mime];
        $safe = $mediaType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $abs = $baseDir . '/' . $safe;
        if (!move_uploaded_file((string)$file['tmp_name'], $abs)) {
            error_log('unit public media: move upload failed to ' . $abs);
            $error = 'Could not save file. Please check upload folder permissions.';
            return null;
        }
        @chmod($abs, 0666);

        $rel = 'uploads/realestate/unit_public/' . $unitId . '/' . ($mediaType === 'photo' ? 'photos' : 'floor_plans') . '/' . $safe;
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM re_unit_public_media WHERE company_id = ? AND unit_id = ? AND media_type = ?");
        $stmt->execute([$companyId, $unitId, $mediaType]);
        $sort = (int)$stmt->fetchColumn() + 1;
        $isPrimary = 0;
        if ($mediaType === 'photo') {
            $p = $conn->prepare("SELECT COUNT(*) FROM re_unit_public_media WHERE company_id = ? AND unit_id = ? AND media_type = 'photo'");
            $p->execute([$companyId, $unitId]);
            $isPrimary = ((int)$p->fetchColumn() === 0) ? 1 : 0;
        }

        $ins = $conn->prepare("
            INSERT INTO re_unit_public_media
            (company_id, unit_id, media_type, file_path, file_name, mime_type, file_size, is_primary, sort_order, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $companyId,
            $unitId,
            $mediaType,
            $rel,
            (string)($file['name'] ?? $safe),
            $mime,
            isset($file['size']) ? (int)$file['size'] : null,
            $isPrimary,
            $sort,
            $uploadedBy,
        ]);
        $mediaId = (int)$conn->lastInsertId();

        if ($mediaType === 'floor_plan') {
            $conn->prepare("UPDATE re_units SET floor_plan_file = ? WHERE id = ? AND company_id = ?")->execute([$rel, $unitId, $companyId]);
        }

        return $mediaId;
    }
}

if (!function_exists('re_unit_public_set_primary_media')) {
    function re_unit_public_set_primary_media(PDO $conn, int $companyId, int $unitId, int $mediaId): void {
        $conn->prepare("UPDATE re_unit_public_media SET is_primary = 0 WHERE company_id = ? AND unit_id = ? AND media_type = 'photo'")
            ->execute([$companyId, $unitId]);
        $conn->prepare("UPDATE re_unit_public_media SET is_primary = 1, updated_at = NOW() WHERE id = ? AND company_id = ? AND unit_id = ? AND media_type = 'photo'")
            ->execute([$mediaId, $companyId, $unitId]);
    }
}

if (!function_exists('re_unit_public_delete_media')) {
    function re_unit_public_delete_media(PDO $conn, int $companyId, int $unitId, int $mediaId): void {
        $stmt = $conn->prepare("SELECT * FROM re_unit_public_media WHERE id = ? AND company_id = ? AND unit_id = ? LIMIT 1");
        $stmt->execute([$mediaId, $companyId, $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $wasPrimary = !empty($row['is_primary']);
        $wasFloorPlan = ($row['media_type'] ?? '') === 'floor_plan';
        $full = dirname(__DIR__, 3) . '/' . ltrim((string)$row['file_path'], '/');
        if (is_file($full)) @unlink($full);

        $conn->prepare("DELETE FROM re_unit_public_media WHERE id = ? AND company_id = ? AND unit_id = ?")->execute([$mediaId, $companyId, $unitId]);

        if ($wasFloorPlan) {
            $next = $conn->prepare("SELECT file_path FROM re_unit_public_media WHERE company_id = ? AND unit_id = ? AND media_type = 'floor_plan' ORDER BY sort_order ASC, id ASC LIMIT 1");
            $next->execute([$companyId, $unitId]);
            $conn->prepare("UPDATE re_units SET floor_plan_file = ? WHERE id = ? AND company_id = ?")
                ->execute([$next->fetchColumn() ?: null, $unitId, $companyId]);
        }
        if ($wasPrimary) {
            $next = $conn->prepare("SELECT id FROM re_unit_public_media WHERE company_id = ? AND unit_id = ? AND media_type = 'photo' ORDER BY sort_order ASC, id ASC LIMIT 1");
            $next->execute([$companyId, $unitId]);
            $nextId = (int)($next->fetchColumn() ?: 0);
            if ($nextId > 0) {
                re_unit_public_set_primary_media($conn, $companyId, $unitId, $nextId);
            }
        }
    }
}
