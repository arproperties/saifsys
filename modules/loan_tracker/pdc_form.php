<?php
/**
 * Company's post-dated cheques — add a cheque, or change one (?id=): details, copy of the
 * cheque, its status history, delete. Owner, Admin and HR. The cheque is kept in Reem; this
 * page shows it and passes every change on (see includes/lt_pdc.php).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_pdc.php';

$who = lt_pdc_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$dirs = lt_pdc_directions();
$statuses = lt_pdc_statuses();

$id = (int)($_GET['id'] ?? 0);
$pdc = null;
$history = [];
$customs = [];
try {
    if ($id) {
        $one = lt_pdc_reem($who, 'GET', '/cheques/' . $id);
        $pdc = (array)$one['cheque'];
        $history = (array)($one['history'] ?? []);
        $customs = (array)($one['customs'] ?? []);
    } else {
        $customs = (array)(lt_pdc_reem($who, 'GET', '/customs')['customs'] ?? []);
    }
} catch (BinvError $e) {
    if ($e->status !== 404) {
        lt_pdc_stop($e);
    }
    lt_flash('That cheque was not found.', 'warning');
    header('Location: ' . $ltBase . '/pdc.php');
    exit;
}

$fields = ['direction', 'party_name', 'cheque_no', 'bank', 'cheque_date', 'amount', 'purpose', 'location', 'reference', 'approved_by', 'issued_by', 'status', 'notes'];
$v = [];
foreach ($fields as $k) {
    $v[$k] = $pdc ? (string)($pdc[$k] ?? '') : '';
}
if ($pdc) {
    $v['amount'] = number_format((float)$pdc['amount'], 2, '.', '');
} else {
    $v['direction'] = isset($dirs[$_GET['dir'] ?? '']) ? $_GET['dir'] : 'issued';
    $v['status'] = 'pending';
    $v['approved_by'] = lt_user_name($conn, current_user_id()); // the person adding it; can be changed
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ($pdc && ($_POST['do'] ?? '') === 'delete') {
        try {
            lt_pdc_reem($who, 'DELETE', '/cheques/' . $id);
            lt_flash('Cheque ' . $pdc['cheque_no'] . ' (' . $pdc['party_name'] . ') is deleted.');
        } catch (BinvError $e) {
            lt_flash($e->getMessage(), 'danger');
        }
        header('Location: ' . $ltBase . '/pdc.php');
        exit;
    }
    foreach ($fields as $k) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $file = $_FILES['file'] ?? null;
    try {
        // Reem checks the cheque and says everything that is wrong with it in one answer.
        $send = $v + ['status_other' => (string)($_POST['status_other'] ?? ''), 'company_name' => $who['company_name']];
        $saved = $pdc
            ? lt_pdc_reem($who, 'PUT', '/cheques/' . $id, $send)
            : lt_pdc_reem($who, 'POST', '/cheques', $send);
        $said = $pdc ? 'Cheque ' . $saved['cheque_no'] . ' saved.' : 'Cheque ' . $saved['cheque_no'] . ' added.';
        // The copy comes second, and its failure is said out loud: the cheque is saved either way.
        $fileError = lt_pdc_send_file($who, (int)$saved['id'], $file);
        lt_flash($fileError !== null ? $said . ' But its copy was not kept: ' . $fileError : $said, $fileError !== null ? 'warning' : 'success');
        header('Location: ' . $ltBase . '/pdc.php' . ($pdc ? '?status=all' : ''));
        exit;
    } catch (BinvError $e) {
        // Reem says everything that is wrong in one answer, a sentence each: one line per sentence here.
        $errors = preg_split('/(?<=\.)\s+(?=Please )/', $e->getMessage()) ?: [$e->getMessage()];
    }
}

$pageTitle = $pdc ? 'Change cheque' : 'Add cheque';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4" style="max-width:960px">
  <div>
    <h1 class="lt-title"><?= $pdc ? 'Change cheque' : "Add a Company's Post Dated Cheque" ?></h1>
    <p class="lt-sub"><?= $pdc ? h($pdc['cheque_no'] . ' · ' . $pdc['party_name']) . ' · ' . lt_pdc_badge((string)$pdc['status'], $pdc['status_other'] ?? null) : 'A cheque the company gives, or a cheque given to the company.' ?></p>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger" style="max-width:960px">
    <div class="fw-semibold mb-1">Please check the following:</div>
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" style="max-width:960px" id="pdcForm">
  <?php csrf_field(); ?>
  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="row g-3">
      <div class="col-12">
        <div class="btn-group" role="group">
          <?php foreach ($dirs as $k => $d): ?>
            <input type="radio" class="btn-check" name="direction" id="dir_<?= h($k) ?>" value="<?= h($k) ?>" <?= $v['direction'] === $k ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="dir_<?= h($k) ?>"><i class="bi <?= h($d['icon']) ?> me-1"></i><?= h($d['long']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="party_name"><span id="partyLbl"><?= h($dirs[$v['direction']]['party'] ?? 'Party') ?></span> <span class="lt-req">*</span></label>
        <input type="text" class="form-control" id="party_name" name="party_name" maxlength="200" value="<?= h($v['party_name']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="cheque_no">Cheque no. <span class="lt-req">*</span></label>
        <input type="text" class="form-control" id="cheque_no" name="cheque_no" maxlength="50" value="<?= h($v['cheque_no']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="bank">Bank</label>
        <input type="text" class="form-control" id="bank" name="bank" maxlength="120" value="<?= h($v['bank']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="cheque_date">Cheque date <span class="lt-req">*</span></label>
        <input type="date" class="form-control" id="cheque_date" name="cheque_date" value="<?= h($v['cheque_date']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="amount">Amount (AED) <span class="lt-req">*</span></label>
        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" value="<?= h($v['amount']) ?>" required>
      </div>
      <?php if (!$pdc): ?>
        <div class="col-md-3">
          <label class="form-label small fw-semibold" for="status">Status</label>
          <select class="form-select" id="status" name="status">
            <?= lt_pdc_status_options($customs, str_starts_with($v['status'], 'custom:') ? 'other' : $v['status'], str_starts_with($v['status'], 'custom:') ? substr($v['status'], 7) : null) ?>
          </select>
          <input type="text" class="form-control mt-2" name="status_other" id="statusOther" maxlength="60" placeholder="Type the status" value="<?= h((string)($_POST['status_other'] ?? '')) ?>" hidden>
        </div>
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="reference">Reference</label>
        <input type="text" class="form-control" id="reference" name="reference" maxlength="100" value="<?= h($v['reference']) ?>" placeholder="Invoice, contract…">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="approved_by">Approved by</label>
        <input type="text" class="form-control" id="approved_by" name="approved_by" maxlength="150" value="<?= h($v['approved_by']) ?>">
        <?php if (!$pdc): ?><div class="form-text">Filled with your name. Change it if someone else approved the cheque.</div><?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="issued_by">Issued by</label>
        <input type="text" class="form-control" id="issued_by" name="issued_by" maxlength="150" value="<?= h($v['issued_by']) ?>" placeholder="Who signed / gave the cheque">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="purpose">Reason</label>
        <input type="text" class="form-control" id="purpose" name="purpose" maxlength="255" value="<?= h($v['purpose']) ?>" placeholder="What the cheque is for">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="location">Location</label>
        <input type="text" class="form-control" id="location" name="location" maxlength="150" value="<?= h($v['location']) ?>" placeholder="Building, office, site…">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="file">Cheque copy</label>
        <?php if ($pdc && !empty($pdc['attachment'])): ?>
          <div class="small mb-1"><i class="bi bi-paperclip"></i> <a target="_blank" rel="noopener" href="<?= h($ltBase) ?>/pdc.php?file=<?= $id ?>"><?= h(($pdc['original_name'] ?? '') ?: 'Attached') ?></a> <span class="text-muted">(a new file replaces it)</span></div>
        <?php endif; ?>
        <input type="file" class="form-control" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp">
        <div class="form-text">A picture or a PDF, 8 MB at most.</div>
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold" for="notes">Notes</label>
        <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000"><?= h($v['notes']) ?></textarea>
      </div>
    </div>
    <?php if ($pdc): ?><div class="form-text mt-2">The status is changed with the <b>Status</b> button on the list, so each change is kept in the history below.</div><?php endif; ?>
  </div></div>

  <div class="d-flex justify-content-between align-items-center mb-4">
    <a href="<?= h($ltBase) ?>/pdc.php<?= $pdc ? '?status=all' : '' ?>" class="btn btn-lt-ghost"><i class="bi bi-arrow-left me-1"></i> Back</a>
    <button type="submit" class="btn btn-lt"><i class="bi bi-check-lg me-1"></i><?= $pdc ? 'Save changes' : 'Add cheque' ?></button>
  </div>
</form>

<?php if ($pdc): ?>
  <div class="card card-round mb-3" style="max-width:960px"><div class="card-body p-4">
    <div class="lt-section mb-2">Status history</div>
    <?php foreach ($history as $hst): ?>
      <div class="d-flex flex-wrap gap-2 align-items-center py-2 border-top small">
        <span class="text-muted" style="min-width:95px"><?= h(lt_date($hst['changed_on'])) ?></span>
        <span><?= !empty($hst['from_status']) ? lt_pdc_badge((string)$hst['from_status'], $hst['from_other'] ?? null) . ' <i class="bi bi-arrow-right mx-1"></i> ' : '' ?><?= lt_pdc_badge((string)$hst['to_status'], $hst['to_other'] ?? null) ?></span>
        <span class="flex-grow-1"><?= h($hst['note'] ?? '') ?></span>
        <span class="text-muted"><?= h(($hst['by'] ?? '') ?: '') ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$history): ?><div class="text-muted small">No changes yet.</div><?php endif; ?>
  </div></div>

  <form method="post" style="max-width:960px" onsubmit="return confirm('Delete cheque <?= h(addslashes($pdc['cheque_no'])) ?> (<?= h(addslashes($pdc['party_name'])) ?>)?\n\nIt is taken off the list, and its copy is deleted.');">
    <?php csrf_field(); ?><input type="hidden" name="do" value="delete">
    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete this cheque</button>
  </form>
<?php endif; ?>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var lbl = document.getElementById('partyLbl');
  var st = document.getElementById('status'), so = document.getElementById('statusOther');
  if (st && so) {
    var sync = function () { var o = st.value === 'other'; so.hidden = !o; so.required = o; };
    st.addEventListener('change', function () { sync(); if (st.value === 'other') { so.focus(); } }); sync();
  }
  document.querySelectorAll('[name=direction]').forEach(function (r) {
    r.addEventListener('change', function () { lbl.textContent = this.value === 'received' ? 'Received from' : 'Paid to'; });
  });
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
