<?php
/**
 * Link cleaning bank reconciliation to Chart of Accounts (Asset / bank cash accounts).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$msg = '';
$err = '';
$hasBankMeta = false;

try {
    $chk = $conn->query("SHOW COLUMNS FROM cleaning_bank_accounts LIKE 'iban'");
    $hasBankMeta = (bool) $chk->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $hasBankMeta = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['add_bank'])) {
        $cid = (int) ($_POST['chart_account_id'] ?? 0);
        $label = trim((string) ($_POST['display_name'] ?? ''));
        $bank_name = trim((string) ($_POST['bank_name'] ?? ''));
        $account_name = trim((string) ($_POST['account_name'] ?? ''));
        $account_number = trim((string) ($_POST['account_number'] ?? ''));
        $iban = trim((string) ($_POST['iban'] ?? ''));
        $swift_bic = trim((string) ($_POST['swift_bic'] ?? ''));
        $branch_name = trim((string) ($_POST['branch_name'] ?? ''));
        if ($cid <= 0) {
            $err = 'Select a chart account.';
        } else {
            try {
                $st = $conn->prepare("
                    SELECT id, account_no, name FROM chart_of_accounts
                    WHERE id = ? AND type = 'Asset' AND is_header = 0 AND is_active = 1
                    LIMIT 1
                ");
                $st->execute([$cid]);
                $coa = $st->fetch(PDO::FETCH_ASSOC);
                if (!$coa) {
                    $err = 'Invalid or inactive asset account.';
                } else {
                    $nm = $label !== '' ? mb_substr($label, 0, 120) : ($coa['name'] . ' (' . $coa['account_no'] . ')');
                    if ($hasBankMeta) {
                        $ins = $conn->prepare("
                            INSERT INTO cleaning_bank_accounts
                              (chart_account_id, name, bank_name, account_name, account_number, iban, swift_bic, branch_name, currency, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'AED', 1)
                        ");
                        $ins->execute([
                            $cid,
                            $nm,
                            $bank_name !== '' ? mb_substr($bank_name, 0, 150) : null,
                            $account_name !== '' ? mb_substr($account_name, 0, 150) : null,
                            $account_number !== '' ? mb_substr($account_number, 0, 80) : null,
                            $iban !== '' ? mb_substr($iban, 0, 80) : null,
                            $swift_bic !== '' ? mb_substr($swift_bic, 0, 20) : null,
                            $branch_name !== '' ? mb_substr($branch_name, 0, 120) : null,
                        ]);
                    } else {
                        $ins = $conn->prepare('INSERT INTO cleaning_bank_accounts (chart_account_id, name, currency, is_active) VALUES (?, ?, \'AED\', 1)');
                        $ins->execute([$cid, $nm]);
                    }
                    $msg = 'Bank account linked.';
                }
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'uk_cleaning_bank_coa') !== false) {
                    $err = 'That chart account is already linked.';
                } else {
                    $err = $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['toggle_id'])) {
        $tid = (int) $_POST['toggle_id'];
        $conn->prepare('UPDATE cleaning_bank_accounts SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$tid]);
        $msg = 'Updated.';
    } elseif (isset($_POST['save_bank']) && $hasBankMeta) {
        $bid = (int) ($_POST['bank_id'] ?? 0);
        if ($bid <= 0) {
            $err = 'Invalid bank account.';
        } else {
            $st = $conn->prepare("
                UPDATE cleaning_bank_accounts
                SET
                  name = ?,
                  bank_name = ?,
                  account_name = ?,
                  account_number = ?,
                  iban = ?,
                  swift_bic = ?,
                  branch_name = ?,
                  updated_at = NOW()
                WHERE id = ?
            ");
            $st->execute([
                mb_substr(trim((string) ($_POST['display_name'] ?? '')), 0, 120),
                ($v = trim((string) ($_POST['bank_name'] ?? ''))) !== '' ? mb_substr($v, 0, 150) : null,
                ($v = trim((string) ($_POST['account_name'] ?? ''))) !== '' ? mb_substr($v, 0, 150) : null,
                ($v = trim((string) ($_POST['account_number'] ?? ''))) !== '' ? mb_substr($v, 0, 80) : null,
                ($v = trim((string) ($_POST['iban'] ?? ''))) !== '' ? mb_substr($v, 0, 80) : null,
                ($v = trim((string) ($_POST['swift_bic'] ?? ''))) !== '' ? mb_substr($v, 0, 20) : null,
                ($v = trim((string) ($_POST['branch_name'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
                $bid
            ]);
            $msg = 'Bank details updated.';
        }
    }
}

$coa_opts = $conn->query("
    SELECT id, account_no, name FROM chart_of_accounts
    WHERE type = 'Asset' AND is_header = 0 AND is_active = 1 AND account_no <> '1110'
    ORDER BY account_no
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$banks = $conn->query("
    SELECT b.*, c.account_no, c.name AS coa_name
    FROM cleaning_bank_accounts b
    JOIN chart_of_accounts c ON c.id = b.chart_account_id
    ORDER BY c.account_no
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Bank reconciliation — accounts</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>body{background:#f6f7f9}</style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex align-items-center mb-3">
    <div>
      <div class="text-uppercase small text-muted">Cleaning</div>
      <h3 class="mb-0">Bank reconciliation accounts</h3>
      <div class="text-muted">Map chart-of-accounts bank accounts and keep bank master details (IBAN, SWIFT, account info).</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="bank_reconciliation.php" class="btn btn-primary"><i class="bi bi-bank"></i> Reconciliation</a>
      <a href="reports.php" class="btn btn-outline-secondary">Reports</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h5 class="card-title">Add bank account</h5>
      <form method="post" class="row g-2 align-items-end">
        <?php csrf_field(); ?>
        <input type="hidden" name="add_bank" value="1">
        <div class="col-md-6">
          <label class="form-label">Chart account</label>
          <select name="chart_account_id" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($coa_opts as $o): ?>
              <option value="<?= (int) $o['id'] ?>"><?= h($o['account_no'] . ' — ' . $o['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Display name (optional)</label>
          <input type="text" name="display_name" class="form-control" placeholder="Defaults to COA name + code">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary w-100">Add</button>
        </div>
        <?php if ($hasBankMeta): ?>
        <div class="col-md-4">
          <label class="form-label">Bank name (optional)</label>
          <input type="text" name="bank_name" class="form-control" placeholder="Bank name">
        </div>
        <div class="col-md-4">
          <label class="form-label">Account name (optional)</label>
          <input type="text" name="account_name" class="form-control" placeholder="Account holder name">
        </div>
        <div class="col-md-4">
          <label class="form-label">Account number (optional)</label>
          <input type="text" name="account_number" class="form-control" placeholder="Account number">
        </div>
        <div class="col-md-4">
          <label class="form-label">IBAN (optional)</label>
          <input type="text" name="iban" class="form-control" placeholder="IBAN">
        </div>
        <div class="col-md-2">
          <label class="form-label">SWIFT/BIC</label>
          <input type="text" name="swift_bic" class="form-control" placeholder="SWIFT">
        </div>
        <div class="col-md-2">
          <label class="form-label">Branch</label>
          <input type="text" name="branch_name" class="form-control" placeholder="Branch">
        </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <?php if (!$hasBankMeta): ?>
    <div class="alert alert-warning">
      Run migration <code>migrations/cleaning_phase3_bank_account_details.sql</code> to enable IBAN/SWIFT/bank master fields.
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>COA #</th>
            <th>Name</th>
            <th>Bank details</th>
            <th>Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($banks as $b): ?>
            <tr>
              <td><?= h($b['account_no']) ?></td>
              <td><?= h($b['name']) ?><div class="small text-muted"><?= h($b['coa_name']) ?></div></td>
              <td>
                <?php if ($hasBankMeta): ?>
                  <div class="small"><strong>Bank:</strong> <?= h($b['bank_name'] ?? '—') ?></div>
                  <div class="small"><strong>A/C name:</strong> <?= h($b['account_name'] ?? '—') ?></div>
                  <div class="small"><strong>A/C #:</strong> <?= h($b['account_number'] ?? '—') ?></div>
                  <div class="small"><strong>IBAN:</strong> <?= h($b['iban'] ?? '—') ?></div>
                  <div class="small"><strong>SWIFT:</strong> <?= h($b['swift_bic'] ?? '—') ?></div>
                  <div class="small"><strong>Branch:</strong> <?= h($b['branch_name'] ?? '—') ?></div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= ((int) $b['is_active']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
              <td class="text-end">
                <form method="post" class="d-inline"><?php csrf_field(); ?>
                  <input type="hidden" name="toggle_id" value="<?= (int) $b['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary">Toggle active</button>
                </form>
                <?php if ($hasBankMeta): ?>
                <button type="button"
                        class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#editModal"
                        data-id="<?= (int) $b['id'] ?>"
                        data-name="<?= h($b['name']) ?>"
                        data-bank_name="<?= h((string) ($b['bank_name'] ?? '')) ?>"
                        data-account_name="<?= h((string) ($b['account_name'] ?? '')) ?>"
                        data-account_number="<?= h((string) ($b['account_number'] ?? '')) ?>"
                        data-iban="<?= h((string) ($b['iban'] ?? '')) ?>"
                        data-swift_bic="<?= h((string) ($b['swift_bic'] ?? '')) ?>"
                        data-branch_name="<?= h((string) ($b['branch_name'] ?? '')) ?>">
                  Edit details
                </button>
                <?php endif; ?>
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
</div>

<?php if ($hasBankMeta): ?>
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
        <div class="mb-2">
          <label class="form-label">Display name</label>
          <input type="text" class="form-control" name="display_name" id="e_name" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Bank name</label>
          <input type="text" class="form-control" name="bank_name" id="e_bank_name">
        </div>
        <div class="mb-2">
          <label class="form-label">Account name</label>
          <input type="text" class="form-control" name="account_name" id="e_account_name">
        </div>
        <div class="mb-2">
          <label class="form-label">Account number</label>
          <input type="text" class="form-control" name="account_number" id="e_account_number">
        </div>
        <div class="mb-2">
          <label class="form-label">IBAN</label>
          <input type="text" class="form-control" name="iban" id="e_iban">
        </div>
        <div class="row g-2">
          <div class="col-md-5">
            <label class="form-label">SWIFT/BIC</label>
            <input type="text" class="form-control" name="swift_bic" id="e_swift_bic">
          </div>
          <div class="col-md-7">
            <label class="form-label">Branch</label>
            <input type="text" class="form-control" name="branch_name" id="e_branch_name">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function (event) {
  var b = event.relatedTarget;
  if (!b) return;
  document.getElementById('e_id').value = b.getAttribute('data-id') || '';
  document.getElementById('e_name').value = b.getAttribute('data-name') || '';
  document.getElementById('e_bank_name').value = b.getAttribute('data-bank_name') || '';
  document.getElementById('e_account_name').value = b.getAttribute('data-account_name') || '';
  document.getElementById('e_account_number').value = b.getAttribute('data-account_number') || '';
  document.getElementById('e_iban').value = b.getAttribute('data-iban') || '';
  document.getElementById('e_swift_bic').value = b.getAttribute('data-swift_bic') || '';
  document.getElementById('e_branch_name').value = b.getAttribute('data-branch_name') || '';
});
</script>
<?php endif; ?>
</body>
</html>
