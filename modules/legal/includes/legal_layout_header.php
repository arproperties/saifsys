<?php
/**
 * Legal Department — standalone module layout (no Real Estate sidebar).
 */
if (!isset($brand)) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
    $brand = getBrandSettings($conn);
}
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$currentPage = basename($_SERVER['PHP_SELF']);
$appBase = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
$legalBase = $appBase . '/modules/legal';

$U = $_SESSION['user'] ?? [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username'] ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
require_once __DIR__ . '/legal_helper.php';
$hasLegal = legal_can_manage($conn);

// Hide "Switch Module" for users locked to Legal only.
// Owners/Admins (or anyone with access to more than one module) keep the link.
$canSwitchModule = true;
try {
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
    $legalUid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    $legalRoleNames = $_SESSION['role_names'] ?? [];
    $isOwnerAdmin = in_array('Owner', $legalRoleNames, true) || in_array('Admin', $legalRoleNames, true);
    if (!$isOwnerAdmin && $legalUid > 0) {
        $legalUserModules = get_user_modules($conn, $legalUid);
        $legalModuleKeys = array_filter(array_unique(array_map(
            static function ($m) { return is_array($m) ? ($m['module'] ?? '') : (string)$m; },
            $legalUserModules
        )));
        $canSwitchModule = count($legalModuleKeys) > 1;
    }
} catch (Throwable $e) {
    $canSwitchModule = true; // fail open so users are never trapped
}

$casePages = ['legal_cases.php','legal_case_add.php','legal_case_edit.php','legal_case_view.php'];
$noticePages = ['legal_notices.php','legal_notice_add.php','legal_notice_view.php'];
$pdcPages = ['legal_billing_cheques.php'];
$documentPages = ['legal_documents.php'];
$calendarPages = ['legal_calendar.php','legal_hearing_add.php','legal_hearing_edit.php'];
$counselPages = ['legal_counsel.php','legal_counsel_add.php','legal_counsel_edit.php'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' - Legal | ' : 'Legal | ' ?><?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/legal.css?v=<?= @filemtime(__DIR__ . '/../assets/legal.css') ?: 1 ?>" rel="stylesheet">
    <?php if (isset($pageHead)) echo $pageHead; ?>
</head>
<body>
<?php
// Check-out bar for staff who have checked in. Prints nothing when the
// feature is off or the person has no attendance to record.
$asWidget = dirname(__DIR__, 3) . '/includes/attendance_self_widget.php';
if (is_file($asWidget)) { require $asWidget; }
?>
<div class="d-flex">
    <aside id="legalSb" class="legal-sidebar d-flex flex-column">
        <div class="brand">
            <?php if ($logoSrc = brand_logo_src($brand)): ?>
                <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name']) ?>" style="width:34px;height:34px;object-fit:contain;border-radius:8px;">
            <?php else: ?>
                <i class="bi bi-bank2"></i>
            <?php endif; ?>
            <span class="sb-label">Legal Department</span>
        </div>
        <?php if ($hasLegal): ?>
        <?php require_once dirname(__DIR__, 3) . '/includes/nav_search.php'; ?>
        <?= nav_search_box() ?>
        <nav class="flex-grow-1 py-2">
            <div class="legal-nav-section">Overview</div>
            <a href="legal_dashboard.php" class="legal-nav-link <?= $currentPage === 'legal_dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i><span class="sb-label">Dashboard</span>
            </a>
            <div class="legal-nav-section">Cases &amp; Notices</div>
            <a href="legal_cases.php" class="legal-nav-link <?= in_array($currentPage, $casePages, true) ? 'active' : '' ?>">
                <i class="bi bi-briefcase"></i><span class="sb-label">Legal Cases</span>
            </a>
            <a href="legal_notices.php" class="legal-nav-link <?= in_array($currentPage, $noticePages, true) ? 'active' : '' ?>">
                <i class="bi bi-envelope-paper"></i><span class="sb-label">Legal Notices</span>
            </a>
            <div class="legal-nav-section">Collections &amp; Files</div>
            <a href="legal_cheque_notifications.php" class="legal-nav-link <?= $currentPage === 'legal_cheque_notifications.php' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i><span class="sb-label">Cheque Notifications</span>
            </a>
            <a href="legal_billing_cheques.php" class="legal-nav-link <?= in_array($currentPage, $pdcPages, true) ? 'active' : '' ?>">
                <i class="bi bi-bank"></i><span class="sb-label">Post-Dated Cheques</span>
            </a>
            <a href="legal_documents.php" class="legal-nav-link <?= in_array($currentPage, $documentPages, true) ? 'active' : '' ?>">
                <i class="bi bi-folder2-open"></i><span class="sb-label">Documents</span>
            </a>
            <div class="legal-nav-section">Planning &amp; Reports</div>
            <a href="legal_calendar.php" class="legal-nav-link <?= in_array($currentPage, $calendarPages, true) ? 'active' : '' ?>">
                <i class="bi bi-calendar3"></i><span class="sb-label">Hearings Calendar</span>
            </a>
            <a href="legal_counsel.php" class="legal-nav-link <?= in_array($currentPage, $counselPages, true) ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i><span class="sb-label">External Counsel</span>
            </a>
            <a href="legal_reports.php" class="legal-nav-link <?= $currentPage === 'legal_reports.php' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-line"></i><span class="sb-label">Reports</span>
            </a>
        </nav>
        <?php endif; ?>
        <?php if ($canSwitchModule): ?>
        <div class="p-3 border-top border-secondary">
            <a href="<?= h($appBase) ?>/select-module" class="legal-nav-link"><i class="bi bi-grid"></i><span class="sb-label">Switch Module</span></a>
        </div>
        <?php endif; ?>
    </aside>
    <div class="legal-main">
        <div class="legal-topbar d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="legalSbToggle"><i class="bi bi-list"></i></button>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-dark">Legal</span>
                <span class="text-muted small"><?= h($fullName ?: $userName) ?></span>
                <span class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:0.85rem"><?= h($avatarInitial) ?></span>
            </div>
        </div>
        <div class="container-fluid py-4 px-4">
