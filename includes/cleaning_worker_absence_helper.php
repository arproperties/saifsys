<?php
/**
 * Sync worker availability absence blocks to HR attendance.
 */

function cleaning_worker_absence_column_exists(PDO $conn, string $column): bool {
    static $cache = [];
    $key = 'worker_unavailability.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'worker_unavailability'
          AND COLUMN_NAME = ?
    ");
    $st->execute([$column]);
    return $cache[$key] = ((int)$st->fetchColumn() > 0);
}

function cleaning_worker_absence_ensure_schema(PDO $conn): void {
    if (!cleaning_worker_absence_column_exists($conn, 'absence_type')) {
        $conn->exec("ALTER TABLE worker_unavailability ADD COLUMN absence_type ENUM('time_off','absent') NOT NULL DEFAULT 'time_off' AFTER reason");
    }
    if (!cleaning_worker_absence_column_exists($conn, 'attendance_id')) {
        $conn->exec("ALTER TABLE worker_unavailability ADD COLUMN attendance_id INT(11) DEFAULT NULL AFTER absence_type");
    }
}

function cleaning_worker_absence_resolve_employee(PDO $conn, int $workerId): ?array {
    $st = $conn->prepare("
        SELECT e.id, e.company_id, e.full_name, e.employee_code
        FROM workers w
        JOIN employees e ON (
            (e.employee_code IS NOT NULL AND e.employee_code <> '' AND e.employee_code = w.emp_num)
            OR (e.nickname IS NOT NULL AND e.nickname <> '' AND e.nickname = w.nickname)
            OR (e.full_name IS NOT NULL AND e.full_name <> '' AND e.full_name = w.worker_name)
        )
        WHERE w.id = ?
        ORDER BY e.status = 'active' DESC, e.id ASC
        LIMIT 1
    ");
    $st->execute([$workerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cleaning_worker_absence_sync_attendance(PDO $conn, int $workerId, string $date, string $reason, ?int $userId = null): int {
    $employee = cleaning_worker_absence_resolve_employee($conn, $workerId);
    if (!$employee) {
        throw new RuntimeException('No linked HR employee found for this worker. Check employee_code / worker emp_num mapping.');
    }

    $employeeId = (int)$employee['id'];
    $companyId = (int)($employee['company_id'] ?? 1);
    $note = 'Worker Availability absent: ' . trim($reason ?: 'Marked absent');

    $existing = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND work_date = ? LIMIT 1");
    $existing->execute([$employeeId, $date]);
    $attendanceId = (int)($existing->fetchColumn() ?: 0);

    if ($attendanceId > 0) {
        $upd = $conn->prepare("
            UPDATE attendance
            SET company_id = ?, check_in = NULL, check_out = NULL, hours = NULL,
                status = 'absent', source = 'admin', notes = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$companyId, $note, $userId, $attendanceId]);
        return $attendanceId;
    }

    $ins = $conn->prepare("
        INSERT INTO attendance
            (company_id, employee_id, work_date, check_in, check_out, hours, status, source, notes, created_by, updated_by)
        VALUES (?, ?, ?, NULL, NULL, NULL, 'absent', 'admin', ?, ?, ?)
    ");
    $ins->execute([$companyId, $employeeId, $date, $note, $userId, $userId]);
    return (int)$conn->lastInsertId();
}

function cleaning_worker_absence_delete_synced_attendance(PDO $conn, array $unavailability): void {
    if (($unavailability['absence_type'] ?? '') !== 'absent') return;
    $attendanceId = (int)($unavailability['attendance_id'] ?? 0);
    if ($attendanceId <= 0) return;

    $st = $conn->prepare("SELECT id, notes, source, status FROM attendance WHERE id = ? LIMIT 1");
    $st->execute([$attendanceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    $notes = (string)($row['notes'] ?? '');
    if (strpos($notes, 'Worker Availability absent:') === 0 && ($row['status'] ?? '') === 'absent') {
        $conn->prepare("DELETE FROM attendance WHERE id = ?")->execute([$attendanceId]);
    }
}
