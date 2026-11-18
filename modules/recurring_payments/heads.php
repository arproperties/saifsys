<?php
/**
 * Recurring Payments — the module's own income heads: add, rename, delete.
 * What a payment is for ("Shop", "DEWA", "Washing Machine"): one list for every building,
 * typed here and used only here. Reem keeps the list and decides what is allowed
 * (a name once, and no deleting a head that has entries).
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
$selfUrl = $rpayBase . '/heads.php';

// Two tabs: Income heads (below) and Groups (DEWA, Partition ..., Fixed / Dynamic, period rules).
$tab = ($_GET['tab'] ?? '') === 'groups' ? 'groups' : 'heads';
if ($tab === 'groups'):
    $selfUrl = $rpayBase . '/heads.php?tab=groups';
$filters = [];
foreach (['month', 'building'] as $k) {
    if (is_string($_GET[$k] ?? null) && $_GET[$k] !== '') {
        $filters[$k] = $_GET[$k];
    }
}
$link = function (array $extra = []) use ($selfUrl, $filters) {
    $q = array_merge($filters, $extra);
    return $GLOBALS['rpayBase'] . '/heads.php?' . http_build_query(array_merge(['tab' => 'groups'], $q));
};

$tablesReady = true;
try {
    $conn->query('SELECT 1 FROM rpay_head_group_members LIMIT 1');
} catch (PDOException $e) {
    $tablesReady = false;
}

// Make, change or delete a group.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesReady) {
    csrf_verify();
    $back = is_string($_POST['back'] ?? null) && strpos($_POST['back'], $selfUrl) === 0 ? $_POST['back'] : $selfUrl;
    $action = (string)($_POST['action'] ?? '');
    $gid = (int)($_POST['group'] ?? 0);
    if ($action === 'delete' && $gid > 0) {
        $conn->prepare('DELETE FROM rpay_head_groups WHERE id = ?')->execute([$gid]);
        binv_flash('Group deleted. Its heads are now in no group.');
        header('Location: ' . $back);
        exit;
    }
    if ($action === 'save') {
        $name = trim(preg_replace('/\s+/u', ' ', is_string($_POST['name'] ?? null) ? $_POST['name'] : ''));
        $heads = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['heads'] ?? [])))));
        $kind = ($_POST['kind'] ?? '') === 'dynamic' ? 'dynamic' : 'fixed';
        $rule = isset(RPAY_PERIOD_RULES[$_POST['period_rule'] ?? '']) ? (string)$_POST['period_rule'] : 'none';
        if ($name === '' || mb_strlen($name) > 80) {
            binv_flash('Please type a name for the group (up to 80 letters).', 'danger');
            header('Location: ' . $link(['edit' => $gid ?: 'new']));
            exit;
        }
        $same = $conn->prepare('SELECT id FROM rpay_head_groups WHERE name = ? AND id <> ?');
        $same->execute([$name, $gid]);
        if ($same->fetchColumn()) {
            binv_flash('There is already a group called "' . $name . '".', 'danger');
            header('Location: ' . $link(['edit' => $gid ?: 'new']));
            exit;
        }
        $conn->beginTransaction();
        if ($gid > 0) {
            $conn->prepare('UPDATE rpay_head_groups SET name = ?, kind = ?, period_rule = ? WHERE id = ?')->execute([$name, $kind, $rule, $gid]);
        } else {
            $uid = (int)($_SESSION['user']['id'] ?? 0);
            $conn->prepare('INSERT INTO rpay_head_groups (name, kind, period_rule, created_by) VALUES (?, ?, ?, ?)')->execute([$name, $kind, $rule, $uid ?: null]);
            $gid = (int)$conn->lastInsertId();
        }
        // The ticked heads, and only those, are in this group; a head ticked here leaves its old group.
        $conn->prepare('DELETE FROM rpay_head_group_members WHERE group_id = ?')->execute([$gid]);
        $put = $conn->prepare('INSERT INTO rpay_head_group_members (head_id, group_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)');
        foreach ($heads as $hid) {
            $put->execute([$hid, $gid]);
        }
        $conn->commit();
        binv_flash('"' . $name . '" saved with ' . count($heads) . ' ' . (count($heads) === 1 ? 'head' : 'heads') . '.');
        header('Location: ' . $link(['group' => $gid]));
        exit;
    }
    header('Location: ' . $back);
    exit;
}

try {
    $data = rpay_reem($code, 'GET', $filters ? '?' . http_build_query($filters) : '');
} catch (BinvError $e) {
    rpay_stop($e);
}
$month = (string)($data['month'] ?? date('Y-m'));
$buildings = $data['buildings'] ?? [];
$heads = [];
foreach (rpay_live_heads($conn, $data['heads'] ?? []) as $hd) { // heads deleted in saifsys are left out
    $heads[(int)$hd['id']] = (string)$hd['name'];
}
asort($heads, SORT_NATURAL | SORT_FLAG_CASE);

$groupNames = [];   // group id => name
$groupKinds = [];   // group id => 'fixed' | 'dynamic'
$groupRules = [];   // group id => period rule (RPAY_PERIOD_RULES)
$memberOf = [];     // head id => group id
if ($tablesReady) {
    try {
        $rows = $conn->query('SELECT * FROM rpay_head_groups ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { // the kind column is not there yet: every group counts as fixed
        $rows = $conn->query("SELECT id, name, 'fixed' AS kind FROM rpay_head_groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rows as $r) {
        $groupNames[(int)$r['id']] = (string)$r['name'];
        $groupKinds[(int)$r['id']] = $r['kind'] === 'dynamic' ? 'dynamic' : 'fixed';
        $groupRules[(int)$r['id']] = (string)($r['period_rule'] ?? 'none');
    }
    foreach ($conn->query('SELECT head_id, group_id FROM rpay_head_group_members') as $r) {
        $memberOf[(int)$r['head_id']] = (int)$r['group_id'];
    }
}

// The month's payments, added up per group. Group 0: heads in no group.
$cards = [];
foreach ($groupNames as $gid => $name) {
    $cards[$gid] = ['name' => $name, 'kind' => $groupKinds[$gid] ?? 'fixed', 'heads' => [], 'lines' => [], 'due' => 0.0, 'received' => 0.0, 'left' => 0.0];
}
foreach ($heads as $hid => $hname) {
    $gid = isset($memberOf[$hid], $cards[$memberOf[$hid]]) ? $memberOf[$hid] : 0;
    if ($gid === 0 && !isset($cards[0])) {
        $cards[0] = ['name' => 'Not in a group', 'heads' => [], 'lines' => [], 'due' => 0.0, 'received' => 0.0, 'left' => 0.0];
    }
    $cards[$gid]['heads'][$hid] = $hname;
}
foreach ($data['dues'] ?? [] as $d) {
    $hid = (int)($d['head']['id'] ?? 0);
    $gid = isset($memberOf[$hid], $cards[$memberOf[$hid]]) ? $memberOf[$hid] : 0;
    if (!isset($cards[$gid])) {
        $cards[$gid] = ['name' => 'Not in a group', 'heads' => [], 'lines' => [], 'due' => 0.0, 'received' => 0.0, 'left' => 0.0];
    }
    $c = &$cards[$gid];
    $c['lines'][] = $d;
    $c['due'] += (float)($d['amount'] ?? 0);
    $c['received'] += (float)($d['received'] ?? 0);
    $c['left'] += (float)($d['left'] ?? 0);
    unset($c);
}
// Biggest first; "Not in a group" last.
uksort($cards, function ($a, $b) use ($cards) {
    return ((int)($a === 0) <=> (int)($b === 0)) ?: ($cards[$b]['due'] <=> $cards[$a]['due'])
        ?: strcasecmp($cards[$a]['name'], $cards[$b]['name']);
});
if (isset($cards[0]) && !$cards[0]['heads'] && !$cards[0]['lines']) {
    unset($cards[0]);
}
// The month by kind: fixed (same every month) and dynamic (changes every month).
$byKind = ['fixed' => ['due' => 0.0, 'received' => 0.0, 'left' => 0.0], 'dynamic' => ['due' => 0.0, 'received' => 0.0, 'left' => 0.0]];
foreach ($cards as $gid => $c) {
    if ($gid !== 0) {
        foreach (['due', 'received', 'left'] as $k) {
            $byKind[$c['kind']][$k] += $c[$k];
        }
    }
}
/** The Fixed / Dynamic tag. */
function rh_kind_tag(string $kind): string
{
    return $kind === 'dynamic'
        ? '<span class="badge rounded-pill rh-dynamic" title="The amount changes every month"><i class="bi bi-graph-up"></i> Dynamic</span>'
        : '<span class="badge rounded-pill rh-fixed" title="The same amount every month"><i class="bi bi-pin-angle"></i> Fixed</span>';
}

$openId = isset($_GET['group']) && $_GET['group'] !== '' ? (int)$_GET['group'] : null;
$open = $openId !== null ? ($cards[$openId] ?? null) : null;
if ($open) {
    usort($open['lines'], function ($a, $b) {
        return strcmp((string)($a['due_date'] ?? ''), (string)($b['due_date'] ?? '')) ?: strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
}
$edit = $_GET['edit'] ?? null;
$editId = $edit === 'new' ? 0 : (int)$edit;
$editing = $tablesReady && ($edit === 'new' || isset($groupNames[$editId]));

$pageTitle = 'Groups';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'heads' ? ' active' : '' ?>" href="<?= h($rpayBase) ?>/heads.php"><i class="bi bi-tags"></i> Income heads</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'groups' ? ' active' : '' ?>" href="<?= h($rpayBase) ?>/heads.php?tab=groups"><i class="bi bi-grid-3x3-gap"></i> Groups</a></li>
</ul>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="page-header-label mb-1">Groups</div>
    <p class="text-muted mb-0">Your own groups of income heads, like DEWA, Partition or Shop, for <?= h(rpay_month_label($month)) ?>. Click a card to see its payments.</p>
  </div>
  <?php if ($tablesReady): ?>
    <a href="<?= h($link(['edit' => 'new'])) ?>" class="btn text-white" style="background:var(--primary)"><i class="bi bi-plus-lg"></i> New group</a>
  <?php endif; ?>
</div>

<?php if (!$tablesReady): ?>
  <div class="alert alert-warning">Groups are not set up on this server yet: run <code>migrations/rpay_head_groups.sql</code>.</div>
<?php endif; ?>

<?php if ($editing): $editHeads = array_keys(array_filter($memberOf, function ($g) use ($editId) { return $g === $editId; })); ?>
<div class="card card-round mb-3" style="max-width:720px">
  <div class="card-body">
    <div class="fw-semibold fs-5 mb-3"><?= $editId ? 'Change "' . h($groupNames[$editId]) . '"' : 'New group' ?></div>
    <form method="post" action="<?= h($selfUrl) ?>">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="group" value="<?= (int)$editId ?>">
      <div class="mb-3" style="max-width:340px">
        <label class="form-label small text-muted mb-1">Group name</label>
        <input type="text" name="name" class="form-control" maxlength="80" required value="<?= h($editId ? $groupNames[$editId] : '') ?>" placeholder="e.g. DEWA, Partition, Shop" autofocus>
      </div>
      <?php $k = $editId ? ($groupKinds[$editId] ?? 'fixed') : 'fixed'; ?>
      <div class="mb-3">
        <label class="form-label small text-muted mb-1 d-block">Payment</label>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="kind" id="rhFixed" value="fixed"<?= $k === 'fixed' ? ' checked' : '' ?>>
          <label class="form-check-label" for="rhFixed">Fixed <span class="text-muted small">(same every month, like rent)</span></label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="kind" id="rhDynamic" value="dynamic"<?= $k === 'dynamic' ? ' checked' : '' ?>>
          <label class="form-check-label" for="rhDynamic">Dynamic <span class="text-muted small">(changes every month, like DEWA)</span></label>
        </div>
      </div>
      <div class="mb-3" style="max-width:480px">
        <label class="form-label small text-muted mb-1">What each payment is for</label>
        <?php $pr = $editId ? ($groupRules[$editId] ?? 'none') : 'none'; ?>
        <select name="period_rule" class="form-select">
          <?php foreach (RPAY_PERIOD_RULES as $rk => $rl): ?>
            <option value="<?= h($rk) ?>"<?= $pr === $rk ? ' selected' : '' ?>><?= h($rl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex justify-content-between align-items-end mb-1">
        <label class="form-label small text-muted mb-0">Heads in this group</label>
        <input type="search" class="form-control form-control-sm" style="max-width:200px" placeholder="Find a head" id="rhFind">
      </div>
      <div class="rh-heads border rounded p-2 mb-3">
        <?php foreach ($heads as $hid => $hname): $other = $memberOf[$hid] ?? 0; $mine = in_array($hid, $editHeads, true); ?>
          <label class="d-flex align-items-center gap-2 py-1 rh-head">
            <input type="checkbox" class="form-check-input m-0" name="heads[]" value="<?= (int)$hid ?>"<?= $mine ? ' checked' : '' ?>>
            <span><?= h($hname) ?></span>
            <?php if (!$mine && $other && isset($groupNames[$other])): ?><span class="small text-muted">(now in <?= h($groupNames[$other]) ?>; ticking moves it here)</span><?php endif; ?>
          </label>
        <?php endforeach; ?>
        <?php if (!$heads): ?><div class="text-muted small">No income heads yet.</div><?php endif; ?>
      </div>
      <div class="d-flex gap-2">
        <button class="btn text-white" style="background:var(--primary)">Save</button>
        <a href="<?= h($link($editId ? ['group' => $editId] : [])) ?>" class="btn btn-light border">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3">
  <input type="hidden" name="tab" value="groups">
  <div>
    <label class="form-label small text-muted mb-1">Month</label>
    <input type="month" name="month" class="form-control" value="<?= h($month) ?>">
  </div>
  <div style="min-width:220px">
    <label class="form-label small text-muted mb-1">Building</label>
    <select name="building" class="form-select">
      <option value="">All buildings</option>
      <?php foreach ($buildings as $b): ?>
        <option value="<?= (int)$b['id'] ?>"<?= (string)$b['id'] === ($filters['building'] ?? '') ? ' selected' : '' ?>><?= h($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-light border">Show</button>
</form>

<?php if (!$groupNames && $tablesReady): ?>
  <p class="text-muted">No groups yet. Click <b>New group</b>, name it (for example DEWA) and tick its heads.</p>
<?php endif; ?>

<?php if ($cards): ?>
<div class="rh-grid mb-4">
  <?php foreach ($cards as $gid => $c): $n = count($c['lines']); $pct = $c['due'] > 0 ? min(100, round($c['received'] / $c['due'] * 100)) : 0; ?>
    <a class="rh-card<?= $gid !== 0 ? ' rh-c' . ($gid % 10) : '' ?><?= $gid === $openId ? ' rh-open' : '' ?><?= $gid === 0 ? ' rh-none' : '' ?>" href="<?= h($gid === $openId ? $link() : $link(['group' => $gid])) ?>">
      <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
        <div class="fw-semibold fs-6"><?= h($c['name']) ?></div>
        <span class="badge rounded-pill text-bg-light border text-nowrap"><?= $n ?> <?= $n === 1 ? 'payment' : 'payments' ?></span>
      </div>
      <div class="d-flex align-items-center gap-2 small text-muted mb-2">
        <?php if ($gid !== 0): ?><?= rh_kind_tag($c['kind']) ?><?php endif; ?>
        <span><?= count($c['heads']) ?> <?= count($c['heads']) === 1 ? 'head' : 'heads' ?></span>
      </div>
      <div class="small text-muted">Due</div>
      <div class="fs-5 fw-bold mb-2"><?= h(rpay_money($c['due'])) ?></div>
      <div class="progress mb-2" style="height:6px"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
      <div class="d-flex justify-content-between small gap-2">
        <span class="text-success">Received <?= h(rpay_money($c['received'])) ?></span>
        <span class="<?= $c['left'] > 0 ? 'text-warning-emphasis' : 'text-muted' ?>">To come <?= h(rpay_money($c['left'])) ?></span>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($open): ?>
<div class="card card-round" id="rhList">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
      <div class="fw-semibold fs-5"><?= h($open['name']) ?> <?= $openId > 0 ? rh_kind_tag($open['kind']) : '' ?> <span class="text-muted fs-6">· <?= h(rpay_month_label($month)) ?></span></div>
      <div class="d-flex gap-2">
        <?php if ($openId > 0): ?>
          <a href="<?= h($link(['edit' => $openId])) ?>" class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i> Change</a>
          <form method="post" action="<?= h($selfUrl) ?>" onsubmit="return confirm('Delete the group &quot;<?= h($open['name']) ?>&quot;? The heads and payments stay; they are just in no group.');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="group" value="<?= (int)$openId ?>">
            <input type="hidden" name="back" value="<?= h($link()) ?>">
            <button class="btn btn-sm btn-light border text-danger"><i class="bi bi-trash"></i> Delete</button>
          </form>
        <?php endif; ?>
        <a href="<?= h($link()) ?>" class="btn btn-sm btn-light border">Close</a>
      </div>
    </div>
    <div class="small text-muted mb-3"><?= $open['heads'] ? h(implode(' · ', $open['heads'])) : 'No heads yet. Click Change to tick them.' ?></div>
    <?php if (!$open['lines']): ?>
      <p class="text-muted mb-0">No payments this month.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted"><th>Title</th><th>Head</th><th>Building</th><th>Unit</th><th>Due date</th><th class="text-end">Amount</th><th class="text-end">Received</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($open['lines'] as $d): ?>
              <tr>
                <td><a class="fw-semibold text-decoration-none" href="<?= h($rpayBase . '/due_detail.php?due=' . (int)$d['id']) ?>"><?= h($d['title'] ?? '') ?></a></td>
                <td><?= h($d['head']['name'] ?? '') ?></td>
                <td><?= h($d['building']['name'] ?? '') ?></td>
                <td><?= h($d['unit'] ?? '') ?></td>
                <td class="num"><?= h(rpay_date($d['due_date'] ?? '')) ?></td>
                <td class="num text-end"><?= h(rpay_money($d['amount'] ?? 0)) ?></td>
                <td class="num text-end"><?= h(rpay_money($d['received'] ?? 0)) ?></td>
                <td><?= rpay_badge($d) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="fw-semibold"><td colspan="5">Total</td><td class="num text-end"><?= h(rpay_money($open['due'])) ?></td><td class="num text-end"><?= h(rpay_money($open['received'])) ?></td><td></td></tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<style>
.rh-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }
.rh-card{ display:block; background:#fff; border:1px solid #e3e6ea; border-radius:12px; padding:14px 16px; color:inherit; text-decoration:none; transition:box-shadow .15s, border-color .15s; }
.rh-card:hover{ box-shadow:0 4px 14px rgba(0,0,0,.08); border-color:#c9ced4; color:inherit; }
.rh-card.rh-open{ border-color:var(--primary); box-shadow:0 0 0 2px var(--primary) inset; }
.rh-card.rh-none{ border-style:dashed; background:#fafbfc; }
/* Each group its own soft colour, by its id (the same card keeps the same colour). */
.rh-c0{ background:#eef4ff; border-color:#cfdcf7; }  .rh-c1{ background:#eefaf3; border-color:#c8ebd6; }
.rh-c2{ background:#fff6e8; border-color:#f3dcb4; }  .rh-c3{ background:#f6efff; border-color:#ddcdf5; }
.rh-c4{ background:#ffeff2; border-color:#f4cdd5; }  .rh-c5{ background:#eaf8fb; border-color:#c3e6ee; }
.rh-c6{ background:#f9f7e6; border-color:#e9e2b5; }  .rh-c7{ background:#f1f3f6; border-color:#d6dbe2; }
.rh-c8{ background:#effaf8; border-color:#c6e9e2; }  .rh-c9{ background:#fdf0e9; border-color:#f1d2c0; }
body.dark-mode .rh-c0{ background:#1d2a45; border-color:#2f4370; }  body.dark-mode .rh-c1{ background:#183429; border-color:#265541; }
body.dark-mode .rh-c2{ background:#3a2c14; border-color:#5c4620; }  body.dark-mode .rh-c3{ background:#2b2142; border-color:#45356a; }
body.dark-mode .rh-c4{ background:#3a1f27; border-color:#5e3340; }  body.dark-mode .rh-c5{ background:#16323a; border-color:#24505c; }
body.dark-mode .rh-c6{ background:#33301a; border-color:#524d2a; }  body.dark-mode .rh-c7{ background:#232a35; border-color:#38424f; }
body.dark-mode .rh-c8{ background:#163430; border-color:#24544d; }  body.dark-mode .rh-c9{ background:#3a2419; border-color:#5d3a29; }
.rh-heads{ max-height:320px; overflow:auto; }
.rh-fixed{ background:#e8eefc; color:#1e3a8a; font-weight:600; }
.rh-dynamic{ background:#fdf1dc; color:#8a5300; font-weight:600; }
.rh-kind{ background:#fff; min-width:220px; }
body.dark-mode .rh-card.rh-none, body.dark-mode .rh-kind{ background:#1e293b; border-color:#334155; }
body.dark-mode .rh-fixed{ background:#1e3a8a; color:#dbe4ff; }
body.dark-mode .rh-dynamic{ background:#5c3a00; color:#ffe2b0; }
</style>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
<script>
(function () {
  var find = document.getElementById('rhFind');
  if (find) {
    find.addEventListener('input', function () {
      var q = find.value.toLowerCase();
      document.querySelectorAll('.rh-head').forEach(function (l) { l.style.display = l.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : ''; });
    });
  }
  var list = document.getElementById('rhList');
  if (list) { list.scrollIntoView({behavior: 'smooth', block: 'start'}); }
})();
</script>

<?php
    exit;
endif; // the Groups tab


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['head'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    try {
        if ($action === 'add') {
            $head = rpay_reem($code, 'POST', '/heads', ['name' => $name]);
            binv_flash('"' . $head['name'] . '" added.');
        } elseif ($action === 'rename') {
            $head = rpay_reem($code, 'PUT', '/heads/' . $id, ['name' => $name]);
            binv_flash('Renamed to "' . $head['name'] . '".');
        } elseif ($action === 'delete' || $action === 'restore') {
            // Deleted and restored in saifsys only, with a reason; the head stays in Reem.
            $head = null;
            foreach (rpay_reem($code, 'GET', '/heads')['heads'] ?? [] as $hd) {
                if ((int)$hd['id'] === $id) {
                    $head = $hd;
                }
            }
            if (!$head) {
                throw new BinvError('That income head could not be found.', 404);
            }
            $reason = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '';
            $error = rpay_log_head($conn, $id, (string)$head['name'], $action === 'delete' ? 'deleted' : 'restored', $reason, (int)current_user_id());
            if ($error !== null) {
                throw new BinvError($error, 400);
            }
            binv_flash('"' . $head['name'] . '" ' . ($action === 'delete' ? 'deleted. You can restore it from "Deleted income heads".' : 'restored.'));
        }
    } catch (BinvError $e) {
        binv_flash($e->getMessage(), 'danger');
    } catch (PDOException $e) {
        error_log('recurrent income: head log not saved: ' . $e->getMessage());
        binv_flash('That could not be saved.', 'danger');
    }
    header('Location: ' . $selfUrl);
    exit;
}

try {
    $allHeads = rpay_reem($code, 'GET', '/heads')['heads'] ?? [];
} catch (BinvError $e) {
    rpay_stop($e);
}
$deleted = rpay_deleted_heads($conn);
$heads = rpay_live_heads($conn, $allHeads);
$deletedHeads = array_values(array_filter($allHeads, function ($hd) use ($deleted) { return isset($deleted[(int)$hd['id']]); }));
$history = rpay_head_history($conn);

$pageTitle = 'Income Heads';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'heads' ? ' active' : '' ?>" href="<?= h($rpayBase) ?>/heads.php"><i class="bi bi-tags"></i> Income heads</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'groups' ? ' active' : '' ?>" href="<?= h($rpayBase) ?>/heads.php?tab=groups"><i class="bi bi-grid-3x3-gap"></i> Groups</a></li>
</ul>
<div class="mb-3">
  <div class="page-header-label mb-1">Income Heads</div>
  <p class="text-muted mb-0">What a payment is for, like Shop, DEWA or Washing Machine. One list for every building; pick the head on each entry.</p>

</div>

<div class="card card-round mb-3" style="max-width:680px">
  <div class="card-body">
    <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" class="form-control" maxlength="<?= RPAY_NAME_MAX ?>" placeholder="Income head name" required <?= $heads ? '' : 'autofocus' ?>>
      <button class="btn text-white text-nowrap" style="background:var(--primary)"><i class="bi bi-plus-lg"></i> Add income head</button>
    </form>
  </div>
</div>

<div class="card card-round" style="max-width:680px">
  <div class="table-responsive">
    <table class="table rpay-table align-middle">
      <thead>
        <tr><th>Income head</th><th>Entries</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$heads): ?>
          <tr><td colspan="3" class="text-muted text-center py-5">No income heads yet. Add the first one above.</td></tr>
        <?php endif; ?>
        <?php foreach ($heads as $head): ?>
          <tr>
            <td>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-flex gap-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="head" value="<?= (int)$head['id'] ?>">
                <input type="hidden" name="action" value="rename">
                <input type="text" name="name" class="form-control form-control-sm" maxlength="<?= RPAY_NAME_MAX ?>" value="<?= h($head['name']) ?>" required>
                <button class="btn btn-sm btn-light border text-nowrap">Save name</button>
              </form>
            </td>
            <td class="num text-nowrap">
              <?php if ((int)($head['entries'] ?? 0) > 0): ?>
                <a class="fw-semibold text-decoration-none" href="<?= h($rpayBase) ?>/entries.php?head=<?= (int)$head['id'] ?>" title="See the entries under this head"><?= (int)$head['entries'] ?></a>
                <a class="small text-decoration-none ms-2" href="<?= h($rpayBase) ?>/index.php?head=<?= (int)$head['id'] ?>" title="See this month's payments for this head">Payments</a>
              <?php else: ?>
                0
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php $n = (int)($head['entries'] ?? 0); ?>
              <form method="post" action="<?= h($selfUrl) ?>" class="d-inline rpay-reason-form"
                    data-ask="<?= h('Why delete "' . $head['name'] . '"?' . ($n ? ' Its ' . $n . ' ' . ($n === 1 ? 'entry keeps' : 'entries keep') . ' it; it is only hidden from the lists.' : '')) ?>">
                <?php csrf_field(); ?>
                <input type="hidden" name="head" value="<?= (int)$head['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="reason" value="">
                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($deletedHeads): ?>
<div class="card card-round mt-3" style="max-width:680px">
  <div class="card-body">
    <div class="fw-semibold mb-2">Deleted income heads</div>
    <ul class="list-unstyled mb-0">
      <?php foreach ($deletedHeads as $hd): $d = $deleted[(int)$hd['id']]; ?>
        <li class="py-2 border-bottom d-flex align-items-start gap-2">
          <div class="flex-grow-1">
            <div class="fw-semibold text-decoration-line-through text-muted"><?= h($hd['name']) ?></div>
            <div class="small">Reason: <?= h($d['reason']) ?></div>
            <div class="small text-muted"><?= h($d['by'] !== '' ? $d['by'] : 'Someone') ?> · <?= h(rpay_datetime($d['at'])) ?><?= (int)($hd['entries'] ?? 0) ? ' · still on ' . (int)$hd['entries'] . ' ' . ((int)$hd['entries'] === 1 ? 'entry' : 'entries') : '' ?></div>
          </div>
          <form method="post" action="<?= h($selfUrl) ?>" class="m-0 rpay-reason-form" data-ask="<?= h('Why restore "' . $hd['name'] . '"?') ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="head" value="<?= (int)$hd['id'] ?>">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="reason" value="">
            <button class="btn btn-sm btn-light border"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<?php if ($history): ?>
<details class="mt-3" style="max-width:680px">
  <summary class="small text-muted">History of deletes and restores</summary>
  <ul class="list-unstyled small mt-2 mb-0">
    <?php foreach ($history as $r): ?>
      <li class="py-1 border-bottom">
        <span class="badge text-bg-<?= $r['action'] === 'deleted' ? 'danger' : 'success' ?>"><?= $r['action'] === 'deleted' ? 'Deleted' : 'Restored' ?></span>
        <span class="fw-semibold"><?= h($r['head_name']) ?></span> — <?= h($r['reason']) ?>
        <span class="text-muted">· <?= h($r['fullname'] ?? 'Someone') ?> · <?= h(rpay_datetime($r['created_at'])) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>

<script>
// Delete and restore ask for the reason first; no reason, nothing is sent.
document.querySelectorAll('.rpay-reason-form').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    var reason = window.prompt(f.dataset.ask + '\n\nReason:');
    if (reason === null || reason.trim() === '') { ev.preventDefault(); if (reason !== null) { alert('Please give a reason.'); } return; }
    f.querySelector('input[name=reason]').value = reason.trim();
  });
});
</script>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
