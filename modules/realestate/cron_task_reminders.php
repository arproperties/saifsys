<?php
/**
 * Real Estate - Task Reminders
 * Run via cron every 5-15 minutes to send due reminders.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/re_email_helper.php';

$now = new DateTimeImmutable('now');

// Find tasks with reminder_at in the past and reminder_method set
$stmt = $conn->prepare("
    SELECT t.*, e.email as assigned_email, e.full_name as assigned_name, c.category_name
    FROM re_tasks t
    LEFT JOIN employees e ON e.id = t.assigned_to
    LEFT JOIN re_task_categories c ON c.id = t.category_id
    WHERE t.reminder_at IS NOT NULL
      AND t.reminder_at <= NOW()
      AND t.status IN ('pending','in_progress','on_hold')
      AND t.reminder_method IS NOT NULL
");
$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($tasks as $task) {
    if (empty($task['assigned_email'])) {
        continue;
    }

    // Basic email reminder using existing helper
    try {
        send_task_reminder_email($conn, $task);
    } catch (Exception $e) {
        // swallow errors in cron
    }

    // Clear reminder so it's sent only once
    $u = $conn->prepare("UPDATE re_tasks SET reminder_at = NULL, reminder_method = NULL WHERE id = ? AND company_id = ?");
    $u->execute([$task['id'], $task['company_id']]);
}

echo "Reminders processed at " . $now->format('Y-m-d H:i:s') . PHP_EOL;

