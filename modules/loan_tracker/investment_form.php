<?php
/**
 * Loan Tracker — new investment, or change one (?id=). One page:
 * invested to (person / company), the investment, duration, return, security, documents.
 * Owner only.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_invest.php';

$companyId = lt_inv_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
lt_inv_require_edit($conn, $ltBase . '/investments.php');
$to = lt_inv_to();
$paidBy = lt_inv_paid_by();
$freqs = lt_inv_frequencies();
$kinds = lt_inv_security_kinds();

$id = (int)($_GET['id'] ?? 0);
$inv = $id ? lt_inv_get($conn, $id, $companyId) : null;
if ($id && !$inv) {
    header('Location: ' . $ltBase . '/investments.php');
    exit;
}
if ($inv && $inv['status'] !== 'active') {
    lt_flash('A closed investment cannot be changed.', 'warning');
    header('Location: ' . $ltBase . '/investment_view.php?id=' . $id);
    exit;
}
$secRows = $docRows = [];
if ($inv) {
    $q = $conn->prepare("SELECT * FROM lt_investment_security WHERE investment_id = ? ORDER BY id");
    $q->execute([$id]);
    $secRows = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $conn->prepare("SELECT * FROM lt_investment_documents WHERE investment_id = ? ORDER BY id");
    $q->execute([$id]);
    $docRows = $q->fetchAll(PDO::FETCH_ASSOC);
}

$fields = ['invest_to', 'name', 'contact_name', 'phone', 'email', 'amount', 'invested_on', 'purpose', 'paid_by', 'reference',
    'duration_months', 'return_type', 'return_value', 'return_frequency', 'return_mode', 'return_status', 'notes'];
$retStatuses = lt_inv_return_statuses();
$v = [];
foreach ($fields as $f) {
    $v[$f] = $inv ? (string)($inv[$f] ?? '') : '';
}
if (!$inv) {
    $v['invest_to'] = in_array($_GET['to'] ?? '', ['person', 'company'], true) ? $_GET['to'] : 'person';
    $v['invested_on'] = date('Y-m-d');
    $v['paid_by'] = 'bank';
    $v['return_type'] = 'percent';
    $v['return_frequency'] = 'monthly';
}
$errors = [];
// Custom plan: each payout typed in (date, amount, note)
$planRows = $inv ? array_map(static fn($p) => ['after' => (string)($p['after_months'] ?? ''), 'unit' => (string)($p['after_unit'] ?? 'months'), 'due_date' => $p['due_date'], 'amount' => $p['amount'], 'note' => (string)$p['note']], lt_inv_plan($conn, $id)) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($fields as $f) {
        $v[$f] = trim((string)($_POST[$f] ?? ''));
    }
    $validDate = static fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
    if (!isset($to[$v['invest_to']])) { $errors[] = 'Please choose a person or a company.'; }
    if ($v['name'] === '') { $errors[] = 'Please enter the name.'; }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'The email address does not look right.'; }
    $amount = is_numeric($v['amount']) ? round((float)$v['amount'], 2) : 0.0;
    if ($amount <= 0) { $errors[] = 'Please enter the amount invested.'; }
    if (!$validDate($v['invested_on'])) { $errors[] = 'Please enter the date of the investment.'; }
    if (!isset($paidBy[$v['paid_by']])) { $errors[] = 'Please choose how the money was paid.'; }
    $months = ctype_digit($v['duration_months']) ? (int)$v['duration_months'] : 0;
    if ($months < 1 || $months > 600) { $errors[] = 'Please enter the duration in months (1 to 600).'; }
    $planRows = [];
    foreach ((array)($_POST['plan'] ?? []) as $p) {
        $p = array_map(static fn($x) => trim((string)$x), (array)$p);
        if (($p['after'] ?? '') === '' && ($p['due_date'] ?? '') === '' && ($p['amount'] ?? '') === '' && ($p['note'] ?? '') === '') { continue; }
        $after = ctype_digit($p['after'] ?? '') && (int)$p['after'] <= 3650 ? (string)(int)$p['after'] : '';
        $unit = mb_substr(trim((string)($p['unit'] ?? '')), 0, 30) ?: 'months';
        if (isset(LT_INV_AFTER_UNITS[strtolower($unit)])) { $unit = strtolower($unit); }
        // "after N months" gives the date when no date was typed
        $due = $p['due_date'] ?? '';
        if ($due === '' && $after !== '' && isset(LT_INV_AFTER_UNITS[$unit]) && $validDate($v['invested_on'])) { $due = lt_inv_after_date($v['invested_on'], (int)$after, $unit); }
        $planRows[] = ['after' => $after, 'unit' => $unit, 'due_date' => $due, 'amount' => $p['amount'] ?? '', 'note' => mb_substr($p['note'] ?? '', 0, 150)];
    }
    foreach ($planRows as $i => $p) {
        if (!$validDate($p['due_date'])) { $errors[] = 'Payout ' . ($i + 1) . ': please enter the date.'; }
        if (!is_numeric($p['amount']) || (float)$p['amount'] <= 0) { $errors[] = 'Payout ' . ($i + 1) . ': please enter the amount.'; }
    }
    $v['return_type'] = $planRows ? 'custom' : 'none';
    // Return: nothing here is required; what is filled in must look right.
    // the example is kept only as a note of how the plan was filled
    $retVal = $v['return_value'] !== '' && is_numeric($v['return_value']) && (float)$v['return_value'] >= 0 ? (float)$v['return_value'] : null;
    if (!isset($freqs[$v['return_frequency']])) { $v['return_frequency'] = 'monthly'; }
    if ($v['return_mode'] !== '' && !isset($paidBy[$v['return_mode']])) { $v['return_mode'] = ''; }
    if ($v['return_status'] !== '' && !isset($retStatuses[$v['return_status']])) { $v['return_status'] = ''; }
    // Repayments (returns received) added on this form, each with an optional proof
    $newRet = [];
    foreach ((array)($_POST['ret'] ?? []) as $i => $r) {
        $r = array_map(static fn($x) => trim((string)$x), (array)$r);
        $f = lt_inv_file_at($_FILES['ret_file'] ?? null, $i);
        if (($r['amount'] ?? '') === '' && ($r['received_on'] ?? '') === '' && !lt_has_upload($f)) { continue; }
        $ra = is_numeric($r['amount'] ?? '') ? round((float)$r['amount'], 2) : 0.0;
        if ($ra <= 0) { $errors[] = 'Payout received: please enter the amount.'; continue; }
        $rd = ($r['received_on'] ?? '') !== '' ? $r['received_on'] : date('Y-m-d');
        if (!$validDate($rd) || $rd > date('Y-m-d')) { $errors[] = 'Payout received: the date does not look right (not in the future).'; continue; }
        if ($e = lt_upload_error($f, 'Payout proof')) { $errors[] = $e; continue; }
        $newRet[] = ['amount' => $ra, 'date' => $rd, 'f' => $f];
    }
    $proof = $_FILES['proof'] ?? null;
    if ($e = lt_upload_error($proof, 'Proof of payment')) { $errors[] = $e; }

    // Security rows (new) and their files
    $newSec = [];
    foreach ((array)($_POST['sec'] ?? []) as $i => $s) {
        $s = array_map(static fn($x) => trim((string)$x), (array)$s);
        $f = lt_inv_file_at($_FILES['sec_file'] ?? null, $i);
        $hasAny = ($s['details'] ?? '') !== '' || ($s['cheque_no'] ?? '') !== '' || ($s['amount'] ?? '') !== '' || lt_has_upload($f);
        if (!$hasAny) { continue; }
        if (!isset($kinds[$s['kind'] ?? ''])) { $errors[] = 'Security: please choose what it is.'; continue; }
        if (($s['amount'] ?? '') !== '' && (!is_numeric($s['amount']) || (float)$s['amount'] < 0)) { $errors[] = 'Security: the amount does not look right.'; }
        if (($s['item_date'] ?? '') !== '' && !$validDate($s['item_date'])) { $errors[] = 'Security: the date does not look right.'; }
        if ($e = lt_upload_error($f, 'Security document')) { $errors[] = $e; }
        $newSec[] = ['s' => $s, 'f' => $f];
    }
    // Document rows (new)
    $newDocs = [];
    foreach ((array)($_POST['doc_title'] ?? []) as $i => $title) {
        $title = trim((string)$title);
        $f = lt_inv_file_at($_FILES['doc_file'] ?? null, $i);
        if ($title === '' && !lt_has_upload($f)) { continue; }
        if (!lt_has_upload($f)) { $errors[] = 'Document "' . $title . '": please attach the file.'; continue; }
        if ($e = lt_upload_error($f, 'Document')) { $errors[] = $e; continue; }
        $newDocs[] = ['title' => $title !== '' ? $title : 'Document', 'f' => $f];
    }
    $dropSec = array_map('intval', (array)($_POST['drop_sec'] ?? []));
    $dropDoc = array_map('intval', (array)($_POST['drop_doc'] ?? []));

    if (!$errors) {
        $stored = [];
        try {
            $conn->beginTransaction();
            $end = lt_add_months($v['invested_on'], $months);
            $vals = [$v['invest_to'], mb_substr($v['name'], 0, 200), $v['contact_name'] ?: null, $v['phone'] ?: null, $v['email'] ?: null,
                $amount, $v['invested_on'], mb_substr($v['purpose'], 0, 500) ?: null, $v['paid_by'], $v['reference'] ?: null,
                $months, $end, $v['return_type'], $retVal, $retVal !== null ? $v['return_frequency'] : null,
                $v['return_mode'] ?: null, $v['return_status'] ?: null, mb_substr($v['notes'], 0, 1000) ?: null];
            if ($inv) {
                $conn->prepare("UPDATE lt_investments SET invest_to = ?, name = ?, contact_name = ?, phone = ?, email = ?, amount = ?, invested_on = ?,
                                       purpose = ?, paid_by = ?, reference = ?, duration_months = ?, end_date = ?, return_type = ?, return_value = ?,
                                       return_frequency = ?, return_mode = ?, return_status = ?, notes = ? WHERE id = ? AND company_id = ?")
                    ->execute(array_merge($vals, [$id, $companyId]));
            } else {
                $conn->prepare("INSERT INTO lt_investments (invest_to, name, contact_name, phone, email, amount, invested_on, purpose, paid_by, reference,
                                       duration_months, end_date, return_type, return_value, return_frequency, return_mode, return_status, notes, company_id, created_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute(array_merge($vals, [$companyId, current_user_id()]));
                $id = (int)$conn->lastInsertId();
            }
            // the custom plan: replaced as a whole on each save
            $conn->prepare("DELETE FROM lt_investment_plan WHERE investment_id = ?")->execute([$id]);
            $planTotal = null; $planCount = null;
            if ($v['return_type'] === 'custom') {
                usort($planRows, static fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
                $pi = $conn->prepare("INSERT INTO lt_investment_plan (investment_id, seq, after_months, after_unit, due_date, amount, note) VALUES (?,?,?,?,?,?,?)");
                $planTotal = 0.0;
                foreach ($planRows as $n => $p) {
                    $pi->execute([$id, $n + 1, $p['after'] !== '' ? (int)$p['after'] : null, $p['unit'] ?? 'months', $p['due_date'], round((float)$p['amount'], 2), $p['note'] !== '' ? $p['note'] : null]);
                    $planTotal += round((float)$p['amount'], 2);
                }
                $planCount = count($planRows);
            }
            $conn->prepare("UPDATE lt_investments SET plan_total = ?, plan_count = ? WHERE id = ?")->execute([$planTotal, $planCount, $id]);
            $folder = 'inv' . $id;
            if (lt_has_upload($proof)) {
                $p = lt_store_upload($proof, $folder);
                $stored[] = $p;
                $conn->prepare("UPDATE lt_investments SET proof_path = ?, proof_name = ? WHERE id = ?")
                    ->execute([$p, mb_substr(basename((string)$proof['name']), 0, 255), $id]);
            }
            $ins = $conn->prepare("INSERT INTO lt_investment_security (investment_id, kind, details, cheque_no, bank, amount, item_date, file_path, original_name)
                                   VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($newSec as $ns) {
                $s = $ns['s'];
                $p = lt_has_upload($ns['f']) ? lt_store_upload($ns['f'], $folder) : null;
                if ($p) { $stored[] = $p; }
                $ins->execute([$id, $s['kind'], mb_substr($s['details'] ?? '', 0, 255) ?: null, ($s['cheque_no'] ?? '') ?: null, ($s['bank'] ?? '') ?: null,
                    ($s['amount'] ?? '') !== '' ? round((float)$s['amount'], 2) : null, ($s['item_date'] ?? '') ?: null,
                    $p, $p ? mb_substr(basename((string)$ns['f']['name']), 0, 255) : null]);
            }
            $ins = $conn->prepare("INSERT INTO lt_investment_returns (investment_id, received_on, amount, paid_by, file_path, original_name, created_by)
                                   VALUES (?,?,?,?,?,?,?)");
            foreach ($newRet as $nr) {
                $p = lt_has_upload($nr['f']) ? lt_store_upload($nr['f'], $folder) : null;
                if ($p) { $stored[] = $p; }
                $ins->execute([$id, $nr['date'], $nr['amount'], $v['return_mode'] ?: 'bank', $p,
                    $p ? mb_substr(basename((string)$nr['f']['name']), 0, 255) : null, current_user_id()]);
            }
            $ins = $conn->prepare("INSERT INTO lt_investment_documents (investment_id, title, file_path, original_name) VALUES (?,?,?,?)");
            foreach ($newDocs as $nd) {
                $p = lt_store_upload($nd['f'], $folder);
                $stored[] = $p;
                $ins->execute([$id, mb_substr($nd['title'], 0, 150), $p, mb_substr(basename((string)$nd['f']['name']), 0, 255)]);
            }
            $gone = [];
            if ($inv && $dropSec) {
                $in = implode(',', array_fill(0, count($dropSec), '?'));
                $q = $conn->prepare("SELECT file_path FROM lt_investment_security WHERE investment_id = ? AND id IN ($in)");
                $q->execute(array_merge([$id], $dropSec));
                $gone = array_merge($gone, $q->fetchAll(PDO::FETCH_COLUMN));
                $conn->prepare("DELETE FROM lt_investment_security WHERE investment_id = ? AND id IN ($in)")->execute(array_merge([$id], $dropSec));
            }
            if ($inv && $dropDoc) {
                $in = implode(',', array_fill(0, count($dropDoc), '?'));
                $q = $conn->prepare("SELECT file_path FROM lt_investment_documents WHERE investment_id = ? AND id IN ($in)");
                $q->execute(array_merge([$id], $dropDoc));
                $gone = array_merge($gone, $q->fetchAll(PDO::FETCH_COLUMN));
                $conn->prepare("DELETE FROM lt_investment_documents WHERE investment_id = ? AND id IN ($in)")->execute(array_merge([$id], $dropDoc));
            }
            $conn->commit();
            if ($inv && lt_has_upload($proof) && !empty($inv['proof_path'])) {
                @unlink(dirname(__DIR__, 2) . '/' . $inv['proof_path']);
            }
            foreach (array_filter($gone) as $g) {
                @unlink(dirname(__DIR__, 2) . '/' . $g);
            }
            lt_flash($inv ? 'Investment saved.' : 'Investment added: ' . lt_inv_no($id) . '.');
            header('Location: ' . $ltBase . '/investment_view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            foreach ($stored as $p) { @unlink(dirname(__DIR__, 2) . '/' . $p); }
            if (!$inv) { $id = 0; }
            error_log('loan_tracker investment save: ' . $e->getMessage());
            $errors[] = 'The investment could not be saved. Please try again.';
        }
    }
}

$pageTitle = $inv ? 'Change investment' : 'New investment';
$pageStyles = '
  .lt-sec-row, .lt-doc-row{ border:1px solid var(--lt-line); border-radius:12px; padding:.8rem; background:#fafafa; }
  .lt-old{ display:flex; align-items:center; gap:.6rem; padding:.55rem .8rem; border:1px solid var(--lt-line); border-radius:10px; background:#fff; }
  .lt-calc{ background:var(--lt-tint); border:1px solid var(--lt-tint-3); border-radius:12px; padding:.8rem 1rem; }
  .lt-calc small{ display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--primary-dark); opacity:.8; }
  .lt-calc b{ font-size:1.1rem; color:var(--primary-dark); font-variant-numeric:tabular-nums; }
  .lt-amount-in{ font-size:1.5rem; font-weight:800; color:var(--primary-dark); font-variant-numeric:tabular-nums; }
  .lt-amount-big{ background:var(--lt-tint-2); border:1px solid var(--lt-tint-3); border-radius:14px; padding:.55rem 1.1rem; text-align:right; color:var(--primary-dark); }
  .lt-amount-big small{ display:block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; opacity:.8; }
  .lt-amount-big span{ font-size:1.9rem; font-weight:800; font-variant-numeric:tabular-nums; line-height:1.15; }
  .lt-plan-row td{ padding:.35rem .4rem; vertical-align:middle; }
  .lt-example{ border:1px dashed var(--lt-tint-3); background:#fafbff; border-radius:12px; padding:.8rem 1rem; }
  .lt-step{ display:flex; align-items:center; gap:.5rem; font-weight:700; color:var(--lt-ink); margin-bottom:.75rem; }
  .lt-step > span:first-child{ width:22px; height:22px; border-radius:50%; background:var(--primary); color:#fff; font-size:.75rem; display:inline-flex; align-items:center; justify-content:center; }
  .lt-plan{ background:var(--lt-tint); border:1px solid var(--lt-tint-3); border-radius:10px; padding:.65rem .9rem; color:var(--primary-dark); }
';
require __DIR__ . '/includes/lt_layout_header.php';
$secKindOptions = '';
foreach ($kinds as $k => $kk) { $secKindOptions .= '<option value="' . h($k) . '">' . h($kk['label']) . '</option>'; }
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4" style="max-width:960px">
  <div>
    <h1 class="lt-title"><?= $inv ? 'Change investment' : 'New investment' ?></h1>
    <p class="lt-sub"><?= $inv ? h(lt_inv_no($id) . ' · ' . $inv['name']) : 'Money invested to a person or a company.' ?></p>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger" style="max-width:960px">
    <div class="fw-semibold mb-1">Please check the following:</div>
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <div class="small mt-2">Files you attached need to be attached again.</div>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" style="max-width:960px" id="ltInv">
  <?php csrf_field(); ?>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3">Invested to</div>
    <div class="row g-3 mb-2">
      <?php foreach ($to as $k => $t): ?>
        <div class="col-6 col-md-3">
          <label class="lt-choice" style="--c:<?= h($t['color']) ?>">
            <input type="radio" name="invest_to" value="<?= h($k) ?>" <?= $v['invest_to'] === $k ? 'checked' : '' ?>>
            <span class="lt-choice-box d-flex align-items-center gap-2"><i class="bi <?= h($t['icon']) ?>"></i><span class="fw-semibold"><?= h($t['label']) ?></span></span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label small fw-semibold" for="name"><span data-for="person">Name</span><span data-for="company">Company name</span> <span class="lt-req">*</span></label>
        <input type="text" class="form-control" id="name" name="name" maxlength="200" value="<?= h($v['name']) ?>" required>
      </div>
      <div class="col-md-6" data-for="company">
        <label class="form-label small fw-semibold" for="contact_name">Contact person</label>
        <input type="text" class="form-control" id="contact_name" name="contact_name" maxlength="200" value="<?= h($v['contact_name']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="phone">Phone</label>
        <input type="text" class="form-control" id="phone" name="phone" maxlength="50" value="<?= h($v['phone']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold" for="email">Email</label>
        <input type="email" class="form-control" id="email" name="email" maxlength="150" value="<?= h($v['email']) ?>">
      </div>
    </div>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div class="lt-section mb-0">Investment</div>
      <div class="lt-amount-big" id="amountBig" hidden><small>Amount invested</small><span>AED <b id="amountBigVal"></b></span></div>
    </div>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="amount">Amount (AED) <span class="lt-req">*</span></label>
        <input type="number" class="form-control form-control-lg lt-amount-in" id="amount" name="amount" min="0.01" step="0.01" value="<?= h($v['amount']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="invested_on">Date invested <span class="lt-req">*</span></label>
        <input type="date" class="form-control" id="invested_on" name="invested_on" value="<?= h($v['invested_on']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="duration_months">Duration (months) <span class="lt-req">*</span></label>
        <input type="number" class="form-control" id="duration_months" name="duration_months" min="1" max="600" step="1" value="<?= h($v['duration_months']) ?>" required>
        <div class="form-text">Ends on <b id="cEnd">—</b></div>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="paid_by">Paid by <span class="lt-req">*</span></label>
        <select class="form-select" id="paid_by" name="paid_by">
          <?php foreach ($paidBy as $k => $l): ?><option value="<?= h($k) ?>" <?= $v['paid_by'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="reference">Reference</label>
        <input type="text" class="form-control" id="reference" name="reference" maxlength="100" value="<?= h($v['reference']) ?>" placeholder="Transfer / cheque no.">
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="proof">Proof of payment</label>
        <?php if ($inv && !empty($inv['proof_path'])): ?>
          <div class="small mb-1"><i class="bi bi-paperclip"></i> <a target="_blank" rel="noopener" href="<?= h($ltBase) ?>/investment_file.php?k=proof&id=<?= $id ?>"><?= h($inv['proof_name'] ?: 'Attached') ?></a></div>
        <?php endif; ?>
        <input type="file" class="form-control" id="proof" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp">
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold" for="purpose">Purpose</label>
        <input type="text" class="form-control" id="purpose" name="purpose" maxlength="500" value="<?= h($v['purpose']) ?>" placeholder="What the money is for">
      </div>
    </div>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3">Pay back / Payouts</div>

    <datalist id="afterUnits"><?php foreach (LT_INV_AFTER_UNITS as $uk => $ul): ?><option value="<?= $uk ?>"><?php endforeach; ?></datalist>
    <table class="table table-sm mb-2" style="max-width:760px">
      <thead><tr><th class="small" style="width:210px">Receive after</th><th class="small" style="width:160px">Date</th><th class="small" style="width:150px">Amount (AED)</th><th class="small">Custom note</th><th></th></tr></thead>
      <tbody id="planRows">
      <?php foreach ($planRows ?: [['after' => '', 'unit' => 'months', 'due_date' => '', 'amount' => '', 'note' => '']] as $i => $p): ?>
        <tr class="lt-plan-row">
          <td><div class="input-group input-group-sm"><input type="number" class="form-control" name="plan[<?= $i ?>][after]" min="0" max="3650" step="1" value="<?= h($p['after'] ?? '') ?>" placeholder="e.g. 3"><input type="text" class="form-control" name="plan[<?= $i ?>][unit]" list="afterUnits" maxlength="30" style="max-width:115px" value="<?= h($p['unit'] ?? 'months') ?>" title="Choose or type your own"></div></td>
          <td><input type="date" class="form-control form-control-sm" name="plan[<?= $i ?>][due_date]" value="<?= h($p['due_date']) ?>"></td>
          <td><input type="number" class="form-control form-control-sm" name="plan[<?= $i ?>][amount]" min="0.01" step="0.01" value="<?= h($p['amount']) ?>" placeholder="e.g. 5000"></td>
          <td><input type="text" class="form-control form-control-sm" name="plan[<?= $i ?>][note]" maxlength="150" value="<?= h($p['note']) ?>" placeholder="e.g. 1st payout, rent share…"></td>
          <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-plan><i class="bi bi-x-lg"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="d-flex flex-wrap align-items-center gap-3">
      <button type="button" class="btn btn-lt-ghost btn-sm" id="addPlan"><i class="bi bi-plus-lg me-1"></i>Add payout</button>
      <span class="small" id="cCustom"></span>
    </div>

    <div class="row g-3 mt-2">
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="return_mode">Paid by</label>
        <select class="form-select" id="return_mode" name="return_mode">
          <option value="">—</option>
          <?php foreach ($paidBy as $k => $l): ?><option value="<?= h($k) ?>" <?= $v['return_mode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold" for="return_status">Status</label>
        <select class="form-select" id="return_status" name="return_status">
          <option value="">—</option>
          <?php foreach ($retStatuses as $k => $l): ?><option value="<?= h($k) ?>" <?= $v['return_status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="mt-3">
      <div id="retRows"></div>
      <button type="button" class="btn btn-lt-ghost btn-sm" id="addRet"><i class="bi bi-plus-lg me-1"></i>Add a payout already received</button>
    </div>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3">Security</div>
    <?php foreach ($secRows as $s): ?>
      <label class="lt-old mb-2">
        <i class="bi <?= h($kinds[$s['kind']]['icon'] ?? 'bi-shield') ?>"></i>
        <span class="flex-grow-1"><b><?= h($kinds[$s['kind']]['label'] ?? $s['kind']) ?></b>
          <?= h(trim(implode(' · ', array_filter([$s['details'], $s['cheque_no'] ? 'No. ' . $s['cheque_no'] : '', $s['bank'], $s['amount'] !== null ? 'AED ' . lt_money($s['amount']) : '', $s['item_date'] ? lt_date($s['item_date']) : ''])))) ?></span>
        <span class="small text-danger"><input type="checkbox" name="drop_sec[]" value="<?= (int)$s['id'] ?>"> Remove</span>
      </label>
    <?php endforeach; ?>
    <div id="secRows"></div>
    <button type="button" class="btn btn-lt-ghost btn-sm mt-2" id="addSec"><i class="bi bi-plus-lg me-1"></i>Add security</button>
    <div class="form-text">Cheques (one row each), property, gold, a guarantor, an agreement… with a photo or PDF.</div>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <div class="lt-section mb-3">Documents</div>
    <?php foreach ($docRows as $d): ?>
      <label class="lt-old mb-2">
        <i class="bi bi-file-earmark"></i>
        <span class="flex-grow-1"><a target="_blank" rel="noopener" href="<?= h($ltBase) ?>/investment_file.php?k=doc&id=<?= (int)$d['id'] ?>"><?= h($d['title']) ?></a></span>
        <span class="small text-danger"><input type="checkbox" name="drop_doc[]" value="<?= (int)$d['id'] ?>"> Remove</span>
      </label>
    <?php endforeach; ?>
    <div id="docRows"></div>
    <button type="button" class="btn btn-lt-ghost btn-sm mt-2" id="addDoc"><i class="bi bi-plus-lg me-1"></i>Add document</button>
    <div class="form-text">Agreement, Emirates ID / passport, trade licence… PDF, JPG, PNG or WEBP, up to 10 MB each.</div>
  </div></div>

  <div class="card card-round mb-3"><div class="card-body p-4">
    <label class="form-label small fw-semibold" for="notes">Notes</label>
    <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000"><?= h($v['notes']) ?></textarea>
  </div></div>

  <div class="d-flex justify-content-between align-items-center">
    <a href="<?= h($ltBase) ?>/<?= $inv ? 'investment_view.php?id=' . $id : 'investments.php' ?>" class="btn btn-lt-ghost"><i class="bi bi-arrow-left me-1"></i> Back</a>
    <button type="submit" class="btn btn-lt"><i class="bi bi-check-lg me-1"></i><?= $inv ? 'Save changes' : 'Save investment' ?></button>
  </div>
</form>

<template id="secTpl">
  <div class="lt-sec-row mb-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-2"><label class="form-label small mb-1">What</label><select class="form-select form-select-sm" data-n="kind"><?= $secKindOptions ?></select></div>
      <div class="col-md-3"><label class="form-label small mb-1">Details</label><input type="text" class="form-control form-control-sm" data-n="details" maxlength="255" placeholder="Title deed no., gold weight, guarantor name…"></div>
      <div class="col-md-2" data-cheque><label class="form-label small mb-1">Cheque no.</label><input type="text" class="form-control form-control-sm" data-n="cheque_no" maxlength="50"></div>
      <div class="col-md-2" data-cheque><label class="form-label small mb-1">Bank</label><input type="text" class="form-control form-control-sm" data-n="bank" maxlength="100"></div>
      <div class="col-md-1"><label class="form-label small mb-1">AED</label><input type="number" class="form-control form-control-sm" data-n="amount" min="0" step="0.01"></div>
      <div class="col-md-2"><label class="form-label small mb-1">Date</label><input type="date" class="form-control form-control-sm" data-n="item_date"></div>
      <div class="col-md-10"><input type="file" class="form-control form-control-sm" data-f="sec_file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
      <div class="col-md-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove><i class="bi bi-x-lg"></i></button></div>
    </div>
  </div>
</template>
<template id="retTpl">
  <div class="lt-doc-row mb-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label small mb-1">Date received</label><input type="date" class="form-control form-control-sm" data-n="received_on"></div>
      <div class="col-md-3"><label class="form-label small mb-1">Amount (AED)</label><input type="number" class="form-control form-control-sm" data-n="amount" min="0.01" step="0.01"></div>
      <div class="col-md-5"><label class="form-label small mb-1">Proof</label><input type="file" class="form-control form-control-sm" data-f="ret_file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
      <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove><i class="bi bi-x-lg"></i></button></div>
    </div>
  </div>
</template>
<template id="docTpl">
  <div class="lt-doc-row mb-2">
    <div class="row g-2 align-items-center">
      <div class="col-md-5"><input type="text" class="form-control form-control-sm" data-n="doc_title" maxlength="150" placeholder="Title, e.g. Agreement"></div>
      <div class="col-md-6"><input type="file" class="form-control form-control-sm" data-f="doc_file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
      <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove><i class="bi bi-x-lg"></i></button></div>
    </div>
  </div>
</template>

<?php
$pageScripts = <<<'JS'
<script>
(function () {
  var f = document.getElementById('ltInv');
  var fmt = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function addMonths(iso, k) {
    var p = iso.split('-').map(Number); if (p.length !== 3 || !p[0]) { return '—'; }
    var y = p[0], m = p[1] - 1 + k, d = p[2];
    y += Math.floor(m / 12); m = ((m % 12) + 12) % 12;
    return String(Math.min(d, new Date(y, m + 1, 0).getDate())).padStart(2, '0') + ' ' + months[m] + ' ' + y;
  }
  function calc() {
    var to = (f.querySelector('[name=invest_to]:checked') || {}).value;
    f.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.dataset.for !== to; });
    var a = parseFloat(f.amount.value) || 0, n = parseInt(f.duration_months.value, 10) || 0;
    document.getElementById('cEnd').textContent = n > 0 && f.invested_on.value ? addMonths(f.invested_on.value, n) : '—';
    var big = document.getElementById('amountBig');
    big.hidden = !(a > 0); document.getElementById('amountBigVal').textContent = fmt(a);
    var pn = 0, pt = 0;
    document.querySelectorAll('#planRows tr').forEach(function (tr) {
      var v = parseFloat((tr.querySelector('[name$="[amount]"]') || {}).value) || 0;
      if (v > 0) { pn++; pt += v; }
    });
    document.getElementById('cCustom').innerHTML = pn ? 'Total: <b>AED ' + fmt(Math.round(pt * 100) / 100) + '</b> (' + pn + ' payout' + (pn === 1 ? '' : 's') + ')' : '';
  }
  f.addEventListener('input', calc);
  f.addEventListener('change', calc);
  calc();

  // Rows for security and documents; names carry the row number so each file stays with its row.
  var seq = 0;
  function addRow(tplId, boxId, prefix) {
    var i = seq++;
    var node = document.getElementById(tplId).content.firstElementChild.cloneNode(true);
    node.querySelectorAll('[data-n]').forEach(function (el) {
      el.name = (prefix === 'sec' || prefix === 'ret') ? prefix + '[' + i + '][' + el.dataset.n + ']' : el.dataset.n + '[' + i + ']';
    });
    node.querySelectorAll('[data-f]').forEach(function (el) { el.name = el.dataset.f + '[' + i + ']'; });
    node.querySelector('[data-remove]').addEventListener('click', function () { node.remove(); });
    var kind = node.querySelector('[data-n=kind]');
    if (kind) {
      var show = function () { node.querySelectorAll('[data-cheque]').forEach(function (el) { el.hidden = kind.value !== 'cheque'; }); };
      kind.addEventListener('change', show); show();
    }
    document.getElementById(boxId).appendChild(node);
  }
  document.getElementById('addSec').addEventListener('click', function () { addRow('secTpl', 'secRows', 'sec'); });
  document.getElementById('addDoc').addEventListener('click', function () { addRow('docTpl', 'docRows', 'doc'); });
  document.getElementById('addRet').addEventListener('click', function () { addRow('retTpl', 'retRows', 'ret'); });
  // Payouts: one row each
  var planSeq = 1000;
  function bindPlan(tr) {
    tr.querySelector('[data-remove-plan]').addEventListener('click', function () {
      if (document.querySelectorAll('#planRows tr').length > 1) { tr.remove(); } else { tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
      calc();
    });
  }
  document.querySelectorAll('#planRows tr').forEach(bindPlan);
  document.getElementById('planRows').addEventListener('input', function (e) {
    if (!e.target.name || !/\[(after|unit)\]$/.test(e.target.name)) { return; }
    afterToDate(e.target.closest('tr'));
  });
  document.getElementById('planRows').addEventListener('change', function (e) {
    if (e.target.name && /\[unit\]$/.test(e.target.name)) { afterToDate(e.target.closest('tr')); }
  });
  // "Receive after N days / weeks / months / years" fills the date from the date invested
  function afterToDate(tr) {
    var n = parseInt(tr.querySelector('[name$="[after]"]').value, 10), unit = tr.querySelector('[name$="[unit]"]').value.trim().toLowerCase(), start = f.invested_on.value;
    if (isNaN(n) || !start || ['days', 'weeks', 'months', 'years'].indexOf(unit) < 0) { return; } // a word of your own: set the date yourself
    var p = start.split('-').map(Number), out;
    if (unit === 'days' || unit === 'weeks') {
      out = new Date(p[0], p[1] - 1, p[2] + n * (unit === 'weeks' ? 7 : 1));
    } else {
      var k = unit === 'years' ? n * 12 : n, y = p[0], m = p[1] - 1 + k; y += Math.floor(m / 12); m = ((m % 12) + 12) % 12;
      out = new Date(y, m, Math.min(p[2], new Date(y, m + 1, 0).getDate()));
    }
    tr.querySelector('[name$="[due_date]"]').value = out.getFullYear() + '-' + String(out.getMonth() + 1).padStart(2, '0') + '-' + String(out.getDate()).padStart(2, '0');
  }
  document.getElementById('addPlan').addEventListener('click', function () {
    var i = planSeq++, tr = document.createElement('tr');
    tr.className = 'lt-plan-row';
    tr.innerHTML = '<td><div class="input-group input-group-sm"><input type="number" class="form-control" name="plan[' + i + '][after]" min="0" max="3650" step="1">'
      + '<input type="text" class="form-control" name="plan[' + i + '][unit]" list="afterUnits" maxlength="30" style="max-width:115px" value="months" title="Choose or type your own"></div></td>'
      + '<td><input type="date" class="form-control form-control-sm" name="plan[' + i + '][due_date]"></td>'
      + '<td><input type="number" class="form-control form-control-sm" name="plan[' + i + '][amount]" min="0.01" step="0.01"></td>'
      + '<td><input type="text" class="form-control form-control-sm" name="plan[' + i + '][note]" maxlength="150"></td>'
      + '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-plan><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('planRows').appendChild(tr); bindPlan(tr); tr.querySelector('input').focus();
  });
  calc();
})();
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
