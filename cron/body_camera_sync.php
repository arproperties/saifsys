<?php
// cron/body_camera_sync.php
// Fetches each Looki L1's new recordings from Looki's cloud, links them to jobs and finds the
// store and voice events for Operations → Body camera. Also deletes recordings past Keep files.
// Hostinger cron (every 30 minutes):
//   */30 * * * * /usr/bin/php /home/<user>/public_html/cron/body_camera_sync.php >/dev/null 2>&1
// Rules: modules/operations/includes/ops_body_camera.php.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/operations/includes/ops_helper.php';
require_once __DIR__ . '/../modules/operations/includes/ops_body_camera.php';

if (!bc_tables_ready($conn)) {
    fwrite(STDERR, "Body camera tables are not set up. Run migrations/ops_body_camera.sql.\n");
    exit(1);
}

// One run at a time: a slow download must not overlap the next run.
$lock = fopen(bc_dir() . '/sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " another sync is running\n";
    exit(0);
}

foreach (bc_sync_all($conn) as $line) {
    echo date('Y-m-d H:i:s') . ' ' . $line . "\n";
}
exit(0);
