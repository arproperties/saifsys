<?php
/**
 * HR API v1 — the full employee record, for our own apps outside saifsys.
 *
 * Unlike api/jarvis/v1 (which the Jarvis assistant uses and which leaves passports,
 * bank details and documents out on purpose), this one gives everything: every column
 * on the employee, every document, and the document files. So it has its own key:
 * HR_API_KEY in includes/config.php, sent as `X-HR-Key`. Empty or missing = the whole
 * API answers 503, so uploading this file without configuring it exposes nothing.
 *
 * Read-only, GET only. Every company is included; `company` narrows it to one.
 *
 *   ?action=employees&status=&company=&q=&page=1&limit=50
 *        employees with every field and their documents. status: current (default —
 *        active, on leave, notice period), left, all, or one status. limit up to 200.
 *   ?action=employee&id=   (or &code=E00076)
 *        one employee, every field and every document.
 *   ?action=salaries&status=&company=&q=&code=&payment_type=wps|cash
 *        basic, allowance, bonus, total, how paid, bank account and IBAN per employee,
 *        with totals by company and by payment type. Current staff by default.
 *   ?action=documents&code=&type=&company=&expiring=&page=1&limit=100
 *        documents on their own, each with its employee. code = one employee, type =
 *        Passport, Visa, Contract..., expiring = days (already expired or within that
 *        many days). Soonest expiry first. limit up to 500.
 *   ?action=document_file&id=&mode=view|download
 *        the file behind a document. Each document's file_url points here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../hr/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/../../../hr/includes/hr_document_file_helper.php';

const HR_API_PAGE_MAX = 200;

// Files we hand over inline; anything else goes out as an opaque download.
const HR_API_INLINE_MIME = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];

function hr_api_send(array $body, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hr_api_error(string $code, string $message, int $status): void
{
    hr_api_send(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

$secret = defined('HR_API_KEY') ? (string)HR_API_KEY : '';
if ($secret === '') {
    hr_api_error('not_configured', 'HR_API_KEY is not set on this server.', 503);
}
$given = (string)($_SERVER['HTTP_X_HR_KEY'] ?? '');
if ($given === '' || !hash_equals($secret, $given)) {
    hr_api_error('unauthorized', 'Not authorised.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    hr_api_error('method_not_allowed', 'Read-only: GET only.', 405);
}

$action = (string)($_GET['action'] ?? '');

/** Zero dates are how MySQL says "none". */
function hr_api_clean(array $row): array
{
    foreach ($row as $k => $v) {
        if ($v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
            $row[$k] = null;
        }
    }
    return $row;
}

/** Where a document's file can be fetched, as a full URL to this API. */
function hr_api_file_url(int $docId): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/api/hr/v1/index.php', '?');
    return ($https ? 'https' : 'http') . "://$host$path?action=document_file&id=$docId";
}

/** Every document of the given employees, keyed by employee id. */
function hr_api_documents(PDO $conn, array $employeeIds): array
{
    if (!$employeeIds) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($employeeIds), '?'));
    $stmt = $conn->prepare("SELECT ed.*, dt.name AS type_name
        FROM employee_documents ed
        LEFT JOIN document_types dt ON dt.id = ed.doc_type_id
        WHERE ed.employee_id IN ($marks)
        ORDER BY ed.employee_id, ed.expires_at IS NULL, ed.expires_at, ed.id");
    $stmt->execute(array_values($employeeIds));

    $today = new DateTimeImmutable('today');
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $d = hr_api_clean($d);
        $expires = $d['expires_at'] ? substr($d['expires_at'], 0, 10) : null;
        $hasFile = trim((string)$d['file_path']) !== '';
        $out[(int)$d['employee_id']][] = [
            'id' => (int)$d['id'],
            'type' => $d['type_name'] ?: ($d['doc_type'] ?: null),
            'number' => $d['doc_number'] ?: null,
            'issued_at' => $d['issued_at'] ? substr($d['issued_at'], 0, 10) : null,
            'expires_at' => $expires,
            'days_left' => $expires ? (int)$today->diff(new DateTimeImmutable($expires))->format('%r%a') : null,
            'notes' => $d['notes'] ?: null,
            'has_file' => $hasFile,
            'file_name' => $hasFile ? basename(str_replace('\\', '/', $d['file_path'])) : null,
            'file_url' => $hasFile ? hr_api_file_url((int)$d['id']) : null,
            'uploaded_at' => $d['created_at'],
            'updated_at' => $d['updated_at'],
        ];
    }
    return $out;
}

/** The full employee record: every column, the names it points at, its documents. */
function hr_api_employee(array $e, array $docs): array
{
    $e = hr_api_clean($e);
    $status = (string)($e['status'] ?: 'inactive');
    $names = [
        'company_name' => $e['company_name'],
        'department_name' => $e['dept_name'],
        'location_name' => $e['loc_name'],
        'manager_name' => $e['manager_name'],
    ];
    unset($e['dept_name'], $e['loc_name'], $e['company_name'], $e['manager_name']);
    return [
        'id' => (int)$e['id'],
        'status_label' => hr_employee_status_label($status),
        'left' => in_array($status, hr_employee_left_statuses(), true),
    ] + $e + $names + ['documents' => $docs];
}

$FROM = "FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN locations l ON l.id = e.location_id
    LEFT JOIN companies c ON c.id = e.company_id
    LEFT JOIN employees m ON m.id = e.manager_id";
$SELECT = "SELECT e.*, d.name AS dept_name, l.name AS loc_name, c.name AS company_name,
    m.full_name AS manager_name";

/** Turns fetched rows into full records, documents included. */
function hr_api_records(PDO $conn, array $rows): array
{
    $docs = hr_api_documents($conn, array_map(fn($r) => (int)$r['id'], $rows));
    return array_map(fn($r) => hr_api_employee($r, $docs[(int)$r['id']] ?? []), $rows);
}

/** The status, company and q filters that employees and salaries share. */
function hr_api_employee_filters(array &$where, array &$params): void
{
    $status = trim((string)($_GET['status'] ?? ''));
    if ($status === '' || $status === 'current') {
        $list = hr_employee_current_statuses();
    } elseif ($status === 'left') {
        $list = hr_employee_left_statuses();
    } elseif ($status === 'all') {
        $list = [];
    } elseif (array_key_exists($status, hr_employee_status_options())) {
        $list = [$status];
    } else {
        hr_api_error('bad_request', 'status must be current, left, all, or one of: ' . implode(', ', array_keys(hr_employee_status_options())) . '.', 400);
    }
    if ($list) {
        $marks = [];
        foreach ($list as $i => $s) {
            $marks[] = ":st$i";
            $params[":st$i"] = $s;
        }
        $where[] = 'e.status IN (' . implode(',', $marks) . ')';
    }

    $company = trim((string)($_GET['company'] ?? ''));
    if ($company !== '') {
        $where[] = 'c.name LIKE :company';
        $params[':company'] = "%$company%";
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(e.full_name LIKE :q OR e.nickname LIKE :q OR e.employee_code LIKE :q OR e.email LIKE :q OR e.phone LIKE :q)';
        $params[':q'] = "%$q%";
    }
}

if ($action === 'employees') {
    $where = [];
    $params = [];
    hr_api_employee_filters($where, $params);

    $limit = (int)($_GET['limit'] ?? 50);
    if ($limit < 1 || $limit > HR_API_PAGE_MAX) {
        hr_api_error('bad_request', 'limit must be 1 to ' . HR_API_PAGE_MAX . '.', 400);
    }
    $page = max(1, (int)($_GET['page'] ?? 1));

    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = $conn->prepare("SELECT COUNT(*) $FROM$sqlWhere");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("$SELECT $FROM$sqlWhere ORDER BY c.name, e.full_name, e.id
        LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $stmt->execute($params);
    $rows = hr_api_records($conn, $stmt->fetchAll(PDO::FETCH_ASSOC));

    hr_api_send([
        'ok' => true,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int)ceil($total / $limit)),
        'limit' => $limit,
        'shown' => count($rows),
        'employees' => $rows,
    ]);
}

if ($action === 'employee') {
    $id = (int)($_GET['id'] ?? 0);
    $code = trim((string)($_GET['code'] ?? ''));
    if ($id <= 0 && $code === '') {
        hr_api_error('bad_request', 'id or code is required.', 400);
    }
    $stmt = $conn->prepare("$SELECT $FROM WHERE " . ($id > 0 ? 'e.id = :v' : 'e.employee_code = :v') . ' LIMIT 1');
    $stmt->execute([':v' => $id > 0 ? $id : $code]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        hr_api_error('not_found', 'No employee with that ' . ($id > 0 ? 'id' : 'code') . '.', 404);
    }
    hr_api_send(['ok' => true, 'employee' => hr_api_records($conn, $rows)[0]]);
}

if ($action === 'salaries') {
    $where = [];
    $params = [];
    hr_api_employee_filters($where, $params);
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code !== '') {
        $where[] = 'e.employee_code = :code';
        $params[':code'] = $code;
    }
    $paidBy = trim((string)($_GET['payment_type'] ?? ''));
    if ($paidBy !== '') {
        if (!in_array($paidBy, ['wps', 'cash'], true)) {
            hr_api_error('bad_request', 'payment_type must be wps or cash.', 400);
        }
        // An empty payment type is paid by WPS, as on the payroll pages.
        $where[] = $paidBy === 'cash' ? "e.payment_type = 'cash'" : "COALESCE(e.payment_type, '') <> 'cash'";
    }

    $stmt = $conn->prepare("$SELECT $FROM" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
        ORDER BY c.name, e.full_name, e.id');
    $stmt->execute($params);

    $money = fn($v) => $v === null || $v === '' ? null : round((float)$v, 2);
    $rows = [];
    $byCompany = [];
    $byPayment = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $basic = $money($e['basic_salary']);
        $allowance = $money($e['allowance']);
        $bonus = $money($e['bonus']);
        // The stored total is what payroll uses; it is only worked out here when missing.
        $total = $money($e['total_salary']) ?? (($basic ?? 0) + ($allowance ?? 0) + ($bonus ?? 0));
        $cash = ($e['payment_type'] ?? '') === 'cash';
        $company = $e['company_name'] ?? 'No company';
        $payKey = $cash ? 'cash' : 'wps';

        $rows[] = [
            'id' => (int)$e['id'],
            'code' => $e['employee_code'],
            'name' => $e['full_name'],
            'position' => $e['position_title'] ?: null,
            'company' => $e['company_name'],
            'department' => $e['dept_name'],
            'status' => hr_employee_status_label((string)($e['status'] ?: 'inactive')),
            'basic' => $basic,
            'allowance' => $allowance,
            'bonus' => $bonus,
            'total' => round($total, 2),
            'payment_type' => $payKey,
            'payment_label' => $cash ? 'Cash' : 'WPS (Bank Transfer)',
            'bank_account_no' => $e['bank_account_no'] ?: null,
            'iban' => $e['iban'] ?: null,
        ];
        $byCompany[$company]['employees'] = ($byCompany[$company]['employees'] ?? 0) + 1;
        $byCompany[$company]['total'] = round(($byCompany[$company]['total'] ?? 0) + $total, 2);
        $byPayment[$payKey]['employees'] = ($byPayment[$payKey]['employees'] ?? 0) + 1;
        $byPayment[$payKey]['total'] = round(($byPayment[$payKey]['total'] ?? 0) + $total, 2);
    }

    hr_api_send([
        'ok' => true,
        'currency' => 'AED',
        'employees_count' => count($rows),
        'monthly_total' => round(array_sum(array_column($rows, 'total')), 2),
        'by_company' => $byCompany,
        'by_payment_type' => $byPayment,
        'employees' => $rows,
    ]);
}

if ($action === 'documents') {
    $where = [];
    $params = [];
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code !== '') {
        $where[] = 'e.employee_code = :code';
        $params[':code'] = $code;
    }
    $type = trim((string)($_GET['type'] ?? ''));
    if ($type !== '') {
        $where[] = '(dt.name LIKE :type OR ed.doc_type LIKE :type)';
        $params[':type'] = "%$type%";
    }
    $company = trim((string)($_GET['company'] ?? ''));
    if ($company !== '') {
        $where[] = 'c.name LIKE :company';
        $params[':company'] = "%$company%";
    }
    // expiring = days: expired already or expiring within that many days.
    if (isset($_GET['expiring']) && $_GET['expiring'] !== '') {
        $days = (int)$_GET['expiring'];
        if ($days < 0 || $days > 3650) {
            hr_api_error('bad_request', 'expiring must be 0 to 3650 days.', 400);
        }
        $where[] = "ed.expires_at IS NOT NULL AND ed.expires_at <> '0000-00-00' AND ed.expires_at <= :until";
        $params[':until'] = (new DateTimeImmutable('today'))->modify("+$days days")->format('Y-m-d');
    }
    $limit = (int)($_GET['limit'] ?? 100);
    if ($limit < 1 || $limit > 500) {
        hr_api_error('bad_request', 'limit must be 1 to 500.', 400);
    }
    $page = max(1, (int)($_GET['page'] ?? 1));

    $docFrom = "FROM employee_documents ed
        JOIN employees e ON e.id = ed.employee_id
        LEFT JOIN document_types dt ON dt.id = ed.doc_type_id
        LEFT JOIN companies c ON c.id = e.company_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $stmt = $conn->prepare("SELECT COUNT(*) $docFrom");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT ed.id, ed.employee_id, e.employee_code, e.full_name, e.status, c.name AS company_name
        $docFrom ORDER BY ed.expires_at IS NULL, ed.expires_at, ed.id LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $stmt->execute($params);
    $found = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Same document shape as inside an employee, plus who it belongs to.
    $byEmployee = hr_api_documents($conn, array_values(array_unique(array_map(fn($r) => (int)$r['employee_id'], $found))));
    $docById = [];
    foreach ($byEmployee as $list) {
        foreach ($list as $doc) {
            $docById[$doc['id']] = $doc;
        }
    }
    $rows = [];
    foreach ($found as $r) {
        $rows[] = [
            'employee' => [
                'id' => (int)$r['employee_id'],
                'code' => $r['employee_code'],
                'name' => $r['full_name'],
                'status' => hr_employee_status_label((string)($r['status'] ?: 'inactive')),
                'company' => $r['company_name'],
            ],
        ] + $docById[(int)$r['id']];
    }

    hr_api_send([
        'ok' => true,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int)ceil($total / $limit)),
        'limit' => $limit,
        'shown' => count($rows),
        'documents' => $rows,
    ]);
}

if ($action === 'document_file') {
    $stmt = $conn->prepare('SELECT file_path FROM employee_documents WHERE id = ?');
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $filePath = trim((string)$stmt->fetchColumn());
    if ($filePath === '') {
        hr_api_error('not_found', 'No document with that id, or it has no file.', 404);
    }

    // Only ever serve files that live under the project uploads folder.
    $uploadsRoot = realpath(__DIR__ . '/../../../uploads');
    $real = realpath(hr_document_file_absolute_path($filePath));
    if (!$real || !$uploadsRoot || !is_file($real) || strpos($real, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0) {
        // A local copy of the database lists files only live has. HR_API_FILE_FALLBACK
        // (set in a local config only, never on live) sends those to the live copy.
        if (defined('HR_API_FILE_FALLBACK') && HR_API_FILE_FALLBACK !== '') {
            header('Location: ' . rtrim(HR_API_FILE_FALLBACK, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', hr_document_file_relative_path($filePath)))), true, 302);
            exit;
        }
        hr_api_error('file_missing', 'The file for this document is not on this server.', 404);
    }

    // Content-Type from our own whitelist, never from the stored bytes.
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $mime = HR_API_INLINE_MIME[$ext] ?? null;
    $mode = ($_GET['mode'] ?? 'view') === 'download' || $mime === null ? 'attachment' : 'inline';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($real));
    header('Content-Type: ' . ($mime ?? 'application/octet-stream'));
    header("Content-Disposition: $mode; filename=\"$name\"");
    header('Content-Length: ' . filesize($real));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($real);
    exit;
}

hr_api_error('not_found', 'Unknown action. Try employees, employee or document_file.', 404);
