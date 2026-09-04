<?php
// accounts/invoices.php
require_once __DIR__.'/../includes/advanced_search_service.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/../includes/module_access.php';
require_once __DIR__.'/../includes/permissions.php';

// Check permission (backward compatible: if no permission but has role, allow)
if (!has_permission('invoices.view', MODULE_FINANCE, $conn)) {
    // Fallback to role check for backward compatibility
    require_role(['Owner','Admin','Account'], $conn);
}

// Get current company context
$currentCompanyId = current_company_id($conn) ?: 1;

// Handle advanced search
$search_query = trim($_GET['q'] ?? '');
$search_filters = [];

// Parse search filters from URL parameters
$status = $_GET['status'] ?? '';
if ($status !== '' && in_array($status, ['draft','issued','partially_paid','paid','void'], true)) {
    $search_filters['status'] = [$status]; // Convert to array for search component
}

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if ($from !== '') { $search_filters['date_from'] = $from; }
if ($to !== '') { $search_filters['date_to'] = $to; }

$overdue = !empty($_GET['overdue']);
if ($overdue) { $search_filters['overdue'] = true; }

$amount_min = $_GET['amount_min'] ?? '';
$amount_max = $_GET['amount_max'] ?? '';
if ($amount_min !== '') { $search_filters['amount_min'] = $amount_min; }
if ($amount_max !== '') { $search_filters['amount_max'] = $amount_max; }

$due_from = $_GET['due_from'] ?? '';
$due_to = $_GET['due_to'] ?? '';
if ($due_from !== '') { $search_filters['due_from'] = $due_from; }
if ($due_to !== '') { $search_filters['due_to'] = $due_to; }

$client_status = $_GET['client_status'] ?? '';
if ($client_status !== '' && in_array($client_status, ['active','inactive','vip','at_risk'], true)) {
    $search_filters['client_status'] = $client_status;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

// Build search query using AdvancedSearchService
$searchService = new AdvancedSearchService($conn);
$searchQuery = $searchService->buildInvoiceSearchQuery(array_merge($search_filters, ['search' => $search_query]));

$where = ["1=1"];
$args = [];

// Company filter (always apply)
$where[] = "i.company_id = ?";
$args[] = $currentCompanyId;

// Add search conditions
if (!empty($searchQuery['where_clause'])) {
    $where[] = substr($searchQuery['where_clause'], 6); // Remove "WHERE " prefix
    $args = array_merge($args, $searchQuery['params']);
}

// Summary for the full filtered result set, not only the current page.
// AR totals count child job invoices only — BINV statements are excluded from money totals.
$arOnly = "COALESCE(i.is_batch_summary, 0) = 0";
$summary_sql = "
  SELECT
    COUNT(*) AS total_records,
    COALESCE(SUM(CASE WHEN $arOnly THEN i.total ELSE 0 END), 0) AS total_amount
  FROM invoices i
  LEFT JOIN client c ON c.id = i.client_id
  WHERE ".implode(' AND ', $where);

$summary_st = $conn->prepare($summary_sql);
$summary_st->execute($args);
$invoiceSummary = $summary_st->fetch(PDO::FETCH_ASSOC) ?: [];
$total_records = (int)($invoiceSummary['total_records'] ?? 0);
$filteredTotalAmount = (float)($invoiceSummary['total_amount'] ?? 0);
$total_pages = ceil($total_records / $per_page);
$offset = ($page - 1) * $per_page;

// Status totals use the same filters except the Status dropdown, so the period
// always shows the paid / partial / void split side-by-side.
$statusSummaryFilters = $search_filters;
unset($statusSummaryFilters['status']);
$statusSearchQuery = $searchService->buildInvoiceSearchQuery(array_merge($statusSummaryFilters, ['search' => $search_query]));
$statusWhere = ["1=1", "i.company_id = ?"];
$statusArgs = [$currentCompanyId];
if (!empty($statusSearchQuery['where_clause'])) {
    $statusWhere[] = substr($statusSearchQuery['where_clause'], 6);
    $statusArgs = array_merge($statusArgs, $statusSearchQuery['params']);
}
$status_summary_sql = "
  SELECT
    COALESCE(SUM(CASE WHEN i.status = 'void' AND $arOnly THEN i.total ELSE 0 END), 0) AS void_amount,
    COALESCE(SUM(CASE WHEN i.status = 'paid' AND $arOnly THEN i.total ELSE 0 END), 0) AS paid_amount,
    COALESCE(SUM(CASE WHEN i.status = 'partially_paid' AND $arOnly THEN i.total ELSE 0 END), 0) AS partial_amount
  FROM invoices i
  LEFT JOIN client c ON c.id = i.client_id
  WHERE ".implode(' AND ', $statusWhere);

$status_summary_st = $conn->prepare($status_summary_sql);
$status_summary_st->execute($statusArgs);
$statusSummary = $status_summary_st->fetch(PDO::FETCH_ASSOC) ?: [];
$voidedInvoiceAmount = (float)($statusSummary['void_amount'] ?? 0);
$paidInvoiceAmount = (float)($statusSummary['paid_amount'] ?? 0);
$partialInvoiceAmount = (float)($statusSummary['partial_amount'] ?? 0);

/* Paid = allocations from receipts (excluding void receipts, if you use a status field) */
$sql = "
  SELECT
    i.*,
    c.client_name,
    COALESCE(pa.amount_paid, 0) AS amount_paid,
    CASE
      WHEN COALESCE(i.is_batch_summary, 0) = 1 THEN 0
      ELSE GREATEST(ROUND(COALESCE(i.total,0) - COALESCE(pa.amount_paid,0), 2), 0)
    END AS balance_due,
    COALESCE(i.is_batch_summary, 0) AS is_batch_summary
  FROM invoices i
  LEFT JOIN client c ON c.id = i.client_id
  LEFT JOIN (
    /* Sum of all allocations applied to each invoice */
    SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
    FROM receipt_allocations ra
    GROUP BY ra.invoice_id
  ) pa ON pa.invoice_id = i.id
  WHERE ".implode(' AND ', $where)."
  ORDER BY i.issue_date DESC, i.id DESC
  LIMIT $per_page OFFSET $offset
";
$st = $conn->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<?php
// Simple search form without the problematic component
?>
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="invoices">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?= h($search_query) ?>" placeholder="Search invoices...">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>Issued</option>
                    <option value="partially_paid" <?= $status === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="void" <?= $status === 'void' ? 'selected' : '' ?>>Void</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Client Status</label>
                <select class="form-select" name="client_status">
                    <option value="">All Clients</option>
                    <option value="active" <?= $client_status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $client_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="vip" <?= $client_status === 'vip' ? 'selected' : '' ?>>VIP</option>
                    <option value="at_risk" <?= $client_status === 'at_risk' ? 'selected' : '' ?>>At Risk</option>
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="overdue" value="1" <?= $overdue ? 'checked' : '' ?>>
                    <label class="form-check-label">Overdue Only</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Search</button>
                    <a href="account" class="btn btn-secondary" data-tab="invoices" data-clear-filters="true">Clear</a>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Invoice Date From</label>
                <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Invoice Date To</label>
                <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Due Date From</label>
                <input type="date" class="form-control" name="due_from" value="<?= h($due_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Due Date To</label>
                <input type="date" class="form-control" name="due_to" value="<?= h($due_to) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Amount Min</label>
                <input type="number" step="0.01" min="0" class="form-control" name="amount_min" value="<?= h($amount_min) ?>" placeholder="0.00">
            </div>
            <div class="col-md-2">
                <label class="form-label">Amount Max</label>
                <input type="number" step="0.01" min="0" class="form-control" name="amount_max" value="<?= h($amount_max) ?>" placeholder="0.00">
            </div>
            <div class="col-md-2">
                <label class="form-label">Total Invoices</label>
                <input type="text" class="form-control bg-light fw-semibold" value="<?= number_format($total_records) ?>" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label">Total Amount (AR only)</label>
                <div class="input-group">
                    <span class="input-group-text">AED</span>
                    <input type="text" class="form-control bg-light fw-semibold" value="<?= money($filteredTotalAmount) ?>" readonly>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Voided Invoices Amount</label>
                <div class="input-group">
                    <span class="input-group-text">AED</span>
                    <input type="text" class="form-control bg-light fw-semibold text-secondary" value="<?= money($voidedInvoiceAmount) ?>" readonly>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Paid Invoices Amount</label>
                <div class="input-group">
                    <span class="input-group-text">AED</span>
                    <input type="text" class="form-control bg-light fw-semibold text-success" value="<?= money($paidInvoiceAmount) ?>" readonly>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Partially Paid Amount</label>
                <div class="input-group">
                    <span class="input-group-text">AED</span>
                    <input type="text" class="form-control bg-light fw-semibold text-warning" value="<?= money($partialInvoiceAmount) ?>" readonly>
                </div>
            </div>
        </form>
    </div>
</div>
<?php
?>

<!-- Bulk Actions Toolbar -->
<div id="bulkActionsToolbar" class="card mb-3" style="display: none;">
  <div class="card-body py-2">
    <div class="d-flex align-items-center">
      <span class="me-3">
        <span id="selectedCount">0</span> invoices selected
      </span>
      <div class="btn-group btn-group-sm">
        <button class="btn btn-outline-primary" onclick="showBulkEmailModal()">
          <i class="bi bi-envelope me-1"></i>Send Email
        </button>
        <button class="btn btn-outline-warning" onclick="bulkAction('void')">
          <i class="bi bi-x-circle me-1"></i>Mark as Void
        </button>
        <button class="btn btn-outline-info" onclick="bulkAction('pdf')">
          <i class="bi bi-file-pdf me-1"></i>Download PDFs
        </button>
        <button class="btn btn-outline-success" onclick="bulkAction('csv')">
          <i class="bi bi-file-csv me-1"></i>Export CSV
        </button>
        <button class="btn btn-outline-info" onclick="exportAllData('excel')">
          <i class="bi bi-file-excel me-1"></i>Export Excel
        </button>
        <button class="btn btn-outline-warning" onclick="exportAllData('pdf')">
          <i class="bi bi-file-pdf me-1"></i>Export PDF
        </button>
      </div>
      <button class="btn btn-outline-secondary btn-sm ms-auto" onclick="clearSelection()">
        <i class="bi bi-x"></i> Clear Selection
      </button>
    </div>
  </div>
</div>

<div class="table-responsive">
  <table class="table table-striped align-middle">
    <thead class="table-light">
      <tr>
        <th style="width: 40px;">
          <input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes(this)">
        </th>
        <th>Invoice</th><th>Date</th><th>Client</th>
        <th class="text-end">Total</th>
        <th class="text-end">Paid</th>
        <th class="text-end">Balance</th>
        <th>Status</th><th style="width: 120px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <input type="checkbox" class="invoice-checkbox" value="<?= (int)$r['id'] ?>" onchange="updateBulkActions()">
          </td>
          <td>
            <?= h($r['invoice_no']) ?>
            <?php if (!empty($r['is_batch_summary'])): ?>
              <span class="badge bg-secondary ms-1" title="Client statement only — allocate payments to child INV- invoices">BINV</span>
            <?php endif; ?>
          </td>
          <td><?= h($r['issue_date']) ?></td>
          <td><?= h($r['client_name'] ?: '—') ?></td>
          <td class="text-end"><?= money($r['total']) ?></td>
          <td class="text-end"><?= money($r['amount_paid']) ?></td>
          <td class="text-end">
            <?php if (!empty($r['is_batch_summary'])): ?>
              <span class="text-muted" title="Not collectible — pay child INV- invoices">—</span>
            <?php else: ?>
              <?= money($r['balance_due']) ?>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge text-bg-<?= $r['status']==='paid'?'success':($r['status']==='partially_paid'?'warning':($r['status']==='void'?'secondary':'info')) ?>">
              <?= ucfirst(str_replace('_',' ', $r['status'])) ?>
            </span>
          </td>
          <td>
            <div class="btn-group btn-group-sm">
              <a class="btn btn-outline-primary" href="accounts/invoice_view.php?id=<?= (int)$r['id'] ?>" title="View">
                <i class="bi bi-eye"></i>
              </a>
              <?php if (in_array($r['status'], ['draft', 'issued', 'partially_paid'])): ?>
              <a class="btn btn-outline-warning" href="accounts/invoice_edit.php?id=<?= (int)$r['id'] ?>" title="Edit">
                <i class="bi bi-pencil"></i>
              </a>
              <?php endif; ?>
              <a class="btn btn-outline-info" href="accounts/invoice_print.php?id=<?= (int)$r['id'] ?>" target="_blank" title="Print PDF">
                <i class="bi bi-file-pdf"></i>
              </a>
              <button class="btn btn-outline-success" onclick="sendInvoiceEmail(<?= (int)$r['id'] ?>)" title="Send Email">
                <i class="bi bi-envelope"></i>
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr>
          <td colspan="9" class="text-center text-muted py-4">
            <?php if ($overdue): ?>
              <div class="py-4">
                <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                <h5 class="mt-2">No Overdue Invoices</h5>
                <p class="text-muted">Great news! All your invoices are current.</p>
                <div class="mt-3">
                  <a href="account" class="btn btn-outline-primary me-2" data-tab="invoices" data-filter='{"status":"issued"}'>
                    <i class="bi bi-receipt me-1"></i>View All Outstanding Invoices
                  </a>
                  <a href="account" class="btn btn-outline-secondary" data-tab="invoices">
                    <i class="bi bi-list me-1"></i>View All Invoices
                  </a>
                </div>
              </div>
            <?php else: ?>
              No invoices found.
            <?php endif; ?>
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($total_pages > 1): ?>
<nav aria-label="Invoice pagination" class="mt-3">
  <div class="d-flex justify-content-between align-items-center">
    <div class="text-muted">
      Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_records) ?> of <?= $total_records ?> invoices
    </div>
    <ul class="pagination pagination-sm mb-0">
      <?php if ($page > 1): ?>
        <li class="page-item">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo; Previous</a>
        </li>
      <?php endif; ?>
      
      <?php
      $start_page = max(1, $page - 2);
      $end_page = min($total_pages, $page + 2);
      
      if ($start_page > 1): ?>
        <li class="page-item">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
        </li>
        <?php if ($start_page > 2): ?>
          <li class="page-item disabled"><span class="page-link">...</span></li>
        <?php endif; ?>
      <?php endif; ?>
      
      <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
      
      <?php if ($end_page < $total_pages): ?>
        <?php if ($end_page < $total_pages - 1): ?>
          <li class="page-item disabled"><span class="page-link">...</span></li>
        <?php endif; ?>
        <li class="page-item">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
        </li>
      <?php endif; ?>
      
      <?php if ($page < $total_pages): ?>
        <li class="page-item">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next &raquo;</a>
        </li>
      <?php endif; ?>
    </ul>
  </div>
</nav>
<?php endif; ?>

<!-- Bulk Email Modal -->
<div class="modal fade" id="bulkEmailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="bulkEmailForm">
      <div class="modal-header">
        <h5 class="modal-title">Send Bulk Emails</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Email Template</label>
          <select class="form-select" name="template_id" id="bulkTemplateId">
            <option value="">Use Default Template</option>
            <!-- Templates will be loaded via AJAX -->
          </select>
        </div>
        
        <div class="mb-3">
          <label class="form-label">Custom Message (Optional)</label>
          <textarea class="form-control" name="custom_message" id="bulkCustomMessage" rows="3"
                    placeholder="Add a personal message to include with the emails..."></textarea>
        </div>
        
        <div class="alert alert-info">
          <i class="bi bi-info-circle"></i>
          <span id="bulkEmailCount">0</span> invoices will be processed. 
          Emails will be sent to each client's registered email address.
        </div>
        
        <div class="text-danger small" id="bulkEmailErr" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-envelope"></i> Send Emails
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Bulk operations functionality
function toggleAllCheckboxes(selectAllCheckbox) {
  const checkboxes = document.querySelectorAll('.invoice-checkbox');
  checkboxes.forEach(checkbox => {
    checkbox.checked = selectAllCheckbox.checked;
  });
  updateBulkActions();
}

function updateBulkActions() {
  const checkboxes = document.querySelectorAll('.invoice-checkbox:checked');
  const toolbar = document.getElementById('bulkActionsToolbar');
  const countSpan = document.getElementById('selectedCount');
  
  countSpan.textContent = checkboxes.length;
  
  if (checkboxes.length > 0) {
    toolbar.style.display = 'block';
  } else {
    toolbar.style.display = 'none';
  }
}

function clearSelection() {
  const checkboxes = document.querySelectorAll('.invoice-checkbox');
  const selectAll = document.getElementById('selectAll');
  
  checkboxes.forEach(checkbox => {
    checkbox.checked = false;
  });
  selectAll.checked = false;
  updateBulkActions();
}

function getSelectedInvoiceIds() {
  const checkboxes = document.querySelectorAll('.invoice-checkbox:checked');
  return Array.from(checkboxes).map(cb => cb.value);
}

function bulkAction(action) {
  const selectedIds = getSelectedInvoiceIds();
  
  if (selectedIds.length === 0) {
    alert('Please select at least one invoice.');
    return;
  }
  
  switch (action) {
    case 'void':
      if (confirm(`Mark ${selectedIds.length} invoice(s) as void? This action cannot be undone.`)) {
        bulkVoidInvoices(selectedIds);
      }
      break;
    case 'pdf':
      downloadBulkPDFs(selectedIds);
      break;
    case 'csv':
      exportBulkCSV(selectedIds);
      break;
  }
}

function showBulkEmailModal() {
  const selectedIds = getSelectedInvoiceIds();
  
  if (selectedIds.length === 0) {
    alert('Please select at least one invoice.');
    return;
  }
  
  // Update count in modal
  document.getElementById('bulkEmailCount').textContent = selectedIds.length;
  
  // Load email templates
  loadBulkEmailTemplates();
  
  // Show modal
  new bootstrap.Modal(document.getElementById('bulkEmailModal')).show();
}

function loadBulkEmailTemplates() {
  const templateSelect = document.getElementById('bulkTemplateId');
  if (templateSelect.children.length <= 1) { // Only has default option
    fetch('ajax/get_email_templates.php?type=invoice')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          data.templates.forEach(template => {
            const option = document.createElement('option');
            option.value = template.id;
            option.textContent = template.template_name;
            templateSelect.appendChild(option);
          });
        }
      })
      .catch(error => console.error('Error loading templates:', error));
  }
}

// sendInvoiceEmail function is defined in account.php

function sendBulkEmail(invoiceIds, templateId = null, customMessage = null) {
  const formData = new FormData();
  formData.append('action', 'email');
  formData.append('invoice_ids', JSON.stringify(invoiceIds));
  if (templateId) formData.append('template_id', templateId);
  if (customMessage) formData.append('custom_message', customMessage);
  
  fetch('ajax/bulk_invoice_actions.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert(data.message || `Email queue created and processed. ${data.processed} emails sent, ${data.failed} failed.`);
      clearSelection();
      bootstrap.Modal.getInstance(document.getElementById('bulkEmailModal')).hide();
    } else {
      alert('Error: ' + (data.error || 'Failed to send emails'));
    }
  })
  .catch(error => {
    alert('Error: ' + error.message);
  });
}

function bulkVoidInvoices(invoiceIds) {
  const formData = new FormData();
  formData.append('action', 'void');
  formData.append('invoice_ids', JSON.stringify(invoiceIds));
  
  fetch('ajax/bulk_invoice_actions.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert(`${data.void_count} invoice(s) marked as void.`);
      location.reload();
    } else {
      alert('Error: ' + (data.error || 'Failed to void invoices'));
    }
  })
  .catch(error => {
    alert('Error: ' + error.message);
  });
}

function downloadBulkPDFs(invoiceIds) {
  const params = new URLSearchParams();
  params.append('action', 'pdf');
  params.append('invoice_ids', JSON.stringify(invoiceIds));
  
  window.open('ajax/bulk_invoice_actions.php?' + params.toString(), '_blank');
}

function exportBulkCSV(invoiceIds) {
  const params = new URLSearchParams();
  params.append('action', 'csv');
  params.append('invoice_ids', JSON.stringify(invoiceIds));
  
  window.open('ajax/bulk_invoice_actions.php?' + params.toString(), '_blank');
}

function exportAllData(format) {
  const filters = {
    status: '<?= h($status) ?>',
    q: '<?= h($search_query) ?>',
    from: '<?= h($from) ?>',
    to: '<?= h($to) ?>',
    overdue: <?= $overdue ? 'true' : 'false' ?>,
    amount_min: '<?= h($amount_min) ?>',
    amount_max: '<?= h($amount_max) ?>',
    due_from: '<?= h($due_from) ?>',
    due_to: '<?= h($due_to) ?>',
    client_status: '<?= h($client_status) ?>'
  };
  
  const params = new URLSearchParams();
  params.append('type', 'invoices');
  params.append('format', format);
  params.append('filters', JSON.stringify(filters));
  
  window.open('ajax/export_data.php?' + params.toString(), '_blank');
}

// Bulk email form submission
document.getElementById('bulkEmailForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  
  const selectedIds = getSelectedInvoiceIds();
  const templateId = document.getElementById('bulkTemplateId').value;
  const customMessage = document.getElementById('bulkCustomMessage').value;
  
  document.getElementById('bulkEmailErr').style.display = 'none';
  
  if (selectedIds.length === 0) {
    document.getElementById('bulkEmailErr').textContent = 'No invoices selected';
    document.getElementById('bulkEmailErr').style.display = 'block';
    return;
  }
  
  sendBulkEmail(selectedIds, templateId || null, customMessage || null);
});

// Advanced search integration
document.addEventListener('advancedSearch', function(event) {
  const { query, filters } = event.detail;
  
  // Build URL parameters
  const params = new URLSearchParams();
  params.append('tab', 'invoices');
  
  if (query) {
    params.append('q', query);
  }
  
  // Add filter parameters
  Object.keys(filters).forEach(key => {
    if (filters[key] !== null && filters[key] !== '' && filters[key] !== false) {
      if (Array.isArray(filters[key])) {
        filters[key].forEach(value => {
          params.append(key + '[]', value);
        });
      } else {
        params.append(key, filters[key]);
      }
    }
  });
  
  window.location.href = 'account?' + params.toString();
});
</script>
