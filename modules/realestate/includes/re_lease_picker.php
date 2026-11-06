<?php
/**
 * "Select Lease" picker used by Move-In / Move-Out create pages.
 *
 * Opened from the side menu: a type-to-search dropdown (Select2) with
 * status / building quick filters.
 *   $pageHead = re_lease_picker_head();            // before the layout header
 *   echo re_lease_picker_filters($leases);         // above <select id="lease_id">
 *   echo re_lease_picker_script();                 // after the form
 * Each <option> carries data-status / data-building / data-search; leases
 * that already have a move-in / move-out are rendered `disabled` (greyed out,
 * not selectable).
 *
 * Opened from a lease (?lease_id=): the lease is fixed, no search shown.
 *   echo re_lease_picker_locked($lease, $changeUrl);
 */

/**
 * Searchable text for one lease row (tenant, unit, building, lease no, contact).
 */
function re_lease_picker_search_text(array $lease): string
{
    return strtolower(implode(' ', [
        $lease['lease_number'] ?? '',
        $lease['building_name'] ?? '',
        $lease['unit_number'] ?? '',
        $lease['first_name'] ?? '',
        $lease['last_name'] ?? '',
        $lease['phone'] ?? '',
        $lease['email'] ?? '',
        $lease['status'] ?? '',
    ]));
}

function re_lease_picker_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function re_lease_picker_label(array $lease): string
{
    return ($lease['lease_number'] ?? '') . ' - ' . ($lease['building_name'] ?? '') . ' - '
        . ($lease['unit_number'] ?? '') . ' - ' . trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''))
        . ' [' . re_lease_picker_status_label((string)($lease['status'] ?? '')) . ']';
}

/**
 * Stylesheets for the searchable dropdown (same Select2 build as lease_add.php).
 */
function re_lease_picker_head(): string
{
    return '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">'
        . '<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">';
}

/**
 * Read-only display of a lease fixed by ?lease_id=, with a link to pick another.
 */
function re_lease_picker_locked(array $lease, string $changeUrl): string
{
    $esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<div class="input-group">'
        . '<span class="input-group-text"><i class="bi bi-lock"></i></span>'
        . '<input type="text" class="form-control" readonly value="' . $esc(re_lease_picker_label($lease)) . '">'
        . '<a href="' . $esc($changeUrl) . '" class="btn btn-outline-secondary">Choose another lease</a>'
        . '</div>';
}

/**
 * Status / building quick filters for the searchable dropdown.
 */
function re_lease_picker_filters(array $leases): string
{
    $esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $statusCounts = [];
    $buildings = [];
    foreach ($leases as $l) {
        $st = (string)($l['status'] ?? '');
        $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
        $buildings[(string)($l['building_name'] ?? '')] = true;
    }
    $statusOrder = function_exists('re_lease_valid_statuses')
        ? re_lease_valid_statuses()
        : ['draft', 'active', 'expired', 'terminated', 'renewed', 'has_legal_case'];
    ksort($buildings, SORT_NATURAL | SORT_FLAG_CASE);

    $html = '<div class="row g-2 mb-2" id="leasePickerFilters">';
    $html .= '<div class="col-md-5"><select id="leaseFilterStatus" class="form-select form-select-sm" aria-label="Filter by lease status">';
    $html .= '<option value="">All statuses (' . count($leases) . ')</option>';
    foreach ($statusOrder as $st) {
        if (empty($statusCounts[$st])) {
            continue;
        }
        $html .= '<option value="' . $esc($st) . '">' . $esc(re_lease_picker_status_label($st)) . ' (' . (int)$statusCounts[$st] . ')</option>';
    }
    $html .= '</select></div>';

    $html .= '<div class="col-md-7"><select id="leaseFilterBuilding" class="form-select form-select-sm" aria-label="Filter by building">';
    $html .= '<option value="">All buildings</option>';
    foreach (array_keys($buildings) as $b) {
        $html .= '<option value="' . $esc($b) . '">' . $esc($b) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '</div>';

    return $html;
}

/**
 * Turns <select id="lease_id"> into a type-to-search dropdown honouring the
 * quick filters. Select2 fires jQuery-only change events, so they are re-sent
 * as native events for the page's addEventListener('change') handlers.
 */
function re_lease_picker_script(): string
{
    return <<<'HTML'
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    const select = document.getElementById('lease_id');
    if (!select || typeof jQuery === 'undefined' || !jQuery.fn.select2) return;
    const $select = jQuery(select);
    const statusEl = document.getElementById('leaseFilterStatus');
    const buildingEl = document.getElementById('leaseFilterBuilding');
    const countEl = document.getElementById('leaseFilterCount');
    const all = Array.from(select.options).filter(o => o.value);

    function passesFilters(o) {
        const st = statusEl ? statusEl.value : '';
        const bld = buildingEl ? buildingEl.value : '';
        return (!st || o.dataset.status === st) && (!bld || o.dataset.building === bld);
    }

    $select.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Type lease no, tenant, unit, phone or email…',
        allowClear: true,
        matcher: function (params, data) {
            const o = data.element;
            if (!o || !o.value) return null;
            if (!passesFilters(o)) return null;
            const terms = (params.term || '').toLowerCase().split(/\s+/).filter(Boolean);
            const hay = o.dataset.search || '';
            return terms.every(t => hay.includes(t)) ? data : null;
        },
        language: { noResults: () => 'No matching lease' }
    });

    $select.on('change', function (e) {
        if (e.isTrigger) select.dispatchEvent(new Event('change'));
    });

    function applyFilters() {
        const shown = all.filter(passesFilters);
        // disabled = greyed out; data-blocked = listed but refused on selection (e.g. draft lease)
        const blocked = shown.filter(o => o.disabled || o.dataset.blocked === '1').length;
        if (countEl) countEl.textContent = (shown.length - blocked) + ' available'
            + (blocked ? ' · ' + blocked + ' not available' : '');
        const cur = select.options[select.selectedIndex];
        if (cur && cur.value && !passesFilters(cur)) {
            select.value = '';
            select.dispatchEvent(new Event('change'));
        }
    }
    [statusEl, buildingEl].forEach(el => el && el.addEventListener('change', applyFilters));
    applyFilters();
})();
</script>
HTML;
}

/**
 * "Lease is Draft" popup (Bootstrap modal). Open it from JS with
 * showDraftLeasePopup(leaseNumber, leaseId). Used where a draft lease must
 * not get a move-out (Move-Out page, Move-Out button on the lease page).
 */
function re_lease_draft_popup(): string
{
    return <<<'HTML'
<div class="modal fade" id="draftLeaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-warning"></i> Lease status is Draft</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Lease <strong id="draftLeaseNumber"></strong> is still in <span class="badge bg-secondary">Draft</span> status.</p>
                <p class="mb-0">A move-out cannot be created for a draft lease. Activate the lease first
                    (<em>Edit Lease → Status: Active</em>), then create the move-out.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">OK</button>
                <a href="#" id="draftLeaseEditLink" class="btn btn-primary"><i class="bi bi-pencil"></i> Edit Lease</a>
            </div>
        </div>
    </div>
</div>
<script>
function showDraftLeasePopup(leaseNumber, leaseId) {
    document.getElementById('draftLeaseNumber').textContent = leaseNumber || '';
    document.getElementById('draftLeaseEditLink').href = 'lease_add.php?id=' + encodeURIComponent(leaseId || '');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('draftLeaseModal')).show();
}
</script>
HTML;
}
