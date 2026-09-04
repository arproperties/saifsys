<?php
/**
 * Export work orders (CSV / Excel) using the same filters as workorder_list.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../includes/work_order_financial_guard.php';

require_login();

function wo_export_workflow_label(array $row): string
{
    if (($row['status'] ?? '') === 'cancelled') {
        return 'Cancelled';
    }
    $parts = [];
    if (!empty($row['is_finalized'])) {
        $parts[] = 'Finalized';
    } elseif (!empty($row['ops_status'])) {
        $parts[] = ucfirst((string)$row['ops_status']);
    }
    $display = wo_display_workflow_status($row);
    if ($display !== '') {
        $parts[] = ucwords(str_replace('_', ' ', $display));
    }
    return $parts ? implode(' / ', $parts) : '';
}

function wo_export_build_filters(PDO $conn): array
{
    $filters = merge_get_with_session('operation_workorder');

    $search         = trim((string)($filters['search'] ?? ''));
    $date           = trim((string)($filters['date'] ?? ''));
    $date_from      = trim((string)($filters['date_from'] ?? ''));
    $date_to        = trim((string)($filters['date_to'] ?? ''));
    $status_filter  = trim((string)($filters['status_filter'] ?? ''));
    $ops_filter     = trim((string)($filters['ops_filter'] ?? ''));
    $worker_filter  = trim((string)($filters['worker_filter'] ?? ''));
    $invoice_filter = trim((string)($filters['invoice_filter'] ?? ''));

    if ($date_from === '' && $date_to === '' && $date === '') {
        $date_from = date('Y-m-d');
        $date_to   = date('Y-m-d');
    }

    $where  = [];
    $params = [];

    $currentCompanyId = current_company_id($conn) ?: 1;
    if (wo_column_exists($conn, 'company_id')) {
        $where[] = 'mo.company_id = :company_id';
        $params[':company_id'] = $currentCompanyId;
    }

    if ($search !== '') {
        $where[] = '('
            . 'COALESCE(c.client_name, mo.client_name) LIKE :q OR '
            . 'mo.worker_name LIKE :q OR '
            . 'mo.remark LIKE :q OR '
            . 'mo.Notes LIKE :q OR '
            . 'mo.time LIKE :q'
            . ')';
        $params[':q'] = '%' . $search . '%';
    }

    if ($date_from !== '' && $date_to !== '') {
        $where[] = '(mo.service_date BETWEEN :df AND :dt OR (mo.service_date IS NULL AND mo.`date` BETWEEN :df AND :dt))';
        $params[':df'] = $date_from;
        $params[':dt'] = $date_to;
    } elseif ($date !== '') {
        $where[] = '(mo.service_date = :d OR (mo.service_date IS NULL AND mo.`date` = :d))';
        $params[':d'] = $date;
    }

    if ($status_filter !== '') {
        $where[] = "COALESCE(mo.status,'') = :status";
        $params[':status'] = $status_filter;
    }

    if ($ops_filter !== '' && wo_column_exists($conn, 'ops_status')) {
        if ($ops_filter === 'open') {
            $where[] = 'COALESCE(mo.is_finalized,0) = 0';
            $where[] = "COALESCE(mo.status,'') <> 'cancelled'";
            $where[] = "(mo.ops_status = 'open' OR COALESCE(mo.status,'') IN ('draft','scheduled','confirmed','in_progress'))";
        } elseif ($ops_filter === 'completed') {
            $where[] = 'COALESCE(mo.is_finalized,0) = 0';
            $where[] = "COALESCE(mo.status,'') <> 'cancelled'";
            $where[] = "(mo.ops_status = 'completed' OR COALESCE(mo.status,'') IN ('completed','invoiced'))";
        } elseif ($ops_filter === 'cancelled') {
            $where[] = "COALESCE(mo.status,'') = 'cancelled'";
        } elseif ($ops_filter === 'finalized') {
            $where[] = 'COALESCE(mo.is_finalized,0) = 1';
        }
    } elseif ($ops_filter !== '') {
        if ($ops_filter === 'open') {
            $where[] = "COALESCE(mo.status,'') NOT IN ('completed','invoiced','cancelled')";
        } elseif ($ops_filter === 'completed') {
            $where[] = "COALESCE(mo.status,'') IN ('completed','invoiced')";
        } elseif ($ops_filter === 'cancelled') {
            $where[] = "COALESCE(mo.status,'') = 'cancelled'";
        }
    }

    $worker_filter_id = null;
    if ($worker_filter !== '') {
        try {
            $wf = $conn->prepare('SELECT id FROM workers WHERE worker_name = ? OR nickname = ? LIMIT 1');
            $wf->execute([$worker_filter, $worker_filter]);
            $worker_filter_id = (int)$wf->fetchColumn() ?: null;
            if (!$worker_filter_id) {
                $wf = $conn->prepare('SELECT id FROM workers WHERE worker_name LIKE ? OR nickname LIKE ? LIMIT 1');
                $wf->execute(['%' . $worker_filter . '%', '%' . $worker_filter . '%']);
                $worker_filter_id = (int)$wf->fetchColumn() ?: null;
            }
        } catch (Throwable $e) {
            $worker_filter_id = null;
        }
    }

    if ($worker_filter_id) {
        $params[':worker_filter_id'] = $worker_filter_id;
    } elseif ($worker_filter !== '') {
        $where[] = 'mo.worker_name LIKE :worker';
        $params[':worker'] = '%' . $worker_filter . '%';
    }

    if ($invoice_filter === 'invoiced') {
        $where[] = 'i.id IS NOT NULL';
    } elseif ($invoice_filter === 'not_invoiced') {
        $where[] = 'i.id IS NULL';
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    return [
        'where_sql' => $where_sql,
        'params' => $params,
        'worker_filter_id' => $worker_filter_id,
        'date_from' => $date_from,
        'date_to' => $date_to,
    ];
}

function wo_export_fetch_rows(PDO $conn, array $ctx): array
{
    $orderAmountSql = wo_order_customer_total_sql($conn);
    $joinWorkerFilter = '';
    if ($ctx['worker_filter_id']) {
        $joinWorkerFilter = 'JOIN order_workers owf ON owf.order_id = mo.id AND owf.worker_id = :worker_filter_id';
    }

    $sql = "
        SELECT
            mo.id,
            COALESCE(c.client_name, mo.client_name) AS client_name,
            mo.worker_name,
            COALESCE(mo.service_date, mo.`date`) AS service_date,
            mo.time,
            mo.hours,
            ({$orderAmountSql}) AS order_total,
            mo.status,
            mo.ops_status,
            mo.is_finalized,
            mo.remark,
            mo.Notes AS note,
            mo.driver_name,
            i.invoice_no,
            i.status AS invoice_status,
            i.total AS invoice_total,
            mo.cancel_reason,
            mo.address_o,
            mo.mobile_num_o,
            mo.email_o,
            mo.payment,
            mo.fee_charged
        FROM make_order mo
        {$joinWorkerFilter}
        LEFT JOIN client c ON c.id = mo.client_id
        LEFT JOIN invoices i ON i.order_id = mo.id AND i.status NOT IN ('void', 'draft')
        {$ctx['where_sql']}
        ORDER BY COALESCE(mo.service_date, mo.`date`) DESC, mo.id DESC
    ";

    $st = $conn->prepare($sql);
    $st->execute($ctx['params']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $orderIds = array_column($rows, 'id');
    $workersByOrder = [];
    $workerMap = [];

    try {
        $wm = $conn->query('SELECT id, nickname, worker_name FROM workers');
        while ($r = $wm->fetch(PDO::FETCH_ASSOC)) {
            $workerMap[(int)$r['id']] = $r['nickname'] ?: ($r['worker_name'] ?: ('#' . $r['id']));
        }

        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $q = $conn->prepare("SELECT order_id, worker_id FROM order_workers WHERE order_id IN ({$in})");
        $q->execute($orderIds);
        while ($ow = $q->fetch(PDO::FETCH_ASSOC)) {
            $oid = (int)$ow['order_id'];
            $wid = (int)$ow['worker_id'];
            $workersByOrder[$oid][] = $workerMap[$wid] ?? ('#' . $wid);
        }
    } catch (Throwable $e) {
        // optional
    }

    foreach ($rows as &$row) {
        $oid = (int)$row['id'];
        $names = $workersByOrder[$oid] ?? [];
        $row['workers'] = $names ? implode(', ', $names) : (string)($row['worker_name'] ?? '');
        $row['status'] = wo_display_workflow_status($row);
        $row['workflow'] = wo_export_workflow_label($row);
    }
    unset($row);

    return $rows;
}

function wo_export_columns(): array
{
    return [
        'id' => 'Order ID',
        'client_name' => 'Client',
        'workers' => 'Workers',
        'service_date' => 'Service Date',
        'time' => 'Time',
        'hours' => 'Hours',
        'order_total' => 'Total (incl. VAT)',
        'status' => 'Status',
        'workflow' => 'Workflow',
        'remark' => 'Remark',
        'note' => 'Note',
        'driver_name' => 'Driver',
        'invoice_no' => 'Invoice No',
        'invoice_status' => 'Invoice Status',
        'invoice_total' => 'Invoice Total',
        'address_o' => 'Address',
        'mobile_num_o' => 'Mobile',
        'email_o' => 'Email',
        'payment' => 'Payment',
        'fee_charged' => 'Fee Charged',
        'cancel_reason' => 'Cancel Reason',
    ];
}

function wo_export_send_csv(array $rows, array $columns, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, array_values($columns));

    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $val = $row[$key] ?? '';
            if (is_numeric($val) && in_array($key, ['hours', 'order_total', 'invoice_total'], true)) {
                $val = number_format((float)$val, 2, '.', '');
            }
            $line[] = $val;
        }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}

function wo_export_send_excel(array $rows, array $columns, string $filename): void
{
    if (is_file(__DIR__ . '/../includes/export_helpers.php')) {
        require_once __DIR__ . '/../includes/export_helpers.php';
        if (class_exists('ExportService')) {
            (new ExportService($GLOBALS['conn']))->export($rows, $columns, 'excel', $filename, 'Work Orders Export');
        }
    }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo chr(0xEF) . chr(0xBB) . chr(0xBF);
    echo '<html><head><meta charset="UTF-8"></head><body><table border="1"><tr>';
    foreach ($columns as $label) {
        echo '<th>' . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_keys($columns) as $key) {
            $val = $row[$key] ?? '';
            if (is_numeric($val) && in_array($key, ['hours', 'order_total', 'invoice_total'], true)) {
                $val = number_format((float)$val, 2, '.', '');
            }
            echo '<td>' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
}

$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'excel', 'xlsx', 'xls'], true)) {
    http_response_code(400);
    exit('Unsupported format. Use format=csv or format=excel');
}
if ($format === 'xlsx' || $format === 'xls') {
    $format = 'excel';
}

try {
    $ctx = wo_export_build_filters($conn);
    $rows = wo_export_fetch_rows($conn, $ctx);
    $columns = wo_export_columns();

    $range = $ctx['date_from'] && $ctx['date_to']
        ? ($ctx['date_from'] . '_to_' . $ctx['date_to'])
        : date('Y-m-d');
    $filename = 'work_orders_' . $range . '_' . date('His');

    if ($format === 'excel') {
        wo_export_send_excel($rows, $columns, $filename);
    } else {
        wo_export_send_csv($rows, $columns, $filename);
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Export failed: ' . $e->getMessage());
}
