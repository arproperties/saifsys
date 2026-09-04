<?php
/**
 * Public mobile API — Find Your Home long-term rentals.
 *
 * Routes:
 * GET  /api/mobile/long-term-rentals
 * GET  /api/mobile/long-term-rentals/filters
 * GET  /api/mobile/long-term-rentals/{unit_id}
 * POST /api/mobile/long-term-rentals/{unit_id}/viewing-request
 * POST /api/mobile/long-term-rentals/{unit_id}/lease-application
 */

declare(strict_types=1);

define('SKIP_RATE_LIMIT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../modules/realestate/includes/unit_public_listing_helper.php';
require_once __DIR__ . '/../../modules/realestate/includes/re_email_helper.php';

ini_set('serialize_precision', '-1');
re_unit_public_ensure_schema($conn);

if (!function_exists('ltr_route')) {
    function ltr_route(): string {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $uriPath = $uriPath === false ? '' : trim((string)$uriPath, '/');
        $marker = 'api/mobile/long-term-rentals';
        $pos = strpos($uriPath, $marker);
        if ($pos === false) return '';
        return trim(substr($uriPath, $pos + strlen($marker)), '/');
    }
}

if (!function_exists('ltr_company_id_filter')) {
    function ltr_company_id_filter(): ?int {
        $requested = filter_var($_GET['company_id'] ?? null, FILTER_VALIDATE_INT);
        return $requested && $requested > 0 ? $requested : null;
    }
}

if (!function_exists('ltr_read_body')) {
    /**
     * @return array<string,mixed>
     */
    function ltr_read_body(): array {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
        return $_POST ?: [];
    }
}

if (!function_exists('ltr_clean_text')) {
    function ltr_clean_text($value, int $max = 255): string {
        $text = trim(strip_tags((string)$value));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }
        return substr($text, 0, $max);
    }
}

if (!function_exists('ltr_nullable_text')) {
    function ltr_nullable_text($value, int $max = 255): ?string {
        $text = ltr_clean_text($value, $max);
        return $text === '' ? null : $text;
    }
}

if (!function_exists('ltr_valid_date')) {
    function ltr_valid_date(?string $date): ?string {
        $date = trim((string)$date);
        if ($date === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
    }
}

if (!function_exists('ltr_valid_time')) {
    function ltr_valid_time(?string $time): ?string {
        $time = trim((string)$time);
        if ($time === '') return null;
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time .= ':00';
        }
        $dt = DateTime::createFromFormat('H:i:s', $time);
        return ($dt && $dt->format('H:i:s') === $time) ? $time : null;
    }
}

if (!function_exists('ltr_require_fields')) {
    /**
     * @param array<string,mixed> $body
     * @param list<string> $fields
     */
    function ltr_require_fields(array $body, array $fields): void {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($body[$field]) || trim((string)$body[$field]) === '') {
                $missing[] = $field;
            }
        }
        if ($missing) {
            errorResponse('Missing required fields: ' . implode(', ', $missing), 422);
        }
    }
}

if (!function_exists('ltr_rate_limit')) {
    function ltr_rate_limit(string $action, int $unitId, int $limit = 5, int $windowSeconds = 600): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = hash('sha256', $action . '|' . $unitId . '|' . $ip);
        $dir = sys_get_temp_dir() . '/herosysgro_ltr_rate';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // Fail open if the temp directory is not writable.
        }
        $file = $dir . '/' . $key . '.json';
        $now = time();
        $hits = [];
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) {
                $hits = array_values(array_filter(array_map('intval', $decoded), fn($ts) => $ts > ($now - $windowSeconds)));
            }
        }
        if (count($hits) >= $limit) {
            errorResponse('Too many requests. Please try again later.', 429);
        }
        $hits[] = $now;
        @file_put_contents($file, json_encode($hits));
    }
}

if (!function_exists('ltr_base_where_sql')) {
    /**
     * Find Your Home listing eligibility.
     *
     * Occupancy rule (aligned with ERP unit vacancy / Active Lease UX):
     * only a non-deleted active lease blocks a published vacant unit.
     * draft / expired / terminated / renewed / soft-deleted do not block.
     */
    function ltr_base_where_sql(string $alias = 'u'): string {
        return "
            {$alias}.publish_to_mobile = 1
            AND {$alias}.rental_mode IN ('long_term', 'both')
            AND {$alias}.status = 'vacant'
            AND NOT EXISTS (
                SELECT 1
                FROM re_leases l
                LEFT JOIN re_lease_units lu ON lu.lease_id = l.id
                WHERE l.company_id = {$alias}.company_id
                  AND l.status = 'active'
                  AND l.deleted_at IS NULL
                  AND (l.unit_id = {$alias}.id OR lu.unit_id = {$alias}.id)
                LIMIT 1
            )
        ";
    }
}

if (!function_exists('ltr_media_url')) {
    function ltr_media_url(?string $path): ?string {
        $path = trim((string)$path);
        if ($path === '') return null;
        $normalized = str_replace('\\', '/', $path);
        if (strpos($normalized, '..') !== false) return null;
        if (!preg_match('#^uploads/realestate/unit_public/\d+/#', ltrim($normalized, '/'))) {
            return null;
        }
        return buildMediaUrl($normalized);
    }
}

if (!function_exists('ltr_validate_lead_identity')) {
    /**
     * @param array<string,mixed> $body
     */
    function ltr_validate_lead_identity(array $body): array {
        $fullName = ltr_clean_text($body['full_name'] ?? '', 150);
        $phone = ltr_clean_text($body['phone'] ?? '', 50);
        if (strlen($fullName) < 2) {
            errorResponse('full_name must be at least 2 characters', 422);
        }
        if (strlen(preg_replace('/\D+/', '', $phone)) < 6) {
            errorResponse('phone must be a valid contact number', 422);
        }
        return [$fullName, $phone];
    }
}

if (!function_exists('ltr_recent_lead_id')) {
    function ltr_recent_lead_id(PDO $conn, string $table, int $companyId, int $unitId, string $phone, ?string $email): ?int {
        if (!in_array($table, ['re_unit_viewing_requests', 're_unit_lease_applications'], true)) {
            return null;
        }
        $where = [
            'company_id = ?',
            'unit_id = ?',
            'phone = ?',
            'created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        ];
        $params = [$companyId, $unitId, $phone];
        if ($email !== null) {
            $where[] = 'email = ?';
            $params[] = $email;
        }
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1");
        $stmt->execute($params);
        $id = (int)($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('ltr_badges')) {
    /**
     * @param array<string,mixed> $unit
     * @return list<string>
     */
    function ltr_badges(array $unit): array {
        $badges = [];
        $status = (string)($unit['marketing_status'] ?? '');
        if ($status !== '') $badges[] = re_unit_public_status_label($status);
        if (!empty($unit['featured'])) $badges[] = 'Featured';
        if (!empty($unit['balcony'])) $badges[] = 'Balcony';
        if (!empty($unit['has_floor_plan'])) $badges[] = 'Floor Plan';
        if (!empty($unit['virtual_tour_url'])) $badges[] = '3D Tour';
        return array_values(array_unique($badges));
    }
}

if (!function_exists('ltr_unit_summary')) {
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function ltr_unit_summary(array $row): array {
        $unit = [
            'unit_id' => (int)$row['id'],
            'listing_title' => $row['listing_title'] ?: ('Unit ' . ($row['unit_number'] ?? '')),
            'building_name' => $row['building_name'] ?? null,
            'location' => $row['building_address'] ?? null,
            'unit_type' => $row['unit_type'] ?? null,
            'bedrooms' => isset($row['bedrooms']) ? (int)$row['bedrooms'] : null,
            'bathrooms' => isset($row['bathrooms']) ? round((float)$row['bathrooms'], 1) : null,
            'unit_size_sqft' => isset($row['unit_size_sqft']) ? round((float)$row['unit_size_sqft'], 2) : null,
            'furnished_status' => $row['furniture_status'] ?? null,
            'annual_rent' => isset($row['annual_rent']) ? (float)$row['annual_rent'] : null,
            'cheques_count' => isset($row['cheques_count']) ? (int)$row['cheques_count'] : null,
            'marketing_status' => $row['marketing_status'] ?? null,
            'primary_photo_url' => ltr_media_url($row['primary_photo_path'] ?? null),
            'has_floor_plan' => !empty($row['has_floor_plan']),
            'has_virtual_tour' => !empty($row['virtual_tour_url']),
            'whatsapp_contact' => $row['whatsapp_contact'] ?? null,
            'call_contact' => $row['call_contact'] ?? null,
        ];
        $unit['badges'] = ltr_badges(array_merge($row, $unit));
        return $unit;
    }
}

if (!function_exists('ltr_detail_payload')) {
    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $media
     * @return array<string,mixed>
     */
    function ltr_detail_payload(array $row, array $media): array {
        $photos = [];
        $floorPlan = null;
        foreach ($media as $m) {
            $item = [
                'id' => (int)$m['id'],
                'url' => ltr_media_url($m['file_path'] ?? null),
                'title' => $m['title'] ?? null,
                'is_primary' => !empty($m['is_primary']),
            ];
            if (($m['media_type'] ?? '') === 'photo') {
                $photos[] = $item;
            } elseif ($floorPlan === null) {
                $floorPlan = $item;
            }
        }
        $summary = ltr_unit_summary($row);
        return $summary + [
            'unit_number' => $row['unit_number'] ?? null,
            'listing_description' => $row['listing_description'] ?? null,
            'amenities' => re_unit_public_parse_amenities($row['amenities_json'] ?? ''),
            'photos' => $photos,
            'floor_plan' => $floorPlan,
            'virtual_tour_url' => $row['virtual_tour_url'] ?? null,
            'map_url' => $row['map_url'] ?? null,
            'latitude' => isset($row['latitude']) ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) ? (float)$row['longitude'] : null,
            'pricing' => [
                'annual_rent' => isset($row['annual_rent']) ? (float)$row['annual_rent'] : null,
                'cheques_count' => isset($row['cheques_count']) ? (int)$row['cheques_count'] : null,
                'security_deposit' => isset($row['security_deposit']) ? (float)$row['security_deposit'] : null,
                'commission' => isset($row['commission']) ? (float)$row['commission'] : null,
            ],
            'contact' => [
                'whatsapp' => $row['whatsapp_contact'] ?? null,
                'call' => $row['call_contact'] ?? null,
            ],
            'ctas' => [
                'schedule_viewing' => true,
                'apply_for_lease' => true,
                'whatsapp' => !empty($row['whatsapp_contact']),
                'call' => !empty($row['call_contact']),
                'virtual_tour' => !empty($row['virtual_tour_url']),
                'floor_plan' => $floorPlan !== null,
                'map' => !empty($row['map_url']) || (!empty($row['latitude']) && !empty($row['longitude'])),
            ],
            'share' => $row['_share_meta'] ?? [
                'share_enabled' => false,
                'share_code' => null,
                'share_url' => null,
            ],
        ];
    }
}

if (!function_exists('ltr_unit_select_sql')) {
    function ltr_unit_select_sql(): string {
        return "
            SELECT u.id, u.company_id, u.unit_number, u.unit_type, u.furniture_status, u.annual_rent,
                   u.listing_title, u.listing_description, u.marketing_status, u.cheques_count,
                   u.security_deposit, u.commission, u.unit_size_sqft, u.bedrooms, u.bathrooms,
                   u.parking_count, u.balcony, u.amenities_json, u.floor_plan_file,
                   u.virtual_tour_url, u.map_url, u.latitude, u.longitude, u.whatsapp_contact,
                   u.call_contact, u.featured, u.sort_order,
                   b.name AS building_name, b.address AS building_address,
                   pm.file_path AS primary_photo_path,
                   CASE WHEN fp.id IS NOT NULL OR u.floor_plan_file IS NOT NULL THEN 1 ELSE 0 END AS has_floor_plan
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id AND b.is_active = 1
            LEFT JOIN re_unit_public_media pm ON pm.id = (
                SELECT pm2.id
                FROM re_unit_public_media pm2
                WHERE pm2.company_id = u.company_id
                  AND pm2.unit_id = u.id
                  AND pm2.media_type = 'photo'
                ORDER BY pm2.is_primary DESC, pm2.sort_order ASC, pm2.id ASC
                LIMIT 1
            )
            LEFT JOIN re_unit_public_media fp ON fp.id = (
                SELECT fp2.id
                FROM re_unit_public_media fp2
                WHERE fp2.company_id = u.company_id
                  AND fp2.unit_id = u.id
                  AND fp2.media_type = 'floor_plan'
                ORDER BY fp2.sort_order ASC, fp2.id ASC
                LIMIT 1
            )
        ";
    }
}

if (!function_exists('ltr_handle_list')) {
    function ltr_handle_list(PDO $conn): void {
        $where = [ltr_base_where_sql('u')];
        $params = [];
        $companyId = ltr_company_id_filter();
        if ($companyId !== null) {
            $where[] = 'u.company_id = ?';
            $params[] = $companyId;
        }

        $location = ltr_nullable_text($_GET['location'] ?? null, 120);
        if ($location !== null) {
            $where[] = 'b.address LIKE ?';
            $params[] = '%' . $location . '%';
        }
        $buildingId = filter_var($_GET['building_id'] ?? null, FILTER_VALIDATE_INT);
        if ($buildingId) {
            $where[] = 'u.building_id = ?';
            $params[] = $buildingId;
        }
        $unitType = ltr_nullable_text($_GET['unit_type'] ?? null, 50);
        if ($unitType !== null) {
            $where[] = 'u.unit_type = ?';
            $params[] = $unitType;
        }
        $bedrooms = filter_var($_GET['bedrooms'] ?? null, FILTER_VALIDATE_INT);
        if ($bedrooms !== false && $bedrooms !== null) {
            $where[] = 'u.bedrooms = ?';
            $params[] = $bedrooms;
        }
        $furnished = ltr_nullable_text($_GET['furnished_status'] ?? null, 50);
        if ($furnished !== null) {
            $where[] = 'u.furniture_status = ?';
            $params[] = $furnished;
        }
        $minPrice = filter_var($_GET['min_price'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($minPrice !== false && $minPrice !== null) {
            $where[] = 'u.annual_rent >= ?';
            $params[] = $minPrice;
        }
        $maxPrice = filter_var($_GET['max_price'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($maxPrice !== false && $maxPrice !== null) {
            $where[] = 'u.annual_rent <= ?';
            $params[] = $maxPrice;
        }
        if (isset($_GET['ready_to_move']) && in_array((string)$_GET['ready_to_move'], ['1', 'true', 'yes'], true)) {
            $where[] = "u.marketing_status = 'ready_to_move'";
        }
        if (isset($_GET['featured']) && in_array((string)$_GET['featured'], ['1', 'true', 'yes'], true)) {
            $where[] = 'u.featured = 1';
        }
        $search = ltr_nullable_text($_GET['search'] ?? null, 120);
        if ($search !== null) {
            $where[] = '(u.listing_title LIKE ? OR u.unit_number LIKE ? OR u.unit_type LIKE ? OR b.name LIKE ? OR b.address LIKE ?)';
            $sp = '%' . $search . '%';
            array_push($params, $sp, $sp, $sp, $sp, $sp);
        }

        $sort = (string)($_GET['sort'] ?? 'featured');
        $orderBy = match ($sort) {
            'price_asc' => 'u.annual_rent ASC, u.sort_order ASC, u.id DESC',
            'price_desc' => 'u.annual_rent DESC, u.sort_order ASC, u.id DESC',
            'size_desc' => 'u.unit_size_sqft DESC, u.sort_order ASC, u.id DESC',
            'newest' => 'u.id DESC',
            default => 'u.featured DESC, u.sort_order ASC, u.id DESC',
        };
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $sql = ltr_unit_select_sql() . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$orderBy} LIMIT {$limit}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $units = array_map('ltr_unit_summary', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        successResponse(['units' => $units, 'count' => count($units)]);
    }
}

if (!function_exists('ltr_fetch_unit')) {
    /**
     * @return array<string,mixed>|null
     */
    function ltr_fetch_unit(PDO $conn, int $unitId, ?int $companyId = null): ?array {
        $where = ['u.id = ?', ltr_base_where_sql('u')];
        $params = [$unitId];
        if ($companyId !== null) {
            $where[] = 'u.company_id = ?';
            $params[] = $companyId;
        }
        $sql = ltr_unit_select_sql() . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('ltr_handle_detail')) {
    function ltr_handle_detail(PDO $conn, int $unitId): void {
        $unit = ltr_fetch_unit($conn, $unitId, ltr_company_id_filter());
        if (!$unit) errorResponse('Rental unit not found', 404);
        $media = re_unit_public_media_rows($conn, (int)$unit['company_id'], $unitId);
        require_once __DIR__ . '/../../includes/property_share_helper.php';
        $unit['_share_meta'] = re_property_share_public_meta_for_unit(
            $conn,
            (int)$unit['company_id'],
            (int)$unit['id']
        );
        successResponse(ltr_detail_payload($unit, $media), null, 'unit');
    }
}

if (!function_exists('ltr_handle_filters')) {
    function ltr_handle_filters(PDO $conn): void {
        $baseParts = [ltr_base_where_sql('u')];
        $params = [];
        $companyId = ltr_company_id_filter();
        if ($companyId !== null) {
            $baseParts[] = 'u.company_id = ?';
            $params[] = $companyId;
        }
        $base = implode(' AND ', $baseParts);

        $buildings = $conn->prepare("
            SELECT DISTINCT b.id, b.name, b.address AS location
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id AND b.is_active = 1
            WHERE {$base}
            ORDER BY b.name
        ");
        $buildings->execute($params);

        $unitTypes = $conn->prepare("
            SELECT DISTINCT u.unit_type
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id AND b.is_active = 1
            WHERE {$base} AND u.unit_type IS NOT NULL AND u.unit_type <> ''
            ORDER BY u.unit_type
        ");
        $unitTypes->execute($params);

        $furnished = $conn->prepare("
            SELECT DISTINCT u.furniture_status
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id AND b.is_active = 1
            WHERE {$base} AND u.furniture_status IS NOT NULL AND u.furniture_status <> ''
            ORDER BY u.furniture_status
        ");
        $furnished->execute($params);

        $bedrooms = $conn->prepare("
            SELECT DISTINCT u.bedrooms
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id AND b.is_active = 1
            WHERE {$base} AND u.bedrooms IS NOT NULL
            ORDER BY u.bedrooms
        ");
        $bedrooms->execute($params);

        $price = $conn->prepare("
            SELECT MIN(u.annual_rent) AS min_rent, MAX(u.annual_rent) AS max_rent
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id AND b.is_active = 1
            WHERE {$base} AND u.annual_rent IS NOT NULL
        ");
        $price->execute($params);
        $priceRow = $price->fetch(PDO::FETCH_ASSOC) ?: [];

        $buildingRows = $buildings->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $locations = array_values(array_unique(array_filter(array_map(fn($b) => (string)($b['location'] ?? ''), $buildingRows))));

        successResponse([
            'locations' => $locations,
            'buildings' => array_map(fn($b) => [
                'id' => (int)$b['id'],
                'name' => $b['name'],
                'location' => $b['location'],
            ], $buildingRows),
            'unit_types' => array_values($unitTypes->fetchAll(PDO::FETCH_COLUMN) ?: []),
            'furnished_options' => array_values($furnished->fetchAll(PDO::FETCH_COLUMN) ?: []),
            'min_rent' => isset($priceRow['min_rent']) ? (float)$priceRow['min_rent'] : null,
            'max_rent' => isset($priceRow['max_rent']) ? (float)$priceRow['max_rent'] : null,
            'bedrooms_options' => array_map('intval', $bedrooms->fetchAll(PDO::FETCH_COLUMN) ?: []),
        ], null, 'filters');
    }
}

if (!function_exists('ltr_handle_viewing_request')) {
    function ltr_handle_viewing_request(PDO $conn, int $unitId): void {
        $unit = ltr_fetch_unit($conn, $unitId, ltr_company_id_filter());
        if (!$unit) errorResponse('Rental unit not found', 404);
        $companyId = (int)$unit['company_id'];
        ltr_rate_limit('viewing', $unitId);

        $body = ltr_read_body();
        ltr_require_fields($body, ['full_name', 'phone']);
        [$fullName, $phone] = ltr_validate_lead_identity($body);
        $email = ltr_nullable_text($body['email'] ?? null, 150);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            errorResponse('Invalid email address', 422);
        }
        $recentId = ltr_recent_lead_id($conn, 're_unit_viewing_requests', $companyId, $unitId, $phone, $email);
        if ($recentId !== null) {
            jsonResponse([
                'success' => true,
                'request' => [
                    'id' => $recentId,
                    'status' => 'new',
                    'message' => 'A recent viewing request already exists. Our leasing team will contact you soon.',
                ],
            ]);
        }
        $preferredDate = ltr_valid_date(isset($body['preferred_date']) ? (string)$body['preferred_date'] : null);
        if (!empty($body['preferred_date']) && $preferredDate === null) {
            errorResponse('preferred_date must be YYYY-MM-DD', 422);
        }
        $preferredTime = ltr_valid_time(isset($body['preferred_time']) ? (string)$body['preferred_time'] : null);
        if (!empty($body['preferred_time']) && $preferredTime === null) {
            errorResponse('preferred_time must be HH:MM', 422);
        }

        $stmt = $conn->prepare("
            INSERT INTO re_unit_viewing_requests
            (company_id, unit_id, full_name, phone, email, preferred_date, preferred_time, message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $unitId,
            $fullName,
            $phone,
            $email,
            $preferredDate,
            $preferredTime,
            ltr_nullable_text($body['message'] ?? null, 1000),
        ]);
        $requestId = (int)$conn->lastInsertId();
        try {
            if (function_exists('send_find_home_viewing_request_notification')) {
                $mailResult = send_find_home_viewing_request_notification($conn, $requestId, $companyId);
                if (empty($mailResult['success'])) {
                    error_log('Find Home viewing request email not sent: ' . json_encode($mailResult['errors'] ?? []));
                }
            }
        } catch (Throwable $e) {
            error_log('Find Home viewing request email failed: ' . $e->getMessage());
        }
        jsonResponse([
            'success' => true,
            'request' => [
                'id' => $requestId,
                'status' => 'new',
                'message' => 'Viewing request submitted.',
            ],
        ], 201);
    }
}

if (!function_exists('ltr_handle_lease_application')) {
    function ltr_handle_lease_application(PDO $conn, int $unitId): void {
        $unit = ltr_fetch_unit($conn, $unitId, ltr_company_id_filter());
        if (!$unit) errorResponse('Rental unit not found', 404);
        $companyId = (int)$unit['company_id'];
        ltr_rate_limit('application', $unitId, 3, 900);

        $body = ltr_read_body();
        ltr_require_fields($body, ['full_name', 'phone']);
        [$fullName, $phone] = ltr_validate_lead_identity($body);
        $email = ltr_nullable_text($body['email'] ?? null, 150);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            errorResponse('Invalid email address', 422);
        }
        $recentId = ltr_recent_lead_id($conn, 're_unit_lease_applications', $companyId, $unitId, $phone, $email);
        if ($recentId !== null) {
            jsonResponse([
                'success' => true,
                'application' => [
                    'id' => $recentId,
                    'status' => 'new',
                    'message' => 'A recent lease application already exists. Our leasing team will contact you soon.',
                ],
            ]);
        }
        $moveInDate = ltr_valid_date(isset($body['move_in_date']) ? (string)$body['move_in_date'] : null);
        if (!empty($body['move_in_date']) && $moveInDate === null) {
            errorResponse('move_in_date must be YYYY-MM-DD', 422);
        }
        $occupants = filter_var($body['occupants_count'] ?? null, FILTER_VALIDATE_INT);
        if ($occupants !== false && $occupants !== null && ($occupants < 1 || $occupants > 50)) {
            errorResponse('occupants_count must be between 1 and 50', 422);
        }

        $stmt = $conn->prepare("
            INSERT INTO re_unit_lease_applications
            (company_id, unit_id, full_name, phone, email, nationality, employer, move_in_date, occupants_count, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $unitId,
            $fullName,
            $phone,
            $email,
            ltr_nullable_text($body['nationality'] ?? null, 100),
            ltr_nullable_text($body['employer'] ?? null, 150),
            $moveInDate,
            $occupants !== false ? $occupants : null,
            ltr_nullable_text($body['notes'] ?? null, 1000),
        ]);
        $applicationId = (int)$conn->lastInsertId();
        try {
            if (function_exists('send_find_home_lease_application_notification')) {
                $mailResult = send_find_home_lease_application_notification($conn, $applicationId, $companyId);
                if (empty($mailResult['success'])) {
                    error_log('Find Home lease application email not sent: ' . json_encode($mailResult['errors'] ?? []));
                }
            }
        } catch (Throwable $e) {
            error_log('Find Home lease application email failed: ' . $e->getMessage());
        }
        jsonResponse([
            'success' => true,
            'application' => [
                'id' => $applicationId,
                'status' => 'new',
                'message' => 'Lease application submitted.',
            ],
        ], 201);
    }
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $route = ltr_route();

    if ($method === 'GET' && $route === '') {
        ltr_handle_list($conn);
    }
    if ($method === 'GET' && $route === 'filters') {
        ltr_handle_filters($conn);
    }
    if ($method === 'GET' && preg_match('#^(\d+)$#', $route, $m)) {
        ltr_handle_detail($conn, (int)$m[1]);
    }
    if ($method === 'POST' && preg_match('#^(\d+)/viewing-request$#', $route, $m)) {
        ltr_handle_viewing_request($conn, (int)$m[1]);
    }
    if ($method === 'POST' && preg_match('#^(\d+)/lease-application$#', $route, $m)) {
        ltr_handle_lease_application($conn, (int)$m[1]);
    }

    errorResponse('Not found', 404);
} catch (Throwable $e) {
    error_log('long_term_rentals API error: ' . $e->getMessage());
    errorResponse('Server error', 500);
}
