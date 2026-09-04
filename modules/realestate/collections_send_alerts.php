<?php
/**
 * Real Estate Module - Send Collections Alerts
 * Manually trigger alert sending for overdue payments
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/collections_helper.php';
require_once __DIR__ . '/includes/installment_outstanding.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

$results = [];
$alertType = $_GET['type'] ?? 'all';

// Handle manual alert sending
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_overdue_alerts') {
        // Get all overdue installments
        $overdue = $conn->prepare("
            SELECT
                li.id,
                li.id as installment_id,
                li.lease_id,
                li.installment_date as due_date,
                li.amount,
                DATEDIFF(CURDATE(), li.installment_date) as days_overdue
            FROM re_lease_installments li
            JOIN re_leases l ON l.id = li.lease_id
            WHERE l.company_id = ?
            AND l.status <> 'draft'
            AND li.status = 'pending'
            AND li.installment_date < CURDATE()
            AND NOT EXISTS (
                SELECT 1
                FROM re_post_dated_cheques c
                WHERE c.installment_id = li.id
                  AND c.lease_id = li.lease_id
                  AND c.status IN ('cleared', 'returned', 'cancelled')
            )
        ");
        $overdue->execute([$currentCompanyId]);
        // Resolve what is really still owed (the schedule row stays 'pending' at face
        // value in Invoice Mode) and drop settled rows before emailing anyone.
        $overdueItems = re_apply_installment_outstanding($conn, (int)$currentCompanyId, $overdue->fetchAll(PDO::FETCH_ASSOC));

        $sent = 0;
        $skipped = 0;
        foreach ($overdueItems as $item) {
            $result = send_overdue_rent_alert(
                $conn,
                $currentCompanyId,
                $item['lease_id'],
                $item['installment_id'],
                null,
                null,
                $item['due_date'],
                (float)$item['outstanding_balance'],
                (int)$item['days_overdue']
            );
            
            if ($result['success']) {
                $sent++;
            } else {
                $skipped++;
            }
        }
        
        $results[] = [
            'type' => 'overdue_installments',
            'sent' => $sent,
            'skipped' => $skipped,
            'message' => "Sent {$sent} alerts, skipped {$skipped}"
        ];
    }
    
    if ($action === 'send_billing_items_alerts') {
        // Get all overdue billing items
        $overdue = $conn->prepare("
            SELECT 
                bi.id as billing_item_id,
                bi.lease_id,
                bi.due_date,
                bi.total_amount as amount,
                DATEDIFF(CURDATE(), bi.due_date) as days_overdue
            FROM re_billing_items bi
            JOIN re_leases l ON l.id = bi.lease_id
            WHERE bi.company_id = ?
            AND l.status <> 'draft'
            AND bi.is_paid = 0
            AND COALESCE(bi.is_waived, 0) = 0
            AND bi.status != 'waived'
            AND bi.due_date < CURDATE()
        ");
        $overdue->execute([$currentCompanyId]);
        $overdueItems = $overdue->fetchAll(PDO::FETCH_ASSOC);
        
        $sent = 0;
        $skipped = 0;
        foreach ($overdueItems as $item) {
            $result = send_overdue_rent_alert(
                $conn,
                $currentCompanyId,
                $item['lease_id'],
                null,
                $item['billing_item_id'],
                null,
                $item['due_date'],
                (float)$item['amount'],
                (int)$item['days_overdue']
            );
            
            if ($result['success']) {
                $sent++;
            } else {
                $skipped++;
            }
        }
        
        $results[] = [
            'type' => 'overdue_billing_items',
            'sent' => $sent,
            'skipped' => $skipped,
            'message' => "Sent {$sent} alerts, skipped {$skipped}"
        ];
    }
    
    if ($action === 'send_invoice_alerts') {
        // Get all overdue invoices
        $overdue = $conn->prepare("
            SELECT 
                i.id as invoice_id,
                i.lease_id,
                i.due_date,
                i.outstanding_amount as amount,
                DATEDIFF(CURDATE(), i.due_date) as days_overdue
            FROM re_invoices i
            JOIN re_leases l ON l.id = i.lease_id
            WHERE i.company_id = ?
            AND l.status <> 'draft'
            AND i.status IN ('sent', 'partial')
            AND i.due_date < CURDATE()
        ");
        $overdue->execute([$currentCompanyId]);
        $overdueItems = $overdue->fetchAll(PDO::FETCH_ASSOC);
        
        $sent = 0;
        $skipped = 0;
        foreach ($overdueItems as $item) {
            $result = send_overdue_rent_alert(
                $conn,
                $currentCompanyId,
                $item['lease_id'],
                null,
                null,
                $item['invoice_id'],
                $item['due_date'],
                (float)$item['amount'],
                (int)$item['days_overdue']
            );
            
            if ($result['success']) {
                $sent++;
            } else {
                $skipped++;
            }
        }
        
        $results[] = [
            'type' => 'overdue_invoices',
            'sent' => $sent,
            'skipped' => $skipped,
            'message' => "Sent {$sent} alerts, skipped {$skipped}"
        ];
    }
}

// Get statistics
// The installment count cannot be a COUNT(*): a row that is already fully collected still
// reads status = 'pending', so it has to go through the same resolver the send path uses,
// otherwise this header disagrees with the number of alerts actually sent.
$statInstallments = $conn->prepare("
    SELECT li.id, li.lease_id, li.amount
    FROM re_lease_installments li
    JOIN re_leases l ON l.id = li.lease_id
    WHERE l.company_id = ?
      AND l.status <> 'draft'
      AND li.status = 'pending'
      AND li.installment_date < CURDATE()
      AND NOT EXISTS (
          SELECT 1
          FROM re_post_dated_cheques c
          WHERE c.installment_id = li.id
            AND c.lease_id = li.lease_id
            AND c.status IN ('cleared', 'returned', 'cancelled')
      )
");
$statInstallments->execute([$currentCompanyId]);
$statInstallments = re_apply_installment_outstanding($conn, (int)$currentCompanyId, $statInstallments->fetchAll(PDO::FETCH_ASSOC));

$stats = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM re_billing_items bi
         JOIN re_leases l ON l.id = bi.lease_id
         WHERE bi.company_id = ?
           AND l.status <> 'draft'
           AND bi.is_paid = 0
           AND COALESCE(bi.is_waived, 0) = 0
           AND bi.status != 'waived'
           AND bi.due_date < CURDATE()) as overdue_billing_items,
        (SELECT COUNT(*) FROM re_invoices i
         JOIN re_leases l ON l.id = i.lease_id
         WHERE i.company_id = ?
           AND l.status <> 'draft'
           AND i.status IN ('sent', 'partial')
           AND i.due_date < CURDATE()) as overdue_invoices
");
$stats->execute([$currentCompanyId, $currentCompanyId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);
$stats['overdue_installments'] = count($statInstallments);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Send Alerts';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-envelope"></i> Send Collections Alerts</h1>
            <a href="collections.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Collections
            </a>
        </div>

        <?php if (!empty($results)): ?>
            <?php foreach ($results as $result): ?>
                <div class="alert alert-<?= $result['sent'] > 0 ? 'success' : 'info' ?>">
                    <strong><?= ucfirst(str_replace('_', ' ', $result['type'])) ?>:</strong> <?= h($result['message']) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> 
            <strong>Note:</strong> This will send alerts for all overdue items based on your alert configuration. 
            Alerts will only be sent if they are enabled and meet the threshold criteria.
        </div>

        <div class="row">
            <!-- Overdue Installments -->
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-calendar-x"></i> Overdue Installments</h5>
                    </div>
                    <div class="card-body">
                        <p class="h3 text-danger"><?= $stats['overdue_installments'] ?></p>
                        <p class="text-muted">Rent installments that are overdue</p>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_overdue_alerts">
                            <button type="submit" class="btn btn-danger w-100" 
                                    onclick="return confirm('Send alerts for all overdue installments?')">
                                <i class="bi bi-envelope"></i> Send Alerts
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Overdue Billing Items -->
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-exclamation-circle"></i> Overdue Billing Items</h5>
                    </div>
                    <div class="card-body">
                        <p class="h3 text-warning"><?= $stats['overdue_billing_items'] ?></p>
                        <p class="text-muted">Service charges, penalties, etc. that are overdue</p>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_billing_items_alerts">
                            <button type="submit" class="btn btn-warning w-100" 
                                    onclick="return confirm('Send alerts for all overdue billing items?')">
                                <i class="bi bi-envelope"></i> Send Alerts
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Overdue Invoices -->
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-file-text"></i> Overdue Invoices</h5>
                    </div>
                    <div class="card-body">
                        <p class="h3 text-danger"><?= $stats['overdue_invoices'] ?></p>
                        <p class="text-muted">Invoices that are overdue</p>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send_invoice_alerts">
                            <button type="submit" class="btn btn-danger w-100" 
                                    onclick="return confirm('Send alerts for all overdue invoices?')">
                                <i class="bi bi-envelope"></i> Send Alerts
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> How It Works</h5>
            </div>
            <div class="card-body">
                <ul>
                    <li>Alerts are sent based on your configuration in <a href="collections_alerts_config.php">Alert Settings</a></li>
                    <li>Only enabled alert types will be sent</li>
                    <li>Alerts respect the "days overdue threshold" setting</li>
                    <li>Alerts respect the "alert frequency" setting (once, daily, weekly)</li>
                    <li>Recipients are configured in Alert Settings or Real Estate Email Notifications</li>
                    <li>All alerts are logged for tracking</li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

