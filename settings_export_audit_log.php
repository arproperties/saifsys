<?php
/**
 * CSV Export for Audit Log (Owner only). Hard cap 10,000 rows.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/AuditService.php';

if (!has_role('Owner', $conn)) {
    http_response_code(403);
    die('Access denied. Owner role required.');
}

try {
    $enriched = [
        'company_id' => false,
        'module' => false,
        'source' => false,
        'action_label' => false,
        'object_ref' => false,
    ];
    try {
        $cols = $conn->query('SHOW COLUMNS FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($enriched as $c => $_) {
            $enriched[$c] = in_array($c, $cols, true);
        }
    } catch (Throwable $e) {
        // keep defaults
    }

    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['date_from'])) {
        $where[] = 'al.created_at >= ?';
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'al.created_at <= ?';
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }
    if (!empty($_GET['user_id'])) {
        $where[] = 'al.user_id = ?';
        $params[] = (int)$_GET['user_id'];
    }
    if (!empty($_GET['action'])) {
        $where[] = 'al.action = ?';
        $params[] = $_GET['action'];
    }
    if (!empty($_GET['object_type'])) {
        $where[] = 'al.object_type = ?';
        $params[] = $_GET['object_type'];
    }
    if (isset($_GET['success']) && $_GET['success'] !== '') {
        $where[] = 'al.success = ?';
        $params[] = (int)$_GET['success'];
    }
    if (!empty($_GET['search'])) {
        $where[] = '(al.summary LIKE ? OR al.object_id LIKE ?'
            . ($enriched['object_ref'] ? ' OR al.object_ref LIKE ?' : '')
            . ')';
        $q = '%' . $_GET['search'] . '%';
        $params[] = $q;
        $params[] = $q;
        if ($enriched['object_ref']) {
            $params[] = $q;
        }
    }
    if ($enriched['company_id'] && !empty($_GET['company_id'])) {
        $where[] = 'al.company_id = ?';
        $params[] = (int)$_GET['company_id'];
    }
    if ($enriched['module'] && !empty($_GET['module'])) {
        $where[] = 'al.module = ?';
        $params[] = $_GET['module'];
    }
    if ($enriched['source'] && !empty($_GET['source'])) {
        $where[] = 'al.source = ?';
        $params[] = $_GET['source'];
    }
    if (empty($_GET['include_views'])) {
        $where[] = "al.action <> 'view'";
    }

    $whereClause = implode(' AND ', $where);
    $extra = '';
    if ($enriched['company_id']) {
        $extra .= ', al.company_id';
    }
    if ($enriched['module']) {
        $extra .= ', al.module';
    }
    if ($enriched['source']) {
        $extra .= ', al.source';
    }
    if ($enriched['action_label']) {
        $extra .= ', al.action_label';
    }
    if ($enriched['object_ref']) {
        $extra .= ', al.object_ref';
    }

    $sql = "
        SELECT
            al.created_at, al.user_name, al.user_role, al.action, al.object_type, al.object_id,
            al.summary, al.success, al.ip_address, al.error_message
            $extra
        FROM audit_log al
        WHERE $whereClause
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT 10000
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'audit_log_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Export-Row-Cap: 10000');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'Date/Time',
        'User',
        'Role',
        'Action',
        'Action Label',
        'Company ID',
        'Module',
        'Source',
        'Object Type',
        'Object ID',
        'Object Ref',
        'Summary',
        'Status',
        'IP Address',
        'Error Message',
        'NOTE',
    ]);

    foreach ($records as $i => $record) {
        $note = ($i === 0) ? 'Export capped at 10,000 rows for current filters' : '';
        fputcsv($output, [
            $record['created_at'] ? date('Y-m-d H:i:s', strtotime($record['created_at'])) : '',
            $record['user_name'] ?? 'Not recorded',
            $record['user_role'] ?? '',
            $record['action'] ?? '',
            $record['action_label'] ?? AuditService::actionLabel((string)($record['action'] ?? '')),
            $record['company_id'] ?? '',
            $record['module'] ?? '',
            $record['source'] ?? '',
            $record['object_type'] ?? '',
            $record['object_id'] ?? '',
            $record['object_ref'] ?? '',
            $record['summary'] ?? '',
            ((int)($record['success'] ?? 0) === 1) ? 'Success' : 'Failure',
            $record['ip_address'] ?? '',
            $record['error_message'] ?? '',
            $note,
        ]);
    }

    fclose($output);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    die('Export failed: ' . htmlspecialchars($e->getMessage()));
}
