<?php
/**
 * Recurrent Income — one room and its bedspaces: each bed occupied or vacant, who is in it,
 * a note. Add beds, take one out, rename the room. All saifsys's own (migrations/rpay_rooms.sql).
 *
 *   room.php?id=3&grp=4        grp: the group page to go back to
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
$roomId = (int)($_GET['id'] ?? 0);
$grp = (int)($_GET['grp'] ?? 0);
$selfUrl = $rpayBase . '/room.php?' . http_build_query(['id' => $roomId, 'grp' => $grp]);
$backUrl = $grp ? $rpayBase . '/group_view.php?grp=' . $grp : $rpayBase . '/index.php';

$rooms = rpay_rooms($conn);
// What this group calls the spaces: "Bedspace" in a camp, "Partition" in a flat.
$spaceWord = (string)(rpay_all_groups($conn)[$grp]['space_word'] ?? 'Bedspace');
// A flat's partitions are never staff: no Staff / Outsider choice there, occupied = outsider.
$allowsStaff = (bool)(rpay_all_groups($conn)[$grp]['allows_staff'] ?? true);
$spaceLow = strtolower($spaceWord);
$room = $rooms[$roomId] ?? null;
if (!$room) {
    binv_flash('That room could not be found.', 'danger');
    header('Location: ' . $backUrl);
    exit;
}
$me = (int)current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (rpay_handle_move_post($conn, $code)) {
        // A payment moved to another room (saifsys's own table): back to this room's payments.
        header('Location: ' . $selfUrl . '&tab=history');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_beds') {
            // Every bed in one go: vacant, or occupied by staff (employee + company) or an outsider (name + rent).
            $empOk = array_flip(array_map('intval', $conn->query('SELECT id FROM employees')->fetchAll(PDO::FETCH_COLUMN)));
            $coOk = array_flip(array_map('intval', $conn->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_COLUMN)));
            $up = $conn->prepare('UPDATE rpay_room_beds SET status = ?, occupant_type = ?, employee_id = ?, company_id = ?, occupant = ?, rent = ?, due_date = ?, note = ?, updated_by = ?
                WHERE id = ? AND room_id = ?');
            $warn = [];
            foreach ((array)($_POST['bed'] ?? []) as $bid => $b) {
                $status = ($b['status'] ?? '') === 'occupied' ? 'occupied' : 'vacant';
                $type = $status === 'occupied' && in_array($b['type'] ?? '', ['staff', 'outsider'], true) ? $b['type'] : null;
                if ($status === 'occupied' && !$allowsStaff) {
                    $type = 'outsider';
                }
                $emp = $type === 'staff' && isset($empOk[(int)($b['employee'] ?? 0)]) ? (int)$b['employee'] : null;
                $co = $type === 'staff' && isset($coOk[(int)($b['company'] ?? 0)]) ? (int)$b['company'] : null;
                $who = $type === 'outsider' ? mb_substr(trim((string)($b['occupant'] ?? '')), 0, 120) : '';
                $rentIn = trim(str_replace(',', '', (string)($b['rent'] ?? '')));
                $rent = $type === 'outsider' && $rentIn !== '' && is_numeric($rentIn) && (float)$rentIn >= 0 ? round((float)$rentIn, 2) : null;
                $note = mb_substr(trim((string)($b['note'] ?? '')), 0, 255);
                $dueIn = DateTime::createFromFormat('!Y-m-d', (string)($b['due_date'] ?? ''));
                $dueOn = $type === 'outsider' && $dueIn ? $dueIn->format('Y-m-d') : null;
                if ($type === 'outsider' && $rentIn !== '' && $rent === null) {
                    $warn[] = 'a rent that is not a number was left empty';
                }
                $up->execute([$status, $type, $emp, $co, $who !== '' ? $who : null, $rent, $dueOn, $note !== '' ? $note : null, $me ?: null, (int)$bid, $roomId]);
            }
            binv_flash((count((array)($_POST['bed'] ?? [])) === 1 ? $spaceWord . ' saved.' : $spaceWord . 's saved.') . ($warn ? ' Note: ' . implode('; ', array_unique($warn)) . '.' : ''), $warn ? 'warning' : 'success');
        } elseif ($action === 'add_beds') {
            $n = max(1, min(100, (int)($_POST['count'] ?? 1)));
            rpay_add_beds($conn, $roomId, $n, (string)($_POST['status'] ?? 'vacant'), $me);
            binv_flash($n . ' ' . $spaceLow . ($n === 1 ? '' : 's') . ' added.');
        } elseif ($action === 'remove_bed') {
            $conn->prepare('DELETE FROM rpay_room_beds WHERE id = ? AND room_id = ?')->execute([(int)($_POST['bed_id'] ?? 0), $roomId]);
            binv_flash($spaceWord . ' taken out.');
        } elseif ($action === 'rename') {
            $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 80) {
                throw new BinvError('Give the room a name.', 400);
            }
            $conn->prepare('UPDATE rpay_rooms SET name = ?, note = ? WHERE id = ?')
                ->execute([$name, trim((string)($_POST['note'] ?? '')) ?: null, $roomId]);
            binv_flash('Room saved.');
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        binv_flash($e->getCode() === '23000' ? 'There is already a room with that name in this building.' : 'That could not be saved.', 'danger');
    }
    // From the group page's Details box: back there, with this room's box open again.
    $back = (string)($_POST['back'] ?? '');
    header('Location: ' . ($back !== '' && strpos($back, $rpayBase . '/group_view.php') === 0 ? $back : $selfUrl));
    exit;
}

$beds = rpay_room_beds($conn, $roomId);
// The staff a bed can be given to (current ones, plus anyone already in a bed here) and the companies.
$inBeds = array_filter(array_map(function ($b) { return (int)$b['employee_id']; }, $beds));
$empRows = $conn->query("SELECT id, full_name, employee_code, company_id FROM employees
    WHERE status IN ('active', 'on_leave', 'notice_period')" . ($inBeds ? ' OR id IN (' . implode(',', $inBeds) . ')' : '') . '
    ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$companies = $conn->query('SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$empName = array_column($empRows, 'full_name', 'id');
try {
    $bNames = array_column(rpay_reem($code, 'GET', '/entries')['buildings'] ?? [], 'name', 'id');
} catch (BinvError $e) {
    $bNames = [];
}

// The two tabs: Details (the beds) and Payment history (this room's payments, 10 a page).
$tab = ($_GET['tab'] ?? '') === 'history' ? 'history' : 'details';
const RPAY_ROOM_HISTORY_PAGE = 10;
try {
    $history = rpay_rooms_payments($conn, $code, [$roomId => $room])[$roomId] ?? [];
} catch (BinvError $e) {
    $history = null;
}
// From a room card's Received / To be paid: only the paid, or only the ones still to come.
$st = in_array($_GET['st'] ?? '', ['paid', 'pending'], true) ? $_GET['st'] : '';
if ($st !== '' && is_array($history)) {
    $history = array_values(array_filter($history, function ($d) use ($st) { return $st === 'paid' ? (float)($d['received'] ?? 0) > 0 : ($d['status'] ?? '') !== 'paid'; }));
}
$hp = max(0, (int)($_GET['hp'] ?? 0));
$hPages = $history ? (int)ceil(count($history) / RPAY_ROOM_HISTORY_PAGE) : 1;
$hp = min($hp, max(0, $hPages - 1));
$hShown = $history ? array_slice($history, $hp * RPAY_ROOM_HISTORY_PAGE, RPAY_ROOM_HISTORY_PAGE) : [];

$pageTitle = $room['name'];
require __DIR__ . '/includes/rpay_layout_header.php';
?>


<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to <?= $grp ? 'the group' : 'payments' ?></a>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mt-2 mb-3">
  <div>
    <div class="d-flex align-items-center gap-2">
      <div class="page-header-label mb-0"><?= h($room['name']) ?></div>
      <?php if ($room['note']): ?><span class="badge rounded-pill text-bg-light border"><?= h($room['note']) ?></span><?php endif; ?>
    </div>
    <div class="text-muted small"><?= h($bNames[$room['building_id']] ?? '') ?></div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#rpRoomEdit"><i class="bi bi-pencil"></i> Edit room</button>
    <button class="btn btn-sm text-white" style="background:var(--primary)" type="button" data-bs-toggle="collapse" data-bs-target="#rpAddBeds"><i class="bi bi-plus-lg"></i> Add <?= h($spaceLow) ?>s</button>
  </div>
</div>

<form method="post" action="<?= h($selfUrl) ?>" class="collapse mb-3" id="rpRoomEdit">
  <div class="card card-round"><div class="card-body d-flex flex-wrap gap-2 align-items-end py-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="rename">
    <div><label class="form-label small text-muted mb-1">Room</label><input name="name" class="form-control form-control-sm" value="<?= h($room['name']) ?>" maxlength="80" required></div>
    <div><label class="form-label small text-muted mb-1">Note</label><input name="note" class="form-control form-control-sm" value="<?= h((string)$room['note']) ?>" maxlength="255" placeholder="e.g. Staff"></div>
    <button class="btn btn-sm btn-success">Save</button>
    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#rpRoomEdit">Cancel</button>
  </div></div>
</form>

<form method="post" action="<?= h($selfUrl) ?>" class="collapse mb-3" id="rpAddBeds">
  <div class="card card-round"><div class="card-body d-flex flex-wrap gap-2 align-items-end py-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="add_beds">
    <div><label class="form-label small text-muted mb-1">How many <?= h($spaceLow) ?>s</label><input name="count" type="number" min="1" max="100" value="1" class="form-control form-control-sm" style="width:110px"></div>
    <div><label class="form-label small text-muted mb-1">They are</label>
      <select name="status" class="form-select form-select-sm"><option value="vacant">Vacant</option><option value="occupied">Occupied</option></select></div>
    <button class="btn btn-sm btn-success">Add</button>
    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#rpAddBeds">Cancel</button>
  </div></div>
</form>

<div class="rp-stats mb-3">
  <div class="rp-stat"><span><?= h($spaceWord) ?>s</span><b><?= $room['beds'] ?></b></div>
  <div class="rp-stat"><span>Occupied</span><b class="text-success"><?= $room['occupied'] ?></b></div>
  <div class="rp-stat"><span>Vacant</span><b class="<?= $room['vacant'] ? 'text-warning-emphasis' : '' ?>"><?= $room['vacant'] ?></b></div>
  <?php if ($allowsStaff): ?><div class="rp-stat"><span>Staff</span><b><?= $room['staff'] ?></b></div><?php endif; ?>
  <?php if ($allowsStaff): ?><div class="rp-stat"><span>Outsiders</span><b><?= $room['outsiders'] ?></b></div><?php endif; ?>
  <div class="rp-stat"><span>Rent a month</span><b><?= h(rpay_money($room['rent'])) ?></b></div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'details' ? ' active' : '' ?>" href="<?= h($selfUrl) ?>"><i class="bi bi-door-open"></i> Details</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'history' ? ' active' : '' ?>" href="<?= h($selfUrl . '&tab=history') ?>"><i class="bi bi-clock-history"></i> Payment history<?= is_array($history) ? ' <span class="badge text-bg-light border">' . count($history) . '</span>' : '' ?></a></li>
</ul>
<?php if ($tab === 'details'): ?>
<?php if (!$beds): ?>
  <div class="card card-round"><div class="card-body text-muted text-center py-5">No <?= h($spaceLow) ?>s yet. Use <b>Add <?= h($spaceLow) ?>s</b>.</div></div>
<?php else: ?>
<?php
  // Each bed as one line: who is in it, what it pays, its status; the side panel edits it.
  $initials = function (string $name): string {
      $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
      return strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1)) ;
  };
  $bedData = [];   // for the side panel, by bed id
  $counts = ['all' => count($beds), 'occupied' => 0, 'vacant' => 0, 'staff' => 0, 'outsider' => 0];
  foreach ($beds as $b) {
      $occ = $b['status'] === 'occupied';
      $type = (string)($b['occupant_type'] ?? '');
      $counts[$occ ? 'occupied' : 'vacant']++;
      if ($occ && isset($counts[$type])) { $counts[$type]++; }
      $bedData[(int)$b['id']] = [
          'no' => (string)$b['bed_no'], 'status' => $b['status'], 'type' => $type,
          'employee' => (int)$b['employee_id'], 'company' => (int)$b['company_id'],
          'occupant' => (string)$b['occupant'], 'note' => (string)$b['note'],
          'rent' => $b['rent'] !== null ? number_format((float)$b['rent'], 2, '.', '') : '',
          'due' => (string)($b['due_date'] ?? ''),
      ];
  }
  $pills = ['all' => 'All', 'occupied' => 'Occupied', 'vacant' => 'Vacant'] + ($allowsStaff ? ['staff' => 'Staff', 'outsider' => 'Outsider'] : []);
?>
<div class="d-flex flex-wrap gap-2 mb-3" id="rbPills">
  <?php foreach ($pills as $k => $label): ?>
    <button type="button" class="rb-pill<?= $k === 'all' ? ' on' : '' ?>" data-f="<?= $k ?>"><?= $label ?> <em><?= $counts[$k] ?></em></button>
  <?php endforeach; ?>
</div>

<div class="rb-list" id="rbList">
  <?php foreach ($beds as $b):
    $occ = $b['status'] === 'occupied';
    $type = (string)($b['occupant_type'] ?? '');
    if (!$allowsStaff && $occ) { $type = 'outsider'; }
    $empNm = $type === 'staff' && $b['employee_id'] ? (string)($empName[(int)$b['employee_id']] ?? '') : '';
    $name = $type === 'staff' ? $empNm : ($type === 'outsider' ? (string)$b['occupant'] : '');
    $alert = $occ && $type === 'outsider' ? rpay_due_alert($b['due_date'] ?? null, true) : null; ?>
    <button type="button" class="rb-row <?= $occ ? 'rb-occ' : 'rb-vac' ?>" data-bed="<?= (int)$b['id'] ?>"
            data-tags="<?= $occ ? 'occupied' : 'vacant' ?> <?= h($type) ?>">
      <span class="rb-no"><?= h($b['bed_no']) ?></span>
      <span class="rb-av <?= !$occ ? 'rb-av-empty' : ($name === '' ? 'rb-av-none' : ($type === 'staff' ? 'rb-av-staff' : 'rb-av-out')) ?>"><?= $occ && $name !== '' && $initials($name) !== '' ? h($initials($name)) : '<i class="bi ' . ($occ ? 'bi-person' : 'bi-door-open') . '"></i>' ?></span>
      <span class="rb-main">
        <?php if (!$occ): ?>
          <span class="rb-name text-muted">Vacant</span>
          <span class="rb-meta text-primary"><i class="bi bi-plus-lg"></i> Add details</span>
        <?php else: ?>
          <span class="rb-name"><?= $name !== '' ? h($name) : '<span class="text-danger">' . ($type === 'staff' ? 'Pick the employee' : ($type === 'outsider' ? 'Add the name' : 'Add details')) . '</span>' ?></span>
          <span class="rb-meta">
            <?php if ($allowsStaff && $type): ?><span class="rb-tag"><?= $type === 'staff' ? 'Staff' : 'Outsider' ?></span><?php endif; ?>
            <?php if ($type === 'staff'): ?>
              <?= h($companies[(int)$b['company_id']] ?? 'No company yet') ?>
            <?php elseif ($type === 'outsider'): ?>
              <?= $b['rent'] !== null ? '<b>' . h(rpay_money($b['rent'])) . '</b> a month' : '<span class="text-danger">Add the rent</span>' ?>
              <?= !empty($b['due_date']) ? ' · due ' . h(rpay_date($b['due_date'])) : '' ?>
            <?php endif; ?>
          </span>
        <?php endif; ?>
        <?php if ($b['note']): ?><span class="rb-note"><?= h($b['note']) ?></span><?php endif; ?>
      </span>
      <span class="rb-end">
        <?= $alert ? rpay_due_bell($alert) : '' ?>
        <span class="badge rounded-pill <?= $occ ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $occ ? 'Occupied' : 'Vacant' ?></span>
        <i class="bi bi-chevron-right text-muted"></i>
      </span>
    </button>
  <?php endforeach; ?>
  <div class="text-muted text-center py-4 d-none" id="rbNone">Nothing here.</div>
</div>

<!-- The side panel: opened from a bed; the list stays usable behind it -->
<aside class="offcanvas offcanvas-end rb-panel" tabindex="-1" id="rbPanel" data-bs-backdrop="false" data-bs-scroll="true" aria-labelledby="rbTitle">
  <form method="post" action="<?= h($selfUrl) ?>" id="rbForm" class="d-flex flex-column h-100">
    <div class="rb-head">
      <div>
        <div class="rb-kicker"><?= h($room['name']) ?></div>
        <div class="rb-title" id="rbTitle"><?= h($spaceWord === 'Bedspace' ? 'Bed' : $spaceWord) ?> <span id="rbNo"></span></div>
      </div>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="save_beds">
      <label class="form-label small text-muted mb-1">Status</label>
      <div class="btn-group w-100 mb-3" role="group">
        <input type="radio" class="btn-check" name="status" id="rbSO" value="occupied"><label class="btn btn-outline-success" for="rbSO">Occupied</label>
        <input type="radio" class="btn-check" name="status" id="rbSV" value="vacant"><label class="btn btn-outline-secondary" for="rbSV">Vacant</label>
      </div>
      <div id="rbWho">
        <?php if ($allowsStaff): ?>
          <label class="form-label small text-muted mb-1">Occupied by</label>
          <div class="btn-group w-100 mb-3" role="group">
            <input type="radio" class="btn-check" name="type" id="rbTS" value="staff"><label class="btn btn-outline-primary" for="rbTS"><i class="bi bi-person-badge"></i> Staff</label>
            <input type="radio" class="btn-check" name="type" id="rbTO" value="outsider"><label class="btn btn-outline-primary" for="rbTO"><i class="bi bi-person"></i> Outsider</label>
          </div>
          <div id="rbStaff">
            <label class="form-label small text-muted mb-1">Employee</label>
            <select name="employee" id="rbEmp" class="form-select mb-3" data-no-search>
              <option value="">Pick the employee</option>
              <?php foreach ($empRows as $e): ?><option value="<?= (int)$e['id'] ?>" data-company="<?= (int)$e['company_id'] ?>"><?= h($e['full_name']) ?><?= $e['employee_code'] ? ' (' . h($e['employee_code']) . ')' : '' ?></option><?php endforeach; ?>
            </select>
            <label class="form-label small text-muted mb-1">Company</label>
            <select name="company" id="rbCo" class="form-select mb-3" data-no-search>
              <option value="">Pick the company</option>
              <?php foreach ($companies as $cid => $cname): ?><option value="<?= (int)$cid ?>"><?= h($cname) ?></option><?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="type" value="outsider">
        <?php endif; ?>
        <div id="rbOut">
          <label class="form-label small text-muted mb-1">Name</label>
          <input name="occupant" id="rbName" class="form-control mb-3" maxlength="120">
          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="form-label small text-muted mb-1">Rent a month</label>
              <div class="input-group"><span class="input-group-text">AED</span><input name="rent" id="rbRent" class="form-control" inputmode="decimal"></div>
            </div>
            <div class="col-5">
              <label class="form-label small text-muted mb-1">Due date</label>
              <input type="date" name="due_date" id="rbDue" class="form-control">
            </div>
          </div>
        </div>
      </div>
      <label class="form-label small text-muted mb-1">Note <span class="fw-normal">(optional)</span></label>
      <input name="note" id="rbNote" class="form-control" maxlength="255">
    </div>
    <div class="rb-foot">
      <button type="button" class="btn btn-link text-danger text-decoration-none me-auto px-0" id="rbRemove"><i class="bi bi-trash"></i> Remove</button>
      <button type="button" class="btn btn-light border" data-bs-dismiss="offcanvas">Cancel</button>
      <button class="btn btn-success"><i class="bi bi-check-lg"></i> Save</button>
    </div>
  </form>
</aside>
<script type="application/json" id="rbData"><?= json_encode($bedData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<?php foreach ($beds as $b): $bid = (int)$b['id']; ?>
  <form method="post" action="<?= h($selfUrl) ?>" id="rpr<?= $bid ?>" onsubmit="return confirm('Take bed <?= h($b['bed_no']) ?> out of this room?');"><?php csrf_field(); ?><input type="hidden" name="action" value="remove_bed"><input type="hidden" name="bed_id" value="<?= $bid ?>"></form>
<?php endforeach; ?>
<?php else: /* Payment history */ ?>
  <div class="d-flex flex-wrap gap-2 mb-2">
    <a class="btn btn-sm <?= $st === '' ? 'btn-primary' : 'btn-light border' ?>" href="<?= h($selfUrl . '&tab=history') ?>">All</a>
    <a class="btn btn-sm <?= $st === 'paid' ? 'btn-primary' : 'btn-light border' ?>" href="<?= h($selfUrl . '&tab=history&st=paid') ?>">Received</a>
    <a class="btn btn-sm <?= $st === 'pending' ? 'btn-primary' : 'btn-light border' ?>" href="<?= h($selfUrl . '&tab=history&st=pending') ?>">To be paid</a>
  </div>
  <?php if ($history === null): ?>
    <div class="card card-round"><div class="card-body text-muted">The payments could not be read just now.</div></div>
  <?php elseif (!$history): ?>
    <div class="card card-round"><div class="card-body text-muted text-center py-5">No payments for this room yet. A payment in the wrong room, or in none, can be moved here with its "Move to".</div></div>
  <?php else: ?>
    <?php
      $rowsList = $hShown;
      $moveRooms = array_column(rpay_rooms($conn), 'name', 'id');
      $hereRoom = $roomId;
      $movePostUrl = $selfUrl;
      $rpayUi = 'rows';
      require __DIR__ . '/includes/rpay_ui.php';
    ?>
    <?php if ($hPages > 1): ?>
      <nav class="d-flex justify-content-center gap-1 mt-3">
        <?php for ($i = 0; $i < $hPages; $i++): ?>
          <a class="btn btn-sm <?= $i === $hp ? 'btn-primary' : 'btn-light border' ?>" href="<?= h($selfUrl . '&tab=history' . ($st !== '' ? '&st=' . $st : '') . '&hp=' . $i) ?>"><?= $i + 1 ?></a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; /* the tabs */ ?>

<?php $rpayUi = 'css'; require __DIR__ . '/includes/rpay_ui.php'; ?>
<style>
.rb-pill{ border:1px solid #dfe3e8; background:#fff; border-radius:999px; padding:4px 12px; font-size:.85rem; font-weight:600; color:#475569; }.rb-pill em{ font-style:normal; background:#eef1f5; border-radius:999px; padding:0 7px; margin-left:4px; font-size:.75rem; }.rb-pill.on{ background:var(--primary); border-color:var(--primary); color:#fff; } .rb-pill.on em{ background:rgba(255,255,255,.25); }.rb-list{ display:flex; flex-direction:column; gap:8px; max-width:980px; }.rb-row{ position:relative; display:flex; align-items:center; gap:12px; width:100%; text-align:left; background:#fff; border:1px solid #e3e6ea; border-radius:12px; padding:10px 14px 10px 18px; transition:box-shadow .15s, border-color .15s; }.rb-row::before{ content:''; position:absolute; left:0; top:8px; bottom:8px; width:4px; border-radius:0 4px 4px 0; background:var(--edge,#cbd5e1); }.rb-row.rb-occ{ --edge:#2e9e5b; } .rb-row.rb-vac{ --edge:#e0a800; background:#fffdf5; }.rb-row:hover{ box-shadow:0 8px 20px rgba(15,23,42,.08); border-color:#cfd5dc; transform:translateY(-1px); } .rb-row{ transition:box-shadow .15s, border-color .15s, transform .15s; }.rb-row.rb-on{ border-color:var(--primary); box-shadow:0 0 0 2px rgba(30,58,138,.15); }.rb-no{ width:28px; font-weight:700; color:#64748b; text-align:center; flex-shrink:0; }.rb-av{ width:40px; height:40px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem; letter-spacing:.02em; flex-shrink:0; box-shadow:0 2px 6px rgba(15,23,42,.12); color:#fff; background:#94a3b8; } .rb-av-staff{ background:linear-gradient(135deg,#3b82f6,#1e40af); } .rb-av-out{ background:linear-gradient(135deg,#14b8a6,#0f766e); } .rb-av-none{ background:#eef1f5; color:#94a3b8; box-shadow:none; }.rb-av-empty{ background:linear-gradient(135deg,#fde68a,#f59e0b); color:#78350f; }.rb-main{ display:flex; flex-direction:column; min-width:0; flex:1; }.rb-name{ font-weight:600; }.rb-meta{ font-size:.82rem; color:#64748b; }.rb-tag{ display:inline-block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; background:#eef1f5; color:#475569; border-radius:4px; padding:0 5px; margin-right:4px; }.rb-note{ font-size:.78rem; color:#94a3b8; font-style:italic; }.rb-end{ display:flex; align-items:center; gap:8px; flex-shrink:0; }.rb-panel{ width:440px !important; max-width:100%; }.rb-head{ display:flex; justify-content:space-between; align-items:flex-start; padding:16px 18px; background:linear-gradient(135deg,var(--primary),var(--primary-light,#2563eb)); color:#fff; }.rb-kicker{ font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; opacity:.8; }.rb-title{ font-size:1.3rem; font-weight:700; }.rb-foot{ display:flex; gap:8px; align-items:center; padding:12px 18px; border-top:1px solid #e3e6ea; }body.dark-mode .rb-row, body.dark-mode .rb-pill{ background:#1e293b; border-color:#334155; color:#e2e8f0; }body.dark-mode .rb-row.rb-vac{ background:#2a2412; }body.dark-mode .rb-foot{ border-top-color:#334155; }
.rp-stats{ display:flex; flex-wrap:wrap; gap:10px; }
.rp-stat{ background:#fff; border:1px solid #e3e6ea; border-radius:10px; padding:8px 14px; min-width:120px; }
.rp-stat span{ display:block; font-size:.75rem; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; }
.rp-stat b{ font-size:1.25rem; }
.rp-sheet thead th{ font-size:.72rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#8a9099; background:#f8f9fb; padding:.55rem .5rem; white-space:nowrap; }
.rp-sheet tbody td{ padding:.35rem .5rem; border-color:#eef0f3; }
.rp-sheet tr.rp-occ td:first-child{ box-shadow:inset 4px 0 0 #2e9e5b; }
.rp-sheet tr.rp-vac td:first-child{ box-shadow:inset 4px 0 0 #e0a800; }
.rp-sheet tr.rp-vac td{ background:#fffbeb; }
.rp-sheet tr.rp-changed td{ background:#eef4ff; }
.rp-sheet .form-select, .rp-sheet .form-control{ border-color:#e3e6ea; }
body.dark-mode .rp-sheet thead th{ background:#1e293b; color:#94a3b8; }
body.dark-mode .rp-sheet tr.rp-vac td{ background:#2a2412; }
body.dark-mode .rp-sheet tr.rp-changed td{ background:#16233a; }
body.dark-mode .rp-stat{ background:#1e293b; border-color:#334155; }
</style>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
<script>
// The bed list: filter pills, and a side panel to see / change one bed (the list stays usable).
(function () {
  var list = document.getElementById('rbList'), panelEl = document.getElementById('rbPanel');
  if (!list || !panelEl || !window.bootstrap) { return; }
  var data = JSON.parse(document.getElementById('rbData').textContent || '{}');
  var form = document.getElementById('rbForm'), panel = new bootstrap.Offcanvas(panelEl);
  var who = document.getElementById('rbWho'), staff = document.getElementById('rbStaff'), out = document.getElementById('rbOut');
  var emp = document.getElementById('rbEmp'), co = document.getElementById('rbCo');
  var current = null;

  // Pills: All / Occupied / Vacant / Staff / Outsider.
  document.querySelectorAll('#rbPills .rb-pill').forEach(function (p) {
    p.addEventListener('click', function () {
      document.querySelectorAll('#rbPills .rb-pill').forEach(function (x) { x.classList.toggle('on', x === p); });
      var f = p.dataset.f, shown = 0;
      list.querySelectorAll('.rb-row').forEach(function (r) {
        var ok = f === 'all' || (' ' + r.dataset.tags + ' ').indexOf(' ' + f + ' ') !== -1;
        r.classList.toggle('d-none', !ok);
        if (ok) { shown++; }
      });
      document.getElementById('rbNone').classList.toggle('d-none', shown > 0);
    });
  });

  function show() {
    var occ = document.getElementById('rbSO').checked;
    var t = form.querySelector('input[name=type]:checked') || form.querySelector('input[type=hidden][name=type]');
    who.style.display = occ ? '' : 'none';
    if (staff) { staff.style.display = occ && t && t.value === 'staff' ? '' : 'none'; }
    out.style.display = occ && t && t.value === 'outsider' ? '' : 'none';
  }
  form.querySelectorAll('input[name=status], input[name=type]').forEach(function (r) { r.addEventListener('change', show); });
  if (emp) {
    emp.addEventListener('change', function () {
      var o = emp.options[emp.selectedIndex], c = o ? o.dataset.company : '';
      if (c && c !== '0' && co.querySelector('option[value="' + c + '"]')) { co.value = c; }
    });
  }

  function open(row, addDetails) {
    var d = data[row.dataset.bed];
    if (!d) { return; }
    current = row.dataset.bed;
    list.querySelectorAll('.rb-row').forEach(function (r) { r.classList.toggle('rb-on', r === row); });
    document.getElementById('rbNo').textContent = d.no;
    document.getElementById(d.status === 'occupied' || addDetails ? 'rbSO' : 'rbSV').checked = true;
    var ts = document.getElementById('rbTS'), to = document.getElementById('rbTO');
    if (ts) { ts.checked = d.type === 'staff'; to.checked = d.type === 'outsider'; }
    if (emp) { emp.value = d.employee ? String(d.employee) : ''; co.value = d.company ? String(d.company) : ''; }
    document.getElementById('rbName').value = d.occupant;
    document.getElementById('rbRent').value = d.rent;
    document.getElementById('rbDue').value = d.due;
    document.getElementById('rbNote').value = d.note;
    show();
    panel.show();
  }
  list.querySelectorAll('.rb-row').forEach(function (row) {
    row.addEventListener('click', function () { open(row, row.classList.contains('rb-vac')); });
  });
  panelEl.addEventListener('hidden.bs.offcanvas', function () {
    list.querySelectorAll('.rb-row').forEach(function (r) { r.classList.remove('rb-on'); });
  });

  // Save: the page takes beds as bed[id][field]; name this bed's fields just before sending.
  form.addEventListener('submit', function () {
    ['status', 'type', 'employee', 'company', 'occupant', 'rent', 'due_date', 'note'].forEach(function (f) {
      form.querySelectorAll('[name="' + f + '"]').forEach(function (el) { el.name = 'bed[' + current + '][' + f + ']'; });
    });
  });
  // Remove: the bed's own remove form.
  document.getElementById('rbRemove').addEventListener('click', function () {
    var f = document.getElementById('rpr' + current);
    if (f && confirm('Take this one out of the room?')) { f.submit(); }
  });
})();
</script>
