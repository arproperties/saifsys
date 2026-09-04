<?php
// cron/document_reminders.php
// Finds employee documents matching active reminder schedules, sends emails, and logs each send.
// Can be called from CLI (cron) or from UI "Run now".

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../hr/includes/hr_employee_lifecycle.php';

function when_phrase_from_schedule(string $direction, int $days): string {
    if ($direction === 'before') {
        if ($days === 0) return 'expire today';
        if ($days === 1) return 'expire in 1 day';
        return "expire in {$days} days";
    }
    // after
    if ($days === 0) return 'expired today';
    if ($days === 1) return 'expired 1 day ago';
    return "expired {$days} days ago";
}

/**
 * Main entry. If $dryRun is true -> does all lookups & logs decisions but DOES NOT send mails (still inserts log with "skipped").
 * $runKey groups a run; default is date('Y-m-d').
 * Returns: ['found'=>N, 'eligible'=>N, 'sent'=>N, 'skipped'=>N, 'failed'=>N]
 */
function run_document_reminders(PDO $conn, bool $dryRun=false, ?string $runKey=null): array {
    $runKey = $runKey ?: date('Y-m-d');

    // 1) Load email sender settings
    $settings = $conn->query("SELECT * FROM app_email_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $sendingEnabled = $settings && (int)$settings['is_enabled'] === 1;

    // 2) Load active recipients & schedules
    $recipients = $conn->query("SELECT * FROM app_reminder_recipients WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $schedules  = $conn->query("SELECT * FROM app_reminder_schedules WHERE is_active=1 ORDER BY direction, days_offset")->fetchAll(PDO::FETCH_ASSOC);

    // 3) Load template (subject/body)
    $tpl = $conn->prepare("SELECT subject, body_html FROM app_email_templates WHERE code='doc_expiry_reminder' LIMIT 1");
    $tpl->execute();
    $tplRow = $tpl->fetch(PDO::FETCH_ASSOC) ?: [
      'subject'   => '[HR] Document {doc_type} for {employee_name} {when_phrase}',
      'body_html' => '<p>Hello {recipient_name_or_team},</p>
                      <p>The following employee document will {when_phrase}:</p>
                      <ul>
                        <li><strong>Employee:</strong> {employee_name} ({employee_code})</li>
                        <li><strong>Document:</strong> {doc_type}</li>
                        <li><strong>Expires:</strong> {expiry_date}</li>
                      </ul>
                      <p>Please arrange renewal.</p>
                      <p>Thanks,<br>{company_name}</p>',
    ];

    $companyName = $settings['from_name'] ?? 'HR';

    // If nothing to do, exit early
    if (!$schedules || !$recipients) {
        return ['found'=>0, 'eligible'=>0, 'sent'=>0, 'skipped'=>0, 'failed'=>0];
    }

    // 4) For each schedule, compute the target date and fetch docs that match
    $today = new DateTimeImmutable('today');
    $found=0; $eligible=0; $sent=0; $skipped=0; $failed=0;
    $currentStatuses = hr_employee_current_statuses();
    $currentStatusSql = hr_employee_status_in_sql($currentStatuses);

    foreach ($schedules as $sc) {
        $dir  = $sc['direction']; // 'before' or 'after'
        $days = (int)$sc['days_offset'];

        // Target date calculation:
        // "before" N days -> expiry_date = today + N
        // "after"  N days -> expiry_date = today - N
        $target = ($dir === 'before')
            ? $today->modify("+{$days} days")
            : $today->modify("-{$days} days");
        $targetDate = $target->format('Y-m-d');

        // Fetch documents expiring exactly on that target date for current employees.
        $sql = "
          SELECT ed.id AS document_id, ed.employee_id, ed.doc_type, ed.expires_at,
                 e.full_name, e.employee_code, e.email,
                 COALESCE(c.name, '') AS company_name
          FROM employee_documents ed
          JOIN employees e ON e.id = ed.employee_id
          LEFT JOIN companies c ON c.id = e.company_id
          WHERE ed.expires_at = ?
            AND e.status IN ({$currentStatusSql})
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$targetDate], $currentStatuses));
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($docs as $d) {
            $found++;
            $whenPhrase = when_phrase_from_schedule($dir, $days);

            foreach ($recipients as $rcp) {
                $to = trim($rcp['email']);
                if ($to === '') continue;

                // De-dup by (run_key, doc, schedule, recipient)
                $logIns = $conn->prepare("
                  INSERT IGNORE INTO app_reminder_logs
                  (run_key, document_id, schedule_id, recipient_email, subject, status)
                  VALUES (?, ?, ?, ?, ?, ?)
                ");

                // Prepare subject/body with placeholders
                $vars = [
                  'recipient_name_or_team' => $rcp['name'] ?: 'Team',
                  'employee_name'          => $d['full_name'] ?: $d['employee_code'],
                  'employee_code'          => $d['employee_code'] ?: '',
                  'doc_type'               => $d['doc_type'] ?: 'Document',
                  'expiry_date'            => $d['expires_at'] ?: '',
                  'when_phrase'            => $whenPhrase,
                  'employee_company'       => $d['company_name'] ?: '',
                  'company_name'           => $companyName,
                ];
                $subject = render_template($tplRow['subject'], $vars);
                $body    = render_template($tplRow['body_html'], $vars);

                // Try to reserve the log row first; if it fails (duplicate) skip sending
                $initialStatus = ($dryRun || !$sendingEnabled) ? 'skipped' : 'sent';
                $logIns->execute([$runKey, (int)$d['document_id'], (int)$sc['id'], $to, $subject, $initialStatus]);
                $affected  = $logIns->rowCount(); // 1 if inserted, 0 if duplicate

                if ($affected === 0) {
                    // Already sent for this run; skip
                    continue;
                }

                $eligible++;

                if ($dryRun || !$sendingEnabled) {
                    // already logged as 'skipped'
                    $skipped++;
                    continue;
                }

                // Actually send
                $mailResult = send_smtp_mail($settings, $to, $subject, $body);

                if (!empty($mailResult['ok'])) {
                    $sent++;
                } else {
                    $failed++;
                    $errorText = substr((string)($mailResult['error'] ?? 'Mailer returned false'), 0, 500);
                    // update log status to failed
                    $upd = $conn->prepare("
                      UPDATE app_reminder_logs
                      SET status='failed', error_text=?
                      WHERE run_key=? AND document_id=? AND schedule_id=? AND recipient_email=? LIMIT 1
                    ");
                    $upd->execute([$errorText, $runKey, (int)$d['document_id'], (int)$sc['id'], $to]);
                }
            }
        }
    }

    return ['found'=>$found, 'eligible'=>$eligible, 'sent'=>$sent, 'skipped'=>$skipped, 'failed'=>$failed];
}

// If invoked directly from CLI, run once (real send). Including this file should only expose the runner function.
if (php_sapi_name()==='cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $res = run_document_reminders($conn, false, null);
    echo "Reminders: found={$res['found']} eligible={$res['eligible']} sent={$res['sent']} skipped={$res['skipped']} failed={$res['failed']}\n";
}
