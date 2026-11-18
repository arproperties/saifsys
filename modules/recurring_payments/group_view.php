<?php
/**
 * Recurrent Income — one group on its own page (DEWA, Bedspace / Room, ...): the month's
 * payments of the group as cards, each saying what it is for by the group's period rule.
 * Opened by clicking a group card on the Payments list. Read only from Reem; the group,
 * its rule and typed periods are saifsys's own.
 *
 *   group_view.php?grp=6&month=2026-11&building=3
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
$gid = (int)($_GET['grp'] ?? 0);
$filters = [];
foreach (['month', 'building', 'status'] as $k) {
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $filters[$k] = $_GET[$k];
    }
}
$backUrl = $rpayBase . '/index.php?' . http_build_query(array_intersect_key($filters, ['month' => 1, 'building' => 1]) + ['groups' => 1]);

$groups = rpay_all_groups($conn);
$group = $groups[$gid] ?? null;
if (!$group) {
    binv_flash('That group could not be found.', 'danger');
    header('Location: ' . $backUrl);
    exit;
}

// A payment moved to a room from the "Not in a room" list (saifsys's own table).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_due' && !empty($group['has_rooms'])) {
    csrf_verify();
    rpay_handle_move_post($conn, $code);
    header('Location: ' . $rpayBase . '/group_view.php?' . http_build_query(['grp' => $gid] + $filters));
    exit;
}

// A new room (groups with rooms): saifsys's own table; Reem is only asked for the building list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_room' && !empty($group['has_rooms'])) {
    csrf_verify();
    $bid = (int)($_POST['building'] ?? 0);
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
    $beds = max(0, min(200, (int)($_POST['beds'] ?? 0)));
    try {
        $known = array_column(rpay_reem($code, 'GET', '/entries')['buildings'] ?? [], 'name', 'id');
        if (!isset($known[$bid])) {
            throw new BinvError('Pick the building.', 400);
        }
        if ($name === '' || mb_strlen($name) > 80) {
            throw new BinvError('Give the room a name, like G3.', 400);
        }
        $conn->beginTransaction();
        $conn->prepare('INSERT INTO rpay_rooms (building_id, group_id, name, note, sort, created_by) VALUES (?, ?, ?, ?, 99, ?)')
            ->execute([$bid, $gid, $name, trim((string)($_POST['note'] ?? '')) ?: null, (int)current_user_id() ?: null]);
        $rid = (int)$conn->lastInsertId();
        if ($beds) {
            rpay_add_beds($conn, $rid, $beds, (string)($_POST['status'] ?? 'vacant'), (int)current_user_id());
        }
        $conn->commit();
        binv_flash('Room "' . $name . '" added with ' . $beds . ' ' . ($beds === 1 ? 'bedspace' : 'bedspaces') . '.');
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        binv_flash($e->getCode() === '23000' ? 'There is already a room called "' . $name . '" in that building.' : 'The room could not be added.', 'danger');
    }
    header('Location: ' . $rpayBase . '/group_view.php?' . http_build_query(['grp' => $gid] + $filters));
    exit;
}

$reemFilters = array_intersect_key($filters, ['month' => 1, 'building' => 1]);
$headGroup = rpay_head_group_map($conn);
$inThisGroup = function (array $d) use ($headGroup, $gid) {
    return (int)($headGroup[(int)($d['head']['id'] ?? 0)]['id'] ?? 0) === $gid;
};
const RPAY_GROUP_VIEW_MONTHS = 60; // "All months" goes this far back at most
const RPAY_GROUP_PAGE_MONTHS = 10; // months on one page

try {
    $entryData = rpay_reem($code, 'GET', '/entries');
    // The months to read: one picked, or every month from the group's first entry to next month.
    $groupEntries = array_values(array_filter($entryData['entries'] ?? [], function ($e) use ($headGroup, $gid) {
        return (int)($headGroup[(int)($e['head']['id'] ?? 0)]['id'] ?? 0) === $gid;
    }));
    $next = (new DateTime('first day of next month'))->format('Y-m');
    $first = $groupEntries ? min(array_map(function ($e) { return (string)($e['first_month'] ?? ''); }, $groupEntries)) : $next;
    $floor = (new DateTime('first day of this month'))->modify('-' . (RPAY_GROUP_VIEW_MONTHS - 1) . ' months')->format('Y-m');
    $first = max($first, $floor);
    $allMonths = [];
    for ($m = DateTime::createFromFormat('!Y-m', $first); $m && $m->format('Y-m') <= $next; $m->modify('+1 month')) {
        $allMonths[] = $m->format('Y-m');
    }
    $allMonths = array_reverse($allMonths); // newest first
    $pickedMonth = $filters['month'] ?? '';
    $pageNo = max(0, (int)($_GET['page'] ?? 0));
    $pages = max(1, (int)ceil(count($allMonths) / RPAY_GROUP_PAGE_MONTHS));
    $pageNo = min($pageNo, $pages - 1);
    $readMonths = $pickedMonth !== '' ? [$pickedMonth] : array_slice($allMonths, $pageNo * RPAY_GROUP_PAGE_MONTHS, RPAY_GROUP_PAGE_MONTHS);
    $lines = [];
    $data = null;
    foreach ($readMonths as $m) {
        $part = rpay_reem($code, 'GET', '?' . http_build_query(['month' => $m] + array_intersect_key($filters, ['building' => 1])));
        $data = $data ?? $part;
        foreach ($part['dues'] ?? [] as $d) {
            if ($inThisGroup($d)) {
                $lines[] = $d;
            }
        }
    }
    $data = $data ?? ['buildings' => $entryData['buildings'] ?? [], 'heads' => $entryData['heads'] ?? []];
} catch (BinvError $e) {
    rpay_stop($e);
}
usort($lines, function ($a, $b) { return strcmp((string)$b['due_date'], (string)$a['due_date']) ?: ((int)$b['id'] <=> (int)$a['id']); });
$month = $pickedMonth;
$sum = function (array $list, string $key) { return round(array_sum(array_map(function ($d) use ($key) { return (float)($d[$key] ?? 0); }, $list)), 2); };
$due = $sum($lines, 'amount');
$got = $sum($lines, 'received');
$left = $sum($lines, 'left');
$status = $filters['status'] ?? '';
$shown = array_values(array_filter($lines, function ($d) use ($status) {
    $paid = ($d['status'] ?? '') === 'paid';
    return $status === '' || ($status === 'paid' ? $paid : !$paid);
}));
$saved = rpay_saved_periods($conn, array_map(function ($d) { return (int)$d['id']; }, $shown));
$heads = array_values(array_filter(rpay_live_heads($conn, $data['heads'] ?? []), function ($hd) use ($headGroup, $gid) {
    return (int)($headGroup[(int)$hd['id']]['id'] ?? 0) === $gid;
}));
// The links from the cards carry the Payments list's filters, so "Back to payments" comes here's month.
$listFilters = $reemFilters + ['grp' => $gid];
$payUrl = $rpayBase . '/pay.php?' . http_build_query($listFilters) . '&due=';
$self = function (array $extra = []) use ($rpayBase, $gid, $filters) {
    return $rpayBase . '/group_view.php?' . http_build_query(array_merge(['grp' => $gid], $filters, $extra));
};

$pageTitle = $group['name'];
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to payments</a>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mt-2 mb-3">
  <div>
    <div class="d-flex align-items-center gap-2">
      <div class="page-header-label mb-0"><?= h($group['name']) ?></div>
      <?= rpay_kind_tag($group['kind']) ?>
    </div>
    <p class="text-muted small mb-0">
      <?= $month !== '' ? h(rpay_month_label($month)) : ($readMonths ? h(rpay_month_label(end($readMonths)) . (count($readMonths) > 1 ? ' – ' . rpay_month_label($readMonths[0]) : '')) : 'All months') ?><?= $heads ? ' · ' . h(implode(', ', array_column($heads, 'name'))) : '' ?>
      <?php if ($group['period_rule'] === 'prev_month'): ?><br>Each payment is for the month before its due date.<?php endif; ?>
    </p>
  </div>
  <?php $newEntryUrl = $rpayBase . '/entry_form.php?' . http_build_query(array_filter(['grp' => $gid, 'head' => $heads ? (int)$heads[0]['id'] : 0, 'building' => (int)($filters['building'] ?? 0) ?: (int)(array_values(array_filter(rpay_rooms($conn), function ($r) use ($gid) { return (int)$r['group_id'] === $gid; }))[0]['building_id'] ?? 0)])); ?>
  <a href="<?= h($newEntryUrl) ?>" class="btn text-white align-self-center" style="background:var(--primary)"><i class="bi bi-plus-lg"></i> Add entry</a>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
    <input type="hidden" name="grp" value="<?= $gid ?>">
    <div>
      <label class="form-label small text-muted mb-1">Month</label>
      <select name="month" class="form-select form-select-sm">
        <option value="">All months</option>
        <?php foreach ($allMonths as $m): ?>
          <option value="<?= h($m) ?>"<?= $month === $m ? ' selected' : '' ?>><?= h(rpay_month_label($m)) ?></option>
        <?php endforeach; ?>
        <?php if ($month !== '' && !in_array($month, $allMonths, true)): ?><option value="<?= h($month) ?>" selected><?= h(rpay_month_label($month)) ?></option><?php endif; ?>
      </select>
    </div>
    <div>
      <label class="form-label small text-muted mb-1">Building</label>
      <select name="building" class="form-select form-select-sm">
        <option value="">All buildings</option>
        <?php foreach ($data['buildings'] ?? [] as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= (string)($filters['building'] ?? '') === (string)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small text-muted mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Pending</option>
        <option value="paid"<?= $status === 'paid' ? ' selected' : '' ?>>Paid</option>
      </select>
    </div>
    <button class="btn btn-sm btn-light border">Show</button>
  </form>
</div>

<div class="d-flex flex-wrap gap-4 mb-3">
  <?php $gst = function ($st) use ($self) { return $self(['status' => $st, 'page' => null]); }; ?>
  <a class="rp-tot-link text-decoration-none<?= $status === 'paid' ? ' rp-tot-on' : '' ?>" href="<?= h($gst('paid')) ?>" title="Show the paid payments"><div class="small text-muted">Received</div><div class="fs-5 fw-bold text-success"><?= h(rpay_money($got)) ?></div></a>
  <a class="rp-tot-link text-decoration-none<?= $status === 'pending' ? ' rp-tot-on' : '' ?>" href="<?= h($gst('pending')) ?>" title="Show the pending payments"><div class="small text-muted">Still to come</div><div class="fs-5 fw-bold <?= $left > 0 ? 'text-warning-emphasis' : '' ?>"><?= h(rpay_money($left)) ?></div></a>
  <?php if ($status !== ''): ?><a class="align-self-end small text-decoration-none" href="<?= h($self(['status' => null])) ?>"><i class="bi bi-x-lg"></i> Show all</a><?php endif; ?>
</div>

<?php if (!empty($group['has_rooms'])): ?>
  <?php
    $spaceWord = (string)($group['space_word'] ?? 'Bedspace'); // "Bedspace" in a camp, "Partition" in a flat
    // Only this group's rooms (rooms made before rooms had a group show in every group with rooms).
    $rooms = array_filter(rpay_rooms($conn, (int)($filters['building'] ?? 0)), function ($r) use ($gid) { return empty($r['group_id']) || (int)$r['group_id'] === $gid; });
    $bNames = array_column($data['buildings'] ?? [], 'name', 'id');
    $tBeds = array_sum(array_column($rooms, 'beds'));
    $tOcc = array_sum(array_column($rooms, 'occupied'));
  ?>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <div class="fw-semibold fs-5"><?= $spaceWord === 'Bedspace' ? 'Rooms' : h($spaceWord) . 's' ?></div>
    <?php if ($tBeds): ?>
      <span class="small text-muted">· <?= $tBeds ?> <?= h(strtolower($spaceWord)) ?>s · <span class="text-success fw-semibold"><?= $tOcc ?> occupied</span> · <span class="text-warning-emphasis fw-semibold"><?= $tBeds - $tOcc ?> vacant</span></span>
    <?php endif; ?>
    <button class="btn btn-sm btn-light border ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#rpAddRoom"><i class="bi bi-plus-lg"></i> Add room</button>
  </div>
  <form method="post" class="collapse card card-round mb-3" id="rpAddRoom">
    <div class="card-body row g-2 align-items-end py-2">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="add_room">
      <div class="col-sm-3"><label class="form-label small text-muted mb-1">Building</label>
        <select name="building" class="form-select form-select-sm" required>
          <?php foreach ($data['buildings'] ?? [] as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (string)($filters['building'] ?? '3') === (string)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-sm-2"><label class="form-label small text-muted mb-1">Room</label><input name="name" class="form-control form-control-sm" maxlength="80" placeholder="e.g. G6" required></div>
      <div class="col-sm-2"><label class="form-label small text-muted mb-1"><?= h($spaceWord) ?>s</label><input name="beds" type="number" min="0" max="200" class="form-control form-control-sm" value="0"></div>
      <div class="col-sm-2"><label class="form-label small text-muted mb-1">They are</label>
        <select name="status" class="form-select form-select-sm"><option value="vacant">Vacant</option><option value="occupied">Occupied</option></select></div>
      <div class="col-sm-2"><label class="form-label small text-muted mb-1">Note</label><input name="note" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Staff"></div>
      <div class="col-sm-1"><button class="btn btn-sm btn-success w-100">Add</button></div>
    </div>
  </form>
  <?php if (!$rooms): ?>
    <p class="text-muted">No rooms yet. Add the first one.</p>
  <?php endif; ?>
  <?php
    // Each room's payments (all months), for its received / to-be-paid and its summary.
    try {
        $roomPays = rpay_rooms_payments($conn, $code, $rooms);
        // "Not in a room": only this group's payments.
        $roomPays[0] = array_values(array_filter($roomPays[0] ?? [], function ($d) use ($headGroup, $gid) { return (int)($headGroup[(int)($d['head']['id'] ?? 0)]['id'] ?? 0) === $gid; }));
    } catch (BinvError $e) {
        $roomPays = [];
    }
    $openRoom = (int)($_GET['room'] ?? 0);
  ?>
  <div class="rp-room-grid mb-3">
    <?php foreach ($rooms as $r): $pct = $r['beds'] ? round($r['occupied'] / $r['beds'] * 100) : 0;
      $pl = $roomPays[$r['id']] ?? [];
      $rGot = array_sum(array_column($pl, 'received'));
      $rLeft = array_sum(array_column($pl, 'left'));
      // The bell: unpaid payments due soon or past due, and outsiders whose rent is due soon.
      $rBell = count(array_filter($pl, function ($d) { return (bool)rpay_due_alert($d['due_date'] ?? null, ($d['status'] ?? '') !== 'paid'); }));
      foreach (rpay_room_beds($conn, (int)$r['id']) as $bb) {
          if ($bb['status'] === 'occupied' && $bb['occupant_type'] === 'outsider' && rpay_due_alert($bb['due_date'] ?? null, true)) { $rBell++; }
      } ?>
      <?php $roomUrl = $rpayBase . '/room.php?' . http_build_query(['id' => $r['id'], 'grp' => $gid]); ?>
      <a class="rp-room text-decoration-none" href="<?= h($roomUrl) ?>" title="Open <?= h($r['name']) ?>">
        <div class="d-flex justify-content-between align-items-start gap-1">
          <span class="fw-bold fs-5"><?= h($r['name']) ?><?php if ($rBell): ?> <span class="rp-bell-count" title="<?= $rBell ?> due soon or past due"><i class="bi bi-bell-fill"></i> <?= $rBell ?></span><?php endif; ?></span>
          <?php if ($r['note']): ?><span class="badge text-bg-light border"><?= h($r['note']) ?></span><?php endif; ?>
        </div>
        <?php if (empty($filters['building'])): ?><div class="tiny text-muted"><?= h($bNames[$r['building_id']] ?? '') ?></div><?php endif; ?>
        <?php
          $lastPaid = null;
          foreach ($pl as $x) {
              if ((float)($x['received'] ?? 0) > 0) { $lastPaid = $x; break; }
          }
        ?>
        <?php if ($r['beds']): ?>
          <div class="progress mt-2 mb-1" style="height:6px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
        <?php endif; ?>
        <dl class="rp-kv mt-2 mb-0">
          <dt><?= h($spaceWord) ?>s</dt><dd><?= $r['beds'] ?></dd>
          <dt>Occupied</dt><dd class="text-success"><?= $r['occupied'] ?></dd>
          <dt>Vacant</dt><dd class="<?= $r['vacant'] ? 'text-warning-emphasis' : '' ?>"><?= $r['vacant'] ?></dd>
          <?php if (!empty($group['allows_staff'])): ?><dt>Staff · Outsiders</dt><dd><?= $r['staff'] ?> · <?= $r['outsiders'] ?></dd><?php endif; ?>
          <dt>Rent a month</dt><dd><?= $r['rent'] > 0 ? '<span class="rp-rent-hl">' . h(rpay_money($r['rent'])) . '</span>' : '—' ?></dd>
          <dt>Received</dt><dd class="text-success"><span class="rp-sublink" data-href="<?= h($roomUrl . '&tab=history&st=paid') ?>" title="Show the paid payments"><?= h(rpay_money($rGot)) ?></span></dd>
          <dt>To be paid</dt><dd class="<?= $rLeft > 0 ? 'text-warning-emphasis' : '' ?>"><span class="rp-sublink" data-href="<?= h($roomUrl . '&tab=history&st=pending') ?>" title="Show the payments still to come"><?= h(rpay_money($rLeft)) ?></span></dd>
          <dt>Last payment</dt><dd><?= $lastPaid ? h(rpay_money($lastPaid['received'])) . '<div class="tiny text-muted fw-normal">' . h(rpay_date($lastPaid['paid_on'] ?? '')) . '</div>' : '—' ?></dd>
        </dl>
        <span class="btn btn-sm btn-light border w-100 mt-2"><i class="bi bi-card-list"></i> Details</span>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Payments that are in no room: move each to the room it belongs to -->
  <?php if (!empty($roomPays[0])): ?>
    <div class="d-flex align-items-baseline gap-2 mb-2">
      <div class="fw-semibold fs-5">Not in a room</div>
      <span class="small text-muted"><?= count($roomPays[0]) ?> <?= count($roomPays[0]) === 1 ? 'payment' : 'payments' ?> · move each to its room</span>
    </div>
    <?php
      $rowsList = $roomPays[0];
      $grp = $gid;
      $moveRooms = array_column(rpay_rooms($conn), 'name', 'id');
      $hereRoom = 0;
      $movePostUrl = $rpayBase . '/group_view.php?' . http_build_query(['grp' => $gid] + $filters);
      $rpayUi = 'rows';
      require __DIR__ . '/includes/rpay_ui.php';
    ?>
    <div class="mb-4"></div>
  <?php endif; ?>
<?php endif; ?>
<?php if (!empty($group['has_rooms']) && $status !== ''): ?><div class="fw-semibold fs-5 mb-2" id="rpList"><?= $status === 'paid' ? 'Paid' : 'Pending' ?> payments</div><?php endif; ?>
<?php if (empty($group['has_rooms']) || $status !== ''): /* groups without rooms list their payments here; with rooms, when Received / Still to come is clicked */ ?>

<?php if (!$shown): ?>
  <div class="card card-round"><div class="card-body text-muted text-center py-5">No <?= h($group['name']) ?> payments<?= $month !== '' ? ' for ' . h(rpay_month_label($month)) : '' ?><?= $status !== '' || !empty($filters['building']) ? ' with these filters' : '' ?>.</div></div>
<?php endif; ?>

<?php
  $lastMonth = null;
  // The "For" column only when there is something to show in it: a period rule, or a period typed by hand.
  $showFor = $group['period_rule'] !== 'none' || (bool)array_intersect_key($saved, array_flip(array_map(function ($d) { return (int)$d['id']; }, $shown)));

  // Headings: by room for a group with rooms (the room number in the unit or the title -
  // "G03", "G3", "Room G03"), otherwise by month. Each heading has its own totals.
  $headOf = function (array $d) { return (string)($d['month'] ?? ''); };
  $headLabel = function (string $k) { return rpay_month_label($k); };
  if (!empty($group['has_rooms'])) {
      $roomList = rpay_rooms($conn);
      $roomKey = function (string $s) { return preg_match('/\bG\s*0*(\d{1,2})\b/i', $s, $m) ? 'G' . (int)$m[1] : ''; };
      $byKey = [];
      foreach ($roomList as $r) {
          $k = $roomKey($r['name']);
          $byKey[$r['building_id'] . ':' . ($k !== '' ? $k : strtolower($r['name']))] = $r;
      }
      $headOf = function (array $d) use ($roomKey, $byKey) {
          $b = (int)($d['building']['id'] ?? 0);
          foreach ([(string)($d['unit'] ?? ''), (string)($d['title'] ?? '')] as $s) {
              $k = $roomKey($s);
              if ($k !== '' && isset($byKey[$b . ':' . $k])) {
                  return 'r' . $byKey[$b . ':' . $k]['id'];
              }
          }
          return 'zz-other';
      };
      $headLabel = function (string $k) use ($roomList) {
          return $k === 'zz-other' ? 'Other (no room matched)' : ($roomList[(int)substr($k, 1)]['name'] ?? $k);
      };
      $roomOrder = array_flip(array_map(function ($id) { return 'r' . $id; }, array_keys($roomList)));
      usort($shown, function ($a, $b) use ($headOf, $roomOrder) {
          $ka = $headOf($a);
          $kb = $headOf($b);
          return (($roomOrder[$ka] ?? PHP_INT_MAX) <=> ($roomOrder[$kb] ?? PHP_INT_MAX)) ?: strcmp((string)$b['due_date'], (string)$a['due_date']);
      });
  }
  $headTotals = [];
  foreach ($shown as $d) {
      $k = $headOf($d);
      $headTotals[$k] = $headTotals[$k] ?? ['n' => 0, 'got' => 0.0, 'left' => 0.0];
      $headTotals[$k]['n']++;
      $headTotals[$k]['got'] += (float)($d['received'] ?? 0);
      $headTotals[$k]['left'] += (float)($d['left'] ?? 0);
  }
?>
<div class="rp-pay-grid">
  <?php foreach ($shown as $d): ?>
    <?php if ($headOf($d) !== $lastMonth): $lastMonth = $headOf($d); $ht = $headTotals[$lastMonth]; ?>
      <?php if (!empty($group['has_rooms'])): ?>
        <div class="rp-month d-flex flex-wrap align-items-baseline gap-2">
          <span><?= h($headLabel($lastMonth)) ?></span>
          <span class="small fw-normal text-muted"><?= $ht['n'] ?> <?= $ht['n'] === 1 ? 'payment' : 'payments' ?></span>
          <span class="small fw-semibold text-success ms-auto"><?= h(rpay_money($ht['got'])) ?> received</span>
          <?php if ($ht['left'] > 0): ?><span class="small fw-semibold text-warning-emphasis">· <?= h(rpay_money($ht['left'])) ?> to come</span><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="rp-month"><?= h($headLabel($lastMonth)) ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php
      $paid = ($d['status'] ?? '') === 'paid';
      $partly = !$paid && (float)($d['received'] ?? 0) > 0;
      $per = rpay_group_period($d, $group['period_rule'], $saved);
      $openUrl = $rpayBase . '/due_detail.php?' . http_build_query($listFilters + ['due' => (int)$d['id']]);
    ?>
    <div class="rp-pay <?= $paid ? 'rp-paid' : ($partly ? 'rp-partly' : 'rp-pending') ?>">
      <?php if ($showFor): ?>
        <div class="rp-for">
          <?php if ($per): ?>
            <div class="fw-bold lh-sm"><?= h($per['title']) ?></div>
            <?php if ($per['range'] !== ''): ?><div class="tiny text-muted"><?= h($per['range']) ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="rp-what">
        <a class="fw-semibold text-decoration-none text-body" href="<?= h($openUrl) ?>"><?= h($d['title'] ?? '') ?></a>
        <div class="tiny text-muted"><?= h(implode(' · ', array_filter([$d['building']['name'] ?? '', $d['unit'] ?? '']))) ?></div>
      </div>
      <div class="rp-due">
        <div class="rp-date rp-date-due"><i class="bi bi-calendar-event"></i> Due on <b><?= h(rpay_date($d['due_date'] ?? '')) ?></b></div>
        <?php if (($paid || $partly) && !empty($d['paid_on'])): ?>
          <div class="rp-date rp-date-got mt-1"><i class="bi bi-check2"></i> Paid on <b><?= h(rpay_date($d['paid_on'])) ?></b></div>
        <?php else: ?>
          <div class="tiny text-warning-emphasis fw-semibold mt-1">Not paid yet</div>
        <?php endif; ?>
<?php if ($alert = rpay_due_alert($d['due_date'] ?? null, !$paid)): ?><div class="mt-1"><?= rpay_due_bell($alert) ?></div><?php endif; ?>
      </div>
      <div class="rp-amt fw-bold"><?= h(rpay_money($d['amount'] ?? 0)) ?></div>
      <div class="rp-st">
        <span class="badge text-bg-<?= $paid ? 'success' : ($partly ? 'info' : 'warning') ?>"><?= $paid ? 'Paid' : ($partly ? 'Partly paid' : 'Pending') ?></span>
        <?php if ($paid || $partly): ?>
          <div class="tiny text-success fw-semibold"><?= h(rpay_money($d['received'] ?? 0)) ?> received</div>
          <?php if (!empty($d['account']['name'])): ?><div class="tiny text-muted"><?= h($d['account']['name']) ?></div><?php endif; ?>
          <?php if ($partly): ?><div class="tiny text-warning-emphasis fw-semibold"><?= h(rpay_money($d['left'] ?? 0)) ?> to come</div><?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="rp-act">
        <?php if ($paid): ?>
          <a href="<?= h($payUrl . (int)$d['id']) ?>" class="btn btn-sm btn-light border">Receipts</a>
        <?php elseif ($partly): ?>
          <a href="<?= h($payUrl . (int)$d['id']) ?>" class="btn btn-sm btn-success"><i class="bi bi-plus-lg"></i> Add</a>
        <?php else: ?>
          <a href="<?= h($payUrl . (int)$d['id']) ?>" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Mark paid</a>
        <?php endif; ?>
        <a href="<?= h($openUrl) ?>" class="btn btn-sm btn-light border" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($month === '' && $pages > 1): ?>  <div class="d-flex justify-content-between align-items-center mt-3">    <div><?php if ($pageNo > 0): ?><a class="btn btn-light border" href="<?= h($self(['page' => $pageNo - 1])) ?>"><i class="bi bi-chevron-left"></i> Newer months</a><?php endif; ?></div>    <span class="small text-muted">Page <?= $pageNo + 1 ?> of <?= $pages ?></span>    <div><?php if ($pageNo < $pages - 1): ?><a class="btn btn-light border" href="<?= h($self(['page' => $pageNo + 1])) ?>">Older months <i class="bi bi-chevron-right"></i></a><?php endif; ?></div>  </div><?php endif; ?>
<?php endif; /* the payments list */ ?>
<style>
.rp-pay-grid{ display:flex; flex-direction:column; gap:8px; }
.rp-alert{ display:inline-flex; align-items:center; gap:4px; padding:1px 8px; border-radius:999px; font-size:.75rem; font-weight:700; white-space:nowrap; }
.rp-alert-overdue{ background:#fde2e4; color:#b02a37; border:1px solid #f5b5bb; }
.rp-alert-soon{ background:#fff3cd; color:#8a5300; border:1px solid #ffe08a; }
.rp-alert .bi-bell-fill{ animation:rpRing 1.6s ease-in-out infinite; transform-origin:top center; }
@keyframes rpRing{ 0%,60%,100%{ transform:rotate(0); } 10%{ transform:rotate(14deg); } 20%{ transform:rotate(-12deg); } 30%{ transform:rotate(8deg); } 40%{ transform:rotate(-6deg); } }
.rp-bell-count{ display:inline-flex; align-items:center; gap:3px; padding:1px 8px; border-radius:999px; background:#dc3545; color:#fff; font-size:.75rem; font-weight:700; vertical-align:middle; }
body.dark-mode .rp-alert-overdue{ background:#4a1a20; color:#ffb3ba; border-color:#7a2a33; }
body.dark-mode .rp-alert-soon{ background:#4a3b0b; color:#ffe2a0; border-color:#7a6213; }
.rp-room-grid{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
.rp-sublink{ cursor:pointer; text-decoration:underline dotted; text-underline-offset:3px; }
.rp-sublink:hover{ text-decoration:underline; }
.rp-tot-link{ display:block; color:inherit; border-radius:10px; padding:4px 8px; margin:-4px -8px; }
.rp-tot-link:hover{ background:rgba(0,0,0,.04); }
.rp-tot-on{ box-shadow:0 0 0 2px var(--primary) inset; }
@media (max-width:991px){ .rp-room-grid{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:575px){ .rp-room-grid{ grid-template-columns:1fr; } }
.rp-room-grid .rp-room{ padding:16px 18px; }
.rp-room-grid .rp-kv{ font-size:.92rem; gap:6px 14px; }
.rp-room{ cursor:pointer; }
.rp-room.rp-room-on{ box-shadow:0 0 0 2px var(--primary) inset; }
.rp-kv{ display:grid; grid-template-columns:auto 1fr; gap:3px 10px; font-size:.85rem; }
.rp-kv dt{ font-weight:400; color:#6c757d; }
.rp-kv dd{ margin:0; text-align:right; font-weight:700; }
.rp-room-money{ display:flex; justify-content:space-between; gap:8px; border-top:1px dashed #e3e6ea; padding-top:6px; }
.rp-room-money span{ display:block; font-size:.7rem; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; }
.rp-room-money b{ font-size:.92rem; }
.rp-rent-hl{ display:inline-block; background:#fff3cd; border:1px solid #ffe08a; color:#7a5a00; font-weight:700; border-radius:6px; padding:1px 8px; }
body.dark-mode .rp-rent-hl{ background:#4a3b0b; border-color:#7a6213; color:#ffe2a0; }
.rd-sum{ display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:8px; }
.rd-sum > div{ border:1px solid #e3e6ea; border-radius:10px; padding:8px 12px; background:#fafbfc; }
.rd-sum span{ display:block; font-size:.7rem; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; }
.rd-sum b{ font-size:1.05rem; }
body.dark-mode .rd-sum > div{ background:#1e293b; border-color:#334155; }
.rd-timeline{ list-style:none; margin:0; padding:0 0 0 18px; border-left:2px solid #e3e6ea; }
.rd-timeline li{ position:relative; padding:8px 0 10px 12px; }
.rd-timeline li::before{ content:''; position:absolute; left:-25px; top:13px; width:12px; height:12px; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 1px #cfd5dc; }
.rd-timeline li.rd-paid::before{ background:#2e9e5b; } .rd-timeline li.rd-pend::before{ background:#e0a800; } .rd-timeline li.rd-part::before{ background:#2f80c4; }
.rd-table thead th{ font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#8a9099; background:#f8f9fb; }
body.dark-mode .rd-table thead th{ background:#1e293b; color:#94a3b8; }
.rp-room{ display:block; background:#fff; border:1px solid #e3e6ea; border-radius:12px; padding:10px 12px; color:inherit; text-decoration:none; }
.rp-room:hover{ box-shadow:0 4px 12px rgba(0,0,0,.08); color:inherit; }
.rp-room .tiny{ font-size:.75rem; }
body.dark-mode .rp-room{ background:#1e293b; border-color:#334155; }
.rp-pay{ display:flex; align-items:center; gap:14px; border:1px solid #e3e6ea; border-radius:10px; padding:8px 12px; }
.rp-pay .rp-for{ width:150px; flex-shrink:0; }
.rp-pay .rp-what{ flex:1 1 220px; min-width:0; }
.rp-pay .rp-due{ width:190px; flex-shrink:0; }
.rp-pay .rp-due .rp-date{ display:block; width:max-content; }
.rp-pay .rp-amt{ width:120px; text-align:right; flex-shrink:0; }
.rp-pay .rp-st{ width:230px; flex-shrink:0; }
.rp-pay .rp-act{ display:flex; gap:6px; flex-shrink:0; }
.rp-pay .tiny{ font-size:.78rem; }
.rp-date{ display:inline-block; padding:1px 8px; border-radius:6px; font-size:.78rem; font-weight:600; background:#fff; border:1px solid #d6dbe2; color:#334155; white-space:nowrap; }
.rp-date-due{ background:#e8eefc; border-color:#c7d4f5; color:#1e3a8a; }
.rp-date-got{ background:#d8f2e1; border-color:#a9dcbc; color:#0f5132; }
body.dark-mode .rp-date{ background:#0f172a; border-color:#475569; color:#e2e8f0; }
body.dark-mode .rp-date-due{ background:#1e3a8a; border-color:#2f4370; color:#dbe4ff; }
body.dark-mode .rp-date-got{ background:#1d4d33; border-color:#2e7a52; color:#c9f2d8; }
.rp-pay.rp-paid{ background:#eaf7ee; border-color:#c3e6cf; border-left:4px solid #2e9e5b; }
.rp-pay.rp-pending{ background:#fff8e1; border-color:#f3e2a6; border-left:4px solid #e0a800; }
.rp-pay.rp-partly{ background:#e8f3fc; border-color:#c5def3; border-left:4px solid #2f80c4; }
body.dark-mode .rp-pay.rp-paid{ background:#15301f; border-color:#24513a; border-left-color:#3fbf73; }
body.dark-mode .rp-pay.rp-pending{ background:#33290f; border-color:#5a4919; border-left-color:#e0a800; }
body.dark-mode .rp-pay.rp-partly{ background:#142b3d; border-color:#23476a; border-left-color:#4b9fe0; }
@media (max-width:768px){ .rp-pay{ flex-wrap:wrap; gap:6px 12px; } .rp-pay .rp-for{ width:auto; } .rp-pay .rp-what{ flex-basis:100%; order:-1; } .rp-pay .rp-amt{ width:auto; } .rp-pay .rp-st{ width:auto; } .rp-pay .rp-due{ width:auto; } }
.rp-month{ font-weight:700; font-size:1.05rem; padding-top:6px; border-bottom:1px solid rgba(0,0,0,.08); }
.rp-month:first-child{ padding-top:0; }
body.dark-mode .rp-month{ border-bottom-color:rgba(255,255,255,.1); }
.rp-got{ background:#e7f6ec; border:1px solid #b7e2c4; color:#146c43; border-radius:10px; padding:6px 10px; font-weight:700; font-size:.9rem; }
.rpay-fixed{ background:#e8eefc; color:#1e3a8a; font-weight:600; }
.rpay-dynamic{ background:#fdf1dc; color:#8a5300; font-weight:600; }
.rh-c0{ background:#eef4ff; border-color:#cfdcf7; } .rh-c1{ background:#eefaf3; border-color:#c8ebd6; }
.rh-c2{ background:#fff6e8; border-color:#f3dcb4; } .rh-c3{ background:#f6efff; border-color:#ddcdf5; }
.rh-c4{ background:#ffeff2; border-color:#f4cdd5; } .rh-c5{ background:#eaf8fb; border-color:#c3e6ee; }
.rh-c6{ background:#f9f7e6; border-color:#e9e2b5; } .rh-c7{ background:#f1f3f6; border-color:#d6dbe2; }
.rh-c8{ background:#effaf8; border-color:#c6e9e2; } .rh-c9{ background:#fdf0e9; border-color:#f1d2c0; }
body.dark-mode .rp-got{ background:#163a26; border-color:#24603d; color:#8fe0ad; }
body.dark-mode .rpay-fixed{ background:#1e3a8a; color:#dbe4ff; }
body.dark-mode .rpay-dynamic{ background:#5c3a00; color:#ffe2b0; }
body.dark-mode .rh-c0{ background:#1d2a45; border-color:#2f4370; } body.dark-mode .rh-c1{ background:#183429; border-color:#265541; }
body.dark-mode .rh-c2{ background:#3a2c14; border-color:#5c4620; } body.dark-mode .rh-c3{ background:#2b2142; border-color:#45356a; }
body.dark-mode .rh-c4{ background:#3a1f27; border-color:#5e3340; } body.dark-mode .rh-c5{ background:#16323a; border-color:#24505c; }
body.dark-mode .rh-c6{ background:#33301a; border-color:#524d2a; } body.dark-mode .rh-c7{ background:#232a35; border-color:#38424f; }
body.dark-mode .rh-c8{ background:#163430; border-color:#24544d; } body.dark-mode .rh-c9{ background:#3a2419; border-color:#5d3a29; }
</style>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
<script>
// A room card's Received / To be paid open that room's payments, paid or pending (the card itself opens the room).
document.querySelectorAll('.rp-sublink').forEach(function (s) {
  s.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); window.location.href = s.dataset.href; });
});
// Received / Still to come clicked: go straight to the list.
if (new URLSearchParams(location.search).get('status')) {
  var l = document.getElementById('rpList');
  if (l) { l.scrollIntoView({ block: 'start' }); }
}
</script>
