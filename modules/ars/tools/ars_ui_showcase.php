<?php
/**
 * ARS UI Component Showcase — Phase 3B Wave 0
 *
 * Isolated, non-production. NOT linked from live ARS navigation.
 * Demo data only. Does not call financial posting endpoints.
 * Stay portal out of scope — do not link here.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../includes/ars_helpers.php';
require_once __DIR__ . '/../includes/ars_permissions.php';
require_once __DIR__ . '/../includes/ars_ui.php';

$arsCompanyId = arsPageAuth($conn);
unset($arsCompanyId); // showcase does not query live operational data
$brand = getBrandSettings($conn);
$pageTitle = 'ARS UI Showcase (Wave 0)';
$pageHead = '';
ob_start();
ars_ui_assets();
$pageHead = ob_get_clean();

require_once __DIR__ . '/../includes/ars_layout_header.php';

$versions = ars_ui_versions();
?>

<div class="alert alert-warning no-print mb-3">
  <strong>Wave 0 showcase only.</strong> Not linked from navigation. Demo data. No live financial actions.
  Stay portal is <em>out of modernization scope</em>.
</div>

<?= ars_ui_app_open(['class' => 'p-4 rounded-ars-lg border border-ars-border mb-4']) ?>
<?= ars_ui_toast_region() ?>

<?= ars_ui_breadcrumbs([
    ['label' => 'ARS', 'href' => '../index.php'],
    ['label' => 'Tools'],
    ['label' => 'UI Showcase'],
]) ?>

<?= ars_ui_page_header('Design system showcase', [
    'subtitle' => 'Wave 0 foundation · Tailwind + Alpine + Lucide · #ars-app isolation',
    'actions_html' => ars_ui_button('Demo toast', [
        'variant' => 'secondary',
        'attrs' => 'onclick="window.ArsUI && ArsUI.toast(\'Toast live region OK\', \'success\')"',
    ]),
]) ?>

<section class="mb-8" aria-labelledby="sec-type">
  <h2 id="sec-type" class="text-ars-lg font-semibold mb-3">Typography &amp; colour</h2>
  <div class="grid gap-3 md:grid-cols-2">
    <div class="rounded-ars-lg border border-ars-border bg-ars-surface p-4">
      <p class="text-ars-2xl font-semibold m-0">Plus Jakarta Sans</p>
      <p class="text-ars-muted text-ars-sm">Body / muted · KPI <span class="ars-tabular font-semibold text-ars-text">12,450.00</span></p>
    </div>
    <div class="rounded-ars-lg border border-ars-border bg-ars-surface p-4 flex flex-wrap gap-2">
      <span class="h-8 w-8 rounded-ars-md bg-ars-ink" title="primary"></span>
      <span class="h-8 w-8 rounded-ars-md bg-ars-sand" title="accent"></span>
      <span class="h-8 w-8 rounded-ars-md bg-ars-success" title="success"></span>
      <span class="h-8 w-8 rounded-ars-md bg-ars-warning" title="warning"></span>
      <span class="h-8 w-8 rounded-ars-md bg-ars-danger" title="danger"></span>
      <span class="h-8 w-8 rounded-ars-md bg-ars-info" title="info"></span>
    </div>
  </div>
</section>

<section class="mb-8" aria-labelledby="sec-btn">
  <h2 id="sec-btn" class="text-ars-lg font-semibold mb-3">Buttons</h2>
  <div class="flex flex-wrap gap-2 mb-3">
    <?= ars_ui_button('Primary', ['icon' => 'plus']) ?>
    <?= ars_ui_button('Secondary', ['variant' => 'secondary']) ?>
    <?= ars_ui_button('Danger', ['variant' => 'danger']) ?>
    <?= ars_ui_button('Ghost', ['variant' => 'ghost']) ?>
    <?= ars_ui_button('Locked pay', ['locked' => true, 'icon' => 'banknote']) ?>
    <?= ars_ui_permission_disabled('Approve', 'You do not have permission') ?>
  </div>
  <div class="flex gap-2">
    <?= ars_ui_icon_button('search', 'Search') ?>
    <?= ars_ui_icon_button('bell', 'Notifications') ?>
  </div>
</section>

<section class="mb-8" aria-labelledby="sec-status">
  <h2 id="sec-status" class="text-ars-lg font-semibold mb-3">Statuses (icon + text + colour)</h2>
  <div class="flex flex-wrap gap-2 mb-2">
    <?= ars_ui_status_badge('booking', 'pending') ?>
    <?= ars_ui_status_badge('booking', 'confirmed') ?>
    <?= ars_ui_status_badge('booking', 'checked-in') ?>
    <?= ars_ui_status_badge('booking', 'cancelled') ?>
  </div>
  <div class="flex flex-wrap gap-2 mb-2">
    <?= ars_ui_status_badge('housekeeping', 'dirty') ?>
    <?= ars_ui_status_badge('housekeeping', 'in-progress') ?>
    <?= ars_ui_status_badge('housekeeping', 'ready') ?>
  </div>
  <div class="flex flex-wrap gap-2 mb-2">
    <?= ars_ui_status_badge('maintenance', 'open') ?>
    <?= ars_ui_status_badge('maintenance', 'blocked') ?>
    <?= ars_ui_status_badge('maintenance', 'done') ?>
  </div>
  <div class="flex flex-wrap gap-2 mb-2">
    <?= ars_ui_status_badge('financial', 'draft') ?>
    <?= ars_ui_status_badge('financial', 'pending') ?>
    <?= ars_ui_status_badge('financial', 'posted') ?>
    <?= ars_ui_status_badge('financial', 'reversed') ?>
    <?= ars_ui_status_badge('financial', 'voided') ?>
    <?= ars_ui_status_badge('financial', 'locked') ?>
    <?= ars_ui_lock_indicator() ?>
  </div>
</section>

<section class="mb-8" aria-labelledby="sec-kpi">
  <h2 id="sec-kpi" class="text-ars-lg font-semibold mb-3">KPI &amp; alerts</h2>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-3">
    <?= ars_ui_kpi_card('Arrivals today', '6', ['icon' => 'log-in', 'hint' => 'Demo']) ?>
    <?= ars_ui_kpi_card('Departures', '4', ['icon' => 'log-out']) ?>
    <?= ars_ui_kpi_card('Not ready', '2', ['icon' => 'alert-triangle', 'alert' => true]) ?>
    <?= ars_ui_kpi_card('Due (AED)', '3,150.00', ['icon' => 'banknote']) ?>
  </div>
  <?= ars_ui_alert_card('Unit not ready', 'Demo: Al Noor 1204 still Dirty before 15:00 arrival.', ['tone' => 'warning']) ?>
</section>

<section class="mb-8" aria-labelledby="sec-form">
  <h2 id="sec-form" class="text-ars-lg font-semibold mb-3">Forms &amp; filters</h2>
  <?= ars_ui_error_banner('Demo validation banner (assertive live region).') ?>
  <?= ars_ui_filter_bar(
      ars_ui_form_field('q', 'Search (demo)', ['help' => 'No live query']) .
      ars_ui_button('Apply', ['variant' => 'secondary', 'type' => 'button'])
  ) ?>
  <?= ars_ui_form_field('guest', 'Guest name', ['required' => true, 'error' => 'Demo: this field is required']) ?>
</section>

<section class="mb-8" aria-labelledby="sec-table">
  <h2 id="sec-table" class="text-ars-lg font-semibold mb-3">Table shell</h2>
  <?= ars_ui_table_shell(
      'Demo bookings',
      '<tr><th class="px-3 py-2">Ref</th><th class="px-3 py-2">Guest</th><th class="px-3 py-2">Status</th></tr>',
      '<tr><td class="px-3 py-2">BK-DEMO-1</td><td class="px-3 py-2">Alex Guest</td><td class="px-3 py-2">' . ars_ui_status_badge('booking', 'confirmed') . '</td></tr>'
      . '<tr><td class="px-3 py-2">BK-DEMO-2</td><td class="px-3 py-2">Sam Example</td><td class="px-3 py-2">' . ars_ui_status_badge('booking', 'pending') . '</td></tr>'
  ) ?>
</section>

<section class="mb-8" aria-labelledby="sec-overlay">
  <h2 id="sec-overlay" class="text-ars-lg font-semibold mb-3">Drawer / Modal / Confirm</h2>
  <div class="flex flex-wrap gap-2 mt-2">
    <div x-data="{ open: false }">
      <button type="button" class="inline-flex min-h-ars-touch items-center rounded-ars-md border border-ars-border bg-ars-surface px-4 text-ars-sm" @click="open = true">Open drawer</button>
      <div x-show="open" x-cloak class="fixed inset-0 z-[1080]" role="dialog" aria-modal="true" aria-labelledby="live-drawer-title" data-ars-focus-trap @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-black/30" @click="open = false"></div>
        <div class="absolute top-0 right-0 flex h-full w-full max-w-md flex-col bg-ars-surface shadow-ars-md" @click.stop>
          <div class="flex items-center justify-between border-b border-ars-border px-4 py-3">
            <h2 id="live-drawer-title" class="text-ars-md font-semibold m-0">Demo drawer</h2>
            <button type="button" class="min-h-ars-touch min-w-ars-touch" @click="open = false" aria-label="Close">✕</button>
          </div>
          <div class="p-4 text-ars-sm text-ars-muted">Presentational only. Escape closes. Focus trap active.</div>
        </div>
      </div>
    </div>
    <div x-data="{ open: false }">
      <button type="button" class="inline-flex min-h-ars-touch items-center rounded-ars-md border border-ars-border bg-ars-surface px-4 text-ars-sm" @click="open = true">Open modal</button>
      <div x-show="open" x-cloak class="fixed inset-0 z-[1090] flex items-center justify-center p-4" role="dialog" aria-modal="true" data-ars-focus-trap @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
        <div class="relative z-10 w-full max-w-lg rounded-ars-lg border border-ars-border bg-ars-surface p-5 shadow-ars-md" @click.stop>
          <h2 class="text-ars-lg font-semibold m-0 mb-2">Demo modal</h2>
          <p class="text-ars-sm text-ars-muted">No live actions.</p>
          <button type="button" class="mt-3 rounded-ars-md border border-ars-border px-4 min-h-ars-touch" @click="open = false">Close</button>
        </div>
      </div>
    </div>
    <div x-data="{ open: false }">
      <button type="button" class="inline-flex min-h-ars-touch items-center rounded-ars-md bg-ars-danger px-4 text-white text-ars-sm min-h-ars-touch" @click="open = true">Confirm (danger)</button>
      <div x-show="open" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" role="alertdialog" aria-modal="true" data-ars-focus-trap @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
        <div class="relative z-10 w-full max-w-md rounded-ars-lg border border-ars-border bg-ars-surface p-5 shadow-ars-md" @click.stop>
          <h2 class="text-ars-lg font-semibold m-0">Demo confirmation</h2>
          <p class="mt-2 text-ars-sm text-ars-muted">This does not post journals or call financial APIs.</p>
          <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="rounded-ars-md border border-ars-border px-4 min-h-ars-touch" @click="open = false">Cancel</button>
            <button type="button" class="rounded-ars-md bg-ars-danger text-white px-4 min-h-ars-touch" @click="open = false">Confirm</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="mb-8" aria-labelledby="sec-states">
  <h2 id="sec-states" class="text-ars-lg font-semibold mb-3">Empty / skeleton / network</h2>
  <div class="grid gap-3 md:grid-cols-3">
    <?= ars_ui_empty_state('No demo rows', 'Empty state pattern for lists.') ?>
    <div class="rounded-ars-lg border border-ars-border bg-ars-surface p-4"><?= ars_ui_skeleton(['lines' => 4]) ?></div>
    <?= ars_ui_network_error('Demo network error. Retry is inert.', ['retry_id' => 'demo-retry']) ?>
  </div>
</section>

<section class="mb-8" aria-labelledby="sec-time">
  <h2 id="sec-time" class="text-ars-lg font-semibold mb-3">Timeline</h2>
  <div class="rounded-ars-lg border border-ars-border bg-ars-surface p-4">
    <?= ars_ui_timeline_item('Booking created', 'Demo · 10:12', 'Activity Center style item') ?>
    <?= ars_ui_timeline_item('Confirmed', 'Demo · 10:15', 'Would show document # when wired in later waves') ?>
  </div>
</section>

<section class="mb-8" aria-labelledby="sec-resp">
  <h2 id="sec-resp" class="text-ars-lg font-semibold mb-3">Responsive note</h2>
  <p class="text-ars-sm text-ars-muted">Resize viewport: cards stack on small screens; touch targets ≥ 44px; bottom spacing reserved for future staff mobile nav (Wave 9).</p>
  <p class="text-ars-xs text-ars-muted m-0">Versions:
    <?php foreach ($versions as $k => $v): ?>
      <code><?= ars_ui_h($k) ?>=<?= ars_ui_h($v) ?></code>
    <?php endforeach; ?>
  </p>
</section>

<?= ars_ui_app_close() ?>

<?php require_once __DIR__ . '/../includes/ars_layout_footer.php'; ?>
