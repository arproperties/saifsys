<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/scheduled_reports_service.php';
require_role(['Owner','Admin','Account'], $conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$report_id = (int)($_GET['id'] ?? 0);

if ($report_id <= 0) {
    echo '<div class="alert alert-danger">Invalid report ID</div>';
    exit;
}

try {
    $scheduledReportsService = new ScheduledReportsService($conn);
    $history = $scheduledReportsService->getRunHistory($report_id, 20);
    
    if (empty($history)) {
        echo '<div class="text-center py-3 text-muted">No run history available</div>';
        exit;
    }
    
    ?>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Started</th>
                    <th>Status</th>
                    <th>File Size</th>
                    <th>Recipients</th>
                    <th>Sent</th>
                    <th>Download</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $run):
                    $fsize = !empty($run['file_path']) && is_file($run['file_path']) ? filesize($run['file_path']) : 0;
                ?>
                <tr>
                    <td><?= date('M j, Y H:i:s', strtotime($run['started_at'])) ?></td>
                    <td>
                        <span class="badge bg-<?= 
                            $run['status'] === 'completed' ? 'success' : 
                            ($run['status'] === 'failed' ? 'danger' : 'warning')
                        ?>">
                            <?= ucfirst($run['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($fsize): ?>
                        <?= number_format($fsize / 1024, 1) ?> KB
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td><?= $run['total_recipients'] ?></td>
                    <td><?= $run['sent_recipients'] ?></td>
                    <td>
                        <?php if ($run['status'] === 'completed' && !empty($run['file_path']) && is_file($run['file_path'])): ?>
                        <a class="btn btn-sm btn-outline-primary" href="ajax/download_scheduled_report_run.php?run_id=<?= (int)$run['id'] ?>"><i class="bi bi-download"></i></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($run['error_message']): ?>
                        <span class="text-danger small" title="<?= h($run['error_message']) ?>">
                            <?= h(substr($run['error_message'], 0, 50)) ?><?= strlen($run['error_message']) > 50 ? '...' : '' ?>
                        </span>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading history: ' . h($e->getMessage()) . '</div>';
}
?>
