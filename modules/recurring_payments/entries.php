<?php
/**
 * Recurring Payments — the entries: what repeats, where, how much, on which day.
 * Pause, resume, create a payment by hand (this month's, or one with its own date, for
 * an invoice entered late), and delete are passed to Reem,
 * which decides whether each is allowed.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/rpay_helper.php';

require_login(get_application_web_root() . '/login');
$code = rpay_boot($conn);

$rpayBase = get_application_web_root() . '/modules/recurring_payments';
$selfUrl = $rpayBase . '/entries.php';
// Search and filters; all kept after Pause, Create now and the rest.
$getStr = fn($k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$headFilter = $getStr('head');      // an income head id, or "none" for no head
$q = $getStr('q');                  // title, unit, building, head or notes
$dateFilter = $getStr('date');      // a start date (YYYY-MM-DD) from the entry form
$filterParams = array_filter(['q' => $q, 'date' => $dateFilter, 'head' => $headFilter], fn($v) => $v !== '');
$listUrl = $selfUrl . ($filterParams ? '?' . http_build_query($filterParams) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // A note added or deleted (saifsys's own table): back to the list with that entry's notes open.
    if (rpay_handle_note_post($conn, $code)) {
        $back = $listUrl . (strpos($listUrl, '?') === false ? '?' : '&') . 'notes=' . (int)($_POST['entry'] ?? 0);
        header('Location: ' . $back);
        exit;
    }
    $id = (int)($_POST['entry'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'pause' || $action === 'resume') {
            $entry = rpay_reem($code, 'POST', '/entries/' . $id . '/active', ['active' => $action === 'resume']);
            binv_flash('"' . $entry['title'] . '" ' . ($action === 'resume' ? 'resumed.' : 'paused. No new payments will be created for it.'));
        } elseif ($action === 'create') {
            $date = is_string($_POST['date'] ?? null) ? trim($_POST['date']) : '';
            $due = rpay_reem($code, 'POST', '/entries/' . $id . '/dues', $date !== '' ? ['date' => $date] : null);
            binv_flash('Payment for ' . rpay_month_label((string)($due['month'] ?? '')) . ' created for "' . ($due['title'] ?? '') . '".');
        } elseif ($action === 'delete') {
            rpay_reem($code, 'DELETE', '/entries/' . $id);
            binv_flash('Entry deleted.');
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $listUrl);
    exit;
}

try {
    $data = rpay_reem($code, 'GET', '/entries');
} catch (BinvError $e) {
    rpay_stop($e);
}
$allEntries = $data['entries'] ?? [];

$entries = array_values(array_filter($allEntries, function ($e) use ($headFilter, $q, $dateFilter) {
    $headId = (int)($e['head']['id'] ?? 0);
    if ($headFilter !== '' && ($headFilter === 'none' ? $headId !== 0 : (string)$headId !== $headFilter)) {
        return false;
    }
    if ($dateFilter !== '' && rpay_start_date((string)($e['first_month'] ?? ''), (int)($e['day'] ?? 1)) !== $dateFilter) {
        return false;
    }
    if ($q !== '') {
        $hay = implode(' ', [$e['title'] ?? '', $e['unit'] ?? '', $e['building']['name'] ?? '', $e['head']['name'] ?? '', $e['notes'] ?? '']);
        if (mb_stripos($hay, $q) === false) {
            return false;
        }
    }
    return true;
}));

// Notes kept in saifsys, per entry.
$noteMap = rpay_entry_notes($conn, array_map(function ($e) { return (int)$e['id']; }, $entries));

$pageTitle = 'Entries';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="page-header-label mb-1">Entries</div>
    <p class="text-muted mb-0">Add each thing once. Its payment is created every month on the day you pick.</p>
  </div>
  <a href="<?= h($rpayBase) ?>/entry_form.php" class="btn text-white" style="background:var(--primary)">
    <i class="bi bi-plus-lg"></i> Add entry
  </a>
</div>

<div class="card card-round mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end" action="<?= h($selfUrl) ?>">
      <div class="col-12 col-md-4">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input type="search" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Title, unit, building, head">
        </div>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Show date</label>
        <input type="date" name="date" class="form-control" value="<?= h($dateFilter) ?>" onchange="this.form.submit()">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">Income head</label>
        <select name="head" class="form-select" data-search onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach (rpay_live_heads($conn, $data['heads'] ?? [], (int)($headFilter !== 'none' ? $headFilter : 0)) as $hd): ?>
            <option value="<?= (int)$hd['id'] ?>"<?= $headFilter === (string)$hd['id'] ? ' selected' : '' ?>><?= h($hd['name']) ?></option>
          <?php endforeach; ?>
          <option value="none"<?= $headFilter === 'none' ? ' selected' : '' ?>>No income head</option>
        </select>
      </div>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button class="btn btn-light border flex-fill"><i class="bi bi-funnel"></i> Show</button>
        <?php if ($filterParams): ?>
          <a class="btn btn-link text-decoration-none px-1" href="<?= h($selfUrl) ?>">Clear</a>
        <?php endif; ?>
      </div>
      <div class="col-12 d-flex flex-wrap align-items-center gap-3 small">
        <span class="text-muted"><?= count($entries) ?> of <?= count($allEntries) ?> entr<?= count($allEntries) === 1 ? 'y' : 'ies' ?></span>
        <?php if ($headFilter !== ''): ?>
          <a class="text-decoration-none" href="<?= h($rpayBase) ?>/index.php?head=<?= h(urlencode($headFilter)) ?>">See payments</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Title</th><th>Building</th><th>Income head</th><th>Shop / unit</th><th class="text-end">Amount</th><th>Start date</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$entries): ?>
          <tr><td colspan="8" class="text-muted text-center py-5"><?= $filterParams ? 'No entries match the search or filters.' : 'No entries yet. Add the first one.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $e): ?>
          <?php $active = !empty($e['active']); $auto = !empty($e['auto_create']); ?>
          <tr>
            <td>
              <a class="fw-semibold text-decoration-none" href="<?= h($rpayBase) ?>/entry_form.php?id=<?= (int)$e['id'] ?>"><?= h($e['title'] ?? '') ?></a>
              <?php if (!empty($e['notes'])): ?><div class="small text-muted"><?= h($e['notes']) ?></div><?php endif; ?>
            </td>
            <td><?= h($e['building']['name'] ?? '') ?></td>
            <td>
              <?php if (!empty($e['head'])): ?>
                <?= h($e['head']['name'] ?? '') ?>
              <?php else: ?>
                <a class="small text-decoration-none" href="<?= h($rpayBase) ?>/entry_form.php?id=<?= (int)$e['id'] ?>">Set head</a>
              <?php endif; ?>
            </td>
            <td><?= h($e['unit'] ?? '') ?></td>
            <td class="num text-end fw-semibold"><?= h(rpay_money($e['amount'] ?? 0)) ?></td>
            <td>
              <?= h(rpay_date(rpay_start_date((string)($e['first_month'] ?? ''), (int)($e['day'] ?? 1)))) ?>
            </td>
            <td><span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>"><?= $active ? 'Active' : 'Paused' ?></span></td>
            <td class="text-end text-nowrap">
              <?php $nc = count($noteMap[(int)$e['id']] ?? []); ?>
              <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#rpNotes<?= (int)$e['id'] ?>" title="Notes">
                <i class="bi bi-journal-text"></i> Notes<?= $nc ? ' <span class="badge text-bg-secondary">' . $nc . '</span>' : '' ?>
              </button>
              <?php if ($active): ?>
                <form method="post" action="<?= h($listUrl) ?>" class="d-inline-flex gap-1 align-middle">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="entry" value="<?= (int)$e['id'] ?>">
                  <input type="hidden" name="action" value="create">
                  <input type="date" name="date" class="form-control form-control-sm" style="width:auto" title="The date of the payment. Leave empty for this month.">
                  <button class="btn btn-sm btn-light border" title="Create a payment now: this month's, or the one for the date picked">Create now</button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= h($listUrl) ?>" class="d-inline">
                <?php csrf_field(); ?>
                <input type="hidden" name="entry" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="action" value="<?= $active ? 'pause' : 'resume' ?>">
                <button class="btn btn-sm btn-light border"><?= $active ? 'Pause' : 'Resume' ?></button>
              </form>
              <?php if ((int)($e['payments'] ?? 0) === 0): ?>
                <form method="post" action="<?= h($listUrl) ?>" class="d-inline" onsubmit="return confirm('Delete this entry? This cannot be undone.');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="entry" value="<?= (int)$e['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <!-- The entry's notes, opened right under it -->
          <tr class="collapse<?= (int)($_GET['notes'] ?? 0) === (int)$e['id'] ? ' show' : '' ?>" id="rpNotes<?= (int)$e['id'] ?>">
            <td colspan="8" class="bg-body-tertiary">
              <div style="max-width:640px">
                <?php
                  $notesEntry = $e;
                  $notesList = $noteMap[(int)$e['id']] ?? [];
                  $notesPostUrl = $listUrl . (strpos($listUrl, '?') === false ? '?' : '&') . 'notes=' . (int)$e['id'];
                  $rpayUi = 'notes';
                  require __DIR__ . '/includes/rpay_ui.php';
                ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
