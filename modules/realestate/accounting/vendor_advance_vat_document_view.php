<?php
/**
 * Vendor Advance VAT Document — detail / post / reverse
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
$success = !empty($_GET['posted']) ? 'VAT document posted.' : (!empty($_GET['saved']) ? 'Draft saved.' : '');
$error = '';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

if (!re_ap_advance_vat_table_ready($conn)) {
    http_response_code(503);
    die('Advance VAT schema is not installed.');
}

$doc = re_ap_load_advance_vat_doc($conn, $companyId, $id);
if (!$doc) {
    header('Location: vendor_advance_vat_documents.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'post') {
            $res = re_ap_post_advance_vat_document($conn, $companyId, $id, $userId);
            if (empty($res['success'])) {
                throw new RuntimeException($res['error'] ?? 'Posting failed');
            }
            $success = 'VAT document posted. Journal #' . (int)$res['journal_id'];
        } elseif ($action === 'reverse') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                throw new RuntimeException('Reversal reason is required.');
            }
            $res = re_ap_reverse_advance_vat_document($conn, $companyId, $id, $reason, $userId);
            if (empty($res['success'])) {
                throw new RuntimeException($res['error'] ?? 'Reversal failed');
            }
            $success = 'VAT document reversed.' . (!empty($res['reversal_journal_id']) ? ' Reversal journal #' . (int)$res['reversal_journal_id'] : '');
        } elseif ($action === 'upload') {
            if (empty($_FILES['attachments']['name'][0])) {
                throw new RuntimeException('Select at least one file.');
            }
            $up = re_ap_store_advance_vat_attachments($conn, $companyId, $id, $_FILES['attachments'], $userId);
            if (empty($up['success']) || (int)$up['count'] <= 0) {
                throw new RuntimeException($up['error'] ?? 'No valid attachments uploaded.');
            }
            $success = (int)$up['count'] . ' attachment(s) uploaded.';
        }
        $doc = re_ap_load_advance_vat_doc($conn, $companyId, $id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$vendorName = '';
$vs = $conn->prepare("SELECT vendor_name FROM re_vendors WHERE id = ? AND company_id = ?");
$vs->execute([(int)$doc['vendor_id'], $companyId]);
$vendorName = (string)($vs->fetchColumn() ?: '');

$attachments = re_ap_advance_vat_attachments($conn, $companyId, $id);
$consumed = re_ap_advance_vat_doc_consumed($conn, $companyId, $id);
$remaining = ($doc['status'] ?? '') === 'posted'
    ? re_ap_advance_vat_doc_remaining($conn, $companyId, $id)
    : 0.0;

$links = [];
$ls = $conn->prepare("
    SELECT l.*, vi.invoice_number
    FROM re_vendor_advance_vat_bill_links l
    JOIN re_vendor_invoices vi ON vi.id = l.vendor_invoice_id AND vi.company_id = l.company_id
    WHERE l.company_id = ? AND l.advance_vat_document_id = ?
    ORDER BY l.id DESC
");
$ls->execute([$companyId, $id]);
$links = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];

$audit = [];
try {
    $as = $conn->prepare("
        SELECT * FROM re_vendor_ap_audit
        WHERE company_id = ? AND vendor_payment_id = ? AND action_type LIKE 'advance_vat%'
        ORDER BY id DESC LIMIT 50
    ");
    $as->execute([$companyId, (int)$doc['vendor_payment_id']]);
    $audit = $as->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
}

$badge = ($doc['status'] === 'posted') ? 'success' : (($doc['status'] === 'draft') ? 'warning text-dark' : 'secondary');
$pageTitle = 'Advance VAT ' . $doc['supplier_invoice_number'];
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label">
        <i data-lucide="file-badge" class="me-1"></i>
        Advance VAT <?= h($doc['supplier_invoice_number']) ?>
        <span class="badge bg-<?= $badge ?> ms-2"><?= h($doc['status']) ?></span>
    </div>
    <div class="d-flex gap-2">
        <a href="vendor_advance_vat_documents.php" class="btn btn-outline-secondary">List</a>
        <?php if (($doc['status'] ?? '') === 'draft'): ?>
            <a href="vendor_advance_vat_document_edit.php?id=<?= $id ?>" class="btn btn-outline-primary">Edit</a>
            <form method="post" class="d-inline" onsubmit="return confirm('Post this document?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="post">
                <button class="btn btn-success">Post</button>
            </form>
        <?php endif; ?>
        <?php if (($doc['status'] ?? '') === 'posted'): ?>
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#reverseModal">Reverse</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card card-round">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Vendor</strong><br><?= h($vendorName) ?></div>
                    <div class="col-md-4"><strong>Source payment</strong><br>
                        <a href="vendor_payments.php?q=PAY-<?= (int)$doc['vendor_payment_id'] ?>">PAY-<?= (int)$doc['vendor_payment_id'] ?></a>
                    </div>
                    <div class="col-md-4"><strong>Supplier date</strong><br><?= h($doc['supplier_invoice_date']) ?></div>
                    <div class="col-md-4"><strong>Supplier TRN</strong><br><?= h($doc['supplier_trn'] ?: '—') ?></div>
                    <div class="col-md-4"><strong>Currency</strong><br><?= h($doc['currency_code'] ?? 'AED') ?></div>
                    <div class="col-md-4"><strong>Journal</strong><br>
                        <?php if (!empty($doc['journal_id'])): ?>
                            <a href="journal_entry_view.php?id=<?= (int)$doc['journal_id'] ?>">#<?= (int)$doc['journal_id'] ?></a>
                        <?php else: ?>—<?php endif; ?>
                        <?php if (!empty($doc['reversal_journal_id'])): ?>
                            / reverse <a href="journal_entry_view.php?id=<?= (int)$doc['reversal_journal_id'] ?>">#<?= (int)$doc['reversal_journal_id'] ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4"><strong>Taxable</strong><br><?= m($doc['taxable_amount']) ?></div>
                    <div class="col-md-4"><strong>VAT</strong><br><?= m($doc['vat_amount']) ?></div>
                    <div class="col-md-4"><strong>Gross</strong><br><?= m($doc['gross_amount']) ?></div>
                    <div class="col-md-4"><strong>VAT consumed by bills</strong><br><?= m($consumed) ?></div>
                    <div class="col-md-4"><strong>VAT remaining for bills</strong><br><?= m($remaining) ?></div>
                    <?php if (!empty($doc['notes'])): ?>
                        <div class="col-12"><strong>Notes</strong><br><?= nl2br(h($doc['notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card card-round mt-3">
            <div class="card-header">Bill links</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Bill</th><th class="text-end">VAT linked</th><th class="text-end">Taxable linked</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (!$links): ?><tr><td colspan="4" class="text-muted">No bill links.</td></tr><?php endif; ?>
                        <?php foreach ($links as $l): ?>
                            <tr>
                                <td><a href="vendor_bill_view.php?id=<?= (int)$l['vendor_invoice_id'] ?>"><?= h($l['invoice_number']) ?></a></td>
                                <td class="text-end"><?= m($l['vat_amount_linked']) ?></td>
                                <td class="text-end"><?= m($l['taxable_amount_linked']) ?></td>
                                <td><span class="badge bg-<?= ($l['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h($l['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-round mb-3">
            <div class="card-header">Attachments</div>
            <div class="card-body">
                <?php foreach ($attachments as $a): ?>
                    <a class="btn btn-sm btn-outline-secondary mb-1" target="_blank" href="../../../<?= h($a['file_path']) ?>"><?= h($a['file_name']) ?></a>
                <?php endforeach; ?>
                <?php if (!$attachments): ?><div class="text-muted mb-2">No attachments.</div><?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="mt-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="attachments[]" class="form-control form-control-sm mb-2" multiple accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <button class="btn btn-sm btn-outline-primary">Upload</button>
                </form>
            </div>
        </div>
        <div class="card card-round">
            <div class="card-header">Recent audit (payment)</div>
            <div class="list-group list-group-flush small">
                <?php if (!$audit): ?><div class="list-group-item text-muted">No audit rows.</div><?php endif; ?>
                <?php foreach ($audit as $a): ?>
                    <div class="list-group-item">
                        <div><strong><?= h($a['action_type']) ?></strong> · <?= m($a['amount'] ?? 0) ?></div>
                        <div class="text-muted"><?= h($a['changed_at'] ?? '') ?> · <?= h($a['reason'] ?? '') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if (($doc['status'] ?? '') === 'posted'): ?>
<div class="modal fade" id="reverseModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="reverse">
            <div class="modal-header">
                <h5 class="modal-title">Reverse Advance VAT Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">Reversal is blocked while posted bill links remain. Unlink/void related bills first.</div>
                <label class="form-label">Reason *</label>
                <textarea name="reason" class="form-control" rows="2" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Reverse this VAT document and restore advance balance?');">Reverse</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
