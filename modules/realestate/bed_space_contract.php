<?php
/**
 * Real Estate Module - Bed Space Rental Agreement
 * One page: a blank form for a new agreement (optionally filled from a lease) with a live preview,
 * the saved agreements below (draft / final / cancelled), a full screen view, and letterheads in a popup.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/bed_space_contract_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$companyName = bsc_company_name($conn, $currentCompanyId);

$error = '';
$success = '';
$messages = ['Draft saved.', 'Agreement confirmed as final. PDF generated.', 'Draft deleted.', 'Agreement cancelled.', 'Letterhead added.', 'Letterhead updated.', 'Letterhead deleted.'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// A draft opened for editing in the form (?edit=); final and cancelled ones are never loaded into the form
$editId = (int)($_POST['id'] ?? $_GET['edit'] ?? 0) ?: null;
$editing = null;
if ($editId && in_array($action, ['', 'save', 'confirm'], true)) {
    $editing = bsc_agreement($conn, $currentCompanyId, $editId);
    if (!$editing || $editing['status'] !== 'draft') {
        $editing = null;
        $editId = null;
    }
}

// Values from a lease, for "Fill from lease"
if ($action === 'lease_values') {
    header('Content-Type: application/json');
    echo json_encode(bsc_values_from_lease($conn, $currentCompanyId, (int)($_GET['lease_id'] ?? 0)) ?: ['error' => 'Lease not found']);
    exit;
}

// Preview: of a saved agreement (view_id), or of what is typed in the form
if ($action === 'preview') {
    header('Content-Type: text/html; charset=UTF-8');
    $data = !empty($_GET['view_id'])
        ? (bsc_agreement($conn, $currentCompanyId, (int)$_GET['view_id']) ?: bsc_input([]))
        : bsc_input($_GET);
    $template = bsc_template($conn, $currentCompanyId, $userId);
    $letterhead = bsc_letterhead($conn, $currentCompanyId, (int)($data['letterhead_id'] ?? 0) ?: null);
    $pdfUrl = 'bed_space_contract.php?action=lh_pdf&lh_id=' . (int)$letterhead['id'] . '&v=' . urlencode((string)($letterhead['updated_at'] ?? ''));
    echo bsc_preview_html(bsc_fill($template['template_html'], $data), $letterhead, $companyName, $pdfUrl);
    exit;
}

// The full-page letterhead PDF, for the preview
if ($action === 'lh_pdf') {
    $file = bsc_letterhead_pdf_file(bsc_letterhead($conn, $currentCompanyId, (int)($_GET['lh_id'] ?? 0) ?: null));
    if (!$file) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if ($action === 'download') {
    $row = bsc_agreement($conn, $currentCompanyId, (int)($_GET['id'] ?? 0));
    $full = !empty($row['contract_path']) ? bsc_base_path() . '/' . $row['contract_path'] : '';
    if ($full && is_file($full)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($full) . '"');
        header('Content-Length: ' . filesize($full));
        readfile($full);
        exit;
    }
    $error = 'PDF file not found.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $postId = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save' || $action === 'confirm') {
            $data = bsc_input($_POST);
            if ($data['tenant_name'] === '' || $data['bed_space_no'] === '') {
                throw new RuntimeException('Tenant Name and Bed Space Number are required.');
            }
            $savedId = bsc_save_agreement($conn, $currentCompanyId, $editId, $data, $userId);
            $msg = 'Draft saved.';
            if ($action === 'confirm') {
                bsc_confirm_agreement($conn, $currentCompanyId, $savedId, $userId, $companyName);
                $msg = 'Agreement confirmed as final. PDF generated.';
            }
            header('Location: bed_space_contract.php?msg=' . urlencode($msg));
            exit;
        }
        if ($action === 'confirm_saved') {
            bsc_confirm_agreement($conn, $currentCompanyId, $postId, $userId, $companyName);
            header('Location: bed_space_contract.php?msg=' . urlencode('Agreement confirmed as final. PDF generated.'));
            exit;
        }
        if ($action === 'delete') {
            bsc_delete_agreement($conn, $currentCompanyId, $postId);
            header('Location: bed_space_contract.php?msg=' . urlencode('Draft deleted.'));
            exit;
        }
        if ($action === 'cancel') {
            bsc_cancel_agreement($conn, $currentCompanyId, $postId, (string)($_POST['cancel_reason'] ?? ''), $userId);
            header('Location: bed_space_contract.php?msg=' . urlencode('Agreement cancelled.'));
            exit;
        }
        if ($action === 'lh_save' || $action === 'lh_delete') {
            $lhId = (int)($_POST['letterhead_id'] ?? 0) ?: null;
            $msg = $action === 'lh_save'
                ? bsc_save_letterhead($conn, $currentCompanyId, $lhId, $_POST, $userId)
                : bsc_delete_letterhead($conn, $currentCompanyId, (int)$lhId);
            header('Location: bed_space_contract.php?msg=' . urlencode($msg));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
if (!$error && in_array($_GET['msg'] ?? '', $messages, true)) {
    $success = $_GET['msg'];
}

// The agreement shown full screen (View in the list)
$viewing = !empty($_GET['view']) ? bsc_agreement($conn, $currentCompanyId, (int)$_GET['view']) : null;

$bscUserName = function ($id) use ($conn) {
    if (!$id) {
        return '';
    }
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(fullname, ''), username) FROM user WHERE id = ?");
    $stmt->execute([$id]);
    return (string)$stmt->fetchColumn();
};

// Form values: what was just posted (on an error), else the draft being edited, else a blank new one
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['save', 'confirm'], true)) {
    $form = bsc_input($_POST);
} elseif ($editing) {
    $form = $editing;
} else {
    $form = array_fill_keys(array_keys(BSC_FIELDS), '');
    $form['agreement_date'] = date('Y-m-d');
    $form['landlord_name'] = $companyName;
    $form['lease_id'] = null;
    $form['letterhead_id'] = null;
}
$letterheads = bsc_letterheads($conn, $currentCompanyId, true);
$selectedLetterhead = bsc_letterhead($conn, $currentCompanyId, (int)($form['letterhead_id'] ?? 0) ?: null);
$agreements = bsc_agreements($conn, $currentCompanyId);

$stmt = $conn->prepare("
    SELECT l.id, l.lease_number, l.status, t.first_name, t.last_name, t.company_name AS tenant_company_name,
           u.unit_number, b.name AS building_name
    FROM re_leases l
    JOIN re_tenants t ON t.id = l.tenant_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE l.company_id = ? AND (l.status IN ('draft', 'active') OR l.id = ?)
    ORDER BY b.name, u.unit_number, l.start_date DESC
");
$stmt->execute([$currentCompanyId, (int)($form['lease_id'] ?? 0)]);
$leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fv($form, $key) { return h($form[$key] ?? ''); }

$pageTitle = 'Bed Space Contract';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="page-header-label">Bed Space Contract</div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#letterheadModal">
                    <i class="bi bi-image"></i> Letterheads
                </button>
                <a href="bed_space_contract.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Agreement</a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-xl-5">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>
                            <?= $editing ? 'Edit Draft #' . (int)$editId . ' ' . bsc_status_badge('draft', 'ms-1') : 'New Agreement' ?>
                        </strong>
                        <?php if ($editing): ?>
                            <a href="bed_space_contract.php" class="btn btn-sm btn-outline-secondary">Close</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="bscForm">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="save" id="bscAction">
                            <?php if ($editing): ?>
                                <input type="hidden" name="id" value="<?= (int)$editId ?>">
                            <?php endif; ?>

                            <div class="mb-3 p-2 rounded" style="background:#f4f6fa;">
                                <label class="form-label small mb-1">Fill from lease <span class="text-muted">(optional - you can still edit every field)</span></label>
                                <input type="text" id="leaseFilter" class="form-control form-control-sm mb-1" placeholder="Type to filter: tenant, unit, lease no.">
                                <select name="lease_id" id="leaseSelect" class="form-select form-select-sm">
                                    <option value="">-- None --</option>
                                    <?php foreach ($leases as $l): ?>
                                        <?php
                                        $tenant = trim((string)$l['tenant_company_name']) ?: trim($l['first_name'] . ' ' . $l['last_name']);
                                        $label = $l['building_name'] . ' / ' . $l['unit_number'] . ' - ' . $tenant . ' (' . $l['lease_number'] . ', ' . $l['status'] . ')';
                                        ?>
                                        <option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === (int)($form['lease_id'] ?? 0) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1">Plot No</label>
                                    <input type="text" name="plot_no" class="form-control form-control-sm bsc-field" maxlength="100" value="<?= fv($form, 'plot_no') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Date</label>
                                    <input type="date" name="agreement_date" class="form-control form-control-sm bsc-field" value="<?= fv($form, 'agreement_date') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Landlord Name</label>
                                    <input type="text" name="landlord_name" class="form-control form-control-sm bsc-field" maxlength="255" value="<?= fv($form, 'landlord_name') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Tenant Name *</label>
                                    <input type="text" name="tenant_name" class="form-control form-control-sm bsc-field" maxlength="255" required value="<?= fv($form, 'tenant_name') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Subject of Tenancy</label>
                                    <input type="text" name="subject_of_tenancy" class="form-control form-control-sm bsc-field" maxlength="255" value="<?= fv($form, 'subject_of_tenancy') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Period: From</label>
                                    <input type="date" name="start_date" class="form-control form-control-sm bsc-field" value="<?= fv($form, 'start_date') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Period: To</label>
                                    <input type="date" name="end_date" class="form-control form-control-sm bsc-field" value="<?= fv($form, 'end_date') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Unit No</label>
                                    <input type="text" name="unit_no" class="form-control form-control-sm bsc-field" maxlength="100" value="<?= fv($form, 'unit_no') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Bed Space Number *</label>
                                    <input type="text" name="bed_space_no" class="form-control form-control-sm bsc-field" maxlength="50" required value="<?= fv($form, 'bed_space_no') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Rent (AED)</label>
                                    <input type="number" step="0.01" min="0" name="rent" class="form-control form-control-sm bsc-field" value="<?= fv($form, 'rent') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1">Security Deposit (AED)</label>
                                    <input type="number" step="0.01" min="0" name="security_deposit" class="form-control form-control-sm bsc-field" value="<?= fv($form, 'security_deposit') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Terms of Payment</label>
                                    <input type="text" name="terms_of_payment" class="form-control form-control-sm bsc-field" maxlength="255" value="<?= fv($form, 'terms_of_payment') ?>" placeholder="e.g. 12 installments via Cheque">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1">Letterhead</label>
                                    <div class="input-group input-group-sm">
                                        <select name="letterhead_id" id="letterheadSelect" class="form-select bsc-field">
                                            <?php foreach ($letterheads as $lh): ?>
                                                <option value="<?= (int)$lh['id'] ?>" <?= (int)$lh['id'] === (int)$selectedLetterhead['id'] ? 'selected' : '' ?>>
                                                    <?= h($lh['name']) ?><?= (int)$lh['is_default'] === 1 ? ' (default)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#letterheadModal" title="Manage letterheads"><i class="bi bi-gear"></i></button>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-outline-primary flex-fill" onclick="document.getElementById('bscAction').value='save'"><i class="bi bi-save"></i> Save Draft</button>
                                <button type="submit" class="btn btn-primary flex-fill"
                                    onclick="if (!confirm('Confirm as final? The PDF will be generated and the agreement can no longer be edited or deleted, only cancelled.')) return false; document.getElementById('bscAction').value='confirm'">
                                    <i class="bi bi-check2-circle"></i> Confirm as Final
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-eye"></i> Preview</span>
                        <small class="text-muted">Updates as you type. The PDF splits it into A4 pages.</small>
                    </div>
                    <div class="card-body p-0">
                        <iframe id="previewFrame" style="width:100%; height:1000px; border:0; background:#e9ecef;"></iframe>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><strong>Saved Agreements</strong></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0" id="agreementsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tenant</th>
                                <th>Unit / Bed</th>
                                <th>Period</th>
                                <th>Letterhead</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($agreements)): ?>
                                <tr><td colspan="7" class="text-center text-muted">No agreements yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($agreements as $a): ?>
                                <tr class="<?= (int)$a['id'] === (int)$editId ? 'table-primary' : '' ?>">
                                    <td><?= (int)$a['id'] ?></td>
                                    <td><?= h($a['tenant_name']) ?></td>
                                    <td><?= h($a['unit_no']) ?> / <?= h($a['bed_space_no']) ?></td>
                                    <td><?= $a['start_date'] ? h(date('d/m/Y', strtotime($a['start_date']))) : '' ?> - <?= $a['end_date'] ? h(date('d/m/Y', strtotime($a['end_date']))) : '' ?></td>
                                    <td><?= h($a['letterhead_name'] ?? '-') ?></td>
                                    <td>
                                        <?= bsc_status_badge($a['status']) ?>
                                        <?php if ($a['status'] === 'cancelled' && $a['cancel_reason']): ?>
                                            <i class="bi bi-info-circle text-muted" title="<?= h($a['cancel_reason']) ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="?view=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View full screen"><i class="bi bi-arrows-fullscreen"></i> View</a>
                                        <?php if ($a['status'] === 'draft'): ?>
                                            <a href="?edit=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this draft?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete draft"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!empty($a['contract_path'])): ?>
                                            <a href="?id=<?= (int)$a['id'] ?>&action=download" class="btn btn-sm btn-outline-success" title="Download PDF"><i class="bi bi-download"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($viewing): ?>
        <!-- Full screen view of a saved agreement -->
        <div class="modal fade" id="fullModal" tabindex="-1">
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <div>
                            <h5 class="modal-title mb-0">
                                Agreement #<?= (int)$viewing['id'] ?> - <?= h($viewing['tenant_name']) ?>
                                <?= bsc_status_badge($viewing['status'], 'ms-1') ?>
                            </h5>
                            <?php if ($viewing['status'] === 'final'): ?>
                                <small class="text-muted">Confirmed <?= h(date('d/m/Y H:i', strtotime($viewing['confirmed_at']))) ?><?= $viewing['confirmed_by'] ? ' by ' . h($bscUserName($viewing['confirmed_by'])) : '' ?></small>
                            <?php elseif ($viewing['status'] === 'cancelled'): ?>
                                <small class="text-danger">
                                    Cancelled <?= h(date('d/m/Y H:i', strtotime($viewing['cancelled_at']))) ?><?= $viewing['cancelled_by'] ? ' by ' . h($bscUserName($viewing['cancelled_by'])) : '' ?>.
                                    Reason: <?= h($viewing['cancel_reason']) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <a href="bed_space_contract.php" class="btn-close"></a>
                    </div>
                    <div class="modal-body p-0" style="background:#e9ecef;">
                        <iframe id="fullFrame" src="bed_space_contract.php?action=preview&view_id=<?= (int)$viewing['id'] ?>" style="width:100%; height:100%; border:0;"></iframe>
                    </div>
                    <div class="modal-footer py-2">
                        <a href="bed_space_contract.php" class="btn btn-secondary">Close</a>
                        <?php if (!empty($viewing['contract_path'])): ?>
                            <a href="?id=<?= (int)$viewing['id'] ?>&action=download" class="btn btn-success"><i class="bi bi-download"></i> Download PDF</a>
                        <?php endif; ?>
                        <?php if ($viewing['status'] === 'draft'): ?>
                            <a href="?edit=<?= (int)$viewing['id'] ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Confirm as final? The PDF will be generated and the agreement can no longer be edited or deleted, only cancelled.');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="confirm_saved">
                                <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
                                <button type="submit" class="btn btn-primary" id="fullConfirm"><i class="bi bi-check2-circle"></i> Confirm as Final</button>
                            </form>
                        <?php elseif ($viewing['status'] === 'final'): ?>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-x-circle"></i> Cancel Agreement</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($viewing['status'] === 'final'): ?>
        <!-- Cancel popup -->
        <div class="modal fade" id="cancelModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Cancel Agreement #<?= (int)$viewing['id'] ?></h5>
                            <button type="button" class="btn-close" data-bs-toggle="modal" data-bs-target="#fullModal"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label">Reason for cancelling *</label>
                            <textarea name="cancel_reason" class="form-control" rows="3" required></textarea>
                            <small class="text-muted">The agreement stays in the list as Cancelled, with this reason.</small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#fullModal">Back</button>
                            <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> Cancel Agreement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Letterheads popup -->
        <div class="modal fade" id="letterheadModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Letterheads</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <table class="table table-sm align-middle">
                            <tbody>
                                <?php foreach ($letterheads as $lh): ?>
                                    <?php $images = bsc_letterhead_images($lh); ?>
                                    <tr>
                                        <td style="width:45%">
                                            <?php if (($lh['type'] ?? 'images') === 'pdf'): ?>
                                                <a href="bed_space_contract.php?action=lh_pdf&lh_id=<?= (int)$lh['id'] ?>" target="_blank" class="text-decoration-none">
                                                    <i class="bi bi-file-earmark-pdf text-danger fs-4 align-middle"></i> Full page PDF
                                                </a>
                                                <small class="text-muted d-block">Text space: <?= h((float)$lh['margin_top']) ?> mm from top, <?= h((float)$lh['margin_bottom']) ?> mm from bottom</small>
                                            <?php elseif ($images['header']): ?>
                                                <img src="<?= h($images['header']) ?>" alt="Header" style="max-width:100%; max-height:50px; border:1px solid #dee2e6;">
                                            <?php else: ?>
                                                <span class="text-muted small">Text header (company name)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= h($lh['name']) ?></strong>
                                            <?php if ((int)$lh['is_default'] === 1): ?><span class="badge bg-primary ms-1">Default</span><?php endif; ?>
                                            <?php if ((int)$lh['is_builtin'] === 1): ?><br><small class="text-muted">Same as the Tenancy Contract</small><?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary lh-edit"
                                                data-id="<?= (int)$lh['id'] ?>" data-name="<?= h($lh['name']) ?>" data-default="<?= (int)$lh['is_default'] ?>"
                                                data-type="<?= h($lh['type'] ?? 'images') ?>" data-builtin="<?= (int)$lh['is_builtin'] ?>"
                                                data-mt="<?= h((float)$lh['margin_top']) ?>" data-mb="<?= h((float)$lh['margin_bottom']) ?>"><i class="bi bi-pencil"></i></button>
                                            <?php if ((int)$lh['is_builtin'] !== 1): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this letterhead?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="lh_delete">
                                                    <input type="hidden" name="letterhead_id" value="<?= (int)$lh['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <form method="POST" enctype="multipart/form-data" class="border rounded p-3" id="lhForm">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="lh_save">
                            <input type="hidden" name="letterhead_id" id="lhId" value="">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong id="lhFormTitle">Add Letterhead</strong>
                                <button type="button" class="btn btn-sm btn-link d-none" id="lhNew">+ Add new instead</button>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1">Name *</label>
                                    <input type="text" name="lh_name" id="lhName" class="form-control form-control-sm" maxlength="150" required>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="lh_default" id="lhDefault">
                                        <label class="form-check-label" for="lhDefault">Set as default</label>
                                    </div>
                                </div>
                                <div class="col-12" id="lhTypeRow">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="lh_type" id="lhTypePdf" value="pdf" checked>
                                        <label class="btn btn-outline-primary" for="lhTypePdf"><i class="bi bi-file-earmark-pdf"></i> Full page PDF</label>
                                        <input type="radio" class="btn-check" name="lh_type" id="lhTypeImages" value="images">
                                        <label class="btn btn-outline-primary" for="lhTypeImages"><i class="bi bi-image"></i> Header / footer images</label>
                                    </div>
                                </div>

                                <div class="col-12 lh-pdf">
                                    <label class="form-label small mb-1">Letterhead PDF <span id="lhPdfReq">*</span></label>
                                    <input type="file" name="letterhead_pdf" id="lhPdf" class="form-control form-control-sm" accept="application/pdf,.pdf">
                                    <small class="text-muted">One page with the header and footer and an empty middle. In Word: File &gt; Save As &gt; PDF. Max 10 MB.</small>
                                </div>
                                <div class="col-md-6 lh-pdf">
                                    <label class="form-label small mb-1">Text starts below (mm from top)</label>
                                    <input type="number" step="0.5" min="0" max="150" name="lh_margin_top" id="lhMarginTop" class="form-control form-control-sm" value="45">
                                </div>
                                <div class="col-md-6 lh-pdf">
                                    <label class="form-label small mb-1">Text stops above (mm from bottom)</label>
                                    <input type="number" step="0.5" min="0" max="150" name="lh_margin_bottom" id="lhMarginBottom" class="form-control form-control-sm" value="30">
                                </div>
                                <div class="col-12 lh-pdf d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="lhDetect"><i class="bi bi-magic"></i> Auto-detect</button>
                                    <small class="text-muted" id="lhDetectNote">Filled in automatically when you choose a PDF.</small>
                                </div>

                                <div class="col-md-6 lh-images d-none">
                                    <label class="form-label small mb-1">Header image <span id="lhHeaderReq">*</span></label>
                                    <input type="file" name="header_image" id="lhHeader" class="form-control form-control-sm" accept="image/png,image/jpeg">
                                </div>
                                <div class="col-md-6 lh-images d-none">
                                    <label class="form-label small mb-1">Footer image (optional)</label>
                                    <input type="file" name="footer_image" class="form-control form-control-sm" accept="image/png,image/jpeg">
                                </div>
                                <div class="col-12 lh-images d-none">
                                    <small class="text-muted">PNG or JPG, wide like a letterhead (about 2000 x 250 px), max 5 MB. Without a footer, the Ain Al Reem footer is used.</small>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="bi bi-save"></i> Save Letterhead</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var form = document.getElementById('bscForm');
                var frame = document.getElementById('previewFrame');
                var lease = document.getElementById('leaseSelect');
                var filter = document.getElementById('leaseFilter');

                function refreshPreview() {
                    var params = new URLSearchParams();
                    form.querySelectorAll('.bsc-field').forEach(function (el) { params.set(el.name, el.value); });
                    params.set('action', 'preview');
                    frame.src = 'bed_space_contract.php?' + params.toString();
                }
                var timer;
                form.querySelectorAll('.bsc-field').forEach(function (el) {
                    el.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(refreshPreview, 400); });
                    el.addEventListener('change', refreshPreview);
                });

                // Fill the fields from the chosen lease; Bed Space Number and Date stay as typed
                lease.addEventListener('change', function () {
                    if (!lease.value) return;
                    fetch('bed_space_contract.php?action=lease_values&lease_id=' + encodeURIComponent(lease.value))
                        .then(function (r) { return r.json(); })
                        .then(function (v) {
                            if (v.error) { alert(v.error); return; }
                            Object.keys(v).forEach(function (k) {
                                var input = form.querySelector('[name="' + k + '"]');
                                if (input) input.value = v[k];
                            });
                            refreshPreview();
                        });
                });
                filter.addEventListener('input', function () {
                    var q = filter.value.toLowerCase();
                    Array.prototype.forEach.call(lease.options, function (opt, i) {
                        if (i === 0) return;
                        opt.hidden = q !== '' && opt.text.toLowerCase().indexOf(q) === -1;
                    });
                });
                refreshPreview();

                // Letterhead popup: edit fills the form below the list
                var lhTitle = document.getElementById('lhFormTitle');
                var lhNew = document.getElementById('lhNew');
                var lhHeader = document.getElementById('lhHeader');
                var lhPdf = document.getElementById('lhPdf');
                var lhEditing = false;
                function lhSetType(type) {
                    document.getElementById(type === 'pdf' ? 'lhTypePdf' : 'lhTypeImages').checked = true;
                    document.querySelectorAll('.lh-pdf').forEach(function (el) { el.classList.toggle('d-none', type !== 'pdf'); });
                    document.querySelectorAll('.lh-images').forEach(function (el) { el.classList.toggle('d-none', type === 'pdf'); });
                    // Files are required only when adding; on edit an empty one keeps the current file
                    lhPdf.required = !lhEditing && type === 'pdf';
                    lhHeader.required = !lhEditing && type !== 'pdf';
                    var keep = lhEditing ? '(leave empty to keep)' : '*';
                    document.getElementById('lhPdfReq').textContent = keep;
                    document.getElementById('lhHeaderReq').textContent = keep;
                }
                document.querySelectorAll('input[name=lh_type]').forEach(function (r) {
                    r.addEventListener('change', function () { lhSetType(r.value); });
                });
                function lhReset() {
                    document.getElementById('lhForm').reset();
                    document.getElementById('lhId').value = '';
                    lhTitle.textContent = 'Add Letterhead';
                    lhEditing = false;
                    document.getElementById('lhTypeRow').classList.remove('d-none');
                    lhSetType('pdf');
                    lhNew.classList.add('d-none');
                    document.getElementById('lhDetectNote').textContent = 'Filled in automatically when you choose a PDF.';
                }
                document.querySelectorAll('.lh-edit').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        document.getElementById('lhForm').reset();
                        document.getElementById('lhId').value = btn.dataset.id;
                        document.getElementById('lhName').value = btn.dataset.name;
                        document.getElementById('lhDefault').checked = btn.dataset.default === '1';
                        document.getElementById('lhMarginTop').value = btn.dataset.mt;
                        document.getElementById('lhMarginBottom').value = btn.dataset.mb;
                        lhTitle.textContent = 'Edit: ' + btn.dataset.name;
                        lhEditing = true;
                        // The built-in Ain Al Reem letterhead stays an image one
                        document.getElementById('lhTypeRow').classList.toggle('d-none', btn.dataset.builtin === '1');
                        lhSetType(btn.dataset.type);
                        document.getElementById('lhDetectNote').textContent = 'Auto-detect reads the saved PDF, or the new one if you choose a file.';
                        lhNew.classList.remove('d-none');
                        document.getElementById('lhName').focus();
                    });
                });
                lhNew.addEventListener('click', lhReset);
                lhReset();

                // Auto-detect the text space of a PDF letterhead: draw page 1 with pdf.js and find
                // where the header ends and the footer starts, then keep a small gap from both
                var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
                var lhNote = document.getElementById('lhDetectNote');
                function loadPdfJs() {
                    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
                    return new Promise(function (resolve, reject) {
                        var s = document.createElement('script');
                        s.src = PDFJS;
                        s.onload = function () {
                            pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS.replace('pdf.min.js', 'pdf.worker.min.js');
                            resolve(pdfjsLib);
                        };
                        s.onerror = reject;
                        document.head.appendChild(s);
                    });
                }
                function detectMargins(source) {
                    return loadPdfJs()
                        .then(function (lib) { return lib.getDocument(source).promise; })
                        .then(function (doc) { return doc.getPage(1); })
                        .then(function (page) {
                            var v = page.getViewport({ scale: 1.5 });
                            var c = document.createElement('canvas');
                            c.width = Math.round(v.width); c.height = Math.round(v.height);
                            var ctx = c.getContext('2d');
                            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
                            return page.render({ canvasContext: ctx, viewport: v }).promise.then(function () {
                                var w = c.width, h = c.height, px = ctx.getImageData(0, 0, w, h).data;
                                var ink = new Array(h);
                                for (var y = 0; y < h; y++) {
                                    ink[y] = false;
                                    for (var x = 0; x < w; x += 2) {
                                        var i = (y * w + x) * 4;
                                        if (px[i] < 230 || px[i + 1] < 230 || px[i + 2] < 230) { ink[y] = true; break; }
                                    }
                                }
                                var pxPerMm = h / (page.view[3] * 25.4 / 72);
                                var gap = 8 * pxPerMm; // an empty band this tall ends the header / footer
                                function edge(from, step, limit) {
                                    var last = -1;
                                    for (var y = from; y !== limit; y += step) {
                                        if (ink[y]) last = y;
                                        else if (last >= 0 && Math.abs(y - last) >= gap) break;
                                    }
                                    return last;
                                }
                                var headerEnd = edge(0, 1, Math.round(h * 0.45));
                                var footerStart = edge(h - 1, -1, Math.round(h * 0.55));
                                return {
                                    header: headerEnd < 0 ? 0 : (headerEnd + 1) / pxPerMm,
                                    footer: footerStart < 0 ? 0 : (h - footerStart) / pxPerMm
                                };
                            });
                        });
                }
                function applyDetect(source) {
                    lhNote.textContent = 'Reading the PDF...';
                    detectMargins(source).then(function (m) {
                        var half = function (n) { return Math.ceil(n * 2) / 2; };
                        document.getElementById('lhMarginTop').value = Math.max(15, half(m.header + 8));
                        document.getElementById('lhMarginBottom').value = Math.max(15, half(m.footer + 6));
                        lhNote.textContent = 'Header ends at ' + m.header.toFixed(0) + ' mm, footer starts ' + m.footer.toFixed(0)
                            + ' mm from the bottom. A small gap is added; change the numbers if needed.';
                    }).catch(function () {
                        lhNote.textContent = 'Could not read this PDF to detect the space. Please enter the numbers.';
                    });
                }
                lhPdf.addEventListener('change', function () {
                    if (!lhPdf.files[0]) return;
                    lhPdf.files[0].arrayBuffer().then(function (buf) { applyDetect({ data: new Uint8Array(buf) }); });
                });
                document.getElementById('lhDetect').addEventListener('click', function () {
                    var id = document.getElementById('lhId').value;
                    if (lhPdf.files[0]) {
                        lhPdf.files[0].arrayBuffer().then(function (buf) { applyDetect({ data: new Uint8Array(buf) }); });
                    } else if (id) {
                        applyDetect('bed_space_contract.php?action=lh_pdf&lh_id=' + encodeURIComponent(id));
                    } else {
                        lhNote.textContent = 'Choose the letterhead PDF first.';
                    }
                });

                <?php if ($viewing): ?>
                new bootstrap.Modal(document.getElementById('fullModal')).show();
                <?php endif; ?>
                // Drop ?msg= from the address so a refresh does not show the message again
                if (window.location.search.indexOf('msg=') !== -1) {
                    var url = new URL(window.location);
                    url.searchParams.delete('msg');
                    window.history.replaceState({}, '', url);
                }
            });
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
