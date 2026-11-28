<?php
/**
 * Post-dated cheques — add a cheque, or change one (?id=): details, copy of the cheque,
 * its status history, delete. Owner, Admin and HR.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_pdc.php';

$companyId = lt_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$dirs = lt_pdc_directions();
$statuses = lt_pdc_statuses();

$id = (int)($_GET['id'] ?? 0);
$pdc = $id ? lt_pdc_get($conn, $id, $companyId) : null;
if ($id && !$pdc) {
    lt_flash('That cheque was not found.', 'warning');
    header('Location: ' . $ltBase . '/pdc.php');
    exit;
}

$fields = ['direction', 'party_name', 'cheque_no', 'bank', 'cheque_date', 'amount', 'purpose', 'location', 'reference', 'approved_by', 'issued_by', 'status', 'notes'];
$v = [];
foreach ($fields as $k) {
    $v[$k] = $pdc ? (string)($pdc[$k] ?? '') : '';
}
if (!$pdc) {
    $v['direction'] = isset($dirs[$_GET['dir'] ?? '']) ? $_GET['dir'] : 'issued';
    $v['status'] = 'pending';
    $v['approved_by'] = lt_user_name($conn, current_user_id()); // the person adding it; can be changed
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ($pdc && ($_POST['do'] ?? '') === 'delete') {
        $conn->prepare("DELETE FROM lt_pdc WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        if ($pdc['file_path']) { @unlink(dirname(__DIR__, 2) . '/' . $pdc['file_path']); @rmdir(lt_upload_root() . '/pdc' . $id); }
        lt_flash('Cheque ' . $pdc['cheque_no'] . ' (' . $pdc['party_name'] . ') is deleted.');
        header('Location: ' . $ltBase . '/pdc.php');
        exit;
    }
    foreach ($fields as $k) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if (!isset($dirs[$v['direction']])) { $errors[] = 'Please choose whether we issued or received the cheque.'; }
    if ($v['party_name'] === '') { $errors[] = 'Please enter who the cheque is ' . ($v['direction'] === 'received' ? 'from' : 'to') . '.'; }
    if ($v['cheque_no'] === '') { $errors[] = 'Please enter the cheque number.'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['cheque_date']) || !checkdate((int)substr($v['cheque_date'], 5, 2), (int)substr($v['cheque_date'], 8, 2), (int)substr($v['cheque_date'], 0, 4))) {
        $errors[] = 'Please enter the cheque date.';
    }
    $amount = is_numeric(str_replace(',', '', $v['amount'])) ? round((float)str_replace(',', '', $v['amount']), 2) : 0.0;
    if ($amount <= 0) { $errors[] = 'Please enter the amount.'; }
    $newStatus = ['pending', null];
    if (!$pdc) {
        $newStatus = lt_pdc_parse_status($v['status'], (string)($_POST['status_other'] ?? ''));
        if (!$newStatus) {
            $errors[] = $v['status'] === 'other' ? 'Please type the name of the status.' : 'Please choose the status.';
            $newStatus = ['pending', null];
        }
    }
    $file = $_FILES['file'] ?? null;
    if ($e = lt_upload_error($file, 'Cheque copy')) { $errors[] = $e; }
    // Same cheque twice?
    if (!$errors) {
        $dq = $conn->prepare("SELECT id FROM lt_pdc WHERE company_id = ? AND cheque_no = ? AND COALESCE(bank,'') = ? AND direction = ? AND id <> ?");
        $dq->execute([$companyId, $v['cheque_no'], $v['bank'], $v['direction'], $id]);
        if ($dq->fetchColumn()) { $errors[] = 'Cheque ' . $v['cheque_no'] . ($v['bank'] !== '' ? ' of ' . $v['bank'] : '') . ' is already in the list.'; }
    }

    if (!$errors) {
        $stored = null;
        try {
            $conn->beginTransaction();
            $vals = [$v['direction'], mb_substr($v['party_name'], 0, 200), mb_substr($v['cheque_no'], 0, 50), mb_substr($v['bank'], 0, 120) ?: null,
                $v['cheque_date'], $amount, mb_substr($v['purpose'], 0, 255) ?: null, mb_substr($v['reference'], 0, 100) ?: null, mb_substr($v['notes'], 0, 1000) ?: null,
                mb_substr($v['approved_by'], 0, 150) ?: null, mb_substr($v['location'], 0, 150) ?: null, mb_substr($v['issued_by'], 0, 150) ?: null];
            if ($pdc) {
                $conn->prepare("UPDATE lt_pdc SET direction = ?, party_name = ?, cheque_no = ?, bank = ?, cheque_date = ?, amount = ?, purpose = ?, reference = ?, notes = ?,
                                       approved_by = ?, location = ?, issued_by = ?, updated_by = ? WHERE id = ? AND company_id = ?")
                    ->execute(array_merge($vals, [current_user_id(), $id, $companyId]));
            } else {
                $conn->prepare("INSERT INTO lt_pdc (direction, party_name, cheque_no, bank, cheque_date, amount, purpose, reference, notes, approved_by, location, issued_by, status, status_other, status_date, company_id, created_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),?,?)")
                    ->execute(array_merge($vals, [$newStatus[0], $newStatus[1], $companyId, current_user_id()]));
                $id = (int)$conn->lastInsertId();
                $conn->prepare("INSERT INTO lt_pdc_history (pdc_id, from_status, to_status, to_other, changed_on, note, changed_by) VALUES (?, NULL, ?, ?, CURDATE(), 'Added', ?)")
                    ->execute([$id, $newStatus[0], $newStatus[1], current_user_id()]);
            }
            if (lt_has_upload($file)) {
                $stored = lt_store_upload($file, 'pdc' . $id);
                $conn->prepare("UPDATE lt_pdc SET file_path = ?, original_name = ? WHERE id = ?")
                    ->execute([$stored, mb_substr(basename((string)$file['name']), 0, 255), $id]);
            }
            $conn->commit();
            if ($stored && $pdc && $pdc['file_path']) { @unlink(dirname(__DIR__, 2) . '/' . $pdc['file_path']); }
            lt_flash($pdc ? 'Cheque ' . $v['cheque_no'] . ' saved.' : 'Cheque ' . $v['cheque_no'] . ' added.');
            header('Location: ' . $ltBase . '/pdc.php' . ($pdc ? '?status=all' : ''));
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            if ($stored) { @unlink(dirname(__DIR__, 2) . '/' . $stored); }
            if (!$pdc) { $id = 0; }
            error_log('loan_tracker pdc save: ' . $e->getMessage());
            $errors[] = 'The cheque could not be saved. Please try again.';
        }
    }
}

$history = [];
if ($pdc) {
    $q = $conn->prepare("SELECT hst.*, COALESCE(NULLIF(u.fullname,''), u.username) AS by_name
                           FROM lt_pdc_history hst LEFT JOIN `user` u ON u.id = hst.changed_by WHERE hst.pdc_id = ? ORDER BY hst.changed_on DESC, hst.id DESC");
    $q->execute([$id]);
    $history = $q->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $pdc ? 'Change cheque' : 'Add cheque';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4" style="max-width:960px">
  <div>
    <h1 class="lt-title"><?= $pdc ? 'Change cheque' : 'Add a Company PDC' ?></h1>
    <p class="lt-sub"><?= $pdc ? h($pdc['cheque_no'] . ' · ' . $pdc['party_name']) . ' · ' . lt_pdc_badge($pdc['status'], $pdc['status_other']) : 'A cheque we give, or a cheque given to us.' ?></p>
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
            <?= lt_pdc_status_options(lt_pdc_custom_statuses($conn, $companyId), str_starts_with($v['status'], 'custom:') ? 'other' : $v['status'], str_starts_with($v['status'], 'custom:') ? substr($v['status'], 7) : null) ?>
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
        <?php if ($pdc && $pdc['file_path']): ?>
          <div class="small mb-1"><i class="bi bi-paperclip"></i> <a target="_blank" rel="noopener" href="<?= h($ltBase) ?>/pdc.php?file=<?= $id ?>"><?= h($pdc['original_name'] ?: 'Attached') ?></a> <span class="text-muted">(a new file replaces it)</span></div>
        <?php endif; ?>
        <input type="file" class="form-control" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp">
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
        <span><?= $hst['from_status'] ? lt_pdc_badge($hst['from_status'], $hst['from_other']) . ' <i class="bi bi-arrow-right mx-1"></i> ' : '' ?><?= lt_pdc_badge($hst['to_status'], $hst['to_other']) ?></span>
        <span class="flex-grow-1"><?= h($hst['note'] ?? '') ?></span>
        <span class="text-muted"><?= h($hst['by_name'] ?: '') ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$history): ?><div class="text-muted small">No changes yet.</div><?php endif; ?>
  </div></div>

  <form method="post" style="max-width:960px" onsubmit="return confirm('Delete cheque <?= h(addslashes($pdc['cheque_no'])) ?> (<?= h(addslashes($pdc['party_name'])) ?>)?\n\nIts copy and history are deleted too. This cannot be undone.');">
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
