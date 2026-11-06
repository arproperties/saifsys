<?php
/**
 * Real Estate Module - Mark as Moved In (POST from lease_view.php)
 * Records a completed, historical move-in without the checklist. Owner/Admin only.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/move_in_historical_helper.php';
require_once __DIR__ . '/includes/move_in_chain_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$leaseId = (int)($_POST['lease_id'] ?? 0);
$back = 'lease_view.php?id=' . $leaseId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$leaseId) {
    header('Location: leases.php');
    exit;
}
csrf_verify();

if (!has_role('Owner', $conn) && !has_role('Admin', $conn)) {
    $_SESSION['error'] = 'Only Owner or Admin can mark a lease as moved in.';
    header('Location: ' . $back);
    exit;
}

$companyId = current_company_id($conn) ?: 1;
$userId = (int)(current_user_id() ?: 0);

// Not for a lease that has ended
$stLease = $conn->prepare("SELECT status FROM re_leases WHERE id = ? AND company_id = ?");
$stLease->execute([$leaseId, $companyId]);
if (in_array((string)$stLease->fetchColumn(), ['expired', 'terminated'], true)) {
    $_SESSION['error'] = 'An expired or terminated lease cannot be marked as moved in.';
    header('Location: ' . $back);
    exit;
}

// A renewal is covered by the move-in of the first lease of the tenancy: record it there
$first = re_move_in_first_lease($conn, (int)$companyId, $leaseId);
$targetId = $first ? (int)$first['id'] : $leaseId;

$res = re_move_in_mark_historical(
    $conn,
    $companyId,
    $userId,
    $targetId,
    trim((string)($_POST['move_in_date'] ?? '')),
    (string)($_POST['reason'] ?? '')
);

if (!empty($res['ok']) && $first) {
    // The renewal is the running lease: its unit is occupied
    $cur = $conn->prepare("SELECT status, unit_id FROM re_leases WHERE id = ? AND company_id = ?");
    $cur->execute([$leaseId, $companyId]);
    if (($row = $cur->fetch(PDO::FETCH_ASSOC)) && $row['status'] === 'active') {
        re_lease_units_set_occupied($conn, $companyId, $leaseId);
    }
    $_SESSION['success'] = 'Marked as moved in on the first lease ' . ($first['lease_number'] ?: ('#' . $first['id']))
        . '. It covers this renewal: Move-Out is now available.';
} elseif (!empty($res['ok'])) {
    $_SESSION['success'] = 'Lease marked as moved in. Move-Out is now available for this lease.';
} else {
    $_SESSION['error'] = $res['error'] ?? 'Could not mark as moved in.';
}
header('Location: ' . $back);
exit;
