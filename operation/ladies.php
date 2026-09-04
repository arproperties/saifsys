<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/url_helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Get filter state from session or GET (backward compatibility)
$filters = merge_get_with_session('operation_ladies');
$search = trim($filters['search'] ?? '');

// Get all cleaners
$where = "WHERE 1";
$params = [];
if ($search) {
    $where .= " AND (worker_name LIKE ? OR nickname LIKE ? OR mobile_num LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$cleaners_stmt = $conn->prepare("SELECT * FROM workers $where ORDER BY worker_name ASC");
$cleaners_stmt->execute($params);
$cleaners = $cleaners_stmt->fetchAll(PDO::FETCH_ASSOC);

// Pick selected worker - from session or GET
$selected_id = $filters['worker_id'] ?? $_SESSION['ladies_selected_worker_id'] ?? ($cleaners[0]['id'] ?? null);
if (isset($filters['worker_id'])) {
    $_SESSION['ladies_selected_worker_id'] = $filters['worker_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ladies (Cleaners) - Operations</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background: #f8fafb; }
        .sidebar-worker { background: #fff; border-radius: 18px; box-shadow: 0 6px 28px #0001; padding: 18px; min-width: 260px; max-width: 320px;}
        .sidebar-worker .list-group-item.active {background: #1e90ff; color: #fff; border: none;}
        .sidebar-worker .list-group-item {border: none; border-radius: 8px; margin-bottom: 3px; cursor: pointer; font-weight: 500;}
        .sidebar-worker .status-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin-right: 8px;}
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="row g-4">
        <!-- Sidebar -->
        <div class="col-md-3">
            <div class="sidebar-worker">
                <div class="d-flex align-items-center mb-2">
                    <span class="fw-bold fs-5 flex-grow-1" style="color:#1466dd">Cleaners</span>
                </div>
                <input type="text" class="form-control mb-2" id="search-worker" placeholder="Search by name or phone..." oninput="filterWorkerList()">
                <div style="max-height: 470px; overflow-y:auto;">
                    <div class="list-group" id="worker-list">
                        <?php foreach ($cleaners as $w): ?>
                            <a href="javascript:void(0);"
                                class="list-group-item<?= $selected_id == $w['id'] ? ' active' : '' ?>"
                                onclick="selectWorker(event, <?= $w['id'] ?>)"
                                data-worker-id="<?= $w['id'] ?>">
                                <span class="status-dot bg-success"></span>
                                <?= htmlspecialchars($w['worker_name']) ?>
                                <?php if ($w['nickname']): ?>
                                    <small class="text-muted ms-2">(<?= htmlspecialchars($w['nickname']) ?>)</small>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Main Content (AJAX loaded) -->
        <div class="col-md-9">
            <div id="worker-details">
                <!-- Will be filled by AJAX -->
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let CURRENT_WORKER_ID = null;

// Live search
function filterWorkerList() {
  const val = document.getElementById('search-worker').value.toLowerCase();
  document.querySelectorAll('#worker-list .list-group-item').forEach(function(item){
    const txt = item.textContent.toLowerCase();
    item.style.display = txt.includes(val) ? '' : 'none';
  });
}

// Load right panel
function selectWorker(event, workerId, page=1) {
  event.preventDefault();
  CURRENT_WORKER_ID = workerId;

  document.querySelectorAll('#worker-list .list-group-item').forEach(el => el.classList.remove('active'));
  event.currentTarget.classList.add('active');

  document.getElementById('worker-details').innerHTML =
    '<div style="min-height:280px;display:flex;justify-content:center;align-items:center;"><div class="spinner-border text-info" role="status"></div></div>';

  // Store filter state in session
  fetch('accounts/ajax/store_filters.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      page: 'operation_ladies',
      filters: {worker_id: workerId, page: page}
    })
  });

  fetch('operation/ajax_worker_details.php?worker_id=' + workerId + '&page=' + page)
    .then(res => res.text())
    .then(html => {
      document.getElementById('worker-details').innerHTML = html;
      // Update URL to clean format
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', 'operation');
      }
    });
}

// Delegated handlers so they work after every reload:
document.addEventListener('submit', function(e) {
  if (e.target && e.target.id === 'filterForm') {
    e.preventDefault();
    if (!CURRENT_WORKER_ID) return;
    const formData = new FormData(e.target);
    const filters = {};
    for (let [key, value] of formData.entries()) {
      filters[key] = value;
    }
    filters.worker_id = CURRENT_WORKER_ID;
    
    // Store filters in session
    fetch('accounts/ajax/store_filters.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        page: 'operation_ladies',
        filters: filters
      })
    });
    
    const params = new URLSearchParams(formData).toString();
    fetch('operation/ajax_worker_details.php?' + params)
      .then(res => res.text())
      .then(html => { document.getElementById('worker-details').innerHTML = html; });
  }
});

document.addEventListener('click', function(e) {
  const link = e.target.closest('.pagination .page-link');
  if (link && link.dataset.page) {
    e.preventDefault();
    const page = parseInt(link.dataset.page, 10);
    if (!CURRENT_WORKER_ID || isNaN(page) || page < 1) return;
    // Preserve current filter inputs if present
    const form = document.getElementById('filterForm');
    const filters = {};
    if (form) {
      const formData = new FormData(form);
      for (let [key, value] of formData.entries()) {
        filters[key] = value;
      }
    }
    filters.worker_id = CURRENT_WORKER_ID;
    filters.page = page;
    
    // Store filters in session
    fetch('accounts/ajax/store_filters.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        page: 'operation_ladies',
        filters: filters
      })
    });
    
    const qs = new URLSearchParams(filters);
    fetch('operation/ajax_worker_details.php?' + qs.toString())
      .then(res => res.text())
      .then(html => { document.getElementById('worker-details').innerHTML = html; });
  }
});

// Initial load
window.onload = function() {
  const first = document.querySelector('#worker-list .list-group-item.active') || document.querySelector('#worker-list .list-group-item');
  if (first) first.click();
}
</script>

</body>
</html>
