<?php
/**
 * Tenant Communication Center — announcements domain.
 * Source of truth for Customer App, Owner App, Website, AI, WhatsApp.
 * Does not invent financial logic; targeting resolves portal users by lease/unit/building.
 */

declare(strict_types=1);

function re_ann_project_root(): string
{
    return dirname(__DIR__);
}

function re_ann_upload_base(): string
{
    return re_ann_project_root() . '/uploads/realestate/announcements';
}

function re_ann_ensure_htaccess(): void
{
    $base = re_ann_upload_base();
    if (!is_dir($base)) {
        @mkdir($base, 0777, true);
    }
    @chmod($base, 0777);
    $ht = $base . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Deny PHP execution in announcement uploads\n"
            . "<FilesMatch \"\\.(?i:php|phtml|php3|php4|php5|phar)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "Options -ExecCGI\n"
            . "RemoveHandler .php .phtml .php3 .php4 .php5 .phar\n");
    }
}

function re_ann_default_categories(): array
{
    return [
        ['code' => 'maintenance', 'name' => 'Maintenance', 'sort_order' => 10],
        ['code' => 'utilities', 'name' => 'Utilities', 'sort_order' => 20],
        ['code' => 'safety', 'name' => 'Safety', 'sort_order' => 30],
        ['code' => 'security', 'name' => 'Security', 'sort_order' => 40],
        ['code' => 'pest_control', 'name' => 'Pest Control', 'sort_order' => 50],
        ['code' => 'community', 'name' => 'Community', 'sort_order' => 60],
        ['code' => 'management', 'name' => 'Management', 'sort_order' => 70],
        ['code' => 'emergency', 'name' => 'Emergency', 'sort_order' => 5],
        ['code' => 'holiday', 'name' => 'Holiday', 'sort_order' => 80],
        ['code' => 'other', 'name' => 'Other', 'sort_order' => 90],
    ];
}

function re_ann_priorities(): array
{
    return [
        'critical' => 'Critical',
        'high' => 'High',
        'normal' => 'Normal',
        'information' => 'Information',
    ];
}

function re_ann_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->exec("
        CREATE TABLE IF NOT EXISTS re_announcement_categories (
          id INT(11) NOT NULL AUTO_INCREMENT,
          company_id INT(11) NOT NULL,
          code VARCHAR(50) NOT NULL,
          name VARCHAR(100) NOT NULL,
          sort_order INT(11) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_re_ann_cat_company_code (company_id, code),
          KEY idx_re_ann_cat_active (company_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS re_announcements (
          id INT(11) NOT NULL AUTO_INCREMENT,
          company_id INT(11) NOT NULL,
          category_id INT(11) DEFAULT NULL,
          title VARCHAR(200) NOT NULL,
          body MEDIUMTEXT NOT NULL,
          priority ENUM('critical','high','normal','information') NOT NULL DEFAULT 'normal',
          status ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
          publish_at DATETIME DEFAULT NULL,
          expire_at DATETIME DEFAULT NULL,
          target_scope ENUM('company','buildings','units','tenants','group') NOT NULL DEFAULT 'company',
          image_path VARCHAR(500) DEFAULT NULL,
          image_mime VARCHAR(120) DEFAULT NULL,
          send_in_app TINYINT(1) NOT NULL DEFAULT 1,
          send_push TINYINT(1) NOT NULL DEFAULT 0,
          push_batch_id INT(11) DEFAULT NULL,
          created_by INT(11) DEFAULT NULL,
          published_by INT(11) DEFAULT NULL,
          published_at DATETIME DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_re_ann_company_status (company_id, status, publish_at),
          KEY idx_re_ann_priority (company_id, priority, status),
          KEY idx_re_ann_category (category_id),
          KEY idx_re_ann_expire (company_id, expire_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS re_announcement_targets (
          id INT(11) NOT NULL AUTO_INCREMENT,
          company_id INT(11) NOT NULL,
          announcement_id INT(11) NOT NULL,
          target_type ENUM('building','unit','tenant','tenant_portal_user','group') NOT NULL,
          target_id INT(11) NOT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_re_ann_target (announcement_id, target_type, target_id),
          KEY idx_re_ann_target_lookup (company_id, target_type, target_id),
          KEY idx_re_ann_target_ann (announcement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS re_announcement_attachments (
          id INT(11) NOT NULL AUTO_INCREMENT,
          company_id INT(11) NOT NULL,
          announcement_id INT(11) NOT NULL,
          file_path VARCHAR(500) NOT NULL,
          file_name VARCHAR(255) NOT NULL,
          mime_type VARCHAR(120) DEFAULT NULL,
          file_size INT(11) DEFAULT NULL,
          uploaded_by INT(11) DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_re_ann_attach (announcement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    re_ann_ensure_htaccess();
}

function re_ann_seed_categories(PDO $conn, int $companyId): void
{
    if ($companyId <= 0) {
        return;
    }
    $ins = $conn->prepare('
        INSERT IGNORE INTO re_announcement_categories (company_id, code, name, sort_order, is_active)
        VALUES (?, ?, ?, ?, 1)
    ');
    foreach (re_ann_default_categories() as $cat) {
        $ins->execute([$companyId, $cat['code'], $cat['name'], $cat['sort_order']]);
    }
}

/**
 * Lifecycle label for UI / consumers (does not mutate DB).
 */
function re_ann_lifecycle_label(array $row, ?DateTimeInterface $now = null): string
{
    $status = (string)($row['status'] ?? 'draft');
    if ($status === 'draft') {
        return 'Draft';
    }
    if ($status === 'archived') {
        return 'Archived';
    }
    $nowTs = ($now ?? new DateTimeImmutable('now'))->getTimestamp();
    $publishAt = !empty($row['publish_at']) ? strtotime((string)$row['publish_at']) : null;
    $expireAt = !empty($row['expire_at']) ? strtotime((string)$row['expire_at']) : null;

    if ($status === 'scheduled' || ($status === 'published' && $publishAt && $publishAt > $nowTs)) {
        return 'Scheduled';
    }
    if ($expireAt && $expireAt < $nowTs) {
        return 'Expired';
    }
    if ($status === 'published') {
        return 'Active';
    }
    return ucfirst($status);
}

function re_ann_is_active_now(array $row, ?DateTimeInterface $now = null): bool
{
    if ((string)($row['status'] ?? '') !== 'published') {
        return false;
    }
    $nowTs = ($now ?? new DateTimeImmutable('now'))->getTimestamp();
    if (!empty($row['publish_at']) && strtotime((string)$row['publish_at']) > $nowTs) {
        return false;
    }
    if (!empty($row['expire_at']) && strtotime((string)$row['expire_at']) < $nowTs) {
        return false;
    }
    return true;
}

/**
 * @return list<array<string,mixed>>
 */
function re_ann_list_categories(PDO $conn, int $companyId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM re_announcement_categories WHERE company_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    $st = $conn->prepare($sql);
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array{type:string,id:int}> $targets
 */
function re_ann_replace_targets(PDO $conn, int $companyId, int $announcementId, string $scope, array $targets): void
{
    $del = $conn->prepare('DELETE FROM re_announcement_targets WHERE announcement_id = ? AND company_id = ?');
    $del->execute([$announcementId, $companyId]);

    if ($scope === 'company' || $scope === 'group') {
        // company = all tenants; group reserved (no rows yet)
        return;
    }

    $ins = $conn->prepare('
        INSERT INTO re_announcement_targets (company_id, announcement_id, target_type, target_id)
        VALUES (?, ?, ?, ?)
    ');
    $allowed = [
        'buildings' => 'building',
        'units' => 'unit',
        'tenants' => 'tenant',
    ];
    $expectedType = $allowed[$scope] ?? null;
    if ($expectedType === null) {
        return;
    }
    foreach ($targets as $t) {
        $type = (string)($t['type'] ?? $expectedType);
        $id = (int)($t['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ($type !== $expectedType && !($scope === 'tenants' && in_array($type, ['tenant', 'tenant_portal_user'], true))) {
            $type = $expectedType;
        }
        $ins->execute([$companyId, $announcementId, $type, $id]);
    }
}

/**
 * @return list<array<string,mixed>>
 */
function re_ann_get_targets(PDO $conn, int $companyId, int $announcementId): array
{
    $st = $conn->prepare('SELECT * FROM re_announcement_targets WHERE company_id = ? AND announcement_id = ?');
    $st->execute([$companyId, $announcementId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Resolve portal users for an announcement (for push / future inbox fan-out).
 *
 * @return list<array<string,mixed>>
 */
function re_ann_resolve_portal_recipients(PDO $conn, int $companyId, array $announcement, array $targets): array
{
    $scope = (string)($announcement['target_scope'] ?? 'company');
    $baseSelect = "
        SELECT DISTINCT
            tpu.id AS tenant_portal_user_id,
            tpu.tenant_id,
            tpu.company_id,
            tpu.display_name,
            tpu.email
        FROM tenant_portal_users tpu
    ";
    $where = ["tpu.company_id = ?", "tpu.status = 'approved'"];
    $params = [$companyId];

    if ($scope === 'company') {
        // all approved portal users in company
    } elseif ($scope === 'buildings') {
        $ids = [];
        foreach ($targets as $t) {
            if (($t['target_type'] ?? '') === 'building') {
                $ids[] = (int)$t['target_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $baseSelect .= "
            INNER JOIN re_leases tl ON tl.tenant_id = tpu.tenant_id AND tl.company_id = tpu.company_id
            INNER JOIN re_units tu ON tu.id = tl.unit_id AND tu.company_id = tl.company_id
        ";
        $where[] = "tl.status IN ('active','renewed')";
        $where[] = 'tu.building_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    } elseif ($scope === 'units') {
        $ids = [];
        foreach ($targets as $t) {
            if (($t['target_type'] ?? '') === 'unit') {
                $ids[] = (int)$t['target_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $baseSelect .= "
            INNER JOIN re_leases tl ON tl.tenant_id = tpu.tenant_id AND tl.company_id = tpu.company_id
            INNER JOIN re_units tu ON tu.id = tl.unit_id AND tu.company_id = tl.company_id
        ";
        $where[] = "tl.status IN ('active','renewed')";
        $where[] = 'tu.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    } elseif ($scope === 'tenants') {
        $tenantIds = [];
        $portalIds = [];
        foreach ($targets as $t) {
            $type = (string)($t['target_type'] ?? '');
            $id = (int)($t['target_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($type === 'tenant_portal_user') {
                $portalIds[] = $id;
            } else {
                $tenantIds[] = $id;
            }
        }
        $tenantIds = array_values(array_unique($tenantIds));
        $portalIds = array_values(array_unique($portalIds));
        if ($tenantIds === [] && $portalIds === []) {
            return [];
        }
        $ors = [];
        if ($tenantIds !== []) {
            $ors[] = 'tpu.tenant_id IN (' . implode(',', array_fill(0, count($tenantIds), '?')) . ')';
            array_push($params, ...$tenantIds);
        }
        if ($portalIds !== []) {
            $ors[] = 'tpu.id IN (' . implode(',', array_fill(0, count($portalIds), '?')) . ')';
            array_push($params, ...$portalIds);
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    } else {
        // group reserved
        return [];
    }

    $st = $conn->prepare($baseSelect . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY tpu.display_name, tpu.email');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Active announcements for a tenant in a building (future Customer API / AI).
 *
 * @return list<array<string,mixed>>
 */
function re_ann_active_for_tenant_context(
    PDO $conn,
    int $companyId,
    int $tenantId,
    ?int $buildingId = null,
    ?int $unitId = null,
    ?int $portalUserId = null
): array {
    re_ann_ensure_schema($conn);
    $st = $conn->prepare("
        SELECT a.*, c.name AS category_name, c.code AS category_code
        FROM re_announcements a
        LEFT JOIN re_announcement_categories c ON c.id = a.category_id AND c.company_id = a.company_id
        WHERE a.company_id = ?
          AND a.status = 'published'
          AND (a.publish_at IS NULL OR a.publish_at <= NOW())
          AND (a.expire_at IS NULL OR a.expire_at >= NOW())
        ORDER BY
          FIELD(a.priority, 'critical', 'high', 'normal', 'information'),
          a.publish_at DESC, a.id DESC
    ");
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return [];
    }

    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tSt = $conn->prepare("
        SELECT * FROM re_announcement_targets
        WHERE company_id = ? AND announcement_id IN ($placeholders)
    ");
    $tSt->execute(array_merge([$companyId], $ids));
    $byAnn = [];
    foreach ($tSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
        $byAnn[(int)$t['announcement_id']][] = $t;
    }

    $out = [];
    foreach ($rows as $row) {
        $scope = (string)$row['target_scope'];
        $targets = $byAnn[(int)$row['id']] ?? [];
        $match = false;
        if ($scope === 'company') {
            $match = true;
        } elseif ($scope === 'buildings' && $buildingId) {
            foreach ($targets as $t) {
                if ($t['target_type'] === 'building' && (int)$t['target_id'] === $buildingId) {
                    $match = true;
                    break;
                }
            }
        } elseif ($scope === 'units' && $unitId) {
            foreach ($targets as $t) {
                if ($t['target_type'] === 'unit' && (int)$t['target_id'] === $unitId) {
                    $match = true;
                    break;
                }
            }
        } elseif ($scope === 'tenants') {
            foreach ($targets as $t) {
                if ($t['target_type'] === 'tenant' && (int)$t['target_id'] === $tenantId) {
                    $match = true;
                    break;
                }
                if ($portalUserId && $t['target_type'] === 'tenant_portal_user' && (int)$t['target_id'] === $portalUserId) {
                    $match = true;
                    break;
                }
            }
        }
        if ($match) {
            $row['lifecycle'] = re_ann_lifecycle_label($row);
            $out[] = $row;
        }
    }
    return $out;
}

function re_ann_media_url(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === '') {
        return null;
    }
    if (strpos($relativePath, '..') !== false) {
        return null;
    }
    return '../../' . ltrim(str_replace('\\', '/', $relativePath), '/');
}

/**
 * @return array{ok:bool,error?:string,path?:string,mime?:string}
 */
function re_ann_store_image(PDO $conn, int $companyId, int $announcementId, array $file, int $maxBytes = 10485760): array
{
    re_ann_ensure_htaccess();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Image upload failed.'];
    }
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Image must be 10 MB or smaller.'];
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Image must be JPG, PNG, GIF, or WEBP.'];
    }
    $ext = $allowed[$mime];
    $dir = re_ann_upload_base() . '/' . $announcementId;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return ['ok' => false, 'error' => 'Cannot create announcement media folder.'];
    }
    @chmod($dir, 0777);
    $name = 'image_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    $rel = 'uploads/realestate/announcements/' . $announcementId . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to save image.'];
    }
    $old = $conn->prepare('SELECT image_path FROM re_announcements WHERE id = ? AND company_id = ?');
    $old->execute([$announcementId, $companyId]);
    $prev = (string)($old->fetchColumn() ?: '');
    $upd = $conn->prepare('UPDATE re_announcements SET image_path = ?, image_mime = ? WHERE id = ? AND company_id = ?');
    $upd->execute([$rel, $mime, $announcementId, $companyId]);
    if ($prev !== '' && $prev !== $rel) {
        $abs = re_ann_project_root() . '/' . ltrim($prev, '/');
        if (is_file($abs) && strpos(str_replace('\\', '/', $prev), 'uploads/realestate/announcements/') === 0) {
            @unlink($abs);
        }
    }
    return ['ok' => true, 'path' => $rel, 'mime' => $mime];
}

/**
 * @return array{ok:bool,error?:string,attachment_id?:int}
 */
function re_ann_store_attachment(PDO $conn, int $companyId, int $announcementId, array $file, ?int $uploadedBy = null, int $maxBytes = 10485760): array
{
    re_ann_ensure_htaccess();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Attachment upload failed.'];
    }
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Attachment must be 10 MB or smaller.'];
    }
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'Attachment type not allowed.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $mimeOk = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
    ];
    if (!in_array($mime, $mimeOk, true)) {
        return ['ok' => false, 'error' => 'Attachment MIME type not allowed.'];
    }
    $dir = re_ann_upload_base() . '/' . $announcementId;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return ['ok' => false, 'error' => 'Cannot create announcement attachment folder.'];
    }
    @chmod($dir, 0777);
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME)) ?: 'file';
    $name = $safe . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    $rel = 'uploads/realestate/announcements/' . $announcementId . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to save attachment.'];
    }
    $ins = $conn->prepare('
        INSERT INTO re_announcement_attachments
            (company_id, announcement_id, file_path, file_name, mime_type, file_size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $companyId,
        $announcementId,
        $rel,
        (string)$file['name'],
        $mime,
        (int)$file['size'],
        $uploadedBy,
    ]);
    return ['ok' => true, 'attachment_id' => (int)$conn->lastInsertId()];
}

/**
 * Promote scheduled rows whose publish_at has arrived.
 */
function re_ann_promote_scheduled(PDO $conn, int $companyId): void
{
    $st = $conn->prepare("
        UPDATE re_announcements
        SET status = 'published', published_at = COALESCE(published_at, NOW())
        WHERE company_id = ?
          AND status = 'scheduled'
          AND publish_at IS NOT NULL
          AND publish_at <= NOW()
    ");
    $st->execute([$companyId]);
}
