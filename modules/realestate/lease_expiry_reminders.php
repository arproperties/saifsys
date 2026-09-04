<?php
/**
 * Real Estate Module - Lease Expiry Reminders
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/lease_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$success = '';
$error = '';

// Handle send reminder action (supports normal POST and AJAX JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'send_reminder') {
    csrf_verify();
    $leaseId = (int)($_POST['lease_id'] ?? 0);
    $reminderType = $_POST['reminder_type'] ?? '30_days';
    $sendToManagement = !empty($_POST['send_to_management']);
    $sendToTenant = !empty($_POST['send_to_tenant']);

    $result = ['success' => false, 'messages' => [], 'message' => 'Invalid lease'];
    if ($leaseId) {
        $result = send_lease_expiry_reminder($conn, $leaseId, $reminderType, $sendToManagement, $sendToTenant);
        if ($result['success']) {
            $success = implode(', ', $result['messages']);
        } else {
            $error = $result['message'] ?? 'Failed to send reminder';
        }
    }

    // If this is an AJAX request, return JSON immediately
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'  => (bool)($result['success'] ?? false),
            'messages' => $result['messages'] ?? [],
            'message'  => $result['message'] ?? ($success ?: $error),
        ]);
        exit;
    }
}

// Handle generate reminders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'generate_reminders') {
    csrf_verify();
    $result = generate_expiry_reminders($conn, 30);
    if ($result['success']) {
        $success = "Generated {$result['generated_count']} reminder(s) for leases expiring in 30 days";
    }
}

// Get upcoming expiring leases
$filter = $_GET['filter'] ?? 'upcoming';
$where = ["l.company_id = ?", "l.status = 'active'"];
$params = [$currentCompanyId];

if ($filter === 'upcoming') {
    $where[] = "l.end_date >= CURDATE() AND l.end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($filter === 'expired') {
    $where[] = "l.end_date < CURDATE()";
} elseif ($filter === 'all') {
    // No additional filter
}

$leases = $conn->prepare("
    SELECT l.*, 
           u.unit_number, u.unit_type,
           b.name as building_name,
           t.first_name, t.last_name, t.email, t.phone,
           DATEDIFF(l.end_date, CURDATE()) as days_until_expiry,
           (SELECT COUNT(*) FROM re_lease_expiry_reminders ler WHERE ler.lease_id = l.id AND ler.sent_to_management = 1) as reminders_sent
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY l.end_date ASC
");
$leases->execute($params);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Lease Expiry Reminders';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-header-label">Lease Expiry Reminders</div>
            <div class="btn-group">
                <form method="POST" style="display:inline;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_reminders">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Generate Reminders (30 days)
                    </button>
                </form>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Tabs -->
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $filter === 'upcoming' ? 'active' : '' ?>" href="?filter=upcoming">Upcoming (30 days)</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $filter === 'expired' ? 'active' : '' ?>" href="?filter=expired">Expired</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">All Active Leases</a>
            </li>
        </ul>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Lease Number</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th>End Date</th>
                                <th>Days Until Expiry</th>
                                <th>Reminders Sent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leases)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No leases found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($leases as $lease): ?>
                                    <tr>
                                        <td>
                                            <a href="lease_view.php?id=<?= $lease['id'] ?>">
                                                <strong><?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?></strong>
                                            </a>
                                        </td>
                                        <td><?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?></td>
                                        <td>
                                            <?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>
                                            <?php if ($lease['email']): ?>
                                                <br><small class="text-muted"><?= h($lease['email']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('Y-m-d', strtotime($lease['end_date'])) ?></td>
                                        <td>
                                            <?php
                                            $days = (int)$lease['days_until_expiry'];
                                            if ($days < 0) {
                                                echo '<span class="badge bg-danger">Expired ' . abs($days) . ' days ago</span>';
                                            } elseif ($days <= 7) {
                                                echo '<span class="badge bg-warning">' . $days . ' days</span>';
                                            } elseif ($days <= 30) {
                                                echo '<span class="badge bg-info">' . $days . ' days</span>';
                                            } else {
                                                echo '<span class="text-muted">' . $days . ' days</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($lease['reminders_sent'] > 0): ?>
                                                <span class="badge bg-success"><?= $lease['reminders_sent'] ?> sent</span>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#sendReminderModal"
                                                    data-lease-id="<?= $lease['id'] ?>"
                                                    data-lease-number="<?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?>"
                                                    data-days="<?= $days ?>">
                                                <i class="bi bi-envelope"></i> Send Reminder
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Send Reminder Modal -->
        <div class="modal fade" id="sendReminderModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Send Expiry Reminder</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" id="sendReminderForm">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="send_reminder">
                        <input type="hidden" name="lease_id" id="modal_lease_id">
                        
                        <div class="modal-body">
                            <p>Lease: <strong id="modal_lease_number"></strong></p>
                            <p>Days until expiry: <strong id="modal_days"></strong></p>
                            
                            <div class="mb-3">
                                <label class="form-label">Reminder Type</label>
                                <select name="reminder_type" class="form-select">
                                    <option value="100_days">100 Days Before</option>
                                    <option value="90_days">90 Days Before</option>
                                    <option value="60_days">60 Days Before</option>
                                    <option value="30_days">30 Days Before</option>
                                    <option value="15_days">15 Days Before</option>
                                    <option value="7_days">7 Days Before</option>
                                    <option value="1_day">1 Day Before</option>
                                    <option value="expired">Expired</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_to_management" id="send_to_management" checked>
                                    <label class="form-check-label" for="send_to_management">Send to Management</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_to_tenant" id="send_to_tenant" checked>
                                    <label class="form-check-label" for="send_to_tenant">Send to Tenant</label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Send Reminder</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var sendReminderModal = document.getElementById('sendReminderModal');
                if (sendReminderModal) {
                    sendReminderModal.addEventListener('show.bs.modal', function(event) {
                        var button = event.relatedTarget;
                        document.getElementById('modal_lease_id').value = button.getAttribute('data-lease-id');
                        document.getElementById('modal_lease_number').textContent = button.getAttribute('data-lease-number');
                        document.getElementById('modal_days').textContent = button.getAttribute('data-days') + ' days';
                    });
                }

                // AJAX submit for Send Reminder so user gets immediate feedback
                var sendReminderForm = document.getElementById('sendReminderForm');
                if (sendReminderForm) {
                    sendReminderForm.addEventListener('submit', function (e) {
                        e.preventDefault();

                        var submitBtn = sendReminderForm.querySelector('button[type="submit"]');
                        var originalHtml = submitBtn.innerHTML;
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending...';

                        var formData = new FormData(sendReminderForm);

                        fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            var msg = data.message || (data.messages && data.messages.join(', ')) || 'Reminder processed.';
                            if (data.success) {
                                // Close modal and reload so the table + count update
                                var modalInstance = bootstrap.Modal.getInstance(sendReminderModal);
                                if (modalInstance) {
                                    modalInstance.hide();
                                }
                                alert('Reminder sent: ' + msg);
                                window.location.reload();
                            } else {
                                alert('Failed to send reminder: ' + msg);
                            }
                        })
                        .catch(function (err) {
                            console.error('Error sending reminder:', err);
                            alert('An error occurred while sending the reminder. Please try again.');
                        })
                        .finally(function () {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalHtml;
                        });
                    });
                }
            });
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

