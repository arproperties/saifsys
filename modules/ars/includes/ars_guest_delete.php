<?php
/**
 * Guarded guest deletion — a guest row may only be removed while it carries no
 * operational or financial history. Anything with money or a stay attached must
 * be cancelled/reversed through its own workflow first.
 */

require_once __DIR__ . '/ars_permissions.php';
require_once dirname(__DIR__, 3) . '/includes/AuditService.php';

if (!function_exists('ars_guest_delete_table_exists')) {
    function ars_guest_delete_table_exists(PDO $conn, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $stmt = $conn->query("SHOW TABLES LIKE " . $conn->quote($table));
            $cache[$table] = (bool)($stmt && $stmt->fetchColumn());
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

/**
 * May the current user delete guests? Mirrors ars_require_booking_action:
 * ARS Core department, or module access with no department flags (owner/admin).
 */
function ars_user_can_delete_guest(PDO $conn): bool
{
    $core = ars_user_has_core($conn);
    $ops = ars_user_has_ops($conn);
    return $core || (!$core && !$ops);
}

/**
 * Human-readable reasons this guest cannot be deleted. Empty array = deletable.
 *
 * @return list<string>
 */
function ars_guest_delete_blockers(PDO $conn, int $companyId, int $guestId): array
{
    $checks = [
        ['ars_bookings', 'guest_id = ? AND company_id = ?', [$guestId, $companyId], '%d booking(s) on record — cancel and delete those first'],
        ['ars_financial_documents', 'guest_id = ? AND company_id = ?', [$guestId, $companyId], '%d financial document(s) issued to this guest'],
        ['ars_guest_credits', 'guest_id = ? AND company_id = ?', [$guestId, $companyId], '%d guest credit(s) on the ledger'],
        ['ars_unit_occupancies', 'guest_id = ? AND company_id = ?', [$guestId, $companyId], '%d unit occupancy record(s) linked'],
        ['portal_users', 'guest_id = ? AND company_id = ?', [$guestId, $companyId], '%d guest portal account(s) linked'],
    ];

    $blockers = [];
    foreach ($checks as [$table, $where, $params, $message]) {
        if (!ars_guest_delete_table_exists($conn, $table)) {
            continue;
        }
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
            $stmt->execute($params);
            $count = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            // A missing column on this deployment cannot prove the guest is clean.
            $blockers[] = 'Could not verify ' . $table . ' — deletion blocked for safety.';
            continue;
        }
        if ($count > 0) {
            $blockers[] = sprintf($message, $count);
        }
    }
    return $blockers;
}

/**
 * Hard-delete a guest after re-checking the guards. Returns an error string on
 * refusal, or null on success.
 */
function ars_guest_delete(PDO $conn, int $companyId, int $guestId, ?int $userId): ?string
{
    $stmt = $conn->prepare('SELECT * FROM ars_guests WHERE id = ? AND company_id = ?');
    $stmt->execute([$guestId, $companyId]);
    $guest = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$guest) {
        return 'Guest not found.';
    }

    $blockers = ars_guest_delete_blockers($conn, $companyId, $guestId);
    if ($blockers) {
        return 'This guest cannot be deleted: ' . implode('; ', $blockers) . '.';
    }

    // Guest-scoped rows that carry no financial meaning once the guest is gone.
    $sweep = [
        'ars_guest_notifications' => 'guest_id = ? AND company_id = ?',
        'customer_device_tokens' => 'guest_id = ? AND company_id = ?',
        'customer_push_logs' => 'guest_id = ? AND company_id = ?',
        'ars_guest_attachments' => 'guest_id = ? AND company_id = ?',
    ];

    // Uploaded ID scans go with the profile. Paths are read before the rows are
    // swept; the files themselves are removed only after the delete commits.
    $docPaths = [];
    if (ars_guest_delete_table_exists($conn, 'ars_guest_attachments')) {
        try {
            $ds = $conn->prepare('SELECT relative_path FROM ars_guest_attachments WHERE guest_id = ? AND company_id = ?');
            $ds->execute([$guestId, $companyId]);
            $docPaths = $ds->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            $docPaths = [];
        }
    }

    $conn->beginTransaction();
    try {
        foreach ($sweep as $table => $where) {
            if (!ars_guest_delete_table_exists($conn, $table)) {
                continue;
            }
            try {
                $conn->prepare("DELETE FROM `{$table}` WHERE {$where}")->execute([$guestId, $companyId]);
            } catch (PDOException $e) {
                // Optional satellite table shaped differently here; leave it alone.
            }
        }
        $del = $conn->prepare('DELETE FROM ars_guests WHERE id = ? AND company_id = ?');
        $del->execute([$guestId, $companyId]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Guest row was not removed.');
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return 'Failed to delete guest: ' . $e->getMessage();
    }

    foreach ($docPaths as $rel) {
        $abs = dirname(__DIR__, 3) . '/' . ltrim((string)$rel, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    $guestName = trim((string)($guest['first_name'] ?? '') . ' ' . (string)($guest['last_name'] ?? ''));
    try {
        AuditService::logEvent([
            'action' => 'guest_deleted',
            'module' => 'ars',
            'company_id' => $companyId,
            'object_type' => 'ars_guests',
            'object_id' => (string)$guestId,
            'object_ref' => $guestName !== '' ? $guestName : ('Guest #' . $guestId),
            'summary' => 'Deleted guest ' . ($guestName !== '' ? $guestName : ('#' . $guestId)) . ' (no bookings or financial history).',
            'old_data' => $guest,
            'user_id' => $userId,
            'source' => 'user',
            'success' => true,
        ]);
    } catch (Throwable $ignored) {
    }

    return null;
}
