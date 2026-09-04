<?php
/**
 * ARS Shell Preview — Wave 1
 * Not linked from production navigation. Demo of shell chrome + shared states.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../includes/ars_helpers.php';
require_once __DIR__ . '/../includes/ars_permissions.php';
require_once __DIR__ . '/../includes/ars_shell.php';

arsPageAuth($conn);
$brand = getBrandSettings($conn);
$pageTitle = 'Shell Preview';

ars_shell_begin([
    'title' => 'Shell preview',
    'subtitle' => 'Wave 1 application chrome — not a business workflow page',
    'breadcrumbs' => [
        ['label' => 'ARS', 'href' => ars_shell_href('index.php')],
        ['label' => 'Tools'],
        ['label' => 'Shell preview'],
    ],
    'actions_html' => ars_ui_button('Primary action', ['icon' => 'plus', 'variant' => 'primary'])
        . ars_ui_button('Secondary', ['variant' => 'secondary']),
    'toolbar_html' => ars_ui_button('Filter', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'filter'])
        . ars_ui_button('Export', ['variant' => 'ghost', 'size' => 'sm', 'icon' => 'download']),
    'legacy_bootstrap' => false,
]);
?>

<div class="mb-4 rounded-ars-lg border border-ars-warning/40 bg-orange-50 px-4 py-3 text-ars-sm text-ars-warning" role="status">
  Preview only. Stay portal is out of scope. Financial Core untouched.
</div>

<div class="grid gap-4 lg:grid-cols-3 mb-6">
  <div class="lg:col-span-2 rounded-ars-lg border border-ars-border bg-ars-surface p-5 shadow-ars-sm">
    <h2 class="m-0 text-ars-md font-semibold">Workspace container</h2>
    <p class="mt-2 m-0 text-ars-sm text-ars-muted">Consistent margins, page header, action toolbar, and content spacing for all future staff pages.</p>
  </div>
  <div class="rounded-ars-lg border border-ars-border bg-ars-surface p-5 shadow-ars-sm">
    <h2 class="m-0 text-ars-md font-semibold">Navigation</h2>
    <p class="mt-2 m-0 text-ars-sm text-ars-muted">Approved IA with Finance promoted and empty Accounting retired. Activity Center is a disabled placeholder.</p>
  </div>
</div>

<section class="mb-6" aria-labelledby="states-h">
  <h2 id="states-h" class="mb-3 text-ars-lg font-semibold">Shared states</h2>
  <div class="grid gap-3 md:grid-cols-3">
    <?= ars_shell_page_state('loading', 'Loading') ?>
    <?= ars_shell_page_state('empty', 'Nothing here yet', 'Empty state pattern for lists and boards.') ?>
    <?= ars_shell_page_state('error', 'Something went wrong', 'Demo error banner') ?>
  </div>
</section>

<?php
ars_shell_end();
