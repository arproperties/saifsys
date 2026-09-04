<?php
/**
 * ARS Booking Activity Center — event store helpers (Phase 1 writers + Phase 1B UI).
 * Versioned migrations only — no runtime CREATE/ALTER.
 */

function ars_booking_activities_table_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query('SELECT 1 FROM ars_booking_activities LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function ars_booking_activities_has_column(PDO $conn, string $column): bool {
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM ars_booking_activities LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$column] = false;
    }
    return $cache[$column];
}

/** @return list<string> */
function ars_activity_categories(): array {
    return [
        'operational', 'financial', 'payment', 'accounting', 'housekeeping',
        'maintenance', 'notes', 'documents', 'system',
    ];
}

/**
 * Safe activity writer. Never throws to callers — failures are logged only.
 * Validates booking belongs to company_id. Supports optional dedupe_key.
 *
 * @param array $payload Keys: company_id, booking_id, event_type, title; optional others
 */
function ars_booking_activity_log(PDO $conn, array $payload): ?int {
    if (!ars_booking_activities_table_ready($conn)) {
        return null;
    }

    try {
        $companyId = (int)($payload['company_id'] ?? 0);
        $bookingId = (int)($payload['booking_id'] ?? 0);
        $eventType = trim((string)($payload['event_type'] ?? ''));
        $title = trim((string)($payload['title'] ?? ''));
        if ($companyId <= 0 || $bookingId <= 0 || $eventType === '' || $title === '') {
            return null;
        }

        // Prevent orphaned events — booking must exist in company scope.
        $chk = $conn->prepare('SELECT booking_number FROM ars_bookings WHERE id = ? AND company_id = ? LIMIT 1');
        $chk->execute([$bookingId, $companyId]);
        $bnRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$bnRow) {
            error_log('[ARS Activity] Rejected orphan activity booking_id=' . $bookingId . ' company_id=' . $companyId);
            return null;
        }
        if (empty($payload['booking_number'])) {
            $payload['booking_number'] = $bnRow['booking_number'] ?? null;
        }

        $category = (string)($payload['event_category'] ?? 'system');
        if (!in_array($category, ars_activity_categories(), true)) {
            $category = 'system';
        }

        $source = (string)($payload['source'] ?? 'system');
        $allowedSource = ['user', 'system', 'guest', 'backfill', 'import'];
        if (!in_array($source, $allowedSource, true)) {
            $source = 'system';
        }

        $meta = $payload['meta'] ?? ($payload['meta_json'] ?? null);
        $metaJson = null;
        if (is_array($meta)) {
            // Strip obviously sensitive keys from UI-facing metadata.
            unset($meta['password'], $meta['token'], $meta['secret'], $meta['raw_payload'], $meta['raw_payload_json']);
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($meta) && $meta !== '') {
            $metaJson = $meta;
        }

        $dedupeKey = isset($payload['dedupe_key']) ? trim((string)$payload['dedupe_key']) : null;
        if ($dedupeKey === '') {
            $dedupeKey = null;
        }

        $hasSource = ars_booking_activities_has_column($conn, 'source');
        $hasBackfill = ars_booking_activities_has_column($conn, 'is_backfill');
        $hasDedupe = ars_booking_activities_has_column($conn, 'dedupe_key');

        if ($hasDedupe && $dedupeKey !== null) {
            $dup = $conn->prepare('SELECT id FROM ars_booking_activities WHERE booking_id = ? AND dedupe_key = ? LIMIT 1');
            $dup->execute([$bookingId, $dedupeKey]);
            $existing = $dup->fetchColumn();
            if ($existing) {
                return (int)$existing;
            }
        }

        $cols = [
            'company_id', 'booking_id', 'booking_number', 'event_category', 'event_type', 'title', 'description',
            'previous_value', 'new_value', 'related_entity_type', 'related_entity_id', 'related_document_number',
            'related_journal_id', 'status',
        ];
        $vals = [
            $companyId,
            $bookingId,
            $payload['booking_number'] ?? null,
            $category,
            $eventType,
            $title,
            $payload['description'] ?? null,
            isset($payload['previous_value']) ? (is_scalar($payload['previous_value']) ? (string)$payload['previous_value'] : json_encode($payload['previous_value'])) : null,
            isset($payload['new_value']) ? (is_scalar($payload['new_value']) ? (string)$payload['new_value'] : json_encode($payload['new_value'])) : null,
            $payload['related_entity_type'] ?? null,
            isset($payload['related_entity_id']) ? (int)$payload['related_entity_id'] : null,
            $payload['related_document_number'] ?? null,
            isset($payload['related_journal_id']) ? (int)$payload['related_journal_id'] : null,
            $payload['status'] ?? null,
        ];

        if ($hasSource) {
            $cols[] = 'source';
            $vals[] = $source;
        }
        if ($hasBackfill) {
            $cols[] = 'is_backfill';
            $vals[] = !empty($payload['is_backfill']) ? 1 : 0;
        }
        if ($hasDedupe) {
            $cols[] = 'dedupe_key';
            $vals[] = $dedupeKey;
        }

        $cols[] = 'meta_json';
        $vals[] = $metaJson;
        $cols[] = 'created_by';
        $vals[] = isset($payload['created_by']) ? (int)$payload['created_by'] : null;

        if (!empty($payload['created_at']) && is_string($payload['created_at'])) {
            $cols[] = 'created_at';
            $vals[] = $payload['created_at'];
        }

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO ars_booking_activities (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
        $stmt = $conn->prepare($sql);
        $stmt->execute($vals);

        return (int)$conn->lastInsertId();
    } catch (Throwable $e) {
        error_log('[ARS Activity] log failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * @return array{items:list<array>,total:int,page:int,per_page:int,has_more:bool}
 */
function ars_booking_activities_fetch(
    PDO $conn,
    int $companyId,
    int $bookingId,
    string $filter = 'all',
    int $page = 1,
    int $perPage = 25
): array {
    $page = max(1, $page);
    $perPage = max(5, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    if (!ars_booking_activities_table_ready($conn)) {
        return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'has_more' => false];
    }

    $where = ['a.company_id = ?', 'a.booking_id = ?'];
    $params = [$companyId, $bookingId];

    $filter = strtolower(trim($filter));
    if ($filter === 'payments') {
        $filter = 'payment';
    }
    if ($filter !== 'all' && in_array($filter, ars_activity_categories(), true)) {
        $where[] = 'a.event_category = ?';
        $params[] = $filter;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM ars_booking_activities a WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT a.*, u.fullname AS created_by_name, u.username AS created_by_username
        FROM ars_booking_activities a
        LEFT JOIN `user` u ON u.id = a.created_by
        WHERE {$whereSql}
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = ars_activity_present_row($row);
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'has_more' => ($offset + count($items)) < $total,
    ];
}

function ars_company_activities_fetch(
    PDO $conn,
    int $companyId,
    string $filter = 'all',
    int $page = 1,
    int $perPage = 40
): array {
    $page = max(1, $page);
    $perPage = max(5, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    if (!ars_booking_activities_table_ready($conn)) {
        return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'has_more' => false];
    }

    $where = ['a.company_id = ?'];
    $params = [$companyId];

    $filter = strtolower(trim($filter));
    if ($filter === 'payments') {
        $filter = 'payment';
    }
    if ($filter !== 'all' && in_array($filter, ars_activity_categories(), true)) {
        $where[] = 'a.event_category = ?';
        $params[] = $filter;
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM ars_booking_activities a WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT a.*, u.fullname AS created_by_name, u.username AS created_by_username
        FROM ars_booking_activities a
        LEFT JOIN `user` u ON u.id = a.created_by
        WHERE {$whereSql}
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = ars_activity_present_row($row);
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'has_more' => ($offset + count($items)) < $total,
    ];
}

/**
 * Presentational row for UI/API (escaped by caller with h()).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function ars_activity_present_row(array $row): array {
    $meta = null;
    if (!empty($row['meta_json'])) {
        $decoded = json_decode((string)$row['meta_json'], true);
        if (is_array($decoded)) {
            unset($decoded['password'], $decoded['token'], $decoded['secret'], $decoded['raw_payload'], $decoded['raw_payload_json']);
            $meta = $decoded;
        }
    }

    $deepLink = ars_activity_deep_link($row);
    $userLabel = trim((string)($row['created_by_name'] ?? ''));
    if ($userLabel === '') {
        $userLabel = trim((string)($row['created_by_username'] ?? ''));
    }
    if ($userLabel === '') {
        $userLabel = !empty($row['is_backfill']) || ($row['source'] ?? '') === 'backfill' ? 'System (historical)' : 'System';
    }

    $prev = $row['previous_value'] !== null ? (string)$row['previous_value'] : null;
    $next = $row['new_value'] !== null ? (string)$row['new_value'] : null;
    // Hide raw JSON blobs from operator Timeline (e.g. early stay_dates_corrected rows).
    if ($prev !== null && ($prev === '' || $prev[0] === '{' || $prev[0] === '[')) {
        $prev = null;
    }
    if ($next !== null && ($next === '' || $next[0] === '{' || $next[0] === '[')) {
        $next = null;
    }

    return [
        'id' => (int)$row['id'],
        'booking_id' => isset($row['booking_id']) ? (int)$row['booking_id'] : null,
        'booking_number' => $row['booking_number'] !== null ? (string)$row['booking_number'] : null,
        'event_category' => (string)$row['event_category'],
        'event_type' => (string)$row['event_type'],
        'title' => (string)$row['title'],
        'description' => $row['description'] !== null ? (string)$row['description'] : null,
        'previous_value' => $prev,
        'new_value' => $next,
        'related_entity_type' => $row['related_entity_type'] !== null ? (string)$row['related_entity_type'] : null,
        'related_entity_id' => isset($row['related_entity_id']) ? (int)$row['related_entity_id'] : null,
        'related_document_number' => $row['related_document_number'] !== null ? (string)$row['related_document_number'] : null,
        'related_journal_id' => isset($row['related_journal_id']) ? (int)$row['related_journal_id'] : null,
        'status' => $row['status'] !== null ? (string)$row['status'] : null,
        'source' => (string)($row['source'] ?? 'system'),
        'is_backfill' => !empty($row['is_backfill']),
        'created_by' => isset($row['created_by']) ? (int)$row['created_by'] : null,
        'created_by_name' => $userLabel,
        'created_at' => (string)$row['created_at'],
        'icon' => ars_activity_category_icon((string)$row['event_category']),
        'deep_link' => $deepLink,
        'meta' => $meta,
    ];
}

function ars_activity_category_icon(string $category): string {
    return match ($category) {
        'operational' => 'bi-arrow-left-right',
        'financial' => 'bi-lock',
        'payment' => 'bi-cash-coin',
        'accounting' => 'bi-journal-check',
        'housekeeping' => 'bi-broom',
        'maintenance' => 'bi-wrench',
        'notes' => 'bi-sticky',
        'documents' => 'bi-file-earmark-text',
        default => 'bi-activity',
    };
}

/**
 * Build a relative deep link only when a known safe destination exists.
 *
 * @param array<string,mixed> $row
 */
function ars_activity_deep_link(array $row): ?array {
    $type = (string)($row['related_entity_type'] ?? '');
    $id = (int)($row['related_entity_id'] ?? 0);
    $journalId = (int)($row['related_journal_id'] ?? 0);
    $bookingId = (int)($row['booking_id'] ?? 0);

    if ($journalId > 0 || $type === 're_journal_header') {
        $jid = $journalId > 0 ? $journalId : $id;
        if ($jid > 0) {
            $companyId = (int)($row['company_id'] ?? 0);
            $url = '../realestate/accounting/journal_entry_view.php?id=' . $jid;
            if ($companyId > 0) {
                $url .= '&company_id=' . $companyId;
            }
            return [
                'label' => 'Open journal',
                'url' => $url,
            ];
        }
    }

    return match ($type) {
        'ars_booking', '' => $bookingId > 0 ? [
            'label' => 'Booking',
            'url' => 'booking_view.php?id=' . $bookingId . '#activity-center',
        ] : null,
        'ars_guest' => $id > 0 ? ['label' => 'Guest profile', 'url' => 'guest_view.php?id=' . $id] : null,
        're_unit' => $id > 0 ? ['label' => 'Unit profile', 'url' => 'unit_profile.php?id=' . $id] : null,
        'ars_booking_payment' => $bookingId > 0 ? [
            'label' => 'Payments on booking',
            'url' => 'booking_view.php?id=' . $bookingId . '#payments',
        ] : null,
        'ars_booking_charge' => $bookingId > 0 ? [
            'label' => 'Charges on booking',
            'url' => 'booking_view.php?id=' . $bookingId . '#charges',
        ] : null,
        'housekeeping_order', 'make_order' => (static function () use ($row, $id): array {
            $meta = null;
            if (!empty($row['meta_json'])) {
                $decoded = json_decode((string)$row['meta_json'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $serviceDate = '';
            if (is_array($meta)) {
                $serviceDate = (string)($meta['service_date'] ?? ($meta['cleaning']['service_date'] ?? ''));
            }
            $url = 'housekeeping.php';
            $qs = [];
            if ($id > 0) {
                $qs['highlight'] = (string)$id;
            }
            if ($serviceDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate)) {
                $qs['date'] = $serviceDate;
            }
            if ($qs) {
                $url .= '?' . http_build_query($qs);
            }
            return [
                'label' => $id > 0 ? ('Open WO #' . $id) : 'Housekeeping',
                'url' => $url,
            ];
        })(),
        're_maintenance_request' => $id > 0 ? [
            'label' => 'Maintenance',
            'url' => 'maintenance.php?highlight=' . $id,
        ] : ['label' => 'Maintenance', 'url' => 'maintenance.php'],
        'ars_booking_document' => $bookingId > 0 ? [
            'label' => 'Documents',
            'url' => 'booking_view.php?id=' . $bookingId . '#documents',
        ] : null,
        'ars_financial_document' => $id > 0 ? [
            'label' => 'Financial document',
            'url' => 'financial_document_view.php?id=' . $id,
        ] : null,
        'ars_security_deposit' => $bookingId > 0 ? [
            'label' => 'Deposit on booking',
            'url' => 'booking_view.php?id=' . $bookingId . '#payments',
        ] : ($id > 0 ? [
            'label' => 'Security deposit',
            'url' => 'financial_document_view.php?deposit_id=' . $id,
        ] : null),
        'ars_refund' => $id > 0 ? [
            'label' => 'Refund',
            'url' => 'financial_document_view.php?refund_id=' . $id,
        ] : null,
        'ars_credit_note', 'ars_adjustment' => $id > 0 ? [
            'label' => 'Financial document',
            'url' => 'financial_document_view.php?id=' . $id,
        ] : null,
        default => null,
    };
}

/**
 * Log guest profile changes against open bookings for that guest (company-scoped).
 */
function ars_activity_log_guest_change(PDO $conn, int $companyId, int $guestId, string $summary, ?int $userId = null): void {
    try {
        $stmt = $conn->prepare("
            SELECT id, booking_number FROM ars_bookings
            WHERE company_id = ? AND guest_id = ?
              AND status IN ('pending','confirmed','checked_in','checked_out')
            ORDER BY id DESC
            LIMIT 20
        ");
        $stmt->execute([$companyId, $guestId]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($bookings as $b) {
            ars_booking_activity_log($conn, [
                'company_id' => $companyId,
                'booking_id' => (int)$b['id'],
                'booking_number' => $b['booking_number'] ?? null,
                'event_category' => 'operational',
                'event_type' => 'guest_information_changed',
                'title' => 'Guest information updated',
                'description' => $summary,
                'related_entity_type' => 'ars_guest',
                'related_entity_id' => $guestId,
                'source' => 'user',
                'created_by' => $userId,
                'dedupe_key' => 'guest_change:' . $guestId . ':' . (int)$b['id'] . ':' . date('YmdHi'),
            ]);
        }
    } catch (Throwable $e) {
        error_log('[ARS Activity] guest change log failed: ' . $e->getMessage());
    }
}
