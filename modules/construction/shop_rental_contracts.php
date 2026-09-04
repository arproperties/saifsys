<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_once __DIR__ . '/includes/construction_shop_rental_ops_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_shop_require_company_id($conn);
$userId = (int)(current_user_id() ?: 0);
$ready = co_db_table_exists($conn, 'co_shop_rental_contracts');
$flashMsg = '';
$flashErr = '';
if (!empty($_SESSION['co_shop_contracts_flash']) && is_array($_SESSION['co_shop_contracts_flash'])) {
    $flashMsg = (string)($_SESSION['co_shop_contracts_flash']['msg'] ?? '');
    $flashErr = (string)($_SESSION['co_shop_contracts_flash']['err'] ?? '');
    unset($_SESSION['co_shop_contracts_flash']);
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'delete_contract') {
            $deleteId = (int)($_POST['contract_id'] ?? 0);
            if ($deleteId <= 0) {
                throw new RuntimeException('Select a contract to delete.');
            }
            if (strtoupper(trim((string)($_POST['confirm_purge'] ?? ''))) !== 'DELETE') {
                throw new RuntimeException('Type DELETE to confirm permanent purge of this contract and its financial postings.');
            }
            $result = co_shop_delete_contract($conn, $cid, $deleteId, $userId);
            $p = $result['purged'] ?? [];
            $_SESSION['co_shop_contracts_flash'] = [
                'msg' => 'Purged contract ' . ($result['contract_number'] ?: ('#' . $deleteId))
                    . ' — invoices: ' . (int)($p['invoices'] ?? 0)
                    . ', payments: ' . (int)($p['payments'] ?? 0)
                    . ', journals: ' . (int)($p['journals'] ?? 0)
                    . ', deposits: ' . (int)($p['deposit_receipts'] ?? 0) . '.',
            ];
            header('Location: shop_rental_contracts.php');
            exit;
        }
        if ($action === 'delete_contracts_bulk') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['contract_ids'] ?? [])), static fn($v) => $v > 0)));
            if (!$ids) {
                throw new RuntimeException('Select at least one contract to delete.');
            }
            $ok = [];
            $fail = [];
            if (strtoupper(trim((string)($_POST['confirm_purge'] ?? ''))) !== 'DELETE') {
                throw new RuntimeException('Type DELETE to confirm permanent purge of selected contracts and their financial postings.');
            }
            foreach ($ids as $deleteId) {
                try {
                    $result = co_shop_delete_contract($conn, $cid, $deleteId, $userId);
                    $p = $result['purged'] ?? [];
                    $ok[] = ($result['contract_number'] ?: ('#' . $deleteId))
                        . ' (J' . (int)($p['journals'] ?? 0) . '/Inv' . (int)($p['invoices'] ?? 0) . ')';
                } catch (Throwable $e) {
                    $fail[] = $e->getMessage();
                }
            }
            if ($ok && $fail) {
                $_SESSION['co_shop_contracts_flash'] = [
                    'msg' => 'Deleted: ' . implode(', ', $ok) . '.',
                    'err' => 'Blocked: ' . implode(' | ', $fail),
                ];
            } elseif ($ok) {
                $_SESSION['co_shop_contracts_flash'] = ['msg' => 'Deleted: ' . implode(', ', $ok) . '.'];
            } else {
                $_SESSION['co_shop_contracts_flash'] = ['err' => implode(' | ', $fail)];
            }
            header('Location: shop_rental_contracts.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['co_shop_contracts_flash'] = ['err' => $e->getMessage()];
        header('Location: shop_rental_contracts.php');
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$expiry = trim((string)($_GET['expiry'] ?? '')); // soon|past|all
$health = trim((string)($_GET['health'] ?? '')); // poor|fair|good
$deposit = trim((string)($_GET['deposit'] ?? '')); // missing|partial|held
$export = $_GET['export'] ?? '';

$allowedStatus = ['draft', 'active', 'renewed', 'expired', 'terminated', 'archived'];
if ($status !== '' && !in_array($status, $allowedStatus, true)) {
    $status = '';
}

$rows = [];
$metrics = [];
$shopLabels = [];
if ($ready) {
    $sql = "
        SELECT c.*, u.shop_number, cl.client_name
        FROM co_shop_rental_contracts c
        JOIN co_shop_units u ON u.id = c.shop_unit_id
        JOIN co_clients cl ON cl.id = c.client_id
        WHERE c.company_id = ?
    ";
    $params = [$cid];
    if ($status !== '') {
        $sql .= " AND c.status = ?";
        $params[] = $status;
    }
    if ($expiry === 'soon') {
        $sql .= " AND c.status = 'active' AND c.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
    } elseif ($expiry === 'past') {
        $sql .= " AND c.status = 'active' AND c.end_date < CURDATE()";
    }
    if ($q !== '') {
        $sql .= " AND (
            c.contract_number LIKE ?
            OR cl.client_name LIKE ?
            OR u.shop_number LIKE ?
            OR EXISTS (
                SELECT 1 FROM co_shop_rental_contract_shops cs
                JOIN co_shop_units su ON su.id = cs.shop_unit_id
                WHERE cs.contract_id = c.id AND cs.company_id = c.company_id
                  AND su.shop_number LIKE ?
            )
        )";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY c.start_date DESC, c.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $metrics = co_shop_portfolio_metrics($conn, $cid, $rows);
    $shopLabels = co_shop_batch_shops_labels($conn, $cid, array_map(static fn($r) => (int)$r['id'], $rows));

    // Client-side metric filters applied after batch metrics (health/deposit)
    if ($health !== '' || $deposit !== '') {
        $rows = array_values(array_filter($rows, static function ($row) use ($metrics, $health, $deposit) {
            $m = $metrics[(int)$row['id']] ?? null;
            if (!$m) {
                return false;
            }
            if ($health !== '' && ($m['health_status'] ?? '') !== $health) {
                return false;
            }
            if ($deposit !== '' && ($m['deposit_status'] ?? '') !== $deposit) {
                return false;
            }
            return true;
        }));
    }
}

// CSV export of filtered list
if ($export === 'csv' && $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shop_contracts_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Contract', 'Shops', 'Tenant', 'Start', 'End', 'Status', 'Rent Net', 'Outstanding', 'Overdue', 'Health', 'Deposit', 'Payment']);
    foreach ($rows as $row) {
        $m = $metrics[(int)$row['id']] ?? [];
        fputcsv($out, [
            $row['contract_number'],
            $shopLabels[(int)$row['id']] ?? $row['shop_number'],
            $row['client_name'],
            $row['start_date'],
            $row['end_date'],
            $row['status'],
            $row['rent_amount'],
            $m['outstanding'] ?? '',
            $m['overdue'] ?? '',
            $m['health_label'] ?? '',
            $m['deposit_label'] ?? '',
            $m['payment_label'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$statusCounts = ['total' => 0, 'active' => 0, 'draft' => 0, 'expired' => 0, 'terminated' => 0, 'renewed' => 0, 'archived' => 0];
if ($ready) {
    $cntStmt = $conn->prepare("SELECT status, COUNT(*) AS n FROM co_shop_rental_contracts WHERE company_id = ? GROUP BY status");
    $cntStmt->execute([$cid]);
    foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $st = (string)$cr['status'];
        $n = (int)$cr['n'];
        $statusCounts['total'] += $n;
        if (isset($statusCounts[$st])) {
            $statusCounts[$st] = $n;
        }
    }
}

$coUiV2 = true;
$pageTitle = 'Shop Rental Contracts';
require_once __DIR__ . '/includes/construction_layout_header.php';

$badge = static function (string $status, string $label): string {
    $map = [
        'good' => 'success', 'held' => 'success', 'paid' => 'success', 'ok' => 'success', 'none' => 'secondary',
        'fair' => 'warning text-dark', 'partial' => 'warning text-dark', 'soon' => 'warning text-dark', 'uninvoiced' => 'secondary',
        'poor' => 'danger', 'missing' => 'danger', 'overdue' => 'danger', 'expired' => 'danger',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};

$qs = static function (array $extra = []) use ($q, $status, $expiry, $health, $deposit): string {
    return http_build_query(array_filter(array_merge([
        'q' => $q, 'status' => $status, 'expiry' => $expiry, 'health' => $health, 'deposit' => $deposit,
    ], $extra), static fn($v) => $v !== '' && $v !== null));
};
?>
<style>
.shop-bulk-bar { display:none; position:sticky; top:0; z-index:5; background:var(--co-bg-card); border:1px solid var(--co-border); border-radius:var(--co-radius-sm); }
.shop-bulk-bar.show { display:flex; }
.shop-quick { font-size:.78rem; color:var(--co-text-dim); }
</style>
<div class="co-page-header">
    <div>
        <div class="co-crumb"><a href="shop_rental_control_center.php">Control Center</a><span>/</span><span>Contracts</span></div>
        <h1>Shop Rental Contracts</h1>
        <p class="co-page-sub">Madar Al Wadi portfolio — search, filters, export · money stays contract-level.</p>
    </div>
    <div class="co-page-actions">
        <a href="shop_rental_control_center.php" class="btn btn-outline-secondary btn-sm"><i data-lucide="gauge" style="width:14px;height:14px" class="me-1"></i> Control Center</a>
        <a href="?<?= h($qs(['export' => 'csv'])) ?>" class="btn btn-outline-secondary btn-sm"><i data-lucide="download" style="width:14px;height:14px" class="me-1"></i> Export CSV</a>
        <a href="shop_rental_contract_add.php" class="btn btn-primary btn-sm"><i data-lucide="plus" style="width:14px;height:14px" class="me-1"></i> New Contract</a>
    </div>
</div>

<div class="co-stat-toggles">
    <?php
    $toggleQs = static function (?string $st) use ($q, $expiry, $health, $deposit): string {
        return '?' . http_build_query(array_filter([
            'q' => $q, 'status' => $st, 'expiry' => $expiry, 'health' => $health, 'deposit' => $deposit,
        ], static fn($v) => $v !== '' && $v !== null));
    };
    ?>
    <a class="co-stat-toggle <?= $status === '' ? 'active' : '' ?>" href="<?= h($toggleQs(null)) ?>"><span class="n"><?= (int)$statusCounts['total'] ?></span><span class="t">Total</span></a>
    <a class="co-stat-toggle <?= $status === 'active' ? 'active' : '' ?>" href="<?= h($toggleQs('active')) ?>"><span class="n"><?= (int)$statusCounts['active'] ?></span><span class="t">Active</span></a>
    <a class="co-stat-toggle <?= $status === 'draft' ? 'active' : '' ?>" href="<?= h($toggleQs('draft')) ?>"><span class="n"><?= (int)$statusCounts['draft'] ?></span><span class="t">Draft</span></a>
    <a class="co-stat-toggle <?= $status === 'expired' ? 'active' : '' ?>" href="<?= h($toggleQs('expired')) ?>"><span class="n"><?= (int)$statusCounts['expired'] ?></span><span class="t">Expired</span></a>
    <a class="co-stat-toggle <?= $status === 'terminated' ? 'active' : '' ?>" href="<?= h($toggleQs('terminated')) ?>"><span class="n"><?= (int)$statusCounts['terminated'] ?></span><span class="t">Terminated</span></a>
</div>

<?php if (!$ready): ?><div class="alert alert-warning">Run <code>migrations/construction_income_workflow.sql</code> to enable shop rental contracts.</div><?php endif; ?>
<?php if ($flashMsg !== ''): ?><div class="alert alert-success"><?= h($flashMsg) ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="alert alert-danger"><?= h($flashErr) ?></div><?php endif; ?>
<div class="alert alert-warning small">
    <strong>Purge delete:</strong> Delete permanently removes the contract <em>and</em> its invoices, payment receipts, deposit receipts,
    and related posted journals from this company’s GL (TB / P&amp;L / BS). This cannot be undone.
    Blocked only if a renewal child still exists or an accounting period is locked.
</div>

<form method="get" class="co-filter-bar mb-3" id="shopFilterForm">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="search" name="q" class="form-control" placeholder="Contract, tenant, shop…" value="<?= h($q) ?>" autofocus>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <?php foreach ($allowedStatus as $st): ?>
                    <option value="<?= h($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Expiry</label>
            <select name="expiry" class="form-select">
                <option value="">Any</option>
                <option value="soon" <?= $expiry === 'soon' ? 'selected' : '' ?>>Expiring ≤60d</option>
                <option value="past" <?= $expiry === 'past' ? 'selected' : '' ?>>Past end (active)</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Health</label>
            <select name="health" class="form-select">
                <option value="">Any</option>
                <option value="good" <?= $health === 'good' ? 'selected' : '' ?>>Good</option>
                <option value="fair" <?= $health === 'fair' ? 'selected' : '' ?>>Fair</option>
                <option value="poor" <?= $health === 'poor' ? 'selected' : '' ?>>Poor</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Deposit</label>
            <select name="deposit" class="form-select">
                <option value="">Any</option>
                <option value="held" <?= $deposit === 'held' ? 'selected' : '' ?>>Held</option>
                <option value="partial" <?= $deposit === 'partial' ? 'selected' : '' ?>>Partial</option>
                <option value="missing" <?= $deposit === 'missing' ? 'selected' : '' ?>>Missing</option>
            </select>
        </div>
        <div class="col-md-auto d-flex gap-2">
            <button class="btn btn-primary">Apply</button>
            <a class="btn btn-outline-secondary" href="shop_rental_contracts.php">Clear</a>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2 align-items-center mt-1">
            <span class="text-muted small">Saved filters:</span>
            <select id="savedFilterSelect" class="form-select form-select-sm" style="max-width:180px">
                <option value="">Load…</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSaveFilter">Save current</button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteFilter">Delete</button>
            <span class="text-muted small ms-auto"><?= count($rows) ?> contract<?= count($rows) === 1 ? '' : 's' ?></span>
        </div>
    </div>
</form>

<div class="alert alert-light border shop-bulk-bar align-items-center gap-2 mb-3 py-2" id="bulkBar">
    <strong><span id="bulkCount">0</span> selected</strong>
    <button type="button" class="btn btn-sm btn-outline-primary" id="bulkExport">Export selected CSV</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkOpen">Open selected</button>
    <button type="button" class="btn btn-sm btn-outline-danger" id="bulkDelete">Delete selected</button>
    <button type="button" class="btn btn-sm btn-link" id="bulkClear">Clear</button>
</div>
<form method="post" id="bulkDeleteForm" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="delete_contracts_bulk">
    <input type="hidden" name="confirm_purge" id="bulkConfirmPurge" value="">
    <div id="bulkDeleteIds"></div>
</form>

<div class="card card-round co-table-shell shop-portfolio"><div class="card-body p-0 table-responsive">
<table class="table table-hover mb-0 align-middle" id="contractsTable">
    <thead class="table-light">
        <tr>
            <th style="width:2.2rem"><input type="checkbox" class="form-check-input" id="checkAll" title="Select all"></th>
            <th>Contract</th>
            <th>Shops / Tenant</th>
            <th>Period</th>
            <th class="text-end">Rent (net)</th>
            <th class="text-end">Outstanding</th>
            <th class="text-end">Overdue</th>
            <th>Deposit</th>
            <th>Payment</th>
            <th>Health</th>
            <th>Expiry</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row):
        $id = (int)$row['id'];
        $m = $metrics[$id] ?? null;
        $days = $m['days_to_expiry'] ?? null;
        $expiryLabel = $days === null ? '—' : ($days < 0 ? 'Expired' : ($days . 'd'));
        $shopsLabel = $shopLabels[$id] ?? (string)$row['shop_number'];
        $pastEnd = ($row['status'] === 'active' && (string)$row['end_date'] < date('Y-m-d'));
    ?>
        <tr data-id="<?= $id ?>" data-contract="<?= h($row['contract_number']) ?>">
            <td><input type="checkbox" class="form-check-input row-check" value="<?= $id ?>"></td>
            <td>
                <strong><?= h($row['contract_number']) ?></strong>
                <?php if ($m): ?><div class="shop-quick"><?= h($m['quick_status']) ?></div><?php endif; ?>
                <?php if ($pastEnd): ?><div class="shop-quick text-danger">Past end date</div><?php endif; ?>
            </td>
            <td>
                <div><?= h($shopsLabel) ?></div>
                <div class="shop-quick"><?= h($row['client_name']) ?></div>
            </td>
            <td class="small"><?= h($row['start_date']) ?><br><span class="text-muted">to <?= h($row['end_date']) ?></span></td>
            <td class="text-end"><?= co_format_money($row['rent_amount']) ?><div class="shop-quick"><?= h($row['payment_frequency']) ?></div></td>
            <td class="text-end <?= ($m && $m['outstanding'] > 0.005) ? 'text-warning' : '' ?>"><?= $m ? co_format_money($m['outstanding']) : '—' ?></td>
            <td class="text-end <?= ($m && $m['overdue'] > 0.005) ? 'text-danger fw-semibold' : '' ?>"><?= $m ? co_format_money($m['overdue']) : '—' ?></td>
            <td><?= $m ? $badge($m['deposit_status'], $m['deposit_label']) : '—' ?></td>
            <td><?= $m ? $badge($m['payment_status'], $m['payment_label']) : '—' ?></td>
            <td><?= $m ? $badge($m['health_status'], $m['health_label']) . '<div class="shop-quick">' . h($m['health_score']) . '</div>' : '—' ?></td>
            <td><?= $m ? $badge($m['expiry_warn'], $expiryLabel) : '—' ?></td>
            <td><?= co_ui_status_pill((string)$row['status']) ?></td>
            <td class="text-end text-nowrap">
                <a href="shop_rental_contract_view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Open</a>
                <form method="post" class="d-inline" onsubmit="var t=prompt('PURGE contract <?= h($row['contract_number']) ?> and ALL linked invoices, receipts, and GL journals?\n\nType DELETE to confirm:'); if(!t||t.toUpperCase()!=='DELETE'){alert('Cancelled.'); return false;} this.querySelector('[name=confirm_purge]').value='DELETE'; return true;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_contract">
                    <input type="hidden" name="contract_id" value="<?= $id ?>">
                    <input type="hidden" name="confirm_purge" value="">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Purge contract + financial postings">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="13"><div class="co-empty"><div class="ico"><i data-lucide="folder-open" style="width:32px;height:32px"></i></div>No shop rental contracts match these filters.</div></td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>

<script>
(function () {
  const KEY = 'co_shop_contract_saved_filters_v1';
  const form = document.getElementById('shopFilterForm');
  const sel = document.getElementById('savedFilterSelect');
  function loadSaved() {
    let map = {};
    try { map = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { map = {}; }
    sel.innerHTML = '<option value="">Load…</option>';
    Object.keys(map).sort().forEach(name => {
      const o = document.createElement('option');
      o.value = name; o.textContent = name; sel.appendChild(o);
    });
    return map;
  }
  let map = loadSaved();
  document.getElementById('btnSaveFilter').addEventListener('click', () => {
    const name = prompt('Name for this filter set:');
    if (!name) return;
    const data = Object.fromEntries(new FormData(form).entries());
    map[name.trim()] = data;
    localStorage.setItem(KEY, JSON.stringify(map));
    map = loadSaved();
    sel.value = name.trim();
  });
  document.getElementById('btnDeleteFilter').addEventListener('click', () => {
    if (!sel.value || !map[sel.value]) return;
    if (!confirm('Delete saved filter "' + sel.value + '"?')) return;
    delete map[sel.value];
    localStorage.setItem(KEY, JSON.stringify(map));
    map = loadSaved();
  });
  sel.addEventListener('change', () => {
    const data = map[sel.value];
    if (!data) return;
    ['q','status','expiry','health','deposit'].forEach(k => {
      const el = form.elements[k];
      if (el) el.value = data[k] || '';
    });
    form.submit();
  });

  const bar = document.getElementById('bulkBar');
  const countEl = document.getElementById('bulkCount');
  const checks = () => [...document.querySelectorAll('.row-check')];
  function refreshBulk() {
    const n = checks().filter(c => c.checked).length;
    countEl.textContent = String(n);
    bar.classList.toggle('show', n > 0);
  }
  document.getElementById('checkAll').addEventListener('change', e => {
    checks().forEach(c => { c.checked = e.target.checked; });
    refreshBulk();
  });
  document.getElementById('contractsTable').addEventListener('change', e => {
    if (e.target.classList.contains('row-check')) refreshBulk();
  });
  document.getElementById('bulkClear').addEventListener('click', () => {
    document.getElementById('checkAll').checked = false;
    checks().forEach(c => { c.checked = false; });
    refreshBulk();
  });
  document.getElementById('bulkOpen').addEventListener('click', () => {
    checks().filter(c => c.checked).forEach(c => {
      window.open('shop_rental_contract_view.php?id=' + c.value, '_blank');
    });
  });
  document.getElementById('bulkExport').addEventListener('click', () => {
    const ids = new Set(checks().filter(c => c.checked).map(c => c.value));
    const rows = [...document.querySelectorAll('#contractsTable tbody tr[data-id]')].filter(tr => ids.has(tr.dataset.id));
    let csv = 'Contract,ID\n';
    rows.forEach(tr => { csv += '"' + (tr.dataset.contract || '').replace(/"/g, '""') + '",' + tr.dataset.id + '\n'; });
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'shop_contracts_selected.csv';
    a.click();
  });
  document.getElementById('bulkDelete').addEventListener('click', () => {
    const selected = checks().filter(c => c.checked);
    if (!selected.length) return;
    const names = selected.map(c => {
      const tr = c.closest('tr');
      return (tr && tr.dataset.contract) ? tr.dataset.contract : ('#' + c.value);
    });
    const typed = prompt('PURGE ' + selected.length + ' contract(s) and ALL linked invoices, receipts, and GL journals?\n\n' + names.join(', ') + '\n\nType DELETE to confirm:');
    if (!typed || typed.toUpperCase() !== 'DELETE') {
      alert('Cancelled.');
      return;
    }
    document.getElementById('bulkConfirmPurge').value = 'DELETE';
    const box = document.getElementById('bulkDeleteIds');
    box.innerHTML = '';
    selected.forEach(c => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'contract_ids[]';
      input.value = c.value;
      box.appendChild(input);
    });
    document.getElementById('bulkDeleteForm').submit();
  });
})();
</script>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
