<?php
/**
 * Tenant Services Phase 1 — maintenance & cleaning (lease-scoped, X-Tenant-Lease-Id).
 */

declare(strict_types=1);

/**
 * @return array{lease_id:int,tenant_id:int,unit_id:int,company_id:int}|null
 */
function customer_api_tenant_services_lease_context(PDO $conn, array $ctx, int $leaseId): ?array {
    customer_api_tenant_require_lease_header_match($leaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $leaseId);
    $lease = customer_api_tenant_load_lease_detail($conn, $leaseId);
    if (!$lease) {
        return null;
    }
    return [
        'lease_id' => $leaseId,
        'tenant_id' => (int)($lease['tenant_id'] ?? 0),
        'unit_id' => (int)($lease['unit_id'] ?? 0),
        'company_id' => (int)($lease['company_id'] ?? 0),
    ];
}

function customer_api_tenant_maintenance_photo_url(string $relativePath): string {
    $rel = customer_api_public_path_url($relativePath);
    return customer_api_absolute_url($rel);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function customer_api_tenant_format_maintenance_row(PDO $conn, array $row, bool $includeDetail = false): array {
    $id = (int)($row['id'] ?? 0);
    $status = (string)($row['status'] ?? 'pending');
    $out = [
        'id' => $id,
        'request_date' => (string)($row['request_date'] ?? ''),
        'priority' => (string)($row['priority'] ?? 'medium'),
        'category' => (string)($row['category'] ?? 'general'),
        'description' => (string)($row['description'] ?? ''),
        'status' => $status,
        'completed_at' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
        'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
        'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
        'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
    ];

    if ($includeDetail) {
        $out['responded_at'] = $row['responded_at'] !== null ? (string)$row['responded_at'] : null;
        $out['timeline'] = customer_api_tenant_maintenance_timeline($row);
        $photos = [];
        $pStmt = $conn->prepare("
            SELECT id, photo_type, file_name, file_path, mime_type, created_at
            FROM re_maintenance_photos
            WHERE maintenance_request_id = ? AND company_id = ?
            ORDER BY created_at ASC, id ASC
        ");
        $pStmt->execute([$id, (int)($row['company_id'] ?? 0)]);
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $path = (string)($p['file_path'] ?? '');
            $photos[] = [
                'id' => (int)$p['id'],
                'photo_type' => (string)($p['photo_type'] ?? 'before'),
                'file_name' => (string)($p['file_name'] ?? ''),
                'url' => $path !== '' ? customer_api_tenant_maintenance_photo_url($path) : '',
                'mime_type' => $p['mime_type'] !== null ? (string)$p['mime_type'] : null,
                'created_at' => $p['created_at'] !== null ? (string)$p['created_at'] : null,
            ];
        }
        $out['photos'] = $photos;
        $out['photo_count'] = count($photos);
    } else {
        $cntStmt = $conn->prepare('SELECT COUNT(*) FROM re_maintenance_photos WHERE maintenance_request_id = ?');
        $cntStmt->execute([$id]);
        $out['photo_count'] = (int)$cntStmt->fetchColumn();
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_maintenance_timeline(array $row): array {
    $status = (string)($row['status'] ?? 'pending');
    $created = $row['created_at'] ?? $row['request_date'] ?? null;
    $responded = $row['responded_at'] ?? null;
    $completed = $row['completed_at'] ?? null;
    $updated = $row['updated_at'] ?? null;

    $steps = [
        [
            'key' => 'submitted',
            'label' => 'Submitted',
            'at' => $created !== null ? (string)$created : null,
            'completed' => true,
            'current' => $status === 'pending',
        ],
        [
            'key' => 'in_progress',
            'label' => 'In progress',
            'at' => $responded !== null ? (string)$responded : null,
            'completed' => in_array($status, ['in_progress', 'completed', 'cancelled'], true),
            'current' => $status === 'in_progress',
        ],
        [
            'key' => 'completed',
            'label' => $status === 'cancelled' ? 'Cancelled' : 'Completed',
            'at' => $status === 'cancelled'
                ? ($updated !== null ? (string)$updated : null)
                : ($completed !== null ? (string)$completed : null),
            'completed' => in_array($status, ['completed', 'cancelled'], true),
            'current' => in_array($status, ['completed', 'cancelled'], true),
        ],
    ];

    return $steps;
}

function customer_api_tenant_maintenance_tables_exist(PDO $conn): bool {
    try {
        $conn->query('SELECT 1 FROM re_maintenance_requests LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function customer_api_tenant_handle_maintenance_list(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_maintenance_tables_exist($conn)) {
        customer_api_send_ok(['requests' => [], 'configured' => false]);
    }

    $stmt = $conn->prepare("
        SELECT id, company_id, request_date, priority, category, description, status,
               completed_at, notes, created_at, updated_at
        FROM re_maintenance_requests
        WHERE lease_id = ?
        ORDER BY request_date DESC, id DESC
    ");
    $stmt->execute([$leaseId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = customer_api_tenant_format_maintenance_row($conn, $row, false);
    }
    customer_api_send_ok(['requests' => $items, 'configured' => true]);
}

function customer_api_tenant_handle_maintenance_detail(PDO $conn, int $leaseId, int $requestId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM re_maintenance_requests
        WHERE id = ? AND lease_id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId, $leaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        customer_api_send_error('not_found', 'Maintenance request not found', 404);
    }

    customer_api_send_ok([
        'request' => customer_api_tenant_format_maintenance_row($conn, $row, true),
    ]);
}

function customer_api_tenant_handle_maintenance_create(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_maintenance_tables_exist($conn)) {
        customer_api_send_error('not_configured', 'Maintenance requests are not available', 503);
    }

    $body = customer_api_read_json_body();
    $description = trim((string)($body['description'] ?? ''));
    $category = trim((string)($body['category'] ?? 'general'));
    $priority = (string)($body['priority'] ?? 'medium');
    $allowedPriority = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $allowedPriority, true)) {
        $priority = 'medium';
    }
    $allowedCategories = ['general', 'plumbing', 'electrical', 'ac', 'pest_control', 'cleaning', 'other'];
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'general';
    }

    if ($description === '') {
        customer_api_send_error('validation_error', 'description is required', 400);
    }

    $ins = $conn->prepare("
        INSERT INTO re_maintenance_requests
            (company_id, unit_id, tenant_id, lease_id, request_date, priority, category, description, status, created_by, source)
        VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, 'pending', NULL, 'tenant_mobile')
    ");
    try {
        $ins->execute([
            $leaseCtx['company_id'],
            $leaseCtx['unit_id'],
            $leaseCtx['tenant_id'],
            $leaseId,
            $priority,
            $category,
            $description,
        ]);
    } catch (Throwable $e) {
        $ins = $conn->prepare("
            INSERT INTO re_maintenance_requests
                (company_id, unit_id, tenant_id, lease_id, request_date, priority, category, description, status, created_by)
            VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, 'pending', NULL)
        ");
        $ins->execute([
            $leaseCtx['company_id'],
            $leaseCtx['unit_id'],
            $leaseCtx['tenant_id'],
            $leaseId,
            $priority,
            $category,
            $description,
        ]);
    }

    $requestId = (int)$conn->lastInsertId();

    if ($requestId > 0 && file_exists(dirname(__DIR__, 3) . '/modules/realestate/includes/re_email_helper.php')) {
        require_once dirname(__DIR__, 3) . '/modules/realestate/includes/re_email_helper.php';
        if (function_exists('send_maintenance_request_notification')) {
            send_maintenance_request_notification($conn, $requestId, $leaseCtx['company_id']);
        }
    }

    $stmt = $conn->prepare('SELECT * FROM re_maintenance_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    customer_api_send_ok([
        'request' => customer_api_tenant_format_maintenance_row($conn, $row, true),
    ], 201);
}

/**
 * Ensure upload path exists and is writable by the web server (e.g. XAMPP daemon).
 */
function customer_api_ensure_writable_upload_dir(string $absoluteDir): void {
    $parent = dirname($absoluteDir);
    if (!is_dir($parent)) {
        if (!@mkdir($parent, 0777, true) && !is_dir($parent)) {
            customer_api_send_error(
                'upload_failed',
                'Could not create upload directory. Ask your administrator to fix permissions on uploads/realestate/maintenance.',
                500
            );
        }
        @chmod($parent, 0777);
    }
    if (!is_dir($absoluteDir)) {
        if (!@mkdir($absoluteDir, 0777, true) && !is_dir($absoluteDir)) {
            customer_api_send_error(
                'upload_failed',
                'Could not create upload directory for this request.',
                500
            );
        }
    }
    @chmod($absoluteDir, 0777);
    if (!is_writable($absoluteDir)) {
        @chmod($parent, 0777);
        @chmod($absoluteDir, 0777);
    }
    if (!is_dir($absoluteDir) || !is_writable($absoluteDir)) {
        customer_api_send_error(
            'upload_failed',
            'Upload directory is not writable. On XAMPP, run: chmod -R 777 uploads/realestate/maintenance',
            500
        );
    }
}

/**
 * Normalize multipart file field (photos or photos[]) into a flat list of file slots.
 *
 * @return list<array{name:string,tmp_name:string,error:int,size:int}>
 */
function customer_api_collect_uploaded_files(string $field): array {
    if (empty($_FILES[$field])) {
        return [];
    }
    $bucket = $_FILES[$field];
    if (!is_array($bucket['name'] ?? null)) {
        return [[
            'name' => (string)($bucket['name'] ?? ''),
            'tmp_name' => (string)($bucket['tmp_name'] ?? ''),
            'error' => (int)($bucket['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($bucket['size'] ?? 0),
        ]];
    }
    $out = [];
    $names = (array)$bucket['name'];
    $tmps = (array)($bucket['tmp_name'] ?? []);
    $errors = (array)($bucket['error'] ?? []);
    $sizes = (array)($bucket['size'] ?? []);
    foreach ($names as $idx => $name) {
        $out[] = [
            'name' => (string)$name,
            'tmp_name' => (string)($tmps[$idx] ?? ''),
            'error' => (int)($errors[$idx] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($sizes[$idx] ?? 0),
        ];
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_maintenance_store_uploads(
    PDO $conn,
    int $requestId,
    int $companyId,
    int $maxFiles = 5
): array {
    $fileSlots = customer_api_collect_uploaded_files('photos');
    if ($fileSlots === []) {
        return [];
    }

    $uploadBaseDir = dirname(__DIR__, 3) . '/uploads/realestate/maintenance';
    $uploadDir = $uploadBaseDir . '/' . $requestId;
    customer_api_ensure_writable_upload_dir($uploadBaseDir);
    customer_api_ensure_writable_upload_dir($uploadDir);

    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;
    $uploaded = [];

    $existing = $conn->prepare('SELECT COUNT(*) FROM re_maintenance_photos WHERE maintenance_request_id = ?');
    $existing->execute([$requestId]);
    $existingCount = (int)$existing->fetchColumn();
    $slots = max(0, $maxFiles - $existingCount);

    foreach ($fileSlots as $slot) {
        if ($slots <= 0) {
            break;
        }
        $originalName = $slot['name'];
        if ($slot['error'] !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = $slot['tmp_name'];
        $size = $slot['size'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes, true) || $size <= 0 || $size > $maxSize) {
            continue;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $tmp) : null;
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!$mimeType || !in_array($mimeType, $allowedMime, true)) {
            continue;
        }

        $timestamp = time();
        $random = bin2hex(random_bytes(4));
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo((string)$originalName, PATHINFO_FILENAME));
        $newName = $safeName . '_' . $timestamp . '_' . $random . '.' . $ext;
        $filePath = $uploadDir . '/' . $newName;
        $relativePath = 'uploads/realestate/maintenance/' . $requestId . '/' . $newName;

        if (!move_uploaded_file($tmp, $filePath)) {
            continue;
        }

        $stmt = $conn->prepare("
            INSERT INTO re_maintenance_photos
                (company_id, maintenance_request_id, photo_type, file_name, file_path, file_size, mime_type, description, uploaded_by)
            VALUES (?, ?, 'before', ?, ?, ?, ?, NULL, NULL)
        ");
        $stmt->execute([
            $companyId,
            $requestId,
            $originalName,
            $relativePath,
            $size,
            $mimeType,
        ]);
        $photoId = (int)$conn->lastInsertId();
        $uploaded[] = [
            'id' => $photoId,
            'photo_type' => 'before',
            'file_name' => (string)$originalName,
            'url' => customer_api_tenant_maintenance_photo_url($relativePath),
            'mime_type' => $mimeType,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $slots--;
    }

    return $uploaded;
}

function customer_api_tenant_handle_maintenance_photos(PDO $conn, int $leaseId, int $requestId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $stmt = $conn->prepare('SELECT id, company_id FROM re_maintenance_requests WHERE id = ? AND lease_id = ? LIMIT 1');
    $stmt->execute([$requestId, $leaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        customer_api_send_error('not_found', 'Maintenance request not found', 404);
    }

    $photos = customer_api_tenant_maintenance_store_uploads(
        $conn,
        $requestId,
        (int)$row['company_id']
    );

    customer_api_send_ok(['photos' => $photos, 'uploaded_count' => count($photos)]);
}

function customer_api_tenant_cleaning_tables_exist(PDO $conn): bool {
    try {
        $conn->query('SELECT 1 FROM tenant_cleaning_requests LIMIT 1');
        $conn->query('SELECT 1 FROM re_cleaning_rates LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function customer_api_tenant_format_cleaning_row(array $row, bool $includeDetail = false): array {
    $status = (string)($row['status'] ?? 'pending');
    $out = [
        'id' => (int)($row['id'] ?? 0),
        'service_date' => $row['service_date'] !== null ? (string)$row['service_date'] : null,
        'service_time' => $row['service_time'] !== null ? (string)$row['service_time'] : null,
        'num_cleaners' => (int)($row['num_cleaners'] ?? 1),
        'num_hours' => (float)($row['num_hours'] ?? 0),
        'has_materials' => !empty($row['has_materials']),
        'rate_per_hour_snapshot' => $row['rate_per_hour_snapshot'] !== null
            ? number_format((float)$row['rate_per_hour_snapshot'], 2, '.', '')
            : null,
        'materials_fee_snapshot' => $row['materials_fee_snapshot'] !== null
            ? number_format((float)$row['materials_fee_snapshot'], 2, '.', '')
            : null,
        'total_amount_aed' => $row['total_amount_aed'] !== null
            ? number_format((float)$row['total_amount_aed'], 2, '.', '')
            : null,
        'status' => $status,
        'admin_notes' => $row['admin_notes'] !== null ? (string)$row['admin_notes'] : null,
        'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
        'approved_at' => $row['approved_at'] !== null ? (string)$row['approved_at'] : null,
    ];

    if ($includeDetail) {
        $out['timeline'] = customer_api_tenant_cleaning_timeline($row);
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_cleaning_timeline(array $row): array {
    $status = (string)($row['status'] ?? 'pending');
    $created = $row['created_at'] ?? null;
    $approved = $row['approved_at'] ?? null;

    return [
        [
            'key' => 'submitted',
            'label' => 'Submitted',
            'at' => $created !== null ? (string)$created : null,
            'completed' => true,
            'current' => $status === 'pending',
        ],
        [
            'key' => 'review',
            'label' => $status === 'rejected' ? 'Rejected' : 'Approved',
            'at' => $approved !== null ? (string)$approved : null,
            'completed' => in_array($status, ['approved', 'rejected'], true),
            'current' => in_array($status, ['approved', 'rejected'], true),
        ],
    ];
}

function customer_api_tenant_handle_cleaning_rates(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_cleaning_tables_exist($conn)) {
        customer_api_send_ok([
            'configured' => false,
            'rate_per_hour_aed' => null,
            'materials_fee_aed' => null,
        ]);
    }

    $stmt = $conn->prepare('SELECT rate_per_hour_aed, materials_fee_aed FROM re_cleaning_rates WHERE company_id = ? LIMIT 1');
    $stmt->execute([$leaseCtx['company_id']]);
    $rates = $stmt->fetch(PDO::FETCH_ASSOC);

    customer_api_send_ok([
        'configured' => true,
        'rate_per_hour_aed' => $rates
            ? number_format((float)$rates['rate_per_hour_aed'], 2, '.', '')
            : '0.00',
        'materials_fee_aed' => $rates
            ? number_format((float)$rates['materials_fee_aed'], 2, '.', '')
            : '0.00',
    ]);
}

function customer_api_tenant_handle_cleaning_list(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_cleaning_tables_exist($conn)) {
        customer_api_send_ok(['requests' => [], 'configured' => false]);
    }

    $stmt = $conn->prepare("
        SELECT id, service_date, service_time, num_cleaners, num_hours, has_materials,
               rate_per_hour_snapshot, materials_fee_snapshot, total_amount_aed, status,
               admin_notes, created_at, approved_at
        FROM tenant_cleaning_requests
        WHERE lease_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$leaseId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = customer_api_tenant_format_cleaning_row($row, false);
    }
    customer_api_send_ok(['requests' => $items, 'configured' => true]);
}

function customer_api_tenant_handle_cleaning_detail(PDO $conn, int $leaseId, int $requestId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM tenant_cleaning_requests
        WHERE id = ? AND lease_id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId, $leaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        customer_api_send_error('not_found', 'Cleaning request not found', 404);
    }

    customer_api_send_ok([
        'request' => customer_api_tenant_format_cleaning_row($row, true),
    ]);
}

function customer_api_tenant_handle_cleaning_create(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_cleaning_tables_exist($conn)) {
        customer_api_send_error('not_configured', 'Cleaning requests are not available', 503);
    }

    $body = customer_api_read_json_body();
    $numCleaners = max(1, min(20, (int)($body['num_cleaners'] ?? 1)));
    $numHours = max(0.5, min(24 * 7, (float)($body['num_hours'] ?? 1)));
    $hasMaterials = !empty($body['has_materials']);
    $serviceDateRaw = trim((string)($body['service_date'] ?? ''));
    $serviceTime = trim((string)($body['service_time'] ?? ''));
    $serviceDateSql = null;
    if ($serviceDateRaw !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $serviceDateRaw);
        if ($d instanceof DateTime) {
            $serviceDateSql = $d->format('Y-m-d');
        } else {
            customer_api_send_error('validation_error', 'service_date must be YYYY-MM-DD', 400);
        }
    }

    $rateStmt = $conn->prepare('SELECT rate_per_hour_aed, materials_fee_aed FROM re_cleaning_rates WHERE company_id = ? LIMIT 1');
    $rateStmt->execute([$leaseCtx['company_id']]);
    $rates = $rateStmt->fetch(PDO::FETCH_ASSOC);
    $ratePerHour = $rates ? (float)$rates['rate_per_hour_aed'] : 0.0;
    $materialsFeePerHour = $rates ? (float)$rates['materials_fee_aed'] : 0.0;
    $totalHours = $numCleaners * $numHours;
    $total = ($ratePerHour * $totalHours)
        + ($hasMaterials ? $materialsFeePerHour * $totalHours : 0);
    $total = round($total, 2);

    $ins = $conn->prepare("
        INSERT INTO tenant_cleaning_requests
            (tenant_id, lease_id, company_id, service_date, service_time, num_cleaners, num_hours,
             has_materials, rate_per_hour_snapshot, materials_fee_snapshot, total_amount_aed, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $ins->execute([
        $leaseCtx['tenant_id'],
        $leaseId,
        $leaseCtx['company_id'],
        $serviceDateSql,
        $serviceTime !== '' ? $serviceTime : null,
        $numCleaners,
        $numHours,
        $hasMaterials ? 1 : 0,
        $ratePerHour ?: null,
        $hasMaterials ? $materialsFeePerHour : null,
        $total ?: null,
    ]);

    $requestId = (int)$conn->lastInsertId();

    if ($requestId > 0 && file_exists(dirname(__DIR__, 3) . '/modules/realestate/includes/re_email_helper.php')) {
        require_once dirname(__DIR__, 3) . '/modules/realestate/includes/re_email_helper.php';
        if (function_exists('send_cleaning_request_notification')) {
            send_cleaning_request_notification($conn, $requestId, $leaseCtx['company_id']);
        }
    }

    $stmt = $conn->prepare('SELECT * FROM tenant_cleaning_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    customer_api_send_ok([
        'request' => customer_api_tenant_format_cleaning_row($row, true),
    ], 201);
}

function customer_api_tenant_normalize_unit_type_key(string $unitTypeRaw): string {
    $key = strtolower(trim($unitTypeRaw));
    $key = preg_replace('/\s+/', '_', $key) ?? $key;
    if (in_array($key, ['studio', '1_bhk', '2_bhk', '3_bhk'], true)) {
        return $key;
    }
    if (preg_match('/studio/i', $unitTypeRaw)) {
        return 'studio';
    }
    if (preg_match('/1\s*bhk|1br|1bed/i', $unitTypeRaw)) {
        return '1_bhk';
    }
    if (preg_match('/2\s*bhk|2br|2bed/i', $unitTypeRaw)) {
        return '2_bhk';
    }
    if (preg_match('/3\s*bhk|3br|3bed/i', $unitTypeRaw)) {
        return '3_bhk';
    }
    return 'studio';
}

function customer_api_tenant_unit_type_label(string $unitTypeKey): string {
    return ucwords(str_replace('_', ' ', $unitTypeKey));
}

function customer_api_tenant_pest_control_tables_exist(PDO $conn): bool {
    try {
        $conn->query('SELECT 1 FROM tenant_pest_control_requests LIMIT 1');
        $conn->query('SELECT 1 FROM re_pest_control_rates LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function customer_api_tenant_format_pest_control_row(array $row, bool $includeDetail = false): array {
    $status = (string)($row['status'] ?? 'pending');
    $unitType = (string)($row['unit_type'] ?? '');
    $out = [
        'id' => (int)($row['id'] ?? 0),
        'service_date' => $row['service_date'] !== null ? (string)$row['service_date'] : null,
        'service_time' => $row['service_time'] !== null ? (string)$row['service_time'] : null,
        'unit_type' => $unitType,
        'unit_type_label' => customer_api_tenant_unit_type_label($unitType),
        'price_snapshot' => $row['price_snapshot'] !== null
            ? number_format((float)$row['price_snapshot'], 2, '.', '')
            : null,
        'total_amount_aed' => $row['total_amount_aed'] !== null
            ? number_format((float)$row['total_amount_aed'], 2, '.', '')
            : null,
        'status' => $status,
        'admin_notes' => $row['admin_notes'] !== null ? (string)$row['admin_notes'] : null,
        'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
        'approved_at' => $row['approved_at'] !== null ? (string)$row['approved_at'] : null,
    ];

    if ($includeDetail) {
        $out['timeline'] = customer_api_tenant_pest_control_timeline($row);
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_pest_control_timeline(array $row): array {
    $status = (string)($row['status'] ?? 'pending');
    $created = $row['created_at'] ?? null;
    $approved = $row['approved_at'] ?? null;

    return [
        [
            'key' => 'submitted',
            'label' => 'Submitted',
            'at' => $created !== null ? (string)$created : null,
            'completed' => true,
            'current' => $status === 'pending',
        ],
        [
            'key' => 'review',
            'label' => $status === 'rejected' ? 'Rejected' : 'Approved',
            'at' => $approved !== null ? (string)$approved : null,
            'completed' => in_array($status, ['approved', 'rejected'], true),
            'current' => in_array($status, ['approved', 'rejected'], true),
        ],
    ];
}

function customer_api_tenant_pest_control_quote(PDO $conn, int $leaseId, int $companyId): array {
    $lease = customer_api_tenant_load_lease_detail($conn, $leaseId);
    if (!$lease) {
        return ['configured' => false];
    }
    $unitTypeRaw = (string)($lease['unit_type'] ?? '');
    $unitTypeKey = customer_api_tenant_normalize_unit_type_key($unitTypeRaw);
    $price = null;
    $stmt = $conn->prepare('SELECT price_aed FROM re_pest_control_rates WHERE company_id = ? AND unit_type = ? LIMIT 1');
    $stmt->execute([$companyId, $unitTypeKey]);
    $rateRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rateRow) {
        $price = number_format((float)$rateRow['price_aed'], 2, '.', '');
    }

    return [
        'configured' => true,
        'unit_type' => $unitTypeKey,
        'unit_type_label' => customer_api_tenant_unit_type_label($unitTypeKey),
        'unit_type_display' => $unitTypeRaw !== '' ? $unitTypeRaw : customer_api_tenant_unit_type_label($unitTypeKey),
        'price_aed' => $price,
    ];
}

function customer_api_tenant_handle_pest_control_quote(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_pest_control_tables_exist($conn)) {
        customer_api_send_ok(['configured' => false]);
    }
    customer_api_send_ok(customer_api_tenant_pest_control_quote($conn, $leaseId, $leaseCtx['company_id']));
}

function customer_api_tenant_handle_pest_control_list(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_pest_control_tables_exist($conn)) {
        customer_api_send_ok(['requests' => [], 'configured' => false]);
    }

    $stmt = $conn->prepare("
        SELECT id, service_date, service_time, unit_type, price_snapshot, total_amount_aed,
               status, admin_notes, created_at, approved_at
        FROM tenant_pest_control_requests
        WHERE lease_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$leaseId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = customer_api_tenant_format_pest_control_row($row, false);
    }
    customer_api_send_ok(['requests' => $items, 'configured' => true]);
}

function customer_api_tenant_handle_pest_control_detail(PDO $conn, int $leaseId, int $requestId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $stmt = $conn->prepare('SELECT * FROM tenant_pest_control_requests WHERE id = ? AND lease_id = ? LIMIT 1');
    $stmt->execute([$requestId, $leaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        customer_api_send_error('not_found', 'Pest control request not found', 404);
    }

    customer_api_send_ok([
        'request' => customer_api_tenant_format_pest_control_row($row, true),
    ]);
}

function customer_api_tenant_handle_pest_control_create(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_pest_control_tables_exist($conn)) {
        customer_api_send_error('not_configured', 'Pest control requests are not available', 503);
    }

    $body = customer_api_read_json_body();
    $serviceDateRaw = trim((string)($body['service_date'] ?? ''));
    $serviceTime = trim((string)($body['service_time'] ?? ''));
    $serviceDateSql = null;
    if ($serviceDateRaw !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $serviceDateRaw);
        if ($d instanceof DateTime) {
            $serviceDateSql = $d->format('Y-m-d');
        } else {
            customer_api_send_error('validation_error', 'service_date must be YYYY-MM-DD', 400);
        }
    }

    $quote = customer_api_tenant_pest_control_quote($conn, $leaseId, $leaseCtx['company_id']);
    $unitTypeKey = (string)($quote['unit_type'] ?? 'studio');
    $priceSnapshot = isset($quote['price_aed']) ? (float)$quote['price_aed'] : null;
    $total = $priceSnapshot !== null ? round($priceSnapshot, 2) : null;

    $ins = $conn->prepare("
        INSERT INTO tenant_pest_control_requests
            (tenant_id, lease_id, company_id, service_date, service_time, unit_type,
             price_snapshot, total_amount_aed, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $ins->execute([
        $leaseCtx['tenant_id'],
        $leaseId,
        $leaseCtx['company_id'],
        $serviceDateSql,
        $serviceTime !== '' ? $serviceTime : null,
        $unitTypeKey,
        $priceSnapshot,
        $total,
    ]);

    $requestId = (int)$conn->lastInsertId();

    if ($requestId > 0 && file_exists(dirname(__DIR__, 3) . '/modules/realestate/includes/re_email_helper.php')) {
        require_once dirname(__DIR__, 3) . '/modules/realestate/includes/re_email_helper.php';
        if (function_exists('send_pest_control_request_notification')) {
            send_pest_control_request_notification($conn, $requestId, $leaseCtx['company_id']);
        }
    }

    $stmt = $conn->prepare('SELECT * FROM tenant_pest_control_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    customer_api_send_ok([
        'request' => customer_api_tenant_format_pest_control_row($row, true),
    ], 201);
}
