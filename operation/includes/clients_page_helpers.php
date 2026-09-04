<?php
/**
 * Shared data helpers for Operation > Clients page.
 */

if (!function_exists('clients_page_safe')) {
    function clients_page_safe($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('clients_page_money2')) {
    function clients_page_money2($n) {
        return number_format((float)$n, 2);
    }
}

if (!function_exists('clients_page_money')) {
    function clients_page_money($n) {
        return number_format((float)$n, 2);
    }
}

if (!function_exists('clients_page_health_class')) {
    function clients_page_health_class($score) {
        $score = (int)$score;
        if ($score >= 80) return 'excellent';
        if ($score >= 60) return 'good';
        if ($score >= 40) return 'fair';
        return 'poor';
    }
}

/**
 * Fast sidebar list — no per-client order/invoice aggregates.
 */
function clients_fetch_sidebar_list(PDO $conn, int $companyId, array $opts = []): array
{
    $q = trim($opts['q'] ?? '');
    $preset = trim($opts['preset'] ?? '');
    $limit = min(100, max(10, (int)($opts['limit'] ?? 60)));
    $offset = max(0, (int)($opts['offset'] ?? 0));

    $where = ['c.is_active = 1', 'c.company_id = ?'];
    $params = [$companyId];

    if ($q !== '') {
        $where[] = '(c.client_name LIKE ? OR c.mobile_num LIKE ? OR c.email LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    switch ($preset) {
        case 'vip':
            $where[] = "c.client_status = 'vip'";
            break;
        case 'inactive':
            $where[] = "c.client_status = 'inactive'";
            break;
        case 'at_risk':
            $where[] = "c.client_status = 'at_risk'";
            break;
        case 'outstanding':
            $where[] = 'COALESCE(v.ar_total, 0) > 0';
            break;
    }

    $sql = "
        SELECT
            c.id,
            c.client_name,
            c.mobile_num,
            c.client_status,
            c.health_score,
            c.credit_limit,
            COALESCE(v.ar_total, 0) AS outstanding_balance,
            COALESCE(v.open_invoices, 0) AS open_invoices
        FROM client c
        LEFT JOIN v_ar_client_summary v ON v.client_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.client_name ASC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $st = $conn->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Load selected client panel data (single client only).
 */
function clients_load_detail(PDO $conn, int $companyId, int $clientId, string $from = '', string $to = ''): ?array
{
    if ($clientId <= 0) {
        return null;
    }

    $st = $conn->prepare('SELECT * FROM client WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$clientId, $companyId]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        return null;
    }

    require_once __DIR__ . '/../../includes/ar_helpers.php';

    $available_credit = ar_client_available_credit($conn, $clientId);
    $unapplied_receipts = ar_client_unapplied_receipts($conn, $clientId);
    $open_invoices = ar_client_open_invoices($conn, $clientId);

    $w = ['mo.company_id = ?'];
    $args = [$companyId];
    $w[] = 'mo.client_id = ?';
    $args[] = $clientId;
    if ($from !== '') {
        $w[] = 'mo.service_date >= ?';
        $args[] = $from;
    }
    if ($to !== '') {
        $w[] = 'mo.service_date <= ?';
        $args[] = $to;
    }
    $whereOrders = 'WHERE ' . implode(' AND ', $w);

    $orders = [];
    $sql = "
        SELECT mo.*,
               i.id AS invoice_id, i.invoice_no, i.status AS invoice_status, i.total AS invoice_total
          FROM make_order mo
     LEFT JOIN invoices i ON i.order_id = mo.id
        {$whereOrders}
      ORDER BY mo.service_date DESC, mo.id DESC
         LIMIT 150
    ";
    $st = $conn->prepare($sql);
    $st->execute($args);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);

    $wI = ['i.company_id = ?', 'i.client_id = ?'];
    $aI = [$companyId, $clientId];
    if ($from !== '') {
        $wI[] = 'i.issue_date >= ?';
        $aI[] = $from;
    }
    if ($to !== '') {
        $wI[] = 'i.issue_date <= ?';
        $aI[] = $to;
    }
    $whereInv = 'WHERE ' . implode(' AND ', $wI);

    $invoices = [];
    $sql = "
        SELECT i.*,
               COALESCE(SUM(ra.amount_applied), 0) AS paid,
               (i.total - COALESCE(SUM(ra.amount_applied), 0)) AS balance
          FROM invoices i
     LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
        {$whereInv}
      GROUP BY i.id
      ORDER BY i.issue_date DESC, i.id DESC
         LIMIT 150
    ";
    $st = $conn->prepare($sql);
    $st->execute($aI);
    $invoices = $st->fetchAll(PDO::FETCH_ASSOC);

    $wP = ['r.client_id = ?'];
    $aP = [$clientId];
    if ($from !== '') {
        $wP[] = 'r.receipt_date >= ?';
        $aP[] = $from;
    }
    if ($to !== '') {
        $wP[] = 'r.receipt_date <= ?';
        $aP[] = $to;
    }
    $wherePay = 'WHERE ' . implode(' AND ', $wP);

    $payments = [];
    $sql = "
        SELECT r.*,
               GROUP_CONCAT(CONCAT(i.invoice_no, ' (', FORMAT(ra.amount_applied, 2), ')')
                            ORDER BY ra.id SEPARATOR ', ') AS allocations
          FROM receipts r
     LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
     LEFT JOIN invoices i ON i.id = ra.invoice_id
        {$wherePay}
      GROUP BY r.id
      ORDER BY r.receipt_date DESC, r.id DESC
         LIMIT 150
    ";
    $st = $conn->prepare($sql);
    $st->execute($aP);
    $payments = $st->fetchAll(PDO::FETCH_ASSOC);

    $total_hours = 0.0;
    $orders_amount = 0.0;
    foreach ($orders as $o) {
        $total_hours += (float)($o['hours'] ?? 0);
        $orders_amount += (float)($o['invoice_total'] ?? ($o['grand_total'] ?? $o['total'] ?? 0));
    }

    $sum_total = $sum_paid = $sum_open = 0.0;
    foreach ($invoices as $iv) {
        $sum_total += (float)$iv['total'];
        $sum_paid += (float)$iv['paid'];
        $sum_open += max(0, (float)$iv['balance']);
    }

    $pending_stmt = $conn->prepare("
        SELECT COALESCE(SUM(grand_total), 0)
          FROM make_order
         WHERE client_id = ?
           AND status IN ('confirmed', 'completed')
           AND (invoice_id IS NULL OR invoice_id = 0)
    ");
    $pending_stmt->execute([$clientId]);
    $pending_invoicing = (float)($pending_stmt->fetchColumn() ?: 0.0);

    $invoiced_balance = $sum_open;
    $sum_open += $pending_invoicing;

    $last_payment = null;
    $st = $conn->prepare('SELECT * FROM receipts WHERE client_id = ? ORDER BY receipt_date DESC, id DESC LIMIT 1');
    $st->execute([$clientId]);
    $last_payment = $st->fetch(PDO::FETCH_ASSOC);

    return compact(
        'client', 'clientId', 'companyId', 'from', 'to',
        'orders', 'invoices', 'payments',
        'available_credit', 'unapplied_receipts', 'open_invoices',
        'total_hours', 'orders_amount',
        'sum_total', 'sum_paid', 'sum_open',
        'pending_invoicing', 'invoiced_balance', 'last_payment'
    );
}

function clients_render_sidebar_item(array $c, int $activeId): string
{
    $id = (int)$c['id'];
    $active = $id === $activeId ? ' active' : '';
    $name = clients_page_safe($c['client_name']);
    $phone = clients_page_safe($c['mobile_num']);
    $status = clients_page_safe($c['client_status'] ?? 'active');
    $health = (int)($c['health_score'] ?? 50);
    $healthClass = clients_page_health_class($health);
    $balance = (float)($c['outstanding_balance'] ?? 0);
    $openInv = (int)($c['open_invoices'] ?? 0);
    $credit = (float)($c['credit_limit'] ?? 0);

    $vip = ($c['client_status'] ?? '') === 'vip'
        ? '<span class="badge bg-warning text-dark ms-2"><i class="bi bi-star-fill"></i> VIP</span>' : '';
    $due = $balance > 0
        ? '<span class="text-warning">AED ' . clients_page_money($balance) . ' due</span>' : '';

    return <<<HTML
<a href="#" class="list-group-item list-group-item-action client-list-item{$active}"
   data-client-id="{$id}"
   data-name="{$name}"
   data-phone="{$phone}"
   data-status="{$status}"
   data-health="{$health}"
   data-balance="{$balance}"
   data-orders="{$openInv}"
   data-credit="{$credit}">
  <div class="d-flex align-items-start">
    <div class="client-status {$status}"></div>
    <div class="flex-grow-1">
      <div class="fw-semibold d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center"><span>{$name}</span>{$vip}</div>
        <span class="health-score {$healthClass}">{$health}</span>
      </div>
      <small class="text-muted d-block">{$phone}</small>
      <div class="small text-muted mt-1">
        <span class="me-2">{$openInv} open inv.</span>{$due}
      </div>
    </div>
  </div>
</a>
HTML;
}
