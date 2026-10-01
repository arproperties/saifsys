<?php
/**
 * Fleet inside Operations — what every fleet page in this module loads first.
 *
 * The pages moved here from HR. The rules, the queries and the shared bits of
 * page stayed in hr/includes, because the Driver app's API (api/mobile/fleet)
 * and the employee screen read them from there.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/db_connect.php';
require_once dirname(__DIR__, 3) . '/includes/url_helper.php';
require_once __DIR__ . '/ops_helper.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_company_scope.php';
require_once dirname(__DIR__, 3) . '/hr/includes/ui/hr_ui_helpers.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_fleet.php';
require_once dirname(__DIR__, 3) . '/hr/includes/hr_fleet_ui.php';

// Same door as the rest of Operations: Owner, Admin and Operations supervisors.
require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
// fleet-map.js still lives with the HR assets.
$hrAssetBase = $appBase . '/assets/hr';

/**
 * The fleet pages are written with the HR shell's card, table and pill classes,
 * and hr-ui-v2.css only applies inside that shell. These are the same pieces in
 * this module's look; ops_layout_header.php prints them.
 */
function ops_fleet_styles(): string
{
    return '
.hr-page-header h1{font-size:1.85rem;font-weight:700;color:var(--primary);margin:0}
.hr-page-sub{color:#6b7280}
.hr-crumb{color:#9ca3af;text-decoration:none}
.hr-settings-card{background:#fff;border:1px solid rgba(0,0,0,.175);border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.06);margin-bottom:1.25rem;overflow:hidden}
.hr-settings-card > .settings-header{background:#fafafa;border-bottom:1px solid rgba(0,0,0,.1);padding:1rem 1.5rem;font-weight:600}
.hr-settings-card > .card-body:not(.p-0){padding:1.25rem 1.5rem}
.hr-table-shell{background:#fff;border:1px solid rgba(0,0,0,.175);border-radius:16px;overflow:hidden}
.hr-table-shell .table{margin-bottom:0;font-size:.875rem}
.hr-table-shell thead th{background:#fafafa;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:700;white-space:nowrap}
.hr-pill{display:inline-flex;align-items:center;padding:.2rem .55rem;border-radius:999px;font-size:.75rem;font-weight:600;border:1px solid transparent}
.hr-pill-ok{background:#ecfdf5;color:#059669;border-color:#a7f3d0}
.hr-pill-fail{background:#fef2f2;color:#dc2626;border-color:#fecaca}
.hr-pill-warn{background:#fffbeb;color:#d97706;border-color:#fde68a}
.hr-pill-info{background:#f0f9ff;color:#0284c7;border-color:#bae6fd}
.hr-pill-muted{background:#f3f4f6;color:#6b7280;border-color:#e5e7eb}
.hr-pill-gold{background:rgba(184,134,11,.12);color:#b8860b;border-color:#f0d78c}
';
}
