<?php
/**
 * AJAX Handler for Audit Log Data (Owner-friendly History)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/AuditService.php';

header('Content-Type: application/json');

if (!has_role('Owner', $conn)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Owner role required.']);
    exit;
}

$action = $_GET['action'] ?? 'fetch';
$enriched = AuditService::detectEnrichedColumns($conn);

try {
    if ($action === 'filter_options') {
        $users = $conn->query("
            SELECT DISTINCT al.user_id, u.username, u.fullname
            FROM audit_log al
            LEFT JOIN user u ON u.id = al.user_id
            WHERE al.user_id IS NOT NULL
            ORDER BY COALESCE(u.fullname, u.username)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $companies = [];
        try {
            $companies = $conn->query("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $companies = $conn->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        }

        $modules = [
            ['key' => 'cleaning', 'label' => 'Cleaning'],
            ['key' => 'realestate', 'label' => 'Real Estate'],
            ['key' => 'construction', 'label' => 'Construction'],
            ['key' => 'hr', 'label' => 'HR'],
            ['key' => 'inventory', 'label' => 'Inventory'],
            ['key' => 'legal', 'label' => 'Legal'],
            ['key' => 'ars', 'label' => 'ARS'],
            ['key' => 'admin', 'label' => 'Administration'],
            ['key' => 'auth', 'label' => 'Sign-in'],
            ['key' => 'accounts', 'label' => 'Accounts'],
        ];

        $actions = $conn->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
        $actionOptions = [];
        foreach ($actions as $a) {
            $actionOptions[] = [
                'value' => $a,
                'label' => AuditService::actionLabel((string)$a),
            ];
        }

        echo json_encode([
            'success' => true,
            'users' => $users,
            'companies' => $companies,
            'modules' => $modules,
            'actions' => $actionOptions,
            'enriched' => $enriched,
            'schema_ready' => !empty($enriched['company_id']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
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
        $where[] = '(al.summary LIKE ? OR al.object_id LIKE ?' .
            ($enriched['object_ref'] ? ' OR al.object_ref LIKE ?' : '') . ')';
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
        if ($_GET['source'] === 'automated') {
            $where[] = "al.source IN ('api','system','job')";
        } else {
            $where[] = 'al.source = ?';
            $params[] = $_GET['source'];
        }
    }
    // Hide routine view noise by default unless include_views=1
    if (empty($_GET['include_views'])) {
        $where[] = "al.action <> 'view'";
    }

    $whereClause = implode(' AND ', $where);

    // Keyset pagination when cursor_id provided; else page offset for compatibility
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $cursorId = isset($_GET['cursor_id']) ? (int)$_GET['cursor_id'] : 0;
    $page = max(1, (int)($_GET['page'] ?? 1));

    $countSql = "SELECT COUNT(*) FROM audit_log al WHERE $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $successCount = 0;
    $failCount = 0;
    $autoCount = 0;
    try {
        $sumSql = "SELECT
            SUM(CASE WHEN al.success = 1 THEN 1 ELSE 0 END) AS ok_n,
            SUM(CASE WHEN al.success = 0 THEN 1 ELSE 0 END) AS fail_n"
            . ($enriched['source']
                ? ", SUM(CASE WHEN al.source IN ('api','system','job') THEN 1 ELSE 0 END) AS auto_n"
                : ", 0 AS auto_n")
            . " FROM audit_log al WHERE $whereClause";
        $sumStmt = $conn->prepare($sumSql);
        $sumStmt->execute($params);
        $sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $successCount = (int)($sum['ok_n'] ?? 0);
        $failCount = (int)($sum['fail_n'] ?? 0);
        $autoCount = (int)($sum['auto_n'] ?? 0);
    } catch (Throwable $e) {
        // fail-soft KPIs
    }

    $extraSelect = '';
    if ($enriched['company_id']) {
        $extraSelect .= ', al.company_id, c.name AS company_name';
    }
    if ($enriched['module']) {
        $extraSelect .= ', al.module';
    }
    if ($enriched['source']) {
        $extraSelect .= ', al.source';
    }
    if ($enriched['object_ref']) {
        $extraSelect .= ', al.object_ref';
    }
    if ($enriched['action_label']) {
        $extraSelect .= ', al.action_label';
    }

    $joinCompany = $enriched['company_id'] ? 'LEFT JOIN companies c ON c.id = al.company_id' : '';

    $fetchWhere = $whereClause;
    $fetchParams = $params;
    if ($cursorId > 0) {
        $fetchWhere .= ' AND al.id < ?';
        $fetchParams[] = $cursorId;
        $limitSql = 'LIMIT ' . (int)$perPage;
        $offsetSql = '';
    } else {
        $offset = ($page - 1) * $perPage;
        $limitSql = 'LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
        $offsetSql = '';
    }

    $sql = "
        SELECT
            al.id, al.user_id,
            COALESCE(NULLIF(al.user_name, ''), u.fullname, u.username, u.email) AS user_name,
            al.user_role, al.action, al.object_type, al.object_id,
            al.summary, al.old_data, al.new_data, al.ip_address, al.user_agent, al.success,
            al.error_message, al.created_at
            {$extraSelect}
        FROM audit_log al
        LEFT JOIN `user` u ON u.id = al.user_id
        {$joinCompany}
        WHERE {$fetchWhere}
        ORDER BY al.id DESC
        {$limitSql}
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($fetchParams);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(static function ($record) {
        $record['action_label'] = $record['action_label']
            ?? AuditService::actionLabel((string)$record['action']);
        $record['module_label'] = AuditService::moduleLabel($record['module'] ?? null);
        $record['source_label'] = [
            'user' => 'User',
            'api' => 'API',
            'system' => 'System',
            'job' => 'Scheduled job',
        ][$record['source'] ?? 'user'] ?? 'User';
        if (!empty($record['old_data']) && strlen($record['old_data']) > 2000) {
            $record['old_data_preview'] = substr($record['old_data'], 0, 2000) . '...';
        }
        if (!empty($record['new_data']) && strlen($record['new_data']) > 2000) {
            $record['new_data_preview'] = substr($record['new_data'], 0, 2000) . '...';
        }
        return $record;
    }, $records);

    $nextCursor = null;
    if ($records) {
        $nextCursor = (int)$records[count($records) - 1]['id'];
    }

    echo json_encode([
        'success' => true,
        'records' => $formatted,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
        'next_cursor_id' => $nextCursor,
        'enriched' => $enriched,
        'export_cap' => 10000,
        'stats' => [
            'total' => $total,
            'success' => $successCount,
            'failure' => $failCount,
            'automated' => $autoCount,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
