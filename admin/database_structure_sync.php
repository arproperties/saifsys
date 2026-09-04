<?php
/**
 * Live server: compare schema snapshot vs live DB and apply safe structure changes.
 * STRUCTURE ONLY — never drops tables/columns or deletes data.
 */
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/db_connect.php';
require_once $projectRoot . '/includes/database_structure_sync.php';

$providedSyncKey = (string)($_GET['sync_key'] ?? $_POST['sync_key'] ?? '');
$hasSyncKey = $providedSyncKey !== '';

$roles = [];
if (!$hasSyncKey) {
    require_once $projectRoot . '/includes/auth.php';
    $roles = current_user_id() ? current_user_roles($conn) : [];
}

if (!DatabaseStructureSync::checkAccess($roles, $providedSyncKey ?: null)) {
    if ($hasSyncKey) {
        http_response_code(403);
        die('Access denied. Invalid sync_key.');
    }
    require_once $projectRoot . '/includes/auth.php';
    require_login('../login');
    exit;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$snapshotPath = DatabaseStructureSync::resolveSnapshotPath($projectRoot);
$logsDir = $projectRoot . '/logs';
$mode = $_POST['mode'] ?? $_GET['mode'] ?? 'preview';
$backupConfirmed = !empty($_POST['backup_confirmed']);
$applyConfirmed = !empty($_POST['apply_confirmed']);
$forceRefresh = !empty($_GET['refresh']) || !empty($_POST['refresh']);
$runCompare = $forceRefresh || !empty($_GET['run_compare']) || !empty($_POST['run_compare']);

$msg = '';
$err = '';
$plan = null;
$applyResult = null;
$logPath = '';
$localMeta = [];
$liveInventory = [];
$local = [];
$compareElapsed = 0;
$fromCache = false;
$cacheGeneratedAt = null;
$awaitingCompare = false;
$snapshotInfo = [];

$sync = new DatabaseStructureSync($conn, DB_NAME);

try {
    if (!is_file($snapshotPath)) {
        throw new RuntimeException(
            'Schema snapshot not found. Expected: storage/schema_snapshots/schema_snapshot_local.json '
            . '(or legacy database/schema_snapshot_local.json). '
            . 'Run database/export_schema_snapshot.php on localhost and upload the file.'
        );
    }

    $snapshotInfo = [
        'path' => $snapshotPath,
        'bytes' => (int)filesize($snapshotPath),
        'modified' => date('Y-m-d H:i:s', (int)filemtime($snapshotPath)),
    ];
    $cachePath = DatabaseStructureSync::planCachePathForSnapshot($snapshotPath);
    $hasCache = is_file($cachePath);

    if ($mode === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $built = DatabaseStructureSync::loadCachedPlan($snapshotPath);
        if (!$built) {
            $built = $sync->buildComparePlan($snapshotPath, true);
        }
        $plan = $built['plan'];
        $local = $built['local'];
        $localMeta = $built['local_meta'];
        $liveInventory = $built['live_inventory'];

        if (!$backupConfirmed || !$applyConfirmed) {
            throw new RuntimeException('You must confirm database backup and apply authorization.');
        }
        $applyResult = $sync->applyPlan($plan, false);
        $logPath = DatabaseStructureSync::writeLogFile($logsDir, $plan, $applyResult);
        $failed = count($applyResult['failed'] ?? []);
        $ok = count($applyResult['executed'] ?? []);
        if ($failed > 0) {
            $err = "Apply finished with $ok succeeded and $failed failed. See log: " . basename($logPath);
        } else {
            $msg = "Apply finished successfully ($ok changes). Log: " . basename($logPath);
        }
        @unlink($cachePath);
        $built = $sync->buildComparePlan($snapshotPath, true);
        $plan = $built['plan'];
        $local = $built['local'];
        $localMeta = $built['local_meta'];
        $liveInventory = $built['live_inventory'];
        $compareElapsed = $built['elapsed'];
        $fromCache = false;
        $cacheGeneratedAt = $built['cache_generated_at'] ?? null;
    } elseif ($runCompare) {
        $built = $sync->buildComparePlan($snapshotPath, $forceRefresh);
        $plan = $built['plan'];
        $local = $built['local'];
        $localMeta = $built['local_meta'];
        $liveInventory = $built['live_inventory'];
        $compareElapsed = $built['elapsed'];
        $fromCache = $built['from_cache'];
        $cacheGeneratedAt = $built['cache_generated_at'] ?? null;
        $applyResult = $sync->applyPlan($plan, true);
    } else {
        $built = DatabaseStructureSync::loadCachedPlan($snapshotPath);
        if ($built) {
            $plan = $built['plan'];
            $local = $built['local'];
            $localMeta = $built['local_meta'];
            $liveInventory = $built['live_inventory'];
            $compareElapsed = $built['elapsed'];
            $fromCache = true;
            $cacheGeneratedAt = $built['cache_generated_at'] ?? null;
            $applyResult = $sync->applyPlan($plan, true);
        } else {
            $awaitingCompare = true;
            $liveInventory = $sync->fetchLiveInventoryCounts();
            $summary = DatabaseStructureSync::loadSnapshotSummary($snapshotPath);
            $localMeta = $summary['meta'];
            $local = ['inventory' => $summary['inventory']];
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$summary = $plan['summary'] ?? [];
$safeCount = count($plan['safe'] ?? []);
$manualCount = count($plan['manual_review'] ?? []);
$skippedCount = count($plan['skipped'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Structure Sync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .hero { background: linear-gradient(135deg, #1e3a5f, #2563eb); color: #fff; border-radius: 1rem; }
        pre.sql-block { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: .5rem; font-size: .8rem; max-height: 200px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
        .stat-card { border: 0; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .badge-safe { background: #dcfce7; color: #166534; }
        .badge-risk { background: #fee2e2; color: #991b1b; }
        .badge-skip { background: #e5e7eb; color: #374151; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="hero p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-database-gear"></i> Database Structure Sync</h1>
                <p class="mb-0 opacity-75">Compare localhost snapshot with live <strong><?= h(DB_NAME) ?></strong> — structure only, no data changes.</p>
            </div>
            <span class="badge bg-light text-dark fs-6"><?= h(strtoupper($mode)) ?> MODE</span>
        </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

    <?php if ($awaitingCompare && !$err): ?>
    <div class="alert alert-primary">
        <strong>Ready to compare.</strong> Snapshot found (<?= number_format($snapshotInfo['bytes'] ?? 0) ?> bytes).
        No cached plan yet — click below to run the compare (structure only, no data changes).
    </div>
    <div class="card stat-card mb-4">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-4">On Hostinger, use the text runner first if the button below times out.</p>
            <a class="btn btn-primary btn-lg me-2" href="?<?= $hasSyncKey ? 'sync_key=' . rawurlencode($providedSyncKey) . '&amp;' : '' ?>run_compare=1">
                <i class="bi bi-play-fill"></i> Run Structure Compare
            </a>
            <?php if ($hasSyncKey): ?>
            <a class="btn btn-outline-secondary btn-lg" href="../database/run_database_structure_sync.php?mode=preview&amp;sync_key=<?= h($providedSyncKey) ?>" target="_blank">
                <i class="bi bi-terminal"></i> Text Runner (recommended)
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($plan && !$err): ?>
    <div class="alert alert-info">
        Compare completed in <strong><?= h((string)$compareElapsed) ?>s</strong>
        <?php if ($fromCache): ?> (loaded from cache — generated <?= h((string)$cacheGeneratedAt) ?>)<?php endif; ?>.
        <?php if ($hasSyncKey): ?>
        If this page timed out before, use
        <a class="alert-link" href="../database/run_database_structure_sync.php?mode=preview&amp;sync_key=<?= h($providedSyncKey) ?>">CLI-style preview</a>
        via Hostinger Terminal, or
        <a class="alert-link" href="?sync_key=<?= h($providedSyncKey) ?>&amp;refresh=1">refresh compare</a>.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="alert alert-warning border-warning">
        <strong><i class="bi bi-exclamation-triangle"></i> Please backup the live database before running Apply Mode.</strong>
        This tool never drops tables or columns automatically, but always take a full mysqldump backup first.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-semibold">Local snapshot (uploaded)</div>
                <div class="card-body small">
                    <?php if ($localMeta): ?>
                    <div>Exported: <?= h($localMeta['exported_at'] ?? '?') ?></div>
                    <div>From DB: <?= h($localMeta['database'] ?? '?') ?></div>
                    <div>Server: <?= h($localMeta['server_version'] ?? '?') ?></div>
                    <div>Tables: <?= (int)($local['inventory']['tables'] ?? 0) ?></div>
                    <div>Views: <?= (int)($local['inventory']['views'] ?? 0) ?></div>
                    <div>FKs: <?= (int)($local['inventory']['foreign_keys'] ?? 0) ?></div>
                    <?php else: ?>
                    <span class="text-muted">No snapshot loaded</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card stat-card h-100">
                <div class="card-header bg-white fw-semibold">Live database (current)</div>
                <div class="card-body small">
                    <div>Database: <?= h(DB_NAME) ?></div>
                    <div>Tables: <?= (int)($liveInventory['tables'] ?? 0) ?></div>
                    <div>Views: <?= (int)($liveInventory['views'] ?? 0) ?></div>
                    <div>Triggers: <?= (int)($liveInventory['triggers'] ?? 0) ?></div>
                    <div>Procedures: <?= (int)($liveInventory['procedures'] ?? 0) ?></div>
                    <div>Functions: <?= (int)($liveInventory['functions'] ?? 0) ?></div>
                    <div>FKs: <?= (int)($liveInventory['foreign_keys'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($plan): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><div class="fs-3 text-success"><?= $safeCount ?></div><div class="text-muted small">Safe changes</div></div></div></div>
        <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><div class="fs-3 text-danger"><?= $manualCount ?></div><div class="text-muted small">Manual review</div></div></div></div>
        <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><div class="fs-3 text-secondary"><?= $skippedCount ?></div><div class="text-muted small">Skipped (live-only)</div></div></div></div>
        <div class="col-md-3"><div class="card stat-card"><div class="card-body text-center"><div class="fs-3"><?= (int)($summary['missing_tables'] ?? 0) ?></div><div class="text-muted small">Missing tables</div></div></div></div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-safe">Safe (<?= $safeCount ?>)</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-manual">Manual review (<?= $manualCount ?>)</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-skipped">Skipped (<?= $skippedCount ?>)</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-summary">Summary</button></li>
    </ul>

    <div class="tab-content mb-4">
        <div class="tab-pane fade show active" id="tab-safe">
            <?php if (!$plan['safe']): ?>
            <div class="alert alert-info">No safe auto-apply changes detected — live structure matches local for additive rules.</div>
            <?php else: ?>
            <?php foreach ($plan['safe'] as $i => $entry): ?>
            <div class="card mb-2">
                <div class="card-body py-2">
                    <span class="badge badge-safe"><?= h($entry['category']) ?></span>
                    <strong><?= h($entry['object']) ?></strong>
                    <span class="text-muted small">— <?= h($entry['reason']) ?></span>
                    <?php if (!empty($entry['sql'])): ?><pre class="sql-block mt-2 mb-0"><?= h($entry['sql']) ?></pre><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="tab-manual">
            <?php if (!$plan['manual_review']): ?>
            <div class="alert alert-success">No risky changes flagged.</div>
            <?php else: ?>
            <?php foreach ($plan['manual_review'] as $entry): ?>
            <div class="card mb-2 border-danger">
                <div class="card-body py-2">
                    <span class="badge badge-risk"><?= h($entry['category']) ?></span>
                    <strong><?= h($entry['object']) ?></strong>
                    <span class="text-muted small">— <?= h($entry['reason']) ?></span>
                    <?php if (!empty($entry['sql'])): ?><pre class="sql-block mt-2 mb-0"><?= h($entry['sql']) ?></pre><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="tab-skipped">
            <?php if (!$plan['skipped']): ?>
            <div class="text-muted">Nothing skipped.</div>
            <?php else: ?>
            <?php foreach (array_slice($plan['skipped'], 0, 100) as $entry): ?>
            <div class="small mb-1"><span class="badge badge-skip"><?= h($entry['category']) ?></span> <?= h($entry['object']) ?> — <?= h($entry['reason']) ?></div>
            <?php endforeach; ?>
            <?php if ($skippedCount > 100): ?><div class="text-muted small">… and <?= $skippedCount - 100 ?> more</div><?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="tab-summary">
            <table class="table table-sm table-bordered bg-white">
                <?php foreach ($summary as $k => $v): ?>
                <tr><td><?= h(str_replace('_', ' ', $k)) ?></td><td class="text-end"><?= (int)$v ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="card stat-card mb-4">
        <div class="card-body">
            <h2 class="h5">Actions</h2>
            <form method="get" class="mb-3 d-inline">
                <?php if ($hasSyncKey): ?><input type="hidden" name="sync_key" value="<?= h($providedSyncKey) ?>"><?php endif; ?>
                <input type="hidden" name="refresh" value="1">
                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Refresh Compare</button>
            </form>
            <form method="post" class="mb-3 d-inline">
                <input type="hidden" name="mode" value="preview">
                <?php if ($hasSyncKey): ?><input type="hidden" name="sync_key" value="<?= h($providedSyncKey) ?>"><?php endif; ?>
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-eye"></i> Reload Preview</button>
            </form>

            <?php if ($safeCount > 0): ?>
            <form method="post" onsubmit="return confirmApply();">
                <input type="hidden" name="mode" value="apply">
                <?php if ($hasSyncKey): ?>
                <input type="hidden" name="sync_key" value="<?= h($providedSyncKey) ?>">
                <?php endif; ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="backup_confirmed" id="backup_confirmed" value="1">
                    <label class="form-check-label" for="backup_confirmed">I have backed up the live database (mysqldump).</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="apply_confirmed" id="apply_confirmed" value="1">
                    <label class="form-check-label" for="apply_confirmed">Apply <?= $safeCount ?> safe structure change(s) now (no drops, no data deletion).</label>
                </div>
                <button type="submit" class="btn btn-danger"><i class="bi bi-lightning"></i> Apply Safe Changes</button>
            </form>
            <?php else: ?>
            <p class="text-muted mb-0">Nothing safe to apply automatically.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card stat-card">
        <div class="card-body small text-muted">
            <strong>Safety rules:</strong> Never drops tables/columns/views · Never truncates · Never renames automatically ·
            Risky type/null/FK changes appear under Manual Review · Logs saved to <code>logs/database_structure_sync_*.log</code>
            <?php if ($hasSyncKey): ?>
            <br><strong>Hostinger timeout?</strong> Run in Terminal:
            <code>php database/run_database_structure_sync.php --preview --sync-key=<?= h($providedSyncKey) ?></code>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmApply() {
    return document.getElementById('backup_confirmed').checked
        && document.getElementById('apply_confirmed').checked
        && confirm('Apply safe structure changes to LIVE database <?= h(DB_NAME) ?>?\n\nEnsure you have a full backup.');
}
</script>
</body>
</html>
