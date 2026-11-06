<?php
/**
 * Finance > Settings: the accounts a company's supplier invoices and supplier payments post to.
 * Chosen once per company by Accounts; nothing is booked to them until saved.
 */
require_once __DIR__ . '/../includes/fin_context.php';
require_once __DIR__ . '/../includes/fin_supplier_invoice_helper.php';

$tableOk = fin_coa_table_ready($conn) && fin_si_ready($conn);
$msg = $_GET['msg'] ?? '';
$err = '';

if ($tableOk && $company && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        fin_settings_save($conn, $companyId, array_intersect_key((array)($_POST['setting'] ?? []), FIN_SETTINGS), $userId);
        header('Location: fin_settings.php?msg=' . urlencode('Settings saved.'));
        exit;
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('fin_settings: ' . $e->getMessage());
        $err = 'The settings could not be saved.';
    }
}

$accounts = ($tableOk && $company) ? fin_voucher_ledger_accounts($conn, $companyId) : [];
$saved = ($tableOk && $company) ? fin_settings_get($conn, $companyId) : [];
// Until a setting is saved, the form proposes the account whose name fits; Save keeps it.
$suggest = ['si_vat_input' => '/vat input/i', 'si_payable' => '/sundry creditors|accounts payable/i', 'sp_advance' => '/advance (paid )?to suppliers?/i'];
$shown = [];
foreach (FIN_SETTINGS as $key => $label) {
    $shown[$key] = $saved[$key] ?? null;
    if ($shown[$key] === null) {
        foreach ($accounts as $a) {
            if (preg_match($suggest[$key], $a['name'])) {
                $shown[$key] = (int)$a['id'];
                break;
            }
        }
    }
}

$pageTitle = 'Finance Settings';
$finActive = 'settings';
require __DIR__ . '/../includes/fin_layout_header.php';
?>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$tableOk): ?>
    <div class="alert alert-warning">Run migrations/fin_supplier_invoices.sql first.</div>
  <?php elseif (!$company): ?>
    <div class="alert alert-warning">Select a company to see its settings.</div>
  <?php else: ?>
  <form method="post" class="card" style="max-width: 760px">
    <?php csrf_field(); ?>
    <div class="card-body">
      <?php foreach (FIN_SETTINGS as $key => $label): ?>
      <div class="mb-3">
        <label class="form-label small mb-1" for="set_<?= h($key) ?>"><?= h($label) ?></label>
        <select class="form-select" id="set_<?= h($key) ?>" name="setting[<?= h($key) ?>]">
          <option value="0">Not chosen</option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)$shown[$key] ? 'selected' : '' ?>><?= h($a['code'] . ' ' . $a['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($saved[$key] === null): ?><div class="form-text text-warning">Not saved yet<?= $shown[$key] ? ': the account shown is a suggestion' : '' ?>.</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <button class="btn btn-primary">Save</button>
    </div>
  </form>
  <?php endif; ?>
<?php require __DIR__ . '/../includes/fin_layout_footer.php'; ?>
</body>
</html>
