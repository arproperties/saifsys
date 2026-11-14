<?php
/**
 * Loan Tracker — New loan, step 2: the borrower and their documents.
 *
 *   loan_docs.php?to=employee&employee=12   Emirates ID, passport, visa filled in from HR
 *   loan_docs.php?to=person                 name, contact, Emirates ID, passport, visa, other documents
 *   loan_docs.php?to=company                company name, contact, trade licence, other documents
 *
 * Emirates ID and passport (or the trade licence for a company) need a number and a file.
 * Next saves the loan as a draft with its documents; loan details are the next step.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';

$companyId = lt_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$types = lt_types();

$to = $_GET['to'] ?? '';
if (!isset($types[$to])) {
    header('Location: ' . $ltBase . '/new_loan.php?error=to');
    exit;
}
$emp = null;
$hrDocs = [];
if ($to === 'employee') {
    $emp = lt_employee($conn, (int)($_GET['employee'] ?? 0));
    if (!$emp) {
        header('Location: ' . $ltBase . '/new_loan.php?to=employee&error=employee');
        exit;
    }
    $hrDocs = lt_employee_docs($conn, $emp);
}
$selfUrl = $ltBase . '/loan_docs.php?' . http_build_query(['to' => $to] + ($emp ? ['employee' => (int)$emp['id']] : []));

// The documents asked for, by borrower type: kind => [title, icon, required]
$docDefs = $to === 'company'
    ? ['trade_licence' => ['Trade licence', 'bi-file-earmark-text', true]]
    : ['emirates_id' => ['Emirates ID', 'bi-person-vcard', true],
       'passport'    => ['Passport', 'bi-passport', true],
       'visa'        => ['Visa', 'bi-airplane', false]];
$allowOther = $to !== 'employee';

// Form values: what was posted, or HR's values for an employee
$v = ['name' => '', 'contact' => '', 'phone' => '', 'email' => '', 'docs' => [], 'other' => []];
foreach ($docDefs as $kind => $_) {
    $v['docs'][$kind] = ['number' => $hrDocs[$kind]['doc_number'] ?? '', 'expiry' => $hrDocs[$kind]['expires_at'] ?? ''];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $str = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $date = function ($s, $label) use (&$errors) {
        $s = trim((string)$s);
        if ($s === '') { return null; }
        $d = DateTime::createFromFormat('Y-m-d', $s);
        if (!$d || $d->format('Y-m-d') !== $s) { $errors[] = $label . ': the expiry date is not a valid date.'; return null; }
        return $s;
    };

    if ($to !== 'employee') {
        $v['name'] = $str('name', 200);
        $v['contact'] = $str('contact', 200);
        $v['phone'] = $str('phone', 50);
        $v['email'] = $str('email', 150);
        if ($v['name'] === '') {
            $errors[] = $to === 'company' ? 'Company name is required.' : 'Full name is required.';
        }
        if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The email address does not look right.';
        }
    }

    // The named documents
    $save = [];
    foreach ($docDefs as $kind => [$title, , $required]) {
        $number = mb_substr(trim((string)($_POST['number_' . $kind] ?? '')), 0, 100);
        $expiryRaw = (string)($_POST['expiry_' . $kind] ?? '');
        $skip = $required && !empty($_POST['skip_' . $kind]);
        $reason = mb_substr(trim((string)($_POST['skip_reason_' . $kind] ?? '')), 0, 255);
        $v['docs'][$kind] = ['number' => $number, 'expiry' => $expiryRaw, 'skip' => $skip, 'reason' => $reason];
        $expiry = $date($expiryRaw, $title);

        // A required document skipped for now: only the reason is needed
        if ($skip) {
            if ($reason === '') {
                $errors[] = $title . ': please give the reason for skipping it.';
            } else {
                $save[] = ['kind' => $kind, 'title' => $title, 'number' => $number, 'expiry' => $expiry,
                    'upload' => null, 'hr' => null, 'reason' => $reason];
            }
            continue;
        }

        $file = $_FILES['file_' . $kind] ?? null;
        if ($e = lt_upload_error($file, $title)) { $errors[] = $e; continue; }
        $hasUpload = lt_has_upload($file);
        $hrFile = !$hasUpload && !empty($hrDocs[$kind]['hr_document_id']);
        $hasFile = $hasUpload || $hrFile;

        if ($required) {
            if ($number === '') { $errors[] = $title . ' number is required.'; }
            if (!$hasFile) { $errors[] = $title . ': please attach the file.'; }
        } elseif (($number !== '' || $expiry) && !$hasFile) {
            // Optional document with no file (e.g. visa number from HR, file not in HR): keep the details only
            $save[] = ['kind' => $kind, 'title' => $title, 'number' => $number, 'expiry' => $expiry,
                'upload' => null, 'hr' => null, 'nofile' => true];
        }
        if ($hasFile) {
            $save[] = ['kind' => $kind, 'title' => $title, 'number' => $number, 'expiry' => $expiry,
                'upload' => $hasUpload ? $file : null,
                'hr' => $hrFile ? $hrDocs[$kind] : null];
        }
    }

    // Other documents (outside person and company)
    if ($allowOther) {
        $titles = (array)($_POST['other_title'] ?? []);
        $files = $_FILES['other_file'] ?? null;
        foreach ($titles as $i => $t) {
            $t = mb_substr(trim((string)$t), 0, 150);
            $number = mb_substr(trim((string)($_POST['other_number'][$i] ?? '')), 0, 100);
            $expiryRaw = (string)($_POST['other_expiry'][$i] ?? '');
            $file = $files ? ['name' => $files['name'][$i] ?? '', 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0] : null;
            $hasUpload = lt_has_upload($file);
            if ($t === '' && $number === '' && trim($expiryRaw) === '' && !$hasUpload) { continue; }
            $label = $t !== '' ? $t : 'Other document';
            $v['other'][] = ['title' => $t, 'number' => $number, 'expiry' => $expiryRaw];
            $expiry = $date($expiryRaw, $label);
            if ($e = lt_upload_error($file, $label)) { $errors[] = $e; continue; }
            if ($t === '') { $errors[] = 'Other document: please give it a name.'; }
            if (!$hasUpload) { $errors[] = $label . ': please attach the file.'; }
            if ($t !== '' && $hasUpload) {
                $save[] = ['kind' => 'other', 'title' => $t, 'number' => $number, 'expiry' => $expiry, 'upload' => $file, 'hr' => null];
            }
        }
    }

    if (!$errors) {
        $stored = [];
        try {
            $conn->beginTransaction();
            $conn->prepare("INSERT INTO lt_loans (company_id, loan_to, employee_id, borrower_name, contact_name, phone, email, status, created_by)
                            VALUES (?,?,?,?,?,?,?,'draft',?)")
                ->execute([
                    $companyId, $to, $emp ? (int)$emp['id'] : null,
                    $emp ? $emp['full_name'] : $v['name'],
                    $to === 'company' && $v['contact'] !== '' ? $v['contact'] : null,
                    $emp ? ($emp['phone'] ?: $emp['phone_personal'] ?: null) : ($v['phone'] ?: null),
                    $emp ? ($emp['email'] ?: null) : ($v['email'] ?: null),
                    current_user_id(),
                ]);
            $loanId = (int)$conn->lastInsertId();
            $ins = $conn->prepare("INSERT INTO lt_loan_documents (loan_id, doc_kind, title, doc_number, expires_at, source, hr_document_id, file_path, original_name, skipped, skip_reason)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $skipped = 0;
            foreach ($save as $d) {
                if (!empty($d['reason'])) {
                    $skipped++;
                    $ins->execute([$loanId, $d['kind'], $d['title'], $d['number'] ?: null, $d['expiry'], 'upload', null,
                        null, null, 1, $d['reason']]);
                } elseif (!empty($d['nofile'])) {
                    $ins->execute([$loanId, $d['kind'], $d['title'], $d['number'] ?: null, $d['expiry'], 'upload', null,
                        null, null, 0, null]);
                } elseif ($d['upload']) {
                    $path = lt_store_upload($d['upload'], $loanId);
                    $stored[] = $path;
                    $ins->execute([$loanId, $d['kind'], $d['title'], $d['number'] ?: null, $d['expiry'], 'upload', null,
                        $path, mb_substr(basename((string)$d['upload']['name']), 0, 255), 0, null]);
                } else {
                    $ins->execute([$loanId, $d['kind'], $d['title'], $d['number'] ?: null, $d['expiry'], 'hr',
                        (int)$d['hr']['hr_document_id'], $d['hr']['file_path'], null, 0, null]);
                }
            }
            $conn->commit();
            $n = count($save) - $skipped;
            lt_flash(lt_loan_no($loanId) . ' saved as a draft with ' . $n . ' document' . ($n === 1 ? '' : 's')
                . ($skipped ? ' (' . $skipped . ' skipped for now)' : '') . '. Now the loan details.');
            header('Location: ' . $ltBase . '/loan_details.php?id=' . $loanId);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            foreach ($stored as $p) { @unlink(dirname(__DIR__, 2) . '/' . $p); }
            error_log('loan_tracker save: ' . $e->getMessage());
            $errors[] = 'The loan could not be saved. Please try again.';
        }
    }
}

$accept = '.pdf,.jpg,.jpeg,.png,.webp';
$typeInfo = $types[$to];
$pageTitle = 'New loan';
require __DIR__ . '/includes/lt_layout_header.php';

/** One document block: number, expiry and the file (HR's copy for an employee, or an upload). */
$docBlock = function (string $kind, array $def) use ($v, $hrDocs, $emp, $accept, $ltBase) {
    [$title, $icon, $required] = $def;
    $hr = $hrDocs[$kind] ?? null;
    $hasHrFile = $hr && !empty($hr['hr_document_id']);
    $req = $required ? ' <span class="lt-req">*</span>' : '';
    ?>
    <div class="col-12">
      <div class="lt-doc">
        <div class="lt-doc-head">
          <span class="lt-doc-icon"><i class="bi <?= $icon ?>"></i></span>
          <div class="fw-semibold"><?= h($title) ?><?= $required ? '' : ' <span class="text-muted fw-normal small">(optional)</span>' ?></div>
        </div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="number_<?= $kind ?>"><?= h($title) ?> number<?= $req ?></label>
            <input type="text" class="form-control" id="number_<?= $kind ?>" name="number_<?= $kind ?>" maxlength="100"
                   value="<?= h($v['docs'][$kind]['number'] ?? '') ?>" <?= $required ? 'required' : '' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="expiry_<?= $kind ?>">Expiry date</label>
            <input type="date" class="form-control" id="expiry_<?= $kind ?>" name="expiry_<?= $kind ?>" value="<?= h($v['docs'][$kind]['expiry'] ?? '') ?>">
          </div>
          <div class="col-md-5">
            <label class="form-label small fw-semibold" for="file_<?= $kind ?>">Attachment<?= $req ?></label>
            <?php if ($emp && $hasHrFile): ?>
              <div class="lt-hr-file mb-2">
                <i class="bi bi-check-circle-fill"></i> On file in HR
                <a class="ms-auto fw-semibold" style="color:var(--primary-dark)" target="_blank" rel="noopener"
                   href="<?= h($ltBase) ?>/file.php?hr=<?= (int)$hr['hr_document_id'] ?>">View</a>
              </div>
              <input type="file" class="form-control form-control-sm" id="file_<?= $kind ?>" name="file_<?= $kind ?>" accept="<?= $accept ?>">
              <div class="form-text">Only attach a file to use a different copy.</div>
            <?php else: ?>
              <?php if ($emp): ?>
                <div class="lt-missing mb-2"><i class="bi bi-exclamation-circle"></i> Not on file in HR<?= $required ? ' — please attach' : '' ?></div>
              <?php endif; ?>
              <input type="file" class="form-control" id="file_<?= $kind ?>" name="file_<?= $kind ?>" accept="<?= $accept ?>" <?= $required ? 'required' : '' ?>>
              <div class="form-text">PDF, JPG, PNG or WEBP, up to 10 MB.</div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($required): $skipOn = !empty($v['docs'][$kind]['skip']); ?>
          <div class="lt-skip mt-3" data-kind="<?= h($kind) ?>">
            <div class="form-check">
              <input class="form-check-input lt-skip-box" type="checkbox" id="skip_<?= $kind ?>" name="skip_<?= $kind ?>" value="1" <?= $skipOn ? 'checked' : '' ?>>
              <label class="form-check-label small fw-semibold" for="skip_<?= $kind ?>">Don't have it now — skip with a reason</label>
            </div>
            <div class="lt-skip-reason mt-2" <?= $skipOn ? '' : 'hidden' ?>>
              <label class="form-label small fw-semibold" for="skip_reason_<?= $kind ?>">Reason for skipping <?= h($title) ?> <span class="lt-req">*</span></label>
              <textarea class="form-control" id="skip_reason_<?= $kind ?>" name="skip_reason_<?= $kind ?>" rows="2" maxlength="255"
                        placeholder="e.g. Passport is with immigration for visa renewal"><?= h($v['docs'][$kind]['reason'] ?? '') ?></textarea>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4" style="max-width:960px">
  <h1 class="lt-title">New loan</h1>
  <div class="lt-loan-to">
    <span class="lt-loan-to-label">Loan to</span>
    <span class="lt-loan-to-value"><i class="bi <?= $typeInfo['icon'] ?>"></i> <?= h($typeInfo['label']) ?></span>
  </div>
</div>

<?php lt_steps(2); ?>

<?php if ($errors): ?>
  <div class="alert alert-danger" style="max-width:960px">
    <div class="fw-semibold mb-1">Please check the following:</div>
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <?php if (!empty($_FILES)): ?><div class="small mt-2">Files you attached need to be attached again.</div><?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" action="<?= h($selfUrl) ?>" enctype="multipart/form-data" style="max-width:960px">
  <?php csrf_field(); ?>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3"><?= $to === 'company' ? 'Company' : 'Borrower' ?></div>
    <?php if ($emp): ?>
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="avatar" style="width:48px;height:48px;background:var(--lt-tint);color:var(--primary);font-size:1.1rem"><?= h(strtoupper(substr($emp['full_name'], 0, 1))) ?></div>
        <div>
          <div class="fw-semibold fs-5" style="color:var(--lt-ink)"><?= h($emp['full_name']) ?></div>
          <div class="text-muted small"><?= h($emp['employee_code']) ?> · <?= h($emp['position_title'] ?: '—') ?> · <?= h($emp['company_name'] ?: '—') ?></div>
        </div>
        <div class="ms-md-auto small text-muted">
          <?php if ($emp['phone'] || $emp['phone_personal']): ?><div><i class="bi bi-telephone me-1"></i><?= h($emp['phone'] ?: $emp['phone_personal']) ?></div><?php endif; ?>
          <?php if ($emp['email']): ?><div><i class="bi bi-envelope me-1"></i><?= h($emp['email']) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="mt-3"><a href="<?= h($ltBase) ?>/new_loan.php?to=employee&employee=<?= (int)$emp['id'] ?>" class="small">Change employee</a></div>
    <?php else: ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold" for="name"><?= $to === 'company' ? 'Company name' : 'Full name' ?> <span class="lt-req">*</span></label>
          <input type="text" class="form-control" id="name" name="name" maxlength="200" required value="<?= h($v['name']) ?>"
                 placeholder="<?= $to === 'company' ? 'As on the trade licence' : 'As on the passport' ?>">
        </div>
        <?php if ($to === 'company'): ?>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="contact">Contact person</label>
            <input type="text" class="form-control" id="contact" name="contact" maxlength="200" value="<?= h($v['contact']) ?>">
          </div>
        <?php endif; ?>
        <div class="col-md-<?= $to === 'company' ? '6' : '3' ?>">
          <label class="form-label small fw-semibold" for="phone">Phone</label>
          <input type="tel" class="form-control" id="phone" name="phone" maxlength="50" value="<?= h($v['phone']) ?>">
        </div>
        <div class="col-md-<?= $to === 'company' ? '6' : '3' ?>">
          <label class="form-label small fw-semibold" for="email">Email</label>
          <input type="email" class="form-control" id="email" name="email" maxlength="150" value="<?= h($v['email']) ?>">
        </div>
      </div>
    <?php endif; ?>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3">Documents<?= $emp ? ' · filled in from HR' : '' ?></div>
    <div class="row g-3">
      <?php foreach ($docDefs as $kind => $def) { $docBlock($kind, $def); } ?>
    </div>

    <?php if ($allowOther): ?>
      <div class="mt-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">Other documents <span class="text-muted fw-normal small">(optional)</span></div>
          <button type="button" class="btn btn-lt-ghost btn-sm" id="ltAddOther"><i class="bi bi-plus-lg me-1"></i>Add document</button>
        </div>
        <div id="ltOthers" class="d-grid gap-2">
          <?php foreach ($v['other'] as $o): ?>
            <div class="lt-doc lt-other">
              <div class="row g-2 align-items-end">
                <div class="col-md-4"><label class="form-label small fw-semibold">Document name <span class="lt-req">*</span></label>
                  <input type="text" class="form-control" name="other_title[]" maxlength="150" value="<?= h($o['title']) ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-semibold">Number</label>
                  <input type="text" class="form-control" name="other_number[]" maxlength="100" value="<?= h($o['number']) ?>"></div>
                <div class="col-md-2"><label class="form-label small fw-semibold">Expiry</label>
                  <input type="date" class="form-control" name="other_expiry[]" value="<?= h($o['expiry']) ?>"></div>
                <div class="col-md-2"><label class="form-label small fw-semibold">Attachment <span class="lt-req">*</span></label>
                  <input type="file" class="form-control" name="other_file[]" accept="<?= $accept ?>"></div>
                <div class="col-md-1 text-end"><button type="button" class="btn btn-link text-danger lt-remove" title="Remove"><i class="bi bi-trash"></i></button></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="text-muted small mt-2" id="ltOthersEmpty" <?= $v['other'] ? 'hidden' : '' ?>>No other documents added.</div>
      </div>
    <?php endif; ?>
  </div></div>

  <div class="d-flex justify-content-between align-items-center">
    <a href="<?= h($ltBase) ?>/new_loan.php?<?= h(http_build_query(['to' => $to] + ($emp ? ['employee' => (int)$emp['id']] : []))) ?>" class="btn btn-lt-ghost"><i class="bi bi-arrow-left me-1"></i> Back</a>
    <button type="submit" class="btn btn-lt">Next <i class="bi bi-arrow-right ms-1"></i></button>
  </div>
</form>

<?php if ($allowOther): ?>
<template id="ltOtherTpl">
  <div class="lt-doc lt-other">
    <div class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label small fw-semibold">Document name <span class="lt-req">*</span></label>
        <input type="text" class="form-control" name="other_title[]" maxlength="150" placeholder="e.g. Salary certificate" required></div>
      <div class="col-md-3"><label class="form-label small fw-semibold">Number</label>
        <input type="text" class="form-control" name="other_number[]" maxlength="100"></div>
      <div class="col-md-2"><label class="form-label small fw-semibold">Expiry</label>
        <input type="date" class="form-control" name="other_expiry[]"></div>
      <div class="col-md-2"><label class="form-label small fw-semibold">Attachment <span class="lt-req">*</span></label>
        <input type="file" class="form-control" name="other_file[]" accept="<?= $accept ?>" required></div>
      <div class="col-md-1 text-end"><button type="button" class="btn btn-link text-danger lt-remove" title="Remove"><i class="bi bi-trash"></i></button></div>
    </div>
  </div>
</template>
<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var list = document.getElementById('ltOthers');
  var empty = document.getElementById('ltOthersEmpty');
  var tpl = document.getElementById('ltOtherTpl');
  function sync() { empty.hidden = list.children.length > 0; }
  document.getElementById('ltAddOther').addEventListener('click', function () {
    list.appendChild(tpl.content.cloneNode(true));
    list.lastElementChild.querySelector('input').focus();
    sync();
  });
  list.addEventListener('click', function (e) {
    var b = e.target.closest('.lt-remove');
    if (b) { b.closest('.lt-other').remove(); sync(); }
  });
})();
</script>
JS;
endif;
// Skipping a required document: the reason becomes required and the number and file do not.
$pageScripts = ($pageScripts ?? '') . <<<'JS'
<script>
(function () {
  document.querySelectorAll('.lt-skip').forEach(function (box) {
    var kind = box.dataset.kind;
    var cb = box.querySelector('.lt-skip-box');
    var wrap = box.querySelector('.lt-skip-reason');
    var reason = wrap.querySelector('textarea');
    var number = document.getElementById('number_' + kind);
    var file = document.getElementById('file_' + kind);
    var fileWasRequired = file && file.required;
    function sync() {
      var on = cb.checked;
      wrap.hidden = !on;
      reason.required = on;
      if (number) { number.required = !on; }
      if (file) { file.required = !on && fileWasRequired; file.disabled = on; }
      box.closest('.lt-doc').classList.toggle('lt-doc-skipped', on);
      if (on) { reason.focus(); }
    }
    cb.addEventListener('change', sync);
    if (cb.checked) { sync(); }
  });
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
