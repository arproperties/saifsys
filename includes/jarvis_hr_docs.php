<?php
/**
 * Employee documents for Reem's Document Renewals, read through the Jarvis API
 * (api/jarvis/v1/modules/hr_docs.php). Reem copies them onto its Shelf and checks again
 * every night, so these only read.
 *
 * A stored file_path is never handed out: Reem gets the document's id and asks for the
 * file by it, and the file is only served from inside uploads/docs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../hr/includes/hr_employee_lifecycle.php';

const JARVIS_HR_DOCS_MAX = 500;

/**
 * The file a stored path points at, if it is inside uploads/docs and really there.
 * Two shapes are stored: "uploads/docs/E00007/x.pdf" (hr/documents.php) and a bare
 * "doc_4_1764867264.pdf" (hr/employee_view.php), which lives in uploads/docs itself.
 */
function jarvis_hr_docs_resolve(string $appRoot, ?string $stored): ?string
{
    $stored = trim((string)$stored);
    if ($stored === '') {
        return null;
    }
    $base = realpath($appRoot . '/uploads/docs');
    if ($base === false) {
        return null;
    }
    $rel = str_starts_with($stored, 'uploads/') ? $stored : 'uploads/docs/' . $stored;
    $abs = realpath($appRoot . '/' . ltrim($rel, '/'));
    if ($abs === false || !is_file($abs) || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $abs;
}

/** One page of employee documents, oldest id first, after `$afterId`. */
function jarvis_hr_docs_list(PDO $conn, string $appRoot, int $afterId, int $limit): array
{
    $limit = max(1, min($limit, JARVIS_HR_DOCS_MAX));
    $stmt = $conn->prepare("SELECT ed.id, ed.doc_type, ed.doc_number, ed.issued_at, ed.expires_at, ed.notes, ed.file_path,
            COALESCE(ed.updated_at, ed.created_at) AS changed_at, e.employee_code, e.full_name, e.status
        FROM employee_documents ed JOIN employees e ON e.id = ed.employee_id
        WHERE ed.id > ? ORDER BY ed.id LIMIT " . ($limit + 1));
    $stmt->execute([$afterId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);

    $left = hr_employee_left_statuses();
    $docs = array_map(function (array $r) use ($appRoot, $left) {
        $file = jarvis_hr_docs_resolve($appRoot, $r['file_path']);
        return [
            'id' => (int)$r['id'],
            'employee_code' => (string)$r['employee_code'],
            'employee_name' => (string)$r['full_name'],
            'employee_left' => in_array(strtolower((string)$r['status']), $left, true),
            'type' => (string)$r['doc_type'],
            'number' => $r['doc_number'] !== null && $r['doc_number'] !== '' ? (string)$r['doc_number'] : null,
            'issued_at' => jarvis_hr_docs_date($r['issued_at']),
            'expires_at' => jarvis_hr_docs_date($r['expires_at']),
            'notes' => $r['notes'] !== null && $r['notes'] !== '' ? (string)$r['notes'] : null,
            'has_file' => $file !== null,
            'file_name' => $file !== null ? basename($file) : null,
            'updated_at' => $r['changed_at'] !== null ? (string)$r['changed_at'] : null,
        ];
    }, $rows);

    return ['documents' => $docs, 'next_after_id' => $more ? (int)end($rows)['id'] : null];
}

/** The file of one employee document, or null when there is none to serve. */
function jarvis_hr_docs_file(PDO $conn, string $appRoot, int $id): ?string
{
    $stmt = $conn->prepare('SELECT file_path FROM employee_documents WHERE id = ?');
    $stmt->execute([$id]);
    $path = $stmt->fetchColumn();
    return $path === false ? null : jarvis_hr_docs_resolve($appRoot, (string)$path);
}

const JARVIS_HR_PAYROLL_MAX_MONTHS = 24;

/**
 * One employee's pay as payroll actually ran it, newest first: finalized or paid runs only
 * (a draft is not what was paid, a cancelled one never was), within `$months` of `$today`.
 * Reem sets it beside the contract's terms to flag what differs.
 */
function jarvis_hr_docs_payroll(PDO $conn, string $code, int $months, string $today): ?array
{
    $emp = $conn->prepare('SELECT id, employee_code, full_name FROM employees WHERE employee_code = ?');
    $emp->execute([$code]);
    $e = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        return null;
    }
    $months = max(1, min($months, JARVIS_HR_PAYROLL_MAX_MONTHS));
    $since = (new DateTimeImmutable($today))->modify("-{$months} months")->format('Y-m-d');
    $stmt = $conn->prepare("SELECT r.id, r.period_from, r.period_to, r.payroll_type, r.status,
            i.base_pay, i.allowance, i.bonus, i.overtime, i.gross_pay, i.total_deductions, i.deductions, i.net_pay
        FROM payroll_items i JOIN payroll_runs r ON r.id = i.payroll_run_id
        WHERE i.employee_id = ? AND r.status IN ('finalized', 'paid') AND r.period_to >= ?
        ORDER BY r.period_to DESC, r.id DESC");
    $stmt->execute([(int)$e['id'], $since]);
    $num = fn($v) => $v === null ? null : (float)$v;
    return [
        'employee_code' => (string)$e['employee_code'],
        'employee_name' => (string)$e['full_name'],
        'items' => array_map(fn(array $r) => [
            'run_id' => (int)$r['id'],
            'period_from' => (string)$r['period_from'],
            'period_to' => (string)$r['period_to'],
            'payroll_type' => (string)$r['payroll_type'],
            'status' => (string)$r['status'],
            'base_pay' => $num($r['base_pay']),
            'allowance' => $num($r['allowance']),
            'bonus' => $num($r['bonus']),
            'overtime' => $num($r['overtime']),
            'gross_pay' => $num($r['gross_pay']),
            'deductions' => $num($r['total_deductions'] ?? $r['deductions']),
            'net_pay' => $num($r['net_pay']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

function jarvis_hr_docs_date($d): ?string
{
    return $d && $d !== '0000-00-00' ? substr((string)$d, 0, 10) : null;
}
