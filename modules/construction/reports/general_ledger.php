<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
$cid = $ctx['company_id'];

$accountId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

if ($accountId && empty($_GET['date_from'])) {
    $rangeStmt = $conn->prepare("
        SELECT MIN(entry_date) AS first_entry, MAX(entry_date) AS last_entry
        FROM re_general_ledger
        WHERE account_id = ? AND company_id = ?
    ");
    $rangeStmt->execute([$accountId, $cid]);
    $range = $rangeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($range['first_entry'])) {
        $dateFrom = $range['first_entry'];
    }
    if (empty($_GET['date_to']) && !empty($range['last_entry']) && $range['last_entry'] > $dateTo) {
        $dateTo = $range['last_entry'];
    }
}

$accountsStmt = $conn->prepare("
    SELECT id, account_code, account_name, account_type, normal_balance
    FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1 AND is_header = 0
    ORDER BY FIELD(account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'), account_code
");
$accountsStmt->execute([$cid]);
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$account = null;
$entries = [];
$opening = 0.0;
$closing = 0.0;
if ($accountId) {
    foreach ($accounts as $candidate) {
        if ((int)$candidate['id'] === $accountId) {
            $account = $candidate;
            break;
        }
    }
    if ($account) {
        $normal = $account['normal_balance'] ?? 'debit';
        $openSql = $normal === 'debit'
            ? "SELECT COALESCE(SUM(debit_amount) - SUM(credit_amount), 0) FROM re_general_ledger WHERE account_id = ? AND company_id = ? AND entry_date < ?"
            : "SELECT COALESCE(SUM(credit_amount) - SUM(debit_amount), 0) FROM re_general_ledger WHERE account_id = ? AND company_id = ? AND entry_date < ?";
        $stmt = $conn->prepare($openSql);
        $stmt->execute([$accountId, $cid, $dateFrom]);
        $opening = (float)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT gl.*, jh.journal_number, jh.journal_type, jh.reference_type, jh.reference_id, jh.description AS journal_description
            FROM re_general_ledger gl
            JOIN re_journal_headers jh ON jh.id = gl.journal_id
            WHERE gl.account_id = ? AND gl.company_id = ? AND gl.entry_date BETWEEN ? AND ?
            ORDER BY gl.entry_date ASC, gl.id ASC
        ");
        $stmt->execute([$accountId, $cid, $dateFrom, $dateTo]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Qty / rate come from the supplier invoice line behind each posting.
        // The ledger row does not store the line id, so match on invoice + account + amount.
        $invoiceIds = [];
        foreach ($entries as $row) {
            if ($row['reference_type'] === 'co_supplier_invoice' && (int)$row['reference_id'] > 0) {
                $invoiceIds[(int)$row['reference_id']] = true;
            }
        }
        $itemsByInvoice = [];
        if ($invoiceIds && co_db_table_exists($conn, 'co_supplier_invoice_items')) {
            $ids = array_keys($invoiceIds);
            $place = implode(',', array_fill(0, count($ids), '?'));
            $itemStmt = $conn->prepare("
                SELECT id, invoice_id, quantity, unit_price, subtotal
                FROM co_supplier_invoice_items
                WHERE company_id = ? AND expense_account_id = ? AND invoice_id IN ($place)
                ORDER BY id
            ");
            $itemStmt->execute(array_merge([$cid, $accountId], $ids));
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $itemsByInvoice[(int)$item['invoice_id']][] = $item;
            }
        }
        $usedItems = [];
        foreach ($entries as &$row) {
            $row['qty'] = null;
            $row['rate'] = null;
            $items = $itemsByInvoice[(int)$row['reference_id']] ?? [];
            if ($row['reference_type'] !== 'co_supplier_invoice' || !$items) {
                continue;
            }
            $isCredit = (float)$row['credit_amount'] > 0;
            $amount = $isCredit ? (float)$row['credit_amount'] : (float)$row['debit_amount'];
            // A reversal re-uses the same line, so track debit and credit sides separately.
            $journalSide = (int)$row['journal_id'] . ($isCredit ? 'C' : 'D');
            foreach ($items as $item) {
                $key = $journalSide . ':' . $item['id'];
                if (isset($usedItems[$key]) || abs((float)$item['subtotal'] - $amount) > 0.005) {
                    continue;
                }
                $usedItems[$key] = true;
                $row['qty'] = $isCredit ? -(float)$item['quantity'] : (float)$item['quantity'];
                $row['rate'] = (float)$item['unit_price'];
                break;
            }
        }
        unset($row);
        $periodQty = array_sum(array_map(fn($r) => (float)($r['qty'] ?? 0), $entries));

        $periodDebit = array_sum(array_map(fn($r) => (float)$r['debit_amount'], $entries));
        $periodCredit = array_sum(array_map(fn($r) => (float)$r['credit_amount'], $entries));
        $closing = $normal === 'debit' ? $opening + $periodDebit - $periodCredit : $opening + $periodCredit - $periodDebit;
    }
}

$exportRows = $entries;
if ($entries) {
    $exportRows[] = [
        'description' => 'Total',
        'qty' => $periodQty,
        'debit_amount' => $periodDebit,
        'credit_amount' => $periodCredit,
    ];
}
co_report_export($exportRows, [
    'entry_date' => 'Date',
    'journal_number' => 'Journal',
    'reference_type' => 'Reference Type',
    'description' => 'Description',
    'qty' => 'Qty',
    'rate' => 'Rate',
    'debit_amount' => 'Debit',
    'credit_amount' => 'Credit',
    'balance' => 'Stored Balance',
], 'construction_general_ledger_' . $dateFrom . '_' . $dateTo, 'Construction General Ledger');

$pageTitle = 'Construction General Ledger';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">General Ledger</h1><p class="text-muted mb-0">Account activity from <?= h($dateFrom) ?> to <?= h($dateTo) ?></p></div>
    <div class="d-flex gap-2"><?= $account ? co_report_export_buttons() : '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>' ?></div>
</div>
<form method="get" class="card card-round mb-4 no-print">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label">Account</label><select name="account_id" class="form-select"><option value="0">Select account</option><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $accountId === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['account_code'] . ' — ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>"></div>
        <div class="col-md-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
    </div>
</form>
<?php if (!$account): ?>
<div class="card card-round"><div class="card-body text-center text-muted py-4">Choose an account to view ledger activity.</div></div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card card-round"><div class="card-body"><div class="text-muted">Opening Balance</div><h4><?= co_format_money($opening) ?></h4></div></div></div>
    <div class="col-md-6"><div class="card card-round"><div class="card-body"><div class="text-muted">Closing Balance</div><h4><?= co_format_money($closing) ?></h4></div></div></div>
</div>
<div class="card card-round">
    <div class="gl-toolbar no-print">
        <div class="gl-search">
            <i class="bi bi-search gl-search-icon"></i>
            <input type="text" id="glSearch" placeholder="Search entries by supplier, journal, reference or amount" autocomplete="off" aria-label="Search ledger entries">
            <button type="button" id="glSearchClear" class="gl-search-clear" aria-label="Clear search" hidden><i class="bi bi-x-lg"></i></button>
        </div>
        <span class="gl-count" id="glSearchCount"><?= count($entries) ?> entries</span>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0 gl-table">
            <thead class="table-light"><tr><th>Date</th><th>Journal</th><th>Reference</th><th>Description</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody id="glRows">
            <?php foreach ($entries as $row): ?>
                <tr class="gl-row" data-debit="<?= (float)$row['debit_amount'] ?>" data-credit="<?= (float)$row['credit_amount'] ?>"<?= isset($row['qty']) ? ' data-qty="' . (float)$row['qty'] . '"' : '' ?>><td><?= h($row['entry_date']) ?></td><td><?php if (!empty($row['journal_id'])): ?><a href="../journal_entry_view.php?id=<?= (int)$row['journal_id'] ?>"><?= h($row['journal_number']) ?></a><?php else: ?><?= h($row['journal_number']) ?><?php endif; ?></td><td><?= h($row['reference_type'] . ' #' . $row['reference_id']) ?></td><td><?= h($row['description'] ?: $row['journal_description']) ?></td><td class="text-end"><?= $row['qty'] !== null ? number_format($row['qty'], 2) : '-' ?></td><td class="text-end"><?= $row['rate'] !== null ? co_format_money($row['rate']) : '-' ?></td><td class="text-end"><?= (float)$row['debit_amount'] ? co_format_money($row['debit_amount']) : '-' ?></td><td class="text-end"><?= (float)$row['credit_amount'] ? co_format_money($row['credit_amount']) : '-' ?></td></tr>
            <?php endforeach; ?>
            <?php if ($entries): ?><tr id="glNoMatch" style="display:none"><td colspan="8" class="text-center text-muted py-4">No entries match your search.</td></tr><?php endif; ?>
            <?php if (!$entries): ?><tr><td colspan="8" class="text-center text-muted py-4">No ledger activity in this period.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($entries): ?>
            <tfoot class="table-light fw-bold"><tr><td colspan="4" class="text-end">Total</td><td class="text-end" id="glTotalQty"><?= number_format($periodQty, 2) ?></td><td></td><td class="text-end" id="glTotalDebit"><?= co_format_money($periodDebit) ?></td><td class="text-end" id="glTotalCredit"><?= co_format_money($periodCredit) ?></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<style>
.gl-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 16px; border-bottom:1px solid #e9edf3; }
.gl-search { position:relative; flex:1 1 320px; max-width:460px; }
.gl-search-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px; pointer-events:none; transition:color .15s; }
.gl-search input { width:100%; height:40px; padding:0 38px 0 40px; border:1px solid #dbe2ea; border-radius:10px; background:#f8fafc; font-size:14px; color:#1e293b; outline:none; transition:border-color .15s, box-shadow .15s, background .15s; }
.gl-search input::placeholder { color:#94a3b8; }
.gl-search input:hover { border-color:#c5d0dc; }
.gl-search input:focus { background:#fff; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.gl-search:focus-within .gl-search-icon { color:#2563eb; }
.gl-search-clear { position:absolute; right:8px; top:50%; transform:translateY(-50%); width:24px; height:24px; display:flex; align-items:center; justify-content:center; border:0; border-radius:6px; background:transparent; color:#64748b; font-size:11px; cursor:pointer; }
.gl-search-clear:hover { background:#eef2f7; color:#1e293b; }
.gl-count { font-size:12.5px; font-weight:500; color:#475569; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:999px; padding:5px 12px; white-space:nowrap; }
.gl-count.is-filtered { color:#1d4ed8; background:#eff6ff; border-color:#bfdbfe; }
.gl-table td:nth-child(1), .gl-table td:nth-child(2), .gl-table th { white-space:nowrap; }
</style>
<script>
(function () {
    var input = document.getElementById('glSearch');
    var body = document.getElementById('glRows');
    if (!input || !body) return;
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr.gl-row'));
    var count = document.getElementById('glSearchCount');
    var empty = document.getElementById('glNoMatch');
    var clear = document.getElementById('glSearchClear');
    clear.addEventListener('click', function () { input.value = ''; input.dispatchEvent(new Event('input')); input.focus(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape' && input.value) { clear.click(); } });
    var money = function (n) { return 'AED ' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    var setText = function (id, text) { var el = document.getElementById(id); if (el) el.textContent = text; };
    input.addEventListener('input', function () {
        var terms = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
        var shown = 0, qty = 0, debit = 0, credit = 0;
        rows.forEach(function (tr) {
            var text = tr.textContent.toLowerCase().replace(/,/g, '');
            var match = terms.every(function (t) { return text.indexOf(t.replace(/,/g, '')) !== -1; });
            tr.style.display = match ? '' : 'none';
            if (!match) return;
            shown++;
            qty += parseFloat(tr.dataset.qty) || 0;
            debit += parseFloat(tr.dataset.debit) || 0;
            credit += parseFloat(tr.dataset.credit) || 0;
        });
        if (empty) empty.style.display = shown ? 'none' : '';
        count.textContent = terms.length ? shown + ' of ' + rows.length + ' entries' : rows.length + ' entries';
        count.classList.toggle('is-filtered', terms.length > 0);
        clear.hidden = !input.value;
        setText('glTotalQty', qty.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        setText('glTotalDebit', money(debit));
        setText('glTotalCredit', money(credit));
    });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
