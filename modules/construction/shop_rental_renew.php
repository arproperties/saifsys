<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
$cid = co_shop_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT c.*, cl.client_name FROM co_shop_rental_contracts c JOIN co_clients cl ON cl.id = c.client_id WHERE c.id = ? AND c.company_id = ?");
$stmt->execute([$id, $cid]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) { http_response_code(404); exit('Contract not found'); }
if (!co_shop_phase2a_schema_ready($conn)) {
    $err = 'Run migrations/construction_shop_rental_phase2a.sql';
}
$err = $err ?? '';
$shops = co_shop_contract_shops($conn, $cid, $id);
$defaultStart = date('Y-m-d', strtotime($contract['end_date'] . ' +1 day'));
$defaultEnd = date('Y-m-d', strtotime($defaultStart . ' +1 year -1 day'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    csrf_verify();
    try {
        $conn->beginTransaction();
        $newId = co_shop_create_renewal_draft($conn, $cid, $id, [
            'start_date' => $_POST['start_date'] ?? $defaultStart,
            'end_date' => $_POST['end_date'] ?? $defaultEnd,
            'rent_amount' => (float)($_POST['rent_amount'] ?? $contract['rent_amount']),
            'security_deposit' => (float)($_POST['security_deposit'] ?? $contract['security_deposit']),
            'vat_rate' => (float)($_POST['vat_rate'] ?? $contract['vat_rate']),
            'payment_frequency' => $_POST['payment_frequency'] ?? $contract['payment_frequency'],
            'rent_cheque_count' => (int)($_POST['rent_cheque_count'] ?? $contract['rent_cheque_count']),
            'deposit_cheque_count' => (int)($_POST['deposit_cheque_count'] ?? $contract['deposit_cheque_count']),
            'shop_unit_ids' => array_map('intval', (array)($_POST['shop_unit_ids'] ?? [])),
            'primary_shop_unit_id' => (int)($_POST['primary_shop_unit_id'] ?? 0) ?: null,
            'commission_enabled' => !empty($_POST['commission_enabled']),
            'commission_basis' => $_POST['commission_basis'] ?? 'percent',
            'commission_percent' => (float)($_POST['commission_percent'] ?? 5),
            'commission_fixed_amount' => (float)($_POST['commission_fixed_amount'] ?? 0),
            'commission_manual_override' => !empty($_POST['commission_manual_override']),
            'commission_net_amount' => (float)($_POST['commission_net_amount'] ?? 0),
            'commission_vat_enabled' => !empty($_POST['commission_vat_enabled']),
            'commission_vat_rate' => (float)($_POST['commission_vat_rate'] ?? 5),
        ], $userId);
        $conn->commit();
        header('Location: shop_rental_contract_view.php?id=' . $newId . '&msg=' . urlencode('Renewal draft created. Parent remains Active until you activate this renewal.'));
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $err = $e->getMessage();
    }
}

$allShops = $conn->prepare("SELECT id, shop_number, shop_name, status FROM co_shop_units WHERE company_id = ? ORDER BY shop_number");
$allShops->execute([$cid]);
$allShops = $allShops->fetchAll(PDO::FETCH_ASSOC);
$selected = array_map(static fn($s) => (int)$s['shop_unit_id'], $shops);
$primary = (int)$contract['shop_unit_id'];

$pageTitle = 'Renew Shop Contract';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="shop_rental_contract_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Renew Contract</h1>
    <p class="text-muted mb-0"><?= h($contract['contract_number']) ?> · <?= h($contract['client_name']) ?> · Parent stays <strong>Active</strong> until the renewal is activated.</p>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card card-round"><div class="card-body row g-3"><?php csrf_field(); ?>
    <div class="col-md-3"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= h($_POST['start_date'] ?? $defaultStart) ?>" required></div>
    <div class="col-md-3"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= h($_POST['end_date'] ?? $defaultEnd) ?>" required></div>
    <div class="col-md-3"><label class="form-label">Total Contract Rent (net)</label><input type="number" step="0.01" name="rent_amount" class="form-control" value="<?= h($_POST['rent_amount'] ?? $contract['rent_amount']) ?>" required></div>
    <div class="col-md-3"><label class="form-label">Security Deposit</label><input type="number" step="0.01" name="security_deposit" class="form-control" value="<?= h($_POST['security_deposit'] ?? $contract['security_deposit']) ?>"></div>
    <div class="col-md-3"><label class="form-label">VAT %</label><input type="number" step="0.01" name="vat_rate" class="form-control" value="<?= h($_POST['vat_rate'] ?? $contract['vat_rate']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Payment Frequency</label><select name="payment_frequency" class="form-select"><?php foreach (['monthly','quarterly','semi_annual','annual'] as $f): ?><option value="<?= $f ?>" <?= ($f === ($_POST['payment_frequency'] ?? $contract['payment_frequency'])) ? 'selected' : '' ?>><?= h(ucwords(str_replace('_',' ',$f))) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Rent Cheques</label><input type="number" min="0" name="rent_cheque_count" class="form-control" value="<?= h($_POST['rent_cheque_count'] ?? $contract['rent_cheque_count']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Deposit Cheques</label><input type="number" min="0" name="deposit_cheque_count" class="form-control" value="<?= h($_POST['deposit_cheque_count'] ?? $contract['deposit_cheque_count']) ?>"></div>
    <div class="col-12"><label class="form-label">Shops</label><div class="border rounded p-3 bg-light row g-2">
        <?php foreach ($allShops as $shop): $sid=(int)$shop['id']; ?>
        <div class="col-md-4"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="shop_unit_ids[]" value="<?= $sid ?>" id="rs<?= $sid ?>" <?= in_array($sid, $selected, true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="rs<?= $sid ?>"><?= h($shop['shop_number']) ?></label>
        </div></div>
        <?php endforeach; ?>
    </div></div>
    <div class="col-md-4"><label class="form-label">Primary Shop</label><select name="primary_shop_unit_id" class="form-select"><?php foreach ($allShops as $shop): $sid=(int)$shop['id']; if (!in_array($sid,$selected,true) && empty($_POST)) continue; ?><option value="<?= $sid ?>" <?= $sid===$primary?'selected':'' ?>><?= h($shop['shop_number']) ?></option><?php endforeach; ?></select></div>
    <?php if (co_shop_commission_schema_ready($conn)): ?>
    <div class="col-12"><hr><h6>Tenant Commission</h6></div>
    <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="commission_enabled" value="1" <?= !empty($contract['commission_enabled'])?'checked':'' ?>><label class="form-check-label">Enabled</label></div></div>
    <div class="col-md-2"><label class="form-label">Basis</label><select name="commission_basis" class="form-select"><option value="percent" <?= ($contract['commission_basis']??'')==='percent'?'selected':'' ?>>Percent</option><option value="fixed" <?= ($contract['commission_basis']??'')==='fixed'?'selected':'' ?>>Fixed</option></select></div>
    <div class="col-md-2"><label class="form-label">Percent</label><input type="number" step="0.0001" name="commission_percent" class="form-control" value="<?= h($contract['commission_percent'] ?? 5) ?>"></div>
    <div class="col-md-2"><label class="form-label">Fixed</label><input type="number" step="0.01" name="commission_fixed_amount" class="form-control" value="<?= h($contract['commission_fixed_amount'] ?? 0) ?>"></div>
    <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="commission_vat_enabled" value="1" <?= !empty($contract['commission_vat_enabled'])?'checked':'' ?>><label class="form-check-label">VAT</label></div></div>
    <div class="col-md-2"><label class="form-label">VAT %</label><input type="number" step="0.01" name="commission_vat_rate" class="form-control" value="<?= h($contract['commission_vat_rate'] ?? 5) ?>"></div>
    <?php endif; ?>
    <div class="col-12"><button class="btn btn-primary">Create Renewal Draft</button></div>
</div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
