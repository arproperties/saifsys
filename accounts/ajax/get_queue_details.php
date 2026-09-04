<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/email_queue_service.php';
require_role(['Owner','Admin','Account'], $conn);

// Helper function for HTML escaping
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

$queue_id = (int)($_GET['id'] ?? 0);

if ($queue_id <= 0) {
    echo '<div class="alert alert-danger">Invalid queue ID</div>';
    exit;
}

try {
    $emailQueueService = new EmailQueueService($conn);
    
    // Get queue details
    $queue = $emailQueueService->getQueueStatus($queue_id);
    if (!$queue) {
        echo '<div class="alert alert-danger">Queue not found</div>';
        exit;
    }
    
    // Get queue items
    $stmt = $conn->prepare("
        SELECT 
            qi.*,
            CASE 
                WHEN q.entity_type = 'invoice' THEN i.invoice_no
                WHEN q.entity_type = 'statement' THEN CONCAT('Statement for Client #', qi.entity_id)
                ELSE CONCAT('Entity #', qi.entity_id)
            END as entity_name
        FROM email_queue_items qi
        LEFT JOIN email_queue q ON q.id = qi.queue_id
        LEFT JOIN invoices i ON i.id = qi.entity_id AND q.entity_type = 'invoice'
        WHERE qi.queue_id = ?
        ORDER BY qi.created_at
    ");
    $stmt->execute([$queue_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ?>
    <div class="row">
        <div class="col-md-6">
            <h6>Queue Information</h6>
            <table class="table table-sm">
                <tr><td><strong>Name:</strong></td><td><?= h($queue['queue_name']) ?></td></tr>
                <tr><td><strong>Type:</strong></td><td><?= h($queue['entity_type']) ?></td></tr>
                <tr><td><strong>Status:</strong></td><td>
                    <span class="badge bg-<?= 
                        $queue['status'] === 'completed' ? 'success' : 
                        ($queue['status'] === 'failed' ? 'danger' : 
                        ($queue['status'] === 'processing' ? 'warning' : 
                        ($queue['status'] === 'cancelled' ? 'secondary' : 'primary')))
                    ?>">
                        <?= ucfirst($queue['status']) ?>
                    </span>
                </td></tr>
                <tr><td><strong>Created:</strong></td><td><?= date('M j, Y H:i:s', strtotime($queue['created_at'])) ?></td></tr>
                <?php if ($queue['started_at']): ?>
                <tr><td><strong>Started:</strong></td><td><?= date('M j, Y H:i:s', strtotime($queue['started_at'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($queue['completed_at']): ?>
                <tr><td><strong>Completed:</strong></td><td><?= date('M j, Y H:i:s', strtotime($queue['completed_at'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($queue['error_message']): ?>
                <tr><td><strong>Error:</strong></td><td class="text-danger"><?= h($queue['error_message']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
        <div class="col-md-6">
            <h6>Progress Summary</h6>
            <div class="mb-3">
                <div class="d-flex justify-content-between">
                    <span>Total Items:</span>
                    <strong><?= $queue['total_items'] ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Sent:</span>
                    <span class="text-success"><?= $queue['sent_items'] ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Failed:</span>
                    <span class="text-danger"><?= $queue['failed_items'] ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Pending:</span>
                    <span class="text-warning"><?= $queue['pending_items'] ?></span>
                </div>
            </div>
            
            <?php if ($queue['total_items'] > 0): ?>
            <div class="progress mb-3">
                <div class="progress-bar bg-success" style="width: <?= ($queue['sent_items'] / $queue['total_items']) * 100 ?>%"></div>
                <div class="progress-bar bg-danger" style="width: <?= ($queue['failed_items'] / $queue['total_items']) * 100 ?>%"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <h6>Queue Items</h6>
    <div class="table-responsive">
        <table class="table table-sm table-striped">
            <thead>
                <tr>
                    <th>Entity</th>
                    <th>Recipient</th>
                    <th>Status</th>
                    <th>Sent At</th>
                    <th>Error</th>
                    <th>Retries</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= h($item['entity_name']) ?></td>
                    <td><?= h($item['recipient_email']) ?></td>
                    <td>
                        <span class="badge bg-<?= 
                            $item['status'] === 'sent' ? 'success' : 
                            ($item['status'] === 'failed' ? 'danger' : 
                            ($item['status'] === 'skipped' ? 'secondary' : 'warning'))
                        ?>">
                            <?= ucfirst($item['status']) ?>
                        </span>
                    </td>
                    <td><?= $item['sent_at'] ? date('M j, H:i:s', strtotime($item['sent_at'])) : '-' ?></td>
                    <td>
                        <?php if ($item['error_message']): ?>
                        <span class="text-danger small" title="<?= h($item['error_message']) ?>">
                            <?= h(substr($item['error_message'], 0, 50)) ?><?= strlen($item['error_message']) > 50 ? '...' : '' ?>
                        </span>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td><?= $item['retry_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading queue details: ' . h($e->getMessage()) . '</div>';
}
?>
