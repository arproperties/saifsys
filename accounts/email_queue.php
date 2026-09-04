<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/email_queue_service.php';
require_role(['Owner','Admin','Account'], $conn);

// Helper function for HTML escaping
if (!function_exists('h')) {
    function h($s) { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

$msg = $err = '';
$emailQueueService = new EmailQueueService($conn);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $queue_id = (int)($_POST['queue_id'] ?? 0);

        switch ($action) {
            case 'cancel':
                if ($queue_id <= 0) {
                    throw new Exception('Invalid queue ID');
                }
                $result = $emailQueueService->cancelQueue($queue_id);
                if ($result['success']) {
                    $msg = "Queue cancelled successfully";
                } else {
                    $err = $result['error'];
                }
                break;

            case 'retry':
                if ($queue_id <= 0) {
                    throw new Exception('Invalid queue ID');
                }
                $result = $emailQueueService->retryFailedItems($queue_id);
                if ($result['success']) {
                    $msg = "Failed items reset for retry";
                } else {
                    $err = $result['error'];
                }
                break;

            case 'process':
                if ($queue_id <= 0) {
                    throw new Exception('Invalid queue ID');
                }
                $result = $emailQueueService->processQueue($queue_id);
                if ($result['success']) {
                    $msg = "Queue processed: {$result['processed']} sent, {$result['failed']} failed";
                } else {
                    $err = $result['error'];
                }
                break;

            case 'cleanup':
                $cleaned = $emailQueueService->cleanupOldQueues();
                $msg = "Cleaned up {$cleaned} old queue records";
                break;
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// Get filter parameters
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get queues
$queues = $emailQueueService->getQueues($per_page, $offset, $status ?: null);

// Get total count for pagination
$count_stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM email_queue 
    " . ($status ? "WHERE status = ?" : "")
);
$count_stmt->execute($status ? [$status] : []);
$total_queues = $count_stmt->fetchColumn();
$total_pages = ceil($total_queues / $per_page);

// Get statistics
$stats = $emailQueueService->getQueueStats();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Email Queue Management | BMSystem</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .status-badge { font-size: 0.8rem; }
        .progress-sm { height: 0.5rem; }
        .queue-card { transition: transform 0.2s; }
        .queue-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-light">
<div class="container my-4">
    <div class="d-flex align-items-center mb-4">
        <a href="../account" class="btn btn-outline-secondary me-3">
            <i class="bi bi-arrow-left"></i> Back to Accounts
        </a>
        <h2 class="mb-0">Email Queue Management</h2>
        <div class="ms-auto">
            <button class="btn btn-warning" onclick="cleanupOldQueues()">
                <i class="bi bi-trash"></i> Cleanup Old
            </button>
        </div>
    </div>

    <?php if($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-primary"><?= $stats['total_queues'] ?></h5>
                    <p class="card-text small">Total Queues</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-warning"><?= $stats['pending_queues'] + $stats['processing_queues'] ?></h5>
                    <p class="card-text small">Active Queues</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-success"><?= $stats['processed_emails'] ?></h5>
                    <p class="card-text small">Emails Sent</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-danger"><?= $stats['failed_emails'] ?></h5>
                    <p class="card-text small">Failed Emails</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Status Filter</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="email_queue.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Queues List -->
    <div class="row">
        <?php if (empty($queues)): ?>
        <div class="col-12">
            <div class="text-center py-5">
                <i class="bi bi-envelope display-1 text-muted"></i>
                <h4 class="text-muted mt-3">No Email Queues</h4>
                <p class="text-muted">No email queues found matching your criteria.</p>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($queues as $queue): ?>
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card queue-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?= h($queue['queue_name']) ?></h6>
                    <span class="badge bg-<?= 
                        $queue['status'] === 'completed' ? 'success' : 
                        ($queue['status'] === 'failed' ? 'danger' : 
                        ($queue['status'] === 'processing' ? 'warning' : 
                        ($queue['status'] === 'cancelled' ? 'secondary' : 'primary')))
                    ?> status-badge">
                        <?= ucfirst($queue['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        <i class="bi bi-tag"></i> <?= h($queue['entity_type']) ?>
                    </p>
                    <p class="small mb-2">
                        <strong>Items:</strong> <?= $queue['total_items'] ?>
                    </p>
                    
                    <?php if ($queue['total_items'] > 0): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Progress</span>
                            <span><?= $queue['sent_items'] + $queue['failed_items'] ?> / <?= $queue['total_items'] ?></span>
                        </div>
                        <div class="progress progress-sm">
                            <div class="progress-bar bg-success" style="width: <?= ($queue['sent_items'] / $queue['total_items']) * 100 ?>%"></div>
                            <div class="progress-bar bg-danger" style="width: <?= ($queue['failed_items'] / $queue['total_items']) * 100 ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <p class="small text-muted mb-0">
                        <i class="bi bi-calendar"></i>
                        Created: <?= date('M j, Y H:i', strtotime($queue['created_at'])) ?>
                    </p>
                    
                    <?php if ($queue['completed_at']): ?>
                    <p class="small text-muted mb-0">
                        <i class="bi bi-check-circle"></i>
                        Completed: <?= date('M j, Y H:i', strtotime($queue['completed_at'])) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div class="btn-group btn-group-sm w-100">
                        <?php if ($queue['status'] === 'pending'): ?>
                        <button class="btn btn-outline-primary" onclick="processQueue(<?= $queue['id'] ?>)">
                            <i class="bi bi-play"></i> Process
                        </button>
                        <button class="btn btn-outline-danger" onclick="cancelQueue(<?= $queue['id'] ?>)">
                            <i class="bi bi-x"></i> Cancel
                        </button>
                        <?php elseif ($queue['status'] === 'failed'): ?>
                        <button class="btn btn-outline-warning" onclick="retryQueue(<?= $queue['id'] ?>)">
                            <i class="bi bi-arrow-clockwise"></i> Retry
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-info" onclick="viewQueueDetails(<?= $queue['id'] ?>)">
                            <i class="bi bi-eye"></i> Details
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Queue pagination">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>&status=<?= h($status) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Queue Details Modal -->
<div class="modal fade" id="queueDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Queue Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="queueDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function processQueue(queueId) {
    if (confirm('Process this queue now?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="process">
            <input type="hidden" name="queue_id" value="${queueId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function cancelQueue(queueId) {
    if (confirm('Cancel this queue? Pending items will be skipped.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="queue_id" value="${queueId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function retryQueue(queueId) {
    if (confirm('Retry failed items in this queue?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="retry">
            <input type="hidden" name="queue_id" value="${queueId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewQueueDetails(queueId) {
    fetch(`ajax/get_queue_details.php?id=${queueId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('queueDetailsContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('queueDetailsModal')).show();
        })
        .catch(error => {
            alert('Error loading queue details: ' + error.message);
        });
}

function cleanupOldQueues() {
    if (confirm('Clean up old completed queue records? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="cleanup">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-refresh every 30 seconds for active queues
setInterval(() => {
    const activeQueues = document.querySelectorAll('.badge.bg-warning, .badge.bg-primary');
    if (activeQueues.length > 0) {
        location.reload();
    }
}, 30000);
</script>
<script>
  // Clean URL navigation for data-tab links
  document.querySelectorAll('a[data-tab]').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const tab = this.getAttribute('data-tab');
      const form = document.createElement('form');
      form.method = 'POST';
      // Use absolute path based on current location
      const currentPath = window.location.pathname;
      const basePath = currentPath.substring(0, currentPath.indexOf('/accounts'));
      form.action = basePath + '/account';
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'tab';
      input.value = tab;
      form.appendChild(input);
      document.body.appendChild(form);
      form.submit();
    });
  });
</script>
</body>
</html>
