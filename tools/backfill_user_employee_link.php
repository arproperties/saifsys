<?php
/**
 * Backfill script: populate user.employee_id based on existing data
 *
 * Usage: php tools/backfill_user_employee_link.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_connect.php';

if (!$conn || !($conn instanceof PDO)) {
    fwrite(STDERR, "Failed to establish database connection.\n");
    exit(1);
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Backfill user.employee_id start ===\n";

$select = $conn->query("
    SELECT u.id, u.username, u.fullname, u.email, u.contactnumber
    FROM `user` u
    WHERE u.employee_id IS NULL
    ORDER BY u.id
");

$updated = 0;
$skipped = 0;

while ($user = $select->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$user['id'];

    // 1) Match via employees.user_id
    $q = $conn->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
    $q->execute([$uid]);
    $employeeId = $q->fetchColumn();

    // 2) Match via email
    if (!$employeeId && !empty($user['email']) && $user['email'] !== '0') {
        $q = $conn->prepare("SELECT id FROM employees WHERE email = ? LIMIT 1");
        $q->execute([$user['email']]);
        $employeeId = $q->fetchColumn() ?: null;
    }

    // 3) Match via contact number / phone
    if (!$employeeId && !empty($user['contactnumber']) && $user['contactnumber'] !== '0') {
        $q = $conn->prepare("
            SELECT id
            FROM employees
            WHERE phone = ?
               OR REPLACE(REPLACE(phone, ' ', ''), '-', '') = REPLACE(REPLACE(?, ' ', ''), '-', '')
            LIMIT 1
        ");
        $q->execute([$user['contactnumber'], $user['contactnumber']]);
        $employeeId = $q->fetchColumn() ?: null;
    }

    if ($employeeId) {
        $employeeId = (int)$employeeId;

        $conn->prepare("UPDATE `user` SET employee_id = ? WHERE id = ?")
             ->execute([$employeeId, $uid]);

        // Ensure employees.user_id is also linked
        $conn->prepare("UPDATE employees SET user_id = ? WHERE id = ? AND (user_id IS NULL OR user_id = 0)")
             ->execute([$uid, $employeeId]);

        echo sprintf("Linked user #%d (%s) -> employee #%d\n", $uid, $user['username'], $employeeId);
        $updated++;
    } else {
        echo sprintf("Skipped user #%d (%s) - no matching employee found\n", $uid, $user['username']);
        $skipped++;
    }
}

echo "=== Backfill complete: {$updated} linked, {$skipped} skipped ===\n";

