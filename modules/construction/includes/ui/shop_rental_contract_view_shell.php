<?php
/**
 * Shop Rental Contract View — executive IA shell (presentation only).
 * Expects all contract view variables already prepared by shop_rental_contract_view.php.
 * Partials under includes/ui/contract_view/ preserve original forms/actions.
 */
$cvTab = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? 'overview')) ?: 'overview';
$allowedTabs = ['overview','lifecycle','finance','shops','cheques','deposits','commission','charges','concession','schedule','diagnostics','timeline'];
if (!in_array($cvTab, $allowedTabs, true)) {
    $cvTab = 'overview';
}
$statusFlow = ['draft','active','renewed','expired','terminated','archived'];
$curStatus = (string)($contract['status'] ?? 'draft');
$statusIdx = array_search($curStatus, $statusFlow, true);
if ($statusIdx === false) {
    $statusIdx = 0;
}
$warnCount = count($diagnostics ?: []) + ($pastEndDate ? 1 : 0) + count($warn ?: []);
$durationLabel = co_shop_contract_month_count((string)($contract['start_date'] ?? ''), (string)($contract['end_date'] ?? '')) . ' mo';
?>
<style>
.co-cv-layout { display:grid; grid-template-columns: minmax(0,1fr) 300px; gap:1.25rem; align-items:start; }
@media (max-width: 1199px) { .co-cv-layout { grid-template-columns: 1fr; } .co-cv-aside { order:-1; display:grid; grid-template-columns:1fr 1fr; gap:1rem; } }
@media (max-width: 767px) { .co-cv-aside { grid-template-columns:1fr; } }
.co-cv-aside { position:sticky; top:1rem; }
@media (max-width: 1199px) { .co-cv-aside { position:static; } }
.co-cv-ready .co-cv-panel { display:none !important; }
.co-cv-ready .co-cv-panel.active { display:block !important; }
.co-cv-fin-row { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:.65rem; margin-bottom:.65rem; }
.co-cv-fin-row.cash { grid-template-columns:repeat(6,minmax(0,1fr)); }
.co-cv-fin-row.comm { grid-template-columns:repeat(3,minmax(0,1fr)); max-width:42rem; }
@media (max-width: 991px) { .co-cv-fin-row, .co-cv-fin-row.cash { grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (max-width: 575px) { .co-cv-fin-row, .co-cv-fin-row.cash, .co-cv-fin-row.comm { grid-template-columns:repeat(2,minmax(0,1fr)); } }
.co-cv-kpi { background:var(--co-bg-card); border:1px solid var(--co-border); border-radius:var(--co-radius-sm); padding:.75rem .85rem; min-height:4.5rem; }
.co-cv-kpi .l { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; color:var(--co-text-muted); margin-bottom:.2rem; }
.co-cv-kpi .v { font-family:var(--co-font-display); font-size:1.05rem; font-weight:700; color:var(--co-text); line-height:1.2; }
.co-cv-kpi .s { font-size:.72rem; color:var(--co-text-dim); margin-top:.15rem; }
.co-cv-kpi.tone-ok .v { color:#34d399; }
.co-cv-kpi.tone-warn .v { color:var(--co-gold-soft); }
.co-cv-kpi.tone-danger .v { color:var(--co-danger); }
.co-cv-kpi.tone-info .v { color:#a78bfa; }
.co-life-flow { display:flex; flex-wrap:wrap; gap:.35rem; align-items:center; margin-bottom:1rem; }
.co-life-node { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .7rem; border-radius:999px; border:1px solid var(--co-border); color:var(--co-text-dim); font-size:.75rem; font-weight:600; }
.co-life-node.done { border-color:rgba(16,185,129,.35); color:#6ee7b7; background:var(--co-teal-dim); }
.co-life-node.current { border-color:var(--co-gold); color:var(--co-gold-soft); background:var(--co-gold-dim); box-shadow:0 0 0 2px rgba(212,175,55,.15); }
.co-life-arrow { color:var(--co-text-dim); font-size:.75rem; }
.co-qa-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
.co-qa-grid .btn { min-height:auto; padding:.55rem .5rem; font-size:.78rem; white-space:normal; }
.co-cv-tabs { flex-wrap:nowrap; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
.co-cv-tabs .co-tab { white-space:nowrap; flex:0 0 auto; }
.co-cv-warn-strip { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; }
.co-cv-warn-chip {
  display:inline-flex; align-items:center;
  font-size:.78rem; font-weight:600; line-height:1.35; opacity:1;
  padding:.35rem .65rem; border-radius:999px;
  background:var(--co-badge-warn-bg, color-mix(in srgb, var(--erp-warning, var(--co-warn)) 16%, var(--erp-card-bg, var(--co-bg-card))));
  border:1px solid var(--co-badge-warn-bd, color-mix(in srgb, var(--erp-warning, var(--co-warn)) 42%, transparent));
  color:var(--co-badge-warn-fg, color-mix(in srgb, var(--erp-warning, var(--co-warn)) 72%, #0f172a));
}
.shop-health-item { display:flex; gap:.65rem; align-items:flex-start; padding:.5rem 0; border-bottom:1px solid var(--co-border); }
.shop-health-item:last-child { border-bottom:0; }
.shop-dot { width:10px; height:10px; border-radius:50%; flex:0 0 auto; margin-top:.4rem; background:#64748b; }
.shop-dot.success { background:#34d399; box-shadow:0 0 0 3px rgba(52,211,153,.22); }
.shop-dot.warning { background:#fbbf24; box-shadow:0 0 0 3px rgba(251,191,36,.25); }
.shop-dot.error, .shop-dot.danger { background:#f87171; box-shadow:0 0 0 3px rgba(248,113,113,.25); }
.shop-health-item.status-success .fw-semibold { color:#6ee7b7; }
.shop-health-item.status-warning .fw-semibold { color:#fde68a; }
.shop-health-item.status-error .fw-semibold,
.shop-health-item.status-danger .fw-semibold { color:#fca5a5; }
.shop-timeline { position:relative; padding-left:.25rem; }
.shop-tl-item { position:relative; padding:.55rem .25rem .55rem .95rem; border-left:2px solid var(--co-border); margin-left:.35rem; }
.shop-tl-item::before { content:''; position:absolute; left:-5px; top:.85rem; width:8px; height:8px; border-radius:50%; background:#64748b; border:2px solid var(--co-bg-card); }
.shop-tl-item.tone-success::before { background:#34d399; }
.shop-tl-item.tone-warning::before, .shop-tl-item.tone-warn::before { background:#fbbf24; }
.shop-tl-item.tone-danger::before, .shop-tl-item.tone-error::before { background:#f87171; }
.shop-tl-item.tone-primary::before, .shop-tl-item.tone-info::before { background:#60a5fa; }
@media print {
  .co-cv-layout { display:block !important; }
  .co-cv-aside, .co-cv-tabs, .no-print { display:none !important; }
  .co-cv-ready .co-cv-panel { display:block !important; }
}
</style>

<div class="co-page-header no-print">
    <div>
        <div class="co-crumb">
            <a href="shop_rental_control_center.php">Control Center</a><span>/</span>
            <a href="shop_rental_contracts.php">Contracts</a><span>/</span>
            <span><?= h($contract['contract_number']) ?></span>
        </div>
        <h1 class="d-flex flex-wrap align-items-center gap-2">
            <?= h($contract['contract_number']) ?>
            <?= co_ui_status_pill($curStatus) ?>
        </h1>
        <p class="co-page-sub">
            <strong style="color:var(--co-text)"><?= h($shopsLabel ?: $contract['shop_number']) ?></strong>
            · <?= h($contract['client_name']) ?>
        </p>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
            <span class="co-pill co-pill-expired"><?= count($contractShops) ?> shop<?= count($contractShops) === 1 ? '' : 's' ?></span>
            <span class="co-pill co-pill-expired"><?= h($vatMethodLabel) ?></span>
            <span class="co-pill co-pill-expired"><?= h(ucfirst($contract['vat_mode'] ?? 'exclusive')) ?> VAT</span>
            <span class="co-pill co-pill-expired"><?= !empty($contract['accrual_deferred_rent']) ? 'Deferred rent' : 'Direct income' ?></span>
            <?php if ($health): ?>
            <span class="co-pill <?= $health['overall'] === 'success' ? 'co-pill-active' : ($health['overall'] === 'warning' ? 'co-pill-warn' : 'co-pill-terminated') ?>">
                <?= $health['overall'] === 'success' ? 'Healthy' : ('Health: ' . ucfirst($health['overall'])) ?>
            </span>
            <?php endif; ?>
            <?php if ($warnCount > 0): ?>
            <a class="co-pill co-pill-warn" href="?id=<?= (int)$id ?>&tab=diagnostics"><?= (int)$warnCount ?> attention</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="co-page-actions">
        <a class="btn btn-outline-secondary btn-sm" href="?id=<?= (int)$id ?>&print=1" onclick="window.print(); return false;"><i data-lucide="printer" style="width:14px;height:14px"></i> Print</a>
        <a class="btn btn-outline-secondary btn-sm" href="?id=<?= (int)$id ?>&tab=finance#payment-manager"><i data-lucide="wallet" style="width:14px;height:14px"></i> Payment Manager</a>
        <div class="dropdown">
            <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Actions</button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <?php if ($contract['status'] === 'draft'): ?>
                <li>
                    <form method="post" class="px-3 py-1"><?php csrf_field(); ?>
                        <input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="active">
                        <button class="dropdown-item px-0" <?= $phase175Ready ? '' : 'disabled' ?>><?= !empty($contract['parent_contract_id']) ? 'Activate Renewal' : 'Activate Contract' ?></button>
                    </form>
                </li>
                <?php endif; ?>
                <?php if ($contract['status'] === 'active' && co_shop_phase2a_schema_ready($conn)): ?>
                <li><a class="dropdown-item" href="shop_rental_renew.php?id=<?= $id ?>">Renew Contract</a></li>
                <li><a class="dropdown-item" href="shop_rental_terminate.php?id=<?= $id ?>">Early Termination</a></li>
                <li><a class="dropdown-item" href="shop_rental_move_out_inspection.php?id=<?= $id ?>">Move-Out Inspection</a></li>
                <li><a class="dropdown-item" href="shop_rental_deposit_settle.php?id=<?= $id ?>">Settle Deposit</a></li>
                <li><hr class="dropdown-divider"></li>
                <?php endif; ?>
                <?php if ($contract['status'] === 'active'): ?>
                <li>
                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Mark this contract expired and release shops if unused?');"><?php csrf_field(); ?>
                        <input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="expired">
                        <button class="dropdown-item px-0 text-warning" <?= $phase175Ready ? '' : 'disabled' ?>>Mark Expired</button>
                    </form>
                </li>
                <?php endif; ?>
                <?php if (in_array($contract['status'], ['expired', 'terminated', 'renewed'], true) && co_shop_phase2a_schema_ready($conn)): ?>
                <li>
                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Archive this contract?');"><?php csrf_field(); ?>
                        <input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="archived">
                        <button class="dropdown-item px-0">Archive</button>
                    </form>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <?php if (!empty($deleteEligibility['allowed'])): ?>
                <?php
                    $p = $deleteEligibility['purge'] ?? [];
                    $purgeHint = 'Invoices ' . (int)($p['invoices'] ?? 0)
                        . ', payments ' . (int)($p['payments'] ?? 0)
                        . ', journals ' . (int)($p['journals'] ?? 0)
                        . ', deposits ' . (int)($p['deposit_receipts'] ?? 0);
                ?>
                <li>
                    <form method="post" class="px-3 py-1" onsubmit="var t=prompt('PURGE <?= h($contract['contract_number']) ?> and ALL linked financial data?\n<?= h($purgeHint) ?>\n\nType DELETE to confirm:'); if(!t||t.toUpperCase()!=='DELETE'){alert('Cancelled.'); return false;} this.querySelector('[name=confirm_purge]').value='DELETE'; return true;"><?php csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_contract">
                        <input type="hidden" name="confirm_purge" value="">
                        <button class="dropdown-item px-0 text-danger">Delete Contract (purge GL)</button>
                    </form>
                </li>
                <?php else: ?>
                <li>
                    <span class="dropdown-item-text text-muted small" title="<?= h(implode(' ', $deleteEligibility['blockers'] ?? [])) ?>">
                        Delete blocked: <?= h(implode(' ', $deleteEligibility['blockers'] ?? ['unavailable'])) ?>
                    </span>
                </li>
                <?php endif; ?>
                <li><a class="dropdown-item" href="shop_units.php">Shop Units</a></li>
                <li><a class="dropdown-item" href="shop_rental_contracts.php">All Contracts</a></li>
            </ul>
        </div>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success no-print py-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger no-print py-2"><?= h($err) ?></div><?php endif; ?>
<?php if ($warn): ?><div class="alert alert-warning no-print py-2"><strong>Draft overlap</strong><ul class="mb-0 small"><?php foreach ($warn as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($pastEndDate): ?>
<div class="alert alert-warning no-print py-2 mb-3"><strong>Past end date</strong> — still Active since <?= h($contract['end_date']) ?>. Use Mark Expired when ready.</div>
<?php endif; ?>
<?php if (!$phase1Ready): ?><div class="alert alert-warning no-print py-2">Run <code>migrations/construction_shop_rental_phase1.sql</code>.</div><?php endif; ?>
<?php if ($phase1Ready && !$phase175Ready): ?><div class="alert alert-warning no-print py-2">Run <code>migrations/construction_shop_rental_phase175.sql</code>.</div><?php endif; ?>

<?php if ($diagnostics): ?>
<div class="co-cv-warn-strip no-print">
    <?php foreach (array_slice($diagnostics, 0, 4) as $w): ?>
        <span class="co-cv-warn-chip" title="<?= h($w['detail']) ?>"><?= h($w['title']) ?></span>
    <?php endforeach; ?>
    <?php if (count($diagnostics) > 4): ?><span class="co-cv-warn-chip">+<?= count($diagnostics) - 4 ?> more</span><?php endif; ?>
</div>
<?php endif; ?>

<!-- Financial Overview (always visible) -->
<div class="mb-3" id="contract-financial-overview">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong style="color:var(--co-gold-soft); font-size:.8rem; letter-spacing:.06em; text-transform:uppercase;">Financial Overview</strong>
        <span class="text-muted small">Read-only · live documents</span>
    </div>
    <div class="co-cv-fin-row">
        <div class="co-cv-kpi"><div class="l">Net Contract Rent</div><div class="v"><?= co_format_money($contract['rent_amount']) ?></div><div class="s">Plan <?= h($contract['payment_frequency'] ?? '') ?></div></div>
        <div class="co-cv-kpi"><div class="l">Monthly Equivalent</div><div class="v"><?= co_format_money(co_shop_monthly_equivalent_net($contract)) ?></div><div class="s"><?= (!empty($concessionStatus['enabled'])) ? 'Total ÷ chargeable months' : 'Total ÷ months' ?></div></div>
        <div class="co-cv-kpi"><div class="l">VAT Method</div><div class="v" style="font-size:.9rem"><?= h($vatMethodLabel) ?></div><div class="s"><?= h(ucfirst($contract['vat_mode'] ?? 'exclusive')) ?></div></div>
        <div class="co-cv-kpi"><div class="l">Duration</div><div class="v"><?= h($durationLabel) ?></div><div class="s"><?= h($contract['start_date']) ?> → <?= h($contract['end_date']) ?><?= (!empty($concessionStatus['enabled'])) ? (' · ' . (int)$concessionStatus['chargeable_months'] . ' chargeable') : '' ?></div></div>
        <div class="co-cv-kpi"><div class="l">Security Deposit</div><div class="v"><?= co_format_money($contract['security_deposit']) ?></div><div class="s">Required</div></div>
        <div class="co-cv-kpi"><div class="l">Total Invoiced</div><div class="v"><?= $summary ? co_format_money($summary['invoiced']) : '—' ?></div><div class="s"><?= $summary ? ('VAT ' . strip_tags(co_format_money($summary['vat_invoiced']))) : '' ?></div></div>
    </div>
    <div class="co-cv-fin-row cash">
        <div class="co-cv-kpi tone-ok"><div class="l">Collected</div><div class="v"><?= $summary ? co_format_money($summary['collected']) : '—' ?></div></div>
        <div class="co-cv-kpi <?= (!empty($summary['outstanding']) && $summary['outstanding'] > 0.005) ? 'tone-warn' : '' ?>"><div class="l">Outstanding</div><div class="v"><?= $summary ? co_format_money($summary['outstanding']) : '—' ?></div></div>
        <div class="co-cv-kpi <?= (!empty($summary['overdue']) && $summary['overdue'] > 0.005) ? 'tone-danger' : '' ?>"><div class="l">Overdue</div><div class="v"><?= $summary ? co_format_money($summary['overdue']) : '—' ?></div></div>
        <div class="co-cv-kpi"><div class="l">Tenant Credit</div><div class="v"><?= $summary ? co_format_money($summary['client_credit']) : '—' ?></div></div>
        <div class="co-cv-kpi"><div class="l">Deferred Revenue</div><div class="v"><?= $summary ? co_format_money($summary['deferred_revenue']) : '—' ?></div><div class="s"><?= !empty($contract['accrual_deferred_rent']) ? '2215' : 'Direct' ?></div></div>
        <div class="co-cv-kpi tone-info"><div class="l">Deposit Held</div><div class="v"><?= $summary ? co_format_money($summary['deposit_received']) : co_format_money($contract['deposit_received_amount']) ?></div><div class="s">of <?= strip_tags(co_format_money($contract['security_deposit'])) ?></div></div>
    </div>
    <?php if ($commissionStatus): ?>
    <div class="co-cv-fin-row comm">
        <div class="co-cv-kpi"><div class="l">Commission Net</div><div class="v"><?= co_format_money($commissionStatus['net'] ?? 0) ?></div><div class="s"><?= h(ucfirst(str_replace('_', ' ', $commissionStatus['status'] ?? ''))) ?></div></div>
        <div class="co-cv-kpi tone-ok"><div class="l">Commission Collected</div><div class="v"><?= co_format_money($commissionStatus['collected'] ?? 0) ?></div></div>
        <div class="co-cv-kpi <?= (($commissionStatus['outstanding'] ?? 0) > 0.005) ? 'tone-warn' : '' ?>"><div class="l">Commission Outstanding</div><div class="v"><?= co_format_money($commissionStatus['outstanding'] ?? 0) ?></div></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($keyMoneyStatus) && (!empty($keyMoneyStatus['enabled']) || !empty($keyMoneyStatus['invoice']) || (($keyMoneyStatus['net'] ?? 0) > 0))): ?>
    <div class="co-cv-fin-row comm">
        <div class="co-cv-kpi"><div class="l">Key Money Net</div><div class="v"><?= co_format_money($keyMoneyStatus['net'] ?? 0) ?></div><div class="s"><?= h(ucfirst(str_replace('_', ' ', $keyMoneyStatus['status'] ?? ''))) ?> · 4170</div></div>
        <div class="co-cv-kpi tone-ok"><div class="l">Key Money Paid</div><div class="v"><?= co_format_money($keyMoneyStatus['collected'] ?? 0) ?></div></div>
        <div class="co-cv-kpi <?= (($keyMoneyStatus['outstanding'] ?? 0) > 0.005) ? 'tone-warn' : '' ?>"><div class="l">Key Money Outstanding</div><div class="v"><?= co_format_money($keyMoneyStatus['outstanding'] ?? 0) ?></div></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($concessionStatus) && !empty($concessionStatus['enabled'])): ?>
    <div class="co-cv-fin-row comm">
        <div class="co-cv-kpi"><div class="l">Concession</div><div class="v" style="font-size:.85rem"><?= h($concessionStatus['from']) ?> → <?= h($concessionStatus['to']) ?></div><div class="s"><?= h($concessionStatus['reason_label'] ?? '') ?> · <?= (int)$concessionStatus['concession_months'] ?> free mo</div></div>
        <div class="co-cv-kpi"><div class="l">Chargeable Period</div><div class="v" style="font-size:.85rem"><?= h($concessionStatus['chargeable_from']) ?> → <?= h($concessionStatus['chargeable_to']) ?></div><div class="s"><?= (int)$concessionStatus['chargeable_months'] ?> mo<?= !empty($concessionStatus['chargeable_has_gap']) ? ' · gap' : '' ?></div></div>
        <div class="co-cv-kpi tone-info"><div class="l">Concession Value</div><div class="v"><?= co_format_money($concessionStatus['value_total'] ?? 0) ?></div><div class="s"><?= !empty($concessionStatus['value_stored']) ? 'Stored' : 'Estimate' ?></div></div>
    </div>
    <?php endif; ?>
</div>

<div id="coCvShell" class="co-cv-layout co-cv-ready" data-cv-current="<?= h($cvTab) ?>">

<div class="co-cv-main min-w-0">
    <nav class="co-tabs co-cv-tabs no-print mb-3" aria-label="Contract workspace">
        <?php
        $tabs = [
            'overview' => 'Overview',
            'lifecycle' => 'Lifecycle',
            'finance' => 'Finance',
            'shops' => 'Shops & Units',
            'cheques' => 'Payment Plan',
            'schedule' => 'Invoices / Schedule',
            'deposits' => 'Deposits',
            'commission' => 'Commission',
            'charges' => 'Charges',
            'concession' => 'Concession',
            'diagnostics' => 'Diagnostics',
            'timeline' => 'Timeline',
        ];
        foreach ($tabs as $k => $label):
        ?>
        <button type="button" class="co-tab<?= $cvTab === $k ? ' active' : '' ?>" data-cv-tab="<?= h($k) ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
    </nav>

    <!-- OVERVIEW (summary only — forms live in other tabs) -->
    <div class="co-cv-panel<?= $cvTab === 'overview' ? ' active' : '' ?>" id="cv-panel-overview" data-cv-panel="overview">
        <div class="row g-3 mb-3">
            <div class="col-lg-5">
                <div class="card card-round h-100 print-shops">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <strong class="shop-section-title">Contract Shops</strong>
                        <button type="button" class="btn btn-link btn-sm py-0 no-print" data-cv-tab="shops">Edit</button>
                    </div>
                    <div class="card-body py-2">
                        <ul class="mb-0">
                            <?php foreach ($contractShops as $s): ?>
                                <li>
                                    <strong><?= h($s['shop_number']) ?></strong>
                                    <?= !empty($s['shop_name']) ? ' — ' . h($s['shop_name']) : '' ?>
                                    <?php if (!empty($s['is_primary'])): ?><span class="badge bg-primary">Primary</span><?php endif; ?>
                                    <span class="badge bg-light text-dark border"><?= h($s['unit_status'] ?? '') ?></span>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$contractShops): ?><li class="text-muted">No shops linked.</li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card card-round h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong class="shop-section-title">Status &amp; Occupancy</strong>
                        <button type="button" class="btn btn-link btn-sm py-0 no-print" data-cv-tab="lifecycle">Manage</button>
                    </div>
                    <div class="card-body">
                        <div class="co-life-flow">
                            <?php foreach ($statusFlow as $i => $st):
                                if ($i > 0) echo '<span class="co-life-arrow">→</span>';
                                $cls = $i < $statusIdx ? 'done' : ($i === $statusIdx ? 'current' : '');
                            ?>
                                <span class="co-life-node <?= $cls ?>"><?= h(ucfirst($st)) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <?php if ($contract['status'] === 'active' && co_shop_phase2a_schema_ready($conn)): ?>
                                <a class="btn btn-sm btn-outline-primary" href="shop_rental_renew.php?id=<?= $id ?>">Renew Contract</a>
                                <a class="btn btn-sm btn-outline-warning" href="shop_rental_terminate.php?id=<?= $id ?>">Early Termination</a>
                                <a class="btn btn-sm btn-outline-secondary" href="shop_rental_move_out_inspection.php?id=<?= $id ?>">Move-out Inspection</a>
                                <a class="btn btn-sm btn-outline-secondary" href="shop_rental_deposit_settle.php?id=<?= $id ?>">Settle Deposit</a>
                            <?php elseif ($contract['status'] === 'draft'): ?>
                                <button type="button" class="btn btn-sm btn-success" data-cv-tab="lifecycle">Activate…</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-cv-tab="lifecycle">Lifecycle actions</button>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted small mb-0">
                            <?php if (!empty($contract['parent_contract_id'])): ?>
                                Renewal of <a href="shop_rental_contract_view.php?id=<?= (int)$contract['parent_contract_id'] ?>">#<?= (int)$contract['parent_contract_id'] ?></a>.
                            <?php endif; ?>
                            <?php if (!empty($contract['renewed_to_contract_id'])): ?>
                                Renewed to <a href="shop_rental_contract_view.php?id=<?= (int)$contract['renewed_to_contract_id'] ?>">#<?= (int)$contract['renewed_to_contract_id'] ?></a>.
                            <?php endif; ?>
                            Shops occupy only while Active. Full status forms are on the Lifecycle tab.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($commissionStatus): ?>
        <div class="card card-round mb-3">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong class="shop-section-title">Tenant Commission</strong>
                <button type="button" class="btn btn-link btn-sm py-0" data-cv-tab="commission">Configure</button>
            </div>
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <div class="col-4 col-md-2"><div class="text-muted">Net</div><strong><?= co_format_money($commissionStatus['net'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">VAT</div><strong><?= co_format_money($commissionStatus['vat'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Invoiced</div><strong><?= co_format_money($commissionStatus['invoiced'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Collected</div><strong><?= co_format_money($commissionStatus['collected'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Outstanding</div><strong><?= co_format_money($commissionStatus['outstanding'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Status</div><strong><?= h(ucfirst(str_replace('_', ' ', $commissionStatus['status'] ?? 'n/a'))) ?></strong></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($keyMoneyStatus) && (!empty($keyMoneyStatus['enabled']) || !empty($keyMoneyStatus['invoice']) || (($keyMoneyStatus['net'] ?? 0) > 0))): ?>
        <div class="card card-round mb-3">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong class="shop-section-title">Key Money</strong>
                <button type="button" class="btn btn-link btn-sm py-0" data-cv-tab="charges">Charges</button>
            </div>
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <div class="col-4 col-md-2"><div class="text-muted">Net</div><strong><?= co_format_money($keyMoneyStatus['net'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">VAT</div><strong><?= co_format_money($keyMoneyStatus['vat'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Invoiced</div><strong><?= co_format_money($keyMoneyStatus['invoiced'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Paid</div><strong><?= co_format_money($keyMoneyStatus['collected'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Outstanding</div><strong><?= co_format_money($keyMoneyStatus['outstanding'] ?? 0) ?></strong></div>
                    <div class="col-4 col-md-2"><div class="text-muted">Status</div><strong><?= h(ucfirst(str_replace('_', ' ', $keyMoneyStatus['status'] ?? 'n/a'))) ?></strong></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($concessionStatus) && !empty($concessionStatus['enabled'])): ?>
        <div class="card card-round mb-3">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong class="shop-section-title">Rent Concession</strong>
                <button type="button" class="btn btn-link btn-sm py-0" data-cv-tab="concession">Configure</button>
            </div>
            <div class="card-body py-2">
                <div class="row g-2 small">
                    <div class="col-6 col-md-3"><div class="text-muted">Window</div><strong><?= h($concessionStatus['from']) ?> → <?= h($concessionStatus['to']) ?></strong></div>
                    <div class="col-6 col-md-3"><div class="text-muted">Reason</div><strong><?= h($concessionStatus['reason_label'] ?? '—') ?></strong></div>
                    <div class="col-6 col-md-3"><div class="text-muted">Chargeable</div><strong><?= (int)$concessionStatus['chargeable_months'] ?> / <?= (int)$concessionStatus['occupancy_months'] ?> mo</strong></div>
                    <div class="col-6 col-md-3"><div class="text-muted">Value</div><strong><?= co_format_money($concessionStatus['value_total'] ?? 0) ?></strong></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- LIFECYCLE (canonical status forms — only here) -->
    <div class="co-cv-panel<?= $cvTab === 'lifecycle' ? ' active' : '' ?>" id="cv-panel-lifecycle" data-cv-panel="lifecycle">
        <div class="co-life-flow mb-3">
            <?php foreach ($statusFlow as $i => $st):
                if ($i > 0) echo '<span class="co-life-arrow">→</span>';
                $cls = $i < $statusIdx ? 'done' : ($i === $statusIdx ? 'current' : '');
            ?>
                <span class="co-life-node <?= $cls ?>"><?= h(ucfirst($st)) ?></span>
            <?php endforeach; ?>
        </div>
        <?php include __DIR__ . '/contract_view/_lifecycle_status.php'; ?>
        <p class="text-muted small no-print">Edit shops &amp; dates under the <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-cv-tab="shops">Shops &amp; Units</button> tab.</p>
    </div>

    <!-- FINANCE -->
    <div class="co-cv-panel<?= $cvTab === 'finance' ? ' active' : '' ?>" id="cv-panel-finance" data-cv-panel="finance">
        <?php include __DIR__ . '/contract_view/_vat_method.php'; ?>
        <div class="mb-3"><?php include __DIR__ . '/contract_view/_accounting_nav.php'; ?></div>
        <?php include __DIR__ . '/contract_view/_payment_manager.php'; ?>
        <p class="text-muted small no-print mb-0">Monthly earning periods live under <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-cv-tab="schedule">Invoices / Schedule</button>.</p>
    </div>

    <!-- SHOPS (canonical edit form) -->
    <div class="co-cv-panel<?= $cvTab === 'shops' ? ' active' : '' ?>" id="cv-panel-shops" data-cv-panel="shops">
        <?php include __DIR__ . '/contract_view/_shops.php'; ?>
        <?php include __DIR__ . '/contract_view/_edit_shops.php'; ?>
    </div>

    <!-- CHEQUES -->
    <div class="co-cv-panel<?= $cvTab === 'cheques' ? ' active' : '' ?>" id="cv-panel-cheques" data-cv-panel="cheques">
        <?php include __DIR__ . '/contract_view/_cheques.php'; ?>
    </div>

    <!-- SCHEDULE -->
    <div class="co-cv-panel<?= $cvTab === 'schedule' ? ' active' : '' ?>" id="cv-panel-schedule" data-cv-panel="schedule">
        <?php include __DIR__ . '/contract_view/_schedule.php'; ?>
        <a class="btn btn-outline-secondary btn-sm no-print" href="?id=<?= (int)$id ?>&tab=finance#payment-manager">Open Payment Manager</a>
        <a class="btn btn-primary btn-sm no-print ms-1" href="shop_rental_payment_workspace.php?contract_id=<?= (int)$id ?>">Receive Payment</a>
    </div>

    <!-- DEPOSITS -->
    <div class="co-cv-panel<?= $cvTab === 'deposits' ? ' active' : '' ?>" id="cv-panel-deposits" data-cv-panel="deposits">
        <?php include __DIR__ . '/contract_view/_deposit.php'; ?>
        <?php if ($contract['status'] === 'active' && co_shop_phase2a_schema_ready($conn)): ?>
        <div class="co-quick-actions mt-3 no-print">
            <a class="co-qa" href="shop_rental_move_out_inspection.php?id=<?= $id ?>"><i data-lucide="clipboard-check" style="width:18px;height:18px"></i> Move-Out Inspection</a>
            <a class="co-qa" href="shop_rental_deposit_settle.php?id=<?= $id ?>"><i data-lucide="landmark" style="width:18px;height:18px"></i> Settle Deposit</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- COMMISSION (canonical form) -->
    <div class="co-cv-panel<?= $cvTab === 'commission' ? ' active' : '' ?>" id="cv-panel-commission" data-cv-panel="commission">
        <?php if ($commissionReady): ?>
            <?php include __DIR__ . '/contract_view/_commission.php'; ?>
        <?php elseif ($phase1Ready): ?>
            <div class="alert alert-info">Run <code>migrations/construction_shop_rental_phase16_commission.sql</code> to enable tenant commission.</div>
        <?php else: ?>
            <div class="co-empty">Commission requires Phase 1 schema.</div>
        <?php endif; ?>
    </div>

    <!-- CHARGES (Payment Maturity Phase 1 + Key Money) -->
    <div class="co-cv-panel<?= $cvTab === 'charges' ? ' active' : '' ?>" id="cv-panel-charges" data-cv-panel="charges">
        <?php include __DIR__ . '/contract_view/_key_money.php'; ?>
        <?php include __DIR__ . '/contract_view/_charges.php'; ?>
    </div>

    <!-- RENT CONCESSION -->
    <div class="co-cv-panel<?= $cvTab === 'concession' ? ' active' : '' ?>" id="cv-panel-concession" data-cv-panel="concession">
        <?php include __DIR__ . '/contract_view/_concession.php'; ?>
    </div>

    <!-- DIAGNOSTICS -->
    <div class="co-cv-panel<?= $cvTab === 'diagnostics' ? ' active' : '' ?>" id="cv-panel-diagnostics" data-cv-panel="diagnostics">
        <?php include __DIR__ . '/contract_view/_diagnostics.php'; ?>
        <?php if (!$diagnostics): ?><div class="alert alert-success py-2">No diagnostic warnings.</div><?php endif; ?>
    </div>

    <!-- TIMELINE -->
    <div class="co-cv-panel<?= $cvTab === 'timeline' ? ' active' : '' ?>" id="cv-panel-timeline" data-cv-panel="timeline">
        <?php include __DIR__ . '/contract_view/_timeline.php'; ?>
        <?php if (!$timeline): ?><div class="co-empty">No timeline events yet.</div><?php endif; ?>
    </div>
</div><!-- main -->

<aside class="co-cv-aside no-print">
    <div class="mb-3">
        <div class="card card-round">
            <div class="card-header bg-white"><strong class="shop-section-title">Quick Actions</strong></div>
            <div class="card-body">
                <div class="co-qa-grid">
                <?php foreach ($quickActions as $qa):
                    if (!$qa['enabled']) continue;
                    if ($qa['type'] === 'form'): ?>
                        <form method="post"><?php csrf_field(); ?>
                            <input type="hidden" name="action" value="<?= h($qa['action']) ?>">
                            <?php if (($qa['action'] ?? '') === 'generate_cheques_quick'): ?>
                                <input type="hidden" name="rent_cheque_count" value="<?= (int)($contract['rent_cheque_count'] ?? 0) ?>">
                                <input type="hidden" name="deposit_cheque_count" value="<?= (int)($contract['deposit_cheque_count'] ?? 0) ?>">
                            <?php endif; ?>
                            <button class="btn btn-outline-secondary btn-sm w-100"><?= h($qa['label']) ?></button>
                        </form>
                    <?php elseif ($qa['type'] === 'print'): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="window.print()"><?= h($qa['label']) ?></button>
                    <?php else: ?>
                        <a href="<?= h($qa['href'] ?? '#') ?>" class="btn btn-outline-secondary btn-sm w-100"><?= h($qa['label']) ?></a>
                    <?php endif;
                endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-3">
        <?php include __DIR__ . '/contract_view/_health.php'; ?>
        <div class="px-3 pb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100" data-cv-tab="diagnostics">Open Diagnostics</button>
        </div>
    </div>

    <div class="card card-round mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong class="shop-section-title">Timeline</strong>
            <button type="button" class="btn btn-link btn-sm py-0" data-cv-tab="timeline">Full</button>
        </div>
        <div class="card-body py-2">
            <?php if ($timeline): ?>
            <div class="shop-timeline">
                <?php foreach (array_slice($timeline, 0, 6) as $ev): ?>
                    <div class="shop-tl-item tone-<?= h($ev['tone'] ?? 'secondary') ?>">
                        <div class="fw-semibold small"><?= h($ev['label']) ?></div>
                        <?php if (!empty($ev['detail'])): ?>
                            <div class="small text-muted"><?= h($ev['detail']) ?></div>
                        <?php endif; ?>
                        <div class="small mt-1">
                            <span class="text-muted"><?= h($ev['date']) ?></span>
                            <?php if (!empty($ev['user']) && $ev['user'] !== '—'): ?>
                                · <span class="text-muted">by</span> <?= h($ev['user']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-muted small py-2">No timeline events yet.</div>
            <?php endif; ?>
        </div>
    </div>
</aside>

</div><!-- shell -->

<script>
(function () {
  var shell = document.getElementById('coCvShell');
  if (!shell) return;
  var allowed = <?= json_encode(array_values($allowedTabs)) ?>;
  var hashMap = {
    'payment-manager': 'finance',
    'deposit-section': 'deposits',
    'commission-section': 'commission',
    'cheque-plan': 'cheques',
    'rent-schedule': 'schedule',
    'vat-collection-method': 'finance',
    'contract-timeline': 'timeline',
    'status-occupancy': 'lifecycle'
  };

  function setTab(tab, pushUrl) {
    if (allowed.indexOf(tab) === -1) tab = 'overview';
    shell.setAttribute('data-cv-current', tab);
    shell.classList.add('co-cv-ready');
    shell.querySelectorAll('[data-cv-panel]').forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-cv-panel') === tab);
    });
    shell.querySelectorAll('button.co-tab[data-cv-tab]').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-cv-tab') === tab);
    });
    if (pushUrl !== false) {
      try {
        var u = new URL(window.location.href);
        u.searchParams.set('tab', tab);
        history.replaceState({}, '', u);
      } catch (e) {}
    }
  }

  shell.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cv-tab]');
    if (!btn || !shell.contains(btn)) return;
    if (btn.tagName === 'A' && btn.getAttribute('href') && btn.getAttribute('href').charAt(0) !== '#') return;
    e.preventDefault();
    setTab(btn.getAttribute('data-cv-tab'));
  });

  var hash = (location.hash || '').replace(/^#/, '');
  if (hash && hashMap[hash]) {
    setTab(hashMap[hash], true);
    var el = document.getElementById(hash);
    if (el) setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
  } else {
    setTab(shell.getAttribute('data-cv-current') || 'overview', false);
  }
})();
</script>
