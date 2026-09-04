<?php
require_once __DIR__ . '/construction_report_helpers.php';
$ctx = co_report_bootstrap();
$brand = $ctx['brand'];
require_once __DIR__ . '/../includes/construction_shop_rental_helpers.php';
$cid = co_shop_require_company_id($conn);
$userId = (int)(current_user_id() ?: 0);
$month = $_GET['month'] ?? date('Y-m');
$monthStart = date('Y-m-01', strtotime($month . '-01'));
$monthEnd = date('Y-m-t', strtotime($monthStart));
$msg = '';
$err = '';

function co_deferred_rent_rows(PDO $conn, int $cid, string $monthStart, string $monthEnd): array {
    $shopsExpr = co_shop_phase175_schema_ready($conn)
        ? ("COALESCE(" . co_shop_sql_shops_label('c') . ", u.shop_number)")
        : "u.shop_number";
    $stmt = $conn->prepare("
        SELECT s.id AS schedule_id, s.period_start, s.period_end, s.amount, s.vat_amount,
               i.invoice_number, i.invoice_date, c.contract_number, cl.client_name,
               {$shopsExpr} AS shop_number,
               r.id AS recognition_id, r.amount AS recognized_amount, r.journal_id
        FROM co_shop_rent_schedules s
        JOIN co_shop_rental_contracts c ON c.id = s.contract_id
        JOIN co_clients cl ON cl.id = c.client_id
        JOIN co_shop_units u ON u.id = c.shop_unit_id
        JOIN co_client_invoices i ON i.id = s.invoice_id
        LEFT JOIN co_shop_rent_recognitions r
               ON r.schedule_id = s.id
              AND r.company_id = s.company_id
              AND r.recognition_month = ?
        WHERE s.company_id = ?
          AND c.accrual_deferred_rent = 1
          AND s.status = 'invoiced'
          AND s.period_start <= ?
          AND s.period_end >= ?
          AND i.status <> 'cancelled'
        ORDER BY shop_number, cl.client_name, s.period_start
    ");
    $stmt->execute([$monthStart, $cid, $monthEnd, $monthStart]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $months = co_months_inclusive($row['period_start'], $row['period_end']);
        $net = (float)$row['amount'];
        if ($months <= 1) {
            $row['recognition_amount'] = round($net, 2);
        } else {
            $base = round($net / $months, 2);
            $monthIndex = 0;
            $cursor = new DateTime(date('Y-m-01', strtotime($row['period_start'])));
            $endM = new DateTime(date('Y-m-01', strtotime($row['period_end'])));
            $target = new DateTime($monthStart);
            while ($cursor < $target && $cursor <= $endM) {
                $cursor->modify('+1 month');
                $monthIndex++;
            }
            if ($monthIndex >= $months - 1) {
                $row['recognition_amount'] = round($net - ($base * ($months - 1)), 2);
            } else {
                $row['recognition_amount'] = $base;
            }
        }
        $row['recognition_month'] = $monthStart;
        $row['recognition_status'] = !empty($row['recognition_id']) ? 'Recognized' : 'Pending';
    }
    unset($row);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'recognize_one' || $action === 'recognize_all') {
            $rows = co_deferred_rent_rows($conn, $cid, $monthStart, $monthEnd);
            $selected = [];
            if ($action === 'recognize_one') {
                $scheduleId = (int)($_POST['schedule_id'] ?? 0);
                foreach ($rows as $row) {
                    if ((int)$row['schedule_id'] === $scheduleId && empty($row['recognition_id'])) {
                        $selected[] = $row;
                        break;
                    }
                }
            } else {
                foreach ($rows as $row) {
                    if (empty($row['recognition_id'])) {
                        $selected[] = $row;
                    }
                }
            }
            if (!$selected) {
                throw new RuntimeException('No pending deferred rent found for this month.');
            }
            $conn->beginTransaction();
            $posted = 0;
            foreach ($selected as $row) {
                $amount = (float)$row['recognition_amount'];
                if ($amount <= 0) {
                    continue;
                }
                $postResult = co_post_shop_rent_recognition((int)$row['schedule_id'], $cid, $monthStart, $amount, $userId);
                if (!$postResult['success']) {
                    throw new RuntimeException($postResult['error'] ?? 'Revenue recognition posting failed.');
                }
                $ins = $conn->prepare("
                    INSERT INTO co_shop_rent_recognitions
                        (company_id, schedule_id, recognition_month, amount, journal_id, recognized_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$cid, (int)$row['schedule_id'], $monthStart, $amount, $postResult['journal_id'], $userId ?: null]);
                $conn->prepare("
                    UPDATE co_shop_rent_schedules
                    SET recognized_at = COALESCE(recognized_at, NOW()),
                        recognized_journal_id = COALESCE(recognized_journal_id, ?)
                    WHERE id = ? AND company_id = ?
                ")->execute([$postResult['journal_id'], (int)$row['schedule_id'], $cid]);
                $posted++;
            }
            $conn->commit();
            $msg = $posted . ' deferred rent recognition journal(s) posted.';
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $err = $e->getMessage();
    }
}

$rows = co_deferred_rent_rows($conn, $cid, $monthStart, $monthEnd);
co_report_export($rows, ['contract_number' => 'Contract', 'client_name' => 'Tenant', 'shop_number' => 'Shop', 'period_start' => 'Period From', 'period_end' => 'Period To', 'invoice_number' => 'Invoice', 'recognition_amount' => 'Recognition Amount', 'recognition_status' => 'Status'], 'construction_deferred_rent_recognition_' . $month, 'Deferred Rent Recognition');
$pageTitle = 'Deferred Rent Recognition';
require_once __DIR__ . '/../includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div><h1 class="h4 mb-0">Deferred Rent Recognition</h1><p class="text-muted mb-0"><?= h(date('F Y', strtotime($monthStart))) ?></p></div>
    <div class="d-flex gap-2"><?= co_report_export_buttons() ?></div>
</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="get" class="card card-round mb-4 no-print"><div class="card-body row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label">Recognition Month</label><input type="month" name="month" class="form-control" value="<?= h($month) ?>"></div>
    <div class="col-md-4"><button class="btn btn-primary">Generate</button></div>
</div></form>
<div class="card card-round">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Pending / Recognized Rent</strong>
        <form method="post" class="no-print mb-0"><?php csrf_field(); ?><input type="hidden" name="action" value="recognize_all"><button class="btn btn-sm btn-success">Recognize All Pending</button></form>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0"><thead class="table-light"><tr><th>Contract</th><th>Tenant</th><th>Shop</th><th>Period</th><th>Invoice</th><th class="text-end">Amount</th><th>Status</th><th class="no-print"></th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= h($row['contract_number']) ?></td>
                <td><?= h($row['client_name']) ?></td>
                <td><?= h($row['shop_number']) ?></td>
                <td><?= h($row['period_start']) ?> to <?= h($row['period_end']) ?></td>
                <td><?= h($row['invoice_number']) ?></td>
                <td class="text-end"><?= co_format_money($row['recognition_amount']) ?></td>
                <td><span class="badge bg-<?= $row['recognition_status'] === 'Recognized' ? 'success' : 'warning text-dark' ?>"><?= h($row['recognition_status']) ?></span></td>
                <td class="text-end no-print">
                    <?php if (empty($row['recognition_id'])): ?>
                        <form method="post" class="mb-0"><?php csrf_field(); ?><input type="hidden" name="action" value="recognize_one"><input type="hidden" name="schedule_id" value="<?= (int)$row['schedule_id'] ?>"><button class="btn btn-sm btn-outline-success">Recognize</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No deferred shop rent schedules found for this month.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/construction_layout_footer.php'; ?>
