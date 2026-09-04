<?php
/**
 * Construction — link bank accounts to Chart of Accounts (re_bank_accounts).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_bank_reconciliation.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
co_bank_reco_require_permission($conn, 'construction.bank_reconciliation.view');

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['add_bank'])) {
        $glId = (int) ($_POST['gl_account_id'] ?? 0);
        $accountName = trim((string) ($_POST['account_name'] ?? ''));
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
        $iban = trim((string) ($_POST['iban'] ?? ''));
        $swiftCode = trim((string) ($_POST['swift_code'] ?? ''));
        if ($glId <= 0 || $accountName === '') {
            $err = 'Select a GL account and enter an account name.';
        } else {
            try {
                $st = $conn->prepare("
                    SELECT id, account_code, account_name FROM re_chart_of_accounts
                    WHERE id = ? AND company_id = ? AND is_active = 1
                      AND (account_code LIKE '11%' OR account_code LIKE '12%')
                    LIMIT 1
                ");
                $st->execute([$glId, $cid]);
                $coa = $st->fetch(PDO::FETCH_ASSOC);
                if (!$coa) {
                    $err = 'Invalid or inactive bank/cash GL account (1100–1299).';
                } else {
                    $ins = $conn->prepare("
                        INSERT INTO re_bank_accounts
                          (company_id, account_name, bank_name, account_number, iban, swift_code, currency, gl_account_id, opening_balance, current_balance, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, 'AED', ?, 0, 0, 1)
                    ");
                    $ins->execute([
                        $cid,
                        mb_substr($accountName, 0, 255),
                        $bankName !== '' ? mb_substr($bankName, 0, 255) : null,
                        $accountNumber !== '' ? mb_substr($accountNumber, 0, 100) : null,
                        $iban !== '' ? mb_substr($iban, 0, 50) : null,
                        $swiftCode !== '' ? mb_substr($swiftCode, 0, 20) : null,
                        $glId,
                    ]);
                    $msg = 'Bank account linked.';
                }
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
        }
    } elseif (isset($_POST['toggle_id'])) {
        $tid = (int) $_POST['toggle_id'];
        $conn->prepare('UPDATE re_bank_accounts SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ? AND company_id = ?')->execute([$tid, $cid]);
        $msg = 'Updated.';
    } elseif (isset($_POST['save_bank'])) {
        $bid = (int) ($_POST['bank_id'] ?? 0);
        if ($bid <= 0) {
            $err = 'Invalid bank account.';
        } else {
            $st = $conn->prepare("
                UPDATE re_bank_accounts
                SET account_name = ?, bank_name = ?, account_number = ?, iban = ?, swift_code = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $st->execute([
                mb_substr(trim((string) ($_POST['account_name'] ?? '')), 0, 255),
                ($v = trim((string) ($_POST['bank_name'] ?? ''))) !== '' ? mb_substr($v, 0, 255) : null,
                ($v = trim((string) ($_POST['account_number'] ?? ''))) !== '' ? mb_substr($v, 0, 100) : null,
                ($v = trim((string) ($_POST['iban'] ?? ''))) !== '' ? mb_substr($v, 0, 50) : null,
                ($v = trim((string) ($_POST['swift_code'] ?? ''))) !== '' ? mb_substr($v, 0, 20) : null,
                $bid,
                $cid,
            ]);
            $msg = 'Bank details updated.';
        }
    }
}

$coa_opts = $conn->prepare("
    SELECT id, account_code, account_name FROM re_chart_of_accounts
    WHERE company_id = ? AND is_active = 1
      AND (account_code LIKE '11%' OR account_code LIKE '12%')
    ORDER BY account_code
");
$coa_opts->execute([$cid]);
$coa_opts = $coa_opts->fetchAll(PDO::FETCH_ASSOC) ?: [];

$banks = $conn->prepare("
    SELECT ba.*, coa.account_code, coa.account_name AS coa_name
    FROM re_bank_accounts ba
    JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id AND coa.company_id = ba.company_id
    WHERE ba.company_id = ?
    ORDER BY coa.account_code
");
$banks->execute([$cid]);
$banks = $banks->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dashboardRows = [];
$asOf = date('Y-m-d');
if (co_bank_reco_tables_ready($conn)) {
    foreach ($banks as $b) {
        if (!(int) $b['is_active']) {
            continue;
        }
        $bid = (int) $b['id'];
        $glId = (int) $b['gl_account_id'];
        $erpBal = co_bank_erp_balance($conn, $glId, $cid, $asOf);
        $stmtBal = co_bank_statement_balance($conn, $bid, $cid, $asOf);
        $diff = $stmtBal !== null ? co_bank_reco_money($erpBal - $stmtBal) : null;

        $unrec = 0;
        $suggested = 0;
        $st = $conn->prepare('
            SELECT l.amount,
              (SELECT COALESCE(SUM(m2.amount_matched),0) FROM co_reconciliation_matches m2
               WHERE m2.bank_statement_line_id = l.id AND m2.status IN (\'proposed\',\'confirmed\')) AS matched_sum
            FROM co_bank_statement_lines l
            WHERE l.bank_account_id = ? AND l.company_id = ?
        ');
        $st->execute([$bid, $cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (max(0, round(abs((float) $row['amount']) - (float) $row['matched_sum'], 2)) > 0.009) {
                $unrec++;
            }
        }
        $st = $conn->prepare("
            SELECT COUNT(DISTINCT l.id) FROM co_bank_statement_lines l
            JOIN co_reconciliation_matches m ON m.bank_statement_line_id = l.id AND m.status = 'proposed'
            WHERE l.bank_account_id = ? AND l.company_id = ?
        ");
        $st->execute([$bid, $cid]);
        $suggested = (int) $st->fetchColumn();

        $lastImport = null;
        $st = $conn->prepare('SELECT MAX(imported_at) FROM co_bank_import_batches WHERE bank_account_id = ? AND company_id = ?');
        $st->execute([$bid, $cid]);
        $lastImport = $st->fetchColumn() ?: null;

        $lastReco = null;
        $st = $conn->prepare("
            SELECT MAX(m.confirmed_at) FROM co_reconciliation_matches m
            JOIN co_bank_statement_lines l ON l.id = m.bank_statement_line_id
            WHERE l.bank_account_id = ? AND l.company_id = ? AND m.status = 'confirmed'
        ");
        $st->execute([$bid, $cid]);
        $lastReco = $st->fetchColumn() ?: null;

        if ($stmtBal === null) {
            $status = 'No Statement Imported';
            $badge = 'secondary';
        } elseif ($diff !== null && abs($diff) >= 0.02) {
            $status = 'Difference Found';
            $badge = 'warning text-dark';
        } elseif ($unrec > 0) {
            $status = 'Needs Review';
            $badge = 'warning text-dark';
        } else {
            $status = 'Reconciled';
            $badge = 'success';
        }

        $dashboardRows[] = array_merge($b, [
            'erp_balance' => $erpBal,
            'statement_balance' => $stmtBal,
            'difference' => $diff,
            'unreconciled_count' => $unrec,
            'suggested_count' => $suggested,
            'last_import_at' => $lastImport,
            'last_reconciled_at' => $lastReco,
            'status_label' => $status,
            'status_badge' => $badge,
        ]);
    }
}

$pageTitle = 'Bank Reconciliation Dashboard';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260712-contrast" rel="stylesheet">';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="flex-grow-1">
    <div class="text-uppercase small text-muted">Construction</div>
    <h3 class="mb-0">Bank accounts dashboard</h3>
    <div class="text-muted">Overview of construction bank/cash accounts, balances, and reconciliation status.</div>
  </div>
  <div class="d-flex gap-2">
    <a href="bank_reconciliation_import.php" class="btn btn-outline-primary btn-sm">Import statement</a>
    <a href="bank_reconciliation_rules.php" class="btn btn-outline-secondary btn-sm">Bank rules</a>
    <a href="bank_reconciliation_settings.php" class="btn btn-outline-secondary btn-sm">Settings</a>
    <a href="bank_reconciliation_cash_coding.php" class="btn btn-outline-secondary btn-sm">Cash coding</a>
    <a href="reports/bank_reconciliation_report.php" class="btn btn-outline-secondary btn-sm">Report</a>
    <a href="chart_of_accounts.php" class="btn btn-outline-secondary btn-sm">Chart of Accounts</a>
  </div>
</div>

<?php if ($dashboardRows): ?>
<div class="row g-3 mb-4">
  <?php foreach ($dashboardRows as $d): ?>
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <div>
            <div class="fw-bold"><?= h($d['account_code'] . ' — ' . $d['account_name']) ?></div>
            <div class="small text-muted"><?= h($d['coa_name']) ?> · <?= h($d['currency'] ?? 'AED') ?></div>
          </div>
          <span class="badge bg-<?= h($d['status_badge']) ?>"><?= h($d['status_label']) ?></span>
        </div>
        <div class="row g-2 small mb-3">
          <div class="col-6"><span class="text-muted">ERP balance</span><div class="fw-semibold"><?= number_format((float) $d['erp_balance'], 2) ?></div></div>
          <div class="col-6"><span class="text-muted">Statement balance</span><div class="fw-semibold"><?= $d['statement_balance'] !== null ? number_format((float) $d['statement_balance'], 2) : '—' ?></div></div>
          <div class="col-6"><span class="text-muted">Difference</span><div class="fw-semibold"><?= $d['difference'] !== null ? number_format((float) $d['difference'], 2) : '—' ?></div></div>
          <div class="col-6"><span class="text-muted">Unreconciled / Suggested</span><div class="fw-semibold"><?= (int) $d['unreconciled_count'] ?> / <?= (int) $d['suggested_count'] ?></div></div>
          <div class="col-6"><span class="text-muted">Last import</span><div><?= $d['last_import_at'] ? h(substr((string) $d['last_import_at'], 0, 10)) : '—' ?></div></div>
          <div class="col-6"><span class="text-muted">Last reconciliation</span><div><?= $d['last_reconciled_at'] ? h(substr((string) $d['last_reconciled_at'], 0, 10)) : '—' ?></div></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a href="bank_reconciliation.php?bank_account_id=<?= (int) $d['id'] ?>" class="btn btn-primary btn-sm">Reconcile<?= $d['unreconciled_count'] ? ' (' . (int) $d['unreconciled_count'] . ')' : '' ?></a>
          <a href="bank_reconciliation_import.php?bank_account_id=<?= (int) $d['id'] ?>" class="btn btn-outline-primary btn-sm">Import</a>
          <a href="reports/general_ledger.php?account_id=<?= (int) $d['gl_account_id'] ?>" class="btn btn-outline-secondary btn-sm">Transactions</a>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php elseif (co_bank_reco_tables_ready($conn)): ?>
  <div class="alert alert-info mb-4">No active bank accounts yet. Add one below.</div>
<?php endif; ?>

<h5 class="mb-3">Manage bank accounts</h5>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <h5 class="card-title">Add bank account</h5>
    <form method="post" class="row g-2 align-items-end">
      <?php csrf_field(); ?>
      <input type="hidden" name="add_bank" value="1">
      <div class="col-md-4">
        <label class="form-label">GL account (1100–1299)</label>
        <select name="gl_account_id" class="form-select" required>
          <option value="">— Select —</option>
          <?php foreach ($coa_opts as $o): ?>
            <option value="<?= (int) $o['id'] ?>"><?= h($o['account_code'] . ' — ' . $o['account_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Account name</label>
        <input type="text" name="account_name" class="form-control" required placeholder="e.g. ENBD Current Account">
      </div>
      <div class="col-md-2">
        <label class="form-label">Bank name</label>
        <input type="text" name="bank_name" class="form-control" placeholder="Bank name">
      </div>
      <div class="col-md-2">
        <label class="form-label">Account number</label>
        <input type="text" name="account_number" class="form-control">
      </div>
      <div class="col-md-1">
        <button type="submit" class="btn btn-primary w-100">Add</button>
      </div>
      <div class="col-md-3">
        <label class="form-label">IBAN</label>
        <input type="text" name="iban" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">SWIFT/BIC</label>
        <input type="text" name="swift_code" class="form-control">
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>GL code</th>
          <th>Name</th>
          <th>Bank details</th>
          <th>Active</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($banks as $b): ?>
          <tr>
            <td><?= h($b['account_code']) ?></td>
            <td><?= h($b['account_name']) ?><div class="small text-muted"><?= h($b['coa_name']) ?></div></td>
            <td>
              <div class="small"><strong>Bank:</strong> <?= h($b['bank_name'] ?? '—') ?></div>
              <div class="small"><strong>A/C #:</strong> <?= h($b['account_number'] ?? '—') ?></div>
              <div class="small"><strong>IBAN:</strong> <?= h($b['iban'] ?? '—') ?></div>
              <div class="small"><strong>SWIFT:</strong> <?= h($b['swift_code'] ?? '—') ?></div>
            </td>
            <td><?= ((int) $b['is_active']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
            <td class="text-end">
              <form method="post" class="d-inline"><?php csrf_field(); ?>
                <input type="hidden" name="toggle_id" value="<?= (int) $b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Toggle active</button>
              </form>
              <button type="button" class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#editModal"
                      data-id="<?= (int) $b['id'] ?>"
                      data-name="<?= h($b['account_name']) ?>"
                      data-bank_name="<?= h((string) ($b['bank_name'] ?? '')) ?>"
                      data-account_number="<?= h((string) ($b['account_number'] ?? '')) ?>"
                      data-iban="<?= h((string) ($b['iban'] ?? '')) ?>"
                      data-swift_code="<?= h((string) ($b['swift_code'] ?? '')) ?>">
                Edit details
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$banks): ?>
          <tr><td colspan="5" class="text-muted p-4">No bank accounts yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?php csrf_field(); ?>
      <input type="hidden" name="save_bank" value="1">
      <input type="hidden" name="bank_id" id="e_id">
      <div class="modal-header">
        <h5 class="modal-title">Edit bank details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Account name</label><input type="text" class="form-control" name="account_name" id="e_name" required></div>
        <div class="mb-2"><label class="form-label">Bank name</label><input type="text" class="form-control" name="bank_name" id="e_bank_name"></div>
        <div class="mb-2"><label class="form-label">Account number</label><input type="text" class="form-control" name="account_number" id="e_account_number"></div>
        <div class="mb-2"><label class="form-label">IBAN</label><input type="text" class="form-control" name="iban" id="e_iban"></div>
        <div class="mb-2"><label class="form-label">SWIFT/BIC</label><input type="text" class="form-control" name="swift_code" id="e_swift_code"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function (event) {
  var b = event.relatedTarget;
  if (!b) return;
  document.getElementById('e_id').value = b.getAttribute('data-id') || '';
  document.getElementById('e_name').value = b.getAttribute('data-name') || '';
  document.getElementById('e_bank_name').value = b.getAttribute('data-bank_name') || '';
  document.getElementById('e_account_number').value = b.getAttribute('data-account_number') || '';
  document.getElementById('e_iban').value = b.getAttribute('data-iban') || '';
  document.getElementById('e_swift_code').value = b.getAttribute('data-swift_code') || '';
});
</script>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
