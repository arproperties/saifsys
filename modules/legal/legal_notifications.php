<?php
/**
 * Legal - notification bell feed (JSON).
 *   GET               -> latest items + unread count
 *   POST action=seen  -> mark everything up to now as seen for this user
 * Items: cheque requests sent to Legal (bounced / escalated / hold), cases opened by other
 * users (e.g. from Real Estate or Operations), and issues logged by other users.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/includes/legal_issues_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['user']) && empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$cid = current_company_id($conn) ?: 1;
$uid = (int)current_user_id();
legal_issues_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'seen') {
        $conn->prepare("INSERT INTO re_legal_notif_seen (user_id, company_id, last_seen_at) VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE last_seen_at = NOW()")->execute([$uid, $cid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Last time this user opened the bell; first time = the last 7 days count as new.
$seen = date('Y-m-d H:i:s', strtotime('-7 days'));
try {
    $st = $conn->prepare("SELECT last_seen_at FROM re_legal_notif_seen WHERE user_id = ? AND company_id = ?");
    $st->execute([$uid, $cid]);
    $seen = (string)($st->fetchColumn() ?: $seen);
} catch (Throwable $e) { /* table optional */ }

$tenantSql = "TRIM(COALESCE(NULLIF(t.company_name, ''), CONCAT_WS(' ', t.first_name, t.last_name)))";
$items = [];
$unread = 0;
$add = function (array $rows, callable $map) use (&$items) { foreach ($rows as $r) $items[] = $map($r); };

// 1) Cheque requests sent to Legal
try {
    $st = $conn->prepare("
        SELECT e.id, e.type, e.status, e.cheque_number, e.cheque_amount, e.created_at, u.fullname AS who,
               l.lease_number, $tenantSql AS tenant
        FROM re_legal_cheque_escalations e
        LEFT JOIN user u ON u.id = e.created_by
        LEFT JOIN re_leases l ON l.id = e.lease_id
        LEFT JOIN re_tenants t ON t.id = e.tenant_id
        WHERE e.company_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 15");
    $st->execute([$cid]);
    $add($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => [
        'key' => 'chq' . $r['id'], 'kind' => $r['type'] === 'bounced' ? 'bounce' : 'cheque', 'at' => $r['created_at'],
        'title' => ($r['type'] === 'bounced' ? 'Bounced cheque' : ($r['type'] === 'hold' ? 'Cheque on hold' : 'Cheque escalated')) . ' sent to Legal',
        'sub' => trim(($r['tenant'] ?: 'Tenant') . ' · ' . ($r['cheque_number'] ? 'Chq ' . $r['cheque_number'] . ' · ' : '') . 'AED ' . number_format((float)$r['cheque_amount'])),
        'who' => $r['who'] ?: '', 'url' => 'legal_cheque_notifications.php', 'done' => $r['status'] !== 'new',
    ]);
    $st = $conn->prepare("SELECT COUNT(*) FROM re_legal_cheque_escalations WHERE company_id = ? AND created_at > ?");
    $st->execute([$cid, $seen]);
    $unread += (int)$st->fetchColumn();
} catch (Throwable $e) { }

// 2) Cases opened by someone else
try {
    $st = $conn->prepare("
        SELECT c.id, c.case_number, c.title, c.created_at, c.priority, u.fullname AS who
        FROM re_legal_cases c LEFT JOIN user u ON u.id = c.created_by
        WHERE c.company_id = ? AND c.deleted_at IS NULL AND (c.created_by IS NULL OR c.created_by <> ?)
        ORDER BY c.created_at DESC, c.id DESC LIMIT 15");
    $st->execute([$cid, $uid]);
    $add($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => [
        'key' => 'case' . $r['id'], 'kind' => 'case', 'at' => $r['created_at'],
        'title' => 'New legal case ' . $r['case_number'], 'sub' => (string)$r['title'], 'who' => $r['who'] ?: '',
        'url' => 'legal_case_view.php?id=' . (int)$r['id'], 'done' => false,
    ]);
    $st = $conn->prepare("SELECT COUNT(*) FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL AND (created_by IS NULL OR created_by <> ?) AND created_at > ?");
    $st->execute([$cid, $uid, $seen]);
    $unread += (int)$st->fetchColumn();
} catch (Throwable $e) { }

// 3) Issues logged by someone else
try {
    $st = $conn->prepare("
        SELECT i.id, i.issue_number, i.title, i.created_at, i.status, u.fullname AS who
        FROM re_legal_issues i LEFT JOIN user u ON u.id = i.created_by
        WHERE i.company_id = ? AND i.deleted_at IS NULL AND (i.created_by IS NULL OR i.created_by <> ?)
        ORDER BY i.created_at DESC, i.id DESC LIMIT 15");
    $st->execute([$cid, $uid]);
    $add($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => [
        'key' => 'iss' . $r['id'], 'kind' => 'issue', 'at' => $r['created_at'],
        'title' => 'New issue ' . $r['issue_number'], 'sub' => (string)$r['title'], 'who' => $r['who'] ?: '',
        'url' => 'legal_issue_view.php?id=' . (int)$r['id'], 'done' => in_array($r['status'], ['resolved', 'closed'], true),
    ]);
    $st = $conn->prepare("SELECT COUNT(*) FROM re_legal_issues WHERE company_id = ? AND deleted_at IS NULL AND (created_by IS NULL OR created_by <> ?) AND created_at > ?");
    $st->execute([$cid, $uid, $seen]);
    $unread += (int)$st->fetchColumn();
} catch (Throwable $e) { }

usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
$items = array_slice($items, 0, 20);
foreach ($items as &$it) $it['unread'] = $it['at'] > $seen;
unset($it);

echo json_encode(['ok' => true, 'unread' => $unread, 'latest' => $items[0]['at'] ?? '', 'items' => $items,
                  'now' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
