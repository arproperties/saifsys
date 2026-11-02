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
    // Videos of the unit's condition, next to the photos (uploaded in chunks, see "videos" below)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_move_inspection_videos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            inspection_id INT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            relative_path VARCHAR(500) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mime_type VARCHAR(100) NULL,
            caption VARCHAR(255) NULL,
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ars_miv_inspection (inspection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Move-out: an item found damaged, linked to its row in Damages (ars_move_out_damages)
    try {
        if (!$conn->query("SHOW COLUMNS FROM ars_move_inspection_items LIKE 'is_damaged'")->fetch()) {
            $conn->exec("ALTER TABLE ars_move_inspection_items ADD COLUMN is_damaged TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN damage_id INT NULL");
        }
    } catch (Throwable $e) {
        error_log('ARS checklist damage columns: ' . $e->getMessage());
    }
    // Checklist sections (General Condition, Kitchen, ...): added to older installs
    foreach (['ars_move_checklist_templates', 'ars_move_inspection_items'] as $t) {
        try {
            if (!$conn->query("SHOW COLUMNS FROM $t LIKE 'section_name'")->fetch()) {
                $conn->exec("ALTER TABLE $t ADD COLUMN section_name VARCHAR(100) NULL AFTER " . ($t === 'ars_move_inspection_items' ? 'template_id' : 'move_type'));
            }
        } catch (Throwable $e) {
            error_log('ARS checklist section column (' . $t . '): ' . $e->getMessage());
        }
    }
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
        . '<a class="' . $class($active === 'checklist') . '" href="move_inspection_template.php">' . ars_ui_icon('list-checks', ['class' => 'h-4 w-4']) . ' Inspection Template</a>'
        . '<a class="' . $class($active === 'settings') . '" href="move_settings.php">' . ars_ui_icon('settings', ['class' => 'h-4 w-4']) . ' Settings</a>'
        . '</div>';
}

/** Booking statuses an inspection of this direction can be opened for. */
function ars_move_inspection_allowed_statuses(string $type): array {
    return $type === 'out'
        ? ['checked_in', 'checked_out', 'completed']
        : ['pending', 'confirmed', 'checked_in', 'checked_out', 'completed'];
}

/**
 * Standard checklist (the company's Move-In / Move-Out Inspection Report): 10 sections
 * for a furnished unit. Section 10, Inspection Remarks, is the inspection notes box.
 * "(if any)" / "(if provided)" items are not required: tick them only when the unit has one.
 *
 * @return list<array{0:string,1:string,2:int}> section, item, required
 */
function ars_move_inspection_default_items(string $type): array {
    $out = $type === 'out';
    $s = [
        'General Condition' => [
            ['Apartment is clean and in good condition', 1],
            ['No major wall damage (holes, stains, etc.)', 1],
            ['AC unit is cooling properly', 1],
            ['AC thermostat working and functional', 1],
            ['No foul odor; space is well-ventilated', 1],
            ['Floors clean and undamaged', 1],
            [$out ? 'All keys/remotes returned (main door, mailbox, parking, access cards)' : 'All keys/remotes handed over (main door, mailbox, parking, access cards)', 1],
        ],
        'Furniture & Fixtures' => [
            ['All original furniture items present (as per inventory list)', 1],
            ['Furniture is undamaged and in usable condition', 1],
            ['No stains, tears, or burn marks on upholstery/fabric', 1],
            ['All cabinets, drawers, and shelves intact and functional', 1],
            ['Curtains/blinds present and undamaged', 1],
            ['Light fixtures installed and not missing', 1],
        ],
        'Appliances' => [
            ['Free-standing refrigerator clean and working/functional (if any)', 0],
            ['Free-standing cooker range and hood clean and working/functional (if any)', 0],
            ['Free-standing washing machine working/functional (if any)', 0],
            ['Microwave/oven/stove clean and operational (if any)', 0],
            ['TV (if provided) working with remote', 0],
            ['Air-conditioning units cooling properly', 1],
            ['Water heater working', 1],
            ['All provided appliances accounted for and undamaged', 1],
        ],
        'Lighting & Electrical' => [
            ['All light fixtures/bulbs functional', 1],
            ['All power sockets tested and working', 1],
            ['Switches and circuit breakers working properly', 1],
            [$out ? 'Internet router/modem (if provided) returned and functioning' : 'Internet router/modem (if provided) in place and functioning', 0],
        ],
        'Kitchen' => [
            ['Kitchenware (utensils, pans, dishes - if applicable) accounted for', 0],
            ['Countertops, sink, and cabinets clean and undamaged', 1],
            ['Exhaust/hood filter cleaned', 1],
            ['Sink clean; no leaks; strainer clear', 1],
            ['All cabinets clean and functional', 1],
            ['Stove burners clean and undamaged', 1],
        ],
        'Unit' => [
            ['Room clean and free of personal items', 1],
            ['Walls clean; no holes, scratches, or stains', 1],
            ['Floor clean and undamaged', 1],
            ['Built-in wardrobe/cabinets clean and functional', 1],
            ['Bedframe/mattress (if provided) clean and undamaged', 0],
            ['Curtains/blinds clean and functional', 1],
            ['Light fixtures working', 1],
            ['AC unit cooling properly', 1],
            ['AC thermostat working and functional', 1],
            ['Windows and locks working', 1],
            ['Drawers open/close properly', 1],
        ],
        'Bathroom' => [
            ['Toilets flushed and clean', 1],
            ['Bidet and bidet shower working, and fixtures in good condition', 1],
            ['Sink, shower, and faucets functioning without leaks', 1],
            ['Mirrors, shelves, and fixtures in good condition', 1],
            ['No molds or water damage', 1],
            ['Drains clear and not clogged', 1],
            ['No foul odor; space is well-ventilated', 1],
        ],
        'Additional Areas' => [
            ['Balcony/terrace cleaned, no debris (if any)', 0],
            ['Windows and sliding doors clean and functional', 1],
            ['Storage rooms/maid\'s room empty and clean (if any)', 0],
            ['Parking slot clean (if applicable)', 0],
        ],
        'Final Walkthrough' => [
            ['Walkthrough conducted with the guest and the maintenance service provider', 1],
            ['Photos/videos taken of current condition', 1],
        ],
    ];
    $rows = [];
    foreach ($s as $section => $items) {
        foreach ($items as [$name, $req]) {
            $rows[] = [$section, $name, $req];
        }
    }
    return $rows;
}

/** Section names of the standard checklist, in order (for the settings page). */
function ars_move_inspection_sections(): array {
    return ['General Condition', 'Furniture & Fixtures', 'Appliances', 'Lighting & Electrical', 'Kitchen', 'Unit', 'Bathroom', 'Additional Areas', 'Final Walkthrough'];
}

/**
 * Replace the company's checklist for one direction with the standard one. The old
 * items are kept inactive (hidden); inspections already started keep their own items.
 */
function ars_move_inspection_apply_standard(PDO $conn, int $companyId, string $type): int {
    ars_move_inspection_ensure_schema($conn);
    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE ars_move_checklist_templates SET is_active = 0 WHERE company_id = ? AND move_type = ? AND is_active = 1")
            ->execute([$companyId, $type]);
        $ins = $conn->prepare("
            INSERT INTO ars_move_checklist_templates
                (company_id, move_type, section_name, item_name, item_description, is_required, display_order)
            VALUES (?, ?, ?, ?, NULL, ?, ?)
        ");
        $rows = ars_move_inspection_default_items($type);
        foreach ($rows as $i => [$section, $name, $req]) {
            $ins->execute([$companyId, $type, $section, $name, $req, $i + 1]);
        }
        $conn->commit();
        return count($rows);
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
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
                (company_id, move_type, section_name, item_name, item_description, is_required, display_order)
            VALUES (?, ?, ?, ?, NULL, ?, ?)
        ");
        foreach (ars_move_inspection_default_items($type) as $i => [$section, $name, $req]) {
            $ins->execute([$companyId, $type, $section, $name, $req, $i + 1]);
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
                    (company_id, inspection_id, template_id, section_name, item_name, item_description, is_required, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($templates as $i => $t) {
                $item->execute([
                    $companyId, $inspectionId, $t['id'], $t['section_name'] ?? null, $t['item_name'], $t['item_description'],
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
    // "Photos taken" can only be ticked once a photo is uploaded to this inspection
    if ($completed) {
        $it = $conn->prepare("SELECT item_name FROM ars_move_inspection_items WHERE id = ? AND inspection_id = ?");
        $it->execute([$itemId, (int)$inspection['id']]);
        $itemName = $it->fetchColumn();
        if ($itemName !== false && ars_move_inspection_is_photo_item((string)$itemName) && ars_move_inspection_photo_count($conn, (int)$inspection['id']) === 0) {
            return ['success' => false, 'error' => 'Upload at least one photo or video first (below the checklist); then tick this item.'];
        }
    }
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

/** The checklist item that says photos were taken ("Photos/videos taken of current condition"). */
function ars_move_inspection_is_photo_item(string $name): bool {
    return stripos($name, 'photo') !== false;
}

/** Photos uploaded: "Photos taken" is ticked by itself (as in the Real Estate move-out). Returns items ticked. */
function ars_move_inspection_sync_photo_items(PDO $conn, int $inspectionId, ?int $userId): int {
    if (ars_move_inspection_photo_count($conn, $inspectionId) === 0) {
        return 0;
    }
    $st = $conn->prepare("
        UPDATE ars_move_inspection_items
        SET is_completed = 1, completed_at = ?, completed_by = ?
        WHERE inspection_id = ? AND is_completed = 0 AND item_name LIKE '%photo%'
    ");
    $st->execute([ars_move_inspection_now(), $userId, $inspectionId]);
    return $st->rowCount();
}

/** Photos plus videos: either one counts for "Photos/videos taken". */
function ars_move_inspection_photo_count(PDO $conn, int $inspectionId): int {
    ars_move_inspection_ensure_schema($conn);
    $st = $conn->prepare("SELECT (SELECT COUNT(*) FROM ars_move_inspection_photos WHERE inspection_id = ?) + (SELECT COUNT(*) FROM ars_move_inspection_videos WHERE inspection_id = ?)");
    $st->execute([$inspectionId, $inspectionId]);
    return (int)$st->fetchColumn();
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

/** Formats accepted for new inspection photos (extension => MIME). Older PDF / GIF uploads still open. */
function ars_move_inspection_photo_upload_types(): array {
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
}

/**
 * Re-save an uploaded image as a clean file of the same type (drops anything hidden in
 * it, e.g. a script or the GPS location; JPEG photos are turned upright first).
 * Returns false when GD cannot do it, so the checked original is kept.
 */
function ars_move_inspection_photo_reencode(string $src, string $dest, string $mime): bool {
    $load = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime] ?? null;
    $save = ['image/jpeg' => 'imagejpeg', 'image/png' => 'imagepng', 'image/webp' => 'imagewebp'][$mime] ?? null;
    if (!$load || !$save || !function_exists($load) || !function_exists($save)) {
        return false;
    }
    $img = @$load($src);
    if (!$img) {
        return false;
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($src)['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg && ($rot = imagerotate($img, $deg, 0))) {
            imagedestroy($img);
            $img = $rot;
        }
    }
    if ($mime === 'image/png') {
        imagesavealpha($img, true);
    }
    $ok = $mime === 'image/jpeg' ? imagejpeg($img, $dest, 85) : ($mime === 'image/png' ? imagepng($img, $dest, 6) : imagewebp($img, $dest, 85));
    imagedestroy($img);
    return $ok && is_file($dest) && filesize($dest) > 0;
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
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return $fail('Upload failed. Please choose the photo again.');
    }
    // 1. Extension: photos only
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $types = ars_move_inspection_photo_upload_types();
    if (!isset($types[$ext])) {
        return $fail('Only photos are allowed: JPG, JPEG, PNG or WEBP.');
    }
    // 2. Content: the real file type must be the one its name says (not just "an image")
    $mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
    if ($mime !== $types[$ext]) {
        return $fail('The file is not a real ' . strtoupper($ext) . ' image' . ($mime !== '' ? ' (it is ' . $mime . ')' : '') . '.');
    }
    // 3. It must open as an image of a sensible size (also stops oversized "image bombs")
    $info = @getimagesize($tmp);
    if (!$info || ($info['mime'] ?? '') !== $types[$ext] || $info[0] < 1 || $info[1] < 1) {
        return $fail('The file could not be read as an image.');
    }
    if ($info[0] * $info[1] > 50000000) {
        return $fail('The image is too large (' . $info[0] . ' x ' . $info[1] . ' pixels). Please upload a smaller photo.');
    }

    $companyId = (int)$inspection['company_id'];
    $inspectionId = (int)$inspection['id'];
    $dir = ars_move_inspection_storage_root() . '/' . $companyId . '/' . $inspectionId;
    if (!ars_move_inspection_ensure_storage($dir)) {
        return $fail('Could not save the file. The uploads/ars_move_inspections folder is not writable.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = $dir . '/' . $stored;
    if (ars_move_inspection_photo_reencode($tmp, $dest, $types[$ext])) {
        @unlink($tmp);
        $size = (int)filesize($dest);
    } elseif (!move_uploaded_file($tmp, $dest)) {
        return $fail('Could not save the uploaded file.');
    }
    @chmod($dest, 0644);
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
    // Last photo removed: "Photos taken" is no longer true
    if (ars_move_inspection_photo_count($conn, (int)$inspection['id']) === 0) {
        $conn->prepare("UPDATE ars_move_inspection_items SET is_completed = 0, completed_at = NULL, completed_by = NULL WHERE inspection_id = ? AND is_completed = 1 AND item_name LIKE '%photo%'")
            ->execute([(int)$inspection['id']]);
    }
    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$rel, '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
    return ['success' => true, 'error' => null];
}

// ---------------------------------------------------------------- videos
// A video is sent in small chunks (each well under upload_max_filesize / post_max_size),
// so a large phone video uploads on any server and the page can show real progress.
// init -> chunk (repeated, in order) -> finish; the file is only listed once finish
// has checked the whole file. Unfinished uploads sit in _tmp and are swept after 12 hours.

const ARS_MOVE_VIDEO_MAX_BYTES = 200 * 1024 * 1024;
const ARS_MOVE_VIDEO_MAX_PER_INSPECTION = 10;

/**
 * Accepted video formats: ext => [MIME types the file content may be detected as, MIME to serve].
 * MP4 / MOV / M4V / 3GP are the same container family, so detection can name any of them.
 */
function ars_move_inspection_video_types(): array {
    $iso = ['video/mp4', 'video/x-m4v', 'video/quicktime', 'video/3gpp', 'video/3gpp2', 'application/mp4'];
    return [
        'mp4' => [$iso, 'video/mp4'],
        'm4v' => [$iso, 'video/mp4'],
        // H.264 .mov files play in Chrome / Edge only when served as MP4
        'mov' => [$iso, 'video/mp4'],
        '3gp' => [$iso, 'video/3gpp'],
        'webm' => [['video/webm', 'video/x-matroska'], 'video/webm'],
    ];
}

/** The file's first bytes must be a real video container of that kind (not just a renamed file). */
function ars_move_inspection_video_signature_ok(string $head, string $ext): bool {
    if (strlen($head) < 12) {
        return false;
    }
    if ($ext === 'webm') {
        return substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
    }
    $box = substr($head, 4, 4);
    // Older QuickTime files can start with a moov / mdat / wide atom instead of ftyp
    return $box === 'ftyp' || ($ext === 'mov' && in_array($box, ['moov', 'mdat', 'wide', 'free', 'skip', 'pnot'], true));
}

function ars_move_inspection_ini_bytes(string $key): int {
    $v = trim((string)ini_get($key));
    if ($v === '' || $v === '-1' || $v === '0') {
        return PHP_INT_MAX;
    }
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n > 0 ? $n : PHP_INT_MAX;
}

/** Chunk size: 2 MB, or less when the server's upload limits are smaller. */
function ars_move_inspection_video_chunk_size(): int {
    $limit = min(ars_move_inspection_ini_bytes('upload_max_filesize'), ars_move_inspection_ini_bytes('post_max_size')) - 64 * 1024;
    return max(256 * 1024, min(2 * 1024 * 1024, $limit));
}

function ars_move_inspection_video_tmp_dir(): string {
    return ars_move_inspection_storage_root() . '/_tmp';
}

function ars_move_inspection_video_size_label(int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** @return list<array<string,mixed>> */
function ars_move_inspection_videos(PDO $conn, int $inspectionId): array {
    ars_move_inspection_ensure_schema($conn);
    $stmt = $conn->prepare("
        SELECT v.id, v.original_name, v.file_size, v.caption, v.created_at, u.fullname AS uploaded_by_name
        FROM ars_move_inspection_videos v
        LEFT JOIN `user` u ON u.id = v.uploaded_by
        WHERE v.inspection_id = ?
        ORDER BY v.id
    ");
    $stmt->execute([$inspectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Remove upload sessions left unfinished (closed tab, lost connection) for more than 12 hours. */
function ars_move_inspection_video_sweep(): void {
    foreach (glob(ars_move_inspection_video_tmp_dir() . '/*.json') ?: [] as $meta) {
        if (@filemtime($meta) < time() - 12 * 3600) {
            @unlink(substr($meta, 0, -5) . '.part');
            @unlink($meta);
        }
    }
}

/**
 * Load an upload session and check it belongs to this inspection and user.
 * @return array{0:?array,1:?string,2:string} [meta, error, part path]
 */
function ars_move_inspection_video_session(array $inspection, string $token, ?int $userId): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return [null, 'Invalid upload. Please choose the video again.', ''];
    }
    $base = ars_move_inspection_video_tmp_dir() . '/' . $token;
    $meta = is_file($base . '.json') ? json_decode((string)file_get_contents($base . '.json'), true) : null;
    if (!is_array($meta) || !is_file($base . '.part')) {
        return [null, 'This upload has expired. Please upload the video again.', ''];
    }
    if ((int)$meta['company_id'] !== (int)$inspection['company_id'] || (int)$meta['inspection_id'] !== (int)$inspection['id'] || (int)$meta['user_id'] !== (int)$userId) {
        return [null, 'This upload belongs to another inspection. Please upload the video again.', ''];
    }
    return [$meta, null, $base . '.part'];
}

function ars_move_inspection_video_discard(string $token): void {
    if (preg_match('/^[a-f0-9]{32}$/', $token)) {
        $base = ars_move_inspection_video_tmp_dir() . '/' . $token;
        @unlink($base . '.part');
        @unlink($base . '.json');
    }
}

/** Start an upload: checks the name, type and size before any byte is sent. */
function ars_move_inspection_video_init(PDO $conn, array $inspection, string $name, int $size, string $caption, ?int $userId): array {
    $fail = static fn(string $m): array => ['success' => false, 'error' => $m];
    if (($inspection['status'] ?? '') === 'completed') {
        return $fail('This inspection is completed; videos cannot be added.');
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(ars_move_inspection_video_types()[$ext])) {
        return $fail('Only videos are allowed: MP4, MOV, M4V, 3GP or WEBM.');
    }
    if ($size <= 0) {
        return $fail('The video file is empty.');
    }
    if ($size > ARS_MOVE_VIDEO_MAX_BYTES) {
        return $fail('The video is ' . ars_move_inspection_video_size_label($size) . '. The maximum is ' . ars_move_inspection_video_size_label(ARS_MOVE_VIDEO_MAX_BYTES) . '; trim it or record a shorter video.');
    }
    $st = $conn->prepare("SELECT COUNT(*) FROM ars_move_inspection_videos WHERE inspection_id = ?");
    $st->execute([(int)$inspection['id']]);
    if ((int)$st->fetchColumn() >= ARS_MOVE_VIDEO_MAX_PER_INSPECTION) {
        return $fail('An inspection can have at most ' . ARS_MOVE_VIDEO_MAX_PER_INSPECTION . ' videos. Remove one to add another.');
    }
    $tmp = ars_move_inspection_video_tmp_dir();
    if (!ars_move_inspection_ensure_storage($tmp)) {
        return $fail('Could not save the video. The uploads/ars_move_inspections folder is not writable.');
    }
    ars_move_inspection_video_sweep();
    $free = @disk_free_space($tmp);
    if ($free !== false && $free < $size + 100 * 1024 * 1024) {
        return $fail('Not enough space on the server for this video. Please contact the administrator.');
    }
    $token = bin2hex(random_bytes(16));
    $meta = [
        'company_id' => (int)$inspection['company_id'],
        'inspection_id' => (int)$inspection['id'],
        'user_id' => (int)$userId,
        'name' => substr(preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'video.' . $ext, 0, 240),
        'ext' => $ext,
        'size' => $size,
        'caption' => mb_substr(trim($caption), 0, 255),
    ];
    if (file_put_contents($tmp . '/' . $token . '.part', '') === false || file_put_contents($tmp . '/' . $token . '.json', json_encode($meta)) === false) {
        ars_move_inspection_video_discard($token);
        return $fail('Could not start the upload on the server.');
    }
    return ['success' => true, 'error' => null, 'token' => $token, 'chunk_size' => ars_move_inspection_video_chunk_size()];
}

/**
 * Add one chunk at $offset. A chunk the server already has (a retry after a lost reply)
 * is skipped, so resending is always safe. 'received' tells the page where to continue.
 */
function ars_move_inspection_video_chunk(array $inspection, string $token, int $offset, array $file, ?int $userId): array {
    [$meta, $err, $part] = ars_move_inspection_video_session($inspection, $token, $userId);
    if (!$meta) {
        return ['success' => false, 'error' => $err];
    }
    clearstatcache(true, $part);
    $have = (int)filesize($part);
    if ($offset < $have) {
        return ['success' => true, 'error' => null, 'received' => $have];
    }
    if ($offset > $have) {
        return ['success' => false, 'error' => 'A part of the video was lost on the way. Resuming…', 'received' => $have, 'resume' => true];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return ['success' => false, 'error' => 'A part of the video did not arrive (error ' . (int)($file['error'] ?? 0) . '). Retrying…', 'received' => $have, 'resume' => true];
    }
    $len = (int)filesize((string)$file['tmp_name']);
    if ($len <= 0 || $len > ars_move_inspection_video_chunk_size() || $offset + $len > (int)$meta['size']) {
        ars_move_inspection_video_discard($token);
        return ['success' => false, 'error' => 'The video data does not match the file that was chosen. Please upload it again.'];
    }
    $in = fopen((string)$file['tmp_name'], 'rb');
    if ($offset === 0) {
        // First chunk: stop at once when the file is not really a video
        $head = (string)fread($in, 16);
        if (!ars_move_inspection_video_signature_ok($head, (string)$meta['ext'])) {
            fclose($in);
            ars_move_inspection_video_discard($token);
            return ['success' => false, 'error' => 'The file is not a real ' . strtoupper((string)$meta['ext']) . ' video. Only MP4, MOV, M4V, 3GP or WEBM videos are allowed.'];
        }
        rewind($in);
    }
    $out = fopen($part, 'ab');
    if (!$out || !flock($out, LOCK_EX)) {
        fclose($in);
        return ['success' => false, 'error' => 'The server is busy. Retrying…', 'received' => $have, 'resume' => true];
    }
    clearstatcache(true, $part);
    $have = (int)filesize($part);
    if ($have !== $offset) {
        // Another request wrote meanwhile (double send): report where the file is now
        flock($out, LOCK_UN);
        fclose($out);
        fclose($in);
        return ['success' => $have > $offset, 'error' => $have > $offset ? null : 'Resuming…', 'received' => $have, 'resume' => $have < $offset];
    }
    $copied = stream_copy_to_stream($in, $out);
    fflush($out);
    flock($out, LOCK_UN);
    fclose($out);
    fclose($in);
    @touch(substr($part, 0, -5) . '.json');
    clearstatcache(true, $part);
    $now = (int)filesize($part);
    if ($copied !== $len || $now !== $offset + $len) {
        // Partial write (e.g. disk full): cut back to the last good byte so a retry can continue
        $fh = fopen($part, 'r+');
        if ($fh) {
            ftruncate($fh, $offset);
            fclose($fh);
        }
        return ['success' => false, 'error' => 'Could not write the video on the server. Retrying…', 'received' => $offset, 'resume' => true];
    }
    return ['success' => true, 'error' => null, 'received' => $now];
}

/** All chunks are in: check the whole file once more, then list it on the inspection. */
function ars_move_inspection_video_finish(PDO $conn, array $inspection, string $token, ?int $userId): array {
    $fail = static function (string $m) use ($token): array {
        ars_move_inspection_video_discard($token);
        return ['success' => false, 'error' => $m, 'id' => null];
    };
    [$meta, $err, $part] = ars_move_inspection_video_session($inspection, $token, $userId);
    if (!$meta) {
        return ['success' => false, 'error' => $err, 'id' => null];
    }
    if (($inspection['status'] ?? '') === 'completed') {
        return $fail('This inspection was completed while the video was uploading; it was not added.');
    }
    clearstatcache(true, $part);
    $size = (int)filesize($part);
    if ($size !== (int)$meta['size']) {
        return ['success' => false, 'error' => 'The upload is not complete yet (' . ars_move_inspection_video_size_label($size) . ' of ' . ars_move_inspection_video_size_label((int)$meta['size']) . ').', 'id' => null, 'received' => $size, 'resume' => true];
    }
    $ext = (string)$meta['ext'];
    [$allowed, $serveMime] = ars_move_inspection_video_types()[$ext];
    $fh = fopen($part, 'rb');
    $head = $fh ? (string)fread($fh, 16) : '';
    if ($fh) {
        fclose($fh);
    }
    if (!ars_move_inspection_video_signature_ok($head, $ext)) {
        return $fail('The file is not a real ' . strtoupper($ext) . ' video.');
    }
    $mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($part) : '';
    if ($mime !== '' && !in_array($mime, $allowed, true)) {
        return $fail('The file is not a real ' . strtoupper($ext) . ' video (it is ' . $mime . ').');
    }
    $st = $conn->prepare("SELECT COUNT(*) FROM ars_move_inspection_videos WHERE inspection_id = ?");
    $st->execute([(int)$inspection['id']]);
    if ((int)$st->fetchColumn() >= ARS_MOVE_VIDEO_MAX_PER_INSPECTION) {
        return $fail('An inspection can have at most ' . ARS_MOVE_VIDEO_MAX_PER_INSPECTION . ' videos.');
    }
    $companyId = (int)$inspection['company_id'];
    $inspectionId = (int)$inspection['id'];
    $dir = ars_move_inspection_storage_root() . '/' . $companyId . '/' . $inspectionId;
    if (!ars_move_inspection_ensure_storage($dir)) {
        return $fail('Could not save the video. The uploads/ars_move_inspections folder is not writable.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!@rename($part, $dir . '/' . $stored)) {
        return $fail('Could not save the uploaded video.');
    }
    @chmod($dir . '/' . $stored, 0644);
    @unlink(ars_move_inspection_video_tmp_dir() . '/' . $token . '.json');
    $conn->prepare("
        INSERT INTO ars_move_inspection_videos
            (company_id, inspection_id, original_name, stored_name, relative_path, file_size, mime_type, caption, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $companyId, $inspectionId, (string)$meta['name'], $stored,
        'uploads/ars_move_inspections/' . $companyId . '/' . $inspectionId . '/' . $stored,
        $size, $serveMime, $meta['caption'] !== '' ? $meta['caption'] : null, $userId,
    ]);
    $videoId = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE ars_move_inspections SET updated_at = NOW() WHERE id = ?")->execute([$inspectionId]);
    return ['success' => true, 'error' => null, 'id' => $videoId];
}

/**
 * @return array{success:bool,error:?string,path:?string,filename:?string,mime:?string}
 */
function ars_move_inspection_video_resolve(PDO $conn, int $companyId, int $videoId): array {
    ars_move_inspection_ensure_schema($conn);
    $fail = static fn(string $m): array => ['success' => false, 'error' => $m, 'path' => null, 'filename' => null, 'mime' => null];
    $stmt = $conn->prepare("SELECT * FROM ars_move_inspection_videos WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$videoId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $fail('Video not found.');
    }
    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$row['relative_path'], '/');
    if (!is_file($abs)) {
        return $fail('Video file missing on disk.');
    }
    // Serve type comes from the stored extension, never from what the browser sent.
    $ext = strtolower(pathinfo((string)$row['stored_name'], PATHINFO_EXTENSION));
    return [
        'success' => true,
        'error' => null,
        'path' => $abs,
        'filename' => (string)$row['original_name'],
        'mime' => ars_move_inspection_video_types()[$ext][1] ?? 'application/octet-stream',
    ];
}

/** @return array{success:bool,error:?string} */
function ars_move_inspection_video_delete(PDO $conn, array $inspection, int $videoId): array {
    if (($inspection['status'] ?? '') === 'completed') {
        return ['success' => false, 'error' => 'This inspection is completed; its videos cannot be removed.'];
    }
    $stmt = $conn->prepare("SELECT relative_path FROM ars_move_inspection_videos WHERE id = ? AND inspection_id = ?");
    $stmt->execute([$videoId, (int)$inspection['id']]);
    $rel = $stmt->fetchColumn();
    if ($rel === false) {
        return ['success' => false, 'error' => 'Video not found.'];
    }
    $conn->prepare("DELETE FROM ars_move_inspection_videos WHERE id = ? AND inspection_id = ?")
        ->execute([$videoId, (int)$inspection['id']]);
    // Last photo / video removed: "Photos taken" is no longer true
    if (ars_move_inspection_photo_count($conn, (int)$inspection['id']) === 0) {
        $conn->prepare("UPDATE ars_move_inspection_items SET is_completed = 0, completed_at = NULL, completed_by = NULL WHERE inspection_id = ? AND is_completed = 1 AND item_name LIKE '%photo%'")
            ->execute([(int)$inspection['id']]);
    }
    $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$rel, '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
    return ['success' => true, 'error' => null];
}
