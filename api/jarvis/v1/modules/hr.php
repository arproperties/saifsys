<?php
/**
 * Jarvis API v1 — the HR module.
 *
 * Loaded only by ../index.php, after the key has been checked; opened directly it
 * answers nothing. $conn, $action and the jarvis_api_* helpers come from there.
 *
 * View only: every action reads, none writes. HR is shared across companies, so every
 * company is included (like an HR Manager sees it); `company` narrows it to one.
 * Who may ask is decided in Jarvis: only people with the HR module ticked.
 *
 *   ?module=hr&action=employees&q=&company=&department=&status=&manager=&joined_from=&joined_to=&left_from=&left_to=
 *        a list of employees. status: current (default — active, on leave, notice
 *        period), left, all, or one status. manager = name: the people who report to them.
 *   ?module=hr&action=employee&q=
 *        one employee's profile in full, found by code, name, nickname, phone or email.
 *        Several matches come back as a list to pick from.
 *
 * Left out on purpose: passport / visa / Emirates ID numbers and documents, bank
 * account and IBAN, address, HR notes.
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../../hr/includes/hr_employee_lifecycle.php';

const JARVIS_HR_LIST_MAX = 300;

function jarvis_hr_date(string $name): ?string
{
    $v = trim((string)($_GET[$name] ?? ''));
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        jarvis_api_error('bad_request', "$name must be YYYY-MM-DD.", 400);
    }
    return $v;
}

function jarvis_hr_day(?string $v): ?string
{
    return ($v === null || $v === '' || $v === '0000-00-00') ? null : substr($v, 0, 10);
}

$JARVIS_HR_FROM = "FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN locations l ON l.id = e.location_id
    LEFT JOIN companies c ON c.id = e.company_id
    LEFT JOIN employees m ON m.id = e.manager_id";
$JARVIS_HR_SELECT = "SELECT e.*, d.name AS dept_name, l.name AS loc_name, c.name AS company_name,
    m.full_name AS manager_name";

/** The short line every list shows. */
function jarvis_hr_row(array $e): array
{
    $status = (string)($e['status'] ?: 'inactive');
    return [
        'id' => (int)$e['id'],
        'code' => $e['employee_code'] ?: null,
        'name' => (string)$e['full_name'],
        'nickname' => $e['nickname'] ?: null,
        'position' => $e['position_title'] ?: null,
        'company' => $e['company_name'] ?: null,
        'department' => $e['dept_name'] ?: null,
        'location' => $e['loc_name'] ?: null,
        'manager' => $e['manager_name'] ?: null,
        'status' => hr_employee_status_label($status),
        'left' => in_array($status, hr_employee_left_statuses(), true),
        'joined' => jarvis_hr_day($e['date_joined'] ?? null),
        'exit_date' => jarvis_hr_day($e['exit_date'] ?? null),
    ];
}

/** Statuses as SQL: current, left, all, or one status key. */
function jarvis_hr_status_where(string $status, array &$where, array &$params): void
{
    if ($status === '' || $status === 'current') {
        $list = hr_employee_current_statuses();
    } elseif ($status === 'left') {
        $list = hr_employee_left_statuses();
    } elseif ($status === 'all') {
        return;
    } elseif (array_key_exists($status, hr_employee_status_options())) {
        $list = [$status];
    } else {
        jarvis_api_error('bad_request', 'status must be current, left, all, or one of: ' . implode(', ', array_keys(hr_employee_status_options())) . '.', 400);
    }
    $marks = [];
    foreach ($list as $i => $s) {
        $marks[] = ":st$i";
        $params[":st$i"] = $s;
    }
    $where[] = 'e.status IN (' . implode(',', $marks) . ')';
}

if ($action === 'employees') {
    $where = [];
    $params = [];
    jarvis_hr_status_where(trim((string)($_GET['status'] ?? '')), $where, $params);

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(e.full_name LIKE :q OR e.nickname LIKE :q OR e.employee_code LIKE :q OR e.position_title LIKE :q)';
        $params[':q'] = "%$q%";
    }
    foreach (['company' => 'c.name', 'department' => 'd.name', 'manager' => 'm.full_name'] as $key => $col) {
        $v = trim((string)($_GET[$key] ?? ''));
        if ($v !== '') {
            $where[] = "($col LIKE :$key" . ($key === 'manager' ? ' OR m.nickname LIKE :manager' : '') . ')';
            $params[":$key"] = "%$v%";
        }
    }
    foreach (['joined_from' => 'e.date_joined >=', 'joined_to' => 'e.date_joined <=',
              'left_from' => 'e.exit_date >=', 'left_to' => 'e.exit_date <='] as $key => $cond) {
        $v = jarvis_hr_date($key);
        if ($v !== null) {
            $where[] = "$cond :$key";
            $params[":$key"] = $v;
        }
    }

    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = $conn->prepare("SELECT COUNT(*) $JARVIS_HR_FROM$sqlWhere");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("$JARVIS_HR_SELECT $JARVIS_HR_FROM$sqlWhere ORDER BY c.name, e.full_name LIMIT " . JARVIS_HR_LIST_MAX);
    $stmt->execute($params);
    $rows = array_map('jarvis_hr_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $byCompany = [];
    foreach ($rows as $r) {
        $k = $r['company'] ?? 'No company';
        $byCompany[$k] = ($byCompany[$k] ?? 0) + 1;
    }

    jarvis_api_send([
        'ok' => true,
        'total' => $total,
        'shown' => count($rows),
        'by_company' => $byCompany,
        'employees' => $rows,
    ]);
}

if ($action === 'employee') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        jarvis_api_error('bad_request', 'q is required: an employee code, name, phone or email.', 400);
    }

    // An exact code (or id) wins; otherwise a search, current staff first.
    $stmt = $conn->prepare("$JARVIS_HR_SELECT $JARVIS_HR_FROM WHERE e.employee_code = :q OR e.id = :id LIMIT 1");
    $stmt->execute([':q' => $q, ':id' => ctype_digit($q) ? (int)$q : 0]);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$found) {
        $current = hr_employee_current_statuses();
        $marks = implode(',', array_map(fn($i) => ":st$i", array_keys($current)));
        $params = [':q' => "%$q%", ':e' => $q];
        foreach ($current as $i => $s) {
            $params[":st$i"] = $s;
        }
        $digits = preg_replace('/\D+/', '', $q);
        $phone = strlen($digits) >= 6 ? " OR REPLACE(REPLACE(REPLACE(e.phone, ' ', ''), '-', ''), '+', '') LIKE :ph" : '';
        if ($phone) {
            $params[':ph'] = '%' . substr($digits, -9) . '%';
        }
        $stmt = $conn->prepare("$JARVIS_HR_SELECT $JARVIS_HR_FROM
            WHERE e.full_name LIKE :q OR e.nickname LIKE :q OR e.email = :e$phone
            ORDER BY (e.status IN ($marks)) DESC, e.full_name LIMIT 25");
        $stmt->execute($params);
        $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$found) {
        jarvis_api_send(['ok' => true, 'found' => 0, 'message' => "No employee matches \"$q\"."]);
    }
    if (count($found) > 1) {
        jarvis_api_send(['ok' => true, 'found' => count($found), 'matches' => array_map('jarvis_hr_row', $found)]);
    }

    $e = $found[0];
    $num = fn($k) => isset($e[$k]) && $e[$k] !== null && $e[$k] !== '' ? round((float)$e[$k], 2) : null;
    $text = fn($k) => isset($e[$k]) && $e[$k] !== '' ? $e[$k] : null;
    $profile = jarvis_hr_row($e) + [
        'email' => $e['email'] ?: null,
        'phone' => $e['phone'] ?: null,
        'date_of_birth' => jarvis_hr_day($e['date_of_birth'] ?? null),
        'salary' => [
            'basic' => $num('basic_salary'),
            'allowance' => $num('allowance'),
            'bonus' => $num('bonus'),
            'total' => $num('total_salary'),
            // Same labels as hr_payroll_payment_types(); empty means WPS there too.
            'paid_by' => ($e['payment_type'] ?? '') === 'cash' ? 'Cash' : 'WPS (Bank Transfer)',
        ],
    ];
    if ($profile['left'] || !empty($e['exit_date'])) {
        $profile['exit'] = [
            'type' => $text('exit_type'),
            'reason' => $text('exit_reason'),
            'last_working_day' => jarvis_hr_day($e['last_working_day'] ?? null),
            'final_settlement' => $text('final_settlement_status'),
            'eligible_for_rehire' => isset($e['eligible_for_rehire']) ? (bool)$e['eligible_for_rehire'] : null,
        ];
    }

    $stmt = $conn->prepare("$JARVIS_HR_SELECT $JARVIS_HR_FROM WHERE e.manager_id = :id ORDER BY e.full_name");
    $stmt->execute([':id' => (int)$e['id']]);
    $profile['reports'] = array_values(array_map(
        fn($r) => ['name' => $r['name'], 'position' => $r['position'], 'status' => $r['status']],
        array_filter(array_map('jarvis_hr_row', $stmt->fetchAll(PDO::FETCH_ASSOC)), fn($r) => !$r['left'])
    ));

    jarvis_api_send(['ok' => true, 'found' => 1, 'employee' => $profile]);
}

jarvis_api_error('not_found', 'Unknown HR action.', 404);
