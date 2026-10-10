<?php
/**
 * ARS move-in / move-out inspection — a checklist, remarks and photos per booking.
 *
 * One inspection per booking per direction ('in' / 'out'). It records what staff
 * checked; it never touches money. Deposit deductions stay on the Deposit tab.
 */

declare(strict_types=1);

function ars_move_inspection_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_move_checklist_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            move_type ENUM('in','out') NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            item_description VARCHAR(500) NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ars_mct_company (company_id, move_type, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_move_inspections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            booking_id INT NOT NULL,
            move_type ENUM('in','out') NOT NULL,
            status ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
            notes TEXT NULL,
            completed_at DATETIME NULL,
            completed_by INT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_ars_mi_booking_type (booking_id, move_type),
            INDEX idx_ars_mi_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_move_inspection_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            inspection_id INT UNSIGNED NOT NULL,
            template_id INT UNSIGNED NULL,
            item_name VARCHAR(255) NOT NULL,
            item_description VARCHAR(500) NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            is_completed TINYINT(1) NOT NULL DEFAULT 0,
            remarks VARCHAR(1000) NULL,
            completed_at DATETIME NULL,
            completed_by INT NULL,
            INDEX idx_ars_mii_inspection (inspection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_move_inspection_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            inspection_id INT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            relative_path VARCHAR(500) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            caption VARCHAR(255) NULL,
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ars_mip_inspection (inspection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** Dubai wall-clock time; the live database clock runs on UTC. */
function ars_move_inspection_now(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d H:i:s');
}

function ars_move_inspection_type(?string $value): string {
    return $value === 'out' ? 'out' : 'in';
}

function ars_move_inspection_type_label(string $type): string {
    return $type === 'out' ? 'Move-out' : 'Move-in';
}

/** Segment switch between the move list and the checklist settings; $active is 'list' or 'checklist'. */
function ars_move_inspection_nav_html(string $active): string {
    $class = static fn(bool $on): string => 'no-underline inline-flex items-center gap-1 rounded-ars-md px-3 py-1.5 text-ars-sm font-semibold '
        . ($on ? 'bg-ars-ink text-white' : 'text-ars-muted hover:text-ars-text');
    return '<div class="inline-flex flex-wrap gap-1 rounded-ars-lg border border-ars-border bg-ars-surface p-1 align-middle">'
        . '<a class="' . $class($active === 'list') . '" href="move_report.php">' . ars_ui_icon('list', ['class' => 'h-4 w-4']) . ' Move-in / Move-out list</a>'
        . '<a class="' . $class($active === 'checklist') . '" href="move_checklist.php">' . ars_ui_icon('list-checks', ['class' => 'h-4 w-4']) . ' Checklist</a>'
        . '</div>';
}

/** Booking statuses an inspection of this direction can be opened for. */
function ars_move_inspection_allowed_statuses(string $type): array {
    return $type === 'out'
        ? ['checked_in', 'checked_out', 'completed']
        : ['pending', 'confirmed', 'checked_in', 'checked_out', 'completed'];
}

/**
 * Starting checklist for a company that has not set its own.
 *
 * @return list<array{0:string,1:string,2:int}> name, description, required
 */
function ars_move_inspection_default_items(string $type): array {
    if ($type === 'out') {
        return [
            ['Keys / access cards returned', 'All keys, access cards and parking cards are back', 1],
            ['Inventory checked', 'Furniture, linen, kitchen items and electronics are all there', 1],
            ['Damages checked', 'Walls, furniture and fittings checked; write any damage in the remarks', 1],
            ['AC and appliances working', 'AC, fridge, washing machine, TV and cooker switch on and work', 1],
            ['Guest belongings cleared', 'Nothing left behind in rooms, cupboards or fridge', 1],
            ['Unit photos taken', 'Photos of each room uploaded below', 0],
        ];
    }
    return [
        ['Guest ID / passport collected', 'ID copy is on the guest profile', 1],
        ['Stay payment received', 'Amount due before arrival is paid', 1],
        ['Security deposit received', 'Deposit collected, where the booking has one', 0],
        ['Keys / access cards handed over', 'Guest has the keys, access cards and parking card', 1],
        ['Unit clean and ready', 'Unit is clean, beds made, towels and supplies in place', 1],
        ['AC and appliances working', 'AC, fridge, washing machine, TV and cooker switch on and work', 1],
        ['Inventory checked', 'Furniture, linen, kitchen items and electronics are all there', 1],
        ['House rules explained', 'Guest was told the house rules and check-out time', 0],
    ];
}

/**
 * Active checklist template for one direction; seeds the defaults the first time.
 *
 * @return list<array<string,mixed>>
 */
function ars_move_inspection_templates(PDO $conn, int $companyId, string $type, bool $activeOnly = true): array {
    ars_move_inspection_ensure_schema($conn);
    $count = $conn->prepare("SELECT COUNT(*) FROM ars_move_checklist_templates WHERE company_id = ? AND move_type = ?");
    $count->execute([$companyId, $type]);
    if ((int)$count->fetchColumn() === 0) {
        $ins = $conn->prepare("
            INSERT INTO ars_move_checklist_templates
                (company_id, move_type, item_name, item_description, is_required, display_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach (ars_move_inspection_default_items($type) as $i => $item) {
            $ins->execute([$companyId, $type, $item[0], $item[1], $item[2], $i + 1]);
        }
    }
    $stmt = $conn->prepare("
        SELECT * FROM ars_move_checklist_templates
        WHERE company_id = ? AND move_type = ?" . ($activeOnly ? ' AND is_active = 1' : '') . "
        ORDER BY display_order, id
    ");
    $stmt->execute([$companyId, $type]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ars_move_inspection_find(PDO $conn, int $companyId, int $bookingId, string $type): ?array {
    ars_move_inspection_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT i.*, u.fullname AS completed_by_name
        FROM ars_move_inspections i
        LEFT JOIN `user` u ON u.id = i.completed_by
        WHERE i.company_id = ? AND i.booking_id = ? AND i.move_type = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $bookingId, $type]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * The booking's inspection for this direction, created from the template on first open.
 * The checklist is copied, so later template edits never change an inspection already started.
 */
function ars_move_inspection_get_or_create(PDO $conn, int $companyId, int $bookingId, string $type, ?int $userId): array {
    $existing = ars_move_inspection_find($conn, $companyId, $bookingId, $type);
    if ($existing) {
        return $existing;
    }
    $templates = ars_move_inspection_templates($conn, $companyId, $type);

    $conn->beginTransaction();
    try {
        // The unique key makes two people opening the page together share one inspection.
        $ins = $conn->prepare("
            INSERT IGNORE INTO ars_move_inspections (company_id, booking_id, move_type, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $ins->execute([$companyId, $bookingId, $type, $userId]);
        if ($ins->rowCount() > 0) {
            $inspectionId = (int)$conn->lastInsertId();
            $item = $conn->prepare("
                INSERT INTO ars_move_inspection_items
                    (company_id, inspection_id, template_id, item_name, item_description, is_required, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($templates as $i => $t) {
                $item->execute([
                    $companyId, $inspectionId, $t['id'], $t['item_name'], $t['item_description'],
                    (int)$t['is_required'], (int)$t['display_order'] ?: ($i + 1),
                ]);
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
    return ars_move_inspection_find($conn, $companyId, $bookingId, $type) ?? [];
}

/** @return list<array<string,mixed>> */
function ars_move_inspection_items(PDO $conn, int $inspectionId): array {
    $stmt = $conn->prepare("
        SELECT it.*, u.fullname AS completed_by_name
        FROM ars_move_inspection_items it
        LEFT JOIN `user` u ON u.id = it.completed_by
        WHERE it.inspection_id = ?
        ORDER BY it.display_order, it.id
    ");
    $stmt->execute([$inspectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Tick / untick one item and save its remarks.
 *
 * @return array{success:bool,error:?string}
 */
function ars_move_inspection_save_item(PDO $conn, array $inspection, int $itemId, bool $completed, string $remarks, ?int $userId): array {
    if (($inspection['status'] ?? '') === 'completed') {
        return ['success' => false, 'error' => 'This inspection is already completed.'];
    }
    $remarks = mb_substr(trim($remarks), 0, 1000);
    $stmt = $conn->prepare("
        UPDATE ars_move_inspection_items
        SET remarks = ?,
            completed_at = CASE WHEN ? = 1 AND is_completed = 0 THEN ? WHEN ? = 0 THEN NULL ELSE completed_at END,
            completed_by = CASE WHEN ? = 1 AND is_completed = 0 THEN ? WHEN ? = 0 THEN NULL ELSE completed_by END,
            is_completed = ?
        WHERE id = ? AND inspection_id = ?
    ");
    $c = $completed ? 1 : 0;
    $stmt->execute([$remarks !== '' ? $remarks : null, $c, ars_move_inspection_now(), $c, $c, $userId, $c, $c, $itemId, (int)$inspection['id']]);
    $check = $conn->prepare("SELECT id FROM ars_move_inspection_items WHERE id = ? AND inspection_id = ?");
    $check->execute([$itemId, (int)$inspection['id']]);
    if (!$check->fetch()) {
        return ['success' => false, 'error' => 'Checklist item not found.'];
    }
    $conn->prepare("UPDATE ars_move_inspections SET updated_at = NOW() WHERE id = ?")->execute([(int)$inspection['id']]);
    return ['success' => true, 'error' => null];
}

/** @return array{total:int,done:int,required_left:int} */
function ars_move_inspection_progress(PDO $conn, int $inspectionId): array {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(is_completed), 0) AS done,
               COALESCE(SUM(is_required = 1 AND is_completed = 0), 0) AS required_left
        FROM ars_move_inspection_items WHERE inspection_id = ?
    ");
    $stmt->execute([$inspectionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => (int)($row['total'] ?? 0),
        'done' => (int)($row['done'] ?? 0),
        'required_left' => (int)($row['required_left'] ?? 0),
    ];
}

/**
 * Close the inspection. Refused while a required item is still unticked.
 *
 * @return array{success:bool,error:?string}
 */
function ars_move_inspection_complete(PDO $conn, array $inspection, ?int $userId): array {
    if (($inspection['status'] ?? '') === 'completed') {
        return ['success' => true, 'error' => null];
    }
    $progress = ars_move_inspection_progress($conn, (int)$inspection['id']);
    if ($progress['required_left'] > 0) {
        return ['success' => false, 'error' => 'Tick all required items first (' . $progress['required_left'] . ' left).'];
    }
    $conn->prepare("
        UPDATE ars_move_inspections
        SET status = 'completed', completed_at = ?, completed_by = ?, updated_at = NOW()
        WHERE id = ? AND status = 'in_progress'
    ")->execute([ars_move_inspection_now(), $userId, (int)$inspection['id']]);
    return ['success' => true, 'error' => null];
}

/**
 * Inspection state for many bookings at once (the report's Inspection column).
 *
 * @param list<int> $bookingIds
 * @return array<string,array{status:string,total:int,done:int}> keyed "bookingId:type"
 */
function ars_move_inspection_summary(PDO $conn, int $companyId, array $bookingIds): array {
    ars_move_inspection_ensure_schema($conn);
    $bookingIds = array_values(array_unique(array_filter(array_map('intval', $bookingIds))));
    if (!$bookingIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($bookingIds), '?'));
    $stmt = $conn->prepare("
        SELECT i.booking_id, i.move_type, i.status,
               COUNT(it.id) AS total, COALESCE(SUM(it.is_completed), 0) AS done
        FROM ars_move_inspections i
        LEFT JOIN ars_move_inspection_items it ON it.inspection_id = i.id
        WHERE i.company_id = ? AND i.booking_id IN ($in)
        GROUP BY i.id
    ");
    $stmt->execute(array_merge([$companyId], $bookingIds));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['booking_id'] . ':' . $r['move_type']] = [
            'status' => (string)$r['status'],
            'total' => (int)$r['total'],
            'done' => (int)$r['done'],
        ];
    }
    return $out;
}

/** Short text for one summary entry: "Not started", "3 of 7" or "Done". */
function ars_move_inspection_summary_label(?array $entry): string {
    if (!$entry) {
        return 'Not started';
    }
    return $entry['status'] === 'completed' ? 'Done' : ($entry['done'] . ' of ' . $entry['total']);
}

// ---------------------------------------------------------------- photos

function ars_move_inspection_storage_root(): string {
    return dirname(__DIR__, 3) . '/uploads/ars_move_inspections';
}

/** @return array<string,string> ext => mime; also the whitelist used when serving */
function ars_move_inspection_photo_mime_map(): array {
    return [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];
}

function ars_move_inspection_ensure_storage(string $dir): bool {
    $root = ars_move_inspection_storage_root();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    // Photos are only ever read through move_inspection_photo.php, so deny
    // direct web access to the whole tree.
    $ht = $root . '/.htaccess';
    if (is_dir($root) && !is_file($ht)) {
        @file_put_contents($ht, implode("\n", [
            'Options -Indexes',
            '<IfModule mod_authz_core.c>',
            '  Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '  Order allow,deny',
            '  Deny from all',
            '</IfModule>',
            '',
        ]));
    }
    return is_writable($dir);
}

/** @return list<array<string,mixed>> */
function ars_move_inspection_photos(PDO $conn, int $inspectionId): array {
    $stmt = $conn->prepare("
        SELECT p.id, p.original_name, p.file_size, p.caption, p.created_at, u.fullname AS uploaded_by_name
        FROM ars_move_inspection_photos p
        LEFT JOIN `user` u ON u.id = p.uploaded_by
        WHERE p.inspection_id = ?
        ORDER BY p.id
    ");
    $stmt->execute([$inspectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{success:bool,error:?string,id:?int}
 */
function ars_move_inspection_photo_upload(PDO $conn, array $inspection, array $file, string $caption, ?int $userId): array {
    $fail = static fn(string $m): array => ['success' => false, 'error' => $m, 'id' => null];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $fail('Upload failed (error code ' . (int)($file['error'] ?? 0) . ').');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        return $fail('Each file must be 10 MB or smaller.');
    }
    $orig = (string)($file['name'] ?? 'photo');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $map = ars_move_inspection_photo_mime_map();
    if (!isset($map[$ext])) {
        return $fail('Only photos (JPG, PNG, WEBP, GIF) and PDF are allowed.');
    }
    if (class_exists('finfo')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $ok = $ext === 'pdf' ? $mime === 'application/pdf' : str_starts_with($mime, 'image/');
        if ($mime !== '' && !$ok) {
            return $fail('The file does not look like a ' . ($ext === 'pdf' ? 'PDF' : 'photo') . '.');
        }
    }

    $companyId = (int)$inspection['company_id'];
    $inspectionId = (int)$inspection['id'];
    $dir = ars_move_inspection_storage_root() . '/' . $companyId . '/' . $inspectionId;
    if (!ars_move_inspection_ensure_storage($dir)) {
        return $fail('Could not save the file. The uploads/ars_move_inspections folder is not writable.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
        return $fail('Could not save the uploaded file.');
    }
    $safeOrig = substr(preg_replace('/[^\w.\- ()]+/u', '_', $orig) ?: 'photo.' . $ext, 0, 240);
    $caption = mb_substr(trim($caption), 0, 255);
    $conn->prepare("
        INSERT INTO ars_move_inspection_photos
            (company_id, inspection_id, original_name, stored_name, relative_path, file_size, caption, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $companyId, $inspectionId, $safeOrig, $stored,
        'uploads/ars_move_inspections/' . $companyId . '/' . $inspectionId . '/' . $stored,
        $size, $caption !== '' ? $caption : null, $userId,
    ]);
    return ['success' => true, 'error' => null, 'id' => (int)$conn->lastInsertId()];
}

/**
 * @return array{success:bool,error:?string,path:?string,filename:?string,mime:?string}
 */
function ars_move_inspection_photo_resolve(PDO $conn, int $companyId, int $photoId): array {
    ars_move_inspection_ensure_schema($conn);
    $fail = static fn(string $m): array => ['success' => false, 'error' => $m, 'path' => null, 'filename' => null, 'mime' => null];
    $stmt = $conn->prepare("SELECT * FROM ars_move_inspection_photos WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$photoId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $fail('Photo not found.');
    }
    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$row['relative_path'], '/');
    if (!is_file($abs)) {
        return $fail('Photo file missing on disk.');
    }
    // Serve type comes from the stored extension, never from what the browser sent.
    $ext = strtolower(pathinfo((string)$row['stored_name'], PATHINFO_EXTENSION));
    return [
        'success' => true,
        'error' => null,
        'path' => $abs,
        'filename' => (string)$row['original_name'],
        'mime' => ars_move_inspection_photo_mime_map()[$ext] ?? 'application/octet-stream',
    ];
}

/** @return array{success:bool,error:?string} */
function ars_move_inspection_photo_delete(PDO $conn, array $inspection, int $photoId): array {
    if (($inspection['status'] ?? '') === 'completed') {
        return ['success' => false, 'error' => 'This inspection is completed; its photos cannot be removed.'];
    }
    $stmt = $conn->prepare("SELECT relative_path FROM ars_move_inspection_photos WHERE id = ? AND inspection_id = ?");
    $stmt->execute([$photoId, (int)$inspection['id']]);
    $rel = $stmt->fetchColumn();
    if ($rel === false) {
        return ['success' => false, 'error' => 'Photo not found.'];
    }
    $conn->prepare("DELETE FROM ars_move_inspection_photos WHERE id = ? AND inspection_id = ?")
        ->execute([$photoId, (int)$inspection['id']]);
    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$rel, '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
    return ['success' => true, 'error' => null];
}
