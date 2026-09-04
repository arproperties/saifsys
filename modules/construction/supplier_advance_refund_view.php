<?php
/**
 * Construction — Advance refund view / reverse
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
$msg = !empty($_GET['posted']) ? 'Advance refund posted.' : '';
$err = '';
$rf = co_supplier_load_advance_refund($conn, $cid, $id);
if (!$rf) { header('Location: supplier_payments.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse') {
    csrf_verify();
    $res = co_supplier_reverse_advance_refund($conn, $cid, $id, trim($_POST['reason'] ?? ''), $userId ? (int)$userId : null);
    if (!empty($res['success'])) {
        $msg = 'Refund reversed.';
    } else {
        $err = $res['error'] ?? 'Reverse failed';
    }
    $rf = co_supplier_load_advance_refund($conn, $cid, $id) ?: $rf;
}

$sup = $conn->prepare("SELECT supplier_name FROM co_suppliers WHERE id=? AND company_id=?");
$sup->execute([(int)$rf['supplier_id'], $cid]);
$supplierName = (string)$sup->fetchColumn();
$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
$journalUrl = $appBase . '/modules/construction/journal_entry_view.php?id=';

$pageTitle = 'Advance Refund';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
    <div>
        <a href="supplier_payment_view.php?id=<?= (int)$rf['supplier_payment_id'] ?>" class="btn btn-outline-secondary btn-sm mb-2">Back to Payment</a>
        <h1 class="h4 mb-0">Advance Refund <?= co_format_money($rf['amount']) ?>
            <span class="badge bg-<?= ($rf['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h(ucfirst((string)$rf['status'])) ?></span>
        </h1>
        <p class="text-muted mb-0"><?= h($supplierName) ?> · <?= h($rf['refund_date']) ?></p>
    </div>
    <?php if (($rf['status'] ?? '') === 'posted'): ?>
    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#revModal">Reverse</button>
    <?php endif; ?>
</div>
<div class="card card-round"><div class="card-body">
<table class="table table-sm mb-0">
<tr><td class="text-muted" style="width:30%">Source Payment</td><td><a href="supplier_payment_view.php?id=<?= (int)$rf['supplier_payment_id'] ?>">#<?= (int)$rf['supplier_payment_id'] ?></a></td></tr>
<tr><td class="text-muted">Amount</td><td><?= co_format_money($rf['amount']) ?></td></tr>
<tr><td class="text-muted">Reference</td><td><?= h($rf['reference'] ?: '—') ?></td></tr>
<tr><td class="text-muted">Notes</td><td><?= h($rf['notes'] ?: '—') ?></td></tr>
<tr><td class="text-muted">GL Journal</td><td>
<?php if (!empty($rf['journal_id'])): ?>
<a href="<?= h($journalUrl . (int)$rf['journal_id']) ?>" target="_blank">#<?= (int)$rf['journal_id'] ?></a>
<?php else: ?>—<?php endif; ?>
</td></tr>
</table>
</div></div>
<?php if (($rf['status'] ?? '') === 'posted'): ?>
<div class="modal fade" id="revModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
<?php csrf_field(); ?><input type="hidden" name="action" value="reverse">
<div class="modal-header"><h5 class="modal-title">Reverse Refund</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><label class="form-label">Reason</label><input type="text" name="reason" class="form-control" required></div>
<div class="modal-footer"><button class="btn btn-warning">Reverse</button></div>
</form></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
