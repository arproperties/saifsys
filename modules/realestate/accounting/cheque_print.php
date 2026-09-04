<?php
/**
 * Real Estate Accounting - Cheque Print
 * Print-friendly view for a single cheque (re_post_dated_cheques)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$chequeId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
if (!$chequeId) {
    header('Location: ../billing_cheques.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT c.*, l.lease_number, t.first_name, t.last_name, t.company_name, t.tenant_type
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE c.id = ? AND c.company_id = ?
");
$stmt->execute([$chequeId, $currentCompanyId]);
$cheque = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cheque) {
    header('Location: ../billing_cheques.php');
    exit;
}

$payee = $cheque['tenant_type'] === 'company' ? $cheque['company_name'] : ($cheque['first_name'] . ' ' . $cheque['last_name']);
$amount = (float)$cheque['cheque_amount'];
$amountWords = amount_to_words($amount);

function amount_to_words($num) {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $int = (int)floor($num);
    $dec = round(($num - $int) * 100);
    if ($int >= 1000000) {
        return number_format($num, 2) . ' AED only';
    }
    if ($int == 0) {
        return $dec ? $dec . '/100 fils only' : 'Zero AED only';
    }
    $str = '';
    if ($int >= 1000) {
        $str .= $ones[(int)($int / 1000)] . ' Thousand ';
        $int %= 1000;
    }
    if ($int >= 100) {
        $str .= $ones[(int)($int / 100)] . ' Hundred ';
        $int %= 100;
    }
    if ($int >= 20) {
        $str .= $tens[(int)($int / 10)] . ' ';
        $int %= 10;
    }
    if ($int > 0) {
        $str .= $ones[$int] . ' ';
    }
    $str .= 'AED';
    if ($dec > 0) {
        $str .= ' and ' . $dec . '/100 fils';
    }
    return trim($str) . ' only';
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Print Cheque';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="no-print mb-3">
    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <a href="../billing_cheques.php" class="btn btn-outline-secondary">Back to Cheques</a>
</div>

<div class="card card-round p-4" style="max-width: 800px; border: 2px solid #333;">
    <div class="row mb-4">
        <div class="col-6">
            <strong>Date:</strong> <?= date('d/m/Y', strtotime($cheque['cheque_date'])) ?>
        </div>
        <div class="col-6 text-end">
            <strong>Cheque #:</strong> <?= h($cheque['cheque_number']) ?>
        </div>
    </div>
    <div class="mb-2">
        <label class="form-label small text-muted">Pay</label>
        <div class="border-bottom border-dark pb-1" style="min-height: 28px;">
            <strong><?= h($payee) ?></strong>
        </div>
    </div>
    <div class="mb-2">
        <label class="form-label small text-muted">Amount in words</label>
        <div class="border-bottom border-dark pb-1" style="min-height: 28px;">
            <?= h($amountWords) ?>
        </div>
    </div>
    <div class="row mt-4">
        <div class="col-6">
            <label class="form-label small text-muted">AED</label>
            <div class="border-bottom border-dark d-inline-block" style="min-width: 120px;">
                <?= number_format($amount, 2) ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <?php if (!empty($cheque['bank_name'])): ?>
                <small class="text-muted"><?= h($cheque['bank_name']) ?></small>
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-4 small text-muted">
        Lease: <?= h($cheque['lease_number']) ?> | Received: <?= $cheque['received_date'] ? date('d/m/Y', strtotime($cheque['received_date'])) : '-' ?>
    </div>
</div>

<style media="print">
.no-print { display: none !important; }
body { background: #fff; }
.card-round { box-shadow: none; border: 2px solid #000 !important; }
</style>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
