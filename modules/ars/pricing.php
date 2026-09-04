<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_shell.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);
$arsCompany = get_company($conn, $arsCompanyId);
$currencyStmt = $conn->prepare("SELECT currency FROM ars_company_settings WHERE company_id = ? LIMIT 1");
$currencyStmt->execute([$arsCompanyId]);
$arsCurrency = (string)($currencyStmt->fetchColumn() ?: 'AED');

// Load units for dropdown
$units = ars_fetch_short_term_units($conn, $arsCompanyId);

// Load rules
$rules = $conn->prepare("
    SELECT r.*, u.unit_number, b.name AS building_name
    FROM ars_pricing_rules r
    LEFT JOIN re_units u ON u.id = r.unit_id
    LEFT JOIN re_buildings b ON b.id = u.building_id
    WHERE r.company_id = ?
    ORDER BY r.rule_type, r.priority DESC, r.name
");
$rules->execute([$arsCompanyId]);
$rules = $rules->fetchAll(PDO::FETCH_ASSOC);

$rulesByType = [
    'seasonal' => [],
    'weekend' => [],
    'length_discount' => [],
    'minimum_stay' => [],
];
foreach ($rules as $r) {
    $rulesByType[$r['rule_type']][] = $r;
}

// Load promo codes
$promos = $conn->prepare("SELECT * FROM ars_promo_codes WHERE company_id = ? ORDER BY code");
$promos->execute([$arsCompanyId]);
$promos = $promos->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pricing & Promos';
ars_shell_begin([
    'title' => 'Pricing & Promos',
    'subtitle' => 'Company: ' . ($arsCompany['name'] ?? ('#' . $arsCompanyId))
        . ' · Currency: ' . $arsCurrency
        . ' · Operational pricing rules',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Settings', 'href' => 'settings.php'],
        ['label' => 'Pricing'],
    ],
    'actions_html' => '<div class="d-flex gap-2 flex-wrap">'
        . '<button class="btn btn-ars btn-sm" data-bs-toggle="modal" data-bs-target="#ruleModal" onclick="openAddRule()"><i class="bi bi-plus-lg me-1"></i>Add Rule</button>'
        . '<button class="btn btn-ars-accent btn-sm" data-bs-toggle="modal" data-bs-target="#promoModal" onclick="openAddPromo()"><i class="bi bi-plus-lg me-1"></i>Add Promo Code</button>'
        . '</div>',
    'legacy_bootstrap' => true,
]);
?>


<div id="pageAlert"></div>

<!-- Seasonal Rules -->
<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-sun me-2"></i>Seasonal Rates</span>
        <span class="badge bg-primary"><?= count($rulesByType['seasonal']) ?></span>
    </div>
    <?php if (empty($rulesByType['seasonal'])): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-sun fs-3 d-block mb-2"></i>No seasonal pricing rules yet.
        <br><small>Create rules to automatically adjust nightly rates for specific date ranges.</small>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Name</th><th>Unit</th><th>Dates</th><th>Rate</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rulesByType['seasonal'] as $r): ?>
            <tr id="rule-row-<?= $r['id'] ?>">
                <td data-label="Name" class="fw-semibold"><?= h($r['name']) ?></td>
                <td data-label="Unit"><?= $r['unit_id'] ? h($r['unit_number'] . ' — ' . $r['building_name']) : '<span class="text-muted">All Units</span>' ?></td>
                <td data-label="Dates"><?= h($r['start_date']) ?> → <?= h($r['end_date']) ?></td>
                <td data-label="Rate"><?= h(arsFormatRuleValue($r)) ?></td>
                <td data-label="Priority"><span class="badge bg-secondary"><?= $r['priority'] ?></span></td>
                <td data-label="Status">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" <?= $r['is_active'] ? 'checked' : '' ?> onchange="toggleRule(<?= $r['id'] ?>)">
                    </div>
                </td>
                <td data-label="Actions">
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditRule(<?= htmlspecialchars(json_encode($r)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRule(<?= $r['id'] ?>, '<?= h($r['name']) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Weekend Rules -->
<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-week me-2"></i>Weekend Rates</span>
        <span class="badge bg-warning text-dark"><?= count($rulesByType['weekend']) ?></span>
    </div>
    <?php if (empty($rulesByType['weekend'])): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-calendar-week fs-3 d-block mb-2"></i>No weekend pricing rules.
        <br><small>Create rules to adjust rates on Friday and Saturday nights.</small>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Name</th><th>Unit</th><th>Rate</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rulesByType['weekend'] as $r): ?>
            <tr id="rule-row-<?= $r['id'] ?>">
                <td data-label="Name" class="fw-semibold"><?= h($r['name']) ?></td>
                <td data-label="Unit"><?= $r['unit_id'] ? h($r['unit_number'] . ' — ' . $r['building_name']) : '<span class="text-muted">All Units</span>' ?></td>
                <td data-label="Rate"><?= h(arsFormatRuleValue($r)) ?></td>
                <td data-label="Priority"><span class="badge bg-secondary"><?= $r['priority'] ?></span></td>
                <td data-label="Status">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" <?= $r['is_active'] ? 'checked' : '' ?> onchange="toggleRule(<?= $r['id'] ?>)">
                    </div>
                </td>
                <td data-label="Actions">
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditRule(<?= htmlspecialchars(json_encode($r)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRule(<?= $r['id'] ?>, '<?= h($r['name']) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Length-of-Stay Discounts -->
<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-percent me-2"></i>Length-of-Stay Discounts</span>
        <span class="badge bg-success"><?= count($rulesByType['length_discount']) ?></span>
    </div>
    <?php if (empty($rulesByType['length_discount'])): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-percent fs-3 d-block mb-2"></i>No length-of-stay discounts.
        <br><small>Offer automatic discounts for longer stays (e.g. 7+ nights = 10% off).</small>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Name</th><th>Unit</th><th>Discount</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rulesByType['length_discount'] as $r): ?>
            <tr id="rule-row-<?= $r['id'] ?>">
                <td data-label="Name" class="fw-semibold"><?= h($r['name']) ?></td>
                <td data-label="Unit"><?= $r['unit_id'] ? h($r['unit_number'] . ' — ' . $r['building_name']) : '<span class="text-muted">All Units</span>' ?></td>
                <td data-label="Discount"><?= h(arsFormatRuleValue($r)) ?></td>
                <td data-label="Priority"><span class="badge bg-secondary"><?= $r['priority'] ?></span></td>
                <td data-label="Status">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" <?= $r['is_active'] ? 'checked' : '' ?> onchange="toggleRule(<?= $r['id'] ?>)">
                    </div>
                </td>
                <td data-label="Actions">
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditRule(<?= htmlspecialchars(json_encode($r)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRule(<?= $r['id'] ?>, '<?= h($r['name']) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Minimum Stay Rules -->
<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-minus me-2"></i>Minimum Stay Rules</span>
        <span class="badge bg-danger"><?= count($rulesByType['minimum_stay']) ?></span>
    </div>
    <?php if (empty($rulesByType['minimum_stay'])): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-calendar-minus fs-3 d-block mb-2"></i>No minimum stay rules.
        <br><small>Enforce minimum night requirements globally or for specific periods.</small>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Name</th><th>Unit</th><th>Requirement</th><th>Period</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rulesByType['minimum_stay'] as $r): ?>
            <tr id="rule-row-<?= $r['id'] ?>">
                <td data-label="Name" class="fw-semibold"><?= h($r['name']) ?></td>
                <td data-label="Unit"><?= $r['unit_id'] ? h($r['unit_number'] . ' — ' . $r['building_name']) : '<span class="text-muted">All Units</span>' ?></td>
                <td data-label="Requirement"><?= h(arsFormatRuleValue($r)) ?></td>
                <td data-label="Period"><?= ($r['start_date'] && $r['end_date']) ? h($r['start_date']) . ' → ' . h($r['end_date']) : '<span class="text-muted">Always</span>' ?></td>
                <td data-label="Status">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" <?= $r['is_active'] ? 'checked' : '' ?> onchange="toggleRule(<?= $r['id'] ?>)">
                    </div>
                </td>
                <td data-label="Actions">
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditRule(<?= htmlspecialchars(json_encode($r)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRule(<?= $r['id'] ?>, '<?= h($r['name']) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Promo Codes -->
<div class="ars-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-ticket-perforated me-2"></i>Promo Codes</span>
        <span class="badge bg-info"><?= count($promos) ?></span>
    </div>
    <?php if (empty($promos)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-ticket-perforated fs-3 d-block mb-2"></i>No promo codes yet.
        <br><small>Create codes like SUMMER20 that guests can use for discounts.</small>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table ars-table ars-mobile-cards mb-0">
            <thead><tr><th>Code</th><th>Description</th><th>Discount</th><th>Max Cap</th><th>Uses</th><th>Validity</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($promos as $p): ?>
            <tr>
                <td data-label="Code"><span class="badge bg-dark fs-6 font-monospace"><?= h($p['code']) ?></span></td>
                <td data-label="Description"><?= h($p['description'] ?: '—') ?></td>
                <td data-label="Discount" class="fw-semibold">
                    <?php if ($p['discount_type'] === 'percentage'): ?>
                        <?= number_format((float)$p['discount_value'], 1) ?>%
                    <?php else: ?>
                        AED <?= number_format((float)$p['discount_value'], 2) ?>
                    <?php endif; ?>
                </td>
                <td data-label="Max Cap"><?= $p['max_discount_amount'] !== null ? 'AED ' . number_format((float)$p['max_discount_amount'], 2) : '<span class="text-muted">No limit</span>' ?></td>
                <td data-label="Uses"><?= (int)$p['times_used'] ?> / <?= $p['max_uses'] !== null ? (int)$p['max_uses'] : '∞' ?></td>
                <td data-label="Validity">
                    <?php if ($p['valid_from'] || $p['valid_to']): ?>
                        <?= h($p['valid_from'] ?: '—') ?> → <?= h($p['valid_to'] ?: '—') ?>
                    <?php else: ?>
                        <span class="text-muted">Always</span>
                    <?php endif; ?>
                </td>
                <td data-label="Status">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" <?= $p['is_active'] ? 'checked' : '' ?> onchange="togglePromo(<?= $p['id'] ?>)">
                    </div>
                </td>
                <td data-label="Actions">
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="openEditPromo(<?= htmlspecialchars(json_encode($p)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deletePromo(<?= $p['id'] ?>, '<?= h($p['code']) ?>')" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Promo Modal -->
<div class="modal fade" id="promoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-accent);color:#fff">
                <h5 class="modal-title" id="promoModalTitle"><i class="bi bi-ticket-perforated me-2"></i>Add Promo Code</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="promoId" value="">
                <div class="row g-3">
                    <div class="col-12 col-sm-4">
                        <label class="form-label fw-semibold">Code *</label>
                        <input type="text" id="promoCode" class="form-control text-uppercase font-monospace" placeholder="e.g. SUMMER20" maxlength="50" style="letter-spacing:0.1em">
                    </div>
                    <div class="col-12 col-sm-8">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" id="promoDescription" class="form-control" placeholder="e.g. Summer 2026 promotion">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label fw-semibold">Discount Type *</label>
                        <select id="promoDiscountType" class="form-select">
                            <option value="percentage">Percentage (%)</option>
                            <option value="fixed">Fixed Amount (AED)</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label fw-semibold">Discount Value *</label>
                        <input type="number" step="0.01" min="0.01" id="promoDiscountValue" class="form-control" placeholder="e.g. 20">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label fw-semibold">Max Discount (AED)</label>
                        <input type="number" step="0.01" min="0.01" id="promoMaxDiscountAmount" class="form-control" placeholder="No limit">
                        <small class="text-muted">Cap the discount at this amount</small>
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label fw-semibold">Max Uses</label>
                        <input type="number" min="1" id="promoMaxUses" class="form-control" placeholder="Unlimited">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">Valid From</label>
                        <input type="date" id="promoValidFrom" class="form-control">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">Valid To</label>
                        <input type="date" id="promoValidTo" class="form-control">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">Min Nights</label>
                        <input type="number" min="1" id="promoMinNights" class="form-control" placeholder="No minimum">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">Min Subtotal (AED)</label>
                        <input type="number" step="0.01" min="0" id="promoMinAmount" class="form-control" placeholder="No minimum">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars-accent" id="promoSubmitBtn" onclick="savePromo()"><i class="bi bi-check-lg me-1"></i>Save Promo</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Rule Modal -->
<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--ars-primary);color:#fff">
                <h5 class="modal-title" id="ruleModalTitle"><i class="bi bi-tags me-2"></i>Add Pricing Rule</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ruleId" value="">
                <div class="row g-3">
                    <div class="col-12 col-sm-8">
                        <label class="form-label fw-semibold">Rule Name *</label>
                        <input type="text" id="ruleName" class="form-control" placeholder="e.g. Summer Premium, Weekend Rate">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label fw-semibold">Rule Type *</label>
                        <select id="ruleType" class="form-select" onchange="onRuleTypeChange()">
                            <option value="seasonal">Seasonal Rate</option>
                            <option value="weekend">Weekend Rate</option>
                            <option value="length_discount">Length Discount</option>
                            <option value="minimum_stay">Minimum Stay</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">Apply to Unit</label>
                        <select id="ruleUnit" class="form-select">
                            <option value="">All Units</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['unit_number']) ?> — <?= h($u['building_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label fw-semibold">Priority</label>
                        <input type="number" id="rulePriority" class="form-control" value="0" min="0">
                        <small class="form-text text-muted">Higher number = higher priority when rules overlap</small>
                    </div>

                    <!-- Date fields (seasonal, minimum_stay optional) -->
                    <div class="col-12 col-sm-6 rule-field rule-dates">
                        <label class="form-label fw-semibold">Start Date</label>
                        <input type="date" id="ruleStartDate" class="form-control">
                    </div>
                    <div class="col-12 col-sm-6 rule-field rule-dates">
                        <label class="form-label fw-semibold">End Date</label>
                        <input type="date" id="ruleEndDate" class="form-control">
                    </div>

                    <!-- Rate fields (seasonal, weekend) -->
                    <div class="col-12 rule-field rule-rate-section">
                        <div class="alert alert-info py-2 mb-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Set <strong>either</strong> a fixed nightly rate <strong>or</strong> a percentage modifier (not both).
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 rule-field rule-rate">
                        <label class="form-label fw-semibold">Fixed Nightly Rate (AED)</label>
                        <input type="number" step="0.01" min="0" id="ruleRateAmount" class="form-control" placeholder="e.g. 350.00">
                    </div>
                    <div class="col-12 col-sm-6 rule-field rule-rate">
                        <label class="form-label fw-semibold">Rate Modifier (%)</label>
                        <input type="number" step="0.1" id="ruleRateModifier" class="form-control" placeholder="e.g. +20 or -10">
                        <small class="form-text text-muted">Positive = increase, Negative = decrease</small>
                    </div>

                    <!-- Min nights (length_discount, minimum_stay) -->
                    <div class="col-12 col-sm-6 rule-field rule-min-nights">
                        <label class="form-label fw-semibold">Minimum Nights *</label>
                        <input type="number" min="1" id="ruleMinNights" class="form-control" placeholder="e.g. 7">
                    </div>

                    <!-- Discount percent (length_discount) -->
                    <div class="col-12 col-sm-6 rule-field rule-discount">
                        <label class="form-label fw-semibold">Discount Percentage *</label>
                        <div class="input-group">
                            <input type="number" step="0.1" min="0.1" max="100" id="ruleDiscountPercent" class="form-control" placeholder="e.g. 10">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ars" id="ruleSubmitBtn" onclick="saveRule()"><i class="bi bi-check-lg me-1"></i>Save Rule</button>
            </div>
        </div>
    </div>
</div>

<?php
$unitsJson = json_encode($units);
$pageScripts = <<<JS
<script>
const unitsData = {$unitsJson};

function showAlert(msg, type) {
    document.getElementById('pageAlert').innerHTML =
        '<div class="alert alert-'+type+' alert-dismissible fade show"><i class="bi bi-'+(type==='success'?'check-circle':'exclamation-triangle')+' me-2"></i>'+msg+'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

function ajaxPost(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_csrf', window.ARS_CSRF || '');
    for (const [k,v] of Object.entries(data)) { if (v !== null && v !== undefined) fd.append(k, v); }
    return fetch('ajax_pricing_actions.php', { method: 'POST', body: fd }).then(r => r.json());
}

function onRuleTypeChange() {
    const type = document.getElementById('ruleType').value;
    document.querySelectorAll('.rule-field').forEach(el => el.style.display = 'none');

    if (type === 'seasonal') {
        document.querySelectorAll('.rule-dates, .rule-rate, .rule-rate-section').forEach(el => el.style.display = '');
    } else if (type === 'weekend') {
        document.querySelectorAll('.rule-rate, .rule-rate-section').forEach(el => el.style.display = '');
    } else if (type === 'length_discount') {
        document.querySelectorAll('.rule-min-nights, .rule-discount').forEach(el => el.style.display = '');
    } else if (type === 'minimum_stay') {
        document.querySelectorAll('.rule-dates, .rule-min-nights').forEach(el => el.style.display = '');
    }
}

function clearForm() {
    document.getElementById('ruleId').value = '';
    document.getElementById('ruleName').value = '';
    document.getElementById('ruleType').value = 'seasonal';
    document.getElementById('ruleUnit').value = '';
    document.getElementById('rulePriority').value = '0';
    document.getElementById('ruleStartDate').value = '';
    document.getElementById('ruleEndDate').value = '';
    document.getElementById('ruleRateAmount').value = '';
    document.getElementById('ruleRateModifier').value = '';
    document.getElementById('ruleMinNights').value = '';
    document.getElementById('ruleDiscountPercent').value = '';
}

function openAddRule() {
    clearForm();
    document.getElementById('ruleModalTitle').innerHTML = '<i class="bi bi-tags me-2"></i>Add Pricing Rule';
    document.getElementById('ruleSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Rule';
    onRuleTypeChange();
}

function openEditRule(rule) {
    clearForm();
    document.getElementById('ruleModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Pricing Rule';
    document.getElementById('ruleSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update Rule';
    document.getElementById('ruleId').value = rule.id;
    document.getElementById('ruleName').value = rule.name || '';
    document.getElementById('ruleType').value = rule.rule_type;
    document.getElementById('ruleUnit').value = rule.unit_id || '';
    document.getElementById('rulePriority').value = rule.priority || 0;
    document.getElementById('ruleStartDate').value = rule.start_date || '';
    document.getElementById('ruleEndDate').value = rule.end_date || '';
    document.getElementById('ruleRateAmount').value = (rule.rate_amount && parseFloat(rule.rate_amount) > 0) ? rule.rate_amount : '';
    document.getElementById('ruleRateModifier').value = rule.rate_modifier !== null ? rule.rate_modifier : '';
    document.getElementById('ruleMinNights').value = rule.min_nights || '';
    document.getElementById('ruleDiscountPercent').value = rule.discount_percent || '';
    onRuleTypeChange();
    new bootstrap.Modal(document.getElementById('ruleModal')).show();
}

function saveRule() {
    const id = document.getElementById('ruleId').value;
    const action = id ? 'edit_rule' : 'add_rule';
    const data = {
        rule_id: id || undefined,
        name: document.getElementById('ruleName').value,
        rule_type: document.getElementById('ruleType').value,
        unit_id: document.getElementById('ruleUnit').value || '',
        priority: document.getElementById('rulePriority').value,
        start_date: document.getElementById('ruleStartDate').value,
        end_date: document.getElementById('ruleEndDate').value,
        rate_amount: document.getElementById('ruleRateAmount').value,
        rate_modifier: document.getElementById('ruleRateModifier').value,
        min_nights: document.getElementById('ruleMinNights').value,
        discount_percent: document.getElementById('ruleDiscountPercent').value,
    };
    ajaxPost(action, data).then(d => {
        if (d.success) { location.reload(); }
        else { showAlert(d.error || 'Failed to save rule', 'danger'); }
    }).catch(() => showAlert('Network error', 'danger'));
}

function toggleRule(ruleId) {
    ajaxPost('toggle_rule', { rule_id: ruleId }).then(d => {
        if (!d.success) { showAlert(d.error || 'Failed to toggle', 'danger'); location.reload(); }
    }).catch(() => showAlert('Network error', 'danger'));
}

function deleteRule(ruleId, name) {
    if (!confirm('Delete rule "' + name + '"? This cannot be undone.')) return;
    ajaxPost('delete_rule', { rule_id: ruleId }).then(d => {
        if (d.success) { location.reload(); }
        else { showAlert(d.error || 'Failed to delete', 'danger'); }
    }).catch(() => showAlert('Network error', 'danger'));
}

onRuleTypeChange();

// Promo code functions
function clearPromoForm() {
    document.getElementById('promoId').value = '';
    document.getElementById('promoCode').value = '';
    document.getElementById('promoDescription').value = '';
    document.getElementById('promoDiscountType').value = 'percentage';
    document.getElementById('promoDiscountValue').value = '';
    document.getElementById('promoMaxDiscountAmount').value = '';
    document.getElementById('promoMaxUses').value = '';
    document.getElementById('promoValidFrom').value = '';
    document.getElementById('promoValidTo').value = '';
    document.getElementById('promoMinNights').value = '';
    document.getElementById('promoMinAmount').value = '';
}

function openAddPromo() {
    clearPromoForm();
    document.getElementById('promoModalTitle').innerHTML = '<i class="bi bi-ticket-perforated me-2"></i>Add Promo Code';
    document.getElementById('promoSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Promo';
}

function openEditPromo(promo) {
    clearPromoForm();
    document.getElementById('promoModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Promo Code';
    document.getElementById('promoSubmitBtn').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update Promo';
    document.getElementById('promoId').value = promo.id;
    document.getElementById('promoCode').value = promo.code || '';
    document.getElementById('promoDescription').value = promo.description || '';
    document.getElementById('promoDiscountType').value = promo.discount_type;
    document.getElementById('promoDiscountValue').value = promo.discount_value || '';
    document.getElementById('promoMaxDiscountAmount').value = promo.max_discount_amount || '';
    document.getElementById('promoMaxUses').value = promo.max_uses || '';
    document.getElementById('promoValidFrom').value = promo.valid_from || '';
    document.getElementById('promoValidTo').value = promo.valid_to || '';
    document.getElementById('promoMinNights').value = promo.min_nights || '';
    document.getElementById('promoMinAmount').value = promo.min_amount || '';
    new bootstrap.Modal(document.getElementById('promoModal')).show();
}

function savePromo() {
    const id = document.getElementById('promoId').value;
    const action = id ? 'edit_promo' : 'add_promo';
    const data = {
        promo_id: id || undefined,
        code: document.getElementById('promoCode').value,
        description: document.getElementById('promoDescription').value,
        discount_type: document.getElementById('promoDiscountType').value,
        discount_value: document.getElementById('promoDiscountValue').value,
        max_discount_amount: document.getElementById('promoMaxDiscountAmount').value,
        max_uses: document.getElementById('promoMaxUses').value,
        valid_from: document.getElementById('promoValidFrom').value,
        valid_to: document.getElementById('promoValidTo').value,
        min_nights: document.getElementById('promoMinNights').value,
        min_amount: document.getElementById('promoMinAmount').value,
    };
    ajaxPost(action, data).then(d => {
        if (d.success) { location.reload(); }
        else { showAlert(d.error || 'Failed to save promo', 'danger'); }
    }).catch(() => showAlert('Network error', 'danger'));
}

function togglePromo(promoId) {
    ajaxPost('toggle_promo', { promo_id: promoId }).then(d => {
        if (!d.success) { showAlert(d.error || 'Failed to toggle', 'danger'); location.reload(); }
    }).catch(() => showAlert('Network error', 'danger'));
}

function deletePromo(promoId, code) {
    if (!confirm('Delete promo code "' + code + '"? This cannot be undone.')) return;
    ajaxPost('delete_promo', { promo_id: promoId }).then(d => {
        if (d.success) { location.reload(); }
        else { showAlert(d.error || 'Failed to delete', 'danger'); }
    }).catch(() => showAlert('Network error', 'danger'));
}
</script>
JS;
if (!empty($pageScripts) && !empty($GLOBALS['ars_shell_state'])) {
    $GLOBALS['ars_shell_state']['pageScripts'] = $pageScripts;
}
ars_shell_end();
?>
