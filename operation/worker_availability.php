<?php
// operation/worker_availability.php

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/work_order_financial_guard.php';
require_once __DIR__.'/../includes/work_order_adjustment_service.php';
require_once __DIR__.'/../includes/service_category_helper.php';
require_once __DIR__.'/../includes/cleaning_order_cancellation_helper.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$showFinancialTotals = sm_user_can_finalize($conn);
$canFinalizeUser = sm_user_can_finalize($conn);
$canMarkCompleteUser = sm_user_can_mark_complete($conn);
$canRequestAdjUser = sm_user_can_request_adjustment($conn);
$canApproveAdjUser = sm_user_can_approve_adjustment($conn);
$deferAutoInvoice = sm_defer_auto_invoice($conn);
$defaultServiceCategoryId = (int)(sm_resolve_service_category_id($conn) ?? 0);
$serviceCategories = sm_categories_for_booking_api($conn);

$day      = $_GET['day']   ?? date('Y-m-d');
$slotMin  = max(5, (int)($_GET['slot'] ?? 30));
$dayStart = $_GET['start'] ?? '07:00';
$dayEnd   = $_GET['end']   ?? '22:00';
$showBusy = ($_GET['show'] ?? '') === 'busy';
$opsFilter = trim((string)($_GET['ops'] ?? ''));
$validOpsFilters = ['', 'open', 'completed', 'cancelled', 'finalized'];
if (!in_array($opsFilter, $validOpsFilters, true)) {
  $opsFilter = '';
}

/** Legacy DB statuses — still drive block colours; invoiced/paid are set by finalize/payment only. */
$palette = [
  'draft'       => '#ADB5BD',
  'scheduled'   => '#0DCAF0',
  'confirmed'   => '#6C757D',
  'in_progress' => '#20C997',
  'completed'   => '#198754',
  'invoiced'    => '#6F42C1',
  'paid'        => '#0D6EFD',
  'cancelled'   => '#CED4DA',
];

/** Simplified ops view (matches workorder_list + WORKFLOW.md). */
$opsFilterChips = [
  ''          => ['All', '#e9ecef', '#495057'],
  'open'      => ['Open', '#0DCAF0', '#000'],
  'completed' => ['Completed', '#198754', '#fff'],
  'cancelled' => ['Cancelled', '#CED4DA', '#000'],
  'finalized' => ['Finalized', '#ffc107', '#000'],
];

$boardLegend = [
  ['Open', '#0DCAF0', 'draft / scheduled / confirmed / in progress'],
  ['Completed', '#198754', 'job done, awaiting finalize'],
  ['Cancelled', '#CED4DA', ''],
  ['Finalized', '#ffc107', 'gold border — invoice posted'],
];

try {
  // lightweight fallbacks so the page still loads if the API fails
  $fallback_clients   = $conn->query("SELECT id,client_name,rate,terms,default_vat_rate,credit_limit,NULL as last_driver_id FROM client ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
  $fallback_workers   = $conn->query("
    SELECT w.id, w.nickname, w.daily_cap_hours 
    FROM workers w 
    LEFT JOIN employees e ON w.emp_num = e.employee_code 
    WHERE e.status IS NULL OR e.status != 'Inactive'
    ORDER BY w.nickname
  ")->fetchAll(PDO::FETCH_ASSOC);
  $fallback_drivers   = $conn->query("SELECT id,nickname FROM driver ORDER BY nickname")->fetchAll(PDO::FETCH_ASSOC);
  $fallback_companies = $conn->query("SELECT name FROM comp_sa ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  $fallback_services  = $conn->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  
} catch(Throwable $e){
  $fallback_clients=$fallback_workers=$fallback_drivers=$fallback_companies=$fallback_services=[];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Worker Availability</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<style>
  :root {
    --wa-row-h: 80px;
    --wa-grid-hour: #94a3b8;
    --wa-grid-half: #cbd5e1;
    --wa-grid-slot: #e2e8f0;
  }
  body{background:#eef2f7}
  .board{
    background:#fff;
    border-radius:16px;
    box-shadow:0 8px 30px #00000012;
    border:1px solid #dbe3ec;
  }
  .row-label{
    width:220px;min-width:220px;max-width:220px;
    min-height:var(--wa-row-h);
    display:flex;flex-direction:column;justify-content:center;
    border-right:1px solid #cbd5e1 !important;
    background:#f1f5f9;
  }
  .wa-grid-row:nth-child(even) .row-label{background:#e9eef4}
  .wa-grid-row:nth-child(even) .track{background:#f8fafc}
  .times-bar{
    position:sticky;top:0;z-index:3;background:#f1f5f9;
    min-height:36px;border-bottom:2px solid #94a3b8 !important;
  }
  .time-tick{
    font-size:11px;font-weight:600;color:#475569;
    padding:6px 0;border-left:1px solid var(--wa-grid-hour);
    line-height:1.2;
  }
  .time-tick.minor{
    font-weight:400;color:#94a3b8;
    border-left:1px dashed var(--wa-grid-half);
  }
  .wa-ruler{border:1px solid #cbd5e1 !important;box-shadow:0 2px 4px rgba(15,23,42,.06)}
  .track{
    position:relative;
    height:var(--wa-row-h);
    border-bottom:1px solid #cbd5e1;
    background:#fff;
  }
  .slot{position:absolute;top:0;bottom:0;pointer-events:none}
  .slot-hour{border-left:1px solid var(--wa-grid-hour)}
  .slot.half{border-left:1px dashed var(--wa-grid-half)}
  .slot:not(.slot-hour):not(.half){border-left:1px solid var(--wa-grid-slot)}
  .block{
    position:absolute;top:4px;bottom:4px;border-radius:8px;
    padding:5px 8px;color:#fff;font-size:11px;line-height:1.25;
    overflow:hidden;cursor:grab;
    display:flex;flex-direction:column;justify-content:center;
    box-shadow:0 2px 6px rgba(15,23,42,.22);
    border:1px solid rgba(255,255,255,.2);
    z-index:2;
    min-width:48px;
  }
  .block-inner{display:flex;flex-direction:column;gap:1px;min-width:0;width:100%}
  .block-client{
    font-weight:700;font-size:11px;line-height:1.2;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .block .block-cats{
    display:block;font-size:9px;line-height:1.25;
    opacity:.95;margin-top:1px;
    overflow:hidden;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;
    white-space:normal;
  }
  .block-time{
    font-size:9px;opacity:.88;margin-top:2px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .block.cancelled{opacity:.45;color:#212529;border:1px dashed #adb5bd;background:#e9ecef!important;box-shadow:none}
  .block-finalized{box-shadow:0 0 0 2px #ffc107, 0 2px 6px rgba(15,23,42,.18)!important}
  .drag-ghost{position:absolute;top:4px;bottom:4px;background:#0d6efd22;border:2px dashed #0d6efd;border-radius:8px;pointer-events:none}
  .legend .swatch{display:inline-block;width:14px;height:14px;border-radius:4px;margin-right:6px;vertical-align:middle}
  .resize-handle{position:absolute;top:0;bottom:0;width:8px;background:#00000025;cursor:ew-resize;z-index:3}
  .resize-handle.start{left:0;border-top-left-radius:8px;border-bottom-left-radius:8px}
  .resize-handle.end{right:0;border-top-right-radius:8px;border-bottom-right-radius:8px}
  .row-badge{font-size:10px;padding:2px 6px;border-radius:10px}
  .row-badge.warn{background:#ffc9c9;color:#7a1a1a;border:1px solid #ffa8a8}
  .search-input{width:240px}
  .unavail{
    position:absolute;top:4px;bottom:4px;
    background:#dc354533;border:1px dashed #dc3545;border-radius:8px;
    pointer-events:auto;z-index:1;
  }
  .unavail.absent{background:#dc354555;border-style:solid}
  .totals-card{box-shadow:0 4px 16px #00000008}
  .btn-chip{--bs-btn-padding-y:.25rem;--bs-btn-padding-x:.5rem;--bs-btn-font-size:.75rem;border-radius:999px}
  .alert-success{background:#e6ffed;color:#0b5d1e;border:1px solid #b4e2c1}
  #grid{border:1px solid #cbd5e1;border-top:none;border-radius:0 0 12px 12px;overflow:hidden}
  @media (max-width: 768px) {
    :root { --wa-row-h: 56px; }
    .row-label { width: 120px; min-width: 120px; max-width: 120px; font-size: 12px; padding: 4px !important; }
    .search-input { width: 100%; margin-bottom: 8px; }
    .d-flex.gap-2.align-items-center.flex-wrap { flex-direction: column; align-items: stretch !important; }
    .btn-chip { font-size: 0.65rem; padding: 0.15rem 0.35rem; }
    .board { padding: 8px !important; }
    .time-tick { font-size: 9px; }
    .block { font-size: 10px; padding: 3px 5px; }
    .block-client { font-size: 10px; }
    .block .block-cats, .block-time { font-size: 8px; }
  }
  /* Print stylesheet */
  @media print {
    .btn, .form-control, .form-select, .modal, #totalsCard { display: none !important; }
    body { background: #fff; }
    .board { box-shadow: none; border: 1px solid #000; }
    .block { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  }
  
  /* Select2 Enhancements for Better UX */
  .select2-container--bootstrap-5 .select2-selection {
    min-height: 38px !important;
    font-size: 1rem;
  }
  
  .select2-container--bootstrap-5 .select2-dropdown {
    font-size: 1rem;
    border: 1px solid #dee2e6;
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
  }
  
  .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field {
    padding: 0.5rem;
    font-size: 1rem;
    border: 1px solid #dee2e6;
  }
  
  .select2-container--bootstrap-5 .select2-results__option {
    padding: 0.5rem 1rem;
  }
  
  .select2-container--bootstrap-5 .select2-results__option--highlighted {
    background-color: #0d6efd !important;
    color: white !important;
  }

  /* Add Order booking wizard */
  .booking-card {
    background: #fff;
    border: 1px solid #e8ecf1;
    border-radius: 12px;
    padding: 1rem 1.15rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
  }
  .booking-card-header {
    font-weight: 600;
    font-size: 0.9rem;
    color: #334155;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .booking-card-header .step-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    background: #0d6efd;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 700;
  }
  .cat-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.85rem;
    border: 2px solid #dee2e6;
    border-radius: 999px;
    cursor: pointer;
    user-select: none;
    transition: all 0.15s ease;
    background: #f8fafc;
    font-size: 0.9rem;
  }
  .cat-chip input { display: none; }
  .cat-chip:has(input:checked) {
    border-color: #0d6efd;
    background: #e7f1ff;
    color: #0d4ebb;
    font-weight: 600;
  }
  .worker-picker {
    border: 1px solid #dee2e6;
    border-radius: 10px;
    background: #f8fafc;
    padding: 0.65rem;
  }
  .worker-picker-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    max-height: 200px;
    overflow-y: auto;
    padding: 0.15rem 0.05rem;
  }
  .worker-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.75rem;
    border: 2px solid #dee2e6;
    border-radius: 999px;
    cursor: pointer;
    user-select: none;
    transition: all 0.15s ease;
    background: #fff;
    font-size: 0.85rem;
    margin: 0;
  }
  .worker-chip input { display: none; }
  .worker-chip:has(input:checked) {
    border-color: #198754;
    background: #e8f5e9;
    color: #146c43;
    font-weight: 600;
  }
  .worker-picker-meta {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 0.35rem;
  }
  .booking-quote {
    background: linear-gradient(135deg, #f0f7ff 0%, #f8fafc 100%);
    border: 1px solid #cfe2ff;
    border-radius: 12px;
    padding: 1rem 1.15rem;
  }
  .booking-quote .quote-total {
    font-size: 1.35rem;
    font-weight: 700;
    color: #0d6efd;
  }
  .catalog-panel-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 0.5rem;
  }
  #repeatModal .booking-card { margin-bottom: 0.75rem; }
  
  /* Mobile Optimizations */
  @media (max-width: 768px) {
    .select2-container--bootstrap-5 .select2-selection {
      min-height: 44px !important;
      font-size: 16px !important; /* Prevents iOS zoom */
    }
    
    .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field {
      font-size: 16px !important; /* Prevents iOS zoom */
      padding: 0.75rem;
    }
    
    .select2-container--bootstrap-5 .select2-results__option {
      padding: 0.75rem 1rem;
      font-size: 16px;
    }
    
    .select2-dropdown {
      max-height: 60vh;
    }
  }
</style>
</head>
<body>
<div class="container-fluid py-4">
  <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
    <h4 class="mb-0 me-2">Worker Availability</h4>
    <span class="badge bg-secondary" title="Keyboard shortcuts: Ctrl/Cmd+K (search), T (today), ← → (navigate days), Ctrl/Cmd+N (new)">⌨️ Shortcuts</span>

    <!-- Quick filters -->
    <div class="d-flex gap-2 align-items-center">
      <input type="text" id="workerSearch" class="form-control search-input" placeholder="Find worker…">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="toggleBusy" <?= $showBusy?'checked':''?>>
        <label class="form-check-label" for="toggleBusy">Only rows with events</label>
      </div>
      <button class="btn btn-outline-danger" id="btnAddTimeOff">Add time-off</button>
      <button class="btn btn-outline-primary" id="btnRepeat" title="Create multiple bookings on a weekly pattern">Repeat orders</button>
      <button class="btn btn-outline-success" id="btnDriverCalendar">Driver calendar</button>
      <button class="btn btn-outline-dark" id="btnDriverPDF">Driver PDF</button>
      <button class="btn btn-outline-info" id="btnExport" title="Export current view as CSV">📥 Export</button>
    </div>

    <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
      <!-- Ops workflow filters (simplified) -->
      <div class="d-flex gap-1 align-items-center">
        <small class="text-muted fw-semibold me-1">View:</small>
        <?php foreach ($opsFilterChips as $key => $chip):
          $active = ($opsFilter === $key);
          [$label, $bg, $fg] = $chip;
          $chipText = $active ? $fg : ((strcasecmp($fg, '#fff') === 0 || strcasecmp($fg, '#ffffff') === 0) ? $bg : $fg);
        ?>
          <button
            type="button"
            class="btn btn-outline-secondary btn-chip ops-filter-chip<?= $active ? ' active' : '' ?>"
            data-ops-filter="<?= h($key) ?>"
            style="border-color:<?= h($bg) ?>;color:<?= h($chipText) ?>;background:<?= $active ? h($bg) : '#fff' ?>"
            title="Show <?= h($label) ?> jobs"
          ><?= h($label) ?></button>
        <?php endforeach; ?>
      </div>

      <a class="btn btn-outline-secondary" id="navPrev">&laquo; Prev</a>
      <input type="date" class="form-control" style="width:150px" id="dayPicker" value="<?=h($day)?>">
      <select id="slotSelect" class="form-select" style="width:120px">
        <?php foreach([15,30,60] as $m): ?><option value="<?=$m?>" <?=$m==$slotMin?'selected':''?>><?=$m?> min</option><?php endforeach; ?>
      </select>
      <input type="time" id="startTime" class="form-control" style="width:120px" value="<?=h($dayStart)?>">
      <input type="time" id="endTime" class="form-control" style="width:120px" value="<?=h($dayEnd)?>">
      <button class="btn btn-primary" id="applyView">Apply</button>
      <a class="btn btn-outline-secondary" id="navToday">Today</a>

      <div class="ms-2 d-flex align-items-center gap-2">
        <select id="viewSelect" class="form-select" style="width:120px">
          <option value="day"  <?=($_GET['view']??'day')==='day'?'selected':''?>>Day</option>
          <option value="3d"   <?=($_GET['view']??'day')==='3d'?'selected':''?>>3 days</option>
          <option value="week" <?=($_GET['view']??'day')==='week'?'selected':''?>>Week</option>
        </select>
      </div>

      <div id="dateStrip" class="d-flex flex-wrap gap-2 my-2"></div>
    </div>
  </div>

  <!-- Totals widget -->
  <div id="totalsCard" class="alert alert-light border rounded-3 totals-card d-flex flex-wrap gap-4 align-items-center mb-3"<?= $showFinancialTotals ? '' : ' style="display:none!important"' ?>>
    <div><span class="fw-semibold">Totals for</span> <span id="tw-day" class="badge bg-secondary-subtle text-dark border">—</span></div>
    <div>Orders: <strong id="tw-orders">0</strong></div>
    <div>Hours: <strong id="tw-hours">0.00</strong></div>
    <div>Subtotal: <strong id="tw-sub">AED 0.00</strong></div>
    <div>VAT: <strong id="tw-vat">AED 0.00</strong></div>
    <div>Total: <strong id="tw-total">AED 0.00</strong></div>
  </div>

  <div class="board p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="legend small">
        <span class="me-3"><strong><?=h($day)?></strong> (<?=h($dayStart)?>–<?=h($dayEnd)?>, <?=h($slotMin)?>-min slots)</span>
        <?php foreach ($boardLegend as [$name, $hex, $hint]): ?>
          <span class="me-3"<?= $hint ? ' title="'.h($hint).'"' : '' ?>>
            <?php if ($name === 'Finalized'): ?>
              <span class="swatch" style="border:2px solid <?=$hex?>;background:transparent"></span>
            <?php else: ?>
              <span class="swatch" style="background:<?=$hex?>"></span>
            <?php endif; ?>
            <?=h($name)?>
          </span>
        <?php endforeach; ?>
      </div>
      <div class="small text-muted">Drag empty row to create • Drag edges to resize • Drag block to reassign • <strong>Click</strong> for workflow • <strong>Double-click</strong> to edit / finalize</div>
    </div>

    <!-- Ruler -->
    <div class="d-flex wa-ruler rounded-top sticky-top bg-white">
      <div class="row-label p-2 fw-semibold">Worker</div>
      <div class="flex-fill">
        <div class="d-flex times-bar">
          <?php
            $start=strtotime("$day $dayStart"); $end=strtotime("$day $dayEnd");
            $cols=(($end-$start)/60)/$slotMin;
            for($i=0;$i<=$cols;$i++){
              $ts=$start+($i*$slotMin*60);
              $mins=(int)date('i',$ts);
              $isHour=($mins===0);
              $cls='time-tick text-center'.($isHour?'':' minor');
              echo '<div class="'.$cls.'" style="width:'.(100/($cols+1)).'%;">'.($isHour?date('H:i',$ts):'').'</div>';
            }
          ?>
        </div>
      </div>
    </div>

    <div id="grid"></div>
  </div>
</div>

<!-- Create Order modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <form class="modal-content" id="createForm">
      <div class="modal-header border-0 pb-0">
        <div>
          <h5 class="modal-title mb-1">Book a service</h5>
          <div class="small text-muted">Select what the client needs — you can combine Cleaning with another service in one order.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="service_date" id="f_date" value="<?=h($day)?>">
        <input type="hidden" name="service_category_id" id="f_service_category_primary" value="<?= (int)$defaultServiceCategoryId ?>">

        <?php if (!empty($serviceCategories)): ?>
        <div class="booking-card">
          <div class="booking-card-header"><span class="step-num">1</span> What does the client need?</div>
          <div id="f_category_chips" class="d-flex flex-wrap gap-2">
            <?php foreach ($serviceCategories as $cat): ?>
              <label class="cat-chip mb-0">
                <input type="checkbox" name="booking_category_ids[]" value="<?= (int)$cat['id'] ?>"
                  data-code="<?= h($cat['code']) ?>"
                  <?= (int)$cat['id'] === $defaultServiceCategoryId ? 'checked' : '' ?>>
                <span><?= h(trim(($cat['icon'] ?? '') . ' ' . $cat['name'])) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-text mt-2">Tip: select <strong>Cleaning</strong> and <strong>Pest Control</strong> together when the client needs both on the same visit.</div>
        </div>
        <?php endif; ?>

        <div class="booking-card">
          <div class="booking-card-header"><span class="step-num">2</span> Schedule &amp; team</div>
          <div class="row g-2">
            <div class="col-md-3"><label class="form-label">Start</label><input type="time" class="form-control" name="start_time" id="f_start" required></div>
            <div class="col-md-3"><label class="form-label">End</label><input type="time" class="form-control" name="end_time" id="f_end" required></div>
            <div class="col-md-3"><label class="form-label">Status</label>
              <select name="status" id="f_status" class="form-select">
                <option value="confirmed">confirmed</option>
                <option value="scheduled">scheduled</option>
                <option value="draft">draft</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label">Worker(s)</label>
              <div class="worker-picker" id="f_workers_picker">
                <input type="text" class="form-control form-control-sm mb-2" id="f_workers_search" placeholder="Search workers…">
                <div class="worker-picker-grid" id="f_workers_grid"></div>
                <div class="worker-picker-meta" id="f_workers_meta">Select one or more workers for this job.</div>
              </div>
              <select class="d-none" name="worker_ids[]" id="f_workers" multiple required></select>
            </div>
          </div>
        </div>

        <div class="booking-card">
          <div class="booking-card-header"><span class="step-num">3</span> Client &amp; notes</div>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Client</label><select class="form-select" name="client_id" id="f_client" required></select></div>
            <div class="col-md-3"><label class="form-label">Driver</label><select class="form-select" name="driver_id" id="f_driver"></select></div>
            <div class="col-md-3"><label class="form-label">VAT mode</label><select name="vat_included" id="f_vat" class="form-select"><option value="yes">Yes (fee includes VAT)</option><option value="no">No (add VAT)</option></select></div>
            <div class="col-md-12"><label class="form-label">Remark</label><input type="text" class="form-control" name="remark" id="f_remark" placeholder="Optional note for the team"></div>
          </div>
          <div id="clientPreferencesBox" class="alert alert-info mt-3 mb-0 py-2" style="display:none;">
            <h6 class="mb-2 small fw-semibold"><i class="bi bi-info-circle"></i> Client preferences</h6>
            <div class="row small">
              <div class="col-md-6">
                <p class="mb-1"><strong>Preferred workers:</strong> <span id="pref_workers">—</span></p>
                <p class="mb-0"><strong>VAT mode:</strong> <span id="pref_vat">—</span></p>
              </div>
              <div class="col-md-6">
                <p class="mb-1"><strong>Special instructions:</strong> <span id="pref_instructions">—</span></p>
                <p class="mb-0"><strong>Access:</strong> <span id="pref_access">—</span></p>
              </div>
            </div>
          </div>
        </div>

        <div class="booking-card" id="f_cleaning_panel">
          <div class="booking-card-header"><span class="step-num">4</span> Cleaning — labour &amp; materials</div>
          <div class="row g-2">
            <div class="col-md-4"<?= $showFinancialTotals ? '' : ' style="display:none"' ?>>
              <label class="form-label">Hourly rate (AED)</label>
              <input type="number" step="0.01" class="form-control" name="hourly_rate" id="f_rate" placeholder="From client profile">
            </div>
            <div class="col-md-4">
              <label class="form-label">Cleaning materials</label>
              <select class="form-select" name="need_materials" id="f_need_materials">
                <option value="0">No</option>
                <option value="1">Yes</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Materials note</label>
              <input type="text" class="form-control" name="materials_note" id="f_materials_note" placeholder="Optional">
            </div>
          </div>
          <div class="form-text mt-2" id="f_materials_rate_hint"></div>
        </div>

        <div id="f_catalog_panels"></div>

        <div class="booking-quote" id="f_quote_summary" style="display:none">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <div class="small text-muted">Estimated total</div>
              <div class="quote-total" id="f_quote_total">AED 0.00</div>
            </div>
            <div class="small text-end" id="f_quote_breakdown"></div>
          </div>
        </div>

        <div id="cr_credit_alert" class="alert alert-warning mt-3" style="display:none;"></div>
        <div id="cr_override_wrap" class="form-check mt-2" style="display:none;">
          <input class="form-check-input" type="checkbox" id="cr_override_credit_limit" value="1">
          <label class="form-check-label" for="cr_override_credit_limit">Proceed even if the client exceeds their credit limit</label>
        </div>
        <div id="cr_credit_ok" class="alert alert-success mt-2" style="display:none;">Client within credit limit ✓</div>
        <div class="alert alert-secondary mt-3 mb-0 small" id="calcBox" style="display:none"></div>
        <div id="createError" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary px-4">Create booking</button>
      </div>
    </form>
  </div>
</div>

<!-- Add time-off modal -->
<div class="modal fade" id="timeOffModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="timeOffForm">
      <div class="modal-header">
        <h5 class="modal-title">Add time-off</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Worker</label>
            <select class="form-select" id="to_worker" name="worker_id" required></select>
          </div>
          <div class="col-12">
            <label class="form-label">Type</label>
            <select class="form-select" id="to_absence_type" name="absence_type">
              <option value="time_off">Time-off / unavailable</option>
              <option value="absent">Absent - sync to HR Attendance</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" id="to_date" name="date" required>
          </div>
          <div class="col-3">
            <label class="form-label">Start</label>
            <input type="time" class="form-control" id="to_start" name="start_time" required>
          </div>
          <div class="col-3">
            <label class="form-label">End</label>
            <input type="time" class="form-control" id="to_end" name="end_time" required>
          </div>
          <div class="col-12">
            <label class="form-label">Reason</label>
            <input class="form-control" id="to_reason" name="reason" placeholder="Vacation, sick, no show, etc.">
            <div class="form-text">For Absent, this reason will appear in HR Attendance notes.</div>
          </div>
          <div class="text-danger small mt-2" id="to_err" style="display:none"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
      <input type="hidden" name="action" value="unavail_create">
    </form>
  </div>
</div>

<!-- Edit Order modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <form class="modal-content" id="editForm">
      <div class="modal-header border-0 pb-0">
        <div>
          <h5 class="modal-title mb-1">Edit booking</h5>
          <div class="small text-muted" id="editInfo"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="order_id" id="e_order">
        <input type="hidden" name="service_category_id" id="e_service_category_primary" value="">

        <div id="e_workflow_bar" class="mb-3" style="display:none">
          <div class="d-flex flex-wrap gap-2 align-items-center" id="e_workflow_badges"></div>
          <div class="d-flex flex-wrap gap-2 mt-2" id="e_workflow_actions"></div>
          <div id="e_finalize_hint" class="small text-muted mt-1" style="display:none"></div>
          <div id="e_workflow_alert" class="alert alert-info small py-2 mt-2 mb-0" style="display:none"></div>
        </div>

        <div class="booking-card">
          <div class="booking-card-header"><span class="step-num">1</span> Client</div>
          <input class="form-control" id="e_client_label" readonly>
          <div id="editClientPreferencesBox" class="alert alert-info mt-3 mb-0 py-2" style="display:none;">
            <h6 class="mb-2 small fw-semibold"><i class="bi bi-info-circle"></i> Client preferences</h6>
            <div class="row small">
              <div class="col-md-6">
                <p class="mb-1"><strong>Preferred workers:</strong> <span id="e_pref_workers">—</span></p>
                <p class="mb-0"><strong>VAT mode:</strong> <span id="e_pref_vat">—</span></p>
              </div>
              <div class="col-md-6">
                <p class="mb-1"><strong>Special instructions:</strong> <span id="e_pref_instructions">—</span></p>
                <p class="mb-0"><strong>Access:</strong> <span id="e_pref_access">—</span></p>
              </div>
            </div>
          </div>
        </div>

        <?php if (!empty($serviceCategories)): ?>
        <div class="booking-card">
          <div class="booking-card-header"><span class="step-num">2</span> Services booked</div>
          <div id="e_category_chips" class="d-flex flex-wrap gap-2">
            <?php foreach ($serviceCategories as $cat): ?>
              <label class="cat-chip mb-0">
                <input type="checkbox" name="booking_category_ids[]" value="<?= (int)$cat['id'] ?>"
                  data-code="<?= h($cat['code']) ?>">
                <span><?= h(trim(($cat['icon'] ?? '') . ' ' . $cat['name'])) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="booking-card">
          <div class="booking-card-header"><span class="step-num">3</span> Schedule &amp; team</div>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Service date</label>
              <input type="date" class="form-control" name="service_date" id="e_service_date" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Start</label>
              <input type="time" class="form-control" name="start_time" id="e_start_time">
            </div>
            <div class="col-md-4">
              <label class="form-label">End</label>
              <input type="time" class="form-control" name="end_time" id="e_end_time">
            </div>
            <div class="col-md-12">
              <label class="form-label">Worker(s)</label>
              <div class="worker-picker" id="e_workers_picker">
                <input type="text" class="form-control form-control-sm mb-2" id="e_workers_search" placeholder="Search workers…">
                <div class="worker-picker-grid" id="e_workers_grid"></div>
                <div class="worker-picker-meta" id="e_workers_meta"></div>
              </div>
              <select class="d-none" name="worker_ids[]" id="e_workers" multiple required></select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Driver</label>
              <select class="form-select" name="driver_id" id="e_driver"></select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select class="form-select" name="status" id="e_status"><?php
                $editStatuses = ['draft','scheduled','confirmed','in_progress','completed','cancelled'];
                foreach ($editStatuses as $s): ?><option value="<?=$s?>"><?=str_replace('_',' ',$s)?></option><?php endforeach;
              ?></select>
            </div>
            <div class="col-md-4">
              <label class="form-label">VAT mode</label>
              <select class="form-select" name="vat_included" id="e_vat"><option value="yes">Yes (fee includes VAT)</option><option value="no">No (add VAT)</option></select>
            </div>
          </div>
        </div>

        <div class="booking-card" id="e_cleaning_panel">
          <div class="booking-card-header"><span class="step-num">4</span> Cleaning — labour &amp; materials</div>
          <div class="row g-2">
            <div class="col-md-4"<?= $showFinancialTotals ? '' : ' style="display:none"' ?>>
              <label class="form-label">Hourly rate (AED)</label>
              <input type="number" step="0.01" class="form-control" name="hourly_rate" id="e_rate">
            </div>
            <div class="col-md-4">
              <label class="form-label">Cleaning materials</label>
              <select class="form-select" name="need_materials" id="e_need_materials">
                <option value="0">No</option>
                <option value="1">Yes</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Materials note</label>
              <input type="text" class="form-control" name="materials_note" id="e_materials_note">
            </div>
          </div>
          <div class="form-text mt-2" id="e_materials_rate_hint"></div>
        </div>

        <div id="e_catalog_panels"></div>

        <div class="booking-card">
          <div class="booking-card-header"><span class="step-num">5</span> Notes</div>
          <input type="text" class="form-control" name="remark" id="e_remark" placeholder="Optional note">
        </div>

        <div class="booking-quote" id="e_quote_summary" style="display:none">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <div class="small text-muted">Estimated total</div>
              <div class="quote-total" id="e_quote_total">AED 0.00</div>
            </div>
            <div class="small text-end" id="e_quote_breakdown"></div>
          </div>
        </div>

        <div id="e_overtime_info" class="alert alert-info mt-2" style="display:none;">
          <strong>Overtime Information:</strong>
          <div id="e_overtime_details"></div>
        </div>

        <div id="e_credit_alert" class="alert alert-warning mt-2" style="display:none;"></div>
        <div id="e_override_wrap" class="form-check mt-2" style="display:none;">
          <input class="form-check-input" type="checkbox" id="e_override_credit_limit" name="override_credit_limit" value="1">
          <label class="form-check-label" for="e_override_credit_limit">Proceed even if the client exceeds their credit limit</label>
        </div>
        <div id="e_credit_ok" class="alert alert-success mt-2" style="display:none;">Client within credit limit ✓</div>
        <div class="alert alert-secondary mt-3 mb-0 small" id="editCalcBox" style="display:none"></div>
        <div id="editError" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="submit" class="btn btn-primary" id="e_save_btn">Save changes</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Adjustment request modal (from Worker Availability edit) -->
<div class="modal fade" id="waAdjRequestModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="wa_adj_title">Request Adjustment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Current frozen total: <strong id="wa_adj_frozen">AED 0.00</strong>. Accounts will post a credit note or supplementary invoice — the work order will not be edited directly.</p>
        <div class="mb-3">
          <label class="form-label">Type *</label>
          <select class="form-select" id="wa_adj_type">
            <option value="amount_decrease">Amount decrease (credit note)</option>
            <option value="amount_increase">Amount increase (extra invoice)</option>
            <option value="cancellation">Cancel finalized job</option>
            <option value="other">Other (manual review)</option>
          </select>
        </div>
        <div class="mb-3" id="wa_adj_grand_wrap">
          <label class="form-label">Proposed new total (AED)</label>
          <input type="number" step="0.01" min="0" class="form-control" id="wa_adj_grand">
        </div>
        <div class="mb-3" id="wa_adj_category_wrap" style="display:none">
          <label class="form-label">Category *</label>
          <select class="form-select" id="wa_adj_category">
            <option value="">— Select —</option>
            <?php foreach (cleaning_order_cancel_categories() as $cancelCat): ?>
            <option value="<?= h($cancelCat) ?>"><?= h($cancelCat) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Who/what caused the cancellation — shown to Accounts.</div>
        </div>
        <div class="mb-3" id="wa_adj_reason_wrap">
          <label class="form-label" id="wa_adj_reason_label">Reason *</label>
          <input type="text" class="form-control" id="wa_adj_reason" maxlength="500">
        </div>
        <div class="mb-3">
          <label class="form-label" id="wa_adj_notes_label">Details</label>
          <textarea class="form-control" id="wa_adj_notes" rows="3"></textarea>
        </div>
        <div class="text-danger small" id="wa_adj_error" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="wa_adj_submit">Submit Request</button>
      </div>
    </div>
  </div>
</div>
      
<!-- Cancellation reason modal -->
<div class="modal fade" id="cancelReasonModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="cancelReasonForm">
      <div class="modal-header">
        <h5 class="modal-title">Cancellation Reason</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning small">
          Cancelling this order will also void the linked invoice if it has no payments/allocations.
        </div>
        <div class="mb-3">
          <label class="form-label">Category <span class="text-danger">*</span></label>
          <select class="form-select" id="cancel_category" required>
            <option value="">-- Select category --</option>
            <?php foreach (cleaning_order_cancel_categories() as $cancelCat): ?>
            <option value="<?= h($cancelCat) ?>"><?= h($cancelCat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Details <span class="text-danger">*</span></label>
          <textarea class="form-control" id="cancel_details" rows="3" required placeholder="Write the internal cancellation details..."></textarea>
        </div>
        <div class="text-danger small" id="cancel_reason_error" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
        <button type="submit" class="btn btn-danger">Continue Cancellation</button>
      </div>
    </form>
  </div>
</div>

      <!-- Repeat orders wizard -->
      <div class="modal fade" id="repeatModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-fullscreen-md-down">
          <form class="modal-content" id="repeatForm">
            <div class="modal-header">
              <div>
                <h5 class="modal-title mb-1">Create repeating orders</h5>
                <div class="small text-muted">
                  <span class="badge text-bg-secondary me-1" id="rw_step_badge">Step 1 — Setup</span>
                  Bookings only — invoices are created when an Admin/Accountant <strong>finalizes</strong> each job.
                </div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
              <?php if ($deferAutoInvoice): ?>
              <div class="alert alert-info py-2 small mb-3">
                <strong>Accounting workflow:</strong> These orders will appear on the calendar as <em>open</em> bookings.
                No invoice or GL entry is created until someone uses <strong>Finalize &amp; Generate Invoice</strong> on each completed job.
              </div>
              <?php endif; ?>
              <input type="hidden" id="rw_service_category_id" value="<?= (int)$defaultServiceCategoryId ?>">

              <div class="booking-card">
                <div class="booking-card-header"><span class="step-num">1</span> Service &amp; schedule</div>
                <div class="row g-3">
                <?php if (!empty($serviceCategories)): ?>
                <div class="col-md-12">
                  <label class="form-label fw-semibold">Service category</label>
                  <select class="form-select" id="rw_service_category" required>
                    <?php foreach ($serviceCategories as $cat): ?>
                      <option value="<?= (int)$cat['id'] ?>" data-code="<?= h($cat['code']) ?>"
                        <?= (int)$cat['id'] === $defaultServiceCategoryId ? 'selected' : '' ?>>
                        <?= h(trim(($cat['icon'] ?? '') . ' ' . $cat['name'])) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">One category per repeat batch. Combine Cleaning + Pest Control using <strong>Add Order</strong> instead.</div>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                  <label class="form-label">Start week</label>
                  <input type="date" class="form-control" id="rw_start" required>
                  <div class="form-text">Any date in the first week.</div>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Weeks</label>
                  <input type="number" min="1" max="26" class="form-control" id="rw_weeks" value="4" required>
                </div>
                <div class="col-md-7">
                  <label class="form-label d-block">Days of week</label>
                  <div class="d-flex flex-wrap gap-2">
                    <div class="form-check"><input class="form-check-input rw-dow" type="checkbox" value="1" id="rw_mon" checked><label class="form-check-label" for="rw_mon">Mon</label></div>
                    <div class="form-check"><input class="form-check-input rw-dow" type="checkbox" value="2" id="rw_tue"><label class="form-check-label" for="rw_tue">Tue</label></div>
                    <div class="form-check"><input class="form-check-input rw-dow" type="checkbox" value="3" id="rw_wed"><label class="form-check-label" for="rw_wed">Wed</label></div>
                    <div class="form-check"><input class="form-check-input rw-dow" type="checkbox" value="4" id="rw_thu"><label class="form-check-label" for="rw_thu">Thu</label></div>
                    <div class="form-check"><input class="form-check-input rw-dow" type="checkbox" value="5" id="rw_fri"><label class="form-check-label" for="rw_fri">Fri</label></div>
                    <div class="form-check"><input class="form-check-input rw-dow" type="checkbox" value="6" id="rw_sat"><label class="form-check-label" for="rw_sat">Sat</label></div>
                    <div class="form-check"><input class="form-check-input rw-dow" type="checkbox" value="0" id="rw_sun"><label class="form-check-label" for="rw_sun">Sun</label></div>
                  </div>
                </div>
                </div>
              </div>

              <div class="booking-card">
                <div class="booking-card-header"><span class="step-num">2</span> Time &amp; templates</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Order template (optional)</label>
                  <select class="form-select" id="rw_order_tpl"></select>
                  <div class="form-text">Loads client/rate/status/VAT/driver/shift.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Shift template</label>
                  <select class="form-select" id="rw_shift_tpl"></select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Start</label>
                  <input type="time" class="form-control" id="rw_start_time" placeholder="HH:MM">
                </div>
                <div class="col-md-2">
                  <label class="form-label">End</label>
                  <input type="time" class="form-control" id="rw_end_time" placeholder="HH:MM">
                </div>
              </div>
              </div>

              <div class="booking-card">
                <div class="booking-card-header"><span class="step-num">3</span> Client, team &amp; pricing</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Client</label>
                  <select class="form-select" id="rw_client" required></select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Worker(s)</label>
                  <select class="form-select" id="rw_workers" multiple required></select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Hourly rate (AED)</label>
                  <input type="number" step="0.01" class="form-control" id="rw_rate" placeholder="From client">
                </div>
                <div class="col-md-2">
                  <label class="form-label">VAT mode</label>
                  <select class="form-select" id="rw_vat">
                    <option value="yes">Yes (fee includes VAT)</option>
                    <option value="no">No (add VAT)</option>
                  </select>
                </div>
              </div>
              
              <div id="rwClientPreferencesBox" class="alert alert-info mt-3 mb-0 py-2" style="display:none;">
                <h6 class="mb-2 small fw-semibold"><i class="bi bi-info-circle"></i> Client preferences</h6>
                <div class="row small">
                  <div class="col-md-6">
                    <p class="mb-1"><strong>Preferred workers:</strong> <span id="rw_pref_workers">—</span></p>
                    <p class="mb-0"><strong>VAT mode:</strong> <span id="rw_pref_vat">—</span></p>
                  </div>
                  <div class="col-md-6">
                    <p class="mb-1"><strong>Special instructions:</strong> <span id="rw_pref_instructions">—</span></p>
                    <p class="mb-0"><strong>Access:</strong> <span id="rw_pref_access">—</span></p>
                  </div>
                </div>
              </div>
              
              <div class="row g-3 mt-2">
                <div class="col-md-4">
                  <label class="form-label">Driver (optional)</label>
                  <select class="form-select" id="rw_driver"></select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Initial status</label>
                  <select class="form-select" id="rw_status">
                    <option value="scheduled" selected>Scheduled (open)</option>
                    <option value="confirmed">Confirmed (open)</option>
                    <option value="draft">Draft (open)</option>
                  </select>
                  <div class="form-text">Credit limit check applies only when status is <strong>Confirmed</strong>.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Exclude dates (comma-separated)</label>
                  <input class="form-control" id="rw_exclude" placeholder="YYYY-MM-DD, YYYY-MM-DD, ...">
                </div>
                <div class="col-md-12">
                <label class="form-label">Remark (optional)</label>
                <input class="form-control" id="rw_remark" placeholder="Optional note">
                </div>
                       
              </div>
              </div>

              <div id="rw_err" class="text-danger small mt-3" style="display:none"></div>

              <hr class="my-3">

              <!-- Preview -->
              <div id="rw_preview_wrap" style="display:none">
                <div id="rw_warnings_block"></div>
                <div id="rw_credit_block" class="mb-2">
                  <div id="rw_credit_alert" class="alert alert-warning py-2 px-3 mb-2" style="display:none;"></div>
                  <div id="rw_override_wrap" class="form-check mb-2" style="display:none;">
                    <input class="form-check-input" type="checkbox" id="rw_override_credit_limit" value="1">
                    <label class="form-check-label" for="rw_override_credit_limit">Proceed even if the client exceeds their credit limit</label>
                  </div>
                  <div id="rw_credit_ok" class="alert alert-success py-2 px-3 mb-2" style="display:none;">
                    Client within credit limit ✓
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                  <div>
                    <div class="small text-muted" id="rw_summary">—</div>
                    <div class="small fw-semibold" id="rw_batch_total" style="display:none"></div>
                  </div>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="rw_preview_again">Preview again</button>
                </div>
                <div class="table-responsive" style="max-height:40vh">
                  <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th style="white-space:nowrap">Date</th>
                        <th>Time</th>
                        <th>Workers</th>
                        <th>Subtotal</th>
                        <th>VAT</th>
                        <th>Total</th>
                        <th>Validation</th>
                      </tr>
                    </thead>
                    <tbody id="rw_preview_tbody"></tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="button" class="btn btn-outline-primary" id="rw_do_preview">Preview</button>
              <button type="button" class="btn btn-primary" id="rw_do_commit" disabled>Create orders</button>
            </div>
          </form>
        </div>
      </div>
      
                        <!-- Driver Calendar modal -->
                        <div class="modal fade" id="driverCalModal" tabindex="-1" aria-hidden="true">
                          <div class="modal-dialog">
                            <form class="modal-content" id="driverCalForm" onsubmit="return false;">
                              <div class="modal-header">
                                <h5 class="modal-title">Open driver calendar feed</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                              </div>
                              <div class="modal-body">
                                <label class="form-label">Driver</label>
                                <select class="form-select" id="cal_driver_select" required></select>
                                <div class="form-text">Opens this driver’s personal ICS link in a new tab. Paste that link into Google/Apple/Outlook Calendar.</div>
                                <div id="cal_driver_err" class="text-danger small mt-2" style="display:none"></div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-success" id="cal_open_btn">Open calendar link</button>
                              </div>
                            </form>
                          </div>
                        </div>
                        
                        <!-- Driver Daily Schedule modal -->
                        <div class="modal fade" id="driverPdfModal" tabindex="-1" aria-hidden="true">
                          <div class="modal-dialog">
                            <form class="modal-content" id="driverPdfForm">
                              <div class="modal-header">
                                <h5 class="modal-title">Driver Daily PDF</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                              </div>
                              <div class="modal-body">
                                <div class="mb-2">
                                  <label class="form-label">Driver</label>
                                  <select id="pdf_driver_select" class="form-select" required></select>
                                </div>
                                <div class="mb-2">
                                  <label class="form-label">Date</label>
                                  <input id="pdf_date" type="date" class="form-control" required>
                                </div>
                                <div id="pdf_err" class="text-danger small" style="display:none"></div>
                              </div>
                              <div class="modal-footer">
                                <button class="btn btn-primary" id="pdf_open_btn" type="button">Open PDF</button>
                                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Close</button>
                              </div>
                            </form>
                          </div>
                        </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let DAY   = "<?=h($day)?>";
let START = "<?=h($dayStart)?>";
let END   = "<?=h($dayEnd)?>";
let SLOT  = <?= (int)$slotMin ?>;
let VIEW  = "<?= h($_GET['view'] ?? 'day') ?>"; // 'day' | '3d' | 'week'
const SHOWBUSY = <?= $showBusy ? 'true':'false' ?>;
const PALETTE = <?= json_encode($palette) ?>;
const OPS_OPEN_STATUSES = new Set(['draft','scheduled','confirmed','in_progress']);
const OPS_COMPLETED_STATUSES = new Set(['completed','invoiced','paid']);
const OPS_CLICK_STATUSES = ['scheduled','confirmed','in_progress'];
const EDIT_STATUSES = ['draft','scheduled','confirmed','in_progress','completed','cancelled'];
let OPS_FILTER = <?= json_encode($opsFilter) ?>;
const FALLBACK = {
  clients: <?= json_encode($fallback_clients) ?>,
  workers: <?= json_encode($fallback_workers) ?>,
  drivers: <?= json_encode($fallback_drivers) ?>,
  companies: <?= json_encode($fallback_companies) ?>,
  services: <?= json_encode($fallback_services ?? []) ?>,
  service_categories: <?= json_encode($serviceCategories) ?>
};
const DEFER_AUTO_INVOICE = <?= $deferAutoInvoice ? 'true' : 'false' ?>;
const BOOKING_CATEGORIES = <?= json_encode($serviceCategories) ?>;
const DEFAULT_SERVICE_CATEGORY_ID = <?= (int)$defaultServiceCategoryId ?>;
const SERVICE_CATEGORIES = BOOKING_CATEGORIES;

let ALL_SERVICES_CACHE = [];

function bkSelectedCategoryIds(prefix) {
  return [...document.querySelectorAll(`#${prefix}_category_chips input[name="booking_category_ids[]"]:checked`)]
    .map(el => parseInt(el.value, 10))
    .filter(id => id > 0);
}

function bkIsCleaningSelected(prefix) {
  return [...document.querySelectorAll(`#${prefix}_category_chips input[data-code="cleaning"]:checked`)].length > 0;
}

function bkGetCleaningCategory() {
  return BOOKING_CATEGORIES.find(c => c.code === 'cleaning') || null;
}

function bkUpdatePrimaryCategoryHidden(prefix) {
  const ids = bkSelectedCategoryIds(prefix);
  const cleaning = bkGetCleaningCategory();
  const primary = (cleaning && ids.includes(+cleaning.id)) ? cleaning.id : (ids[0] || DEFAULT_SERVICE_CATEGORY_ID);
  const hidden = document.getElementById(`${prefix}_service_category_primary`);
  if (hidden) hidden.value = String(primary);
}

function bkCatalogRowHtml(cat, itemId = '', qty = 1, price = 0, unit = 'job') {
  const opts = (cat.catalog || []).map(s =>
    `<option value="${s.id}" data-price="${s.default_price}" ${+s.id === +itemId ? 'selected' : ''}>${esc(s.name)} (AED ${(+s.default_price).toFixed(2)})</option>`
  ).join('');
  return `<tr>
    <td>
      <select class="form-select form-select-sm bk-cat-item" name="catalog_item_id[]" required>
        <option value="">-- Select item --</option>${opts}
      </select>
      <input type="hidden" name="catalog_category_id[]" value="${cat.id}">
    </td>
    <td><input type="number" class="form-control form-control-sm bk-cat-qty" name="catalog_qty[]" step="0.01" min="0" value="${qty}"></td>
    <td><select class="form-select form-control-sm bk-cat-unit" name="catalog_unit[]"><option ${unit==='job'?'selected':''}>job</option><option ${unit==='visit'?'selected':''}>visit</option><option ${unit==='pcs'?'selected':''}>pcs</option></select></td>
    <td><input type="number" class="form-control form-control-sm bk-cat-price" name="catalog_price[]" step="0.01" min="0" value="${price}"></td>
    <td class="text-end"><span class="bk-cat-line">0.00</span></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger bk-cat-del">&times;</button></td>
  </tr>`;
}

function bkHookCatalogRow(prefix, tr) {
  const recalc = () => { bkRecalcCatalogLines(prefix); bkRefreshQuote(prefix); };
  tr.querySelectorAll('input,select').forEach(el => {
    el.addEventListener('input', recalc);
    el.addEventListener('change', () => {
      if (el.classList.contains('bk-cat-item')) {
        const opt = el.options[el.selectedIndex];
        const priceEl = tr.querySelector('.bk-cat-price');
        if (opt && opt.value && priceEl) priceEl.value = (+opt.getAttribute('data-price') || 0).toFixed(2);
      }
      recalc();
    });
  });
  tr.querySelector('.bk-cat-del')?.addEventListener('click', () => { tr.remove(); recalc(); });
}

function bkAddCatalogRow(prefix, cat, preset = null) {
  if (!cat.catalog || !cat.catalog.length) {
    alert('Add catalog items for ' + cat.name + ' in Settings → Service Categories first.');
    return;
  }
  const body = document.querySelector(`#${prefix}_catalog_panels .bk-catalog-body[data-cat-id="${cat.id}"]`);
  if (!body) return;
  body.insertAdjacentHTML('beforeend', bkCatalogRowHtml(
    cat,
    preset?.service_id || '',
    preset?.qty ?? 1,
    preset?.unit_price ?? 0,
    preset?.unit || 'job'
  ));
  bkHookCatalogRow(prefix, body.lastElementChild);
  bkRecalcCatalogLines(prefix);
  bkRefreshQuote(prefix);
}

function bkRecalcCatalogLines(prefix) {
  document.querySelectorAll(`#${prefix}_catalog_panels .bk-catalog-body tr`).forEach(tr => {
    const qty = parseFloat(tr.querySelector('.bk-cat-qty')?.value) || 0;
    const price = parseFloat(tr.querySelector('.bk-cat-price')?.value) || 0;
    const line = tr.querySelector('.bk-cat-line');
    if (line) line.textContent = (qty * price).toFixed(2);
  });
}

function bkRenderCatalogPanels(prefix, preserveExisting = true) {
  const wrap = document.getElementById(`${prefix}_catalog_panels`);
  if (!wrap) return;
  const selected = bkSelectedCategoryIds(prefix);
  const nonCleaning = BOOKING_CATEGORIES.filter(c => !c.is_cleaning && selected.includes(+c.id));
  const existing = {};
  if (preserveExisting) {
    wrap.querySelectorAll('.bk-catalog-body tr').forEach(tr => {
      const catId = tr.closest('.booking-card')?.dataset?.catId;
      if (!catId) return;
      if (!existing[catId]) existing[catId] = [];
      existing[catId].push({
        service_id: tr.querySelector('.bk-cat-item')?.value,
        qty: tr.querySelector('.bk-cat-qty')?.value,
        unit_price: tr.querySelector('.bk-cat-price')?.value,
        unit: tr.querySelector('.bk-cat-unit')?.value,
      });
    });
  }
  wrap.innerHTML = '';
  let step = prefix === 'e' ? 5 : 5;
  nonCleaning.forEach(cat => {
    const panel = document.createElement('div');
    panel.className = 'booking-card';
    panel.dataset.catId = String(cat.id);
    panel.innerHTML = `
      <div class="booking-card-header"><span class="step-num">${step++}</span> ${esc(cat.icon || '')} ${esc(cat.name)} — catalog items</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-2">
          <thead><tr>
            <th style="width:40%">Item</th><th style="width:15%">Qty</th><th style="width:15%">Unit</th>
            <th style="width:20%">Unit price (AED)</th><th style="width:10%" class="text-end">Line</th><th></th>
          </tr></thead>
          <tbody class="bk-catalog-body" data-cat-id="${cat.id}"></tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary bk-catalog-add" data-cat-id="${cat.id}">+ Add ${esc(cat.name)} item</button>
    `;
    wrap.appendChild(panel);
    panel.querySelector('.bk-catalog-add').addEventListener('click', () => bkAddCatalogRow(prefix, cat));
    (existing[cat.id] || []).forEach(row => {
      if (row.service_id) bkAddCatalogRow(prefix, cat, row);
    });
  });
}

function bkComputeTotals(prefix) {
  const vatEl = document.getElementById(`${prefix}_vat`);
  const rateEl = document.getElementById(`${prefix}_rate`);
  const startEl = document.getElementById(`${prefix}_start`) || document.getElementById(`${prefix}_start_time`);
  const endEl = document.getElementById(`${prefix}_end`) || document.getElementById(`${prefix}_end_time`);
  const workersEl = document.getElementById(`${prefix}_workers`);
  const vatIncl = (vatEl?.value !== 'no');
  const vr = 0.05;
  const cleaningOn = bkIsCleaningSelected(prefix);
  const cleaning = bkGetCleaningCategory();
  const rate = parseFloat(rateEl?.value || '0') || 0;
  const start = startEl?.value, end = endEl?.value;
  const selWorkers = [...(workersEl?.selectedOptions || [])].length || 1;
  const hours = (start && end) ? Math.max(0, (toMin(end) - toMin(start)) / 60) * selWorkers : 0;
  const needMat = (document.getElementById(`${prefix}_need_materials`)?.value === '1');
  const matRate = cleaning ? (+cleaning.materials_rate_per_hour || 0) : 0;
  let sub = 0;
  const parts = [];
  if (cleaningOn && hours > 0 && rate > 0) {
    let labourSub;
    if (vatIncl) {
      const netRate = rate / (1 + vr);
      labourSub = +(netRate * hours).toFixed(2);
    } else {
      labourSub = +(rate * hours).toFixed(2);
    }
    sub += labourSub;
    parts.push(`Cleaning labour: AED ${labourSub.toFixed(2)}`);
    if (needMat && matRate > 0) {
      const matSub = +(matRate * hours).toFixed(2);
      sub += matSub;
      parts.push(`Materials: AED ${matSub.toFixed(2)}`);
    }
  }
  document.querySelectorAll(`#${prefix}_catalog_panels .bk-catalog-body tr`).forEach(tr => {
    const qty = parseFloat(tr.querySelector('.bk-cat-qty')?.value) || 0;
    const price = parseFloat(tr.querySelector('.bk-cat-price')?.value) || 0;
    if (qty > 0 && price >= 0) {
      const lineSub = +(qty * price).toFixed(2);
      sub += lineSub;
      const name = tr.querySelector('.bk-cat-item')?.selectedOptions?.[0]?.text?.split(' (')[0] || 'Item';
      parts.push(`${name}: AED ${lineSub.toFixed(2)}`);
    }
  });
  sub = +sub.toFixed(2);
  let vat = 0, tot = sub;
  if (vatIncl) { tot = +(sub * (1 + vr)).toFixed(2); vat = +(tot - sub).toFixed(2); }
  else { vat = +(sub * vr).toFixed(2); tot = +(sub + vat).toFixed(2); }
  return { sub, vat, tot, parts, hours, cleaningOn };
}

function bkRefreshQuote(prefix) {
  const { sub, vat, tot, parts, hours, cleaningOn } = bkComputeTotals(prefix);
  const quoteBox = document.getElementById(`${prefix}_quote_summary`);
  const quoteTotal = document.getElementById(`${prefix}_quote_total`);
  const quoteBreak = document.getElementById(`${prefix}_quote_breakdown`);
  if (tot > 0 || parts.length) {
    if (quoteBox) quoteBox.style.display = '';
    if (quoteTotal) quoteTotal.textContent = 'AED ' + tot.toFixed(2);
    if (quoteBreak) {
      quoteBreak.innerHTML = parts.join('<br>') +
        (vat > 0 ? `<br><span class="text-muted">VAT: AED ${vat.toFixed(2)}</span>` : '') +
        (cleaningOn && hours > 0 ? `<br><span class="text-muted">${hours.toFixed(2)} worker-hours</span>` : '');
    }
  } else if (quoteBox) {
    quoteBox.style.display = 'none';
  }
  if (prefix === 'f') crEvaluate();
  else if (prefix === 'e') evaluateCreditForEdit(window.__currentEditOrder__);
}

function bkApplyCategoryUI(prefix, opts = {}) {
  const preserveCatalog = opts.preserveCatalog !== false;
  bkUpdatePrimaryCategoryHidden(prefix);
  const cleaningOn = bkIsCleaningSelected(prefix);
  const cleaningPanel = document.getElementById(`${prefix}_cleaning_panel`);
  if (cleaningPanel) cleaningPanel.style.display = cleaningOn ? '' : 'none';
  const cleaning = bkGetCleaningCategory();
  const hint = document.getElementById(`${prefix}_materials_rate_hint`);
  if (hint && cleaning) {
    const rate = (+cleaning.materials_rate_per_hour || 0).toFixed(2);
    hint.textContent = cleaningOn && +rate > 0
      ? `Materials surcharge: AED ${rate} per hour per worker (set in Settings → Service Categories).`
      : (cleaningOn ? 'Materials rate not configured — set it in Settings → Service Categories.' : '');
  }
  bkRenderCatalogPanels(prefix, preserveCatalog);
  bkRefreshQuote(prefix);
}

function mountWorkerPicker({ selectId, gridId, searchId, metaId, workers, preselected = [], onChange = null }) {
  const selectEl = document.getElementById(selectId);
  const gridEl = document.getElementById(gridId);
  const searchEl = searchId ? document.getElementById(searchId) : null;
  const metaEl = metaId ? document.getElementById(metaId) : null;
  if (!selectEl || !gridEl) return;

  selectEl.innerHTML = workers.map(w => `<option value="${w.id}">${esc(w.nickname || ('#' + w.id))}</option>`).join('');
  const selectedSet = new Set((preselected || []).map(id => String(id)));

  function syncMeta() {
    const count = [...selectEl.selectedOptions].length;
    if (metaEl) metaEl.textContent = count
      ? `${count} worker${count === 1 ? '' : 's'} selected`
      : 'Select one or more workers for this job.';
  }

  function syncFromChips() {
    const ids = [...gridEl.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
    [...selectEl.options].forEach(o => { o.selected = ids.includes(o.value); });
    syncMeta();
    if (onChange) onChange();
    else selectEl.dispatchEvent(new Event('change'));
  }

  function render(filter = '') {
    const q = filter.toLowerCase().trim();
    gridEl.innerHTML = workers
      .filter(w => !q || (w.nickname || '').toLowerCase().includes(q) || String(w.id).includes(q))
      .map(w => {
        const checked = selectedSet.has(String(w.id)) ? 'checked' : '';
        return `<label class="worker-chip mb-0">
          <input type="checkbox" value="${w.id}" ${checked}>
          <span>${esc(w.nickname || ('#' + w.id))}</span>
        </label>`;
      }).join('');
    gridEl.querySelectorAll('input[type=checkbox]').forEach(cb => {
      cb.addEventListener('change', () => {
        if (cb.checked) selectedSet.add(cb.value); else selectedSet.delete(cb.value);
        syncFromChips();
      });
    });
    syncMeta();
  }

  if (searchEl) searchEl.oninput = () => render(searchEl.value);
  render();
  syncFromChips();
}

function bkWireCategoryListeners(prefix) {
  document.querySelectorAll(`#${prefix}_category_chips input[name="booking_category_ids[]"]`).forEach(el => {
    if (el.dataset.bkWired) return;
    el.dataset.bkWired = '1';
    el.addEventListener('change', () => {
      const checked = bkSelectedCategoryIds(prefix);
      if (!checked.length) {
        el.checked = true;
        alert('At least one service category is required.');
        return;
      }
      bkApplyCategoryUI(prefix);
    });
  });
  ['start', 'end', 'start_time', 'end_time', 'rate', 'vat', 'need_materials'].forEach(suffix => {
    const el = document.getElementById(`${prefix}_${suffix}`);
    if (el && !el.dataset.bkWired) {
      el.dataset.bkWired = '1';
      el.addEventListener('input', () => bkRefreshQuote(prefix));
      el.addEventListener('change', () => bkRefreshQuote(prefix));
    }
  });
  const workersEl = document.getElementById(`${prefix}_workers`);
  if (workersEl && !workersEl.dataset.bkWired) {
    workersEl.dataset.bkWired = '1';
    workersEl.addEventListener('change', () => bkRefreshQuote(prefix));
  }
}

function f_getSelectedCategoryIds() { return bkSelectedCategoryIds('f'); }
function f_isCleaningSelected() { return bkIsCleaningSelected('f'); }
function f_getCleaningCategory() { return bkGetCleaningCategory(); }
function f_updatePrimaryCategoryHidden() { bkUpdatePrimaryCategoryHidden('f'); }
function f_applyBookingCategoryUI() { bkWireCategoryListeners('f'); bkApplyCategoryUI('f'); }
function f_computeBookingTotals() { return bkComputeTotals('f'); }
function refreshCalc() { bkRefreshQuote('f'); document.getElementById('calcBox').style.display = 'none'; }
function refreshEditCalc() { bkRefreshQuote('e'); document.getElementById('editCalcBox').style.display = 'none'; }

function smCategoryCodeFromSelect(selectEl) {
  if (!selectEl || selectEl.selectedIndex < 0) return 'cleaning';
  return (selectEl.options[selectEl.selectedIndex]?.getAttribute('data-code') || 'cleaning').toLowerCase();
}

function smServicesForCategory(categoryId) {
  const cid = parseInt(categoryId, 10);
  if (!cid || !ALL_SERVICES_CACHE.length) return ALL_SERVICES_CACHE;
  return ALL_SERVICES_CACHE.filter(s => !s.service_category_id || +s.service_category_id === cid);
}

function smRefreshServiceLineDropdowns(tbodyId, cache) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  tbody.querySelectorAll('select[name^="svc_service_id"]').forEach(drop => {
    const cur = drop.value;
    const opts = cache.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
    drop.innerHTML = '<option value="">-- Select --</option>' + opts;
    if (cur) drop.value = cur;
  });
}

function smApplyCategoryContext(scope) {
  if (scope === 'create') { f_applyBookingCategoryUI(); return; }
  if (scope === 'edit') { bkWireCategoryListeners('e'); bkApplyCategoryUI('e'); return; }
  const sel = document.getElementById('rw_service_category');
  if (!sel) return;
  const hidden = document.getElementById('rw_service_category_id');
  if (hidden) hidden.value = sel.value;
}

function smWireCategoryListeners() {
  bkWireCategoryListeners('f');
  bkWireCategoryListeners('e');
  const rw = document.getElementById('rw_service_category');
  if (rw && !rw.dataset.smCatWired) {
    rw.dataset.smCatWired = '1';
    rw.addEventListener('change', () => smApplyCategoryContext('repeat'));
  }
}

// Configuration constants
const CONFIG = {
  SLOT_MIN: SLOT,
  VAT_RATE_DEFAULT: 0.05,
  OVERTIME_RATE: 10.00,
  SEARCH_DEBOUNCE_MS: 300,
  CACHE_TTL_MS: 300000,
  CURRENCY: 'AED',
  SHOW_FINANCIAL_TOTALS: <?= $showFinancialTotals ? 'true' : 'false' ?>,
  CSRF_TOKEN: <?= json_encode(csrf_token()) ?>,
  CAN_FINALIZE: <?= $canFinalizeUser ? 'true' : 'false' ?>,
  CAN_MARK_COMPLETE: <?= $canMarkCompleteUser ? 'true' : 'false' ?>,
  CAN_REQUEST_ADJ: <?= $canRequestAdjUser ? 'true' : 'false' ?>,
  CAN_APPROVE_ADJ: <?= $canApproveAdjUser ? 'true' : 'false' ?>,
  DEFER_AUTO_INVOICE: <?= $deferAutoInvoice ? 'true' : 'false' ?>
};

// Loading state helpers
function showLoading(btnElement, text = 'Loading...') {
  if (!btnElement) return;
  btnElement.dataset.originalText = btnElement.innerHTML;
  btnElement.disabled = true;
  btnElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + text;
}

function hideLoading(btnElement) {
  if (!btnElement) return;
  btnElement.disabled = false;
  btnElement.innerHTML = btnElement.dataset.originalText || 'Submit';
}

// Consistent API call helper
async function apiCall(url, options = {}) {
  try {
    const r = await fetch(url, options);
    const j = await r.json();
    if (!j.success && !j.ok) throw new Error(j.error || 'Request failed');
    return j;
  } catch (e) {
    console.error('API Error:', url, e);
    throw e;
  }
}

/**
 * Unified event handler for both mouse and touch events
 * @param {HTMLElement} element - Element to attach events to
 * @param {function} onStart - Handler for start (receives clientX, clientY)
 * @param {function} onMove - Handler for move (receives clientX, clientY)
 * @param {function} onEnd - Handler for end (receives clientX, clientY)
 */
function addDragSupport(element, onStart, onMove, onEnd) {
  const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
  
  let isActive = false;
  
  // Get coordinates from event (touch or mouse)
  const getCoords = (e) => {
    if (e.touches && e.touches.length > 0) {
      return {clientX: e.touches[0].clientX, clientY: e.touches[0].clientY};
    }
    return {clientX: e.clientX, clientY: e.clientY};
  };
  
  const handleStart = (e) => {
    if (e.button !== undefined && e.button !== 0) return; // Only left click for mouse
    isActive = true;
    const coords = getCoords(e);
    onStart(coords.clientX, coords.clientY, e);
  };
  
  const handleMove = (e) => {
    if (!isActive) return;
    const coords = getCoords(e);
    onMove(coords.clientX, coords.clientY, e);
  };
  
  const handleEnd = (e) => {
    if (!isActive) return;
    isActive = false;
    const coords = e.changedTouches ? 
      {clientX: e.changedTouches[0].clientX, clientY: e.changedTouches[0].clientY} :
      {clientX: e.clientX, clientY: e.clientY};
    onEnd(coords.clientX, coords.clientY, e);
  };
  
  // Attach appropriate events
  if (isTouchDevice) {
    element.addEventListener('touchstart', handleStart, {passive: false});
    document.addEventListener('touchmove', handleMove, {passive: false});
    document.addEventListener('touchend', handleEnd);
    document.addEventListener('touchcancel', handleEnd);
  } else {
    element.addEventListener('mousedown', handleStart);
    document.addEventListener('mousemove', handleMove);
    document.addEventListener('mouseup', handleEnd);
  }
  
  // Return cleanup function
  return () => {
    if (isTouchDevice) {
      element.removeEventListener('touchstart', handleStart);
      document.removeEventListener('touchmove', handleMove);
      document.removeEventListener('touchend', handleEnd);
      document.removeEventListener('touchcancel', handleEnd);
    } else {
      element.removeEventListener('mousedown', handleStart);
      document.removeEventListener('mousemove', handleMove);
      document.removeEventListener('mouseup', handleEnd);
    }
  };
}

// DOM refs
const dayPicker = document.getElementById('dayPicker');
const startTime = document.getElementById('startTime');
const endTime   = document.getElementById('endTime');
const slotSelect= document.getElementById('slotSelect');
const navPrev   = document.getElementById('navPrev');
const navToday  = document.getElementById('navToday');
const workerSearch = document.getElementById('workerSearch');
const toggleBusy = document.getElementById('toggleBusy');
const viewSelect = document.getElementById('viewSelect');
      
      
      // ===== Export Current View as CSV =====
      const btnExport = document.getElementById('btnExport');
      btnExport.addEventListener('click', () => {
        if (!payloadGlobal || !payloadGlobal.events) {
          alert('No data to export');
          return;
        }
        
        // Build CSV
        const rows = [
          ['Date', 'Worker', 'Client', 'Start Time', 'End Time', 'Status', 'Driver']
        ];
        
        const events = payloadGlobal.events || [];
        const workers = payloadGlobal.workersAll || payloadGlobal.workers || [];
        
        events.forEach(e => {
          const worker = workers.find(w => w.id === e.worker_id);
          rows.push([
            DAY,
            worker ? worker.nickname : 'Worker #' + e.worker_id,
            e.client || '',
            e.start_time || '',
            e.end_time || '',
            e.status || '',
            e.driver || ''
          ]);
        });
        
        // Convert to CSV
        const csv = rows.map(row => 
          row.map(cell => {
            const str = String(cell || '');
            // Escape quotes and wrap in quotes if contains comma/quote/newline
            if (str.includes(',') || str.includes('"') || str.includes('\n')) {
              return '"' + str.replace(/"/g, '""') + '"';
            }
            return str;
          }).join(',')
        ).join('\n');
        
        // Download
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `availability-${DAY}.csv`;
        link.click();
        URL.revokeObjectURL(link.href);
      });
      
      // ===== Driver Daily PDF (top-bar button) =====
      const btnDriverPDF   = document.getElementById('btnDriverPDF');
      const driverPdfModal = new bootstrap.Modal(document.getElementById('driverPdfModal'));
      const pdfSel         = document.getElementById('pdf_driver_select');
      const pdfDate        = document.getElementById('pdf_date');
      const pdfErr         = document.getElementById('pdf_err');
      const pdfOpenBtn     = document.getElementById('pdf_open_btn');

      // Open modal & populate
      btnDriverPDF.addEventListener('click', ()=>{
        const drivers = (payloadGlobal?.drivers && payloadGlobal.drivers.length) ? payloadGlobal.drivers : FALLBACK.drivers || [];
        pdfSel.innerHTML = '<option value="">-- Select Driver --</option>' +
          drivers.map(d => `<option value="${d.id}">${esc(d.nickname || ('Driver #'+d.id))}</option>`).join('');
        pdfDate.value = DAY; // default to the day you're viewing
        pdfErr.style.display = 'none';
        driverPdfModal.show();
      });

      // Open PDF in new tab (inline if Dompdf installed; otherwise print-friendly HTML)
      pdfOpenBtn.addEventListener('click', ()=>{
        const driverId = pdfSel.value;
        const date = pdfDate.value;
        if(!driverId || !date){
          pdfErr.textContent = 'Please choose driver and date.';
          pdfErr.style.display = '';
          return;
        }
        pdfErr.style.display = 'none';
        const url = `driver_schedule_pdf.php?driver=${encodeURIComponent(driverId)}&date=${encodeURIComponent(date)}`;
        window.open(url, '_blank', 'noopener');
      });
      
      // ===== Driver Calendar (top-bar button) =====
      const btnDriverCalendar = document.getElementById('btnDriverCalendar');
      const driverCalModalEl  = document.getElementById('driverCalModal');
      const driverCalModal    = new bootstrap.Modal(driverCalModalEl);
      const calSelect         = document.getElementById('cal_driver_select');
      const calErr            = document.getElementById('cal_driver_err');
      const calOpenBtn        = document.getElementById('cal_open_btn');

      // Open modal and populate drivers
      btnDriverCalendar.addEventListener('click', () => {
        const drivers = (payloadGlobal?.drivers && payloadGlobal.drivers.length)
          ? payloadGlobal.drivers
          : (FALLBACK.drivers || []);

        calSelect.innerHTML =
          '<option value="">-- Select Driver --</option>' +
          drivers.map(d =>
            `<option value="${d.id}">${(window.esc ? esc(d.nickname || ('Driver #'+d.id)) : (d.nickname || ('Driver #'+d.id)))}</option>`
          ).join('');

        // restore default modal body (select + note)
        const body = document.querySelector('#driverCalModal .modal-body');
        // (optional) if you changed the body previously, you can also re-render your original HTML here.

        calErr.style.display = 'none';
        driverCalModal.show();
      });

      // Build a copyable ICS link (no download) and render it in the modal
      calOpenBtn.addEventListener('click', async () => {
        const driverId = calSelect.value;
        if (!driverId) {
          calErr.textContent = 'Please choose a driver.';
          calErr.style.display = '';
          return;
        }
        calErr.style.display = 'none';

        try {
          const res = await fetch(`calendar_feed.php?driver=${encodeURIComponent(driverId)}&mode=link`, {
            credentials: 'same-origin'
          });
          if (!res.ok) throw new Error('HTTP ' + res.status);

          const data = await res.json();
          if (!data || !data.url) throw new Error('Bad response');

          const url = data.url;

          // Replace modal body with the link + copy button
          const body = document.querySelector('#driverCalModal .modal-body');
          body.innerHTML = `
            <label class="form-label fw-semibold">Driver’s calendar link</label>
            <div class="input-group mb-2">
              <input id="icsLinkInput" type="text" class="form-control" readonly value="${url}">
              <button id="copyIcsBtn" type="button" class="btn btn-outline-secondary">Copy</button>
            </div>
            <div class="small text-muted">
              Paste this URL into:
              <ul class="mb-0">
                <li>Google Calendar → Settings → <em>Add calendar</em> → <em>From URL</em></li>
                <li>Apple Calendar (Mac) → File → <em>New Calendar Subscription…</em></li>
                <li>Outlook → Calendar → <em>Add calendar</em> → <em>Subscribe from web</em></li>
              </ul>
            </div>
            <div class="mt-3">
              <a href="${url}" target="_blank" rel="noopener" class="btn btn-light">Test feed (will download)</a>
            </div>
          `;

          // Copy button wiring
          const copyBtn = body.querySelector('#copyIcsBtn');
          const linkInp = body.querySelector('#icsLinkInput');
          copyBtn.addEventListener('click', async () => {
            try {
              await navigator.clipboard.writeText(linkInp.value);
              copyBtn.textContent = 'Copied!';
              setTimeout(() => (copyBtn.textContent = 'Copy'), 1200);
            } catch {
              linkInp.select();
              document.execCommand('copy');
              copyBtn.textContent = 'Copied!';
              setTimeout(() => (copyBtn.textContent = 'Copy'), 1200);
            }
          });
        } catch (err) {
          calErr.textContent = 'Could not generate calendar link.';
          calErr.style.display = '';
          console.error(err);
        }
      });
      
      

      
      // ===== Repeat Wizard =====
      const btnRepeat     = document.getElementById('btnRepeat');
      const repeatModalEl = document.getElementById('repeatModal');
      const repeatModal   = new bootstrap.Modal(repeatModalEl);
      const rw_err        = document.getElementById('rw_err');
      const rw_previewWrap= document.getElementById('rw_preview_wrap');
      const rw_previewT   = document.getElementById('rw_preview_tbody');
      const rw_summary    = document.getElementById('rw_summary');
      const rw_doPreview  = document.getElementById('rw_do_preview');
      const rw_prevAgain  = document.getElementById('rw_preview_again');
      const rw_doCommit   = document.getElementById('rw_do_commit');

      let lastPreviewItems = null;
      let rwListenersWired = false;
      let rwWorkerNameMap = {};

      function rwSetStep(n, label) {
        const b = document.getElementById('rw_step_badge');
        if (b) b.textContent = `Step ${n} — ${label}`;
      }
      function rwWorkerLabel(ids) {
        return (ids || []).map(id => rwWorkerNameMap[id] || ('#' + id)).join(', ');
      }

      // populate selects when opening
      btnRepeat.addEventListener('click', ()=>{
        // Fix: Null safety for payload
        const p = payloadCache || payloadGlobal || {};
        const clients  = Array.isArray(p.clients) ? p.clients : FALLBACK.clients || [];
        const workers  = Array.isArray(p.workersAll) ? p.workersAll : FALLBACK.workers || [];
        const drivers  = Array.isArray(p.drivers) ? p.drivers : FALLBACK.drivers || [];
        const shifts   = Array.isArray(p.templates) ? p.templates : [];
        const orders   = Array.isArray(p.order_templates) ? p.order_templates : [];

        // defaults
        document.getElementById('rw_start').value = DAY;
        document.getElementById('rw_weeks').value = 4;
        document.getElementById('rw_start_time').value = '';
        document.getElementById('rw_end_time').value   = '';
        document.getElementById('rw_exclude').value    = '';
        document.getElementById('rw_rate').value       = '';

        // clear + fill
        const selClient = document.getElementById('rw_client');
        selClient.innerHTML = '<option value="">-- Select Client --</option>' + clients.map(c=>`<option value="${c.id}" data-rate="${c.rate||''}" data-vr="${c.default_vat_rate||5}">${esc(c.client_name||('#'+c.id))}</option>`).join('');
        
        // Initialize Select2 for Repeat modal client dropdown
        $(selClient).select2({
          theme: 'bootstrap-5',
          placeholder: '-- Search Client --',
          allowClear: true,
          width: '100%',
          dropdownParent: $('#repeatModal')
        });

        const selWorkers = document.getElementById('rw_workers');
        selWorkers.innerHTML = workers.map(w=>`<option value="${w.id}">${esc(w.nickname||('#'+w.id))}</option>`).join('');
        rwWorkerNameMap = {};
        workers.forEach(w => { rwWorkerNameMap[w.id] = w.nickname || ('#' + w.id); });

        const selDriver = document.getElementById('rw_driver');
        selDriver.innerHTML = '<option value="">-- Select Driver --</option>'+drivers.map(d=>`<option value="${d.id}">${esc(d.nickname||('#'+d.id))}</option>`).join('');

        const selShift = document.getElementById('rw_shift_tpl');
        selShift.innerHTML = '<option value="">-- No shift template --</option>' + shifts.map(t=>`<option value="${t.id}" data-start="${t.start_time}" data-end="${t.end_time}">${esc(t.label)} (${t.start_time}–${t.end_time})</option>`).join('');

        const selOrderT = document.getElementById('rw_order_tpl');
        selOrderT.innerHTML = '<option value="">-- No order template --</option>' + orders.map(o=>`<option value="${o.id}" data-client="${o.client_id}" data-rate="${o.hourly_rate||''}" data-driver="${o.driver_id||''}" data-status="${o.status}" data-vat="${o.vat_included}" data-shift="${o.shift_template_id||''}">${esc(o.label)} ${o.client_name?('— '+esc(o.client_name)):''}</option>`).join('');
          
          // let order template drive defaults
          selOrderT.onchange = ()=>{
              const opt = selOrderT.options[selOrderT.selectedIndex];
              if (!opt || !opt.value) return;
              const client  = opt.getAttribute('data-client') || '';
              const rate    = opt.getAttribute('data-rate')   || '';
              const driver  = opt.getAttribute('data-driver') || '';
              const status  = opt.getAttribute('data-status') || 'scheduled';
              const vat     = opt.getAttribute('data-vat')    || 'yes';
              const shiftId = opt.getAttribute('data-shift')  || '';
              
              if (client) {
                  const ix=[...selClient.options].findIndex(o=>o.value===client);
                  if(ix>=0) selClient.selectedIndex=ix;
              }
              document.getElementById('rw_rate').value = rate ? Number(rate).toFixed(2) : '';
              if (driver) {
                  const ix=[...selDriver.options].findIndex(o=>o.value===driver);
                  if(ix>=0) selDriver.selectedIndex=ix;
              }
              document.getElementById('rw_status').value = status;
              document.getElementById('rw_vat').value    = (vat==='no'?'no':'yes');
              
              // shift template/time
              if (shiftId) {
                  const ix=[...selShift.options].findIndex(o=>o.value===String(shiftId));
                  if(ix>=0){ selShift.selectedIndex=ix;
                      const o2=selShift.options[selShift.selectedIndex];
                      document.getElementById('rw_start_time').value = o2.getAttribute('data-start') || '';
                      document.getElementById('rw_end_time').value   = o2.getAttribute('data-end')   || '';
                  }
              }
          };
          
          // shift template sets time
          selShift.onchange = ()=>{
              const opt=selShift.options[selShift.selectedIndex];
              if(opt && opt.value){
                  document.getElementById('rw_start_time').value = opt.getAttribute('data-start') || '';
                  document.getElementById('rw_end_time').value   = opt.getAttribute('data-end')   || '';
              }
          };
          
          // pick client → suggest rate if empty
          // Use jQuery event for Select2 compatibility
          $(selClient).on('change', async function() {
              const opt=selClient.options[selClient.selectedIndex];
              const rate=parseFloat(opt?.getAttribute('data-rate')||'0');
              if (rate>0 && !document.getElementById('rw_rate').value) {
                  document.getElementById('rw_rate').value = rate.toFixed(2);
              }
              
              // Fetch and display client preferences
              const clientId = selClient.value;
              if (clientId) {
                await loadClientPreferencesRepeat(clientId);
              } else {
                // Hide preferences box if no client selected
                document.getElementById('rwClientPreferencesBox').style.display = 'none';
              }
          });
          
          rw_err.style.display='none';
          rw_previewWrap.style.display='none';
          rw_doCommit.disabled = true;
          lastPreviewItems = null;
          rwSetStep(1, 'Setup');
          const warnBlk = document.getElementById('rw_warnings_block');
          if (warnBlk) warnBlk.innerHTML = '';
          const batchEl = document.getElementById('rw_batch_total');
          if (batchEl) batchEl.style.display = 'none';
          rwHideCredit();
          smWireCategoryListeners();
          smApplyCategoryContext('repeat');
            
            if (!rwListenersWired) {
              document.getElementById('rw_status')?.addEventListener('change', rwEvaluateCredit);
              document.getElementById('rw_client')?.addEventListener('change', rwEvaluateCredit);
              rwListenersWired = true;
            }
            
          repeatModal.show();
      });

            // build FormData for preview
        function rw_buildFD() {
        const fd = new FormData();
        fd.append('action','repeat_preview');
        //document.getElementById('rw_status')?.addEventListener('change', rwEvaluateCredit);
        //document.getElementById('rw_client')?.addEventListener('change', rwEvaluateCredit);
        const start = document.getElementById('rw_start').value;
        const weeks = document.getElementById('rw_weeks').value;
        fd.append('start_monday', start || DAY);
        fd.append('weeks', weeks || '4');

        // days of week
        document.querySelectorAll('.rw-dow:checked').forEach(cb=> fd.append('dow[]', cb.value));

        // selects/fields
        const orderTpl = document.getElementById('rw_order_tpl').value;
        if (orderTpl) fd.append('order_template_id', orderTpl);

        const shiftTpl = document.getElementById('rw_shift_tpl').value;
        if (shiftTpl) fd.append('shift_template_id', shiftTpl);

        const st = document.getElementById('rw_start_time').value;
        const en = document.getElementById('rw_end_time').value;
        if (st) fd.append('start_time', st);
        if (en) fd.append('end_time', en);

        const client = document.getElementById('rw_client').value;
        if (client) fd.append('client_id', client);

        const rate = document.getElementById('rw_rate').value;
        if (rate) fd.append('hourly_rate', rate);

        const driver = document.getElementById('rw_driver').value;
        if (driver) fd.append('driver_id', driver);

        fd.append('status', document.getElementById('rw_status').value || 'scheduled');
        fd.append('vat_included', document.getElementById('rw_vat').value || 'yes');

        // workers
        [...document.getElementById('rw_workers').selectedOptions].forEach(o=> fd.append('worker_ids[]', o.value));

        // exclude dates
        const excl = (document.getElementById('rw_exclude').value||'')
          .split(',')
          .map(s=>s.trim())
          .filter(s=>/^\d{4}-\d{2}-\d{2}$/.test(s));
        excl.forEach(d=> fd.append('exclude_dates[]', d));
      
        const remark = (document.getElementById('rw_remark').value || '').trim();
        fd.append('remark', remark);

        const catId = (document.getElementById('rw_service_category') || document.getElementById('rw_service_category_id'))?.value;
        if (catId) fd.append('service_category_id', catId);

        return fd;
      }

      async function rw_preview() {
          rw_err.style.display='none';
          rw_previewWrap.style.display='none';
          rw_doCommit.disabled = true;
          lastPreviewItems = null;

        showLoading(rw_doPreview, 'Previewing...');
        const fd = rw_buildFD();
        let j;
        try {
          const r = await fetch('api_availability.php',{method:'POST', body:fd});
          j = await r.json();
        } catch(e) {
          rw_err.textContent = 'Preview failed: Network error. Please check your connection.';
          rw_err.style.display='';
          hideLoading(rw_doPreview);
          return;
        }
        hideLoading(rw_doPreview);
        if (!j?.ok) {
          rw_err.textContent = (j?.error || 'Preview failed: Server returned an error');
          rw_err.style.display='';
          return;
        }

        lastPreviewItems = j.items || [];
        const okCount = (j.summary?.total_ok) ?? 0;
        const errCount= (j.summary?.total_errors) ?? 0;
        const warnCount = (j.warnings?.length) ?? 0;
        rw_summary.textContent = `Preview: ${lastPreviewItems.length} rows — OK: ${okCount}, With issues: ${errCount}${warnCount > 0 ? `, Overtime warnings: ${warnCount}` : ''}`;
        rwSetStep(2, 'Preview');

        const batchTotal = rwSumOkTotals(lastPreviewItems);
        const batchEl = document.getElementById('rw_batch_total');
        if (batchEl && batchTotal > 0) {
          batchEl.textContent = `Batch total (OK rows): AED ${batchTotal.toFixed(2)} — invoiced only after finalize`;
          batchEl.style.display = DEFER_AUTO_INVOICE ? '' : 'none';
        }
        
        // Display overtime warnings
        const warnBlock = document.getElementById('rw_warnings_block');
        warnBlock.innerHTML = '';
        if (j.warnings && j.warnings.length > 0) {
          const warningDiv = document.createElement('div');
          warningDiv.className = 'alert alert-warning mb-2 small';
          warningDiv.innerHTML = '<strong>Overtime warnings:</strong><br>' + j.warnings.map(esc).join('<br>');
          warnBlock.appendChild(warningDiv);
        }

        // render rows (optimize for large previews)
        const INITIAL_DISPLAY = 50;
        const shouldPaginate = lastPreviewItems.length > INITIAL_DISPLAY;
        const itemsToShow = shouldPaginate ? lastPreviewItems.slice(0, INITIAL_DISPLAY) : lastPreviewItems;
        
        const renderRow = (it) => {
          const errs = (it.errors||[]);
          const badge = errs.length
            ? `<span class="badge text-bg-danger">errors (${errs.length})</span><div class="small text-danger">${errs.map(esc).join('<br>')}</div>`
            : `<span class="badge text-bg-success">ok</span>`;
          const q = it.quote||{};
          const w = rwWorkerLabel(it.worker_ids||[]);
          return `<tr class="${errs.length ? 'table-warning' : ''}">
            <td>${esc(it.svc_date)}</td>
            <td>${esc(it.start_time)}–${esc(it.end_time)}</td>
            <td>${esc(w)}</td>
            <td>AED ${(q.subtotal??0).toFixed(2)}</td>
            <td>AED ${(q.vat??0).toFixed(2)}</td>
            <td><strong>AED ${(q.total??0).toFixed(2)}</strong></td>
            <td>${badge}</td>
          </tr>`;
        };
        
        rw_previewT.innerHTML = itemsToShow.map(renderRow).join('');
        
        // Add "Show More" button if needed
        if (shouldPaginate) {
          const showMoreRow = document.createElement('tr');
          showMoreRow.innerHTML = `<td colspan="7" class="text-center">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="rw_show_more">
              Show ${lastPreviewItems.length - INITIAL_DISPLAY} more rows...
            </button>
          </td>`;
          rw_previewT.appendChild(showMoreRow);
          
          document.getElementById('rw_show_more')?.addEventListener('click', function() {
            const remainingRows = lastPreviewItems.slice(INITIAL_DISPLAY).map(renderRow).join('');
            this.closest('tr').remove();
            rw_previewT.insertAdjacentHTML('beforeend', remainingRows);
          });
        }

rw_previewWrap.style.display='';
rw_doCommit.disabled = okCount<=0;

// ✅ now that lastPreviewItems exists, evaluate credit
await rwEvaluateCredit();
      }

      rw_doPreview.addEventListener('click', rw_preview);
      rw_prevAgain.addEventListener('click', rw_preview);

let isCommitting = false;
rw_doCommit.addEventListener('click', async ()=>{
  if (isCommitting) return; // Prevent double submission
  if (!lastPreviewItems || !lastPreviewItems.length) return;

  const okItems = lastPreviewItems.filter(it => !it.errors || it.errors.length===0);
  if (!okItems.length) { alert('Nothing to create (all rows have issues).'); return; }

  // CREDIT block: if confirming & warning visible & no override → block
  const statusVal = (document.getElementById('rw_status')?.value || 'scheduled').toLowerCase();
  const warningVisible = (rwAlert.style.display !== 'none');
  if (statusVal === 'confirmed' && warningVisible && !rwChk.checked) {
    rw_err.textContent = 'Credit limit exceeded for the batch. Tick the override checkbox to proceed.';
    rw_err.style.display = '';
    rwAlert.scrollIntoView({behavior:'smooth', block:'center'});
    return;
  }

  isCommitting = true;
  showLoading(rw_doCommit, 'Creating orders...');
  const fd = new FormData();
  fd.append('action','repeat_commit');
  fd.append('items_json', JSON.stringify(okItems));
  if (rwChk.checked) fd.append('override_credit_limit','1');
  const remark = (document.getElementById('rw_remark').value || '').trim();
  if (remark) fd.append('remark', remark);
  const catId = (document.getElementById('rw_service_category') || document.getElementById('rw_service_category_id'))?.value;
  if (catId) fd.append('service_category_id', catId);
  rwSetStep(3, 'Creating…');

  try{
    const r = await fetch('api_availability.php', {method:'POST', body:fd});
    const j = await r.json();
    if (!j?.success) throw new Error(j?.error || 'Commit failed');
    repeatModal.hide();
    location.reload();
  } catch(e) {
    rw_err.textContent = 'Failed to create orders: ' + (e.message || 'Unknown error');
    rw_err.style.display='';
    hideLoading(rw_doCommit);
  } finally {
    isCommitting = false;
  }
});
      // =================== End Repeat (wizard) ===================


const rwAlert = document.getElementById('rw_credit_alert');
const rwWrap  = document.getElementById('rw_override_wrap');
const rwChk   = document.getElementById('rw_override_credit_limit');
const rwOk    = document.getElementById('rw_credit_ok');

function rwHideCredit(){
  rwAlert.style.display='none';
  rwWrap.style.display='none';
  rwChk.checked=false;
  if (rwOk) rwOk.style.display='none';
}
function rwShowCredit(msg){
  rwAlert.textContent=msg;
  rwAlert.style.display='';
  rwWrap.style.display='';
  if (rwOk) rwOk.style.display='none';
}
function rwShowSuccess(msg){
  if (!rwOk) return;
  rwOk.textContent = msg || 'Client within credit limit ✓';
  rwOk.style.display = '';
  rwAlert.style.display='none';
  rwWrap.style.display='none';
}

// Sum of ok rows (server already returns quote totals in preview)
function rwSumOkTotals(items){
  let sum = 0;
  (items||[]).forEach(it=>{
    if (!it.errors || it.errors.length===0) {
      const q = it.quote || {};
      sum += +(q.total || 0);
    }
  });
  return +sum.toFixed(2);
}

// Evaluate credit after preview loads
async function rwEvaluateCredit(){
  rwHideCredit();

  const status = (document.getElementById('rw_status')?.value || 'scheduled').toLowerCase();
  if (status !== 'confirmed') return;
  const clientId = document.getElementById('rw_client')?.value;
  if (!clientId) return;
  if (!lastPreviewItems || !lastPreviewItems.length) return;

  const ar = await fetchAR(clientId);
  if (ar.credit_limit <= 0) return;

  const batchTotal = rwSumOkTotals(lastPreviewItems);
  const projected  = +(ar.outstanding + batchTotal).toFixed(2);
    if (projected > ar.credit_limit) {
      rwShowCredit(
        `Credit limit warning: Outstanding ${ar.currency} ${ar.outstanding.toFixed(2)} + this batch ${ar.currency} ${batchTotal.toFixed(2)} = ${ar.currency} ${projected.toFixed(2)} (limit ${ar.currency} ${ar.credit_limit.toFixed(2)}). Tick the override checkbox to proceed.`
      );
    } else {
      rwShowSuccess(
        `Client within credit limit ✓ (Outstanding ${ar.currency} ${ar.outstanding.toFixed(2)} / Limit ${ar.currency} ${ar.credit_limit.toFixed(2)})`
      );
    }
}



// Helpers
const toMin = t => { const [H,M]=(t||'00:00').split(':').map(Number); return (H||0)*60+(M||0); };
const pctBetween = (t,s,e) => ((toMin(t)-toMin(s))/(toMin(e)-toMin(s)))*100;
const esc = s => (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
const hhmmFromPct = p => {
  const total = toMin(START) + Math.round((p/100)*(toMin(END)-toMin(START))/SLOT)*SLOT;
  const H = String(Math.floor(total/60)).padStart(2,'0');
  const M = String(total%60).padStart(2,'0');
  return `${H}:${M}`;
};
const fmt = d => d.toISOString().slice(0,10);
function addDaysStr(iso, n){ const d=new Date(iso); d.setDate(d.getDate()+n); return fmt(d); }
function daysBetween(a,b){ const da=new Date(a), db=new Date(b); return Math.round((db-da)/(1000*60*60*24)); }

// View switch
viewSelect.addEventListener('change', ()=>{
  const url=new URL(location.href);
  url.searchParams.set('view', viewSelect.value);
  location.href = url.toString();
});

// Totals card
function renderTotalsCard(payload){
  const t = payload?.totals || {};
  document.getElementById('tw-day').textContent   = DAY;
  document.getElementById('tw-orders').textContent= t.orders_count ?? 0;
  document.getElementById('tw-hours').textContent = Number(t.hours_total ?? 0).toFixed(2);

  const money = t.money || {};
  document.getElementById('tw-sub').textContent   = 'AED ' + Number(money.subtotal ?? 0).toFixed(2);
  document.getElementById('tw-vat').textContent   = 'AED ' + Number(money.vat ?? 0).toFixed(2);
  document.getElementById('tw-total').textContent = 'AED ' + Number(money.grand_total ?? 0).toFixed(2);
}

// Time-off modal open
const btnAddTimeOff = document.getElementById('btnAddTimeOff');
const timeOffModal  = document.getElementById('timeOffModal');
const timeOffForm   = document.getElementById('timeOffForm');
const toErr         = document.getElementById('to_err');

btnAddTimeOff.addEventListener('click', ()=>{
  // Fix: Null safety for payload
  const p = payloadGlobal || payloadCache || {};
  const workers = Array.isArray(p.workersAll) ? p.workersAll : (Array.isArray(p.workers) ? p.workers : FALLBACK.workers || []);
  const sel = document.getElementById('to_worker');
  sel.innerHTML = workers.map(w=>`<option value="${w.id}">${esc(w.nickname || ('#'+w.id))}</option>`).join('');
  document.getElementById('to_date').value = DAY;
  document.getElementById('to_start').value = START;
  document.getElementById('to_end').value   = END;
  document.getElementById('to_absence_type').value = 'time_off';
  document.getElementById('to_reason').value = '';
  toErr.style.display='none';
  new bootstrap.Modal(timeOffModal).show();
});

document.getElementById('to_absence_type')?.addEventListener('change', function(){
  if (this.value === 'absent') {
    document.getElementById('to_start').value = START;
    document.getElementById('to_end').value = END;
    document.getElementById('to_reason').placeholder = 'Absent reason, e.g. sick, no show...';
  } else {
    document.getElementById('to_reason').placeholder = 'Vacation, sick, etc.';
  }
});

// URL controls
function pushURL() {
  const url=new URL(location.href);
  url.searchParams.set('day', dayPicker.value||DAY);
  url.searchParams.set('start', startTime.value||START);
  url.searchParams.set('end', endTime.value||END);
  url.searchParams.set('slot', slotSelect.value||SLOT);
  if (toggleBusy.checked) url.searchParams.set('show','busy'); else url.searchParams.delete('show');
  if (OPS_FILTER) url.searchParams.set('ops', OPS_FILTER); else url.searchParams.delete('ops');
  url.searchParams.delete('status');
  location.href=url.toString();
}
document.getElementById('applyView').addEventListener('click', pushURL);
navToday.addEventListener('click', ()=>{ dayPicker.value=new Date().toISOString().slice(0,10); pushURL(); });
navPrev.addEventListener('click', ()=>{ const d=new Date(dayPicker.value||DAY); d.setDate(d.getDate()-1); dayPicker.value=d.toISOString().slice(0,10); pushURL(); });

// Ops workflow filter chips (single-select)
document.querySelectorAll('.ops-filter-chip').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    OPS_FILTER = btn.dataset.opsFilter || '';
    pushURL();
  });
});

function eventMatchesOpsFilter(ev) {
  if (!OPS_FILTER) return true;
  if (OPS_FILTER === 'finalized') return !!ev.is_finalized;
  if (OPS_FILTER === 'open') return OPS_OPEN_STATUSES.has(ev.status) && !ev.is_finalized;
  if (OPS_FILTER === 'completed') return OPS_COMPLETED_STATUSES.has(ev.status) && !ev.is_finalized;
  if (OPS_FILTER === 'cancelled') return ev.status === 'cancelled';
  return true;
}

function removeWorkflowPopover() {
  document.querySelectorAll('.wa-workflow-popover').forEach(el => el.remove());
}

async function applyWorkflowStatus(orderId, newStatus, blk, prevStatus) {
  if (blk?.dataset?.finalized === '1' || blk?.classList?.contains('block-finalized')) {
    alert('This work order is finalized and locked.');
    return false;
  }
  const fd = new FormData();
  fd.append('action', 'edit');
  fd.append('order_id', String(orderId));
  fd.append('status', newStatus);
  if (newStatus === 'cancelled' && prevStatus !== 'cancelled') {
    const reason = await promptCancellationReason('Order #' + orderId);
    if (!reason) return false;
    fd.append('cancellation_category', reason.category);
    fd.append('cancellation_details', reason.details);
  }
  const r = await fetch('api_availability.php', { method: 'POST', body: fd });
  const j = await r.json();
  if (!j?.success) {
    alert(j?.error || 'Failed');
    return false;
  }
  if (newStatus === 'cancelled') {
    location.reload();
    return true;
  }
  if (blk) blk.style.background = PALETTE[newStatus] || blk.style.background;
  return true;
}

async function markCompleteFromBlock(orderId) {
  if (!confirm('Mark this job as completed?')) return false;
  const fd = new FormData();
  fd.append('order_id', String(orderId));
  fd.append('_csrf', CONFIG.CSRF_TOKEN || '');
  try {
    const res = await fetch('ajax_mark_complete.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      location.reload();
      return true;
    }
    alert(data.message || 'Could not mark complete');
  } catch (e) {
    alert('Request failed');
  }
  return false;
}

async function finalizeFromBlock(orderId) {
  if (!confirm('Finalize this work order? This will lock financial fields and create/post the invoice.')) return false;
  const fd = new FormData();
  fd.append('order_id', String(orderId));
  fd.append('_csrf', CONFIG.CSRF_TOKEN || '');
  try {
    const res = await fetch('ajax_finalize_order.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      location.reload();
      return true;
    }
    alert(data.message || 'Finalize failed');
  } catch (e) {
    alert('Finalize request failed');
  }
  return false;
}

async function showWorkflowPopover(o, blk, clientX, clientY) {
  removeWorkflowPopover();

  try {
    const j = await (await fetch(`api_availability.php?action=order&id=${encodeURIComponent(o.order_id)}`)).json();
    if (j?.success && j.order) {
      const wf = j.order.workflow || {};
      o = Object.assign({}, o, wf, {
        status: j.order.status || o.status,
        order_id: o.order_id,
      });
    }
  } catch (e) { /* keep calendar payload */ }

  const pop = document.createElement('div');
  pop.className = 'popover bs-popover-auto wa-workflow-popover';
  pop.style.position = 'absolute';
  pop.style.left = clientX + 'px';
  pop.style.top = (clientY + 10) + 'px';
  pop.style.zIndex = '1080';
  pop.style.minWidth = '220px';

  if (o.is_finalized || o.ops_locked) {
    pop.innerHTML = `<div class="popover-body small">
      <strong>Finalized &amp; locked</strong><br>${esc(o.ops_lock_reason || 'No changes from the calendar.')}<br>Double-click to view details or request an adjustment.
    </div>`;
    document.body.appendChild(pop);
    const off = (e2) => { if (!pop.contains(e2.target)) { document.removeEventListener('mousedown', off); pop.remove(); } };
    document.addEventListener('mousedown', off);
    return;
  }

  const status = o.status || 'confirmed';
  const canProgress = OPS_OPEN_STATUSES.has(status);
  const isCompleted = status === 'completed';
  let html = '<div class="popover-body p-2"><div class="small fw-semibold mb-2">Workflow</div>';

  if (o.financially_locked && o.financial_lock_reason) {
    html += `<div class="alert alert-warning py-1 px-2 small mb-2">${esc(o.financial_lock_reason)}</div>`;
  }

  if (canProgress) {
    html += '<div class="d-flex flex-wrap gap-1 mb-2">';
    OPS_CLICK_STATUSES.forEach(s => {
      const active = s === status ? ' active' : '';
      html += `<button type="button" class="btn btn-sm btn-outline-secondary wa-wf-status${active}" data-status="${s}">${s.replace('_',' ')}</button>`;
    });
    html += '</div>';
    if (CONFIG.CAN_MARK_COMPLETE !== false && o.can_mark_complete !== false) {
      html += '<button type="button" class="btn btn-sm btn-success w-100 mb-2 wa-wf-complete">Mark Complete</button>';
    }
  } else if (isCompleted) {
    html += '<button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-2 wa-wf-status" data-status="in_progress">Back to In Progress</button>';
    if (o.can_finalize !== false) {
      html += '<button type="button" class="btn btn-sm btn-warning w-100 mb-2 wa-wf-finalize">Finalize &amp; Generate Invoice</button>';
      html += '<div class="small text-muted mb-2">Locks amounts and creates the invoice.</div>';
    }
  } else if (OPS_COMPLETED_STATUSES.has(status)) {
    html += '<div class="small text-muted mb-2">Accounting status — double-click for details.</div>';
  }

  if (status !== 'cancelled') {
    if (o.can_direct_cancel !== false) {
      html += '<button type="button" class="btn btn-sm btn-outline-danger w-100 wa-wf-cancel">Cancel job</button>';
    } else {
      html += `<div class="small text-danger mt-1">${esc(o.cancel_block_reason || 'Cancel via Accounts (void / credit / refund).')}</div>`;
    }
  }
  html += '</div>';
  pop.innerHTML = html;
  document.body.appendChild(pop);

  const off = (e2) => { if (!pop.contains(e2.target)) { document.removeEventListener('mousedown', off); pop.remove(); } };
  document.addEventListener('mousedown', off);

  pop.querySelectorAll('.wa-wf-status').forEach(btn => {
    btn.addEventListener('click', async () => {
      const newStatus = btn.dataset.status;
      const ok = await applyWorkflowStatus(o.order_id, newStatus, blk, status);
      if (ok) pop.remove();
    });
  });
  pop.querySelector('.wa-wf-complete')?.addEventListener('click', async () => {
    const ok = await markCompleteFromBlock(o.order_id);
    if (ok) pop.remove();
  });
  pop.querySelector('.wa-wf-finalize')?.addEventListener('click', async () => {
    const ok = await finalizeFromBlock(o.order_id);
    if (ok) pop.remove();
  });
  pop.querySelector('.wa-wf-cancel')?.addEventListener('click', async () => {
    const ok = await applyWorkflowStatus(o.order_id, 'cancelled', blk, status);
    if (ok) pop.remove();
  });
}
    
// Fetch helpers
async function getJSON(u){
  try{ const r=await fetch(u); return await r.json(); }
  catch(e){ console.error('fetch json failed', u, e); return null; }
}

function promptCancellationReason(orderLabel) {
  return new Promise((resolve) => {
    const modalEl = document.getElementById('cancelReasonModal');
    const form = document.getElementById('cancelReasonForm');
    const category = document.getElementById('cancel_category');
    const details = document.getElementById('cancel_details');
    const err = document.getElementById('cancel_reason_error');
    if (!modalEl || !form || !category || !details) {
      resolve(null);
      return;
    }

    form.reset();
    err.style.display = 'none';
    modalEl.querySelector('.modal-title').textContent = 'Cancellation Reason' + (orderLabel ? ' - ' + orderLabel : '');
    const modal = new bootstrap.Modal(modalEl);
    let resolved = false;

    const cleanup = () => {
      form.removeEventListener('submit', onSubmit);
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
    };
    const onSubmit = (e) => {
      e.preventDefault();
      const cat = category.value.trim();
      const det = details.value.trim();
      if (!cat || !det) {
        err.textContent = 'Please select a category and enter cancellation details.';
        err.style.display = '';
        return;
      }
      resolved = true;
      cleanup();
      modal.hide();
      resolve({category: cat, details: det});
    };
    const onHidden = () => {
      if (!resolved) {
        cleanup();
        resolve(null);
      }
    };

    form.addEventListener('submit', onSubmit);
    modalEl.addEventListener('hidden.bs.modal', onHidden);
    modal.show();
  });
}


async function fetchAll(){
  const base=`api_availability.php?day=${encodeURIComponent(DAY)}&start=${encodeURIComponent(START)}&end=${encodeURIComponent(END)}&slot=${SLOT}`;
  let data=await getJSON(base); if(!data||!data.success) data={success:true,workers:[],events:[]};
  
    const [c, w, d, co, tpl, otpl, svcs, scats] = await Promise.all([
      getJSON('api_availability.php?action=clients'),
      getJSON('api_availability.php?action=workers'),
      getJSON('api_availability.php?action=drivers'),
      getJSON('api_availability.php?action=companies'),
      getJSON('api_availability.php?action=templates'),
      getJSON('api_availability.php?action=order_templates'),
      getJSON('api_availability.php?action=services'),
      getJSON('api_availability.php?action=service_categories'),
    ]);
  
  
  
  return {
    ...data,
    totals: data.totals || null,
    unavailability: Array.isArray(data.unavailability)?data.unavailability:[],
    clients:Array.isArray(c)&&c.length?c:FALLBACK.clients,
    workersAll:Array.isArray(w)&&w.length?w:FALLBACK.workers,
    drivers:Array.isArray(d)&&d.length?d:FALLBACK.drivers,
    companies:Array.isArray(co)&&co.length?co:FALLBACK.companies,
    templates:Array.isArray(tpl)?tpl:[],
    order_templates: Array.isArray(otpl) ? otpl : [],
    services: Array.isArray(svcs) ? svcs : [],
    service_categories: Array.isArray(scats) && scats.length ? scats : (FALLBACK.service_categories || []),
    };
}

function formatOrderBlockHtml(o) {
  const client = esc(o.client || '—');
  const cats = o.category_labels ? `<div class="block-cats">${esc(o.category_labels)}</div>` : '';
  const time = `${o.start_time}–${o.end_time}`;
  const driver = o.driver ? ` · ${esc(o.driver)}` : '';
  return `<div class="block-inner">
    <div class="block-client">${client}</div>
    ${cats}
    <div class="block-time">${time}${driver}</div>
  </div>`;
}

function orderBlockTitle(o) {
  const parts = [o.client || '—', o.category_labels, `${o.start_time}–${o.end_time}`];
  if (o.driver) parts.push(o.driver);
  return parts.filter(Boolean).join(' · ');
}

// Simple overlap badge
function hasConflicts(evs){
  if(!evs || evs.length<2) return false;
  const byStart=[...evs].sort((a,b)=>a.start_time.localeCompare(b.start_time));
  let prevEnd=null;
  for(const e of byStart){
    if(prevEnd && e.start_time < prevEnd) return true;
    prevEnd = e.end_time;
  }
  return false;
}

let payloadGlobal=null;

/**
 * Main render function - draws the worker availability grid
 * @param {Object} payload - Data payload containing workers, events, and unavailability
 * @param {Array} payload.workersAll - List of all workers
 * @param {Array} payload.events - List of order events/bookings
 * @param {Array} payload.unavailability - List of worker time-off blocks
 */
function renderGrid(payload){
  payloadGlobal=payload;
  const {workersAll=[],events=[]}=payload;
  const grid=document.getElementById('grid'); grid.innerHTML='';



  // filters
  const q=(workerSearch.value||'').toLowerCase().trim();

  const byWorker={};
  (events||[]).forEach(ev=>{
    if(!eventMatchesOpsFilter(ev)) return;
    (byWorker[+ev.worker_id] ||= []).push(ev);
  });

  // worker list after filters
  let list = workersAll.filter(w=> q==='' || (w.nickname||'').toLowerCase().includes(q));
  if (toggleBusy.checked) list = list.filter(w=> (byWorker[w.id]||[]).length>0);


  // Empty state message when no workers match filters
  if (list.length === 0) {
    grid.innerHTML = '<div class="alert alert-info mt-3">No workers match your filters. Try adjusting search or status filters.</div>';
    return;
  }

  const spanMin=toMin(END)-toMin(START);
  const cols=Math.max(1,Math.round(spanMin/SLOT));

  list.forEach(w=>{
    const row=document.createElement('div'); row.className='d-flex wa-grid-row'; row.dataset.workerId=w.id;
    const label=document.createElement('div'); label.className='row-label p-2 border-end bg-light d-flex flex-column';
    
    // Calculate worker's hours for the day
    const workerEvents = byWorker[w.id] || [];
    let workerHours = 0;
    workerEvents.forEach(ev => {
      const start = toMin(ev.start_time || '00:00');
      const end = toMin(ev.end_time || '00:00');
      workerHours += Math.max(0, (end - start) / 60);
    });
    
    // Get worker's daily capacity (if available) - convert to number
    const capacity = parseFloat(w.daily_cap_hours) || 0;
    const hasCapacity = capacity > 0;
    
    
    // Build capacity indicator
    let capacityHTML = '';
    if (hasCapacity) {
      const pct = Math.min(100, (workerHours / capacity) * 100);
      const color = pct >= 100 ? '#dc3545' : (pct >= 80 ? '#ffc107' : '#28a745');
      capacityHTML = `
        <div class="small text-muted" style="font-size:10px; margin-top:2px">
          ${workerHours.toFixed(1)}h / ${capacity.toFixed(0)}h
          <div class="progress" style="height:3px;margin-top:2px">
            <div class="progress-bar" style="width:${pct}%;background:${color}"></div>
          </div>
        </div>
      `;
    }
    
    // badges: compute BEFORE setting innerHTML (this was the bug)
    const warn  = hasConflicts(byWorker[w.id]);
    const workerUnavailability = (payload.unavailability || []).filter(u => +u.worker_id === +w.id);
    const hasTO = workerUnavailability.length > 0;
    const hasAbsent = workerUnavailability.some(u => (u.absence_type || '') === 'absent');
    const badges = [];
    if (warn)  badges.push('<span class="row-badge warn">⚠ overlap</span>');
    if (hasAbsent) badges.push('<span class="row-badge warn">absent</span>');
    else if (hasTO) badges.push('<span class="row-badge warn">time-off</span>');
    
    const leftLabel = `
      <div class="d-flex justify-content-between align-items-center w-100">
        <div class="fw-semibold">${esc(w.nickname || ('Worker #'+w.id))}</div>
        ${badges.length ? `<div>${badges.join(' ')}</div>` : ''}
      </div>
    `;
    
    label.innerHTML = leftLabel + capacityHTML;
    row.appendChild(label);

    const timeCol=document.createElement('div'); timeCol.className='flex-fill';
    const track=document.createElement('div'); track.className='track';

    // Unavailability bands
    const unavs = workerUnavailability;
    unavs.forEach(u=>{
      const L = pctBetween(u.start_time, START, END);
      const R = pctBetween(u.end_time,   START, END);
      const ua = document.createElement('div');
      const isAbsent = (u.absence_type || '') === 'absent';
      ua.className = 'unavail' + (isAbsent ? ' absent' : '');
      ua.style.left  = Math.max(0, L) + '%';
      ua.style.width = Math.max(0, R - L) + '%';
      ua.title = (isAbsent ? 'Absent' : 'Unavailable') + (u.reason ? ': ' + u.reason : '');
      track.appendChild(ua);
      ua.addEventListener('contextmenu', async (ev)=>{
        ev.preventDefault();
        // Improved confirmation message with details
        const confirmMsg = `Delete ${isAbsent ? 'absence' : 'time-off'}: ${u.start_time}–${u.end_time}${u.reason ? ' ('+u.reason+')' : ''}?`;
        if(!confirm(confirmMsg)) return;
        const fd = new FormData();
        fd.append('action','unavail_delete');
        fd.append('id', u.id);
        try {
          const r = await fetch('api_availability.php',{method:'POST',body:fd});
          const j = await r.json();
          if(j?.success){ location.reload(); } else { alert('Failed to delete: ' + (j?.error||'Unknown error')); }
        } catch(e) {
          alert('Failed to delete: Network error');
        }
      });
    });

    for(let i=0;i<=cols;i++){
      const s=document.createElement('div');
      const minuteOff = i * SLOT;
      const isHour = (minuteOff % 60) === 0;
      const isHalf = SLOT <= 30 && (minuteOff % 60) === 30;
      s.className = 'slot' + (isHour ? ' slot-hour' : (isHalf ? ' half' : ''));
      s.style.left=(i*(100/cols))+'%'; s.style.width='0'; track.appendChild(s);
    }

    (byWorker[w.id]||[]).forEach(o=>{
      const blk=document.createElement('div');
      const left=pctBetween(o.start_time,START,END);
      const right=pctBetween(o.end_time,START,END);
      blk.style.left=left+'%'; blk.style.width=Math.max(0,right-left)+'%';
      blk.style.background=PALETTE[o.status]||'#6c757d';
      blk.className='block'+(o.status==='cancelled'?' cancelled':'');
      if (o.is_finalized) {
        blk.classList.add('block-finalized');
      }
      blk.dataset.orderId=o.order_id; blk.dataset.workerId=w.id;
      blk.dataset.finalized = o.is_finalized ? '1' : '0';
      blk.title = orderBlockTitle(o);
      blk.innerHTML = formatOrderBlockHtml(o);

      // Distinguish single-click from double-click
      let clickTimer = null;
      
      blk.addEventListener('dblclick', ()=> {
        // Clear any pending single-click action
        if (clickTimer) {
          clearTimeout(clickTimer);
          clickTimer = null;
        }
        openEditModal(o.order_id);
      });

      // quick workflow popover (only if not double-clicked)
      blk.addEventListener('click',(ev)=>{
        ev.stopPropagation();
        if (o.is_finalized) {
          showWorkflowPopover(o, blk, ev.clientX, ev.clientY);
          return;
        }
        
        // Clear existing timer
        if (clickTimer) {
          clearTimeout(clickTimer);
        }
        
        // Wait 300ms to see if this is a double-click
        clickTimer = setTimeout(() => {
          clickTimer = null;
          showWorkflowPopover(o, blk, ev.clientX, ev.clientY);
        }, 300);
      });

      if (!o.is_finalized) {
      // resize
      const hL=document.createElement('div'); hL.className='resize-handle start';
      const hR=document.createElement('div'); hR.className='resize-handle end'; blk.appendChild(hL); blk.appendChild(hR);

      const doSave=async ()=>{
        const s=hhmmFromPct(parseFloat(blk.style.left));
        const e=hhmmFromPct(parseFloat(blk.style.left)+parseFloat(blk.style.width));
        const origS = String(o.start_time || '').substring(0, 5);
        const origE = String(o.end_time || '').substring(0, 5);
        // Click/double-click without a real resize must not POST (avoids silent money recalcs).
        if (s === origS && e === origE) return;
        const fd=new FormData(); fd.append('action','update'); fd.append('order_id',o.order_id); fd.append('start_time',s); fd.append('end_time',e);
        const r=await fetch('api_availability.php',{method:'POST',body:fd}); const j=await r.json();
        if(j?.success){
          const t = blk.querySelector('.block-time');
          if (t) t.textContent = `${j.start_time}–${j.end_time}`;
          o.start_time = j.start_time;
          o.end_time = j.end_time;
        }
        else { alert(j?.error||'Failed to update'); location.reload(); }
      };

      hL.addEventListener('mousedown',(e)=>{
        e.stopPropagation(); const rect=track.getBoundingClientRect(); const startPct=parseFloat(blk.style.left); const endPct=startPct+parseFloat(blk.style.width);
        const move=(ev)=>{ const cur=((ev.clientX-rect.left)/rect.width)*100; const minw=100*(SLOT/(toMin(END)-toMin(START))); const ns=Math.min(Math.max(0,cur), endPct-minw); blk.style.left=ns+'%'; blk.style.width=(endPct-ns)+'%'; };
        const up=()=>{document.removeEventListener('mousemove',move);document.removeEventListener('mouseup',up);doSave();};
        document.addEventListener('mousemove',move); document.addEventListener('mouseup',up);
      });
      hR.addEventListener('mousedown',(e)=>{
        e.stopPropagation(); const rect=track.getBoundingClientRect(); const startPct=parseFloat(blk.style.left);
        const move=(ev)=>{ const cur=((ev.clientX-rect.left)/rect.width)*100; const minw=100*(SLOT/(toMin(END)-toMin(START))); const ne=Math.max(startPct+minw,Math.min(100,cur)); blk.style.width=(ne-startPct)+'%'; };
        const up=()=>{document.removeEventListener('mousemove',move);document.removeEventListener('mouseup',up);doSave();};
        document.addEventListener('mousemove',move); document.addEventListener('mouseup',up);
      });

      // drag to move / reassign
      blk.addEventListener('mousedown',(e)=>{
        if(e.target.classList.contains('resize-handle')) return;
        const rectTrack=track.getBoundingClientRect();
        const startX=e.clientX; const startLeft=parseFloat(blk.style.left); const width=parseFloat(blk.style.width);
        const ghost=blk.cloneNode(true); ghost.style.opacity='0.6'; ghost.style.pointerEvents='none'; document.body.appendChild(ghost);
        const rowRects=[...document.querySelectorAll('.track')].map(tr=>({el:tr,rect:tr.getBoundingClientRect(),wid:tr.parentElement.parentElement.dataset.workerId}));
        function onMove(ev){
          ghost.style.position='fixed'; ghost.style.left=(ev.clientX-40)+'px'; ghost.style.top=(ev.clientY-12)+'px'; ghost.style.width='160px';
          const dx=ev.clientX-startX; const pctDelta=(dx/rectTrack.width)*100;
          const step=100*(SLOT/(toMin(END)-toMin(START)));
          let newLeft = Math.min(100-width, Math.max(0, startLeft + Math.round(pctDelta/step)*step ));
          blk.style.left=newLeft+'%';
        }
        async function onUp(ev){
          document.removeEventListener('mousemove',onMove); document.removeEventListener('mouseup',onUp); ghost.remove();
          let target=rowRects.find(r=>ev.clientY>=r.rect.top && ev.clientY<=r.rect.bottom);
          const s=hhmmFromPct(parseFloat(blk.style.left));
          const e2=hhmmFromPct(parseFloat(blk.style.left)+parseFloat(blk.style.width));
          const origS = String(o.start_time || '').substring(0, 5);
          const origE = String(o.end_time || '').substring(0, 5);
          const workerChanged = !!(target && target.wid != w.id);
          // No-op click (e.g. double-click to edit) must not trigger update/recalc.
          if (!workerChanged && s === origS && e2 === origE) return;
          const fd=new FormData(); fd.append('action','update'); fd.append('order_id',o.order_id); fd.append('start_time',s); fd.append('end_time',e2);
          if(workerChanged){ fd.append('new_worker_id', target.wid); }
          const r=await fetch('api_availability.php',{method:'POST',body:fd}); const j=await r.json();
          if(j?.success){
            const t = blk.querySelector('.block-time');
            if (t) t.textContent = `${j.start_time}–${j.end_time}`;
            o.start_time = j.start_time;
            o.end_time = j.end_time;
            if(workerChanged){ location.reload(); }
          } else { alert(j?.error||'Failed'); location.reload(); }
        }
        document.addEventListener('mousemove',onMove); document.addEventListener('mouseup',onUp);
      });
      }

      track.appendChild(blk);
    });

    // drag-to-create on empty area (with touch support)
    let dragging=false,startPct=0,ghost=null;
    
    const getEventCoords = (e) => {
      if (e.touches && e.touches.length > 0) {
        return {clientX: e.touches[0].clientX, clientY: e.touches[0].clientY};
      }
      return {clientX: e.clientX, clientY: e.clientY};
    };
    
    const handleDragStart = (e) => {
      if(e.button !== undefined && e.button !== 0) return; // mouse: only left click
      if(e.target.closest('.block')) return;
      e.preventDefault(); // Prevent scrolling on touch
      dragging=true;
      const r=track.getBoundingClientRect();
      const coords = getEventCoords(e);
      startPct=((coords.clientX-r.left)/r.width)*100;
      ghost=document.createElement('div');
      ghost.className='drag-ghost';
      ghost.style.left=startPct+'%';
      ghost.style.width='0%';
      track.appendChild(ghost);
    };
    
    const handleDragMove = (e) => {
      if(!dragging) return;
      e.preventDefault(); // Prevent scrolling
      const r=track.getBoundingClientRect();
      const coords = getEventCoords(e);
      const cur=((coords.clientX-r.left)/r.width)*100;
      const a=Math.min(startPct,cur), b=Math.max(startPct,cur);
      ghost.style.left=a+'%';
      ghost.style.width=(b-a)+'%';
    };
    
    async function finish(e){
      if(!dragging) return;
      dragging=false;
      const r=track.getBoundingClientRect();
      const coords = e.changedTouches ? 
        {clientX: e.changedTouches[0].clientX, clientY: e.changedTouches[0].clientY} :
        {clientX: e.clientX, clientY: e.clientY};
      const endPct=((coords.clientX-r.left)/r.width)*100;
      if(ghost&&ghost.parentNode) ghost.parentNode.removeChild(ghost);
      let t1=hhmmFromPct(Math.min(startPct,endPct)), t2=hhmmFromPct(Math.max(startPct,endPct));
      if(t1===t2){ const m=toMin(t1)+SLOT; t2=`${String(Math.floor(m/60)).padStart(2,'0')}:${String(m%60).padStart(2,'0')}`; }
      document.getElementById('f_date').value=DAY;
      document.getElementById('f_start').value=t1;
      document.getElementById('f_end').value=t2;
      await populateModalChoices(payload,w.id);
      new bootstrap.Modal(document.getElementById('createModal')).show();
      refreshCalc();
    }
    
    // Attach both mouse and touch events
    track.addEventListener('mousedown', handleDragStart);
    track.addEventListener('touchstart', handleDragStart, {passive: false});
    track.addEventListener('mousemove', handleDragMove);
    track.addEventListener('touchmove', handleDragMove, {passive: false});
    track.addEventListener('mouseup', finish);
    track.addEventListener('touchend', finish);
    track.addEventListener('mouseleave', (e)=>{ if(dragging) finish(e); });

    timeCol.appendChild(track); row.appendChild(timeCol); grid.appendChild(row);
  });
}

/**
 * Populate create modal dropdowns with clients, workers, drivers, etc.
 * @param {Object} payload - Data payload with lookup lists
 * @param {number} preId - Worker ID to pre-select in workers dropdown
 */
async function populateModalChoices(payload, preId){
  // Null safety: use payload or fallback to empty/fallback data
  const p = payload || {};
  const clients = Array.isArray(p.clients) ? p.clients : FALLBACK.clients || [];
  const workersAll = Array.isArray(p.workersAll) ? p.workersAll : FALLBACK.workers || [];
  const drivers = Array.isArray(p.drivers) ? p.drivers : FALLBACK.drivers || [];
  const services = Array.isArray(p.services) && p.services.length ? p.services : (FALLBACK.services || []);
  f_client.innerHTML='<option value="">-- Select Client --</option>'+clients.map(c=>`<option value="${c.id}" data-rate="${c.rate||''}" data-vr="${c.default_vat_rate||5}" data-lastdriver="${c.last_driver_id||''}">${esc(c.client_name||('Client #'+c.id))}</option>`).join('');
  
  // Initialize Select2 for searchable client dropdown
  $(f_client).select2({
    theme: 'bootstrap-5',
    placeholder: '-- Search Client --',
    allowClear: true,
    width: '100%',
    dropdownParent: $('#createModal'),
    matcher: function(params, data) {
      // If there are no search terms, return all data
      if ($.trim(params.term) === '') {
        return data;
      }
      // Search in text (case-insensitive)
      if (data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1) {
        return data;
      }
      // Return null if no match
      return null;
    }
  });
  
  f_workers.innerHTML=workersAll.map(w=>`<option value="${w.id}">${esc(w.nickname||('#'+w.id))}</option>`).join('');
  mountWorkerPicker({
    selectId: 'f_workers',
    gridId: 'f_workers_grid',
    searchId: 'f_workers_search',
    metaId: 'f_workers_meta',
    workers: workersAll,
    preselected: preId ? [preId] : [],
    onChange: () => { refreshCalc(); crEvaluate(); }
  });
  f_driver.innerHTML='<option value="">-- Select Driver --</option>'+(drivers||[]).map(d=>`<option value="${d.id}">${esc(d.nickname||('#'+d.id))}</option>`).join('');
  
  ALL_SERVICES_CACHE = services;
  smWireCategoryListeners();
  f_applyBookingCategoryUI();

  // Use jQuery event for Select2 compatibility
  $(f_client).on('change', async function() {
    const opt=f_client.options[f_client.selectedIndex];
    const rate=parseFloat(opt?.getAttribute('data-rate')||'0');
    const ld=opt?.getAttribute('data-lastdriver')||'';
    if(rate>0) f_rate.value=rate.toFixed(2);
    if(ld){ const ix=[...f_driver.options].findIndex(o=>o.value===ld); if(ix>0) f_driver.selectedIndex=ix; }
    
    // Fetch and display client preferences
    const clientId = f_client.value;
    if (clientId) {
      await loadClientPreferences(clientId);
    } else {
      // Hide preferences box if no client selected
      document.getElementById('clientPreferencesBox').style.display = 'none';
    }
    
    refreshCalc();
    crEvaluate();
  });
  ['f_start','f_end','f_rate','f_vat','f_need_materials'].forEach(id=>{ const el=document.getElementById(id); if(el && !el.dataset.bkWired){ el.dataset.bkWired='1'; el.oninput=el.onchange=()=>{ refreshCalc(); crEvaluate(); }; }});
}

['f_client','f_start','f_end','f_rate','f_vat','f_status','f_workers'].forEach(id=>{
  const el = document.getElementById(id);
  if (el) el.addEventListener('change', crEvaluate);
});

// ===== Credit control: CREATE modal =====
const crAlert   = document.getElementById('cr_credit_alert');
const crWrap    = document.getElementById('cr_override_wrap');
const crChk     = document.getElementById('cr_override_credit_limit');
const crOk      = document.getElementById('cr_credit_ok');

function crHide(){
  crAlert.style.display = 'none';
  crWrap.style.display  = 'none';
  crChk.checked         = false;
  if (crOk) crOk.style.display = 'none';
}

function crShow(msg){
  crAlert.textContent   = msg;
  crAlert.style.display = '';
  crWrap.style.display  = '';
  if (crOk) crOk.style.display = 'none';
}

function crShowSuccess(msg){
  if (!crOk) return;
  crOk.textContent = msg || 'Client within credit limit ✓';
  crOk.style.display = '';
  crAlert.style.display = 'none';
  crWrap.style.display  = 'none';
}

// Compute projected total for Create modal (hours × rate × cleaners, +5% if VAT to add)
function crComputeOrderTotal(){
  return f_computeBookingTotals().tot || 0;
}

// Fetch AR for a client_id → {credit_limit, outstanding, currency}
async function fetchAR(clientId){
  try{
    const r = await fetch('ajax_client_ar.php?client_id='+encodeURIComponent(clientId), {credentials:'same-origin'});
    const j = await r.json();
    if (j?.ok) return {credit_limit:+j.credit_limit||0, outstanding:+j.outstanding||0, currency:j.currency||CONFIG.CURRENCY};
  }catch(e){
    console.error('Failed to fetch AR for client', clientId, e);
  }
  return {credit_limit:0, outstanding:0, currency:CONFIG.CURRENCY};
}

// Fetch and display client preferences (for Add Order modal)
async function loadClientPreferences(clientId){
  try{
    const r = await fetch('api_availability.php?action=client_preferences&client_id='+encodeURIComponent(clientId), {credentials:'same-origin'});
    const data = await r.json();
    
    if (data.success) {
      // Display preferences
      const prefBox = document.getElementById('clientPreferencesBox');
      const prefWorkers = document.getElementById('pref_workers');
      const prefVat = document.getElementById('pref_vat');
      const prefInstructions = document.getElementById('pref_instructions');
      const prefAccess = document.getElementById('pref_access');
      
      // Update preference displays
      prefWorkers.textContent = data.preferred_workers && data.preferred_workers.length > 0 
        ? data.preferred_workers.join(', ') 
        : 'None specified';
      
      const vatModeDisplay = data.vat_mode === 'yes' 
        ? 'Yes (fee includes VAT)' 
        : 'No (add VAT)';
      prefVat.textContent = vatModeDisplay;
      
      prefInstructions.textContent = data.special_instructions || 'None specified';
      prefAccess.textContent = data.access_instructions || 'None specified';
      
      // Auto-populate VAT mode dropdown
      const vatDropdown = document.getElementById('f_vat');
      if (vatDropdown && data.vat_mode) {
        vatDropdown.value = data.vat_mode;
        // Trigger change event to recalculate totals
        if (vatDropdown.onchange) vatDropdown.onchange();
      }
      
      // Show preferences box
      prefBox.style.display = '';
    } else {
      // Hide preferences box on error
      document.getElementById('clientPreferencesBox').style.display = 'none';
    }
  }catch(e){
    console.error('Failed to fetch client preferences', e);
    document.getElementById('clientPreferencesBox').style.display = 'none';
  }
}

// Fetch and display client preferences (for Edit Order modal)
async function loadClientPreferencesEdit(clientId){
  try{
    const r = await fetch('api_availability.php?action=client_preferences&client_id='+encodeURIComponent(clientId), {credentials:'same-origin'});
    const data = await r.json();
    
    if (data.success) {
      // Display preferences in Edit modal
      const prefBox = document.getElementById('editClientPreferencesBox');
      const prefWorkers = document.getElementById('e_pref_workers');
      const prefVat = document.getElementById('e_pref_vat');
      const prefInstructions = document.getElementById('e_pref_instructions');
      const prefAccess = document.getElementById('e_pref_access');
      
      // Update preference displays
      prefWorkers.textContent = data.preferred_workers && data.preferred_workers.length > 0 
        ? data.preferred_workers.join(', ') 
        : 'None specified';
      
      const vatModeDisplay = data.vat_mode === 'yes' 
        ? 'Yes (fee includes VAT)' 
        : 'No (add VAT)';
      prefVat.textContent = vatModeDisplay;
      
      prefInstructions.textContent = data.special_instructions || 'None specified';
      prefAccess.textContent = data.access_instructions || 'None specified';
      
      // Auto-populate VAT mode dropdown in Edit modal
      const vatDropdown = document.getElementById('e_vat');
      if (vatDropdown && data.vat_mode) {
        vatDropdown.value = data.vat_mode;
        // Trigger change event to recalculate totals
        refreshEditCalc();
      }
      
      // Show preferences box
      prefBox.style.display = '';
    } else {
      // Hide preferences box on error
      document.getElementById('editClientPreferencesBox').style.display = 'none';
    }
  }catch(e){
    console.error('Failed to fetch client preferences for edit', e);
    document.getElementById('editClientPreferencesBox').style.display = 'none';
  }
}

// Fetch and display client preferences (for Repeat Modal)
async function loadClientPreferencesRepeat(clientId){
  try{
    const r = await fetch('api_availability.php?action=client_preferences&client_id='+encodeURIComponent(clientId), {credentials:'same-origin'});
    const data = await r.json();
    
    if (data.success) {
      // Display preferences in Repeat modal
      const prefBox = document.getElementById('rwClientPreferencesBox');
      const prefWorkers = document.getElementById('rw_pref_workers');
      const prefVat = document.getElementById('rw_pref_vat');
      const prefInstructions = document.getElementById('rw_pref_instructions');
      const prefAccess = document.getElementById('rw_pref_access');
      
      // Update preference displays
      prefWorkers.textContent = data.preferred_workers && data.preferred_workers.length > 0 
        ? data.preferred_workers.join(', ') 
        : 'None specified';
      
      const vatModeDisplay = data.vat_mode === 'yes' 
        ? 'Yes (fee includes VAT)' 
        : 'No (add VAT)';
      prefVat.textContent = vatModeDisplay;
      
      prefInstructions.textContent = data.special_instructions || 'None specified';
      prefAccess.textContent = data.access_instructions || 'None specified';
      
      // Auto-populate VAT mode dropdown in Repeat modal
      const vatDropdown = document.getElementById('rw_vat');
      if (vatDropdown && data.vat_mode) {
        vatDropdown.value = data.vat_mode;
      }
      
      // Show preferences box
      prefBox.style.display = '';
    } else {
      // Hide preferences box on error
      document.getElementById('rwClientPreferencesBox').style.display = 'none';
    }
  }catch(e){
    console.error('Failed to fetch client preferences for repeat', e);
    document.getElementById('rwClientPreferencesBox').style.display = 'none';
  }
}

/**
 * Unified credit evaluation function
 * @param {Object} params - Configuration object
 * @param {number} params.clientId - Client ID to check
 * @param {string} params.status - Order status (only 'confirmed' triggers check)
 * @param {number} params.orderTotal - Total amount of order
 * @param {HTMLElement} params.alertEl - Alert element to show warning
 * @param {HTMLElement} params.wrapEl - Wrapper for override checkbox
 * @param {HTMLElement} params.chkEl - Override checkbox element
 * @param {HTMLElement} params.okEl - Success message element
 */
async function evaluateCreditUnified({ clientId, status, orderTotal, alertEl, wrapEl, chkEl, okEl }) {
  // Hide all elements initially
  if (alertEl) alertEl.style.display = 'none';
  if (wrapEl) wrapEl.style.display = 'none';
  if (chkEl) chkEl.checked = false;
  if (okEl) okEl.style.display = 'none';

  // Only check if status is confirmed
  if (status?.toLowerCase() !== 'confirmed') return;
  if (!clientId || orderTotal <= 0) return;

  const ar = await fetchAR(clientId);
  if (ar.credit_limit <= 0) return; // No limit set

  const projected = +(ar.outstanding + orderTotal).toFixed(2);
  
  if (projected > ar.credit_limit) {
    // Show warning
    if (alertEl) {
      alertEl.textContent = `Credit limit warning: Outstanding ${ar.currency} ${ar.outstanding.toFixed(2)} + this order ${ar.currency} ${orderTotal.toFixed(2)} = ${ar.currency} ${projected.toFixed(2)} (limit ${ar.currency} ${ar.credit_limit.toFixed(2)}). Tick the override checkbox to proceed.`;
      alertEl.style.display = '';
    }
    if (wrapEl) wrapEl.style.display = '';
  } else {
    // Show success
    if (okEl) {
      okEl.textContent = `Client within credit limit ✓ (Outstanding ${ar.currency} ${ar.outstanding.toFixed(2)} / Limit ${ar.currency} ${ar.credit_limit.toFixed(2)})`;
      okEl.style.display = '';
    }
  }
}

// Evaluate credit when fields change (only if status is "confirmed")
async function crEvaluate(){
  const status = document.getElementById('f_status')?.value || '';
  const clientId = document.getElementById('f_client')?.value;
  const total = crComputeOrderTotal();
  
  await evaluateCreditUnified({
    clientId: clientId ? parseInt(clientId) : 0,
    status,
    orderTotal: total,
    alertEl: crAlert,
    wrapEl: crWrap,
    chkEl: crChk,
    okEl: crOk
  });
}        

// Create form (with pre-validate and race condition protection)
let isCreatingOrder = false;
createForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  if (isCreatingOrder) return; // Prevent double submission
  
  const submitBtn = e.target.querySelector('button[type="submit"]');
  const fd = new FormData(e.target);

  const catIds = f_getSelectedCategoryIds();
  if (!catIds.length) {
    createError.textContent = 'Select at least one service category.';
    createError.style.display = '';
    return;
  }
  const cleaningOn = f_isCleaningSelected();
  const hasCatalogRows = document.querySelectorAll('#f_catalog_panels .f-catalog-body tr').length > 0;
  if (!cleaningOn && !hasCatalogRows) {
    createError.textContent = 'Add at least one catalog item for the selected service(s).';
    createError.style.display = '';
    return;
  }
  if (cleaningOn && !(parseFloat(f_rate?.value || '0') > 0)) {
    createError.textContent = 'Hourly rate is required when Cleaning is selected.';
    createError.style.display = '';
    return;
  }
  f_updatePrimaryCategoryHidden();

  // pre-check (existing)
  const vfd = new FormData();
  vfd.append('action','validate');
  vfd.append('service_date', fd.get('service_date') || '');
  vfd.append('start_time',   fd.get('start_time')   || '');
  vfd.append('end_time',     fd.get('end_time')     || '');
  (fd.getAll('worker_ids[]') || []).forEach(w=> vfd.append('worker_ids[]', w));

  try {
    const vr = await fetch('api_availability.php', {method:'POST', body:vfd});
    const vj = await vr.json();
    if (!vj.ok) {
      createError.innerHTML = '<strong>Validation errors:</strong><br>' + (vj.errors || ['Validation failed']).join('<br>');
      createError.style.display = '';
      return;
    }
    // Show overtime warnings if any
    if (vj.warnings && vj.warnings.length > 0) {
      createError.innerHTML = '<div class="alert alert-warning mb-2"><strong>Overtime Warning:</strong><br>' + vj.warnings.join('<br>') + '</div>';
      createError.style.display = '';
    } else {
      createError.style.display = 'none';
    }
  } catch(e) {
    createError.innerHTML = 'Validation failed: Network error';
    createError.style.display = '';
    return;
  }

  // CREDIT block: if status is confirmed, warning is visible, and no override → stop
  const statusVal = (document.getElementById('f_status')?.value || '').toLowerCase();
  const warningVisible = (crAlert.style.display !== 'none');
  if (statusVal === 'confirmed' && warningVisible && !crChk.checked) {
    createError.textContent = 'Credit limit exceeded. Tick the override checkbox to proceed.';
    createError.style.display = '';
    crAlert.scrollIntoView({behavior:'smooth',block:'center'});
    return;
  }

  // pass override to backend
  if (crChk.checked) fd.append('override_credit_limit','1');

  isCreatingOrder = true;
  showLoading(submitBtn, 'Creating...');
  
  try {
    // create (existing)
    const r = await fetch('api_availability.php', {method:'POST', body:fd});
    let j; 
    try { j = await r.json(); } catch { j={success:false,error:'Invalid server response'}; }
    
    if (!j.success) {
      createError.innerHTML = '<strong>Failed to create order:</strong><br>' + (j.error||'Unknown error').replace(/\n/g,'<br>');
      createError.style.display = '';
      hideLoading(submitBtn);
      return;
    }
    
    // Show overtime entries created if any
    if (j.overtime_entries && j.overtime_entries.length > 0) {
      const overtimeInfo = j.overtime_entries.map(entry => 
        `${entry.employee}: ${entry.hours}h overtime (${entry.amount} AED)`
      ).join('<br>');
      createError.innerHTML = `<div class="alert alert-info mb-2"><strong>Overtime entries created:</strong><br>${overtimeInfo}</div>`;
      createError.style.display = '';
    }
    
    bootstrap.Modal.getInstance(createModal).hide();
    location.reload();
  } finally {
    isCreatingOrder = false;
    hideLoading(submitBtn);
  }
});

    async function loadOrderNotifications(orderId){
      const box = document.getElementById('editSends');
      if (!box) return;

      box.textContent = 'Loading notifications…';
      try {
        const r = await fetch(`api_availability.php?action=order_notifications&id=${orderId}`);
        const j = await r.json();

        if (!j?.success) {
          box.textContent = j?.error || 'Failed to load notifications.';
          return;
        }
        if (!j.items || j.items.length === 0) {
          box.textContent = 'No notifications yet.';
          return;
        }

        box.innerHTML = '<div class="fw-semibold mb-1">Notifications</div>' +
          j.items.map(m=>{
            const when = m.sent_at || m.created_at || '';
            let vars = {};
            try { vars = JSON.parse(m.variables_json || '{}'); } catch {}
            // Build a short preview line from vars (safe fallbacks)
            const previewParts = [];
            if (vars.client_name) previewParts.push(vars.client_name);
            if (vars.date)        previewParts.push(vars.date);
            if (vars.start && vars.end) previewParts.push(`${vars.start}–${vars.end}`);
            if (vars.workers)     previewParts.push(String(vars.workers));
            const preview = previewParts.join(' • ');

            const badge =
              m.status === 'sent'  ? '<span class="badge text-bg-success">sent</span>'  :
              m.status === 'error' ? '<span class="badge text-bg-danger">error</span>' :
                                     `<span class="badge text-bg-secondary">${m.status}</span>`;

            return `
              <div class="d-flex justify-content-between border rounded p-2 mb-1">
                <div>
                  <strong>${m.template_code}</strong> • ${m.channel} → ${m.recipient}
                  <div class="text-muted">${preview || ''}</div>
                </div>
                <div class="text-end">
                  ${badge}<br>
                  <span class="text-muted">${when}</span>
                  ${m.error_text ? `<div class="text-danger">${m.error_text}</div>` : ''}
                </div>
              </div>`;
          }).join('');
      } catch (e) {
        box.textContent = 'Network error while loading notifications.';
      }
    }
  
/**
 * Workflow panel + financial lock for edit modal (Phase 2/3 from Worker Availability).
 * Ops schedule fields stay editable until finalize; money fields lock on invoice activity.
 */
const MONEY_LOCK_SELECTORS = [
  '#e_rate', '#e_vat',
  '#e_category_chips input', '#e_catalog_panels input', '#e_catalog_panels select', '#e_catalog_panels button',
  '#e_cleaning_panel input', '#e_cleaning_panel select'
];
const OPS_SCHEDULE_SELECTORS = [
  '#e_service_date', '#e_start_time', '#e_end_time', '#e_workers', '#e_workers_picker input'
];
const FINALIZED_LOCK_SELECTORS = [
  '#e_status', '#e_remark', '#e_driver', '#e_need_materials', '#e_materials_note'
];

function unlockFinalizedOrderFields() {
  FINALIZED_LOCK_SELECTORS.forEach(sel => {
    const el = document.querySelector(sel);
    if (el) el.disabled = false;
  });
  const saveBtn = document.getElementById('e_save_btn');
  if (saveBtn) {
    saveBtn.disabled = false;
    saveBtn.style.display = '';
  }
}

function applyFinalizedOrderLock(wf) {
  unlockFinalizedOrderFields();
  unlockEditFinancialFields();
  if (!wf?.is_finalized && !wf?.ops_locked) {
    applyEditFinancialLock(!!wf?.financially_locked);
    return;
  }
  applyEditFinancialLock(true);
  OPS_SCHEDULE_SELECTORS.forEach(sel => {
    document.querySelectorAll(sel).forEach(el => {
      el.disabled = true;
      if (el.tagName === 'BUTTON') el.style.display = 'none';
    });
  });
  FINALIZED_LOCK_SELECTORS.forEach(sel => {
    const el = document.querySelector(sel);
    if (el) el.disabled = true;
  });
  const saveBtn = document.getElementById('e_save_btn');
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.style.display = 'none';
  }
}

function unlockEditFinancialFields() {
  [...MONEY_LOCK_SELECTORS, ...OPS_SCHEDULE_SELECTORS].forEach(sel => {
    document.querySelectorAll(sel).forEach(el => {
      el.disabled = false;
      if (el.tagName === 'BUTTON') el.style.display = '';
    });
  });
}

function applyEditFinancialLock(locked) {
  unlockEditFinancialFields();
  if (!locked) return;
  // Money only — schedule/workers remain editable until finalize.
  MONEY_LOCK_SELECTORS.forEach(sel => {
    document.querySelectorAll(sel).forEach(el => {
      el.disabled = true;
      if (el.tagName === 'BUTTON') el.style.display = 'none';
    });
  });
}

function renderEditWorkflowPanel(wf) {
  const bar = document.getElementById('e_workflow_bar');
  const badges = document.getElementById('e_workflow_badges');
  const actions = document.getElementById('e_workflow_actions');
  const hint = document.getElementById('e_finalize_hint');
  const alertEl = document.getElementById('e_workflow_alert');
  if (!bar || !badges || !actions) return;

  bar.style.display = '';
  badges.innerHTML = '';
  actions.innerHTML = '';
  hint.style.display = 'none';
  hint.textContent = '';
  alertEl.style.display = 'none';
  alertEl.textContent = '';

  const addBadge = (cls, text) => {
    badges.insertAdjacentHTML('beforeend', `<span class="badge ${cls}">${esc(text)}</span>`);
  };

  addBadge('bg-secondary', 'Status: ' + (wf.ops_status || '—'));
  if (wf.is_finalized) {
    addBadge('bg-success', 'Finalized');
  } else if (wf.financially_locked) {
    addBadge('bg-warning text-dark', 'Financially locked');
  } else if (wf.defer_auto_invoice) {
    addBadge('bg-info text-dark', 'Awaiting finalize');
  }
  if (wf.invoice?.invoice_no || wf.invoice?.id) {
    const invNo = wf.invoice.invoice_no || ('#' + wf.invoice.id);
    addBadge('bg-light text-dark border', `Invoice ${invNo} (${wf.invoice.status || '—'})`);
  }
  if (wf.pending_adjustment?.id) {
    addBadge('bg-primary', `Adjustment pending #${wf.pending_adjustment.id}`);
  }

  if (wf.can_mark_complete) {
    actions.insertAdjacentHTML('beforeend',
      '<button type="button" class="btn btn-sm btn-success" id="e_btn_mark_complete">Mark Complete</button>');
  }
  if (wf.can_finalize) {
    const disabled = wf.finalize_ready ? '' : ' disabled';
    const title = wf.finalize_ready ? '' : ` title="${esc(wf.finalize_reason || '')}"`;
    actions.insertAdjacentHTML('beforeend',
      `<button type="button" class="btn btn-sm btn-warning" id="e_btn_finalize"${disabled}${title}>Finalize &amp; Generate Invoice</button>`);
    if (!wf.finalize_ready && wf.finalize_reason) {
      hint.textContent = wf.finalize_reason;
      hint.style.display = '';
    }
  }
  if (wf.can_request_adjustment) {
    actions.insertAdjacentHTML('beforeend',
      '<button type="button" class="btn btn-sm btn-outline-danger" id="e_btn_request_adj">Request Adjustment</button>');
  }
  if (wf.can_approve_adjustment && wf.pending_adjustment?.id) {
    actions.insertAdjacentHTML('beforeend',
      `<a class="btn btn-sm btn-outline-primary" href="../accounts/adjustment_requests.php">Review in Accounts</a>`);
  }

  if (wf.is_finalized || wf.ops_locked) {
    alertEl.textContent = wf.ops_lock_reason || 'This job is finalized and locked. No changes from the calendar — use Accounts → Adjustment Requests if needed.';
    alertEl.className = 'alert alert-warning small py-2 mt-2 mb-0';
    alertEl.style.display = '';
  } else if (wf.financially_locked) {
    alertEl.textContent = wf.financial_lock_reason || 'Money fields are locked due to invoice activity. Schedule/status can still change until finalize.';
    alertEl.className = 'alert alert-warning small py-2 mt-2 mb-0';
    alertEl.style.display = '';
  } else if (wf.defer_auto_invoice && !wf.is_finalized) {
    alertEl.textContent = 'Invoice will be generated when an Admin or Accountant finalizes this job.';
    alertEl.className = 'alert alert-info small py-2 mt-2 mb-0';
    alertEl.style.display = '';
  }

  document.getElementById('e_btn_finalize')?.addEventListener('click', finalizeFromEditModal);
  document.getElementById('e_btn_mark_complete')?.addEventListener('click', markCompleteFromEditModal);
  document.getElementById('e_btn_request_adj')?.addEventListener('click', openWaAdjRequestModal);
}

async function postWorkflowAction(url, orderId) {
  const fd = new FormData();
  fd.append('order_id', String(orderId));
  fd.append('_csrf', CONFIG.CSRF_TOKEN || '');
  const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
  return res.json();
}

async function finalizeFromEditModal() {
  const orderId = document.getElementById('e_order')?.value;
  if (!orderId) return;
  if (!confirm('Finalize this work order? This will lock financial fields and create/post the invoice.')) return;
  const btn = document.getElementById('e_btn_finalize');
  if (btn) btn.disabled = true;
  try {
    const data = await postWorkflowAction('ajax_finalize_order.php', orderId);
    if (data.success) {
      bootstrap.Modal.getInstance(editModal)?.hide();
      location.reload();
    } else {
      alert(data.message || 'Finalize failed');
      if (btn) btn.disabled = false;
    }
  } catch (e) {
    alert('Finalize request failed');
    if (btn) btn.disabled = false;
  }
}

async function markCompleteFromEditModal() {
  const orderId = document.getElementById('e_order')?.value;
  if (!orderId) return;
  if (!confirm('Mark this job as completed?')) return;
  const btn = document.getElementById('e_btn_mark_complete');
  if (btn) btn.disabled = true;
  try {
    const data = await postWorkflowAction('ajax_mark_complete.php', orderId);
    if (data.success) {
      bootstrap.Modal.getInstance(editModal)?.hide();
      location.reload();
    } else {
      alert(data.message || 'Could not mark complete');
      if (btn) btn.disabled = false;
    }
  } catch (e) {
    alert('Request failed');
    if (btn) btn.disabled = false;
  }
}

function openWaAdjRequestModal() {
  const wf = window.__currentEditWorkflow__ || {};
  document.getElementById('wa_adj_title').textContent = 'Request Adjustment — WO #' + (document.getElementById('e_order')?.value || '');
  document.getElementById('wa_adj_frozen').textContent = `AED ${(wf.frozen_grand ?? 0).toFixed(2)}`;
  document.getElementById('wa_adj_grand').value = wf.frozen_grand ?? '';
  document.getElementById('wa_adj_reason').value = '';
  document.getElementById('wa_adj_notes').value = '';
  const catEl = document.getElementById('wa_adj_category');
  if (catEl) catEl.value = '';
  document.getElementById('wa_adj_error').style.display = 'none';
  const typeSel = document.getElementById('wa_adj_type');
  typeSel.value = 'amount_decrease';
  typeSel.dispatchEvent(new Event('change'));
  new bootstrap.Modal(document.getElementById('waAdjRequestModal')).show();
}

function syncWaAdjCancellationFields() {
  const type = document.getElementById('wa_adj_type')?.value || '';
  const isCancel = type === 'cancellation';
  const wrap = document.getElementById('wa_adj_grand_wrap');
  const catWrap = document.getElementById('wa_adj_category_wrap');
  const reasonWrap = document.getElementById('wa_adj_reason_wrap');
  const reasonLabel = document.getElementById('wa_adj_reason_label');
  const notesLabel = document.getElementById('wa_adj_notes_label');
  if (wrap) wrap.style.display = isCancel ? 'none' : '';
  if (catWrap) catWrap.style.display = isCancel ? '' : 'none';
  if (reasonWrap) reasonWrap.style.display = isCancel ? 'none' : '';
  if (reasonLabel) reasonLabel.textContent = 'Reason *';
  if (notesLabel) notesLabel.textContent = isCancel ? 'Details *' : 'Details';
  const notes = document.getElementById('wa_adj_notes');
  if (notes) {
    notes.placeholder = isCancel ? 'Why is this job being cancelled?' : '';
    notes.required = isCancel;
  }
}

document.getElementById('wa_adj_type')?.addEventListener('change', syncWaAdjCancellationFields);

document.getElementById('wa_adj_submit')?.addEventListener('click', async function() {
  const orderId = document.getElementById('e_order')?.value;
  const type = document.getElementById('wa_adj_type')?.value || 'other';
  const errEl = document.getElementById('wa_adj_error');
  let reason = (document.getElementById('wa_adj_reason')?.value || '').trim();
  let notes = (document.getElementById('wa_adj_notes')?.value || '').trim();

  if (type === 'cancellation') {
    const category = (document.getElementById('wa_adj_category')?.value || '').trim();
    const details = notes;
    if (!category) {
      errEl.textContent = 'Please select a category (Cleaner, Driver, Management, or Client).';
      errEl.style.display = '';
      return;
    }
    if (!details) {
      errEl.textContent = 'Details are required for cancellation.';
      errEl.style.display = '';
      return;
    }
    reason = (category + ': ' + details).substring(0, 500);
    notes = 'Cancellation category: ' + category + '\nDetails: ' + details;
  } else if (!reason) {
    errEl.textContent = 'Reason is required.';
    errEl.style.display = '';
    return;
  }

  const fd = new FormData();
  fd.append('order_id', orderId);
  fd.append('request_type', type);
  fd.append('reason', reason);
  fd.append('notes', notes);
  const grand = document.getElementById('wa_adj_grand')?.value;
  if (grand !== '' && type !== 'cancellation') {
    fd.append('requested_grand', grand);
  }
  fd.append('_csrf', CONFIG.CSRF_TOKEN || '');
  this.disabled = true;
  try {
    const res = await fetch('ajax_request_adjustment.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      bootstrap.Modal.getInstance(document.getElementById('waAdjRequestModal'))?.hide();
      bootstrap.Modal.getInstance(editModal)?.hide();
      location.reload();
    } else {
      errEl.textContent = data.message || 'Request failed';
      errEl.style.display = '';
      this.disabled = false;
    }
  } catch (e) {
    errEl.textContent = 'Request failed';
    errEl.style.display = '';
    this.disabled = false;
  }
});

function resetEditBookingForm() {
  const catPanels = document.getElementById('e_catalog_panels');
  if (catPanels) catPanels.innerHTML = '';
  const quote = document.getElementById('e_quote_summary');
  if (quote) quote.style.display = 'none';
  editError.style.display = 'none';
  window.__editBookingSnapshot__ = null;
}

/**
 * Open edit modal with order details pre-populated
 * @param {number} orderId - ID of the order to edit
 */
async function openEditModal(orderId){
    resetEditBookingForm();

    const j = await (await fetch(`api_availability.php?action=order&id=${orderId}`)).json();
    if (!j.success) { alert(j.error||'Fetch failed'); return; }
    const o=j.order;
    e_order.value=o.id;
    e_client_label.value=(o.client_name||('Client #'+o.client_id));
    document.getElementById('e_need_materials').value = String(j.order.need_materials ?? 0);
    document.getElementById('e_materials_note').value = j.order.materials_note ?? '';

    const catIds = (o.booking_category_ids && o.booking_category_ids.length)
      ? o.booking_category_ids.map(String)
      : (o.service_category_id ? [String(o.service_category_id)] : []);
    document.querySelectorAll('#e_category_chips input[name="booking_category_ids[]"]').forEach(cb => {
      cb.checked = catIds.includes(cb.value);
    });
    if (!catIds.length) {
      const cleaningCb = document.querySelector('#e_category_chips input[data-code="cleaning"]');
      if (cleaningCb) cleaningCb.checked = true;
    }

    document.getElementById('e_service_date').value = o.service_date || o.svc_date_calc || '';
    document.getElementById('e_start_time').value = (o.start_time || '').substring(0, 5);
    document.getElementById('e_end_time').value = (o.end_time || '').substring(0, 5);

    const workers = payloadGlobal?.workersAll || FALLBACK.workers || [];
    mountWorkerPicker({
      selectId: 'e_workers',
      gridId: 'e_workers_grid',
      searchId: 'e_workers_search',
      metaId: 'e_workers_meta',
      workers,
      preselected: o.worker_ids || [],
      onChange: () => { calculateEditOvertime(); refreshEditCalc(); }
    });

    const drivers = payloadGlobal?.drivers || FALLBACK.drivers;
    e_driver.innerHTML = '<option value="">-- Select Driver --</option>'+drivers.map(d=>`<option value="${d.id}">${esc(d.nickname||('#'+d.id))}</option>`).join('');
    if(o.driver_id){ const ix=[...e_driver.options].findIndex(x=>+x.value===+o.driver_id); if(ix>=0) e_driver.selectedIndex=ix; }
    e_status.value = o.status||'confirmed';
    e_rate.value   = o.hourly_rate||'';

    bkWireCategoryListeners('e');
    bkApplyCategoryUI('e', { preserveCatalog: false });

    const catalogPresets = {};
    (o.services || []).forEach(svc => {
      if (!svc.service_id) return;
      const meta = BOOKING_CATEGORIES.flatMap(c => (c.catalog || []).map(i => ({...i, catId: c.id})))
        .find(i => +i.id === +svc.service_id);
      if (!meta) return;
      const catId = String(meta.catId);
      const sid = String(svc.service_id);
      if (!catalogPresets[catId]) catalogPresets[catId] = {};
      if (catalogPresets[catId][sid]) {
        const prev = catalogPresets[catId][sid];
        prev.qty = (parseFloat(prev.qty) || 0) + (parseFloat(svc.qty) || 0);
      } else {
        catalogPresets[catId][sid] = { ...svc };
      }
    });
    Object.entries(catalogPresets).forEach(([catId, bySvc]) => {
      const cat = BOOKING_CATEGORIES.find(c => String(c.id) === catId);
      if (!cat) return;
      Object.values(bySvc).forEach(row => bkAddCatalogRow('e', cat, row));
    });

    // Set VAT mode from stored value when available (avoid wrong inference on save)
    if (o.vat_included !== undefined && o.vat_included !== null && String(o.vat_included).trim() !== '') {
      e_vat.value = o.vat_included === 'yes' || o.vat_included === '1' || o.vat_included === 1 ? 'yes' : 'no';
    } else {
      // Do NOT infer from total+vat≈grand — that holds for BOTH modes and flips inclusive→add.
      // Default matches create-order / client preference: fee includes VAT.
      e_vat.value = 'yes';
    }
    
    e_remark.value = o.remark||'';
    editInfo.textContent = `${o.service_date} ${(o.start_time||'').substring(0,5)}–${(o.end_time||'').substring(0,5)}` +
      (o.category_labels ? ` • ${o.category_labels}` : (o.service_category_name ? ` • ${o.service_category_icon || ''} ${o.service_category_name}`.trim() : ''));
    
    // Store current order data for credit evaluation
    window.__currentEditOrder__ = o;
    window.__currentEditWorkflow__ = o.workflow || {};

    if (o.workflow) {
      renderEditWorkflowPanel(o.workflow);
      applyEditFinancialLock(!!o.workflow.financially_locked);
      applyFinalizedOrderLock(o.workflow);
    } else {
      document.getElementById('e_workflow_bar').style.display = 'none';
      unlockEditFinancialFields();
      unlockFinalizedOrderFields();
    }
    
    evaluateCreditForEdit(o);
    if (!editStoredTotalsDifferFromQuote()) {
      editError.style.display = 'none';
      editError.className = 'text-danger small mt-2';
    }
    
    // Calculate overtime on load
    calculateEditOvertime();
    
    // Calculate totals on load
    refreshEditCalc();

    window.__editBookingSnapshot__ = editBookingSnapshotNow();
    if (editStoredTotalsDifferFromQuote()) {
      editError.innerHTML = '<strong>Note:</strong> Stored total (AED ' + parseFloat(o.grand_total || 0).toFixed(2) +
        ') differs from calculated total. Click <em>Save changes</em> to update this order.';
      editError.className = 'alert alert-warning small mt-2';
      editError.style.display = '';
    }

    // 🔽 load notifications for this order
    loadOrderNotifications(orderId);
    
    // Load client preferences for Edit modal
    if (o.client_id) {
      await loadClientPreferencesEdit(o.client_id);
    }

    new bootstrap.Modal(editModal).show();
  }

// ===== Credit control in Edit modal =====
const e_credit_alert = document.getElementById('e_credit_alert');
const e_override_wrap = document.getElementById('e_override_wrap');
const e_override_chk  = document.getElementById('e_override_credit_limit');
const e_credit_ok     = document.getElementById('e_credit_ok');

// Helper to compute the order's total if not already present
function computeOrderTotalForEdit(order, workersCount) {
  const live = bkComputeTotals('e').tot;
  if (live > 0) return live;
  if (order.grand_total && +order.grand_total > 0) return parseFloat(order.grand_total);
  return 0;
}

function showCreditWarning(msg) {
  e_credit_alert.textContent = msg;
  e_credit_alert.style.display = '';
  e_override_wrap.style.display = '';
  if (e_credit_ok) e_credit_ok.style.display = 'none';
}
function hideCreditWarning() {
  e_credit_alert.style.display = 'none';
  e_override_wrap.style.display = 'none';
  e_override_chk.checked = false;
  if (e_credit_ok) e_credit_ok.style.display = 'none';
}
function showCreditOk(msg){
  if (!e_credit_ok) return;
  e_credit_ok.textContent = msg || 'Client within credit limit ✓';
  e_credit_ok.style.display = '';
  e_credit_alert.style.display = 'none';
  e_override_wrap.style.display = 'none';
}


// Load AR and evaluate credit when opening modal, and when status changes
async function evaluateCreditForEdit(order) {
  hideCreditWarning();
  if (!order?.client_id) return;

  // Only warn if trying to confirm (now or later)
  const status = (document.getElementById('e_status')?.value || '').toLowerCase();
  if (status !== 'confirmed') return;

  // Get worker count for this order (needed to compute total if grand_total absent)
  let workersCount = 1;
  try {
    const resW = await fetch(`api_availability.php?action=order_workers_count&id=${encodeURIComponent(order.id)}`);
    const jw   = await resW.json();
    if (jw?.success && jw?.count) workersCount = parseInt(jw.count,10) || 1;
  } catch (e) {}

  // Projected order amount
  const orderTotal = computeOrderTotalForEdit(order, workersCount);

  // Fetch client AR (re-use the same endpoint as order_add.php)
  let creditLimit = 0, outstanding = 0, currency = 'AED';
  try {
    const res = await fetch(`ajax_client_ar.php?client_id=${encodeURIComponent(order.client_id)}`, { credentials:'same-origin' });
    const j = await res.json();
    if (j?.ok) {
      creditLimit = parseFloat(j.credit_limit)||0;
      outstanding = parseFloat(j.outstanding)||0;
      currency    = j.currency || 'AED';
    }
  } catch (e) {}

  if (creditLimit > 0) {
    const projected = Math.round((outstanding + orderTotal + Number.EPSILON) * 100)/100;
    if (projected > creditLimit) {
      showCreditWarning(
        `Credit limit warning: Outstanding ${currency} ${outstanding.toFixed(2)} + this order ${currency} ${orderTotal.toFixed(2)} = ${currency} ${projected.toFixed(2)} (limit ${currency} ${creditLimit.toFixed(2)}). Tick the override checkbox to proceed.`
      );
    } else {
      showCreditOk(
        `Client within credit limit ✓ (Outstanding ${currency} ${outstanding.toFixed(2)} / Limit ${currency} ${creditLimit.toFixed(2)})`
      );
    }
  }
}

// Overtime calculation for Edit modal (with debouncing)
let overtimeTimeout = null;
async function calculateEditOvertime() {
  // Clear previous timeout
  clearTimeout(overtimeTimeout);
  
  // Debounce to prevent rapid API calls
  overtimeTimeout = setTimeout(async () => {
    const startTime = document.getElementById('e_start_time')?.value;
    const endTime = document.getElementById('e_end_time')?.value;
    const orderId = document.getElementById('e_order')?.value;
    
    if (!startTime || !endTime || !orderId) {
      document.getElementById('e_overtime_info').style.display = 'none';
      return;
    }
    
    try {
      const formData = new FormData();
      formData.append('action', 'calculate_overtime');
      formData.append('order_id', orderId);
      formData.append('start_time', startTime);
      formData.append('end_time', endTime);
      
      const response = await fetch('api_availability.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      if (result.success && result.overtime_info) {
        const overtimeDiv = document.getElementById('e_overtime_details');
        const overtimeInfo = document.getElementById('e_overtime_info');
        
        if (result.overtime_info.length > 0) {
          overtimeDiv.innerHTML = result.overtime_info.map(entry => 
            `${entry.worker_name}: ${entry.hours}h overtime (${entry.amount} ${CONFIG.CURRENCY})`
          ).join('<br>');
          overtimeInfo.style.display = 'block';
        } else {
          overtimeInfo.style.display = 'none';
        }
      }
    } catch (e) {
      console.error('Failed to calculate overtime:', e);
    }
  }, CONFIG.SEARCH_DEBOUNCE_MS);
}

/**
 * Calculate and display order totals in Edit modal
 */
document.getElementById('e_start_time')?.addEventListener('change', () => {
  calculateEditOvertime();
  refreshEditCalc();
});
document.getElementById('e_end_time')?.addEventListener('change', () => {
  calculateEditOvertime();
  refreshEditCalc();
});
document.getElementById('e_rate')?.addEventListener('input', refreshEditCalc);
document.getElementById('e_vat')?.addEventListener('change', refreshEditCalc);

// Re-check when the status dropdown changes
document.getElementById('e_status')?.addEventListener('change', () => {
  // we need the order currently loaded in the modal
  try {
    const order = window.__currentEditOrder__;
    if (order) evaluateCreditForEdit(order);
  } catch (e) {}
});

let isEditingOrder = false;

function editBookingSnapshotNow() {
  return JSON.stringify({
    cats: bkSelectedCategoryIds('e').sort(),
    rate: document.getElementById('e_rate')?.value,
    vat: document.getElementById('e_vat')?.value,
    materials: document.getElementById('e_need_materials')?.value,
    start: document.getElementById('e_start_time')?.value,
    end: document.getElementById('e_end_time')?.value,
    workers: [...(document.getElementById('e_workers')?.selectedOptions || [])].map(o => o.value).sort(),
    catalog: [...document.querySelectorAll('#e_catalog_panels .bk-catalog-body tr')].map(tr => ({
      sid: tr.querySelector('.bk-cat-item')?.value,
      qty: tr.querySelector('.bk-cat-qty')?.value,
      price: tr.querySelector('.bk-cat-price')?.value,
    })),
  });
}

function editQuoteGrandTotal() {
  const txt = document.getElementById('e_quote_total')?.textContent || '';
  const m = txt.match(/([\d,]+\.?\d*)/);
  return m ? parseFloat(m[1].replace(/,/g, '')) : null;
}

/** True when DB row total does not match the live quote (needs a repair save). */
function editStoredTotalsDifferFromQuote() {
  const o = window.__currentEditOrder__;
  if (!o) return false;
  // Flat-fee jobs (ARS checkout cleaning): fee_charged is the job amount with hourly_rate=0.
  // The edit form quotes from hourly × hours, so it will always "differ" — do not nudge a repair save.
  const hourly = parseFloat(o.hourly_rate || 0);
  const feeCharged = parseFloat(o.fee_charged || 0);
  if (hourly <= 0.00001 && feeCharged > 0.00001) {
    return false;
  }
  const quote = editQuoteGrandTotal();
  if (quote === null || Number.isNaN(quote)) return false;
  const dbGrand = parseFloat(o.grand_total || o.total || 0);
  return Math.abs(quote - dbGrand) > 0.02;
}

editModal?.addEventListener('hidden.bs.modal', () => resetEditBookingForm());

editForm.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit') {
    e.preventDefault();
  }
});
createForm.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit') {
    e.preventDefault();
  }
});

editForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  
  if (isEditingOrder) return;

  bkUpdatePrimaryCategoryHidden('e');
  const catIds = bkSelectedCategoryIds('e');
  if (!catIds.length) {
    editError.textContent = 'Select at least one service category.';
    editError.style.display = '';
    return;
  }
  if (bkIsCleaningSelected('e') && !(parseFloat(document.getElementById('e_rate')?.value || '0') > 0)) {
    editError.textContent = 'Hourly rate is required when Cleaning is selected.';
    editError.style.display = '';
    return;
  }

  const snapNow = editBookingSnapshotNow();
  const isUnchanged = window.__editBookingSnapshot__ && snapNow === window.__editBookingSnapshot__;
  if (isUnchanged && !editStoredTotalsDifferFromQuote()) {
    bootstrap.Modal.getInstance(editModal)?.hide();
    return;
  }
  
  // If confirming & warning is visible but override is not checked, block submit
  const isWarning = (e_credit_alert.style.display !== 'none');
  const statusVal = (document.getElementById('e_status')?.value || '').toLowerCase();
  if (statusVal === 'confirmed' && isWarning && !e_override_chk.checked) {
    // ensure the warning is visible to the user
    e_credit_alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  const currentOrder = window.__currentEditOrder__ || {};
  let cancellationReason = null;
  if (statusVal === 'cancelled' && (currentOrder.status || '').toLowerCase() !== 'cancelled') {
    cancellationReason = await promptCancellationReason('Order #' + (currentOrder.id || e_order.value));
    if (!cancellationReason) return;
  }
  
  isEditingOrder = true;
  const submitBtn = e.target.querySelector('button[type="submit"]');
  showLoading(submitBtn, 'Saving...');
  
  try {
    const fd=new FormData(e.target);
    if (cancellationReason) {
      fd.append('cancellation_category', cancellationReason.category);
      fd.append('cancellation_details', cancellationReason.details);
    }
    const r=await fetch('api_availability.php',{method:'POST',body:fd}); 
    let j; 
    try { j=await r.json(); } catch { j={success:false,error:'Invalid server response'}; }
    
    if(!j?.success){ 
      editError.className = 'text-danger small mt-2';
      editError.innerHTML='<strong>Failed to save:</strong> ' + (j?.error||'Unknown error');
      editError.style.display='';
      hideLoading(submitBtn);
      return;
    }
    
    bootstrap.Modal.getInstance(editModal).hide();
    location.reload();
  } finally {
    isEditingOrder = false;
  }
});

// Time-off save
timeOffForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(timeOffForm);
  try{
    const r = await fetch('api_availability.php', {method:'POST', body:fd});
    const j = await r.json();
    if(!j?.success){ throw new Error(j?.error||'Failed'); }
    
    // Store for undo (need to get the created ID - but API doesn't return it yet)
    // storeUndoAction('unavail_create', {id: j.id, worker: fd.get('worker_id'), date: fd.get('date')});
    
    bootstrap.Modal.getInstance(timeOffModal).hide();
    location.reload();
  }catch(err){
    toErr.textContent = err.message || 'Error saving time-off';
    toErr.style.display = '';
  }
});

// Range cache for multi-day view
let rangeCache = null; // {from,to, daysByKey: { 'YYYY-MM-DD': {...} }, workers: [...]}

async function fetchRange(from, to){
  const url = `api_availability.php?action=range&from=${from}&to=${to}&start=${START}&end=${END}&slot=${SLOT}`;
  const j = await getJSON(url);
  if(!j?.success) throw new Error(j?.error||'Range fetch failed');
  const byKey = {};
  (j.days||[]).forEach(d => { byKey[d.day] = d; });
  rangeCache = {from:j.range.from, to:j.range.to, workers:j.workers||[], daysByKey:byKey};
  return rangeCache;
}

function buildStrip(from, to){
  const strip = document.getElementById('dateStrip');
  strip.innerHTML = '';
  for (let i=0;i<=daysBetween(from,to);i++){
    const d = addDaysStr(from, i);
    const btn = document.createElement('button');
    btn.className = 'btn btn-sm ' + (d===DAY ? 'btn-primary':'btn-outline-primary');
    btn.textContent = d;
    btn.addEventListener('click', ()=>{
      DAY = d;
      const pay = dayPayloadFromCache(d);
      if (pay) { renderTotalsCard({totals: pay.totals}); renderGrid({workersAll: rangeCache.workers, events: pay.events, unavailability: pay.unavailability}); highlightStrip(); }
    });
    strip.appendChild(btn);
  }
  function highlightStrip(){
    [...strip.querySelectorAll('button')].forEach(b=> b.classList.toggle('btn-primary', b.textContent===DAY));
    [...strip.querySelectorAll('button')].forEach(b=> b.classList.toggle('btn-outline-primary', b.textContent!==DAY));
  }
  highlightStrip();
}
function dayPayloadFromCache(isoDay){
  if(!rangeCache) return null;
  return rangeCache.daysByKey[isoDay] || null;
}

// Boot
let payloadCache=null;
async function boot(){
  if (VIEW === 'day') {
    const p = await fetchAll();
    payloadCache = p;
    ALL_SERVICES_CACHE = p.services || FALLBACK.services || [];
    smWireCategoryListeners();
    document.getElementById('dateStrip').innerHTML = '';
    renderTotalsCard(p);
    renderGrid(p);
  } else {
    const span = (VIEW==='3d') ? 2 : 6;
    const from = DAY;
    const to   = addDaysStr(DAY, span);
    const rc = await fetchRange(from, to);
    buildStrip(from, to);
    const todayPayload = dayPayloadFromCache(DAY);
    renderTotalsCard({totals: todayPayload?.totals || null});
    renderGrid({workersAll: rc.workers, events: todayPayload?.events||[], unavailability: todayPayload?.unavailability||[]});
  }
  
}
boot();

// live filter with debouncing
let searchTimeout = null;
workerSearch.addEventListener('input', ()=> {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    const payload = payloadCache || payloadGlobal;
    if (payload) renderGrid(payload);
  }, CONFIG.SEARCH_DEBOUNCE_MS);
});
toggleBusy.addEventListener('change', ()=> pushURL());

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
  // Ctrl/Cmd + K - Focus worker search
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault();
    workerSearch.focus();
    workerSearch.select();
  }
  
  // Ctrl/Cmd + N - Open create modal (only if no modal is open)
  if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
    const openModals = document.querySelectorAll('.modal.show');
    if (openModals.length === 0) {
      e.preventDefault();
      btnRepeat.click();
    }
  }
  
  // T - Jump to today
  if (e.key === 't' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    const activeElement = document.activeElement;
    // Only if not typing in an input/textarea
    if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElement.tagName)) {
      e.preventDefault();
      navToday.click();
    }
  }
  
  // Arrow keys - Navigate days
  if (e.key === 'ArrowLeft' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    const activeElement = document.activeElement;
    if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElement.tagName)) {
      e.preventDefault();
      navPrev.click();
    }
  }
  
  if (e.key === 'ArrowRight' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    const activeElement = document.activeElement;
    if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElement.tagName)) {
      e.preventDefault();
      const d = new Date(dayPicker.value || DAY);
      d.setDate(d.getDate() + 1);
      dayPicker.value = d.toISOString().slice(0, 10);
      pushURL();
    }
  }
});
</script>
</body>
</html>
