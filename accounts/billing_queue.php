<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/work_order_batch_invoice_service.php';
$smPhase4Ready = sm_batch_tables_exist($conn);
?>
<div class="container" style="max-width:1100px;">
  <h2>Billing Queue</h2>
  <?php if (!$smPhase4Ready): ?>
  <div class="alert alert-warning py-2 small mb-3">
    <strong>Batch billing tables are missing.</strong>
    Run <a href="tools/sm_apply_phase4_schema.php" target="_blank">tools/sm_apply_phase4_schema.php</a> once (Admin login), then refresh this page.
    Until then, finalized child invoices will not appear here — use <strong>Accounts → Invoices</strong> instead.
  </div>
  <?php endif; ?>
  <div class="alert alert-info py-2 small mb-3">
    <strong>Two different invoices — don't skip this step if you batch bill clients:</strong>
    <ol class="mb-0 ps-3">
      <li><strong>Finalize</strong> (Worker Availability / Work Order) → creates <strong>child INV-</strong> per job + posts to GL. <em>You already did this.</em></li>
      <li><strong>Billing Queue / Batch Invoices</strong> → groups those child invoices into one <strong>client statement BINV-</strong> (no extra GL). <em>Optional for one-off jobs; needed for weekly/monthly client billing.</em></li>
    </ol>
  </div>
  <p class="text-muted small">Lists clients with finalized jobs that have a child invoice but are <strong>not yet</strong> on a batch summary (BINV). Set <strong>Cadence → All</strong> if you don't see a client.</p>

  <div class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label">Cadence</label>
        <select id="fltCadence" class="form-select">
          <option value="">All</option>
          <option value="D">Daily</option>
          <option value="W">Weekly</option>
          <option value="Bi-W">Bi-Weekly</option>
          <option value="M">Monthly</option>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label">Min total (AED)</label>
        <input id="fltMin" type="number" step="0.01" class="form-control" value="0">
      </div>
      <div class="col-auto form-check mt-4">
        <input id="fltDueOnly" type="checkbox" class="form-check-input">
        <label class="form-check-label">Due only</label>
      </div>
      <div class="col-auto">
        <button id="btnRefresh" class="btn btn-primary">Refresh</button>
      </div>
    </div>
  </div>

  <div id="metrics" class="mb-2"></div>

  <div class="table-responsive">
    <table class="table table-sm align-middle" id="tblQueue">
      <thead>
        <tr>
          <th>Client</th>
          <th>Cadence</th>
          <th>Unbilled Window</th>
          <th class="text-end">Orders</th>
          <th class="text-end">Hours</th>
          <th class="text-end">Subtotal</th>
          <th class="text-end">VAT</th>
          <th class="text-end">Total</th>
          <th>Credit</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody><tr><td colspan="10">Loading…</td></tr></tbody>
    </table>
  </div>

<div class="d-flex justify-content-between align-items-center mt-2" id="pagerBar">
  <div class="small text-muted" id="pagerInfo">Showing 0–0 of 0</div>
  <div class="d-flex gap-2 align-items-center">
    <label class="form-label m-0 me-2 small">Rows / page</label>
    <select id="pageSize" class="form-select form-select-sm" style="width:90px">
      <option>25</option>
      <option selected>50</option>
      <option>100</option>
      <option>200</option>
    </select>
    <div class="btn-group btn-group-sm">
      <button class="btn btn-outline-secondary" id="btnPrev">‹ Prev</button>
      <button class="btn btn-outline-secondary" id="btnNext">Next ›</button>
    </div>
  </div>
</div>
</div>

<script>
let currentPage = 1;

function fmt2(n){ return Number(n||0).toFixed(2); }

async function fetchQueue(page = currentPage) {
  currentPage = page;

  const cad  = document.getElementById('fltCadence').value;
  const min  = document.getElementById('fltMin').value || 0;
  const due  = document.getElementById('fltDueOnly').checked ? 1 : 0;
  const ps   = parseInt(document.getElementById('pageSize').value, 10) || 50;

  const url = `accounts/api_billing_queue.php` +
    `?action=due_clients&cadence=${encodeURIComponent(cad)}` +
    `&min_total=${min}&due_only=${due}&page=${page}&page_size=${ps}`;

  const r = await fetch(url);
  const js = await r.json();
  if (!js.success) { alert(js.error || 'Failed'); return; }

  const tb = document.querySelector('#tblQueue tbody');
  tb.innerHTML = '';

  let cDue=0, cBlocked=0, sumTotal=0;

  if (!js.items.length) {
    tb.innerHTML = `<tr><td colspan="10" class="text-muted py-4 text-center">
      No clients ready for batch billing on this page.
      <div class="small mt-1">If you just finalized one job, check <strong>Accounts → Invoices</strong> for the child INV — batch (BINV) is only needed to combine multiple jobs into one client statement.</div>
      <div class="small">Try <strong>Cadence → All</strong> and click Refresh.</div>
    </td></tr>`;
  }

  for (const it of js.items) {
    // backend now filters below-min already; status is 'due' or 'not-due'
    const statusBadge = it.status==='due'
      ? '<span class="badge bg-success">Due</span>'
      : '<span class="badge bg-light text-dark">Not due</span>';

    const creditBadge = it.credit.blocked
      ? `<span class="badge bg-danger">Blocked</span>`
      : `<span class="badge bg-info text-dark">OK</span>`;

    const rangeText = it.range ? `${it.range.from} → ${it.range.to}` : '—';

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <div class="fw-semibold">${it.client_name}</div>
        <div class="small text-muted">${statusBadge} ${it.auto_bill?'<span class="badge bg-warning text-dark ms-1">Auto</span>':''}</div>
      </td>
      <td>${it.cadence || '—'}</td>
      <td>${rangeText}</td>
      <td class="text-end">${it.orders_cnt}</td>
      <td class="text-end">${fmt2(it.hours)}</td>
      <td class="text-end">${fmt2(it.subtotal)}</td>
      <td class="text-end">${fmt2(it.vat)}</td>
      <td class="text-end fw-semibold">${fmt2(it.grand_total)}</td>
      <td>
        <div class="small">Limit: ${fmt2(it.credit.limit)}</div>
        <div class="small">Outstanding: ${fmt2(it.credit.outstanding)}</div>
        ${creditBadge}
      </td>
      <td>
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-secondary"
            onclick="gotoPreview(${it.client_id}, '${it.range?it.range.from:''}', '${it.range?it.range.to:''}')">Preview</button>
          <button class="btn btn-primary"
            ${(!it.range || it.credit.blocked)?'disabled':''}
            onclick="generateAndEmail(${it.client_id}, '${it.range?it.range.from:''}', '${it.range?it.range.to:''}')">Generate</button>
        </div>
      </td>
    `;
    tb.appendChild(tr);

    if (it.status==='due') cDue++;
    if (it.credit.blocked) cBlocked++;
    sumTotal += Number(it.grand_total||0);
  }

  // metrics
  document.getElementById('metrics').innerHTML =
    `<div class="alert alert-light border">
       <b>${js.items.length}</b> clients on this page —
       <b>${cDue}</b> due now, <b>${cBlocked}</b> credit-blocked.
       Page total: <b>AED ${fmt2(sumTotal)}</b>.
     </div>`;

  // pager info & buttons
  const p = js.pagination || {page:1,page_size:ps,total_rows:js.items.length,total_pages:1};
  const shown = js.items.length;
  const start = (shown===0) ? 0 : ((p.page-1)*p.page_size)+1;
  const end   = shown===0 ? 0 : start + shown - 1;
  document.getElementById('pagerInfo').textContent =
    shown
      ? `Showing ${start}–${end} — ${shown} client${shown===1?'':'s'} on this page`
      : `No clients on this page (database may have more on other pages — try Cadence → All)`;

  document.getElementById('btnPrev').disabled = (p.page<=1);
  document.getElementById('btnNext').disabled = (p.page>=p.total_pages);
}

function gotoPreview(clientId, from, to) {
  const url = `account.php?tab=batch_invoices&client_id=${clientId}&from=${from}&to=${to}`;
  window.location.href = url;
}

async function generateAndEmail(clientId, from, to) {
  if (!confirm(`Create BINV batch summary for ${from} → ${to}?\n\nThis groups existing child INV- invoices into ONE client statement. It does NOT create duplicate job invoices or extra GL.`)) return;
  const fd = new FormData();
  fd.append('action','generate_and_email');
  fd.append('client_id', clientId);
  fd.append('from', from);
  fd.append('to', to);
  fd.append('itemization','per_order');
  fd.append('send_email','1');
  const r = await fetch('accounts/api_billing_queue.php', {method:'POST', body:fd});
  const js = await r.json();
  if (!js.success) return alert(js.error || 'Failed');
  window.open(`accounts/invoice_view.php?id=${js.invoice_id}`,'_blank');
  fetchQueue(); // refresh current page
}

// events
document.getElementById('btnRefresh').addEventListener('click', () => fetchQueue(1));
document.getElementById('btnPrev').addEventListener('click', () => fetchQueue(currentPage - 1));
document.getElementById('btnNext').addEventListener('click', () => fetchQueue(currentPage + 1));
document.getElementById('pageSize').addEventListener('change', () => fetchQueue(1));

// initial load
fetchQueue();
</script>
