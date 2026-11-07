<?php
/**
 * Operations → Store — what every Store page loads first: the gate, the rules
 * and the little bits of page they share. Rules: ops_store.php.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/db_connect.php';
require_once dirname(__DIR__, 3) . '/includes/url_helper.php';
require_once __DIR__ . '/ops_helper.php';
require_once __DIR__ . '/ops_store.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$stReady = st_tables_ready($conn);

$stU = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$stWho = [
    'user_id' => (int)current_user_id(),
    'name' => (string)($stU['full_name'] ?? $stU['fullname'] ?? $_SESSION['fullname'] ?? $stU['username'] ?? 'Office'),
    'office' => true,
];

/** The tabs across the top of every Store page. */
function st_page_tabs(string $opsBase, string $current): string
{
    $tabs = ['store.php' => 'Stock', 'store_history.php' => 'History', 'store_setup.php' => 'Stores & items'];
    $out = '<ul class="nav ops-tabs mb-3">';
    foreach ($tabs as $file => $label) {
        $out .= '<li class="nav-item"><a class="nav-link' . ($file === $current ? ' active' : '') . '" href="' . h($opsBase . '/' . $file) . '">' . h($label) . '</a></li>';
    }
    return $out . '</ul>';
}

function st_kind_badge(string $kind): string
{
    $cls = ['in' => 'bg-success-subtle text-success-emphasis', 'out' => 'bg-danger-subtle text-danger-emphasis',
        'move' => 'bg-primary-subtle text-primary-emphasis', 'count' => 'bg-warning-subtle text-warning-emphasis', 'broken' => 'bg-secondary-subtle text-secondary-emphasis'][$kind] ?? 'bg-light text-dark';
    return '<span class="badge ' . $cls . '">' . h(ST_KINDS[$kind] ?? $kind) . '</span>';
}

function st_not_ready_alert(): string
{
    return '<div class="alert alert-warning">The store tables are not set up yet. Run migrations/staff_store.sql.</div>';
}
