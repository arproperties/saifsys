<?php
/**
 * Real Estate Module - Collections & Alerts Dashboard
 * Manage overdue payments, alerts, and collections
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/installment_outstanding.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Get overdue installments
$overdueInstallments = $conn->prepare("
    SELECT 
        li.*,
        l.lease_number,
        l.monthly_rent,
        l.tenant_id,
        u.unit_number,
        u.building_id,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        DATEDIFF(CURDATE(), li.installment_date) as days_overdue
    FROM re_lease_installments li
    JOIN re_leases l ON l.id = li.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ?
    AND l.status <> 'draft'
    AND b.is_active = 1
    AND li.status = 'pending'
    AND li.installment_date < CURDATE()
    AND NOT EXISTS (
        SELECT 1
        FROM re_post_dated_cheques c
        WHERE c.installment_id = li.id
          AND c.lease_id = li.lease_id
          AND c.status IN ('cleared', 'returned', 'cancelled')
    )
    ORDER BY li.installment_date ASC
");
$overdueInstallments->execute([$currentCompanyId]);
$overdueInstallments = $overdueInstallments->fetchAll(PDO::FETCH_ASSOC);
// The schedule row's own status/amount are not a settlement signal: in Invoice Mode a
// receipt settles the invoices behind the row without ever writing it back to 'paid'.
// Resolve what is actually still owed (same precedence as lease_view.php) and drop rows
// that are already fully collected.
$overdueInstallments = re_apply_installment_outstanding($conn, $currentCompanyId, $overdueInstallments);

// Get overdue billing items
$overdueBillingItems = $conn->prepare("
    SELECT 
        bi.*,
        l.lease_number,
        l.tenant_id,
        u.unit_number,
        u.building_id,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        DATEDIFF(CURDATE(), bi.due_date) as days_overdue
    FROM re_billing_items bi
    JOIN re_leases l ON l.id = bi.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE bi.company_id = ?
    AND l.status <> 'draft'
    AND b.is_active = 1
    AND bi.is_paid = 0
    AND COALESCE(bi.is_waived, 0) = 0
    AND bi.status != 'waived'
    AND bi.due_date < CURDATE()
    ORDER BY bi.due_date ASC
");
$overdueBillingItems->execute([$currentCompanyId]);
$overdueBillingItems = $overdueBillingItems->fetchAll(PDO::FETCH_ASSOC);
// is_paid is never set by Invoice Mode receipts; resolve what is still owed (as lease_view.php does).
$overdueBillingItems = re_apply_billing_item_outstanding($conn, $currentCompanyId, $overdueBillingItems);

// Get overdue invoices
$overdueInvoices = $conn->prepare("
    SELECT 
        i.*,
        l.lease_number,
        l.tenant_id,
        u.unit_number,
        u.building_id,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        DATEDIFF(CURDATE(), i.due_date) as days_overdue
    FROM re_invoices i
    JOIN re_leases l ON l.id = i.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE i.company_id = ?
    AND l.status <> 'draft'
    AND b.is_active = 1
    AND i.status IN ('sent', 'partial')
    AND i.due_date < CURDATE()
    ORDER BY i.due_date ASC
");
$overdueInvoices->execute([$currentCompanyId]);
$overdueInvoices = $overdueInvoices->fetchAll(PDO::FETCH_ASSOC);

// Get bounced cheques
$bouncedCheques = $conn->prepare("
    SELECT 
        c.*,
        l.lease_number,
        l.tenant_id,
        u.unit_number,
        u.building_id,
        b.name as building_name,
        t.first_name,
        t.last_name,
        t.phone,
        t.email
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE c.company_id = ? 
    AND c.status = 'bounced'
    AND b.is_active = 1
    ORDER BY c.bounced_date DESC
");
$bouncedCheques->execute([$currentCompanyId]);
$bouncedCheques = $bouncedCheques->fetchAll(PDO::FETCH_ASSOC);

// ---- Filters (search, date range, building, age) ----
// Applied after outstanding resolution so the amounts shown are the same as unfiltered.
$fSearch   = trim((string)($_GET['q'] ?? ''));
$fFrom     = (string)($_GET['date_from'] ?? '');
$fTo       = (string)($_GET['date_to'] ?? '');
$fBuilding = (int)($_GET['building_id'] ?? 0);
$fAge      = (string)($_GET['age'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) $fFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   $fTo = '';
$ageBuckets = ['1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, PHP_INT_MAX]];
if (!isset($ageBuckets[$fAge])) $fAge = '';
$filtersActive = ($fSearch !== '' || $fFrom !== '' || $fTo !== '' || $fBuilding > 0 || $fAge !== '');

$buildingsList = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? AND is_active = 1 ORDER BY name");
$buildingsList->execute([$currentCompanyId]);
$buildingsList = $buildingsList->fetchAll(PDO::FETCH_ASSOC);

/**
 * Keep rows matching the page filters.
 * $dateField: the row's date used for the From/To range.
 * $searchFields: extra row fields (besides tenant/unit/lease) the search box looks in.
 */
$applyFilters = function (array $rows, string $dateField, array $searchFields = [], bool $useAge = true)
    use ($fSearch, $fFrom, $fTo, $fBuilding, $fAge, $ageBuckets) {
    $needle = mb_strtolower($fSearch);
    return array_values(array_filter($rows, function ($r) use ($needle, $dateField, $searchFields, $useAge, $fFrom, $fTo, $fBuilding, $fAge, $ageBuckets) {
        if ($fBuilding > 0 && (int)($r['building_id'] ?? 0) !== $fBuilding) return false;
        $d = substr((string)($r[$dateField] ?? ''), 0, 10);
        if ($fFrom !== '' && ($d === '' || $d < $fFrom)) return false;
        if ($fTo !== ''   && ($d === '' || $d > $fTo))   return false;
        if ($useAge && $fAge !== '') {
            $days = (int)($r['days_overdue'] ?? 0);
            [$min, $max] = $ageBuckets[$fAge];
            if ($days < $min || $days > $max) return false;
        }
        if ($needle !== '') {
            $hay = [$r['first_name'] ?? '', $r['last_name'] ?? '', ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''),
                    $r['phone'] ?? '', $r['email'] ?? '', $r['unit_number'] ?? '', $r['building_name'] ?? '', $r['lease_number'] ?? ''];
            foreach ($searchFields as $f) $hay[] = $r[$f] ?? '';
            if (mb_strpos(mb_strtolower(implode(' | ', $hay)), $needle) === false) return false;
        }
        return true;
    }));
};

$overdueInstallments = $applyFilters($overdueInstallments, 'installment_date');
$overdueBillingItems = $applyFilters($overdueBillingItems, 'due_date', ['item_name', 'item_type']);
$overdueInvoices     = $applyFilters($overdueInvoices, 'due_date', ['invoice_number']);
$bouncedCheques      = $applyFilters($bouncedCheques, 'bounced_date', ['cheque_number', 'bank_name', 'bounced_reason'], false);

// Calculate statistics
$totalOverdue = 0;
foreach ($overdueInstallments as $item) {
    $totalOverdue += (float)$item['outstanding_balance'];
}
foreach ($overdueBillingItems as $item) {
    $totalOverdue += (float)$item['outstanding_balance'];
}
foreach ($overdueInvoices as $inv) {
    $totalOverdue += (float)$inv['outstanding_amount'];
}

$totalBounced = array_sum(array_column($bouncedCheques, 'cheque_amount'));

// Get recent alerts
$recentAlerts = $conn->prepare("
    SELECT 
        oa.*,
        l.lease_number,
        l.tenant_id,
        u.unit_number,
        u.building_id,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_overdue_rent_alerts oa
    JOIN re_leases l ON l.id = oa.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE oa.company_id = ?
    ORDER BY oa.alert_sent_date DESC
    LIMIT 500
");
$recentAlerts->execute([$currentCompanyId]);
$recentAlerts = $recentAlerts->fetchAll(PDO::FETCH_ASSOC);
$recentAlerts = $applyFilters($recentAlerts, 'alert_sent_date', [], false);
$recentAlerts = array_slice($recentAlerts, 0, $filtersActive ? 200 : 20);

$sumInstallments = array_sum(array_map(fn($r) => (float)$r['outstanding_balance'], $overdueInstallments));
$sumBilling      = array_sum(array_map(fn($r) => (float)$r['outstanding_balance'], $overdueBillingItems));
$sumInvoices     = array_sum(array_map(fn($r) => (float)$r['outstanding_amount'], $overdueInvoices));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }


$fmtDate = function ($d) { return $d ? date('d M Y', strtotime($d)) : '-'; };
$tenantCell = function ($r) {
    $name = h(trim($r['first_name'] . ' ' . $r['last_name']));
    $out  = !empty($r['tenant_id']) ? '<a href="tenant_view.php?id=' . (int)$r['tenant_id'] . '">' . $name . '</a>' : $name;
    if (!empty($r['phone'])) {
        $out .= '<br><a class="small text-muted" href="tel:' . h($r['phone']) . '"><i class="bi bi-telephone"></i> ' . h($r['phone']) . '</a>';
        $wa = preg_replace('/\D+/', '', (string)$r['phone']);
        if (strpos($wa, '00') === 0) $wa = substr($wa, 2);
        elseif (strpos($wa, '0') === 0) $wa = '971' . substr($wa, 1);   // local UAE number
        if (strlen($wa) >= 9) $out .= ' <a class="small text-success ms-1" href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>';
    }
    return $out;
};
$leaseCell = function ($r) {
    return h($r['building_name']) . ' - ' . h($r['unit_number'])
        . '<br><a class="small" href="lease_view.php?id=' . (int)$r['lease_id'] . '">' . h($r['lease_number']) . '</a>';
};
$ageBadge = function ($days) {
    $days = (int)$days;
    $cls = $days > 90 ? 'bg-danger' : ($days > 60 ? 'bg-danger bg-opacity-75' : ($days > 30 ? 'bg-warning text-dark' : 'bg-secondary'));
    return '<span class="badge ' . $cls . '">' . $days . ' days</span>';
};

// Set page title and include layout
$pageTitle = 'Collections & Alerts';
$reLayoutFluid = true;
require_once __DIR__ . '/includes/re_layout_header.php';

$tabs = [
    'installments' => ['label' => 'Rent Installments', 'icon' => 'calendar-x',         'count' => count($overdueInstallments), 'amount' => $sumInstallments],
    'billing'      => ['label' => 'Billing Items',     'icon' => 'exclamation-circle', 'count' => count($overdueBillingItems), 'amount' => $sumBilling],
    'invoices'     => ['label' => 'Invoices',          'icon' => 'file-text',          'count' => count($overdueInvoices),     'amount' => $sumInvoices],
    'cheques'      => ['label' => 'Bounced Cheques',   'icon' => 'bank',               'count' => count($bouncedCheques),      'amount' => $totalBounced],
    'alerts'       => ['label' => 'Alerts Sent',       'icon' => 'bell',               'count' => count($recentAlerts),        'amount' => null],
];
?>
<style>
    .coll-stat { cursor: pointer; transition: box-shadow .15s, transform .15s; border-left: 4px solid var(--bs-border-color); }
    .coll-stat:hover { box-shadow: 0 .25rem .75rem rgba(0,0,0,.08); transform: translateY(-1px); }
    .coll-stat.active { border-left-color: var(--bs-danger); background: var(--bs-danger-bg-subtle); }
    .coll-stat .coll-stat-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; color: var(--bs-secondary-color); }
    .coll-stat .coll-stat-count { font-size: 1.6rem; font-weight: 600; line-height: 1.1; }
    .coll-table thead th { position: sticky; top: 0; background: var(--bs-body-bg); z-index: 1; white-space: nowrap; }
    .coll-table-wrap { max-height: 65vh; overflow: auto; }
    .coll-tabs { gap: .35rem; padding: .35rem; background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color); border-radius: .6rem; }
    .coll-tabs .nav-link {
        white-space: nowrap; color: var(--bs-body-color); font-weight: 500;
        border: 1px solid transparent; border-radius: .45rem; padding: .5rem 1rem;
        background: transparent; box-shadow: none; outline: none;
    }
    .coll-tabs .nav-link i { color: var(--bs-secondary-color); }
    .coll-tabs .nav-link:hover { background: var(--bs-body-bg); border-color: var(--bs-border-color); }
    .coll-tabs .nav-link.active {
        background: var(--bs-body-bg); color: var(--bs-body-color);
        border-color: var(--bs-border-color); border-bottom: 3px solid var(--bs-danger);
        box-shadow: 0 .125rem .35rem rgba(0,0,0,.08);
    }
    .coll-tabs .nav-link.active i { color: var(--bs-danger); }
    .coll-table th[data-sort] { cursor: pointer; user-select: none; }
    .coll-table th[data-sort]::after { content: '\2195'; font-size: .75rem; margin-left: .3rem; opacity: .35; }
    .coll-table th[data-sort].asc::after  { content: '\25B2'; font-size: .6rem; opacity: .9; }
    .coll-table th[data-sort].desc::after { content: '\25BC'; font-size: .6rem; opacity: .9; }
    .coll-table tbody tr.coll-hidden { display: none; }
    @media print {
        .coll-no-print, #collFilterForm, .coll-tabs, #collExport { display: none !important; }
        .coll-table-wrap { max-height: none; overflow: visible; }
    }
    .coll-tabs .nav-link:focus-visible { box-shadow: 0 0 0 .2rem rgba(var(--bs-primary-rgb), .25); }
</style>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div class="page-header-label"><i class="bi bi-exclamation-triangle"></i> Collections & Alerts</div>
            <div class="d-flex gap-2">
                <a href="collections_alerts_config.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-gear"></i> Alert Settings</a>
                <a href="collections_send_alerts.php" class="btn btn-success btn-sm"><i class="bi bi-envelope"></i> Send Alerts Now</a>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body py-3">
                <form method="get" id="collFilterForm" class="row g-2 align-items-end">
                    <div class="col-12 col-lg-3">
                        <label class="form-label small mb-1">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" name="q" id="collSearch" class="form-control" autocomplete="off" value="<?= h($fSearch) ?>"
                                   placeholder="Tenant, phone, unit, lease, invoice or cheque #">
                        </div>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label small mb-1">Due date from</label>
                        <input type="date" name="date_from" id="collFrom" class="form-control" value="<?= h($fFrom) ?>">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label small mb-1">Due date to</label>
                        <input type="date" name="date_to" id="collTo" class="form-control" value="<?= h($fTo) ?>">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label small mb-1">Building</label>
                        <select name="building_id" class="form-select coll-autosubmit">
                            <option value="0">All buildings</option>
                            <?php foreach ($buildingsList as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $fBuilding === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-1">
                        <label class="form-label small mb-1">Overdue</label>
                        <select name="age" class="form-select coll-autosubmit">
                            <option value="">Any</option>
                            <?php foreach (array_keys($ageBuckets) as $a): ?>
                                <option value="<?= h($a) ?>" <?= $fAge === $a ? 'selected' : '' ?>><?= h($a) ?> days</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> Apply</button>
                        <?php if ($filtersActive): ?>
                            <a href="collections.php" class="btn btn-outline-secondary flex-fill"><i class="bi bi-x-lg"></i> Clear</a>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-1 align-items-center">
                        <span class="small text-muted me-1">Quick dates:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary coll-preset" data-preset="this_month">This month</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary coll-preset" data-preset="last_month">Last month</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary coll-preset" data-preset="last_30">Last 30 days</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary coll-preset" data-preset="last_90">Last 90 days</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary coll-preset" data-preset="this_year">This year</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary coll-preset" data-preset="all">All dates</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary: click a card to open its tab -->
        <div class="row g-2 mb-3">
            <?php foreach ($tabs as $key => $t): ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card coll-stat h-100" data-coll-tab="<?= $key ?>">
                        <div class="card-body py-2 px-3">
                            <div class="coll-stat-label"><i class="bi bi-<?= $t['icon'] ?>"></i> <?= h($t['label']) ?></div>
                            <div class="coll-stat-count <?= $t['count'] > 0 && $key !== 'alerts' ? 'text-danger' : '' ?>" data-stat-count><?= $t['count'] ?></div>
                            <?php if ($t['amount'] !== null): ?>
                                <div class="small text-muted"><span data-stat-sum><?= number_format($t['amount'], 2) ?></span> AED</div>
                            <?php else: ?>
                                <div class="small text-muted"><?= $filtersActive ? 'matching filters' : 'latest 20' ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="col-12 col-md-4 col-xl">
                <div class="card h-100 border-danger">
                    <div class="card-body py-2 px-3">
                        <div class="coll-stat-label">Total Overdue</div>
                        <div class="coll-stat-count text-danger" id="collTotalOverdue"><?= number_format($totalOverdue, 2) ?></div>
                        <div class="small text-muted">AED<?= $filtersActive ? ' · filtered' : '' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-body border-bottom-0 pt-3">
                <ul class="nav coll-tabs flex-nowrap overflow-auto" role="tablist">
                    <?php foreach ($tabs as $key => $t): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="coll-tab-<?= $key ?>" data-bs-toggle="tab" data-bs-target="#coll-pane-<?= $key ?>"
                                    type="button" role="tab" data-coll-key="<?= $key ?>">
                                <i class="bi bi-<?= $t['icon'] ?>"></i> <?= h($t['label']) ?>
                                <span class="badge rounded-pill <?= $t['count'] > 0 && $key !== 'alerts' ? 'bg-danger' : 'bg-secondary' ?>" data-tab-count><?= $t['count'] ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                    <span class="small text-muted" id="collLiveNote"><i class="bi bi-lightning"></i> Search filters as you type. Click a column heading to sort.</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success" id="collExport"><i class="bi bi-filetype-csv"></i> Export this tab</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </div>
            </div>
            <div class="card-body tab-content pt-3">

                <!-- Installments -->
                <div class="tab-pane fade" id="coll-pane-installments" role="tabpanel">
                    <?php if (empty($overdueInstallments)): ?>
                        <p class="text-muted mb-0">No overdue installments<?= $filtersActive ? ' match these filters' : '' ?>.</p>
                    <?php else: ?>
                        <div class="table-responsive coll-table-wrap">
                            <table class="table table-sm table-hover align-middle coll-table mb-0">
                                <thead><tr><th data-sort>Unit / Lease</th><th data-sort>Tenant</th><th data-sort>Due Date</th><th data-sort>Overdue</th><th data-sort class="text-end">Outstanding</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($overdueInstallments as $item): ?>
                                    <tr data-amount="<?= (float)$item['outstanding_balance'] ?>">
                                        <td><?= $leaseCell($item) ?></td>
                                        <td><?= $tenantCell($item) ?></td>
                                        <td data-v="<?= h($item['installment_date']) ?>"><?= $fmtDate($item['installment_date']) ?></td>
                                        <td data-v="<?= (int)$item['days_overdue'] ?>"><?= $ageBadge($item['days_overdue']) ?></td>
                                        <td class="text-end" data-v="<?= (float)$item['outstanding_balance'] ?>">
                                            <strong><?= number_format((float)$item['outstanding_balance'], 2) ?> AED</strong>
                                            <?php if ((float)$item['collected_amount'] > 0.005): ?>
                                                <br><small class="text-muted">of <?= number_format((float)$item['amount'], 2) ?> · collected <?= number_format((float)$item['collected_amount'], 2) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><a href="lease_view.php?id=<?= (int)$item['lease_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Lease</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr class="table-light"><th colspan="4">Total (<span class="coll-foot-count"><?= count($overdueInstallments) ?></span>)</th><th class="text-end"><span class="coll-foot-sum"><?= number_format($sumInstallments, 2) ?></span> AED</th><th></th></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Billing items -->
                <div class="tab-pane fade" id="coll-pane-billing" role="tabpanel">
                    <?php if (empty($overdueBillingItems)): ?>
                        <p class="text-muted mb-0">No overdue billing items<?= $filtersActive ? ' match these filters' : '' ?>.</p>
                    <?php else: ?>
                        <div class="table-responsive coll-table-wrap">
                            <table class="table table-sm table-hover align-middle coll-table mb-0">
                                <thead><tr><th data-sort>Item</th><th data-sort>Unit / Lease</th><th data-sort>Tenant</th><th data-sort>Due Date</th><th data-sort>Overdue</th><th data-sort class="text-end">Outstanding</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($overdueBillingItems as $item): ?>
                                    <tr data-amount="<?= (float)$item['outstanding_balance'] ?>">
                                        <td><strong><?= h($item['item_name']) ?></strong><br><small class="text-muted"><?= h(ucfirst(str_replace('_', ' ', (string)$item['item_type']))) ?></small></td>
                                        <td><?= $leaseCell($item) ?></td>
                                        <td><?= $tenantCell($item) ?></td>
                                        <td data-v="<?= h($item['due_date']) ?>"><?= $fmtDate($item['due_date']) ?></td>
                                        <td data-v="<?= (int)$item['days_overdue'] ?>"><?= $ageBadge($item['days_overdue']) ?></td>
                                        <td class="text-end" data-v="<?= (float)$item['outstanding_balance'] ?>">
                                            <strong><?= number_format((float)$item['outstanding_balance'], 2) ?> AED</strong>
                                            <?php if ((float)$item['collected_amount'] > 0.005): ?>
                                                <br><small class="text-muted">of <?= number_format((float)$item['total_amount'], 2) ?> · collected <?= number_format((float)$item['collected_amount'], 2) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><a href="lease_view.php?id=<?= (int)$item['lease_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Lease</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr class="table-light"><th colspan="5">Total (<span class="coll-foot-count"><?= count($overdueBillingItems) ?></span>)</th><th class="text-end"><span class="coll-foot-sum"><?= number_format($sumBilling, 2) ?></span> AED</th><th></th></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Invoices -->
                <div class="tab-pane fade" id="coll-pane-invoices" role="tabpanel">
                    <?php if (empty($overdueInvoices)): ?>
                        <p class="text-muted mb-0">No overdue invoices<?= $filtersActive ? ' match these filters' : '' ?>.</p>
                    <?php else: ?>
                        <div class="table-responsive coll-table-wrap">
                            <table class="table table-sm table-hover align-middle coll-table mb-0">
                                <thead><tr><th data-sort>Invoice #</th><th data-sort>Unit / Lease</th><th data-sort>Tenant</th><th data-sort>Due Date</th><th data-sort>Overdue</th><th data-sort class="text-end">Outstanding</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($overdueInvoices as $inv): ?>
                                    <tr data-amount="<?= (float)$inv['outstanding_amount'] ?>">
                                        <td><strong><?= h($inv['invoice_number']) ?></strong></td>
                                        <td><?= $leaseCell($inv) ?></td>
                                        <td><?= $tenantCell($inv) ?></td>
                                        <td data-v="<?= h($inv['due_date']) ?>"><?= $fmtDate($inv['due_date']) ?></td>
                                        <td data-v="<?= (int)$inv['days_overdue'] ?>"><?= $ageBadge($inv['days_overdue']) ?></td>
                                        <td class="text-end" data-v="<?= (float)$inv['outstanding_amount'] ?>"><strong><?= number_format((float)$inv['outstanding_amount'], 2) ?> AED</strong></td>
                                        <td class="text-end"><a href="billing_invoice_view.php?id=<?= (int)$inv['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr class="table-light"><th colspan="5">Total (<span class="coll-foot-count"><?= count($overdueInvoices) ?></span>)</th><th class="text-end"><span class="coll-foot-sum"><?= number_format($sumInvoices, 2) ?></span> AED</th><th></th></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Bounced cheques -->
                <div class="tab-pane fade" id="coll-pane-cheques" role="tabpanel">
                    <?php if ($fAge !== ''): ?><p class="small text-muted">The "Overdue" filter does not apply here; dates filter by bounced date.</p><?php endif; ?>
                    <?php if (empty($bouncedCheques)): ?>
                        <p class="text-muted mb-0">No bounced cheques<?= $filtersActive ? ' match these filters' : '' ?>.</p>
                    <?php else: ?>
                        <div class="table-responsive coll-table-wrap">
                            <table class="table table-sm table-hover align-middle coll-table mb-0">
                                <thead><tr><th data-sort>Cheque #</th><th data-sort>Unit / Lease</th><th data-sort>Tenant</th><th data-sort>Bounced Date</th><th data-sort>Reason</th><th data-sort class="text-end">Amount</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($bouncedCheques as $cheque): ?>
                                    <tr data-amount="<?= (float)$cheque['cheque_amount'] ?>">
                                        <td><strong><?= h($cheque['cheque_number']) ?></strong></td>
                                        <td><?= $leaseCell($cheque) ?></td>
                                        <td><?= $tenantCell($cheque) ?></td>
                                        <td data-v="<?= h((string)$cheque['bounced_date']) ?>"><?= $fmtDate($cheque['bounced_date']) ?></td>
                                        <td><?= h($cheque['bounced_reason'] ?: '-') ?></td>
                                        <td class="text-end" data-v="<?= (float)$cheque['cheque_amount'] ?>"><strong><?= number_format((float)$cheque['cheque_amount'], 2) ?> AED</strong></td>
                                        <td class="text-end"><a href="billing_cheque_view.php?id=<?= (int)$cheque['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr class="table-light"><th colspan="5">Total (<span class="coll-foot-count"><?= count($bouncedCheques) ?></span>)</th><th class="text-end"><span class="coll-foot-sum"><?= number_format($totalBounced, 2) ?></span> AED</th><th></th></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Alerts -->
                <div class="tab-pane fade" id="coll-pane-alerts" role="tabpanel">
                    <?php if (empty($recentAlerts)): ?>
                        <p class="text-muted mb-0">No alerts sent<?= $filtersActive ? ' match these filters' : '' ?>.</p>
                    <?php else: ?>
                        <?php if (!$filtersActive): ?><p class="small text-muted">Showing the latest 20. Use the date filter to see older alerts.</p><?php endif; ?>
                        <div class="table-responsive coll-table-wrap">
                            <table class="table table-sm table-hover align-middle coll-table mb-0">
                                <thead><tr><th data-sort>Sent</th><th data-sort>Unit / Lease</th><th data-sort>Tenant</th><th data-sort class="text-end">Amount</th><th data-sort>Days Overdue</th><th data-sort>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentAlerts as $alert): ?>
                                    <tr data-amount="<?= (float)$alert['amount'] ?>">
                                        <td data-v="<?= h($alert['alert_sent_date']) ?>"><?= $fmtDate($alert['alert_sent_date']) ?></td>
                                        <td><?= $leaseCell($alert) ?></td>
                                        <td><?= $tenantCell($alert) ?></td>
                                        <td class="text-end" data-v="<?= (float)$alert['amount'] ?>"><?= number_format((float)$alert['amount'], 2) ?> AED</td>
                                        <td data-v="<?= (int)$alert['days_overdue'] ?>"><?= (int)$alert['days_overdue'] ?> days</td>
                                        <td><span class="badge bg-<?= $alert['status'] === 'resolved' ? 'success' : ($alert['status'] === 'sent' ? 'info' : 'warning') ?>"><?= h(ucfirst($alert['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('collFilterForm');
    var from = document.getElementById('collFrom');
    var to   = document.getElementById('collTo');

    // Dropdowns and date boxes apply straight away; search applies on Enter / Apply.
    document.querySelectorAll('.coll-autosubmit').forEach(function (el) {
        el.addEventListener('change', function () { form.submit(); });
    });
    [from, to].forEach(function (el) { el.addEventListener('change', function () { form.submit(); }); });

    function ymd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    document.querySelectorAll('.coll-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var now = new Date(), f = '', t = '';
            switch (btn.dataset.preset) {
                case 'this_month': f = ymd(new Date(now.getFullYear(), now.getMonth(), 1)); t = ymd(now); break;
                case 'last_month': f = ymd(new Date(now.getFullYear(), now.getMonth() - 1, 1)); t = ymd(new Date(now.getFullYear(), now.getMonth(), 0)); break;
                case 'last_30':    f = ymd(new Date(now.getTime() - 30 * 864e5)); t = ymd(now); break;
                case 'last_90':    f = ymd(new Date(now.getTime() - 90 * 864e5)); t = ymd(now); break;
                case 'this_year':  f = ymd(new Date(now.getFullYear(), 0, 1)); t = ymd(now); break;
            }
            from.value = f; to.value = t;
            form.submit();
        });
    });

    // Tabs: remember the last one opened; otherwise open the first tab that has rows.
    var key = 'collections.activeTab';
    var counts = <?= json_encode(array_map(fn($t) => $t['count'], $tabs)) ?>;
    function show(name) {
        var btn = document.getElementById('coll-tab-' + name);
        if (!btn) return;
        bootstrap.Tab.getOrCreateInstance(btn).show();
        document.querySelectorAll('.coll-stat').forEach(function (c) { c.classList.toggle('active', c.dataset.collTab === name); });
    }
    document.querySelectorAll('.coll-tabs [data-coll-key]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function () {
            try { sessionStorage.setItem(key, btn.dataset.collKey); } catch (e) {}
            document.querySelectorAll('.coll-stat').forEach(function (c) { c.classList.toggle('active', c.dataset.collTab === btn.dataset.collKey); });
        });
    });
    document.querySelectorAll('.coll-stat').forEach(function (card) {
        card.addEventListener('click', function () { show(card.dataset.collTab); });
    });
    var saved = null;
    try { saved = sessionStorage.getItem(key); } catch (e) {}
    if (!saved || !(saved in counts)) {
        saved = Object.keys(counts).find(function (k) { return counts[k] > 0; }) || 'installments';
    }
    // ---- Live search: hide non-matching rows in every tab and keep counts/totals in step ----
    var search = document.getElementById('collSearch');
    var money = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    function refreshTotals() {
        var overdue = 0;
        Object.keys(counts).forEach(function (name) {
            var pane = document.getElementById('coll-pane-' + name);
            var rows = pane ? pane.querySelectorAll('tbody tr:not(.coll-hidden)') : [];
            var sum = 0;
            rows.forEach(function (r) { sum += parseFloat(r.dataset.amount || 0); });
            if (name === 'installments' || name === 'billing' || name === 'invoices') overdue += sum;
            var badge = document.querySelector('#coll-tab-' + name + ' [data-tab-count]');
            if (badge) badge.textContent = rows.length;
            var card = document.querySelector('.coll-stat[data-coll-tab="' + name + '"]');
            if (card) {
                card.querySelector('[data-stat-count]').textContent = rows.length;
                var s = card.querySelector('[data-stat-sum]');
                if (s) s.textContent = money(sum);
            }
            if (pane) {
                var fc = pane.querySelector('.coll-foot-count'), fs = pane.querySelector('.coll-foot-sum');
                if (fc) fc.textContent = rows.length;
                if (fs) fs.textContent = money(sum);
            }
        });
        document.getElementById('collTotalOverdue').textContent = money(overdue);
    }
    var searchTimer = null;
    search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            var q = search.value.trim().toLowerCase();
            document.querySelectorAll('.coll-table tbody tr').forEach(function (r) {
                r.classList.toggle('coll-hidden', q !== '' && r.textContent.toLowerCase().indexOf(q) === -1);
            });
            refreshTotals();
        }, 150);
    });

    // ---- Sort by clicking a column heading ----
    document.querySelectorAll('.coll-table th[data-sort]').forEach(function (th) {
        th.addEventListener('click', function () {
            var table = th.closest('table'), tbody = table.tBodies[0];
            var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
            var dir = th.classList.contains('asc') ? -1 : 1;
            table.querySelectorAll('th[data-sort]').forEach(function (h) { h.classList.remove('asc', 'desc'); });
            th.classList.add(dir === 1 ? 'asc' : 'desc');
            var val = function (tr) {
                var td = tr.children[idx];
                return td.dataset.v !== undefined ? td.dataset.v : td.textContent.trim().toLowerCase();
            };
            Array.from(tbody.rows).sort(function (a, b) {
                var x = val(a), y = val(b), nx = parseFloat(x), ny = parseFloat(y);
                var numeric = x !== '' && y !== '' && !isNaN(nx) && !isNaN(ny) && /^-?[\d.]+$/.test(x) && /^-?[\d.]+$/.test(y);
                return (numeric ? nx - ny : x.localeCompare(y)) * dir;
            }).forEach(function (tr) { tbody.appendChild(tr); });
        });
    });

    // ---- Export the open tab (visible rows only) to CSV ----
    document.getElementById('collExport').addEventListener('click', function () {
        var pane = document.querySelector('.tab-pane.active');
        var table = pane && pane.querySelector('table');
        if (!table) { alert('Nothing to export in this tab.'); return; }
        var clean = function (el) { return el.innerText.replace(/\s*\n\s*/g, ' · ').trim(); };
        var heads = Array.from(table.tHead.rows[0].cells);
        var keep = heads.map(function (h) { return h.textContent.trim() !== ''; });
        var lines = [heads.filter(function (h, i) { return keep[i]; }).map(clean)];
        table.querySelectorAll('tbody tr:not(.coll-hidden)').forEach(function (tr) {
            lines.push(Array.from(tr.cells).filter(function (c, i) { return keep[i]; }).map(clean));
        });
        var csv = lines.map(function (row) {
            return row.map(function (v) { return '"' + v.replace(/"/g, '""') + '"'; }).join(',');
        }).join('\r\n');
        var tabName = document.querySelector('.coll-tabs .nav-link.active').dataset.collKey;
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
        a.download = 'collections-' + tabName + '-' + ymd(new Date()) + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
    });

    show(saved);
});
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
