<?php
/**
 * Real Estate — Collections: overdue rent installments, billing items, invoices (daily)
 *
 * Uses the same logic as collections_send_alerts.php (send_overdue_rent_alert).
 * Respects re_collections_alerts_config (enabled, threshold, frequency) per company.
 *
 * CLI:  php modules/realestate/collections_alert_cron.php
 * Web:  .../collections_alert_cron.php?run_cron=1
 *
 * Optional: CRON_BASE_URL=https://your-domain/sys  (for links inside emails when run from CLI)
 */

if (php_sapi_name() === 'cli') {
    chdir(__DIR__ . '/../..');
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/re_cron_helpers.php';
re_cron_guard();

require_once __DIR__ . '/includes/collections_helper.php';
require_once __DIR__ . '/../../includes/tenant_notifications.php';

/** @var PDO $conn */

$companies = re_cron_active_company_ids($conn);
$totals = ['installments' => ['sent' => 0, 'skipped' => 0], 'billing' => ['sent' => 0, 'skipped' => 0], 'invoices' => ['sent' => 0, 'skipped' => 0], 'tenant_due_notifications' => 0];

foreach ($companies as $currentCompanyId) {
    // Tenant in-app "payment due / overdue" notifications (deduped per installment per day)
    $totals['tenant_due_notifications'] += tenant_notifications_generate_due_reminders($conn, (int)$currentCompanyId, 7);

    // --- Overdue installments ---
    $overdue = $conn->prepare("
        SELECT li.id AS installment_id, li.lease_id, li.installment_date AS due_date, li.amount,
               DATEDIFF(CURDATE(), li.installment_date) AS days_overdue
        FROM re_lease_installments li
        JOIN re_leases l ON l.id = li.lease_id
        WHERE l.company_id = ?
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
    foreach ($overdue->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $r = send_overdue_rent_alert(
            $conn,
            $currentCompanyId,
            (int)$item['lease_id'],
            (int)$item['installment_id'],
            null,
            null,
            $item['due_date'],
            (float)$item['amount'],
            (int)$item['days_overdue']
        );
        if (!empty($r['success'])) {
            $totals['installments']['sent']++;
        } else {
            $totals['installments']['skipped']++;
        }
    }

    // --- Overdue billing items ---
    $overdue = $conn->prepare("
        SELECT bi.id AS billing_item_id, bi.lease_id, bi.due_date, bi.total_amount AS amount,
               DATEDIFF(CURDATE(), bi.due_date) AS days_overdue
        FROM re_billing_items bi
        WHERE bi.company_id = ?
          AND bi.is_paid = 0
          AND COALESCE(bi.is_waived, 0) = 0
          AND bi.status != 'waived'
          AND bi.due_date < CURDATE()
    ");
    $overdue->execute([$currentCompanyId]);
    foreach ($overdue->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $r = send_overdue_rent_alert(
            $conn,
            $currentCompanyId,
            (int)$item['lease_id'],
            null,
            (int)$item['billing_item_id'],
            null,
            $item['due_date'],
            (float)$item['amount'],
            (int)$item['days_overdue']
        );
        if (!empty($r['success'])) {
            $totals['billing']['sent']++;
        } else {
            $totals['billing']['skipped']++;
        }
    }

    // --- Overdue invoices ---
    $overdue = $conn->prepare("
        SELECT i.id AS invoice_id, i.lease_id, i.due_date, i.outstanding_amount AS amount,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue
        FROM re_invoices i
        WHERE i.company_id = ?
          AND i.status IN ('sent', 'partial')
          AND i.due_date < CURDATE()
    ");
    $overdue->execute([$currentCompanyId]);
    foreach ($overdue->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $r = send_overdue_rent_alert(
            $conn,
            $currentCompanyId,
            (int)$item['lease_id'],
            null,
            null,
            (int)$item['invoice_id'],
            $item['due_date'],
            (float)$item['amount'],
            (int)$item['days_overdue']
        );
        if (!empty($r['success'])) {
            $totals['invoices']['sent']++;
        } else {
            $totals['invoices']['skipped']++;
        }
    }
}

$lines = [
    'Collections alert cron — ' . date('c'),
    'Installments: sent ' . $totals['installments']['sent'] . ', skipped ' . $totals['installments']['skipped'],
    'Billing items: sent ' . $totals['billing']['sent'] . ', skipped ' . $totals['billing']['skipped'],
    'Invoices: sent ' . $totals['invoices']['sent'] . ', skipped ' . $totals['invoices']['skipped'],
    'Tenant in-app due notifications: ' . $totals['tenant_due_notifications'],
];
$out = implode("\n", $lines) . "\n";

if (php_sapi_name() === 'cli') {
    echo $out;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
}
