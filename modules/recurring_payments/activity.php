<?php
/**
 * Recurrent Income — activity: who added, changed or deleted what, and when, as a timeline.
 * Reem keeps the list; this page only reads it. Nothing can be changed from here: every
 * button is a filter or the sort order.
 *
 *   activity.php                                  the last 30 days, everybody, newest first
 *   activity.php?period=7&type=deleted&by=Jessa&sort=old
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
$dubai = new DateTimeZone('Asia/Dubai');
$today = new DateTimeImmutable('now', $dubai);

// The buttons. A period is turned into two days for Reem; Reem decides the rest.
$periods = ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', 'month' => 'This month', 'last' => 'Last month', 'all' => 'All time'];
$types = ['added' => 'Added', 'received' => 'Money received', 'changed' => 'Changed', 'deleted' => 'Deleted'];
$period = isset($periods[$_GET['period'] ?? '']) ? (string)$_GET['period'] : '30';
$type = isset($types[$_GET['type'] ?? '']) ? (string)$_GET['type'] : '';
// One person is their Reem account number (two people can have the same name); "auto" is what Reem made itself.
$by = is_string($_GET['by'] ?? null) && ($_GET['by'] === 'auto' || ctype_digit($_GET['by'])) ? $_GET['by'] : '';
$sort = ($_GET['sort'] ?? '') === 'old' ? 'old' : '';
$page = max(1, (int)($_GET['page'] ?? 1));

[$from, $to] = match ($period) {
    'today' => [$today, $today],
    '7' => [$today->modify('-6 days'), $today],
    'month' => [$today->modify('first day of this month'), $today],
    'last' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
    'all' => [new DateTimeImmutable('2020-01-01', $dubai), $today],
    default => [$today->modify('-29 days'), $today],
};

try {
    $data = rpay_reem($code, 'GET', '/activity?' . http_build_query(array_filter(
        ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'type' => $type, 'by' => $by, 'sort' => $sort, 'page' => $page > 1 ? (string)$page : ''], static fn($v) => $v !== '')));
} catch (BinvError $e) {
    rpay_stop($e);
}
$rows = (array)($data['activity'] ?? []);
$counts = (array)($data['counts'] ?? []);
$people = (array)($data['people'] ?? []);
$byName = $by === 'auto' ? 'Reem (automatic)' : '';
foreach ($people as $p) {
    if ($by !== '' && (string)($p['id'] ?? '') === $by) {
        $byName = (string)($p['name'] ?? '');
    }
}

// This page's address with one button changed. Any button but the page ones goes back to the first page.
$link = static function (array $change) use ($rpayBase, $period, $type, $by, $sort): string {
    $q = array_filter(array_merge(['period' => $period, 'type' => $type, 'by' => $by, 'sort' => $sort], $change),
        static fn($v) => $v !== '' && $v !== null);
    return $rpayBase . '/activity.php' . ($q ? '?' . http_build_query($q) : '');
};

// What each line of the list is called, and its sign.
$actions = [
    'entry_added' => ['Entry added', 'bi-plus-circle'], 'payment_created' => ['Payment created', 'bi-calendar-plus'],
    'transfer_added' => ['Transfer made', 'bi-arrow-left-right'], 'file_added' => ['Attachment added', 'bi-paperclip'],
    'money_received' => ['Money received', 'bi-cash-coin'],
    'entry_changed' => ['Entry changed', 'bi-pencil'], 'entry_paused' => ['Entry paused', 'bi-pause-circle'],
    'entry_resumed' => ['Entry resumed', 'bi-play-circle'], 'payment_changed' => ['Payment changed', 'bi-pencil'],
    'marked_pending' => ['Set back to pending', 'bi-arrow-counterclockwise'],
    'entry_deleted' => ['Entry deleted', 'bi-trash'], 'payment_deleted' => ['Payment deleted', 'bi-trash'],
    'receipt_deleted' => ['Received money deleted', 'bi-trash'], 'transfer_deleted' => ['Transfer deleted', 'bi-trash'],
    'file_removed' => ['Attachment removed', 'bi-paperclip'],
];
$tone = ['added' => '#2563eb', 'received' => '#16a34a', 'changed' => '#d97706', 'deleted' => '#dc2626'];

$pageTitle = 'Activity';
$pageStyles = '
  .ra-card{ display:block; text-decoration:none; color:inherit; border:1px solid rgba(0,0,0,.08); border-radius:14px; padding:.9rem 1rem; background:#fff; border-left:5px solid var(--t); height:100%; }
  .ra-card:hover{ box-shadow:0 4px 14px rgba(0,0,0,.08); color:inherit; }
  .ra-card.on{ background:color-mix(in srgb, var(--t) 9%, #fff); border-color:var(--t); }
  .ra-card .n{ font-size:1.6rem; font-weight:700; line-height:1.1; font-variant-numeric:tabular-nums; }
  .ra-card .l{ font-size:.72rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#6b7280; }
  .ra-pill{ display:inline-block; padding:.28rem .8rem; border-radius:999px; border:1px solid #d5dbe3; background:#fff; color:#374151; font-size:.82rem; text-decoration:none; white-space:nowrap; }
  .ra-pill:hover{ background:#f3f4f6; color:#111827; }
  .ra-pill.on{ background:var(--primary); border-color:var(--primary); color:#fff; }
  .ra-lbl{ font-size:.7rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#8a9099; min-width:64px; padding-top:.35rem; }
  .ra-day{ font-size:.78rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#6b7280; margin:1.2rem 0 .5rem; }
  .ra-line{ position:relative; margin-left:14px; padding:0 0 1rem 26px; border-left:2px solid #e5e7eb; }
  .ra-line:last-child{ padding-bottom:.2rem; }
  .ra-dot{ position:absolute; left:-15px; top:0; width:28px; height:28px; border-radius:50%; background:var(--t); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.85rem; box-shadow:0 0 0 3px #fff; }
  .ra-what{ font-weight:600; }
  .ra-amt{ font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap; }
  body.dark-mode .ra-card, body.dark-mode .ra-pill{ background:rgba(30,41,59,.95); color:#e4e4e7; border-color:#334155; }
  body.dark-mode .ra-card{ border-left-color:var(--t); }
  body.dark-mode .ra-card.on{ background:color-mix(in srgb, var(--t) 22%, #1e293b); }
  body.dark-mode .ra-pill.on{ background:var(--primary); color:#fff; }
  body.dark-mode .ra-line{ border-left-color:#334155; }
  body.dark-mode .ra-dot{ box-shadow:0 0 0 3px #1e293b; }
';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div>
    <div class="page-header-label mb-1">Activity</div>
    <div class="text-muted small">
      <?= h($from->format('d M Y')) ?><?= $from->format('Y-m-d') === $to->format('Y-m-d') ? '' : ' to ' . h($to->format('d M Y')) ?>
      · <?= $byName !== '' ? h($byName) : 'everybody' ?> · to look at only, nothing can be changed here
    </div>
  </div>
</div>

<!-- The four numbers; each is a button that shows only its own lines -->
<div class="row g-3 mb-3">
  <?php foreach ($types as $key => $label): $c = (array)($counts[$key] ?? []); $on = $type === $key; ?>
    <div class="col-6 col-lg-3">
      <a class="ra-card <?= $on ? 'on' : '' ?>" style="--t:<?= $tone[$key] ?>" href="<?= h($link(['type' => $on ? '' : $key])) ?>" title="<?= $on ? 'Show everything again' : 'Show only these' ?>">
        <div class="l"><?= h($label) ?></div>
        <div class="n"><?= (int)($c['n'] ?? 0) ?></div>
        <div class="small text-muted"><?= $key === 'received' || $key === 'deleted' ? h(rpay_money($c['amount'] ?? 0)) : '&nbsp;' ?></div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="card card-round p-3 mb-3">
  <div class="d-flex gap-2 mb-2">
    <div class="ra-lbl">Period</div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($periods as $key => $label): ?>
        <a class="ra-pill <?= $period === (string)$key ? 'on' : '' ?>" href="<?= h($link(['period' => (string)$key])) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="d-flex gap-2 mb-2">
    <div class="ra-lbl">Show</div>
    <div class="d-flex flex-wrap gap-2">
      <a class="ra-pill <?= $type === '' ? 'on' : '' ?>" href="<?= h($link(['type' => ''])) ?>">Everything</a>
      <?php foreach ($types as $key => $label): ?>
        <a class="ra-pill <?= $type === $key ? 'on' : '' ?>" href="<?= h($link(['type' => $key])) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($people || !empty($data['automatic'])): ?>
  <div class="d-flex gap-2 mb-2">
    <div class="ra-lbl">By</div>
    <div class="d-flex flex-wrap gap-2">
      <a class="ra-pill <?= $by === '' ? 'on' : '' ?>" href="<?= h($link(['by' => ''])) ?>">Everybody</a>
      <?php foreach ($people as $p): $pid = (string)($p['id'] ?? ''); ?>
        <a class="ra-pill <?= $by === $pid ? 'on' : '' ?>" href="<?= h($link(['by' => $pid])) ?>"><?= h($p['name'] ?? '') ?></a>
      <?php endforeach; ?>
      <?php if (!empty($data['automatic'])): ?>
        <a class="ra-pill <?= $by === 'auto' ? 'on' : '' ?>" href="<?= h($link(['by' => 'auto'])) ?>" title="Payments Reem created by itself on their day">Reem (automatic)</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="d-flex gap-2">
    <div class="ra-lbl">Sort</div>
    <div class="d-flex flex-wrap gap-2">
      <a class="ra-pill <?= $sort === '' ? 'on' : '' ?>" href="<?= h($link(['sort' => ''])) ?>"><i class="bi bi-sort-down me-1"></i>Newest first</a>
      <a class="ra-pill <?= $sort === 'old' ? 'on' : '' ?>" href="<?= h($link(['sort' => 'old'])) ?>"><i class="bi bi-sort-up me-1"></i>Oldest first</a>
    </div>
  </div>
</div>

<div class="card card-round p-3 p-md-4">
  <?php if (!$rows): ?>
    <div class="text-muted text-center py-5">Nothing happened in these days<?= $type !== '' || $by !== '' ? ' for what is picked above' : '' ?>.</div>
  <?php endif; ?>
  <?php
  $day = '';
  foreach ($rows as $r):
      $when = (new DateTimeImmutable('@' . (int)($r['at'] ?? 0)))->setTimezone($dubai);
      $kind = (string)($r['type'] ?? 'changed');
      [$label, $icon] = $actions[$r['action'] ?? ''] ?? [ucfirst(str_replace('_', ' ', (string)($r['action'] ?? ''))), 'bi-dot'];
      $about = array_filter([$r['title'] ?? '', $r['building'] ?? '', $r['unit'] ?? '', !empty($r['month']) ? rpay_month_label((string)$r['month']) : '']);
      if ($when->format('Y-m-d') !== $day):
          $day = $when->format('Y-m-d'); ?>
        <div class="ra-day"><?= h($day === $today->format('Y-m-d') ? 'Today · ' . $when->format('d M Y') : $when->format('l · d M Y')) ?></div>
      <?php endif; ?>
    <div class="ra-line" style="--t:<?= $tone[$kind] ?? '#6b7280' ?>">
      <span class="ra-dot"><i class="bi <?= h($icon) ?>"></i></span>
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div>
          <span class="ra-what" style="color:<?= $tone[$kind] ?? '#6b7280' ?>"><?= h($label) ?></span>
          <?php if ($about): ?><span class="ms-1"><?= h(implode(' · ', $about)) ?></span><?php endif; ?>
          <?php if (!empty($r['detail'])): ?><div class="small text-muted"><?= h($r['detail']) ?></div><?php endif; ?>
          <div class="small text-muted">
            <i class="bi bi-person me-1"></i><?= h($r['by'] ?? 'Not recorded') ?> · <?= h($when->format('g:i A')) ?>
            <?php if (!empty($r['old'])): ?> · <span title="Taken from what was already kept when the activity list was started">from before the list was kept</span><?php endif; ?>
          </div>
        </div>
        <?php if (($r['amount'] ?? null) !== null): ?><div class="ra-amt"><?= h(rpay_money($r['amount'])) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($page > 1 || !empty($data['more'])): // a hundred lines to a page ?>
    <div class="d-flex justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
      <?php if ($page > 1): ?>
        <a class="ra-pill" href="<?= h($link(['page' => $page > 2 ? (string)($page - 1) : ''])) ?>"><i class="bi bi-chevron-left me-1"></i>Previous page</a>
      <?php else: ?><span></span><?php endif; ?>
      <span class="small text-muted">Page <?= (int)$page ?></span>
      <?php if (!empty($data['more'])): ?>
        <a class="ra-pill" href="<?= h($link(['page' => (string)($page + 1)])) ?>">Next page<i class="bi bi-chevron-right ms-1"></i></a>
      <?php else: ?><span></span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
