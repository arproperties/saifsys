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
 *   ?module=hr&action=contract_expiries&period=week|month&company=&status=
 *        employees whose latest contract ends this week (Mon-Sun) or this calendar month
 *        (default), soonest first, with days_left. status as for employees.
 *   ?module=hr&action=exits&type=all|resigned|terminated|not_renewed|other&from=&to=&company=
 *        who left (or is leaving), latest first, with how and why, final settlement and
 *        rehire. from/to are on the day they left; by_type counts every kind.
 *   ?module=hr&action=renewals&days=60&company=&status=
 *        contracts to renew: latest contract already ended (overdue) or ending within
 *        `days` (1-365). Current staff unless status says otherwise.
 *   ?module=hr&action=summary&company=
 *        the module at a glance: headcount by status and company, joined and left this
 *        month and year, leaving soon, contracts overdue and ending in 30 / 60 days.
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

// Each employee's latest contract end, as ct.contract_ends; a renewed contract hides the
// old one. Contracts are typed by name (doc_type) on older rows and by document_types on newer.
$JARVIS_HR_CONTRACT = "(SELECT ed.employee_id, MAX(ed.expires_at) AS contract_ends
        FROM employee_documents ed
        LEFT JOIN document_types dt ON dt.id = ed.doc_type_id
        WHERE (ed.doc_type LIKE '%contract%' OR dt.name LIKE '%contract%')
          AND ed.expires_at IS NOT NULL AND ed.expires_at <> '0000-00-00'
        GROUP BY ed.employee_id) ct";

// The day someone left (or leaves): the last working day, else the exit date.
const JARVIS_HR_LEFT_ON = "COALESCE(NULLIF(e.last_working_day, '0000-00-00'), NULLIF(e.exit_date, '0000-00-00'))";
// How they left: the exit type, else what the status says, else other.
const JARVIS_HR_EXIT_KIND = "COALESCE(NULLIF(e.exit_type, ''),
    CASE WHEN e.status IN ('resigned', 'terminated', 'not_renewed') THEN e.status END, 'other')";

/** `company` = name: narrows any action to the matching companies. */
function jarvis_hr_company_where(array &$where, array &$params): void
{
    $v = trim((string)($_GET['company'] ?? ''));
    if ($v !== '') {
        $where[] = 'c.name LIKE :company';
        $params[':company'] = "%$v%";
    }
}

/** Runs a COUNT(*) query and gives back the number. */
function jarvis_hr_count(PDO $conn, string $sql, array $params): int
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

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

if ($action === 'contract_expiries') {
    // The week runs Monday to Sunday; the month is the calendar month. Days already gone
    // in the period still count, marked expired, so "this month" means the whole month.
    $period = trim((string)($_GET['period'] ?? 'month'));
    $today = new DateTimeImmutable('today');
    if ($period === 'week') {
        $from = $today->modify('monday this week');
        $to = $from->modify('+6 days');
    } elseif ($period === 'month') {
        $from = $today->modify('first day of this month');
        $to = $today->modify('last day of this month');
    } else {
        jarvis_api_error('bad_request', 'period must be week or month.', 400);
    }

    $where = [];
    $params = [':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')];
    jarvis_hr_status_where(trim((string)($_GET['status'] ?? '')), $where, $params);
    jarvis_hr_company_where($where, $params);

    $stmt = $conn->prepare("$JARVIS_HR_SELECT, ct.contract_ends $JARVIS_HR_FROM
        JOIN $JARVIS_HR_CONTRACT ON ct.employee_id = e.id
        WHERE ct.contract_ends BETWEEN :from AND :to" . ($where ? ' AND ' . implode(' AND ', $where) : '') . "
        ORDER BY ct.contract_ends, e.full_name LIMIT " . JARVIS_HR_LIST_MAX);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $ends = new DateTimeImmutable($e['contract_ends']);
        $daysLeft = (int)$today->diff($ends)->format('%r%a');
        $rows[] = jarvis_hr_row($e) + [
            'contract_ends' => $ends->format('Y-m-d'),
            'days_left' => $daysLeft,
            'expired' => $daysLeft < 0,
        ];
    }

    jarvis_api_send([
        'ok' => true,
        'period' => $period,
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'total' => count($rows),
        'employees' => $rows,
    ]);
}

if ($action === 'exits') {
    // Everyone who has left, plus anyone with a leaving date still to come (notice period).
    $where = ['(e.status IN (' . implode(',', array_map(fn($i) => ":lf$i", array_keys(hr_employee_left_statuses()))) . ')
        OR ' . JARVIS_HR_LEFT_ON . ' IS NOT NULL)'];
    $params = [];
    foreach (hr_employee_left_statuses() as $i => $s) {
        $params[":lf$i"] = $s;
    }
    jarvis_hr_company_where($where, $params);
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
        $v = jarvis_hr_date($key);
        if ($v !== null) {
            $where[] = JARVIS_HR_LEFT_ON . " $op :$key";
            $params[":$key"] = $v;
        }
    }

    // Counted before the type filter, so by_type always shows every kind.
    $stmt = $conn->prepare('SELECT ' . JARVIS_HR_EXIT_KIND . " AS kind, COUNT(*) $JARVIS_HR_FROM
        WHERE " . implode(' AND ', $where) . ' GROUP BY kind');
    $stmt->execute($params);
    $byType = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    $type = trim((string)($_GET['type'] ?? 'all'));
    if ($type !== 'all') {
        if (!array_key_exists($type, hr_employee_exit_type_options()) || $type === '') {
            jarvis_api_error('bad_request', 'type must be all, resigned, terminated, not_renewed or other.', 400);
        }
        $where[] = JARVIS_HR_EXIT_KIND . ' = :type';
        $params[':type'] = $type;
    }

    $sqlWhere = ' WHERE ' . implode(' AND ', $where);
    $total = jarvis_hr_count($conn, "SELECT COUNT(*) $JARVIS_HR_FROM$sqlWhere", $params);
    $stmt = $conn->prepare("$JARVIS_HR_SELECT, " . JARVIS_HR_LEFT_ON . ' AS left_on, ' . JARVIS_HR_EXIT_KIND . " AS exit_kind
        $JARVIS_HR_FROM$sqlWhere ORDER BY left_on IS NULL, left_on DESC, e.full_name LIMIT " . JARVIS_HR_LIST_MAX);
    $stmt->execute($params);

    $today = date('Y-m-d');
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $leftOn = jarvis_hr_day($e['left_on'] ?? null);
        $rows[] = jarvis_hr_row($e) + [
            'left_on' => $leftOn,
            'upcoming' => $leftOn !== null && $leftOn > $today,
            'exit' => [
                'type' => hr_employee_exit_type_options()[$e['exit_kind']] ?? 'Other',
                'reason' => ($e['exit_reason'] ?? '') !== '' ? $e['exit_reason'] : null,
                'exit_date' => jarvis_hr_day($e['exit_date'] ?? null),
                'last_working_day' => jarvis_hr_day($e['last_working_day'] ?? null),
                'final_settlement' => hr_employee_settlement_options()[$e['final_settlement_status'] ?? ''] ?? null,
                'eligible_for_rehire' => isset($e['eligible_for_rehire']) ? (bool)$e['eligible_for_rehire'] : null,
            ],
        ];
    }

    jarvis_api_send([
        'ok' => true,
        'type' => $type,
        'total' => $total,
        'shown' => count($rows),
        'by_type' => $byType,
        'employees' => $rows,
    ]);
}

if ($action === 'renewals') {
    // Contracts that need renewing: already ended (overdue) or ending within `days`.
    $days = (int)($_GET['days'] ?? 60);
    if ($days < 1 || $days > 365) {
        jarvis_api_error('bad_request', 'days must be 1 to 365.', 400);
    }
    $today = new DateTimeImmutable('today');
    $until = $today->modify("+$days days")->format('Y-m-d');

    $where = [];
    $params = [];
    jarvis_hr_status_where(trim((string)($_GET['status'] ?? '')), $where, $params);
    jarvis_hr_company_where($where, $params);
    $scope = $where ? ' AND ' . implode(' AND ', $where) : '';

    $stmt = $conn->prepare("$JARVIS_HR_SELECT, ct.contract_ends $JARVIS_HR_FROM
        JOIN $JARVIS_HR_CONTRACT ON ct.employee_id = e.id
        WHERE ct.contract_ends <= :until$scope
        ORDER BY ct.contract_ends, e.full_name LIMIT " . JARVIS_HR_LIST_MAX);
    $stmt->execute($params + [':until' => $until]);

    $rows = [];
    $overdue = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $ends = new DateTimeImmutable($e['contract_ends']);
        $daysLeft = (int)$today->diff($ends)->format('%r%a');
        $overdue += $daysLeft < 0 ? 1 : 0;
        $rows[] = jarvis_hr_row($e) + [
            'contract_ends' => $ends->format('Y-m-d'),
            'days_left' => $daysLeft,
            'renewal' => $daysLeft < 0 ? 'overdue' : 'due',
        ];
    }

    jarvis_api_send([
        'ok' => true,
        'days' => $days,
        'until' => $until,
        'total' => count($rows),
        'overdue' => $overdue,
        'due' => count($rows) - $overdue,
        // In scope but with no contract end date on file, so they can never show here.
        'no_contract_date' => jarvis_hr_count($conn, "SELECT COUNT(*) $JARVIS_HR_FROM
            LEFT JOIN $JARVIS_HR_CONTRACT ON ct.employee_id = e.id
            WHERE ct.employee_id IS NULL$scope", $params),
        'employees' => $rows,
    ]);
}

if ($action === 'summary') {
    // The module at a glance: headcount, who joined and left, renewals due.
    $where = [];
    $params = [];
    jarvis_hr_company_where($where, $params);
    $scope = $where ? ' AND ' . implode(' AND ', $where) : '';

    $current = [];
    foreach (hr_employee_current_statuses() as $i => $s) {
        $current[":cu$i"] = $s;
    }
    $isCurrent = 'e.status IN (' . implode(',', array_keys($current)) . ')';

    $stmt = $conn->prepare("SELECT e.status, COUNT(*) $JARVIS_HR_FROM WHERE 1=1$scope GROUP BY e.status");
    $stmt->execute($params);
    $byStatus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $s => $n) {
        $label = hr_employee_status_label((string)$s);
        $byStatus[$label] = ($byStatus[$label] ?? 0) + (int)$n;
    }

    $stmt = $conn->prepare("SELECT COALESCE(c.name, 'No company'), COUNT(*) $JARVIS_HR_FROM
        WHERE $isCurrent$scope GROUP BY c.name ORDER BY c.name");
    $stmt->execute($params + $current);
    $byCompany = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    $today = new DateTimeImmutable('today');
    $monthStart = $today->modify('first day of this month')->format('Y-m-d');
    $yearStart = $today->format('Y') . '-01-01';
    $t = $today->format('Y-m-d');

    $joined = fn(string $from) => jarvis_hr_count($conn, "SELECT COUNT(*) $JARVIS_HR_FROM
        WHERE e.date_joined BETWEEN :from AND :to$scope", $params + [':from' => $from, ':to' => $t]);
    $left = function (string $from) use ($conn, $JARVIS_HR_FROM, $scope, $params, $t): array {
        $stmt = $conn->prepare('SELECT ' . JARVIS_HR_EXIT_KIND . " AS kind, COUNT(*) $JARVIS_HR_FROM
            WHERE " . JARVIS_HR_LEFT_ON . " BETWEEN :from AND :to$scope GROUP BY kind");
        $stmt->execute($params + [':from' => $from, ':to' => $t]);
        $byKind = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        return ['total' => array_sum($byKind), 'by_type' => $byKind];
    };
    $contractsEnding = fn(?string $from, string $to) => jarvis_hr_count($conn, "SELECT COUNT(*) $JARVIS_HR_FROM
        JOIN $JARVIS_HR_CONTRACT ON ct.employee_id = e.id
        WHERE $isCurrent AND ct.contract_ends " . ($from === null ? '< :to' : 'BETWEEN :from AND :to') . $scope,
        $params + $current + [':to' => $to] + ($from === null ? [] : [':from' => $from]));

    jarvis_api_send([
        'ok' => true,
        'today' => $t,
        'headcount' => [
            'current' => array_sum($byCompany),
            'total_records' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_company' => $byCompany,
        ],
        'joined' => ['this_month' => $joined($monthStart), 'this_year' => $joined($yearStart)],
        'left' => ['this_month' => $left($monthStart), 'this_year' => $left($yearStart)],
        'leaving_soon' => jarvis_hr_count($conn, "SELECT COUNT(*) $JARVIS_HR_FROM
            WHERE " . JARVIS_HR_LEFT_ON . " > :t$scope", $params + [':t' => $t]),
        // Current staff only: someone who has left needs no renewal.
        'contracts' => [
            'overdue' => $contractsEnding(null, $t),
            'ending_30_days' => $contractsEnding($t, $today->modify('+30 days')->format('Y-m-d')),
            'ending_60_days' => $contractsEnding($t, $today->modify('+60 days')->format('Y-m-d')),
            'no_contract_date' => jarvis_hr_count($conn, "SELECT COUNT(*) $JARVIS_HR_FROM
                LEFT JOIN $JARVIS_HR_CONTRACT ON ct.employee_id = e.id
                WHERE $isCurrent AND ct.employee_id IS NULL$scope", $params + $current),
        ],
    ]);
}

jarvis_api_error('not_found', 'Unknown HR action.', 404);
