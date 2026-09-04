<?php
// accounts/batch_invoices.php
// expects $conn and helper h() from account.php

// Read optional prefill params from URL
$clientIdPrefill = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$fromPrefill     = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : '';
$toPrefill       = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : '';

$clients = $conn->query("SELECT id, client_name FROM client ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="card-body">
    <h5 class="card-title mb-3">Batch / Periodic Invoicing</h5>
    <div class="alert alert-warning py-2 small mb-3">
      <strong>Hybrid batch (Phase 4):</strong> Each work order must be <strong>finalized</strong> first (child invoice + GL).
      Batch creates a <strong>summary invoice (BINV-)</strong> for the client — child invoices stay on each WO.
    </div>

    <div class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Client</label>
<select id="bi-client" class="form-select">
  <?php foreach ($clients as $c): $cid=(int)$c['id']; ?>
    <option value="<?= $cid ?>" <?= $cid===$clientIdPrefill ? 'selected' : '' ?>>
      <?= h($c['client_name']) ?>
    </option>
  <?php endforeach; ?>
</select>
      </div>

      <div class="col-md-2">
        <label class="form-label">From</label>
<input type="date" id="bi-from" class="form-control" value="<?= h($fromPrefill) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">To</label>
<input type="date" id="bi-to"   class="form-control" value="<?= h($toPrefill) ?>">
      </div>

      <div class="col-md-2">
        <div class="form-check mt-4">
          <input type="checkbox" id="bi-show-invoiced" class="form-check-input">
          <label class="form-check-label" for="bi-show-invoiced" title="Check this to see orders that already have invoices (for generating ROV)">
            Show Invoiced
          </label>
        </div>
      </div>

      <div class="col-md-2">
        <button id="bi-preview" class="btn btn-primary w-100">Preview</button>
      </div>
    </div>

    <div class="table-responsive mt-3">
      <table id="bi-table" class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Date</th>
            <th>Time</th>
            <th>Workers</th>
            <th class="text-end">Hours</th>
            <th class="text-end">Rate</th>
            <th class="text-end">Subtotal</th>
            <th class="text-end">VAT</th>
            <th class="text-end">Total</th>
            <th class="text-center">Status</th>
            <th class="text-center">Child Inv.</th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr>
            <th colspan="4" class="text-end">Totals:</th>
            <th id="bi-sum-hours" class="text-end">0.00</th>
            <th></th>
            <th id="bi-sum-sub" class="text-end">0.00</th>
            <th id="bi-sum-vat" class="text-end">0.00</th>
            <th id="bi-sum-tot" class="text-end">0.00</th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="d-flex gap-2 mb-3">
<select id="bi-itemization" class="form-select" style="max-width:240px">
  <option value="per_order">One line per order</option>
  <option value="single_line" selected>Single summarized line</option>
</select>
      <button id="bi-generate" class="btn btn-success">Generate Invoice</button>
      <button id="bi-rov" class="btn btn-outline-secondary ms-2">Generate ROV</button>
    </div>

    <div class="alert alert-info mb-0" style="font-size:0.9rem;">
      <strong><i class="bi bi-info-circle"></i> Workflow:</strong>
      <ol class="mb-0 mt-2 ps-3">
        <li>Operations: complete jobs → Admin <strong>finalizes</strong> each WO (child invoice + GL)</li>
        <li>Select client and date range, click <strong>Preview</strong> — only finalized, not-yet-batched WOs appear</li>
        <li>Click <strong>Generate Invoice</strong> to create the <strong>BINV summary</strong> (no duplicate GL)</li>
        <li>Use <strong>Show Invoiced</strong> + <strong>Generate ROV</strong> for the visit record document</li>
      </ol>
    </div>
  </div>
</div>

<script>
(function(){
  const $tbody = document.querySelector('#bi-table tbody');
  const fmt = n => (Number(n)||0).toFixed(2);

  async function preview(){
    try{
    $tbody.innerHTML = '';
    const client = document.getElementById('bi-client').value;
    const from   = document.getElementById('bi-from').value;
    const to     = document.getElementById('bi-to').value;
    const showInvoiced = document.getElementById('bi-show-invoiced').checked ? '1' : '0';

    if(!client || !from || !to){ alert('Pick client and date range.'); return; }

    const res = await fetch(`accounts/api_batch_invoices.php?action=preview&client_id=${client}&from=${from}&to=${to}&show_invoiced=${showInvoiced}`);
    const j = await res.json();
    if(!j.success){ alert(j.error||'Preview failed'); return; }

    let hours=0, sub=0, vat=0, tot=0;
    for(const r of j.items){
      hours += +r.hours; sub += +r.total; vat += +r.vat_amount; tot += +r.grand_total;
      const childInv = r.child_invoice_no || (r.child_invoice_id ? ('#'+r.child_invoice_id) : '');
      const isFinalized = r.is_finalized == 1 || childInv;
      const statusBadge = isFinalized
        ? `<span class="badge bg-success">Finalized</span>`
        : `<span class="badge bg-warning text-dark">Not finalized</span>`;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.id}</td>
        <td>${r.svc_date_calc}</td>
        <td>${(r.start_time||'').substring(0,5)}–${(r.end_time||'').substring(0,5)}</td>
        <td>${r.worker_name||''}</td>
        <td class="text-end">${fmt(r.hours)}</td>
        <td class="text-end">${fmt(r.hourly_rate)}</td>
        <td class="text-end">${fmt(r.total)}</td>
        <td class="text-end">${fmt(r.vat_amount)}</td>
        <td class="text-end">${fmt(r.grand_total)}</td>
        <td class="text-center">${statusBadge}</td>
        <td class="text-center">${childInv ? `<span class="badge bg-light text-dark border">${childInv}</span>` : '—'}</td>
      `;
      $tbody.appendChild(tr);
    }
    document.getElementById('bi-sum-hours').textContent = fmt(hours);
    document.getElementById('bi-sum-sub').textContent   = fmt(sub);
    document.getElementById('bi-sum-vat').textContent   = fmt(vat);
    document.getElementById('bi-sum-tot').textContent   = fmt(tot);

    if(j.items.length===0){
      const tr = document.createElement('tr');
      tr.innerHTML = `<td colspan="11" class="text-center text-muted">No eligible finalized orders with child invoices in this range.</td>`;
      $tbody.appendChild(tr);
    }
   }catch(err){
    console.error('Preview error:', err);
    alert('Could not load preview (see console).');
  }

  }

  document.getElementById('bi-preview').addEventListener('click', preview);

document.getElementById('bi-generate').addEventListener('click', async () => {
  try {
    const client = document.getElementById('bi-client').value;
    const from   = document.getElementById('bi-from').value;
    const to     = document.getElementById('bi-to').value;
    const itemz  = document.getElementById('bi-itemization').value;
    if(!client || !from || !to){ alert('Pick client and date range.'); return; }

    const fd = new FormData();
    fd.append('action','commit');
    fd.append('client_id',client);
    fd.append('from',from);
    fd.append('to',to);
    fd.append('itemization',itemz);

    const res = await fetch('accounts/api_batch_invoices.php', { method:'POST', body: fd });
    const j = await res.json();
    if(!j.success){ alert(j.error||'Generate failed'); return; }

    alert('Batch summary created (BINV-). ID: ' + j.invoice_id + '\nChild job invoices (INV-) are unchanged.');
    location.href = `account.php?tab=invoices`; // optional
  } catch (err) {
    console.error('Generate error:', err);
    alert('Could not generate invoice (see console).');
  }
});

// ROV Click handeller 
document.getElementById('bi-rov').addEventListener('click', async () => {
  const client = document.getElementById('bi-client').value;
  const from   = document.getElementById('bi-from').value;
  const to     = document.getElementById('bi-to').value;
  if (!client || !from || !to) { alert('Pick client and date range.'); return; }

  const showInvoiced = document.getElementById('bi-show-invoiced').checked ? '1' : '0';
  const url = `accounts/api_batch_invoices.php?action=rov_pdf&client_id=${client}&from=${from}&to=${to}&show_invoiced=${showInvoiced}`;
  const res = await fetch(url);
  const j = await res.json();
  if (!j.success) { alert(j.error || 'ROV failed'); return; }
  window.open(j.file_url, '_blank');
});

  
// Optional: default range = current month (only if no prefill)
(function setDefaultRange(){
  const hasPrefill =
    <?= $clientIdPrefill > 0 && $fromPrefill && $toPrefill ? 'true' : 'false' ?>;
  if (hasPrefill) return;

  // format YYYY-MM-DD in local time (avoid toISOString timezone shift)
  const pad = n => String(n).padStart(2,'0');
  const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;

  const d = new Date();
  const first = new Date(d.getFullYear(), d.getMonth(), 1);
  const last  = new Date(d.getFullYear(), d.getMonth()+1, 0);
  document.getElementById('bi-from').value = fmt(first);
  document.getElementById('bi-to').value   = fmt(last);
})();

// AUTO-PREFILL + PREVIEW
 const prefill = {
   clientId: <?= json_encode($clientIdPrefill) ?>,
   from:     <?= json_encode($fromPrefill) ?>,
   to:       <?= json_encode($toPrefill) ?>
 };

 // If URL carried client_id/from/to, run preview now
 if (prefill.clientId > 0 && prefill.from && prefill.to) {
   // ensure inputs reflect the params (in case default-range code ran later)
   document.getElementById('bi-client').value = String(prefill.clientId);
   document.getElementById('bi-from').value   = prefill.from;
   document.getElementById('bi-to').value     = prefill.to;
   // trigger preview
   document.getElementById('bi-preview').click();
 }


})();

</script>
