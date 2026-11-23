<?php
/**
 * Recurring Payments — one month's payments, and marking them paid.
 * Reem creates each line on its entry's day of the month; this page lists what Reem
 * has and puts a line back to pending. Receiving money for one, all or a part, is pay.php.
 *
 *   index.php                                  this month, every building
 *   index.php?month=2026-09&building=3&unit=Shop+3&status=pending
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

// Only what Reem needs to hear; Reem decides what a wrong value means.
$filters = [];
foreach (['month', 'building', 'head', 'unit', 'status', 'grp'] as $k) { // grp: a saifsys group, never sent to Reem
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $filters[$k] = $_GET[$k];
    }
}
$selfUrl = $rpayBase . '/index.php' . ($filters ? '?' . http_build_query($filters) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'save_groups') {
        // Which income head is in which group: saifsys's own (rpay_head_group_members); Reem is only read.
        try {
            $known = [];
            foreach (rpay_reem($code, 'GET', '/heads')['heads'] ?? [] as $hd) {
                $known[(int)$hd['id']] = true;
            }
            $map = json_decode((string)($_POST['map'] ?? ''), true);
            $new = json_decode((string)($_POST['new_groups'] ?? ''), true);
            $error = null;
            $madeN = 0;
            if (is_array($new) && $new) {
                // Groups made in the box first; heads dropped in them point to them by their key.
                $made = rpay_make_groups($conn, $new, (int)current_user_id());
                if (is_string($made)) {
                    $error = $made;
                } else {
                    $madeN = count($made);
                    foreach ((array)$map as $hid => $gid) {
                        if (isset($made[(string)$gid])) {
                            $map[$hid] = $made[(string)$gid];
                        }
                    }
                }
            }
            if ($error === null && is_array($map) && $map) {
                $error = rpay_save_head_groups($conn, $map, $known);
            } elseif ($error === null && !$madeN) {
                $error = 'Nothing to save.';
            }
            binv_flash($error ?? ($madeN ? $madeN . ' new ' . ($madeN === 1 ? 'group' : 'groups') . ' made. ' : '') . 'Groups saved.', $error ? 'danger' : 'success');
        } catch (BinvError $e) {
            binv_flash($e->getMessage(), 'danger');
        } catch (PDOException $e) {
            error_log('recurrent income: groups not saved: ' . $e->getMessage());
            binv_flash('The groups could not be saved.', 'danger');
        }
        header('Location: ' . $selfUrl . (strpos($selfUrl, '?') === false ? '?' : '&') . 'groups=1');
        exit;
    }
    // Otherwise only "set pending" is done here; marking paid has its own page, for the account and the file.
    $dueId = (int)($_POST['due'] ?? 0);
    try {
        $due = rpay_reem($code, 'POST', '/dues/' . $dueId . '/pending');
        binv_flash('"' . ($due['title'] ?? 'Payment') . '" is now pending. Its receipts are deleted and the money is out of their accounts again.');
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $selfUrl);
    exit;
}

// What Reem hears: everything but the group and the status. Both are applied here, after
// the group cards are added up over all of the month's payments.
$grpPick = $filters['grp'] ?? '';
// A group (not "none") has its own page: links back to the list with a group go there.
if ($grpPick !== '' && $grpPick !== 'none' && ctype_digit($grpPick)) {
    header('Location: ' . $rpayBase . '/group_view.php?' . http_build_query(['grp' => (int)$grpPick] + array_intersect_key($filters, ['month' => 1, 'building' => 1, 'status' => 1])));
    exit;
}
$reemFilters = $filters;
unset($reemFilters['grp'], $reemFilters['status']);
try {
    $data = rpay_reem($code, 'GET', $reemFilters ? '?' . http_build_query($reemFilters) : '');
} catch (BinvError $e) {
    rpay_stop($e);
}
// A new income head goes to the group its name points to (no match: Other), and says so once.
try {
    $placed = rpay_auto_group_new_heads($conn, rpay_live_heads($conn, $data['heads'] ?? []));
} catch (PDOException $e) {
    error_log('recurrent income: new heads not grouped: ' . $e->getMessage());
    $placed = [];
}
if ($placed) {
    binv_flash(implode(' ', array_map(function ($p) {
        return 'New income head "' . $p['head'] . '" is now in ' . $p['group'] . '.';
    }, $placed)) . ' Change it with the ⓘ next to Groups.', 'info');
}
$headGroup = rpay_head_group_map($conn); // head id => its group
$groupOf = function (array $d) use ($headGroup): int {
    return (int)($headGroup[(int)($d['head']['id'] ?? 0)]['id'] ?? 0);
};
$sum = function (array $list, string $key) { return round(array_sum(array_map(function ($d) use ($key) { return (float)($d[$key] ?? 0); }, $list)), 2); };

// The group cards (shown under the Groups button): each group's payments this month.
$groupCards = [];
foreach ($data['dues'] ?? [] as $d) {
    $gid = $groupOf($d);
    $g = $headGroup[(int)($d['head']['id'] ?? 0)] ?? ['name' => 'Not in a group', 'kind' => ''];
    $groupCards[$gid] = $groupCards[$gid] ?? ['id' => $gid, 'name' => $g['name'], 'kind' => $g['kind'], 'lines' => []];
    $groupCards[$gid]['lines'][] = $d;
}
// The bell: payments whose due date has come and that are not paid in full, in every month
// (from the first entry, 12 months back at most), not only the month on screen.
$today = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
$dueNow = []; // group id => how many
try {
    $firsts = array_filter(array_map(function ($e) { return (string)($e['first_month'] ?? ''); }, rpay_reem($code, 'GET', '/entries')['entries'] ?? []));
    $from = max($firsts ? min($firsts) : substr($today, 0, 7), (new DateTime('first day of this month'))->modify('-11 months')->format('Y-m'));
    $shownMonth = (string)($data['month'] ?? '');
    for ($m = DateTime::createFromFormat('!Y-m', $from); $m && $m->format('Y-m') <= substr($today, 0, 7); $m->modify('+1 month')) {
        $mm = $m->format('Y-m');
        $list = $mm === $shownMonth ? ($data['dues'] ?? [])
            : (rpay_reem($code, 'GET', '?' . http_build_query(['month' => $mm] + array_intersect_key($filters, ['building' => 1])))['dues'] ?? []);
        foreach ($list as $d) {
            if (($d['status'] ?? '') !== 'paid' && (string)($d['due_date'] ?? '') <= $today) {
                $gid = $groupOf($d);
                $dueNow[$gid] = ($dueNow[$gid] ?? 0) + 1;
                if (!isset($groupCards[$gid])) { // a group with nothing this month but something still owed
                    $g = $headGroup[(int)($d['head']['id'] ?? 0)] ?? ['name' => 'Not in a group', 'kind' => ''];
                    $groupCards[$gid] = ['id' => $gid, 'name' => $g['name'], 'kind' => $g['kind'], 'lines' => []];
                }
            }
        }
    }
} catch (BinvError $e) {
    $dueNow = []; // the bells are left off rather than the page failing
}
$dueTotal = array_sum($dueNow);
// Every group has its card, even with nothing this month (it shows zeros).
foreach (rpay_all_groups($conn) as $agid => $ag) {
    if (!isset($groupCards[$agid])) {
        $groupCards[$agid] = ['id' => (int)$agid, 'name' => $ag['name'], 'kind' => $ag['kind'], 'lines' => []];
    }
}

foreach ($groupCards as &$c) {
    $c += ['count' => count($c['lines']), 'due' => $sum($c['lines'], 'amount'), 'received' => $sum($c['lines'], 'received'), 'left' => $sum($c['lines'], 'left')];
    $c['bell'] = $dueNow[$c['id']] ?? 0;
}
unset($c);
uasort($groupCards, function ($a, $b) { return ((int)($a['id'] === 0) <=> (int)($b['id'] === 0)) ?: ($b['due'] <=> $a['due']) ?: strcasecmp($a['name'], $b['name']); });

// The list: the picked group only ("none": heads in no group), then the status.
$rows = $data['dues'] ?? [];
if ($grpPick !== '') {
    $rows = array_values(array_filter($rows, function ($d) use ($groupOf, $grpPick) {
        return $grpPick === 'none' ? $groupOf($d) === 0 : $groupOf($d) === (int)$grpPick;
    }));
}
$pend = array_values(array_filter($rows, function ($d) { return ($d['status'] ?? '') !== 'paid'; }));
$paidL = array_values(array_filter($rows, function ($d) { return ($d['status'] ?? '') === 'paid'; }));
$data['totals'] = [
    'pending' => $sum($rows, 'left'), 'paid' => $sum($rows, 'received'),
    'pending_count' => count($pend), 'paid_count' => count($paidL),
    'partly_count' => count(array_filter($pend, function ($d) { return (float)($d['received'] ?? 0) > 0; })),
];
$st = $filters['status'] ?? '';
$data['dues'] = $st === 'pending' ? $pend : ($st === 'paid' ? $paidL : $rows);
// A link to this list with one group picked (null: every group), keeping the other filters.
$grpLink = function ($gid) use ($rpayBase, $filters) {
    $q = $filters;
    unset($q['grp']);
    if ($gid !== null) {
        $q['grp'] = $gid === 0 ? 'none' : $gid;
    }
    return $rpayBase . '/index.php' . ($q ? '?' . http_build_query($q) : '');
};
$openGroups = true; // the group cards show at once; the Groups button folds them away
$month = (string)($data['month'] ?? date('Y-m'));
$buildings = $data['buildings'] ?? [];
$heads = rpay_live_heads($conn, $data['heads'] ?? [], (int)($filters['head'] ?? 0)); // heads deleted in saifsys are left out
$units = $data['units'] ?? [];
$dues = $data['dues'] ?? [];
$totals = $data['totals'] ?? [];
$status = $filters['status'] ?? '';
$payUrl = $rpayBase . '/pay.php?' . ($filters ? http_build_query($filters) . '&' : '') . 'due=';
$editUrl = $rpayBase . '/due_edit.php?' . ($filters ? http_build_query($filters) . '&' : '') . 'due=';

$pageTitle = 'Payments';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="page-header-label mb-1">Recurrent Income</div>
    <p class="text-muted mb-0">What should come in for <?= h(rpay_month_label($month)) ?>. Each line is created on its entry's day of the month.</p>
  </div>
  <a href="<?= h($rpayBase) ?>/entry_form.php" class="btn text-white" style="background:var(--primary)">
    <i class="bi bi-plus-lg"></i> Add entry
  </a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <?php $stLink = function ($st) use ($rpayBase, $filters) { $q = $filters; unset($q['grp']); $q['status'] = $st; return $rpayBase . '/index.php?' . http_build_query($q); }; ?>
    <a class="card card-round h-100 text-decoration-none text-body rp-total-link<?= ($filters['status'] ?? '') === 'pending' ? ' rp-total-on' : '' ?>" href="<?= h($stLink('pending')) ?>" title="Show the pending payments"><div class="card-body">
      <div class="small text-muted">Pending</div>
      <div class="rpay-total text-warning-emphasis"><?= h(rpay_money($totals['pending'] ?? 0)) ?></div>
      <div class="small text-muted"><?= (int)($totals['pending_count'] ?? 0) ?> payment<?= (int)($totals['pending_count'] ?? 0) === 1 ? '' : 's' ?><?= (int)($totals['partly_count'] ?? 0) > 0 ? ', ' . (int)$totals['partly_count'] . ' partly paid' : '' ?></div>
    </div></a>
  </div>
  <div class="col-6 col-lg-3">
    <a class="card card-round h-100 text-decoration-none text-body rp-total-link<?= ($filters['status'] ?? '') === 'paid' ? ' rp-total-on' : '' ?>" href="<?= h($stLink('paid')) ?>" title="Show the paid payments"><div class="card-body">
      <div class="small text-muted">Received</div>
      <div class="rpay-total text-success"><?= h(rpay_money($totals['paid'] ?? 0)) ?></div>
      <div class="small text-muted"><?= (int)($totals['paid_count'] ?? 0) ?> paid in full</div>
    </div></a>
  </div>
</div>

<div class="card card-round mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end" action="<?= h($rpayBase) ?>/index.php">
      <div class="col-6 col-md">
        <label class="form-label small fw-semibold mb-1">Month</label>
        <input type="month" name="month" class="form-control" value="<?= h($month) ?>">
      </div>
      <div class="col-6 col-md">
        <label class="form-label small fw-semibold mb-1">Building</label>
        <select name="building" class="form-select" data-search>
          <option value="">All buildings</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>"<?= (string)($filters['building'] ?? '') === (string)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
<?php if ($grpPick !== ''): ?><input type="hidden" name="grp" value="<?= h($grpPick) ?>"><?php endif; ?>
      <div class="col-6 col-md">
        <label class="form-label small fw-semibold mb-1">Income head</label>
        <select name="head" class="form-select" data-search>
          <option value="">All</option>
          <?php foreach ($heads as $head): ?>
            <option value="<?= (int)$head['id'] ?>"<?= (string)($filters['head'] ?? '') === (string)$head['id'] ? ' selected' : '' ?>><?= h($head['name']) ?></option>
          <?php endforeach; ?>
          <option value="none"<?= ($filters['head'] ?? '') === 'none' ? ' selected' : '' ?>>No income head</option>
        </select>
      </div>
      <div class="col-6 col-md">
        <label class="form-label small fw-semibold mb-1">Shop / unit</label>
        <select name="unit" class="form-select" data-search>
          <option value="">All</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= h($u) ?>"<?= strcasecmp((string)($filters['unit'] ?? ''), (string)$u) === 0 ? ' selected' : '' ?>><?= h($u) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select" data-no-search>
          <option value="">Pending and paid</option>
          <option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Pending</option>
          <option value="paid"<?= $status === 'paid' ? ' selected' : '' ?>>Paid</option>
        </select>
      </div>
      <div class="col-6 col-md d-flex gap-2">
        <button class="btn btn-light border flex-fill"><i class="bi bi-funnel"></i> Show</button>
        <div class="btn-group flex-fill" role="group">
          <?php if ($groupCards): ?>
            <button type="button" class="btn btn-light border<?= $grpPick !== '' ? ' border-primary' : '' ?>" data-bs-toggle="collapse" data-bs-target="#rpGroups" aria-expanded="<?= $openGroups ? 'true' : 'false' ?>">
              <i class="bi bi-grid-3x3-gap"></i> Groups<?php if ($dueTotal): ?> <span class="rp-bell ms-1" title="<?= $dueTotal ?> due and not paid"><i class="bi bi-bell-fill"></i> <?= $dueTotal ?></span><?php endif; ?>
            </button>
          <?php endif; ?>
          <?php $loose = count(array_filter(rpay_live_heads($conn, $data['heads'] ?? []), function ($hd) use ($headGroup) { return !isset($headGroup[(int)$hd['id']]); })); ?>
          <button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#rpGroupInfo" title="What is in each group (drag to change)<?= $loose ? ' · ' . $loose . ' not in a group' : '' ?>">
            <i class="bi bi-info-circle"></i><?php if ($loose): ?> <span class="badge text-bg-warning"><?= $loose ?></span><?php endif; ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($groupCards): ?>
<div class="collapse<?= $openGroups ? ' show' : '' ?> mb-3" id="rpGroups">
  <div class="rp-grid">
    <?php foreach ($groupCards as $gc): $on = $grpPick !== '' && ($gc['id'] === 0 ? $grpPick === 'none' : $grpPick === (string)$gc['id']); ?>
      <a class="rp-card<?= $gc['id'] ? ' rh-c' . ($gc['id'] % 10) : ' rp-none' ?><?= $on ? ' rp-on' : '' ?>" href="<?= h($gc['id'] ? $rpayBase . '/group_view.php?' . http_build_query(['grp' => $gc['id']] + array_intersect_key($filters, ['month' => 1, 'building' => 1])) : $grpLink($on ? null : 0)) ?>" title="<?= $gc['id'] ? 'Open ' . h($gc['name']) : ($on ? 'Show every group' : 'Show the payments in no group') ?>">
        <div class="d-flex justify-content-between align-items-start gap-1 mb-1">
          <span class="fw-semibold"><?= h($gc['name']) ?></span>
          <span class="d-flex align-items-center gap-2 text-nowrap">
            <?php if ($gc['bell']): ?><span class="rp-bell" title="<?= $gc['bell'] ?> due and not paid (all months)"><i class="bi bi-bell-fill"></i> <?= $gc['bell'] ?></span><?php endif; ?>
            <span class="small text-muted"><?= $gc['count'] ?> <?= $gc['count'] === 1 ? 'payment' : 'payments' ?></span>
          </span>
        </div>
        <?= rpay_kind_tag($gc['kind']) ?>
        <div class="fs-5 fw-bold mt-1"><?= h(rpay_money($gc['due'])) ?></div>
        <div class="small"><span class="text-success"><?= h(rpay_money($gc['received'])) ?> in</span><?php if ($gc['left'] > 0): ?> · <span class="text-warning-emphasis"><?= h(rpay_money($gc['left'])) ?> to come</span><?php endif; ?></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if ($grpPick !== ''): ?>
    <a class="small text-decoration-none d-inline-block mt-2" href="<?= h($grpLink(null)) ?>"><i class="bi bi-x-lg"></i> Show every group</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<style>
.rp-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:12px; }
.rp-card{ display:block; border:1px solid #e3e6ea; border-radius:12px; padding:12px 14px; color:inherit; text-decoration:none; transition:box-shadow .15s; }
.rp-card:hover{ box-shadow:0 4px 14px rgba(0,0,0,.08); color:inherit; }
.rp-card.rp-on{ box-shadow:0 0 0 2px var(--primary) inset; }
.rp-total-link{ transition:box-shadow .15s; }
.rp-total-link:hover{ box-shadow:0 4px 14px rgba(0,0,0,.08); }
.rp-total-on{ box-shadow:0 0 0 2px var(--primary) inset; }
.rp-bell{ display:inline-flex; align-items:center; gap:3px; padding:1px 8px; border-radius:999px; background:#dc3545; color:#fff; font-size:.78rem; font-weight:700; line-height:1.4; }
.rp-card.rp-none{ border-style:dashed; background:#fafbfc; }
.rpay-fixed{ background:#e8eefc; color:#1e3a8a; font-weight:600; }
.rpay-dynamic{ background:#fdf1dc; color:#8a5300; font-weight:600; }
.rh-c0{ background:#eef4ff; border-color:#cfdcf7; } .rh-c1{ background:#eefaf3; border-color:#c8ebd6; }
.rh-c2{ background:#fff6e8; border-color:#f3dcb4; } .rh-c3{ background:#f6efff; border-color:#ddcdf5; }
.rh-c4{ background:#ffeff2; border-color:#f4cdd5; } .rh-c5{ background:#eaf8fb; border-color:#c3e6ee; }
.rh-c6{ background:#f9f7e6; border-color:#e9e2b5; } .rh-c7{ background:#f1f3f6; border-color:#d6dbe2; }
.rh-c8{ background:#effaf8; border-color:#c6e9e2; } .rh-c9{ background:#fdf0e9; border-color:#f1d2c0; }
body.dark-mode .rh-c0{ background:#1d2a45; border-color:#2f4370; } body.dark-mode .rh-c1{ background:#183429; border-color:#265541; }
body.dark-mode .rh-c2{ background:#3a2c14; border-color:#5c4620; } body.dark-mode .rh-c3{ background:#2b2142; border-color:#45356a; }
body.dark-mode .rh-c4{ background:#3a1f27; border-color:#5e3340; } body.dark-mode .rh-c5{ background:#16323a; border-color:#24505c; }
body.dark-mode .rh-c6{ background:#33301a; border-color:#524d2a; } body.dark-mode .rh-c7{ background:#232a35; border-color:#38424f; }
body.dark-mode .rh-c8{ background:#163430; border-color:#24544d; } body.dark-mode .rh-c9{ background:#3a2419; border-color:#5d3a29; }
body.dark-mode .rp-card.rp-none{ background:#1e293b; border-color:#334155; }
body.dark-mode .rpay-fixed{ background:#1e3a8a; color:#dbe4ff; }
body.dark-mode .rpay-dynamic{ background:#5c3a00; color:#ffe2b0; }
.rp-zone{ border:1px solid #e3e6ea; border-radius:12px; padding:10px 12px; min-height:84px; }
.rp-zone.rp-over{ outline:2px dashed var(--primary); outline-offset:2px; }
.rp-zone .rp-heads{ display:flex; flex-wrap:wrap; gap:6px; min-height:30px; }
.rp-head{ display:inline-block; padding:3px 10px; border-radius:999px; background:#fff; border:1px solid #cfd5dc; font-size:.85rem; cursor:grab; user-select:none; }
.rp-head.rp-picked{ box-shadow:0 0 0 2px var(--primary); }
.rp-head.rp-moved{ border-color:var(--primary); font-weight:600; }
body.dark-mode .rp-head{ background:#0f172a; border-color:#475569; }
</style>

<?php
  // The ⓘ box: every group with its income heads; drag a head (or tap it, then tap a group) to move it.
  $infoGroups = rpay_all_groups($conn);
  $infoHeads = rpay_live_heads($conn, $data['heads'] ?? []);
  $inGroup = [];
  foreach ($infoHeads as $hd) {
      $inGroup[(int)($headGroup[(int)$hd['id']]['id'] ?? 0)][] = $hd;
  }
?>
<div class="modal fade" id="rpGroupInfo" tabindex="-1" aria-labelledby="rpGroupInfoTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="post" action="<?= h($selfUrl) ?>" id="rpGroupForm">
      <div class="modal-header">
        <h5 class="modal-title" id="rpGroupInfoTitle">What comes in each group</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Each payment counts in the group of its income head. Drag an income head to another group to change it, or tap it and then tap the group. A new income head goes to the group its name matches, or to Other. Press <b>Save</b> when done.</p>
        <?php if (!empty($inGroup[0])): ?>
          <div class="alert alert-warning py-2 small"><?= count($inGroup[0]) ?> income head<?= count($inGroup[0]) === 1 ? ' is' : 's are' ?> not in a group yet.</div>
        <?php endif; ?>
        <div class="rp-grid" id="rpZones">
          <?php foreach ($infoGroups + [0 => ['name' => 'Not in a group', 'kind' => '']] as $gid => $g): ?>
            <div class="rp-zone<?= $gid ? ' rh-c' . ($gid % 10) : ' rp-none' ?>" data-group="<?= (int)$gid ?>">
              <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <span class="fw-semibold"><?= h($g['name']) ?></span>
                <?= rpay_kind_tag($g['kind']) ?>
              </div>
              <div class="rp-heads">
                <?php foreach ($inGroup[$gid] ?? [] as $hd): ?>
                  <span class="rp-head" draggable="true" data-head="<?= (int)$hd['id'] ?>" data-start="<?= (int)$gid ?>"><?= h($hd['name']) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="rp-zone rp-newbox">
            <div class="fw-semibold mb-2"><i class="bi bi-plus-lg"></i> New group</div>
            <input type="text" class="form-control form-control-sm mb-2" id="rpNewName" maxlength="80" placeholder="Name, e.g. Shop">
            <div class="d-flex gap-3 small mb-2">
              <label><input type="radio" name="rp_new_kind" value="fixed" checked> Fixed</label>
              <label><input type="radio" name="rp_new_kind" value="dynamic"> Dynamic</label>
            </div>
            <button type="button" class="btn btn-sm btn-light border" id="rpNewAdd">Add group</button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_groups">
        <input type="hidden" name="map" value="">
        <input type="hidden" name="new_groups" value="">
        <span class="small text-muted me-auto" id="rpGroupChanges"></span>
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
        <button class="btn text-white" style="background:var(--primary)" id="rpGroupSave" disabled>Save</button>
      </div>
    </form>
  </div>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Title</th><th>Building</th><th>Income head</th><th>Shop / unit</th><th>Due</th><th class="text-end">Amount</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$dues): ?>
          <tr><td colspan="8" class="text-muted text-center py-5">
            No payments for <?= h(rpay_month_label($month)) ?><?= $status !== '' || !empty($filters['building']) || !empty($filters['head']) || !empty($filters['unit']) || $grpPick !== '' ? ' with these filters' : '' ?>.
            A payment appears here on its entry's day of the month.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($dues as $d): ?>
          <?php $paid = ($d['status'] ?? '') === 'paid'; $partly = !$paid && (float)($d['received'] ?? 0) > 0; ?>
          <tr class="rpay-row" data-due="<?= (int)$d['id'] ?>" title="Double-click for details">
            <td class="fw-semibold"><?= h($d['title'] ?? '') ?></td>
            <td><?= h($d['building']['name'] ?? '') ?></td>
            <td><?= h($d['head']['name'] ?? '') ?></td>
            <td>
              <?php if (!empty($d['unit'])): ?>
                <?php $uRoom = rpay_room_of_due($conn, $d); ?>
                <?php if ($uRoom): ?><a class="text-decoration-none" title="Open <?= h($uRoom['name']) ?>" href="<?= h($rpayBase . '/room.php?' . http_build_query(['id' => $uRoom['id'], 'grp' => (int)$uRoom['group_id']])) ?>"><?= h($d['unit']) ?></a><?php else: ?><?= h($d['unit']) ?><?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="num"><?= h(rpay_date($d['due_date'] ?? '')) ?></td>
            <td class="num text-end fw-semibold"><?= h(rpay_money($d['amount'] ?? 0)) ?></td>
            <td>
              <span class="badge text-bg-<?= $paid ? 'success' : ($partly ? 'info' : 'warning') ?>"><?= $paid ? 'Paid' : ($partly ? 'Partly paid' : 'Pending') ?></span>
              <?php if ($partly): ?>
                <div class="small"><span class="text-success"><?= h(rpay_money($d['received'])) ?> in</span> · <span class="text-warning-emphasis fw-semibold"><?= h(rpay_money($d['left'] ?? 0)) ?> left</span></div>
              <?php endif; ?>
              <?php if (($paid || $partly) && !empty($d['paid_on'])): ?>
                <div class="small text-muted"><?= h(rpay_date($d['paid_on'])) ?><?= !empty($d['paid_by']) ? ' · ' . h($d['paid_by']) : '' ?></div>
              <?php endif; ?>
              <?php if (($paid || $partly) && !empty($d['account']['name'])): ?>
                <div class="small"><i class="bi bi-wallet2"></i> <?= h($d['account']['name']) ?></div>
              <?php endif; ?>
              <?php if (!empty($d['attachment'])): ?>
                <a class="small text-decoration-none" target="_blank" rel="noopener" href="<?= h(rpay_file_url($rpayBase, 'due', (int)$d['id'])) ?>"><i class="bi bi-paperclip"></i> Attachment</a>
              <?php elseif (!empty($d['receipt_files'])): ?>
                <a class="small text-decoration-none" href="<?= h($payUrl . (int)$d['id']) ?>"><i class="bi bi-paperclip"></i> Attachments</a>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <?php if ($paid || $partly): ?>
                <a href="<?= h($payUrl . (int)$d['id']) ?>" class="btn btn-sm <?= $paid ? 'btn-light border' : 'btn-success' ?>"><?= $paid ? 'Receipts' : '<i class="bi bi-plus-lg"></i> Add payment' ?></a>
                <form method="post" action="<?= h($selfUrl) ?>" class="d-inline" onsubmit="return confirm('Set this back to pending? Every receipt on it is deleted, with its file, and the money comes out of its account.');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="due" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-sm btn-light border">Set pending</button>
                </form>
              <?php else: ?>
                <a href="<?= h($payUrl . (int)$d['id']) ?>" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Mark paid</a>
              <?php endif; ?>
              <a href="<?= h($editUrl . (int)$d['id']) ?>" class="btn btn-sm btn-light border" title="Edit date, amount or attachment, or delete"><i class="bi bi-pencil"></i> Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($dues): ?><p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle"></i> Double-click a payment to open it.</p><?php endif; ?>

<style>.rpay-row{ cursor:pointer; user-select:none; }</style>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
<script>
(function () {
  var url = <?= json_encode($rpayBase . '/due_detail.php?' . ($filters ? http_build_query($filters) . '&' : '') . 'due=') ?>;
  document.querySelectorAll('tr.rpay-row').forEach(function (row) {
    row.addEventListener('dblclick', function (ev) {
      if (ev.target.closest('a, button, form, input, select')) { return; }
      window.location.href = url + encodeURIComponent(row.dataset.due);
    });
  });
})();

// The ⓘ box: drag an income head to another group (or tap it, then tap the group), then Save.
(function () {
  var form = document.getElementById('rpGroupForm');
  if (!form) { return; }
  var save = document.getElementById('rpGroupSave'), said = document.getElementById('rpGroupChanges');
  var dragged = null, picked = null, newCount = 0;
  function refresh() {
    var n = form.querySelectorAll('.rp-head.rp-moved').length, g = form.querySelectorAll('.rp-zone[data-new]').length, bits = [];
    if (g) { bits.push(g + ' new group' + (g === 1 ? '' : 's')); }
    if (n) { bits.push(n + ' income head' + (n === 1 ? '' : 's') + ' moved'); }
    save.disabled = !(n || g);
    said.textContent = bits.length ? bits.join(', ') + ', not saved yet' : '';
  }
  function moveTo(head, zone) {
    zone.querySelector('.rp-heads').appendChild(head);
    head.classList.toggle('rp-moved', head.dataset.start !== zone.dataset.group);
    refresh();
  }
  function wireZone(zone) {
    zone.addEventListener('dragover', function (ev) { if (dragged) { ev.preventDefault(); zone.classList.add('rp-over'); } });
    zone.addEventListener('dragleave', function () { zone.classList.remove('rp-over'); });
    zone.addEventListener('drop', function (ev) { ev.preventDefault(); zone.classList.remove('rp-over'); if (dragged) { moveTo(dragged, zone); } });
    zone.addEventListener('click', function () { if (picked) { picked.classList.remove('rp-picked'); moveTo(picked, zone); picked = null; } });
  }
  // A new group made right here: a card to drag heads into; it is made when Save is pressed.
  var newName = document.getElementById('rpNewName');
  document.getElementById('rpNewAdd').addEventListener('click', function () {
    var name = newName.value.trim().replace(/\s+/g, ' ');
    if (!name) { newName.focus(); return; }
    var taken = Array.prototype.some.call(form.querySelectorAll('.rp-zone .fw-semibold'), function (el) { return el.textContent.trim().toLowerCase() === name.toLowerCase(); });
    if (taken) { alert('There is already a group called "' + name + '".'); return; }
    var kind = form.querySelector('input[name=rp_new_kind]:checked').value;
    var zone = document.createElement('div');
    zone.className = 'rp-zone';
    zone.dataset.group = 'n' + (++newCount);
    zone.dataset.new = '1'; zone.dataset.name = name; zone.dataset.kind = kind;
    zone.innerHTML = '<div class="d-flex justify-content-between align-items-center gap-2 mb-2"><span class="fw-semibold"></span>'
      + '<span class="badge rounded-pill ' + (kind === 'dynamic' ? 'rpay-dynamic' : 'rpay-fixed') + '">' + (kind === 'dynamic' ? 'Dynamic' : 'Fixed') + '</span></div>'
      + '<div class="rp-heads"></div><div class="small text-muted mt-1">New · drag income heads here</div>';
    zone.querySelector('.fw-semibold').textContent = name;
    form.querySelector('.rp-newbox').before(zone);
    wireZone(zone);
    newName.value = '';
    refresh();
  });
  newName.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); document.getElementById('rpNewAdd').click(); } });
  form.querySelectorAll('.rp-head').forEach(function (head) {
    head.addEventListener('dragstart', function (ev) { dragged = head; ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', head.dataset.head); });
    head.addEventListener('dragend', function () { dragged = null; });
    head.addEventListener('click', function (ev) {
      ev.stopPropagation();
      if (picked) { picked.classList.remove('rp-picked'); }
      picked = picked === head ? null : head;
      if (picked) { picked.classList.add('rp-picked'); }
    });
  });
  form.querySelectorAll('.rp-zone[data-group]').forEach(wireZone);
  form.addEventListener('submit', function () {
    var map = {}, made = [];
    form.querySelectorAll('.rp-head.rp-moved').forEach(function (head) { map[head.dataset.head] = head.closest('.rp-zone').dataset.group; });
    form.querySelectorAll('.rp-zone[data-new]').forEach(function (z) { made.push({ key: z.dataset.group, name: z.dataset.name, kind: z.dataset.kind }); });
    form.querySelector('input[name=map]').value = JSON.stringify(map);
    form.querySelector('input[name=new_groups]').value = JSON.stringify(made);
  });
})();
</script>
