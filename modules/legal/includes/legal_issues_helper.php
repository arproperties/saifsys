<?php
/**
 * Legal Department - Issues register helpers.
 * Evidence files are stored in uploads/legal_issues (web access denied by .htaccess)
 * and only ever served through legal_issue_file.php, which checks company access.
 */

if (!defined('LEGAL_ISSUE_DIR')) define('LEGAL_ISSUE_DIR', dirname(__DIR__, 3) . '/uploads/legal_issues');
if (!defined('LEGAL_ISSUE_REL')) define('LEGAL_ISSUE_REL', 'uploads/legal_issues');
if (!defined('LEGAL_ISSUE_MAX_BYTES')) define('LEGAL_ISSUE_MAX_BYTES', 10 * 1024 * 1024); // 10 MB per file
if (!defined('LEGAL_ISSUE_MAX_FILES')) define('LEGAL_ISSUE_MAX_FILES', 20);

function legal_issue_types(): array {
    return [
        'illegal_occupancy' => 'Illegal occupancy / overstay',
        'property_damage'   => 'Property damage',
        'unauthorised_use'  => 'Unauthorised use / subletting',
        'nuisance'          => 'Noise / nuisance complaint',
        'safety'            => 'Safety / fire hazard',
        'unpaid_dues'       => 'Unpaid dues / utilities',
        'contract_breach'   => 'Contract breach',
        'dispute'           => 'Dispute with tenant',
        'other'             => 'Other',
    ];
}

function legal_issue_statuses(): array {
    return ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
}

function legal_issue_status_meta(): array {
    // [label, css tone, icon]
    return [
        'open'        => ['Open', 'open', 'bi-exclamation-circle-fill'],
        'in_progress' => ['In progress', 'progress', 'bi-arrow-repeat'],
        'resolved'    => ['Resolved', 'resolved', 'bi-check-circle-fill'],
        'closed'      => ['Closed', 'closed', 'bi-archive-fill'],
    ];
}

function legal_issue_priorities(): array {
    return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
}

/** Extensions accepted as evidence => served MIME type. */
function legal_issue_allowed(): array {
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'pdf' => 'application/pdf'];
}

/** Tables ready? (self-installing, like the journal attachments, so uploads never vanish silently). */
function legal_issues_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if ($conn->query("SHOW TABLES LIKE 're_legal_notif_seen'")->fetchColumn()) return $ready = true;
        $sql = @file_get_contents(dirname(__DIR__, 3) . '/migrations/re_legal_issues.sql');
        if (!$sql) return $ready = false;
        foreach (preg_split('/;\s*(\r?\n|$)/', preg_replace('/^--.*$/m', '', $sql)) as $stmt) {
            if (trim($stmt) !== '') $conn->exec($stmt);
        }
        return $ready = (bool)$conn->query("SHOW TABLES LIKE 're_legal_notif_seen'")->fetchColumn();
    } catch (Throwable $e) {
        return $ready = false;
    }
}

function legal_issue_next_number(PDO $conn, int $companyId): string {
    $prefix = 'ISS-' . date('Y') . '-';
    $st = $conn->prepare("SELECT issue_number FROM re_legal_issues WHERE company_id = ? AND issue_number LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$companyId, $prefix . '%']);
    $last = (string)$st->fetchColumn();
    $n = $last !== '' ? (int)substr($last, strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Companies the user may log issues for (falls back to the current company). */
function legal_issue_companies(PDO $conn, int $userId, int $currentCompanyId): array {
    $out = [];
    try {
        foreach (get_user_companies($conn, $userId) as $c) $out[(int)$c['id']] = $c['name'];
    } catch (Throwable $e) { /* fall through */ }
    if (!isset($out[$currentCompanyId])) {
        $st = $conn->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([$currentCompanyId]);
        $out = [$currentCompanyId => (string)($st->fetchColumn() ?: 'Company #' . $currentCompanyId)] + $out;
    }
    return $out;
}

function legal_issue_can_company(PDO $conn, int $companyId, int $currentCompanyId): bool {
    if ($companyId === $currentCompanyId) return true;
    $uid = (int)current_user_id();
    return $uid > 0 && user_has_company_access($conn, $uid, $companyId);
}

/** The contract that belongs to a unit: the active one, else the most recent. */
function legal_issue_unit_leases(PDO $conn, int $companyId, int $unitId): array {
    $st = $conn->prepare("
        SELECT l.id, l.lease_number, l.status, l.start_date, l.end_date, l.annual_rent, l.monthly_rent,
               t.id AS tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone, t.email
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.company_id = ? AND l.unit_id = ? AND l.deleted_at IS NULL
        ORDER BY (l.status = 'active') DESC, (CURDATE() BETWEEN l.start_date AND l.end_date) DESC, l.start_date DESC
        LIMIT 10");
    $st->execute([$companyId, $unitId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['tenant_label'] = legal_tenant_name($r);
    unset($r);
    return $rows;
}

/** UAE numbers to wa.me digits (05x... -> 9715x..., 00... -> ...). */
function legal_wa_number(?string $phone): string {
    $d = preg_replace('/\D/', '', (string)$phone);
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    elseif (str_starts_with($d, '0')) $d = '971' . substr($d, 1);
    elseif (strlen($d) === 9 && $d[0] === '5') $d = '971' . $d;
    return strlen($d) >= 9 ? $d : '';
}

function legal_issue_log(PDO $conn, int $companyId, int $issueId, string $kind, ?string $note, ?string $old = null, ?string $new = null): void {
    try {
        $conn->prepare("INSERT INTO re_legal_issue_updates (company_id, issue_id, kind, old_status, new_status, note, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)")
             ->execute([$companyId, $issueId, $kind, $old, $new, $note, current_user_id()]);
    } catch (Throwable $e) { /* timeline is best effort */ }
}

/**
 * Save uploaded evidence from $_FILES[$field] (multiple). Returns [saved count, error messages].
 * Files are renamed to random names; the type is checked from the file content, not the browser.
 */
function legal_issue_save_files(PDO $conn, int $companyId, int $issueId, string $field = 'evidence'): array {
    $saved = 0;
    $errors = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return [0, []];

    $st = $conn->prepare("SELECT COUNT(*) FROM re_legal_issue_files WHERE issue_id = ? AND company_id = ?");
    $st->execute([$issueId, $companyId]);
    $have = (int)$st->fetchColumn();

    $dir = LEGAL_ISSUE_DIR . '/' . $companyId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [0, ['Upload folder could not be created.']];
    $guard = LEGAL_ISSUE_DIR . '/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");

    $allowed = legal_issue_allowed();
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $f = $_FILES[$field];
    foreach ($f['name'] as $i => $orig) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $orig = basename((string)$orig);
        if ($f['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = $orig . ': upload failed.'; continue; }
        if ($have + $saved >= LEGAL_ISSUE_MAX_FILES) { $errors[] = 'Only ' . LEGAL_ISSUE_MAX_FILES . ' files per issue.'; break; }
        if ($f['size'][$i] > LEGAL_ISSUE_MAX_BYTES) { $errors[] = $orig . ': larger than 10 MB.'; continue; }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) { $errors[] = $orig . ': only photos (JPG, PNG, WEBP, GIF) or PDF.'; continue; }
        $real = $finfo ? (string)finfo_file($finfo, $f['tmp_name'][$i]) : $allowed[$ext];
        if ($real !== $allowed[$ext] && !($ext === 'jpg' && $real === 'image/jpeg')) {
            $errors[] = $orig . ': the file content is not a ' . strtoupper($ext) . '.';
            continue;
        }
        $name = date('Ymd') . '_' . $issueId . '_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!move_uploaded_file($f['tmp_name'][$i], $dir . '/' . $name)) { $errors[] = $orig . ': could not be saved.'; continue; }
        $conn->prepare("INSERT INTO re_legal_issue_files (company_id, issue_id, file_name, file_path, mime_type, file_size, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)")
             ->execute([$companyId, $issueId, mb_substr($orig, 0, 255), LEGAL_ISSUE_REL . '/' . $companyId . '/' . $name,
                        $allowed[$ext], (int)$f['size'][$i], current_user_id()]);
        $saved++;
    }
    return [$saved, $errors];
}

function legal_issue_files(PDO $conn, int $companyId, int $issueId): array {
    $st = $conn->prepare("SELECT * FROM re_legal_issue_files WHERE issue_id = ? AND company_id = ? ORDER BY id");
    $st->execute([$issueId, $companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
