<?php
/**
 * Global Activity Center — company-wide feed of booking activity events.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';
require_once __DIR__ . '/includes/ars_activity.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_ds.php';

$arsCompanyId = arsPageAuth($conn);
$brand = getBrandSettings($conn);

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if ($filter === 'payments') {
    $filter = 'payment';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$feed = ars_company_activities_fetch($conn, $arsCompanyId, $filter, $page, 40);

$categories = array_merge(['all'], ars_activity_categories());
$catLabels = [
    'all' => 'All',
    'operational' => 'Ops',
    'financial' => 'Financial',
    'payment' => 'Payments',
    'accounting' => 'Accounting',
    'housekeeping' => 'HK',
    'maintenance' => 'Maint',
    'notes' => 'Notes',
    'documents' => 'Documents',
    'system' => 'System',
];

$pageTitle = 'Activity Center';
ars_shell_begin([
    'title' => 'Activity Center',
    'subtitle' => 'Company-wide booking events · ' . number_format((int)$feed['total']) . ' total',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => 'index.php'],
        ['label' => 'Activity Center'],
    ],
    'legacy_bootstrap' => false,
]);
?>

<div class="mb-4 flex flex-wrap gap-2">
<?php foreach ($categories as $cat):
    $active = $filter === $cat;
    $label = $catLabels[$cat] ?? ucfirst($cat);
    $href = '?filter=' . rawurlencode($cat);
?>
    <a href="<?= h($href) ?>"
       class="no-underline inline-flex items-center rounded-full border px-3 py-1.5 text-ars-sm font-medium transition-colors
              <?= $active
                  ? 'border-ars-ink bg-ars-ink text-white'
                  : 'border-ars-border bg-ars-surface text-ars-muted hover:border-ars-ink/40 hover:text-ars-text' ?>">
        <?= h($label) ?>
    </a>
<?php endforeach; ?>
</div>

<div class="rounded-ars-lg border border-ars-border bg-ars-surface shadow-ars-sm overflow-hidden">
<?php if (empty($feed['items'])): ?>
    <div class="p-8">
        <?= ars_ui_empty_state(
            'No activity yet',
            ars_booking_activities_table_ready($conn)
                ? 'Events appear here when bookings are confirmed, paid, amended, or noted.'
                : 'Activity table is not installed yet. Run the Phase 1B activity migration if needed.'
        ) ?>
    </div>
<?php else: ?>
    <ul class="divide-y divide-ars-border m-0 p-0 list-none">
    <?php foreach ($feed['items'] as $item):
        $bookingId = (int)($item['booking_id'] ?? 0);
        $bookingNum = (string)($item['booking_number'] ?? '');
        $title = (string)($item['title'] ?? 'Event');
        $when = (string)($item['created_at'] ?? '');
        $cat = (string)($item['event_category'] ?? 'system');
        $who = (string)($item['created_by_name'] ?? 'System');
        $deep = $item['deep_link'] ?? null;
        $href = '#';
        if (is_array($deep) && !empty($deep['href'])) {
            $href = (string)$deep['href'];
        } elseif (is_array($deep) && !empty($deep['url'])) {
            $href = (string)$deep['url'];
        } elseif ($bookingId > 0) {
            $href = 'booking_view.php?id=' . $bookingId . '#ws-activity';
        }
    ?>
        <li class="px-4 py-3 hover:bg-ars-bg/80 transition-colors">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 mb-0.5">
                        <span class="inline-flex rounded-full bg-teal-50 text-ars-ink border border-ars-border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"><?= h($cat) ?></span>
                        <?php if ($bookingNum !== ''): ?>
                            <a class="text-ars-sm font-semibold text-ars-ink no-underline hover:underline" href="booking_view.php?id=<?= $bookingId ?>"><?= h($bookingNum) ?></a>
                        <?php endif; ?>
                    </div>
                    <a class="block text-ars-text font-medium no-underline hover:text-ars-ink" href="<?= h($href) ?>"><?= h($title) ?></a>
                    <?php if (!empty($item['description'])): ?>
                        <p class="mt-1 mb-0 text-ars-sm text-ars-muted"><?= h((string)$item['description']) ?></p>
                    <?php endif; ?>
                    <div class="mt-1 text-[12px] text-ars-muted"><?= h($who) ?><?= $when !== '' ? ' · ' . h($when) : '' ?></div>
                </div>
                <?php if ($bookingId > 0): ?>
                <a class="shrink-0 text-ars-sm font-semibold text-ars-ink no-underline hover:underline" href="booking_view.php?id=<?= $bookingId ?>">Open</a>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>

<?php if (!empty($feed['has_more']) || $page > 1): ?>
<nav class="mt-4 flex items-center justify-between gap-3">
    <div>
    <?php if ($page > 1): ?>
        <a class="btn-ars-outline inline-flex no-underline px-3 py-2 rounded-ars-md border border-ars-border text-ars-sm" href="?filter=<?= h(rawurlencode($filter)) ?>&amp;page=<?= $page - 1 ?>">Previous</a>
    <?php endif; ?>
    </div>
    <span class="text-ars-sm text-ars-muted">Page <?= (int)$page ?></span>
    <div>
    <?php if (!empty($feed['has_more'])): ?>
        <a class="inline-flex no-underline px-3 py-2 rounded-ars-md bg-ars-ink text-white text-ars-sm font-semibold" href="?filter=<?= h(rawurlencode($filter)) ?>&amp;page=<?= $page + 1 ?>">Next</a>
    <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<?php ars_shell_end(); ?>
