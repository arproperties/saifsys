<?php
/**
 * ARS Rental Contract: the Short-Term Lease Agreement (English | Arabic).
 * Form on the left (fill from a booking, or type), live A4 preview on the right, saved contracts below
 * (draft / final / cancelled), full screen view, and letterheads in a popup. Same flow as Leases > Bed Space Contract.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_shell.php';
require_once __DIR__ . '/includes/ars_rental_contract.php';

$arsCompanyId = arsPageAuth($conn);
$userId = current_user_id();
$self = 'rental_contract.php';

$error = '';
$success = '';
$messages = ['Draft saved.', 'Contract confirmed as final. PDF generated.', 'Draft deleted.', 'Contract cancelled.',
    'Letterhead added.', 'Letterhead updated.', 'Letterhead deleted.'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// A draft opened for editing (?edit=); final and cancelled ones are never loaded into the form
$editId = (int)($_POST['id'] ?? $_GET['edit'] ?? 0) ?: null;
$editing = null;
if ($editId && in_array($action, ['', 'save', 'confirm'], true)) {
    $editing = arc_contract($conn, $arsCompanyId, $editId);
    if (!$editing || $editing['status'] !== 'draft') {
        $editing = null;
        $editId = null;
    }
}

// Values from a booking, for "Fill from booking"
if ($action === 'booking_values') {
    header('Content-Type: application/json');
    echo json_encode(arc_values_from_booking($conn, $arsCompanyId, (int)($_GET['booking_id'] ?? 0)) ?: ['error' => 'Booking not found']);
    exit;
}

// Preview: of a saved contract (view_id), or of what is typed in the form
if ($action === 'preview') {
    header('Content-Type: text/html; charset=UTF-8');
    try {
        $data = !empty($_GET['view_id'])
            ? (arc_contract($conn, $arsCompanyId, (int)$_GET['view_id']) ?: arc_input([]))
            : arc_input($_GET);
        $letterhead = arc_letterhead($conn, $arsCompanyId, (int)($data['letterhead_id'] ?? 0) ?: null);
        $pdfUrl = $self . '?action=lh_pdf&lh_id=' . (int)$letterhead['id'] . '&v=' . urlencode((string)($letterhead['updated_at'] ?? ''));
        echo arc_preview_html(arc_fill(arc_template_html(), $data), $letterhead, $pdfUrl);
    } catch (Throwable $e) {
        echo '<p style="font-family:sans-serif;color:#b02a37;padding:20px;">' . h($e->getMessage()) . '</p>';
    }
    exit;
}

// The full-page letterhead PDF, for the preview
if ($action === 'lh_pdf') {
    $file = bsc_letterhead_pdf_file(arc_letterhead($conn, $arsCompanyId, (int)($_GET['lh_id'] ?? 0) ?: null));
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
    $row = arc_contract($conn, $arsCompanyId, (int)($_GET['id'] ?? 0));
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
        $msg = '';
        if ($action === 'save' || $action === 'confirm') {
            $data = arc_input($_POST);
            if ($data['tenant_name'] === '') {
                throw new RuntimeException('Tenant is required.');
            }
            $savedId = arc_save_contract($conn, $arsCompanyId, $editId, $data, $userId);
            $msg = 'Draft saved.';
            if ($action === 'confirm') {
                arc_confirm_contract($conn, $arsCompanyId, $savedId, $userId);
                $msg = 'Contract confirmed as final. PDF generated.';
            }
        } elseif ($action === 'confirm_saved') {
            arc_confirm_contract($conn, $arsCompanyId, $postId, $userId);
            $msg = 'Contract confirmed as final. PDF generated.';
        } elseif ($action === 'delete') {
            arc_delete_contract($conn, $arsCompanyId, $postId);
            $msg = 'Draft deleted.';
        } elseif ($action === 'cancel') {
            arc_cancel_contract($conn, $arsCompanyId, $postId, (string)($_POST['cancel_reason'] ?? ''), $userId);
            $msg = 'Contract cancelled.';
        } elseif ($action === 'lh_save' || $action === 'lh_delete') {
            arc_letterheads($conn, $arsCompanyId);
            $lhId = (int)($_POST['letterhead_id'] ?? 0) ?: null;
            $msg = $action === 'lh_save'
                ? bsc_save_letterhead($conn, $arsCompanyId, $lhId, $_POST, $userId)
                : bsc_delete_letterhead($conn, $arsCompanyId, (int)$lhId);
        }
        if ($msg !== '') {
            header('Location: ' . $self . '?msg=' . urlencode($msg));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
if (!$error && in_array($_GET['msg'] ?? '', $messages, true)) {
    $success = $_GET['msg'];
}

$viewing = !empty($_GET['view']) ? arc_contract($conn, $arsCompanyId, (int)$_GET['view']) : null;

$userName = function ($id) use ($conn) {
    if (!$id) {
        return '';
    }
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(fullname, ''), username) FROM user WHERE id = ?");
    $stmt->execute([$id]);
    return (string)$stmt->fetchColumn();
};

// Form values: what was just posted (on an error), else the draft being edited, else a new one
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['save', 'confirm'], true)) {
    $form = arc_input($_POST);
} elseif ($editing) {
    $form = $editing;
} else {
    $form = array_fill_keys(array_keys(ARC_FIELDS), '');
    $form['contract_date'] = date('Y-m-d');
    $form['landlord_name'] = ARC_LANDLORD_DEFAULT;
    $form['contract_type'] = 'Short-Term';
    $form['terms_of_payment'] = 'Single advance payment';
    $form['booking_id'] = null;
    $form['letterhead_id'] = null;
}
$letterheads = arc_letterheads($conn, $arsCompanyId);
$selectedLetterhead = arc_letterhead($conn, $arsCompanyId, (int)($form['letterhead_id'] ?? 0) ?: null);
$contracts = arc_contracts($conn, $arsCompanyId);
$bookings = arc_bookings($conn, $arsCompanyId, (int)($form['booking_id'] ?? 0));

$fv = fn($key) => h($form[$key] ?? '');
$badge = function (string $status, string $extra = ''): string {
    $map = ['draft' => ['bg-warning text-dark', 'Draft'], 'final' => ['bg-success', 'Final'], 'cancelled' => ['bg-danger', 'Cancelled']];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $cls . ($extra ? ' ' . $extra : '') . '">' . $label . '</span>';
};
$input = function (string $name, string $label, string $type = 'text', string $col = 'col-6', string $extra = '') use ($fv) {
    return '<div class="' . $col . '"><label class="form-label small mb-1">' . h($label) . '</label>'
        . '<input type="' . $type . '" name="' . $name . '" class="form-control form-control-sm arc-field" value="' . $fv($name) . '" ' . $extra . '></div>';
};

ars_shell_begin([
    'title' => 'ARS Rental Contract',
    'subtitle' => 'Short-Term Lease Agreement in English and Arabic',
    'breadcrumbs' => [['label' => 'ARS', 'href' => 'index.php'], ['label' => 'Rental Contract']],
    'actions_html' => '<button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#letterheadModal"><i class="bi bi-image"></i> Letterheads</button>'
        . ' <a href="' . $self . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> New Contract</a>',
    'legacy_bootstrap' => true,
]);
?>
<style>
    .arc-group { font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #0d4250; margin: 14px 0 4px; border-bottom: 1px solid #e3e8eb; padding-bottom: 2px; }
</style>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show py-2"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?= $editing ? 'Edit Draft #' . (int)$editId . ' ' . $badge('draft', 'ms-1') : 'New Contract' ?></strong>
                <?php if ($editing): ?>
                    <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary">Close</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="arcForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save" id="arcAction">
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editId ?>">
                    <?php endif; ?>

                    <div class="mb-2 p-2 rounded" style="background:#f4f6fa;">
                        <label class="form-label small mb-1">Fill from booking <span class="text-muted">(optional - you can still edit every field)</span></label>
                        <input type="text" id="bookingFilter" class="form-control form-control-sm mb-1" placeholder="Type to filter: guest, unit, booking no.">
                        <select name="booking_id" id="bookingSelect" class="form-select form-select-sm">
                            <option value="">-- None --</option>
                            <?php foreach ($bookings as $b): ?>
                                <?php
                                $label = trim($b['first_name'] . ' ' . $b['last_name']) . ' - ' . trim($b['unit_number'] . ' ' . $b['building_name'])
                                    . ' (' . $b['booking_number'] . ', ' . date('d/m/Y', strtotime($b['check_in'])) . ' - ' . date('d/m/Y', strtotime($b['check_out'])) . ')';
                                ?>
                                <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === (int)($form['booking_id'] ?? 0) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2">
                        <?= $input('lease_no', 'Lease ID') ?>
                        <?= $input('contract_date', 'Date', 'date') ?>
                        <?= $input('landlord_name', 'Landlord Name', 'text', 'col-12', 'maxlength="255"') ?>

                        <div class="col-12"><div class="arc-group">Tenant</div></div>
                        <?= $input('tenant_name', 'Tenant *', 'text', 'col-12', 'maxlength="255" required') ?>
                        <?= $input('tenant_mobile', 'Mobile No.', 'text', 'col-12') ?>
                        <?= $input('tenant_eid', 'Emirates ID No.') ?>
                        <?= $input('tenant_passport', 'Passport No.') ?>
                        <?= $input('tenant_email', 'Email', 'email', 'col-12') ?>
                        <div class="col-12">
                            <label class="form-label small mb-1">Address</label>
                            <textarea name="tenant_address" rows="2" class="form-control form-control-sm arc-field"><?= $fv('tenant_address') ?></textarea>
                        </div>

                        <div class="col-12"><div class="arc-group">Contract</div></div>
                        <?= $input('contract_type', 'Contract Type', 'text', 'col-12', 'maxlength="100"') ?>
                        <?= $input('start_date', 'Period: From', 'date') ?>
                        <?= $input('end_date', 'Period: To', 'date') ?>
                        <?= $input('unit_no', 'Unit No', 'text', 'col-6', 'maxlength="100"') ?>
                        <?= $input('rent', 'Rent (AED)', 'number', 'col-6', 'step="0.01" min="0"') ?>
                        <?= $input('terms_of_payment', 'Terms of Payment', 'text', 'col-6', 'maxlength="255"') ?>
                        <?= $input('security_deposit', 'Security Deposit (AED)', 'number', 'col-6', 'step="0.01" min="0"') ?>

                        <div class="col-12 mt-2">
                            <label class="form-label small mb-1">Letterhead</label>
                            <div class="input-group input-group-sm">
                                <select name="letterhead_id" class="form-select arc-field">
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
                        <button type="submit" class="btn btn-outline-primary flex-fill" onclick="document.getElementById('arcAction').value='save'"><i class="bi bi-save"></i> Save Draft</button>
                        <button type="submit" class="btn btn-primary flex-fill"
                            onclick="if (!confirm('Confirm as final? The PDF will be generated and the contract can no longer be edited or deleted, only cancelled.')) return false; document.getElementById('arcAction').value='confirm'">
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
    <div class="card-header"><strong>Saved Contracts</strong></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr><th>#</th><th>Lease ID</th><th>Tenant</th><th>Unit</th><th>Period</th><th>Rent</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($contracts)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No contracts yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($contracts as $c): ?>
                        <tr class="<?= (int)$c['id'] === (int)$editId ? 'table-primary' : '' ?>">
                            <td><?= (int)$c['id'] ?></td>
                            <td><?= h($c['lease_no'] ?: '-') ?></td>
                            <td><?= h($c['tenant_name']) ?></td>
                            <td><?= h($c['unit_no']) ?></td>
                            <td class="text-nowrap"><?= $c['start_date'] ? h(date('d/m/Y', strtotime($c['start_date']))) : '' ?> - <?= $c['end_date'] ? h(date('d/m/Y', strtotime($c['end_date']))) : '' ?></td>
                            <td class="text-nowrap"><?= $c['rent'] !== null ? 'AED ' . number_format((float)$c['rent'], 2) : '' ?></td>
                            <td>
                                <?= $badge($c['status']) ?>
                                <?php if ($c['status'] === 'cancelled' && $c['cancel_reason']): ?>
                                    <i class="bi bi-info-circle text-muted" title="<?= h($c['cancel_reason']) ?>"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="?view=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View full screen"><i class="bi bi-arrows-fullscreen"></i> View</a>
                                <?php if ($c['status'] === 'draft'): ?>
                                    <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this draft?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete draft"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($c['contract_path'])): ?>
                                    <a href="?id=<?= (int)$c['id'] ?>&action=download" class="btn btn-sm btn-outline-success" title="Download PDF"><i class="bi bi-download"></i></a>
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
<div class="modal fade" id="fullModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div>
                    <h5 class="modal-title mb-0">Contract #<?= (int)$viewing['id'] ?> - <?= h($viewing['tenant_name']) ?> <?= $badge($viewing['status'], 'ms-1') ?></h5>
                    <?php if ($viewing['status'] === 'final'): ?>
                        <small class="text-muted">Confirmed <?= h(date('d/m/Y H:i', strtotime($viewing['confirmed_at']))) ?><?= $viewing['confirmed_by'] ? ' by ' . h($userName($viewing['confirmed_by'])) : '' ?></small>
                    <?php elseif ($viewing['status'] === 'cancelled'): ?>
                        <small class="text-danger">Cancelled <?= h(date('d/m/Y H:i', strtotime($viewing['cancelled_at']))) ?><?= $viewing['cancelled_by'] ? ' by ' . h($userName($viewing['cancelled_by'])) : '' ?>. Reason: <?= h($viewing['cancel_reason']) ?></small>
                    <?php endif; ?>
                </div>
                <a href="<?= $self ?>" class="btn-close"></a>
            </div>
            <div class="modal-body p-0" style="background:#e9ecef;">
                <iframe src="<?= $self ?>?action=preview&view_id=<?= (int)$viewing['id'] ?>" style="width:100%; height:100%; border:0;"></iframe>
            </div>
            <div class="modal-footer py-2">
                <a href="<?= $self ?>" class="btn btn-secondary">Close</a>
                <?php if (!empty($viewing['contract_path'])): ?>
                    <a href="?id=<?= (int)$viewing['id'] ?>&action=download" class="btn btn-success"><i class="bi bi-download"></i> Download PDF</a>
                <?php endif; ?>
                <?php if ($viewing['status'] === 'draft'): ?>
                    <a href="?edit=<?= (int)$viewing['id'] ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Confirm as final? The PDF will be generated and the contract can no longer be edited or deleted, only cancelled.');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="confirm_saved">
                        <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Confirm as Final</button>
                    </form>
                <?php elseif ($viewing['status'] === 'final'): ?>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-x-circle"></i> Cancel Contract</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($viewing['status'] === 'final'): ?>
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Contract #<?= (int)$viewing['id'] ?></h5>
                    <button type="button" class="btn-close" data-bs-toggle="modal" data-bs-target="#fullModal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Reason for cancelling *</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required></textarea>
                    <small class="text-muted">The contract stays in the list as Cancelled, with this reason.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#fullModal">Back</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> Cancel Contract</button>
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
                            <?php $images = arc_letterhead_images($lh); ?>
                            <tr>
                                <td style="width:45%">
                                    <?php if (($lh['type'] ?? 'images') === 'pdf'): ?>
                                        <a href="<?= $self ?>?action=lh_pdf&lh_id=<?= (int)$lh['id'] ?>" target="_blank" class="text-decoration-none"><i class="bi bi-file-earmark-pdf text-danger fs-4 align-middle"></i> Full page PDF</a>
                                        <small class="text-muted d-block">Text space: <?= h((float)$lh['margin_top']) ?> mm from top, <?= h((float)$lh['margin_bottom']) ?> mm from bottom</small>
                                    <?php elseif ($images['header']): ?>
                                        <img src="<?= h($images['header']) ?>" alt="Header" style="max-width:100%; max-height:50px; border:1px solid #dee2e6;">
                                    <?php else: ?>
                                        <span class="text-muted small">No header image</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= h($lh['name']) ?></strong>
                                    <?php if ((int)$lh['is_default'] === 1): ?><span class="badge bg-primary ms-1">Default</span><?php endif; ?>
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
                                <input type="radio" class="btn-check" name="lh_type" id="lhTypeImages" value="images" checked>
                                <label class="btn btn-outline-primary" for="lhTypeImages"><i class="bi bi-image"></i> Header / footer images</label>
                                <input type="radio" class="btn-check" name="lh_type" id="lhTypePdf" value="pdf">
                                <label class="btn btn-outline-primary" for="lhTypePdf"><i class="bi bi-file-earmark-pdf"></i> Full page PDF</label>
                            </div>
                        </div>
                        <div class="col-md-6 lh-images">
                            <label class="form-label small mb-1">Header image <span id="lhHeaderReq">*</span></label>
                            <input type="file" name="header_image" id="lhHeader" class="form-control form-control-sm" accept="image/png,image/jpeg">
                        </div>
                        <div class="col-md-6 lh-images">
                            <label class="form-label small mb-1">Footer image (optional)</label>
                            <input type="file" name="footer_image" class="form-control form-control-sm" accept="image/png,image/jpeg">
                        </div>
                        <div class="col-12 lh-images">
                            <small class="text-muted">PNG or JPG, wide like a letterhead (about 1800 x 265 px), max 5 MB.</small>
                        </div>
                        <div class="col-12 lh-pdf d-none">
                            <label class="form-label small mb-1">Letterhead PDF <span id="lhPdfReq">*</span></label>
                            <input type="file" name="letterhead_pdf" id="lhPdf" class="form-control form-control-sm" accept="application/pdf,.pdf">
                            <small class="text-muted">One page with the header and footer and an empty middle. In Word: File &gt; Save As &gt; PDF. Max 10 MB.</small>
                        </div>
                        <div class="col-md-6 lh-pdf d-none">
                            <label class="form-label small mb-1">Text starts below (mm from top)</label>
                            <input type="number" step="0.5" min="0" max="150" name="lh_margin_top" id="lhMarginTop" class="form-control form-control-sm" value="45">
                        </div>
                        <div class="col-md-6 lh-pdf d-none">
                            <label class="form-label small mb-1">Text stops above (mm from bottom)</label>
                            <input type="number" step="0.5" min="0" max="150" name="lh_margin_bottom" id="lhMarginBottom" class="form-control form-control-sm" value="30">
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
    var form = document.getElementById('arcForm');
    var frame = document.getElementById('previewFrame');
    var booking = document.getElementById('bookingSelect');
    var filter = document.getElementById('bookingFilter');

    function refreshPreview() {
        var params = new URLSearchParams();
        form.querySelectorAll('.arc-field').forEach(function (el) { params.set(el.name, el.value); });
        params.set('action', 'preview');
        frame.src = '<?= $self ?>?' + params.toString();
    }
    var timer;
    form.querySelectorAll('.arc-field').forEach(function (el) {
        el.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(refreshPreview, 400); });
        el.addEventListener('change', refreshPreview);
    });

    // Fill the fields from the chosen booking; Lease ID and Date stay as typed
    booking.addEventListener('change', function () {
        if (!booking.value) return;
        fetch('<?= $self ?>?action=booking_values&booking_id=' + encodeURIComponent(booking.value))
            .then(function (r) { return r.json(); })
            .then(function (v) {
                if (v.error) { alert(v.error); return; }
                Object.keys(v).forEach(function (k) {
                    var el = form.querySelector('[name="' + k + '"]');
                    if (el) el.value = v[k];
                });
                refreshPreview();
            });
    });
    filter.addEventListener('input', function () {
        var q = filter.value.toLowerCase();
        Array.prototype.forEach.call(booking.options, function (opt, i) {
            if (i === 0) return;
            opt.hidden = q !== '' && opt.text.toLowerCase().indexOf(q) === -1;
        });
    });
    refreshPreview();

    // Letterhead popup: edit fills the form below the list
    var lhEditing = false;
    var lhPdf = document.getElementById('lhPdf');
    var lhHeader = document.getElementById('lhHeader');
    function lhSetType(type) {
        document.getElementById(type === 'pdf' ? 'lhTypePdf' : 'lhTypeImages').checked = true;
        document.querySelectorAll('.lh-pdf').forEach(function (el) { el.classList.toggle('d-none', type !== 'pdf'); });
        document.querySelectorAll('.lh-images').forEach(function (el) { el.classList.toggle('d-none', type === 'pdf'); });
        lhPdf.required = !lhEditing && type === 'pdf';
        lhHeader.required = !lhEditing && type !== 'pdf';
        var keep = lhEditing ? '(leave empty to keep)' : '*';
        document.getElementById('lhPdfReq').textContent = keep;
        document.getElementById('lhHeaderReq').textContent = keep;
    }
    document.querySelectorAll('input[name=lh_type]').forEach(function (r) {
        r.addEventListener('change', function () { lhSetType(r.value); });
    });
    var lhNew = document.getElementById('lhNew');
    function lhReset() {
        document.getElementById('lhForm').reset();
        document.getElementById('lhId').value = '';
        document.getElementById('lhFormTitle').textContent = 'Add Letterhead';
        lhEditing = false;
        document.getElementById('lhTypeRow').classList.remove('d-none');
        lhSetType('images');
        lhNew.classList.add('d-none');
    }
    document.querySelectorAll('.lh-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('lhForm').reset();
            document.getElementById('lhId').value = btn.dataset.id;
            document.getElementById('lhName').value = btn.dataset.name;
            document.getElementById('lhDefault').checked = btn.dataset.default === '1';
            document.getElementById('lhMarginTop').value = btn.dataset.mt;
            document.getElementById('lhMarginBottom').value = btn.dataset.mb;
            document.getElementById('lhFormTitle').textContent = 'Edit: ' + btn.dataset.name;
            lhEditing = true;
            document.getElementById('lhTypeRow').classList.toggle('d-none', btn.dataset.builtin === '1');
            lhSetType(btn.dataset.type);
            lhNew.classList.remove('d-none');
            document.getElementById('lhName').focus();
        });
    });
    lhNew.addEventListener('click', lhReset);
    lhReset();

    <?php if ($viewing): ?>
    new bootstrap.Modal(document.getElementById('fullModal')).show();
    <?php endif; ?>
    if (window.location.search.indexOf('msg=') !== -1) {
        var url = new URL(window.location);
        url.searchParams.delete('msg');
        window.history.replaceState({}, '', url);
    }
});
</script>
<?php ars_shell_end(); ?>
