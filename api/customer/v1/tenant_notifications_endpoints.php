<?php
/**
 * Tenant in-app notifications — mobile API endpoints.
 *
 * GET  tenant/notifications              -> list (optionally ?lease_id, ?only_unread, ?limit, ?before_id)
 * GET  tenant/notifications/unread-count -> { unread: N }
 * POST tenant/notifications/{id}/read    -> mark one read
 * POST tenant/notifications/read-all     -> mark all read
 *
 * All endpoints require tenant Bearer auth and only ever return/affect rows that
 * belong to the authenticated tenant (scoped by company_id + tenant identity).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/tenant_notifications.php';

/**
 * Build the SQL WHERE fragment + params that scope rows to the authenticated tenant.
 *
 * @param array<string,mixed> $ctx
 * @return array{0:string,1:list<mixed>}
 */
function customer_api_tenant_notifications_scope(array $ctx): array {
    $companyId = (int)($ctx['company_id'] ?? 0);
    $tenantId = (int)($ctx['tenant_id'] ?? 0);
    $tpuId = (int)($ctx['tenant_portal_user_id'] ?? 0);

    $ors = [];
    $params = [$companyId];
    if ($tenantId > 0) {
        $ors[] = 'tenant_id = ?';
        $params[] = $tenantId;
    }
    if ($tpuId > 0) {
        $ors[] = 'tenant_portal_user_id = ?';
        $params[] = $tpuId;
    }
    if ($ors === []) {
        // No identity to match — force an impossible condition.
        return ['company_id = ? AND 1 = 0', [$companyId]];
    }
    $where = 'company_id = ? AND (' . implode(' OR ', $ors) . ')';
    return [$where, $params];
}

function customer_api_tenant_handle_notifications_list(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!tenant_notifications_table_ready($conn)) {
        customer_api_send_ok(['notifications' => [], 'unread' => 0, 'configured' => false]);
    }

    [$where, $params] = customer_api_tenant_notifications_scope($ctx);

    // Optional lease scope (header or query) — only narrows, never widens.
    $leaseFilter = 0;
    $headerLease = customer_api_tenant_lease_header_value();
    if ($headerLease !== null && $headerLease > 0) {
        $leaseFilter = $headerLease;
    }
    $qLease = (int)($_GET['lease_id'] ?? 0);
    if ($qLease > 0) {
        $leaseFilter = $qLease;
    }
    if ($leaseFilter > 0) {
        $where .= ' AND lease_id = ?';
        $params[] = $leaseFilter;
    }

    if (!empty($_GET['only_unread'])) {
        $where .= ' AND is_read = 0';
    }

    $beforeId = (int)($_GET['before_id'] ?? 0);
    if ($beforeId > 0) {
        $where .= ' AND id < ?';
        $params[] = $beforeId;
    }

    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit <= 0 || $limit > 100) {
        $limit = 50;
    }

    $sql = "
        SELECT id, type, entity_type, entity_id, lease_id, title, body, is_read, read_at, created_at
        FROM re_tenant_notifications
        WHERE $where
        ORDER BY id DESC
        LIMIT $limit
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = customer_api_tenant_notification_format($row);
    }

    // Unread count across the full tenant scope (independent of paging/filters).
    [$uWhere, $uParams] = customer_api_tenant_notifications_scope($ctx);
    $uStmt = $conn->prepare("SELECT COUNT(*) FROM re_tenant_notifications WHERE $uWhere AND is_read = 0");
    $uStmt->execute($uParams);
    $unread = (int)$uStmt->fetchColumn();

    customer_api_send_ok([
        'notifications' => $items,
        'unread' => $unread,
        'configured' => true,
    ]);
}

function customer_api_tenant_handle_notifications_unread_count(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!tenant_notifications_table_ready($conn)) {
        customer_api_send_ok(['unread' => 0]);
    }
    [$where, $params] = customer_api_tenant_notifications_scope($ctx);
    $stmt = $conn->prepare("SELECT COUNT(*) FROM re_tenant_notifications WHERE $where AND is_read = 0");
    $stmt->execute($params);
    customer_api_send_ok(['unread' => (int)$stmt->fetchColumn()]);
}

function customer_api_tenant_handle_notification_mark_read(PDO $conn, int $notificationId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!tenant_notifications_table_ready($conn)) {
        customer_api_send_error('not_found', 'Notification not found', 404);
    }
    if ($notificationId <= 0) {
        customer_api_send_error('validation_error', 'Invalid notification id', 400);
    }
    [$where, $params] = customer_api_tenant_notifications_scope($ctx);
    $sql = "UPDATE re_tenant_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND $where";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$notificationId], $params));
    if ($stmt->rowCount() === 0) {
        // Either it does not exist for this tenant, or it was already read.
        $chk = $conn->prepare("SELECT COUNT(*) FROM re_tenant_notifications WHERE id = ? AND $where");
        $chk->execute(array_merge([$notificationId], $params));
        if ((int)$chk->fetchColumn() === 0) {
            customer_api_send_error('not_found', 'Notification not found', 404);
        }
    }

    [$uWhere, $uParams] = customer_api_tenant_notifications_scope($ctx);
    $uStmt = $conn->prepare("SELECT COUNT(*) FROM re_tenant_notifications WHERE $uWhere AND is_read = 0");
    $uStmt->execute($uParams);
    customer_api_send_ok(['unread' => (int)$uStmt->fetchColumn()]);
}

function customer_api_tenant_handle_notifications_mark_all_read(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!tenant_notifications_table_ready($conn)) {
        customer_api_send_ok(['unread' => 0, 'updated' => 0]);
    }
    [$where, $params] = customer_api_tenant_notifications_scope($ctx);
    $stmt = $conn->prepare("UPDATE re_tenant_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE $where AND is_read = 0");
    $stmt->execute($params);
    customer_api_send_ok(['unread' => 0, 'updated' => $stmt->rowCount()]);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function customer_api_tenant_notification_format(array $row): array {
    return [
        'id' => (int)$row['id'],
        'type' => (string)($row['type'] ?? ''),
        'entity_type' => $row['entity_type'] !== null ? (string)$row['entity_type'] : null,
        'entity_id' => $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
        'lease_id' => $row['lease_id'] !== null ? (int)$row['lease_id'] : null,
        'title' => (string)($row['title'] ?? ''),
        'body' => $row['body'] !== null ? (string)$row['body'] : null,
        'is_read' => ((int)($row['is_read'] ?? 0)) === 1,
        'read_at' => $row['read_at'] !== null ? (string)$row['read_at'] : null,
        'created_at' => (string)($row['created_at'] ?? ''),
    ];
}
