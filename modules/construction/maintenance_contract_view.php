<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$userId = (int)(current_user_id() ?: 0);
$id = (int)($_GET['id'] ?? 0);
$msg = '';
$err = '';
$stmt = $conn->prepare("SELECT c.*, cl.client_name, p.project_code, p.project_name FROM co_maintenance_contracts c JOIN co_clients cl ON cl.id = c.client_id LEFT JOIN co_projects p ON p.id = c.project_id WHERE c.id = ? AND c.company_id = ?");
$stmt->execute([$id, $cid]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) { header('Location: maintenance_contracts.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'generate_schedules') {
            $endDate = $contract['end_date'] ?: $contract['start_date'];
            $created = co_generate_period_schedules($conn, 'co_maintenance_invoice_schedules', $cid, $id, $contract['start_date'], $endDate, $contract['billing_frequency'], (float)$contract['amount'], (float)$contract['vat_rate']);
            $msg = $created . ' invoice schedule row(s) created.';
        } elseif (($_POST['action'] ?? '') === 'invoice_schedule') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM co_maintenance_invoice_schedules WHERE id = ? AND company_id = ? AND contract_id = ? AND status = 'pending'");
            $stmt->execute([$scheduleId, $cid, $id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule) throw new RuntimeException('Schedule not found or already invoiced.');
            $invoiceNumber = co_next_document_number($conn, $cid, 'MAIN-INV', 'co_client_invoices', 'invoice_number');
            $incomeAccountId = co_default_income_account_id($conn, $cid, 'maintenance_service');
            $desc = 'Maintenance service - ' . $contract['service_name'] . ' for ' . $schedule['period_start'] . ' to ' . $schedule['period_end'];
            $conn->beginTransaction();
            $invoiceId = co_create_income_invoice($conn, $cid, (int)$contract['client_id'], (int)($contract['project_id'] ?? 0), 'maintenance_service', $scheduleId, $invoiceNumber, $schedule['due_date'], $schedule['due_date'], (float)$schedule['amount'], (float)$schedule['vat_amount'], $desc, $incomeAccountId, $userId);
            $postResult = co_post_client_invoice_to_accounting($invoiceId, $cid, $userId);
            if (!$postResult['success']) throw new RuntimeException($postResult['error'] ?? 'Invoice posting failed.');
            $conn->prepare("UPDATE co_client_invoices SET journal_id = ? WHERE id = ? AND company_id = ?")->execute([$postResult['journal_id'], $invoiceId, $cid]);
            $conn->prepare("UPDATE co_maintenance_invoice_schedules SET invoice_id = ?, status = 'invoiced' WHERE id = ? AND company_id = ?")->execute([$invoiceId, $scheduleId, $cid]);
            $conn->commit();
            $msg = 'Maintenance invoice generated and posted.';
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $err = $e->getMessage();
    }
}
$stmt = $conn->prepare("SELECT s.*, i.invoice_number, i.status AS invoice_status FROM co_maintenance_invoice_schedules s LEFT JOIN co_client_invoices i ON i.id = s.invoice_id WHERE s.company_id = ? AND s.contract_id = ? ORDER BY s.period_start");
$stmt->execute([$cid, $id]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Maintenance Contract';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div><a href="maintenance_contracts.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0"><?= h($contract['contract_number']) ?></h1><p class="text-muted mb-0"><?= h($contract['service_name']) ?> - <?= h($contract['client_name']) ?></p></div>
    <form method="post"><input type="hidden" name="action" value="generate_schedules"><button class="btn btn-primary">Generate Invoice Schedule</button></form>
</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="card card-round mb-4"><div class="card-body row g-3"><div class="col-md-3"><div class="text-muted">Amount</div><strong><?= co_format_money($contract['amount']) ?></strong></div><div class="col-md-3"><div class="text-muted">Billing</div><strong><?= h($contract['billing_frequency']) ?></strong></div><div class="col-md-3"><div class="text-muted">Project</div><strong><?= h($contract['project_code'] ?: '-') ?></strong></div><div class="col-md-3"><div class="text-muted">Status</div><span class="badge bg-success"><?= h($contract['status']) ?></span></div></div></div>
<div class="card card-round"><div class="card-body p-0 table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Period</th><th>Due</th><th class="text-end">Amount</th><th class="text-end">VAT</th><th>Invoice</th><th></th></tr></thead><tbody>
<?php foreach ($schedules as $s): ?><tr><td><?= h($s['period_start']) ?> to <?= h($s['period_end']) ?></td><td><?= h($s['due_date']) ?></td><td class="text-end"><?= co_format_money($s['amount']) ?></td><td class="text-end"><?= co_format_money($s['vat_amount']) ?></td><td><?= $s['invoice_id'] ? h($s['invoice_number'] . ' (' . $s['invoice_status'] . ')') : '<span class="badge bg-secondary">Pending</span>' ?></td><td><?php if (!$s['invoice_id']): ?><form method="post"><input type="hidden" name="action" value="invoice_schedule"><input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-outline-success">Generate Invoice</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$schedules): ?><tr><td colspan="6" class="text-center text-muted py-4">No invoice schedule yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
