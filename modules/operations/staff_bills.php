<?php
/**
 * Operations — Staff Bills: every bill (or money) handed from person to person
 * in the staff app (Bills), with who has it now and the whole chain — who gave
 * it to whom, on which day, accepted or rejected and why, edited, kept.
 *
 * Owner and Admin only. All companies by default (filter by company).
 * A record only — nothing here is posted to the accounts.
 * Download (Excel) gives what is shown, one row per bill (?download=1).
 * Rules: includes/staff_bills.php. Tables: migrations/staff_bills.sql.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/../../includes/staff_bills.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);
if (!array_intersect(current_user_roles($conn), ['Owner', 'Admin'])) {
    http_response_code(403);
    exit('Only the Owner or an Admin can see staff bills.');
}

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$userId = (int)current_user_id();

$ready = bill_tables_ready($conn);

// Accept or reject, from here, a bill that is waiting for the person signed in —
// the same as doing it on the phone (same rules, same history, same notification).
// Delete or decline: only bills whose starter asked for it to be deleted (in the
// staff app), by the Owner or an Admin. A deleted bill is discarded, not erased.
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $me = ['user_id' => $userId, 'name' => (string)(bill_user_name($conn, $userId) ?: 'User #' . $userId), 'office' => false];
    $billId = (int)($_POST['bill_id'] ?? 0);
    $do = (string)($_POST['do'] ?? '');
    try {
        $result = match ($do) {
            'accept' => bill_accept($conn, $me, $billId),
            'reject' => bill_reject($conn, $me, $billId, $_POST['reason'] ?? ''),
            'discard' => bill_discard($conn, $me, $billId, $_POST['reason'] ?? ''),
            'decline' => bill_decline_delete($conn, $me, $billId, $_POST['reason'] ?? ''),
            default => throw new BillError('Unknown action.'),
        };
        if ($result === null || $result[0] === null) {
            throw new BillError('That bill is not there any more.');
        }
        bill_notify($conn, $result[1]);
        ops_flash('Bill #' . $billId . match ($do) {
            'accept' => ' accepted. It is with you now.',
            'reject' => ' rejected. It went back to the person who gave it.',
            'decline' => ': delete request declined. The bill stays; the person who asked has been told.',
            default => ' deleted. It is gone from the staff app; it stays here under Status: Discarded.',
        });
    } catch (BillError $e) {
        ops_flash('Bill #' . $billId . ': ' . $e->getMessage(), 'danger');
    }
    $back = (string)($_POST['back'] ?? '');
    header('Location: staff_bills.php' . (str_starts_with($back, '?') ? $back : ''));
    exit;
}

$f = [
    'from' => $_GET['from'] ?? null,
    'to' => $_GET['to'] ?? null,
    'person' => (int)($_GET['person'] ?? 0),
    'company' => (int)($_GET['company'] ?? 0),
    'status' => (string)($_GET['status'] ?? ''),
    'q' => (string)($_GET['q'] ?? ''),
];
$data = $ready ? bill_list_all($conn, $f) : ['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'bills' => [], 'total' => 0, 'people' => [], 'companies' => []];
$bills = $data['bills'];
$waiting = count(array_filter($bills, static fn(array $b): bool => $b['status'] === 'waiting'));
$delRequests = count(array_filter($bills, static fn(array $b): bool => $b['delete_request'] !== null && $b['status'] !== 'discarded'));
$filtered = $f['status'] === 'delete_requested' || $f['person'] > 0 || $f['company'] > 0 || isset(BILL_STATUSES[$f['status']]) || trim($f['q']) !== '' || !empty($_GET['from']) || !empty($_GET['to']);

$fmtDay = static fn(?string $d): string => $d ? date('d-m-Y', strtotime($d)) : '';
$fmtStamp = static fn(?string $d): string => $d ? date('d-m-Y h:i A', strtotime($d)) : '';

/** One history entry in words: [text, extra lines]. Shared by the table and the download. */
$eventText = static function (array $ev) use ($fmtDay): array {
    $changes = array_map(static fn(array $c): string => $c[0] . ': ' . ($c[1] !== '' ? $c[1] . ' → ' : '') . ($c[2] !== '' ? $c[2] : '(empty)'), $ev['changes'] ?? []);
    return match ($ev['kind']) {
        'started' => [$ev['by'] . ' started it', []],
        'given' => [$ev['by'] . ' → ' . $ev['to'] . ' · given ' . $fmtDay($ev['given_on']), []],
        'accepted' => [$ev['by'] . ' accepted', []],
        'rejected' => [$ev['by'] . ' rejected', ['Reason: ' . ($ev['reason'] ?? '')]],
        'edited' => [$ev['by'] . ' changed it', array_merge($changes, ['Reason: ' . ($ev['reason'] ?? '')])],
        'kept' => [$ev['by'] . ' kept it', []],
        'discarded' => ['Deleted by ' . $ev['by'] . ' (office)', ['Reason: ' . ($ev['reason'] ?? '')]],
        'delete_requested' => [$ev['by'] . ' asked to delete it', ['Reason: ' . ($ev['reason'] ?? '')]],
        'note' => [$ev['by'] . ' added a note', ['Note: ' . ($ev['reason'] ?? '')]],
        'delete_declined' => ['Delete request declined by ' . $ev['by'], $ev['reason'] ? ['Reason: ' . $ev['reason']] : []],
        'waiting' => ['Waiting for ' . $ev['by'] . ' to accept', []],
        default => [$ev['kind'], []],
    };
};

/** The whole history in one cell of text, for the download. */
$chainText = static function (array $b) use ($eventText, $fmtStamp): string {
    return implode("\n", array_map(static function (array $ev) use ($eventText, $fmtStamp): string {
        [$text, $extra] = $eventText($ev);
        return $text . ($ev['at'] ? ' (' . $fmtStamp($ev['at']) . ')' : '') . ($extra ? ' — ' . implode('; ', $extra) : '');
    }, $b['events']));
};

if ($ready && ($_GET['download'] ?? '') === '1') {
    $rows = array_map(static fn(array $b): array => [
        $b['id'], $fmtDay($b['received_on']), $b['amount'], $b['by']['name'], $b['holder']['name'],
        BILL_STATUSES[$b['status']] ?? $b['status'], $b['notes'] ?? '', count($b['files']), $chainText($b),
    ], $bills);
    ops_send_csv('staff_bills_' . $data['from'] . ($data['to'] !== $data['from'] ? '_to_' . $data['to'] : '') . '.csv',
        ['Bill #', 'Date received', 'Amount (AED)', 'Started by', 'With now', 'Status', 'Notes', 'Attachments', 'History'],
        $rows);
}

$statusBadge = static function (array $b): string {
    $cls = ['waiting' => 'bg-warning-subtle text-warning-emphasis', 'with' => 'bg-primary-subtle text-primary-emphasis', 'kept' => 'bg-success-subtle text-success-emphasis', 'discarded' => 'bg-secondary text-white'][$b['status']] ?? 'bg-light text-dark';
    $text = BILL_STATUSES[$b['status']] ?? $b['status'];
    if ($b['returned']) {
        $cls = 'bg-danger-subtle text-danger-emphasis';
        $text = 'Rejected, back with giver';
    } elseif ($b['status'] === 'with' && !$b['moves']) {
        $cls = 'bg-light text-dark border';
        $text = 'Not given yet';
    }
    return '<span class="badge ' . $cls . '">' . h($text) . '</span>';
};

$pageTitle = 'Staff Bills';
$billCompanyName = '';
foreach ($data['companies'] as $c) {
    if ((int)$c['id'] === $f['company']) {
        $billCompanyName = (string)$c['name'];
    }
}
$pageStyles = '
  .b-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .b-row { cursor: pointer; }
  .b-tl { list-style: none; margin: 0; padding: 0; }
  .b-tl li { position: relative; padding: 0 0 .35rem 1.25rem; }
  .b-tl li:last-child { padding-bottom: 0; }
  .b-tl li:before { content: ""; position: absolute; left: .2rem; top: .45rem; width: .5rem; height: .5rem; border-radius: 50%; background: #6c757d; }
  .b-tl li.ok:before { background: #198754; } .b-tl li.no:before { background: #dc3545; } .b-tl li.wait:before { background: #fd7e14; }
  .b-thumb { width: 96px; height: 96px; object-fit: cover; border-radius: .375rem; border: 1px solid #dee2e6; }
  .b-table { border-color: #e3e6ea; }
  .b-table > :not(caption) > * > * { border-color: #e3e6ea; padding: .55rem .65rem; }
  .b-table thead th { background: #f4f6f8; font-weight: 600; white-space: nowrap; vertical-align: middle; }
  .b-table td.b-actions { width: 1%; white-space: nowrap; text-align: center; }';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-4">
  <div class="page-header-label">Staff Bills</div>
  <div class="text-muted small">
    <?= h($billCompanyName !== '' ? $billCompanyName : 'All companies') ?> · bills and money handed from person to person in the staff app (Bills).
    A record only: nothing here is posted to the accounts.
  </div>
</div>
  <?php if (!$ready): ?>
    <div class="alert alert-warning">The bill tables are not set up yet. Run migrations/staff_bills.sql.</div>
  <?php else: ?>
  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-lg">
          <label class="form-label small mb-1" for="bFrom">Received from</label>
          <input type="date" class="form-control form-control-sm" id="bFrom" name="from" value="<?= h($data['from']) ?>">
        </div>
        <div class="col-6 col-lg">
          <label class="form-label small mb-1" for="bTo">To</label>
          <input type="date" class="form-control form-control-sm" id="bTo" name="to" value="<?= h($data['to']) ?>">
        </div>
        <div class="col-6 col-lg">
          <label class="form-label small mb-1" for="bCompany">Company</label>
          <select class="form-select form-select-sm" id="bCompany" name="company">
            <option value="">All companies</option>
            <?php foreach ($data['companies'] as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $f['company'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-lg">
          <label class="form-label small mb-1" for="bPerson">Person</label>
          <select class="form-select form-select-sm" id="bPerson" name="person">
            <option value="">Everyone</option>
            <?php foreach ($data['people'] as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $f['person'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-lg">
          <label class="form-label small mb-1" for="bStatus">Status</label>
          <select class="form-select form-select-sm" id="bStatus" name="status">
            <option value="">All</option>
            <?php foreach (BILL_STATUSES as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
            <option value="delete_requested" <?= $f['status'] === 'delete_requested' ? 'selected' : '' ?>>Delete requested</option>
          </select>
        </div>
        <div class="col-6 col-lg">
          <label class="form-label small mb-1" for="bQ">Search</label>
          <input type="search" class="form-control form-control-sm" id="bQ" name="q" value="<?= h($f['q']) ?>" placeholder="Notes, name or bill #">
        </div>
        <div class="col-12 col-lg-auto text-nowrap">
          <button class="btn btn-sm btn-primary">Show</button>
          <?php if ($filtered): ?><a href="staff_bills.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
          <a href="?<?= h(http_build_query(array_merge($_GET, ['download' => '1']))) ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        </div>
      </form>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-3 mb-3">
    <?php $billTotal = (float)$data['total']; /* 100, or 1,250.50 when there are fils */ ?>
    <div class="card px-3 py-2 justify-content-center bg-primary-subtle border-primary-subtle text-primary-emphasis"><div class="fs-5 fw-semibold">Total: <?= number_format($billTotal, fmod($billTotal, 1.0) == 0.0 ? 0 : 2) ?></div></div>
    <div class="card px-3 py-2 justify-content-center bg-success-subtle border-success-subtle text-success-emphasis"><div class="fs-5 fw-semibold">Bills: <?= count($bills) ?></div></div>
    <div class="card px-3 py-2 justify-content-center bg-warning-subtle border-warning-subtle text-warning-emphasis"><div class="fs-5 fw-semibold">Waiting to accept: <?= $waiting ?></div></div>
    <a href="?status=delete_requested" class="card px-3 py-2 justify-content-center bg-danger-subtle border-danger-subtle text-danger-emphasis text-decoration-none"><div class="fs-5 fw-semibold">Delete requests: <?= $delRequests ?></div></a>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover table-bordered align-middle mb-0 b-table">
        <thead><tr>
          <th style="width:110px">Received</th>
          <th class="text-end" style="width:120px">Amount (AED)</th>
          <th style="width:170px">With now</th>
          <th style="width:150px">Status</th>
          <th style="min-width:340px">Timeline</th>
          <th style="width:200px">Notes</th>
          <th style="width:1%"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($bills as $b): ?>
          <tr class="b-row" data-bs-toggle="collapse" data-bs-target="#bill<?= (int)$b['id'] ?>" aria-expanded="false">
            <td class="text-nowrap align-top"><?= h($fmtDay($b['received_on'])) ?></td>
            <td class="b-num align-top"><?= number_format($b['amount'], 2) ?></td>
            <td class="align-top"><?= h($b['holder']['name']) ?></td>
            <td class="align-top">
              <?= $statusBadge($b) ?>
              <?php if ($b['delete_request'] !== null && $b['status'] !== 'discarded'): ?>
              <div class="mt-2" onclick="event.stopPropagation()">
                <span class="badge bg-danger">Delete requested</span>
                <div class="small mt-1"><?= h($b['delete_request']['by']['name']) ?>: <?= h($b['delete_request']['reason']) ?></div>
                <div class="mt-1 d-flex gap-1 flex-wrap">
                  <button type="button" class="btn btn-sm btn-danger"
                          onclick="billDelete(<?= (int)$b['id'] ?>, <?= h(json_encode(number_format($b['amount'], 2) . ' · ' . $b['holder']['name'])) ?>, <?= h(json_encode($b['delete_request']['reason'])) ?>)"><i class="bi bi-trash me-1"></i>Delete</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#dec<?= (int)$b['id'] ?>">Decline</button>
                </div>
                <form method="post" class="collapse mt-2" id="dec<?= (int)$b['id'] ?>">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="do" value="decline">
                  <input type="hidden" name="back" value="<?= h('?' . http_build_query($_GET)) ?>">
                  <input type="text" name="reason" class="form-control form-control-sm mb-1" maxlength="255" placeholder="Why not? (optional)">
                  <button class="btn btn-sm btn-secondary">Decline request</button>
                </form>
              </div>
              <?php endif; ?>
              <?php if ($b['status'] === 'waiting' && $b['holder']['user_id'] === $userId): ?>
              <div class="mt-2" onclick="event.stopPropagation()">
                <div class="small fw-semibold text-warning-emphasis mb-1">Waiting for you</div>
                <form method="post" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="do" value="accept">
                  <input type="hidden" name="back" value="<?= h('?' . http_build_query($_GET)) ?>">
                  <button class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Accept</button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rej<?= (int)$b['id'] ?>">Reject</button>
                <form method="post" class="collapse mt-2" id="rej<?= (int)$b['id'] ?>">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="do" value="reject">
                  <input type="hidden" name="back" value="<?= h('?' . http_build_query($_GET)) ?>">
                  <input type="text" name="reason" class="form-control form-control-sm mb-1" maxlength="255" placeholder="Reason (needed)" required>
                  <button class="btn btn-sm btn-danger">Reject and send back</button>
                </form>
              </div>
              <?php endif; ?>
            </td>
            <td class="align-top">
              <ul class="b-tl small">
                <?php foreach ($b['events'] as $ev): [$evText, $evExtra] = $eventText($ev); ?>
                  <li class="<?= ['accepted' => 'ok', 'kept' => 'ok', 'rejected' => 'no', 'waiting' => 'wait', 'edited' => 'wait', 'discarded' => 'no', 'delete_requested' => 'no', 'delete_declined' => 'ok'][$ev['kind']] ?? '' ?>">
                    <?= h($evText) ?><?php if ($ev['at']): ?> <span class="text-muted">· <?= h($fmtStamp($ev['at'])) ?></span><?php endif; ?>
                    <?php foreach ($evExtra as $line): ?><div class="<?= in_array($ev['kind'], ['rejected', 'discarded', 'delete_requested'], true) ? 'text-danger-emphasis' : '' ?>"><?= h($line) ?></div><?php endforeach; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </td>
            <td class="align-top" style="max-width:200px"><div class="text-truncate" title="<?= h($b['notes'] ?? '') ?>"><?= h($b['notes'] ?? '') ?></div></td>
            <td class="align-top text-muted small b-actions">
              <?php if ($b['files']): ?><i class="bi bi-paperclip"></i><?= count($b['files']) ?> <?php endif; ?><i class="bi bi-chevron-down"></i>
              <?php if ($b['status'] !== 'discarded' && $b['delete_request'] !== null): /* only when the starter asked */ ?>
                <button type="button" class="btn btn-sm btn-outline-danger ms-2" title="Delete bill #<?= (int)$b['id'] ?>"
                        onclick="event.stopPropagation(); billDelete(<?= (int)$b['id'] ?>, <?= h(json_encode(number_format($b['amount'], 2) . ' · ' . $b['holder']['name'])) ?>, <?= h(json_encode($b['delete_request']['reason'])) ?>)">
                  <i class="bi bi-trash"></i>
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="collapse" id="bill<?= (int)$b['id'] ?>">
            <td colspan="7" class="bg-light">
              <div class="row g-3 py-2">
                <?php if ($b['notes']): ?>
                <div class="col-md-6">
                  <div class="fw-semibold mb-2">Notes</div>
                  <div class="small"><?= nl2br(h($b['notes'])) ?></div>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                  <div class="fw-semibold mb-2">Attachment</div>
                  <?php if (!$b['files']): ?><div class="small text-muted">No attachment.</div><?php endif; ?>
                  <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($b['files'] as $fl): ?>
                      <?php $url = 'staff_bill_file.php?id=' . (int)$b['id'] . '&file=' . (int)$fl['id']; ?>
                      <a href="<?= h($url) ?>" target="_blank" rel="noopener">
                        <?php if ($fl['mime'] === 'application/pdf'): ?>
                          <span class="d-grid border rounded bg-white b-thumb" style="place-items:center"><i class="bi bi-file-earmark-pdf fs-2 text-danger"></i></span>
                        <?php else: ?>
                          <img src="<?= h($url) ?>" alt="Bill #<?= (int)$b['id'] ?>" class="b-thumb" loading="lazy">
                        <?php endif; ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; if (!$bills): ?>
          <tr><td colspan="7" class="text-muted text-center py-4"><?= $filtered ? 'No bills match.' : 'No bills received in this period.' ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Delete (discard) popup: one for the page, filled in by billDelete(). -->
  <div class="modal fade" id="billDeleteModal" tabindex="-1" aria-labelledby="billDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" class="modal-content">
        <?php csrf_field(); ?>
        <input type="hidden" name="bill_id" id="billDeleteId" value="">
        <input type="hidden" name="do" value="discard">
        <input type="hidden" name="back" value="<?= h('?' . http_build_query($_GET)) ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="billDeleteTitle">Delete bill</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2 fw-semibold" id="billDeleteWhat"></div>
          <p class="small text-muted mb-3">It is removed from the staff app and from the totals. It stays here under Status: Discarded, with your name and the reason.</p>
          <label class="form-label small mb-1" for="billDeleteReason">Reason</label>
          <input type="text" class="form-control" name="reason" id="billDeleteReason" maxlength="255" placeholder="Why is it deleted?" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
<?php
$pageScripts = <<<'JS'
<script>
function billDelete(id, what, asked) {
  document.getElementById('billDeleteId').value = id;
  document.getElementById('billDeleteTitle').textContent = 'Delete bill #' + id + '?';
  document.getElementById('billDeleteWhat').textContent = 'AED ' + what;
  document.getElementById('billDeleteReason').value = asked ? 'Asked by the starter: ' + asked : '';
  var el = document.getElementById('billDeleteModal');
  bootstrap.Modal.getOrCreateInstance(el).show();
  el.addEventListener('shown.bs.modal', function () { document.getElementById('billDeleteReason').focus(); }, { once: true });
}
</script>
JS;
require __DIR__ . '/includes/ops_layout_footer.php';
