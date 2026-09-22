<?php
/**
 * Real Estate — Management digest (weekly)
 *
 * Sends one HTML email per active company to digest recipients (management + owner emails).
 * Runs on Mondays by default; override with FORCE_WEEKLY_DIGEST=1 or ?force_weekly=1
 *
 * CLI:  php modules/realestate/management_digest_cron.php
 * Web:  .../management_digest_cron.php?run_cron=1
 * Force: .../management_digest_cron.php?run_cron=1&force_weekly=1
 *
 * Optional: CRON_BASE_URL or LEASE_REMINDER_BASE_URL for links when using CLI.
 */

if (php_sapi_name() === 'cli') {
    chdir(__DIR__ . '/../..');
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/re_cron_helpers.php';
re_cron_guard();

require_once __DIR__ . '/includes/re_email_helper.php';
require_once __DIR__ . '/includes/installment_outstanding.php';

/** @var PDO $conn */

$forceWeekly = (getenv('FORCE_WEEKLY_DIGEST') === '1')
    || (isset($_GET['force_weekly']) && (string)$_GET['force_weekly'] === '1');
if (!$forceWeekly && (int)date('N') !== 1) {
    $msg = "management_digest_cron: skipped (not Monday). Set FORCE_WEEKLY_DIGEST=1 or use force_weekly=1 to run now.\n";
    echo php_sapi_name() === 'cli' ? $msg : htmlspecialchars($msg);
    exit(0);
}

$settings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $msg = "management_digest_cron: SMTP not configured (app_email_settings id=1).\n";
    echo php_sapi_name() === 'cli' ? $msg : htmlspecialchars($msg);
    exit(0);
}

$base = rtrim(get_base_url(), '/');
$reBase = $base . '/modules/realestate';

$companies = re_cron_active_company_ids($conn);
$sent = 0;

foreach ($companies as $companyId) {
    $recipients = re_cron_digest_recipients($conn, $companyId);
    if (!$recipients) {
        continue;
    }

    $leases90 = $conn->prepare("
        SELECT COUNT(*) FROM re_leases
        WHERE company_id = ?
          AND status = 'active'
          AND end_date IS NOT NULL
          AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ");
    $leases90->execute([$companyId]);
    $nLeases90 = (int)$leases90->fetchColumn();

    // Not an aggregate: a fully collected installment still reads status = 'pending' at
    // face value in Invoice Mode, so COUNT/SUM here would overstate arrears to management.
    // Resolve each row's real outstanding balance, then total what is left.
    $qInst = $conn->prepare("
        SELECT li.id, li.lease_id, li.amount
        FROM re_lease_installments li
        JOIN re_leases l ON l.id = li.lease_id
        WHERE l.company_id = ?
          AND l.status <> 'draft'
          AND NOT EXISTS (SELECT 1 FROM re_units xu JOIN re_buildings xb ON xb.id = xu.building_id WHERE xu.id = l.unit_id AND xb.is_active = 0)
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
    $qInst->execute([$companyId]);
    $rowsInst = re_apply_installment_outstanding($conn, (int)$companyId, $qInst->fetchAll(PDO::FETCH_ASSOC));
    $nOverdueInst = count($rowsInst);
    $sumOverdueInst = 0.0;
    foreach ($rowsInst as $rowInst) {
        $sumOverdueInst += (float)$rowInst['outstanding_balance'];
    }

    $qBill = $conn->prepare("
        SELECT COUNT(*), COALESCE(SUM(bi.total_amount), 0)
        FROM re_billing_items bi
        JOIN re_leases l ON l.id = bi.lease_id
        WHERE bi.company_id = ?
          AND l.status <> 'draft'
          AND NOT EXISTS (SELECT 1 FROM re_units xu JOIN re_buildings xb ON xb.id = xu.building_id WHERE xu.id = l.unit_id AND xb.is_active = 0)
          AND bi.is_paid = 0
          AND COALESCE(bi.is_waived, 0) = 0
          AND bi.status != 'waived'
          AND bi.due_date < CURDATE()
    ");
    $qBill->execute([$companyId]);
    $rowBill = $qBill->fetch(PDO::FETCH_NUM);
    $nOverdueBill = (int)($rowBill[0] ?? 0);
    $sumOverdueBill = (float)($rowBill[1] ?? 0);

    $qInv = $conn->prepare("
        SELECT COUNT(*), COALESCE(SUM(i.outstanding_amount), 0)
        FROM re_invoices i
        JOIN re_leases l ON l.id = i.lease_id
        WHERE i.company_id = ?
          AND l.status <> 'draft'
          AND NOT EXISTS (SELECT 1 FROM re_units xu JOIN re_buildings xb ON xb.id = xu.building_id WHERE xu.id = l.unit_id AND xb.is_active = 0)
          AND i.status IN ('sent', 'partial')
          AND i.due_date < CURDATE()
    ");
    $qInv->execute([$companyId]);
    $rowInv = $qInv->fetch(PDO::FETCH_NUM);
    $nOverdueInv = (int)($rowInv[0] ?? 0);
    $sumOverdueInv = (float)($rowInv[1] ?? 0);

    $qChq = $conn->prepare("
        SELECT COUNT(*) FROM re_post_dated_cheques
        WHERE company_id = ?
          AND status = 'pending'
          AND cheque_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    $qChq->execute([$companyId]);
    $nChq7 = (int)$qChq->fetchColumn();

    $qMaint = $conn->prepare("
        SELECT COUNT(*) FROM re_maintenance_requests
        WHERE company_id = ?
          AND status IN ('pending', 'in_progress')
    ");
    $qMaint->execute([$companyId]);
    $nMaint = (int)$qMaint->fetchColumn();

    $nPortal = 0;
    try {
        $qPu = $conn->prepare("
            SELECT COUNT(*) FROM tenant_portal_users
            WHERE company_id = ? AND status = 'pending_approval'
        ");
        $qPu->execute([$companyId]);
        $nPortal = (int)$qPu->fetchColumn();
    } catch (Throwable $e) {
        // table may not exist on older DBs
    }

    $fmtMoney = static function (float $v): string {
        return number_format($v, 2) . ' AED';
    };

    $weekLabel = date('Y-m-d') . ' (week ' . date('W') . ')';
    $subject = '[Real Estate] Weekly management digest — ' . $weekLabel . ' (company #' . $companyId . ')';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;font-size:14px">'
        . '<h2>Weekly management digest</h2>'
        . '<p>Snapshot for your portfolio. Company ID: ' . (int)$companyId . '</p>'
        . '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse:collapse;max-width:720px">'
        . '<tbody>'
        . '<tr><td><strong>Active leases ending in 90 days</strong></td><td>' . $nLeases90 . '</td>'
        . '<td><a href="' . htmlspecialchars($reBase . '/leases.php') . '">Leases</a></td></tr>'
        . '<tr><td><strong>Overdue rent installments</strong></td><td>' . $nOverdueInst . ' &mdash; ' . htmlspecialchars($fmtMoney($sumOverdueInst)) . '</td>'
        . '<td><a href="' . htmlspecialchars($reBase . '/collections.php') . '">Collections</a></td></tr>'
        . '<tr><td><strong>Overdue billing items</strong></td><td>' . $nOverdueBill . ' &mdash; ' . htmlspecialchars($fmtMoney($sumOverdueBill)) . '</td>'
        . '<td><a href="' . htmlspecialchars($reBase . '/collections.php') . '">Collections</a></td></tr>'
        . '<tr><td><strong>Overdue invoices</strong></td><td>' . $nOverdueInv . ' &mdash; ' . htmlspecialchars($fmtMoney($sumOverdueInv)) . '</td>'
        . '<td><a href="' . htmlspecialchars($reBase . '/collections.php') . '">Collections</a></td></tr>'
        . '<tr><td><strong>Pending cheques due within 7 days</strong></td><td>' . $nChq7 . '</td>'
        . '<td><a href="' . htmlspecialchars($reBase . '/billing_cheques.php') . '">Cheques</a></td></tr>'
        . '<tr><td><strong>Maintenance (pending / in progress)</strong></td><td>' . $nMaint . '</td>'
        . '<td><a href="' . htmlspecialchars($reBase . '/maintenance.php') . '">Maintenance</a></td></tr>'
        . '<tr><td><strong>Tenant portal pending approvals</strong></td><td>' . $nPortal . '</td>'
        . '<td><a href="' . htmlspecialchars($reBase . '/tenant_portal_approvals.php') . '">Portal</a></td></tr>'
        . '</tbody></table>'
        . '<p style="color:#666;font-size:12px;margin-top:16px">Automated weekly digest from HZ Real Estate.</p>'
        . '</body></html>';

    $okAny = false;
    foreach ($recipients as $email) {
        $sendResult = send_smtp_mail($settings, $email, $subject, $html);
        if (!empty($sendResult['ok'])) {
            $okAny = true;
        }
    }
    if ($okAny) {
        $sent++;
    }
}

$out = "Management digest cron — " . date('c') . "\n"
    . "Companies emailed: {$sent} / " . count($companies) . "\n";

if (php_sapi_name() === 'cli') {
    echo $out;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
}
