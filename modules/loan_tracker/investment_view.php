<?php
/**
 * Loan Tracker — one investment: details, security, documents, returns received.
 * Record a return (with proof) and close the investment (with the reason). Owner only.
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
$id = (int)($_GET['id'] ?? 0);
$inv = lt_inv_get($conn, $id, $companyId);
if (!$inv) {
    header('Location: ' . $ltBase . '/investments.php');
    exit;
}
$paidBy = lt_inv_paid_by();
$kinds = lt_inv_security_kinds();
$self = $ltBase . '/investment_view.php?id=' . $id;
$errors = [];
$rv = ['received_on' => date('Y-m-d'), 'amount' => '', 'paid_by' => 'bank', 'reference' => '', 'notes' => ''];

$canEdit = lt_inv_can_edit($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    lt_inv_require_edit($conn, $self);
    $do = (string)($_POST['do'] ?? '');
    if ($inv['status'] !== 'active') {
        lt_flash('This investment is closed.', 'warning');
        header('Location: ' . $self);
        exit;
    }
    if ($do === 'note') {
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000);
        if ($note === '') {
            $errors[] = 'Please write the note.';
        } else {
            $conn->prepare("INSERT INTO lt_investment_notes (investment_id, note, created_by) VALUES (?,?,?)")->execute([$id, $note, current_user_id()]);
            lt_flash('Note added.');
            header('Location: ' . $self . '#timeline');
            exit;
        }
    } elseif ($do === 'return') {
        foreach ($rv as $k => $_) {
            $rv[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $amt = is_numeric($rv['amount']) ? round((float)$rv['amount'], 2) : 0.0;
        if ($amt <= 0) { $errors[] = 'Please enter the amount received.'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rv['received_on']) || $rv['received_on'] > date('Y-m-d')) { $errors[] = 'Please enter the date received (not in the future).'; }
        if (!isset($paidBy[$rv['paid_by']])) { $errors[] = 'Please choose how it was paid.'; }
        $file = $_FILES['proof'] ?? null;
        if ($e = lt_upload_error($file, 'Proof')) { $errors[] = $e; }
        if (!$errors) {
            $p = null;
            try {
                $p = lt_has_upload($file) ? lt_store_upload($file, 'inv' . $id) : null;
                $conn->prepare("INSERT INTO lt_investment_returns (investment_id, received_on, amount, paid_by, reference, notes, file_path, original_name, created_by)
                                VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$id, $rv['received_on'], $amt, $rv['paid_by'], $rv['reference'] ?: null, mb_substr($rv['notes'], 0, 200) ?: null,
                        $p, $p ? mb_substr(basename((string)$file['name']), 0, 255) : null, current_user_id()]);
                lt_flash('Payout of AED ' . lt_money($amt) . ' recorded.');
                header('Location: ' . $self);
                exit;
            } catch (Throwable $e) {
                if ($p) { @unlink(dirname(__DIR__, 2) . '/' . $p); }
                error_log('loan_tracker investment return: ' . $e->getMessage());
                $errors[] = 'The return could not be saved. Please try again.';
            }
        }
    } elseif ($do === 'close') {
        $note = trim((string)($_POST['close_note'] ?? ''));
        if ($note === '') {
            $errors[] = 'Please write why the investment is closed (e.g. "Amount returned in full on …").';
        } else {
            $conn->prepare("UPDATE lt_investments SET status = 'closed', closed_at = NOW(), closed_by = ?, close_note = ? WHERE id = ? AND company_id = ? AND status = 'active'")
                ->execute([current_user_id(), mb_substr($note, 0, 255), $id, $companyId]);
            lt_flash('Investment closed.');
            header('Location: ' . $self);
            exit;
        }
    }
}

$q = $conn->prepare("SELECT * FROM lt_investment_security WHERE investment_id = ? ORDER BY id");
$q->execute([$id]);
$sec = $q->fetchAll(PDO::FETCH_ASSOC);
$q = $conn->prepare("SELECT * FROM lt_investment_documents WHERE investment_id = ? ORDER BY id");
$q->execute([$id]);
$docs = $q->fetchAll(PDO::FETCH_ASSOC);
$q = $conn->prepare("SELECT r.*, COALESCE(NULLIF(u.fullname,''), u.username) AS by_name FROM lt_investment_returns r LEFT JOIN user u ON u.id = r.created_by
                      WHERE r.investment_id = ? ORDER BY r.received_on, r.id");
$q->execute([$id]);
$rets = $q->fetchAll(PDO::FETCH_ASSOC);
$received = array_sum(array_map(fn($r) => (float)$r['amount'], $rets));
$expected = lt_inv_expected($inv);
$plan = $inv['return_type'] === 'custom' ? lt_inv_plan($conn, $id) : [];
$events = lt_inv_timeline($conn, $inv, $ltBase);
// the next payout in the plan, to fill the payout form
$nextPlan = null; $cum = 0.0;
foreach ($plan as $p) { $cum += (float)$p['amount']; if ($cum > $received + 0.005) { $nextPlan = $p; break; } }
$t = lt_inv_to()[$inv['invest_to']];
$end = lt_inv_end_state($inv);

$pageTitle = lt_inv_no($id);
$pageStyles = '
  .lt-kv{ display:flex; justify-content:space-between; gap:1rem; padding:.45rem 0; border-top:1px solid var(--lt-tint); }
  .lt-kv:first-child{ border-top:0; }
  .lt-kv span:first-child{ color:var(--lt-muted); }
  .lt-big{ border-radius:14px; padding:.9rem 1.1rem; height:100%; }
  .lt-big small{ display:block; font-weight:600; opacity:.85; }
  .lt-hero{ background:var(--lt-tint-2); border:1px solid var(--lt-tint-3); border-left:6px solid var(--primary); border-radius:16px; padding:1.1rem 1.4rem; color:var(--primary-dark); }
  .lt-hero small{ display:block; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; opacity:.8; }
  .lt-tl-memo .lt-tl-dot{ background:#64748b; }
  .lt-hero-amt{ font-size:2.6rem; font-weight:800; line-height:1.1; font-variant-numeric:tabular-nums; }
  .lt-big b{ font-size:1.35rem; font-variant-numeric:tabular-nums; }
  .lt-end{ border-radius:99px; padding:.25rem .75rem; font-size:.8rem; font-weight:700; }
  .lt-end.late{ background:#dc2626; color:#fff; } .lt-end.soon{ background:#fef3c7; color:#92400e; } .lt-end.done{ background:#dcfce7; color:#166534; }
  .lt-item{ display:flex; gap:.75rem; align-items:flex-start; padding:.65rem 0; border-top:1px solid var(--lt-tint); }
  .lt-item:first-child{ border-top:0; }
  .lt-item i.bi{ color:var(--primary); font-size:1.1rem; margin-top:.1rem; }
';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="lt-sub"><a href="<?= h($ltBase) ?>/investments.php" class="text-decoration-none"><i class="bi bi-arrow-left"></i> Investments</a></div>
    <h1 class="lt-title mt-1"><?= h($inv['name']) ?></h1>
    <p class="lt-sub"><i class="bi <?= h($t['icon']) ?>" style="color:<?= h($t['color']) ?>"></i> <?= h($t['label']) ?> · <?= h(lt_inv_no($id)) ?>
      <?php if ($end['text'] !== ''): ?> <span class="lt-end <?= h($end['class']) ?> ms-1"><?= h($end['text']) ?></span><?php endif; ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-lt-ghost" data-bs-toggle="collapse" data-bs-target="#invDetails" aria-expanded="false" aria-controls="invDetails"><i class="bi bi-card-list me-1"></i> View details</button>
    <?php if ($inv['status'] === 'active' && $canEdit): ?>
      <a href="<?= h($ltBase) ?>/investment_form.php?id=<?= $id ?>" class="btn btn-lt-ghost"><i class="bi bi-pencil me-1"></i> Change</a>
      <button type="button" class="btn btn-lt" data-bs-toggle="collapse" data-bs-target="#ret" aria-expanded="false" aria-controls="ret"><i class="bi bi-plus-lg me-1"></i> Add note / payout</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="lt-hero mb-3">
  <small>Amount invested</small>
  <div class="lt-hero-amt">AED <?= lt_money($inv['amount']) ?></div>
  <div class="small">on <?= h(lt_date($inv['invested_on'])) ?> · <?= (int)$inv['duration_months'] ?> months · ends <?= h(lt_date($inv['end_date'])) ?></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="lt-big" style="background:#dcfce7;color:#166534"><small>Payouts received</small><b>AED <?= lt_money($received) ?></b></div></div>
  <div class="col-md-6"><div class="lt-big" style="background:#fef3c7;color:#92400e"><small>Payouts still to come</small><b>AED <?= lt_money(max(0, $expected - $received)) ?></b><div class="small">of AED <?= lt_money($expected) ?> expected</div></div></div>
</div>

<div id="invPanels">
    <?php if ($inv['status'] === 'active' && $canEdit): ?>
      <div class="collapse<?= $errors && in_array($_POST['do'] ?? '', ['note', 'return'], true) ? ' show' : '' ?>" id="ret" data-bs-parent="#invPanels">
      <div class="card card-round mb-3" style="max-width:760px"><div class="card-body p-4">
        <div class="lt-section mb-2">Add a note or payout</div>
        <ul class="nav nav-pills nav-fill mb-3 small" role="tablist">
          <li class="nav-item"><button type="button" class="nav-link <?= ($_POST['do'] ?? '') === 'return' ? '' : 'active' ?>" data-bs-toggle="pill" data-bs-target="#addNote"><i class="bi bi-chat-left-text me-1"></i>Note</button></li>
          <li class="nav-item"><button type="button" class="nav-link <?= ($_POST['do'] ?? '') === 'return' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#addPay"><i class="bi bi-arrow-down-circle me-1"></i>Payout received</button></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade <?= ($_POST['do'] ?? '') === 'return' ? '' : 'show active' ?>" id="addNote">
            <form method="post">
              <?php csrf_field(); ?><input type="hidden" name="do" value="note">
              <textarea class="form-control mb-2" name="note" rows="3" maxlength="1000" placeholder="Anything to remember: a call, a promise, a change…" required></textarea>
              <div class="text-end"><button class="btn btn-lt"><i class="bi bi-check-lg me-1"></i>Save note</button></div>
            </form>
          </div>
          <div class="tab-pane fade <?= ($_POST['do'] ?? '') === 'return' ? 'show active' : '' ?>" id="addPay">
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?php csrf_field(); ?><input type="hidden" name="do" value="return">
          <div class="col-6"><label class="form-label small fw-semibold">Date received</label><input type="date" class="form-control" name="received_on" max="<?= date('Y-m-d') ?>" value="<?= h($rv['received_on']) ?>" required></div>
          <div class="col-6"><label class="form-label small fw-semibold">Amount (AED)</label><input type="number" class="form-control" name="amount" min="0.01" step="0.01" value="<?= h($rv['amount'] !== '' ? $rv['amount'] : ($nextPlan ? number_format((float)$nextPlan['amount'], 2, '.', '') : '')) ?>" required></div>
          <div class="col-6"><label class="form-label small fw-semibold">Paid by</label><select class="form-select" name="paid_by"><?php foreach ($paidBy as $k => $l): ?><option value="<?= h($k) ?>" <?= $rv['paid_by'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label small fw-semibold">Reference</label><input type="text" class="form-control" name="reference" maxlength="100" value="<?= h($rv['reference']) ?>"></div>
          <div class="col-12"><label class="form-label small fw-semibold">Notes</label><input type="text" class="form-control" name="notes" maxlength="200" value="<?= h($rv['notes']) ?>"></div>
          <div class="col-12"><label class="form-label small fw-semibold">Proof</label><input type="file" class="form-control" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
          <div class="col-12 text-end"><button class="btn btn-lt"><i class="bi bi-check-lg me-1"></i>Save payout</button></div>
        </form>
          </div>
        </div>
      </div></div>
      </div>
    <?php endif; ?>
<div class="collapse" id="invDetails" data-bs-parent="#invPanels">
  <div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card card-round h-100"><div class="card-body p-4">
      <div class="lt-section mb-2">Details</div>
      <div class="lt-kv"><span>Date invested</span><span><?= h(lt_date($inv['invested_on'])) ?></span></div>
      <div class="lt-kv"><span>Duration</span><span><?= (int)$inv['duration_months'] ?> months · ends <?= h(lt_date($inv['end_date'])) ?></span></div>
      <div class="lt-kv"><span>Pay back</span><span><?= h(lt_inv_return_text($inv)) ?></span></div>
      <?php if (!empty($inv['return_mode'])): ?><div class="lt-kv"><span>Paid out by</span><span><?= h($paidBy[$inv['return_mode']] ?? '') ?></span></div><?php endif; ?>
      <?php if (!empty($inv['return_status'])): ?><div class="lt-kv"><span>Payout status</span><span><?= h(lt_inv_return_statuses()[$inv['return_status']] ?? '') ?></span></div><?php endif; ?>
      <div class="lt-kv"><span>Paid by</span><span><?= h($paidBy[$inv['paid_by']] ?? '') ?><?= $inv['reference'] ? ' · Ref ' . h($inv['reference']) : '' ?>
        <?php if (!empty($inv['proof_path'])): ?> · <a target="_blank" rel="noopener" href="<?= h($ltBase) ?>/investment_file.php?k=proof&id=<?= $id ?>"><i class="bi bi-paperclip"></i> proof</a><?php endif; ?></span></div>
      <?php if ($inv['purpose']): ?><div class="lt-kv"><span>Purpose</span><span><?= h($inv['purpose']) ?></span></div><?php endif; ?>
      <?php if ($inv['contact_name']): ?><div class="lt-kv"><span>Contact person</span><span><?= h($inv['contact_name']) ?></span></div><?php endif; ?>
      <?php if ($inv['phone']): ?><div class="lt-kv"><span>Phone</span><span><?= h($inv['phone']) ?></span></div><?php endif; ?>
      <?php if ($inv['email']): ?><div class="lt-kv"><span>Email</span><span><?= h($inv['email']) ?></span></div><?php endif; ?>
      <?php if ($inv['notes']): ?><div class="lt-kv"><span>Notes</span><span><?= nl2br(h($inv['notes'])) ?></span></div><?php endif; ?>
      <div class="lt-kv"><span>Added by</span><span><?= h($inv['created_by_name'] ?: '—') ?> · <?= h(lt_date($inv['created_at'])) ?></span></div>
      <?php if ($inv['status'] === 'closed'): ?>
        <div class="lt-kv"><span>Closed</span><span><?= h(lt_date($inv['closed_at'])) ?> by <?= h($inv['closed_by_name'] ?: '—') ?> · <?= h($inv['close_note']) ?></span></div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card card-round h-100"><div class="card-body p-4">
      <div class="lt-section mb-2">Security</div>
      <?php if (!$sec): ?><div class="text-muted">No security recorded.</div><?php endif; ?>
      <?php foreach ($sec as $s): $k = $kinds[$s['kind']] ?? ['label' => $s['kind'], 'icon' => 'bi-shield']; ?>
        <div class="lt-item"><i class="bi <?= h($k['icon']) ?>"></i>
          <div class="flex-grow-1"><b><?= h($k['label']) ?></b>
            <div class="small text-muted"><?= h(implode(' · ', array_filter([$s['details'], $s['cheque_no'] ? 'No. ' . $s['cheque_no'] : '', $s['bank'],
                $s['amount'] !== null ? 'AED ' . lt_money($s['amount']) : '', $s['item_date'] ? lt_date($s['item_date']) : '']))) ?: '—' ?></div></div>
          <?php if ($s['file_path']): ?><a class="small" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/investment_file.php?k=sec&id=<?= (int)$s['id'] ?>"><i class="bi bi-paperclip"></i> View</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card card-round h-100"><div class="card-body p-4">
      <div class="lt-section mb-2">Documents</div>
      <?php if (!$docs): ?><div class="text-muted">No documents.</div><?php endif; ?>
      <?php foreach ($docs as $d): ?>
        <div class="lt-item"><i class="bi bi-file-earmark"></i><div class="flex-grow-1"><?= h($d['title']) ?></div>
          <a class="small" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/investment_file.php?k=doc&id=<?= (int)$d['id'] ?>"><i class="bi bi-paperclip"></i> View</a></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  </div>
</div>
</div>

<?php if ($plan): $states = lt_inv_plan_states($plan, $received, $inv['status'] === 'active');
  $stLabel = ['received' => ['Received', '#dcfce7', '#166534'], 'part' => ['Part received', '#dbeafe', '#1e40af'], 'overdue' => ['Overdue', '#fee2e2', '#991b1b'], 'due' => ['Due', '#fef3c7', '#92400e']]; ?>
  <div class="card card-round mb-3"><div class="card-body p-0">
    <div class="d-flex justify-content-between align-items-center px-4 pt-3 pb-2">
      <div class="lt-section mb-0">Payout plan</div>
      <span class="small text-muted"><?= count($states) ?> payout<?= count($states) === 1 ? '' : 's' ?> · AED <?= lt_money($expected) ?></span>
    </div>
    <div class="table-responsive">
      <table class="table lt-table mb-0">
        <thead><tr><th>#</th><th>Date</th><th>Receive after</th><th class="text-end">Amount (AED)</th><th class="text-end">Received (AED)</th><th>Status</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($states as $n => $p): [$sl, $sb, $sf] = $stLabel[$p['state']]; ?>
          <tr>
            <td class="text-muted"><?= $n + 1 ?></td>
            <td><?= h(lt_date($p['due_date'])) ?></td>
            <td class="text-muted"><?= h(ucfirst(lt_inv_after_text($p['after_months'], $p['after_unit'] ?? 'months')) ?: '—') ?></td>
            <td class="text-end fw-semibold" style="font-variant-numeric:tabular-nums"><?= lt_money($p['amount']) ?></td>
            <td class="text-end" style="font-variant-numeric:tabular-nums"><?= $p['got'] > 0 ? lt_money($p['got']) : '—' ?></td>
            <td><span class="lt-badge" style="background:<?= $sb ?>;color:<?= $sf ?>"><?= $sl ?></span></td>
            <td class="small"><?= h($p['note'] ?: '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="fw-semibold">Total</td><td class="text-end fw-bold" style="font-variant-numeric:tabular-nums"><?= lt_money($expected) ?></td><td class="text-end fw-bold" style="font-variant-numeric:tabular-nums"><?= lt_money(min($received, $expected)) ?></td><td colspan="2"></td></tr></tfoot>
      </table>
    </div>
  </div></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">

    <div class="card card-round mb-3"><div class="card-body p-4">
      <div class="lt-section mb-2">Payouts received</div>
      <?php if (!$rets): ?><div class="text-muted">Nothing received yet.</div><?php endif; ?>
      <?php foreach ($rets as $r): ?>
        <div class="lt-item"><i class="bi bi-arrow-down-circle" style="color:#16a34a"></i>
          <div class="flex-grow-1"><b>AED <?= lt_money($r['amount']) ?></b> <span class="text-muted">· <?= h(lt_date($r['received_on'])) ?></span>
            <div class="small text-muted"><?= h(trim(($paidBy[$r['paid_by']] ?? '') . ($r['reference'] ? ' · Ref ' . $r['reference'] : '') . ($r['notes'] ? ' · ' . $r['notes'] : '') . ($r['by_name'] ? ' · recorded by ' . $r['by_name'] : ''))) ?></div></div>
          <?php if ($r['file_path']): ?><a class="small" target="_blank" rel="noopener" href="<?= h($ltBase) ?>/investment_file.php?k=ret&id=<?= (int)$r['id'] ?>"><i class="bi bi-paperclip"></i> Proof</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($rets): ?><div class="lt-kv mt-2"><span><b>Total received</b></span><span><b>AED <?= lt_money($received) ?></b></span></div><?php endif; ?>
    </div></div>

    <?php if ($inv['status'] === 'active' && $canEdit): ?>

      <div class="card card-round mb-3"><div class="card-body p-4">
        <div class="lt-section mb-2">Close the investment</div>
        <form method="post" class="d-flex flex-wrap gap-2" onsubmit="return confirm('Close this investment? It cannot be changed after this.');">
          <?php csrf_field(); ?><input type="hidden" name="do" value="close">
          <input type="text" class="form-control" style="flex:1;min-width:220px" name="close_note" maxlength="255" placeholder="Why, e.g. Amount returned in full on 01 Jan 2027" required>
          <button class="btn btn-lt-ghost"><i class="bi bi-lock me-1"></i>Close</button>
        </form>
      </div></div>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <div class="card card-round mb-3" id="timeline"><div class="card-body p-4">
      <div class="lt-section mb-3">Timeline</div>
      <?php lt_render_timeline($events); ?>
    </div></div>
  </div>
</div>

<?php require __DIR__ . '/includes/lt_layout_footer.php';
