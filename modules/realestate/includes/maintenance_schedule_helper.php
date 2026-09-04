<?php
/**
 * Real Estate Module - Maintenance Schedule helper.
 *
 * Shared logic for the Maintenance Schedule board (work orders), team
 * assignment, conflict detection, and PDF/board rendering metadata.
 *
 * This is part of the existing Real Estate -> Maintenance section; it reuses
 * the existing employees, buildings, units and maintenance request data.
 */

if (!function_exists('re_ms_ensure_schema')) {
    /** Create the schedule tables on demand (defensive; migration is the source of truth). */
    function re_ms_ensure_schema(PDO $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `re_maintenance_schedules` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `work_order_number` VARCHAR(40) DEFAULT NULL,
              `maintenance_request_id` INT(11) DEFAULT NULL,
              `building_id` INT(11) DEFAULT NULL,
              `unit_id` INT(11) DEFAULT NULL,
              `task_type` ENUM('ac','electrical','plumbing','civil','painting','carpentry','inspection','general','emergency','other') NOT NULL DEFAULT 'general',
              `title` VARCHAR(255) DEFAULT NULL,
              `description` TEXT DEFAULT NULL,
              `priority` ENUM('low','normal','high','emergency') NOT NULL DEFAULT 'normal',
              `schedule_date` DATE NOT NULL,
              `start_time` TIME NOT NULL DEFAULT '09:00:00',
              `end_time` TIME NOT NULL DEFAULT '10:00:00',
              `status` ENUM('scheduled','in_progress','completed','delayed','cancelled') NOT NULL DEFAULT 'scheduled',
              `notes` TEXT DEFAULT NULL,
              `created_by` INT(11) DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_company_date` (`company_id`, `schedule_date`),
              KEY `idx_request` (`maintenance_request_id`),
              KEY `idx_building` (`building_id`),
              KEY `idx_unit` (`unit_id`),
              KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->exec("
            CREATE TABLE IF NOT EXISTS `re_maintenance_schedule_assignees` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `company_id` INT(11) NOT NULL,
              `schedule_id` INT(11) NOT NULL,
              `employee_id` INT(11) NOT NULL,
              `team_role` ENUM('engineer','supervisor','technician','helper','other') NOT NULL DEFAULT 'technician',
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_schedule_employee` (`schedule_id`, `employee_id`),
              KEY `idx_employee` (`employee_id`),
              KEY `idx_company` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $done = true;
    }
}

if (!function_exists('re_ms_task_types')) {
    function re_ms_task_types(): array
    {
        return [
            'ac' => 'AC',
            'electrical' => 'Electrical',
            'plumbing' => 'Plumbing',
            'civil' => 'Civil',
            'painting' => 'Painting',
            'carpentry' => 'Carpentry',
            'inspection' => 'Inspection',
            'general' => 'General Maintenance',
            'emergency' => 'Emergency',
            'other' => 'Other',
        ];
    }
}

if (!function_exists('re_ms_priorities')) {
    function re_ms_priorities(): array
    {
        return [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'emergency' => 'Emergency',
        ];
    }
}

if (!function_exists('re_ms_statuses')) {
    function re_ms_statuses(): array
    {
        return [
            'scheduled' => 'Scheduled',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'delayed' => 'Delayed',
            'cancelled' => 'Cancelled',
        ];
    }
}

if (!function_exists('re_ms_status_colors')) {
    /** Status -> hex color (board blocks, legend, PDF). */
    function re_ms_status_colors(): array
    {
        return [
            'scheduled' => '#0D6EFD',   // Blue
            'in_progress' => '#FD7E14', // Orange
            'completed' => '#198754',   // Green
            'delayed' => '#DC3545',     // Red
            'cancelled' => '#6C757D',   // Grey
        ];
    }
}

if (!function_exists('re_ms_status_color')) {
    function re_ms_status_color(string $status): string
    {
        $colors = re_ms_status_colors();
        return $colors[$status] ?? '#6C757D';
    }
}

if (!function_exists('re_ms_team_roles')) {
    function re_ms_team_roles(): array
    {
        return [
            'engineer' => 'Engineer',
            'supervisor' => 'Supervisor',
            'technician' => 'Technician',
            'helper' => 'Helper',
            'other' => 'Other',
        ];
    }
}

if (!function_exists('re_ms_classify_position')) {
    /**
     * Classify an employee position title into a maintenance team role.
     * Returns engineer|supervisor|technician|helper or null when not maintenance staff.
     */
    function re_ms_classify_position(?string $title): ?string
    {
        $t = strtolower(trim((string)$title));
        if ($t === '') {
            return null;
        }
        if (strpos($t, 'eng') !== false) {
            return 'engineer';
        }
        if (strpos($t, 'supervis') !== false || strpos($t, 'foreman') !== false || strpos($t, 'camp boss') !== false) {
            return 'supervisor';
        }
        $techKeywords = ['techn', 'plumb', 'electric', 'carpent', 'mason', 'masson', 'mechanic', 'mep', 'painter', 'crane operator', 'excavator operator'];
        foreach ($techKeywords as $kw) {
            if (strpos($t, $kw) !== false) {
                return 'technician';
            }
        }
        if (strpos($t, 'helper') !== false || strpos($t, 'labour') !== false || strpos($t, 'labor') !== false) {
            return 'helper';
        }
        return null;
    }
}

if (!function_exists('re_ms_team_role_order')) {
    function re_ms_team_role_order(string $role): int
    {
        $order = ['engineer' => 0, 'supervisor' => 1, 'technician' => 2, 'helper' => 3, 'other' => 4];
        return $order[$role] ?? 5;
    }
}

if (!function_exists('re_ms_company_names')) {
    /** id => company name map (cached per request). */
    function re_ms_company_names(PDO $conn): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (['companies', 'comp_sa'] as $tbl) {
            try {
                foreach ($conn->query("SELECT id, name FROM $tbl")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $map[(int)$c['id']] = $c['name'];
                }
                if (!empty($map)) break;
            } catch (Throwable $e) {
                // try next candidate table
            }
        }
        return $map;
    }
}

if (!function_exists('re_ms_fetch_employees')) {
    /**
     * Fetch employees with a computed maintenance team_role.
     *
     * Maintenance-classified staff (engineers/supervisors/technicians/helpers) are
     * returned regardless of which company they belong to, because in many setups
     * the technical workforce sits under a shared/operations company rather than the
     * Real Estate company. Non-maintenance staff are limited to $companyId so the
     * picker stays focused.
     *
     * @param bool $maintenanceOnly when true, only maintenance-classified staff are returned.
     * @return array<int,array> rows keyed by employee id
     */
    function re_ms_fetch_employees(PDO $conn, int $companyId, bool $maintenanceOnly = true): array
    {
        // Resilient fetch (capacity columns / status values vary across installs).
        // NOTE: no company filter here — we classify in PHP and decide inclusion below.
        $rows = [];
        $attempts = [
            "SELECT id, full_name, first_name, last_name, position_title, phone, email, status, company_id, daily_cap_hours, weekly_cap_hours
                FROM employees WHERE (status IS NULL OR status = 'active') ORDER BY full_name",
            "SELECT id, full_name, first_name, last_name, position_title, phone, email, status, company_id
                FROM employees WHERE (status IS NULL OR status = 'active') ORDER BY full_name",
            "SELECT id, full_name, first_name, last_name, position_title, phone, email, status, company_id
                FROM employees ORDER BY full_name",
        ];
        foreach ($attempts as $sql) {
            try {
                $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows = [];
                continue;
            }
            if (!empty($rows)) {
                break;
            }
        }

        $companyNames = re_ms_company_names($conn);

        $result = [];
        foreach ($rows as $r) {
            $role = re_ms_classify_position($r['position_title'] ?? '');
            $isMaintenance = ($role !== null);
            $sameCompany = ((int)($r['company_id'] ?? 0) === $companyId);

            if ($maintenanceOnly) {
                // Board left list: maintenance staff from any company.
                if (!$isMaintenance) {
                    continue;
                }
            } else {
                // Modal picker: maintenance staff (any company) + this company's other staff.
                if (!$isMaintenance && !$sameCompany) {
                    continue;
                }
            }

            $r['team_role'] = $role ?? 'other';
            if (!array_key_exists('daily_cap_hours', $r)) { $r['daily_cap_hours'] = null; }
            if (!array_key_exists('weekly_cap_hours', $r)) { $r['weekly_cap_hours'] = null; }
            $r['company_name'] = $companyNames[(int)($r['company_id'] ?? 0)] ?? '';
            $r['is_other_company'] = !$sameCompany;
            $r['display_name'] = trim((string)($r['full_name'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')))) ?: ('Employee #' . $r['id']);
            $result[(int)$r['id']] = $r;
        }
        return $result;
    }
}

if (!function_exists('re_ms_generate_wo_number')) {
    /** Generate a stable work order number once a schedule row exists. */
    function re_ms_generate_wo_number(int $companyId, int $scheduleId): string
    {
        return 'WO-' . $companyId . '-' . str_pad((string)$scheduleId, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('re_ms_fetch_schedules')) {
    /**
     * Fetch schedules in a date range with assignees, building, unit, request.
     *
     * @param array $filters keys: building_id, status, task_type, employee_id
     * @return array list of schedule rows; each has 'assignees' => [ [employee_id, name, team_role], ... ]
     */
    function re_ms_fetch_schedules(PDO $conn, int $companyId, string $dateFrom, string $dateTo, array $filters = []): array
    {
        re_ms_ensure_schema($conn);

        $where = ['s.company_id = ?', 's.schedule_date BETWEEN ? AND ?'];
        $params = [$companyId, $dateFrom, $dateTo];

        if (!empty($filters['building_id'])) {
            $where[] = 's.building_id = ?';
            $params[] = (int)$filters['building_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 's.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['task_type'])) {
            $where[] = 's.task_type = ?';
            $params[] = $filters['task_type'];
        }
        if (!empty($filters['employee_id'])) {
            $where[] = 's.id IN (SELECT schedule_id FROM re_maintenance_schedule_assignees WHERE employee_id = ?)';
            $params[] = (int)$filters['employee_id'];
        }

        $sql = "
            SELECT s.*,
                   b.name AS building_name,
                   u.unit_number,
                   ca.area_name AS common_area_name,
                   mr.id AS request_id
            FROM re_maintenance_schedules s
            LEFT JOIN re_buildings b ON b.id = s.building_id
            LEFT JOIN re_units u ON u.id = s.unit_id
            LEFT JOIN re_building_common_areas ca ON ca.id = s.common_area_id
            LEFT JOIN re_maintenance_requests mr ON mr.id = s.maintenance_request_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.schedule_date ASC, s.start_time ASC, s.id ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!function_exists('re_maint_location_label')) {
            require_once __DIR__ . '/maintenance_location_helper.php';
        }
        foreach ($schedules as &$schedRow) {
            $schedRow['location_label'] = re_maint_location_label(
                $schedRow['building_name'] ?? null,
                (string)($schedRow['location_type'] ?? 'unit'),
                $schedRow['unit_number'] ?? null,
                $schedRow['common_area_name'] ?? null
            );
        }
        unset($schedRow);

        if (empty($schedules)) {
            return [];
        }

        $ids = array_map(static fn($r) => (int)$r['id'], $schedules);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $aStmt = $conn->prepare("
            SELECT a.schedule_id, a.employee_id, a.team_role,
                   COALESCE(NULLIF(e.full_name, ''), CONCAT('Employee #', a.employee_id)) AS employee_name,
                   e.position_title
            FROM re_maintenance_schedule_assignees a
            LEFT JOIN employees e ON e.id = a.employee_id
            WHERE a.schedule_id IN ($ph)
            ORDER BY a.team_role ASC, employee_name ASC
        ");
        $aStmt->execute($ids);
        $assigneeMap = [];
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $assigneeMap[(int)$a['schedule_id']][] = $a;
        }

        foreach ($schedules as &$s) {
            $s['assignees'] = $assigneeMap[(int)$s['id']] ?? [];
        }
        unset($s);

        return $schedules;
    }
}

if (!function_exists('re_ms_detect_conflicts')) {
    /**
     * Find time-overlap conflicts for the given employees on a date.
     *
     * @param int[] $employeeIds
     * @return array list of ['employee_id','employee_name','work_order_number','start_time','end_time']
     */
    function re_ms_detect_conflicts(PDO $conn, int $companyId, array $employeeIds, string $date, string $startTime, string $endTime, int $excludeScheduleId = 0): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (empty($employeeIds)) {
            return [];
        }
        re_ms_ensure_schema($conn);

        $ph = implode(',', array_fill(0, count($employeeIds), '?'));
        $params = array_merge([$companyId, $date], $employeeIds);
        $params[] = $endTime;   // existing.start < new.end
        $params[] = $startTime; // existing.end > new.start
        $excludeSql = '';
        if ($excludeScheduleId > 0) {
            $excludeSql = ' AND s.id <> ?';
            $params[] = $excludeScheduleId;
        }

        $sql = "
            SELECT a.employee_id,
                   COALESCE(NULLIF(e.full_name, ''), CONCAT('Employee #', a.employee_id)) AS employee_name,
                   s.work_order_number, s.start_time, s.end_time, s.id AS schedule_id
            FROM re_maintenance_schedule_assignees a
            JOIN re_maintenance_schedules s ON s.id = a.schedule_id
            LEFT JOIN employees e ON e.id = a.employee_id
            WHERE s.company_id = ?
              AND s.schedule_date = ?
              AND s.status <> 'cancelled'
              AND a.employee_id IN ($ph)
              AND s.start_time < ?
              AND s.end_time > ?
              $excludeSql
            ORDER BY employee_name, s.start_time
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('re_ms_can_override')) {
    /** Managers / Owners / Admins may override conflicts and delete. */
    function re_ms_can_override(PDO $conn): bool
    {
        if (!function_exists('current_user_roles')) {
            return false;
        }
        $roles = current_user_roles($conn);
        if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
            return true;
        }
        foreach ($roles as $role) {
            if (stripos($role, 'manager') !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('re_ms_map_category_to_task_type')) {
    /** Best-effort mapping of a maintenance request category to a task type. */
    function re_ms_map_category_to_task_type(?string $category): string
    {
        $c = strtolower(trim((string)$category));
        if ($c === '') {
            return 'general';
        }
        $map = [
            'ac' => 'ac', 'air' => 'ac', 'hvac' => 'ac', 'cooling' => 'ac',
            'electric' => 'electrical', 'electrical' => 'electrical',
            'plumb' => 'plumbing', 'water' => 'plumbing', 'leak' => 'plumbing',
            'civil' => 'civil', 'paint' => 'painting',
            'carpent' => 'carpentry', 'wood' => 'carpentry',
            'inspect' => 'inspection', 'emergency' => 'emergency',
        ];
        foreach ($map as $kw => $type) {
            if (strpos($c, $kw) !== false) {
                return $type;
            }
        }
        return 'general';
    }
}

if (!function_exists('re_ms_duration_hours')) {
    /** Decimal hours between two HH:MM[:SS] times (same day). */
    function re_ms_duration_hours(string $start, string $end): float
    {
        $s = strtotime('1970-01-01 ' . $start);
        $e = strtotime('1970-01-01 ' . $end);
        if ($s === false || $e === false || $e <= $s) {
            return 0.0;
        }
        return round(($e - $s) / 3600, 2);
    }
}

if (!function_exists('re_ms_normalize_time')) {
    /** Normalize an HH:MM input to HH:MM:00, snapped to a step (minutes). */
    function re_ms_normalize_time(string $t, int $stepMinutes = 0): string
    {
        $ts = strtotime('1970-01-01 ' . $t);
        if ($ts === false) {
            return '00:00:00';
        }
        if ($stepMinutes > 0) {
            $mins = (int)date('G', $ts) * 60 + (int)date('i', $ts);
            $mins = (int)(round($mins / $stepMinutes) * $stepMinutes);
            $mins = max(0, min(24 * 60, $mins));
            return sprintf('%02d:%02d:00', intdiv($mins, 60), $mins % 60);
        }
        return date('H:i:00', $ts);
    }
}

if (!function_exists('re_ms_notify_assignees')) {
    /**
     * Best-effort email notification to assigned employees. Never throws.
     * Returns the number of emails attempted.
     */
    function re_ms_notify_assignees(PDO $conn, int $companyId, int $scheduleId, array $employeeIds): int
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (empty($employeeIds)) {
            return 0;
        }
        $sent = 0;
        try {
            require_once __DIR__ . '/re_email_helper.php';
            if (!function_exists('send_re_email')) {
                return 0;
            }
            // Schedule details
            if (!function_exists('re_maint_location_label')) {
                require_once __DIR__ . '/maintenance_location_helper.php';
            }
            $stmt = $conn->prepare("
                SELECT s.*, b.name AS building_name, u.unit_number, ca.area_name AS common_area_name
                FROM re_maintenance_schedules s
                LEFT JOIN re_buildings b ON b.id = s.building_id
                LEFT JOIN re_units u ON u.id = s.unit_id
                LEFT JOIN re_building_common_areas ca ON ca.id = s.common_area_id
                WHERE s.id = ? AND s.company_id = ?
            ");
            $stmt->execute([$scheduleId, $companyId]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$s) {
                return 0;
            }
            $taskTypes = re_ms_task_types();
            $statuses = re_ms_statuses();
            $loc = re_maint_location_label(
                $s['building_name'] ?? null,
                (string)($s['location_type'] ?? 'unit'),
                $s['unit_number'] ?? null,
                $s['common_area_name'] ?? null
            );

            $ph = implode(',', array_fill(0, count($employeeIds), '?'));
            $eStmt = $conn->prepare("SELECT id, full_name, email FROM employees WHERE id IN ($ph)");
            $eStmt->execute($employeeIds);
            $emps = $eStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($emps as $emp) {
                $email = trim((string)($emp['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $name = htmlspecialchars((string)($emp['full_name'] ?: 'Team member'), ENT_QUOTES, 'UTF-8');
                $subject = 'Work Order ' . ($s['work_order_number'] ?: ('#' . $s['id'])) . ' - ' . date('M j, Y', strtotime($s['schedule_date']));
                $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">'
                    . '<p>Dear ' . $name . ',</p>'
                    . '<p>You have been assigned to the following maintenance work order:</p>'
                    . '<table cellpadding="6" style="border-collapse:collapse;font-size:13px">'
                    . '<tr><td><strong>Work Order</strong></td><td>' . htmlspecialchars((string)($s['work_order_number'] ?: ('#' . $s['id'])), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                    . '<tr><td><strong>Task</strong></td><td>' . htmlspecialchars($taskTypes[$s['task_type']] ?? $s['task_type'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
                    . '<tr><td><strong>Location</strong></td><td>' . htmlspecialchars($loc ?: '-', ENT_QUOTES, 'UTF-8') . '</td></tr>'
                    . '<tr><td><strong>Date</strong></td><td>' . htmlspecialchars(date('l, M j, Y', strtotime($s['schedule_date'])), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                    . '<tr><td><strong>Time</strong></td><td>' . htmlspecialchars(substr($s['start_time'], 0, 5) . ' - ' . substr($s['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                    . '<tr><td><strong>Status</strong></td><td>' . htmlspecialchars($statuses[$s['status']] ?? $s['status'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
                    . ($s['description'] ? '<tr><td><strong>Details</strong></td><td>' . nl2br(htmlspecialchars((string)$s['description'], ENT_QUOTES, 'UTF-8')) . '</td></tr>' : '')
                    . '</table>'
                    . '<p style="color:#666;font-size:12px">This is an automated notification from the maintenance scheduling system.</p>'
                    . '</div>';
                if (@send_re_email($email, $subject, $html)) {
                    $sent++;
                }
            }
        } catch (Throwable $e) {
            // swallow - notifications must never break scheduling
        }
        return $sent;
    }
}
