<?php
/**
 * Real Estate — Post-dated cheques: upcoming deposit / overdue pending / clearance follow-up (daily)
 *
 * Pending: reminders when cheque_date is in 7, 3, 1, 0 days; overdue pending: once per ISO week per cheque
 * (requires re_cheque_reminder_log).
 * Deposited (not cleared): reminders 3, 7, and 14 days after deposited_date.
 *
 * Migration: migrations/add_re_cheque_reminder_log.sql
 *
 * CLI:  php modules/realestate/cheque_reminder_cron.php
 * Web:  .../cheque_reminder_cron.php?run_cron=1
 */

if (php_sapi_name() === 'cli') {
    chdir(__DIR__ . '/../..');
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/re_cron_helpers.php';
re_cron_guard();

require_once __DIR__ . '/includes/re_email_helper.php';

/** @var PDO $conn */

/**
 * Reminder keys for pending cheques (by days until cheque_date).
 */
function re_cron_pdc_pending_reminder_key(int $daysUntil): ?string {
    if ($daysUntil === 7) {
        return 'd7';
    }
    if ($daysUntil === 3) {
        return 'd3';
    }
    if ($daysUntil === 1) {
        return 'd1';
    }
    if ($daysUntil === 0) {
        return 'due';
    }
    if ($daysUntil < 0) {
        return sprintf('overdue_%s_W%02d', date('o'), (int)date('W'));
    }
    return null;
}

$settings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $msg = "cheque_reminder_cron: SMTP not configured (app_email_settings id=1).\n";
    echo php_sapi_name() === 'cli' ? $msg : htmlspecialchars($msg);
    exit(0);
}

$logTableOk = true;
try {
    $conn->query("SELECT 1 FROM re_cheque_reminder_log LIMIT 1");
} catch (Throwable $e) {
    $logTableOk = false;
}

$companies = re_cron_active_company_ids($conn);
$sentEmails = 0;
$skipped = 0;

$base = rtrim(get_base_url(), '/');

foreach ($companies as $companyId) {
    $recipients = re_cron_digest_recipients($conn, $companyId);
    if (!$recipients) {
        continue;
    }

    $dueRows = [];

    $stmt = $conn->prepare("
        SELECT c.id, c.cheque_number, c.cheque_date, c.cheque_amount, c.bank_name, c.status,
               l.lease_number, u.unit_number, b.name AS building_name,
               DATEDIFF(c.cheque_date, CURDATE()) AS days_until
        FROM re_post_dated_cheques c
        JOIN re_leases l ON l.id = c.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE c.company_id = ?
          AND c.status = 'pending'
    ");
    $stmt->execute([$companyId]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending as $row) {
        $daysUntil = (int)$row['days_until'];
        $key = re_cron_pdc_pending_reminder_key($daysUntil);
        if ($key === null) {
            continue;
        }
        if ($logTableOk) {
            $chk = $conn->prepare("SELECT 1 FROM re_cheque_reminder_log WHERE cheque_id = ? AND reminder_key = ? LIMIT 1");
            $chk->execute([(int)$row['id'], $key]);
            if ($chk->fetchColumn()) {
                $skipped++;
                continue;
            }
        }
        $row['_reminder_key'] = $key;
        $row['_kind'] = 'pending';
        $dueRows[] = $row;
    }

    $stmtDep = $conn->prepare("
        SELECT c.id, c.cheque_number, c.cheque_date, c.cheque_amount, c.bank_name, c.status,
               c.deposited_date,
               l.lease_number, u.unit_number, b.name AS building_name,
               DATEDIFF(CURDATE(), c.deposited_date) AS days_since_deposit
        FROM re_post_dated_cheques c
        JOIN re_leases l ON l.id = c.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE c.company_id = ?
          AND c.status = 'deposited'
          AND (c.cleared_date IS NULL OR c.cleared_date = '0000-00-00')
          AND c.deposited_date IS NOT NULL
          AND c.deposited_date <> '0000-00-00'
          AND DATEDIFF(CURDATE(), c.deposited_date) IN (3, 7, 14)
    ");
    $stmtDep->execute([$companyId]);
    $deposited = $stmtDep->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deposited as $row) {
        $dsd = (int)$row['days_since_deposit'];
        $key = 'dep_d' . $dsd;
        if ($logTableOk) {
            $chk = $conn->prepare("SELECT 1 FROM re_cheque_reminder_log WHERE cheque_id = ? AND reminder_key = ? LIMIT 1");
            $chk->execute([(int)$row['id'], $key]);
            if ($chk->fetchColumn()) {
                $skipped++;
                continue;
            }
        }
        $row['_reminder_key'] = $key;
        $row['_kind'] = 'deposited';
        $dueRows[] = $row;
    }

    if (!$dueRows) {
        continue;
    }

    $rowsHtml = '';
    foreach ($dueRows as $r) {
        $amt = number_format((float)$r['cheque_amount'], 2);
        $kind = ($r['_kind'] ?? '') === 'deposited' ? 'Clearance' : 'Deposit due';
        if (($r['_kind'] ?? '') === 'deposited') {
            $dsd = (int)($r['days_since_deposit'] ?? 0);
            $lbl = $dsd . ' day(s) since deposit (awaiting clearance)';
        } else {
            $du = (int)$r['days_until'];
            $lbl = $du > 0 ? "in {$du} day(s)" : ($du === 0 ? 'today' : abs($du) . ' day(s) overdue');
        }
        $rowsHtml .= '<tr>'
            . '<td>' . htmlspecialchars($kind) . '</td>'
            . '<td>' . htmlspecialchars($r['cheque_number']) . '</td>'
            . '<td>' . htmlspecialchars($r['lease_number']) . '</td>'
            . '<td>' . htmlspecialchars($r['building_name'] . ' — ' . $r['unit_number']) . '</td>'
            . '<td>' . htmlspecialchars($r['cheque_date']) . '</td>'
            . '<td>' . htmlspecialchars($lbl) . '</td>'
            . '<td>' . htmlspecialchars($amt) . ' AED</td>'
            . '<td><a href="' . htmlspecialchars($base . '/modules/realestate/billing_cheque_view.php?id=' . (int)$r['id']) . '">View</a></td>'
            . '</tr>';
    }

    $subject = '[Real Estate] Cheque reminders — ' . date('Y-m-d') . ' (company #' . $companyId . ')';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif">'
        . '<h2>Post-dated cheque reminders</h2>'
        . '<p>The following cheques need attention (deposit or bank clearance).</p>'
        . '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse">'
        . '<thead><tr><th>Type</th><th>Cheque #</th><th>Lease</th><th>Location</th><th>Cheque date</th><th>Timing</th><th>Amount</th><th>Link</th></tr></thead>'
        . '<tbody>' . $rowsHtml . '</tbody></table>'
        . '<p style="color:#666;font-size:12px">Automated message from HZ Real Estate.</p>'
        . '</body></html>';

    $okAny = false;
    foreach ($recipients as $email) {
        $sendResult = send_smtp_mail($settings, $email, $subject, $html);
        if (!empty($sendResult['ok'])) {
            $okAny = true;
        }
    }

    if ($okAny && $logTableOk) {
        $ins = $conn->prepare("INSERT INTO re_cheque_reminder_log (company_id, cheque_id, reminder_key) VALUES (?, ?, ?)");
        foreach ($dueRows as $r) {
            try {
                $ins->execute([$companyId, (int)$r['id'], $r['_reminder_key']]);
            } catch (Throwable $e) {
                // ignore duplicate key
            }
        }
        $sentEmails++;
    }
}

$out = "Cheque reminder cron — " . date('c') . "\n"
    . "Company batches emailed: {$sentEmails}, log skips: {$skipped}\n"
    . ($logTableOk ? '' : "WARNING: re_cheque_reminder_log missing — run migrations/add_re_cheque_reminder_log.sql (dedupe disabled).\n");

if (php_sapi_name() === 'cli') {
    echo $out;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo htmlspecialchars($out, ENT_QUOTES, 'UTF-8');
}
