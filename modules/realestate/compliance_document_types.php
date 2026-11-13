<?php
/**
 * Real Estate Module - Document Types Management
 * Configure document types for compliance tracking
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/compliance_ui.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'edit') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $documentTypeName = $_POST['document_type_name'] ?? '';
        $documentTypeCode = $_POST['document_type_code'] ?? '';
        $description = $_POST['description'] ?? '';
        $isRequired = !empty($_POST['is_required']) ? 1 : 0;
        $hasExpiry = !empty($_POST['has_expiry']) ? 1 : 0;
        $defaultExpiryDays = !empty($_POST['default_expiry_days']) ? (int)$_POST['default_expiry_days'] : null;
        $alertDaysBeforeExpiry = !empty($_POST['alert_days_before_expiry']) ? (int)$_POST['alert_days_before_expiry'] : 30;
        $displayOrder = !empty($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
        
        if ($documentTypeName && $documentTypeCode) {
            if ($id) {
                // Update
                $stmt = $conn->prepare("
                    UPDATE re_document_types
                    SET document_type_name = ?,
                        document_type_code = ?,
                        description = ?,
                        is_required = ?,
                        has_expiry = ?,
                        default_expiry_days = ?,
                        alert_days_before_expiry = ?,
                        display_order = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([
                    $documentTypeName, $documentTypeCode, $description, $isRequired,
                    $hasExpiry, $defaultExpiryDays, $alertDaysBeforeExpiry, $displayOrder,
                    $id, $currentCompanyId
                ]);
            } else {
                // Insert
                $stmt = $conn->prepare("
                    INSERT INTO re_document_types
                    (company_id, document_type_name, document_type_code, description, is_required, 
                     has_expiry, default_expiry_days, alert_days_before_expiry, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $documentTypeName, $documentTypeCode, $description, $isRequired,
                    $hasExpiry, $defaultExpiryDays, $alertDaysBeforeExpiry, $displayOrder
                ]);
            }
            $_SESSION['success'] = 'Document type saved successfully.';
            header('Location: compliance_document_types.php');
            exit;
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)$_POST['id'];
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $conn->prepare("UPDATE re_document_types SET is_active = ? WHERE id = ? AND company_id = ?")
            ->execute([$isActive, $id, $currentCompanyId]);
        $_SESSION['success'] = 'Document type updated.';
        header('Location: compliance_document_types.php');
        exit;
    }
}

// Get document types
$documentTypes = $conn->prepare("
    SELECT * FROM re_document_types
    WHERE company_id = ?
    ORDER BY display_order, document_type_name
");
$documentTypes->execute([$currentCompanyId]);
$documentTypes = $documentTypes->fetchAll(PDO::FETCH_ASSOC);

// How many documents of each type are on file
$usage = $conn->prepare("SELECT document_type_id, COUNT(*) FROM re_documents WHERE company_id = ? AND document_type_id IS NOT NULL GROUP BY document_type_id");
$usage->execute([$currentCompanyId]);
$usage = $usage->fetchAll(PDO::FETCH_KEY_PAIR);
// Types that actually hold documents with an expiry date (reminders apply to them even if "has expiry" is off)
$dated = $conn->prepare("SELECT DISTINCT document_type_id FROM re_documents WHERE company_id = ? AND document_type_id IS NOT NULL AND expiry_date IS NOT NULL");
$dated->execute([$currentCompanyId]);
$dated = array_flip(array_map('intval', $dated->fetchAll(PDO::FETCH_COLUMN)));

$activeCount = count(array_filter($documentTypes, static function ($t) { return (int)$t['is_active'] === 1; }));
$expiryCount = count(array_filter($documentTypes, static function ($t) { return (int)$t['has_expiry'] === 1; }));
$requiredCount = count(array_filter($documentTypes, static function ($t) { return (int)$t['is_required'] === 1; }));

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Set page title and include layout
$pageTitle = 'Document Types';
require_once __DIR__ . '/includes/re_layout_header.php';

re_cx_header(
    'types',
    'Document Types',
    'The kinds of documents staff upload, whether they carry an expiry date, and how many days before expiry the reminder starts.',
    '<button type="button" class="cx-btn cx-btn-primary" data-bs-toggle="modal" data-bs-target="#documentTypeModal" onclick="resetForm()"><i class="bi bi-plus-lg"></i> New document type</button>'
);
re_cx_flash();
?>

<div class="cx-kpis">
    <div class="cx-kpi"><div class="cx-kpi-icon bg-blue"><i class="bi bi-tags"></i></div>
        <div><div class="cx-kpi-label">Document types</div><div class="cx-kpi-value"><?= count($documentTypes) ?></div>
             <div class="cx-kpi-note"><?= $activeCount ?> active</div></div></div>
    <div class="cx-kpi"><div class="cx-kpi-icon bg-amber"><i class="bi bi-calendar-event"></i></div>
        <div><div class="cx-kpi-label">With expiry date</div><div class="cx-kpi-value"><?= $expiryCount ?></div>
             <div class="cx-kpi-note">types that expire</div></div></div>
    <div class="cx-kpi"><div class="cx-kpi-icon bg-red"><i class="bi bi-asterisk"></i></div>
        <div><div class="cx-kpi-label">Required</div><div class="cx-kpi-value"><?= $requiredCount ?></div>
             <div class="cx-kpi-note">must be on file</div></div></div>
</div>

<section class="cx-card">
    <div class="cx-card-head">
        <div><h2 class="cx-card-title"><i class="bi bi-list-ul"></i> All document types</h2>
             <p class="cx-card-sub">Any document uploaded with an expiry date is tracked; the reminder days below decide when it appears in the summary.</p></div>
        <input type="search" class="cx-search" id="cxTypeSearch" placeholder="Search types…" aria-label="Search document types">
    </div>
    <?php if (empty($documentTypes)): ?>
        <div class="cx-empty"><i class="bi bi-tags" style="color:var(--cx-muted)"></i>No document types yet. Create one to get started.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="cx-table" id="cxTypeTable">
            <thead><tr>
                <th>Document type</th>
                <th class="cx-hide-sm">Code</th>
                <th>Requirement</th>
                <th>Expiry</th>
                <th>Reminder</th>
                <th class="num cx-hide-sm">On file</th>
                <th>Status</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($documentTypes as $type): $on = (int)($usage[$type['id']] ?? 0); ?>
                <tr data-search="<?= h(strtolower($type['document_type_name'] . ' ' . $type['document_type_code'] . ' ' . $type['description'])) ?>"
                    <?= $type['is_active'] ? '' : 'style="opacity:.6"' ?>>
                    <td style="white-space:normal;"><div class="cx-strong"><?= h($type['document_type_name']) ?></div>
                        <?php if ($type['description']): ?><div class="cx-dim"><?= h($type['description']) ?></div><?php endif; ?></td>
                    <td class="cx-hide-sm"><span class="cx-code"><?= h($type['document_type_code']) ?></span></td>
                    <td><?= $type['is_required']
                            ? '<span class="cx-pill bg-red no-dot">Required</span>'
                            : '<span class="cx-pill bg-grey no-dot">Optional</span>' ?></td>
                    <td><?php if ($type['has_expiry']): ?>
                            <span class="cx-pill bg-amber no-dot"><i class="bi bi-calendar-check"></i> Expires</span>
                            <?php if ($type['default_expiry_days']): ?><div class="cx-dim mt-1" title="If staff leave the expiry date blank on upload, it is set to the upload date + <?= (int)$type['default_expiry_days'] ?> days">If blank: <?= (int)$type['default_expiry_days'] ?> days from upload</div><?php endif; ?>
                        <?php else: ?>
                            <span class="cx-dim">No expiry</span>
                        <?php endif; ?></td>
                    <td><?php if ($type['has_expiry'] || isset($dated[(int)$type['id']])): ?>
                            <span class="cx-strong"><?= (int)$type['alert_days_before_expiry'] ?></span> <span class="cx-dim">days before</span>
                        <?php else: ?><span class="cx-dim">–</span><?php endif; ?></td>
                    <td class="num cx-hide-sm"><?= $on ?: '<span class="cx-dim">–</span>' ?></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int)$type['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $type['is_active'] ? 0 : 1 ?>">
                            <button type="submit" class="cx-pill <?= $type['is_active'] ? 'bg-green' : 'bg-grey' ?>" style="border:0;cursor:pointer;"
                                    title="Click to <?= $type['is_active'] ? 'deactivate' : 'activate' ?>">
                                <?= $type['is_active'] ? 'Active' : 'Inactive' ?>
                            </button>
                        </form>
                    </td>
                    <td class="num">
                        <button type="button" class="cx-btn cx-btn-sm" onclick="editType(<?= h(json_encode($type)) ?>)"><i class="bi bi-pencil"></i> Edit</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="cx-empty" id="cxTypeNoMatch" hidden>No document types match.</div>
    </div>
    <?php endif; ?>
</section>

<?php re_cx_footer(); ?>

    <!-- Document Type Modal -->
    <div class="modal fade" id="documentTypeModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:14px;border:0;">
                <div class="modal-header" style="border-bottom:1px solid #eaecf0;">
                    <h5 class="modal-title fw-bold" id="modalTitle">New document type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="documentTypeForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create" id="formAction">
                    <input type="hidden" name="id" id="formId">
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label fw-semibold" for="document_type_name">Name <span class="text-danger">*</span></label>
                                <input type="text" name="document_type_name" id="document_type_name" class="form-control" required placeholder="e.g. Emirates ID">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold" for="document_type_code">Code <span class="text-danger">*</span></label>
                                <input type="text" name="document_type_code" id="document_type_code" class="form-control text-uppercase" required placeholder="EMIRATES_ID">
                                <div class="form-text">Unique, capital letters and underscores.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="description">Description</label>
                                <textarea name="description" id="description" class="form-control" rows="2" placeholder="Short note shown under the name"></textarea>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch p-3 ps-5 border rounded-3 h-100">
                                    <input class="form-check-input" type="checkbox" role="switch" name="is_required" id="is_required">
                                    <label class="form-check-label fw-semibold" for="is_required">Required document</label>
                                    <div class="form-text mt-0">Must be on file for every lease.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch p-3 ps-5 border rounded-3 h-100">
                                    <input class="form-check-input" type="checkbox" role="switch" name="has_expiry" id="has_expiry" onchange="toggleExpiryFields()">
                                    <label class="form-check-label fw-semibold" for="has_expiry">Has an expiry date</label>
                                    <div class="form-text mt-0">This document expires (e.g. visa, Emirates ID, Ejari).</div>
                                </div>
                            </div>
                            <div class="col-md-6" id="defaultDaysField" style="display:none;">
                                <label class="form-label fw-semibold" for="default_expiry_days">Default validity (days)</label>
                                <input type="number" name="default_expiry_days" id="default_expiry_days" class="form-control" min="1" placeholder="e.g. 365">
                                <div class="form-text">If staff leave the expiry date blank on upload, it is set to this many days from the upload date.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="alert_days_before_expiry">Reminder starts (days before expiry)</label>
                                <input type="number" name="alert_days_before_expiry" id="alert_days_before_expiry" class="form-control" value="30" min="1" max="365">
                                <div class="form-text">When the document first appears in the expiry summary.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="display_order">Display order</label>
                                <input type="number" name="display_order" id="display_order" class="form-control" value="0" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top:1px solid #eaecf0;">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function resetForm() {
            document.getElementById('documentTypeForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').textContent = 'New document type';
            toggleExpiryFields();
        }

        function editType(type) {
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = type.id;
            document.getElementById('document_type_name').value = type.document_type_name;
            document.getElementById('document_type_code').value = type.document_type_code;
            document.getElementById('description').value = type.description || '';
            document.getElementById('is_required').checked = type.is_required == 1;
            document.getElementById('has_expiry').checked = type.has_expiry == 1;
            document.getElementById('default_expiry_days').value = type.default_expiry_days || '';
            document.getElementById('alert_days_before_expiry').value = type.alert_days_before_expiry || 30;
            document.getElementById('display_order').value = type.display_order || 0;
            document.getElementById('modalTitle').textContent = 'Edit ' + type.document_type_name;
            toggleExpiryFields();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('documentTypeModal')).show();
        }

        function toggleExpiryFields() {
            var hasExpiry = document.getElementById('has_expiry').checked;
            document.getElementById('defaultDaysField').style.display = hasExpiry ? 'block' : 'none';
        }

        (function () {
            var input = document.getElementById('cxTypeSearch');
            var table = document.getElementById('cxTypeTable');
            if (!input || !table) return;
            var rows = Array.prototype.slice.call(table.tBodies[0].rows);
            var none = document.getElementById('cxTypeNoMatch');
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase(), shown = 0;
                rows.forEach(function (tr) {
                    var ok = !q || tr.getAttribute('data-search').indexOf(q) !== -1;
                    tr.hidden = !ok; if (ok) shown++;
                });
                none.hidden = shown !== 0;
            });
        })();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
